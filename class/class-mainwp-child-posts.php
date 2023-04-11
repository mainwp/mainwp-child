<?php
/**
 * MainWP Child posts handler
 *
 * This file handles all post & post plus actions.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

//phpcs:disable Generic.Metrics.CyclomaticComplexity -- Required to achieve desired results, pull request solutions appreciated.

/**
 * Class MainWP_Child_Posts
 *
 * Handle all post & post plus actions.
 */
class MainWP_Child_Posts {

	/**
	 * Public static variable to hold the single instance of MainWP_Child_Posts.
	 *
	 * @var mixed Default null
	 */
	protected static $instance = null;

	/**
	 * Comments and clauses.
	 *
	 * @var string Comments and clauses.
	 */
	private $comments_and_clauses;

	/**
	 * Posts with given suffix.
	 *
	 * @var string Posts with given suffix.
	 */
	private $posts_where_suffix;

	/**
	 * Get class name.
	 *
	 * @return string __CLASS__ Class name.
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * MainWP_Child_Posts constructor
	 *
	 * Run any time class is called.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Posts::comments_and_clauses()
	 * @uses \MainWP\Child\MainWP_Child_Posts::posts_where_suffix()
	 */
	public function __construct() {
		$this->comments_and_clauses = '';
		$this->posts_where_suffix   = '';
	}

	/**
	 * Create a public static instance of MainWP_Child_Posts.
	 *
	 * @return MainWP_Child_Posts|null
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}


	/**
	 * Get recent posts.
	 *
	 * @param array  $pAllowedStatuses Array of allowed post statuses.
	 * @param int    $pCount Number of posts.
	 * @param string $type Post type.
	 * @param null   $extra Extra tokens.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Posts::get_recent_posts_int()
	 *
	 * @return array $allPost Return array of recent posts.
	 */
	public function get_recent_posts( $pAllowedStatuses, $pCount, $type = 'post', $extra = null ) {
		$allPosts = array();
		if ( null !== $pAllowedStatuses ) {
			foreach ( $pAllowedStatuses as $status ) {
				$this->get_recent_posts_int( $status, $pCount, $type, $allPosts, $extra );
			}
		} else {
			$this->get_recent_posts_int( 'any', $pCount, $type, $allPosts, $extra );
		}

		return $allPosts;
	}

	/**
	 * Initiate get recent posts.
	 *
	 * @param string $status Post status.
	 * @param int    $pCount Number of posts.
	 * @param string $type Post type.
	 * @param array  $allPosts All posts array.
	 * @param null   $extra Extra tokens.
	 *
	 * @uses \WPSEO_Link_Column_Count()
	 * @uses \WPSEO_Meta()
	 * @uses MainWP_WordPress_SEO::instance()::parse_column_score()
	 * @uses MainWP_WordPress_SEO::instance()->parse_column_score_readability()
	 * @uses \MainWP\Child\MainWP_Child_Posts::get_out_post()
	 */
	public function get_recent_posts_int( $status, $pCount, $type, &$allPosts, $extra = null ) {

		$args = array(
			'post_status'      => $status,
			'suppress_filters' => false,
			'post_type'        => $type,
		);

		$tokens = array();
		if ( is_array( $extra ) && isset( $extra['tokens'] ) ) {
			$tokens = $extra['tokens'];
			if ( 1 == $extra['extract_post_type'] ) {
				$args['post_type'] = 'post';
			} elseif ( 2 == $extra['extract_post_type'] ) {
				$args['post_type'] = 'page';
			} elseif ( 3 == $extra['extract_post_type'] ) {
				$args['post_type'] = array( 'post', 'page' );
			}
		}
		$tokens = array_flip( $tokens );

		if ( 0 !== $pCount ) {
			$args['numberposts'] = $pCount;
		}

		$wp_seo_enabled = false;
		if ( ! empty( $_POST['WPSEOEnabled'] ) ) {
			if ( is_plugin_active( 'wordpress-seo/wp-seo.php' ) && class_exists( '\WPSEO_Link_Column_Count' ) && class_exists( '\WPSEO_Meta' ) ) {
				$wp_seo_enabled = true;
			}
		}

		$posts = get_posts( $args );

		if ( is_array( $posts ) ) {
			if ( $wp_seo_enabled ) {
				$post_ids = array();
				foreach ( $posts as $post ) {
					$post_ids[] = $post->ID;
				}

				/**
				* Credits
				*
				* Plugin-Name: Yoast SEO
				* Plugin URI: https://yoast.com/wordpress/plugins/seo/#utm_source=wpadmin&utm_medium=plugin&utm_campaign=wpseoplugin
				* Author: Team Yoast
				* Author URI: https://yoast.com/
				* Licence: GPL v3
				*
				* The code is used for the MainWP WordPress SEO Extension
				* Extension URL: https://mainwp.com/extension/wordpress-seo/
				*/
				$link_count = new \WPSEO_Link_Column_Count();
				$link_count->set( $post_ids );
			}
			foreach ( $posts as $post ) {
				$outPost = $this->get_out_post( $post, $extra, $tokens );
				if ( $wp_seo_enabled ) {
					$outPost['seo_data'] = array(
						'count_seo_links'   => $link_count->get( $post->ID, 'internal_link_count' ),
						'count_seo_linked'  => $link_count->get( $post->ID, 'incoming_link_count' ),
						'seo_score'         => MainWP_WordPress_SEO::instance()->parse_column_score( $post->ID ),
						'readability_score' => MainWP_WordPress_SEO::instance()->parse_column_score_readability( $post->ID ),
					);
				}
				$allPosts[] = $outPost;
			}
		}
	}

	/**
	 * Build Post.
	 *
	 * @param array  $post Post array.
	 * @param string $extra Post date & time.
	 * @param array  $tokens Post tokens.
	 * @return array $outPost Return completed post.
	 */
	private function get_out_post( $post, $extra, $tokens ) {
		$outPost                  = array();
		$outPost['id']            = $post->ID;
		$outPost['post_type']     = $post->post_type;
		$outPost['status']        = $post->post_status;
		$outPost['title']         = $post->post_title;
		$outPost['comment_count'] = $post->comment_count;
		if ( isset( $extra['where_post_date'] ) && ! empty( $extra['where_post_date'] ) ) {
			$outPost['dts'] = strtotime( $post->post_date_gmt );
		} else {
			$outPost['dts'] = strtotime( $post->post_modified_gmt );
		}

		if ( 'page' == $post->post_type ) {
			$outPost['dts'] = strtotime( $post->post_modified_gmt ); // to order by modified date.
		}

		if ( 'future' == $post->post_status ) {
			$outPost['dts'] = strtotime( $post->post_date_gmt );
		}

		$usr                    = get_user_by( 'id', $post->post_author );
		$outPost['author']      = ! empty( $usr ) ? $usr->user_nicename : 'removed';
		$outPost['authorEmail'] = ! empty( $usr ) ? $usr->user_email : 'removed';
		$categoryObjects        = get_the_category( $post->ID );
		$categories             = '';
		foreach ( $categoryObjects as $cat ) {
			if ( '' !== $categories ) {
				$categories .= ', ';
			}
			$categories .= $cat->name;
		}
		$outPost['categories'] = $categories;

		$tagObjects = get_the_tags( $post->ID );
		$tags       = '';
		if ( is_array( $tagObjects ) ) {
			foreach ( $tagObjects as $tag ) {
				if ( '' !== $tags ) {
					$tags .= ', ';
				}
				$tags .= $tag->name;
			}
		}
		$outPost['tags'] = $tags;
		if ( is_array( $tokens ) ) {
			if ( isset( $tokens['[post.url]'] ) ) {
				$outPost['[post.url]'] = get_permalink( $post->ID );
			}
			if ( isset( $tokens['[post.website.url]'] ) ) {
				$outPost['[post.website.url]'] = get_site_url();
			}
			if ( isset( $tokens['[post.website.name]'] ) ) {
				$outPost['[post.website.name]'] = get_bloginfo( 'name' );
			}
		}
		return $outPost;
	}

	/**
	 * Get all posts.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Posts::get_all_posts_by_type()
	 */
	public function get_all_posts() {
		$post_type = ( isset( $_POST['post_type'] ) ? sanitize_text_field( wp_unslash( $_POST['post_type'] ) ) : 'post' );
		$this->get_all_posts_by_type( $post_type );
	}

	/**
	 * Get all pages.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Posts::get_all_posts_by_type()
	 */
	public function get_all_pages() {
		$this->get_all_posts_by_type( 'page' );
	}

	/**
	 * Append the Post's SQL WHERE clause suffix.
	 *
	 * @param string $where Post's SQL WHERE clause.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Posts::posts_where_suffix()
	 *
	 * @return string $where The full SQL WHERE clause with the appended suffix.
	 */
	public function posts_where( $where ) {
		if ( $this->posts_where_suffix ) {
			$where .= ' ' . $this->posts_where_suffix;
		}

		return $where;
	}

	/**
	 * Get all posts by type.
	 *
	 * @param string $type Post type.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Posts::posts_where_suffix()
	 * @uses \MainWP\Child\MainWP_Helper::write()
	 */
	public function get_all_posts_by_type( $type ) {

		/**
		 * Object, providing access to the WordPress database.
		 *
		 * @global object $wpdb WordPress Database instance.
		 */
		global $wpdb;

		add_filter( 'posts_where', array( &$this, 'posts_where' ) );
		$where_post_date = isset( $_POST['where_post_date'] ) && ! empty( $_POST['where_post_date'] ) ? true : false;
		if ( isset( $_POST['postId'] ) ) {
			$this->posts_where_suffix .= $wpdb->prepare( " AND $wpdb->posts.ID = %d ", sanitize_text_field( wp_unslash( $_POST['postId'] ) ) );
		} elseif ( isset( $_POST['userId'] ) ) {
			$this->posts_where_suffix .= $wpdb->prepare( " AND $wpdb->posts.post_author = %d ", sanitize_text_field( wp_unslash( $_POST['userId'] ) ) );
		} else {
			if ( isset( $_POST['keyword'] ) && '' !== $_POST['keyword'] ) {
				$search_on = isset( $_POST['search_on'] ) ? sanitize_text_field( wp_unslash( $_POST['search_on'] ) ) : '';
				if ( 'title' == $search_on ) {
					$this->posts_where_suffix .= $wpdb->prepare( " AND ( $wpdb->posts.post_title LIKE %s ) ", '%' . $wpdb->esc_like( sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) ) . '%' );
				} elseif ( 'content' == $search_on ) {
					$this->posts_where_suffix .= $wpdb->prepare( " AND ( $wpdb->posts.post_content LIKE %s )", '%' . $wpdb->esc_like( sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) ) . '%' );
				} else {
					$this->posts_where_suffix .= $wpdb->prepare( " AND ( $wpdb->posts.post_content LIKE %s OR $wpdb->posts.post_title LIKE  %s )", '%' . $wpdb->esc_like( sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) ) . '%', '%' . $wpdb->esc_like( sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) ) . '%' );
				}
			}
			if ( isset( $_POST['dtsstart'] ) && '' !== $_POST['dtsstart'] ) {
				if ( $where_post_date ) {
					$this->posts_where_suffix .= $wpdb->prepare( " AND $wpdb->posts.post_date > %s", $wpdb->esc_like( sanitize_text_field( wp_unslash( $_POST['dtsstart'] ) ) ) );
				} else {
					$this->posts_where_suffix .= $wpdb->prepare( " AND $wpdb->posts.post_modified > %s", $wpdb->esc_like( sanitize_text_field( wp_unslash( $_POST['dtsstart'] ) ) ) );
				}
			}
			if ( isset( $_POST['dtsstop'] ) && '' !== $_POST['dtsstop'] ) {
				if ( $where_post_date ) {
					$this->posts_where_suffix .= $wpdb->prepare( " AND $wpdb->posts.post_date < %s ", $wpdb->esc_like( sanitize_text_field( wp_unslash( $_POST['dtsstop'] ) ) ) );
				} else {
					$this->posts_where_suffix .= $wpdb->prepare( " AND $wpdb->posts.post_modified < %s", $wpdb->esc_like( sanitize_text_field( wp_unslash( $_POST['dtsstop'] ) ) ) );
				}
			}

			if ( isset( $_POST['exclude_page_type'] ) && wp_unslash( $_POST['exclude_page_type'] ) ) {
				$this->posts_where_suffix .= " AND $wpdb->posts.post_type NOT IN ('page')";
			}
		}

		$maxPages = 50;
		if ( defined( 'MAINWP_CHILD_NR_OF_PAGES' ) ) {
			$maxPages = MAINWP_CHILD_NR_OF_PAGES;
		}

		if ( isset( $_POST['maxRecords'] ) ) {
			$maxPages = ! empty( $_POST['maxRecords'] ) ? intval( wp_unslash( $_POST['maxRecords'] ) ) : 0;
		}
		if ( 0 === $maxPages ) {
			$maxPages = 99999;
		}

		$extra = array();
		if ( isset( $_POST['extract_tokens'] ) ) {
			$extra['tokens']            = isset( $_POST['extract_tokens'] ) ? json_decode( base64_decode( wp_unslash( $_POST['extract_tokens'] ) ), true ) : ''; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
			$extra['extract_post_type'] = isset( $_POST['extract_post_type'] ) ? sanitize_text_field( wp_unslash( $_POST['extract_post_type'] ) ) : '';
		}

		$extra['where_post_date'] = $where_post_date;
		$rslt                     = isset( $_POST['status'] ) ? $this->get_recent_posts( explode( ',', wp_unslash( $_POST['status'] ) ), $maxPages, $type, $extra ) : '';
		$this->posts_where_suffix = '';

		MainWP_Helper::write( $rslt );
	}

	/**
	 * Build New Post.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Posts::create_post()
	 * @uses \MainWP\Child\MainWP_Helper::instance()->error()
	 * @uses \MainWP\Child\MainWP_Helper::write()
	 */
	public function new_post() {
		$new_post            = isset( $_POST['new_post'] ) ? json_decode( base64_decode( wp_unslash( $_POST['new_post'] ) ), true ) : ''; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
		$post_custom         = isset( $_POST['post_custom'] ) ? json_decode( base64_decode( wp_unslash( $_POST['post_custom'] ) ), true ) : ''; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
		$post_category       = isset( $_POST['post_category'] ) ? rawurldecode( base64_decode( wp_unslash( $_POST['post_category'] ) ) ) : null; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
		$post_tags           = isset( $new_post['post_tags'] ) ? rawurldecode( $new_post['post_tags'] ) : null;
		$post_featured_image = isset( $_POST['post_featured_image'] ) && ! empty( $_POST['post_featured_image'] ) ? base64_decode( wp_unslash( $_POST['post_featured_image'] ) ) : ''; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
		$upload_dir          = isset( $_POST['mainwp_upload_dir'] ) ? json_decode( base64_decode( wp_unslash( $_POST['mainwp_upload_dir'] ) ), true ) : ''; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..

		$others = array();
		if ( isset( $_POST['featured_image_data'] ) ) {
			$others['featured_image_data'] = ! empty( $_POST['featured_image_data'] ) ? json_decode( base64_decode( wp_unslash( $_POST['featured_image_data'] ) ), true ) : ''; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
		}

		$res = $this->create_post( $new_post, $post_custom, $post_category, $post_featured_image, $upload_dir, $post_tags, $others );

		if ( is_array( $res ) && isset( $res['error'] ) ) {
			MainWP_Helper::instance()->error( $res['error'] );
		}

		$created = $res['success'];
		if ( true !== $created ) {
			MainWP_Helper::instance()->error( 'Undefined error' );
		}

		$information['added']    = true;
		$information['added_id'] = $res['added_id'];
		$information['link']     = $res['link'];

		do_action( 'mainwp_child_after_newpost', $res );

		MainWP_Helper::write( $information );
	}

	/**
	 * Post Action.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Links_Checker()
	 * @uses \MainWP\Child\MainWP_Child_Posts::get_post_edit()
	 * @uses \MainWP\Child\MainWP_Child_Posts::get_page_edit()
	 * @uses \MainWP\Child\MainWP_Helper::write()
	 * @uses \MainWP\Child\MainWP_Child_Links_Checker::get_class_name()
	 */
	public function post_action() {
		$action  = ! empty( $_POST['action'] ) ? sanitize_text_field( wp_unslash( $_POST['action'] ) ) : '';
		$postId  = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		$my_post = array();

		if ( 'publish' === $action ) {
			$post_current = get_post( $postId );
			if ( empty( $post_current ) ) {
				$information['status'] = 'FAIL';
			} else {
				if ( 'future' == $post_current->post_status ) {
					wp_publish_post( $postId );
					wp_update_post(
						array(
							'ID'            => $postId,
							'post_date'     => current_time( 'mysql', false ),
							'post_date_gmt' => current_time( 'mysql', true ),
						)
					);
				} else {
					wp_update_post(
						array(
							'ID'          => $postId,
							'post_status' => 'publish',
						)
					);
				}
			}
		} elseif ( 'update' === $action ) {
			$postData = isset( $_POST['post_data'] ) ? wp_unslash( $_POST['post_data'] ) : array();
			$my_post  = is_array( $postData ) ? $postData : array();
			wp_update_post( $my_post );
		} elseif ( 'unpublish' === $action ) {
			$my_post['ID']          = $postId;
			$my_post['post_status'] = 'draft';
			wp_update_post( $my_post );
		} elseif ( 'trash' === $action ) {
			add_action( 'trash_post', array( MainWP_Child_Links_Checker::get_class_name(), 'hook_post_deleted' ) );
			wp_trash_post( $postId );
		} elseif ( 'delete' === $action ) {
			add_action( 'delete_post', array( MainWP_Child_Links_Checker::get_class_name(), 'hook_post_deleted' ) );
			wp_delete_post( $postId, true );
		} elseif ( 'restore' === $action ) {
			wp_untrash_post( $postId );
		} elseif ( 'update_meta' === $action ) {
			$values     = isset( $_POST['values'] ) ? json_decode( base64_decode( wp_unslash( $_POST['values'] ) ), true ) : array(); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
			$meta_key   = $values['meta_key'];
			$meta_value = $values['meta_value'];
			$check_prev = $values['check_prev'];

			foreach ( $meta_key as $i => $key ) {
				if ( 1 === intval( $check_prev[ $i ] ) ) {
					update_post_meta( $postId, $key, get_post_meta( $postId, $key, true ) ? get_post_meta( $postId, $key, true ) : $meta_value[ $i ] );
				} else {
					update_post_meta( $postId, $key, $meta_value[ $i ] );
				}
			}
		} elseif ( 'get_edit' === $action ) {
			$postId    = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
			$post_type = isset( $_POST['post_type'] ) ? sanitize_text_field( wp_unslash( $_POST['post_type'] ) ) : '';
			if ( 'post' == $post_type ) {
				$my_post = $this->get_post_edit( $postId );
			} else {
				$my_post = $this->get_page_edit( $postId );
			}
		} else {
			$information['status'] = 'FAIL';
		}

		if ( ! isset( $information['status'] ) ) {
			$information['status'] = 'SUCCESS';
		}
		$information['my_post'] = $my_post;
		MainWP_Helper::write( $information );
	}

	/**
	 * Get post edit data.
	 *
	 * @param string $id Post ID.
	 *
	 * @return array|bool Return $post_data or FALSE on failure.
	 */
	private function get_post_edit( $id ) {
		$post = get_post( $id );
		if ( $post ) {
			$categoryObjects = get_the_category( $post->ID );
			$categories      = '';
			foreach ( $categoryObjects as $cat ) {
				if ( '' !== $categories ) {
					$categories .= ', ';
				}
				$categories .= $cat->name;
			}
			$post_category = $categories;

			$tagObjects = get_the_tags( $post->ID );
			$tags       = '';
			if ( is_array( $tagObjects ) ) {
				foreach ( $tagObjects as $tag ) {
					if ( '' !== $tags ) {
						$tags .= ', ';
					}
					$tags .= $tag->name;
				}
			}
			$post_tags = $tags;

			$post_custom = get_post_custom( $id );

			$galleries           = get_post_gallery( $id, false );
			$post_gallery_images = array();

			if ( is_array( $galleries ) && isset( $galleries['ids'] ) ) {
				$attached_images = explode( ',', $galleries['ids'] );
				foreach ( $attached_images as $attachment_id ) {
					$attachment = get_post( $attachment_id );
					if ( $attachment ) {
						$post_gallery_images[] = array(
							'id'          => $attachment_id,
							'alt'         => get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ),
							'caption'     => $attachment->post_excerpt,
							'description' => $attachment->post_content,
							'src'         => $attachment->guid,
							'title'       => $attachment->post_title,
						);
					}
				}
			}

			include_once ABSPATH . 'wp-includes' . DIRECTORY_SEPARATOR . 'post-thumbnail-template.php';
			$post_featured_image = get_post_thumbnail_id( $id );
			$child_upload_dir    = wp_upload_dir();
			$new_post            = array(
				'edit_id'        => $id,
				'is_sticky'      => is_sticky( $id ) ? 1 : 0,
				'post_title'     => $post->post_title,
				'post_content'   => $post->post_content,
				'post_status'    => $post->post_status,
				'post_date'      => $post->post_date,
				'post_date_gmt'  => $post->post_date_gmt,
				'post_tags'      => $post_tags,
				'post_name'      => $post->post_name,
				'post_excerpt'   => $post->post_excerpt,
				'comment_status' => $post->comment_status,
				'ping_status'    => $post->ping_status,
				'post_type'      => $post->post_type,
				'post_password'  => $post->post_password,
			);

			if ( null != $post_featured_image ) { // Featured image is set, retrieve URL.
				$img                 = wp_get_attachment_image_src( $post_featured_image, 'full' );
				$post_featured_image = $img[0];
			}

			require_once ABSPATH . 'wp-admin/includes/post.php';
			wp_set_post_lock( $id );

			// prepare $post_custom values.
			$new_post_custom = array();
			foreach ( $post_custom as $meta_key => $meta_values ) {
				$new_meta_values = array();
				foreach ( $meta_values as $key_value => $meta_value ) {
					if ( is_serialized( $meta_value ) ) {
						$meta_value = unserialize( $meta_value ); // phpcs:ignore --  safe internal value.
					}
					$new_meta_values[ $key_value ] = $meta_value;
				}
				$new_post_custom[ $meta_key ] = $new_meta_values;
			}

			$post_data = array(
				'new_post'            => base64_encode( wp_json_encode( $new_post ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
				'post_custom'         => base64_encode( wp_json_encode( $new_post_custom ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
				'post_category'       => base64_encode( $post_category ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
				'post_featured_image' => base64_encode( $post_featured_image ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
				'post_gallery_images' => base64_encode( wp_json_encode( $post_gallery_images ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
				'child_upload_dir'    => base64_encode( wp_json_encode( $child_upload_dir ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
			);
			return $post_data;

		}
		return false;
	}

	/**
	 * Get page edit data.
	 *
	 * @param string $id Page ID.
	 *
	 * @return array|bool Return $post_data or FALSE on failure.
	 */
	private function get_page_edit( $id ) {
		$post = get_post( $id );
		if ( $post ) {
			$post_custom = get_post_custom( $id );
			include_once ABSPATH . 'wp-includes' . DIRECTORY_SEPARATOR . 'post-thumbnail-template.php';
			$post_featured_image = get_post_thumbnail_id( $id );
			$child_upload_dir    = wp_upload_dir();

			$new_post = array(
				'edit_id'        => $id,
				'post_title'     => $post->post_title,
				'post_content'   => $post->post_content,
				'post_status'    => $post->post_status,
				'post_date'      => $post->post_date,
				'post_date_gmt'  => $post->post_date_gmt,
				'post_type'      => 'page',
				'post_name'      => $post->post_name,
				'post_excerpt'   => $post->post_excerpt,
				'comment_status' => $post->comment_status,
				'ping_status'    => $post->ping_status,
				'post_password'  => $post->post_password,
			);

			if ( null != $post_featured_image ) {
					$img                 = wp_get_attachment_image_src( $post_featured_image, 'full' );
					$post_featured_image = $img[0];
			}

			$galleries           = get_post_gallery( $id, false );
			$post_gallery_images = array();

			if ( is_array( $galleries ) && isset( $galleries['ids'] ) ) {
				$attached_images = explode( ',', $galleries['ids'] );
				foreach ( $attached_images as $attachment_id ) {
					$attachment = get_post( $attachment_id );
					if ( $attachment ) {
						$post_gallery_images[] = array(
							'id'          => $attachment_id,
							'alt'         => get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ),
							'caption'     => $attachment->post_excerpt,
							'description' => $attachment->post_content,
							'src'         => $attachment->guid,
							'title'       => $attachment->post_title,
						);
					}
				}
			}

			require_once ABSPATH . 'wp-admin/includes/post.php';
			wp_set_post_lock( $id );

			// prepare $post_custom values.
			$new_post_custom = array();
			foreach ( $post_custom as $meta_key => $meta_values ) {
				$new_meta_values = array();
				foreach ( $meta_values as $key_value => $meta_value ) {
					if ( is_serialized( $meta_value ) ) {
						$meta_value = unserialize( $meta_value ); // phpcs:ignore -- safe internal value.
					}
					$new_meta_values[ $key_value ] = $meta_value;
				}
				$new_post_custom[ $meta_key ] = $new_meta_values;
			}

			$post_data = array(
				'new_post'            => base64_encode( wp_json_encode( $new_post ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
				'post_custom'         => base64_encode( wp_json_encode( $new_post_custom ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
				'post_featured_image' => base64_encode( $post_featured_image ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
				'post_gallery_images' => base64_encode( wp_json_encode( $post_gallery_images ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
				'child_upload_dir'    => base64_encode( wp_json_encode( $child_upload_dir ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
			);
			return $post_data;
		}
		return false;
	}

	/**
	 * Create new post.
	 *
	 * @param array  $new_post            Post data array.
	 * @param array  $post_custom         Post custom meta data.
	 * @param string $post_category       Post categories.
	 * @param string $post_featured_image Post featured image.
	 * @param string $upload_dir          Upload directory.
	 * @param string $post_tags           Post tags.
	 * @param array  $others              Other data.
	 *
	 * @return array|string[] $ret Return success array, permalink & Post ID.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Posts::set_post_custom_data()
	 * @uses \MainWP\Child\MainWP_Child_Posts::update_found_images()
	 * @uses \MainWP\Child\MainWP_Child_Posts::create_has_shortcode_gallery()
	 * @uses \MainWP\Child\MainWP_Child_Posts::create_post_plus()
	 * @uses \MainWP\Child\MainWP_Child_Posts::update_post_data()
	 */
	private function create_post(
		$new_post,
		$post_custom,
		$post_category,
		$post_featured_image,
		$upload_dir,
		$post_tags,
		$others = array()
		) {

		/**
		* Hook: `mainwp_before_post_update`
		*
		* Runs before creating or updating a post via MainWP dashboard.
		*
		* @param array  $new_post      � Post data array.
		* @param array  $post_custom   � Post custom meta data.
		* @param string $post_category � Post categories.
		* @param string $post_tags     � Post tags.
		*/
		do_action( 'mainwp_before_post_update', $new_post, $post_custom, $post_category, $post_tags, $others );

		$edit_post_id = 0;
		$is_post_plus = false;

		$this->set_post_custom_data( $new_post, $post_custom, $post_tags, $edit_post_id, $is_post_plus, $others );

		require_once ABSPATH . 'wp-admin/includes/post.php';

		if ( $edit_post_id ) {
			$user_id = wp_check_post_lock( $edit_post_id );
			if ( $user_id ) {
				$user  = get_userdata( $user_id );
				$error = sprintf( esc_html__( 'This content is currently locked. %s is currently editing.', 'mainwp-child' ), $user->display_name );
				return array( 'error' => $error );
			}
		}

		// if editing post then will check if image existed.
		$check_image_existed = $edit_post_id ? true : false;

		$this->update_found_images( $new_post, $upload_dir, $check_image_existed );
		$this->create_has_shortcode_gallery( $new_post );

		if ( $is_post_plus ) {
			$this->create_post_plus( $new_post, $post_custom );
		}

		if ( isset( $post_custom['_mainwp_replace_advance_img'] ) && true == $post_custom['_mainwp_replace_advance_img'][0] ) {
			$new_post['post_content'] = self::replace_advanced_image( $new_post['post_content'], $upload_dir );
			$new_post['post_content'] = self::replace_advanced_image( $new_post['post_content'], $upload_dir, true ); // to fix images url with slashes.
			unset( $post_custom['_mainwp_replace_advance_img'] );
		}

		// Save the post to the WP.
		remove_filter( 'content_save_pre', 'wp_filter_post_kses' );  // to fix brake scripts or html.
		$post_status             = $new_post['post_status']; // save post_status.
		$new_post['post_status'] = 'auto-draft'; // to fix reports, to log as created post.

		// Update post.
		if ( $edit_post_id ) {
			// check if post existed.
			$current_post = get_post( $edit_post_id );
			if ( $current_post && ( ( ! isset( $new_post['post_type'] ) && 'post' == $current_post->post_type ) || ( isset( $new_post['post_type'] ) && $new_post['post_type'] == $current_post->post_type ) ) ) {
				$new_post['ID'] = $edit_post_id;
			}
			$new_post['post_status'] = $post_status; // child reports: to logging as update post.
		}
		$wp_error = false;
		if ( $edit_post_id ) {
			$new_post_id = wp_update_post( $new_post, $wp_error ); // to fix: update post.
		} else {
			$new_post_id = wp_insert_post( $new_post, $wp_error ); // insert post.
		}
		// Show errors if something went wrong.
		if ( is_wp_error( $wp_error ) ) {
			return $wp_error->get_error_message();
		}
		if ( empty( $new_post_id ) ) {
			return array( 'error' => 'Empty post id' );
		}

		if ( ! $edit_post_id ) {
			wp_update_post(
				array(
					'ID'          => $new_post_id,
					'post_status' => $post_status,
				)
			);
		}

		if ( is_array( $post_custom ) && isset( $post_custom['_mainwp_edit_post_save_to_post_type'] ) ) {
			$saved_post_type = $post_custom['_mainwp_edit_post_save_to_post_type'];
			$saved_post_type = is_array( $saved_post_type ) ? current( $saved_post_type ) : $saved_post_type;
			if ( ! empty( $saved_post_type ) ) {
				wp_update_post(
					array(
						'ID'        => $new_post_id,
						'post_type' => $saved_post_type,
					)
				);
			}
			unset( $post_custom['_mainwp_edit_post_save_to_post_type'] );
		}

		$this->update_post_data( $new_post_id, $post_custom, $post_category, $post_featured_image, $check_image_existed, $is_post_plus, $others );

		// unlock if edit post.
		if ( $edit_post_id ) {
			update_post_meta( $edit_post_id, '_edit_lock', '' );
		}
		$permalink       = get_permalink( $new_post_id );
		$ret['success']  = true;
		$ret['link']     = $permalink;
		$ret['added_id'] = $new_post_id;
		return $ret;
	}

	/**
	 * Set custom post data.
	 *
	 * @param array  $new_post            Post data array.
	 * @param array  $post_custom         Post custom meta data.
	 * @param string $post_tags           Post tags.
	 * @param string $edit_post_id        Edit Post ID.
	 * @param bool   $is_post_plus        TRUE|FALSE, Whether or not this came from MainWP Post Plus Extension.
	 * @param array  $others        Post custom others meta data.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Posts::update_wp_rocket_custom_post()
	 */
	private function set_post_custom_data( &$new_post, $post_custom, $post_tags, &$edit_post_id, &$is_post_plus, $others ) {

		/**
		 * Current user global.
		 *
		 * @global string
		 */
		global $current_user;

		$this->update_wp_rocket_custom_post( $post_custom );

		// current user may be connected admin or alternative admin.
		$current_uid = $current_user->ID;

		// Set up a new post (adding additional information).
		$new_post['post_author'] = isset( $new_post['post_author'] ) && ! empty( $new_post['post_author'] ) ? $new_post['post_author'] : $current_uid;

		if ( isset( $new_post['post_title'] ) ) {
			$new_post['post_title'] = MainWP_Utility::esc_content( $new_post['post_title'], 'mixed' );
		}

		if ( isset( $new_post['post_excerpt'] ) ) {
			$new_post['post_excerpt'] = MainWP_Utility::esc_content( $new_post['post_excerpt'], 'mixed' );
		}

		if ( isset( $new_post['custom_post_author'] ) && ! empty( $new_post['custom_post_author'] ) ) {
			$_author = get_user_by( 'login', $new_post['custom_post_author'] );
			if ( ! empty( $_author ) ) {
				$new_post['post_author'] = $_author->ID;
			}
			unset( $new_post['custom_post_author'] );
		}

		// post plus extension process.
		$is_post_plus = isset( $post_custom['_mainwp_post_plus'] ) ? true : false;

		if ( $is_post_plus ) {
			if ( isset( $new_post['post_date_gmt'] ) && ! empty( $new_post['post_date_gmt'] ) && '0000-00-00 00:00:00' != $new_post['post_date_gmt'] ) {
				$post_date_timestamp   = strtotime( $new_post['post_date_gmt'] ) + get_option( 'gmt_offset' ) * 60 * 60;
				$new_post['post_date'] = date( 'Y-m-d H:i:s', $post_date_timestamp ); // phpcs:ignore -- local time.
			}
		}

		if ( isset( $post_tags ) && '' != $post_tags ) {
			$new_post['tags_input'] = $post_tags;
		}

		if ( isset( $post_custom['_mainwp_edit_post_id'] ) && $post_custom['_mainwp_edit_post_id'] ) {
			$edit_post_id = current( $post_custom['_mainwp_edit_post_id'] );
		} elseif ( isset( $new_post['ID'] ) && $new_post['ID'] ) {
			$edit_post_id = $new_post['ID'];
		}
	}

	/**
	 * Update post data.
	 *
	 * @param string $new_post_id         New post ID.
	 * @param array  $post_custom         Post custom meta data.
	 * @param string $post_category       Post categories.
	 * @param string $post_featured_image Post featured image.
	 * @param bool   $check_image_existed TRUE|FALSE, Whether or not featured image already exists.
	 * @param bool   $is_post_plus        TRUE|FALSE, Whether or not this came from MainWP Post Plus Extension.
	 * @param array  $others        Others data.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Posts::set_custom_post_fields()
	 * @uses \MainWP\Child\MainWP_Child_Posts::update_seo_meta()
	 * @uses \MainWP\Child\MainWP_Child_Posts::create_set_categories()
	 * @uses \MainWP\Child\MainWP_Child_Posts::create_featured_image()
	 * @uses \MainWP\Child\MainWP_Child_Posts::post_plus_update_author()
	 * @uses \MainWP\Child\MainWP_Child_Posts::post_plus_update_categories()
	 */
	private function update_post_data( $new_post_id, $post_custom, $post_category, $post_featured_image, $check_image_existed, $is_post_plus, $others ) {

		$seo_ext_activated = false;
		if ( class_exists( '\WPSEO_Meta' ) && class_exists( '\WPSEO_Admin' ) ) {
			$seo_ext_activated = true;
		}

		$post_to_only_existing_categories = false;

		$this->set_custom_post_fields( $new_post_id, $post_custom, $seo_ext_activated, $post_to_only_existing_categories );

		// yoast seo plugin activated.
		if ( $seo_ext_activated ) {
			$this->update_seo_meta( $new_post_id, $post_custom );
		}

		$this->create_set_categories( $new_post_id, $post_category, $post_to_only_existing_categories );
		$this->create_featured_image( $new_post_id, $post_featured_image, true, $others ); // always checks featured img.

		// post plus extension process.
		if ( $is_post_plus ) {
			$this->post_plus_update_author( $new_post_id, $post_custom );
			$this->post_plus_update_categories( $new_post_id, $post_custom );
		}

		// to support custom post author.
		$custom_post_author = apply_filters( 'mainwp_create_post_custom_author', false, $new_post_id );
		if ( ! empty( $custom_post_author ) ) {
			wp_update_post(
				array(
					'ID'          => $new_post_id,
					'post_author' => $custom_post_author,
				)
			);
		}
	}

	/**
	 * Update WPRocket custom post.
	 *
	 * @param array $post_custom Post custom meta data.
	 *
	 * @uses \get_rocket_option()
	 * @see https://github.com/wp-media/wp-rocket/blob/master/inc/functions/options.php
	 *
	 * @uses \MainWP\Child\MainWP_Child_WP_Rocket::instance()::is_activated()
	 * @uses \MainWP\Child\MainWP_Child_WP_Rocket::is_activated()
	 */
	private function update_wp_rocket_custom_post( &$post_custom ) {
		// Options fields.
		$wprocket_fields = array(
			'lazyload',
			'lazyload_iframes',
			'minify_html',
			'minify_css',
			'minify_js',
			'cdn',
			'async_css',
			'defer_all_js',
		);

		$wprocket_activated = false;
		if ( MainWP_Child_WP_Rocket::instance()->is_activated() ) {
			if ( function_exists( '\get_rocket_option' ) ) {
				$wprocket_activated = true;
				foreach ( $wprocket_fields as $field ) {
					if ( ! isset( $post_custom[ '_rocket_exclude_' . $field ] ) ) {
						if ( ! \get_rocket_option( $field ) ) {
							$post_custom[ '_rocket_exclude_' . $field ] = array( true );
						}
					}
				}
			}
		}
		if ( ! $wprocket_activated ) {
			foreach ( $wprocket_fields as $field ) {
				if ( isset( $post_custom[ '_rocket_exclude_' . $field ] ) ) {
					unset( $post_custom[ '_rocket_exclude_' . $field ] );
				}
			}
		}
	}

	/**
	 * Search for all the images added to the new post.
	 *
	 * @param array  $new_post            Post data array.
	 * @param string $upload_dir          Upload directory.
	 * @param bool   $check_image_existed TRUE|FALSE, Whether or not featured image already exists.
	 *
	 * @uses \MainWP\Child\MainWP_Utility::upload_image()
	 * @uses \MainWP\Child\MainWP_Helper::log_debug()
	 */
	private function update_found_images( &$new_post, $upload_dir, $check_image_existed ) {

		// Some images have a href tag to click to navigate to the image.. we need to replace this too.
		$foundMatches = preg_match_all( '/(<a[^>]+href=\"(.*?)\"[^>]*>)?(<img[^>\/]*src=\"((.*?)(png|gif|jpg|jpeg))\")/ix', $new_post['post_content'], $matches, PREG_SET_ORDER );
		if ( $foundMatches > 0 ) {
			// We found images, now to download them so we can start balbal.
			foreach ( $matches as $match ) {
				$hrefLink = $match[2];
				$imgUrl   = $match[4];

				if ( ! isset( $upload_dir['baseurl'] ) || ( false === strripos( $imgUrl, $upload_dir['baseurl'] ) ) ) { // url of image is not in dashboard site.
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
					$downloadfile      = MainWP_Utility::upload_image( $originalImgUrl, array(), $check_image_existed );
					$localUrl          = $downloadfile['url'];
					$linkToReplaceWith = dirname( $localUrl );
					if ( '' !== $hrefLink ) {
						$server     = get_option( 'mainwp_child_server' );
						$serverHost = wp_parse_url( $server, PHP_URL_HOST );
						if ( ! empty( $serverHost ) && strpos( $hrefLink, $serverHost ) !== false ) {
							$serverHref               = 'href="' . $serverHost;
							$replaceServerHref        = 'href="' . wp_parse_url( $localUrl, PHP_URL_SCHEME ) . '://' . wp_parse_url( $localUrl, PHP_URL_HOST );
							$new_post['post_content'] = str_replace( $serverHref, $replaceServerHref, $new_post['post_content'] );
						}
					}
					$lnkToReplace = dirname( $imgUrl );
					if ( 'http:' !== $lnkToReplace && 'https:' !== $lnkToReplace ) {
						$new_post['post_content'] = str_replace( $imgUrl, $localUrl, $new_post['post_content'] ); // replace src image.
						$new_post['post_content'] = str_replace( $lnkToReplace, $linkToReplaceWith, $new_post['post_content'] );
					}
				} catch ( \Exception $e ) {
					MainWP_Helper::log_debug( $e->getMessage() );
				}
			}
		}
	}

	/**
	 * Method replace_advanced_image()
	 *
	 * Handle upload advanced image.
	 *
	 * @param array $content post content data.
	 * @param array $upload_dir upload directory info.
	 * @param bool  $withslashes to use preg pattern with slashes.
	 *
	 * @return mixed array of result.
	 */
	public static function replace_advanced_image( $content, $upload_dir, $withslashes = false ) {

		if ( empty( $upload_dir ) || ! isset( $upload_dir['baseurl'] ) ) {
			return $content;
		}

		$dashboard_url        = get_option( 'mainwp_child_server' );
		$site_url_destination = get_site_url();

		// to fix url with slashes.
		if ( $withslashes ) {
			$site_url_destination = str_replace( '/', '\/', $site_url_destination );
			$dashboard_url        = str_replace( '/', '\/', $dashboard_url );
		}

		$foundMatches = preg_match_all( '#(' . preg_quote( $site_url_destination, null ) . ')[^\.]*(\.(png|gif|jpg|jpeg))#ix', $content, $matches, PREG_SET_ORDER );

		if ( 0 < $foundMatches ) {

			$matches_checked = array();
			$check_double    = array();
			foreach ( $matches as $match ) {
				// to avoid double images.
				if ( ! in_array( $match[0], $check_double ) ) {
					$check_double[]    = $match[0];
					$matches_checked[] = $match;
				}
			}
			foreach ( $matches_checked as $match ) {

				$imgUrl = $match[0];
				if ( false === strripos( wp_unslash( $imgUrl ), $upload_dir['baseurl'] ) ) {
					continue;
				}

				if ( preg_match( '/-\d{3}x\d{3}\.[a-zA-Z0-9]{3,4}$/', $imgUrl, $imgMatches ) ) {
					$search         = $imgMatches[0];
					$replace        = '.' . $match[3];
					$originalImgUrl = str_replace( $search, $replace, $imgUrl );
				} else {
					$originalImgUrl = $imgUrl;
				}

				try {
					$downloadfile      = MainWP_Utility::upload_image( wp_unslash( $originalImgUrl ), array(), true );
					$localUrl          = $downloadfile['url'];
					$linkToReplaceWith = dirname( $localUrl );
					$lnkToReplace      = dirname( $imgUrl );
					if ( 'http:' !== $lnkToReplace && 'https:' !== $lnkToReplace ) {
						$content = str_replace( $imgUrl, $localUrl, $content ); // replace src image.
						$content = str_replace( $lnkToReplace, $linkToReplaceWith, $content );
					}
				} catch ( \Exception $e ) {
					// ok.
				}
			}
			if ( false === strripos( $dashboard_url, $site_url_destination ) ) {
				// replace other images src outside dashboard upload folder.
				$content = str_replace( $dashboard_url, $site_url_destination, $content );
			}
		}
		return $content;
	}

	/**
	 * Create shortcode image gallery.
	 *
	 * @param array $new_post Post data array.
	 *
	 * @uses \MainWP\Child\MainWP_Utility::upload_image()
	 * @uses \Exception()
	 */
	private function create_has_shortcode_gallery( &$new_post ) {

		if ( has_shortcode( $new_post['post_content'], 'gallery' ) ) {
			if ( preg_match_all( '/\[gallery[^\]]+ids=\"(.*?)\"[^\]]*\]/ix', $new_post['post_content'], $matches, PREG_SET_ORDER ) ) {
				$replaceAttachedIds = array();
				if ( isset( $_POST['post_gallery_images'] ) ) {
					$post_gallery_images = isset( $_POST['post_gallery_images'] ) ? json_decode( base64_decode( wp_unslash( $_POST['post_gallery_images'] ) ), true ) : ''; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
					if ( is_array( $post_gallery_images ) ) {
						foreach ( $post_gallery_images as $gallery ) {
							if ( isset( $gallery['src'] ) ) {
								try {
									$upload = MainWP_Utility::upload_image( $gallery['src'], $gallery ); // Upload image to WP.
									if ( null !== $upload ) {
										$replaceAttachedIds[ $gallery['id'] ] = $upload['id'];
									}
								} catch ( \Exception $e ) {
									// ok!
								}
							}
						}
					}
				}
				if ( count( $replaceAttachedIds ) > 0 ) {
					foreach ( $matches as $match ) {
						$idsToReplace     = $match[1];
						$idsToReplaceWith = '';
						$originalIds      = explode( ',', $idsToReplace );
						foreach ( $originalIds as $attached_id ) {
							if ( ! empty( $originalIds ) && isset( $replaceAttachedIds[ $attached_id ] ) ) {
								$idsToReplaceWith .= $replaceAttachedIds[ $attached_id ] . ',';
							}
						}
						$idsToReplaceWith = rtrim( $idsToReplaceWith, ',' );
						if ( ! empty( $idsToReplaceWith ) ) {
							$new_post['post_content'] = str_replace( '"' . $idsToReplace . '"', '"' . $idsToReplaceWith . '"', $new_post['post_content'] );
						}
					}
				}
			}
		}
	}

	/**
	 * Create post plus post.
	 *
	 * @param array $new_post    Post data array.
	 * @param array $post_custom Post custom meta data.
	 */
	private function create_post_plus( &$new_post, $post_custom ) {
		$random_publish_date = isset( $post_custom['_saved_draft_random_publish_date'] ) ? $post_custom['_saved_draft_random_publish_date'] : false;
		$random_publish_date = is_array( $random_publish_date ) ? current( $random_publish_date ) : null;

		if ( ! empty( $random_publish_date ) ) {
			$random_date_from = isset( $post_custom['_saved_draft_publish_date_from'] ) ? $post_custom['_saved_draft_publish_date_from'] : 0;
			$random_date_from = is_array( $random_date_from ) ? current( $random_date_from ) : 0;

			$random_date_to = isset( $post_custom['_saved_draft_publish_date_to'] ) ? $post_custom['_saved_draft_publish_date_to'] : 0;
			$random_date_to = is_array( $random_date_to ) ? current( $random_date_to ) : 0;

			$now = time();

			if ( empty( $random_date_from ) ) {
				$random_date_from = $now;
			}

			if ( empty( $random_date_to ) ) {
				$random_date_to = $now;
			}

			if ( $random_date_from === $now && $random_date_from === $random_date_to ) {
				$random_date_to = $now + 7 * 24 * 3600;
			}

			if ( $random_date_from > $random_date_to ) {
				$tmp              = $random_date_from;
				$random_date_from = $random_date_to;
				$random_date_to   = $tmp;
			}

			$random_timestamp      = wp_rand( $random_date_from, $random_date_to );
			$new_post['post_date'] = date( 'Y-m-d H:i:s', $random_timestamp ); // phpcs:ignore -- local time.
		}
	}

	/**
	 * Update post plus author.
	 *
	 * @param string $new_post_id New post ID.
	 * @param array  $post_custom Post custom meta data.
	 */
	private function post_plus_update_author( $new_post_id, $post_custom ) {
		$random_privelege      = isset( $post_custom['_saved_draft_random_privelege'] ) ? $post_custom['_saved_draft_random_privelege'] : null;
		$random_privelege      = is_array( $random_privelege ) ? current( $random_privelege ) : null;
		$random_privelege_base = base64_decode( $random_privelege ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
		$random_privelege      = json_decode( $random_privelege_base, true );

		if ( is_array( $random_privelege ) && count( $random_privelege ) > 0 ) {
			$random_post_authors = array();
			foreach ( $random_privelege as $role ) {
				$users = get_users( array( 'role' => $role ) );
				foreach ( $users as $user ) {
					$random_post_authors[] = $user->ID;
				}
			}
			if ( count( $random_post_authors ) > 0 ) {
				shuffle( $random_post_authors );
				$key = array_rand( $random_post_authors );
				wp_update_post(
					array(
						'ID'          => $new_post_id,
						'post_author' => $random_post_authors[ $key ],
					)
				);
			}
		}
	}

	/**
	 * Update post plus categories.
	 *
	 * @param string $new_post_id New post ID.
	 * @param array  $post_custom Post custom meta data.
	 */
	private function post_plus_update_categories( $new_post_id, $post_custom ) {
		$random_category = isset( $post_custom['_saved_draft_random_category'] ) ? $post_custom['_saved_draft_random_category'] : false;
		$random_category = is_array( $random_category ) ? current( $random_category ) : null;
		if ( ! empty( $random_category ) ) {
			$cats        = get_categories(
				array(
					'type'       => 'post',
					'hide_empty' => 0,
				)
			);
			$random_cats = array();
			if ( is_array( $cats ) ) {
				foreach ( $cats as $cat ) {
					$random_cats[] = $cat->term_id;
				}
			}
			if ( count( $random_cats ) > 0 ) {
				shuffle( $random_cats );
				$key = array_rand( $random_cats );
				wp_set_post_categories( $new_post_id, array( $random_cats[ $key ] ), false );
			}
		}
	}

	/**
	 * Create new and set categories.
	 *
	 * @param string $new_post_id   New post ID.
	 * @param string $post_category Post category.
	 * @param bool   $post_to_only  TRUE|FALSE, Whether or not to post only to this category.
	 *
	 * @uses wp_create_categories() Create categories for the given post.
	 * @see https://developer.wordpress.org/reference/functions/wp_create_categories/
	 *
	 * @uses wp_set_post_categories() Set categories for a post.
	 * @see https://developer.wordpress.org/reference/functions/wp_set_post_categories/
	 */
	private function create_set_categories( $new_post_id, $post_category, $post_to_only ) {

		// If categories exist, create them (second parameter of wp_create_categories adds the categories to the post).
		include_once ABSPATH . 'wp-admin/includes/taxonomy.php'; // Contains wp_create_categories.
		if ( isset( $post_category ) && '' !== $post_category ) {
			$categories = explode( ',', $post_category );
			if ( count( $categories ) > 0 ) {
				if ( ! $post_to_only ) {
					$post_category = wp_create_categories( $categories, $new_post_id );
				} else {
					$cat_ids = array();
					foreach ( $categories as $cat ) {
						$id = category_exists( $cat );
						if ( $id ) {
							$cat_ids[] = $id;
						}
					}
					if ( count( $cat_ids ) > 0 ) {
						wp_set_post_categories( $new_post_id, $cat_ids );
					}
				}
			}
		}
	}

	/**
	 * Set custom post fields.
	 *
	 * @param string $new_post_id       New post ID.
	 * @param array  $post_custom       Post custom meta data.
	 * @param bool   $seo_ext_activated TRUE|FALSE, Whether or not Yoast SEO is activateed or not.
	 * @param bool   $post_to_only      TRUE|FALSE, Whether or not to post only to this category.
	 */
	private function set_custom_post_fields( $new_post_id, $post_custom, $seo_ext_activated, &$post_to_only ) {

		// Set custom fields.
		$not_allowed = array(
			'_slug',
			'_tags',
			'_edit_lock',
			'_selected_sites',
			'_selected_groups',
			'_selected_by',
			'_categories',
			'_edit_last',
			'_sticky',
			'_mainwp_post_dripper',
			'_bulkpost_do_not_del',
			'_mainwp_spin_me',
			'_mainwp_boilerplate_sites_posts',
			'_mainwp_boilerplate',
			'_mainwp_post_plus',
			'_saved_as_draft',
			'_saved_draft_categories',
			'_saved_draft_tags',
			'_saved_draft_random_privelege',
			'_saved_draft_random_category',
			'_saved_draft_random_publish_date',
			'_saved_draft_publish_date_from',
			'_saved_draft_publish_date_to',
			'_post_to_only_existing_categories',
			'_mainwp_edit_post_site_id',
			'_mainwp_edit_post_id',
			'_edit_post_status',
			'_mainwp_edit_post_type',
			'_mainwp_edit_post_status',
			'_mainwp_edit_post_save_to_post_type',
			'_mainwp_post_dripper_sites_number',
			'_mainwp_post_dripper_time_number',
			'_mainwp_post_dripper_select_time',
			'_mainwp_post_dripper_use_post_dripper',
			'mainwp_post_id',
			'_mainwp_post_dripper_selected_drip_sites',
			'_mainwp_post_dripper_total_drip_sites',
			'_mainwp_replace_advance_img',
		);

		if ( $seo_ext_activated ) {
			// update those custom fields later.
			$not_allowed[] = \WPSEO_Meta::$meta_prefix . 'opengraph-image-id';
			$not_allowed[] = \WPSEO_Meta::$meta_prefix . 'opengraph-image';
		}

		if ( is_array( $post_custom ) ) {
			foreach ( $post_custom as $meta_key => $meta_values ) {
				if ( ! in_array( $meta_key, $not_allowed ) ) {
					foreach ( $meta_values as $meta_value ) {
						if ( 0 === strpos( $meta_key, '_mainwp_spinner_' ) ) {
							continue;
						}

						if ( ! $seo_ext_activated ) {
							// if WordPress SEO plugin is not activated do not save yoast post meta.
							if ( false === strpos( $meta_key, '_yoast_wpseo_' ) ) {
								update_post_meta( $new_post_id, $meta_key, $meta_value );
							}
						} else {
							update_post_meta( $new_post_id, $meta_key, $meta_value );
						}
					}
				} elseif ( '_sticky' === $meta_key ) {
					foreach ( $meta_values as $meta_value ) {
						if ( 'sticky' === base64_decode( $meta_value ) ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
							stick_post( $new_post_id );
						}
					}
				} elseif ( '_post_to_only_existing_categories' === $meta_key ) {
					if ( isset( $meta_values[0] ) && $meta_values[0] ) {
						$post_to_only = true;
					}
				}
			}
		}
	}

	/**
	 * Update Yoast SEO Extension meta.
	 *
	 * @param string $new_post_id     New post ID.
	 * @param array  $post_custom      Post custom meta data.
	 *
	 * @uses \MainWP\Child\MainWP_Utility::upload_image()
	 * @uses \WPSEO_Meta::$meta_prefix()
	 * @uses \Exception()
	 */
	private function update_seo_meta( $new_post_id, $post_custom ) {

		$_seo_opengraph_image = isset( $post_custom[ \WPSEO_Meta::$meta_prefix . 'opengraph-image' ] ) ? $post_custom[ \WPSEO_Meta::$meta_prefix . 'opengraph-image' ] : array();
		$_seo_opengraph_image = current( $_seo_opengraph_image );
		$_server_domain       = '';
		$_server              = get_option( 'mainwp_child_server' );
		if ( preg_match( '/(https?:\/\/[^\/]+\/).+/', $_server, $matchs ) ) {
			$_server_domain = isset( $matchs[1] ) ? $matchs[1] : '';
		}

		// upload image if it on the server.
		if ( ! empty( $_seo_opengraph_image ) && false !== strpos( $_seo_opengraph_image, $_server_domain ) ) {
			try {
				$upload = MainWP_Utility::upload_image( $_seo_opengraph_image ); // Upload image to WP.
				if ( null !== $upload ) {
					update_post_meta( $new_post_id, \WPSEO_Meta::$meta_prefix . 'opengraph-image', $upload['url'] ); // Add the image to the post!
					update_post_meta( $new_post_id, \WPSEO_Meta::$meta_prefix . 'opengraph-image-id', $upload['id'] ); // Add the id image to the post!
				}
			} catch ( \Exception $e ) {
				// ok!
			}
		}
	}

	/**
	 * Create featured image.
	 *
	 * @param string $new_post_id         New post ID.
	 * @param string $post_featured_image Post featured image.
	 * @param bool   $check_image_existed TRUE|FALSE, Whether or not featured image already exists.
	 * @param array  $others        Post custom others meta data.
	 *
	 * @uses \MainWP\Child\MainWP_Utility::upload_image()
	 * @uses \Excepsion()
	 */
	private function create_featured_image( $new_post_id, $post_featured_image, $check_image_existed, $others ) {

		$featured_image_exist = false;
		// If featured image exists - set it.
		if ( null !== $post_featured_image ) {
			try {
				$upload = MainWP_Utility::upload_image( $post_featured_image, array(), $check_image_existed, $new_post_id ); // Upload image to WP.
				if ( null !== $upload ) {
					update_post_meta( $new_post_id, '_thumbnail_id', $upload['id'] ); // Add the thumbnail to the post!
					$featured_image_exist = true;
					if ( isset( $others['featured_image_data'] ) && ! empty( $others['featured_image_data'] ) ) {
						$_image_data = $others['featured_image_data'];
						update_post_meta( $upload['id'], '_wp_attachment_image_alt', $_image_data['alt'] );
						wp_update_post(
							array(
								'ID'           => $upload['id'],
								'post_excerpt' => MainWP_Utility::esc_content( $_image_data['caption'], 'mixed' ),
								'post_content' => $_image_data['description'],
								'post_title'   => htmlspecialchars( $_image_data['title'] ),
							)
						);
					}
				}
			} catch ( \Exception $e ) {
				// ok!
			}
		}

		if ( ! $featured_image_exist ) {
			delete_post_meta( $new_post_id, '_thumbnail_id' );
		}
	}

}
