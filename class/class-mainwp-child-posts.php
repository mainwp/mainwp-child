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
				$link_count = new WPSEO_Link_Column_Count();
				$link_count->set( $post_ids );
			}
			foreach ( $posts as $post ) {
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

				if ( $wp_seo_enabled ) {
					$post_id             = $post->ID;
					$outPost['seo_data'] = array(
						'count_seo_links'   => $link_count->get( $post_id, 'internal_link_count' ),
						'count_seo_linked'  => $link_count->get( $post_id, 'incoming_link_count' ),
						'seo_score'         => \MainWP_WordPress_SEO::instance()->parse_column_score( $post_id ),
						'readability_score' => \MainWP_WordPress_SEO::instance()->parse_column_score_readability( $post_id ),
					);
				}

				$allPosts[] = $outPost;
			}
		}
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

		if ( isset( $_POST['_ezin_post_category'] ) ) {
			$new_post['_ezin_post_category'] = maybe_unserialize( base64_decode( $_POST['_ezin_post_category'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for begin reasons.
		}

		$others = array();
		if ( isset( $_POST['featured_image_data'] ) && ! empty( $_POST['featured_image_data'] ) ) {
			$others['featured_image_data'] = unserialize( base64_decode( $_POST['featured_image_data'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for begin reasons.
		}

		$res = MainWP_Helper::create_post( $new_post, $post_custom, $post_category, $post_featured_image, $upload_dir, $post_tags, $others );

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

}
