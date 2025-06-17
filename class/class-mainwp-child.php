<?php
/**
 * MainWP Child
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

// phpcs:disable -- required for debugging.
if ( isset( $_REQUEST['mainwpsignature'] ) && ( ! defined('MAINWP_CHILD_DEBUG') || false === MAINWP_CHILD_DEBUG ) ) {
    ini_set( 'display_errors', false );
    error_reporting( 0 );
}

// phpcs:enable

require_once ABSPATH . '/wp-admin/includes/file.php'; // NOSONAR - WP compatible.
require_once ABSPATH . '/wp-admin/includes/plugin.php'; // NOSONAR - WP compatible.

/**
 * Class MainWP_Child
 *
 * Manage all MainWP features.
 */
class MainWP_Child {

    /**
     * Public static variable containing the latest MainWP Child plugin version.
     *
     * @var string MainWP Child plugin version.
     */
    public static $version = '5.4.0.10'; // NOSONAR - not IP.

    /**
     * Private variable containing the latest MainWP Child update version.
     *
     * @var string MainWP Child update version.
     */
    private $update_version = '1.6';

    /**
     * Public variable containing the MainWP Child plugin slug.
     *
     * @var string MainWP Child plugin slug.
     */
    public $plugin_slug;

    /**
     * MainWP_Child constructor.
     *
     * Run any time class is called.
     *
     * @param resource $plugin_file MainWP Child plugin file.
     *
     * @uses \MainWP\Child\MainWP_Child_Branding::save_branding_options()
     * @uses \MainWP\Child\MainWP_Child_Plugins_Check::instance()
     * @uses \MainWP\Child\MainWP_Child_Themes_Check::instance()
     * @uses \MainWP\Child\MainWP_Child_Updates::get_instance()
     * @uses \MainWP\Child\MainWP_Client_Report::init()
     * @uses \MainWP\Child\MainWP_Clone::init()
     * @uses \MainWP\Child\MainWP_Connect::check_other_auth()
     * @uses \MainWP\Child\MainWP_Pages::init()
     * @uses \MainWP\Child\MainWP_Helper::update_option()
     * @uses \MainWP\Child\MainWP_Utility::run_saved_snippets()
     * @uses \MainWP\Child\MainWP_Utility::get_class_name()
     */
    /**
     * MainWP_Child constructor.
     *
     * Minimal constructor that only sets up the plugin slug and loads options.
     * The actual initialization is done in init_full() or init_frontend_only().
     *
     * @param resource $plugin_file MainWP Child plugin file.
     */
    public function __construct( $plugin_file ) {
        $this->update();
        $this->load_all_options();
        $this->plugin_slug = plugin_basename( $plugin_file );
    }

    /**
     * Method init_full()
     *
     * Initialize full functionality for admin, cron, WP CLI, or API requests.
     * This method is called via the plugins_loaded hook when in admin area or during API requests.
     */
    public function init_full() {
        // Register essential hooks.
        $this->register_essential_hooks();

        // Initialize premium plugin detection.
        $this->init_premium_plugin_detection();

        // Initialize essential components.
        MainWP_Pages::get_instance()->init();

        // Initialize admin-specific components.
        $this->init_admin_components();

        // Initialize connection and core features.
        $this->init_core_features();

        // Run saved snippets - this is essential functionality.
        MainWP_Utility::instance()->run_saved_snippets();

        // Initialize special environments (WP CLI, disconnected sites, cron).
        $this->init_special_environments();

        // Register essential actions and filters.
        $this->register_essential_actions_filters();
    }

    /**
     * Register essential hooks for the plugin.
     */
    private function register_essential_hooks() {
        add_action( 'template_redirect', array( $this, 'template_redirect' ) );
        add_action( 'activated_plugin', array( $this, 'hook_activated_plugin' ) );
        add_action( 'init', array( &$this, 'init_check_login' ), 1 );
        add_action( 'init', array( &$this, 'parse_init' ), 9999 );
        add_action( 'init', array( &$this, 'localization' ), 33 );
        add_action( 'init', array( &$this, 'init_hooks' ), 9 );
        add_action( 'admin_init', array( &$this, 'admin_init' ) );
        add_action( 'plugin_action_links_mainwp-child/mainwp-child.php', array( &$this, 'plugin_settings_link' ) );
    }

    /**
     * Initialize premium plugin detection hooks.
     */
    private function init_premium_plugin_detection() {
        if ( is_admin() && function_exists( 'get_current_screen' ) ) {
            $screen = get_current_screen();
            if ( $screen && ( 'plugins' === $screen->id || 'update-core' === $screen->id ) ) {
                add_action( 'pre_current_active_plugins', array( MainWP_Child_Updates::get_instance(), 'detect_premium_themesplugins_updates' ) );
                add_action( 'core_upgrade_preamble', array( MainWP_Child_Updates::get_instance(), 'detect_premium_themesplugins_updates' ) );
            }
        }
    }

    /**
     * Initialize admin-specific components.
     */
    private function init_admin_components() {
        if ( is_admin() ) {
            // Update plugin version in database.
            MainWP_Helper::update_option( 'mainwp_child_plugin_version', static::$version, 'yes' );

            // Initialize pages and asset loading.
            MainWP_Pages::get_instance()->init();
            MainWP_Child_Assets::instance()->init();

            // Initiate MainWP Cache Control class only when needed.
            MainWP_Child_Cache_Purge::instance();

            // Initiate MainWP Child API Backups class.
            MainWP_Child_Api_Backups::instance();

            if ( is_admin() ) {
                MainWP_Helper::update_option( 'mainwp_child_plugin_version', static::$version, 'yes' );
            }
        }
    }

    /**
     * Initialize connection and core features.
     */
    private function init_core_features() {
        // Initialize connection-related components.
        MainWP_Connect::instance()->check_other_auth();

        // Initialize core features.
        MainWP_Clone::instance()->init();
        MainWP_Client_Report::instance()->init();
        MainWP_Child_Vulnerability_Checker::instance();

        // These are only needed in admin area and are performance-intensive.
        if ( is_admin() ) {
            MainWP_Child_Plugins_Check::instance();
            MainWP_Child_Themes_Check::instance();
        }
    }

    /**
     * Initialize special environments (WP CLI, disconnected sites, cron).
     */
    private function init_special_environments() {
        // WP CLI support.
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            MainWP_Child_WP_CLI_Command::init();
        }

        // Initiate Branding Options - essential for disconnected sites.
        if ( ! get_option( 'mainwp_child_pubkey' ) ) {
            MainWP_Child_Branding::instance()->save_branding_options( 'branding_disconnected', 'yes' );
        }

        // Support for cron jobs.
        $this->setup_cron_jobs();
    }

    /**
     * Register essential actions and filters for security and data handling.
     */
    private function register_essential_actions_filters() {
        // Essential action to response data result.
        add_action( 'mainwp_child_write', array( MainWP_Helper::class, 'write' ) );

        // Essential filters for security.
        add_filter( 'mainwp_child_create_action_nonce', array( MainWP_Utility::class, 'hook_create_nonce_action' ), 10, 2 );
        // Note: Filter name has a typo ('acion' instead of 'action') but kept for backward compatibility.
        add_filter( 'mainwp_child_verify_authed_acion_nonce', array( MainWP_Utility::class, 'hook_verify_authed_action_nonce' ), 10, 2 );
        add_filter( 'mainwp_child_get_ping_nonce', array( MainWP_Utility::class, 'hook_get_ping_nonce' ), 10, 2 );
        add_filter( 'mainwp_child_get_encrypted_option', array( MainWP_Child_Keys_Manager::class, 'hook_get_encrypted_option' ), 10, 3 );
    }

    /**
     * Method init_frontend_only()
     *
     * Initialize minimal functionality for frontend requests.
     * This method is called via the plugins_loaded hook when on frontend pages.
     */
    public function init_frontend_only() {
        // Only register the absolute minimum hooks needed for frontend.
        add_action( 'template_redirect', array( $this, 'template_redirect' ) );
        add_action( 'init', array( &$this, 'localization' ), 33 );

        // Run saved snippets - this is essential functionality that might be needed on frontend.
        MainWP_Utility::instance()->run_saved_snippets();

        // Essential action to response data result.
        add_action( 'mainwp_child_write', array( MainWP_Helper::class, 'write' ) );
    }

    /**
     * Method load_all_options()
     *
     * Load all MainWP Child plugin options with optimized caching.
     *
     * @return array|bool Return array of options or false on failure.
     */
    public function load_all_options() {
        // Check if options are already cached in our custom cache group.
        $cached_options = wp_cache_get( 'mainwp_child_all_options', 'mainwp' );
        if ( false !== $cached_options ) {
            return $cached_options;
        }

        // Get WordPress core cache values.
        list( $alloptions, $notoptions ) = $this->get_wp_cache_values();

        // Define the options we need.
        $mainwp_options = $this->get_required_options_list();

        // Check if we need to query the database.
        $need_db_query = ! is_array( $alloptions ) || ! isset( $alloptions['mainwp_child_pubkey'] );

        if ( $need_db_query ) {
            // Query the database for our options.
            $alloptions = $this->query_options_from_database( $alloptions, $notoptions, $mainwp_options );
        }

        // Cache the results in our custom cache group for faster future access.
        wp_cache_set( 'mainwp_child_all_options', $alloptions, 'mainwp', 60 ); // Cache for 60 seconds.

        return $alloptions;
    }

    /**
     * Get WordPress core cache values for options.
     *
     * @return array Array containing alloptions and notoptions.
     */
    private function get_wp_cache_values() {
        if ( ! defined( 'WP_INSTALLING' ) || ! is_multisite() ) {
            $alloptions = wp_cache_get( 'alloptions', 'options' );
            $notoptions = wp_cache_get( 'notoptions', 'options' );
        } else {
            $alloptions = false;
            $notoptions = false;
        }

        return array( $alloptions, $notoptions );
    }

    /**
     * Get the list of required MainWP Child options.
     *
     * @return array List of option names.
     */
    private function get_required_options_list() {
        return array(
            'mainwp_child_auth',
            'mainwp_child_reports_db',
            'mainwp_child_pluginDir',
            'mainwp_updraftplus_hide_plugin',
            'mainwp_backwpup_ext_enabled',
            'mainwp_child_server',
            'mainwp_pagespeed_hide_plugin',
            'mainwp_child_clone_permalink',
            'mainwp_child_restore_permalink',
            'mainwp_ext_snippets_enabled',
            'mainwp_child_pubkey',
            'mainwp_security',
            'mainwp_backupwordpress_ext_enabled',
            'mainwp_pagespeed_ext_enabled',
            'mainwp_linkschecker_ext_enabled',
            'mainwp_child_branding_settings',
            'mainwp_child_plugintheme_days_outdate',
            'mainwp_wp_staging_ext_enabled',
            'mainwp_child_connected_admin',
            'mainwp_child_actions_saved_number_of_days',
            'mainwp_child_pingnonce',
        );
    }

    /**
     * Query options from the database and update caches.
     *
     * @param array $alloptions    Current options array.
     * @param array $notoptions    Current notoptions array.
     * @param array $mainwp_options List of options to query.
     *
     * @return array Updated options array.
     */
    private function query_options_from_database( $alloptions, $notoptions, $mainwp_options ) {
        global $wpdb;

        // Prepare and execute a single optimized query.
        $suppress = $wpdb->suppress_errors();

        // Use individual queries for each option to avoid SQL injection warnings.
        // This is more verbose but safer and avoids code scanner false positives.
        // Direct database access is necessary here for performance optimization
        // as we need to efficiently retrieve multiple options at once.
        // WordPress's get_option() would result in multiple separate queries.
        $alloptions_db = array();

        foreach ( $mainwp_options as $option ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $result = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT option_name, option_value FROM $wpdb->options WHERE option_name = %s",
                    $option
                )
            );

            if ( $result ) {
                $alloptions_db[] = $result;
            }
        }

        $wpdb->suppress_errors( $suppress );

        if ( ! is_array( $alloptions ) ) {
            $alloptions = array();
        }

        if ( is_array( $alloptions_db ) ) {
            // Process the results and update caches.
            $this->process_query_results( $alloptions, $notoptions, $alloptions_db, $mainwp_options );
        }

        return $alloptions;
    }

    /**
     * Process database query results and update caches.
     *
     * @param array $alloptions    Current options array.
     * @param array $notoptions    Current notoptions array.
     * @param array $alloptions_db Database query results.
     * @param array $mainwp_options List of options that were queried.
     */
    private function process_query_results( &$alloptions, &$notoptions, $alloptions_db, $mainwp_options ) {
        foreach ( (array) $alloptions_db as $o ) {
            $alloptions[ $o->option_name ] = $o->option_value;
            $key                           = array_search( $o->option_name, $mainwp_options, true );
            if ( false !== $key ) {
                unset( $mainwp_options[ $key ] );
            }
        }

        if ( ! is_array( $notoptions ) ) {
            $notoptions = array();
        }

        foreach ( $mainwp_options as $option ) {
            $notoptions[ $option ] = true;
        }

        if ( ! defined( 'WP_INSTALLING' ) || ! is_multisite() ) {
            wp_cache_set( 'alloptions', $alloptions, 'options' );
            wp_cache_set( 'notoptions', $notoptions, 'options' );
        }
    }

    /**
     * Method update()
     *
     * Update the MainWP Child plugin version (mainwp_child_update_version) option.
     * Optimized with caching to avoid unnecessary database queries.
     *
     * @return void
     *
     * @uses \MainWP\Child\MainWP_Helper::update_option()
     */
    public function update() {
        // Try to get from cache first.
        $cache_key      = 'mainwp_child_update_version';
        $update_version = wp_cache_get( $cache_key, 'mainwp' );

        // If not in cache, get from database.
        if ( false === $update_version ) {
            $update_version = get_option( $cache_key );
            // Cache the result for future checks.
            wp_cache_set( $cache_key, $update_version, 'mainwp', 3600 ); // Cache for 1 hour.
        }

        // If version is current, return early.
        if ( $update_version === $this->update_version ) {
            return;
        }

        // Handle legacy version upgrades.
        if ( version_compare( $update_version, '1.6', '<' ) ) {
            delete_option( 'mainwp_child_subpages' );
            wp_cache_delete( 'mainwp_child_subpages', 'options' );
        }

        // Update the version in database and cache.
        // Use update_option directly to avoid static access warning.
        update_option( $cache_key, $this->update_version, 'yes' );

        // Also update the cache for faster future access.
        wp_cache_set( $cache_key, $this->update_version, 'mainwp', 3600 ); // Cache for 1 hour.
    }

    /**
     * Method localization()
     *
     * Load the MainWP Child plugin textdomains.
     */
    public function localization() {
        load_plugin_textdomain( 'mainwp-child', false, dirname( dirname( plugin_basename( __FILE__ ) ) ) . '/languages/' );
    }

    /**
     * Method template_redirect()
     *
     * Handle the template redirect for 404 maintenance alerts.
     */
    public function template_redirect() {
        MainWP_Utility::instance()->send_maintenance_alert();
    }

    /**
     * Method hook_activated_plugin()
     *
     * @param  mixed $plugin plugin.
     * @return void
     */
    public function hook_activated_plugin( $plugin ) {
        if ( plugin_basename( MAINWP_CHILD_FILE ) === $plugin && ( ! defined( 'DOING_CRON' ) || ! DOING_CRON ) && ( ! empty( $_GET['action'] ) && 'activate' === wp_unslash( $_GET['action'] ) ) ) { //phpcs:ignore -- NOSONAR -ok.
            $branding = MainWP_Child_Branding::instance()->is_branding();
            if ( ! $branding && function_exists( '\get_current_screen' ) ) {
                $screen = get_current_screen();
                // Check if the current screen is the Plugins page.
                if ( $screen && 'plugins' === $screen->id ) {
                    wp_safe_redirect( 'options-general.php?page=mainwp_child_tab' );
                    exit();
                }
            }
        }
    }

    /**
     * Method parse_init()
     *
     * Parse the init hook.
     *
     * @return void
     *
     * @uses \MainWP\Child\MainWP_Child_Callable::init_call_functions()
     * @uses \MainWP\Child\MainWP_Clone::request_clone_funct()
     * @uses \MainWP\Child\MainWP_Connect::parse_login_required()
     * @uses \MainWP\Child\MainWP_Connect::register_site()
     * @uses \MainWP\Child\MainWP_Connect::auth()
     * @uses \MainWP\Child\MainWP_Connect::parse_init_auth()
     * @uses \MainWP\Child\MainWP_Security::fix_all()
     * @uses \MainWP\Child\MainWP_Utility::fix_for_custom_themes()
     */
    public function parse_init() {
        // phpcs:disable WordPress.Security.NonceVerification
        if ( isset( $_REQUEST['cloneFunc'] ) ) {
            $valid_clone = MainWP_Clone::instance()->request_clone_funct();
            if ( ! $valid_clone ) {
                return;
            }
        }

        // if login required.
        if ( isset( $_REQUEST['login_required'] ) && ( '1' === $_REQUEST['login_required'] ) && isset( $_REQUEST['user'] ) ) {
            $valid_login_required = MainWP_Connect::instance()->parse_login_required();
            // return if login required are not valid, if login is valid will redirect to admin side.
            if ( ! $valid_login_required ) {
                return;
            }
        }

        MainWP_Security::fix_all();

        // Register does not require auth, so we register here.
        if ( isset( $_POST['function'] ) && 'register' === $_POST['function'] ) {
            MainWP_Helper::maybe_set_doing_cron();
            MainWP_Utility::fix_for_custom_themes();
            MainWP_Connect::instance()->register_site(); // register the site and exit.
        }

        $mainwpsignature = isset( $_POST['mainwpsignature'] ) ? rawurldecode( wp_unslash( $_POST['mainwpsignature'] ) ) : ''; //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $function        = isset( $_POST['function'] ) ? sanitize_text_field( wp_unslash( $_POST['function'] ) ) : null;
        $nonce           = MainWP_System::instance()->validate_params( 'nonce' );

        // phpcs:enable

        // Authenticate here.
        $auth = MainWP_Connect::instance()->auth( $mainwpsignature, $function, $nonce );

        // Parse auth, if it is not correct actions then exit with message or return.
        if ( ! MainWP_Connect::instance()->parse_init_auth( $auth ) ) {
            return;
        }

        /**
         * WordPress submenu no privilege.
         *
         * @global string
         */
        global $_wp_submenu_nopriv;

        if ( null === $_wp_submenu_nopriv ) {
            $_wp_submenu_nopriv = array(); // phpcs:ignore -- Required to fix warnings, pull request solutions appreciated.
        }

        // execute callable functions here.
        MainWP_Child_Callable::get_instance()->init_call_functions( $auth );
    }

    /**
     * Method init_check_login()
     *
     * Initiate the check login process.
     *
     * @uses MainWP_Connect::check_login()
     */
    public function init_check_login() {
        MainWP_Connect::instance()->check_login();
    }

    /**
     * Method init_hooks()
     */
    public function init_hooks() {
        MainWP_Child_Actions::get_instance()->init_hooks();
    }

    /**
     * Method admin_init()
     *
     * If the current user is administrator initiate the admin ajax.
     *
     * @uses \MainWP\Child\MainWP_Clone::init_ajax()
     */
    public function admin_init() {
        if ( MainWP_Helper::is_admin() && is_admin() ) {
            MainWP_Clone::instance()->init_ajax();
        }

        if ( empty( get_option( 'mainwp_child_pubkey' ) ) ) {
            $ttl_pubkey = (int) get_option( 'mainwp_child_ttl_active_unconnected_site', 20 );
            if ( ! empty( $ttl_pubkey ) ) {
                $lasttime_active = get_option( 'mainwp_child_lasttime_not_connected' );
                if ( empty( $lasttime_active ) ) {
                    MainWP_Helper::update_option( 'mainwp_child_lasttime_not_connected', time() );
                } elseif ( $lasttime_active < time() - $ttl_pubkey * MINUTE_IN_SECONDS ) {
                    include_once ABSPATH . '/wp-admin/includes/plugin.php'; // NOSONAR -- WP compatible.
                    delete_option( 'mainwp_child_lasttime_not_connected' );
                    deactivate_plugins( $this->plugin_slug, true );
                }
            }
        }
    }

    /**
     * Method parse_init_extensions()
     *
     * Parse MainWP Extension initiations.
     *
     * @uses \MainWP\Child\MainWP_Child_Branding::branding_init()
     * @uses \MainWP\Child\MainWP_Client_Report::creport_init()
     * @uses \MainWP\Child\MainWP_Child_IThemes_Security::ithemes_init()
     * @uses \MainWP\Child\MainWP_Child_Updraft_Plus_Backups::updraftplus_init()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_WordPress::init()
     * @uses \MainWP\Child\MainWP_Child_WP_Rocket::init()
     * @uses \MainWP\Child\MainWP_Child_Back_WP_Up::init()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::instance()
     * @uses \MainWP\Child\MainWP_Child_Wordfence::wordfence_init()
     * @uses \MainWP\Child\MainWP_Child_Timecapsule::init()
     * @uses \MainWP\Child\MainWP_Child_Staging::init()
     * @uses \MainWP\Child\MainWP_Child_Pagespeed::init()
     * @uses \MainWP\Child\MainWP_Child_Links_Checker::init()
     * @uses \MainWP\Child\MainWP_Child_WPvivid_BackupRestore::init()
     * @uses \MainWP\Child\MainWP_Child_Aam::instance()
     * @uses \MainWP\Child\MainWP_Child_HTML_Regression::instance()->init()
     */
    private function parse_init_extensions() {
        MainWP_Child_Branding::instance()->branding_init();
        MainWP_Client_Report::instance()->creport_init();
        MainWP_Child_IThemes_Security::instance()->ithemes_init();
        MainWP_Child_Updraft_Plus_Backups::instance()->updraftplus_init();
        MainWP_Child_Back_Up_WordPress::instance()->init();
        MainWP_Child_WP_Rocket::instance()->init();
        MainWP_Child_Back_WP_Up::instance()->init();
        MainWP_Child_Back_Up_Buddy::instance();
        MainWP_Child_Wordfence::instance()->wordfence_init();
        MainWP_Child_Timecapsule::instance()->init();
        MainWP_Child_Staging::instance()->init();
        MainWP_Child_Pagespeed::instance()->init();
        MainWP_Child_Links_Checker::instance()->init();
        MainWP_Child_WPvivid_BackupRestore::instance()->init();
        MainWP_Child_DB_Updater::instance();
        MainWP_Child_Jetpack_Protect::instance();
        MainWP_Child_Jetpack_Scan::instance();
        MainWP_Child_Aam::instance()->init();
        MainWP_Custom_Post_Type::instance();
        MainWP_Child_HTML_Regression::instance()->init();
    }


    /**
     * Method activation()
     *
     * Activate the MainWP Child plugin and delete unwanted data.
     *
     * @uses \MainWP\Child\MainWP_Helper::update_option()
     */
    public function activation() {
        delete_option( 'mainwp_child_lasttime_not_connected' ); // delete if existed.
    }

    /**
     * Method deactivation()
     *
     * Deactivate the MainWP Child plugin.
     *
     * @param bool $deact Whether or not to deactivate pugin. Default: true.
     */
    public function deactivation( $deact = true ) {

        delete_option( 'mainwp_child_lasttime_not_connected' ); // delete if existed.

        $mu_plugin_enabled = apply_filters( 'mainwp_child_mu_plugin_enabled', false );
        if ( $mu_plugin_enabled ) {
            return;
        }

        if ( $deact ) {
            do_action( 'mainwp_child_deactivation' );
        }
    }

    /**
     * Method delete_connection_data()
     *
     * Delete connection data.
     *
     * @param bool $check_must_use Check must use before delete data.
     */
    public function delete_connection_data( $check_must_use = true ) {

        if ( $check_must_use ) {
            $mu_plugin_enabled = apply_filters( 'mainwp_child_mu_plugin_enabled', false );
            if ( $mu_plugin_enabled ) {
                return;
            }
        }

        $to_delete   = array(
            'mainwp_child_pubkey',
            'mainwp_child_nonce',
            'mainwp_security',
            'mainwp_child_server',
            'mainwp_child_connected_admin',
        );
        $to_delete[] = 'mainwp_ext_snippets_enabled';
        $to_delete[] = 'mainwp_ext_code_snippets';
        $to_delete[] = 'mainwp_child_openssl_sign_algo';

        foreach ( $to_delete as $delete ) {
            if ( get_option( $delete ) ) {
                delete_option( $delete );
                wp_cache_delete( $delete, 'options' );
            }
        }
    }

    /**
     * Method plugin_settings_link()
     *
     * On the plugins page add a link to the MainWP settings page.
     *
     * @param array $actions An array of plugin action links. Should include `deactivate`.
     *
     * @return array
     */
    public function plugin_settings_link( $actions ) {
        $href          = admin_url( 'options-general.php?page=mainwp_child_tab' );
        $settings_link = '<a href="' . $href . '">' . __( 'Settings' ) . '</a>'; // phpcs:ignore WordPress.WP.I18n.MissingArgDomain
        array_unshift( $actions, $settings_link );

        return $actions;
    }

    /**
     * Setup cron jobs for MainWP Child.
     *
     * This method checks if we're in a cron context and sets up necessary hooks.
     */
    private function setup_cron_jobs() {
        // Check if we're in a cron context with the mainwp_child_run parameter.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $cron_run = filter_input( INPUT_GET, 'mainwp_child_run', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

        if ( defined( 'DOING_CRON' ) && DOING_CRON && ! empty( $cron_run ) ) {
            add_action( 'init', array( MainWP_Utility::get_class_name(), 'cron_active' ), PHP_INT_MAX );
        }
    }
}
