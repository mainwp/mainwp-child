<?php
/**
 * MainWP Connect
 *
 * Manage connection between MainWP Dashboard and the child site.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

/**
 * Class MainWP_Connect
 *
 * Manage connection between MainWP Dashboard and the child site.
 */
class MainWP_Connect {

	/**
	 * Public static variable to hold the single instance of the class.
	 *
	 * @var mixed Default null
	 */
	public static $instance = null;

	/**
	 * Private variable to hold the max history value.
	 *
	 * @var int $maxHistory Max history.
	 */
	private $maxHistory = 5;

	/**
	 * Method get_class_name()
	 *
	 * Get class name.
	 *
	 * @return string __CLASS__ Class name.
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * Method instance()
	 *
	 * Create a public static instance.
	 *
	 * @return mixed Class instance.
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Method register_site()
	 *
	 * Register the current WordPress site thus generating teh public key.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Branding::save_branding_options()
	 * @uses \MainWP\Child\MainWP_Child_Stats::get_site_stats()
	 * @uses \MainWP\Child\MainWP_Helper::instance()->error()
	 * @uses \MainWP\Child\MainWP_Helper::is_ssl_enabled()
	 * @uses \MainWP\Child\MainWP_Helper::update_option()
	 */
	public function register_site() { // phpcs:ignore -- Current complexity is the only way to achieve desired results, pull request solutions appreciated.

		/**
		 * Current user global.
		 *
		 * @global string
		 */
		global $current_user;

		$information = array();

		// Check if the user is valid & login.
		if ( ! isset( $_POST['user'] ) || ! isset( $_POST['pubkey'] ) ) {
			MainWP_Helper::instance()->error( sprintf( esc_html__( 'Public key could not be set. Please make sure that the OpenSSL library has been configured correctly on your MainWP Dashboard. For additional help, please check this %1$shelp document%2$s.', 'mainwp-child' ), '<strong><a href="https://kb.mainwp.com/docs/cant-connect-website-getting-the-invalid-request-error-message/" target="_blank">', '</a></strong>' ) );
		}

		// Already added - can't readd. Deactivate plugin.
		if ( get_option( 'mainwp_child_pubkey' ) ) {

			// Set disconnect status to yes here, it will empty after reconnected.
			MainWP_Child_Branding::instance()->save_branding_options( 'branding_disconnected', 'yes' );
			MainWP_Helper::instance()->error( esc_html__( 'Public key already set. Please deactivate & reactivate the MainWP Child plugin on the child site and try again.', 'mainwp-child' ) );
		}

		$uniqueId = MainWP_Helper::get_site_unique_id();
		// Check the Unique Security ID.
		if ( '' != $uniqueId ) {
			if ( ! isset( $_POST['uniqueId'] ) || ( '' === $_POST['uniqueId'] ) ) {
				MainWP_Helper::instance()->error( esc_html__( 'This child site is set to require a unique security ID. Please enter it before the connection can be established.', 'mainwp-child' ) );
			} elseif ( $uniqueId !== $_POST['uniqueId'] ) {
				MainWP_Helper::instance()->error( esc_html__( 'The unique security ID mismatch! Please correct it before the connection can be established.', 'mainwp-child' ) );
			}
		}

		// Check SSL Requirement.
		if ( ! MainWP_Helper::is_ssl_enabled() && ( ! defined( 'MAINWP_ALLOW_NOSSL_CONNECT' ) || ! MAINWP_ALLOW_NOSSL_CONNECT ) ) {
			MainWP_Helper::instance()->error( esc_html__( 'OpenSSL library is required on the child site to set up a secure connection.', 'mainwp-child' ) );
		}

		// Check if the user exists and if yes, check if it's Administartor user.
		if ( isset( $_POST['user'] ) ) {
			if ( empty( $_POST['user'] ) || ! $this->login( wp_unslash( $_POST['user'] ) ) ) {
				MainWP_Helper::instance()->error( esc_html__( 'Unexisting administrator user. Please verify that it is an existing administrator.', 'mainwp-child' ) );
			}
			if ( 10 !== $current_user->wp_user_level && ( ! isset( $current_user->user_level ) || 10 !== $current_user->user_level ) && ! $current_user->has_cap( 'level_10' ) ) {
				MainWP_Helper::instance()->error( esc_html__( 'User is not an administrator. Please use an administrator user to establish the connection.', 'mainwp-child' ) );
			}
		}

		// Update the mainwp_child_pubkey option.
		MainWP_Helper::update_option( 'mainwp_child_pubkey', ( isset( $_POST['pubkey'] ) ? base64_encode( wp_unslash( $_POST['pubkey'] ) ) : '' ), 'yes' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for the backwards compatibility.

		// Save the public key.
		MainWP_Helper::update_option( 'mainwp_child_server', ! empty( $_POST['server'] ) ? wp_unslash( $_POST['server'] ) : '' );

		// Save the nonce.
		MainWP_Helper::update_option( 'mainwp_child_nonce', 0 );

		// Update the mainwp_child_nossl option.
		MainWP_Helper::update_option( 'mainwp_child_nossl', ( '-1' === $_POST['pubkey'] || ! MainWP_Helper::is_ssl_enabled() ? 1 : 0 ), 'yes' );

		$information['nossl'] = ( '-1' === $_POST['pubkey'] || ! MainWP_Helper::is_ssl_enabled() ? 1 : 0 );

		if ( function_exists( 'random_bytes' ) ) {
			$nossl_key = random_bytes( 32 );
			$nossl_key = bin2hex( $nossl_key );
		} else {
			$nossl_key = uniqid( '', true );
		}

		// Update the mainwp_child_nossl_key option.
		MainWP_Helper::update_option( 'mainwp_child_nossl_key', $nossl_key, 'yes' );
		MainWP_Helper::update_option( 'mainwp_child_connected_admin', $current_user->user_login, 'yes' );

		$information['nosslkey'] = $nossl_key;
		$information['register'] = 'OK';
		$information['uniqueId'] = MainWP_Helper::get_site_unique_id();
		$information['user']     = isset( $_POST['user'] ) ? sanitize_text_field( wp_unslash( $_POST['user'] ) ) : '';

		MainWP_Child_Stats::get_instance()->get_site_stats( $information ); // get stats and exit.
	}


	/**
	 * Method parse_init_auth()
	 *
	 * Parse inistial authentication.
	 *
	 * @param  bool $auth True is authenticated, false if not.
	 *
	 * @return bool ture|false.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Callable::is_callable_function()
	 * @uses \MainWP\Child\MainWP_Child_Callable::is_callable_function_no_auth()
	 * @uses \MainWP\Child\MainWP_Helper::instance()->error()
	 */
	public function parse_init_auth( $auth = false ) { // phpcs:ignore -- Current complexity is the only way to achieve desired results, pull request solutions appreciated.

		if ( ! $auth && isset( $_POST['mainwpsignature'] ) ) { // with 'mainwpsignature' then need to callable functions.
			MainWP_Helper::instance()->error( esc_html__( 'Authentication failed! Please deactivate & re-activate the MainWP Child plugin on this child site and try again.', 'mainwp-child' ) );
		}

		if ( ! $auth && isset( $_POST['function'] ) ) {
			$func             = isset( $_POST['function'] ) ? sanitize_text_field( wp_unslash( $_POST['function'] ) ) : '';
			$callable         = MainWP_Child_Callable::get_instance()->is_callable_function( $func );
			$callable_no_auth = MainWP_Child_Callable::get_instance()->is_callable_function_no_auth( $func );

			if ( $callable && ! $callable_no_auth ) {
				MainWP_Helper::instance()->error( esc_html__( 'Authentication failed! Please deactivate & re-activate the MainWP Child plugin on this site and try again.', 'mainwp-child' ) );
			}
		}

		if ( $auth ) {
			$auth_user = false;
			// Check if the user exists & is an administrator.
			if ( isset( $_POST['function'] ) && isset( $_POST['user'] ) ) {

				$user = null;
				if ( isset( $_POST['alt_user'] ) && ! empty( $_POST['alt_user'] ) ) {
					$uname = isset( $_POST['alt_user'] ) ? sanitize_text_field( wp_unslash( $_POST['alt_user'] ) ) : '';
					if ( $this->check_login_as( $uname ) ) {
						$auth_user = $uname;
						// get alternative admin user.
						$user = get_user_by( 'login', $auth_user );
					}
				}

				// check just clone admin here.
				$just_clone_admin = get_option( 'mainwp_child_just_clone_admin' );
				$clone_sync       = false;
				if ( ! empty( $just_clone_admin ) ) {
					delete_option( 'mainwp_child_just_clone_admin' );
					if ( $username != $just_clone_admin ) {
						$username   = $just_clone_admin;
						$clone_sync = true;
					}
				}

				// if alternative admin not existed.
				if ( ! $user ) {
					// check connected admin existed.
					$uname     = isset( $_POST['user'] ) ? sanitize_text_field( wp_unslash( $_POST['user'] ) ) : '';
					$user      = get_user_by( 'login', $uname );
					$auth_user = $uname;
				}

				if ( ! $user ) {
					MainWP_Helper::instance()->error( esc_html__( 'Unexisting administrator user. Please verify that it is an existing administrator.', 'mainwp-child' ) );
				}

				if ( 10 != $user->wp_user_level && ( ! isset( $user->user_level ) || 10 != $user->user_level ) && ! $user->has_cap( 'level_10' ) ) {
					MainWP_Helper::instance()->error( esc_html__( 'User not administrator. Please use an administrator user to establish the connection.', 'mainwp-child' ) );
				}

				// try to login.
				$logged_in = $this->login( $auth_user );

				// authed.
				if ( $clone_sync && $logged_in ) {
					$information                            = array();
					$information['sync']                    = MainWP_Child_Stats::get_instance()->get_site_stats( array(), false );
					$information['sync']['clone_adminname'] = $just_clone_admin;
					MainWP_Helper::write( $information ); // forced exit to sync clone admin.
				}
			}

			if ( isset( $_POST['function'] ) && 'visitPermalink' === $_POST['function'] ) {

				if ( empty( $auth_user ) ) {
					$auth_user = isset( $_POST['user'] ) ? sanitize_text_field( wp_unslash( $_POST['user'] ) ) : '';
				}
				// try to login.
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

	/**
	 * Method auth()
	 *
	 * Connection authentication handler. Verifies that the signature is correct for the specified data using the public key associated with pub_key_id. This must be the public key corresponding to the private key used for signing.
	 *
	 * @param  string $signature MainWP Dashboard signature.
	 * @param  string $func      Function to run.
	 * @param  string $nonce     Security nonce.
	 * @param  int    $nossl     OpenSSL not availalbe. NoSSL connection required.
	 *
	 * @return int|bool $auth  Returns 1 if authenticated, false if authentication fails.
	 */
	public function auth( $signature, $func, $nonce, $nossl ) {
		if ( empty( $signature ) || ! isset( $func ) || ( ! get_option( 'mainwp_child_pubkey' ) && ! get_option( 'mainwp_child_nossl_key' ) ) ) {
			$auth = false;
		} else {
			$nossl       = get_option( 'mainwp_child_nossl' );
			$serverNoSsl = ( isset( $nossl ) && 1 === (int) $nossl );
			if ( ( 1 === (int) $nossl ) || $serverNoSsl ) {
				$nossl_key = get_option( 'mainwp_child_nossl_key' );
				$auth      = hash_equals( md5( $func . $nonce . $nossl_key ), base64_decode( $signature ) ); // // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible.
			} else {
				$auth = openssl_verify( $func . $nonce, base64_decode( $signature ), base64_decode( get_option( 'mainwp_child_pubkey' ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible.
				if ( 1 !== $auth ) {
					$auth = false;
				}
			}
		}
		return $auth;
	}

	/**
	 * Method parse_login_required()
	 *
	 * Check if the login process is required.
	 *
	 * @return bool Return true on success, false on failure.
	 */
	public function parse_login_required() { // phpcs:ignore -- Current complexity is the only way to achieve desired results, pull request solutions appreciated.

		/**
		 * Current user global.
		 *
		 * @global string
		 */
		global $current_user;

		$alter_login_required = false;
		$username             = isset( $_REQUEST['user'] ) ? rawurldecode( wp_unslash( $_REQUEST['user'] ) ) : '';

		if ( isset( $_REQUEST['alt_user'] ) ) {
			$alter_login_required = ! empty( $_REQUEST['alt_user'] ) ? self::instance()->check_login_as( wp_unslash( $_REQUEST['alt_user'] ) ) : false;
			if ( $alter_login_required ) {
				$username = isset( $_REQUEST['alt_user'] ) ? rawurldecode( wp_unslash( $_REQUEST['alt_user'] ) ) : '';
			}
		}

		if ( is_user_logged_in() ) {
			if ( 10 !== $current_user->wp_user_level && ( ! isset( $current_user->user_level ) || 10 !== $current_user->user_level ) && ! current_user_can( 'level_10' ) ) {
				do_action( 'wp_logout' );
			}
		}

		$signature = rawurldecode( isset( $_REQUEST['mainwpsignature'] ) ? wp_unslash( $_REQUEST['mainwpsignature'] ) : '' );

		$file = $this->get_request_files();

		$function = ! empty( $_POST['function'] ) ? sanitize_text_field( wp_unslash( $_POST['function'] ) ) : rawurldecode( ( isset( $_REQUEST['where'] ) ? wp_unslash( $_REQUEST['where'] ) : $file ) );
		$nonce    = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ) : '';
		$nossl    = isset( $_REQUEST['nossl'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['nossl'] ) ) : 0;

		$auth = self::instance()->auth( $signature, $function, $nonce, $nossl );

		if ( ! $auth ) {
			return false;
		}

		if ( ! is_user_logged_in() || $username !== $current_user->user_login ) {
			if ( ! $this->login( $username ) ) {
				return false;
			}

			if ( 10 !== $current_user->wp_user_level && ( ! isset( $current_user->user_level ) || 10 !== $current_user->user_level ) && ! current_user_can( 'level_10' ) ) {
				// if is not alternative admin login.
				// it is connected admin login.
				if ( ! $alter_login_required ) {
					// log out if connected admin is not admin level 10.
					do_action( 'wp_logout' );

					return false;
				}
			}
		}
		$this->check_redirects();
		return true;
	}

	/**
	 * Method get_request_files()
	 *
	 * Parse HTTP request to get files.
	 *
	 * @return resource Requested file.
	 */
	private function get_request_files() {
		$file = '';
		if ( isset( $_REQUEST['f'] ) ) {
			$file = ! empty( $_REQUEST['f'] ) ? wp_unslash( $_REQUEST['f'] ) : '';
		} elseif ( isset( $_REQUEST['file'] ) ) {
			$file = ! empty( $_REQUEST['file'] ) ? wp_unslash( $_REQUEST['file'] ) : '';
		} elseif ( isset( $_REQUEST['fdl'] ) ) {
			$file = ! empty( $_REQUEST['fdl'] ) ? wp_unslash( $_REQUEST['fdl'] ) : '';
		}
		return $file;
	}

	/**
	 * Method check_redirects()
	 *
	 * Handle redirects.
	 *
	 * @return bool Returns false if $_REQUEST['fdl'] is set.
	 *
	 * @uses \MainWP\Child\MainWP_Utility::upload_file()
	 */
	private function check_redirects() {
		if ( isset( $_REQUEST['fdl'] ) ) {
			$fdl = isset( $_REQUEST['fdl'] ) ? wp_unslash( $_REQUEST['fdl'] ) : '';
			if ( empty( $fdl ) || stristr( $fdl, '..' ) ) {
				return false;
			}

			$foffset = isset( $_REQUEST['foffset'] ) ? wp_unslash( $_REQUEST['foffset'] ) : 0;

			MainWP_Utility::instance()->upload_file( $fdl, $foffset );
			exit;
		}

		$open_location = ! empty( $_REQUEST['open_location'] ) ? wp_unslash( $_REQUEST['open_location'] ) : '';
		// support for custom wp-admin slug.
		if ( ! empty( $open_locatio ) ) {
			$this->open_location_redirect( $open_location );
		}

		$this->where_redirect();
	}

	/**
	 * Method open_location_redirect()
	 *
	 * Jump to the wanted location (child site WP Admin page).
	 *
	 * @param  string $open_location Desired location relative path.
	 */
	private function open_location_redirect( $open_location ) {
		$_vars = self::parse_query( $open_location );
		$_path = wp_parse_url( $open_location, PHP_URL_PATH );
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

	/**
	 * Method parse_query()
	 *
	 * Parse query
	 *
	 * @param  string $var Contains the parameter to prase.
	 *
	 * @return array  $arr Array containing parsed arguments.
	 */
	public static function parse_query( $var ) {
		$var = wp_parse_url( $var, PHP_URL_QUERY );
		$var = html_entity_decode( $var );
		$var = explode( '&', $var );
		$arr = array();
		foreach ( $var as $val ) {
			$x            = explode( '=', $val );
			$arr[ $x[0] ] = $x[1];
		}
		unset( $val, $x, $var );

		return $arr;
	}

	/**
	 * Method where_redirect()
	 *
	 * Safe redirect to wanted location.
	 */
	private function where_redirect() {
		$where = isset( $_REQUEST['where'] ) ? wp_unslash( $_REQUEST['where'] ) : '';
		if ( isset( $_POST['f'] ) || isset( $_POST['file'] ) ) {
			$file = '';
			if ( isset( $_POST['f'] ) ) {
				$file = ! empty( $_POST['f'] ) ? wp_unslash( $_POST['f'] ) : '';
			} elseif ( isset( $_POST['file'] ) ) {
				$file = ! empty( $_POST['file'] ) ? wp_unslash( $_POST['file'] ) : '';
			}
			$where = 'admin.php?page=mainwp_child_tab&tab=restore-clone';
			if ( '' === session_id() ) {
				session_start();
			}
			$_SESSION['file'] = $file;
			$_SESSION['size'] = isset( $_POST['size'] ) ? sanitize_text_field( wp_unslash( $_POST['size'] ) ) : '';
		}
		wp_safe_redirect( admin_url( $where ) );
		exit();
	}

	/**
	 * Method check_login()
	 *
	 * Auto-login user to the child site when the Open WP Admin feature from the MainWP Dashboard is used.
	 *
	 * @return bool Return false if $_POST['mainwpsignature'] is not set.
	 *
	 * @uses MainWP_Connect::login() Handle the login process.
	 * @uses \MainWP\Child\MainWP_Helper::instance()->error()
	 */
	public function check_login() { // phpcs:ignore -- Current complexity is the only way to achieve desired results, pull request solutions appreciated.
		if ( ! isset( $_POST['mainwpsignature'] ) || empty( $_POST['mainwpsignature'] ) ) {
			return false;
		}

		$file = $this->get_request_files();

		$mainwpsignature = isset( $_POST['mainwpsignature'] ) ? rawurldecode( wp_unslash( $_POST['mainwpsignature'] ) ) : '';
		$function        = ! empty( $_POST['function'] ) ? sanitize_text_field( wp_unslash( $_POST['function'] ) ) : rawurldecode( ( isset( $_REQUEST['where'] ) ? wp_unslash( $_REQUEST['where'] ) : $file ) );
		$nonce           = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		$nossl           = isset( $_POST['nossl'] ) ? sanitize_text_field( wp_unslash( $_POST['nossl'] ) ) : 0;

		$auth = self::instance()->auth( $mainwpsignature, $function, $nonce, $nossl );

		if ( ! $auth ) {
			MainWP_Helper::instance()->error( esc_html__( 'Authentication failed! Please deactivate and re-activate the MainWP Child plugin on this site.', 'mainwp-child' ) );
		}
		$auth_user = false;
		if ( $auth ) {
			// disable duo auth for mainwp.
			remove_action( 'init', 'duo_verify_auth', 10 );
			// Check if the user exists & is an administrator.
			if ( isset( $_POST['function'] ) && isset( $_POST['user'] ) ) {
				$user = null;
				if ( isset( $_POST['alt_user'] ) ) {
					if ( ! empty( $_POST['alt_user'] ) && $this->check_login_as( wp_unslash( $_POST['alt_user'] ) ) ) {
						$auth_user = isset( $_POST['alt_user'] ) ? sanitize_text_field( wp_unslash( $_POST['alt_user'] ) ) : '';
						$user      = get_user_by( 'login', $auth_user );
					}
				}
				// if not valid alternative admin.
				if ( ! $user ) {
					// check connected admin existed.
					$uname     = isset( $_POST['user'] ) ? sanitize_text_field( wp_unslash( $_POST['user'] ) ) : '';
					$user      = get_user_by( 'login', $uname );
					$auth_user = $uname;
				}
				if ( ! $user ) {
					MainWP_Helper::instance()->error( esc_html__( 'Unexisting administrator user. Please verify that it is an existing administrator.', 'mainwp-child' ) );
				}
				if ( 10 != $user->wp_user_level && ( ! isset( $user->user_level ) || 10 != $user->user_level ) && ! $user->has_cap( 'level_10' ) ) {
					MainWP_Helper::instance()->error( esc_html__( 'User not administrator. Please use an administrator user to establish the connection.', 'mainwp-child' ) );
				}
				$this->login( $auth_user );
			}
			if ( isset( $_POST['function'] ) && 'visitPermalink' === $_POST['function'] ) {
				if ( empty( $auth_user ) ) {
					$auth_user = isset( $_POST['user'] ) ? sanitize_text_field( wp_unslash( $_POST['user'] ) ) : '';
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
	 * Method check_login_as()
	 *
	 * Auto-login alternative user to the child site when the Open WP Admin feature from the MainWP Dashboard is used.
	 *
	 * @param string $alter_login Alternative user account to log into.
	 *
	 * @used-by MainWP_Child::check_login() Auto-login user to the child site when the Open WP Admin feature from the MainWP Dashboard is used.
	 *
	 * @return bool Return false will log in as default admin user. Return true will try to login as alternative user.
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

	/**
	 * Method login()
	 *
	 * The login process handler.
	 *
	 * @param  string $username Contains the account username.
	 * @param  bool   $doAction If true, run 'wp_login' action aftr the login.
	 *
	 * @used-by MainWP_Child::check_login() Auto-login user to the child site when the Open WP Admin feature from the MainWP Dashboard is used.
	 *
	 * @return bool true|false
	 */
	public function login( $username, $doAction = false ) {

		/**
		 * Current user global.
		 *
		 * @global string
		 */
		global $current_user;

		// Logout if required.
		if ( isset( $current_user->user_login ) ) {
			if ( $current_user->user_login === $username ) {

				// to fix issue multi user session.
				$user_id = wp_validate_auth_cookie();
				if ( $user_id && $user_id === $current_user->ID ) {
					$this->check_compatible_connect_info( true );
					return true;
				}

				wp_set_auth_cookie( $current_user->ID );

				$this->check_compatible_connect_info( true );
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

			$logged_in = ( is_user_logged_in() && $current_user->user_login === $username );

			if ( $logged_in ) {
				$this->check_compatible_connect_info( $logged_in );
			}

			return $logged_in;
		}

		return false;
	}

	/**
	 * Method check_other_auth()
	 *
	 * Check other authentication methods.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::rand_string()
	 * @uses \MainWP\Child\MainWP_Helper::update_option()
	 */
	public function check_other_auth() {
		$auths = get_option( 'mainwp_child_auth' );

		if ( ! is_array( $auths ) ) {
			$auths = array();
		}

		if ( ! isset( $auths['last'] ) || $auths['last'] < mktime( 0, 0, 0, date( 'm' ), date( 'd' ), date( 'Y' ) ) ) { // phpcs:ignore -- local time required to achieve desired results, pull request solutions appreciated.
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

	/**
	 * Method is_valid_auth()
	 *
	 * Check if authentication is valid.
	 *
	 * @param  string $key Contains the authentication key to check.
	 *
	 * @return bool true|false If valid authentication, return true, if not, return false.
	 */
	public function is_valid_auth( $key ) {
		$auths = get_option( 'mainwp_child_auth' );
		if ( ! is_array( $auths ) ) {
			return false;
		}
		for ( $i = 0; $i <= $this->maxHistory; $i ++ ) {
			if ( isset( $auths[ $i ] ) && ( $auths[ $i ] === $key ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Method get_max_history()
	 *
	 * @return int The max history value.
	 */
	public function get_max_history() {
		return $this->maxHistory;
	}

	/**
	 * Method check_compatible_connect_info()
	 *
	 * Check check compatible connected info.
	 *
	 * @param bool $logged_in logged in or not.
	 */
	public function check_compatible_connect_info( $logged_in ) {
		global $current_user;
		$connect_user = isset( $_POST['user'] ) ? wp_unslash( $_POST['user'] ) : '';
		if ( ! empty( $connect_user ) && $current_user->user_login == $connect_user ) {
			$connected_admin = get_option( 'mainwp_child_connected_admin', '' );
			if ( empty( $connected_admin ) ) {
				// to comparable.
				MainWP_Helper::update_option( 'mainwp_child_connected_admin', $connect_user, 'yes' );
			}
		}
	}

}

