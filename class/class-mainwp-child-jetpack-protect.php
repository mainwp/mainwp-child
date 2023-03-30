<?php
/**
 * MainWP Child Jetpack Protect.
 *
 * MainWP Jetpack Protect Extension handler.
 *
 * @link https://mainwp.com/extension/jetpack-protect/
 *
 * @package MainWP\Child
 *
 * Credits
 *
 * Plugin-Name: Jetpack Protect
 * Plugin URI: https://wordpress.org/plugins/jetpack-protect/
 * Author: Automattic
 * Author URI: https://jetpack.com/
 *
 * The code is used for the MainWP Jetpack Protect Extension
 * Extension URL: https://mainwp.com/extension/jetpack-protect/
 */

namespace MainWP\Child;

// phpcs:disable PSR1.Classes.ClassDeclaration, WordPress.WP.AlternativeFunctions -- Required to achieve desired results. Pull requests appreciated.

/**
 * Class MainWP_Child_Jetpack_Protect
 *
 * MainWP Staging Extension handler.
 */
class MainWP_Child_Jetpack_Protect {

	/**
	 * Public static variable to hold the single instance of the class.
	 *
	 * @var mixed Default null
	 */
	public static $instance = null;

	/**
	 * Public variable to hold the information if the WP Staging plugin is installed on the child site.
	 *
	 * @var bool If WP Staging intalled, return true, if not, return false.
	 */
	public $is_plugin_installed = false;

	/**
	 * Public variable to hold the information if the WP Staging plugin is installed on the child site.
	 *
	 * @var string version string.
	 */
	public $plugin_version = false;

	/**
	 * Private variable to hold the Jetpack Connection information.
	 *
	 * @var string version string.
	 */
	private $connection = null;

	/**
	 * Create a public static instance of MainWP_Child_Jetpack_Protect.
	 *
	 * @return MainWP_Child_Jetpack_Protect
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * MainWP_Child_Jetpack_Protect constructor.
	 *
	 * Run any time class is called.
	 */
	public function __construct() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		if ( is_plugin_active( 'jetpack-protect/jetpack-protect.php' ) && defined( 'JETPACK_PROTECT_DIR' ) ) {
			$this->is_plugin_installed = true;
		}

		if ( ! $this->is_plugin_installed ) {
			return;
		}

		add_filter( 'mainwp_site_sync_others_data', array( $this, 'sync_others_data' ), 10, 2 );

		if ( 'hide' === get_option( 'mainwp_child_jetpack_protect_hide_plugin' ) ) {
			add_filter( 'all_plugins', array( $this, 'hook_all_plugins' ) );
			add_action( 'admin_menu', array( $this, 'hook_remove_menu' ) );
			add_action( 'admin_head', array( $this, 'admin_head' ) );
			add_filter( 'site_transient_update_plugins', array( &$this, 'hook_remove_update_nag' ) );
			add_filter( 'mainwp_child_hide_update_notice', array( &$this, 'hook_hide_update_notice' ) );
		}
	}

	/**
	 * Load connection manager object.
	 *
	 * @return object An array of available clones.
	 */
	public function load_connection_manager() {
		if ( null === $this->connection ) {
			MainWP_Helper::instance()->check_classes_exists( '\Automattic\Jetpack\Connection\Manager' );
			$this->connection = new \Automattic\Jetpack\Connection\Manager();
		}
		return $this->connection;
	}

	/**
	 * Sync others data.
	 *
	 * Get an array of available clones of this Child Sites.
	 *
	 * @param array $information Holder for available clones.
	 * @param array $data Array of existing clones.
	 *
	 * @uses MainWP_Child_Jetpack_Protect::get_sync_data()
	 *
	 * @return array $information An array of available clones.
	 */
	public function sync_others_data( $information, $data = array() ) {
		if ( isset( $data['sync_JetpackProtect'] ) && $data['sync_JetpackProtect'] ) {
			try {
				$this->load_connection_manager();
				MainWP_Helper::instance()->check_methods( $this->connection, 'is_connected' );
				$status                                  = $this->get_sync_data();
				$information['sync_JetpackProtect_Data'] = array(
					'status'    => $status['status'],
					'connected' => $this->connection->is_connected(),
				);

				if ( MainWP_Helper::instance()->check_classes_exists( '\Automattic\Jetpack\My_Jetpack\Products\Scan', true ) ) {
					$protect_san = new \Automattic\Jetpack\My_Jetpack\Products\Scan();
					if ( MainWP_Helper::instance()->check_methods( $protect_san, 'is_active', true ) ) {
						$information['sync_JetpackProtect_Data']['is_active'] = $protect_san::is_active() ? 1 : 0;
					}
				}
			} catch ( \Exception $e ) {
				// error!
			}
		}
		return $information;
	}

	/**
	 * Fires off MainWP_Child_Jetpack_Protect::get_overview().
	 *
	 * @uses MainWP_Child_Jetpack_Protect::get_overview()
	 * @return array An array of available clones.
	 */
	public function get_sync_data() {
		return $this->get_scan_status();
	}


	/**
	 * Fires of certain Jetpack Protect plugin actions.
	 */
    public function action() { // phpcs:ignore -- ignore complex method notice.
		if ( ! $this->is_plugin_installed ) {
			MainWP_Helper::write( array( 'error' => __( 'Please install Jetpack Protect plugin on child website', 'mainwp-child' ) ) );
		}

		$information = array();

		if ( isset( $_POST['mwp_action'] ) ) {
			$mwp_action = ! empty( $_POST['mwp_action'] ) ? sanitize_text_field( wp_unslash( $_POST['mwp_action'] ) ) : '';
			try {
				$this->load_connection_manager();
				switch ( $mwp_action ) {
					case 'set_showhide':
						$information = $this->set_showhide();
						break;
					case 'set_connect_disconnect':
						$information = $this->set_connect_disconnect();
						break;
				}
			} catch ( \Exception $e ) {
				$information = array( 'error' => $e->getMessage() );
			}
		}
		MainWP_Helper::write( $information );
	}

	/**
	 * Sets whether or not to hide the Jetpack Protect Plugin.
	 *
	 * @return array $information Action result.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::update_option()
	 */
	public function set_showhide() {
		$hide = isset( $_POST['showhide'] ) && ( 'hide' === $_POST['showhide'] ) ? 'hide' : '';
		MainWP_Helper::update_option( 'mainwp_child_jetpack_protect_hide_plugin', $hide, 'yes' );
		$information['result'] = 'SUCCESS';
		return $information;
	}

	/**
	 * Set JP connect.
	 *
	 * @return array $return connect result.
	 */
	public function set_connect_disconnect() {
		$status = isset( $_POST['status'] ) ? $_POST['status'] : '';
		if ( 'connect' === $status ) {
			MainWP_Helper::instance()->check_methods( $this->connection, array( 'set_plugin_instance', 'try_registration', 'is_connected' ) );

			MainWP_Helper::instance()->check_classes_exists( array( '\Automattic\Jetpack\Connection\Plugin_Storage', '\Automattic\Jetpack\Connection\Plugin' ) );
			MainWP_Helper::instance()->check_methods( '\Automattic\Jetpack\Connection\Plugin_Storage', 'get_one' );

			$connected_plugin = \Automattic\Jetpack\Connection\Plugin_Storage::get_one( (string) $request['plugin_slug'] );
			if ( ! is_wp_error( $connected_plugin ) && ! empty( $connected_plugin ) ) {
				$this->connection->set_plugin_instance( new \Automattic\Jetpack\Connection\Plugin( (string) $request['plugin_slug'] ) );
			}
			$result = $this->connection->try_registration();

			if ( is_wp_error( $result ) ) {
				return array( 'error' => $result->get_error_message() );
			}

			return array(
				'code'      => 'success',
				'connected' => $this->connection->is_connected(),
			);
		} elseif ( 'disconnect' === $status ) {
			MainWP_Helper::instance()->check_methods( $this->connection, array( 'is_connected', 'disconnect_site' ) );
			if ( $this->connection->is_connected() ) {
				$this->connection->disconnect_site();
				return array(
					'code'      => 'success',
					'connected' => $this->connection->is_connected(),
				);
			}
			return array(
				'code'  => 'disconnect_failed',
				'error' => esc_html__( 'Failed to disconnect the site as it appears already disconnected.', 'mainwp-child' ),
			);
		}
		return array( 'code' => 'invalid_data' );
	}

	/**
	 * Get scan status.
	 *
	 * @return array $return scan result.
	 */
	public function get_scan_status() {
		MainWP_Helper::instance()->check_classes_exists( '\Automattic\Jetpack\Protect\Status' );
		MainWP_Helper::instance()->check_methods( '\Automattic\Jetpack\Protect\Status', 'get_status' );
		$return = array(
			'status' => \Automattic\Jetpack\Protect\Status::get_status(),
		);
		return $return;
	}

	/**
	 * Get list of all plugins except WPStaging.
	 *
	 * @param array $plugins All installed plugins.
	 * @return mixed Returned array of plugins without WPStaging included.
	 */
	public function hook_all_plugins( $plugins ) {
		foreach ( $plugins as $key => $value ) {
			$plugin_slug = basename( $key, '.php' );
			if ( 'jetpack-protect' === $plugin_slug ) {
				unset( $plugins[ $key ] );
			}
		}

		return $plugins;
	}

	/**
	 * Remove the plugin menu.
	 */
	public function hook_remove_menu() {
		remove_menu_page( 'jetpack-protect' );
		$pos = isset( $_SERVER['REQUEST_URI'] ) ? stripos( wp_unslash( $_SERVER['REQUEST_URI'] ), 'admin.php?page=jetpack-protect' ) : false;
		if ( false !== $pos ) {
			wp_safe_redirect( get_option( 'siteurl' ) . '/wp-admin/index.php' );
			exit();
		}
	}

	/**
	 * Hide plugin menus.
	 */
	public function admin_head() {
		?>
		<style type="text/css">
			#wp-admin-bar-jetpack-protect{
				display: none !important;
			}
			#toplevel_page_jetpack{
				display: none !important;
			}
		</style>
		<?php
	}


	/**
	 * Hide all admin update notices.
	 *
	 * @param array $slugs WPStaging plugin slug.
	 * @return mixed Returned $slugs.
	 */
	public function hook_hide_update_notice( $slugs ) {
		$slugs[] = 'jetpack-protect/jetpack-protect.php';

		return $slugs;
	}

	/**
	 * Remove WPStaging update Nag message.
	 *
	 * @param array $value WPStaging slug.
	 * @return mixed $value Response array.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::is_updates_screen()
	 */
	public function hook_remove_update_nag( $value ) {
		if ( isset( $_POST['mainwpsignature'] ) ) {
			return $value;
		}

		if ( ! MainWP_Helper::is_updates_screen() ) {
			return $value;
		}

		if ( isset( $value->response['jetpack-protect/jetpack-protect.php'] ) ) {
			unset( $value->response['jetpack-protect/jetpack-protect.php'] );
		}
		return $value;
	}
}
