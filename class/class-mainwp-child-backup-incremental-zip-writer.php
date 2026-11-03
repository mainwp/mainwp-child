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
     * Per-task token folded into the part name so two tasks never collide on
     * the same component+index in the shared backup dir. Empty for the legacy
     * (un-namespaced) name.
     *
     * @var string
     */
    protected $task_token;

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
     * Deferred output competes with other sites on the same disk.
     *
     * @var int Source bytes queued plus the existing part's close-time copy.
     */
    private $pending_bytes = 0;

    /**
     * Capture the walk's containment policy for pack-time revalidation.
     *
     * @var string[]
     */
    protected $allowed_backup_bases;

    /**
     * Constructor.
     *
     * @param string     $backup_dir  Directory holding the parts.
     * @param string     $component   Component name.
     * @param int|null   $max_entries Entry threshold override (tests).
     * @param int|null   $max_bytes   Byte threshold override (tests).
     * @param string     $task_token  Per-task token folded into the part name.
     * @param array|null $bases       Allowed containment bases, null for the shared defaults.
     */
    public function __construct( $backup_dir, $component, $max_entries = null, $max_bytes = null, $task_token = '', $bases = null ) {
        $this->backup_dir  = rtrim( $backup_dir, '/' );
        $this->component   = $component;
        $this->max_entries = null === $max_entries ? self::MAX_PART_ENTRIES : (int) $max_entries;
        $this->max_bytes   = null === $max_bytes ? self::MAX_PART_BYTES : (int) $max_bytes;
        $this->task_token  = (string) $task_token;

        $this->allowed_backup_bases = null === $bases ? MainWP_Backup::default_backup_bases() : $bases;
    }

    /**
     * Part file name for a component and index, optionally namespaced by a
     * per-task token so two tasks never collide on the same component+index.
     *
     * @param string $component Component name.
     * @param int    $index     Part index (1-based).
     * @param string $token     Per-task token, sanitized to alphanumerics;
     *                          empty for the legacy name.
     *
     * @return string Part basename, e.g. plugins.part001.zip or
     *                plugins.<token>.part001.zip.
     */
    public static function part_basename( $component, $index, $token = '' ) {
        $token = preg_replace( '/[^A-Za-z0-9]/', '', (string) $token );
        $stem  = '' !== $token ? $component . '.' . $token : $component;
        return sprintf( '%s.part%03d.zip', $stem, (int) $index );
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
     * @param int $accounted_bytes  Source bytes the cursor accounted to it.
     *
     * @return bool True when intact and open; false when the caller must
     *              discard the part and rewind to its first file.
     */
    public function open_for_append( $index, $expected_entries, $accounted_bytes = 0 ) {
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

        $this->zip = $zip;
        clearstatcache( true, $path );
        $this->pending_bytes = (int) filesize( $path );
        $this->part_index    = (int) $index;
        $this->entry_count   = (int) $expected_entries;
        // The threshold counts SOURCE bytes, so resume from the total the
        // cursor accumulated. filesize() here is the COMPRESSED part, which
        // would mix metrics and let a resumed part run past max_bytes.
        $this->byte_count = (int) $accounted_bytes;

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

        $this->zip           = $zip;
        $this->part_index    = (int) $index;
        $this->entry_count   = 0;
        $this->byte_count    = 0;
        $this->pending_bytes = 0;
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
     *     @type bool       $skipped True when the source vanished or failed containment.
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

        // A source can be replaced between the walk and this pack slice.
        clearstatcache( true, $abs_path );
        if ( MainWP_Backup::is_file_link( $abs_path ) || ! MainWP_Backup::is_safe_backup_path( $abs_path, $this->allowed_backup_bases ) ) {
            $result['skip_reason'] = MainWP_Backup::is_file_link( $abs_path ) ? 'link' : 'unsafe';
            if ( 'unsafe' === $result['skip_reason'] && ! is_link( $abs_path ) && ! file_exists( $abs_path ) ) {
                $result['skip_reason'] = 'unstatable';
            }
            MainWP_Backup::record_skipped_path( $abs_path, $result['skip_reason'] );
            $result['skipped'] = true;
            return $result;
        }

        $size = @filesize( $abs_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a file deleted between walk and pack is skipped, not fatal.
        if ( false === $size || ! is_file( $abs_path ) ) {
            $result['skip_reason'] = 'unstatable';
            MainWP_Backup::record_skipped_path( $abs_path, $result['skip_reason'] );
            $result['skipped'] = true;
            return $result;
        }

        if ( $this->entry_count > 0 && ( $this->entry_count >= $this->max_entries || $this->byte_count + $size > $this->max_bytes ) ) {
            $result['closed']  = $this->close_part();
            $result['rotated'] = true;
            $this->open_new_part( $result['closed']['index'] + 1 );
        }

        $this->assert_disk_space( $size );
        if ( ! $this->zip->addFile( $abs_path, $relpath ) ) {
            throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_ZIP_FAILED ) ), null, MainWP_Backup_Error::ERR_ZIP_FAILED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        }

        ++$this->entry_count;
        $this->byte_count    += (int) $size;
        $this->pending_bytes += (int) $size;

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

        $this->assert_disk_space();
        $closed              = MainWP_Helper::protect_backup_path(
            $path,
            function () {
                return @$this->zip->close(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- failure converted to a coded exception below.
            }
        );
        $this->zip           = null;
        $this->pending_bytes = 0;

        if ( true !== $closed ) {
            throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_ZIP_FAILED ) ), null, MainWP_Backup_Error::ERR_ZIP_FAILED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        }

        // The part path was stat'd before the close (open_new_part /
        // open_for_append), and not every runtime invalidates that cache
        // entry when the archive is finalized; a stale read here would
        // record the pre-close byte count.
        clearstatcache( true, $path );

        return array(
            'file'    => self::part_basename( $this->component, $index, $this->task_token ),
            'size'    => (int) filesize( $path ),
            'entries' => (int) $entries,
            'index'   => (int) $index,
        );
    }

    /**
     * Discard before task cleanup unlinks the part; a live destructor would flush it.
     */
    public function discard() {
        try {
            if ( null !== $this->zip ) {
                @$this->zip->unchangeAll(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- already closed handles warn on PHP 7.4.
            }
        } catch ( \Throwable $cleanup_error ) {
            // Preserve the failure that interrupted the archive.
        } finally {
            $this->zip           = null;
            $this->pending_bytes = 0;
        }
    }

    /**
     * Libzip rewrites an appended part into a full temporary copy at close.
     *
     * @param int $entry_bytes Source bytes about to be queued.
     * @throws MainWP_Exception When the floor refuses a write.
     */
    private function assert_disk_space( $entry_bytes = 0 ) {
        try {
            MainWP_Backup::assert_disk_space_during_backup( $this->backup_dir, null, $this->pending_bytes + $entry_bytes );
        } catch ( MainWP_Exception $e ) {
            if ( MainWP_Backup_Error::ERR_DISK_FULL === $e->get_message_error_code() ) {
                $this->discard();
            }
            throw $e;
        }
    }

    /**
     * Write qualified parts for an internally enabled task.
     *
     * @param MainWP_Child_Backup_Task_Runner $runner Task runner.
     * @param array                           $descriptor Descriptor.
     * @param array                           $entries Qualified files.
     * @param array                           $cursor Part cursor.
     * @param int                             $deadline Slice deadline.
     * @return array Part result.
     */
    public function capture_for_task( $runner, $descriptor, $entries, $cursor, $deadline ) {
        if ( ! $runner instanceof MainWP_Child_Backup_Task_Runner || ! $runner->allows_v2_capture() ) {
            MainWP_Child_Backup_Layout::refuse( MainWP_Backup_Error::ERR_UNSUPPORTED_BACKUP_FORMAT );
        }
        return $this->write_v2_parts_slice( $descriptor, $entries, $cursor, $deadline );
    }

    /**
     * Write bounded wrapped-tar parts through the private v2 capture seam.
     *
     * No request handler calls this entry while v2 capture is disabled.
     *
     * @param array $descriptor Canonical layout descriptor.
     * @param array $entries Qualified identities to local source files.
     * @param array $cursor Checkpoint returned by the preceding slice.
     * @param float $deadline Slice deadline, checked between complete entries.
     * @param int   $slice_entries Maximum complete entries per slice.
     * @param int   $slice_bytes Maximum wrapper bytes per slice.
     * @return array Completion, cursor and completed part records.
     */
    private function write_v2_parts_slice( $descriptor, $entries, $cursor, $deadline, $slice_entries = 100, $slice_bytes = 4194304 ) {
        $digest  = MainWP_Child_Backup_Layout::digest( $descriptor );
        $binding = hash( 'sha256', wp_json_encode( $entries ) );
        if ( empty( $cursor ) ) {
            $cursor = array(
                'format'                   => 2,
                'layout_descriptor_sha256' => $digest,
                'entries_sha256'           => $binding,
                'next_entry'               => 0,
                'part_index'               => 1,
                'part_entries'             => array(),
                'codec'                    => array(),
                'parts'                    => array(),
            );
        } elseif ( 2 !== ( $cursor['format'] ?? null ) || ( $cursor['layout_descriptor_sha256'] ?? null ) !== $digest || ( $cursor['entries_sha256'] ?? null ) !== $binding ) {
            MainWP_Child_Backup_Layout::refuse( MainWP_Backup_Error::ERR_LAYOUT_CHANGED );
        }
        if ( ! isset( $cursor['next_entry'], $cursor['part_index'], $cursor['part_entries'], $cursor['codec'], $cursor['parts'] ) || ! is_int( $cursor['next_entry'] ) || $cursor['next_entry'] < 0 || $cursor['next_entry'] > count( $entries ) || ! is_int( $cursor['part_index'] ) || $cursor['part_index'] < 1 || ! is_array( $cursor['part_entries'] ) || ! is_array( $cursor['codec'] ) || ! is_array( $cursor['parts'] ) ) {
            MainWP_Child_Backup_Layout::refuse();
        }
        if ( empty( $cursor['part_entries'] ) && count( $entries ) === $cursor['next_entry'] ) {
            return array(
                'done'             => true,
                'cursor'           => $cursor,
                'parts'            => $cursor['parts'],
                'omitted'          => $cursor['omitted'] ?? array(),
                'omitted_overflow' => ! empty( $cursor['omitted_overflow'] ),
            );
        }
        if ( empty( $cursor['part_entries'] ) ) {
            $identities  = array_keys( $entries );
            $bytes       = 0;
            $roles       = array_column( $descriptor['roots'], 'coverage', 'role' );
            $entry_count = count( $identities );
            for ( $i = $cursor['next_entry']; $i < $entry_count; ++$i ) {
                $identity     = $identities[ $i ];
                $source       = $entries[ $identity ];
                list( $role ) = MainWP_Child_Backup_Layout::parse_identity( $identity );
                clearstatcache( true, $source );
                $omittable = 'config' !== $role && ( is_link( $source ) || ! file_exists( $source ) );
                if ( ! isset( $roles[ $role ] ) || 'preserved' === $roles[ $role ] || ( ! $omittable && ( ! MainWP_Backup::is_safe_backup_path( $source, $this->allowed_backup_bases ) || ! is_file( $source ) ) ) ) {
                    MainWP_Child_Backup_Layout::refuse();
                }
                $size = $omittable ? 0 : filesize( $source );
                if ( false === $size ) {
                    MainWP_Child_Backup_Layout::refuse();
                }
                if ( ! empty( $cursor['part_entries'] ) && ( count( $cursor['part_entries'] ) >= $this->max_entries || $bytes + $size > $this->max_bytes ) ) {
                    break;
                }
                $cursor['part_entries'][ $identity ] = $source;
                $bytes                              += $size;
            }
        }
        if ( array_slice( $entries, $cursor['next_entry'], count( $cursor['part_entries'] ), true ) !== $cursor['part_entries'] ) {
            MainWP_Child_Backup_Layout::refuse( MainWP_Backup_Error::ERR_LAYOUT_CHANGED );
        }
        $position = 0;
        foreach ( $cursor['part_entries'] as $identity => $source ) {
            if ( $position++ < ( $cursor['codec']['entry_index'] ?? 0 ) ) {
                continue;
            }
            list( $role ) = MainWP_Child_Backup_Layout::parse_identity( $identity );
            clearstatcache( true, $source );
            $omittable = 'config' !== $role && ( is_link( $source ) || ! file_exists( $source ) );
            if ( ! $omittable && ( ! MainWP_Backup::is_safe_backup_path( $source, $this->allowed_backup_bases ) || ! is_file( $source ) ) ) {
                MainWP_Child_Backup_Layout::refuse();
            }
        }
        // Retain the legacy stem and token rules, changing only the v2 suffix.
        $basename = substr( self::part_basename( $this->component, $cursor['part_index'], $this->task_token ), 0, -4 ) . '.mwp2';
        $path     = $this->backup_dir . '/' . $basename;
        MainWP_Backup::assert_disk_space_during_backup( $this->backup_dir );
        $result          = ( new MainWP_Child_Backup_Layout_Codec() )->write_slice( $path, $descriptor, $cursor['part_entries'], $cursor['codec'], $deadline, $slice_entries, $slice_bytes, true );
        $cursor['codec'] = $result['cursor'];
        foreach ( $cursor['codec']['omitted'] ?? array() as $identity => $reason ) {
            if ( isset( $cursor['omitted'][ $identity ] ) || count( $cursor['omitted'] ?? array() ) < 1000 ) {
                $cursor['omitted'][ $identity ] = $reason;
            } else {
                $cursor['omitted_overflow'] = true;
            }
        }
        if ( ! empty( $cursor['codec']['omitted_overflow'] ) ) {
            $cursor['omitted_overflow'] = true;
        }
        if ( $result['done'] ) {
            clearstatcache( true, $path );
            $cursor['parts'][]     = array(
                'file'                     => $basename,
                'size'                     => (int) filesize( $path ),
                'entries'                  => count( $cursor['part_entries'] ) - ( $cursor['codec']['omitted_count'] ?? 0 ),
                'index'                    => $cursor['part_index'],
                'backup_format'            => 2,
                'layout_descriptor_sha256' => $digest,
            );
            $cursor['next_entry'] += count( $cursor['part_entries'] );
            ++$cursor['part_index'];
            $cursor['part_entries'] = array();
            $cursor['codec']        = array();
            wp_delete_file( $path . '.tar' );
        }
        return array(
            'done'             => $result['done'] && count( $entries ) === $cursor['next_entry'],
            'cursor'           => $cursor,
            'parts'            => $cursor['parts'],
            'omitted'          => $cursor['omitted'] ?? array(),
            'omitted_overflow' => ! empty( $cursor['omitted_overflow'] ),
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
     * Source bytes accounted to the open part, for the resume cursor.
     *
     * @return int
     */
    public function current_bytes() {
        return $this->byte_count;
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
        return $this->backup_dir . '/' . self::part_basename( $this->component, $index, $this->task_token );
    }
}
