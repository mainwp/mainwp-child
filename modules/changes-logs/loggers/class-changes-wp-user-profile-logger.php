<?php
/**
 * Logger: User Profile
 *
 * User Profile logger class file.
 *
 *
 * @author  WP Activity Log plugin.
 *
 * @since 5.4.1
 *
 * @package MainWP\Child
 */

declare(strict_types=1);

namespace MainWP\Child\Changes\Loggers;

use MainWP\Child\Changes\Changes_Logs_Manager;
use MainWP\Child\Changes\Helpers\Changes_WP_Helper;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * User Profiles sensor.
 *
 * 4001 User created another WordPress user
 * 4002 The role of a user was changed by another WordPress user
 * 4003 User has changed his or her password
 * 4004 User changed another user's password
 * 4005 User changed his or her email address
 * 4006 User changed another user's email address
 * 4007 User was deleted by another user
 * 4008 User granted Super Admin privileges
 * 4009 User revoked from Super Admin privileges
 * 4014 User opened the profile page of another user
 */
class Changes_WP_User_Profile_Logger {

    /**
     * List of super admins.
     *
     * @var array
     *
     */
    private static $old_superadmins;

    /**
     * Inits the main hooks
     *
     * @return void
     *
     */
    public static function init() {
        add_action( 'profile_update', array( __CLASS__, 'event_user_updated' ), 10, 2 );
        add_action( 'set_user_role', array( __CLASS__, 'event_user_role_changed' ), 10, 3 );
        add_action( 'delete_user', array( __CLASS__, 'event_user_deleted' ) );
        add_action( 'wpmu_delete_user', array( __CLASS__, 'event_user_deleted' ) );
        add_action( 'edit_user_profile', array( __CLASS__, 'event_open_profile' ), 10, 1 );
        add_action( 'grant_super_admin', array( __CLASS__, 'get_super_admins' ) );
        add_action( 'revoke_super_admin', array( __CLASS__, 'get_super_admins' ) );
        add_action( 'granted_super_admin', array( __CLASS__, 'event_super_access_granted' ), 10, 1 );
        add_action( 'revoked_super_admin', array( __CLASS__, 'event_super_access_revoked' ), 10, 1 );
        add_action( 'update_user_meta', array( __CLASS__, 'event_application_password_added' ), 10, 4 );
        add_action( 'retrieve_password', array( __CLASS__, 'event_password_reset_link_sent' ), 10, 1 );

        // We hook into action 'user_register' because it is part of the function 'wp_insert_user'.
        add_action( 'user_register', array( __CLASS__, 'on_user_register' ), 10, 1 );
    }

    /**
     * Captures addition of application passwords.
     *
     * @param int    $meta_id ID of the metadata entry to update.
     * @param int    $user_id ID of the user metadata is for.
     * @param string $meta_key Metadata key.
     * @param mixed  $_meta_value Metadata value. Serialized if non-scalar.
     *
     */
    public static function event_application_password_added( $meta_id, $user_id, $meta_key, $_meta_value ) {

        // Filter global arrays for security.
        $server_array = filter_input_array( INPUT_SERVER );
        if ( ! isset( $server_array['HTTP_REFERER'] ) || ! isset( $server_array['REQUEST_URI'] ) ) {
            return;
        }

        // Check the page which is performing this change.
        $referer_check = pathinfo( \sanitize_text_field( \wp_unslash( $server_array['HTTP_REFERER'] ) ) );
        $referer_check = $referer_check['filename'];
        $referer_check = ( strpos( $referer_check, '.' ) !== false ) ? strstr( $referer_check, '.', true ) : $referer_check;

        $is_correct_referer_and_action = false;

        if ( 'profile' === $referer_check || 'user-edit' === $referer_check ) {
            $is_correct_referer_and_action = true;
        }

        // Ensure we are dealing with the correct request.
        if ( $is_correct_referer_and_action && strpos( $server_array['REQUEST_URI'], '/wp/v2/users/' . $user_id . '/application-passwords' ) !== false ) {

            $old_value = get_user_meta( $user_id, '_application_passwords', true );

            $current_user       = get_user_by( 'id', $user_id );
            $current_userdata   = get_userdata( $user_id );
            $current_user_roles = implode(
                ', ',
                array_map(
                    array(
                        __CLASS__,
                        'filter_role_names',
                    ),
                    $current_userdata->roles
                )
            );
            $event_id           = ( 'user-edit' === $referer_check ) ? 4026 : 4025;

            // Note, firstname and lastname fields are purposefully spaces to avoid NULL.
            if ( isset( $_POST['name'] ) ) {
                Changes_Logs_Manager::trigger_event(
                    $event_id,
                    array(
                        'roles'         => $current_user_roles,
                        'login'         => $current_user->user_login,
                        'firstname'     => ( empty( $current_user->user_firstname ) ) ? ' ' : $current_user->user_firstname,
                        'lastname'      => ( empty( $current_user->user_lastname ) ) ? ' ' : $current_user->user_lastname,
                        'CurrentUserID' => $current_user->ID,
                        'friendly_name' => \sanitize_text_field( \wp_unslash( $_POST['name'] ) ),
                        'EventType'     => 'added',
                    )
                );
            } elseif ( ! empty( $old_value ) && count( $old_value ) > 1 && empty( $_meta_value ) ) {
                // Check if all have been removed.
                $event_id = ( 'user-edit' === $referer_check ) ? 4028 : 4027;

                // Note, firstname and lastname fields are purposefully spaces to avoid NULL.
                Changes_Logs_Manager::trigger_event(
                    $event_id,
                    array(
                        'roles'         => $current_user_roles,
                        'login'         => $current_user->user_login,
                        'firstname'     => ( empty( $current_user->user_firstname ) ) ? ' ' : $current_user->user_firstname,
                        'lastname'      => ( empty( $current_user->user_lastname ) ) ? ' ' : $current_user->user_lastname,
                        'CurrentUserID' => $current_user->ID,
                        'EventType'     => 'revoked',
                    )
                );
            } elseif ( count( $_meta_value ) < count( $old_value ) ) {
                // Check the item that was removed.
                $revoked_pw      = array_diff( array_map( 'serialize', $old_value ), array_map( 'serialize', $_meta_value ) );
                $revoked_pw      = array_values( array_map( 'unserialize', $revoked_pw ) );
                $revoked_pw_name = $revoked_pw[0]['name'];
                $event_id        = ( 'user-edit' === $referer_check ) ? 4026 : 4025;

                // Note, firstname and lastname fields are purposefully spaces to avoid NULL.
                Changes_Logs_Manager::trigger_event(
                    $event_id,
                    array(
                        'roles'         => $current_user_roles,
                        'login'         => $current_user->user_login,
                        'firstname'     => ( empty( $current_user->user_firstname ) ) ? ' ' : $current_user->user_firstname,
                        'lastname'      => ( empty( $current_user->user_lastname ) ) ? ' ' : $current_user->user_lastname,
                        'CurrentUserID' => $current_user->ID,
                        'friendly_name' => \sanitize_text_field( $revoked_pw_name ),
                        'EventType'     => 'revoked',
                    )
                );
            }
        }
    }

    /**
     * Method: Support for Ultimate Member email change
     * alert.
     *
     * @param int     $user_id - User ID.
     * @param WP_User $old_userdata - Old WP_User object.
     *
     */
    public static function event_user_updated( $user_id, $old_userdata ) {
        // Get new user data.
        $new_userdata = get_userdata( $user_id );

        // Alert if user password is changed.
        if ( $old_userdata->user_pass !== $new_userdata->user_pass ) {
            $event      = get_current_user_id() === $user_id ? 4003 : 4004;
            $user_roles = implode( ', ', array_map( array( __CLASS__, 'filter_role_names' ), $new_userdata->roles ) );
            Changes_Logs_Manager::trigger_event(
                $event,
                array(
                    'TargetUserID'   => $user_id,
                    'TargetUserData' => (object) array(
                        'Username'  => $new_userdata->user_login,
                        'Roles'     => $user_roles,
                        'FirstName' => $new_userdata->user_firstname,
                        'LastName'  => $new_userdata->user_lastname,
                    ),
                    'EditUserLink'   => add_query_arg( 'user_id', $user_id, \network_admin_url( 'user-edit.php' ) ),
                )
            );
        }

        // Alert if user email is changed.
        if ( $old_userdata->user_email !== $new_userdata->user_email ) {
            $event      = get_current_user_id() === $user_id ? 4005 : 4006;
            $user_roles = implode( ', ', array_map( array( __CLASS__, 'filter_role_names' ), $new_userdata->roles ) );
            Changes_Logs_Manager::trigger_event(
                $event,
                array(
                    'TargetUserID'   => $user_id,
                    'TargetUsername' => $new_userdata->user_login,
                    'OldEmail'       => $old_userdata->user_email,
                    'NewEmail'       => $new_userdata->user_email,
                    'Roles'          => $user_roles,
                    'FirstName'      => $new_userdata->user_firstname,
                    'LastName'       => $new_userdata->user_lastname,
                    'EditUserLink'   => add_query_arg( 'user_id', $user_id, \network_admin_url( 'user-edit.php' ) ),
                    'TargetUserData' => (object) array(
                        'Username'  => $new_userdata->user_login,
                        'FirstName' => $new_userdata->user_firstname,
                        'LastName'  => $new_userdata->user_lastname,
                        'Email'     => $new_userdata->user_email,
                        'Roles'     => $user_roles ? $user_roles : 'none',
                    ),
                )
            );
        }

        // Alert if display name is changed.
        if ( $old_userdata->display_name !== $new_userdata->display_name ) {
            $user_roles = implode( ', ', array_map( array( __CLASS__, 'filter_role_names' ), $new_userdata->roles ) );
            Changes_Logs_Manager::trigger_event(
                4020,
                array(
                    'TargetUsername'  => $new_userdata->user_login,
                    'old_displayname' => $old_userdata->display_name,
                    'new_displayname' => $new_userdata->display_name,
                    'Roles'           => $user_roles,
                    'FirstName'       => $new_userdata->user_firstname,
                    'LastName'        => $new_userdata->user_lastname,
                    'EditUserLink'    => add_query_arg( 'user_id', $user_id, \network_admin_url( 'user-edit.php' ) ),
                    'TargetUserData'  => (object) array(
                        'Username'  => $new_userdata->user_login,
                        'FirstName' => $new_userdata->user_firstname,
                        'LastName'  => $new_userdata->user_lastname,
                        'Email'     => $new_userdata->user_email,
                        'Roles'     => $user_roles ? $user_roles : 'none',
                    ),
                )
            );
        }

        // Alert if website URL is changed.
        if ( $old_userdata->user_url !== $new_userdata->user_url ) {
            $user_roles = implode( ', ', array_map( array( __CLASS__, 'filter_role_names' ), $new_userdata->roles ) );
            Changes_Logs_Manager::trigger_event(
                4021,
                array(
                    'TargetUsername' => $new_userdata->user_login,
                    'old_url'        => $old_userdata->user_url,
                    'new_url'        => $new_userdata->user_url,
                    'Roles'          => $user_roles,
                    'FirstName'      => $new_userdata->user_firstname,
                    'LastName'       => $new_userdata->user_lastname,
                    'EditUserLink'   => add_query_arg( 'user_id', $user_id, \network_admin_url( 'user-edit.php' ) ),
                    'TargetUserData' => (object) array(
                        'Username'  => $new_userdata->user_login,
                        'FirstName' => $new_userdata->user_firstname,
                        'LastName'  => $new_userdata->user_lastname,
                        'Email'     => $new_userdata->user_email,
                        'Roles'     => $user_roles ? $user_roles : 'none',
                    ),
                )
            );
        }

        // Alert if role has changed via Members plugin.
        if ( isset( $_POST['members_user_roles'] ) && ! empty( $_POST['members_user_roles'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            if ( $old_userdata->roles !== $_POST['members_user_roles'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
                self::event_user_role_changed( $user_id, $_POST['members_user_roles'], $old_userdata->roles, true );
            }
        }
    }

    /**
     * Triggered when a user role is changed.
     *
     * @param int     $user_id         User ID of the user.
     * @param string  $new_role        New role.
     * @param array   $old_roles       Array of old roles.
     * @param boolean $use_posted_data If true, posted user data is used.
     *
     */
    public static function event_user_role_changed( $user_id, $new_role, $old_roles, $use_posted_data = false ) {
        // Get WP_User object.
        $user = get_userdata( $user_id );

        // Check if $user is false then return.
        if ( ! $user ) {
            return;
        }

        $roles_to_process = ( $use_posted_data ) ? $new_role : $user->roles;

        $old_roles = array_map( array( __CLASS__, 'filter_role_names' ), $old_roles );
        $new_roles = array_map( array( __CLASS__, 'filter_role_names' ), $roles_to_process );

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
                    'TargetUserData' => (object) array(
                        'Username'  => $user->user_login,
                        'FirstName' => $user->user_firstname,
                        'LastName'  => $user->user_lastname,
                        'Email'     => $user->user_email,
                        'Roles'     => $new_roles ? $new_roles : 'none',
                    ),
                    'multisite_text' => Changes_WP_Helper::is_multisite() ? get_current_blog_id() : false,
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
     */
    public static function event_open_profile( $user ) {
        if ( ! $user ) {
            return;
        }

        $current_user = Changes_User_Helper::get_current_user();
        $updated      = isset( $_GET['updated'] ); // phpcs:ignore
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
                    'EditUserLink'   => add_query_arg( 'user_id', $user->ID, \network_admin_url( 'user-edit.php' ) ),
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
     */
    public static function event_super_access_granted( $user_id ) {
        $user = get_userdata( $user_id );
        if ( $user && ! in_array( $user->user_login, self::$old_superadmins, true ) ) {
            $user_roles = implode( ', ', array_map( array( __CLASS__, 'filter_role_names' ), $user->roles ) );
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
     */
    public static function event_super_access_revoked( $user_id ) {
        $user = get_userdata( $user_id );
        if ( $user && in_array( $user->user_login, self::$old_superadmins, true ) ) {
            $user_roles = implode( ', ', array_map( array( __CLASS__, 'filter_role_names' ), $user->roles ) );
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
     * Trigger event when admin sends a password reset link.
     *
     * @param string $user_login User's login name.
     *
     */
    public static function event_password_reset_link_sent( $user_login ) {
        $user = get_user_by( 'login', $user_login );
        if ( $user instanceof \WP_User ) {
            $userdata   = get_userdata( $user->ID );
            $user_roles = implode(
                ', ',
                array_map(
                    array(
                        __CLASS__,
                        'filter_role_names',
                    ),
                    $userdata->roles
                )
            );

            Changes_Logs_Manager::trigger_event(
                4029,
                array(
                    'roles'          => $user_roles,
                    'login'          => $user->user_login,
                    'firstname'      => ( empty( $user->user_firstname ) ) ? ' ' : $user->user_firstname,
                    'lastname'       => ( empty( $user->user_lastname ) ) ? ' ' : $user->user_lastname,
                    'EventType'      => 'submitted',
                    'TargetUserID'   => $user->ID,
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
     *
     * @return string
     *
     */
    public static function filter_role_names( $user_role ) {
        global $wp_roles;

        return isset( $wp_roles->role_names[ $user_role ] ) ? $wp_roles->role_names[ $user_role ] : false;
    }

    /**
     * Must Not Contain Create User.
     *
     */
    public static function must_not_contain_create_user() {
        return ! Changes_Logs_Manager::will_trigger( 4012 );
    }

    /**
     * Must Not Contain User Changes.
     *
     */
    public static function must_not_contain_user_changes() {
        return ! (
        Changes_Logs_Manager::will_or_has_triggered( 4010 )
        || Changes_Logs_Manager::will_or_has_triggered( 4011 )
        || Changes_Logs_Manager::will_or_has_triggered( 4012 )
        || Changes_Logs_Manager::will_or_has_triggered( 4000 )
        || Changes_Logs_Manager::will_or_has_triggered( 4001 )
        );
    }

    /**
     * When a user is created (by any means other than direct database insert), action 'user_register' is fired because
     * it is part of the function 'wp_insert_user'. We can assume one of the following events if the current session is
     * logged in end event 4000 is not triggered from elsewhere (it is also hooked into action 'user_register').
     *
     * - 4001 User created another WordPress user
     * - 4012 New network user created
     *
     * @param int $user_id - User ID of the registered user.
     *
     */
    public static function on_user_register( $user_id ) {
        if ( ! is_user_logged_in() ) {
            // We bail if the user is not logged in. That is no longer user creation, but a user registration.
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
            'FirstName' => ! empty( $user->user_firstname ) ? $user->user_firstname : '',
            'LastName'  => ! empty( $user->user_lastname ) ? $user->user_lastname : '',
            'Roles'     => is_array( $user->roles ) ? implode( ', ', $user->roles ) : $user->roles,
        );

        $event_code = Changes_WP_Helper::is_multisite() ? 4012 : 4001;

        $event_data = array(
            'NewUserID'    => $user_id,
            'NewUserData'  => (object) $new_user_data,
            'EditUserLink' => add_query_arg( 'user_id', $user_id, \network_admin_url( 'user-edit.php' ) ),
        );

        Changes_Logs_Manager::trigger_event( $event_code, $event_data, Changes_WP_Helper::is_multisite() );
    }
}
