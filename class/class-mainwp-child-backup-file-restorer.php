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
 * The .mainwp-restore-*.tmp name is reserved for owner-tagged extraction
 * temps; backup walkers exclude both legacy and owner-tagged forms from
 * every archive so interrupted output cannot become backup data. Before
 * writing into a directory, the sweep reclaims this task's own temps and
 * foreign temps whose owners the runner confirms are no longer live.
 * Without that lookup, foreign temps are kept. Legacy names are never swept,
 * and archived members with reserved names are restored as is. A worker that dies inside
 * write_entry_stream() leaves at most one temp, bounded by the remaining
 * cumulative expanded-byte budget (up to 64 GiB), attributable by its owner
 * token, and never archived.
 * Reclamation waits until a later restore writes into the same directory.
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
     * Cursor persistence callback for the current slice.
     *
     * @var callable|null
     */
    private $checkpoint_cursor;

    /**
     * Directories already swept for abandoned entry temps in this request.
     *
     * @var array
     */
    private $swept_directories = array();

    /**
     * A real .htaccess in the current part takes precedence over its legacy alias.
     *
     * @var array<string,bool>
     */
    private $real_htaccess_entries = array();

    /**
     * Stable task token, or a random token for this standalone restorer.
     *
     * @var string|null
     */
    private $temp_owner;

    /**
     * Owner liveness lookup; only an explicit false permits foreign-temp deletion.
     *
     * @var callable|null
     */
    private $is_temp_owner_live;

    /**
     * Whether compressed preparation refused the extraction limits.
     *
     * @var bool
     */
    protected $preparation_limit_exceeded = false;

    const MAX_EXTRACT_ENTRIES        = 2000000;
    const MAX_EXPANDED_BYTES         = 68719476736;
    const MAX_COMPRESSION_RATIO      = 200;
    const RATIO_MIN_COMPRESSED_BYTES = 65536;
    const TEMP_PREFIX                = '.mainwp-restore-';
    const TEMP_SUFFIX                = '.tmp';

    /**
     * Keep standalone callers' foreign temps unless the runner supplies task knowledge.
     *
     * @param callable|null $is_temp_owner_live Returns true for live, false for absent/terminal, null for unknown.
     */
    public function __construct( $is_temp_owner_live = null ) {
        $this->is_temp_owner_live = $is_temp_owner_live;
    }

    /**
     * Identify reserved legacy and owner-tagged basenames for backup exclusion.
     *
     * @param string $basename File basename.
     *
     * @return bool Whether the name is reserved.
     */
    public static function is_reserved_temp_name( $basename ) {
        return (bool) preg_match( '/^' . preg_quote( self::TEMP_PREFIX, '/' ) . '[0-9a-f]+(?:-[0-9a-f]+)?' . preg_quote( self::TEMP_SUFFIX, '/' ) . '\z/', $basename );
    }

    /**
     * Allow small fixtures to exercise the production bounds.
     *
     * @return array Extraction limits.
     */
    protected function extraction_limits() {
        return array(
            'entries'         => self::MAX_EXTRACT_ENTRIES,
            'expanded_bytes'  => self::MAX_EXPANDED_BYTES,
            'ratio'           => self::MAX_COMPRESSION_RATIO,
            'ratio_min_bytes' => self::RATIO_MIN_COMPRESSED_BYTES,
        );
    }

    /**
     * Extract one bounded slice of the part archives.
     *
     * @param array     $cursor   Resume cursor (empty array on first call).
     * @param array     $config   { parts: string[], hashes: array<string,string>
     *                            keyed by basename, extract_to: string,
     *                            checkpoint_cursor?: callable,
     *                            temp_owner?: string (lowercase hex, stable per task) }.
     * @param float|int $deadline Absolute epoch the slice must yield by.
     *
     * @return array { done: bool, cursor: array, error: string|null,
     *                 error_code: string|null }
     */
    public function restore_slice( $cursor, $config, $deadline ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity -- bounded extraction loop with integrity gates.
        // Older partial cursors cannot reconstruct dumps written before this record existed.
        if ( empty( $cursor ) ) {
            $cursor = array( 'dump_files' => array() );
        }
        $cursor = array_merge(
            array(
                'part_index'     => 0,
                'entry_index'    => 0,
                'entries'        => 0,
                'expanded_bytes' => 0,
            ),
            is_array( $cursor ) ? $cursor : array()
        );

        if ( null === $this->temp_owner ) {
            $temp_owner = isset( $config['temp_owner'] ) ? $config['temp_owner'] : bin2hex( random_bytes( 8 ) );
            if ( ! is_string( $temp_owner ) || ! preg_match( '/^[0-9a-f]+\z/', $temp_owner ) ) {
                return $this->result( false, $cursor, 'Invalid restore temporary file owner.', MainWP_Backup_Error::ERR_RESTORE_EXTRACT );
            }
            $this->temp_owner = $temp_owner;
        }

        $this->checkpoint_cursor = isset( $config['checkpoint_cursor'] ) ? $config['checkpoint_cursor'] : null;
        $parts                   = $config['parts'];
        $extract_to              = $config['extract_to'];
        $did_work                = false;
        $part_count              = count( $parts );

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

        $this->real_htaccess_entries = array();
        $entry_count                 = (int) $zip->numFiles;
        while ( $cursor['entry_index'] < $entry_count ) {
            if ( $did_work && microtime( true ) >= $deadline ) {
                $zip->close();
                return $this->result( false, $cursor, null, null );
            }

            $name = $zip->getNameIndex( $cursor['entry_index'] );
            try {
                $this->extract_entry( $zip, $name, $extract_to, $cursor );
            } catch ( \Exception $e ) {
                $zip->close();
                $error_code = $e instanceof MainWP_Exception ? $e->get_message_error_code() : '';
                return $this->result( false, $cursor, $e->getMessage(), ! empty( $error_code ) ? $error_code : MainWP_Backup_Error::ERR_RESTORE_EXTRACT );
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
     * @throws MainWP_Exception When an entry directory cannot be created (returned as a coded slice result).
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
        // (8.4 returns empty entry streams), so compressed parts decompress
        // to a plain .tar first; the plain-tar reader is solid everywhere.
        $this->preparation_limit_exceeded = false;
        $timed_out                        = false;
        $plain                            = $this->plain_tar_for( $part, $deadline, $timed_out );
        if ( null === $plain ) {
            if ( $this->preparation_limit_exceeded ) {
                return $this->result( false, $cursor, 'The compressed archive ' . basename( $part ) . ' expands beyond the restore size limit.', MainWP_Backup_Error::ERR_RESTORE_EXTRACT );
            }
            if ( $timed_out ) {
                if ( $did_work ) {
                    // A prior part spent most of this slice; retry the
                    // decompression next slice with a full budget before
                    // judging the stream too large. The next slice reaches this
                    // part first (did_work false), so it is never an endless
                    // retry: a full slice that still cannot finish refuses below.
                    return $this->result( false, $cursor, null, null );
                }
                // A full slice was not enough to decompress this stream and decompression
                // cannot resume across requests, so refuse rather than loop.
                return $this->result(
                    false,
                    $cursor,
                    'The compressed archive ' . basename( $part ) . ' is too large to decompress within one restore slice on this host. Restore on a host with a higher PHP time limit, or re-create the backup in the uncompressed .tar format.',
                    MainWP_Backup_Error::ERR_RESTORE_EXTRACT
                );
            }
            $message = preg_match( '/\.tar\.(gz|bz2)$/i', $part ) ? 'Could not decompress archive: ' : 'Could not prepare archive for reading: ';
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

        $this->real_htaccess_entries = array();
        foreach ( $entries as $entry ) {
            if ( ! $entry['dir'] && '.htaccess' === basename( $entry['name'] ) ) {
                $this->real_htaccess_entries[ $entry['name'] ] = true;
            }
        }

        $entry_count = count( $entries );
        while ( $cursor['entry_index'] < $entry_count ) {
            if ( $did_work && microtime( true ) >= $deadline ) {
                return $this->result( false, $cursor, null, null );
            }

            $entry = $entries[ $cursor['entry_index'] ];
            if ( static::should_skip_entry( $entry['name'] ) ) {
                ++$cursor['entry_index'];
                $did_work = true;
                continue;
            }
            $entry['name'] = static::map_entry_name( $entry['name'], $this->real_htaccess_entries );
            if ( null === $entry['name'] ) {
                ++$cursor['entry_index'];
                $did_work = true;
                continue;
            }
            try {
                // Tar compression covers the container, not individual entries.
                $this->check_entry_limits( $entry['size'], 0, $cursor, $entry['name'] );
                if ( $entry['dir'] ) {
                    $target = static::validate_extract_path( rtrim( $entry['name'], '/' ), $extract_to );
                    if ( ! is_dir( $target ) ) {
                        wp_mkdir_p( $target );
                        if ( ! is_dir( $target ) ) {
                            throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_RESTORE_TARGET_NOT_WRITABLE ) ), null, MainWP_Backup_Error::ERR_RESTORE_TARGET_NOT_WRITABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry message.
                        }
                    }
                } else {
                    $target = static::validate_extract_path( $entry['name'], $extract_to );
                    $stream = fopen( $entry['phar_url'], 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- streaming extraction from the tar.
                    if ( false === $stream ) {
                        throw new \Exception( sprintf( 'Could not read archive entry: %s.', esc_html( $entry['name'] ) ) );
                    }
                    try {
                        $this->write_entry_stream( $stream, $target, $cursor, 0, $entry['name'], $extract_to );
                    } finally {
                        fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- paired with fopen above.
                    }
                }
            } catch ( \Exception $e ) {
                // The success path below discards the decompressed sibling;
                // the error path must too, or a rejected archive leaves a
                // full-size plain tar behind in the backup directory.
                $this->discard_plain_tar( $part, $plain );
                $error_code = $e instanceof MainWP_Exception ? $e->get_message_error_code() : '';
                return $this->result( false, $cursor, $e->getMessage(), ! empty( $error_code ) ? $error_code : MainWP_Backup_Error::ERR_RESTORE_EXTRACT );
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
     * A .tar passes through; .tar.gz and .tar.bz2 stream into a sibling temp
     * file. Decompression is bounded by the slice deadline and cannot be
     * resumed across requests (the stream state is not serializable), so a
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
        if ( ! preg_match( '/\.tar\.(gz|bz2)$/i', $part ) ) {
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
            $tmp = $plain . '.' . bin2hex( random_bytes( 6 ) ) . '.tmp';
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

        $gzip  = (bool) preg_match( '/\.tar\.gz$/i', $part );
        $open  = $gzip ? 'gzopen' : 'bzopen';
        $read  = $gzip ? 'gzread' : 'bzread';
        $close = $gzip ? 'gzclose' : 'bzclose';
        if ( ! function_exists( $open ) || ! function_exists( $read ) || ! function_exists( $close ) ) {
            return null;
        }

        $in = @$open( $part, 'r' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open failures are handled below; warnings must not corrupt the response.
        if ( false === $in ) {
            return null;
        }
        // Same reason as the hard-link branch: a resumed runner shares this
        // task's scratch dir, so a shared name lets one promote the other's
        // half-written stream.
        $compressed_tmp = $plain . '.' . bin2hex( random_bytes( 6 ) ) . '.tmp';
        $this->sweep_stale_plain_temps( $plain, $compressed_tmp );
        $out = @fopen( $compressed_tmp, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged -- open failures are handled below; warnings must not corrupt the response.
        if ( false === $out ) {
            $close( $in );
            return null;
        }
        $written    = 0;
        $limits     = $this->extraction_limits();
        $compressed = filesize( $part );
        while ( true ) {
            if ( microtime( true ) >= $deadline ) {
                // Out of slice mid-stream. Decompression cannot resume, so discard
                // the partial and refuse rather than loop forever from byte 0.
                $close( $in );
                fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- paired with fopen above.
                wp_delete_file( $compressed_tmp );
                $timed_out = true;
                return null;
            }
            $chunk = $read( $in, 262144 );
            if ( '' === $chunk ) {
                break;
            }
            if ( false === $chunk || strlen( $chunk ) !== fwrite( $out, $chunk ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- streaming decompression.
                $close( $in );
                fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- paired with fopen above.
                wp_delete_file( $compressed_tmp );
                return null;
            }
            $written += strlen( $chunk );
            if ( $written > $limits['expanded_bytes'] || ( $compressed > 0 && $compressed >= $limits['ratio_min_bytes'] && $written > $compressed * $limits['ratio'] ) ) {
                $close( $in );
                fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- discard decompression that exceeds extraction limits.
                wp_delete_file( $compressed_tmp );
                $this->preparation_limit_exceeded = true;
                return null;
            }
        }
        $close( $in );
        $closed = $this->close_out( $out );

        // The byte count guards against promoting an incomplete file as a reusable alias.
        clearstatcache( true, $compressed_tmp );
        if ( ! $closed || @filesize( $compressed_tmp ) !== $written || ! @rename( $compressed_tmp, $plain ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged -- atomic promote; filesize/rename warnings must not corrupt the response.
            wp_delete_file( $compressed_tmp );
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
        $directory = dirname( $plain );
        $prefix    = basename( $plain ) . '.';
        $entries   = @scandir( $directory ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unreadable directory cannot be swept; warnings must not corrupt the response.
        if ( false === $entries ) {
            return;
        }
        foreach ( $entries as $entry ) {
            if ( 0 !== strpos( $entry, $prefix ) || '.tmp' !== substr( $entry, -4 ) ) {
                continue;
            }
            $stale = $directory . '/' . $entry;
            if ( $stale === $tmp ) {
                continue;
            }
            $mtime = $this->plain_temp_mtime( $stale );
            // This bound protects a live peer; abandoned temps may survive the first resume until a later one.
            if ( false !== $mtime && $mtime < time() - MainWP_Child_Backup_Task_Runner::LEASE_TTL_SECONDS ) {
                wp_delete_file( $stale );
            }
        }
    }

    /**
     * Read a temporary file's age, preserving stat failures for the sweep.
     *
     * @param string $path Temporary file path.
     *
     * @return int|false Modification time, or false when stat fails.
     */
    protected function plain_temp_mtime( $path ) {
        return @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a peer may remove its temp before stat; warnings must not corrupt the response.
    }

    /**
     * Close the decompressed output stream.
     *
     * PHP's fclose() only reports an invalid stream, not a deferred close(2) error, so the
     * byte count compared against the promoted file is the completeness gate.
     *
     * @param resource $out Output stream.
     *
     * @return bool Whether fclose() accepted the stream.
     */
    protected function close_out( $out ) {
        return @fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged -- completeness depends on the byte count; warnings must not corrupt the response.
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
        if ( in_array( strtolower( $extension ), array( '.tar.gz', '.tar.bz2' ), true ) ) {
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
        $message = str_replace( array( $plain, $part ), array( basename( $plain ), basename( $part ) ), $e->getMessage() );
        $message = $this->reduce_host_paths( $message );

        return 'Could not open archive: ' . basename( $part ) . ' (' . get_class( $e ) . ': ' . $message . ')';
    }

    /**
     * Reduce host paths and keep error text safe to render after truncation.
     * The non-mbstring fallback is not covered by the test matrix.
     *
     * @param string $message Host error text.
     * @return string Reduced UTF-8 text.
     */
    private function reduce_host_paths( $message ) {
        $message = preg_replace( '/[\x00-\x1F\x7F]/', '', $message );
        $uploads = wp_upload_dir( null, false );
        // The shared bases are realpaths; PHP can also report the configured paths.
        $bases = array_merge( MainWP_Backup::default_backup_bases(), array( ABSPATH, WP_CONTENT_DIR, $uploads['basedir'] ) );
        usort(
            $bases,
            static function ( $left, $right ) {
                return strlen( $right ) <=> strlen( $left );
            }
        );
        foreach ( array_unique( $bases ) as $base ) {
            $base = rtrim( $base, '/\\' );
            if ( '' !== $base ) {
                $message = str_replace( $base, '[site]', $message );
            }
        }
        $message = preg_replace( '~/(?:[^\s/"\'<>()[\]{}]+/)+([^\s/"\'<>()[\]{}]+)~', '$1', $message );
        // Backslash paths take a second pass: a Windows or UNC path reported by
        // PHP survives the forward-slash collapse above and would carry the host
        // directory tree and the OS user name to the dashboard.
        $message = preg_replace( '~(?:[A-Za-z]:)?\\\\(?:[^\s\\\\"\'<>()[\]{}]+\\\\)+([^\s\\\\"\'<>()[\]{}]+)~', '$1', $message );
        // Character truncation preserves boundaries, but host errors may already
        // contain invalid UTF-8 that esc_html() would render as an empty string.
        $message = mb_substr( (string) $message, 0, 300 );
        // mbstring is optional; an Error here escapes both extraction loops' catch ( Exception ).
        if ( function_exists( 'mb_check_encoding' ) && function_exists( 'mb_convert_encoding' ) ) {
            if ( ! mb_check_encoding( $message, 'UTF-8' ) ) {
                $message = mb_convert_encoding( $message, 'UTF-8', 'UTF-8' );
            }
        } elseif ( ! preg_match( '//u', $message ) ) {
            $message = preg_replace( '/[\x80-\xFF]/', '?', $message );
        }
        return $message;
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
     * @throws MainWP_Exception When the archive exceeds the entry limit.
     *
     * @return array<int,array{name:string,dir:bool,phar_url:string,size:int}> Sorted entries.
     */
    private function tar_entry_names( $phar ) {
        $prefix  = 'phar://' . $phar->getPath() . '/';
        $entries = array();
        $limits  = $this->extraction_limits();

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
                'size'     => $item->getSize(),
            );
            if ( count( $entries ) > $limits['entries'] ) {
                throw new MainWP_Exception( sprintf( 'Restore entry count limit exceeded: %s.', esc_html( $name ) ), null, MainWP_Backup_Error::ERR_RESTORE_EXTRACT ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- entry name is escaped; code is a registry constant.
            }
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

            if ( static::should_skip_entry( $relpath ) ) {
                continue;
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

            if ( ! @unlink( $target ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged -- failure is returned through the restore error registry.
                clearstatcache( true, $target );
                if ( ! file_exists( $target ) ) {
                    ++$deleted;
                    continue;
                }
                return array(
                    'deleted'    => $deleted,
                    'error'      => sprintf( 'Could not delete tombstoned file: %s.', esc_html( $relpath ) ),
                    'error_code' => MainWP_Backup_Error::ERR_RESTORE_EXTRACT,
                );
            }
            ++$deleted;
        }

        return array(
            'deleted'    => $deleted,
            'error'      => null,
            'error_code' => null,
        );
    }

    /**
     * Skip legacy clone configuration and redundant .htaccess aliases.
     *
     * @param string $name                  Archive entry name.
     * @param array  $real_htaccess_entries Real .htaccess presence keyed by entry name.
     * @return string|null Destination entry name, or null for a skipped entry.
     */
    private static function map_entry_name( $name, $real_htaccess_entries = array() ) {
        $normalized = implode( '/', array_diff( explode( '/', wp_normalize_path( $name ) ), array( '', '.' ) ) );
        if ( in_array( $normalized, array( 'clone', 'clone/config.txt' ), true ) ) {
            return null;
        }
        $mapped = preg_replace( '#(^|/)mainwp-htaccess$#', '$1.htaccess', $name );
        return $mapped !== $name && ! empty( $real_htaccess_entries[ $mapped ] ) ? null : $mapped;
    }

    /**
     * Restoring the running plugin would invalidate the Dashboard's capability record.
     *
     * @param string $name Site-relative entry name.
     * @return bool Whether the entry belongs to the active Child plugin.
     */
    private static function should_skip_entry( $name ) {
        $name = wp_normalize_path( $name );
        if ( 0 === strpos( $name, '/' ) || false !== strpos( $name, '..' ) ) {
            return false;
        }
        $name       = implode( '/', array_diff( explode( '/', $name ), array( '', '.' ) ) );
        $plugin_dir = rtrim( str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( MAINWP_CHILD_PLUGIN_DIR ) ), '/' );

        return MainWP_Helper::in_excludes( array( $plugin_dir ), $name );
    }

    /**
     * Extract one archive entry to its validated target.
     *
     * @param \ZipArchive $zip        Open archive.
     * @param string      $name       Entry name.
     * @param string      $extract_to Extraction root.
     * @param array       $cursor     Cumulative extraction counters (by reference).
     *
     * @throws \Exception When the entry validates outside the root or
     *                    cannot be written.
     * @throws MainWP_Exception When entry metadata cannot be read or a directory cannot be created.
     */
    private function extract_entry( $zip, $name, $extract_to, &$cursor ) {
        if ( static::should_skip_entry( $name ) ) {
            return;
        }
        $mapped = static::map_entry_name( $name );
        if ( null === $mapped ) {
            return;
        }
        if ( $mapped !== $name && ! array_key_exists( $mapped, $this->real_htaccess_entries ) ) {
            $this->real_htaccess_entries[ $mapped ] = false !== $zip->locateName( $mapped );
        }
        $entry_name = static::map_entry_name( $name, $this->real_htaccess_entries );
        if ( null === $entry_name ) {
            return;
        }
        $stat = $zip->statIndex( $cursor['entry_index'] );
        if ( false === $stat ) {
            throw new MainWP_Exception( 'Could not read archive entry metadata.', null, MainWP_Backup_Error::ERR_RESTORE_EXTRACT ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- static internal message.
        }
        $this->check_entry_limits( $stat['size'], $stat['comp_size'], $cursor, $name );
        if ( '/' === substr( $name, -1 ) ) {
            // Directory entry.
            $target = static::validate_extract_path( rtrim( $entry_name, '/' ), $extract_to );
            if ( ! is_dir( $target ) ) {
                wp_mkdir_p( $target );
                if ( ! is_dir( $target ) ) {
                    throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_RESTORE_TARGET_NOT_WRITABLE ) ), null, MainWP_Backup_Error::ERR_RESTORE_TARGET_NOT_WRITABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry message.
                }
            }
            return;
        }

        $target = static::validate_extract_path( $entry_name, $extract_to );

        $stream = $zip->getStream( $name );
        if ( false === $stream ) {
            throw new \Exception( sprintf( 'Could not read archive entry: %s.', esc_html( $name ) ) );
        }

        try {
            $this->write_entry_stream( $stream, $target, $cursor, $stat['comp_size'], $name, $extract_to );
        } finally {
            fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- paired with getStream above.
        }
    }

    /**
     * Replace a target only after its entry has been fully written and closed.
     *
     * @param resource $stream     Entry stream.
     * @param string   $target     Validated target path.
     * @param array    $cursor     Cumulative extraction counters (by reference).
     * @param int      $compressed Compressed entry size, zero for tar entries.
     * @param string   $entry_name Archive entry name.
     * @param string   $extract_to Extraction root.
     *
     * @throws \Exception When writing or promoting the entry fails.
     * @throws \Throwable After discarding failed temporary output.
     */
    private function write_entry_stream( $stream, $target, &$cursor, $compressed, $entry_name, $extract_to ) {
        $directory = dirname( $target );
        $this->sweep_entry_temps( $directory );
        $perms = false;
        $owner = false;
        $group = false;
        if ( ! is_link( $target ) && is_file( $target ) ) {
            // phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged -- metadata reads can fail without preventing replacement.
            $perms = @fileperms( $target );
            $owner = @fileowner( $target );
            $group = @filegroup( $target );
            // phpcs:enable WordPress.PHP.NoSilencedErrors.Discouraged
        }
        // A false read must not become chmod( 0 ), which would leave the
        // restored file unreadable; fall through to the creation default.
        $mode               = false === $perms ? null : ( $perms & 0777 );
        list( $temp, $out ) = $this->create_entry_temp( $target, $entry_name );
        try {
            $this->copy_bounded_entry( $stream, $out, $cursor, $compressed, $entry_name );
            $closed = fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- finish the temporary file before promotion.
            $out    = null;
            if ( ! $closed ) {
                throw new \Exception( sprintf( 'Could not finish writing extracted file: %s.', esc_html( $entry_name ) ) );
            }
            $this->promote_entry_temp( $temp, $target, $mode, $entry_name );
            // Only promoted dump members belong to the replay set, including across slices.
            $dump_root = rtrim( $extract_to, '/' );
            if ( in_array( dirname( $target ), array( $dump_root, $dump_root . '/wp-content' ), true ) && isset( $cursor['dump_files'] ) && preg_match( '/^dbBackup.*\.sql\.php$/D', basename( $target ) ) && ! in_array( $target, $cursor['dump_files'], true ) ) {
                $cursor['dump_files'][] = $target;
            }
            clearstatcache( true, $target );
            // Ownership changes may be denied to PHP; restored bytes and mode remain valid.
            // phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions -- native ownership preservation is best effort.
            if ( false !== $owner && @fileowner( $target ) !== $owner ) {
                @chown( $target, $owner );
            }
            if ( false !== $group && @filegroup( $target ) !== $group ) {
                @chgrp( $target, $group );
            }
            // phpcs:enable WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions
        } catch ( \Throwable $e ) {
            if ( is_resource( $out ) ) {
                fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- close before discarding failed output.
            }
            wp_delete_file( $temp );
            throw $e;
        }
    }

    /**
     * Create an exclusive output stream, retaining the final open failure's cause.
     *
     * @param string $target Validated target path.
     * @param string $entry_name Archive entry name.
     * @return array Temporary path and open stream.
     * @throws \Exception When all creation attempts fail.
     */
    private function create_entry_temp( $target, $entry_name ) {
        $out = false;
        error_clear_last();
        for ( $attempt = 0; $attempt < 3 && false === $out; ++$attempt ) {
            $temp = $this->entry_temp_path( $target );
            $out  = is_link( $temp ) ? false : @fopen( $temp, 'xb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged -- exclusive creation never truncates an existing path.
        }
        if ( false !== $out ) {
            return array( $temp, $out );
        }

        $error     = error_get_last();
        $directory = dirname( $target );
        $parent    = dirname( $entry_name );
        $where     = '.' === $parent ? 'the site root' : $parent;
        if ( ! is_dir( $directory ) ) {
            $why = 'the directory no longer exists.';
        } elseif ( ! empty( $error['message'] ) ) {
            // PHP includes the absolute temporary path before the stream error.
            $cause = preg_replace( '/^.*(?:Failed to open stream: |failed to open stream: )/s', '', $error['message'] );
            $cause = str_replace( array( $temp, $directory ), array( basename( $temp ), $where ), $cause );
            $why   = $this->reduce_host_paths( $cause );
        } elseif ( is_writable( $directory ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- fallback when PHP recorded no open error.
            $why = 'temporary file creation failed; check available disk space and filesystem restrictions.';
        } else {
            $why = 'the restore needs write permission on that directory.';
        }
        throw new \Exception( sprintf( 'Could not create a temporary file in %s: %s', esc_html( $where ), esc_html( $why ) ) );
    }

    /**
     * Preserve the mode before replacement; diagnose either failure with one stat.
     *
     * @param string   $temp Temporary path.
     * @param string   $target Validated target path.
     * @param int|null $mode Existing target mode, or null for the creation default.
     * @param string   $entry_name Archive entry name.
     * @throws \Exception When chmod or rename fails.
     */
    private function promote_entry_temp( $temp, $target, $mode, $entry_name ) {
        // Short-circuiting keeps chmod before rename and avoids probing successful entries.
        // phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged -- native mode preservation and atomic replacement.
        if ( ( null === $mode || @chmod( $temp, $mode ) ) && @rename( $temp, $target ) ) {
            return;
        }
        // phpcs:enable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged
        clearstatcache( true, $temp );
        if ( ! file_exists( $temp ) ) {
            throw new \Exception( sprintf( 'The temporary file for %s was removed before it could be promoted; another restore worker may be running on this site.', esc_html( $entry_name ) ) );
        }
        throw new \Exception( sprintf( 'Could not write extracted file: %s.', esc_html( $entry_name ) ) );
    }

    /**
     * Choose a new same-directory name for an exclusive entry write.
     *
     * @param string $target Validated target path.
     *
     * @return string Temporary path.
     * @throws \LogicException When the owner token is invalid.
     */
    protected function entry_temp_path( $target ) {
        if ( ! is_string( $this->temp_owner ) || ! preg_match( '/^[0-9a-f]+\z/', $this->temp_owner ) ) {
            throw new \LogicException( 'An extraction temp requires a valid owner token.' );
        }
        return dirname( $target ) . '/' . self::TEMP_PREFIX . $this->temp_owner . '-' . bin2hex( random_bytes( 8 ) ) . self::TEMP_SUFFIX;
    }

    /**
     * Match only names attributable to this restore task.
     *
     * @param string $basename File basename.
     *
     * @return bool Whether this task owns the temp name.
     */
    private function is_owned_temp_name( $basename ) {
        return self::is_reserved_temp_name( $basename ) && 0 === strpos( $basename, self::TEMP_PREFIX . $this->temp_owner . '-' );
    }

    /**
     * Reclaim regular temps owned by this task or by a confirmed inactive task.
     *
     * Age cannot distinguish abandoned temps from restored members, whose
     * mtimes are fresh. Ownership makes repeated sweeps across slices safe;
     * the once-per-object directory guard only avoids redundant scans.
     *
     * @param string $directory Validated target directory.
     */
    private function sweep_entry_temps( $directory ) {
        $resolved  = realpath( $directory );
        $directory = false === $resolved ? $directory : $resolved;
        if ( isset( $this->swept_directories[ $directory ] ) ) {
            return;
        }
        $this->swept_directories[ $directory ] = true;
        foreach ( (array) glob( $directory . '/' . self::TEMP_PREFIX . '*' . self::TEMP_SUFFIX ) as $temp ) {
            if ( is_link( $temp ) || ! is_file( $temp ) ) {
                continue;
            }
            if ( ! $this->is_owned_temp_name( basename( $temp ) ) ) {
                // Legacy names have no owner; unknown liveness must not delete a live peer's output.
                if ( ! is_callable( $this->is_temp_owner_live ) ||
                    ! preg_match( '/^' . preg_quote( self::TEMP_PREFIX, '/' ) . '([0-9a-f]+)-[0-9a-f]+' . preg_quote( self::TEMP_SUFFIX, '/' ) . '\z/', basename( $temp ), $matches ) ||
                    false !== call_user_func( $this->is_temp_owner_live, $matches[1] ) ) {
                    continue;
                }
            }
            wp_delete_file( $temp );
        }
    }

    /**
     * Reject excessive work before creating an extraction target.
     *
     * @param int    $size       Declared entry size.
     * @param int    $compressed Compressed entry size, zero for tar entries.
     * @param array  $cursor     Cumulative extraction counters (by reference).
     * @param string $entry_name Archive entry name.
     *
     * @throws MainWP_Exception When an extraction limit is exceeded.
     */
    private function check_entry_limits( $size, $compressed, &$cursor, $entry_name ) {
        $limits = $this->extraction_limits();
        if ( $cursor['entries'] >= $limits['entries'] ) {
            throw new MainWP_Exception( sprintf( 'Restore entry count limit exceeded: %s.', esc_html( $entry_name ) ), null, MainWP_Backup_Error::ERR_RESTORE_EXTRACT ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- entry name is escaped; code is a registry constant.
        }
        if ( $compressed > 0 && $compressed >= $limits['ratio_min_bytes'] && $size / $compressed > $limits['ratio'] ) {
            throw new MainWP_Exception( sprintf( 'Restore compression ratio limit exceeded: %s.', esc_html( $entry_name ) ), null, MainWP_Backup_Error::ERR_RESTORE_EXTRACT ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- entry name is escaped; code is a registry constant.
        }
        ++$cursor['entries'];
    }

    /**
     * Bound actual output so an understated archive header cannot bypass limits.
     *
     * @param resource $stream     Entry stream.
     * @param resource $out        Validated output stream.
     * @param array    $cursor     Cumulative extraction counters (by reference).
     * @param int      $compressed Compressed entry size, zero for tar entries.
     * @param string   $entry_name Archive entry name.
     *
     * @throws MainWP_Exception When a limit is exceeded or a stream fails.
     */
    private function copy_bounded_entry( $stream, $out, &$cursor, $compressed, $entry_name ) {
        $limits      = $this->extraction_limits();
        $entry_bytes = 0;
        while ( ! feof( $stream ) ) {
            $remaining = $limits['expanded_bytes'] - $cursor['expanded_bytes'];
            if ( $compressed > 0 && $compressed >= $limits['ratio_min_bytes'] ) {
                $remaining = min( $remaining, $compressed * $limits['ratio'] - $entry_bytes );
            }
            $chunk = fread( $stream, min( 262144, max( 1, $remaining ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- bounded archive stream.
            if ( false === $chunk || ( '' === $chunk && ! feof( $stream ) ) ) {
                throw new MainWP_Exception( sprintf( 'Could not read archive entry: %s.', esc_html( $entry_name ) ), null, MainWP_Backup_Error::ERR_RESTORE_EXTRACT ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- entry name is escaped; code is a registry constant.
            }
            if ( '' === $chunk ) {
                break;
            }
            if ( strlen( $chunk ) > $remaining ) {
                throw new MainWP_Exception( sprintf( 'Restore expanded output limit exceeded: %s.', esc_html( $entry_name ) ), null, MainWP_Backup_Error::ERR_RESTORE_EXTRACT ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- entry name is escaped; code is a registry constant.
            }
            $written = fwrite( $out, $chunk ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- bounded archive stream.
            if ( false !== $written ) {
                $cursor['expanded_bytes'] += $written;
                $entry_bytes              += $written;
            }
            if ( strlen( $chunk ) !== $written ) {
                throw new MainWP_Exception( sprintf( 'Could not write extracted file: %s.', esc_html( $entry_name ) ), null, MainWP_Backup_Error::ERR_RESTORE_EXTRACT ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- entry name is escaped; code is a registry constant.
            }
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
     * @throws MainWP_Exception When an entry's parent directory cannot be created.
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
            // Creating parents through an outward symlink would modify files outside the restore root.
            $ancestor = dirname( $target_path );
            while ( ! file_exists( $ancestor ) && ! is_link( $ancestor ) && dirname( $ancestor ) !== $ancestor ) {
                $ancestor = dirname( $ancestor );
            }
            $real_ancestor = realpath( $ancestor );
            if ( false === $real_ancestor || strpos( $real_ancestor . '/', $real_extract . '/' ) !== 0 ) {
                throw new \Exception(
                    sprintf( 'Archive entry resolves outside extraction directory: %s. Extraction aborted.', esc_html( $entry_name ) )
                );
            }
            wp_mkdir_p( dirname( $target_path ) );
            if ( ! is_dir( dirname( $target_path ) ) ) {
                throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_RESTORE_TARGET_NOT_WRITABLE ) ), null, MainWP_Backup_Error::ERR_RESTORE_TARGET_NOT_WRITABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry message.
            }
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
