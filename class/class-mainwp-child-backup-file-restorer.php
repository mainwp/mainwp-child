<?php
/**
 * MainWP Child Backup File Restorer - zip-slip-guarded archive extraction.
 *
 * Every archive verifies its sha256 against the hash recorded when the
 * artifact was received before a single entry is extracted, and every
 * entry resolves through validate_extract_path() before a byte is
 * written. Absolute paths, traversal sequences, and entries whose
 * resolved parent directory falls outside the extraction root abort
 * the extraction. The prefix compare appends a trailing slash to both
 * sides: realpath() returns no trailing slash, so a bare prefix check
 * would accept a sibling directory that merely shares the extraction
 * root's name as a prefix.
 *
 * Extraction is resumable: the cursor carries the part index and the
 * entry index inside the open part, checkpointed by the task runner at
 * every budget yield. Parts apply in order, so an incremental chain's
 * later parts overwrite the base, and tombstones delete files through
 * the same path discipline.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Child_Backup_File_Restorer
 *
 * @package MainWP\Child
 */
class MainWP_Child_Backup_File_Restorer { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Extract one bounded slice of the part archives.
     *
     * @param array     $cursor   Resume cursor (empty array on first call).
     * @param array     $config   { parts: string[], hashes: array<string,string>
     *                            keyed by basename, extract_to: string }.
     * @param float|int $deadline Absolute epoch the slice must yield by.
     *
     * @return array { done: bool, cursor: array, error: string|null,
     *                 error_code: string|null }
     */
    public function restore_slice( $cursor, $config, $deadline ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity -- bounded extraction loop with integrity gates.
        $cursor = array_merge(
            array(
                'part_index'  => 0,
                'entry_index' => 0,
            ),
            is_array( $cursor ) ? $cursor : array()
        );

        $parts      = $config['parts'];
        $extract_to = $config['extract_to'];
        $did_work   = false;
        $part_count = count( $parts );

        while ( $cursor['part_index'] < $part_count ) {
            $part = $parts[ $cursor['part_index'] ];

            if ( 0 === $cursor['entry_index'] ) {
                $error = $this->verify_part_hash( $part, $config['hashes'] );
                if ( null !== $error ) {
                    return $this->result( false, $cursor, $error, MainWP_Backup_Error::ERR_FILE_CORRUPT );
                }
            }

            $zip = new \ZipArchive();
            if ( true !== $zip->open( $part ) ) {
                return $this->result( false, $cursor, 'Could not open archive: ' . basename( $part ), MainWP_Backup_Error::ERR_RESTORE_EXTRACT );
            }

            $entry_count = (int) $zip->numFiles;
            while ( $cursor['entry_index'] < $entry_count ) {
                if ( $did_work && microtime( true ) >= $deadline ) {
                    $zip->close();
                    return $this->result( false, $cursor, null, null );
                }

                $name = $zip->getNameIndex( $cursor['entry_index'] );
                try {
                    $this->extract_entry( $zip, $name, $extract_to );
                } catch ( \Exception $e ) {
                    $zip->close();
                    return $this->result( false, $cursor, $e->getMessage(), MainWP_Backup_Error::ERR_RESTORE_EXTRACT );
                }

                ++$cursor['entry_index'];
                $did_work = true;
            }

            $zip->close();
            ++$cursor['part_index'];
            $cursor['entry_index'] = 0;
        }

        return $this->result( true, $cursor, null, null );
    }

    /**
     * Apply a tombstones list: delete each named file under the root.
     *
     * Hostile lines (traversal, absolute paths, entries resolving
     * outside the root) abort - a tombstone list is artifact data and
     * gets the same trust as an archive entry.
     *
     * @param string $tombstones_file Path to tombstones.list.
     * @param string $extract_to      Extraction root.
     *
     * @return array { deleted: int, error: string|null, error_code: string|null }
     */
    public function apply_tombstones( $tombstones_file, $extract_to ) {
        $lines = file( $tombstones_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- local sidecar read.
        if ( false === $lines ) {
            return array(
                'deleted'    => 0,
                'error'      => 'Could not read the tombstones list.',
                'error_code' => MainWP_Backup_Error::ERR_RESTORE_EXTRACT,
            );
        }

        $real_root = realpath( $extract_to );
        $deleted   = 0;

        foreach ( $lines as $line ) {
            $relpath = trim( $line );
            if ( '' === $relpath ) {
                continue;
            }

            if ( 0 === strpos( $relpath, '/' ) || false !== strpos( $relpath, '..' ) || preg_match( '/^[A-Z]:\\\\/i', $relpath ) ) {
                return array(
                    'deleted'    => $deleted,
                    'error'      => sprintf( 'Tombstone entry resolves outside the site root: %s. Deletion aborted.', esc_html( $relpath ) ),
                    'error_code' => MainWP_Backup_Error::ERR_RESTORE_EXTRACT,
                );
            }

            $target = rtrim( $extract_to, '/' ) . '/' . $relpath;
            if ( ! is_file( $target ) ) {
                continue;
            }

            $real_parent = realpath( dirname( $target ) );
            if ( false === $real_root || false === $real_parent || strpos( $real_parent . '/', $real_root . '/' ) !== 0 ) {
                return array(
                    'deleted'    => $deleted,
                    'error'      => sprintf( 'Tombstone entry resolves outside the site root: %s. Deletion aborted.', esc_html( $relpath ) ),
                    'error_code' => MainWP_Backup_Error::ERR_RESTORE_EXTRACT,
                );
            }

            if ( unlink( $target ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- applying a deletion record.
                ++$deleted;
            }
        }

        return array(
            'deleted'    => $deleted,
            'error'      => null,
            'error_code' => null,
        );
    }

    /**
     * Extract one archive entry to its validated target.
     *
     * @param \ZipArchive $zip        Open archive.
     * @param string      $name       Entry name.
     * @param string      $extract_to Extraction root.
     *
     * @throws \Exception When the entry validates outside the root or
     *                    cannot be written.
     */
    private function extract_entry( $zip, $name, $extract_to ) {
        if ( '/' === substr( $name, -1 ) ) {
            // Directory entry.
            $target = static::validate_extract_path( rtrim( $name, '/' ), $extract_to );
            if ( ! is_dir( $target ) ) {
                wp_mkdir_p( $target );
            }
            return;
        }

        $target = static::validate_extract_path( $name, $extract_to );

        $stream = $zip->getStream( $name );
        if ( false === $stream ) {
            throw new \Exception( sprintf( 'Could not read archive entry: %s.', esc_html( $name ) ) );
        }

        $out = fopen( $target, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- streaming extraction to the validated path.
        if ( false === $out ) {
            fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- paired with getStream above.
            throw new \Exception( sprintf( 'Could not write extracted file: %s.', esc_html( $name ) ) );
        }

        stream_copy_to_stream( $stream, $out );
        fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- paired with getStream above.
        if ( false === fclose( $out ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- paired with fopen above.
            throw new \Exception( sprintf( 'Could not finish writing extracted file: %s.', esc_html( $name ) ) );
        }
    }

    /**
     * Verify a part archive's sha256 before extraction.
     *
     * @param string $part   Archive path.
     * @param array  $hashes Expected hashes keyed by basename.
     *
     * @return string|null Error text or null when the hash matches.
     */
    private function verify_part_hash( $part, $hashes ) {
        $base = basename( $part );
        if ( empty( $hashes[ $base ] ) ) {
            return 'No expected hash recorded for archive: ' . $base . '. Extraction refused.';
        }
        $actual = hash_file( 'sha256', $part );
        if ( ! is_string( $actual ) || ! hash_equals( $hashes[ $base ], $actual ) ) {
            return 'Archive integrity check failed for: ' . $base . '. The file may be corrupted.';
        }
        return null;
    }

    /**
     * Build a slice result.
     *
     * @param bool        $done       Whether extraction completed.
     * @param array       $cursor     Cursor.
     * @param string|null $error      Error text.
     * @param string|null $error_code Error code.
     *
     * @return array Slice result.
     */
    private function result( $done, $cursor, $error, $error_code ) {
        return array(
            'done'       => $done,
            'cursor'     => $cursor,
            'error'      => $error,
            'error_code' => $error_code,
        );
    }

    /**
     * Validate an archive entry's extraction target.
     *
     * @param string $entry_name Relative entry name from the archive.
     * @param string $extract_to Extraction root directory.
     *
     * @return string The validated target path for the entry.
     *
     * @throws \Exception When the entry would resolve outside the
     *                    extraction root.
     */
    public static function validate_extract_path( $entry_name, $extract_to ) {
        // Reject absolute paths - PHP 7.4 compatible.
        if ( strpos( $entry_name, '/' ) === 0 || preg_match( '/^[A-Z]:\\\\/i', $entry_name ) ) {
            throw new \Exception(
                sprintf( 'Archive contains absolute path entry: %s. Extraction aborted.', esc_html( $entry_name ) )
            );
        }

        // Reject path traversal sequences - PHP 7.4 compatible.
        if ( strpos( $entry_name, '..' ) !== false ) {
            throw new \Exception(
                sprintf( 'Archive contains path traversal entry: %s. Extraction aborted.', esc_html( $entry_name ) )
            );
        }

        // Resolve and verify path stays within extraction directory.
        $target_path  = rtrim( $extract_to, '/' ) . '/' . $entry_name;
        $real_extract = realpath( $extract_to );
        $real_target  = realpath( dirname( $target_path ) );

        if ( false === $real_target ) {
            wp_mkdir_p( dirname( $target_path ) );
            $real_target = realpath( dirname( $target_path ) );
        }

        // SECURITY: append '/' to BOTH sides - realpath() has no trailing
        // slash, so a bare prefix compare passes for sibling dirs sharing
        // a prefix ('/path/backup-abc123-evil' vs '/path/backup-abc123').
        // The trailing slash closes that hole.
        if ( false === $real_target || strpos( $real_target . '/', $real_extract . '/' ) !== 0 ) {
            throw new \Exception(
                sprintf( 'Archive entry resolves outside extraction directory: %s. Extraction aborted.', esc_html( $entry_name ) )
            );
        }

        return $target_path;
    }
}
