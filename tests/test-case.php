<?php
/**
 * Base test case for MainWP Dashboard.
 *
 * @package Mainwp_Child
 * @author  MainWP
 */

class TestCase extends MainWP_Unit_Test_Case {

	/**
	 * Emulate deactivating, then subsequently reactivating the plugin.
	 */
	protected static function reactivate_plugin() {
		$plugin = basename( dirname( __DIR__ ) ) . '/mainwp-child.php';

		do_action( 'deactivate_' . $plugin, false );
		do_action( 'activate_' . $plugin, false );
	}
}
