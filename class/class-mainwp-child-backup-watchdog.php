<?php
/**
 * MainWP Child Backup Watchdog (Phase 1G).
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
        add_filter( 'cron_schedules', array( $this, 'register_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval -- ~120s re-arm is the 1G design; the watchdog is a fallback driver, not a poller.
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
     */
    public function disarm() {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( false !== $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
        }
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
                $spine->checkpoint( $task->task_id, $version, array( 'phase' => MainWP_Child_Backup_Task::PHASE_FAILED ) );
                continue;
            }

            if ( ! $spine->acquire_lease( $task->task_id, 'watchdog', $version, 2 * MainWP_Child_Backup_Task::SLICE_BUDGET_SECONDS ) ) {
                continue; // Another runner won the row - back off.
            }

            ++$version; // acquire_lease incremented it.
            $version = $spine->checkpoint( $task->task_id, $version, array( 'resume_count' => (int) $task->resume_count + 1 ) );
            if ( false === $version ) {
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
             */
            do_action( 'mainwp_child_backup_task_resume', $task, $version );

            $spine->release_lease( $task->task_id, 'watchdog', $version );
        }
    }
}
