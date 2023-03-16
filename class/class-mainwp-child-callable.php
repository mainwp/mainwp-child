<?php
/**
 * MainWP Callable Functions
 *
 * Manage functions that can be executed on the child site.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

// phpcs:disable WordPress.WP.AlternativeFunctions --  to use external code, third party credit.

/**
 * Class MainWP_Child_Callable
 *
 * Manage functions that can be executed on the child site.
 */
class MainWP_Child_Callable {

	/**
	 * Public static variable to hold the single instance of the class.
	 *
	 * @var mixed Default null
	 */
	protected static $instance = null;

	/**
	 * Private variable to hold the array of all callable functions.
	 *
	 * @var array Callable functions.
	 */
	private $callableFunctions = array(
		'stats'                 => 'get_site_stats',
		'upgrade'               => 'upgrade_wp',
		'newpost'               => 'new_post',
		'deactivate'            => 'deactivate',
		'newuser'               => 'new_user',
		'newadminpassword'      => 'new_admin_password',
		'installplugintheme'    => 'install_plugin_theme',
		'upgradeplugintheme'    => 'upgrade_plugin_theme',
		'upgradetranslation'    => 'upgrade_translation',
		'backup'                => 'backup',
		'backup_checkpid'       => 'backup_checkpid',
		'cloneinfo'             => 'cloneinfo',
		'security'              => 'get_security_stats',
		'securityFix'           => 'do_security_fix',
		'securityUnFix'         => 'do_security_un_fix',
		'post_action'           => 'post_action',
		'get_all_posts'         => 'get_all_posts',
		'comment_action'        => 'comment_action',
		'comment_bulk_action'   => 'comment_bulk_action',
		'get_all_comments'      => 'get_all_comments',
		'get_all_themes'        => 'get_all_themes',
		'theme_action'          => 'theme_action',
		'get_all_plugins'       => 'get_all_plugins',
		'plugin_action'         => 'plugin_action',
		'get_all_pages'         => 'get_all_pages',
		'get_all_users'         => 'get_all_users',
		'user_action'           => 'user_action',
		'search_users'          => 'search_users',
		'maintenance_site'      => 'maintenance_site',
		'branding_child_plugin' => 'branding_child_plugin',
		'code_snippet'          => 'code_snippet',
		'uploader_action'       => 'uploader_action',
		'wordpress_seo'         => 'wordpress_seo',
		'client_report'         => 'client_report',
		'createBackupPoll'      => 'backup_poll',
		'page_speed'            => 'page_speed',
		'woo_com_status'        => 'woo_com_status',
		'links_checker'         => 'links_checker',
		'wordfence'             => 'wordfence',
		'delete_backup'         => 'delete_backup',
		'update_values'         => 'update_child_values',
		'ithemes'               => 'ithemes',
		'updraftplus'           => 'updraftplus',
		'backup_wp'             => 'backup_wp',
		'backwpup'              => 'backwpup',
		'wp_rocket'             => 'wp_rocket',
		'settings_tools'        => 'settings_tools',
		'skeleton_key'          => 'bulk_settings_manager', // deprecated.
		'bulk_settings_manager' => 'bulk_settings_manager',
		'custom_post_type'      => 'custom_post_type',
		'backup_buddy'          => 'backup_buddy',
		'get_site_icon'         => 'get_site_icon',
		'vulner_checker'        => 'vulner_checker',
		'wp_staging'            => 'wp_staging',
		'disconnect'            => 'disconnect',
		'time_capsule'          => 'time_capsule',
		'extra_excution'        => 'extra_execution', // deprecated!
		'extra_execution'       => 'extra_execution',
		'wpvivid_backuprestore' => 'wpvivid_backuprestore',
		'check_abandoned'       => 'check_abandoned',
		'wp_seopress'           => 'wp_seopress',
		'db_updater'            => 'db_updater',
		'cache_purge_action'    => 'cache_purge_action',
		'jetpack_protect'       => 'jetpack_protect',
		'jetpack_scan'          => 'jetpack_scan',
		'delete_actions'        => 'delete_actions',
	);

	/**
	 * Private variable to hold the array of all callable functions that don't require regularl authentication.
	 *
	 * @var array Callable functions.
	 */
	private $callableFunctionsNoAuth = array(
		'stats' => 'get_site_stats_no_auth',
	);

	/**
	 * Method get_class_name()
	 *
	 * Get class name.
	 *
	 * @return string __CLASS__ Class name.
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * MainWP_Child_Callable constructor.
	 *
	 * Run any time class is called.
	 */
	public function __construct() {
	}

	/**
	 * Method instance()
	 *
	 * Create a public static instance.
	 *
	 * @return mixed Class instance.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Method init_call_functions()
	 *
	 * Initiate callable functions.
	 *
	 * @param bool $auth If true, regular authentication is required.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::instance()->error()
	 * @uses \MainWP\Child\MainWP_Utility::handle_fatal_error()
	 * @uses \MainWP\Child\MainWP_Utility::fix_for_custom_themes()
	 */
	public function init_call_functions( $auth = false ) {
		$callable         = false;
		$callable_no_auth = false;
		$call_func        = false;

		// check if function is callable.
		if ( isset( $_POST['function'] ) ) {
			$call_func        = isset( $_POST['function'] ) ? sanitize_text_field( wp_unslash( $_POST['function'] ) ) : '';
			$callable         = $this->is_callable_function( $call_func ); // check callable func.
			$callable_no_auth = $this->is_callable_function_no_auth( $call_func ); // check callable no auth func.
		}

		// Fire off the called function.
		if ( $auth && isset( $_POST['function'] ) && $callable ) {
			MainWP_Helper::maybe_set_doing_cron();
			MainWP_Utility::handle_fatal_error();
			MainWP_Utility::fix_for_custom_themes();
			$this->call_function( $call_func );
		} elseif ( isset( $_POST['function'] ) && $callable_no_auth ) {

			MainWP_Helper::maybe_set_doing_cron();

			MainWP_Utility::fix_for_custom_themes();
			$this->call_function_no_auth( $call_func );
		} elseif ( isset( $_POST['function'] ) && isset( $_POST['mainwpsignature'] ) && ! $callable && ! $callable_no_auth ) {
			MainWP_Helper::instance()->error( esc_html__( 'Required version has not been detected. Please, make sure that you are using the latest version of the MainWP Child plugin on your site.', 'mainwp-child' ) );
		}
	}

	/**
	 * Method is_callable_function()
	 *
	 * Check if the function is the list of callable functions.
	 *
	 * @param string $func Contains the name of the function to check.
	 *
	 * @return bool If callable, return true, if not, return false.
	 */
	public function is_callable_function( $func ) {
		if ( isset( $this->callableFunctions[ $func ] ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Method is_callable_function_no_auth()
	 *
	 * Check if the function is the list of callable functions that don't require regular authentication.
	 *
	 * @param string $func Contains the name of the function to check.
	 *
	 * @return bool If callable, return true, if not, return false.
	 */
	public function is_callable_function_no_auth( $func ) {
		if ( isset( $this->callableFunctionsNoAuth[ $func ] ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Method call_function()
	 *
	 * Call ceratin function.
	 *
	 * @param string $func Contains the name of the function to call.
	 */
	public function call_function( $func ) {
		if ( $this->is_callable_function( $func ) ) {
			call_user_func( array( $this, $this->callableFunctions[ $func ] ) );
		}
	}

	/**
	 * Method call_function_no_auth()
	 *
	 * Call ceratin function without regular authentication if the function is in the $callableFunctionsNoAuth list.
	 *
	 * @param string $func Contains the name of the function to call.
	 */
	public function call_function_no_auth( $func ) {
		if ( $this->is_callable_function_no_auth( $func ) ) {
			call_user_func( array( $this, $this->callableFunctionsNoAuth[ $func ] ) );
		}
	}

	/**
	 * Method get_site_stats()
	 *
	 * Fire off the get_site_stats() function.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Stats::get_site_stats()
	 */
	public function get_site_stats() {
		MainWP_Child_Stats::get_instance()->get_site_stats();
	}

	/**
	 * Method get_site_stats_no_auth()
	 *
	 * Fire off the get_site_stats_no_auth() function.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Stats::get_site_stats_no_auth()
	 */
	public function get_site_stats_no_auth() {
		MainWP_Child_Stats::get_instance()->get_site_stats_no_auth();
	}

	/**
	 * Method install_plugin_theme()
	 *
	 * Fire off the install_plugin_theme() function.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Install::install_plugin_theme()
	 */
	public function install_plugin_theme() {
		MainWP_Child_Install::get_instance()->install_plugin_theme();
	}

	/**
	 * Method upgrade_wp()
	 *
	 * Fire off the upgrade_wp() function.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Updates::upgrade_wp()
	 */
	public function upgrade_wp() {
		MainWP_Child_Updates::get_instance()->upgrade_wp();
	}

	/**
	 * Method upgrade_translation()
	 *
	 * Fire off the upgrade_translation() function.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Updates::upgrade_translation()
	 */
	public function upgrade_translation() {
		MainWP_Child_Updates::get_instance()->upgrade_translation();
	}

	/**
	 * Method upgrade_plugin_theme()
	 *
	 * Fire off the upgrade_plugin_theme() function.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Updates::upgrade_plugin_theme()
	 */
	public function upgrade_plugin_theme() {
		MainWP_Child_Updates::get_instance()->upgrade_plugin_theme();
	}

	/**
	 * Method theme_action()
	 *
	 * Fire off the theme_action() function.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Install::theme_action()
	 */
	public function theme_action() {
		MainWP_Child_Install::get_instance()->theme_action();
	}

	/**
	 * Method plugin_action()
	 *
	 * Fire off the plugin_action() function.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Install::plugin_action()
	 */
	public function plugin_action() {
		MainWP_Child_Install::get_instance()->plugin_action();
	}

	/**
	 * Method get_all_plugins()
	 *
	 * Fire off the get_all_plugins() function.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Stats::get_all_plugins()
	 */
	public function get_all_plugins() {
		MainWP_Child_Stats::get_instance()->get_all_plugins();
	}

	/**
	 * Method get_all_themes()
	 *
	 * Fire off the get_all_themes() function.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Stats::get_all_themes()
	 */
	public function get_all_themes() {
		MainWP_Child_Stats::get_instance()->get_all_themes();
	}

	/**
	 * Method get_all_users()
	 *
	 * Fire off the get_all_users() function.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Users::get_all_users()
	 */
	public function get_all_users() {
		MainWP_Child_Users::get_instance()->get_all_users();
	}

	/**
	 * Method user_action()
	 *
	 * Fire off the user_action() function.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Users::user_action()
	 */
	public function user_action() {
		MainWP_Child_Users::get_instance()->user_action();
	}

	/**
	 * Method search_users()
	 *
	 * Fire off the search_users() function.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Users::search_users()
	 */
	public function search_users() {
		MainWP_Child_Users::get_instance()->search_users();
	}

	/**
	 * Method get_all_posts()
	 *
	 * Fire off the get_all_posts() function.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Posts::get_all_posts()
	 */
	public function get_all_posts() {
		MainWP_Child_Posts::get_instance()->get_all_posts();
	}

	/**
	 * Method get_all_pages()
	 *
	 * Fire off the get_all_pages() function.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Posts::get_all_pages()
	 */
	public function get_all_pages() {
		MainWP_Child_Posts::get_instance()->get_all_pages();
	}

	/**
	 * Method comment_action()
	 *
	 * Fire off the comment_action() function.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Comments::comment_action()
	 */
	public function comment_action() {
		MainWP_Child_Comments::get_instance()->comment_action();
	}

	/**
	 * Method get_all_comments()
	 *
	 * Fire off the get_all_comments() function.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Comments::get_all_comments()
	 */
	public function get_all_comments() {
		MainWP_Child_Comments::get_instance()->get_all_comments();
	}

	/**
	 * Method comment_bulk_action()
	 *
	 * Fire off the comment_bulk_action() function.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Comments::comment_bulk_action()
	 */
	public function comment_bulk_action() {
		MainWP_Child_Comments::get_instance()->comment_bulk_action();
	}

	/**
	 * Method maintenance_site()
	 *
	 * Fire off the maintenance_site() function.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Maintenance::maintenance_site()
	 */
	public function maintenance_site() {
		MainWP_Child_Maintenance::get_instance()->maintenance_site();
	}

	/**
	 * Method new_post()
	 *
	 * Fire off the new_post() function.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Posts::new_post()
	 */
	public function new_post() {
		MainWP_Child_Posts::get_instance()->new_post();
	}

	/**
	 * Method post_action()
	 *
	 * Fire off the post_action() function.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Posts::post_action()
	 */
	public function post_action() {
		MainWP_Child_Posts::get_instance()->post_action();
	}

	/**
	 * Method new_admin_password()
	 *
	 * Fire off the new_admin_password() function.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Users::new_admin_password()
	 */
	public function new_admin_password() {
		MainWP_Child_Users::get_instance()->new_admin_password();
	}

	/**
	 * Method new_user()
	 *
	 * Fire off the new_user() function.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Users::new_user()
	 */
	public function new_user() {
		MainWP_Child_Users::get_instance()->new_user();
	}

	/**
	 * Method cloneinfo()
	 *
	 * Fire off the cloneinfo() function.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::write()
	 */
	public function cloneinfo() {

		/**
		 * WordPress DB Table Prefix.
		 *
		 * @global string
		 */
		global $table_prefix;

		$information['dbCharset']    = DB_CHARSET;
		$information['dbCollate']    = DB_COLLATE;
		$information['table_prefix'] = $table_prefix;
		$information['site_url']     = get_option( 'site_url' );
		$information['home']         = get_option( 'home' );

		MainWP_Helper::write( $information );
	}

	/**
	 * Method backup_poll()
	 *
	 * Fire off the backup_poll() function.
	 *
	 * @uses \MainWP\Child\MainWP_Backup::backup_poll()
	 */
	public function backup_poll() {
		MainWP_Backup::get()->backup_poll();
	}

	/**
	 * Method backup_checkpid()
	 *
	 * Fire off the backup_checkpid() function.
	 *
	 * @uses \MainWP\Child\MainWP_Backup::backup_checkpid()
	 */
	public function backup_checkpid() {
		MainWP_Backup::get()->backup_checkpid();
	}

	/**
	 * Method backup()
	 *
	 * Fire off the backup() function.
	 *
	 * @param bool $write Whether or not to execute MainWP_Helper::write(), Default: true.
	 *
	 * @return array Action result.
	 *
	 * @uses \MainWP\Child\MainWP_Backup::backup()
	 */
	public function backup( $write = true ) {
		return MainWP_Backup::get()->backup( $write );
	}

	/**
	 * Method backup_full()
	 *
	 * Fire off the backup_full() function.
	 *
	 * @param string $file_name Contains the backup file name.
	 *
	 * @return array Action result.
	 *
	 * @uses \MainWP\Child\MainWP_Backup::backup_full()
	 */
	protected function backup_full( $file_name ) {
		return MainWP_Backup::get()->backup_full( $file_name );
	}

	/**
	 * Method backup_db()
	 *
	 * Fire off the backup_db() function.
	 *
	 * @param string $file_name      Contains the backup file name.
	 * @param string $file_extension Contains the backup file extension.
	 *
	 * @return array Action result.
	 *
	 * @uses \MainWP\Child\MainWP_Backup::backup_db()
	 */
	protected function backup_db( $file_name = '', $file_extension = 'zip' ) {
		return MainWP_Backup::get()->backup_db( $file_name, $file_extension );
	}

	/**
	 * Method get_site_icon()
	 *
	 * Fire off the get_site_icon() function.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Misc::get_site_icon()
	 */
	public function get_site_icon() {
		MainWP_Child_Misc::get_instance()->get_site_icon();
	}

	/**
	 * Method check_abandoned()
	 *
	 * Fire off the check_abandoned() function.
	 */
	public function check_abandoned() {
		$which = sanitize_text_field( wp_unslash( $_POST['which'] ) );
		$infor = array();
		if ( 'plugin' == $which ) {
			MainWP_Child_Plugins_Check::instance()->run_check();
			$infor['success'] = 1;
		} else {
			MainWP_Child_Themes_Check::instance()->run_check();
			$infor['success'] = 1;
		}
		$infor['sync'] = MainWP_Child_Stats::get_instance()->get_site_stats( array(), false );
		MainWP_Helper::write( $infor );
	}

	/**
	 * Method get_security_stats()
	 *
	 * Fire off the get_security_stats() function.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Misc::get_security_stats()
	 */
	public function get_security_stats() {
		MainWP_Child_Misc::get_instance()->get_security_stats();
	}

	/**
	 * Method do_security_fix()
	 *
	 * Fire off the do_security_fix() function.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Misc::do_security_fix()
	 */
	public function do_security_fix() {
		MainWP_Child_Misc::get_instance()->do_security_fix();
	}

	/**
	 * Method do_security_un_fix()
	 *
	 * Fire off the do_security_un_fix() function.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Misc::do_security_un_fix()
	 */
	public function do_security_un_fix() {
		MainWP_Child_Misc::get_instance()->do_security_un_fix();
	}

	/**
	 * Method settings_tools()
	 *
	 * Fire off the settings_tools() function.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Misc::do_security_un_fix()
	 */
	public function settings_tools() {
		MainWP_Child_Misc::get_instance()->do_security_un_fix();
	}

	/**
	 * Method bulk_settings_manager()
	 *
	 * Fire off the action() function.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Bulk_Settings_Manager::action()
	 */
	public function bulk_settings_manager() {
		MainWP_Child_Bulk_Settings_Manager::instance()->action();
	}

	/**
	 * Method custom_post_type()
	 *
	 * Fire off the action() function.
	 *
	 * @uses \MainWP\Child\MainWP_Custom_Post_Type::action()
	 */
	public function custom_post_type() {
		MainWP_Custom_Post_Type::instance()->action();
	}

	/**
	 * Method backup_buddy()
	 *
	 * Fire off the action() function.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::action()
	 */
	public function backup_buddy() {
		MainWP_Child_Back_Up_Buddy::instance()->action();
	}

	/**
	 * Method vulner_checker()
	 *
	 * Fire off the action() function.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Vulnerability_Checker::action()
	 */
	public function vulner_checker() {
		MainWP_Child_Vulnerability_Checker::instance()->action();
	}

	/**
	 * Method time_capsule()
	 *
	 * Fire off the action() function.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Timecapsule::action()
	 */
	public function time_capsule() {
		MainWP_Child_Timecapsule::instance()->action();
	}

	/**
	 * Method wp_staging()
	 *
	 * Fire off the action() function.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Staging::action()
	 */
	public function wp_staging() {
		MainWP_Child_Staging::instance()->action();
	}

	/**
	 * Method extra_execution()
	 *
	 * Additional functions to execute.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::write()
	 */
	public function extra_execution() {
		$post        = $_POST;
		$information = array();
		/**
		 * Filter 'mainwp_child_extra_execution'
		 *
		 * Additional functions to execute through the filter.
		 *
		 * @param array $information An array containing the synchronization information.
		 * @param mixed $post Contains the POST request.
		 *
		 * @since 4.0
		 */
		$information = apply_filters( 'mainwp_child_extra_execution', $information, $post );
		MainWP_Helper::write( $information );
	}

	/**
	 * Method uploader_action()
	 *
	 * Fire off the uploader_action() function.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Misc::uploader_action()
	 */
	public function uploader_action() {
		MainWP_Child_Misc::get_instance()->uploader_action();
	}

	/**
	 * Method wordpress_seo()
	 *
	 * Fire off the action() function.
	 */
	public function wordpress_seo() {
		MainWP_WordPress_SEO::instance()->action();
	}

	/**
	 * Method wp_seopress()
	 *
	 * Fire off the action() function.
	 *
	 * @uses \MainWP\Child\MainWP_Child_WP_Seopress::action();
	 */
	public function wp_seopress() {
		MainWP_Child_WP_Seopress::instance()->action();
	}

	/**
	 * Method client_report()
	 *
	 * Fire off the action() function.
	 *
	 * @uses \MainWP\Child\MainWP_Client_Report::action()
	 */
	public function client_report() {
		MainWP_Client_Report::instance()->action();
	}

	/**
	 * Method page_speed()
	 *
	 * Fire off the action() function.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Pagespeed::action()
	 */
	public function page_speed() {
		MainWP_Child_Pagespeed::instance()->action();
	}

	/**
	 * Method woo_com_status()
	 *
	 * Fire off the action() function.
	 *
	 * @uses \MainWP\Child\MainWP_Child_WooCommerce_Status::action()
	 */
	public function woo_com_status() {
		MainWP_Child_WooCommerce_Status::instance()->action();
	}

	/**
	 * Method links_checker()
	 *
	 * Fire off the action() function.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Links_Checker::action()
	 */
	public function links_checker() {
		MainWP_Child_Links_Checker::instance()->action();
	}

	/**
	 * Method wordfence()
	 *
	 * Fire off the action() function.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Wordfence::action()
	 */
	public function wordfence() {
		MainWP_Child_Wordfence::instance()->action();
	}

	/**
	 * Method ithemes()
	 *
	 * Fire off the action() function.
	 *
	 * @uses \MainWP\Child\MainWP_Child_IThemes_Security::action()
	 */
	public function ithemes() {
		MainWP_Child_IThemes_Security::instance()->action();
	}

	/**
	 * Method updraftplus()
	 *
	 * Fire off the action() function.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Updraft_Plus_Backups::action()
	 */
	public function updraftplus() {
		MainWP_Child_Updraft_Plus_Backups::instance()->action();
	}

	/**
	 * Method wpvivid_backuprestore()
	 *
	 * Fire off the action() function.
	 *
	 * @uses \MainWP\Child\MainWP_Child_WPvivid_BackupRestore::action()
	 */
	public function wpvivid_backuprestore() {
		MainWP_Child_WPvivid_BackupRestore::instance()->action();
	}

	/**
	 * Method backup_wp()
	 *
	 * Fire off the action() function.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Back_Up_WordPress::action()
	 * @uses \MainWP\Child\MainWP_Helper::write()
	 */
	public function backup_wp() {
		if ( ! version_compare( phpversion(), '5.3', '>=' ) ) {
			$error = sprintf( esc_html__( 'PHP Version %s is unsupported.', 'mainwp-child' ), phpversion() );
			MainWP_Helper::write( array( 'error' => $error ) );
		}
		MainWP_Child_Back_Up_WordPress::instance()->action();
	}

	/**
	 * Method wp_rocket()
	 *
	 * Fire off the action() function.
	 *
	 * @uses \MainWP\Child\MainWP_Child_WP_Rocket::action()
	 */
	public function wp_rocket() {
		MainWP_Child_WP_Rocket::instance()->action();
	}

	/**
	 * Method backwpup()
	 *
	 * Fire off the action() function.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Back_WP_Up::action()
	 */
	public function backwpup() {
		MainWP_Child_Back_WP_Up::instance()->action();
	}


	/**
	 * Method db_updater()
	 *
	 * Fire off the action() function.
	 */
	public function db_updater() {
		MainWP_Child_DB_Updater::instance()->action();
	}

	/**
	 * Method jetpack_protect()
	 *
	 * Fire off the action() function.
	 */
	public function jetpack_protect() {
		MainWP_Child_Jetpack_Protect::instance()->action();
	}


	/**
	 * Method jetpack_scan()
	 *
	 * Fire off the action() function.
	 */
	public function jetpack_scan() {
		MainWP_Child_Jetpack_Scan::instance()->action();
	}

	/**
	 * Method delete_actions()
	 *
	 * Delete Non-MainWP actions.
	 */
	public function delete_actions() {
		MainWP_Child_Actions::get_instance()->delete_actions();
	}

	/**
	 * Method delete_backup()
	 *
	 * Delete backup.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::get_mainwp_dir()
	 * @uses \MainWP\Child\MainWP_Helper::write()
	 */
	public function delete_backup() {
		$dirs      = MainWP_Helper::get_mainwp_dir( 'backup' );
		$backupdir = $dirs[0];

		$file = isset( $_REQUEST['del'] ) ? wp_unslash( $_REQUEST['del'] ) : '';

		if ( file_exists( $backupdir . $file ) ) {
			unlink( $backupdir . $file );
		}

		MainWP_Helper::write( array( 'result' => 'ok' ) );
	}

	/**
	 * Method update_child_values()
	 *
	 * Update the MainWP Child site options.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::update_option()
	 * @uses \MainWP\Child\MainWP_Helper::write()
	 */
	public function update_child_values() {
		$unique_id = isset( $_POST['uniqueId'] ) ? sanitize_text_field( wp_unslash( $_POST['uniqueId'] ) ) : '';
		MainWP_Helper::update_option( 'mainwp_child_uniqueId', $unique_id );
		MainWP_Helper::write( array( 'result' => 'ok' ) );
	}

	/**
	 * Method branding_child_plugin()
	 *
	 * Fire off the action() function.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Branding::action()
	 */
	public function branding_child_plugin() {
		MainWP_Child_Branding::instance()->action();
	}

	/**
	 * Method update_child_plugin()
	 *
	 * Fire off the action() function.
	 *
	 * @uses MainWP_Child_Cache_Purge::action()
	 * @used-by \MainWP\Extensions\CacheControl\MainWP_Cache_Control_Purge_View::ajax_cache_control_purge_cache_all()
	 */
	public function cache_purge_action() {
		MainWP_Child_Cache_Purge::instance()->auto_purge_cache( 'true' );
	}

	/**
	 * Method code_snippet()
	 *
	 * Fire off the code_snippet() function.
	 *
	 * @uses MainWP_Child_Misc::code_snippet()
	 */
	public function code_snippet() {
		MainWP_Child_Misc::get_instance()->code_snippet();
	}

	/**
	 * Method disconnect()
	 *
	 * Disconnect the child site from the current MainWP Dashboard.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::write()
	 */
	public function disconnect() {

		/**
		 * MainWP Child instance.
		 *
		 * @global object
		 */
		global $mainWPChild;

		$mainWPChild->deactivation( false );
		MainWP_Helper::write( array( 'result' => 'success' ) );
	}


	/**
	 * Method deactivate()
	 *
	 * Deactivate the MainWP Child plugin in the site.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::errpr()
	 * @uses \MainWP\Child\MainWP_Helper::write()
	 */
	public function deactivate() {

		/**
		 * MainWP Child instance.
		 *
		 * @global object
		 */
		global $mainWPChild;

		include_once ABSPATH . 'wp-admin/includes/plugin.php';
		deactivate_plugins( $mainWPChild->plugin_slug, true );
		$information = array();
		if ( is_plugin_active( $mainWPChild->plugin_slug ) ) {
			MainWP_Helper::instance()->error( 'Plugin still active' );
		}
		$information['deactivated'] = true;
		MainWP_Helper::write( $information );
	}

}
