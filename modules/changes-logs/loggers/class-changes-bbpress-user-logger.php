<?php
/**
 * Logger: User Profile
 *
 * User profile sensor file.
 *
 * @since 5.4.1
 * @package MainWP/Child
 */

declare(strict_types=1);

namespace MainWP\Child\Changes\Loggers;

use MainWP\Child\Changes\Helpers\Changes_WP_Helper;
use MainWP\Child\Changes\Helpers\Changes_User_Helper;
use MainWP\Child\Changes\Changes_Logs_Manager;
use MainWP\Child\Changes\Helpers\Changes_BBPress_Helper;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * User Profiles sensor.
 *
 * 4000 New user was created on WordPress
 * 4001 User created another WordPress user
 * 4002 The role of a user was changed by another WordPress user
 * 4003 User has changed his or her password
 * 4004 User changed another user's password
 * 4005 User changed his or her email address
 * 4006 User changed another user's email address
 * 4007 User was deleted by another user
 * 4008 User granted Super Admin privileges
 * 4009 User revoked from Super Admin privileges
 * 4013 The forum role of a user was changed by another WordPress user
 * 4014 User opened the profile page of another user
 *
 */
class Changes_BBPress_User_Logger {

    /**
     * List of super admins.
     *
     * @var array
     *
     * @since 4.6.0
     */
    protected $old_superadmins;

    /**
     * Listening to events using WP hooks.
     *
     * @since 4.6.0
     */
    public static function init() {
        if ( Changes_BBPress_Helper::is_bbpress_active() ) {
            add_action( 'profile_update', array( __CLASS__, 'event_user_updated' ), 10, 2 );
            add_action( 'set_user_role', array( __CLASS__, 'event_user_role_changed' ), 10, 3 );
        }
    }

    /**
     * Method: Support for Ultimate Member email change
     * alert.
     *
     * @param int     $user_id      - User ID.
     * @param WP_User $old_userdata - Old WP_User object.
     *
     * @since 4.6.0
     */
    public static function event_user_updated( $user_id, $old_userdata ) {
        // Get new user data.
        $new_userdata = get_userdata( $user_id );

        // BBPress user roles.
        $bbpress_roles = array( 'bbp_spectator', 'bbp_moderator', 'bbp_participant', 'bbp_keymaster', 'bbp_blocked' );

        // Get bbpress user roles data.
        $old_bbpress_roles = array_intersect( $bbpress_roles, $old_userdata->roles );
        $new_bbpress_roles = array_intersect( $bbpress_roles, $new_userdata->roles );

        $old_bbpress_roles = array_map( array( __CLASS__, 'filter_role_names' ), $old_bbpress_roles );
        $new_bbpress_roles = array_map( array( __CLASS__, 'filter_role_names' ), $new_bbpress_roles );

        // Convert array to string.
        $old_bbpress_roles = is_array( $old_bbpress_roles ) ? implode( ', ', $old_bbpress_roles ) : '';
        $new_bbpress_roles = is_array( $new_bbpress_roles ) ? implode( ', ', $new_bbpress_roles ) : '';

        if ( $old_bbpress_roles !== $new_bbpress_roles ) {
            $current_user = Changes_User_Helper::get_current_user();
            Changes_Logs_Manager::trigger_event(
                8023,
                array(
                    'TargetUsername' => $new_userdata->user_login,
                    'OldRole'        => $old_bbpress_roles,
                    'NewRole'        => $new_bbpress_roles,
                    'UserChanger'    => $current_user->user_login,
                    'FirstName'      => $new_userdata->user_firstname,
                    'LastName'       => $new_userdata->user_lastname,
                    'EditUserLink'   => add_query_arg( 'user_id', $new_userdata->ID, \network_admin_url( 'user-edit.php' ) ),
                )
            );
        }
    }

    /**
     * Triggered when a user role is changed.
     *
     * @param int    $user_id   - User ID of the user.
     * @param string $new_role  - New role.
     * @param array  $old_roles - Array of old roles.
     *
     * @since 4.6.0
     */
    public static function event_user_role_changed( $user_id, $new_role, $old_roles ) {
        // Get WP_User object.
        $user = get_userdata( $user_id );

        // Check if $user is false then return.
        if ( ! $user ) {
            return;
        }

        // If BBPress plugin is active then check for user roles change.
        // BBPress user roles.
        $bbpress_roles = array( 'bbp_spectator', 'bbp_moderator', 'bbp_participant', 'bbp_keymaster', 'bbp_blocked' );

        // Set WP roles.
        $old_roles = array_diff( $old_roles, $bbpress_roles );
        $new_roles = array_diff( $user->roles, $bbpress_roles );
        $old_roles = array_map( array( __CLASS__, 'filter_role_names' ), $old_roles );
        $new_roles = array_map( array( __CLASS__, 'filter_role_names' ), $new_roles );

        // Get roles.
        $old_roles = is_array( $old_roles ) ? implode( ', ', $old_roles ) : '';
        $new_roles = is_array( $new_roles ) ? implode( ', ', $new_roles ) : '';

        // Alert if roles are changed.
        if ( $old_roles !== $new_roles ) {
            Changes_Logs_Manager::trigger_event_if(
                4002,
                array(
                    'TargetUserID'   => $user_id,
                    'TargetUsername' => $user->user_login,
                    'OldRole'        => $old_roles,
                    'NewRole'        => $new_roles,
                    'FirstName'      => $user->user_firstname,
                    'LastName'       => $user->user_lastname,
                    'EditUserLink'   => add_query_arg( 'user_id', $user_id, \network_admin_url( 'user-edit.php' ) ),
                    'multisite_text' => Changes_WP_Helper::is_multisite() ? get_current_blog_id() : false,
                    'TargetUserData' => (object) array(
                        'Username'  => $user->user_login,
                        'FirstName' => $user->user_firstname,
                        'LastName'  => $user->user_lastname,
                        'Email'     => $user->user_email,
                        'Roles'     => $new_roles ? $new_roles : 'none',
                    ),
                ),
                array( __CLASS__, 'must_not_contain_user_changes' )
            );
        }
    }

    /**
     * Triggered when a user is deleted.
     *
     * @param int $user_id - User ID of the registered user.
     *
     * @since 4.6.0
     */
    public static function event_user_deleted( $user_id ) {
        $user = get_userdata( $user_id );
        $role = is_array( $user->roles ) ? implode( ', ', $user->roles ) : $user->roles;
        Changes_Logs_Manager::trigger_event_if(
            4007,
            array(
                'TargetUserID'   => $user_id,
                'TargetUserData' => (object) array(
                    'Username'  => $user->user_login,
                    'FirstName' => $user->user_firstname,
                    'LastName'  => $user->user_lastname,
                    'Email'     => $user->user_email,
                    'Roles'     => $role ? $role : 'none',
                ),
            ),
            array( __CLASS__, 'must_not_contain_create_user' )
        );
    }

    /**
     * Triggered when a user profile is opened.
     *
     * @param object $user - Instance of WP_User.
     *
     * @since 4.6.0
     */
    public static function event_open_profile( $user ) {
        if ( ! $user ) {
            return;
        }
        $current_user = Changes_User_Helper::get_current_user();
        $updated      = isset( $_GET['updated'] );
        if ( $current_user && ( $user->ID !== $current_user->ID ) && ! $updated ) {
            $user_roles = implode( ', ', array_map( array( __CLASS__, 'filter_role_names' ), $user->roles ) );
            Changes_Logs_Manager::trigger_event(
                4014,
                array(
                    'UserChanger'    => $current_user->user_login,
                    'TargetUsername' => $user->user_login,
                    'FirstName'      => $user->user_firstname,
                    'LastName'       => $user->user_lastname,
                    'Roles'          => $user_roles,
                    'EditUserLink'   => \add_query_arg( 'user_id', $user->ID, \network_admin_url( 'user-edit.php' ) ),
                    'TargetUserData' => (object) array(
                        'Username'  => $user->user_login,
                        'FirstName' => $user->user_firstname,
                        'LastName'  => $user->user_lastname,
                        'Email'     => $user->user_email,
                        'Roles'     => $user_roles ? $user_roles : 'none',
                    ),
                )
            );
        }
    }

    /**
     * Triggered when a user accesses the admin area.
     *
     * @since 4.6.0
     */
    public static function get_super_admins() {
        self::$old_superadmins = Changes_WP_Helper::is_multisite() ? get_super_admins() : null;
    }

    /**
     * Super Admin Enabled.
     *
     * Triggers when a user is granted super admin access.
     *
     * @param int $user_id - ID of the user that was granted Super Admin privileges.
     *
     * @since 4.6.0
     */
    public static function event_super_access_granted( $user_id ) {
        $user = get_userdata( $user_id );
        if ( $user && ! in_array( $user->user_login, self::$old_superadmins, true ) ) {
            $user_roles = implode(
                ', ',
                array_map(
                    array(
                        __CLASS__,
                        'filter_role_names',
                    ),
                    $user->roles
                )
            );
            Changes_Logs_Manager::trigger_event(
                4008,
                array(
                    'TargetUserID'   => $user_id,
                    'TargetUsername' => $user->user_login,
                    'Roles'          => is_array( $user->roles ) ? implode( ', ', $user->roles ) : $user->roles,
                    'FirstName'      => $user->user_firstname,
                    'LastName'       => $user->user_lastname,
                    'EditUserLink'   => add_query_arg( 'user_id', $user_id, \network_admin_url( 'user-edit.php' ) ),
                    'TargetUserData' => (object) array(
                        'Username'  => $user->user_login,
                        'FirstName' => $user->user_firstname,
                        'LastName'  => $user->user_lastname,
                        'Email'     => $user->user_email,
                        'Roles'     => $user_roles ? $user_roles : 'none',
                    ),
                )
            );
        }
    }

    /**
     * Super Admin Disabled.
     *
     * Triggers when a user is revoked super admin access.
     *
     * @param int $user_id - ID of the user that was revoked Super Admin privileges.
     *
     * @since 4.6.0
     */
    public static function event_super_access_revoked( $user_id ) {
        $user = get_userdata( $user_id );
        if ( $user && in_array( $user->user_login, self::$old_superadmins, true ) ) {
            $user_roles = implode(
                ', ',
                array_map(
                    array(
                        __CLASS__,
                        'filter_role_names',
                    ),
                    $user->roles
                )
            );
            Changes_Logs_Manager::trigger_event(
                4009,
                array(
                    'TargetUserID'   => $user_id,
                    'TargetUsername' => $user->user_login,
                    'Roles'          => is_array( $user->roles ) ? implode( ', ', $user->roles ) : $user->roles,
                    'FirstName'      => $user->user_firstname,
                    'LastName'       => $user->user_lastname,
                    'EditUserLink'   => add_query_arg( 'user_id', $user_id, \network_admin_url( 'user-edit.php' ) ),
                    'TargetUserData' => (object) array(
                        'Username'  => $user->user_login,
                        'FirstName' => $user->user_firstname,
                        'LastName'  => $user->user_lastname,
                        'Email'     => $user->user_email,
                        'Roles'     => $user_roles ? $user_roles : 'none',
                    ),
                )
            );
        }
    }

    /**
     * Remove BBPress Prefix from User Role.
     *
     * @param string $user_role - User role.
     * @return string
     *
     * @since 4.6.0
     */
    public static function filter_role_names( $user_role ) {
        global $wp_roles;
        return isset( $wp_roles->role_names[ $user_role ] ) ? $wp_roles->role_names[ $user_role ] : false;
    }

    /**
     * Must Not Contain Create User.
     *
     * @since 4.6.0
     */
    public static function must_not_contain_create_user(): bool {
        return ! Changes_Logs_Manager::will_trigger( 4012 );
    }

    /**
     * Must Not Contain User Changes.
     *
     * @since 4.6.0
     */
    public static function must_not_contain_user_changes(): bool {
        return ! (
        Changes_Logs_Manager::will_or_has_triggered( 4010 )
        || Changes_Logs_Manager::will_or_has_triggered( 4011 )
        || Changes_Logs_Manager::will_or_has_triggered( 4012 )
        || Changes_Logs_Manager::will_or_has_triggered( 4000 )
        || Changes_Logs_Manager::will_or_has_triggered( 4001 )
        );
    }
}
