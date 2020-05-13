<?php

namespace MainWP\Child;

// phpcs:disable WordPress.WP.AlternativeFunctions -- to custom.

class MainWP_Utility {
	
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
	
	
	public function run_saved_snippets() {
		
		if ( isset( $_POST['action'] ) && isset( $_POST['mainwpsignature'] ) ) {
			$action = $_POST['action'];			
			if ( 'run_snippet' === $action || 'save_snippet' === $action || 'delete_snippet' === $action ) {
				return;  // do not run saved snippets if in do action snippet.
			}
		}

		if ( get_option( 'mainwp_ext_snippets_enabled' ) ) {
			$snippets = get_option( 'mainwp_ext_code_snippets' );
			if ( is_array( $snippets ) && count( $snippets ) > 0 ) {
				foreach ( $snippets as $code ) {
					MainWP_Helper::execute_snippet( $code );
				}
			}
		}
	}
	
	public static function fix_for_custom_themes() {
		if ( file_exists( ABSPATH . '/wp-admin/includes/screen.php' ) ) {
			include_once ABSPATH . '/wp-admin/includes/screen.php';
		}
		if ( function_exists( 'et_register_updates_component' ) ) {
			et_register_updates_component();
		}
	}
	
	/**
	 * 
	 * To support maintenance alert
	 * 	 
	 */	
	public function maintenance_alert() {
		if ( ! is_404() ) {
			return;
		}

		if ( 1 !== (int) get_option( 'mainwp_maintenance_opt_alert_404' ) ) {
			return;
		}

		$email = get_option( 'mainwp_maintenance_opt_alert_404_email' );

		if ( empty( $email ) || ! preg_match( '/^[a-zA-Z0-9_.+-]+@[a-zA-Z0-9-]+\.[a-zA-Z0-9-.]+$/is', $email ) ) {
			return;
		}

		// set status.
		header( 'HTTP/1.1 404 Not Found' );
		header( 'Status: 404 Not Found' );

		// site info.
		$blog       = get_bloginfo( 'name' );
		$site       = get_bloginfo( 'url' ) . '/';
		$from_email = get_bloginfo( 'admin_email' );

		// referrer.
		if ( isset( $_SERVER['HTTP_REFERER'] ) ) {
			$referer = MainWP_Helper::clean( $_SERVER['HTTP_REFERER'] );
		} else {
			$referer = 'undefined';
		}
		$protocol = isset( $_SERVER['HTTPS'] ) && strcasecmp( $_SERVER['HTTPS'], 'off' ) ? 'https://' : 'http://';
		// request URI.
		if ( isset( $_SERVER['REQUEST_URI'] ) && isset( $_SERVER['HTTP_HOST'] ) ) {
			$request = MainWP_Helper::clean( $protocol . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] );
		} else {
			$request = 'undefined';
		}
		// query string.
		if ( isset( $_SERVER['QUERY_STRING'] ) ) {
			$string = MainWP_Helper::clean( $_SERVER['QUERY_STRING'] );
		} else {
			$string = 'undefined';
		}
		// IP address.
		if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$address = MainWP_Helper::clean( $_SERVER['REMOTE_ADDR'] );
		} else {
			$address = 'undefined';
		}
		// user agent.
		if ( isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
			$agent = MainWP_Helper::clean( $_SERVER['HTTP_USER_AGENT'] );
		} else {
			$agent = 'undefined';
		}
		// identity.
		if ( isset( $_SERVER['REMOTE_IDENT'] ) ) {
			$remote = MainWP_Helper::clean( $_SERVER['REMOTE_IDENT'] );
		} else {
			$remote = 'undefined';
		}
		// log time.
		$time = MainWP_Helper::clean( date( 'F jS Y, h:ia', time() ) ); // phpcs:ignore -- local time.

		$mail = '<div>404 alert</div><div></div>' .
				'<div>TIME: ' . $time . '</div>' .
				'<div>*404: ' . $request . '</div>' .
				'<div>SITE: ' . $site . '</div>' .
				'<div>REFERRER: ' . $referer . '</div>' .
				'<div>QUERY STRING: ' . $string . '</div>' .
				'<div>REMOTE ADDRESS: ' . $address . '</div>' .
				'<div>REMOTE IDENTITY: ' . $remote . '</div>' .
				'<div>USER AGENT: ' . $agent . '</div>';
		wp_mail(
			$email,
			'MainWP - 404 Alert: ' . $blog,
			MainWP_Helper::format_email( $email, $mail ),
			array(
				'content-type: text/html',
			)
		);
	}
	
	public function cron_active() {
		if ( ! defined( 'DOING_CRON' ) || ! DOING_CRON ) {
			return;
		}
		if ( empty( $_GET['mainwp_child_run'] ) || 'test' !== $_GET['mainwp_child_run'] ) {
			return;
		}
		session_write_close();
		header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ), true );
		header( 'X-Robots-Tag: noindex, nofollow', true );
		header( 'X-MainWP-Child-Version: ' . MainWP_Child::$version, true );
		nocache_headers();
		if ( 'test' == $_GET['mainwp_child_run'] ) {
			die( 'MainWP Test' );
		}
		die( '' );
	}
	
	
	/**
	 * 
	 * To support upload backup files.
	 * 
	 */
	public function upload_file( $file, $offset = 0 ) {
		$dirs      = MainWP_Helper::get_mainwp_dir( 'backup' );
		$backupdir = $dirs[0];

		header( 'Content-Description: File Transfer' );

		header( 'Content-Description: File Transfer' );
		if ( MainWP_Helper::ends_with( $file, '.tar.gz' ) ) {
			header( 'Content-Type: application/x-gzip' );
			header( 'Content-Encoding: gzip' );
		} else {
			header( 'Content-Type: application/octet-stream' );
		}
		header( 'Content-Disposition: attachment; filename="' . basename( $file ) . '"' );
		header( 'Expires: 0' );
		header( 'Cache-Control: must-revalidate' );
		header( 'Pragma: public' );
		header( 'Content-Length: ' . filesize( $backupdir . $file ) );
		while ( ob_end_flush() ) {; // phpcs:ignore
		}
		$this->readfile_chunked( $backupdir . $file, $offset );
	}

	public function readfile_chunked( $filename, $offset ) {
		$chunksize = 1024; // how many bytes per chunk?
		$handle    = fopen( $filename, 'rb' );
		if ( false === $handle ) {
			return false;
		}

		fseek( $handle, $offset );

		while ( ! feof( $handle ) ) {
			$buffer = fread( $handle, $chunksize );
			echo $buffer;
			ob_flush();
			flush();
			$buffer = null;
		}

		return fclose( $handle );
	}
	
}
