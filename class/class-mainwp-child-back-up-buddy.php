<?php

/*
 *
 * Credits
 *
 * Plugin-Name: BackupBuddy
 * Plugin URI: http://ithemes.com/purchase/backupbuddy/
 * Author: iThemes
 * Author URI: http://ithemes.com/
 * iThemes Package: backupbuddy
 *
 * The code is used for the MainWP Buddy Extension
 * Extension URL: https://mainwp.com/extension/mainwpbuddy/
 *
*/

class MainWP_Child_Back_Up_Buddy {
	public static $instance = null;
	public $plugin_translate = 'mainwp-child';
	public $is_backupbuddy_installed = false;

	static function Instance() {
		if ( null === MainWP_Child_Back_Up_Buddy::$instance ) {
			MainWP_Child_Back_Up_Buddy::$instance = new MainWP_Child_Back_Up_Buddy();
		}
		return MainWP_Child_Back_Up_Buddy::$instance;
	}

	public function __construct() {
		// To fix bug run dashboard on local machine
		//if ( is_plugin_active( 'backupbuddy/backupbuddy.php' )) {
		if ( class_exists('pb_backupbuddy')) {
			$this->is_backupbuddy_installed = true;
		}

		if (!$this->is_backupbuddy_installed) {
			return;
		}

        add_filter( 'mainwp-site-sync-others-data', array( $this, 'syncOthersData' ), 10, 2 );

		add_action( 'wp_ajax_mainwp_backupbuddy_download_archive', array( $this, 'download_archive' ) );
		add_action( 'mainwp_child_site_stats', array( $this, 'do_site_stats' ) );

		if ( get_option( 'mainwp_backupbuddy_hide_plugin' ) === 'hide' ) {
			add_filter( 'all_plugins', array( $this, 'all_plugins' ) );
			add_action( 'admin_menu', array( $this, 'admin_menu' ) );
			add_filter( 'site_transient_update_plugins', array( &$this, 'remove_update_nag' ) );
            add_filter( 'mainwp_child_hide_update_notice', array( &$this, 'hide_update_notice' ) );
		}
	}

    function hide_update_notice( $slugs ) {
        $slugs[] = 'backupbuddy/backupbuddy.php';
        return $slugs;
    }

	function remove_update_nag( $value ) {
		if ( isset( $_POST['mainwpsignature'] ) ) {
			return $value;
		}

        if (! MainWP_Helper::is_screen_with_update()) {
            return $value;
        }

		if ( isset( $value->response['backupbuddy/backupbuddy.php'] ) ) {
			unset( $value->response['backupbuddy/backupbuddy.php'] );
		}

		return $value;
	}



	public function all_plugins( $plugins ) {
		foreach ( $plugins as $key => $value ) {
			$plugin_slug = basename( $key, '.php' );
			if ( 'backupbuddy' === $plugin_slug ) {
				unset( $plugins[ $key ] );
			}
		}

		return $plugins;
	}

	public function admin_menu() {
		global $submenu;
		remove_menu_page( 'pb_backupbuddy_backup' );

		if ( false !== stripos( $_SERVER['REQUEST_URI'], 'admin.php?page=pb_backupbuddy_' ) ) {
			wp_redirect( get_option( 'siteurl' ) . '/wp-admin/index.php' );
			exit();
		}
	}


	function do_site_stats() {
		if (has_action('mainwp_child_reports_log')) {
			do_action( 'mainwp_child_reports_log', 'backupbuddy');
		} else {
			$this->do_reports_log('backupbuddy');
		}
	}
    // ok
	function do_reports_log($ext = '') {
		if ($ext !== 'backupbuddy')
			return;

		if (!$this->is_backupbuddy_installed) {
			return;
		}

        try {

            MainWP_Helper::check_methods( 'pb_backupbuddy', array( 'plugin_path' ));

            if ( ! class_exists( 'backupbuddy_core' ) ) {
                if ( file_exists(pb_backupbuddy::plugin_path() . '/classes/core.php') )
                    require_once( pb_backupbuddy::plugin_path() . '/classes/core.php' );
            }

            if (file_exists(pb_backupbuddy::plugin_path() . '/classes/fileoptions.php'))
                require_once( pb_backupbuddy::plugin_path() . '/classes/fileoptions.php' );

            MainWP_Helper::check_classes_exists(array( 'backupbuddy_core', 'pb_backupbuddy_fileoptions' ));
            MainWP_Helper::check_methods('backupbuddy_core', 'getLogDirectory');

            // Backup type.
            $pretty_type = array(
                'full'	=>	'Full',
                'db'	=>	'Database',
                'files' =>	'Files',
            );

            $recentBackups_list = glob( backupbuddy_core::getLogDirectory() . 'fileoptions/*.txt' );


                foreach( $recentBackups_list as $backup_fileoptions ) {

                    $backup = new pb_backupbuddy_fileoptions( $backup_fileoptions, $read_only = true );
                    if ( method_exists($backup, 'is_ok') && true !== ( $result = $backup->is_ok() ) ) {
                        continue;
                    }

                    $backup = &$backup->options;

                    if ( !isset( $backup['serial'] ) || ( $backup['serial'] == '' ) ) {
                        continue;
                    }

                    if ( ( $backup['finish_time'] >= $backup['start_time'] ) && ( 0 != $backup['start_time'] ) ) {
                        // it is ok
                    } else {
                        continue;
                    }

                    $backupType = '';
                    if ( isset( $backup['profile'] ) && isset( $backup['profile']['type'] ) ) {
                        if (true === MainWP_Helper::check_properties('pb_backupbuddy', 'format', true)) {
                            if (true === MainWP_Helper::check_methods(pb_backupbuddy::$format, array( 'prettify' ), true)) {
                                $backupType = pb_backupbuddy::$format->prettify( $backup['profile']['type'], $pretty_type );
                            }
                        }
                    } else {
                        if (true === MainWP_Helper::check_methods('backupbuddy_core', array( 'pretty_backup_type', 'getBackupTypeFromFile' ), true)) {
                            $backupType = backupbuddy_core::pretty_backup_type( backupbuddy_core::getBackupTypeFromFile( $backup['archive_file'] ) );
                        }
                    }

                    if ( '' == $backupType ) {
                        $backupType = 'Unknown';
                    }

                    $finish_time = $backup['finish_time'];
                    $message = 'BackupBuddy ' . $backupType . ' finished';
                    if (!empty($finish_time)) {
                        do_action( 'mainwp_reports_backupbuddy_backup', $message, $backupType, $finish_time);
                    }
                }

                if ( file_exists(pb_backupbuddy::plugin_path() . '/destinations/live/live_periodic.php') ) {
                    require_once( pb_backupbuddy::plugin_path() . '/destinations/live/live_periodic.php' );

                    MainWP_Helper::check_classes_exists(array( 'backupbuddy_live_periodic' ));
                    MainWP_Helper::check_methods('backupbuddy_live_periodic', 'get_stats');

                    $state = backupbuddy_live_periodic::get_stats();
                    if (is_array($state) && isset($state['stats'])) {

                        if ( is_array($state['stats'] ) && isset( $state['stats']['last_remote_snapshot'] )) {
                            if (isset( $state['stats']['last_remote_snapshot_response'] )) {
                                $resp = $state['stats']['last_remote_snapshot_response'];
                                if ( isset( $resp['success'] ) && $resp['success']) {
                                    $finish_time = $state['stats']['last_remote_snapshot'];
                                    $backupType = 'Live Backup to cloud';
                                    $message = 'BackupBuddy ' . $backupType . ' finished';
                                    if (!empty($finish_time)) {
                                        do_action( 'mainwp_reports_backupbuddy_backup', $message, $backupType, $finish_time);
                                    }

                                }
                            }
                        }

                    }
                }
        } catch( Exception $e ) {

        }
    }

	public function action() {
		$information = array();
		if ( ! $this->is_backupbuddy_installed ) {
			MainWP_Helper::write( array( 'error' => __( 'Please install the BackupBuddy plugin on the child site.', $this->plugin_translate ) ) );
		}

		if ( ! class_exists( 'backupbuddy_core' ) ) {
			require_once( pb_backupbuddy::plugin_path() . '/classes/core.php' );
		}

		if ( !isset( pb_backupbuddy::$options ) ) {
			pb_backupbuddy::load();
		}

		if ( isset( $_POST['mwp_action'] ) ) {
			switch ( $_POST['mwp_action'] ) {
				case 'set_showhide':
					$information = $this->set_showhide();
					break;
				case 'save_settings':
					$information = $this->save_settings();
					break;
				case 'reset_defaults':
					$information = $this->reset_defaults();
					break;
				case 'get_notifications':
					$information = $this->get_notifications();
					break;
				case 'schedules_list':
					$information = $this->schedules_list();
					break;
				case 'run_scheduled_backup':
					$information = $this->run_scheduled_backup();
					break;
				case 'save_scheduled_backup':
					$information = $this->save_scheduled_backup();
					break;
				case 'delete_scheduled_backup':
					$information = $this->delete_scheduled_backup();
					break;
				case 'save_profile':
					$information = $this->save_profile();
					break;
				case 'delete_profile':
					$information = $this->delete_profile();
					break;
				case 'delete_backup':
					$information = $this->delete_backup();
					break;
				case 'backup_list':
					$information = $this->backup_list();
					break;
				case 'save_note':
					$information = $this->save_note();
					break;
				case 'get_hash':
					$information = $this->get_hash();
					break;
				case 'zip_viewer':
					$information = $this->zip_viewer();
					break;
				case 'exclude_tree':
					$information = $this->exclude_tree();
					break;
				case 'restore_file_view':
					$information = $this->restore_file_view();
					break;
				case 'restore_file_restore':
					$information = $this->restore_file_restore();
					break;
				case 'view_log':
					$information = $this->view_log();
					break;
				case 'view_detail':
					$information = $this->view_detail();
					break;
				case 'reset_integrity':
					$information = $this->reset_integrity();
					break;
				case 'download_archive':
					$information = $this->download_archive();
					break;
				case 'create_backup':
					$information = $this->create_backup();
					break;
				case 'start_backup':
					$information = $this->start_backup();
					break;
				case 'backup_status':
					$information = $this->backup_status();
					break;
				case 'stop_backup':
					$information = $this->stop_backup();
					break;
				case 'remote_save':
					$information = $this->remote_save();
					break;
				case 'remote_delete':
					$information = $this->remote_delete();
					break;
				case 'remote_send':
					$information = $this->remote_send();
					break;
				case 'remote_list':
					$information = $this->remote_list();
					break;
				case 'get_main_log':
					$information = $this->get_main_log();
					break;
				case 'settings_other':
					$information = $this->settings_other();
					break;
				case 'malware_scan':
					$information = $this->malware_scan();
					break;
				case 'live_setup':
					$information = $this->live_setup();
					break;
				case 'live_save_settings':
					$information = $this->live_save_settings();
					break;
				case 'live_action_disconnect':
					$information = $this->live_action_disconnect();
					break;
				case 'live_action':
					$information = $this->live_action();
					break;
				case 'download_troubleshooting':
					$information = $this->download_troubleshooting();
					break;
				case 'get_live_backups':
					$information = $this->get_live_backups();
					break;
				case 'copy_file_to_local':
					$information = $this->copy_file_to_local();
					break;
				case 'delete_file_backup':
					$information = $this->delete_file_backup();
					break;
				case 'get_live_stats':
					$information = $this->get_live_stats();
					break;
				case 'load_products_license':
					$information = $this->load_products_license();
					break;
				case 'save_license_settings':
					$information = $this->save_license_settings();
					break;
				case 'activate_package':
					$information = $this->activate_package();
					break;
				case 'deactivate_package':
					$information = $this->deactivate_package();
					break;
			}
		}
		MainWP_Helper::write( $information );
	}


	function set_showhide() {
		$hide = isset( $_POST['showhide'] ) && ( 'hide' === $_POST['showhide'] ) ? 'hide' : '';
		MainWP_Helper::update_option( 'mainwp_backupbuddy_hide_plugin', $hide );
		$information['result'] = 'SUCCESS';
		return $information;
	}

	function save_settings() {

		$type = isset($_POST['type']) ? $_POST['type'] : '';

		if ($type !== 'general_settings' && $type !== 'advanced_settings' && $type !== 'all' ) {
			return array('error' => __('Invalid data. Please check and try again.') );
		}

		$filter_advanced_settings = array(
			'backup_reminders',
			'archive_name_format',
			'archive_name_profile',
			'lock_archives_directory',
			'include_importbuddy',
			'default_backup_tab',
			'disable_localization',
			'limit_single_cron_per_pass',
			'use_internal_cron',
			'cron_request_timeout_override',
			'remote_send_timeout_retries',
			'hide_live',
			'hide_dashboard_widget',
			'set_greedy_execution_time',
			'archive_limit_size_big',
			'max_execution_time',
			'log_level',
			'save_backup_sum_log',
			'max_site_log_size',
			'max_send_stats_days',
			'max_send_stats_count',
			'max_notifications_age_days',
			'backup_mode',
			'delete_archives_pre_backup',
			'disable_https_local_ssl_verify',
			'prevent_flush',
			'save_comment_meta',
			'integrity_check', // profiles#0
			'backup_cron_rescheduling',
			'skip_spawn_cron_call',
			'backup_cron_passed_force_time',
			'php_runtime_test_minimum_interval',
			'php_memory_test_minimum_interval',
			'database_method_strategy',
			'skip_database_dump', // profiles#0
			'breakout_tables',
			'force_single_db_file',
			'phpmysqldump_maxrows',
			'ignore_command_length_check',
			'compression',
			'zip_method_strategy',
			'alternative_zip_2',
			'zip_build_strategy',
			'zip_step_period',
			'zip_burst_gap',
			'zip_min_burst_content',
			'zip_max_burst_content',
			'disable_zipmethod_caching',
			'ignore_zip_warnings',
			'ignore_zip_symlinks',
		);


		$filter_general_settings = array(
			'importbuddy_pass_hash',
			'importbuddy_pass_length',
			'backup_directory',
			'role_access',
			'archive_limit_age',
			'archive_limit_full',
			'archive_limit',
			'archive_limit_size',
			'archive_limit_db',
			'archive_limit_files',
			'title_multisite',
			'multisite_export',
			'backup_nonwp_tables', // profiles#0
			'mysqldump_additional_includes', // profiles#0
			'mysqldump_additional_excludes', // profiles#0
			'excludes', // profiles#0
			'email_notify_scheduled_start',
			'email_notify_scheduled_start_subject',
			'email_notify_scheduled_start_body',
			'email_notify_scheduled_complete',
			'email_notify_scheduled_complete_subject',
			'email_notify_scheduled_complete_body',
			'email_notify_send_finish',
			'email_notify_send_finish_subject',
			'email_notify_send_finish_body',
			'no_new_backups_error_days',
			'email_notify_error',
			'email_notify_error_subject',
			'email_notify_error_body',
			'email_return'
		);

		$filter_profile0_values = array(
			'mysqldump_additional_includes',
			'mysqldump_additional_excludes',
			'excludes',
			'integrity_check',
			'skip_database_dump',
			'backup_nonwp_tables'
		);

		$settings = unserialize(base64_decode($_POST['options']));

		$save_settings = array();

		if (is_array($settings)) {
			if ($type === 'all' || 'general_settings' === $type) {
				foreach($filter_general_settings as $field) {
					if(isset($settings[$field])) {
						$save_settings[$field] = $settings[$field];
					}
				}
			}

			if ($type === 'all' || 'advanced_settings' === $type) {
				foreach($filter_advanced_settings as $field) {
					if(isset($settings[$field])) {
						$save_settings[$field] = $settings[$field];
					}
				}
			}
		}

		if (!empty($save_settings)) {
			$newOptions = pb_backupbuddy::$options;

			foreach($newOptions as $key => $val) {
				if (isset($save_settings[$key])) {
					$newOptions[$key] = $save_settings[$key];
				}
			}

			if (isset($newOptions['profiles']) && isset($newOptions['profiles'][0])) {
				foreach ($filter_profile0_values as $field) {
					if (isset($settings[$field])) {
						$newOptions['profiles'][0][$field] = $settings[$field];
					}
				}
			}

			if ('general_settings' === $type || 'all' === $type ) {
				$newOptions['importbuddy_pass_hash_confirm'] = '';
			}

			global $wpdb;
			$option = 'pb_' . pb_backupbuddy::settings( 'slug' );
			$newOptions = sanitize_option( $option, $newOptions );
			$newOptions = maybe_serialize( $newOptions );

			add_site_option( $option, $newOptions, '', 'no'); // 'No' prevents autoload if we wont always need the data loaded.
			$wpdb->update( $wpdb->options, array( 'option_value' => $newOptions ), array( 'option_name' => $option ) );

			$information['backupDirectoryDefault'] = backupbuddy_core::_getBackupDirectoryDefault();
			$information['result'] = 'SUCCESS';
		}

		return $information;
	}

	function reset_defaults() {
		// Keep log serial.
		$old_log_serial = pb_backupbuddy::$options['log_serial'];

		$keepDestNote = '';
		$remote_destinations = pb_backupbuddy::$options['remote_destinations'];
		pb_backupbuddy::$options = pb_backupbuddy::settings( 'default_options' );
		if ( '1' == $_POST['keep_destinations'] ) {
			pb_backupbuddy::$options['remote_destinations'] = $remote_destinations;
			$keepDestNote = ' ' . __( 'Remote destination settings were not reset.', 'mainwp-child' );
		}

		// Replace log serial.
		pb_backupbuddy::$options['log_serial'] = $old_log_serial;

		pb_backupbuddy::save();

		backupbuddy_core::verify_directories( $skipTempGeneration = true ); // Re-verify directories such as backup dir, temp, etc.
		$resetNote = __( 'Plugin settings have been reset to defaults.', 'mainwp-child' );
		backupbuddy_core::addNotification( 'settings_reset', 'Plugin settings reset', $resetNote . $keepDestNote );

		$information['message'] = $resetNote . $keepDestNote;
		$information['result'] = 'SUCCESS';
		return $information;
	}

	function get_notifications() {
		return array('result' => 'SUCCESS', 'notifications' => backupbuddy_core::getNotifications() );
	}

	function get_schedules_run_time() {
		$schedules_run_time = array();
		foreach ( pb_backupbuddy::$options['schedules'] as $schedule_id => $schedule ) {
			// Determine first run.
			$first_run = pb_backupbuddy::$format->date( pb_backupbuddy::$format->localize_time( $schedule['first_run'] ) );
			// Determine last run.
			if ( isset( $schedule['last_run'] ) ) { // backward compatibility before last run tracking added. Pre v2.2.11. Eventually remove this.
				if ( $schedule['last_run'] == 0 ) {
					$last_run = '<i>' . __( 'Never', 'it-l10n-backupbuddy' ) . '</i>';
				} else {
					$last_run = pb_backupbuddy::$format->date( pb_backupbuddy::$format->localize_time( $schedule['last_run'] ) );
				}
			} else { // backward compatibility for before last run tracking was added.
				$last_run = '<i> ' . __( 'Unknown', 'it-l10n-backupbuddy' ) . '</i>';
			}

			// Determine next run.
			$next_run = wp_next_scheduled( 'backupbuddy_cron', array( 'run_scheduled_backup', array( (int)$schedule_id ) ) );
			if ( false === $next_run ) {
				$next_run = '<font color=red>Error: Cron event not found</font>';
			} else {
				$next_run = pb_backupbuddy::$format->date( pb_backupbuddy::$format->localize_time( $next_run ) );
			}

			$run_time = 'First run: ' . $first_run . '<br>' .
			            'Last run: ' . $last_run . '<br>' .
			            'Next run: ' . $next_run;

			$schedules_run_time[$schedule_id] = $run_time;

		}

		return $schedules_run_time;
	}

	function schedules_list() {
		$information = array();
		$information['schedules'] = pb_backupbuddy::$options['schedules'];
		$information['next_schedule_index'] = pb_backupbuddy::$options['next_schedule_index'];
		$information['schedules_run_time'] = $this->get_schedules_run_time();

        // to fix missing destination notice
        if (isset(pb_backupbuddy::$options['remote_destinations'])) { // update
			$information['remote_destinations'] = pb_backupbuddy::$options['remote_destinations'];
		}

		$information['result'] = 'SUCCESS';
		return $information;
	}


	function run_scheduled_backup() {
		if ( ! is_main_site() ) { // Only run for main site or standalone. Multisite subsites do not allow schedules.
			return array('error' => __('Only run for main site or standalone. Multisite subsites do not allow schedules', 'mainwp-child') );
		}

		$schedule_id = (int) $_POST['schedule_id'];

		if ( !isset( pb_backupbuddy::$options['schedules'][$schedule_id] ) || ! is_array( pb_backupbuddy::$options['schedules'][$schedule_id] ) ) {
			return array('error' => __( 'Error: not found the backup schedule or invalid data', 'mainwp-child' ));
		}

		pb_backupbuddy::alert( 'Manually running scheduled backup "' . pb_backupbuddy::$options['schedules'][$schedule_id]['title'] . '" in the background.' . '<br>' .
		                       __( 'Note: If there is no site activity there may be delays between steps in the backup. Access the site or use a 3rd party service, such as a free pinging service, to generate site activity.', 'it-l10n-backupbuddy' ) );
		pb_backupbuddy_cron::_run_scheduled_backup( $schedule_id );

		$information['result'] = 'SUCCESS';

		return $information;
	}


	function save_scheduled_backup() {
		$schedule_id = intval($_POST['schedule_id']);
		$schedule = unserialize(base64_decode($_POST['data']));

		if (!is_array($schedule)) {
			return array('error' => __( 'Invalid schedule data', 'mainwp-child' ));
		}
		$information = array();
		// add new
		if (!isset(pb_backupbuddy::$options['schedules'][$schedule_id])) {
			$next_index = pb_backupbuddy::$options['next_schedule_index'];
			pb_backupbuddy::$options['next_schedule_index']++; // This change will be saved in savesettings function below.
			pb_backupbuddy::$options['schedules'][$schedule_id] = $schedule;
			$result = backupbuddy_core::schedule_event( $schedule['first_run'], $schedule['interval'], 'run_scheduled_backup', array( $schedule_id ) );
			if ( $result === false ) {
				return array('error' => 'Error scheduling event with WordPress. Your schedule may not work properly. Please try again. Error #3488439b. Check your BackupBuddy error log for details.');
			}
		} else {
			$first_run = $schedule['first_run'];
			$next_scheduled_time = wp_next_scheduled( 'backupbuddy_cron', array( 'run_scheduled_backup', array( (int)$schedule_id ) ) );
			backupbuddy_core::unschedule_event( $next_scheduled_time, 'backupbuddy_cron', array( 'run_scheduled_backup', array( (int)$schedule_id ) ) );
			backupbuddy_core::schedule_event( $first_run, $schedule['interval'], 'run_scheduled_backup', array( (int)$schedule_id ) ); // Add new schedule.
			pb_backupbuddy::$options['schedules'][$schedule_id] = $schedule;
		}
		pb_backupbuddy::save();
		$information['result'] = 'SUCCESS';
		$information['schedules'] = pb_backupbuddy::$options['schedules'];
		$information['next_schedule_index'] = pb_backupbuddy::$options['next_schedule_index'];
		$information['schedules_run_time'] = $this->get_schedules_run_time();
		return $information;
	}


	function save_profile() {
		$profile_id = $_POST['profile_id'];
		$profile = unserialize(base64_decode($_POST['data']));

		if (!is_array($profile)) {
			return array('error' => __( 'Invalid profile data', 'mainwp-child' ));
		}

		pb_backupbuddy::$options['profiles'][$profile_id] = $profile;
		pb_backupbuddy::save();

		$information['result'] = 'SUCCESS';
		return $information;
	}


	function delete_profile() {
		$profile_id = $_POST['profile_id'];

		if (isset(pb_backupbuddy::$options['profiles'][$profile_id]))
			unset(pb_backupbuddy::$options['profiles'][$profile_id]);

		pb_backupbuddy::save();
		$information['result'] = 'SUCCESS';
		return $information;
	}

	function delete_backup( $type = 'default', $subsite_mode = false ) {
		$item_ids = $_POST['item_ids'];
		$item_ids = explode(',', $item_ids);
		$information = array();
		if ( is_array( $item_ids ) && count( $item_ids ) > 0 ) {
			$needs_save = false;
			$deleted_files = array();
			foreach( $item_ids as $item ) {
				if ( file_exists( backupbuddy_core::getBackupDirectory() . $item ) ) {
					if ( @unlink( backupbuddy_core::getBackupDirectory() . $item ) === true ) {
						$deleted_files[] = $item;

						// Cleanup any related fileoptions files.
						$serial = backupbuddy_core::get_serial_from_file( $item );

						$backup_files = glob( backupbuddy_core::getBackupDirectory() . '*.zip' );
						if ( ! is_array( $backup_files ) ) {
							$backup_files = array();
						}
						if ( count( $backup_files ) > 5 ) { // Keep a minimum number of backups in array for stats.
							$this_serial = backupbuddy_core::get_serial_from_file( $item );
							$fileoptions_file = backupbuddy_core::getLogDirectory() . 'fileoptions/' . $this_serial . '.txt';
							if ( file_exists( $fileoptions_file ) ) {
								@unlink( $fileoptions_file );
							}
							if ( file_exists( $fileoptions_file . '.lock' ) ) {
								@unlink( $fileoptions_file . '.lock' );
							}
							$needs_save = true;
						}
					}
				} // End if file exists.
			} // End foreach.
			if ( $needs_save === true ) {
				pb_backupbuddy::save();
			}

			$information['result'] = 'SUCCESS';

		} // End if deleting backup(s).
		return $information;
	}

    // ok
	public function syncOthersData( $information, $data = array() ) {
        if ( isset( $data['syncBackupBuddy'] ) && $data['syncBackupBuddy'] ) {
            try {
                $information['syncBackupBuddy'] = $this->get_sync_data();
            } catch(Exception $e) {

            }
        }
		return $information;
	}

    // ok
	public function get_sync_data() {

        try {
            if ( ! class_exists( 'backupbuddy_core' ) ) {
                MainWP_Helper::check_classes_exists('pb_backupbuddy');
                MainWP_Helper::check_methods('pb_backupbuddy', array( 'plugin_path' ) );

                $plugin_path = pb_backupbuddy::plugin_path();
                if (file_exists($plugin_path . '/classes/core.php'))
                    require_once( $plugin_path . '/classes/core.php' );
            }

            MainWP_Helper::check_classes_exists(array( 'backupbuddy_core', 'backupbuddy_api' ));
            MainWP_Helper::check_methods('backupbuddy_core', array( 'get_plugins_root', 'get_themes_root', 'get_media_root'  ) );
            MainWP_Helper::check_methods('backupbuddy_api', array( 'getOverview' ) );



            $data = array();
            $data['plugins_root'] =  backupbuddy_core::get_plugins_root();
            $data['themes_root'] =  backupbuddy_core::get_themes_root();
            $data['media_root'] =  backupbuddy_core::get_media_root();
            $data['additional_tables'] = $this->pb_additional_tables();
            $data['abspath'] =  ABSPATH;

            $getOverview = backupbuddy_api::getOverview();
            $data['editsSinceLastBackup'] =  $getOverview['editsSinceLastBackup'] ;

            if ( isset( $getOverview['lastBackupStats']['finish'] ) ) {
                $finish_time = $getOverview['lastBackupStats']['finish'] ;
                $time = $this->localize_time( $finish_time );
                $data['lastBackupStats'] = date("M j - g:i A", $time);
                $data['lasttime_backup'] = $finish_time;
                MainWP_Helper::update_lasttime_backup('backupbuddy', $finish_time); // to support Require Backup Before Update feature
            } else {
                $data['lastBackupStats'] = 'Unknown';
            }

            return $data;
        } catch(Exception $e) {
          // not exit here
        }

		return false;
	}

    function localize_time( $timestamp ) {
		if ( function_exists( 'get_option' ) ) {
			$gmt_offset = get_option( 'gmt_offset' );
		} else {
			$gmt_offset = 0;
		}
		return $timestamp + ( $gmt_offset * 3600 );
	}

	function backup_list() {
		require_once( pb_backupbuddy::plugin_path() . '/destinations/bootstrap.php' );
		$information = array();
		$information['backup_list'] = $this->get_backup_list();
		$information['recent_backup_list'] = $this->get_recent_backup_list();
		//$information['destinations_list'] = pb_backupbuddy_destinations::get_destinations_list();
		$backup_directory = backupbuddy_core::getBackupDirectory();
		$backup_directory = str_replace( '\\', '/', $backup_directory );
		$backup_directory = rtrim( $backup_directory, '/\\' ) . '/';
		$information['backupDirectoryWithinSiteRoot'] = (FALSE !== stristr( $backup_directory, ABSPATH )) ? 'yes' : 'no';
		$information['result'] = 'SUCCESS';
		return $information;
	}

	function save_note() {
		if ( !isset( pb_backupbuddy::$classes['zipbuddy'] ) ) {
			require_once( pb_backupbuddy::plugin_path() . '/lib/zipbuddy/zipbuddy.php' );
			pb_backupbuddy::$classes['zipbuddy'] = new pluginbuddy_zipbuddy( backupbuddy_core::getBackupDirectory() );
		}
		$backup_file = $_POST['backup_file'];
		$note = $_POST['note'];
		$note = preg_replace( "/[[:space:]]+/", ' ', $note );
		$note = preg_replace( "/[^[:print:]]/", '', $note );
		$note = substr( $note, 0, 200 );


		// Returns true on success, else the error message.
		$old_comment = pb_backupbuddy::$classes['zipbuddy']->get_comment( $backup_file );
		$comment = backupbuddy_core::normalize_comment_data( $old_comment );
		$comment['note'] = $note;

		//$new_comment = base64_encode( serialize( $comment ) );

		$comment_result = pb_backupbuddy::$classes['zipbuddy']->set_comment( $backup_file, $comment );

		$information = array();
		if ( $comment_result === true ) {
			$information['result'] = 'SUCCESS';
		}

		// Even if we cannot save the note into the archive file, store it in internal settings.
		$serial = backupbuddy_core::get_serial_from_file( $backup_file );

		require_once( pb_backupbuddy::plugin_path() . '/classes/fileoptions.php' );
		pb_backupbuddy::status( 'details', 'Fileoptions instance #24.' );
		$backup_options = new pb_backupbuddy_fileoptions( backupbuddy_core::getLogDirectory() . 'fileoptions/' . $serial . '.txt' );
		if ( true === ( $result = $backup_options->is_ok() ) ) {
			$backup_options->options['integrity']['comment'] = $note;
			$backup_options->save();
		}
		return $information;
	}

	function get_hash() {
		$callback_data = $_POST['callback_data'];
		$file = backupbuddy_core::getBackupDirectory() . $callback_data;
		if (file_exists($file))
			return array( 'result' =>'SUCCESS', 'hash' => md5_file( $file ) );
		else
			return array( 'error' =>'Not found the file' );
	}

	function zip_viewer() {

		// How long to cache the specific backup file tree information for (seconds)
		$max_cache_time = 86400;

		// This is the root directory we want the listing for
		$root = $_POST[ 'dir' ];
		$root_len = strlen( $root );

		// This will identify the backup zip file we want to list
		$serial = $_POST[ 'serial' ];
		$alerts = array();
		// The fileoptions file that contains the file tree information
		require_once( pb_backupbuddy::plugin_path() . '/classes/fileoptions.php' );
		$fileoptions_file = backupbuddy_core::getLogDirectory() . 'fileoptions/' . $serial . '-filetree.txt';

		// Purge cache if too old.
		if ( file_exists( $fileoptions_file ) && ( ( time() - filemtime( $fileoptions_file ) ) > $max_cache_time ) ) {
			if ( false === unlink( $fileoptions_file ) ) {
				$alerts[] = 'Error #456765545. Unable to wipe cached fileoptions file `' . $fileoptions_file . '`.' ;
			}
		}

		pb_backupbuddy::status( 'details', 'Fileoptions instance #28.' );
		$fileoptions = new pb_backupbuddy_fileoptions( $fileoptions_file );
		$zip_viewer = $_POST[ 'zip_viewer' ];
		// Either we are getting cached file tree information or we need to create afresh
		if ( true !== ( $result = $fileoptions->is_ok() ) ) {
			// Get file listing.
			require_once( pb_backupbuddy::plugin_path() . '/lib/zipbuddy/zipbuddy.php' );
			pb_backupbuddy::$classes['zipbuddy'] = new pluginbuddy_zipbuddy( ABSPATH, array(), 'unzip' );
			$files = pb_backupbuddy::$classes['zipbuddy']->get_file_list( backupbuddy_core::getBackupDirectory() . str_replace( '\\/', '', $zip_viewer ) );
			$fileoptions->options = $files;
			$fileoptions->save();
		} else {
			$files = &$fileoptions->options;
		}

		// Just make sure we have a sensible files listing
		if ( ! is_array( $files ) ) {
			return array( 'error' => 'Error #548484.  Unable to retrieve file listing from backup file `' . htmlentities( $zip_viewer ) . '`.' );
		}

		// To record subdirs of this root
		$subdirs = array();

		// Strip out any files/subdirs that are not actually directly under the given root
		foreach( $files as $key => $file ) {

			// If shorter than root length then certainly is not within this (root) directory.
			// It's a quick test that is more effective the longer the root (the deeper you go
			// into the tree)
			if ( strlen( $file[ 0 ] ) < $root_len ) {

				unset( $files[ $key ] );
				continue;

			}

			// The root must be prefix of this file	otherwise it's not under the root
			// e.g., with root=this/dir/path/
			// these will fail: file=this/dir/file; file=this/dir/otherpath/; file=that/dir/path/file
			// and these would succeed: file=this/dir/path/; file=this/dir/path/file; file=this/dir/path/otherpath/
			if ( substr( $file[ 0 ], 0, $root_len ) != $root ) {

				unset( $files[ $key ] );
				continue;

			}

			// If the file _is_ the root then we don't want to list it
			// Don't want to do this on _every_ file as very specific so do it here after we have
			// weeded out files for more common reasons
			if ( 0 == strcmp( $file[ 0 ], $root ) ) {

				unset( $files[ $key ] );
				continue;

			}

			// Interesting file, get the path with the root prefix removed
			// Note: root may be empty in which case the result will be the original filename
			$unrooted_file = substr( $file[ 0 ], $root_len );

			// We must ensure that we list the subdir/ even if subdir/ does not appear
			// as a distinct entry in the list but only subdir/file or subdir/subsubdir/ or
			// subdir/subsubdir/file. Find if we have any directory separator(s) in the filename
			// and if so remember where the first is
			if ( false !== ( $pos = strpos( $unrooted_file, '/' ) ) ) {

				// Get the subdir/ prefix part, discarding everything after the first /
				$subdir = substr( $unrooted_file, 0, ( $pos + 1 ) );

				// Have we already seen it
				if ( !in_array( $subdir, $subdirs ) ) {

					// Not already seen so record we have seen it and modify this entry to be
					// specific for the subdir/
					$subdirs[] = $subdir;

					// Replace the original (rooted) file name
					$files[ $key ][ 0 ] = $subdir;

				} else {

					// We already know about the subdir/ so remove this entry
					unset( $files[ $key ] );
					continue;

				}

			} else {

				// This is just like file within the root
				// Replace the original (rooted) file name
				$files[ $key ][ 0 ] = $unrooted_file;

			}

		}

		return array('result' => 'SUCCESS', 'files' => $files, 'message' => implode('<br/>', $alerts));

	}

	function exclude_tree() {
		$root = substr( ABSPATH, 0, strlen( ABSPATH ) - 1 ) . '/' . ltrim( urldecode( $_POST['dir'] ), '/\\' );
		if( file_exists( $root ) ) {
			$files = scandir( $root );

			natcasesort( $files );

			// Sort with directories first.
			$sorted_files = array(); // Temporary holder for sorting files.
			$sorted_directories = array(); // Temporary holder for sorting directories.
			foreach( $files as $file ) {
				if ( ( $file == '.' ) || ( $file == '..' ) ) {
					continue;
				}
				if( is_file( str_replace( '//', '/', $root . $file ) ) ) {
					array_push( $sorted_files, $file );
				} else {
					array_unshift( $sorted_directories, $file );
				}
			}
			$files = array_merge( array_reverse( $sorted_directories ), $sorted_files );
			unset( $sorted_files );
			unset( $sorted_directories );
			unset( $file );

			ob_start();

			if( count( $files ) > 0 ) { // Files found.
				echo '<ul class="jqueryFileTree" style="display: none;">';
				foreach( $files as $file ) {
					if( file_exists( str_replace( '//', '/', $root . $file ) ) ) {
						if ( is_dir( str_replace( '//', '/', $root . $file ) ) ) { // Directory.
							echo '<li class="directory collapsed">';
							$return = '';
							$return .= '<div class="pb_backupbuddy_treeselect_control">';
							$return .= '<img src="' . pb_backupbuddy::plugin_url() . '/images/redminus.png" style="vertical-align: -3px;" title="Add to exclusions..." class="pb_backupbuddy_filetree_exclude">';
							$return .= '</div>';
							echo '<a href="#" rel="' . htmlentities( str_replace( ABSPATH, '', $root ) . $file) . '/" title="Toggle expand...">' . htmlentities($file) . $return . '</a>';
							echo '</li>';
						} else { // File.
							echo '<li class="file collapsed">';
							$return = '';
							$return .= '<div class="pb_backupbuddy_treeselect_control">';
							$return .= '<img src="' . pb_backupbuddy::plugin_url() . '/images/redminus.png" style="vertical-align: -3px;" title="Add to exclusions..." class="pb_backupbuddy_filetree_exclude">';
							$return .= '</div>';
							echo '<a href="#" rel="' . htmlentities( str_replace( ABSPATH, '', $root ) . $file) . '">' . htmlentities($file) . $return . '</a>';
							echo '</li>';
						}
					}
				}
				echo '</ul>';
			} else {
				echo '<ul class="jqueryFileTree" style="display: none;">';
				echo '<li><a href="#" rel="' . htmlentities( pb_backupbuddy::_POST( 'dir' ) . 'NONE' ) . '"><i>Empty Directory ...</i></a></li>';
				echo '</ul>';
			}

			$html = ob_get_clean();
			return array('result' => $html) ;
		} else {
			return array('error' => 'Error #1127555. Unable to read child site root.') ;
		}

	}

    // ok
	function pb_additional_tables( $display_size = false ) {

        MainWP_Helper::check_classes_exists('pb_backupbuddy');
        MainWP_Helper::check_methods('pb_backupbuddy', 'plugin_url');
        MainWP_Helper::check_properties('pb_backupbuddy', 'format');
        MainWP_Helper::check_methods(pb_backupbuddy::$format, 'file_size');


		$return = '';
		$size_string = '';

		global $wpdb;
		if ( true === $display_size ) {
			$results = $wpdb->get_results( "SHOW TABLE STATUS", ARRAY_A );
		} else {
			$results = $wpdb->get_results( "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()", ARRAY_A );
		}
		foreach( $results as $result ) {

			if ( true === $display_size ) {
				// Fix up row count and average row length for InnoDB engine which returns inaccurate (and changing) values for these.
				if ( 'InnoDB' === $result[ 'Engine' ] ) {
					if ( false !== ( $rowCount = $wpdb->get_var( "SELECT COUNT(1) as rowCount FROM `{$rs[ 'Name' ]}`", ARRAY_A ) ) ) {
						if ( 0 < ( $result[ 'Rows' ] = $rowCount ) ) {
							$result[ 'Avg_row_length' ] = ( $result[ 'Data_length' ] / $result[ 'Rows' ] );
						}
					}
					unset( $rowCount );
				}

				// Table size.
				$size_string = ' (' . pb_backupbuddy::$format->file_size( ( $result['Data_length'] + $result['Index_length'] ) ) . ') ';

			} // end if display size enabled.

			$return .= '<li class="file ext_sql collapsed">';
			$return .= '<a rel="/" alt="' . $result['table_name'] . '">' . $result['table_name'] . $size_string;
			$return .= '<div class="pb_backupbuddy_treeselect_control">';
			$return .= '<img src="' . pb_backupbuddy::plugin_url() . '/images/redminus.png" style="vertical-align: -3px;" title="Add to exclusions..." class="pb_backupbuddy_table_addexclude"> <img src="' . pb_backupbuddy::plugin_url() . '/images/greenplus.png" style="vertical-align: -3px;" title="Add to inclusions..." class="pb_backupbuddy_table_addinclude">';
			$return .= '</div>';
			$return .= '</a>';
			$return .= '</li>';
		}

		return '<div class="jQueryOuterTree" style="position: absolute; height: 160px;"><ul class="jqueryFileTree">' . $return . '</ul></div>';
	}

	function restore_file_view() {

		$archive_file = $_POST[ 'archive' ]; // archive to extract from.
		$file = $_POST[  'file' ]; // file to extract.
		$serial = backupbuddy_core::get_serial_from_file( $archive_file ); // serial of archive.
		$temp_file = uniqid(); // temp filename to extract into.

		require_once( pb_backupbuddy::plugin_path() . '/lib/zipbuddy/zipbuddy.php' );
		$zipbuddy = new pluginbuddy_zipbuddy( backupbuddy_core::getBackupDirectory() );

		// Calculate temp directory & lock it down.
		$temp_dir = get_temp_dir();
		$destination = $temp_dir . 'backupbuddy-' . $serial;
		if ( ( ( ! file_exists( $destination ) ) && ( false === mkdir( $destination ) ) ) ) {
			$error = 'Error #458485945b: Unable to create temporary location.';
			pb_backupbuddy::status( 'error', $error );
			return array('error' => $error);
		}

		// If temp directory is within webroot then lock it down.
		$temp_dir = str_replace( '\\', '/', $temp_dir ); // Normalize for Windows.
		$temp_dir = rtrim( $temp_dir, '/\\' ) . '/'; // Enforce single trailing slash.
		if ( FALSE !== stristr( $temp_dir, ABSPATH ) ) { // Temp dir is within webroot.
			pb_backupbuddy::anti_directory_browsing( $destination );
		}
		unset( $temp_dir );

		$message = 'Extracting "' . $file . '" from archive "' . $archive_file . '" into temporary file "' . $destination . '". ';
		//echo '<!-- ';
		pb_backupbuddy::status( 'details', $message );
		//echo $message;

		$file_content = '';

		$extractions = array( $file => $temp_file );
		$extract_result = $zipbuddy->extract( backupbuddy_core::getBackupDirectory() . $archive_file, $destination, $extractions );
		if ( false === $extract_result ) { // failed.
			//echo ' -->';
			$error = 'Error #584984458. Unable to extract.';
			pb_backupbuddy::status( 'error', $error );
			return array( 'error' => $error );
		} else { // success.
			$file_content = file_get_contents( $destination . '/' . $temp_file );
		}

		// Try to cleanup.
		if ( file_exists( $destination ) ) {
			if ( false === pb_backupbuddy::$filesystem->unlink_recursive( $destination ) ) {
				pb_backupbuddy::status( 'details', 'Unable to delete temporary holding directory `' . $destination . '`.' );
			} else {
				pb_backupbuddy::status( 'details', 'Cleaned up temporary files.' );
			}
		}

		return array( 'result' => 'SUCCESS', 'file_content' => $file_content );
	}


	function restore_file_restore() {

		$files = $_POST[ 'files' ]; // file to extract.
		$archive_file = $_POST[ 'archive' ]; // archive to extract from.

		$files_array = explode( ',', $files );
		$files = array();
		foreach( $files_array as $file ) {
			if ( substr( $file, -1 ) == '/' ) { // If directory then add wildcard.
				$file = $file . '*';
			}
			$files[$file] = $file;
		}
		unset( $files_array );

		$success = false;

		global $pb_backupbuddy_js_status;
		$pb_backupbuddy_js_status = true;
		pb_backupbuddy::set_status_serial( 'restore' );
		global $wp_version;
		pb_backupbuddy::status( 'details', 'BackupBuddy v' . pb_backupbuddy::settings( 'version' ) . ' using WordPress v' . $wp_version . ' on ' . PHP_OS . '.' );

		require( pb_backupbuddy::plugin_path() . '/classes/_restoreFiles.php' );

		ob_start();
		$result = backupbuddy_restore_files::restore( backupbuddy_core::getBackupDirectory() . $archive_file, $files, $finalPath = ABSPATH );
		$restore_result = ob_get_clean();
		pb_backupbuddy::flush();
		return array('restore_result' => $restore_result);
	}

	function get_backup_list( $type = 'default', $subsite_mode = false ) {
		$backups = array();
		$backup_sort_dates = array();

		$files = glob( backupbuddy_core::getBackupDirectory() . 'backup*.zip' );
		if ( ! is_array( $files ) ) {
			$files = array();
		}

		$files2 = glob( backupbuddy_core::getBackupDirectory() . 'snapshot*.zip' );
		if ( ! is_array( $files2 ) ) {
			$files2 = array();
		}

		$files = array_merge( $files, $files2 );

		if ( is_array( $files ) && !empty( $files ) ) { // For robustness. Without open_basedir the glob() function returns an empty array for no match. With open_basedir in effect the glob() function returns a boolean false for no match.

			$backup_prefix = backupbuddy_core::backup_prefix(); // Backup prefix for this site. Used for MS checking that this user can see this backup.
			foreach( $files as $file_id => $file ) {

				if ( ( $subsite_mode === true ) && is_multisite() ) { // If a Network and NOT the superadmin must make sure they can only see the specific subsite backups for security purposes.

					// Only allow viewing of their own backups.
					if ( ! strstr( $file, $backup_prefix ) ) {
						unset( $files[$file_id] ); // Remove this backup from the list. This user does not have access to it.
						continue; // Skip processing to next file.
					}
				}

				$serial = backupbuddy_core::get_serial_from_file( $file );

				$options = array();
				if ( file_exists( backupbuddy_core::getLogDirectory() . 'fileoptions/' . $serial . '.txt' ) ) {
					require_once( pb_backupbuddy::plugin_path() . '/classes/fileoptions.php' );
					pb_backupbuddy::status( 'details', 'Fileoptions instance #33.' );
					$backup_options = new pb_backupbuddy_fileoptions( backupbuddy_core::getLogDirectory() . 'fileoptions/' . $serial . '.txt', $read_only = false, $ignore_lock = false, $create_file = true ); // Will create file to hold integrity data if nothing exists.
				} else {
					$backup_options = '';
				}
				$backup_integrity = backupbuddy_core::backup_integrity_check( $file, $backup_options, $options );

				// Backup status.
				$pretty_status = array(
					true	=>	'<span class="pb_label pb_label-success">Good</span>', // v4.0+ Good.
					'pass'	=>	'<span class="pb_label pb_label-success">Good</span>', // Pre-v4.0 Good.
					false	=>	'<span class="pb_label pb_label-important">Bad</span>',  // v4.0+ Bad.
					'fail'	=>	'<span class="pb_label pb_label-important">Bad</span>',  // Pre-v4.0 Bad.
				);

				// Backup type.
				$pretty_type = array(
					'full'		=>	'Full',
					'db'		=>	'Database',
					'files'		=>	'Files',
					'themes'	=>	'Themes',
					'plugins'	=>	'Plugins',
				);


				// Defaults...
				$detected_type = '';
				$file_size = '';
				$modified = '';
				$modified_time = 0;
				$integrity = '';

				$main_string = 'Warn#284.';
				if ( is_array( $backup_integrity ) ) { // Data intact... put it all together.
					// Calculate time ago.
					$time_ago = '';
					if ( isset( $backup_integrity['modified'] ) ) {
						$time_ago = pb_backupbuddy::$format->time_ago( $backup_integrity['modified'] ) . ' ago';
					}

					$detected_type = pb_backupbuddy::$format->prettify( $backup_integrity['detected_type'], $pretty_type );
					if ( $detected_type == '' ) {
						$detected_type = backupbuddy_core::pretty_backup_type( backupbuddy_core::getBackupTypeFromFile( $file ) );
						if ( '' == $detected_type ) {
							$detected_type = '<span class="description">Unknown</span>';
						}
					} else {
						if ( isset( $backup_options->options['profile'] ) ) {
							$detected_type = '
							<div>
								<span style="color: #AAA; float: left;">' . $detected_type . '</span>
								<span style="display: inline-block; float: left; height: 15px; border-right: 1px solid #EBEBEB; margin-left: 6px; margin-right: 6px;"></span>
								' . htmlentities( $backup_options->options['profile']['title'] ) . '
							</div>
							'
							;
						}
					}

					$file_size = pb_backupbuddy::$format->file_size( $backup_integrity['size'] );
					$modified = pb_backupbuddy::$format->date( pb_backupbuddy::$format->localize_time( $backup_integrity['modified'] ), 'l, F j, Y - g:i:s a' );
					$modified_time = $backup_integrity['modified'];
					if ( isset( $backup_integrity['status'] ) ) { // Pre-v4.0.
						$status = $backup_integrity['status'];
					} else { // v4.0+
						$status = $backup_integrity['is_ok'];
					}


					// Calculate main row string.
					if ( $type == 'default' ) { // Default backup listing.
						$download_url = '/wp-admin/admin-ajax.php?action=mainwp_backupbuddy_download_archive&backupbuddy_backup=' . basename( $file ) . '&_wpnonce=' . MainWP_Helper::create_nonce_without_session( 'mainwp_download_backup' );
						$main_string = '<a href="#" download-url="' . $download_url . '"class="backupbuddyFileTitle mwp_bb_download_backup_lnk" title="' . basename( $file ) . '">' . $modified . ' (' . $time_ago . ')</a>';
					} elseif ( $type == 'migrate' ) { // Migration backup listing.
						$main_string = '<a class="pb_backupbuddy_hoveraction_migrate backupbuddyFileTitle" rel="' . basename( $file ) . '" href="' . pb_backupbuddy::page_url() . '&migrate=' . basename( $file ) . '&value=' . basename( $file ) . '" title="' . basename( $file ) . '">' . $modified . ' (' . $time_ago . ')</a>';
					} else {
						$main_string = '{Unknown type.}';
					}
					// Add comment to main row string if applicable.
					if ( isset( $backup_integrity['comment'] ) && ( $backup_integrity['comment'] !== false ) && ( $backup_integrity['comment'] !== '' ) ) {
						$main_string .= '<br><span class="description">Note: <span class="pb_backupbuddy_notetext">' . htmlentities( $backup_integrity['comment'] ) . '</span></span>';
					}


					$integrity = pb_backupbuddy::$format->prettify( $status, $pretty_status ) . ' ';
					if ( isset( $backup_integrity['scan_notes'] ) && count( (array)$backup_integrity['scan_notes'] ) > 0 ) {
						foreach( (array)$backup_integrity['scan_notes'] as $scan_note ) {
							$integrity .= $scan_note . ' ';
						}
					}
					$integrity .= '<a href="#" serial="' . $serial  . '" class="mwp_bb_reset_integrity_lnk" file-name="' . basename( $file ) . '" title="Rescan integrity. Last checked ' . pb_backupbuddy::$format->date( $backup_integrity['scan_time'] ) . '."> <i class="fa fa-refresh" aria-hidden="true"></i></a>';
					$integrity .= '<div class="row-actions"><a title="' . __( 'Backup Status', 'mainwp-child' ) . '" href="#" serial="' . $serial . '" class="mainwp_bb_view_details_lnk thickbox">' . __( 'View Details', 'mainwp-child' ) . '</a></div>';

					$sumLogFile = backupbuddy_core::getLogDirectory() . 'status-' . $serial . '_' . pb_backupbuddy::$options['log_serial'] . '.txt';
					if ( file_exists( $sumLogFile ) ) {
						$integrity .= '<div class="row-actions"><a title="' . __( 'View Backup Log', 'mainwp-child' ) . '" href="#" serial="' . $serial . '" class="mainwp_bb_view_log_lnk thickbox">' . __( 'View Log', 'mainwp-child' ) . '</a></div>';
					}

				} // end if is_array( $backup_options ).

				// No integrity check for themes or plugins types.
				$raw_type = backupbuddy_core::getBackupTypeFromFile( $file );
				if ( ( 'themes' == $raw_type ) || ( 'plugins' == $raw_type ) ) {
					$integrity = 'n/a';
				}

				$backups[basename( $file )] = array(
					array( basename( $file ), $main_string . '<br><span class="description" style="color: #AAA; display: inline-block; margin-top: 5px;">' . basename( $file ) . '</span>' ),
					$detected_type,
					$file_size,
					$integrity,
				);


				$backup_sort_dates[basename( $file)] = $modified_time;

			} // End foreach().

		} // End if.

		// Sort backup by date.
		arsort( $backup_sort_dates );
		// Re-arrange backups based on sort dates.
		$sorted_backups = array();
		foreach( $backup_sort_dates as $backup_file => $backup_sort_date ) {
			$sorted_backups[$backup_file] = $backups[$backup_file];
			unset( $backups[$backup_file] );
		}
		unset( $backups );
		return $sorted_backups;

	} // End backups_list().

	function get_recent_backup_list () {
		$recentBackups_list = glob( backupbuddy_core::getLogDirectory() . 'fileoptions/*.txt' );
		if ( ! is_array( $recentBackups_list ) ) {
			$recentBackups_list = array();
		}

		$recentBackups = array();
		if ( count( $recentBackups_list ) > 0 ) {

			// Backup type.
			$pretty_type = array(
				'full'	=>	'Full',
				'db'	=>	'Database',
				'files' =>	'Files',
			);

			foreach( $recentBackups_list as $backup_fileoptions ) {

				require_once( pb_backupbuddy::plugin_path() . '/classes/fileoptions.php' );
				pb_backupbuddy::status( 'details', 'Fileoptions instance #1.' );
				$backup = new pb_backupbuddy_fileoptions( $backup_fileoptions, $read_only = true );
				if ( true !== ( $result = $backup->is_ok() ) ) {
					pb_backupbuddy::status( 'error', __('Unable to access fileoptions data file.', 'mainwp-child' ) . ' Error: ' . $result );
					continue;
				}
				$backup = &$backup->options;

				if ( !isset( $backup['serial'] ) || ( $backup['serial'] == '' ) ) {
					continue;
				}
				if ( ( $backup['finish_time'] >= $backup['start_time'] ) && ( 0 != $backup['start_time'] ) ) {
					$status = '<span class="pb_label pb_label-success">Completed</span>';
				} elseif ( $backup['finish_time'] == -1 ) {
					$status = '<span class="pb_label pb_label-warning">Cancelled</span>';
				} elseif ( FALSE === $backup['finish_time'] ) {
					$status = '<span class="pb_label pb_label-error">Failed (timeout?)</span>';
				} elseif ( ( time() - $backup['updated_time'] ) > backupbuddy_constants::TIME_BEFORE_CONSIDERED_TIMEOUT ) {
					$status = '<span class="pb_label pb_label-error">Failed (likely timeout)</span>';
				} else {
					$status = '<span class="pb_label pb_label-warning">In progress or timed out</span>';
				}
				$status .= '<br>';


				// Technical details link.
				$status .= '<div class="row-actions">';
				$status .= '<a title="' . __( 'Backup Process Technical Details', 'mainwp-child' ) . '" href="#" serial="' . $backup['serial'] . '" class="mainwp_bb_view_details_lnk thickbox">View Details</a>';

				$sumLogFile = backupbuddy_core::getLogDirectory() . 'status-' . $backup['serial'] . '_' . pb_backupbuddy::$options['log_serial'] . '.txt';
				if ( file_exists( $sumLogFile ) ) {
					$status .= '<div class="row-actions"><a title="' . __( 'View Backup Log', 'mainwp-child' ) . '" href="#" serial="' . $backup['serial'] . '"  class="mainwp_bb_view_log_lnk thickbox">' . __( 'View Log', 'mainwp-child' ) . '</a></div>';
				}

				$status .= '</div>';

				// Calculate finish time (if finished).
				if ( $backup['finish_time'] > 0 ) {
					$finish_time = pb_backupbuddy::$format->date( pb_backupbuddy::$format->localize_time( $backup['finish_time'] ) ) . '<br><span class="description">' . pb_backupbuddy::$format->time_ago( $backup['finish_time'] ) . ' ago</span>';
				} else { // unfinished.
					$finish_time = '<i>Unfinished</i>';
				}

				$backupTitle = '<span class="backupbuddyFileTitle" style="color: #000;" title="' . basename( $backup['archive_file'] ) . '">' . pb_backupbuddy::$format->date( pb_backupbuddy::$format->localize_time( $backup['start_time'] ), 'l, F j, Y - g:i:s a' ) . ' (' . pb_backupbuddy::$format->time_ago( $backup['start_time'] ) . ' ago)</span><br><span class="description">' . basename( $backup['archive_file'] ) . '</span>';

				if ( isset( $backup['profile'] ) && isset( $backup['profile']['type'] ) ) {
					$backupType = '<div>
						<span style="color: #AAA; float: left;">' . pb_backupbuddy::$format->prettify( $backup['profile']['type'], $pretty_type ) . '</span>
						<span style="display: inline-block; float: left; height: 15px; border-right: 1px solid #EBEBEB; margin-left: 6px; margin-right: 6px;"></span>'
					              . $backup['profile']['title'] .
					              '</div>';
				} else {
					$backupType = backupbuddy_core::pretty_backup_type( backupbuddy_core::getBackupTypeFromFile( $backup['archive_file'] ) );
					if ( '' == $backupType ) {
						$backupType = '<span class="description">Unknown</span>';
					}
				}

				if ( isset( $backup['archive_size'] ) && ( $backup['archive_size'] > 0 ) ) {
					$archive_size = pb_backupbuddy::$format->file_size( $backup['archive_size'] );
				} else {
					$archive_size = 'n/a';
				}

				// No integrity check for themes or plugins types.
				$raw_type = backupbuddy_core::getBackupTypeFromFile( $backup['archive_file'] );
				if ( ( 'themes' == $raw_type ) || ( 'plugins' == $raw_type ) ) {
					$status = 'n/a';
				}

				// Append to list.
				$recentBackups[ $backup['serial'] ] = array(
					array( basename( $backup['archive_file'] ), $backupTitle ),
					$backupType,
					$archive_size,
					ucfirst( $backup['trigger'] ),
					$status,
					'start_timestamp' => $backup['start_time'], // Used by array sorter later to put backups in proper order.
				);

			}

			$columns = array(
				__('Recently Made Backups (Start Time)', 'mainwp-child' ),
				__('Type | Profile', 'mainwp-child' ),
				__('File Size', 'mainwp-child' ),
				__('Trigger', 'mainwp-child' ),
				__('Status', 'mainwp-child' ) . ' <span class="description">(hover for options)</span>',
			);

			function pb_backupbuddy_aasort (&$array, $key) {
				$sorter=array();
				$ret=array();
				reset($array);
				foreach ($array as $ii => $va) {
					$sorter[$ii]=$va[$key];
				}
				asort($sorter);
				foreach ($sorter as $ii => $va) {
					$ret[$ii]=$array[$ii];
				}
				$array=$ret;
			}

			pb_backupbuddy_aasort( $recentBackups, 'start_timestamp' ); // Sort by multidimensional array with key start_timestamp.
			$recentBackups = array_reverse( $recentBackups ); // Reverse array order to show newest first.
		}

		return $recentBackups;
	}

	function delete_scheduled_backup() {
		$schedule_ids =  $_POST['schedule_ids'];
		$schedule_ids = explode(',', $schedule_ids);

		if (empty($schedule_ids)) {
			return array('error' => __( 'Empty schedule ids', 'mainwp-child' ));
		}
		foreach ($schedule_ids as $sch_id) {
			if ( isset( pb_backupbuddy::$options['schedules'][$sch_id] ) ) {
				unset( pb_backupbuddy::$options['schedules'][$sch_id] );
			}
		}
		pb_backupbuddy::save();
		$information['result'] = 'SUCCESS';
		return $information;
	}

	function view_log() {
		$serial = $_POST[ 'serial' ];
		$logFile = backupbuddy_core::getLogDirectory() . 'status-' . $serial . '_sum_' . pb_backupbuddy::$options['log_serial'] . '.txt';

		if ( ! file_exists( $logFile ) ) {
			return array('error' => 'Error #858733: Log file `' . $logFile . '` not found or access denied.' );
		}

		$lines = file_get_contents( $logFile );
		$lines = explode( "\n", $lines );
		ob_start();
		?>

		<textarea readonly="readonly" id="backupbuddy_messages" wrap="off" style="width: 100%; min-height: 400px; height: 500px; height: 80%; background: #FFF;"><?php
			foreach( (array)$lines as $rawline ) {
				$line = json_decode( $rawline, true );
				//print_r( $line );
				if ( is_array( $line ) ) {
					$u = '';
					if ( isset( $line['u'] ) ) { // As off v4.2.15.6. TODO: Remove this in a couple of versions once old logs without this will have cycled out.
						$u = '.' . $line['u'];
					}
					echo pb_backupbuddy::$format->date( $line['time'], 'G:i:s' ) . $u . "\t\t";
					echo $line['run'] . "sec\t";
					echo $line['mem'] . "MB\t";
					echo $line['event'] . "\t";
					echo $line['data'] . "\n";
				} else {
					echo $rawline . "\n";
				}
			}
			?></textarea><br><br>
		<small>Log file: <?php echo $logFile; ?></small>
		<br>
		<?php
		echo '<small>Last modified: ' . pb_backupbuddy::$format->date( filemtime( $logFile ) ) . ' (' . pb_backupbuddy::$format->time_ago( filemtime( $logFile ) ) . ' ago)';
		?>
		<br><br>
		<?php
		$html = ob_get_clean();
		pb_backupbuddy::flush();
		return array('result' => 'SUCCESS', 'html_log' => $html);
	}

	function view_detail() {

		$serial = $_POST['serial'];
		$serial = str_replace( '/\\', '', $serial );
		pb_backupbuddy::load();

		require_once( pb_backupbuddy::plugin_path() . '/classes/fileoptions.php' );
		pb_backupbuddy::status( 'details', 'Fileoptions instance #27.' );
		$optionsFile = backupbuddy_core::getLogDirectory() . 'fileoptions/' . $serial . '.txt';
		$backup_options = new pb_backupbuddy_fileoptions( $optionsFile, $read_only = true );
		if ( true !== ( $result = $backup_options->is_ok() ) ) {
			return array('error' => __('Unable to access fileoptions data file.', 'mainwp-child' ) . ' Error: ' . $result );
		}
		ob_start();
		$integrity = $backup_options->options['integrity'];

		$start_time = 'Unknown';
		$finish_time = 'Unknown';
		if ( isset( $backup_options->options['start_time'] ) ) {
			$start_time = pb_backupbuddy::$format->date( pb_backupbuddy::$format->localize_time( $backup_options->options['start_time'] ) ) . ' <span class="description">(' . pb_backupbuddy::$format->time_ago( $backup_options->options['start_time'] ) . ' ago)</span>';
			if ( $backup_options->options['finish_time'] > 0 ) {
				$finish_time = pb_backupbuddy::$format->date( pb_backupbuddy::$format->localize_time( $backup_options->options['finish_time'] ) ) . ' <span class="description">(' . pb_backupbuddy::$format->time_ago( $backup_options->options['finish_time'] ) . ' ago)</span>';
			} else { // unfinished.
				$finish_time = '<i>Unfinished</i>';
			}
		}


		//***** BEGIN TESTS AND RESULTS.
		if ( isset( $integrity['status_details'] ) ) { // $integrity['status_details'] is NOT array (old, pre-3.1.9).
			echo '<h3>Integrity Technical Details</h3>';
			echo '<textarea style="width: 100%; height: 175px;" wrap="off">';
			foreach( $integrity as $item_name => $item_value ) {
				$item_value = str_replace( '<br />', '<br>', $item_value );
				$item_value = str_replace( '<br><br>', '<br>', $item_value );
				$item_value = str_replace( '<br>', "\n     ", $item_value );
				echo $item_name . ' => ' . $item_value . "\n";
			}
			echo '</textarea><br><br><b>Note:</b> It is normal to see several "file not found" entries as BackupBuddy checks for expected files in multiple locations, expecting to only find each file once in one of those locations.';
		} else { // $integrity['status_details'] is array.

			echo '<br>';

			if ( isset( $integrity['status_details'] ) ) { // PRE-v4.0 Tests.
				function pb_pretty_results( $value ) {
					if ( $value === true ) {
						return '<span class="pb_label pb_label-success">Pass</span>';
					} else {
						return '<span class="pb_label pb_label-important">Fail</span>';
					}
				}

				// The tests & their status..
				$tests = array();
				$tests[] = array( 'BackupBackup data file exists', pb_pretty_results( $integrity['status_details']['found_dat'] ) );
				$tests[] = array( 'Database SQL file exists', pb_pretty_results( $integrity['status_details']['found_sql'] ) );
				if ( $integrity['detected_type'] == 'full' ) { // Full backup.
					$tests[] = array( 'WordPress wp-config.php exists (full/files backups only)', pb_pretty_results( $integrity['status_details']['found_wpconfig'] ) );
				} elseif ( $integrity['detected_type'] == 'files' ) { // Files only backup.
					$tests[] = array( 'WordPress wp-config.php exists (full/files backups only)', pb_pretty_results( $integrity['status_details']['found_wpconfig'] ) );
				} else { // DB only.
					$tests[] = array( 'WordPress wp-config.php exists (full/files backups only)', '<span class="pb_label pb_label-success">N/A</span>' );
				}
			} else { // 4.0+ Tests.
				$tests = array();
				if ( isset( $integrity['tests'] ) ) {
					foreach( (array)$integrity['tests'] as $test ) {
						if ( true === $test['pass'] ) {
							$status_text = '<span class="pb_label pb_label-success">Pass</span>';
						} else {
							$status_text = '<span class="pb_label pb_label-important">Fail</span>';
						}
						$tests[] = array( $test['test'], $status_text );
					}
				}
			}

			$columns = array(
				__( 'Integrity Test', 'mainwp-child' ),
				__( 'Status', 'mainwp-child' ),
			);

			pb_backupbuddy::$ui->list_table(
				$tests,
				array(
					'columns'		=>	$columns,
					'css'			=>	'width: 100%; min-width: 200px;',
				)
			);

		} // end $integrity['status_details'] is an array.
		echo '<br><br>';
		//***** END TESTS AND RESULTS.


		// Output meta info table (if any).
		$metaInfo = array();
		if ( isset( $integrity['file'] ) && ( false === ( $metaInfo = backupbuddy_core::getZipMeta( backupbuddy_core::getBackupDirectory() . $integrity['file'] ) ) ) ) { // $backup_options->options['archive_file']
			echo '<i>No meta data found in zip comment. Skipping meta information display.</i>';
		} else {
			pb_backupbuddy::$ui->list_table(
				$metaInfo,
				array(
					'columns'		=>	array( 'Backup Details', 'Value' ),
					'css'			=>	'width: 100%; min-width: 200px;',
				)
			);
		}
		echo '<br><br>';


		//***** BEGIN STEPS.
		$steps = array();
		$steps[] = array( 'Start Time', $start_time, '' );
		if ( isset( $backup_options->options['steps'] ) ) {
			foreach( $backup_options->options['steps'] as $step ) {
				if ( isset( $step['finish_time'] ) && ( $step['finish_time'] != 0 ) ) {

					// Step name.
					if ( $step['function'] == 'backup_create_database_dump' ) {
						if ( count( $step['args'][0] ) == 1 ) {
							$step_name = 'Database dump (breakout: ' . $step['args'][0][0] . ')';
						} else {
							$step_name = 'Database dump';
						}
					} elseif ( $step['function'] == 'backup_zip_files' ) {
						if ( isset( $backup_options->options['steps']['backup_zip_files'] ) ) {
							$zip_time = $backup_options->options['steps']['backup_zip_files'];
						} else {
							$zip_time = 0;
						}

						// Calculate write speed in MB/sec for this backup.
						if ( $zip_time == '0' ) { // Took approx 0 seconds to backup so report this speed.
							$write_speed = '> ' . pb_backupbuddy::$format->file_size( $backup_options->options['integrity']['size'] );
						} else {
							if ( $zip_time == 0 ) {
								$write_speed = '';
							} else {
								$write_speed = pb_backupbuddy::$format->file_size( $backup_options->options['integrity']['size'] / $zip_time ) . '/sec';
							}
						}
						$step_name = 'Zip archive creation (Write speed: ' . $write_speed . ')';
					} elseif ( $step['function'] == 'post_backup' ) {
						$step_name = 'Post-backup cleanup';
					} elseif( $step['function'] == 'integrity_check' ) {
						$step_name = 'Integrity Check';
					} else {
						$step_name = $step['function'];
					}

					// Step time taken.
					$seconds = (int)( $step['finish_time'] - $step['start_time'] );
					if ( $seconds < 1 ) {
						$step_time = '< 1 second';
					} else {
						$step_time = $seconds . ' seconds';
					}


					// Compile details for this step into array.
					$steps[] = array(
						$step_name,
						$step_time,
						$step['attempts'],
					);

				}
			} // End foreach.
		} else { // End if serial in array is set.
			$step_times[] = 'unknown';
		} // End if serial in array is NOT set.


		// Total overall time from initiation to end.
		if ( isset( $backup_options->options['finish_time'] ) && isset( $backup_options->options['start_time'] ) && ( $backup_options->options['finish_time'] != 0 ) && ( $backup_options->options['start_time'] != 0 ) ) {
			$seconds = ( $backup_options->options['finish_time'] - $backup_options->options['start_time'] );
			if ( $seconds < 1 ) {
				$total_time = '< 1 second';
			} else {
				$total_time = $seconds . ' seconds';
			}
		} else {
			$total_time = '<i>Unknown</i>';
		}
		$steps[] = array( 'Finish Time', $finish_time, '' );
		$steps[] = array(
			'<b>Total Overall Time</b>',
			$total_time,
			'',
		);

		$columns = array(
			__( 'Backup Steps', 'mainwp-child' ),
			__( 'Time', 'mainwp-child' ),
			__( 'Attempts', 'mainwp-child' ),
		);

		if ( count( $steps ) == 0 ) {
			_e( 'No step statistics were found for this backup.', 'mainwp-child' );
		} else {
			pb_backupbuddy::$ui->list_table(
				$steps,
				array(
					'columns'		=>	$columns,
					'css'			=>	'width: 100%; min-width: 200px;',
				)
			);
		}
		echo '<br><br>';
		//***** END STEPS.

		if ( isset( $backup_options->options['trigger'] ) ) {
			$trigger = $backup_options->options['trigger'];
		} else {
			$trigger = 'Unknown trigger';
		}
		if ( isset( $integrity['scan_time'] ) ) {
			$scanned = pb_backupbuddy::$format->date( pb_backupbuddy::$format->localize_time( $integrity['scan_time'] ) );
			echo ucfirst( $trigger ) . " backup {$integrity['file']} last scanned {$scanned}.";
		}
		echo '<br><br><br>';

		echo '<a class="button secondary-button" onclick="jQuery(\'#pb_backupbuddy_advanced_debug\').slideToggle();">Display Advanced Debugging</a>';
		echo '<div id="pb_backupbuddy_advanced_debug" style="display: none;">From options file: `' . $optionsFile . '`.<br>';
		echo '<textarea style="width: 100%; height: 400px;" wrap="on">';
		echo print_r( $backup_options->options, true );
		echo '</textarea><br><br>';
		echo '</div><br><br>';

		$html = ob_get_clean();
		pb_backupbuddy::flush();
		return array('result' => 'SUCCESS', 'html_detail' => $html);
	}

	function reset_integrity() {
		$_GET['reset_integrity'] = $_POST['reset_integrity'];
		$information['backup_list'] = $this->get_backup_list();
		$information['result'] = 'SUCCESS';
		return $information;
	}

	function download_archive() {

		if ( ! isset( $_GET['_wpnonce'] ) || empty( $_GET['_wpnonce'] ) ) {
			die( '-1' );
		}

		if ( ! MainWP_Helper::verify_nonce_without_session( $_GET['_wpnonce'], 'mainwp_download_backup' ) ) {
			die( '-2' );
		}

		backupbuddy_core::verifyAjaxAccess();

		if ( is_multisite() && !current_user_can( 'manage_network' ) ) { // If a Network and NOT the superadmin must make sure they can only download the specific subsite backups for security purposes.

			if ( !strstr( pb_backupbuddy::_GET( 'backupbuddy_backup' ), backupbuddy_core::backup_prefix() ) ) {
				die( 'Access Denied. You may only download backups specific to your Multisite Subsite. Only Network Admins may download backups for another subsite in the network.' );
			}
		}

		if ( !file_exists( backupbuddy_core::getBackupDirectory() . pb_backupbuddy::_GET( 'backupbuddy_backup' ) ) ) { // Does not exist.
			die( 'Error #548957857584784332. The requested backup file does not exist. It may have already been deleted.' );
		}

		$abspath = str_replace( '\\', '/', ABSPATH );
		$backup_dir = str_replace( '\\', '/', backupbuddy_core::getBackupDirectory() );

		if ( FALSE === stristr( $backup_dir, $abspath ) ) {
			die( 'Error #5432532. You cannot download backups stored outside of the WordPress web root. Please use FTP or other means.' );
		}

		$sitepath = str_replace( $abspath, '', $backup_dir );
		$download_url = rtrim( site_url(), '/\\' ) . '/' . trim( $sitepath, '/\\' ) . '/' . pb_backupbuddy::_GET( 'backupbuddy_backup' );

		if ( pb_backupbuddy::$options['lock_archives_directory'] == '1' ) {

			if ( file_exists( backupbuddy_core::getBackupDirectory() . '.htaccess' ) ) {
				$unlink_status = @unlink( backupbuddy_core::getBackupDirectory() . '.htaccess' );
				if ( $unlink_status === false ) {
					die( 'Error #844594. Unable to temporarily remove .htaccess security protection on archives directory to allow downloading. Please verify permissions of the BackupBuddy archives directory or manually download via FTP.' );
				}
			}

			header( 'Location: ' . $download_url );
			ob_clean();
			flush();
			sleep( 8 );

			$htaccess_creation_status = @file_put_contents( backupbuddy_core::getBackupDirectory() . '.htaccess', 'deny from all' );
			if ( $htaccess_creation_status === false ) {
				die( 'Error #344894545. Security Warning! Unable to create security file (.htaccess) in backups archive directory. This file prevents unauthorized downloading of backups should someone be able to guess the backup location and filenames. This is unlikely but for best security should be in place. Please verify permissions on the backups directory.' );
			}

		} else {
			header( 'Location: ' . $download_url );
		}
		die();
	}

	function create_backup() {
		$requested_profile = $_POST['profile_id'];

		if (!isset(pb_backupbuddy::$options['profiles'][ $requested_profile ])) {
			return array('error' => 'Invalid Profile. Not found.');
		}

		require_once( pb_backupbuddy::plugin_path() . '/classes/backup.php' );
		$newBackup = new pb_backupbuddy_backup();

		$profile_array = pb_backupbuddy::$options['profiles'][ $requested_profile ];
		$serial_override = pb_backupbuddy::random_string( 10 );

		if ( $newBackup->start_backup_process(
				$profile_array,
				'manual', // trigger
				array(),
				isset($_POST['post_backup_steps']) && is_array($_POST['post_backup_steps']) ? $_POST['post_backup_steps'] : array(),
				'',
				$serial_override,
				'', // export_plugins
				'', // direction
				'' // deployDestination
			) !== true ) {
			return array('error' => __('Fatal Error #4344443: Backup failure. Please see any errors listed in the Status Log for details.', 'it-l10n-backupbuddy' ));
		}
		return array('result' => 'SUCCESS');
	}

	function start_backup() {
		require_once( pb_backupbuddy::plugin_path() . '/classes/backup.php' );
		$newBackup = new pb_backupbuddy_backup();
		$data = $_POST['data'];
		if (is_array($data) && isset($data['serial_override'])) {
			if ( $newBackup->start_backup_process(
					$data['profile_array'],
					$data['trigger'],
					array(),
					isset($data['post_backup_steps']) && is_array($data['post_backup_steps']) ? $data['post_backup_steps'] : array(),
					'',
					$data['serial_override'],
					isset($data['export_plugins']) ? $data['export_plugins'] : '',
					$data['direction'],
					isset($data['deployDestination']) ? $data['deployDestination'] : ''
				) !== true ) {
				return array('error' => __('Fatal Error #4344443: Backup failure. Please see any errors listed in the Status Log for details.', 'it-l10n-backupbuddy' ));
			}
		} else {
			return array('error' => 'Invalid backup request.');
		}

		return array('ok' => 1);

	}

	function backup_status() {
		$data = $_POST['data'];
		$result = '';
		if (is_array($data) && isset($data['serial'])) {
			ob_start();
			backupbuddy_api::getBackupStatus( $data['serial'], $data['specialAction'], $data['initwaitretrycount'],  $data['sqlFile'], $echo = true );
			$result = ob_get_clean();
		} else {
			return array('error' => 'Invalid backup request.');
		}
		return array('ok' => 1, 'result' => $result);
	}

	function stop_backup() {
		$serial = $_POST['serial'];
		set_transient( 'pb_backupbuddy_stop_backup-' . $serial, true, ( 60*60*24 ) );
		return array('ok' => 1);
	}

	function remote_save() {
		$data = isset($_POST['data']) ? $_POST['data'] : false;
		$destination_id = isset($_POST['destination_id']) ? $_POST['destination_id'] : 0;

        if (is_array($data) && isset($data['do_not_override'])) {

            if (true == $data['do_not_override']) {
                if (($data['type'] == 's32' || $data['type'] == 's33')) {
                    $not_override = array(
                        'accesskey',
                        'secretkey',
                        'bucket',
                        'region'
                    );
                    foreach($not_override as $opt) {
                        if (isset($data[$opt])) {
                            unset($data[$opt]);
                        }
                    }
                }
            }

            unset($data['do_not_override']);
        }


		if (is_array($data)) {
			if (isset(pb_backupbuddy::$options['remote_destinations'][$destination_id])) { // update
				pb_backupbuddy::$options['remote_destinations'][$destination_id] = array_merge( pb_backupbuddy::$options['remote_destinations'][$destination_id], $data );
			} else { // add new
				$data['token'] = pb_backupbuddy::$options['dropboxtemptoken'];
				pb_backupbuddy::$options['remote_destinations'][$destination_id] = $data;
			}
			pb_backupbuddy::save();
			return array('ok' => 1);
		} else {
			return array('error' => 'Invalid request.');
		}
	}

	function remote_list() {
		$information = array();
		if (isset(pb_backupbuddy::$options['remote_destinations'])) { // update
			$information['remote_destinations'] = pb_backupbuddy::$options['remote_destinations'];
		}
		$information['result'] = 'SUCCESS';
		return $information;
	}

	function remote_delete() {
		$destination_id = isset($_POST['destination_id']) ? $_POST['destination_id'] : null;
		if ($destination_id !== null) {
			require_once( pb_backupbuddy::plugin_path() . '/destinations/bootstrap.php' );
			$delete_response = pb_backupbuddy_destinations::delete_destination( $destination_id, true );

			if ( $delete_response !== true ) {
				return array('error' => $delete_response);
			} else {
				return array('ok' => 1);
			}
		} else {
			return array('error' => 'Invalid request.');
		}
	}

	function remote_send() {

		$destination_id = isset($_POST['destination_id']) ? $_POST['destination_id'] : null;
		$file = isset($_POST['file']) ? $_POST['file'] : null;
		$trigger = isset($_POST['trigger']) ? $_POST['trigger'] : 'manual';

		if ( $file != 'importbuddy.php' ) {
			$backup_file = backupbuddy_core::getBackupDirectory() . $file;
			if ( ! file_exists( $backup_file ) ) { // Error if file to send did not exist!
				$error_message = 'Unable to find file `' . $backup_file . '` to send. File does not appear to exist. You can try again in a moment or turn on full error logging and try again to log for support.';
				pb_backupbuddy::status( 'error', $error_message );
				return array( 'error' => $error_message);
			}
			if ( is_dir( $backup_file ) ) { // Error if a directory is trying to be sent.
				$error_message = 'You are attempting to send a directory, `' . $backup_file . '`. Try again and verify there were no javascript errors.';
				pb_backupbuddy::status( 'error', $error_message );
				return array( 'error' => $error_message);
			}
		} else {
			$backup_file = '';
		}

		if ( isset($_POST['send_importbuddy']) && $_POST['send_importbuddy'] == '1' ) {
			$send_importbuddy = true;
			pb_backupbuddy::status( 'details', 'Cron send to be scheduled with importbuddy sending.' );
		} else {
			$send_importbuddy = false;
			pb_backupbuddy::status( 'details', 'Cron send to be scheduled WITHOUT importbuddy sending.' );
		}

		if ( isset($_POST['delete_after']) && $_POST['delete_after'] == '1' ) {
			$delete_after = true;
			pb_backupbuddy::status( 'details', 'Remote send set to delete after successful send.' );
		} else {
			$delete_after = false;
			pb_backupbuddy::status( 'details', 'Remote send NOT set to delete after successful send.' );
		}

		if ( !isset( pb_backupbuddy::$options['remote_destinations'][$destination_id] ) ) {
			return array( 'error' => 'Error #833383: Invalid destination ID `' . htmlentities( $destination_id ) . '`.' );
		}


		// For Stash we will check the quota prior to initiating send.
		if ( pb_backupbuddy::$options['remote_destinations'][$destination_id]['type'] == 'stash' ) {
			// Pass off to destination handler.
			require_once( pb_backupbuddy::plugin_path() . '/destinations/bootstrap.php' );
			$send_result = pb_backupbuddy_destinations::get_info( 'stash' ); // Used to kick the Stash destination into life.
			$stash_quota = pb_backupbuddy_destination_stash::get_quota( pb_backupbuddy::$options['remote_destinations'][$destination_id], true );

			if ( isset( $stash_quota['error'] ) ) {
				return array( 'error' =>  ' Error accessing Stash account. Send aborted. Details: `' . implode( ' - ', $stash_quota['error'] ) . '`.');
			}

			if ( $backup_file != '' ) {
				$backup_file_size = filesize( $backup_file );
			} else {
				$backup_file_size = 50000;
			}
			if ( ( $backup_file_size + $stash_quota['quota_used'] ) > $stash_quota['quota_total'] ) {
				ob_start();
				echo "You do not have enough Stash storage space to send this file. Please upgrade your Stash storage or delete files to make space.\n\n";
				echo 'Attempting to send file of size ' . pb_backupbuddy::$format->file_size( $backup_file_size ) . ' but you only have ' . $stash_quota['quota_available_nice'] . ' available. ';
				echo 'Currently using ' . $stash_quota['quota_used_nice'] . ' of ' . $stash_quota['quota_total_nice'] . ' (' . $stash_quota['quota_used_percent'] . '%).';
				$error = ob_get_clean();
				return array( 'error' => $error );
			} else {
				if ( isset( $stash_quota['quota_warning'] ) && ( $stash_quota['quota_warning'] != '' ) ) {
					$warning = 'Warning: ' . $stash_quota['quota_warning'] . "\n\n";
					$success_output = true;
				}
			}

		} // end if Stash.

		pb_backupbuddy::status( 'details', 'Scheduling cron to send to this remote destination...' );

		$schedule_result = backupbuddy_core::schedule_single_event( time(), 'remote_send', array( $destination_id, $backup_file, $trigger, $send_importbuddy, $delete_after ) );
		if ( $schedule_result === FALSE ) {
			$error = 'Error scheduling file transfer. Please check your BackupBuddy error log for details. A plugin may have prevented scheduling or the database rejected it.';
			pb_backupbuddy::status( 'error', $error );
			return array( 'error' => $error);
		} else {
			pb_backupbuddy::status( 'details', 'Cron to send to remote destination scheduled.' );
		}
		if ( '1' != pb_backupbuddy::$options['skip_spawn_cron_call'] ) {
			update_option( '_transient_doing_cron', 0 ); // Prevent cron-blocking for next item.
			spawn_cron( time() + 150 ); // Adds > 60 seconds to get around once per minute cron running limit.
		}
		return array( 'ok' => 1);
	}

	function get_main_log() {
		$log_file = backupbuddy_core::getLogDirectory() . 'log-' . pb_backupbuddy::$options['log_serial'] . '.txt';
		ob_start();
		if ( file_exists( $log_file ) ) {
			readfile( $log_file );
		} else {
			echo __('Nothing has been logged.', 'it-l10n-backupbuddy' );
		}
		$result = ob_get_clean();
		return array('result' => $result );
	}

	function settings_other() {

		$other_action = $_POST['other_action'];

		$message = '';
		$error = '';

		if ( 'cleanup_now' ==  $other_action ) {
			$message = 'Performing cleanup procedures now trimming old files and data.';
			require_once( pb_backupbuddy::plugin_path() . '/classes/housekeeping.php' );
			backupbuddy_housekeeping::run_periodic( 0 ); // 0 cleans up everything even if not very old.

		} else if ( 'delete_tempfiles_now' == $other_action) {
			$tempDir = backupbuddy_core::getTempDirectory();
			$logDir = backupbuddy_core::getLogDirectory();
			$message = 'Deleting all files contained within `' . $tempDir . '` and `' . $logDir . '`.' ;
			pb_backupbuddy::$filesystem->unlink_recursive( $tempDir );
			pb_backupbuddy::$filesystem->unlink_recursive( $logDir );
			pb_backupbuddy::anti_directory_browsing( $logDir, $die = false ); // Put log dir back in place.
		} else if ( 'reset_log' == $other_action ) {
			$log_file = backupbuddy_core::getLogDirectory() . 'log-' . pb_backupbuddy::$options['log_serial'] . '.txt';
			if ( file_exists( $log_file ) ) {
				@unlink( $log_file );
			}
			if ( file_exists( $log_file ) ) { // Didnt unlink.
				$error = 'Unable to clear log file. Please verify permissions on file `' . $log_file . '`.';
			} else { // Unlinked.
				$message = 'Cleared log file.';
			}
		} else if ( 'reset_disalerts' == $other_action) {
			pb_backupbuddy::$options['disalerts'] = array();
			pb_backupbuddy::save();
			$message = 'Dismissed alerts have been reset. They may now be visible again.';

		} else if ( 'cancel_running_backups' == $other_action) {
			require_once( pb_backupbuddy::plugin_path() . '/classes/fileoptions.php' );

			$fileoptions_directory = backupbuddy_core::getLogDirectory() . 'fileoptions/';
			$files = glob( $fileoptions_directory . '*.txt' );
			if ( ! is_array( $files ) ) {
				$files = array();
			}
			$cancelCount = 0;
			for ($x = 0; $x <= 3; $x++) { // Try this a few times since there may be race conditions on an open file.
				foreach( $files as $file ) {
					pb_backupbuddy::status( 'details', 'Fileoptions instance #383.' );

					$backup_options = new pb_backupbuddy_fileoptions( $file, $read_only = false );
					if ( true !== ( $result = $backup_options->is_ok() ) ) {
						pb_backupbuddy::status( 'error', 'Error retrieving fileoptions file `' . $file . '`. Err 335353266.' );
					} else {
						if ( empty( $backup_options->options['finish_time'] ) || ( ( FALSE !== $backup_options->options['finish_time'] ) && ( '-1' != $backup_options->options['finish_time'] ) ) ) {
							$backup_options->options['finish_time'] = -1; // Force marked as cancelled by user.
							$backup_options->save();
							$cancelCount++;
						}
					}
				}
				sleep( 1 );
			}

			$message = 'Marked all timed out or running backups & transfers as officially cancelled (`' . $cancelCount . '` total found).';
		}

		return array('_error' => $error, '_message' => $message);
	}

	function malware_scan() {

		backupbuddy_core::schedule_single_event( time(), 'housekeeping', array() );
		update_option( '_transient_doing_cron', 0 );
		spawn_cron( time() + 150 );

		ob_start();

		if ( ! defined( 'pluginbuddy_importbuddy' ) ) {
			$url = home_url();
		} else {
			$url = str_replace( $_SERVER['QUERY_STRING'], '', $_SERVER['REQUEST_URI'] );
			$url = str_replace( basename( $url ) , '', $url );
			$url = 'http://' . $_SERVER['HTTP_HOST'] . $url;
		}
		?>
		<style type="text/css">
			.inside label {
				display: block;
				vertical-align: top;
				width: 140px;
				font-weight: bold;
			}
		</style>


		<?php
		pb_backupbuddy::$ui->start_metabox( __( 'Malware Scan URL', 'it-l10n-backupbuddy' ), true, 'width: 100%;' );

		?>

		<?php echo $url; ?>

		<?php

		$continue_1 = true;
		if ( $url == 'http://localhost' ) {
			_e('ERROR: You are currently running your site locally. Your site must be internet accessible to scan.', 'it-l10n-backupbuddy' );
			$continue_1 = false;
		}

		if ( $continue_1 === true ) {

			if ( !empty( $_POST['refresh'] ) ) {
				delete_transient( 'pb_backupbuddy_malwarescan' );
			}

			//echo '<br />Scanning `' . $url . '`.<br /><br />';
			if ( !defined( 'pluginbuddy_importbuddy' ) ) {
				$scan = get_transient( 'pb_backupbuddy_malwarescan' );
			} else {
				$scan = false;
			}

			if ( false === $scan ) {
				flush();

				$scan = wp_remote_get(
					'http://sitecheck.sucuri.net/scanner/?scan=' . urlencode( $url ) . '&serialized&clear=true',
					array(
						'method' => 'GET',
						'timeout' => 45,
						'redirection' => 5,
						'httpversion' => '1.0',
						'blocking' => true,
						'headers' => array(),
						'body' => null,
						'cookies' => array()
					)
				);

				if ( is_wp_error( $scan ) ) {
					pb_backupbuddy::alert( __('ERROR #24452. Unable to load Malware Scan results. Details:', 'it-l10n-backupbuddy' ). ' ' . $scan->get_error_message(), true );
					$scan = 'N;';
				} else {
					$scan = $scan['body'];
					set_transient( 'pb_backupbuddy_malwarescan', $scan, 60*60*1 ); // 1 hour cache.
				}

			}

			$continue_2 = true;
			if ( substr( $scan, 0, 2 ) == 'N;' ) {
				echo __('An error was encountered attempting to scan this site.','it-l10n-backupbuddy' ), '<br />';
				echo __('An internet connection is required and this site must be accessible on the public internet.', 'it-l10n-backupbuddy' );
				echo '<br>';
				$scan = array();
				$continue_2 = false;
			} else {
				$scan = maybe_unserialize( $scan );
				//echo '<pre>';
				//print_r( $scan );
				//echo '</pre>';
			}

		}

		pb_backupbuddy::$ui->end_metabox();
		?>



		<?php


		if ( $continue_2 === true ) {
			function lined_array( $array ) {
				if ( is_array( $array ) ) {
					foreach( $array as $array_key => $array_item ) {
						if ( is_array( $array_item ) ) {
							$array[$array_key] = lined_array( $array_item );
						}
					}
					//return implode( '<br />', $array );
					$return = '';
					foreach( $array as $array_item ) {
						$return .= $array_item . '<br />';
					}
					return $return;
				} else {
					if ( empty( $array ) ) {
						return '<i>'.__('none', 'it-l10n-backupbuddy' ).'</i><br />';
					} else {
						return $array . '<br />';
					}
				}
			}

			if ( !empty( $scan['MALWARE'] ) && ( $scan['MALWARE'] != 'E' ) ) {
				echo '<table><tr><td><i class="fa fa-exclamation-circle fa-5x" style="color: red"></i></td><td><h1>', __('Warning: Possible Malware Detected!', 'it-l10n-backupbuddy' ), '</h1>',__('See details below.', 'it-l10n-backupbuddy' ), '</td></tr></table>';
			}

			?>


			<div class="postbox-container" style="width: 100%; min-width: 750px;">
				<div class="metabox-holder">
					<div class="meta-box-sortables">

						<div id="breadcrumbslike" class="postbox">
							<div class="handlediv" title="<?php _e('Click to toggle', 'it-l10n-backupbuddy' );?>"><br /></div>
							<h3 class="hndle"><span><?php _e('Malware Detection', 'it-l10n-backupbuddy' );?></span></h3>
							<div class="inside">
								<label><?php _e('Malware', 'it-l10n-backupbuddy' );?></label>
								<?php
								if ( !empty( $scan['MALWARE']['WARN'] ) ) { // Malware found.
									echo lined_array( $scan['MALWARE']['WARN'] );
									backupbuddy_core::addNotification( 'malware_found', 'Malware detected on `' . $url . '`.', 'A malware scan was run on the site and detected malware.', array(), true ); // Urgent
								} else { // No malware found.
									echo '<i>', __('none', 'it-l10n-backupbuddy' ), '</i><br />';
									backupbuddy_core::addNotification( 'malware_not_found', 'No malware detected on `' . $url . '`.', 'A malware scan was run on the site and did not detect malware.' );
								} ?><br />
							</div>
						</div>

						<div id="breadcrumbslike" class="postbox">
							<div class="handlediv" title="<?php _e('Click to toggle', 'it-l10n-backupbuddy' );?>"><br /></div>
							<h3 class="hndle"><span><?php _e('Web server details', 'it-l10n-backupbuddy' );?></span></h3>
							<div class="inside">
								<label><?php _e('Site', 'it-l10n-backupbuddy' );?></label> <?php if ( !empty( $scan['SCAN']['SITE'] ) ) { echo lined_array( $scan['SCAN']['SITE'] ); } else { echo '<i>', __('none', 'it-l10n-backupbuddy' ),'</i><br />'; } ?><br />
								<label><?php _e('Hostname', 'it-l10n-backupbuddy' );?></label> <?php if ( !empty( $scan['SCAN']['DOMAIN'] ) ) { echo lined_array( $scan['SCAN']['DOMAIN'] ); } else { echo '<i>',__('none', 'it-l10n-backupbuddy' ),'</i><br />'; } ?><br />
								<label><?php _e('IP Address', 'it-l10n-backupbuddy' );?></label> <?php if ( !empty( $scan['SCAN']['IP'] ) ) { echo lined_array( $scan['SCAN']['IP'] ); } else { echo '<i>',__('none', 'it-l10n-backupbuddy' ),'</i><br />'; } ?><br />
								<label><?php _e('System details', 'it-l10n-backupbuddy' );?></label> <?php if ( !empty( $scan['SYSTEM']['NOTICE'] ) ) { echo lined_array( $scan['SYSTEM']['NOTICE'] ); } else { echo '<i>', __('none','it-l10n-backupbuddy' ), '</i><br />'; } ?><br />
								<label><?php _e('Information', 'it-l10n-backupbuddy' );?></label> <?php if ( !empty( $scan['SYSTEM']['INFO'] ) ) { echo lined_array( $scan['SYSTEM']['INFO'] ); } else { echo '<i>', __('none', 'it-l10n-backupbuddy' ), '</i><br />'; } ?><br />
							</div>
						</div>

						<div id="breadcrumbslike" class="postbox">
							<div class="handlediv" title="Click to toggle"><br /></div>
							<h3 class="hndle"><span><?php _e('Web application', 'it-l10n-backupbuddy' );?></span></h3>
							<div class="inside">
								<label><?php _e('Details', 'it-l10n-backupbuddy' );?></label> <?php if ( !empty( $scan['WEBAPP']['INFO'] ) ) { echo lined_array( $scan['WEBAPP']['INFO'] ); } else { echo '<i>', __('none', 'it-l10n-backupbuddy' ),'</i><br />'; } ?><br />
								<label><?php _e('Versions', 'it-l10n-backupbuddy' );?></label> <?php if ( !empty( $scan['WEBAPP']['VERSION'] ) ) { echo lined_array( $scan['WEBAPP']['VERSION'] ); } else { echo '<i>',__('none', 'it-l10n-backupbuddy' ),'</i><br />'; } ?><br />
								<label><?php _e('Notices', 'it-l10n-backupbuddy' );?></label> <?php if ( !empty( $scan['WEBAPP']['NOTICE'] ) ) { echo lined_array( $scan['WEBAPP']['NOTICE'] ); } else { echo '<i>', __('none', 'it-l10n-backupbuddy' ), '</i><br />'; } ?><br />
								<label><?php _e('Errors', 'it-l10n-backupbuddy' );?></label> <?php if ( !empty( $scan['WEBAPP']['ERROR'] ) ) { echo lined_array( $scan['WEBAPP']['ERROR'] ); } else { echo '<i>',__('none', 'it-l10n-backupbuddy' ),'</i><br />'; } ?><br />
								<label><?php _e('Warnings', 'it-l10n-backupbuddy' );?></label> <?php if ( !empty( $scan['WEBAPP']['WARN'] ) ) { echo lined_array( $scan['WEBAPP']['WARN'] ); } else { echo '<i>', __('none', 'it-l10n-backupbuddy' ), '</i><br />'; } ?><br />
							</div>
						</div>

						<div id="breadcrumbslike" class="postbox">
							<div class="handlediv" title="<?php _e('Click to toggle', 'it-l10n-backupbuddy' );?>"><br /></div>
							<h3 class="hndle"><span><?php _e('Links', 'it-l10n-backupbuddy' );?></span></h3>
							<div class="inside">
								<?php if ( !empty( $scan['LINKS']['URL'] ) ) { echo lined_array( $scan['LINKS']['URL'] ); } else { echo '<i>', __('none', 'it-l10n-backupbuddy' ), '</i><br />'; } ?>
							</div>
						</div>

						<div id="breadcrumbslike" class="postbox">
							<div class="handlediv" title="<?php _e('Click to toggle', 'it-l10n-backupbuddy' );?>"><br /></div>
							<h3 class="hndle"><span><?php _e('Local Javascript', 'it-l10n-backupbuddy' );?></span></h3>
							<div class="inside">
								<?php if ( !empty( $scan['LINKS']['JSLOCAL'] ) ) { echo lined_array( $scan['LINKS']['JSLOCAL'] ); } else { echo '<i>', __('none', 'it-l10n-backupbuddy' ),'</i><br />'; } ?>
							</div>
						</div>

						<div id="breadcrumbslike" class="postbox">
							<div class="handlediv" title="<?php _e('Click to toggle', 'it-l10n-backupbuddy' );?>"><br /></div>
							<h3 class="hndle"><span><?php _e('External Javascript', 'it-l10n-backupbuddy' );?></span></h3>
							<div class="inside">
								<?php if ( !empty( $scan['LINKS']['JSEXTERNAL'] ) ) { echo lined_array( $scan['LINKS']['JSEXTERNAL'] ); } else { echo '<i>', __('none', 'it-l10n-backupbuddy' ), '</i><br />'; } ?>
							</div>
						</div>

						<div id="breadcrumbslike" class="postbox">
							<div class="handlediv" title="<?php _e('Click to toggle', 'it-l10n-backupbuddy' );?>"><br /></div>
							<h3 class="hndle"><span><?php _e('Iframes Included', 'it-l10n-backupbuddy' );?></span></h3>
							<div class="inside">
								<?php if ( !empty( $scan['LINKS']['IFRAME'] ) ) { echo lined_array( $scan['LINKS']['IFRAME'] ); } else { echo '<i>', __('none', 'it-l10n-backupbuddy' ), '</i><br />'; } ?>
							</div>
						</div>

						<div id="breadcrumbslike" class="postbox">
							<div class="handlediv" title="<?php _e('Click to toggle', 'it-l10n-backupbuddy' );?>"><br /></div>
							<h3 class="hndle"><span><?php _e('Blacklisting Status', 'it-l10n-backupbuddy' );?></span></h3>
							<div class="inside">
								<?php if ( !empty( $scan['BLACKLIST']['INFO'] ) ) { echo lined_array( $scan['BLACKLIST']['INFO'] ); } else { echo '<i>', __('none', 'it-l10n-backupbuddy' ), '</i><br />'; } ?>
							</div>
						</div>

					</div>
				</div>
			</div>
			<?php


		}
		$result = ob_get_clean();

		return array('result' => $result);
	}


	function live_setup() {

		$errors = array();

		$archive_types = array(
			'db' => __( 'Database Backup', 'it-l10n-backupbuddy' ),
			'full' => __( 'Full Backup', 'it-l10n-backupbuddy' ),
			'plugins' => __( 'Plugins Backup', 'it-l10n-backupbuddy' ),
			'themes' => __( 'Themes Backup', 'it-l10n-backupbuddy' ),
		);

		$archive_periods = array(
			'daily',
			'weekly',
			'monthly',
			'yearly',
		);


		if ( ( '' == $_POST['live_username'] ) || ( '' == $_POST['live_password'] ) ) { // A field is blank.
			$errors[] = 'You must enter your iThemes username & password to log in to BackupBuddy Stash Live.';
		} else { // Username and password provided.

			require_once( pb_backupbuddy::plugin_path() . '/destinations/stash2/class.itx_helper2.php' );
			require_once( pb_backupbuddy::plugin_path() . '/destinations/stash2/init.php' );
			require_once( pb_backupbuddy::plugin_path() . '/destinations/live/init.php' );
			global $wp_version;

			$itxapi_username = strtolower( $_POST['live_username'] );
			$password_hash = iThemes_Credentials::get_password_hash( $itxapi_username, $_POST['live_password'] );
			$access_token = ITXAPI_Helper2::get_access_token( $itxapi_username, $password_hash, site_url(), $wp_version );

			$settings = array(
				'itxapi_username' => $itxapi_username,
				'itxapi_password' => $access_token,
			);
			$response = pb_backupbuddy_destination_stash2::stashAPI( $settings, 'connect' );

			if ( ! is_array( $response ) ) { // Error message.
				$errors[] = print_r( $response, true );
			} else {
				if ( isset( $response['error'] ) ) {
					$errors[] = $response['error']['message'];
				} else {
					if ( isset( $response['token'] ) ) {
						$itxapi_token = $response['token'];
					} else {
						$errors[] = 'Error #2308832: Unexpected server response. Token missing. Check your BackupBuddy Stash Live login and try again. Detailed response: `' . print_r( $response, true ) .'`.';
					}
				}
			}

			// If we have the token then create the Live destination.
			if ( isset( $itxapi_token ) ) {
				if ( count( pb_backupbuddy::$options['remote_destinations'] ) > 0 ) {
					$nextDestKey = max( array_keys( pb_backupbuddy::$options['remote_destinations'] ) ) + 1;
				} else { // no destinations yet. first index.
					$nextDestKey = 0;
				}

				pb_backupbuddy::$options['remote_destinations'][ $nextDestKey ] = pb_backupbuddy_destination_live::$default_settings;
				pb_backupbuddy::$options['remote_destinations'][ $nextDestKey ]['itxapi_username'] = $_POST['live_username'];
				pb_backupbuddy::$options['remote_destinations'][ $nextDestKey ]['itxapi_token'] = $itxapi_token;
				pb_backupbuddy::$options['remote_destinations'][ $nextDestKey ]['title'] = 'My BackupBuddy Stash Live';

				// Notification email.
				pb_backupbuddy::$options['remote_destinations'][ $nextDestKey ]['email'] = $_POST['email'];

				// Archive limits.
				foreach( $archive_types as $archive_type => $archive_type_name ) {
					foreach( $archive_periods as $archive_period ) {
						$settings_name = 'limit_' . $archive_type . '_' . $archive_period;
						pb_backupbuddy::$options['remote_destinations'][ $nextDestKey ][ $settings_name ] = $_POST['live_settings'][$settings_name];
					}
				}

				if ( '1' == $_POST['send_snapshot_notification'] ) {
					pb_backupbuddy::$options['remote_destinations'][ $nextDestKey ]['send_snapshot_notification'] = $_POST['send_snapshot_notification'];
				} else {
					pb_backupbuddy::$options['remote_destinations'][ $nextDestKey ]['send_snapshot_notification'] = '0';
				}

				pb_backupbuddy::save();
				$destination_id = $nextDestKey;

				// Send new settings for archive limiting to Stash API.
				backupbuddy_live::send_trim_settings();



				// Set first run of BackupBuddy Stash Live so it begins immediately.
				$cronArgs = array();
				$schedule_result = backupbuddy_core::schedule_single_event( time(), 'live_periodic', $cronArgs );
				if ( true === $schedule_result ) {
					pb_backupbuddy::status( 'details', 'Next Live Periodic chunk step cron event scheduled.' );
				} else {
					pb_backupbuddy::status( 'error', 'Next Live Periodic chunk step cron event FAILED to be scheduled.' );
				}
				if ( '1' != pb_backupbuddy::$options['skip_spawn_cron_call'] ) {
					pb_backupbuddy::status( 'details', 'Spawning cron now.' );
					update_option( '_transient_doing_cron', 0 ); // Prevent cron-blocking for next item.
					spawn_cron( time() + 150 ); // Adds > 60 seconds to get around once per minute cron running limit.
				}

			}

		} // end if user and pass set.


		if ( 0 == count( $errors ) ) {
			pb_backupbuddy::save();
			$data = pb_backupbuddy::$options['remote_destinations'][ $destination_id ];
			return array( 'destination_id' => $destination_id, 'data' => $data);
		} else {
			return array( 'errors' => $errors );
		}

	}

	function live_save_settings() {
		$data = $_POST['data'];
		$new_destination_id = $_POST['destination_id'];


		require_once( pb_backupbuddy::plugin_path() . '/destinations/bootstrap.php' );
		require_once( pb_backupbuddy::plugin_path() . '/destinations/live/live.php' );
		require_once( pb_backupbuddy::plugin_path() . '/destinations/live/live_periodic.php' );

		$destination_id = backupbuddy_live::getLiveID();
		$destination_settings = isset(pb_backupbuddy::$options['remote_destinations'][$destination_id]) ? pb_backupbuddy::$options['remote_destinations'][$destination_id] : array();

		$check_current = !empty($destination_settings) ? true : false;

		$error = '';
		if ( $new_destination_id && is_array($data) ) {
			$itxapi_username = isset($destination_settings['itxapi_username']) ? $destination_settings['itxapi_username'] : '';
			$itxapi_token = isset($destination_settings['itxapi_token']) ? $destination_settings['itxapi_token'] : '';
			$destination_settings = array_merge( $destination_settings, $data );
			$destination_settings['itxapi_username'] = $itxapi_username;
			$destination_settings['itxapi_token'] = $itxapi_token;
			pb_backupbuddy::$options['remote_destinations'][$new_destination_id] = $destination_settings;

			if ($check_current && $destination_id != $new_destination_id) {
				unset(pb_backupbuddy::$options['remote_destinations'][$destination_id]);
			}

			pb_backupbuddy::save();
			//pb_backupbuddy::alert( __( 'Settings saved. Restarting Live process so they take immediate effect.', 'it-l10n-backupbuddy' ) );
			set_transient( 'backupbuddy_live_jump', array( 'daily_init', array() ), 60*60*48 ); // Tells Live process to restart from the beginning (if mid-process) so new settigns apply.

			backupbuddy_live::send_trim_settings();
			return array('ok' => 1);
		} else {
			$error = 'Invalid data. Please check and try again.';
		}
		return array('error' => $error);
	}

	function live_action_disconnect() {
		$error = '';
		$liveDestinationID = $_POST['destination_id'];

		$return = array();
		if ($liveDestinationID) {
			if (isset(pb_backupbuddy::$options['remote_destinations'][ $liveDestinationID ])) {
				// Clear destination settings.
				unset( pb_backupbuddy::$options['remote_destinations'][ $liveDestinationID ] );
				pb_backupbuddy::save();
				// Clear cached Live credentials.
				require_once( pb_backupbuddy::plugin_path() . '/destinations/live/init.php' );
				delete_transient( pb_backupbuddy_destination_live::LIVE_ACTION_TRANSIENT_NAME );
			} else {
				$error = 'Error: destination not found.';
			}
			$return['ok'] = 1;
		} else {
			$error = 'Error: Empty destination id.';
		}

		if (!empty($error))
			$return['error'] = $error;

		return $return;
	}

	function live_action() {
		$action = $_POST['live_action'];
		$error = $message = '';

		require_once( pb_backupbuddy::plugin_path() . '/destinations/live/live_periodic.php' );
		$state = backupbuddy_live_periodic::get_stats();

		$destination_id = backupbuddy_live::getLiveID();
		$destination = backupbuddy_live_periodic::get_destination_settings();

		if ( 'clear_log' == $action ) {
			$sumLogFile = backupbuddy_core::getLogDirectory() . 'status-live_periodic_' . pb_backupbuddy::$options['log_serial'] . '.txt';
			@unlink( $sumLogFile );
			if ( file_exists( $sumLogFile ) ) {
				$error = 'Error #893489322: Unable to clear log file `' . $sumLogFile . '`. Check permissions or manually delete.' ;
			} else {
				$message = 'Log file cleared.';
			}

		} else if ( 'create_snapshot' == $action ) { // < 100% backed up _OR_ ( we are on a step other than daily_init and the last_activity is more recent than the php runtime )
			if ( true === backupbuddy_api::runLiveSnapshot() ) {
				//pb_backupbuddy::alert( '<h3>' . __( 'Verifying everything is up to date before Snapshot', 'it-l10n-backupbuddy' ) . '</h3><p class="description" style="max-width: 700px; display: inline-block;">' . __( 'Please wait while we verify your backup is completely up to date before we create the Snapshot. This may take a few minutes...', 'it-l10n-backupbuddy' ) . '</p>', false, '', 'backupbuddy_live_snapshot_verify_uptodate' );
				$message = '<h3>' . __( 'Verifying everything is up to date before Snapshot', 'it-l10n-backupbuddy' ) . '</h3><p class="description" style="max-width: 700px; display: inline-block;">' . __( 'Please wait while we verify your backup is completely up to date before we create the Snapshot. This may take a few minutes...', 'it-l10n-backupbuddy' ) . '</p>';
				require( pb_backupbuddy::plugin_path() . '/destinations/live/_manual_snapshot.php' );
			}

		} else if ( 'pause_periodic' == $action ) {
			backupbuddy_api::setLiveStatus( $pause_continuous = '', $pause_periodic = true );
			$destination = pb_backupbuddy::$options['remote_destinations'][$destination_id]; // Update local var.
			//pb_backupbuddy::disalert( '', __( 'Live File Backup paused. It may take a moment for current processes to finish.', 'it-l10n-backupbuddy' ) );
			$message = __( 'Live File Backup paused. It may take a moment for current processes to finish.', 'it-l10n-backupbuddy' );
			include( pb_backupbuddy::plugin_path() . '/destinations/live/_stats.php' ); // Recalculate stats.
		} else if ( 'resume_periodic' == $action ) {
			$launchNowText = ' ' . __( 'Unpaused but not running now.', 'it-l10n-backupbuddy' );
			$start_run = false;
			if ( '1' != pb_backupbuddy::_GET( 'skip_run_live_now' ) ) {
				$launchNowText = '';
				$start_run = true;
			}

			backupbuddy_api::setLiveStatus( $pause_continuous = '', $pause_periodic = false, $start_run );
			//pb_backupbuddy::disalert( '', __( 'Live File Backup has resumed.', 'it-l10n-backupbuddy' ) . $launchNowText );
			$message = __( 'Live File Backup has resumed.', 'it-l10n-backupbuddy' ) . $launchNowText;
			include( pb_backupbuddy::plugin_path() . '/destinations/live/_stats.php' ); // Recalculate stats.
		} else if ( 'pause_continuous' == $action ) {
			backupbuddy_api::setLiveStatus( $pause_continuous = true, $pause_periodic = '' );
			$destination = pb_backupbuddy::$options['remote_destinations'][$destination_id]; // Update local var.
			include( pb_backupbuddy::plugin_path() . '/destinations/live/_stats.php' ); // Recalculate stats.
			//pb_backupbuddy::disalert( '', __( 'Live Database Backup paused.', 'it-l10n-backupbuddy' ) );
			$message = __( 'Live Database Backup paused.', 'it-l10n-backupbuddy' );
		} else if ( 'resume_continuous' == $action ) {
			backupbuddy_api::setLiveStatus( $pause_continuous = false, $pause_periodic = '' );
			$destination = pb_backupbuddy::$options['remote_destinations'][$destination_id]; // Update local var.
			include( pb_backupbuddy::plugin_path() . '/destinations/live/_stats.php' ); // Recalculate stats.
			//pb_backupbuddy::disalert( '', __( 'Live Database Backup resumed.', 'it-l10n-backupbuddy' ) );
			$message = __( 'Live Database Backup resumed.', 'it-l10n-backupbuddy' );
		} else {
			$error = 'Error #1000. Invalid request.';
		}

		return array( 'ok' => 1, '_error' => $error, '_message' => $message );
	}



	function download_troubleshooting() {
		require( pb_backupbuddy::plugin_path() . '/destinations/live/_troubleshooting.php' );
		backupbuddy_live_troubleshooting::run();
		$output = "**File best viewed with wordwrap OFF**\n\n" . print_r( backupbuddy_live_troubleshooting::get_raw_results(), true );
		$backup_prefix = backupbuddy_core::backup_prefix();
		return array( 'output' => $output, 'backup_prefix' => $backup_prefix );
	}

	function get_live_backups() {
		$destination_id = $_POST['destination_id'];
		// Load required files.
		require_once( pb_backupbuddy::plugin_path() . '/destinations/s32/init.php' );

		if ( ! isset( pb_backupbuddy::$options['remote_destinations'][ $destination_id ] ) ) {
			return array( 'error' => 'Error #9828332: Destination not found with id `' . htmlentities( $destination_id ) . '`.' );
		}
		require_once( pb_backupbuddy::plugin_path() . '/destinations/stash2/init.php' );
		$settings = &pb_backupbuddy::$options['remote_destinations'][ $destination_id ];
		$settings = pb_backupbuddy_destination_stash2::_formatSettings( $settings );

		$destination = pb_backupbuddy::$options['remote_destinations'][ $destination_id ];

		if ( 'live' == $destination['type'] ) {
			$remotePath = 'snapshot-';// . backupbuddy_core::backup_prefix();
			$site_only = true;
		} else {
			// Get list of files for this site.
			$remotePath = 'backup-';// . backupbuddy_core::backup_prefix();
			$site_only = true;
		}

		$files = pb_backupbuddy_destination_stash2::listFiles( $settings, '', $site_only ); //2nd param was $remotePath.
		if ( ! is_array( $files ) ) {
			return array( 'error' => 'Error #892329c: ' . $files );
		}

		$backup_list_temp = array();
		foreach( (array)$files as $file ) {

			if ( ( '' != $remotePath ) && ( ! backupbuddy_core::startsWith( basename( $file['filename'] ), $remotePath ) ) ) { // Only show backups for this site unless set to show all.
				continue;
			}

			$last_modified = $file['uploaded_timestamp'];
			$size = (double) $file['size'];
			$backup_type = backupbuddy_core::getBackupTypeFromFile( $file['filename'], $quiet = false, $skip_fileoptions = true );

			// Generate array of table rows.
			while( isset( $backup_list_temp[$last_modified] ) ) { // Avoid collisions.
				$last_modified += 0.1;
			}

			if ( 'live' == $destination['type'] ) {
				$backup_list_temp[$last_modified] = array(
					array( base64_encode( $file['url'] ), '<span class="backupbuddy-stash-file-list-title">' . pb_backupbuddy::$format->date( pb_backupbuddy::$format->localize_time( $last_modified ) ) . ' <span class="description">(' . pb_backupbuddy::$format->time_ago( $last_modified ) . ' ago)</span></span><br><span title="' . $file['filename'] . '">' . basename( $file['filename'] ) . '</span>' ),
					pb_backupbuddy::$format->date( pb_backupbuddy::$format->localize_time( $last_modified ) ) . '<br /><span class="description">(' . pb_backupbuddy::$format->time_ago( $last_modified ) . ' ago)</span>',
					pb_backupbuddy::$format->file_size( $size ),
					backupbuddy_core::pretty_backup_type( $backup_type ),
				);
			} else {
				$backup_list_temp[$last_modified] = array(
					array( base64_encode( $file['url'] ), '<span title="' . $file['filename'] . '">' . basename( $file['filename'] ) . '</span>' ),
					pb_backupbuddy::$format->date( pb_backupbuddy::$format->localize_time( $last_modified ) ) . '<br /><span class="description">(' . pb_backupbuddy::$format->time_ago( $last_modified ) . ' ago)</span>',
					pb_backupbuddy::$format->file_size( $size ),
					backupbuddy_core::pretty_backup_type( $backup_type ),
				);
			}

		}

		krsort( $backup_list_temp );
		$backup_list = array();
		foreach( $backup_list_temp as $backup_item ) {
			$backup_list[ $backup_item[0][0] ] = $backup_item;
		}
		unset( $backup_list_temp );

		return array( 'backup_list' => $backup_list );
	}

	function copy_file_to_local() {

		$file = base64_decode( $_POST['cpy_file'] );
		$destination_id = $_POST['destination_id'];

		// Load required files.
		require_once( pb_backupbuddy::plugin_path() . '/destinations/s32/init.php' );
		if ( ! isset( pb_backupbuddy::$options['remote_destinations'][ $destination_id ] ) ) {
			return array( 'error' => 'Error #9828332: Destination not found with id `' . htmlentities( $destination_id ) . '`.' );
		}

		$settings = &pb_backupbuddy::$options['remote_destinations'][ $destination_id ];
		$settings = pb_backupbuddy_destination_stash2::_formatSettings( $settings );

		pb_backupbuddy::status( 'details',  'Scheduling Cron for creating Stash copy.' );
		backupbuddy_core::schedule_single_event( time(), 'process_remote_copy', array( 'stash2', $file, $settings ) );
		if ( '1' != pb_backupbuddy::$options['skip_spawn_cron_call'] ) {
			update_option( '_transient_doing_cron', 0 ); // Prevent cron-blocking for next item.
			spawn_cron( time() + 150 ); // Adds > 60 seconds to get around once per minute cron running limit.
		}
		return array( 'ok' => 1 );

	}

	function delete_file_backup() {
		// Handle deletion.
		$files = $_POST['items'];
		$destination_id = $_POST['destination_id'];

		// Load required files.
		require_once( pb_backupbuddy::plugin_path() . '/destinations/s32/init.php' );
		if ( ! isset( pb_backupbuddy::$options['remote_destinations'][ $destination_id ] ) ) {
			return array( 'error' => 'Error #9828332: Destination not found with id `' . htmlentities( $destination_id ) . '`.' );
		}

		$settings = &pb_backupbuddy::$options['remote_destinations'][ $destination_id ];
		$settings = pb_backupbuddy_destination_stash2::_formatSettings( $settings );

		$deleteFiles = array();
		foreach( (array)$files as $file ) {
			$file = base64_decode( $file );

			$startPos = pb_backupbuddy_destination_stash2::strrpos_count( $file, '/', 2 ) + 1; // next to last slash.
			$file = substr( $file, $startPos );
			if ( FALSE !== strstr( $file, '?' ) ) {
				$file = substr( $file, 0, strpos( $file, '?' ) );
			}
			$deleteFiles[] = $file;
		}
		$response = pb_backupbuddy_destination_stash2::deleteFiles( $settings, $deleteFiles );

		if ( true === $response ) {
			$msg = 'Deleted ' . implode( ', ', $deleteFiles ) . '.';
		} else {
			$msg = 'Failed to delete one or more files. Details: `' . $response . '`.';
		}

		return array( 'ok' => 1, 'msg' => $msg );
	}

	function get_live_stats() {

		// Check if running PHP 5.3+.
		$php_minimum = 5.3;
		if ( version_compare( PHP_VERSION, $php_minimum, '<' ) ) { // Server's PHP is insufficient.
			return array('error' => '-1');
		}

		if ( false === ( $stats = backupbuddy_api::getLiveStats() ) ) { // Live is disconnected.
			return array('error' => '-1');
		}

		// If there is more to do and too long of time has passed since activity then try to jumpstart the process at the beginning.
		if ( ( ( 0 == $stats['files_total'] ) || ( $stats['files_sent'] < $stats['files_total'] ) ) && ( 'wait_on_transfers' != $stats['current_function'] ) ) { // ( Files to send not yet calculated OR more remain to send ) AND not on the wait_on_transfers step.
			$time_since_last_activity = microtime( true ) - $stats['last_periodic_activity'];

			if ( $time_since_last_activity < 30 ) { // Don't even bother getting max execution time if it's been less than 30 seconds since run.
				// do nothing
			} else { // More than 30 seconds since last activity.

				// Detect max PHP execution time. If TESTED value is higher than PHP value then go with that since we want to err on not overlapping processes here.
				$detected_execution = backupbuddy_core::detectLikelyHighestExecutionTime();

				if ( $time_since_last_activity > ( $detected_execution + backupbuddy_constants::TIMED_OUT_PROCESS_RESUME_WIGGLE_ROOM ) ) { // Enough time has passed to assume timed out.

					require_once( pb_backupbuddy::plugin_path() . '/destinations/live/live.php' );
					if ( false === ( $liveID = backupbuddy_live::getLiveID() ) ) {
						die( '-1' );
					}
					if ( '1' != pb_backupbuddy::$options['remote_destinations'][ $liveID ]['pause_periodic'] ) { // Only proceed if NOT paused.

						pb_backupbuddy::status( 'warning', 'BackupBuddy Stash Live process appears timed out while user it viewing Live page. Forcing run now.' );

						$cronArgs = array();
						$schedule_result = backupbuddy_core::schedule_single_event( time(), 'live_periodic', $cronArgs );
						if ( true === $schedule_result ) {
							pb_backupbuddy::status( 'details', 'Next Live Periodic chunk step cron event scheduled.' );
						} else {
							pb_backupbuddy::status( 'error', 'Next Live Periodic chunk step cron event FAILED to be scheduled.' );
						}
						if ( '1' != pb_backupbuddy::$options['skip_spawn_cron_call'] ) {
							pb_backupbuddy::status( 'details', 'Spawning cron now.' );
							update_option( '_transient_doing_cron', 0 ); // Prevent cron-blocking for next item.
							spawn_cron( time() + 150 ); // Adds > 60 seconds to get around once per minute cron running limit.
						}
					}

				}
			}

		}

		return  array('result' => json_encode( $stats ));
	}

	function save_license_settings() {
		$settings = $_POST['settings'];
		if (is_array($settings) && isset($GLOBALS['ithemes-updater-settings'])) {
			$GLOBALS['ithemes-updater-settings']->update_options( $settings );
			return array('ok' => 1);
		}
		return false;
	}

	function load_products_license() {
		$packages = array();
		$packages_name = array();
		if (isset($GLOBALS['ithemes_updater_path'])) {

			require_once( $GLOBALS['ithemes_updater_path'] . '/functions.php' );
			require_once( $GLOBALS['ithemes_updater_path'] . '/api.php' );
			require_once( $GLOBALS['ithemes_updater_path'] . '/keys.php' );

			require_once( $GLOBALS['ithemes_updater_path'] . '/packages.php' );

			$details = Ithemes_Updater_Packages::get_full_details();
			$packages = isset($details['packages']) ? $details['packages'] : array();
			if (is_array($packages)) {
				foreach ( $packages as $path => $data ) {
					$packages_name[$path] = Ithemes_Updater_Functions::get_package_name( $data['package'] );
				}
			}

		}
		return array('ok' => 1, 'packages' => $packages, 'packages_name' => $packages_name);
	}

	function activate_package() {

		$username = $_POST['username'];
		$password = $_POST['password'];
		$packages = $_POST['packages'];

		$return = array( 'ok' => 1 );
		if (isset($GLOBALS['ithemes_updater_path'])) {

			require_once( $GLOBALS['ithemes_updater_path'] . '/functions.php' );
			require_once( $GLOBALS['ithemes_updater_path'] . '/api.php' );
			require_once( $GLOBALS['ithemes_updater_path'] . '/keys.php' );

			require_once( $GLOBALS['ithemes_updater_path'] . '/packages.php' );


			$response = Ithemes_Updater_API::activate_package( $username, $password, $packages );

			if ( is_wp_error( $response ) ) {
				$errors[] = $this->get_error_explanation( $response );
				$return['errors'] = $errors;
				return $return;
			}

			if ( empty( $response['packages'] ) ) {
				$errors[] = __( 'An unknown server error occurred. Please try to license your products again at another time.', 'it-l10n-backupbuddy' );
				$return['errors'] = $errors;
				return $return;
			}

			uksort( $response['packages'], 'strnatcasecmp' );

			$success = array();
			$warn = array();
			$fail = array();

			foreach ( $response['packages'] as $package => $data ) {
				if ( preg_match( '/ \|\|\| \d+$/', $package ) )
					continue;

				$name = Ithemes_Updater_Functions::get_package_name( $package );

				if ( ! empty( $data['key'] ) )
					$success[] = $name;
				else if ( ! empty( $data['status'] ) && ( 'expired' == $data['status'] ) )
					$warn[$name] = __( 'Your product subscription has expired', 'it-l10n-backupbuddy' );
				else
					$fail[$name] = $data['error']['message'];
			}


			if ( ! empty( $success ) ) {
				$messages[] = wp_sprintf( __( 'Successfully licensed %l.', 'it-l10n-backupbuddy' ), $success );
				$return['messages'] = $messages;
			}

			if ( ! empty( $fail ) ) {
				foreach ( $fail as $name => $reason )
					$errors[] = sprintf( __( 'Unable to license %1$s. Reason: %2$s', 'it-l10n-backupbuddy' ), $name, $reason );
				$return['errors'] = $errors;
			}

			if ( ! empty( $warn ) ) {
				foreach ( $warn as $name => $reason )
					$soft_errors[] = sprintf( __( 'Unable to license %1$s. Reason: %2$s', 'it-l10n-backupbuddy' ), $name, $reason );
				$return['soft_errors'] = $soft_errors;
			}

		}
		return $return;
	}

	function deactivate_package( $data ) {

		$username = $_POST['username'];
		$password = $_POST['password'];
		$packages = $_POST['packages'];

		$return = array( 'ok' => 1 );

		if (isset($GLOBALS['ithemes_updater_path'])) {

			require_once( $GLOBALS['ithemes_updater_path'] . '/functions.php' );
			require_once( $GLOBALS['ithemes_updater_path'] . '/api.php' );
			require_once( $GLOBALS['ithemes_updater_path'] . '/keys.php' );

			require_once( $GLOBALS['ithemes_updater_path'] . '/packages.php' );

			$response = Ithemes_Updater_API::deactivate_package($username, $password, $packages );

			if ( is_wp_error( $response ) ) {
				$errors[] = $this->get_error_explanation( $response );
				$return['errors'] = $errors;
				return $return;
			}

			if ( empty( $response['packages'] ) ) {
				$errors[] = __( 'An unknown server error occurred. Please try to remove licenses from your products again at another time.', 'it-l10n-mainwp-backupbuddy' );
				$return['errors'] = $errors;
				return $return;
			}


			uksort( $response['packages'], 'strnatcasecmp' );

			$success = array();
			$fail = array();

			foreach ( $response['packages'] as $package => $data ) {
				if ( preg_match( '/ \|\|\| \d+$/', $package ) )
					continue;

				$name = Ithemes_Updater_Functions::get_package_name( $package );

				if ( isset( $data['status'] ) && ( 'inactive' == $data['status'] ) )
					$success[] = $name;
				else if ( isset( $data['error'] ) && isset( $data['error']['message'] ) )
					$fail[$name] = $data['error']['message'];
				else
					$fail[$name] = __( 'Unknown server error.', 'it-l10n-mainwp-backupbuddy' );
			}


			if ( ! empty( $success ) ) {
				$messages[] = wp_sprintf( _n( 'Successfully removed license from %l.', 'Successfully removed licenses from %l.', count( $success ), 'it-l10n-mainwp-backupbuddy' ), $success );
				$return['messages'] = $messages;
			}

			if ( ! empty( $fail ) ) {
				foreach ( $fail as $name => $reason )
					$errors[] = sprintf( __( 'Unable to remove license from %1$s. Reason: %2$s', 'it-l10n-mainwp-backupbuddy' ), $name, $reason );
				$return['errors'] = $errors;

			}

		}
		return $return;
	}

	private function get_error_explanation( $error, $package = '' ) {
		$code = $error->get_error_code();
		$package_name = Ithemes_Updater_Functions::get_package_name( $package );
		$message = '';

		switch( $code ) {
			case 'ITXAPI_Updater_Bad_Login':
				$message = __( 'Incorrect password. Please make sure that you are supplying your iThemes membership username and password details.', 'it-l10n-mainwp-backupbuddy' );
				break;
			case 'ITXAPI_Updater_Username_Unknown':
			case 'ITXAPI_Updater_Username_Invalid':
				$message = __( 'Invalid username. Please make sure that you are supplying your iThemes membership username and password details.', 'it-l10n-mainwp-backupbuddy' );
				break;
			case 'ITXAPI_Product_Package_Unknown':
				$message = sprintf( __( 'The licensing server reports that the %1$s (%2$s) product is unknown. Please contact support for assistance.', 'it-l10n-mainwp-backupbuddy' ), $package_name, $package );
				break;
			case 'ITXAPI_Updater_Too_Many_Sites':
				$message = sprintf( __( '%1$s could not be licensed since the membership account is out of available licenses for this product. You can unlicense the product on other sites or upgrade your membership to one with a higher number of licenses in order to increase the amount of available licenses.', 'it-l10n-mainwp-backupbuddy' ), $package_name );
				break;
			case 'ITXAPI_License_Key_Generate_Failed':
				$message = sprintf( __( '%s could not be licensed due to an internal error. Please try to license %s again at a later time. If this problem continues, please contact iThemes support.', 'it-l10n-mainwp-backupbuddy' ), $package_name );
				break;
		}

		if ( empty( $message ) ) {
			if ( ! empty( $package ) )
				$message = sprintf( __( 'An unknown error relating to the %1$s product occurred. Please contact iThemes support. Error details: %2$s', 'it-l10n-mainwp-backupbuddy' ), $package_name, $error->get_error_message() . " ($code)" );
			else
				$message = sprintf( __( 'An unknown error occurred. Please contact iThemes support. Error details: %s', 'it-l10n-mainwp-backupbuddy' ), $error->get_error_message() . " ($code)" );
		}

		return $message;
	}



}

