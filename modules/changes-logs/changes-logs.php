<?php
/**
 * MainWP Child Changes Logs
 *
 * @since 5.4.1
 * @package MainWP/Child
 */

if ( ! defined( 'CHANGES_LOGS_MODULE_BASE_DIR' ) ) {
    define( 'CHANGES_LOGS_MODULE_BASE_DIR', MAINWP_CHILD_MODULES_DIR . 'changes-logs/' );
}

if ( ! function_exists( 'mainwp_child_changes_logs_get_classes_list' ) ) {
    /**
     * Get changes logs classes to load.
     *
     * @since 5.4.1
     *
     * @param  string $group Group of classes
     * @param  string $type Get type
     *
     * @return array
     */
    function mainwp_child_changes_logs_get_classes_list( $group, $type = 'class' ) {
        switch ( $group ) {
            case 'Loggers':
                $classes = array(
                    'wp-comments'     => 'MainWP\Child\Changes\Loggers\Changes_WP_Comments_Logger',
                    'wp-content'      => 'MainWP\Child\Changes\Loggers\Changes_WP_Content_Logger',
                    'wp-files'        => 'MainWP\Child\Changes\Loggers\Changes_WP_Files_Logger',
                    'wp-menus'        => 'MainWP\Child\Changes\Loggers\Changes_WP_Menus_Logger',
                    'wp-metadata'     => 'MainWP\Child\Changes\Loggers\Changes_WP_MetaData_Logger',
                    'wp-multisite'    => 'MainWP\Child\Changes\Loggers\Changes_WP_Multisite_Logger',
                    'wp-register'     => 'MainWP\Child\Changes\Loggers\Changes_WP_Register_Logger',
                    'wp-system'       => 'MainWP\Child\Changes\Loggers\Changes_WP_System_Logger',
                    'wp-user-profile' => 'MainWP\Child\Changes\Loggers\Changes_WP_User_Profile_Logger',
                    'wp-widgets'      => 'MainWP\Child\Changes\Loggers\Changes_WP_Widgets_Logger',
                    'bbpress-user'    => 'MainWP\Child\Changes\Loggers\Changes_BBPress_User_Logger',
                    'wp-log-in-out'   => 'MainWP\Child\Changes\Loggers\Changes_WP_Log_In_Out_Logger',
                    'wp-database'     => 'MainWP\Child\Changes\Loggers\Changes_WP_Database_Logger',
                );
                break;
            case 'Plugin_Loggers':
            case 'Alerts':
            default:
                $classes = array();
                break;

        }

        if ( 'class' === $type ) {
            return array_values( $classes );
        }
        return $classes;
    }
}

if ( class_exists( '\MainWP\Child\Changes\Changes_Logs' ) ) {
    \MainWP\Child\Changes\Changes_Logs::instance();
}
