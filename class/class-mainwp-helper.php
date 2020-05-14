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

	public function write( $val ) {
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
		mainwp_child_helper()->write( $information );
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

	public static function clean( $string ) {
		$string = trim( $string );
		$string = htmlentities( $string, ENT_QUOTES );
		$string = str_replace( "\n", '<br>', $string );
		$string = stripslashes( $string );
		return $string;
	}

	public static function end_session() {
		session_write_close();
		ob_end_flush();
	}

	public static function fetch_url( $url, $postdata ) {
		try {
			$tmpUrl = $url;
			if ( '/' !== substr( $tmpUrl, - 1 ) ) {
				$tmpUrl .= '/';
			}

			return self::m_fetch_url( $tmpUrl . 'wp-admin/', $postdata );
		} catch ( \Exception $e ) {
			try {
				return self::m_fetch_url( $url, $postdata );
			} catch ( \Exception $ex ) {
				throw $e;
			}
		}
	}

	public static function m_fetch_url( $url, $postdata ) {
		$agent = 'Mozilla/5.0 (compatible; MainWP-Child/' . MainWP_Child::$version . '; +http://mainwp.com)';

		if ( ! is_array( $postdata ) ) {
			$postdata = array();
		}

		$postdata['json_result'] = true; // forced all response in json format.

		// phpcs:disable WordPress.WP.AlternativeFunctions -- to custom.
		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $postdata );
		curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 10 );
		curl_setopt( $ch, CURLOPT_USERAGENT, $agent );
		$data        = curl_exec( $ch );
		$http_status = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$err         = curl_error( $ch );
		curl_close( $ch );

		if ( ( false === $data ) && ( 0 === $http_status ) ) {
			throw new \Exception( 'Http Error: ' . $err );
		} elseif ( preg_match( '/<mainwp>(.*)<\/mainwp>/', $data, $results ) > 0 ) {
			$result      = $results[1];
			$result_base = base64_decode( $result ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for begin reasons.
			$information = json_decode( $result_base, true ); // it is json_encode result.
			return $information;
		} elseif ( '' === $data ) {
			throw new \Exception( __( 'Something went wrong while contacting the child site. Please check if there is an error on the child site. This error could also be caused by trying to clone or restore a site to large for your server settings.', 'mainwp-child' ) );
		} else {
			throw new \Exception( __( 'Child plugin is disabled or the security key is incorrect. Please resync with your main installation.', 'mainwp-child' ) );
		}
		// phpcs:enable
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

	public static function format_email( $to, $body ) {
		return '<br>
<div>
            <br>
            <div style="background:#ffffff;padding:0 1.618em;font:13px/20px Helvetica,Arial,Sans-serif;padding-bottom:50px!important">
                <div style="width:600px;background:#fff;margin-left:auto;margin-right:auto;margin-top:10px;margin-bottom:25px;padding:0!important;border:10px Solid #fff;border-radius:10px;overflow:hidden">
                    <div style="display: block; width: 100% ; background-image: url(https://mainwp.com/wp-content/uploads/2013/02/debut_light.png) ; background-repeat: repeat; border-bottom: 2px Solid #7fb100 ; overflow: hidden;">
                      <div style="display: block; width: 95% ; margin-left: auto ; margin-right: auto ; padding: .5em 0 ;">
                         <div style="float: left;"><a href="https://mainwp.com"><img src="https://mainwp.com/wp-content/uploads/2013/07/MainWP-Logo-1000-300x62.png" alt="MainWP" height="30"/></a></div>
                         <div style="float: right; margin-top: .6em ;">
                            <span style="display: inline-block; margin-right: .8em;"><a href="https://mainwp.com/mainwp-extensions/" style="font-family: Helvetica, Sans; color: #7fb100; text-transform: uppercase; font-size: 14px;">Extensions</a></span>
                            <span style="display: inline-block; margin-right: .8em;"><a style="font-family: Helvetica, Sans; color: #7fb100; text-transform: uppercase; font-size: 14px;" href="https://mainwp.com/forum">Support</a></span>
                            <span style="display: inline-block; margin-right: .8em;"><a style="font-family: Helvetica, Sans; color: #7fb100; text-transform: uppercase; font-size: 14px;" href="https://docs.mainwp.com">Documentation</a></span>
                            <span style="display: inline-block; margin-right: .5em;" class="mainwp-memebers-area"><a href="https://mainwp.com/member/login/index" style="padding: .6em .5em ; border-radius: 50px ; -moz-border-radius: 50px ; -webkit-border-radius: 50px ; background: #1c1d1b; border: 1px Solid #000; color: #fff !important; font-size: .9em !important; font-weight: normal ; -webkit-box-shadow:  0px 0px 0px 5px rgba(0, 0, 0, .1); box-shadow:  0px 0px 0px 5px rgba(0, 0, 0, .1);">Members Area</a></span>
                         </div><div style="clear: both;"></div>
                      </div>
                    </div>
                    <div>
                        <p>Hello MainWP User!<br></p>
                        ' . $body . '
                        <div></div>
                        <br />
                        <div>MainWP</div>
                        <div><a href="https://www.MainWP.com" target="_blank">www.MainWP.com</a></div>
                        <p></p>
                    </div>

                    <div style="display: block; width: 100% ; background: #1c1d1b;">
                      <div style="display: block; width: 95% ; margin-left: auto ; margin-right: auto ; padding: .5em 0 ;">
                        <div style="padding: .5em 0 ; float: left;"><p style="color: #fff; font-family: Helvetica, Sans; font-size: 12px ;">© 2013 MainWP. All Rights Reserved.</p></div>
                        <div style="float: right;"><a href="https://mainwp.com"><img src="https://mainwp.com/wp-content/uploads/2013/07/MainWP-Icon-300.png" height="45"/></a></div><div style="clear: both;"></div>
                      </div>
                   </div>
                </div>
                <center>
                    <br><br><br><br><br><br>
                    <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color:#ffffff;border-top:1px solid #e5e5e5">
                        <tbody><tr>
                            <td align="center" valign="top" style="padding-top:20px;padding-bottom:20px">
                                <table border="0" cellpadding="0" cellspacing="0">
                                    <tbody><tr>
                                        <td align="center" valign="top" style="color:#606060;font-family:Helvetica,Arial,sans-serif;font-size:11px;line-height:150%;padding-right:20px;padding-bottom:5px;padding-left:20px;text-align:center">
                                            This email is sent from your MainWP Dashboard.
                                            <br>
                                            If you do not wish to receive these notices please re-check your preferences in the MainWP Settings page.
                                            <br>
                                            <br>
                                        </td>
                                    </tr>
                                </tbody></table>
                            </td>
                        </tr>
                    </tbody></table>

                </center>
            </div>
</div>
<br>';
	}

	public static function update_option( $option_name, $option_value, $autoload = 'no' ) {
		$success = add_option( $option_name, $option_value, '', $autoload );

		if ( ! $success ) {
			$success = update_option( $option_name, $option_value );
		}

		return $success;
	}

	public static function fix_option( $option_name, $autoload = 'no' ) {
		global $wpdb;

		if ( $autoload != $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM $wpdb->options WHERE option_name = %s", $option_name ) ) ) {
			$option_value = get_option( $option_name );
			delete_option( $option_name );
			add_option( $option_name, $option_value, '', $autoload );
		}
	}

	public static function update_lasttime_backup( $by, $time ) {
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

	public static function get_revisions( $max_revisions ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( " SELECT	`post_parent`, COUNT(*) cnt FROM $wpdb->posts WHERE `post_type` = 'revision' GROUP BY `post_parent` HAVING COUNT(*) > %d ", $max_revisions ) );
	}

	public static function delete_revisions( $results, $max_revisions ) {
		global $wpdb;

		if ( ! is_array( $results ) || 0 === count( $results ) ) {
			return;
		}
		$count_deleted  = 0;
		$results_length = count( $results );
		for ( $i = 0; $i < $results_length; $i ++ ) {
			$number_to_delete = $results[ $i ]->cnt - $max_revisions;
			$count_deleted   += $number_to_delete;
			$results_posts    = $wpdb->get_results( $wpdb->prepare( "SELECT `ID`, `post_modified` FROM  $wpdb->posts WHERE `post_parent`= %d AND `post_type`='revision' ORDER BY `post_modified` ASC", $results[ $i ]->post_parent ) );
			$delete_ids       = array();
			if ( is_array( $results_posts ) && count( $results_posts ) > 0 ) {
				for ( $j = 0; $j < $number_to_delete; $j ++ ) {
					$delete_ids[] = $results_posts[ $j ]->ID;
				}
			}

			if ( count( $delete_ids ) > 0 ) {
				$sql_delete = " DELETE FROM $wpdb->posts WHERE `ID` IN (" . implode( ',', $delete_ids ) . ")"; // phpcs:ignore -- safe
				$wpdb->get_results( $sql_delete ); // phpcs:ignore -- safe
			}
		}

		return $count_deleted;
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

	public static function create_nonce_without_session( $action = - 1 ) {
		$user = wp_get_current_user();
		$uid  = (int) $user->ID;
		if ( ! $uid ) {
			$uid = apply_filters( 'nonce_user_logged_out', $uid, $action );
		}

		$i = wp_nonce_tick();

		return substr( wp_hash( $i . '|' . $action . '|' . $uid, 'nonce' ), - 12, 10 );
	}

	public static function verify_nonce_without_session( $nonce, $action = - 1 ) {
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

	public static function is_screen_with_update() {

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

	/**
	 * Method execute_snippet()
	 *
	 * Execute snippet code
	 *
	 * @param string $code The code  *
	 *
	 * @return array result
	 */
	public static function execute_snippet( $code ) {
		ob_start();
		$result = eval( $code ); // phpcs:ignore Squiz.PHP.Eval -- eval() used safely.
		$output = ob_get_contents();
		ob_end_clean();
		$return = array();
		$error  = error_get_last();
		if ( ( false === $result ) && $error ) {
			$return['status'] = 'FAIL';
			$return['result'] = $error['message'];
		} else {
			$return['status'] = 'SUCCESS';
			$return['result'] = $output;
		}
		return $return;
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
