<?php
/**
 * MainWP Child Plugin
 *
 * @package MainWP\Child
 */

/**
 * Plugin Name: MainWP Child
 * Description: Provides a secure connection between your MainWP Dashboard and your WordPress sites. MainWP allows you to manage WP sites from one central location. Plugin documentation and options can be found here https://kb.mainwp.com/.
 * Plugin URI: https://mainwp.com/
 * Author: MainWP
 * Author URI: https://mainwp.com
 * Text Domain: mainwp-child
 * Version: 4.4.1.1
 * Requires at least: 5.4
 * Requires PHP: 7.4
 */

require_once ABSPATH . 'wp-includes' . DIRECTORY_SEPARATOR . 'version.php'; // Version information from WordPress.

/**
 * Define MainWP Child Plugin Debug Mode.
 *
 * @const ( bool ) Whether or not MainWP Child is in debug mode. Default: false.
 * @source https://code-reference.mainwp.com/classes/MainWP.Child.MainWP_Child.html
 */
if ( ! defined( 'MAINWP_CHILD_DEBUG' ) ) {
	define( 'MAINWP_CHILD_DEBUG', false );
}

if ( ! defined( 'MAINWP_CHILD_FILE' ) ) {

	/**
	 * Define MainWP Child Plugin absolute full path and filename of this file.
	 *
	 * @const ( string ) Defined MainWP Child file path.
	 * @source https://github.com/mainwp/mainwp-child/blob/master/mainwp-child.php
	 */
	define( 'MAINWP_CHILD_FILE', __FILE__ );
}

if ( ! defined( 'MAINWP_CHILD_PLUGIN_DIR' ) ) {

	/**
	 * Define MainWP Child Plugin Directory.
	 *
	 * @const ( string ) Defined MainWP Child Plugin Directory.
	 * @source https://github.com/mainwp/mainwp-child/blob/master/mainwp-child.php
	 */
	define( 'MAINWP_CHILD_PLUGIN_DIR', plugin_dir_path( MAINWP_CHILD_FILE ) );
}

if ( ! defined( 'MAINWP_CHILD_URL' ) ) {

	/**
	 * Define MainWP Child Plugin URL.
	 *
	 * @const ( string ) Defined MainWP Child Plugin URL.
	 * @source https://github.com/mainwp/mainwp-child/blob/master/mainwp-child.php
	 */
	define( 'MAINWP_CHILD_URL', plugin_dir_url( MAINWP_CHILD_FILE ) );
}

/**
 * MainWP Child Plugin Autoloader to load all other class files.
 *
 * @param string $class_name Name of the class to load.
 *
 * @uses \MainWP\Child\MainWP_Child()
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
