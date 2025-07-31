<?php
/**
 * Logger: Register
 *
 * Register logger class file.
 *
 * @since      5.5
 * @package    mainwp/child
 */

namespace MainWP\Child\Changes;

// Exit.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * User registration.
 */
class Changes_Handle_WP_Register {

    /**
     * Is that a login logger or not?
     *
     * @var boolean
     */
    private static $login_logging = true;

    /**
     * Inits the main hooks
     *
     * @return void
     */
    public static function init_hooks() {
        \add_action( 'user_register', array( __CLASS__, 'callback_change_user_register' ), 10, 1 );
    }

    /**
     * Is that a front end?.
     *
     * @return boolean
     */
    public static function is_login_logging() {
        return self::$login_logging;
    }

    /**
     * That needs to be registered as a frontend.
     *
     * @return boolean
     */
    public static function is_frontend_logger() {
        $frontend_events = Changes_Helper::get_frontend_events();
        $should_load     = ! empty( $frontend_events['register'] ) || ! empty( $frontend_events['login'] ) || ! empty( $frontend_events['woocommerce'] );

        if ( $should_load ) {
            return true;
        }

        return false;
    }

    /**
     * When a user registers.
     *
     * @param int $user_id - User ID of the user.
     */
    public static function callback_change_user_register( $user_id ) {
        if ( is_user_logged_in() ) {
            return;
        }

        $user = get_userdata( $user_id );
        if ( ! $user instanceof \WP_User ) {
            return;
        }

        $new_user_data = array(
            'username'  => $user->user_login,
            'firstname' => ( $user->user_firstname ) ?? '',
            'lastname'  => ( $user->user_lastname ) ?? '',
            'userroles' => is_array( $user->roles ) ? implode( ', ', $user->roles ) : $user->roles,
        );

        $log_data = array(
            'newuserid'   => $user_id,
            'newuserdata' => (object) $new_user_data,
        );

        if ( function_exists( 'get_current_user_id' ) ) {
            $log_data['username'] = 'System';
        }
        Changes_Logs_Logger::log_change_save_delay( 1605, $log_data ); // ok.
    }
}
