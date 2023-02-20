<?php
/**
 * MainWP Ithemes Security
 *
 * MainWP iThemes Security Extension handler.
 * Extension URL: https://mainwp.com/extension/ithemes-security/
 *
 * @package MainWP\Child
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
 */

namespace MainWP\Child;

// phpcs:disable -- third party credit code.


/**
 * Class MainWP_Child_IThemes_Security
 */
class MainWP_Child_IThemes_Security {

    /**
     * Public static variable to hold the single instance of MainWP_Child_IThemes_Security.
     * @var null
     */
    public static $instance = null;

    /**
     * @var bool Whether or not iThemes Plugin is installed or not. Default: false.
     */
    public $is_plugin_installed = false;

    /**
     * Create a public static instance of MainWP_Child_IThemes_Security.
     *
     * @return MainWP_Child_IThemes_Security|null
     */
    public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

    /**
     * MainWP_Child_IThemes_Security constructor.
     *
     * Run any time class is called.
     *
     * @uses MainWP_Child_IThemes_Security::is_plugin_installed()
     */
    public function __construct() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		if ( is_plugin_active( 'better-wp-security/better-wp-security.php' ) || is_plugin_active( 'ithemes-security-pro/ithemes-security-pro.php' ) ) {
			$this->is_plugin_installed = true;
		}

		if ( ! $this->is_plugin_installed ) {
			return;
		}

		add_filter( 'mainwp_site_sync_others_data', array( $this, 'sync_others_data' ), 10, 2 );
	}

    /**
     * Sync other data from $data[] and merge with $information[]
     *
     * @param array $information Returned response array for MainWP BackWPup Extension actions.
     * @param array $data Other data to sync to $information array.
     * @return array $information Returned information array with both sets of data.
     */
    public function sync_others_data( $information, $data = array() ) {
		if ( is_array( $data ) && isset( $data['ithemeExtActivated'] ) && ( 'yes' === $data['ithemeExtActivated'] ) ) {
			try {
				$information['syncIThemeData'] = array(
					'users_and_roles' => $this->get_available_admin_users_and_roles(),
				);
				
				global $itsec_lockout;

				if ( $itsec_lockout ) {
					$lockout_query = array(
						'limit'   => 100,
						'current' => true,
						'order'   => 'DESC',
						'orderby' => 'lockout_start',
					);
					$lockouts = $itsec_lockout->get_lockouts( 'all', $lockout_query );
					if ( $lockouts ) {
						$information['syncIThemeData']['lockout_count'] = count( $lockouts );
					}
				}


				$request = new \WP_REST_Request( 'GET', '/ithemes-security/v1/site-scanner/scans' );
				
				$range1 = \ITSEC_Core::get_current_time_gmt();
				$range0 = strtotime( '-30 days', $range1 );
				
				
				$request->set_query_params( [
					'after'  => \ITSEC_Lib::to_rest_date( $range0 ),
					'before' => \ITSEC_Lib::to_rest_date( $range1 ),
				] );
		
				$response = rest_do_request( $request );
				$scans = rest_get_server()->response_to_data( $response, true );
				
				if ( is_array( $scans ) && count( $scans ) > 0 ) {
					$scan = current( $scans );
					$information['syncIThemeData']['scan_info'] = array(
						'time' => $scan['time'],
						'description' => $scan['description'],
						'status' => $scan['status'],						
					);
				}

				if ( class_exists( '\iThemesSecurity\Ban_Users\Database_Repository' ) ) {
					$repository = \ITSEC_Modules::get_container()->get( \iThemesSecurity\Ban_Users\Database_Repository::class );
					$information['syncIThemeData']['count_bans'] = $repository->count_bans( new \iThemesSecurity\Ban_Hosts\Filters() );
				}

				$information['syncIThemeData']['lockouts_host'] = $this->get_lockouts( 'host', true );
				$information['syncIThemeData']['lockouts_user'] = $this->get_lockouts( 'user', true );
				$information['syncIThemeData']['lockouts_username'] = $this->get_lockouts( 'username', true );


			} catch ( \Exception $e ) {
				error_log( $e->getMessage() ); // phpcs:ignore -- debug mode only.
			}
		}
		return $information;
	}

    /**
     * MainWP iThemes Security Extension actions.
     *
     * @uses \MainWP\Child\MainWP_Child_IThemes_Security::set_showhide()
     * @uses \MainWP\Child\MainWP_Child_IThemes_Security::save_settings()
     * @uses \MainWP\Child\MainWP_Child_IThemes_Security::whitelist_release()
     * @uses \MainWP\Child\MainWP_Child_IThemes_Security::backup_db()
     * @uses \MainWP\Child\MainWP_Child_IThemes_Security::admin_user()
     * @uses \MainWP\Child\MainWP_Child_IThemes_Security::change_database_prefix()
     * @uses \MainWP\Child\MainWP_Child_IThemes_Security::reset_api_key()
     * @uses \MainWP\Child\MainWP_Child_IThemes_Security::malware_scan()
     * @uses \MainWP\Child\MainWP_Child_IThemes_Security::>purge_logs()
     * @uses \MainWP\Child\MainWP_Child_IThemes_Security::file_change()
     * @uses \MainWP\Child\MainWP_Child_IThemes_Security::release_lockout()
     * @uses \MainWP\Child\MainWP_Child_IThemes_Security::update_module_status()
     * @uses \MainWP\Child\MainWP_Child_IThemes_Security::wordpress_salts()
     * @uses \MainWP\Child\MainWP_Child_IThemes_Security::file_permissions()
     * @uses \MainWP\Child\MainWP_Child_IThemes_Security::reload_backup_exclude()
     * @uses \MainWP\Child\MainWP_Child_IThemes_Security::security_site()
     * @uses \MainWP\Child\MainWP_Child_IThemes_Security::activate_network_brute_force()
     * @uses \MainWP\Child\MainWP_Helper::write()
     */
    public function action() {
		$information = array();
		if ( ! class_exists( '\ITSEC_Core' ) || ! class_exists( '\ITSEC_Modules' ) ) {
			$information['error'] = 'NO_ITHEME';
			MainWP_Helper::write( $information );
		}

	    /** @global string $mainwp_itsec_modules_path MainWP itsec modules path.  */
		global $mainwp_itsec_modules_path;

		$mainwp_itsec_modules_path = \ITSEC_Core::get_core_dir() . '/modules/';

		if ( isset( $_POST['mwp_action'] ) ) {
			$mwp_action = ! empty( $_POST['mwp_action'] ) ? sanitize_text_field( wp_unslash( $_POST['mwp_action'] ) ) : '';
			switch ( $mwp_action ) {
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

	
    /**
     * Set show or hide UpdraftPlus Plugin from Admin & plugins list.
     *
     * @return array $information Return results.
     *
     * @uses \MainWP\Child\MainWP_Helper::update_option()
     */
    public function set_showhide() {
		$hide = isset( $_POST['showhide'] ) && ( 'hide' === $_POST['showhide'] ) ? 'hide' : '';
		MainWP_Helper::update_option( 'mainwp_ithemes_hide_plugin', $hide );
		$information['result'] = 'success';

		return $information;
	}

    /**
     * Initiate iThemes settings.
     *
     * @uses MainWP_Child_IThemes_Security::is_plugin_installed()
     */
    public function ithemes_init() {
		if ( ! $this->is_plugin_installed ) {
			return;
		}

		if ( 'hide' === get_option( 'mainwp_ithemes_hide_plugin' ) ) {
			add_filter( 'all_plugins', array( $this, 'all_plugins' ) );
			add_action( 'admin_menu', array( $this, 'remove_menu' ) );
			add_action( 'admin_init', array( $this, 'admin_init' ) );
			add_action( 'admin_head', array( &$this, 'custom_admin_css' ) );
			if ( isset( $_GET['page'] ) && ( 'itsec' == $_GET['page'] || 'itsec-security-check' == $_GET['page'] ) ) {
				wp_safe_redirect( get_option( 'siteurl' ) . '/wp-admin/index.php' );
				exit();
			}
		}
	}

    /**
     * iThemes Security Admin initiation.
     */
    public function admin_init() {
		remove_meta_box( 'itsec-dashboard-widget', 'dashboard', 'normal' );
	}

    /**
     * Remove iThemes Security from plugins page.
     *
     * @param array $plugins All plugins array.
     *
     * @return array $plugins All plugins array with iThemes Security removed.
     */
    public function all_plugins( $plugins ) {
		foreach ( $plugins as $key => $value ) {
			$plugin_slug = basename( $key, '.php' );
			if ( 'better-wp-security' === $plugin_slug || 'ithemes-security-pro' === $plugin_slug ) {
				unset( $plugins[ $key ] );
			}
		}

		return $plugins;
	}

    /**
     *  Remove iThemes Security plugin from WP Admin menu.
     */
    public function remove_menu() {
		remove_menu_page( 'itsec' );
	}

    /**
     * Custom admin CSS.
     */
    public function custom_admin_css() {
		?>
		<style type="text/css">
			#wp-admin-bar-itsec_admin_bar_menu{
				display: none !important;
			}
		</style>
		<?php
	}

    /**
     * Save UpdraftPlus settings.
     *
     * @return array[] $return Return Error message or Success Message.
     *
     * @uses \ITSEC_Lib::get_server()
     * @uses \ITSEC_Lib::get_ssl_support_probability()
     * @uses \ITSEC_Lib_Config_File::get_server_config()
     * @uses \ITSEC_Lib_Config_File::get_wp_config()
     * @uses \ITSEC_Modules::get_default()
     * @uses \ITSEC_Modules::get_setting()
     * @uses MainWP_Child_IThemes_Security::get_lockouts()
     * @uses MainWP_Child_IThemes_Security::validate_directory()
     * @uses MainWP_Child_IThemes_Security::activate_api_key()
     * @uses MainWP_Child_IThemes_Security::get_excludable_tables()
     * @uses MainWP_Child_IThemes_Security::get_available_admin_users_and_roles()
     */
    public function save_settings() {

		if ( ! class_exists( '\ITSEC_Lib' ) ) {
			require \ITSEC_Core::get_core_dir() . '/core/class-itsec-lib.php';
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
			'password-requirements',
			'system-tweaks',
			'wordpress-tweaks',
			'multisite-tweaks',
			'notification-center',
			'two-factor',
		);

		$require_permalinks = false;
		$updated            = false;
		$errors             = array();
		$nbf_settings       = array();

		$update_settings = isset( $_POST['settings'] ) ? json_decode( base64_decode( wp_unslash( $_POST['settings'] ) ), true ) : ''; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..

		foreach ( $update_settings as $module => $settings ) {
			$do_not_save = false;
			$current_settings = \ITSEC_Modules::get_settings( $module );
			if ( in_array( $module, $_itsec_modules ) ) {
				if ( 'wordpress-salts' == $module ) {
					$settings['last_generated'] = \ITSEC_Modules::get_setting( $module, 'last_generated' );
				} elseif ( 'global' == $module ) {
					$keep_olds = array( 'did_upgrade', 'log_info', 'show_new_dashboard_notice', 'show_security_check', 'nginx_file', 'manage_group' );
					foreach ( $keep_olds as $key ) {
						$settings[ $key ] = \ITSEC_Modules::get_setting( $module, $key );
					}

					if ( ! isset( $settings['log_location'] ) || empty( $settings['log_location'] ) ) {
						$settings['log_location'] = \ITSEC_Modules::get_setting( $module, 'log_location' );
					} else {
						$result = $this->validate_directory( 'log_location', $settings['log_location'] );
						if ( true !== $result ) {
							$errors[]                 = $result;
							$settings['log_location'] = \ITSEC_Modules::get_setting( $module, 'log_location' );
						}
					}
				} elseif ( 'backup' == $module ) {
					if ( ! isset( $settings['location'] ) || empty( $settings['location'] ) ) {
						$settings['location'] = \ITSEC_Modules::get_setting( $module, 'location' );
					} else {
						$result = $this->validate_directory( 'location', $settings['location'] );
						if ( true !== $result ) {
							$errors[]             = $result;
							$settings['location'] = \ITSEC_Modules::get_setting( $module, 'location' );
						}
					}
					if ( ! isset( $settings['exclude'] ) ) {
						$settings['exclude'] = \ITSEC_Modules::get_setting( $module, 'exclude' );

					}
				} elseif ( 'hide-backend' == $module ) {
					if ( isset( $settings['enabled'] ) && ! empty( $settings['enabled'] ) ) {
						$permalink_structure = get_option( 'permalink_structure', false );
						if ( empty( $permalink_structure ) && ! is_multisite() ) {
							$errors[]           = esc_html__( 'You must change <strong>WordPress permalinks</strong> to a setting other than "Plain" in order to use "Hide Backend" feature.', 'mainwp-child' );
							$require_permalinks = true;
							$do_not_save        = true;
						}
					}
				} elseif ( 'network-brute-force' == $module ) {

					if ( isset( $settings['email'] ) ) {
						$result = $this->activate_api_key( $settings );
						if ( false === $result ) {
							$nbf_settings = $settings;
							$errors[]     = 'Error: Active iThemes Network Brute Force Protection Api Key';
						} else {
							$nbf_settings = $result;
						}
					} else {
						$previous_settings = \ITSEC_Modules::get_settings( $module );
						if ( isset( $settings['enable_ban'] ) ) {
							$previous_settings['enable_ban'] = $settings['enable_ban'];
							$nbf_settings                    = $previous_settings;
						} else {
							$do_not_save  = true;
							$nbf_settings = $previous_settings;
						}
					}
					$settings = $nbf_settings;
				} elseif ( 'notification-center' == $module ) {
					$current_settings = \ITSEC_Modules::get_settings( $module );
					if ( isset( $settings['notifications'] ) ) {
						$update_fields = array( 'schedule', 'enabled', 'subject' );
						if ( isset( $_POST['is_individual'] ) && $_POST['is_individual'] ) {
							$update_fields = array_merge( $update_fields, array( 'user_list', 'email_list' ) );
						}
						foreach ( $settings['notifications'] as $key => $val ) {
							foreach ( $update_fields as $field ) {
								if ( isset( $val[ $field ] ) ) {
									$current_settings['notifications'][ $key ][ $field ] = $val[ $field ];
								}
							}
						}
						$updated = true;
						\ITSEC_Modules::set_settings( $module, $current_settings );
					}
					continue;
				}

				if ( ! $do_not_save ) {
					foreach ( $settings as $key => $val ) {						
						$current_settings[$key] = $val;
					}
					\ITSEC_Modules::set_settings( $module, $current_settings );
					$updated = true;
				}
			}
		}

		if ( isset( $update_settings['itsec_active_modules'] ) ) {
			$current_val = get_site_option( 'itsec_active_modules', array() );
			foreach ( $update_settings['itsec_active_modules'] as $mod => $val ) {
				$current_val[ $mod ] = $val;
			}
			update_site_option( 'itsec_active_modules', $current_val );
		}

		require_once \ITSEC_Core::get_core_dir() . '/lib/class-itsec-lib-config-file.php';

		$values = array(
			'permalink_structure'   => get_option( 'permalink_structure' ),
			'is_multisite'          => is_multisite() ? 1 : 0,
			'users_can_register'    => get_site_option( 'users_can_register' ) ? 1 : 0,
			'server_nginx'          => ( \ITSEC_Lib::get_server() === 'nginx' ) ? 1 : 0,
			'has_ssl'               => \ITSEC_Lib::get_ssl_support_probability(),
			'jquery_version'        => \ITSEC_Modules::get_setting( 'wordpress-tweaks', 'jquery_version' ),
			'server_rules'          => \ITSEC_Lib_Config_File::get_server_config(),
			'config_rules'          => \ITSEC_Lib_Config_File::get_wp_config(),
			'default_log_location'  => \ITSEC_Modules::get_default( 'global', 'log_location' ),
			'default_location'      => \ITSEC_Modules::get_default( 'backup', 'location' ),
			'excludable_tables'     => $this->get_excludable_tables(),
			'users_and_roles'       => $this->get_available_admin_users_and_roles(),
		);

		$return = array(
			'site_status' => $values,
		);

		if ( $require_permalinks ) {
			$return['require_permalinks'] = 1;
		}

		$return['nbf_settings'] = $nbf_settings;

		if ( ! empty( $errors ) ) {
			$return['extra_message'] = $errors;
		}

		if ( $updated ) {
			$return['result'] = 'success';
		} else {
			$return['error'] = esc_html__( 'Not Updated', 'mainwp-child' );
		}

		return $return;
	}

    /**
     * Activate network brute force.
     *
     * @return array $information Results array.
     *
     * @uses \ITSEC_Modules::get_settings()
     * @uses \ITSEC_Modules::activate()
     */
    public static function activate_network_brute_force() {
		$data        = isset( $_POST['data'] ) ? json_decode( base64_decode( wp_unslash( $_POST['data'] ) ), true ) : array(); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
		$information = array();
		if ( is_array( $data ) ) {
			$settings                  = \ITSEC_Modules::get_settings( 'network-brute-force' );
			$settings['email']         = $data['email'];
			$settings['updates_optin'] = $data['updates_optin'];
			$settings['api_nag']       = false;
			$results                   = \ITSEC_Modules::set_settings( 'network-brute-force', $settings );
			if ( is_wp_error( $results ) ) {
				$information['error'] = 'Error: Active iThemes Network Brute Force Protection Api Key';
			} elseif ( $results['saved'] ) {
				\ITSEC_Modules::activate( 'network-brute-force' );
				$nbf_settings = \ITSEC_Modules::get_settings( 'network-brute-force' );
			}
		}
		if ( null !== $nbf_settings ) {
			$information['nbf_settings'] = $nbf_settings;
			$information['result']       = 'success';
		}
		return $information;
	}

    /**
     * Validate directory.
     *
     * @param string $name Input name.
     * @param string $folder Folder.
     *
     * @return bool|string Return TRUE on success or Error message on failure.
     *
     * @uses \ITSEC_Lib_Directory::is_dir()
     * @uses \ITSEC_Lib_Directory::create()
     * @uses \ITSEC_Lib_Directory::is_writable()
     * @uses \ITSEC_Lib_Directory::add_file_listing_protection()
     */
    private function validate_directory( $name, $folder ) {
		require_once \ITSEC_Core::get_core_dir() . 'lib/class-itsec-lib-directory.php';
		$error = null;
		if ( ! \ITSEC_Lib_Directory::is_dir( $folder ) ) {
			$result = \ITSEC_Lib_Directory::create( $folder );

			if ( is_wp_error( $result ) ) {
				$error = sprintf( _x( 'The directory supplied in %1$s cannot be used as a valid directory. %2$s', '%1$s is the input name. %2$s is the error message.', 'mainwp-child' ), $name, $result->get_error_message() );
			}
		}

		if ( empty( $error ) && ! \ITSEC_Lib_Directory::is_writable( $folder ) ) {
			$error = sprintf( esc_html__( 'The directory supplied in %1$s is not writable. Please select a directory that can be written to.', 'mainwp-child' ), $name );
		}

		if ( empty( $error ) ) {
			\ITSEC_Lib_Directory::add_file_listing_protection( $folder );
			return true;
		} else {
			return $error;
		}
	}

    /**
     * Activate api key.
     *
     * @param array $settings Setting array.
     *
     * @return array|bool Return $settings array or FALSE on failure.
     *
     * @uses \ITSEC_Network_Brute_Force_Utilities::get_api_key()
     * @uses \ITSEC_Network_Brute_Force_Utilities::activate_api_key()
     * @uses \ITSEC_Response::reload_module()
     */
    private function activate_api_key( $settings ) {

        /** @global string $mainwp_itsec_modules_path MainWP itsec modules path.  */
		global $mainwp_itsec_modules_path;

		if ( file_exists( $mainwp_itsec_modules_path . 'network-brute-force/utilities.php' ) ) {
			require_once $mainwp_itsec_modules_path . 'network-brute-force/utilities.php';
		} else if ( file_exists( $mainwp_itsec_modules_path . 'ipcheck/utilities.php' ) ) {
			require_once $mainwp_itsec_modules_path . 'ipcheck/utilities.php';
		}

		$key = \ITSEC_Network_Brute_Force_Utilities::get_api_key( $settings['email'], $settings['updates_optin'] );
		if ( is_wp_error( $key ) ) {
			return false;
		} else {
			$secret = \ITSEC_Network_Brute_Force_Utilities::activate_api_key( $key );

			if ( is_wp_error( $secret ) ) {
				return false;
			} else {
				$settings['api_key']    = $key;
				$settings['api_secret'] = $secret;

				$settings['api_nag'] = false;

				\ITSEC_Response::reload_module( 'network-brute-force' );
			}
		}
		unset( $settings['email'] );
		return $settings;
	}

    /**
     * Backup status.
     *
     * @return int $status 1, 2, 3 or 4
     *  (1) Is not a multisite installation, backupbuddy_api exists & Scheduled backups are >=1
     *  (2) Is not multisite and backupbuddy_api exists
     *  (3) Has backup = true & schedualed backup = true
     *  (4) Has backup = true.
     *
     * @uses \backupbuddy_api::getSchedules()
     * @uses MainWP_Child_IThemes_Security::has_backup()
     * @uses MainWP_Child_IThemes_Security::scheduled_backup()
     * @uses MainWP_Child_IThemes_Security::has_backup()
     *
     */
    public function backup_status() {
		$status = 0;
		if ( ! is_multisite() && class_exists( '\backupbuddy_api' ) && count( \backupbuddy_api::getSchedules() ) >= 1 ) {
			$status = 1;
		} elseif ( ! is_multisite() && class_exists( '\backupbuddy_api' ) ) {
			$status = 2;
		} elseif ( $this->has_backup() === true && $this->scheduled_backup() === true ) {
			$status = 3;
		} elseif ( $this->has_backup() === true ) {
			$status = 4;
		}

		return $status;
	}

    /**
     * Check if backup exists.
     *
     * @return bool TRUE|FALSE
     */
    public function has_backup() {
		$has_backup = false;

		return apply_filters( 'itsec_has_external_backup', $has_backup );
	}

    /**
     * Check if there is a shedualed backup.
     *
     * @return bool TRUE|FALSE.
     */
    public function scheduled_backup() {
		$sceduled_backup = false;

		return apply_filters( 'itsec_scheduled_external_backup', $sceduled_backup );
	}

    /**
     * Whitelist Dashboard IP address.
     *
     * @return array|string[] Response array.
     */
    public function whitelist() {

        /** @global array $itsec_globals itsec globals. */
		global $itsec_globals;

		$ip       = isset( $_POST['ip'] ) ? sanitize_text_field( wp_unslash( $_POST['ip'] ) ) : '';
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
			$response['message1'] = esc_html__( 'Your IP Address', 'mainwp-child' );
			$response['message2'] = esc_html__( 'is whitelisted for', 'mainwp-child' );

			return $response;
		}
	}

    /**
     * Whitelist release.
     *
     * @return string Return 'Success'.
     */
    public function whitelist_release() {
		delete_site_option( 'itsec_temp_whitelist_ip' );

		return 'success';
	}

    /**
     * Backup Database.
     *
     * @return array $return Return results array.
     *
     * @uses \ITSEC_Backup()
     * @uses \ITSEC_Backup::run()
     * @uses \ITSEC_Backup::do_backup()
     * @uses \ITSEC_Response::get_error_strings()
     */
    public function backup_db() {

        /**
         * @global string $mainwp_itsec_modules_path MainWP itsec modules path.
         * @global object $itsec_backup              ITsec backup class.
         */
		global $itsec_backup, $mainwp_itsec_modules_path;

		if ( ! isset( $itsec_backup ) ) {
			require_once $mainwp_itsec_modules_path . 'backup/class-itsec-backup.php';
			$itsec_backup = new \ITSEC_Backup();
			$itsec_backup->run();
		}

		$return = array();

		$str_error = '';
		$result    = $itsec_backup->do_backup( true );

		if ( is_wp_error( $result ) ) {
			$errors = \ITSEC_Response::get_error_strings( $result );

			foreach ( $errors as $error ) {
				$str_error .= $error . '<br />';
			}
		} elseif ( is_string( $result ) ) {
			$return['result']  = 'success';
			$return['message'] = $result;
		} else {
			$str_error = sprintf( esc_html__( 'The backup request returned an unexpected response. It returned a response of type <code>%1$s</code>.', 'mainwp-child' ), gettype( $result ) );
		}

		if ( ! empty( $str_error ) ) {
			$return['error'] = $str_error;
		}

		return $return;
	}


    /**
     * Update WordPress Salts.
     *
     * @return array $return Return results array.
     *
     * @uses \ITSEC_WordPress_Salts_Utilities::generate_new_salts()
     * @uses \ITSEC_Response::get_error_strings()
     * @uses \ITSEC_Core::get_current_time_gmt()
     * @uses \ITSEC_Modules::set_setting()
     */
    private function wordpress_salts() {

        /** @global string $mainwp_itsec_modules_path MainWP itsec modules path. */
		global $mainwp_itsec_modules_path;

		if ( ! class_exists( '\ITSEC_WordPress_Salts_Utilities' ) ) {
			require $mainwp_itsec_modules_path . 'salts/utilities.php';
		}
		$result    = \ITSEC_WordPress_Salts_Utilities::generate_new_salts();
		$str_error = '';
		if ( is_wp_error( $result ) ) {
			$errors = \ITSEC_Response::get_error_strings( $result );

			foreach ( $errors as $error ) {
				$str_error .= $error . '<br />';
			}
		} else {
			$return['result']  = 'success';
			$return['message'] = esc_html__( 'The WordPress salts were successfully regenerated.', 'mainwp-child' );
			$last_generated    = \ITSEC_Core::get_current_time_gmt();
			\ITSEC_Modules::set_setting( 'wordpress-salts', 'last_generated', $last_generated );
		}
		if ( ! empty( $str_error ) ) {
			$return['error'] = $str_error;
		}
		return $return;
	}

    /**
     * Update file permissions.
     *
     * @return array Return results table html.
     *
     * @uses \ITSEC_Core::get_wp_upload_dir()
     * @uses \ITSEC_Lib_Config_File::get_wp_config_file_path()
     * @uses \ITSEC_Lib_Config_File::get_server_config_file_path()
     */
    private function file_permissions() {

			require_once \ITSEC_Core::get_core_dir() . '/lib/class-itsec-lib-config-file.php';

			$wp_upload_dir = \ITSEC_Core::get_wp_upload_dir();

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
					0755,
				),
				array(
					$wp_upload_dir['basedir'],
					0755,
				),
				array(
					\ITSEC_Lib_Config_File::get_wp_config_file_path(),
					0444,
				),
				array(
					\ITSEC_Lib_Config_File::get_server_config_file_path(),
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
				$row[]       = sprintf( '%o', $permissions );

				if ( ! $permissions || $permissions != $suggested_permissions ) {
					$row[] = esc_html__( 'WARNING', 'mainwp-child' );
					$row[] = '<div style="background-color: #FEFF7F; border: 1px solid #E2E2E2;">&nbsp;&nbsp;&nbsp;</div>';
				} else {
					$row[] = esc_html__( 'OK', 'mainwp-child' );
					$row[] = '<div style="background-color: #22EE5B; border: 1px solid #E2E2E2;">&nbsp;&nbsp;&nbsp;</div>';
				}

				$rows[] = $row;
			}

			$class = 'entry-row';
			ob_start();
			?>
		<p><input type="button" id="itsec-file-permissions-reload_file_permissions" name="file-permissions[reload_file_permissions]" class="button-primary itsec-reload-module" value="<?php esc_attr_e( 'Reload File Permissions Details', 'mainwp-child' ); ?>"></p>
		<table class="widefat">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Relative Path', 'mainwp-child' ); ?></th>
					<th><?php esc_html_e( 'Suggestion', 'mainwp-child' ); ?></th>
					<th><?php esc_html_e( 'Value', 'mainwp-child' ); ?></th>
					<th><?php esc_html_e( 'Result', 'mainwp-child' ); ?></th>
					<th><?php esc_html_e( 'Status', 'mainwp-child' ); ?></th>
				</tr>
			</thead>
			<tfoot>
				<tr>
					<th><?php esc_html_e( 'Relative Path', 'mainwp-child' ); ?></th>
					<th><?php esc_html_e( 'Suggestion', 'mainwp-child' ); ?></th>
					<th><?php esc_html_e( 'Value', 'mainwp-child' ); ?></th>
					<th><?php esc_html_e( 'Result', 'mainwp-child' ); ?></th>
					<th><?php esc_html_e( 'Status', 'mainwp-child' ); ?></th>
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
		return array( 'html' => $html );
	}

    /**
     * Run File Change scanner.
     *
     * @return array $return results array.
     *
     * @uses \ITSEC_File_Change_Scanner::run_scan()
     */
    public function file_change() {

        /** @global string $mainwp_itsec_modules_path MainWP itsec modules path. */
		global $mainwp_itsec_modules_path;

		if ( ! class_exists( '\ITSEC_File_Change_Scanner' ) ) {
			require_once $mainwp_itsec_modules_path . 'file-change/scanner.php';
		}
		
		$results = \ITSEC_File_Change_Scanner::schedule_start();

		if ( is_wp_error( $results ) ) {
			$error = $results->get_error_message();
			$return['result']      = 'failed';
			$return['scan_error'] = $error;
		} else {
			$return['result']      = 'success';
			$return['scan_result'] = $results;
		}
		return $return;
	}

    /**
     * Update admin user.
     *
     * @return array Return Success or Fail.
     *
     * @uses \ITSEC_Lib::user_id_exists()
     * @uses MainWP_Child_IThemes_Security::change_admin_user()
     */
    public function admin_user() {

		$settings = isset( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array();

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$new_username = isset( $settings['new_username'] ) ? $settings['new_username'] : '';
		$change_id    = isset( $settings['change_id'] ) && $settings['change_id'] ? true : false;

		if ( ! class_exists( '\ITSEC_Lib' ) ) {

            /** @global object $itsec_globals ITsec globals. */
			global $itsec_globals;

			require \ITSEC_Core::get_core_dir() . '/core/class-itsec-lib.php';
		}

		$username_exists = username_exists( 'admin' );
		$user_id_exists  = \ITSEC_Lib::user_id_exists( 1 );
		$msg             = '';
		if ( strlen( $new_username ) >= 1 ) {

		    /** @global string $current_user Current user global variable. */
			global $current_user;

			if ( ! $username_exists ) {
				$msg = esc_html__( 'Admin user already changes.', 'mainwp-child' );
			} elseif ( 'admin' == $current_user->user_login ) {
				$return['result'] = 'CHILD_ADMIN';
				return $return;
			}
		}

		if ( true === $change_id && ! $user_id_exists ) {
			if ( ! empty( $msg ) ) {
				$msg .= '<br/>';
			}
			$msg .= esc_html__( 'Admin user ID already changes.', 'mainwp-child' );
		}

		$admin_success = true;
		$return        = array();

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

    /**
     * Change admin user.
     *
     * @param string $username Username to update to. Default: null.
     * @param bool $id User Id found. Default: false.
     * @return bool Return TRUE on success and FALSE on failure.
     *
     * @uses \ITSEC_Core::get_itsec_files()
     * @uses \ITSEC_Core::get_itsec_files::release_file_lock()
     */
    private function change_admin_user($username = null, $id = false ) {

        /** @global object $wpdb WordPress Database  */
		global $wpdb;

		$itsec_files = \ITSEC_Core::get_itsec_files();

			$new_user = sanitize_text_field( $username );

			$user_object = get_user_by( 'id', '1' );

		if ( null !== $username && validate_username( $new_user ) && false === username_exists( $new_user ) ) {

			if ( true === $id ) {

				$user_login = $new_user;

			} else {

				$wpdb->query( 'UPDATE `' . $wpdb->users . "` SET user_login = '" . esc_sql( $new_user ) . "' WHERE user_login='admin';" );

				if ( is_multisite() ) {
					$oldAdmins = $wpdb->get_var( 'SELECT meta_value FROM `' . $wpdb->sitemeta . "` WHERE meta_key = 'site_admins'" );
					$newAdmins = str_replace( '5:"admin"', strlen( $new_user ) . ':"' . esc_sql( $new_user ) . '"', $oldAdmins );
					$wpdb->query( $wpdb->prepare( 'UPDATE `' . $wpdb->sitemeta . "` SET meta_value = %s WHERE meta_key = 'site_admins'", $newAdmins ) );
				}

				wp_clear_auth_cookie();
				$itsec_files->release_file_lock( 'admin_user' );

				return true;

			}
		} elseif ( null !== $username ) {

			$itsec_files->release_file_lock( 'admin_user' );

			return false;

		} else {

			$user_login = $user_object->user_login;

		}

		if ( true === $id ) {

			$wpdb->query( 'DELETE FROM `' . $wpdb->users . '` WHERE ID = 1;' );

			$wpdb->insert(
				$wpdb->users,
				array(
					'user_login'          => $user_login,
					'user_pass'           => $user_object->user_pass,
					'user_nicename'       => $user_object->user_nicename,
					'user_email'          => $user_object->user_email,
					'user_url'            => $user_object->user_url,
					'user_registered'     => $user_object->user_registered,
					'user_activation_key' => $user_object->user_activation_key,
					'user_status'         => $user_object->user_status,
					'display_name'        => $user_object->display_name,
				)
			);

			if ( is_multisite() && null !== $username && validate_username( $new_user ) ) {

				$oldAdmins = $wpdb->get_var( 'SELECT meta_value FROM `' . $wpdb->sitemeta . "` WHERE meta_key = 'site_admins'" );
				$newAdmins = str_replace( '5:"admin"', strlen( $new_user ) . ':"' . esc_sql( $new_user ) . '"', $oldAdmins );
				$wpdb->query( 'UPDATE `' . $wpdb->sitemeta . "` SET meta_value = '" . esc_sql( $newAdmins ) . "' WHERE meta_key = 'site_admins'" );

			}

			$new_user = $wpdb->insert_id;

			$wpdb->query( $wpdb->prepare( 'UPDATE `' . $wpdb->posts . '` SET post_author = %s WHERE post_author = 1;', $new_user ) );
			$wpdb->query( $wpdb->prepare( 'UPDATE `' . $wpdb->usermeta . '` SET user_id = %s WHERE user_id = 1;', $new_user ) );
			$wpdb->query( $wpdb->prepare( 'UPDATE `' . $wpdb->comments . '` SET user_id = %s WHERE user_id = 1;', $new_user ) );
			$wpdb->query( $wpdb->prepare( 'UPDATE `' . $wpdb->links . '` SET link_owner = %s WHERE link_owner = 1;', $new_user ) );

			wp_clear_auth_cookie();
			$itsec_files->release_file_lock( 'admin_user' );

			return true;

		}

		return false;
	}

    /**
     * Build WP_config rules.
     *
     * @param array $rules_array Config rules array.
     * @param null $input New directory input.
     *
     * @return array Return $rules_array.
     */
    public function build_wpconfig_rules( $rules_array, $input = null ) {
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

		$rules_array[] = array(
			'type'  => 'wpconfig',
			'name'  => 'Content Directory',
			'rules' => $rules,
		);

		return $rules_array;
	}


    /**
     * Change database prefix.
     *
     * @return array $return Return response array.
     *
     * @uses \ITSEC_Database_Prefix_Utility::change_database_prefix()
     * @uses \ITSEC_Response::get_error_strings()
     * @uses \ITSEC_Response::reload_module()
     */
    public function change_database_prefix() {

        /** @global string $mainwp_itsec_modules_path MainWP itsec modules path. */
		global $mainwp_itsec_modules_path;

		require_once $mainwp_itsec_modules_path . 'database-prefix/utility.php';
		$str_error = '';
		$return    = array();

		if ( isset( $_POST['change_prefix'] ) && 'yes' === $_POST['change_prefix'] ) {
			$result = \ITSEC_Database_Prefix_Utility::change_database_prefix();
			$return = $result['errors'];
			if ( is_array( $result['errors'] ) ) {
				foreach ( $result['errors'] as $error ) {
					$arr_errors = \ITSEC_Response::get_error_strings( $error );
					foreach ( $arr_errors as $er ) {
						$str_error .= $er . '<br />';
					}
				}
			}

			\ITSEC_Response::reload_module( 'database-prefix' );

			if ( false === $result['new_prefix'] ) {
				$return['error'] = $str_error;
			} else {
				$return['result']  = 'success';
				$return['message'] = sprintf( esc_html__( 'The database table prefix was successfully changed to <code>%1$s</code>.', 'mainwp-child' ), $result['new_prefix'] );

			}
		}
		return $return;
	}

    /**
     * Update API key.
     *
     * @return array $return Return response array. Success or nochange.
     */
    public function api_key() {
		$settings = get_site_option( 'itsec_ipcheck' );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$settings['reset'] = true;
		$return            = array();
		if ( update_site_option( 'itsec_ipcheck', $settings ) ) {
			$return['result'] = 'success';
		} else {
			$return['result'] = 'nochange';
		}

		return $return;
	}

    /**
     * Reset api key.
     *
     * @return array $information Return response array.
     *
     * @uses \ITSEC_Modules::get_defaults()
     * @uses \ITSEC_Modules::set_defaults()
     * @uses \ITSEC_Response::set_response()
     * @uses \ITSEC_Response::add_errors()
     * @uses \ITSEC_Response::add_messages()
     */
    public function reset_api_key() {

		$defaults = \ITSEC_Modules::get_defaults( 'network-brute-force' );
		$results  = \ITSEC_Modules::set_settings( 'network-brute-force', $defaults );

		\ITSEC_Response::set_response( $results['saved'] );
		\ITSEC_Response::add_errors( $results['errors'] );
		\ITSEC_Response::add_messages( $results['messages'] );

		$information = array();
		if ( $results['saved'] ) {
			$information['result']       = 'success';
			$information['nbf_settings'] = \ITSEC_Modules::get_settings( 'network-brute-force' );
		} elseif ( empty( $results['errors'] ) ) {
			$information['error_reset_api'] = 1;
		}
		return $information;
	}

    /**
     * Malware scan.
     *
     * @return array $response Return response array.
     *
     * @uses \ITSEC_Core::current_user_can_manage()
     * @uses \ITSEC_Malware_Scanner::scan()
     * @uses \ITSEC_Malware_Scan_Results_Template::get_html()
     */
    public function malware_scan() {

        /** @global string $mainwp_itsec_modules_path MainWP itsec modules path. */
		global $mainwp_itsec_modules_path;

		if ( ! class_exists( '\ITSEC_Malware_Scanner' ) ) {
			require_once $mainwp_itsec_modules_path . 'malware/class-itsec-malware-scanner.php';
			require_once $mainwp_itsec_modules_path . 'malware/class-itsec-malware-scan-results-template.php';
		}

		$response = array();
		if ( ! \ITSEC_Core::current_user_can_manage() ) {
			$response['error'] = 'The currently logged in user does not have sufficient permissions to run this scan.';
		} else {
			$results          = \ITSEC_Malware_Scanner::scan();
			$response['html'] = \ITSEC_Malware_Scan_Results_Template::get_html( $results, true );
		}

		return $response;
	}

    /**
     * Get malware scan results.
     *
     * @return array $response Return response array.
     *
     * @uses \ITSEC_Malware_Scanner::scan()
     * @uses \ITSEC_Malware_Scan_Results_Template::get_html()
     */
    public function malware_get_scan_results() {

        /** @global string $mainwp_itsec_modules_path MainWP itsec modules path. */
		global $mainwp_itsec_modules_path;

		if ( ! class_exists( '\ITSEC_Malware_Scanner' ) ) {
			require_once $mainwp_itsec_modules_path . 'malware/class-itsec-malware-scanner.php';
			require_once $mainwp_itsec_modules_path . 'malware/class-itsec-malware-scan-results-template.php';
		}
		$response         = array();
		$results          = \ITSEC_Malware_Scanner::scan();
		$response['html'] = \ITSEC_Malware_Scan_Results_Template::get_html( $results, true );
		return $response;
	}

    /**
     * Purge logs.
     *
     * @return string[] Return response array.
     */
    public function purge_logs() {

        /** @global object $wpdb WordPress Database object. */
		global $wpdb;

		$wpdb->query( 'DELETE FROM `' . $wpdb->base_prefix . 'itsec_log`;' );

		return array( 'result' => 'success' );
	}


    /**
     * Get lockouts.
     *
     * @param string $type Type of lockout: Host, user, username, Default: all.
     * @param bool $current TRUE if current. Default: FALSE.
     *
     * @return array $output Return response array.
     *
     * @uses MainWP_Child_IThemes_Security::get_lockouts_int()
     */
    public function get_lockouts( $type = 'all', $current = false ) {

        /**
         * @global object $wpdb WordPress Database object.
         * @global object $itsec_globals itsec globals.
         */
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

			$active = $and . " `lockout_active`=1 AND `lockout_expire_gmt` > '" . gmdate( 'Y-m-d H:i:s', $itsec_globals['current_time_gmt'] ) . "'";

		} else {

			$active = '';

		}
		$results = $wpdb->get_results( 'SELECT * FROM `' . $wpdb->base_prefix . 'itsec_lockouts`' . $where . $type_statement . $active . ';', ARRAY_A ); // phpcs:ignore -- safe query.		$output  = array();

		return $this->get_lockouts_int( $results, $type );
	}

    /**
     * Initiate get lockouts.
     *
     * @param array $results Results from MainWP_Child_IThemes_Security::get_lockouts()
     * @param string $type Type of lockout: Host, user, username, Default: all.
     *
     * @return array $output Return response array.
     */
    private function get_lockouts_int($results, $type ){

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

    /**
     * Release lockout.
     *
     * @return string[] Return results array.
     *
     * @uses \ITSEC_Lib::clear_caches()
     */
    public function release_lockout() {

        /** @global object $wpdb WordPress Database. */
		global $wpdb;

		if ( ! class_exists( '\ITSEC_Lib' ) ) {
			require \ITSEC_Core::get_core_dir() . '/core/class-itsec-lib.php';
		}

		$lockout_ids = array_map( 'sanitize_text_field', wp_unslash( $_POST['lockout_ids'] ) );
		if ( ! is_array( $lockout_ids ) ) {
			$lockout_ids = array();
		}

		$type    = 'updated';
		$message = esc_html__( 'The selected lockouts have been cleared.', 'mainwp-child' );

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

		\ITSEC_Lib::clear_caches();

		if ( ! is_multisite() ) {
			if ( ! function_exists( 'add_settings_error' ) ) {
				require_once ABSPATH . '/wp-admin/includes/template.php';
			}

			add_settings_error( 'itsec', esc_attr( 'settings_updated' ), $message, $type );
		}

		return array(
			'result'      => 'success',
		);
	}

    /**
     * Update module status.
     *
     * @return string[] Return response array.
     */
    public function update_module_status() {

		$active_modules = isset( $_POST['active_modules'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['active_modules'] ) ) : array();

		if ( ! is_array( $active_modules ) ) {
			$active_modules = array();
		}

		$current_val = get_site_option( 'itsec_active_modules', array() );
		foreach ( $active_modules as $mod => $val ) {
			$current_val[ $mod ] = $val;
		}

		update_site_option( 'itsec_active_modules', $current_val );
		return array( 'result' => 'success' );
	}

    /**
     * Reload excluded backups table.
     *
     * @return array Return response array.
     *
     * @uses \ITSEC_Modules::get_setting()
     * @uses MainWP_Child_IThemes_Security::get_excludable_tables()
     */
    private function reload_backup_exclude() {
		return array(
			'exclude'           => \ITSEC_Modules::get_setting( 'backup', 'exclude' ),
			'excludable_tables' => $this->get_excludable_tables(),
			'result'            => 'success',
		);
	}

    /**
     * Get excludable backups table.
     *
     * @return array $excludes Return response array.
     *
     * @uses \ITSEC_Modules::get_setting()
     */
    private function get_excludable_tables() {

        /** @global object $wpdb WordPress Database. */
		global $wpdb;

		$all_sites      = \ITSEC_Modules::get_setting( 'backup', 'all_sites' );
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

		$tables   = $wpdb->get_results( $query, ARRAY_N ); // phpcs:ignore -- safe query.
		$excludes = array();

		foreach ( $tables as $table ) {
			$short_table = substr( $table[0], strlen( $wpdb->prefix ) );

			if ( in_array( $short_table, $ignored_tables ) ) {
				continue;
			}

			$excludes[ $short_table ] = $table[0];
		}

		return $excludes;
	}

    /**
     * Get security check results.
     *
     * @return array Return response array.
     *
     * @uses \ITSEC_Security_Check_Scanner::get_results()
     * @uses \ITSEC_Security_Check_Feedback_Renderer::render(
     */
    private function security_site() {

        /** @global string $mainwp_itsec_modules_path MainWP itsec modules path. */
		global $mainwp_itsec_modules_path;

		require_once $mainwp_itsec_modules_path . 'security-check/scanner.php';
		require_once $mainwp_itsec_modules_path . 'security-check/feedback-renderer.php';
		$results = \ITSEC_Security_Check_Scanner::get_results();
		ob_start();
		\ITSEC_Security_Check_Feedback_Renderer::render( $results );
		$response = ob_get_clean();
		return array(
			'result'   => 'success',
			'response' => $response,
		);
	}

    /**
     * Get available admin users and roles.
     *
     * @return array[] Return response array.phpdoc
     *
     * @uses \WP_Roles()
     */
    public function get_available_admin_users_and_roles() {
		if ( is_callable( 'wp_roles' ) ) {
			$roles = wp_roles();
		} else {
			$roles = new \WP_Roles();
		}

		$available_roles = array();
		$available_users = array();

		foreach ( $roles->roles as $role => $details ) {
			if ( isset( $details['capabilities']['manage_options'] ) && ( true === $details['capabilities']['manage_options'] ) ) {
				$available_roles[ "role:$role" ] = translate_user_role( $details['name'] );

				$users = get_users( array( 'role' => $role ) );

				foreach ( $users as $user ) {
					/* translators: 1: user display name, 2: user login */
					$available_users[ $user->ID ] = sprintf( esc_html__( '%1$s (%2$s)', 'mainwp-child' ), $user->display_name, $user->user_login );
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
