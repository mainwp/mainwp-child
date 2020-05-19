<?php

namespace MainWP\Child;

class MainWP_Child_Posts {

	protected static $instance = null;

	private $comments_and_clauses;
	private $posts_where_suffix;


	/**
	 * Method get_class_name()
	 *
	 * Get Class Name.
	 *
	 * @return object
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	public function __construct() {
		$this->comments_and_clauses = '';
		$this->posts_where_suffix   = '';
	}

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}



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

	public function get_recent_posts_int( $status, $pCount, $type = 'post', &$allPosts, $extra = null ) {

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
		if ( isset( $_POST['WPSEOEnabled'] ) && $_POST['WPSEOEnabled'] ) {
			if ( is_plugin_active( 'wordpress-seo/wp-seo.php' ) && class_exists( 'WPSEO_Link_Column_Count' ) && class_exists( 'WPSEO_Meta' ) ) {
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

				/*
				*
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
				*
				*/
				$link_count = new WPSEO_Link_Column_Count();
				$link_count->set( $post_ids );
			}
			foreach ( $posts as $post ) {
				$outPost = $this->get_out_post( $post, $extra, $tokens );
				if ( $wp_seo_enabled ) {
					$outPost['seo_data'] = array(
						'count_seo_links'   => $link_count->get( $post->ID, 'internal_link_count' ),
						'count_seo_linked'  => $link_count->get( $post->ID, 'incoming_link_count' ),
						'seo_score'         => \MainWP_WordPress_SEO::instance()->parse_column_score( $post->ID ),
						'readability_score' => \MainWP_WordPress_SEO::instance()->parse_column_score_readability( $post->ID ),
					);
				}
				$allPosts[] = $outPost;
			}
		}
	}

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

		if ( 'future' == $post->post_status ) {
			$outPost['dts'] = strtotime( $post->post_date_gmt );
		}

		$usr               = get_user_by( 'id', $post->post_author );
		$outPost['author'] = ! empty( $usr ) ? $usr->user_nicename : 'removed';
		$categoryObjects   = get_the_category( $post->ID );
		$categories        = '';
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

	public function get_all_posts() {
		$post_type = ( isset( $_POST['post_type'] ) ? $_POST['post_type'] : 'post' );
		$this->get_all_posts_by_type( $post_type );
	}

	public function get_all_pages() {
		$this->get_all_posts_by_type( 'page' );
	}

	public function posts_where( $where ) {
		if ( $this->posts_where_suffix ) {
			$where .= ' ' . $this->posts_where_suffix;
		}

		return $where;
	}

	public function get_all_posts_by_type( $type ) {
		global $wpdb;

		add_filter( 'posts_where', array( &$this, 'posts_where' ) );
		$where_post_date = isset( $_POST['where_post_date'] ) && ! empty( $_POST['where_post_date'] ) ? true : false;
		if ( isset( $_POST['postId'] ) ) {
			$this->posts_where_suffix .= " AND $wpdb->posts.ID = " . $_POST['postId'];
		} elseif ( isset( $_POST['userId'] ) ) {
			$this->posts_where_suffix .= " AND $wpdb->posts.post_author = " . $_POST['userId'];
		} else {
			if ( isset( $_POST['keyword'] ) ) {
				$search_on = isset( $_POST['search_on'] ) ? $_POST['search_on'] : '';
				if ( 'title' == $search_on ) {
					$this->posts_where_suffix .= " AND ( $wpdb->posts.post_title LIKE '%" . $_POST['keyword'] . "%' )";
				} elseif ( 'content' == $search_on ) {
					$this->posts_where_suffix .= " AND ($wpdb->posts.post_content LIKE '%" . $_POST['keyword'] . "%' )";
				} else {
					$this->posts_where_suffix .= " AND ($wpdb->posts.post_content LIKE '%" . $_POST['keyword'] . "%' OR $wpdb->posts.post_title LIKE '%" . $_POST['keyword'] . "%' )";
				}
			}
			if ( isset( $_POST['dtsstart'] ) && '' !== $_POST['dtsstart'] ) {
				if ( $where_post_date ) {
					$this->posts_where_suffix .= " AND $wpdb->posts.post_date > '" . $_POST['dtsstart'] . "'";
				} else {
					$this->posts_where_suffix .= " AND $wpdb->posts.post_modified > '" . $_POST['dtsstart'] . "'";
				}
			}
			if ( isset( $_POST['dtsstop'] ) && '' !== $_POST['dtsstop'] ) {
				if ( $where_post_date ) {
					$this->posts_where_suffix .= " AND $wpdb->posts.post_date < '" . $_POST['dtsstop'] . "'";
				} else {
					$this->posts_where_suffix .= " AND $wpdb->posts.post_modified < '" . $_POST['dtsstop'] . "'";
				}
			}

			if ( isset( $_POST['exclude_page_type'] ) && $_POST['exclude_page_type'] ) {
				$this->posts_where_suffix .= " AND $wpdb->posts.post_type NOT IN ('page')";
			}
		}

		$maxPages = 50;
		if ( defined( 'MAINWP_CHILD_NR_OF_PAGES' ) ) {
			$maxPages = MAINWP_CHILD_NR_OF_PAGES;
		}

		if ( isset( $_POST['maxRecords'] ) ) {
			$maxPages = $_POST['maxRecords'];
		}
		if ( 0 === $maxPages ) {
			$maxPages = 99999;
		}

		$extra = array();
		if ( isset( $_POST['extract_tokens'] ) ) {
			$extra['tokens']            = maybe_unserialize( base64_decode( $_POST['extract_tokens'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for begin reasons.
			$extra['extract_post_type'] = $_POST['extract_post_type'];
		}

		$extra['where_post_date'] = $where_post_date;
		$rslt                     = $this->get_recent_posts( explode( ',', $_POST['status'] ), $maxPages, $type, $extra );
		$this->posts_where_suffix = '';

		mainwp_child_helper()->write( $rslt );
	}

	public function new_post() {
		$new_post            = maybe_unserialize( base64_decode( $_POST['new_post'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for begin reasons.
		$post_custom         = maybe_unserialize( base64_decode( $_POST['post_custom'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for begin reasons.
		$post_category       = rawurldecode( isset( $_POST['post_category'] ) ? base64_decode( $_POST['post_category'] ) : null ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for begin reasons.
		$post_tags           = rawurldecode( isset( $new_post['post_tags'] ) ? $new_post['post_tags'] : null );
		$post_featured_image = base64_decode( $_POST['post_featured_image'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for begin reasons.
		$upload_dir          = maybe_unserialize( base64_decode( $_POST['mainwp_upload_dir'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for begin reasons.

		$others = array();
		if ( isset( $_POST['featured_image_data'] ) && ! empty( $_POST['featured_image_data'] ) ) {
			$others['featured_image_data'] = unserialize( base64_decode( $_POST['featured_image_data'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for begin reasons.
		}

		$res = $this->create_post( $new_post, $post_custom, $post_category, $post_featured_image, $upload_dir, $post_tags, $others );

		if ( is_array( $res ) && isset( $res['error'] ) ) {
			MainWP_Helper::error( $res['error'] );
		}

		$created = $res['success'];
		if ( true !== $created ) {
			MainWP_Helper::error( 'Undefined error' );
		}

		$information['added']    = true;
		$information['added_id'] = $res['added_id'];
		$information['link']     = $res['link'];

		do_action( 'mainwp_child_after_newpost', $res );

		mainwp_child_helper()->write( $information );
	}

	public function post_action() {
		$action  = $_POST['action'];
		$postId  = $_POST['id'];
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
							'ID'                  => $postId,
							'post_date'           => current_time( 'mysql', false ),
							'post_date_gmt'       => current_time( 'mysql', true ),
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
			$postData = $_POST['post_data'];
			$my_post  = is_array( $postData ) ? $postData : array();
			wp_update_post( $my_post );
		} elseif ( 'unpublish' === $action ) {
			$my_post['ID']          = $postId;
			$my_post['post_status'] = 'draft';
			wp_update_post( $my_post );
		} elseif ( 'trash' === $action ) {
			add_action( 'trash_post', array( '\MainWP_Child_Links_Checker', 'hook_post_deleted' ) );
			wp_trash_post( $postId );
		} elseif ( 'delete' === $action ) {
			add_action( 'delete_post', array( '\MainWP_Child_Links_Checker', 'hook_post_deleted' ) );
			wp_delete_post( $postId, true );
		} elseif ( 'restore' === $action ) {
			wp_untrash_post( $postId );
		} elseif ( 'update_meta' === $action ) {
			$values     = maybe_unserialize( base64_decode( $_POST['values'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for begin reasons.
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
			$postId    = $_POST['id'];
			$post_type = $_POST['post_type'];
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
		mainwp_child_helper()->write( $information );
	}

	public function get_post_edit( $id ) {
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
			);

			if ( null != $post_featured_image ) { // Featured image is set, retrieve URL.
				$img                 = wp_get_attachment_image_src( $post_featured_image, 'full' );
				$post_featured_image = $img[0];
			}

			require_once ABSPATH . 'wp-admin/includes/post.php';
			wp_set_post_lock( $id );

			$post_data = array(
				'new_post'            => base64_encode( serialize( $new_post ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for begin reasons.
				'post_custom'         => base64_encode( serialize( $post_custom ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for begin reasons.
				'post_category'       => base64_encode( $post_category ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for begin reasons.
				'post_featured_image' => base64_encode( $post_featured_image ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for begin reasons.
				'post_gallery_images' => base64_encode( serialize( $post_gallery_images ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for begin reasons.
				'child_upload_dir'    => base64_encode( serialize( $child_upload_dir ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for begin reasons.
			);
			return $post_data;

		}
		return false;
	}

	public function get_page_edit( $id ) {
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

			$post_data = array(
				'new_post'            => base64_encode( serialize( $new_post ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for begin reasons.
				'post_custom'         => base64_encode( serialize( $post_custom ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for begin reasons.
				'post_featured_image' => base64_encode( $post_featured_image ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for begin reasons.
				'post_gallery_images' => base64_encode( serialize( $post_gallery_images ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for begin reasons.
				'child_upload_dir'    => base64_encode( serialize( $child_upload_dir ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for begin reasons.
			);
			return $post_data;
		}
		return false;
	}

	public function comment_action() {
		$action    = $_POST['action'];
		$commentId = $_POST['id'];

		if ( 'approve' === $action ) {
			wp_set_comment_status( $commentId, 'approve' );
		} elseif ( 'unapprove' === $action ) {
			wp_set_comment_status( $commentId, 'hold' );
		} elseif ( 'spam' === $action ) {
			wp_spam_comment( $commentId );
		} elseif ( 'unspam' === $action ) {
			wp_unspam_comment( $commentId );
		} elseif ( 'trash' === $action ) {
			add_action( 'trashed_comment', array( '\MainWP_Child_Links_Checker', 'hook_trashed_comment' ), 10, 1 );
			wp_trash_comment( $commentId );
		} elseif ( 'restore' === $action ) {
			wp_untrash_comment( $commentId );
		} elseif ( 'delete' === $action ) {
			wp_delete_comment( $commentId, true );
		} else {
			$information['status'] = 'FAIL';
		}

		if ( ! isset( $information['status'] ) ) {
			$information['status'] = 'SUCCESS';
		}
		mainwp_child_helper()->write( $information );
	}

	public function comment_bulk_action() {
		$action                 = $_POST['action'];
		$commentIds             = explode( ',', $_POST['ids'] );
		$information['success'] = 0;
		foreach ( $commentIds as $commentId ) {
			if ( $commentId ) {
				$information['success'] ++;
				if ( 'approve' === $action ) {
					wp_set_comment_status( $commentId, 'approve' );
				} elseif ( 'unapprove' === $action ) {
					wp_set_comment_status( $commentId, 'hold' );
				} elseif ( 'spam' === $action ) {
					wp_spam_comment( $commentId );
				} elseif ( 'unspam' === $action ) {
					wp_unspam_comment( $commentId );
				} elseif ( 'trash' === $action ) {
					wp_trash_comment( $commentId );
				} elseif ( 'restore' === $action ) {
					wp_untrash_comment( $commentId );
				} elseif ( 'delete' === $action ) {
					wp_delete_comment( $commentId, true );
				} else {
					$information['success']--;
				}
			}
		}
		mainwp_child_helper()->write( $information );
	}


	public function comments_clauses( $clauses ) {
		if ( $this->comments_and_clauses ) {
			$clauses['where'] .= ' ' . $this->comments_and_clauses;
		}

		return $clauses;
	}

	public function get_all_comments() {
		global $wpdb;

		add_filter( 'comments_clauses', array( &$this, 'comments_clauses' ) );

		if ( isset( $_POST['postId'] ) ) {
			$this->comments_and_clauses .= " AND $wpdb->comments.comment_post_ID = " . $_POST['postId'];
		} else {
			if ( isset( $_POST['keyword'] ) ) {
				$this->comments_and_clauses .= " AND $wpdb->comments.comment_content LIKE '%" . $_POST['keyword'] . "%'";
			}
			if ( isset( $_POST['dtsstart'] ) && '' !== $_POST['dtsstart'] ) {
				$this->comments_and_clauses .= " AND $wpdb->comments.comment_date > '" . $_POST['dtsstart'] . "'";
			}
			if ( isset( $_POST['dtsstop'] ) && '' !== $_POST['dtsstop'] ) {
				$this->comments_and_clauses .= " AND $wpdb->comments.comment_date < '" . $_POST['dtsstop'] . "'";
			}
		}

		$maxComments = 50;
		if ( defined( 'MAINWP_CHILD_NR_OF_COMMENTS' ) ) {
			$maxComments = MAINWP_CHILD_NR_OF_COMMENTS; // to compatible.
		}

		if ( isset( $_POST['maxRecords'] ) ) {
			$maxComments = $_POST['maxRecords'];
		}

		if ( 0 === $maxComments ) {
			$maxComments = 99999;
		}

		$rslt                       = $this->get_recent_comments( explode( ',', $_POST['status'] ), $maxComments );
		$this->comments_and_clauses = '';

		mainwp_child_helper()->write( $rslt );
	}

	public function get_recent_comments( $pAllowedStatuses, $pCount ) {
		if ( ! function_exists( 'get_comment_author_url' ) ) {
			include_once WPINC . '/comment-template.php';
		}
		$allComments = array();

		foreach ( $pAllowedStatuses as $status ) {
			$params = array( 'status' => $status );
			if ( 0 !== $pCount ) {
				$params['number'] = $pCount;
			}
			$comments = get_comments( $params );
			if ( is_array( $comments ) ) {
				foreach ( $comments as $comment ) {
					$post                        = get_post( $comment->comment_post_ID );
					$email                       = apply_filters( 'comment_email', $comment->comment_author_email );
					$outComment                  = array();
					$outComment['id']            = $comment->comment_ID;
					$outComment['status']        = wp_get_comment_status( $comment->comment_ID );
					$outComment['author']        = $comment->comment_author;
					$outComment['author_url']    = get_comment_author_url( $comment->comment_ID );
					$outComment['author_ip']     = get_comment_author_IP( $comment->comment_ID );
					$outComment['author_email']  = apply_filters( 'comment_email', $comment->comment_author_email );
					$outComment['postId']        = $comment->comment_post_ID;
					$outComment['postName']      = $post->post_title;
					$outComment['comment_count'] = $post->comment_count;
					$outComment['content']       = $comment->comment_content;
					$outComment['dts']           = strtotime( $comment->comment_date_gmt );
					$allComments[]               = $outComment;
				}
			}
		}

		return $allComments;
	}



	private function create_post( $new_post, $post_custom, $post_category, $post_featured_image, $upload_dir, $post_tags, $others = array() ) {

		/**
		* Hook: `mainwp_before_post_update`
		*
		* Runs before creating or updating a post via MainWP dashboard.
		*
		* @param array  $new_post      – Post data array.
		* @param array  $post_custom   – Post custom meta data.
		* @param string $post_category – Post categories.
		* @param string $post_tags     – Post tags.
		*/
		do_action( 'mainwp_before_post_update', $new_post, $post_custom, $post_category, $post_tags );
		$edit_post_id = 0;
		$is_post_plus = false;
		$this->set_post_custom_data( $new_post, $post_custom, $post_tags, $edit_post_id, $is_post_plus );
		require_once ABSPATH . 'wp-admin/includes/post.php';
		if ( $edit_post_id ) {
			$user_id = wp_check_post_lock( $edit_post_id );
			if ( $user_id ) {
				$user  = get_userdata( $user_id );
				$error = sprintf( __( 'This content is currently locked. %s is currently editing.', 'mainwp-child' ), $user->display_name );
				return array( 'error' => $error );
			}
		}
		$check_image_existed = $edit_post_id ? true : false; // if editing post then will check if image existed.
		$this->create_found_images( $new_post, $upload_dir, $check_image_existed );
		$this->create_has_shortcode_gallery( $new_post );
		if ( $is_post_plus ) {
			$this->create_post_plus( $new_post, $post_custom );
		}
		// Save the post to the WP.
		remove_filter( 'content_save_pre', 'wp_filter_post_kses' );  // to fix brake scripts or html.
		$post_status             = $new_post['post_status']; // save post_status.
		$new_post['post_status'] = 'auto-draft'; // to fix reports: to logging as created post.
		// update post.
		if ( $edit_post_id ) {
			// check if post existed.
			$current_post = get_post( $edit_post_id );
			if ( $current_post && ( ( ! isset( $new_post['post_type'] ) && 'post' == $current_post->post_type ) || ( isset( $new_post['post_type'] ) && $new_post['post_type'] == $current_post->post_type ) ) ) {
				$new_post['ID'] = $edit_post_id;
			}
			$new_post['post_status'] = $post_status; // child reports: to logging as update post.
		}
		$wp_error    = null;
		$new_post_id = wp_insert_post( $new_post, $wp_error );
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
		$this->update_post_data( $new_post_id, $post_custom, $post_category, $post_featured_image, $check_image_existed, $is_post_plus );
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

	private function set_post_custom_data( &$new_post, $post_custom, $post_tags, &$edit_post_id, &$is_post_plus ) {

		global $current_user;

		$this->create_wp_rocket( $post_custom );

		// current user may be connected admin or alternative admin.
		$current_uid = $current_user->ID;

		// Set up a new post (adding addition information).

		$new_post['post_author'] = isset( $new_post['post_author'] ) && ! empty( $new_post['post_author'] ) ? $new_post['post_author'] : $current_uid;

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

		if ( isset( $post_tags ) && '' !== $post_tags ) {
			$new_post['tags_input'] = $post_tags;
		}

		if ( isset( $post_custom['_mainwp_edit_post_id'] ) && $post_custom['_mainwp_edit_post_id'] ) {
			$edit_post_id = current( $post_custom['_mainwp_edit_post_id'] );
		} elseif ( isset( $new_post['ID'] ) && $new_post['ID'] ) {
			$edit_post_id = $new_post['ID'];
		}
	}

	private function update_post_data( $new_post_id, $post_custom, $post_category, $post_featured_image, $check_image_existed, $is_post_plus ) {

		$seo_ext_activated = false;
		if ( class_exists( 'WPSEO_Meta' ) && class_exists( 'WPSEO_Admin' ) ) {
			$seo_ext_activated = true;
		}

		$post_to_only_existing_categories = false;

		$this->create_set_custom_fields( $new_post_id, $post_custom, $seo_ext_activated, $post_to_only_existing_categories );

		// yoast seo plugin activated.
		if ( $seo_ext_activated ) {
			$this->create_seo_extension_activated( $new_post_id, $post_custom );
		}

		$this->create_set_categories( $new_post_id, $post_category, $post_to_only_existing_categories );

		$this->create_featured_image( $new_post_id, $post_featured_image, $check_image_existed );

		// post plus extension process.
		if ( $is_post_plus ) {
			$this->create_post_plus_categories( $new_post_id, $post_custom );
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

	private function create_wp_rocket( &$post_custom ) {
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
		if ( \MainWP_Child_WP_Rocket::instance()->is_activated() ) {
			if ( function_exists( 'get_rocket_option' ) ) {
				$wprocket_activated = true;
				foreach ( $wprocket_fields as $field ) {
					if ( ! isset( $post_custom[ '_rocket_exclude_' . $field ] ) ) {
						if ( ! get_rocket_option( $field ) ) {
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

	private function create_found_images( &$new_post, $upload_dir, $check_image_existed ) {

		// Search for all the images added to the new post. Some images have a href tag to click to navigate to the image.. we need to replace this too.
		$foundMatches = preg_match_all( '/(<a[^>]+href=\"(.*?)\"[^>]*>)?(<img[^>\/]*src=\"((.*?)(png|gif|jpg|jpeg))\")/ix', $new_post['post_content'], $matches, PREG_SET_ORDER );
		if ( $foundMatches > 0 ) {
			// We found images, now to download them so we can start balbal.
			foreach ( $matches as $match ) {
				$hrefLink = $match[2];
				$imgUrl   = $match[4];

				if ( ! isset( $upload_dir['baseurl'] ) || ( 0 !== strripos( $imgUrl, $upload_dir['baseurl'] ) ) ) {
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
						$new_post['post_content'] = str_replace( $lnkToReplace, $linkToReplaceWith, $new_post['post_content'] );
					}
				} catch ( \Exception $e ) {
					MainWP_Helper::log_debug( $e->getMessage() );
				}
			}
		}
	}

	private function create_has_shortcode_gallery( &$new_post ) {

		if ( has_shortcode( $new_post['post_content'], 'gallery' ) ) {
			if ( preg_match_all( '/\[gallery[^\]]+ids=\"(.*?)\"[^\]]*\]/ix', $new_post['post_content'], $matches, PREG_SET_ORDER ) ) {
				$replaceAttachedIds = array();
				if ( isset( $_POST['post_gallery_images'] ) ) {
					$post_gallery_images = unserialize( base64_decode( $_POST['post_gallery_images'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for begin reasons.
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

	private function create_post_plus_categories( $new_post_id, $post_custom ) {

			$random_privelege      = isset( $post_custom['_saved_draft_random_privelege'] ) ? $post_custom['_saved_draft_random_privelege'] : null;
			$random_privelege      = is_array( $random_privelege ) ? current( $random_privelege ) : null;
			$random_privelege_base = base64_decode( $random_privelege ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for begin reasons.
			$random_privelege      = maybe_unserialize( $random_privelege_base );

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

	private function create_set_custom_fields( $new_post_id, $post_custom, $seo_ext_activated, &$post_to_only ) {

		// Set custom fields.
		$not_allowed   = array(
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
		);
		$not_allowed[] = '_mainwp_boilerplate_sites_posts';
		$not_allowed[] = '_mainwp_post_plus';
		$not_allowed[] = '_saved_as_draft';
		$not_allowed[] = '_saved_draft_categories';
		$not_allowed[] = '_saved_draft_tags';
		$not_allowed[] = '_saved_draft_random_privelege';
		$not_allowed[] = '_saved_draft_random_category';
		$not_allowed[] = '_saved_draft_random_publish_date';
		$not_allowed[] = '_saved_draft_publish_date_from';
		$not_allowed[] = '_saved_draft_publish_date_to';
		$not_allowed[] = '_post_to_only_existing_categories';
		$not_allowed[] = '_mainwp_edit_post_site_id';
		$not_allowed[] = '_mainwp_edit_post_id';
		$not_allowed[] = '_edit_post_status';

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
						if ( 'sticky' === base64_decode( $meta_value ) ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for begin reasons.
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

	private function create_seo_extension_activated( $new_post_id, $post_custom ) {

		$_seo_opengraph_image = isset( $post_custom[ WPSEO_Meta::$meta_prefix . 'opengraph-image' ] ) ? $post_custom[ WPSEO_Meta::$meta_prefix . 'opengraph-image' ] : array();
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
					update_post_meta( $new_post_id, WPSEO_Meta::$meta_prefix . 'opengraph-image', $upload['url'] ); // Add the image to the post!
				}
			} catch ( \Exception $e ) {
				// ok!
			}
		}
	}

	private function create_featured_image( $new_post_id, $post_featured_image, $check_image_existed ) {

		$featured_image_exist = false;
		// If featured image exists - set it.
		if ( null !== $post_featured_image ) {
			try {
				$upload = MainWP_Utility::upload_image( $post_featured_image, array(), $check_image_existed, $new_post_id ); // Upload image to WP.
				if ( null !== $upload ) {
					update_post_meta( $new_post_id, '_thumbnail_id', $upload['id'] ); // Add the thumbnail to the post!
					$featured_image_exist = true;
					if ( isset( $others['featured_image_data'] ) ) {
						$_image_data = $others['featured_image_data'];
						update_post_meta( $upload['id'], '_wp_attachment_image_alt', $_image_data['alt'] );
						wp_update_post(
							array(
								'ID'           => $upload['id'],
								'post_excerpt' => $_image_data['caption'],
								'post_content' => $_image_data['description'],
								'post_title'   => $_image_data['title'],
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
