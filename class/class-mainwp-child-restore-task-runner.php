<?php
/**
 * MainWP Child Restore Task Runner - restore phases on the task spine.
 *
 * Restore rides the same persisted task table, lease/CAS discipline,
 * slice budget, and watchdog as backups - this runner only swaps the
 * phase dispatch. The phases: artifacts arrive into a per-task scratch
 * directory (via the chunk-receive callable, plus adoption of artifacts
 * already on this site), get hash-verified, file archives extract over
 * the site root through the zip-slip guard, the database replays into
 * tmp tables and swaps atomically (or in place under maintenance mode
 * when the dump declares foreign keys), URLs rewrite
 * serialization-aware, and a final verification gates the drop of the
 * old aside tables.
 *
 * Failure discipline: any phase error lands the task in the terminal
 * failed phase with tmp tables dropped, maintenance mode lifted, and
 * the scratch directory removed - but the old-prefixed aside tables are
 * deliberately kept. They are the instant database rollback until a
 * verified restore (or an operator) retires them.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Child_Restore_Task_Runner
 *
 * @package MainWP\Child
 */
class MainWP_Child_Restore_Task_Runner extends MainWP_Child_Backup_Task_Runner { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Restore-task resume cap: a restore spans many more slices than a
     * backup (transfer + extraction + replay), so the watchdog gets more
     * re-entries before declaring failure.
     */
    const RESTORE_MAX_RESUMES = 20;

    /**
     * Minimum wall-clock horizon for a restore task, seconds.
     */
    const RESTORE_MIN_DEADLINE_SECONDS = 3600;

    /**
     * Singleton (separate from the backup runner's).
     *
     * @var MainWP_Child_Restore_Task_Runner|null
     */
    protected static $restore_instance = null;

    /**
     * Get the singleton instance.
     *
     * @return MainWP_Child_Restore_Task_Runner
     */
    public static function instance() {
        if ( null === static::$restore_instance ) {
            static::$restore_instance = new self();
        }
        return static::$restore_instance;
    }

    /**
     * Whether a task type belongs to this runner.
     *
     * @param string $type Task type.
     *
     * @return bool True for restore types.
     */
    public static function is_restore_type( $type ) {
        return in_array( $type, array( 'restore', 'restore_db', 'restore_files' ), true );
    }

    /**
     * Advance one slice for a stalled restore task. Backup types are the
     * backup runner's; this listener only takes restore tasks.
     *
     * @param object $task    Stalled task row (pre-resume state).
     * @param int    $version Row version after the watchdog's bookkeeping.
     */
    public function handle_watchdog_resume( $task, $version ) {
        if ( ! static::is_restore_type( $task->type ) ) {
            return;
        }
        parent::handle_watchdog_resume( $task, $version );
    }

    /**
     * Validate params and create a restore task.
     *
     * @param string $sub_type 'full', 'db', or 'files'.
     * @param array  $params   Restore params persisted into the cursor:
     *                         { restore_id, source_prefix, artifacts,
     *                         has_foreign_keys, file_rollback }.
     *
     * @throws MainWP_Exception On multisite, an already-active task, or a
     *                          failed insert.
     *
     * @return string Task id.
     */
    public function create_restore_task( $sub_type, $params ) {
        $spine = MainWP_Child_Backup_Task::instance();
        $spine->install();

        if ( ! empty( $spine->get_active_tasks() ) ) {
            throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_ALREADY_RUNNING ) ), null, MainWP_Backup_Error::ERR_ALREADY_RUNNING ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        }

        $total_bytes = 0;
        foreach ( (array) $params['artifacts'] as $artifact ) {
            $total_bytes += isset( $artifact['size'] ) ? (int) $artifact['size'] : 0;
        }
        // The horizon scales with payload (transfer, extraction, and replay
        // all do): one hour base plus a second per half-megabyte.
        $deadline = self::RESTORE_MIN_DEADLINE_SECONDS + (int) ( $total_bytes / 524288 );

        $type = 'restore';
        if ( 'db' === $sub_type ) {
            $type = 'restore_db';
        } elseif ( 'files' === $sub_type ) {
            $type = 'restore_files';
        }
        $task_id = $spine->create_task( $type, array( 'params' => $params ), self::RESTORE_MAX_RESUMES, $deadline );
        if ( false === $task_id ) {
            throw new MainWP_Exception( 'The restore task could not be created on this site.', null, '' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message.
        }

        $scratch = $this->scratch_dir( $task_id, $this->backup_dir() );
        if ( ! is_dir( $scratch ) ) {
            wp_mkdir_p( $scratch );
        }

        // A previous failed restore with the same id left tmp litter at
        // most; clearing it here keeps the replay idempotent.
        $this->restore_engine()->cleanup_tmp_tables( $params['restore_id'] );

        return $task_id;
    }

    /**
     * Whether this install is a WordPress multisite (test seam).
     *
     * @return bool
     */
    protected function is_multisite_install() {
        return is_multisite();
    }

    /**
     * The URL-rewrite exclusion map: every posts table's guid column
     * stays untouched, covering the main site's table and each
     * sub-blog's numbered table on a multisite network.
     *
     * @param array  $stems         Loaded table stems.
     * @param string $target_prefix Live table prefix.
     *
     * @return array<string,array> Excluded columns by table name.
     */
    public function guid_exclusions( $stems, $target_prefix ) {
        $exclude = array();
        foreach ( (array) $stems as $stem ) {
            if ( 'posts' === $stem || preg_match( '/^\d+_posts$/', (string) $stem ) ) {
                $exclude[ $target_prefix . $stem ] = array( 'guid' );
            }
        }
        return $exclude;
    }

    /**
     * Sanitize a cloud-pull manifest from the wire against the task's
     * own artifact list.
     *
     * Rejection is wholesale: any malformed entry, any URL that is not
     * https, any hash that is not hex sha256, or any basename outside
     * the artifact list (or adopt-only) invalidates the whole manifest.
     * The caller then creates the task without it and the restore takes
     * the staged transfer path.
     *
     * @param mixed $raw       Decoded pull_manifest_json.
     * @param array $artifacts Sanitized task artifact entries.
     *
     * @return array|null Clean manifest, or null when rejected.
     */
    public static function sanitize_pull_manifest( $raw, $artifacts ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity -- ordered validation gates on wire input.
        if ( ! is_array( $raw ) || empty( $raw['artifacts'] ) || ! is_array( $raw['artifacts'] ) ) {
            return null;
        }

        $allowed = array();
        foreach ( $artifacts as $artifact ) {
            if ( empty( $artifact['adopt_only'] ) && isset( $artifact['file'] ) ) {
                $allowed[ (string) $artifact['file'] ] = true;
            }
        }

        $clean = array();
        foreach ( $raw['artifacts'] as $entry ) {
            if ( ! is_array( $entry ) || empty( $entry['file'] ) || empty( $allowed[ (string) $entry['file'] ] ) ) {
                return null;
            }
            if ( ! isset( $entry['sha256'] ) || ! is_string( $entry['sha256'] ) || ! preg_match( '/^[a-f0-9]{64}$/', $entry['sha256'] ) ) {
                return null;
            }

            $item = array(
                'file'   => (string) $entry['file'],
                'sha256' => $entry['sha256'],
                'size'   => isset( $entry['size'] ) ? (int) $entry['size'] : 0,
            );

            if ( isset( $entry['chunks'] ) && is_array( $entry['chunks'] ) && ! empty( $entry['chunks'] ) ) {
                $chunks = array();
                foreach ( $entry['chunks'] as $chunk ) {
                    if ( ! is_array( $chunk ) ) {
                        return null;
                    }
                    // Chunks are content-addressed with the algorithm the
                    // upload recorded; only known, locally available
                    // algorithms are verifiable here.
                    $algo = isset( $chunk['algo'] ) ? (string) $chunk['algo'] : 'sha256';
                    if ( ! in_array( $algo, array( 'sha256', 'xxh128' ), true ) || ! in_array( $algo, hash_algos(), true ) ) {
                        return null;
                    }
                    $length = 'sha256' === $algo ? 64 : 32;
                    if ( ! isset( $chunk['hash'] ) || ! is_string( $chunk['hash'] ) || ! preg_match( '/^[a-f0-9]{' . $length . '}$/', $chunk['hash'] ) ) {
                        return null;
                    }
                    if ( ! static::is_https_url( isset( $chunk['url'] ) ? $chunk['url'] : '' ) ) {
                        return null;
                    }
                    $chunks[] = array(
                        'hash' => $chunk['hash'],
                        'algo' => $algo,
                        'url'  => (string) $chunk['url'],
                    );
                }
                $item['chunks'] = $chunks;
            } else {
                if ( ! static::is_https_url( isset( $entry['url'] ) ? $entry['url'] : '' ) ) {
                    return null;
                }
                $item['url'] = (string) $entry['url'];
            }

            $clean[] = $item;
        }

        return array( 'artifacts' => $clean );
    }

    /**
     * Whether a value is a safe https pull URL. Retained as the call seam
     * inside sanitize_pull_manifest(); delegates to is_safe_remote_url() so
     * the manifest URL gates also block SSRF targets.
     *
     * @param mixed $url Candidate.
     *
     * @return bool
     */
    protected static function is_https_url( $url ) {
        return static::is_safe_remote_url( $url );
    }

    /**
     * Whether a pull URL is safe to fetch.
     *
     * Requires https. Cloud-metadata and link-local targets (169.254.0.0/16,
     * the metadata hostnames, IPv6 ::1 and fe80::/10) are rejected
     * unconditionally. Other private/loopback hosts (RFC1918, 127.0.0.0/8,
     * fc00::/7) are rejected unless an operator opts in with the
     * MAINWP_BACKUP_ALLOW_PRIVATE_PULL constant or the
     * mainwp_backup_allow_private_pull option (the on-prem MinIO case). Both
     * the literal host and a resolved IPv4 are checked. This is deliberately
     * not a blanket wp_http_validate_url(): a configured private endpoint
     * must stay reachable when the override is set.
     *
     * @param mixed $url Candidate.
     *
     * @return bool
     */
    protected static function is_safe_remote_url( $url ) {
        if ( ! is_string( $url ) || '' === $url ) {
            return false;
        }
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) || 'https' !== strtolower( $parts['scheme'] ) ) {
            return false;
        }

        $host = strtolower( $parts['host'] );

        // Metadata aliases are blocked by name, before any resolution.
        if ( in_array( $host, array( 'metadata', 'metadata.google.internal' ), true ) ) {
            return false;
        }

        // Candidate IPs: the literal host when it is an IP, otherwise every A
        // and AAAA record the name resolves to. A name that resolves to
        // nothing yields no IP and is treated as public (it cannot be reached
        // anyway). Resolving both families closes the IPv6-only bypass.
        $literal = trim( $host, '[]' );
        if ( false !== filter_var( $literal, FILTER_VALIDATE_IP ) ) {
            $ips = array( $literal );
        } else {
            $ips = static::resolve_host_ips( $host );
        }

        foreach ( $ips as $ip ) {
            if ( static::ip_is_metadata_or_link_local( $ip ) ) {
                return false;
            }
        }

        $allow_private = ( defined( 'MAINWP_BACKUP_ALLOW_PRIVATE_PULL' ) && MAINWP_BACKUP_ALLOW_PRIVATE_PULL )
            || (bool) get_option( 'mainwp_backup_allow_private_pull' );
        if ( $allow_private ) {
            return true;
        }

        foreach ( $ips as $ip ) {
            // NO_PRIV_RANGE + NO_RES_RANGE reject RFC1918, loopback,
            // link-local and other reserved blocks; a public IP survives.
            if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether an IP is a cloud-metadata / link-local address that no
     * override may reach: IPv4 169.254.0.0/16, IPv6 ::1 and fe80::/10.
     *
     * @param string $ip Validated IP literal.
     *
     * @return bool
     */
    protected static function ip_is_metadata_or_link_local( $ip ) {
        if ( false !== strpos( $ip, ':' ) ) {
            $packed = inet_pton( $ip );
            if ( false === $packed || 16 !== strlen( $packed ) ) {
                return false;
            }
            if ( inet_pton( '::1' ) === $packed ) {
                return true;
            }
            // fe80::/10 -> first 10 bits 1111111010.
            return 0xfe === ord( $packed[0] ) && 0x80 === ( ord( $packed[1] ) & 0xc0 );
        }
        return 0 === strpos( $ip, '169.254.' );
    }

    /**
     * Resolve a hostname to every IP it points at - both IPv4 (A) and IPv6
     * (AAAA). Resolving only IPv4 would let an IPv6-only private or metadata
     * host pass the guard as "public" while wp_remote_get still connects to
     * it. Returns an empty array when nothing resolves. Isolated so tests can
     * script resolution without real DNS.
     *
     * @param string $host Hostname.
     *
     * @return string[] Validated IP literals (may be empty).
     */
    protected static function resolve_host_ips( $host ) {
        $ips = array();

        $v4 = gethostbynamel( $host );
        if ( is_array( $v4 ) ) {
            foreach ( $v4 as $ip ) {
                if ( false !== filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    $ips[] = $ip;
                }
            }
        }

        if ( function_exists( 'dns_get_record' ) ) {
            $aaaa = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failed AAAA lookup is non-fatal; IPv4 still covers the host.
            if ( is_array( $aaaa ) ) {
                foreach ( $aaaa as $record ) {
                    if ( isset( $record['ipv6'] ) && false !== filter_var( $record['ipv6'], FILTER_VALIDATE_IP ) ) {
                        $ips[] = $record['ipv6'];
                    }
                }
            }
        }

        return array_values( array_unique( $ips ) );
    }

    /**
     * Cancel a restore task cleanly.
     *
     * The cancel respects the lease: while another runner is mid-slice
     * the task state is theirs, so the cancel reports busy and the
     * caller retries after the slice (or the lease TTL) ends. Once the
     * lease is held, the task gets the same treatment as a phase
     * failure - tmp tables dropped, maintenance lifted, scratch removed,
     * old aside tables kept as the rollback - and lands terminal failed
     * with the cancelled error code.
     *
     * @param string $task_id Task id.
     *
     * @return array { result: ok|busy|already_terminal, phase } or
     *               { error, error_code } when the task is unknown.
     */
    public function cancel_restore_task( $task_id ) {
        $spine = MainWP_Child_Backup_Task::instance();
        $spine->install();

        $task = $spine->get_task( $task_id );
        if ( null === $task || ! static::is_restore_type( $task->type ) ) {
            return array(
                'error'      => esc_html__( 'Restore task not found. It may have been cleaned up or was never created on this site.', 'mainwp-child' ),
                'error_code' => '',
            );
        }

        if ( MainWP_Child_Backup_Task::is_terminal_phase( $task->phase ) ) {
            return array(
                'result' => 'already_terminal',
                'phase'  => $task->phase,
            );
        }

        $version = (int) $task->version;
        if ( ! $spine->acquire_lease( $task_id, 'cancel', $version ) ) {
            return array(
                'result' => 'busy',
                'phase'  => $task->phase,
            );
        }
        ++$version;

        $backup_dir = $this->backup_dir();
        $sub_state  = $spine->read_sub_state( $task, $backup_dir );
        $sub_state  = is_array( $sub_state ) ? $sub_state : array();

        $this->terminal_cleanup( $task, $sub_state, $backup_dir );

        $new_version = $spine->checkpoint(
            $task_id,
            $version,
            array(
                'phase'     => MainWP_Child_Backup_Task::PHASE_FAILED,
                'sub_state' => array(
                    'error' => array(
                        'message' => MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_RESTORE_CANCELLED ),
                        'code'    => MainWP_Backup_Error::ERR_RESTORE_CANCELLED,
                    ),
                ),
            )
        );
        if ( false === $new_version ) {
            // Something else wrote the row between the acquire and the
            // checkpoint (the wall-clock enforcer is the only writer that
            // ignores leases). Report what the row actually holds instead
            // of claiming a cancel that never landed.
            $spine->release_lease( $task_id, 'cancel', $version );
            $fresh = $spine->get_task( $task_id );
            $phase = null !== $fresh ? $fresh->phase : '';
            if ( MainWP_Child_Backup_Task::is_terminal_phase( $phase ) ) {
                return array(
                    'result' => 'already_terminal',
                    'phase'  => $phase,
                );
            }
            return array(
                'result' => 'busy',
                'phase'  => $phase,
            );
        }

        $spine->delete_sidecar( $task_id, $backup_dir );
        $spine->release_lease( $task_id, 'cancel', $new_version );

        return array(
            'result' => 'ok',
            'phase'  => MainWP_Child_Backup_Task::PHASE_FAILED,
        );
    }

    /**
     * Receive one hash-verified piece of a restore artifact.
     *
     * The write path derives from the task id (scratch dir) plus the
     * artifact's basename, which must be in the list the task was
     * created with - nothing on the wire becomes a path. A duplicate of
     * a confirmed chunk is acknowledged without writing; a gap returns
     * the current size so the sender realigns; the final chunk verifies
     * the whole artifact's sha256 and discards the file on mismatch.
     *
     * @param string $task_id      Task id.
     * @param string $file         Artifact basename (pre-sanitized).
     * @param int    $offset       Byte offset of this chunk.
     * @param string $raw          Decoded chunk bytes.
     * @param string $chunk_sha256 Expected sha256 of the chunk bytes.
     * @param bool   $is_final     Whether this is the artifact's last chunk.
     *
     * @return array Ack { result, file, bytes_received, phase } or
     *               error { error, error_code, bytes_received? }.
     */
    public function receive_chunk( $task_id, $file, $offset, $raw, $chunk_sha256, $is_final ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity -- ordered integrity gates on the only write path from the wire.
        $spine = MainWP_Child_Backup_Task::instance();
        $spine->install();

        $task = $spine->get_task( $task_id );
        if ( null === $task || ! static::is_restore_type( $task->type ) ) {
            return $this->chunk_error( esc_html__( 'Restore task not found. It may have been cleaned up or was never created on this site.', 'mainwp-child' ), '' );
        }
        $chunk_phases = array(
            MainWP_Child_Backup_Task::PHASE_QUEUED,
            MainWP_Child_Backup_Task::PHASE_PULLING_ARTIFACTS,
            MainWP_Child_Backup_Task::PHASE_RECEIVING_ARTIFACTS,
        );
        if ( ! in_array( $task->phase, $chunk_phases, true ) ) {
            return $this->chunk_error( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_RESTORE_WRONG_PHASE ), MainWP_Backup_Error::ERR_RESTORE_WRONG_PHASE );
        }

        $backup_dir = $this->backup_dir();
        $sub_state  = $spine->read_sub_state( $task, $backup_dir );
        $sub_state  = is_array( $sub_state ) ? $sub_state : array();
        $artifacts  = isset( $sub_state['params']['artifacts'] ) ? (array) $sub_state['params']['artifacts'] : array();

        $expected = null;
        foreach ( $artifacts as $artifact ) {
            if ( isset( $artifact['file'] ) && $artifact['file'] === $file ) {
                $expected = $artifact;
                break;
            }
        }
        if ( null === $expected || ! empty( $expected['adopt_only'] ) ) {
            return $this->chunk_error( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_ARTIFACT_UNKNOWN ), MainWP_Backup_Error::ERR_ARTIFACT_UNKNOWN );
        }

        $scratch = $this->scratch_dir( $task->task_id, $backup_dir );
        if ( ! is_dir( $scratch ) ) {
            wp_mkdir_p( $scratch );
        }

        // Refuse a transfer the disk cannot hold before the first byte is
        // written. assert_pull_disk_space guards the cloud-pull path; the
        // chunk path otherwise streamed the whole archive into scratch with
        // no space check. Checked once at the start of the transfer:
        // record_chunk_progress creates sub_state['receiving'] on the first
        // write, so a started transfer skips this on every later chunk, and
        // a transfer that never started re-checks on retry.
        if ( empty( $sub_state['receiving'] ) && $this->receive_exceeds_disk( $artifacts, $scratch ) ) {
            return $this->chunk_error( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_DISK_FULL ), MainWP_Backup_Error::ERR_DISK_FULL );
        }

        $path = $scratch . $file;
        $size = file_exists( $path ) ? (int) filesize( $path ) : 0;

        if ( $offset < 0 || $offset > $size ) {
            return array(
                'error'          => MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_CHUNK_GAP ),
                'error_code'     => MainWP_Backup_Error::ERR_CHUNK_GAP,
                'bytes_received' => $size,
            );
        }

        if ( ! MainWP_Child_Chunk_Append_Helper::valid_chunk_hash( $raw, $chunk_sha256 ) ) {
            return $this->chunk_error( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_CHUNK_HASH ), MainWP_Backup_Error::ERR_CHUNK_HASH );
        }

        if ( $offset < $size ) {
            // Already-written bytes: a duplicate of a confirmed chunk.
            return $this->chunk_ack( $spine, $task, $file, $size );
        }

        $write_error = MainWP_Child_Chunk_Append_Helper::append_chunk( $path, $raw, $offset );
        if ( null !== $write_error ) {
            return $this->chunk_error( esc_html__( 'The artifact data could not be written. The site may be out of disk space.', 'mainwp-child' ), $write_error );
        }
        if ( '' !== $raw ) {
            // The append streamed through fopen/fwrite, which leaves the
            // pre-append size in PHP's stat cache on some PHP versions;
            // acking that stale count tells the sender no bytes landed.
            clearstatcache( true, $path );
            $size = (int) filesize( $path );
        }

        $done = false;
        if ( $is_final ) {
            $expected_hash = isset( $expected['sha256'] ) ? (string) $expected['sha256'] : '';
            if ( ! MainWP_Child_Chunk_Append_Helper::verify_final( $path, $expected_hash ) ) {
                wp_delete_file( $path );
                return $this->chunk_error( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_ARTIFACT_CORRUPT ), MainWP_Backup_Error::ERR_ARTIFACT_CORRUPT );
            }
            $done = true;
        }

        $this->record_chunk_progress( $spine, $task, $backup_dir, $file, $size, $done );
        return $this->chunk_ack( $spine, $task, $file, $size );
    }

    /**
     * Persist per-artifact receive progress onto the task cursor.
     *
     * Runs under CAS with a couple of retries - the runner may
     * checkpoint concurrently. Every successful write also refreshes
     * last_progress_at, which keeps the watchdog from stall-killing an
     * active transfer.
     *
     * @param MainWP_Child_Backup_Task $spine      Spine instance.
     * @param object                   $task       Task row (refreshed inside).
     * @param string                   $backup_dir Backup scratch root.
     * @param string                   $file       Artifact basename.
     * @param int                      $size       Bytes received so far.
     * @param bool                     $done       Whether the artifact verified complete.
     */
    protected function record_chunk_progress( $spine, $task, $backup_dir, $file, $size, $done ) {
        $path = $this->scratch_dir( $task->task_id, $backup_dir ) . $file;

        for ( $attempt = 0; $attempt < 3; $attempt++ ) {
            $fresh = $spine->get_task( $task->task_id );
            if ( null === $fresh ) {
                return;
            }
            $sub_state = $spine->read_sub_state( $fresh, $backup_dir );
            $sub_state = is_array( $sub_state ) ? $sub_state : array();
            if ( ! isset( $sub_state['receiving'] ) ) {
                $sub_state['receiving'] = array(
                    'adoption_done' => false,
                    'files'         => array(),
                    'hashes'        => array(),
                );
            }

            // Re-read the staging file so a write retried under CAS (or
            // racing a concurrent append) records the bytes actually on
            // disk instead of the size captured before the race.
            clearstatcache( true, $path );
            $bytes = file_exists( $path ) ? (int) filesize( $path ) : $size;

            $sub_state['receiving']['files'][ $file ] = array(
                'bytes' => $bytes,
                'done'  => $done,
            );

            $serialized = $spine->write_sub_state( $fresh->task_id, $sub_state, $backup_dir, (int) $fresh->version );
            if ( false === $serialized ) {
                $serialized = wp_json_encode( $sub_state );
            }
            if ( false !== $spine->checkpoint( $fresh->task_id, (int) $fresh->version, array( 'sub_state' => $serialized ) ) ) {
                return;
            }
        }
    }

    /**
     * Build a chunk acknowledgment with the task's current phase.
     *
     * @param MainWP_Child_Backup_Task $spine Spine instance.
     * @param object                   $task  Task row.
     * @param string                   $file  Artifact basename.
     * @param int                      $size  Bytes received.
     *
     * @return array Ack payload.
     */
    protected function chunk_ack( $spine, $task, $file, $size ) {
        $fresh = $spine->get_task( $task->task_id );
        return array(
            'result'         => 'ok',
            'file'           => $file,
            'bytes_received' => $size,
            'phase'          => null !== $fresh ? $fresh->phase : $task->phase,
        );
    }

    /**
     * Build a chunk error payload.
     *
     * @param string $message Error message.
     * @param string $code    Error code.
     *
     * @return array Error payload.
     */
    protected function chunk_error( $message, $code ) {
        return array(
            'error'      => $message,
            'error_code' => $code,
        );
    }

    /**
     * The per-task scratch directory all artifact paths derive from.
     *
     * Nothing about this path comes from the wire beyond the task id the
     * child itself generated.
     *
     * @param string $task_id    Task id.
     * @param string $backup_dir Backup scratch root.
     *
     * @return string Scratch directory with trailing slash.
     */
    public function scratch_dir( $task_id, $backup_dir ) {
        return rtrim( $backup_dir, '/' ) . '/restore-' . MainWP_Helper::sanitize_filename( $task_id ) . '/';
    }

    /**
     * Dispatch one restore phase step.
     *
     * Any throwable triggers terminal cleanup (tmp tables, maintenance
     * mode, scratch dir - old tables stay) before the spine's failure
     * trap records the terminal phase.
     *
     * @param object $task       Fresh task row.
     * @param int    $version    Version this runner owns.
     * @param array  $sub_state  Resolved resume cursor.
     * @param int    $deadline   Slice deadline (unix time).
     * @param string $backup_dir Scratch directory.
     *
     * @throws MainWP_Exception On an unknown phase.
     * @throws \Throwable Re-thrown phase failures after cleanup.
     *
     * @return array { version: int, yield: bool, abandon: bool }
     */
    protected function run_phase( $task, $version, $sub_state, $deadline, $backup_dir ) {
        try {
            switch ( $task->phase ) {
                case MainWP_Child_Backup_Task::PHASE_QUEUED:
                    $this->preflight_restore( $task, $backup_dir );
                    return $this->advance_phase( $task, $version, $sub_state, $backup_dir );
                case MainWP_Child_Backup_Task::PHASE_PULLING_ARTIFACTS:
                    return $this->run_pulling( $task, $version, $sub_state, $deadline, $backup_dir );
                case MainWP_Child_Backup_Task::PHASE_RECEIVING_ARTIFACTS:
                    return $this->run_receiving( $task, $version, $sub_state, $backup_dir );
                case MainWP_Child_Backup_Task::PHASE_VERIFYING_ARTIFACTS:
                    return $this->run_verifying( $task, $version, $sub_state, $backup_dir );
                case MainWP_Child_Backup_Task::PHASE_RESTORING_FILES:
                    return $this->run_restoring_files( $task, $version, $sub_state, $deadline, $backup_dir );
                case MainWP_Child_Backup_Task::PHASE_SNAPSHOTTING_DB:
                    return $this->run_snapshotting_db( $task, $version, $sub_state, $deadline, $backup_dir );
                case MainWP_Child_Backup_Task::PHASE_REPLAYING_DB:
                    return $this->run_replaying_db( $task, $version, $sub_state, $deadline, $backup_dir );
                case MainWP_Child_Backup_Task::PHASE_PRESERVING_WOO:
                    return $this->run_preserving_woo( $task, $version, $sub_state, $deadline, $backup_dir );
                case MainWP_Child_Backup_Task::PHASE_MERGING_WOO:
                    return $this->run_merging_woo( $task, $version, $sub_state, $deadline, $backup_dir );
                case MainWP_Child_Backup_Task::PHASE_FK_ROLLBACK:
                    return $this->run_fk_rollback( $task, $version, $sub_state, $deadline, $backup_dir );
                case MainWP_Child_Backup_Task::PHASE_SWAPPING_DB:
                    return $this->run_swapping_db( $task, $version, $sub_state, $backup_dir );
                case MainWP_Child_Backup_Task::PHASE_REWRITING_URLS:
                    return $this->run_rewriting_urls( $task, $version, $sub_state, $deadline, $backup_dir );
                case MainWP_Child_Backup_Task::PHASE_POST_RESTORE_VERIFY:
                    return $this->run_post_restore_verify( $task, $version, $sub_state, $backup_dir );
                default:
                    throw new MainWP_Exception( 'Unknown restore phase: ' . esc_html( $task->phase ), null, '' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped inline.
            }
        } catch ( \Throwable $e ) { // phpcs:ignore -- NOSONAR - cleanup must run on any phase failure.
            $this->terminal_cleanup( $task, $sub_state, $backup_dir );
            throw $e;
        }
    }

    /**
     * Restore preflight: fail fast on the cheap, high-value checks before any
     * file or database change. A non-writable scratch target, or a database
     * user missing the table-swap grants, is surfaced here with a stable code
     * instead of after the transfer work is already spent. The dashboard
     * confirms remote-artifact availability; the disk check rides the pull
     * phase.
     *
     * @param object $task       Task row.
     * @param string $backup_dir Scratch root.
     *
     * @throws MainWP_Exception When a target is not writable or a grant is missing.
     *
     * @return void
     */
    protected function preflight_restore( $task, $backup_dir ) {
        $scratch = $this->scratch_dir( $task->task_id, $backup_dir );
        if ( ! is_dir( $scratch ) ) {
            wp_mkdir_p( $scratch );
        }
        if ( ! is_dir( $scratch ) || ! is_writable( $scratch ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- a cheap readiness probe; WP_Filesystem is not initialized this early in a restore slice.
            throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_DIR_NOT_WRITABLE ) ), null, MainWP_Backup_Error::ERR_DIR_NOT_WRITABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        }

        // A database-bearing restore swaps tables with CREATE/RENAME/DROP;
        // probe those grants so a missing one stops the restore here, not
        // mid-swap. A files-only restore never touches the database.
        if ( 'restore_files' !== $task->type && ! $this->restore_engine()->probe_swap_grants() ) {
            throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_RESTORE_DB_PERMS ) ), null, MainWP_Backup_Error::ERR_RESTORE_DB_PERMS ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        }
    }

    /**
     * Pulling phase: download artifacts straight from cloud storage
     * using the presigned URLs in the task's pull manifest. When the
     * dashboard supplied no manifest, the phase is a transparent no-op
     * and the task advances straight to receiving, keeping the staged
     * chunk transfer path exactly as it was.
     *
     * Every pulled file verifies against the sha256 the task was
     * created with; manifest basenames must belong to the task's own
     * artifact list, so nothing in the manifest can name a new write
     * path. Failures land terminal with the cloud-pull error code -
     * the dashboard reacts by re-staging through the standard transfer.
     * Presigned URLs never appear in errors or in anything logged.
     *
     * @param object $task       Task row.
     * @param int    $version    Owned version.
     * @param array  $sub_state  Cursor.
     * @param int    $deadline   Slice deadline.
     * @param string $backup_dir Scratch root.
     *
     * @throws MainWP_Exception On insufficient disk space or any pull failure.
     *
     * @return array Slice result.
     */
    protected function run_pulling( $task, $version, $sub_state, $deadline, $backup_dir ) {
        $params = isset( $sub_state['params'] ) ? $sub_state['params'] : array();
        $pull   = isset( $params['pull_manifest'] ) && is_array( $params['pull_manifest'] ) ? $params['pull_manifest'] : array();
        $list   = isset( $pull['artifacts'] ) && is_array( $pull['artifacts'] ) ? array_values( $pull['artifacts'] ) : array();

        if ( empty( $list ) ) {
            return $this->advance_phase( $task, $version, $sub_state, $backup_dir );
        }

        $allowed = array();
        foreach ( isset( $params['artifacts'] ) ? (array) $params['artifacts'] : array() as $artifact ) {
            if ( empty( $artifact['adopt_only'] ) && isset( $artifact['file'] ) ) {
                $allowed[ (string) $artifact['file'] ] = true;
            }
        }

        $scratch = $this->scratch_dir( $task->task_id, $backup_dir );
        if ( ! is_dir( $scratch ) ) {
            wp_mkdir_p( $scratch );
        }

        $pulling = isset( $sub_state['pulling'] ) && is_array( $sub_state['pulling'] ) ? $sub_state['pulling'] : array( 'next' => 0 );

        if ( empty( $pulling['disk_checked'] ) ) {
            $this->assert_pull_disk_space( $list, (int) $pulling['next'], $scratch );
            $pulling['disk_checked'] = true;
        }

        $count    = count( $list );
        $progress = false;
        while ( $pulling['next'] < $count ) {
            if ( $progress && time() >= $deadline ) {
                $sub_state['pulling'] = $pulling;
                return $this->checkpoint_sub_state( $task, $version, $sub_state, $backup_dir, true );
            }

            $artifact = (array) $list[ $pulling['next'] ];
            $base     = isset( $artifact['file'] ) ? (string) $artifact['file'] : '';
            if ( '' === $base || empty( $allowed[ $base ] ) ) {
                throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_CLOUD_PULL_FAILED ) ), null, MainWP_Backup_Error::ERR_CLOUD_PULL_FAILED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
            }

            if ( ! empty( $artifact['chunks'] ) ) {
                if ( ! $this->pull_chunked_artifact( $artifact, $scratch, $pulling, $deadline ) ) {
                    $sub_state['pulling'] = $pulling;
                    return $this->checkpoint_sub_state( $task, $version, $sub_state, $backup_dir, true );
                }
            } else {
                $this->pull_whole_artifact( $artifact, $scratch );
            }

            $sub_state = $this->mark_artifact_pulled( $sub_state, $scratch, $base );
            ++$pulling['next'];
            unset( $pulling['chunk'] );
            $progress = true;
        }

        $sub_state['pulling'] = $pulling;
        return $this->advance_phase( $task, $version, $sub_state, $backup_dir );
    }

    /**
     * Download one whole artifact and verify it before promoting it
     * into the scratch staging area.
     *
     * @param array  $artifact Pull manifest entry: file, sha256, url.
     * @param string $scratch  Scratch dir with trailing slash.
     *
     * @throws MainWP_Exception When the download, hash, or promote fails.
     */
    protected function pull_whole_artifact( $artifact, $scratch ) {
        $base = (string) $artifact['file'];
        $path = $scratch . $base;
        $tmp  = $path . '.pulling';
        $url  = isset( $artifact['url'] ) ? (string) $artifact['url'] : '';

        if ( ! $this->download_url_to_file( $url, $tmp ) ) {
            if ( file_exists( $tmp ) ) {
                wp_delete_file( $tmp );
            }
            throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_CLOUD_PULL_FAILED ) ), null, MainWP_Backup_Error::ERR_CLOUD_PULL_FAILED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        }

        $expected = isset( $artifact['sha256'] ) ? (string) $artifact['sha256'] : '';
        $actual   = hash_file( 'sha256', $tmp );
        if ( '' === $expected || ! is_string( $actual ) || ! hash_equals( $expected, $actual ) ) {
            wp_delete_file( $tmp );
            throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_CLOUD_PULL_FAILED ) ), null, MainWP_Backup_Error::ERR_CLOUD_PULL_FAILED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        }

        if ( ! rename( $tmp, $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- atomic promote of the pulled artifact.
            wp_delete_file( $tmp );
            throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_CLOUD_PULL_FAILED ) ), null, MainWP_Backup_Error::ERR_CLOUD_PULL_FAILED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        }
    }

    /**
     * Reassemble one chunked artifact from per-chunk presigned URLs.
     *
     * Each piece verifies against its content hash before appending;
     * the finished file verifies against the artifact's sha256 (and
     * recorded size) before promoting. The chunk cursor lives on the
     * pulling state, so a slice that runs out of budget resumes at the
     * next chunk.
     *
     * @param array  $artifact Pull manifest entry: file, sha256, size, chunks.
     * @param string $scratch  Scratch dir with trailing slash.
     * @param array  $pulling  Pulling cursor (by reference).
     * @param int    $deadline Slice deadline.
     *
     * @throws MainWP_Exception When a download, hash, write, or promote fails.
     *
     * @return bool True when the artifact finished; false to yield mid-chunk.
     */
    protected function pull_chunked_artifact( $artifact, $scratch, &$pulling, $deadline ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity -- resumable verify-then-append loop.
        $base     = (string) $artifact['file'];
        $assembly = $scratch . $base . '.assembling';
        $chunks   = array_values( (array) $artifact['chunks'] );
        $cursor   = isset( $pulling['chunk'] ) && is_array( $pulling['chunk'] ) ? $pulling['chunk'] : array(
            'next'  => 0,
            'bytes' => 0,
        );

        // A stale assembly file that does not match the cursor restarts
        // clean - appending to unknown bytes would corrupt the artifact.
        $on_disk = file_exists( $assembly ) ? (int) filesize( $assembly ) : 0;
        if ( $on_disk !== (int) $cursor['bytes'] ) {
            wp_delete_file( $assembly );
            $cursor = array(
                'next'  => 0,
                'bytes' => 0,
            );
        }

        $count    = count( $chunks );
        $progress = false;
        while ( $cursor['next'] < $count ) {
            if ( $progress && time() >= $deadline ) {
                $pulling['chunk'] = $cursor;
                return false;
            }

            $chunk = (array) $chunks[ $cursor['next'] ];
            $piece = $scratch . $base . '.chunk';
            $url   = isset( $chunk['url'] ) ? (string) $chunk['url'] : '';

            if ( ! $this->download_url_to_file( $url, $piece ) ) {
                if ( file_exists( $piece ) ) {
                    wp_delete_file( $piece );
                }
                throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_CLOUD_PULL_FAILED ) ), null, MainWP_Backup_Error::ERR_CLOUD_PULL_FAILED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
            }

            $algo   = isset( $chunk['algo'] ) && in_array( (string) $chunk['algo'], hash_algos(), true ) ? (string) $chunk['algo'] : 'sha256';
            $actual = hash_file( $algo, $piece );
            if ( empty( $chunk['hash'] ) || ! is_string( $actual ) || ! hash_equals( (string) $chunk['hash'], $actual ) ) {
                wp_delete_file( $piece );
                wp_delete_file( $assembly );
                throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_CLOUD_PULL_FAILED ) ), null, MainWP_Backup_Error::ERR_CLOUD_PULL_FAILED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
            }

            $bytes = (string) file_get_contents( $piece ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- verified chunk bytes.
            wp_delete_file( $piece );

            $fh = fopen( $assembly, 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- streaming append to the assembly file.
            if ( false === $fh || strlen( $bytes ) !== fwrite( $fh, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- streaming append.
                if ( false !== $fh ) {
                    fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- paired with fopen above.
                }
                throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_DISK_FULL ) ), null, MainWP_Backup_Error::ERR_DISK_FULL ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
            }
            fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- paired with fopen above.

            $cursor['bytes'] += strlen( $bytes );
            ++$cursor['next'];
            $progress = true;
        }

        $expected_size = isset( $artifact['size'] ) ? (int) $artifact['size'] : 0;
        $expected_hash = isset( $artifact['sha256'] ) ? (string) $artifact['sha256'] : '';
        clearstatcache( true, $assembly );
        $whole = hash_file( 'sha256', $assembly );
        if ( ( $expected_size > 0 && (int) filesize( $assembly ) !== $expected_size )
            || '' === $expected_hash || ! is_string( $whole ) || ! hash_equals( $expected_hash, $whole )
            || ! rename( $assembly, $scratch . $base ) // phpcs:ignore WordPress.WP.AlternativeFunctions -- atomic promote of the reassembled artifact.
        ) {
            wp_delete_file( $assembly );
            throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_CLOUD_PULL_FAILED ) ), null, MainWP_Backup_Error::ERR_CLOUD_PULL_FAILED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        }

        unset( $pulling['chunk'] );
        return true;
    }

    /**
     * Record a pulled artifact on the receiving state, so the receiving
     * phase finds it complete and advances without any wire transfer.
     *
     * @param array  $sub_state Cursor.
     * @param string $scratch   Scratch dir with trailing slash.
     * @param string $base      Artifact basename.
     *
     * @return array Updated cursor.
     */
    protected function mark_artifact_pulled( $sub_state, $scratch, $base ) {
        if ( ! isset( $sub_state['receiving'] ) || ! is_array( $sub_state['receiving'] ) ) {
            $sub_state['receiving'] = array(
                'adoption_done' => false,
                'files'         => array(),
                'hashes'        => array(),
            );
        }

        clearstatcache( true, $scratch . $base );
        $sub_state['receiving']['files'][ $base ] = array(
            'bytes' => (int) filesize( $scratch . $base ),
            'done'  => true,
        );

        return $sub_state;
    }

    /**
     * Fail before the first download when free disk space cannot hold
     * the artifacts still to pull (plus a working margin).
     *
     * @param array  $artifacts Pull manifest artifacts.
     * @param int    $next    Index of the next artifact to pull.
     * @param string $scratch Scratch dir.
     *
     * @throws MainWP_Exception When free space is insufficient.
     */
    protected function assert_pull_disk_space( $artifacts, $next, $scratch ) {
        $needed = 0;
        $count  = count( $artifacts );
        for ( $i = $next; $i < $count; $i++ ) {
            $needed += isset( $artifacts[ $i ]['size'] ) ? (int) $artifacts[ $i ]['size'] : 0;
        }

        $free = $this->free_disk_space( $scratch );
        if ( null !== $free && $free < $needed + 64 * 1024 * 1024 ) {
            throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_DISK_FULL ) ), null, MainWP_Backup_Error::ERR_DISK_FULL ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        }
    }

    /**
     * Whether the artifacts still to receive cannot fit in free disk plus a
     * working margin. The chunk-transfer path writes every artifact into
     * scratch before applying, so this mirrors assert_pull_disk_space for
     * the chunk path, which otherwise had no space check. Unknowable free
     * space skips the guard rather than refusing the transfer.
     *
     * @param array  $artifacts Task artifact list.
     * @param string $scratch   Scratch dir the chunks land in.
     *
     * @return bool True when the transfer would not fit.
     */
    protected function receive_exceeds_disk( $artifacts, $scratch ) {
        $needed = 0;
        foreach ( $artifacts as $artifact ) {
            if ( ! empty( $artifact['adopt_only'] ) ) {
                continue;
            }
            $needed += isset( $artifact['size'] ) ? (int) $artifact['size'] : 0;
        }
        if ( $needed <= 0 ) {
            return false;
        }

        $free = $this->free_disk_space( $scratch );
        return null !== $free && $free < $needed + 64 * 1024 * 1024;
    }

    /**
     * Free disk space for a directory, or null when unknowable.
     *
     * @param string $dir Directory.
     *
     * @return float|null
     */
    protected function free_disk_space( $dir ) {
        $free = function_exists( 'disk_free_space' ) ? @disk_free_space( $dir ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- some hosts disable the call; unknown free space skips the check.
        return false === $free ? null : (float) $free;
    }

    /**
     * Extra wall-clock seconds the foreign-key path needs over the replay
     * alone, covering the aside snapshot and a possible rollback replay.
     *
     * @param int $bytes Estimated size of the tables being snapshotted.
     *
     * @return int Seconds to add to the task's deadline.
     */
    protected function fk_extra_budget_seconds( $bytes ) {
        // Two extra passes over the snapshotted bytes (snapshot + rollback) at
        // the same per-byte rate the create-time replay budget uses, plus a
        // base allowance for the fixed overhead of each pass.
        $per_pass = (int) ( (int) $bytes / 524288 );
        return self::RESTORE_MIN_DEADLINE_SECONDS + ( $per_pass * 2 );
    }

    /**
     * Sum the on-disk size of the named live tables from information_schema.
     *
     * @param string[] $tables Fully-qualified live table names.
     *
     * @return int Estimated bytes, or 0 when the size is unknowable.
     */
    protected function estimate_live_tables_bytes( $tables ) {
        global $wpdb;

        $tables = array_values( array_filter( array_map( 'strval', (array) $tables ) ) );
        if ( empty( $tables ) ) {
            return 0;
        }

        $placeholders = implode( ',', array_fill( 0, count( $tables ), '%s' ) );
        $bytes        = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- size estimate, no caching applicable.
            $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- table list expands the placeholders.
                'SELECT SUM(DATA_LENGTH + INDEX_LENGTH) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . $placeholders . ')', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- the %s placeholders are built above and bound below.
                $tables
            )
        );
        return null === $bytes ? 0 : (int) $bytes;
    }

    /**
     * Require enough free disk for the aside snapshot before it starts.
     *
     * Advisory hosts (disk_free_space disabled) pass: the during-snapshot
     * dumper failure still catches a full disk and reports the same code with
     * the live database untouched, so this is a faster, cleaner failure rather
     * than the only guard. The multiplier is filterable for cautious hosts and
     * defaults to 1.0 so the check only fires when the copy clearly cannot fit.
     *
     * @param int    $needed_bytes Estimated aside-copy size.
     * @param string $scratch      Directory the aside copy is written to.
     *
     * @throws MainWP_Exception When free space is below the estimate.
     */
    protected function preflight_snapshot_disk( $needed_bytes, $scratch ) {
        $needed_bytes = (int) $needed_bytes;
        if ( $needed_bytes <= 0 ) {
            return;
        }

        $free = $this->free_disk_space( $scratch );
        if ( null === $free ) {
            return;
        }

        $multiplier = (float) apply_filters( 'mainwp_restore_snapshot_disk_multiplier', 1.0 );
        $required   = (float) $needed_bytes * $multiplier;
        if ( (float) $free < $required ) {
            throw new MainWP_Exception(
                sprintf(
                    /* translators: 1: estimated space needed, 2: free space available */
                    esc_html__( 'Not enough free disk space to copy the database aside before the restore. Estimated need %1$s, available %2$s. Free up disk space on the child site and try again.', 'mainwp-child' ),
                    esc_html( size_format( $required ) ),
                    esc_html( size_format( $free ) )
                ),
                null,
                MainWP_Backup_Error::ERR_RESTORE_SNAPSHOT_FAILED // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message parts escaped above, code is a registry constant.
            );
        }
    }

    /**
     * Stream a presigned URL to a local file over HTTPS.
     *
     * The URL carries its own query authentication; nothing else is
     * sent. The response body streams to disk, so artifact bytes never
     * pass through memory. Redirects are refused - a presigned URL
     * never redirects, and following one would widen where these
     * requests can land.
     *
     * @param string $url       Presigned URL.
     * @param string $dest_path Local target path.
     *
     * @return bool Whether the download completed with HTTP 200.
     */
    protected function download_url_to_file( $url, $dest_path ) {
        $response = wp_remote_get(
            $url,
            array(
                'stream'      => true,
                'filename'    => $dest_path,
                'timeout'     => 120,
                'sslverify'   => true,
                'redirection' => 0,
            )
        );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        return 200 === (int) wp_remote_retrieve_response_code( $response );
    }

    /**
     * Receiving phase: adopt artifacts already on this site, then wait
     * for the wire transfer (driven by the chunk callable) to finish.
     *
     * @param object $task       Task row.
     * @param int    $version    Owned version.
     * @param array  $sub_state  Cursor.
     * @param string $backup_dir Scratch root.
     *
     * @throws MainWP_Exception When an adopt-only artifact cannot be found.
     *
     * @return array Slice result.
     */
    protected function run_receiving( $task, $version, $sub_state, $backup_dir ) {
        $params    = isset( $sub_state['params'] ) ? $sub_state['params'] : array();
        $artifacts = isset( $params['artifacts'] ) ? (array) $params['artifacts'] : array();
        $receiving = isset( $sub_state['receiving'] ) ? $sub_state['receiving'] : array(
            'adoption_done' => false,
            'files'         => array(),
            'hashes'        => array(),
        );

        if ( empty( $receiving['adoption_done'] ) ) {
            $receiving                  = $this->adopt_local_artifacts( $task, $artifacts, $receiving, $backup_dir );
            $receiving['adoption_done'] = true;
            $sub_state['receiving']     = $receiving;
        }

        foreach ( $artifacts as $artifact ) {
            $base = $artifact['file'];
            if ( ! empty( $artifact['adopt_only'] ) && empty( $receiving['files'][ $base ]['done'] ) ) {
                throw new MainWP_Exception( esc_html( 'Rollback artifact not found on this site: ' . $base ), null, MainWP_Backup_Error::ERR_DL_FILE_NOT_FOUND ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped inline.
            }
        }

        if ( $this->all_artifacts_received( $artifacts, $receiving ) ) {
            return $this->advance_phase( $task, $version, $sub_state, $backup_dir );
        }

        // Wire transfer still in flight; persist adoption state and yield.
        return $this->checkpoint_sub_state( $task, $version, $sub_state, $backup_dir, true );
    }

    /**
     * Verifying phase: every artifact's bytes match its expected hash.
     *
     * @param object $task       Task row.
     * @param int    $version    Owned version.
     * @param array  $sub_state  Cursor.
     * @param string $backup_dir Scratch root.
     *
     * @throws MainWP_Exception On a hash mismatch or a missing artifact.
     *
     * @return array Slice result.
     */
    protected function run_verifying( $task, $version, $sub_state, $backup_dir ) {
        $params  = $sub_state['params'];
        $scratch = $this->scratch_dir( $task->task_id, $backup_dir );
        $hashes  = $this->expected_hashes( $sub_state );

        foreach ( (array) $params['artifacts'] as $artifact ) {
            $base = $artifact['file'];
            $path = $scratch . $base;
            if ( ! is_file( $path ) ) {
                throw new MainWP_Exception( esc_html( 'Restore artifact missing from the staging area: ' . $base ), null, MainWP_Backup_Error::ERR_FILE_CORRUPT ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped inline.
            }
            $expected = isset( $hashes[ $base ] ) ? $hashes[ $base ] : '';
            $actual   = hash_file( 'sha256', $path );
            if ( '' === $expected || ! is_string( $actual ) || ! hash_equals( $expected, $actual ) ) {
                throw new MainWP_Exception( esc_html( 'Restore artifact failed its integrity check: ' . $base ), null, MainWP_Backup_Error::ERR_FILE_CORRUPT ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped inline.
            }
        }

        return $this->advance_phase( $task, $version, $sub_state, $backup_dir );
    }

    /**
     * File phase: extract part archives over the site root and apply
     * tombstones, in the order the artifact list prescribes.
     *
     * @param object $task       Task row.
     * @param int    $version    Owned version.
     * @param array  $sub_state  Cursor.
     * @param int    $deadline   Slice deadline.
     * @param string $backup_dir Scratch root.
     *
     * @throws MainWP_Exception On an extraction or tombstone failure.
     *
     * @return array Slice result.
     */
    protected function run_restoring_files( $task, $version, $sub_state, $deadline, $backup_dir ) {
        $scratch = $this->scratch_dir( $task->task_id, $backup_dir );
        $hashes  = $this->expected_hashes( $sub_state );
        $ops     = array();
        foreach ( (array) $sub_state['params']['artifacts'] as $artifact ) {
            if ( in_array( $artifact['role'], array( 'full', 'part', 'tombstones' ), true ) ) {
                $ops[] = $artifact;
            }
        }

        $cursor = isset( $sub_state['file_restore'] ) ? $sub_state['file_restore'] : array(
            'op_index'    => 0,
            'part_cursor' => array(),
        );

        $restorer = $this->file_restorer();
        $op_count = count( $ops );

        while ( $cursor['op_index'] < $op_count ) {
            if ( time() >= $deadline ) {
                $sub_state['file_restore'] = $cursor;
                return $this->checkpoint_sub_state( $task, $version, $sub_state, $backup_dir, true );
            }

            $op   = $ops[ $cursor['op_index'] ];
            $path = $scratch . $op['file'];

            if ( 'tombstones' === $op['role'] ) {
                $outcome = $restorer->apply_tombstones( $path, $this->site_root() );
                if ( null !== $outcome['error'] ) {
                    throw new MainWP_Exception( esc_html( $outcome['error'] ), null, $outcome['error_code'] ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped inline.
                }
                ++$cursor['op_index'];
                $cursor['part_cursor'] = array();
                continue;
            }

            $outcome = $restorer->restore_slice(
                $cursor['part_cursor'],
                array(
                    'parts'      => array( $path ),
                    'hashes'     => array( $op['file'] => isset( $hashes[ $op['file'] ] ) ? $hashes[ $op['file'] ] : '' ),
                    'extract_to' => $this->site_root(),
                ),
                $deadline
            );
            if ( null !== $outcome['error'] ) {
                throw new MainWP_Exception( esc_html( $outcome['error'] ), null, $outcome['error_code'] ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped inline.
            }
            if ( ! $outcome['done'] ) {
                $cursor['part_cursor']     = $outcome['cursor'];
                $sub_state['file_restore'] = $cursor;
                return $this->checkpoint_sub_state( $task, $version, $sub_state, $backup_dir, true );
            }

            ++$cursor['op_index'];
            $cursor['part_cursor'] = array();
        }

        $sub_state['file_restore'] = $cursor;
        return $this->advance_phase( $task, $version, $sub_state, $backup_dir );
    }

    /**
     * DB phase: extract the dump archive, decide the path (atomic swap
     * vs in-place under maintenance), and replay slices.
     *
     * @param object $task       Task row.
     * @param int    $version    Owned version.
     * @param array  $sub_state  Cursor.
     * @param int    $deadline   Slice deadline.
     * @param string $backup_dir Scratch root.
     *
     * @throws MainWP_Exception On extraction or a swap-path replay failure.
     * @throws \Throwable Re-thrown swap-path replay errors (the foreign-key path routes to rollback instead).
     *
     * @return array Slice result.
     */
    protected function run_replaying_db( $task, $version, $sub_state, $deadline, $backup_dir ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity -- staged extract/route/replay flow.
        $params  = $sub_state['params'];
        $scratch = $this->scratch_dir( $task->task_id, $backup_dir );
        $engine  = $this->restore_engine();

        if ( ! isset( $sub_state['db_replay'] ) ) {
            $prepared = $this->prepare_db_replay( $task, $sub_state, $scratch, $deadline );
            if ( null !== $prepared ) {
                // The dump archive extraction needs more budget; persist
                // its cursor and yield.
                return $this->checkpoint_sub_state( $task, $version, $sub_state, $backup_dir, true );
            }
        }

        $db = $sub_state['db_replay'];

        // WooCommerce order preservation runs once, before any replay touches
        // the live database: the gate confirms a supported store, maintenance
        // is entered, and the customers and stock the kept orders reference are
        // captured while the live tables are still current. The done flag keeps
        // the jump from re-firing when preserve_woo routes back here.
        if ( $this->should_preserve_woo( $sub_state ) && empty( $sub_state['woo_capture']['done'] ) ) {
            return $this->jump_phase( $task, $version, $sub_state, $backup_dir, MainWP_Child_Backup_Task::PHASE_PRESERVING_WOO );
        }

        // The in-place foreign-key replay overwrites the live tables with no
        // staging, so a copy of those tables is taken aside first. The replay
        // only runs once the snapshot is complete; on failure it can be undone.
        if ( ! empty( $db['fk_path'] ) && empty( $db['snapshot_done'] ) ) {
            return $this->jump_phase( $task, $version, $sub_state, $backup_dir, MainWP_Child_Backup_Task::PHASE_SNAPSHOTTING_DB );
        }

        // A resume after a stall restarts the in-flight dump file; the
        // file's own leading DROP keeps that idempotent.
        $unclean = (int) $task->resume_count > (int) $db['seen_resumes'];
        if ( $unclean ) {
            $db['seen_resumes'] = (int) $task->resume_count;
        }

        $config = array(
            'restore_id'    => $params['restore_id'],
            'source_prefix' => $db['source_prefix'],
            'target_prefix' => $this->target_prefix(),
            'files'         => $db['files'],
            'fk_path'       => $db['fk_path'],
            // The running restore's own state lives in the task spine;
            // replaying the dump's copy would clobber it mid-flight. When
            // preserving orders, the live WooCommerce order tables are kept too.
            'skip_stems'    => array_merge( array( 'mainwp_child_backup_task' ), $this->build_woo_skip_stems( $sub_state ) ),
        );

        try {
            $outcome = $engine->replay_slice( $db['cursor'], $config, $deadline, $unclean );
        } catch ( \Throwable $e ) {
            // On the foreign-key path the live tables may already be half
            // overwritten, so any replay failure (returned or thrown) routes
            // to the rollback phase instead of a bare terminal failure.
            if ( ! empty( $db['fk_path'] ) ) {
                $code = ( $e instanceof MainWP_Exception ) ? $e->get_message_error_code() : MainWP_Backup_Error::ERR_RESTORE_DB_REPLAY;
                return $this->enter_fk_rollback( $task, $version, $sub_state, $backup_dir, $e->getMessage(), $code );
            }
            throw $e;
        }
        $db['cursor']           = $outcome['cursor'];
        $sub_state['db_replay'] = $db;

        if ( null !== $outcome['error'] ) {
            if ( ! empty( $db['fk_path'] ) ) {
                return $this->enter_fk_rollback( $task, $version, $sub_state, $backup_dir, $outcome['error'], $outcome['error_code'] );
            }
            throw new MainWP_Exception( esc_html( $outcome['error'] ), null, $outcome['error_code'] ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped inline.
        }

        if ( ! $outcome['done'] ) {
            return $this->checkpoint_sub_state( $task, $version, $sub_state, $backup_dir, true );
        }

        // Dump files are restore byproducts, never site content: the
        // embedded set just replayed, and any older litter a previous
        // full-archive restore deposited must not ride into the next
        // backup (a stale options dump archived beside the fresh one
        // can win extraction and silently roll the site back).
        $this->cleanup_dump_litter();

        if ( $db['fk_path'] ) {
            // In-place replay finished: leave maintenance mode and skip
            // the swap phase entirely (the live tables already hold the
            // restored data).
            $engine->exit_maintenance();
            return $this->jump_phase( $task, $version, $sub_state, $backup_dir, MainWP_Child_Backup_Task::PHASE_REWRITING_URLS );
        }

        return $this->advance_phase( $task, $version, $sub_state, $backup_dir );
    }

    /**
     * Snapshot phase (foreign-key path only): copy the live tables the
     * in-place replay will overwrite to aside dump files under the scratch
     * dir, so a failed replay can be rolled back. Maintenance mode is entered
     * here and carried through the replay that follows. Resumable: the dumper
     * cursor and the file list are checkpointed across slices.
     *
     * @param object    $task       Task row.
     * @param int       $version    Owned version.
     * @param array     $sub_state  Cursor.
     * @param float|int $deadline   Slice deadline.
     * @param string    $backup_dir Scratch root.
     *
     * @throws MainWP_Exception When the aside snapshot cannot be written.
     *
     * @return array Slice result.
     */
    protected function run_snapshotting_db( $task, $version, $sub_state, $deadline, $backup_dir ) {
        $engine  = $this->restore_engine();
        $scratch = $this->scratch_dir( $task->task_id, $backup_dir );
        $db      = $sub_state['db_replay'];

        if ( ! isset( $sub_state['fk_snapshot'] ) ) {
            $split = $engine->list_dump_live_tables( $db['files'], $db['source_prefix'], $this->target_prefix(), array_merge( array( 'mainwp_child_backup_task' ), $this->build_woo_skip_stems( $sub_state ) ) );

            // The aside copy needs room for the live tables it replaces, and the
            // snapshot + replay + rollback passes need more wall-clock than the
            // replay alone. Both are sized once, before maintenance starts, so a
            // disk shortfall fails with the site still online and the live
            // database untouched.
            if ( ! empty( $split['existing'] ) ) {
                $bytes = $this->estimate_live_tables_bytes( $split['existing'] );
                $this->preflight_snapshot_disk( $bytes, $scratch );
                MainWP_Child_Backup_Task::instance()->extend_wall_clock( $task->task_id, $this->fk_extra_budget_seconds( $bytes ) );
            }

            $sub_state['fk_snapshot'] = array(
                'existing'  => $split['existing'],
                'new_stems' => $split['new_stems'],
                'cursor'    => array(),
                'files'     => array(),
            );
        }

        // The live tables are read while the site is offline so the aside copy
        // is consistent; the replay re-enters maintenance, which is idempotent.
        $engine->begin_maintenance();
        $fk = $sub_state['fk_snapshot'];

        // Nothing live to copy (a fresh-install target): no aside is needed,
        // the rollback would just drop whatever the replay creates.
        if ( empty( $fk['existing'] ) ) {
            $db['snapshot_done']    = true;
            $db['aside_files']      = array();
            $sub_state['db_replay'] = $db;
            return $this->jump_phase( $task, $version, $sub_state, $backup_dir, MainWP_Child_Backup_Task::PHASE_REPLAYING_DB );
        }

        $aside_dir = $scratch . 'aside/';
        if ( ! is_dir( $aside_dir ) ) {
            wp_mkdir_p( $aside_dir );
        }

        $snap                     = $engine->snapshot_tables_slice(
            $fk['cursor'],
            array(
                'tables'       => $fk['existing'],
                'aside_prefix' => $aside_dir . 'aside',
            ),
            $deadline
        );
        $fk['cursor']             = $snap['cursor'];
        $fk['files']              = $snap['files'];
        $sub_state['fk_snapshot'] = $fk;

        if ( null !== $snap['error'] ) {
            // The snapshot runs before any live table changes, so a failure
            // here leaves the database untouched.
            throw new MainWP_Exception( esc_html( $snap['error'] ), null, $snap['error_code'] ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped inline.
        }

        if ( ! $snap['done'] ) {
            return $this->checkpoint_sub_state( $task, $version, $sub_state, $backup_dir, true );
        }

        $db['snapshot_done']    = true;
        $db['aside_files']      = $fk['files'];
        $sub_state['db_replay'] = $db;
        return $this->jump_phase( $task, $version, $sub_state, $backup_dir, MainWP_Child_Backup_Task::PHASE_REPLAYING_DB );
    }

    /**
     * Enter the rollback phase, carrying the original replay error so the
     * terminal failure reports what went wrong.
     *
     * @param object $task       Task row.
     * @param int    $version    Owned version.
     * @param array  $sub_state  Cursor.
     * @param string $backup_dir Scratch root.
     * @param string $error      Original replay error message.
     * @param string $error_code Original replay error code.
     *
     * @return array Slice result.
     */
    protected function enter_fk_rollback( $task, $version, $sub_state, $backup_dir, $error, $error_code ) {
        $sub_state['fk_rollback'] = array(
            'original_error'      => (string) $error,
            'original_error_code' => (string) $error_code,
            'cursor'              => array(),
            'seen_resumes'        => (int) $task->resume_count,
        );
        return $this->jump_phase( $task, $version, $sub_state, $backup_dir, MainWP_Child_Backup_Task::PHASE_FK_ROLLBACK );
    }

    /**
     * Rollback phase (foreign-key path only): drop the half-restored live
     * tables and replay the aside snapshot back, returning the database to its
     * pre-restore state. Resumable. On success the task fails with a rolled-back
     * code (the original restore still did not apply); if the rollback itself
     * cannot finish, maintenance mode is deliberately kept on and the task
     * fails with a rollback-failed code so the site is not served broken.
     *
     * @param object    $task       Task row.
     * @param int       $version    Owned version.
     * @param array     $sub_state  Cursor.
     * @param float|int $deadline   Slice deadline.
     * @param string    $backup_dir Scratch root.
     *
     * @return array Slice result.
     */
    protected function run_fk_rollback( $task, $version, $sub_state, $deadline, $backup_dir ) {
        $engine        = $this->restore_engine();
        $target_prefix = $this->target_prefix();

        // Hold the site offline through the rollback. Entry from a replay
        // failure is already in maintenance; entry from a post-replay verify
        // failure is not, because the successful replay had exited it. The
        // call is idempotent and refreshes the flag for a long rollback.
        $engine->begin_maintenance();

        // Entry from enter_fk_rollback carries the original replay error. Entry
        // from a deadline-forced reroute does not, so the bookkeeping is
        // initialised cold with the wall-clock reason.
        $fkr = isset( $sub_state['fk_rollback'] ) ? $sub_state['fk_rollback'] : array(
            'original_error'      => MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_WALL_CLOCK ),
            'original_error_code' => MainWP_Backup_Error::ERR_WALL_CLOCK,
            'cursor'              => array(),
            'seen_resumes'        => (int) $task->resume_count,
        );
        $fk  = isset( $sub_state['fk_snapshot'] ) ? $sub_state['fk_snapshot'] : array(
            'existing'  => array(),
            'new_stems' => array(),
            'files'     => array(),
        );

        $drop_tables = isset( $fk['existing'] ) ? $fk['existing'] : array();
        foreach ( (array) ( isset( $fk['new_stems'] ) ? $fk['new_stems'] : array() ) as $stem ) {
            $drop_tables[] = $target_prefix . $stem;
        }
        $aside_files = isset( $sub_state['db_replay']['aside_files'] ) ? $sub_state['db_replay']['aside_files'] : ( isset( $fk['files'] ) ? $fk['files'] : array() );

        $unclean = (int) $task->resume_count > (int) $fkr['seen_resumes'];
        if ( $unclean ) {
            $fkr['seen_resumes'] = (int) $task->resume_count;
        }

        $rb                       = $engine->rollback_aside_slice(
            isset( $fkr['cursor'] ) ? $fkr['cursor'] : array(),
            array(
                'drop_tables'   => $drop_tables,
                'aside_files'   => $aside_files,
                'target_prefix' => $target_prefix,
                'restore_id'    => isset( $sub_state['params']['restore_id'] ) ? (string) $sub_state['params']['restore_id'] : '',
                'skip_stems'    => array_merge( array( 'mainwp_child_backup_task' ), $this->build_woo_skip_stems( $sub_state ) ),
            ),
            $deadline,
            $unclean
        );
        $fkr['cursor']            = $rb['cursor'];
        $sub_state['fk_rollback'] = $fkr;

        if ( null !== $rb['error'] ) {
            // Recovery could not finish: the database may be inconsistent, so
            // the site is left in maintenance mode on purpose.
            return $this->fail_terminal( $task, $version, $sub_state, $backup_dir, $rb['error'], MainWP_Backup_Error::ERR_RESTORE_ROLLBACK_FAILED );
        }

        if ( ! $rb['done'] ) {
            return $this->checkpoint_sub_state( $task, $version, $sub_state, $backup_dir, true );
        }

        // The live database is back to its pre-restore state.
        $engine->exit_maintenance();
        $this->remove_scratch_dir( $task, $backup_dir );
        return $this->fail_terminal( $task, $version, $sub_state, $backup_dir, $fkr['original_error'], MainWP_Backup_Error::ERR_RESTORE_ROLLED_BACK );
    }

    /**
     * Checkpoint the task terminal-failed with an explicit error and code.
     *
     * @param object $task       Task row.
     * @param int    $version    Owned version.
     * @param array  $sub_state  Cursor.
     * @param string $backup_dir Scratch root.
     * @param string $message    Error message.
     * @param string $code       Error code.
     *
     * @return array Slice result.
     */
    protected function fail_terminal( $task, $version, $sub_state, $backup_dir, $message, $code ) {
        $sub_state['error'] = array(
            'message' => (string) $message,
            'code'    => (string) $code,
        );
        return $this->jump_phase( $task, $version, $sub_state, $backup_dir, MainWP_Child_Backup_Task::PHASE_FAILED );
    }

    /**
     * Swap phase: one atomic RENAME promotes the loaded tmp tables.
     *
     * @param object $task       Task row.
     * @param int    $version    Owned version.
     * @param array  $sub_state  Cursor.
     * @param string $backup_dir Scratch root.
     *
     * @throws MainWP_Exception When the swap fails.
     *
     * @return array Slice result.
     */
    protected function run_swapping_db( $task, $version, $sub_state, $backup_dir ) {
        $engine = $this->restore_engine();
        $stems  = $this->loaded_stems( $sub_state );

        $outcome = $engine->swap_tables(
            $stems,
            array(
                'restore_id'    => $sub_state['params']['restore_id'],
                'target_prefix' => $this->target_prefix(),
            )
        );
        if ( ! $outcome['done'] ) {
            throw new MainWP_Exception( esc_html( $outcome['error'] ), null, $outcome['error_code'] ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped inline.
        }

        $sub_state['old_tables'] = $outcome['old_tables'];
        $this->flush_caches();

        return $this->advance_phase( $task, $version, $sub_state, $backup_dir );
    }

    /**
     * URL rewrite phase: serialization-aware replacement of the dump's
     * URLs (and table prefix, when it differs) in the live tables.
     *
     * @param object $task       Task row.
     * @param int    $version    Owned version.
     * @param array  $sub_state  Cursor.
     * @param int    $deadline   Slice deadline.
     * @param string $backup_dir Scratch root.
     *
     * @return array Slice result.
     */
    protected function run_rewriting_urls( $task, $version, $sub_state, $deadline, $backup_dir ) {
        $pairs = $this->url_pairs( $sub_state );
        if ( empty( $pairs ) ) {
            return $this->advance_or_merge_woo( $task, $version, $sub_state, $backup_dir );
        }

        $target_prefix = $this->target_prefix();
        $tables        = array();
        foreach ( $this->loaded_stems( $sub_state ) as $stem ) {
            $tables[] = $target_prefix . $stem;
        }

        $cursor  = isset( $sub_state['url_rewrite'] ) ? $sub_state['url_rewrite'] : array();
        $outcome = $this->url_rewriter()->rewrite_slice(
            $cursor,
            array(
                'tables'  => $tables,
                'pairs'   => $pairs,
                'exclude' => $this->guid_exclusions( $this->loaded_stems( $sub_state ), $target_prefix ),
            ),
            $deadline
        );

        $sub_state['url_rewrite'] = $outcome['cursor'];
        if ( ! empty( $outcome['failed_updates'] ) ) {
            // A failed write leaves that row's URLs un-rewritten. The
            // restored data is intact, so this is not fatal, but it must
            // not pass silently: carry the count into the verification
            // payload the dashboard surfaces.
            $prior                                   = isset( $sub_state['url_rewrite_failed_updates'] ) ? (int) $sub_state['url_rewrite_failed_updates'] : 0;
            $sub_state['url_rewrite_failed_updates'] = $prior + (int) $outcome['failed_updates'];
            $sub_state['url_rewrite_error']          = (string) $outcome['last_error'];
        }
        if ( ! $outcome['done'] ) {
            return $this->checkpoint_sub_state( $task, $version, $sub_state, $backup_dir, true );
        }

        $this->flush_caches();
        return $this->advance_or_merge_woo( $task, $version, $sub_state, $backup_dir );
    }

    /**
     * Whether this restore opted in to WooCommerce order preservation.
     *
     * @param array $sub_state Cursor.
     *
     * @return bool
     */
    protected function should_preserve_woo( $sub_state ) {
        return ! empty( $sub_state['params']['preserve_orders'] );
    }

    /**
     * Route the end of the URL-rewrite phase: into the WooCommerce merge phase
     * when preserving (once), otherwise straight on to verification. This is the
     * single entry to merge_woo, so both the swap path and the in-place
     * foreign-key path converge here after the database is live.
     *
     * @param object $task       Task row.
     * @param int    $version    Owned version.
     * @param array  $sub_state  Cursor.
     * @param string $backup_dir Scratch root.
     *
     * @return array Slice result.
     */
    protected function advance_or_merge_woo( $task, $version, $sub_state, $backup_dir ) {
        if ( $this->should_preserve_woo( $sub_state ) && empty( $sub_state['woo_merge']['done'] ) ) {
            return $this->jump_phase( $task, $version, $sub_state, $backup_dir, MainWP_Child_Backup_Task::PHASE_MERGING_WOO );
        }
        return $this->advance_phase( $task, $version, $sub_state, $backup_dir );
    }

    /**
     * WooCommerce preserve phase (jump-only): confirm the store and backup are a
     * supported configuration, then capture - while the live database is still
     * current - the customer accounts and product stock the kept orders
     * reference. The order tables themselves stay in place via
     * build_woo_skip_stems(); this phase handles only the customers and stock
     * that live in shared tables and would otherwise be reverted.
     *
     * The gate runs before any database change, so an unsupported store is left
     * exactly as it was. Capture reads the live tables directly and the site is
     * NOT held in maintenance mode: the restore is driven by the dashboard
     * polling the child, and WordPress maintenance mode returns a 503 to that
     * poll, which would break the restore. Any customer or stock change between
     * capture and the swap is surfaced by the post-restore orphan scan instead.
     *
     * @param object    $task       Task row.
     * @param int       $version    Owned version.
     * @param array     $sub_state  Cursor.
     * @param float|int $deadline   Slice deadline (capture is bounded; unused).
     * @param string    $backup_dir Scratch root.
     *
     * @return array Slice result.
     */
    protected function run_preserving_woo( $task, $version, $sub_state, $deadline, $backup_dir ) {
        $db   = $sub_state['db_replay'];
        $gate = $this->woo_preserve_gate( $db['files'], $db['source_prefix'] );
        if ( null !== $gate ) {
            return $this->fail_terminal( $task, $version, $sub_state, $backup_dir, MainWP_Backup_Error::message( $gate ), $gate );
        }

        $scratch = $this->scratch_dir( $task->task_id, $backup_dir );
        if ( ! is_dir( $scratch ) ) {
            wp_mkdir_p( $scratch );
        }
        $summary = $this->capture_woo_state( $sub_state, $scratch );

        $sub_state['woo_capture'] = array(
            'done'      => true,
            'file'      => $summary['file'],
            'customers' => $summary['customers'],
            'stock'     => $summary['stock'],
        );

        return $this->jump_phase( $task, $version, $sub_state, $backup_dir, MainWP_Child_Backup_Task::PHASE_REPLAYING_DB );
    }

    /**
     * WooCommerce merge phase (jump-only): re-insert the captured customer
     * accounts the restore reverted away, and re-apply the captured live product
     * stock. Runs after the database is live and after URL rewriting. Idempotent
     * and resumable: re-inserts use INSERT IGNORE and stock re-apply is an
     * UPDATE, so a re-run after a killed slice converges. The outcome counts are
     * recorded for the dashboard, never a silent partial success.
     *
     * @param object    $task       Task row.
     * @param int       $version    Owned version.
     * @param array     $sub_state  Cursor.
     * @param float|int $deadline   Slice deadline (merge is bounded; unused).
     * @param string    $backup_dir Scratch root.
     *
     * @return array Slice result.
     */
    protected function run_merging_woo( $task, $version, $sub_state, $deadline, $backup_dir ) {
        $scratch = $this->scratch_dir( $task->task_id, $backup_dir );
        $summary = $this->merge_woo_state( $sub_state, $scratch );

        $progress                 = isset( $sub_state['progress'] ) && is_array( $sub_state['progress'] ) ? $sub_state['progress'] : array();
        $progress['woo_preserve'] = $summary;
        $sub_state['progress']    = $progress;
        $sub_state['woo_merge']   = array( 'done' => true );

        $this->flush_caches();

        return $this->jump_phase( $task, $version, $sub_state, $backup_dir, MainWP_Child_Backup_Task::PHASE_POST_RESTORE_VERIFY );
    }

    /**
     * Confirm the live store and the backup are a supported configuration for
     * keeping live WooCommerce orders through a restore. Returns an error code
     * to abort with, or null when preservation is safe to proceed.
     *
     * Supported only when the live store uses High-Performance Order Storage as
     * the sole source of orders (custom orders table enabled, posts sync off),
     * the backup also used HPOS (its dump carries a wc_orders table), and the
     * backup's WooCommerce version matches the live version so the kept tables
     * and the reverted woocommerce_db_version agree.
     *
     * @param string[] $files         Dump file paths.
     * @param string   $source_prefix Prefix the dump's tables carry.
     *
     * @return string|null Error code to abort with, or null to proceed.
     */
    protected function woo_preserve_gate( $files, $source_prefix ) {
        // The backup must store orders in HPOS, or reverting wp_posts would
        // bring shop_order posts back alongside the kept live wc_orders.
        if ( null === $this->find_dump_file_for_table( $files, $source_prefix . 'wc_orders' ) ) {
            return MainWP_Backup_Error::ERR_RESTORE_WOO_LEGACY_ORDERS;
        }

        // The live store must read orders only from HPOS; on the legacy posts
        // storage the kept wc_orders are not authoritative.
        if ( 'yes' !== $this->woo_live_option( 'woocommerce_custom_orders_table_enabled' ) ) {
            return MainWP_Backup_Error::ERR_RESTORE_WOO_LEGACY_ORDERS;
        }

        // Sync mode keeps a shop_order mirror in wp_posts that the restore would
        // revert under the kept orders, leaving the two representations at odds.
        if ( 'yes' === $this->woo_live_option( 'woocommerce_custom_orders_table_data_sync_enabled' ) ) {
            return MainWP_Backup_Error::ERR_RESTORE_WOO_SYNC_MODE;
        }

        // A WooCommerce version change can migrate the order schema, leaving the
        // kept tables and the reverted woocommerce_db_version out of step. An
        // unreadable backup version fails closed.
        $backup_ver = $this->read_dump_option( $files, $source_prefix, 'woocommerce_db_version' );
        $live_ver   = $this->woo_live_option( 'woocommerce_db_version' );
        if ( '' === (string) $backup_ver || (string) $backup_ver !== (string) $live_ver ) {
            return MainWP_Backup_Error::ERR_RESTORE_WOO_VERSION_SKEW;
        }

        return null;
    }

    /**
     * Read one option value straight from the live options table by the target
     * prefix, bypassing the object cache (the restore is mid-flight, pre-swap).
     *
     * @param string $name Option name.
     *
     * @return string Option value, empty string when absent.
     */
    protected function woo_live_option( $name ) {
        global $wpdb;
        $table = $this->target_prefix() . 'options';
        $value = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM `' . $table . '` WHERE option_name = %s', $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- live read by prefix, cache bypassed.
        return null === $value ? '' : (string) $value;
    }

    /**
     * Read one option's value from the backup's options dump file without
     * replaying it - the gate needs the backup's woocommerce_db_version before
     * any replay runs. Returns an empty string when the option is not found.
     *
     * @param string[] $files         Dump file paths.
     * @param string   $source_prefix Prefix the dump's tables carry.
     * @param string   $name          Option name.
     *
     * @return string Option value, empty string when absent.
     */
    protected function read_dump_option( $files, $source_prefix, $name ) {
        $file = $this->find_dump_file_for_table( $files, $source_prefix . 'options' );
        if ( null === $file ) {
            return '';
        }
        $sql = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- bounded local dump read.
        if ( false === $sql ) {
            return '';
        }
        // The options dump writes (option_id, 'name', 'value', 'autoload')
        // tuples; the value is the quoted field right after the option name.
        if ( preg_match( '/' . preg_quote( "'" . $name . "'", '/' ) . "\\s*,\\s*'((?:[^'\\\\]|\\\\.)*)'/", $sql, $m ) ) {
            return stripslashes( $m[1] );
        }
        return '';
    }

    /**
     * The dump file whose single table matches a given name, or null.
     *
     * @param string[] $files Dump file paths.
     * @param string   $table Source-prefixed table name to find.
     *
     * @return string|null Matching file path, or null.
     */
    protected function find_dump_file_for_table( $files, $table ) {
        $engine = $this->restore_engine();
        foreach ( (array) $files as $file ) {
            if ( $table === $engine->peek_table_name( $file ) ) {
                return $file;
            }
        }
        return null;
    }

    /**
     * Capture, from the live database, the customer accounts and product stock
     * the kept orders reference, writing them to a scratch file the merge phase
     * reads after the swap. Customers are bounded to those a revert could drop
     * (registered since the backup, with a day of clock-skew margin); product
     * stock covers every managed-stock product. No row cap - a silent cap would
     * be the data loss this feature exists to prevent.
     *
     * @param array  $sub_state Cursor.
     * @param string $scratch   Per-task scratch directory (with trailing slash).
     *
     * @throws MainWP_Exception When the capture file cannot be written.
     *
     * @return array { file: string, customers: int, stock: int }
     */
    protected function capture_woo_state( $sub_state, $scratch ) {
        global $wpdb;
        $prefix = $this->target_prefix();
        $since  = (int) ( isset( $sub_state['params']['woo_order_since'] ) ? $sub_state['params']['woo_order_since'] : 0 );

        $orders_tb = $prefix . 'wc_orders';
        $users_tb  = $prefix . 'users';
        $meta_tb   = $prefix . 'usermeta';

        $where = '';
        if ( $since > 0 ) {
            $where = $wpdb->prepare( ' AND u.user_registered >= %s', gmdate( 'Y-m-d H:i:s', max( 0, $since - DAY_IN_SECONDS ) ) );
        }

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- raw reads by live prefix, pre-swap, into a scratch capture file.
        $users = $wpdb->get_results(
            'SELECT u.* FROM `' . $users_tb . '` u'
            . ' WHERE u.ID IN ( SELECT DISTINCT customer_id FROM `' . $orders_tb . '` WHERE customer_id > 0 )' . $where,
            ARRAY_A
        );
        $users = is_array( $users ) ? $users : array();

        $ids = array();
        foreach ( $users as $u ) {
            $ids[] = (int) $u['ID'];
        }
        $usermeta = array();
        if ( ! empty( $ids ) ) {
            $usermeta = $wpdb->get_results( 'SELECT * FROM `' . $meta_tb . '` WHERE user_id IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')', ARRAY_A );
            $usermeta = is_array( $usermeta ) ? $usermeta : array();
        }
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery

        $stock   = $this->capture_woo_stock( $prefix );
        $payload = array(
            'users'    => $users,
            'usermeta' => $usermeta,
            'stock'    => $stock,
        );

        // The merge phase reads this file after the swap; if the write or the
        // atomic rename fails, abort here (before any replay) rather than mark
        // the capture done and silently re-apply nothing later.
        $file    = $scratch . 'woo-preserve.json';
        $tmp     = $file . '.tmp';
        $written = file_put_contents( $tmp, wp_json_encode( $payload ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- atomic temp-then-rename.
        if ( false === $written || ! rename( $tmp, $file ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- atomic rename.
            if ( file_exists( $tmp ) ) {
                wp_delete_file( $tmp );
            }
            throw new MainWP_Exception( 'Could not write the WooCommerce order-preservation capture file.', null, MainWP_Backup_Error::ERR_RESTORE_SNAPSHOT_FAILED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message.
        }

        return array(
            'file'      => 'woo-preserve.json',
            'customers' => count( $users ),
            'stock'     => count( $stock ),
        );
    }

    /**
     * Snapshot the live stock of every managed-stock product: the _stock,
     * _stock_status, and _manage_stock postmeta, plus the product lookup row
     * when the lookup table exists.
     *
     * @param string $prefix Live table prefix.
     *
     * @return array<int,array> Stock rows keyed by index, each with product_id.
     */
    protected function capture_woo_stock( $prefix ) {
        global $wpdb;
        $postmeta = $prefix . 'postmeta';

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- raw reads by live prefix into a scratch file.
        $managed = $wpdb->get_col( 'SELECT post_id FROM `' . $postmeta . "` WHERE meta_key = '_manage_stock' AND meta_value = 'yes'" );
        $managed = is_array( $managed ) ? array_map( 'intval', $managed ) : array();
        if ( empty( $managed ) ) {
            return array();
        }
        $in   = implode( ',', $managed );
        $rows = $wpdb->get_results(
            'SELECT post_id, meta_key, meta_value FROM `' . $postmeta . '`'
            . ' WHERE post_id IN (' . $in . ") AND meta_key IN ('_stock','_stock_status','_manage_stock')",
            ARRAY_A
        );
        $rows = is_array( $rows ) ? $rows : array();

        $stock = array();
        foreach ( $rows as $r ) {
            $pid = (int) $r['post_id'];
            if ( ! isset( $stock[ $pid ] ) ) {
                $stock[ $pid ] = array( 'product_id' => $pid );
            }
            $stock[ $pid ][ $r['meta_key'] ] = $r['meta_value'];
        }

        $lookup = $prefix . 'wc_product_meta_lookup';
        if ( $this->table_exists( $lookup ) ) {
            $lrows = $wpdb->get_results( 'SELECT product_id, stock_quantity, stock_status FROM `' . $lookup . '` WHERE product_id IN (' . $in . ')', ARRAY_A );
            foreach ( (array) $lrows as $lr ) {
                $pid = (int) $lr['product_id'];
                if ( ! isset( $stock[ $pid ] ) ) {
                    $stock[ $pid ] = array( 'product_id' => $pid );
                }
                $stock[ $pid ]['lookup_quantity'] = $lr['stock_quantity'];
                $stock[ $pid ]['lookup_status']   = $lr['stock_status'];
            }
        }
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery

        return array_values( $stock );
    }

    /**
     * Apply the captured customers and stock to the live (restored) database:
     * re-insert each customer the restore reverted away, preserving the original
     * ID, and re-apply live stock for products that still exist. Returns the
     * outcome counts for the dashboard.
     *
     * @param array  $sub_state Cursor.
     * @param string $scratch   Per-task scratch directory (with trailing slash).
     *
     * @return array { customers_restored, customers_present, stock_applied, products_missing }
     */
    protected function merge_woo_state( $sub_state, $scratch ) {
        global $wpdb;
        $prefix  = $this->target_prefix();
        $summary = array(
            'customers_restored' => 0,
            'customers_present'  => 0,
            'stock_applied'      => 0,
            'products_missing'   => 0,
        );

        $file = $scratch . ( isset( $sub_state['woo_capture']['file'] ) ? basename( (string) $sub_state['woo_capture']['file'] ) : 'woo-preserve.json' );
        if ( ! is_file( $file ) ) {
            return $summary;
        }
        $payload = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- local scratch read.
        if ( ! is_array( $payload ) ) {
            return $summary;
        }

        $users_tb = $prefix . 'users';
        $meta_tb  = $prefix . 'usermeta';

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- raw writes by live prefix, post-swap, cache bypassed.
        foreach ( (array) ( isset( $payload['users'] ) ? $payload['users'] : array() ) as $u ) {
            $id     = (int) $u['ID'];
            $exists = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM `' . $users_tb . '` WHERE ID = %d', $id ) );
            if ( $exists > 0 ) {
                ++$summary['customers_present'];
                continue;
            }
            // A since-backup registrant holds an auto-increment ID above the
            // backup's max, so the reverted table cannot already hold it;
            // INSERT IGNORE is the belt-and-braces against a reused id.
            $wpdb->query(
                $wpdb->prepare(
                    'INSERT IGNORE INTO `' . $users_tb . '` (ID, user_login, user_pass, user_nicename, user_email, user_url, user_registered, user_activation_key, user_status, display_name)'
                    . ' VALUES (%d, %s, %s, %s, %s, %s, %s, %s, %d, %s)',
                    $id,
                    (string) $u['user_login'],
                    (string) $u['user_pass'],
                    (string) $u['user_nicename'],
                    (string) $u['user_email'],
                    (string) $u['user_url'],
                    (string) $u['user_registered'],
                    (string) $u['user_activation_key'],
                    (int) $u['user_status'],
                    (string) $u['display_name']
                )
            );
            ++$summary['customers_restored'];
        }

        foreach ( (array) ( isset( $payload['usermeta'] ) ? $payload['usermeta'] : array() ) as $m ) {
            $wpdb->query(
                $wpdb->prepare(
                    'INSERT IGNORE INTO `' . $meta_tb . '` (umeta_id, user_id, meta_key, meta_value) VALUES (%d, %d, %s, %s)',
                    (int) $m['umeta_id'],
                    (int) $m['user_id'],
                    (string) $m['meta_key'],
                    (string) $m['meta_value']
                )
            );
        }

        $postmeta   = $prefix . 'postmeta';
        $lookup     = $prefix . 'wc_product_meta_lookup';
        $has_lookup = $this->table_exists( $lookup );
        foreach ( (array) ( isset( $payload['stock'] ) ? $payload['stock'] : array() ) as $s ) {
            $pid    = (int) $s['product_id'];
            $exists = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM `' . $postmeta . '` WHERE post_id = %d', $pid ) );
            if ( 0 === $exists ) {
                ++$summary['products_missing'];
                continue;
            }
            // Upsert each meta row: a product unmanaged in the backup has no
            // _stock row to UPDATE, so re-applying live stock would silently do
            // nothing. Insert the row when it is absent.
            foreach ( array( '_stock', '_stock_status', '_manage_stock' ) as $key ) {
                if ( ! isset( $s[ $key ] ) ) {
                    continue;
                }
                $has_meta = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM `' . $postmeta . '` WHERE post_id = %d AND meta_key = %s', $pid, $key ) );
                if ( $has_meta > 0 ) {
                    $wpdb->query( $wpdb->prepare( 'UPDATE `' . $postmeta . '` SET meta_value = %s WHERE post_id = %d AND meta_key = %s', (string) $s[ $key ], $pid, $key ) );
                } else {
                    $wpdb->query( $wpdb->prepare( 'INSERT INTO `' . $postmeta . '` (post_id, meta_key, meta_value) VALUES (%d, %s, %s)', $pid, $key, (string) $s[ $key ] ) );
                }
            }
            if ( $has_lookup && isset( $s['lookup_status'] ) ) {
                $wpdb->query( $wpdb->prepare( 'UPDATE `' . $lookup . '` SET stock_quantity = %s, stock_status = %s WHERE product_id = %d', isset( $s['lookup_quantity'] ) ? (string) $s['lookup_quantity'] : '', (string) $s['lookup_status'], $pid ) );
            }
            ++$summary['stock_applied'];
        }
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery

        return $summary;
    }

    /**
     * Count kept orders whose customer the merge could not bring back. Should be
     * zero after a clean merge; a nonzero count is surfaced, not hidden.
     *
     * @return array { orphan_customer_orders: int }
     */
    protected function scan_woo_orphans() {
        global $wpdb;
        $prefix  = $this->target_prefix();
        $orders  = $prefix . 'wc_orders';
        $users   = $prefix . 'users';
        $sql     = 'SELECT COUNT(*) FROM `' . $orders . '` o LEFT JOIN `' . $users . '` u ON o.customer_id = u.ID WHERE o.customer_id > 0 AND u.ID IS NULL';
        $orphans = (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- table names from the live prefix.
        return array( 'orphan_customer_orders' => $orphans );
    }

    /**
     * Whether a fully-qualified table exists.
     *
     * @param string $table Table name with prefix.
     *
     * @return bool
     */
    protected function table_exists( $table ) {
        global $wpdb;
        $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- existence probe.
        return (string) $found === (string) $table;
    }

    /**
     * Verification phase: row counts, a sentinel write/read on the live
     * options table, and an advisory loopback. Only a pass retires the
     * old aside tables and the scratch directory.
     *
     * @param object $task       Task row.
     * @param int    $version    Owned version.
     * @param array  $sub_state  Cursor.
     * @param string $backup_dir Scratch root.
     *
     * @throws MainWP_Exception When verification fails (old tables kept).
     *
     * @return array Slice result.
     */
    protected function run_post_restore_verify( $task, $version, $sub_state, $backup_dir ) {
        $engine        = $this->restore_engine();
        $target_prefix = $this->target_prefix();

        $expected = array();
        if ( isset( $sub_state['db_replay']['cursor']['tables'] ) ) {
            foreach ( $sub_state['db_replay']['cursor']['tables'] as $stem => $info ) {
                if ( isset( $info['status'] ) && 'ok' === $info['status'] ) {
                    $expected[ $stem ] = (int) $info['rows'];
                }
            }
        }

        // A preserve restore re-inserts the kept customers into users and
        // usermeta after the swap, so those tables hold more rows than the
        // backup by design. Drop them from the strict row-count check; their
        // integrity is covered by the orphan scan, not a count match. Stock is
        // re-applied with UPDATE, so postmeta and the lookup keep their counts.
        if ( $this->should_preserve_woo( $sub_state ) ) {
            unset( $expected['users'], $expected['usermeta'] );
        }

        // On the foreign-key path the live tables were overwritten in place,
        // so a verification failure here is a wrong restore that must be undone
        // from the aside snapshot (still on disk until this phase passes), not
        // a bare failure that would discard it. The swap path keeps its old
        // tables aside, so it fails terminally as before.
        $is_fk = ! empty( $sub_state['db_replay']['fk_path'] );

        $counts = $engine->verify_row_counts( $expected, $target_prefix );
        if ( ! $counts['ok'] ) {
            $detail = 'Post-restore row counts do not match the replayed data. Mismatches: ' . wp_json_encode( $counts['mismatches'] );
            if ( $is_fk ) {
                return $this->enter_fk_rollback( $task, $version, $sub_state, $backup_dir, $detail, MainWP_Backup_Error::ERR_RESTORE_DB_REPLAY );
            }
            throw new MainWP_Exception( esc_html( $detail . ' The previous tables were kept for rollback.' ), null, MainWP_Backup_Error::ERR_RESTORE_DB_REPLAY ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped inline.
        }

        if ( ! $this->sentinel_round_trip( $target_prefix, $sub_state['params']['restore_id'] ) ) {
            if ( $is_fk ) {
                return $this->enter_fk_rollback( $task, $version, $sub_state, $backup_dir, 'The restored options table failed a write/read round trip.', MainWP_Backup_Error::ERR_RESTORE_DB_REPLAY );
            }
            throw new MainWP_Exception( 'The restored options table failed a write/read round trip. The previous tables were kept for rollback.', null, MainWP_Backup_Error::ERR_RESTORE_DB_REPLAY ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message.
        }

        $sub_state['verification'] = array(
            'row_counts_ok'              => true,
            'sentinel_ok'                => true,
            'loopback_status'            => $this->loopback_status(),
            'sub_site_probes'            => $this->sub_site_probes(),
            'url_rewrite_failed_updates' => isset( $sub_state['url_rewrite_failed_updates'] ) ? (int) $sub_state['url_rewrite_failed_updates'] : 0,
            'url_rewrite_error'          => isset( $sub_state['url_rewrite_error'] ) ? (string) $sub_state['url_rewrite_error'] : '',
        );

        // A preserve restore keeps the live orders in place; surface how many
        // still reference a customer the merge could not bring back so the
        // dashboard flags a shortfall rather than hiding it. Advisory only.
        if ( $this->should_preserve_woo( $sub_state ) ) {
            $sub_state['verification']['woo_orphans'] = $this->scan_woo_orphans();
        }

        // Verification passed: retire the rollback tables and staging.
        $engine->drop_old_tables( $sub_state['params']['restore_id'] );
        $this->remove_scratch_dir( $task, $backup_dir );

        return $this->advance_phase( $task, $version, $sub_state, $backup_dir );
    }

    /**
     * Adopt expected artifacts already present in the backup directory.
     *
     * An artifact whose hash matches the manifest is moved into the
     * scratch dir and marked received - this is how a rollback restore
     * (whose artifacts never left this site) runs with zero transfer.
     * Adopt-only artifacts trust the local bytes and record their hash
     * for the verification phase.
     *
     * @param object $task       Task row.
     * @param array  $artifacts  Expected artifact list.
     * @param array  $receiving  Receiving cursor.
     * @param string $backup_dir Backup scratch root.
     *
     * @return array Updated receiving cursor.
     */
    protected function adopt_local_artifacts( $task, $artifacts, $receiving, $backup_dir ) {
        $scratch = $this->scratch_dir( $task->task_id, $backup_dir );
        $root    = rtrim( $backup_dir, '/' ) . '/';

        foreach ( $artifacts as $artifact ) {
            $base = $artifact['file'];
            if ( ! empty( $receiving['files'][ $base ]['done'] ) ) {
                continue;
            }

            $candidate = $root . $base;
            if ( ! is_file( $candidate ) ) {
                continue;
            }

            $actual = hash_file( 'sha256', $candidate );
            if ( ! is_string( $actual ) ) {
                continue;
            }

            $expected = isset( $artifact['sha256'] ) ? (string) $artifact['sha256'] : '';
            if ( '' !== $expected && ! hash_equals( $expected, $actual ) ) {
                continue; // Same name, different bytes - the wire copy wins.
            }

            if ( ! copy( $candidate, $scratch . $base ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- staging a local artifact copy.
                continue;
            }

            $receiving['files'][ $base ]  = array(
                'bytes' => (int) filesize( $scratch . $base ),
                'done'  => true,
            );
            $receiving['hashes'][ $base ] = $actual;
        }

        return $receiving;
    }

    /**
     * Whether every expected artifact is received (wire or adoption).
     *
     * @param array $artifacts Expected artifact list.
     * @param array $receiving Receiving cursor.
     *
     * @return bool True when all are in.
     */
    public function all_artifacts_received( $artifacts, $receiving ) {
        foreach ( $artifacts as $artifact ) {
            if ( empty( $receiving['files'][ $artifact['file'] ]['done'] ) ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Consolidated expected hashes: the manifest's, overlaid with hashes
     * recorded at adoption (covers adopt-only artifacts).
     *
     * @param array $sub_state Cursor.
     *
     * @return array<string,string> sha256 by basename.
     */
    protected function expected_hashes( $sub_state ) {
        $hashes = array();
        foreach ( (array) $sub_state['params']['artifacts'] as $artifact ) {
            if ( ! empty( $artifact['sha256'] ) ) {
                $hashes[ $artifact['file'] ] = (string) $artifact['sha256'];
            }
        }
        if ( isset( $sub_state['receiving']['hashes'] ) ) {
            foreach ( (array) $sub_state['receiving']['hashes'] as $base => $hash ) {
                if ( empty( $hashes[ $base ] ) ) {
                    $hashes[ $base ] = (string) $hash;
                }
            }
        }
        return $hashes;
    }

    /**
     * Extract the dump archive and resolve the replay route.
     *
     * Returns null when preparation finished and sub_state['db_replay']
     * is initialized; returns a truthy marker when extraction must
     * yield (its cursor is stored on the sub_state for the caller to
     * checkpoint).
     *
     * @param object $task      Task row.
     * @param array  $sub_state Cursor (by reference).
     * @param string $scratch   Scratch dir.
     * @param int    $deadline  Slice deadline.
     *
     * @throws MainWP_Exception When the dump archive is missing or bad.
     *
     * @return true|null True to yield with the extraction cursor, null
     *                   when the replay route is ready.
     */
    protected function prepare_db_replay( $task, &$sub_state, $scratch, $deadline ) {
        $params      = $sub_state['params'];
        $db_artifact = null;
        foreach ( (array) $params['artifacts'] as $artifact ) {
            if ( 'db' === $artifact['role'] ) {
                $db_artifact = $artifact; // The dashboard sends exactly one: the chain-newest dump.
            }
        }
        if ( null === $db_artifact ) {
            // A plain full backup embeds its dump inside the site archive;
            // the file phase already extracted those .sql.php files. Replay
            // them in place and remove them once the replay is done.
            $embedded = $this->find_embedded_dump_files();
            if ( empty( $embedded ) ) {
                throw new MainWP_Exception( 'No database artifact was provided for this restore.', null, MainWP_Backup_Error::ERR_RESTORE_DB_REPLAY ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message.
            }
            $sub_state['db_replay'] = $this->build_db_replay_state( $task, $embedded, $params, true );
            return null;
        }

        $hashes = $this->expected_hashes( $sub_state );
        $db_dir = $scratch . 'db/';
        if ( ! is_dir( $db_dir ) ) {
            wp_mkdir_p( $db_dir );
        }

        $cursor  = isset( $sub_state['db_extract'] ) ? $sub_state['db_extract'] : array();
        $outcome = $this->file_restorer()->restore_slice(
            $cursor,
            array(
                'parts'      => array( $scratch . $db_artifact['file'] ),
                'hashes'     => array( $db_artifact['file'] => isset( $hashes[ $db_artifact['file'] ] ) ? $hashes[ $db_artifact['file'] ] : '' ),
                'extract_to' => $db_dir,
            ),
            $deadline
        );
        if ( null !== $outcome['error'] ) {
            throw new MainWP_Exception( esc_html( $outcome['error'] ), null, $outcome['error_code'] ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped inline.
        }
        if ( ! $outcome['done'] ) {
            $sub_state['db_extract'] = $outcome['cursor'];
            return true;
        }

        $files = glob( $db_dir . '*.sql.php' );
        if ( empty( $files ) ) {
            throw new MainWP_Exception( 'The database archive contained no table dump files.', null, MainWP_Backup_Error::ERR_RESTORE_DB_REPLAY ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message.
        }

        $sub_state['db_replay'] = $this->build_db_replay_state( $task, $files, $params, false );

        return null;
    }

    /**
     * Build the replay route state for a set of dump files.
     *
     * @param object   $task     Task row.
     * @param string[] $files    Per-table .sql.php files.
     * @param array    $params   Restore params.
     * @param bool     $embedded Whether the files were embedded in the
     *                           site archive (removed after replay).
     *
     * @throws MainWP_Exception When no table prefix can be determined.
     *
     * @return array db_replay state.
     */
    protected function build_db_replay_state( $task, $files, $params, $embedded ) {
        sort( $files );

        $engine = $this->restore_engine();
        $banner = $engine->read_banner( $files[0] );

        $source_prefix = '';
        if ( ! empty( $params['source_prefix'] ) ) {
            $source_prefix = (string) $params['source_prefix'];
        } elseif ( isset( $banner['info']['table_prefix'] ) ) {
            $source_prefix = (string) $banner['info']['table_prefix'];
        }
        if ( '' === $source_prefix ) {
            // Older embedded dumps carry no banner; the live prefix is the
            // only sane default for a same-site restore.
            $source_prefix = $embedded ? $this->target_prefix() : '';
        }
        if ( '' === $source_prefix ) {
            throw new MainWP_Exception( 'The dump does not record its table prefix and none was provided.', null, MainWP_Backup_Error::ERR_RESTORE_DB_REPLAY ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message.
        }

        $fk = null;
        if ( isset( $params['has_foreign_keys'] ) && null !== $params['has_foreign_keys'] && '' !== $params['has_foreign_keys'] ) {
            $fk = (bool) $params['has_foreign_keys'];
        } elseif ( isset( $banner['meta']['has_foreign_keys'] ) && null !== $banner['meta']['has_foreign_keys'] ) {
            $fk = (bool) $banner['meta']['has_foreign_keys'];
        }
        if ( null === $fk ) {
            $fk = $engine->probe_fk( $files );
        }

        // A cross-prefix restore rewrites only the CREATE TABLE name, not the
        // REFERENCES clauses in foreign keys, so replaying a FK-bearing dump
        // into a different prefix would point constraints at the wrong (or a
        // missing) table. Refuse here - before any maintenance or write - so
        // the operator restores to a same-prefix site rather than risk a
        // silently broken database. Rewriting REFERENCES is intentionally not
        // attempted - it could corrupt a production DB on a rare combination.
        if ( $fk && $source_prefix !== $this->target_prefix() ) {
            throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_RESTORE_CROSS_PREFIX_FK ) ), null, MainWP_Backup_Error::ERR_RESTORE_CROSS_PREFIX_FK ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        }

        return array(
            'files'         => $files,
            'source_prefix' => $source_prefix,
            'fk_path'       => $fk,
            'banner_info'   => isset( $banner['info'] ) ? $banner['info'] : null,
            'embedded'      => $embedded,
            'cursor'        => array(),
            'seen_resumes'  => (int) $task->resume_count,
        );
    }

    /**
     * Remove database dump litter from the live site.
     */
    protected function cleanup_dump_litter() {
        $roots = array(
            rtrim( $this->site_root(), '/' ),
            rtrim( $this->site_root(), '/' ) . '/wp-content',
        );
        foreach ( $roots as $root ) {
            foreach ( array_merge( (array) glob( $root . '/dbBackup*.sql.php' ), (array) glob( $root . '/dbBackup*.sql' ) ) as $litter ) {
                if ( is_file( $litter ) ) {
                    wp_delete_file( $litter );
                }
            }
        }
    }

    /**
     * Locate dump files a full archive extracted over the site.
     *
     * The full backup places its per-table dumps under wp-content, and
     * some generations used the site root.
     *
     * @return string[] Dump file paths.
     */
    protected function find_embedded_dump_files() {
        $candidates = array_merge(
            (array) glob( rtrim( $this->site_root(), '/' ) . '/dbBackup*.sql.php' ),
            (array) glob( rtrim( $this->site_root(), '/' ) . '/wp-content/dbBackup*.sql.php' )
        );
        return array_values( array_filter( $candidates, 'is_file' ) );
    }

    /**
     * Checkpoint into an explicit phase (used to skip the swap on the
     * in-place path).
     *
     * @param object $task       Task row.
     * @param int    $version    Owned version.
     * @param array  $sub_state  Cursor.
     * @param string $backup_dir Scratch root.
     * @param string $phase      Destination phase.
     *
     * @return array Slice result.
     */
    protected function jump_phase( $task, $version, $sub_state, $backup_dir, $phase ) {
        $spine      = MainWP_Child_Backup_Task::instance();
        $sub_state  = $this->stamp_progress( $task, $sub_state, $backup_dir );
        $serialized = $spine->write_sub_state( $task->task_id, $sub_state, $backup_dir, $version );
        if ( false === $serialized ) {
            $serialized = wp_json_encode( $sub_state );
        }

        $new_version = $spine->checkpoint(
            $task->task_id,
            $version,
            array(
                'phase'     => $phase,
                'sub_state' => $serialized,
            )
        );
        if ( false === $new_version ) {
            return $this->slice_result( $version, true, true );
        }

        return $this->slice_result( $new_version, false );
    }

    /**
     * Build the URL (and prefix) replacement pairs from the dump banner.
     *
     * @param array $sub_state Cursor.
     *
     * @return array<int,array{from:string,to:string}> Pairs.
     */
    protected function url_pairs( $sub_state ) {
        $info  = isset( $sub_state['db_replay']['banner_info'] ) ? (array) $sub_state['db_replay']['banner_info'] : array();
        $pairs = array();

        $upload  = wp_upload_dir();
        $current = array(
            'site_url'    => site_url(),
            'home_url'    => home_url(),
            'content_url' => content_url(),
            'upload_url'  => isset( $upload['baseurl'] ) ? $upload['baseurl'] : '',
        );

        foreach ( $current as $key => $to ) {
            $from = isset( $info[ $key ] ) ? (string) $info[ $key ] : '';
            if ( '' !== $from && '' !== $to && $from !== $to ) {
                $pairs[] = array(
                    'from' => $from,
                    'to'   => $to,
                );
            }
        }

        $source_prefix = isset( $sub_state['db_replay']['source_prefix'] ) ? (string) $sub_state['db_replay']['source_prefix'] : '';
        $target_prefix = $this->target_prefix();
        if ( '' !== $source_prefix && $source_prefix !== $target_prefix ) {
            $pairs[] = array(
                'from' => $source_prefix,
                'to'   => $target_prefix,
            );
        }

        return $pairs;
    }

    /**
     * The WooCommerce HPOS order tables to leave untouched during a restore
     * when the operator asked to preserve live orders.
     *
     * Returns an empty list for every restore that did not opt in, so a
     * non-preserve restore replays exactly as before. The order, order-item,
     * and order-derived analytics tables are dedicated HPOS tables, so skipping
     * their stems keeps the live rows in place on both the swap path and the
     * in-place foreign-key path. The customer accounts and product stock those
     * orders reference live in shared tables (users, postmeta) and are handled
     * by the preserve_woo and merge_woo phases instead, not here.
     *
     * @param array $sub_state Resume cursor, carrying params.preserve_orders.
     *
     * @return string[] Bare table stems, empty when not preserving.
     */
    protected function build_woo_skip_stems( $sub_state ) {
        if ( empty( $sub_state['params']['preserve_orders'] ) ) {
            return array();
        }
        return array(
            'wc_orders',
            'wc_orders_meta',
            'wc_order_addresses',
            'wc_order_operational_data',
            'woocommerce_order_items',
            'woocommerce_order_itemmeta',
            'wc_order_product_lookup',
            'wc_order_stats',
            'wc_order_coupon_lookup',
            'wc_order_tax_lookup',
            'wc_customer_lookup',
        );
    }

    /**
     * Stems whose tmp tables loaded cleanly.
     *
     * @param array $sub_state Cursor.
     *
     * @return string[] Bare table stems.
     */
    protected function loaded_stems( $sub_state ) {
        $stems = array();
        if ( isset( $sub_state['db_replay']['cursor']['tables'] ) ) {
            foreach ( $sub_state['db_replay']['cursor']['tables'] as $stem => $info ) {
                if ( isset( $info['status'] ) && 'ok' === $info['status'] ) {
                    $stems[] = $stem;
                }
            }
        }
        return $stems;
    }

    /**
     * Sentinel write/read/delete against the live options table.
     *
     * Raw queries on purpose: the options table was just swapped, and a
     * get_option would happily answer from the object cache.
     *
     * @param string $target_prefix Live table prefix.
     * @param string $restore_id    Restore id (key uniqueness).
     *
     * @return bool True when the round trip held.
     */
    protected function sentinel_round_trip( $target_prefix, $restore_id ) {
        global $wpdb;

        $table = $target_prefix . 'options';
        $key   = 'mainwp_restore_sentinel_' . $restore_id;
        $value = 'sentinel-' . $restore_id . '-' . time();

        //phpcs:disable WordPress.DB -- deliberate raw round trip against the just-swapped table, bypassing the object cache.
        $wpdb->query( $wpdb->prepare( 'DELETE FROM `' . $table . '` WHERE option_name = %s', $key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from the live prefix.
        $inserted = $wpdb->query( $wpdb->prepare( 'INSERT INTO `' . $table . '` (option_name, option_value, autoload) VALUES (%s, %s, %s)', $key, $value, 'no' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from the live prefix.
        $read     = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM `' . $table . '` WHERE option_name = %s', $key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from the live prefix.
        $wpdb->query( $wpdb->prepare( 'DELETE FROM `' . $table . '` WHERE option_name = %s', $key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from the live prefix.
        //phpcs:enable WordPress.DB

        return false !== $inserted && $read === $value;
    }

    /**
     * Advisory loopback check; many hosts block loopback, so the status
     * is recorded but never fails the restore.
     *
     * @return int HTTP status, or 0 when the request failed.
     */
    protected function loopback_status() {
        $response = wp_remote_get(
            home_url( '/' ),
            array(
                'timeout'   => 10,
                'sslverify' => false,
            )
        );
        if ( is_wp_error( $response ) ) {
            return 0;
        }
        return (int) wp_remote_retrieve_response_code( $response );
    }

    /**
     * Active non-main sub-blog home URLs on a multisite network.
     *
     * Bounded on purpose: the smoke check probes a single sub-site, so a
     * large network is never loaded wholesale. Archived, spam, and
     * deleted sub-blogs are skipped - a probe against one of those is not
     * a meaningful health signal.
     *
     * @return array<int,string> Blog id => home URL.
     */
    protected function sub_site_urls() {
        if ( ! function_exists( 'get_sites' ) ) {
            return array();
        }
        $ids  = get_sites(
            array(
                'fields'   => 'ids',
                'number'   => 10,
                'archived' => 0,
                'spam'     => 0,
                'deleted'  => 0,
            )
        );
        $urls = array();
        foreach ( (array) $ids as $id ) {
            $id = (int) $id;
            if ( is_main_site( $id ) ) {
                continue;
            }
            $urls[ $id ] = get_home_url( $id );
        }
        return $urls;
    }

    /**
     * Advisory per-sub-blog smoke check for multisite restores.
     *
     * After a network restore every sub-blog's tables were swapped too,
     * but the main-site loopback says nothing about them. This probes the
     * first sub-site over HTTP and records the result. Advisory like
     * loopback_status(): it never throws and never fails the restore, so
     * it is safe to call before the rollback tables are retired.
     *
     * @return array<int,array> List of blog_id/url/status entries.
     */
    protected function sub_site_probes() {
        if ( ! $this->is_multisite_install() ) {
            return array();
        }
        $urls = $this->sub_site_urls();
        if ( empty( $urls ) ) {
            return array();
        }

        $probes = array();
        foreach ( $urls as $blog_id => $url ) {
            $response = wp_remote_get(
                $url,
                array(
                    'timeout'   => 10,
                    'sslverify' => false,
                )
            );
            $status   = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
            $probes[] = array(
                'blog_id' => (int) $blog_id,
                'url'     => (string) $url,
                'status'  => $status,
            );
            break; // First sub-site only - a smoke check, not a network sweep.
        }
        return $probes;
    }

    /**
     * Flush caches that hold pre-restore data.
     */
    protected function flush_caches() {
        wp_cache_flush();
    }

    /**
     * Terminal cleanup on any phase failure: tmp tables dropped,
     * maintenance lifted, scratch removed. The old aside tables stay -
     * they are the rollback.
     *
     * @param object $task       Task row.
     * @param array  $sub_state  Cursor at failure time.
     * @param string $backup_dir Scratch root.
     */
    protected function terminal_cleanup( $task, $sub_state, $backup_dir ) {
        try {
            $engine = $this->restore_engine();
            if ( isset( $sub_state['params']['restore_id'] ) ) {
                $engine->cleanup_tmp_tables( $sub_state['params']['restore_id'] );
            }
            $engine->exit_maintenance();
            $this->remove_scratch_dir( $task, $backup_dir );
        } catch ( \Throwable $cleanup_error ) {
            // Cleanup must never mask the original failure, so the error is not
            // rethrown. Record it for support instead - a failed cleanup can
            // leave tmp tables, the maintenance flag, or scratch behind.
            // Credentials are scrubbed in case the error came from the database.
            MainWP_Helper::log_debug(
                '[MainWP Backup] Restore terminal cleanup failed for task ' .
                ( isset( $task->task_id ) ? $task->task_id : '?' ) . ': ' .
                MainWP_Backup_Error::scrub_db_credentials( $cleanup_error->getMessage() )
            );
        }
    }

    /**
     * Run terminal cleanup for a restore failed from OUTSIDE the runner - the
     * wall-clock enforcer and the watchdog resume cap both write the terminal
     * row directly and never reach run_phase()'s catch, so a non-FK restore
     * would otherwise leave tmp tables, the .maintenance flag, and scratch
     * behind. A timed-out FK rollback deliberately keeps maintenance on (its
     * aside tables are the recovery), so it is left untouched.
     *
     * @param object $task Pre-fail task row. Its sub_state still carries the
     *                     restore cursor (restore_id) the cleanup needs; the
     *                     terminal write only overwrote the column and any
     *                     sidecar survives.
     */
    public function cleanup_terminal_task( $task ) {
        if ( ! MainWP_Child_Backup_Task::is_restore_type( $task->type ) ) {
            return;
        }
        if ( MainWP_Child_Backup_Task::PHASE_FK_ROLLBACK === $task->phase ) {
            return;
        }

        $backup_dir = $this->backup_dir();
        $sub_state  = MainWP_Child_Backup_Task::instance()->read_sub_state( $task, $backup_dir );
        $this->terminal_cleanup( $task, is_array( $sub_state ) ? $sub_state : array(), $backup_dir );
    }

    /**
     * Remove the per-task scratch directory.
     *
     * @param object $task       Task row.
     * @param string $backup_dir Scratch root.
     */
    protected function remove_scratch_dir( $task, $backup_dir ) {
        $scratch = $this->scratch_dir( $task->task_id, $backup_dir );
        if ( ! is_dir( $scratch ) ) {
            return;
        }
        $this->rrmdir( $scratch );
    }

    /**
     * Recursive directory removal, bounded to the scratch tree.
     *
     * @param string $dir Directory.
     */
    private function rrmdir( $dir ) {
        foreach ( (array) scandir( $dir ) as $entry ) {
            if ( '.' === $entry || '..' === $entry ) {
                continue;
            }
            $path = rtrim( $dir, '/' ) . '/' . $entry;
            if ( is_dir( $path ) && ! is_link( $path ) ) {
                $this->rrmdir( $path );
            } else {
                @unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions -- best-effort scratch cleanup.
            }
        }
        @rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions -- best-effort scratch cleanup.
    }

    /**
     * The live table prefix - never taken from the wire.
     *
     * @return string Prefix.
     */
    protected function target_prefix() {
        global $wpdb;
        return $wpdb->prefix;
    }

    /**
     * DB restore engine (test seam).
     *
     * @return MainWP_Backup_Db_Restore_Engine
     */
    protected function restore_engine() {
        return new MainWP_Backup_Db_Restore_Engine();
    }

    /**
     * File restorer (test seam).
     *
     * @return MainWP_Child_Backup_File_Restorer
     */
    protected function file_restorer() {
        return new MainWP_Child_Backup_File_Restorer();
    }

    /**
     * URL rewriter (test seam).
     *
     * @return MainWP_Backup_Url_Rewriter
     */
    protected function url_rewriter() {
        return new MainWP_Backup_Url_Rewriter();
    }
}
