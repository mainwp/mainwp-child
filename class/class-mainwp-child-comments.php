<?php
/**
 * MainWP Child Comments
 *
 * This file handles all Child Site comment actions.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

/**
 * Class MainWP_Child_Comments
 *
 * Handles all Child Site comment actions.
 */
class MainWP_Child_Comments {

	/**
	 * Public static variable to hold the single instance of the class.
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
	 * Get Class Name.
	 *
	 * @return string
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * MainWP_Child_Comments constructor.
	 *
	 * Run any time class is called.
	 */
	public function __construct() {
		$this->comments_and_clauses = '';
	}

	/**
	 * Create a public static instance of ainWP_Child_Comments.
	 *
	 * @return MainWP_Child_Comments|null
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * MainWP Child Comment actions: approve, unapprove, spam, unspam, trash, restore, delete.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Links_Checker::get_class_name()
	 * @uses \MainWP\Child\MainWP_Helper::write()
	 */
	public function comment_action() {
		$action    = ! empty( $_POST['action'] ) ? sanitize_text_field( wp_unslash( $_POST['action'] ) ) : '';
		$commentId = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';

		if ( 'approve' === $action ) {
			wp_set_comment_status( $commentId, 'approve' );
		} elseif ( 'unapprove' === $action ) {
			wp_set_comment_status( $commentId, 'hold' );
		} elseif ( 'spam' === $action ) {
			wp_spam_comment( $commentId );
		} elseif ( 'unspam' === $action ) {
			wp_unspam_comment( $commentId );
		} elseif ( 'trash' === $action ) {
			add_action( 'trashed_comment', array( MainWP_Child_Links_Checker::get_class_name(), 'hook_trashed_comment' ), 10, 1 );
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
		MainWP_Helper::write( $information );
	}

	/**
	 * MainWP Child Bulk Comment actions: approve, unapprove, spam, unspam, trash, restore, delete.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::write()
	 */
	public function comment_bulk_action() {
		$action                 = ! empty( $_POST['action'] ) ? sanitize_text_field( wp_unslash( $_POST['action'] ) ) : '';
		$commentIds             = isset( $_POST['ids'] ) ? explode( ',', sanitize_text_field( wp_unslash( $_POST['ids'] ) ) ) : array();
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
		MainWP_Helper::write( $information );
	}

	/**
	 * Comment WHERE Clauses.
	 *
	 * @param array $clauses MySQL WHERE Clause.
	 *
	 * @return array $clauses, Array of MySQL WHERE Clauses.
	 */
	public function comments_clauses( $clauses ) {
		if ( $this->comments_and_clauses ) {
			$clauses['where'] .= ' ' . $this->comments_and_clauses;
		}

		return $clauses;
	}

	/**
	 * Get all comments.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::write()
	 */
	public function get_all_comments() {

		/**
		 * WordPress Database instance.
		 *
		 * @global object $wpdb
		 */
		global $wpdb;

		add_filter( 'comments_clauses', array( &$this, 'comments_clauses' ) );

		if ( isset( $_POST['postId'] ) ) {
			$this->comments_and_clauses .= $wpdb->prepare( " AND $wpdb->comments.comment_post_ID = %d ", sanitize_text_field( wp_unslash( $_POST['postId'] ) ) );
		} else {
			if ( isset( $_POST['keyword'] ) && '' !== $_POST['keyword'] ) {
				$this->comments_and_clauses .= $wpdb->prepare( " AND $wpdb->comments.comment_content LIKE %s ", '%' . $wpdb->esc_like( sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) ) . '%' );
			}
			if ( isset( $_POST['dtsstart'] ) && '' !== $_POST['dtsstart'] ) {
				$this->comments_and_clauses .= $wpdb->prepare( " AND $wpdb->comments.comment_date > %s ", $wpdb->esc_like( sanitize_text_field( wp_unslash( $_POST['dtsstart'] ) ) ) );
			}
			if ( isset( $_POST['dtsstop'] ) && '' !== $_POST['dtsstop'] ) {
				$this->comments_and_clauses .= $wpdb->prepare( " AND $wpdb->comments.comment_date < %s ", $wpdb->esc_like( sanitize_text_field( wp_unslash( $_POST['dtsstop'] ) ) ) );
			}
		}

		$maxComments = 50;
		if ( defined( 'MAINWP_CHILD_NR_OF_COMMENTS' ) ) {
			$maxComments = MAINWP_CHILD_NR_OF_COMMENTS; // to compatible.
		}

		if ( isset( $_POST['maxRecords'] ) ) {
			$maxComments = ! empty( $_POST['maxRecords'] ) ? intval( $_POST['maxRecords'] ) : 0;
		}

		if ( 0 === $maxComments ) {
			$maxComments = 99999;
		}
		$status                     = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';
		$rslt                       = $this->get_recent_comments( explode( ',', $status ), $maxComments );
		$this->comments_and_clauses = '';

		MainWP_Helper::write( $rslt );
	}

	/**
	 * Get recent comments.
	 *
	 * @param array $pAllowedStatuses An array containing allowed comment statuses.
	 * @param int   $pCount Number of comments to return.
	 *
	 * @return array $allComments Array of all comments found.
	 */
	public function get_recent_comments( $pAllowedStatuses, $pCount ) {
		if ( ! function_exists( '\get_comment_author_url' ) ) {
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
