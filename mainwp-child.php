<?php
/*
  Plugin Name: MainWP Child
  Plugin URI: https://mainwp.com/
  Description: Provides a secure connection between your MainWP Dashboard and your WordPress sites. MainWP allows you to manage WP sites from one central location. Plugin documentation and options can be found here https://mainwp.com/help/
  Author: MainWP
  Author URI: https://mainwp.com
  Text Domain: mainwp-child
  Version: 4.0.6.2
 */
include_once( ABSPATH . 'wp-includes' . DIRECTORY_SEPARATOR . 'version.php' ); //Version information from wordpress

define( 'MAINWP_DEBUG', FALSE );

if ( ! defined( 'MAINWP_CHILD_FILE' ) ) {
	define( 'MAINWP_CHILD_FILE', __FILE__ );
}

if ( ! defined( 'MAINWP_CHILD_URL' ) ) {
	define( 'MAINWP_CHILD_URL', plugin_dir_url( MAINWP_CHILD_FILE ) );
}

function mainwp_child_autoload( $class_name ) {
	$autoload_dir  = \trailingslashit( dirname( __FILE__ ) . '/class' );
	$autoload_path = sprintf( '%sclass-%s.php', $autoload_dir, strtolower( str_replace( '_', '-', $class_name ) ) );

	if ( file_exists( $autoload_path ) ) {
		require_once( $autoload_path );
	}
}

if ( function_exists( 'spl_autoload_register' ) ) {
	spl_autoload_register( 'mainwp_child_autoload' );
}

$mainWPChild = new MainWP_Child( WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . plugin_basename( __FILE__ ) );
register_activation_hook( __FILE__, array( $mainWPChild, 'activation' ) );
register_deactivation_hook( __FILE__, array( $mainWPChild, 'deactivation' ) );
