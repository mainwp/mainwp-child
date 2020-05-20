<?php

namespace MainWP\Child;

// phpcs:disable WordPress.WP.AlternativeFunctions -- to custom functions.

class MainWP_Helper {

	public static $instance = null;

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

	public static function write( $val ) {
		if ( isset( $_REQUEST['json_result'] ) && true == $_REQUEST['json_result'] ) :
			$output = wp_json_encode( $val );
		else :
			$output = serialize( $val ); // phpcs:ignore -- to compatible.
		endif;

		die( '<mainwp>' . base64_encode( $output ) . '</mainwp>' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- to compatible with http encoding.
	}

	public static function close_connection( $val = null ) {

		if ( isset( $_REQUEST['json_result'] ) && true == $_REQUEST['json_result'] ) :
			$output = wp_json_encode( $val );
		else :
			$output = serialize( $val ); // phpcs:ignore -- to compatible.
		endif;

		$output = '<mainwp>' . base64_encode( $output ) . '</mainwp>'; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for begin reasons.
		// Close browser connection so that it can resume AJAX polling.
		header( 'Content-Length: ' . strlen( $output ) );
		header( 'Connection: close' );
		header( 'Content-Encoding: none' );
		if ( session_id() ) {
			session_write_close();
		}
		echo $output;
		if ( ob_get_level() ) {
			ob_end_flush();
		}
		flush();
	}

	public static function error( $error, $code = null ) {
		$information['error'] = $error;
		if ( null !== $code ) {
			$information['error_code'] = $code;
		}
		self::write( $information );
	}

	/**
	 * PARSE
	 * Parses some CSS into an array
	 * CSSPARSER
	 * Copyright (C) 2009 Peter Kröner
	 */
	public static function parse_css( $css ) {

		// Remove CSS-Comments.
		$css = preg_replace( '/\/\*.*?\*\//ms', '', $css );
		// Remove HTML-Comments.
		$css = preg_replace( '/([^\'"]+?)(\<!--|--\>)([^\'"]+?)/ms', '$1$3', $css );
		// Extract @media-blocks into $blocks.
		preg_match_all( '/@.+?\}[^\}]*?\}/ms', $css, $blocks );
		// Append the rest to $blocks.
		array_push( $blocks[0], preg_replace( '/@.+?\}[^\}]*?\}/ms', '', $css ) );
		$ordered      = array();
		$count_blocks = count( $blocks[0] );
		for ( $i = 0; $i < $count_blocks; $i++ ) {
			// If @media-block, strip declaration and parenthesis.
			if ( '@media' === substr( $blocks[0][ $i ], 0, 6 ) ) {
				$ordered_key   = preg_replace( '/^(@media[^\{]+)\{.*\}$/ms', '$1', $blocks[0][ $i ] );
				$ordered_value = preg_replace( '/^@media[^\{]+\{(.*)\}$/ms', '$1', $blocks[0][ $i ] );
			} elseif ( '@' === substr( $blocks[0][ $i ], 0, 1 ) ) {
				$ordered_key   = $blocks[0][ $i ];
				$ordered_value = $blocks[0][ $i ];
			} else {
				$ordered_key   = 'main';
				$ordered_value = $blocks[0][ $i ];
			}
			// Split by parenthesis, ignoring those inside content-quotes.
			$ordered[ $ordered_key ] = preg_split( '/([^\'"\{\}]*?[\'"].*?(?<!\\\)[\'"][^\'"\{\}]*?)[\{\}]|([^\'"\{\}]*?)[\{\}]/', trim( $ordered_value, " \r\n\t" ), -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE );
		}
		return self::parse_css_rebuild( $ordered );
	}

	public static function parse_css_rebuild( $ordered ) {
		// Beginning to rebuild new slim CSS-Array.
		foreach ( $ordered as $key => $val ) {
			$new       = array();
			$count_val = count( $val );
			for ( $i = 0; $i < $count_val; $i++ ) {
				// Split selectors and rules and split properties and values.
				$selector = trim( $val[ $i ], " \r\n\t" );

				if ( ! empty( $selector ) ) {
					if ( ! isset( $new[ $selector ] ) ) {
						$new[ $selector ] = array();
					}
					$rules = explode( ';', $val[ ++$i ] );
					foreach ( $rules as $rule ) {
						$rule = trim( $rule, " \r\n\t" );
						if ( ! empty( $rule ) ) {
							$rule     = array_reverse( explode( ':', $rule ) );
							$property = trim( array_pop( $rule ), " \r\n\t" );
							$value    = implode( ':', array_reverse( $rule ) );

							if ( ! isset( $new[ $selector ][ $property ] ) || ! preg_match( '/!important/', $new[ $selector ][ $property ] ) ) {
								$new[ $selector ][ $property ] = $value;
							} elseif ( preg_match( '/!important/', $new[ $selector ][ $property ] ) && preg_match( '/!important/', $value ) ) {
								$new[ $selector ][ $property ] = $value;
							}
						}
					}
				}
			}
			$ordered[ $key ] = $new;
		}
		$parsed = $ordered;

		$output = '';
		foreach ( $parsed as $media => $content ) {
			if ( '@media' === substr( $media, 0, 6 ) ) {
				$output .= $media . " {\n";
				$prefix  = "\t";
			} else {
				$prefix = '';
			}

			foreach ( $content as $selector => $rules ) {
				$output .= $prefix . $selector . " {\n";
				foreach ( $rules as $property => $value ) {
					$output .= $prefix . "\t" . $property . ': ' . $value;
					$output .= ";\n";
				}
				$output .= $prefix . "}\n\n";
			}
			if ( '@media' === substr( $media, 0, 6 ) ) {
				$output .= "}\n\n";
			}
		}
		return $output;
	}

	public static function get_mainwp_dir( $what = null, $dieOnError = true ) {
		/** @var $wp_filesystem WP_Filesystem_Base */
		global $wp_filesystem;
		self::get_wp_filesystem();

		$upload_dir = wp_upload_dir();
		$dir        = $upload_dir['basedir'] . DIRECTORY_SEPARATOR . 'mainwp' . DIRECTORY_SEPARATOR;
		self::check_dir( $dir, $dieOnError );
		if ( ! $wp_filesystem->exists( $dir . 'index.php' ) ) {
			touch( $dir . 'index.php' );
		}
		$url = $upload_dir['baseurl'] . '/mainwp/';

		if ( 'backup' === $what ) {
			$dir .= 'backup' . DIRECTORY_SEPARATOR;
			self::check_dir( $dir, $dieOnError );
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

	public static function check_dir( $dir, $dieOnError, $chmod = 0755 ) {
		self::get_wp_filesystem();
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
				$error = __( 'Unable to create directory ', 'mainwp-child' ) . str_replace( ABSPATH, '', $dir ) . '.' . __( ' Is its parent directory writable by the server?', 'mainwp-child' );
				if ( $dieOnError ) {
					self::error( $error );
				} else {
					throw new \Exception( $error );
				}
			}
		}
	}

	public static function validate_mainwp_dir() {
		$done = false;
		$dir  = self::get_mainwp_dir();
		$dir  = $dir[0];
		if ( self::get_wp_filesystem() ) {
			global $wp_filesystem;
			try {
				self::check_dir( $dir, false );
			} catch ( \Exception $e ) {
				// ok!
			}
			if ( ! empty( $wp_filesystem ) ) {
				if ( $wp_filesystem->is_writable( $dir ) ) {
					$done = true;
				}
			}
		}

		//phpcs:disable -- use system functions
		if ( ! $done ) {
			if ( ! file_exists( $dir ) ) {
				@mkdirs( $dir );
			}
			if ( is_writable( $dir ) ) {
				$done = true;
			}
		}
		//phpcs:enable

		return $done;
	}

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
	 * @return WP_Filesystem_Base
	 */
	public static function get_wp_filesystem() {
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
					define( 'MAINWP_SAVE_FS_METHOD', get_filesystem_method() );
				}
				define( 'FS_METHOD', 'direct' );
			}
			$init = WP_Filesystem( $creds );
		} else {
			$init = true;
		}

		return $init;
	}

	public static function check_wp_filesystem() {

		$FTP_ERROR = 'Failed! Please, add FTP details for automatic updates.';

		self::get_wp_filesystem();

		global $wp_filesystem;

		if ( empty( $wp_filesystem ) ) {
			self::error( $FTP_ERROR );
		} elseif ( is_wp_error( $wp_filesystem->errors ) ) {
			$errorCodes = $wp_filesystem->errors->get_error_codes();
			if ( ! empty( $errorCodes ) ) {
				self::error( __( 'WordPress Filesystem error: ', 'mainwp-child' ) . $wp_filesystem->errors->get_error_message() );
			}
		}
		return $wp_filesystem;
	}

	public static function no_ssl_filter_function( $r, $url ) {
		$r['sslverify'] = false;
		return $r;
	}

	public static function reject_unsafe_urls( $r, $url ) {
		$r['reject_unsafe_urls'] = false;
		if ( isset( $_POST['wpadmin_user'] ) && ! empty( $_POST['wpadmin_user'] ) && isset( $_POST['wpadmin_passwd'] ) && ! empty( $_POST['wpadmin_passwd'] ) ) {
			$auth                          = base64_encode( $_POST['wpadmin_user'] . ':' . $_POST['wpadmin_passwd'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for begin reasons.
			$r['headers']['Authorization'] = "Basic $auth";
		}
		return $r;
	}

	public static function starts_with( $haystack, $needle ) {
		return ! strncmp( $haystack, $needle, strlen( $needle ) );
	}

	public static function ends_with( $haystack, $needle ) {
		$length = strlen( $needle );
		if ( 0 == $length ) {
			return true;
		}

		return ( substr( $haystack, - $length ) == $needle );
	}

	public static function get_nice_url( $pUrl, $showHttp = false ) {
		$url = $pUrl;

		if ( self::starts_with( $url, 'http://' ) ) {
			if ( ! $showHttp ) {
				$url = substr( $url, 7 );
			}
		} elseif ( self::starts_with( $pUrl, 'https://' ) ) {
			if ( ! $showHttp ) {
				$url = substr( $url, 8 );
			}
		} else {
			if ( $showHttp ) {
				$url = 'http://' . $url;
			}
		}

		if ( self::ends_with( $url, '/' ) ) {
			if ( ! $showHttp ) {
				$url = substr( $url, 0, strlen( $url ) - 1 );
			}
		} else {
			$url = $url . '/';
		}

		return $url;
	}

	public static function end_session() {
		session_write_close();
		ob_end_flush();
	}

	public static function rand_string( $length, $charset = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789' ) {
		$str   = '';
		$count = strlen( $charset );
		while ( $length -- ) {
			$str .= $charset[ mt_rand( 0, $count - 1 ) ]; // phpcs:ignore
		}

		return $str;
	}

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

	public static function human_filesize( $bytes, $decimals = 2 ) {
		$size   = array( 'B', 'kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB' );
		$factor = floor( ( strlen( $bytes ) - 1 ) / 3 );

		return sprintf( "%.{$decimals}f", $bytes / pow( 1024, $factor ) ) . $size[ $factor ];
	}

	public static function is_dir_empty( $dir ) {
		if ( ! is_readable( $dir ) ) {
			return null;
		}

		return ( 2 === count( scandir( $dir ) ) );
	}

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

	public static function get_timestamp( $timestamp ) {
		$gmtOffset = get_option( 'gmt_offset' );

		return ( $gmtOffset ? ( $gmtOffset * HOUR_IN_SECONDS ) + $timestamp : $timestamp );
	}

	public static function format_date( $timestamp ) {
		return date_i18n( get_option( 'date_format' ), $timestamp );
	}

	public static function format_time( $timestamp ) {
		return date_i18n( get_option( 'time_format' ), $timestamp );
	}

	public static function format_timestamp( $timestamp ) {
		return date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	public static function update_option( $option_name, $option_value, $autoload = 'no' ) {
		$success = add_option( $option_name, $option_value, '', $autoload );

		if ( ! $success ) {
			$success = update_option( $option_name, $option_value );
		}

		return $success;
	}

	public function update_lasttime_backup( $by, $time ) {
		$backup_by = array( 'backupbuddy', 'backupwordpress', 'backwpup', 'updraftplus', 'wptimecapsule' );
		if ( ! in_array( $by, $backup_by ) ) {
			return false;
		}

		$lasttime = get_option( 'mainwp_lasttime_backup_' . $by );
		if ( $time > $lasttime ) {
			update_option( 'mainwp_lasttime_backup_' . $by, $time );
		}

		return true;
	}

	public function get_lasttime_backup( $by ) {

		if ( 'backupwp' == $by ) {
			$by = 'backupwordpress';
		}

		$activated = true;
		switch ( $by ) {
			case 'backupbuddy':
				if ( ! is_plugin_active( 'backupbuddy/backupbuddy.php' ) && ! is_plugin_active( 'Backupbuddy/backupbuddy.php' ) ) {
					$activated = false;
				}
				break;
			case 'backupwordpress':
				if ( ! is_plugin_active( 'backupwordpress/backupwordpress.php' ) ) {
					$activated = false;
				}
				break;
			case 'backwpup':
				if ( ! is_plugin_active( 'backwpup/backwpup.php' ) && ! is_plugin_active( 'backwpup-pro/backwpup.php' ) ) {
					$activated = false;
				}
				break;
			case 'updraftplus':
				if ( ! is_plugin_active( 'updraftplus/updraftplus.php' ) ) {
					$activated = false;
				}
				break;
			case 'wptimecapsule':
				if ( ! is_plugin_active( 'wp-time-capsule/wp-time-capsule.php' ) ) {
					$activated = false;
				}
				break;
			default:
				$activated = false;
				break;
		}

		if ( ! $activated ) {
			return 0;
		}

		return get_option( 'mainwp_lasttime_backup_' . $by, 0 );
	}

	public function create_nonce_without_session( $action = - 1 ) {
		$user = wp_get_current_user();
		$uid  = (int) $user->ID;
		if ( ! $uid ) {
			$uid = apply_filters( 'nonce_user_logged_out', $uid, $action );
		}

		$i = wp_nonce_tick();

		return substr( wp_hash( $i . '|' . $action . '|' . $uid, 'nonce' ), - 12, 10 );
	}

	public function verify_nonce_without_session( $nonce, $action = - 1 ) {
		$nonce = (string) $nonce;
		$user  = wp_get_current_user();
		$uid   = (int) $user->ID;
		if ( ! $uid ) {
			$uid = apply_filters( 'nonce_user_logged_out', $uid, $action );
		}

		if ( empty( $nonce ) ) {
			return false;
		}

		$i = wp_nonce_tick();

		$expected = substr( wp_hash( $i . '|' . $action . '|' . $uid, 'nonce' ), - 12, 10 );
		if ( hash_equals( $expected, $nonce ) ) {
			return 1;
		}

		$expected = substr( wp_hash( ( $i - 1 ) . '|' . $action . '|' . $uid, 'nonce' ), - 12, 10 );
		if ( hash_equals( $expected, $nonce ) ) {
			return 2;
		}

		return false;
	}

	public static function get_revisions( $max_revisions ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( " SELECT	`post_parent`, COUNT(*) cnt FROM $wpdb->posts WHERE `post_type` = 'revision' GROUP BY `post_parent` HAVING COUNT(*) > %d ", $max_revisions ) );
	}


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

	public static function is_archive( $pFileName, $pPrefix = '', $pSuffix = '' ) {
		return preg_match( '/' . $pPrefix . '(.*).(zip|tar|tar.gz|tar.bz2)' . $pSuffix . '$/', $pFileName );
	}

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

	public static function ctype_digit( $str ) {
		return ( is_string( $str ) || is_int( $str ) || is_float( $str ) ) && preg_match( '/^\d+\z/', $str );
	}

	public static function is_admin() {
		global $current_user;
		if ( 0 == $current_user->ID ) {
			return false;
		}

		if ( 10 == $current_user->wp_user_level || ( isset( $current_user->user_level ) && 10 == $current_user->user_level ) || current_user_can( 'level_10' ) ) {
			return true;
		}

		return false;
	}

	public static function is_ssl_enabled() {
		if ( defined( 'MAINWP_NOSSL' ) ) {
			return ! MAINWP_NOSSL;
		}
		return function_exists( 'openssl_verify' );
	}

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

	public static function is_wp_engine() {
		return function_exists( 'is_wpe' ) && is_wpe();
	}

	public function check_files_exists( $files = array(), $return = false ) {
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

	public static function check_properties( $object, $properties = array(), $return = false ) {
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

	public static function check_functions( $funcs = array(), $return = false ) {
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

	public static function log_debug( $msg ) {
		if ( defined( 'MAINWP_CHILD_DEBUG' ) && MAINWP_CHILD_DEBUG ) {
			error_log( $msg ); // phpcs:ignore -- debug mode only.
		}
	}

	public static function set_limit( $timeout, $mem = false ) {
		// phpcs:disable
		if ( ! empty( $mem ) ) {
			ini_set( 'memory_limit', $mem );
		}
		set_time_limit( $timeout );
		ini_set( 'max_execution_time', $timeout );
		// phpcs:enable
	}
}
