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
     * Subscribe to the watchdog's resume signal so stalled tasks get their
     * slice advanced by this runner.
     */
    public function register_hooks() {
        add_action( 'mainwp_child_backup_task_resume', array( $this, 'handle_watchdog_resume' ), 10, 2 );
    }

    /**
     * Advance one slice for a stalled task the watchdog re-entered.
     *
     * The watchdog already holds the lease (and did the resume bookkeeping),
     * so the runner skips acquisition. It still releases with the final
     * version it owns, making the watchdog's own trailing release a harmless
     * no-op.
     *
     * @param object $task    Stalled task row (pre-resume state).
     * @param int    $version Row version after the watchdog's bookkeeping.
     */
    public function handle_watchdog_resume( $task, $version ) {
        unset( $version ); // A fresh read below carries the current version.

        if ( MainWP_Child_Restore_Task_Runner::is_restore_type( $task->type ) && ! ( $this instanceof MainWP_Child_Restore_Task_Runner ) ) {
            return; // Restore tasks resume through the restore runner's listener.
        }

        $fresh = MainWP_Child_Backup_Task::instance()->get_task( $task->task_id );
        if ( null === $fresh ) {
            return;
        }

        $this->advance_one_slice( $fresh, self::LEASE_OWNER_WATCHDOG, true );
    }

    /**
     * Validate request params and create a new task on the machine.
     *
     * @param string $type   Backup type (full/db/files).
     * @param array  $params Backup params persisted into the resume cursor.
     *
     * @throws MainWP_Exception On an unknown type, an already-active task
     *                          (the single-backup invariant), or a failed insert.
     *
     * @return string Task id.
     */
    public function create_task_for_params( $type, $params ) {
        if ( ! in_array( $type, array( 'full', 'db', 'files', 'incremental' ), true ) ) {
            throw new MainWP_Exception( 'Unknown backup type requested for the backup task.', null, '' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message.
        }

        $spine = MainWP_Child_Backup_Task::instance();
        $spine->install();

        if ( ! empty( $spine->get_active_tasks() ) ) {
            throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_ALREADY_RUNNING ) ), null, MainWP_Backup_Error::ERR_ALREADY_RUNNING ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        }

        $task_id = $spine->create_task( $type, array( 'params' => is_array( $params ) ? $params : array() ) );
        if ( false === $task_id ) {
            throw new MainWP_Exception( 'The backup task could not be created on this site.', null, '' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message.
        }

        return $task_id;
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

        if ( MainWP_Child_Backup_Task::is_terminal_phase( $task->phase ) ) {
            return $task; // Finished work: no lease churn, no version bump.
        }

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

        if ( 'incremental' === $task->type ) {
            return $this->run_incremental_archiving( $task, $version, $sub_state, $deadline, $backup_dir );
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

        if ( ! empty( $params['emit_manifest'] ) ) {
            // A manifest emission failure never fails the backup itself -
            // the archive is intact; the chain base just lacks a manifest,
            // which the next incremental resolves by forcing a full.
            try {
                $manifest = $this->emit_manifest_sidecars( $params, $backup_dir );
            } catch ( \Throwable $e ) { // phpcs:ignore -- NOSONAR - manifest emission is best effort.
                $manifest = null;
            }
            if ( is_array( $manifest ) && isset( $manifest['size'] ) ) {
                $sub_state['manifest']  = array(
                    'file' => MainWP_Child_Backup_File_Walker::FILES_LIST,
                    'size' => (int) $manifest['size'],
                );
                $sub_state['artifacts'] = array(
                    array(
                        'file' => $res['file'],
                        'size' => (int) $res['filesize'],
                        'role' => 'full',
                    ),
                    array(
                        'file' => MainWP_Child_Backup_File_Walker::FILES_LIST,
                        'size' => (int) $manifest['size'],
                        'role' => 'manifest',
                    ),
                );
            }
        }

        return $this->advance_phase( $task, $version, $sub_state, $backup_dir );
    }

    /**
     * Archive only what changed since the parent snapshot, bounded.
     *
     * Step 1 (once): resolve the parent manifest, walk the current tree
     * into staged sidecars, and diff - changes.list and tombstones.list
     * land on disk and the slice yields so packing starts with a fresh
     * budget. Step 2 (resumable): pack changes.list into rotating
     * per-component ZIP parts, the cursor being the changes.list line
     * number. Step 3: archive the fresh database dump and record the
     * role-tagged artifact list.
     *
     * @param object $task       Task row.
     * @param int    $version    Owned version.
     * @param array  $sub_state  Cursor.
     * @param int    $deadline   Slice deadline.
     * @param string $backup_dir Scratch directory.
     *
     * @throws MainWP_Exception When the parent manifest is unusable (the
     *                          Dashboard reruns the site as a full backup)
     *                          or an artifact cannot be created.
     *
     * @return array Slice result.
     */
    protected function run_incremental_archiving( $task, $version, $sub_state, $deadline, $backup_dir ) { // phpcs:ignore -- NOSONAR - staged diff/pack flow.
        $params = isset( $sub_state['params'] ) && is_array( $sub_state['params'] ) ? $sub_state['params'] : array();
        $dir    = rtrim( $backup_dir, '/' ) . '/';

        if ( empty( $sub_state['diff_done'] ) ) {
            $parent_path = $this->resolve_parent_manifest( $sub_state, $backup_dir );

            $manifest = $this->emit_manifest_sidecars( $params, $backup_dir );

            $diff = $this->run_incremental_diff(
                $parent_path,
                $dir . MainWP_Child_Backup_File_Walker::FILES_LIST . MainWP_Child_Backup_File_Walker::STAGED_SUFFIX,
                $dir . MainWP_Child_Backup_Incremental_Diff::CHANGES_LIST,
                $dir . MainWP_Child_Backup_Incremental_Diff::TOMBSTONES_LIST
            );

            if ( ! empty( $diff['force_full'] ) ) {
                throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_INCREMENTAL_BASE_MISSING ) ), null, MainWP_Backup_Error::ERR_INCREMENTAL_BASE_MISSING ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
            }

            $sub_state['diff_done']        = true;
            $sub_state['manifest']         = array(
                'file' => MainWP_Child_Backup_File_Walker::FILES_LIST,
                'size' => (int) $manifest['size'],
            );
            $sub_state['changes_count']    = (int) $diff['changes'];
            $sub_state['tombstones_count'] = (int) $diff['tombstones'];
            $sub_state['pack_cursor']      = array(
                'line'             => 0,
                'component'        => '',
                'part_index'       => 0,
                'part_entry_count' => 0,
                'part_start_line'  => 0,
                'next_index'       => array(),
            );
            $sub_state['completed_parts']  = array();

            // Yield so the pack loop starts with a fresh slice budget.
            return $this->checkpoint_sub_state( $task, $version, $sub_state, $backup_dir, true );
        }

        $pack      = $this->pack_incremental_changes( $sub_state, $deadline, $backup_dir );
        $sub_state = $pack['sub_state'];
        if ( empty( $pack['done'] ) ) {
            return $this->checkpoint_sub_state( $task, $version, $sub_state, $backup_dir, true );
        }

        $artifacts   = array();
        $artifacts[] = array(
            'file' => $sub_state['manifest']['file'],
            'size' => (int) $sub_state['manifest']['size'],
            'role' => 'manifest',
        );

        $tombstones_path = $dir . MainWP_Child_Backup_Incremental_Diff::TOMBSTONES_LIST;
        $artifacts[]     = array(
            'file' => MainWP_Child_Backup_Incremental_Diff::TOMBSTONES_LIST,
            'size' => file_exists( $tombstones_path ) ? (int) filesize( $tombstones_path ) : 0,
            'role' => 'tombstones',
        );

        if ( ! empty( $sub_state['db_files'] ) ) {
            $db_artifact = $this->archive_db_artifact( $sub_state['db_files'], $sub_state['db_prefix'], 'zip' );
            if ( empty( $db_artifact ) || ! file_exists( $db_artifact ) ) {
                throw new MainWP_Exception( 'The database backup archive could not be created.', null, MainWP_Backup_Error::ERR_ZIP_FAILED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message.
            }
            $artifacts[] = array(
                'file' => basename( $db_artifact ),
                'size' => (int) filesize( $db_artifact ),
                'role' => 'db',
            );
            unset( $sub_state['db_files'] );
        }

        foreach ( $sub_state['completed_parts'] as $part ) {
            $artifacts[] = array(
                'file' => $part['file'],
                'size' => (int) $part['size'],
                'role' => 'part',
            );
        }

        // The manifest is the top-level artifact: it is small, always
        // present, and what the next run in the chain needs first.
        $sub_state['artifact']  = array(
            'file' => $sub_state['manifest']['file'],
            'size' => (int) $sub_state['manifest']['size'],
        );
        $sub_state['artifacts'] = $artifacts;

        return $this->advance_phase( $task, $version, $sub_state, $backup_dir );
    }

    /**
     * Pack changes.list into rotating per-component parts, resuming from
     * the cursor. The cursor's open part is verified before any append: a
     * part killed mid-write has no central directory, so a failed verify
     * discards the part and rewinds the cursor to the part's first file.
     *
     * @param array  $sub_state  Cursor.
     * @param int    $deadline   Slice deadline.
     * @param string $backup_dir Scratch directory.
     *
     * @throws MainWP_Exception When changes.list cannot be read.
     *
     * @return array { done: bool, sub_state: array }
     */
    protected function pack_incremental_changes( $sub_state, $deadline, $backup_dir ) { // phpcs:ignore -- NOSONAR - resumable pack loop.
        $cursor    = $sub_state['pack_cursor'];
        $completed = isset( $sub_state['completed_parts'] ) && is_array( $sub_state['completed_parts'] ) ? $sub_state['completed_parts'] : array();
        $dir       = rtrim( $backup_dir, '/' ) . '/';

        $handle = fopen( $dir . MainWP_Child_Backup_Incremental_Diff::CHANGES_LIST, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- streamed list read.
        if ( false === $handle ) {
            throw new MainWP_Exception( 'The change list for the incremental backup could not be read.', null, MainWP_Backup_Error::ERR_FILE_CORRUPT ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message.
        }

        $writer = null;
        if ( '' !== $cursor['component'] && $cursor['part_index'] > 0 ) {
            $writer = new MainWP_Child_Backup_Incremental_Zip_Writer( $backup_dir, $cursor['component'] );
            if ( $cursor['part_entry_count'] > 0 && ! $writer->open_for_append( $cursor['part_index'], $cursor['part_entry_count'] ) ) {
                // Torn part - rebuild it from its first file.
                $cursor['line']             = $cursor['part_start_line'];
                $cursor['part_entry_count'] = 0;
                $writer->open_new_part( $cursor['part_index'] );
            } elseif ( 0 === (int) $cursor['part_entry_count'] ) {
                $writer->open_new_part( $cursor['part_index'] );
            }
        }

        $line_no = 0;
        while ( $line_no < $cursor['line'] && false !== fgets( $handle ) ) {
            ++$line_no;
        }

        $done = true;
        while ( true ) {
            if ( $this->pack_budget_exhausted( $deadline ) ) {
                if ( null !== $writer && $writer->is_open() ) {
                    // Close the part so the checkpointed entry count has a
                    // central directory behind it; the next slice appends.
                    $cursor['part_entry_count'] = $writer->current_entries();
                    $writer->close_part();
                }
                $done = false;
                break;
            }

            $line = fgets( $handle );
            if ( false === $line ) {
                break;
            }
            $line = rtrim( $line, "\r\n" );
            if ( '' === $line ) {
                ++$line_no;
                $cursor['line'] = $line_no;
                continue;
            }

            $fields  = explode( "\t", $line );
            $relpath = $fields[0];

            $component = MainWP_Child_Backup_File_Walker::classify_component( $relpath );
            if ( null === $writer || ! $writer->is_open() || $component !== $cursor['component'] ) {
                if ( null !== $writer && $writer->is_open() ) {
                    $record = $writer->close_part();
                    if ( null !== $record && $record['entries'] > 0 ) {
                        $completed[]                                  = $record;
                        $cursor['next_index'][ $cursor['component'] ] = $record['index'] + 1;
                    }
                }
                $index  = isset( $cursor['next_index'][ $component ] ) ? (int) $cursor['next_index'][ $component ] : 1;
                $writer = new MainWP_Child_Backup_Incremental_Zip_Writer( $backup_dir, $component );
                $writer->open_new_part( $index );
                $cursor['component']        = $component;
                $cursor['part_index']       = $index;
                $cursor['part_start_line']  = $line_no;
                $cursor['part_entry_count'] = 0;
            }

            $added = $writer->add_file( $this->site_root() . $relpath, $relpath );
            if ( $added['rotated'] && null !== $added['closed'] ) {
                $completed[]                                  = $added['closed'];
                $cursor['next_index'][ $cursor['component'] ] = $added['closed']['index'] + 1;
                $cursor['part_index']                         = $writer->current_index();
                $cursor['part_start_line']                    = $line_no;
                $cursor['part_entry_count']                   = 0;
            }

            ++$line_no;
            $cursor['line'] = $line_no;
        }

        fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- streamed list read.

        if ( $done ) {
            if ( null !== $writer && $writer->is_open() ) {
                $record = $writer->close_part();
                if ( null !== $record && $record['entries'] > 0 ) {
                    $completed[] = $record;
                }
            }
            $cursor['component']        = '';
            $cursor['part_index']       = 0;
            $cursor['part_entry_count'] = 0;
        }

        $sub_state['pack_cursor']     = $cursor;
        $sub_state['completed_parts'] = $completed;

        return array(
            'done'      => $done,
            'sub_state' => $sub_state,
        );
    }

    /**
     * Locate the parent manifest the diff runs against: an inline push
     * from the Dashboard wins, then the child-local sidecar from the last
     * completed run. The inline copy is cleared from the cursor as soon as
     * it lands on disk - it can be megabytes.
     *
     * @param array  $sub_state  Cursor (modified: inline manifest cleared).
     * @param string $backup_dir Scratch directory.
     *
     * @throws MainWP_Exception When no usable parent manifest exists - the
     *                          Dashboard reruns the site as a full backup.
     *
     * @return string Parent manifest path.
     */
    protected function resolve_parent_manifest( &$sub_state, $backup_dir ) {
        $dir = rtrim( $backup_dir, '/' ) . '/';

        $b64 = isset( $sub_state['params']['parent_manifest_b64'] ) ? $sub_state['params']['parent_manifest_b64'] : '';
        if ( '' !== $b64 ) {
            unset( $sub_state['params']['parent_manifest_b64'] );
            $decoded = base64_decode( $b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- wire transport encoding for the pushed manifest, not code.
            if ( false !== $decoded && '' !== $decoded ) {
                $parent = $dir . 'parent-files.list';
                if ( false !== file_put_contents( $parent, $decoded ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- scratch-dir write.
                    return $parent;
                }
            }
        }

        $local = $dir . MainWP_Child_Backup_File_Walker::FILES_LIST;
        if ( file_exists( $local ) ) {
            return $local;
        }

        throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_INCREMENTAL_BASE_MISSING ) ), null, MainWP_Backup_Error::ERR_INCREMENTAL_BASE_MISSING ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
    }

    /**
     * Diff the current walk against the parent manifest, choosing the
     * in-memory map or the bounded-memory sorted merge by parent size.
     *
     * @param string $parent_path     Parent files.list path.
     * @param string $current_path    Current walk's staged files.list path.
     * @param string $changes_path    changes.list output path.
     * @param string $tombstones_path tombstones.list output path.
     *
     * @return array { changes: int, tombstones: int, force_full: bool }
     */
    protected function run_incremental_diff( $parent_path, $current_path, $changes_path, $tombstones_path ) {
        $diff             = new MainWP_Child_Backup_Incremental_Diff();
        $parent_had_files = file_exists( $parent_path ) && filesize( $parent_path ) > 0;

        // Past ~100k parent entries (~4 MiB of list) the per-file map
        // presses on shared-host memory limits - use the sorted merge.
        if ( $parent_had_files && filesize( $parent_path ) > 4 * 1024 * 1024 ) {
            $merged = $diff->sorted_merge_diff( $parent_path, $current_path, $changes_path, $tombstones_path );
            return array(
                'changes'    => (int) $merged['changes'],
                'tombstones' => (int) $merged['tombstones'],
                'force_full' => empty( $merged['valid'] ),
            );
        }

        $loaded = $diff->load_parent_map( $parent_path );
        if ( $diff->should_force_full( $loaded, $parent_had_files ) ) {
            return array(
                'changes'    => 0,
                'tombstones' => 0,
                'force_full' => true,
            );
        }

        $counts = $diff->stream_diff( $current_path, $loaded['map'], $changes_path, $tombstones_path );
        return array(
            'changes'    => (int) $counts['changes'],
            'tombstones' => (int) $counts['tombstones'],
            'force_full' => false,
        );
    }

    /**
     * Root the pack loop resolves change relpaths against.
     *
     * @return string Site root with a trailing slash.
     */
    protected function site_root() {
        return ABSPATH;
    }

    /**
     * Whether the pack loop should checkpoint and yield.
     *
     * @param int $deadline Slice deadline (unix time).
     *
     * @return bool True when the budget is exhausted.
     */
    protected function pack_budget_exhausted( $deadline ) {
        return time() >= $deadline - 2;
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

        $sub_state = $this->stamp_progress( $task, $sub_state, $backup_dir );

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
            if ( MainWP_Child_Backup_Task::PHASE_COMPLETED === $next && ! empty( $sub_state['manifest'] ) ) {
                // The run that emitted staged manifest sidecars finished -
                // promote them as the snapshot the next incremental diffs
                // against. A failed run never reaches this, so the previous
                // snapshot's manifest stays usable.
                $this->promote_manifest_sidecars( $backup_dir );
            }
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
        $sub_state  = $this->stamp_progress( $task, $sub_state, $backup_dir );
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
     * Stamp a progress snapshot onto the cursor before it is persisted.
     *
     * The snapshot rides the existing checkpoint writes, so it costs no
     * extra database round trips. Counters appear only where the work
     * exposes them cheaply: tier-2 dumps report table counters from the
     * cursor, the file phase reports the growing archive size, and a
     * finished artifact reports its final size.
     *
     * @param object $task       Task row.
     * @param array  $sub_state  Cursor about to be persisted.
     * @param string $backup_dir Scratch directory.
     *
     * @return array Cursor with the progress key refreshed.
     */
    protected function stamp_progress( $task, $sub_state, $backup_dir ) {
        $progress = $this->progress_snapshot( $task, $sub_state, $backup_dir );
        if ( ! empty( $progress ) ) {
            $sub_state['progress'] = $progress;
        }
        return $sub_state;
    }

    /**
     * Build the progress snapshot for the current phase.
     *
     * @param object $task       Task row.
     * @param array  $sub_state  Cursor.
     * @param string $backup_dir Scratch directory.
     *
     * @return array Progress counters; empty when nothing is known.
     */
    protected function progress_snapshot( $task, $sub_state, $backup_dir ) {
        if ( isset( $sub_state['artifact']['file'], $sub_state['artifact']['size'] ) ) {
            return array(
                'current_file' => (string) $sub_state['artifact']['file'],
                'bytes_done'   => (int) $sub_state['artifact']['size'],
                'bytes_total'  => (int) $sub_state['artifact']['size'],
            );
        }

        $progress = array();

        if ( MainWP_Child_Backup_Task::PHASE_DUMPING_DB === $task->phase
            && isset( $sub_state['db_cursor']['tables'] ) && is_array( $sub_state['db_cursor']['tables'] ) ) {
            $done                        = isset( $sub_state['db_cursor']['index'] ) ? (int) $sub_state['db_cursor']['index'] : 0;
            $progress['files_total']     = count( $sub_state['db_cursor']['tables'] );
            $progress['files_processed'] = $done;
            if ( isset( $sub_state['db_cursor']['tables'][ $done ] ) ) {
                $progress['current_file'] = (string) $sub_state['db_cursor']['tables'][ $done ];
            }
        }

        if ( MainWP_Child_Backup_Task::PHASE_ARCHIVING_FILES === $task->phase && ! empty( $sub_state['archive_file'] ) ) {
            $progress['current_file'] = (string) $sub_state['archive_file'];
            $partial                  = rtrim( $backup_dir, '/' ) . '/' . $sub_state['archive_file'];
            if ( file_exists( $partial ) ) {
                $progress['bytes_done'] = (int) filesize( $partial );
            }
        }

        return $progress;
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
     * Emit the manifest sidecars for the tree this backup covered.
     *
     * @param array  $params     Backup params.
     * @param string $backup_dir Scratch directory.
     *
     * @return array Emission result with file/size/count.
     */
    protected function emit_manifest_sidecars( $params, $backup_dir ) {
        $walker = new MainWP_Child_Backup_File_Walker(
            null,
            isset( $params['excludes'] ) && is_array( $params['excludes'] ) ? $params['excludes'] : array(),
            ! empty( $params['excludezip'] ),
            ! empty( $params['excludenonwp'] ),
            array( $backup_dir )
        );
        return $walker->emit_sidecars( $backup_dir );
    }

    /**
     * Promote the staged manifest sidecars to their final names.
     *
     * @param string $backup_dir Scratch directory.
     *
     * @return bool Promotion result.
     */
    protected function promote_manifest_sidecars( $backup_dir ) {
        return MainWP_Child_Backup_File_Walker::promote_sidecars( $backup_dir );
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
