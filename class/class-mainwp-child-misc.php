<?php
/**
 * MainWP Child Misc functions
 *
 * This file is for misc functions that don't really belong anywhere else.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Required to achieve desired results, pull request solutions appreciated.

/**
 * Class MainWP_Child_Misc
 *
 * Misc functions that don't really belong anywhere else.
 */
class MainWP_Child_Misc {

	/**
	 * Public static variable to hold the single instance of the class.
	 *
	 * @var mixed Default null
	 */
	protected static $instance = null;

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
	 * MainWP_Child_Misc constructor.
	 *
	 * Run any time class is called.
	 */
	public function __construct() {
	}

	/**
	 * Method get_instance()
	 *
	 * Create a public static instance.
	 *
	 * @return mixed Class instance.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Method get_site_icon()
	 *
	 * Fire off the get favicon function and add to sync information.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Misc::get_favicon() Get the child site favicon.
	 * @uses \MainWP\Child\MainWP_Helper::write() Write response data to be sent to the MainWP Dashboard.
	 */
	public function get_site_icon() {
		$information = array();
		$url         = $this->get_favicon( true );
		if ( ! empty( $url ) ) {
			$information['faviIconUrl'] = $url;
		}
		MainWP_Helper::write( $information );
	}

	/**
	 * Method get_favicon()
	 *
	 * Get the child site favicon.
	 *
	 * @param bool $parse_page Whether or not to parse the page. Default: false.
	 *
	 * @uses MainWP_Child_Misc::try_to_parse_favicon() Try to parse child site URL for favicon.
	 * @uses get_site_icon_url() Returns the Site Icon URL.
	 * @see https://developer.wordpress.org/reference/functions/get_site_icon_url/
	 *
	 * @used-by MainWP_Child_Misc::get_site_icon() Fire off the get favicon function and add to sync information.
	 *
	 * @return string|bool Return favicon URL on success, FALSE on failure.
	 */
	public function get_favicon( $parse_page = false ) {

		$favi_url = '';
		$favi     = '';
		$site_url = get_option( 'siteurl' );
		if ( substr( $site_url, - 1 ) != '/' ) {
			$site_url .= '/';
		}

		if ( function_exists( '\get_site_icon_url' ) && \has_site_icon() ) {
			$favi     = \get_site_icon_url();
			$favi_url = $favi;
		}

		if ( empty( $favi ) ) {
			if ( file_exists( ABSPATH . 'favicon.ico' ) ) {
				$favi = 'favicon.ico';
			} elseif ( file_exists( ABSPATH . 'favicon.png' ) ) {
				$favi = 'favicon.png';
			}

			if ( ! empty( $favi ) ) {
				$favi_url = $site_url . $favi;
			}
		}

		if ( $parse_page ) {
			// try to parse page.
			if ( empty( $favi_url ) ) {
				$favi_url = $this->try_to_parse_favicon( $site_url );
			}

			if ( ! empty( $favi_url ) ) {
				return $favi_url;
			} else {
				return false;
			}
		} else {
			return $favi_url;
		}
	}

	/**
	 * Method try_to_parse_favicon()
	 *
	 * Try to parse child site URL for favicon.
	 *
	 * @param string $site_url Child site URL.
	 *
	 * @uses wp_remote_get() Performs an HTTP request using the GET method and returns its response.
	 * @see https://developer.wordpress.org/reference/functions/wp_remote_get/
	 *
	 * @used-by MainWP_Child_Misc::get_favicon() Get the child site favicon.
	 *
	 * @return string Parsed favicon.
	 */
	private function try_to_parse_favicon( $site_url ) {
		$request = wp_remote_get( $site_url, array( 'timeout' => 50 ) );
		$favi    = '';
		if ( is_array( $request ) && isset( $request['body'] ) ) {
			$preg_str1 = '/(<link\s+(?:[^\>]*)(?:rel="shortcut\s+icon"\s*)(?:[^>]*)?href="([^"]+)"(?:[^>]*)?>)/is';
			$preg_str2 = '/(<link\s+(?:[^\>]*)(?:rel="(?:shortcut\s+)?icon"\s*)(?:[^>]*)?href="([^"]+)"(?:[^>]*)?>)/is';

			if ( preg_match( $preg_str1, $request['body'], $matches ) ) {
				$favi = $matches[2];
			} elseif ( preg_match( $preg_str2, $request['body'], $matches ) ) {
				$favi = $matches[2];
			}
		}
		$favi_url = '';
		if ( ! empty( $favi ) ) {
			if ( false === strpos( $favi, 'http' ) ) {
				if ( 0 === strpos( $favi, '//' ) ) {
					if ( 0 === strpos( $site_url, 'https' ) ) {
						$favi_url = 'https:' . $favi;
					} else {
						$favi_url = 'http:' . $favi;
					}
				} else {
					$favi_url = $site_url . $favi;
				}
			} else {
				$favi_url = $favi;
			}
		}
		return $favi_url;
	}

	/**
	 * Method get_security_stats()
	 *
	 * Get security issues information.
	 *
	 * @param bool $return Either return or not.
	 *
	 * @return array
	 *
	 * @uses \MainWP\Child\MainWP_Helper::write()
	 * @uses \MainWP\Child\MainWP_Security::prevent_listing_ok()
	 * @uses \MainWP\Child\MainWP_Security::remove_wp_version_ok()
	 * @uses \MainWP\Child\MainWP_Security::remove_rsd_ok()
	 * @uses \MainWP\Child\MainWP_Security::remove_wlw_ok()
	 * @uses \MainWP\Child\MainWP_Security::remove_database_reporting_ok()
	 * @uses \MainWP\Child\MainWP_Security::remove_php_reporting_ok()
	 * @uses \MainWP\Child\MainWP_Security::remove_scripts_version_ok()
	 * @uses \MainWP\Child\MainWP_Security::remove_registered_versions_ok()
	 * @uses \MainWP\Child\MainWP_Security::admin_user_ok()
	 * @uses \MainWP\Child\MainWP_Security::remove_readme_ok()
	 */
	public function get_security_stats( $return = false ) { // phpcs:ignore -- required to achieve desired results, pull request solutions appreciated.
		$information = array();

		$information['listing']             = ( ! MainWP_Security::prevent_listing_ok() ? 'N' : 'Y' );
		$information['wp_version']          = ( ! MainWP_Security::remove_wp_version_ok() ? 'N' : 'Y' );
		$information['rsd']                 = ( ! MainWP_Security::remove_rsd_ok() ? 'N' : 'Y' );
		$information['wlw']                 = ( ! MainWP_Security::remove_wlw_ok() ? 'N' : 'Y' );
		$information['db_reporting']        = ( ! MainWP_Security::remove_database_reporting_ok() ? 'N' : 'Y' );
		$information['php_reporting']       = ( ! MainWP_Security::remove_php_reporting_ok() ? 'N' : 'Y' );
		$information['versions']            = ( ! MainWP_Security::remove_scripts_version_ok() || ! MainWP_Security::remove_styles_version_ok() || ! MainWP_Security::remove_generator_version_ok() ? 'N' : 'Y' );
		$information['registered_versions'] = ( MainWP_Security::remove_registered_versions_ok() ? 'Y' : 'N' );
		$information['admin']               = ( MainWP_Security::admin_user_ok() ? 'Y' : 'N' );
		$information['readme']              = ( MainWP_Security::remove_readme_ok() ? 'Y' : 'N' );
		$information['wp_uptodate']         = ( MainWP_Security::wpcore_updated_ok() ? 'Y' : 'N' );
		$information['phpversion_matched']  = ( MainWP_Security::phpversion_ok() ? 'Y' : 'N' );
		$information['sslprotocol']         = ( MainWP_Security::sslprotocol_ok() ? 'Y' : 'N' );
		$information['debug_disabled']      = ( MainWP_Security::debug_disabled_ok() ? 'Y' : 'N' );

		if ( $return ) {
			return $information;
		}

		MainWP_Helper::write( $information );
	}


	/**
	 * Method do_security_fix()
	 *
	 * Fix detected security issues and set feedback to sync information.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::update_option() Update option by name.
	 * @uses \MainWP\Child\MainWP_Helper::write() Write response data to be sent to the MainWP Dashboard.
	 * @uses \MainWP\Child\MainWP_Security::prevent_listing()
	 * @uses \MainWP\Child\MainWP_Security::prevent_listing_ok()
	 * @uses \MainWP\Child\MainWP_Security::remove_wp_version()
	 * @uses \MainWP\Child\MainWP_Security::remove_wp_version_ok()
	 * @uses \MainWP\Child\MainWP_Security::remove_rsd()
	 * @uses \MainWP\Child\MainWP_Security::remove_rsd_ok()
	 * @uses \MainWP\Child\MainWP_Security::remove_wlw()
	 * @uses \MainWP\Child\MainWP_Security::remove_wlw_ok()
	 * @uses \MainWP\Child\MainWP_Security::remove_database_reporting()
	 * @uses \MainWP\Child\MainWP_Security::remove_database_reporting_ok()
	 * @uses \MainWP\Child\MainWP_Security::remove_php_reporting()
	 * @uses \MainWP\Child\MainWP_Security::remove_php_reporting_ok()
	 * @uses \MainWP\Child\MainWP_Security::remove_generator_version()
	 * @uses \MainWP\Child\MainWP_Security::admin_user_ok()
	 * @uses \MainWP\Child\MainWP_Security::remove_readme()
	 * @uses \MainWP\Child\MainWP_Security::remove_readme_ok()
	 * @uses \MainWP\Child\MainWP_Child_Stats::get_site_stats()
	 */
	public function do_security_fix() { // phpcs:ignore -- Current complexity is the only way to achieve desired results, pull request solutions appreciated.
		$sync = false;

		$feature = isset( $_POST['feature'] ) ? sanitize_text_field( wp_unslash( $_POST['feature'] ) ) : '';

		if ( 'all' === $feature ) {
			$sync = true;
		}

		$skips = isset( $_POST['skip_features'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['skip_features'] ) ) : array();
		if ( ! is_array( $skips ) ) {
			$skips = array();
		}

		$information = array();
		$security    = get_option( 'mainwp_security' );
		if ( ! is_array( $security ) ) {
			$security = array();
		}

		if ( 'all' === $feature || 'listing' === $feature ) {
			if ( ! in_array( 'listing', $skips ) ) {
				MainWP_Security::prevent_listing();
			}
			$information['listing'] = ( ! MainWP_Security::prevent_listing_ok() ? 'N' : 'Y' );
		}

		if ( 'all' === $feature || 'wp_version' === $feature ) {
			if ( ! in_array( 'wp_version', $skips ) ) {
				$security['wp_version'] = true;
				MainWP_Security::remove_wp_version( true );
			}
			$information['wp_version'] = ( ! MainWP_Security::remove_wp_version_ok() ? 'N' : 'Y' );
		}

		if ( 'all' === $feature || 'rsd' === $feature ) {
			if ( ! in_array( 'rsd', $skips ) ) {
				$security['rsd'] = true;
				MainWP_Security::remove_rsd( true );
			}
			$information['rsd'] = ( ! MainWP_Security::remove_rsd_ok() ? 'N' : 'Y' );
		}

		if ( 'all' === $feature || 'wlw' === $feature ) {
			if ( ! in_array( 'wlw', $skips ) ) {
				$security['wlw'] = true;
				MainWP_Security::remove_wlw( true );
			}
			$information['wlw'] = ( ! MainWP_Security::remove_wlw_ok() ? 'N' : 'Y' );
		}

		if ( 'all' === $feature || 'db_reporting' === $feature ) {
			if ( ! in_array( 'db_reporting', $skips ) ) {
				MainWP_Security::remove_database_reporting();
			}
			$information['db_reporting'] = ( ! MainWP_Security::remove_database_reporting_ok() ? 'N' : 'Y' );
		}

		if ( 'all' === $feature || 'php_reporting' === $feature ) {
			if ( ! in_array( 'php_reporting', $skips ) ) {
				$security['php_reporting'] = true;
				MainWP_Security::remove_php_reporting( true );
			}
			$information['php_reporting'] = ( ! MainWP_Security::remove_php_reporting_ok() ? 'N' : 'Y' );
		}

		if ( 'all' === $feature || 'versions' === $feature ) {
			if ( ! in_array( 'versions', $skips ) ) {
				$security['scripts_version']   = true;
				$security['styles_version']    = true;
				$security['generator_version'] = true;
				MainWP_Security::remove_generator_version( true );
				$information['versions'] = 'Y';
			}
		}

		if ( 'all' === $feature || 'registered_versions' === $feature ) {
			if ( ! in_array( 'registered_versions', $skips ) ) {
				$security['registered_versions']    = true;
				$information['registered_versions'] = 'Y';
			}
		}

		if ( 'all' === $feature || 'admin' === $feature ) {
			$information['admin'] = ( ! MainWP_Security::admin_user_ok() ? 'N' : 'Y' );
		}

		if ( 'all' === $feature || 'readme' === $feature ) {
			if ( ! in_array( 'readme', $skips ) ) {
				$security['readme'] = true;
				MainWP_Security::remove_readme( true );
			}
			$information['readme'] = ( MainWP_Security::remove_readme_ok() ? 'Y' : 'N' );
		}

		MainWP_Helper::update_option( 'mainwp_security', $security, 'yes' );

		if ( $sync ) {
			$information['sync'] = MainWP_Child_Stats::get_instance()->get_site_stats( array(), false );
		}
		MainWP_Helper::write( $information );
	}

	/**
	 * Method do_security_un_fix()
	 *
	 * Unfix fixed child site security issues.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::update_option() Update option by name.
	 * @uses \MainWP\Child\MainWP_Helper::write() Write response data to be sent to the MainWP Dashboard.
	 * @uses \MainWP\Child\MainWP_Security::remove_readme_ok()
	 * @uses \MainWP\Child\MainWP_Child_Stats::get_site_stats()
	 */
	public function do_security_un_fix() {
		$information = array();

		$feature = isset( $_POST['feature'] ) ? sanitize_text_field( wp_unslash( $_POST['feature'] ) ) : '';

		$sync = false;
		if ( 'all' === $feature ) {
			$sync = true;
		}

		$security = get_option( 'mainwp_security' );

		if ( 'all' === $feature || 'wp_version' === $feature ) {
			$security['wp_version']    = false;
			$information['wp_version'] = 'N';
		}

		if ( 'all' === $feature || 'rsd' === $feature ) {
			$security['rsd']    = false;
			$information['rsd'] = 'N';
		}

		if ( 'all' === $feature || 'wlw' === $feature ) {
			$security['wlw']    = false;
			$information['wlw'] = 'N';
		}

		if ( 'all' === $feature || 'php_reporting' === $feature ) {
			$security['php_reporting']    = false;
			$information['php_reporting'] = 'N';
		}

		if ( 'all' === $feature || 'versions' === $feature ) {
			$security['scripts_version']   = false;
			$security['styles_version']    = false;
			$security['generator_version'] = false;
			$information['versions']       = 'N';
		}

		if ( 'all' === $feature || 'registered_versions' === $feature ) {
			$security['registered_versions']    = false;
			$information['registered_versions'] = 'N';
		}
		if ( 'all' === $feature || 'readme' === $feature ) {
			$security['readme']    = false;
			$information['readme'] = MainWP_Security::remove_readme_ok();
		}

		MainWP_Helper::update_option( 'mainwp_security', $security, 'yes' );

		if ( $sync ) {
			$information['sync'] = MainWP_Child_Stats::get_instance()->get_site_stats( array(), false );
		}

		MainWP_Helper::write( $information );
	}

	/**
	 * Method settings_tools()
	 *
	 * Fire off misc actions and set feedback to the sync information.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::write() Write response data to be sent to the MainWP Dashboard.
	 * @uses wp_destroy_all_sessions() Remove all session tokens for the current user from the database.
	 * @see https://developer.wordpress.org/reference/functions/wp_destroy_all_sessions/
	 *
	 * @uses wp_get_all_sessions() Retrieve a list of sessions for the current user.
	 * @see https://developer.wordpress.org/reference/functions/wp_get_all_sessions/
	 */
	public function settings_tools() {
		if ( isset( $_POST['action'] ) ) {
			$mwp_action = ! empty( $_POST['action'] ) ? sanitize_text_field( wp_unslash( $_POST['action'] ) ) : '';
			switch ( $mwp_action ) {
				case 'force_destroy_sessions':
					if ( 0 === get_current_user_id() ) {
						MainWP_Helper::write( array( 'error' => esc_html__( 'Cannot get user_id', 'mainwp-child' ) ) );
					}

					wp_destroy_all_sessions();

					$sessions = wp_get_all_sessions();

					if ( empty( $sessions ) ) {
						MainWP_Helper::write( array( 'success' => 1 ) );
					} else {
						MainWP_Helper::write( array( 'error' => esc_html__( 'Cannot destroy sessions', 'mainwp-child' ) ) );
					}
					break;

				default:
					MainWP_Helper::write( array( 'error' => esc_html__( 'Invalid action', 'mainwp-child' ) ) );
			}
		} else {
			MainWP_Helper::write( array( 'error' => esc_html__( 'Missing action', 'mainwp-child' ) ) );
		}
	}

	/**
	 * Method uploader_action()
	 *
	 * Initiate the file upload action.
	 *
	 * @return void
	 *
	 * @uses \MainWP\Child\MainWP_Helper::write() Write response data to be sent to the MainWP Dashboard.
	 * @uses \MainWP\Child\MainWP_Child_Misc::uploader_upload_file() Upload file from the MainWP Dashboard.
	 */
	public function uploader_action() {
		$file_url    = isset( $_POST['url'] ) ? MainWP_Utility::instance()->maybe_base64_decode( wp_unslash( $_POST['url'] ) ) : '';
		$path        = isset( $_POST['path'] ) ? wp_unslash( $_POST['path'] ) : '';
		$filename    = isset( $_POST['filename'] ) ? wp_unslash( $_POST['filename'] ) : '';
		$information = array();

		if ( empty( $file_url ) || empty( $path ) ) {
			MainWP_Helper::write( $information );

			return;
		}

		if ( strpos( $path, 'wp-content' ) === 0 ) {
			$path = basename( WP_CONTENT_DIR ) . substr( $path, 10 );
		} elseif ( strpos( $path, 'wp-includes' ) === 0 ) {
			$path = WPINC . substr( $path, 11 );
		}

		if ( '/' === $path ) {
			$dir = ABSPATH;
		} else {
			$path = str_replace( ' ', '-', $path );
			$path = str_replace( '.', '-', $path );
			$dir  = ABSPATH . $path;
		}

		if ( ! file_exists( $dir ) ) {
			if ( false === mkdir( $dir, 0777, true ) ) {
				$information['error'] = 'ERRORCREATEDIR';
				MainWP_Helper::write( $information );

				return;
			}
		}

		try {
			$upload = $this->uploader_upload_file( $file_url, $dir, $filename );
			if ( null !== $upload ) {
				$information['success'] = true;
			}
		} catch ( \Exception $e ) {
			$information['error'] = $e->getMessage();
		}
		MainWP_Helper::write( $information );
	}


	/**
	 * Method uploader_upload_file()
	 *
	 * Upload file from the MainWP Dashboard.
	 *
	 * @param string $file_url  URL of file to be uploaded.
	 * @param string $path      Path to upload to.
	 * @param string $file_name Name of file to upload.
	 *
	 * @uses wp_remote_get() Performs an HTTP request using the GET method and returns its response.
	 * @see https://developer.wordpress.org/reference/functions/wp_remote_get/
	 *
	 * @uses sanitize_file_name() Sanitizes a filename, replacing whitespace with dashes.
	 * @see https://developer.wordpress.org/reference/functions/sanitize_file_name/
	 *
	 * @uses is_wp_error() Check whether variable is a WordPress Error.
	 * @see https://developer.wordpress.org/reference/functions/is_wp_error/
	 *
	 * @uses wp_remote_retrieve_response_code() Retrieve only the response code from the raw response.
	 * @see https://developer.wordpress.org/reference/functions/wp_remote_retrieve_response_code/
	 *
	 * @uses wp_remote_retrieve_response_message() Retrieve only the response message from the raw response.
	 * @see https://developer.wordpress.org/reference/functions/wp_remote_retrieve_response_message/
	 *
	 * @used-by MainWP_Child_Misc::uploader_action() Initiate the file upload action.
	 *
	 * @throws \Exception Error message.
	 *
	 * @return array Full path and file name of uploaded file.
	 */
	public function uploader_upload_file( $file_url, $path, $file_name ) {
		// Fixes: Uploader Extension rename htaccess file issue.
		if ( '.htaccess' != $file_name && '.htpasswd' != $file_name ) {
			$file_name = sanitize_file_name( $file_name );
		}

		$full_file_name = $path . DIRECTORY_SEPARATOR . $file_name;

		$response = wp_remote_get(
			$file_url,
			array(
				'timeout'  => 10 * 60 * 60,
				'stream'   => true,
				'filename' => $full_file_name,
			)
		);

		if ( is_wp_error( $response ) ) {
			unlink( $full_file_name );
			throw new \Exception( 'Error: ' . $response->get_error_message() );
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			unlink( $full_file_name );
			throw new \Exception( 'Error 404: ' . trim( wp_remote_retrieve_response_message( $response ) ) );
		}
		$fix_name = true;
		if ( '.phpfile.txt' === substr( $file_name, - 12 ) ) {
			$new_file_name = substr( $file_name, 0, - 12 ) . '.php';
			$new_file_name = $path . DIRECTORY_SEPARATOR . $new_file_name;
		} elseif ( 0 === strpos( $file_name, 'fix_underscore' ) ) {
			$new_file_name = str_replace( 'fix_underscore', '', $file_name );
			$new_file_name = $path . DIRECTORY_SEPARATOR . $new_file_name;
		} else {
			$fix_name = false;
		}

		if ( $fix_name ) {
			$moved = rename( $full_file_name, $new_file_name );
			if ( $moved ) {
				return array( 'path' => $new_file_name );
			} else {
				unlink( $full_file_name );
				throw new \Exception( 'Error: Copy file.' );
			}
		}

		return array( 'path' => $full_file_name );
	}

	/**
	 * Method code_snippet()
	 *
	 * Initiate Code Snippet actions run_snippet, save_snippet and delete_snippet.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::write() Write response data to be sent to the MainWP Dashboard.
	 * @uses \MainWP\Child\MainWP_Utility::execute_snippet() Execute code snippet.
	 * @uses \MainWP\Child\MainWP_Child_Misc::snippet_save_snippet() Save code snippet.
	 * @uses \MainWP\Child\MainWP_Child_Misc::snippet_delete_snippet() Delete code snippet.
	 * @uses get_option() Retrieves an option value based on an option name.
	 * @see https://developer.wordpress.org/reference/functions/get_option/
	 * @uses \MainWP\Child\MainWP_Utility::execute_snippet()
	 */
	public function code_snippet() {

		$action = ! empty( $_POST['action'] ) ? sanitize_text_field( wp_unslash( $_POST['action'] ) ) : '';
		$type   = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
		$slug   = isset( $_POST['slug'] ) ? wp_unslash( $_POST['slug'] ) : '';

		$snippets = get_option( 'mainwp_ext_code_snippets' );

		if ( ! is_array( $snippets ) ) {
			$snippets = array();
		}

		if ( 'run_snippet' === $action || 'save_snippet' === $action ) {
			if ( ! isset( $_POST['code'] ) ) {
				MainWP_Helper::write( array( 'status' => 'FAIL' ) );
			}
		}

		$code = isset( $_POST['code'] ) ? wp_unslash( $_POST['code'] ) : '';

		$information = array();
		if ( 'run_snippet' === $action ) {
			$information = MainWP_Utility::execute_snippet( $code );
		} elseif ( 'save_snippet' === $action ) {
			$information = $this->snippet_save_snippet( $slug, $type, $code, $snippets );
		} elseif ( 'delete_snippet' === $action ) {
			$information = $this->snippet_delete_snippet( $slug, $type, $snippets );
		}

		if ( empty( $information ) ) {
			$information = array( 'status' => 'FAIL' );
		}

		MainWP_Helper::write( $information );
	}

	/**
	 * Method snippet_save_snippet()
	 *
	 * Save code snippet.
	 *
	 * @param string $slug Snippet slug.
	 * @param string $type Type of snippet.
	 * @param string $code Snippet code.
	 * @param array  $snippets An array containing all snippets.
	 *
	 * @return array $return Status response.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::update_option() Update option by name.
	 * @uses \MainWP\Child\MainWP_Child_Misc::snippet_update_wp_config() Update the child site wp-config.php file.
	 *
	 * @used-by \MainWP\Child\MainWP_Child_Misc::code_snippet() Initiate Code Snippet actions run_snippet, save_snippet and delete_snippet.
	 */
	private function snippet_save_snippet( $slug, $type, $code, $snippets ) {
		$return = array();
		if ( 'C' === $type ) { // save into wp-config file.
			if ( false !== $this->snippet_update_wp_config( 'save', $slug, $code ) ) {
				$return['status'] = 'SUCCESS';
			}
		} else {
			$snippets[ $slug ] = $code;
			if ( MainWP_Helper::update_option( 'mainwp_ext_code_snippets', $snippets ) ) {
				$return['status'] = 'SUCCESS';
			}
		}
		MainWP_Helper::update_option( 'mainwp_ext_snippets_enabled', true, 'yes' );

		return $return;
	}

	/**
	 * Method snippet_delete_snippet()
	 *
	 * Delete code snippet.
	 *
	 * @param string $slug Snippet slug.
	 * @param string $type Type of snippet.
	 * @param array  $snippets An array containing all snippets.
	 *
	 * @return array $return Status response.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::update_option() Update option by name.
	 * @uses \MainWP\Child\MainWP_Child_Misc::snippet_update_wp_config() Update the child site wp-config.php file.
	 *
	 * @used-by \MainWP\Child\MainWP_Child_Misc::code_snippet() Initiate Code Snippet actions run_snippet, save_snippet and delete_snippet.
	 */
	private function snippet_delete_snippet( $slug, $type, $snippets ) {
		$return = array();
		if ( 'C' === $type ) { // delete in wp-config file.
			if ( false !== $this->snippet_update_wp_config( 'delete', $slug ) ) {
				$return['status'] = 'SUCCESS';
			}
		} else {
			if ( isset( $snippets[ $slug ] ) ) {
				unset( $snippets[ $slug ] );
				if ( MainWP_Helper::update_option( 'mainwp_ext_code_snippets', $snippets ) ) {
					$return['status'] = 'SUCCESS';
				}
			} else {
				$return['status'] = 'SUCCESS';
			}
		}
		return $return;
	}

	/**
	 * Method snippet_update_wp_config()
	 *
	 * Update the child site wp-config.php file.
	 *
	 * @param string $action Action to perform: Delete, Save.
	 * @param string $slug   Snippet slug.
	 * @param string $code   Code snippet.
	 *
	 * @used-by MainWP_Child_Misc::snippet_save_snippet() Save code snippet.
	 * @used-by MainWP_Child_Misc::snippet_delete_snippet() Delete code snippet.
	 *
	 * @return bool If remvoed, return true, if not, return false.
	 */
	public function snippet_update_wp_config( $action, $slug, $code = '' ) {

		$config_file = '';
		if ( file_exists( ABSPATH . 'wp-config.php' ) ) {
			// The config file resides in ABSPATH.
			$config_file = ABSPATH . 'wp-config.php';
		} elseif ( file_exists( dirname( ABSPATH ) . '/wp-config.php' ) && ! file_exists( dirname( ABSPATH ) . '/wp-settings.php' ) ) {
			// The config file resides one level above ABSPATH but is not part of another install.
			$config_file = dirname( ABSPATH ) . '/wp-config.php';
		}

		if ( ! empty( $config_file ) ) {
			$wpConfig = file_get_contents( $config_file );

			if ( 'delete' === $action ) {
				$wpConfig = preg_replace( '/' . PHP_EOL . '{1,2}\/\*\*\*snippet_' . $slug . '\*\*\*\/(.*)\/\*\*\*end_' . $slug . '\*\*\*\/' . PHP_EOL . '/is', '', $wpConfig );
			} elseif ( 'save' === $action ) {
				$wpConfig = preg_replace( '/(\$table_prefix *= *[\'"][^\'|^"]*[\'"] *;)/is', '${1}' . PHP_EOL . PHP_EOL . '/***snippet_' . $slug . '***/' . PHP_EOL . $code . PHP_EOL . '/***end_' . $slug . '***/' . PHP_EOL, $wpConfig );
			}
			file_put_contents( $config_file, $wpConfig );
			return true;
		}
		return false;
	}

}
