<?php

namespace MainWP\Child;

class MainWP_Connect {

	public static $instance = null;
	private $maxHistory     = 5;

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

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	// This will register the current wp - thus generating the public key etc.
	public function register_site() {
		global $current_user;

		$information = array();
		// Check if the user is valid & login.
		if ( ! isset( $_POST['user'] ) || ! isset( $_POST['pubkey'] ) ) {
			MainWP_Helper::error( __( 'Invalid request!', 'mainwp-child' ) );
		}

		// Already added - can't readd. Deactivate plugin.
		if ( get_option( 'mainwp_child_pubkey' ) ) {
			// set disconnect status to yes here, it will empty after reconnected.
			MainWP_Child_Branding::instance()->save_branding_options( 'branding_disconnected', 'yes' );
			MainWP_Helper::error( __( 'Public key already set. Please deactivate & reactivate the MainWP Child plugin and try again.', 'mainwp-child' ) );
		}

		if ( '' != get_option( 'mainwp_child_uniqueId' ) ) {
			if ( ! isset( $_POST['uniqueId'] ) || ( '' === $_POST['uniqueId'] ) ) {
				MainWP_Helper::error( __( 'This child site is set to require a unique security ID. Please enter it before the connection can be established.', 'mainwp-child' ) );
			} elseif ( get_option( 'mainwp_child_uniqueId' ) !== $_POST['uniqueId'] ) {
				MainWP_Helper::error( __( 'The unique security ID mismatch! Please correct it before the connection can be established.', 'mainwp-child' ) );
			}
		}

		// Check SSL Requirement.
		if ( ! MainWP_Helper::is_ssl_enabled() && ( ! defined( 'MAINWP_ALLOW_NOSSL_CONNECT' ) || ! MAINWP_ALLOW_NOSSL_CONNECT ) ) {
			MainWP_Helper::error( __( 'SSL is required on the child site to set up a secure connection.', 'mainwp-child' ) );
		}

		// Login.
		if ( isset( $_POST['user'] ) ) {
			if ( ! $this->login( $_POST['user'] ) ) {
				$hint_miss_user = __( 'That administrator username was not found on this child site. Please verify that it is an existing administrator.', 'mainwp-child' ) . '<br/>' . __( 'Hint: Check if the administrator user exists on the child site, if not, you need to use an existing administrator.', 'mainwp-child' );
				MainWP_Helper::error( $hint_miss_user );
			}

			if ( 10 !== $current_user->wp_user_level && ( ! isset( $current_user->user_level ) || 10 !== $current_user->user_level ) && ! $current_user->has_cap( 'level_10' ) ) {
				MainWP_Helper::error( __( 'That user is not an administrator. Please use an administrator user to establish the connection.', 'mainwp-child' ) );
			}
		}

		MainWP_Helper::update_option( 'mainwp_child_pubkey', base64_encode( $_POST['pubkey'] ), 'yes' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for begin reasons.
		MainWP_Helper::update_option( 'mainwp_child_server', $_POST['server'] ); // Save the public key.
		MainWP_Helper::update_option( 'mainwp_child_nonce', 0 ); // Save the nonce.

		MainWP_Helper::update_option( 'mainwp_child_nossl', ( '-1' === $_POST['pubkey'] || ! MainWP_Helper::is_ssl_enabled() ? 1 : 0 ), 'yes' );
		$information['nossl'] = ( '-1' === $_POST['pubkey'] || ! MainWP_Helper::is_ssl_enabled() ? 1 : 0 );
		if ( function_exists( 'random_bytes' ) ) {
			$nossl_key = random_bytes( 32 );
			$nossl_key = bin2hex( $nossl_key );
		} else {
			$nossl_key = uniqid( '', true );
		}
		MainWP_Helper::update_option( 'mainwp_child_nossl_key', $nossl_key, 'yes' );
		$information['nosslkey'] = $nossl_key;

		$information['register'] = 'OK';
		$information['uniqueId'] = get_option( 'mainwp_child_uniqueId', '' );
		$information['user']     = $_POST['user'];

		MainWP_Child_Stats::get_instance()->get_site_stats( $information );
	}


	public function parse_init_auth() {

		$auth = $this->auth( isset( $_POST['mainwpsignature'] ) ? $_POST['mainwpsignature'] : '', isset( $_POST['function'] ) ? $_POST['function'] : '', isset( $_POST['nonce'] ) ? $_POST['nonce'] : '', isset( $_POST['nossl'] ) ? $_POST['nossl'] : 0 );

		if ( ! $auth && isset( $_POST['mainwpsignature'] ) ) {
			MainWP_Helper::error( __( 'Authentication failed! Please deactivate & re-activate the MainWP Child plugin on this site and try again.', 'mainwp-child' ) );
		}

		if ( ! $auth && isset( $_POST['function'] ) ) {
			$func             = $_POST['function'];
			$callable         = MainWP_Child_Callable::get_instance()->is_callable_function( $func );
			$callable_no_auth = MainWP_Child_Callable::get_instance()->is_callable_function_no_auth( $func );

			if ( $callable && ! $callable_no_auth ) {
				MainWP_Helper::error( __( 'Authentication failed! Please deactivate & re-activate the MainWP Child plugin on this site and try again.', 'mainwp-child' ) );
			}
		}

		if ( $auth ) {
			$auth_user = false;
			// Check if the user exists & is an administrator.
			if ( isset( $_POST['function'] ) && isset( $_POST['user'] ) ) {

				$user = null;
				if ( isset( $_POST['alt_user'] ) && ! empty( $_POST['alt_user'] ) ) {
					if ( $this->check_login_as( $_POST['alt_user'] ) ) {
						$auth_user = $_POST['alt_user'];
						$user      = get_user_by( 'login', $auth_user );
					}
				}

				// if alternative admin not existed.
				if ( ! $user ) {
					// check connected admin existed.
					$user      = get_user_by( 'login', $_POST['user'] );
					$auth_user = $_POST['user'];
				}

				if ( ! $user ) {
					MainWP_Helper::error( __( 'Unexising administrator username. Please verify that it is an existing administrator.', 'mainwp-child' ) );
				}

				if ( 10 != $user->wp_user_level && ( ! isset( $user->user_level ) || 10 != $user->user_level ) && ! $user->has_cap( 'level_10' ) ) {
					MainWP_Helper::error( __( 'Invalid user. Please verify that the user has administrator privileges.', 'mainwp-child' ) );
				}

				$this->login( $auth_user );
			}

			if ( isset( $_POST['function'] ) && 'visitPermalink' === $_POST['function'] ) {

				if ( empty( $auth_user ) ) {
					$auth_user = $_POST['user'];
				}

				if ( $this->login( $auth_user, true ) ) {
					return false;
				} else {
					exit();
				}
			}

			// Redirect to the admin side if needed.
			if ( isset( $_POST['admin'] ) && '1' === $_POST['admin'] ) {
				wp_safe_redirect( get_option( 'siteurl' ) . '/wp-admin/' );
				die();
			}
		}

		return true;
	}


	public function auth( $signature, $func, $nonce, $pNossl ) {
		if ( empty( $signature ) || ! isset( $func ) || ( ! get_option( 'mainwp_child_pubkey' ) && ! get_option( 'mainwp_child_nossl_key' ) ) ) {
			$auth = false;
		} else {
			$nossl       = get_option( 'mainwp_child_nossl' );
			$serverNoSsl = ( isset( $pNossl ) && 1 === (int) $pNossl );

			if ( ( 1 === (int) $nossl ) || $serverNoSsl ) {
				$nossl_key = get_option( 'mainwp_child_nossl_key' );
				$auth      = hash_equals( md5( $func . $nonce . $nossl_key ), base64_decode( $signature ) ); // // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for begin reasons.								
			} else {
				$auth = openssl_verify( $func . $nonce, base64_decode( $signature ), base64_decode( get_option( 'mainwp_child_pubkey' ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for begin reasons.
				if ( 1 !== $auth ) {
					$auth = false;
				}
			}
		}

		return $auth;
	}

	public function parse_login_required() {

		global $current_user;

		$alter_login_required = false;
		$username             = rawurldecode( $_REQUEST['user'] );

		if ( isset( $_REQUEST['alt_user'] ) && ! empty( $_REQUEST['alt_user'] ) ) {
			$alter_login_required = self::instance()->check_login_as( $_REQUEST['alt_user'] );
			if ( $alter_login_required ) {
				$username = rawurldecode( $_REQUEST['alt_user'] );
			}
		}

		if ( is_user_logged_in() ) {
			global $current_user;
			if ( 10 !== $current_user->wp_user_level && ( ! isset( $current_user->user_level ) || 10 !== $current_user->user_level ) && ! current_user_can( 'level_10' ) ) {
				do_action( 'wp_logout' );
			}
		}

		$signature = rawurldecode( isset( $_REQUEST['mainwpsignature'] ) ? $_REQUEST['mainwpsignature'] : '' );
		$file      = '';
		if ( isset( $_REQUEST['f'] ) ) {
			$file = $_REQUEST['f'];
		} elseif ( isset( $_REQUEST['file'] ) ) {
			$file = $_REQUEST['file'];
		} elseif ( isset( $_REQUEST['fdl'] ) ) {
			$file = $_REQUEST['fdl'];
		}

		$auth = self::instance()->auth( $signature, rawurldecode( ( isset( $_REQUEST['where'] ) ? $_REQUEST['where'] : $file ) ), isset( $_REQUEST['nonce'] ) ? $_REQUEST['nonce'] : '', isset( $_REQUEST['nossl'] ) ? $_REQUEST['nossl'] : 0 );

		if ( ! $auth ) {
			return;
		}

		if ( ! is_user_logged_in() || $username !== $current_user->user_login ) {
			if ( ! $this->login( $username ) ) {
				return;
			}

			global $current_user;
			if ( 10 !== $current_user->wp_user_level && ( ! isset( $current_user->user_level ) || 10 !== $current_user->user_level ) && ! current_user_can( 'level_10' ) ) {
				// if is not alternative admin login.
				// it is connected admin login.
				if ( ! $alter_login_required ) {
					// log out if connected admin is not admin level 10.
					do_action( 'wp_logout' );

					return;
				}
			}
		}

		if ( isset( $_REQUEST['fdl'] ) ) {
			if ( stristr( $_REQUEST['fdl'], '..' ) ) {
				return;
			}

			MainWP_Utility::instance()->upload_file( $_REQUEST['fdl'], isset( $_REQUEST['foffset'] ) ? $_REQUEST['foffset'] : 0 );
			exit;
		}

		$where = isset( $_REQUEST['where'] ) ? $_REQUEST['where'] : '';
		if ( isset( $_POST['f'] ) || isset( $_POST['file'] ) ) {
			$file = '';
			if ( isset( $_POST['f'] ) ) {
				$file = $_POST['f'];
			} elseif ( isset( $_POST['file'] ) ) {
				$file = $_POST['file'];
			}

			$where = 'admin.php?page=mainwp_child_tab&tab=restore-clone';
			if ( '' === session_id() ) {
				session_start();
			}
			$_SESSION['file'] = $file;
			$_SESSION['size'] = $_POST['size'];
		}

		// to support open not wp-admin url.
		$open_location = isset( $_REQUEST['open_location'] ) ? $_REQUEST['open_location'] : '';
		if ( ! empty( $open_location ) ) {
			$open_location = base64_decode( $open_location ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for begin reasons.
			$_vars         = MainWP_Helper::parse_query( $open_location );
			$_path         = wp_parse_url( $open_location, PHP_URL_PATH );
			if ( isset( $_vars['_mwpNoneName'] ) && isset( $_vars['_mwpNoneValue'] ) ) {
				$_vars[ $_vars['_mwpNoneName'] ] = wp_create_nonce( $_vars['_mwpNoneValue'] );
				unset( $_vars['_mwpNoneName'] );
				unset( $_vars['_mwpNoneValue'] );
				$open_url = '';
				foreach ( $_vars as $key => $value ) {
					$open_url .= $key . '=' . $value . '&';
				}
				$open_url      = rtrim( $open_url, '&' );
				$open_location = '/wp-admin/' . $_path . '?' . $open_url;
			} else {
				if ( strpos( $open_location, 'nonce=child_temp_nonce' ) !== false ) {
					$open_location = str_replace( 'nonce=child_temp_nonce', 'nonce=' . wp_create_nonce( 'wp-ajax' ), $open_location );
				}
			}
			wp_safe_redirect( site_url() . $open_location );
			exit();
		}

		wp_safe_redirect( admin_url( $where ) );

		exit();
	}

	public function check_login() {

		if ( ! isset( $_POST['mainwpsignature'] ) || empty( $_POST['mainwpsignature'] ) ) {
			return false;
		}

		$file = '';
		if ( isset( $_REQUEST['f'] ) ) {
			$file = $_REQUEST['f'];
		} elseif ( isset( $_REQUEST['file'] ) ) {
			$file = $_REQUEST['file'];
		} elseif ( isset( $_REQUEST['fdl'] ) ) {
			$file = $_REQUEST['fdl'];
		}

		$auth = $this->auth( isset( $_POST['mainwpsignature'] ) ? rawurldecode( $_POST['mainwpsignature'] ) : '', isset( $_POST['function'] ) ? $_POST['function'] : rawurldecode( ( isset( $_REQUEST['where'] ) ? $_REQUEST['where'] : $file ) ), isset( $_POST['nonce'] ) ? $_POST['nonce'] : '', isset( $_POST['nossl'] ) ? $_POST['nossl'] : 0 );

		if ( ! $auth ) {
			MainWP_Helper::error( __( 'Authentication failed! Please deactivate and re-activate the MainWP Child plugin on this site.', 'mainwp-child' ) );
		}

		$auth_user = false;
		if ( $auth ) {
			// disable duo auth for mainwp.
			remove_action( 'init', 'duo_verify_auth', 10 );

			// Check if the user exists & is an administrator.
			if ( isset( $_POST['function'] ) && isset( $_POST['user'] ) ) {

				$user = null;

				if ( isset( $_POST['alt_user'] ) && ! empty( $_POST['alt_user'] ) ) {
					if ( $this->check_login_as( $_POST['alt_user'] ) ) {
						$auth_user = $_POST['alt_user'];
						$user      = get_user_by( 'login', $auth_user );
					}
				}

				// if not valid alternative admin.
				if ( ! $user ) {
					// check connected admin existed.
					$user      = get_user_by( 'login', $_POST['user'] );
					$auth_user = $_POST['user'];
				}

				if ( ! $user ) {
					MainWP_Helper::error( __( 'That administrator username was not found on this child site. Please verify that it is an existing administrator.', 'mainwp-child' ) );
				}

				if ( 10 != $user->wp_user_level && ( ! isset( $user->user_level ) || 10 != $user->user_level ) && ! $user->has_cap( 'level_10' ) ) {
					MainWP_Helper::error( __( 'That user is not an administrator. Please use an administrator user to establish the connection.', 'mainwp-child' ) );
				}

				$this->login( $auth_user );
			}

			if ( isset( $_POST['function'] ) && 'visitPermalink' === $_POST['function'] ) {

				if ( empty( $auth_user ) ) {
					$auth_user = $_POST['user'];
				}

				if ( $this->login( $auth_user, true ) ) {
					return;
				} else {
					exit();
				}
			}

			// Redirect to the admin part if needed.
			if ( isset( $_POST['admin'] ) && '1' === $_POST['admin'] ) {
				wp_safe_redirect( get_option( 'siteurl' ) . '/wp-admin/' );
				die();
			}
		}
	}

	/**
	 *
	 * Check to support login by alternative admin.
	 * Return false will login by connected admin user.
	 * Return true will try to login as alternative user.
	 */
	public function check_login_as( $alter_login ) {

		if ( ! empty( $alter_login ) ) {
			// check alternative admin existed.
			$user = get_user_by( 'login', $alter_login );

			if ( ! $user ) {
				// That administrator username was not found on this child site.
				return false;
			}

			if ( 10 != $user->wp_user_level && ( ! isset( $user->user_level ) || 10 != $user->user_level ) && ! $user->has_cap( 'level_10' ) ) {
				// That user is not an administrator.
				return false;
			}

			return true; // ok, will try to login by alternative user.
		}

		return false;
	}

	// Login.
	public function login( $username, $doAction = false ) {
		global $current_user;

		// Logout if required.
		if ( isset( $current_user->user_login ) ) {
			if ( $current_user->user_login === $username ) {

				// to fix issue multi user session.
				$user_id = wp_validate_auth_cookie();
				if ( $user_id && $user_id === $current_user->ID ) {
					return true;
				}

				wp_set_auth_cookie( $current_user->ID );
				return true;
			}
			do_action( 'wp_logout' );
		}

		$user = get_user_by( 'login', $username );
		if ( $user ) {
			wp_set_current_user( $user->ID );
			wp_set_auth_cookie( $user->ID );
			if ( $doAction ) {
				do_action( 'wp_login', $user->user_login );
			}

			return ( is_user_logged_in() && $current_user->user_login === $username );
		}

		return false;
	}


	public function check_other_auth() {
		$auths = get_option( 'mainwp_child_auth' );

		if ( ! $auths ) {
			$auths = array();
		}

		if ( ! isset( $auths['last'] ) || $auths['last'] < mktime( 0, 0, 0, date( 'm' ), date( 'd' ), date( 'Y' ) ) ) { // phpcs:ignore -- local time.
			// Generate code for today.
			for ( $i = 0; $i < $this->maxHistory; $i ++ ) {
				if ( ! isset( $auths[ $i + 1 ] ) ) {
					continue;
				}

				$auths[ $i ] = $auths[ $i + 1 ];
			}
			$newI = $this->maxHistory + 1;
			while ( isset( $auths[ $newI ] ) ) {
				unset( $auths[ $newI ++ ] );
			}
			$auths[ $this->maxHistory ] = md5( MainWP_Helper::rand_string( 14 ) );
			$auths['last']              = time();
			MainWP_Helper::update_option( 'mainwp_child_auth', $auths, 'yes' );
		}
	}

	public function is_valid_auth( $key ) {
		$auths = get_option( 'mainwp_child_auth' );
		if ( ! $auths ) {
			return false;
		}
		for ( $i = 0; $i <= $this->maxHistory; $i ++ ) {
			if ( isset( $auths[ $i ] ) && ( $auths[ $i ] === $key ) ) {
				return true;
			}
		}

		return false;
	}


	public function get_max_history() {
		return $this->maxHistory;
	}

}
