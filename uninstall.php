
<?php
/**
 * MainWP Child Uninstall
 *
 * Uninstalling mainwp child deletes options.
 *
 * @author      mainwp
 * @category    Core
 * @package     mainwp-child/Uninstaller
 * @version     3.4
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Delete options.
$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE 'mainWPChild\_%';" );
