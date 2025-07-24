<?php
/**
 * Logger: User Profile
 *
 * User profile file.
 *
 * @since 5.5
 * @package MainWP/Child
 */

namespace MainWP\Child\Changes;

// Exit.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * User Profiles.
 */
class Changes_Handle_BBPress_User {

    /**
     * Class cache to store the state of the plugin.
     *
     * @var bool
     */
    private static $plugin_active = null;

    /**
     * Listening to events using WP hooks.
     */
    public static function init_hooks() {
        if ( static::is_bbpress_active() ) {
            add_action( 'profile_update', array( __CLASS__, 'callback_change_user_updated' ), 10, 2 );
            add_action( 'set_user_role', array( __CLASS__, 'callback_change_user_role_changed' ), 10, 3 );
        }
    }

    /**
     * Checks if the BBPress is active.
     *
     * @return bool
     */
    public static function is_bbpress_active() {
        if ( null === self::$plugin_active ) {
            self::$plugin_active = Changes_Helper::is_plugin_active( 'bbpress/bbpress.php' );
        }

        return self::$plugin_active;
    }

    /**
     * Method: Support for email change
     * alert.
     *
     * @param int     $user_id      - User ID.
     * @param WP_User $old_userdata - Old WP_User object.
     */
    public static function callback_change_user_updated( $user_id, $old_userdata ) {
        $new_userdata = get_userdata( $user_id );

        $bbpress_roles = array( 'bbp_spectator', 'bbp_moderator', 'bbp_participant', 'bbp_keymaster', 'bbp_blocked' );

        $old_roles_list = array_intersect( $bbpress_roles, $old_userdata->roles );
        $new_roles_list = array_intersect( $bbpress_roles, $new_userdata->roles );

        $old_roles_list = Changes_Helper::get_role_names( $old_roles_list );
        $new_roles_list = Changes_Helper::get_role_names( $new_roles_list );

        if ( $old_roles_list !== $new_roles_list ) {
            $current_user = Changes_Helper::change_get_current_user();
            $log_data     = array(
                'changeusername' => $new_userdata->user_login,
                'oldrole'        => $old_roles_list,
                'newrole'        => $new_roles_list,
                'actionuser'     => $current_user->user_login,
                'firstname'      => $new_userdata->user_firstname,
                'lastname'       => $new_userdata->user_lastname,
            );
            Changes_Logs_Logger::log_change( 1620, $log_data );
        }
    }

    /**
     * When a user role is changed.
     *
     * @param int    $user_id   - User ID of the user.
     * @param string $new_role  - New role.
     * @param array  $old_roles - Array of old roles.
     */
    public static function callback_change_user_role_changed( $user_id, $new_role, $old_roles ) {
        $user = get_userdata( $user_id );

        if ( ! $user ) {
            return;
        }

        $bbpress_roles = array( 'bbp_spectator', 'bbp_moderator', 'bbp_participant', 'bbp_keymaster', 'bbp_blocked' );
        $old_roles     = array_diff( $old_roles, $bbpress_roles );
        $new_roles     = array_diff( $user->roles, $bbpress_roles );

        $old_roles = Changes_Helper::get_role_names( $old_roles );
        $new_roles = Changes_Helper::get_role_names( $new_roles );

        if ( $old_roles !== $new_roles ) {
            $log_data = array(
                'changeuserid'   => $user_id,
                'changeusername' => $user->user_login,
                'oldrole'        => $old_roles,
                'newrole'        => $new_roles,
                'firstname'      => $user->user_firstname,
                'lastname'       => $user->user_lastname,
                'multisite_blog' => Changes_Helper::is_multisite() ? Changes_Helper::get_blog_id() : false,
                'changeuserdata' => (object) array(
                    'username'  => $user->user_login,
                    'firstname' => $user->user_firstname,
                    'lastname'  => $user->user_lastname,
                    'userroles' => $new_roles ? $new_roles : 'none',
                ),
            );
            Changes_Logs_Logger::log_change_save_delay( 1615, $log_data );
        }
    }
}
