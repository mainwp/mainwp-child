<?php
/**
 * Logger: Content
 *
 * Content logger class file.
 *
 * @since     4.5.1
 * @package   mainwp/child
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
 * WordPress contents (posts, pages and custom posts).
 *
 * Apart from some other events, the following were migrated from plugins & themes sensor:
 * 5019 A plugin created a post
 * 5025 A plugin deleted a post
 *
 */
class Changes_WP_Content_Logger {

    /**
     * Holds the name of the meta used to setting the lock status of the post
     */
    const LOCK_META_NAME = '_edit_lock';

    /**
     * Old post.
     *
     * @var stdClass
     *
     */
    private static $old_post = null;

    /**
     * Old permalink.
     *
     * @var string
     *
     */
    private static $old_link = null;

    /**
     * Old categories.
     *
     * @var array
     *
     */
    private static $old_cats = null;

    /**
     * Old tags.
     *
     * @var array
     *
     */
    private static $old_tags = null;

    /**
     * Old path to file.
     *
     * @var string
     *
     */
    private static $old_tmpl = null;

    /**
     * Old post is marked as sticky.
     *
     * @var boolean
     *
     */
    private static $old_sticky = null;

    /**
     * Old Post Status.
     *
     * @var string
     *
     */
    private static $old_status = null;

    /**
     * Old Post Meta.
     *
     * @var string
     *
     */
    private static $old_meta = null;

    /**
     * Inits the main hooks
     *
     * @return void
     *
     */
    public static function init() {
        \add_action( 'pre_post_update', array( __CLASS__, 'get_before_post_edit_data' ), 10, 2 );
        \add_action( 'save_post', array( __CLASS__, 'post_changed' ), 10, 3 );
        \add_action( 'set_object_terms', array( __CLASS__, 'post_terms_changed' ), 10, 4 );
        \add_action( 'post_stuck', array( __CLASS__, 'post_stuck_event' ), 10, 1 );
        \add_action( 'post_unstuck', array( __CLASS__, 'post_unstuck_event' ), 10, 1 );
        \add_action( 'delete_post', array( __CLASS__, 'event_post_deleted' ), 10, 1 );
        \add_action( 'wp_trash_post', array( __CLASS__, 'event_post_trashed' ), 10, 1 );
        \add_action( 'untrash_post', array( __CLASS__, 'event_post_untrashed' ) );
        \add_action( 'future_to_publish', array( __CLASS__, 'event_publish_future' ), 10, 1 );
        \add_action( 'admin_action_edit', array( __CLASS__, 'edit_post_in_gutenberg' ), 10 );
        \add_filter( 'post_edit_form_tag', array( __CLASS__, 'edit_post_in_classic' ), 10, 1 );
        \add_action( 'wp_head', array( __CLASS__, 'viewing_post' ), 10 );
        \add_action( 'create_category', array( __CLASS__, 'event_category_creation' ), 10, 1 );
        \add_action( 'create_post_tag', array( __CLASS__, 'event_tag_creation' ), 10, 1 );
        \add_action( 'pre_delete_term', array( __CLASS__, 'check_taxonomy_term_deletion' ), 10, 2 );
        \add_filter( 'wp_update_term_data', array( __CLASS__, 'event_update_term_data' ), 10, 4 );
        \add_filter( 'add_post_metadata', array( __CLASS__, 'check_added_meta' ), 10, 5 );
        \add_filter( 'delete_post_metadata', array( __CLASS__, 'check_deleted_meta' ), 10, 5 );
        \add_action( 'update_post_meta', array( __CLASS__, 'before_changing_meta' ), 10, 4 );
        \add_action( 'updated_post_meta', array( __CLASS__, 'check_changed_meta' ), 10, 4 );
    }

    /**
     * Get Post Data.
     *
     * Collect old post data before post update event.
     *
     * @param int $post_id - Post ID.
     *
     * @since 5.0.0
     */
    public static function get_before_post_edit_data( $post_id ) {
        $post_id = (int) $post_id; // Making sure that the post id is integer.
        $post    = \get_post( $post_id ); // Get post.

        // If post exists.
        if ( ! empty( $post ) && $post instanceof \WP_Post ) {
            self::$old_post   = $post;
            self::$old_link   = get_permalink( $post_id );
            self::$old_tmpl   = self::get_post_template( self::$old_post );
            self::$old_cats   = self::get_post_categories( self::$old_post );
            self::$old_tags   = self::get_post_tags( self::$old_post );
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
     *
     */
    public static function post_changed( $post_id, $post, $update ) {
        // Ignore if post type is empty, revision or trash.
        if ( empty( $post->post_type ) || 'revision' === $post->post_type || 'trash' === $post->post_status ) {
            return;
        }

        if ( null === self::$old_post ) {
            return;
        }

        // Ignorable states.
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            // Check post creation event.
            if ( self::$old_post && 'auto-draft' === self::$old_post->post_status && 'draft' === $post->post_status ) {
                self::check_post_creation( self::$old_post, $post );
            }
            return;
        }

        if ( Changes_Logs_Manager::is_disabled_post_type( $post->post_type ) ) {
            return;
        }

        /**
         * Post Changed.
         *
         * Don't let the second request for meta update from Gutenberg pass this checkpoint.
         *
         * Only pass these requests:
         *   1. Rest request from Gutenberg.
         *   2. Classic editor request.
         *   3. Quick edit ajax request.
         *
         * @since 3.4
         */
        if ( ! defined( 'REST_REQUEST' ) && ! defined( 'DOING_AJAX' ) ) {
            // Either Gutenberg's second post request or classic editor's request.
            if ( ! isset( $_REQUEST['classic-editor'] ) ) {
                $editor_replace = get_option( 'classic-editor-replace', 'classic' );
                $allow_users    = get_option( 'classic-editor-allow-users', 'disallow' );

                // If block editor is selected and users are not allowed to switch editors then it is Gutenberg's second request.
                if ( 'block' === $editor_replace && 'disallow' === $allow_users ) {
                    return;
                }

                if ( 'allow' === $allow_users ) { // if users are allowed to switch then it is Gutenberg's second request.
                    return;
                }
            }
        }

        if ( $update ) {
            $status_event = self::check_status_change( self::$old_post, $post );

            if ( 2001 !== $status_event && 'auto-draft' !== self::$old_post->post_status ) {
                // Handle update post events.
                $changes = 0;
                $changes = self::check_author_change( self::$old_post, $post )
                + self::check_parent_change( self::$old_post, $post )
                + self::check_visibility_change( self::$old_post, $post, self::$old_status, $post->post_status )
                + self::check_date_change( self::$old_post, $post )
                + self::check_permalink_change( self::$old_link, get_permalink( $post->ID ), $post )
                + self::check_comments_pings( self::$old_post, $post );

                // If a status change event has occurred, then don't log event 2002 (post modified).
                $changes = $status_event ? true : $changes;
                if ( '1' === $changes ) {
                    remove_action( 'save_post', array( __CLASS__, 'post_changed' ), 10, 3 );
                }
                self::check_modification_change( $post->ID, self::$old_post, $post, $changes );
            }
        } else {
            // If not update then check post creation.
            self::check_post_creation( self::$old_post, $post );
        }
    }

    /**
     * Check if post terms changed via Gutenberg.
     *
     * @param int    $post_id  - Post ID.
     * @param array  $terms    - Array of terms.
     * @param array  $tt_ids   - Array of taxonomy term ids.
     * @param string $taxonomy - Taxonomy slug.
     *
     */
    public static function post_terms_changed( $post_id, $terms, $tt_ids, $taxonomy ) {
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
            // Check tags change event.
            self::check_tags_change( self::$old_tags, self::get_post_tags( $post ), $post );
        } else {
            // Check categories change event.
            self::check_categories_change( self::$old_cats, self::get_post_categories( $post ), $post );
        }
    }

    /**
     * Post Stuck Event.
     *
     * @param integer $post_id - Post ID.
     *
     */
    public static function post_stuck_event( $post_id ) {
        self::log_sticky_post_event( $post_id, 2049 );
    }

    /**
     * Post Unstuck Event.
     *
     * @param integer $post_id - Post ID.
     *
     */
    public static function post_unstuck_event( $post_id ) {
        self::log_sticky_post_event( $post_id, 2050 );
    }

    /**
     * Post permanently deleted.
     *
     * @param integer $post_id - Post ID.
     *
     */
    public static function event_post_deleted( $post_id ) {
        // Exclude CPTs from external plugins.
        $post = get_post( $post_id );

        $event = 2008;
        // Check WordPress backend operations.
        if ( self::check_auto_draft( $event, $post->post_title ) ) {
            return;
        }

        $event_data = self::get_post_event_data( $post ); // Get event data.

        // Check if this was initiated by a plugin.
        $request_params = \MainWP\Child\Changes\Helpers\Changes_PHP_Helper::get_filtered_request_data();
        if ( empty( $request_params['action'] ) && isset( $request_params['page'] ) ) {
            $event      = 5025;
            $event_data = array(
                'PostID'     => $post->ID,
                'PostType'   => $post->post_type,
                'PostTitle'  => $post->post_title,
                'PostStatus' => $post->post_status,
                'Username'   => 'Plugins',
            );
        }

        Changes_Logs_Manager::trigger_event( $event, $event_data ); // Log event.
    }

    /**
     * Post moved to the trash.
     *
     * @param integer $post_id - Post ID.
     *
     */
    public static function event_post_trashed( $post_id ) {
        $post        = get_post( $post_id );
        $editor_link = self::get_editor_link( $post_id );

        Changes_Logs_Manager::trigger_event(
            2012,
            array(
                'PostID'             => $post->ID,
                'PostType'           => $post->post_type,
                'PostTitle'          => $post->post_title,
                'PostStatus'         => $post->post_status,
                'PostDate'           => $post->post_date,
                'PostUrl'            => get_permalink( $post->ID ),
                $editor_link['name'] => $editor_link['value'],
            )
        );
    }

    /**
     * Post restored from trash.
     *
     * @param integer $post_id - Post ID.
     *
     */
    public static function event_post_untrashed( $post_id ) {
        $post        = get_post( $post_id );
        $editor_link = self::get_editor_link( $post_id );

        Changes_Logs_Manager::trigger_event(
            2014,
            array(
                'PostID'             => $post->ID,
                'PostType'           => $post->post_type,
                'PostTitle'          => $post->post_title,
                'PostStatus'         => $post->post_status,
                'PostDate'           => $post->post_date,
                'PostUrl'            => get_permalink( $post->ID ),
                $editor_link['name'] => $editor_link['value'],
            )
        );
        remove_action( 'save_post', array( __CLASS__, 'post_changed' ), 10, 3 );
    }

    /**
     * Post future publishing.
     *
     * @param integer $post_id - Post ID.
     *
     */
    public static function event_publish_future( $post_id ) {
        $post = get_post( $post_id );

        $editor_link = self::get_editor_link( $post_id );

        Changes_Logs_Manager::trigger_event(
            2001,
            array(
                'PostID'             => $post->ID,
                'PostType'           => $post->post_type,
                'PostTitle'          => $post->post_title,
                'PostStatus'         => $post->post_status,
                'PostDate'           => $post->post_date,
                'PostUrl'            => get_permalink( $post->ID ),
                $editor_link['name'] => $editor_link['value'],
            )
        );
        remove_action( 'save_post', array( __CLASS__, 'post_changed' ), 10, 3 );
    }

    /**
     * Alert for Editing of Posts and Custom Post Types in Gutenberg.
     *
     */
    public static function edit_post_in_gutenberg() {
        global $pagenow;

        if ( 'post.php' !== $pagenow ) {
            return;
        }

        $post_id = isset( $_GET['post'] ) ? (int) sanitize_text_field( wp_unslash( $_GET['post'] ) ) : false;

        // Check post id.
        if ( empty( $post_id ) ) {
            return;
        }

        if ( is_user_logged_in() && is_admin() ) {
            // Get post.
            $post = get_post( $post_id );

            // Log event.
            self::post_opened_in_editor( $post );
        }
    }

    /**
     * Alerts for Editing of Posts, Pages and Custom Post Types.
     *
     * @param WP_Post $post - Post.
     *
     */
    public static function edit_post_in_classic( $post ) {
        if ( is_user_logged_in() && is_admin() ) {
            // Log event.
            self::post_opened_in_editor( $post );
        }
        return $post;
    }

    /**
     * Post View Event.
     *
     * Alerts for Viewing of Posts and Custom Post Types.
     *
     */
    public static function viewing_post() {
        // Retrieve the current post object.
        $post = get_queried_object();

        if ( is_user_logged_in() && ! is_admin() ) {
            $current_path = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : false;

            if (
            ! empty( $_SERVER['HTTP_REFERER'] )
            && ! empty( $current_path )
            && false !== strpos( sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) ), $current_path )
            ) {
                // Ignore this if we were on the same page so we avoid double audit entries.
                return;
            }

            if ( ! empty( $post->post_title ) ) {
                $edit_link = self::get_editor_link( $post->ID ); // Get editor link.
                $post_data = self::get_post_event_data( $post ); // Get event post data.

                // Update post URL based on current actual path.
                if ( Changes_WP_Helper::is_multisite() && ! is_subdomain_install() ) {
                    // For multisite using subfolders, remove the subfolder.
                    $subdir_path = parse_url( home_url(), PHP_URL_PATH ); // phpcs:ignore
                    if ( ! is_null( $subdir_path ) ) {
                        $escaped      = str_replace( '/', '\/', preg_quote( $subdir_path ) ); // phpcs:ignore
                        $current_path = preg_replace( '/' . $escaped . '/', '', $current_path );
                    }
                }

                // Bail if this don't have this, as it's probably an archive.
                if ( ! isset( $post_data['PostUrl'] ) ) {
                    return;
                }

                $full_current_path = home_url( $current_path );
                if ( $full_current_path !== $post_data['PostUrl'] ) {
                    $post_data['PostUrl'] = esc_url( $full_current_path );
                }

                // Set editor link.
                $post_data[ $edit_link['name'] ] = $edit_link['value'];
                Changes_Logs_Manager::trigger_event( 2101, $post_data );
            }
        }
    }

    /**
     * New category created.
     *
     * @param integer $category_id - Category ID.
     *
     */
    public static function event_category_creation( $category_id ) {
        $category      = get_category( $category_id );
        $category_link = self::get_taxonomy_edit_link( $category_id, 'category' );
        Changes_Logs_Manager::trigger_event(
            2023,
            array(
                'CategoryName' => $category->name,
                'Slug'         => $category->slug,
                'CategoryLink' => $category_link,
            )
        );
    }

    /**
     * New tag created.
     *
     * @param int $tag_id - Tag ID.
     *
     */
    public static function event_tag_creation( $tag_id ) {
        $tag      = get_tag( $tag_id );
        $tag_link = self::get_taxonomy_edit_link( $tag_id );
        Changes_Logs_Manager::trigger_event(
            2121,
            array(
                'TagName' => $tag->name,
                'Slug'    => $tag->slug,
                'TagLink' => $tag_link,
            )
        );
    }

    /**
     * Taxonomy Terms Deleted Events.
     *
     * @param integer $term_id  - Term ID.
     * @param string  $taxonomy - Taxonomy Name.
     *
     */
    public static function check_taxonomy_term_deletion( $term_id, $taxonomy ) {
        if ( 'post_tag' === $taxonomy ) {
            $tag = get_tag( $term_id );
            Changes_Logs_Manager::trigger_event(
                2122,
                array(
                    'TagID'   => $term_id,
                    'TagName' => $tag->name,
                    'Slug'    => $tag->slug,
                )
            );
        } elseif ( 'category' === $taxonomy ) {
            $category      = get_category( $term_id );
            $category_link = self::get_taxonomy_edit_link( $term_id, $taxonomy );
            Changes_Logs_Manager::trigger_event(
                2024,
                array(
                    'CategoryID'   => $term_id,
                    'CategoryName' => $category->cat_name,
                    'Slug'         => $category->slug,
                    'CategoryLink' => $category_link,
                )
            );
        }
    }

    /**
     * Triggered when term data is updated.
     *
     * @param array  $data     Term data to be updated.
     * @param int    $term_id  Term ID.
     * @param string $taxonomy Taxonomy slug.
     * @param array  $args     Arguments passed to wp_update_term().
     *
     */
    public static function event_update_term_data( $data, $term_id, $taxonomy, $args ) {
        // Get new data.
        $new_name   = isset( $data['name'] ) ? $data['name'] : false;
        $new_slug   = isset( $data['slug'] ) ? $data['slug'] : false;
        $new_desc   = isset( $args['description'] ) ? $args['description'] : false;
        $new_parent = isset( $args['parent'] ) ? $args['parent'] : false;

        // Get old data.
        $term       = get_term( $term_id, $taxonomy );
        $old_name   = $term->name;
        $old_slug   = $term->slug;
        $old_desc   = $term->description;
        $old_parent = $term->parent;
        $term_link  = self::get_taxonomy_edit_link( $term_id, $taxonomy );

        // Check if the taxonomy is `post tag`.
        if ( 'post_tag' === $taxonomy ) {
            // Update if both names are not same.
            if ( $old_name !== $new_name ) {
                Changes_Logs_Manager::trigger_event(
                    2123,
                    array(
                        'old_name' => $old_name,
                        'new_name' => $new_name,
                        'TagLink'  => $term_link,
                        'Slug'     => $new_slug,
                    )
                );
            }

            // Update if both slugs are not same.
            if ( $old_slug !== $new_slug ) {
                Changes_Logs_Manager::trigger_event(
                    2124,
                    array(
                        'tag'      => $new_name,
                        'old_slug' => $old_slug,
                        'new_slug' => $new_slug,
                        'TagLink'  => $term_link,
                    )
                );
            }

            // Update if both descriptions are not same.
            if ( $old_desc !== $new_desc ) {
                Changes_Logs_Manager::trigger_event(
                    2125,
                    array(
                        'tag'      => $new_name,
                        'TagLink'  => $term_link,
                        'old_desc' => $old_desc,
                        'new_desc' => $new_desc,
                    )
                );
            }
        } elseif ( 'category' === $taxonomy ) { // Check if the taxonomy is `category`.
            // Log event if both names are not same.
            if ( $old_name !== $new_name ) {
                Changes_Logs_Manager::trigger_event(
                    2127,
                    array(
                        'old_name' => $old_name,
                        'new_name' => $new_name,
                        'slug'     => $new_slug,
                        'cat_link' => $term_link,
                    )
                );
            }

            // Log event if both slugs are not same.
            if ( $old_slug !== $new_slug ) {
                Changes_Logs_Manager::trigger_event(
                    2128,
                    array(
                        'CategoryName' => $new_name,
                        'old_slug'     => $old_slug,
                        'new_slug'     => $new_slug,
                        'cat_link'     => $term_link,
                    )
                );
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
                Changes_Logs_Manager::trigger_event(
                    2052,
                    array(
                        'CategoryName' => $new_name,
                        'OldParent'    => $old_parent_name,
                        'NewParent'    => $new_parent_name,
                        'CategoryLink' => $term_link,
                        'Slug'         => $new_slug,
                    )
                );
            }
        }
        return $data; // Return data for the filter.
    }

    /**
     * Checks if selected metadata items have changed. Function is called from different contexts.
     *
     * @param int    $post_id        Post ID.
     * @param string $meta_key       Meta key.
     * @param mixed  $meta_value     Meta value.
     * @param mixed  $default_result Default result. The name is misleading - it holds the meta ID value.
     *
     * @return int - The meta ID.
     *
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
     * @return int ID of updated metadata entry.
     *
     */
    public static function check_changed_meta( $meta_id, $post_id, $meta_key, $meta_value ) {
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
     * @return bool|null Whether to allow metadata deletion of the given type.
     *
     */
    public static function check_deleted_meta( $delete, $object_id, $meta_key, $meta_value, $delete_all ) {
        return self::check_selected_meta_change( $object_id, $meta_key, $meta_value, $delete );
    }

    /**
     * Check Page Template Update for additions.
     *
     * @param null|bool $check      Whether to allow adding metadata for the given type.
     * @param int       $object_id  ID of the object metadata is for.
     * @param string    $meta_key   Metadata key.
     * @param mixed     $meta_value Metadata value. Must be serializable if non-scalar.
     * @param bool      $unique     Whether the specified meta key should be unique for the object.
     *
     * @return bool|null    Whether to allow metadata addition of the given type.
     *
     */
    public static function check_added_meta( $check, $object_id, $meta_key, $meta_value, $unique ) {
        return self::check_selected_meta_change( $object_id, $meta_key, $meta_value, $check );
    }

    /**
     * Check Page Template Update.
     *
     * @param int   $post_id    Post ID.
     * @param mixed $meta_value Meta value.
     *
     */
    public static function check_template_change( $post_id, $meta_value ) {
        $post     = get_post( $post_id );
        $old_tmpl = ( self::$old_tmpl && 'page' !== basename( self::$old_tmpl, '.php' ) ) ? ucwords( str_replace( array( '-', '_' ), ' ', basename( self::$old_tmpl, '.php' ) ) ) : __( 'Default template', 'mainwp-child' );
        $new_tmpl = ( $meta_value ) ? ucwords( str_replace( array( '-', '_' ), ' ', basename( $meta_value ) ) ) : __( 'Default', 'mainwp-child' );
        if ( $old_tmpl !== $new_tmpl ) {
            $editor_link = self::get_editor_link( $post_id );
            Changes_Logs_Manager::trigger_event(
                2048,
                array(
                    'PostID'             => $post->ID,
                    'PostType'           => $post->post_type,
                    'PostTitle'          => $post->post_title,
                    'PostStatus'         => $post->post_status,
                    'PostDate'           => $post->post_date,
                    'OldTemplate'        => $old_tmpl,
                    'NewTemplate'        => $new_tmpl,
                    $editor_link['name'] => $editor_link['value'],
                )
            );
        }
    }

    /**
     * Fires immediately before updating a post's metadata.
     *
     * @param int    $meta_id    ID of metadata entry to update.
     * @param int    $object_id  Post ID.
     * @param string $meta_key   Metadata key.
     * @param mixed  $meta_value Metadata value. This will be a PHP-serialized string representation of the value
     *                           if the value is an array, an object, or itself a PHP-serialized string.
     *
     */
    public static function before_changing_meta( $meta_id, $object_id, $meta_key, $meta_value ) {
        if ( self::LOCK_META_NAME === $meta_key ) {
            self::fire_lock_change( (int) $object_id, (string) $meta_value );
        }
    }

    /**
     * Fires lock owner changing.
     *
     * @param integer $post_id   - The ID of the post.
     * @param string  $meta_value - The meta value - that is in format "1649934679:2"
     *                             where the "1649934679" is the timestamp of the locking
     *                             and "2" is the ID of the \WP_User which currently editing the post.
     *
     * @return void
     *
     */
    public static function fire_lock_change( int $post_id, string $meta_value ) {

        if ( $meta_value ) {
            if ( ! function_exists( 'wp_check_post_lock' ) ) {
                require_once ABSPATH . 'wp-admin/includes/post.php';
            }
            $user_id = \wp_check_post_lock( $post_id ); // The id of the user currently editing the post.

            /**
             * The post is currently edited by someone - proceed with extracting the new user editor
             */
            if ( $user_id ) {
                $lock        = explode( ':', $meta_value );
                $new_user_id = $lock[1];

                if ( $new_user_id ) {
                    if ( $new_editor_user = get_userdata( $new_user_id ) ) { //phpcs:ignore
                        $post        = get_post( $post_id );
                        $editor_link = self::get_editor_link( $post_id );
                        Changes_Logs_Manager::trigger_event(
                            2133,
                            array(
                                'PostID'             => $post->ID,
                                'PostType'           => $post->post_type,
                                'PostTitle'          => $post->post_title,
                                'PostStatus'         => $post->post_status,
                                $editor_link['name'] => $editor_link['value'],
                                'user'               => get_userdata( $user_id )->display_name,
                            )
                        );
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
     *
     */
    public static function check_featured_image_change( $post_id, $meta_value ) {
        $previous_featured_image = ( isset( self::$old_meta['_thumbnail_id'][0] ) ) ? wp_get_attachment_metadata( self::$old_meta['_thumbnail_id'][0] ) : false;
        $new_featured_image      = wp_get_attachment_metadata( $meta_value );

        if ( empty( $new_featured_image['file'] ) && empty( $previous_featured_image['file'] ) ) {
            return;
        }

        $event_type = 'modified';

        if ( empty( $previous_featured_image['file'] ) && ! empty( $new_featured_image['file'] ) ) {
            $event_type = 'added';
        } elseif ( ! empty( $previous_featured_image['file'] ) && empty( $new_featured_image['file'] ) ) {
            $event_type = 'removed';
        }

        $previous_image = is_array( $previous_featured_image ) && array_key_exists( 'file', $previous_featured_image ) ? $previous_featured_image['file'] : __( 'No previous image', 'mainwp-child' );
        $new_image      = is_array( $new_featured_image ) && array_key_exists( 'file', $new_featured_image ) ? $new_featured_image['file'] : __( 'No image', 'mainwp-child' );

        $post        = get_post( $post_id );
        $editor_link = self::get_editor_link( $post_id );
        Changes_Logs_Manager::trigger_event(
            2130,
            array(
                'PostID'             => $post->ID,
                'PostType'           => $post->post_type,
                'PostTitle'          => $post->post_title,
                'PostStatus'         => $post->post_status,
                'PostDate'           => $post->post_date,
                'previous_image'     => $previous_image,
                'new_image'          => $new_image,
                $editor_link['name'] => $editor_link['value'],
                'EventType'          => $event_type,
            )
        );
    }

    /**
     * Collect old post data before MainWP Post update event.
     *
     * @param array $new_post    - Array of new post data.
     * @param array $post_custom - Array of data related to MainWP.
     *
     */
    public static function event_mainwp_init( $new_post, $post_custom ) {
        // Get post id.
        $post_id = isset( $post_custom['_mainwp_edit_post_id'][0] ) ? $post_custom['_mainwp_edit_post_id'][0] : false;
        $post_id = (int) $post_id;

        // Check if ID exists.
        if ( $post_id ) {
            // Get post.
            $post = get_post( $post_id );

            // If post exists.
            if ( ! empty( $post ) ) {
                self::$old_post   = $post;
                self::$old_link   = get_permalink( $post_id );
                self::$old_tmpl   = self::get_post_template( self::$old_post );
                self::$old_cats   = self::get_post_categories( self::$old_post );
                self::$old_tags   = self::get_post_tags( self::$old_post );
                self::$old_sticky = in_array( $post_id, get_option( 'sticky_posts' ), true );
            }
        }
    }

    /**
     * Get the template path.
     *
     * @param WP_Post $post - The post.
     * @return string - Full path to file.
     *
     */
    private static function get_post_template( $post ) {
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
     *
     */
    private static function get_post_categories( $post ) {
        return ! isset( $post->ID ) ? array() : wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) );
    }

    /**
     * Get post tags (array of tag names).
     *
     * @param stdClass $post - The post.
     * @return array - List of tags.
     *
     */
    private static function get_post_tags( $post ) {
        return ! isset( $post->ID ) ? array() : wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) );
    }

    /**
     * Check post creation.
     *
     * @global array $_POST
     *
     * @param WP_Post $old_post - Old post.
     * @param WP_Post $new_post - New post.
     *
     */
    private static function check_post_creation( $old_post, $new_post ) {
        if ( ! empty( $new_post ) && $new_post instanceof \WP_Post ) {
            $event        = 0;
            $is_scheduled = false;
            switch ( $new_post->post_status ) {
                case 'publish':
                    $event = 2001;
                    break;
                case 'draft':
                    $event = 2000;
                    break;
                case 'future':
                    $event        = 2074;
                    $is_scheduled = true;
                    break;
                case 'pending':
                    $event = 2073;
                    break;
                default:
                    break;
            }
            if ( $event ) {
                $editor_link = self::get_editor_link( $new_post->ID ); // Editor link.
                $event_data  = self::get_post_event_data( $new_post ); // Post event data.

                // Set editor link in the event data.
                $event_data[ $editor_link['name'] ] = $editor_link['value'];

                if ( $is_scheduled ) {
                    $event_data['PublishingDate'] = $new_post->post_date;
                    Changes_Logs_Manager::trigger_event( $event, $event_data );
                } else {

                    // So far we assume that the action is initiated by a user, let's check if it was actually initiated
                    // by a plugin.
                    $request_params = \MainWP\Child\Changes\Helpers\Changes_PHP_Helper::get_filtered_request_data();
                    if ( array_key_exists( 'plugin', $request_params ) && ! empty( $request_params['plugin'] ) ) {
                        // Event initiated by a plugin.
                        $plugin_name = $request_params['plugin'];
                        if ( \is_wp_error( \validate_plugin( $plugin_name ) ) ) {
                            return;
                        }
                        $plugin_data = get_plugin_data( trailingslashit( WP_PLUGIN_DIR ) . $plugin_name );
                        $event_data  = array(
                            'PluginName'         => ( $plugin_data && isset( $plugin_data['Name'] ) ) ? $plugin_data['Name'] : false,
                            'PostID'             => $new_post->ID,
                            'PostType'           => $new_post->post_type,
                            'PostTitle'          => $new_post->post_title,
                            'PostStatus'         => $new_post->post_status,
                            'Username'           => 'Plugins',
                            $editor_link['name'] => $editor_link['value'],
                        );
                    }

                    Changes_Logs_Manager::trigger_event( $event, $event_data );
                }
            }
        }
    }

    /**
     * Get editor link.
     *
     * @param int $post_id - The \WP_Post post ID.
     *
     * @return array $editor_link - Name and value link.
     *
     */
    public static function get_editor_link( $post_id ): array {
        $post_id = \is_object( $post_id ) ? $post_id->ID : (int) $post_id;
        return array(
            'name'  => 'EditorLinkPost',
            'value' => \get_edit_post_link( $post_id ),
        );
    }

    /**
     * Return Post Event Data.
     *
     * @param WP_Post $post - WP Post object.
     * @return array
     *
     */
    public static function get_post_event_data( $post ) {
        if ( ! empty( $post ) && $post instanceof \WP_Post ) {
            $event_data = array(
                'PostID'     => $post->ID,
                'PostType'   => $post->post_type,
                'PostTitle'  => $post->post_title,
                'PostStatus' => $post->post_status,
                'PostDate'   => $post->post_date,
                'PostUrl'    => get_permalink( $post->ID ),
            );
            return $event_data;
        }
        return array();
    }

    /**
     * Author changed.
     *
     * @param stdClass $oldpost - Old post.
     * @param stdClass $newpost - New post.
     *
     */
    private static function check_author_change( $oldpost, $newpost ) {
        if ( $oldpost->post_author !== $newpost->post_author ) {
            $editor_link = self::get_editor_link( $oldpost->ID );
            $old_author  = get_userdata( $oldpost->post_author );
            $old_author  = ( is_object( $old_author ) ) ? $old_author->user_login : 'N/A';
            $new_author  = get_userdata( $newpost->post_author );
            $new_author  = ( is_object( $new_author ) ) ? $new_author->user_login : 'N/A';
            Changes_Logs_Manager::trigger_event(
                2019,
                array(
                    'PostID'             => $oldpost->ID,
                    'PostType'           => $oldpost->post_type,
                    'PostTitle'          => $oldpost->post_title,
                    'PostStatus'         => $oldpost->post_status,
                    'PostDate'           => $oldpost->post_date,
                    'PostUrl'            => get_permalink( $oldpost->ID ),
                    'OldAuthor'          => $old_author,
                    'NewAuthor'          => $new_author,
                    $editor_link['name'] => $editor_link['value'],
                )
            );
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
     *
     */
    private static function check_status_change( $oldpost, $newpost ) {
        if ( $oldpost->post_status !== $newpost->post_status ) {
            $event        = 0;
            $is_scheduled = false;

            if ( 'auto-draft' === $oldpost->post_status && 'draft' === $newpost->post_status ) {
                $event = 2000;
            } elseif ( 'publish' === $newpost->post_status ) {
                $event = 2001;
            } elseif ( 'pending' === $newpost->post_status ) {
                $event = 2073;
            } elseif ( 'future' === $newpost->post_status ) {
                $event        = 2074;
                $is_scheduled = true;
            } else {
                $event = 2021;
            }

            if ( $event ) {
                $editor_link = self::get_editor_link( $newpost->ID ); // Editor link.
                $event_data  = self::get_post_event_data( $newpost ); // Post event data.

                // Set editor link in the event data.
                $event_data[ $editor_link['name'] ] = $editor_link['value'];

                if ( $is_scheduled ) {
                    $event_data['PublishingDate'] = $newpost->post_date;
                    Changes_Logs_Manager::trigger_event( $event, $event_data );
                } elseif ( 2021 === $event ) {
                    $event_data['OldStatus'] = $oldpost->post_status;
                    $event_data['NewStatus'] = $newpost->post_status;
                    Changes_Logs_Manager::trigger_event( $event, $event_data );
                } else {
                    // NOTE: this triggers if NOT firing event 5019.
                    Changes_Logs_Manager::trigger_event_if( $event, $event_data, array( __CLASS__, 'plugin_not_created_post' ) );
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
     *
     */
    private static function check_parent_change( $oldpost, $newpost ) {
        if ( $oldpost->post_parent !== $newpost->post_parent && 'page' === $newpost->post_type ) {
            $editor_link = self::get_editor_link( $oldpost->ID );
            Changes_Logs_Manager::trigger_event(
                2047,
                array(
                    'PostID'             => $oldpost->ID,
                    'PostType'           => $oldpost->post_type,
                    'PostTitle'          => $oldpost->post_title,
                    'PostStatus'         => $oldpost->post_status,
                    'PostDate'           => $oldpost->post_date,
                    'OldParent'          => $oldpost->post_parent,
                    'NewParent'          => $newpost->post_parent,
                    'OldParentName'      => $oldpost->post_parent ? get_the_title( $oldpost->post_parent ) : 'no parent',
                    'NewParentName'      => $newpost->post_parent ? get_the_title( $newpost->post_parent ) : 'no parent',
                    $editor_link['name'] => $editor_link['value'],
                )
            );
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
     *
     */
    private static function check_permalink_change( $old_link, $new_link, $post ) {
        if ( in_array( $post->post_status, array( 'draft', 'pending' ), true ) ) {
            $old_link = self::$old_post->post_name;
            $new_link = $post->post_name;
        }

        if ( $old_link !== $new_link ) {
            $editor_link = self::get_editor_link( $post->ID );
            Changes_Logs_Manager::trigger_event(
                2017,
                array(
                    'PostID'             => $post->ID,
                    'PostType'           => $post->post_type,
                    'PostTitle'          => $post->post_title,
                    'PostStatus'         => $post->post_status,
                    'PostDate'           => $post->post_date,
                    'OldUrl'             => $old_link,
                    'NewUrl'             => $new_link,
                    $editor_link['name'] => $editor_link['value'],
                )
            );
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
     *
     */
    private static function check_visibility_change( $oldpost, $newpost, $old_status, $new_status ) {
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
            $editor_link = self::get_editor_link( $oldpost->ID );
            Changes_Logs_Manager::trigger_event(
                2025,
                array(
                    'PostID'             => $oldpost->ID,
                    'PostType'           => $oldpost->post_type,
                    'PostTitle'          => $oldpost->post_title,
                    'PostStatus'         => $newpost->post_status,
                    'PostDate'           => $oldpost->post_date,
                    'PostUrl'            => get_permalink( $oldpost->ID ),
                    'OldVisibility'      => $old_visibility,
                    'NewVisibility'      => $new_visibility,
                    $editor_link['name'] => $editor_link['value'],
                )
            );
            return 1;
        }
    }

    /**
     * Post date changed.
     *
     * @param \WP_Post $oldpost - Old post.
     * @param \WP_Post $newpost - New post.
     *
     */
    private static function check_date_change( $oldpost, $newpost ) {
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

        /*
            * Return early if this looks like a re-save on a draft.
            */
        if ( self::is_draft_resave( $oldpost, $newpost ) ) {
            return 0;
        }

        if ( $from !== $to ) {
            $editor_link = self::get_editor_link( $oldpost->ID );
            Changes_Logs_Manager::trigger_event(
                2027,
                array(
                    'PostID'             => $oldpost->ID,
                    'PostType'           => $oldpost->post_type,
                    'PostTitle'          => $oldpost->post_title,
                    'PostStatus'         => $oldpost->post_status,
                    'PostDate'           => $newpost->post_date,
                    'PostUrl'            => get_permalink( $oldpost->ID ),
                    'OldDate'            => $oldpost->post_date,
                    'NewDate'            => $newpost->post_date,
                    $editor_link['name'] => $editor_link['value'],
                )
            );
            return 1;
        }
        return 0;
    }

    /**
     * Comments/Trackbacks and Pingbacks check.
     *
     * @param stdClass $oldpost - Old post.
     * @param stdClass $newpost - New post.
     *
     */
    private static function check_comments_pings( $oldpost, $newpost ) {
        $result      = 0;
        $editor_link = self::get_editor_link( $newpost->ID );

        // Comments.
        if ( $oldpost->comment_status !== $newpost->comment_status ) {
            Changes_Logs_Manager::trigger_event(
                2111,
                array(
                    'PostID'             => $newpost->ID,
                    'PostType'           => $newpost->post_type,
                    'PostStatus'         => $newpost->post_status,
                    'PostDate'           => $newpost->post_date,
                    'PostTitle'          => $newpost->post_title,
                    'PostUrl'            => get_permalink( $newpost->ID ),
                    $editor_link['name'] => $editor_link['value'],
                    'EventType'          => 'open' === $newpost->comment_status ? 'enabled' : 'disabled',
                )
            );
            $result = 1;
        }

        // Trackbacks and Pingbacks.
        if ( $oldpost->ping_status !== $newpost->ping_status ) {
            Changes_Logs_Manager::trigger_event(
                2112,
                array(
                    'PostID'             => $newpost->ID,
                    'PostType'           => $newpost->post_type,
                    'PostTitle'          => $newpost->post_title,
                    'PostStatus'         => $newpost->post_status,
                    'PostDate'           => $newpost->post_date,
                    'PostUrl'            => get_permalink( $newpost->ID ),
                    $editor_link['name'] => $editor_link['value'],
                    'EventType'          => 'open' === $newpost->ping_status ? 'enabled' : 'disabled',
                )
            );
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
     *
     */
    private static function check_categories_change( $old_cats, $new_cats, $post ) {
        $old_cats = implode( ', ', (array) $old_cats );
        $new_cats = implode( ', ', (array) $new_cats );

        if ( $old_cats !== $new_cats && 'page' !== $post->post_type ) {
            $editor_link = self::get_editor_link( $post->ID );
            $alert_data  = array(
                'PostID'             => $post->ID,
                'PostType'           => $post->post_type,
                'PostTitle'          => $post->post_title,
                'PostStatus'         => $post->post_status,
                'PostDate'           => $post->post_date,
                'PostUrl'            => get_permalink( $post->ID ),
                'OldCategories'      => $old_cats ? $old_cats : 'no categories',
                'NewCategories'      => $new_cats ? $new_cats : 'no categories',
                $editor_link['name'] => $editor_link['value'],
            );
            Changes_Logs_Manager::trigger_event( 2016, $alert_data );
        }
    }

    /**
     * Reports tags change event. This could be tags addition, removal and possibly other in the future.
     *
     * @param int      $event_code   Event code.
     * @param WP_Post  $post         WordPress post object.
     * @param string[] $tags_changed Changed tags.
     *
     */
    private static function report_tags_change_event( $event_code, $post, $tags_changed ) {
        $editor_link = self::get_editor_link( $post->ID );
        Changes_Logs_Manager::trigger_event(
            $event_code,
            array(
                'PostID'             => $post->ID,
                'PostType'           => $post->post_type,
                'PostStatus'         => $post->post_status,
                'PostTitle'          => $post->post_title,
                'PostDate'           => $post->post_date,
                'PostUrl'            => get_permalink( $post->ID ),
                'tag'                => ! empty( $tags_changed ) ? implode( ', ', $tags_changed ) : esc_html__( 'no tags', 'mainwp-child' ),
                $editor_link['name'] => $editor_link['value'],
            )
        );
    }

    /**
     * Tags changed.
     *
     * @param array   $old_tags - Old tags.
     * @param array   $new_tags - New tags.
     * @param WP_Post $post - The post.
     *
     */
    private static function check_tags_change( $old_tags, $new_tags, $post ) {
        // Ensure old_tags is not null.
        if ( ! $old_tags ) {
            $old_tags = array();
        }
        $intersection = array_intersect( $old_tags, $new_tags );
        if ( count( $intersection ) === count( $old_tags ) && count( $old_tags ) === count( $new_tags ) ) {
            // No change, let's leave.
            return;
        }

        // Check for added tags.
        $added_tags = array_diff( (array) $new_tags, (array) $old_tags );
        if ( ! empty( $added_tags ) ) {
            self::report_tags_change_event( 2119, $post, $added_tags );
        }

        // Check for removed tags.
        $removed_tags = array_diff( (array) $old_tags, (array) $new_tags );
        if ( ! empty( $removed_tags ) ) {
            self::report_tags_change_event( 2120, $post, $removed_tags );
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
     *
     */
    public static function check_modification_change( $post_id, $oldpost, $newpost, $modified ) {
        self::check_title_change( $oldpost, $newpost );

        $content_changed = $oldpost->post_content !== $newpost->post_content;

        /*
            * If the content hasn't changed and this looks to be a draft resave
            * then we won't track anything for this modification.
            */
        if ( ! $content_changed && self::is_draft_resave( $oldpost, $newpost ) ) {
            return;
        }

        if ( $oldpost->post_modified !== $newpost->post_modified ) {
            $event = 0;

            if ( $content_changed ) { // Check if content changed.
                $event = 2065;
            } elseif ( ! $modified ) {
                $event = 2002;
            }

            if ( $event ) {
                if ( 2002 === $event ) {
                    // Get Yoast alerts.
                    $yoast_alerts         = Changes_Logs_Manager::get_alerts_by_category( 'Yoast SEO' );
                    $yoast_metabox_alerts = Changes_Logs_Manager::get_alerts_by_category( 'Yoast SEO Meta Box' );
                    $yoast_alerts         = $yoast_alerts + $yoast_metabox_alerts;
                    // Check all alerts.
                    foreach ( $yoast_alerts as $alert_code => $alert ) {
                        if ( Changes_Logs_Manager::will_or_has_triggered( $alert_code ) ) {
                            return 0; // Return if any Yoast alert has or will trigger.
                        }
                    }
                }

                $event_data                         = self::get_post_event_data( $oldpost );
                $editor_link                        = self::get_editor_link( $oldpost->ID );
                $event_data[ $editor_link['name'] ] = $editor_link['value'];
                $event_data['RevisionLink']         = self::get_post_revision( $post_id, $oldpost );

                // Check excerpt change.
                $old_post_excerpt = $oldpost->post_excerpt;
                $post_excerpt     = get_post_field( 'post_excerpt', $post_id );

                if ( empty( $old_post_excerpt ) && ! empty( $post_excerpt ) ) {
                    $event_data['EventType'] = 'added';
                } elseif ( ! empty( $old_post_excerpt ) && empty( $post_excerpt ) ) {
                    $event_data['EventType'] = 'removed';
                } elseif ( $old_post_excerpt !== $post_excerpt ) {
                    $event_data['EventType'] = 'modified';
                }

                if ( $old_post_excerpt !== $post_excerpt ) {
                    $event = 2129;
                    // We are purposefully showing an empty, not NULL value.
                    $event_data['old_post_excerpt'] = ( $old_post_excerpt ) ? $old_post_excerpt : ' ';
                    $event_data['post_excerpt']     = ( $post_excerpt ) ? $post_excerpt : ' ';
                }

                if ( 2002 === $event ) {
                    // If we reach this point, we no longer need to check if the content has changed as we already have
                    // an event to handle it. So trigger 2002 regardless and "something" has changed in the post, we
                    // just don't detect it elsewhere.
                    Changes_Logs_Manager::trigger_event_if( $event, $event_data, array( __CLASS__, 'ignore_other_post_events' ) );
                } else {
                    Changes_Logs_Manager::trigger_event( $event, $event_data );
                }
            }
        }
    }

    /**
     * Method: Ensure no other post-related events are being fired, or have recently been fired.
     *
     * @return bool
     *
     */
    public static function ignore_other_post_events() {

        $post_events = array_keys( Changes_Logs_Manager::get_alerts_by_sub_category( 'Content' ) );

        $post_events = array_merge( $post_events, array_keys( Changes_Logs_Manager::get_alerts_by_sub_category( 'Coupons' ) ) );

        $post_events = array_merge( $post_events, array_keys( Changes_Logs_Manager::get_alerts_by_sub_category( 'Tags' ) ) );

        $post_events = array_merge( $post_events, array_keys( Changes_Logs_Manager::get_alerts_by_sub_category( 'Categories' ) ) );

        $post_events = array_merge( $post_events, array_keys( Changes_Logs_Manager::get_alerts_by_sub_category( 'Custom Fields' ) ) );

        $post_events = array_merge( $post_events, array_keys( Changes_Logs_Manager::get_alerts_by_sub_category( 'Custom Fields (ACF)' ) ) );

        $post_events = array_merge( $post_events, array_keys( Changes_Logs_Manager::get_alerts_by_sub_category( 'Comments' ) ) );

        $post_events = array_merge( $post_events, array_keys( Changes_Logs_Manager::get_alerts_by_sub_category( 'Custom Post Types' ) ) );

        $post_events = array_merge( $post_events, array_keys( Changes_Logs_Manager::get_alerts_by_sub_category( 'Pages' ) ) );

        $post_events[] = 9159;

        foreach ( $post_events as $event ) {
            if ( Changes_Logs_Manager::will_or_has_triggered( $event ) || Changes_Logs_Manager::was_triggered_recently( $event ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Changed title of a post.
     *
     * @param stdClass $oldpost - Old post.
     * @param stdClass $newpost - New post.
     *
     */
    private static function check_title_change( $oldpost, $newpost ) {
        if ( $oldpost->post_title !== $newpost->post_title ) {
            $editor_link = self::get_editor_link( $oldpost->ID );
            Changes_Logs_Manager::trigger_event(
                2086,
                array(
                    'PostID'             => $newpost->ID,
                    'PostType'           => $newpost->post_type,
                    'PostTitle'          => $newpost->post_title,
                    'PostStatus'         => $newpost->post_status,
                    'PostDate'           => $newpost->post_date,
                    'PostUrl'            => get_permalink( $newpost->ID ),
                    'OldTitle'           => $oldpost->post_title,
                    'NewTitle'           => $newpost->post_title,
                    $editor_link['name'] => $editor_link['value'],
                )
            );
            return 1;
        }
        return 0;
    }

    /**
     * Return post revision link.
     *
     * @param integer $post_id - Post ID.
     * @param WP_Post $post    - WP Post object.
     *
     * @return string
     *
     */
    private static function get_post_revision( $post_id, $post ) {
        $revisions = wp_get_post_revisions( $post_id );
        if ( ! empty( $revisions ) ) {
            $revision = array_shift( $revisions );
            return self::get_revision_link( $revision->ID );
        }
    }

    /**
     * Builds revision link.
     *
     * @param integer $revision_id - Revision ID.
     *
     * @return string|null - Link.
     *
     */
    private static function get_revision_link( $revision_id ) {
        return ! empty( $revision_id ) ? add_query_arg( 'revision', $revision_id, \network_admin_url( 'revision.php' ) ) : null;
    }

    /**
     * Log post stuck/unstuck events.
     *
     * @param integer $post_id - Post ID.
     * @param integer $event   - Event ID.
     *
     */
    private static function log_sticky_post_event( $post_id, $event ) {
        // Get post.
        $post = get_post( $post_id );

        if ( is_wp_error( $post ) ) {
            return;
        }

        $editor_link = self::get_editor_link( $post_id ); // Editor link.
        $event_data  = self::get_post_event_data( $post ); // Event data.

        $event_data[ $editor_link['name'] ] = $editor_link['value'];
        Changes_Logs_Manager::trigger_event( $event, $event_data );
    }

    /**
     * Check auto draft and the setting: Hide Plugin in Plugins Page
     *
     * @param integer $code  - Alert code.
     * @param string  $title - Title.
     *
     * @return boolean
     *
     */
    private static function check_auto_draft( $code, $title ) {
        $ignore = 0;
        if ( 2008 === $code && ( 'auto-draft' === $title || 'Auto Draft' === $title ) ) {
            $ignore = ! \MainWP\Child\Changes\Helpers\Changes_Settings_Helper::get_boolean_option_value( 'wp-backend' );
        }
        return $ignore;
    }

    /**
     * Post Opened for Editing in WP Editors.
     *
     * @param WP_Post $post – Post object.
     *
     */
    public static function post_opened_in_editor( $post ) {
        if ( empty( $post ) || ! $post instanceof \WP_Post ) {
            return;
        }

        $current_path = isset( $_SERVER['SCRIPT_NAME'] ) ? esc_url_raw( wp_unslash( $_SERVER['SCRIPT_NAME'] ) ) . '?post=' . $post->ID : false;
        $referrer     = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : false;

        // Check referrer URL.
        if ( ! empty( $referrer ) ) {
            // Parse the referrer.
            $parsed_url = wp_parse_url( $referrer );

            // If the referrer is post-new then we can ignore this one.
            if ( isset( $parsed_url['path'] ) && 'post-new' === basename( $parsed_url['path'], '.php' ) ) {
                return $post;
            }
        }

        if ( ! empty( $referrer ) && strpos( $referrer, $current_path ) !== false ) {
            // Ignore this if we were on the same page so we avoid double audit entries.
            return $post;
        }

        if ( ! empty( $post->post_title ) ) {
            $event = 2100;
            if ( ! Changes_Logs_Manager::was_triggered( $event ) ) {
                $editor_link = self::get_editor_link( $post->ID );
                Changes_Logs_Manager::trigger_event(
                    $event,
                    array(
                        'PostID'             => $post->ID,
                        'PostType'           => $post->post_type,
                        'PostTitle'          => $post->post_title,
                        'PostStatus'         => $post->post_status,
                        'PostDate'           => $post->post_date,
                        'PostUrl'            => get_permalink( $post->ID ),
                        $editor_link['name'] => $editor_link['value'],
                    )
                );
            }
        }
    }

    /**
     * Builds category link.
     *
     * @param integer $tag_id   - Tag ID.
     * @param string  $taxonomy - Taxonomy.
     *
     * @return string|null - Link.
     *
     */
    private static function get_taxonomy_edit_link( $tag_id, $taxonomy = 'post_tag' ) {
        $tag_args = array(
            'taxonomy' => $taxonomy,
            'tag_ID'   => $tag_id,
        );
        return ! empty( $tag_id ) ? add_query_arg( $tag_args, \network_admin_url( 'term.php' ) ) : null;
    }

    /**
     * Returns true if this looks like a re-save on a draft.
     *
     * @method is_draft_resave
     *
     * @param  \WP_Post $oldpost The old post object if one exists.
     * @param  \WP_Post $newpost The new post object.
     *
     * @return boolean
     *
     */
    private static function is_draft_resave( $oldpost, $newpost ) {
        /*
            * If this is a 'draft' and used to be a 'draft' plus the gmt dates
            * match and it contains only characters that would appear in the
            * a default unpublished post then...
            */
        if ( 'draft' === $oldpost->post_status
        && $oldpost->post_status === $newpost->post_status
        && $oldpost->post_date_gmt === $newpost->post_date_gmt
        && preg_match( '/^[0\-\ \:]+$/', $oldpost->post_date_gmt ) ) {
            // Don't track this as a date change.
            return true;
        }
    }

    /**
     * Callback to test if a post was just made by a plugin.
     *
     * NOTE: the return is flipped to handle a double NOT in _CommitItem().
     *
     * @method plugin_not_created_post
     *
     * @return boolean
     *
     */
    public static function plugin_not_created_post() {
        // Ensure no event for a WC coupon.
        if ( Changes_Logs_Manager::will_or_has_triggered( 9063 ) ) {
            return false;
        }

        $triggered = Changes_Logs_Manager::will_or_has_triggered( 5019 );
        // inverting value here to account for the double NOT in _CommitItem().

        return ! $triggered;
    }
}
