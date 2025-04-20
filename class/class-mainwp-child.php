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
    public function __construct( $plugin_file ) {
        $this->update();
        $this->plugin_slug = plugin_basename( $plugin_file );
    }

    /**
     * Method init_frontend_only()
     *
     * Lightweight initialization for frontend requests.
     * Only registers the absolute minimum hooks needed for frontend functionality.
     */
    public function init_frontend_only() {
        // Register only essential hooks for frontend.
        add_action( 'template_redirect', array( $this, 'template_redirect' ) );
        add_action( 'init', array( $this, 'localization' ), 33 );

        // Run saved snippets - this is essential functionality.
        MainWP_Utility::instance()->run_saved_snippets();

        // Register essential security filters.
        add_filter( 'mainwp_child_create_action_nonce', array( MainWP_Utility::class, 'hook_create_nonce_action' ), 10, 2 );
        add_filter( 'mainwp_child_verify_authed_acion_nonce', array( MainWP_Utility::class, 'hook_verify_authed_action_nonce' ), 10, 2 );
        add_filter( 'mainwp_child_get_ping_nonce', array( MainWP_Utility::class, 'hook_get_ping_nonce' ), 10, 2 );
        add_filter( 'mainwp_child_get_encrypted_option', array( MainWP_Child_Keys_Manager::class, 'hook_get_encrypted_option' ), 10, 3 );
    }

    /**
     * Method init_full()
     *
     * Full initialization for admin, AJAX, REST, cron, CLI, or API requests.
     * Loads all required functionality for the MainWP Child plugin.
     */
    public function init_full() {
        // Load options early so they're available to all components.
        $this->load_all_options();

        // Register essential hooks.
        $this->register_essential_hooks();

        // Initialize essential components.
        MainWP_Pages::get_instance()->init();

        // Initialize admin-specific components.
        $this->init_admin_components();

        // Run saved snippets - this is essential functionality.
        MainWP_Utility::instance()->run_saved_snippets();
        MainWP_Child_Vulnerability_Checker::instance();

        // Initialize CLI support if needed.
        $this->init_cli_support();

        // Initialize branding for disconnected sites.
        $this->init_branding_for_disconnected();

        // Initialize cron support if needed.
        $this->init_cron_support();

        // Essential action to response data result.
        add_action( 'mainwp_child_write', array( MainWP_Helper::class, 'write' ) );
    }

    /**
     * Initialize admin-specific components.
     *
     * This method handles all the admin-area specific initializations.
     */
    private function init_admin_components() {
        if ( ! is_admin() ) {
            return;
        }

        // Update plugin version in database.
        MainWP_Helper::update_option( 'mainwp_child_plugin_version', static::$version, 'yes' );

        // Initialize connection-related components.
        MainWP_Connect::instance()->check_other_auth();

        // Initialize core features.
        MainWP_Clone::instance()->init();
        MainWP_Client_Report::instance()->init();

        // Register screen-specific hooks.
        $this->register_screen_hooks();
    }

    /**
     * Register hooks that depend on the current screen.
     *
     * These hooks are deferred until WordPress knows which screen is being displayed.
     */
    private function register_screen_hooks() {
        // Defer screen checks until WordPress knows the current screen.
        add_action(
            'current_screen',
            static function ( $screen ) {
                if ( in_array( $screen->id, array( 'plugins', 'update-core' ), true ) ) {
                    // Support for better detection for premium plugins.
                    add_action( 'pre_current_active_plugins', array( MainWP_Child_Updates::get_instance(), 'detect_premium_themesplugins_updates' ) );
                    // Support for better detection for premium themes.
                    add_action( 'core_upgrade_preamble', array( MainWP_Child_Updates::get_instance(), 'detect_premium_themesplugins_updates' ) );
                }
            }
        );

        // These are only needed in admin area and are performance-intensive.
        // Defer loading until WordPress knows the current screen.
        add_action(
            'current_screen',
            function () {
                if ( $this->is_mainwp_pages() ) {
                    // Lazy load these only when on MainWP pages.
                    MainWP_Child_Plugins_Check::instance();
                    MainWP_Child_Themes_Check::instance();

                    // Initiate MainWP Cache Control class.
                    MainWP_Child_Cache_Purge::instance();

                    // Initiate MainWP Child API Backups class.
                    MainWP_Child_Api_Backups::instance();
                }
            }
        );
    }

    /**
     * Initialize CLI support if WP-CLI is active.
     */
    private function init_cli_support() {
        // WP CLI support.
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            MainWP_Child_WP_CLI_Command::init();
        }
    }

    /**
     * Initialize branding for disconnected sites.
     */
    private function init_branding_for_disconnected() {
        // Initiate Branding Options - essential for disconnected sites.
        if ( ! get_option( 'mainwp_child_pubkey' ) ) {
            MainWP_Child_Branding::instance()->save_branding_options( 'branding_disconnected', 'yes' );
        }
    }

    /**
     * Initialize cron support if needed.
     */
    private function init_cron_support() {
        // Support for cron jobs.
        if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
            $mainwp_child_run = filter_input( INPUT_GET, 'mainwp_child_run', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
            if ( ! empty( $mainwp_child_run ) ) {
                add_action( 'init', array( MainWP_Utility::class, 'cron_active' ), PHP_INT_MAX );
            }
        }
    }

    /**
     * Method register_essential_hooks()
     *
     * Register essential hooks that are needed for core functionality.
     */
    private function register_essential_hooks() {
        add_action( 'template_redirect', array( $this, 'template_redirect' ) );
        add_action( 'activated_plugin', array( $this, 'hook_activated_plugin' ) );
        add_action( 'init', array( $this, 'init_check_login' ), 1 );
        add_action( 'init', array( $this, 'parse_init' ), 9999 );
        add_action( 'init', array( $this, 'localization' ), 33 );
        add_action( 'init', array( $this, 'init_hooks' ), 9 );
        add_action( 'admin_init', array( $this, 'admin_init' ) );
        add_action( 'plugin_action_links_mainwp-child/mainwp-child.php', array( $this, 'plugin_settings_link' ) );

        // Essential filters for security.
        add_filter( 'mainwp_child_create_action_nonce', array( MainWP_Utility::class, 'hook_create_nonce_action' ), 10, 2 );
        add_filter( 'mainwp_child_verify_authed_acion_nonce', array( MainWP_Utility::class, 'hook_verify_authed_action_nonce' ), 10, 2 );
        add_filter( 'mainwp_child_get_ping_nonce', array( MainWP_Utility::class, 'hook_get_ping_nonce' ), 10, 2 );
        add_filter( 'mainwp_child_get_encrypted_option', array( MainWP_Child_Keys_Manager::class, 'hook_get_encrypted_option' ), 10, 3 );
    }

    /**
     * Method load_all_options()
     *
     * Load all MainWP Child plugin options.
     *
     * @return array|bool Return array of options or false on failure.
     */
    public function load_all_options() { //phpcs:ignore -- NOSONAR - complex.

        /**
         * WP Database object.
         *
         * @global object $wpdb WordPress object.
         */
        global $wpdb;

        if ( ! defined( 'WP_INSTALLING' ) || ! is_multisite() ) {
            $alloptions = wp_cache_get( 'alloptions', 'options' );
        } else {
            $alloptions = false;
        }

        if ( ! defined( 'WP_INSTALLING' ) || ! is_multisite() ) {
            $notoptions = wp_cache_get( 'notoptions', 'options' );
        } else {
            $notoptions = false;
        }

        if ( ! isset( $alloptions['mainwp_child_pubkey'] ) ) {
            $suppress       = $wpdb->suppress_errors();
            $mainwp_options = array(
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

            // Execute individual queries for each option for maximum security.
            // Note: Direct database calls are necessary here for performance optimization
            // as we're loading multiple options at once and need to bypass the regular
            // WordPress option API to avoid multiple get_option() calls.
            $alloptions_db = array();
            foreach ( $mainwp_options as $option ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Necessary for bulk option loading
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
                // Create a copy of mainwp_options to track which ones were not found.
                $not_found_options = array_flip( $mainwp_options );

                foreach ( (array) $alloptions_db as $o ) {
                    $alloptions[ $o->option_name ] = $o->option_value;
                    // Remove from not_found_options as we've found this option.
                    if ( isset( $not_found_options[ $o->option_name ] ) ) {
                        unset( $not_found_options[ $o->option_name ] );
                    }
                }

                if ( ! is_array( $notoptions ) ) {
                    $notoptions = array();
                }

                // Add remaining options to notoptions.
                foreach ( array_keys( $not_found_options ) as $option ) {
                    $notoptions[ $option ] = true;
                }

                if ( ! defined( 'WP_INSTALLING' ) || ! is_multisite() ) {
                    wp_cache_set( 'alloptions', $alloptions, 'options' );
                    wp_cache_set( 'notoptions', $notoptions, 'options' );
                }
            }
        }

        return $alloptions;
    }

    /**
     * Method update()
     *
     * Update the MainWP Child plugin version (mainwp_child_update_version) option.
     *
     * @return void
     *
     * @uses \MainWP\Child\MainWP_Helper::update_option()
     */
    public function update() {
        $update_version = get_option( 'mainwp_child_update_version' );

        if ( $update_version === $this->update_version ) {
            return;
        }

        if ( version_compare( $update_version, '1.6', '<' ) ) {
            delete_option( 'mainwp_child_subpages ' );
        }

        MainWP_Helper::update_option( 'mainwp_child_update_version', $this->update_version, 'yes' );
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
     * Helper method to check if we're on a MainWP admin page.
     * This method should only be called after the 'current_screen' action has fired.
     *
     * @return bool True if on a MainWP admin page, false otherwise.
     */
    private function is_mainwp_pages() {
        if ( ! is_admin() ) {
            return false;
        }

        $screen = get_current_screen();
        if ( ! $screen ) {
            return false;
        }

        // List of screens where MainWP Child functionality is needed.
        $mainwp_screens = array(
            'settings_page_mainwp_child_tab',
            'dashboard',  // Include dashboard for widgets.
            'update-core', // Include updates page.
        );

        // Also check if we're on a page with mainwp in the query string.
        // Using WordPress's built-in function to get the current admin page.
        $is_mainwp_page = false;
        $page           = filter_input( INPUT_GET, 'page', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

        if ( ! empty( $page ) ) {
            // Use case-insensitive comparison for better reliability.
            $is_mainwp_page = ( false !== stripos( $page, 'mainwp' ) );
        }

        return in_array( $screen->id, $mainwp_screens, true ) || $is_mainwp_page;
    }
}
