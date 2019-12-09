<?php

/*
 *
 * Credits
 *
 * Plugin-Name: WP Time Capsule
 * Plugin URI: https://wptimecapsule.com
 * Author: Revmakx
 * Author URI: http://www.revmakx.com
 *
 * The code is used for the MainWP Time Capsule Extension
 * Extension URL: https://mainwp.com/extension/time-capsule/
 *
*/

class MainWP_Child_Timecapsule {
    public static $instance = null;
    public $is_plugin_installed = false;

    static function Instance() {
        if ( null === MainWP_Child_Timecapsule::$instance ) {
            MainWP_Child_Timecapsule::$instance = new MainWP_Child_Timecapsule();
        }
        return MainWP_Child_Timecapsule::$instance;
    }

    public function __construct() {
        require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
		if ( is_plugin_active( 'wp-time-capsule/wp-time-capsule.php' ) && defined('WPTC_CLASSES_DIR')) {
            $this->is_plugin_installed = true;
		}

        if (!$this->is_plugin_installed)
            return;

        add_filter( 'mainwp-site-sync-others-data', array( $this, 'syncOthersData' ), 10, 2 );

    }


	public function init() {
         if (!$this->is_plugin_installed)
            return;

		if ( get_option( 'mainwp_time_capsule_ext_enabled' ) !== 'Y' )
            return;

        add_action( 'mainwp_child_site_stats', array( $this, 'do_site_stats' ) );

		if ( get_option( 'mainwp_time_capsule_hide_plugin' ) === 'hide' ) {
			add_filter( 'all_plugins', array( $this, 'all_plugins' ) );
			add_action( 'admin_menu', array( $this, 'remove_menu' ) );
			add_filter( 'site_transient_update_plugins', array( &$this, 'remove_update_nag' ) );
            add_filter( 'mainwp_child_hide_update_notice', array( &$this, 'hide_update_notice' ) );
		}
	}


	public function action() {
            if (!$this->is_plugin_installed) {
                 MainWP_Helper::write( array('error' => 'Please install WP Time Capsule plugin on child website') );
            }

            try {
                $this->require_files();
            } catch ( Exception $e) {
                $error = $e->getMessage();
                MainWP_Helper::write( array('error' => $error) );
            }

            $information = array();

            $options_helper = new Wptc_Options_Helper();
            $options = WPTC_Factory::get('config');
            $is_user_logged_in  = $options->get_option('is_user_logged_in');
            $privileges_wptc = $options_helper->get_unserialized_privileges();



            if ( isset( $_POST['mwp_action'] ) ) {

                if ((
                            $_POST['mwp_action'] == 'save_settings' ||
                            $_POST['mwp_action'] == 'get_staging_details_wptc' ||
                            $_POST['mwp_action'] == 'progress_wptc'
                    ) && (!$is_user_logged_in || !$privileges_wptc )
                    ) {
                        MainWP_Helper::write( array('error' => 'You are not login to your WP Time Capsule account.') );
                }

                switch ( $_POST['mwp_action'] ) {
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


    public function require_files() {
            if (! class_exists('WPTC_Base_Factory') && defined('WPTC_PLUGIN_DIR') ) {
                if ( MainWP_Helper::check_files_exists(WPTC_PLUGIN_DIR . 'Base/Factory.php') ) {
                    include_once WPTC_PLUGIN_DIR.'Base/Factory.php';
                }
            }
            if ( ! class_exists('Wptc_Options_Helper') && defined('WPTC_PLUGIN_DIR') ) {
                if ( MainWP_Helper::check_files_exists(WPTC_PLUGIN_DIR . 'Views/wptc-options-helper.php') ) {
                    include_once WPTC_PLUGIN_DIR . 'Views/wptc-options-helper.php';
                }
            }
	}

    function set_showhide() {
		$hide = isset( $_POST['showhide'] ) && ( 'hide' === $_POST['showhide'] ) ? 'hide' : '';
		MainWP_Helper::update_option( 'mainwp_time_capsule_hide_plugin', $hide, 'yes' );
		$information['result'] = 'SUCCESS';
		return $information;
	}

    // ok
	public function syncOthersData( $information, $data = array() ) {
        if ( isset( $data['syncWPTimeCapsule'] ) && $data['syncWPTimeCapsule'] ) {
            $information['syncWPTimeCapsule'] = $this->get_sync_data();
            if (get_option( 'mainwp_time_capsule_ext_enabled' ) !== 'Y')
                MainWP_Helper::update_option( 'mainwp_time_capsule_ext_enabled', 'Y', 'yes' );
        }
		return $information;
	}

    // ok
    public function get_sync_data() {
        try {
            $this->require_files();
            MainWP_Helper::check_classes_exists(array('Wptc_Options_Helper', 'WPTC_Base_Factory', 'WPTC_Factory'));

            $config = WPTC_Factory::get('config');
            MainWP_Helper::check_methods($config, 'get_option');

            $main_account_email_var = $config->get_option('main_account_email');
            $last_backup_time = $config->get_option('last_backup_time');
            $wptc_settings = WPTC_Base_Factory::get('Wptc_Settings');

            $options_helper = new Wptc_Options_Helper();

            MainWP_Helper::check_methods($options_helper, array( 'get_plan_interval_from_subs_info', 'get_is_user_logged_in'));
            MainWP_Helper::check_methods($wptc_settings, array( 'get_connected_cloud_info'));

            $all_backups = $this->getBackups();
            $backups_count = 0;
            if (is_array($all_backups)) {
                $formatted_backups = array();
               foreach ($all_backups as $key => $value) {
                    $value_array = (array) $value;
                    $formatted_backups[$value_array['backupID']][] = $value_array;
                }
                $backups_count = count($formatted_backups);
            }

            $return = array(
                    'main_account_email' => $main_account_email_var,
                    'signed_in_repos' =>   $wptc_settings->get_connected_cloud_info(),
                    'plan_name' => $options_helper->get_plan_interval_from_subs_info(),
                    'plan_interval' => $options_helper->get_plan_interval_from_subs_info(),
                    'lastbackup_time' => !empty($last_backup_time) ? $last_backup_time : 0,
                    'is_user_logged_in' => $options_helper->get_is_user_logged_in(),
                    'backups_count' => $backups_count
            );
            return $return;
        } catch ( Exception $e) {
            // do not exit here
        }
        return false;
    }

    protected function getBackups( $last_time = false ) {
        if (empty($last_time)) {
            $last_time = strtotime(date('Y-m-d', strtotime(date('Y-m-01'))));
        }
        global $wpdb;
        $all_backups = $wpdb->get_results(
            $wpdb->prepare("
            SELECT *
            FROM {$wpdb->base_prefix}wptc_processed_files
            WHERE backupID > %s ", $last_time)
        );

		return $all_backups;
	}

    public function get_tables() {
        $category = $_POST['category'];
        $exclude_class_obj = new Wptc_ExcludeOption($category);
        $exclude_class_obj->get_tables();
        die();
	}

    public function exclude_file_list(){
        if (!isset($_POST['data'])) {
			wptc_die_with_json_encode( array('status' => 'no data found') );
		}
        $category = $_POST['category'];
        $exclude_class_obj = new Wptc_ExcludeOption($category);
        $exclude_class_obj->exclude_file_list($_POST['data']);
        die();
	}

    function progress_wptc() {

        $config = WPTC_Factory::get('config');
        global $wpdb;
        if (!$config->get_option('in_progress')) {
            spawn_cron();
        }

        $processed_files = WPTC_Factory::get('processed-files');

        $return_array = array();
        $return_array['stored_backups'] = $processed_files->get_stored_backups();
        $return_array['backup_progress'] = array();
        $return_array['starting_first_backup'] = $config->get_option('starting_first_backup');
        $return_array['meta_data_backup_process'] = $config->get_option('meta_data_backup_process');
        $return_array['backup_before_update_progress'] = $config->get_option('backup_before_update_progress');
        $return_array['is_staging_running'] = apply_filters('is_any_staging_process_going_on', '');
        $cron_status = $config->get_option('wptc_own_cron_status');

        if (!empty($cron_status)) {
            $return_array['wptc_own_cron_status'] = unserialize($cron_status);
            $return_array['wptc_own_cron_status_notified'] = (int) $config->get_option('wptc_own_cron_status_notified');
        }

        $start_backups_failed_server = $config->get_option('start_backups_failed_server');
        if (!empty($start_backups_failed_server)) {
            $return_array['start_backups_failed_server'] = unserialize($start_backups_failed_server);
            $config->set_option('start_backups_failed_server', false);
        }

        $processed_files->get_current_backup_progress($return_array);

        $return_array['user_came_from_existing_ver'] = (int) $config->get_option('user_came_from_existing_ver');
        $return_array['show_user_php_error'] = $config->get_option('show_user_php_error');
        $return_array['bbu_setting_status'] = apply_filters('get_backup_before_update_setting_wptc', '');
        $return_array['bbu_note_view'] = apply_filters('get_bbu_note_view', '');
        $return_array['staging_status'] = apply_filters('staging_status_wptc', '');

        $processed_files = WPTC_Factory::get('processed-files');
        $last_backup_time = $config->get_option('last_backup_time');

        if (!empty($last_backup_time)) {
            $user_time = $config->cnvt_UTC_to_usrTime($last_backup_time);
            $processed_files->modify_schedule_backup_time($user_time);
            $formatted_date = date("M d @ g:i a", $user_time);
            $return_array['last_backup_time'] = $formatted_date;
        } else {
            $return_array['last_backup_time']  = 'No Backup Taken';
        }

        return array( 'result' => $return_array );

    }

    function wptc_cron_status(){
        $config = WPTC_Factory::get('config');
        wptc_own_cron_status();
        $status = array();
        $cron_status = $config->get_option('wptc_own_cron_status');
        if (!empty($cron_status)) {
            $cron_status = unserialize($cron_status);

            if ($cron_status['status'] == 'success') {
                $status['status'] = 'success';
            } else {
                $status['status'] = 'failed';
                $status['status_code'] = $cron_status['statusCode'];
                $status['err_msg'] = $cron_status['body'];
                $status['cron_url'] = $cron_status['cron_url'];
                $status['ips'] = $cron_status['ips'];
            }
            return array('result' => $status);
        }
        return false;
    }

    function get_this_backups_html() {
        $this_backup_ids = $_POST['this_backup_ids'];
        $specific_dir = $_POST['specific_dir'];
        $type = $_POST['type'];
        $treeRecursiveCount = $_POST['treeRecursiveCount'];
        $processed_files = WPTC_Factory::get('processed-files');

        $result = $processed_files->get_this_backups_html($this_backup_ids, $specific_dir, $type, $treeRecursiveCount);
        return array( 'result' => $result );
    }


function start_restore_tc_callback_wptc() {

	if (apply_filters('is_restore_to_staging_wptc', '')) {
		$request = apply_filters('get_restore_to_staging_request_wptc', '');
	} else {
		$request = $_POST['data'];
	}

    include_once ( WPTC_CLASSES_DIR . 'class-prepare-restore-bridge.php' );

	new WPTC_Prepare_Restore_Bridge($request);
}

function get_sibling_files_callback_wptc() {
    //note that we are getting the ajax function data via $_POST.
	$file_name = $_POST['data']['file_name'];
	$file_name = wp_normalize_path($file_name);
	$backup_id = $_POST['data']['backup_id'];
	$recursive_count = $_POST['data']['recursive_count'];
	// //getting the backups

    $processed_files = WPTC_Factory::get('processed-files');
	echo $processed_files->get_this_backups_html($backup_id, $file_name, $type = 'sibling', (int) $recursive_count);
    die();
}

    function send_issue_report() {
        WPTC_Base_Factory::get('Wptc_App_Functions')->send_report();
        die();
    }


    function get_logs_rows() {
        $result = $this->prepare_items();
        $result['display_rows'] = base64_encode(serialize($this->get_display_rows($result['items'])));
        return $result;
    }

    function prepare_items() {
        global $wpdb;

		if (isset($_POST['type'])) {
			$type = $_POST['type'];
			switch ($type) {
			case 'backups':
				$query = "SELECT * FROM " . $wpdb->base_prefix . "wptc_activity_log WHERE type LIKE '%backup%' AND show_user = 1 GROUP BY action_id";
				break;
			case 'restores':
				$query = "SELECT * FROM " . $wpdb->base_prefix . "wptc_activity_log WHERE type LIKE 'restore%' GROUP BY action_id";
				break;
            case 'staging':
				$query = "SELECT * FROM " . $wpdb->base_prefix . "wptc_activity_log WHERE type LIKE 'staging%' GROUP BY action_id";
				break;
            case 'backup_and_update':
				$query = "SELECT * FROM " . $wpdb->base_prefix . "wptc_activity_log WHERE type LIKE 'backup_and_update%' GROUP BY action_id";
				break;
            case 'auto_update':
				$query = "SELECT * FROM " . $wpdb->base_prefix . "wptc_activity_log WHERE type LIKE 'auto_update%' GROUP BY action_id";
				break;
			case 'others':
				$query = "SELECT * FROM " . $wpdb->base_prefix . "wptc_activity_log WHERE type NOT LIKE 'restore%' AND type NOT LIKE 'backup%' AND show_user = 1";
				break;
			default:
				$query = "SELECT * FROM " . $wpdb->base_prefix . "wptc_activity_log GROUP BY action_id UNION SELECT * FROM " . $wpdb->base_prefix . "wptc_activity_log WHERE action_id='' AND show_user = 1";
				break;
			}
		} else {
			$query = "SELECT * FROM " . $wpdb->base_prefix . "wptc_activity_log WHERE show_user = 1   GROUP BY action_id ";
		}
		/* -- Preparing your query -- */

		/* -- Ordering parameters -- */
		//Parameters that are going to be used to order the result
		$orderby = !empty($_POST["orderby"]) ? mysql_real_escape_string($_POST["orderby"]) : 'id';
		$order = !empty($_POST["order"]) ? mysql_real_escape_string($_POST["order"]) : 'DESC';
		if (!empty($orderby) & !empty($order)) {$query .= ' ORDER BY ' . $orderby . ' ' . $order;}

		/* -- Pagination parameters -- */
		//Number of elements in your table?
		$totalitems = $wpdb->query($query); //return the total number of affected rows
		//How many to display per page?
		$perpage = 20;
		//Which page is this?
		$paged = !empty($_POST["paged"]) ? $_POST["paged"] : '';
        if (empty($paged) || !is_numeric($paged) || $paged <= 0) {$paged = 1;} //Page Number
		//How many pages do we have in total?
		$totalpages = ceil($totalitems / $perpage); //Total number of pages
		//adjust the query to take pagination into account
		if (!empty($paged) && !empty($perpage)) {
			$offset = ($paged - 1) * $perpage;
			$query .= ' LIMIT ' . (int) $offset . ',' . (int) $perpage;
		}

        return array(   'items' => $wpdb->get_results($query) ,
                        'totalitems' => $totalitems,
                        'perpage' => $perpage
                );
    }


    function lazy_load_activity_log_wptc(){

        if (!isset($_POST['data'])) {
			return false;
		}

		$data = $_POST['data'];

		if (!isset($data['action_id']) || !isset($data['limit'])) {
			return false;
		}
        global $wpdb;

		$action_id     = $data['action_id'];
		$from_limit    = $data['limit'];
		$detailed      = '';
		$load_more     = false;
		$current_limit = WPTC_Factory::get('config')->get_option('activity_log_lazy_load_limit');
		$to_limit      = $from_limit + $current_limit;

		$sql = "SELECT * FROM " . $wpdb->base_prefix . "wptc_activity_log WHERE action_id=" . $action_id . ' AND show_user = 1 ORDER BY id DESC LIMIT '.$from_limit.' , '.$current_limit;

		$sub_records = $wpdb->get_results($sql);
		$row_count   = count($sub_records);

		if ($row_count == $current_limit) {
			$load_more = true;
		}

		$detailed = $this->get_activity_log($sub_records);

		if (isset($load_more) && $load_more) {
			$detailed .= '<tr><td></td><td><a style="cursor:pointer; position:relative" class="wptc_activity_log_load_more" action_id="'. esc_attr( $action_id ).'" limit="'. esc_attr( $to_limit ) .'">Load more</a></td><td></td></tr>';
		}

        return array( 'result' => $detailed);

		//die($detailed);
    }


    function get_display_rows($records) {
		global $wpdb;
		//Get the records registered in the prepare_items method
        if (!is_array($records))
            return '';

		$i=0;
		$limit = WPTC_Factory::get('config')->get_option('activity_log_lazy_load_limit');
		//Get the columns registered in the get_columns and get_sortable_columns methods
		// $columns = $this->get_columns();
		$timezone = WPTC_Factory::get('config')->get_option('wptc_timezone');
		if (count($records) > 0) {

			foreach ($records as $key => $rec) {
                $html = '';

				$more_logs = false;
				$load_more = false;
				if ($rec->action_id != '') {
					$sql = "SELECT * FROM " . $wpdb->base_prefix . "wptc_activity_log WHERE action_id=" . $rec->action_id . ' AND show_user = 1 ORDER BY id DESC LIMIT 0 , '.$limit;
					$sub_records = $wpdb->get_results($sql);
					$row_count = count($sub_records);
					if ($row_count == $limit) {
						$load_more = true;
					}

					if ($row_count > 0) {
						$more_logs = true;
						$detailed = '<table>';
						$detailed .= $this->get_activity_log($sub_records);
						if (isset($load_more) && $load_more) {
							$detailed .= '<tr><td></td><td><a style="cursor:pointer; position:relative" class="mainwp_wptc_activity_log_load_more" action_id="'.$rec->action_id.'" limit="'.$limit.'">Load more</a></td><td></td></tr>';
						}
						$detailed .= '</table>';

					}
				}
				//Open the line
				$html .= '<tr class="act-tr">';
				$Ldata = unserialize($rec->log_data);
				$user_time = WPTC_Factory::get('config')->cnvt_UTC_to_usrTime($Ldata['log_time']);
				WPTC_Factory::get('processed-files')->modify_schedule_backup_time($user_time);
				// $user_tz = new DateTime('@' . $Ldata['log_time'], new DateTimeZone(date_default_timezone_get()));
				// $user_tz->setTimeZone(new DateTimeZone($timezone));
				// $user_tz_now = $user_tz->format("M d, Y @ g:i:s a");
				$user_tz_now = date("M d, Y @ g:i:s a", $user_time);
				$msg = '';
				if (!(strpos($rec->type, 'backup') === false)) {
					//Backup process
					$msg = 'Backup Process';
				} else if (!(strpos($rec->type, 'restore') === false)) {
					//Restore Process
					$msg = 'Restore Process';
				} else if (!(strpos($rec->type, 'staging') === false)) {
					//Restore Process
					$msg = 'Staging Process';
				} else {
					if ($row_count < 2) {
						$more_logs = false;
					}
					$msg = $Ldata['msg'];
				}
				$html .= '<td class="wptc-act-td">' . $user_tz_now . '</td><td class="wptc-act-td">' . $msg;
				if ($more_logs) {
					$html .= "&nbsp&nbsp&nbsp&nbsp<a class='wptc-show-more' action_id='" . round($rec->action_id) . "'>View details</a></td>";
				} else {
					$html .= "</td>";
				}
				$html .= '<td class="wptc-act-td"><a class="report_issue_wptc" id="' . $rec->id . '" href="#">Send report to plugin developer</a></td>';
				if ($more_logs) {

					$html .= "</tr><tr id='" . round($rec->action_id) . "' class='wptc-more-logs'><td colspan=3>" . $detailed . "</td>";
				} else {
					$html .= "</td>";
				}
				//Close the line
				$html .= '</tr>';

                $display_rows[$key] = $html;
			}

		}
        return $display_rows;
	}


    function get_activity_log($sub_records){
		if (count($sub_records) < 1) {
			return false;
		}
		$detailed = '';
		$timezone = WPTC_Factory::get('config')->get_option('wptc_timezone');
		foreach ($sub_records as $srec) {
			$Moredata = unserialize($srec->log_data);
			$user_tmz = new DateTime('@' . $Moredata['log_time'], new DateTimeZone(date_default_timezone_get()));
			$user_tmz->setTimeZone(new DateTimeZone($timezone));
			$user_tmz_now = $user_tmz->format("M d @ g:i:s a");
			$detailed .= '<tr><td>' . $user_tmz_now . '</td><td>' . $Moredata['msg'] . '</td><td></td></tr>';
		}
		return $detailed;
	}

    function clear_wptc_logs() {
        global $wpdb;
        if ($wpdb->query("TRUNCATE TABLE `" . $wpdb->base_prefix . "wptc_activity_log`")) {
            $result = 'yes';
        } else {
            $result = 'no';
        }
        return array('result' => $result);
    }

    function stop_fresh_backup_tc_callback_wptc() {
        //for backup during update
        $deactivated_plugin = null;
        $backup = new WPTC_BackupController();
        $backup->stop($deactivated_plugin);
        return array('result' => 'ok');
    }


    function get_root_files() {
        $category = $_POST['category'];
        $exclude_class_obj = new Wptc_ExcludeOption($category);
        $exclude_class_obj->get_root_files();
        die();
	}


    public function exclude_table_list(){
        if (!isset($_POST['data'])) {
			wptc_die_with_json_encode( array('status' => 'no data found') );
		}
        $category = $_POST['data']['category'];
        $exclude_class_obj = new Wptc_ExcludeOption($category);
        $exclude_class_obj->exclude_table_list($_POST['data']);
        die();
    }

    function do_site_stats() {
        if (has_action('mainwp_child_reports_log')) {
            do_action( 'mainwp_child_reports_log', 'wptimecapsule');
        } else {
            $this->do_reports_log('wptimecapsule');
        }
    }

    // ok
    public function do_reports_log($ext = '') {

        if ( $ext !== 'wptimecapsule' ) return;

        if (!$this->is_plugin_installed)
            return;

        try {
             MainWP_Helper::check_classes_exists(array( 'WPTC_Factory'));

            $config = WPTC_Factory::get('config');

            MainWP_Helper::check_methods($config, 'get_option');

            $backup_time = $config->get_option('last_backup_time');

            if (!empty($backup_time)) {
                MainWP_Helper::update_lasttime_backup( 'wptimecapsule', $backup_time ); // to support backup before update feature
            }

            $last_time = time() - 24 * 7 * 2 * 60 * 60; // 2 weeks ago
            $lasttime_logged = MainWP_Helper::get_lasttime_backup('wptimecapsule');
            if (empty($lasttime_logged))
                $last_time = time() - 24 * 7 * 8 * 60 * 60; // 8 weeks ago

            $all_last_backups = $this->getBackups( $last_time );

            if (is_array($all_last_backups)) {
                $formatted_backups = array();
                foreach ($all_last_backups as $key => $value) {
                    $value_array = (array) $value;
                    $formatted_backups[$value_array['backupID']][] = $value_array;
                }
                $message = 'WP Time Capsule backup finished';
                $backup_type = 'WP Time Capsule backup';
                if (count($formatted_backups) > 0) {
                    foreach($formatted_backups as $key => $value) {
                        $backup_time = $key;
                        do_action( 'mainwp_reports_wptimecapsule_backup', $message, $backup_type, $backup_time );
                    }
                }
            }


        } catch(Exception $e) {

        }
    }

    public function include_table_list(){
        if (!isset($_POST['data'])) {
			wptc_die_with_json_encode( array('status' => 'no data found') );
		}
        $category = $_POST['data']['category'];
        $exclude_class_obj = new Wptc_ExcludeOption($category);
        $exclude_class_obj->include_table_list($_POST['data']);
        die();
	}

    public function include_table_structure_only(){

        if (!isset($_POST['data'])) {
			wptc_die_with_json_encode( array('status' => 'no data found') );
		}

        $category = $_POST['data']['category'];
        $exclude_class_obj = new Wptc_ExcludeOption($category);
        $exclude_class_obj->include_table_structure_only($_POST['data']);
        die();
	}

    public function include_file_list(){

        if (!isset($_POST['data'])) {
			wptc_die_with_json_encode( array('status' => 'no data found') );
		}
        $category = $_POST['category'];
        $exclude_class_obj = new Wptc_ExcludeOption($category);
        $exclude_class_obj->include_file_list($_POST['data']);
        die();
	}

    public function get_files_by_key() {
        $key = $_POST['key'];
        $category = $_POST['category'];
        $exclude_class_obj = new Wptc_ExcludeOption($category);
        $exclude_class_obj->get_files_by_key($key);
        die();
	}

    private function process_wptc_login() {
        $options_helper = new Wptc_Options_Helper();

        if($options_helper->get_is_user_logged_in()){
            return array(
                'result' => 'is_user_logged_in',
                'sync_data' => $this->get_sync_data()
            );
        }

        $email = $_POST['acc_email'];
        $pwd = $_POST['acc_pwd'];

        if (empty( $email ) || empty($pwd)) {
            return array('error' => 'Username and password cannot be empty');
        }


        $config = WPTC_Base_Factory::get('Wptc_InitialSetup_Config');
        $options = WPTC_Factory::get('config');

		$config->set_option('wptc_main_acc_email_temp', base64_encode($email));
		$config->set_option('wptc_main_acc_pwd_temp', base64_encode(md5(trim( wp_unslash( $pwd ) ))));
		$config->set_option('wptc_token', false);

		$options->request_service(
			array(
				'email'                 => $email,
				'pwd'                   => trim( wp_unslash( $pwd )),
				'return_response'       => false,
				'sub_action' 	        => false,
				'login_request'         => true,
				'reset_login_if_failed' => true,
			)
		);


        $is_user_logged_in  = $options->get_option('is_user_logged_in');

		if (!$is_user_logged_in) {
			return array('error' => 'Login failed.');
		}
        return array('result' => 'ok', 'sync_data' => $this->get_sync_data());
	}

    function get_installed_plugins(){

        $backup_before_auto_update_settings = WPTC_Pro_Factory::get('Wptc_Backup_Before_Auto_Update_Settings');
        $plugins = $backup_before_auto_update_settings->get_installed_plugins();

		if ($plugins) {
			return array('results' =>$plugins );
		}
        return  array( 'results' => array());
    }

    function get_installed_themes(){

        $backup_before_auto_update_settings = WPTC_Pro_Factory::get('Wptc_Backup_Before_Auto_Update_Settings');

        $plugins = $backup_before_auto_update_settings->get_installed_themes();
		if ($plugins) {
			return array('results' =>$plugins );
		}
        return array('results' => array() ) ;
    }

    function is_staging_need_request(){
        $staging = WPTC_Pro_Factory::get('Wptc_Staging');
        $staging->is_staging_need_request();
        die();
    }

    function get_staging_details_wptc(){
        $staging = WPTC_Pro_Factory::get('Wptc_Staging');
        $details = $staging->get_staging_details();
		$details['is_running'] = $staging->is_any_staging_process_going_on();
		wptc_die_with_json_encode( $details, 1 );
    }

    function start_fresh_staging_wptc(){
        $staging = WPTC_Pro_Factory::get('Wptc_Staging');

		if (empty($_POST['path'])) {
			wptc_die_with_json_encode( array('status' => 'error', 'msg' => 'path is missing') );
		}

		$staging->choose_action($_POST['path'], $reqeust_type = 'fresh');
        die();
    }

    function get_staging_url_wptc(){
        $staging = WPTC_Pro_Factory::get('Wptc_Staging');
        $staging->get_staging_url_wptc();
		die();
    }

    function stop_staging_wptc(){
        $staging = WPTC_Pro_Factory::get('Wptc_Staging');
		$staging->stop_staging_wptc();
		die();
    }

    function continue_staging_wptc(){
        $staging = WPTC_Pro_Factory::get('Wptc_Staging');
        $staging->choose_action();
		die();
    }

    function delete_staging_wptc(){
        $staging = WPTC_Pro_Factory::get('Wptc_Staging');
        $staging->delete_staging_wptc();
		die();
    }

    function copy_staging_wptc(){
        $staging = WPTC_Pro_Factory::get('Wptc_Staging');
        $staging->choose_action(false, $reqeust_type = 'copy');
		die();
    }

    function get_staging_current_status_key(){
        $staging = WPTC_Pro_Factory::get('Wptc_Staging');
        $staging->get_staging_current_status_key();
		die();
    }

    function wptc_sync_purchase(){
        $config = WPTC_Factory::get('config');

        $config->request_service(
                    array(
                        'email'           => false,
                        'pwd'             => false,
                        'return_response' => false,
                        'sub_action' 	  => 'sync_all_settings_to_node',
                        'login_request'   => true,
                    )
                );
        die();
    }

    public function init_restore() {

		if (empty($_POST)) {
			return ( array('error' => 'Backup id is empty !') );
		}
        $restore_to_staging = WPTC_Base_Factory::get('Wptc_Restore_To_Staging');
		$restore_to_staging->init_restore($_POST);

        die();
	}

    function save_settings_wptc(){

        $options_helper = new Wptc_Options_Helper();

        if( !$options_helper->get_is_user_logged_in() ){
            return array(
                'sync_data' => $this->get_sync_data(),
                'error' => 'Login to your WP Time Capsule account first'
            );
        }

        $data = unserialize(base64_decode($_POST['data']));

        $tabName =  $_POST['tabname'];
        $is_general =  $_POST['is_general'];


        $saved = false;

        $config = WPTC_Factory::get('config');

        if ( $tabName == 'backup' ) { // save_backup_settings_wptc()

            $config->set_option('user_excluded_extenstions', $data['user_excluded_extenstions']);
            $config->set_option('user_excluded_files_more_than_size_settings', $data['user_excluded_files_more_than_size_settings']);


            if (!empty($data['backup_slot'])) {
                $config->set_option('old_backup_slot', $config->get_option('backup_slot'));
                $config->set_option('backup_slot', $data['backup_slot']);
            }

            $config->set_option('backup_db_query_limit', $data['backup_db_query_limit']);
            $config->set_option('database_encrypt_settings', $data['database_encrypt_settings']);
            $config->set_option('wptc_timezone', $data['wptc_timezone']);
            $config->set_option('schedule_time_str', $data['schedule_time_str']);

            if(!empty($data['schedule_time_str']) && !empty($data['wptc_timezone']) ){
                if (function_exists('wptc_modify_schedule_backup'))
                    wptc_modify_schedule_backup();
            }

            $notice = apply_filters('check_requirements_auto_backup_wptc', '');

            if (!empty($data['revision_limit']) && !$notice ) {
                $notice = apply_filters('save_settings_revision_limit_wptc', $data['revision_limit']);
            }

            $saved = true;

        } else if ( $tabName == 'backup_auto' ) { // update_auto_update_settings()

            $config->set_option('backup_before_update_setting', $data['backup_before_update_setting']);

            $current = $config->get_option('wptc_auto_update_settings');
            $current = unserialize($current);
            $new = unserialize($data['wptc_auto_update_settings']);

            $current['update_settings']['status']                    = $new['update_settings']['status'];
            $current['update_settings']['schedule']['enabled']       = $new['update_settings']['schedule']['enabled'];
            $current['update_settings']['schedule']['time']   		 = $new['update_settings']['schedule']['time'];
            $current['update_settings']['core']['major']['status']   = $new['update_settings']['core']['major']['status'];
            $current['update_settings']['core']['minor']['status']   = $new['update_settings']['core']['minor']['status'];
            $current['update_settings']['themes']['status']          = $new['update_settings']['themes']['status'];
            $current['update_settings']['plugins']['status']         = $new['update_settings']['plugins']['status'];

            if (!$is_general) {
                if (isset($new['update_settings']['plugins']['included']))
                    $current['update_settings']['plugins']['included']       = $new['update_settings']['plugins']['included'];
                else
                    $current['update_settings']['plugins']['included'] = array();

                if (isset($new['update_settings']['themes']['included']))
                    $current['update_settings']['themes']['included']        = $new['update_settings']['themes']['included'];
                else
                    $current['update_settings']['themes']['included']        = array();
            }
            $config->set_option('wptc_auto_update_settings', serialize($current));
            $saved = true;

        } else if ( $tabName == 'vulns_update' ) {
            $current = $config->get_option('vulns_settings');
            $current = unserialize($current);
            $new = unserialize($data['vulns_settings']);

            $current['status'] = $new['status'];
            $current['core']['status'] = $new['core']['status'];
            $current['themes']['status'] = $new['themes']['status'];
            $current['plugins']['status'] = $new['plugins']['status'];

            if (!$is_general) {
                $vulns_plugins_included =  !empty($new['plugins']['vulns_plugins_included']) ? $new['plugins']['vulns_plugins_included'] : array();

                $plugin_include_array = array();

                if (!empty($vulns_plugins_included)) {
                    $plugin_include_array = explode(',', $vulns_plugins_included);
                    $plugin_include_array = !empty($plugin_include_array) ? $plugin_include_array : array() ;
                }

                wptc_log($plugin_include_array, '--------$plugin_include_array--------');



                $included_plugins = $this->filter_plugins($plugin_include_array);



                wptc_log($included_plugins, '--------$included_plugins--------');

                $current['plugins']['excluded'] = serialize($included_plugins);


                $vulns_themes_included =  !empty($new['themes']['vulns_themes_included']) ? $new['themes']['vulns_themes_included'] : array();

                $themes_include_array  = array();

                if (!empty($vulns_themes_included)) {
                    $themes_include_array = explode(',', $vulns_themes_included);
                }

                $included_themes = $this->filter_themes($themes_include_array);
                $current['themes']['excluded'] = serialize($included_themes);
            }
            $config->set_option('vulns_settings', serialize($current));

            $saved = true;

        } else if ( $tabName == 'staging_opts' ) {
            $config->set_option('user_excluded_extenstions_staging', $data['user_excluded_extenstions_staging']);
            $config->set_option('internal_staging_db_rows_copy_limit', $data['internal_staging_db_rows_copy_limit']);
            $config->set_option('internal_staging_file_copy_limit', $data['internal_staging_file_copy_limit']);
            $config->set_option('internal_staging_deep_link_limit', $data['internal_staging_deep_link_limit']);
            $config->set_option('internal_staging_enable_admin_login', $data['internal_staging_enable_admin_login']);
            $config->set_option('staging_is_reset_permalink', $data['staging_is_reset_permalink']);
            if (!$is_general) {
                $config->set_option('staging_login_custom_link', $data['staging_login_custom_link']);
            }
            $saved = true;
        }

        if ( ! $saved ) {
            return array('error' => 'Error: Not saved settings');
        }

        return array('result' => 'ok');
    }

	private function filter_plugins($included_plugins){
        $app_functions = WPTC_Base_Factory::get('Wptc_App_Functions');
		$plugins_data = $app_functions->get_all_plugins_data($specific = true, $attr = 'slug');
		$not_included_plugin = array_diff($plugins_data, $included_plugins);
		wptc_log($plugins_data, '--------$plugins_data--------');
		wptc_log($not_included_plugin, '--------$not_included_plugin--------');
		return $not_included_plugin;
	}


	private function filter_themes($included_themes){
        $app_functions = WPTC_Base_Factory::get('Wptc_App_Functions');
		$themes_data = $app_functions->get_all_themes_data($specific = true, $attr = 'slug');
		$not_included_theme = array_diff($themes_data, $included_themes);
		wptc_log($themes_data, '--------$themes_data--------');
		wptc_log($not_included_theme, '--------$not_included_theme--------');
		return $not_included_theme;
	}


    public function analyze_inc_exc(){
        $exclude_opts_obj = WPTC_Base_Factory::get('Wptc_ExcludeOption');
		$exclude_opts_obj = $exclude_opts_obj->analyze_inc_exc(); // raw response
        die();
	}

    public function get_enabled_plugins(){
        $vulns_obj = WPTC_Base_Factory::get('Wptc_Vulns');

		$plugins = $vulns_obj->get_enabled_plugins();
        $plugins = WPTC_Base_Factory::get('Wptc_App_Functions')->fancytree_format($plugins, 'plugins');

        return array('results' => $plugins);
	}

    public function get_enabled_themes(){
        $vulns_obj = WPTC_Base_Factory::get('Wptc_Vulns');
		$themes = $vulns_obj->get_enabled_themes();
        $themes = WPTC_Base_Factory::get('Wptc_App_Functions')->fancytree_format($themes, 'themes');
        return array('results' => $themes);
	}

    public function get_system_info(){
        global $wpdb;

        $wptc_settings = WPTC_Base_Factory::get('Wptc_Settings');

        ob_start();

        echo '<table class="wp-list-table widefat fixed" cellspacing="0" >';
        echo '<thead><tr><th width="35%">' . __( 'Setting', 'wp-time-capsule' ) . '</th><th>' . __( 'Value', 'wp-time-capsule' ) . '</th></tr></thead>';
        echo '<tr title="&gt;=3.9.14"><td>' . __( 'WordPress version', 'wp-time-capsule' ) . '</td><td>' . esc_html( $wptc_settings->get_plugin_data( 'wp_version' ) ) . '</td></tr>';
        echo '<tr title=""><td>' . __( 'WP Time Capsule version', 'wp-time-capsule' ) . '</td><td>' . esc_html( $wptc_settings->get_plugin_data( 'Version' ) ) . '</td></tr>';

        $bit = '';
        if ( PHP_INT_SIZE === 4 ) {
            $bit = ' (32bit)';
        }
        if ( PHP_INT_SIZE === 8 ) {
            $bit = ' (64bit)';
        }

        echo '<tr title="&gt;=5.3.1"><td>' . __( 'PHP version', 'wp-time-capsule' ) . '</td><td>' . esc_html( PHP_VERSION . ' ' . $bit ) . '</td></tr>';
        echo '<tr title="&gt;=5.0.15"><td>' . __( 'MySQL version', 'wp-time-capsule' ) . '</td><td>' . esc_html( $wpdb->get_var( "SELECT VERSION() AS version" ) ) . '</td></tr>';

        if ( function_exists( 'curl_version' ) ) {
            $curlversion = curl_version();
            echo '<tr title=""><td>' . __( 'cURL version', 'wp-time-capsule' ) . '</td><td>' . esc_html( $curlversion[ 'version' ] ) . '</td></tr>';
            echo '<tr title=""><td>' . __( 'cURL SSL version', 'wp-time-capsule' ) . '</td><td>' . esc_html( $curlversion[ 'ssl_version' ] ) . '</td></tr>';
        }
        else {
            echo '<tr title=""><td>' . __( 'cURL version', 'wp-time-capsule' ) . '</td><td>' . __( 'unavailable', 'wp-time-capsule' ) . '</td></tr>';
        }

        echo '</td></tr>';
        echo '<tr title=""><td>' . __( 'Server', 'wp-time-capsule' ) . '</td><td>' . esc_html( $_SERVER[ 'SERVER_SOFTWARE' ] ) . '</td></tr>';
        echo '<tr title=""><td>' . __( 'Operating System', 'wp-time-capsule' ) . '</td><td>' . esc_html( PHP_OS ) . '</td></tr>';
        echo '<tr title=""><td>' . __( 'PHP SAPI', 'wp-time-capsule' ) . '</td><td>' . esc_html( PHP_SAPI ) . '</td></tr>';

        $php_user = __( 'Function Disabled', 'wp-time-capsule' );
        if ( function_exists( 'get_current_user' ) ) {
            $php_user = get_current_user();
        }

        echo '<tr title=""><td>' . __( 'Current PHP user', 'wp-time-capsule' ) . '</td><td>' . esc_html( $php_user )  . '</td></tr>';
        echo '<tr title="&gt;=30"><td>' . __( 'Maximum execution time', 'wp-time-capsule' ) . '</td><td>' . esc_html( ini_get( 'max_execution_time' ) ) . ' ' . __( 'seconds', 'wp-time-capsule' ) . '</td></tr>';

        if ( defined( 'FS_CHMOD_DIR' ) )
            echo '<tr title="FS_CHMOD_DIR"><td>' . __( 'CHMOD Dir', 'wp-time-capsule' ) . '</td><td>' . esc_html( FS_CHMOD_DIR ) . '</td></tr>';
        else
            echo '<tr title="FS_CHMOD_DIR"><td>' . __( 'CHMOD Dir', 'wp-time-capsule' ) . '</td><td>0755</td></tr>';

        $now = localtime( time(), TRUE );
        echo '<tr title=""><td>' . __( 'Server Time', 'wp-time-capsule' ) . '</td><td>' . esc_html( $now[ 'tm_hour' ] . ':' . $now[ 'tm_min' ] ) . '</td></tr>';
        echo '<tr title=""><td>' . __( 'Blog Time', 'wp-time-capsule' ) . '</td><td>' . date( 'H:i', current_time( 'timestamp' ) ) . '</td></tr>';
        echo '<tr title="WPLANG"><td>' . __( 'Blog language', 'wp-time-capsule' ) . '</td><td>' . get_bloginfo( 'language' ) . '</td></tr>';
        echo '<tr title="utf8"><td>' . __( 'MySQL Client encoding', 'wp-time-capsule' ) . '</td><td>';
        echo defined( 'DB_CHARSET' ) ? DB_CHARSET : '';
        echo '</td></tr>';
        echo '<tr title="URF-8"><td>' . __( 'Blog charset', 'wp-time-capsule' ) . '</td><td>' . get_bloginfo( 'charset' ) . '</td></tr>';
        echo '<tr title="&gt;=128M"><td>' . __( 'PHP Memory limit', 'wp-time-capsule' ) . '</td><td>' . esc_html( ini_get( 'memory_limit' ) ) . '</td></tr>';
        echo '<tr title="WP_MEMORY_LIMIT"><td>' . __( 'WP memory limit', 'wp-time-capsule' ) . '</td><td>' . esc_html( WP_MEMORY_LIMIT ) . '</td></tr>';
        echo '<tr title="WP_MAX_MEMORY_LIMIT"><td>' . __( 'WP maximum memory limit', 'wp-time-capsule' ) . '</td><td>' . esc_html( WP_MAX_MEMORY_LIMIT ) . '</td></tr>';
        echo '<tr title=""><td>' . __( 'Memory in use', 'wp-time-capsule' ) . '</td><td>' . size_format( @memory_get_usage( TRUE ), 2 ) . '</td></tr>';

        //disabled PHP functions
        $disabled = esc_html( ini_get( 'disable_functions' ) );
        if ( ! empty( $disabled ) ) {
            $disabledarry = explode( ',', $disabled );
            echo '<tr title=""><td>' . __( 'Disabled PHP Functions:', 'wp-time-capsule' ) . '</td><td>';
            echo implode( ', ', $disabledarry );
            echo '</td></tr>';
        }

        //Loaded PHP Extensions
        echo '<tr title=""><td>' . __( 'Loaded PHP Extensions:', 'wp-time-capsule' ) . '</td><td>';
        $extensions = get_loaded_extensions();
        sort( $extensions );
        echo  esc_html( implode( ', ', $extensions ) );
        echo '</td></tr>';
        echo '</table>';

        $html = ob_get_clean();
        return array( 'result' => $html);
	}


    public function update_vulns_settings(){

        $vulns_obj = WPTC_Base_Factory::get('Wptc_Vulns');

        $data = isset($_POST['data']) ? $_POST['data'] : array() ;
		$vulns_obj->update_vulns_settings($data);

        return array( 'success' => 1 );
	}

    function start_fresh_backup_tc_callback_wptc() {
        start_fresh_backup_tc_callback_wptc($type = '', $args = null, $test_connection = true, $ajax_check = false);
        return array('result' => 'success');
    }

    public function save_manual_backup_name_wptc() {
	    $backup_name = $_POST['backup_name'];
	    $processed_files = WPTC_Factory::get('processed-files');
        $processed_files->save_manual_backup_name_wptc($backup_name);
        die();
	}

	public function all_plugins( $plugins ) {
		foreach ( $plugins as $key => $value ) {
			$plugin_slug = basename( $key, '.php' );
			if ( 'wp-time-capsule' === $plugin_slug ) {
				unset( $plugins[ $key ] );
			}
		}

		return $plugins;
	}

	public function remove_menu() {
        remove_menu_page( 'wp-time-capsule-monitor' );
		$pos = stripos( $_SERVER['REQUEST_URI'], 'admin.php?page=wp-time-capsule-monitor' );
		if ( false !== $pos ) {
			wp_redirect( get_option( 'siteurl' ) . '/wp-admin/index.php' );
			exit();
		}
	}

    function hide_update_notice( $slugs ) {
        $slugs[] = 'wp-time-capsule/wp-time-capsule.php';
        return $slugs;
    }

	function remove_update_nag( $value ) {
		if ( isset( $_POST['mainwpsignature'] ) ) {
			return $value;
		}
        if (! MainWP_Helper::is_screen_with_update()) {
            return $value;
        }
		if ( isset( $value->response['wp-time-capsule/wp-time-capsule.php'] ) ) {
			unset( $value->response['wp-time-capsule/wp-time-capsule.php'] );
		}

		return $value;
	}
}

