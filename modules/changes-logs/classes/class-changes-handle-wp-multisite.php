<?php
/**
 * Logger: Multisite
 *
 * Multisite logger class file.
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
 * Multisite.
 */
class Changes_Handle_WP_Multisite {

    /**
     * Inits the main hooks
     *
     * @return void
     */
    public static function init_hooks() {
        if ( Changes_Helper::is_multisite() ) {
            add_action( 'add_user_to_blog', array( __CLASS__, 'callback_change_user_added_to_blog' ), 10, 3 );
            add_action( 'remove_user_from_blog', array( __CLASS__, 'callback_change_user_removed_from_blog' ), 10, 2 );
        }
    }

    /**
     * Existing user added to a site.
     *
     * @param int    $user_id - User ID.
     * @param string $role - User role.
     * @param int    $blog_id - Blog ID.
     */
    public static function callback_change_user_added_to_blog( $user_id, $role, $blog_id ) {
        $user     = get_userdata( $user_id );
        $log_data = array(
            'changeuserid'   => $user_id,
            'changeusername' => $user ? $user->user_login : false,
            'changeuserrole' => $role,
            'blogid'         => $blog_id,
            'sitename'       => get_blog_option( $blog_id, 'blogname' ),
            'firstname'      => $user ? $user->user_firstname : false,
            'lastname'       => $user ? $user->user_lastname : false,
            'changeuserdata' => (object) array(
                'username'  => $user->user_login,
                'firstname' => $user->user_firstname,
                'lastname'  => $user->user_lastname,
                'userroles' => $role ? $role : 'none',
            ),
        );
        Changes_Logs_Logger::log_change_save_delay( 1670, $log_data );
    }

    /**
     * User removed from site.
     *
     * @param int $user_id - User ID.
     * @param int $blog_id - Blog ID.
     */
    public static function callback_change_user_removed_from_blog( $user_id, $blog_id ) {
        $user       = get_userdata( $user_id );
        $roles_list = Changes_Helper::get_role_names( $user->roles );
        $log_data   = array(
            'changeuserid'   => $user_id,
            'changeusername' => $user->user_login,
            'changeuserrole' => is_array( $user->roles ) ? implode( ', ', $user->roles ) : $user->roles,
            'blogid'         => $blog_id,
            'sitename'       => get_blog_option( $blog_id, 'blogname' ),
            'firstname'      => $user ? $user->user_firstname : false,
            'lastname'       => $user ? $user->user_lastname : false,
            'changeuserdata' => (object) array(
                'username'  => $user->user_login,
                'firstname' => $user->user_firstname,
                'lastname'  => $user->user_lastname,
                'userroles' => $roles_list ? $roles_list : 'none',
            ),
        );
        Changes_Logs_Logger::log_change_save_delay( 1665, $log_data );
    }
}
