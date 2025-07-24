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
        add_action( 'init', array( $this, 'init_hooks' ), 0 );
        add_action( 'init', array( $this, 'init' ) );
        Changes_Loggers_Loader::load_early_change_handle();
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
        add_action( 'mainwp_child_actions_data_clean', array( Changes_Logs_DB_Log::instance(), 'hook_remove_records' ), 10, 2 );
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
            new $class_name();
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
