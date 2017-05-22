<?php
/**
 * MainWP Child Uninstall
 *
 * Uninstalling mainwp child deletes options.
 *
 * @author      MainWP
 * @category    Core
 * @package     mainwp-child/Uninstaller
 * @version     3.4
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Delete options.
$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE 'mainWPChild\_%';" );


// Clear any cached data that has been removed
wp_cache_flush();
