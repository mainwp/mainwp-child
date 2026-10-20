<?php
/**
 * PHPUnit bootstrap file
 *
 * @package Mainwp_Child
 */

// Load Composer autoloader (PHPUnit polyfills + test classes).
$_composer_autoload = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( ! file_exists( $_composer_autoload ) ) {
	echo 'Could not find vendor/autoload.php, have you run composer install?' . PHP_EOL;
	exit( 1 );
}
require_once $_composer_autoload;

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find $_tests_dir/includes/functions.php, have you run bin/install-wp-tests.sh ?" . PHP_EOL; // WPCS: XSS ok.
	exit( 1 );
}

// Give access to tests_add_filter() function.
require_once $_tests_dir . '/includes/functions.php';

/**
 * Manually load the plugin being tested.
 */
function _manually_load_plugin() {
	// WordPress includes plugins at file scope, so the plugin's lazy instance is a global there.
	global $mainWPChild;
	require dirname( dirname( __FILE__ ) ) . '/mainwp-child.php';
}
tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';
