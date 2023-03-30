<?php
/**
 * MainWP Child Actions.
 *
 * Handle MainWP Child Actions.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

/**
 * Class MainWP_Child_Actions
 *
 * Handle MainWP Child Actions.
 */
class MainWP_Child_Actions {

	/**
	 * Public static variable to hold the single instance of the class.
	 *
	 * @var mixed Default null
	 */
	protected static $instance = null;

	/**
	 * Public static variable.
	 *
	 * @var mixed Default null
	 */
	protected static $actions_data = null;

	/**
	 * Public static variable.
	 *
	 * @var mixed Default null
	 */
	protected static $sending = null;

	/**
	 * Public static variable.
	 *
	 * @var mixed Default null.
	 */
	protected static $connected_admin = null;

	/**
	 * Public static variable.
	 *
	 * @var mixed Default null.
	 */
	protected static $enable_actions_notification = null;

	/**
	 * Old plugins.
	 *
	 * @var array Old plugins array.
	 * */
	private $current_plugins_info = array();

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
	 * MainWP_Child_Callable constructor.
	 *
	 * Run any time class is called.
	 */
	public function __construct() {
		self::$connected_admin             = get_option( 'mainwp_child_connected_admin', '' );
		self::$enable_actions_notification = (int) get_option( 'mainwp_child_actions_notification_enable', 0 );
	}

	/**
	 * Method instance()
	 *
	 * Create a public static instance.
	 *
	 * @return mixed Class instance.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::get_actions_data();
		}
		return self::$instance;
	}

	/**
	 * Method init_hooks().
	 *
	 * Init WP hooks.
	 */
	public function init_hooks() {

		// avoid actions.
		if ( isset( $_POST['mainwpsignature'] ) ) {
			return;
		}

		// not connected, avoid actions.
		if ( empty( self::$connected_admin ) ) {
			return;
		}

		$pubkey = get_option( 'mainwp_child_pubkey' );
		// not connected, avoid actions.
		if ( empty( $pubkey ) ) {
			return;
		}

		$actions = array(
			'upgrader_pre_install',
			'upgrader_process_complete',
			'activate_plugin',
			'deactivate_plugin',
			'switch_theme',
			'delete_site_transient_update_themes',
			'pre_option_uninstall_plugins',
			'deleted_plugin',
			'_core_updated_successfully',
		);

		foreach ( $actions as $action ) {
			add_action( $action, array( $this, 'callback' ), 10, 99 );
		}
		if ( 1 === self::$enable_actions_notification ) {
			self::$enable_actions_notification = 0; // to do.
		}
	}

	/**
	 * Callback for all registered hooks.
	 * Looks for a class method with the convention: "callback_{action name}"
	 */
	public function callback() {
		$current  = current_filter();
		$callback = array( $this, 'callback_' . preg_replace( '/[^A-Za-z0-9_\-]/', '_', $current ) ); // to fix A-Z charater in callback name.

		// Call the real function.
		if ( is_callable( $callback ) ) {
			return call_user_func_array( $callback, func_get_args() );
		}
	}

	/**
	 * Method to get actions info.
	 */
	private function check_to_send_actions_notification() {
		$nexttime_send = (int) get_option( 'mainwp_child_send_action_notification_next_time', 0 );
		if ( ! empty( $nexttime_send ) && $nexttime_send < time() ) {
			$actions_data = get_option( 'mainwp_child_actions_saved_data', array() );
			if ( ! empty( $actions_data ) ) {
				$this->send_actions_notification( $actions_data );
			}
			delete_option( 'mainwp_child_send_action_notification_next_time' );
		}
	}

	/**
	 * Method to get actions info.
	 */
	public static function get_actions_data() {
		if ( null === self::$actions_data ) {
			self::$actions_data = get_option( 'mainwp_child_actions_saved_data', array() );
			if ( ! is_array( self::$actions_data ) ) {
				self::$actions_data = array();
			}
			$username = get_option( 'mainwp_child_connected_admin', '' );
			if ( ! isset( self::$actions_data['connected_admin'] ) ) {
				self::$actions_data['connected_admin'] = $username;
			} elseif ( '' != $username && $username != self::$actions_data['connected_admin'] ) {
				self::$actions_data = array( 'connected_admin' => $username ); // if it is not same the connected user then clear the actions data.
				update_option( 'mainwp_child_actions_saved_data', self::$actions_data );
				delete_option( 'mainwp_child_send_action_notification_next_time' );
			}
			self::check_actions_data();
		}
		return self::$actions_data;
	}


	/**
	 * Method to save actions info.
	 *
	 * @param int   $index index.
	 * @param array $data Action data .
	 *
	 * @return bool Return TRUE.
	 */
	private function update_actions_data( $index, $data ) {
		$index                        = strval( $index );
		self::$actions_data[ $index ] = $data;
		update_option( 'mainwp_child_actions_saved_data', self::$actions_data );
		return true;
	}


	/**
	 * Method to check actions data.
	 * Clear old the action info.
	 */
	public static function check_actions_data() {
		$checked = intval( get_option( 'mainwp_child_actions_data_checked', 0 ) );
		if ( empty( $checked ) ) {
			update_option( 'mainwp_child_actions_data_checked', time() );
		} else {
			$checked = date( 'Y-m-d', $checked ); // phpcs:ignore -- Use local time to achieve desired results, pull request solutions appreciated.
			if ( $checked != date( 'Y-m-d' ) ) { // phpcs:ignore -- Use local time to achieve desired results, pull request solutions appreciated.
				$days_number = intval( get_option( 'mainwp_child_actions_saved_number_of_days', 30 ) );
				$days_number = apply_filters( 'mainwp_child_actions_saved_number_of_days', $days_number );
				$days_number = ( 3 > $days_number || 6 * 30 < $days_number ) ? 30 : $days_number;
				$check_time  = $days_number * \DAY_IN_SECONDS;

				$updated = false;
				foreach ( self::$actions_data as $index => $data ) {

					if ( 'connected_admin' === strval( $index ) ) {
						continue;
					}

					if ( ! is_array( $data ) || $check_time < time() - intval( $data['created'] ) || empty( $data['action_user'] ) ) {
						unset( self::$actions_data[ $index ] );
						$updated = true;
					}
				}

				if ( $updated ) {
					update_option( 'mainwp_child_actions_saved_data', self::$actions_data );
				}
				update_option( 'mainwp_child_actions_data_checked', time() );
			}
		}
	}

	/**
	 * Log plugin installations.
	 *
	 * @action transition_post_status.
	 *
	 * @param \WP_Upgrader $upgrader WP_Upgrader class object.
	 * @param array        $extra Extra attributes array.
	 *
	 * @return bool Return TRUE|FALSE.
	 */
	public function callback_upgrader_process_complete( $upgrader, $extra ) { // phpcs:ignore -- required to achieve desired results, pull request solutions appreciated.
		$logs    = array();
		$success = ! is_wp_error( $upgrader->skin->result );
		$error   = null;

		if ( ! $success ) {
			$errors = $upgrader->skin->result->errors;

			list( $error ) = reset( $errors );
		}

		// This would have failed down the road anyway.
		if ( ! isset( $extra['type'] ) ) {
			return false;
		}

		$type   = $extra['type'];
		$action = $extra['action'];

		if ( ! in_array( $type, array( 'plugin', 'theme' ), true ) ) {
			return false;
		}

		if ( 'install' === $action ) {
			if ( 'plugin' === $type ) {
				$path = $upgrader->plugin_info();

				if ( ! $path ) {
					return false;
				}

				$data    = get_plugin_data( $upgrader->skin->result['local_destination'] . '/' . $path );
				$slug    = $upgrader->result['destination_name'];
				$name    = $data['Name'];
				$version = $data['Version'];
			} else { // theme.
				$slug = $upgrader->theme_info();

				if ( ! $slug ) {
					return false;
				}

				wp_clean_themes_cache();

				$theme   = wp_get_theme( $slug );
				$name    = $theme->name;
				$version = $theme->version;
			}

			$action = 'installed';
			// translators: Placeholders refer to a plugin/theme type, a plugin/theme name, and a plugin/theme version (e.g. "plugin", "Stream", "4.2").
			$message = _x(
				'Installed %1$s: %2$s %3$s',
				'Plugin/theme installation. 1: Type (plugin/theme), 2: Plugin/theme name, 3: Plugin/theme version',
				'mainwp-child'
			);

			$logs[] = compact( 'slug', 'name', 'version', 'message', 'action' );
		} elseif ( 'update' === $action ) {

			if ( is_object( $upgrader ) && property_exists( $upgrader, 'skin' ) && 'Automatic_Upgrader_Skin' == get_class( $upgrader->skin ) ) {
				return false;
			}

			$action = 'updated';
			// translators: Placeholders refer to a plugin/theme type, a plugin/theme name, and a plugin/theme version (e.g. "plugin", "Stream", "4.2").
			$message = _x(
				'Updated %1$s: %2$s %3$s',
				'Plugin/theme update. 1: Type (plugin/theme), 2: Plugin/theme name, 3: Plugin/theme version',
				'mainwp-child'
			);

			if ( 'plugin' === $type ) {
				if ( isset( $extra['bulk'] ) && true === $extra['bulk'] ) {
					$slugs = $extra['plugins'];
				} else {
					$slugs = array( $upgrader->skin->plugin );
				}

				foreach ( $slugs as $slug ) {
					$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $slug );
					$name        = $plugin_data['Name'];
					$version     = $plugin_data['Version'];
					// ( Net-Concept - Xavier NUEL ) : get old versions.
					if ( isset( $this->current_plugins_info[ $slug ] ) ) {
						$old_version = $this->current_plugins_info[ $slug ]['Version'];
					} else {
						$old_version = $upgrader->skin->plugin_info['Version']; // to fix old version.
					}

					if ( version_compare( $version, $old_version, '>' ) ) {
						$logs[] = compact( 'slug', 'name', 'old_version', 'version', 'message', 'action' );
					}
				}
			} else { // theme.
				if ( isset( $extra['bulk'] ) && true === $extra['bulk'] ) {
					$slugs = $extra['themes'];
				} else {
					$slugs = array( $upgrader->skin->theme );
				}

				foreach ( $slugs as $slug ) {
					$theme       = wp_get_theme( $slug );
					$stylesheet  = $theme['Stylesheet Dir'] . '/style.css';
					$theme_data  = get_file_data(
						$stylesheet,
						array(
							'Version' => 'Version',
						)
					);
					$name        = $theme['Name'];
					$old_version = $upgrader->skin->theme_info->get( 'Version' ); // to fix old version  //$theme['Version'].
					$version     = $theme_data['Version'];

					$logs[] = compact( 'slug', 'name', 'old_version', 'version', 'message', 'action' );
				}
			}
		} else {
			return false;
		}

		$context = $type . 's';

		foreach ( $logs as $log ) {
			$name        = isset( $log['name'] ) ? $log['name'] : null;
			$version     = isset( $log['version'] ) ? $log['version'] : null;
			$slug        = isset( $log['slug'] ) ? $log['slug'] : null;
			$old_version = isset( $log['old_version'] ) ? $log['old_version'] : null;
			$message     = isset( $log['message'] ) ? $log['message'] : null;
			$action      = isset( $log['action'] ) ? $log['action'] : null;

			$this->save_actions(
				$message,
				compact( 'type', 'name', 'version', 'slug', 'success', 'error', 'old_version' ),
				$context,
				$action
			);
		}
		return true;
	}


	/**
	 * Activate plugin callback.
	 *
	 * @param string $slug Plugin slug.
	 * @param bool   $network_wide Check if network wide.
	 */
	public function callback_activate_plugin( $slug, $network_wide = false ) {
		$_plugins     = $this->get_plugins();
		$name         = $_plugins[ $slug ]['Name'];
		$network_wide = $network_wide ? esc_html__( 'network wide', 'mainwp-child' ) : null;

		if ( empty( $name ) ) {
			return;
		}

		$this->save_actions(
			_x(
				'"%1$s" plugin activated %2$s',
				'1: Plugin name, 2: Single site or network wide',
				'mainwp-child'
			),
			compact( 'name', 'network_wide', 'slug' ),
			'plugins',
			'activated'
		);
	}

	/**
	 * Decativate plugin callback.
	 *
	 * @param string $slug Plugin slug.
	 * @param bool   $network_wide Check if network wide.
	 */
	public function callback_deactivate_plugin( $slug, $network_wide = false ) {
		$_plugins     = $this->get_plugins();
		$name         = $_plugins[ $slug ]['Name'];
		$network_wide = $network_wide ? esc_html__( 'network wide', 'mainwp-child' ) : null;

		$this->save_actions(
			_x(
				'"%1$s" plugin deactivated %2$s',
				'1: Plugin name, 2: Single site or network wide',
				'mainwp-child'
			),
			compact( 'name', 'network_wide', 'slug' ),
			'plugins',
			'deactivated'
		);
	}

	/**
	 * Switch theme callback.
	 *
	 * @param string $name Theme name.
	 * @param string $theme Theme slug.
	 */
	public function callback_switch_theme( $name, $theme ) {
		unset( $theme );
		$this->save_actions(
			esc_html__( '"%s" theme activated', 'mainwp-child' ),
			compact( 'name' ),
			'themes',
			'activated'
		);
	}

	/**
	 * Update theme & transient delete callback.
	 *
	 * @todo Core needs a delete_theme hook
	 */
	public function callback_delete_site_transient_update_themes() {
		$backtrace = debug_backtrace(); // @codingStandardsIgnoreLine This is used as a hack to determine a theme was deleted.
		$delete_theme_call = null;

		foreach ( $backtrace as $call ) {
			if ( isset( $call['function'] ) && 'delete_theme' === $call['function'] ) {
				$delete_theme_call = $call;
				break;
			}
		}

		if ( empty( $delete_theme_call ) ) {
			return;
		}

		$name = $delete_theme_call['args'][0];
		// @todo Can we get the name of the theme? Or has it already been eliminated

		$this->save_actions(
			esc_html__( '"%s" theme deleted', 'mainwp-child' ),
			compact( 'name' ),
			'themes',
			'deleted'
		);
	}

	/**
	 * Uninstall plugins callback.
	 */
	public function callback_pre_option_uninstall_plugins() {
		if ( ! isset( $_POST['action'] ) || 'delete-plugin' !== $_POST['action'] ) {
			return false;
		}
		$plugin                       = $_POST['plugin'];
		$_plugins                     = $this->get_plugins();
		$plugins_to_delete            = array();
		$plugins_to_delete[ $plugin ] = isset( $_plugins[ $plugin ] ) ? $_plugins[ $plugin ] : array();
		update_option( 'wp_mainwp_stream_plugins_to_delete', $plugins_to_delete );
		return false;
	}

	/**
	 * Uninstall plugins callback.
	 *
	 * @param string $plugin_file  plugin file name.
	 * @param bool   $deleted deleted or not.
	 */
	public function callback_deleted_plugin( $plugin_file, $deleted ) {
		if ( $deleted ) {

			if ( ! isset( $_POST['action'] ) || 'delete-plugin' !== $_POST['action'] ) {
				return;
			}
			$plugins_to_delete = get_option( 'wp_mainwp_stream_plugins_to_delete' );
			if ( ! $plugins_to_delete ) {
				return;
			}
			foreach ( $plugins_to_delete as $plugin => $data ) {
				if ( $plugin_file == $plugin ) {
					$name         = $data['Name'];
					$network_wide = $data['Network'] ? esc_html__( 'network wide', 'mainwp-child' ) : '';

					$this->save_actions(
						esc_html__( '"%s" plugin deleted', 'mainwp-child' ),
						compact( 'name', 'plugin', 'network_wide' ),
						'plugins',
						'deleted'
					);
				}
			}
			delete_option( 'wp_mainwp_stream_plugins_to_delete' );
		}
	}

	/**
	 * Logs WordPress core upgrades
	 *
	 * @action automatic_updates_complete
	 *
	 * @param string $update_results  Update results.
	 * @return mixed bool|null.
	 */
	public function callback_automatic_updates_complete( $update_results ) {
		global $pagenow, $wp_version;

		if ( ! is_array( $update_results ) || ! isset( $update_results['core'] ) ) {
			return false;
		}

		$info = $update_results['core'][0];

		$old_version  = $wp_version;
		$new_version  = $info->item->version;
		$auto_updated = true;

		$message = esc_html__( 'WordPress auto-updated to %s', 'stream' );

		$this->save_actions(
			$message,
			compact( 'new_version', 'old_version', 'auto_updated' ),
			'wordpress', // phpcs:ignore -- fix format text.
			'updated'
		);
	}

	/**
	 * Core updated successfully callback.
	 *
	 * @param string $new_version New WordPress verison.
	 */
	public function callback__core_updated_successfully( $new_version ) {

		/**
		 * Global variables.
		 *
		 * @global string $pagenow Current page.
		 * @global string $wp_version WordPress version.
		 */
		global $pagenow, $wp_version;

		$old_version  = $wp_version;
		$auto_updated = ( 'update-core.php' !== $pagenow );

		if ( $auto_updated ) {
			// translators: Placeholder refers to a version number (e.g. "4.2").
			$message = esc_html__( 'WordPress auto-updated to %s', 'mainwp-child' );
		} else {
			// translators: Placeholder refers to a version number (e.g. "4.2").
			$message = esc_html__( 'WordPress updated to %s', 'mainwp-child' );
		}

		$this->save_actions(
			$message,
			compact( 'new_version', 'old_version', 'auto_updated' ),
			'wordpress', // phpcs:ignore -- fix format text.
			'updated'
		);
	}

	/**
	 * Upgrader pre-instaler callback.
	 */
	public function callback_upgrader_pre_install() {
		$this->current_plugins_info = $this->get_plugins();
	}

	/**
	 * Wrapper method for calling get_plugins().
	 *
	 * @return array Installed plugins.
	 */
	public function get_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return get_plugins();
	}

	/**
	 * Log handler.
	 *
	 * @param string $message           sprintf-ready error message string.
	 * @param array  $args              sprintf (and extra) arguments to use.
	 * @param string $context           Context of the event.
	 * @param string $action            Action of the event.
	 */
	public function save_actions( $message, $args, $context, $action ) {

		/**
		 * Global variable.
		 *
		 * @global object $wp_roles WordPress user roles object.
		 * */
		global $wp_roles;

		if ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) {
			return false;
		}

		$context_label = $this->get_valid_context( $context );
		if ( empty( $context_label ) ) { // not valid.
			return false;
		}

		$action_label = $this->get_valid_action( $action );
		if ( empty( $action_label ) ) { // not valid.
			return false;
		}

		$user_id = get_current_user_id();
		$user    = get_user_by( 'id', $user_id );

		$connected_user = get_option( 'mainwp_child_connected_admin', '' );

		if ( ! empty( $user->user_login ) && $connected_user == $user->user_login && MainWP_Helper::is_dashboard_request() ) {
			return false;  // not save action.
		}

		$actions_save = apply_filters( 'mainwp_child_actions_save_data', true, $context, $action, $args, $message, $user_id );

		if ( ! $actions_save ) {
			return false;
		}

		$user_role_label = '';
		$role            = '';
		$roles           = MainWP_Utility::instance()->get_roles();
		if ( ! empty( $user->roles ) ) {
			$user_roles      = array_values( $user->roles );
			$role            = $user_roles[0];
			$user_role_label = isset( $roles[ $role ] ) ? $roles[ $role ] : $role;
		}

		$userlogin = (string) ( ! empty( $user->user_login ) ? $user->user_login : '' );

		$agent     = $this->get_current_agent();
		$meta_data = array(
			'user_id'         => (int) $user_id,
			'display_name'    => (string) $this->get_display_name( $user ),
			'action_user'     => (string) $userlogin,
			'role'            => (string) $role,
			'user_role_label' => (string) $user_role_label,
			'agent'           => (string) $agent,
		);

		if ( 'wp_cli' === $agent && function_exists( 'posix_getuid' ) ) {
			$uid       = posix_getuid();
			$user_info = posix_getpwuid( $uid );

			$meta_data['system_user_id']   = (int) $uid;
			$meta_data['system_user_name'] = (string) $user_info['name'];
		}

		// Prevent any meta with null values from being logged.
		$other_meta = array_filter(
			$args,
			function ( $var ) {
				return ! is_null( $var );
			}
		);

		// Add user meta to Stream meta.
		$other_meta['meta_data'] = $meta_data;

		$created = MainWP_Helper::get_timestamp();

		if ( ! empty( $user->roles ) ) {
			$roles = array_values( $user->roles );
			$role  = $roles[0];
		} elseif ( is_multisite() && is_super_admin() && $wp_roles->is_role( 'administrator' ) ) {
			$role = 'administrator';
		} else {
			$role = '';
		}

		$action = (string) $action;

		$new_action = 0;
		if ( 1 === self::$enable_actions_notification ) {
			$new_action = 1; // new to notification.
		}

		$recordarr = array(
			'context'     => $context,
			'action'      => $action,
			'action_user' => $userlogin,
			'created'     => $created,
			'summary'     => (string) vsprintf( $message, $args ),
			'meta_data'   => $other_meta,
			'new'         => $new_action,
		);
		$index     = time() . rand( 1000, 9999 ); // phpcs:ignore -- ok for index.
		$this->update_actions_data( $index, $recordarr );

		if ( 1 === $new_action ) {
			update_option( 'mainwp_child_send_action_notification_next_time', time() + 5 * MINUTE_IN_SECONDS );
		}
	}


	/**
	 * Delete actions logs.
	 */
	public function delete_actions() {
		delete_option( 'mainwp_child_actions_saved_data' );
		delete_option( 'mainwp_child_send_action_notification_next_time' );
		MainWP_Helper::write( array( 'success' => 'ok' ) );
	}

	/**
	 * Get valid context.
	 *
	 * @param string $context  Context.
	 *
	 * @return string Context label.
	 */
	public function get_valid_context( $context ) {
		$context = (string) $context;
		$valid   = array(
			'plugins'   => 'Plugins',
			'themes'    => 'Themes',
			'wordpress' => 'WordPress'  // phpcs:ignore -- fix format text.
		);
		return isset( $valid[ $context ] ) ? $valid[ $context ] : '';
	}

	/**
	 * Get valid action.
	 *
	 * @param string $action  action.
	 *
	 * @return string action label.
	 */
	public function get_valid_action( $action ) {
		$action = (string) $action;
		$valid  = array(
			'updated'     => 'updated',
			'deleted'     => 'deleted',
			'activated'   => 'activated',
			'deactivated' => 'deactivated',
			'installed'   => 'installed',
		);
		return isset( $valid[ $action ] ) ? $valid[ $action ] : '';
	}

	/**
	 * Get the display name of the user
	 *
	 * @param mixed $user  User object.
	 *
	 * @return string Return User Login or Display Names.
	 */
	public function get_display_name( $user ) {
		if ( 0 == $user->ID ) {
			if ( 'wp_cli' === $this->get_current_agent() ) {
				return 'WP-CLI';
			}
			return esc_html__( 'N/A', 'mainwp-child' );
		} else {
			if ( ! empty( $user->display_name ) ) {
				return $user->display_name;
			} else {
				return $user->user_login;
			}
		}
	}

	/**
	 * Get agent.
	 *
	 * @return string
	 */
	public function get_current_agent() {
		$agent = '';
		if ( defined( '\WP_CLI' ) && \WP_CLI ) {
			$agent = 'wp_cli';
		} elseif ( $this->is_doing_wp_cron() ) {
			$agent = 'wp_cron';
		}
		return $agent;
	}

	/**
	 * True if doing WP Cron, otherwise false.
	 *
	 * @return bool
	 */
	public function is_doing_wp_cron() {
		return (
			$this->is_cron_enabled()
			&&
			defined( 'DOING_CRON' )
			&&
			DOING_CRON
		);
	}

	/**
	 * True if native WP Cron is enabled, otherwise false.
	 *
	 * @return bool
	 */
	public function is_cron_enabled() {
		return ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ? false : true;
	}

	/**
	 * Method send_installer_notification()
	 *
	 * To send email notification for plugins/themes/core change.
	 *
	 * @param array $data  Action Data.
	 */
	public function send_actions_notification( $data ) {

		if ( 1 !== self::$enable_actions_notification ) {
			return;
		}

		$username = get_option( 'mainwp_child_connected_admin', '' );
		$user     = get_user_by( 'login', $username );
		$email    = ! empty( $user->user_email ) ? $user->user_email : '';

		if ( empty( $email ) ) {
			return;
		}

		$actions = self::get_actions_data();

		ksort( $actions );
		// site info.
		$blog = get_bloginfo( 'name' );
		$site = get_bloginfo( 'url' ) . '/';

		// log time.
		$time = sanitize_text_field( wp_unslash( date( 'F jS Y, h:ia', time() ) ) ); // phpcs:ignore -- Use local time to achieve desired results, pull request solutions appreciated.

		$content = '<div>Actions notification</div><div></div><br>';

		foreach ( $actions as $index => $data ) {
			if ( 'connected_admin' === strval( $index ) ) {
				continue;
			}
			if ( is_array( $data ) && ! empty( $data['action_user'] ) && isset( $data['new'] ) && 1 === (int) $data['new'] ) {
				$content    .= $data['action_user'] . ' - ' . $data['summray'] . ' - ' . MainWP_Helper::format_timestamp( ( $data['created'] ) ) . '<br>';
				$data['new'] = 0;
				$this->update_actions_data( $index, $data );
			}
		}

		MainWP_Utility::instance()->send_wp_mail(
			$email,
			'MainWP - Actions notification: ' . $blog,
			MainWP_Child_Format::format_email( $content ),
			array(
				'content-type: text/html',
			)
		);
	}
}
