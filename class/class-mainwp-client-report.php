<?php
/**
 * MainWP Client Report
 *
 * MainWP Client Reports extension handler.
 *
 * @link https://mainwp.com/extension/client-reports/
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

/**
 * Class MainWP_Client_Report
 *
 * MainWP Client Reports extension handler.
 *
 * @uses \MainWP\Child\MainWP_Client_Report_Base
 */
class MainWP_Client_Report extends MainWP_Client_Report_Base {

	/**
	 * Public static variable to hold the single instance of the class.
	 *
	 * @var mixed Default null
	 */
	public static $instance = null;

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
	 * Create a public static instance of MainWP_Client_Report|MainWP_Client_Report_Base|null.
	 *
	 * @return MainWP_Client_Report|MainWP_Client_Report_Base|null
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * MainWP_Client_Report constructor.
	 *
	 * Run any time class is called.
	 */
	public function __construct() {
		add_filter( 'wp_mainwp_stream_current_agent', array( $this, 'current_agent' ), 10, 1 );
	}

	/**
	 * Initiate Client report
	 */
	public function init() {
		add_filter( 'mainwp_site_sync_others_data', array( $this, 'sync_others_data' ), 10, 2 );
		add_action( 'mainwp_child_log', array( self::get_class_name(), 'do_reports_log' ) );
	}

	/**
	 * Get current user agent.
	 *
	 * @param string $agent User agent.
	 * @return string $agent Current user agent.
	 */
	public function current_agent( $agent ) {
		if ( isset( $_POST['function'] ) && isset( $_POST['mainwpsignature'] ) ) {
			$agent = '';
		}
		return $agent;
	}

	/**
	 * Sync others data.
	 *
	 * @param array $information Holder for returned data.
	 * @param array $data Data to sync.
	 * @return array $information Synced data.
	 */
	public function sync_others_data( $information, $data = array() ) {
		if ( isset( $data['syncClientReportData'] ) && $data['syncClientReportData'] ) {
			$creport_sync_data = array();
			$firsttime         = get_option( 'mainwp_creport_first_time_activated' );
			if ( false !== $firsttime ) {
				$creport_sync_data['firsttime_activated'] = $firsttime;
			}
			if ( ! empty( $creport_sync_data ) ) {
				$information['syncClientReportData'] = $creport_sync_data;
			}
		}
		return $information;
	}

	/**
	 * Create reports log file.
	 *
	 * @param string $ext File extension.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::do_reports_log()
	 * @uses \MainWP\Child\MainWP_Child_Back_Up_WordPress::do_reports_log()
	 * @uses \MainWP\Child\MainWP_Child_Back_WP_Up::do_reports_log()
	 * @uses \MainWP\Child\MainWP_Child_Wordfence::do_reports_log()
	 * @uses \MainWP\Child\MainWP_Child_Timecapsule::do_reports_log()
	 */
	public static function do_reports_log( $ext = '' ) {
		switch ( $ext ) {
			case 'backupbuddy':
				MainWP_Child_Back_Up_Buddy::instance()->do_reports_log( $ext );
				break;
			case 'backupwordpress':
				MainWP_Child_Back_Up_WordPress::instance()->do_reports_log( $ext );
				break;
			case 'backwpup':
				MainWP_Child_Back_WP_Up::instance()->do_reports_log( $ext );
				break;
			case 'wordfence':
				MainWP_Child_Wordfence::instance()->do_reports_log( $ext );
				break;
			case 'wptimecapsule':
				MainWP_Child_Timecapsule::instance()->do_reports_log( $ext );
				break;
		}
	}

	/**
	 * Actions: save_sucuri_stream, save_backup_stream, get_stream, set_showhide.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::write()
	 */
	public function action() {

		$information = array();

		if ( ! function_exists( '\wp_mainwp_stream_get_instance' ) ) {
			$information['error'] = esc_html__( 'No MainWP Child Reports plugin installed.', 'mainwp-child' );
			MainWP_Helper::write( $information );
		}

		try {
			if ( isset( $_POST['mwp_action'] ) ) {
				$mwp_action = ! empty( $_POST['mwp_action'] ) ? sanitize_text_field( wp_unslash( $_POST['mwp_action'] ) ) : '';
				switch ( $mwp_action ) {
					case 'save_sucuri_stream':
						$information = $this->save_sucuri_stream();
						break;
					case 'save_backup_stream':
						$information = $this->save_backup_stream();
						break;
					case 'get_stream':
						$information = $this->get_stream();
						break;
					case 'set_showhide':
						$information = $this->set_showhide();
						break;
				}
			}
		} catch ( \Exception $e ) {
			$information['error'] = $e->getMessage();
		}

		MainWP_Helper::write( $information );
	}

	/**
	 * Save sucuri stream.
	 *
	 * @return bool true|false.
	 */
	public function save_sucuri_stream() {
		$scan_data   = isset( $_POST['scan_data'] ) ? wp_unslash( $_POST['scan_data'] ) : '';
		$scan_time   = isset( $_POST['scan_time'] ) ? sanitize_text_field( wp_unslash( $_POST['scan_time'] ) ) : 0;
		$scan_status = isset( $_POST['scan_status'] ) ? sanitize_text_field( wp_unslash( $_POST['scan_status'] ) ) : '';
		$result      = isset( $_POST['result'] ) ? wp_unslash( $_POST['result'] ) : '';
		do_action( 'mainwp_reports_sucuri_scan', $result, $scan_status, $scan_data, $scan_time );
		return true;
	}

	/**
	 * Save backup stream.
	 *
	 * @return bool true|false.
	 */
	public function save_backup_stream() {
		$destination = isset( $_POST['destination'] ) ? wp_unslash( $_POST['destination'] ) : '';
		$message     = isset( $_POST['message'] ) ? wp_unslash( $_POST['message'] ) : '';
		$size        = isset( $_POST['size'] ) ? wp_unslash( $_POST['size'] ) : '';
		$status      = isset( $_POST['status'] ) ? wp_unslash( $_POST['status'] ) : '';
		$type        = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';

		do_action( 'mainwp_backup', $destination, $message, $size, $status, $type );
		return true;
	}

	/**
	 * Get stream.
	 *
	 * @return array $information Stream array.
	 */
	public function get_stream() {

		$sections = isset( $_POST['sections'] ) ? json_decode( base64_decode( wp_unslash( $_POST['sections'] ) ), true ) : array(); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
		if ( ! is_array( $sections ) ) {
			$sections = array();
		}

		$other_tokens = isset( $_POST['other_tokens'] ) ? json_decode( base64_decode( wp_unslash( $_POST['other_tokens'] ) ), true ) : array(); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
		if ( ! is_array( $other_tokens ) ) {
			$other_tokens = array();
		}

		unset( $_POST['sections'] );
		unset( $_POST['other_tokens'] );

		$args    = $this->get_stream_get_params( $other_tokens, $sections );
		$records = \wp_mainwp_stream_get_instance()->db->query( $args );

		if ( ! is_array( $records ) ) {
			$records = array();
		}

		// fix invalid data, or skip records!
		$skip_records = array();

		$other_tokens_data = $this->get_stream_others_tokens( $records, $other_tokens, $skip_records );
		$sections_data     = $this->get_stream_sections_data( $records, $sections, $skip_records );

		$information = array(
			'other_tokens_data' => $other_tokens_data,
			'sections_data'     => $sections_data,
		);
		return $information;
	}

	/**
	 * Set Branding Show/Hide.
	 *
	 * @return array $information Results array.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Branding::save_branding_options()
	 */
	public function set_showhide() {
		$hide = isset( $_POST['showhide'] ) && ( 'hide' === $_POST['showhide'] ) ? 'hide' : '';
		MainWP_Child_Branding::instance()->save_branding_options( 'hide_child_reports', $hide );
		$information['result'] = 'SUCCESS';

		return $information;
	}

	/**
	 * Initiate Client Reports.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Branding::get_branding_options()
	 * @uses \MainWP\Child\MainWP_Child_Branding::is_branding()
	 */
	public function creport_init() {

		$branding_opts = MainWP_Child_Branding::instance()->get_branding_options();
		$hide_nag      = false;

		if ( isset( $branding_opts['hide_child_reports'] ) && 'hide' == $branding_opts['hide_child_reports'] ) {
			add_filter( 'all_plugins', array( $this, 'creport_branding_plugin' ) );
			add_action( 'admin_menu', array( $this, 'creport_remove_menu' ) );
			$hide_nag = true;
		}

		if ( ! $hide_nag ) {
			// check child branding settings!
			if ( MainWP_Child_Branding::instance()->is_branding() ) {
				$hide_nag = true;
			}
		}

		if ( $hide_nag ) {
			add_filter( 'site_transient_update_plugins', array( &$this, 'remove_update_nag' ) );
			add_filter( 'mainwp_child_hide_update_notice', array( &$this, 'hide_update_notice' ) );
		}
	}

	/**
	 * Hide the MainWP Child Reports plugin update notice.
	 *
	 * @param array $slugs An array containing slugs to hide.
	 *
	 * @return array Updated array containing slugs to hide.
	 */
	public function hide_update_notice( $slugs ) {

		$slugs[] = 'mainwp-child-reports/mainwp-child-reports.php';
		return $slugs;
	}

	/**
	 * Remove update nag.
	 *
	 * @param string $value Value to remove.
	 *
	 * @return string Response.
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

		if ( isset( $value->response['mainwp-child-reports/mainwp-child-reports.php'] ) ) {
			unset( $value->response['mainwp-child-reports/mainwp-child-reports.php'] );
		}

		return $value;
	}

	/**
	 * Client Reports Branding plugin.
	 *
	 * @param array $plugins Plugins array.
	 * @return array Plugins array.
	 */
	public function creport_branding_plugin( $plugins ) {
		foreach ( $plugins as $key => $value ) {
			$plugin_slug = basename( $key, '.php' );
			if ( 'mainwp-child-reports' === $plugin_slug ) {
				unset( $plugins[ $key ] );
			}
		}
		return $plugins;
	}

	/**
	 * Client Remove Menu.
	 */
	public function creport_remove_menu() {
		remove_menu_page( 'mainwp_wp_stream' );
	}
}
