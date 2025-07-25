<?php
/**
 * Logger: Content
 *
 * Content logger class file.
 *
 * @since     5.5
 * @package   mainwp/child
 */

namespace MainWP\Child\Changes;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * WordPress contents (posts, pages and custom posts).
 */
class Changes_Handle_WP_Content {

    /**
     * The name of the meta used to setting the lock status of the post
     */
    const LOCK_META_NAME = '_edit_lock';

    /**
     * Old post.
     *
     * @var stdClass
     */
    private static $old_post = null;

    /**
     * Old permalink.
     *
     * @var string
     */
    private static $old_link = null;

    /**
     * Old categories.
     *
     * @var array
     */
    private static $old_cats = null;

    /**
     * Old tags.
     *
     * @var array
     */
    private static $old_tags = null;

    /**
     * Old path to file.
     *
     * @var string
     */
    private static $old_tmpl = null;

    /**
     * Old post is marked as sticky.
     *
     * @var boolean
     */
    private static $old_sticky = null;

    /**
     * Old Post Status.
     *
     * @var string
     */
    private static $old_status = null;

    /**
     * Old Post Meta.
     *
     * @var string
     */
    private static $old_meta = null;

    /**
     * Inits the main hooks
     *
     * @return void
     */
    public static function init_hooks() {
        \add_filter( 'add_post_metadata', array( __CLASS__, 'callback_change_check_added_meta' ), 10, 5 );
        \add_action( 'admin_action_edit', array( __CLASS__, 'callback_change_edit_post_in_gutenberg' ), 10 );
        \add_action( 'create_category', array( __CLASS__, 'callback_change_category_creation' ), 10, 1 );
        \add_filter( 'delete_post_metadata', array( __CLASS__, 'callback_change_check_deleted_meta' ), 10, 5 );
        \add_action( 'delete_post', array( __CLASS__, 'callback_change_post_deleted' ), 10, 1 );
        \add_filter( 'post_edit_form_tag', array( __CLASS__, 'callback_change_edit_post_in_classic' ), 10, 1 );
        \add_action( 'future_to_publish', array( __CLASS__, 'callback_change_publish_future' ), 10, 1 );
        \add_action( 'post_stuck', array( __CLASS__, 'callback_change_post_stuck_event' ), 10, 1 );
        \add_action( 'post_unstuck', array( __CLASS__, 'callback_change_post_unstuck_event' ), 10, 1 );
        \add_action( 'pre_delete_term', array( __CLASS__, 'callback_change_check_taxonomy_term_deletion' ), 10, 2 );
        \add_action( 'pre_post_update', array( __CLASS__, 'change_before_post_edit_data' ), 10, 2 );
        \add_action( 'save_post', array( __CLASS__, 'callback_change_post_changed' ), 10, 3 );
        \add_action( 'set_object_terms', array( __CLASS__, 'callback_change_post_terms_changed' ), 10, 4 );
        \add_action( 'untrash_post', array( __CLASS__, 'callback_change_post_untrashed' ) );
        \add_filter( 'wp_update_term_data', array( __CLASS__, 'callback_change_update_term_data' ), 10, 4 );
        \add_action( 'update_post_meta', array( __CLASS__, 'callback_change_before_changing_meta' ), 10, 4 );
        \add_action( 'updated_post_meta', array( __CLASS__, 'callback_change_check_changed_meta' ), 10, 4 );
        \add_action( 'wp_head', array( __CLASS__, 'callback_change_viewing_post' ), 10 );
        \add_action( 'wp_trash_post', array( __CLASS__, 'callback_change_post_trashed' ), 10, 1 );
    }

    /**
     * Get Post Data.
     *
     * @param int $post_id - Post ID.
     */
    public static function change_before_post_edit_data( $post_id ) {
        $post_id = (int) $post_id;
        $post    = \get_post( $post_id );

        if ( ! empty( $post ) && $post instanceof \WP_Post ) {
            self::$old_post   = $post;
            self::$old_link   = get_permalink( $post_id );
            self::$old_tmpl   = self::change_get_post_template( self::$old_post );
            self::$old_cats   = self::change_get_post_categories( self::$old_post );
            self::$old_tags   = self::change_get_post_tags( self::$old_post );
            self::$old_sticky = in_array( $post_id, get_option( 'sticky_posts' ), true );
            self::$old_status = $post->post_status;
            self::$old_meta   = get_post_meta( $post_id );
        }
    }

    /**
     * Check all the post changes.
     *
     * @param integer $post_id - Post ID.
     * @param WP_Post $post    - WP Post object.
     * @param boolean $update  - True if post update, false if post is new.
     */
    public static function callback_change_post_changed( $post_id, $post, $update ) {
        if ( empty( $post->post_type ) || 'revision' === $post->post_type || 'trash' === $post->post_status ) {
            return;
        }

        if ( null === self::$old_post ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            if ( self::$old_post && 'auto-draft' === self::$old_post->post_status && 'draft' === $post->post_status ) {
                static::change_post_creation( self::$old_post, $post );
            }
            return;
        }

        if ( Changes_Logs_Logger::is_disabled_post_type( $post->post_type ) ) {
            return;
        }

        /**
         * Post Changed.
         *
         * Only pass these requests:
         */
        if ( ! defined( 'REST_REQUEST' ) && ! defined( 'DOING_AJAX' ) && ! isset( $_REQUEST['classic-editor'] ) ) {
            // Either Gutenberg's second post request or classic editor's request.
            $editor_replace = get_option( 'classic-editor-replace', 'classic' );
            $allow_users    = get_option( 'classic-editor-allow-users', 'disallow' );

            if ( 'block' === $editor_replace && 'disallow' === $allow_users ) {
                return;
            }

            if ( 'allow' === $allow_users ) {
                return;
            }
        }

        if ( $update ) {
            $status_event = self::change_post_status( self::$old_post, $post );

            if ( 1205 !== $status_event && 'auto-draft' !== self::$old_post->post_status ) {
                $changes = 0;
                $changes = static::change_post_author( self::$old_post, $post )
                + static::change_post_parent( self::$old_post, $post )
                + static::change_post_visibility( self::$old_post, $post, self::$old_status, $post->post_status )
                + static::change_post_date( self::$old_post, $post )
                + static::change_post_permalink( self::$old_link, get_permalink( $post->ID ), $post )
                + static::change_comments_pings( self::$old_post, $post );

                $changes = $status_event ? true : $changes;
                if ( '1' === $changes ) {
                    remove_action( 'save_post', array( __CLASS__, 'callback_change_post_changed' ), 10, 3 );
                }
                static::change_post_modification( $post->ID, self::$old_post, $post, $changes );
            }
        } else {
            static::change_post_creation( self::$old_post, $post );
        }
    }

    /**
     * Check if post terms changed via Gutenberg.
     *
     * @param int    $post_id  - Post ID.
     * @param array  $terms    - Array of terms.
     * @param array  $tt_ids   - Array of taxonomy term ids.
     * @param string $taxonomy - Taxonomy slug.
     */
    public static function callback_change_post_terms_changed( $post_id, $terms, $tt_ids, $taxonomy ) {
        $post = get_post( $post_id );

        if ( is_wp_error( $post ) ) {
            return;
        }

        if ( null === $post ) {
            return;
        }

        if ( 'auto-draft' === $post->post_status ) {
            return;
        }

        // Support for Admin Columns Pro plugin and its add-on.
        if ( isset( $_POST['_ajax_nonce'] ) && ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_ajax_nonce'] ) ), 'ac-ajax' ) ) {
            return;
        }

        if ( isset( $_POST['action'] ) && 'acp_editing_single_request' === sanitize_text_field( wp_unslash( $_POST['action'] ) ) ) {
            return;
        }

        if ( 'post_tag' === $taxonomy ) {
            self::change_check_post_tags( self::$old_tags, self::change_get_post_tags( $post ), $post );
        } else {
            self::change_post_categories( self::$old_cats, self::change_get_post_categories( $post ), $post );
        }
    }

    /**
     * Post Stuck Event.
     *
     * @param integer $post_id - Post ID.
     */
    public static function callback_change_post_stuck_event( $post_id ) {
        self::change_post_sticky( $post_id, 1265 );
    }

    /**
     * Post Unstuck Event.
     *
     * @param integer $post_id - Post ID.
     */
    public static function callback_change_post_unstuck_event( $post_id ) {
        self::change_post_sticky( $post_id, 1270 );
    }

    /**
     * Post permanently deleted.
     *
     * @param integer $post_id - Post ID.
     */
    public static function callback_change_post_deleted( $post_id ) {
        $post    = get_post( $post_id );
        $type_id = 1215;

        if ( 'auto-draft' === $post->post_title || 'Auto Draft' === $post->post_title ) {
            return;
        }

        $log_data       = static::change_post_changes_data( $post );
        $request_params = Changes_Helper::get_filtered_request_data();
        if ( empty( $request_params['action'] ) && isset( $request_params['page'] ) ) {
            $type_id  = 1220;
            $log_data = array(
                'postid'     => $post->ID,
                'posttype'   => $post->post_type,
                'posttitle'  => $post->post_title,
                'poststatus' => $post->post_status,
                'username'   => 'Plugins',
            );
        }
        Changes_Logs_Logger::log_change( $type_id, $log_data );
    }

    /**
     * Post moved to the trash.
     *
     * @param integer $post_id - Post ID.
     */
    public static function callback_change_post_trashed( $post_id ) {
        $post     = get_post( $post_id );
        $log_data = array(
            'postid'     => $post->ID,
            'posttype'   => $post->post_type,
            'posttitle'  => $post->post_title,
            'poststatus' => $post->post_status,
            'postdate'   => $post->post_date,
            'posturl'    => get_permalink( $post->ID ),
        );
        Changes_Logs_Logger::log_change( 1225, $log_data );
    }

    /**
     * Post restored from trash.
     *
     * @param integer $post_id - Post ID.
     */
    public static function callback_change_post_untrashed( $post_id ) {
        $post = get_post( $post_id );

        $log_data = array(
            'postid'     => $post->ID,
            'posttype'   => $post->post_type,
            'posttitle'  => $post->post_title,
            'poststatus' => $post->post_status,
            'postdate'   => $post->post_date,
            'posturl'    => get_permalink( $post->ID ),
        );

        Changes_Logs_Logger::log_change( 1230, $log_data );
        remove_action( 'save_post', array( __CLASS__, 'callback_change_post_changed' ), 10, 3 );
    }

    /**
     * Post future publishing.
     *
     * @param integer $post_id - Post ID.
     */
    public static function callback_change_publish_future( $post_id ) {
        $post     = get_post( $post_id );
        $log_data = array(
            'postid'     => $post->ID,
            'posttype'   => $post->post_type,
            'posttitle'  => $post->post_title,
            'poststatus' => $post->post_status,
            'postdate'   => $post->post_date,
            'posturl'    => get_permalink( $post->ID ),
        );
        Changes_Logs_Logger::log_change( 1205, $log_data );
        remove_action( 'save_post', array( __CLASS__, 'callback_change_post_changed' ), 10, 3 );
    }

    /**
     * Log for Editing of Posts and Custom Post Types in Gutenberg.
     */
    public static function callback_change_edit_post_in_gutenberg() {
        global $pagenow;

        if ( 'post.php' !== $pagenow ) {
            return;
        }

        $post_id = isset( $_GET['post'] ) ? (int) sanitize_text_field( wp_unslash( $_GET['post'] ) ) : false;

        if ( empty( $post_id ) ) {
            return;
        }

        if ( is_user_logged_in() && is_admin() ) {
            $post = get_post( $post_id );
            self::change_opened_post_editor( $post );
        }
    }

    /**
     * Logs for Editing of Posts, Pages and Custom Post Types.
     *
     * @param WP_Post $post - Post.
     */
    public static function callback_change_edit_post_in_classic( $post ) {
        if ( is_user_logged_in() && is_admin() ) {
            // Log event.
            self::change_opened_post_editor( $post );
        }
        return $post;
    }

    /**
     * Post View Event.
     *
     * Logs for Viewing of Posts and Custom Post Types.
     */
    public static function callback_change_viewing_post() {
        $post = get_queried_object();

        if ( is_user_logged_in() && ! is_admin() ) {
            $current_path = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : false;

            if (
            ! empty( $_SERVER['HTTP_REFERER'] )
            && ! empty( $current_path )
            && false !== strpos( sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) ), $current_path )
            ) {
                return;
            }

            if ( ! empty( $post->post_title ) ) {
                $post_data = self::change_post_changes_data( $post );

                if ( Changes_Helper::is_multisite() && ! is_subdomain_install() ) {
                    $subdir_path = parse_url( home_url(), PHP_URL_PATH ); // phpcs:ignore
                    if ( ! is_null( $subdir_path ) ) {
                        $escaped      = str_replace( '/', '\/', preg_quote( $subdir_path ) ); // phpcs:ignore
                        $current_path = preg_replace( '/' . $escaped . '/', '', $current_path );
                    }
                }

                if ( ! isset( $post_data['posturl'] ) ) {
                    return;
                }
                $full_current_path = home_url( $current_path );
                if ( $full_current_path !== $post_data['posturl'] ) {
                    $post_data['posturl'] = esc_url( $full_current_path );
                }
                Changes_Logs_Logger::log_change( 1315, $post_data );
            }
        }
    }

    /**
     * New category created.
     *
     * @param integer $category_id - Category ID.
     */
    public static function callback_change_category_creation( $category_id ) {
        $category = get_category( $category_id );
        $log_data = array(
            'categoryname' => $category->name,
            'slug'         => $category->slug,
        );
        Changes_Logs_Logger::log_change( 1370, $log_data );
    }


    /**
     * Taxonomy Terms Deleted Events.
     *
     * @param integer $term_id  - Term ID.
     * @param string  $taxonomy - Taxonomy Name.
     */
    public static function callback_change_check_taxonomy_term_deletion( $term_id, $taxonomy ) {
        if ( 'category' === $taxonomy ) {
            $category = get_category( $term_id );
            $log_data = array(
                'categoryid'   => $term_id,
                'categoryname' => $category->cat_name,
                'slug'         => $category->slug,
            );
            Changes_Logs_Logger::log_change( 1375, $log_data );
        }
    }

    /**
     * When term data is updated.
     *
     * @param array  $data     Term data to be updated.
     * @param int    $term_id  Term ID.
     * @param string $taxonomy Taxonomy slug.
     * @param array  $args     Arguments passed to wp_update_term().
     */
    public static function callback_change_update_term_data( $data, $term_id, $taxonomy, $args ) {
        $new_name   = isset( $data['name'] ) ? $data['name'] : false;
        $new_slug   = isset( $data['slug'] ) ? $data['slug'] : false;
        $new_desc   = isset( $args['description'] ) ? $args['description'] : false;
        $new_parent = isset( $args['parent'] ) ? $args['parent'] : false;

        $term       = get_term( $term_id, $taxonomy );
        $old_name   = $term->name;
        $old_slug   = $term->slug;
        $old_desc   = $term->description;
        $old_parent = $term->parent;

        if ( 'post_tag' === $taxonomy ) {
            if ( $old_name !== $new_name ) {
                $log_data = array(
                    'old_name' => $old_name,
                    'new_name' => $new_name,
                    'slug'     => $new_slug,
                );
                Changes_Logs_Logger::log_change( 1405, $log_data );
            }

            if ( $old_slug !== $new_slug ) {
                $log_data = array(
                    'tag'      => $new_name,
                    'old_slug' => $old_slug,
                    'new_slug' => $new_slug,
                );
                Changes_Logs_Logger::log_change( 1410, $log_data );
            }

            if ( $old_desc !== $new_desc ) {
                $log_data = array(
                    'tag'      => $new_name,
                    'old_desc' => $old_desc,
                    'new_desc' => $new_desc,
                );
                Changes_Logs_Logger::log_change( 1415, $log_data );
            }
        } elseif ( 'category' === $taxonomy ) { // The taxonomy is `category`.
            if ( $old_name !== $new_name ) {
                $log_data = array(
                    'old_name' => $old_name,
                    'new_name' => $new_name,
                    'slug'     => $new_slug,
                );
                Changes_Logs_Logger::log_change( 1385, $log_data );
            }

            if ( $old_slug !== $new_slug ) {
                $log_data = array(
                    'categoryname' => $new_name,
                    'old_slug'     => $old_slug,
                    'new_slug'     => $new_slug,
                );
                Changes_Logs_Logger::log_change( 1390, $log_data );
            }

            if ( 0 !== $old_parent ) {
                $old_parent_obj  = get_category( $old_parent );
                $old_parent_name = empty( $old_parent_obj ) ? 'no parent' : $old_parent_obj->name;
            } else {
                $old_parent_name = 'no parent';
            }
            if ( 0 !== $new_parent ) {
                $new_parent_obj  = get_category( $new_parent );
                $new_parent_name = empty( $new_parent_obj ) ? 'no parent' : $new_parent_obj->name;
            } else {
                $new_parent_name = 'no parent';
            }

            if ( $old_parent_name !== $new_parent_name ) {
                $log_data = array(
                    'categoryname' => $new_name,
                    'oldparent'    => $old_parent_name,
                    'newparent'    => $new_parent_name,
                    'slug'         => $new_slug,
                );
                Changes_Logs_Logger::log_change( 1380, $log_data );
            }
        }
        return $data;
    }

    /**
     * Checks if selected metadata items have changed.
     *
     * @param int    $post_id        Post ID.
     * @param string $meta_key       Meta key.
     * @param mixed  $meta_value     Meta value.
     * @param mixed  $default_result Default result. The name is misleading - it holds the meta ID value.
     *
     * @return int - The meta ID.
     */
    private static function check_selected_meta_change( $post_id, $meta_key, $meta_value, $default_result ) {
        if ( ! $post_id ) {
            return $default_result;
        }

        switch ( $meta_key ) {
            case '_wp_page_template':
                self::check_template_change( $post_id, $meta_value );
                break;
            case '_thumbnail_id':
                self::check_featured_image_change( $post_id, $meta_value );
                break;
            default:
                return $default_result;
        }

        return $default_result;
    }

    /**
     * Check Page Template Update.
     *
     * @param int    $meta_id    ID of updated metadata entry.
     * @param int    $post_id    Post ID.
     * @param string $meta_key   Meta key.
     * @param mixed  $meta_value Meta value.
     *
     * @return int ID of metadata entry.
     */
    public static function callback_change_check_changed_meta( $meta_id, $post_id, $meta_key, $meta_value ) {
        return self::check_selected_meta_change( $post_id, $meta_key, $meta_value, $meta_id );
    }

    /**
     * Check Page Template Update for deletions.
     *
     * @param null|bool $delete     Whether to allow metadata deletion of the given type.
     * @param int       $object_id  ID of the object metadata is for.
     * @param string    $meta_key   Metadata key.
     * @param mixed     $meta_value Metadata value. Must be serializable if non-scalar.
     * @param bool      $delete_all Whether to delete the matching metadata entries
     *                              for all objects, ignoring the specified $object_id.
     *                              Default false.
     *
     * @return bool|null Whether to allow metadata deletion.
     */
    public static function callback_change_check_deleted_meta( $delete, $object_id, $meta_key, $meta_value, $delete_all ) {
        return self::check_selected_meta_change( $object_id, $meta_key, $meta_value, $delete );
    }

    /**
     * Check Page Template Update.
     *
     * @param null|bool $check      Whether to allow adding metadata.
     * @param int       $object_id  ID of the object metadata.
     * @param string    $meta_key   Metadata key.
     * @param mixed     $meta_value Metadata value..
     * @param bool      $unique     Meta key should be unique for the object.
     *
     * @return bool|null    Whether to allow metadata addition.
     */
    public static function callback_change_check_added_meta( $check, $object_id, $meta_key, $meta_value, $unique ) {
        return self::check_selected_meta_change( $object_id, $meta_key, $meta_value, $check );
    }

    /**
     * Check Page Template Update.
     *
     * @param int   $post_id    Post ID.
     * @param mixed $meta_value Meta value.
     */
    public static function check_template_change( $post_id, $meta_value ) {
        $post     = get_post( $post_id );
        $old_tmpl = ( self::$old_tmpl && 'page' !== basename( self::$old_tmpl, '.php' ) ) ? ucwords( str_replace( array( '-', '_' ), ' ', basename( self::$old_tmpl, '.php' ) ) ) : __( 'Default template', 'mainwp-child' );
        $new_tmpl = ( $meta_value ) ? ucwords( str_replace( array( '-', '_' ), ' ', basename( $meta_value ) ) ) : __( 'Default', 'mainwp-child' );
        if ( $old_tmpl !== $new_tmpl ) {
            $log_data = array(
                'postid'      => $post->ID,
                'posttype'    => $post->post_type,
                'posttitle'   => $post->post_title,
                'poststatus'  => $post->post_status,
                'postdate'    => $post->post_date,
                'oldtemplate' => $old_tmpl,
                'newtemplate' => $new_tmpl,
            );
            Changes_Logs_Logger::log_change( 1260, $log_data );
        }
    }

    /**
     * Fires immediately before updating a post's metadata.
     *
     * @param int    $meta_id    ID of metadata to update.
     * @param int    $object_id  Post ID.
     * @param string $meta_key   Metadata key.
     * @param mixed  $meta_value Metadata value.
     */
    public static function callback_change_before_changing_meta( $meta_id, $object_id, $meta_key, $meta_value ) {
        if ( self::LOCK_META_NAME === $meta_key ) {
            self::fire_lock_change( (int) $object_id, (string) $meta_value );
        }
    }

    /**
     * Fires lock owner changing.
     *
     * @param integer $post_id   - The ID of the post.
     * @param string  $meta_value - The meta value.
     *
     * @return void
     */
    public static function fire_lock_change( int $post_id, string $meta_value ) {

        if ( $meta_value ) {
            if ( ! function_exists( 'wp_check_post_lock' ) ) {
                require_once ABSPATH . 'wp-admin/includes/post.php';
            }
            $user_id = \wp_check_post_lock( $post_id );

            if ( $user_id ) {
                $lock        = explode( ':', $meta_value );
                $new_user_id = $lock[1];

                if ( $new_user_id ) {
                    if ( get_userdata( $new_user_id ) ) { //phpcs:ignore
                        $post     = get_post( $post_id );
                        $log_data = array(
                            'postid'     => $post->ID,
                            'posttype'   => $post->post_type,
                            'posttitle'  => $post->post_title,
                            'poststatus' => $post->post_status,
                            'user'       => get_userdata( $user_id )->display_name,
                        );
                        Changes_Logs_Logger::log_change( 1340, $log_data );
                    }
                }
            }
        }
    }

    /**
     * Check Post Featured Image Update.
     *
     * @param int   $post_id    Post ID.
     * @param mixed $meta_value Meta value.
     */
    public static function check_featured_image_change( $post_id, $meta_value ) {
        $previous_featured_image = ( isset( self::$old_meta['_thumbnail_id'][0] ) ) ? wp_get_attachment_metadata( self::$old_meta['_thumbnail_id'][0] ) : false;
        $new_featured_image      = wp_get_attachment_metadata( $meta_value );

        if ( empty( $new_featured_image['file'] ) && empty( $previous_featured_image['file'] ) ) {
            return;
        }

        $action_name = 'updated ';

        if ( empty( $previous_featured_image['file'] ) && ! empty( $new_featured_image['file'] ) ) {
            $action_name = 'added';
        } elseif ( ! empty( $previous_featured_image['file'] ) && empty( $new_featured_image['file'] ) ) {
            $action_name = 'removed';
        }

        $previous_image = is_array( $previous_featured_image ) && array_key_exists( 'file', $previous_featured_image ) ? $previous_featured_image['file'] : __( 'No previous image', 'mainwp-child' );
        $new_image      = is_array( $new_featured_image ) && array_key_exists( 'file', $new_featured_image ) ? $new_featured_image['file'] : __( 'No image', 'mainwp-child' );

        $post     = get_post( $post_id );
        $log_data = array(
            'postid'         => $post->ID,
            'posttype'       => $post->post_type,
            'posttitle'      => $post->post_title,
            'poststatus'     => $post->post_status,
            'postdate'       => $post->post_date,
            'previous_image' => $previous_image,
            'new_image'      => $new_image,
            'actionname'     => $action_name,
        );
        Changes_Logs_Logger::log_change( 1335, $log_data );
    }

    /**
     * Get the template path.
     *
     * @param WP_Post $post - The post.
     * @return string - Full path to file.
     */
    private static function change_get_post_template( $post ) {
        if ( ! isset( $post->ID ) ) {
            return '';
        }

        $id       = $post->ID;
        $template = get_page_template_slug( $id );
        $pagename = $post->post_name;

        $templates = array();
        if ( $template && 0 === validate_file( $template ) ) {
            $templates[] = $template;
        }
        if ( $pagename ) {
            $templates[] = "page-$pagename.php";
        }
        if ( $id ) {
            $templates[] = "page-$id.php";
        }
        $templates[] = 'page.php';

        return get_query_template( 'page', $templates );
    }

    /**
     * Get post categories (array of category names).
     *
     * @param stdClass $post - The post.
     * @return array - List of categories.
     */
    private static function change_get_post_categories( $post ) {
        return ! isset( $post->ID ) ? array() : wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) );
    }

    /**
     * Get post tags (array of tag names).
     *
     * @param stdClass $post - The post.
     * @return array - List of tags.
     */
    private static function change_get_post_tags( $post ) {
        return ! isset( $post->ID ) ? array() : wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) );
    }

    /**
     * Check post creation.
     *
     * @global array $_POST
     *
     * @param WP_Post $old_post - Old post.
     * @param WP_Post $new_post - New post.
     */
    private static function change_post_creation( $old_post, $new_post ) {
        if ( ! empty( $new_post ) && $new_post instanceof \WP_Post && 'shop_coupon' !== $new_post->post_type ) {
            $event        = 0;
            $is_scheduled = false;
            switch ( $new_post->post_status ) {
                case 'publish':
                    $event = 1205;
                    break;
                case 'draft':
                    $event = 1200;
                    break;
                case 'future':
                    $event        = 1285;
                    $is_scheduled = true;
                    break;
                case 'pending':
                    $event = 1280;
                    break;
                default:
                    break;
            }
            if ( $event ) {
                $log_data = self::change_post_changes_data( $new_post );
                if ( $is_scheduled ) {
                    $log_data['publishingdate'] = $new_post->post_date;
                    Changes_Logs_Logger::log_change( $event, $log_data );
                } else {

                    $request_params = Changes_Helper::get_filtered_request_data();
                    if ( array_key_exists( 'plugin', $request_params ) && ! empty( $request_params['plugin'] ) ) {
                        $plugin_name = $request_params['plugin'];
                        if ( is_wp_error( validate_plugin( $plugin_name ) ) ) {
                            return;
                        }
                        $plugin_data = get_plugin_data( trailingslashit( WP_PLUGIN_DIR ) . $plugin_name );
                        $log_data    = array(
                            'pluginname' => ( $plugin_data && isset( $plugin_data['Name'] ) ) ? $plugin_data['Name'] : false,
                            'postid'     => $new_post->ID,
                            'posttype'   => $new_post->post_type,
                            'posttitle'  => $new_post->post_title,
                            'poststatus' => $new_post->post_status,
                            'username'   => 'Plugins',
                        );
                    }

                    Changes_Logs_Logger::log_change( $event, $log_data );
                }
            }
        }
    }

    /**
     * Return Post Event Data.
     *
     * @param WP_Post $post - WP Post object.
     * @return array
     */
    public static function change_post_changes_data( $post ) {
        if ( ! empty( $post ) && $post instanceof \WP_Post ) {
            $log_data = array(
                'postid'     => $post->ID,
                'posttype'   => $post->post_type,
                'posttitle'  => $post->post_title,
                'poststatus' => $post->post_status,
                'postdate'   => $post->post_date,
                'posturl'    => get_permalink( $post->ID ),
            );
            return $log_data;
        }
        return array();
    }

    /**
     * Author changed.
     *
     * @param stdClass $oldpost - Old post.
     * @param stdClass $newpost - New post.
     */
    private static function change_post_author( $oldpost, $newpost ) {
        if ( $oldpost->post_author !== $newpost->post_author ) {
            $old_author = get_userdata( $oldpost->post_author );
            $old_author = ( is_object( $old_author ) ) ? $old_author->user_login : 'N/A';
            $new_author = get_userdata( $newpost->post_author );
            $new_author = ( is_object( $new_author ) ) ? $new_author->user_login : 'N/A';
            $log_data   = array(
                'postid'     => $oldpost->ID,
                'posttype'   => $oldpost->post_type,
                'posttitle'  => $oldpost->post_title,
                'poststatus' => $oldpost->post_status,
                'postdate'   => $oldpost->post_date,
                'posturl'    => get_permalink( $oldpost->ID ),
                'oldauthor'  => $old_author,
                'newauthor'  => $new_author,
            );
            Changes_Logs_Logger::log_change( 1245, $log_data );
            return 1;
        }
    }

    /**
     * Status changed.
     *
     * @param stdClass $oldpost - Old post.
     * @param stdClass $newpost - New post.
     *
     * @return integer
     */
    private static function change_post_status( $oldpost, $newpost ) {
        if ( $oldpost->post_status !== $newpost->post_status && 'shop_coupon' !== $newpost->post_type ) {
            $event        = 0;
            $is_scheduled = false;

            if ( 'auto-draft' === $oldpost->post_status && 'draft' === $newpost->post_status ) {
                $event = 1200;
            } elseif ( 'publish' === $newpost->post_status ) {
                $event = 1205;
            } elseif ( 'pending' === $newpost->post_status ) {
                $event = 1280;
            } elseif ( 'future' === $newpost->post_status ) {
                $event        = 1285;
                $is_scheduled = true;
            } else {
                $event = 1250;
            }

            if ( $event ) {
                $log_data = self::change_post_changes_data( $newpost );
                if ( $is_scheduled ) {
                    $log_data['publishingdate'] = $newpost->post_date;
                    Changes_Logs_Logger::log_change( $event, $log_data );
                } elseif ( 1250 === $event ) {
                    $log_data['oldstatus'] = $oldpost->post_status;
                    $log_data['newstatus'] = $newpost->post_status;
                    Changes_Logs_Logger::log_change( $event, $log_data );
                } else {
                    Changes_Logs_Logger::log_change_save_delay( $event, $log_data );
                }
            }

            return $event;
        }
    }

    /**
     * Post parent changed.
     *
     * @param stdClass $oldpost - Old post.
     * @param stdClass $newpost - New post.
     */
    private static function change_post_parent( $oldpost, $newpost ) {
        if ( $oldpost->post_parent !== $newpost->post_parent && 'page' === $newpost->post_type ) {
            $log_data = array(
                'postid'        => $oldpost->ID,
                'posttype'      => $oldpost->post_type,
                'posttitle'     => $oldpost->post_title,
                'poststatus'    => $oldpost->post_status,
                'postdate'      => $oldpost->post_date,
                'oldparent'     => $oldpost->post_parent,
                'newparent'     => $newpost->post_parent,
                'oldparentname' => $oldpost->post_parent ? get_the_title( $oldpost->post_parent ) : 'no parent',
                'newparentname' => $newpost->post_parent ? get_the_title( $newpost->post_parent ) : 'no parent',
            );
            Changes_Logs_Logger::log_change( 1255, $log_data );
            return 1;
        }
    }

    /**
     * Permalink changed.
     *
     * @param string   $old_link - Old permalink.
     * @param string   $new_link - New permalink.
     *
     * @param stdClass $post - The post.
     */
    private static function change_post_permalink( $old_link, $new_link, $post ) {
        if ( in_array( $post->post_status, array( 'draft', 'pending' ), true ) ) {
            $old_link = self::$old_post->post_name;
            $new_link = $post->post_name;
        }

        if ( $old_link !== $new_link ) {
            $log_data = array(
                'postid'     => $post->ID,
                'posttype'   => $post->post_type,
                'posttitle'  => $post->post_title,
                'poststatus' => $post->post_status,
                'postdate'   => $post->post_date,
                'oldurl'     => $old_link,
                'newurl'     => $new_link,
            );
            Changes_Logs_Logger::log_change( 1240, $log_data );
            return 1;
        }
        return 0;
    }

    /**
     * Post visibility changed.
     *
     * @param WP_Post $oldpost - Old post.
     * @param WP_Post $newpost - New post.
     * @param string  $old_status - Old status.
     * @param string  $new_status - New status.
     */
    private static function change_post_visibility( $oldpost, $newpost, $old_status, $new_status ) {
        $old_visibility = '';
        $new_visibility = '';

        if ( $oldpost->post_password ) {
            $old_visibility = esc_html__( 'Password Protected', 'mainwp-child' );
        } elseif ( 'private' === $oldpost->post_status ) {
            $old_visibility = esc_html__( 'Private', 'mainwp-child' );
        } else {
            $old_visibility = esc_html__( 'Public', 'mainwp-child' );
        }

        if ( $newpost->post_password ) {
            $new_visibility = esc_html__( 'Password Protected', 'mainwp-child' );
        } elseif ( 'private' === $newpost->post_status ) {
            $new_visibility = esc_html__( 'Private', 'mainwp-child' );
        } else {
            $new_visibility = esc_html__( 'Public', 'mainwp-child' );
        }

        if ( $old_visibility && $new_visibility && ( $old_visibility !== $new_visibility ) ) {
            $log_data = array(
                'postid'        => $oldpost->ID,
                'posttype'      => $oldpost->post_type,
                'posttitle'     => $oldpost->post_title,
                'poststatus'    => $newpost->post_status,
                'postdate'      => $oldpost->post_date,
                'posturl'       => get_permalink( $oldpost->ID ),
                'oldvisibility' => $old_visibility,
                'newvisibility' => $new_visibility,
            );
            Changes_Logs_Logger::log_change( 1290, $log_data );
            return 1;
        }
    }

    /**
     * Post date changed.
     *
     * @param \WP_Post $oldpost - Old post.
     * @param \WP_Post $newpost - New post.
     */
    private static function change_post_date( $oldpost, $newpost ) {
        $from = 0;
        $to   = 0;
        if ( isset( $oldpost->post_date ) && null !== $oldpost->post_date ) {
            $from = strtotime( $oldpost->post_date );
        }
        if ( isset( $newpost->post_date ) && null !== $newpost->post_date ) {
            $to = strtotime( $newpost->post_date );
        }

        if ( 'pending' === $oldpost->post_status ) {
            return 0;
        }

        if ( self::is_post_draft_resave( $oldpost, $newpost ) ) {
            return 0;
        }

        if ( $from !== $to ) {
            $log_data = array(
                'postid'     => $oldpost->ID,
                'posttype'   => $oldpost->post_type,
                'posttitle'  => $oldpost->post_title,
                'poststatus' => $oldpost->post_status,
                'postdate'   => $newpost->post_date,
                'posturl'    => get_permalink( $oldpost->ID ),
                'olddate'    => $oldpost->post_date,
                'newdate'    => $newpost->post_date,
            );
            Changes_Logs_Logger::log_change( 1295, $log_data );
            return 1;
        }
        return 0;
    }

    /**
     * Comments/Trackbacks and Pingbacks check.
     *
     * @param stdClass $oldpost - Old post.
     * @param stdClass $newpost - New post.
     */
    private static function change_comments_pings( $oldpost, $newpost ) {
        $result = 0;
        // Comments.
        if ( $oldpost->comment_status !== $newpost->comment_status ) {
            $type_id = 1320;
            if ( 'open' !== $newpost->comment_status ) {
                $type_id = 1321;
            }
            $log_data = array(
                'postid'     => $newpost->ID,
                'posttype'   => $newpost->post_type,
                'poststatus' => $newpost->post_status,
                'postdate'   => $newpost->post_date,
                'posttitle'  => $newpost->post_title,
                'posturl'    => get_permalink( $newpost->ID ),
            );
            Changes_Logs_Logger::log_change( $type_id, $log_data );
            $result = 1;
        }

        // Trackbacks and Pingbacks.
        if ( $oldpost->ping_status !== $newpost->ping_status ) {
            $type_id = 1325;
            if ( 'open' !== $newpost->ping_status ) {
                $type_id = 1326;
            }
            $log_data = array(
                'postid'     => $newpost->ID,
                'posttype'   => $newpost->post_type,
                'posttitle'  => $newpost->post_title,
                'poststatus' => $newpost->post_status,
                'postdate'   => $newpost->post_date,
                'posturl'    => get_permalink( $newpost->ID ),
            );
            Changes_Logs_Logger::log_change( $type_id, $log_data );
            $result = 1;
        }
        return $result;
    }

    /**
     * Categories changed.
     *
     * @param array   $old_cats - Old categories.
     * @param array   $new_cats - New categories.
     * @param WP_Post $post     - The post.
     */
    private static function change_post_categories( $old_cats, $new_cats, $post ) {
        $old_cats = implode( ', ', (array) $old_cats );
        $new_cats = implode( ', ', (array) $new_cats );

        if ( $old_cats !== $new_cats && 'page' !== $post->post_type ) {
            $log_data = array(
                'postid'        => $post->ID,
                'posttype'      => $post->post_type,
                'posttitle'     => $post->post_title,
                'poststatus'    => $post->post_status,
                'postdate'      => $post->post_date,
                'posturl'       => get_permalink( $post->ID ),
                'oldcategories' => $old_cats ? $old_cats : 'no categories',
                'newcategories' => $new_cats ? $new_cats : 'no categories',
            );
            Changes_Logs_Logger::log_change( 1235, $log_data );
        }
    }

    /**
     * Reports tags change event. This could be tags addition, removal and possibly other in the future.
     *
     * @param int      $log_code   Event code.
     * @param WP_Post  $post         WordPress post object.
     * @param string[] $tags_changed Changed tags.
     */
    private static function change_post_tags( $log_code, $post, $tags_changed ) {
        $log_data = array(
            'postid'     => $post->ID,
            'posttype'   => $post->post_type,
            'poststatus' => $post->post_status,
            'posttitle'  => $post->post_title,
            'postdate'   => $post->post_date,
            'posturl'    => get_permalink( $post->ID ),
            'tag'        => ! empty( $tags_changed ) ? implode( ', ', $tags_changed ) : esc_html__( 'no tags', 'mainwp-child' ),
        );
        Changes_Logs_Logger::log_change( $log_code, $log_data );
    }

    /**
     * Tags changed.
     *
     * @param array   $old_tags - Old tags.
     * @param array   $new_tags - New tags.
     * @param WP_Post $post - The post.
     */
    private static function change_check_post_tags( $old_tags, $new_tags, $post ) {
        if ( ! $old_tags ) {
            $old_tags = array();
        }
        $intersection = array_intersect( $old_tags, $new_tags );
        if ( count( $intersection ) === count( $old_tags ) && count( $old_tags ) === count( $new_tags ) ) {
            return;
        }

        $added_tags = array_diff( (array) $new_tags, (array) $old_tags );
        if ( ! empty( $added_tags ) ) {
            self::change_post_tags( 1395, $post, $added_tags );
        }

        $removed_tags = array_diff( (array) $old_tags, (array) $new_tags );
        if ( ! empty( $removed_tags ) ) {
            self::change_post_tags( 1400, $post, $removed_tags );
        }
    }

    /**
     * Post modified content.
     *
     * @param integer  $post_id – Post ID.
     * @param stdClass $oldpost – Old post.
     * @param stdClass $newpost – New post.
     * @param int      $modified – Set to 0 if no changes done to the post.
     *
     * @return int|void
     */
    public static function change_post_modification( $post_id, $oldpost, $newpost, $modified ) {
        self::change_post_title( $oldpost, $newpost );

        $content_changed = $oldpost->post_content !== $newpost->post_content;

        if ( ! $content_changed && self::is_post_draft_resave( $oldpost, $newpost ) ) {
            return;
        }

        if ( $oldpost->post_modified !== $newpost->post_modified ) {
            $event = 0;

            if ( $content_changed ) {
                $event = 1300;
            } elseif ( ! $modified ) {
                $event = 1210;
            }

            if ( $event ) {
                $log_data = self::change_post_changes_data( $oldpost );

                $old_post_excerpt = $oldpost->post_excerpt;
                $post_excerpt     = get_post_field( 'post_excerpt', $post_id );

                if ( empty( $old_post_excerpt ) && ! empty( $post_excerpt ) ) {
                    $log_data['actionname'] = 'added';
                } elseif ( ! empty( $old_post_excerpt ) && empty( $post_excerpt ) ) {
                    $log_data['actionname'] = 'removed';
                } elseif ( $old_post_excerpt !== $post_excerpt ) {
                    $log_data['actionname'] = 'updated';
                }

                if ( $old_post_excerpt !== $post_excerpt ) {
                    $event                        = 1330;
                    $log_data['old_post_excerpt'] = ( $old_post_excerpt ) ? $old_post_excerpt : ' ';
                    $log_data['post_excerpt']     = ( $post_excerpt ) ? $post_excerpt : ' ';
                }

                if ( 1210 === $event ) {
                    Changes_Logs_Logger::log_change_save_delay( $event, $log_data );
                } else {
                    Changes_Logs_Logger::log_change( $event, $log_data );
                }
            }
        }
    }


    /**
     * Changed title of a post.
     *
     * @param stdClass $oldpost - Old post.
     * @param stdClass $newpost - New post.
     */
    private static function change_post_title( $oldpost, $newpost ) {
        if ( $oldpost->post_title !== $newpost->post_title ) {
            $log_data = array(
                'postid'     => $newpost->ID,
                'posttype'   => $newpost->post_type,
                'posttitle'  => $newpost->post_title,
                'poststatus' => $newpost->post_status,
                'postdate'   => $newpost->post_date,
                'posturl'    => get_permalink( $newpost->ID ),
                'oldtitle'   => $oldpost->post_title,
                'newtitle'   => $newpost->post_title,
            );
            Changes_Logs_Logger::log_change( 1305, $log_data );
            return 1;
        }
        return 0;
    }


    /**
     * Log post stuck/unstuck events.
     *
     * @param integer $post_id - Post ID.
     * @param integer $event   - Event ID.
     */
    private static function change_post_sticky( $post_id, $event ) {
        $post = get_post( $post_id );

        if ( is_wp_error( $post ) ) {
            return;
        }
        $log_data = self::change_post_changes_data( $post );
        Changes_Logs_Logger::log_change( $event, $log_data );
    }


    /**
     * Post Opened for Editing in WP Editors.
     *
     * @param WP_Post $post – Post object.
     */
    public static function change_opened_post_editor( $post ) {
        if ( empty( $post ) || ! $post instanceof \WP_Post ) {
            return;
        }

        $current_path = isset( $_SERVER['SCRIPT_NAME'] ) ? esc_url_raw( wp_unslash( $_SERVER['SCRIPT_NAME'] ) ) . '?post=' . $post->ID : false;
        $referrer     = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : false;

        if ( ! empty( $referrer ) ) {
            $parsed_url = wp_parse_url( $referrer );

            if ( isset( $parsed_url['path'] ) && 'post-new' === basename( $parsed_url['path'], '.php' ) ) {
                return $post;
            }
        }

        if ( ! empty( $referrer ) && strpos( $referrer, $current_path ) !== false ) {
            // Ignore this if we were on the same page.
            return $post;
        }

        if ( ! empty( $post->post_title ) ) {
            $event = 1310;
            if ( ! Changes_Logs_Logger::was_handled( $event ) ) {
                $log_data = array(
                    'postid'     => $post->ID,
                    'posttype'   => $post->post_type,
                    'posttitle'  => $post->post_title,
                    'poststatus' => $post->post_status,
                    'postdate'   => $post->post_date,
                    'posturl'    => get_permalink( $post->ID ),
                );
                Changes_Logs_Logger::log_change( $event, $log_data );
            }
        }
    }

    /**
     * Returns true if this looks like a re-save on a draft.
     *
     * @method is_post_draft_resave
     *
     * @param  \WP_Post $oldpost The old post object if one exists.
     * @param  \WP_Post $newpost The new post object.
     *
     * @return boolean
     */
    private static function is_post_draft_resave( $oldpost, $newpost ) {
        if ( 'draft' === $oldpost->post_status
        && $oldpost->post_status === $newpost->post_status
        && $oldpost->post_date_gmt === $newpost->post_date_gmt
        && preg_match( '/^[0\-\ \:]+$/', $oldpost->post_date_gmt ) ) {
            // Don't track this as a date change.
            return true;
        }
    }
}
