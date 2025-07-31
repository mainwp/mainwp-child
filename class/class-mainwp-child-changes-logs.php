<?php
/**
 * MainWP Child Changes Logs.
 *
 * @package MainWP\Child
 *
 * @since 5.4.1
 */

namespace MainWP\Child;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

/**
 * Class MainWP_Child_Changes_Logs
 */
class MainWP_Child_Changes_Logs {

    /**
     * Public static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    protected static $instance = null;

    /**
     * Method get_class_name()
     *
     * Get class name.
     *
     * @return string __CLASS__ Class name.
     */
    public static function get_class_name() {
        return __CLASS__;
    }


    /**
     * Method get_changes_data().
     *
     * @return mixed
     *
     * @since 5.4.1
     */
    public static function get_changes_data() {
        $params = ! empty( $_POST['params_logs'] ) && is_array( $_POST['params_logs'] ) ?  $_POST['params_logs'] : array(); //phpcs:ignore -- ok.

        if ( empty( $params ) ) {
            return '';
        }

        $limit = isset( $params['events_count'] ) ? $params['events_count'] : false;

        if ( empty( $limit ) ) {
            return '';
        }

        if ( class_exists( '\MainWP\Child\Changes\Changes_Sync_Data_Helper' ) ) {
            return \MainWP\Child\Changes\Changes_Sync_Data_Helper::get_events_data( $limit, $params );
        }
        return '';
    }
}
