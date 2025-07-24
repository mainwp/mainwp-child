<?php
/**
 * Controller: Load Manager
 *
 * @since     5.5
 * @package   mainwp/child
 */

namespace MainWP\Child\Changes;

// Exit.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * Handle for loading
 */
class Changes_Loggers_Loader {

    // views array.
    const MAINWP_CHILD_VIEWS = array(
        'mainwp_child_tab',
        'mainwp-reports-page',
        'mainwp-reports-settings',
    );

    /**
     * Cached array
     *
     * @var array
     */
    public static $loggers_handle = array();

    /**
     * Some of the need to load data / attach events earlier.
     *
     * @return void
     */
    public static function load_early_change_handle() {

        $loggers = static::get_loggers();

        foreach ( $loggers as $logger ) {
            if ( method_exists( $logger, 'before_init' ) ) {
                call_user_func_array( array( $logger, 'before_init' ), array() );
            }
        }
    }

    /**
     * Loads all the
     *
     * @return void
     */
    public static function load_change_loggers_init() {

        if ( is_admin() ) {
            global $pagenow;
            $current_page = isset( $_GET['page'] ) ? \sanitize_text_field( \wp_unslash( $_GET['page'] ) ) : false;

            if ( $current_page && (
            in_array( $current_page, self::MAINWP_CHILD_VIEWS, true ) // Views.
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

        if ( Changes_Helper::is_login_page() && ! \is_user_logged_in() ) {
            foreach ( $loggers as $key => &$logger ) {
                if ( method_exists( $logger, 'is_login_logging' ) ) {
                    $is_login_logging = call_user_func_array( array( $logger, 'is_login_logging' ), array() );

                    if ( ! $is_login_logging ) {
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
             * @param array $loggers - List of to be loaded for visitors.
             */
            $loggers = \apply_filters( 'mainwp_child_changes_logs_load_login_loggers', $loggers );
        } elseif ( Changes_Logs::is_frontend() && ! \is_user_logged_in() ) {
            // Load all the frontend.
            foreach ( $loggers as $key => &$logger ) {
                if ( method_exists( $logger, 'is_frontend_logger' ) ) {
                    $is_frontend_logger = call_user_func_array( array( $logger, 'is_frontend_logger' ), array() );

                    if ( ! $is_frontend_logger ) {
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
             * @param array $loggers - List of loggers to be loaded for visitors.
             */
            $loggers = \apply_filters( 'mainwp_child_changes_logs_load_frontend_loggers', $loggers );
        }

        foreach ( $loggers as $logger ) {
            if ( method_exists( $logger, 'init_hooks' ) ) {
                call_user_func_array( array( $logger, 'init_hooks' ), array() );
            }
        }
    }

    /**
     * Caches the classes
     *
     * @return array
     */
    public static function get_loggers() {
        if ( empty( static::$loggers_handle ) ) {
            static::$loggers_handle = \mainwp_child_changes_logs_get_handler();
        }

        return static::$loggers_handle;
    }
}
