<?php
/**
 * MainWP Child Server Information Base
 *
 * This is the base set of methods to collect a Child Site's Server Information.
 */
namespace MainWP\Child;

/**
 * Class MainWP_Child_Server_Information_Base
 * @package MainWP\Child
 */
class MainWP_Child_Server_Information_Base {

    /**
     * Get Class Name.
     *
     * @return string
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
     * @return int $i Number of checked variables
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
     * @param string $path    MainWP Directory Path.
     *
     * @uses MainWP_Helper::get_mainwp_dir()
     * @uses Exception::getMessage()
     *
     * @return bool TRUE if exists & writeable, FALSE if not.
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
     * @param string $pCompare    Comparison operator.
     * @param string $pVersion    Version to compare to.
     * @param string $pGetter     The method to grab the data with.
     * @param null $pExtraCompare Extra comparison operator.
     * @param null $pExtraVersion Extra version to compare to.
     * @param bool $sizeCompare   Size to compare to.
     *
     * @uses \MainWP\Child\MainWP_Child_Server_Information_Base::get_class_name()
     * @uses \MainWP\Child\MainWP_Child_Server_Information_Base::filesize_compare()
     *
     * @return bool|int  When using the optional operator argument, the function will return TRUE if the
     *  relationship is the one specified by the operator, FALSE otherwise. Returns -1 if the first version
     *  is lower than the second, 0 if they are equal, and 1 if the second is lower.
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
     * @param null $operator Comparison operator.
     * @return bool|int  When using the optional operator argument, the function will return TRUE if the
     *  relationship is the one specified by the operator, FALSE otherwise. Returns -1 if the first version
     *  is lower than the second, 0 if they are equal, and 1 if the second is lower.
     */
    protected static function filesize_compare($value1, $value2, $operator = null ) {
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
     * @return bool Returns TRUE or FALSE.
     */
    protected static function get_curl_support() {
		return function_exists( 'curl_version' );
	}

    /**
     * Get current cURL timeout.
     *
     * @return string Current cURL timeout value.
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
     * @param string $value    Required values to compare to.
     * @param null   $operator Comparison operator.
     *
     * @return bool|int  When using the optional operator argument, the function will return TRUE if the
     *  relationship is the one specified by the operator, FALSE otherwise. Returns -1 if the first version
     *  is lower than the second, 0 if they are equal, and 1 if the second is lower.
     */
    protected static function curlssl_compare($value, $operator = null ) {
		if ( isset( $value['version_number'] ) && defined( 'OPENSSL_VERSION_NUMBER' ) ) {
			return version_compare( OPENSSL_VERSION_NUMBER, $value['version_number'], $operator );
		}

		return false;
	}

    /**
     * Get curl ssl version.
     *
     * @return string ssl version.
     */
    protected static function get_curl_ssl_version() {
		$curlversion = curl_version();

		return $curlversion['ssl_version'];
	}

    /**
     * Check for disabled PHP functions.
     *
     * @return string List of disabled functions or 'No functions disabled'.
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
     * Get loaded PHP Extensions.
     *
     * @return string List of loaded PHP Extensions.
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
    protected static function get_file_system_method() {
		if ( defined( 'MAINWP_SAVE_FS_METHOD' ) ) {
			return MAINWP_SAVE_FS_METHOD;
		}
		$fs = get_filesystem_method();

		return $fs;
	}

    /**
     * Get the current MainWP Child Plugin version.
     *
     * @return string $currentVersion The MainWP Child Plugin current version.
     */
    protected static function get_current_version() {
		$currentVersion = get_option( 'mainwp_child_plugin_version' );

		return $currentVersion;
	}

    /**
     * Get the current MainWP Child Plugin version.
     *
     * @return string|bool Most recent MainWP Child Version or FALSE.
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
     * @return bool Return TRUE or FALSE.
     */
    protected static function get_zip_archive_enabled() {
		return class_exists( '\ZipArchive' );
	}

    /**
     * Check if PHP function gzopen is enabled.
     *
     * @return bool Return TRUE or FALSE.
     */
    protected static function get_gzip_enabled() {
		return function_exists( 'gzopen' );
	}

    /**
     * Check if PHP function bzopen is enabled.
     *
     * @return bool Return TRUE or FALSE.
     */
    protected static function get_bzip_enabled() {
		return function_exists( 'bzopen' );
	}

    /**
     * Get current WordPress Version.
     *
     * @return string $wp_version Current WordPress version.
     */
    protected static function get_wordpress_version() {

        /** @global string $wp_version WordPress Version. */
		global $wp_version;

		return $wp_version;
	}

    /**
     * Get current WordPress Memory Limit.
     *
     * @return string Current WordPress Memory Limit.
     */
    protected static function get_wordpress_memory_limit() {
		return WP_MEMORY_LIMIT;
	}

    /**
     * Check if in Multisite WordPress Environment.
     *
     * @return bool $isMultisite Return TRUE or FALSE.
     */
    protected static function check_if_multisite() {
		$isMultisite = ! is_multisite() ? true : false;

		return $isMultisite;
	}

    /**
     * Check if HP Extension openSSL is enabled.
     *
     * @return bool Return TRUE or FALSE.
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
     * @return string Current PHP Version.
     */
    protected static function get_php_version() {
		return phpversion();
	}

    /**
     * Get Max execution time.
     *
     * @return string Return the set max_execution_time.
     */
    protected static function get_max_execution_time() {
		return ini_get( 'max_execution_time' );
	}

    /**
     * Get the max uplaod filesize.
     *
     * @return string Return the set upload_max_filesize.
     */
    protected static function get_upload_max_filesize() {
		return ini_get( 'upload_max_filesize' );
	}

    /**
     * Get the max post size.
     *
     * @return string Return the set post_max_size.
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

		/** @var $wpdb wpdb */
		global $wpdb;

		return $wpdb->get_var( "SHOW VARIABLES LIKE 'version'", 1 );
	}

    /**
     * Get Max Input Time.
     *
     * @return string Return current set max_input_time.
     */
    protected static function get_max_input_time() {
		return ini_get( 'max_input_time' );
	}

    /**
     * Get current PHP Memory Limit.
     *
     * @return string Return current set PHP memory_limit.
     */
    public static function get_php_memory_limit() {
		return ini_get( 'memory_limit' );
	}

    /**
     * Get Operating System.
     *
     * @return string Return current Operating System.
     */
    protected static function get_os() {
		echo esc_html( PHP_OS );
	}

    /**
     * Get System Architecture.
     *
     * @return string System Architecture.
     */
    protected static function get_architecture() {
		echo esc_html( PHP_INT_SIZE * 8 ) . ' bit';
	}

    /**
     * Get the current Memory Usage.
     *
     * @return string Return current memory usage.
     */
    protected static function memory_usage() {
		if ( function_exists( 'memory_get_usage' ) ) {
			$memory_usage = round( memory_get_usage() / 1024 / 1024, 2 ) . ' MB';
		} else {
			$memory_usage = __( 'N/A', 'mainwp-child' );
		}
		echo esc_html( $memory_usage );
	}

    /**
     * Get the current output buffer size.
     *
     * @return string Return the current set pcre.backtrack_limit.
     */
    protected static function get_output_buffer_size() {
		return ini_get( 'pcre.backtrack_limit' );
	}

    /**
     * Check if PHP is in Safe Mode.
     *
     * @return string ON|OFF.
     */
    protected static function get_php_safe_mode() {
		if ( version_compare( phpversion(), '5.3.0' ) < 0 && ini_get( 'safe_mode' ) ) {
			$safe_mode = __( 'ON', 'mainwp-child' );
		} else {
			$safe_mode = __( 'OFF', 'mainwp-child' );
		}
		echo esc_html( $safe_mode );
	}

    /**
     * Get current SQL Mode.
     *
     * @return String Return current SQL mode.
     */
    protected static function get_sql_mode() {
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

    /**
     * Check if PHP Allow URL fopen is enabled.
     *
     * @return string ON|OFF.
     */
    protected static function get_php_allow_url_fopen() {
		if ( ini_get( 'allow_url_fopen' ) ) {
			$allow_url_fopen = __( 'ON', 'mainwp-child' );
		} else {
			$allow_url_fopen = __( 'OFF', 'mainwp-child' );
		}
		echo esc_html( $allow_url_fopen );
	}

    /**
     * Check if PHP exif is enabled.
     *
     * @return string YES|NO.
     */
    protected static function get_php_exif() {
		if ( is_callable( 'exif_read_data' ) ) {
			$exif = __( 'YES', 'mainwp-child' ) . ' ( V' . substr( phpversion( 'exif' ), 0, 4 ) . ')';
		} else {
			$exif = __( 'NO', 'mainwp-child' );
		}
		echo esc_html( $exif );
	}

    /**
     * Check if PHP IP TC is enabled.
     *
     * @return string YES|NO.
     */
    protected static function get_php_ip_tc() {
		if ( is_callable( 'iptcparse' ) ) {
			$iptc = __( 'YES', 'mainwp-child' );
		} else {
			$iptc = __( 'NO', 'mainwp-child' );
		}
		echo esc_html( $iptc );
	}

    /**
     * Check if PHP XML is enabled.
     *
     * @return string YES|NO.
     */
    protected static function get_php_xml() {
		if ( is_callable( 'xml_parser_create' ) ) {
			$xml = __( 'YES', 'mainwp-child' );
		} else {
			$xml = __( 'NO', 'mainwp-child' );
		}
		echo esc_html( $xml );
	}

    /**
     * Get current Server Gateway Interface.
     *
     * @return string Return current Server Gateway Interface.
     */
    protected static function get_server_getaway_interface() {
		$gate = isset( $_SERVER['GATEWAY_INTERFACE'] ) ? $_SERVER['GATEWAY_INTERFACE'] : '';
		echo esc_html( $gate );
	}

    /**
     * Get Server IP.
     *
     * @return string Return Server IP.
     */
    protected static function get_server_ip() {
		echo esc_html( $_SERVER['SERVER_ADDR'] );
	}

    /**
     * Get Server Name.
     *
     * @return string Server Name.
     */
    protected static function get_server_name() {
		echo esc_html( $_SERVER['SERVER_NAME'] );
	}

    /**
     * Get Server Software.
     *
     * @return string Server Software.
     */
    protected static function get_server_software() {
		echo esc_html( $_SERVER['SERVER_SOFTWARE'] );
	}

    /**
     * Get Server Protocol.
     *
     * @return String Server Protocol.
     */
    protected static function get_server_protocol() {
		echo esc_html( $_SERVER['SERVER_PROTOCOL'] );
	}

    /**
     * Get Server Request Time.
     *
     * @return string Server Request Time.
     */
    protected static function get_server_request_time() {
		echo esc_html( $_SERVER['REQUEST_TIME'] );
	}

    /**
     * Get Server HTTP Accept.
     *
     * @return string Server HTTP Accept.
     */
    protected static function get_server_http_accept() {
		echo esc_html( $_SERVER['HTTP_ACCEPT'] );
	}

    /**
     * Get Server accepted charset.
     *
     * @return string N/A or Server accepted charset.
     */
    protected static function get_server_accept_charset() {
		if ( ! isset( $_SERVER['HTTP_ACCEPT_CHARSET'] ) || ( '' === $_SERVER['HTTP_ACCEPT_CHARSET'] ) ) {
			esc_html_e( 'N/A', 'mainwp-child' );
		} else {
			echo esc_html( $_SERVER['HTTP_ACCEPT_CHARSET'] );
		}
	}

    /**
     * Get Server HTTP Host.
     *
     * @return string HTTP Host.
     */
    protected static function get_http_host() {
		echo esc_html( $_SERVER['HTTP_HOST'] );
	}

    /**
     * Get Server Complete URL.
     *
     * @return string Complete URL.
     */
    protected static function get_complete_url() {
		echo isset( $_SERVER['HTTP_REFERER'] ) ? esc_html( $_SERVER['HTTP_REFERER'] ) : '';
	}

    /**
     * Get Server User Agent.
     *
     * @return string User Agent.
     */
    protected static function get_user_agent() {
		echo esc_html( $_SERVER['HTTP_USER_AGENT'] );
	}

    /**
     * Check if HTTPS is on.
     *
     * @return string ON|OFF.
     */
    protected static function get_https() {
		if ( isset( $_SERVER['HTTPS'] ) && '' !== $_SERVER['HTTPS'] ) {
			echo esc_html( __( 'ON', 'mainwp-child' ) . ' - ' . $_SERVER['HTTPS'] );
		} else {
			esc_html_e( 'OFF', 'mainwp-child' );
		}
	}

    /**
     * Check Server Self Connection.
     *
     * @return string $test_result
     */
    protected static function server_self_connect() {
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


    /**
     * Get Server remote address.
     *
     * @return string Remote Address.
     */
    protected static function get_remote_address() {
		echo esc_html( $_SERVER['REMOTE_ADDR'] );
	}

    /**
     * Get Server Remote Host.
     *
     * @return string Server Remote Host.
     */
    protected static function get_remote_host() {
		if ( ! isset( $_SERVER['REMOTE_HOST'] ) || ( '' === $_SERVER['REMOTE_HOST'] ) ) {
			esc_html_e( 'N/A', 'mainwp-child' );
		} else {
			echo esc_html( $_SERVER['REMOTE_HOST'] );
		}
	}

    /**
     * Get Server Remote Port.
     *
     * @return string Server Remote Port.
     */
    protected static function get_remote_port() {
		echo esc_html( $_SERVER['REMOTE_PORT'] );
	}

    /**
     * Get Server Script Filename.
     *
     * @return string Server Script Filename.
     */
    protected static function get_script_file_name() {
		echo esc_html( $_SERVER['SCRIPT_FILENAME'] );
	}

    /**
     * Get Server Port.
     *
     * @return string Server Port.
     */
    protected static function get_server_port() {
		echo esc_html( $_SERVER['SERVER_PORT'] );
	}

    /**
     * Get current page URL
     *
     * @return string Current page URL.
     */
    protected static function get_current_page_uri() {
		echo esc_html( $_SERVER['REQUEST_URI'] );
	}

    /**
     * Get WordPress Root Directory.
     *
     * @return string WordPress Root Directory.
     */
    protected static function get_wp_root() {
		echo esc_html( ABSPATH );
	}

    /**
     * Time Comparison.
     *
     * @param string $a Time 1
     * @param string $b Time 2
     * @return int Return 0 if $a is equal to $b ELSE or time.
     */
    protected static function time_compare($a, $b ) {
		if ( $a === $b ) {
			return 0;
		}

		return ( strtotime( $a['time'] ) > strtotime( $b['time'] ) ) ? - 1 : 1;
	}
}
