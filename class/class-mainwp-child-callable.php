<?php

namespace MainWP\Child;

// phpcs:disable WordPress.WP.AlternativeFunctions --  to use external code, third party credit.

class MainWP_Child_Callable {

	protected static $instance = null;

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
		'keyword_links_action'  => 'keyword_links_action',
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
		'skeleton_key'          => 'skeleton_key',
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
	);

	private $callableFunctionsNoAuth = array(
		'stats' => 'get_site_stats_no_auth',
	);

	/**
	 * Method get_class_name()
	 *
	 * Get Class Name.
	 *
	 * @return object
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	public function __construct() {
	}

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init_call_functions( $auth = false ) {
		$callable         = false;
		$callable_no_auth = false;
		$call_func        = false;

		// check to execute mainwp child's callable functions.
		if ( isset( $_POST['function'] ) ) {
			$call_func        = $_POST['function'];
			$callable         = $this->is_callable_function( $call_func ); // check callable func.
			$callable_no_auth = $this->is_callable_function_no_auth( $call_func ); // check callable no auth func.
		}

		// Call the function required.
		if ( $auth && isset( $_POST['function'] ) && $callable ) {
			define( 'DOING_CRON', true );
			MainWP_Utility::handle_fatal_error();
			MainWP_Utility::fix_for_custom_themes();
			$this->call_function( $call_func );
		} elseif ( isset( $_POST['function'] ) && $callable_no_auth ) {
			define( 'DOING_CRON', true );
			MainWP_Utility::fix_for_custom_themes();
			$this->call_function_no_auth( $call_func );
		} elseif ( isset( $_POST['function'] ) && isset( $_POST['mainwpsignature'] ) && ! $callable && ! $callable_no_auth ) {
			MainWP_Helper::error( __( 'Required version has not been detected. Please, make sure that you are using the latest version of the MainWP Child plugin on your site.', 'mainwp-child' ) );
		}
	}

	public function is_callable_function( $func ) {
		if ( isset( $this->callableFunctions[ $func ] ) ) {
			return true;
		}
		return false;
	}

	public function is_callable_function_no_auth( $func ) {
		if ( isset( $this->callableFunctionsNoAuth[ $func ] ) ) {
			return true;
		}
		return false;
	}

	public function call_function( $func ) {
		if ( $this->is_callable_function( $func ) ) {
			call_user_func( array( $this, $this->callableFunctions[ $func ] ) );
		}
	}

	public function call_function_no_auth( $func ) {
		if ( $this->is_callable_function_no_auth( $func ) ) {
			call_user_func( array( $this, $this->callableFunctionsNoAuth[ $func ] ) );
		}
	}

	public function get_site_stats() {
		MainWP_Child_Stats::get_instance()->get_site_stats();
	}

	public function get_site_stats_no_auth() {
		MainWP_Child_Stats::get_instance()->get_site_stats_no_auth();
	}

	/**
	 * Functions to support core functionality
	 */
	public function install_plugin_theme() {
		MainWP_Child_Install::get_instance()->install_plugin_theme();
	}

	public function upgrade_wp() {
		MainWP_Child_Updates::get_instance()->upgrade_wp();
	}

	public function upgrade_translation() {
		MainWP_Child_Updates::get_instance()->upgrade_translation();
	}

	public function upgrade_plugin_theme() {
		MainWP_Child_Updates::get_instance()->upgrade_plugin_theme();
	}

	public function theme_action() {
		MainWP_Child_Install::get_instance()->theme_action();
	}

	public function plugin_action() {
		MainWP_Child_Install::get_instance()->plugin_action();
	}

	public function get_all_plugins() {
		MainWP_Child_Stats::get_instance()->get_all_plugins();
	}

	public function get_all_themes() {
		MainWP_Child_Stats::get_instance()->get_all_themes();
	}

	public function get_all_users() {
		MainWP_Child_Users::get_instance()->get_all_users();
	}

	public function user_action() {
		MainWP_Child_Users::get_instance()->user_action();
	}

	public function search_users() {
		MainWP_Child_Users::get_instance()->search_users();
	}

	public function get_all_posts() {
		MainWP_Child_Posts::get_instance()->get_all_posts();
	}

	public function get_all_pages() {
		MainWP_Child_Posts::get_instance()->get_all_pages();
	}

	public function comment_action() {
		MainWP_Child_Comments::get_instance()->comment_action();
	}

	public function get_all_comments() {
		MainWP_Child_Comments::get_instance()->get_all_comments();
	}

	public function comment_bulk_action() {
		MainWP_Child_Comments::get_instance()->comment_bulk_action();
	}

	public function maintenance_site() {
		MainWP_Child_Maintenance::get_instance()->maintenance_site();
	}

	public function new_post() {
		MainWP_Child_Posts::get_instance()->new_post();
	}

	public function post_action() {
		MainWP_Child_Posts::get_instance()->post_action();
	}

	public function new_admin_password() {
		MainWP_Child_Users::get_instance()->new_admin_password();
	}

	public function new_user() {
		MainWP_Child_Users::get_instance()->new_user();
	}

	public function cloneinfo() {
		global $table_prefix;
		$information['dbCharset']    = DB_CHARSET;
		$information['dbCollate']    = DB_COLLATE;
		$information['table_prefix'] = $table_prefix;
		$information['site_url']     = get_option( 'site_url' );
		$information['home']         = get_option( 'home' );

		MainWP_Helper::write( $information );
	}

	public function backup_poll() {
		MainWP_Backup::get()->backup_poll();
	}

	public function backup_checkpid() {
		MainWP_Backup::get()->backup_checkpid();
	}

	public function backup( $pWrite = true ) {
		return MainWP_Backup::get()->backup( $pWrite );
	}

	protected function backup_full( $fileName ) {
		return MainWP_Backup::get()->backup_full( $fileName );
	}

	protected function backup_db( $fileName = '', $ext = 'zip' ) {
		return MainWP_Backup::get()->backup_db( $fileName, $ext );
	}

	public function get_site_icon() {
		MainWP_Child_Misc::get_instance()->get_site_icon();
	}

	public function get_security_stats() {
		MainWP_Child_Misc::get_instance()->get_security_stats();
	}

	public function do_security_fix() {
		MainWP_Child_Misc::get_instance()->do_security_fix();
	}

	public function do_security_un_fix() {
		MainWP_Child_Misc::get_instance()->do_security_un_fix();
	}

	public function settings_tools() {
		MainWP_Child_Misc::get_instance()->do_security_un_fix();
	}

	public function skeleton_key() {
		MainWP_Child_Skeleton_Key::instance()->action();
	}

	public function custom_post_type() {
		MainWP_Custom_Post_Type::instance()->action();
	}

	public function backup_buddy() {
		\MainWP_Child_Back_Up_Buddy::instance()->action();
	}

	public function vulner_checker() {
		MainWP_Child_Vulnerability_Checker::instance()->action();
	}

	public function time_capsule() {
		\MainWP_Child_Timecapsule::instance()->action();
	}

	public function wp_staging() {
		\MainWP_Child_Staging::instance()->action();
	}

	public function extra_execution() {
		$post        = $_POST;
		$information = array();
		$information = apply_filters( 'mainwp_child_extra_execution', $information, $post );
		MainWP_Helper::write( $information );
	}


	public function uploader_action() {
		MainWP_Child_Misc::get_instance()->uploader_action();
	}

	public function wordpress_seo() {
		\MainWP_WordPress_SEO::instance()->action();
	}

	public function client_report() {
		MainWP_Client_Report::instance()->action();
	}

	public function page_speed() {
		\MainWP_Child_Pagespeed::instance()->action();
	}

	public function woo_com_status() {
		\MainWP_Child_WooCommerce_Status::instance()->action();
	}

	public function links_checker() {
		\MainWP_Child_Links_Checker::instance()->action();
	}

	public function wordfence() {
		\MainWP_Child_Wordfence::instance()->action();
	}

	public function ithemes() {
		\MainWP_Child_IThemes_Security::instance()->action();
	}


	public function updraftplus() {
		\MainWP_Child_Updraft_Plus_Backups::instance()->action();
	}

	public function wpvivid_backuprestore() {
		\MainWP_Child_WPvivid_BackupRestore::instance()->action();
	}

	public function backup_wp() {
		if ( ! version_compare( phpversion(), '5.3', '>=' ) ) {
			$error = sprintf( __( 'PHP Version %s is unsupported.', 'mainwp-child' ), phpversion() );
			MainWP_Helper::write( array( 'error' => $error ) );
		}
		\MainWP_Child_Back_Up_WordPress::instance()->action();
	}

	public function wp_rocket() {
		\MainWP_Child_WP_Rocket::instance()->action();
	}

	public function backwpup() {
		\MainWP_Child_Back_WP_Up::instance()->action();
	}


	public function delete_backup() {
		$dirs      = MainWP_Helper::get_mainwp_dir( 'backup' );
		$backupdir = $dirs[0];

		$file = $_REQUEST['del'];

		if ( file_exists( $backupdir . $file ) ) {
			unlink( $backupdir . $file );
		}

		MainWP_Helper::write( array( 'result' => 'ok' ) );
	}


	public function update_child_values() {
		$uniId = isset( $_POST['uniqueId'] ) ? $_POST['uniqueId'] : '';
		MainWP_Helper::update_option( 'mainwp_child_uniqueId', $uniId );
		MainWP_Helper::write( array( 'result' => 'ok' ) );
	}

	public function keyword_links_action() {
		MainWP_Keyword_Links::instance()->action();
	}

	public function branding_child_plugin() {
		MainWP_Child_Branding::instance()->action();
	}

	public function code_snippet() {
		MainWP_Child_Misc::get_instance()->code_snippet();
	}

	public function disconnect() {
		global $mainWPChild;
		$mainWPChild->deactivation( false );
		MainWP_Helper::write( array( 'result' => 'success' ) );
	}


	// Deactivating child plugin.
	public function deactivate() {
		global $mainWPChild;
		include_once ABSPATH . 'wp-admin/includes/plugin.php';
		deactivate_plugins( $mainWPChild->plugin_slug, true );
		$information = array();
		if ( is_plugin_active( $mainWPChild->plugin_slug ) ) {
			MainWP_Helper::error( 'Plugin still active' );
		}
		$information['deactivated'] = true;
		MainWP_Helper::write( $information );
	}

}
