<?php
/**
 * MainWP Helper
 *
 * @package MainWP/Child
 */

namespace MainWP\Child;

//phpcs:disable WordPress.WP.AlternativeFunctions, Generic.Metrics.CyclomaticComplexity -- Custom functions and current complexity is required to achieve desired results, pull request solutions appreciated.

/**
 * Class MainWP_Helper
 *
 * Helper functions.
 */
class MainWP_Helper {

	/**
	 * Public static variable to hold the single instance of the class.
	 *
	 * @var mixed Default null
	 */
	public static $instance = null;

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
	 * Method write()
	 *
	 * Write response data to be sent to the MainWP Dashboard.
	 *
	 * @param mixed $value Contains information to be written.
	 */
	public static function write( $value ) {
		$output = wp_json_encode( $value );
		die( '<mainwp>' . base64_encode( $output ) . '</mainwp>' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for backwards compatibility.
	}


	/**
	 * Method send()
	 *
	 * Send response data to be sent to the MainWP Dashboard.
	 *
	 * @param mixed $value Contains information to be send.
	 * @param mixed $action action send message.
	 */
	public static function send( $value, $action = '' ) {
		/**
		 * Action: process before send close message.
		 *
		 * @since 4.4.0.3
		 */
		do_action( 'mainwp_child_before_send_close_message', $value, $action );

		MainWP_Utility::close_connection( $value );
	}

	/**
	 * Method write()
	 *
	 * Handle response data errors.
	 *
	 * @param string $error Contains error message.
	 * @param mixed  $code Contains the error code.
	 */
	public function error( $error, $code = null ) {
		$information['error'] = $error;
		if ( null !== $code ) {
			$information['error_code'] = $code;
		}
		self::write( $information );
	}

	/**
	 * Method get_mainwp_dir()
	 *
	 * Get the MainWP directory.
	 *
	 * @param string $what Contains directory name.
	 * @param bool   $die_on_error If true, process will die on error, if false, process will continue.
	 *
	 * @return array Return directory and directory URL.
	 */
	public static function get_mainwp_dir( $what = null, $die_on_error = true ) {

		/**
		 * Global variable containing the instance of the (auto-)configured filesystem object after the filesystem "factory" has been run.
		 *
		 * @global object $wp_filesystem Filesystem object.
		 */
		global $wp_filesystem;

		self::get_wp_filesystem();

		$upload_dir = wp_upload_dir();
		$dir        = $upload_dir['basedir'] . DIRECTORY_SEPARATOR . 'mainwp' . DIRECTORY_SEPARATOR;
		self::check_dir( $dir, $die_on_error );
		if ( ! $wp_filesystem->exists( $dir . 'index.php' ) ) {
			touch( $dir . 'index.php' );
		}
		$url = $upload_dir['baseurl'] . '/mainwp/';

		if ( 'backup' === $what ) {
			$dir .= 'backup' . DIRECTORY_SEPARATOR;
			self::check_dir( $dir, $die_on_error );
			if ( ! $wp_filesystem->exists( $dir . 'index.php' ) ) {
				touch( $dir . 'index.php' );
			}

			$another_name = '.htaccess';
			if ( ! $wp_filesystem->exists( $dir . $another_name ) ) {
				$file = fopen( $dir . $another_name, 'w+' );
				fwrite( $file, 'deny from all' );
				fclose( $file );
			}
			$url .= 'backup/';
		}

		return array( $dir, $url );
	}

	/**
	 * Method check_dir()
	 *
	 * Check if the /mainwp/ direcorty is writable by server.
	 *
	 * @param string $dir Contains directory path.
	 * @param bool   $die_on_error If true, process will die on error, if false, process will continue.
	 * @param int    $chmod Contains information about the directory permissions settings.
	 *
	 * @throws \Exception Error message.
	 */
	public static function check_dir( $dir, $die_on_error, $chmod = 0755 ) {
		self::get_wp_filesystem();

		/**
		 * Global variable containing the instance of the (auto-)configured filesystem object after the filesystem "factory" has been run.
		 *
		 * @global object $wp_filesystem Filesystem object.
		 */
		global $wp_filesystem;

		if ( ! file_exists( $dir ) ) {
			if ( empty( $wp_filesystem ) ) {
				mkdir( $dir, $chmod, true );
			} else {
				if ( ( 'ftpext' === $wp_filesystem->method ) && defined( 'FTP_BASE' ) ) {
					$ftpBase = FTP_BASE;
					$ftpBase = trailingslashit( $ftpBase );
					$tmpdir  = str_replace( ABSPATH, $ftpBase, $dir );
				} else {
					$tmpdir = $dir;
				}
				$wp_filesystem->mkdir( $tmpdir, $chmod );
			}

			if ( ! file_exists( $dir ) ) {
				$error = esc_html__( 'Unable to create directory ', 'mainwp-child' ) . str_replace( ABSPATH, '', $dir ) . '.' . esc_html__( ' Is its parent directory writable by the server?', 'mainwp-child' );
				if ( $die_on_error ) {
					self::instance()->error( $error );
				} else {
					throw new \Exception( $error );
				}
			}
		}
	}

	/**
	 * Method search()
	 *
	 * Nested search field value in an array or object.
	 *
	 * @param array|object $array Array or object to search.
	 * @param string       $key Field value to search for in the $array.
	 *
	 * @return mixed If found return field value, if not, return NULL.
	 */
	public static function search( $array, $key ) {
		if ( is_object( $array ) ) {
			$array = (array) $array;
		}
		if ( is_array( $array ) || is_object( $array ) ) {
			if ( isset( $array[ $key ] ) ) {
				return $array[ $key ];
			}

			foreach ( $array as $subarray ) {
				$result = self::search( $subarray, $key );
				if ( null !== $result ) {
					return $result;
				}
			}
		}
		return null;
	}

	/**
	 * Method get_wp_filesystem()
	 *
	 * Get the WordPress filesystem.
	 *
	 * @return mixed $init WordPress filesystem base.
	 */
	public static function get_wp_filesystem() {

		/**
		 * Global variable containing the instance of the (auto-)configured filesystem object after the filesystem "factory" has been run.
		 *
		 * @global object $wp_filesystem Filesystem object.
		 */
		global $wp_filesystem;

		if ( empty( $wp_filesystem ) ) {
			ob_start();
			if ( file_exists( ABSPATH . '/wp-admin/includes/screen.php' ) ) {
				include_once ABSPATH . '/wp-admin/includes/screen.php';
			}
			if ( file_exists( ABSPATH . '/wp-admin/includes/template.php' ) ) {
				include_once ABSPATH . '/wp-admin/includes/template.php';
			}
			$creds = request_filesystem_credentials( 'test' );
			ob_end_clean();
			if ( empty( $creds ) ) {
				if ( ! defined( 'MAINWP_SAVE_FS_METHOD' ) ) {

					/**
					 * Defines save file system method.
					 *
					 * @const ( string ) Defined save file system method.
					 * @source https://code-reference.mainwp.com/classes/MainWP.Child.MainWP_Helper.html
					 */
					define( 'MAINWP_SAVE_FS_METHOD', get_filesystem_method() );
				}

				/**
				 * Defines file system method.
				 *
				 * @const ( string ) Defined file system method.
				 * @source https://code-reference.mainwp.com/classes/MainWP.Child.MainWP_Helper.html
				 */
				if ( ! defined( 'FS_METHOD' ) ) {
					define( 'FS_METHOD', 'direct' );
				}
			}
			$init = \WP_Filesystem( $creds );
		} else {
			$init = true;
		}
		return $init;
	}

	/**
	 * Method check_wp_filesystem()
	 *
	 * Check the WordPress filesystem.
	 *
	 * @return mixed $wp_filesystem WordPress filesystem check result.
	 */
	public static function check_wp_filesystem() {

		$FTP_ERROR = 'Failed! Please, add FTP details for automatic updates.';

		self::get_wp_filesystem();

		/**
		 * Global variable containing the instance of the (auto-)configured filesystem object after the filesystem "factory" has been run.
		 *
		 * @global object $wp_filesystem Filesystem object.
		 */
		global $wp_filesystem;

		if ( empty( $wp_filesystem ) ) {
			self::instance()->error( $FTP_ERROR );
		} elseif ( is_wp_error( $wp_filesystem->errors ) ) {
			$errorCodes = $wp_filesystem->errors->get_error_codes();
			if ( ! empty( $errorCodes ) ) {
				self::instance()->error( esc_html__( 'WordPress Filesystem error: ', 'mainwp-child' ) . $wp_filesystem->errors->get_error_message() );
			}
		}
		return $wp_filesystem;
	}

	/**
	 * Method reject_unsafe_urls()
	 *
	 * Reject unsafe URLs in HTTP Basic Authentication handler.
	 *
	 * @param array  $r Array containing the request data.
	 * @param string $url URL to check.
	 *
	 * @return array $r Updated array containing the request data.
	 */
	public static function reject_unsafe_urls( $r, $url ) {
		$r['reject_unsafe_urls'] = false;
		$wpadmin_user            = isset( $_POST['wpadmin_user'] ) && ! empty( $_POST['wpadmin_user'] ) ? wp_unslash( $_POST['wpadmin_user'] ) : '';
		$wpadmin_passwd          = isset( $_POST['wpadmin_passwd'] ) && ! empty( $_POST['wpadmin_passwd'] ) ? wp_unslash( $_POST['wpadmin_passwd'] ) : '';

		if ( ! empty( $wpadmin_user ) && ! empty( $wpadmin_passwd ) ) {
			$auth                          = base64_encode( $wpadmin_user . ':' . $wpadmin_passwd ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for backwards compatibility.
			$r['headers']['Authorization'] = "Basic $auth";
		}

		if ( isset( $_POST['sslVerify'] ) && '0' === $_POST['sslVerify'] ) {
			$r['sslverify'] = false;
		}

		return $r;
	}

	/**
	 * Method starts_with()
	 *
	 * Check if the String 1 starts with the String 2.
	 *
	 * @param string $haystack Contains the String 1 for the comparison.
	 * @param string $needle Contains the String 2 for the comparison.
	 *
	 * @return bool true|false Return true if the comparison is positive, false if not.
	 */
	public static function starts_with( $haystack, $needle ) {
		return ! strncmp( $haystack, $needle, strlen( $needle ) );
	}

	/**
	 * Method ends_with()
	 *
	 * Check if the String 1 ends with the String 2.
	 *
	 * @param string $haystack Contains the String 1 for the comparison.
	 * @param string $needle Contains the String 2 for the comparison.
	 *
	 * @return bool true|false Return true if the comparison is positive, false if not.
	 */
	public static function ends_with( $haystack, $needle ) {
		$length = strlen( $needle );
		if ( 0 == $length ) {
			return true;
		}
		return ( substr( $haystack, - $length ) == $needle );
	}

	/**
	 * Method get_nice_url()
	 *
	 * Convert noraml URL to nice URL.
	 *
	 * @param string $url_to_clean Contains the URL that needs to be cleaned.
	 * @param bool   $show_http True to include HTTP|HTTPS in URL.
	 *
	 * @return string $url Cleaned (nice) URL.
	 */
	public static function get_nice_url( $url_to_clean, $show_http = false ) {
		$url = $url_to_clean;

		if ( self::starts_with( $url, 'http://' ) ) {
			if ( ! $show_http ) {
				$url = substr( $url, 7 );
			}
		} elseif ( self::starts_with( $url_to_clean, 'https://' ) ) {
			if ( ! $show_http ) {
				$url = substr( $url, 8 );
			}
		} else {
			if ( $show_http ) {
				$url = 'http://' . $url;
			}
		}

		if ( self::ends_with( $url, '/' ) ) {
			if ( ! $show_http ) {
				$url = substr( $url, 0, strlen( $url ) - 1 );
			}
		} else {
			$url = $url . '/';
		}
		return $url;
	}

	/**
	 * Method end_session()
	 *
	 * End session and flush the output buffer.
	 */
	public static function end_session() {
		session_write_close();
		ob_end_flush();
	}

	/**
	 * Method rand_string()
	 *
	 * Generate random string.
	 *
	 * @param int    $length Contains the string lenghts.
	 * @param string $charset Contain all allowed characters for the generated string.
	 *
	 * @return string $str Generated random string.
	 */
	public static function rand_string( $length, $charset = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789' ) {
		$str   = '';
		$count = strlen( $charset );
		while ( $length -- ) {
			$str .= $charset[ mt_rand( 0, $count - 1 ) ]; // phpcs:ignore -- required to achieve desired results, pull request solutions appreciated.
		}
		return $str;
	}

	/**
	 * Method return_bytes()
	 *
	 * Convert value to bytes.
	 *
	 * @param string $val Contains the value to convert to bytes.
	 *
	 * @return string $val Value converted to bytes.
	 */
	public static function return_bytes( $val ) {
		$val  = trim( $val );
		$last = $val[ strlen( $val ) - 1 ];
		$val  = rtrim( $val, $last );
		$last = strtolower( $last );
		switch ( $last ) {
			case 'g':
				$val *= 1024;
				break;
			case 'm':
				$val *= 1024;
				break;
			case 'k':
				$val *= 1024;
				break;
		}
		return $val;
	}

	/**
	 * Method human_filesize()
	 *
	 * Convert filesize to more user-friendly format.
	 *
	 * @param int $bytes Contains the value in bytes to convert.
	 * @param int $decimals Contains the number of decimal places to round.
	 *
	 * @return string Value converted to more user-friendly format.
	 */
	public static function human_filesize( $bytes, $decimals = 2 ) {
		$size   = array( 'B', 'kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB' );
		$factor = floor( ( strlen( $bytes ) - 1 ) / 3 );

		return sprintf( "%.{$decimals}f", $bytes / pow( 1024, $factor ) ) . $size[ $factor ];
	}

	/**
	 * Method is_dir_empty()
	 *
	 * Check if a directory is empty.
	 *
	 * @param string $dir Contains the directory location path.
	 *
	 * @return bool true|false Return true if the directory is empty, false if not.
	 */
	public static function is_dir_empty( $dir ) {
		if ( ! is_readable( $dir ) ) {
			return null;
		}
		return ( 2 === count( scandir( $dir ) ) );
	}

	/**
	 * Method delete_dir()
	 *
	 * Delete wanted directory.
	 *
	 * @param string $dir Contains the directory location path.
	 */
	public static function delete_dir( $dir ) {
		$nodes = glob( $dir . '*' );

		if ( is_array( $nodes ) ) {
			foreach ( $nodes as $node ) {
				if ( is_dir( $node ) ) {
					self::delete_dir( $node . DIRECTORY_SEPARATOR );
				} else {
					unlink( $node );
				}
			}
		}
		rmdir( $dir );
	}

	/**
	 * Method funct_exists()
	 *
	 * Check if a function exists.
	 *
	 * @param string $func Contains the function name.
	 *
	 * @return bool true|false Return true if the function exists, false if not.
	 */
	public static function funct_exists( $func ) {
		if ( ! function_exists( $func ) ) {
			return false;
		}

		if ( extension_loaded( 'suhosin' ) ) {
			$suhosin = ini_get( 'suhosin.executor.func.blacklist' );
			if ( ! empty( $suhosin ) ) {
				$suhosin = explode( ',', $suhosin );
				$suhosin = array_map( 'trim', $suhosin );
				$suhosin = array_map( 'strtolower', $suhosin );

				return ( function_exists( $func ) && ! array_search( $func, $suhosin ) );
			}
		}
		return true;
	}

	/**
	 * Method get_timestamp()
	 *
	 * Get the timestamp and include GMT offset.
	 *
	 * @param string $timestamp Contains the timestamp value.
	 *
	 * @return string $timestamp The timestamp including the GMT offset.
	 */
	public static function get_timestamp( $timestamp = false ) {
		if ( false === $timestamp ) {
			$timestamp = time();
		}
		$gmtOffset = get_option( 'gmt_offset' );

		return ( $gmtOffset ? ( $gmtOffset * HOUR_IN_SECONDS ) + $timestamp : $timestamp );
	}

	/**
	 * Method format_date()
	 *
	 * Format date as per the WordPress general settings.
	 *
	 * @param string $timestamp Contains the timestamp value.
	 *
	 * @return string Formatted date.
	 */
	public static function format_date( $timestamp ) {
		return date_i18n( get_option( 'date_format' ), $timestamp );
	}

	/**
	 * Method format_time()
	 *
	 * Format time as per the WordPress general settings.
	 *
	 * @param string $timestamp Contains the timestamp value.
	 *
	 * @return string Formatted time.
	 */
	public static function format_time( $timestamp ) {
		return date_i18n( get_option( 'time_format' ), $timestamp );
	}

	/**
	 * Method format_timestamp()
	 *
	 * Format timestamp as per the WordPress general settings.
	 *
	 * @param string $timestamp Contains the timestamp value.
	 *
	 * @return string Formatted date and time.
	 */
	public static function format_timestamp( $timestamp ) {
		return date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	/**
	 * Method update_option()
	 *
	 * Update option.
	 *
	 * @param string $option_name Contains the option name.
	 * @param string $option_value Contains the option value.
	 * @param string $autoload Autoload? Yes or no.
	 *
	 * @return mixed $success Option updated.
	 */
	public static function update_option( $option_name, $option_value, $autoload = 'no' ) {
		$success = add_option( $option_name, $option_value, '', $autoload );
		if ( ! $success ) {
			$success = update_option( $option_name, $option_value );
		}
		return $success;
	}

	/**
	 * Method get_site_unique_id()
	 *
	 * Get site unique id.
	 *
	 * @return string $uniqueId unique id.
	 */
	public static function get_site_unique_id() {
		if ( defined( 'MAINWP_CHILD_UNIQUEID' ) ) {
			$uniqueId = MAINWP_CHILD_UNIQUEID;
		} else {
			$uniqueId = get_option( 'mainwp_child_uniqueId', '' );
		}
		return apply_filters( 'mainwp_child_unique_id', $uniqueId );
	}

	/**
	 * Method in_excludes()
	 *
	 * Check if the value is in the excludes list.
	 *
	 * @param array  $excludes Array containing the list of excludes.
	 * @param string $value Value to check.
	 *
	 * @return bool true|false If in excluded list, return true, if not, return false.
	 */
	public static function in_excludes( $excludes, $value ) {
		if ( empty( $value ) ) {
			return false;
		}
		if ( null != $excludes ) {
			foreach ( $excludes as $exclude ) {
				if ( self::ends_with( $exclude, '*' ) ) {
					if ( self::starts_with( $value, substr( $exclude, 0, strlen( $exclude ) - 1 ) ) ) {
						return true;
					}
				} elseif ( $value == $exclude ) {
					return true;
				} elseif ( self::starts_with( $value, $exclude . '/' ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Method sanitize_filename()
	 *
	 * Sanitize file name.
	 *
	 * @param string $filename Contains the file name to be sanitized.
	 *
	 * @return string $filename Sanitized filename.
	 */
	public static function sanitize_filename( $filename ) {
		if ( ! function_exists( 'mb_ereg_replace' ) ) {
			return sanitize_file_name( $filename );
		}
		// Remove anything which isn't a word, whitespace, number or any of the following caracters -_~,;:[]().
		// If you don't need to handle multi-byte characters you can use preg_replace rather than mb_ereg_replace.
		// Thanks @�?ukasz Rysiak!
		$filename = mb_ereg_replace( '([^\w\s\d\-_~,;:\[\]\(\).])', '', $filename );
		// Remove any runs of periods (thanks falstro!).
		$filename = mb_ereg_replace( '([\.]{2,})', '', $filename );
		return $filename;
	}

	/**
	 * Method ctype_digit()
	 *
	 * Check for numberic character(s).
	 *
	 * @param string $str Contains the string to check.
	 *
	 * @return bool true|false If numberic characters found, return true, if not, return false.
	 */
	public static function ctype_digit( $str ) {
		return ( is_string( $str ) || is_int( $str ) || is_float( $str ) ) && preg_match( '/^\d+\z/', $str );
	}

	/**
	 * Method is_admin()
	 *
	 * Check if the current user is administrator.
	 *
	 * @return bool true|false If the current user is administrator (Level 10), return true, if not, return false.
	 */
	public static function is_admin() {

		/**
		 * Current user global.
		 *
		 * @global string
		 */
		global $current_user;

		if ( 0 == $current_user->ID ) {
			return false;
		}
		if ( 10 == $current_user->wp_user_level || ( isset( $current_user->user_level ) && 10 == $current_user->user_level ) || current_user_can( 'level_10' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Method is_ssl_enabled()
	 *
	 * Check if the OpenSSL PHP extension is enabled.
	 *
	 * @return bool true|false If the OpenSSL PHP extension is enabled, return true, if not, return false.
	 */
	public static function is_ssl_enabled() {
		if ( defined( 'MAINWP_NOSSL' ) ) {
			return ! MAINWP_NOSSL;
		}
		return function_exists( 'openssl_verify' );
	}

	/**
	 * Method is_updates_screen()
	 *
	 * Check if the current screen is the Updates screen.
	 *
	 * @return bool true|false If the current screen is updates, return true, if not, return false.
	 */
	public static function is_updates_screen() {
		if ( ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return false;
		}
		if ( function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( $screen ) {
				if ( 'update-core' == $screen->base && 'index.php' == $screen->parent_file ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Method is_wp_engine()
	 *
	 * Check if the child site is hosted on the WP Engine server.
	 *
	 * @return bool true|false If the child site is hosted on the WP Engine, return true, if not, return false.
	 */
	public static function is_wp_engine() {
		return function_exists( 'is_wpe' ) && is_wpe();
	}

	/**
	 * Method is_wp_engine_php8()
	 *
	 * Check if the child site is hosted on the WP Engine server and PHP8.
	 *
	 * @return bool true|false If the child site is hosted on the WP Engine and PHP8, return true, if not, return false.
	 */
	public static function is_wp_engine_php8() {
		if ( self::is_wp_engine() && version_compare( phpversion(), '8.0', '>=' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Method get_wp_host()
	 *
	 * Get host if hosted on the FLYWHEEL or Pressable host.
	 *
	 * @return string flywheel|pressable If the child site is hosted on FLYWHEEL or Pressable host.
	 */
	public static function get_wp_host() {
		return self::is_flywheel_host() ? 'flywheel' : ( self::is_pressable_host() ? 'pressable' : '' );
	}

	/**
	 * Method is_flywheel_host()
	 *
	 * Check if the child site is hosted on the FLYWHEEL server.
	 *
	 * @return bool true|false If the child site is hosted on the FLYWHEEL, return true, if not, return false.
	 */
	public static function is_flywheel_host() {
		return defined( 'FLYWHEEL_PLUGIN_DIR' ) && ! empty( FLYWHEEL_PLUGIN_DIR );
	}

	/**
	 * Method is_pressable_host()
	 *
	 * Check if the child site is hosted on the Pressable host.
	 *
	 * @return bool true|false If the child site is hosted on the Pressable host, return true, if not, return false.
	 */
	public static function is_pressable_host() {
		$press_site_id = get_option( 'pressable_site_id', false );
		return ! empty( $press_site_id );
	}


	/**
	 * Method maybe_set_doing_cron()
	 *
	 * May be define doing cron.
	 */
	public static function maybe_set_doing_cron() {
		if ( ! self::is_wp_engine_php8() ) {
			// Prevent disable/re-enable.
			if ( ! defined( 'DOING_CRON' ) ) {
				/**
				 * Checks whether cron is in progress.
				 *
				 * @const ( bool ) Default: true
				 * @source https://code-reference.mainwp.com/classes/MainWP.Child.MainWP_Child_Updates.html
				 */
				define( 'DOING_CRON', true );
			}
		}
	}


	/**
	 * Method is_dashboard_request()
	 *
	 * If it is dashboard request.
	 */
	public static function is_dashboard_request() {
		return isset( $_POST['mainwpsignature'] ) && isset( $_POST['function'] ) ? true : false;
	}


	/**
	 * Method check_files_exists()
	 *
	 * Check if a certain files exist.
	 *
	 * @param array $files Array containing list of files to check.
	 * @param bool  $return If true, return feedback.
	 *
	 * @throws \Exception Error message.
	 *
	 * @return mixed If exists, return true, if not, return list of missing files.
	 */
	public static function check_files_exists( $files = array(), $return = false ) {
		$missing = array();
		if ( is_array( $files ) ) {
			foreach ( $files as $name ) {
				if ( ! file_exists( $name ) ) {
					$missing[] = $name;
				}
			}
		} else {
			if ( ! file_exists( $files ) ) {
					$missing[] = $files;
			}
		}
		if ( ! empty( $missing ) ) {
			$message = 'Missing file(s): ' . implode( ',', $missing );
			if ( $return ) {
				return $message;
			} else {
				throw new \Exception( $message );
			}
		}
		return true;
	}

	/**
	 * Method check_classes_exists()
	 *
	 * Check if a certain classes exist.
	 *
	 * @param array $classes Array containing list of classes to check.
	 * @param bool  $return If true, return feedback.
	 *
	 * @throws \Exception Error message.
	 *
	 * @return mixed If exists, return true, if not, return list of missing classes.
	 */
	public function check_classes_exists( $classes = array(), $return = false ) {
		$missing = array();
		if ( is_array( $classes ) ) {
			foreach ( $classes as $name ) {
				if ( ! class_exists( $name ) ) {
					$missing[] = $name;
				}
			}
		} else {
			if ( ! class_exists( $classes ) ) {
				$missing[] = $classes;
			}
		}
		if ( ! empty( $missing ) ) {
			$message = 'Missing classes: ' . implode( ',', $missing );
			if ( $return ) {
				return $message;
			} else {
				throw new \Exception( $message );
			}
		}
		return true;
	}

	/**
	 * Method check_methods()
	 *
	 * Check if a certain methods exist.
	 *
	 * @param object $object Object to check.
	 * @param array  $methods Array containing list of methods to check.
	 * @param bool   $return If true, return feedback.
	 *
	 * @throws \Exception Error message.
	 *
	 * @return mixed If exists, return true, if not, return list of missing methods.
	 */
	public function check_methods( $object, $methods = array(), $return = false ) {
		$missing = array();
		if ( is_array( $methods ) ) {
				$missing = array();
			foreach ( $methods as $name ) {
				if ( ! method_exists( $object, $name ) ) {
					$missing[] = $name;
				}
			}
		} elseif ( ! empty( $methods ) ) {
			if ( ! method_exists( $object, $methods ) ) {
				$missing[] = $methods;
			}
		}
		if ( ! empty( $missing ) ) {
			$message = 'Missing method: ' . implode( ',', $missing );
			if ( $return ) {
				return $message;
			} else {
				throw new \Exception( $message );
			}
		}
		return true;
	}


	/**
	 * Method check_properties()
	 *
	 * Check if a certain properties exist.
	 *
	 * @param object $object Object to check.
	 * @param array  $properties Array containing list of properties to check.
	 * @param bool   $return If true, return feedback.
	 *
	 * @throws \Exception Error message.
	 *
	 * @return mixed If exists, return true, if not, return list of missing properties.
	 */
	public function check_properties( $object, $properties = array(), $return = false ) {
		$missing = array();
		if ( is_array( $properties ) ) {
			foreach ( $properties as $name ) {
				if ( ! property_exists( $object, $name ) ) {
					$missing[] = $name;
				}
			}
		} elseif ( ! empty( $properties ) ) {
			if ( ! property_exists( $object, $properties ) ) {
				$missing[] = $properties;
			}
		}
		if ( ! empty( $missing ) ) {
			$message = 'Missing properties: ' . implode( ',', $missing );
			if ( $return ) {
				return $message;
			} else {
				throw new \Exception( $message );
			}
		}
		return true;
	}

	/**
	 * Method check_functions()
	 *
	 * Check if a certain functions exist.
	 *
	 * @param array $funcs Array containing list of functions to check.
	 * @param bool  $return If true, return feedback.
	 *
	 * @throws \Exception Error message.
	 *
	 * @return mixed If exists, return true, if not, return list of missing functions.
	 */
	public function check_functions( $funcs = array(), $return = false ) {
		$missing = array();
		if ( is_array( $funcs ) ) {
			foreach ( $funcs as $name ) {
				if ( ! function_exists( $name ) ) {
					$missing[] = $name;
				}
			}
		} elseif ( ! empty( $funcs ) ) {
			if ( ! function_exists( $funcs ) ) {
				$missing[] = $funcs;
			}
		}
		if ( ! empty( $missing ) ) {
			$message = 'Missing functions: ' . implode( ',', $missing );
			if ( $return ) {
				return $message;
			} else {
				throw new \Exception( $message );
			}
		}
		return true;
	}

	/**
	 * Method log_debug()
	 *
	 * Log error message to error log.
	 *
	 * @param string $msg Contains the error message.
	 */
	public static function log_debug( $msg ) {
		if ( defined( 'MAINWP_CHILD_DEBUG' ) && MAINWP_CHILD_DEBUG ) {
			error_log( $msg ); // phpcs:ignore -- used in debug mode only to achieve desired results, pull request solutions appreciated.
		}
	}

	/**
	 * Method set_limit()
	 *
	 * Set PHP Memory Limit and PHP Max Execution time values.
	 *
	 * @param int $timeout Contains the timeout value in seconds.
	 * @param int $mem Contains the memory limit value in MB.
	 */
	public static function set_limit( $timeout, $mem = false ) {
		// phpcs:disable -- required in order to achieve desired results, pull request solutions appreciated.
		if ( ! empty( $mem ) ) {
			ini_set( 'memory_limit', $mem );
		}
		set_time_limit( $timeout );
		ini_set( 'max_execution_time', $timeout );
		// phpcs:enable
	}
}
