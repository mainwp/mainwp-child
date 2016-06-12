<?php
/*
  Plugin Name: MainWP Child
  Plugin URI: http://mainwp.com/
  Description: Child Plugin for MainWP. The plugin is used so the installed blog can be securely managed remotely by your network. Plugin documentation and options can be found here http://docs.mainwp.com
  Author: MainWP
  Author URI: http://mainwp.com
  Text Domain: mainwp-child
  Version: 3.1.5
 */
if ( ( isset( $_REQUEST['heatmap'] ) && '1' === $_REQUEST['heatmap'] ) || ( isset( $_REQUEST['mainwpsignature'] ) && ( ! empty( $_REQUEST['mainwpsignature'] ) ) ) ) {
	header( 'X-Frame-Options: ALLOWALL' );
}
//header('X-Frame-Options: GOFORIT');
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
} else {
	function __autoload( $class_name ) {
		mainwp_child_autoload( $class_name );
	}
}

$mainWPChild = new MainWP_Child( WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . plugin_basename( __FILE__ ) );
register_activation_hook( __FILE__, array( $mainWPChild, 'activation' ) );
register_deactivation_hook( __FILE__, array( $mainWPChild, 'deactivation' ) );
