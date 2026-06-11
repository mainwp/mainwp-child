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
        return 'restore' === $type || 'restore_db' === $type;
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
     * @param string $sub_type 'full' or 'db'.
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
        if ( is_multisite() ) {
            throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_RESTORE_MULTISITE ) ), null, MainWP_Backup_Error::ERR_RESTORE_MULTISITE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        }

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

        $type    = 'db' === $sub_type ? 'restore_db' : 'restore';
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
        if ( MainWP_Child_Backup_Task::PHASE_RECEIVING_ARTIFACTS !== $task->phase && MainWP_Child_Backup_Task::PHASE_QUEUED !== $task->phase ) {
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
        $path = $scratch . $file;
        $size = file_exists( $path ) ? (int) filesize( $path ) : 0;

        if ( $offset < 0 || $offset > $size ) {
            return array(
                'error'          => MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_CHUNK_GAP ),
                'error_code'     => MainWP_Backup_Error::ERR_CHUNK_GAP,
                'bytes_received' => $size,
            );
        }

        if ( '' === $chunk_sha256 || ! hash_equals( $chunk_sha256, hash( 'sha256', $raw ) ) ) {
            return $this->chunk_error( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_CHUNK_HASH ), MainWP_Backup_Error::ERR_CHUNK_HASH );
        }

        if ( $offset < $size ) {
            // Already-written bytes: a duplicate of a confirmed chunk.
            return $this->chunk_ack( $spine, $task, $file, $size );
        }

        if ( '' !== $raw ) {
            $fh = fopen( $path, 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- streaming append to the staging file.
            if ( false === $fh || strlen( $raw ) !== fwrite( $fh, $raw ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- streaming append.
                if ( false !== $fh ) {
                    fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- paired with fopen above.
                }
                return $this->chunk_error( esc_html__( 'The artifact data could not be written. The site may be out of disk space.', 'mainwp-child' ), MainWP_Backup_Error::ERR_DISK_FULL );
            }
            fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- paired with fopen above.
            $size = (int) filesize( $path );
        }

        if ( ! file_exists( $path ) && 0 === $offset && '' === $raw ) {
            // A zero-byte artifact (an empty tombstones list) still needs
            // its file so the final hash check and the apply phase see it.
            file_put_contents( $path, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- creating the empty staging file.
        }

        $done = false;
        if ( $is_final ) {
            $expected_hash = isset( $expected['sha256'] ) ? (string) $expected['sha256'] : '';
            $actual        = hash_file( 'sha256', $path );
            if ( '' === $expected_hash || ! is_string( $actual ) || ! hash_equals( $expected_hash, $actual ) ) {
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

            $serialized = $spine->write_sub_state( $fresh->task_id, $sub_state, $backup_dir );
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
                    return $this->advance_phase( $task, $version, $sub_state, $backup_dir );
                case MainWP_Child_Backup_Task::PHASE_RECEIVING_ARTIFACTS:
                    return $this->run_receiving( $task, $version, $sub_state, $backup_dir );
                case MainWP_Child_Backup_Task::PHASE_VERIFYING_ARTIFACTS:
                    return $this->run_verifying( $task, $version, $sub_state, $backup_dir );
                case MainWP_Child_Backup_Task::PHASE_RESTORING_FILES:
                    return $this->run_restoring_files( $task, $version, $sub_state, $deadline, $backup_dir );
                case MainWP_Child_Backup_Task::PHASE_REPLAYING_DB:
                    return $this->run_replaying_db( $task, $version, $sub_state, $deadline, $backup_dir );
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
     * @throws MainWP_Exception On extraction or replay failure.
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
            // replaying the dump's copy would clobber it mid-flight.
            'skip_stems'    => array( 'mainwp_child_backup_task' ),
        );

        $outcome      = $engine->replay_slice( $db['cursor'], $config, $deadline, $unclean );
        $db['cursor'] = $outcome['cursor'];

        if ( null !== $outcome['error'] ) {
            throw new MainWP_Exception( esc_html( $outcome['error'] ), null, $outcome['error_code'] ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped inline.
        }

        $sub_state['db_replay'] = $db;

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
            return $this->advance_phase( $task, $version, $sub_state, $backup_dir );
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
                'exclude' => array( $target_prefix . 'posts' => array( 'guid' ) ),
            ),
            $deadline
        );

        $sub_state['url_rewrite'] = $outcome['cursor'];
        if ( ! $outcome['done'] ) {
            return $this->checkpoint_sub_state( $task, $version, $sub_state, $backup_dir, true );
        }

        $this->flush_caches();
        return $this->advance_phase( $task, $version, $sub_state, $backup_dir );
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

        $counts = $engine->verify_row_counts( $expected, $target_prefix );
        if ( ! $counts['ok'] ) {
            throw new MainWP_Exception( esc_html( 'Post-restore row counts do not match the replayed data. The previous tables were kept for rollback. Mismatches: ' . wp_json_encode( $counts['mismatches'] ) ), null, MainWP_Backup_Error::ERR_RESTORE_DB_REPLAY ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped inline.
        }

        if ( ! $this->sentinel_round_trip( $target_prefix, $sub_state['params']['restore_id'] ) ) {
            throw new MainWP_Exception( 'The restored options table failed a write/read round trip. The previous tables were kept for rollback.', null, MainWP_Backup_Error::ERR_RESTORE_DB_REPLAY ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message.
        }

        $sub_state['verification'] = array(
            'row_counts_ok'   => true,
            'sentinel_ok'     => true,
            'loopback_status' => $this->loopback_status(),
        );

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
        $serialized = $spine->write_sub_state( $task->task_id, $sub_state, $backup_dir );
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
        } catch ( \Throwable $cleanup_error ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement -- cleanup must never mask the original failure.
            null;
        }
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
