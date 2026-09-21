<?php
/**
 * System Monitor Bootstrap.
 *
 * Entry point for the System Monitor module.
 *
 * Responsibilities:
 * - Load autoloader / loader
 * - Initialize System Monitor core
 *
 * @package     MainWP/Child
 */

namespace MainWP\Child\SystemMonitor;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


require_once __DIR__ . '/class-mainwp-child-system-monitor-loader.php';

// Register the autoloader immediately so activation hooks and other early
// entry points can load System Monitor classes.
MainWP_Child_System_Monitor_Loader::init();


/**
 * Bootstrap System Monitor module.
 *
 * @return void
 */
function bootstrap() {

    // Initialize the system monitor core.
    MainWP_Child_System_Monitor::init();

    // phpcs:disable WordPress.WP.CronInterval.CronSchedulesInterval -- the system monitor cron scanner is built around a one-minute tick.
    add_filter(
        'cron_schedules',
        function ( $schedules ) {
            if ( ! isset( $schedules['minute'] ) ) {
                $schedules['minute'] = array(
                    'interval' => MINUTE_IN_SECONDS,
                    'display'  => __( 'Every Minute', 'mainwp-child' ),
                );
            }
            return $schedules;
        }
    );
    // phpcs:enable WordPress.WP.CronInterval.CronSchedulesInterval
}

/**
 * Hook bootstrap into WordPress.
 *
 * Using plugins_loaded ensures:
 * - WordPress is fully loaded
 * - other plugins are available
 * - safe place for module initialization
 */
add_action( 'plugins_loaded', __NAMESPACE__ . '\\bootstrap' );
