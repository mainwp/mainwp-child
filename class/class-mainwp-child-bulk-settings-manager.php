<?php
/**
 * MainWP Child Bulk Settings Manager
 *
 * This file handles connecting to the child site as a browser in order performs an HTTP request using the POST method and returns its response.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

/**
 * Class MainWP_Child_Bulk_Settings_Manager
 *
 * Handles connecting to the child site as a browser in order performs an HTTP request using the POST method and returns its response.
 */
class MainWP_Child_Bulk_Settings_Manager {

	/**
	 * Public static variable to hold the single instance of the class.
	 *
	 * @var mixed Default null
	 */
	public static $instance = null;

	/**
	 * Public statis variable containing the synchronization information.
	 *
	 * @var array Synchronization information.
	 */
	public static $information = array();

	/**
	 * Public variable to hold the information about the language domain.
	 *
	 * @var string 'mainwp-child' languge domain.
	 */
	public $plugin_translate = 'mainwp-child';

	/**
	 * Create public static instance for MainWP_Child_Bulk_Settings_Manager.
	 *
	 * @return MainWP_Child_Bulk_Settings_Manager|null
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Save Settings & Visit Site as Browser actions.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::write()
	 */
	public function action() {

		/**
		 * MainWP bulk settings manager fatal error handler.
		 */
		function mainwp_bulk_settings_manager_handle_fatal_error() {
			$error = error_get_last();
			if ( isset( $error['type'] ) && in_array( $error['type'], array( 1, 4, 16, 64, 256 ) ) && isset( $error['message'] ) ) {
				MainWP_Helper::write( array( 'error' => 'MainWP_Child fatal error : ' . $error['message'] . ' Line: ' . $error['line'] . ' File: ' . $error['file'] ) );
			}
		}

		register_shutdown_function( '\MainWP\Child\mainwp_bulk_settings_manager_handle_fatal_error' );

		$mwp_action = ! empty( $_POST['action'] ) ? sanitize_text_field( wp_unslash( $_POST['action'] ) ) : '';
		switch ( $mwp_action ) {
			case 'skeleton_key_visit_site_as_browser': // deprecated.
				$information = $this->visit_site_as_browser();
				break;
			case 'bulk_settings_manager_visit_site_as_browser':
				$information = $this->visit_site_as_browser();
				break;
			case 'save_settings':
				$information = $this->save_settings();
				break;
			default:
				$information = array( 'error' => 'Unknown action' );
		}

		MainWP_Helper::write( $information );
		exit();
	}

	/**
	 * Visit site as a browser.
	 *
	 * @return array|string[] Response array or Error message string within an array.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::get_class_name()
	 */
	protected function visit_site_as_browser() { // phpcs:ignore -- ignore complex method notice.
		if ( ! isset( $_POST['url'] ) || ! is_string( wp_unslash( $_POST['url'] ) ) || strlen( wp_unslash( $_POST['url'] ) ) < 2 ) {
			return array( 'error' => 'Missing url' );
		}

		if ( ! isset( $_POST['args'] ) || ! is_array( $_POST['args'] ) ) {
			return array( 'error' => 'Missing args' );
		}

		$_POST = stripslashes_deep( wp_unslash( $_POST ) );

		$args = isset( $_POST['args'] ) ? wp_unslash( $_POST['args'] ) : array();

		$current_user = wp_get_current_user();

		$url = isset( $_POST['url'] ) ? '/' . wp_unslash( $_POST['url'] ) : '';

		$expiration = time() + 600;
		$manager    = \WP_Session_Tokens::get_instance( $current_user->ID );
		$token      = $manager->create( $expiration );

		$secure = is_ssl();
		if ( $secure ) {
			$auth_cookie_name = SECURE_AUTH_COOKIE;
			$scheme           = 'secure_auth';
		} else {
			$auth_cookie_name = AUTH_COOKIE;
			$scheme           = 'auth';
		}
		$auth_cookie                  = wp_generate_auth_cookie( $current_user->ID, $expiration, $scheme, $token );
		$logged_in_cookie             = wp_generate_auth_cookie( $current_user->ID, $expiration, 'logged_in', $token );
		$_COOKIE[ $auth_cookie_name ] = $auth_cookie;
		$_COOKIE[ LOGGED_IN_COOKIE ]  = $logged_in_cookie;
		$post_args                    = array();
		$post_args['body']            = array();
		$post_args['redirection']     = 5;
		$post_args['decompress']      = false;
		$post_args['cookies']         = array(
			new \WP_Http_Cookie(
				array(
					'name'  => $auth_cookie_name,
					'value' => $auth_cookie,
				)
			),
			new \WP_Http_Cookie(
				array(
					'name'  => LOGGED_IN_COOKIE,
					'value' => $logged_in_cookie,
				)
			),
		);

		$skip_invalid_nonce = false;
		if ( isset( $_REQUEST['skip_invalid_nonce'] ) && ! empty( $_REQUEST['skip_invalid_nonce'] ) ) {
			$skip_invalid_nonce = true;
		}

		if ( isset( $args['get'] ) ) {
			$get_args = $args['get'];
			parse_str( $args['get'], $get_args );
		}

		if ( ! isset( $get_args ) || ! is_array( $get_args ) ) {
			$get_args = array();
		}

		$get_args['bulk_settings_manageruse_nonce_key']  = intval( time() );
		$get_args['bulk_settings_manageruse_nonce_hmac'] = hash_hmac( 'sha256', $get_args['bulk_settings_manageruse_nonce_key'], NONCE_KEY );

		if ( true === $skip_invalid_nonce ) {
			$get_args['bulk_settings_skip_invalid_nonce'] = $skip_invalid_nonce;
		}

		$good_nonce = null;
		if ( isset( $args['nonce'] ) && ! empty( $args['nonce'] ) ) {
			parse_str( $args['nonce'], $temp_nonce );
			$good_nonce = $this->wp_create_nonce_recursive( $temp_nonce );
			$get_args   = array_merge( $get_args, $good_nonce );
		}

		if ( isset( $args['post'] ) ) {
			parse_str( $args['post'], $temp_post );
			if ( ! isset( $temp_post ) || ! is_array( $temp_post ) ) {
				$temp_post = array();
			}

			if ( ! empty( $good_nonce ) ) {
				$temp_post = array_merge( $temp_post, $good_nonce );
			}

			if ( true === $skip_invalid_nonce ) {
				$temp_post['bulk_settings_skip_invalid_nonce'] = $skip_invalid_nonce;
			}

			$post_args['body'] = $temp_post;
		}

		$post_args['timeout'] = 25;

		$full_url = add_query_arg( $get_args, get_site_url() . $url );

		add_filter( 'http_request_args', array( MainWP_Helper::get_class_name(), 'reject_unsafe_urls' ), 99, 2 );

		$response = wp_remote_post( $full_url, $post_args );

		if ( is_wp_error( $response ) ) {
			return array( 'error' => 'wp_remote_post error: ' . $response->get_error_message() );
		}

		$received_content = wp_remote_retrieve_body( $response );

		if ( preg_match( '/<mainwp>(.*)<\/mainwp>/', $received_content, $received_result ) > 0 ) {
			$received_content_mainwp = json_decode( base64_decode( $received_result[1] ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
			if ( isset( $received_content_mainwp['error'] ) ) {
				return array( 'error' => $received_content_mainwp['error'] );
			}
		}

		$search_ok_counter   = 0;
		$search_fail_counter = 0;

		if ( isset( $args['search']['ok'] ) ) {
			foreach ( $args['search']['ok'] as $search ) {
				if ( preg_match( '/' . preg_quote( $search, '/' ) . '/i', $received_content ) ) {
					++ $search_ok_counter;
				}
			}
		}

		if ( isset( $args['search']['fail'] ) ) {
			foreach ( $args['search']['fail'] as $search ) {
				if ( preg_match( '/' . preg_quote( $search, '/' ) . '/i', $received_content ) ) {
					++ $search_fail_counter;
				}
			}
		}
		unset( $get_args['bulk_settings_manageruse_nonce_key'] );
		unset( $get_args['bulk_settings_manageruse_nonce_hmac'] );

		return array(
			'success'             => 1,
			'content'             => $received_content,
			'url'                 => $full_url,
			'get'                 => $get_args,
			'post'                => $post_args['body'],
			'search_ok_counter'   => $search_ok_counter,
			'search_fail_counter' => $search_fail_counter,
		);
	}

	/**
	 * Create WP nonce.
	 *
	 * @param array $array An array containing the nonce.
	 *
	 * @return array An array containing the nonce.
	 */
	private function wp_create_nonce_recursive( $array ) {
		foreach ( $array as $key => $value ) {
			if ( is_array( $array[ $key ] ) ) {
				$array[ $key ] = $this->wp_create_nonce_recursive( $array[ $key ] );
			} else {
				$array[ $key ] = wp_create_nonce( $array[ $key ] );
			}
		}

		return $array;
	}

	/**
	 * Save site settings.
	 *
	 * @return array|bool|string[] Result array ok|error or FALSE or $whitelist_options[].
	 */
	public function save_settings() {
		$settings = isset( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array();

		if ( ! is_array( $settings ) || empty( $settings ) ) {
			return array( 'error' => esc_html__( 'Invalid data. Please check and try again.', 'mainwp-child' ) );
		}

		$whitelist_options = array(
			'general' => array( 'blogname', 'blogdescription', 'gmt_offset', 'date_format', 'time_format', 'start_of_week', 'timezone_string', 'WPLANG' ),
		);

		if ( ! is_multisite() ) {
			if ( ! defined( 'WP_SITEURL' ) ) {
				$whitelist_options['general'][] = 'siteurl';
			}
			if ( ! defined( 'WP_HOME' ) ) {
				$whitelist_options['general'][] = 'home';
			}

			$whitelist_options['general'][] = 'admin_email';
			$whitelist_options['general'][] = 'users_can_register';
			$whitelist_options['general'][] = 'default_role';
		}

		$whitelist_general = $whitelist_options['general'];

		if ( ! empty( $settings['WPLANG'] ) ) {
			require_once ABSPATH . 'wp-admin/includes/translation-install.php';
			if ( wp_can_install_language_pack() ) {
				$language = wp_download_language_pack( $settings['WPLANG'] );
				if ( $language ) {
					$settings['WPLANG'] = $language;
				}
			}
		}

		$updated = false;
		foreach ( $settings as $option => $value ) {
			if ( in_array( $option, $whitelist_general ) ) {
				if ( ! is_array( $value ) ) {
					$value = trim( $value );
				}
				$value = wp_unslash( $value );
				update_option( $option, $value );
				$updated = true;
			}
		}

		if ( ! $updated ) {
			return false;
		}

		return array( 'result' => 'ok' );
	}

}
