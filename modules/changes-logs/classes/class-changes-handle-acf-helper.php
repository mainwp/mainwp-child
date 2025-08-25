<?php
/**
 * Logger: ACF Helper
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
 * Handle ACF Helper class.
 */
class Changes_Handle_ACF_Helper {


    /**
     * Store the state of the ACF plugin.
     *
     * @var bool
     */
    private static $plugin_active = null;

    /**
     * Array of meta data.
     *
     * @var array
     */
    private static $old_meta = array();

    /**
     * Inits the hooks
     *
     * @return void
     */
    public static function init_hooks() {
        if ( static::is_acf_active() ) {
            \add_filter( 'acf/pre_update_value', array( __CLASS__, 'callback_change_before_relationship_update_check' ), 10, 4 );
            // Relationship field is only for posts.
            \add_action( 'updated_post_meta', array( __CLASS__, 'callback_change_field_updated' ), 10, 4 );
            \add_action( 'deleted_post_meta', array( __CLASS__, 'callback_change_field_updated' ), 10, 4 );
        }
    }


    /**
     * Runs before an ACF field value is updated.
     *
     * @param mixed      $check   Variable logic.
     * @param mixed      $value   The new value.
     * @param int|string $post_id The post id.
     * @param array      $field   The field array.
     *
     * @return mixed
     */
    public static function callback_change_before_relationship_update_check( $check, $value, $post_id, $field ) {
        if ( 'relationship' === $field['type'] ) {
            self::$old_meta[ $field['name'] ] = array(
                'field'   => $field,
                'value'   => \get_field( $field['name'] ),
                'post_id' => $post_id,
            );
        }

        return $check;
    }

    /**
     * Fires after updating metadata.
     *
     * @param int    $meta_id ID of metadata.
     * @param int    $object_id ID of the object.
     * @param string $meta_key Metadata key.
     * @param mixed  $_meta_value Metadata value
     */
    public static function callback_change_field_updated( $meta_id, $object_id, $meta_key, $_meta_value ) {
        if ( in_array( $meta_key, array_keys( self::$old_meta ) ) ) { // phpcs:ignore -- ok.
            $old_value = self::get_array_of_post_ids( self::$old_meta[ $meta_key ]['value'] );
            $new_value = self::get_array_of_post_ids( $_meta_value );
            $removed   = array_diff( $old_value, $new_value );
            $added     = array_diff( $new_value, $old_value );

            if ( ! empty( $added ) ) {
                self::log_change_acf( 1345, $added, $object_id, $meta_key, $meta_id );
            }

            if ( ! empty( $removed ) ) {
                self::log_change_acf( 1350, $removed, $object_id, $meta_key, $meta_id );
            }
        }
    }

    /**
     * Log ACF relationship field changes.
     *
     * @param int             $event_id              Event ID.
     * @param int[]|WP_Post[] $relationship_post_ids Posts or post IDs.
     * @param int             $object_id             Object ID.
     * @param string          $meta_key              Meta key.
     * @param int             $meta_id               Meta ID.
     */
    private static function log_change_acf( $event_id, $relationship_post_ids, $object_id, $meta_key, $meta_id ) {
        $post = get_post( $object_id );
        Changes_Logs_Logger::log_change(
            $event_id,
            array(
                'postid'        => $object_id,
                'posttitle'     => $post->post_title,
                'poststatus'    => $post->post_status,
                'posttype'      => $post->post_type,
                'postdate'      => $post->post_date,
                'metaid'        => $meta_id,
                'metakey'       => $meta_key,
                'relationships' => self::get_relationships_label( $relationship_post_ids ),
                'metalink'      => $meta_key,
            )
        );
    }

    /**
     *
     * Get the relationship label.
     *
     * @param int[] $post_ids Post IDs.
     *
     * @return string
     */
    private static function get_relationships_label( $post_ids ) {
        return implode(
            ', ',
            array_map(
                function ( $post_id ) {
                    return get_the_title( $post_id ) . ' (' . $post_id . ')';
                },
                $post_ids
            )
        );
    }

    /**
     * Get value to an array of post IDs.
     *
     * @param mixed $value An array.
     *
     * @return int[]
     */
    private static function get_array_of_post_ids( $value ) {
        $result = array();
        if ( is_array( $value ) ) {
            $result = array_map(
                function ( $item ) {
                    return ( $item instanceof \WP_Post ) ? $item->ID : intval( $item );
                },
                $value
            );
        }
        return $result;
    }

    /**
     * Checks if the ACF is active.
     *
     * @return bool
     */
    public static function is_acf_active() {
        if ( null === self::$plugin_active ) {
            if ( \class_exists( '\ACF', \false ) ) {
                self::$plugin_active = true;
            } else {
                self::$plugin_active = false;
            }
        }
        return self::$plugin_active;
    }
}
