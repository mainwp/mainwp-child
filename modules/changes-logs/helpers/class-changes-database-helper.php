<?php
/**
 * Class: MySQL Logger
 *
 * Logger class.
 *
 * @since      5.4.1
 * @package    mainwp/child
 *
 * @author Stoil Dobrev <sdobreff@gmail.com>
 */

namespace MainWP\Child\Changes\Helpers;

use MainWP\Child\MainWP_Child_DB_Base;
use MainWP\Child\Changes\Entities\Changes_Logs_Entity;


// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * This class stores the logs in the database and there is also the function to clean up alerts.
 */
class Changes_Database_Helper {

    /**
     * Log an event to the database.
     *
     * There is no difference between local and external database handling os of version 4.3.2.
     *
     * @param integer $type    - Alert code.
     * @param array   $data    - Metadata.
     * @param integer $date    - (Optional) created_on.
     * @param integer $site_id - (Optional) site_id.
     */
    public static function log( $type, $data = array(), $date = null, $site_id = null ) {
        // PHP alerts logging was deprecated in version 4.2.0.
        if ( $type < 0010 ) {
            return;
        }

        // We need to remove the timestamp to prevent from saving it as meta.
        unset( $data['Timestamp'] );

        $logger_db    = MainWP_Child_DB_Base::instance()->get_connection(); // Get DB connection.
        $connection = true;
        if ( isset( $logger_db->dbh->errno ) ) {
            $connection = ( 0 === (int) $logger_db->dbh->errno ); // Database connection error check.
        } elseif ( is_wp_error( $logger_db->error ) ) {
            $connection = false;
        }

        // Check DB connection.
        if ( $connection ) { // If connected then save the alert in DB.
            $site_id = ! is_null( $site_id ) ? $site_id : ( function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 0 );

            $site_id = \apply_filters( 'mainwp_child_changes_logs_database_site_id_value', $site_id, $type, $data );

            Changes_Logs_Entity::store_record(
                $data,
                $type,
                self::get_correct_timestamp( $data, $date ),
                ! is_null( $site_id ) ? $site_id : ( function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 0 )
            );

        }

        /**
         * Fires immediately after an alert is logged.
         *
         */
        do_action( 'mainwp_child_changes_logs_logged_alert', null, $type, $data, $date, $site_id );
    }


    /**
     * Determines what is the correct timestamp for the event.
     *
     * It uses the timestamp from metadata if available. This is needed because we introduced a possible delay by using
     * action scheduler in 4.3.0. The $legacy_date attribute is only used for migration of legacy data. This should be
     * removed in future releases.
     *
     * @param array $metadata    Event metadata.
     * @param int   $legacy_date Legacy date only used when migrating old db event format to the new one.
     *
     * @return float GMT timestamp including microseconds.
     * @since 4.6.0
     */
    protected static function get_correct_timestamp( $metadata, $legacy_date ) {

        if ( is_null( $legacy_date ) ) {
            $timestamp = current_time( 'U.u', true );

            $timestamp = \apply_filters( 'mainwp_child_changes_logs_database_timestamp_value', $timestamp, $metadata );

            return array_key_exists( 'Timestamp', $metadata ) ? $metadata['Timestamp'] : $timestamp;
        }

        return floatval( $legacy_date );
    }
}
