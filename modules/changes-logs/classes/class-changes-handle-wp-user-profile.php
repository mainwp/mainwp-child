<?php
/**
 * Logger: User Profile
 *
 * User Profile logger class file.
 *
 * @since 5.5
 *
 * @package MainWP\Child
 */

namespace MainWP\Child\Changes;

// Exit.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * User Profiles.
 */
class Changes_Handle_WP_User_Profile {

    /**
     * List of super admins.
     *
     * @var array
     */
    private static $old_superadmins;

    /**
     * Inits the main hooks
     *
     * @return void
     */
    public static function init_hooks() {
        add_action( 'delete_user', array( __CLASS__, 'callback_change_user_deleted' ) );
        add_action( 'edit_user_profile', array( __CLASS__, 'callback_change_open_profile' ), 10, 1 );
        add_action( 'grant_super_admin', array( __CLASS__, 'change_set_super_admins' ) );
        add_action( 'granted_super_admin', array( __CLASS__, 'callback_change_super_access_granted' ), 10, 1 );
        add_action( 'profile_update', array( __CLASS__, 'callback_change_user_updated' ), 10, 2 );
        add_action( 'revoke_super_admin', array( __CLASS__, 'change_set_super_admins' ) );
        add_action( 'revoked_super_admin', array( __CLASS__, 'callback_change_super_access_revoked' ), 10, 1 );
        add_action( 'set_user_role', array( __CLASS__, 'callback_change_user_role_changed' ), 10, 3 );
        add_action( 'update_user_meta', array( __CLASS__, 'callback_change_application_password_added' ), 10, 4 );
        add_action( 'user_register', array( __CLASS__, 'callback_change_on_user_register' ), 10, 1 );
        add_action( 'wpmu_delete_user', array( __CLASS__, 'callback_change_user_deleted' ) );
    }

    /**
     * Captures addition of application passwords.
     *
     * @param int    $meta_id ID of the metadata entry to update.
     * @param int    $user_id ID of the user metadata is for.
     * @param string $meta_key Metadata key.
     * @param mixed  $_meta_value Metadata value. Serialized if non-scalar.
     */
    public static function callback_change_application_password_added( $meta_id, $user_id, $meta_key, $_meta_value ) {

        $server_vars = filter_input_array( INPUT_SERVER );
        if ( ! isset( $server_vars['HTTP_REFERER'] ) || ! isset( $server_vars['REQUEST_URI'] ) ) {
            return;
        }

        $referer_check = pathinfo( \sanitize_text_field( \wp_unslash( $server_vars['HTTP_REFERER'] ) ) );
        $referer_check = $referer_check['filename'];
        $referer_check = ( strpos( $referer_check, '.' ) !== false ) ? strstr( $referer_check, '.', true ) : $referer_check;

        $is_correct_referer_and_action = false;

        if ( 'profile' === $referer_check || 'user-edit' === $referer_check ) {
            $is_correct_referer_and_action = true;
        }

        if ( $is_correct_referer_and_action && strpos( $server_vars['REQUEST_URI'], '/wp/v2/users/' . $user_id . '/application-passwords' ) !== false ) {

            $old_value = get_user_meta( $user_id, '_application_passwords', true );

            $current_user       = get_user_by( 'id', $user_id );
            $current_userdata   = get_userdata( $user_id );
            $current_roles_list = Changes_Helper::get_role_names( $current_userdata->roles );

            if ( isset( $_POST['name'] ) ) {
                $type_id  = ( 'user-edit' === $referer_check ) ? 1725 : 1720;
                $log_data = array(
                    'roles'         => $current_roles_list,
                    'login'         => $current_user->user_login,
                    'firstname'     => ( empty( $current_user->user_firstname ) ) ? ' ' : $current_user->user_firstname,
                    'lastname'      => ( empty( $current_user->user_lastname ) ) ? ' ' : $current_user->user_lastname,
                    'currentuserid' => $current_user->ID,
                    'friendly_name' => \sanitize_text_field( \wp_unslash( $_POST['name'] ) ),
                    'actionname'    => 'added',
                );
                Changes_Logs_Logger::log_change( $type_id, $log_data );
            } elseif ( ! empty( $old_value ) && count( $old_value ) > 1 && empty( $_meta_value ) ) {
                $type_id  = ( 'user-edit' === $referer_check ) ? 1735 : 1730;
                $log_data = array(
                    'roles'         => $current_roles_list,
                    'login'         => $current_user->user_login,
                    'firstname'     => ( empty( $current_user->user_firstname ) ) ? ' ' : $current_user->user_firstname,
                    'lastname'      => ( empty( $current_user->user_lastname ) ) ? ' ' : $current_user->user_lastname,
                    'currentuserid' => $current_user->ID,
                    'actionname'    => 'revoked',
                );
                Changes_Logs_Logger::log_change( $type_id, $log_data );
            } elseif ( count( $_meta_value ) < count( $old_value ) ) {
                $revoked_pw      = array_diff( array_map( 'serialize', $old_value ), array_map( 'serialize', $_meta_value ) );
                $revoked_pw      = array_values( array_map( 'unserialize', $revoked_pw ) );
                $revoked_pw_name = $revoked_pw[0]['name'];

                $type_id = ( 'user-edit' === $referer_check ) ? 1726 : 1721;

                $log_data = array(
                    'roles'         => $current_roles_list,
                    'login'         => $current_user->user_login,
                    'firstname'     => ( empty( $current_user->user_firstname ) ) ? ' ' : $current_user->user_firstname, // spaces to avoid NULL.
                    'lastname'      => ( empty( $current_user->user_lastname ) ) ? ' ' : $current_user->user_lastname, // spaces to avoid NULL.
                    'currentuserid' => $current_user->ID,
                    'friendly_name' => \sanitize_text_field( $revoked_pw_name ),
                    'actionname'    => 'revoked',
                );

                Changes_Logs_Logger::log_change( $type_id, $log_data );
            }
        }
    }

    /**
     * Method: Support for Ultimate Member email change
     * alert.
     *
     * @param int     $user_id - User ID.
     * @param WP_User $old_userdata - Old WP_User object.
     */
    public static function callback_change_user_updated( $user_id, $old_userdata ) {
        $new_userdata = get_userdata( $user_id );

        if ( $old_userdata->user_pass !== $new_userdata->user_pass ) {
            $event      = get_current_user_id() === $user_id ? 1625 : 1630;
            $roles_list = Changes_Helper::get_role_names( $new_userdata->roles );

            $log_data = array(
                'changeuserid'   => $user_id,
                'changeuserdata' => (object) array(
                    'username'  => $new_userdata->user_login,
                    'userroles' => $roles_list,
                    'firstname' => $new_userdata->user_firstname,
                    'lastname'  => $new_userdata->user_lastname,
                ),
            );
            Changes_Logs_Logger::log_change( $event, $log_data );
        }

        if ( $old_userdata->user_email !== $new_userdata->user_email ) {
            $event      = get_current_user_id() === $user_id ? 1635 : 1640;
            $roles_list = Changes_Helper::get_role_names( $new_userdata->roles );
            $log_data   = array(
                'changeuserid'   => $user_id,
                'changeusername' => $new_userdata->user_login,
                'oldemail'       => $old_userdata->user_email,
                'newemail'       => $new_userdata->user_email,
                'userroles'      => $roles_list,
                'firstname'      => $new_userdata->user_firstname,
                'lastname'       => $new_userdata->user_lastname,
                'changeuserdata' => (object) array(
                    'username'  => $new_userdata->user_login,
                    'firstname' => $new_userdata->user_firstname,
                    'lastname'  => $new_userdata->user_lastname,
                    'userroles' => $roles_list ? $roles_list : 'none',
                ),
            );
            Changes_Logs_Logger::log_change( $event, $log_data );
        }

        if ( $old_userdata->display_name !== $new_userdata->display_name ) {
            $roles_list = Changes_Helper::get_role_names( $new_userdata->roles );
            $log_data   = array(
                'changeusername'  => $new_userdata->user_login,
                'old_displayname' => $old_userdata->display_name,
                'new_displayname' => $new_userdata->display_name,
                'userroles'       => $roles_list,
                'firstname'       => $new_userdata->user_firstname,
                'lastname'        => $new_userdata->user_lastname,
                'changeuserdata'  => (object) array(
                    'username'  => $new_userdata->user_login,
                    'firstname' => $new_userdata->user_firstname,
                    'lastname'  => $new_userdata->user_lastname,
                    'userroles' => $roles_list ? $roles_list : 'none',
                ),
            );
            Changes_Logs_Logger::log_change( 1710, $log_data );
        }

        if ( $old_userdata->user_url !== $new_userdata->user_url ) {
            $roles_list = Changes_Helper::get_role_names( $new_userdata->roles );
            $log_data   = array(
                'changeusername' => $new_userdata->user_login,
                'old_url'        => $old_userdata->user_url,
                'new_url'        => $new_userdata->user_url,
                'userroles'      => $roles_list,
                'firstname'      => $new_userdata->user_firstname,
                'lastname'       => $new_userdata->user_lastname,
                'changeuserdata' => (object) array(
                    'username'  => $new_userdata->user_login,
                    'firstname' => $new_userdata->user_firstname,
                    'lastname'  => $new_userdata->user_lastname,
                    'userroles' => $roles_list ? $roles_list : 'none',
                ),
            );
            Changes_Logs_Logger::log_change( 1715, $log_data );
        }

        if ( isset( $_POST['members_user_roles'] ) && ! empty( $_POST['members_user_roles'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            if ( $old_userdata->roles !== $_POST['members_user_roles'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
                self::callback_change_user_role_changed( $user_id, $_POST['members_user_roles'], $old_userdata->roles, true );
            }
        }
    }

    /**
     * When a user role is changed.
     *
     * @param int     $user_id         User ID of the user.
     * @param string  $new_role        New role.
     * @param array   $old_roles       Array of old roles.
     * @param boolean $use_posted_data If true, posted user data is used.
     */
    public static function callback_change_user_role_changed( $user_id, $new_role, $old_roles, $use_posted_data = false ) {
        $user = get_userdata( $user_id );

        if ( ! $user ) {
            return;
        }

        $roles_to_process = ( $use_posted_data ) ? $new_role : $user->roles;

        $old_roles = Changes_Helper::get_role_names( $old_roles );
        $new_roles = Changes_Helper::get_role_names( $roles_to_process );

        if ( $old_roles !== $new_roles ) {
            $log_data = array(
                'changeuserid'   => $user_id,
                'changeusername' => $user->user_login,
                'oldrole'        => $old_roles,
                'newrole'        => $new_roles,
                'firstname'      => $user->user_firstname,
                'lastname'       => $user->user_lastname,
                'changeuserdata' => (object) array(
                    'username'  => $user->user_login,
                    'firstname' => $user->user_firstname,
                    'lastname'  => $user->user_lastname,
                    'userroles' => $new_roles ? $new_roles : 'none',
                ),
                'multisite_blog' => Changes_Helper::is_multisite() ? Changes_Helper::get_blog_id() : false,
            );
            Changes_Logs_Logger::log_change_save_delay( 1615, $log_data );
        }
    }

    /**
     * When a user is deleted.
     *
     * @param int $user_id - User ID of the registered user.
     */
    public static function callback_change_user_deleted( $user_id ) {
        $user = get_userdata( $user_id );
        $role = is_array( $user->roles ) ? implode( ', ', $user->roles ) : $user->roles;

        $log_data = array(
            'changeuserid'   => $user_id,
            'changeuserdata' => (object) array(
                'username'  => $user->user_login,
                'firstname' => $user->user_firstname,
                'lastname'  => $user->user_lastname,
                'userroles' => $role ? $role : 'none',
            ),
        );

        Changes_Logs_Logger::log_change_save_delay( 1645, $log_data );
    }

    /**
     * When a user profile is opened.
     *
     * @param object $user - Instance of WP_User.
     */
    public static function callback_change_open_profile( $user ) {
        if ( ! $user ) {
            return;
        }

        $current_user = Changes_Helper::change_get_current_user();
        $updated      = isset( $_GET['updated'] ); // phpcs:ignore
        if ( $current_user && ( $user->ID !== $current_user->ID ) && ! $updated ) {
            $roles_list = Changes_Helper::get_role_names( $user->roles );
            $log_data   = array(
                'actionuser'     => $current_user->user_login,
                'changeusername' => $user->user_login,
                'firstname'      => $user->user_firstname,
                'lastname'       => $user->user_lastname,
                'userroles'      => $roles_list,
                'changeuserdata' => (object) array(
                    'username'  => $user->user_login,
                    'firstname' => $user->user_firstname,
                    'lastname'  => $user->user_lastname,
                    'userroles' => $roles_list ? $roles_list : 'none',
                ),
            );
            Changes_Logs_Logger::log_change( 1680, $log_data );
        }
    }

    /**
     * When a user accesses the admin area.
     */
    public static function change_set_super_admins() {
        self::$old_superadmins = Changes_Helper::is_multisite() ? get_super_admins() : null;
    }

    /**
     * Super Admin Enabled.
     *
     * When a user is granted super admin access.
     *
     * @param int $user_id - ID of the user that was granted Super Admin privileges.
     */
    public static function callback_change_super_access_granted( $user_id ) {
        $user = get_userdata( $user_id );
        if ( $user && ! in_array( $user->user_login, self::$old_superadmins, true ) ) {
            $roles_list = Changes_Helper::get_role_names( $user->roles );
            $log_data   = array(
                'changeuserid'   => $user_id,
                'changeusername' => $user->user_login,
                'userroles'      => is_array( $user->roles ) ? implode( ', ', $user->roles ) : $user->roles,
                'firstname'      => $user->user_firstname,
                'lastname'       => $user->user_lastname,
                'changeuserdata' => (object) array(
                    'username'  => $user->user_login,
                    'firstname' => $user->user_firstname,
                    'lastname'  => $user->user_lastname,
                    'userroles' => $roles_list ? $roles_list : 'none',
                ),
            );
            Changes_Logs_Logger::log_change( 1650, $log_data );
        }
    }

    /**
     * Super Admin Disabled.
     *
     * When a user is revoked super admin access.
     *
     * @param int $user_id - ID of the user that was revoked Super Admin privileges.
     */
    public static function callback_change_super_access_revoked( $user_id ) {
        $user = get_userdata( $user_id );
        if ( $user && in_array( $user->user_login, self::$old_superadmins, true ) ) {
            $roles_list = Changes_Helper::get_role_names( $user->roles );
            $log_data   = array(
                'changeuserid'   => $user_id,
                'changeusername' => $user->user_login,
                'userroles'      => is_array( $user->roles ) ? implode( ', ', $user->roles ) : $user->roles,
                'firstname'      => $user->user_firstname,
                'lastname'       => $user->user_lastname,
                'changeuserdata' => (object) array(
                    'username'  => $user->user_login,
                    'firstname' => $user->user_firstname,
                    'lastname'  => $user->user_lastname,
                    'userroles' => $roles_list ? $roles_list : 'none',
                ),
            );

            Changes_Logs_Logger::log_change( 1655, $log_data );
        }
    }

    /**
     * When a user is created
     *
     * @param int $user_id - User ID of the registered user.
     */
    public static function callback_change_on_user_register( $user_id ) {
        if ( ! is_user_logged_in() ) {
            return;
        }

        $user = get_userdata( $user_id );
        if ( ! $user instanceof \WP_User ) {
            return;
        }

        $new_user_data = array(
            'username'  => $user->user_login,
            'firstname' => ! empty( $user->user_firstname ) ? $user->user_firstname : '',
            'lastname'  => ! empty( $user->user_lastname ) ? $user->user_lastname : '',
            'userroles' => is_array( $user->roles ) ? implode( ', ', $user->roles ) : $user->roles,
        );

        $log_code = Changes_Helper::is_multisite() ? 1660 : 1610;

        $log_data = array(
            'newuserid'   => $user_id,
            'newuserdata' => (object) $new_user_data,
        );

        if ( Changes_Helper::is_multisite() ) {
            Changes_Logs_Logger::log_change_save_delay( $log_code, $log_data );
        } else {
            Changes_Logs_Logger::log_change( $log_code, $log_data );
        }
    }
}
