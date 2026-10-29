<?php
/**
 * MainWP Child Backup Watchdog.
 *
 * Child-cron fallback that resumes stalled backup tasks when the Dashboard
 * continuation goes away mid-run. Child WP-Cron only fires on traffic, so the
 * watchdog is never the sole driver - the Dashboard is primary, and the
 * wall-clock deadline on the task row covers children whose cron never fires.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Child_Backup_Watchdog
 *
 * @package MainWP\Child
 */
class MainWP_Child_Backup_Watchdog { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Cron hook name.
     */
    const CRON_HOOK = 'mainwp_child_backup_watchdog';

    /**
     * Cron re-arm interval in seconds.
     */
    const INTERVAL_SECONDS = 120;

    /**
     * Base stall threshold in seconds, before the per-site jitter.
     */
    const STALL_THRESHOLD_SECONDS = 180;

    /**
     * Singleton instance.
     *
     * @var MainWP_Child_Backup_Watchdog|null
     */
    protected static $instance = null;

    /**
     * Get the singleton instance.
     *
     * @return MainWP_Child_Backup_Watchdog
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Register the cron schedule and hook.
     */
    public function init() {
        add_filter( 'cron_schedules', array( $this, 'register_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval -- the ~120s re-arm is by design; the watchdog is a fallback driver, not a poller.
        add_action( self::CRON_HOOK, array( $this, 'run' ) );
    }

    /**
     * Add the watchdog interval to the cron schedules.
     *
     * @param array $schedules Existing schedules.
     *
     * @return array Schedules including the watchdog interval.
     */
    public function register_schedule( $schedules ) {
        $schedules['mainwp_child_backup_watchdog_interval'] = array(
            'interval' => self::INTERVAL_SECONDS,
            'display'  => 'MainWP Child Backup Watchdog',
        );
        return $schedules;
    }

    /**
     * Arm the watchdog event (called when a task is created).
     */
    public function arm() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + self::INTERVAL_SECONDS, 'mainwp_child_backup_watchdog_interval', self::CRON_HOOK );
        }
    }

    /**
     * Disarm the watchdog event (no active tasks left).
     *
     * Re-reads the active tasks immediately before unscheduling. A task created
     * between run()'s empty-check and this call would arm() to a no-op (the
     * event is still scheduled) and then be left unguarded by an unconditional
     * unschedule. This shrinks - but cannot fully close - the WP-cron TOCTOU
     * window; the task's wall-clock deadline reclaim self-heals the residual
     * case where a row lands after this final read.
     */
    public function disarm() {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( false === $timestamp ) {
            return;
        }
        if ( ! empty( MainWP_Child_Backup_Task::instance()->get_active_tasks() ) ) {
            return;
        }
        wp_unschedule_event( $timestamp, self::CRON_HOOK );
    }

    /**
     * Cron entry point.
     */
    public function run() {
        $this->check_tasks();
        if ( empty( MainWP_Child_Backup_Task::instance()->get_active_tasks() ) ) {
            $this->disarm();
        }
    }

    /**
     * Stall threshold with the per-site jitter applied.
     *
     * The jitter is deterministic per child (derived from the site URL), so
     * when a dashboard managing hundreds of children dies, their watchdogs
     * re-enter spread over a minute instead of as a thundering herd.
     *
     * @return int Threshold in seconds.
     */
    public function get_stall_threshold() {
        $jitter = absint( crc32( (string) get_option( 'siteurl' ) ) ) % 61;
        return self::STALL_THRESHOLD_SECONDS + $jitter;
    }

    /**
     * Find stalled tasks and resume or fail them.
     *
     * A task at its resume cap goes terminal failed. Otherwise the watchdog
     * acquires the lease (it only wins when the lease is free or expired),
     * increments resume_count, and re-enters the task from its sub_state.
     *
     * @param int|null $threshold_seconds Stall threshold override, null for the jittered default.
     */
    public function check_tasks( $threshold_seconds = null ) {
        if ( null === $threshold_seconds ) {
            $threshold_seconds = $this->get_stall_threshold();
        }

        $spine = MainWP_Child_Backup_Task::instance();

        foreach ( $spine->find_stalled_tasks( $threshold_seconds ) as $task ) {
            $version = (int) $task->version;

            if ( (int) $task->resume_count >= (int) $task->max_resumes ) {
                // A stalled, in-flight foreign-key replay must roll the
                // half-applied live database back rather than fail outright,
                // even at the resume cap - otherwise the live tables are left
                // half-overwritten. The rollback gets fresh resume budget.
                if ( $spine->reroute_fk_replay_to_rollback( $task, $version ) ) {
                    continue;
                }

                // Out of resume attempts: record why so the Dashboard shows a
                // reason and a code instead of a bare "failed".
                $terminal_state = array(
                    'error' => array(
                        'message' => MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_STALLED ),
                        'code'    => MainWP_Backup_Error::ERR_STALLED,
                    ),
                );
                $cap_owner = '';
                if ( MainWP_Child_Backup_Task::is_restore_type( $task->type ) ) {
                    // An overrun slice re-extends its own lease without a version bump
                    // while it cleans up, so the cap takes the lease first: then neither
                    // side can drop tmp tables under the other's swap probe or report.
                    $cap_owner = substr( MainWP_Child_Backup_Task_Runner::LEASE_OWNER_WATCHDOG, 0, 27 ) . ':' . wp_generate_uuid4();
                    if ( ! $spine->acquire_lease( $task->task_id, $cap_owner, $version, 2 * MainWP_Child_Backup_Task::SLICE_BUDGET_SECONDS ) ) {
                        continue;
                    }
                    ++$version;
                }
                $capped = false;
                try {
                    if ( '' !== $cap_owner ) {
                        $terminal_state['verification']['outcome'] = MainWP_Child_Restore_Task_Runner::instance()->terminal_outcome( $task, null, 'failed', MainWP_Backup_Error::ERR_STALLED );
                    }
                    $capped = $spine->checkpoint(
                        $task->task_id,
                        $version,
                        array(
                            'phase'     => MainWP_Child_Backup_Task::PHASE_FAILED,
                            'sub_state' => $terminal_state,
                        ),
                        false
                    );
                } finally {
                    if ( '' !== $cap_owner ) {
                        $held = $spine->get_task( $task->task_id );
                        if ( null !== $held ) {
                            $spine->release_lease( $task->task_id, $cap_owner, (int) $held->version );
                        }
                    }
                }

                // A forced-terminal restore leaves tmp tables, the maintenance
                // flag, and scratch behind (the runner never ran its catch);
                // clean up using the pre-fail task. FK rollback keeps
                // maintenance on - cleanup skips it.
                if ( false !== $capped && MainWP_Child_Backup_Task::is_restore_type( $task->type ) ) {
                    MainWP_Child_Restore_Task_Runner::instance()->cleanup_terminal_task( $task );
                }

                // A forced-terminal backup never runs the runner's terminal
                // sweep. Delete the partial artifacts its cursor names - the
                // capped checkpoint above overwrote the row, so cleanup reads
                // the pre-fail $task (its sidecar survives until the delete
                // below) - plus the walk-run scratch and the sidecar.
                if ( false !== $capped && ! MainWP_Child_Backup_Task::is_restore_type( $task->type ) ) {
                    try {
                        $dirs = MainWP_Helper::get_mainwp_dir( 'backup' );
                        MainWP_Child_Backup_Task_Runner::instance()->cleanup_failed_task_artifacts( $task->task_id, $spine->read_sub_state( $task, $dirs[0] ), $dirs[0] );
                        $spine->delete_sidecar( $task->task_id, $dirs[0] );
                    } finally {
                        MainWP_Child_Backup_Mutex::release( $task->task_id );
                    }
                }
                continue;
            }

            $owner = substr( MainWP_Child_Backup_Task_Runner::LEASE_OWNER_WATCHDOG, 0, 27 ) . ':' . wp_generate_uuid4();
            if ( ! $spine->acquire_lease( $task->task_id, $owner, $version, 2 * MainWP_Child_Backup_Task::SLICE_BUDGET_SECONDS ) ) {
                continue; // Another runner won the row - back off.
            }

            ++$version; // acquire_lease incremented it.
            $version = $spine->checkpoint( $task->task_id, $version, array( 'resume_count' => (int) $task->resume_count + 1 ) );
            if ( false === $version ) {
                // The lease is held and $version is stale by definition (the
                // checkpoint failed its own CAS), so re-read the row for a
                // version the release can match. Skipping the release would
                // wedge the task until the lease expires.
                $fresh = $spine->get_task( $task->task_id );
                if ( null !== $fresh ) {
                    $spine->release_lease( $task->task_id, $owner, (int) $fresh->version );
                }
                continue;
            }

            /**
             * Fires when the watchdog re-enters a stalled backup task.
             *
             * The phase runners (dump/archive slices) subscribe to this to
             * advance one bounded slice from the task's sub_state cursor.
             *
             * @param object $task    The stalled task row (pre-resume state).
             * @param int    $version Current row version after the resume bookkeeping.
             * @param string $owner   Unique token holding the lease for this invocation.
             */
            do_action( 'mainwp_child_backup_task_resume', $task, $version, $owner );

            $spine->release_lease( $task->task_id, $owner, $version );
        }
    }
}
