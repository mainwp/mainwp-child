<?php
/**
 * MainWP Child Jetpack Scan.
 *
 * MainWP Jetpack Scan Extension handler.
 *
 * @link https://mainwp.com/extension/jetpack/
 *
 * @package MainWP\Child
 *
 * Credits
 *
 * Plugin-Name: Jetpack Scan
 * Plugin URI: https://wordpress.org/plugins/jetpack/
 * Author: Automattic
 * Author URI: https://jetpack.com/
 *
 * The code is used for the MainWP Jetpack Scan Extension
 * Extension URL: https://mainwp.com/extension/jetpack/
 */

namespace MainWP\Child;

// phpcs:disable PSR1.Classes.ClassDeclaration, WordPress.WP.AlternativeFunctions -- Required to achieve desired results. Pull requests appreciated.

/**
 * Class MainWP_Child_Jetpack_Scan
 *
 * MainWP Staging Extension handler.
 */
class MainWP_Child_Jetpack_Scan {

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
	 * Create a public static instance of MainWP_Child_Jetpack_Scan.
	 *
	 * @return MainWP_Child_Jetpack_Scan
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * MainWP_Child_Jetpack_Scan constructor.
	 *
	 * Run any time class is called.
	 */
	public function __construct() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		if ( is_plugin_active( 'jetpack-protect/jetpack-protect.php' ) && defined( 'JETPACK_PROTECT_DIR' ) ) {
			$this->is_plugin_installed = true;
		}

		if ( ! $this->is_plugin_installed ) {
			if ( is_plugin_active( 'jetpack/jetpack.php' ) && defined( 'JETPACK__PLUGIN_DIR' ) ) {
				$this->is_plugin_installed = true;
			}
		}

		if ( ! $this->is_plugin_installed ) {
			return;
		}

		if ( 'hide' === get_option( 'mainwp_child_jetpack_scan_hide_plugin' ) ) {
			add_action( 'admin_head', array( &$this, 'admin_head' ) );
			add_filter( 'all_plugins', array( $this, 'hook_all_plugins' ) );
			add_action( 'admin_menu', array( $this, 'hook_remove_menu' ), 2000 ); // Jetpack uses 998.
			add_filter( 'site_transient_update_plugins', array( &$this, 'hook_remove_update_nag' ) );
			add_filter( 'mainwp_child_hide_update_notice', array( &$this, 'hook_hide_update_notice' ) );
		}
	}

	/**
	 * Fires of certain Jetpack Scan plugin actions.
	 */
    public function action() { // phpcs:ignore -- ignore complex method notice.
		if ( ! $this->is_plugin_installed ) {
			MainWP_Helper::write( array( 'error' => __( 'Please install Jetpack Protect or Jetpact Scan plugin on child website', 'mainwp-child' ) ) );
		}

		$information = array();

		if ( isset( $_POST['mwp_action'] ) ) {
			$mwp_action = ! empty( $_POST['mwp_action'] ) ? sanitize_text_field( wp_unslash( $_POST['mwp_action'] ) ) : '';
			try {
				switch ( $mwp_action ) {
					case 'set_showhide':
						$information = $this->set_showhide();
						break;
				}
			} catch ( \Exception $e ) {
				$information = array( 'error' => $e->getMessage() );
			}
		}
		MainWP_Helper::write( $information );
	}

	/**
	 * Sets whether or not to hide the Jetpack Scan Plugin.
	 *
	 * @return array $information Action result.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::update_option()
	 */
	public function set_showhide() {
		$hide = isset( $_POST['showhide'] ) && ( 'hide' === $_POST['showhide'] ) ? 'hide' : '';
		MainWP_Helper::update_option( 'mainwp_child_jetpack_scan_hide_plugin', $hide, 'yes' );
		MainWP_Helper::update_option( 'mainwp_child_jetpack_protect_hide_plugin', $hide, 'yes' );
		$information['result'] = 'SUCCESS';
		return $information;
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
			if ( 'jetpack' === $plugin_slug ) {
				unset( $plugins[ $key ] );
			}
		}

		return $plugins;
	}

	/**
	 * Remove Jetpack WordPress Menu.
	 */
	public function hook_remove_menu() {
		remove_menu_page( 'jetpack' );
		$pos = isset( $_SERVER['REQUEST_URI'] ) ? stripos( wp_unslash( $_SERVER['REQUEST_URI'] ), 'admin.php?page=jetpack' ) : false;
		if ( false !== $pos ) {
			wp_safe_redirect( admin_url( 'index.php' ) );
			exit();
		}
	}


	/**
	 * Render admin header.
	 */
	public function admin_head() {
		?>
		<style type="text/css">
			div.jitm-card,
			div.jitm-banner {
				display: none !important;
			}
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
	 * @param array $slugs Jetpack plugin slug.
	 * @return mixed Returned $slugs.
	 */
	public function hook_hide_update_notice( $slugs ) {
		$slugs[] = 'jetpack/jetpack.php';

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

		if ( isset( $value->response['jetpack/jetpack.php'] ) ) {
			unset( $value->response['jetpack/jetpack.php'] );
		}
		return $value;
	}
}
