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
    }

    /**
     * Sync other data from $data[] and merge with $information[]
     *
     * @param array $information Returned response array for MainWP BackWPup Extension actions.
     * @param array $data Other data to sync to $information array.
     *
     * @return array $information Returned information array with both sets of data.
     */
    public function hook_sync_others_data( $information, $data = array() ) {
        if ( isset( $data['sync_cpt_data'] ) && $data['sync_cpt_data'] ) {
            try {
                $information['sync_cpt_data'] = array(
                    'custom_taxonomies' => $this->get_custom_taxonomies(),
                );
            } catch ( MainWP_Exception $e ) {
                // ok!
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
     * Method mainwp_custom_post_type_handle_fatal_error()
     *
     * Custom post type fatal error handler.
     */
    public static function mainwp_custom_post_type_handle_fatal_error() {
        $error = error_get_last();
        if ( isset( $error['type'] ) && E_ERROR === $error['type'] && isset( $error['message'] ) ) {
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
            return array( 'error' => esc_html__( 'Missing data', $this->plugin_translate ) );
        }
        $data = array();
        if ( is_string( $_POST['data'] ) ) { // to compatible.
            $data = stripslashes( wp_unslash( $_POST['data'] ) );
            $data = json_decode( $data, true );
        } elseif ( is_array( $_POST['data'] ) ) {
            $data = wp_unslash( $_POST['data'] );
        }

        if ( empty( $data ) || ! is_array( $data ) || ! isset( $data['post'] ) ) {
            return array( 'error' => esc_html__( 'Cannot decode data', $this->plugin_translate ) );
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
                return array( 'error' => _( 'Missing', $this->plugin_translate ) . ' ' . $key . ' ' . esc_html__( 'inside post data', $this->plugin_translate ) );
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
            return array( 'error' => esc_html__( 'Please install', $this->plugin_translate ) . ' ' . $data_insert['post_type'] . ' ' . esc_html__( 'on child and try again', $this->plugin_translate ) );
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
                    'error'             => esc_html__( 'Cannot get old post. Probably is deleted now. Please try again for create new post', $this->plugin_translate ),
                );
            }

            if ( get_post_status( $old_post_id ) === 'trash' ) {
                return array( 'error' => esc_html__( 'This post is inside trash on child website. Please try publish it manually and try again.', $this->plugin_translate ) );
            }
            $check_image_existed = true;
            $data_insert['ID']   = $old_post_id;

            // Remove all previous post meta.
            // Get all unique meta_key.
            foreach ( get_post_meta( $old_post_id ) as $temp_meta_key => $temp_meta_val ) {
                if ( ! delete_post_meta( $old_post_id, $temp_meta_key ) ) {
                    return array( 'error' => esc_html__( 'Cannot delete old post meta values', $this->plugin_translate ) );
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
            return array( 'error' => esc_html__( 'Error when insert new post:', $this->plugin_translate ) . ' ' . $post_id->get_error_message() );
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
                    return array( 'error' => esc_html__( 'Missing taxonomy', $this->plugin_translate ) . ' `' . esc_html( $key['taxonomy'] ) . '`' );
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
                        return array( 'error' => esc_html__( 'Error when adding taxonomy to post', $this->plugin_translate ) );
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
     * @param int    $post_id Post ID to update.
     * @param string $data Meta datat add.
     * @param bool   $check_image_existed Whether or not to check if image exists. true|false.
     * @param bool   $is_woocomerce Whether or not the post is a woocommerce product. true|false.
     *
     * @return array|bool|string[] Response array, true|false, Error message.
     *
     * @uses \MainWP\Child\MainWP_Utility::upload_image()\
     */
    private function insert_postmeta( $post_id, $data, $check_image_existed, $is_woocomerce ) { //phpcs:ignore -- NOSONAR - complex.
        foreach ( $data['postmeta'] as $key ) {
            if ( isset( $key['meta_key'] ) && isset( $key['meta_value'] ) ) {
                $meta_value = $key['meta_value'];
                if ( $is_woocomerce ) {
                    if ( '_sku' === $key['meta_key'] && ! wc_product_has_unique_sku( $post_id, $meta_value ) ) {
                        return array( 'error' => esc_html__( 'Product SKU must be unique', $this->plugin_translate ) );
                    }
                    if ( '_product_image_gallery' === $key['meta_key'] ) {
                        if ( isset( $data['extras']['woocommerce']['product_images'] ) ) {
                            $ret = $this->upload_postmeta_image( $data['extras']['woocommerce']['product_images'], $meta_value, $check_image_existed );
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
                                return array( 'error' => esc_html__( 'Cannot add featured image', $this->plugin_translate ) );
                            }
                        } catch ( MainWP_Exception $e ) {
                            continue;
                        }
                    } else {
                        continue;
                    }
                }

                $meta_value = maybe_unserialize( $meta_value ); // NOSONARR .
                if ( add_post_meta( $post_id, $key['meta_key'], $meta_value ) === false ) {
                    return array( 'error' => esc_html__( 'Error when adding post meta', $this->plugin_translate ) . ' `' . esc_html( $key['meta_key'] ) . '`' );
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
     * @param array $product_images      Woocomerce product images.
     * @param array $meta_value          Meta values.
     * @param bool  $check_image_existed Determins if the images already exists.
     *
     * @return array|bool Error message array or TRUE on success.
     *
     * @uses \MainWP\Child\MainWP_Utility::upload_image()
     */
    private function upload_postmeta_image( $product_images, &$meta_value, $check_image_existed ) {
        $product_image_gallery = array();
        foreach ( $product_images as $product_image ) {
            try {
                $upload_featured_image = MainWP_Utility::upload_image( $product_image, array(), $check_image_existed );

                if ( null !== $upload_featured_image ) {
                    $product_image_gallery[] = $upload_featured_image['id'];
                } else {
                    return array( 'error' => esc_html__( 'Cannot add product image', $this->plugin_translate ) );
                }
            } catch ( MainWP_Exception $e ) {
                continue;
            }
        }
        $meta_value = implode( $product_image_gallery, ',' );
        return true;
    }
}
