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
            $message = preg_match( '/\.tar\.gz$/i', $part ) ? 'Could not decompress archive: ' : 'Could not prepare archive for reading: ';
            return $this->result( false, $cursor, $message . basename( $part ), MainWP_Backup_Error::ERR_RESTORE_EXTRACT );
        }

        if ( ! $this->phar_available() ) {
            $this->discard_plain_tar( $part, $plain );
            return $this->result( false, $cursor, "This site's PHP has the phar extension disabled, which the restore needs to read .tar archives.", MainWP_Backup_Error::ERR_RESTORE_EXTRACT );
        }

        try {
            $phar    = new \PharData( $plain );
            $entries = $this->tar_entry_names( $phar );
        } catch ( \Throwable $e ) {
            $this->discard_plain_tar( $part, $plain );
            return $this->result( false, $cursor, $this->archive_open_error( $e, $part, $plain ), MainWP_Backup_Error::ERR_RESTORE_EXTRACT );
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

        $plain = static::phar_safe_tar_path( $part );
        if ( ! preg_match( '/\.tar\.gz$/i', $part ) ) {
            if ( ! static::phar_rejects_name( basename( $part ) ) ) {
                return $part;
            }
            // The name itself is unreadable to PharData; a hard link under a
            // short name reads the same bytes without copying the part.
            if ( file_exists( $plain ) ) {
                if ( filesize( $plain ) === filesize( $part ) ) {
                    return $plain;
                }
                // A copy from a request that died mid-write; a tar truncated on an
                // entry boundary opens cleanly with only part of the archive.
                wp_delete_file( $plain );
            }
            if ( $this->link_part( $part, $plain ) ) {
                return $plain;
            }
            // Runners on different hosts can share a pid and this scratch directory.
            $tmp = $plain . '.' . wp_generate_password( 12, false ) . '.tmp';
            $this->sweep_stale_plain_temps( $plain, $tmp );
            $copied = $this->copy_part( $part, $tmp );
            clearstatcache( true, $tmp );
            $size = @filesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failed copy may leave no file; warnings must not corrupt the response.
            if ( $copied && false !== $size && filesize( $part ) === $size && @rename( $tmp, $plain ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions -- like link/copy failures, rename warnings must not corrupt the response.
                return $plain;
            }
            wp_delete_file( $tmp );
            return null;
        }

        if ( file_exists( $plain ) && filesize( $plain ) > 0 ) {
            return $plain; // A prior slice already decompressed it.
        }

        $in = @gzopen( $part, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open failures are handled below; warnings must not corrupt the response.
        if ( false === $in ) {
            return null;
        }
        // Same reason as the hard-link branch: a resumed runner shares this
        // task's scratch dir, so a shared name lets one promote the other's
        // half-written stream.
        $gz_tmp = $plain . '.' . wp_generate_password( 12, false ) . '.tmp';
        $this->sweep_stale_plain_temps( $plain, $gz_tmp );
        $out = @fopen( $gz_tmp, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged -- open failures are handled below; warnings must not corrupt the response.
        if ( false === $out ) {
            gzclose( $in );
            return null;
        }
        $written = 0;
        while ( ! gzeof( $in ) ) {
            if ( microtime( true ) >= $deadline ) {
                // Out of slice mid-stream. gzip is not resumable, so discard
                // the partial and refuse rather than loop forever from byte 0.
                gzclose( $in );
                fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- paired with fopen above.
                wp_delete_file( $gz_tmp );
                $timed_out = true;
                return null;
            }
            $chunk = gzread( $in, 262144 );
            if ( false === $chunk || strlen( $chunk ) !== fwrite( $out, $chunk ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- streaming decompression.
                gzclose( $in );
                fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- paired with fopen above.
                wp_delete_file( $gz_tmp );
                return null;
            }
            $written += strlen( $chunk );
        }
        gzclose( $in );
        fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- paired with fopen above.

        // A failed final flush or a foreign truncation must not become a reusable alias.
        clearstatcache( true, $gz_tmp );
        if ( @filesize( $gz_tmp ) !== $written || ! @rename( $gz_tmp, $plain ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged -- atomic promote; filesize/rename warnings must not corrupt the response.
            wp_delete_file( $gz_tmp );
            return null;
        }
        return $plain;
    }

    /**
     * Interrupted writers otherwise leave a full archive behind on every resume.
     *
     * @param string $plain Alias path.
     * @param string $tmp   Current writer's temporary path.
     */
    private function sweep_stale_plain_temps( $plain, $tmp ) {
        foreach ( (array) glob( $plain . '.*.tmp' ) as $stale ) {
            // The age bound protects a live peer's in-flight write from this sweep.
            if ( $stale !== $tmp && filemtime( $stale ) < time() - HOUR_IN_SECONDS ) {
                wp_delete_file( $stale );
            }
        }
    }

    /**
     * Isolate hard-link support so tests can reach the copy fallback on any filesystem.
     *
     * @param string $part  Archive path.
     * @param string $plain Alias path.
     *
     * @return bool Whether the hard link was created.
     */
    protected function link_part( $part, $plain ) {
        return @link( $part, $plain ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- link() may be unsupported; warnings must not corrupt the response.
    }

    /**
     * Isolate copying so tests can reproduce a short copy without racing another process.
     *
     * @param string $part Archive path.
     * @param string $tmp  Temporary copy path.
     *
     * @return bool Whether copying reported success.
     */
    protected function copy_part( $part, $tmp ) {
        return @copy( $part, $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions -- copy warnings must not corrupt the response.
    }

    /**
     * The alias path PharData can open for a part.
     *
     * PharData finds the archive format by scanning from the first dot in
     * the file name and gives up when that tail is 50 characters or more
     * (phar_check_str in ext/phar). Dashboard archive names carry the
     * site's host name, so a dotted host plus the date stamp already sits
     * near the limit, and appending .plain.tar crossed it on a live restore.
     * Dots in the stem become dashes to keep the dot-tail below the limit.
     * The basename hash prevents dotted and dashed names sharing an alias.
     *
     * @param string $part Archive path.
     *
     * @return string Alias path beside the part.
     */
    protected static function phar_safe_tar_path( $part ) {
        $stem      = preg_replace( '/\.tar(\.gz|\.bz2)?$/i', '', basename( $part ) );
        $extension = substr( basename( $part ), strlen( $stem ) );
        if ( '.tar.gz' === strtolower( $extension ) ) {
            $extension = '.plain.tar';
        }
        return dirname( $part ) . '/' . str_replace( '.', '-', $stem ) . '-' . substr( sha1( basename( $part ) ), 0, 12 ) . $extension;
    }

    /**
     * Whether PharData refuses a file name before reading a byte of it.
     *
     * @param string $basename Archive file name.
     *
     * @return bool True when the tail after the first dot is 50+ characters.
     */
    protected static function phar_rejects_name( $basename ) {
        $dot = strpos( $basename, '.' );
        return false !== $dot && strlen( $basename ) - $dot >= 50;
    }

    /**
     * Whether this host can read .tar archives at all.
     *
     * Hosts that disable the phar extension are the case this reports by
     * name; the check is its own method so a test can reach the branch
     * without unloading an extension.
     *
     * @return bool True when PharData is available.
     */
    protected function phar_available() {
        return class_exists( '\PharData' );
    }

    /**
     * Report why an archive could not be opened, keeping the cause.
     *
     * The dashboard only ever saw "Could not open archive: <name>", which
     * names the file and nothing about the failure. The exception class and
     * message carry the cause, so they stay - reduced to basenames, because
     * the reader is another site's operator and the message crosses the wire.
     *
     * @param \Throwable $e     Archive-open failure.
     * @param string     $part  Original archive path.
     * @param string     $plain Plain tar path that failed to open.
     *
     * @return string Result text.
     */
    protected function archive_open_error( $e, $part, $plain ) {
        $message = preg_replace( '/[\x00-\x1F\x7F]/', '', $e->getMessage() );
        $message = str_replace( array( $plain, $part ), array( basename( $plain ), basename( $part ) ), $message );
        $message = preg_replace( '~/(?:[^\s/"\'<>()[\]{}]+/)+([^\s/"\'<>()[\]{}]+)~', '$1', $message );
        // Backslash paths take a second pass: a Windows or UNC path reported by
        // PHP survives the forward-slash collapse above and would carry the host
        // directory tree and the OS user name to the dashboard.
        $message = preg_replace( '~(?:[A-Za-z]:)?\\\\(?:[^\s\\\\"\'<>()[\]{}]+\\\\)+([^\s\\\\"\'<>()[\]{}]+)~', '$1', $message );
        // Byte truncation can split a multi-byte sequence, and esc_html() renders
        // invalid UTF-8 as the empty string - which would hide the very cause
        // this message exists to report.
        $message = mb_substr( (string) $message, 0, 300 );

        return 'Could not open archive: ' . basename( $part ) . ' (' . get_class( $e ) . ': ' . $message . ')';
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
