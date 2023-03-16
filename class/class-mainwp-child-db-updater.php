<?php
/**
 * MainWP Database Updater
 *
 * MainWP MainWP Database Updater extension handler.
 * Extension URL: https://mainwp.com/extension/databaseupdater/
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

/**
 * Class MainWP_Child_DB_Updater
 *
 * MainWP Database Updater extension handler.
 */
class MainWP_Child_DB_Updater {

	/**
	 * Public static variable to hold the single instance of the class.
	 *
	 * @var mixed Default null
	 */
	public static $instance = null;

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
		add_filter( 'mainwp_site_sync_others_data', array( $this, 'sync_others_data' ), 10, 2 );
		MainWP_Child_DB_Updater_WC::instance();
		MainWP_Child_DB_Updater_Elementor::instance();
	}

	/**
	 * Method sync_others_data()
	 *
	 * Sync data to & from the MainWP Dashboard.
	 *
	 * @param array $information Array containing the data to be sent to the Dashboard.
	 * @param array $data        Array containing the data sent from the Dashboard; to be saved to the Child Site.
	 *
	 * @return array $information Array containing the data to be sent to the Dashboard.
	 */
	public function sync_others_data( $information, $data = array() ) {
		if ( isset( $data['syncDBUpdater'] ) && $data['syncDBUpdater'] ) {
			try {
				$information['syncDBUpdaterResponse'] = array(
					'plugin_db_upgrades' => $this->get_sync_data(),
				);
			} catch ( \Exception $e ) {
				// ok!
			}
		}
		return $information;
	}

	/**
	 * Method action()
	 *
	 * Fire off certain branding actions.
	 *
	 * @uses MainWP_Child_Branding::update_branding() Update custom branding settings.
	 * @uses \MainWP\Child\MainWP_Helper::write()
	 */
	public function action() {
		$information = array();
		MainWP_Child_DB_Updater_WC::instance()->requires_files();
		try {
			$mwp_action = ! empty( $_POST['mwp_action'] ) ? sanitize_text_field( wp_unslash( $_POST['mwp_action'] ) ) : '';
			switch ( $mwp_action ) {
				case 'update_db':
					$information = $this->update_db();
					break;
			}
		} catch ( \Exception $e ) {
			$information['error'] = $e->getMessage();
		}

		MainWP_Helper::write( $information );
	}

	/**
	 * Get sync data.
	 */
	public function get_sync_data() {
		$sync_plugin_db_upgrades = apply_filters( 'mainwp_child_db_updater_sync_data', array() );
		return $sync_plugin_db_upgrades;
	}

	/**
	 * Method update_db()
	 *
	 * Update DB.
	 *
	 * @return array Action result.
	 */
	public function update_db() {
		$information = array();
		$plugins     = isset( $_POST['list'] ) ? explode( ',', urldecode( wp_unslash( $_POST['list'] ) ) ) : array();
		$upgrades    = array();
		foreach ( $plugins as $slug ) {
			$success = false;
			switch ( $slug ) {
				case 'woocommerce/woocommerce.php':
					$success = MainWP_Child_DB_Updater_WC::instance()->update_db();
					break;
				case 'elementor/elementor.php':
					$success = MainWP_Child_DB_Updater_Elementor::instance()->update_db();
					break;
				case 'elementor-pro/elementor-pro.php':
					$success = MainWP_Child_DB_Updater_Elementor::instance()->update_db( true );
					break;
			}
			if ( $success ) {
				$upgrades[ $slug ] = 1;
			}
		}
		$information['upgrades']           = $upgrades;
		$information['plugin_db_upgrades'] = $this->get_sync_data();
		return $information;
	}
}

