<?php
/**
 * Logger: WP Meta Data
 *
 * WP Meta Data logger class file.
 *
 * @since      5.4.1
 * @package    mainwp/child
 */

declare(strict_types=1);

namespace MainWP\Child\Changes\Loggers;

use MainWP\Child\Changes\Helpers\Changes_WP_Helper;
use MainWP\Child\Changes\Changes_Logs_Manager;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Custom fields (posts, pages, custom posts and users) sensor.
 *
 * 2053 User created a custom field for a post
 * 2062 User updated a custom field name for a post
 * 2054 User updated a custom field value for a post
 * 2055 User deleted a custom field from a post
 * 4015 User updated a custom field value for a user
 * 4016 User created a custom field value for a user
 * 4017 User changed first name for a user
 * 4018 User changed last name for a user
 * 4019 User changed nickname for a user
 * 4020 User changed the display name for a user
 */
class Changes_WP_MetaData_Logger {

    /**
     * Empty meta counter.
     *
     * @var int
     *
     */
    private static $null_meta_counter = 0;

    /**
     * Array of meta data being updated.
     *
     * @var array
     *
     */
    protected static $old_meta = array();

    /**
     * Inits the main hooks
     *
     * @return void
     *
     */
    public static function init() {
        add_action( 'add_post_meta', array( __CLASS__, 'event_post_meta_created' ), 10, 3 );
        add_action( 'update_post_meta', array( __CLASS__, 'event_post_meta_updating' ), 10, 3 );
        add_action( 'updated_post_meta', array( __CLASS__, 'event_post_meta_updated' ), 10, 4 );
        add_action( 'deleted_post_meta', array( __CLASS__, 'event_post_meta_deleted' ), 10, 4 );
        add_action( 'save_post', array( __CLASS__, 'reset_null_meta_counter' ), 10 );
        add_action( 'add_user_meta', array( __CLASS__, 'event_user_meta_created' ), 10, 3 );
        add_action( 'update_user_meta', array( __CLASS__, 'event_user_meta_updating' ), 10, 3 );
        add_action( 'updated_user_meta', array( __CLASS__, 'event_user_meta_updated' ), 10, 4 );
        add_action( 'user_register', array( __CLASS__, 'reset_null_meta_counter' ), 10 );
    }

    /**
     * Created a custom field.
     *
     * @param int    $object_id - Object ID.
     * @param string $meta_key - Meta key.
     * @param mixed  $meta_value - Meta value.
     *
     */
    public static function event_post_meta_created( $object_id, $meta_key, $meta_value ) {
        if ( ! self::can_log_meta_key( 'post', $object_id, $meta_key ) || is_array( $meta_value ) ) {
            return;
        }

        if ( empty( $meta_value ) && ( self::$null_meta_counter < 1 ) ) { // Report only one NULL meta value.
            ++self::$null_meta_counter;
        } elseif ( self::$null_meta_counter >= 1 ) { // Do not report if NULL meta values are more than one.
            return;
        }

        // Get post object.
        $post = get_post( $object_id );

        // Return if the post object is null or the post type is revision.
        if ( null === $post || 'revision' === $post->post_type ) {
            return;
        }

        /**
         * Filter: `mainwp_child_changes_logs_before_post_meta_create_event`
         *
         * Runs before logging event for post meta created i.e. 2053.
         * This filter can be used as check to whether log this event or not.
         *
         * @since 3.3.1
         *
         * @param bool    $log_event  - True if log meta event, false if not.
         * @param string  $meta_key   - Meta key.
         * @param mixed   $meta_value - Meta value.
         * @param WP_Post $post       - Post object.
         */
        $log_meta_event = apply_filters( 'mainwp_child_changes_logs_before_post_meta_create_event', true, $meta_key, $meta_value, $post );

        if ( $log_meta_event ) {
            $editor_link = Changes_WP_Helper::get_editor_link( $post );
            Changes_Logs_Manager::trigger_event(
                2053,
                array(
                    'PostID'             => $object_id,
                    'PostTitle'          => $post->post_title,
                    'PostStatus'         => $post->post_status,
                    'PostType'           => $post->post_type,
                    'PostDate'           => $post->post_date,
                    'PostUrl'            => get_permalink( $post->ID ),
                    'MetaKey'            => $meta_key,
                    'MetaValue'          => $meta_value,
                    'MetaLink'           => $meta_key,
                    $editor_link['name'] => $editor_link['value'],
                )
            );
        }
    }

    /**
     * Sets the old meta.
     *
     * @param int    $meta_id - Meta ID.
     * @param int    $object_id - Object ID.
     * @param string $meta_key - Meta key.
     *
     */
    public static function event_post_meta_updating( $meta_id, $object_id, $meta_key ) {
        static $meta_type = 'post';
        $meta             = get_metadata_by_mid( $meta_type, $meta_id );

        self::$old_meta[ $meta_id ] = array(
            'key' => ( $meta ) ? $meta->meta_key : $meta_key,
            'val' => get_metadata( $meta_type, $object_id, $meta_key, true ),
        );
    }

    /**
     * Updated a custom field name/value.
     *
     * @param int    $meta_id - Meta ID.
     * @param int    $object_id - Object ID.
     * @param string $meta_key - Meta key.
     * @param mixed  $meta_value - Meta value.
     *
     */
    public static function event_post_meta_updated( $meta_id, $object_id, $meta_key, $meta_value ) {
        if ( ! self::can_log_meta_key( 'post', $object_id, $meta_key ) || is_array( $meta_value ) ) {
            return;
        }

        // Get post object.
        $post = get_post( $object_id );

        // Return if the post object is null or the post type is revision.
        if ( null === $post || 'revision' === $post->post_type ) {
            return;
        }

        /**
         * Action Hook.
         *
         * Runs before logging events for post meta updated i.e. 2062 or 2054.
         *
         * This hook can be used to log events for updated post meta on the
         * front-end since the plugin only supports events for updating post
         * meta via wp admin panel.
         *
         * @param int    $meta_id        - Meta ID.
         * @param int    $object_id      - Post ID.
         * @param array  self::$old_meta - Array of metadata holding keys & values of old metadata before updating the current post.
         * @param string $meta_key       - Meta key.
         * @param mixed  $meta_value     - Meta value.
         * @since 3.2.2
         */
        do_action( 'mainwp_child_changes_logs_post_meta_updated', $meta_id, $object_id, (object) self::$old_meta, $meta_key, $meta_value );

        if ( isset( static::$old_meta[ $meta_id ] ) ) {
            /**
             * Filter: `mainwp_child_changes_logs_before_post_meta_update_event`
             *
             * Runs before logging events for post meta updated i.e. 2054 or 2062.
             * This filter can be used as check to whether log these events or not.
             *
             * @param bool     $log_event  - True if log meta event 2054 or 2062, false if not.
             * @param string   $meta_key   - Meta key.
             * @param mixed    $meta_value - Meta value.
             * @param stdClass $old_meta   - Old meta value and key object.
             * @param WP_Post  $post       - Post object.
             * @param integer  $meta_id    - Meta ID.
             *
             * @since 3.3.1
             */
            $log_meta_event = apply_filters( 'mainwp_child_changes_logs_before_post_meta_update_event', true, $meta_key, $meta_value, self::$old_meta[ $meta_id ], $post, $meta_id );

            // Check change in meta key.
            if ( $log_meta_event && self::$old_meta[ $meta_id ]['key'] !== $meta_key ) {
                $editor_link = Changes_WP_Helper::get_editor_link( $post );
                Changes_Logs_Manager::trigger_event(
                    2062,
                    array(
                        'PostID'             => $object_id,
                        'PostTitle'          => $post->post_title,
                        'PostStatus'         => $post->post_status,
                        'PostType'           => $post->post_type,
                        'PostDate'           => $post->post_date,
                        'PostUrl'            => get_permalink( $post->ID ),
                        'MetaID'             => $meta_id,
                        'MetaKeyNew'         => $meta_key,
                        'MetaKeyOld'         => self::$old_meta[ $meta_id ]['key'],
                        'MetaValue'          => $meta_value,
                        'MetaLink'           => $meta_key,
                        $editor_link['name'] => $editor_link['value'],
                    )
                );
            } elseif ( $log_meta_event && self::$old_meta[ $meta_id ]['val'] !== $meta_value ) { // Check change in meta value.
                $editor_link = Changes_WP_Helper::get_editor_link( $post );
                Changes_Logs_Manager::trigger_event_if(
                    2054,
                    array(
                        'PostID'             => $object_id,
                        'PostTitle'          => $post->post_title,
                        'PostStatus'         => $post->post_status,
                        'PostType'           => $post->post_type,
                        'PostDate'           => $post->post_date,
                        'PostUrl'            => get_permalink( $post->ID ),
                        'MetaID'             => $meta_id,
                        'MetaKey'            => $meta_key,
                        'MetaValueNew'       => $meta_value,
                        'MetaValueOld'       => self::$old_meta[ $meta_id ]['val'],
                        'MetaLink'           => $meta_key,
                        $editor_link['name'] => $editor_link['value'],
                    ),
                    /**
                    * Don't fire if there's already an event 2131 or 2132 (ACF relationship change).
                    *
                    * @return bool
                    */
                    function () {
                        return ! Changes_Logs_Manager::will_or_has_triggered( 2131 )
                            && ! Changes_Logs_Manager::will_or_has_triggered( 2132 );
                    }
                );
            }
            // Remove old meta update data.
            unset( self::$old_meta[ $meta_id ] );
        }
    }

    /**
     * Deleted a custom field.
     *
     * @param array  $meta_ids - Meta IDs.
     * @param int    $object_id - Object ID.
     * @param string $meta_key - Meta key.
     * @param mixed  $meta_value - Meta value.
     *
     */
    public static function event_post_meta_deleted( $meta_ids, $object_id, $meta_key, $meta_value ) {
        // If meta key starts with "_" then return.
        if ( '_' === substr( (string) $meta_key, 0, 1 ) ) {
            return;
        }

        // Get post object.
        $post = get_post( $object_id );

        // Return if the post object is null.
        if ( null === $post ) {
            return;
        }

        $editor_link = Changes_WP_Helper::get_editor_link( $post );

        foreach ( $meta_ids as $meta_id ) {
            if ( ! self::can_log_meta_key( 'post', $object_id, $meta_key ) ) {
                continue;
            }

            /**
             * Filter: `mainwp_child_changes_logs_before_post_meta_delete_event`
             *
             * Runs before logging event for post meta deleted i.e. 2054.
             * This filter can be used as check to whether log this event or not.
             *
             * @since 3.3.1
             *
             * @param bool     $log_event  - True if log meta event 2055, false if not.
             * @param string   $meta_key   - Meta key.
             * @param mixed    $meta_value - Meta value.
             * @param WP_Post  $post       - Post object.
             * @param integer  $meta_id    - Meta ID.
             */
            $log_meta_event = apply_filters( 'mainwp_child_changes_logs_before_post_meta_delete_event', true, $meta_key, $meta_value, $post, $meta_id );

            // If not allowed to log meta event then skip it.
            if ( ! $log_meta_event ) {
                continue;
            }

            if ( 'trash' !== $post->post_status ) {
                Changes_Logs_Manager::trigger_event(
                    2055,
                    array(
                        'PostID'             => $object_id,
                        'PostTitle'          => $post->post_title,
                        'PostStatus'         => $post->post_status,
                        'PostType'           => $post->post_type,
                        'PostDate'           => $post->post_date,
                        'PostUrl'            => get_permalink( $post->ID ),
                        'MetaID'             => $meta_id,
                        'MetaKey'            => $meta_key,
                        'MetaValue'          => $meta_value,
                        $editor_link['name'] => $editor_link['value'],
                    )
                );
            }
        }
    }

    /**
     * Method: Reset Null Meta Counter.
     *
     */
    public static function reset_null_meta_counter() {
        self::$null_meta_counter = 0;
    }

    /**
     * Create a custom field name/value.
     *
     * @param int    $object_id - Object ID.
     * @param string $meta_key - Meta key.
     * @param mixed  $meta_value - Meta value.
     *
     */
    public static function event_user_meta_created( $object_id, $meta_key, $meta_value ) {
        // Check to see if we can log the meta key.
        if ( ! self::can_log_meta_key( 'user', $object_id, $meta_key ) || is_array( $meta_value ) ) {
            return;
        }

        if ( self::is_woocommerce_user_meta( $meta_key ) ) {
            return;
        }

        if ( empty( $meta_value ) && ( self::$null_meta_counter < 1 ) ) { // Report only one NULL meta value.
            ++self::$null_meta_counter;
        } elseif ( self::$null_meta_counter >= 1 ) { // Do not report if NULL meta values are more than one.
            return;
        }

        // Get user.
        $user = get_user_by( 'ID', $object_id );

        Changes_Logs_Manager::trigger_event_if(
            4016,
            array(
                'TargetUsername'    => $user->user_login,
                'custom_field_name' => $meta_key,
                'new_value'         => $meta_value,
                'FirstName'         => $user->user_firstname,
                'LastName'          => $user->user_lastname,
                'Roles'             => is_array( $user->roles ) ? implode( ', ', $user->roles ) : $user->roles,
                'EditUserLink'      => add_query_arg( 'user_id', $user->ID, \network_admin_url( 'user-edit.php' ) ),
                'TargetUserData'    => (object) array(
                    'Username'  => $user->user_login,
                    'FirstName' => $user->user_firstname,
                    'LastName'  => $user->user_lastname,
                    'Email'     => $user->user_email,
                    'Roles'     => is_array( $user->roles ) ? implode( ', ', $user->roles ) : $user->roles,
                ),
            ),
            array( __CLASS__, 'must_not_contain_new_user_alert' )
        );
    }

    /**
     * Sets the old meta.
     *
     * @param int    $meta_id - Meta ID.
     * @param int    $object_id - Object ID.
     * @param string $meta_key - Meta key.
     *
     */
    public static function event_user_meta_updating( $meta_id, $object_id, $meta_key ) {
        static $meta_type = 'user';
        $meta             = get_metadata_by_mid( $meta_type, $meta_id );

        // Set old meta array.
        self::$old_meta[ $meta_id ] = array(
            'key' => ( $meta ) ? $meta->meta_key : $meta_key,
            'val' => get_metadata( $meta_type, $object_id, $meta_key, true ),
        );
    }

    /**
     * Updated a custom field name/value.
     *
     * @param int    $meta_id - Meta ID.
     * @param int    $object_id - Object ID.
     * @param string $meta_key - Meta key.
     * @param mixed  $meta_value - Meta value.
     *
     */
    public static function event_user_meta_updated( $meta_id, $object_id, $meta_key, $meta_value ) {
        // Check to see if we can log the meta key.
        if ( ! self::can_log_meta_key( 'user', $object_id, $meta_key ) || is_array( $meta_value ) ) {
            return;
        }

        if ( self::is_woocommerce_user_meta( $meta_key ) ) {
            return;
        }

        if ( 'last_update' === $meta_key ) { // Contains timestamp for last user update so ignore it.
            return;
        }

        $username_meta = array( 'first_name', 'last_name', 'nickname' ); // User profile name related meta.
        $user          = get_user_by( 'ID', $object_id ); // Get user.

        if ( isset( self::$old_meta[ $meta_id ] ) && ! in_array( $meta_key, $username_meta, true ) ) {
            // Check change in meta value.
            if ( self::$old_meta[ $meta_id ]['val'] !== $meta_value ) {
                Changes_Logs_Manager::trigger_event_if(
                    4015,
                    array(
                        'TargetUsername'    => $user->user_login,
                        'custom_field_name' => $meta_key,
                        'new_value'         => $meta_value,
                        'old_value'         => self::$old_meta[ $meta_id ]['val'],
                        'FirstName'         => $user->user_firstname,
                        'LastName'          => $user->user_lastname,
                        'Roles'             => is_array( $user->roles ) ? implode( ', ', $user->roles ) : $user->roles,
                        'EditUserLink'      => add_query_arg( 'user_id', $user->ID, \network_admin_url( 'user-edit.php' ) ),
                        'MetaLink'          => $meta_key,
                        'TargetUserData'    => (object) array(
                            'Username'  => $user->user_login,
                            'FirstName' => $user->user_firstname,
                            'LastName'  => $user->user_lastname,
                            'Email'     => $user->user_email,
                            'Roles'     => is_array( $user->roles ) ? implode( ', ', $user->roles ) : $user->roles,
                        ),
                    ),
                    array( __CLASS__, 'must_not_contain_role_changes' )
                );
            }
            // Remove old meta update data.
            unset( self::$old_meta[ $meta_id ] );
        } elseif ( isset( self::$old_meta[ $meta_id ] ) && in_array( $meta_key, $username_meta, true ) ) {
            // Detect the alert based on meta key.
            switch ( $meta_key ) {
                case 'first_name':
                    if ( self::$old_meta[ $meta_id ]['val'] !== $meta_value ) {
                        Changes_Logs_Manager::trigger_event(
                            4017,
                            array(
                                'TargetUsername' => $user->user_login,
                                'new_firstname'  => $meta_value,
                                'old_firstname'  => self::$old_meta[ $meta_id ]['val'],
                                'FirstName'      => $user->user_firstname,
                                'LastName'       => $user->user_lastname,
                                'Roles'          => is_array( $user->roles ) ? implode( ', ', $user->roles ) : $user->roles,
                                'EditUserLink'   => add_query_arg( 'user_id', $user->ID, \network_admin_url( 'user-edit.php' ) ),
                                'MetaLink'       => $meta_key,
                                'TargetUserData' => (object) array(
                                    'Username'  => $user->user_login,
                                    'FirstName' => $user->user_firstname,
                                    'LastName'  => $user->user_lastname,
                                    'Email'     => $user->user_email,
                                    'Roles'     => is_array( $user->roles ) ? implode( ', ', $user->roles ) : $user->roles,
                                ),
                            )
                        );
                    }
                    break;

                case 'last_name':
                    if ( self::$old_meta[ $meta_id ]['val'] !== $meta_value ) {
                        Changes_Logs_Manager::trigger_event(
                            4018,
                            array(
                                'TargetUsername' => $user->user_login,
                                'new_lastname'   => $meta_value,
                                'old_lastname'   => self::$old_meta[ $meta_id ]['val'],
                                'FirstName'      => $user->user_firstname,
                                'LastName'       => $user->user_lastname,
                                'Roles'          => is_array( $user->roles ) ? implode( ', ', $user->roles ) : $user->roles,
                                'EditUserLink'   => add_query_arg( 'user_id', $user->ID, \network_admin_url( 'user-edit.php' ) ),
                                'TargetUserData' => (object) array(
                                    'Username'  => $user->user_login,
                                    'FirstName' => $user->user_firstname,
                                    'LastName'  => $user->user_lastname,
                                    'Email'     => $user->user_email,
                                    'Roles'     => is_array( $user->roles ) ? implode( ', ', $user->roles ) : $user->roles,
                                ),
                            )
                        );
                    }
                    break;

                case 'nickname':
                    if ( self::$old_meta[ $meta_id ]['val'] !== $meta_value ) {
                        Changes_Logs_Manager::trigger_event(
                            4019,
                            array(
                                'TargetUsername' => $user->user_login,
                                'new_nickname'   => $meta_value,
                                'old_nickname'   => self::$old_meta[ $meta_id ]['val'],
                                'FirstName'      => $user->user_firstname,
                                'LastName'       => $user->user_lastname,
                                'Roles'          => is_array( $user->roles ) ? implode( ', ', $user->roles ) : $user->roles,
                                'EditUserLink'   => add_query_arg( 'user_id', $user->ID, \network_admin_url( 'user-edit.php' ) ),
                                'TargetUserData' => (object) array(
                                    'Username'  => $user->user_login,
                                    'FirstName' => $user->user_firstname,
                                    'LastName'  => $user->user_lastname,
                                    'Email'     => $user->user_email,
                                    'Roles'     => is_array( $user->roles ) ? implode( ', ', $user->roles ) : $user->roles,
                                ),
                            )
                        );
                    }
                    break;

                default:
                    break;
            }
        }
    }

    /**
     * Method: This function make sures that alert 4002
     * has not been triggered before updating user meta.
     *
     * @return bool
     *
     */
    public static function must_not_contain_role_changes() {
        return ! Changes_Logs_Manager::will_or_has_triggered( 4002 );
    }

    /**
     * Method: This function make sures that alert 4001
     * has not been triggered before creating user meta.
     *
     * @return bool
     *
     */
    public static function must_not_contain_new_user_alert() {
        return ! Changes_Logs_Manager::will_or_has_triggered( 4001 ) && ! Changes_Logs_Manager::will_or_has_triggered( 4012 );
    }

    /**
     * Check if meta key belongs to WooCommerce user meta.
     *
     * @param string $meta_key - Meta key.
     *
     * @return boolean
     *
     */
    private static function is_woocommerce_user_meta( $meta_key ) {
        // Check for WooCommerce user profile keys.
        if ( false !== strpos( (string) $meta_key, 'shipping_' ) || false !== strpos( (string) $meta_key, 'billing_' ) ) {
            // Remove the prefix to avoid redundancy in the meta keys.
            $address_key = str_replace( array( 'shipping_', 'billing_' ), '', (string) $meta_key );

            // WC address meta keys without prefix.
            $meta_keys = array( 'first_name', 'last_name', 'company', 'country', 'address_1', 'address_2', 'city', 'state', 'postcode', 'phone', 'email' );

            if ( in_array( $address_key, $meta_keys, true ) ) {
                return true;
            }
        }

        // Meta key does not belong to WooCommerce.
        return false;
    }

    /**
     * Check "Excluded Custom Fields" or meta keys starts with "_".
     *
     * @param string $object_type Object type - user or post.
     * @param int    $object_id   - Object ID.
     * @param string $meta_key    - Meta key.
     *
     * @return boolean Can log true|false
     *
     */
    public static function can_log_meta_key( $object_type, $object_id, $meta_key ) {
        // Check if excluded meta key or starts with _.
        if ( '_' === substr( (string) $meta_key, 0, 1 ) ) {
            /**
             * List of hidden keys allowed to log.
             *
             * @since 3.4.1
             */
            $log_hidden_keys = apply_filters( 'mainwp_child_changes_logs_log_hidden_meta_keys', array() );

            // If the meta key is allowed to log then return true.
            if ( in_array( $meta_key, $log_hidden_keys, true ) ) {
                return true;
            }

            return false;
        } elseif ( self::is_excluded_custom_fields( $object_type, $meta_key ) ) {
            return false;
        } else {
            return true;
        }
    }

    /**
     * Check "Excluded Custom Fields".
     * Used in the above function.
     *
     * @param string $object_type Object type - user or post.
     * @param string $custom - Custom meta key.
     *
     * @return boolean is excluded from monitoring true|false
     *
     */
    public static function is_excluded_custom_fields( $object_type, $custom ) {
        $custom_fields = array();
        if ( 'post' === $object_type ) {
            $custom_fields = \MainWP\Child\Changes\Helpers\Changes_Settings_Helper::get_excluded_post_meta_fields();
        } elseif ( 'user' === $object_type ) {
            $custom_fields = \MainWP\Child\Changes\Helpers\Changes_Settings_Helper::get_excluded_user_meta_fields();
        }

        if ( in_array( $custom, $custom_fields, true ) ) {
            return true;
        }

        foreach ( $custom_fields as $field ) {
            if ( false !== strpos( $field, '*' ) ) {
                // Wildcard str[any_character] when you enter (str*).
                if ( '*' === substr( $field, - 1 ) ) {
                    $field = rtrim( $field, '*' );
                    if ( preg_match( "/^$field/", $custom ) ) {
                        return true;
                    }
                }

                // Wildcard [any_character]str when you enter (*str).
                if ( '*' === substr( $field, 0, 1 ) ) {
                    $field = ltrim( $field, '*' );
                    if ( preg_match( "/$field$/", $custom ) ) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
