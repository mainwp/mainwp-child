<?php
/**
 * Changes Logs Class
 *
 * @author  WP Activity Log plugin.
 *
 * @since 5.4.1
 *
 * @package MainWP\Child
 */

declare(strict_types=1);


namespace MainWP\Child\Changes;

use MainWP\Child\Changes\Helpers\Changes_User_Helper;
use MainWP\Child\Changes\Helpers\Changes_WP_Helper;
use MainWP\Child\Changes\Helpers\Changes_Settings_Helper;
use MainWP\Child\Changes\Entities\Changes_Metadata_Entity;
use MainWP\Child\Changes\Entities\Changes_Logs_Entity;
use MainWP\Child\Changes\Helpers\Changes_Database_Helper;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Changes_Logs_Manager
 */
class Changes_Logs_Manager {

    /**
     * Holds list of the ignored \WP_Post types.
     */
    public const IGNORED_POST_TYPES = array(
        'attachment',          // Attachment CPT.
        'revision',            // Revision CPT.
        'nav_menu_item',       // Nav menu item CPT.
        'customize_changeset', // Customize changeset CPT.
        'custom_css',          // Custom CSS CPT.
        'wp_template',         // Gutenberg templates.
    );

    /**
     * Array of loggers.
     */
    private static $loggers = array();

    /**
     * Contains a list of logs to trigger.
     *
     * @var array
     */
    private static $pipeline = array();

    /**
     * Holds the array with the excluded post types.
     *
     * @var array
     */
    private static $ignored_cpts = array();

    /**
     * Holds an array with all the registered logs.
     *
     * @var array
     */
    private static $logs = array();

    /**
     * Array of Deprecated Events.
     *
     * @var array
     */
    private static $deprecated_events = array();

    /**
     * Contains an array of logs that have been triggered for this request.
     *
     * @var int[]
     */
    private static $triggered_types = array();

    /**
     * Holds the array of all the excluded users from the settings.
     *
     * @var array
     */
    private static $excluded_users = array();

    /**
     * Holds the array of all the excluded roles from the settings.
     *
     * @var array
     */
    private static $excluded_roles = array();

    /**
     * Holds an array of all the post types.
     *
     * @var array
     */
    private static $all_post_types = array();

    /**
     * Amount of seconds to check back for the given log occurrence.
     *
     * @var int
     */
    private static $seconds_to_check_back = 5;

    /**
     * Holds a cached value if the checked logs which were recently fired.
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
     * Is IP address disabled.
     *
     * Store WP Users for caching purposes.
     *
     * @var bool
     */
    private static $is_ip_disabled = null;

    /**
     * Holds the array with the event types
     *
     * @var array
     */
    private static $event_types = array();

    /**
     * Holds the array with the objects data
     *
     * @var array
     */
    private static $objects_data = array();

    /**
     * Initializes the class and adds the hooks.
     *
     * @return void
     */
    public static function init() {
        \add_action( 'shutdown', array( __CLASS__, 'commit_pipeline' ), 8 );
    }

    /**
     * Trigger an log.
     *
     * @param int   $type    - Alert type.
     * @param array $data    - Alert data.
     * @param mixed $delayed - False if delayed, function if not.
     */
    public static function trigger_event( $type, $data = array(), $delayed = false ) {

        if ( \mainwp_child_is_dashboard_request() ) {
            return;
        }

        // Check if PostType index is set in data array.
        if ( isset( $data['PostType'] ) && ! empty( $data['PostType'] ) ) {
            // If the post type is disabled then return.
            if ( static::is_disabled_post_type( $data['PostType'] ) ) {
                return;
            }
        }

        // If the post status is disabled then return.
        if ( isset( $data['PostStatus'] ) && ! empty( $data['PostStatus'] ) ) {
            if ( static::is_disabled_post_status( $data['PostStatus'] ) ) {
                return;
            }
        }

        // Figure out the username.
        $username = Changes_User_Helper::get_current_user()->user_login;

        // If user switching plugin class exists and filter is set to disable then try to get the old user.
        if ( apply_filters( 'mainwp_child_changes_logs_disable_user_switching_plugin_tracking', false ) && class_exists( '\user_switching' ) ) {
            $old_user = \user_switching::get_old_user();
            if ( isset( $old_user->user_login ) ) {
                // Looks like this is a switched user so setup original user values for use when logging.
                $username              = $old_user->user_login;
                $data['Username']      = $old_user->user_login;
                $data['CurrentUserID'] = $old_user->ID;
            }
        }

        if ( empty( $username ) && ! empty( $data['Username'] ) ) {
            $username = $data['Username'];
        }

        // Get current user roles.
        if ( isset( $old_user ) && false !== $old_user ) {
            // looks like this is a switched user so setup original user
            // roles and values for later user.
            $roles                    = Changes_User_Helper::get_user_roles( $old_user );
            $data['CurrentUserRoles'] = $roles;
        } else {
            // not a switched user so get the current user roles.
            $roles = Changes_User_Helper::get_user_roles();
        }
        if ( empty( $roles ) && ! empty( $data['CurrentUserRoles'] ) ) {
            $roles = $data['CurrentUserRoles'];
        }

        // If user or user role is enabled then go ahead.
        if ( static::check_enable_user_roles( $username, $roles ) ) {
            $data['Timestamp'] = ( isset( $data['Timestamp'] ) && ! empty( $data['Timestamp'] ) ) ? $data['Timestamp'] : current_time( 'U.u', 'true' );
            if ( $delayed ) {
                static::trigger_event_if( $type, $data, null );
            } else {
                static::commit_item( $type, $data, null );
            }
        }
    }


    /**
     * Method: Check whether post type is disabled or not.
     *
     * @param string $post_type - Post type.
     *
     * @return bool - True if disabled, False if otherwise.
     */
    public static function is_disabled_post_type( $post_type ) {
        return in_array( $post_type, self::get_all_post_types(), true );
    }

    /**
     * Method: Check whether post status is disabled or not.
     *
     * @param string $post_status - Post status.
     *
     * @return bool - True if disabled, False if otherwise.
     *
     * @since 5.0.0
     */
    public static function is_disabled_post_status( $post_status ) {
        return in_array( $post_status, Changes_Settings_Helper::get_excluded_post_statuses(), true );
    }

    /**
     * Returns array with all post types
     *
     * @return array
     */
    public static function get_all_post_types(): array {
        if ( empty( self::$all_post_types ) ) {
            self::$all_post_types = array_merge( Changes_Settings_Helper::get_excluded_post_types(), self::get_ignored_post_types() );
        }

        if ( ! \is_array( self::$all_post_types ) ) {
            self::$all_post_types = array();
        }

        return self::$all_post_types;
    }

    /**
     * Returns all the ignored post types - post types are \WP_Post types
     * Note: There is a difference between ignored types and disabled types.
     */
    public static function get_ignored_post_types(): array {
        if ( empty( self::$ignored_cpts ) ) {
            /*
                * Filter: `mainwp_child_changes_logs_ignored_custom_post_types`
                *
                * Ignored custom post types filter.
                *
                * @param array $ignored_cpts - Array of custom post types.
                *
                * @since 5.4.1
                */
            self::$ignored_cpts = apply_filters(
                'mainwp_child_changes_logs_ignored_custom_post_types',
                array_unique(
                    array_merge(
                        Changes_Settings_Helper::get_excluded_post_types(),
                        self::IGNORED_POST_TYPES
                    )
                )
            );
        }

        if ( ! \is_array( self::$ignored_cpts ) ) {
            self::$ignored_cpts = array();
        }

        return self::$ignored_cpts;
    }

    /**
     * Removes type from the ignored post types.
     *
     * @param string $post_type - The name of the post type to remove.
     *
     * @return void
     */
    public static function remove_from_ignored_post_types( string $post_type ) {
        if ( empty( self::$ignored_cpts ) ) {
            self::get_ignored_post_types();
        }

        $key = array_search( $post_type, self::$ignored_cpts, true );

        if ( false !== $key ) {
            unset( self::$ignored_cpts[ $key ] );
        }
    }

    /**
     * Adds type to the ignored post types.
     *
     * @param string $post_type - The name of the post type to remove.
     *
     * @return void
     */
    public static function add_to_ignored_post_types( string $post_type ) {
        if ( empty( self::$ignored_cpts ) ) {
            self::get_ignored_post_types();
        }

        $key = array_search( $post_type, self::$ignored_cpts, true );

        if ( false === $key ) {
            self::$ignored_cpts[] = $post_type;
        }
    }

    /**
     * Removes type from the ignored post types.
     *
     * @param string $post_type - The name of the post type to remove.
     *
     * @return void
     */
    public static function remove_from_all_ignored_post_types( string $post_type ) {
        if ( empty( self::$all_post_types ) ) {
            self::get_all_post_types();
        }

        $key = array_search( $post_type, self::$all_post_types, true );

        if ( false !== $key ) {
            unset( self::$all_post_types[ $key ] );
        }
    }

    /**
     * Adds type to the ignored post types.
     *
     * @param string $post_type - The name of the post type to remove.
     *
     * @return void
     */
    public static function add_to_all_ignored_post_types( string $post_type ) {
        if ( empty( self::$all_post_types ) ) {
            self::get_all_post_types();
        }

        $key = array_search( $post_type, self::$all_post_types, true );

        if ( false === $key ) {
            self::$all_post_types[] = $post_type;
        }
    }

    /**
     * Check enable user and roles.
     *
     * @param string $user  - Username.
     * @param array  $roles - User roles.
     *
     * @return bool - True if enable false otherwise.
     */
    public static function check_enable_user_roles( $user, $roles ) {
        if ( '' !== $user && self::is_disabled_user( $user ) ) {
            return false;
        }

        if ( '' !== $roles && self::is_disabled_role( $roles ) ) {
            return false;
        }

        return true;
    }

    /**
     * Returns whether user is enabled or not.
     *
     * @param string $user - Username.
     *
     * @return bool True if disabled, false otherwise.
     */
    public static function is_disabled_user( $user ) {
        if ( empty( self::$excluded_users ) ) {
            self::$excluded_users = Changes_Settings_Helper::get_excluded_monitoring_users();
        }

        return in_array( $user, self::$excluded_users, true );
    }

    /**
     * Returns whether user is enabled or not.
     *
     * @param array $roles - User roles.
     *
     * @return bool True if disabled, false otherwise.
     */
    public static function is_disabled_role( $roles ) {
        $is_disabled = false;

        if ( empty( self::$excluded_roles ) ) {
            self::$excluded_roles = Changes_Settings_Helper::get_excluded_monitoring_roles();
        }

        if ( ! \is_array( self::$excluded_roles ) ) {
            self::$excluded_roles = array( self::$excluded_roles );
        }

        if ( ! \is_array( $roles ) ) {
            $roles = array( $roles );
        }

        foreach ( $roles as $role ) {
            if ( in_array( $role, self::$excluded_roles, true ) ) {
                $is_disabled = true;
            }
        }

        return $is_disabled;
    }

    /**
     * Trigger only if a condition is met at the end of request.
     *
     * @param int      $type - Alert type ID.
     * @param array    $data - Alert data.
     * @param callable $cond - A future condition callback (receives an object as parameter).
     */
    public static function trigger_event_if( $type, $data, $cond = null ) {

        if ( \mainwp_child_is_dashboard_request() ) {
            return;
        }

        // Check if PostType index is set in data array.
        if ( isset( $data['PostType'] ) && ! empty( $data['PostType'] ) ) {
            // If the post type is disabled then return.
            if ( self::is_disabled_post_type( $data['PostType'] ) ) {
                return;
            }
        }

        // If the post status is disabled then return.
        if ( isset( $data['PostStatus'] ) && ! empty( $data['PostStatus'] ) ) {
            if ( self::is_disabled_post_status( $data['PostStatus'] ) ) {
                return;
            }
        }

        $username = null;

        // if user switching plugin class exists and filter is set to disable then try get the old user.
        if ( \apply_filters( 'mainwp_child_changes_logs_disable_user_switching_plugin_tracking', false ) && class_exists( '\user_switching' ) ) {
            $old_user = \user_switching::get_old_user();
            if ( isset( $old_user->user_login ) ) {
                // looks like this is a switched user so setup original user
                // values for use when logging.
                $username              = $old_user->user_login;
                $data['Username']      = $old_user->user_login;
                $data['CurrentUserID'] = $old_user->ID;
            }
        }

        $roles = array();
        if ( 1000 === $type ) {
            // When event 1000 is triggered, the user is not logged in.
            // We need to extract the username and user roles from the event data.
            $username = array_key_exists( 'Username', $data ) ? $data['Username'] : null;
            $roles    = array_key_exists( 'CurrentUserRoles', $data ) ? $data['CurrentUserRoles'] : array();
        } elseif ( class_exists( 'user_switching' ) && isset( $old_user ) && false !== $old_user ) {
            // looks like this is a switched user so setup original user
            // roles and values for later user.
            $roles                    = Changes_User_Helper::get_user_roles( $old_user );
            $data['CurrentUserRoles'] = $roles;
        } else {
            $username = Changes_User_Helper::get_current_user()->user_login;
            $roles    = Changes_User_Helper::get_user_roles();
        }

        if ( self::check_enable_user_roles( $username, $roles ) ) {
            if ( ! array_key_exists( 'Timestamp', $data ) ) {
                $data['Timestamp'] = current_time( 'U.u', 'true' );
            }
            self::$pipeline[] = array(
                'type' => $type,
                'data' => $data,
                'cond' => $cond,
            );
        }
    }

    /**
     * Method: Commit an log now.
     *
     * @param int   $type   - Alert type.
     * @param array $data   - Data of the log.
     * @param array $cond   - Condition for the log.
     * @param bool  $_retry - Retry.
     *
     * @return mixed
     *
     * @internal
     */
    protected static function commit_item( $type, $data, $cond, $_retry = true ) {
        // Double NOT operation here is intentional. Same as ! ( bool ) [ $value ]
        // NOTE: return false on a true condition to compensate.
        if ( ! $cond || (bool) call_user_func( $cond ) ) {
            if ( static::is_enabled( $type ) ) {
                if ( isset( self::get_logs()[ $type ] ) ) {
                    // Ok, convert log to a log entry.
                    self::$triggered_types[] = $type;
                    self::log( $type, $data );
                } elseif ( $_retry ) {

                    return self::commit_item( $type, $data, $cond, false );
                }
            }
        }
    }

    /**
     * Returns whether log of type $type is enabled or not.
     *
     * @param int $type Alert type.
     *
     * @return bool True if enabled, false otherwise.
     */
    public static function is_enabled( $type ) {
        $disabled_events = Changes_Settings_Helper::get_disabled_logs();

        return ! in_array( $type, $disabled_events, true );
    }

    /**
     * Register a whole group of items.
     *
     * @param array $groups - An array with group name as the index and an array of group items as the value.
     *                      Item values is an array of [type, code, description, message, object, event type] respectively.
     */
    public static function register_group( $groups ) {
        foreach ( $groups as $name => $group ) {
            foreach ( $group as $subname => $subgroup ) {
                foreach ( $subgroup as $item ) {
                    static::register( $name, $subname, $item );
                }
            }
        }
    }


    /**
     * Register an alert type.
     *
     * @param string $category    Category name.
     * @param string $subcategory Subcategory name.
     * @param array  $info        Event information from defaults.php.
     */
    public static function register( $category, $subcategory, $info ) {
        // Default for optional fields.
        $metadata   = array();
        $links      = array();
        $object     = '';
        $event_type = '';

        $definition_items_count = count( $info );
        if ( 7 === $definition_items_count ) {
            // Most recent event definition introduced in version 4.2.1.
            list($code, $desc, $message, $metadata, $links, $object, $event_type) = $info;
        }

        if ( is_string( $links ) ) {
            $links = array( $links );
        }

        /**
         * Changes log Filter: `mainwp_child_changes_log_metadata_definition`.
         *
         * Filters event metadata definition before registering specific event with the alert manager. This is the
         * preferred way to change metadata definition of built-in events.
         *
         * @param array $metadata - Event data.
         * @param int   $code     - Event ID.
         */
        $metadata = \apply_filters( 'mainwp_child_changes_log_metadata_definition', $metadata, $code );

        self::$logs[ $code ] = array(
            'code'        => $code,
            'category'    => $category,
            'subcategory' => $subcategory,
            'desc'        => $desc,
            'message'     => $message,
            'metadata'    => $metadata,
            'links'       => $links,
            'object'      => $object,
            'event_type'  => $event_type,
        );
    }


    /**
     * Duplicate Event Notice.
     */
    public static function duplicate_event_notice() {
        $class   = 'notice notice-error';
        $message = __( 'You have custom events that are using the same ID or IDs which are already registered in the plugin, so they have been disabled.', 'mainwp-child' );
        printf(
            /* Translators: 1.CSS classes, 2. Notice, 3. Contact us link */
            '<div class="%1$s"><p>%2$s %3$s ' . \esc_html__( '%4$s to help you solve this issue.', 'mainwp-child' ) . '</p></div>',
            \esc_attr( $class ),
            '<span style="color:#dc3232; font-weight:bold;">' . \esc_html__( 'ERROR:', 'mainwp-child' ) . '</span>',
            \esc_html( $message ),
            '<a href="https://melapress.com/contact" target="_blank">' . \esc_html__( 'Contact us', 'mainwp-child' ) . '</a>'
        );
    }

    /**
     * Converts an Alert into a Log entry (by invoking loggers).
     * You should not call this method directly.
     *
     * @param int   $event_id   - Alert type.
     * @param array $event_data - Misc log data.
     */
    public static function log( $event_id, $event_data = array() ) {

        if ( ! in_array( $event_id, Changes_Defaults::support_logs() ) ) {
            return;
        }

        $log_obj = self::get_logs()[ $event_id ];

        if ( ! isset( $event_data['ClientIP'] ) ) {
            $client_ip = Changes_Settings_Helper::get_main_client_ip();
            if ( ! empty( $client_ip ) ) {
                $event_data['ClientIP'] = $client_ip;
            }
        }
        if ( ! isset( $event_data['OtherIPs'] ) && Changes_Settings_Helper::get_boolean_option_value( 'use-proxy-ip' ) ) {
            $other_ips = Changes_Settings_Helper::get_client_ips();
            if ( ! empty( $other_ips ) ) {
                $event_data['OtherIPs'] = $other_ips;
            }
        }
        if ( ! isset( $event_data['UserAgent'] ) ) {
            if ( isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
                $event_data['UserAgent'] = \sanitize_text_field( \wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
            }
        }
        if ( ! isset( $event_data['Username'] ) && ! isset( $event_data['CurrentUserID'] ) ) {
            if ( function_exists( 'get_current_user_id' ) ) {
                $event_data['CurrentUserID'] = \get_current_user_id();
                if ( 0 !== $event_data['CurrentUserID'] ) {
                    $user = \get_user_by( 'ID', $event_data['CurrentUserID'] );
                    if ( \is_a( $user, '\WP_User' ) ) {
                        $event_data['Username'] = $user->user_login;
                    } else {
                        $event_data['Username'] = 'Unknown User';
                    }
                }
                if ( 0 === $event_data['CurrentUserID'] ) {
                    if ( 'system' === \strtolower( $log_obj['object'] ) ) {
                        $event_data['Username'] = 'System';
                    } elseif ( str_starts_with( \strtolower( $log_obj['object'] ), 'woocommerce' ) && 9130 !== (int) $event_id ) {
                        $event_data['Username'] = 'WooCommerce System';
                    } else {
                        $event_data['Username'] = 'Unknown User';
                    }
                }
            }
        }
        if ( isset( $event_data['CurrentUserID'] ) && ! isset( $event_data['Username'] ) ) {
            if ( 0 === $event_data['CurrentUserID'] ) {
                if ( 'system' === \strtolower( $log_obj['object'] ) ) {
                    $event_data['Username'] = 'System';
                } elseif ( str_starts_with( \strtolower( $log_obj['object'] ), 'woocommerce' ) ) {
                    $event_data['Username'] = 'WooCommerce System';
                } else {
                    $event_data['Username'] = 'Unknown User';
                }
            } else {
                $user = \get_user_by( 'ID', $event_data['CurrentUserID'] );
                if ( $user ) {
                    $event_data['Username'] = $user->user_login;
                } else {
                    $event_data['Username'] = 'Deleted';
                }
            }
        }
        if ( ! isset( $event_data['CurrentUserRoles'] ) && function_exists( 'is_user_logged_in' ) && \is_user_logged_in() ) {
            $current_user_roles = Changes_User_Helper::get_user_roles();
            if ( ! empty( $current_user_roles ) ) {
                $event_data['CurrentUserRoles'] = $current_user_roles;
            }
        }

        // If the user sessions plugin is loaded try to attach the SessionID.
        if ( ! isset( $event_data['SessionID'] ) && class_exists( '\WSAL\Helpers\User_Sessions_Helper' ) ) {
            // Try to get the session id generated from logged in cookie.
            $session_id = User_Sessions_Helper::get_session_id_from_logged_in_user_cookie();
            // If we have a SessionID then add it to event_data.
            if ( ! empty( $session_id ) ) {
                $event_data['SessionID'] = $session_id;
            }
        }

        // Add event object.
        if ( $log_obj && ! isset( $event_data['Object'] ) ) {
            $event_data['Object'] = $log_obj['object'];
        }

        // Add event type.
        if ( $log_obj && ! isset( $event_data['EventType'] ) ) {
            $event_data['EventType'] = $log_obj['event_type'];
        }

        // Append further details if in multisite.
        if ( Changes_WP_Helper::is_multisite() ) {
            $event_data['SiteID']  = get_current_blog_id();
            $event_data['SiteURL'] = get_site_url( $event_data['SiteID'] );
        }

        /**
         * Filter: `mainwp_child_changes_logs_event_id_before_log`.
         *
         * Filters event id before logging it to the database.
         *
         * @since 5.4.1
         *
         * @param int   $event_id   - Event ID.
         * @param array $event_data - Event data.
         */
        $event_id = \apply_filters( 'mainwp_child_changes_logs_event_id_before_log', $event_id, $event_data );

        /**
         * Filter: `mainwp_child_changes_logs_event_data_before_log`.
         *
         * Filters event data before logging it to the database.
         *
         * @since 5.4.1
         *
         * @param array $event_data - Event data.
         * @param int   $event_id   - Event ID.
         */
        $event_data = \apply_filters( 'mainwp_child_changes_logs_event_data_before_log', $event_data, $event_id );

        foreach ( self::get_loggers() as $logger ) {
            $logger::log( $event_id, $event_data );
        }
    }

    /**
     * Returns all the logs. If the array with the logs is not initialized - it first tries to initialize it.
     */
    public static function get_logs() {
        if ( empty( self::$logs ) ) {
            Changes_Defaults::set_changes_logs();
        }

        if ( ! array( self::$logs ) ) {
            self::$logs = array();
        }

        return self::$logs;
    }

    /**
     * Collects all loggers (if not already) and returns them.
     */
    public static function get_loggers() {
        if ( empty( self::$loggers ) ) {
            self::$loggers[] = new Changes_Database_Helper();
        }

        return self::$loggers;
    }


    /**
     * Method: Runs over triggered logs in pipeline and passes them to loggers.
     *
     * @internal
     *
     */
    public static function commit_pipeline() {
        if ( \mainwp_child_is_dashboard_request() ) {
            return;
        }
        foreach ( self::$pipeline as $key => $item ) {
            unset( self::$pipeline[ $key ] );
            self::commit_item( $item['type'], $item['data'], $item['cond'] );
            self::$pipeline[ $key ] = $item;
        }
    }

    /**
     * Returns the list with all the deprecated events.
     *
     * @return array
     */
    public static function get_deprecated_events() {
        if ( empty( self::$deprecated_events ) ) {
            self::$deprecated_events = apply_filters( 'mainwp_child_changes_logs_deprecated_event_ids', array( 2004, 2005, 2006, 2007, 2009, 2013, 2015, 2018, 2020, 2022, 2026, 2028, 2059, 2060, 2061, 2064, 2066, 2069, 2075, 2087, 2102, 2103, 2113, 2114, 2115, 2116, 2117, 2118, 5020, 5026, 2107, 2003, 2029, 2030, 2031, 2032, 2033, 2034, 2035, 2036, 2037, 2038, 2039, 2040, 2041, 2056, 2057, 2058, 2063, 2067, 2068, 2070, 2072, 2076, 2088, 2104, 2105, 5021, 5027, 2108 ) );
        }

        return self::$deprecated_events;
    }

    /**
     * Method: True if at the end of request an log of this type will be triggered.
     *
     * @param int $type  - Alert type ID.
     * @param int $count - A minimum number of event occurrences.
     */
    public static function will_trigger( $type, $count = 1 ): bool {
        $number_found = 0;
        foreach ( self::$pipeline as $item ) {
            if ( $item['type'] === $type ) {
                ++$number_found;
                if ( 1 === $count || $number_found === $count ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Method: True if an log has been or will be triggered in this request, false otherwise.
     *
     * @param int $type  - Alert type ID.
     * @param int $count - A minimum number of event occurrences.
     *
     * @return bool
     */
    public static function will_or_has_triggered( $type, $count = 1 ) {
        return in_array( $type, self::$triggered_types, true ) || self::will_trigger( $type, $count );
    }

    /**
     * Method: True if an log has been or will be triggered in this request, false otherwise.
     *
     * @param int $type  - Alert type ID.
     * @param int $count - A minimum number of event occurrences.
     *
     * @return bool
     */
    public static function has_triggered( $type, $count = 1 ) {
        return in_array( $type, self::$triggered_types, true );
    }

    /**
     * Method: Returns array of logs by category.
     *
     * @param string $category - Alerts category.
     *
     * @return Changes_Logs_Manager[]
     */
    public static function get_logs_by_category( $category ) {
        // Categorized logs array.
        $logs = array();
        foreach ( self::get_logs() as $log ) {
            if ( $category === $log['category'] ) {
                $logs[ $log['code'] ] = $log;
            }
        }

        return $logs;
    }

    /**
     * Check if the log was triggered.
     *
     * @param integer|array $log_type_id - Alert code.
     *
     * @return boolean
     */
    public static function was_triggered( $log_type_id ) {

        $last_occurrence = Changes_Metadata_Entity::build_query(
            array( 'log_type_id' => 'log_type_id' ),
            array(),
            array( 'created_on' => 'DESC' ),
            array( 1 )
        );

        if ( ! empty( $last_occurrence ) && isset( $last_occurrence[0]['log_type_id'] ) ) {
            if ( ! is_array( $log_type_id ) && (int) $last_occurrence[0]['log_type_id'] === (int) $log_type_id ) {
                return true;
            } elseif ( is_array( $log_type_id ) && in_array( (int) $last_occurrence[0]['log_type_id'], $log_type_id, true ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the log was triggered recently.
     *
     * Checks last 5 events if they occurred less than self::$seconds_to_check_back seconds ago.
     *
     * @param int|array $log_id - Alert code.
     *
     * @return bool
     */
    public static function was_triggered_recently( $log_id ) {
        // if we have already checked this don't check again.
        if ( isset( self::$cached_log_checks ) && array_key_exists( $log_id, self::$cached_log_checks ) && self::$cached_log_checks[ $log_id ] ) {
            return true;
        }

        $last_occurrences = self::get_latest_events( 5 );

        $known_to_trigger = false;

        if ( \is_array( $last_occurrences ) ) {
            foreach ( $last_occurrences as $last_occurrence ) {
                if ( $known_to_trigger ) {
                    break;
                }
                if ( ! empty( $last_occurrence ) && \is_array( $last_occurrence ) && \key_exists( 'created_on', $last_occurrence ) && ( $last_occurrence['created_on'] + self::$seconds_to_check_back ) > time() ) {
                    if ( ! is_array( $log_id ) && (int) $last_occurrence['log_type_id'] === $log_id ) {
                        $known_to_trigger = true;
                    } elseif ( is_array( $log_id ) && in_array( (int) $last_occurrence[0]['log_type_id'], $log_id, true ) ) {
                        $known_to_trigger = true;
                    }
                }
            }
        }
        // once we know the answer to this don't check again to avoid queries.
        self::$cached_log_checks[ $log_id ] = $known_to_trigger;

        return $known_to_trigger;
    }

    /**
     * Get latest events from DB.
     *
     * @param int  $limit – Number of events.
     * @param bool $include_meta - Should we include meta to the collected events.
     * @param bool $first - If is set to true, it will extract oldest events (default is most recent ones).
     *
     * @return array|bool
     *
     * @since 5.0.0 $first flag is added
     */
    public static function get_latest_events( $limit = 1, bool $include_meta = false, bool $first = false ) {

        if ( ! Changes_Metadata_Entity::get_connection()->has_connected ) {
            // Connection problem while using external database (if local database is used, we would see WordPress's
            // "Error Establishing a Database Connection" screen).
            return false;
        }

        $direction = 'DESC';
        if ( $first ) {
            $direction = 'ASC';
        }

        $query = array();

        // Get site id.
        $site_id = (int) Changes_WP_Helper::get_view_site_id();

        $site_id = \apply_filters( 'mainwp_child_changes_logs_alter_site_id', $site_id );

        // if we have a blog id then add it.
        if ( $site_id && 0 < $site_id ) {
            $query['AND'][] = array( ' site_id = %s ' => $site_id );
        }

        if ( ! $include_meta ) {
            $events = Changes_Metadata_Entity::build_query( array(), $query, array( 'created_on' => $direction ), array( $limit ) );
        } else {

            $meta_table_name = Changes_Metadata_Entity::get_table_name();
            $join_clause     = array(
                $meta_table_name => array(
                    'direction'   => 'LEFT',
                    'join_fields' => array(
                        array(
                            'join_field_left'  => 'log_id',
                            'join_table_right' => Changes_Metadata_Entity::get_table_name(),
                            'join_field_right' => 'id',
                        ),
                    ),
                ),
            );
            // order results by date and return the query.
            $meta_full_fields_array       = Changes_Metadata_Entity::prepare_full_select_statement();
            $occurrence_full_fields_array = Changes_Metadata_Entity::prepare_full_select_statement();

            /**
             * Limit here is set to $limit * 15, because now we have to extract metadata as well.
             * Because of that we can not use limit directly here. We will extract enough data to include the metadata as well and limit the results later on - still fastest than creating enormous amount of queries.
             * Currently there are no more than 10 records (meta) per occurrence, we are using 12 just in case.
             */
            $events = Changes_Metadata_Entity::build_query( array_merge( $meta_full_fields_array, $occurrence_full_fields_array ), $query, array( 'created_on' => $direction ), array( $limit * 12 ), $join_clause );

            $events = Changes_Logs_Entity::prepare_with_meta_data( $events );

            $events = array_slice( $events, 0, $limit );
        }

        if ( ! empty( $events ) && is_array( $events ) ) {
            return $events;
        }

        return array();
    }

    /**
     * Method: Log the message for sensor.
     *
     * @param int    $type    - Type of log.
     * @param string $message - Alert message.
     * @param mixed  $args    - Message arguments.
     *
     * @since 5.1.1
     */
    public static function log_problem( $type, $message, $args ) {
        static::trigger_event(
            $type,
            array(
                'Message' => $message,
                'Context' => $args,
                'Trace'   => debug_backtrace(),
            )
        );
    }

    /**
     * Method: Log error message for sensor.
     *
     * @param string $message - Alert message.
     * @param mixed  $args    - Message arguments.
     */
    public static function log_error( $message, $args ) {
        self::log_problem( 0001, $message, $args );
    }

    /**
     * Method: Log warning message for sensor.
     *
     * @param string $message - Alert message.
     * @param mixed  $args    - Message arguments.
     */
    public static function log_warn( $message, $args ) {
        self::log_problem( 0002, $message, $args );
    }

    /**
     * Method: Log info message for sensor.
     *
     * @param string $message - Alert message.
     * @param mixed  $args    - Message arguments.
     */
    protected function log_info( $message, $args ) {
        self::log_problem( 0003, $message, $args );
    }

    /**
     * Get event type data array or optionally just value of a single type.
     *
     * @param string $type A type that the string is requested for (optional).
     *
     * @return array|string
     */
    public static function get_event_type_data( $type = '' ) {
        if ( empty( self::$event_types ) ) {

            self::$event_types = array(
                'login'        => esc_html__( 'Login', 'mainwp-child' ),
                'logout'       => esc_html__( 'Logout', 'mainwp-child' ),
                'installed'    => esc_html__( 'Installed', 'mainwp-child' ),
                'activated'    => esc_html__( 'Activated', 'mainwp-child' ),
                'deactivated'  => esc_html__( 'Deactivated', 'mainwp-child' ),
                'uninstalled'  => esc_html__( 'Uninstalled', 'mainwp-child' ),
                'updated'      => esc_html__( 'Updated', 'mainwp-child' ),
                'created'      => esc_html__( 'Created', 'mainwp-child' ),
                'modified'     => esc_html__( 'Modified', 'mainwp-child' ),
                'deleted'      => esc_html__( 'Deleted', 'mainwp-child' ),
                'published'    => esc_html__( 'Published', 'mainwp-child' ),
                'approved'     => esc_html__( 'Approved', 'mainwp-child' ),
                'unapproved'   => esc_html__( 'Unapproved', 'mainwp-child' ),
                'enabled'      => esc_html__( 'Enabled', 'mainwp-child' ),
                'disabled'     => esc_html__( 'Disabled', 'mainwp-child' ),
                'added'        => esc_html__( 'Added', 'mainwp-child' ),
                'failed-login' => esc_html__( 'Failed Login', 'mainwp-child' ),
                'blocked'      => esc_html__( 'Blocked', 'mainwp-child' ),
                'uploaded'     => esc_html__( 'Uploaded', 'mainwp-child' ),
                'restored'     => esc_html__( 'Restored', 'mainwp-child' ),
                'opened'       => esc_html__( 'Opened', 'mainwp-child' ),
                'viewed'       => esc_html__( 'Viewed', 'mainwp-child' ),
                'started'      => esc_html__( 'Started', 'mainwp-child' ),
                'stopped'      => esc_html__( 'Stopped', 'mainwp-child' ),
                'removed'      => esc_html__( 'Removed', 'mainwp-child' ),
                'unblocked'    => esc_html__( 'Unblocked', 'mainwp-child' ),
                'renamed'      => esc_html__( 'Renamed', 'mainwp-child' ),
                'duplicated'   => esc_html__( 'Duplicated', 'mainwp-child' ),
                'submitted'    => esc_html__( 'Submitted', 'mainwp-child' ),
                'revoked'      => esc_html__( 'Revoked', 'mainwp-child' ),
                'sent'         => esc_html__( 'Sent', 'mainwp-child' ),
                'executed'     => esc_html__( 'Executed', 'mainwp-child' ),
                'failed'       => esc_html__( 'Failed', 'mainwp-child' ),
            );
            // sort the types alphabetically.
            asort( self::$event_types );
            self::$event_types = apply_filters(
                'mainwp_child_changes_logs_event_type_data',
                self::$event_types
            );
        }

        /*
            * If a specific type was requested then try return that otherwise the
            * full array gets returned.
            *
            */
        if ( ! empty( $type ) ) {
            // NOTE: if we requested type doesn't exist returns 'unknown type'.
            return ( isset( self::$event_types[ $type ] ) ) ? self::$event_types[ $type ] : __( 'unknown type', 'mainwp-child' );
        }

        // if a specific type was not requested return the full array.
        return self::$event_types;
    }

    /**
     * Get event objects.
     *
     * @param string $object An object the string is requested for (optional).
     *
     * @return array|string
     */
    public static function get_event_objects_data( $object = '' ) {
        if ( empty( self::$objects_data ) ) {

            self::$objects_data = array(
                'user'              => esc_html__( 'User', 'mainwp-child' ),
                'system'            => esc_html__( 'System', 'mainwp-child' ),
                'plugin'            => esc_html__( 'Plugin', 'mainwp-child' ),
                'database'          => esc_html__( 'Database', 'mainwp-child' ),
                'post'              => esc_html__( 'Post', 'mainwp-child' ),
                'file'              => esc_html__( 'File', 'mainwp-child' ),
                'tag'               => esc_html__( 'Tag', 'mainwp-child' ),
                'comment'           => esc_html__( 'Comment', 'mainwp-child' ),
                'setting'           => esc_html__( 'Setting', 'mainwp-child' ),
                'system-setting'    => esc_html__( 'System Setting', 'mainwp-child' ),
                'cron-job'          => esc_html__( 'Cron Jobs', 'mainwp-child' ),
                'mainwp-network'    => esc_html__( 'MainWP Network', 'mainwp-child' ),
                'mainwp'            => esc_html__( 'MainWP', 'mainwp-child' ),
                'category'          => esc_html__( 'Category', 'mainwp-child' ),
                'custom-field'      => esc_html__( 'Custom Field', 'mainwp-child' ),
                'widget'            => esc_html__( 'Widget', 'mainwp-child' ),
                'menu'              => esc_html__( 'Menu', 'mainwp-child' ),
                'theme'             => esc_html__( 'Theme', 'mainwp-child' ),
                'activity-log'      => esc_html__( 'Activity log', 'mainwp-child' ),
                'wp-activity-log'   => esc_html__( 'WP Activity Log', 'mainwp-child' ),
                'multisite-network' => esc_html__( 'Multisite Network', 'mainwp-child' ),
                'ip-address'        => esc_html__( 'IP Address', 'mainwp-child' ),
            );

            asort( self::$objects_data );
            self::$objects_data = apply_filters(
                'mainwp_child_changes_logs_event_objects',
                self::$objects_data
            );
        }

        /*
        * If a specific object was requested then try return that otherwise
        * the full array gets returned.
        *
        */
        if ( ! empty( $object ) ) {
            // NOTE: if we requested object doesn't exist returns 'unknown object'.
            return ( isset( self::$objects_data[ $object ] ) ) ? self::$objects_data[ $object ] : __( 'unknown object', 'mainwp-child' );
        }

        // if a specific object was not requested return the full array.
        return self::$objects_data;
    }

    /**
     * Return user data array of the events.
     *
     * @param string $username – Username.
     *
     * @return array
     */
    public static function get_event_user_data( $username ) {
        // User data.
        $user_data = array();

        // Handle usernames.
        if ( empty( $username ) ) {
            $user_data['username'] = 'System';
        } elseif ( 'Plugin' === $username ) {
            $user_data['username'] = 'Plugin';
        } elseif ( 'Plugins' === $username ) {
            $user_data['username'] = 'Plugins';
        } elseif ( 'Website Visitor' === $username || 'Unregistered user' === $username ) {
            $user_data['username'] = 'Unregistered user';
        } else {
            // Check WP user.
            if ( isset( self::$wp_users[ $username ] ) ) {
                // Retrieve from users cache.
                $user_data = self::$wp_users[ $username ];
            } else {
                // Get user from WP.
                $user = \get_user_by( 'login', $username );

                if ( $user && $user instanceof \WP_User ) {
                    // Store the user data in class member.
                    self::$wp_users[ $username ] = array(
                        'ID'            => $user->ID,
                        'user_login'    => $user->user_login,
                        'first_name'    => $user->first_name,
                        'last_name'     => $user->last_name,
                        'display_name'  => $user->display_name,
                        'user_email'    => $user->user_email,
                        'user_nicename' => $user->user_nicename,
                        'user_roles'    => Changes_User_Helper::get_user_roles( $user ),
                    );

                    $user_data = self::$wp_users[ $username ];
                }
            }

            // Set user data.
            if ( ! empty( $user ) ) {
                $user_data['username'] = 'System';
            }
        }

        return $user_data;
    }

    /**
     * Returns the cached wp users.
     *
     * @return array
     */
    public static function get_wp_users() {
        return self::$wp_users;
    }

    /**
     * Returns all supported logs.
     *
     * @param bool $sorted – Sort the logs array or not.
     *
     * @return array
     */
    public static function get_categorized_logs( $sorted = true ) {

        $result = array();

        foreach ( self::get_logs() as $log ) {
            if ( ! isset( $result[ \html_entity_decode( $log['category'] ) ] ) ) {
                $result[ \html_entity_decode( $log['category'] ) ] = array();
            }
            if ( ! isset( $result[ \html_entity_decode( $log['category'] ) ] ) ) {
                $result[ \html_entity_decode( $log['category'] ) ] = array();
            }
            $result[ \html_entity_decode( $log['category'] ) ][] = $log;
        }

        if ( $sorted ) {
            ksort( $result );
        }

        return $result;
    }

    /**
     * Returns give log property by its id
     *
     * @param int    $log_id - The id of the log.
     * @param string $property - The property name.
     *
     * @return mixed
     */
    public static function get_log_property( $log_id, $property ) {

        if ( isset( self::get_logs()[ $log_id ] ) && isset( self::get_logs()[ $log_id ][ $property ] ) ) {
            return self::get_logs()[ $log_id ][ $property ];
        }

        return false;
    }

    /**
     * Disables the log by its identifier
     *
     * @param string|array $log - The array or comma separated string with the ids that needs to be set as disabled.
     *
     * @return false|void
     *
     * @since 5.2.2
     */
    public static function disable_enable_log( $log ) {

        if ( \is_array( $log ) && ! empty( $log ) ) {
            $log = array_unique( array_map( 'intval', $log ) );
        } elseif ( \is_array( $log ) && empty( $log ) ) {
            return false;
        }

        if ( \is_string( $log ) ) {
            $log = \explode( ',', $log );
            $log = array_unique( array_map( 'intval', $log ) );
        }

        if ( empty( $log ) ) {
            return false;
        }

        $currently_disabled = Changes_Settings_Helper::get_disabled_logs();
        $disabled           = \array_flip( $currently_disabled );
        $enabled            = array();

        foreach ( $log as $log_id ) {
            if ( in_array( $log_id, $currently_disabled, true ) ) {
                $enabled[] = $log_id;
                unset( $disabled[ $log_id ] );
            } else {
                $disabled[ $log_id ] = '';
            }
        }

        $disabled = \array_flip( $disabled );

        $disabled = \apply_filters( 'mainwp_child_changes_logs_save_settings_disabled_events', $disabled, self::get_logs(), array(), $enabled );

        // Report any changes as an event.
        static::report_enabled_disabled_event( $enabled, $disabled );

        // Save the disabled events.
        Changes_Settings_Helper::set_disabled_logs( $disabled ); // Save the disabled events.
    }

    /**
     * Reports an event if an log has been disabled/enabled.
     *
     * @param array $enabled - Array of enabled event IDs prior to saving.
     * @param array $disabled - Array of disabled events prior to saving.
     * @param bool  $is_frontend - If set as frontend - check the frontend event codes.
     *
     * @return void
     *
     * @since 5.2.2
     */
    public static function report_enabled_disabled_event( $enabled, $disabled, $is_frontend = false ) {

        if ( $is_frontend ) {
            $current_enabled = $enabled;
            $fresh_enabled   = $disabled;
            $frontend_labels = array(
                'register'    => esc_html__( 'Keep a log when a visitor registers a user on the website. Only enable this if you allow visitors to register as users on your website. User registration is disabled by default in WordPress.', 'mainwp-child' ),
                'login'       => esc_html__( 'Keep a log of user log in activity on custom login forms (such as WooCommerce & membership plugins)', 'mainwp-child' ),
                'woocommerce' => esc_html__( 'Keep a log of visitor orders, stock changes and other public events?', 'mainwp-child' ),
            );

            $frontend_codes = array(
                'register'     => 4000,
                'login'        => 1000,
                'woocommerce'  => 9035,
                'gravityforms' => 5709,
            );

            foreach ( $current_enabled as $frontend_event => $value ) {
                if ( $value !== $fresh_enabled[ $frontend_event ] ) {
                    static::trigger_event(
                        6060,
                        array(
                            'ID'          => $frontend_codes[ $frontend_event ],
                            'description' => $frontend_labels[ $frontend_event ],
                            'EventType'   => ( $fresh_enabled[ $frontend_event ] ) ? 'enabled' : 'disabled',
                        )
                    );
                }
            }
        } else {
            // Grab currently saved list of disabled events for comparison.
            $currently_disabled = Changes_Settings_Helper::get_disabled_logs();

            // Further remove items which are disabled in the UI but potentially not saved as disabled in the settings yet (for example fresh install).
            $obsolete_events  = array( 9999, 2126, 99999, 0000, 0001, 0002, 0003, 0004, 0005, 0006 );
            $obsolete_events  = apply_filters( 'mainwp_child_changes_logs_togglelogs_obsolete_events', $obsolete_events );
            $ms_user_logs     = ( ! Changes_WP_Helper::is_multisite() ) ? array_keys( self::get_logs_by_category( 'User' ) ) : array();
            $deprecated_event = static::get_deprecated_events();
            $always_disabled  = Changes_Settings_Helper::get_default_always_disabled_logs();
            $events_to_ignore = array_merge( $obsolete_events, $always_disabled, $deprecated_event, $ms_user_logs );

            // Remove items we dont want to trigger logs for here.
            $disabled = array_diff( $disabled, $events_to_ignore );

            // Check for events which are newly enabled.
            foreach ( $enabled as $enabled_log_id ) {
                if ( in_array( $enabled_log_id, $currently_disabled, true ) ) {
                    $log_data = Changes_Logs_Helper::get_log( $enabled_log_id );
                    static::trigger_event(
                        6060,
                        array(
                            'ID'          => $enabled_log_id,
                            'description' => $log_data['desc'],
                            'EventType'   => 'enabled',
                        )
                    );
                }
            }

            // Check for events which are newly disabled.
            foreach ( $disabled as $disabled_log_id ) {
                if ( ! in_array( $disabled_log_id, $currently_disabled, true ) ) {
                    $log_data = Changes_Logs_Helper::get_log( $disabled_log_id );
                    static::trigger_event(
                        6060,
                        array(
                            'ID'          => $disabled_log_id,
                            'description' => $log_data['desc'],
                            'EventType'   => 'disabled',
                        )
                    );
                }
            }
        }
    }
}
