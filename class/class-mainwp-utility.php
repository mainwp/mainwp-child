<?php
/**
 * MainWP Utility
 *
 * @package MainWP/Child
 */

namespace MainWP\Child;

// phpcs:disable WordPress.WP.AlternativeFunctions, Generic.Metrics.CyclomaticComplexity -- Custom functions required to achieve desired results, pull request solutions appreciated.

/**
 * Class MainWP_Utility
 *
 * MainWP Utility
 */
class MainWP_Utility {

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
	 * Method run_saved_snippets()
	 *
	 * Fire off  MainWP Code Snippets Extension code snipets execution.
	 *
	 * @return void
	 */
	public function run_saved_snippets() {
		if ( isset( $_POST['action'] ) && isset( $_POST['mainwpsignature'] ) ) {
			$action = ! empty( $_POST['action'] ) ? sanitize_text_field( wp_unslash( $_POST['action'] ) ) : '';
			if ( 'run_snippet' === $action || 'save_snippet' === $action || 'delete_snippet' === $action ) {
				return;
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
	 * Execute MainWP Code Snippets Extension custom code snippets.
	 *
	 * @param string $code Custom code snippet code (content).
	 *
	 * @return array Array contaning result information.
	 */
	public static function execute_snippet( $code ) {
		ob_start();
		$result = eval( $code ); // phpcs:ignore Squiz.PHP.Eval -- eval() used safely to achieve desired results, pull request solutions appreciated.
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

	/**
	 * Method fix_for_custom_themes()
	 *
	 * Custom fix for the Elegant Themes products compatibility.
	 */
	public static function fix_for_custom_themes() {
		if ( file_exists( ABSPATH . '/wp-admin/includes/screen.php' ) ) {
			include_once ABSPATH . '/wp-admin/includes/screen.php';
		}
		if ( function_exists( 'et_register_updates_component' ) ) {
			et_register_updates_component();
		}
	}

	/**
	 * Method send_wp_mail()
	 *
	 * Sends notification email.
	 *
	 * @param string|array $to          Array or comma-separated list of email addresses to send message.
	 * @param string       $subject     Email subject.
	 * @param string       $message     Message contents.
	 * @param string|array $headers     Optional. Additional headers.
	 *
	 * @return bool Whether the email contents were sent successfully.
	 */
	public function send_wp_mail( $to, $subject, $message, $headers = '' ) {
		return wp_mail(
			$to,
			$subject,
			$message,
			$headers
		);
	}


	/**
	 * Method handle_shutdown()
	 *
	 * Handle fatal and compile errors.
	 */
	public static function handle_shutdown() {
		$error = error_get_last();
		if ( isset( $error['type'] ) && isset( $error['message'] ) && ( E_ERROR === $error['type'] || E_COMPILE_ERROR === $error['type'] ) ) {
			MainWP_Helper::write( array( 'error' => 'MainWP_Child fatal error : ' . $error['message'] . ' Line: ' . $error['line'] . ' File: ' . $error['file'] ) );
		}
	}

	/**
	 * Method handle_fatal_error()
	 *
	 * Handle fatal error for requests from the MainWP Dashboard.
	 *
	 * @uses \MainWP\Child\MainWP_Utility::handle_shutdown()
	 */
	public static function handle_fatal_error() {
		if ( isset( $_POST['function'] ) && isset( $_POST['mainwpsignature'] ) && ( isset( $_POST['mwp_action'] ) || 'wordpress_seo' == $_POST['function'] ) ) {
			register_shutdown_function( '\MainWP\Child\MainWP_Utility::handle_shutdown' );
		}
	}

	/**
	 * Method cron_active()
	 *
	 * Start job if in cron and run query args are set.
	 *
	 * @return void
	 *
	 * @uses \MainWP\Child\MainWP_Child::$version
	 */
	public static function cron_active() {
		if ( ! defined( 'DOING_CRON' ) || ! DOING_CRON ) {
			return;
		}
		if ( empty( $_GET['mainwp_child_run'] ) || 'test' !== $_GET['mainwp_child_run'] ) {
			return;
		}
		session_write_close();
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ), true );
			header( 'X-Robots-Tag: noindex, nofollow', true );
			header( 'X-MainWP-Child-Version: ' . MainWP_Child::$version, true );
			nocache_headers();
		}
		die( 'MainWP Test' );
	}


	/**
	 * Method upload_file()
	 *
	 * Upload bacup fils to execute clone or restore proces.
	 *
	 * @param mixed $file Backup file.
	 * @param int   $offset Offset value.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::get_mainwp_dir()
	 * @uses \MainWP\Child\MainWP_Helper::ends_with()
	 */
	public function upload_file( $file, $offset = 0 ) {

		if ( empty( $file ) || stristr( $file, '..' ) ) {
			return false;
		}

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
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		$this->readfile_chunked( $backupdir . $file, $offset );
	}

	/**
	 * Method readfile_chunked()
	 *
	 * Read chunked backup file.
	 *
	 * @param string $filename Backup file name.
	 * @param int    $offset Offset value.
	 *
	 * @return mixed Close file.
	 */
	public function readfile_chunked( $filename, $offset ) {
		$chunksize = 1024;
		$handle    = fopen( $filename, 'rb' );
		if ( false === $handle ) {
			return false;
		}

		fseek( $handle, $offset );

		while ( ! feof( $handle ) ) {
			$buffer = fread( $handle, $chunksize );
			echo $buffer;
			if ( ob_get_length() ) {
				ob_flush();
				flush();
			}
			$buffer = null;
		}

		return fclose( $handle );
	}

	/**
	 * Method upload_image()
	 *
	 * Upload images from the MainWP Dashboard while posting Posts and/or Pages.
	 *
	 * @param string $img_url Contains image URL.
	 * @param array  $img_data Contains image data.
	 * @param bool   $check_file_existed Does the file exist? True or false.
	 * @param int    $parent_id Attachment parent post ID.
	 *
	 * @return null NULL
	 * @throws \Exception Error message.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::get_wp_filesystem()
	 * @uses \MainWP\Child\MainWP_Helper::get_class_name()
	 */
	public static function upload_image( $img_url, $img_data = array(), $check_file_existed = false, $parent_id = 0 ) {
		if ( ! is_array( $img_data ) ) {
			$img_data = array();
		}

		/**
		 * Global variable containing the instance of the (auto-)configured filesystem object after the filesystem "factory" has been run.
		 *
		 * @global object $wp_filesystem Filesystem object.
		 */
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

			// to fix issue with recreating new attachment.
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

			if ( self::instance()->check_image_file_name( $local_img_path ) ) {
				$moved = $wp_filesystem->move( $temporary_file, $local_img_path );
				if ( $moved ) {
					return self::insert_attachment_media( $img_data, $img_url, $parent_id, $local_img_path, $local_img_url );
				}
			}
		}

		if ( $wp_filesystem->exists( $temporary_file ) ) {
			$wp_filesystem->delete( $temporary_file );
		}
		return null;
	}

	/**
	 * Method check_image_file_name()
	 *
	 * Check if the file image.
	 *
	 * @param string $filename Contains image (file) name.
	 *
	 * @return true|false valid name or not.
	 */
	public function check_image_file_name( $filename ) {
		if ( validate_file( $filename ) ) {
			return false;
		}

		$allowed_files = array( 'jpg', 'jpeg', 'jpe', 'gif', 'png', 'bmp', 'tif', 'tiff', 'ico' );
		$names         = explode( '.', $filename );
		$file_ext      = strtolower( end( $names ) );
		if ( ! in_array( $file_ext, $allowed_files ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Method check_media_file_existed()
	 *
	 * Check if the media file already exists.
	 *
	 * @param string   $upload_dir Contains upload directory path.
	 * @param string   $filename Contains image (file) name.
	 * @param resource $temporary_file Temporary file.
	 * @param string   $local_img_path Local media file path.
	 * @param string   $local_img_url Local media file URL.
	 *
	 * @return array Media file ID and URL.
	 */
	private static function check_media_file_existed( $upload_dir, $filename, $temporary_file, &$local_img_path, $local_img_url ) {

		/**
		 * Global variable containing the instance of the (auto-)configured filesystem object after the filesystem "factory" has been run.
		 *
		 * @global object $wp_filesystem Filesystem object.
		 */
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

	/**
	 * Method insert_attachment_media()
	 *
	 * Insterd attachment media.
	 *
	 * @param array  $img_data Array containing image data.
	 * @param string $img_url Contains the media file URL.
	 * @param int    $parent_id Attachment parent post ID.
	 * @param string $local_img_path Contains the local media file path.
	 * @param string $local_img_url Contains the local media file URL.
	 *
	 * @return array Media file ID and URL.
	 */
	private static function insert_attachment_media( $img_data, $img_url, $parent_id, $local_img_path, $local_img_url ) {

		$wp_filetype = wp_check_filetype( basename( $img_url ), null ); // Get the filetype to set the mimetype.
		$attachment  = array(
			'post_mime_type' => $wp_filetype['type'],
			'post_title'     => isset( $img_data['title'] ) && ! empty( $img_data['title'] ) ? htmlspecialchars( $img_data['title'] ) : preg_replace( '/\.[^.]+$/', '', basename( $img_url ) ),
			'post_content'   => isset( $img_data['description'] ) && ! empty( $img_data['description'] ) ? $img_data['description'] : '',
			'post_excerpt'   => isset( $img_data['caption'] ) && ! empty( $img_data['caption'] ) ? self::esc_content( $img_data['caption'], 'mixed' ) : '',
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

	/**
	 * Method get_maybe_existed_attached_id()
	 *
	 * If the media file exists, get the attachment ID.
	 *
	 * @param string $filename Contains the media file name.
	 * @param bool   $full_guid Full global unique identifier.
	 *
	 * @return int Attachment ID.
	 */
	public static function get_maybe_existed_attached_id( $filename, $full_guid = true ) {

		/**
		 * Object, providing access to the WordPress database.
		 *
		 * @global $wpdb WordPress Database instance.
		 */
		global $wpdb;

		if ( $full_guid ) {
			return $wpdb->get_results( $wpdb->prepare( "SELECT ID,guid FROM $wpdb->posts WHERE post_type = 'attachment' AND guid = %s", $filename ) );
		}
		return $wpdb->get_results( $wpdb->prepare( "SELECT ID,guid FROM $wpdb->posts WHERE post_type = 'attachment' AND guid LIKE %s", '%/' . $wpdb->esc_like( $filename ) ) );
	}

	/**
	 * Method fetch_url()
	 *
	 * Fire off the m_fetch_url() to execute communication with the MainWP Dashboard.
	 *
	 * @param string $url Contains the URL.
	 * @param array  $postdata Array containg the post request information.
	 *
	 * @throws string $e Error message.
	 */
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

	/**
	 * Method fetch_url()
	 *
	 * Execute communication with the MainWP Dashboard.
	 *
	 * @param string $url Contains the URL.
	 * @param array  $postdata Array containg the post request information.
	 *
	 * @throws \Exception Error message.
	 *
	 * @uses \MainWP\Child\MainWP_Child::$version
	 */
	public static function m_fetch_url( $url, $postdata ) {
		$agent = 'Mozilla/5.0 (compatible; MainWP-Child/' . MainWP_Child::$version . '; +http://mainwp.com)';

		if ( ! is_array( $postdata ) ) {
			$postdata = array();
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions -- Custom functions required to achieve desired results, pull request solutions appreciated.
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
		if ( 'resource' === gettype( $ch ) ) {
			curl_close( $ch );
		}

		if ( ( false === $data ) && ( 0 === $http_status ) ) {
			throw new \Exception( 'Http Error: ' . $err );
		} elseif ( preg_match( '/<mainwp>(.*)<\/mainwp>/', $data, $results ) > 0 ) {
			$result      = $results[1];
			$result_base = base64_decode( $result ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for backwards compatibility.
			$information = json_decode( $result_base, true );
			return $information;
		} elseif ( '' === $data ) {
			throw new \Exception( esc_html__( 'Something went wrong while contacting the child site. Please check if there is an error on the child site. This error could also be caused by trying to clone or restore a site to large for your server settings.', 'mainwp-child' ) );
		} else {
			throw new \Exception( esc_html__( 'Child plugin is disabled or the security key is incorrect. Please resync with your main installation.', 'mainwp-child' ) );
		}
		// phpcs:enable
	}

	/**
	 * Method validate_mainwp_dir()
	 *
	 * Check if the /mainwp/ directory is writable.
	 *
	 * @return bool $done Is the /mainwp/ directory writable? True or false.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::get_mainwp_dir()
	 * @uses \MainWP\Child\MainWP_Helper::get_wp_filesystem()
	 * @uses \MainWP\Child\MainWP_Helper::check_dir()
	 */
	public static function validate_mainwp_dir() {
		$done = false;
		$dir  = MainWP_Helper::get_mainwp_dir();
		$dir  = $dir[0];
		if ( MainWP_Helper::get_wp_filesystem() ) {

			/**
			 * Global variable containing the instance of the (auto-)configured filesystem object after the filesystem "factory" has been run.
			 *
			 * @global object $wp_filesystem Filesystem object.
			 */
			global $wp_filesystem;

			try {
				MainWP_Helper::check_dir( $dir, false );
			} catch ( \Exception $e ) {
				// ok!
			}
			if ( ! empty( $wp_filesystem ) ) {
				if ( $wp_filesystem->is_writable( $dir ) ) {
					$done = true;
				}
			}
		}

		//phpcs:disable -- System functions required to achieve desired results, pull request solutions appreciated.
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

	/**
	 * Method close_connection()
	 *
	 * Close connection.
	 *
	 * @param array $val Array containing connection information.
	 */
	public static function close_connection( $val = null ) {
		$output = wp_json_encode( $val );
		$output = '<mainwp>' . base64_encode( $output ) . '</mainwp>'; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for backwards compatibility.
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
		sleep( 10 );
	}

	/**
	 * Method send_maintenance_alert()
	 *
	 * MainWP Maintenance Extension feature to send email notification for 404 (Page not found) errors.
	 */
	public function send_maintenance_alert() {
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
		$blog = get_bloginfo( 'name' );
		$site = get_bloginfo( 'url' ) . '/';

		// referrer.
		$referer = isset( $_SERVER['HTTP_REFERER'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : 'undefined';

		$protocol = isset( $_SERVER['HTTPS'] ) && strcasecmp( sanitize_text_field( wp_unslash( $_SERVER['HTTPS'] ) ), 'off' ) ? 'https://' : 'http://';
		// request URI.
		$request = isset( $_SERVER['REQUEST_URI'] ) && isset( $_SERVER['HTTP_HOST'] ) ? $protocol . sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) . wp_strip_all_tags( wp_unslash( $_SERVER['REQUEST_URI'] ) ) ) : 'undefined';

		// query string.
		$string = isset( $_SERVER['QUERY_STRING'] ) ? sanitize_text_field( wp_strip_all_tags( wp_unslash( $_SERVER['QUERY_STRING'] ) ) ) : 'undefined';
		// IP address.
		$address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_strip_all_tags( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) ) : 'undefined';

		// user agent.
		$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_strip_all_tags( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) ) : 'undefined';

		// identity.
		$remote = isset( $_SERVER['REMOTE_IDENT'] ) ? sanitize_text_field( wp_strip_all_tags( wp_unslash( $_SERVER['REMOTE_IDENT'] ) ) ) : 'undefined';

		// log time.
		$time = sanitize_text_field( wp_unslash( date( 'F jS Y, h:ia', time() ) ) ); // phpcs:ignore -- Use local time to achieve desired results, pull request solutions appreciated.

		$mail = '<div>404 alert</div><div></div>' .
				'<div>TIME: ' . $time . '</div>' .
				'<div>*404: ' . $request . '</div>' .
				'<div>SITE: ' . $site . '</div>' .
				'<div>REFERRER: ' . $referer . '</div>' .
				'<div>QUERY STRING: ' . $string . '</div>' .
				'<div>REMOTE ADDRESS: ' . $address . '</div>' .
				'<div>REMOTE IDENTITY: ' . $remote . '</div>' .
				'<div>USER AGENT: ' . $agent . '</div>';
		$this->send_wp_mail(
			$email,
			'MainWP - 404 Alert: ' . $blog,
			MainWP_Child_Format::format_email( $mail ),
			array(
				'content-type: text/html',
			)
		);
	}

	/**
	 * Send support email.
	 *
	 * @return bool Return TRUE on success FALSE on failure.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Branding::get_branding_options()
	 */
	public function send_support_mail() {
		$opts    = MainWP_Child_Branding::instance()->get_branding_options();
		$email   = $opts['support_email'];
		$sub     = isset( $_POST['mainwp_branding_contact_message_subject'] ) ? wp_kses_post( nl2br( stripslashes( wp_unslash( $_POST['mainwp_branding_contact_message_subject'] ) ) ) ) : '';
		$from    = isset( $_POST['mainwp_branding_contact_send_from'] ) ? trim( wp_unslash( $_POST['mainwp_branding_contact_send_from'] ) ) : '';
		$subject = ! empty( $sub ) ? $sub : 'MainWP - Support Contact';
		$content = isset( $_POST['mainwp_branding_contact_message_content'] ) ? wp_kses_post( nl2br( stripslashes( wp_unslash( $_POST['mainwp_branding_contact_message_content'] ) ) ) ) : '';
		$mail    = '';
		$headers = '';

		$from_page = isset( $_POST['mainwp_branding_send_from_page'] ) ? wp_unslash( $_POST['mainwp_branding_send_from_page'] ) : '';

		if ( ! empty( $_POST['mainwp_branding_contact_message_content'] ) && ! empty( $email ) ) {

			/**
			 * Current user global.
			 *
			 * @global string
			 */
			global $current_user;

			$headers .= "Content-Type: text/html;charset=utf-8\r\n";
			if ( ! empty( $from ) ) {
				$headers .= 'From: "' . $from . '" <' . $from . ">\r\n";
			}

			$headers = apply_filters( 'mainwp_child_contact_support_mail_headers', $headers, $email, $from );

			$mail .= "<p>Support Email from: <a href='" . site_url() . "'>" . site_url() . "</a></p>\r\n\r\n";
			$mail .= '<p>Sent from WordPress page: ' . ( ! empty( $from_page ) ? "<a href='" . esc_url( $from_page ) . "'>" . esc_url( $from_page ) . "</a></p>\r\n\r\n" : '' );
			$mail .= '<p>Client Email: ' . $current_user->user_email . " </p>\r\n\r\n";
			$mail .= "<p>Support Text:</p>\r\n\r\n";
			$mail .= '<p>' . $content . "</p>\r\n\r\n";

			self::instance()->send_wp_mail( $email, $subject, $mail, $headers );

			return true;
		}
		return false;
	}

	/**
	 * Method create_nonce_without_session()
	 *
	 * Create nonce without session.
	 *
	 * @param mixed $action Action to perform.
	 *
	 * @return string Custom nonce.
	 */
	public static function create_nonce_without_session( $action = - 1 ) {
		$user = wp_get_current_user();
		$uid  = (int) $user->ID;
		if ( ! $uid ) {
			$uid = apply_filters( 'nonce_user_logged_out', $uid, $action );
		}

		$i = wp_nonce_tick();

		return substr( wp_hash( $i . '|' . $action . '|' . $uid, 'nonce' ), - 12, 10 );
	}

	/**
	 * Method verify_nonce_without_session()
	 *
	 * Verify nonce without session.
	 *
	 * @param string $nonce Nonce to verify.
	 * @param mixed  $action Action to perform.
	 *
	 * @return mixed If verified return 1 or 2, if not return false.
	 */
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

	/**
	 * Method update_lasttime_backup()
	 *
	 * Update the last backup timestap.
	 *
	 * @param string $by Selected backup system.
	 * @param string $time Time of the backup exacution.
	 *
	 * @return bool true|false If updated, return true, if the last backup time not updated, return false.
	 */
	public static function update_lasttime_backup( $by, $time ) {
		$backup_by = array( 'backupbuddy', 'backupwordpress', 'backwpup', 'updraftplus', 'wptimecapsule', 'wpvivid' );
		if ( ! in_array( $by, $backup_by ) ) {
			return false;
		}

		$lasttime = get_option( 'mainwp_lasttime_backup_' . $by );
		if ( $time > $lasttime ) {
			update_option( 'mainwp_lasttime_backup_' . $by, $time );
		}

		return true;
	}

	/**
	 * Method get_lasttime_backup()
	 *
	 * Get the last backup timestap.
	 *
	 * @param string $by Selected backup system.
	 *
	 * @return mixed If activated any of the supported backup systems, return the last backup timestamp.
	 */
	public static function get_lasttime_backup( $by ) { // phpcs:ignore -- required to achieve desired results, pull request solutions appreciated.
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
			case 'wpvivid':
				if ( ! is_plugin_active( 'wpvivid-backuprestore/wpvivid-backuprestore.php' ) ) {
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

	/**
	 * Method remove_filters_by_hook_name()
	 *
	 * Remove filters with method name.
	 *
	 * @param string $hook_name   Contains the hook name.
	 * @param int    $priority    Contains the priority value.
	 *
	 * @return bool Return false if filtr is not set.
	 */
	public static function remove_filters_by_hook_name( $hook_name = '', $priority = 0 ) {

		/**
		 * WordPress filter array.
		 *
		 * @global array $wp_filter WordPress filter array.
		 */
		global $wp_filter;

		// Take only filters on right hook name and priority.
		if ( ! isset( $wp_filter[ $hook_name ][ $priority ] ) || ! is_array( $wp_filter[ $hook_name ][ $priority ] ) ) {
			return false;
		}
		// Loop on filters registered.
		foreach ( (array) $wp_filter[ $hook_name ][ $priority ] as $unique_id => $filter_array ) {
			// Test if filter is an object (suppoted object only).
			if ( isset( $filter_array['function'] ) && is_object( $filter_array['function'] ) ) {
				// Test for WordPress >= 4.7 WP_Hook class.
				if ( is_a( $wp_filter[ $hook_name ], 'WP_Hook' ) ) {
					unset( $wp_filter[ $hook_name ]->callbacks[ $priority ][ $unique_id ] );
				} else {
					unset( $wp_filter[ $hook_name ][ $priority ][ $unique_id ] );
				}
			}
		}
		return false;
	}

	/**
	 * Get an array of user roles
	 *
	 * @return array
	 */
	public function get_roles() {
		$wp_roles = new \WP_Roles();
		$roles    = array();

		foreach ( $wp_roles->get_names() as $role => $label ) {
			if ( is_string( $label ) ) {
				$roles[ $role ] = translate_user_role( $label );
			}
		}

		return $roles;
	}

	/**
	 * Method maybe_base64_decode()
	 *
	 * Maybe base64 decode string.
	 *
	 * @param string $str input string.
	 *
	 * @return string $decoded Maybe base64 decode string.
	 */
	public function maybe_base64_decode( $str ) {
		$decoded = base64_decode( $str ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Required for backwards compatibility.
		$Str1    = preg_replace( '/[\x00-\x1F\x7F-\xFF]/', '', $decoded );
		if ( $Str1 != $decoded || '' == $Str1 ) {
			return $str;
		}
		return $decoded;
	}


	/**
	 * Method esc_content()
	 *
	 * Escape content,
	 * allowed content (a,href,title,br,em,strong,p,hr,ul,ol,li,h1,h2 ... ).
	 *
	 * @param mixed  $content Content to escape.
	 * @param string $type Type of content. Default = note.
	 * @param mixed  $more_allowed input allowed tags - options.
	 *
	 * @return string Filtered content containing only the allowed HTML.
	 */
	public static function esc_content( $content, $type = 'note', $more_allowed = array() ) {
		if ( ! is_string( $content ) ) {
			return $content;
		}

		if ( 'note' === $type ) {

			$allowed_html = array(
				'a'      => array(
					'href'  => array(),
					'title' => array(),
				),
				'br'     => array(),
				'em'     => array(),
				'strong' => array(),
				'p'      => array(),
				'hr'     => array(),
				'ul'     => array(),
				'ol'     => array(),
				'li'     => array(),
				'h1'     => array(),
				'h2'     => array(),
			);

			if ( is_array( $more_allowed ) && ! empty( $more_allowed ) ) {
				$allowed_html = array_merge( $allowed_html, $more_allowed );
			}

			$content = wp_kses( $content, $allowed_html );

		} elseif ( 'mixed' == $type ) {

			$allowed_html = array(
				'a'      => array(
					'href'    => array(),
					'title'   => array(),
					'class'   => array(),
					'onclick' => array(),
				),
				'img'    => array(
					'src'     => array(),
					'title'   => array(),
					'class'   => array(),
					'onclick' => array(),
					'alt'     => array(),
					'width'   => array(),
					'height'  => array(),
					'sizes'   => array(),
					'srcset'  => array(),
					'usemap'  => array(),
				),
				'br'     => array(),
				'em'     => array(),
				'strong' => array(),
				'p'      => array(),
				'hr'     => array(),
				'ul'     => array(
					'style' => array(),
				),
				'ol'     => array(),
				'li'     => array(),
				'h1'     => array(),
				'h2'     => array(),
				'head'   => array(),
				'html'   => array(
					'lang' => array(),
				),
				'meta'   => array(
					'name'       => array(),
					'http-equiv' => array(),
					'content'    => array(),
					'charset'    => array(),
				),
				'title'  => array(),
				'body'   => array(
					'style' => array(),
				),
				'span'   => array(
					'id'    => array(),
					'style' => array(),
					'class' => array(),
				),
				'form'   => array(
					'id'       => array(),
					'method'   => array(),
					'action'   => array(),
					'onsubmit' => array(),
				),
				'table'  => array(
					'class' => array(),
				),
				'thead'  => array(
					'class' => array(),
				),
				'tbody'  => array(
					'class' => array(),
				),
				'tr'     => array(
					'id' => array(),
				),
				'td'     => array(
					'class' => array(),
				),
				'div'    => array(
					'id'    => array(),
					'style' => array(),
					'class' => array(),
				),
				'input'  => array(
					'type'    => array(),
					'name'    => array(),
					'class'   => array(),
					'value'   => array(),
					'onclick' => array(),
				),
				'button' => array(
					'type'    => array(),
					'name'    => array(),
					'value'   => array(),
					'class'   => array(),
					'title'   => array(),
					'onclick' => array(),
				),
			);

			if ( is_array( $more_allowed ) && ! empty( $more_allowed ) ) {
				$allowed_html = array_merge( $allowed_html, $more_allowed );
			}

			$content = wp_kses( $content, $allowed_html );
		} else {
			$content = wp_kses_post( $content );
		}

		return $content;
	}
}
