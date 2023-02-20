<?php
/**
 * MainWP MainWP_Clone
 *
 * Manage child site cloning process.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

/**
 * Class MainWP_Clone
 *
 * Manage child site cloning process.
 */
class MainWP_Clone {

	/**
	 * Public static variable to hold the single instance of the class.
	 *
	 * @var mixed Default null
	 */
	protected static $instance = null;

	/**
	 * Protected variable to hold security nonces.
	 *
	 * @var array Security nonces.
	 */
	protected $security_nonces;

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
	 * Method init_ajax()
	 *
	 * Initiate AJAX requests.
	 */
	public function init_ajax() {
		$this->add_action( 'mainwp-child_clone_backupcreate', array( &$this, 'clone_backup_create' ) );
		$this->add_action( 'mainwp-child_clone_backupcreatepoll', array( &$this, 'clone_backup_create_poll' ) );
		$this->add_action( 'mainwp-child_clone_backupdownload', array( &$this, 'clone_backup_download' ) );
		$this->add_action( 'mainwp-child_clone_backupdownloadpoll', array( &$this, 'clone_backup_download_poll' ) );
		$this->add_action( 'mainwp-child_clone_backupextract', array( &$this, 'clone_backup_extract' ) );
	}

	/**
	 * Method add_security_nonce()
	 *
	 * Create security nonce for specific actions.
	 *
	 * @param string $action Contains the action that requires security nonce.
	 */
	public function add_security_nonce( $action ) {
		if ( ! is_array( $this->security_nonces ) ) {
			$this->security_nonces = array();
		}
		if ( ! function_exists( 'wp_create_nonce' ) ) {
			include_once ABSPATH . WPINC . '/pluggable.php';
		}
		$this->security_nonces[ $action ] = wp_create_nonce( $action );
	}

	/**
	 * Method get_security_nonces()
	 *
	 * Get security nonces from the security nonces array.
	 *
	 * @return array Security nonces.
	 */
	public function get_security_nonces() {
		return $this->security_nonces;
	}

	/**
	 * Method add_action()
	 *
	 * Add actions to the 'wp_ajax_' hook and create security nonce.
	 *
	 * @param string $action   Contains action to be added to the 'wp_ajax_' hook.
	 * @param string $callback Contains a callback action.
	 */
	public function add_action( $action, $callback ) {
		add_action( 'wp_ajax_' . $action, $callback );
		$this->add_security_nonce( $action );
	}

	/**
	 * Method secure_request()
	 *
	 * Build secure request for the clone process.
	 *
	 * @param string $action    Contains the action that is being performed.
	 * @param string $query_arg Contains the query argument.
	 *
	 * @return void
	 *
	 * @uses \MainWP\Child\MainWP_Helper::is_admin()
	 * @uses \MainWP\Child\MainWP_Helper::update_option()
	 */
	public function secure_request( $action = '', $query_arg = 'security' ) {
		if ( ! MainWP_Helper::is_admin() ) {
			die( 0 );
		}

		if ( '' == $action ) {
			return;
		}

		if ( ! $this->check_security( $action, $query_arg ) ) {
			die( wp_json_encode( array( 'error' => esc_html__( 'Invalid request!', 'mainwp-child' ) ) ) );
		}

		if ( isset( $_POST['dts'] ) ) {
			$ajaxPosts = get_option( 'mainwp_ajaxposts' );
			if ( ! is_array( $ajaxPosts ) ) {
				$ajaxPosts = array();
			}

			// If already processed, just quit!
			if ( isset( $ajaxPosts[ $action ] ) && ( $ajaxPosts[ $action ] == $_POST['dts'] ) ) {
				die( wp_json_encode( array( 'error' => esc_html__( 'Double request!', 'mainwp-child' ) ) ) );
			}

			$ajaxPosts[ $action ] = isset( $_POST['dts'] ) ? sanitize_text_field( wp_unslash( $_POST['dts'] ) ) : '';
			MainWP_Helper::update_option( 'mainwp_ajaxposts', $ajaxPosts );
		}
	}

	/**
	 * Method check_security()
	 *
	 * Check the clone request security.
	 *
	 * @param string $action    Contains the action that is being performed.
	 * @param string $query_arg Contains the query argument.
	 *
	 * @return bool true|false If secure, return true, if not, return false.
	 */
	public function check_security( $action = - 1, $query_arg = 'security' ) {
		if ( - 1 == $action ) {
			return false;
		}

		$adminurl = strtolower( admin_url() );
		$referer  = strtolower( wp_get_referer() );
		$result   = isset( $_REQUEST[ $query_arg ] ) ? wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST[ $query_arg ] ) ), $action ) : false;
		if ( ! $result && ! ( - 1 == $action && 0 === strpos( $referer, $adminurl ) ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Method init()
	 *
	 * Initiate action hooks.
	 *
	 * @uses \MainWP\Child\MainWP_Clone_Page::get_class_name()
	 */
	public function init() {
		add_action( 'check_admin_referer', array( self::get_class_name(), 'permalink_changed' ) );
		if ( get_option( 'mainwp_child_clone_permalink' ) || get_option( 'mainwp_child_restore_permalink' ) ) {
			add_action( 'admin_notices', array( MainWP_Clone_Page::get_class_name(), 'permalink_admin_notice' ) );
		}
	}

	/**
	 * Method upload_mimes()
	 *
	 * Add allowed mime types and file extensions.
	 *
	 * @param array $mime_types Mime types keyed by the file extension regex corresponding to those types.
	 *
	 * @return array Array containing allowed mime types.
	 */
	public static function upload_mimes( $mime_types = array() ) {
		if ( ! isset( $mime_types['tar.bz2'] ) ) {
			$mime_types['tar.bz2'] = 'application/x-tar';
		}

		return $mime_types;
	}

	/**
	 * Request clone.
	 *
	 * @return bool|void true|void.
	 *
	 * @uses \MainWP\Child\MainWP_Connect::is_valid_auth()
	 * @uses \MainWP\Child\MainWP_Helper::get_mainwp_dir()
	 * @uses \MainWP\Child\MainWP_Helper::write()
	 * @uses \MainWP\Child\MainWP_Utility::upload_file()
	 */
	public function request_clone_funct() { // phpcs:ignore -- Current complexity is the only way to achieve desired results, pull request solutions appreciated.

		if ( ! isset( $_REQUEST['key'] ) ) {
			return;
		}
		if ( ! isset( $_REQUEST['f'] ) || ( '' === $_REQUEST['f'] ) ) {
			return;
		}
		if ( ! isset( $_REQUEST['key'] ) || ! MainWP_Connect::instance()->is_valid_auth( wp_unslash( $_REQUEST['key'] ) ) ) {
			return;
		}

		$cloneFunc = isset( $_REQUEST['cloneFunc'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['cloneFunc'] ) ) : '';

		if ( 'dl' === $cloneFunc ) {
			$f = isset( $_REQUEST['f'] ) ? wp_unslash( $_REQUEST['f'] ) : '';
			if ( ! empty( $f ) ) {
				MainWP_Utility::instance()->upload_file( wp_unslash( $_REQUEST['f'] ) );
			}
			exit;
		} elseif ( 'deleteCloneBackup' === $cloneFunc ) {
			$df = isset( $_POST['f'] ) ? sanitize_text_field( wp_unslash( $_POST['f'] ) ) : '';
			if ( empty( $df ) || stristr( $df, '..' ) ) {
				return false;
			}

			$dirs      = MainWP_Helper::get_mainwp_dir( 'backup' );
			$backupdir = $dirs[0];
			$result    = glob( $backupdir . $df );
			if ( 0 === count( $result ) ) {
				return;
			}

			unlink( $result[0] );
			MainWP_Helper::write( array( 'result' => 'ok' ) );
		} elseif ( 'createCloneBackupPoll' === $cloneFunc ) {
			$dirs        = MainWP_Helper::get_mainwp_dir( 'backup' );
			$backupdir   = $dirs[0];
			$f           = isset( $_POST['f'] ) ? wp_unslash( $_POST['f'] ) : '';
			$archiveFile = false;
			if ( ! empty( $f ) ) {
				$result = glob( $backupdir . 'backup-' . $f . '-*' );
				foreach ( $result as $file ) {
					if ( self::is_archive( $file, 'backup-' . $f . '-' ) ) {
						$archiveFile = $file;
						break;
					}
				}
			}
			if ( false === $archiveFile ) {
				return;
			}

			MainWP_Helper::write( array( 'size' => filesize( $archiveFile ) ) );
		} elseif ( 'createCloneBackup' === $cloneFunc ) {
			$this->create_clone_backup();
		}
		return true;
	}


	/**
	 * Create backup of clone.
	 *
	 * @uses \MainWP\Child\MainWP_Backup::create_full_backup()
	 * @uses \MainWP\Child\MainWP_Helper::end_session()
	 * @uses \MainWP\Child\MainWP_Helper::is_dir_empty()
	 * @uses \MainWP\Child\MainWP_Helper::get_mainwp_dir()
	 * @uses \MainWP\Child\MainWP_Helper::write()
	 */
	private function create_clone_backup() { // phpcs:ignore -- Current complexity is the only way to achieve desired results, pull request solutions appreciated.
		MainWP_Helper::end_session();
		$files = glob( WP_CONTENT_DIR . '/dbBackup*.sql' );
		foreach ( $files as $file ) {
			unlink( $file );
		}
		if ( file_exists( ABSPATH . 'clone/config.txt' ) ) {
			unlink( ABSPATH . 'clone/config.txt' );
		}
		if ( MainWP_Helper::is_dir_empty( ABSPATH . 'clone' ) ) {
			rmdir( ABSPATH . 'clone' );
		}

		$wpversion = isset( $_POST['wpversion'] ) ? sanitize_text_field( wp_unslash( $_POST['wpversion'] ) ) : '';
		global $wp_version;
		$includeCoreFiles = ( $wpversion !== $wp_version );
		$excludes         = ( isset( $_POST['exclude'] ) ? explode( ',', wp_unslash( $_POST['exclude'] ) ) : array() );
		$excludes[]       = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/uploads/mainwp';
		$uploadDir        = MainWP_Helper::get_mainwp_dir();
		$uploadDir        = $uploadDir[0];
		$excludes[]       = str_replace( ABSPATH, '', $uploadDir );
		$excludes[]       = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/object-cache.php';
		if ( version_compare( phpversion(), '5.3.0' ) >= 0 || ! ini_get( 'safe_mode' ) ) {
			set_time_limit( 6000 );
		}

		$newExcludes = array();
		foreach ( $excludes as $exclude ) {
			$newExcludes[] = rtrim( $exclude, '/' );
		}

		$method = ( ! isset( $_POST['zipmethod'] ) ? 'tar.gz' : wp_unslash( $_POST['zipmethod'] ) );
		if ( 'tar.gz' === $method && ! function_exists( 'gzopen' ) ) {
			$method = 'zip';
		}

		$file = false;
		if ( isset( $_POST['f'] ) ) {
			$file = ! empty( $_POST['f'] ) ? wp_unslash( $_POST['f'] ) : false;
		} elseif ( isset( $_POST['file'] ) ) {
			$file = ! empty( $_POST['file'] ) ? wp_unslash( $_POST['file'] ) : false;
		}

		$res = MainWP_Backup::get()->create_full_backup( $newExcludes, $file, true, $includeCoreFiles, 0, false, false, false, false, $method );
		if ( ! $res ) {
			$information['backup'] = false;
		} else {
			$information['backup'] = $res['file'];
			$information['size']   = $res['filesize'];
		}

		$plugins = array();
		$dir     = WP_CONTENT_DIR . '/plugins/';
		$fh      = opendir( $dir );
		while ( $entry = readdir( $fh ) ) {
			if ( ! is_dir( $dir . $entry ) ) {
				continue;
			}
			if ( ( '.' === $entry ) || ( '..' === $entry ) ) {
				continue;
			}
			$plugins[] = $entry;
		}
		closedir( $fh );
		$information['plugins'] = $plugins;

		$themes = array();
		$dir    = WP_CONTENT_DIR . '/themes/';
		$fh     = opendir( $dir );
		while ( $entry = readdir( $fh ) ) {
			if ( ! is_dir( $dir . $entry ) ) {
				continue;
			}
			if ( ( '.' === $entry ) || ( '..' === $entry ) ) {
				continue;
			}
			$themes[] = $entry;
		}
		closedir( $fh );
		$information['themes'] = $themes;
		MainWP_Helper::write( $information );
	}

	/**
	 * Method clone_backup_create()
	 *
	 * Create backup of template site so it can be used to clone it.
	 *
	 * @throws \Exception Error message.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::end_session()
	 * @uses \MainWP\Child\MainWP_Helper::update_option()
	 * @uses \MainWP\Child\MainWP_Utility::fetch_url()
	 */
	public function clone_backup_create() {
		try {
			$this->secure_request( 'mainwp-child_clone_backupcreate' );

			if ( ! isset( $_POST['siteId'] ) ) {
				throw new \Exception( esc_html__( 'No site given', 'mainwp-child' ) );
			}

			$siteId = isset( $_POST['siteId'] ) ? intval( wp_unslash( $_POST['siteId'] ) ) : false;

			$rand         = isset( $_POST['rand'] ) ? sanitize_text_field( wp_unslash( $_POST['rand'] ) ) : '';
			$sitesToClone = get_option( 'mainwp_child_clone_sites' );

			if ( ! is_array( $sitesToClone ) || ! isset( $sitesToClone[ $siteId ] ) ) {
				throw new \Exception( esc_html__( 'Site not found', 'mainwp-child' ) );
			}

			$siteToClone = $sitesToClone[ $siteId ];
			$url         = $siteToClone['url'];
			$key         = $siteToClone['extauth'];
			$clone_admin = $siteToClone['connect_admin'];

			MainWP_Helper::end_session();

			// Send request to the childsite!

			/**
			 * The installed version of WordPress.
			 *
			 * @global string $wp_version The installed version of WordPress.
			 */
			global $wp_version;

			$method = ( function_exists( 'gzopen' ) ? 'tar.gz' : 'zip' );
			$result = MainWP_Utility::fetch_url(
				$url,
				array(
					'cloneFunc'   => 'createCloneBackup',
					'key'         => $key,
					'f'           => $rand,
					'wpversion'   => $wp_version,
					'zipmethod'   => $method,
				)
			);

			if ( ! $result['backup'] ) {
				throw new \Exception( esc_html__( 'Could not create backupfile on child', 'mainwp-child' ) );
			}
			MainWP_Helper::update_option( 'mainwp_temp_clone_plugins', $result['plugins'] );
			MainWP_Helper::update_option( 'mainwp_temp_clone_themes', $result['themes'] );
			MainWP_Helper::update_option( 'mainwp_temp_clone_admin', $clone_admin );

			$output = array(
				'url'  => $result['backup'],
				'size' => round( $result['size'] / 1024, 0 ),
			);
		} catch ( \Exception $e ) {
			$output = array( 'error' => $e->getMessage() );
		}

		die( wp_json_encode( $output ) );
	}

	/**
	 * Method clone_backup_create_poll()
	 *
	 * Create backup poll of template site so it can be used to clone it.
	 *
	 * @throws \Exception Error message.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::end_session()
	 * @uses \MainWP\Child\MainWP_Utility::fetch_url()
	 */
	public function clone_backup_create_poll() {
		try {
			$this->secure_request( 'mainwp-child_clone_backupcreatepoll' );

			if ( ! isset( $_POST['siteId'] ) ) {
				throw new \Exception( esc_html__( 'No site given', 'mainwp-child' ) );
			}
			$siteId = isset( $_POST['siteId'] ) ? sanitize_text_field( wp_unslash( $_POST['siteId'] ) ) : '';
			$rand   = isset( $_POST['rand'] ) ? sanitize_text_field( wp_unslash( $_POST['rand'] ) ) : '';

			$sitesToClone = get_option( 'mainwp_child_clone_sites' );
			if ( ! is_array( $sitesToClone ) || ! isset( $sitesToClone[ $siteId ] ) ) {
				throw new \Exception( esc_html__( 'Site not found', 'mainwp-child' ) );
			}

			$siteToClone = $sitesToClone[ $siteId ];
			$url         = $siteToClone['url'];

			$key = $siteToClone['extauth'];

			MainWP_Helper::end_session();
			// Send request to the childsite!
			$result = MainWP_Utility::fetch_url(
				$url,
				array(
					'cloneFunc'   => 'createCloneBackupPoll',
					'key'         => $key,
					'f'           => $rand,
				)
			);

			if ( ! isset( $result['size'] ) ) {
				throw new \Exception( esc_html__( 'Invalid response', 'mainwp-child' ) );
			}

			$output = array( 'size' => round( $result['size'] / 1024, 0 ) );
		} catch ( \Exception $e ) {
			$output = array( 'error' => $e->getMessage() );
		}
		die( wp_json_encode( $output ) );
	}

	/**
	 * Method clone_backup_download()
	 *
	 * Download backup file of template site so it can be used to clone it.
	 *
	 * @return mixed Response message.
	 * @throws \Exception Error message.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::end_session()
	 * @uses \MainWP\Child\MainWP_Helper::get_mainwp_dir()
	 * @uses \MainWP\Child\MainWP_Utility::fetch_url()
	 */
	public function clone_backup_download() { // phpcs:ignore -- Current complexity is the only way to achieve desired results, pull request solutions appreciated.
		try {
			$this->secure_request( 'mainwp-child_clone_backupdownload' );

			if ( ! isset( $_POST['file'] ) ) {
				throw new \Exception( esc_html__( 'No download link given', 'mainwp-child' ) );
			}

			$file = isset( $_POST['file'] ) ? wp_unslash( $_POST['file'] ) : '';
			if ( isset( $_POST['siteId'] ) ) {
				$siteId = isset( $_POST['siteId'] ) ? intval( wp_unslash( $_POST['siteId'] ) ) : false;

				$sitesToClone = get_option( 'mainwp_child_clone_sites' );

				if ( ! is_array( $sitesToClone ) || ! isset( $sitesToClone[ $siteId ] ) ) {
					throw new \Exception( esc_html__( 'Site not found', 'mainwp-child' ) );
				}

				$siteToClone = $sitesToClone[ $siteId ];
				$url         = $siteToClone['url'];
				$key         = $siteToClone['extauth'];

				$url = trailingslashit( $url ) . '?cloneFunc=dl&key=' . rawurlencode( $key ) . '&f=' . $file;
			} else {
				$url = $file;
			}
			MainWP_Helper::end_session();
			// Send request to the childsite!
			$split     = explode( '=', $file );
			$file      = urldecode( $split[ count( $split ) - 1 ] );
			$filename  = 'download-' . basename( $file );
			$dirs      = MainWP_Helper::get_mainwp_dir( 'backup', false );
			$backupdir = $dirs[0];
			$dh        = opendir( $backupdir );
			if ( $dh ) {
				$fl = readdir( $dh );
				while ( false !== $fl ) {
					if ( '.' !== $fl && '..' !== $fl && self::is_archive( $fl, 'download-' ) ) {
						unlink( $backupdir . $fl );
					}
					$fl = readdir( $dh );
				}
				closedir( $dh );
			}

			$filename = $backupdir . $filename;

			$response = wp_remote_get(
				$url,
				array(
					'timeout'  => 300000,
					'stream'   => true,
					'filename' => $filename,
				)
			);

			if ( is_wp_error( $response ) ) {
				unlink( $filename );

				return $response;
			}

			if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				unlink( $filename );

				return new \WP_Error( 'http_404', trim( wp_remote_retrieve_response_message( $response ) ) );
			}

			$output = array( 'done' => $filename );

			// Delete backup on child.
			try {
				if ( isset( $_POST['siteId'] ) ) {
					$siteId = isset( $_POST['siteId'] ) ? intval( wp_unslash( $_POST['siteId'] ) ) : false;

					$sitesToClone = get_option( 'mainwp_child_clone_sites' );
					if ( is_array( $sitesToClone ) && isset( $sitesToClone[ $siteId ] ) ) {
						$siteToClone = $sitesToClone[ $siteId ];

						MainWP_Utility::fetch_url(
							$siteToClone['url'],
							array(
								'cloneFunc'   => 'deleteCloneBackup',
								'key'         => $siteToClone['extauth'],
								'f'           => $file,
							)
						);
					}
				}
			} catch ( \Exception $e ) {
				throw $e;
			}
		} catch ( \Exception $e ) {
			$output = array( 'error' => $e->getMessage() );
		}

		die( wp_json_encode( $output ) );
	}

	/**
	 * Method clone_backup_download_poll()
	 *
	 * Download backup file poll of template site so it can be used to clone it.
	 *
	 * @throws \Exception Error message.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::end_session()
	 * @uses \MainWP\Child\MainWP_Helper::get_mainwp_dir()
	 */
	public function clone_backup_download_poll() {
		try {
			$this->secure_request( 'mainwp-child_clone_backupdownloadpoll' );

			MainWP_Helper::end_session();

			$dirs        = MainWP_Helper::get_mainwp_dir( 'backup', false );
			$backupdir   = $dirs[0];
			$files       = glob( $backupdir . 'download-*' );
			$archiveFile = false;

			foreach ( $files as $file ) {
				if ( self::is_archive( $file, 'download-' ) ) {
					$archiveFile = $file;
					break;
				}
			}
			if ( false === $archiveFile ) {
				throw new \Exception( esc_html__( 'No download file found', 'mainwp-child' ) );
			}
			$output = array( 'size' => filesize( $archiveFile ) / 1024 );
		} catch ( \Exception $e ) {
			$output = array( 'error' => $e->getMessage() );
		}
		die( wp_json_encode( $output ) );
	}

	/**
	 * Method clone_backup_extract()
	 *
	 * Extract the backup archive to clone the site.
	 *
	 * @uses \MainWP\Child\MainWP_Clone_Install()
	 * @uses \MainWP\Child\MainWP_Helper::end_session()
	 * @uses \MainWP\Child\MainWP_Helper::update_option()
	 * @uses \MainWP\Child\MainWP_Helper::starts_with()
	 */
	public function clone_backup_extract() {
		try {
			$this->secure_request( 'mainwp-child_clone_backupextract' );

			MainWP_Helper::end_session();

			$file = false;
			if ( isset( $_POST['f'] ) ) {
				$file = ! empty( $_POST['f'] ) ? wp_unslash( $_POST['f'] ) : false;
			} elseif ( isset( $_POST['file'] ) ) {
				$file = ! empty( $_POST['file'] ) ? wp_unslash( $_POST['file'] ) : false;
			}

			$testFull     = false;
			$file         = $this->clone_backup_get_file( $file, $testFull );
			$cloneInstall = new MainWP_Clone_Install( $file );
			$cloneInstall->read_configuration_file();

			$plugins     = get_option( 'mainwp_temp_clone_plugins' );
			$themes      = get_option( 'mainwp_temp_clone_themes' );
			$clone_admin = get_option( 'mainwp_temp_clone_admin' );

			if ( $testFull ) {
				$cloneInstall->test_download();
			}
			$cloneInstall->remove_config_file();
			$cloneInstall->extract_backup();

			$pubkey       = get_option( 'mainwp_child_pubkey' );
			$uniqueId     = MainWP_Helper::get_site_unique_id();
			$uniqueId     = get_option( 'mainwp_child_uniqueId' );
			$server       = get_option( 'mainwp_child_server' );
			$nonce        = get_option( 'mainwp_child_nonce' );
			$nossl        = get_option( 'mainwp_child_nossl' );
			$nossl_key    = get_option( 'mainwp_child_nossl_key' );
			$sitesToClone = get_option( 'mainwp_child_clone_sites' );
			$username     = get_option( 'mainwp_child_connected_admin' );

			$cloneInstall->install();

			delete_option( 'mainwp_child_pubkey' );
			delete_option( 'mainwp_child_uniqueId' );
			delete_option( 'mainwp_child_server' );
			delete_option( 'mainwp_child_nonce' );
			delete_option( 'mainwp_child_nossl' );
			delete_option( 'mainwp_child_nossl_key' );
			delete_option( 'mainwp_child_clone_sites' );
			delete_option( 'mainwp_temp_clone_admin' );
			delete_option( 'mainwp_child_connected_admin' );

			MainWP_Helper::update_option( 'mainwp_child_pubkey', $pubkey, 'yes' );
			MainWP_Helper::update_option( 'mainwp_child_uniqueId', $uniqueId );
			MainWP_Helper::update_option( 'mainwp_child_server', $server );
			MainWP_Helper::update_option( 'mainwp_child_nonce', $nonce );
			MainWP_Helper::update_option( 'mainwp_child_nossl', $nossl, 'yes' );
			MainWP_Helper::update_option( 'mainwp_child_nossl_key', $nossl_key );
			MainWP_Helper::update_option( 'mainwp_child_clone_sites', $sitesToClone );
			MainWP_Helper::update_option( 'mainwp_child_just_clone_admin', $clone_admin );
			MainWP_Helper::update_option( 'mainwp_child_connected_admin', $username, 'yes' );

			if ( ! MainWP_Helper::starts_with( basename( $file ), 'download-backup-' ) ) {
				MainWP_Helper::update_option( 'mainwp_child_restore_permalink', true, 'yes' );
			} else {
				MainWP_Helper::update_option( 'mainwp_child_clone_permalink', true, 'yes' );
			}

			$cloneInstall->update_wp_config();
			$cloneInstall->clean();
			$output = $this->clone_backup_delete_files( $plugins, $themes );
		} catch ( \Exception $e ) {
			$output = array( 'error' => $e->getMessage() );
		}

		die( wp_json_encode( $output ) );
	}

	/**
	 * Method clone_backup_get_file()
	 *
	 * Get the backup file to download and clone.
	 *
	 * @param resource $file     Backup file to be downloaded.
	 * @param bool     $testFull Return true if the file exists.
	 *
	 * @return resource Return the backup file.
	 * @throws \Exception Error message.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::get_mainwp_dir()
	 */
	private function clone_backup_get_file( $file, &$testFull ) {
		if ( '' == $file ) {
			$dirs        = MainWP_Helper::get_mainwp_dir( 'backup', false );
			$backupdir   = $dirs[0];
			$files       = glob( $backupdir . 'download-*' );
			$archiveFile = false;
			foreach ( $files as $file ) {
				if ( self::is_archive( $file, 'download-' ) ) {
					$archiveFile = $file;
					break;
				}
			}
			if ( false === $archiveFile ) {
				throw new \Exception( esc_html__( 'No download file found', 'mainwp-child' ) );
			}
			$file = $archiveFile;
		} elseif ( file_exists( $file ) ) {
			$testFull = true;
		} else {
			$file = ABSPATH . $file;
			if ( ! file_exists( $file ) ) {
				throw new \Exception( esc_html__( 'Backup file not found', 'mainwp-child' ) );
			}
			$testFull = true;
		}
		return $file;
	}

	/**
	 * Method is_archive()
	 *
	 * Check if the file is archive file.
	 *
	 * @param string $file_name Contains the file name.
	 * @param string $prefix    Contains the prefix.
	 * @param string $suffix    Contains the sufix.
	 *
	 * @return bool true|false If the file is archive, return true, if not, return false.
	 */
	public static function is_archive( $file_name, $prefix = '', $suffix = '' ) {
		return preg_match( '/' . $prefix . '(.*).(zip|tar|tar.gz|tar.bz2)' . $suffix . '$/', $file_name );
	}

	/**
	 * Method clone_backup_delete_files()
	 *
	 * Delete unneeded files (plugins and themes).
	 *
	 * @param array $plugins Array containig plugins to be kept.
	 * @param array $themes  Array containig themes to be kept.
	 *
	 * @return array Array containing output feedback.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::delete_dir()
	 */
	private function clone_backup_delete_files( $plugins, $themes ) {
		if ( false !== $plugins ) {
			$out = array();
			if ( is_array( $plugins ) ) {
				$dir = WP_CONTENT_DIR . '/plugins/';
				$fh  = opendir( $dir );
				while ( $entry = readdir( $fh ) ) {
					if ( ! is_dir( $dir . $entry ) ) {
						continue;
					}
					if ( ( '.' === $entry ) || ( '..' === $entry ) ) {
						continue;
					}
					if ( ! in_array( $entry, $plugins ) ) {
						MainWP_Helper::delete_dir( $dir . $entry );
					}
				}
				closedir( $fh );
			}
			delete_option( 'mainwp_temp_clone_plugins' );
		}
		if ( false !== $themes ) {
			$out = array();
			if ( is_array( $themes ) ) {
				$dir = WP_CONTENT_DIR . '/themes/';
				$fh  = opendir( $dir );
				while ( $entry = readdir( $fh ) ) {
					if ( ! is_dir( $dir . $entry ) ) {
						continue;
					}
					if ( ( '.' === $entry ) || ( '..' === $entry ) ) {
						continue;
					}
					if ( ! in_array( $entry, $themes ) ) {
						MainWP_Helper::delete_dir( $dir . $entry );
					}
				}
				closedir( $fh );
			}
			delete_option( 'mainwp_temp_clone_themes' );
		}
		$output = array( 'result' => 'ok' );
		wp_logout();
		wp_set_current_user( 0 );
		return $output;
	}

	/**
	 * Method permalink_changed()
	 *
	 * Check if the permalinks settings are re-saved.
	 *
	 * @param string $action Contains performed action.
	 */
	public static function permalink_changed( $action ) {
		if ( 'update-permalink' === $action ) {
			if ( isset( $_POST['permalink_structure'] ) || isset( $_POST['category_base'] ) || isset( $_POST['tag_base'] ) ) {
				delete_option( 'mainwp_child_clone_permalink' );
				delete_option( 'mainwp_child_restore_permalink' );
			}
		}
	}

}

