<?php
/**
 * MainWP Custom Post Type
 *
 * MainWP Custom Post Type extension handler.
 *
 * @link https://mainwp.com/extension/custom-post-type/
 * @package MainWP\Child
 */

namespace MainWP\Child;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Custom_Post_Type
 *
 * MainWP Custom Post Type extension handler.
 */
class MainWP_Custom_Post_Type {

    /**
     * Public static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    public static $instance = null;

    /**
     * Public static variable containing the synchronization information.
     *
     * @var array Synchronization information.
     */
    public static $information = array();

    /**
     * Whether the current request uses the bounded v2 response contract.
     *
     * @var bool
     */
    private static $v2_action = false;

    /**
     * Whether the current v2 action has already written child content.
     *
     * @var bool
     */
    private static $v2_mutation_started = false;

    /**
     * Public variable to hold the information about the language domain.
     *
     * @var string 'mainwp-child' languge domain.
     */
    public $plugin_translate = 'mainwp-child';

    /**
     * Create a public static instance of MainWP_Custom_Post_Type.
     *
     * @return MainWP_Custom_Post_Type|null
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }

        return static::$instance;
    }

    /**
     * Constructor.
     *
     * Run any time class is called.
     */
    public function __construct() {
        add_filter( 'mainwp_site_sync_others_data', array( $this, 'hook_sync_others_data' ), 10, 2 );
        $call_inval = array( $this, 'invalidate_recent_custom_posts_cache_safe' );
        add_action( 'save_post', $call_inval );
        add_action( 'trashed_post', $call_inval );
        add_action( 'deleted_post', $call_inval );
        add_action( 'untrashed_post', $call_inval );
    }

    /**
     * Sync other data from $data[] and merge with $information[]
     *
     * @param array $information Returned response array for MainWP CPT Extension actions.
     * @param array $data Other data to sync to $information array.
     *
     * @return array $information Returned information array with both sets of data.
     */
    public function hook_sync_others_data( $information, $data = array() ) {
        if ( isset( $data['sync_cpt_data'] ) && $data['sync_cpt_data'] ) {
            try {
                $ids = $this->get_recent_custom_posts_cached();

                $information['sync_cpt_data'] = array(
                    'custom_taxonomies'   => $this->get_custom_taxonomies(),
                    'recent_custom_posts' => $this->get_recent_custom_posts( $ids ),
                );

            } catch ( MainWP_Exception $e ) {
                // ok!
            }

            if ( isset( $data['sync_cpt_limit'] ) ) {
                $current_limit = get_option( 'mainwp_child_recent_custom_cpt_ids_limit', 20 );
                if ( (int) ( $data['sync_cpt_limit'] ) !== (int) $current_limit && ! empty( $data['sync_cpt_limit'] ) ) {
                    update_option( 'mainwp_child_recent_custom_cpt_ids_limit', $data['sync_cpt_limit'] );
                }
            }
        }
        return $information;
    }

    /**
     * Get custom taxonomies sync data.
     *
     * @return array $data array of data.
     */
    public function get_custom_taxonomies() {

        $args = array(
            'public'   => true,
            '_builtin' => false,
        );

        $output     = 'objects';
        $operator   = 'and';
        $taxonomies = get_taxonomies( $args, $output, $operator );

        $custom = array();

        if ( $taxonomies ) {
            foreach ( $taxonomies  as $tax_name => $taxonomy ) {

                $terms = get_terms(
                    array(
                        'taxonomy'   => $tax_name,
                        'hide_empty' => false,
                    )
                );

                if ( is_array( $terms ) ) {
                    foreach ( $terms as $term ) {

                        $custom[] = array(
                            'term_id'     => $term->term_id,
                            'name'        => $term->name,
                            'slug'        => $term->slug,
                            'taxonomy'    => $term->taxonomy,
                            'parent'      => $term->parent,
                            'post_types'  => $taxonomy->object_type,
                            'description' => $term->description,
                        );
                    }
                }
            }
        }

        return $custom;
    }



    /**
     * Return the truthful closed outcome for a fatal during a v2 import.
     *
     * `rejected` promises the child was left untouched, so it may only be reported while
     * nothing has been written yet. Once the first row, meta delete or term unlink has run
     * the request may have applied in part, which the Dashboard has to reconcile rather
     * than retry.
     *
     * @return array Closed fatal outcome.
     */
    private static function v2_fatal_outcome() {
        return array(
            'outcome' => static::$v2_mutation_started ? 'unknown' : 'rejected',
            'reason'  => 'child_failure',
        );
    }

    /**
     * Method mainwp_custom_post_type_handle_fatal_error()
     *
     * Custom post type fatal error handler.
     */
    public static function mainwp_custom_post_type_handle_fatal_error() {
        $error = error_get_last();
        if ( self::$v2_action && isset( $error['type'] ) && E_ERROR === $error['type'] ) {
            $data = static::v2_fatal_outcome();
        } elseif ( isset( $error['type'] ) && E_ERROR === $error['type'] && isset( $error['message'] ) ) {
            $data = array( 'error' => 'MainWPChild fatal error : ' . $error['message'] . ' Line: ' . $error['line'] . ' File: ' . $error['file'] );
        } else {
            $data = static::$information;
        }

        $data = wp_json_encode( $data );

        die( '<mainwp>' . base64_encode( $data ) . '</mainwp>' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions,WordPress.Security.EscapeOutput -- base64_encode required for backwards compatibility.
    }

    /**
     * Custom post type action.
     *
     * @uses \MainWP\Child\MainWP_Custom_Post_Type::mainwp_custom_post_type_handle_fatal_error()
     */
    public function action() {

        register_shutdown_function( '\MainWP\Child\MainWP_Custom_Post_Type::mainwp_custom_post_type_handle_fatal_error' );
        // phpcs:disable WordPress.Security.NonceVerification
        $information = array();
        $mwp_action  = MainWP_System::instance()->validate_params( 'action' );
        // phpcs:enable WordPress.Security.NonceVerification

        if ( 'custom_post_type_import' === $mwp_action ) {
            $information = $this->import_custom_post();
        } elseif ( 'custom_post_type_import_v2' === $mwp_action ) {
            self::$v2_action     = true;
            static::$information = array(
                'outcome' => 'rejected',
                'reason'  => 'child_failure',
            );
            $information         = $this->import_custom_post_v2();
        } else {
            $information = array( 'error' => 'Unknown action' );
        }

        static::$information = $information;

        exit();
    }

    /**
     * Import custom post type.
     *
     * @return array|string[] $return Response array, or error message on failure.
     */
    private function import_custom_post() { //phpcs:ignore -- NOSONAR - complex.

        add_filter( 'http_request_host_is_external', '__return_true' );

        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( ! isset( $_POST['data'] ) || ( is_string( $_POST['data'] ) && strlen( wp_unslash( $_POST['data'] ) ) < 2 ) ) {
            return array( 'error' => esc_html__( 'Missing data', 'mainwp-child' ) );
        }
        $data = array();
        if ( is_string( $_POST['data'] ) ) { // to compatible.
            $data = stripslashes( wp_unslash( $_POST['data'] ) );
            $data = json_decode( $data, true );
        } elseif ( is_array( $_POST['data'] ) ) {
            $data = wp_unslash( $_POST['data'] );
        }

        if ( empty( $data ) || ! is_array( $data ) || ! isset( $data['post'] ) ) {
            return array( 'error' => esc_html__( 'Cannot decode data', 'mainwp-child' ) );
        }
        $edit_id = ( isset( $_POST['post_id'] ) && ! empty( $_POST['post_id'] ) ) ? intval( wp_unslash( $_POST['post_id'] ) ) : 0;
        // phpcs:enable
        $return = $this->insert_post( $data, $edit_id );
        if ( isset( $return['success'] ) && 1 === (int) $return['success'] && isset( $data['product_variation'] ) && is_array( $data['product_variation'] ) ) {
            foreach ( $data['product_variation'] as $product_variation ) {
                $this->insert_post( $product_variation, 0, $return['post_id'] );
            }
        }
        return $return;
    }

    /**
     * Import one strictly bounded v2 custom post payload.
     *
     * @return array Closed v2 result.
     */
    private function import_custom_post_v2() { // phpcs:ignore -- bounded orchestration is clearer in one method.
        self::$v2_mutation_started = false;
        add_filter( 'http_request_host_is_external', '__return_true' );

        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( ! isset( $_POST['data'] ) ) {
            return $this->v2_rejected( 'invalid_payload' );
        }
        $raw = wp_unslash( $_POST['data'] );
        if ( is_string( $raw ) ) {
            if ( 0 === strlen( $raw ) || 16777216 < strlen( $raw ) ) {
                return $this->v2_rejected( 'invalid_payload' );
            }
            $data = json_decode( $raw, true, 10 );
            if ( JSON_ERROR_NONE !== json_last_error() ) {
                return $this->v2_rejected( 'invalid_payload' );
            }
        } elseif ( is_array( $raw ) ) {
            $data    = $raw;
            $encoded = wp_json_encode( $data );
            if ( ! is_string( $encoded ) || 16777216 < strlen( $encoded ) ) {
                return $this->v2_rejected( 'invalid_payload' );
            }
        } else {
            return $this->v2_rejected( 'invalid_payload' );
        }
        $edit_id = isset( $_POST['post_id'] ) ? $this->v2_positive_id( wp_unslash( $_POST['post_id'] ) ) : 0;
        // phpcs:enable
        if ( false === $edit_id || ! $this->validate_v2_payload( $data, false ) ) {
            return $this->v2_rejected( 'invalid_payload' );
        }

        $post_type    = $data['post']['post_type'];
        $custom_types = get_post_types( array( '_builtin' => false ) );
        if ( ! in_array( $post_type, $custom_types, true ) ) {
            return $this->v2_rejected( 'unsupported_post_type' );
        }
        if ( isset( $data['product_variation'] ) ) {
            foreach ( $data['product_variation'] as $variation_payload ) {
                // Variations reach wp_insert_post through the same writer, so they need the same
                // registered-and-not-builtin gate; without it any post type could be imported here.
                if ( ! in_array( $variation_payload['post']['post_type'], $custom_types, true ) ) {
                    return $this->v2_rejected( 'unsupported_post_type' );
                }
            }
        }
        $mode = 'created';
        if ( $edit_id > 0 ) {
            $existing = get_post( $edit_id, ARRAY_A );
            if ( is_array( $existing ) ) {
                if ( 'trash' === get_post_status( $edit_id ) || $post_type !== $existing['post_type'] ) {
                    return $this->v2_rejected( 'target_rejected' );
                }
                $mode = 'updated';
            } else {
                $edit_id = 0;
                $mode    = 'recreated_after_stale_mapping';
            }
        }

        $main = $this->insert_post_v2( $data, $edit_id, 0 );
        if ( empty( $main['post_id'] ) ) {
            return $this->v2_rejected( 'child_failure' );
        }

        $stages     = $main['failed_stages'];
        $variations = array(
            'total'     => 0,
            'succeeded' => 0,
            'failed'    => 0,
        );
        if ( isset( $data['product_variation'] ) ) {
            $variations['total'] = count( $data['product_variation'] );
            foreach ( $data['product_variation'] as $variation ) {
                $result = $this->insert_post_v2( $variation, 0, $main['post_id'] );
                if ( empty( $result['post_id'] ) || ! empty( $result['failed_stages'] ) ) {
                    ++$variations['failed'];
                } else {
                    ++$variations['succeeded'];
                }
            }
            if ( $variations['failed'] > 0 ) {
                $stages[] = 'variations';
            }
        }

        $stages = array_values( array_unique( $stages ) );
        return array(
            'outcome'        => empty( $stages ) ? 'complete' : 'partial',
            'content_status' => empty( $stages ) ? 'complete' : 'partial',
            'partial_stage'  => $this->v2_partial_stage( $stages ),
            'post_id'        => (int) $main['post_id'],
            'mode'           => $mode,
            'variations'     => $variations,
        );
    }

    /**
     * Validate a complete main or variation payload before mutation.
     *
     * @param mixed $data Payload.
     * @param bool  $variation Whether this is a variation payload.
     * @return bool
     */
    private function validate_v2_payload( $data, $variation ) { // phpcs:ignore -- explicit closed validation.
        if ( ! is_array( $data ) ) {
            return false;
        }
        $required = $variation ? array( 'post', 'postmeta', 'extras' ) : array( 'post', 'postmeta', 'terms', 'extras', 'categories', 'post_only_existing' );
        $allowed  = $required;
        if ( ! $variation ) {
            $allowed[] = 'product_variation';
        }
        if ( array() !== array_diff( $required, array_keys( $data ) ) || array() !== array_diff( array_keys( $data ), $allowed ) ) {
            return false;
        }
        $post_keys = array( 'post_date', 'post_date_gmt', 'post_content', 'post_title', 'post_excerpt', 'post_status', 'comment_status', 'ping_status', 'post_password', 'post_name', 'to_ping', 'pinged', 'post_modified', 'post_modified_gmt', 'post_content_filtered', 'menu_order', 'post_type' );
        if ( ! is_array( $data['post'] ) || array() !== array_diff( $post_keys, array_keys( $data['post'] ) ) || array() !== array_diff( array_keys( $data['post'] ), $post_keys ) ) {
            return false;
        }
        foreach ( $post_keys as $key ) {
            if ( 'menu_order' === $key ) {
                if ( ! is_int( $data['post'][ $key ] ) ) {
                    return false;
                }
            } elseif ( ! is_string( $data['post'][ $key ] ) || ( in_array( $key, array( 'post_content', 'post_content_filtered' ), true ) ? 8388608 : 65535 ) < strlen( $data['post'][ $key ] ) ) {
                return false;
            }
        }
        if ( ! is_array( $data['postmeta'] ) || 2000 < count( $data['postmeta'] ) ) {
            return false;
        }
        // A null meta_value is accepted and reaches strlen() as null, which PHP 8.1+ deprecates; the
        // notice would print ahead of the payload on a site with display_errors on and break the
        // Dashboard's parse of an otherwise valid import. The cast keeps every verdict identical.
        foreach ( $data['postmeta'] as $meta ) {
            $meta_keys = is_array( $meta ) ? array_keys( $meta ) : array();
            sort( $meta_keys );
            if ( array( 'meta_key', 'meta_value' ) !== $meta_keys || ! is_string( $meta['meta_key'] ) || '' === $meta['meta_key'] || 255 < strlen( $meta['meta_key'] ) || ( ! is_scalar( $meta['meta_value'] ) && null !== $meta['meta_value'] ) || 1048576 < strlen( (string) maybe_serialize( $meta['meta_value'] ) ) ) {
                return false;
            }
        }
        if ( ! is_array( $data['extras'] ) || array_diff( array_keys( $data['extras'] ), array( 'upload_dir', 'featured_image', 'woocommerce' ) ) || ! isset( $data['extras']['upload_dir']['baseurl'] ) || ! is_array( $data['extras']['upload_dir'] ) || array_diff( array_keys( $data['extras']['upload_dir'] ), array( 'path', 'url', 'subdir', 'basedir', 'baseurl', 'error' ) ) || ! is_string( $data['extras']['upload_dir']['baseurl'] ) || 2048 < strlen( $data['extras']['upload_dir']['baseurl'] ) || ! in_array( wp_parse_url( $data['extras']['upload_dir']['baseurl'], PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ) {
            return false;
        }
        if ( isset( $data['extras']['woocommerce'] ) && ( ! is_array( $data['extras']['woocommerce'] ) || array_diff( array_keys( $data['extras']['woocommerce'] ), array( 'product_images' ) ) ) ) {
            return false;
        }
        $media = array();
        if ( isset( $data['extras']['featured_image'] ) ) {
            $media[] = $data['extras']['featured_image'];
        }
        if ( isset( $data['extras']['woocommerce']['product_images'] ) ) {
            if ( ! is_array( $data['extras']['woocommerce']['product_images'] ) ) {
                return false;
            }
            $media = array_merge( $media, $data['extras']['woocommerce']['product_images'] );
        }
        if ( 500 < count( $media ) ) {
            return false;
        }
        foreach ( $media as $url ) {
            if ( ! is_string( $url ) || 2048 < strlen( $url ) || ! in_array( wp_parse_url( $url, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ) {
                return false;
            }
        }
        if ( $variation ) {
            return true;
        }
        if ( array() !== $data['categories'] || 0 !== $data['post_only_existing'] || ! is_array( $data['terms'] ) || 1000 < count( $data['terms'] ) || ( isset( $data['product_variation'] ) && ( ! is_array( $data['product_variation'] ) || 200 < count( $data['product_variation'] ) ) ) ) {
            return false;
        }
        if ( isset( $data['product_variation'] ) ) {
            foreach ( $data['product_variation'] as $variation_payload ) {
                if ( ! $this->validate_v2_payload( $variation_payload, true ) ) {
                    return false;
                }
            }
        }
        foreach ( $data['terms'] as $term ) {
            $term_keys = is_array( $term ) ? array_keys( $term ) : array();
            sort( $term_keys );
            if ( array( 'description', 'name', 'parent', 'slug', 'taxonomy', 'term_group', 'term_order' ) !== $term_keys || ! is_string( $term['name'] ) || '' === $term['name'] || ! is_string( $term['slug'] ) || '' === $term['slug'] || ! is_string( $term['taxonomy'] ) || sanitize_key( $term['taxonomy'] ) !== $term['taxonomy'] || ! is_string( $term['description'] ) || 200 < strlen( $term['name'] ) || 200 < strlen( $term['slug'] ) || 32 < strlen( $term['taxonomy'] ) || 2000 < strlen( $term['description'] ) || ! $this->v2_nonnegative_integer( $term['parent'] ) || ! $this->v2_nonnegative_integer( $term['term_group'] ) || ! $this->v2_nonnegative_integer( $term['term_order'] ) ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Create or update the main row before subordinate content stages.
     *
     * @param array $data Validated entity payload.
     * @param int   $edit_id Existing post ID.
     * @param int   $parent_id Parent post ID.
     * @return array
     */
    private function insert_post_v2( $data, $edit_id, $parent_id ) {
        $insert                 = $data['post'];
        $insert['post_author']  = get_current_user_id();
        $insert['post_title']   = htmlspecialchars( $insert['post_title'] );
        $insert['post_excerpt'] = MainWP_Utility::esc_content( $insert['post_excerpt'], 'mixed' );
        if ( $parent_id > 0 ) {
            $insert['post_parent'] = $parent_id;
        }
        self::$v2_mutation_started = true;
        if ( $edit_id > 0 ) {
            $insert['ID'] = $edit_id;
            $post_id      = wp_update_post( $insert, true );
        } else {
            $post_id = wp_insert_post( $insert, true );
        }
        if ( is_wp_error( $post_id ) || $post_id < 1 ) {
            return array(
                'post_id'       => 0,
                'failed_stages' => array(),
            );
        }

        $failed = array();
        $media  = $this->search_images_v2( $insert['post_content'], $data['extras']['upload_dir'], $edit_id > 0 );
        if ( $media['failed'] > 0 ) {
            $failed[] = 'content_media';
        }
        if ( $media['content'] !== $insert['post_content'] ) {
            $updated = wp_update_post(
                array(
                    'ID'           => $post_id,
                    'post_content' => $media['content'],
                ),
                true
            );
            if ( is_wp_error( $updated ) ) {
                $failed[] = 'content_media';
            }
        }

        if ( $edit_id > 0 ) {
            foreach ( get_post_meta( $post_id ) as $meta_key => $unused ) {
                if ( ! delete_post_meta( $post_id, $meta_key ) ) {
                    $failed[] = 'postmeta';
                    break;
                }
            }
            wp_delete_object_term_relationships( $post_id, get_object_taxonomies( $insert['post_type'] ) );
        }
        $is_woocommerce  = in_array( $insert['post_type'], array( 'product', 'product_variation' ), true ) && function_exists( 'wc_product_has_unique_sku' );
        $metadata_stages = array();
        if ( ! empty( $data['postmeta'] ) && true !== $this->insert_postmeta( $post_id, $data, $edit_id > 0, $is_woocommerce, $metadata_stages ) && empty( $metadata_stages ) ) {
            $metadata_stages[] = 'postmeta';
        }
        $failed = array_merge( $failed, $metadata_stages );
        if ( isset( $data['terms'] ) && true !== $this->insert_custom_data( $post_id, $data ) ) {
            $failed[] = 'taxonomy';
        }
        return array(
            'post_id'       => (int) $post_id,
            'failed_stages' => array_values( array_unique( $failed ) ),
        );
    }

    /**
     * Rewrite content images while counting bounded failures.
     *
     * @param string $content Content.
     * @param array  $upload_dir Source upload directory.
     * @param bool   $check_image Whether existing images may be reused.
     * @return array
     */
    private function search_images_v2( $content, $upload_dir, $check_image ) {
        $failed  = 0;
        $matches = array();
        preg_match_all( '/<img[^>\/]*src="((.*?)(png|gif|jpg|jpeg|avif))"/ix', $content, $matches, PREG_SET_ORDER );
        foreach ( $matches as $match ) {
            $url = $match[1];
            if ( false === strripos( $url, $upload_dir['baseurl'] ) ) {
                continue;
            }
            try {
                $uploaded = MainWP_Utility::upload_image( $url, array(), $check_image );
                if ( ! is_array( $uploaded ) || empty( $uploaded['url'] ) || ! is_string( $uploaded['url'] ) ) {
                    ++$failed;
                    continue;
                }
                $content = str_replace( $url, $uploaded['url'], $content );
            } catch ( MainWP_Exception $e ) {
                ++$failed;
            }
        }
        return array(
            'content' => $content,
            'failed'  => $failed,
        );
    }

    /**
     * Return a closed pre-mutation rejection.
     *
     * @param string $reason Stable rejection reason.
     * @return array
     */
    private function v2_rejected( $reason ) {
        return array(
            'outcome' => 'rejected',
            'reason'  => $reason,
        );
    }

    /**
     * Normalize an optional positive ID.
     *
     * @param mixed $value Candidate ID.
     * @return int|false
     */
    private function v2_positive_id( $value ) {
        if ( is_int( $value ) && $value > 0 ) {
            return $value;
        }
        if ( ! is_string( $value ) || ! preg_match( '/^[1-9][0-9]*$/', $value ) || (string) (int) $value !== $value ) {
            return false;
        }
        return (int) $value;
    }

    /**
     * Return whether a stored numeric field is a bounded non-negative integer.
     *
     * @param mixed $value Candidate numeric value.
     * @return bool
     */
    private function v2_nonnegative_integer( $value ) {
        if ( is_int( $value ) ) {
            return $value >= 0;
        }
        return is_string( $value ) && preg_match( '/^(0|[1-9][0-9]*)$/', $value ) && strlen( $value ) <= 20;
    }

    /**
     * Select the first bounded partial stage.
     *
     * @param array $stages Failed stages.
     * @return string
     */
    private function v2_partial_stage( $stages ) {
        if ( empty( $stages ) ) {
            return 'none';
        }
        return 1 === count( $stages ) ? $stages[0] : 'multiple';
    }

    /**
     * Search for images inside post content and upload it to Child Site.
     *
     * @param string $post_content Post content to search.
     * @param string $upload_dir Upload directory.
     * @param bool   $check_image Check if file exists. Default: false.
     *
     * @return string|string[] Error message or post content string.
     *
     * @uses \MainWP\Child\MainWP_Utility::upload_image()
     */
    private function search_images( $post_content, $upload_dir, $check_image = false ) { //phpcs:ignore -- NOSONAR - complex.
        $foundMatches = preg_match_all( '/(<a[^>]+href=\"(.*?)\"[^>]*>)?(<img[^>\/]*src=\"((.*?)(png|gif|jpg|jpeg|avif))\")/ix', $post_content, $matches, PREG_SET_ORDER );
        if ( $foundMatches > 0 ) {
            foreach ( $matches as $match ) {
                $hrefLink = $match[2];
                $imgUrl   = $match[4];

                if ( ! isset( $upload_dir['baseurl'] ) || ( false === strripos( $imgUrl, $upload_dir['baseurl'] ) ) ) {
                    continue;
                }

                if ( preg_match( '/-\d{3}x\d{3}\.[a-zA-Z0-9]{3,4}$/', $imgUrl, $imgMatches ) ) {
                    $search         = $imgMatches[0];
                    $replace        = '.' . $match[6];
                    $originalImgUrl = str_replace( $search, $replace, $imgUrl );
                } else {
                    $originalImgUrl = $imgUrl;
                }

                try {
                    $downloadfile      = MainWP_Utility::upload_image( $originalImgUrl, array(), $check_image );
                    $localUrl          = $downloadfile['url'];
                    $linkToReplaceWith = dirname( $localUrl );
                    if ( '' !== $hrefLink ) {
                        $server     = MainWP_Child_Keys_Manager::get_encrypted_option( 'mainwp_child_server' );
                        $serverHost = wp_parse_url( $server, PHP_URL_HOST );
                        if ( ! empty( $serverHost ) && strpos( $hrefLink, $serverHost ) !== false ) {
                            $serverHref        = 'href="' . $serverHost;
                            $replaceServerHref = 'href="' . wp_parse_url( $localUrl, PHP_URL_SCHEME ) . '://' . wp_parse_url( $localUrl, PHP_URL_HOST );
                            $post_content      = str_replace( $serverHref, $replaceServerHref, $post_content );
                        } elseif ( strpos( $hrefLink, 'http' ) !== false ) {
                            $lnkToReplace = dirname( $hrefLink );
                            if ( 'http:' !== $lnkToReplace && 'https:' !== $lnkToReplace ) {
                                $post_content = str_replace( $imgUrl, $localUrl, $post_content ); // replace src image.
                                $post_content = str_replace( $lnkToReplace, $linkToReplaceWith, $post_content );
                            }
                        }
                    }

                    $lnkToReplace = dirname( $imgUrl );
                    if ( 'http:' !== $lnkToReplace && 'https:' !== $lnkToReplace ) {
                        $post_content = str_replace( $imgUrl, $localUrl, $post_content ); // replace src image.
                        $post_content = str_replace( $lnkToReplace, $linkToReplaceWith, $post_content );
                    }
                } catch ( MainWP_Exception $e ) {
                    // ok!
                }
            }
        }

        return $post_content;
    }

    /**
     * Insert data into published post.
     *
     * @param string $data Data to insert.
     * @param int    $edit_id Post ID to edit.
     * @param int    $parent_id Post parent ID.
     *
     * @return array|bool|string[] Response array, true|false, Error message.
     */
    private function insert_post( $data, $edit_id, $parent_id = 0 ) { // phpcs:ignore -- NOSONAR -required to achieve desired results, pull request solutions appreciated.
        $data_insert                = array();
        $data_post                  = $data['post'];
        $data_insert['post_author'] = get_current_user_id();

        $data_keys = array(
            'post_date',
            'post_date_gmt',
            'post_content',
            'post_title',
            'post_excerpt',
            'post_status',
            'comment_status',
            'ping_status',
            'post_password',
            'post_name',
            'to_ping',
            'pinged',
            'post_modified',
            'post_modified_gmt',
            'post_content_filtered',
            'menu_order',
            'post_type',
        );

        foreach ( $data_keys as $key ) {
            if ( ! isset( $data_post[ $key ] ) ) {
                return array( 'error' => __( 'Missing', 'mainwp-child' ) . ' ' . $key . ' ' . esc_html__( 'inside post data', 'mainwp-child' ) );
            }

            if ( 'post_title' === $key ) {
                $data_insert[ $key ] = htmlspecialchars( $data_post[ $key ] );
            } elseif ( 'post_excerpt' === $key ) {
                $data_insert[ $key ] = MainWP_Utility::esc_content( $data_post[ $key ], 'mixed' );
            } else {
                $data_insert[ $key ] = $data_post[ $key ];
            }
        }

        if ( ! in_array( $data_insert['post_type'], get_post_types( array( '_builtin' => false ) ) ) ) {
            return array( 'error' => esc_html__( 'Please install', 'mainwp-child' ) . ' ' . $data_insert['post_type'] . ' ' . esc_html__( 'on child and try again', 'mainwp-child' ) );
        }

        $is_woocomerce = false;
        if ( ( 'product' === $data_insert['post_type'] || 'product_variation' === $data_insert['post_type'] ) && function_exists( 'wc_product_has_unique_sku' ) ) {
            $is_woocomerce = true;
        }

        $check_image_existed = false;

        if ( ! empty( $edit_id ) ) {
            $old_post_id = (int) $edit_id;
            $old_post    = get_post( $old_post_id, ARRAY_A );
            if ( is_null( $old_post ) ) {
                return array(
                    'delete_connection' => 1,
                    'error'             => esc_html__( 'Cannot get old post. Probably is deleted now. Please try again for create new post', 'mainwp-child' ),
                );
            }

            if ( get_post_status( $old_post_id ) === 'trash' ) {
                return array( 'error' => esc_html__( 'This post is inside trash on child website. Please try publish it manually and try again.', 'mainwp-child' ) );
            }
            $check_image_existed = true;
            $data_insert['ID']   = $old_post_id;

            // Remove all previous post meta.
            // Get all unique meta_key.
            foreach ( get_post_meta( $old_post_id ) as $temp_meta_key => $temp_meta_val ) {
                if ( ! delete_post_meta( $old_post_id, $temp_meta_key ) ) {
                    return array( 'error' => esc_html__( 'Cannot delete old post meta values', 'mainwp-child' ) );
                }
            }

            // Remove all previous taxonomy.
            wp_delete_object_term_relationships( $old_post_id, get_object_taxonomies( $data_insert['post_type'] ) );
        }

        $data_insert['post_content'] = $this->search_images( $data_insert['post_content'], $data['extras']['upload_dir'], $check_image_existed );

        if ( ! empty( $parent_id ) ) {
            $data_insert['post_parent'] = $parent_id;
        }

        if ( ! empty( $edit_id ) ) {
            $post_id = wp_update_post( $data_insert, true );
        } else {
            $post_id = wp_insert_post( $data_insert, true );
        }

        if ( is_wp_error( $post_id ) ) {
            return array( 'error' => esc_html__( 'Error when insert new post:', 'mainwp-child' ) . ' ' . $post_id->get_error_message() );
        }

        // Insert post meta.
        if ( ! empty( $data['postmeta'] ) && is_array( $data['postmeta'] ) ) {
            $ret = $this->insert_postmeta( $post_id, $data, $check_image_existed, $is_woocomerce );
            if ( true !== $ret ) {
                return $ret;
            }
        }

        $ret = $this->insert_custom_data( $post_id, $data );

        if ( true !== $ret ) {
            return $ret;
        }

        return array(
            'success' => 1,
            'post_id' => $post_id,
        );
    }

    /**
     * Insert custom post data.
     *
     * @param int    $post_id Post ID to update.
     * @param string $data Custom data to add.
     * @return array|bool|string[] Response array, true|false, Error message.
     */
    private function insert_custom_data( $post_id, $data ) { //phpcs:ignore -- NOSONAR - complex.

        // MainWP Categories.
        if ( ! empty( $data['categories'] ) && is_array( $data['categories'] ) ) {
            // Contains wp_create_categories.
            include_once ABSPATH . 'wp-admin/includes/taxonomy.php'; // NOSONAR -- WP compatible.

            $categories = $data['categories'];

            $post_cust_cats = array();
            $new_cust_cats  = array();

            foreach ( $categories as $cat ) {
                if ( is_string( $cat ) ) {
                    $new_cust_cats[] = $cat;
                } else {
                    $post_cust_cats[] = $cat;
                }
            }

            $post_type  = isset( $data['post']['post_type'] ) ? $data['post']['post_type'] : '';
            $cust_terms = $this->get_custom_taxonomies();

            if ( ! empty( $post_type ) ) {
                if ( $post_cust_cats ) {
                    if ( '0' === $data['post_only_existing'] ) {
                        $found = false;
                        foreach ( $post_cust_cats as $term ) {
                            $post_term = false;
                            $found     = $this->find_existed_term( $cust_terms, $term, $post_type );

                            if ( ! $found ) {
                                $new_term = wp_insert_term(
                                    $term['name'],
                                    $term['taxonomy'],
                                    array(
                                        'description' => $term['description'],
                                        'slug'        => $term['slug'],
                                    )
                                );
                                if ( is_array( $new_term ) && ! empty( $new_term['term_taxonomy_id'] ) ) {
                                    $post_term = get_term_by( 'term_taxonomy_id', $new_term['term_taxonomy_id'], '', ARRAY_A );
                                }
                            } else {
                                $post_term = $found;
                            }

                            if ( $post_term && ! empty( $post_term['name'] ) ) {
                                wp_set_object_terms( $post_id, $post_term['name'], $post_term['taxonomy'], true );
                            }
                        }
                    } else {
                        $post_terms_ids = array();
                        foreach ( $post_cust_cats as $term ) {
                            $found = $this->find_existed_term( $cust_terms, $term, $post_type );
                            if ( $found && ! empty( $found['term_id'] ) ) {
                                $post_terms_ids[] = $found['term_id'];
                            }
                        }
                        if ( ! empty( $post_terms_ids ) ) {
                            wp_set_object_terms( $post_id, $post_terms_ids );
                        }
                    }
                }

                if ( $new_cust_cats ) {
                    $cust_terms = $this->get_custom_taxonomies(); // reload.
                    if ( '0' === $data['post_only_existing'] ) {
                        $found = false;
                        foreach ( $new_cust_cats as $name ) {
                            $post_term = false;
                            $found     = $this->find_existed_term( $cust_terms, array( 'name' => $name ), $post_type );

                            if ( ! $found ) {
                                $found_taxo = $this->find_existed_taxonomy( $cust_terms, $post_type );
                                if ( $found_taxo ) {
                                    $new_term = wp_insert_term(
                                        $name,
                                        $found_taxo['taxonomy'],
                                        array(
                                            'description' => $found_taxo['description'],
                                        )
                                    );
                                    if ( is_array( $new_term ) && ! empty( $new_term['term_taxonomy_id'] ) ) {
                                        $post_term = get_term_by( 'term_taxonomy_id', $new_term['term_taxonomy_id'], '', ARRAY_A );
                                    }
                                }
                            } else {
                                $post_term = $found;
                            }

                            if ( $post_term && ! empty( $post_term['name'] ) ) {
                                wp_set_object_terms( $post_id, $post_term['name'], $post_term['taxonomy'], true );
                            }
                        }
                    } else {
                        $post_terms_ids = array();
                        foreach ( $post_cust_cats as $name ) {
                            $found = $this->find_existed_term( $cust_terms, array( 'name' => $name ), $post_type );
                            if ( $found && ! empty( $found['term_id'] ) ) {
                                $post_terms_ids[] = $found['term_id'];
                            }
                        }
                        if ( ! empty( $post_terms_ids ) ) {
                            wp_set_object_terms( $post_id, $post_terms_ids );
                        }
                    }
                }
            }
        }

        // Insert post terms except categories.
        if ( ! empty( $data['terms'] ) && is_array( $data['terms'] ) ) {
            foreach ( $data['terms'] as $key ) {
                if ( ! taxonomy_exists( $key['taxonomy'] ) ) {
                    return array( 'error' => esc_html__( 'Missing taxonomy', 'mainwp-child' ) . ' `' . esc_html( $key['taxonomy'] ) . '`' );
                }

                $term = wp_insert_term(
                    $key['name'],
                    $key['taxonomy'],
                    array(
                        'description' => $key['description'],
                        'slug'        => $key['slug'],
                    )
                );

                $term_taxonomy_id = 0;

                if ( is_wp_error( $term ) ) {
                    if ( isset( $term->error_data['term_exists'] ) ) {
                        $term_taxonomy_id = (int) $term->error_data['term_exists'];
                    }
                } elseif ( isset( $term['term_taxonomy_id'] ) ) {
                        $term_taxonomy_id = (int) $term['term_taxonomy_id'];
                }

                if ( $term_taxonomy_id > 0 ) {
                    $term_taxonomy_ids = wp_set_object_terms( $post_id, $term_taxonomy_id, $key['taxonomy'], true );
                    if ( is_wp_error( $term_taxonomy_ids ) ) {
                        return array( 'error' => esc_html__( 'Error when adding taxonomy to post', 'mainwp-child' ) );
                    }
                }
            }
        }
        return true;
    }

    /**
     * Find existed term.
     *
     * @param array  $cust_terms Custom existed Terms.
     * @param array  $term Term to find.
     * @param string $post_type Custom post type.
     *
     * @return mixed Result.
     */
    public function find_existed_term( $cust_terms, $term, $post_type ) {
        if ( ! is_array( $term ) || empty( $term['name'] ) ) {
            return false;
        }
        $found = false;
        foreach ( $cust_terms as $item ) {
            if ( $item['name'] === $term['name'] && is_array( $item['post_types'] ) && in_array( $post_type, $item['post_types'], true ) ) {
                $found = $item;
                break;
            }
        }
        return $found;
    }

    /**
     * Find existed taxonomy for post type.
     *
     * @param array  $cust_terms Custom existed Terms.
     * @param string $post_type Custom post type.
     *
     * @return mixed Result.
     */
    public function find_existed_taxonomy( $cust_terms, $post_type ) {

        if ( empty( $post_type ) ) {
            return false;
        }

        $found = false;
        foreach ( $cust_terms as $item ) {
            if ( in_array( $post_type, $item['post_types'], true ) ) {
                $found = $item;
                break;
            }
        }
        return $found;
    }


    /**
     * Insert post meta.
     *
     * @param int        $post_id Post ID to update.
     * @param string     $data Meta datat add.
     * @param bool       $check_image_existed Whether or not to check if image exists. true|false.
     * @param bool       $is_woocomerce    Whether or not the post is a woocommerce product. true|false.
     * @param array|null $v2_failed_stages Optional v2 failure-stage collector.
     *
     * @return array|bool|string[] Response array, true|false, Error message.
     *
     * @uses \MainWP\Child\MainWP_Utility::upload_image()\
     */
    private function insert_postmeta( $post_id, $data, $check_image_existed, $is_woocomerce, &$v2_failed_stages = null ) { //phpcs:ignore -- NOSONAR - complex.
        foreach ( $data['postmeta'] as $key ) {
            if ( isset( $key['meta_key'] ) && isset( $key['meta_value'] ) ) {
                $meta_value = $key['meta_value'];
                if ( $is_woocomerce ) {
                    if ( '_sku' === $key['meta_key'] && ! wc_product_has_unique_sku( $post_id, $meta_value ) ) {
                        return array( 'error' => esc_html__( 'Product SKU must be unique', 'mainwp-child' ) );
                    }
                    if ( '_product_image_gallery' === $key['meta_key'] ) {
                        if ( isset( $data['extras']['woocommerce']['product_images'] ) ) {
                            $ret = $this->upload_postmeta_image( $data['extras']['woocommerce']['product_images'], $meta_value, $check_image_existed, $v2_failed_stages );
                            if ( true !== $ret ) {
                                return $ret;
                            }
                        } else {
                            continue;
                        }
                    }
                }

                if ( '_thumbnail_id' === $key['meta_key'] ) {
                    if ( isset( $data['extras']['featured_image'] ) ) {
                        try {
                            $upload_featured_image = MainWP_Utility::upload_image( $data['extras']['featured_image'], array(), $check_image_existed );

                            if ( null !== $upload_featured_image ) {
                                $meta_value = $upload_featured_image['id'];
                            } else {
                                if ( is_array( $v2_failed_stages ) ) {
                                    $v2_failed_stages[] = 'content_media';
                                    continue;
                                }
                                return array( 'error' => esc_html__( 'Cannot add featured image', 'mainwp-child' ) );
                            }
                        } catch ( MainWP_Exception $e ) {
                            if ( is_array( $v2_failed_stages ) ) {
                                $v2_failed_stages[] = 'content_media';
                            }
                            continue;
                        }
                    } else {
                        continue;
                    }
                }

                $meta_value = maybe_unserialize( $meta_value ); // NOSONARR .
                if ( add_post_meta( $post_id, $key['meta_key'], $meta_value ) === false ) {
                    return array( 'error' => esc_html__( 'Error when adding post meta', 'mainwp-child' ) . ' `' . esc_html( $key['meta_key'] ) . '`' );
                }
            }
        }
        return true;
    }

    /**
     * Method upload_postmeta_image()
     *
     * Upload post meta image.
     *
     * @param array      $product_images      Woocomerce product images.
     * @param array      $meta_value          Meta values.
     * @param bool       $check_image_existed Determins if the images already exists.
     * @param array|null $v2_failed_stages   Optional v2 failure-stage collector.
     *
     * @return array|bool Error message array or TRUE on success.
     *
     * @uses \MainWP\Child\MainWP_Utility::upload_image()
     */
    private function upload_postmeta_image( $product_images, &$meta_value, $check_image_existed, &$v2_failed_stages = null ) {
        $product_image_gallery = array();
        foreach ( $product_images as $product_image ) {
            try {
                $upload_featured_image = MainWP_Utility::upload_image( $product_image, array(), $check_image_existed );

                if ( null !== $upload_featured_image ) {
                    $product_image_gallery[] = $upload_featured_image['id'];
                } else {
                    if ( is_array( $v2_failed_stages ) ) {
                        $v2_failed_stages[] = 'content_media';
                        continue;
                    }
                    return array( 'error' => esc_html__( 'Cannot add product image', 'mainwp-child' ) );
                }
            } catch ( MainWP_Exception $e ) {
                if ( is_array( $v2_failed_stages ) ) {
                    $v2_failed_stages[] = 'content_media';
                }
                continue;
            }
        }
        $meta_value = implode( ',', $product_image_gallery );
        return true;
    }

    /**
     * Get recent custom posts.
     *
     * @param array $ids Post ids.
     * @param  mixed $limit Limit number of posts.
     * @return array $allPost Return array of recent posts.
     */
    public function get_recent_custom_posts( $ids, $limit = 0 ) {

        if ( empty( $ids ) || ! is_array( $ids ) ) {
            return array();
        }

        $allPosts = array();

        $extra = array(
            'ids' => $ids,
        );

        MainWP_Child_Posts::get_instance()->get_recent_posts_int( 'any', $limit, 'any', $allPosts, $extra );
        return $allPosts;
    }


    /**
     * Method get_recent_custom_posts_cached
     *
     * @return array Cached recent posts IDs.
     */
    public function get_recent_custom_posts_cached() {

        $limit = get_option( 'mainwp_child_recent_custom_cpt_ids_limit', 20 );

        $cache_key = sprintf( 'mainwp_child_recent_custom_cpt_ids_%d', $limit );
        $group     = 'custom_posts';

        $ids = wp_cache_get( $cache_key, $group );

        if ( false !== $ids ) {
            return $ids;
        }

        $ids = $this->get_custom_posts( $limit );

        wp_cache_set( $cache_key, $ids, $group, 120 );

        return $ids;
    }

    /**
     * Method get_custom_posts
     *
     * @param  mixed $limit Limit get.
     * @return array
     */
    public function get_custom_posts( $limit = 20 ) {

        $post_types = get_post_types(
            array(
                '_builtin' => false,
                'public'   => true,
            )
        );

        if ( empty( $post_types ) ) {
            return array();
        }

        return get_posts(
            array(
                'post_type'      => $post_types,
                'post_status'    => array(
                    'publish',
                    'draft',
                    'pending',
                    'trash',
                    'future',
                ),
                'posts_per_page' => $limit,
                'fields'         => 'ids',
                'orderby'        => 'date',
                'order'          => 'DESC',
                'no_found_rows'  => true,
            )
        );
    }

    /**
     * Method invalidate_recent_custom_posts_cache_safe.
     *
     * @param  mixed $post_id Post ID.
     * @return void
     */
    public function invalidate_recent_custom_posts_cache_safe( $post_id ) {

        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }

        $limit = get_option( 'mainwp_child_recent_custom_cpt_ids_limit', 20 );

        $cache_key = sprintf( 'mainwp_child_recent_custom_cpt_ids_%d', $limit );
        $group     = 'custom_posts';

        if ( get_post_type( $post_id ) && post_type_supports( get_post_type( $post_id ), 'editor' ) ) {
            wp_cache_delete( $cache_key, $group );
        }
    }
}
