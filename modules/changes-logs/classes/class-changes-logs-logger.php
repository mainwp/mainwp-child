<?php
/**
 * Changes Logs Class
 *
 * @since 5.5
 *
 * @package MainWP\Child
 */

namespace MainWP\Child\Changes;

use MainWP\Child\MainWP_Child_DB_Base;

// Exit.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Changes_Logs_Logger
 */
class Changes_Logs_Logger { //phpcs:ignore -- NOSONAR -ok.


    /**
     * Contains a list of logs to trigger.
     *
     * @var array
     */
    private static $delay_logs_items = array();

    /**
     * The array with the excluded post types.
     *
     * @var array
     */
    private static $ignored_post_types = array();

    /**
     * An array with all the registered logs.
     *
     * @var array
     */
    private static $logs = array();

    /**
     * Contains an array of logs.
     *
     * @var int[]
     */
    private static $logs_type_queue = array();

    /**
     * A cached value if the checked logs which were recently fired.
     *
     * @var array
     */
    private static $cached_log_checks = array();

    /**
     * WP Users.
     *
     * Store WP Users for caching purposes.
     *
     * @var WP_User[]
     */
    private static $wp_users = array();

    /**
     * Initializes the class and adds the hooks.
     *
     * @return void
     */
    public static function init_hooks() {
        \add_action( 'shutdown', array( __CLASS__, 'change_process_delay_logs_items' ), 8 );
    }

    /**
     * Log event.
     *
     * @param int   $type_id    - Log type.
     * @param array $data    - Log data.
     */
    public static function log_change( $type_id, $data = array() ) {
        if ( \mainwp_child_is_dashboard_request() ) {
            return;
        }

        if ( isset( $data['posttype'] ) && ! empty( $data['posttype'] ) && static::is_disabled_post_type( $data['posttype'] ) ) {
            return;
        }

        $data = static::prepare_log_data( $data );

        static::log_item( $type_id, $data, false );
    }

    /**
     * Log event delayed.
     *
     * @param int   $type_id  - Log type.
     * @param array $data    - Log data.
     */
    public static function log_change_save_delay( $type_id, $data = array() ) {
        if ( \mainwp_child_is_dashboard_request() ) {
            return;
        }

        $data = static::prepare_log_data( $data );

        self::$delay_logs_items[] = array(
            'type_id' => $type_id,
            'data'    => $data,
        );
    }

    /**
     * Log delayed, trigger only if a condition is met.
     *
     * @param int $type_id    - Log type.
     *
     * @return bool Avoid or do the log.
     */
    public static function check_conditions_delayed_logs( $type_id ) {

        if ( ! is_scalar( $type_id ) ) {
            return false;
        }

        $enable_to_log = true;

        switch ( true ) {
            case 1615 === $type_id:
                $check_logs = array( 1670, 1665, 1660, 1605, 1610 );
                foreach ( $check_logs as $check_id ) {
                    if ( static::is_delayed_log_type_exist( $check_id ) ) {
                        $enable_to_log = false;
                        break;
                    }
                }
                break;
            case in_array( $type_id, array( 1645, 1670, 1665 ), true ):
                $enable_to_log = ! static::is_delayed_log_type_exist( 1660 );
                break;
            case 1210 === $type_id:
                $check_logs = Changes_Logs_Helper::get_post_change_logs_events();
                foreach ( $check_logs as $check_id ) {
                    if ( static::in_queue_or_handled_logs( $check_id ) || static::was_handled_recently( $check_id ) ) {
                        $enable_to_log = false;
                        break;
                    }
                }
                break;
            case 1570 === $type_id:
                $enable_to_log = ! static::is_delayed_log_type_exist( 1625 ) && ! static::is_delayed_log_type_exist( 1570 ) && ! static::is_delayed_log_type_exist( 1580 );
                break;
            case 1355 === $type_id:
                $enable_to_log = ! static::is_delayed_log_type_exist( 1345 ) && ! static::is_delayed_log_type_exist( 1350 );
                break;
            case 1690 === $type_id:
                $enable_to_log = ! static::is_delayed_log_type_exist( 1610 ) && ! static::is_delayed_log_type_exist( 1615 );
                break;
            case 1685 === $type_id:
                $enable_to_log = ! static::is_delayed_log_type_exist( 1615 );
                break;
            case 1605 === $type_id:
                $enable_to_log = ! Changes_Helper::is_multisite() || ! static::is_delayed_log_type_exist( 1675 );
                break;
            default:
                break;
        }

        return $enable_to_log;
    }

    /**
     * Prepare log data.
     *
     * @param array $data    - Log data.
     *
     * @return array - Log data.
     */
    public static function prepare_log_data( $data = array() ) {
        if ( apply_filters( 'mainwp_child_changes_logs_disable_user_switching_plugin_tracking', false ) && class_exists( '\user_switching' ) ) {
            $old_user = \user_switching::get_old_user();
            if ( isset( $old_user->user_login ) ) {
                $data['username']      = $old_user->user_login;
                $data['currentuserid'] = $old_user->ID;
            }
        }
        if ( isset( $old_user ) && false !== $old_user ) {
            $roles                    = Changes_Helper::get_user_roles( $old_user );
            $data['currentuserroles'] = $roles;
        }

        $data['log_timestamp'] = ( isset( $data['log_timestamp'] ) && ! empty( $data['log_timestamp'] ) ) ? $data['log_timestamp'] : current_time( 'U.u', 'true' );

        return $data;
    }

    /**
     * Check post type is disabled or not.
     *
     * @param string $post_type - Post type.
     *
     * @return bool - True if disabled, False if otherwise.
     */
    public static function is_disabled_post_type( $post_type ) {
        return in_array( $post_type, self::get_ignored_post_types(), true );
    }


    /**
     * Returns all the ignored post types - post types are \WP_Post types
     */
    public static function get_ignored_post_types() {
        if ( empty( self::$ignored_post_types ) ) {
            /*
            * Filter: `mainwp_child_changes_logs_ignored_post_types`
            *
            * Ignored custom post types filter.
            *
            */
            self::$ignored_post_types = apply_filters( 'mainwp_child_changes_logs_ignored_post_types', Changes_Helper::IGNORED_POST_TYPES );
        }

        if ( ! is_array( self::$ignored_post_types ) ) {
            self::$ignored_post_types = array();
        }

        return self::$ignored_post_types;
    }


    /**
     * Method: log item.
     *
     * @param int   $type_id   - Log type.
     * @param array $data   - Data of the log.
     * @param bool  $is_delayed   - Whether it is delayed.
     * @param bool  $_retry - Retry.
     *
     * @return mixed
     *
     * @internal
     */
    protected static function log_item( $type_id, $data, $is_delayed, $_retry = true ) {
        if ( ( ! $is_delayed || static::check_conditions_delayed_logs( $type_id ) ) && static::is_enabled( $type_id ) ) {
            if ( isset( self::get_logs()[ $type_id ] ) ) {
                self::$logs_type_queue[] = $type_id;
                self::log( $type_id, $data );
            } elseif ( $_retry ) {
                return static::log_item( $type_id, $data, $is_delayed, false );
            }
        }
    }

    /**
     * Returns whether log is enabled or not.
     *
     * @param int $type_id Log type.
     *
     * @return bool True if enabled, false otherwise.
     */
    public static function is_enabled( $type_id ) {
        $disabled_types = Changes_Helper::get_disabled_logs_types();
        return ! in_array( $type_id, $disabled_types, true );
    }

    /**
     * Converts into a Log entry.
     *
     * @param int   $type_id   - Log type number.
     * @param array $log_data - Misc log data.
     */
    public static function log( $type_id, $log_data = array() ) {

        $log_obj = isset( self::get_logs()[ $type_id ] ) ? self::get_logs()[ $type_id ] : false;
        if ( empty( $log_obj ) ) {
            return;
        }

        if ( ! isset( $log_data['clientip'] ) ) {
            $client_ip = Changes_Helper::change_get_client_ip();
            if ( ! empty( $client_ip ) ) {
                $log_data['clientip'] = $client_ip;
            }
        }

        if ( ! isset( $log_data['useragent'] ) && isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
            $log_data['useragent'] = \sanitize_text_field( \wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
        }

        if ( ! isset( $log_data['username'] ) && ! isset( $log_data['currentuserid'] ) && function_exists( 'get_current_user_id' ) ) {
            $log_data['currentuserid'] = \get_current_user_id();
            if ( 0 !== $log_data['currentuserid'] ) {
                $user = \get_user_by( 'ID', $log_data['currentuserid'] );
                if ( \is_a( $user, '\WP_User' ) ) {
                    $log_data['username'] = $user->user_login;
                } else {
                    $log_data['username'] = 'Unknown User';
                }
            }
            if ( 0 === $log_data['currentuserid'] ) {
                if ( 'system' === strtolower( $log_obj['context'] ) ) {
                    $log_data['username'] = 'System';
                } elseif ( str_starts_with( strtolower( $log_obj['context'] ), 'woocommerce' ) ) { // User added/removed a product from an order.
                    $log_data['username'] = 'WooCommerce System';
                } else {
                    $log_data['username'] = 'Unknown User';
                }
            }
        }
        if ( isset( $log_data['currentuserid'] ) && ! isset( $log_data['username'] ) ) {
            if ( 0 === $log_data['currentuserid'] ) {
                if ( 'system' === strtolower( $log_obj['context'] ) ) {
                    $log_data['username'] = 'System';
                } elseif ( str_starts_with( strtolower( $log_obj['context'] ), 'woocommerce' ) ) {
                    $log_data['username'] = 'WooCommerce System';
                } else {
                    $log_data['username'] = 'Unknown User';
                }
            } else {
                $user = \get_user_by( 'ID', $log_data['currentuserid'] );
                if ( $user ) {
                    $log_data['username'] = $user->user_login;
                } else {
                    $log_data['username'] = 'Deleted';
                }
            }
        }
        if ( ! isset( $log_data['currentuserroles'] ) && function_exists( 'is_user_logged_in' ) && \is_user_logged_in() ) {
            $current_user_roles = Changes_Helper::get_user_roles();
            if ( ! empty( $current_user_roles ) ) {
                $log_data['currentuserroles'] = $current_user_roles;
            }
        }

        if ( $log_obj && ! isset( $log_data['context'] ) ) {
            $log_data['context'] = $log_obj['context'];
        }

        if ( $log_obj && ! isset( $log_data['actionname'] ) ) {
            $log_data['actionname'] = $log_obj['action_name'];
        }

        if ( Changes_Helper::is_multisite() ) {
            $log_data['bog_id']  = Changes_Helper::get_blog_id();
            $log_data['siteurl'] = get_site_url( $log_data['bog_id'] );
        }

        /**
         * Filter: `mainwp_child_changes_logs_type_id_before_log`.
         *
         * Filters event id before logging it to the database.
         *
         * @param int   $type_id   - Event ID.
         * @param array $log_data - Event data.
         */
        $type_id = \apply_filters( 'mainwp_child_changes_logs_type_id_before_log', $type_id, $log_data );

        /**
         * Filter: `mainwp_child_changes_logs_event_data_before_log`.
         *
         * Filters event data before logging it to the database.
         *
         * @param array $log_data - Event data.
         * @param int   $type_id   - Event ID.
         */
        $log_data = \apply_filters( 'mainwp_child_changes_logs_event_data_before_log', $log_data, $type_id );

        static::insert_log( $type_id, $log_data );
    }

    /**
     * Returns all the logs.
     */
    public static function get_logs() {
        if ( empty( self::$logs ) ) {
            static::register_logs();
        }

        if ( ! is_array( self::$logs ) ) {
            self::$logs = array();
        }

        return self::$logs;
    }


    /**
     * Method register_changes_logs().
     *
     * @return array data.
     */
    public static function register_logs() { //phpcs:ignore -- NOSONAR - long function.
        $defaults = Changes_Logs_Helper::get_changes_logs_types();
        foreach ( $defaults as $item ) {

            if ( ! is_array( $item ) ) {
                continue;
            }

            $type_id                = isset( $item['type_id'] ) ? $item['type_id'] : '';
            $object                 = isset( $item['context'] ) ? $item['context'] : '';
            $action_name            = isset( $item['action_name'] ) ? $item['action_name'] : '';
            self::$logs[ $type_id ] = array(
                'type_id'     => $type_id,
                'context'     => $object,
                'action_name' => $action_name,
            );
        }
    }

    /**
     * Method: Process delayed logs.
     */
    public static function change_process_delay_logs_items() {
        if ( \mainwp_child_is_dashboard_request() ) {
            return;
        }
        foreach ( self::$delay_logs_items as $key => $item ) {
            unset( self::$delay_logs_items[ $key ] );
            static::log_item( $item['type_id'], $item['data'], true );
            self::$delay_logs_items[ $key ] = $item;
        }
    }

    /**
     * Method: True if at the end of request an log of this type will be triggered.
     *
     * @param int $type_id  - Log type ID.
     */
    public static function is_delayed_log_type_exist( $type_id ) {
        foreach ( self::$delay_logs_items as $item ) {
            if ( $item['type_id'] === $type_id ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Method: True or False, if an log has been or will be triggered in this request.
     *
     * @param int $type_id  - Log type ID.
     *
     * @return bool
     */
    public static function in_queue_or_handled_logs( $type_id ) {
        return in_array( $type_id, self::$logs_type_queue, true ) || self::is_delayed_log_type_exist( $type_id );
    }

    /**
     * Method: True or False, if an log has been or will be triggered in this request.
     *
     * @param int $type_id  - Log type ID.
     *
     * @return bool
     */
    public static function has_handled_log( $type_id ) {
        return in_array( $type_id, self::$logs_type_queue, true );
    }

    /**
     * Check if the log was handled.
     *
     * @param integer|array $log_type_id - Log code.
     *
     * @return boolean
     */
    public static function was_handled( $log_type_id ) {

        $last_changes_logs = Changes_Logs_DB_Log::instance()->get_logs_data(
            array(
                'fields'   => array( 'tbllogs' => array( 'log_type_id' => 'log_type_id' ) ),
                'order_by' => ' created_on DESC ',
                'limit'    => 1,
            )
        );

        if ( ! empty( $last_changes_logs ) && isset( $last_changes_logs[0]['log_type_id'] ) ) {
            if ( ! is_array( $log_type_id ) && (int) $last_changes_logs[0]['log_type_id'] === (int) $log_type_id ) {
                return true;
            } elseif ( is_array( $log_type_id ) && in_array( (int) $last_changes_logs[0]['log_type_id'], $log_type_id, true ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the log was handled recently.
     *
     * Checks last 5 events.
     *
     * @param int|array $type_id - Log code.
     *
     * @return bool
     */
    public static function was_handled_recently( $type_id ) {
        if ( isset( self::$cached_log_checks ) && ( is_int( $type_id ) || is_string( $type_id ) ) && array_key_exists( $type_id, self::$cached_log_checks ) && self::$cached_log_checks[ $type_id ] ) {
            return true;
        }

        $last_changes_logs = static::get_latest_logs( 5 );

        $triggered_log = false;

        $seconds_recheck = 5;

        if ( is_array( $last_changes_logs ) ) {
            foreach ( $last_changes_logs as $last_log ) {
                if ( $triggered_log ) {
                    break;
                }
                if ( ! empty( $last_log ) && is_array( $last_log ) && \key_exists( 'created_on', $last_log ) && ( $last_log['created_on'] + $seconds_recheck ) > time() && ( ( ! is_array( $type_id ) && (int) $last_log['log_type_id'] === $type_id ) || ( is_array( $type_id ) && in_array( (int) $last_log[0]['log_type_id'], $type_id, true ) ) ) ) {
                    $triggered_log = true;
                }
            }
        }
        self::$cached_log_checks[ $type_id ] = $triggered_log;

        return $triggered_log;
    }

    /**
     * Get latest logs from DB.
     *
     * @param int  $limit – Number of items.
     * @param bool $with_meta - Include meta.
     *
     * @return array|bool
     */
    public static function get_latest_logs( $limit = 1, bool $with_meta = false ) {

        $params = array(
            'fields'    => array(),
            'with_meta' => $with_meta ? 1 : 0,
            'limit'     => $limit * 10,
            'order_by'  => ' created_on DESC ',
        );

        $logs_data = Changes_Logs_DB_Log::instance()->get_logs_data( $params );

        if ( $with_meta ) {
            $logs_data = Changes_Logs_DB_Log::instance()->prepare_log_with_meta_data( $logs_data );
            $logs_data = array_slice( $logs_data, 0, $limit );
        }

        if ( ! empty( $logs_data ) && is_array( $logs_data ) ) {
            return $logs_data;
        }

        return array();
    }

    /**
     * Return user data.
     *
     * @param string $username – Username.
     *
     * @return array
     */
    public static function get_log_user_data( $username ) {
        $user_data = array();
        if ( empty( $username ) ) {
            $user_data['username'] = 'System';
        } elseif ( 'Plugin' === $username ) {
            $user_data['username'] = 'Plugin';
        } elseif ( 'Plugins' === $username ) {
            $user_data['username'] = 'Plugins';
        } else {
            // Check WP user.
            if ( isset( self::$wp_users[ $username ] ) ) {
                $user_data = self::$wp_users[ $username ];
            } else {
                $user = \get_user_by( 'login', $username );

                if ( $user && $user instanceof \WP_User ) {
                    self::$wp_users[ $username ] = array(
                        'ID'            => $user->ID,
                        'user_login'    => $user->user_login,
                        'first_name'    => $user->first_name,
                        'last_name'     => $user->last_name,
                        'display_name'  => $user->display_name,
                        'user_email'    => $user->user_email,
                        'user_nicename' => $user->user_nicename,
                        'user_roles'    => Changes_Helper::get_user_roles( $user ),
                    );

                    $user_data = self::$wp_users[ $username ];
                }
            }

            if ( ! empty( $user ) ) {
                $user_data['username'] = 'System';
            }
        }

        return $user_data;
    }

    /**
     * Log an event to the database.
     *
     * @param integer $type_id    - Log code.
     * @param array   $data    - Metadata.
     */
    public static function insert_log( $type_id, $data = array() ) {

        $timestamp = array_key_exists( 'log_timestamp', $data ) ? $data['log_timestamp'] : current_time( 'U.u', true );

        unset( $data['log_timestamp'] );

        $blog_id = Changes_Helper::get_blog_id();
        $blog_id = \apply_filters( 'mainwp_child_changes_logs_database_blog_id_value', $blog_id, $type_id, $data );

        Changes_Logs_DB_Log::instance()->save_record(
            $data,
            $type_id,
            $timestamp,
            $blog_id
        );

        do_action( 'mainwp_child_changes_logs_logged', null, $type_id, $data, $blog_id );
    }
}
