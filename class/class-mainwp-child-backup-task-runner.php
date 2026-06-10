<?php
/**
 * MainWP Child Backup Task Runner - advances a backup task one bounded slice.
 *
 * Each call advances the persisted task machine by one slice sized to stay
 * under shared-hosting proxy ceilings, checkpointing phase and resume cursor
 * so the next call (dashboard continuation or watchdog fallback) picks up
 * where this one stopped. Work runs under the task row's atomic lease; every
 * state write is a compare-and-swap, and a stale version abandons the slice
 * rather than corrupting another runner's progress.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Child_Backup_Task_Runner
 *
 * @package MainWP\Child
 */
class MainWP_Child_Backup_Task_Runner { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Lease owner label for dashboard-driven slices.
     */
    const LEASE_OWNER_DASHBOARD = 'dashboard';

    /**
     * Lease owner label for watchdog-driven slices.
     */
    const LEASE_OWNER_WATCHDOG = 'watchdog';

    /**
     * Lease TTL in seconds (~2x one slice).
     */
    const LEASE_TTL_SECONDS = 180;

    /**
     * Work budget for one slice, in seconds. Kept under typical proxy
     * ceilings with margin for the surrounding request overhead.
     */
    const SLICE_BUDGET_SECONDS = 75;

    /**
     * Minimum seconds of remaining budget worth starting engine work with.
     */
    const MIN_PHASE_BUDGET = 10;

    /**
     * Singleton instance.
     *
     * @var MainWP_Child_Backup_Task_Runner|null
     */
    protected static $instance = null;

    /**
     * Task id of the slice currently advancing (for progress callbacks).
     *
     * @var string
     */
    protected $current_task_id = '';

    /**
     * Get the singleton instance.
     *
     * @return MainWP_Child_Backup_Task_Runner
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Advance a task by one bounded slice.
     *
     * Acquires the lease (unless the caller already holds it), then loops
     * the phase machine until a phase yields (partial work persisted), the
     * slice budget runs out, or the task goes terminal. The lease is always
     * released with the final version this runner owns - including when the
     * lease was pre-held, so the task is immediately available again and the
     * original holder's release becomes a harmless no-op.
     *
     * @param object $task           Task row (a fresh read).
     * @param string $owner          Lease owner label.
     * @param bool   $lease_pre_held True when the caller already holds the lease (watchdog path).
     *
     * @return object|null Fresh task row after the slice.
     */
    public function advance_one_slice( $task, $owner, $lease_pre_held = false ) { // phpcs:ignore -- NOSONAR - slice loop with failure handling.
        $spine   = MainWP_Child_Backup_Task::instance();
        $task_id = $task->task_id;
        $version = (int) $task->version;

        if ( ! $lease_pre_held ) {
            if ( ! $spine->acquire_lease( $task_id, $owner, $version, self::LEASE_TTL_SECONDS ) ) {
                return $spine->get_task( $task_id ); // Another runner owns the row - back off.
            }
            ++$version; // The acquire incremented it.
        }

        $this->current_task_id = $task_id;
        $backup_dir            = $this->backup_dir();
        $deadline              = time() + $this->slice_budget();
        $abandoned             = false;

        try {
            $guard = 0;
            while ( ++$guard <= 10 ) {
                $task = $spine->get_task( $task_id );
                if ( null === $task || MainWP_Child_Backup_Task::is_terminal_phase( $task->phase ) ) {
                    break;
                }

                $sub_state = $spine->read_sub_state( $task, $backup_dir );
                if ( null === $sub_state ) {
                    $sub_state = array(); // Unrecoverable cursor - restart the phase cleanly.
                }

                $result  = $this->run_phase( $task, $version, $sub_state, $deadline, $backup_dir );
                $version = $result['version'];

                if ( $result['abandon'] ) {
                    $abandoned = true;
                    break;
                }
                if ( $result['yield'] || time() >= $deadline ) {
                    break;
                }
            }
        } catch ( \Throwable $e ) { // phpcs:ignore -- NOSONAR - any engine failure must reach a terminal phase.
            $code        = ( $e instanceof MainWP_Exception ) ? $e->get_message_error_code() : '';
            $new_version = $spine->checkpoint(
                $task_id,
                $version,
                array(
                    'phase'     => MainWP_Child_Backup_Task::PHASE_FAILED,
                    'sub_state' => array(
                        'error' => array(
                            'message' => $e->getMessage(),
                            'code'    => $code,
                        ),
                    ),
                )
            );
            if ( false !== $new_version ) {
                $version = $new_version;
                $spine->delete_sidecar( $task_id, $backup_dir );
            }
        }

        if ( ! $abandoned ) {
            $spine->release_lease( $task_id, $owner, $version );
        }

        $this->current_task_id = '';

        return $spine->get_task( $task_id );
    }

    /**
     * Dispatch one phase step.
     *
     * @param object $task       Fresh task row.
     * @param int    $version    Version this runner owns.
     * @param array  $sub_state  Resolved resume cursor.
     * @param int    $deadline   Slice deadline (unix time).
     * @param string $backup_dir Scratch directory.
     *
     * @return array { version: int, yield: bool, abandon: bool }
     */
    protected function run_phase( $task, $version, $sub_state, $deadline, $backup_dir ) {
        switch ( $task->phase ) {
            case MainWP_Child_Backup_Task::PHASE_QUEUED:
                return $this->run_queued( $task, $version, $sub_state, $backup_dir );
            case MainWP_Child_Backup_Task::PHASE_DUMPING_DB:
                return $this->run_dumping_db( $task, $version, $sub_state, $deadline, $backup_dir );
            case MainWP_Child_Backup_Task::PHASE_ARCHIVING_FILES:
                return $this->run_archiving_files( $task, $version, $sub_state, $deadline, $backup_dir );
            case MainWP_Child_Backup_Task::PHASE_UPLOADING:
                return $this->run_stub_phase( $task, $version, $sub_state, $backup_dir, 'upload_accepted', 'perform_upload' );
            case MainWP_Child_Backup_Task::PHASE_SUBMITTING:
                return $this->run_stub_phase( $task, $version, $sub_state, $backup_dir, 'submit_accepted', 'perform_submit' );
            default:
                // Unknown phase - nothing sane to do; yield and let the
                // wall-clock deadline fail the task eventually.
                return $this->slice_result( $version, true );
        }
    }

    /**
     * Queued: route to the first work phase for this backup type.
     *
     * @param object $task       Task row.
     * @param int    $version    Owned version.
     * @param array  $sub_state  Cursor.
     * @param string $backup_dir Scratch directory.
     *
     * @return array Slice result.
     */
    protected function run_queued( $task, $version, $sub_state, $backup_dir ) {
        return $this->advance_phase( $task, $version, $sub_state, $backup_dir );
    }

    /**
     * Dump the database, bounded. Tier 1 completes within the slice or
     * downgrades to Tier 2 on its first budget overrun; Tier 2 resumes from
     * a persisted cursor across slices. A db backup also produces its final
     * artifact here (the dump archive), since it has no file phase.
     *
     * @param object $task       Task row.
     * @param int    $version    Owned version.
     * @param array  $sub_state  Cursor.
     * @param int    $deadline   Slice deadline.
     * @param string $backup_dir Scratch directory.
     *
     * @throws MainWP_Exception When the dump artifact cannot be created.
     *
     * @return array Slice result.
     */
    protected function run_dumping_db( $task, $version, $sub_state, $deadline, $backup_dir ) { // phpcs:ignore -- NOSONAR - tiered dump routing.
        $remaining = $deadline - time();
        if ( $remaining < self::MIN_PHASE_BUDGET ) {
            return $this->slice_result( $version, true );
        }

        $params = isset( $sub_state['params'] ) && is_array( $sub_state['params'] ) ? $sub_state['params'] : array();

        if ( empty( $sub_state['db_prefix'] ) ) {
            $sub_state['db_prefix'] = $this->build_db_prefix( $task, $params, $backup_dir );
        }
        $prefix = $sub_state['db_prefix'];

        if ( empty( $sub_state['db_tier'] ) ) {
            $sub_state['db_tier'] = $this->tier1_available() ? 1 : 2;
        }

        if ( empty( $sub_state['db_files'] ) ) {
            if ( 1 === (int) $sub_state['db_tier'] ) {
                try {
                    $files = $this->dump_tier1( $prefix, $remaining );
                } catch ( MainWP_Backup_Budget_Exception $e ) {
                    // One wasted request at most: never retry a snapshot dump
                    // that already proved it cannot fit the budget.
                    unset( $e );
                    $sub_state['db_tier']         = 2;
                    $sub_state['tier1_attempted'] = 1;
                    return $this->checkpoint_sub_state( $task, $version, $sub_state, $backup_dir, true );
                }
            } else {
                $cursor = isset( $sub_state['db_cursor'] ) ? $sub_state['db_cursor'] : null;
                $slice  = $this->dump_tier2( $prefix, $cursor, $remaining );
                if ( empty( $slice['done'] ) ) {
                    $sub_state['db_cursor'] = $slice['cursor'];
                    return $this->checkpoint_sub_state( $task, $version, $sub_state, $backup_dir, true );
                }
                $files = $slice['files'];
            }

            unset( $sub_state['db_cursor'] );
            $sub_state['db_files'] = $this->finalize_dump_files( $files, $prefix );

            // Persist dump completion before the artifact step - a kill
            // between the two must not repeat the dump.
            $result = $this->checkpoint_sub_state( $task, $version, $sub_state, $backup_dir, false );
            if ( $result['abandon'] ) {
                return $result;
            }
            $version = $result['version'];
        }

        if ( 'db' === $task->type ) {
            $ext      = isset( $params['ext'] ) ? $params['ext'] : 'zip';
            $artifact = $this->archive_db_artifact( $sub_state['db_files'], $prefix, $ext );
            if ( empty( $artifact ) || ! file_exists( $artifact ) ) {
                throw new MainWP_Exception( 'The database backup archive could not be created.', null, MainWP_Backup_Error::ERR_ZIP_FAILED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message.
            }
            $sub_state['artifact'] = array(
                'file' => basename( $artifact ),
                'size' => (int) filesize( $artifact ),
            );
            unset( $sub_state['db_files'] );
        }

        return $this->advance_phase( $task, $version, $sub_state, $backup_dir );
    }

    /**
     * Archive the site files, bounded. Resumable formats reopen the same
     * archive in append mode each slice; the archive itself is the cursor.
     *
     * @param object $task       Task row.
     * @param int    $version    Owned version.
     * @param array  $sub_state  Cursor.
     * @param int    $deadline   Slice deadline.
     * @param string $backup_dir Scratch directory.
     *
     * @return array Slice result.
     */
    protected function run_archiving_files( $task, $version, $sub_state, $deadline, $backup_dir ) {
        $remaining = $deadline - time();
        if ( $remaining < self::MIN_PHASE_BUDGET ) {
            return $this->slice_result( $version, true );
        }

        $params = isset( $sub_state['params'] ) && is_array( $sub_state['params'] ) ? $sub_state['params'] : array();

        if ( empty( $sub_state['archive_file'] ) ) {
            $name      = isset( $params['file'] ) && '' !== $params['file'] ? $params['file'] . '-' : '';
            $extension = $this->resolve_archive_extension( isset( $params['ext'] ) ? $params['ext'] : 'zip' );

            $sub_state['archive_file'] = 'backup-' . $name . time() . $extension;
        }
        $append = ! empty( $sub_state['archive_started'] );

        // Persist the archive name before any engine work, so a killed
        // slice resumes the same file instead of starting a second one.
        $sub_state['archive_started'] = true;

        $result = $this->checkpoint_sub_state( $task, $version, $sub_state, $backup_dir, false );
        if ( $result['abandon'] ) {
            return $result;
        }
        $version = $result['version'];

        try {
            $res = $this->run_file_archiver(
                $params,
                $sub_state['archive_file'],
                $append,
                $deadline,
                isset( $sub_state['db_files'] ) ? $sub_state['db_files'] : array()
            );
        } catch ( MainWP_Backup_Budget_Exception $e ) {
            unset( $e );
            // Partial progress is in the archive itself - just yield.
            return $this->slice_result( $version, true );
        }

        $sub_state['artifact'] = array(
            'file' => $res['file'],
            'size' => (int) $res['filesize'],
        );
        unset( $sub_state['db_files'] );

        return $this->advance_phase( $task, $version, $sub_state, $backup_dir );
    }

    /**
     * A wired-but-stubbed late phase with the safety guard: work already
     * accepted by the receiver is never re-issued.
     *
     * @param object $task        Task row.
     * @param int    $version     Owned version.
     * @param array  $sub_state   Cursor.
     * @param string $backup_dir  Scratch directory.
     * @param string $accept_key  Acceptance marker key in sub_state.
     * @param string $work_method Stub work method name.
     *
     * @return array Slice result.
     */
    protected function run_stub_phase( $task, $version, $sub_state, $backup_dir, $accept_key, $work_method ) {
        if ( empty( $sub_state[ $accept_key ] ) ) {
            $this->{$work_method}( $sub_state );
            $sub_state[ $accept_key ] = true;
        }

        return $this->advance_phase( $task, $version, $sub_state, $backup_dir );
    }

    /**
     * Checkpoint to the next phase for this task type. Terminal transitions
     * clean up the sidecar.
     *
     * @param object $task       Task row.
     * @param int    $version    Owned version.
     * @param array  $sub_state  Cursor to persist.
     * @param string $backup_dir Scratch directory.
     *
     * @return array Slice result.
     */
    protected function advance_phase( $task, $version, $sub_state, $backup_dir ) {
        $spine = MainWP_Child_Backup_Task::instance();
        $next  = MainWP_Child_Backup_Task::next_phase( $task->phase, $task->type );
        if ( null === $next ) {
            return $this->slice_result( $version, true );
        }

        $serialized = $spine->write_sub_state( $task->task_id, $sub_state, $backup_dir );
        if ( false === $serialized ) {
            $serialized = wp_json_encode( $sub_state ); // Sidecar write failed - keep state inline as a best effort.
        }

        $new_version = $spine->checkpoint(
            $task->task_id,
            $version,
            array(
                'phase'     => $next,
                'sub_state' => $serialized,
            )
        );
        if ( false === $new_version ) {
            return $this->slice_result( $version, true, true );
        }

        if ( MainWP_Child_Backup_Task::is_terminal_phase( $next ) ) {
            $spine->delete_sidecar( $task->task_id, $backup_dir );
        }

        return $this->slice_result( $new_version, false );
    }

    /**
     * Checkpoint the cursor without changing phase.
     *
     * @param object $task        Task row.
     * @param int    $version     Owned version.
     * @param array  $sub_state   Cursor to persist.
     * @param string $backup_dir  Scratch directory.
     * @param bool   $then_yield  Whether the slice should yield after this write.
     *
     * @return array Slice result.
     */
    protected function checkpoint_sub_state( $task, $version, $sub_state, $backup_dir, $then_yield ) {
        $spine      = MainWP_Child_Backup_Task::instance();
        $serialized = $spine->write_sub_state( $task->task_id, $sub_state, $backup_dir );
        if ( false === $serialized ) {
            $serialized = wp_json_encode( $sub_state );
        }

        $new_version = $spine->checkpoint( $task->task_id, $version, array( 'sub_state' => $serialized ) );
        if ( false === $new_version ) {
            return $this->slice_result( $version, true, true );
        }

        return $this->slice_result( $new_version, $then_yield );
    }

    /**
     * Build a slice-step result.
     *
     * @param int  $version      Owned version after the step.
     * @param bool $should_yield Stop this slice (partial work persisted).
     * @param bool $abandon      Stale CAS - stop without further writes.
     *
     * @return array Slice result.
     */
    protected function slice_result( $version, $should_yield, $abandon = false ) {
        return array(
            'version' => $version,
            'yield'   => $should_yield,
            'abandon' => $abandon,
        );
    }

    /**
     * Stable dump file prefix for this task.
     *
     * A full backup uses the bare prefix the legacy archiver paths expect;
     * a db backup gets the legacy timestamped name.
     *
     * @param object $task       Task row.
     * @param array  $params     Backup params.
     * @param string $backup_dir Scratch directory.
     *
     * @return string Dump file prefix (absolute).
     */
    protected function build_db_prefix( $task, $params, $backup_dir ) {
        $dir = rtrim( $backup_dir, '/' ) . '/';
        if ( 'full' === $task->type ) {
            return $dir . 'dbBackup';
        }
        $name = isset( $params['file'] ) && '' !== $params['file'] ? $params['file'] . '-' : '';
        return $dir . 'dbBackup-' . $name . time();
    }

    /**
     * The archive extension the tar archiver would actually use on this
     * host (compression fallbacks included), so the persisted file name
     * matches the file the engine creates.
     *
     * @param string $ext Requested extension.
     *
     * @return string Dotted extension.
     */
    protected function resolve_archive_extension( $ext ) {
        if ( 'zip' === $ext ) {
            return '.zip';
        }
        if ( 'tar.bz2' === $ext && ! function_exists( 'bzopen' ) ) {
            $ext = 'tar.gz';
        }
        if ( 'tar.gz' === $ext && ! function_exists( 'gzopen' ) ) {
            $ext = 'tar';
        }
        return '.' . $ext;
    }

    // ---- engine seams ----------------------------------------------------
    // Protected so tests can stub the heavy engines while the spine logic
    // above runs for real.

    /**
     * The randomized backup scratch directory.
     *
     * @return string Directory path.
     */
    protected function backup_dir() {
        $dirs = MainWP_Helper::get_mainwp_dir( 'backup' );
        return $dirs[0];
    }

    /**
     * Slice work budget in seconds.
     *
     * @return int Budget.
     */
    protected function slice_budget() {
        return self::SLICE_BUDGET_SECONDS;
    }

    /**
     * Whether the snapshot dump's dedicated connection is available.
     *
     * @return bool Availability.
     */
    protected function tier1_available() {
        $preflight = MainWP_Backup_Db_Dumper::instance()->check_dedicated_connection();
        return ! empty( $preflight['ok'] );
    }

    /**
     * Run the consistent-snapshot dump within the given budget.
     *
     * @param string $prefix Dump file prefix.
     * @param int    $budget Seconds.
     *
     * @return array Dump file paths.
     */
    protected function dump_tier1( $prefix, $budget ) {
        $result = MainWP_Backup_Db_Dumper::instance()->dump_to_files( $prefix, $budget );
        return $result['files'];
    }

    /**
     * Run one paginated dump slice.
     *
     * @param string     $prefix Dump file prefix.
     * @param array|null $cursor Resume cursor.
     * @param int        $budget Seconds.
     *
     * @return array Slice result (done/cursor/files).
     */
    protected function dump_tier2( $prefix, $cursor, $budget ) {
        return MainWP_Backup_Db_Dumper::instance()->dump_tier2_slice( $prefix, $cursor, $budget );
    }

    /**
     * Add the legacy completion marker to a finished dump file set.
     *
     * @param array  $files  Dump files.
     * @param string $prefix Dump file prefix.
     *
     * @return array Files including the marker.
     */
    protected function finalize_dump_files( $files, $prefix ) {
        fclose( fopen( $prefix . '.sql', 'w' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- empty marker file, legacy format.
        $files[] = $prefix . '.sql';
        return $files;
    }

    /**
     * Archive a finished dump file set into the db backup artifact.
     *
     * @param array  $db_files Dump files.
     * @param string $prefix   Dump file prefix.
     * @param string $ext      Archive extension.
     *
     * @return string|false Artifact path, false on failure.
     */
    protected function archive_db_artifact( $db_files, $prefix, $ext ) {
        return MainWP_Backup::get()->archive_db_files( $db_files, $prefix, $ext );
    }

    /**
     * Run one bounded slice of the file archiver.
     *
     * The archive file name is stable across slices, and the engine opens an
     * existing file in append mode itself - so a budget-interrupted archive
     * continues instead of restarting. Pre-produced dump files are handed to
     * the engine so the database is never dumped twice.
     *
     * @param array  $params       Backup params.
     * @param string $archive_file Archive file name (stable across slices).
     * @param bool   $append       Whether a previous slice already started the archive.
     * @param int    $deadline     Slice deadline (unix time).
     * @param array  $db_files     Pre-produced dump files.
     *
     * @throws MainWP_Exception When the archiver fails, or its budget subclass when the slice budget interrupts the traversal.
     *
     * @return array Result with file/filesize keys.
     */
    protected function run_file_archiver( $params, $archive_file, $append, $deadline, $db_files ) { // phpcs:ignore -- NOSONAR - engine boundary.
        $backup = MainWP_Backup::get();
        $ext    = isset( $params['ext'] ) ? $params['ext'] : 'zip';

        if ( 'zip' === $ext ) {
            // Zip cannot resume mid-archive: restart clean.
            $partial = rtrim( $this->backup_dir(), '/' ) . '/' . $archive_file;
            if ( file_exists( $partial ) ) {
                unlink( $partial ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- partial archive cleanup.
            }
            $append = false;
        }

        if ( ! empty( $db_files ) ) {
            $backup->set_prepared_db_files( $db_files );
        }
        $backup->set_slice_deadline( $deadline );

        $task_id = $this->current_task_id;
        $backup->set_progress_callback(
            function () use ( $task_id ) {
                MainWP_Child_Backup_Task::instance()->touch_progress( $task_id );
            }
        );

        try {
            // Passing the full file name with append=true makes the engine
            // create on the first slice (no file yet) and append after.
            $res = $backup->create_full_backup(
                isset( $params['excludes'] ) && is_array( $params['excludes'] ) ? $params['excludes'] : array(),
                '',
                true,
                true,
                isset( $params['file_descriptors'] ) ? (int) $params['file_descriptors'] : 0,
                $archive_file,
                ! empty( $params['excludezip'] ),
                ! empty( $params['excludenonwp'] ),
                isset( $params['loadFilesBeforeZip'] ) ? $params['loadFilesBeforeZip'] : true,
                $ext,
                false,
                true
            );
        } finally {
            $backup->set_prepared_db_files( null );
            $backup->set_slice_deadline( null );
            $backup->set_progress_callback( null );
        }

        if ( ! $res || empty( $res['file'] ) ) {
            throw new MainWP_Exception( 'The backup archive could not be created.', null, MainWP_Backup_Error::ERR_TAR_WRITE_FAILED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message.
        }

        return $res;
    }

    /**
     * Upload transport stub - filled in by the storage transport work. The
     * acceptance guard around this call is already live.
     *
     * @param array $sub_state Cursor.
     */
    protected function perform_upload( $sub_state ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- stub signature.
        // Stub: local artifacts need no transport yet.
    }

    /**
     * Manifest submit stub - filled in by the manifest work. The acceptance
     * guard around this call is already live.
     *
     * @param array $sub_state Cursor.
     */
    protected function perform_submit( $sub_state ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- stub signature.
        // Stub: nothing to submit yet.
    }
}
