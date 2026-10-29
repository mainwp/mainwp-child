<?php
/**
 * Class SmokeTest
 *
 * @package Mainwp_Child
 */

/**
 * Smoke test: verifies the test harness boots WordPress and loads the plugin.
 */
class SmokeTest extends WP_UnitTestCase {

	/**
	 * The plugin bootstrap file is loaded.
	 */
	public function test_plugin_is_loaded() {
		$this->assertTrue( defined( 'MAINWP_CHILD_FILE' ), 'MAINWP_CHILD_FILE should be defined by mainwp-child.php' );
		$this->assertTrue( defined( 'MAINWP_CHILD_PLUGIN_DIR' ), 'MAINWP_CHILD_PLUGIN_DIR should be defined by mainwp-child.php' );
	}

	/**
	 * Core plugin classes are autoloadable.
	 */
	public function test_core_classes_exist() {
		$this->assertTrue( class_exists( '\MainWP\Child\MainWP_Child' ), 'MainWP_Child class should exist' );
		$this->assertTrue( class_exists( '\MainWP\Child\MainWP_Utility' ), 'MainWP_Utility class should exist' );
		$this->assertTrue( class_exists( '\MainWP\Child\MainWP_Backup' ), 'MainWP_Backup class should exist' );
		$this->assertTrue( class_exists( '\MainWP\Child\MainWP_Helper' ), 'MainWP_Helper class should exist' );
	}

	/**
	 * The WordPress test environment provides a usable database.
	 */
	public function test_wordpress_environment() {
		global $wpdb;
		$this->assertNotEmpty( $wpdb->prefix );
		$this->assertSame( 1, (int) $wpdb->get_var( 'SELECT 1' ) );
	}
}
