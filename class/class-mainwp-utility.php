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
					self::execute_snippet( $code );
				}
			}
		}
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


	/**
	 * Handle fatal error for requests from the dashboard
	 * mwp_action requests
	 * wordpress_seo requests
	 * This will do not handle fatal error for sync request from the dashboard
	 */
	public static function handle_fatal_error() {

		function handle_shutdown() {
			// handle fatal errors and compile errors.
			$error = error_get_last();
			if ( isset( $error['type'] ) && isset( $error['message'] ) && ( E_ERROR === $error['type'] || E_COMPILE_ERROR === $error['type'] ) ) {
				mainwp_child_helper()->write( array( 'error' => 'MainWP_Child fatal error : ' . $error['message'] . ' Line: ' . $error['line'] . ' File: ' . $error['file'] ) );
			}
		}

		if ( isset( $_POST['function'] ) && isset( $_POST['mainwpsignature'] ) && ( isset( $_POST['mwp_action'] ) || 'wordpress_seo' == $_POST['function'] ) ) {
			register_shutdown_function( 'handle_shutdown' );
		}
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

	// $check_file_existed: to support checking if file existed.
	// $parent_id: optional.
	public static function upload_image( $img_url, $img_data = array(), $check_file_existed = false, $parent_id = 0 ) {
		if ( ! is_array( $img_data ) ) {
			$img_data = array();
		}

		/** @var $wp_filesystem WP_Filesystem_Base */
		global $wp_filesystem;
		MainWP_Helper::get_wp_filesystem();

		include_once ABSPATH . 'wp-admin/includes/file.php';
		$upload_dir = wp_upload_dir();
		add_filter( 'http_request_args', array( MainWP_Helper::get_class_name(), 'reject_unsafe_urls' ), 99, 2 );
		$temporary_file = download_url( $img_url );
		remove_filter( 'http_request_args', array( MainWP_Helper::get_class_name(), 'reject_unsafe_urls' ), 99, 2 );

		if ( is_wp_error( $temporary_file ) ) {
			throw new \Exception( 'Error: ' . $temporary_file->get_error_message() );
		} else {
			$filename       = basename( $img_url );
			$local_img_path = $upload_dir['path'] . DIRECTORY_SEPARATOR . $filename;
			$local_img_url  = $upload_dir['url'] . '/' . basename( $local_img_path );

			// to fix issue re-create new attachment.
			if ( $check_file_existed ) {
				$result = self::check_media_file_existed( $upload_dir, $filename, $temporary_file, $local_img_path, $local_img_url );
				if ( ! empty( $result ) ) {
					return $result;
				}
			}

			// file exists, do not overwrite, generate unique file name.
			// this may causing of issue incorrect source of image in post content.
			if ( $wp_filesystem->exists( $local_img_path ) ) {
				$local_img_path = dirname( $local_img_path ) . '/' . wp_unique_filename( dirname( $local_img_path ), basename( $local_img_path ) );
				$local_img_url  = $upload_dir['url'] . '/' . basename( $local_img_path );
			}

			$moved = $wp_filesystem->move( $temporary_file, $local_img_path );
			if ( $moved ) {
				return self::insert_attachment_media( $img_data, $img_url, $parent_id, $local_img_path, $local_img_url );
			}
		}

		if ( $wp_filesystem->exists( $temporary_file ) ) {
			$wp_filesystem->delete( $temporary_file );
		}
		return null;
	}

	private static function check_media_file_existed( $upload_dir, $filename, $temporary_file, &$local_img_path, $local_img_url ) {
		global $wp_filesystem;
		if ( $wp_filesystem->exists( $local_img_path ) ) {
			if ( filesize( $local_img_path ) == filesize( $temporary_file ) ) {
				$result = self::get_maybe_existed_attached_id( $local_img_url );
				if ( is_array( $result ) ) {
					$attach = current( $result );
					if ( is_object( $attach ) ) {
						if ( $wp_filesystem->exists( $temporary_file ) ) {
							$wp_filesystem->delete( $temporary_file );
						}
						return array(
							'id'  => $attach->ID,
							'url' => $local_img_url,
						);
					}
				}
			}
		} else {
			$result = self::get_maybe_existed_attached_id( $filename, false );
			if ( is_array( $result ) ) {
				$attach = current( $result );
				if ( is_object( $attach ) ) {
					$basedir        = $upload_dir['basedir'];
					$baseurl        = $upload_dir['baseurl'];
					$local_img_path = str_replace( $baseurl, $basedir, $attach->guid );
					if ( $wp_filesystem->exists( $local_img_path ) && ( $wp_filesystem->size( $local_img_path ) == $wp_filesystem->size( $temporary_file ) ) ) {
						if ( $wp_filesystem->exists( $temporary_file ) ) {
							$wp_filesystem->delete( $temporary_file );
						}
						return array(
							'id'  => $attach->ID,
							'url' => $attach->guid,
						);
					}
				}
			}
		}
	}

	private static function insert_attachment_media( $img_data, $img_url, $parent_id, $local_img_path, $local_img_url ) {

		$wp_filetype = wp_check_filetype( basename( $img_url ), null ); // Get the filetype to set the mimetype.
		$attachment  = array(
			'post_mime_type' => $wp_filetype['type'],
			'post_title'     => isset( $img_data['title'] ) && ! empty( $img_data['title'] ) ? $img_data['title'] : preg_replace( '/\.[^.]+$/', '', basename( $img_url ) ),
			'post_content'   => isset( $img_data['description'] ) && ! empty( $img_data['description'] ) ? $img_data['description'] : '',
			'post_excerpt'   => isset( $img_data['caption'] ) && ! empty( $img_data['caption'] ) ? $img_data['caption'] : '',
			'post_status'    => 'inherit',
			'guid'           => $local_img_url,
		);

		// for post attachments, thumbnail.
		if ( $parent_id ) {
			$attachment['post_parent'] = $parent_id;
		}

		$attach_id = wp_insert_attachment( $attachment, $local_img_path ); // Insert the image in the database.
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$attach_data = wp_generate_attachment_metadata( $attach_id, $local_img_path );
		wp_update_attachment_metadata( $attach_id, $attach_data ); // Update generated metadata.
		if ( isset( $img_data['alt'] ) && ! empty( $img_data['alt'] ) ) {
			update_post_meta( $attach_id, '_wp_attachment_image_alt', $img_data['alt'] );
		}
		return array(
			'id'  => $attach_id,
			'url' => $local_img_url,
		);
	}

	public static function get_maybe_existed_attached_id( $filename, $full_guid = true ) {
		global $wpdb;
		if ( $full_guid ) {
			return $wpdb->get_results( $wpdb->prepare( "SELECT ID,guid FROM $wpdb->posts WHERE post_type = 'attachment' AND guid = %s", $filename ) );
		}
		return $wpdb->get_results( $wpdb->prepare( "SELECT ID,guid FROM $wpdb->posts WHERE post_type = 'attachment' AND guid LIKE %s", '%/' . $wpdb->esc_like( $filename ) ) );
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

}
