<?php
namespace MainWP\Child;

class MainWP_Child_Server_Information {

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

	public static function init() {
		add_action(
			'wp_ajax_mainwp-child_dismiss_warnings',
			array(
				self::get_class_name(),
				'dismiss_warnings',
			)
		);
	}

	public static function dismiss_warnings() {
		if ( isset( $_POST['what'] ) ) {
			$dismissWarnings = get_option( 'mainwp_child_dismiss_warnings' );
			if ( ! is_array( $dismissWarnings ) ) {
				$dismissWarnings = array();
			}
			if ( 'warning' == $_POST['what'] ) {
				if ( isset( $_POST['warnings'] ) ) {
					$warnings = intval( $_POST['warnings'] );
				} else {
					$warnings = self::get_warnings();
				}
				$dismissWarnings['warnings'] = $warnings;
			}
			MainWP_Helper::update_option( 'mainwp_child_dismiss_warnings', $dismissWarnings );
		}
	}

	public static function get_warnings() {
		$i = 0;
		if ( ! self::check( '>=', '3.4', 'get_wordpress_version' ) ) {
			$i ++;
		}
		if ( ! self::check( '>=', '5.2.4', 'get_php_version' ) ) {
			$i ++;
		}
		if ( ! self::check( '>=', '5.0', 'get_my_sql_version' ) ) {
			$i ++;
		}
		if ( ! self::check( '>=', '30', 'get_max_execution_time', '=', '0' ) ) {
			$i ++;
		}
		if ( ! self::check( '>=', '2M', 'get_upload_max_filesize', null, null, true ) ) {
			$i ++;
		}
		if ( ! self::check( '>=', '2M', 'get_post_max_size', null, null, true ) ) {
			$i ++;
		}
		if ( ! self::check( '>=', '10000', 'get_output_buffer_size' ) ) {
			$i ++;
		}
		if ( ! self::check_mainwp_directory() ) {
			$i ++;
		}

		return $i;
	}

	public function check_mainwp_directory( &$message = '', &$path = '' ) {
		$path = '';
		try {
			$dirs = MainWP_Helper::get_mainwp_dir( null, false );
			$path = $dirs[0];
		} catch ( \Exception $e ) {
			$message = $e->getMessage();
			return false;
		}

		if ( ! is_dir( dirname( $path ) ) ) {
			$message = 'Directory not found';
			return false;
		}

		$hasWPFileSystem = MainWP_Helper::get_wp_filesystem();

		global $wp_filesystem;

		if ( $hasWPFileSystem && ! empty( $wp_filesystem ) ) {
			if ( ! $wp_filesystem->is_writable( $path ) ) {
				$message = 'Directory not writable';
				return false;
			}
		} else {
			if ( ! is_writable( $path ) ) {
				$message = 'Directory not writable';
				return false;
			}
		}
		$message = 'Writable';
		return true;
	}

	public static function check( $pCompare, $pVersion, $pGetter, $pExtraCompare = null, $pExtraVersion = null, $sizeCompare = false ) {
		$currentVersion = call_user_func( array( self::get_class_name(), $pGetter ) );

		if ( $sizeCompare ) {
			return self::filesize_compare( $currentVersion, $pVersion, $pCompare );
		} else {
			return ( version_compare( $currentVersion, $pVersion, $pCompare ) || ( ( null !== $pExtraCompare ) && version_compare( $currentVersion, $pExtraVersion, $pExtraCompare ) ) );
		}
	}

	public static function filesize_compare( $value1, $value2, $operator = null ) {
		if ( false !== strpos( $value1, 'G' ) ) {
			$value1 = preg_replace( '/[A-Za-z]/', '', $value1 );
			$value1 = intval( $value1 ) * 1024;
		} else {
			$value1 = preg_replace( '/[A-Za-z]/', '', $value1 );
		}

		if ( false !== strpos( $value2, 'G' ) ) {
			$value2 = preg_replace( '/[A-Za-z]/', '', $value2 );
			$value2 = intval( $value2 ) * 1024;
		} else {
			$value2 = preg_replace( '/[A-Za-z]/', '', $value2 );
		}

		return version_compare( $value1, $value2, $operator );
	}

	public static function get_curl_support() {
		return function_exists( 'curl_version' );
	}

	public static function get_curl_timeout() {
		return ini_get( 'default_socket_timeout' );
	}

	public static function get_curl_version() {
		$curlversion = curl_version();

		return $curlversion['version'];
	}

	public static function curlssl_compare( $value, $operator = null ) {
		if ( isset( $value['version_number'] ) && defined( 'OPENSSL_VERSION_NUMBER' ) ) {
			return version_compare( OPENSSL_VERSION_NUMBER, $value['version_number'], $operator );
		}

		return false;
	}

	public static function get_curl_ssl_version() {
		$curlversion = curl_version();

		return $curlversion['ssl_version'];
	}

	public static function mainwp_required_functions() {
		$disabled_functions = ini_get( 'disable_functions' );
		if ( '' !== $disabled_functions ) {
			$arr = explode( ',', $disabled_functions );
			sort( $arr );
			$arr_length = count( $arr );
			for ( $i = 0; $i < $arr_length; $i ++ ) {
				echo esc_html( $arr[ $i ] . ', ' );
			}
		} else {
			echo esc_html__( 'No functions disabled', 'mainwp-child' );
		}
	}

	public static function get_loaded_php_extensions() {
		$extensions = get_loaded_extensions();
		sort( $extensions );
		echo esc_html( implode( ', ', $extensions ) );
	}

	public static function get_file_system_method() {
		if ( defined( 'MAINWP_SAVE_FS_METHOD' ) ) {
			return MAINWP_SAVE_FS_METHOD;
		}
		$fs = get_filesystem_method();

		return $fs;
	}

	public static function get_current_version() {
		$currentVersion = get_option( 'mainwp_child_plugin_version' );

		return $currentVersion;
	}

	public static function get_mainwp_version() {
		include_once ABSPATH . '/wp-admin/includes/plugin-install.php';
		$api = plugins_api(
			'plugin_information',
			array(
				'slug'    => 'mainwp-child',
				'fields'  => array( 'sections' => false ),
				'timeout' => 60,
			)
		);
		if ( is_object( $api ) && isset( $api->version ) ) {
			return $api->version;
		}

		return false;
	}

	public static function get_zip_archive_enabled() {
		return class_exists( '\ZipArchive' );
	}

	public static function get_gzip_enabled() {
		return function_exists( 'gzopen' );
	}

	public static function get_bzip_enabled() {
		return function_exists( 'bzopen' );
	}

	public static function get_wordpress_version() {
		global $wp_version;

		return $wp_version;
	}

	public static function get_wordpress_memory_limit() {
		return WP_MEMORY_LIMIT;
	}

	public static function check_if_multisite() {
		$isMultisite = ! is_multisite() ? true : false;

		return $isMultisite;
	}

	public static function get_ssl_support() {
		return extension_loaded( 'openssl' );
	}

	public static function get_ssl_warning() {
		$conf = array( 'private_key_bits' => 2048 );
		$str  = '';
		if ( function_exists( 'openssl_pkey_new' ) ) {
			$res = openssl_pkey_new( $conf );
			openssl_pkey_export( $res, $privkey );

			$str = openssl_error_string();
		}
		return ( stristr( $str, 'NCONF_get_string:no value' ) ? '' : $str );
	}

	public static function get_php_version() {
		return phpversion();
	}

	public static function get_max_execution_time() {
		return ini_get( 'max_execution_time' );
	}

	public static function get_upload_max_filesize() {
		return ini_get( 'upload_max_filesize' );
	}

	public static function get_post_max_size() {
		return ini_get( 'post_max_size' );
	}

	public static function get_my_sql_version() {
		/** @var $wpdb wpdb */
		global $wpdb;

		return $wpdb->get_var( "SHOW VARIABLES LIKE 'version'", 1 );
	}

	public static function get_max_input_time() {
		return ini_get( 'max_input_time' );
	}

	public static function get_php_memory_limit() {
		return ini_get( 'memory_limit' );
	}

	public static function get_os() {
		echo esc_html( PHP_OS );
	}

	public static function get_architecture() {
		echo esc_html( PHP_INT_SIZE * 8 ) . ' bit';
	}

	public static function memory_usage() {
		if ( function_exists( 'memory_get_usage' ) ) {
			$memory_usage = round( memory_get_usage() / 1024 / 1024, 2 ) . ' MB';
		} else {
			$memory_usage = __( 'N/A', 'mainwp-child' );
		}
		echo esc_html( $memory_usage );
	}

	public static function get_output_buffer_size() {
		return ini_get( 'pcre.backtrack_limit' );
	}

	public static function get_php_safe_mode() {
		if ( version_compare( phpversion(), '5.3.0' ) < 0 && ini_get( 'safe_mode' ) ) {
			$safe_mode = __( 'ON', 'mainwp-child' );
		} else {
			$safe_mode = __( 'OFF', 'mainwp-child' );
		}
		echo esc_html( $safe_mode );
	}

	public static function get_sql_mode() {
		global $wpdb;
		$mysqlinfo = $wpdb->get_results( "SHOW VARIABLES LIKE 'sql_mode'" );
		if ( is_array( $mysqlinfo ) ) {
			$sql_mode = $mysqlinfo[0]->Value;
		}
		if ( empty( $sql_mode ) ) {
			$sql_mode = __( 'NOT SET', 'mainwp-child' );
		}
		echo esc_html( $sql_mode );
	}

	public static function get_php_allow_url_fopen() {
		if ( ini_get( 'allow_url_fopen' ) ) {
			$allow_url_fopen = __( 'ON', 'mainwp-child' );
		} else {
			$allow_url_fopen = __( 'OFF', 'mainwp-child' );
		}
		echo esc_html( $allow_url_fopen );
	}

	public static function get_php_exif() {
		if ( is_callable( 'exif_read_data' ) ) {
			$exif = __( 'YES', 'mainwp-child' ) . ' ( V' . substr( phpversion( 'exif' ), 0, 4 ) . ')';
		} else {
			$exif = __( 'NO', 'mainwp-child' );
		}
		echo esc_html( $exif );
	}

	public static function get_php_ip_tc() {
		if ( is_callable( 'iptcparse' ) ) {
			$iptc = __( 'YES', 'mainwp-child' );
		} else {
			$iptc = __( 'NO', 'mainwp-child' );
		}
		echo esc_html( $iptc );
	}

	public static function get_php_xml() {
		if ( is_callable( 'xml_parser_create' ) ) {
			$xml = __( 'YES', 'mainwp-child' );
		} else {
			$xml = __( 'NO', 'mainwp-child' );
		}
		echo esc_html( $xml );
	}

	public static function get_server_getaway_interface() {
		$gate = isset( $_SERVER['GATEWAY_INTERFACE'] ) ? $_SERVER['GATEWAY_INTERFACE'] : '';
		echo esc_html( $gate );
	}

	public static function get_server_ip() {
		echo esc_html( $_SERVER['SERVER_ADDR'] );
	}

	public static function get_server_name() {
		echo esc_html( $_SERVER['SERVER_NAME'] );
	}

	public static function get_server_software() {
		echo esc_html( $_SERVER['SERVER_SOFTWARE'] );
	}

	public static function get_server_protocol() {
		echo esc_html( $_SERVER['SERVER_PROTOCOL'] );
	}

	public static function get_server_request_time() {
		echo esc_html( $_SERVER['REQUEST_TIME'] );
	}

	public static function get_server_http_accept() {
		echo esc_html( $_SERVER['HTTP_ACCEPT'] );
	}

	public static function get_server_accept_charset() {
		if ( ! isset( $_SERVER['HTTP_ACCEPT_CHARSET'] ) || ( '' === $_SERVER['HTTP_ACCEPT_CHARSET'] ) ) {
			esc_html_e( 'N/A', 'mainwp-child' );
		} else {
			echo esc_html( $_SERVER['HTTP_ACCEPT_CHARSET'] );
		}
	}

	public static function get_http_host() {
		echo esc_html( $_SERVER['HTTP_HOST'] );
	}

	public static function get_complete_url() {
		echo isset( $_SERVER['HTTP_REFERER'] ) ? esc_html( $_SERVER['HTTP_REFERER'] ) : '';
	}

	public static function get_user_agent() {
		echo esc_html( $_SERVER['HTTP_USER_AGENT'] );
	}

	public static function get_https() {
		if ( isset( $_SERVER['HTTPS'] ) && '' !== $_SERVER['HTTPS'] ) {
			echo esc_html( __( 'ON', 'mainwp-child' ) . ' - ' . $_SERVER['HTTPS'] );
		} else {
			esc_html_e( 'OFF', 'mainwp-child' );
		}
	}

	public static function server_self_connect() {
		$url         = site_url( 'wp-cron.php' );
		$query_args  = array( 'mainwp_child_run' => 'test' );
		$url         = add_query_arg( $query_args, $url );
		$args        = array(
			'blocking'        => true,
			'sslverify'       => apply_filters( 'https_local_ssl_verify', true ),
			'timeout'         => 15,
		);
		$response    = wp_remote_post( $url, $args );
		$test_result = '';
		if ( is_wp_error( $response ) ) {
			$test_result .= sprintf( __( 'The HTTP response test get an error "%s"', 'mainwp-child' ), $response->get_error_message() );
		}
		$response_code = wp_remote_retrieve_response_code( $response );
		if ( $response_code < 200 && $response_code > 204 ) {
			$test_result .= sprintf( __( 'The HTTP response test get a false http status (%s)', 'mainwp-child' ), wp_remote_retrieve_response_code( $response ) );
		} else {
			$response_body = wp_remote_retrieve_body( $response );
			if ( false === strstr( $response_body, 'MainWP Test' ) ) {
				$test_result .= sprintf( __( 'Not expected HTTP response body: %s', 'mainwp-child' ), esc_attr( wp_strip_all_tags( $response_body ) ) );
			}
		}
		if ( empty( $test_result ) ) {
			_e( 'Response Test O.K.', 'mainwp-child' );
		} else {
			echo $test_result;
		}
	}


	public static function get_remote_address() {
		echo esc_html( $_SERVER['REMOTE_ADDR'] );
	}

	public static function get_remote_host() {
		if ( ! isset( $_SERVER['REMOTE_HOST'] ) || ( '' === $_SERVER['REMOTE_HOST'] ) ) {
			esc_html_e( 'N/A', 'mainwp-child' );
		} else {
			echo esc_html( $_SERVER['REMOTE_HOST'] );
		}
	}

	public static function get_remote_port() {
		echo esc_html( $_SERVER['REMOTE_PORT'] );
	}

	public static function get_script_file_name() {
		echo esc_html( $_SERVER['SCRIPT_FILENAME'] );
	}

	public static function get_server_port() {
		echo esc_html( $_SERVER['SERVER_PORT'] );
	}

	public static function get_current_page_uri() {
		echo esc_html( $_SERVER['REQUEST_URI'] );
	}

	public static function get_wp_root() {
		echo esc_html( ABSPATH );
	}

	public static function time_compare( $a, $b ) {
		if ( $a === $b ) {
			return 0;
		}

		return ( strtotime( $a['time'] ) > strtotime( $b['time'] ) ) ? - 1 : 1;
	}
}
