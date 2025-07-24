<?php
/**
 * MainWP Child Changes Logs
 *
 * @since 5.5
 * @package MainWP/Child
 */

// Exit.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'CHANGES_LOGS_MODULE_BASE_DIR' ) ) {
    define( 'CHANGES_LOGS_MODULE_BASE_DIR', MAINWP_CHILD_MODULES_DIR . 'changes-logs/' );
}

if ( ! function_exists( 'mainwp_child_changes_logs_get_handler' ) ) {
    /**
     * Get changes logs connector to load.
     *
     * @param  string $type Get type
     *
     * @return array
     */
    function mainwp_child_changes_logs_get_handler( $type = 'class' ) {
        $handlers = array(
            'wp-comments'       => 'MainWP\Child\Changes\Changes_Handle_WP_Comments',
            'wp-content'        => 'MainWP\Child\Changes\Changes_Handle_WP_Content',
            'wp-files'          => 'MainWP\Child\Changes\Changes_Handle_WP_Files',
            'wp-menus'          => 'MainWP\Child\Changes\Changes_Handle_WP_Menus',
            'wp-metadata'       => 'MainWP\Child\Changes\Changes_Handle_WP_MetaData',
            'wp-multisite'      => 'MainWP\Child\Changes\Changes_Handle_WP_Multisite',
            'wp-register'       => 'MainWP\Child\Changes\Changes_Handle_WP_Register',
            'wp-system'         => 'MainWP\Child\Changes\Changes_Handle_WP_System',
            'wp-user-profile'   => 'MainWP\Child\Changes\Changes_Handle_WP_User_Profile',
            'wp-widgets'        => 'MainWP\Child\Changes\Changes_Handle_WP_Widgets',
            'bbpress-user'      => 'MainWP\Child\Changes\Changes_Handle_BBPress_User',
            'wp-log-in-out'     => 'MainWP\Child\Changes\Changes_Handle_WP_Log_In_Out',
            'wp-database'       => 'MainWP\Child\Changes\Changes_Handle_WP_Database',
            'wp-plugins-themes' => 'MainWP\Child\Changes\Changes_Handle_WP_Plugins_Themes',
            'wp-mainwp'         => 'MainWP\Child\Changes\Changes_Handle_WP_MainWP',
        );

        if ( 'class' === $type ) {
            return array_values( $handlers );
        }
        return $handlers;
    }
}

if ( class_exists( '\MainWP\Child\Changes\Changes_Logs' ) ) {
    \MainWP\Child\Changes\Changes_Logs::instance();
}
