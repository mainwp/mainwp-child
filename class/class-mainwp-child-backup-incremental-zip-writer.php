<?php
/**
 * MainWP Child Backup Incremental Zip Writer - rotating part archives.
 *
 * Packs changed files into per-component ZIP parts
 * (plugins.part001.zip, plugins.part002.zip, ...) that rotate when a
 * part passes the size or entry-count threshold, so no single archive
 * grows past what a bounded slice can finish writing.
 *
 * ZipArchive writes the central directory only on close(), so a part
 * killed mid-write is structurally invalid. Re-entry therefore verifies
 * before appending: the part must open cleanly and its persisted entry
 * count must match the resume cursor's expectation. Anything else means
 * torn state - the caller discards the part, rewinds the cursor to the
 * part's first file, and rebuilds it. Append never uses OVERWRITE.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Child_Backup_Incremental_Zip_Writer
 *
 * @package MainWP\Child
 */
class MainWP_Child_Backup_Incremental_Zip_Writer { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Source-byte threshold per part (~200 MiB).
     */
    const MAX_PART_BYTES = 209715200;

    /**
     * Entry threshold per part.
     */
    const MAX_PART_ENTRIES = 55000;

    /**
     * Directory holding the parts.
     *
     * @var string
     */
    protected $backup_dir;

    /**
     * Component this writer packs (plugins/themes/uploads/wp-content/core).
     *
     * @var string
     */
    protected $component;

    /**
     * Entry threshold in effect.
     *
     * @var int
     */
    protected $max_entries;

    /**
     * Byte threshold in effect.
     *
     * @var int
     */
    protected $max_bytes;

    /**
     * Open archive, null when no part is open.
     *
     * @var \ZipArchive|null
     */
    protected $zip = null;

    /**
     * Index of the open part.
     *
     * @var int
     */
    protected $part_index = 0;

    /**
     * Entries in the open part (persisted plus added this session).
     *
     * @var int
     */
    protected $entry_count = 0;

    /**
     * Source bytes accounted to the open part.
     *
     * @var int
     */
    protected $byte_count = 0;

    /**
     * Constructor.
     *
     * @param string   $backup_dir  Directory holding the parts.
     * @param string   $component   Component name.
     * @param int|null $max_entries Entry threshold override (tests).
     * @param int|null $max_bytes   Byte threshold override (tests).
     */
    public function __construct( $backup_dir, $component, $max_entries = null, $max_bytes = null ) {
        $this->backup_dir  = rtrim( $backup_dir, '/' );
        $this->component   = $component;
        $this->max_entries = null === $max_entries ? self::MAX_PART_ENTRIES : (int) $max_entries;
        $this->max_bytes   = null === $max_bytes ? self::MAX_PART_BYTES : (int) $max_bytes;
    }

    /**
     * Part file name for a component and index.
     *
     * @param string $component Component name.
     * @param int    $index     Part index (1-based).
     *
     * @return string Part basename, e.g. plugins.part001.zip.
     */
    public static function part_basename( $component, $index ) {
        return sprintf( '%s.part%03d.zip', $component, (int) $index );
    }

    /**
     * Verify an existing part and open it for appending.
     *
     * The part must exist, open cleanly (a torn write has no central
     * directory and fails here), and hold exactly the entry count the
     * resume cursor recorded. On success the part stays open and new
     * entries append to it.
     *
     * @param int $index            Part index to resume.
     * @param int $expected_entries Entries the cursor recorded for it.
     *
     * @return bool True when intact and open; false when the caller must
     *              discard the part and rewind to its first file.
     */
    public function open_for_append( $index, $expected_entries ) {
        $path = $this->part_path( $index );
        if ( ! file_exists( $path ) ) {
            return false;
        }

        $zip = new \ZipArchive();
        if ( true !== $zip->open( $path ) ) {
            return false; // No central directory - torn mid-write.
        }

        if ( (int) $zip->numFiles !== (int) $expected_entries ) {
            $zip->close();
            return false; // Entries past (or short of) the checkpoint.
        }

        $this->zip         = $zip;
        $this->part_index  = (int) $index;
        $this->entry_count = (int) $expected_entries;
        $this->byte_count  = (int) filesize( $path );

        return true;
    }

    /**
     * Start a brand-new part, replacing any file at its name - the caller
     * has already decided a leftover file is torn state.
     *
     * @param int $index Part index.
     *
     * @throws MainWP_Exception When the part cannot be created.
     */
    public function open_new_part( $index ) {
        $path = $this->part_path( $index );
        if ( file_exists( $path ) ) {
            wp_delete_file( $path ); // NOSONAR .
        }

        $zip = new \ZipArchive();
        if ( true !== $zip->open( $path, \ZipArchive::CREATE ) ) {
            throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_ZIP_FAILED ) ), null, MainWP_Backup_Error::ERR_ZIP_FAILED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        }

        $this->zip         = $zip;
        $this->part_index  = (int) $index;
        $this->entry_count = 0;
        $this->byte_count  = 0;
    }

    /**
     * Add one file, rotating to the next part first when the open part
     * has reached a threshold.
     *
     * @param string $abs_path Absolute source path.
     * @param string $relpath  Site-relative path (the entry name).
     *
     * @throws MainWP_Exception When no part is open or the entry cannot be added.
     *
     * @return array {
     *     Add result.
     *
     *     @type bool       $rotated True when a rotation happened before this add.
     *     @type array|null $closed  The completed part record when rotated.
     *     @type bool       $skipped True when the source vanished and was skipped.
     * }
     */
    public function add_file( $abs_path, $relpath ) {
        if ( null === $this->zip ) {
            throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_ZIP_FAILED ) ), null, MainWP_Backup_Error::ERR_ZIP_FAILED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        }

        $result = array(
            'rotated' => false,
            'closed'  => null,
            'skipped' => false,
        );

        $size = @filesize( $abs_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a file deleted between walk and pack is skipped, not fatal.
        if ( false === $size || ! is_file( $abs_path ) ) {
            $result['skipped'] = true;
            return $result;
        }

        if ( $this->entry_count > 0 && ( $this->entry_count >= $this->max_entries || $this->byte_count + $size > $this->max_bytes ) ) {
            $result['closed']  = $this->close_part();
            $result['rotated'] = true;
            $this->open_new_part( $result['closed']['index'] + 1 );
        }

        if ( ! $this->zip->addFile( $abs_path, $relpath ) ) {
            throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_ZIP_FAILED ) ), null, MainWP_Backup_Error::ERR_ZIP_FAILED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        }

        ++$this->entry_count;
        $this->byte_count += (int) $size;

        return $result;
    }

    /**
     * Close the open part, writing its central directory, and report it.
     *
     * @throws MainWP_Exception When the close fails - the part would have
     *                          no valid central directory.
     *
     * @return array|null Part record (file, size, entries, index), null
     *                    when no part was open.
     */
    public function close_part() {
        if ( null === $this->zip ) {
            return null;
        }

        $index   = $this->part_index;
        $entries = $this->entry_count;
        $path    = $this->part_path( $index );

        $closed    = @$this->zip->close(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- failure converted to a coded exception below.
        $this->zip = null;

        if ( true !== $closed ) {
            throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_ZIP_FAILED ) ), null, MainWP_Backup_Error::ERR_ZIP_FAILED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        }

        return array(
            'file'    => self::part_basename( $this->component, $index ),
            'size'    => (int) filesize( $path ),
            'entries' => (int) $entries,
            'index'   => (int) $index,
        );
    }

    /**
     * Index of the open part.
     *
     * @return int Part index.
     */
    public function current_index() {
        return $this->part_index;
    }

    /**
     * Entries in the open part.
     *
     * @return int Entry count.
     */
    public function current_entries() {
        return $this->entry_count;
    }

    /**
     * Whether a part is currently open.
     *
     * @return bool Open state.
     */
    public function is_open() {
        return null !== $this->zip;
    }

    /**
     * Absolute path of a part.
     *
     * @param int $index Part index.
     *
     * @return string Part path.
     */
    protected function part_path( $index ) {
        return $this->backup_dir . '/' . self::part_basename( $this->component, $index );
    }
}
