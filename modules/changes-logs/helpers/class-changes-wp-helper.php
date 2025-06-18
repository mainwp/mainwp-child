<?php
/**
 * Responsible for the WP core functionalities.
 *
 * @author  WP Activity Log plugin.
 *
 * Changes Logs Class
 *
 * @since 5.4.1
 *
 * @package MainWP\Child
 */

declare(strict_types=1);

namespace MainWP\Child\Changes\Helpers;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/*
 * WP helper class
 *
 */
class Changes_WP_Helper {

    /**
     * The cache time to store data for. Default is 1 hour in seconds.
     *
     * @since 3.5.2
     * @var   int
     */
    private static $ttl = 30;

    /**
     * The data to be stored.
     *
     * @since 3.5.2
     * @var   mixed
     */
    public static $data;

    /**
     * Options key used to this data.
     *
     * @since 3.5.2
     * @var   string
     */
    public const STORAGE_KEY = 'mainwp_child_changes_logs_networkwide_tracker_cpts';

    /**
     * Hold the user roles as array - Human readable is used for key of the array, and the internal role name is the value.
     *
     * @var array
     *
     */
    private static $user_roles = array();

    /**
     * Hold the user roles as array - Internal role name is used for key of the array, and the human readable format is the value.
     *
     * @var array
     *
     */
    private static $user_roles_wp = array();

    /**
     * Keeps the value of the multisite install of the WP.
     *
     * @var bool
     *
     */
    private static $is_multisite = null;

    /**
     * Holds array with all the sites in multisite WP installation.
     *
     * @var array
     */
    private static $sites = array();

    /**
     * Holds array with all the site urls in multisite WP installation. The urls are the keys and values are the IDs.
     *
     * @var array
     */
    private static $site_urls = array();

    /**
     * Internal cache array for site urls extracted as info
     *
     * @var array
     */
    private static $blogs_info = array();

    /**
     * Checks if specific role exists.
     *
     * @param string $role - The name of the role to check.
     *
     */
    public static function is_role_exists( string $role ): bool {
        self::set_roles();

        if ( in_array( $role, self::$user_roles, true ) ) {
            return true;
        }

        return false;
    }

    /**
     * Returns the currently available WP roles - the Human readable format is the key.
     *
     * @return array
     *
     */
    public static function get_roles() {
        self::set_roles();

        return self::$user_roles;
    }

    /**
     * Returns the currently available WP roles.
     *
     * @return array
     *
     */
    public static function get_roles_wp() {
        if ( empty( self::$user_roles_wp ) ) {
            self::set_roles();
            self::$user_roles_wp = array_flip( self::$user_roles );
        }

        return self::$user_roles_wp;
    }

    /**
     * Returns the WP post types as array.
     *
     * @return array
     *
     * @since 5.2.1
     */
    public static function get_post_types() {

        $post_types = array();

        // Get the post types.
        $output     = 'names'; // Names or objects, note names is the default.
        $operator   = 'and'; // Conditions: "and" or "or".
        $post_types = \get_post_types( array(), $output, $operator );

        // Search and remove attachment type.
        $key = array_search( 'attachment', $post_types, true );
        if ( false !== $key ) {
            unset( $post_types[ $key ] );
        }

        // Add select options to widget.
        foreach ( $post_types as $post_type ) {
            $post_types[ strtolower( $post_type ) ] = $post_type;
        }

        return $post_types;
    }

    /**
     * Returns WP post statuses as array
     *
     * @return array
     *
     * @since 5.2.1
     */
    public static function get_post_statuses() {

        $post_statuses = array();

        $wp_post_statuses = \get_post_stati();
        // Add select options to widget.
        foreach ( $wp_post_statuses as $status ) {
            $post_statuses[ $status ] = $status;
        }

        return $post_statuses;
    }

    /**
     * Returns WOO product statuses as array
     *
     * @return array
     *
     * @since 5.3.4
     */
    public static function get_woo_product_statuses() {
        $post_statuses               = array();
        $post_statuses['publish']    = 'publish';
        $post_statuses['private']    = 'private';
        $post_statuses['future']     = 'future';
        $post_statuses['auto-draft'] = 'auto-draft';
        $post_statuses['draft']      = 'draft';

        return $post_statuses;
    }

    /**
     * Check is this is a multisite setup.
     *
     * @return bool
     *
     */
    public static function is_multisite() {
        if ( null === self::$is_multisite ) {
            self::$is_multisite = function_exists( 'is_multisite' ) && is_multisite();
        }

        return \apply_filters( 'mainwp_child_changes_logs_override_is_multisite', self::$is_multisite );
    }

    /**
     * Collects blogs URLs - used for mainWP site check.
     *
     * @return array
     *
     * @since 5.0.0
     */
    public static function get_site_urls() {
        $sites = self::get_multi_sites();

        foreach ( $sites as $site_object ) {
            $url                     = \get_blogaddress_by_id( $site_object->blog_id );
            self::$site_urls[ $url ] = $site_object->blog_id;
        }

        return self::$site_urls;
    }

    /**
     * Deletes a plugin option from the WP options table.
     *
     * Handled option name with and without the prefix for backwards compatibility.
     *
     * @since  4.0.2
     *
     * @param string $option_name Name of the option to delete.
     *
     * @return bool
     */
    public static function delete_global_option( $option_name = '' ) {
        $prefixed_name = self::prefix_name( $option_name );

        if ( self::is_multisite() ) {
            \switch_to_blog( \get_main_network_id() );
        }

        $result = \delete_option( $prefixed_name );

        if ( self::is_multisite() ) {
            \restore_current_blog();
        }

        return $result;
    }

    /**
     * Just an alias for update_global_option.
     *
     * @param string $setting_name - The name of the option.
     * @param mixed  $new_value    - The value to be stored.
     * @param bool   $autoload     - Should that option be autoloaded or not? No effect on network wide options.
     *
     * @return mixed
     *
     */
    public static function set_global_option( $setting_name, $new_value, $autoload = false ) {
        return self::update_global_option( $setting_name, $new_value, $autoload );
    }

    /**
     * Internal function used to set the value of an option. Any necessary prefixes are already contained in the option
     * name.
     *
     * @param string $option_name Option name we want to save a value for including necessary plugin prefix.
     * @param mixed  $value       A value to store under the option name.
     * @param bool   $autoload    Whether to autoload this option.
     *
     * @return bool Whether the option was updated.
     *
     * @since  4.1.3
     */
    public static function update_global_option( $option_name = '', $value = null, $autoload = false ) {
        // bail early if no option name or value was passed.
        if ( empty( $option_name ) || null === $value ) {
            return;
        }

        $prefixed_name = self::prefix_name( $option_name );

        if ( self::is_multisite() ) {
            \switch_to_blog( \get_main_network_id() );
        }

        $result = \update_option( $prefixed_name, $value, $autoload );

        if ( self::is_multisite() ) {
            \restore_current_blog();
        }

        return $result;
    }

    /**
     * Internal function used to get the value of an option. Any necessary prefixes are already contained in the option
     * name.
     *
     * @param string $option_name Option name we want to get a value for including necessary plugin prefix.
     * @param mixed  $default     a default value to use when one doesn't exist.
     *
     * @return mixed
     *
     * @since  4.1.3
     */
    public static function get_global_option( $option_name = '', $default = null ) {
        // bail early if no option name was requested.
        if ( empty( $option_name ) || ! is_string( $option_name ) ) {
            return;
        }

        if ( self::is_multisite() ) {
            switch_to_blog( get_main_network_id() );
        }

        $prefixed_name = self::prefix_name( $option_name );

        $result = \get_option( $prefixed_name, $default );

        if ( self::is_multisite() ) {
            restore_current_blog();
        }

        return maybe_unserialize( $result );
    }

    /**
     * Collects all the sites from multisite WP installation.
     *
     * @since 4.6.0
     */
    public static function get_multi_sites(): array {
        if ( self::is_multisite() ) {
            if ( empty( self::$sites ) ) {
                self::$sites = \get_sites();
            }

            return self::$sites;
        }

        return array();
    }

    /**
     * Deletes a transient. If this is a multisite, the network transient is deleted.
     *
     * @param string $transient Transient name. Expected to not be SQL-escaped.
     *
     * @return bool True if the transient was deleted, false otherwise.
     *
     */
    public static function delete_transient( $transient ) {
        return self::is_multisite() ? delete_site_transient( $transient ) : delete_transient( $transient );
    }

    /**
     * Gets all active plugins in current WordPress installation.
     *
     */
    public static function get_active_plugins(): array {
        $active_plugins = array();
        if ( self::is_multisite() ) {
            $active_plugins = array_keys( get_site_option( 'active_sitewide_plugins', array() ) );
        } else {
            $active_plugins = get_option( 'active_plugins' );
        }

        return $active_plugins;
    }

    /**
     * Collects all active plugins for the current WP installation.
     *
     * @return array
     *
     * @since 4.6.0
     */
    public static function get_all_active_plugins(): array {
        $plugins = array();
        if ( self::is_multisite() ) {
            $plugins = wp_get_active_network_plugins();
        }

        $plugins = \array_merge( $plugins, wp_get_active_and_valid_plugins() );

        return $plugins;
    }

    /**
     * Original WP function expects to provide name of the cron as well as the cron parameters.
     * Unfortunately this is not possible as these parameters are dynamically generated, that function searches for the cron name only.
     *
     * @param string $name - Name of the cron to search for.
     *
     * @since 4.4.3
     */
    public static function check_for_cron_job( string $name = '' ): bool {
        if ( '' !== trim( $name ) ) {
            $crons = _get_cron_array();

            foreach ( $crons as $cron ) {
                if ( isset( $cron[ $name ] ) ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check whether we are on an admin and plugin page.
     *
     * @since 4.4.3
     *
     * @param array|string $slug ID(s) of a plugin page. Possible values: 'general', 'logs', 'about' or array of them.
     *
     * @return bool
     */
    public static function is_admin_page( $slug = array() ) { // phpcs:ignore Generic.Metrics.NestingLevel.MaxExceeded
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $cur_page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
        $check    = WSAL_PREFIX_PAGE;

        return \is_admin() && ( false !== strpos( $cur_page, $check ) );
    }

    /**
     * Remove all non-WP Mail SMTP plugin notices from our plugin pages.
     *
     * @since 4.4.3
     */
    public static function hide_unrelated_notices() {
        // Bail if we're not on our screen or page.
        if ( ! self::is_admin_page() ) {
            return;
        }

        self::remove_unrelated_actions( 'user_admin_notices' );
        self::remove_unrelated_actions( 'admin_notices' );
        self::remove_unrelated_actions( 'all_admin_notices' );
        self::remove_unrelated_actions( 'network_admin_notices' );
    }

    /**
     * Get editor link.
     *
     * @param stdClass|int $post - The post.
     *
     * @return array $editor_link - Name and value link
     *
     */
    public static function get_editor_link( $post ) {
        $post_id = is_int( $post ) ? intval( $post ) : $post->ID;

        return array(
            'name'  => 'EditorLinkPost',
            'value' => get_edit_post_link( $post_id ),
        );
    }

    /**
     * Method: Get view site id.
     *
     *
     * @return int
     */
    public static function get_view_site_id() {
        switch ( true ) {
            // Non-multisite.
            case ! self::is_multisite():
                return false;
                // Multisite + main site view.
            case self::is_main_blog() && ! self::is_specific_view():
                return -1;
                // Multisite + switched site view.
            case self::is_main_blog() && self::is_specific_view():
                return self::get_specific_view();
                // Multisite + local site view.
            default:
                return \get_current_blog_id();
        }
    }


    /**
     * Returns the current blog ID if on multisite.
     *
     * @return bool|int
     *
     */
    public static function get_blog_id() {
        if ( self::is_multisite() ) {
            return \get_current_blog_id();
        }

        return false;
    }

    /**
     * Method: Get a specific view.
     *
     *
     * @return int
     */
    public static function get_specific_view() {
        return isset( $_REQUEST['changeslogs-cbid'] ) ? (int) sanitize_text_field( wp_unslash( $_REQUEST['changeslogs-cbid'] ) ) : 0;
    }

    /**
     * Method: Check if the blog is main blog.
     *
     */
    public static function is_main_blog(): bool {
        return 1 === get_current_blog_id();
    }

    /**
     * Method: Check if it is a specific view.
     *
     *
     * @return bool
     */
    public static function is_specific_view() {
        return isset( $_REQUEST['changeslogs-cbid'] ) && 0 !== (int) $_REQUEST['changeslogs-cbid'];
    }

    /**
     * Remove all non-WP Mail SMTP notices from the our plugin pages based on the provided action hook.
     *
     * @since 4.4.3
     *
     * @param string $action The name of the action.
     */
    private static function remove_unrelated_actions( $action ) {
        global $wp_filter;

        if ( empty( $wp_filter[ $action ]->callbacks ) || ! is_array( $wp_filter[ $action ]->callbacks ) ) {
            return;
        }

        foreach ( $wp_filter[ $action ]->callbacks as $priority => $hooks ) {
            foreach ( $hooks as $name => $arr ) {
                if (
                    ( // Cover object method callback case.
                        is_array( $arr['function'] ) &&
                        isset( $arr['function'][0] ) &&
                        is_object( $arr['function'][0] ) &&
                        false !== strpos( strtolower( get_class( $arr['function'][0] ) ), CHANGES_LOGS_PREFIX )
                    ) ||
                    ( // Cover class static method callback case.
                        ! empty( $name ) &&
                        false !== strpos( strtolower( $name ), CHANGES_LOGS_PREFIX )
                    ) ||
                    ( // Cover class static method callback case.
                        ! empty( $name ) &&
                        false !== strpos( strtolower( $name ), 'mainwp_child_changes_logs\\' )
                    )
                ) {
                    continue;
                }

                unset( $wp_filter[ $action ]->callbacks[ $priority ][ $name ] );
            }
        }
    }

    /**
     * Sets the internal variable with all the existing WP roles
     * If this is multisite - the super admin role is also added. In the WP you can have user without any other role but super admin.
     *
     * @return void
     *
     */
    private static function set_roles() {
        if ( empty( self::$user_roles ) ) {
            global $wp_roles;

            if ( null === $wp_roles ) {
                wp_roles();
            }

            self::$user_roles = array_flip( $wp_roles->get_names() );

            if ( self::is_multisite() ) {
                self::$user_roles['Super Admin'] = 'superadmin';
            }
        }
    }

    /**
     * Adds settings name prefix if it needs to be added.
     *
     * @param string $name - The name of the setting.
     *
     */
    private static function prefix_name( string $name ): string {
        if ( false === strpos( $name, CHANGES_LOGS_PREFIX ) ) {
            $name = CHANGES_LOGS_PREFIX . $name;
        }

        return $name;
    }

    /**
     * Retrieves the value of a transient. If this is a multisite, the network transient is retrieved.
     *
     * If the transient does not exist, does not have a value, or has expired,
     * then the return value will be false.
     *
     * @param string $transient Transient name. Expected to not be SQL-escaped.
     *
     * @return mixed Value of transient.
     *
     */
    public static function get_transient( $transient ) {
        return self::is_multisite() ? get_site_transient( $transient ) : get_transient( $transient );
    }

    /**
     * Sets/updates the value of a transient. If this is a multisite, the network transient is set/updated.
     *
     * You do not need to serialize values. If the value needs to be serialized,
     * then it will be serialized before it is set.
     *
     * @param string $transient  Transient name. Expected to not be SQL-escaped.
     *                           Must be 172 characters or fewer in length.
     * @param mixed  $value      Transient value. Must be serializable if non-scalar.
     *                           Expected to not be SQL-escaped.
     * @param int    $expiration Optional. Time until expiration in seconds. Default 0 (no expiration).
     *
     * @return bool True if the value was set, false otherwise.
     *
     */
    public static function set_transient( $transient, $value, $expiration = 0 ) {
        return self::is_multisite() ? set_site_transient( $transient, $value, $expiration ) : set_transient( $transient, $value, $expiration );
    }

    /**
     * Checks if we are currently on the login screen.
     *
     */
    public static function is_login_screen(): bool {

        $login = parse_url( site_url( 'wp-login.php' ), PHP_URL_PATH ) === parse_url( \wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        return \apply_filters( 'mainwp_child_changes_logs_login_screen_url', $login );
    }

    /**
     * Checks if we are currently on the register page.
     *
     */
    public static function is_register_page(): bool {
        if ( self::is_login_screen() && ! empty( $_REQUEST['action'] ) && 'register' === $_REQUEST['action'] ) {
            return true;
        }

        return false;
    }

    /**
     * Retrieves blog info for given site based on current multisite situation. Optimizes for performance using local
     * cache.
     *
     * @param int $site_id Site ID.
     *
     * @return array
     *
     */
    public static function get_blog_info( $site_id ) {
        // Blog details.
        if ( isset( self::$blogs_info[ $site_id ] ) ) {
            return self::$blogs_info[ $site_id ];
        }
        if ( self::is_multisite() ) {
            $blog_info = \get_blog_details( $site_id, true );
            $blog_name = \esc_html__( 'Unknown Site', 'mainwp-child' );
            $blog_url  = '';

            if ( $blog_info ) {
                $blog_name = \esc_html( $blog_info->blogname );
                $blog_url  = \esc_attr( $blog_info->siteurl );
            }
        } else {
            $blog_name = \get_bloginfo( 'name' );
            $blog_url  = '';

            if ( empty( $blog_name ) ) {
                $blog_name = __( 'Unknown Site', 'mainwp-child' );
            } else {
                $blog_name = \esc_html( $blog_name );
                $blog_url  = \esc_attr( \get_bloginfo( 'url' ) );
            }
        }

        self::$blogs_info[ $site_id ] = array(
            'name' => $blog_name,
            'url'  => $blog_url,
        );

        return self::$blogs_info[ $site_id ];
    }

    /**
     * Determines whether a plugin is active.
     *
     * @uses is_plugin_active() Uses this WP core function after making sure that this function is available.
     *
     * @param string $plugin Path to the main plugin file from plugins directory.
     *
     * @return bool True, if in the active plugins list. False, not in the list.
     *
     * @since 4.6.0
     */
    public static function is_plugin_active( $plugin ) {
        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $is_active = \is_plugin_active( $plugin );

        if ( ! $is_active && self::is_multisite() ) {
            // Check if the plugin is active on any of the multisite blogs.
            $sites = self::get_multi_sites();
            foreach ( $sites as $site ) {
                \switch_to_blog( $site->blog_id );

                if ( in_array( $plugin, (array) \get_option( 'active_plugins', array() ) ) ) {
                    $is_active = true;

                    \restore_current_blog();

                    return $is_active;
                }

                \restore_current_blog();
            }
        }

        return $is_active;
    }

    /**
     * Returns the full path to the admin area. Multisite admin is taken of consideration.
     *
     * @param string $additional_path - If there is additional path to add to the admin area.
     *
     * @return string
     *
     */
    public static function get_admin_url( string $additional_path = '' ) {
        if ( self::is_multisite() ) {
            return network_admin_url( $additional_path );
        }

        return get_admin_url( null, $additional_path );
    }

    /**
     * Query sites from WPDB.
     *
     * @since 5.0.0
     *
     * @param int|null $limit — Maximum number of sites to return (null = no limit).
     *
     * @return object — Object with keys: blog_id, blogname, domain
     */
    public static function get_sites( $limit = null ) {
        if ( self::$is_multisite ) {
            global $wpdb;
            // Build query.
            $sql = 'SELECT blog_id, domain FROM ' . $wpdb->blogs;
            if ( ! is_null( $limit ) ) {
                $sql .= ' LIMIT ' . $limit;
            }

            // Execute query.
            $res = $wpdb->get_results($sql); // phpcs:ignore

            // Modify result.
            foreach ( $res as $row ) {
                $row->blogname = \get_blog_option( $row->blog_id, 'blogname' );
            }
        } else {
            $res           = new \stdClass();
            $res->blog_id  = \get_current_blog_id();
            $res->blogname = esc_html( \get_bloginfo( 'name' ) );
            $res           = array( $res );
        }

        // Return result.
        return $res;
    }

    /**
     * The number of sites on the network.
     *
     * @since 5.0.0
     *
     * @return int
     */
    public static function get_site_count() {
        global $wpdb;
        $sql = 'SELECT COUNT(*) FROM ' . $wpdb->blogs;

        return (int) $wpdb->get_var($sql); // phpcs:ignore
    }

    /**
     * Returns associate array with user roles names.
     *
     * @return array
     *
     * @since 5.0.0
     */
    public static function get_translated_roles(): array {
        global $wp_roles;

        if ( null === $wp_roles ) {
            wp_roles();
        }

        $roles = wp_roles()->get_names();

        foreach ( $roles as $inner => $role ) {
            $role_names[ $inner ] = translate_user_role( $role );
        }
        if ( self::is_multisite() ) {
            $role_names['superadmin'] = translate_user_role( 'Super Admin' );
        }

        return $role_names;
    }

    /**
     * Get the blog URL.
     *
     * @since 3.4
     *
     * @return string
     */
    public static function get_blog_domain(): string {
        if ( self::is_multisite() ) {
            $blog_id     = function_exists( 'get_current_blog_id' ) ? \get_current_blog_id() : 0;
            $blog_domain = \get_blog_option( $blog_id, 'home' );
        } else {
            $blog_domain = \get_option( 'home' );
        }

        // Replace protocols.
        return str_replace( array( 'http://', 'https://' ), '', $blog_domain );
    }

    public static function get_blog_name(): string {
        if ( self::is_multisite() ) {
            $blog_id   = function_exists( 'get_current_blog_id' ) ? \get_current_blog_id() : 0;
            $blog_name = \get_blog_option( $blog_id, 'blogname' );
        } else {
            $blog_name = \get_bloginfo( 'name' );
        }

        return $blog_name;
    }

    /** CPT tracker ... stll confused about this one */
    /**
     * Return a list of data about a specific requested site, or the network
     * wide list of data otherwise. Empty array if neither exist.
     *
     * @method get_network_data_list
     * @since  3.5.2
     * @param  integer $site_id if a specific site is there. This is technically nullable type but for back compat isn't.
     * @return array
     */
    public static function get_network_data_list( $site_id = 0 ) {
        $network_data = get_network_option( null, self::STORAGE_KEY );
        // get the site list requested otherwise get the network list.
        $list = ( 0 !== $site_id && isset( $network_data['site'][ $site_id ] ) ) ? $network_data['site'][ $site_id ] : $network_data['list'];
        return ( ! empty( $list ) ) ? $list : array();
    }
    /**
     * Tests if the actions need run to store update this sites and the network
     * sites cached options for CPTs.
     *
     * Returns true or false based on the current sites option value being
     * present and considered valid.
     *
     * @method conditions
     * @since  3.5.2
     * @return bool
     */
    public static function conditions() {
        $conditions_met           = false;
        $local_post_types_wrapper = \get_option( self::STORAGE_KEY );
        if (
        ! $local_post_types_wrapper ||
        ! is_array( $local_post_types_wrapper ) ||
        ! isset( $local_post_types_wrapper['timestamp'] ) ||
        (int) $local_post_types_wrapper['timestamp'] + self::$ttl < time()
        ) {
            $conditions_met = true;
        }
        return $conditions_met;
    }
    /**
     * The actions that are used to track CPT registration and store the list
     * at a later point.
     *
     * @method actions
     * @since  3.5.2
     * @return void
     */
    public static function actions() {
        \add_action( 'wp_loaded', array( __CLASS__, 'generate_data' ) );
        \add_action( 'wp_loaded', array( __CLASS__, 'update_storage_site' ) );
        \add_action( 'wp_loaded', array( __CLASS__, 'update_storage_network' ) );
    }
    /**
     * Gets a list of post types registered on this site.
     *
     * @method get_registered_post_types
     * @since  3.5.2
     * @return array
     */
    private static function get_registered_post_types() {
        $post_types = get_post_types( array(), 'names' );
        $post_types = array_diff( $post_types, array( 'attachment', 'revision', 'nav_menu_item', 'customize_changeset', 'custom_css', 'oembed_cache', 'user_request', 'wp_block' ) );
        $data       = array();
        foreach ( $post_types as $post_type ) {
            $data[] = $post_type;
        }
        return $data;
    }
    /**
     * Method to store this site data locally to the site.
     *
     * Stores the data in an array containing a timestamp for freshness
     * invalidation at on later checks or updates.
     *
     * @method update_storage_site
     * @since  3.5.2
     * @return bool
     */
    public static function update_storage_site() {
        $local_data = array(
            'timestamp' => time(),
            'data'      => self::$data,
        );
        return update_option( self::STORAGE_KEY, $local_data );
    }

    /**
     * Method to store this sites local data as part of the global network wide
     * data store. This should merge the data rather than overwrite in most
     * cases.
     *
     * @method update_storage_network
     * @since  3.5.2
     * @return bool
     */
    public static function update_storage_network() {
        // get any network stored data.
        $network_data    = get_network_option( null, self::STORAGE_KEY );
        $current_blog_id = get_current_blog_id();
        $data_updated    = false;

        if ( false === $network_data ) {
            $network_data         = array();
            $network_data['site'] = array();
        }
        if (
        ! isset( $network_data['site'][ $current_blog_id ] )
        || ( isset( $network_data['site'][ $current_blog_id ] ) && $network_data['site'][ get_current_blog_id() ] !== self::$data )
        ) {
            $network_data['site'][ $current_blog_id ] = self::$data;
            // if the network doesn't have data for this site or the data it
            // has is differs then perform the update.
            $network_wide_list = array();
            foreach ( $network_data['site'] as $list ) {
                // loop through each item in a site and add uniques to a list.
                foreach ( $list as $item ) {
                    if ( ! in_array( $item, $network_wide_list, true ) ) {
                        $network_wide_list[] = $item;
                    }
                }
            }
            // save the data on the network with the latest list and the current
            // sites data updated in it.
            $network_data['list'] = $network_wide_list;
            // update the site data on the network.
            $data_updated = update_network_option( null, self::STORAGE_KEY, $network_data );
        }
        return $data_updated;
    }

    /**
     * Gets this sites registered post types and stores them in the $data
     * property for saving at a later point.
     *
     * @method generate_data
     * @since  3.5.2
     * @return void
     */
    public static function generate_data() {
        self::$data = self::get_registered_post_types();
    }
}
