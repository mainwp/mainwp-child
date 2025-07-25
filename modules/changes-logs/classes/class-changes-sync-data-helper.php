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

        $extra = '';

        $records = $limit;

        if ( isset( $query_args['newer_than'] ) ) {
            $extra = ' AND created_on > ' . $query_args['newer_than'] . $extra;
        }

        if ( isset( $query_args['older_than'] ) ) {
            $extra = ' AND created_on < ' . $query_args['older_than'] . $extra;
        }

        $logs_data = Changes_Logs_DB_Log::instance()->load_logs_array( '%d', array( 1 ), $extra . ' ORDER BY blog_id, created_on DESC LIMIT ' . $records );
        $logs_data = Changes_Logs_DB_Log::instance()->get_log_meta_data( $logs_data );

        if ( ! empty( $logs_data ) && is_array( $logs_data ) ) {
            foreach ( $logs_data as &$log ) {
                $log['meta_values']['userdata'] = Changes_Logs_Logger::get_log_user_data( static::get_username( $log['meta_values'] ) );
                $log['meta_data']               = $log['meta_values'];
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
                    'user_roles'   => (string) $log['user_roles'],
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
