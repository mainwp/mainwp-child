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

// phpcs:disable PSR1.Classes.ClassDeclaration, WordPress.WP.AlternativeFunctions -- required to achieve desired results, pull request solutions appreciated.

/**
 * Class MainWP_Child_WP_Rocket
 *
 * MainWP Rocket extension handler.
 */
class MainWP_Child_WP_Rocket {

	/**
	 * Public static variable to hold the single instance of the class.
	 *
	 * @var mixed Default null
	 */
	public static $instance = null;

	/**
	 * Public variable to hold the infomration if the WP Rocket plugin is installed on the child site.
	 *
	 * @var bool If WP Rocket intalled, return true, if not, return false.
	 */
	public $is_plugin_installed = false;

	/**
	 * Method instance()
	 *
	 * Create a public static instance.
	 *
	 * @return mixed Class instance.
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
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
			'cache_mobile'                => 1,
			'do_caching_mobile_files'     => 0,
			'cache_logged_user'           => 0,
			'emoji'                       => 0,
			'embeds'                      => 1,
			'control_heartbeat'           => 0,
			'heartbeat_site_behavior'     => 'reduce_periodicity',
			'heartbeat_admin_behavior'    => 'reduce_periodicity',
			'heartbeat_editor_behavior'   => 'reduce_periodicity',
			'varnish_auto_purge'          => 0,
			'manual_preload'              => 0,
			'automatic_preload'           => 0,
			'sitemap_preload'             => 0,
			'preload_links'               => 0,
			'sitemaps'                    => array(),
			'database_revisions'          => 0,
			'database_auto_drafts'        => 0,
			'database_trashed_posts'      => 0,
			'database_spam_comments'      => 0,
			'database_trashed_comments'   => 0,
			'database_expired_transients' => 0,
			'database_all_transients'     => 0,
			'database_optimize_tables'    => 0,
			'schedule_automatic_cleanup'  => 0,
			'automatic_cleanup_frequency' => '',
			'cache_reject_uri'            => array(),
			'cache_reject_cookies'        => array(),
			'cache_reject_ua'             => array(),
			'cache_query_strings'         => array(),
			'cache_purge_pages'           => array(),
			'purge_cron_interval'         => 10,
			'purge_cron_unit'             => 'HOUR_IN_SECONDS',
			'exclude_css'                 => array(),
			'exclude_js'                  => array(),
			'exclude_inline_js'           => array(),
			'async_css'                   => 0,
			'defer_all_js'                => 0,
			'critical_css'                => '',
			'exclude_defer_js'            => array(),
			'delay_js'                    => 0,
			'delay_js_scripts'            => array(),
			'lazyload'                    => 0,
			'lazyload_iframes'            => 0,
			'exclude_lazyload'            => array(),
			'lazyload_youtube'            => 0,
			'minify_css'                  => 0,
			'image_dimensions'            => 0,
			'cache_webp'                  => 0,
			'minify_concatenate_css'      => 0,
			'minify_css_legacy'           => 0,
			'minify_js'                   => 0,
			'minify_concatenate_js'       => 0,
			'minify_js_combine_all'       => 0,
			'preload_fonts'               => array(),
			'dns_prefetch'                => 0,
			'cdn'                         => 0,
			'cdn_cnames'                  => array(),
			'cdn_zone'                    => array(),
			'cdn_reject_files'            => array(),
			'do_cloudflare'               => 0,
			'cloudflare_email'            => '',
			'cloudflare_api_key'          => '',
			'cloudflare_domain'           => '',
			'cloudflare_devmode'          => 0,
			'cloudflare_protocol_rewrite' => 0,
			'cloudflare_auto_settings'    => 0,
			'cloudflare_old_settings'     => 0,
			'analytics_enabled'           => 0,
			'google_analytics_cache'      => 0,
			'facebook_pixel_cache'        => 0,
			'do_cloudflare'               => 0,
			'sucury_waf_cache_sync'       => 0,
			'cloudflare_api_key'          => '',
			'cloudflare_email'            => '',
			'cloudflare_zone_id'          => '',
			'cloudflare_devmode'          => 0,
			'cloudflare_protocol_rewrite' => 0,
			'sucury_waf_cache_sync'       => 0,
			'sucury_waf_api_key'          => '',
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
				$data                            = array( 'rocket_boxes' => get_user_meta( $GLOBALS['current_user']->ID, 'rocket_boxes', true ) );
				$information['syncWPRocketData'] = $data;
			} catch ( \Exception $e ) {
				// ok!
			}
		}
		return $information;
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
				self::remove_filters_with_method_name( $hook_name, $method, $priority );
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
			if ( isset( $filter_array['function'] ) && is_array( $filter_array['function'] ) ) {
				// Test if object is a class and method is equal to param !
				if ( is_object( $filter_array['function'][0] ) && get_class( $filter_array['function'][0] ) && $filter_array['function'][1] == $method_name ) {
					// Test for WordPress >= 4.7 WP_Hook class.
					if ( is_a( $wp_filter[ $hook_name ], 'WP_Hook' ) ) {
						unset( $wp_filter[ $hook_name ]->callbacks[ $priority ][ $unique_id ] );
					} else {
						unset( $wp_filter[ $hook_name ][ $priority ][ $unique_id ] );
					}
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
		if ( isset( $_POST['mainwpsignature'] ) ) {
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
		$pos = isset( $_SERVER['REQUEST_URI'] ) ? stripos( wp_unslash( $_SERVER['REQUEST_URI'] ), 'options-general.php?page=wprocket' ) : false;
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
	 * @uses \MainWP\Child\MainWP_Child_WP_Rocket::purge_cache_all() Purge all cache.
	 * @uses \MainWP\Child\MainWP_Child_WP_Rocket::preload_cache() Preload cache.
	 * @uses \MainWP\Child\MainWP_Child_WP_Rocket::generate_critical_css() Generate critical CSS.
	 * @uses \MainWP\Child\MainWP_Child_WP_Rocket::save_settings() Save the plugin settings.
	 * @uses \MainWP\Child\MainWP_Child_WP_Rocket::load_existing_settings() Load existing settings.
	 * @uses \MainWP\Child\MainWP_Child_WP_Rocket::optimize_database() Optimize database tables.
	 * @uses \MainWP\Child\MainWP_Child_WP_Rocket::get_optimize_info() Get the optimization information.
	 * @uses \MainWP\Child\MainWP_Child_WP_Rocket::do_admin_post_rocket_purge_opcache() Do admin post to purge opcache.
	 * @uses \MainWP\Child\MainWP_Helper::write()
	 */
	public function action() {
		if ( ! $this->is_plugin_installed ) {
			MainWP_Helper::write( array( 'error' => esc_html__( 'Please install WP Rocket plugin on child website', $this->plugin_translate ) ) );
			return;
		}

		$information = array();

		if ( isset( $_POST['mwp_action'] ) ) {
			$mwp_action = ! empty( $_POST['mwp_action'] ) ? sanitize_text_field( wp_unslash( $_POST['mwp_action'] ) ) : '';
			try {
				switch ( $mwp_action ) {
					case 'set_showhide':
						$information = $this->set_showhide();
						break;
					case 'purge_cloudflare':
						$information = $this->purge_cloudflare();
						break;
					case 'purge_all':
						$information = $this->purge_cache_all();
						break;
					case 'preload_cache':
						$information = $this->preload_cache();
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
				}
			} catch ( \Exception $e ) {
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
		$hide = isset( $_POST['showhide'] ) && ( 'hide' === $_POST['showhide'] ) ? 'hide' : '';
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
	 * Method purge_cache_all()
	 *
	 * Purge all cache.
	 *
	 * @used-by MainWP_Child_WP_Rocket::actions() Fire off certain WP Rocket plugin actions.
	 *
	 * @return array Action result.
	 */
	public function purge_cache_all() {
		if ( function_exists( 'rocket_clean_domain' ) || function_exists( 'rocket_clean_minify' ) || function_exists( 'create_rocket_uniqid' ) ) {
			set_transient( 'rocket_clear_cache', 'all', HOUR_IN_SECONDS );
			rocket_clean_domain();
			rocket_clean_minify();
			if ( function_exists( 'rocket_clean_cache_busting' ) ) {
				rocket_clean_cache_busting();
			}
			if ( ! function_exists( 'rocket_dismiss_boxes' ) && defined( 'WP_ROCKET_ADMIN_PATH' ) ) {
				require_once WP_ROCKET_ADMIN_PATH . 'admin.php';
			}
			include_once ABSPATH . '/wp-admin/includes/template.php';
			$options                   = get_option( WP_ROCKET_SLUG );
			$options['minify_css_key'] = create_rocket_uniqid();
			$options['minify_js_key']  = create_rocket_uniqid();
			remove_all_filters( 'update_option_' . WP_ROCKET_SLUG );
			update_option( WP_ROCKET_SLUG, $options );
			rocket_dismiss_box( 'rocket_warning_plugin_modification' );
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
	 * @throws \Exception Error message.
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
	 * @throws \Exception Error message.
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
		$options = isset( $_POST['settings'] ) ? json_decode( base64_decode( wp_unslash( $_POST['settings'] ) ), true ) : ''; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
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

		return array( 'result' => 'SUCCESS' );
	}

	/**
	 * Method optimize_database()
	 *
	 * Optimize database tables.
	 *
	 * @return array Action result
	 * @throws \Exception Error message.
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
	 * Method get_optimize_info()
	 *
	 * Get the optimization information.
	 *
	 * @return array Action result and optimization information.
	 * @throws \Exception Error message.
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

}
