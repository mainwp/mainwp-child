<?php
/**
 * Helper Class
 *
 * @package    mainwp/child
 *
 * @since      5.5
 */

namespace MainWP\Child\Changes;

defined( 'ABSPATH' ) || exit; // Exit.

/**
 * All the user related settings must go trough this class.
 */
class Changes_Helper {


    /**
     * The main IP of the client.
     *
     * @var string
     */
    private static $client_ip = '';


    /**
     * List of the ignored WP Post types.
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
         * Option name changes logs DB version.
         *
         * @var string
         */
    public const CHANGES_LOGS_DB_OPTION_NAME = 'mainwp_child_changes_logs_db_version';

    /**
     * The class user variable
     *
     * @var \WP_User
     */
    private static $user = null;

    /**
     * The cache for the current WP user.
     *
     * @var \WP_User
     */
    private static $current_user = null;



    /**
     * GMT Offset
     *
     * @var string
     */
    private static $gmt_offset_sec = 0;

    /**
     * Date format.
     *
     * @var string
     */
    private static $date_format;

    /**
     * Time format.
     *
     * @var string
     */
    private static $time_format;

    /**
     * Datetime format.
     *
     * @var string
     */
    private static $datetime_format;

    /**
     * Keeps the value of the multisite install of the WP.
     *
     * @var bool
     */
    private static $is_multisite = null;

    /**
     * Array with all the sites in multisite WP installation.
     *
     * @var array
     */
    private static $sites = array();

    /**
     * The array with the disabled logs codes.
     *
     * @var array
     */
    private static $disabled_log_types = null;

    /**
     * Is the database logging enabled or not.
     *
     * @var bool
     */
    private static $frontend_events = null;

    /**
     * Check is this is a multisite setup.
     *
     * @return bool
     */
    public static function is_multisite() {
        if ( null === self::$is_multisite ) {
            self::$is_multisite = function_exists( 'is_multisite' ) && is_multisite();
        }

        return \apply_filters( 'mainwp_child_changes_logs_override_is_multisite', self::$is_multisite );
    }


    /**
     * Collects all the sites from multisite WP installation.
     */
    public static function change_get_multi_sites() {
        if ( self::is_multisite() ) {
            if ( empty( self::$sites ) ) {
                self::$sites = \get_sites();
            }

            return self::$sites;
        }

        return array();
    }

    /**
     * Returns the currently set user.
     *
     * @return \WP_User
     */
    public static function get_user() {
        if ( null === self::$user ) {
            self::set_user();
        }

        return self::$user;
    }


    /**
     * Sets the user
     *
     * @param null|int|\WP_User $user - The WP user that must be used.
     *
     * @return void
     */
    public static function set_user( $user = null ) {
        if ( $user instanceof \WP_User ) {
            if ( isset( self::$user ) && $user === self::$user ) {
                return;
            }
            self::$user = $user;
        } elseif ( false !== ( filter_var( $user, FILTER_VALIDATE_INT ) ) ) {
            if ( isset( self::$user ) && $user instanceof \WP_User && $user === self::$user->ID ) {
                return;
            }
            if ( ! function_exists( 'get_user_by' ) ) {
                require ABSPATH . WPINC . '/pluggable.php';
            }
            self::$user = \get_user_by( 'id', $user );
            if ( \is_bool( self::$user ) ) {
                self::$user = \wp_get_current_user();
            }
        } elseif ( is_string( $user ) && ! empty( trim( $user ) ) ) {
            if ( isset( self::$user ) && $user instanceof \WP_User && $user === self::$user->ID ) {
                return;
            }
            if ( ! function_exists( 'get_user_by' ) ) {
                require ABSPATH . WPINC . '/pluggable.php';
            }
            self::$user = \get_user_by( 'login', $user );
        } else {
            if ( ! function_exists( 'wp_get_current_user' ) ) {
                require ABSPATH . WPINC . '/pluggable.php';
                wp_cookie_constants();
            }
            self::$user = \wp_get_current_user();
        }
    }


    /**
     * Returns the roles for the given user (or the current one).
     *
     * @param null|int|\WP_User $user - The WP user.
     *
     * @return array
     */
    public static function get_user_roles( $user = null ) {
        self::change_proper_user( $user );

        $roles = self::$user->roles;

        if ( self::is_multisite() ) {

            $blogs = get_blogs_of_user( self::$user->ID );
            foreach ( $blogs as $blog ) {
                $user_obj = new \WP_User( self::$user->ID, '', $blog->userblog_id );

                $roles = \array_merge( $roles, $user_obj->roles );
            }

            $roles = array_unique( $roles );
        }

        if ( ! isset( $roles ) ) {
            $roles = array();
        }
        if ( ! is_array( $roles ) ) {
            $roles = (array) $roles;
        }

        if ( count( $roles ) > 1 && in_array( 'administrator', $roles, true ) ) {
            array_unshift( $roles, 'administrator' );
            $roles = array_unique( $roles );
        }

        if ( self::is_multisite() && is_super_admin( self::$user->ID ) ) {
            $roles[] = 'superadmin';
            if ( count( $roles ) > 1 ) {
                array_unshift( $roles, 'superadmin' );
                $roles = array_unique( $roles );
            }
        }

        return (array) $roles;
    }


    /**
     * Caches the current user WP object - this method is used to avoid unnecessary database queries to core WP functions that returns the current user object. It stores the value of the current user in the class variable and returns it when needed.
     *
     * @return \WP_User
     */
    public static function change_get_current_user() {
        if ( null === self::$current_user ) {
            self::$current_user = \wp_get_current_user();
        }

        return self::$current_user;
    }


    /**
     * Sets the local variable class based on the given parameter.
     *
     * @param null|int|\WP_User $user - The WP user we should extract the meta data for.
     *
     * @return void
     */
    private static function change_proper_user( $user = null ) {
        if ( null !== $user ) {
            self::set_user( $user );
        } else {
            self::get_user();
        }
    }


    /**
     * Filters request data.
     *
     * @return array Filtered request data.
     */
    public static function get_filtered_request_data() {
        $result = array();

        $get_data = filter_input_array( INPUT_GET );
        if ( is_array( $get_data ) ) {
            $result = array_merge( $result, $get_data );
        }

        $post_data = filter_input_array( INPUT_POST );
        if ( is_array( $post_data ) ) {
            $result = array_merge( $result, $post_data );
        }

        return $result;
    }



    /**
     * Check if the float is IPv4 instead.
     *
     * @param float $ip_address - Number to check.
     *
     * @return bool result validation
     */
    public static function is_ip_address( $ip_address ) {
        return filter_var( $ip_address, FILTER_VALIDATE_IP ) !== false;
    }


    /**
     * Validate IP address.
     *
     * @param string $ip - IP address.
     *
     * @return string|bool
     */
    public static function validate_ip( $ip ) {
        $opts = FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6;

        $opts = $opts | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;

        $filtered_ip = filter_var( $ip, FILTER_VALIDATE_IP, $opts );

        if ( ! $filtered_ip || empty( $filtered_ip ) ) {
            return false;
        } else {
            return $filtered_ip;
        }
    }

    /**
     * Formats date time based on various requirements.
     *
     * @param float  $timestamp              Timestamp.
     * @param string $type                   Output type.
     *
     * @return string
     */
    public static function get_formatted_date( $timestamp, $type = 'datetime' ) {

        if ( null === self::$date_format ) {
            self::$gmt_offset_sec  = get_option( 'gmt_offset' ) * HOUR_IN_SECONDS;
            self::$date_format     = get_option( 'date_format' );
            self::$time_format     = get_option( 'time_format' );
            self::$datetime_format = get_option( 'date_format' );
        }

        $result = '';
        $format = null;
        switch ( $type ) {
            case 'datetime':
                $format = self::$datetime_format;
                break;
            case 'date':
                $format = self::$date_format;
                break;
            case 'time':
                $format = self::$time_format;
                break;
            default:
                return $result;
        }

        if ( null === $format ) {
            return $result;
        }

        $tz_adj_timestamp = $timestamp + self::$gmt_offset_sec;
        $result           = date_i18n( $format, $tz_adj_timestamp ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
        return $result;
    }

    /**
     * Whether a plugin is active.
     *
     * @param string $plugin Path to the main plugin file.
     *
     * @return bool True|False.
     */
    public static function is_plugin_active( $plugin ) {
        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $is_active = \is_plugin_active( $plugin );

        if ( ! $is_active && self::is_multisite() ) {
            $sites = self::change_get_multi_sites();
            foreach ( $sites as $site ) {
                \switch_to_blog( $site->blog_id );

                if ( in_array( $plugin, (array) \get_option( 'active_plugins', array() ) ) ) {
                    $is_active = true;

                    \restore_current_blog();

                    return $is_active;
                }

                \restore_current_blog();
            }
        }

        return $is_active;
    }


    /**
     * Checks if we are currently on the login screen.
     */
    public static function is_login_screen() {
        $login = parse_url( site_url( 'wp-login.php' ), PHP_URL_PATH ) === parse_url( \wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        return \apply_filters( 'mainwp_child_changes_logs_login_screen_url', $login );
    }

    /**
     * Checks if we are currently on the login screen.
     */
    public static function is_login_page() {

        $login = parse_url( site_url( 'wp-login.php' ), PHP_URL_PATH ) === parse_url( \wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        return \apply_filters( 'mainwp_child_changes_logs_login_page', $login );
    }

    /**
     * Get role names.
     *
     * @param string $roles - User role.
     * @param bool   $listing - Return list or array of user role.
     *
     * @return string
     */
    public static function get_role_names( $roles, $listing = true ) {
        $names = array_map(
            function ( $role ) {
                global  $wp_roles;
                return isset( $wp_roles->role_names[ $role ] ) ? $wp_roles->role_names[ $role ] : false;
            },
            $roles
        );

        if ( $listing ) {
            return is_array( $names ) ? implode( ', ', $names ) : '';
        }

        return is_array( $names ) ? $names : array();
    }

    /**
     * Get main client IP.
     *
     * @return string|null
     */
    public static function change_get_client_ip() {
        if ( '' === self::$client_ip && isset( $_SERVER['REMOTE_ADDR'] ) ) {
            $ip              = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
            self::$client_ip = static::normalize_ip( $ip );
            if ( ! static::validate_ip( self::$client_ip ) ) {
                self::$client_ip = 'Error: Invalid IP Address';
            }
        }
        return self::$client_ip;
    }

    /**
     * Normalize IP address.
     *
     * @param string $ip - IP address.
     *
     * @return string
     */
    public static function normalize_ip( $ip ) {
        $ip = trim( $ip );

        if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6 ) ) {
            return $ip;
        }

        $ip = parse_url( 'http://' . $ip, PHP_URL_HOST );

        $ip = str_replace( array( '[', ']' ), '', $ip );

        return $ip;
    }

    /**
     * Get current blog id.
     *
     * @return int Blog ID
     */
    public static function get_blog_id() {
        return function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 0;
    }

    /**
     * Return IDs of disabled logs.
     *
     * @return array
     */
    public static function get_disabled_logs_types() {
        if ( null === self::$disabled_log_types ) {
            $disabled_types = apply_filters( 'mainwp_child_changes_logs_disabled_logs', array() );

            if ( ! is_array( $disabled_types ) ) {
                $disabled_types = \explode( ',', $disabled_types );

                \array_walk( $disabled_types, 'trim' );
            }
            self::$disabled_log_types = array_map( 'intval', $disabled_types );
        }

        if ( ! is_array( self::$disabled_log_types ) ) {
            self::$disabled_log_types = array();
        }

        return self::$disabled_log_types;
    }

    /**
     * Get frontend events option.
     *
     * @return array
     */
    public static function get_frontend_events() {
        if ( null === self::$frontend_events ) {
            $default               = array(
                'register'     => false,
                'login'        => false,
                'woocommerce'  => false,
                'gravityforms' => false,
            );
            self::$frontend_events = $default;
        }
        return self::$frontend_events;
    }
}
