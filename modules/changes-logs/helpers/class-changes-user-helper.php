<?php
/**
 * Responsible for the User's operations
 *
 * @package    mainwp/child
 * @since      4.5.1
 * @copyright  2025 Melapress
 * @license    https://www.apache.org/licenses/LICENSE-2.0 Apache License 2.0
 * @link       https://wordpress.org/plugins/wp-2fa/
 */

declare(strict_types=1);

namespace MainWP\Child\Changes\Helpers;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

/**
 * All the user related settings must go trough this class.
 *
 * @since 4.4.3
 */
class Changes_User_Helper {
    /**
     * The class user variable
     *
     * @var \WP_User
     *
     * @since 4.4.3
     */
    private static $user = null;

    /**
     * Holds the cache for the current WP user. That variable is used because the one returned from the class get_user() method could return another user object (previously set by some of the other plugin logic)
     *
     * @var \WP_User
     *
     * @since 5.1.1
     */
    private static $current_user = null;

    /**
     * Every meta call for the user must go through this method, so we can unify the code.
     *
     * @param string            $meta - The meta name that we should check.
     * @param null|int|\WP_User $user - The WP user we should extract the meta data for.
     *
     * @return mixed
     *
     * @since 4.4.3
     */
    public static function get_meta( string $meta, $user = null ) {
        self::set_proper_user( $user );

        return \get_user_meta( self::$user->ID, $meta, true );
    }

    /**
     * Every meta storing call for the user must go through this method
     *
     * @param string            $meta - The meta name that we should check.
     * @param mixed             $value - The value which should be stored.
     * @param null|int|\WP_User $user - The WP user we should extract the meta data for.
     *
     * @return mixed
     *
     * @since 4.4.3
     */
    public static function set_meta( string $meta, $value, $user = null ) {
        self::set_proper_user( $user );

        return \update_user_meta( self::$user->ID, $meta, $value );
    }

    /**
     * Removes meta for the given user
     *
     * @param string            $meta - The name of the meta.
     * @param null|int|\WP_User $user - The WP user we should extract the meta data for.
     *
     * @return mixed
     *
     * @since 4.4.3
     */
    public static function remove_meta( string $meta, $user = null ) {
        self::set_proper_user( $user );

        return \delete_user_meta( self::$user->ID, $meta );
    }

    /**
     * Returns the currently set user.
     *
     * @return \WP_User
     *
     * @since 4.4.3
     */
    public static function get_user() {
        if ( null === self::$user ) {
            self::set_user();
        }

        return self::$user;
    }

    /**
     * Returns WP User object.
     *
     * @param null|int|\WP_User $user - The WP user that must be used.
     *
     * @return \WP_User
     *
     * @since 4.4.3
     */
    public static function get_user_object( $user = null ) {
        self::set_user( $user );

        return self::$user;
    }

    /**
     * Sets the user
     *
     * @param null|int|\WP_User $user - The WP user that must be used.
     *
     * @return void
     *
     * @since 4.4.3
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
     * Returns the default role for the given user
     *
     * @param null|int|\WP_User $user - The WP user.
     *
     * @return string
     *
     * @since 4.4.3
     */
    public static function get_user_role( $user = null ): string {
        self::set_proper_user( $user );

        if ( Changes_WP_Helper::is_multisite() ) {
            $blog_id = \get_current_blog_id();

            if ( ! is_user_member_of_blog( self::$user->ID, $blog_id ) ) {

                $user_blog_id = \get_active_blog_for_user( self::$user->ID );

                if ( null !== $user_blog_id ) {

                    self::$user = new \WP_User(
                    // $user_id
                        self::$user->ID,
                        // $name | login, ignored if $user_id is set
                        '',
                        // $blog_id
                        $user_blog_id->blog_id
                    );
                }
            }
        }

        $role = reset( self::$user->roles );

        /**
         * The code looks like this for clearness only
         */
        if ( Changes_WP_Helper::is_multisite() ) {
            /**
             * On multi site we can have user which has no assigned role, but it is superadmin.
             * If the check confirms that - assign the role of the administrator to the user in order not to break our code.
             *
             * Unfortunately we could never be sure what is the name of the administrator role (someone could change this default value),
             * in order to continue working we will use the presumption that if given role has 'manage_options' capability, then it is
             * most probably administrator - so we will assign that role to the user.
             */
            if ( false === $role && is_super_admin( self::$user->ID ) ) {

                $role = 'superadmin';
            }
        }

        return (string) $role;
    }

    /**
     * Returns the roles for the given user (or the current one).
     * For the multisite, if the user is super admin it adds `superadmin` role to the roles
     *
     * @param null|int|\WP_User $user - The WP user.
     *
     * @return array
     *
     * @since 4.4.3
     */
    public static function get_user_roles( $user = null ): array {
        self::set_proper_user( $user );

        $roles = self::$user->roles;

        if ( Changes_WP_Helper::is_multisite() ) {

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

        // When user has an admin role anywhere put that at the top
        // of the list.
        if ( count( $roles ) > 1 && in_array( 'administrator', $roles, true ) ) {
            array_unshift( $roles, 'administrator' );
            $roles = array_unique( $roles );
        }

        /**
         * The code looks like this for clearness only
         */
        if ( Changes_WP_Helper::is_multisite() ) {
            /**
             * On multi site we can have user which has no assigned role, but it is superadmin.
             * If the check confirms that - assign the role of the administrator to the user in order not to break our code.
             *
             * Unfortunately we could never be sure what is the name of the administrator role (someone could change this default value),
             * in order to continue working we will use the presumption that if given role has 'manage_options' capability, then it is
             * most probably administrator - so we will assign that role to the user.
             */
            if ( is_super_admin( self::$user->ID ) ) {

                $roles[] = 'superadmin';

                // When user has a superadmin role (in multisite) put that at the top
                // of the list.
                if ( count( $roles ) > 1 ) {
                    array_unshift( $roles, 'superadmin' );
                    $roles = array_unique( $roles );
                }
            }
        }

        return (array) $roles;
    }

    /**
     * Checks if the given user has administrator or super administrator privileges
     *
     * @param null|int|\WP_User $user - The WP user that must be used.
     *
     * @return boolean
     *
     * @since 4.4.3
     */
    public static function is_admin( $user = null ): bool {
        self::set_proper_user( $user );

        $is_admin = in_array( 'administrator', self::$user->roles, true ) || ( function_exists( 'is_super_admin' ) && is_super_admin( self::$user->ID ) );

        if ( ! $is_admin ) {
            return false;
        }
        return true;
    }

    /**
     * Caches the current user WP object - this method is used to avoid unnecessary database queries to core WP functions that returns the current user object. It stores the value of the current user in the class variable and returns it when needed.
     *
     * @return \WP_User
     *
     * @since 5.1.1
     */
    public static function get_current_user() {
        if ( null === self::$current_user ) {
            self::$current_user = \wp_get_current_user();
        }

        return self::$current_user;
    }

    /**
     * Returns the user email address.
     *
     * @param null|int|\WP_User $user - The WP user that must be used.
     *
     * @return string
     *
     * @since 4.4.3
     */
    public static function get_user_email( $user = null ): string {
        self::set_proper_user( $user );

        return (string) self::$user->user_email;
    }

    /**
     * Sets the local variable class based on the given parameter.
     *
     * @param null|int|\WP_User $user - The WP user we should extract the meta data for.
     *
     * @return void
     *
     * @since 4.4.3
     */
    private static function set_proper_user( $user = null ) {
        if ( null !== $user ) {
            self::set_user( $user );
        } else {
            self::get_user();
        }
    }
}
