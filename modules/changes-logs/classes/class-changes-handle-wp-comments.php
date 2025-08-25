<?php
/**
 * Logger: Comments
 *
 * Comments logger class file.
 *
 * @since     5.5
 * @package   mainwp/child
 */

namespace MainWP\Child\Changes;

// Exit.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Provides logging functionality for the comments
 */
class Changes_Handle_WP_Comments {

    /**
     * Is that a frontend logger or not?
     *
     * @var boolean
     */
    private static $frontend_logger = true;

    /**
     * Inits the main hooks
     *
     * @return void
     */
    public static function init_hooks() {
        \add_action( 'comment_post', array( __CLASS__, 'callback_change_comment' ), 10, 3 );
        \add_action( 'deleted_comment', array( __CLASS__, 'callback_change_comment_deleted' ), 10, 1 );
        \add_action( 'edit_comment', array( __CLASS__, 'callback_change_comment_edit' ), 10, 1 );
        \add_action( 'spammed_comment', array( __CLASS__, 'callback_change_comment_spam' ), 10, 1 );
        \add_action( 'trashed_comment', array( __CLASS__, 'callback_change_comment_trash' ), 10, 1 );
        \add_action( 'transition_comment_status', array( __CLASS__, 'callback_change_comment_approve' ), 10, 3 );
        \add_action( 'unspammed_comment', array( __CLASS__, 'callback_change_comment_unspam' ), 10, 1 );
        \add_action( 'untrashed_comment', array( __CLASS__, 'callback_change_comment_untrash' ), 10, 1 );
    }

    /**
     * Is that a front end?
     *
     * @return boolean
     */
    public static function is_frontend_logger() {
        return self::$frontend_logger;
    }

    /**
     * Comment edit.
     *
     * @param integer $comment_id - Comment ID.
     */
    public static function callback_change_comment_edit( $comment_id ) {
        self::change_comment_action( $comment_id, 1535 );
    }

    /**
     * Comment status.
     *
     * @param string   $new_status - New status.
     * @param string   $old_status - Old status.
     * @param stdClass $comment - Comment.
     */
    public static function callback_change_comment_approve( $new_status, $old_status, $comment ) {
        if ( ! empty( $comment ) && $old_status !== $new_status ) {
            $post   = get_post( $comment->comment_post_ID );
            $fields = array(
                'posttitle'  => $post->post_title,
                'postid'     => $post->ID,
                'posttype'   => $post->post_type,
                'poststatus' => $post->post_status,
                'commentid'  => $comment->comment_ID,
                'author'     => $comment->comment_author,
                'date'       => $comment->comment_date,
            );

            if ( 'approved' === $new_status ) {
                Changes_Logs_Logger::log_change( 1520, $fields );
            }
            if ( 'unapproved' === $new_status ) {
                Changes_Logs_Logger::log_change( 1525, $fields );
            }
        }
    }

    /**
     * Comment spam.
     *
     * @param integer $comment_id - Comment ID.
     */
    public static function callback_change_comment_spam( $comment_id ) {
        self::change_comment_action( $comment_id, 1540 );
    }

    /**
     * Comment unspam.
     *
     * @param integer $comment_id - Comment ID.
     */
    public static function callback_change_comment_unspam( $comment_id ) {
        self::change_comment_action( $comment_id, 1545 );
    }

    /**
     * Comment trash.
     *
     * @param integer $comment_id - Comment ID.
     */
    public static function callback_change_comment_trash( $comment_id ) {
        self::change_comment_action( $comment_id, 1550 );
    }

    /**
     * Comment untrash.
     *
     * @param integer $comment_id comment ID.
     */
    public static function callback_change_comment_untrash( $comment_id ) {
        self::change_comment_action( $comment_id, 1555 );
    }

    /**
     * Comment deleted.
     *
     * @param integer $comment_id comment ID.
     */
    public static function callback_change_comment_deleted( $comment_id ) {
        self::change_comment_action( $comment_id, 1560 );
    }

    /**
     * Fires immediately after a comment is inserted into the database.
     *
     * @param int        $comment_id       The comment ID.
     * @param int|string $comment_approved 1 if the comment is approved, 0 if not, 'spam' if spam.
     * @param array      $comment_data     Comment data.
     */
    public static function callback_change_comment( $comment_id, $comment_approved, $comment_data ) {

        if ( isset( $comment_data['comment_parent'] ) && $comment_data['comment_parent'] ) {
            self::change_comment_action( $comment_id, 1530 );
            return;
        }

        $comment = get_comment( $comment_id );
        if ( $comment && 'spam' !== $comment->comment_approved ) {
            $post   = get_post( $comment->comment_post_ID );
            $fields = array(
                'posttitle'  => $post->post_title,
                'postid'     => $post->ID,
                'posttype'   => $post->post_type,
                'poststatus' => $post->post_status,
                'commentid'  => $comment->comment_ID,
                'date'       => $comment->comment_date,
            );

            $user_data = get_user_by( 'email', $comment->comment_author_email );

            if ( $user_data && $user_data instanceof \WP_User ) {
                $user_roles = Changes_Helper::get_user_roles( $user_data );

                $fields['username']         = $user_data->user_login;
                $fields['currentuserroles'] = $user_roles;
                if ( ! empty( $user_data->ID ) ) {
                    $fields['currentuserid'] = $user_data->ID;
                }
                Changes_Logs_Logger::log_change( 1565, $fields );
            }
        }
    }

    /**
     * Comment change_action.
     *
     * @param integer $comment_id - Comment ID.
     * @param integer $log_code - Log type id.
     */
    private static function change_comment_action( $comment_id, $log_code ) {
        $comment = get_comment( $comment_id );
        if ( $comment ) {
            $post   = get_post( $comment->comment_post_ID );
            $fields = array(
                'posttitle'  => $post->post_title,
                'postid'     => $post->ID,
                'posttype'   => $post->post_type,
                'poststatus' => $post->post_status,
                'commentid'  => $comment->comment_ID,
                'author'     => $comment->comment_author,
                'date'       => $comment->comment_date,
            );

            if ( 'shop_order' !== $post->post_type && ( property_exists( $comment, 'comment_type' ) && 'order_note' !== $comment->comment_type ) ) {
                Changes_Logs_Logger::log_change( $log_code, $fields );
            }
        }
    }
}
