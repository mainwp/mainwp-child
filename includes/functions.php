<?php
/**
 * MainWP Child Functions.
 *
 * @package     MainWP/Child
 */

if ( isset( $_GET['skeleton_keyuse_nonce_key'] ) && isset( $_GET['skeleton_keyuse_nonce_hmac'] ) ) {
	$skeleton_keyuse_nonce_key  = intval( $_GET['skeleton_keyuse_nonce_key'] );
	$skeleton_keyuse_nonce_hmac = $_GET['skeleton_keyuse_nonce_hmac'];
	$skeleton_keycurrent_time   = intval( time() );

	if ( $skeleton_keycurrent_time >= $skeleton_keyuse_nonce_key && $skeleton_keycurrent_time <= ( $skeleton_keyuse_nonce_key + 30 ) ) {

		if ( strcmp( $skeleton_keyuse_nonce_hmac, hash_hmac( 'sha256', $skeleton_keyuse_nonce_key, NONCE_KEY ) ) === 0 ) {

			if ( ! function_exists( 'wp_verify_nonce' ) ) :

				/**
				 * Verify that correct nonce was used with time limit.
				 *
				 * The user is given an amount of time to use the token, so therefore, since the
				 * UID and $action remain the same, the independent variable is the time.
				 *
				 * @since 2.0.3
				 *
				 * @param string     $nonce Nonce that was used in the form to verify
				 * @param string|int $action Should give context to what is taking place and be the same when nonce was created.
				 *
				 * @return false|int False if the nonce is invalid, 1 if the nonce is valid and generated between
				 *                   0-12 hours ago, 2 if the nonce is valid and generated between 12-24 hours ago.
				 */
				function wp_verify_nonce( $nonce, $action = - 1 ) {
					$nonce = (string) $nonce;
					$user  = wp_get_current_user();
					$uid   = (int) $user->ID;
					if ( ! $uid ) {
						/**
						 * Filter whether the user who generated the nonce is logged out.
						 *
						 * @since 3.5.0
						 *
						 * @param int $uid ID of the nonce-owning user.
						 * @param string $action The nonce action.
						 */
						$uid = apply_filters( 'nonce_user_logged_out', $uid, $action );
					}

					if ( empty( $nonce ) ) {

						// To fix verify nonce conflict #1.
						// this is fake post field to fix some conflict of wp_verify_nonce().
						// just return false to unverify nonce, does not exit.
						if ( isset( $_POST[ $action ] ) && ( 'mainwp-bsm-unverify-nonce' == $_POST[ $action ] ) ) {
							return false;
						}

						// to help tracing the conflict verify nonce with other plugins.
						ob_start();
						debug_print_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ); // phpcs:ignore -- debug feature.
						$stackTrace = "\n" . ob_get_clean();
						die( '<mainwp>' . base64_encode( json_encode( array( 'error' => 'You dont send nonce: ' . $action . '<br/>Trace: ' . $stackTrace ) ) ) . '</mainwp>' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for benign reasons.
					}

					// To fix verify nonce conflict #2.
					// this is fake nonce to fix some conflict of wp_verify_nonce().
					// just return false to unverify nonce, does not exit.
					if ( 'mainwp-bsm-unverify-nonce' == $nonce ) {
						return false;
					}

					$token = wp_get_session_token();
					$i     = wp_nonce_tick();

					// Nonce generated 0-12 hours ago.
					$expected = substr( wp_hash( $i . '|' . $action . '|' . $uid . '|' . $token, 'nonce' ), - 12, 10 );
					if ( hash_equals( $expected, $nonce ) ) {
						return 1;
					}

					// Nonce generated 12-24 hours ago.
					$expected = substr( wp_hash( ( $i - 1 ) . '|' . $action . '|' . $uid . '|' . $token, 'nonce' ), - 12, 10 );
					if ( hash_equals( $expected, $nonce ) ) {
						return 2;
					}

					// To fix verify nonce conflict #3.
					// this is fake post field to fix some conflict of wp_verify_nonce().
					// just return false to unverify nonce, does not exit.
					if ( isset( $_POST[ $action ] ) && ( 'mainwp-bsm-unverify-nonce' == $_POST[ $action ] ) ) {
						return false;
					}

					ob_start();
					debug_print_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ); // phpcs:ignore -- debug feature.
					$stackTrace = "\n" . ob_get_clean();

					// Invalid nonce.
					die( '<mainwp>' . base64_encode( json_encode( array( 'error' => 'Invalid nonce! Try to use: ' . $action . '<br/>Trace: ' . $stackTrace ) ) ) . '</mainwp>' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for benign reasons.
				}
			endif;
		}
	}
}


if ( ! function_exists( 'mainwp_child_helper' ) ) {

	/**
	 * Method mainwp_child_helper()
	 *
	 * Get MainWP Child helper instance.
	 *
	 * @return mixed MainWP\Child\MainWP_Helper
	 */
	function mainwp_child_helper() {
		return MainWP\Child\MainWP_Helper::instance();
	}
}
