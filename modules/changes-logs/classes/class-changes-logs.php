<?php
/**
 * Changes Logs Class
 *
 * @author  WP Activity Log plugin.
 *
 * @since 5.4.1
 *
 * @package MainWP\Child
 */

declare(strict_types=1);


namespace MainWP\Child\Changes;

use MainWP\Child\MainWP_Helper;
use MainWP\Child\Changes\Entities\Changes_Logs_Entity;
use MainWP\Child\Changes\Entities\Changes_Metadata_Entity;
use MainWP\Child\Changes\Helpers\Changes_WP_Helper;
use MainWP\Child\Changes\Helpers\Changes_Settings_Helper;


// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


if ( ! defined( 'CHANGES_LOGS_PREFIX' ) ) {
    define( 'CHANGES_LOGS_PREFIX', 'mainwp_child_changes_logs_' );
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
     * Private variable to hold the database version info.
     *
     * @var string DB version info.
     */
    protected $db_version = '1.0.2'; // NOSONAR - no IP.


    /**
     * Option name for front-end events.
     *
     * @var string
     */
    public const CHANGES_LOGS_DB_OPTION_NAME = 'mainwp_child_changes_logs_db_version';

    /**
     * Constructor.
     *
     * Run any time class is called.
     */
    public function __construct() {
        add_action( 'init', array( $this, 'init' ) );

        Changes_Loggers_Load_Manager::load_early_sensors();

        \add_action(
            'init',
            function () {
                Changes_Logs_Manager::init();
                Changes_Loggers_Load_Manager::load_loggers_init();
            },
            0
        );
    }

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
     * init
     *
     * @return void
     */
    public function init() {
        $this->install();
        Changes_Logs_Manager::init();
    }

    /**
     * Method install()
     *
     * Installs the new DB.
     *
     * @return void
     */
    public function install() { // phpcs:ignore -- NOSONAR - complex function. Current complexity is the only way to achieve desired results, pull request solutions appreciated.

        $currentVersion = get_option( static::CHANGES_LOGS_DB_OPTION_NAME );

        if ( $currentVersion === $this->db_version ) {
            return;
        }

        $sql   = array();
        $sql[] = Changes_Logs_Entity::get_entity_table_create( $currentVersion );
        $sql[] = Changes_Metadata_Entity::get_entity_table_create( $currentVersion );

        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php'; // NOSONAR - WP compatible.

        $suppress = $wpdb->suppress_errors();
        foreach ( $sql as $query ) {
            \dbDelta( $query );
        }
        $wpdb->suppress_errors( $suppress );

        MainWP_Helper::update_option( static::CHANGES_LOGS_DB_OPTION_NAME, $this->db_version );
    }


    /**
     * Load built-in loggers
     */
    public function load_loggers() {

        $loggers = \mainwp_child_changes_logs_get_classes_list( 'Loggers' );

        $loggers = apply_filters( 'mainwp_child_changes_logs_load_loggers', $loggers );

        foreach ( $loggers as $class_name ) {
            if ( ! class_exists( $class_name ) ) {
                continue;
            }
            new $class_name();
        }

        /**
         * Fires after all connectors have been registered.
         *
         * @param array      $labels     All register connectors labels array
         * @param Connectors $connectors The Connectors object
         */
        do_action( 'mainwp_child_changes_logs_after_load_loggers', $this );
    }



    /**
     * Whether the current request is a frontend request.
     *
     * @return bool
     */
    public static function is_frontend() {
        return ! is_admin()
        && ! Changes_WP_Helper::is_login_screen()
        && ( ! defined( 'WP_CLI' ) || ! \WP_CLI )
        && ( ! defined( 'DOING_CRON' ) || ! DOING_CRON )
        && ! self::is_rest_api()
        && ! self::is_admin_blocking_plugins_support_enabled();
    }

    /**
     * Whether the current request is a REST API request.
     *
     * @return bool
     *
     * @since 5.0.0
     */
    public static function is_rest_api() {
        if (
            ( defined( 'REST_REQUEST' ) && REST_REQUEST )
            || ! empty( $_GET['rest_route'] ) // phpcs:ignore
            ) {
                return true;
        }

        if ( ! get_option( 'permalink_structure' ) ) {
            return false;
        }

        /*
        * This is needed because, if called early, global $wp_rewrite is not defined but required
        * by get_rest_url(). WP will reuse what we set here, or in worst case will replace, but no
        * consequences for us in any case.
        */
        if ( empty( $GLOBALS['wp_rewrite'] ) ) {
            $GLOBALS['wp_rewrite'] = new \WP_Rewrite(); // phpcs:ignore -- WordPress.WP.GlobalVariablesOverride.Prohibited
        }

        $current_path = trim( (string) parse_url( (string) add_query_arg( array() ), PHP_URL_PATH ), '/' ) . '/'; // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
        $rest_path    = trim( (string) parse_url( (string) get_rest_url(), PHP_URL_PATH ), '/' ) . '/'; // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url

        return strpos( $current_path, $rest_path ) === 0;
    }

        /**
         * Checks if the admin blocking plugins support is enabled.
         *
         * @see https://trello.com/c/1OCd5iKc/589-wieserdk-al4mwp-cannot-retrieve-events-when-admin-url-is-changed
         * @return bool
         */
    private static function is_admin_blocking_plugins_support_enabled() {

        // Only meant for 404 pages, but may run before is_404 can be used.
        $is_404 = ! did_action( 'wp' ) || is_404();
        if ( ! $is_404 ) {
            return false;
        }

        /*
         * We assume settings have already been migrated (in version 4.1.3) to WordPress options table. We might
         * miss some 404 events until the plugin upgrade runs, but that is a very rare edge case. The same applies
         * to loading of 'admin-blocking-plugins-support' option further down.
         *
         * We do not need to worry about the missed 404s after version 4.1.5 as they were completely removed.
         */
        $is_stealth_mode = Changes_Settings_Helper::get_option_value( 'mwp-child-stealth-mode', 'no' );

        if ( 'yes' !== $is_stealth_mode ) {
            // Only intended if MainWP stealth mode is active.
            return false;
        }
    }
}
