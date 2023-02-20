<?php
/**
 * MainWP Child Backup Buddy
 *
 * The code is used for the MainWP Buddy Extension.
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
 * @package MainWP\Child
 */

namespace MainWP\Child;

// phpcs:disable -- third party credit.


/**
 * Class MainWP_Child_Back_Up_Buddy
 */
class MainWP_Child_Back_Up_Buddy {

    /**
     * Public static variable to hold the single instance of MainWP_Child_Back_Up_Buddy.
     * @var null
     */
    public static $instance  = null;

    /** @var string $plugin_translate Plugin translation string. */
    public $plugin_translate = 'mainwp-child';

    /** @var bool $is_backupbuddy_installed Whether or not BackupBuddy is installed. Default: False. */
    public $is_backupbuddy_installed = false;

    /**
     * Create a public static instance of MainWP_Child_Back_Up_Buddy.
     *
     * @return MainWP_Child_Back_Up_Buddy|null
     */
    public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

    /**
     * MainWP_Child_Back_Up_Buddy constructor.
     *
     * Run any time class is called.
     */
    public function __construct() {
		// To fix bug run dashboard on local machine.
		if ( class_exists( '\pb_backupbuddy' ) ) {
			$this->is_backupbuddy_installed = true;
		}

		if ( ! $this->is_backupbuddy_installed ) {
			return;
		}

		add_filter( 'mainwp_site_sync_others_data', array( $this, 'sync_others_data' ), 10, 2 );

		add_action( 'wp_ajax_mainwp_backupbuddy_download_archive', array( $this, 'download_archive' ) );
		add_action( 'mainwp_child_site_stats', array( $this, 'do_site_stats' ) );

		if ( get_option( 'mainwp_backupbuddy_hide_plugin' ) === 'hide' ) {
			add_filter( 'all_plugins', array( $this, 'all_plugins' ) );
			add_action( 'admin_menu', array( $this, 'admin_menu' ) );
			add_filter( 'site_transient_update_plugins', array( &$this, 'remove_update_nag' ) );
			add_filter( 'mainwp_child_hide_update_notice', array( &$this, 'hide_update_notice' ) );
		}
	}

    /**
     * Hide Backupbuddy update notice.
     *
     * @param string $slugs plugin slug.
     * @return string $slugs Return slugs array.
     */
    public function hide_update_notice( $slugs ) {
		$slugs[] = 'backupbuddy/backupbuddy.php';
		return $slugs;
	}

    /**
     * Remove update nag.
     *
     * @param array $value Plugin slug array.
     * @return array $value Plugin slug array.
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

		if ( isset( $value->response['backupbuddy/backupbuddy.php'] ) ) {
			unset( $value->response['backupbuddy/backupbuddy.php'] );
		}

		return $value;
	}


    /**
     * Remove Backup buddy from plugins list.
     *
     * @param array $plugins All plugins array.
     * @return array $plugins All plugins array with backupbuddy removed.
     */
    public function all_plugins( $plugins ) {
		foreach ( $plugins as $key => $value ) {
			$plugin_slug = basename( $key, '.php' );
			if ( 'backupbuddy' === $plugin_slug ) {
				unset( $plugins[ $key ] );
			}
		}

		return $plugins;
	}

    /**
     * Remove backupbuddy from admin menu.
     */
    public function admin_menu() {

	    /**
	     * Submenu array.
         *
         * @global object
	     */
		global $submenu;

		remove_menu_page( 'pb_backupbuddy_backup' );

		if ( isset( $_SERVER['REQUEST_URI'] ) && false !== stripos( wp_unslash( $_SERVER['REQUEST_URI'] ), 'admin.php?page=pb_backupbuddy_' ) ) {
			wp_safe_redirect( get_option( 'siteurl' ) . '/wp-admin/index.php' );
			exit();
		}
	}


    /**
     * Backupbuddy Client Reports log.
     *
     * @uses MainWP_Child_Back_Up_Buddy::do_reports_log()
     */
    public function do_site_stats() {
		if ( has_action( 'mainwp_child_reports_log' ) ) {
			do_action( 'mainwp_child_reports_log', 'backupbuddy' );
		} else {
			$this->do_reports_log( 'backupbuddy' );
		}
	}

    /**
     * Create BackupBuddy Client Reports log.
     *
     * @param string $ext Extension to create log for.
     *
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::is_backupbuddy_installed()
     * @uses \MainWP\Child\MainWP_Helper::instance()->check_methods()
     * @uses \MainWP\Child\MainWP_Helper::instance()->check_properties()
     * @uses \MainWP\Child\MainWP_Helper::instance()->check_methods()
     * @uses \MainWP\Child\MainWP_Helper::instance()->check_classes_exists()
     * @uses \MainWP\Child\MainWP_Helper::instance()->check_methods()
     * @uses \pb_backupbuddy_fileoptions()
     * @uses \pb_backupbuddy_fileoptions::is_ok()
     * @uses \pb_backupbuddy::$format::prettify()
     * @uses \backupbuddy_live_periodic::get_stats()
     * @uses \Exception
     */
    public function do_reports_log( $ext = '' ) {
		if ( 'backupbuddy' !== $ext ) {
			return;
		}

		if ( ! $this->is_backupbuddy_installed ) {
			return;
		}

		try {

			MainWP_Helper::instance()->check_methods( '\pb_backupbuddy', array( 'plugin_path' ) );

			if ( ! class_exists( '\backupbuddy_core' ) ) {
				if ( file_exists( \pb_backupbuddy::plugin_path() . '/classes/core.php' ) ) {
					require_once \pb_backupbuddy::plugin_path() . '/classes/core.php';
				}
			}

			if ( file_exists( \pb_backupbuddy::plugin_path() . '/classes/fileoptions.php' ) ) {
				require_once \pb_backupbuddy::plugin_path() . '/classes/fileoptions.php';
			}

			MainWP_Helper::instance()->check_classes_exists( array( '\backupbuddy_core', '\pb_backupbuddy_fileoptions' ) );
			MainWP_Helper::instance()->check_methods( '\backupbuddy_core', 'getLogDirectory' );

			$pretty_type = array(
				'full'  => 'Full',
				'db'    => 'Database',
				'files' => 'Files',
			);

			$recentBackups_list = glob( \backupbuddy_core::getLogDirectory() . 'fileoptions/*.txt' );

			foreach ( $recentBackups_list as $backup_fileoptions ) {

				$backup = new \pb_backupbuddy_fileoptions( $backup_fileoptions, $read_only = true );
				$result = $backup->is_ok();
				if ( method_exists( $backup, 'is_ok' ) && true !== $result ) {
					continue;
				}

				$backup = &$backup->options;

				if ( ! isset( $backup['serial'] ) || ( '' == $backup['serial'] ) ) {
					continue;
				}

				$check_finished = false;
				if ( ( $backup['finish_time'] >= $backup['start_time'] ) && ( 0 != $backup['start_time'] ) ) {
					$check_finished = true;
				}

				if ( ! $check_finished ) {
					continue;
				}

				$backupType = '';
				if ( isset( $backup['profile'] ) && isset( $backup['profile']['type'] ) ) {
					if ( true === MainWP_Helper::instance()->check_properties( '\pb_backupbuddy', 'format', true ) ) {
						if ( true === MainWP_Helper::instance()->check_methods( \pb_backupbuddy::$format, array( 'prettify' ), true ) ) {
							$backupType = \pb_backupbuddy::$format->prettify( $backup['profile']['type'], $pretty_type );
						}
					}
				} else {
					if ( true === MainWP_Helper::instance()->check_methods( '\backupbuddy_core', array( 'pretty_backup_type', 'getBackupTypeFromFile' ), true ) ) {
						$backupType = \backupbuddy_core::pretty_backup_type( \backupbuddy_core::getBackupTypeFromFile( $backup['archive_file'] ) );
					}
				}

				if ( '' == $backupType ) {
					$backupType = 'Unknown';
				}

				$finish_time = $backup['finish_time'];
				$message     = 'BackupBuddy ' . $backupType . ' finished';
				if ( ! empty( $finish_time ) ) {
					do_action( 'mainwp_reports_backupbuddy_backup', $message, $backupType, $finish_time );
				}
			}

			if ( file_exists( \pb_backupbuddy::plugin_path() . '/destinations/live/live_periodic.php' ) ) {
				require_once \pb_backupbuddy::plugin_path() . '/destinations/live/live_periodic.php';

				MainWP_Helper::instance()->check_classes_exists( array( '\backupbuddy_live_periodic' ) );
				MainWP_Helper::instance()->check_methods( '\backupbuddy_live_periodic', 'get_stats' );

				$state = \backupbuddy_live_periodic::get_stats();
				if ( is_array( $state ) && isset( $state['stats'] ) ) {

					if ( is_array( $state['stats'] ) && isset( $state['stats']['last_remote_snapshot'] ) ) {
						if ( isset( $state['stats']['last_remote_snapshot_response'] ) ) {
							$resp = $state['stats']['last_remote_snapshot_response'];
							if ( isset( $resp['success'] ) && $resp['success'] ) {
								$finish_time = $state['stats']['last_remote_snapshot'];
								$backupType  = 'Live Backup to cloud';
								$message     = 'BackupBuddy ' . $backupType . ' finished';
								if ( ! empty( $finish_time ) ) {
									do_action( 'mainwp_reports_backupbuddy_backup', $message, $backupType, $finish_time );
								}
							}
						}
					}
				}
			}
		} catch ( \Exception $e ) {
			// ok!
		}
	}

    /**
     * MainWP Child BackupBuddy actions.
     *
     * @uses \MainWP\Child\MainWP_Helper::write()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::is_backupbuddy_installed()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::set_showhide()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::save_settings()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::reset_defaults()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::get_notifications()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::schedules_list()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::run_scheduled_backup()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::save_scheduled_backup()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::delete_scheduled_backup()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::save_profile()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::delete_profile()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::delete_backup()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::backup_list()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::save_note()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::get_hash()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::zip_viewer()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::exclude_tree()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::restore_file_view()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::restore_file_restore()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::view_log()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::view_detail()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::reset_integrity()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::download_archive()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::create_backup()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::start_backup()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::backup_status()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::stop_backup()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::remote_save()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::remote_delete()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::remote_send()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::remote_list()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::get_main_log()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::settings_other()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::malware_scan()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::live_setup()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::live_save_settings()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::live_action_disconnect()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::live_action()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::download_troubleshooting()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::get_live_backups()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::copy_file_to_local()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::delete_file_backup()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::get_live_stats()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::load_products_license()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::save_license_settings()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::activate_package()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::deactivate_package()
     */
    public function action() {
		$information = array();
		if ( ! $this->is_backupbuddy_installed ) {
			MainWP_Helper::write( array( 'error' => esc_html__( 'Please install the BackupBuddy plugin on the child site.', $this->plugin_translate ) ) );
		}

		if ( ! class_exists( '\backupbuddy_core' ) ) {
			require_once \pb_backupbuddy::plugin_path() . '/classes/core.php';
		}

		if ( ! isset( \pb_backupbuddy::$options ) ) {
			\pb_backupbuddy::load();
		}
		
		$mwp_action = isset( $_POST['mwp_action'] ) ? sanitize_text_field( wp_unslash( $_POST['mwp_action'] ) ) : '';
		if ( isset( $_POST['mwp_action'] ) ) {
			switch ( $mwp_action ) {
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

    /**
     * Set show or hide BackupBuddy Plugin from Admin & plugins list.
     *
     * @return array $information Return results.
     *
     * @uses \MainWP\Child\MainWP_Helper::update_option()
     */
    public function set_showhide() {
		$hide = isset( $_POST['showhide'] ) && ( 'hide' === $_POST['showhide'] ) ? 'hide' : '';
		MainWP_Helper::update_option( 'mainwp_backupbuddy_hide_plugin', $hide );
		$information['result'] = 'SUCCESS';
		return $information;
	}

    /**
     * Save BackupBuddy settings.
     *
     * @return array $out Return response array.
     *
     * @uses \pb_backupbuddy::$options()
     * @uses \backupbuddy_core::_getBackupDirectoryDefault()
     */
    public function save_settings() {

		$type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';

		if ( 'general_settings' !== $type && 'advanced_settings' !== $type && 'all' !== $type ) {
			return array( 'error' => esc_html__( 'Invalid data. Please check and try again.' ) );
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
			'integrity_check',
			'backup_cron_rescheduling',
			'skip_spawn_cron_call',
			'backup_cron_passed_force_time',
			'php_runtime_test_minimum_interval',
			'php_memory_test_minimum_interval',
			'database_method_strategy',
			'skip_database_dump',
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
			'backup_nonwp_tables',
			'mysqldump_additional_includes',
			'mysqldump_additional_excludes',
			'excludes',
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
			'email_return',
		);

		$filter_profile0_values = array(
			'mysqldump_additional_includes',
			'mysqldump_additional_excludes',
			'excludes',
			'integrity_check',
			'skip_database_dump',
			'backup_nonwp_tables',
		);

		$settings = json_decode( base64_decode( wp_unslash( $_POST['options'] ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..

		$save_settings = array();

		if ( is_array( $settings ) ) {
			if ( 'all' === $type || 'general_settings' === $type ) {
				foreach ( $filter_general_settings as $field ) {
					if ( isset( $settings[ $field ] ) ) {
						$save_settings[ $field ] = $settings[ $field ];
					}
				}
			}

			if ( 'all' === $type || 'advanced_settings' === $type ) {
				foreach ( $filter_advanced_settings as $field ) {
					if ( isset( $settings[ $field ] ) ) {
						$save_settings[ $field ] = $settings[ $field ];
					}
				}
			}
		}

		if ( ! empty( $save_settings ) ) {
			$newOptions = \pb_backupbuddy::$options;

			foreach ( $newOptions as $key => $val ) {
				if ( isset( $save_settings[ $key ] ) ) {
					$newOptions[ $key ] = $save_settings[ $key ];
				}
			}

			if ( isset( $newOptions['profiles'] ) && isset( $newOptions['profiles'][0] ) ) {
				foreach ( $filter_profile0_values as $field ) {
					if ( isset( $settings[ $field ] ) ) {
						$newOptions['profiles'][0][ $field ] = $settings[ $field ];
					}
				}
			}

			if ( 'general_settings' === $type || 'all' === $type ) {
				$newOptions['importbuddy_pass_hash_confirm'] = '';
			}

			/** @global object $wpdb WordPres Database object. */
			global $wpdb;

			$option     = 'pb_' . \pb_backupbuddy::settings( 'slug' );
			$newOptions = sanitize_option( $option, $newOptions );
			$newOptions = maybe_serialize( $newOptions ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- third party credit.

			add_site_option( $option, $newOptions, '', 'no' ); // 'No' prevents autoload if we wont always need the data loaded.
			$wpdb->update( $wpdb->options, array( 'option_value' => $newOptions ), array( 'option_name' => $option ) );

			$information['backupDirectoryDefault'] = \backupbuddy_core::_getBackupDirectoryDefault();
			$information['result']                 = 'SUCCESS';
		}

		return $information;
	}

    /**
     * Reset BackupBuddy defaults.
     *
     * @return array $information Return success message & result.
     *
     * @uses \pb_backupbuddy::$options
     * @uses \pb_backupbuddy::settings()
     * @uses \pb_backupbuddy::save()
     * @uses \backupbuddy_core::verify_directories()
     * @uses \backupbuddy_core::addNotification()
     */
    public function reset_defaults() {

		// Keep log serial.
		$old_log_serial = \pb_backupbuddy::$options['log_serial'];

		$keepDestNote            = '';
		$remote_destinations     = \pb_backupbuddy::$options['remote_destinations'];
		\pb_backupbuddy::$options = \pb_backupbuddy::settings( 'default_options' );
		if ( '1' == $_POST['keep_destinations'] ) {
			\pb_backupbuddy::$options['remote_destinations'] = $remote_destinations;
			$keepDestNote                                   = ' ' . esc_html__( 'Remote destination settings were not reset.', 'mainwp-child' );
		}

		// Replace log serial.
		\pb_backupbuddy::$options['log_serial'] = $old_log_serial;

		\pb_backupbuddy::save();
		$skipTempGeneration = true;
		\backupbuddy_core::verify_directories( $skipTempGeneration ); // Re-verify directories such as backup dir, temp, etc.
		$resetNote = esc_html__( 'Plugin settings have been reset to defaults.', 'mainwp-child' );
		\backupbuddy_core::addNotification( 'settings_reset', 'Plugin settings reset', $resetNote . $keepDestNote );

		$information['message'] = $resetNote . $keepDestNote;
		$information['result']  = 'SUCCESS';
		return $information;
	}

    /**
     * Get BackupBuddy core success notifications.
     *
     * @return array Return BackupBuddy SUCCESS notifications.
     *
     * @uses \backupbuddy_core::getNotifications()
     */
    public function get_notifications() {
		return array(
			'result'        => 'SUCCESS',
			'notifications' => \backupbuddy_core::getNotifications(),
		);
	}

    /**
     * Get schedules run time.
     *
     * @return array Return $schedules_run_time.
     *
     * @uses \pb_backupbuddy::$options
     * @uses \pb_backupbuddy::$format::date()
     * @uses \pb_backupbuddy::$format::localize_time()
     */
    public function get_schedules_run_time() {
		$schedules_run_time = array();
		foreach ( \pb_backupbuddy::$options['schedules'] as $schedule_id => $schedule ) {
			// Determine first run.
			$first_run = \pb_backupbuddy::$format->date( \pb_backupbuddy::$format->localize_time( $schedule['first_run'] ) );
			// Determine last run.
			if ( isset( $schedule['last_run'] ) ) { // backward compatibility before last run tracking added. Pre v2.2.11. Eventually remove this.
				if ( 0 == $schedule['last_run'] ) {
					$last_run = '<i>' . esc_html__( 'Never', 'mainwp-child' ) . '</i>';
				} else {
					$last_run = \pb_backupbuddy::$format->date( \pb_backupbuddy::$format->localize_time( $schedule['last_run'] ) );
				}
			} else { // backward compatibility for before last run tracking was added.
				$last_run = '<i> ' . esc_html__( 'Unknown', 'mainwp-child' ) . '</i>';
			}

			// Determine next run.
			$next_run = wp_next_scheduled( 'backupbuddy_cron', array( 'run_scheduled_backup', array( (int) $schedule_id ) ) );
			if ( false === $next_run ) {
				$next_run = '<font color=red>Error: Cron event not found</font>';
			} else {
				$next_run = \pb_backupbuddy::$format->date( \pb_backupbuddy::$format->localize_time( $next_run ) );
			}

			$run_time = 'First run: ' . $first_run . '<br>' .
						'Last run: ' . $last_run . '<br>' .
						'Next run: ' . $next_run;

			$schedules_run_time[ $schedule_id ] = $run_time;

		}

		return $schedules_run_time;
	}

    /**
     * List schedules.
     *
     * @return array $information Return results array.
     *
     * @uses \pb_backupbuddy::$options
     * @uses MainWP_Child_Back_Up_Buddy::get_schedules_run_time()
     */
    public function schedules_list() {
		$information                        = array();
		$information['schedules']           = \pb_backupbuddy::$options['schedules'];
		$information['next_schedule_index'] = \pb_backupbuddy::$options['next_schedule_index'];
		$information['schedules_run_time']  = $this->get_schedules_run_time();

		// to fix missing destination notice.
		if ( isset( \pb_backupbuddy::$options['remote_destinations'] ) ) {
			$information['remote_destinations'] = \pb_backupbuddy::$options['remote_destinations'];
		}

		$information['result'] = 'SUCCESS';
		return $information;
	}


    /**
     * Run scheduled backup.
     *
     * @return array $information Return results array.
     *
     * @uses \pb_backupbuddy::$options
     * @uses \pb_backupbuddy::alert()
     * @uses \pb_backupbuddy_cron::_run_scheduled_backup()
     */
    public function run_scheduled_backup() {
		if ( ! is_main_site() ) { // Only run for main site or standalone. Multisite subsites do not allow schedules.
			return array( 'error' => esc_html__( 'Only run for main site or standalone. Multisite subsites do not allow schedules', 'mainwp-child' ) );
		}

		$schedule_id = (int) $_POST['schedule_id'];

		if ( ! isset( \pb_backupbuddy::$options['schedules'][ $schedule_id ] ) || ! is_array( \pb_backupbuddy::$options['schedules'][ $schedule_id ] ) ) {
			return array( 'error' => esc_html__( 'Error: not found the backup schedule or invalid data', 'mainwp-child' ) );
		}

		\pb_backupbuddy::alert( 'Manually running scheduled backup "' . \pb_backupbuddy::$options['schedules'][ $schedule_id ]['title'] . '" in the background.<br>' . esc_html__( 'Note: If there is no site activity there may be delays between steps in the backup. Access the site or use a 3rd party service, such as a free pinging service, to generate site activity.', 'mainwp-child' ) );
		\pb_backupbuddy_cron::_run_scheduled_backup( $schedule_id );

		$information['result'] = 'SUCCESS';

		return $information;
	}


    /**
     * Save scheduled backup.
     *
     * @return array|string[] $information Return results array.
     *
     * @uses \pb_backupbuddy::$options
     * @uses \backupbuddy_core::schedule_event()
     * @uses \backupbuddy_core::unschedule_event()
     * @uses \pb_backupbuddy::save()
     * @uses MainWP_Child_Back_Up_Buddy::get_schedules_run_time()
     */
    public function save_scheduled_backup() {
		$schedule_id = intval( $_POST['schedule_id'] );
		$schedule    = json_decode( base64_decode( wp_unslash( $_POST['data'] ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..

		if ( ! is_array( $schedule ) ) {
			return array( 'error' => esc_html__( 'Invalid schedule data', 'mainwp-child' ) );
		}
		$information = array();

		if ( ! isset( \pb_backupbuddy::$options['schedules'][ $schedule_id ] ) ) {
			$next_index = \pb_backupbuddy::$options['next_schedule_index'];
			\pb_backupbuddy::$options['next_schedule_index']++; // This change will be saved in savesettings function below.
			\pb_backupbuddy::$options['schedules'][ $schedule_id ] = $schedule;
			$result = \backupbuddy_core::schedule_event( $schedule['first_run'], $schedule['interval'], 'run_scheduled_backup', array( $schedule_id ) );
			if ( false === $result ) {
				return array( 'error' => 'Error scheduling event with WordPress. Your schedule may not work properly. Please try again. Error #3488439b. Check your BackupBuddy error log for details.' );
			}
		} else {
			$first_run           = $schedule['first_run'];
			$next_scheduled_time = wp_next_scheduled( 'backupbuddy_cron', array( 'run_scheduled_backup', array( (int) $schedule_id ) ) );
			\backupbuddy_core::unschedule_event( $next_scheduled_time, 'backupbuddy_cron', array( 'run_scheduled_backup', array( (int) $schedule_id ) ) );
			\backupbuddy_core::schedule_event( $first_run, $schedule['interval'], 'run_scheduled_backup', array( (int) $schedule_id ) ); // Add new schedule.
			\pb_backupbuddy::$options['schedules'][ $schedule_id ] = $schedule;
		}
		\pb_backupbuddy::save();
		$information['result']              = 'SUCCESS';
		$information['schedules']           = \pb_backupbuddy::$options['schedules'];
		$information['next_schedule_index'] = \pb_backupbuddy::$options['next_schedule_index'];
		$information['schedules_run_time']  = $this->get_schedules_run_time();
		return $information;
	}


    /**
     * Save profile.
     *
     * @return array $information Return response array.
     *
     * @uses \pb_backupbuddy::$options
     * @uses \pb_backupbuddy::save()
     */
    public function save_profile() {
		$profile_id = isset( $_POST['profile_id'] ) ? sanitize_text_field( wp_unslash( $_POST['profile_id'] ) ) : 0;
		$profile    = json_decode( base64_decode( wp_unslash( $_POST['data'] ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..

		if ( ! is_array( $profile ) ) {
			return array( 'error' => esc_html__( 'Invalid profile data', 'mainwp-child' ) );
		}

		\pb_backupbuddy::$options['profiles'][ $profile_id ] = $profile;
		\pb_backupbuddy::save();

		$information['result'] = 'SUCCESS';
		return $information;
	}


    /**
     * Delete profile.
     *
     * @return array $information Return results array.
     *
     * @uses \pb_backupbuddy::$options
     * @uses \pb_backupbuddy::save()
     */
    public function delete_profile() {
		$profile_id = isset( $_POST['profile_id'] ) ? sanitize_text_field( wp_unslash( $_POST['profile_id'] ) ) : 0;

		if ( isset( \pb_backupbuddy::$options['profiles'][ $profile_id ] ) ) {
			unset( \pb_backupbuddy::$options['profiles'][ $profile_id ] );
		}

		\pb_backupbuddy::save();
		$information['result'] = 'SUCCESS';
		return $information;
	}

    /**
     * Delete backup.
     *
     * @param string $type Type of backup.
     * @param bool $subsite_mode Whether or not the backup is for a subsite. Default: false.
     *
     * @return array $information Return results array.
     *
     * @uses \backupbuddy_core::getBackupDirectory()
     * @uses \backupbuddy_core::get_serial_from_file()
     * @uses \backupbuddy_core::getLogDirectory()
     * @uses \backupbuddy_core::getLogDirectory::re::get_serial_from_file()
     * @uses \pb_backupbuddy::save()
     */
    public function delete_backup( $type = 'default', $subsite_mode = false ) {
		$item_ids    = isset( $_POST['item_ids'] ) ? explode( ',', wp_unslash( $_POST['item_ids'] ) ) : array();
		$information = array();
		if ( is_array( $item_ids ) && count( $item_ids ) > 0 ) {
			$needs_save    = false;
			$deleted_files = array();
			foreach ( $item_ids as $item ) {
				if ( file_exists( \backupbuddy_core::getBackupDirectory() . $item ) ) {
					if ( unlink( \backupbuddy_core::getBackupDirectory() . $item ) === true ) {
						$deleted_files[] = $item;

						// Cleanup any related fileoptions files.
						$serial = \backupbuddy_core::get_serial_from_file( $item );

						$backup_files = glob( \backupbuddy_core::getBackupDirectory() . '*.zip' );
						if ( ! is_array( $backup_files ) ) {
							$backup_files = array();
						}
						if ( count( $backup_files ) > 5 ) { // Keep a minimum number of backups in array for stats.
							$this_serial      = \backupbuddy_core::get_serial_from_file( $item );
							$fileoptions_file = \backupbuddy_core::getLogDirectory() . 'fileoptions/' . $this_serial . '.txt';
							if ( file_exists( $fileoptions_file ) ) {
								unlink( $fileoptions_file );
							}
							if ( file_exists( $fileoptions_file . '.lock' ) ) {
								unlink( $fileoptions_file . '.lock' );
							}
							$needs_save = true;
						}
					}
				}
			}
			if ( true === $needs_save ) {
				\pb_backupbuddy::save();
			}

			$information['result'] = 'SUCCESS';

		}
		return $information;
	}

    /**
     * Sync other data from $data[] and merge with $information[]
     *
     * @param array $information Returned response array for MainWP BackWPup Extension actions.
     * @param array $data Other data to sync to $information array.
     *
     * @return array $information Returned information array with both sets of data.
     */
    public function sync_others_data( $information, $data = array() ) {
		if ( isset( $data['syncBackupBuddy'] ) && $data['syncBackupBuddy'] ) {
			try {
				$information['syncBackupBuddy'] = $this->get_sync_data();
			} catch ( \Exception $e ) {
				// ok!
			}
		}
		return $information;
	}

    /**
     * Get sync data.
     *
     * @uses \MainWP\Child\MainWP_Helper::instance()->check_classes_exists()
     * @uses \MainWP\Child\MainWP_Helper::instance()->check_methods()
     * @uses \pb_backupbuddy::plugin_path()
     * @uses \backupbuddy_core::get_plugins_root()
     * @uses \backupbuddy_core::get_themes_root()
     * @uses \backupbuddy_core::get_media_root()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::pb_additional_tables()
     * @uses \MainWP\Child\MainWP_Utility::update_lasttime_backup()
     */
    public function get_sync_data() {
		try {
			if ( ! class_exists( '\backupbuddy_core' ) ) {
				MainWP_Helper::instance()->check_classes_exists( '\pb_backupbuddy' );
				MainWP_Helper::instance()->check_methods( '\pb_backupbuddy', array( 'plugin_path' ) );

				$plugin_path = \pb_backupbuddy::plugin_path();
				if ( file_exists( $plugin_path . '/classes/core.php' ) ) {
					require_once $plugin_path . '/classes/core.php';
				}
			}

			MainWP_Helper::instance()->check_classes_exists( array( '\backupbuddy_core', '\backupbuddy_api' ) );
			MainWP_Helper::instance()->check_methods( '\backupbuddy_core', array( 'get_plugins_root', 'get_themes_root', 'get_media_root' ) );
			MainWP_Helper::instance()->check_methods( '\backupbuddy_api', array( 'getOverview' ) );

			$data                      = array();
			$data['plugins_root']      = \backupbuddy_core::get_plugins_root();
			$data['themes_root']       = \backupbuddy_core::get_themes_root();
			$data['media_root']        = \backupbuddy_core::get_media_root();
			$data['additional_tables'] = $this->pb_additional_tables();
			$data['abspath']           = ABSPATH;

			$getOverview                  = \backupbuddy_api::getOverview();
			$data['editsSinceLastBackup'] = $getOverview['editsSinceLastBackup'];

			if ( isset( $getOverview['lastBackupStats']['finish'] ) ) {
				$finish_time             = $getOverview['lastBackupStats']['finish'];
				$time                    = $this->localize_time( $finish_time );
				$data['lastBackupStats'] = date( 'M j - g:i A', $time ); // phpcs:ignore -- local time.
				$data['lasttime_backup'] = $finish_time;
				MainWP_Utility::update_lasttime_backup( 'backupbuddy', $finish_time ); // support Require Backup Before Update feature.
			} else {
				$data['lastBackupStats'] = 'Unknown';
			}

			return $data;
		} catch ( \Exception $e ) {
			// not exit here!
		}

		return false;
	}

    /**
     * Localize time.
     *
     * @param float $timestamp Time to localize.
     *
     * @return float|int Return localized timestamp.
     */
    public function localize_time($timestamp ) {
		if ( function_exists( 'get_option' ) ) {
			$gmt_offset = get_option( 'gmt_offset' );
		} else {
			$gmt_offset = 0;
		}
		return $timestamp + ( $gmt_offset * 3600 );
	}

    /**
     * Backup list.
     *
     * @return array $information Return results array.
     *
     * @uses \pb_backupbuddy::plugin_path()
     * @uses MainWP_Child_Back_Up_Buddy::get_backup_list()
     * @uses MainWP_Child_Back_Up_Buddy::get_recent_backup_list()
     * @uses \backupbuddy_core::getBackupDirectory()
     */
    public function backup_list() {
		require_once \pb_backupbuddy::plugin_path() . '/destinations/bootstrap.php';
		$information                                  = array();
		$information['backup_list']                   = $this->get_backup_list();
		$information['recent_backup_list']            = $this->get_recent_backup_list();
		$backup_directory                             = \backupbuddy_core::getBackupDirectory();
		$backup_directory                             = str_replace( '\\', '/', $backup_directory );
		$backup_directory                             = rtrim( $backup_directory, '/\\' ) . '/';
		$information['backupDirectoryWithinSiteRoot'] = ( false !== stristr( $backup_directory, ABSPATH ) ) ? 'yes' : 'no';
		$information['result']                        = 'SUCCESS';
		return $information;
	}

    /**
     * Save note.
     *
     * @return array $information Return results array.
     *
     * @uses \pb_backupbuddy::plugin_path()
     * @uses \pb_backupbuddy::$classes
     * @uses \pb_backupbuddy::$classes::get_comment()
     * @uses \pb_backupbuddy::status()
     * @uses \pb_backupbuddy_fileoptions()
     * @uses \pb_backupbuddy_fileoptions::is_ok()
     * @uses \pb_backupbuddy_fileoptions::save()
     * @uses \pb_backupbuddy::$classes::set_comment()
     * @uses \backupbuddy_core::getBackupDirectory()
     * @uses \backupbuddy_core::normalize_comment_data()
     * @uses \backupbuddy_core::get_serial_from_file()
     * @uses \backupbuddy_core::getLogDirectory()
     */
    public function save_note() {
		if ( ! isset( \pb_backupbuddy::$classes['zipbuddy'] ) ) {
			require_once \pb_backupbuddy::plugin_path() . '/lib/zipbuddy/zipbuddy.php';
			\pb_backupbuddy::$classes['zipbuddy'] = new \pluginbuddy_zipbuddy( \backupbuddy_core::getBackupDirectory() );
		}
		$backup_file = isset( $_POST['backup_file'] ) ? wp_unslash( $_POST['backup_file'] ) : '';
		$note        = isset( $_POST['backup_file'] ) ? wp_unslash( $_POST['note'] ) : '';
		$note        = preg_replace( '/[[:space:]]+/', ' ', $note );
		$note        = preg_replace( '/[^[:print:]]/', '', $note );
		$note        = substr( $note, 0, 200 );

		// Returns true on success, else the error message.
		$old_comment     = \pb_backupbuddy::$classes['zipbuddy']->get_comment( $backup_file );
		$comment         = \backupbuddy_core::normalize_comment_data( $old_comment );
		$comment['note'] = $note;

		$comment_result = \pb_backupbuddy::$classes['zipbuddy']->set_comment( $backup_file, $comment );

		$information = array();
		if ( true === $comment_result ) {
			$information['result'] = 'SUCCESS';
		}

		// Even if we cannot save the note into the archive file, store it in internal settings.
		$serial = \backupbuddy_core::get_serial_from_file( $backup_file );

		require_once \pb_backupbuddy::plugin_path() . '/classes/fileoptions.php';
		\pb_backupbuddy::status( 'details', 'Fileoptions instance #24.' );
		$backup_options = new \pb_backupbuddy_fileoptions( \backupbuddy_core::getLogDirectory() . 'fileoptions/' . $serial . '.txt' );
		$result         = $backup_options->is_ok();
		if ( true === $result ) {
			$backup_options->options['integrity']['comment'] = $note;
			$backup_options->save();
		}
		return $information;
	}

    /**
     * Get hash.
     *
     * @return array|string[] Return results array.
     *
     * @uses \backupbuddy_core::getBackupDirectory()
     */
    public function get_hash() {
		$callback_data = isset( $_POST['callback_data'] ) ? wp_unslash( $_POST['callback_data'] ) : '';
		$file          = \backupbuddy_core::getBackupDirectory() . $callback_data;
		if ( file_exists( $file ) ) {
			return array(
				'result' => 'SUCCESS',
				'hash'   => md5_file( $file ),
			);
		} else {
			return array( 'error' => 'Not found the file' );
		}
	}

    /**
     * Zip viewer.
     *
     * @return array|string[] Return results array.
     *
     * @uses \pb_backupbuddy::plugin_path()
     * @uses \backupbuddy_core::getLogDirectory()
     * @uses \pb_backupbuddy::status()
     * @uses \pb_backupbuddy_fileoptions()
     * @uses \pb_backupbuddy_fileoptions::is_ok()
     * @uses \pb_backupbuddy_fileoptions::save()
     * @uses \pb_backupbuddy::$classes
     * @uses \pb_backupbuddy::$classes::get_file_list()
     * @uses \pluginbuddy_zipbuddy()
     */
    public function zip_viewer() {

		// How long to cache the specific backup file tree information for (seconds).
		$max_cache_time = 86400;

		// This is the root directory we want the listing for.
		$root     = isset( $_POST['dir'] ) ? wp_unslash( $_POST['dir'] ) : '';
		$root_len = strlen( $root );

		// This will identify the backup zip file we want to list.
		$serial = isset( $_POST['serial'] ) ? sanitize_text_field( wp_unslash( $_POST['serial'] ) ) : '';
		$alerts = array();
		// The fileoptions file that contains the file tree information.
		require_once \pb_backupbuddy::plugin_path() . '/classes/fileoptions.php';
		$fileoptions_file = \backupbuddy_core::getLogDirectory() . 'fileoptions/' . $serial . '-filetree.txt';

		// Purge cache if too old.
		if ( file_exists( $fileoptions_file ) && ( ( time() - filemtime( $fileoptions_file ) ) > $max_cache_time ) ) {
			if ( false === unlink( $fileoptions_file ) ) {
				$alerts[] = 'Error #456765545. Unable to wipe cached fileoptions file `' . $fileoptions_file . '`.';
			}
		}

		\pb_backupbuddy::status( 'details', 'Fileoptions instance #28.' );
		$fileoptions = new \pb_backupbuddy_fileoptions( $fileoptions_file );
		$zip_viewer  = isset( $_POST['zip_viewer'] ) ? wp_unslash( $_POST['zip_viewer'] ) : '';
		// Either we are getting cached file tree information or we need to create afresh.
		$result = $fileoptions->is_ok();
		if ( true !== $result ) {
			// Get file listing.
			require_once \pb_backupbuddy::plugin_path() . '/lib/zipbuddy/zipbuddy.php';
			\pb_backupbuddy::$classes['zipbuddy'] = new \pluginbuddy_zipbuddy( ABSPATH, array(), 'unzip' );
			$files                               = \pb_backupbuddy::$classes['zipbuddy']->get_file_list( \backupbuddy_core::getBackupDirectory() . str_replace( '\\/', '', $zip_viewer ) );
			$fileoptions->options                = $files;
			$fileoptions->save();
		} else {
			$files = &$fileoptions->options;
		}

		if ( ! is_array( $files ) ) {
			return array( 'error' => 'Error #548484.  Unable to retrieve file listing from backup file `' . htmlentities( $zip_viewer ) . '`.' );
		}
		$subdirs = array();

		foreach ( $files as $key => $file ) {
			if ( strlen( $file[0] ) < $root_len ) {
				unset( $files[ $key ] );
				continue;
			}

			if ( substr( $file[0], 0, $root_len ) != $root ) {
				unset( $files[ $key ] );
				continue;
			}

			if ( 0 == strcmp( $file[0], $root ) ) {
				unset( $files[ $key ] );
				continue;
			}
			$unrooted_file = substr( $file[0], $root_len );
			$pos           = strpos( $unrooted_file, '/' );
			if ( false !== $pos ) {
				$subdir = substr( $unrooted_file, 0, ( $pos + 1 ) );
				if ( ! in_array( $subdir, $subdirs ) ) {
					$subdirs[]        = $subdir;
					$files[ $key ][0] = $subdir;
				} else {
					unset( $files[ $key ] );
					continue;

				}
			} else {
				$files[ $key ][0] = $unrooted_file;
			}
		}

		return array(
			'result'  => 'SUCCESS',
			'files'   => $files,
			'message' => implode( '<br/>', $alerts ),
		);
	}

    /**
     * Exclude tree.
     *
     * @return array|string[] Return excluded files & directories list html or ERROR message.
     */
    public function exclude_tree() {
		$root = substr( ABSPATH, 0, strlen( ABSPATH ) - 1 ) . '/' . ( isset( $_POST['dir'] ) ? ltrim( urldecode( $_POST['dir'] ), '/\\' ) : '' );
		if ( file_exists( $root ) ) {
			$files = scandir( $root );

			natcasesort( $files );

			// Sort with directories first.
			$sorted_files       = array(); // Temporary holder for sorting files.
			$sorted_directories = array(); // Temporary holder for sorting directories.
			foreach ( $files as $file ) {
				if ( ( '.' == $file ) || ( '..' == $file ) ) {
					continue;
				}
				if ( is_file( str_replace( '//', '/', $root . $file ) ) ) {
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

			if ( count( $files ) > 0 ) { // Files found.
				echo '<ul class="jqueryFileTree" style="display: none;">';
				foreach ( $files as $file ) {
					if ( file_exists( str_replace( '//', '/', $root . $file ) ) ) {
						if ( is_dir( str_replace( '//', '/', $root . $file ) ) ) { // Directory.
							echo '<li class="directory collapsed">';
							$return  = '';
							$return .= '<div class="pb_backupbuddy_treeselect_control">';
							$return .= '<img src="' . \pb_backupbuddy::plugin_url() . '/images/redminus.png" style="vertical-align: -3px;" title="Add to exclusions..." class="pb_backupbuddy_filetree_exclude">';
							$return .= '</div>';
							echo '<a href="#" rel="' . htmlentities( str_replace( ABSPATH, '', $root ) . $file ) . '/" title="Toggle expand...">' . htmlentities( $file ) . $return . '</a>';
							echo '</li>';
						} else { // File.
							echo '<li class="file collapsed">';
							$return  = '';
							$return .= '<div class="pb_backupbuddy_treeselect_control">';
							$return .= '<img src="' . \pb_backupbuddy::plugin_url() . '/images/redminus.png" style="vertical-align: -3px;" title="Add to exclusions..." class="pb_backupbuddy_filetree_exclude">';
							$return .= '</div>';
							echo '<a href="#" rel="' . htmlentities( str_replace( ABSPATH, '', $root ) . $file ) . '">' . htmlentities( $file ) . $return . '</a>';
							echo '</li>';
						}
					}
				}
				echo '</ul>';
			} else {
				echo '<ul class="jqueryFileTree" style="display: none;">';
				echo '<li><a href="#" rel="' . htmlentities( \pb_backupbuddy::_POST( 'dir' ) . 'NONE' ) . '"><i>Empty Directory ...</i></a></li>';
				echo '</ul>';
			}

			$html = ob_get_clean();
			return array( 'result' => $html );
		} else {
			return array( 'error' => 'Error #1127555. Unable to read child site root.' );
		}
	}

    /**
     * Additional tables.
     *
     * @param bool $display_size Display size. Default: false.
     *
     * @return string Additional table html.
     * @throws Exception|\Exception Error message.
     *
     * @uses \MainWP\Child\MainWP_Helper::instance()->check_classes_exists()
     * @uses \MainWP\Child\MainWP_Helper::instance()->check_methods()
     * @uses \MainWP\Child\MainWP_Helper::instance()->check_properties()
     * @uses \MainWP\Child\MainWP_Helper::instance()->check_methods()
     * @uses $wpdb::get_results()
     * @uses \pb_backupbuddy::$format::file_size()
     * @uses \pb_backupbuddy::plugin_url()
     */
    public function pb_additional_tables( $display_size = false ) {

		MainWP_Helper::instance()->check_classes_exists( '\pb_backupbuddy' );
		MainWP_Helper::instance()->check_methods( '\pb_backupbuddy', 'plugin_url' );
		MainWP_Helper::instance()->check_properties( '\pb_backupbuddy', 'format' );
		MainWP_Helper::instance()->check_methods( \pb_backupbuddy::$format, 'file_size' );

		$return      = '';
		$size_string = '';

		/** @global object $wpdb WordPress Database. */
		global $wpdb;

		if ( true === $display_size ) {
			$results = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A );
		} else {
			$results = $wpdb->get_results( 'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()', ARRAY_A );
		}
		foreach ( $results as $result ) {

			if ( true === $display_size ) {
				// Fix up row count and average row length for InnoDB engine which returns inaccurate (and changing) values for these.
				if ( 'InnoDB' === $result['Engine'] ) {
					$rowCount = $wpdb->get_var( "SELECT COUNT(1) as rowCount FROM `{$result[ 'Name' ]}`", ARRAY_A ); // phpcs:ignore -- safe query.
					if ( false !== $rowCount ) {
						$result['Rows'] = $rowCount;
						if ( 0 < $result['Rows'] ) {
							$result['Avg_row_length'] = ( $result['Data_length'] / $result['Rows'] );
						}
					}
					unset( $rowCount );
				}

				// Table size.
				$size_string = ' (' . \pb_backupbuddy::$format->file_size( ( $result['Data_length'] + $result['Index_length'] ) ) . ') ';

			} // end if display size enabled.

			$return .= '<li class="file ext_sql collapsed">';
			$return .= '<a rel="/" alt="' . $result['table_name'] . '">' . $result['table_name'] . $size_string;
			$return .= '<div class="pb_backupbuddy_treeselect_control">';
			$return .= '<img src="' . \pb_backupbuddy::plugin_url() . '/images/redminus.png" style="vertical-align: -3px;" title="Add to exclusions..." class="pb_backupbuddy_table_addexclude"> <img src="' . \pb_backupbuddy::plugin_url() . '/images/greenplus.png" style="vertical-align: -3px;" title="Add to inclusions..." class="pb_backupbuddy_table_addinclude">';
			$return .= '</div>';
			$return .= '</a>';
			$return .= '</li>';
		}

		return '<div class="jQueryOuterTree" style="position: absolute; height: 160px;"><ul class="jqueryFileTree">' . $return . '</ul></div>';
	}

    /**
     * Restore file view.
     *
     * @return array|string[] Return results array. ERROR message on failure.
     *
     * @uses \backupbuddy_core::get_serial_from_file()
     * @uses \pb_backupbuddy::plugin_path()
     * @uses \pluginbuddy_zipbuddy()
     * @uses \pluginbuddy_zipbuddy::extract()
     * @uses \backupbuddy_core::getBackupDirectory()
     * @uses \pb_backupbuddy::status()
     * @uses \pb_backupbuddy::anti_directory_browsing()
     * @uses \pb_backupbuddy::$filesystem::unlink_recursive()
     */
    public function restore_file_view() {

		$archive_file = isset( $_POST['archive'] ) ? wp_unslash( $_POST['archive'] ) : ''; // archive to extract from.
		$file         = isset( $_POST['file'] ) ? wp_unslash( $_POST['file'] ) : ''; // file to extract.
		$serial       = \backupbuddy_core::get_serial_from_file( $archive_file ); // serial of archive.
		$temp_file    = uniqid(); // temp filename to extract into.

		require_once \pb_backupbuddy::plugin_path() . '/lib/zipbuddy/zipbuddy.php';
		$zipbuddy = new \pluginbuddy_zipbuddy( \backupbuddy_core::getBackupDirectory() );

		// Calculate temp directory & lock it down.
		$temp_dir    = get_temp_dir();
		$destination = $temp_dir . 'backupbuddy-' . $serial;
		if ( ( ( ! file_exists( $destination ) ) && ( false === mkdir( $destination ) ) ) ) {
			$error = 'Error #458485945b: Unable to create temporary location.';
			\pb_backupbuddy::status( 'error', $error );
			return array( 'error' => $error );
		}

		// If temp directory is within webroot then lock it down.
		$temp_dir = str_replace( '\\', '/', $temp_dir ); // Normalize for Windows.
		$temp_dir = rtrim( $temp_dir, '/\\' ) . '/'; // Enforce single trailing slash.
		if ( false !== stristr( $temp_dir, ABSPATH ) ) { // Temp dir is within webroot.
			\pb_backupbuddy::anti_directory_browsing( $destination );
		}
		unset( $temp_dir );

		$message = 'Extracting "' . $file . '" from archive "' . $archive_file . '" into temporary file "' . $destination . '". ';
		\pb_backupbuddy::status( 'details', $message );

		$file_content = '';

		$extractions    = array( $file => $temp_file );
		$extract_result = $zipbuddy->extract( \backupbuddy_core::getBackupDirectory() . $archive_file, $destination, $extractions );
		if ( false === $extract_result ) { // failed.
			$error = 'Error #584984458. Unable to extract.';
			\pb_backupbuddy::status( 'error', $error );
			return array( 'error' => $error );
		} else { // success.
			$file_content = file_get_contents( $destination . '/' . $temp_file );
		}

		// Try to cleanup.
		if ( file_exists( $destination ) ) {
			if ( false === \pb_backupbuddy::$filesystem->unlink_recursive( $destination ) ) {
				\pb_backupbuddy::status( 'details', 'Unable to delete temporary holding directory `' . $destination . '`.' );
			} else {
				\pb_backupbuddy::status( 'details', 'Cleaned up temporary files.' );
			}
		}

		return array(
			'result'       => 'SUCCESS',
			'file_content' => $file_content,
		);
	}


    /**
     * Backupbuddy Restore files.
     *
     * @return array Return results array.
     *
     * @uses \pb_backupbuddy::set_status_serial()
     * @uses \pb_backupbuddy::status()
     * @uses \pb_backupbuddy::plugin_path()
     * @uses \backupbuddy_restore_files::restore()
     * @uses \backupbuddy_core::getBackupDirectory()
     * @uses \pb_backupbuddy::flush()
     */
    public function restore_file_restore() {

		$files        = isset( $_POST['files'] ) ? wp_unslash( $_POST['files'] ) : ''; // file to extract.
		$archive_file = isset( $_POST['archive'] ) ? wp_unslash( $_POST['archive'] ) : ''; // archive to extract from.

		$files_array = explode( ',', $files );
		$files       = array();
		foreach ( $files_array as $file ) {
			if ( substr( $file, -1 ) == '/' ) { // If directory then add wildcard.
				$file = $file . '*';
			}
			$files[ $file ] = $file;
		}
		unset( $files_array );

		$success = false;

		/** @global object $pb_backupbuddy_js_status BackupBuddy js status. */
		global $pb_backupbuddy_js_status;

		$pb_backupbuddy_js_status = true;

		\pb_backupbuddy::set_status_serial( 'restore' );

		/** @global string $wp_version WordPress version. */
		global $wp_version;

		\pb_backupbuddy::status( 'details', 'BackupBuddy v' . \pb_backupbuddy::settings( 'version' ) . ' using WordPress v' . $wp_version . ' on ' . PHP_OS . '.' );

		require \pb_backupbuddy::plugin_path() . '/classes/_restoreFiles.php';

		ob_start();
		$result         = \backupbuddy_restore_files::restore( \backupbuddy_core::getBackupDirectory() . $archive_file, $files, $finalPath = ABSPATH );
		$restore_result = ob_get_clean();
		\pb_backupbuddy::flush();
		return array( 'restore_result' => $restore_result );
	}

    /**
     * Get backup list.
     *
     * @param string $type Type of backup.
     * @param bool $subsite_mode Whether or not the backup is for a subsite. Default: false.
     *
     * @return array Return $sorted_backups array.
     *
     * @uses \backupbuddy_core::getBackupDirectory()
     * @uses \backupbuddy_core::backup_prefix()
     * @uses \backupbuddy_core::get_serial_from_file()
     * @uses \backupbuddy_core::getLogDirectory()
     * @uses \backupbuddy_core::backup_integrity_check()
     * @uses \backupbuddy_core::pretty_backup_type()
     * @uses \backupbuddy_core::getBackupTypeFromFile()
     * @uses \backupbuddy_core::pretty_backup_type()
     * @uses \backupbuddy_core::getBackupTypeFromFile()
     * @uses \pb_backupbuddy::plugin_path()
     * @uses \pb_backupbuddy::status()
     * @uses \pb_backupbuddy_fileoptions()
     * @uses \pb_backupbuddy_fileoptions()
     * @uses \pb_backupbuddy::$format::file_size()
     * @uses \pb_backupbuddy::$format::time_ago()
     * @uses \pb_backupbuddy::$format::prettify()
     * @uses \pb_backupbuddy::$format::date()
     * @uses \pb_backupbuddy::$format::localize_time()
     * @uses \MainWP\Child\MainWP_Utility::create_nonce_without_session()
     */
    public function get_backup_list( $type = 'default', $subsite_mode = false ) {
		$backups           = array();
		$backup_sort_dates = array();

		$files = glob( \backupbuddy_core::getBackupDirectory() . 'backup*.zip' );
		if ( ! is_array( $files ) ) {
			$files = array();
		}

		$files2 = glob( \backupbuddy_core::getBackupDirectory() . 'snapshot*.zip' );
		if ( ! is_array( $files2 ) ) {
			$files2 = array();
		}

		$files = array_merge( $files, $files2 );

		if ( is_array( $files ) && ! empty( $files ) ) { // For robustness. Without open_basedir the glob() function returns an empty array for no match. With open_basedir in effect the glob() function returns a boolean false for no match.

			$backup_prefix = \backupbuddy_core::backup_prefix(); // To checking that this user can see this backup.
			foreach ( $files as $file_id => $file ) {

				if ( ( true === $subsite_mode ) && is_multisite() ) { // If a Network and NOT the superadmin must make sure they can only see the specific subsite backups for security purposes.

					// Only allow viewing of their own backups.
					if ( ! strstr( $file, $backup_prefix ) ) {
						unset( $files[ $file_id ] ); // Remove this backup from the list. This user does not have access to it.
						continue; // Skip processing to next file.
					}
				}

				$serial = \backupbuddy_core::get_serial_from_file( $file );

				$options = array();
				if ( file_exists( \backupbuddy_core::getLogDirectory() . 'fileoptions/' . $serial . '.txt' ) ) {
					require_once \pb_backupbuddy::plugin_path() . '/classes/fileoptions.php';
					\pb_backupbuddy::status( 'details', 'Fileoptions instance #33.' );
					$backup_options = new \pb_backupbuddy_fileoptions( \backupbuddy_core::getLogDirectory() . 'fileoptions/' . $serial . '.txt', $read_only = false, $ignore_lock = false, $create_file = true ); // Will create file to hold integrity data if nothing exists.
				} else {
					$backup_options = '';
				}
				$backup_integrity = \backupbuddy_core::backup_integrity_check( $file, $backup_options, $options );

				// Backup status.
				$pretty_status = array(
					true    => '<span class="pb_label pb_label-success">Good</span>', // v4.0+ Good.
					'pass'  => '<span class="pb_label pb_label-success">Good</span>', // Pre-v4.0 Good.
					false   => '<span class="pb_label pb_label-important">Bad</span>',  // v4.0+ Bad.
					'fail'  => '<span class="pb_label pb_label-important">Bad</span>',  // Pre-v4.0 Bad.
				);

				// Backup type.
				$pretty_type = array(
					'full'      => 'Full',
					'db'        => 'Database',
					'files'     => 'Files',
					'themes'    => 'Themes',
					'plugins'   => 'Plugins',
				);

				// Defaults.
				$detected_type = '';
				$file_size     = '';
				$modified      = '';
				$modified_time = 0;
				$integrity     = '';

				$main_string = 'Warn#284.';
				if ( is_array( $backup_integrity ) ) { // Data intact... put it all together.
					// Calculate time ago.
					$time_ago = '';
					if ( isset( $backup_integrity['modified'] ) ) {
						$time_ago = \pb_backupbuddy::$format->time_ago( $backup_integrity['modified'] ) . ' ago';
					}

					$detected_type = \pb_backupbuddy::$format->prettify( $backup_integrity['detected_type'], $pretty_type );
					if ( '' == $detected_type ) {
						$detected_type = \backupbuddy_core::pretty_backup_type( \backupbuddy_core::getBackupTypeFromFile( $file ) );
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
							';
						}
					}

					$file_size     = \pb_backupbuddy::$format->file_size( $backup_integrity['size'] );
					$modified      = \pb_backupbuddy::$format->date( \pb_backupbuddy::$format->localize_time( $backup_integrity['modified'] ), 'l, F j, Y - g:i:s a' );
					$modified_time = $backup_integrity['modified'];
					if ( isset( $backup_integrity['status'] ) ) { // Pre-v4.0.
						$status = $backup_integrity['status'];
					} else { // v4.0+.
						$status = $backup_integrity['is_ok'];
					}

					// Calculate main row string.
					if ( 'default' == $type ) { // Default backup listing.
						$download_url = '/wp-admin/admin-ajax.php?action=mainwp_backupbuddy_download_archive&backupbuddy_backup=' . basename( $file ) . '&_wpnonce=' . MainWP_Utility::create_nonce_without_session( 'mainwp_download_backup' );
						$main_string  = '<a href="#" download-url="' . $download_url . '"class="backupbuddyFileTitle mwp_bb_download_backup_lnk" title="' . basename( $file ) . '">' . $modified . ' (' . $time_ago . ')</a>';
					} elseif ( 'migrate' == $type ) { // Migration backup listing.
						$main_string = '<a class="pb_backupbuddy_hoveraction_migrate backupbuddyFileTitle" rel="' . basename( $file ) . '" href="' . \pb_backupbuddy::page_url() . '&migrate=' . basename( $file ) . '&value=' . basename( $file ) . '" title="' . basename( $file ) . '">' . $modified . ' (' . $time_ago . ')</a>';
					} else {
						$main_string = '{Unknown type.}';
					}
					// Add comment to main row string if applicable.
					if ( isset( $backup_integrity['comment'] ) && ( false !== $backup_integrity['comment'] ) && ( '' !== $backup_integrity['comment'] ) ) {
						$main_string .= '<br><span class="description">Note: <span class="pb_backupbuddy_notetext">' . htmlentities( $backup_integrity['comment'] ) . '</span></span>';
					}

					$integrity = \pb_backupbuddy::$format->prettify( $status, $pretty_status ) . ' ';
					if ( isset( $backup_integrity['scan_notes'] ) && count( (array) $backup_integrity['scan_notes'] ) > 0 ) {
						foreach ( (array) $backup_integrity['scan_notes'] as $scan_note ) {
							$integrity .= $scan_note . ' ';
						}
					}
					$integrity .= '<a href="#" serial="' . $serial . '" class="mwp_bb_reset_integrity_lnk" file-name="' . basename( $file ) . '" title="Rescan integrity. Last checked ' . \pb_backupbuddy::$format->date( $backup_integrity['scan_time'] ) . '."> <i class="fa fa-refresh" aria-hidden="true"></i></a>';
					$integrity .= '<div class="row-actions"><a title="' . esc_html__( 'Backup Status', 'mainwp-child' ) . '" href="#" serial="' . $serial . '" class="mainwp_bb_view_details_lnk thickbox">' . esc_html__( 'View Details', 'mainwp-child' ) . '</a></div>';

					$sumLogFile = \backupbuddy_core::getLogDirectory() . 'status-' . $serial . '_' . \pb_backupbuddy::$options['log_serial'] . '.txt';
					if ( file_exists( $sumLogFile ) ) {
						$integrity .= '<div class="row-actions"><a title="' . esc_html__( 'View Backup Log', 'mainwp-child' ) . '" href="#" serial="' . $serial . '" class="mainwp_bb_view_log_lnk thickbox">' . esc_html__( 'View Log', 'mainwp-child' ) . '</a></div>';
					}
				}

				// No integrity check for themes or plugins types.
				$raw_type = \backupbuddy_core::getBackupTypeFromFile( $file );
				if ( ( 'themes' == $raw_type ) || ( 'plugins' == $raw_type ) ) {
					$integrity = 'n/a';
				}

				$backups[ basename( $file ) ] = array(
					array( basename( $file ), $main_string . '<br><span class="description" style="color: #AAA; display: inline-block; margin-top: 5px;">' . basename( $file ) . '</span>' ),
					$detected_type,
					$file_size,
					$integrity,
				);

				$backup_sort_dates[ basename( $file ) ] = $modified_time;

			}
		}

		// Sort backup by date.
		arsort( $backup_sort_dates );
		// Re-arrange backups based on sort dates.
		$sorted_backups = array();
		foreach ( $backup_sort_dates as $backup_file => $backup_sort_date ) {
			$sorted_backups[ $backup_file ] = $backups[ $backup_file ];
			unset( $backups[ $backup_file ] );
		}
		unset( $backups );
		return $sorted_backups;
	}

    /**
     * Get recent backup list.
     *
     * @return array $recentBackups Return recent backups array.
     *
     * @uses \backupbuddy_core::getLogDirectory()
     * @uses \backupbuddy_core::pretty_backup_type()
     * @uses \backupbuddy_core::getBackupTypeFromFile()
     * @uses \backupbuddy_core::getBackupTypeFromFile()
     * @uses \pb_backupbuddy::plugin_path()
     * @uses \pb_backupbuddy::status()
     * @uses \pb_backupbuddy_fileoptions()
     * @uses \pb_backupbuddy_fileoptions::is_ok()
     * @uses \pb_backupbuddy::$format::date()
     * @uses \pb_backupbuddy::$format::localize_time()
     * @uses \pb_backupbuddy::$format::prettify()
     * @uses \pb_backupbuddy::$format::file_size()
     */
    public function get_recent_backup_list() {
		$recentBackups_list = glob( \backupbuddy_core::getLogDirectory() . 'fileoptions/*.txt' );
		if ( ! is_array( $recentBackups_list ) ) {
			$recentBackups_list = array();
		}

		$recentBackups = array();
		if ( count( $recentBackups_list ) > 0 ) {

			// Backup type.
			$pretty_type = array(
				'full'  => 'Full',
				'db'    => 'Database',
				'files' => 'Files',
			);

			foreach ( $recentBackups_list as $backup_fileoptions ) {

				require_once \pb_backupbuddy::plugin_path() . '/classes/fileoptions.php';
				\pb_backupbuddy::status( 'details', 'Fileoptions instance #1.' );
				$backup = new \pb_backupbuddy_fileoptions( $backup_fileoptions, $read_only = true );
				$result = $backup->is_ok();
				if ( true !== $result ) {
					\pb_backupbuddy::status( 'error', esc_html__( 'Unable to access fileoptions data file.', 'mainwp-child' ) . ' Error: ' . $result );
					continue;
				}
				$backup = &$backup->options;

				if ( ! isset( $backup['serial'] ) || ( '' == $backup['serial'] ) ) {
					continue;
				}
				if ( ( $backup['finish_time'] >= $backup['start_time'] ) && ( 0 != $backup['start_time'] ) ) {
					$status = '<span class="pb_label pb_label-success">Completed</span>';
				} elseif ( -1 == $backup['finish_time'] ) {
					$status = '<span class="pb_label pb_label-warning">Cancelled</span>';
				} elseif ( false === $backup['finish_time'] ) {
					$status = '<span class="pb_label pb_label-error">Failed (timeout?)</span>';
				} elseif ( ( time() - $backup['updated_time'] ) > \backupbuddy_constants::TIME_BEFORE_CONSIDERED_TIMEOUT ) {
					$status = '<span class="pb_label pb_label-error">Failed (likely timeout)</span>';
				} else {
					$status = '<span class="pb_label pb_label-warning">In progress or timed out</span>';
				}
				$status .= '<br>';

				// Technical details link.
				$status .= '<div class="row-actions">';
				$status .= '<a title="' . esc_html__( 'Backup Process Technical Details', 'mainwp-child' ) . '" href="#" serial="' . $backup['serial'] . '" class="mainwp_bb_view_details_lnk thickbox">View Details</a>';

				$sumLogFile = \backupbuddy_core::getLogDirectory() . 'status-' . $backup['serial'] . '_' . \pb_backupbuddy::$options['log_serial'] . '.txt';
				if ( file_exists( $sumLogFile ) ) {
					$status .= '<div class="row-actions"><a title="' . esc_html__( 'View Backup Log', 'mainwp-child' ) . '" href="#" serial="' . $backup['serial'] . '"  class="mainwp_bb_view_log_lnk thickbox">' . esc_html__( 'View Log', 'mainwp-child' ) . '</a></div>';
				}

				$status .= '</div>';

				// Calculate finish time (if finished).
				if ( $backup['finish_time'] > 0 ) {
					$finish_time = \pb_backupbuddy::$format->date( \pb_backupbuddy::$format->localize_time( $backup['finish_time'] ) ) . '<br><span class="description">' . \pb_backupbuddy::$format->time_ago( $backup['finish_time'] ) . ' ago</span>';
				} else { // unfinished.
					$finish_time = '<i>Unfinished</i>';
				}

				$backupTitle = '<span class="backupbuddyFileTitle" style="color: #000;" title="' . basename( $backup['archive_file'] ) . '">' . \pb_backupbuddy::$format->date( \pb_backupbuddy::$format->localize_time( $backup['start_time'] ), 'l, F j, Y - g:i:s a' ) . ' (' . \pb_backupbuddy::$format->time_ago( $backup['start_time'] ) . ' ago)</span><br><span class="description">' . basename( $backup['archive_file'] ) . '</span>';

				if ( isset( $backup['profile'] ) && isset( $backup['profile']['type'] ) ) {
					$backupType = '<div><span style="color: #AAA; float: left;">' . \pb_backupbuddy::$format->prettify( $backup['profile']['type'], $pretty_type ) . '</span><span style="display: inline-block; float: left; height: 15px; border-right: 1px solid #EBEBEB; margin-left: 6px; margin-right: 6px;"></span>' . $backup['profile']['title'] . '</div>';
				} else {
					$backupType = \backupbuddy_core::pretty_backup_type( \backupbuddy_core::getBackupTypeFromFile( $backup['archive_file'] ) );
					if ( '' == $backupType ) {
						$backupType = '<span class="description">Unknown</span>';
					}
				}

				if ( isset( $backup['archive_size'] ) && ( $backup['archive_size'] > 0 ) ) {
					$archive_size = \pb_backupbuddy::$format->file_size( $backup['archive_size'] );
				} else {
					$archive_size = 'n/a';
				}

				// No integrity check for themes or plugins types.
				$raw_type = \backupbuddy_core::getBackupTypeFromFile( $backup['archive_file'] );
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
				esc_html__( 'Recently Made Backups (Start Time)', 'mainwp-child' ),
				esc_html__( 'Type | Profile', 'mainwp-child' ),
				esc_html__( 'File Size', 'mainwp-child' ),
				esc_html__( 'Trigger', 'mainwp-child' ),
				esc_html__( 'Status', 'mainwp-child' ) . ' <span class="description">(hover for options)</span>',
			);

            /**
             * Backupbuddy Sort.
             *
             * @param array $array Array to sort.
             * @param strign $key Sort key.
             *
             * @return array $array Return sorted array.
             */
			function pb_backupbuddy_aasort( &$array, $key ) {
				$sorter = array();
				$ret    = array();
				reset( $array );
				foreach ( $array as $ii => $va ) {
					$sorter[ $ii ] = $va[ $key ];
				}
				asort( $sorter );
				foreach ( $sorter as $ii => $va ) {
					$ret[ $ii ] = $array[ $ii ];
				}
				$array = $ret;
			}

			pb_backupbuddy_aasort( $recentBackups, 'start_timestamp' ); // Sort by multidimensional array with key start_timestamp.
			$recentBackups = array_reverse( $recentBackups ); // Reverse array order to show newest first.
		}

		return $recentBackups;
	}

    /**
     * Delete sheduled backup.
     *
     * @return array $information Return results array.
     *
     * @uses \pb_backupbuddy::$options
     * @uses \pb_backupbuddy::save()
     */
    public function delete_scheduled_backup() {
		$schedule_ids = isset( $_POST['schedule_ids'] ) ? wp_unslash( $_POST['schedule_ids'] ) : '';
		$schedule_ids = explode( ',', $schedule_ids );

		if ( empty( $schedule_ids ) ) {
			return array( 'error' => esc_html__( 'Empty schedule ids', 'mainwp-child' ) );
		}
		foreach ( $schedule_ids as $sch_id ) {
			if ( isset( \pb_backupbuddy::$options['schedules'][ $sch_id ] ) ) {
				unset( \pb_backupbuddy::$options['schedules'][ $sch_id ] );
			}
		}
		\pb_backupbuddy::save();
		$information['result'] = 'SUCCESS';
		return $information;
	}

    /**
     * View log.
     *
     * @return array|string[] Return log html.
     *
     * @uses \backupbuddy_core::getLogDirectory()
     * @uses \pb_backupbuddy::$format::date()
     * @uses \pb_backupbuddy::$format::time_ago()
     * @uses \pb_backupbuddy::flush()
     */
    public function view_log() {
		$serial  = isset( $_POST['serial'] ) ? sanitize_text_field( wp_unslash( $_POST['serial'] ) ) : '';
		$logFile = \backupbuddy_core::getLogDirectory() . 'status-' . $serial . '_sum_' . \pb_backupbuddy::$options['log_serial'] . '.txt';

		if ( ! file_exists( $logFile ) ) {
			return array( 'error' => 'Error #858733: Log file `' . $logFile . '` not found or access denied.' );
		}

		$lines = file_get_contents( $logFile );
		$lines = explode( "\n", $lines );
		ob_start();
		?>

		<textarea readonly="readonly" id="backupbuddy_messages" wrap="off" style="width: 100%; min-height: 400px; height: 500px; height: 80%; background: #FFF;">
		<?php
		foreach ( (array) $lines as $rawline ) {
			$line = json_decode( $rawline, true );
			if ( is_array( $line ) ) {
				$u = '';
				if ( isset( $line['u'] ) ) {
					$u = '.' . $line['u'];
				}
				echo \pb_backupbuddy::$format->date( $line['time'], 'G:i:s' ) . $u . "\t\t";
				echo $line['run'] . "sec\t";
				echo $line['mem'] . "MB\t";
				echo $line['event'] . "\t";
				echo $line['data'] . "\n";
			} else {
				echo $rawline . "\n";
			}
		}
		?>
			</textarea><br><br>
		<small>Log file: <?php echo $logFile; ?></small>
		<br>
		<?php
		echo '<small>Last modified: ' . \pb_backupbuddy::$format->date( filemtime( $logFile ) ) . ' (' . \pb_backupbuddy::$format->time_ago( filemtime( $logFile ) ) . ' ago)';
		?>
		<br><br>
		<?php
		$html = ob_get_clean();
		\pb_backupbuddy::flush();
		return array(
			'result'   => 'SUCCESS',
			'html_log' => $html,
		);
	}
	
	
	/**
	 * Pretty results.
	 *
	 * @param bool $value Results TRUE|FALSE.
	 *
	 * @return string Return Pass or Fail message.
	 */
	function pb_pretty_results( $value ) {
		if ( true === $value ) {
			return '<span class="pb_label pb_label-success">Pass</span>';
		} else {
			return '<span class="pb_label pb_label-important">Fail</span>';
		}
	}
				

    /**
     * View details.
     *
     * @return array|string[] Return results array & echo details.
     *
     * @uses \backupbuddy_core::getLogDirectory()
     * @uses \backupbuddy_core::getZipMeta()
     * @uses \backupbuddy_core::getBackupDirectory()
     * @uses \pb_backupbuddy_fileoptions()
     * @uses \pb_backupbuddy_fileoptions::is_ok()
     * @uses \pb_backupbuddy::$format::date()
     * @uses \pb_backupbuddy::$format::localize_time()
     * @uses \pb_backupbuddy::$ui::list_table()
     * @uses \pb_backupbuddy::load()
     * @uses \pb_backupbuddy::plugin_path()
     * @uses \pb_backupbuddy::status()
     * @uses \pb_backupbuddy::flush()
     */
    public function view_detail() {

		$serial = isset( $_POST['serial'] ) ? sanitize_text_field( wp_unslash( $_POST['serial'] ) ) : '';
		$serial = str_replace( '/\\', '', $serial );
		\pb_backupbuddy::load();

		require_once \pb_backupbuddy::plugin_path() . '/classes/fileoptions.php';
		\pb_backupbuddy::status( 'details', 'Fileoptions instance #27.' );
		$optionsFile    = \backupbuddy_core::getLogDirectory() . 'fileoptions/' . $serial . '.txt';
		$backup_options = new \pb_backupbuddy_fileoptions( $optionsFile, $read_only = true );
		$result         = $backup_options->is_ok();
		if ( true !== $result ) {
			return array( 'error' => esc_html__( 'Unable to access fileoptions data file.', 'mainwp-child' ) . ' Error: ' . $result );
		}
		ob_start();
		$integrity = $backup_options->options['integrity'];

		$start_time  = 'Unknown';
		$finish_time = 'Unknown';
		if ( isset( $backup_options->options['start_time'] ) ) {
			$start_time = \pb_backupbuddy::$format->date( \pb_backupbuddy::$format->localize_time( $backup_options->options['start_time'] ) ) . ' <span class="description">(' . \pb_backupbuddy::$format->time_ago( $backup_options->options['start_time'] ) . ' ago)</span>';
			if ( $backup_options->options['finish_time'] > 0 ) {
				$finish_time = \pb_backupbuddy::$format->date( \pb_backupbuddy::$format->localize_time( $backup_options->options['finish_time'] ) ) . ' <span class="description">(' . \pb_backupbuddy::$format->time_ago( $backup_options->options['finish_time'] ) . ' ago)</span>';
			} else { // unfinished.
				$finish_time = '<i>Unfinished</i>';
			}
		}

		// ***** BEGIN TESTS AND RESULTS.
		if ( isset( $integrity['status_details'] ) ) {
			echo '<h3>Integrity Technical Details</h3>';
			echo '<textarea style="width: 100%; height: 175px;" wrap="off">';
			foreach ( $integrity as $item_name => $item_value ) {
				$item_value = str_replace( '<br />', '<br>', $item_value );
				$item_value = str_replace( '<br><br>', '<br>', $item_value );
				$item_value = str_replace( '<br>', "\n     ", $item_value );
				echo $item_name . ' => ' . $item_value . "\n";
			}
			echo '</textarea><br><br><b>Note:</b> It is normal to see several "file not found" entries as BackupBuddy checks for expected files in multiple locations, expecting to only find each file once in one of those locations.';
		} else {

			echo '<br>';

			if ( isset( $integrity['status_details'] ) ) { // PRE-v4.0 Tests.


				// The tests & their status.
				$tests   = array();
				$tests[] = array( 'BackupBackup data file exists', $this->pb_pretty_results( $integrity['status_details']['found_dat'] ) );
				$tests[] = array( 'Database SQL file exists', $this->pb_pretty_results( $integrity['status_details']['found_sql'] ) );
				if ( 'full' == $integrity['detected_type'] ) {
					$tests[] = array( 'WordPress wp-config.php exists (full/files backups only)', $this->pb_pretty_results( $integrity['status_details']['found_wpconfig'] ) );
				} elseif ( 'files' == $integrity['detected_type'] ) {
					$tests[] = array( 'WordPress wp-config.php exists (full/files backups only)', $this->pb_pretty_results( $integrity['status_details']['found_wpconfig'] ) );
				} else { // DB only.
					$tests[] = array( 'WordPress wp-config.php exists (full/files backups only)', '<span class="pb_label pb_label-success">N/A</span>' );
				}
			} else { // 4.0+ Tests.
				$tests = array();
				if ( isset( $integrity['tests'] ) ) {
					foreach ( (array) $integrity['tests'] as $test ) {
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
				esc_html__( 'Integrity Test', 'mainwp-child' ),
				esc_html__( 'Status', 'mainwp-child' ),
			);

			\pb_backupbuddy::$ui->list_table(
				$tests,
				array(
					'columns'       => $columns,
					'css'           => 'width: 100%; min-width: 200px;',
				)
			);

		}
		echo '<br><br>';
		// ***** END TESTS AND RESULTS.

		// Output meta info table (if any).
		$metaInfo = array();
		$metaInfo = \backupbuddy_core::getZipMeta( \backupbuddy_core::getBackupDirectory() . $integrity['file'] );
		if ( isset( $integrity['file'] ) && ( false === $metaInfo ) ) {
			echo '<i>No meta data found in zip comment. Skipping meta information display.</i>';
		} else {
			\pb_backupbuddy::$ui->list_table(
				$metaInfo,
				array(
					'columns'       => array( 'Backup Details', 'Value' ),
					'css'           => 'width: 100%; min-width: 200px;',
				)
			);
		}
		echo '<br><br>';

		// ***** BEGIN STEPS.
		$steps   = array();
		$steps[] = array( 'Start Time', $start_time, '' );
		if ( isset( $backup_options->options['steps'] ) ) {
			foreach ( $backup_options->options['steps'] as $step ) {
				if ( isset( $step['finish_time'] ) && ( 0 != $step['finish_time'] ) ) {

					// Step name.
					if ( 'backup_create_database_dump' == $step['function'] ) {
						if ( count( $step['args'][0] ) == 1 ) {
							$step_name = 'Database dump (breakout: ' . $step['args'][0][0] . ')';
						} else {
							$step_name = 'Database dump';
						}
					} elseif ( 'backup_zip_files' == $step['function'] ) {
						if ( isset( $backup_options->options['steps']['backup_zip_files'] ) ) {
							$zip_time = $backup_options->options['steps']['backup_zip_files'];
						} else {
							$zip_time = 0;
						}

						// Calculate write speed in MB/sec for this backup.
						if ( '0' == $zip_time ) { // Took approx 0 seconds to backup so report this speed.
							$write_speed = '> ' . \pb_backupbuddy::$format->file_size( $backup_options->options['integrity']['size'] );
						} else {
							if ( 0 == $zip_time ) {
								$write_speed = '';
							} else {
								$write_speed = \pb_backupbuddy::$format->file_size( $backup_options->options['integrity']['size'] / $zip_time ) . '/sec';
							}
						}
						$step_name = 'Zip archive creation (Write speed: ' . $write_speed . ')';
					} elseif ( 'post_backup' == $step['function'] ) {
						$step_name = 'Post-backup cleanup';
					} elseif ( 'integrity_check' == $step['function'] ) {
						$step_name = 'Integrity Check';
					} else {
						$step_name = $step['function'];
					}

					// Step time taken.
					$seconds = (int) ( $step['finish_time'] - $step['start_time'] );
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
			}
		} else {
			$step_times[] = 'unknown';
		}

		// Total overall time from initiation to end.
		if ( isset( $backup_options->options['finish_time'] ) && isset( $backup_options->options['start_time'] ) && ( 0 != $backup_options->options['finish_time'] ) && ( 0 != $backup_options->options['start_time'] ) ) {
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
			esc_html__( 'Backup Steps', 'mainwp-child' ),
			esc_html__( 'Time', 'mainwp-child' ),
			esc_html__( 'Attempts', 'mainwp-child' ),
		);

		if ( count( $steps ) == 0 ) {
			esc_html_e( 'No step statistics were found for this backup.', 'mainwp-child' );
		} else {
			\pb_backupbuddy::$ui->list_table(
				$steps,
				array(
					'columns'       => $columns,
					'css'           => 'width: 100%; min-width: 200px;',
				)
			);
		}
		echo '<br><br>';
		// ***** END STEPS.

		if ( isset( $backup_options->options['trigger'] ) ) {
			$trigger = $backup_options->options['trigger'];
		} else {
			$trigger = 'Unknown trigger';
		}
		if ( isset( $integrity['scan_time'] ) ) {
			$scanned = \pb_backupbuddy::$format->date( \pb_backupbuddy::$format->localize_time( $integrity['scan_time'] ) );
			echo ucfirst( $trigger ) . " backup {$integrity['file']} last scanned {$scanned}.";
		}
		echo '<br><br><br>';

		echo '<a class="button secondary-button" onclick="jQuery(\'#pb_backupbuddy_advanced_debug\').slideToggle();">Display Advanced Debugging</a>';
		echo '<div id="pb_backupbuddy_advanced_debug" style="display: none;">From options file: `' . $optionsFile . '`.<br>';
		echo '<textarea style="width: 100%; height: 400px;" wrap="on">';
		echo print_r( $backup_options->options, true ); // phpcs:ignore -- debug feature.
		echo '</textarea><br><br>';
		echo '</div><br><br>';

		$html = ob_get_clean();
		\pb_backupbuddy::flush();
		return array(
			'result'      => 'SUCCESS',
			'html_detail' => $html,
		);
	}

    /**
     * Reset Integrity.
     *
     * @return array $information Return results array.
     */
    public function reset_integrity() {
		$_GET['reset_integrity']    = isset( $_POST['reset_integrity'] ) ? sanitize_text_field( wp_unslash( $_POST['reset_integrity'] ) ) : '';
		$information['backup_list'] = $this->get_backup_list();
		$information['result']      = 'SUCCESS';
		return $information;
	}

    /**
     * Download backup archive.
     *
     * @uses \MainWP\Child\MainWP_Utility::verify_nonce_without_session()
     * @uses \pb_backupbuddy::_GET()
     * @uses \pb_backupbuddy::$option
     * @uses \backupbuddy_core::verifyAjaxAccess()
     * @uses \backupbuddy_core::backup_prefix()
     * @uses \backupbuddy_core::getBackupDirectory()
     */
    public function download_archive() {

		if ( ! isset( $_GET['_wpnonce'] ) || empty( $_GET['_wpnonce'] ) ) {
			die( '-1' );
		}

		if ( ! MainWP_Utility::verify_nonce_without_session( $_GET['_wpnonce'], 'mainwp_download_backup' ) ) {
			die( '-2' );
		}

		\backupbuddy_core::verifyAjaxAccess();

		if ( is_multisite() && ! current_user_can( 'manage_network' ) ) { // If a Network and NOT the superadmin must make sure they can only download the specific subsite backups for security purposes.

			if ( ! strstr( \pb_backupbuddy::_GET( 'backupbuddy_backup' ), \backupbuddy_core::backup_prefix() ) ) {
				die( 'Access Denied. You may only download backups specific to your Multisite Subsite. Only Network Admins may download backups for another subsite in the network.' );
			}
		}

		if ( ! file_exists( \backupbuddy_core::getBackupDirectory() . \pb_backupbuddy::_GET( 'backupbuddy_backup' ) ) ) { // Does not exist.
			die( 'Error #548957857584784332. The requested backup file does not exist. It may have already been deleted.' );
		}

		$abspath    = str_replace( '\\', '/', ABSPATH );
		$backup_dir = str_replace( '\\', '/', \backupbuddy_core::getBackupDirectory() );

		if ( false === stristr( $backup_dir, $abspath ) ) {
			die( 'Error #5432532. You cannot download backups stored outside of the WordPress web root. Please use FTP or other means.' );
		}

		$sitepath     = str_replace( $abspath, '', $backup_dir );
		$download_url = rtrim( site_url(), '/\\' ) . '/' . trim( $sitepath, '/\\' ) . '/' . \pb_backupbuddy::_GET( 'backupbuddy_backup' );

		if ( '1' == \pb_backupbuddy::$options['lock_archives_directory'] ) {

			if ( file_exists( \backupbuddy_core::getBackupDirectory() . '.htaccess' ) ) {
				$unlink_status = unlink( \backupbuddy_core::getBackupDirectory() . '.htaccess' );
				if ( false === $unlink_status ) {
					die( 'Error #844594. Unable to temporarily remove .htaccess security protection on archives directory to allow downloading. Please verify permissions of the BackupBuddy archives directory or manually download via FTP.' );
				}
			}

			header( 'Location: ' . $download_url );
			ob_clean();
			flush();
			sleep( 8 );

			$htaccess_creation_status = file_put_contents( \backupbuddy_core::getBackupDirectory() . '.htaccess', 'deny from all' );
			if ( false === $htaccess_creation_status ) {
				die( 'Error #344894545. Security Warning! Unable to create security file (.htaccess) in backups archive directory. This file prevents unauthorized downloading of backups should someone be able to guess the backup location and filenames. This is unlikely but for best security should be in place. Please verify permissions on the backups directory.' );
			}
		} else {
			header( 'Location: ' . $download_url );
		}
		die();
	}

    /**
     * Create backup.
     *
     * @return array|string[] Return SUCCESS message or ERROR message.
     *
     * @uses \pb_backupbuddy::$options
     * @uses \pb_backupbuddy_backup()
     * @uses \pb_backupbuddy::plugin_path()
     * @uses \pb_backupbuddy::random_string()
     * @uses \pb_backupbuddy_backup::start_backup_process()
     */
    public function create_backup() {
		$requested_profile = isset( $_POST['profile_id'] ) ? sanitize_text_field( wp_unslash( $_POST['profile_id'] ) ) : 0;

		if ( ! isset( \pb_backupbuddy::$options['profiles'][ $requested_profile ] ) ) {
			return array( 'error' => 'Invalid Profile. Not found.' );
		}

		require_once \pb_backupbuddy::plugin_path() . '/classes/backup.php';
		$newBackup = new \pb_backupbuddy_backup();

		$profile_array   = \pb_backupbuddy::$options['profiles'][ $requested_profile ];
		$serial_override = \pb_backupbuddy::random_string( 10 );

		if ( true !== $newBackup->start_backup_process( $profile_array, 'manual', array(), isset( $_POST['post_backup_steps'] ) && is_array( $_POST['post_backup_steps'] ) ? wp_unslash( $_POST['post_backup_steps'] ) : array(), '', $serial_override, '', '', '' ) ) {
			return array( 'error' => esc_html__( 'Fatal Error #4344443: Backup failure. Please see any errors listed in the Status Log for details.', 'mainwp-child' ) );
		}
		return array( 'result' => 'SUCCESS' );
	}

    /**
     * Start backup.
     *
     * @return array|int[]|string[] Return results array containing either OK->1 OR ERROR message.
     *
     * @uses \pb_backupbuddy::plugin_path()
     * @uses \pb_backupbuddy_backup()
     * @uses \pb_backupbuddy_backup::start_backup_process()
     */
    public function start_backup() {
		require_once \pb_backupbuddy::plugin_path() . '/classes/backup.php';
		$newBackup = new \pb_backupbuddy_backup();
		$data      = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : '';
		if ( is_array( $data ) && isset( $data['serial_override'] ) ) {
			if ( $newBackup->start_backup_process(
				$data['profile_array'],
				$data['trigger'],
				array(),
				isset( $data['post_backup_steps'] ) && is_array( $data['post_backup_steps'] ) ? $data['post_backup_steps'] : array(),
				'',
				$data['serial_override'],
				isset( $data['export_plugins'] ) ? $data['export_plugins'] : '',
				$data['direction'],
				isset( $data['deployDestination'] ) ? $data['deployDestination'] : ''
			) !== true ) {
				return array( 'error' => esc_html__( 'Fatal Error #4344443: Backup failure. Please see any errors listed in the Status Log for details.', 'mainwp-child' ) );
			}
		} else {
			return array( 'error' => 'Invalid backup request.' );
		}

		return array( 'ok' => 1 );
	}

    /**
     * Backup status.
     *
     * @return array|string[] Return results array or ERROR message on failure.
     *
     * @uses \backupbuddy_api::getBackupStatus()
     *
     */
    public function backup_status() {
		$data   = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : '';
		$result = '';
		if ( is_array( $data ) && isset( $data['serial'] ) ) {
			ob_start();
			\backupbuddy_api::getBackupStatus( $data['serial'], $data['specialAction'], $data['initwaitretrycount'], $data['sqlFile'], $echo = true );
			$result = ob_get_clean();
		} else {
			return array( 'error' => 'Invalid backup request.' );
		}
		return array(
			'ok'     => 1,
			'result' => $result,
		);
	}

    /**
     * Stop Backup.
     *
     * @return int[] Return 1 on success.
     */
    public function stop_backup() {
		$serial = isset( $_POST['serial'] ) ? wp_unslash( $_POST['serial'] ) : '';
		set_transient( 'pb_backupbuddy_stop_backup-' . $serial, true, ( 60 * 60 * 24 ) );
		return array( 'ok' => 1 );
	}

    /**
     * Remove Save.
     *
     * @return int[]|string[] Return 1 on success or ERROR message on failure.
     *
     * @uses \pb_backupbuddy::$options
     * @uses \pb_backupbuddy::save()
     */
    public function remote_save() {
		$data           = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : false;
		$destination_id = isset( $_POST['destination_id'] ) ? sanitize_text_field( wp_unslash( $_POST['destination_id'] ) ) : 0;

		if ( is_array( $data ) && isset( $data['do_not_override'] ) ) {

			if ( true == $data['do_not_override'] ) {
				if ( ( 's32' == $data['type'] || 's33' == $data['type'] ) ) {
					$not_override = array(
						'accesskey',
						'secretkey',
						'bucket',
						'region',
					);
					foreach ( $not_override as $opt ) {
						if ( isset( $data[ $opt ] ) ) {
							unset( $data[ $opt ] );
						}
					}
				}
			}

			unset( $data['do_not_override'] );
		}

		if ( is_array( $data ) ) {
			if ( isset( \pb_backupbuddy::$options['remote_destinations'][ $destination_id ] ) ) {
				\pb_backupbuddy::$options['remote_destinations'][ $destination_id ] = array_merge( \pb_backupbuddy::$options['remote_destinations'][ $destination_id ], $data );
			} else {
				$data['token'] = \pb_backupbuddy::$options['dropboxtemptoken'];
				\pb_backupbuddy::$options['remote_destinations'][ $destination_id ] = $data;
			}
			\pb_backupbuddy::save();
			return array( 'ok' => 1 );
		} else {
			return array( 'error' => 'Invalid request.' );
		}
	}

    /**
     * Remove backup list.
     *
     * @return array $information Return results array.
     *
     * @uses \pb_backupbuddy::$options
     */
    public function remote_list() {
		$information = array();
		if ( isset( \pb_backupbuddy::$options['remote_destinations'] ) ) {
			$information['remote_destinations'] = \pb_backupbuddy::$options['remote_destinations'];
		}
		$information['result'] = 'SUCCESS';
		return $information;
	}

    /**
     * Remote backup delete.
     *
     * @return array|int[]|string[] Returm OK on success or ERROR message on failure.
     *
     * @uses \pb_backupbuddy::plugin_path()
     * @uses \pb_backupbuddy_destinations::delete_destination()
     */
    public function remote_delete() {
		$destination_id = isset( $_POST['destination_id'] ) ? sanitize_text_field( wp_unslash( $_POST['destination_id'] ) ) : null;
		if ( null !== $destination_id ) {
			require_once \pb_backupbuddy::plugin_path() . '/destinations/bootstrap.php';
			$delete_response = \pb_backupbuddy_destinations::delete_destination( $destination_id, true );

			if ( true !== $delete_response ) {
				return array( 'error' => $delete_response );
			} else {
				return array( 'ok' => 1 );
			}
		} else {
			return array( 'error' => 'Invalid request.' );
		}
	}

    /**
     * Remove send.
     *
     * @return array|int[]|string[] Return 1 on sucess or ERROR message on failure.
     *
     * @uses \backupbuddy_core::getBackupDirectory()
     * @uses \backupbuddy_core::status()
     * @uses \backupbuddy_core::schedule_single_event()
     * @uses \pb_backupbuddy::$options
     * @uses \pb_backupbuddy::plugin_path()
     * @uses \pb_backupbuddy_destinations::get_info()
     * @uses \pb_backupbuddy_destination_stash::get_quota()
     * @uses \pb_backupbuddy::$format::file_size()
     */
    public function remote_send() {

		$destination_id = isset( $_POST['destination_id'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['destination_id'] ) ) : null;
		$file           = isset( $_POST['file'] ) ? wp_unslash( $_POST['file'] ) : null;
		$trigger        = isset( $_POST['trigger'] ) ? wp_unslash( $_POST['trigger'] ) : 'manual';

		if ( 'importbuddy.php' != $file ) {
			$backup_file = \backupbuddy_core::getBackupDirectory() . $file;
			if ( ! file_exists( $backup_file ) ) { // Error if file to send did not exist!
				$error_message = 'Unable to find file `' . $backup_file . '` to send. File does not appear to exist. You can try again in a moment or turn on full error logging and try again to log for support.';
				\pb_backupbuddy::status( 'error', $error_message );
				return array( 'error' => $error_message );
			}
			if ( is_dir( $backup_file ) ) { // Error if a directory is trying to be sent.
				$error_message = 'You are attempting to send a directory, `' . $backup_file . '`. Try again and verify there were no javascript errors.';
				\pb_backupbuddy::status( 'error', $error_message );
				return array( 'error' => $error_message );
			}
		} else {
			$backup_file = '';
		}

		if ( isset( $_POST['send_importbuddy'] ) && '1' == $_POST['send_importbuddy'] ) {
			$send_importbuddy = true;
			\pb_backupbuddy::status( 'details', 'Cron send to be scheduled with importbuddy sending.' );
		} else {
			$send_importbuddy = false;
			\pb_backupbuddy::status( 'details', 'Cron send to be scheduled WITHOUT importbuddy sending.' );
		}

		if ( isset( $_POST['delete_after'] ) && '1' == $_POST['delete_after'] ) {
			$delete_after = true;
			\pb_backupbuddy::status( 'details', 'Remote send set to delete after successful send.' );
		} else {
			$delete_after = false;
			\pb_backupbuddy::status( 'details', 'Remote send NOT set to delete after successful send.' );
		}

		if ( ! isset( \pb_backupbuddy::$options['remote_destinations'][ $destination_id ] ) ) {
			return array( 'error' => 'Error #833383: Invalid destination ID `' . htmlentities( $destination_id ) . '`.' );
		}

		// For Stash we will check the quota prior to initiating send.
		if ( 'stash' == \pb_backupbuddy::$options['remote_destinations'][ $destination_id ]['type'] ) {
			// Pass off to destination handler.
			require_once \pb_backupbuddy::plugin_path() . '/destinations/bootstrap.php';
			$send_result = \pb_backupbuddy_destinations::get_info( 'stash' ); // Used to kick the Stash destination into life.
			$stash_quota = \pb_backupbuddy_destination_stash::get_quota( \pb_backupbuddy::$options['remote_destinations'][ $destination_id ], true );

			if ( isset( $stash_quota['error'] ) ) {
				return array( 'error' => ' Error accessing Stash account. Send aborted. Details: `' . implode( ' - ', $stash_quota['error'] ) . '`.' );
			}

			if ( '' != $backup_file ) {
				$backup_file_size = filesize( $backup_file );
			} else {
				$backup_file_size = 50000;
			}
			if ( ( $backup_file_size + $stash_quota['quota_used'] ) > $stash_quota['quota_total'] ) {
				ob_start();
				echo "You do not have enough Stash storage space to send this file. Please upgrade your Stash storage or delete files to make space.\n\n";
				echo 'Attempting to send file of size ' . \pb_backupbuddy::$format->file_size( $backup_file_size ) . ' but you only have ' . $stash_quota['quota_available_nice'] . ' available. ';
				echo 'Currently using ' . $stash_quota['quota_used_nice'] . ' of ' . $stash_quota['quota_total_nice'] . ' (' . $stash_quota['quota_used_percent'] . '%).';
				$error = ob_get_clean();
				return array( 'error' => $error );
			} else {
				if ( isset( $stash_quota['quota_warning'] ) && ( '' != $stash_quota['quota_warning'] ) ) {
					$warning        = 'Warning: ' . $stash_quota['quota_warning'] . "\n\n";
					$success_output = true;
				}
			}
		}

		\pb_backupbuddy::status( 'details', 'Scheduling cron to send to this remote destination...' );

		$schedule_result = \backupbuddy_core::schedule_single_event( time(), 'remote_send', array( $destination_id, $backup_file, $trigger, $send_importbuddy, $delete_after ) );
		if ( false === $schedule_result ) {
			$error = 'Error scheduling file transfer. Please check your BackupBuddy error log for details. A plugin may have prevented scheduling or the database rejected it.';
			\pb_backupbuddy::status( 'error', $error );
			return array( 'error' => $error );
		} else {
			\pb_backupbuddy::status( 'details', 'Cron to send to remote destination scheduled.' );
		}
		if ( '1' != \pb_backupbuddy::$options['skip_spawn_cron_call'] ) {
			update_option( '_transient_doing_cron', 0 ); // Prevent cron-blocking for next item.
			spawn_cron( time() + 150 ); // Adds > 60 seconds to get around once per minute cron running limit.
		}
		return array( 'ok' => 1 );
	}

    /**
     * Get main log.
     *
     * @return array Return result array or ERROR message on failure.
     *
     * @uses \backupbuddy_core::getLogDirectory()
     * @uses \pb_backupbuddy::$options
     */
    public function get_main_log() {
		$log_file = \backupbuddy_core::getLogDirectory() . 'log-' . \pb_backupbuddy::$options['log_serial'] . '.txt';
		ob_start();
		if ( file_exists( $log_file ) ) {
			readfile( $log_file );
		} else {
			echo esc_html__( 'Nothing has been logged.', 'mainwp-child' );
		}
		$result = ob_get_clean();
		return array( 'result' => $result );
	}

    /**
     * Other settings.
     *
     * @return string[] Return results array.
     *
     * @uses \pb_backupbuddy::plugin_path()
     * @uses \backupbuddy_housekeeping::run_periodic()
     * @uses \backupbuddy_core::getTempDirectory()
     * @uses \backupbuddy_core::getLogDirectory()
     * @uses \backupbuddy_core::getLogDirectory()
     * @uses \pb_backupbuddy::$filesystem->unlink_recursive()
     * @uses \pb_backupbuddy::anti_directory_browsing()
     * @uses \pb_backupbuddy::save()
     * @uses \pb_backupbuddy_fileoptions()
     * @uses \pb_backupbuddy_fileoptions::is_ok()
     */
    public function settings_other() {

		$other_action = isset( $_POST['other_action'] ) ? wp_unslash( $_POST['other_action'] ) : '';

		$message = '';
		$error   = '';

		if ( 'cleanup_now' == $other_action ) {
			$message = 'Performing cleanup procedures now trimming old files and data.';
			require_once \pb_backupbuddy::plugin_path() . '/classes/housekeeping.php';
			\backupbuddy_housekeeping::run_periodic( 0 ); // 0 cleans up everything even if not very old.

		} elseif ( 'delete_tempfiles_now' == $other_action ) {
			$tempDir = \backupbuddy_core::getTempDirectory();
			$logDir  = \backupbuddy_core::getLogDirectory();
			$message = 'Deleting all files contained within `' . $tempDir . '` and `' . $logDir . '`.';
			\pb_backupbuddy::$filesystem->unlink_recursive( $tempDir );
			\pb_backupbuddy::$filesystem->unlink_recursive( $logDir );
			\pb_backupbuddy::anti_directory_browsing( $logDir, $die = false ); // Put log dir back in place.
		} elseif ( 'reset_log' == $other_action ) {
			$log_file = \backupbuddy_core::getLogDirectory() . 'log-' . \pb_backupbuddy::$options['log_serial'] . '.txt';
			if ( file_exists( $log_file ) ) {
				unlink( $log_file );
			}
			if ( file_exists( $log_file ) ) { // Didnt unlink.
				$error = 'Unable to clear log file. Please verify permissions on file `' . $log_file . '`.';
			} else { // Unlinked.
				$message = 'Cleared log file.';
			}
		} elseif ( 'reset_disalerts' == $other_action ) {
			\pb_backupbuddy::$options['disalerts'] = array();
			\pb_backupbuddy::save();
			$message = 'Dismissed alerts have been reset. They may now be visible again.';

		} elseif ( 'cancel_running_backups' == $other_action ) {
			require_once \pb_backupbuddy::plugin_path() . '/classes/fileoptions.php';

			$fileoptions_directory = \backupbuddy_core::getLogDirectory() . 'fileoptions/';
			$files                 = glob( $fileoptions_directory . '*.txt' );
			if ( ! is_array( $files ) ) {
				$files = array();
			}
			$cancelCount = 0;
			for ( $x = 0; $x <= 3; $x++ ) { // Try this a few times since there may be race conditions on an open file.
				foreach ( $files as $file ) {
					\pb_backupbuddy::status( 'details', 'Fileoptions instance #383.' );

					$backup_options = new \pb_backupbuddy_fileoptions( $file, $read_only = false );
					$result         = $backup_options->is_ok();
					if ( true !== $result ) {
						\pb_backupbuddy::status( 'error', 'Error retrieving fileoptions file `' . $file . '`. Err 335353266.' );
					} else {
						if ( empty( $backup_options->options['finish_time'] ) || ( ( false !== $backup_options->options['finish_time'] ) && ( '-1' != $backup_options->options['finish_time'] ) ) ) {
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

		return array(
			'_error'   => $error,
			'_message' => $message,
		);
	}

    /**
     * Malware scan.
     *
     * @return array $result Return result array.
     *
     * @uses \backupbuddy_core::schedule_single_event()
     * @uses \backupbuddy_core::addNotification()
     * @uses \pb_backupbuddy::$ui::start_metabox()
     * @uses \pb_backupbuddy::alert()
     */
    public function malware_scan() {

		\backupbuddy_core::schedule_single_event( time(), 'housekeeping', array() );
		update_option( '_transient_doing_cron', 0 );
		spawn_cron( time() + 150 );

		ob_start();

		if ( ! defined( 'pluginbuddy_importbuddy' ) ) {
			$url = home_url();
		} else {
			$url = str_replace( $_SERVER['QUERY_STRING'], '', $_SERVER['REQUEST_URI'] );
			$url = str_replace( basename( $url ), '', $url );
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
		\pb_backupbuddy::$ui->start_metabox( esc_html__( 'Malware Scan URL', 'mainwp-child' ), true, 'width: 100%;' );

		?>

		<?php echo $url; ?>

		<?php

		$continue_1 = true;
		if ( 'http://localhost' == $url ) {
			esc_html_e( 'ERROR: You are currently running your site locally. Your site must be internet accessible to scan.', 'mainwp-child' );
			$continue_1 = false;
		}

		if ( true === $continue_1 ) {

			if ( ! empty( $_POST['refresh'] ) ) {
				delete_transient( 'pb_backupbuddy_malwarescan' );
			}

			if ( ! defined( 'pluginbuddy_importbuddy' ) ) {
				$scan = get_transient( 'pb_backupbuddy_malwarescan' );
			} else {
				$scan = false;
			}

			if ( false === $scan ) {
				flush();

				$scan = wp_remote_get(
					'http://sitecheck.sucuri.net/scanner/?scan=' . rawurlencode( $url ) . '&serialized&clear=true',
					array(
						'method'      => 'GET',
						'timeout'     => 45,
						'redirection' => 5,
						'httpversion' => '1.0',
						'blocking'    => true,
						'headers'     => array(),
						'body'        => null,
						'cookies'     => array(),
					)
				);

				if ( is_wp_error( $scan ) ) {
					\pb_backupbuddy::alert( esc_html__( 'ERROR #24452. Unable to load Malware Scan results. Details:', 'mainwp-child' ) . ' ' . $scan->get_error_message(), true );
					$scan = 'N;';
				} else {
					$scan = $scan['body'];
					set_transient( 'pb_backupbuddy_malwarescan', $scan, 60 * 60 * 1 ); // 1 hour cache.
				}
			}

			$continue_2 = true;
			if ( substr( $scan, 0, 2 ) == 'N;' ) {
				echo esc_html__( 'An error was encountered attempting to scan this site.', 'mainwp-child' ), '<br />';
				echo esc_html__( 'An internet connection is required and this site must be accessible on the public internet.', 'mainwp-child' );
				echo '<br>';
				$scan       = array();
				$continue_2 = false;
			} else {
				$scan = maybe_unserialize( $scan ); // safe third party scan result.
			}
		}
		\pb_backupbuddy::$ui->end_metabox();

		if ( true === $continue_2 ) {

            /**
             * Turn array into html li.
             *
             * @param array $array Array of data to convert.
             *
             * @return string Return html list.
             */
			function lined_array( $array ) {
				if ( is_array( $array ) ) {
					foreach ( $array as $array_key => $array_item ) {
						if ( is_array( $array_item ) ) {
							$array[ $array_key ] = lined_array( $array_item );
						}
					}
					$return = '';
					foreach ( $array as $array_item ) {
						$return .= $array_item . '<br />';
					}
					return $return;
				} else {
					if ( empty( $array ) ) {
						return '<i>' . esc_html__( 'none', 'mainwp-child' ) . '</i><br />';
					} else {
						return $array . '<br />';
					}
				}
			}

			if ( ! empty( $scan['MALWARE'] ) && ( 'E' != $scan['MALWARE'] ) ) {
				echo '<table><tr><td><i class="fa fa-exclamation-circle fa-5x" style="color: red"></i></td><td><h1>', esc_html__( 'Warning: Possible Malware Detected!', 'mainwp-child' ), '</h1>', esc_html__( 'See details below.', 'mainwp-child' ), '</td></tr></table>';
			}
			?>
			<div class="postbox-container" style="width: 100%; min-width: 750px;">
				<div class="metabox-holder">
					<div class="meta-box-sortables">

						<div id="breadcrumbslike" class="postbox">
							<div class="handlediv" title="<?php esc_html_e( 'Click to toggle', 'mainwp-child' ); ?>"><br /></div>
							<h3 class="hndle"><span><?php esc_html_e( 'Malware Detection', 'mainwp-child' ); ?></span></h3>
							<div class="inside">
								<label><?php esc_html_e( 'Malware', 'mainwp-child' ); ?></label>
								<?php
								if ( ! empty( $scan['MALWARE']['WARN'] ) ) { // Malware found.
									echo lined_array( $scan['MALWARE']['WARN'] );
									\backupbuddy_core::addNotification( 'malware_found', 'Malware detected on `' . $url . '`.', 'A malware scan was run on the site and detected malware.', array(), true );
								} else { // No malware found.
									echo '<i>', esc_html__( 'none', 'mainwp-child' ), '</i><br />';
									\backupbuddy_core::addNotification( 'malware_not_found', 'No malware detected on `' . $url . '`.', 'A malware scan was run on the site and did not detect malware.' );
								}
								?>
								<br />
							</div>
						</div>

						<div id="breadcrumbslike" class="postbox">
							<div class="handlediv" title="<?php esc_html_e( 'Click to toggle', 'mainwp-child' ); ?>"><br /></div>
							<h3 class="hndle"><span><?php esc_html_e( 'Web server details', 'mainwp-child' ); ?></span></h3>
							<div class="inside">
								<label><?php esc_html_e( 'Site', 'mainwp-child' ); ?></label>
								<?php
								if ( ! empty( $scan['SCAN']['SITE'] ) ) {
									echo lined_array( $scan['SCAN']['SITE'] );
								} else {
									echo '<i>', esc_html__( 'none', 'mainwp-child' ), '</i><br />';
								}
								?>
								<br />
								<label><?php esc_html_e( 'Hostname', 'mainwp-child' ); ?></label>
								<?php
								if ( ! empty( $scan['SCAN']['DOMAIN'] ) ) {
									echo lined_array( $scan['SCAN']['DOMAIN'] );
								} else {
									echo '<i>', esc_html__( 'none', 'mainwp-child' ), '</i><br />';
								}
								?>
								<br />
								<label><?php esc_html_e( 'IP Address', 'mainwp-child' ); ?></label>
								<?php
								if ( ! empty( $scan['SCAN']['IP'] ) ) {
									echo lined_array( $scan['SCAN']['IP'] );
								} else {
									echo '<i>', esc_html__( 'none', 'mainwp-child' ), '</i><br />';
								}
								?>
								<br />
								<label><?php esc_html_e( 'System details', 'mainwp-child' ); ?></label>
								<?php
								if ( ! empty( $scan['SYSTEM']['NOTICE'] ) ) {
									echo lined_array( $scan['SYSTEM']['NOTICE'] );
								} else {
									echo '<i>', esc_html__( 'none', 'mainwp-child' ), '</i><br />';
								}
								?>
								<br />
								<label><?php esc_html_e( 'Information', 'mainwp-child' ); ?></label>
								<?php
								if ( ! empty( $scan['SYSTEM']['INFO'] ) ) {
									echo lined_array( $scan['SYSTEM']['INFO'] );
								} else {
									echo '<i>', esc_html__( 'none', 'mainwp-child' ), '</i><br />';
								}
								?>
								<br />
							</div>
						</div>
						<div id="breadcrumbslike" class="postbox">
							<div class="handlediv" title="Click to toggle"><br /></div>
							<h3 class="hndle"><span><?php esc_html_e( 'Web application', 'mainwp-child' ); ?></span></h3>
							<div class="inside">
								<label><?php esc_html_e( 'Details', 'mainwp-child' ); ?></label>
								<?php
								if ( ! empty( $scan['WEBAPP']['INFO'] ) ) {
									echo lined_array( $scan['WEBAPP']['INFO'] );
								} else {
									echo '<i>', esc_html__( 'none', 'mainwp-child' ), '</i><br />';
								}
								?>
								<br />
								<label><?php esc_html_e( 'Versions', 'mainwp-child' ); ?></label>
								<?php
								if ( ! empty( $scan['WEBAPP']['VERSION'] ) ) {
									echo lined_array( $scan['WEBAPP']['VERSION'] );
								} else {
									echo '<i>',esc_html__( 'none', 'mainwp-child' ), '</i><br />';
								}
								?>
								<br />
								<label><?php esc_html_e( 'Notices', 'mainwp-child' ); ?></label>
								<?php
								if ( ! empty( $scan['WEBAPP']['NOTICE'] ) ) {
									echo lined_array( $scan['WEBAPP']['NOTICE'] );
								} else {
									echo '<i>', esc_html__( 'none', 'mainwp-child' ), '</i><br />'; }
								?>
								<br />
								<label><?php esc_html_e( 'Errors', 'mainwp-child' ); ?></label>
								<?php
								if ( ! empty( $scan['WEBAPP']['ERROR'] ) ) {
									echo lined_array( $scan['WEBAPP']['ERROR'] );
								} else {
									echo '<i>',esc_html__( 'none', 'mainwp-child' ), '</i><br />';
								}
								?>
								<br />
								<label><?php esc_html_e( 'Warnings', 'mainwp-child' ); ?></label>
								<?php
								if ( ! empty( $scan['WEBAPP']['WARN'] ) ) {
									echo lined_array( $scan['WEBAPP']['WARN'] );
								} else {
									echo '<i>', esc_html__( 'none', 'mainwp-child' ), '</i><br />';
								}
								?>
								<br />
							</div>
						</div>

						<div id="breadcrumbslike" class="postbox">
							<div class="handlediv" title="<?php esc_html_e( 'Click to toggle', 'mainwp-child' ); ?>"><br /></div>
							<h3 class="hndle"><span><?php esc_html_e( 'Links', 'mainwp-child' ); ?></span></h3>
							<div class="inside">
								<?php
								if ( ! empty( $scan['LINKS']['URL'] ) ) {
									echo lined_array( $scan['LINKS']['URL'] );
								} else {
									echo '<i>', esc_html__( 'none', 'mainwp-child' ), '</i><br />';
								}
								?>
							</div>
						</div>

						<div id="breadcrumbslike" class="postbox">
							<div class="handlediv" title="<?php esc_html_e( 'Click to toggle', 'mainwp-child' ); ?>"><br /></div>
							<h3 class="hndle"><span><?php esc_html_e( 'Local Javascript', 'mainwp-child' ); ?></span></h3>
							<div class="inside">
								<?php
								if ( ! empty( $scan['LINKS']['JSLOCAL'] ) ) {
									echo lined_array( $scan['LINKS']['JSLOCAL'] );
								} else {
									echo '<i>', esc_html__( 'none', 'mainwp-child' ),'</i><br />';
								}
								?>
							</div>
						</div>

						<div id="breadcrumbslike" class="postbox">
							<div class="handlediv" title="<?php esc_html_e( 'Click to toggle', 'mainwp-child' ); ?>"><br /></div>
							<h3 class="hndle"><span><?php esc_html_e( 'External Javascript', 'mainwp-child' ); ?></span></h3>
							<div class="inside">
								<?php
								if ( ! empty( $scan['LINKS']['JSEXTERNAL'] ) ) {
									echo lined_array( $scan['LINKS']['JSEXTERNAL'] );
								} else {
									echo '<i>', esc_html__( 'none', 'mainwp-child' ), '</i><br />'; }
								?>
							</div>
						</div>

						<div id="breadcrumbslike" class="postbox">
							<div class="handlediv" title="<?php esc_attr_e( 'Click to toggle', 'mainwp-child' ); ?>"><br /></div>
							<h3 class="hndle"><span><?php esc_html_e( 'Iframes Included', 'mainwp-child' ); ?></span></h3>
							<div class="inside">
								<?php
								if ( ! empty( $scan['LINKS']['IFRAME'] ) ) {
									echo lined_array( $scan['LINKS']['IFRAME'] );
								} else {
									echo '<i>', esc_html__( 'none', 'mainwp-child' ), '</i><br />';
								}
								?>
							</div>
						</div>

						<div id="breadcrumbslike" class="postbox">
							<div class="handlediv" title="<?php esc_attr_e( 'Click to toggle', 'mainwp-child' ); ?>"><br /></div>
							<h3 class="hndle"><span><?php esc_html_e( ' Blacklisting Status', 'mainwp-child' ); ?></span></h3>
							<div class="inside">
								<?php
								if ( ! empty( $scan['BLACKLIST']['INFO'] ) ) {
									echo lined_array( $scan['BLACKLIST']['INFO'] );
								} else {
									echo '<i>', esc_html__( 'none', 'mainwp-child' ), '</i><br />';
								}
								?>
							</div>
						</div>

					</div>
				</div>
			</div>
			<?php

		}
		$result = ob_get_clean();

		return array( 'result' => $result );
	}


    /**
     * Live setup.
     *
     * @return array|array[]
     *
     * @uses iThemes_Credentials::get_password_hash()
     * @uses ITXAPI_Helper2::get_access_token()
     * @uses \pb_backupbuddy::plugin_path()
     * @uses \pb_backupbuddy::save()
     * @uses \pb_backupbuddy::status()
     * @uses \pb_backupbuddy_destination_stash2::stashAPI()
     * @uses \pb_backupbuddy::$options
     * @uses \pb_backupbuddy_destination_live::$default_settings
     * @uses \backupbuddy_live::send_trim_settings()
     * @uses \backupbuddy_core::schedule_single_event()
     */
    public function live_setup() {

		$errors = array();

		$archive_types = array(
			'db'      => esc_html__( 'Database Backup', 'mainwp-child' ),
			'full'    => esc_html__( 'Full Backup', 'mainwp-child' ),
			'plugins' => esc_html__( 'Plugins Backup', 'mainwp-child' ),
			'themes'  => esc_html__( 'Themes Backup', 'mainwp-child' ),
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

			require_once \pb_backupbuddy::plugin_path() . '/destinations/stash2/class.itx_helper2.php';
			require_once \pb_backupbuddy::plugin_path() . '/destinations/stash2/init.php';
			require_once \pb_backupbuddy::plugin_path() . '/destinations/live/init.php';

			/** @global string $wp_version WordPress version. */
			global $wp_version;

			$itxapi_username = strtolower( $_POST['live_username'] );
			$password_hash   = \iThemes_Credentials::get_password_hash( $itxapi_username, $_POST['live_password'] );
			$access_token    = \ITXAPI_Helper2::get_access_token( $itxapi_username, $password_hash, site_url(), $wp_version );

			$settings = array(
				'itxapi_username' => $itxapi_username,
				'itxapi_password' => $access_token,
			);
			$response = \pb_backupbuddy_destination_stash2::stashAPI( $settings, 'connect' );

			if ( ! is_array( $response ) ) { // Error message.
				$errors[] = print_r( $response, true ); // phpcs:ignore -- debug feature.
			} else {
				if ( isset( $response['error'] ) ) {
					$errors[] = $response['error']['message'];
				} else {
					if ( isset( $response['token'] ) ) {
						$itxapi_token = $response['token'];
					} else {
						$errors[] = 'Error #2308832: Unexpected server response. Token missing. Check your BackupBuddy Stash Live login and try again. Detailed response: `' . print_r( $response, true ) . '`.'; // phpcs:ignore -- debug feature.
					}
				}
			}

			// If we have the token then create the Live destination.
			if ( isset( $itxapi_token ) ) {
				if ( count( \pb_backupbuddy::$options['remote_destinations'] ) > 0 ) {
					$nextDestKey = max( array_keys( \pb_backupbuddy::$options['remote_destinations'] ) ) + 1;
				} else { // no destinations yet. first index.
					$nextDestKey = 0;
				}

				\pb_backupbuddy::$options['remote_destinations'][ $nextDestKey ]                    = \pb_backupbuddy_destination_live::$default_settings;
				\pb_backupbuddy::$options['remote_destinations'][ $nextDestKey ]['itxapi_username'] = isset( $_POST['live_username'] ) ? wp_unslash( $_POST['live_username'] ) : '';
				\pb_backupbuddy::$options['remote_destinations'][ $nextDestKey ]['itxapi_token']    = $itxapi_token;
				\pb_backupbuddy::$options['remote_destinations'][ $nextDestKey ]['title']           = 'My BackupBuddy Stash Live';

				// Notification email.
				\pb_backupbuddy::$options['remote_destinations'][ $nextDestKey ]['email'] = isset( $_POST['email']  ) ? sanitize_text_field( wp_unslash( $_POST['email'] ) ) : '';

				// Archive limits.
				foreach ( $archive_types as $archive_type => $archive_type_name ) {
					foreach ( $archive_periods as $archive_period ) {
						$settings_name = 'limit_' . $archive_type . '_' . $archive_period;
						\pb_backupbuddy::$options['remote_destinations'][ $nextDestKey ][ $settings_name ] = isset( $_POST['live_settings'][ $settings_name ] ) ? wp_unslash( $_POST['live_settings'][ $settings_name ] ) : '';
					}
				}

				if ( '1' == $_POST['send_snapshot_notification'] ) {
					\pb_backupbuddy::$options['remote_destinations'][ $nextDestKey ]['send_snapshot_notification'] = isset( $_POST['send_snapshot_notification'] ) ? wp_unslash( $_POST['send_snapshot_notification'] ) : '';
				} else {
					\pb_backupbuddy::$options['remote_destinations'][ $nextDestKey ]['send_snapshot_notification'] = '0';
				}

				\pb_backupbuddy::save();
				$destination_id = $nextDestKey;

				// Send new settings for archive limiting to Stash API.
				\backupbuddy_live::send_trim_settings();

				// Set first run of BackupBuddy Stash Live so it begins immediately.
				$cronArgs        = array();
				$schedule_result = \backupbuddy_core::schedule_single_event( time(), 'live_periodic', $cronArgs );
				if ( true === $schedule_result ) {
					\pb_backupbuddy::status( 'details', 'Next Live Periodic chunk step cron event scheduled.' );
				} else {
					\pb_backupbuddy::status( 'error', 'Next Live Periodic chunk step cron event FAILED to be scheduled.' );
				}
				if ( '1' != \pb_backupbuddy::$options['skip_spawn_cron_call'] ) {
					\pb_backupbuddy::status( 'details', 'Spawning cron now.' );
					update_option( '_transient_doing_cron', 0 ); // Prevent cron-blocking for next item.
					spawn_cron( time() + 150 ); // Adds > 60 seconds to get around once per minute cron running limit.
				}
			}
		} // end if user and pass set.

		if ( 0 == count( $errors ) ) {
			\pb_backupbuddy::save();
			$data = \pb_backupbuddy::$options['remote_destinations'][ $destination_id ];
			return array(
				'destination_id' => $destination_id,
				'data'           => $data,
			);
		} else {
			return array( 'errors' => $errors );
		}
	}

    /**
     * Live save settings.
     *
     * @return int[]|string[] Return 1 on success or ERROR message on failure.
     *
     * @uses \pb_backupbuddy::plugin_path()
     * @uses \pb_backupbuddy::$options
     * @uses \pb_backupbuddy::save()
     * @uses \backupbuddy_live::send_trim_settings()
     * @uses \backupbuddy_live::getLiveID()
     * @uses \backupbuddy_live::send_trim_settings()
     */
    public function live_save_settings() {
		$data               = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : array();
		$new_destination_id = isset( $_POST['destination_id'] ) ? sanitize_text_field( wp_unslash( $_POST['destination_id'] ) ) : '';

		require_once \pb_backupbuddy::plugin_path() . '/destinations/bootstrap.php';
		require_once \pb_backupbuddy::plugin_path() . '/destinations/live/live.php';
		require_once \pb_backupbuddy::plugin_path() . '/destinations/live/live_periodic.php';

		$destination_id       = \backupbuddy_live::getLiveID();
		$destination_settings = isset( \pb_backupbuddy::$options['remote_destinations'][ $destination_id ] ) ? \pb_backupbuddy::$options['remote_destinations'][ $destination_id ] : array();

		$check_current = ! empty( $destination_settings ) ? true : false;

		$error = '';
		if ( $new_destination_id && is_array( $data ) ) {
			$itxapi_username                         = isset( $destination_settings['itxapi_username'] ) ? $destination_settings['itxapi_username'] : '';
			$itxapi_token                            = isset( $destination_settings['itxapi_token'] ) ? $destination_settings['itxapi_token'] : '';
			$destination_settings                    = array_merge( $destination_settings, $data );
			$destination_settings['itxapi_username'] = $itxapi_username;
			$destination_settings['itxapi_token']    = $itxapi_token;
			\pb_backupbuddy::$options['remote_destinations'][ $new_destination_id ] = $destination_settings;

			if ( $check_current && $destination_id != $new_destination_id ) {
				unset( \pb_backupbuddy::$options['remote_destinations'][ $destination_id ] );
			}

			\pb_backupbuddy::save();
			set_transient( 'backupbuddy_live_jump', array( 'daily_init', array() ), 60 * 60 * 48 ); // Tells Live process to restart from the beginning (if mid-process) so new settigns apply.

			\backupbuddy_live::send_trim_settings();
			return array( 'ok' => 1 );
		} else {
			$error = 'Invalid data. Please check and try again.';
		}
		return array( 'error' => $error );
	}

    /**
     * Live action disconnect.
     *
     * @return array $return Return 1 on success or ERROR message on failure.
     *
     * @uses \pb_backupbuddy::$options
     * @uses \pb_backupbuddy::save()
     * @uses \pb_backupbuddy::plugin_path()
     */
    public function live_action_disconnect() {
		$error             = '';
		$liveDestinationID = isset( $_POST['destination_id'] ) ? sanitize_text_field( wp_unslash( $_POST['destination_id'] ) ) : '';

		$return = array();
		if ( $liveDestinationID ) {
			if ( isset( \pb_backupbuddy::$options['remote_destinations'][ $liveDestinationID ] ) ) {
				// Clear destination settings.
				unset( \pb_backupbuddy::$options['remote_destinations'][ $liveDestinationID ] );
				\pb_backupbuddy::save();
				// Clear cached Live credentials.
				require_once \pb_backupbuddy::plugin_path() . '/destinations/live/init.php';
				delete_transient( \pb_backupbuddy_destination_live::LIVE_ACTION_TRANSIENT_NAME );
			} else {
				$error = 'Error: destination not found.';
			}
			$return['ok'] = 1;
		} else {
			$error = 'Error: Empty destination id.';
		}

		if ( ! empty( $error ) ) {
			$return['error'] = $error;
		}

		return $return;
	}

    /**
     * Live action.
     *
     * @return array Return results array.
     *
     * @uses \pb_backupbuddy::plugin_path()
     * @uses \pb_backupbuddy::$options
     * @uses \pb_backupbuddy::_GET()
     * @uses \backupbuddy_live_periodic::get_stats()
     * @uses \backupbuddy_live::getLiveID()
     * @uses \backupbuddy_live_periodic::get_destination_settings()
     * @uses \backupbuddy_core::getLogDirectory()
     * @uses \backupbuddy_api::runLiveSnapshot()
     * @uses \backupbuddy_api::setLiveStatus()
     */
    public function live_action() {
		$action  = isset( $_POST['live_action'] ) ? sanitize_text_field( wp_unslash( $_POST['live_action'] ) ) : '';
		$error   = '';
		$message = '';

		require_once \pb_backupbuddy::plugin_path() . '/destinations/live/live_periodic.php';
		$state = \backupbuddy_live_periodic::get_stats();

		$destination_id = \backupbuddy_live::getLiveID();
		$destination    = \backupbuddy_live_periodic::get_destination_settings();

		if ( 'clear_log' == $action ) {
			$sumLogFile = \backupbuddy_core::getLogDirectory() . 'status-live_periodic_' . \pb_backupbuddy::$options['log_serial'] . '.txt';
			unlink( $sumLogFile );
			if ( file_exists( $sumLogFile ) ) {
				$error = 'Error #893489322: Unable to clear log file `' . $sumLogFile . '`. Check permissions or manually delete.';
			} else {
				$message = 'Log file cleared.';
			}
        } elseif ( 'create_snapshot' == $action ) { // < 100% backed up _OR_ ( we are on a step other than daily_init and the last_activity is more recent than the php runtime ).
            if ( true === \backupbuddy_api::runLiveSnapshot() ) {
                $message = '<h3>' . esc_html__( 'Verifying everything is up to date before Snapshot', 'mainwp-child' ) . '</h3><p class="description" style="max-width: 700px; display: inline-block;">' . esc_html__( 'Please wait while we verify your backup is completely up to date before we create the Snapshot. This may take a few minutes...', 'mainwp-child' ) . '</p>';
                require \pb_backupbuddy::plugin_path() . '/destinations/live/_manual_snapshot.php';
            }
		} elseif ( 'pause_periodic' == $action ) {
			$pause_continuous = '';
			$pause_periodic   = true;
			\backupbuddy_api::setLiveStatus( $pause_continuous, $pause_periodic );
			$destination = \pb_backupbuddy::$options['remote_destinations'][ $destination_id ]; // Update local var.
			$message     = esc_html__( 'Live File Backup paused. It may take a moment for current processes to finish.', 'mainwp-child' );
			include \pb_backupbuddy::plugin_path() . '/destinations/live/_stats.php';
		} elseif ( 'resume_periodic' == $action ) {
			$launchNowText = ' ' . esc_html__( 'Unpaused but not running now.', 'mainwp-child' );
			$start_run     = false;
			if ( '1' != \pb_backupbuddy::_GET( 'skip_run_live_now' ) ) {
				$launchNowText = '';
				$start_run     = true;
			}
			$pause_continuous = '';
			$pause_periodic   = false;
			\backupbuddy_api::setLiveStatus( $pause_continuous, $pause_periodic, $start_run );
			$message = esc_html__( 'Live File Backup has resumed.', 'mainwp-child' ) . $launchNowText;
			include \pb_backupbuddy::plugin_path() . '/destinations/live/_stats.php';
		} elseif ( 'pause_continuous' == $action ) {
			$pause_continuous = true;
			$pause_periodic   = '';
			\backupbuddy_api::setLiveStatus( $pause_continuous, $pause_periodic );
			$destination = \pb_backupbuddy::$options['remote_destinations'][ $destination_id ];
			include \pb_backupbuddy::plugin_path() . '/destinations/live/_stats.php'; // Recalculate stats.
			$message = esc_html__( 'Live Database Backup paused.', 'mainwp-child' );
		} elseif ( 'resume_continuous' == $action ) {
			$pause_continuous = false;
			$pause_periodic   = '';
			\backupbuddy_api::setLiveStatus( $pause_continuous, $pause_periodic );
			$destination = \pb_backupbuddy::$options['remote_destinations'][ $destination_id ]; // Update local var.
			include \pb_backupbuddy::plugin_path() . '/destinations/live/_stats.php'; // Recalculate stats.
			$message = esc_html__( 'Live Database Backup resumed.', 'mainwp-child' );
		} else {
			$error = 'Error #1000. Invalid request.';
		}

		return array(
			'ok'       => 1,
			'_error'   => $error,
			'_message' => $message,
		);
	}


    /**
     * Download troubleshooting.
     *
     * @return array Return results array.
     *
     * @uses \pb_backupbuddy::plugin_path()
     * @uses \backupbuddy_live_troubleshooting::run()
     * @uses \backupbuddy_live_troubleshooting::get_raw_results()
     * @uses \backupbuddy_core::backup_prefix()
     */
    public function download_troubleshooting() {
		require \pb_backupbuddy::plugin_path() . '/destinations/live/_troubleshooting.php';
		\backupbuddy_live_troubleshooting::run();
		$output        = "**File best viewed with wordwrap OFF**\n\n" . print_r( backupbuddy_live_troubleshooting::get_raw_results(), true ); // phpcs:ignore -- debug feature.
		$backup_prefix = \backupbuddy_core::backup_prefix();
		return array(
			'output'        => $output,
			'backup_prefix' => $backup_prefix,
		);
	}

    /**
     * Get live backups.
     *
     * @return array[]|string[] Return backup list.
     *
     * @uses \pb_backupbuddy::plugin_path()
     * @uses \pb_backupbuddy::$options
     * @uses \pb_backupbuddy_destination_stash2::_formatSettings()
     * @uses \pb_backupbuddy_destination_stash2::listFiles()
     * @uses \pb_backupbuddy::$format::date()
     * @uses \pb_backupbuddy::$format::localize_time()
     * @uses \pb_backupbuddy::$format::time_ago()
     * @uses \pb_backupbuddy::$format::file_size()
     * @uses \backupbuddy_core::startsWith()
     * @uses \backupbuddy_core::getBackupTypeFromFile()
     * @uses \backupbuddy_core::pretty_backup_type()
     * @uses \backupbuddy_core::pretty_backup_type()
     */
    public function get_live_backups() {
		$destination_id = isset( $_POST['destination_id'] ) ? sanitize_text_field( wp_unslash( $_POST['destination_id'] ) ) : '';
		// Load required files.
		require_once \pb_backupbuddy::plugin_path() . '/destinations/s32/init.php';

		if ( ! isset( \pb_backupbuddy::$options['remote_destinations'][ $destination_id ] ) ) {
			return array( 'error' => 'Error #9828332: Destination not found with id `' . htmlentities( $destination_id ) . '`.' );
		}
		require_once \pb_backupbuddy::plugin_path() . '/destinations/stash2/init.php';
		$settings = &\pb_backupbuddy::$options['remote_destinations'][ $destination_id ];
		$settings = \pb_backupbuddy_destination_stash2::_formatSettings( $settings );

		$destination = \pb_backupbuddy::$options['remote_destinations'][ $destination_id ];

		if ( 'live' == $destination['type'] ) {
			$remotePath = 'snapshot-';
			$site_only  = true;
		} else {
			// Get list of files for this site.
			$remotePath = 'backup-';
			$site_only  = true;
		}

		$files = \pb_backupbuddy_destination_stash2::listFiles( $settings, '', $site_only ); // 2nd param was $remotePath.
		if ( ! is_array( $files ) ) {
			return array( 'error' => 'Error #892329c: ' . $files );
		}

		$backup_list_temp = array();
		foreach ( (array) $files as $file ) {

			if ( ( '' != $remotePath ) && ( ! \backupbuddy_core::startsWith( basename( $file['filename'] ), $remotePath ) ) ) { // Only show backups for this site unless set to show all.
				continue;
			}

			$last_modified = $file['uploaded_timestamp'];
			$size          = (float) $file['size'];
			$backup_type   = \backupbuddy_core::getBackupTypeFromFile( $file['filename'], $quiet = false, $skip_fileoptions = true );

			// Generate array of table rows.
			while ( isset( $backup_list_temp[ $last_modified ] ) ) { // Avoid collisions.
				$last_modified += 0.1;
			}

			if ( 'live' == $destination['type'] ) {
				$backup_list_temp[ $last_modified ] = array(
					array( base64_encode( $file['url'] ), '<span class="backupbuddy-stash-file-list-title">' . \pb_backupbuddy::$format->date( \pb_backupbuddy::$format->localize_time( $last_modified ) ) . ' <span class="description">(' . \pb_backupbuddy::$format->time_ago( $last_modified ) . ' ago)</span></span><br><span title="' . $file['filename'] . '">' . basename( $file['filename'] ) . '</span>' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
					\pb_backupbuddy::$format->date( \pb_backupbuddy::$format->localize_time( $last_modified ) ) . '<br /><span class="description">(' . \pb_backupbuddy::$format->time_ago( $last_modified ) . ' ago)</span>',
					\pb_backupbuddy::$format->file_size( $size ),
					\backupbuddy_core::pretty_backup_type( $backup_type ),
				);
			} else {
				$backup_list_temp[ $last_modified ] = array(
					array( base64_encode( $file['url'] ), '<span title="' . $file['filename'] . '">' . basename( $file['filename'] ) . '</span>' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- to compatible http encoding.
					\pb_backupbuddy::$format->date( \pb_backupbuddy::$format->localize_time( $last_modified ) ) . '<br /><span class="description">(' . \pb_backupbuddy::$format->time_ago( $last_modified ) . ' ago)</span>',
					\pb_backupbuddy::$format->file_size( $size ),
					\backupbuddy_core::pretty_backup_type( $backup_type ),
				);
			}
		}

		krsort( $backup_list_temp );
		$backup_list = array();
		foreach ( $backup_list_temp as $backup_item ) {
			$backup_list[ $backup_item[0][0] ] = $backup_item;
		}
		unset( $backup_list_temp );

		return array( 'backup_list' => $backup_list );
	}

    /**
     * Copy file to local.
     *
     * @return int[]|string[] Return 1 on success and ERROR message on failure.
     *
     * @uses \pb_backupbuddy::plugin_path()
     * @uses \pb_backupbuddy::$options
     * @uses \pb_backupbuddy::status()
     * @uses \pb_backupbuddy_destination_stash2::_formatSettings()
     * @uses \backupbuddy_core::schedule_single_event()
     *
     */
    public function copy_file_to_local() {

		$file           = isset( $_POST['cpy_file'] ) ? base64_decode( wp_unslash( $_POST['cpy_file'] ) ) : ''; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
		$destination_id = isset( $_POST['destination_id'] ) ? sanitize_text_field( wp_unslash( $_POST['destination_id'] ) ) : '';

		// Load required files.
		require_once \pb_backupbuddy::plugin_path() . '/destinations/s32/init.php';
		if ( ! isset( \pb_backupbuddy::$options['remote_destinations'][ $destination_id ] ) ) {
			return array( 'error' => 'Error #9828332: Destination not found with id `' . htmlentities( $destination_id ) . '`.' );
		}

		$settings = &\pb_backupbuddy::$options['remote_destinations'][ $destination_id ];
		$settings = \pb_backupbuddy_destination_stash2::_formatSettings( $settings );

		\pb_backupbuddy::status( 'details', 'Scheduling Cron for creating Stash copy.' );
		\backupbuddy_core::schedule_single_event( time(), 'process_remote_copy', array( 'stash2', $file, $settings ) );
		if ( '1' != \pb_backupbuddy::$options['skip_spawn_cron_call'] ) {
			update_option( '_transient_doing_cron', 0 ); // Prevent cron-blocking for next item.
			spawn_cron( time() + 150 ); // Adds > 60 seconds to get around once per minute cron running limit.
		}
		return array( 'ok' => 1 );
	}

    /**
     * Delete backup file.
     *
     * @return int[]|string[] Return 1 on success and ERROR message on failure.
     *
     * @uses \pb_backupbuddy::plugin_path()
     * @uses \pb_backupbuddy::$options
     * @uses \pb_backupbuddy_destination_stash2::_formatSettings()
     * @uses \pb_backupbuddy_destination_stash2::strrpos_count()
     * @uses \pb_backupbuddy_destination_stash2::deleteFiles()
     */
    public function delete_file_backup() {
		// Handle deletion.
		$files          = isset( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : array();
		$destination_id = isset( $_POST['destination_id'] ) ? sanitize_text_field( wp_unslash( $_POST['destination_id'] ) ) : '';

		// Load required files.
		require_once \pb_backupbuddy::plugin_path() . '/destinations/s32/init.php';
		if ( ! isset( \pb_backupbuddy::$options['remote_destinations'][ $destination_id ] ) ) {
			return array( 'error' => 'Error #9828332: Destination not found with id `' . htmlentities( $destination_id ) . '`.' );
		}

		$settings = &\pb_backupbuddy::$options['remote_destinations'][ $destination_id ];
		$settings = \pb_backupbuddy_destination_stash2::_formatSettings( $settings );

		$deleteFiles = array();
		foreach ( (array) $files as $file ) {
			$file = base64_decode( $file ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..

			$startPos = \pb_backupbuddy_destination_stash2::strrpos_count( $file, '/', 2 ) + 1; // next to last slash.
			$file     = substr( $file, $startPos );
			if ( false !== strstr( $file, '?' ) ) {
				$file = substr( $file, 0, strpos( $file, '?' ) );
			}
			$deleteFiles[] = $file;
		}
		$response = \pb_backupbuddy_destination_stash2::deleteFiles( $settings, $deleteFiles );

		if ( true === $response ) {
			$msg = 'Deleted ' . implode( ', ', $deleteFiles ) . '.';
		} else {
			$msg = 'Failed to delete one or more files. Details: `' . $response . '`.';
		}

		return array(
			'ok'  => 1,
			'msg' => $msg,
		);
	}

    /**
     * Get live stats.
     *
     * @return array|string[] Return json encoded results or ERROR message on failure.
     *
     * @uses \backupbuddy_api::getLiveStats()
     * @uses \backupbuddy_core::detectLikelyHighestExecutionTime()
     * @uses \backupbuddy_core::schedule_single_event()
     * @uses \backupbuddy_live::getLiveID();
     * @uses \pb_backupbuddy::plugin_path()
     * @uses \pb_backupbuddy::$options
     * @uses \pb_backupbuddy::status()
     */
    public function get_live_stats() {

		// Check if running PHP 5.3+.
		$php_minimum = 5.3;
		if ( version_compare( PHP_VERSION, $php_minimum, '<' ) ) { // Server's PHP is insufficient.
			return array( 'error' => '-1' );
		}

		$stats = \backupbuddy_api::getLiveStats();

		if ( false === $stats ) { // Live is disconnected.
			return array( 'error' => '-1' );
		}

		// If there is more to do and too long of time has passed since activity then try to jumpstart the process at the beginning.
		if ( ( ( 0 == $stats['files_total'] ) || ( $stats['files_sent'] < $stats['files_total'] ) ) && ( 'wait_on_transfers' != $stats['current_function'] ) ) { // ( Files to send not yet calculated OR more remain to send ) AND not on the wait_on_transfers step.
			$time_since_last_activity = microtime( true ) - $stats['last_periodic_activity'];

			if ( $time_since_last_activity >= 30 ) { // More than 30 seconds since last activity.

				// Detect max PHP execution time. If TESTED value is higher than PHP value then go with that since we want to err on not overlapping processes here.
				$detected_execution = \backupbuddy_core::detectLikelyHighestExecutionTime();

				if ( $time_since_last_activity > ( $detected_execution + \backupbuddy_constants::TIMED_OUT_PROCESS_RESUME_WIGGLE_ROOM ) ) { // Enough time has passed to assume timed out.

					require_once \pb_backupbuddy::plugin_path() . '/destinations/live/live.php';
					$liveID = \backupbuddy_live::getLiveID();
					if ( false === $liveID ) {
						die( '-1' );
					}
					if ( '1' != \pb_backupbuddy::$options['remote_destinations'][ $liveID ]['pause_periodic'] ) { // Only proceed if NOT paused.

						\pb_backupbuddy::status( 'warning', 'BackupBuddy Stash Live process appears timed out while user it viewing Live page. Forcing run now.' );

						$cronArgs        = array();
						$schedule_result = \backupbuddy_core::schedule_single_event( time(), 'live_periodic', $cronArgs );
						if ( true === $schedule_result ) {
							\pb_backupbuddy::status( 'details', 'Next Live Periodic chunk step cron event scheduled.' );
						} else {
							\pb_backupbuddy::status( 'error', 'Next Live Periodic chunk step cron event FAILED to be scheduled.' );
						}
						if ( '1' != \pb_backupbuddy::$options['skip_spawn_cron_call'] ) {
							\pb_backupbuddy::status( 'details', 'Spawning cron now.' );
							update_option( '_transient_doing_cron', 0 ); // Prevent cron-blocking for next item.
							spawn_cron( time() + 150 ); // Adds > 60 seconds to get around once per minute cron running limit.
						}
					}
				}
			}
		}

		return array( 'result' => wp_json_encode( $stats ) );
	}

    /**
     * Save license settings.
     *
     * @return bool|int[] Return 1 on success and FALSE on failure.
     */
    public function save_license_settings() {
		$settings = isset( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : false;
		if ( is_array( $settings ) && isset( $GLOBALS['ithemes-updater-settings'] ) ) {
			$GLOBALS['ithemes-updater-settings']->update_options( $settings );
			return array( 'ok' => 1 );
		}
		return false;
	}

    /**
     * Load product license.
     *
     * @return array Return results array.
     *
     * @uses Ithemes_Updater_Packages::get_full_details()
     * @uses Ithemes_Updater_Functions::get_package_name()
     */
    public function load_products_license() {
		$packages      = array();
		$packages_name = array();
		if ( isset( $GLOBALS['ithemes_updater_path'] ) ) {

			require_once $GLOBALS['ithemes_updater_path'] . '/functions.php';
			require_once $GLOBALS['ithemes_updater_path'] . '/api.php';
			require_once $GLOBALS['ithemes_updater_path'] . '/keys.php';

			require_once $GLOBALS['ithemes_updater_path'] . '/packages.php';

			$details  = \Ithemes_Updater_Packages::get_full_details();
			$packages = isset( $details['packages'] ) ? $details['packages'] : array();
			if ( is_array( $packages ) ) {
				foreach ( $packages as $path => $data ) {
					$packages_name[ $path ] = \Ithemes_Updater_Functions::get_package_name( $data['package'] );
				}
			}
		}
		return array(
			'ok'            => 1,
			'packages'      => $packages,
			'packages_name' => $packages_name,
		);
	}

    /**
     * Activate package.
     *
     * @return array $return Return response array.
     *
     * @uses Ithemes_Updater_API::activate_package()
     * @uses Ithemes_Updater_Functions::get_package_name()
     * @uses MainWP_Child_Back_Up_Buddy::get_error_explanation()
     */
    public function activate_package() {

		$username = isset( $_POST['username'] ) ? sanitize_text_field( wp_unslash( $_POST['username'] ) ) : '';
		$password = isset( $_POST['password'] ) ? wp_unslash( $_POST['password'] ) : '';
		$packages = isset( $_POST['packages'] ) ? wp_unslash( $_POST['packages'] ) : '';

		$return = array( 'ok' => 1 );
		if ( isset( $GLOBALS['ithemes_updater_path'] ) ) {

			require_once $GLOBALS['ithemes_updater_path'] . '/functions.php';
			require_once $GLOBALS['ithemes_updater_path'] . '/api.php';
			require_once $GLOBALS['ithemes_updater_path'] . '/keys.php';

			require_once $GLOBALS['ithemes_updater_path'] . '/packages.php';

			$response = \Ithemes_Updater_API::activate_package( $username, $password, $packages );

			if ( is_wp_error( $response ) ) {
				$errors[]         = $this->get_error_explanation( $response );
				$return['errors'] = $errors;
				return $return;
			}

			if ( empty( $response['packages'] ) ) {
				$errors[]         = esc_html__( 'An unknown server error occurred. Please try to license your products again at another time.', 'mainwp-child' );
				$return['errors'] = $errors;
				return $return;
			}

			uksort( $response['packages'], 'strnatcasecmp' );

			$success = array();
			$warn    = array();
			$fail    = array();

			foreach ( $response['packages'] as $package => $data ) {
				if ( preg_match( '/ \|\|\| \d+$/', $package ) ) {
					continue;
				}

				$name = \Ithemes_Updater_Functions::get_package_name( $package );

				if ( ! empty( $data['key'] ) ) {
					$success[] = $name;
				} elseif ( ! empty( $data['status'] ) && ( 'expired' == $data['status'] ) ) {
					$warn[ $name ] = esc_html__( 'Your product subscription has expired', 'mainwp-child' );
				} else {
					$fail[ $name ] = $data['error']['message'];
				}
			}

			if ( ! empty( $success ) ) {
				$messages[]         = wp_sprintf( esc_html__( 'Successfully licensed %l.', 'mainwp-child' ), $success );
				$return['messages'] = $messages;
			}

			if ( ! empty( $fail ) ) {
				foreach ( $fail as $name => $reason ) {
					$errors[] = sprintf( esc_html__( 'Unable to license %1$s. Reason: %2$s', 'mainwp-child' ), $name, $reason );
				}
				$return['errors'] = $errors;
			}

			if ( ! empty( $warn ) ) {
				foreach ( $warn as $name => $reason ) {
					$soft_errors[] = sprintf( esc_html__( 'Unable to license %1$s. Reason: %2$s', 'mainwp-child' ), $name, $reason );
				}
				$return['soft_errors'] = $soft_errors;
			}
		}
		return $return;
	}

    /**
     * Deactivate package.
     *
     * @param array $data Data array.
     *
     * @return array return$ Return response array.
     *
     * @uses Ithemes_Updater_API::deactivate_package()
     * @uses Ithemes_Updater_Functions::get_package_name()
     * @uses MainWP_Child_Back_Up_Buddy::get_error_explanation()
     */
    public function deactivate_package( $data ) {

		$username = isset( $_POST['username'] ) ? sanitize_text_field( wp_unslash( $_POST['username'] ) ) : '';
		$password = isset( $_POST['password'] ) ? wp_unslash( $_POST['password'] ) : '';
		$packages = isset( $_POST['packages'] ) ? wp_unslash( $_POST['packages'] ) : '';

		$return = array( 'ok' => 1 );

		if ( isset( $GLOBALS['ithemes_updater_path'] ) ) {

			require_once $GLOBALS['ithemes_updater_path'] . '/functions.php';
			require_once $GLOBALS['ithemes_updater_path'] . '/api.php';
			require_once $GLOBALS['ithemes_updater_path'] . '/keys.php';

			require_once $GLOBALS['ithemes_updater_path'] . '/packages.php';

			$response = \Ithemes_Updater_API::deactivate_package( $username, $password, $packages );

			if ( is_wp_error( $response ) ) {
				$errors[]         = $this->get_error_explanation( $response );
				$return['errors'] = $errors;
				return $return;
			}

			if ( empty( $response['packages'] ) ) {
				$errors[]         = esc_html__( 'An unknown server error occurred. Please try to remove licenses from your products again at another time.', 'it-l10n-mainwp-backupbuddy' );
				$return['errors'] = $errors;
				return $return;
			}

			uksort( $response['packages'], 'strnatcasecmp' );

			$success = array();
			$fail    = array();

			foreach ( $response['packages'] as $package => $data ) {
				if ( preg_match( '/ \|\|\| \d+$/', $package ) ) {
					continue;
				}

				$name = \Ithemes_Updater_Functions::get_package_name( $package );

				if ( isset( $data['status'] ) && ( 'inactive' == $data['status'] ) ) {
					$success[] = $name;
				} elseif ( isset( $data['error'] ) && isset( $data['error']['message'] ) ) {
					$fail[ $name ] = $data['error']['message'];
				} else {
					$fail[ $name ] = esc_html__( 'Unknown server error.', 'it-l10n-mainwp-backupbuddy' );
				}
			}

			if ( ! empty( $success ) ) {
				$messages[]         = wp_sprintf( _n( 'Successfully removed license from %l.', 'Successfully removed licenses from %l.', count( $success ), 'it-l10n-mainwp-backupbuddy' ), $success );
				$return['messages'] = $messages;
			}

			if ( ! empty( $fail ) ) {
				foreach ( $fail as $name => $reason ) {
					$errors[] = sprintf( esc_html__( 'Unable to remove license from %1$s. Reason: %2$s', 'it-l10n-mainwp-backupbuddy' ), $name, $reason );
				}
				$return['errors'] = $errors;

			}
		}
		return $return;
	}

    /**
     * Get error code.
     *
     * @param $error Returned error to get code for: Bad_Login, Username_Unknown, Username_Invalid, Package_Unknown,
     *  Too_Many_Sites, Generate_Failed.
     * @param string $package Ithemes package.
     *
     * @return string $message Return ERROR message to display.
     *
     * @uses Ithemes_Updater_Functions::get_package_name()
     */
    private function get_error_explanation( $error, $package = '' ) {
		$code         = $error->get_error_code();
		$package_name = \Ithemes_Updater_Functions::get_package_name( $package );
		$message      = '';

		switch ( $code ) {
			case 'ITXAPI_Updater_Bad_Login':
				$message = esc_html__( 'Incorrect password. Please make sure that you are supplying your iThemes membership username and password details.', 'it-l10n-mainwp-backupbuddy' );
				break;
			case 'ITXAPI_Updater_Username_Unknown':
			case 'ITXAPI_Updater_Username_Invalid':
				$message = esc_html__( 'Invalid username. Please make sure that you are supplying your iThemes membership username and password details.', 'it-l10n-mainwp-backupbuddy' );
				break;
			case 'ITXAPI_Product_Package_Unknown':
				$message = sprintf( esc_html__( 'The licensing server reports that the %1$s (%2$s) product is unknown. Please contact support for assistance.', 'it-l10n-mainwp-backupbuddy' ), $package_name, $package );
				break;
			case 'ITXAPI_Updater_Too_Many_Sites':
				$message = sprintf( esc_html__( '%1$s could not be licensed since the membership account is out of available licenses for this product. You can unlicense the product on other sites or upgrade your membership to one with a higher number of licenses in order to increase the amount of available licenses.', 'it-l10n-mainwp-backupbuddy' ), $package_name );
				break;
			case 'ITXAPI_License_Key_Generate_Failed':
				$message = sprintf( esc_html__( '%1$s could not be licensed due to an internal error. Please try to license %2$s again at a later time. If this problem continues, please contact iThemes support.', 'it-l10n-mainwp-backupbuddy' ), $package_name );
				break;
		}

		if ( empty( $message ) ) {
			if ( ! empty( $package ) ) {
				$message = sprintf( esc_html__( 'An unknown error relating to the %1$s product occurred. Please contact iThemes support. Error details: %2$s', 'it-l10n-mainwp-backupbuddy' ), $package_name, $error->get_error_message() . " ($code)" );
			} else {
				$message = sprintf( esc_html__( 'An unknown error occurred. Please contact iThemes support. Error details: %s', 'it-l10n-mainwp-backupbuddy' ), $error->get_error_message() . " ($code)" );
			}
		}

		return $message;
	}



}
