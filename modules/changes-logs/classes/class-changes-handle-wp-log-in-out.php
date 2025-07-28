<?php
/**
 * Logger: Login/Logout.
 *
 * Login/Logout class file.
 *
 * @since     5.5
 * @package   mainwp/child
 */

namespace MainWP\Child\Changes;

// Exit.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Login/Logout.
 */
class Changes_Handle_WP_Log_In_Out {

    /**
     * Current user object
     *
     * @var \WP_User
     */
    private static $current_user = null;

    /**
     * Is that a lgin or not?
     *
     * @var boolean
     */
    private static $login_logging = true;

    /**
     * Keeps a queue of executed events (login and logout)
     *
     * @var array
     */
    private static $login_queue = array();

    /**
     * Inits the main hooks
     *
     * @return void
     */
    public static function init_hooks() {
        \add_action( 'clear_auth_cookie', array( __CLASS__, 'change_get_current_user' ), 10 );
        \add_action( 'lostpassword_post', array( __CLASS__, 'callback_change_user_requested_pw_reset' ), 10, 2 );
        \add_action( 'password_reset', array( __CLASS__, 'callback_change_password_reset' ), 10, 2 );
        \add_action( 'set_auth_cookie', array( __CLASS__, 'callback_change_login' ), 10, 6 );
        \add_action( 'shutdown', array( __CLASS__, 'callback_change_shutdown_empty_queue' ), 7 );
        \add_action( 'wp_logout', array( __CLASS__, 'callback_change_logout' ), 5 );
        if ( Changes_Helper::is_plugin_active( 'user-switching/user-switching.php' ) ) {
            \add_action( 'switch_to_user', array( __CLASS__, 'callback_change_user_switched_event' ), 10, 2 );
        }
    }

    /**
     * Is that a front end? The doesn't need to have that method implemented, except if they want to specifically set that value.
     *
     * @return boolean
     */
    public static function is_login_logging() {
        return self::$login_logging;
    }

    /**
     * That needs to be registered as a frontend, when the admin sets the plugin to monitor the login from 3rd parties.
     *
     * @return boolean
     */
    public static function is_frontend_logger() {
        $frontend_events = Changes_Helper::get_frontend_events();
        $should_load     = ! empty( $frontend_events['register'] ) || ! empty( $frontend_events['login'] );

        if ( $should_load ) {
            return true;
        }

        return false;
    }

    /**
     * Sets current user.
     */
    public static function change_get_current_user() {

        if ( ! empty( self::$login_queue ) ) {
            self::$login_queue['logout'] = true;
        }

        self::$current_user = Changes_Helper::change_get_current_user();
    }

    /**
     * Change Login.
     *
     * @param string $auth_cookie Authentication cookie value.
     * @param int    $expire      The time the login grace period expires as a UNIX timestamp.
     *                            Default is 12 hours.
     * @param int    $expiration  The time when the authentication cookie expires as a UNIX timestamp.
     *                            Default is 14 days.
     * @param int    $user_id     User ID.
     * @param string $scheme      Authentication scheme.
     * @param string $token       User's session token to use for this cookie.
     */
    public static function callback_change_login( $auth_cookie, $expire, $expiration, $user_id, $scheme, $token ) {
        $post_vars = filter_input_array( INPUT_POST );

        if ( isset( $post_vars['_um_account'] ) && isset( $post_vars['_um_account_tab'] ) && 'password' === $post_vars['_um_account_tab'] ) {
            if ( isset( $post_vars['current_user_password'] ) // Previous password.
            && isset( $post_vars['user_password'] ) // New password.
            && isset( $post_vars['confirm_user_password'] ) // Confirm new password.
            && $post_vars['current_user_password'] !== $post_vars['user_password'] // If current & new password don't match.
            && $post_vars['user_password'] === $post_vars['confirm_user_password'] ) { // And new & confirm password are same then.
                $user = get_user_by( 'id', $user_id );

                if ( ! empty( $user ) ) {
                    $user_roles = Changes_Helper::get_user_roles( $user );
                    $log_data   = array(
                        'username'         => $user->user_login,
                        'currentuserid'    => $user->ID,
                        'currentuserroles' => $user_roles,
                    );
                    Changes_Logs_Logger::log_change_save_delay( 1625, $log_data );
                }
            }
            return;
        }

        $user = get_user_by( 'id', $user_id );
        if ( ! is_a( $user, '\WP_User' ) ) {
            return;
        }
        $user_login = $user->data->user_login;
        $user_roles = Changes_Helper::get_user_roles( $user );

        $log_data = array(
            'username'         => $user_login,
            'currentuserid'    => $user_id,
            'currentuserroles' => $user_roles,
        );

        self::$login_queue['login'] = $log_data;
    }

    /**
     * Change Logout.
     */
    public static function callback_change_logout() {
        if ( self::$current_user->ID ) {
            $log_data = array(
                'currentuserid'    => self::$current_user->ID,
                'currentuserroles' => Changes_Helper::get_user_roles( self::$current_user ),
            );
            Changes_Logs_Logger::log_change_save_delay( 1575, $log_data );
        }
    }

    /**
     * Changed password.
     *
     * @param WP_User $user - User object.
     * @param string  $new_pass - New Password.
     */
    public static function callback_change_password_reset( $user, $new_pass ) {
        if ( ! empty( $user ) ) {
            $user_roles = Changes_Helper::get_user_roles( $user );
            $log_data   = array(
                'username'         => $user->user_login,
                'currentuserid'    => $user->ID,
                'currentuserroles' => $user_roles,
            );
            Changes_Logs_Logger::log_change_save_delay( 1625, $log_data );
        }
    }

    /**
     * User Switched.
     *
     * Current user switched to another user event.
     *
     * @param int $new_user_id - New user id.
     * @param int $old_user_id - Old user id.
     */
    public static function callback_change_user_switched_event( $new_user_id, $old_user_id ) {
        $target_user       = \get_user_by( 'ID', $new_user_id );
        $target_user_roles = Changes_Helper::get_user_roles( $target_user );
        $target_user_roles = implode( ', ', $target_user_roles );
        $old_user          = \get_user_by( 'ID', $old_user_id );
        $old_user_roles    = Changes_Helper::get_user_roles( $old_user );
        $log_data          = array(
            'changeusername'   => $target_user->user_login,
            'changeuserrole'   => $target_user_roles,
            'username'         => $old_user->user_login,
            'currentuserid'    => $old_user->ID,
            'currentuserroles' => $old_user_roles,
        );
        Changes_Logs_Logger::log_change( 1595, $log_data );
    }

    /**
     * User has requested a password reset.
     *
     * @param object $errors Current WP_errors object.
     * @param object $user   User making the request.
     */
    public static function callback_change_user_requested_pw_reset( $errors, $user = null ) {

        if ( is_null( $user ) || ! isset( $user->roles ) ) {
            return;
        }

        $user_roles = Changes_Helper::get_user_roles( $user );
        $log_data   = array(
            'username'         => $user->user_login,
            'currentuserroles' => $user_roles,
            'currentuserid'    => $user->ID,
        );
        Changes_Logs_Logger::log_change_save_delay( 1600, $log_data );
    }

    /**
     * Checks queue with the logged events and executes them.
     *
     * @return void
     */
    public static function callback_change_shutdown_empty_queue() {
        if ( ! empty( self::$login_queue ) && isset( self::$login_queue['login'] ) && ! isset( self::$login_queue['logout'] ) ) {
            Changes_Logs_Logger::log_change_save_delay( 1570, self::$login_queue['login'] );
        }
    }
}
