<?php
/**
 * MainWP Rocket
 *
 * MainWP Rocket extension handler.
 * Extension URL: https://mainwp.com/extension/rocket/
 *
 * @package MainWP\Child
 *
 * Credits
 *
 * Plugin-Name: WP Rocket
 * Plugin-URI: https://wp-rocket.me
 * Author: WP Media
 * Author URI: http://wp-media.me
 * Licence: GPLv2 or later
 */

namespace MainWP\Child;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable PSR1.Classes.ClassDeclaration, WordPress.WP.AlternativeFunctions -- required to achieve desired results, pull request solutions appreciated.

/**
 * Class MainWP_Child_WP_Rocket
 *
 * MainWP Rocket extension handler.
 */
class MainWP_Child_WP_Rocket {//phpcs:ignore -- NOSONAR - multi methods.

    /**
     * Public static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    public static $instance = null;

    /**
     * Option holding the abilities-v2 request receipts.
     *
     * @var string
     */
    private const ABILITIES_V2_RECEIPTS_OPTION = 'mainwp_wp_rocket_abilities_v2_receipts';

    /**
     * Receipt count at which a new request has to free a slot before it may run.
     *
     * @var int
     */
    private const ABILITIES_V2_MAX_RECEIPTS = 100;

    /**
     * Seconds a stored timestamp may lead the current time by and still be usable.
     *
     * Receipts come out of a WordPress option, so their timestamps are untrusted input. A small
     * allowance keeps a site whose clock drifted or moved backwards from calling its own recent
     * receipts corrupt, while anything further ahead is an impossible moment this store never wrote.
     *
     * @var int
     */
    private const ABILITIES_V2_CLOCK_SKEW = 300;

    /**
     * Public variable to hold the infomration if the WP Rocket plugin is installed on the child site.
     *
     * @var bool If WP Rocket intalled, return true, if not, return false.
     */
    public $is_plugin_installed = false;

    /**
     * Public variable to hold the information about the language domain.
     *
     * @var string 'mainwp-child' languge domain.
     */
    public $plugin_translate = 'mainwp-child';

    /**
     * Method instance()
     *
     * Create a public static instance.
     *
     * @return mixed Class instance.
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }

        return static::$instance;
    }

    /**
     * MainWP_Child_WP_Rocket constructor.
     *
     * Run any time class is called.
     */
    public function __construct() {
        if ( is_plugin_active( 'wp-rocket/wp-rocket.php' ) ) {
            $this->is_plugin_installed = true;
        }
    }

    /**
     * Method init()
     *
     * Initiate action hooks.
     *
     * @return void
     */
    public function init() {
        if ( ! $this->is_plugin_installed ) {
            return;
        }

        add_filter( 'mainwp_site_sync_others_data', array( $this, 'sync_others_data' ), 10, 2 );
        if ( 'hide' === get_option( 'mainwp_wprocket_hide_plugin' ) ) {
            add_filter( 'all_plugins', array( $this, 'all_plugins' ) );
            add_action( 'admin_menu', array( $this, 'remove_menu' ) );
            add_filter( 'site_transient_update_plugins', array( &$this, 'remove_update_nag' ) );
            add_filter( 'mainwp_child_hide_update_notice', array( &$this, 'hide_update_notice' ) );
            add_action( 'wp_before_admin_bar_render', array( $this, 'wp_before_admin_bar_render' ), 99 );
            add_action( 'admin_init', array( $this, 'remove_notices' ) );
        }
    }

    /**
     * Method get_rocket_default_options()
     *
     * WP Rocket plugin settings default options.
     *
     * @return array Default options.
     */
    public function get_rocket_default_options() {
        return array(
            'emoji'                        => 0,
            'embeds'                       => 1,
            'control_heartbeat'            => 0,
            'heartbeat_site_behavior'      => 'reduce_periodicity',
            'heartbeat_admin_behavior'     => 'reduce_periodicity',
            'heartbeat_editor_behavior'    => 'reduce_periodicity',
            'manual_preload'               => 0,
            'preload_excluded_uri'         => array(),
            'automatic_preload'            => 0,
            'sitemap_preload'              => 0,
            'preload_links'                => 0,
            'sitemaps'                     => array(),
            'database_revisions'           => 0,
            'database_auto_drafts'         => 0,
            'database_trashed_posts'       => 0,
            'database_spam_comments'       => 0,
            'database_trashed_comments'    => 0,
            'database_expired_transients'  => 0,
            'database_all_transients'      => 0,
            'database_optimize_tables'     => 0,
            'schedule_automatic_cleanup'   => 0,
            'automatic_cleanup_frequency'  => '',
            'cache_reject_uri'             => array(),
            'cache_reject_cookies'         => array(),
            'cache_reject_ua'              => array(),
            'cache_query_strings'          => array(),
            'cache_purge_pages'            => array(),
            'purge_cron_interval'          => 10,
            'purge_cron_unit'              => 'HOUR_IN_SECONDS',
            'exclude_css'                  => array(),
            'exclude_js'                   => array(),
            'exclude_inline_js'            => array(),
            'async_css'                    => 0,
            'defer_all_js'                 => 0,
            'critical_css'                 => '',
            'remove_unused_css_safelist'   => '',
            'exclude_defer_js'             => array(),
            'delay_js'                     => 0,
            'delay_js_exclusions_selected' => array(),
            'delay_js_exclusions'          => array(),
            'delay_js_scripts'             => array(),
            'lazyload'                     => 0,
            'lazyload_css_bg_img'          => 0,
            'lazyload_iframes'             => 0,
            'exclude_lazyload'             => array(),
            'lazyload_youtube'             => 0,
            'minify_css'                   => 0,
            'image_dimensions'             => 0,
            'minify_concatenate_css'       => 0,
            'minify_css_legacy'            => 0,
            'minify_js'                    => 0,
            'minify_concatenate_js'        => 0,
            'minify_js_combine_all'        => 0,
            'preload_fonts'                => array(),
            'dns_prefetch'                 => 0,
            'cdn'                          => 0,
            'cdn_cnames'                   => array(),
            'cdn_zone'                     => array(),
            'cdn_reject_files'             => array(),
            'do_cloudflare'                => 0,
            'cloudflare_email'             => '',
            'cloudflare_api_key'           => '',
            'cloudflare_domain'            => '',
            'cloudflare_devmode'           => 0,
            'cloudflare_protocol_rewrite'  => 0,
            'cloudflare_auto_settings'     => 0,
            'cloudflare_old_settings'      => 0,
            'analytics_enabled'            => 0,
            'google_analytics_cache'       => 0,
            'facebook_pixel_cache'         => 0,
            'sucury_waf_cache_sync'        => 0,
            'cache_logged_user'            => 0,
            'varnish_auto_purge'           => 0,
            'cache_webp'                   => 0,
            'cloudflare_zone_id'           => '',
            'sucury_waf_api_key'           => '',
            'host_fonts_locally'           => 0, // Host Google Fonts Locally.
            'delay_js_execution_safe_mode' => 0, // Safe Mode for Delay JavaScript Execution.
        );
    }

    /**
     * Method sync_others_data()
     *
     * Sync the WP Rocket plugin settings.
     *
     * @param  array $information Array containing the sync information.
     * @param  array $data        Array containing the WP Rocekt plugin data to be synced.
     *
     * @return array $information Array containing the sync information.
     */
    public function sync_others_data( $information, $data = array() ) {

        if ( isset( $data['syncWPRocketData'] ) && ( 'yes' === $data['syncWPRocketData'] ) ) {
            try {
                $data                            = array(
                    'rocket_boxes'            => get_user_meta( $GLOBALS['current_user']->ID, 'rocket_boxes', true ),
                    'lists_delayjs'           => get_option( '_transient_wpr_dynamic_lists_delayjs', array() ),
                    'lists_delayjs_full_list' => $this->prepare_delayjs_ui_list(),
                );
                $information['syncWPRocketData'] = $data;
            } catch ( MainWP_Exception $e ) {
                // ok!
            }
        }
        return $information;
    }


    /**
     * Prepare the list of scripts, plugins and theme for the view.
     *
     * @return array|array[]
     */
    public function prepare_delayjs_ui_list() {
        $list = get_transient( 'wpr_dynamic_lists_delayjs' );

        if ( empty( $list ) ) {
            return array();
        }

        $full_list = array(
            'scripts' => array(
                'items' => array(
                    'analytics'          => array(
                        'title'    => __( 'Analytics & Trackers', 'mainwp-child' ),
                        'items'    => array(),
                        'svg-icon' => 'analytics',
                    ),
                    'ad_networks'        => array(
                        'title'    => __( 'Ad Networks', 'mainwp-child' ),
                        'items'    => array(),
                        'svg-icon' => 'ad_network',
                    ),
                    'payment_processors' => array(
                        'title'    => __( 'Payment Processors', 'mainwp-child' ),
                        'items'    => array(),
                        'svg-icon' => 'payment',
                    ),
                    'other_services'     => array(
                        'title'    => __( 'Other Services', 'mainwp-child' ),
                        'items'    => array(),
                        'svg-icon' => 'others',
                    ),
                ),
            ),
            'plugins' => array(
                'items' => array(),
            ),
            'themes'  => array(
                'items' => array(),
            ),
        );

        $script_types = array(
            'analytics'          => 'get_analytics_from_list',
            'ad_networks'        => 'get_ad_networks_from_list',
            'payment_processors' => 'get_payment_processors_from_list',
            'other_services'     => 'get_other_services_from_list',
        );

        foreach ( $script_types as $type => $method ) {
            $scripts = $this->$method( $list );
            foreach ( $scripts as $script_key => $script ) {
                $full_list['scripts']['items'][ $type ]['items'][] = array(
                    'id'    => $script_key,
                    'title' => $script->title,
                    'icon'  => $this->get_icon( $script ),
                );
            }
        }

        $active_plugins = $this->get_active_plugins();
        $plugins_list   = ! empty( $list->plugins ) ? (array) $list->plugins : array();

        foreach ( $plugins_list as $plugin_key => $plugin ) {

            if ( ! in_array( $plugin->condition, $active_plugins, true ) ) {
                continue;
            }

            $full_list['plugins']['items'][] = array(
                'id'    => $plugin_key,
                'title' => $plugin->title,
                'icon'  => $this->get_icon( $plugin ),
            );
        }

        $active_theme = $this->get_active_theme();

        $themes_list = ! empty( $list->themes ) ? (array) $list->themes : array();

        foreach ( $themes_list as $theme_key => $theme ) {
            if ( $theme->condition !== $active_theme ) {
                continue;
            }

            $full_list['themes']['items'][] = array(
                'id'    => $theme_key,
                'title' => $theme->title,
                'icon'  => $this->get_icon( $theme ),
            );
        }

        return $full_list;
    }

    /**
     * Get Analytics scripts from the list.
     *
     * @param object $lists List of scripts.
     * @return array
     */
    private function get_analytics_from_list( $lists ) {
        return ! empty( $lists->scripts->analytics ) ? (array) $lists->scripts->analytics : array();
    }

    /**
     * Get Ad Networks from the list.
     *
     * @param object $lists List of scripts.
     * @return array
     */
    private function get_ad_networks_from_list( $lists ) {
        return ! empty( $lists->scripts->ad_networks ) ? (array) $lists->scripts->ad_networks : array();
    }

    /**
     * Get Payment Processors from the list.
     *
     * @param object $lists List of scripts.
     * @return array
     */
    private function get_payment_processors_from_list( $lists ) {
        return ! empty( $lists->scripts->payment_processors ) ? (array) $lists->scripts->payment_processors : array();
    }

    /**
     * Get Other Services from the list.
     *
     * @param object $lists List of scripts.
     * @return array
     */
    private function get_other_services_from_list( $lists ) {
        return ! empty( $lists->scripts->other_services ) ? (array) $lists->scripts->other_services : array();
    }


    /**
     * Fetch the icon.
     *
     * @param object $item item from the list.
     * @return string
     */
    private function get_icon( $item ) {
        if ( empty( $item->icon_url ) ) {
            return '';
        }

        return $item->icon_url;
    }


    /**
     * Get active plugins (list of IDs).
     *
     * @return array
     */
    public function get_active_plugins() {
        $plugins = (array) get_option( 'active_plugins', array() );

        if ( ! is_multisite() ) {
            return $plugins;
        }

        return array_merge(
            $plugins,
            array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) )
        );
    }


    /**
     * Get active theme ID.
     *
     * @return string
     */
    public function get_active_theme() {
        return $this->get_theme_name( wp_get_theme() );
    }


    /**
     * Get theme name from theme object.
     *
     * @param WP_Theme $theme Theme to get its name.
     *
     * @return string
     */
    private function get_theme_name( $theme ) {

        if ( ! $theme instanceof \WP_Theme ) {
            return 'Undefined';
        }

        $parent = $theme->get_template();
        if ( ! empty( $parent ) ) {
            return $parent;
        }

        return $theme->get( 'Name' );
    }

    /**
     * Method remove_notices()
     *
     * Remove admin notices thrown by the WP Rocket plugin when the plugin is hidden.
     *
     * @uses MainWP_Child_WP_Rocket::remove_filters_with_method_name() Remove filters with method name.
     */
    public function remove_notices() {
        $remove_hooks['admin_notices'] = array(
            'rocket_bad_deactivations'                    => 10,
            'rocket_warning_plugin_modification'          => 10,
            'rocket_plugins_to_deactivate'                => 10,
            'rocket_warning_using_permalinks'             => 10,
            'rocket_warning_wp_config_permissions'        => 10,
            'rocket_warning_advanced_cache_permissions'   => 10,
            'rocket_warning_advanced_cache_not_ours'      => 10,
            'rocket_warning_htaccess_permissions'         => 10,
            'rocket_warning_config_dir_permissions'       => 10,
            'rocket_warning_cache_dir_permissions'        => 10,
            'rocket_warning_minify_cache_dir_permissions' => 10,
            'rocket_thank_you_license'                    => 10,
            'rocket_need_api_key'                         => 10,
        );
        foreach ( $remove_hooks as $hook_name => $hooks ) {
            foreach ( $hooks as $method => $priority ) {
                static::remove_filters_with_method_name( $hook_name, $method, $priority );
            }
        }
    }


    /**
     * Method remove_filters_with_method_name()
     *
     * Remove filters with method name.
     *
     * @param string $hook_name   Contains the hook name.
     * @param string $method_name Contains the method name.
     * @param int    $priority    Contains the priority value.
     *
     * @used-by MainWP_Child_WP_Rocket::remove_notices() Remove admin notices thrown by the WP Rocket plugin when the plugin is hidden.
     *
     * @return bool Return false if filtr is not set.
     */
    public static function remove_filters_with_method_name( $hook_name = '', $method_name = '', $priority = 0 ) {

        /**
         * WordPress filter array.
         *
         * @global array $wp_filter WordPress filter array.
         */
        global $wp_filter;

        // Take only filters on right hook name and priority.
        if ( ! isset( $wp_filter[ $hook_name ][ $priority ] ) || ! is_array( $wp_filter[ $hook_name ][ $priority ] ) ) {
            return false;
        }
        // Loop on filters registered.
        foreach ( (array) $wp_filter[ $hook_name ][ $priority ] as $unique_id => $filter_array ) {
            // Test if filter is an array ! (always for class/method).
            if ( isset( $filter_array['function'] ) && is_array( $filter_array['function'] ) && is_object( $filter_array['function'][0] ) && get_class( $filter_array['function'][0] ) && $filter_array['function'][1] === $method_name ) {
                // Test for WordPress >= 4.7 WP_Hook class.
                if ( is_a( $wp_filter[ $hook_name ], 'WP_Hook' ) ) {
                    unset( $wp_filter[ $hook_name ]->callbacks[ $priority ][ $unique_id ] );
                } else {
                    unset( $wp_filter[ $hook_name ][ $priority ][ $unique_id ] );
                }
            }
        }
        return false;
    }

    /**
     * Method wp_before_admin_bar_render()
     *
     * Remove the WP Rocket admin bar node when the plugin is hidden.
     */
    public function wp_before_admin_bar_render() {

        /**
         * WordPress admin bar array.
         *
         * @global array $wp_admin_bar WordPress admin bar array.
         */
        global $wp_admin_bar;

        $nodes = $wp_admin_bar->get_nodes();
        if ( is_array( $nodes ) ) {
            foreach ( $nodes as $node ) {
                $node->id = 'wp-rocket';
                if ( 'wp-rocket' === $node->parent || $node->id ) {
                    $wp_admin_bar->remove_node( $node->id );
                }
            }
        }
    }

    /**
     * Method hide_update_notice()
     *
     * Remove the WP Rocket plugin update notice when the plugin is hidden.
     *
     * @param array $slugs Array containing installed plugins slugs.
     *
     * @return array $slugs Array containing installed plugins slugs.
     */
    public function hide_update_notice( $slugs ) {
        $slugs[] = 'wp-rocket/wp-rocket.php';
        return $slugs;
    }

    /**
     * Method remove_update_nag()
     *
     * Remove the WP Rocket plugin update notice when the plugin is hidden.
     *
     * @param object $value Object containing update information.
     *
     * @return object $value Object containing update information.
     *
     * @uses \MainWP\Child\MainWP_Helper::is_updates_screen()
     */
    public function remove_update_nag( $value ) {
        if ( MainWP_Helper::is_dashboard_request() ) {
            return $value;
        }

        if ( ! MainWP_Helper::is_updates_screen() ) {
            return $value;
        }

        if ( isset( $value->response['wp-rocket/wp-rocket.php'] ) ) {
            unset( $value->response['wp-rocket/wp-rocket.php'] );
        }

        return $value;
    }

    /**
     * Method is_activated()
     *
     * Check if the WP Rocket pulgin is activated.
     *
     * @return bool If the WP Rocket pulgin is active, return true, if not, return false.
     */
    public function is_activated() {
        if ( ! $this->is_plugin_installed ) {
            return false;
        }

        return true;
    }

    /**
     * Method remove_menu()
     *
     * Remove the WP Rocket menu item when the plugin is hidden.
     */
    public function remove_menu() {

        /**
         * WordPress submenu array.
         *
         * @global array $submenu WordPress submenu array.
         */
        global $submenu;

        if ( isset( $submenu['options-general.php'] ) ) {
            foreach ( $submenu['options-general.php'] as $index => $item ) {
                if ( 'wprocket' === $item[2] ) {
                    unset( $submenu['options-general.php'][ $index ] );
                    break;
                }
            }
        }
        $pos = isset( $_SERVER['REQUEST_URI'] ) ? stripos( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), 'options-general.php?page=wprocket' ) : false;
        if ( false !== $pos ) {
            wp_safe_redirect( get_option( 'siteurl' ) . '/wp-admin/index.php' );
            exit();
        }
    }

    /**
     * Method all_plugins()
     *
     * Remove the WP Rocket plugin from the list of all plugins when the plugin is hidden.
     *
     * @param array $plugins Array containing all installed plugins.
     *
     * @return array $plugins Array containing all installed plugins without the WP Rocket.
     */
    public function all_plugins( $plugins ) {
        foreach ( $plugins as $key => $value ) {
            $plugin_slug = basename( $key, '.php' );
            if ( 'wp-rocket' === $plugin_slug ) {
                unset( $plugins[ $key ] );
            }
        }

        return $plugins;
    }

    /**
     * Method actions()
     *
     * Fire off certain WP Rocket plugin actions.
     *
     * @return void
     *
     * @uses \MainWP\Child\MainWP_Child_WP_Rocket::set_showhide() Hide or unhide the WP Rocket plugin.
     * @uses \MainWP\Child\MainWP_Child_WP_Rocket::purge_cloudflare() Purge the Cloudflare cache.
     * @uses \MainWP\Child\MainWP_Child_WP_Rocket::preload_purge_cache_all() Purge all cache.
     * @uses \MainWP\Child\MainWP_Child_WP_Rocket::generate_critical_css() Generate critical CSS.
     * @uses \MainWP\Child\MainWP_Child_WP_Rocket::save_settings() Save the plugin settings.
     * @uses \MainWP\Child\MainWP_Child_WP_Rocket::load_existing_settings() Load existing settings.
     * @uses \MainWP\Child\MainWP_Child_WP_Rocket::optimize_database() Optimize database tables.
     * @uses \MainWP\Child\MainWP_Child_WP_Rocket::get_optimize_info() Get the optimization information.
     * @uses \MainWP\Child\MainWP_Child_WP_Rocket::do_admin_post_rocket_purge_opcache() Do admin post to purge opcache.
     * @uses \MainWP\Child\MainWP_Child_WP_Rocket::update_exclusion_list() Update inclusion and exclusion lists.
     * @uses \MainWP\Child\MainWP_Helper::write()
     */
    public function action() { //phpcs:ignore -- NOSONAR -complex.

        // Action to be performed.
        $mwp_action = MainWP_System::instance()->validate_params( 'mwp_action' );

        $abilities_v2_response = $this->abilities_v2_action_response( $mwp_action );
        if ( null !== $abilities_v2_response ) {
            MainWP_Helper::write( $abilities_v2_response );
            return;
        }

        // Check if the WP Rocket plugin is installed on the child website.
        if ( ! $this->is_plugin_installed ) {
            MainWP_Helper::write( array( 'error' => esc_html__( 'Please install WP Rocket plugin on child website', 'mainwp-child' ) ) );
            return;
        }

        // Run specific wprocket method based on the passed action.
        $information = array();
        if ( ! empty( $mwp_action ) ) {
            try {
                switch ( $mwp_action ) {
                    case 'set_showhide':
                        $information = $this->set_showhide();
                        break;
                    case 'purge_cloudflare':
                        $information = $this->purge_cloudflare();
                        break;
                    case 'preload_purge_all':
                        $information = $this->preload_purge_cache_all();
                        break;
                    case 'generate_critical_css':
                        $information = $this->generate_critical_css();
                        break;
                    case 'save_settings':
                        $information = $this->save_settings();
                        break;
                    case 'load_existing_settings':
                        $information = $this->load_existing_settings();
                        break;
                    case 'optimize_database':
                        $information = $this->optimize_database();
                        break;
                    case 'get_optimize_info':
                        $information = $this->get_optimize_info();
                        break;
                    case 'purge_opcache':
                        $information = $this->do_admin_post_rocket_purge_opcache();
                        break;
                    case 'update_exclusion_list':
                        $information = $this->update_exclusion_list();
                        break;
                    case 'clear_priority_elements':
                        $information = $this->clear_priority_elements();
                        break;
                    default:
                        break;
                }
            } catch ( MainWP_Exception $e ) {
                $information = array( 'error' => $e->getMessage() );
            }
        }
        MainWP_Helper::write( $information );
    }

    /**
     * Method set_showhide()
     *
     * Hide or unhide the WP Rocket plugin.
     *
     * @return array Action result.
     *
     * @used-by \MainWP\Child\MainWP_Child_WP_Rocket::actions() Fire off certain WP Rocket plugin actions.
     * @uses    \MainWP\Child\MainWP_Helper::update_option()
     */
    public function set_showhide() {
        $hide = MainWP_System::instance()->validate_params( 'showhide' );
        MainWP_Helper::update_option( 'mainwp_wprocket_hide_plugin', $hide );
        $information['result'] = 'SUCCESS';

        return $information;
    }

    /**
     * Method do_admin_post_rocket_purge_opcache()
     *
     * Do admin post to purge opcache.
     *
     * @used-by MainWP_Child_WP_Rocket::actions() Fire off certain WP Rocket plugin actions.
     *
     * @return array Action result.
     */
    public function do_admin_post_rocket_purge_opcache() {
        if ( function_exists( 'opcache_reset' ) ) {
            opcache_reset();
        } else {
            return array( 'error' => 'The host do not support the function reset opcache.' );
        }
        return array( 'result' => 'SUCCESS' );
    }

    /**
     * Method purge_cloudflare()
     *
     * Purge the Cloudflare cache.
     *
     * @used-by MainWP_Child_WP_Rocket::actions() Fire off certain WP Rocket plugin actions.
     *
     * @return array Action result.
     */
    public function purge_cloudflare() {
        if ( function_exists( 'rocket_purge_cloudflare' ) ) {
            rocket_purge_cloudflare();
            return array( 'result' => 'SUCCESS' );
        } else {
            return array( 'error' => 'function_not_exist' );
        }
    }

    /**
     * Method require_file_path()
     *
     * Include necessary files.
     *
     * @param string $function_name function Name.
     * @param string $file_name file Name.
     */
    private function require_file_path( $function_name, $file_name ) {
        if ( ! function_exists( $function_name ) && defined( 'WP_ROCKET_FUNCTIONS_PATH' ) ) {
            require_once WP_ROCKET_FUNCTIONS_PATH . $file_name; // NOSONAR - WP compatible.
        }
    }

    /**
     * Method preload_purge_cache_all()
     *
     * Purge all cache.
     *
     * @used-by MainWP_Child_WP_Rocket::actions() Fire off certain WP Rocket plugin actions.
     *
     * @return array Action result.
     */
    public function preload_purge_cache_all() {
        if ( function_exists( 'rocket_clean_domain' ) || function_exists( 'rocket_clean_minify' ) || function_exists( 'create_rocket_uniqid' ) ) {
            set_transient( 'rocket_clear_cache', 'all', HOUR_IN_SECONDS );

            // Require files to make sure the functions are available.
            $this->require_file_path( 'get_rocket_i18n_uri', 'i18n.php' );
            $this->require_file_path( 'get_rocket_parse_url', 'formatting.php' );
            $this->require_file_path( 'create_rocket_uniqid', 'admin.php' );

            rocket_clean_domain();
            rocket_clean_minify();

            if ( function_exists( 'rocket_clean_cache_busting' ) ) {
                rocket_clean_cache_busting();
            }

            if ( ! function_exists( 'rocket_dismiss_boxes' ) && defined( 'WP_ROCKET_ADMIN_PATH' ) ) {
                require_once WP_ROCKET_ADMIN_PATH . 'admin.php'; // NOSONAR - WP compatible.
            }

            include_once ABSPATH . '/wp-admin/includes/template.php'; // NOSONAR -- WP compatible.

            $options                   = get_option( WP_ROCKET_SLUG );
            $options['minify_css_key'] = create_rocket_uniqid();
            $options['minify_js_key']  = create_rocket_uniqid();

            remove_all_filters( 'update_option_' . WP_ROCKET_SLUG );
            update_option( WP_ROCKET_SLUG, $options );
            rocket_dismiss_box( 'rocket_warning_plugin_modification' );

            // call preload cache.
            $this->preload_cache();

            return array( 'result' => 'SUCCESS' );
        } else {
            return array( 'error' => 'function_not_exist' );
        }
    }

    /**
     * Method preload_cache()
     *
     * Preload cache.
     *
     * @return array Action result.
     * @throws MainWP_Exception Error message.
     *
     * @used-by \MainWP\Child\MainWP_Child_WP_Rocket::actions() Fire off certain WP Rocket plugin actions.
     * @uses    \MainWP\Child\MainWP_Helper::instance()->check_methods()
     * @uses    \MainWP\Child\MainWP_Helper::instance()->check_classes_exists()
     */
    public function preload_cache() {
        MainWP_Helper::instance()->check_functions( array( 'run_rocket_sitemap_preload', 'run_rocket_bot' ) );
        $existed = MainWP_Helper::instance()->check_classes_exists( '\WP_Rocket\Engine\Preload\FullProcess', true );
        // compatible.
        if ( true === $existed ) {
            $preload_process = new \WP_Rocket\Engine\Preload\FullProcess();
            MainWP_Helper::instance()->check_methods( $preload_process, array( 'is_process_running' ) );

            if ( $preload_process->is_process_running() ) {
                return array( 'result' => 'RUNNING' );
            }

            delete_transient( 'rocket_preload_errors' );
            run_rocket_bot( 'cache-preload', '' );
            run_rocket_sitemap_preload();

            return array( 'result' => 'SUCCESS' );
        } else {
            // Preload cache.
            run_rocket_bot();
            run_rocket_sitemap_preload();

            return array( 'result' => 'SUCCESS' );
        }
    }

    /**
     * Method generate_critical_css()
     *
     * Generate critical CSS.
     *
     * @return array Action result.
     * @throws MainWP_Exception Error message.
     *
     * @used-by \MainWP\Child\MainWP_Child_WP_Rocket::actions() Fire off certain WP Rocket plugin actions.
     * @uses    \MainWP\Child\MainWP_Helper::instance()->check_properties()
     * @uses    \MainWP\Child\MainWP_Helper::instance()->check_classes_exists()
     */
    public function generate_critical_css() {
        $old_version = false;
        if ( class_exists( '\WP_Rocket\Subscriber\Optimization\Critical_CSS_Subscriber' ) ) { // to compatible with old version.
            MainWP_Helper::instance()->check_classes_exists(
                array(
                    '\WP_Rocket\Subscriber\Optimization\Critical_CSS_Subscriber',
                    '\WP_Rocket\Optimization\CSS\Critical_CSS',
                    '\WP_Rocket\Optimization\CSS\Critical_CSS_Generation',
                    '\WP_Rocket\Admin\Options',
                    '\WP_Rocket\Admin\Options_Data',
                )
            );
            $old_version = true;
        } else {
            MainWP_Helper::instance()->check_classes_exists(
                array(
                    '\WP_Rocket\Engine\CriticalPath\CriticalCSS',
                    '\WP_Rocket\Engine\CriticalPath\CriticalCSSGeneration',
                    '\WP_Rocket\Engine\CriticalPath\ProcessorService',
                    '\WP_Rocket\Engine\CriticalPath\DataManager',
                    '\WP_Rocket\Engine\CriticalPath\APIClient',
                    '\WP_Rocket\Admin\Options',
                    '\WP_Rocket\Admin\Options_Data',
                )
            );
            MainWP_Helper::instance()->check_functions( array( '\rocket_direct_filesystem', '\rocket_get_constant' ) );
        }

        if ( $old_version ) {
            $critical_css = new \WP_Rocket\Optimization\CSS\Critical_CSS( new \WP_Rocket\Optimization\CSS\Critical_CSS_Generation() );
            $options_api  = new \WP_Rocket\Admin\Options( 'wp_rocket_' );
            $options      = new \WP_Rocket\Admin\Options_Data( $options_api->get( 'settings', array() ) );

            $sitemap_preload = new \WP_Rocket\Subscriber\Optimization\Critical_CSS_Subscriber( $critical_css, $options );

            MainWP_Helper::instance()->check_properties( $sitemap_preload, 'critical_css' );
            MainWP_Helper::instance()->check_methods( $sitemap_preload->critical_css, 'process_handler' );

            $sitemap_preload->critical_css->process_handler();
        } else {
            $filesystem        = \rocket_direct_filesystem();
            $options           = new \WP_Rocket\Admin\Options( 'wp_rocket_' );
            $options_data      = new \WP_Rocket\Admin\Options_Data( $options->get( 'settings', array() ) );
            $critical_css_path = \rocket_get_constant( 'WP_ROCKET_CRITICAL_CSS_PATH' ) . get_current_blog_id() . '/';

            $cpcss_service = new \WP_Rocket\Engine\CriticalPath\ProcessorService( new \WP_Rocket\Engine\CriticalPath\DataManager( $critical_css_path, $filesystem ), new \WP_Rocket\Engine\CriticalPath\APIClient() );

            $critical_css = new \WP_Rocket\Engine\CriticalPath\CriticalCSS( new \WP_Rocket\Engine\CriticalPath\CriticalCSSGeneration( $cpcss_service ), $options_data, $filesystem );
            $critical_css->process_handler();
        }
        return array( 'result' => 'SUCCESS' );
    }

    /**
     * Method save_settings()
     *
     * Save the plugin settings.
     *
     * @used-by MainWP_Child_WP_Rocket::actions() Fire off certain WP Rocket plugin actions.
     *
     * @return array Action result.
     */
    public function save_settings() {
        // phpcs:disable WordPress.Security.NonceVerification
        $options = isset( $_POST['settings'] ) ? json_decode( base64_decode( wp_unslash( $_POST['settings'] ) ), true ) : '';  //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
        if ( ! is_array( $options ) || empty( $options ) ) {
            return array( 'error' => 'INVALID_OPTIONS' );
        }

        $old_values = get_option( WP_ROCKET_SLUG );

        $defaults_fields = $this->get_rocket_default_options();

        foreach ( $old_values as $field => $value ) {
            if ( ! isset( $defaults_fields[ $field ] ) ) {
                $options[ $field ] = $value;
            }
        }

        update_option( WP_ROCKET_SLUG, $options );

        if ( isset( $_POST['do_database_optimization'] ) && ! empty( $_POST['do_database_optimization'] ) ) {
            $this->optimize_database();
        }
        // phpcs:enable
        return array( 'result' => 'SUCCESS' );
    }


    /**
     * [Description for update_exclusion_list]
     *
     * @return array
     */
    public function update_exclusion_list() {
        // Call cron by rocket update dynamic lists.
        wp_schedule_single_event( time() + 10, 'rocket_update_dynamic_lists' );

        return array( 'result' => 'SUCCESS' );
    }

    /**
     * Method optimize_database()
     *
     * Optimize database tables.
     *
     * @return array Action result
     * @throws MainWP_Exception Error message.
     *
     * @used-by \MainWP\Child\MainWP_Child_WP_Rocket::actions() Fire off certain WP Rocket plugin actions.
     * @uses    \MainWP\Child\MainWP_Helper::instance()->check_methods()
     * @uses    \MainWP\Child\MainWP_Helper::instance()->check_classes_exists()
     */
    public function optimize_database() {
        MainWP_Helper::instance()->check_classes_exists(
            array(
                '\WP_Rocket\Admin\Database\Optimization',
                '\WP_Rocket\Admin\Database\Optimization_Process',
                '\WP_Rocket\Admin\Options',
                '\WP_Rocket\Admin\Options_Data',
            )
        );

        $process      = new \WP_Rocket\Admin\Database\Optimization_Process();
        $optimization = new \WP_Rocket\Admin\Database\Optimization( $process );

        MainWP_Helper::instance()->check_methods( $optimization, array( 'process_handler', 'get_options' ) );

        $options_api = new \WP_Rocket\Admin\Options( 'wp_rocket_' );
        $options     = new \WP_Rocket\Admin\Options_Data( $options_api->get( 'settings', array() ) );

        $items = array_filter( array_keys( $optimization->get_options() ), array( $options, 'get' ) );

        if ( ! empty( $items ) ) {
            $optimization->process_handler( $items );
        }

        $return['result'] = 'SUCCESS';
        return $return;
    }

    /**
     * Answer an abilities_v2 request ahead of the v1 provider gate.
     *
     * This has to run before the installed-plugin bail: on a site without WP Rocket
     * the v2 protocol still owes the Dashboard its own closed envelope, and the v1
     * bail would answer a protocol request with localized display text instead.
     *
     * @param string $mwp_action Validated action name.
     * @return array|null Closed protocol response, or null for a v1 request.
     */
    protected function abilities_v2_action_response( $mwp_action ) {
        return 'abilities_v2' === $mwp_action ? $this->abilities_v2_action() : null;
    }

    /**
     * Decode the additive WP Rocket abilities-v2 transport request.
     *
     * @return array Closed protocol result.
     */
    private function abilities_v2_action() {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Authenticated MainWP Child callable.
        if ( ! isset( $_POST['request'] ) || ! is_string( $_POST['request'] ) ) {
            return $this->abilities_v2_error( 'unknown' );
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Closed JSON is validated below.
        $raw = wp_unslash( $_POST['request'] );
        if ( '' === $raw || 16384 < strlen( $raw ) ) {
            return $this->abilities_v2_error( 'unknown' );
        }
        $request = json_decode( $raw, true );
        return $this->abilities_v2( $request );
    }

    /**
     * Process one closed WP Rocket abilities-v2 request.
     *
     * @param mixed $request Decoded request.
     * @return array Closed protocol result.
     */
    public function abilities_v2( $request ) {
        $operation = is_array( $request ) && isset( $request['operation'] ) && is_string( $request['operation'] ) ? $request['operation'] : 'unknown';
        if ( ! is_array( $request ) || ! isset( $request['protocol'] ) || '2' !== $request['protocol'] ) {
            return $this->abilities_v2_error( $operation );
        }

        if ( 'capabilities' === $operation ) {
            if ( ! $this->abilities_v2_exact_keys( $request, array( 'protocol', 'operation', 'payload' ) ) || ! is_array( $request['payload'] ) || array() !== $request['payload'] ) {
                return $this->abilities_v2_error( $operation );
            }
            return array(
                'protocol'   => '2',
                'operation'  => 'capabilities',
                'ok'         => true,
                'operations' => array( 'optimize_database' ),
                'categories' => array_keys( $this->abilities_v2_category_map() ),
            );
        }

        if ( 'optimize_database' !== $operation || ! $this->abilities_v2_exact_keys( $request, array( 'protocol', 'operation', 'request_ref', 'payload' ) ) ) {
            return $this->abilities_v2_error( $operation );
        }
        if ( ! $this->abilities_v2_valid_request_ref( $request['request_ref'] ) ) {
            return $this->abilities_v2_error( $operation );
        }
        if ( ! is_array( $request['payload'] ) || ! $this->abilities_v2_exact_keys( $request['payload'], array( 'categories' ) ) ) {
            return $this->abilities_v2_error( $operation );
        }

        $categories = $request['payload']['categories'];
        $map        = $this->abilities_v2_category_map();
        if ( ! is_array( $categories ) || array_keys( $categories ) !== range( 0, count( $categories ) - 1 ) || 1 > count( $categories ) || 8 < count( $categories ) ) {
            return $this->abilities_v2_error( $operation );
        }
        $provider_categories = array();
        foreach ( $categories as $category ) {
            if ( ! is_string( $category ) || ! isset( $map[ $category ] ) || in_array( $category, array_slice( $categories, 0, count( $provider_categories ) ), true ) ) {
                return $this->abilities_v2_error( $operation );
            }
            $provider_categories[] = $map[ $category ];
        }

        // The receipt lookup and the dispatch it guards have to be one atomic step, or a Dashboard
        // retry racing its own lost request queues the same destructive optimization twice.
        $lock = $this->abilities_v2_begin_lock();
        if ( null === $lock ) {
            return $this->abilities_v2_error( $operation, 'storage_unavailable' );
        }
        if ( true !== $lock ) {
            return $this->abilities_v2_error( $operation, 'lock_busy' );
        }
        try {
            return $this->abilities_v2_optimize_database( $request['request_ref'], $categories, $provider_categories );
        } finally {
            $this->abilities_v2_end_lock();
        }
    }

    /**
     * Run one optimization request at most once inside the retry horizon, under the held request lock.
     *
     * @param string $request_ref Validated request reference.
     * @param array  $categories Public category names.
     * @param array  $provider_categories WP Rocket category keys.
     * @return array Closed protocol result.
     */
    private function abilities_v2_optimize_database( $request_ref, $categories, $provider_categories ) {
        $operation   = 'optimize_database';
        $effect_hash = hash( 'sha256', wp_json_encode( array( $operation, $categories ) ) );
        $receipts    = get_option( self::ABILITIES_V2_RECEIPTS_OPTION, array() );
        if ( ! is_array( $receipts ) ) {
            return $this->abilities_v2_error( $operation, 'storage_unavailable' );
        }
        // A key holding null is still evidence that something wrote a receipt for this reference,
        // and isset() reads it as absent. Missing it here queues the optimization a second time.
        if ( array_key_exists( $request_ref, $receipts ) ) {
            $receipt = $receipts[ $request_ref ];
            if ( ! $this->abilities_v2_valid_receipt( $receipt ) ) {
                return $this->abilities_v2_error( $operation, 'storage_unavailable' );
            }
            if ( ! hash_equals( $receipt['effect_hash'], $effect_hash ) ) {
                return $this->abilities_v2_error( $operation, 'request_conflict' );
            }
            if ( 'dispatching' !== $receipt['state'] ) {
                // The effect hash binds what the request asked for, not what the stored answer says
                // was done, and the store is a WordPress option. A settled success naming any other
                // category list would assert an effect no receipt establishes, so it is refused for
                // what it is: a store this request cannot read its outcome back from. Re-dispatching
                // is not the alternative, because the receipt is evidence the work may already have run.
                if ( ! $this->abilities_v2_response_binds_categories( $receipt['response'], $categories ) ) {
                    return $this->abilities_v2_error( $operation, 'storage_unavailable' );
                }
                return $receipt['response'];
            }
            // A reservation nobody settled belongs to a request that either died before WP Rocket
            // saw it or reached the queue and lost its answer. While that queue entry could still
            // be draining, running it again would optimize twice, so the outcome is unknown.
            if ( $this->abilities_v2_reservation_is_live( $receipt['accepted_at'] ) ) {
                return $this->abilities_v2_error( $operation, 'outcome_unknown' );
            }
            // Past the horizon the reservation proves nothing: the earlier cleanup has drained or
            // been abandoned, and a reference that can only ever answer outcome_unknown is a worse
            // outcome than a second queued cleanup. Retire it and let this request dispatch.
            unset( $receipts[ $request_ref ] );
        }

        // The request is well formed, so a missing WP Rocket is refused for what it is rather than blamed on the payload.
        if ( ! $this->is_plugin_installed ) {
            return $this->abilities_v2_error( $operation, 'provider_unavailable' );
        }

        // Room is made before the queue is touched: an optimization the store cannot record is one a
        // retry would run again.
        $receipts = $this->abilities_v2_evict_receipts( $receipts );
        if ( false === $receipts ) {
            return $this->abilities_v2_error( $operation, 'storage_unavailable' );
        }

        // The reservation is durable before the provider runs, not after it. An interrupted request
        // that already queued the work must leave something behind for its retry to land on.
        $receipts[ $request_ref ] = array(
            'effect_hash' => $effect_hash,
            'state'       => 'dispatching',
            'response'    => null,
            'accepted_at' => time(),
        );
        if ( ! $this->abilities_v2_store_receipts( $receipts ) ) {
            return $this->abilities_v2_error( $operation, 'storage_unavailable' );
        }

        $response                             = $this->abilities_v2_dispatch_optimize_database( $provider_categories, $categories );
        $receipts[ $request_ref ]['state']    = 'settled';
        $receipts[ $request_ref ]['response'] = $response;
        if ( ! $this->abilities_v2_store_receipts( $receipts ) ) {
            return $this->abilities_v2_error( $operation, 'outcome_unknown' );
        }
        return $response;
    }

    /**
     * Queue the optimization and turn the provider outcome into this request's settled answer.
     *
     * @param array $provider_categories WP Rocket category keys.
     * @param array $categories Public category names.
     * @return array Closed protocol result.
     */
    private function abilities_v2_dispatch_optimize_database( $provider_categories, $categories ) {
        $operation = 'optimize_database';
        try {
            $result = $this->abilities_v2_provider_optimize_database( $provider_categories );
        } catch ( \Throwable $e ) {
            return $this->abilities_v2_error( $operation, 'provider_failed' );
        }
        if ( false === $result || is_wp_error( $result ) ) {
            return $this->abilities_v2_error( $operation, 'provider_failed' );
        }

        // WP Rocket runs the categories on its own background queue and the Child never reads the outcome back,
        // so the only claim this response can support is that the request was accepted for processing.
        return array(
            'protocol'   => '2',
            'operation'  => $operation,
            'ok'         => true,
            'status'     => 'requested',
            'categories' => $categories,
        );
    }

    /**
     * Persist the receipt store, treating an unchanged-value write as success.
     *
     * @param array $receipts Receipts to store.
     * @return bool
     */
    private function abilities_v2_store_receipts( $receipts ) {
        return update_option( self::ABILITIES_V2_RECEIPTS_OPTION, $receipts, false ) || get_option( self::ABILITIES_V2_RECEIPTS_OPTION, array() ) === $receipts;
    }

    /**
     * Validate one stored receipt before anything is decided from it.
     *
     * The store is a WordPress option, so every entry is untrusted input: a malformed one may not
     * be replayed as an answer, and it may not be treated as a receipt worth protecting either.
     *
     * @param mixed $receipt Stored receipt.
     * @return bool
     */
    private function abilities_v2_valid_receipt( $receipt ) {
        if ( ! is_array( $receipt ) || ! $this->abilities_v2_exact_keys( $receipt, array( 'effect_hash', 'state', 'response', 'accepted_at' ) ) ) {
            return false;
        }
        if ( ! is_string( $receipt['effect_hash'] ) || 1 !== preg_match( '/^[0-9a-f]{64}$/D', $receipt['effect_hash'] ) || ! $this->abilities_v2_trusted_timestamp( $receipt['accepted_at'] ) ) {
            return false;
        }
        if ( 'dispatching' === $receipt['state'] ) {
            return null === $receipt['response'];
        }
        return 'settled' === $receipt['state'] && $this->abilities_v2_valid_receipt_response( $receipt['response'] );
    }

    /**
     * Validate a stored optimization outcome before it is replayed as this request's answer.
     *
     * @param mixed $response Stored response.
     * @return bool
     */
    private function abilities_v2_valid_receipt_response( $response ) {
        if ( ! is_array( $response ) ) {
            return false;
        }
        if ( array( 'protocol', 'operation', 'ok', 'error_code' ) === array_keys( $response ) ) {
            return '2' === $response['protocol']
                && 'optimize_database' === $response['operation']
                && false === $response['ok']
                && 'provider_failed' === $response['error_code'];
        }
        return array( 'protocol', 'operation', 'ok', 'status', 'categories' ) === array_keys( $response )
            && '2' === $response['protocol']
            && 'optimize_database' === $response['operation']
            && true === $response['ok']
            && 'requested' === $response['status']
            && is_array( $response['categories'] );
    }

    /**
     * Whether a validated stored response still asserts exactly the categories this request carries.
     *
     * A refusal carries no category claim at all, so only a settled success has anything to bind.
     * The comparison is order sensitive on purpose: the effect hash is taken over the ordered list,
     * so the same list in another order is a different request, not the same one.
     *
     * @param array $response   Stored response that already passed receipt validation.
     * @param array $categories Public category names this request carries.
     * @return bool
     */
    private function abilities_v2_response_binds_categories( $response, $categories ) {
        return ! array_key_exists( 'categories', $response ) || $response['categories'] === $categories;
    }

    /**
     * Whether a stored timestamp is a moment this store could actually have written.
     *
     * A value that is not an integer, not positive, or further in the future than clock skew
     * explains is not a younger timestamp; it is an unusable one, so nothing may read age from it.
     *
     * @param mixed $value Stored timestamp.
     * @return bool
     */
    private function abilities_v2_trusted_timestamp( $value ) {
        return is_int( $value ) && 0 < $value && time() + self::ABILITIES_V2_CLOCK_SKEW >= $value;
    }

    /**
     * The moment before which no Dashboard retry of a request is still expected.
     *
     * @return int
     */
    private function abilities_v2_retry_horizon() {
        return time() - ( DAY_IN_SECONDS + 60 );
    }

    /**
     * Whether an unsettled reservation still stands for work that could be in flight.
     *
     * An untrustworthy timestamp is not a young one, so it can never hold a reservation open.
     *
     * @param mixed $accepted_at Stored reservation timestamp.
     * @return bool
     */
    private function abilities_v2_reservation_is_live( $accepted_at ) {
        return $this->abilities_v2_trusted_timestamp( $accepted_at ) && $accepted_at >= $this->abilities_v2_retry_horizon();
    }

    /**
     * Validate a UUID request reference.
     *
     * @param mixed $value Candidate reference.
     * @return bool
     */
    private function abilities_v2_valid_request_ref( $value ) {
        return is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value );
    }

    /**
     * Free one receipt slot without discarding an outcome a retry could still ask for.
     *
     * Receipts older than any plausible Dashboard retry are dropped oldest first. A store that
     * still cannot free a slot refuses the request rather than evict a live receipt whose
     * reference would then queue the optimization a second time. The accepted_at stamp is written
     * once and never renewed, so a full store crosses the horizon on its own and starts accepting
     * writes again without an operator touching anything.
     *
     * @param array $receipts Stored receipts.
     * @return array|false Receipts with room for one more, or false when no slot can be freed.
     */
    private function abilities_v2_evict_receipts( $receipts ) {
        if ( self::ABILITIES_V2_MAX_RECEIPTS > count( $receipts ) ) {
            return $receipts;
        }
        $repaired  = false;
        $evictable = array();
        foreach ( $receipts as $reference => $receipt ) {
            if ( ! $this->abilities_v2_valid_request_ref( $reference ) ) {
                // No request can present a reference this handler would refuse, so nothing will
                // ever come back for this entry. It is evidence for nobody, and dropping it is what
                // keeps a store of junk keys from holding every later optimization shut. Array keys
                // are not always strings either, so this is also what stops one being compared as one.
                $evictable[ $reference ] = 0;
                continue;
            }
            if ( ! $this->abilities_v2_valid_receipt( $receipt ) ) {
                // An entry nobody can read is still evidence that something wrote a receipt for that
                // reference, so its optimization may already be queued. Giving it up to make room for
                // an unrelated request is how a reference loses its only evidence and queues the work
                // a second time on its next retry. Rebuilt as a tombstone it keeps failing the receipt
                // check, so its own reference still answers storage_unavailable, while gaining a date
                // this store can act on - which is also what re-dates a stamp ahead of this clock,
                // since trusted_timestamp() already reads one of those as unreadable.
                // Rebuilding it and keeping the result only when it differs makes the builder the
                // single definition of a tombstone. A separate "is this already a tombstone" check
                // would be a second definition, and the two drifting apart is how an entry with an
                // impossible stamp gets treated as sound and holds the store shut.
                $rebuilt = $this->abilities_v2_tombstone_record( $receipt );
                if ( $rebuilt !== $receipt ) {
                    $receipts[ $reference ] = $rebuilt;
                    $repaired               = true;
                }
                $receipt = $rebuilt;
            }
            // Eviction may only give up an entry it can prove is past the retry horizon, which is the
            // same question a replay asks of a reservation. Asking it once is what stops eviction from
            // dropping an entry a retry would still have been answered from.
            if ( ! $this->abilities_v2_reservation_is_live( $receipt['accepted_at'] ) ) {
                $evictable[ $reference ] = $receipt['accepted_at'];
            }
        }
        asort( $evictable, SORT_NUMERIC );
        foreach ( array_keys( $evictable ) as $reference ) {
            if ( self::ABILITIES_V2_MAX_RECEIPTS > count( $receipts ) ) {
                break;
            }
            unset( $receipts[ $reference ] );
        }
        // A tombstone is stamped at first observation, so a repair left unpersisted is stamped again
        // on every request and can never grow old enough to be given up - a store of damaged entries
        // would then refuse every optimization forever with no way out. Writing it here freezes those
        // stamps and lets the entries cross the horizon on their own.
        if ( $repaired && ! $this->abilities_v2_store_receipts( $receipts ) ) {
            return false;
        }
        return self::ABILITIES_V2_MAX_RECEIPTS > count( $receipts ) ? $receipts : false;
    }

    /**
     * Rebuild one unreadable entry as a dated tombstone under the same reference.
     *
     * Keeping the reference is what stops its retry from queueing the optimization a second time.
     * What the damaged entry no longer proves is the outcome, so nothing about the effect survives:
     * no effect hash a request could match, and no response to replay. Its own stamp is kept where
     * it still reads, so an entry written by an older build ages out on the date it was written
     * rather than on the date this store first failed to read it. A stamp this clock cannot date is
     * replaced instead: keeping it would leave an entry no clock ever passes, and one of those in a
     * full store refuses every optimization from then on with nothing an operator can do.
     *
     * @param mixed $receipt Unreadable entry.
     * @return array Dated tombstone.
     */
    private function abilities_v2_tombstone_record( $receipt ) {
        $now = time();
        return array(
            'effect_hash' => str_repeat( '0', 64 ),
            'state'       => 'unreadable',
            'response'    => null,
            'accepted_at' => is_array( $receipt ) && isset( $receipt['accepted_at'] ) && is_int( $receipt['accepted_at'] ) && 0 < $receipt['accepted_at'] && $now >= $receipt['accepted_at'] ? $receipt['accepted_at'] : $now,
        );
    }

    /**
     * Return this installation's named optimization request lock.
     *
     * @return string
     */
    protected function abilities_v2_lock_name() {
        return 'mainwp_wp_rocket_v2_' . substr( hash( 'sha256', home_url( '/' ) ), 0, 32 );
    }

    /**
     * Acquire the Child-wide optimization request lock without waiting.
     *
     * @return bool|null True when acquired, false when another session holds it, null when the lock backend could not answer.
     */
    protected function abilities_v2_begin_lock() {
        global $wpdb;
        if ( ! is_object( $wpdb ) || ! is_callable( array( $wpdb, 'prepare' ) ) || ! is_callable( array( $wpdb, 'get_var' ) ) ) {
            return null;
        }
        $wpdb->last_error = '';
        $locked           = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $this->abilities_v2_lock_name() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Named lock is the serialization primitive.
        if ( ! empty( $wpdb->last_error ) ) {
            return null;
        }
        if ( '1' === (string) $locked ) {
            return true;
        }
        // Only '0' means someone else holds it. GET_LOCK() answers NULL on an error or an
        // interrupted wait, which says nothing about who holds the lock, so it is not reported
        // as contention the Child never observed.
        return '0' === (string) $locked ? false : null;
    }

    /**
     * Release the Child-wide optimization request lock.
     *
     * @return bool
     */
    protected function abilities_v2_end_lock() {
        global $wpdb;
        if ( ! is_object( $wpdb ) || ! is_callable( array( $wpdb, 'prepare' ) ) || ! is_callable( array( $wpdb, 'get_var' ) ) ) {
            return false;
        }
        // A lock this session fails to give back outlives the request on a persistent connection
        // and blocks every later mutation with no way for an operator to clear it, so one failed
        // release earns a second attempt before it is given up on.
        for ( $attempt = 0; $attempt < 2; $attempt++ ) {
            $wpdb->last_error = '';
            $released         = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->abilities_v2_lock_name() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Named lock release must be checked.
            // RELEASE_LOCK() answers NULL when the named lock does not exist, which is the state
            // the caller asked for.
            if ( empty( $wpdb->last_error ) && ( null === $released || '1' === (string) $released ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Queue explicit WP Rocket optimization categories without reading settings.
     *
     * @param array $categories WP Rocket category keys.
     * @return bool True when every category is supported and the work reached WP Rocket's queue.
     * @throws MainWP_Exception Missing provider support.
     */
    protected function abilities_v2_provider_optimize_database( $categories ) {
        MainWP_Helper::instance()->check_classes_exists(
            array(
                '\\WP_Rocket\\Admin\\Database\\Optimization',
                '\\WP_Rocket\\Admin\\Database\\Optimization_Process',
            )
        );
        $process      = new \WP_Rocket\Admin\Database\Optimization_Process();
        $optimization = new \WP_Rocket\Admin\Database\Optimization( $process );
        MainWP_Helper::instance()->check_methods( $optimization, array( 'process_handler', 'get_options' ) );

        $supported = array_keys( $optimization->get_options() );
        foreach ( $categories as $category ) {
            if ( ! in_array( $category, $supported, true ) ) {
                return false;
            }
        }
        $optimization->process_handler( $categories );
        return true;
    }

    /**
     * Map public categories to WP Rocket provider categories.
     *
     * @return array<string,string>
     */
    private function abilities_v2_category_map() {
        return array(
            'revisions'          => 'database_revisions',
            'auto_drafts'        => 'database_auto_drafts',
            'trashed_posts'      => 'database_trashed_posts',
            'spam_comments'      => 'database_spam_comments',
            'trashed_comments'   => 'database_trashed_comments',
            'expired_transients' => 'database_expired_transients',
            'all_transients'     => 'database_all_transients',
            'optimize_tables'    => 'database_optimize_tables',
        );
    }

    /**
     * Check an associative array's exact key set.
     *
     * @param array $value Value.
     * @param array $keys Expected keys.
     * @return bool
     */
    private function abilities_v2_exact_keys( $value, $keys ) {
        if ( ! is_array( $value ) ) {
            return false;
        }
        $actual = array_keys( $value );
        sort( $actual );
        sort( $keys );
        return $actual === $keys;
    }

    /**
     * Build a stable redacted protocol error.
     *
     * @param string $operation Operation.
     * @param string $code Stable code.
     * @return array
     */
    private function abilities_v2_error( $operation, $code = 'invalid_request' ) {
        return array(
            'protocol'   => '2',
            'operation'  => is_string( $operation ) && 64 >= strlen( $operation ) ? $operation : 'unknown',
            'ok'         => false,
            'error_code' => $code,
        );
    }

    /**
     * Method get_optimize_info()
     *
     * Get the optimization information.
     *
     * @return array Action result and optimization information.
     * @throws MainWP_Exception Error message.
     *
     * @used-by \MainWP\Child\MainWP_Child_WP_Rocket::actions() Fire off certain WP Rocket plugin actions.
     * @uses    \MainWP\Child\MainWP_Helper::instance()->check_classes_exists()
     * @uses    \MainWP\Child\MainWP_Helper::instance()->check_methods()
     */
    public function get_optimize_info() {
        MainWP_Helper::instance()->check_classes_exists(
            array(
                '\WP_Rocket\Admin\Database\Optimization',
                '\WP_Rocket\Admin\Database\Optimization_Process',
            )
        );

        $process      = new \WP_Rocket\Admin\Database\Optimization_Process();
        $optimization = new \WP_Rocket\Admin\Database\Optimization( $process );

        MainWP_Helper::instance()->check_methods( $optimization, 'count_cleanup_items' );

        $information['optimize_info'] = array(
            'total_revisions'          => $optimization->count_cleanup_items( 'database_revisions' ),
            'total_auto_draft'         => $optimization->count_cleanup_items( 'database_auto_drafts' ),
            'total_trashed_posts'      => $optimization->count_cleanup_items( 'database_trashed_posts' ),
            'total_spam_comments'      => $optimization->count_cleanup_items( 'database_spam_comments' ),
            'total_trashed_comments'   => $optimization->count_cleanup_items( 'database_trashed_comments' ),
            'total_expired_transients' => $optimization->count_cleanup_items( 'database_expired_transients' ),
            'total_all_transients'     => $optimization->count_cleanup_items( 'database_all_transients' ),
            'total_optimize_tables'    => $optimization->count_cleanup_items( 'database_optimize_tables' ),
        );

        $information['result'] = 'SUCCESS';
        return $information;
    }

    /**
     * Method load_existing_settings()
     *
     * Load existing settings.
     *
     * @used-by MainWP_Child_WP_Rocket::actions() Fire off certain WP Rocket plugin actions.
     *
     * @return array Action result and settings options.
     */
    public function load_existing_settings() {
        $options = get_option( WP_ROCKET_SLUG );
        return array(
            'result'  => 'SUCCESS',
            'options' => $options,
        );
    }

    /**
     * Method clear_priority_elements()
     *
     * Clear all priority elements.
     *
     * @return array Status of the action.
     */
    public function clear_priority_elements() {
        try {
            // Check if the function exists.
            if ( ! function_exists( 'wpm_apply_filters_typed' ) || ! function_exists( 'rocket_clean_domain' ) || ! function_exists( 'rocket_dismiss_box' ) ) {
                return array( 'error' => 'function_not_exist' );
            }
            // Clear all priority elements.
            $clean_filter = wpm_apply_filters_typed( 'array', 'rocket_performance_hints_clean_all', array() );
            rocket_clean_domain();
            rocket_dismiss_box( 'rocket_warning_plugin_modification' );
            set_transient(
                'rocket_performance_hints_clear_message',
                $clean_filter
            );

            return array( 'result' => 'SUCCESS' );
        } catch ( MainWP_Exception $e ) {
            return array( 'error' => $e->getMessage() );
        }
    }
}
