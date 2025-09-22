<?php
/**
 * Sync changes logs data.
 *
 * @package    mainwp/child
 *
 * @since 5.5
 */

namespace MainWP\Child\Changes;

defined( 'ABSPATH' ) || exit; // Exit.

/**
 * Handle for all the mainWP operations.
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
        $logs_data = array();

        if ( empty( $limit ) ) {
            return $logs_data;
        }

        global $wpdb;

        $extra = '';

        $records = $limit;

        if ( isset( $query_args['newer_than'] ) ) {
            $extra .= $wpdb->prepare( ' AND created_on > %f ', $query_args['newer_than'] );
        }

        if ( isset( $query_args['older_than'] ) ) {
            $extra .= $wpdb->prepare( ' AND created_on < %f ', $query_args['older_than'] );
        }

        $ignore_sync_logs = get_option( 'mainwp_child_ignored_changes_logs' );
        if ( false !== $ignore_sync_logs ) {
            $ignore_sync_logs = json_decode( $ignore_sync_logs, true );
            if ( ! empty( $ignore_sync_logs ) && is_array( $ignore_sync_logs ) ) {
                // Make placeholders: "%d,%d,%d".
                $placeholders = implode( ',', array_fill( 0, count( $ignore_sync_logs ), '%d' ) );
                if ( ! empty( $placeholders ) ) {
                    // Build query safely.
                    $extra .= $wpdb->prepare( " AND log_type_id NOT IN( $placeholders) ", ...$ignore_sync_logs ); //phpcs:ignore --ok.
                }
            }
        }

        $logs_data = Changes_Logs_DB_Log::instance()->load_logs_array( '%d', array( 1 ), $extra . ' ORDER BY blog_id, created_on ASC LIMIT ' . $records );
        $logs_data = Changes_Logs_DB_Log::instance()->get_log_meta_data( $logs_data );

        if ( ! empty( $logs_data ) && is_array( $logs_data ) ) {
            foreach ( $logs_data as &$log ) {
                $log['meta_data'] = $log['meta_values'];
                unset( $log['meta_values'] );

                // Compatible data.
                $system_user = '';
                $agent       = '';

                if ( 'cron-job' === $log['context'] ) {
                    $system_user = 'wp_cron';
                    $agent       = 'wp_cron';
                } elseif ( $log['user_id'] ) {
                    $system_user = $log['username'];
                }

                $user_meta                     = array(
                    'wp_user_id'   => $log['user_id'] ? (int) $log['user_id'] : 0,
                    'display_name' => (string) $log['username'],
                    'agent'        => (string) $agent,
                );
                $user_meta['action_user']      = $log['user_id'] ? (string) $log['username'] : $system_user;
                $log['meta_data']['user_meta'] = $user_meta;
            }
            unset( $log );
        }
        return $logs_data;
    }


    /**
     * Gets the username.
     *
     * @param array $meta - Event meta data.
     *
     * @return string User's username.
     */
    public static function get_username( $meta = null ) {
        if ( ! is_array( $meta ) ) {
            return '';
        }

        if ( isset( $meta['username'] ) ) {
            return $meta['username'];
        } elseif ( isset( $meta['currentuserid'] ) ) {
            $data = \get_userdata( $meta['currentuserid'] );

            return $data ? $data->user_login : '';
        }

        return '';
    }
}
