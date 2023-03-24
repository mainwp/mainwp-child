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
	 * @param array $db_upgrades Input sync data.
	 *
	 * @return array $db_upgrades Return data array.
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

		if ( self::has_pro() ) {
			if ( $this->should_upgrade( true ) ) {
				$db_upgrades['elementor-pro/elementor-pro.php'] = array(
					'update'     => $this->get_needs_db_update( true ),
					'Name'       => 'Elementor Pro',
					'db_version' => $this->get_current_version( true ),
				);
			}
		}
		return $db_upgrades;
	}


	/**
	 * Is a DB update needed?
	 *
	 * @param bool $pro Pro version or not.
	 *
	 * @since  3.2.0
	 * @return boolean
	 */
	public function get_needs_db_update( $pro = false ) {
		if ( $pro ) {
			$db_versions    = array(
				'new_db_version' => '',
				'slug'           => 'elementor-pro/elementor-pro.php',
			);
			$new_db_version = $this->get_new_version( true );
			if ( $this->should_upgrade( true ) ) {
				$db_versions['new_db_version'] = $new_db_version;
			}
		} else {
			$db_versions    = array(
				'new_db_version' => '',
				'slug'           => 'elementor/elementor.php',
			);
			$new_db_version = $this->get_new_version();
			if ( $this->should_upgrade() ) {
				$db_versions['new_db_version'] = $new_db_version;
			}
		}
		return $db_versions;
	}


	/**
	 * Method should_upgrade().
	 *
	 * Get should upgrade.
	 *
	 * @param bool $pro Pro version or not.
	 *
	 * @return bool version compare result.
	 */
	public function should_upgrade( $pro = false ) {
		$current_version = $this->get_current_version( $pro );
		// It's a new install.
		if ( ! $current_version ) {
			return false;
		}
		return version_compare( $this->get_new_version( $pro ), $current_version, '>' );
	}

	/**
	 * Method has_pro().
	 *
	 * Has pro version.
	 */
	public static function has_pro() {
		return defined( 'ELEMENTOR_PRO_VERSION' );
	}


	/**
	 * Method get_current_version().
	 *
	 * Get current version.
	 *
	 * @param bool $pro Pro version or not.
	 *
	 * @return string version result.
	 */
	public function get_current_version( $pro = false ) {
		return get_option( $this->get_version_option_name( $pro ) );
	}


	/**
	 * Method get_new_version().
	 *
	 * Get new elementor version.
	 *
	 * @param bool $pro Pro version or not.
	 *
	 * @return string version result.
	 */
	public function get_new_version( $pro = false ) {
		return $pro ? ELEMENTOR_PRO_VERSION : ELEMENTOR_VERSION;
	}


	/**
	 * Method get_version_option_name().
	 *
	 * Get version option name.
	 *
	 * @param bool $pro Pro version or not.
	 *
	 * @return string option name.
	 */
	public function get_version_option_name( $pro = false ) {
		return $pro ? 'elementor_pro_version' : 'elementor_version';
	}


	/**
	 * Method update_db()
	 *
	 * Update Elementor DB.
	 *
	 * @param bool $pro Pro version or not.
	 *
	 * @return array Action result.
	 */
	public function update_db( $pro = false ) {
		if ( ! self::$is_plugin_elementor_installed ) {
			return false;
		}
		try {
			return $this->do_db_upgrade( $pro );
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
	 * @param bool $pro Pro version or not.
	 *
	 * @return array Action result.
	 */
	protected function get_update_db_manager_class( $pro = false ) {
		if ( $pro ) {
			return '\ElementorPro\Core\Upgrade\Manager';
		}
		return '\Elementor\Core\Upgrade\Manager';
	}

	/**
	 * Method do_db_upgrade()
	 *
	 * Do DB upgrade.
	 *
	 * @param bool $pro Pro version or not.
	 *
	 * @return array Action result.
	 */
	protected function do_db_upgrade( $pro = false ) {
		$manager_class = $this->get_update_db_manager_class( $pro );

		MainWP_Helper::instance()->check_classes_exists( array( $manager_class, '\Elementor\Plugin' ) );

		// core\upgrade\manager.php.
		// var \Elementor\Core\Upgrade\Manager $manager.
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

