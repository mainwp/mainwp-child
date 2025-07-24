<?php
/**
 * Logger: MainWP actions.
 *
 * MainWP actions class file.
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
class Changes_Handle_WP_MainWP {

    /**
     * Inits the main hooks
     *
     * @return void
     */
    public static function init_hooks() {
        \add_action( 'mainwp_child_login_required_authed', array( __CLASS__, 'callback_change_mainwp_login_required_authed' ), 10 );
    }

    /**
     * MainWP login required authed.
     *
     * @param string $username Authed login required user.
     */
    public static function callback_change_mainwp_login_required_authed( $username ) {
        $cur_user = Changes_Helper::change_get_current_user();
        if ( $cur_user && $cur_user->ID && $username === $cur_user->user_login ) {
            $data = array(
                'username'         => $cur_user->user_login,
                'currentuserid'    => $cur_user->ID,
                'currentuserroles' => Changes_Helper::get_user_roles( $cur_user ),
            );
            Changes_Logs_Logger::log_change( 1596, $data );
        }
    }
}
