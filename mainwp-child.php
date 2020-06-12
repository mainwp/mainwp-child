<?php
/**
 * Plugin Name: MainWP Child
 *
 * Description: Provides a secure connection between your MainWP Dashboard and your WordPress sites. MainWP allows you to manage WP sites from one central location.
 *
 * Plugin URI: https://mainwp.com/
 * Description: Provides a secure connection between your MainWP Dashboard and your WordPress sites. MainWP allows you to manage WP sites from one central location. Plugin documentation and options can be found here https://mainwp.com/help/
 * Author: MainWP
 * Author URI: https://mainwp.com
 * Text Domain: mainwp-child
 * Version: 4.0.7.1
 *
 * @package MainWP\Child
 */

require_once ABSPATH . 'wp-includes' . DIRECTORY_SEPARATOR . 'version.php'; // Version information from WordPress.

/**
 * Define MainWP Child Plugin Debug Mode. Default: false.
 */
define( 'MAINWP_CHILD_DEBUG', false );

if ( ! defined( 'MAINWP_CHILD_FILE' ) ) {
	/**
	 * Define MainWP Child Plugin absolute full path and filename of this file.
	 */
	define( 'MAINWP_CHILD_FILE', __FILE__ );
}

if ( ! defined( 'MAINWP_CHILD_PLUGIN_DIR' ) ) {
	/**
	 * Define MainWP Child Plugin Directory.
	 */
	define( 'MAINWP_CHILD_PLUGIN_DIR', plugin_dir_path( MAINWP_CHILD_FILE ) );
}

if ( ! defined( 'MAINWP_CHILD_URL' ) ) {
	/**
	 * Define MainWP Child Plugin URL.
	 */
	define( 'MAINWP_CHILD_URL', plugin_dir_url( MAINWP_CHILD_FILE ) );
}

/**
 * MainWP Child Plugin Autoloader to load all other class files.
 *
 * @param string $class_name Name of the class to load.
 */
function mainwp_child_autoload( $class_name ) {

	if ( 0 === strpos( $class_name, 'MainWP\Child' ) ) {
		// strip the namespace prefix: MainWP\Child\ .
		$class_name = substr( $class_name, 13 );
	}

	$autoload_dir  = \trailingslashit( dirname( __FILE__ ) . '/class' );
	$autoload_path = sprintf( '%sclass-%s.php', $autoload_dir, strtolower( str_replace( '_', '-', $class_name ) ) );

	if ( file_exists( $autoload_path ) ) {
		require_once $autoload_path;
	}
}

if ( function_exists( 'spl_autoload_register' ) ) {
	spl_autoload_register( 'mainwp_child_autoload' );
}

require_once MAINWP_CHILD_PLUGIN_DIR . 'includes' . DIRECTORY_SEPARATOR . 'functions.php';

$mainWPChild = new MainWP\Child\MainWP_Child( WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . plugin_basename( __FILE__ ) );
register_activation_hook( __FILE__, array( $mainWPChild, 'activation' ) );
register_deactivation_hook( __FILE__, array( $mainWPChild, 'deactivation' ) );
