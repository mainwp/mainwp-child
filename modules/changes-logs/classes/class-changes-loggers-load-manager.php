<?php
/**
 * Controller: Sensors Load Manager
 *
 * @since     4.5.1
 * @package   mainwp/child
 */

declare(strict_types=1);

namespace MainWP\Child\Changes;

use MainWP\Child\Changes\Helpers\Changes_WP_Helper;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * Responsible for loading Sensors
 *
 * @since 4.6.0
 */
class Changes_Loggers_Load_Manager {

    // views array.
    const WS_AL_VIEWS = array(
        'mainwp_child_tab',
        'mainwp-reports-page',
        'mainwp-reports-settings',
    );

    /**
     * Cached sensors array
     *
     * @var array
     *
     * @since 5.1.1
     */
    public static $loggers = array();

    /**
     * Some of the sensors need to load data / attach events earlier - lets give them a chance
     *
     * @return void
     *
     * @since 4.6.0
     */
    public static function load_early_sensors() {

        $loggers = static::get_loggers();

        foreach ( $loggers as $logger ) {
            if ( method_exists( $logger, 'early_init' ) ) {
                call_user_func_array( array( $logger, 'early_init' ), array() );
            }
        }
    }

    /**
     * Loads all the sensors
     *
     * @return void
     */
    public static function load_loggers_init() {

        if ( is_admin() ) {
            global $pagenow;
            // Get current page query argument via $_GET array.
            $current_page = isset( $_GET['page'] ) ? \sanitize_text_field( \wp_unslash( $_GET['page'] ) ) : false;

            // Check these conditions before loading sensors.
            if ( $current_page && (
            in_array( $current_page, self::WS_AL_VIEWS, true ) // Views.
            || 'index.php' === $pagenow  // Dashboard.
            || 'tools.php' === $pagenow  // Tools page.
            || 'export.php' === $pagenow // Export page.
            || 'import.php' === $pagenow // Import page.
            )
            ) {
                return;
            }
        }

        $loggers = static::get_loggers();

        \do_action( 'mainwp_child_changes_logs_loggers_manager_add' );

        $plugin_sensors = \mainwp_child_changes_logs_get_classes_list( 'Plugin_Loggers' );

        if ( ! empty( $plugin_sensors ) ) {
            $loggers = \array_merge( $loggers, $plugin_sensors );
        }

        if ( Changes_WP_Helper::is_login_screen() && ! \is_user_logged_in() ) {
            // Here we need to load only the Sensors which are login enabled.
            foreach ( $loggers as $key => &$logger ) {
                // Check if that sensor is for login or not.
                if ( method_exists( $logger, 'is_login_sensor' ) ) {
                    $is_login_sensor = call_user_func_array( array( $logger, 'is_login_sensor' ), array() );

                    if ( ! $is_login_sensor ) {
                        unset( $loggers[ $key ] );
                    }
                } else {
                    unset( $loggers[ $key ] );
                }
            }
            unset( $logger );

            /**
             * Filter: `mainwp_child_changes_logs_load_login_loggers`
             *
             * Filter for the list of sensors to be loaded for visitors
             * or public. No sensor is allowed to load on the front-end
             * except the ones in this array.
             *
             * @since 5.4.1
             *
             * @param array $loggers - List of sensors to be loaded for visitors.
             */
            $loggers = \apply_filters( 'mainwp_child_changes_logs_load_login_loggers', $loggers );
        } else {
            // Load all the frontend sensors.
            if ( Changes_Logs::is_frontend() && ! \is_user_logged_in() ) {
                // Here we need to load only the Sensors which are frontend enabled.
                foreach ( $loggers as $key => &$logger ) {
                    // Check if that sensor is for frontend or not.
                    if ( method_exists( $logger, 'is_frontend_sensor' ) ) {
                        $is_frontend_sensor = call_user_func_array( array( $logger, 'is_frontend_sensor' ), array() );

                        if ( ! $is_frontend_sensor ) {
                            unset( $loggers[ $key ] );
                        }
                    } else {
                        unset( $loggers[ $key ] );
                    }
                }
                unset( $logger );

                /**
                 * Filter: `mainwp_child_changes_logs_load_frontend_loggers`
                 *
                 * Filter for the list of sensors to be loaded for visitors
                 * or public. No sensor is allowed to load on the front-end
                 * except the ones in this array.
                 *
                 * @since 5.4.1
                 *
                 * @param array $loggers - List of sensors to be loaded for visitors.
                 */
                $loggers = \apply_filters( 'mainwp_child_changes_logs_load_frontend_loggers', $loggers );
            }
            // If we are on some frontend page, we don't want to load the sensors.
            if ( ! Changes_Logs::is_frontend() ) {
                // Not a frontend page? Let remove the ones which are not frontend enabled.
                foreach ( $loggers as $key => &$logger ) {
                    // Check if that sensor is for frontend only or not.
                    if ( method_exists( $logger, 'is_frontend_only_sensor' ) ) {
                        $is_frontend_only_sensor = call_user_func_array( array( $logger, 'is_frontend_only_sensor' ), array() );

                        if ( $is_frontend_only_sensor ) {
                            unset( $loggers[ $key ] );
                        }
                    }
                }
                unset( $logger );
            }
        }

        foreach ( $loggers as $logger ) {
            if ( method_exists( $logger, 'init' ) ) {
                call_user_func_array( array( $logger, 'init' ), array() );
            }
        }
    }

    /**
     * Caches the sensors classes
     *
     * @return array
     *
     * @since 5.1.1
     */
    public static function get_loggers() {
        if ( empty( static::$loggers ) ) {
            static::$loggers = \mainwp_child_changes_logs_get_classes_list( 'Loggers' );
        }

        return static::$loggers;
    }
}
