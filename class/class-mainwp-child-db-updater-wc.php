<?php
/**
 * MainWP Database Updater WC.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

/**
 * Class MainWP_Child_DB_Updater_WC.
 *
 * MainWP Database Updater extension handler.
 */
class MainWP_Child_DB_Updater_WC {

	/**
	 * Public static variable to hold the single instance of the class.
	 *
	 * @var mixed Default null
	 */
	public static $instance = null;

	/**
	 * Public variable to hold the infomration if the WooCommerce plugin is installed on the child site.
	 *
	 * @var bool If WooCommerce intalled, return true, if not, return false.
	 */
	public static $is_plugin_woocom_installed = false;

	/**
	 * Method instance()
	 *
	 * Create a public static instance.
	 *
	 * @return mixed Class instance.
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * Run any time class is called.
	 *
	 * @return void
	 */
	public function __construct() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		if ( function_exists( 'WC' ) && is_plugin_active( 'woocommerce/woocommerce.php' ) ) {
			self::$is_plugin_woocom_installed = true;
		}

		if ( self::$is_plugin_woocom_installed ) {
			add_filter( 'mainwp_child_db_updater_sync_data', array( $this, 'hook_db_updater_sync_data' ), 10, 1 );
		}
	}

	/**
	 * Requires WC files.
	 *
	 * @return mixed Results.
	 */
	public function requires_files() {
		if ( ! self::$is_plugin_woocom_installed ) {
			return;
		}
		if ( file_exists( WC_ABSPATH . 'includes/class-wc-install.php' ) ) {
			include_once WC_ABSPATH . 'includes/class-wc-install.php';
		}
		if ( file_exists( WC_ABSPATH . 'includes/wc-update-functions.php' ) ) {
			include_once WC_ABSPATH . 'includes/wc-update-functions.php';
		}
	}

	/**
	 * Get db updater sync data.
	 *
	 * @param array $db_upgrades Input sync data.
	 *
	 * @return array $db_upgrades Return data array.
	 * @throws Exception Error message.
	 */
	public function hook_db_updater_sync_data( $db_upgrades ) {
		if ( ! self::$is_plugin_woocom_installed ) {
			return $db_upgrades;
		}

		if ( ! is_array( $db_upgrades ) ) {
			$db_upgrades = array();
		}

		$this->requires_files();
		try {

			MainWP_Helper::instance()->check_classes_exists( array( '\WC_Install' ) );
			MainWP_Helper::instance()->check_methods( '\WC_Install', array( 'needs_db_update' ) );

			if ( \WC_Install::needs_db_update() ) {
				$next_scheduled_date = \WC()->queue()->get_next( 'woocommerce_run_update_callback', null, 'woocommerce-db-updates' );
				if ( ! $next_scheduled_date ) {
					$current_db_version = get_option( 'woocommerce_db_version', null );
					// need.
					$db_upgrades['woocommerce/woocommerce.php'] = array(
						'update'     => $this->get_needs_db_update(),
						'Name'       => 'WooCommerce',
						'db_version' => $current_db_version ? $current_db_version : '',
					);
				}
			}
		} catch ( \Exception $e ) {
			// not exit here!
			error_log( $e->getMessage() ); //phpcs:ignore -- for debug.
		}
		return $db_upgrades;
	}



	/**
	 * Is a DB update needed?
	 *
	 * @since  3.2.0
	 * @return boolean
	 */
	public static function get_needs_db_update() {
		$db_versions = array(
			'new_db_version' => '',
			'slug'           => 'woocommerce/woocommerce.php',
		);

		$current_db_version = get_option( 'woocommerce_db_version', null );
		$updates            = \WC_Install::get_db_update_callbacks();
		$update_versions    = array_keys( $updates );
		usort( $update_versions, 'version_compare' );

		if ( ! empty( $update_versions ) ) {
			$db_versions['new_db_version'] = end( $update_versions );
		}

		return $db_versions;
	}

	/**
	 * Method update_db()
	 *
	 * Update WC DB.
	 *
	 * @return array Action result.
	 */
	public function update_db() {
		if ( ! self::$is_plugin_woocom_installed ) {
			return false;
		}
		$success = false;
		try {
			MainWP_Helper::instance()->check_classes_exists( array( '\WC_Install' ) );
			MainWP_Helper::instance()->check_methods( '\WC_Install', array( 'needs_db_update' ) );
			$this->update_wc_db();
			$success = true;
		} catch ( \Exception $e ) {
			error_log( $e->getMessage() ); //phpcs:ignore -- for debug.
		}
		try {
			MainWP_Helper::instance()->check_classes_exists( array( '\WC_Admin_Notices' ) );
			MainWP_Helper::instance()->check_methods( '\WC_Admin_Notices', array( 'remove_notice' ) );
			self::hide_notice( 'update' );
		} catch ( \Exception $e ) {
			error_log( $e->getMessage() ); //phpcs:ignore -- for debug.
		}
		return $success;
	}

	/**
	 * Push all needed DB updates to the queue for processing.
	 */
	private static function update_wc_db() {
		MainWP_Helper::instance()->check_methods( '\WC_Install', array( 'get_db_update_callbacks' ) );
		$current_db_version = get_option( 'woocommerce_db_version' );
		$loop               = 0;
		foreach ( \WC_Install::get_db_update_callbacks() as $version => $update_callbacks ) {
			if ( version_compare( $current_db_version, $version, '<' ) ) {
				foreach ( $update_callbacks as $update_callback ) {
					\WC()->queue()->schedule_single(
						time() + $loop,
						'woocommerce_run_update_callback',
						array(
							'update_callback' => $update_callback,
						),
						'woocommerce-db-updates'
					);
					$loop++;
				}
			}
		}

		// After the callbacks finish, update the db version to the current WC version.
		$current_wc_version = WC()->version;
		if ( version_compare( $current_db_version, $current_wc_version, '<' ) &&
			! \WC()->queue()->get_next( 'woocommerce_update_db_to_current_version' ) ) {
			\WC()->queue()->schedule_single(
				time() + $loop,
				'woocommerce_update_db_to_current_version',
				array(
					'version' => $current_wc_version,
				),
				'woocommerce-db-updates'
			);
		}
	}

	/**
	 * Hide a single notice.
	 *
	 * @param string $name Notice name.
	 */
	private static function hide_notice( $name ) {
		\WC_Admin_Notices::remove_notice( $name );
		update_user_meta( get_current_user_id(), 'dismissed_' . $name . '_notice', true );
		do_action( 'woocommerce_hide_' . $name . '_notice' );
	}
}

