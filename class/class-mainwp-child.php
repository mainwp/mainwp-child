<?php
/**
 * MainWP Child
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

// phpcs:disable -- required for debugging.
if ( isset( $_REQUEST['mainwpsignature'] ) ) {
	// if not debug.
	if ( ! defined('MAINWP_CHILD_DEBUG') || false == MAINWP_CHILD_DEBUG ) {
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
 * Manage all MainWP features.
 */
class MainWP_Child {

	/**
	 * Public static variable containing the latest MainWP Child plugin version.
	 *
	 * @var string MainWP Child plugin version.
	 */
	public static $version = '4.4.1.1';

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
	 * Private variable containing the MainWP Child plugin directory.
	 *
	 * @var string MainWP Child plugin directory.
	 */
	private $plugin_dir;

	/**
	 * MainWP_Child constructor.
	 *
	 * Run any time class is called.
	 *
	 * @param resource $plugin_file MainWP Child plugin file.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Branding::save_branding_options()
	 * @uses \MainWP\Child\MainWP_Child_Plugins_Check::instance()
	 * @uses \MainWP\Child\MainWP_Child_Server_Information::init()
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
		$this->load_all_options();

		$this->plugin_slug = plugin_basename( $plugin_file );

		add_action( 'template_redirect', array( $this, 'template_redirect' ) );
		add_action( 'init', array( &$this, 'init_check_login' ), 1 );
		add_action( 'init', array( &$this, 'parse_init' ), 9999 );
		add_action( 'init', array( &$this, 'localization' ), 33 );
		add_action( 'admin_init', array( &$this, 'admin_init' ) );
		add_action( 'plugin_action_links_mainwp-child/mainwp-child.php', array( &$this, 'plugin_settings_link' ) );

		// support for better detection for premium plugins.
		add_action( 'pre_current_active_plugins', array( MainWP_Child_Updates::get_instance(), 'detect_premium_themesplugins_updates' ) );

		// support for better detection for premium themes.
		add_action( 'core_upgrade_preamble', array( MainWP_Child_Updates::get_instance(), 'detect_premium_themesplugins_updates' ) );

		MainWP_Pages::get_instance()->init();
		MainWP_Child_Cache_Purge::instance();

		if ( is_admin() ) {
			MainWP_Helper::update_option( 'mainwp_child_plugin_version', self::$version, 'yes' );
		}

		MainWP_Connect::instance()->check_other_auth();

		MainWP_Clone::instance()->init();
		MainWP_Child_Server_Information::init();
		MainWP_Client_Report::instance()->init();
		MainWP_Child_Plugins_Check::instance();
		MainWP_Child_Themes_Check::instance();
		MainWP_Utility::instance()->run_saved_snippets();

		/**
		 * Initiate Branding Options.
		 */
		if ( ! get_option( 'mainwp_child_pubkey' ) ) {
			MainWP_Child_Branding::instance()->save_branding_options( 'branding_disconnected', 'yes' );
		}

		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			if ( isset( $_GET['mainwp_child_run'] ) && ! empty( $_GET['mainwp_child_run'] ) ) {
				add_action( 'init', array( MainWP_Utility::get_class_name(), 'cron_active' ), PHP_INT_MAX );
			}
		}

		/**
		 * Action to response data result.
		 *
		 * @since 4.3
		 */
		add_action( 'mainwp_child_write', array( MainWP_Helper::class, 'write' ) );
	}

	/**
	 * Method load_all_options()
	 *
	 * Load all MainWP Child plugin options.
	 *
	 * @return array|bool Return array of options or false on failure.
	 */
	public function load_all_options() {

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

		if ( ! isset( $alloptions['mainwp_child_server'] ) ) {
			$suppress = $wpdb->suppress_errors();
			$options  = array(
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
				'mainwp_child_nossl',
				'mainwp_security',
				'mainwp_backupwordpress_ext_enabled',
				'mainwp_pagespeed_ext_enabled',
				'mainwp_linkschecker_ext_enabled',
				'mainwp_child_branding_settings',
				'mainwp_child_plugintheme_days_outdate',
				'mainwp_wp_staging_ext_enabled',
				'mainwp_child_connected_admin',
				'mainwp_child_actions_saved_number_of_days',

			);
			$query = "SELECT option_name, option_value FROM $wpdb->options WHERE option_name in (";
			foreach ( $options as $option ) {
				$query .= "'" . $option . "', ";
			}
			$query  = substr( $query, 0, strlen( $query ) - 2 );
			$query .= ")"; // phpcs:ignore -- simple style problem.

			$alloptions_db = $wpdb->get_results( $query ); // phpcs:ignore -- safe query, required to achieve desired results, pull request solutions appreciated.
			$wpdb->suppress_errors( $suppress );
			if ( ! is_array( $alloptions ) ) {
				$alloptions = array();
			}
			if ( is_array( $alloptions_db ) ) {
				foreach ( (array) $alloptions_db as $o ) {
					$alloptions[ $o->option_name ] = $o->option_value;
					unset( $options[ array_search( $o->option_name, $options ) ] );
				}
				if ( ! is_array( $notoptions ) ) {
					$notoptions = array();
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

		$mainwpsignature = isset( $_POST['mainwpsignature'] ) ? rawurldecode( wp_unslash( $_POST['mainwpsignature'] ) ) : '';
		$function        = isset( $_POST['function'] ) ? sanitize_text_field( wp_unslash( $_POST['function'] ) ) : null;
		$nonce           = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		$nossl           = isset( $_POST['nossl'] ) ? sanitize_text_field( wp_unslash( $_POST['nossl'] ) ) : 0;

		// Authenticate here.
		$auth = MainWP_Connect::instance()->auth( $mainwpsignature, $function, $nonce, $nossl );

		// Parse auth, if it is not correct actions then exit with message or return.
		if ( ! MainWP_Connect::instance()->parse_init_auth( $auth ) ) {
			return;
		}

		$this->parse_init_extensions();

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
	 * Method admin_init()
	 *
	 * If the current user is administrator initiate the admin ajax.
	 *
	 * @uses \MainWP\Child\MainWP_Clone::init_ajax()
	 * @uses \MainWP\Child\MainWP_Helper::is_admin()
	 */
	public function admin_init() {
		if ( MainWP_Helper::is_admin() && is_admin() ) {
			MainWP_Clone::instance()->init_ajax();
		}
		MainWP_Child_Actions::get_instance()->init_hooks();
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
	}

	/**
	 * Method deactivation()
	 *
	 * Deactivate the MainWP Child plugin and delete unwanted data.
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
			'mainwp_child_connected_admin',
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
	 * Method activation()
	 *
	 * Activate the MainWP Child plugin and delete unwanted data.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::update_option()
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
			'mainwp_child_connected_admin',
		);
		foreach ( $to_delete as $delete ) {
			if ( get_option( $delete ) ) {
				delete_option( $delete );
			}
		}

		MainWP_Helper::update_option( 'mainwp_child_activated_once', true );

		$to_delete = array( 'mainwp_ext_snippets_enabled', 'mainwp_ext_code_snippets' );
		foreach ( $to_delete as $delete ) {
			delete_option( $delete );
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
}
