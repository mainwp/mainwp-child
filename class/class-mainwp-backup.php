<?php
/**
 * MainWP Backup
 *
 * This file handles all Child Site backup functions.
 */
namespace MainWP\Child;

//phpcs:disable WordPress.WP.AlternativeFunctions, Generic.Metrics.CyclomaticComplexity -- to custom file's functions, complex functions/features.

/**
 * Class MainWP_Backup
 *
 * @package MainWP\Child
 */
class MainWP_Backup {

	/** @var $excludeZip Whether to exclude zip archives. */
	protected $excludeZip;

	/** @var $zip Container for zip file. */
	protected $zip;

	/** @var Archive file count. */
	protected $zipArchiveFileCount;

	/** @var Archive size. */
	protected $zipArchiveSizeCount;

	/** @var Archive filename. */
	protected $zipArchiveFileName;

	/** @var Archive file descriptors. */
	protected $file_descriptors;

	/** @var Whether to load file before zip. */
	protected $loadFilesBeforeZip;

	/** @var $timeout Hold the current timeout length. */
	protected $timeout;

	/** @var Last time a backup has been run. */
	protected $lastRun;

	/** @var int $gcCnt. Doesn't seem to be used anywhere. */
	protected $gcCnt = 0;

	/** @var Archive test response. */
	protected $testContent;

	/** @var Tar_Archiver Instance of Tar_Archiver. */
	protected $archiver = null;

	/**
	 * @static
	 * @var null Holds the Public static instance of MainWP_Backup.
	 */
	protected static $instance = null;

	/**
	 * Create a public static instance of MainWP_Backup.
	 *
	 * @return MainWP_Backup|null
	 */
	public static function get() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Create full backup.
	 *
	 * @used-by MainWP_Backup::backup_full()
	 *
	 * @param $excludes
	 * @param string   $filePrefix
	 * @param bool     $addConfig
	 * @param bool     $includeCoreFiles
	 * @param int      $file_descriptors
	 * @param bool     $fileSuffix
	 * @param bool     $excludezip
	 * @param bool     $excludenonwp
	 * @param bool     $loadFilesBeforeZip
	 * @param string   $ext
	 * @param bool     $pid
	 * @param bool     $append
	 *
	 * @return array|bool
	 */
	public function create_full_backup(
		$excludes,
		$filePrefix = '',
		$addConfig = false,
		$includeCoreFiles = false,
		$file_descriptors = 0,
		$fileSuffix = false,
		$excludezip = false,
		$excludenonwp = false,
		$loadFilesBeforeZip = true,
		$ext = 'zip',
		$pid = false,
		$append = false ) {

		$this->file_descriptors   = $file_descriptors;
		$this->loadFilesBeforeZip = $loadFilesBeforeZip;

		$dirs      = MainWP_Helper::get_mainwp_dir( 'backup' );
		$backupdir = $dirs[0];
		if ( ! defined( 'PCLZIP_TEMPORARY_DIR' ) ) {
			define( 'PCLZIP_TEMPORARY_DIR', $backupdir );
		}

		if ( false !== $pid ) {
			$pid = trailingslashit( $backupdir ) . 'backup-' . $pid . '.pid';
		}

		// Verify if another backup is running, if so, return an error.
		$files = glob( $backupdir . '*.pid' );
		foreach ( $files as $file ) {
			if ( basename( $file ) == basename( $pid ) ) {
				continue;
			}

			if ( ( time() - filemtime( $file ) ) < 160 ) {
				MainWP_Helper::error( __( 'Another backup process is running. Please, try again later.', 'mainwp-child' ) );
			}
		}

		$timestamp = time();
		if ( '' !== $filePrefix ) {
			$filePrefix .= '-';
		}

		if ( 'zip' == $ext ) {
			$this->archiver = null;
			$ext            = '.zip';
		} else {
			$this->archiver = new Tar_Archiver( $this, $ext, $pid );
			$ext            = $this->archiver->get_extension();
		}

		if ( ( false !== $fileSuffix ) && ! empty( $fileSuffix ) ) {
			// Append already contains extension!
			$file = $fileSuffix . ( true === $append ? '' : $ext );
		} else {
			$file = 'backup-' . $filePrefix . $timestamp . $ext;
		}
		$filepath = $backupdir . $file;
		$fileurl  = $file;

		if ( ! $addConfig ) {
			if ( ! in_array( str_replace( ABSPATH, '', WP_CONTENT_DIR ), $excludes, true ) && ! in_array( 'wp-admin', $excludes, true ) && ! in_array( WPINC, $excludes, true ) ) {
				$addConfig        = true;
				$includeCoreFiles = true;
			}
		}

		$this->timeout = 20 * 60 * 60;
		$mem           = '512M';
		MainWP_Helper::set_limit( $this->timeout, $mem );

		if ( null !== $this->archiver ) {
			$success = $this->archiver->create_full_backup( $filepath, $excludes, $addConfig, $includeCoreFiles, $excludezip, $excludenonwp, $append );
		} elseif ( $this->check_zip_support() ) {
			$success = $this->create_zip_full_backup( $filepath, $excludes, $addConfig, $includeCoreFiles, $excludezip, $excludenonwp );
		} elseif ( $this->check_zip_console() ) {
			$success = $this->create_zip_console_full_backup( $filepath, $excludes, $addConfig, $includeCoreFiles, $excludezip, $excludenonwp );
		} else {
			$success = $this->create_zip_pcl_full_backup2( $filepath, $excludes, $addConfig, $includeCoreFiles, $excludezip, $excludenonwp );
		}

		return ( $success ) ? array(
			'timestamp' => $timestamp,
			'file'      => $fileurl,
			'filesize'  => filesize( $filepath ),
		) : false;
	}

	/**
	 * Check whether the file is an archive or not & create
	 * a json_encoded, serialized, base64_encoded string.
	 *
	 * @return string $output json_encoded, serialized, base64_encoded string.
	 */
	public function backup_poll() {
		$fileNameUID = ( isset( $_POST['fileNameUID'] ) ? $_POST['fileNameUID'] : '' );
		$fileName    = ( isset( $_POST['fileName'] ) ? $_POST['fileName'] : '' );

		if ( 'full' === $_POST['type'] ) {
			if ( '' !== $fileName ) {
				$backupFile = $fileName;
			} else {
				$backupFile = 'backup-' . $fileNameUID . '-';
			}

			$dirs      = MainWP_Helper::get_mainwp_dir( 'backup' );
			$backupdir = $dirs[0];
			$result    = glob( $backupdir . $backupFile . '*' );

			// Check if archive, set $archiveFile = $file & break.
			$archiveFile = false;
			foreach ( $result as $file ) {
				if ( MainWP_Clone::is_archive( $file, $backupFile, '(.*)' ) ) {
					$archiveFile = $file;
					break;
				}
			}

			// When not an archive.
			if ( false === $archiveFile ) {
				MainWP_Helper::write( array() );
			}

			// When archive found.
			MainWP_Helper::write( array( 'size' => filesize( $archiveFile ) ) );
		} else {

			// When not an archive.
			$backupFile = 'dbBackup-' . $fileNameUID . '-*.sql';

			$dirs      = MainWP_Helper::get_mainwp_dir( 'backup' );
			$backupdir = $dirs[0];
			$result    = glob( $backupdir . $backupFile . '*' );
			if ( 0 === count( $result ) ) {
				MainWP_Helper::write( array() );
			}

			$size = 0;
			foreach ( $result as $f ) {
				$size += filesize( $f );
			}
			MainWP_Helper::write( array( 'size' => $size ) );
			exit();
		}
	}

	/**
	 * Check if backup already exists or is in the process of backing up.
	 */
	public function backup_checkpid() {
		$pid = $_POST['pid'];

		$dirs      = MainWP_Helper::get_mainwp_dir( 'backup' );
		$backupdir = $dirs[0];

		$information = array();

		/** @var $wp_filesystem WP_Filesystem_Base */
		global $wp_filesystem;

		MainWP_Helper::get_wp_filesystem();

		$pidFile  = trailingslashit( $backupdir ) . 'backup-' . $pid . '.pid';
		$doneFile = trailingslashit( $backupdir ) . 'backup-' . $pid . '.done';
		if ( $wp_filesystem->is_file( $pidFile ) ) {
			$time = $wp_filesystem->mtime( $pidFile );

			$minutes = date( 'i', time() ); // phpcs:ignore -- local time.
			$seconds = date( 's', time() ); // phpcs:ignore -- local time.

			$file_minutes = date( 'i', $time ); // phpcs:ignore -- local time.
			$file_seconds = date( 's', $time ); // phpcs:ignore -- local time.

			$minuteDiff = $minutes - $file_minutes;
			if ( 59 === $minuteDiff ) {
				$minuteDiff = 1;
			}
			$secondsdiff = ( $minuteDiff * 60 ) + $seconds - $file_seconds;

			$file                = $wp_filesystem->get_contents( $pidFile );
			$information['file'] = basename( $file );
			if ( $secondsdiff < 80 ) {
				$information['status'] = 'busy';
			} else {
				$information['status'] = 'stalled';
			}
		} elseif ( $wp_filesystem->is_file( $doneFile ) ) {
			$file                  = $wp_filesystem->get_contents( $doneFile );
			$information['status'] = 'done';
			$information['file']   = basename( $file );
			$information['size']   = filesize( $file );
		} else {
			$information['status'] = 'invalid';
		}

		MainWP_Helper::write( $information );
	}

	/**
	 * Perform a backup.
	 *
	 * @param bool $pWrite Whether or not to execute MainWP_Helper::write(), Default: true.
	 *
	 * @return array $information Array of information on the backup containing the type of backup performed,
	 *  full, or DB & whether or not it was successful.
	 */
	public function backup( $pWrite = true ) {

		$timeout = 20 * 60 * 60;
		MainWP_Helper::set_limit( $timeout );

		MainWP_Helper::end_session();

		// Cleanup pid files!
		$dirs      = MainWP_Helper::get_mainwp_dir( 'backup' );
		$backupdir = trailingslashit( $dirs[0] );

		/** @var $wp_filesystem WP_Filesystem_Base */
		global $wp_filesystem;

		MainWP_Helper::get_wp_filesystem();

		$files = glob( $backupdir . '*' );
		foreach ( $files as $file ) {
			if ( MainWP_Helper::ends_with( $file, '/index.php' ) | MainWP_Helper::ends_with( $file, '/.htaccess' ) ) {
				continue;
			}

			if ( ( time() - filemtime( $file ) ) > ( 60 * 60 * 3 ) ) {
				unlink( $file );
			}
		}

		$fileName = ( isset( $_POST['fileUID'] ) ? $_POST['fileUID'] : '' );
		if ( 'full' === $_POST['type'] ) {

			$res = $this->backup_full( $fileName );

			if ( ! $res ) {
				$information['full'] = false;
			} else {
				$information['full'] = $res['file'];
				$information['size'] = $res['filesize'];
			}
			$information['db'] = false;
		} elseif ( 'db' == $_POST['type'] ) {
			$ext = 'zip';
			if ( isset( $_POST['ext'] ) ) {
				$ext = $_POST['ext'];
			}

			$res = $this->backup_db( $fileName, $ext );
			if ( ! $res ) {
				$information['db'] = false;
			} else {
				$information['db']   = $res['file'];
				$information['size'] = $res['filesize'];
			}
			$information['full'] = false;
		} else {
			$information['full'] = false;
			$information['db']   = false;
		}

		if ( $pWrite ) {
			MainWP_Helper::write( $information );
		}

		return $information;
	}

	/**
	 * Perform a full backup.
	 *
	 * @param $fileName Backup file name.
	 * @return array|bool $success Returns an array containing the Backup location & file size. Return FALSE on failure.
	 */
	public function backup_full( $fileName ) {
		$excludes   = ( isset( $_POST['exclude'] ) ? explode( ',', $_POST['exclude'] ) : array() );
		$excludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/uploads/mainwp';
		$uploadDir  = MainWP_Helper::get_mainwp_dir();
		$uploadDir  = $uploadDir[0];
		$excludes[] = str_replace( ABSPATH, '', $uploadDir );
		$excludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/object-cache.php';

		if ( function_exists( 'posix_uname' ) ) {
			$uname = posix_uname();
			if ( is_array( $uname ) && isset( $uname['nodename'] ) ) {
				if ( stristr( $uname['nodename'], 'hostgator' ) ) {
					if ( ! isset( $_POST['file_descriptors'] ) || '0' == $_POST['file_descriptors'] || $_POST['file_descriptors'] > 1000 ) {
						$_POST['file_descriptors'] = 1000;
					}
					$_POST['file_descriptors_auto'] = 0;
					$_POST['loadFilesBeforeZip']    = false;
				}
			}
		}

		$file_descriptors      = ( isset( $_POST['file_descriptors'] ) ? $_POST['file_descriptors'] : 0 );
		$file_descriptors_auto = ( isset( $_POST['file_descriptors_auto'] ) ? $_POST['file_descriptors_auto'] : 0 );
		if ( 1 === (int) $file_descriptors_auto ) {
			if ( function_exists( 'posix_getrlimit' ) ) {
				$result = posix_getrlimit();
				if ( isset( $result['soft openfiles'] ) ) {
					$file_descriptors = $result['soft openfiles'];
				}
			}
		}

		$loadFilesBeforeZip = ( isset( $_POST['loadFilesBeforeZip'] ) ? $_POST['loadFilesBeforeZip'] : true );

		$newExcludes = array();
		foreach ( $excludes as $exclude ) {
			$newExcludes[] = rtrim( $exclude, '/' );
		}

		$excludebackup = ( isset( $_POST['excludebackup'] ) && '1' == $_POST['excludebackup'] );
		$excludecache  = ( isset( $_POST['excludecache'] ) && '1' == $_POST['excludecache'] );
		$excludezip    = ( isset( $_POST['excludezip'] ) && '1' == $_POST['excludezip'] );
		$excludenonwp  = ( isset( $_POST['excludenonwp'] ) && '1' == $_POST['excludenonwp'] );

		if ( $excludebackup ) {
			$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/uploads/backupbuddy_backups';
			$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/uploads/backupbuddy_temp';
			$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/uploads/pb_backupbuddy';
			$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/managewp';
			$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/infinitewp';
			$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/backups';
			$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/backups';
			$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/uploads/backwpup*';
			$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/plugins/wp-complete-backup/storage';
			$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/backups';
			$newExcludes[] = '/administrator/backups';
		}

		if ( $excludecache ) {
			$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/w3tc-cache';
			$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/w3tc';
			$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/cache/config';
			$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/cache/minify';
			$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/cache/page_enhanced';
			$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/cache/tmp';
			$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/cache/supercache';
			$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/cache/quick-cache';
			$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/hyper-cache/cache';
			$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/cache/all';
			$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/cache/wp-rocket';
		}

		$file = false;
		if ( isset( $_POST['f'] ) ) {
			$file = $_POST['f'];
		} elseif ( isset( $_POST['file'] ) ) {
			$file = $_POST['file'];
		}

		$ext = 'zip';
		if ( isset( $_POST['ext'] ) ) {
			$ext = $_POST['ext'];
		}

		$pid = false;
		if ( isset( $_POST['pid'] ) ) {
			$pid = $_POST['pid'];
		}
		$append = ( isset( $_POST['append'] ) && ( '1' == $_POST['append'] ) );
		return $this->create_full_backup( $newExcludes, $fileName, true, true, $file_descriptors, $file, $excludezip, $excludenonwp, $loadFilesBeforeZip, $ext, $pid, $append );
	}

	/**
	 * Perform DB backup.
	 *
	 * @param string $fileName Backup file name.
	 * @param string $ext Backup extension.
	 * @return array|bool $success Returns an array containing the Backup location & file size. Return FALSE on failure.
	 */
	public function backup_db( $fileName = '', $ext = 'zip' ) {
		$dirs      = MainWP_Helper::get_mainwp_dir( 'backup' );
		$dir       = $dirs[0];
		$timestamp = time();

		if ( '' !== $fileName ) {
			$fileName .= '-';
		}

		$filepath_prefix = $dir . 'dbBackup-' . $fileName . $timestamp;

		$dh = opendir( $dir );

		if ( $dh ) {
			while ( ( $file = readdir( $dh ) ) !== false ) {
				if ( '.' !== $file && '..' !== $file && ( preg_match( '/dbBackup-(.*).sql(\.zip|\.tar|\.tar\.gz|\.tar\.bz2|\.tmp)?$/', $file ) ) ) {
					unlink( $dir . $file );
				}
			}
			closedir( $dh );
		}

		$result = $this->create_backup_db( $filepath_prefix, $ext );

		MainWP_Helper::update_option( 'mainwp_child_last_db_backup_size', filesize( $result['filepath'] ) );

		return ( ! $result ) ? false : array(
			'timestamp' => $timestamp,
			'file'      => basename( $result['filepath'] ),
			'filesize'  => filesize( $result['filepath'] ),
		);
	}

	/**
	 * Create a zip file.
	 *
	 * @param array  $files Files to zip.
	 * @param string $archive Type of archive to create.
	 * @return bool Return FALSE on failure.
	 */
	public function zip_file( $files, $archive ) {
		$this->timeout = 20 * 60 * 60;
		$mem           = '512M';
		MainWP_Helper::set_limit( $this->timeout, $mem );

		if ( ! is_array( $files ) ) {
			$files = array( $files );
		}

		if ( null !== $this->archiver ) {
			$success = $this->archiver->zip_file( $files, $archive );
		} elseif ( $this->check_zip_support() ) {
			$success = $this->m_zip_file( $files, $archive );
		} elseif ( $this->check_zip_console() ) {
			$success = $this->m_zip_file_console( $files, $archive );
		} else {
			$success = $this->m_zip_file_pcl( $files, $archive );
		}

		return $success;
	}

	/**
	 * Create m_zip_file.
	 *
	 * @param array  $files Files to zip.
	 * @param string $archive Type of archive to create.
	 * @return bool Return FALSE on failure.
	 */
	public function m_zip_file( $files, $archive ) {
		$this->zip                 = new \ZipArchive();
		$this->zipArchiveFileCount = 0;
		$this->zipArchiveSizeCount = 0;

		$zipRes = $this->zip->open( $archive, \ZipArchive::CREATE );
		if ( $zipRes ) {
			foreach ( $files as $file ) {
				$this->add_fileToZip( $file, basename( $file ) );
			}

			return $this->zip->close();
		}

		return false;
	}

	/**
	 * Method m_zip_file_console().
	 *
	 * @param array  $files Files to zip.
	 * @param string $archive Type of archive to create.
	 * @return bool Return FALSE on failure.
	 */
	public function m_zip_file_console( $files, $archive ) {
		return false;
	}

	/**
	 * Method m_zip_file_pcl().
	 *
	 * @param array  $files Files to zip.
	 * @param string $archive Type of archive to create.
	 * @return array $rslt Return array of results.
	 */
	public function m_zip_file_pcl( $files, $archive ) {
		// Zip this backup folder.
		require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
		$this->zip = new \PclZip( $archive );

		$error = false;
		foreach ( $files as $file ) {
			$rslt = $this->zip->add( $file, PCLZIP_OPT_REMOVE_PATH, dirname( $file ) );
			if ( 0 === $rslt ) {
				$error = true;
			}
		}

		return ! $error;
	}

	/**
	 * Check for default PHP zip support.
	 *
	 * @return bool Returns TRUE if class_name is a defined class, FALSE otherwise.
	 */
	public function check_zip_support() {
		return class_exists( '\ZipArchive' );
	}

	/**
	 * Check if we could run zip on console
	 *
	 * @return bool Return FALSE.
	 */
	public function check_zip_console() {
		return false;
	}

	/**
	 * Create full backup using default PHP zip library.
	 *
	 * @param string           $filepath File path to create.
	 * @param $excludes
	 * @param $addConfig
	 * @param $includeCoreFiles
	 * @param $excludezip
	 * @param $excludenonwp
	 * @return bool Return TRUE on success & FALSE on failure.
	 */
	public function create_zip_full_backup(
		$filepath,
		$excludes,
		$addConfig,
		$includeCoreFiles,
		$excludezip,
		$excludenonwp ) {

		$this->excludeZip          = $excludezip;
		$this->zip                 = new \ZipArchive();
		$this->zipArchiveFileCount = 0;
		$this->zipArchiveSizeCount = 0;
		$this->zipArchiveFileName  = $filepath;
		$zipRes                    = $this->zip->open( $filepath, \ZipArchive::CREATE );
		if ( $zipRes ) {
			$nodes = glob( ABSPATH . '*' );
			if ( ! $includeCoreFiles ) {
				$this->include_core_files( $nodes );
			}

			$db_files = $this->create_backup_db( dirname( $filepath ) . DIRECTORY_SEPARATOR . 'dbBackup' );
			foreach ( $db_files as $db_file ) {
				$this->add_file_to_zipp( $db_file, basename( WP_CONTENT_DIR ) . '/' . basename( $db_file ) );
			}

			if ( file_exists( ABSPATH . '.htaccess' ) ) {
				$this->add_file_to_zipp( ABSPATH . '.htaccess', 'mainwp-htaccess' );
			}

			foreach ( $nodes as $node ) {
				if ( $excludenonwp && is_dir( $node ) ) {
					if ( ! MainWP_Helper::starts_with( $node, WP_CONTENT_DIR ) && ! MainWP_Helper::starts_with( $node, ABSPATH . 'wp-admin' ) && ! MainWP_Helper::starts_with( $node, ABSPATH . WPINC ) ) {
						continue;
					}
				}

				if ( ! MainWP_Helper::in_excludes( $excludes, str_replace( ABSPATH, '', $node ) ) ) {
					if ( is_dir( $node ) ) {
						$this->zip_add_dir( $node, $excludes );
					} elseif ( is_file( $node ) ) {
						$this->add_file_to_zipp( $node, str_replace( ABSPATH, '', $node ) );
					}
				}
			}

			if ( $addConfig ) {
				$this->add_config();
			}

			$return = $this->zip->close();
			foreach ( $db_files as $db_file ) {
				unlink( $db_file );
			}

			return true;
		}

		return false;
	}

	/**
	 * Include core files in backup.
	 *
	 * @param array $nodes Array of files.
	 */
	private function include_core_files( &$nodes ) {
		$coreFiles = array(
			'favicon.ico',
			'index.php',
			'license.txt',
			'readme.html',
			'wp-activate.php',
			'wp-app.php',
			'wp-blog-header.php',
			'wp-comments-post.php',
			'wp-config.php',
			'wp-config-sample.php',
			'wp-cron.php',
			'wp-links-opml.php',
			'wp-load.php',
			'wp-login.php',
			'wp-mail.php',
			'wp-pass.php',
			'wp-register.php',
			'wp-settings.php',
			'wp-signup.php',
			'wp-trackback.php',
			'xmlrpc.php',
		);
		foreach ( $nodes as $key => $node ) {
			if ( MainWP_Helper::starts_with( $node, ABSPATH . WPINC ) ) {
				unset( $nodes[ $key ] );
			} elseif ( MainWP_Helper::starts_with( $node, ABSPATH . basename( admin_url( '' ) ) ) ) {
				unset( $nodes[ $key ] );
			} else {
				foreach ( $coreFiles as $coreFile ) {
					if ( ABSPATH . $coreFile === $node ) {
						unset( $nodes[ $key ] );
					}
				}
			}
		}
		unset( $coreFiles );
	}

	/**
	 * Add config file to backup.
	 */
	public function add_config() {

		/** @var $wpdb wpdb */
		global $wpdb;

		$plugins = array();
		$dir     = WP_CONTENT_DIR . '/plugins/';
		$fh      = opendir( $dir );
		while ( $entry = readdir( $fh ) ) {
			if ( ! is_dir( $dir . $entry ) ) {
				continue;
			}
			if ( ( '.' == $entry ) || ( '..' == $entry ) ) {
				continue;
			}
			$plugins[] = $entry;
		}
		closedir( $fh );

		$themes = array();
		$dir    = WP_CONTENT_DIR . '/themes/';
		$fh     = opendir( $dir );
		while ( $entry = readdir( $fh ) ) {
			if ( ! is_dir( $dir . $entry ) ) {
				continue;
			}
			if ( ( '.' == $entry ) || ( '..' == $entry ) ) {
				continue;
			}
			$themes[] = $entry;
		}
		closedir( $fh );

		if ( defined( 'MAINWP_CHILD_DEBUG' ) && MAINWP_CHILD_DEBUG ) {
			$string = wp_json_encode(
				array(
					'siteurl' => get_option( 'siteurl' ),
					'home'    => get_option( 'home' ),
					'abspath' => ABSPATH,
					'prefix'  => $wpdb->prefix,
					'lang'    => defined( 'WPLANG' ) ? WPLANG : '',
					'plugins' => $plugins,
					'themes'  => $themes,
				)
			);
		} else {
			$string = base64_encode( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- safe.
				serialize( // phpcs:ignore -- safe
					array(
						'siteurl' => get_option( 'siteurl' ),
						'home'    => get_option( 'home' ),
						'abspath' => ABSPATH,
						'prefix'  => $wpdb->prefix,
						'lang'    => defined( 'WPLANG' ) ? WPLANG : '',
						'plugins' => $plugins,
						'themes'  => $themes,
					)
				)
			);
		}

		$this->add_file_from_string_to_zip( 'clone/config.txt', $string );
	}

	/**
	 * Copy directory.
	 *
	 * @param array                 $nodes
	 * @param array                 $excludes Files & directories to exclude.
	 * @param string                $backupfolder Backup folder.
	 * @param bool                  $excludenonwp Whether or not to exclude any wp core files.
	 * @param $root Unused paremeter.
	 */
	public function copy_dir( $nodes, $excludes, $backupfolder, $excludenonwp, $root ) {
		if ( ! is_array( $nodes ) ) {
			return;
		}

		foreach ( $nodes as $node ) {
			if ( $excludenonwp && is_dir( $node ) ) {
				if ( ! MainWP_Helper::starts_with( $node, WP_CONTENT_DIR ) && ! MainWP_Helper::starts_with( $node, ABSPATH . 'wp-admin' ) && ! MainWP_Helper::starts_with( $node, ABSPATH . WPINC ) ) {
					continue;
				}
			}

			if ( ! MainWP_Helper::in_excludes( $excludes, str_replace( ABSPATH, '', $node ) ) ) {
				if ( is_dir( $node ) ) {
					if ( ! file_exists( str_replace( ABSPATH, $backupfolder, $node ) ) ) {
						mkdir( str_replace( ABSPATH, $backupfolder, $node ) ); // phpcs:ignore
					}

					$newnodes = glob( $node . DIRECTORY_SEPARATOR . '*' );
					$this->copy_dir( $newnodes, $excludes, $backupfolder, $excludenonwp, false );
					unset( $newnodes );
				} elseif ( is_file( $node ) ) {
					if ( $this->excludeZip && MainWP_Helper::ends_with( $node, '.zip' ) ) {
						continue;
					}

					copy( $node, str_replace( ABSPATH, $backupfolder, $node ) ); // phpcs:ignore
				}
			}
		}
	}

	/**
	 * Create PCL zip full backup 2.
	 *
	 * @param string $filepath Path to file.
	 * @param array  $excludes Files & directories to exclude.
	 * @param bool   $addConfig Whether to add config.
	 * @param bool   $includeCoreFiles Whether to include core files.
	 * @param bool   $excludezip Whether to exclude zip archives.
	 * @param bool   $excludenonwp Whether or not to exclude any wp core files.
	 *
	 * @return bool Return TRUE on success.
	 */
	public function create_zip_pcl_full_backup2( $filepath, $excludes, $addConfig, $includeCoreFiles, $excludezip, $excludenonwp ) {
		// Create backup folder.
		$backupFolder = dirname( $filepath ) . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR;

		mkdir( $backupFolder ); // phpcs:ignore

		// Create DB backup.
		$db_files = $this->create_backup_db( $backupFolder . 'dbBackup' );

		// Copy installation to backup folder.
		$nodes = glob( ABSPATH . '*' );
		if ( ! $includeCoreFiles ) {
			$coreFiles = array(
				'favicon.ico',
				'index.php',
				'license.txt',
				'readme.html',
				'wp-activate.php',
				'wp-app.php',
				'wp-blog-header.php',
				'wp-comments-post.php',
				'wp-config.php',
				'wp-config-sample.php',
				'wp-cron.php',
				'wp-links-opml.php',
				'wp-load.php',
				'wp-login.php',
				'wp-mail.php',
				'wp-pass.php',
				'wp-register.php',
				'wp-settings.php',
				'wp-signup.php',
				'wp-trackback.php',
				'xmlrpc.php',
			);
			foreach ( $nodes as $key => $node ) {
				if ( MainWP_Helper::starts_with( $node, ABSPATH . WPINC ) ) {
					unset( $nodes[ $key ] );
				} elseif ( MainWP_Helper::starts_with( $node, ABSPATH . basename( admin_url( '' ) ) ) ) {
					unset( $nodes[ $key ] );
				} else {
					foreach ( $coreFiles as $coreFile ) {
						if ( ABSPATH . $coreFile == $node ) {
							unset( $nodes[ $key ] );
						}
					}
				}
			}
			unset( $coreFiles );
		}
		$this->copy_dir( $nodes, $excludes, $backupFolder, $excludenonwp, true );

		foreach ( $db_files as $db_file ) {
			copy( $db_file, $backupFolder . basename( WP_CONTENT_DIR ) . '/' . basename( $db_file ) ); // phpcs:ignore
			unlink( $db_file ); // phpcs:ignore
		}

		unset( $nodes );

		// Zip this backup folder.
		require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
		$this->zip = new \PclZip( $filepath );
		$this->zip->create( $backupFolder, PCLZIP_OPT_REMOVE_PATH, $backupFolder );
		if ( $addConfig ) {
			global $wpdb;

			if ( defined( 'MAINWP_CHILD_DEBUG' ) && MAINWP_CHILD_DEBUG ) {
				$string = wp_json_encode(
					array(
						'siteurl' => get_option( 'siteurl' ),
						'home'    => get_option( 'home' ),
						'abspath' => ABSPATH,
						'prefix'  => $wpdb->prefix,
						'lang'    => WPLANG,
					)
				);
			} else {
				$string = base64_encode( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- safe.
					serialize( // phpcs:ignore -- safe
						array(
							'siteurl' => get_option( 'siteurl' ),
							'home'    => get_option( 'home' ),
							'abspath' => ABSPATH,
							'prefix'  => $wpdb->prefix,
							'lang'    => WPLANG,
						)
					)
				);
			}

			$this->add_file_from_string_to_pcl_zip( 'clone/config.txt', $string, $filepath );
		}
		// Remove backup folder.
		MainWP_Helper::delete_dir( $backupFolder );

		return true;
	}

	/**
	 * Recursively add directory to default PHP zip library.
	 *
	 * @param $path Path to directory.
	 * @param $excludes Files or directories to exclude.
	 */
	public function zip_add_dir( $path, $excludes ) {
		$this->zip->add_empty_dir( str_replace( ABSPATH, '', $path ) );

		if ( file_exists( rtrim( $path, '/' ) . '/.htaccess' ) ) {
			$this->add_file_to_zipp( rtrim( $path, '/' ) . '/.htaccess', rtrim( str_replace( ABSPATH, '', $path ), '/' ) . '/mainwp-htaccess' );
		}

		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path ), RecursiveIteratorIterator::SELF_FIRST );

		foreach ( $iterator as $path ) {
			$name = $path->__toString();
			if ( ( '.' === basename( $name ) ) || ( '..' === basename( $name ) ) ) {
				continue;
			}

			if ( ! MainWP_Helper::in_excludes( $excludes, str_replace( ABSPATH, '', $name ) ) ) {
				if ( $path->isDir() ) {
					$this->zip_add_dir( $name, $excludes );
				} else {
					$this->add_file_to_zipp( $name, str_replace( ABSPATH, '', $name ) );
				}
			}
			$name = null;
			unset( $name );
		}

		$iterator = null;
		unset( $iterator );
	}

	/**
	 * Add file from a string to zip file.
	 *
	 * @param $file File to add to zip.
	 * @param $string String to add.
	 * @return bool true|false.
	 */
	public function add_file_from_string_to_zip( $file, $string ) {
		return $this->zip->addFromString( $file, $string );
	}

	/**
	 * Add file from a string to pclzip file.
	 *
	 * @param $file File to add to zip.
	 * @param $string String to add.
	 * @param $filepath Path to file.
	 *
	 * @return bool true|false.
	 */
	public function add_file_from_string_to_pcl_zip( $file, $string, $filepath ) {
		$file        = preg_replace( '/(?:\.|\/)*(.*)/', '$1', $file );
		$localpath   = dirname( $file );
		$tmpfilename = dirname( $filepath ) . '/' . basename( $file );
		if ( false !== file_put_contents( $tmpfilename, $string ) ) {
			$this->zip->delete( PCLZIP_OPT_BY_NAME, $file );
			$add = $this->zip->add(
				$tmpfilename,
				PCLZIP_OPT_REMOVE_PATH,
				dirname( $filepath ),
				PCLZIP_OPT_ADD_PATH,
				$localpath
			);
			unlink( $tmpfilename );
			if ( ! empty( $add ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Add file to zip.
	 *
	 * @param $path Path to zip file.
	 * @param $zipEntryName File to add to zip.
	 * @return bool True|false.
	 */
	public function add_file_to_zipp( $path, $zipEntryName ) {
		if ( time() - $this->lastRun > 20 ) {
			set_time_limit( $this->timeout ); // phpcs:ignore
			$this->lastRun = time();
		}

		if ( $this->excludeZip && MainWP_Helper::ends_with( $path, '.zip' ) ) {
			return false;
		}

		$this->zipArchiveSizeCount += filesize( $path );
		$this->gcCnt ++;

		if ( ! $this->loadFilesBeforeZip || ( filesize( $path ) > 5 * 1024 * 1024 ) ) {
			$this->zipArchiveFileCount ++;
			$added = $this->zip->add_file( $path, $zipEntryName );
		} else {
			$this->zipArchiveFileCount ++;

			$this->testContent = file_get_contents( $path );
			if ( false === $this->testContent ) {
				return false;
			}
			$added = $this->zip->addFromString( $zipEntryName, $this->testContent );
		}

		if ( $this->gcCnt > 20 ) {
			if ( function_exists( 'gc_enable' ) ) {
				gc_enable();
			}
			if ( function_exists( 'gc_collect_cycles' ) ) {
				gc_collect_cycles();
			}
			$this->gcCnt = 0;
		}

		if ( ( ( $this->file_descriptors > 0 ) && ( $this->zipArchiveFileCount > $this->file_descriptors ) ) ) {
			$this->zip->close();
			$this->zip = null;
			unset( $this->zip );
			if ( function_exists( 'gc_enable' ) ) {
				gc_enable();
			}
			if ( function_exists( 'gc_collect_cycles' ) ) {
				gc_collect_cycles();
			}
			$this->zip = new \ZipArchive();
			$this->zip->open( $this->zipArchiveFileName );
			$this->zipArchiveFileCount = 0;
			$this->zipArchiveSizeCount = 0;
		}

		return $added;
	}

	/**
	 * Create full backup via console.
	 *
	 * @param string $filepath Path to file.
	 * @param array  $excludes Files & directories to exclude.
	 * @param bool   $addConfig Whether to add config.
	 * @param bool   $includeCoreFiles Whether to include core files.
	 * @param bool   $excludezip Whether to exclude zip archives.
	 * @param bool   $excludenonwp Whether or not to exclude any wp core files.
	 * @return bool Return FALSE.
	 */
	public function create_zip_console_full_backup(
		$filepath,
		$excludes,
		$addConfig,
		$includeCoreFiles,
		$excludezip,
		$excludenonwp ) {

		return false;
	}

	/**
	 * Create DB backup.
	 *
	 * @param $filepath_prefix File path prefix.
	 * @param bool                             $archiveExt Archive extension.
	 * @param null                             $archiver Archiver response.
	 * @return array|null[]|string[]
	 */
	public function create_backup_db( $filepath_prefix, $archiveExt = false, &$archiver = null ) {

		$timeout = 20 * 60 * 60;
		$mem     = '512M';
		MainWP_Helper::set_limit( $timeout, $mem );

		/** @var $wpdb wpdb */
		global $wpdb;

		$db_files  = array();
		$tables_db = $wpdb->get_results( 'SHOW TABLES FROM `' . DB_NAME . '`', ARRAY_N );  // phpcs:ignore -- safe query.
		foreach ( $tables_db as $curr_table ) {
			if ( null !== $archiver ) {
				$archiver->update_pid_file();
			}

			$table = $curr_table[0];

			$currentfile = $filepath_prefix . '-' . MainWP_Helper::sanitize_filename( $table ) . '.sql';
			$db_files[]  = $currentfile;
			if ( file_exists( $currentfile ) ) {
				continue;
			}
			$fh = fopen( $currentfile . '.tmp', 'w' );

			fwrite( $fh, "\n\n" . 'DROP TABLE IF EXISTS ' . $table . ';' );
			$table_create = $wpdb->get_row( 'SHOW CREATE TABLE ' . $table, ARRAY_N ); // phpcs:ignore -- safe query.
			fwrite( $fh, "\n" . $table_create[1] . ";\n\n" );

			$rows = MainWP_Child_DB::to_query( 'SELECT * FROM ' . $table, $wpdb->dbh ); // phpcs:ignore -- safe query.

			if ( $rows ) {
				$i            = 0;
				$table_insert = 'INSERT INTO `' . $table . '` VALUES (';

				// @codingStandardsIgnoreStart
				while ( $row = @MainWP_Child_DB::fetch_array( $rows ) ) {
					// @codingStandardsIgnoreEnd
					$query = $table_insert;
					foreach ( $row as $value ) {
						if ( null === $value ) {
							$query .= 'NULL, ';
						} else {
							$query .= '"' . MainWP_Child_DB::real_escape_string( $value ) . '", ';
						}
					}
					$query = trim( $query, ', ' ) . ');';

					fwrite( $fh, "\n" . $query );
					$i ++;

					if ( $i >= 50 ) {
						fflush( $fh );
						$i = 0;
					}

					$query = null;
					$row   = null;
				}
			}
			$rows = null;
			fflush( $fh );
			fclose( $fh );
			rename( $currentfile . '.tmp', $currentfile );
		}

		fclose( fopen( $filepath_prefix . '.sql', 'w' ) );
		$db_files[] = $filepath_prefix . '.sql';

		$archivefilePath = null;
		if ( false !== $archiveExt ) {
			$archivefilePath = $filepath_prefix . '.sql.' . $archiveExt;

			if ( 'zip' === $archiveExt ) {
				$this->archiver = null;
			} else {
				$this->archiver = new Tar_Archiver( $this, $archiveExt );
			}

			if ( $this->zip_file( $db_files, $archivefilePath ) && file_exists( $archivefilePath ) ) {
				foreach ( $db_files as $db_file ) {
					unlink( $db_file );
				}
			}
		}

		return ( false !== $archiveExt ? array( 'filepath' => $archivefilePath ) : $db_files );
	}
}
