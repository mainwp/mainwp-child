<?php
/**
 * Sync changes logs data.
 *
 * @package    mainwp/child
 *
 * @since 5.4.1
 */

declare(strict_types=1);

namespace MainWP\Child\Changes\Helpers;

use MainWP\Child\Changes\Changes_Base_Fields;
use MainWP\Child\Changes\Entities\Changes_Logs_Entity;
use MainWP\Child\Changes\Changes_Logs_Manager;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

/**
 * Responsible for all the mainWP operations.
 */
class Changes_Sync_Data_Helper {

    /**
     * Return alerts for MainWP Extension.
     *
     * @param integer $limit      - Number of alerts to retrieve.
     * @param array   $query_args - Events query arguments, otherwise false.
     *
     * @return mixed
     */
    public static function get_events_data( $limit = 100, $query_args = array() ) {
        $mwp_events = array();

        // Check if limit is not empty.
        if ( empty( $limit ) ) {
            return $mwp_events;
        }

        $extra         = '';
        $search_string = '';

        if ( isset( $query_args['site_url'] ) && Changes_WP_Helper::is_multisite() ) {

            $sites_urls = Changes_WP_Helper::get_site_urls();

            if ( ! empty( $sites_urls ) && isset( $sites_urls[ $query_args['site_url'] ] ) ) {
                $site_id = $sites_urls[ $query_args['site_url'] ];

                $search_string .= ' site_id:' . (int) $site_id;
            }
        }

        $records = $limit;

        $where_clause = Changes_Base_Fields::string_to_search( $search_string );

        if ( '' !== trim( $where_clause ) ) {
            $extra = ' AND ' . $where_clause;
        }

        if ( isset( $query_args['newer_than'] ) ) {
            $extra = ' AND created_on > ' . $query_args['newer_than'] . $extra;
        }

        if ( isset( $query_args['older_than'] ) ) {
            $extra = ' AND created_on < ' . $query_args['older_than'] . $extra;
        }

        $events = Changes_Logs_Entity::load_array( '%d', array( 1 ), null, $extra . ' ORDER BY site_id, created_on DESC LIMIT ' . $records );

        $events = Changes_Logs_Entity::get_multi_meta_array( $events );

        if ( ! empty( $events ) && is_array( $events ) ) {
            foreach ( $events as &$event ) {
                // Get event meta.
                $event['meta_values']['UserData'] = Changes_Logs_Manager::get_event_user_data( Changes_User_Utils::get_username( $event['meta_values'] ) );
                $event['meta_data']               = $event['meta_values'];
                unset( $event['meta_values'] );

                // compatible data.
                $system_user = '';
                $agent       = '';

                if ( 'cron-job' === $event['object'] ) {
                    $system_user = 'wp_cron';
                    $agent       = 'wp_cron';
                } elseif ( $event['user_id'] ) {
                    $system_user = $event['username'];
                }

                $user_meta                       = array(
                    'wp_user_id'   => $event['user_id'] ? (int) $event['user_id'] : 0,
                    'display_name' => (string) $event['username'],
                    'user_roles'   => (string) $event['user_roles'],
                    'agent'        => (string) $agent,
                );
                $user_meta['action_user']        = $event['user_id'] ? (string) $event['username'] : $system_user;
                $event['meta_data']['user_meta'] = $user_meta;
            }
            unset( $event );
        }
        return $events;
    }
}
