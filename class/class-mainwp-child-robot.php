<?php

class MainWP_Child_Robot {
	public static $instance = null;

	static function Instance() {
		if ( null === MainWP_Child_Robot::$instance ) {
			MainWP_Child_Robot::$instance = new MainWP_Child_Robot();
		}

		return MainWP_Child_Robot::$instance;
	}

	public function wpr_insertcomments( $postid, $comments ) {
		remove_filter( 'comment_text', 'make_clickable', 9 );
		foreach ( $comments as $comment ) {
			$comment_post_ID      = $postid;
			$comment_date         = $comment['dts'];
			$comment_date         = date( 'Y-m-d H:i:s', $comment_date );
			$comment_date_gmt     = $comment_date;
			$rnd                  = rand( 1, 9999 );
			$comment_author_email = "someone$rnd@domain.com";
			$comment_author       = $comment['author'];
			$comment_author_url   = '';
			$comment_content      = '';
			$comment_content .= $comment['content'];
			$comment_type     = '';
			$user_ID          = '';
			$comment_approved = 1;
			$commentdata      = compact( 'comment_post_ID', 'comment_date', 'comment_date_gmt', 'comment_author', 'comment_author_email', 'comment_author_url', 'comment_content', 'comment_type', 'user_ID', 'comment_approved' );
			$comment_id       = wp_insert_comment( $commentdata );
		}
	}
}
