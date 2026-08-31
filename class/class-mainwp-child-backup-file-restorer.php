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

            $outcome = $this->is_tar_archive( $part )
                ? $this->extract_tar_part( $part, $extract_to, $cursor, $deadline, $did_work )
                : $this->extract_zip_part( $part, $extract_to, $cursor, $deadline, $did_work );
            if ( null !== $outcome ) {
                return $outcome;
            }

            ++$cursor['part_index'];
            $cursor['entry_index'] = 0;
        }

        return $this->result( true, $cursor, null, null );
    }

    /**
     * Whether an archive is a tar variant (PharData-readable).
     *
     * @param string $part Archive path.
     *
     * @return bool True for .tar, .tar.gz, and .tar.bz2.
     */
    private function is_tar_archive( $part ) {
        return (bool) preg_match( '/\.tar(\.gz|\.bz2)?$/i', $part );
    }

    /**
     * Extract one zip part from the entry cursor.
     *
     * Returns null when the part finished; a slice result on yield or
     * error.
     *
     * @param string $part       Archive path.
     * @param string $extract_to Extraction root.
     * @param array  $cursor     Cursor (by reference).
     * @param mixed  $deadline   Slice deadline.
     * @param bool   $did_work   Work flag (by reference).
     *
     * @return array|null Slice result to propagate, or null.
     */
    private function extract_zip_part( $part, $extract_to, &$cursor, $deadline, &$did_work ) {
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
        return null;
    }

    /**
     * Extract one tar/tar.gz/tar.bz2 part from the entry cursor.
     *
     * PharData reads compressed tars without shelling out and stays
     * PHP 7.4 compatible. Entries enumerate in a stable sorted order so
     * the numeric cursor survives a yield.
     *
     * @param string $part       Archive path.
     * @param string $extract_to Extraction root.
     * @param array  $cursor     Cursor (by reference).
     * @param mixed  $deadline   Slice deadline.
     * @param bool   $did_work   Work flag (by reference).
     *
     * @throws \Exception When an entry cannot be read or written (caught
     *                    and converted to a coded slice result).
     *
     * @return array|null Slice result to propagate, or null.
     */
    private function extract_tar_part( $part, $extract_to, &$cursor, $deadline, &$did_work ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity -- bounded extraction loop with integrity gates.
        // A part already worked this slice and the budget is gone: yield so a
        // compressed part retries decompression next slice with a full budget
        // instead of being refused for a slice a prior part nearly exhausted.
        if ( $did_work && microtime( true ) >= $deadline ) {
            return $this->result( false, $cursor, null, null );
        }

        // Phar reads of compressed tars are unreliable across PHP builds
        // (8.4 returns empty entry streams), so compressed parts gunzip
        // to a plain .tar first; the plain-tar reader is solid everywhere.
        $timed_out = false;
        $plain     = $this->plain_tar_for( $part, $deadline, $timed_out );
        if ( null === $plain ) {
            if ( $timed_out ) {
                if ( $did_work ) {
                    // A prior part spent most of this slice; retry the
                    // decompression next slice with a full budget before
                    // judging the stream too large. The next slice reaches this
                    // part first (did_work false), so it is never an endless
                    // retry: a full slice that still cannot finish refuses below.
                    return $this->result( false, $cursor, null, null );
                }
                // A full slice was not enough to decompress this stream and gzip
                // cannot resume across requests, so refuse rather than loop.
                return $this->result(
                    false,
                    $cursor,
                    'The compressed archive ' . basename( $part ) . ' is too large to decompress within one restore slice on this host. Restore on a host with a higher PHP time limit, or re-create the backup in the uncompressed .tar format.',
                    MainWP_Backup_Error::ERR_RESTORE_EXTRACT
                );
            }
            return $this->result( false, $cursor, 'Could not decompress archive: ' . basename( $part ), MainWP_Backup_Error::ERR_RESTORE_EXTRACT );
        }

        try {
            $phar    = new \PharData( $plain );
            $entries = $this->tar_entry_names( $phar );
        } catch ( \Throwable $e ) {
            $this->discard_plain_tar( $part, $plain );
            return $this->result( false, $cursor, 'Could not open archive: ' . basename( $part ), MainWP_Backup_Error::ERR_RESTORE_EXTRACT );
        }

        $entry_count = count( $entries );
        while ( $cursor['entry_index'] < $entry_count ) {
            if ( $did_work && microtime( true ) >= $deadline ) {
                return $this->result( false, $cursor, null, null );
            }

            $entry = $entries[ $cursor['entry_index'] ];
            try {
                if ( $entry['dir'] ) {
                    $target = static::validate_extract_path( rtrim( $entry['name'], '/' ), $extract_to );
                    if ( ! is_dir( $target ) ) {
                        wp_mkdir_p( $target );
                    }
                } else {
                    $target = static::validate_extract_path( $entry['name'], $extract_to );
                    $stream = fopen( $entry['phar_url'], 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- streaming extraction from the tar.
                    if ( false === $stream ) {
                        throw new \Exception( sprintf( 'Could not read archive entry: %s.', esc_html( $entry['name'] ) ) );
                    }
                    // Neutralize a pre-existing leaf symlink so extraction
                    // never writes through it outside the validated parent.
                    if ( is_link( $target ) ) {
                        unlink( $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- neutralize a stale symlink before writing.
                    }
                    $out = fopen( $target, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- streaming extraction to the validated path.
                    if ( false === $out ) {
                        fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- paired with fopen above.
                        throw new \Exception( sprintf( 'Could not write extracted file: %s.', esc_html( $entry['name'] ) ) );
                    }
                    stream_copy_to_stream( $stream, $out );
                    fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- paired with fopen above.
                    fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- paired with fopen above.
                }
            } catch ( \Exception $e ) {
                // The success path below discards the decompressed sibling;
                // the error path must too, or a rejected archive leaves a
                // full-size plain tar behind in the backup directory.
                $this->discard_plain_tar( $part, $plain );
                return $this->result( false, $cursor, $e->getMessage(), MainWP_Backup_Error::ERR_RESTORE_EXTRACT );
            }

            ++$cursor['entry_index'];
            $did_work = true;
        }

        $this->discard_plain_tar( $part, $plain );
        return null;
    }

    /**
     * Resolve a tar part to a plain (uncompressed) tar on disk.
     *
     * A .tar passes through; .tar.gz streams through zlib into a sibling temp
     * file. The decompression is bounded by the slice deadline: gzip cannot be
     * resumed across requests (the inflate state is not serializable), so a
     * stream that cannot finish inside one slice is refused rather than
     * restarted from byte 0 every slice. The refusal sets $timed_out so the
     * caller can guide the operator to the uncompressed .tar format.
     *
     * @param string    $part      Archive path.
     * @param float|int $deadline  Absolute epoch the decompression must finish by.
     * @param bool      $timed_out Set true when the decompression hit the deadline (by reference).
     *
     * @return string|null Plain tar path, or null on failure or timeout.
     */
    protected function plain_tar_for( $part, $deadline, &$timed_out ) {
        $timed_out = false;

        if ( ! preg_match( '/\.tar\.gz$/i', $part ) ) {
            return $part;
        }

        $plain = $part . '.plain.tar';
        if ( file_exists( $plain ) && filesize( $plain ) > 0 ) {
            return $plain; // A prior slice already decompressed it.
        }

        $in = gzopen( $part, 'rb' );
        if ( false === $in ) {
            return null;
        }
        $out = fopen( $plain . '.tmp', 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- streaming decompression.
        if ( false === $out ) {
            gzclose( $in );
            return null;
        }
        while ( ! gzeof( $in ) ) {
            if ( microtime( true ) >= $deadline ) {
                // Out of slice mid-stream. gzip is not resumable, so discard
                // the partial and refuse rather than loop forever from byte 0.
                gzclose( $in );
                fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- paired with fopen above.
                wp_delete_file( $plain . '.tmp' );
                $timed_out = true;
                return null;
            }
            $chunk = gzread( $in, 262144 );
            if ( false === $chunk || strlen( $chunk ) !== fwrite( $out, $chunk ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- streaming decompression.
                gzclose( $in );
                fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- paired with fopen above.
                wp_delete_file( $plain . '.tmp' );
                return null;
            }
        }
        gzclose( $in );
        fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- paired with fopen above.

        if ( ! rename( $plain . '.tmp', $plain ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- atomic promote.
            return null;
        }
        return $plain;
    }

    /**
     * Remove the decompressed sibling once a part finishes.
     *
     * @param string $part  Original archive path.
     * @param string $plain Plain tar path in use.
     */
    private function discard_plain_tar( $part, $plain ) {
        if ( $plain !== $part && file_exists( $plain ) ) {
            wp_delete_file( $plain );
        }
    }

    /**
     * Enumerate a tar's entries in a stable order.
     *
     * The relative name (for the extraction path guard) derives from
     * the iterator's own phar URL, which is also kept verbatim as the
     * read source - PharData aliases compressed archives internally,
     * so a URL reconstructed from the on-disk file name can miss.
     *
     * @param \PharData $phar Open archive.
     *
     * @return array<int,array{name:string,dir:bool,phar_url:string}> Sorted entries.
     */
    private function tar_entry_names( $phar ) {
        $prefix  = 'phar://' . $phar->getPath() . '/';
        $entries = array();

        $iterator = new \RecursiveIteratorIterator( $phar, \RecursiveIteratorIterator::SELF_FIRST );
        foreach ( $iterator as $item ) {
            $phar_url = (string) $item->getPathname();
            $name     = 0 === strpos( $phar_url, $prefix ) ? substr( $phar_url, strlen( $prefix ) ) : '';
            if ( '' === $name ) {
                continue;
            }
            $entries[] = array(
                'name'     => str_replace( '\\', '/', $name ),
                'dir'      => $item->isDir(),
                'phar_url' => $phar_url,
            );
        }

        usort(
            $entries,
            function ( $a, $b ) {
                return strcmp( $a['name'], $b['name'] );
            }
        );
        return $entries;
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

        // validate_extract_path resolves the parent, but a pre-existing
        // symlink at the leaf would let fopen('wb') write through it to a
        // target outside the root; replace it with a fresh regular file.
        if ( is_link( $target ) ) {
            unlink( $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- neutralize a stale symlink before writing.
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
        $target_path = rtrim( $extract_to, '/' ) . '/' . $entry_name;

        // An unresolvable root makes the containment compare below a tautology
        // (every absolute path starts with '/'), so resolve it first and fail
        // closed rather than let the check pass on a false.
        $real_extract = realpath( $extract_to );
        if ( false === $real_extract ) {
            wp_mkdir_p( $extract_to );
            $real_extract = realpath( $extract_to );
        }
        if ( false === $real_extract ) {
            throw new \Exception( 'Extraction root could not be resolved. Extraction aborted.' );
        }

        $real_target = realpath( dirname( $target_path ) );

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
