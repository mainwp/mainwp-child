<?php
/**
 * MainWP Child WPVivid Backup & Restore
 *
 * This file handles all of the WPvivid Backup & Restore actions.
 */

namespace MainWP\Child;

// phpcs:disable PSR1.Classes.ClassDeclaration, WordPress.WP.AlternativeFunctions --  to use external code, third party credit.

/**
 * Class MainWP_Child_WPvivid_BackupRestore.
 */
class MainWP_Child_WPvivid_BackupRestore {

	/**
	 * @static
	 * @var null Holds the Public static instance of MainWP_Child_WPvivid_BackupRestore.
	 */
	public static $instance = null;

	/** @var bool Whether WPvivid Plugin is installed or not. */
	public $is_plugin_installed = false;

	/** @var object WPvivid_Public_Interface */
	public $public_intetface;

	/**
	 * Create a public static instance of MainWP_Child_WPvivid_BackupRestore.
	 *
	 * @return MainWP_Child_WPvivid_BackupRestore|null
	 */
	static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * MainWP_Child_WPvivid_BackupRestore constructor.
	 *
	 * Run any time class is called.
	 */
	public function __construct() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		if ( is_plugin_active( 'wpvivid-backuprestore/wpvivid-backuprestore.php' ) && defined( 'WPVIVID_PLUGIN_DIR' ) ) {
			$this->is_plugin_installed = true;
		}

		if ( ! $this->is_plugin_installed ) {
			return;
		}

		add_filter( 'mainwp_site_sync_others_data', array( $this, 'sync_others_data' ), 10, 2 );
		$this->public_intetface = new \WPvivid_Public_Interface();
	}

	/**
	 * MainWP_Child_WPvivid_BackupRestore initiator.
	 */
	public function init() {
	}

	/**
	 * Sync other data from $data[] and merge with $information[]
	 *
	 * @param array $information Stores the returned information.
	 * @param array $data Other data to sync.
	 *
	 * @return array $information Returned information array with both sets of data.
	 * @throws Exception Error message.
	 *
	 * @uses WPvivid_Setting::get_sync_data()
	 */
	function sync_others_data( $information, $data = array() ) {
		try {

			if ( isset( $data['syncWPvividData'] ) ) {
				$information['syncWPvividData']         = 1;
				$data                                   = \WPvivid_Setting::get_sync_data();
				$information['syncWPvividSettingData']  = $data['setting'];
				$information['syncWPvividRemoteData']   = $data['remote'];
				$information['syncWPvividScheduleData'] = $data['schedule'];
				$information['syncWPvividSetting']      = $data;
			}
		} catch ( \Exception $e ) {

		}

		return $information;
	}

	/**
	 * Perform specific WPvivid actions.
	 *
	 * @uses \MainWP\Child\MainWP_Child_WPvivid_BackupRestore::prepare_backup()
	 * @uses \MainWP\Child\MainWP_Child_WPvivid_BackupRestore::backup_now()
	 * @uses \MainWP\Child\MainWP_Child_WPvivid_BackupRestore::get_status()
	 * @uses \MainWP\Child\MainWP_Child_WPvivid_BackupRestore::get_backup_schedule()
	 * @uses \MainWP\Child\MainWP_Child_WPvivid_BackupRestore::get_backup_list();
	 * @uses \MainWP\Child\MainWP_Child_WPvivid_BackupRestore::get_default_remote();
	 * @uses \MainWP\Child\MainWP_Child_WPvivid_BackupRestore::delete_backup();
	 * @uses \MainWP\Child\MainWP_Child_WPvivid_BackupRestore::delete_backup_array();
	 * @uses \MainWP\Child\MainWP_Child_WPvivid_BackupRestore::set_security_lock();
	 * @uses \MainWP\Child\MainWP_Child_WPvivid_BackupRestore::view_log();
	 * @uses \MainWP\Child\MainWP_Child_WPvivid_BackupRestore::read_last_backup_log();
	 * @uses \MainWP\Child\MainWP_Child_WPvivid_BackupRestore::view_backup_task_log();
	 * @uses \MainWP\Child\MainWP_Child_WPvivid_BackupRestore::backup_cancel();
	 * @uses \MainWP\Child\MainWP_Child_WPvivid_BackupRestore::init_download_page();
	 * @uses \MainWP\Child\MainWP_Child_WPvivid_BackupRestore::prepare_download_backup();
	 * @uses \MainWP\Child\MainWP_Child_WPvivid_BackupRestore::get_download_task();
	 * @uses \MainWP\Child\MainWP_Child_WPvivid_BackupRestore::download_backup();
	 * @uses \MainWP\Child\MainWP_Child_WPvivid_BackupRestore::set_general_setting();
	 * @uses \MainWP\Child\MainWP_Child_WPvivid_BackupRestore::set_schedule();
	 * @uses \MainWP\Child\MainWP_Child_WPvivid_BackupRestore::set_remote();
	 * @uses \MainWP\Child\MainWP_Child_WPvivid_BackupRestore::post_mainwp_data($_POST);
	 * @uses \MainWP\Child\MainWP_Helper::write()
	 */
	public function action() {
		$information = array();
		if ( ! $this->is_plugin_installed ) {
			$information['error'] = 'NO_WPVIVIDBACKUP';
			MainWP_Helper::write( $information );
		}

		if ( isset( $_POST['mwp_action'] ) ) {
			$mwp_action = ! empty( $_POST['mwp_action'] ) ? sanitize_text_field( wp_unslash( $_POST['mwp_action'] ) ) : '';
			try {
				switch ( $mwp_action ) {
					case 'prepare_backup':
						$information = $this->prepare_backup();
						break;
					case 'backup_now':
						$information = $this->backup_now();
						break;
					case 'get_status':
						$information = $this->get_status();
						break;
					case 'get_backup_schedule':
						$information = $this->get_backup_schedule();
						break;
					case 'get_backup_list':
						$information = $this->get_backup_list();
						break;
					case 'get_default_remote':
						$information = $this->get_default_remote();
						break;
					case 'delete_backup':
						$information = $this->delete_backup();
						break;
					case 'delete_backup_array':
						$information = $this->delete_backup_array();
						break;
					case 'set_security_lock':
						$information = $this->set_security_lock();
						break;
					case 'view_log':
						$information = $this->view_log();
						break;
					case 'read_last_backup_log':
						$information = $this->read_last_backup_log();
						break;
					case 'view_backup_task_log':
						$information = $this->view_backup_task_log();
						break;
					case 'backup_cancel':
						$information = $this->backup_cancel();
						break;
					case 'init_download_page':
						$information = $this->init_download_page();
						break;
					case 'prepare_download_backup':
						$information = $this->prepare_download_backup();
						break;
					case 'get_download_task':
						$information = $this->get_download_task();
						break;
					case 'download_backup':
						$information = $this->download_backup();
						break;
					case 'set_general_setting':
						$information = $this->set_general_setting();
						break;
					case 'set_schedule':
						$information = $this->set_schedule();
						break;
					case 'set_remote':
						$information = $this->set_remote();
						break;
					default:
						$information = $this->post_mainwp_data( $_POST );
						break;
				}
			} catch ( \Exception $e ) {
				$information = array( 'error' => $e->getMessage() );
			}

			MainWP_Helper::write( $information );
		}
	}

	/**
	 * Post MainWP data.
	 *
	 * @param string $data Data to post.
	 *
	 * @return mixed $ret Returned response.
	 */
	public function post_mainwp_data( $data ) {

		/** @global object $wpvivid_plugin WPVivid Class. */
		global $wpvivid_plugin;

		$ret = $wpvivid_plugin->wpvivid_handle_mainwp_action( $data );
		return $ret;
	}

	/**
	 * Prepare backup.
	 *
	 * @uses MainWP_Child_WPvivid_BackupRestore::$public_intetface::prepare_backup()
	 *
	 * @return mixed $ret Returned response.
	 */
	public function prepare_backup() {

		/** @global object $wpvivid_plugin WPVivid Class. */
		global $wpvivid_plugin;

		$wpvivid_plugin->ajax_check_security();
		$ret = isset( $_POST['backup'] ) ? $this->public_intetface->prepare_backup( sanitize_text_field( wp_unslash( $_POST['backup'] ) ) ) : false;
		return $ret;
	}

	/**
	 * Backup now.
	 *
	 * @uses MainWP_Child_WPvivid_BackupRestore::$public_intetface::backup_now()
	 *
	 * @return mixed $ret Returned response.
	 */
	public function backup_now() {

		/** @global object $wpvivid_plugin WPVivid Class. */
		global $wpvivid_plugin;

		$wpvivid_plugin->ajax_check_security();
		$ret = isset( $_POST['task_id'] ) ? $this->public_intetface->backup_now( sanitize_text_field( wp_unslash( $_POST['task_id'] ) ) ) : false;
		return $ret;
	}

	/**
	 * Get status.
	 *
	 * @uses MainWP_Child_WPvivid_BackupRestore::$public_intetface::get_status()
	 *
	 * @return mixed $ret Returned response.
	 */
	public function get_status() {

		/** @global object $wpvivid_plugin WPVivid Class. */
		global $wpvivid_plugin;

		$wpvivid_plugin->ajax_check_security();
		$ret = $this->public_intetface->get_status();
		return $ret;
	}

	/**
	 * Get backup schedule.
	 *
	 * @uses MainWP_Child_WPvivid_BackupRestore::$public_intetface::get_backup_schedule()
	 *
	 * @return mixed $ret Returned response.
	 */
	public function get_backup_schedule() {

		/** @global object $wpvivid_plugin WPVivid Class. */
		global $wpvivid_plugin;

		$wpvivid_plugin->ajax_check_security();
		$ret = $this->public_intetface->get_backup_schedule();
		return $ret;
	}

	/**
	 * Get backup list.
	 *
	 * @uses MainWP_Child_WPvivid_BackupRestore::$public_intetface::get_backup_list()
	 *
	 * @return mixed $ret Returned response.
	 */
	public function get_backup_list() {

		/** @global object $wpvivid_plugin WPVivid Class. */
		global $wpvivid_plugin;

		$wpvivid_plugin->ajax_check_security();
		$ret = $this->public_intetface->get_backup_list();
		return $ret;
	}

	/**
	 * Get default remote destination.
	 *
	 * @uses MainWP_Child_WPvivid_BackupRestore::$public_intetface::get_default_remote()
	 *
	 * @return mixed $ret Returned response.
	 */
	public function get_default_remote() {

		/** @global object $wpvivid_plugin WPVivid Class. */
		global $wpvivid_plugin;

		$wpvivid_plugin->ajax_check_security();
		$ret = $this->public_intetface->get_default_remote();
			return $ret;
	}

	/**
	 * Delete backup.
	 *
	 * @uses MainWP_Child_WPvivid_BackupRestore::$public_intetface::delete_backup()
	 *
	 * @return mixed $ret Returned response.
	 */
	public function delete_backup() {

		/** @global object $wpvivid_plugin WPVivid Class. */
		global $wpvivid_plugin;

		$wpvivid_plugin->ajax_check_security();
		$ret = isset( $_POST['backup_id'] ) && isset( $_POST['force'] ) ? $this->public_intetface->delete_backup( sanitize_text_field( wp_unslash( $_POST['backup_id'] ) ), sanitize_text_field( wp_unslash( $_POST['force'] ) ) ) : false;
		return $ret;
	}

	/**
	 * Delete backup array.
	 *
	 * @uses MainWP_Child_WPvivid_BackupRestore::$public_intetface::delete_backup_array()
	 *
	 * @return mixed $ret Returned response.
	 */
	public function delete_backup_array() {

		/** @global object $wpvivid_plugin WPVivid Class. */
		global $wpvivid_plugin;

		$wpvivid_plugin->ajax_check_security();
		$ret = isset( $_POST['backup_id'] ) ? $this->public_intetface->delete_backup_array( sanitize_text_field( wp_unslash( $_POST['backup_id'] ) ) ) : false;
		return $ret;
	}

	/**
	 * Set security lock.
	 *
	 * @uses MainWP_Child_WPvivid_BackupRestore::$public_intetface::set_security_lock()
	 *
	 * @return mixed $ret Returned response.
	 */
	public function set_security_lock() {

		/** @global object $wpvivid_plugin WPVivid Class. */
		global $wpvivid_plugin;

		$wpvivid_plugin->ajax_check_security();
		$ret = isset( $_POST['backup_id'] ) && isset( $_POST['lock'] ) ? $this->public_intetface->set_security_lock( $_POST['backup_id'], $_POST['lock'] ) : false;
		return $ret;
	}

	/**
	 * View log file.
	 *
	 * @uses MainWP_Child_WPvivid_BackupRestore::$public_intetface::view_log()
	 *
	 * @return mixed $ret Returned response.
	 */
	public function view_log() {

		/** @global object $wpvivid_plugin WPVivid Class. */
		global $wpvivid_plugin;

		$wpvivid_plugin->ajax_check_security();
		$ret = isset( $_POST['id'] ) ? $this->public_intetface->view_log( sanitize_text_field( wp_unslash( $_POST['id'] ) ) ) : false;
		return $ret;
	}

	/**
	 * Read the last backup log entry.
	 *
	 * @uses MainWP_Child_WPvivid_BackupRestore::$public_intetface::read_last_backup_log()
	 *
	 * @return mixed $ret Returned response.
	 */
	public function read_last_backup_log() {

		/** @global object $wpvivid_plugin WPVivid Class. */
		global $wpvivid_plugin;

		$wpvivid_plugin->ajax_check_security();
		$ret = isset( $_POST['log_file_name'] ) ? $this->public_intetface->read_last_backup_log( sanitize_text_field( wp_unslash( $_POST['log_file_name'] ) ) ) : false;
		return $ret;
	}

	/**
	 * View backup task log.
	 *
	 * @uses MainWP_Child_WPvivid_BackupRestore::$public_intetface::view_backup_task_log()
	 *
	 * @return mixed $ret Returned response.
	 */
	public function view_backup_task_log() {

		/** @global object $wpvivid_plugin WPVivid Class. */
		global $wpvivid_plugin;

		$wpvivid_plugin->ajax_check_security();
		$ret = isset( $_POST['id'] ) ? $this->public_intetface->view_backup_task_log( sanitize_text_field( wp_unslash( $_POST['id'] ) ) ) : false;
		return $ret;
	}

	/**
	 * Cancel backup schedule.
	 *
	 * @uses MainWP_Child_WPvivid_BackupRestore::$public_intetface::backup_cancel()
	 *
	 * @return mixed $ret Returned response.
	 */
	public function backup_cancel() {

		/** @global object $wpvivid_plugin WPVivid Class. */
		global $wpvivid_plugin;

		$wpvivid_plugin->ajax_check_security();
		$ret = isset( $_POST['task_id'] ) ? $this->public_intetface->backup_cancel( sanitize_text_field( wp_unslash( $_POST['task_id'] ) ) ) : false;
		return $ret;
	}

	/**
	 * Initiate download page.
	 *
	 * @uses MainWP_Child_WPvivid_BackupRestore::$public_intetface::init_download_page()
	 *
	 * @return mixed $ret Returned response.
	 */
	public function init_download_page() {

		/** @global object $wpvivid_plugin WPVivid Class. */
		global $wpvivid_plugin;

		$wpvivid_plugin->ajax_check_security();
		$ret = isset( $_POST['backup_id'] ) ? $this->public_intetface->init_download_page( sanitize_text_field( wp_unslash( $_POST['backup_id'] ) ) ) : false;
		return $ret;
	}

	/**
	 * Prepare backup download.
	 *
	 * @uses MainWP_Child_WPvivid_BackupRestore::$public_intetface::prepare_download_backup()
	 *
	 * @return mixed $ret Returned response.
	 */
	public function prepare_download_backup() {

		/** @global object $wpvivid_plugin WPVivid Class. */
		global $wpvivid_plugin;

		$wpvivid_plugin->ajax_check_security();
		$ret = isset( $_POST['backup_id'] ) && isset( $_POST['file_name'] ) ? $this->public_intetface->prepare_download_backup( sanitize_text_field( wp_unslash( $_POST['backup_id'] ) ), sanitize_text_field( wp_unslash( $_POST['file_name'] ) ) ) : false;
		return $ret;
	}

	/**
	 * Get download task.
	 *
	 * @uses MainWP_Child_WPvivid_BackupRestore::$public_intetface::get_download_task()
	 *
	 * @return mixed $ret Returned response.
	 */
	public function get_download_task() {

		/** @global object $wpvivid_plugin WPVivid Class. */
		global $wpvivid_plugin;

		$wpvivid_plugin->ajax_check_security();
		$ret = isset( $_POST['backup_id'] ) ? $this->public_intetface->get_download_task( sanitize_text_field( wp_unslash( $_POST['backup_id'] ) ) ) : false;
		return $ret;
	}

	/**
	 * Download Backup.
	 *
	 * @uses MainWP_Child_WPvivid_BackupRestore::$public_intetface::download_backup()
	 *
	 * @return mixed $ret Returned response.
	 */
	public function download_backup() {

		/** @global object $wpvivid_plugin WPVivid Class. */
		global $wpvivid_plugin;

		$wpvivid_plugin->ajax_check_security();
		$ret = isset( $_POST['backup_id'] ) && isset( $_POST['file_name'] ) ? $this->public_intetface->download_backup( sanitize_text_field( wp_unslash( $_POST['backup_id'] ) ), sanitize_text_field( wp_unslash( $_POST['file_name'] ) ) ) : false;
		return $ret;
	}

	/**
	 * Set general settings.
	 *
	 * @uses MainWP_Child_WPvivid_BackupRestore::$public_intetface::set_general_settings()
	 *
	 * @return mixed $ret Returned response.
	 */
	public function set_general_setting() {

		/** @global object $wpvivid_plugin WPVivid Class. */
		global $wpvivid_plugin;

		$wpvivid_plugin->ajax_check_security();
		$ret = isset( $_POST['setting'] ) ? $this->public_intetface->set_general_setting( wp_unslash( $_POST['setting'] ) ) : false;
		return $ret;
	}

	/**
	 * Set backup schedule.
	 *
	 * @uses MainWP_Child_WPvivid_BackupRestore::$public_intetface::set_schedule()
	 *
	 * @return mixed $ret Returned response.
	 */
	public function set_schedule() {

		/** @global object $wpvivid_plugin WPVivid Class. */
		global $wpvivid_plugin;

		$wpvivid_plugin->ajax_check_security();
		$ret = isset( $_POST['schedule'] ) ? $this->public_intetface->set_schedule( sanitize_text_field( wp_unslash( $_POST['schedule'] ) ) ) : false;
		return $ret;
	}

	/**
	 * Set remote destination.
	 *
	 * @uses MainWP_Child_WPvivid_BackupRestore::$public_intetface::set_remote()
	 *
	 * @return mixed $ret Returned response.
	 */
	public function set_remote() {

		/** @global object $wpvivid_plugin WPVivid Class. */
		global $wpvivid_plugin;

		$wpvivid_plugin->ajax_check_security();
		$ret = isset( $_POST['remote'] ) ? $this->public_intetface->set_remote( sanitize_text_field( wp_unslash( $_POST['remote'] ) ) ) : false;
		return $ret;
	}
}
