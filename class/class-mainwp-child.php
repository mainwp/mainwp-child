<?php
/**
 * MainWP Child
 *
 * This file handles all of the task that deal with the
 *  MainWP Child Plugin itself.
 */
namespace MainWP\Child;

// phpcs:disable
if ( defined( 'MAINWP_CHILD_DEBUG' ) && MAINWP_CHILD_DEBUG === true ) {
	error_reporting( E_ALL );
	ini_set( 'display_errors', true );
	ini_set( 'display_startup_errors', true );
} else {
	if ( isset( $_REQUEST['mainwpsignature'] ) ) {
		ini_set( 'display_errors', false );
		error_reporting( 0 );
	}
}
// phpcs:enable


require_once ABSPATH . '/wp-admin/includes/file.php';
require_once ABSPATH . '/wp-admin/includes/plugin.php';

/**
 * Class MainWP_Child
 *
 * @package MainWP\Child
 */
class MainWP_Child {

	/**
	 * @static
	 * @var string MainWP Child Plugin Version.
	 */
	public static $version = '4.0.7.1';

	/**
	 * @var string Update Version.
	 */
	private $update_version = '1.5';

	/**
	 * @var string MainWP Child Plugin slug.
	 */
	public $plugin_slug;

	/**
	 * @var string MainWP Child Plugin directory.
	 */
	private $plugin_dir;

	/**
	 * MainWP_Child constructor.
	 *
	 * @param $plugin_file MainWP Child Plugin file.
	 */
	public function __construct( $plugin_file ) {
		$this->update();
		$this->load_all_options();

		$this->plugin_dir  = dirname( $plugin_file );
		$this->plugin_slug = plugin_basename( $plugin_file );

		add_action( 'template_redirect', array( $this, 'template_redirect' ) );
		add_action( 'init', array( &$this, 'init_check_login' ), 1 );
		add_action( 'init', array( &$this, 'parse_init' ), 9999 );
		add_action( 'init', array( &$this, 'localization' ), 33 );
		add_action( 'admin_init', array( &$this, 'admin_init' ) );
		add_action( 'pre_current_active_plugins', array( MainWP_Child_Updates::get_instance(), 'detect_premium_themesplugins_updates' ) ); // to support detect premium plugins update.
		add_action( 'core_upgrade_preamble', array( MainWP_Child_Updates::get_instance(), 'detect_premium_themesplugins_updates' ) ); // to support detect premium themes.

		MainWP_Pages::get_instance()->init();

		if ( is_admin() ) {
			MainWP_Helper::update_option( 'mainwp_child_plugin_version', self::$version, 'yes' );
		}

		MainWP_Connect::instance()->check_other_auth();

		// init functions.
		MainWP_Clone::get()->init();
		MainWP_Child_Server_Information::init();
		MainWP_Client_Report::instance()->init();
		MainWP_Child_Plugins_Check::instance();
		MainWP_Child_Themes_Check::instance();
		MainWP_Utility::instance()->run_saved_snippets();

		if ( ! get_option( 'mainwp_child_pubkey' ) ) {
			MainWP_Child_Branding::instance()->save_branding_options( 'branding_disconnected', 'yes' );
		}

		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			if ( isset( $_GET['mainwp_child_run'] ) && ! empty( $_GET['mainwp_child_run'] ) ) {
				add_action( 'init', array( MainWP_Utility::get_class_name(), 'cron_active' ), PHP_INT_MAX );
			}
		}
	}

	/**
	 * Load all MainWP Child Plugin options.
	 *
	 * @return array|bool Return array of options $alloptions[] or FALSE on failure.
	 */
	public function load_all_options() {

		/** @var global $wbdb wpdb. */
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

		if ( ! isset( $alloptions['mainwp_db_version'] ) ) {
			$suppress = $wpdb->suppress_errors();
			$options  = array(
				'mainwp_child_auth',
				'mainwp_child_reports_db',
				'mainwp_child_fix_htaccess',
				'mainwp_child_pluginDir',
				'mainwp_updraftplus_hide_plugin',
				'mainwp_backwpup_ext_enabled',
				'mainwpKeywordLinks',
				'mainwp_child_server',
				'mainwp_kwl_options',
				'mainwp_kwl_keyword_links',
				'mainwp_keyword_links_htaccess_set',
				'mainwp_pagespeed_hide_plugin',
				'mainwp_kwl_enable_statistic',
				'mainwp_child_clone_permalink',
				'mainwp_child_restore_permalink',
				'mainwp_ext_snippets_enabled',
				'mainwp_child_pubkey',
				'mainwp_child_nossl',
				'mainwp_security',
				'mainwp_backupwordpress_ext_enabled',
				'mainwp_pagespeed_ext_enabled',
				'mainwp_linkschecker_ext_enabled',
				'mainwp_child_branding_settings',
				'mainwp_child_plugintheme_days_outdate',
			);
			$query    = "SELECT option_name, option_value FROM $wpdb->options WHERE option_name in (";
			foreach ( $options as $option ) {
				$query .= "'" . $option . "', ";
			}
			$query  = substr( $query, 0, strlen( $query ) - 2 );
			$query .= ")"; // phpcs:ignore

			$alloptions_db = $wpdb->get_results( $query ); // phpcs:ignore -- safe query
			$wpdb->suppress_errors( $suppress );
			if ( ! is_array( $alloptions ) ) {
				$alloptions = array();
			}
			if ( is_array( $alloptions_db ) ) {
				foreach ( (array) $alloptions_db as $o ) {
					$alloptions[ $o->option_name ] = $o->option_value;
					unset( $options[ array_search( $o->option_name, $options ) ] );
				}
				foreach ( $options as $option ) {
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
	 * Update MainWP Child Plugin.
	 *
	 * @return string Update verison.
	 */
	public function update() {
		$update_version = get_option( 'mainwp_child_update_version' );

		if ( $update_version === $this->update_version ) {
			return;
		}

		MainWP_Helper::update_option( 'mainwp_child_update_version', $this->update_version, 'yes' );
	}

	/**
	 * Load MainWP Child Plugin textdomains.
	 */
	public function localization() {
		load_plugin_textdomain( 'mainwp-child', false, dirname( dirname( plugin_basename( __FILE__ ) ) ) . '/languages/' );
	}

	/**
	 * Template redirect.
	 */
	public function template_redirect() {
		MainWP_Utility::instance()->maintenance_alert();
	}

	/**
	 *
	 */
	public function parse_init() {

		if ( isset( $_REQUEST['cloneFunc'] ) ) {

			// if not valid result then return.
			$valid_clone = MainWP_Clone_Install::get()->request_clone_funct();
			// not valid clone.
			if ( ! $valid_clone ) {
				return;
			}
		}

		/** @var global $wp_rewrite Core class used to implement a rewrite component API. */
		global $wp_rewrite;

		$snPluginDir = basename( $this->plugin_dir );
		if ( isset( $wp_rewrite->non_wp_rules[ 'wp-content/plugins/' . $snPluginDir . '/([^js\/]*)$' ] ) ) {
			unset( $wp_rewrite->non_wp_rules[ 'wp-content/plugins/' . $snPluginDir . '/([^js\/]*)$' ] );
		}

		if ( isset( $wp_rewrite->non_wp_rules[ 'wp-content/plugins/' . $snPluginDir . '/(.*)$' ] ) ) {
			unset( $wp_rewrite->non_wp_rules[ 'wp-content/plugins/' . $snPluginDir . '/(.*)$' ] );
		}

		if ( get_option( 'mainwp_child_fix_htaccess' ) === false ) {
			include_once ABSPATH . '/wp-admin/includes/misc.php';

			$wp_rewrite->flush_rules();
			MainWP_Helper::update_option( 'mainwp_child_fix_htaccess', 'yes', 'yes' );
		}

		// if login required.
		if ( isset( $_REQUEST['login_required'] ) && ( '1' === $_REQUEST['login_required'] ) && isset( $_REQUEST['user'] ) ) {
			$valid_login_required = MainWP_Connect::instance()->parse_login_required();
			// return parse init if login required are not valid.
			if ( ! $valid_login_required ) {
				return;
			}
		}

		/**
		 * Security
		 */
		MainWP_Security::fix_all();
		MainWP_Debug::process( $this );

		// Register does not require auth, so we register here.
		if ( isset( $_POST['function'] ) && 'register' === $_POST['function'] ) {
			define( 'DOING_CRON', true );
			MainWP_Utility::fix_for_custom_themes();
			MainWP_Connect::instance()->register_site(); // register the site and exit.
		}

		// auth here.
		$auth = MainWP_Connect::instance()->auth( isset( $_POST['mainwpsignature'] ) ? $_POST['mainwpsignature'] : '', isset( $_POST['function'] ) ? $_POST['function'] : '', isset( $_POST['nonce'] ) ? $_POST['nonce'] : '', isset( $_POST['nossl'] ) ? $_POST['nossl'] : 0 );

		// parse auth, if it is not correct actions then exit with message or return.
		if ( ! MainWP_Connect::instance()->parse_init_auth( $auth ) ) {
			return;
		}

		$this->parse_init_extensions();

		global $_wp_submenu_nopriv;
		if ( null === $_wp_submenu_nopriv ) {
			$_wp_submenu_nopriv = array(); // phpcs:ignore -- to fix warning.
		}

		// execute callable functions here.
		MainWP_Child_Callable::get_instance()->init_call_functions( $auth );

		MainWP_Keyword_Links::instance()->parse_init_keyword_links();
	}

	/**
	 * Check login.
	 */
	public function init_check_login() {
		MainWP_Connect::instance()->check_login();
	}

	/**
	 * If user is administrator initiate the admin ajax.
	 */
	public function admin_init() {
		if ( MainWP_Helper::is_admin() && is_admin() ) {
			MainWP_Clone::get()->init_ajax();
		}
	}

	/**
	 * Parse MainWP Extension initiations.
	 */
	private function parse_init_extensions() {
		// Handle fatal errors for those init if needed.
		MainWP_Child_Branding::instance()->branding_init();
		MainWP_Client_Report::instance()->creport_init();
		\MainWP_Child_IThemes_Security::instance()->ithemes_init();
		\MainWP_Child_Updraft_Plus_Backups::instance()->updraftplus_init();
		\MainWP_Child_Back_Up_WordPress::instance()->init();
		\MainWP_Child_WP_Rocket::instance()->init();
		\MainWP_Child_Back_WP_Up::instance()->init();
		\MainWP_Child_Back_Up_Buddy::instance();
		\MainWP_Child_Wordfence::instance()->wordfence_init();
		\MainWP_Child_Timecapsule::instance()->init();
		\MainWP_Child_Staging::instance()->init();
		\MainWP_Child_Pagespeed::instance()->init();
		\MainWP_Child_Links_Checker::instance()->init();
		\MainWP_Child_WPvivid_BackupRestore::instance()->init();
	}

	/**
	 * Hook to deactivate MainWP Child Plugin.
	 *
	 * @param bool $deact Whether or not to deactivate pugin. Default: true.
	 */
	public function deactivation( $deact = true ) {

		$mu_plugin_enabled = apply_filters( 'mainwp_child_mu_plugin_enabled', false );
		if ( $mu_plugin_enabled ) {
			return;
		}

		$to_delete   = array(
			'mainwp_child_pubkey',
			'mainwp_child_nonce',
			'mainwp_child_nossl',
			'mainwp_child_nossl_key',
			'mainwp_security',
			'mainwp_child_server',
		);
		$to_delete[] = 'mainwp_ext_snippets_enabled';
		$to_delete[] = 'mainwp_ext_code_snippets';

		foreach ( $to_delete as $delete ) {
			if ( get_option( $delete ) ) {
				delete_option( $delete );
				wp_cache_delete( $delete, 'options' );
			}
		}

		if ( $deact ) {
			do_action( 'mainwp_child_deactivation' );
		}
	}

	/**
	 * Hook to deactivate Child Plugin.
	 */
	public function activation() {
		$mu_plugin_enabled = apply_filters( 'mainwp_child_mu_plugin_enabled', false );
		if ( $mu_plugin_enabled ) {
			return;
		}

		$to_delete = array(
			'mainwp_child_pubkey',
			'mainwp_child_nonce',
			'mainwp_child_nossl',
			'mainwp_child_nossl_key',
		);
		foreach ( $to_delete as $delete ) {
			if ( get_option( $delete ) ) {
				delete_option( $delete );
			}
		}

		MainWP_Helper::update_option( 'mainwp_child_activated_once', true );

		// delete bad data if existed.
		$to_delete = array( 'mainwp_ext_snippets_enabled', 'mainwp_ext_code_snippets' );
		foreach ( $to_delete as $delete ) {
			delete_option( $delete );
		}
	}

}
