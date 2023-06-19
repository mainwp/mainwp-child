<?php
/**
 * MainWP Child Server Information Base
 *
 * This is the base set of methods to collect a Child Site's Server Information.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

/**
 * Class MainWP_Child_Server_Information_Base
 *
 * Base set of methods to collect a Child Site's server information.
 */
class MainWP_Child_Server_Information_Base {

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
	 * Initiate check on important System Variables and compare them to required defaults.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Server_Information_Base::check()
	 * @uses \MainWP\Child\MainWP_Child_Server_Information_Base::check_mainwp_directory()
	 *
	 * @return int $i Number of detected issues.
	 */
	protected static function get_warnings() {
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

	/**
	 * Check if MainWP Directory is writeable.
	 *
	 * @param string $message Return message - Directory not found, Directory not writable, writeable.
	 * @param string $path    MainWP directory path.
	 *
	 * @return bool TRUE if exists & writeable, FALSE if not.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::get_mainwp_dir()
	 * @uses \MainWP\Child\MainWP_Helper::get_wp_filesystem()
	 * @uses Exception::getMessage()
	 */
	protected static function check_mainwp_directory( &$message = '', &$path = '' ) {
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

		/**
		 * WordPress files system object.
		 *
		 * @global object
		 */
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

	/**
	 * Check Child Site system variables for any issues.
	 *
	 * @param string $pCompare      Comparison operator.
	 * @param string $pVersion      Version to compare to.
	 * @param string $pGetter       The method to grab the data with.
	 * @param string $pExtraCompare Extra comparison operator.
	 * @param string $pExtraVersion Extra version to compare to.
	 * @param bool   $sizeCompare   Size to compare to.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Server_Information_Base::get_class_name()
	 * @uses \MainWP\Child\MainWP_Child_Server_Information_Base::filesize_compare()
	 *
	 * @return bool|int  When using the optional operator argument, the function will return TRUE if the
	 * relationship is the one specified by the operator, FALSE otherwise. Returns -1 if the first version
	 * is lower than the second, 0 if they are equal, and 1 if the second is lower.
	 */
	protected static function check( $pCompare, $pVersion, $pGetter, $pExtraCompare = null, $pExtraVersion = null, $sizeCompare = false ) {
		$currentVersion = call_user_func( array( self::get_class_name(), $pGetter ) );

		if ( $sizeCompare ) {
			return self::filesize_compare( $currentVersion, $pVersion, $pCompare );
		} else {
			return ( version_compare( $currentVersion, $pVersion, $pCompare ) || ( ( null !== $pExtraCompare ) && version_compare( $currentVersion, $pExtraVersion, $pExtraCompare ) ) );
		}
	}

	/**
	 * Compare filesizes.
	 *
	 * @param string $value1 First value to compare.
	 * @param string $value2 Second value to compare.
	 * @param string $operator Comparison operator.
	 *
	 * @return bool|int  When using the optional operator argument, the function will return TRUE if the
	 * relationship is the one specified by the operator, FALSE otherwise. Returns -1 if the first version
	 * is lower than the second, 0 if they are equal, and 1 if the second is lower.
	 */
	protected static function filesize_compare( $value1, $value2, $operator = null ) {
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

	/**
	 * Check if PHP class curl_version() is enabled.
	 *
	 * @return bool If 'curl_version' function exists, return true, if not, return false.
	 */
	protected static function get_curl_support() {
		return function_exists( 'curl_version' );
	}

	/**
	 * Get current cURL Timeout.
	 *
	 * @return int Current cURL timeout value.
	 */
	protected static function get_curl_timeout() {
		return ini_get( 'default_socket_timeout' );
	}

	/**
	 * Get current cURL Version.
	 *
	 * @return string Current cURL Version.
	 */
	protected static function get_curl_version() {
		$curlversion = curl_version();

		return $curlversion['version'];
	}

	/**
	 * Compare current cURL & SSL versions to required values.
	 *
	 * @param string $version   Required values to compare to.
	 * @param string $operator Comparison operator.
	 *
	 * @return bool|int  When using the optional operator argument, the function will return TRUE if the
	 * relationship is the one specified by the operator, FALSE otherwise. Returns -1 if the first version
	 * is lower than the second, 0 if they are equal, and 1 if the second is lower.
	 */
	public static function curlssl_compare( $version, $operator ) {
		if ( function_exists( 'curl_version' ) ) {
			$ver = self::get_curl_ssl_version();
			return version_compare( $ver, $version, $operator );
		}
		return false;
	}

	/**
	 * Get curl ssl version.
	 *
	 * @return string SSL version.
	 */
	protected static function get_curl_ssl_version() {
		$curlversion = curl_version();

		return $curlversion['ssl_version'];
	}

	/**
	 * Check for disabled PHP functions.
	 */
	protected static function mainwp_required_functions() {
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

	/**
	 * Get loaded PHP extensions.
	 */
	protected static function get_loaded_php_extensions() {
		$extensions = get_loaded_extensions();
		sort( $extensions );
		echo esc_html( implode( ', ', $extensions ) );
	}

	/**
	 * Get file system method.
	 *
	 * @return string $fs The returned file system method.
	 */
	public static function get_file_system_method() {
		if ( defined( 'MAINWP_SAVE_FS_METHOD' ) ) {
			return MAINWP_SAVE_FS_METHOD;
		}
		$fs = get_filesystem_method();

		return $fs;
	}

	/**
	 * Get the current MainWP Child plugin version.
	 *
	 * @return string $currentVersion The MainWP Child plugin current version.
	 */
	protected static function get_current_version() {
		$currentVersion = get_option( 'mainwp_child_plugin_version' );

		return $currentVersion;
	}

	/**
	 * Get the current MainWP Child plugin version.
	 *
	 * @return string|bool Most recent MainWP Child version or FALSE.
	 */
	protected static function get_mainwp_version() {
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

	/**
	 * Check if PHP class \ZipArchive is enabled.
	 *
	 * @return bool If '\ZipArchive' class exists, return true, if not, return false.
	 */
	protected static function get_zip_archive_enabled() {
		return class_exists( '\ZipArchive' );
	}

	/**
	 * Check if PHP function gzopen is enabled.
	 *
	 * @return bool If 'gzopen' function exists, return true, if not, return false.
	 */
	protected static function get_gzip_enabled() {
		return function_exists( 'gzopen' );
	}

	/**
	 * Check if PHP function bzopen is enabled.
	 *
	 * @return bool If 'bzopen' function exists, return true, if not, return false.
	 */
	protected static function get_bzip_enabled() {
		return function_exists( 'bzopen' );
	}

	/**
	 * Get current WordPress version.
	 *
	 * @return string $wp_version Current WordPress version.
	 */
	protected static function get_wordpress_version() {

		/**
		 * The installed version of WordPress.
		 *
		 * @global string $wp_version The installed version of WordPress.
		 */
		global $wp_version;

		return $wp_version;
	}

	/**
	 * Get current WordPress memory limit.
	 *
	 * @return string Current WordPress memory limit.
	 */
	protected static function get_wordpress_memory_limit() {
		return WP_MEMORY_LIMIT;
	}

	/**
	 * Check if in multisite WordPress environment.
	 *
	 * @return bool If multisite detected, return false, if not, return true.
	 */
	protected static function check_if_multisite() {
		$isMultisite = ! is_multisite() ? true : false;

		return $isMultisite;
	}

	/**
	 * Check if PHP extension OpenSSL is enabled.
	 *
	 * @return bool If 'openssl' extension is loaded, return true, if not, return false.
	 */
	protected static function get_ssl_support() {
		return extension_loaded( 'openssl' );
	}

	/**
	 * Get any SSL warnings.
	 *
	 * @return false|string Return error message if there are warnings, FALSE otherwise.
	 */
	protected static function get_ssl_warning() {
		$conf = array( 'private_key_bits' => 2048 );
		$str  = '';
		if ( function_exists( 'openssl_pkey_new' ) ) {
			$res = openssl_pkey_new( $conf );
			openssl_pkey_export( $res, $privkey );

			$str = openssl_error_string();
		}
		return ( stristr( $str, 'NCONF_get_string:no value' ) ? '' : $str );
	}

	/**
	 * Get current PHP version.
	 *
	 * @return string Current PHP version.
	 */
	protected static function get_php_version() {
		return phpversion();
	}

	/**
	 * Get max execution time.
	 *
	 * @return string Return the PHP max execution time.
	 */
	protected static function get_max_execution_time() {
		return ini_get( 'max_execution_time' );
	}

	/**
	 * Get the max uplaod filesize.
	 *
	 * @return string Return the maximum upload filesize.
	 */
	protected static function get_upload_max_filesize() {
		return ini_get( 'upload_max_filesize' );
	}

	/**
	 * Get the max post size.
	 *
	 * @return string Return the post maximum filesize.
	 */
	protected static function get_post_max_size() {
		return ini_get( 'post_max_size' );
	}

	/**
	 * Get current MySQL version.
	 *
	 * @return string Return the current MySQL version.
	 */
	public static function get_my_sql_version() {

		/**
		 * Object, providing access to the WordPress database.
		 *
		 * @global $wpdb WordPress Database instance.
		 */
		global $wpdb;

		return $wpdb->get_var( "SHOW VARIABLES LIKE 'version'", 1 );
	}

	/**
	 * Get max input time.
	 *
	 * @return string Return current maximum input time.
	 */
	protected static function get_max_input_time() {
		return ini_get( 'max_input_time' );
	}

	/**
	 * Get current PHP memory limit.
	 *
	 * @return string Return current PHP memory limit.
	 */
	public static function get_php_memory_limit() {
		return ini_get( 'memory_limit' );
	}

	/**
	 * Get operating system.
	 */
	protected static function get_os() {
		echo esc_html( PHP_OS );
	}

	/**
	 * Get System architecture.
	 */
	protected static function get_architecture() {
		echo esc_html( PHP_INT_SIZE * 8 ) . ' bit';
	}

	/**
	 * Get db size.
	 *
	 * @return string Return current db size.
	 */
	public static function get_db_size() {
		global $wpdb;
		$sql = $wpdb->prepare(
			'SELECT
		ROUND(SUM(DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2)
		FROM INFORMATION_SCHEMA.TABLES
		WHERE
		TABLE_SCHEMA = %s',
			$wpdb->dbname
		);

		$dbsize_mb = $wpdb->get_var( $sql ); // phpcs:ignore unprepared SQL.
		return $dbsize_mb;
	}

	/**
	 * Get the current Memory usage.
	 */
	protected static function memory_usage() {
		if ( function_exists( 'memory_get_usage' ) ) {
			$memory_usage = round( memory_get_usage() / 1024 / 1024, 2 ) . ' MB';
		} else {
			$memory_usage = esc_html__( 'N/A', 'mainwp-child' );
		}
		echo esc_html( $memory_usage );
	}

	/**
	 * Get the current output buffer size.
	 *
	 * @return string Return the current back track limit.
	 */
	protected static function get_output_buffer_size() {
		return ini_get( 'pcre.backtrack_limit' );
	}

	/**
	 * Check if PHP is in Safe Mode.
	 */
	protected static function get_php_safe_mode() {
		if ( version_compare( phpversion(), '5.3.0' ) < 0 && ini_get( 'safe_mode' ) ) {
			$safe_mode = esc_html__( 'ON', 'mainwp-child' );
		} else {
			$safe_mode = esc_html__( 'OFF', 'mainwp-child' );
		}
		echo esc_html( $safe_mode );
	}

	/**
	 * Get current SQL mode.
	 */
	protected static function get_sql_mode() {

		/**
		 * Object, providing access to the WordPress database.
		 *
		 * @global $wpdb WordPress Database instance.
		 */
		global $wpdb;

		$mysqlinfo = $wpdb->get_results( "SHOW VARIABLES LIKE 'sql_mode'" );
		if ( is_array( $mysqlinfo ) ) {
			$sql_mode = $mysqlinfo[0]->Value;
		}
		if ( empty( $sql_mode ) ) {
			$sql_mode = esc_html__( 'NOT SET', 'mainwp-child' );
		}
		echo esc_html( $sql_mode );
	}

	/**
	 * Check if PHP Allow URL fopen is enabled.
	 */
	protected static function get_php_allow_url_fopen() {
		if ( ini_get( 'allow_url_fopen' ) ) {
			$allow_url_fopen = esc_html__( 'ON', 'mainwp-child' );
		} else {
			$allow_url_fopen = esc_html__( 'OFF', 'mainwp-child' );
		}
		echo esc_html( $allow_url_fopen );
	}

	/**
	 * Check if PHP exif is enabled.
	 */
	protected static function get_php_exif() {
		if ( is_callable( 'exif_read_data' ) ) {
			$exif = esc_html__( 'YES', 'mainwp-child' ) . ' ( V' . substr( phpversion( 'exif' ), 0, 4 ) . ')';
		} else {
			$exif = esc_html__( 'NO', 'mainwp-child' );
		}
		echo esc_html( $exif );
	}

	/**
	 * Check if PHP IP TC is enabled.
	 */
	protected static function get_php_ip_tc() {
		if ( is_callable( 'iptcparse' ) ) {
			$iptc = esc_html__( 'YES', 'mainwp-child' );
		} else {
			$iptc = esc_html__( 'NO', 'mainwp-child' );
		}
		echo esc_html( $iptc );
	}

	/**
	 * Check if PHP XML is enabled.
	 */
	protected static function get_php_xml() {
		if ( is_callable( 'xml_parser_create' ) ) {
			$xml = esc_html__( 'YES', 'mainwp-child' );
		} else {
			$xml = esc_html__( 'NO', 'mainwp-child' );
		}
		echo esc_html( $xml );
	}

	/**
	 * Get current server gateway interface.
	 */
	protected static function get_server_getaway_interface() {
		echo isset( $_SERVER['GATEWAY_INTERFACE'] ) ? esc_html( sanitize_text_field( wp_unslash( $_SERVER['GATEWAY_INTERFACE'] ) ) ) : '';
	}

	/**
	 * Get server IP.
	 */
	protected static function get_server_ip() {
		echo isset( $_SERVER['SERVER_ADDR'] ) ? esc_html( sanitize_text_field( wp_unslash( $_SERVER['SERVER_ADDR'] ) ) ) : '';
	}

	/**
	 * Get server name.
	 */
	protected static function get_server_name() {
		echo isset( $_SERVER['SERVER_NAME'] ) ? esc_html( sanitize_text_field( wp_unslash( $_SERVER['SERVER_NAME'] ) ) ) : '';
	}

	/**
	 * Get server software.
	 */
	protected static function get_server_software() {
		echo isset( $_SERVER['SERVER_SOFTWARE'] ) ? esc_html( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) ) : '';
	}

	/**
	 * Get server protocol.
	 */
	protected static function get_server_protocol() {
		echo isset( $_SERVER['SERVER_PROTOCOL'] ) ? esc_html( sanitize_text_field( wp_unslash( $_SERVER['SERVER_PROTOCOL'] ) ) ) : '';
	}

	/**
	 * Get server request time.
	 */
	protected static function get_server_request_time() {
		echo isset( $_SERVER['REQUEST_TIME'] ) ? esc_html( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_TIME'] ) ) ) : '';
	}

	/**
	 * Get server HTTP accept.
	 */
	protected static function get_server_http_accept() {
		echo isset( $_SERVER['HTTP_ACCEPT'] ) ? esc_html( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) ) : '';
	}

	/**
	 * Get server accepted charset.
	 */
	protected static function get_server_accept_charset() {
		echo ! empty( $_SERVER['HTTP_ACCEPT_CHARSET'] ) ? esc_html( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_CHARSET'] ) ) ) : esc_html_e( 'N/A', 'mainwp-child' );
	}

	/**
	 * Get server HTTP host.
	 */
	protected static function get_http_host() {
		echo ! empty( $_SERVER['HTTP_HOST'] ) ? esc_html( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : '';
	}

	/**
	 * Get server complete URL.
	 */
	protected static function get_complete_url() {
		echo ! empty( $_SERVER['HTTP_REFERER'] ) ? esc_html( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
	}

	/**
	 * Get server user agent.
	 */
	protected static function get_user_agent() {
		echo ! empty( $_SERVER['HTTP_USER_AGENT'] ) ? esc_html( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) ) : '';
	}

	/**
	 * Check if HTTPS is on.
	 */
	protected static function get_https() {
		echo ! empty( $_SERVER['HTTPS'] ) ? esc_html( esc_html__( 'ON', 'mainwp-child' ) . ' - ' . sanitize_text_field( wp_unslash( $_SERVER['HTTPS'] ) ) ) : esc_html__( 'OFF', 'mainwp-child' );
	}

	/**
	 * Server self-connection test.
	 */
	protected static function server_self_connect() {
		$url         = site_url( 'wp-cron.php' );
		$query_args  = array( 'mainwp_child_run' => 'test' );
		$url         = add_query_arg( $query_args, $url );
		$args        = array(
			'blocking'  => true,
			'sslverify' => apply_filters( 'https_local_ssl_verify', true ),
			'timeout'   => 15,
		);
		$response    = wp_remote_post( $url, $args );
		$test_result = '';
		if ( is_wp_error( $response ) ) {
			$test_result .= sprintf( esc_html__( 'The HTTP response test get an error "%s"', 'mainwp-child' ), $response->get_error_message() );
		}
		$response_code = wp_remote_retrieve_response_code( $response );
		if ( $response_code < 200 && $response_code > 204 ) {
			$test_result .= sprintf( esc_html__( 'The HTTP response test get a false http status (%s)', 'mainwp-child' ), wp_remote_retrieve_response_code( $response ) );
		} else {
			$response_body = wp_remote_retrieve_body( $response );
			if ( false === strstr( $response_body, 'MainWP Test' ) ) {
				$test_result .= sprintf( esc_html__( 'Not expected HTTP response body: %s', 'mainwp-child' ), esc_attr( wp_strip_all_tags( $response_body ) ) );
			}
		}
		if ( empty( $test_result ) ) {
			esc_html_e( 'Response Test O.K.', 'mainwp-child' );
		} else {
			echo $test_result;
		}
	}


	/**
	 * Get server remote address.
	 */
	protected static function get_remote_address() {
		echo isset( $_SERVER['REMOTE_ADDR'] ) ? esc_html( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) ) : '';
	}

	/**
	 * Get server remote host.
	 */
	protected static function get_remote_host() {
		echo ! empty( $_SERVER['REMOTE_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_HOST'] ) ) : esc_html( 'N/A' );
	}

	/**
	 * Get server remote port.
	 */
	protected static function get_remote_port() {
		echo ! empty( $_SERVER['REMOTE_PORT'] ) ? esc_html( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_PORT'] ) ) ) : '';
	}

	/**
	 * Get server script filename.
	 */
	protected static function get_script_file_name() {
		echo ! empty( $_SERVER['SCRIPT_FILENAME'] ) ? esc_html( sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_FILENAME'] ) ) ) : '';
	}

	/**
	 * Get server port.
	 */
	protected static function get_server_port() {
		echo ! empty( $_SERVER['SERVER_PORT'] ) ? esc_html( sanitize_text_field( wp_unslash( $_SERVER['SERVER_PORT'] ) ) ) : '';
	}

	/**
	 * Get current page URL.
	 */
	protected static function get_current_page_uri() {
		echo ! empty( $_SERVER['REQUEST_URI'] ) ? esc_html( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	}

	/**
	 * Get WordPress root directory.
	 */
	protected static function get_wp_root() {
		echo esc_html( ABSPATH );
	}

	/**
	 * Time comparison.
	 *
	 * @param string $a Time 1.
	 * @param string $b Time 2.
	 *
	 * @return int Return 0 if $a is equal to $b, -1 if $a > $b or 1 if $a < $b.
	 */
	protected static function time_compare( $a, $b ) {
		if ( $a === $b ) {
			return 0;
		}

		return ( strtotime( $a['time'] ) > strtotime( $b['time'] ) ) ? - 1 : 1;
	}
}
