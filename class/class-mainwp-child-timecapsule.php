<?php
/**
 * MainWP Time Capsule
 *
 * MainWP Time Capsule Extension handler.
 * Extension URL: https://mainwp.com/extension/time-capsule/
 *
 * @package MainWP\Child
 *
 * Credits
 *
 * Plugin-Name: WP Time Capsule
 * Plugin-URI: https://wptimecapsule.com
 * Author: Revmakx
 * Author URI: http://www.revmakx.com
 * Licence: GPLv2 or later
 */

namespace MainWP\Child;

// phpcs:disable PSR1.Classes.ClassDeclaration, WordPress.WP.AlternativeFunctions, Generic.Metrics.CyclomaticComplexity -- required to achieve desired results, pull request solutions appreciated.

/**
 * Class MainWP_Child_Timecapsule
 *
 * MainWP Time Capsule Extension handler.
 */
class MainWP_Child_Timecapsule {

	/**
	 * Public static variable to hold the single instance of the class.
	 *
	 * @var mixed Default null
	 */
	public static $instance = null;

	/**
	 * Public variable to hold the infomration if the WP Time Capsule plugin is installed on the child site.
	 *
	 * @var bool If WP Time Capsule intalled, return true, if not, return false.
	 */
	public $is_plugin_installed = false;

	/**
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
	 * MainWP_Child_Timecapsule constructor.
	 *
	 * Run any time the class is called.
	 *
	 * @uses is_plugin_active() Determines whether a plugin is active.
	 * @see https://developer.wordpress.org/reference/functions/is_plugin_active/
	 */
	public function __construct() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		if ( is_plugin_active( 'wp-time-capsule/wp-time-capsule.php' ) && defined( 'WPTC_CLASSES_DIR' ) ) {
			$this->is_plugin_installed = true;
		}

		if ( ! $this->is_plugin_installed ) {
			return;
		}

		add_filter( 'mainwp_site_sync_others_data', array( $this, 'sync_others_data' ), 10, 2 );
	}

	/**
	 * Initiate action hooks.
	 *
	 * @uses get_option() Retrieves an option value based on an option name.
	 * @see https://developer.wordpress.org/reference/functions/get_option/
	 *
	 * @return void
	 */
	public function init() {
		if ( ! $this->is_plugin_installed ) {
			return;
		}

		if ( get_option( 'mainwp_time_capsule_ext_enabled' ) !== 'Y' ) {
			return;
		}

		add_action( 'mainwp_child_site_stats', array( $this, 'do_site_stats' ) );

		if ( get_option( 'mainwp_time_capsule_hide_plugin' ) === 'hide' ) {
			add_filter( 'all_plugins', array( $this, 'all_plugins' ) );
			add_action( 'admin_menu', array( $this, 'remove_menu' ) );
			add_filter( 'site_transient_update_plugins', array( &$this, 'remove_update_nag' ) );
			add_filter( 'mainwp_child_hide_update_notice', array( &$this, 'hide_update_notice' ) );
		}
	}

	/**
	 * Fire off certain WP Time Capsule plugin actions.
	 *
	 * @return void
	 *
	 * @uses \MainWP\Child\MainWP_Child_Timecapsule::set_showhide()
	 * @uses \MainWP\Child\MainWP_Child_Timecapsule::get_root_files()
	 * @uses \MainWP\Child\MainWP_Child_Timecapsule::get_tables()
	 * @uses \MainWP\Child\MainWP_Child_Timecapsule::exclude_file_list()
	 * @uses \MainWP\Child\MainWP_Child_Timecapsule::exclude_table_list()
	 * @uses \MainWP\Child\MainWP_Child_Timecapsule::include_table_list()
	 * @uses \MainWP\Child\MainWP_Child_Timecapsule::include_table_structure_only()
	 * @uses \MainWP\Child\MainWP_Child_Timecapsule::include_file_list()
	 * @uses \MainWP\Child\MainWP_Child_Timecapsule::get_files_by_key()
	 * @uses \MainWP\Child\MainWP_Child_Timecapsule::process_wptc_login()
	 * @uses \MainWP\Child\MainWP_Child_Timecapsule::get_installed_plugins()
	 * @uses \MainWP\Child\MainWP_Child_Timecapsule::get_installed_themes()
	 * @uses \MainWP\Child\MainWP_Child_Timecapsule::is_staging_need_request()
	 * @uses \MainWP\Child\MainWP_Child_Timecapsule::get_staging_details_wptc()
	 * @uses \MainWP\Child\MainWP_Child_Timecapsule::start_fresh_staging_wptc()
	 * @uses \MainWP\Child\MainWP_Child_Timecapsule::get_staging_url_wptc()
	 * @uses \MainWP\Child\MainWP_Child_Timecapsule::stop_staging_wptc()
	 * @uses \MainWP\Child\MainWP_Child_Timecapsule::continue_staging_wptc()
	 * @uses \MainWP\Child\MainWP_Child_Timecapsule::delete_staging_wptc()
	 * @uses \MainWP\Child\MainWP_Child_Timecapsule::copy_staging_wptc()
	 * @uses \MainWP\Child\MainWP_Child_Timecapsule::get_staging_current_status_key()
	 * @uses \MainWP\Child\MainWP_Child_Timecapsule::wptc_sync_purchase()
	 * @uses \MainWP\Child\MainWP_Child_Timecapsule::init_restore()
	 * @uses \MainWP\Child\MainWP_Child_Timecapsule::save_settings_wptc()
	 * @uses \MainWP\Child\MainWP_Child_Timecapsule::analyze_inc_exc()
	 * @uses \MainWP\Child\MainWP_Child_Timecapsule::get_enabled_plugins()
	 * @uses \MainWP\Child\MainWP_Child_Timecapsule::get_enabled_themes()
	 * @uses \MainWP\Child\MainWP_Child_Timecapsule::get_system_info()
	 * @uses \MainWP\Child\MainWP_Child_Timecapsule::update_vulns_settings()
	 * @uses \MainWP\Child\MainWP_Child_Timecapsule::start_fresh_backup_tc_callback_wptc()
	 * @uses \MainWP\Child\MainWP_Child_Timecapsule::save_manual_backup_name_wptc()
	 * @uses \MainWP\Child\MainWP_Child_Timecapsule::progress_wptc()
	 * @uses \MainWP\Child\MainWP_Child_Timecapsule::stop_fresh_backup_tc_callback_wptc()
	 * @uses \MainWP\Child\MainWP_Child_Timecapsule::wptc_cron_status()
	 * @uses \MainWP\Child\MainWP_Child_Timecapsule::get_this_backups_html()
	 * @uses \MainWP\Child\MainWP_Child_Timecapsule::start_restore_tc_callback_wptc()
	 * @uses \MainWP\Child\MainWP_Child_Timecapsule::get_sibling_files_callback_wptc()
	 * @uses \MainWP\Child\MainWP_Child_Timecapsule::get_logs_rows()
	 * @uses \MainWP\Child\MainWP_Child_Timecapsule::clear_wptc_logs()
	 * @uses \MainWP\Child\MainWP_Child_Timecapsule::send_issue_report()
	 * @uses \MainWP\Child\MainWP_Child_Timecapsule::lazy_load_activity_log_wptc()
	 * @uses \MainWP\Child\MainWP_Helper::write() Write response data to be sent to the MainWP Dashboard.
	 */
	public function action() { // phpcs:ignore -- Current complexity is the only way to achieve desired results, pull request solutions appreciated.
		if ( ! $this->is_plugin_installed ) {
			MainWP_Helper::write( array( 'error' => 'Please install WP Time Capsule plugin on child website' ) );
		}

		try {
			$this->require_files();
		} catch ( \Exception $e ) {
			$error = $e->getMessage();
			MainWP_Helper::write( array( 'error' => $error ) );
		}

		// to fix.
		if ( isset( $_POST['mwp_action'] ) && ! defined( 'WP_ADMIN' ) ) {
			define( 'WP_ADMIN', true );
		}

		if ( function_exists( '\wptc_load_files' ) ) {
			\wptc_load_files();
		}
			$information = array();

			$options_helper    = new \Wptc_Options_Helper();
			$options           = \WPTC_Factory::get( 'config' );
			$is_user_logged_in = $options->get_option( 'is_user_logged_in' );
			$privileges_wptc   = $options_helper->get_unserialized_privileges();

		if ( isset( $_POST['mwp_action'] ) ) {

			if ( ( 'save_settings' == $_POST['mwp_action'] || 'get_staging_details_wptc' == $_POST['mwp_action'] || 'progress_wptc' == $_POST['mwp_action'] ) && ( ! $is_user_logged_in || ! $privileges_wptc ) ) {
				MainWP_Helper::write( array( 'error' => 'You are not login to your WP Time Capsule account.' ) );
			}
			$mwp_action = ! empty( $_POST['mwp_action'] ) ? sanitize_text_field( wp_unslash( $_POST['mwp_action'] ) ) : '';
			switch ( $mwp_action ) {
				case 'set_showhide':
						$information = $this->set_showhide();
					break;
				case 'get_root_files':
						$information = $this->get_root_files();
					break;
				case 'get_tables':
						$information = $this->get_tables();
					break;
				case 'exclude_file_list':
						$information = $this->exclude_file_list();
					break;
				case 'exclude_table_list':
						$information = $this->exclude_table_list();
					break;
				case 'include_table_list':
						$information = $this->include_table_list();
					break;
				case 'include_table_structure_only':
						$information = $this->include_table_structure_only();
					break;
				case 'include_file_list':
						$information = $this->include_file_list();
					break;
				case 'get_files_by_key':
						$information = $this->get_files_by_key();
					break;
				case 'wptc_login':
						$information = $this->process_wptc_login();
					break;
				case 'get_installed_plugins':
						$information = $this->get_installed_plugins();
					break;
				case 'get_installed_themes':
						$information = $this->get_installed_themes();
					break;
				case 'is_staging_need_request':
						$information = $this->is_staging_need_request();
					break;
				case 'get_staging_details_wptc':
						$information = $this->get_staging_details_wptc();
					break;
				case 'start_fresh_staging_wptc':
						$information = $this->start_fresh_staging_wptc();
					break;
				case 'get_staging_url_wptc':
						$information = $this->get_staging_url_wptc();
					break;
				case 'stop_staging_wptc':
						$information = $this->stop_staging_wptc();
					break;
				case 'continue_staging_wptc':
						$information = $this->continue_staging_wptc();
					break;
				case 'delete_staging_wptc':
						$information = $this->delete_staging_wptc();
					break;
				case 'copy_staging_wptc':
						$information = $this->copy_staging_wptc();
					break;
				case 'get_staging_current_status_key':
						$information = $this->get_staging_current_status_key();
					break;
				case 'wptc_sync_purchase':
						$information = $this->wptc_sync_purchase();
					break;
				case 'init_restore':
						$information = $this->init_restore();
					break;
				case 'save_settings':
						$information = $this->save_settings_wptc();
					break;
				case 'analyze_inc_exc':
						$information = $this->analyze_inc_exc();
					break;
				case 'get_enabled_plugins':
						$information = $this->get_enabled_plugins();
					break;
				case 'get_enabled_themes':
						$information = $this->get_enabled_themes();
					break;
				case 'get_system_info':
						$information = $this->get_system_info();
					break;
				case 'update_vulns_settings':
						$information = $this->update_vulns_settings();
					break;
				case 'start_fresh_backup':
						$information = $this->start_fresh_backup_tc_callback_wptc();
					break;
				case 'save_manual_backup_name':
						$information = $this->save_manual_backup_name_wptc();
					break;
				case 'progress_wptc':
						$information = $this->progress_wptc();
					break;
				case 'stop_fresh_backup':
						$information = $this->stop_fresh_backup_tc_callback_wptc();
					break;
				case 'wptc_cron_status':
						$information = $this->wptc_cron_status();
					break;
				case 'get_this_backups_html':
						$information = $this->get_this_backups_html();
					break;
				case 'start_restore_tc_wptc':
						$information = $this->start_restore_tc_callback_wptc();
					break;
				case 'get_sibling_files':
						$information = $this->get_sibling_files_callback_wptc();
					break;
				case 'get_logs_rows':
						$information = $this->get_logs_rows();
					break;
				case 'clear_logs':
						$information = $this->clear_wptc_logs();
					break;
				case 'send_issue_report':
						$information = $this->send_issue_report();
					break;
				case 'lazy_load_activity_log':
						$information = $this->lazy_load_activity_log_wptc();
					break;
			}
		}
		MainWP_Helper::write( $information );
	}

	/**
	 * Check if required files exist.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::check_files_exists() Check if requested files exist.
	 */
	public function require_files() {
		if ( ! class_exists( '\WPTC_Base_Factory' ) && defined( 'WPTC_PLUGIN_DIR' ) ) {
			if ( MainWP_Helper::check_files_exists( WPTC_PLUGIN_DIR . 'Base/Factory.php' ) ) {
				include_once WPTC_PLUGIN_DIR . 'Base/Factory.php';
			}
		}
		if ( ! class_exists( '\Wptc_Options_Helper' ) && defined( 'WPTC_PLUGIN_DIR' ) ) {
			if ( MainWP_Helper::check_files_exists( WPTC_PLUGIN_DIR . 'Views/wptc-options-helper.php' ) ) {
				include_once WPTC_PLUGIN_DIR . 'Views/wptc-options-helper.php';
			}
		}
	}

	/**
	 * Hide or unhide the WP Time Capsule plugin.
	 *
	 * @return array Action result.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::update_option() Update database option by option name.
	 *
	 * @used-by \MainWP\Child\MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
	 */
	public function set_showhide() {
		$hide = isset( $_POST['showhide'] ) && ( 'hide' === $_POST['showhide'] ) ? 'hide' : '';
		MainWP_Helper::update_option( 'mainwp_time_capsule_hide_plugin', $hide, 'yes' );
		$information['result'] = 'SUCCESS';
		return $information;
	}

	/**
	 * Sync the WP Time Capsule plugin settings.
	 *
	 * @param array $information Array containing the sync information.
	 * @param array $data        Array containing the WP Time Capsule plugin data to be synced.
	 *
	 * @return array $information Array containing the sync information.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Timecapsule::get_sync_data() Get synced WP Time Capsule data.
	 * @uses \MainWP\Child\MainWP_Helper::update_option() Update database option by option name.
	 * @uses get_option() Retrieves an option value based on an option name.
	 * @see https://developer.wordpress.org/reference/functions/get_option/
	 */
	public function sync_others_data( $information, $data = array() ) {
		if ( isset( $data['syncWPTimeCapsule'] ) && $data['syncWPTimeCapsule'] ) {
			$information['syncWPTimeCapsule'] = $this->get_sync_data();
			if ( get_option( 'mainwp_time_capsule_ext_enabled' ) !== 'Y' ) {
				MainWP_Helper::update_option( 'mainwp_time_capsule_ext_enabled', 'Y', 'yes' );
			}
		}
		return $information;
	}

	/**
	 * Get synced WP Time Capsule data.
	 *
	 * @return array|bool Return an array containing the synced data, or false on failure.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::instance()->check_classes_exists() Check if requested class exists.
	 * @uses \MainWP\Child\MainWP_Helper::instance()->check_methods() Check if requested method exists.
	 *
	 * @used-by MainWP_Child_Timecapsule::sync_others_data() Sync the WP Time Capsule plugin settings.
	 */
	public function get_sync_data() {
		try {
			$this->require_files();
			MainWP_Helper::instance()->check_classes_exists( array( '\Wptc_Options_Helper', '\WPTC_Base_Factory', '\WPTC_Factory' ) );

			$config = \WPTC_Factory::get( 'config' );
			MainWP_Helper::instance()->check_methods( $config, 'get_option' );

			$main_account_email_var = $config->get_option( 'main_account_email' );
			$last_backup_time       = $config->get_option( 'last_backup_time' );
			$wptc_settings          = \WPTC_Base_Factory::get( 'Wptc_Settings' );

			$options_helper = new \Wptc_Options_Helper();

			MainWP_Helper::instance()->check_methods( $options_helper, array( 'get_plan_interval_from_subs_info', 'get_is_user_logged_in' ) );
			MainWP_Helper::instance()->check_methods( $wptc_settings, array( 'get_connected_cloud_info' ) );

			$all_backups   = $this->get_backups();
			$backups_count = 0;
			if ( is_array( $all_backups ) ) {
				$formatted_backups = array();
				foreach ( $all_backups as $key => $value ) {
					$value_array                                     = (array) $value;
					$formatted_backups[ $value_array['backupID'] ][] = $value_array;
				}
				$backups_count = count( $formatted_backups );
			}

			$return = array(
				'main_account_email' => $main_account_email_var,
				'signed_in_repos'    => $wptc_settings->get_connected_cloud_info(),
				'plan_name'          => $options_helper->get_plan_interval_from_subs_info(),
				'plan_interval'      => $options_helper->get_plan_interval_from_subs_info(),
				'lastbackup_time'    => ! empty( $last_backup_time ) ? $last_backup_time : 0,
				'is_user_logged_in'  => $options_helper->get_is_user_logged_in(),
				'backups_count'      => $backups_count,
			);
			return $return;
		} catch ( \Exception $e ) {
			// do not exit here!
		}
		return false;
	}

	/**
	 * Get WP Time Capsule backups.
	 *
	 * @param string $last_time Last completed backup timestamp.
	 *
	 * @uses wpdb::get_results() Retrieve an entire SQL result set from the database (i.e., many rows).
	 * @see https://developer.wordpress.org/reference/classes/wpdb/get_results/
	 *
	 * @uses wpdb::prepare() Prepares a SQL query for safe execution. Uses sprintf()-like syntax.
	 * @see https://developer.wordpress.org/reference/classes/wpdb/prepare/
	 *
	 * @return array Returns array of all completed backups.
	 */
	protected function get_backups( $last_time = false ) {
		if ( empty( $last_time ) ) {
			$last_time = strtotime( date( 'Y-m-d', strtotime( date( 'Y-m-01' ) ) ) ); // phpcs:ignore --  required to achieve desired results, pull request solutions appreciated.
		}

		/**
		 * Object, providing access to the WordPress database.
		 *
		 * @global $wpdb WordPress Database instance.
		 */
		global $wpdb;

		$all_backups = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT backupID
				FROM {$wpdb->base_prefix}wptc_processed_files
				WHERE backupID > %s ",
				$last_time
			)
		);

		return $all_backups;
	}

	/**
	 * Get the WP Time Capsule tables.
	 *
	 * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
	 */
	public function get_tables() {
		$category          = isset( $_POST['category'] ) ? wp_unslash( $_POST['category'] ) : '';
		$exclude_class_obj = new \Wptc_ExcludeOption( $category );
		$exclude_class_obj->get_tables();
		die();
	}

	/**
	 * Exlude files from the backup process.
	 *
	 * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
	 */
	public function exclude_file_list() {
		if ( ! isset( $_POST['data'] ) ) {
			wptc_die_with_json_encode( array( 'status' => 'no data found' ) );
		}
		$category          = isset( $_POST['category'] ) ? wp_unslash( $_POST['category'] ) : '';
		$exclude_class_obj = new \Wptc_ExcludeOption( $category );
		$data              = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : '';
		$exclude_class_obj->exclude_file_list( $data );
		die();
	}

	/**
	 * Get backup process progress.
	 *
	 * @uses spawn_cron() Sends a request to run cron through HTTP request that doesn’t halt page loading.
	 * @see https://developer.wordpress.org/reference/functions/spawn_cron/
	 *
	 * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
	 *
	 * @return array Action result.
	 */
	public function progress_wptc() {

		$config = \WPTC_Factory::get( 'config' );

		/**
		 * Object, providing access to the WordPress database.
		 *
		 * @global $wpdb WordPress Database instance.
		 */
		global $wpdb;

		if ( ! $config->get_option( 'in_progress' ) ) {
			spawn_cron();
		}

		$processed_files = \WPTC_Factory::get( 'processed-files' );

		$return_array                                  = array();
		$return_array['stored_backups']                = $processed_files->get_stored_backups();
		$return_array['backup_progress']               = array();
		$return_array['starting_first_backup']         = $config->get_option( 'starting_first_backup' );
		$return_array['meta_data_backup_process']      = $config->get_option( 'meta_data_backup_process' );
		$return_array['backup_before_update_progress'] = $config->get_option( 'backup_before_update_progress' );
		$return_array['is_staging_running']            = apply_filters( 'is_any_staging_process_going_on', '' );
		$cron_status                                   = $config->get_option( 'wptc_own_cron_status' );

		if ( ! empty( $cron_status ) ) {
			$return_array['wptc_own_cron_status']          = unserialize( $cron_status ); // phpcs:ignore -- safe internal value, third party.
			$return_array['wptc_own_cron_status_notified'] = (int) $config->get_option( 'wptc_own_cron_status_notified' );
		}

		$start_backups_failed_server = $config->get_option( 'start_backups_failed_server' );
		if ( ! empty( $start_backups_failed_server ) ) {
			$return_array['start_backups_failed_server'] = unserialize( $start_backups_failed_server ); // phpcs:ignore -- safe internal value, third party.
			$config->set_option( 'start_backups_failed_server', false );
		}

		$processed_files->get_current_backup_progress( $return_array );

		$return_array['user_came_from_existing_ver'] = (int) $config->get_option( 'user_came_from_existing_ver' );
		$return_array['show_user_php_error']         = $config->get_option( 'show_user_php_error' );
		$return_array['bbu_setting_status']          = apply_filters( 'get_backup_before_update_setting_wptc', '' );
		$return_array['bbu_note_view']               = apply_filters( 'get_bbu_note_view', '' );
		$return_array['staging_status']              = apply_filters( 'staging_status_wptc', '' );

		$processed_files  = \WPTC_Factory::get( 'processed-files' );
		$last_backup_time = $config->get_option( 'last_backup_time' );

		if ( ! empty( $last_backup_time ) ) {
			$user_time = $config->cnvt_UTC_to_usrTime( $last_backup_time );
			$processed_files->modify_schedule_backup_time( $user_time );
			$formatted_date                   = date( 'M d @ g:i a', $user_time ); // phpcs:ignore -- required to achieve desired results, pull request solutions appreciated.
			$return_array['last_backup_time'] = $formatted_date;
		} else {
			$return_array['last_backup_time'] = 'No Backup Taken';
		}

		return array( 'result' => $return_array );
	}

	/**
	 * Get the WP Cron status.
	 *
	 * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
	 *
	 * @return array Action result.
	 */
	public function wptc_cron_status() {
		$config = \WPTC_Factory::get( 'config' );
		wptc_own_cron_status();
		$status      = array();
		$cron_status = $config->get_option( 'wptc_own_cron_status' );
		if ( ! empty( $cron_status ) ) {
			$cron_status = unserialize( $cron_status ); // phpcs:ignore -- safe internal value, third party.

			if ( 'success' == $cron_status['status'] ) {
				$status['status'] = 'success';
			} else {
				$status['status']      = 'failed';
				$status['status_code'] = $cron_status['statusCode'];
				$status['err_msg']     = $cron_status['body'];
				$status['cron_url']    = $cron_status['cron_url'];
				$status['ips']         = $cron_status['ips'];
			}
			return array( 'result' => $status );
		}
		return false;
	}

	/**
	 * Get the backups HTML markup.
	 *
	 * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
	 *
	 * @return array Action result.
	 */
	public function get_this_backups_html() {
		$this_backup_ids    = isset( $_POST['this_backup_ids'] ) ? wp_unslash( $_POST['this_backup_ids'] ) : '';
		$specific_dir       = isset( $_POST['specific_dir'] ) ? wp_unslash( $_POST['specific_dir'] ) : '';
		$type               = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
		$treeRecursiveCount = isset( $_POST['treeRecursiveCount'] ) ? sanitize_text_field( wp_unslash( $_POST['treeRecursiveCount'] ) ) : '';
		$processed_files    = \WPTC_Factory::get( 'processed-files' );

		$result = $processed_files->get_this_backups_html( $this_backup_ids, $specific_dir, $type, $treeRecursiveCount );
		return array( 'result' => $result );
	}

	/**
	 * Start the restore process.
	 *
	 * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
	 */
	public function start_restore_tc_callback_wptc() {

		if ( apply_filters( 'is_restore_to_staging_wptc', '' ) ) {
			$request = apply_filters( 'get_restore_to_staging_request_wptc', '' );
		} else {
			$request = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : '';
		}

		include_once WPTC_CLASSES_DIR . 'class-prepare-restore-bridge.php';

		new \WPTC_Prepare_Restore_Bridge( $request );
	}

	/**
	 * Get sibling files.
	 *
	 * @uses wp_normalize_path() Normalize a filesystem path.
	 * @see https://developer.wordpress.org/reference/functions/wp_normalize_path/
	 *
	 * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
	 */
	public function get_sibling_files_callback_wptc() {
		// note that we are getting the ajax function data via $_POST.
		$file_name       = isset( $_POST['data']['file_name'] ) ? wp_unslash( $_POST['data']['file_name'] ) : '';
		$file_name       = wp_normalize_path( $file_name );
		$backup_id       = isset( $_POST['data']['backup_id'] ) ? sanitize_text_field( wp_unslash( $_POST['data']['backup_id'] ) ) : '';
		$recursive_count = isset( $_POST['data']['recursive_count'] ) ? sanitize_text_field( wp_unslash( $_POST['data']['recursive_count'] ) ) : '';

		$processed_files = \WPTC_Factory::get( 'processed-files' );
		echo $processed_files->get_this_backups_html( $backup_id, $file_name, $type = 'sibling', (int) $recursive_count );
		die();
	}

	/**
	 * Send issue report.
	 *
	 * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
	 */
	public function send_issue_report() {
		\WPTC_Base_Factory::get( 'Wptc_App_Functions' )->send_report();
		die();
	}

	/**
	 * Get logs rows.
	 *
	 * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
	 *
	 * @return array Action result.
	 */
	public function get_logs_rows() {
		$result                 = $this->prepare_items();
		$result['display_rows'] = base64_encode( wp_json_encode( $this->get_display_rows( $result['items'] ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode required for the backwards compatibility.
		return $result;
	}

	/**
	 * Prepare items for logs.
	 *
	 * @used-by MainWP_Child_Timecapsule::get_logs_rows() Get logs rows.
	 *
	 * @return array Action result.
	 *
	 * @uses \MainWP\Child\MainWP_Child_DB::real_escape_string()
	 */
	public function prepare_items() {

		/**
		 * Object, providing access to the WordPress database.
		 *
		 * @global $wpdb WordPress Database instance.
		 */
		global $wpdb;

		if ( isset( $_POST['type'] ) ) {
			$type = ! empty( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
			switch ( $type ) {
				case 'backups':
					$query = 'SELECT * FROM ' . $wpdb->base_prefix . "wptc_activity_log WHERE type LIKE '%backup%' AND show_user = 1 GROUP BY action_id";
					break;
				case 'restores':
					$query = 'SELECT * FROM ' . $wpdb->base_prefix . "wptc_activity_log WHERE type LIKE 'restore%' GROUP BY action_id";
					break;
				case 'staging':
					$query = 'SELECT * FROM ' . $wpdb->base_prefix . "wptc_activity_log WHERE type LIKE 'staging%' GROUP BY action_id";
					break;
				case 'backup_and_update':
					$query = 'SELECT * FROM ' . $wpdb->base_prefix . "wptc_activity_log WHERE type LIKE 'backup_and_update%' GROUP BY action_id";
					break;
				case 'auto_update':
					$query = 'SELECT * FROM ' . $wpdb->base_prefix . "wptc_activity_log WHERE type LIKE 'auto_update%' GROUP BY action_id";
					break;
				case 'others':
					$query = 'SELECT * FROM ' . $wpdb->base_prefix . "wptc_activity_log WHERE type NOT LIKE 'restore%' AND type NOT LIKE 'backup%' AND show_user = 1";
					break;
				default:
					$query = 'SELECT * FROM ' . $wpdb->base_prefix . 'wptc_activity_log GROUP BY action_id UNION SELECT * FROM ' . $wpdb->base_prefix . "wptc_activity_log WHERE action_id='' AND show_user = 1";
					break;
			}
		} else {
			$query = 'SELECT * FROM ' . $wpdb->base_prefix . 'wptc_activity_log WHERE show_user = 1   GROUP BY action_id ';
		}

		$orderby = ! empty( $_POST['orderby'] ) ? sanitize_sql_orderby( wp_unslash( $_POST['orderby'] ) ) : 'id';
		$order   = ! empty( $_POST['order'] ) ? sanitize_sql_orderby( wp_unslash( $_POST['order'] ) ) : 'DESC';
		if ( ! empty( $orderby ) & ! empty( $order ) ) {
			$query .= ' ORDER BY ' . $orderby . ' ' . $order;
		}

		$totalitems = $wpdb->query( $query ); // phpcs:ignore -- safe query.
		$perpage    = 20;
		$paged      = ! empty( $_POST['paged'] ) ? intval( $_POST['paged'] ) : '';
		if ( empty( $paged ) || ! is_numeric( $paged ) || $paged <= 0 ) {
			$paged = 1;
		}
		$totalpages = ceil( $totalitems / $perpage );
		if ( ! empty( $paged ) && ! empty( $perpage ) ) {
			$offset = ( $paged - 1 ) * $perpage;
			$query .= ' LIMIT ' . (int) $offset . ',' . (int) $perpage;
		}

		return array(
			'items'      => $wpdb->get_results( $query ), // phpcs:ignore -- safe query required to achieve desired results, pull request solutions appreciated.
			'totalitems' => $totalitems,
			'perpage'    => $perpage,
		);
	}

	/**
	 * Lazy load activity log.
	 *
	 * @uses MainWP_Child_Timecapsule::get_activity_log() Get the WP Time Capsule activity log.
	 *
	 * @uses wpdb::get_results() Retrieve an entire SQL result set from the database (i.e., many rows).
	 * @see https://developer.wordpress.org/reference/classes/wpdb/get_results/
	 *
	 * @uses wpdb::prepare() Prepares a SQL query for safe execution. Uses sprintf()-like syntax.
	 * @see https://developer.wordpress.org/reference/classes/wpdb/prepare/
	 *
	 * @return array Action result.
	 */
	public function lazy_load_activity_log_wptc() {

		if ( ! isset( $_POST['data'] ) ) {
			return false;
		}

		$data = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : array();

		if ( ! isset( $data['action_id'] ) || ! isset( $data['limit'] ) ) {
			return false;
		}

		/**
		 * Object, providing access to the WordPress database.
		 *
		 * @global $wpdb WordPress Database instance.
		 */
		global $wpdb;

		$action_id     = $data['action_id'];
		$from_limit    = $data['limit'];
		$detailed      = '';
		$load_more     = false;
		$current_limit = \WPTC_Factory::get( 'config' )->get_option( 'activity_log_lazy_load_limit' );
		$to_limit      = $from_limit + $current_limit;

		$sub_records = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->base_prefix . 'wptc_activity_log WHERE action_id = %s AND show_user = 1 ORDER BY id DESC LIMIT %d, %d', $action_id, $from_limit, $current_limit ) );

		$row_count = count( $sub_records );

		if ( $row_count == $current_limit ) {
			$load_more = true;
		}

		$detailed = $this->get_activity_log( $sub_records );

		if ( isset( $load_more ) && $load_more ) {
			$detailed .= '<tr><td></td><td><a style="cursor:pointer; position:relative" class="wptc_activity_log_load_more" action_id="' . esc_attr( $action_id ) . '" limit="' . esc_attr( $to_limit ) . '">Load more</a></td><td></td></tr>';
		}

		return array( 'result' => $detailed );
	}

	/**
	 * Display the log rows.
	 *
	 * @param array $records An array of log records.
	 *
	 * @used-by MainWP_Child_Timecapsule::get_logs_rows() Get logs rows.
	 *
	 * @return string Log rows.
	 */
	public function get_display_rows( $records ) {

		/**
		 * Object, providing access to the WordPress database.
		 *
		 * @global $wpdb WordPress Database instance.
		 */
		global $wpdb;

		// Get the records registered in the prepare_items method.
		if ( ! is_array( $records ) ) {
			return '';
		}

		$i     = 0;
		$limit = \WPTC_Factory::get( 'config' )->get_option( 'activity_log_lazy_load_limit' );
		// Get the columns registered in the get_columns and get_sortable_columns methods.
		$timezone = \WPTC_Factory::get( 'config' )->get_option( 'wptc_timezone' );
		if ( count( $records ) > 0 ) {

			foreach ( $records as $key => $rec ) {
				$html = '';

				$more_logs = false;
				$load_more = false;
				if ( '' != $rec->action_id ) {
					$sub_records = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->base_prefix . 'wptc_activity_log WHERE action_id= %s AND show_user = 1 ORDER BY id DESC LIMIT 0, %d', $rec->action_id, $limit ) );
					$row_count   = count( $sub_records );
					if ( $row_count == $limit ) {
						$load_more = true;
					}

					if ( $row_count > 0 ) {
						$more_logs = true;
						$detailed  = '<table>';
						$detailed .= $this->get_activity_log( $sub_records );
						if ( isset( $load_more ) && $load_more ) {
							$detailed .= '<tr><td></td><td><a style="cursor:pointer; position:relative" class="mainwp_wptc_activity_log_load_more" action_id="' . $rec->action_id . '" limit="' . $limit . '">Load more</a></td><td></td></tr>';
						}
						$detailed .= '</table>';

					}
				}
				$html     .= '<tr class="act-tr">';
				$Ldata     = unserialize( $rec->log_data ); // phpcs:ignore -- safe internal value, third party.
				$user_time = \WPTC_Factory::get( 'config' )->cnvt_UTC_to_usrTime( $Ldata['log_time'] );
				\WPTC_Factory::get( 'processed-files' )->modify_schedule_backup_time( $user_time );
				$user_tz_now = date( 'M d, Y @ g:i:s a', $user_time ); // phpcs:ignore -- required to achieve desired results, pull request solutions appreciated.
				$msg         = '';
				if ( ! ( strpos( $rec->type, 'backup' ) === false ) ) {
					// Backup process.
					$msg = 'Backup Process';
				} elseif ( ! ( strpos( $rec->type, 'restore' ) === false ) ) {
					// Restore Process.
					$msg = 'Restore Process';
				} elseif ( ! ( strpos( $rec->type, 'staging' ) === false ) ) {
					// Restore Process.
					$msg = 'Staging Process';
				} else {
					if ( $row_count < 2 ) {
						$more_logs = false;
					}
					$msg = $Ldata['msg'];
				}
				$html .= '<td class="wptc-act-td">' . $user_tz_now . '</td><td class="wptc-act-td">' . $msg;
				if ( $more_logs ) {
					$html .= "&nbsp&nbsp&nbsp&nbsp<a class='wptc-show-more' action_id='" . round( $rec->action_id ) . "'>View details</a></td>";
				} else {
					$html .= '</td>';
				}
				$html .= '<td class="wptc-act-td"><a class="report_issue_wptc" id="' . $rec->id . '" href="#">Send report to plugin developer</a></td>';
				if ( $more_logs ) {

					$html .= "</tr><tr id='" . round( $rec->action_id ) . "' class='wptc-more-logs'><td colspan=3>" . $detailed . '</td>';
				} else {
					$html .= '</td>';
				}

				$html .= '</tr>';

				$display_rows[ $key ] = $html;
			}
		}
		return $display_rows;
	}

	/**
	 * Get the WP Time Capsule activity log.
	 *
	 * @param array $sub_records Activity log sub-records.
	 *
	 * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
	 *
	 * @return string Activity log HTML.
	 */
	public function get_activity_log( $sub_records ) {
		if ( count( $sub_records ) < 1 ) {
			return false;
		}
		$detailed = '';
		$timezone = \WPTC_Factory::get( 'config' )->get_option( 'wptc_timezone' );
		foreach ( $sub_records as $srec ) {
			$Moredata = unserialize( $srec->log_data ); // phpcs:ignore -- safe internal value, third party.
			$user_tmz = new \DateTime( '@' . $Moredata['log_time'], new \DateTimeZone( date_default_timezone_get() ) );
			$user_tmz->setTimeZone( new \DateTimeZone( $timezone ) );
			$user_tmz_now = $user_tmz->format( 'M d @ g:i:s a' );
			$detailed    .= '<tr><td>' . $user_tmz_now . '</td><td>' . $Moredata['msg'] . '</td><td></td></tr>';
		}
		return $detailed;
	}

	/**
	 * Clear the WP Time Capsule logs.
	 *
	 * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
	 *
	 * @return array Action result.
	 */
	public function clear_wptc_logs() {

		/**
		 * Object, providing access to the WordPress database.
		 *
		 * @global $wpdb WordPress Database instance.
		 */
		global $wpdb;

		if ( $wpdb->query( 'TRUNCATE TABLE `' . $wpdb->base_prefix . 'wptc_activity_log`' ) ) {
			$result = 'yes';
		} else {
			$result = 'no';
		}
		return array( 'result' => $result );
	}

	/**
	 * Stop the WP Time Capsule backup process.
	 *
	 * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
	 *
	 * @return array Action result.
	 */
	public function stop_fresh_backup_tc_callback_wptc() {
		$deactivated_plugin = null;
		$backup             = new \WPTC_BackupController();
		$backup->stop( $deactivated_plugin );
		return array( 'result' => 'ok' );
	}

	/**
	 * Get the site root files.
	 *
	 * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
	 */
	public function get_root_files() {
		$category          = isset( $_POST['category'] ) ? wp_unslash( $_POST['category'] ) : '';
		$exclude_class_obj = new \Wptc_ExcludeOption( $category );
		$exclude_class_obj->get_root_files();
		die();
	}

	/**
	 * Exclude database tables.
	 *
	 * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
	 */
	public function exclude_table_list() {
		if ( ! isset( $_POST['data'] ) ) {
			wptc_die_with_json_encode( array( 'status' => 'no data found' ) );
		}
		$category          = isset( $_POST['data']['category'] ) ? wp_unslash( $_POST['data']['category'] ) : '';
		$exclude_class_obj = new \Wptc_ExcludeOption( $category );
		$data              = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : '';
		$exclude_class_obj->exclude_table_list( $data );
		die();
	}

	/**
	 * Add support for the reporting system.
	 *
	 * @uses has_action() Check if any action has been registered for a hook.
	 * @see https://developer.wordpress.org/reference/functions/has_action/
	 *
	 * @uses MainWP_Child_Timecapsule::do_reports_log() Add WP Time Capsule data to the reports database table.
	 */
	public function do_site_stats() {
		if ( has_action( 'mainwp_child_reports_log' ) ) {
			do_action( 'mainwp_child_reports_log', 'wptimecapsule' );
		} else {
			$this->do_reports_log( 'wptimecapsule' );
		}
	}

	/**
	 * Add WP Time Capsule data to the reports database table.
	 *
	 * @param string $ext Current extension.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::instance()->check_classes_exists() Check if the requested class exists.
	 * @uses \MainWP\Child\MainWP_Helper::instance()->check_methods() Check if the requested method exists.
	 * @uses \MainWP\Child\MainWP_Utility::update_lasttime_backup() Get the last backup timestamp.
	 * @uses \MainWP\Child\MainWP_Utility::get_lasttime_backup()
	 *
	 * @used-by \MainWP\Child\MainWP_Child_Timecapsule::do_site_stats() Add support for the reporting system.
	 */
	public function do_reports_log( $ext = '' ) {

		if ( 'wptimecapsule' !== $ext ) {
			return;
		}

		if ( ! $this->is_plugin_installed ) {
			return;
		}

		try {
			MainWP_Helper::instance()->check_classes_exists( array( '\WPTC_Factory' ) );

			$config = \WPTC_Factory::get( 'config' );

			MainWP_Helper::instance()->check_methods( $config, 'get_option' );

			$backup_time = $config->get_option( 'last_backup_time' );

			if ( ! empty( $backup_time ) ) {
				MainWP_Utility::update_lasttime_backup( 'wptimecapsule', $backup_time );
			}

			$last_time       = time() - 24 * 7 * 2 * 60 * 60;
			$lasttime_logged = MainWP_Utility::get_lasttime_backup( 'wptimecapsule' );
			if ( empty( $lasttime_logged ) ) {
				$last_time = time() - 24 * 7 * 8 * 60 * 60;
			}

			$all_last_backups = $this->get_backups( $last_time );

			if ( is_array( $all_last_backups ) ) {
				$formatted_backups = array();
				foreach ( $all_last_backups as $key => $value ) {
					$value_array                                     = (array) $value;
					$formatted_backups[ $value_array['backupID'] ][] = $value_array;
				}
				$message     = 'WP Time Capsule backup finished';
				$backup_type = 'WP Time Capsule backup';
				if ( count( $formatted_backups ) > 0 ) {
					foreach ( $formatted_backups as $key => $value ) {
						$backup_time = $key;
						do_action( 'mainwp_reports_wptimecapsule_backup', $message, $backup_type, $backup_time );
					}
				}
			}
		} catch ( \Exception $e ) {
			// ok.
		}
	}

	/**
	 * Include database tables.
	 *
	 * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
	 */
	public function include_table_list() {
		if ( ! isset( $_POST['data'] ) ) {
			wptc_die_with_json_encode( array( 'status' => 'no data found' ) );
		}
		$category          = isset( $_POST['data']['category'] ) ? wp_unslash( $_POST['data']['category'] ) : array();
		$exclude_class_obj = new \Wptc_ExcludeOption( $category );
		$data              = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : array();
		$exclude_class_obj->include_table_list( $data );
		die();
	}

	/**
	 * Include database table structure only.
	 *
	 * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
	 */
	public function include_table_structure_only() {

		if ( ! isset( $_POST['data'] ) ) {
			wptc_die_with_json_encode( array( 'status' => 'no data found' ) );
		}

		$category          = isset( $_POST['data']['category'] ) ? wp_unslash( $_POST['data']['category'] ) : array();
		$exclude_class_obj = new \Wptc_ExcludeOption( $category );
		$data              = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : array();
		$exclude_class_obj->include_table_structure_only( $data );
		die();
	}

	/**
	 * Include files list.
	 *
	 * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
	 */
	public function include_file_list() {

		if ( ! isset( $_POST['data'] ) ) {
			wptc_die_with_json_encode( array( 'status' => 'no data found' ) );
		}
		$category          = isset( $_POST['category'] ) ? wp_unslash( $_POST['category'] ) : array();
		$exclude_class_obj = new \Wptc_ExcludeOption( $category );
		$data              = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : array();
		$exclude_class_obj->include_file_list( wp_unslash( $data ) );
		die();
	}

	/**
	 * Get files by key.
	 *
	 * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
	 */
	public function get_files_by_key() {
		$key               = isset( $_POST['key'] ) ? wp_unslash( $_POST['key'] ) : '';
		$category          = isset( $_POST['category'] ) ? wp_unslash( $_POST['category'] ) : array();
		$exclude_class_obj = new \Wptc_ExcludeOption( $category );
		$exclude_class_obj->get_files_by_key( $key );
		die();
	}

	/**
	 * Process the WP Time Capsule login process.
	 *
	 * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
	 *
	 * @return array Action result.
	 */
	private function process_wptc_login() {
		$options_helper = new \Wptc_Options_Helper();

		if ( $options_helper->get_is_user_logged_in() ) {
			return array(
				'result'    => 'is_user_logged_in',
				'sync_data' => $this->get_sync_data(),
			);
		}

		$email = isset( $_POST['acc_email'] ) ? sanitize_text_field( wp_unslash( $_POST['acc_email'] ) ) : '';
		$pwd   = isset( $_POST['acc_pwd'] ) ? wp_unslash( $_POST['acc_pwd'] ) : '';

		if ( empty( $email ) || empty( $pwd ) ) {
			return array( 'error' => 'Username and password cannot be empty' );
		}

		$config  = \WPTC_Base_Factory::get( 'Wptc_InitialSetup_Config' );
		$options = \WPTC_Factory::get( 'config' );

		$config->set_option( 'wptc_main_acc_email_temp', base64_encode( $email ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
		$config->set_option( 'wptc_main_acc_pwd_temp', base64_encode( md5( trim( wp_unslash( $pwd ) ) ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
		$config->set_option( 'wptc_token', false );

		$cust_info = $options->request_service(
			array(
				'email'                 => $email,
				'pwd'                   => trim( wp_unslash( $pwd ) ),
				'return_response'       => true,
				'sub_action'            => false,
				'login_request'         => true,
				'reset_login_if_failed' => true,
			)
		);

		if ( ! empty( $cust_info->error ) ) {
			if ( ! empty( $cust_info->true_err_msg ) ) {
				return array( 'error' => $cust_info->true_err_msg );
			}

			return array( 'error' => $cust_info->error );
		}

		$options->reset_plans();

		$main_account_email = $email;
		$main_account_pwd   = $pwd;

		$options->set_option( 'main_account_email', strtolower( $main_account_email ) );
		$options->set_option( 'main_account_pwd', $this->hash_pwd( $main_account_pwd ) );

		$result = $this->proccess_cust_info( $options, $cust_info );

		if ( is_array( $result ) && ! empty( $result['error'] ) ) {
			return array( 'error' => $result['error'] );
		}

		$is_user_logged_in = $options->get_option( 'is_user_logged_in' );

		if ( ! $is_user_logged_in ) {
			return array( 'error' => 'Login failed.' );
		}
		return array(
			'result'    => 'ok',
			'sync_data' => $this->get_sync_data(),
		);
	}

	/**
	 * Hash password.
	 *
	 * @param string $str String to hash.
	 *
	 * @return string Hashed password.
	 */
	public function hash_pwd( $str ) {
		return md5( $str );
	}

	/**
	 * Process the sigin response info.
	 *
	 * @param object $options   Options object.
	 * @param object $cust_info Custon info.
	 *
	 * @return bool Action result.
	 */
	public function proccess_cust_info( $options, $cust_info ) {

		if ( $this->process_service_info( $options, $cust_info ) ) {

			if ( empty( $cust_info->success ) ) {
				return false;
			}

			$cust_req_info = $cust_info->success[0];
			$this_d_name   = $cust_req_info->cust_display_name;
			$this_token    = $cust_req_info->wptc_token;

			$options->set_option( 'uuid', $cust_req_info->uuid );
			$options->set_option( 'wptc_token', $this_token );
			$options->set_option( 'main_account_name', $this_d_name );

			do_action( 'update_white_labling_settings_wptc', $cust_req_info );

			if ( isset( $cust_req_info->connected_sites_count ) ) {
				$options->set_option( 'connected_sites_count', $cust_req_info->connected_sites_count );
			} else {
				$options->set_option( 'connected_sites_count', 1 );
			}

			if ( ! empty( $cust_info->logged_in_but_no_plans_yet ) ) {
				$options->do_options_for_no_plans_yet( $cust_info );
				return false;
			}

			$options->process_subs_info_wptc( $cust_req_info );
			$this->process_privilege_wptc( $options, $cust_req_info );
			$this->save_plan_info_limited( $options, $cust_req_info );

			$is_cron_service = $this->check_if_cron_service_exists( $options );
			wptc_log( $is_cron_service, '--------$is_cron_service--------' );

			if ( $is_cron_service ) {
				$options->set_option( 'is_user_logged_in', true );
				return true;
			}
		}
	}


	/**
	 * Save the plan info.
	 *
	 * @param object $options   Options object.
	 * @param object $cust_info Custon info.
	 *
	 * @return bool Action result.
	 */
	private function save_plan_info_limited( $options, &$cust_info ) {
		wptc_log( func_get_args(), '--------' . __FUNCTION__ . '--------' );
		if ( empty( $cust_info ) || empty( $cust_info->plan_info_limited ) ) {
			return $options->set_option( 'plan_info_limited', false );
		} else {
			$plans = json_decode( json_encode( $cust_info->plan_info_limited ), true );
			wptc_log( $plans, '----------$plans----------------' );
			return $options->set_option( 'plan_info_limited', serialize( $plans ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Required for backwards compatibility.
		}
	}

	/**
	 * Process privilege wptc.
	 *
	 * @param object $options       Options object.
	 * @param object $cust_req_info Custon info.
	 *
	 * @return bool Returns false on failure.
	 */
	private function process_privilege_wptc( $options, $cust_req_info = null ) {

		if ( empty( $cust_req_info->subscription_features ) ) {
			$options->reset_privileges();
			return false;
		}

		$sub_features = (array) $cust_req_info->subscription_features;

		$privileged_feature = array();
		$privileges_args    = array();

		foreach ( $sub_features as $plan_id => $single_sub ) {
			foreach ( $single_sub as $key => $v ) {
				$privileged_feature[ $v->type ][]                    = 'Wptc_' . ucfirst( $v->feature );
				$privileges_args[ 'Wptc_' . ucfirst( $v->feature ) ] = ( ! empty( $v->args ) ) ? $v->args : array();
			}
		}

		// Remove on production!
		array_push( $privileges_args, 'Wptc_Rollback' );
		array_push( $privileged_feature['pro'], 'Wptc_Rollback' );

		$options->set_option( 'privileges_wptc', json_encode( $privileged_feature ) );
		$options->set_option( 'privileges_args', json_encode( $privileges_args ) );
		$revision_limit = new \Wptc_Revision_Limit();
		$revision_limit->update_eligible_revision_limit( $privileges_args );
	}

	/**
	 * Process service info.
	 *
	 * @param object $options   Options object.
	 * @param object $cust_info Custon info.
	 *
	 * @return bool result.
	 */
	private function process_service_info( $options, &$cust_info ) {
		if ( empty( $cust_info ) || ! empty( $cust_info->error ) ) {
			$err_msg = $options->process_wptc_error_msg_then_take_action( $cust_info );

			$options->set_option( 'card_added', false );

			if ( 'logged_in_but_no_plans_yet' == $err_msg ) {
				$options->do_options_for_no_plans_yet( $cust_info );

				return true;
			}
			return false;
		} else {
			return true;
		}
	}

	/**
	 * Process service info.
	 *
	 * @param object $options Options object.
	 *
	 * @return bool Result.
	 */
	public function check_if_cron_service_exists( $options ) {
		if ( ! $options->get_option( 'wptc_server_connected' ) || ! $options->get_option( 'appID' ) || $options->get_option( 'signup' ) != 'done' ) {
			if ( $options->get_option( 'main_account_email' ) ) {
				$this->signup_wptc_server_wptc();
			}
		}
		return true;
	}


	/**
	 * Function for wptc cron service signup
	 *
	 * @return bool result.
	 */
	public function signup_wptc_server_wptc() {

		$config = \WPTC_Factory::get( 'config' );

		$email         = trim( $config->get_option( 'main_account_email', true ) );
		$emailhash     = md5( $email );
		$email_encoded = base64_encode( $email ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Required for backwards compatibility.

		$pwd         = trim( $config->get_option( 'main_account_pwd', true ) );
		$pwd_encoded = base64_encode( $pwd ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Required for backwards compatibility.

		if ( empty( $email ) || empty( $pwd ) ) {
			return false;
		}

		wptc_log( $email, '--------email--------' );

		$name = trim( $config->get_option( 'main_account_name' ) );

		$cron_url = get_wptc_cron_url();

		$app_id = 0;
		if ( $config->get_option( 'appID' ) ) {
			$app_id = $config->get_option( 'appID' );
		}

		$post_arr = array(
			'email'     => $email_encoded,
			'pwd'       => $pwd_encoded,
			'cron_url'  => $cron_url,
			'site_url'  => home_url(),
			'name'      => $name,
			'emailhash' => $emailhash,
			'app_id'    => $app_id,
		);

		$result = do_cron_call_wptc( 'signup', $post_arr );

		$resarr = json_decode( $result );

		wptc_log( $resarr, '--------resarr-node reply--------' );

		if ( ! empty( $resarr ) && 'success' == $resarr->status ) {
			$config->set_option( 'wptc_server_connected', true );
			$config->set_option( 'signup', 'done' );
			$config->set_option( 'appID', $resarr->appID );

			init_auto_backup_settings_wptc( $config );
			$set = push_settings_wptc_server( $resarr->appID, 'signup' );

			$to_url = network_admin_url() . 'admin.php?page=wp-time-capsule';
			return true;
		} else {
			$config->set_option( 'last_service_error', $result );
			$config->set_option( 'appID', false );

			if ( 'production' !== WPTC_ENV ) {
				echo 'Creating Cron service failed';
			}

			return false;
		}
	}

	/**
	 * Get the list of installed plugins.
	 *
	 * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
	 *
	 * @return array Action result.
	 */
	public function get_installed_plugins() {

		$backup_before_auto_update_settings = \WPTC_Pro_Factory::get( 'Wptc_Backup_Before_Auto_Update_Settings' );
		$plugins                            = $backup_before_auto_update_settings->get_installed_plugins();

		if ( $plugins ) {
			return array( 'results' => $plugins );
		}
		return array( 'results' => array() );
	}

	/**
	 * Get the list of installed themes.
	 *
	 * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
	 *
	 * @return array Action result.
	 */
	public function get_installed_themes() {

		$backup_before_auto_update_settings = \WPTC_Pro_Factory::get( 'Wptc_Backup_Before_Auto_Update_Settings' );

		$plugins = $backup_before_auto_update_settings->get_installed_themes();
		if ( $plugins ) {
			return array( 'results' => $plugins );
		}
		return array( 'results' => array() );
	}

	/**
	 * Check if staging request needed.
	 *
	 * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
	 */
	public function is_staging_need_request() {
		$staging = \WPTC_Pro_Factory::get( 'Wptc_Staging' );
		$staging->is_staging_need_request();
		die();
	}

	/**
	 * Get the staging details.
	 *
	 * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
	 */
	public function get_staging_details_wptc() {
		$staging               = \WPTC_Pro_Factory::get( 'Wptc_Staging' );
		$details               = $staging->get_staging_details();
		$details['is_running'] = $staging->is_any_staging_process_going_on();
		wptc_die_with_json_encode( $details, 1 );
	}

	/**
	 * Create a fresh staging site.
	 *
	 * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
	 */
	public function start_fresh_staging_wptc() {
		$staging = \WPTC_Pro_Factory::get( 'Wptc_Staging' );

		if ( empty( $_POST['path'] ) ) {
			wptc_die_with_json_encode(
				array(
					'status' => 'error',
					'msg'    => 'path is missing',
				)
			);
		}

		$staging->choose_action( wp_unslash( $_POST['path'] ), $reqeust_type = 'fresh' );
		die();
	}

	/**
	 * Get the staging site URL.
	 *
	 * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
	 */
	public function get_staging_url_wptc() {
		$staging = \WPTC_Pro_Factory::get( 'Wptc_Staging' );
		$staging->get_staging_url_wptc();
		die();
	}

	/**
	 * Stop the staging site creation process.
	 *
	 * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
	 */
	public function stop_staging_wptc() {
		$staging = \WPTC_Pro_Factory::get( 'Wptc_Staging' );
		$staging->stop_staging_wptc();
		die();
	}

	/**
	 * Continue the staging site creation process.
	 *
	 * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
	 */
	public function continue_staging_wptc() {
		$staging = \WPTC_Pro_Factory::get( 'Wptc_Staging' );
		$staging->choose_action();
		die();
	}

	/**
	 * Delete the staging site.
	 *
	 * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
	 */
	public function delete_staging_wptc() {
		$staging = \WPTC_Pro_Factory::get( 'Wptc_Staging' );
		$staging->delete_staging_wptc();
		die();
	}

	/**
	 * Copy the staging site.
	 *
	 * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
	 */
	public function copy_staging_wptc() {
		$staging = \WPTC_Pro_Factory::get( 'Wptc_Staging' );
		$staging->choose_action( false, $reqeust_type = 'copy' );
		die();
	}

	/**
	 * Get the current staging site status key.
	 *
	 * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
	 */
	public function get_staging_current_status_key() {
		$staging = \WPTC_Pro_Factory::get( 'Wptc_Staging' );
		$staging->get_staging_current_status_key();
		die();
	}

	/**
	 * Sync the WP Time Capsule purchase.
	 *
	 * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
	 */
	public function wptc_sync_purchase() {
		$config = \WPTC_Factory::get( 'config' );

		$config->request_service(
			array(
				'email'           => false,
				'pwd'             => false,
				'return_response' => false,
				'sub_action'      => 'sync_all_settings_to_node',
				'login_request'   => true,
			)
		);
		die();
	}

	/**
	 * Initiate the restore process.
	 *
	 * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
	 */
	public function init_restore() {

		if ( empty( $_POST ) ) {
			return ( array( 'error' => 'Backup id is empty !' ) );
		}
		$restore_to_staging = \WPTC_Base_Factory::get( 'Wptc_Restore_To_Staging' );
		$restore_to_staging->init_restore( $_POST );

		die();
	}

	/**
	 * Save the WP Time Capsule settings.
	 *
	 * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
	 */
	public function save_settings_wptc() {

		$options_helper = new \Wptc_Options_Helper();

		if ( ! $options_helper->get_is_user_logged_in() ) {
			return array(
				'sync_data' => $this->get_sync_data(),
				'error'     => 'Login to your WP Time Capsule account first',
			);
		}

		$data = isset( $_POST['data'] ) ? json_decode( base64_decode( wp_unslash( $_POST['data'] ) ), true ) : array(); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode required for the backwards compatibility.

		$tabName    = isset( $_POST['tabname'] ) ? sanitize_text_field( wp_unslash( $_POST['tabname'] ) ) : '';
		$is_general = isset( $_POST['is_general'] ) ? sanitize_text_field( wp_unslash( $_POST['is_general'] ) ) : '';

		$saved  = false;
		$config = \WPTC_Factory::get( 'config' );
		if ( 'backup' == $tabName ) {
			$this->save_settings_backup_tab( $config, $data );
			$saved = true;
		} elseif ( 'backup_auto' == $tabName ) {
			$this->save_settings_backup_auto_tab( $config, $data, $is_general );
			$saved = true;
		} elseif ( 'vulns_update' == $tabName ) {
			$this->save_settings_vulns_update_tab( $config, $data, $is_general );
			$saved = true;
		} elseif ( 'staging_opts' == $tabName ) {
			$this->save_settings_staging_opts_tab( $config, $data, $is_general );
			$saved = true;
		}
		if ( ! $saved ) {
			return array( 'error' => 'Error: Not saved settings' );
		}
		return array( 'result' => 'ok' );
	}

	/**
	 * Save the WP Time Capsule settings - backups section.
	 *
	 * @param object $config Save config class.
	 * @param array  $data   Data to save.
	 */
	private function save_settings_backup_tab( $config, $data ) {

		$config->set_option( 'user_excluded_extenstions', $data['user_excluded_extenstions'] );
		$config->set_option( 'user_excluded_files_more_than_size_settings', $data['user_excluded_files_more_than_size_settings'] );

		if ( ! empty( $data['backup_slot'] ) ) {
			$config->set_option( 'old_backup_slot', $config->get_option( 'backup_slot' ) );
			$config->set_option( 'backup_slot', $data['backup_slot'] );
		}

		$config->set_option( 'backup_db_query_limit', $data['backup_db_query_limit'] );
		$config->set_option( 'database_encrypt_settings', $data['database_encrypt_settings'] );
		$config->set_option( 'wptc_timezone', $data['wptc_timezone'] );
		$config->set_option( 'schedule_time_str', $data['schedule_time_str'] );

		if ( ! empty( $data['schedule_time_str'] ) && ! empty( $data['wptc_timezone'] ) ) {
			if ( function_exists( 'wptc_modify_schedule_backup' ) ) {
				wptc_modify_schedule_backup();
			}
		}

		$notice = apply_filters( 'check_requirements_auto_backup_wptc', '' );

		if ( ! empty( $data['revision_limit'] ) && ! $notice ) {
			$notice = apply_filters( 'save_settings_revision_limit_wptc', $data['revision_limit'] );
		}
	}

	/**
	 * Save the WP Time Capsule settings - backups auto section.
	 *
	 * @param object $config     Save config class.
	 * @param array  $data       Data to save.
	 * @param bool   $is_general Is general settings check.
	 */
	private function save_settings_backup_auto_tab( $config, $data, $is_general ) {
		$config->set_option( 'backup_before_update_setting', $data['backup_before_update_setting'] );
		$current                              = $config->get_option( 'wptc_auto_update_settings' );
		$current = unserialize( $current ); // phpcs:ignore -- safe internal value, third party.
		$new     = unserialize( $data['wptc_auto_update_settings'] ); // phpcs:ignore -- safe internal value, third party.
		$current['update_settings']['status'] = $new['update_settings']['status'];
		$current['update_settings']['schedule']['enabled']     = $new['update_settings']['schedule']['enabled'];
		$current['update_settings']['schedule']['time']        = $new['update_settings']['schedule']['time'];
		$current['update_settings']['core']['major']['status'] = $new['update_settings']['core']['major']['status'];
		$current['update_settings']['core']['minor']['status'] = $new['update_settings']['core']['minor']['status'];
		$current['update_settings']['themes']['status']        = $new['update_settings']['themes']['status'];
		$current['update_settings']['plugins']['status']       = $new['update_settings']['plugins']['status'];

		if ( ! $is_general ) {
			if ( isset( $new['update_settings']['plugins']['included'] ) ) {
				$current['update_settings']['plugins']['included'] = $new['update_settings']['plugins']['included'];
			} else {
				$current['update_settings']['plugins']['included'] = array();
			}

			if ( isset( $new['update_settings']['themes']['included'] ) ) {
				$current['update_settings']['themes']['included'] = $new['update_settings']['themes']['included'];
			} else {
				$current['update_settings']['themes']['included'] = array();
			}
		}
		$config->set_option( 'wptc_auto_update_settings', serialize( $current ) ); // phpcs:ignore -- safe internal value.
	}

	/**
	 * Save the WP Time Capsule settings - vulnerable updates section.
	 *
	 * @param object $config     Save config class.
	 * @param array  $data       Data to save.
	 * @param bool   $is_general Is general settings check.
	 */
	private function save_settings_vulns_update_tab( $config, $data, $is_general ) {
		$current = $config->get_option( 'vulns_settings' );
		$current = unserialize( $current ); // phpcs:ignore -- safe internal value, third party.
		$new     = unserialize( $data['vulns_settings'] ); // phpcs:ignore -- safe internal value, third party.

		$current['status']            = $new['status'];
		$current['core']['status']    = $new['core']['status'];
		$current['themes']['status']  = $new['themes']['status'];
		$current['plugins']['status'] = $new['plugins']['status'];

		if ( ! $is_general ) {
			$vulns_plugins_included = ! empty( $new['plugins']['vulns_plugins_included'] ) ? $new['plugins']['vulns_plugins_included'] : array();

			$plugin_include_array = array();

			if ( ! empty( $vulns_plugins_included ) ) {
				$plugin_include_array = explode( ',', $vulns_plugins_included );
				$plugin_include_array = ! empty( $plugin_include_array ) ? $plugin_include_array : array();
			}

			wptc_log( $plugin_include_array, '--------$plugin_include_array--------' );

			$included_plugins = $this->filter_plugins( $plugin_include_array );

			wptc_log( $included_plugins, '--------$included_plugins--------' );

			$current['plugins']['excluded'] = serialize( $included_plugins ); // phpcs:ignore -- safe internal value, third party.

			$vulns_themes_included = ! empty( $new['themes']['vulns_themes_included'] ) ? $new['themes']['vulns_themes_included'] : array();

			$themes_include_array = array();

			if ( ! empty( $vulns_themes_included ) ) {
				$themes_include_array = explode( ',', $vulns_themes_included );
			}

			$included_themes               = $this->filter_themes( $themes_include_array );
			$current['themes']['excluded'] = serialize( $included_themes ); // phpcs:ignore -- safe internal value, third party.
		}
		$config->set_option( 'vulns_settings', serialize( $current ) ); // phpcs:ignore -- safe internal value, third party.
	}

	/**
	 * Save the WP Time Capsule settings - staging section.
	 *
	 * @param object $config     Save config class.
	 * @param array  $data       Data to save.
	 * @param bool   $is_general Is general settings check.
	 */
	private function save_settings_staging_opts_tab( $config, $data, $is_general ) {
		$config->set_option( 'user_excluded_extenstions_staging', $data['user_excluded_extenstions_staging'] );
		$config->set_option( 'internal_staging_db_rows_copy_limit', $data['internal_staging_db_rows_copy_limit'] );
		$config->set_option( 'internal_staging_file_copy_limit', $data['internal_staging_file_copy_limit'] );
		$config->set_option( 'internal_staging_deep_link_limit', $data['internal_staging_deep_link_limit'] );
		$config->set_option( 'internal_staging_enable_admin_login', $data['internal_staging_enable_admin_login'] );
		$config->set_option( 'staging_is_reset_permalink', $data['staging_is_reset_permalink'] );
		if ( ! $is_general ) {
			$config->set_option( 'staging_login_custom_link', $data['staging_login_custom_link'] );
		}
	}

	/**
	 * Filter plugins.
	 *
	 * @param array $included_plugins List of included plugins.
	 *
	 * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
	 *
	 * @return array Filtered list of plugins.
	 */
	private function filter_plugins( $included_plugins ) {
		$app_functions       = \WPTC_Base_Factory::get( 'Wptc_App_Functions' );
		$specific            = true;
		$attr                = 'slug';
		$plugins_data        = $app_functions->get_all_plugins_data( $specific, $attr );
		$not_included_plugin = array_diff( $plugins_data, $included_plugins );
		wptc_log( $plugins_data, '--------$plugins_data--------' );
		wptc_log( $not_included_plugin, '--------$not_included_plugin--------' );
		return $not_included_plugin;
	}

	/**
	 * Filter themes.
	 *
	 * @param array $included_themes List of included themes.
	 *
	 * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
	 *
	 * @return array Filtered list of themes.
	 */
	private function filter_themes( $included_themes ) {
		$app_functions      = \WPTC_Base_Factory::get( 'Wptc_App_Functions' );
		$specific           = true;
		$attr               = 'slug';
		$themes_data        = $app_functions->get_all_themes_data( $specific, $attr );
		$not_included_theme = array_diff( $themes_data, $included_themes );
		wptc_log( $themes_data, '--------$themes_data--------' );
		wptc_log( $not_included_theme, '--------$not_included_theme--------' );
		return $not_included_theme;
	}

	/**
	 * Analyze database tables.
	 *
	 * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
	 */
	public function analyze_inc_exc() {
		$exclude_opts_obj = \WPTC_Base_Factory::get( 'Wptc_ExcludeOption' );
		$exclude_opts_obj = $exclude_opts_obj->analyze_inc_exc();
		die();
	}

	/**
	 * Get enabled plugins.
	 *
	 * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
	 *
	 * @return array Action result.
	 */
	public function get_enabled_plugins() {
		$vulns_obj = \WPTC_Base_Factory::get( 'Wptc_Vulns' );

		$plugins = $vulns_obj->get_enabled_plugins();
		$plugins = \WPTC_Base_Factory::get( 'Wptc_App_Functions' )->fancytree_format( $plugins, 'plugins' );

		return array( 'results' => $plugins );
	}

	/**
	 * Get enabled themes.
	 *
	 * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
	 *
	 * @return array Action result.
	 */
	public function get_enabled_themes() {
		$vulns_obj = \WPTC_Base_Factory::get( 'Wptc_Vulns' );
		$themes    = $vulns_obj->get_enabled_themes();
		$themes    = \WPTC_Base_Factory::get( 'Wptc_App_Functions' )->fancytree_format( $themes, 'themes' );
		return array( 'results' => $themes );
	}

	/**
	 * Get the system info.
	 *
	 * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
	 *
	 * @return array Action result.
	 */
	public function get_system_info() {

		/**
		 * Object, providing access to the WordPress database.
		 *
		 * @global $wpdb WordPress Database instance.
		 */
		global $wpdb;

		$wptc_settings = \WPTC_Base_Factory::get( 'Wptc_Settings' );

		ob_start();

		echo '<table class="wp-list-table widefat fixed" cellspacing="0" >';
		echo '<thead><tr><th width="35%">' . esc_html__( 'Setting', 'wp-time-capsule' ) . '</th><th>' . esc_html__( 'Value', 'wp-time-capsule' ) . '</th></tr></thead>';
		echo '<tr title="&gt;=3.9.14"><td>' . esc_html__( 'WordPress version', 'wp-time-capsule' ) . '</td><td>' . esc_html( $wptc_settings->get_plugin_data( 'wp_version' ) ) . '</td></tr>';
		echo '<tr title=""><td>' . esc_html__( 'WP Time Capsule version', 'wp-time-capsule' ) . '</td><td>' . esc_html( $wptc_settings->get_plugin_data( 'Version' ) ) . '</td></tr>';

		$bit = '';
		if ( PHP_INT_SIZE === 4 ) {
			$bit = ' (32bit)';
		}
		if ( PHP_INT_SIZE === 8 ) {
			$bit = ' (64bit)';
		}

		echo '<tr title="&gt;=5.3.1"><td>' . esc_html__( 'PHP version', 'wp-time-capsule' ) . '</td><td>' . esc_html( PHP_VERSION . ' ' . $bit ) . '</td></tr>';
		echo '<tr title="&gt;=5.0.15"><td>' . esc_html__( 'MySQL version', 'wp-time-capsule' ) . '</td><td>' . esc_html( $wpdb->get_var( 'SELECT VERSION() AS version' ) ) . '</td></tr>';

		if ( function_exists( 'curl_version' ) ) {
			$curlversion = curl_version();
			echo '<tr title=""><td>' . esc_html__( 'cURL version', 'wp-time-capsule' ) . '</td><td>' . esc_html( $curlversion['version'] ) . '</td></tr>';
			echo '<tr title=""><td>' . esc_html__( 'cURL SSL version', 'wp-time-capsule' ) . '</td><td>' . esc_html( $curlversion['ssl_version'] ) . '</td></tr>';
		} else {
			echo '<tr title=""><td>' . esc_html__( 'cURL version', 'wp-time-capsule' ) . '</td><td>' . esc_html__( 'unavailable', 'wp-time-capsule' ) . '</td></tr>';
		}

		echo '</td></tr>';
		echo '<tr title=""><td>' . esc_html__( 'Server', 'wp-time-capsule' ) . '</td><td>' . ( isset( $_SERVER['SERVER_SOFTWARE'] ) ? esc_html( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) ) : '' ) . '</td></tr>';
		echo '<tr title=""><td>' . esc_html__( 'Operating System', 'wp-time-capsule' ) . '</td><td>' . esc_html( PHP_OS ) . '</td></tr>';
		echo '<tr title=""><td>' . esc_html__( 'PHP SAPI', 'wp-time-capsule' ) . '</td><td>' . esc_html( PHP_SAPI ) . '</td></tr>';

		$php_user = esc_html__( 'Function Disabled', 'wp-time-capsule' );
		if ( function_exists( 'get_current_user' ) ) {
			$php_user = get_current_user();
		}

		echo '<tr title=""><td>' . esc_html__( 'Current PHP user', 'wp-time-capsule' ) . '</td><td>' . esc_html( $php_user ) . '</td></tr>';
		echo '<tr title="&gt;=30"><td>' . esc_html__( 'Maximum execution time', 'wp-time-capsule' ) . '</td><td>' . esc_html( ini_get( 'max_execution_time' ) ) . ' ' . esc_html__( 'seconds', 'wp-time-capsule' ) . '</td></tr>';

		if ( defined( 'FS_CHMOD_DIR' ) ) {
			echo '<tr title="FS_CHMOD_DIR"><td>' . esc_html__( 'CHMOD Dir', 'wp-time-capsule' ) . '</td><td>' . esc_html( FS_CHMOD_DIR ) . '</td></tr>';
		} else {
			echo '<tr title="FS_CHMOD_DIR"><td>' . esc_html__( 'CHMOD Dir', 'wp-time-capsule' ) . '</td><td>0755</td></tr>';
		}

		$now = localtime( time(), true );
		echo '<tr title=""><td>' . esc_html__( 'Server Time', 'wp-time-capsule' ) . '</td><td>' . esc_html( $now['tm_hour'] . ':' . $now['tm_min'] ) . '</td></tr>';
		echo '<tr title=""><td>' . esc_html__( 'Blog Time', 'wp-time-capsule' ) . '</td><td>' . date( 'H:i', current_time( 'timestamp' ) ) . '</td></tr>'; // phpcs:ignore -- local time.
		echo '<tr title="WPLANG"><td>' . esc_html__( 'Blog language', 'wp-time-capsule' ) . '</td><td>' . get_bloginfo( 'language' ) . '</td></tr>';
		echo '<tr title="utf8"><td>' . esc_html__( 'MySQL Client encoding', 'wp-time-capsule' ) . '</td><td>';
		echo defined( 'DB_CHARSET' ) ? DB_CHARSET : '';
		echo '</td></tr>';
		echo '<tr title="URF-8"><td>' . esc_html__( 'Blog charset', 'wp-time-capsule' ) . '</td><td>' . get_bloginfo( 'charset' ) . '</td></tr>';
		echo '<tr title="&gt;=128M"><td>' . esc_html__( 'PHP Memory limit', 'wp-time-capsule' ) . '</td><td>' . esc_html( ini_get( 'memory_limit' ) ) . '</td></tr>';
		echo '<tr title="WP_MEMORY_LIMIT"><td>' . esc_html__( 'WP memory limit', 'wp-time-capsule' ) . '</td><td>' . esc_html( WP_MEMORY_LIMIT ) . '</td></tr>';
		echo '<tr title="WP_MAX_MEMORY_LIMIT"><td>' . esc_html__( 'WP maximum memory limit', 'wp-time-capsule' ) . '</td><td>' . esc_html( WP_MAX_MEMORY_LIMIT ) . '</td></tr>';
		echo '<tr title=""><td>' . esc_html__( 'Memory in use', 'wp-time-capsule' ) . '</td><td>' . size_format( memory_get_usage( true ), 2 ) . '</td></tr>';

		// disabled PHP functions.
		$disabled = esc_html( ini_get( 'disable_functions' ) );
		if ( ! empty( $disabled ) ) {
			$disabledarry = explode( ',', $disabled );
			echo '<tr title=""><td>' . esc_html__( 'Disabled PHP Functions:', 'wp-time-capsule' ) . '</td><td>';
			echo implode( ', ', $disabledarry );
			echo '</td></tr>';
		}

		// Loaded PHP Extensions.
		echo '<tr title=""><td>' . esc_html__( 'Loaded PHP Extensions:', 'wp-time-capsule' ) . '</td><td>';
		$extensions = get_loaded_extensions();
		sort( $extensions );
		echo esc_html( implode( ', ', $extensions ) );
		echo '</td></tr>';
		echo '</table>';

		$html = ob_get_clean();
		return array( 'result' => $html );
	}

	/**
	 * Update vulnerable updates settings.
	 *
	 * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
	 *
	 * @return array Action result.
	 */
	public function update_vulns_settings() {

		$vulns_obj = \WPTC_Base_Factory::get( 'Wptc_Vulns' );

		$data = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : array();
		$vulns_obj->update_vulns_settings( $data );

		return array( 'success' => 1 );
	}

	/**
	 * Start a fresh backup.
	 *
	 * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
	 *
	 * @return array Action result.
	 */
	public function start_fresh_backup_tc_callback_wptc() {
		$type            = '';
		$args            = null;
		$test_connection = true;
		$ajax_check      = false;
		start_fresh_backup_tc_callback_wptc( $type, $args, $test_connection, $ajax_check );
		return array( 'result' => 'success' );
	}

	/**
	 * Save manual backup name.
	 *
	 * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
	 */
	public function save_manual_backup_name_wptc() {
		$backup_name     = isset( $_POST['backup_name'] ) ? sanitize_text_field( wp_unslash( $_POST['backup_name'] ) ) : '';
		$processed_files = \WPTC_Factory::get( 'processed-files' );
		$processed_files->save_manual_backup_name_wptc( $backup_name );
		die();
	}

	/**
	 * Remove the WP Time Capsule plugin from the list of all plugins when the plugin is hidden.
	 *
	 * @param array $plugins Array containing all installed plugins.
	 *
	 * @return array $plugins Array containing all installed plugins without the WP Time Capsule.
	 */
	public function all_plugins( $plugins ) {
		foreach ( $plugins as $key => $value ) {
			$plugin_slug = basename( $key, '.php' );
			if ( 'wp-time-capsule' === $plugin_slug ) {
				unset( $plugins[ $key ] );
			}
		}

		return $plugins;
	}

	/**
	 * Remove the WP Time Capsule menu item when the plugin is hidden.
	 */
	public function remove_menu() {
		remove_menu_page( 'wp-time-capsule-monitor' );
		$pos = isset( $_SERVER['REQUEST_URI'] ) ? stripos( wp_unslash( $_SERVER['REQUEST_URI'] ), 'admin.php?page=wp-time-capsule-monitor' ) : false;
		if ( false !== $pos ) {
			wp_safe_redirect( get_option( 'siteurl' ) . '/wp-admin/index.php' );
			exit();
		}
	}

	/**
	 * Remove the WP Time Capsule plugin update notice when the plugin is hidden.
	 *
	 * @param array $slugs Array containing installed plugins slugs.
	 *
	 * @return array $slugs Array containing installed plugins slugs.
	 */
	public function hide_update_notice( $slugs ) {
		$slugs[] = 'wp-time-capsule/wp-time-capsule.php';
		return $slugs;
	}

	/**
	 * Remove the WP Time Capsule plugin update notice when the plugin is hidden.
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
		if ( isset( $value->response['wp-time-capsule/wp-time-capsule.php'] ) ) {
			unset( $value->response['wp-time-capsule/wp-time-capsule.php'] );
		}

		return $value;
	}
}
