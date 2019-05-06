<?php

/*
 *
 * Credits
 *
 * Plugin-Name: iThemes Security
 * Plugin URI: https://ithemes.com/security
 * Author: iThemes
 * Author URI: https://ithemes.com
 * License: GPLv2
 *
 * The code is used for the MainWP iThemes Security Extension
 * Extension URL: https://mainwp.com/extension/ithemes-security/
 *
*/

class MainWP_Child_iThemes_Security {
	public static $instance = null;
    public $is_plugin_installed = false;

	static function Instance() {
		if ( null === MainWP_Child_iThemes_Security::$instance ) {
			MainWP_Child_iThemes_Security::$instance = new MainWP_Child_iThemes_Security();
		}

		return MainWP_Child_iThemes_Security::$instance;
	}

	public function __construct() {
        require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
		if ( is_plugin_active( 'better-wp-security/better-wp-security.php') || is_plugin_active( 'ithemes-security-pro/ithemes-security-pro.php' ) ) {
            $this->is_plugin_installed = true;
		}

        if (!$this->is_plugin_installed)
            return;

		add_filter( 'mainwp-site-sync-others-data', array( $this, 'syncOthersData' ), 10, 2 );
	}
    // ok
	function syncOthersData( $information, $data = array() ) {
		if ( is_array( $data ) && isset( $data['ithemeExtActivated'] ) && ( 'yes' === $data['ithemeExtActivated'] ) ) {
            try{
                $information['syncIThemeData'] = array(
                    'users_and_roles' => $this->get_available_admin_users_and_roles()
                );
            } catch(Exception $e) {
                error_log($e->getMessage());
            }
		}
		return $information;
	}

	public function action() {
		$information = array();
		if ( ! class_exists( 'ITSEC_Core' ) || !class_exists('ITSEC_Modules')) {
			$information['error'] = 'NO_ITHEME';
			MainWP_Helper::write( $information );
		}

		global $mainwp_itsec_modules_path;

		$mainwp_itsec_modules_path = ITSEC_Core::get_core_dir() . '/modules/';


		if ( isset( $_POST['mwp_action'] ) ) {
			switch ( $_POST['mwp_action'] ) {
				case 'set_showhide':
					$information = $this->set_showhide();
					break;
				case 'save_settings':
					$information = $this->save_settings();
					break;
				case 'whitelist_release':
					$information = $this->whitelist_release();
					break;
				case 'backup_db':
					$information = $this->backup_db();
					break;
				case 'admin_user':
					$information = $this->admin_user();
					break;
				case 'database_prefix':
					$information = $this->change_database_prefix();
					break;
				case 'reset_api_key':
					$information = $this->reset_api_key();
					break;
				case 'malware_scan':
					$information = $this->malware_scan();
					break;
				case 'clear_all_logs':
					$information = $this->purge_logs();
					break;
				case 'file_change':
					$information = $this->file_change();
					break;
				case 'release_lockout':
					$information = $this->release_lockout();
					break;
				case 'module_status':
					$information = $this->update_module_status();
					break;
				case 'wordpress_salts':
					$information = $this->wordpress_salts();
					break;
				case 'file_permissions':
					$information = $this->file_permissions();
					break;
				case 'reload_backup_exclude':
					$information = $this->reload_backup_exclude();
					break;
				case 'security_site':
					$information = $this->security_site();
					break;
				case 'activate_network_brute_force':
					$information = $this->activate_network_brute_force();
					break;
			}
		}
		MainWP_Helper::write( $information );
	}

	function set_showhide() {
		$hide = isset( $_POST['showhide'] ) && ( 'hide' === $_POST['showhide'] ) ? 'hide' : '';
		MainWP_Helper::update_option( 'mainwp_ithemes_hide_plugin', $hide );
		$information['result'] = 'success';

		return $information;
	}

	public function ithemes_init() {
        if (!$this->is_plugin_installed)
			return;

		if ( get_option( 'mainwp_ithemes_hide_plugin' ) === 'hide' ) {
			add_filter( 'all_plugins', array( $this, 'all_plugins' ) );
			add_action( 'admin_menu', array( $this, 'remove_menu' ) );
			add_action( 'admin_init', array( $this, 'admin_init' ) );
			add_action( 'admin_head', array( &$this, 'custom_admin_css' ) );
			if ( isset($_GET['page']) && ($_GET['page'] == 'itsec' || $_GET['page'] == 'itsec-security-check') ) {
				wp_redirect( get_option( 'siteurl' ) . '/wp-admin/index.php' );
				exit();
			}
		}
	}

	public function admin_init() {
		remove_meta_box( 'itsec-dashboard-widget', 'dashboard', 'normal' );
	}

	public function all_plugins( $plugins ) {
		foreach ( $plugins as $key => $value ) {
			$plugin_slug = basename( $key, '.php' );
			if ( 'better-wp-security' === $plugin_slug || 'ithemes-security-pro' === $plugin_slug ) {
				unset( $plugins[ $key ] );
			}
		}

		return $plugins;
	}

	public function remove_menu() {
		remove_menu_page( 'itsec' );
	}

	function custom_admin_css() {
		?>
		<style type="text/css">
			#wp-admin-bar-itsec_admin_bar_menu{
				display: none !important;
			}
		</style>
		<?php
	}

	function save_settings() {

		if ( ! class_exists( 'ITSEC_Lib' ) ) {
			require(  ITSEC_Core::get_core_dir() . '/core/class-itsec-lib.php' );
		}

		$_itsec_modules = array(
			'global',
			'away-mode',
			'backup',
			'hide-backend',
			'ipcheck',
			'ban-users',
			'brute-force',
			'file-change',
			'404-detection',
			'network-brute-force',
			'ssl',
			//'strong-passwords',
            'password-requirements',
			'system-tweaks',
			'wordpress-tweaks',
			'multisite-tweaks',
            'notification-center',
			//'salts',
			//'content-directory',
		);

		$require_permalinks = false;
		$updated          = false;
		$errors			= array();
		$nbf_settings = array();

		$update_settings         = maybe_unserialize( base64_decode( $_POST['settings'] ) );

		foreach($update_settings as $module => $settings) {
			$do_not_save = false;
			if (in_array($module, $_itsec_modules)) {
				if ($module == 'wordpress-salts') {
					$settings['last_generated'] = ITSEC_Modules::get_setting( $module, 'last_generated' ); // not update
				} else if ($module == 'global') {
					$keep_olds = array( 'did_upgrade', 'log_info', 'show_new_dashboard_notice', 'show_security_check' , 'nginx_file' );
					foreach($keep_olds as $key) {
						$settings[$key] = ITSEC_Modules::get_setting( $module, $key ); // not update
					}

					if (!isset($settings['log_location']) || empty($settings['log_location']) ) {
						$settings['log_location'] = ITSEC_Modules::get_setting( $module, 'log_location' );
					} else {
						$result = $this->validate_directory('log_location', $settings['log_location']);
						if ($result !== true) {
							$errors[] = $result;
							$settings['log_location'] = ITSEC_Modules::get_setting( $module, 'log_location' ); // no change
						}
					}

				} else if ($module == 'backup') {
					if (!isset($settings['location']) || empty($settings['location']) ) {
						$settings['location'] = ITSEC_Modules::get_setting( $module, 'location' );
					} else {
						$result = $this->validate_directory('location', $settings['location']);
						if ($result !== true) {
							$errors[] = $result;
							$settings['location'] = ITSEC_Modules::get_setting( $module, 'location' ); // no change
						}
					}
					if (!isset($settings['exclude']) ) {
						$settings['exclude'] = ITSEC_Modules::get_setting( $module, 'exclude' );;
					}
				} else if ($module == 'hide-backend') {
					if (isset($settings['enabled']) && !empty($settings['enabled'])) {
						$permalink_structure = get_option( 'permalink_structure', false );
						if ( empty( $permalink_structure ) && ! is_multisite() ) {
							$errors[] = __( 'You must change <strong>WordPress permalinks</strong> to a setting other than "Plain" in order to use "Hide Backend" feature.', 'better-wp-security' );
							$require_permalinks = true;
							$do_not_save = true;
						}
					}
				} else if ($module == 'network-brute-force') {

					if ( isset( $settings['email'] ) ) {
						$result = $this->activate_api_key($settings);
						if ($result === false) {
							$nbf_settings = $settings;
							$errors[] = 'Error: Active iThemes Network Brute Force Protection Api Key';
						} else {
							$nbf_settings = $result;
						}
					} else {
						$previous_settings = ITSEC_Modules::get_settings( $module );
						// update 'enable_ban' field only
						if (isset($settings['enable_ban'])) {
							$previous_settings['enable_ban'] = $settings['enable_ban'];
							$nbf_settings = $previous_settings;
						} else {
							$do_not_save = true;
							$nbf_settings = $previous_settings;
						}
					}
					$settings = $nbf_settings;
				} else if ($module == 'notification-center') {
                    $current_settings = ITSEC_Modules::get_settings( $module );
                    if (isset($settings['notifications'])) {
                        $update_fields = array( 'schedule', 'enabled', 'subject');
                        if (isset($_POST['is_individual']) && $_POST['is_individual']) {
                            $update_fields = array_merge($update_fields, array('user_list', 'email_list'));
                        }
                        foreach ($settings['notifications'] as $key => $val) {
                            foreach ($update_fields as $field) {
                                if(isset($val[$field])) {
                                    $current_settings['notifications'][$key][$field] = $val[$field];
                                }
                            }
                        }
                        $updated          = true;
                        ITSEC_Modules::set_settings( $module, $current_settings );
                    }
                    continue;
                }

				if ( !$do_not_save ) {
					ITSEC_Modules::set_settings( $module, $settings );
					$updated          = true;
				}
			}
		}

        if ( isset( $update_settings['itsec_active_modules'] ) ) {
            $current_val = get_site_option( 'itsec_active_modules', array() );
            foreach ($update_settings['itsec_active_modules'] as $mod => $val) {
                $current_val[$mod] = $val;
            }
            update_site_option( 'itsec_active_modules', $current_val );
        }

		require_once( ITSEC_Core::get_core_dir() . '/lib/class-itsec-lib-config-file.php' );

		$values = array(
			'permalink_structure'   => get_option( 'permalink_structure' ),
			'is_multisite'          => is_multisite() ? 1 : 0,
			'users_can_register'    => get_site_option( 'users_can_register' ) ? 1 : 0,
			'server_nginx'          => ( ITSEC_Lib::get_server() === 'nginx' ) ? 1 : 0,
			'has_ssl'				=> ITSEC_Lib::get_ssl_support_probability(),
			'jquery_version'		=> ITSEC_Modules::get_setting( 'wordpress-tweaks', 'jquery_version' ),
			'server_rules'			=> ITSEC_Lib_Config_File::get_server_config(),
			'config_rules'			=> ITSEC_Lib_Config_File::get_wp_config(),
			'lockouts_host'         => $this->get_lockouts( 'host', true ),
			'lockouts_user'         => $this->get_lockouts( 'user', true ),
			'lockouts_username'     => $this->get_lockouts( 'username', true ),
			'default_log_location'     => ITSEC_Modules::get_default( 'global', 'log_location' ),
			'default_location'     => ITSEC_Modules::get_default( 'backup', 'location' ),
			'excludable_tables' => $this->get_excludable_tables(),
            'users_and_roles' => $this->get_available_admin_users_and_roles()
		);

 		$return = array(
			'site_status' => $values
		);

		if ($require_permalinks) {
			$return['require_permalinks'] = 1;
		}

		$return['nbf_settings'] = $nbf_settings;

		if (!empty($errors)) {
			$return['extra_message'] = $errors;
		}

		if ($updated)
			$return['result'] = 'success';
		else
			$return['error'] = __('Not Updated', 'mainwp-child' );

		return $return;
	}

	public static function activate_network_brute_force() {
		$data  = maybe_unserialize( base64_decode( $_POST['data'] ) );
		$information = array();
		if (is_array($data)) {
			$settings = ITSEC_Modules::get_settings( 'network-brute-force' );
			$settings['email'] = $data['email'];
			$settings['updates_optin'] = $data['updates_optin'];
			$settings['api_nag'] = false;
			$results = ITSEC_Modules::set_settings( 'network-brute-force', $settings );
			if ( is_wp_error( $results ) ) {
				$information['error'] = 'Error: Active iThemes Network Brute Force Protection Api Key';
			} else if ( $results['saved'] ) {
				ITSEC_Modules::activate( 'network-brute-force' );
				$nbf_settings = ITSEC_Modules::get_settings( 'network-brute-force' );
//				ITSEC_Response::set_response( '<p>' . __( 'Your site is now using Network Brute Force Protection.', 'better-wp-security' ) . '</p>' );
			}
		}
		if ($nbf_settings !== null) {
			$information['nbf_settings'] = $nbf_settings;
			$information['result'] = 'success';
		}
		return $information;
	}

	private function validate_directory($name, $folder) {
		require_once( ITSEC_Core::get_core_dir() . 'lib/class-itsec-lib-directory.php' );
		$error = null;
		if ( ! ITSEC_Lib_Directory::is_dir( $folder ) ) {
			$result = ITSEC_Lib_Directory::create( $folder );

			if ( is_wp_error( $result ) ) {
				$error = sprintf( _x( 'The directory supplied in %1$s cannot be used as a valid directory. %2$s', '%1$s is the input name. %2$s is the error message.', 'better-wp-security' ), $name, $result->get_error_message() );
			}
		}

		if ( empty( $error ) && ! ITSEC_Lib_Directory::is_writable( $folder ) ) {
			$error = sprintf( __( 'The directory supplied in %1$s is not writable. Please select a directory that can be written to.', 'better-wp-security' ), $name );
		}

		if ( empty( $error ) ) {
			ITSEC_Lib_Directory::add_file_listing_protection( $folder );
			return true;
		} else {
			return $error;
		}
	}

	private function activate_api_key($settings) {
		global $mainwp_itsec_modules_path;
		require_once ( $mainwp_itsec_modules_path . 'ipcheck/utilities.php' );

		$key = ITSEC_Network_Brute_Force_Utilities::get_api_key( $settings['email'], $settings['updates_optin'] );
		if ( is_wp_error( $key ) ) {
			return false;
//			$this->set_can_save( false );
//			$this->add_error( $key );
		} else {
			$secret = ITSEC_Network_Brute_Force_Utilities::activate_api_key( $key );

			if ( is_wp_error( $secret ) ) {
				return false;
//				$this->set_can_save( false );
//				$this->add_error( $secret );
			} else {
				$settings['api_key'] = $key;
				$settings['api_secret'] = $secret;

				$settings['api_nag'] = false;

				ITSEC_Response::reload_module( 'network-brute-force' );
			}
		}
		unset( $settings['email'] );
		return $settings;
	}
	function backup_status() {
		$status = 0;
		if ( ! is_multisite() && class_exists( 'backupbuddy_api' ) && count( backupbuddy_api::getSchedules() ) >= 1 ) {
			$status = 1;
		} elseif ( ! is_multisite() && class_exists( 'backupbuddy_api' ) ) {
			$status = 2;
		} elseif ( $this->has_backup() === true && $this->scheduled_backup() === true ) {
			$status = 3;
		} elseif ( $this->has_backup() === true ) {
			$status = 4;
		}

		return $status;
	}

	public function has_backup() {
		$has_backup = false;

		return apply_filters( 'itsec_has_external_backup', $has_backup );
	}

	public function scheduled_backup() {
		$sceduled_backup = false;

		return apply_filters( 'itsec_scheduled_external_backup', $sceduled_backup );
	}

	public function whitelist() {

		global $itsec_globals;
		$ip       = $_POST['ip'];
		$add_temp = false;
		$temp_ip  = get_site_option( 'itsec_temp_whitelist_ip' );
		if ( false !== $temp_ip ) {
			if ( ( $temp_ip['exp'] < $itsec_globals['current_time'] ) || ( $temp_ip['exp'] !== $ip ) ) {
				delete_site_option( 'itsec_temp_whitelist_ip' );
				$add_temp = true;
			}
		} else {
			$add_temp = true;
		}

		if ( false === $add_temp ) {
			return array( 'error' => 'Not Updated' );
		} else {
			$response = array(
				'ip'  => $ip,
				'exp' => $itsec_globals['current_time'] + 86400,
			);
			add_site_option( 'itsec_temp_whitelist_ip', $response );
			$response['exp_diff'] = human_time_diff( $itsec_globals['current_time'], $response['exp'] );
			$response['message1'] = __( 'Your IP Address', 'better-wp-security' );
			$response['message2'] = __( 'is whitelisted for', 'better-wp-security' );

			return $response;
		}

	}

	function whitelist_release() {
		delete_site_option( 'itsec_temp_whitelist_ip' );

		return 'success';
	}

	function backup_db() {
		global $itsec_backup, $mainwp_itsec_modules_path;

		if ( ! isset( $itsec_backup ) ) {
			require_once ( $mainwp_itsec_modules_path . 'backup/class-itsec-backup.php' );
			$itsec_backup = new ITSEC_Backup();
			$itsec_backup->run();
		}

		$return  = array();

		$str_error = '';
		$result = $itsec_backup->do_backup( true );

		if ( is_wp_error( $result ) ) {
			$errors = ITSEC_Response::get_error_strings( $result );

			foreach ( $errors as $error ) {
				$str_error .= $error . '<br />';
			}
		} else if ( is_string( $result ) ) {
			$return['result'] = 'success';
			$return['message'] = $result;
		} else {
			$str_error = sprintf( __( 'The backup request returned an unexpected response. It returned a response of type <code>%1$s</code>.', 'better-wp-security' ), gettype( $result ) ) ;
		}

		if (!empty($str_error)) {
			$return['error'] = $str_error;
		}

		return $return;
	}


	private function wordpress_salts() {
		global $mainwp_itsec_modules_path;
		if ( ! class_exists( 'ITSEC_WordPress_Salts_Utilities' ) ) {
			require(  $mainwp_itsec_modules_path . 'salts/utilities.php' );
		}
		$result = ITSEC_WordPress_Salts_Utilities::generate_new_salts();
		$str_error = '';
		if ( is_wp_error( $result ) ) {
			$errors = ITSEC_Response::get_error_strings( $result );

			foreach ( $errors as $error ) {
				$str_error .= $error . '<br />';
			}
		} else {
			$return['result'] = 'success';
			$return['message'] = __( 'The WordPress salts were successfully regenerated.', 'better-wp-security' ) ;
			$last_generated = ITSEC_Core::get_current_time_gmt();
			ITSEC_Modules::set_setting( 'wordpress-salts', 'last_generated', $last_generated );
		}
		if (!empty($str_error)) {
			$return['error'] = $str_error;
		}
		return $return;
	}

	private function file_permissions() {
			require_once( ITSEC_Core::get_core_dir() . '/lib/class-itsec-lib-config-file.php' );

			$wp_upload_dir = ITSEC_Core::get_wp_upload_dir();

			$path_data = array(
				array(
					ABSPATH,
					0755,
				),
				array(
					ABSPATH . WPINC,
					0755,
				),
				array(
					ABSPATH . 'wp-admin',
					0755,
				),
				array(
					ABSPATH . 'wp-admin/js',
					0755,
				),
				array(
					WP_CONTENT_DIR,
					0755,
				),
				array(
					get_theme_root(),
					0755,
				),
				array(
					WP_PLUGIN_DIR,
					0755
				),
				array(
					$wp_upload_dir['basedir'],
					0755,
				),
				array(
					ITSEC_Lib_Config_File::get_wp_config_file_path(),
					0444,
				),
				array(
					ITSEC_Lib_Config_File::get_server_config_file_path(),
					0444,
				),
			);


			$rows = array();

			foreach ( $path_data as $path ) {
				$row = array();

				list( $path, $suggested_permissions ) = $path;

				$display_path = preg_replace( '/^' . preg_quote( ABSPATH, '/' ) . '/', '', $path );
				$display_path = ltrim( $display_path, '/' );

				if ( empty( $display_path ) ) {
					$display_path = '/';
				}

				$row[] = $display_path;
				$row[] = sprintf( '%o', $suggested_permissions );

				$permissions = fileperms( $path ) & 0777;
				$row[] = sprintf( '%o', $permissions );

				if ( ! $permissions || $permissions != $suggested_permissions ) {
					$row[] = __( 'WARNING', 'better-wp-security' );
					$row[] = '<div style="background-color: #FEFF7F; border: 1px solid #E2E2E2;">&nbsp;&nbsp;&nbsp;</div>';
				} else {
					$row[] = __( 'OK', 'better-wp-security' );
					$row[] = '<div style="background-color: #22EE5B; border: 1px solid #E2E2E2;">&nbsp;&nbsp;&nbsp;</div>';
				}

				$rows[] = $row;
			}


			$class = 'entry-row';
		ob_start();
		?>
		<p><input type="button" id="itsec-file-permissions-reload_file_permissions" name="file-permissions[reload_file_permissions]" class="button-primary itsec-reload-module" value="<?php _e('Reload File Permissions Details', 'mainwp-child'); ?>"></p>
		<table class="widefat">
			<thead>
				<tr>
					<th><?php _e( 'Relative Path', 'better-wp-security' ); ?></th>
					<th><?php _e( 'Suggestion', 'better-wp-security' ); ?></th>
					<th><?php _e( 'Value', 'better-wp-security' ); ?></th>
					<th><?php _e( 'Result', 'better-wp-security' ); ?></th>
					<th><?php _e( 'Status', 'better-wp-security' ); ?></th>
				</tr>
			</thead>
			<tfoot>
				<tr>
					<th><?php _e( 'Relative Path', 'better-wp-security' ); ?></th>
					<th><?php _e( 'Suggestion', 'better-wp-security' ); ?></th>
					<th><?php _e( 'Value', 'better-wp-security' ); ?></th>
					<th><?php _e( 'Result', 'better-wp-security' ); ?></th>
					<th><?php _e( 'Status', 'better-wp-security' ); ?></th>
				</tr>
			</tfoot>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr class="<?php echo $class; ?>">
						<?php foreach ( $row as $column ) : ?>
							<td><?php echo $column; ?></td>
						<?php endforeach; ?>
					</tr>
					<?php $class = ( 'entry-row' === $class ) ? 'entry-row alternate' : 'entry-row'; ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<br />
	<?php
		$html = ob_get_clean();
		return array('html' => $html);
	}

	public function file_change() {
		global $mainwp_itsec_modules_path;
		if ( ! class_exists( 'ITSEC_File_Change_Scanner' ) ) {
			require_once(  $mainwp_itsec_modules_path . 'file-change/scanner.php' );
		}
		$result = ITSEC_File_Change_Scanner::run_scan( false );
		if ($result === false || $result === true || $result === -1) {
			$return['result'] = 'success';
			$return['scan_result'] = $result;
		}
		return $return;
	}

	function admin_user() {

		$settings = $_POST['settings'];

		if (!is_array($settings))
			$settings = array();

		$new_username = isset( $settings['new_username'] )  ? $settings['new_username'] : '';
		$change_id = isset( $settings['change_id'] )  && $settings['change_id']  ? true : false;


		//load utility functions
		if ( ! class_exists( 'ITSEC_Lib' ) ) {
			global $itsec_globals;
			require(  ITSEC_Core::get_core_dir() . '/core/class-itsec-lib.php' );
		}

		$username_exists = username_exists( 'admin' );
		$user_id_exists  = ITSEC_Lib::user_id_exists( 1 );
		$msg             = '';
        if ( strlen( $new_username ) >= 1) {
            global $current_user;
            if ( ! $username_exists ) {
                $msg = __( 'Admin user already changes.', 'mainwp-child' );
            } else if ($current_user->user_login == 'admin') {
                $return['result'] = 'CHILD_ADMIN';
                return $return;
            }
        }


		if ( true === $change_id && ! $user_id_exists ) {
			if ( ! empty( $msg ) ) {
				$msg .= '<br/>';
			}
			$msg .= __( 'Admin user ID already changes.', 'mainwp-child' );
		}

//		if ( $change_id ) {
//			$user = get_user_by( 'login', $new_username );
//			if ( $user && 1 === (int) $user->ID ) {
//				$return['result'] = 'CHILD_ADMIN';
//				return $return;
//			}
//		}

		$admin_success = true;
		$return           = array();

		if ( strlen( $new_username ) >= 1 && $username_exists ) {
			$admin_success = $this->change_admin_user( $new_username, $change_id );
		} elseif ( true === $change_id && $user_id_exists ) {
			$admin_success = $this->change_admin_user( null, $change_id );
		}

		$return['message'] = $msg;
		if ( false === $admin_success ) {
			$return['result'] = 'fail';
		} else {
			$return['result'] = 'success';
		}
		return $return;
	}

	private function change_admin_user( $username = null, $id = false ) {

		global $wpdb;
		$itsec_files = ITSEC_Core::get_itsec_files();

        // do not need to check this
		//if ( $itsec_files->get_file_lock( 'admin_user' ) ) { //make sure it isn't already running

			//sanitize the username
			$new_user = sanitize_text_field( $username );

			//Get the full user object
			$user_object = get_user_by( 'id', '1' );

			if ( null !== $username && validate_username( $new_user ) && false === username_exists( $new_user ) ) { //there is a valid username to change

				if ( true === $id ) { //we're changing the id too so we'll set the username

					$user_login = $new_user;

				} else { // we're only changing the username

					//query main user table
					$wpdb->query( "UPDATE `" . $wpdb->users . "` SET user_login = '" . esc_sql( $new_user ) . "' WHERE user_login='admin';" );

					if ( is_multisite() ) { //process sitemeta if we're in a multi-site situation

						$oldAdmins = $wpdb->get_var( 'SELECT meta_value FROM `' . $wpdb->sitemeta . "` WHERE meta_key = 'site_admins'" );
						$newAdmins = str_replace( '5:"admin"', strlen( $new_user ) . ':"' . esc_sql( $new_user ) . '"', $oldAdmins );
						$wpdb->query( $wpdb->prepare( 'UPDATE `' . $wpdb->sitemeta . "` SET meta_value = %s WHERE meta_key = 'site_admins'", $newAdmins ) );
					}

					wp_clear_auth_cookie();
					$itsec_files->release_file_lock( 'admin_user' );

					return true;

				}
			} elseif ( null !== $username ) { //username didn't validate

				$itsec_files->release_file_lock( 'admin_user' );

				return false;

			} else { //only changing the id

				$user_login = $user_object->user_login;

			}

			if ( true === $id ) { //change the user id

				$wpdb->query( 'DELETE FROM `' . $wpdb->users . '` WHERE ID = 1;' );

				$wpdb->insert( $wpdb->users, array(
					'user_login'          => $user_login,
					'user_pass'           => $user_object->user_pass,
					'user_nicename'       => $user_object->user_nicename,
					'user_email'          => $user_object->user_email,
					'user_url'            => $user_object->user_url,
					'user_registered'     => $user_object->user_registered,
					'user_activation_key' => $user_object->user_activation_key,
					'user_status'         => $user_object->user_status,
					'display_name'        => $user_object->display_name,
				) );

				if ( is_multisite() && null !== $username && validate_username( $new_user ) ) { //process sitemeta if we're in a multi-site situation

					$oldAdmins = $wpdb->get_var( 'SELECT meta_value FROM `' . $wpdb->sitemeta . "` WHERE meta_key = 'site_admins'" );
					$newAdmins = str_replace( '5:"admin"', strlen( $new_user ) . ':"' . esc_sql( $new_user ) . '"', $oldAdmins );
					$wpdb->query( 'UPDATE `' . $wpdb->sitemeta . "` SET meta_value = '" . esc_sql( $newAdmins ) . "' WHERE meta_key = 'site_admins'" );

				}

				$new_user = $wpdb->insert_id;

				$wpdb->query( $wpdb->prepare( 'UPDATE `' . $wpdb->posts . "` SET post_author = %s WHERE post_author = 1;", $new_user ) );
				$wpdb->query( $wpdb->prepare( 'UPDATE `' . $wpdb->usermeta . "` SET user_id = %s WHERE user_id = 1;", $new_user ) );
				$wpdb->query( $wpdb->prepare( 'UPDATE `' . $wpdb->comments . "` SET user_id = %s WHERE user_id = 1;", $new_user ) );
				$wpdb->query( $wpdb->prepare( 'UPDATE `' . $wpdb->links . "` SET link_owner = %s WHERE link_owner = 1;", $new_user ) );

				wp_clear_auth_cookie();
				$itsec_files->release_file_lock( 'admin_user' );

				return true;

			}
		//}

		return false;

	}

	public function build_wpconfig_rules( $rules_array, $input = null ) {
		//Get the rules from the database if input wasn't sent
		if ( null === $input ) {
			return $rules_array;
		}

		$new_dir = trailingslashit( ABSPATH ) . $input;

		$rules[] = array(
			'type'        => 'add',
			'search_text' => '//Do not delete these. Doing so WILL break your site.',
			'rule'        => '//Do not delete these. Doing so WILL break your site.',
		);

		$rules[] = array(
			'type'        => 'add',
			'search_text' => 'WP_CONTENT_URL',
			'rule'        => "define( 'WP_CONTENT_URL', '" . trailingslashit( get_option( 'siteurl' ) ) . $input . "' );",
		);

		$rules[] = array(
			'type'        => 'add',
			'search_text' => 'WP_CONTENT_DIR',
			'rule'        => "define( 'WP_CONTENT_DIR', '" . $new_dir . "' );",
		);

		$rules_array[] = array( 'type' => 'wpconfig', 'name' => 'Content Directory', 'rules' => $rules );

		return $rules_array;

	}


	public function change_database_prefix() {
		global $mainwp_itsec_modules_path;
		require_once( $mainwp_itsec_modules_path . 'database-prefix/utility.php' );
		$str_error = '';
		$return = array();

		if ( isset( $_POST['change_prefix'] ) && 'yes' === $_POST['change_prefix'] ) {
			$result = ITSEC_Database_Prefix_Utility::change_database_prefix();
			$return = $result['errors'];
			if (is_array($result['errors'])) {
				foreach ($result['errors'] as $error) {
					$arr_errors = ITSEC_Response::get_error_strings( $error );
					foreach ( $arr_errors as $er ) {
						$str_error .= $er . '<br />';
					}
				}
			}

			ITSEC_Response::reload_module( 'database-prefix' );

			if ( false === $result['new_prefix'] ) {
				$return['error'] = $str_error;
			} else {
				$return['result'] = 'success';
				$return['message'] = sprintf( __( 'The database table prefix was successfully changed to <code>%1$s</code>.', 'better-wp-security' ), $result['new_prefix'] );

			}
		}
		return $return;
	}

	public function api_key() {
		$settings = get_site_option( 'itsec_ipcheck' );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$settings['reset'] = true;
		$return               = array();
		if ( update_site_option( 'itsec_ipcheck', $settings ) ) {
			$return['result'] = 'success';
		} else {
			$return['result'] = 'nochange';
		}

		return $return;
	}

	public function reset_api_key() {

		$defaults = ITSEC_Modules::get_defaults( 'network-brute-force' );
		$results = ITSEC_Modules::set_settings( 'network-brute-force', $defaults );

		ITSEC_Response::set_response( $results['saved'] );
		ITSEC_Response::add_errors( $results['errors'] );
		ITSEC_Response::add_messages( $results['messages'] );

		$information = array();
		if ( $results['saved'] ) {
			$information['result'] = 'success';
			$information['nbf_settings'] = ITSEC_Modules::get_settings( 'network-brute-force');
		} else if ( empty( $results['errors'] ) ) {
			$information['error_reset_api'] = 1;
		}
		return $information;
	}

	public function malware_scan() {
		global $mainwp_itsec_modules_path;

		if ( ! class_exists( 'ITSEC_Malware_Scanner' ) ) {
			require_once( $mainwp_itsec_modules_path . 'malware/class-itsec-malware-scanner.php' );
			require_once( $mainwp_itsec_modules_path . 'malware/class-itsec-malware-scan-results-template.php' );
		}

		$response = array();
		if ( ! ITSEC_Core::current_user_can_manage() ) {
			$response['error'] = 'The currently logged in user does not have sufficient permissions to run this scan.';
		} else {
			$results = ITSEC_Malware_Scanner::scan();
			$response['html'] = ITSEC_Malware_Scan_Results_Template::get_html( $results, true );
		}

		return $response;
	}

	public function malware_get_scan_results() {

		global $mainwp_itsec_modules_path;
		if ( ! class_exists( 'ITSEC_Malware_Scanner' ) ) {
			require_once( $mainwp_itsec_modules_path . 'malware/class-itsec-malware-scanner.php' );
			require_once( $mainwp_itsec_modules_path . 'malware/class-itsec-malware-scan-results-template.php' );
		}
		$response = array();
		$results= ITSEC_Malware_Scanner::scan();
		$response['html'] = ITSEC_Malware_Scan_Results_Template::get_html( $results, true );
		return $response;
	}

	public function purge_logs() {
		global $wpdb;
		$wpdb->query( 'DELETE FROM `' . $wpdb->base_prefix . 'itsec_log`;' );

		return array( 'result' => 'success' );
	}


	public function get_lockouts( $type = 'all', $current = false ) {

		global $wpdb, $itsec_globals;

		if ( 'all' !== $type || true === $current ) {
			$where = ' WHERE ';
		} else {
			$where = '';
		}

		switch ( $type ) {

			case 'host':
				$type_statement = "`lockout_host` IS NOT NULL && `lockout_host` != ''";
				break;
			case 'user':
				$type_statement = '`lockout_user` != 0';
				break;
			case 'username':
				$type_statement = "`lockout_username` IS NOT NULL && `lockout_username` != ''";
				break;
			default:
				$type_statement = '';
				break;

		}

		if ( true === $current ) {

			if ( '' !== $type_statement ) {
				$and = ' AND ';
			} else {
				$and = '';
			}

			$active = $and . " `lockout_active`=1 AND `lockout_expire_gmt` > '" . date( 'Y-m-d H:i:s', $itsec_globals['current_time_gmt'] ) . "'";

		} else {

			$active = '';

		}

		$results = $wpdb->get_results( 'SELECT * FROM `' . $wpdb->base_prefix . 'itsec_lockouts`' . $where . $type_statement . $active . ';', ARRAY_A );
		$output  = array();
		if ( is_array( $results ) && count( $results ) > 0 ) {
			switch ( $type ) {
				case 'host':
					foreach ( $results as $val ) {
						$output[] = array(
							'lockout_id'         => $val['lockout_id'],
							'lockout_host'       => $val['lockout_host'],
							'lockout_expire_gmt' => $val['lockout_expire_gmt'],
						);
					}
					break;
				case 'user':
					foreach ( $results as $val ) {
						$output[] = array(
							'lockout_id'         => $val['lockout_id'],
							'lockout_user'       => $val['lockout_user'],
							'lockout_expire_gmt' => $val['lockout_expire_gmt'],
						);
					}
					break;
				case 'username':
					foreach ( $results as $val ) {
						$output[] = array(
							'lockout_id'         => $val['lockout_id'],
							'lockout_username'   => $val['lockout_username'],
							'lockout_expire_gmt' => $val['lockout_expire_gmt'],
						);
					}
					break;
				default:
					break;
			}
		}

		return $output;
	}

	public function release_lockout() {
		global $wpdb;

		if ( ! class_exists( 'ITSEC_Lib' ) ) {
			require(  ITSEC_Core::get_core_dir() . '/core/class-itsec-lib.php' );
		}

		$lockout_ids = $_POST['lockout_ids'];
		if ( ! is_array( $lockout_ids ) ) {
			$lockout_ids = array();
		}

		$type    = 'updated';
		$message = __( 'The selected lockouts have been cleared.', 'better-wp-security' );

		foreach ( $lockout_ids as $value ) {
			$wpdb->update(
				$wpdb->base_prefix . 'itsec_lockouts',
				array(
					'lockout_active' => 0,
				),
				array(
					'lockout_id' => intval( $value ),
				)
			);
		}

		ITSEC_Lib::clear_caches();

		if ( ! is_multisite() ) {
			if ( ! function_exists( 'add_settings_error' ) ) {
				require_once( ABSPATH . '/wp-admin/includes/template.php' );
			}

			add_settings_error( 'itsec', esc_attr( 'settings_updated' ), $message, $type );
		}

		return array(
			'result'      => 'success',
		);
	}

	public function update_module_status() {

		$active_modules = $_POST['active_modules'];

		if (!is_array($active_modules))
			$active_modules = array();

        $current_val = get_site_option( 'itsec_active_modules', array() );
        foreach ($active_modules as $mod => $val) {
            $current_val[$mod] = $val;
        }

		update_site_option( 'itsec_active_modules', $current_val );
		return array('result' => 'success');

	}

	private function reload_backup_exclude(  ) {
		return array(
			'exclude' => ITSEC_Modules::get_setting( 'backup', 'exclude' ),
			'excludable_tables' => $this->get_excludable_tables(),
			'result' => 'success'
		);
	}

	private function get_excludable_tables(  ) {
		global $wpdb;
		$all_sites = ITSEC_Modules::get_setting( 'backup', 'all_sites' );
		$ignored_tables = array(
			'commentmeta',
			'comments',
			'links',
			'options',
			'postmeta',
			'posts',
			'term_relationships',
			'term_taxonomy',
			'terms',
			'usermeta',
			'users',
		);

		if ( $all_sites ) {
			$query = 'SHOW TABLES';
		} else {
			$query = $wpdb->prepare( 'SHOW TABLES LIKE %s', "{$wpdb->base_prefix}%" );
		}

		$tables = $wpdb->get_results( $query, ARRAY_N );
		$excludes = array();

		foreach ( $tables as $table ) {
			$short_table = substr( $table[0], strlen( $wpdb->prefix ) );

			if ( in_array( $short_table, $ignored_tables ) ) {
				continue;
			}

			$excludes[$short_table] = $table[0];
		}

		return $excludes ;
	}

    private function security_site() {
		global $mainwp_itsec_modules_path;
		require_once(  $mainwp_itsec_modules_path . 'security-check/scanner.php' );
        require_once(  $mainwp_itsec_modules_path . 'security-check/feedback-renderer.php' );
        $results = ITSEC_Security_Check_Scanner::get_results();
        ob_start();
        ITSEC_Security_Check_Feedback_Renderer::render( $results );
		$response = ob_get_clean();
		return array('result' => 'success' , 'response' => $response);
	}

    // source from itheme plugin
    // ok
    public function get_available_admin_users_and_roles() {
		if ( is_callable( 'wp_roles' ) ) {
			$roles = wp_roles();
		} else {
			$roles = new WP_Roles();
		}

		$available_roles = array();
		$available_users = array();

		foreach ( $roles->roles as $role => $details ) {
			if ( isset( $details['capabilities']['manage_options'] ) && ( true === $details['capabilities']['manage_options'] ) ) {
				$available_roles["role:$role"] = translate_user_role( $details['name'] );

				$users = get_users( array( 'role' => $role ) );

				foreach ( $users as $user ) {
					/* translators: 1: user display name, 2: user login */
					$available_users[ $user->ID ] = sprintf( __( '%1$s (%2$s)', 'better-wp-security' ), $user->display_name, $user->user_login );
				}
			}
		}

		natcasesort( $available_users );

		return array(
			'users' => $available_users,
			'roles' => $available_roles,
		);
	}

}

