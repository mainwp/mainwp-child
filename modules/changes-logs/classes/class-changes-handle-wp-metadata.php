<?php
/**
 * Logger: WP Meta Data
 *
 * WP Meta Data logger class file.
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
 * Custom fields (posts, pages, custom posts and users).
 */
class Changes_Handle_WP_MetaData {

    /**
     * Empty meta counter.
     *
     * @var int
     */
    private static $empty_meta_value_counter = 0;

    /**
     * Array of meta data being updated.
     *
     * @var array
     */
    protected static $old_meta = array();

    /**
     * Inits the main hooks
     *
     * @return void
     */
    public static function init_hooks() {
        add_action( 'add_post_meta', array( __CLASS__, 'callback_change_post_meta_created' ), 10, 3 );
        add_action( 'add_user_meta', array( __CLASS__, 'callback_change_user_meta_created' ), 10, 3 );
        add_action( 'deleted_post_meta', array( __CLASS__, 'callback_change_post_meta_deleted' ), 10, 4 );
        add_action( 'save_post', array( __CLASS__, 'change_reset_empty_meta_value_counter' ), 10 );
        add_action( 'update_post_meta', array( __CLASS__, 'callback_change_post_meta_updating' ), 10, 3 );
        add_action( 'update_user_meta', array( __CLASS__, 'callback_change_user_meta_updating' ), 10, 3 );
        add_action( 'updated_post_meta', array( __CLASS__, 'callback_change_post_meta_updated' ), 10, 4 );
        add_action( 'updated_user_meta', array( __CLASS__, 'callback_change_user_meta_updated' ), 10, 4 );
        add_action( 'user_register', array( __CLASS__, 'change_reset_empty_meta_value_counter' ), 10 );
    }

    /**
     * Created a custom field.
     *
     * @param int    $object_id - Object ID.
     * @param string $meta_key - Meta key.
     * @param mixed  $meta_value - Meta value.
     */
    public static function callback_change_post_meta_created( $object_id, $meta_key, $meta_value ) {
        if ( ! self::change_check_can_log_meta_key( 'post', $object_id, $meta_key ) || is_array( $meta_value ) ) {
            return;
        }

        if ( empty( $meta_value ) && ( self::$empty_meta_value_counter < 1 ) ) { // Report only one empty meta value.
            ++self::$empty_meta_value_counter;
        } elseif ( self::$empty_meta_value_counter >= 1 ) {
            return;
        }

        $post = get_post( $object_id );

        if ( null === $post || 'revision' === $post->post_type ) {
            return;
        }

        /**
         * Filter: `mainwp_child_changes_logs_before_post_meta_create_event`
         *
         * @param bool    $event  - True if log meta event, false if not.
         * @param string  $meta_key   - Meta key.
         * @param mixed   $meta_value - Meta value.
         * @param WP_Post $post       - Post object.
         */
        $log_meta_event = apply_filters( 'mainwp_child_changes_logs_before_post_meta_create_event', true, $meta_key, $meta_value, $post );

        if ( $log_meta_event ) {
            $log_data = array(
                'postid'     => $object_id,
                'posttitle'  => $post->post_title,
                'poststatus' => $post->post_status,
                'posttype'   => $post->post_type,
                'postdate'   => $post->post_date,
                'posturl'    => get_permalink( $post->ID ),
                'metakey'    => $meta_key,
                'metavalue'  => $meta_value,
                'metalink'   => $meta_key,
            );
            Changes_Logs_Logger::log_change( 1275, $log_data );
        }
    }

    /**
     * Sets the old meta.
     *
     * @param int    $meta_id - Meta ID.
     * @param int    $object_id - Object ID.
     * @param string $meta_key - Meta key.
     */
    public static function callback_change_post_meta_updating( $meta_id, $object_id, $meta_key ) {
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
     */
    public static function callback_change_post_meta_updated( $meta_id, $object_id, $meta_key, $meta_value ) {
        if ( ! self::change_check_can_log_meta_key( 'post', $object_id, $meta_key ) || is_array( $meta_value ) ) {
            return;
        }

        $post = get_post( $object_id );

        if ( null === $post || 'revision' === $post->post_type ) {
            return;
        }

        /**
         * Action Hook.
         *
         * Runs before logging for post meta updated.
         *
         * @param int    $meta_id        - Meta ID.
         * @param int    $object_id      - Post ID.
         * @param array  self::$old_meta - Array of metadata holding keys & values of old metadata before updating the current post.
         * @param string $meta_key       - Meta key.
         * @param mixed  $meta_value     - Meta value.
         */
        do_action( 'mainwp_child_changes_logs_post_meta_updated', $meta_id, $object_id, (object) self::$old_meta, $meta_key, $meta_value );

        if ( isset( static::$old_meta[ $meta_id ] ) ) {
            /**
             * Filter: `mainwp_child_changes_logs_before_post_meta_update_event`
             *
             * @param bool     $event  - True if log meta event 1355 or 1365, false if not.
             * @param string   $meta_key   - Meta key.
             * @param mixed    $meta_value - Meta value.
             * @param stdClass $old_meta   - Old meta value and key object.
             * @param WP_Post  $post       - Post object.
             * @param integer  $meta_id    - Meta ID.
             */
            $log_meta_event = apply_filters( 'mainwp_child_changes_logs_before_post_meta_update_event', true, $meta_key, $meta_value, self::$old_meta[ $meta_id ], $post, $meta_id );

            if ( $log_meta_event && self::$old_meta[ $meta_id ]['key'] !== $meta_key ) {
                $log_data = array(
                    'postid'     => $object_id,
                    'posttitle'  => $post->post_title,
                    'poststatus' => $post->post_status,
                    'posttype'   => $post->post_type,
                    'postdate'   => $post->post_date,
                    'posturl'    => get_permalink( $post->ID ),
                    'metaid'     => $meta_id,
                    'metakeynew' => $meta_key,
                    'metakeyold' => self::$old_meta[ $meta_id ]['key'],
                    'metavalue'  => $meta_value,
                    'metalink'   => $meta_key,
                );
                Changes_Logs_Logger::log_change( 1365, $log_data );
            } elseif ( $log_meta_event && self::$old_meta[ $meta_id ]['val'] !== $meta_value ) { // Check change in meta value.
                $log_data = array(
                    'postid'       => $object_id,
                    'posttitle'    => $post->post_title,
                    'poststatus'   => $post->post_status,
                    'posttype'     => $post->post_type,
                    'postdate'     => $post->post_date,
                    'posturl'      => get_permalink( $post->ID ),
                    'metaid'       => $meta_id,
                    'metakey'      => $meta_key,
                    'metavaluenew' => $meta_value,
                    'metavalueold' => self::$old_meta[ $meta_id ]['val'],
                    'metalink'     => $meta_key,
                );
                Changes_Logs_Logger::log_change_save_delay( 1355, $log_data );
            }
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
     */
    public static function callback_change_post_meta_deleted( $meta_ids, $object_id, $meta_key, $meta_value ) {
        // If meta key starts with "_" then return.
        if ( '_' === substr( (string) $meta_key, 0, 1 ) ) {
            return;
        }

        $post = get_post( $object_id );

        if ( null === $post ) {
            return;
        }

        foreach ( $meta_ids as $meta_id ) {
            if ( ! self::change_check_can_log_meta_key( 'post', $object_id, $meta_key ) ) {
                continue;
            }

            /**
             * Filter: `mainwp_child_changes_logs_before_post_meta_delete_event`
             *
             * @param bool     $event  - True if log meta event 1360, false if not.
             * @param string   $meta_key   - Meta key.
             * @param mixed    $meta_value - Meta value.
             * @param WP_Post  $post       - Post object.
             * @param integer  $meta_id    - Meta ID.
             */
            $log_meta_event = apply_filters( 'mainwp_child_changes_logs_before_post_meta_delete_event', true, $meta_key, $meta_value, $post, $meta_id );

            if ( ! $log_meta_event ) {
                continue;
            }

            if ( 'trash' !== $post->post_status ) {
                $log_data = array(
                    'postid'     => $object_id,
                    'posttitle'  => $post->post_title,
                    'poststatus' => $post->post_status,
                    'posttype'   => $post->post_type,
                    'postdate'   => $post->post_date,
                    'posturl'    => get_permalink( $post->ID ),
                    'metaid'     => $meta_id,
                    'metakey'    => $meta_key,
                    'metavalue'  => $meta_value,
                );
                Changes_Logs_Logger::log_change( 1360, $log_data );
            }
        }
    }

    /**
     * Method: Reset Null Meta Counter.
     */
    public static function change_reset_empty_meta_value_counter() {
        self::$empty_meta_value_counter = 0;
    }

    /**
     * Create a custom field name/value.
     *
     * @param int    $object_id - Object ID.
     * @param string $meta_key - Meta key.
     * @param mixed  $meta_value - Meta value.
     */
    public static function callback_change_user_meta_created( $object_id, $meta_key, $meta_value ) {
        if ( ! self::change_check_can_log_meta_key( 'user', $object_id, $meta_key ) || is_array( $meta_value ) ) {
            return;
        }

        if ( self::is_woocommerce_user_meta( $meta_key ) ) {
            return;
        }

        if ( empty( $meta_value ) && ( self::$empty_meta_value_counter < 1 ) ) {
            ++self::$empty_meta_value_counter;
        } elseif ( self::$empty_meta_value_counter >= 1 ) {
            return;
        }

        $user     = get_user_by( 'ID', $object_id );
        $log_data = array(
            'changeusername'   => $user->user_login,
            'custom_meta_name' => $meta_key,
            'new_value'        => $meta_value,
            'firstname'        => $user->user_firstname,
            'lastname'         => $user->user_lastname,
            'userroles'        => is_array( $user->roles ) ? implode( ', ', $user->roles ) : $user->roles,
            'changeuserdata'   => (object) array(
                'username'  => $user->user_login,
                'firstname' => $user->user_firstname,
                'lastname'  => $user->user_lastname,
                'userroles' => is_array( $user->roles ) ? implode( ', ', $user->roles ) : $user->roles,
            ),
        );
        Changes_Logs_Logger::log_change_save_delay( 1690, $log_data );
    }

    /**
     * Sets the old meta.
     *
     * @param int    $meta_id - Meta ID.
     * @param int    $object_id - Object ID.
     * @param string $meta_key - Meta key.
     */
    public static function callback_change_user_meta_updating( $meta_id, $object_id, $meta_key ) {
        static $meta_type = 'user';
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
     */
    public static function callback_change_user_meta_updated( $meta_id, $object_id, $meta_key, $meta_value ) {
        if ( ! self::change_check_can_log_meta_key( 'user', $object_id, $meta_key ) || is_array( $meta_value ) ) {
            return;
        }

        if ( self::is_woocommerce_user_meta( $meta_key ) ) {
            return;
        }

        if ( 'last_update' === $meta_key ) {
            return;
        }

        $username_meta = array( 'first_name', 'last_name', 'nickname' );
        $user          = get_user_by( 'ID', $object_id );

        if ( isset( self::$old_meta[ $meta_id ] ) && ! in_array( $meta_key, $username_meta, true ) ) {
            if ( self::$old_meta[ $meta_id ]['val'] !== $meta_value ) {
                $log_data = array(
                    'changeusername'   => $user->user_login,
                    'custom_meta_name' => $meta_key,
                    'new_value'        => $meta_value,
                    'old_value'        => self::$old_meta[ $meta_id ]['val'],
                    'firstname'        => $user->user_firstname,
                    'lastname'         => $user->user_lastname,
                    'userroles'        => is_array( $user->roles ) ? implode( ', ', $user->roles ) : $user->roles,
                    'metalink'         => $meta_key,
                    'changeuserdata'   => (object) array(
                        'username'  => $user->user_login,
                        'firstname' => $user->user_firstname,
                        'lastname'  => $user->user_lastname,
                        'userroles' => is_array( $user->roles ) ? implode( ', ', $user->roles ) : $user->roles,
                    ),
                );
                Changes_Logs_Logger::log_change_save_delay( 1685, $log_data );
            }
            unset( self::$old_meta[ $meta_id ] );
        } elseif ( isset( self::$old_meta[ $meta_id ] ) && in_array( $meta_key, $username_meta, true ) ) {
            switch ( $meta_key ) {
                case 'first_name':
                    if ( self::$old_meta[ $meta_id ]['val'] !== $meta_value ) {
                        $log_data = array(
                            'changeusername' => $user->user_login,
                            'new_firstname'  => $meta_value,
                            'old_firstname'  => self::$old_meta[ $meta_id ]['val'],
                            'firstname'      => $user->user_firstname,
                            'lastname'       => $user->user_lastname,
                            'userroles'      => is_array( $user->roles ) ? implode( ', ', $user->roles ) : $user->roles,
                            'metalink'       => $meta_key,
                            'changeuserdata' => (object) array(
                                'username'  => $user->user_login,
                                'firstname' => $user->user_firstname,
                                'lastname'  => $user->user_lastname,
                                'userroles' => is_array( $user->roles ) ? implode( ', ', $user->roles ) : $user->roles,
                            ),
                        );
                        Changes_Logs_Logger::log_change( 1695, $log_data );
                    }
                    break;

                case 'last_name':
                    if ( self::$old_meta[ $meta_id ]['val'] !== $meta_value ) {
                        $log_data = array(
                            'changeusername' => $user->user_login,
                            'new_lastname'   => $meta_value,
                            'old_lastname'   => self::$old_meta[ $meta_id ]['val'],
                            'firstname'      => $user->user_firstname,
                            'lastname'       => $user->user_lastname,
                            'userroles'      => is_array( $user->roles ) ? implode( ', ', $user->roles ) : $user->roles,
                            'changeuserdata' => (object) array(
                                'username'  => $user->user_login,
                                'firstname' => $user->user_firstname,
                                'lastname'  => $user->user_lastname,
                                'userroles' => is_array( $user->roles ) ? implode( ', ', $user->roles ) : $user->roles,
                            ),
                        );
                        Changes_Logs_Logger::log_change( 1700, $log_data );
                    }
                    break;

                case 'nickname':
                    if ( self::$old_meta[ $meta_id ]['val'] !== $meta_value ) {
                        $log_data = array(
                            'changeusername' => $user->user_login,
                            'new_nickname'   => $meta_value,
                            'old_nickname'   => self::$old_meta[ $meta_id ]['val'],
                            'firstname'      => $user->user_firstname,
                            'lastname'       => $user->user_lastname,
                            'userroles'      => is_array( $user->roles ) ? implode( ', ', $user->roles ) : $user->roles,
                            'changeuserdata' => (object) array(
                                'username'  => $user->user_login,
                                'firstname' => $user->user_firstname,
                                'lastname'  => $user->user_lastname,
                                'userroles' => is_array( $user->roles ) ? implode( ', ', $user->roles ) : $user->roles,
                            ),
                        );
                        Changes_Logs_Logger::log_change( 1705, $log_data );
                    }
                    break;

                default:
                    break;
            }
        }
    }


    /**
     * Check if meta key belongs to WooCommerce user meta.
     *
     * @param string $meta_key - Meta key.
     *
     * @return boolean
     */
    private static function is_woocommerce_user_meta( $meta_key ) {
        if ( false !== strpos( (string) $meta_key, 'shipping_' ) || false !== strpos( (string) $meta_key, 'billing_' ) ) {
            $address_key = str_replace( array( 'shipping_', 'billing_' ), '', (string) $meta_key );

            $meta_keys = array( 'first_name', 'last_name', 'company', 'country', 'address_1', 'address_2', 'city', 'state', 'postcode', 'phone', 'email' );

            if ( in_array( $address_key, $meta_keys, true ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check meta keys starts with "_".
     *
     * @param string $object_type Object type - user or post.
     * @param int    $object_id   - Object ID.
     * @param string $meta_key    - Meta key.
     *
     * @return boolean Can log true|false
     */
    public static function change_check_can_log_meta_key( $object_type, $object_id, $meta_key ) {
        if ( '_' === substr( (string) $meta_key, 0, 1 ) ) {
            $log_hidden_keys = apply_filters( 'mainwp_child_changes_logs_log_hidden_meta_keys', array() );

            if ( is_array( $log_hidden_keys ) && in_array( $meta_key, $log_hidden_keys, true ) ) {
                return true;
            }

            return false;
        } else {
            return true;
        }
    }
}
