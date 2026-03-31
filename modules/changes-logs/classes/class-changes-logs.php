<?php
/**
 * Changes Logs Class
 *
 * @since 5.5
 *
 * @package MainWP\Child
 */

namespace MainWP\Child\Changes;

use MainWP\Child\MainWP_Helper;

// Exit.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Changes_Logs
 */
class Changes_Logs {

    /**
     * Private static variable to hold the single instance of the class.
     *
     * @static
     *
     * @var mixed Default null
     */
    private static $instance = null;


    /**
     * Method instance()
     *
     * Return public static instance.
     *
     * @static
     * @return Changes_Logs
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Constructor.
     *
     * Run any time class is called.
     */
    public function __construct() {
        if ( get_option( 'mainwp_child_changes_logs_enabled', true ) ) {
            add_action( 'init', array( $this, 'init_hooks' ), 0 );
            add_action( 'init', array( $this, 'init' ) );
            Changes_Loggers_Loader::load_early_change_handle();
        }
    }

    /**
     * Init hooks.
     *
     * @return void
     */
    public function init_hooks() {
        Changes_Logs_Logger::init_hooks();
        Changes_Loggers_Loader::load_change_loggers_init();
    }

    /**
     * Init.
     *
     * @return void
     */
    public function init() {
        Changes_Logs_DB_Log::instance()->install();
        $this->check_and_clean();
    }


    /**
     * Method check_and_clean.
     */
    public function check_and_clean() {

        if ( get_transient( 'mainwp_child_changes_log_clean_check' ) ) {
            return;
        }
        set_transient( 'mainwp_child_changes_log_clean_check', 1, 300 ); // 5 min lock.

        $checked_clean = get_option( 'mainwp_child_changes_log_clean_lasttime' );

        $last_datetime = false;
        $end_datetime  = gmdate( 'Y-m-d 23:59:59' );

        if ( empty( $checked_clean ) ) {
            // Set to end of today → wait until tomorrow.
            $last_datetime = $end_datetime;
            $checked_clean = $last_datetime;
        }

        $last_clean_time = strtotime( $checked_clean );

        if ( false === $last_clean_time ) {
            // fallback: also wait until next day.
            $last_datetime   = $end_datetime;
            $last_clean_time = strtotime( $last_datetime );
        }

        $today_utc = strtotime( 'today UTC' );

        if ( $last_clean_time < $today_utc ) {
            Changes_Logs_DB_Log::instance()->perform_clean_records();
            $last_datetime = gmdate( 'Y-m-d H:i:s' );
        }

        if ( ! empty( $last_datetime ) ) {
            MainWP_Helper::update_option(
                'mainwp_child_changes_log_clean_lasttime',
                $last_datetime
            );
        }
    }

    /**
     * Load built-in loggers
     */
    public function load_loggers() {

        $loggers = \mainwp_child_changes_logs_get_handler();

        $loggers = apply_filters( 'mainwp_child_changes_logs_load_loggers', $loggers );

        foreach ( $loggers as $class_name ) {
            if ( ! class_exists( $class_name ) ) {
                continue;
            }
            new $class_name(); // NOSONAR --instance class.
        }

        do_action( 'mainwp_child_changes_logs_after_load_loggers', $this );
    }

    /**
     * Whether the current request is a frontend request.
     *
     * @return bool
     */
    public static function is_frontend() {
        return ! is_admin()
        && ! Changes_Helper::is_login_screen()
        && ( ! defined( 'WP_CLI' ) || ! \WP_CLI )
        && ( ! defined( 'DOING_CRON' ) || ! DOING_CRON )
        && ! mainwp_is_rest_api();
    }
}
