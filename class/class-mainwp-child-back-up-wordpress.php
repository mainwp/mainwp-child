<?php


/*
 *
 * Credits
 *
 * Plugin-Name: BackUpWordPress
 * Plugin URI: http://bwp.hmn.md/
 * Author: Human Made Limited
 * Author URI: http://hmn.md/
 * License: GPL-2+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 *
 * The code is used for the MainWP BackUpWordPress Extension
 * Extension URL: https://mainwp.com/extension/backupwordpress/
 *
*/

class MainWP_Child_Back_Up_Wordpress {
	public static $instance = null;
    public $is_plugin_installed = false;
	static function Instance() {
		if ( null === MainWP_Child_Back_Up_Wordpress::$instance ) {
			MainWP_Child_Back_Up_Wordpress::$instance = new MainWP_Child_Back_Up_Wordpress();
		}

		return MainWP_Child_Back_Up_Wordpress::$instance;
	}

    public function __construct() {
		require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
		if ( is_plugin_active( 'backupwordpress/backupwordpress.php' ) ) {
            $this->is_plugin_installed = true;
            if ( version_compare( phpversion(), '5.3', '>=' ) ) {
                add_filter( 'mainwp-site-sync-others-data', array( $this, 'syncOthersData' ), 10, 2 );
            }
		}

    }

	public function init() {
        if ( version_compare( phpversion(), '5.3', '<' ) ) {
            return;
        }

		if (!$this->is_plugin_installed) return;

        add_action( 'mainwp_child_site_stats', array( $this, 'do_site_stats' ) );

		if ( get_option( 'mainwp_backupwordpress_hide_plugin' ) === 'hide' ) {
			add_filter( 'all_plugins', array( $this, 'all_plugins' ) );
			add_action( 'admin_menu', array( $this, 'remove_menu' ) );
			add_filter( 'site_transient_update_plugins', array( &$this, 'remove_update_nag' ) );
            add_filter( 'mainwp_child_hide_update_notice', array( &$this, 'hide_update_notice' ) );
		}
	}

    function hide_update_notice( $slugs ) {
        $slugs[] = 'backupwordpress/backupwordpress.php';
        return $slugs;
    }

	function remove_update_nag( $value ) {
		if ( isset( $_POST['mainwpsignature'] ) ) {
			return $value;
		}

        if (! MainWP_Helper::is_screen_with_update()) {
            return $value;
        }
		if ( isset( $value->response['backupwordpress/backupwordpress.php'] ) ) {
			unset( $value->response['backupwordpress/backupwordpress.php'] );
		}

		return $value;
	}

	public function action() {
		$information = array();
		if ( ! self::isActivated() ) {
			$information['error'] = 'NO_BACKUPWORDPRESS';
			MainWP_Helper::write( $information );
		}

		if ( isset( $_POST['mwp_action'] ) ) {
			switch ( $_POST['mwp_action'] ) {
				case 'set_showhide':
					$information = $this->set_showhide();
					break;
				case 'delete_schedule':
					$information = $this->delete_schedule();
					break;
				case 'cancel_schedule':
					$information = $this->hmbkp_request_cancel_backup();
					break;
				case 'get_backup_status':
					$information = $this->get_backup_status();
					break;
				case 'reload_backupslist':
					$information = $this->reload_backups();
					break;
				case 'delete_backup':
					$information = $this->hmbkp_request_delete_backup();
					break;
				case 'run_schedule':
					$information = $this->run_schedule();
					break;
				case 'save_all_schedules':
					$information = $this->save_all_schedules();
					break;
				case 'update_schedule':
					$information = $this->update_schedule();
					break;
				case 'get_excluded':
					$information = $this->get_excluded();
					break;
				case 'directory_browse':
					$information = $this->directory_browse();
					break;
				case 'exclude_add_rule':
					$information = $this->hmbkp_add_exclude_rule();
					break;
				case 'exclude_remove_rule':
					$information = $this->hmbkp_remove_exclude_rule();
					break;
                case 'general_exclude_add_rule':
					$information = $this->general_exclude_add_rule();
					break;
			}
		}
		MainWP_Helper::write( $information );
	}


	function check_schedule() {
		$schedule_id = ( isset( $_POST['schedule_id'] ) && ! empty( $_POST['schedule_id'] ) ) ? $_POST['schedule_id'] : '';
		if ( empty( $schedule_id ) ) {
			$information = array( 'error' => 'Empty schedule id' );
			MainWP_Helper::write( $information );
		} else {
			$schedule_id = sanitize_text_field( urldecode( $schedule_id ) );
			HM\BackUpWordPress\Schedules::get_instance()->refresh_schedules();
			if ( ! HM\BackUpWordPress\Schedules::get_instance()->get_schedule( $schedule_id ) ) {
				$information = array( 'result' => 'NOTFOUND' );
				MainWP_Helper::write( $information );
			}
		}

		return $schedule_id;
	}
    // ok
	public function syncOthersData( $information, $data = array() ) {
        if ( isset( $data['syncBackUpWordPress'] ) && $data['syncBackUpWordPress'] ) {
            try {
                $information['syncBackUpWordPress'] = $this->get_sync_data();
            } catch(Exception $e) {

            }
        }
		return $information;
	}

    // ok
	private function get_sync_data() {
        MainWP_Helper::check_classes_exists('HM\BackUpWordPress\Schedules');
        MainWP_Helper::check_methods('HM\BackUpWordPress\Schedules', array( 'get_instance', 'refresh_schedules', 'get_schedules' ) );

		HM\BackUpWordPress\Schedules::get_instance()->refresh_schedules();
		$schedules    = HM\BackUpWordPress\Schedules::get_instance()->get_schedules();
		$backups_time = array();

        if (is_array($schedules) && count($schedules)) {
            $check = current($schedules);
            MainWP_Helper::check_methods($check, array( 'get_backups' ) );

            foreach ( $schedules as $sche ) {
                $existing_backup = $sche->get_backups();
                if ( ! empty( $existing_backup ) ) {
                    $backups_time = array_merge( $backups_time, array_keys( $existing_backup ) );
                }
            }
        }

		$lasttime_backup = 0;
		if ( ! empty( $backups_time ) ) {
			$lasttime_backup = max( $backups_time );
		}

		$return = array( 'lasttime_backup' => $lasttime_backup );

		return $return;
	}

    function do_site_stats() {
        if (has_action('mainwp_child_reports_log')) {
            do_action( 'mainwp_child_reports_log', 'backupwordpress');
        } else {
            $this->do_reports_log('backupwordpress');
        }
    }

    // ok
    public function do_reports_log($ext = '') {
        if ( $ext !== 'backupwordpress' ) return;
        if (!$this->is_plugin_installed) return;

        try {
            MainWP_Helper::check_classes_exists('HM\BackUpWordPress\Schedules');
            MainWP_Helper::check_methods('HM\BackUpWordPress\Schedules', array( 'get_instance', 'refresh_schedules', 'get_schedules' ));

            // Refresh the schedules from the database to make sure we have the latest changes
            HM\BackUpWordPress\Schedules::get_instance()->refresh_schedules();
            $schedules = HM\BackUpWordPress\Schedules::get_instance()->get_schedules();
            if (is_array($schedules) && count($schedules) > 0) {
                $check = current($schedules);
                MainWP_Helper::check_methods($check, array( 'get_backups', 'get_type' ));

                foreach($schedules as $schedule) {
                    foreach ( $schedule->get_backups() as $file ) {
                        $backup_type =  $schedule->get_type();
                        $message = "BackupWordpres backup " .  $backup_type . ' finished';
                        $destination = "N/A";
                        if ( file_exists( $file ) ) {
                            $date = @filemtime( $file );
                            if ( !empty( $date ) ) {
                                do_action( "backupwordpress_backup", $destination, $message, 'finished', $backup_type, $date );
                                MainWP_Helper::update_lasttime_backup('backupwordpress', $date); // to support backup before update feature
                            }
                        }
                    }
                }
            }
        } catch(Exception $e) {

        }
    }

	function set_showhide() {
		$hide = isset( $_POST['showhide'] ) && ( 'hide' === $_POST['showhide'] ) ? 'hide' : '';
		MainWP_Helper::update_option( 'mainwp_backupwordpress_hide_plugin', $hide );
		$information['result'] = 'SUCCESS';

		return $information;
	}

	function delete_schedule() {
		$schedule_id = $this->check_schedule();
		$schedule    = new HM\BackUpWordPress\Scheduled_Backup( sanitize_text_field( urldecode( $schedule_id ) ) );

		if ( $schedule ) {
			$schedule->cancel( true );
			$information['result'] = 'SUCCESS';
		} else {
			$information['result'] = 'NOTFOUND';
		}

		return $information;
	}

	function hmbkp_request_cancel_backup() {
		$schedule_id = $this->check_schedule();
		$schedule    = new HM\BackUpWordPress\Scheduled_Backup( sanitize_text_field( urldecode( $schedule_id ) ) );


		// Delete the running backup
		if (method_exists($schedule, 'get_running_backup_filename' )) {
			if ( $schedule->get_running_backup_filename() && file_exists( trailingslashit( hmbkp_path() ) . $schedule->get_running_backup_filename() ) ) {
				unlink( trailingslashit( hmbkp_path() ) . $schedule->get_running_backup_filename() );
			}
			if ( $schedule->get_schedule_running_path() && file_exists( $schedule->get_schedule_running_path() ) ) {
				unlink( $schedule->get_schedule_running_path() );
			}
		} else {
			$status = $schedule->get_status();
			// Delete the running backup
			if ( $status->get_backup_filename() && file_exists( trailingslashit( HM\BackUpWordPress\Path::get_path() ) . $status->get_backup_filename() ) ) {
				unlink( trailingslashit( HM\BackUpWordPress\Path::get_path() ) . $status->get_backup_filename() );
			}
			if ( file_exists( $status->get_status_filepath() ) ) {
				unlink( $status->get_status_filepath() );
			}

		}

		HM\BackUpWordPress\Path::get_instance()->cleanup();

		if ($status === null) {
			$information['scheduleStatus'] = $schedule->get_status();
		} else {
			$information['scheduleStatus'] = $status->get_status();
		}

		$information['result']         = 'SUCCESS';

		return $information;
	}

	function get_backup_status() {
		$schedule_id = $this->check_schedule();
		$schedule    = new HM\BackUpWordPress\Scheduled_Backup( sanitize_text_field( urldecode( $schedule_id ) ) );

		if (method_exists($schedule, 'get_running_backup_filename' )) {
			$information['scheduleStatus'] = $schedule->get_status();
		} else {
			$status = $schedule->get_status();
			$information['scheduleStatus'] = $status->get_status();
		}

		$information['result']         = 'SUCCESS';

		return $information;
	}

	function run_schedule() {
		$schedule_id = $this->check_schedule();
		if (function_exists('hmbkp_run_schedule_async')) {
			hmbkp_run_schedule_async($schedule_id);
		} else if (function_exists('\HM\BackUpWordPress\run_schedule_async')) {
			HM\BackUpWordPress\Path::get_instance()->cleanup();
			// Fixes an issue on servers which only allow a single session per client
			session_write_close();
			$task = new \HM\Backdrop\Task( '\HM\BackUpWordPress\run_schedule_async', $schedule_id );
			$task->schedule();
		} else
			return array( 'error' => __('Error while trying to trigger the schedule', 'mainwp-child') );
		return array( 'result' => 'SUCCESS' );
	}

	function reload_backups() {

		$scheduleIds = isset( $_POST['schedule_ids'] ) ? $_POST['schedule_ids'] : array();
		HM\BackUpWordPress\Schedules::get_instance()->refresh_schedules();

		$all_schedules_ids = array();
		$schedules         = HM\BackUpWordPress\Schedules::get_instance()->get_schedules();
		foreach ( $schedules as $sch ) {
			$all_schedules_ids[] = $sch->get_id();
		}

		if ( empty( $all_schedules_ids ) ) {
			return array( 'error' => 'Schedules could not be found.' );
		}

		foreach ( $all_schedules_ids as $schedule_id ) {
			if ( ! HM\BackUpWordPress\Schedules::get_instance()->get_schedule( $schedule_id ) ) {
				continue;
			}

			$schedule = new HM\BackUpWordPress\Scheduled_Backup( sanitize_text_field( urldecode( $schedule_id ) ) );
			$started_ago = method_exists($schedule, 'get_schedule_running_start_time') ?  $schedule->get_schedule_running_start_time() : $schedule->get_schedule_start_time();
			$out = array(
				'b'              => $this->get_backupslist_html( $schedule ),
				'count'          => count( $schedule->get_backups() ),
				'file_size_text' => $this->hmbkp_get_site_size_text( $schedule ),
				'started_ago'    => human_time_diff( $started_ago ),
			);

			if (method_exists($schedule, 'get_running_backup_filename' )) {
				$out['scheduleStatus'] = $schedule->get_status();
			} else {
				$status = $schedule->get_status();
				$out['scheduleStatus'] = $status->get_status();
			}

			$information['backups'][ $schedule_id ] = $out;
		}

		$send_back_schedules = array();

		$schedules = HM\BackUpWordPress\Schedules::get_instance()->get_schedules();
		foreach ( $schedules as $schedule ) {
			$sch_id = $schedule->get_id();
			if ( ! in_array( $sch_id, $scheduleIds ) ) {
				$current_option = get_option( 'hmbkp_schedule_' . $sch_id );
				if ( is_array( $current_option ) ) {
					unset( $current_option['excludes'] ); // not send this value
					$started_ago = method_exists($schedule, 'get_schedule_running_start_time') ?  $schedule->get_schedule_running_start_time() : $schedule->get_schedule_start_time();
					$send_back_schedules[ $sch_id ] = array(
						'options'        => $current_option,
						'b'              => $this->get_backupslist_html( $schedule ),
						'count'          => count( $schedule->get_backups() ),
						'file_size_text' => $this->hmbkp_get_site_size_text( $schedule ),
						'scheduleStatus' => $schedule->get_status(),
						'started_ago'    => human_time_diff( $started_ago ),
					);
					if (method_exists($schedule, 'get_running_backup_filename' )) {
						$send_back_schedules['scheduleStatus'] = $schedule->get_status();
					} else {
						$status = $schedule->get_status();
						$send_back_schedules['scheduleStatus'] = $status->get_status();
					}
				}
			}
		}

		if (function_exists('HM\BackUpWordPress\Backup::get_home_path'))
			$backups_path = str_replace( HM\BackUpWordPress\Backup::get_home_path(), '', hmbkp_path() );
		else
			$backups_path = str_replace( HM\BackUpWordPress\Path::get_home_path(), '', HM\BackUpWordPress\Path::get_path() );

		$information['backups_path']        = $backups_path;
		$information['send_back_schedules'] = $send_back_schedules;
		$information['result']              = 'SUCCESS';

		return $information;
	}

	function hmbkp_request_delete_backup() {
		if ( ! isset( $_POST['hmbkp_backuparchive'] ) || empty( $_POST['hmbkp_backuparchive'] ) ) {
			return array( 'error' => __( 'Invalid data. Please check and try again.', 'mainwp-child' ) );
		}

		$schedule_id = $this->check_schedule();

		$schedule = new HM\BackUpWordPress\Scheduled_Backup( sanitize_text_field( urldecode( $schedule_id ) ) );

		$deleted = $schedule->delete_backup(base64_decode( urldecode($_POST['hmbkp_backuparchive'] )));

		if ( is_wp_error( $deleted ) ) {
			return array( 'error' => $deleted->get_error_message() );
		}

		$ret =  array(
			'result'         => 'SUCCESS',
			'b'              => $this->get_backupslist_html( $schedule ),
			'count'          => count( $schedule->get_backups() ),
			'file_size_text' => $this->hmbkp_get_site_size_text( $schedule ),
		);
		if (method_exists($schedule, 'get_running_backup_filename' )) {
			$ret['scheduleStatus'] = $schedule->get_status();
		} else {
			$status = $schedule->get_status();
			$ret['scheduleStatus'] = $status->get_status();
		}
		return $ret;
	}

	function get_backupslist_html( $schedule ) {
		ob_start();
		?>
		<table class="widefat">

			<thead>

			<tr>

				<th scope="col"><?php function_exists('hmbkp_backups_number') ? hmbkp_backups_number( $schedule ) : ( function_exists('backups_number') ? backups_number( $schedule ) : "" ) ; ?></th>
				<th scope="col"><?php esc_html_e( 'Size', 'mainwp-backupwordpress-extension' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Type', 'mainwp-backupwordpress-extension' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Actions', 'mainwp-backupwordpress-extension' ); ?></th>

			</tr>

			</thead>

			<tbody>

			<?php if ( $schedule->get_backups() ) {

				$schedule->delete_old_backups();

				foreach ( $schedule->get_backups() as $file ) {

					if ( ! file_exists( $file ) ) {
						continue;
					}

					$this->hmbkp_get_backup_row( $file, $schedule );

				}
			} else { ?>

				<tr>
					<td class="hmbkp-no-backups"
					    colspan="4"><?php esc_html_e( 'This is where your backups will appear once you have some.', 'mainwp-backupwordpress-extension' ); ?></td>
				</tr>

			<?php } ?>

			</tbody>

		</table>
		<?php
		$html = ob_get_clean();

		return $html;
	}


	function hmbkp_get_site_size_text( HM\BackUpWordPress\Scheduled_Backup $schedule ) {
		if (method_exists($schedule, 'is_site_size_cached')) {
			if ( ( 'database' === $schedule->get_type() ) || $schedule->is_site_size_cached() ) {
				return sprintf( '(<code title="' . __( 'Backups will be compressed and should be smaller than this.', 'mainwp-backupwordpress-extension' ) . '">%s</code>)', esc_attr( $schedule->get_formatted_site_size() ) );
			}
		} else {
			$site_size = new HM\BackUpWordPress\Site_Size( $schedule->get_type(), $schedule->get_excludes() );
			if ( ( 'database' === $schedule->get_type() ) || $site_size->is_site_size_cached() ) {
				return sprintf( '(<code title="' . __( 'Backups will be compressed and should be smaller than this.', 'backupwordpress' ) . '">%s</code>)', esc_attr( $site_size->get_formatted_site_size() ) );
			}
		}

		return sprintf( '(<code class="calculating" title="' . __( 'this shouldn\'t take long&hellip;', 'mainwp-backupwordpress-extension' ) . '">' . __( 'calculating the size of your backup&hellip;', 'mainwp-backupwordpress-extension' ) . '</code>)' );

	}

	function hmbkp_get_backup_row( $file, HM\BackUpWordPress\Scheduled_Backup $schedule ) {

		$encoded_file = urlencode( base64_encode( $file ) );
		$offset       = get_option( 'gmt_offset' ) * 3600;

		?>

		<tr class="hmbkp_manage_backups_row">

			<th scope="row">
				<?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' - ' . get_option( 'time_format' ), @filemtime( $file ) + $offset ) ); ?>
			</th>

			<td class="code">
				<?php echo esc_html( size_format( @filesize( $file ) ) ); ?>
			</td>

			<td><?php echo function_exists('hmbkp_human_get_type') ? esc_html( hmbkp_human_get_type( $file, $schedule ) ) : esc_html( HM\BackUpWordPress\human_get_type( $file, $schedule)); ?></td>

			<td>

				<?php if (function_exists('hmbkp_is_path_accessible')) {
					if ( hmbkp_is_path_accessible( hmbkp_path() ) ) {
						?>
						<a href="#"
						   onclick="event.preventDefault(); mainwp_backupwp_download_backup('<?php echo $encoded_file; ?>', <?php echo esc_attr( $schedule->get_id() ); ?>, this);"
						   class="download-action"><?php esc_html_e( 'Download', 'backupwordpress' ); ?></a> |
					<?php };
				} else if (function_exists('HM\BackUpWordPress\is_path_accessible') ) {
					if (HM\BackUpWordPress\is_path_accessible(HM\BackUpWordPress\Path::get_path())) {
						?>
						<a href="#"
						   onclick="event.preventDefault(); mainwp_backupwp_download_backup('<?php echo $encoded_file; ?>', <?php echo esc_attr( $schedule->get_id() ); ?>, this);"
						   class="download-action"><?php esc_html_e( 'Download', 'backupwordpress' ); ?></a> |
					<?php };
				}
				?>

				<a href="#"
				   onclick="event.preventDefault(); mainwp_backupwp_delete_backup('<?php echo $encoded_file; ?>', <?php echo esc_attr( $schedule->get_id() ); ?>, this);"
				   class="delete-action"><?php esc_html_e( 'Delete', 'backupwordpress' ); ?></a>

			</td>

		</tr>

	<?php }

	function get_excluded( $browse_dir = null ) {

		$schedule_id = $this->check_schedule();
		$schedule    = new HM\BackUpWordPress\Scheduled_Backup( sanitize_text_field( urldecode( $schedule_id ) ) );

		$new_version = true;
		if (method_exists($schedule, 'get_running_backup_filename' )) {
			$new_version = false;
			$user_excludes = array_diff( $schedule->get_excludes(), $schedule->backup->default_excludes() );
			$root_dir = $schedule->backup->get_root();
			$is_size_calculated = $schedule->is_site_size_being_calculated();
		} else {
			$excludes = $schedule->get_excludes();
			$user_excludes = $excludes->get_user_excludes();
			$root_dir = HM\BackUpWordPress\Path::get_root();
			$is_size_calculated = HM\BackUpWordPress\Site_Size::is_site_size_being_calculated();
		}

		ob_start();

		?>
		<div class="hmbkp-exclude-settings">

			<?php //if ( $schedule->get_excludes() ) : ?>

			<h3>
				<?php esc_html_e( 'Currently Excluded', 'backupwordpress' ); ?>
			</h3>

			<p><?php esc_html_e( 'We automatically detect and ignore common <abbr title="Version Control Systems">VCS</abbr> folders and other backup plugin folders.', 'backupwordpress' ); ?></p>

			<table class="widefat">

				<tbody>
				<?php foreach ( $user_excludes as $key => $exclude ) : ?>

					<?php $exclude_path = new SplFileInfo( trailingslashit( $root_dir ) . ltrim( str_ireplace( $root_dir, '', $exclude ), '/' ) ); ?>

					<tr>

						<th scope="row">

							<?php if ( $exclude_path->isFile() ) { ?>

								<div class="dashicons dashicons-media-default"></div>

							<?php } elseif ( $exclude_path->isDir() ) { ?>

								<div class="dashicons dashicons-portfolio"></div>

							<?php } ?>

						</th>

						<td>
							<code>
								<?php echo esc_html( str_ireplace( $root_dir, '', $exclude ) ); ?>
							</code>

						</td>

						<td>
							<?php
							if ($new_version)
								$is_default_rule = ( in_array( $exclude, $excludes->get_default_excludes() ) ) || ( HM\BackUpWordPress\Path::get_path() === trailingslashit( HM\BackUpWordPress\Path::get_root() ) . untrailingslashit( $exclude ) );
							else
								$is_default_rule =  ( in_array( $exclude, $schedule->backup->default_excludes() ) ) || ( hmbkp_path() === untrailingslashit( $exclude ) ) ;

							if ( $is_default_rule ) : ?>
								<?php esc_html_e( 'Default rule', 'backupwordpress' ); ?>

							<?php elseif ( defined( 'HMBKP_EXCLUDE' ) && false !== strpos( HMBKP_EXCLUDE, $exclude ) ) : ?>

								<?php esc_html_e( 'Defined in wp-config.php', 'backupwordpress' ); ?>

							<?php else : ?>

								<a href="#"
								   onclick="event.preventDefault(); mainwp_backupwp_remove_exclude_rule('<?php esc_attr_e( $exclude ); ?>', this);"
								   class="delete-action"><?php esc_html_e( 'Stop excluding', 'backupwordpress' ); ?></a>

							<?php endif; ?>

						</td>

					</tr>

				<?php endforeach; ?>

				</tbody>

			</table>

			<?php //endif; ?>

			<h3 id="directory-listing"><?php esc_html_e( 'Your Site', 'backupwordpress' ); ?></h3>

			<p><?php esc_html_e( 'Here\'s a directory listing of all files on your site, you can browse through and exclude files or folders that you don\'t want included in your backup.', 'backupwordpress' ); ?></p>

			<?php
			// The directory to display
			$directory = $root_dir;

			if ( isset( $browse_dir ) ) {

				$untrusted_directory = urldecode( $browse_dir );

				// Only allow real sub directories of the site root to be browsed
				if ( false !== strpos( $untrusted_directory, $root_dir ) && is_dir( $untrusted_directory ) ) {
					$directory = $untrusted_directory;
				}
			}

			// Kick off a recursive filesize scan
			if ($new_version) {

				$site_size = new HM\BackUpWordPress\Site_Size;

				$exclude_string = implode( '|', $excludes->get_excludes_for_regex() );

				if (function_exists('HM\BackUpWordPress\list_directory_by_total_filesize'))
					$files = HM\BackUpWordPress\list_directory_by_total_filesize( $directory, $excludes );

			} else {
				$files = $schedule->list_directory_by_total_filesize( $directory );
				$exclude_string = $schedule->backup->exclude_string( 'regex' );
			}

			if ( $files ) { ?>

				<table class="widefat">
					<thead>
					<tr>
						<th></th>
						<th scope="col"><?php esc_html_e( 'Name', 'backupwordpress' ); ?></th>
						<th scope="col" class="column-format"><?php esc_html_e( 'Size', 'backupwordpress' ); ?></th>
						<th scope="col"
						    class="column-format"><?php esc_html_e( 'Permissions', 'backupwordpress' ); ?></th>
						<th scope="col" class="column-format"><?php esc_html_e( 'Type', 'backupwordpress' ); ?></th>
						<th scope="col" class="column-format"><?php esc_html_e( 'Status', 'backupwordpress' ); ?></th>
					</tr>

					<tr>

						<th scope="row">
							<div class="dashicons dashicons-admin-home"></div>
						</th>

						<th scope="col">

							<?php if ( $root_dir !== $directory ) {
								// echo esc_url( remove_query_arg( 'hmbkp_directory_browse' ) );
								?>
								<a href="#"
								   onclick="event.preventDefault(); mainwp_backupwp_directory_browse('', this)"><?php echo esc_html( $root_dir ); ?></a>
								<code>/</code>

								<?php $parents = array_filter( explode( '/', str_replace( trailingslashit( $root_dir ), '', trailingslashit( dirname( $directory ) ) ) ) );

								foreach ( $parents as $directory_basename ) { ?>

									<a href="#"
									   onclick="event.preventDefault(); mainwp_backupwp_directory_browse('<?php echo urlencode( substr( $directory, 0, strpos( $directory, $directory_basename ) ) . $directory_basename ); ?>', this)"><?php echo esc_html( $directory_basename ); ?></a>
									<code>/</code>

								<?php } ?>

								<?php echo esc_html( basename( $directory ) ); ?>

							<?php } else { ?>

								<?php echo esc_html( $root_dir ); ?>

							<?php } ?>

						</th>

						<td class="column-filesize">

							<?php if ( $is_size_calculated ) : ?>

								<span class="spinner "></span>

								<?php
							else :

								$root = new SplFileInfo( $root_dir );

								if ($new_version) {
									$size = $site_size->filesize( $root );
								} else {
									$size = $schedule->filesize( $root, true );
								}

								if ( false !== $size ) {

									$size = size_format( $size );

									if ( ! $size ) {
										$size = '0 B';
									}
									?>

									<code>

										<?php echo esc_html( $size ); ?>

										<a class="dashicons dashicons-update"
										   href="<?php echo esc_attr( wp_nonce_url( add_query_arg( 'hmbkp_recalculate_directory_filesize', urlencode( $root_dir ) ), 'hmbkp-recalculate_directory_filesize' ) ); ?>"><span><?php esc_html_e( 'Refresh', 'backupwordpress' ); ?></span></a>

									</code>


								<?php } ?>

							<?php endif; ?>

						<td>
							<?php echo esc_html( substr( sprintf( '%o', fileperms( $root_dir ) ), - 4 ) ); ?>
						</td>

						<td>

							<?php
							if ( is_link( $root_dir ) ) {
								esc_html_e( 'Symlink', 'backupwordpress' );
							} elseif ( is_dir( $root_dir ) ) {
								esc_html_e( 'Folder', 'backupwordpress' );
							}
							?>

						</td>

						<td></td>

					</tr>

					</thead>

					<tbody>

					<?php foreach ( $files as $size => $file ) {

						$is_excluded = $is_unreadable = false;

						// Check if the file is excluded
						if ($new_version) {
							if ( $exclude_string && preg_match( '(' . $exclude_string . ')', str_ireplace( trailingslashit( $root_dir ), '', wp_normalize_path( $file->getPathname() ) ) ) ) {
								$is_excluded = true;
							}
						} else {
							if ( $exclude_string && preg_match( '(' . $exclude_string . ')', str_ireplace( trailingslashit( $root_dir ), '', HM\BackUpWordPress\Backup::conform_dir( $file->getPathname() ) ) ) ) {
								$is_excluded = true;
							}
						}

						// Skip unreadable files
						if ( ! @realpath( $file->getPathname() ) || ! $file->isReadable() ) {
							$is_unreadable = true;
						} ?>

						<tr>

							<td>

								<?php if ( $is_unreadable ) { ?>

									<div class="dashicons dashicons-dismiss"></div>

								<?php } elseif ( $file->isFile() ) { ?>

									<div class="dashicons dashicons-media-default"></div>

								<?php } elseif ( $file->isDir() ) { ?>

									<div class="dashicons dashicons-portfolio"></div>

								<?php } ?>

							</td>

							<td>
								<?php
								if ($new_version) {
									if ( $is_unreadable ) { ?>

										<code class="strikethrough"
										      title="<?php echo esc_attr( wp_normalize_path( $file->getRealPath() ) ); ?>"><?php echo esc_html( $file->getBasename() ); ?></code>

									<?php } elseif ( $file->isFile() ) { ?>

										<code
											title="<?php echo esc_attr( wp_normalize_path( $file->getRealPath() ) ); ?>"><?php echo esc_html( $file->getBasename() ); ?></code>

									<?php } elseif ( $file->isDir() ) { ?>
										<code title="<?php echo esc_attr( $file->getRealPath() ); ?>"><a
												href="#"
												onclick="event.preventDefault(); mainwp_backupwp_directory_browse('<?php echo urlencode( wp_normalize_path( $file->getPathname()) ); ?>', this)"><?php echo esc_html( $file->getBasename() ); ?></a></code>


									<?php }
								} else {
									if ( $is_unreadable ) { ?>

										<code class="strikethrough"
										      title="<?php echo esc_attr( $file->getRealPath() ); ?>"><?php echo esc_html( $file->getBasename() ); ?></code>

									<?php } elseif ( $file->isFile() ) { ?>

										<code
											title="<?php echo esc_attr( $file->getRealPath() ); ?>"><?php echo esc_html( $file->getBasename() ); ?></code>

									<?php } elseif ( $file->isDir() ) {
										//echo add_query_arg( 'hmbkp_directory_browse', urlencode( $file->getPathname() ) );
										?>
										<code title="<?php echo esc_attr( $file->getRealPath() ); ?>"><a
												href="#"
												onclick="event.preventDefault(); mainwp_backupwp_directory_browse('<?php echo urlencode( $file->getPathname() ); ?>', this)"><?php echo esc_html( $file->getBasename() ); ?></a></code>

									<?php }
								}
								?>

							</td>

							<td class="column-format column-filesize">

								<?php if ( $file->isDir() && $is_size_calculated ) : ?>

									<span class="spinner"></span>

									<?php
								else :
									if ($new_version) {
										$size = $site_size->filesize( $file );
									} else {
										$size = $schedule->filesize( $file );
									}

									if ( false !== $size ) {

										$size = size_format( $size );

										if ( ! $size ) {
											$size = '0 B';
										} ?>

										<code>

											<?php echo esc_html( $size ); ?>

											<?php if ( $file->isDir() ) { ?>

												<a title="<?php esc_attr_e( 'Recalculate the size of this directory', 'backupwordpress' ); ?>"
												   class="dashicons dashicons-update"
												   href="<?php echo esc_attr( wp_nonce_url( add_query_arg( 'hmbkp_recalculate_directory_filesize', urlencode( wp_normalize_path( $file->getPathname() ) ) ), 'hmbkp-recalculate_directory_filesize' ) ); ?>"><span><?php esc_html_e( 'Refresh', 'backupwordpress' ); ?></span></a>

											<?php } ?>

										</code>


									<?php } else { ?>

										<code>--</code>

									<?php }
								endif;
								?>

							</td>

							<td>
								<?php echo esc_html( substr( sprintf( '%o', $file->getPerms() ), - 4 ) ); ?>
							</td>

							<td>

								<?php if ( $file->isLink() ) : ?>

									<span
										title="<?php echo esc_attr( wp_normalize_path( $file->GetRealPath() ) ); ?>"><?php esc_html_e( 'Symlink', 'backupwordpress' ); ?></span>

								<?php elseif ( $file->isDir() ) :

									esc_html_e( 'Folder', 'backupwordpress' );

								else :

									esc_html_e( 'File', 'backupwordpress' );

								endif;
								?>

							</td>

							<td class="column-format">

								<?php if ( $is_unreadable ) : ?>

									<strong
										title="<?php esc_attr_e( 'Unreadable files won\'t be backed up.', 'backupwordpress' ); ?>"><?php esc_html_e( 'Unreadable', 'backupwordpress' ); ?></strong>

								<?php elseif ( $is_excluded ) : ?>

									<strong><?php esc_html_e( 'Excluded', 'backupwordpress' ); ?></strong>

									<?php
								else :

									$exclude_path = $file->getPathname();

									// Excluded directories need to be trailingslashed
									if ( $file->isDir() ) {
										$exclude_path = trailingslashit( wp_normalize_path( $file->getPathname() ) );
									}

									?>

									<a href="#"
									   onclick="event.preventDefault(); mainwp_backupwp_exclude_add_rule('<?php echo urlencode( $exclude_path ); ?>', this)"
									   class="button-secondary"><?php esc_html_e( 'Exclude &rarr;', 'backupwordpress' ); ?></a>

								<?php endif; ?>

							</td>

						</tr>

					<?php } ?>
					</tbody>
				</table>

			<?php } ?>

			<p class="submit">
				<a href="#" onclick="event.preventDefault(); mainwp_backupwp_edit_exclude_done()"
				   class="button-primary"><?php esc_html_e( 'Done', 'backupwordpress' ); ?></a>
			</p>

		</div>

		<?php
		$output           = ob_get_clean();
		$information['e'] = $output;

		return $information;

	}

	function directory_browse() {
		$browse_dir                = $_POST['browse_dir'];
		$out                       = array();
		$return                    = $this->get_excluded( $browse_dir );
		$out['e']                  = $return['e'];
		$out['current_browse_dir'] = $browse_dir;

		return $out;
	}

	function hmbkp_add_exclude_rule() {

		if ( ! isset( $_POST['exclude_pathname'] ) || empty( $_POST['exclude_pathname'] ) ) {
			return array( 'error' => __( 'Error: Empty exclude directory path.' ) );
		}

		$schedule_id = $this->check_schedule();
		$schedule    = new HM\BackUpWordPress\Scheduled_Backup( sanitize_text_field( $schedule_id ) );

		$exclude_rule = urldecode( $_POST['exclude_pathname'] );

		$schedule->set_excludes( $exclude_rule, true );

		$schedule->save();

		$current_path = urldecode( $_POST['browse_dir'] );

		if ( empty( $current_path ) ) {
			$current_path = null;
		}

		$return                    = $this->get_excluded( $current_path );
		$out['e']                  = $return['e'];
		$out['current_browse_dir'] = $_POST['browse_dir'];

		return $out;

	}

	function hmbkp_remove_exclude_rule() {

		if ( ! isset( $_POST['remove_rule'] ) || empty( $_POST['remove_rule'] ) ) {
			return array( 'error' => __( 'Error: Empty exclude directory path.' ) );
		}

		$schedule_id = $this->check_schedule();
		$schedule    = new HM\BackUpWordPress\Scheduled_Backup( sanitize_text_field( $schedule_id ) );

		$excludes = $schedule->get_excludes();
		$exclude_rule_to_remove = stripslashes( sanitize_text_field( $_POST['remove_rule'] ) );

		if (method_exists($excludes, 'get_user_excludes')) {
			$schedule->set_excludes( array_diff( $excludes->get_user_excludes(), (array) $exclude_rule_to_remove ) );
		} else
			$schedule->set_excludes( array_diff( $excludes, $exclude_rule_to_remove ) );

		$schedule->save();

		$current_path = urldecode( $_POST['browse_dir'] );

		if ( empty( $current_path ) ) {
			$current_path = null;
		}

		$return = $this->get_excluded( $current_path );

		$out['e']                  = $return['e'];
		$out['current_browse_dir'] = $_POST['browse_dir'];

		return $out;
	}


    function general_exclude_add_rule() {

		$sch_id  = $this->check_schedule();
        $schedule = new HM\BackUpWordPress\Scheduled_Backup( sanitize_text_field( $sch_id ) );

        $exclude_paths = urldecode( $_POST['exclude_paths'] );
        $exclude_paths = explode("\n", $exclude_paths);
        if (is_array($exclude_paths) && count($exclude_paths) > 0) {
            foreach ( $exclude_paths as $excl_rule ) {
                $excl_rule = trim($excl_rule);
                $excl_rule = trim($excl_rule, '/');

                if (empty($excl_rule))
                        continue;

                $exclude_rule = ABSPATH . $excl_rule;
                $path = realpath($exclude_rule);
                // If it exist
                if($path !== false)
                {
                    $schedule->set_excludes( $exclude_rule, true );
                    $schedule->save();
                }
            }
        }


        $un_exclude_paths = urldecode( $_POST['un_exclude_paths'] );
        $un_exclude_paths = explode("\n", $un_exclude_paths);

        if (is_array($un_exclude_paths) && count(get_user_excludes) > 0) {
            foreach ( $un_exclude_paths as $exclude_rule_to_remove ) {
                    $exclude_rule_to_remove = trim($exclude_rule_to_remove);
                    $exclude_rule_to_remove = trim($exclude_rule_to_remove, '/');

                    if (empty($exclude_rule_to_remove))
                        continue;

                    $excludes = $schedule->get_excludes();
                    if (method_exists($excludes, 'get_user_excludes')) {
                        $schedule->set_excludes( array_diff( $excludes->get_user_excludes(), (array) $exclude_rule_to_remove ) );
                    } else {
                        $schedule->set_excludes( array_diff( $excludes, $exclude_rule_to_remove ) );
                    }
                    $schedule->save();
            }
        }

        return array('result' => 'SUCCESS');
    }


	function update_schedule() {
		$sch_id  = isset( $_POST['schedule_id'] ) ? $_POST['schedule_id'] : 0;
		$sch_id  = sanitize_text_field( urldecode( $sch_id ) );
		$options = isset( $_POST['options'] ) ? maybe_unserialize( base64_decode( $_POST['options'] ) ) : false;

		if ( ! is_array( $options ) || empty( $options ) || empty( $sch_id ) ) {
			return array( 'error' => 'Error: Schedule data' );
		}

//		$current_value = get_option( 'hmbkp_schedule_' . $sch_id );
//		if ( is_array( $current_value ) && isset( $current_value['excludes'] ) ) {
//			// do not update 'excludes' value
//			$options['excludes'] = $current_value['excludes'];
//		}

		$filter_opts = array(
			'type',
			'email',
			'reoccurrence',
			'max_backups',
			'schedule_start_time',
		);

		$out = array();
		if ( is_array( $options ) ) {
			$old_options = get_option( 'hmbkp_schedule_' . $sch_id );
			if ( is_array( $old_options ) ) {
				foreach ( $old_options as $key => $val ) {
					if ( ! in_array( $key, $filter_opts ) ) {
						$options[ $key ] = $old_options[ $key ];
					}
				}
			}

			update_option( 'hmbkp_schedule_' . $sch_id, $options );
            delete_transient( 'hmbkp_schedules' );
			$out['result'] = 'SUCCESS';
		} else {
			$out['result'] = 'NOTCHANGE';
		}

		$schedule = new HM\BackUpWordPress\Scheduled_Backup( sanitize_text_field( $sch_id ) );

		if ( ! empty( $options['reoccurrence'] ) && ! empty( $options['schedule_start_time'] ) ) {
			// Calculate the start time depending on the recurrence
			$start_time = $options['schedule_start_time'];
			if ( $start_time ) {
				$schedule->set_schedule_start_time( $start_time );
			}
		}

		if ( ! empty( $options['reoccurrence'] ) ) {
			$schedule->set_reoccurrence( $options['reoccurrence'] );
		}
		$out['next_occurrence'] = $schedule->get_next_occurrence( false );

		return $out;
	}

	public function save_all_schedules() {
		$schedules = isset( $_POST['all_schedules'] ) ? maybe_unserialize( base64_decode( $_POST['all_schedules'] ) ) : false;

		if ( ! is_array( $schedules ) || empty( $schedules ) ) {
			return array( 'error' => 'Error: Schedule data' );
		}

		$out = array();
		foreach($schedules as $sch_id => $sch) {
			if ( empty($sch_id) || !isset( $sch['options'] ) || ! is_array( $sch['options'] ) )
				continue;
			$options = $sch['options'];
			$filter_opts = array(
				'type',
				'email',
				'reoccurrence',
				'max_backups',
				'schedule_start_time',
			);
			if ( is_array( $options ) ) {
				$old_options = get_option( 'hmbkp_schedule_' . $sch_id );
				if ( is_array( $old_options ) ) {
					foreach ( $old_options as $key => $val ) {
						if ( ! in_array( $key, $filter_opts ) ) {
							$options[ $key ] = $old_options[ $key ];
						}
					}
				}
				update_option( 'hmbkp_schedule_' . $sch_id, $options );
			}

			$schedule = new HM\BackUpWordPress\Scheduled_Backup( sanitize_text_field( $sch_id ) );

			if ( ! empty( $options['reoccurrence'] ) && ! empty( $options['schedule_start_time'] ) ) {
				// Calculate the start time depending on the recurrence
				$start_time = $options['schedule_start_time'];
				if ( $start_time ) {
					$schedule->set_schedule_start_time( $start_time );
				}
			}

			if ( ! empty( $options['reoccurrence'] ) ) {
				$schedule->set_reoccurrence( $options['reoccurrence'] );
			}
			$out['result'] = 'SUCCESS';
		}
                delete_transient( 'hmbkp_schedules' );
		return $out;
	}

	public static function isActivated() {
		if ( ! defined( 'HMBKP_PLUGIN_PATH' ) || ! class_exists( 'HM\BackUpWordPress\Plugin' ) ) {
			return false;
		}

		return true;
	}


	public function all_plugins( $plugins ) {
		foreach ( $plugins as $key => $value ) {
			$plugin_slug = basename( $key, '.php' );
			if ( 'backupwordpress' === $plugin_slug ) {
				unset( $plugins[ $key ] );
			}
		}

		return $plugins;
	}

	public function remove_menu() {
		global $submenu;
		if ( isset( $submenu['tools.php'] ) ) {
			foreach ( $submenu['tools.php'] as $index => $item ) {
				if ( 'backupwordpress' === $item[2] ) {
					unset( $submenu['tools.php'][ $index ] );
					break;
				}
			}
		}

		$pos = stripos( $_SERVER['REQUEST_URI'], 'tools.php?page=backupwordpress' );
		if ( false !== $pos ) {
			wp_redirect( get_option( 'siteurl' ) . '/wp-admin/index.php' );
			exit();
		}
	}
}

