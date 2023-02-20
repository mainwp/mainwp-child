<?php
/**
 * MainWP Database Updater Elementor.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

/**
 * Class MainWP_Child_DB_Updater_Elementor.
 *
 * MainWP Database Updater extension handler.
 */
class MainWP_Child_DB_Updater_Elementor {

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
	public static $is_plugin_elementor_installed = false;

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
		if ( defined( 'ELEMENTOR_VERSION' ) && is_plugin_active( 'elementor/elementor.php' ) ) {
			self::$is_plugin_elementor_installed = true;
		}
		if ( self::$is_plugin_elementor_installed ) {
			add_filter( 'mainwp_child_db_updater_sync_data', array( $this, 'hook_db_updater_sync_data' ), 10, 1 );
		}
	}


	/**
	 * Get sync data.
	 *
	 * @return array|bool $out Return Updraft data array or FALSE on failure.
	 * @throws Exception Error message.
	 */
	public function hook_db_updater_sync_data( $db_upgrades ) {

		if ( ! self::$is_plugin_elementor_installed ) {
			return $db_upgrades;
		}

		if ( ! is_array( $db_upgrades ) ) {
			$db_upgrades = array();
		}

		if ( $this->should_upgrade() ) {
			$db_upgrades['elementor/elementor.php'] = array(
				'update'     => $this->get_needs_db_update(),
				'Name'       => 'Elementor',
				'db_version' => $this->get_current_version(),
			);
		}
		return $db_upgrades;
	}


	/**
	 * Is a DB update needed?
	 *
	 * @since  3.2.0
	 * @return boolean
	 */
	public function get_needs_db_update() {
		$db_versions    = array(
			'new_db_version' => '',
			'slug'           => 'elementor/elementor.php',
		);
		$new_db_version = $this->get_new_version();
		if ( $this->should_upgrade() ) {
			$db_versions['new_db_version'] = $new_db_version;
		}
		return $db_versions;
	}


	/**
	 * Method should_upgrade().
	 *
	 * Get should upgrade.
	 *
	 * @return bool version compare result.
	 */
	public function should_upgrade() {
		$current_version = $this->get_current_version();
		// It's a new install.
		if ( ! $current_version ) {
			return false;
		}
		return version_compare( $this->get_new_version(), $current_version, '>' );
	}

	/**
	 * Method get_current_version().
	 *
	 * Get current version.
	 *
	 * @return string version result.
	 */
	public function get_current_version() {
		return get_option( $this->get_version_option_name() );
	}

	/**
	 * Method get_new_version().
	 *
	 * Get new elementor version.
	 *
	 * @return string version result.
	 */
	public function get_new_version() {
		return ELEMENTOR_VERSION;
	}

	/**
	 * Method get_version_option_name().
	 *
	 * Get version option name.
	 *
	 * @return string option name.
	 */
	public function get_version_option_name() {
		return 'elementor_version';
	}

	/**
	 * Method update_db()
	 *
	 * Update Elementor DB.
	 *
	 * @return array Action result.
	 */
	public function update_db() {
		if ( ! self::$is_plugin_elementor_installed ) {
			return false;
		}
		try {
			return $this->do_db_upgrade();
		} catch ( \Exception $e ) {
			error_log( $e->getMessage() ); // phpcs:ignore
			return false;
		}
	}

	/**
	 * Method get_update_db_manager_class()
	 *
	 * Get update db manager class.
	 *
	 * @return array Action result.
	 */
	protected function get_update_db_manager_class() {
		return '\Elementor\Core\Upgrade\Manager';
	}

	/**
	 * Method do_db_upgrade()
	 *
	 * Do DB upgrade.
	 *
	 * @return array Action result.
	 */
	protected function do_db_upgrade() {
		$manager_class = $this->get_update_db_manager_class();

		MainWP_Helper::instance()->check_classes_exists( array( $manager_class, '\Elementor\Plugin' ) );

		// core\upgrade\manager.php.
		/** @var \Elementor\Core\Upgrade\Manager $manager */
		$manager = new $manager_class();

		if ( ! $this->check_parent_method( $manager, 'get_task_runner' ) ) {
			return false;
		}

		if ( ! $this->check_parent_method( $manager, 'should_upgrade' ) ) {
			return false;
		}

		$updater = $manager->get_task_runner();

		if ( ! $manager->should_upgrade() ) {
			return true;
		}

		if ( ! $this->check_parent_method( $updater, 'handle_immediately' ) ) {
			return false;
		}

		if ( ! $this->check_parent_method( $manager, 'get_upgrade_callbacks' ) ) {
			return false;
		}

		if ( ! $this->check_parent_method( $manager, 'get_plugin_label' ) ) {
			return false;
		}
		if ( ! $this->check_parent_method( $manager, 'get_current_version' ) ) {
			return false;
		}
		if ( ! $this->check_parent_method( $manager, 'get_new_version' ) ) {
			return false;
		}

		if ( ! $this->check_parent_method( $manager, 'on_runner_complete' ) ) {
			return false;
		}

		$callbacks = $manager->get_upgrade_callbacks();
		$did_tasks = false;

		if ( ! empty( $callbacks ) ) {
			\Elementor\Plugin::$instance->logger->get_logger()->info(
				'Update DB has been started',
				array(
					'meta' => array(
						'plugin' => $manager->get_plugin_label(),
						'from'   => $manager->get_current_version(),
						'to'     => $manager->get_new_version(),
					),
				)
			);

			$updater->handle_immediately( $callbacks );

			$did_tasks = true;
		}
		$manager->on_runner_complete( $did_tasks );
		return true;
	}

	/**
	 * Method check_parent_method().
	 *
	 * Check parent method.
	 *
	 * @param mixed  $obj  Object to check.
	 * @param string $func Function to check.
	 *
	 * @return array Action result.
	 */
	public function check_parent_method( $obj, $func ) {

		if ( method_exists( $obj, $func ) ) {
			return true;
		}

		$parent_cls = get_parent_class( $obj );

		if ( empty( $parent_cls ) ) {
			return false;
		}

		if ( method_exists( $parent_cls, $func ) ) {
			return true;
		}

		return false;
	}
}

