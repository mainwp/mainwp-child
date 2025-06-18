<?php
/**
 * Logger: Comments
 *
 * Comments logger class file.
 *
 * @since     4.5.1
 * @package   mainwp/child
 */

declare(strict_types=1);

namespace MainWP\Child\Changes\Loggers;

use MainWP\Child\Changes\Changes_Logs_Manager;
use MainWP\Child\Changes\Helpers\Changes_User_Helper;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides logging functionality for the comments
 *
 * @since 4.5.0
 */
class Changes_WP_Comments_Logger {

    /**
     * Is that a frontend logger or not?
     * Sensors doesn't need to have this property, except where they explicitly have to set that value.
     *
     * @var boolean
     *
     */
    private static $frontend_sensor = true;

    /**
     * Inits the main hooks
     *
     * @return void
     *
     */
    public static function init() {
        \add_action( 'edit_comment', array( __CLASS__, 'event_comment_edit' ), 10, 1 );
        \add_action( 'transition_comment_status', array( __CLASS__, 'event_comment_approve' ), 10, 3 );
        \add_action( 'spammed_comment', array( __CLASS__, 'event_comment_spam' ), 10, 1 );
        \add_action( 'unspammed_comment', array( __CLASS__, 'event_comment_unspam' ), 10, 1 );
        \add_action( 'trashed_comment', array( __CLASS__, 'event_comment_trash' ), 10, 1 );
        \add_action( 'untrashed_comment', array( __CLASS__, 'event_comment_untrash' ), 10, 1 );
        \add_action( 'deleted_comment', array( __CLASS__, 'event_comment_deleted' ), 10, 1 );
        \add_action( 'comment_post', array( __CLASS__, 'event_comment' ), 10, 3 );
    }

    /**
     * Is that a front end sensor? The sensors doesn't need to have that method implemented, except if they want to specifically set that value.
     *
     * @return boolean
     *
     */
    public static function is_frontend_sensor() {
        return self::$frontend_sensor;
    }

    /**
     * Trigger comment edit.
     *
     * @param integer $comment_id - Comment ID.
     *
     */
    public static function event_comment_edit( $comment_id ) {
        self::event_generic( $comment_id, 2093 );
    }

    /**
     * Trigger comment status.
     *
     * @param string   $new_status - New status.
     * @param string   $old_status - Old status.
     * @param stdClass $comment - Comment.
     *
     */
    public static function event_comment_approve( $new_status, $old_status, $comment ) {
        if ( ! empty( $comment ) && $old_status !== $new_status ) {
            $post         = get_post( $comment->comment_post_ID );
            $comment_link = get_permalink( $post->ID ) . '#comment-' . $comment->comment_ID;
            $fields       = array(
                'PostTitle'   => $post->post_title,
                'PostID'      => $post->ID,
                'PostType'    => $post->post_type,
                'PostStatus'  => $post->post_status,
                'CommentID'   => $comment->comment_ID,
                'Author'      => $comment->comment_author,
                'Date'        => $comment->comment_date,
                'CommentLink' => '<a target="_blank" href="' . $comment_link . '">' . $comment->comment_date . '</a>',
            );

            if ( 'approved' === $new_status ) {
                Changes_Logs_Manager::trigger_event( 2090, $fields );
            }
            if ( 'unapproved' === $new_status ) {
                Changes_Logs_Manager::trigger_event( 2091, $fields );
            }
        }
    }

    /**
     * Trigger comment spam.
     *
     * @param integer $comment_id - Comment ID.
     *
     */
    public static function event_comment_spam( $comment_id ) {
        self::event_generic( $comment_id, 2094 );
    }

    /**
     * Trigger comment unspam.
     *
     * @param integer $comment_id - Comment ID.
     *
     */
    public static function event_comment_unspam( $comment_id ) {
        self::event_generic( $comment_id, 2095 );
    }

    /**
     * Trigger comment trash.
     *
     * @param integer $comment_id - Comment ID.
     *
     */
    public static function event_comment_trash( $comment_id ) {
        self::event_generic( $comment_id, 2096 );
    }

    /**
     * Trigger comment untrash.
     *
     * @param integer $comment_id comment ID.
     *
     */
    public static function event_comment_untrash( $comment_id ) {
        self::event_generic( $comment_id, 2097 );
    }

    /**
     * Trigger comment deleted.
     *
     * @param integer $comment_id comment ID.
     *
     */
    public static function event_comment_deleted( $comment_id ) {
        self::event_generic( $comment_id, 2098 );
    }

    /**
     * Fires immediately after a comment is inserted into the database.
     *
     * @param int        $comment_id       The comment ID.
     * @param int|string $comment_approved 1 if the comment is approved, 0 if not, 'spam' if spam.
     * @param array      $comment_data     Comment data.
     *
     */
    public static function event_comment( $comment_id, $comment_approved, $comment_data ) {
        // Check if the comment is response to another comment.
        if ( isset( $comment_data['comment_parent'] ) && $comment_data['comment_parent'] ) {
            self::event_generic( $comment_id, 2092 );
            return;
        }

        $comment = get_comment( $comment_id );
        if ( $comment ) {
            if ( 'spam' !== $comment->comment_approved ) {
                $post         = get_post( $comment->comment_post_ID );
                $comment_link = get_permalink( $post->ID ) . '#comment-' . $comment_id;
                $fields       = array(
                    'PostTitle'   => $post->post_title,
                    'PostID'      => $post->ID,
                    'PostType'    => $post->post_type,
                    'PostStatus'  => $post->post_status,
                    'CommentID'   => $comment->comment_ID,
                    'Date'        => $comment->comment_date,
                    'CommentLink' => $comment_link,
                );

                // Get user data.
                $user_data = get_user_by( 'email', $comment->comment_author_email );

                if ( $user_data && $user_data instanceof \WP_User ) {
                    // Get user roles.
                    $user_roles = Changes_User_Helper::get_user_roles( $user_data );

                    // Set the fields.
                    $fields['Username']         = $user_data->user_login;
                    $fields['CurrentUserRoles'] = $user_roles;
                    Changes_Logs_Manager::trigger_event( 2099, $fields );
                }
            }
        }
    }

    /**
     * Trigger generic event.
     *
     * @param integer $comment_id - Comment ID.
     * @param integer $alert_code - Event code.
     *
     */
    private static function event_generic( $comment_id, $alert_code ) {
        $comment = get_comment( $comment_id );
        if ( $comment ) {
            $post         = get_post( $comment->comment_post_ID );
            $comment_link = get_permalink( $post->ID ) . '#comment-' . $comment_id;
            $fields       = array(
                'PostTitle'   => $post->post_title,
                'PostID'      => $post->ID,
                'PostType'    => $post->post_type,
                'PostStatus'  => $post->post_status,
                'CommentID'   => $comment->comment_ID,
                'Author'      => $comment->comment_author,
                'Date'        => $comment->comment_date,
                'CommentLink' => '<a target="_blank" href="' . $comment_link . '">' . $comment->comment_date . '</a>',
            );

            if ( 'shop_order' !== $post->post_type && ( property_exists( $comment, 'comment_type' ) && 'order_note' !== $comment->comment_type ) ) {
                Changes_Logs_Manager::trigger_event( $alert_code, $fields );
            }
        }
    }
}
