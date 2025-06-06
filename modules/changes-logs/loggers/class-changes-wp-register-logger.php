<?php
/**
 * Logger: Register
 *
 * Register logger class file.
 *
 * @since      5.4.1
 * @package    mainwp/child
 */

declare(strict_types=1);

namespace MainWP\Child\Changes\Loggers;

use MainWP\Child\Changes\Helpers\Changes_WP_Helper;
use MainWP\Child\Changes\Helpers\Changes_Settings_Helper;
use MainWP\Child\Changes\Changes_Logs_Manager;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * User registration sensor.
 *
 * 4000 New user was created on WordPress
 */
class Changes_WP_Register_Logger {

    /**
     * Is that a login logger or not?
     * Sensors doesn't need to have this property, except where they explicitly not to set that value.
     *
     * @var boolean
     *
     */
    private static $login_sensor = true;

    /**
     * Inits the main hooks
     *
     * @return void
     *
     */
    public static function init() {
        /*
            * Default WordPress registration utilizes action 'register_new_user', but we cannot rely on it to detect
            * a front-end registration implemented by a third party. We hook into the action 'user_register' because it is
            * part of the function 'wp_insert_user' that definitely runs.
            */
        \add_action( 'user_register', array( __CLASS__, 'event_user_register' ), 10, 1 );
    }

    /**
     * Is that a front end sensor? The sensors doesn't need to have that method implemented, except if they want to specifically set that value.
     *
     * @return boolean
     *
     */
    public static function is_login_sensor() {
        return self::$login_sensor;
    }

    /**
     * That needs to be registered as a frontend sensor, when the admin sets the plugin to monitor the login from 3rd parties.
     *
     * @return boolean
     *
     * @since 4.5.1
     */
    public static function is_frontend_sensor(): bool {
        $frontend_events = Changes_Settings_Helper::get_frontend_events();
        $should_load     = ! empty( $frontend_events['register'] ) || ! empty( $frontend_events['login'] ) || ! empty( $frontend_events['woocommerce'] );

        if ( $should_load ) {
            return true;
        }

        return false;
    }

    /**
     * When a user registers, action 'user_register' is fired because it is part of the function 'wp_insert_user'. We
     * can assume event 4000 if the current session is not logged in.
     *
     * @param int $user_id - User ID of the registered user.
     *
     */
    public static function event_user_register( $user_id ) {
        if ( is_user_logged_in() ) {
            // We bail if the user is logged in. That is no longer user registration, but user creation.
            return;
        }

        $user = get_userdata( $user_id );
        if ( ! $user instanceof \WP_User ) {
            // Bail if the user is not valid for some reason.
            return;
        }

        $new_user_data = array(
            'Username'  => $user->user_login,
            'Email'     => $user->user_email,
            'FirstName' => ( $user->user_firstname ) ?? '',
            'LastName'  => ( $user->user_lastname ) ?? '',
            'Roles'     => is_array( $user->roles ) ? implode( ', ', $user->roles ) : $user->roles,
        );

        $event_data = array(
            'NewUserID'    => $user_id,
            'NewUserData'  => (object) $new_user_data,
            'EditUserLink' => \add_query_arg( 'user_id', $user_id, \network_admin_url( 'user-edit.php' ) ),
        );

        if ( function_exists( 'get_current_user_id' ) ) {
            $event_data['Username'] = 'System';
        }

        if ( Changes_WP_Helper::is_multisite() ) {
            // Registration should not be logged on multisite if event 4024 is fired.
            Changes_Logs_Manager::trigger_event_if(
                4000,
                $event_data,
                /**
                * Don't log if event 4024 is fired.
                */
                function () {
                    return ! Changes_Logs_Manager::will_trigger( 4013 );
                }
            );
        } else {
            Changes_Logs_Manager::trigger_event( 4000, $event_data, true );
        }
    }
}
