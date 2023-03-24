<?php
/**
 * MainWP Tar Archiver
 *
 * This file handles the Tar archiver actions.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

// phpcs:disable WordPress.WP.AlternativeFunctions, Generic.Metrics.CyclomaticComplexity -- to custom read/write files, complex tar archiver library.

/**
 * Class Tar_Archiver
 *
 * Handle the Tar archiver actions.
 */
class Tar_Archiver {
	const IDLE   = 0;
	const APPEND = 1;
	const CREATE = 2;

	/**
	 * Whether to exclude zip archives.
	 *
	 * @var bool Set true to exclude ZIP archives from the backup.
	 */
	protected $excludeZip;

	/**
	 * Backup archive file.
	 *
	 * @var resource Backup file.
	 */
	protected $archive;

	/**
	 * Backup archive file path.
	 *
	 * @var string Backup file path.
	 */
	protected $archivePath;

	/**
	 * Backup archive file size.
	 *
	 * @var float Backup file size.
	 */
	protected $archiveSize;

	/**
	 * Last time a backup has been run.
	 *
	 * @var string Last backup run.
	 */
	protected $lastRun = 0;

	/**
	 * Enable debug mode.
	 *
	 * @var bool Set try to enable debugging.
	 */
	protected $debug;

	/**
	 * Chunk of the backup.
	 *
	 * @var string Backup chunk.
	 */
	protected $chunk = '';

	/**
	 * Backup chunk size.
	 *
	 * @var int Backup chunk size.
	 */
	protected $chunkSize = 4194304;

	/**
	 * MainWP_Backup class instance.
	 *
	 * @var object MainWP_Backup class
	 */
	protected $backup;

	/**
	 * Backup archive type.
	 *
	 * @var string Archive type.
	 */
	protected $type;

	/**
	 * Backup PID file.
	 *
	 * @var resource PID file.
	 */
	protected $pidFile;

	/**
	 * Backup PID file content.
	 *
	 * @var string PID file content.
	 */
	protected $pidContent;

	/**
	 * Time of the last PID file update.
	 *
	 * @var string Last PID file update.
	 */
	protected $pidUpdated;

	/**
	 * Arvive mode, IDLE, APPEND or CREATE.
	 *
	 * @var int Arvive mode.
	 */
	protected $mode = self::IDLE;

	/**
	 * Enable logging.
	 *
	 * @var bool Set true to log records.
	 */
	protected $logHandle = false;

	/**
	 * Tar_Archiver constructor.
	 *
	 * Run any time class is called.
	 *
	 * @param object $backup  MainWP_Backup class instance.
	 * @param string $type    Backup arvhive type.
	 * @param bool   $pidFile Request PID file or not.
	 */
	public function __construct( $backup, $type = 'tar', $pidFile = false ) {
		$this->debug = false;

		$this->pidFile = $pidFile;
		$this->backup  = $backup;

		$this->type = $type;
		if ( 'tar.bz2' == $this->type ) {
			if ( ! function_exists( 'bzopen' ) ) {
				$this->type = 'tar.gz';
			}
		}

		if ( 'tar.gz' == $this->type ) {
			if ( ! function_exists( 'gzopen' ) ) {
				$this->type = 'tar';
			}
		}
	}

	/**
	 * Get backup archive file extension, .tar.bz2, .tar.gz or .tar.
	 *
	 * @return string Aarchive file extension, .tar.bz2, .tar.gz or .tar.
	 */
	public function get_extension() {
		if ( 'tar.bz2' == $this->type ) {
			return '.tar.bz2';
		}
		if ( 'tar.gz' == $this->type ) {
			return '.tar.gz';
		}

		return '.tar';
	}

	/**
	 * Create ZIP file.
	 *
	 * @param array  $files Files to zip.
	 * @param string $archive Type of archive to create.
	 *
	 * @uses Tar_Archiver::create() Create archive file.
	 * @uses Tar_Archiver::add_file() Add file to the archive file.
	 * @uses Tar_Archiver::add_data() Add data to the archive file.
	 * @uses Tar_Archiver::close() Close archive file.
	 *
	 * @return bool Return false on failure, true on success.
	 */
	public function zip_file( $files, $archive ) {
		$this->create( $archive );
		if ( $this->archive ) {
			$this->init_log_handle( $archive . '.log' );
			foreach ( $files as $filepath ) {
				$this->add_file( $filepath, basename( $filepath ) );
			}

			$this->add_data( pack( 'a1024', '' ) );
			$this->close();

			return true;
		}

		return false;
	}

	/**
	 * Create backup archive PID file.
	 *
	 * @param resource $file Backup archive PID file.
	 *
	 * @uses WP_Filesystem_Direct::put_contents() Writes a string to a file.
	 * @see https://developer.wordpress.org/reference/classes/wp_filesystem_direct/put_contents/
	 *
	 * @used-by Tar_Archiver::create_full_backup() Create full backup.
	 *
	 * @return bool Return false on failure, true on success.
	 */
	private function create_pid_file( $file ) {
		if ( false === $this->pidFile ) {
			return false;
		}
		$this->pidContent = $file;

		/**
		 * Global variable containing the instance of the (auto-)configured filesystem object after the filesystem "factory" has been run.
		 *
		 * @global object $wp_filesystem Filesystem object.
		 */
		global $wp_filesystem;

		$wp_filesystem->put_contents( $this->pidFile, $this->pidContent );

		$this->pidUpdated = time();

		return true;
	}

	/**
	 * Update backup archive PID file.
	 *
	 * @uses WP_Filesystem_Direct::put_contents() Writes a string to a file.
	 * @see https://developer.wordpress.org/reference/classes/wp_filesystem_direct/put_contents/
	 *
	 * @used-by Tar_Archiver::add_empty_dir() Add empty directory.
	 *
	 * @return bool Return false on failure, true on success.
	 */
	public function update_pid_file() {
		if ( false === $this->pidFile ) {
			return false;
		}
		if ( time() - $this->pidUpdated < 40 ) {
			return false;
		}

		/**
		 * Global variable containing the instance of the (auto-)configured filesystem object after the filesystem "factory" has been run.
		 *
		 * @global object $wp_filesystem Filesystem object.
		 */
		global $wp_filesystem;

		$wp_filesystem->put_contents( $this->pidFile, $this->pidContent );
		$this->pidUpdated = time();

		return true;
	}

	/**
	 * Complete backup archive PID file.
	 *
	 * @uses WP_Filesystem_Direct::move() Moves a file.
	 * @see https://developer.wordpress.org/reference/classes/wp_filesystem_direct/move/
	 *
	 * @used-by Tar_Archiver::create_full_backup() Create full backup.
	 *
	 * @return bool Return false on failure, true on success.
	 */
	private function complete_pid_file() {
		if ( false === $this->pidFile ) {
			return false;
		}

		/**
		 * Global variable containing the instance of the (auto-)configured filesystem object after the filesystem "factory" has been run.
		 *
		 * @global object $wp_filesystem Filesystem object.
		 */
		global $wp_filesystem;

		$filename = basename( $this->pidFile );
		$wp_filesystem->move( $this->pidFile, trailingslashit( dirname( $this->pidFile ) ) . substr( $filename, 0, strlen( $filename ) - 4 ) . '.done' );
		$this->pidFile = false;

		return true;
	}

	/**
	 * Init log file handle.
	 *
	 * @param string $file_log         Log file path.
	 */
	public function init_log_handle( $file_log ) {
		//phpcs:ignore -- for debug.
		// $this->logHandle = fopen( $file_log, 'a+' );
	}

	/**
	 * Create full backup.
	 *
	 * @param string $filepath         Backup file path.
	 * @param array  $excludes         Files to exclude from the backup.
	 * @param bool   $addConfig        Add config file to backup.
	 * @param bool   $includeCoreFiles Include WordPress core files.
	 * @param bool   $excludezip       Exclude zip files from the backup.
	 * @param bool   $excludenonwp     Exclude non-WordPress directories in site root.
	 * @param bool   $append           Append to backup file name.
	 *
	 * @return bool Return false on failure, true on success.
	 * @throws \Exception Error message.
	 *
	 * @uses Tar_Archiver::create_pid_file() Create PID file.
	 * @uses Tar_Archiver::prepare_append() Prepare to append.
	 * @uses Tar_Archiver::create() Create backup archive file.
	 * @uses Tar_Archiver::include_core_files() Include WordPress core files.
	 * @uses Tar_Archiver::create_backup_db() Create database backup.
	 * @uses Tar_Archiver::add_file() Add file to backup archive file.
	 * @uses Tar_Archiver::add_dir() Add directory to backup archive file.
	 * @uses Tar_Archiver::add_config() Add config file to backup archive file.
	 * @uses Tar_Archiver::add_empty_directory() Add empty 'clone' directory to backup archive file.
	 * @uses Tar_Archiver::add_file_from_string() Add file from a string.
	 * @uses Tar_Archiver::add_data() Add data to the backup archive file.
	 * @uses Tar_Archiver::close() Close the bacukup archive file.
	 * @uses Tar_Archiver::complete_pid_file() Complete the PID file.
	 * @uses \MainWP\Child\MainWP_Helper::starts_with()
	 * @uses \MainWP\Child\MainWP_Helper::in_excludes()
	 */
	public function create_full_backup( $filepath, $excludes, $addConfig, $includeCoreFiles, $excludezip, $excludenonwp, $append = false ) {

		$this->init_log_handle( $filepath . '.log' );

		$this->create_pid_file( $filepath );

		$this->excludeZip = $excludezip;

		$this->archivePath = $filepath;

		if ( $append && file_exists( $filepath ) ) {
			$this->mode = self::APPEND;
			$this->prepare_append( $filepath );
		} else {
			$this->mode = self::CREATE;
			$this->create( $filepath );
		}

		if ( $this->archive ) {
			$nodes = glob( ABSPATH . '*' );
			if ( ! $includeCoreFiles ) {
				$this->include_core_files( $nodes );
			}

			$db_files = $this->backup->create_backup_db( dirname( $filepath ) . DIRECTORY_SEPARATOR . 'dbBackup', false, $this );

			foreach ( $db_files as $db_file ) {
				$this->add_file( $db_file, basename( WP_CONTENT_DIR ) . '/' . basename( $db_file ) );
			}

			if ( file_exists( ABSPATH . '.htaccess' ) ) {
				$this->add_file( ABSPATH . '.htaccess', 'mainwp-htaccess' );
			}

			foreach ( $nodes as $node ) {
				if ( $excludenonwp && is_dir( $node ) ) {
					if ( ! MainWP_Helper::starts_with( $node, WP_CONTENT_DIR ) && ! MainWP_Helper::starts_with( $node, ABSPATH . 'wp-admin' ) && ! MainWP_Helper::starts_with( $node, ABSPATH . WPINC ) ) {
						continue;
					}
				}

				if ( ! MainWP_Helper::in_excludes( $excludes, str_replace( ABSPATH, '', $node ) ) ) {
					if ( is_dir( $node ) ) {
						$this->add_dir( $node, $excludes );
					} elseif ( is_file( $node ) ) {
						$this->add_file( $node, str_replace( ABSPATH, '', $node ) );
					}
				}
			}

			if ( $addConfig ) {
				$string = $this->add_config();
				$this->add_empty_directory( 'clone', 0, 0, 0, time() );
				$this->add_file_from_string( 'clone/config.txt', $string );
			}

			$this->add_data( pack( 'a1024', '' ) );
			$this->close();
			foreach ( $db_files as $db_file ) {
				unlink( $db_file );
			}

			$this->complete_pid_file();

			return true;
		}

		return false;
	}

	/**
	 * Include WordPress core file.
	 *
	 * @param array $nodes Default nodes.
	 *
	 * @used-by  \MainWP\Child\Tar_Archiver::create_full_backup() Create full backup.
	 * @uses    \MainWP\Child\MainWP_Helper::starts_with()
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
					if ( ABSPATH . $coreFile == $node ) {
						unset( $nodes[ $key ] );
					}
				}
			}
		}
		unset( $coreFiles );
	}

	/**
	 * Add config file to the backup archive file.
	 *
	 * @uses wp_json_encode() Encode a variable into JSON, with some sanity checks.
	 * @see https://developer.wordpress.org/reference/functions/wp_json_encode/
	 *
	 * @uses get_option() Retrieves an option value based on an option name.
	 * @see https://developer.wordpress.org/reference/functions/get_option/
	 *
	 * @uses get_bloginfo() Retrieves information about the current site.
	 * @see https://developer.wordpress.org/reference/functions/get_bloginfo/
	 *
	 * @used-by Tar_Archiver::create_full_backup() Create full backup.
	 */
	private function add_config() {

		/**
		 * Object, providing access to the WordPress database.
		 *
		 * @global $wpdb WordPress Database instance.
		 */
		global $wpdb;

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

		$string = wp_json_encode(
			array(
				'siteurl' => get_option( 'siteurl' ),
				'home'    => get_option( 'home' ),
				'abspath' => ABSPATH,
				'prefix'  => $wpdb->prefix,
				'lang'    => get_bloginfo( 'language' ),
				'plugins' => $plugins,
				'themes'  => $themes,
			)
		);

		return $string;
	}

	/**
	 * Add directory to the backup archive file.
	 *
	 * @param string $path     File path.
	 * @param array  $excludes List of file to exclude from the backup.
	 *
	 * @uses \MainWP\Child\Tar_Archiver::add_empty_dir() Add empty directory to the backup archive file.
	 * @uses \MainWP\Child\Tar_Archiver::add_file() Add file to the backup archive file.
	 * @uses \MainWP\Child\MainWP_Helper::in_excludes()
	 *
	 * @used-by \MainWP\Child\Tar_Archiver::create_full_backup() Create full backup.
	 */
	public function add_dir( $path, $excludes ) {
		if ( ( '.' == basename( $path ) ) || ( '..' == basename( $path ) ) ) {
			return;
		}

		$this->add_empty_dir( $path, str_replace( ABSPATH, '', $path ) );

		if ( file_exists( rtrim( $path, '/' ) . '/.htaccess' ) ) {
			$this->add_file( rtrim( $path, '/' ) . '/.htaccess', rtrim( str_replace( ABSPATH, '', $path ), '/' ) . '/mainwp-htaccess' );
		}

		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $path ), \RecursiveIteratorIterator::SELF_FIRST, \RecursiveIteratorIterator::CATCH_GET_CHILD );

		foreach ( $iterator as $path ) {
			$name = $path->__toString();
			if ( ( '.' == basename( $name ) ) || ( '..' == basename( $name ) ) ) {
				continue;
			}

			if ( ! MainWP_Helper::in_excludes( $excludes, str_replace( ABSPATH, '', $name ) ) ) {
				if ( $path->isDir() ) {
					$this->add_empty_dir( $name, str_replace( ABSPATH, '', $name ) );
				} else {
					$this->add_file( $name, str_replace( ABSPATH, '', $name ) );
				}
			}
			$name = null;
			unset( $name );
		}

		$iterator = null;
		unset( $iterator );
	}

	/**
	 * Add data to the backup archive file.
	 *
	 * @param arary $data Data to add to the backup archive file.
	 *
	 * @uses Tar_Archiver::add_empty_dir() Add empty directory to the backup archive file.
	 * @uses Tar_Archiver::add_file() Add file to the backup archive file.
	 *
	 * @used-by Tar_Archiver::create_full_backup() Create full backup.
	 *
	 * @throws \Exception Error message.
	 */
	private function add_data( $data ) {
		if ( $this->debug ) {
			$this->chunk .= $data;

			if ( strlen( $this->chunk ) > $this->chunkSize ) {
				$this->write_chunk();
			}

			return;
		}

		if ( 'tar.gz' == $this->type ) {
			if ( false === gzwrite( $this->archive, $data, strlen( $data ) ) ) {
				throw new \Exception( 'Could not write to archive' );
			}
		} elseif ( 'tar.bz2' == $this->type ) {
			if ( false === bzwrite( $this->archive, $data, strlen( $data ) ) ) {
				throw new \Exception( 'Could not write to archive' );
			}
		} else {
			if ( false === fwrite( $this->archive, $data, strlen( $data ) ) ) {
				throw new \Exception( 'Could not write to archive' );
			}
			fflush( $this->archive );
		}
	}

	/**
	 * Write backup chunk.
	 *
	 * @throws \Exception Error message.
	 */
	private function write_chunk() {
		$len = strlen( $this->chunk );
		if ( 0 == $len ) {
			return;
		}

		if ( 'tar.gz' == $this->type ) {
			$this->log( 'writing & flushing ' . $len );
			$this->chunk = gzencode( $this->chunk );
			if ( false === fwrite( $this->archive, $this->chunk, strlen( $this->chunk ) ) ) {
				throw new \Exception( 'Could not write to archive' );
			}
			fflush( $this->archive );
		} elseif ( 'tar.bz2' == $this->type ) {
			if ( false === bzwrite( $this->archive, $this->chunk, strlen( $len ) ) ) {
				throw new \Exception( 'Could not write to archive' );
			}
		} else {
			if ( false === fwrite( $this->archive, $len, strlen( $len ) ) ) {
				throw new \Exception( 'Could not write to archive' );
			}
			fflush( $this->archive );
		}

		$this->chunk = '';
	}

	/**
	 * Fire off the add_empty_directory() function.
	 *
	 * @param string $path      File path.
	 * @param string $entryName Entry name.
	 *
	 * @uses Tar_Archiver::add_empty_directory() Add empty directory to the backup archive file.
	 *
	 * @used-by Tar_Archiver::create_full_backup() Create full backup.
	 */
	private function add_empty_dir( $path, $entryName ) {
		$stat = stat( $path );

		$this->add_empty_directory( $entryName, $stat['mode'], $stat['uid'], $stat['gid'], $stat['mtime'] );
	}

	/**
	 * Add empty directory to the backup archive file.
	 *
	 * @param string $entryName Entry name.
	 * @param int    $mode      Inode protection mode.
	 * @param int    $uid       Userid of the file owner.
	 * @param int    $gid       Groupid of the file owner .
	 * @param string $mtime     Time of last modification of the file.
	 *
	 * @uses Tar_Archiver::check_before_append() Check before append.
	 * @uses Tar_Archiver::add_data() Add data to the backup archive file.
	 *
	 * @used-by Tar_Archiver::add_empty_dir() Fire off the add_empty_directory() function.
	 */
	private function add_empty_directory( $entryName, $mode, $uid, $gid, $mtime ) {
		if ( self::APPEND == $this->mode ) {
			if ( true === $this->check_before_append( $entryName ) ) {
				return true;
			}
		}

		$prefix = '';
		if ( strlen( $entryName ) > 99 ) {
			$prefix    = substr( $entryName, 0, strpos( $entryName, '/', strlen( $entryName ) - 100 ) + 1 );
			$entryName = substr( $entryName, strlen( $prefix ) );
			if ( strlen( $prefix ) > 154 || strlen( $entryName ) > 99 ) {
				$entryName = $prefix . $entryName;
				$prefix    = '';

				$block = pack(
					'a100a8a8a8a12a12a8a1a100a6a2a32a32a8a8a155a12',
					'././@LongLink',
					sprintf( '%07o', 0 ),
					sprintf( '%07o', 0 ),
					sprintf( '%07o', 0 ),
					sprintf( '%011o', strlen( $entryName ) ),
					sprintf( '%011o', 0 ),
					'        ',
					'L',
					'',
					'ustar',
					' ',
					'',
					'',
					'',
					'',
					'',
					''
				);

				$checksum = 0;
				for ( $i = 0; $i < 512; $i ++ ) {
					$checksum += ord( substr( $block, $i, 1 ) );
				}
				$checksum = pack( 'a8', sprintf( '%07o', $checksum ) );
				$block    = substr_replace( $block, $checksum, 148, 8 );

				$this->add_data( $block );
				$this->add_data( pack( 'a512', $entryName ) );
				$entryName = substr( $entryName, 0, 100 );
			}
		}

		$block = pack(
			'a100a8a8a8a12a12a8a1a100a6a2a32a32a8a8a155a12',
			$entryName,
			sprintf( '%07o', $mode ),
			sprintf( '%07o', $uid ),
			sprintf( '%07o', $gid ),
			sprintf( '%011o', 0 ),
			sprintf( '%011o', $mtime ),
			'        ',
			5,
			'',
			'ustar',
			' ',
			'Unknown',
			'Unknown',
			'',
			'',
			$prefix,
			''
		);

		$checksum = 0;
		for ( $i = 0; $i < 512; $i ++ ) {
			$checksum += ord( substr( $block, $i, 1 ) );
		}
		$checksum = pack( 'a8', sprintf( '%07o', $checksum ) );
		$block    = substr_replace( $block, $checksum, 148, 8 );

		$this->add_data( $block );

		return true;
	}

	/**
	 * Block of the backup content.
	 *
	 * @var array Block of content.
	 */
	protected $block;

	/**
	 * Temprary backup content.
	 *
	 * @var string Temprary content.
	 */
	protected $tempContent;

	/**
	 * Garbage collection count.
	 *
	 * @var int Garbage collection count.
	 */
	protected $gcCnt = 0;

	/**
	 * Count number.
	 *
	 * @var int Count number.
	 */
	protected $cnt = 0;

	/**
	 * Add file to the backup archive file.
	 *
	 * @param string $path      File path.
	 * @param string $entryName Entry name.
	 *
	 * @return bool Return false on failure, true on success.
	 * @throws \Exception Error message.
	 *
	 * @used-by \MainWP\Child\Tar_Archiver::create_full_backup() Create full backup.
	 * @uses    \MainWP\Child\Tar_Archiver::add_data() Add data to the backup archive file.
	 * @uses    \MainWP\Child\Tar_Archiver::update_pid_file() Update the PID file.
	 * @uses    \MainWP\Child\MainWP_Helper::ends_with()
	 */
	private function add_file( $path, $entryName ) { // phpcs:ignore -- Current complexity is the only way to achieve desired results, pull request solutions appreciated.
		if ( ( '.' == basename( $path ) ) || ( '..' == basename( $path ) ) ) {
			return false;
		}

		if ( $this->excludeZip && MainWP_Helper::ends_with( $path, '.zip' ) ) {
			$this->log( 'Skipping ' . $path );

			return false;
		}

		$this->log( 'Adding ' . $path );

		$this->update_pid_file();

		$rslt = false;
		if ( self::APPEND == $this->mode ) {
			$rslt = $this->check_before_append( $entryName );
			if ( true === $rslt ) {
				return true;
			}
		}

		if ( time() - $this->lastRun > 60 ) {
			set_time_limit( 20 * 60 * 60 );
			$this->lastRun = time();
		}

		$this->gcCnt ++;
		if ( $this->gcCnt > 20 ) {
			if ( function_exists( 'gc_enable' ) ) {
				gc_enable();
			}
			if ( function_exists( 'gc_collect_cycles' ) ) {
				gc_collect_cycles();
			}
			$this->gcCnt = 0;
		}

		$stat = stat( $path );
		$fp   = fopen( $path, 'rb' );
		if ( ! $fp ) {
			return;
		}

		$prefix = '';
		if ( strlen( $entryName ) > 99 ) {
			$prefix    = substr( $entryName, 0, strpos( $entryName, '/', strlen( $entryName ) - 100 ) + 1 );
			$entryName = substr( $entryName, strlen( $prefix ) );
			if ( strlen( $prefix ) > 154 || strlen( $entryName ) > 99 ) {
				$entryName = $prefix . $entryName;
				$prefix    = '';

				$block = pack(
					'a100a8a8a8a12a12a8a1a100a6a2a32a32a8a8a155a12',
					'././@LongLink',
					sprintf( '%07o', 0 ),
					sprintf( '%07o', 0 ),
					sprintf( '%07o', 0 ),
					sprintf( '%011o', strlen( $entryName ) ),
					sprintf( '%011o', 0 ),
					'        ',
					'L',
					'',
					'ustar',
					' ',
					'',
					'',
					'',
					'',
					'',
					''
				);

				$checksum = 0;
				for ( $i = 0; $i < 512; $i ++ ) {
					$checksum += ord( substr( $block, $i, 1 ) );
				}
				$checksum = pack( 'a8', sprintf( '%07o', $checksum ) );
				$block    = substr_replace( $block, $checksum, 148, 8 );

				if ( ! isset( $rslt['bytesRead'] ) ) {
					$this->add_data( $block );
				}
				if ( ! isset( $rslt['bytesRead'] ) ) {
					$this->add_data( pack( 'a512', $entryName ) );
				}
				$entryName = substr( $entryName, 0, 100 );
			}
		}

		$this->block = pack(
			'a100a8a8a8a12a12a8a1a100a6a2a32a32a8a8a155a12',
			$entryName,
			sprintf( '%07o', $stat['mode'] ),
			sprintf( '%07o', $stat['uid'] ),
			sprintf( '%07o', $stat['gid'] ),
			sprintf( '%011o', $stat['size'] ),
			sprintf( '%011o', $stat['mtime'] ),
			'        ',
			0,
			'',
			'ustar',
			' ',
			'Unknown',
			'Unknown',
			'',
			'',
			$prefix,
			''
		);

		$checksum = 0;
		for ( $i = 0; $i < 512; $i ++ ) {
			$checksum += ord( substr( $this->block, $i, 1 ) );
		}
		$checksum    = pack( 'a8', sprintf( '%07o', $checksum ) );
		$this->block = substr_replace( $this->block, $checksum, 148, 8 );

		if ( ! isset( $rslt['bytesRead'] ) ) {
			$this->add_data( $this->block );
		}

		if ( isset( $rslt['bytesRead'] ) ) {
			fseek( $fp, $rslt['bytesRead'] );

			$alreadyRead = ( $rslt['bytesRead'] % 512 );
			$toRead      = 512 - $alreadyRead;
			if ( $toRead > 0 ) {
				$this->tempContent = fread( $fp, $toRead );

				$this->add_data( $this->tempContent );

				$remainder = 512 - ( strlen( $this->tempContent ) + $alreadyRead );
				$this->log( 'DEBUG-Added ' . strlen( $this->tempContent ) . '(before: ' . $alreadyRead . ') will pack: ' . $remainder . ' (packed: ' . strlen( pack( 'a' . $remainder, '' ) ) );
				if ( $remainder > 0 ) {
					$this->add_data( pack( 'a' . $remainder ), '' );
				}
			}
		}

		while ( ! feof( $fp ) ) {
			$this->tempContent = fread( $fp, 1024000 * 5 );

			$read   = strlen( $this->tempContent );
			$divide = $read % 512;

			$this->add_data( substr( $this->tempContent, 0, $read - $divide ) );

			if ( $divide > 0 ) {
				$this->add_data( pack( 'a512', substr( $this->tempContent, - 1 * $divide ) ) );
			}

			$this->update_pid_file();
		}

		fclose( $fp );

		return true;
	}

	/**
	 * Add file from a string.
	 *
	 * @param string $entryName Entry name.
	 * @param string $content   Entry content.
	 *
	 * @uses Tar_Archiver::add_data() Add data to the backup archive file.
	 *
	 * @return bool Return false on failure, true on success.
	 */
	private function add_file_from_string( $entryName, $content ) {
		$this->log( 'Add from string ' . $entryName );

		if ( self::APPEND == $this->mode ) {
			if ( true === $this->check_before_append( $entryName ) ) {
				return true;
			}
		}

		$prefix = '';
		if ( strlen( $entryName ) > 99 ) {
			$prefix    = substr( $entryName, 0, strpos( $entryName, '/', strlen( $entryName ) - 100 ) + 1 );
			$entryName = substr( $entryName, strlen( $prefix ) );
			if ( strlen( $prefix ) > 154 || strlen( $entryName ) > 99 ) {
				$entryName = $prefix . $entryName;
				$prefix    = '';

				$block = pack(
					'a100a8a8a8a12a12a8a1a100a6a2a32a32a8a8a155a12',
					'././@LongLink',
					sprintf( '%07o', 0 ),
					sprintf( '%07o', 0 ),
					sprintf( '%07o', 0 ),
					sprintf( '%011o', strlen( $entryName ) ),
					sprintf( '%011o', 0 ),
					'        ',
					'L',
					'',
					'ustar',
					' ',
					'',
					'',
					'',
					'',
					'',
					''
				);

				$checksum = 0;
				for ( $i = 0; $i < 512; $i ++ ) {
					$checksum += ord( substr( $block, $i, 1 ) );
				}
				$checksum = pack( 'a8', sprintf( '%07o', $checksum ) );
				$block    = substr_replace( $block, $checksum, 148, 8 );

				$this->add_data( $block );
				$this->add_data( pack( 'a512', $entryName ) );
				$entryName = substr( $entryName, 0, 100 );
			}
		}

		$block = pack(
			'a100a8a8a8a12a12a8a1a100a6a2a32a32a8a8a155a12',
			$entryName,
			sprintf( '%07o', 0 ),
			sprintf( '%07o', 0 ),
			sprintf( '%07o', 0 ),
			sprintf( '%011o', strlen( $content ) ),
			sprintf( '%011o', time() ),
			'        ',
			0,
			'',
			'ustar',
			' ',
			'Unknown',
			'Unknown',
			'',
			'',
			$prefix,
			''
		);

		$checksum = 0;
		for ( $i = 0; $i < 512; $i ++ ) {
			$checksum += ord( substr( $block, $i, 1 ) );
		}
		$checksum = pack( 'a8', sprintf( '%07o', $checksum ) );
		$block    = substr_replace( $block, $checksum, 148, 8 );

		$this->add_data( $block );
		$i = 0;
		while ( ( $line = substr( $content, $i * 512, 512 ) ) != '' ) {
			$i++;
			$this->add_data( pack( 'a512', $line ) );
		}

		return true;
	}

	/**
	 * Check before append.
	 *
	 * @param string $entryName Entry name.
	 *
	 * @uses Tar_Archiver::close() Close the backup archive file.
	 * @uses Tar_Archiver::append() Append to the backup archive file.
	 *
	 * @return array Return function output.
	 */
	private function check_before_append( $entryName ) {
		$rslt = $this->is_next_file( $entryName );

		if ( true === $rslt ) {
			return true;
		}

		$out = false;

		$this->close( false );
		$this->log( 'Reopen archive to append from here' );
		$this->append( $this->archivePath );
		if ( is_array( $rslt ) ) {
			if ( 'tar' == $this->type ) {
				$startOffset = $rslt['startOffset'];
				fseek( $this->archive, $startOffset );
				ftruncate( $this->archive, $startOffset );
			} elseif ( 'tar.gz' == $this->type ) {
				$readOffset = $rslt['readOffset'];
				$bytesRead  = $rslt['bytesRead'];

				$out = array( 'bytesRead' => $bytesRead );
			}
		} elseif ( false === $rslt ) {
			if ( 'tar' == $this->type ) {
				fseek( $this->archive, 0, SEEK_END );
			}
		} else {
			fseek( $this->archive, $rslt );
			ftruncate( $this->archive, $rslt );
		}
		$this->mode = self::CREATE;

		return $out;
	}

	/**
	 * Is next file.
	 *
	 * @param string $entryName Entry name.
	 *
	 * @throws \Exception Error message.
	 *
	 * @return array|bool If true,skip file, if false or number, nothing to read, will continue with current file.
	 */
	private function is_next_file( $entryName ) {
		$currentOffset = ftell( $this->archive );
		$rslt          = array( 'startOffset' => $currentOffset );
		try {
			$block = fread( $this->archive, 512 );

			if ( false === $block || 0 == strlen( $block ) ) {
				return $rslt;
			}

			if ( 512 != strlen( $block ) ) {
				throw new \Exception( 'Invalid block found' );
			}

			$temp = unpack( 'a100name/a8mode/a8uid/a8gid/a12size/a12mtime/a8checksum/a1type/a100symlink/a6magic/a2temp/a32temp/a32temp/a8temp/a8temp/a155prefix/a12temp', $block );
			if ( 'L' == $temp['type'] ) {
				$fname          = trim( fread( $this->archive, 512 ) );
				$block          = fread( $this->archive, 512 );
				$temp           = unpack( 'a100name/a8mode/a8uid/a8gid/a12size/a12mtime/a8checksum/a1type/a100symlink/a6magic/a2temp/a32temp/a32temp/a8temp/a8temp/a155prefix/a12temp', $block );
				$temp['prefix'] = '';
				$temp['name']   = $fname;
			}
			$file = array(
				'name'     => trim( $temp['prefix'] ) . trim( $temp['name'] ),
				'stat'     => array(
					2 => $temp['mode'],
					4 => octdec( $temp['uid'] ),
					5 => octdec( $temp['gid'] ),
					7 => octdec( $temp['size'] ),
					9 => octdec( $temp['mtime'] ),
				),
				'checksum' => octdec( $temp['checksum'] ),
				'type'     => $temp['type'],
				'magic'    => $temp['magic'],
			);

			if ( 5 == $file['type'] ) {
				if ( 0 == strcmp( trim( $file['name'] ), trim( $entryName ) ) ) {
					$this->log( 'Skipping directory [' . $file['name'] . ']' );

					return true;
				} else {
					throw new \Exception( 'Unexpected directory [' . $file['name'] . ']' );
				}
			} elseif ( 0 == $file['type'] ) {
				if ( 0 == strcmp( trim( $file['name'] ), trim( $entryName ) ) ) {
					return $this->read_next_bytes( $file );
				} else {
					$this->log( 'Unexpected file [' . $file['name'] . ']' );
					throw new \Exception( 'Unexpected file' );
				}
			}

			$this->log( 'ERROR' );
			throw new \Exception( 'Should never get here?' );
		} catch ( \Exception $e ) {
			$this->log( $e->getMessage() );
			throw $e;
		}
	}

	/**
	 * Read next bytes.
	 *
	 * @param resource $file File to process.
	 *
	 * @return array|bool Number of bytes or false on failure.
	 */
	private function read_next_bytes( $file ) {
		$previousFtell = ftell( $this->archive );
		$bytes         = $file['stat'][7] + ( 512 == ( 512 - $file['stat'][7] % 512 ) ? 0 : ( 512 - $file['stat'][7] % 512 ) );
		fseek( $this->archive, ftell( $this->archive ) + $bytes );
		$ftell = ftell( $this->archive );
		if ( 'tar.gz' == $this->type ) {
			if ( ( false === $ftell ) || ( -1 == $ftell ) ) {
				fseek( $this->archive, $previousFtell );

				$bytesRead   = 0;
				$bytesToRead = $file['stat'][7];

				while ( $bytesToRead > 0 ) {
					$readNow            = $bytesToRead > 1024 ? 1024 : $bytesToRead;
					$bytesCurrentlyRead = strlen( fread( $this->archive, $readNow ) );

					if ( 0 == $bytesCurrentlyRead ) {
						break;
					}

					$bytesRead   += $bytesCurrentlyRead;
					$bytesToRead -= $bytesCurrentlyRead;
				}

				if ( 0 == $bytesToRead ) {
					$toRead = ( 512 - $file['stat'][7] % 512 ) == 512 ? 0 : ( 512 - $file['stat'][7] % 512 );
					if ( $toRead > 0 ) {
						$read       = strlen( fread( $this->archive, $toRead ) );
						$bytesRead += $read;
					}
				}

				$rslt['bytesRead']  = $bytesRead;
				$rslt['readOffset'] = $previousFtell;

				$this->log( 'Will append this: ' . print_r( $rslt, 1 ) ); // phpcs:ignore -- debug feature.

				return $rslt;
			}
		} elseif ( ( 'tar' == $this->type ) && ( ( false === $ftell ) || ( -1 == $ftell ) ) ) {
			$this->log( 'Will append this: ' . print_r( $rslt, 1 ) ); // phpcs:ignore -- debug feature.

			return $rslt;
		}
		$this->log( 'Skipping file [' . $file['name'] . ']' );
		return true;
	}

	/**
	 * Log messages to the backup error log.
	 *
	 * @param string $text Log message.
	 */
	public function log( $text ) {
		if ( $this->logHandle ) {
			fwrite( $this->logHandle, $text . "\n" );
		}
	}

	/**
	 * Create backup archive file.
	 *
	 * @param string $filepath File location path.
	 */
	public function create( $filepath ) {
		$this->log( 'Creating ' . $filepath );
		if ( $this->debug ) {
			if ( 'tar.bz2' == $this->type ) {
				$this->archive = bzopen( $filepath, 'w' );
			} else {
				$this->archive = fopen( $filepath, 'wb+' );
			}

			return;
		}

		if ( 'tar.gz' == $this->type ) {
			$this->archive = gzopen( $filepath, 'wb' );
		} elseif ( 'tar.bz2' == $this->type ) {
			$this->archive = bzopen( $filepath, 'w' );
		} else {
			$this->archive = fopen( $filepath, 'wb+' );
		}
	}

	/**
	 * Append to the backup archive file.
	 *
	 * @param string $filepath File location path.
	 */
	public function append( $filepath ) {
		$this->log( 'Appending to ' . $filepath );
		if ( $this->debug ) {
			if ( 'tar.bz2' == $this->type ) {
				$this->archive = bzopen( $filepath, 'a' );
			} else {
				$this->archive = fopen( $filepath, 'ab+' );
			}

			return;
		}

		if ( 'tar.gz' == $this->type ) {
			$this->archive = gzopen( $filepath, 'ab' );
		} elseif ( 'tar.bz2' == $this->type ) {
			$this->archive = bzopen( $filepath, 'a' );
		} else {
			$this->archive = fopen( $filepath, 'ab+' );
		}
	}

	/**
	 * Verify if the backup archive file is open.
	 *
	 * @return bool True if open, if not, false.
	 */
	public function is_open() {
		return ! empty( $this->archive );
	}

	/**
	 * Prepare the append process.
	 *
	 * @param string $filepath File location path.
	 *
	 * @throws \Exception Error message.
	 */
	public function prepare_append( $filepath ) {
		if ( $this->debug ) {
			if ( 'tar.gz' == substr( $filepath, - 6 ) ) {
				$text        = chr( 31 ) . chr( 139 ) . chr( 8 ) . chr( 0 ) . chr( 0 ) . chr( 0 ) . chr( 0 ) . chr( 0 ) . chr( 0 );
				$fh          = fopen( $filepath, 'rb' );
				$read        = '';
				$lastCorrect = 0;
				try {
					while ( ! feof( $fh ) ) {
						$read .= fread( $fh, 1000 );
						while ( ( $pos = strpos( $read, $text, 2 ) ) !== false ) {
							for ( $i = 0; $i < 10; $i ++ ) {
								echo ord( $read[ $i ] ) . "\n";
							}

							if ( ! $this->is_valid_block( substr( $read, 10, $pos - 10 ) ) ) {
								throw new \Exception( 'invalid!' );
							}

							$lastCorrect += $pos;
							$read         = substr( $read, $pos );
						}
					}

					if ( ! $this->is_valid_block( substr( $read, 10 ) ) ) {
						throw new \Exception( 'invalid!' );
					}

					fclose( $fh );
				} catch ( \Exception $e ) {
					fclose( $fh );
					$fh = fopen( $filepath, 'ab+' );
					fseek( $fh, $lastCorrect );
					ftruncate( $fh, $lastCorrect );
					fclose( $fh );
				}
			}
		}

		$this->read( $filepath );
	}

	/**
	 * Read the backup archive file.
	 *
	 * @param string $filepath File location path.
	 */
	public function read( $filepath ) {
		$this->log( 'Reading ' . $filepath );
		$this->archiveSize = false;

		if ( 'tar.gz' == substr( $filepath, - 6 ) ) {
			$this->type    = 'tar.gz';
			$this->archive = gzopen( $filepath, 'r' );
		} elseif ( 'tar.bz2' == substr( $filepath, - 7 ) ) {
			$this->type    = 'tar.bz2';
			$this->archive = bzopen( $filepath, 'r' );
		} else {
			$currentPos = ftell( $this->archive );
			fseek( $this->archive, 0, SEEK_END );
			$lastPos = ftell( $this->archive );
			fseek( $this->archive, $currentPos );

			$this->archiveSize = $lastPos;

			$this->type    = 'tar';
			$this->archive = fopen( $filepath, 'rb' );
		}
	}

	/**
	 * Close the backup archive file.
	 *
	 * @param bool $closeLog Log this action if set true.
	 */
	public function close( $closeLog = true ) {
		$this->write_chunk();
		$this->log( 'Closing archive' );

		if ( $closeLog && $this->logHandle ) {
			fclose( $this->logHandle );
		}

		if ( $this->archive ) {
			if ( 'tar.gz' == $this->type ) {
				gzclose( $this->archive );
			} elseif ( 'tar.bz2' == $this->type ) {
				bzclose( $this->archive );
			} else {
				fclose( $this->archive );
			}
		}
	}

	/**
	 * Get File content retrived by name.
	 *
	 * @param string $entryName Entry name.
	 *
	 * @return string File content.
	 */
	public function get_from_name( $entryName ) {
		if ( ! $this->archive ) {
			return false;
		}
		if ( empty( $entryName ) ) {
			return false;
		}
		$content = false;
		fseek( $this->archive, 0 );
		while ( $block = fread( $this->archive, 512 ) ) {
			$temp = unpack( 'a100name/a8mode/a8uid/a8gid/a12size/a12mtime/a8checksum/a1type/a100symlink/a6magic/a2temp/a32temp/a32temp/a8temp/a8temp/a155prefix/a12temp', $block );
			if ( 'L' == $temp['type'] ) {
				$fname          = trim( fread( $this->archive, 512 ) );
				$block          = fread( $this->archive, 512 );
				$temp           = unpack( 'a100name/a8mode/a8uid/a8gid/a12size/a12mtime/a8checksum/a1type/a100symlink/a6magic/a2temp/a32temp/a32temp/a8temp/a8temp/a155prefix/a12temp', $block );
				$temp['prefix'] = '';
				$temp['name']   = $fname;
			}
			$file = array(
				'name'     => trim( $temp['prefix'] ) . trim( $temp['name'] ),
				'stat'     => array(
					2 => $temp['mode'],
					4 => octdec( $temp['uid'] ),
					5 => octdec( $temp['gid'] ),
					7 => octdec( $temp['size'] ),
					9 => octdec( $temp['mtime'] ),
				),
				'checksum' => octdec( $temp['checksum'] ),
				'type'     => $temp['type'],
				'magic'    => $temp['magic'],
			);

			if ( 0x00000000 == $file['checksum'] ) {
				break;
			} elseif ( 'ustar' != substr( $file['magic'], 0, 5 ) ) {
				break;
			}

			$block    = substr_replace( $block, '        ', 148, 8 );
			$checksum = 0;
			for ( $i = 0; $i < 512; $i ++ ) {
				$checksum += ord( substr( $block, $i, 1 ) );
			}

			if ( 0 == $file['type'] ) {
				if ( 0 == strcmp( trim( $file['name'] ), trim( $entryName ) ) ) {
					if ( $file['stat'][7] > 0 ) {
						$content = fread( $this->archive, $file['stat'][7] );
					} else {
						$content = '';
					}
					break;
				} else {
					$bytes = $file['stat'][7] + ( ( 512 - $file['stat'][7] % 512 ) == 512 ? 0 : ( 512 - $file['stat'][7] % 512 ) );
					fseek( $this->archive, ftell( $this->archive ) + $bytes );
				}
			}

			unset( $file );
		}

		return $content;
	}

	/**
	 * Check if the file exists.
	 *
	 * @param string $entryName Entry name.
	 *
	 * @return bool Return tru if the file exists, false if it doesn't.
	 */
	public function file_exists( $entryName ) {
		if ( ! $this->archive ) {
			return false;
		}
		$entryName = untrailingslashit( $entryName );
		if ( empty( $entryName ) ) {
			return false;
		}
		fseek( $this->archive, 0 );
		while ( $block = fread( $this->archive, 512 ) ) {
			$temp = unpack( 'a100name/a8mode/a8uid/a8gid/a12size/a12mtime/a8checksum/a1type/a100symlink/a6magic/a2temp/a32temp/a32temp/a8temp/a8temp/a155prefix/a12temp', $block );
			if ( 'L' == $temp['type'] ) {
				$fname          = trim( fread( $this->archive, 512 ) );
				$block          = fread( $this->archive, 512 );
				$temp           = unpack( 'a100name/a8mode/a8uid/a8gid/a12size/a12mtime/a8checksum/a1type/a100symlink/a6magic/a2temp/a32temp/a32temp/a8temp/a8temp/a155prefix/a12temp', $block );
				$temp['prefix'] = '';
				$temp['name']   = $fname;
			}
			$file = array(
				'name'     => trim( $temp['prefix'] ) . trim( $temp['name'] ),
				'stat'     => array(
					2 => $temp['mode'],
					4 => octdec( $temp['uid'] ),
					5 => octdec( $temp['gid'] ),
					7 => octdec( $temp['size'] ),
					9 => octdec( $temp['mtime'] ),
				),
				'checksum' => octdec( $temp['checksum'] ),
				'type'     => $temp['type'],
				'magic'    => $temp['magic'],
			);

			if ( 0x00000000 == $file['checksum'] ) {
				break;
			} elseif ( 'ustar' != substr( $file['magic'], 0, 5 ) ) {
				break;
			}

			$block    = substr_replace( $block, '        ', 148, 8 );
			$checksum = 0;
			for ( $i = 0; $i < 512; $i ++ ) {
				$checksum += ord( substr( $block, $i, 1 ) );
			}

			if ( 5 == $file['type'] ) {
				if ( 0 == strcmp( trim( $file['name'] ), trim( $entryName ) ) ) {
					return true;
				}
			} elseif ( 0 == $file['type'] ) {
				if ( 0 == strcmp( trim( $file['name'] ), trim( $entryName ) ) ) {
					return true;
				} else {
					$bytes = $file['stat'][7] + ( ( 512 - $file['stat'][7] % 512 ) == 512 ? 0 : ( 512 - $file['stat'][7] % 512 ) );
					fseek( $this->archive, ftell( $this->archive ) + $bytes );
				}
			}

			unset( $file );
		}

		return false;
	}

	/**
	 * Extract backup archive file to a location.
	 *
	 * @param string $to Desired location to extract file.
	 *
	 * @return null
	 */
	public function extract_to( $to ) { // phpcs:ignore -- Current complexity is the only way to achieve desired results, pull request solutions appreciated.

		/**
		 * Global variable containing the instance of the (auto-)configured filesystem object after the filesystem "factory" has been run.
		 *
		 * @global object $wp_filesystem Filesystem object.
		 */
		global $wp_filesystem;

		$to = trailingslashit( $to );
		fseek( $this->archive, 0 );
		while ( $block = fread( $this->archive, 512 ) ) {
			$temp = unpack( 'a100name/a8mode/a8uid/a8gid/a12size/a12mtime/a8checksum/a1type/a100symlink/a6magic/a2temp/a32temp/a32temp/a8temp/a8temp/a155prefix/a12temp', $block );
			if ( 'L' == $temp['type'] ) {
				$fname          = trim( fread( $this->archive, 512 ) );
				$block          = fread( $this->archive, 512 );
				$temp           = unpack( 'a100name/a8mode/a8uid/a8gid/a12size/a12mtime/a8checksum/a1type/a100symlink/a6magic/a2temp/a32temp/a32temp/a8temp/a8temp/a155prefix/a12temp', $block );
				$temp['prefix'] = '';
				$temp['name']   = $fname;
			}
			$file = array(
				'name'     => trim( $temp['prefix'] ) . trim( $temp['name'] ),
				'stat'     => array(
					2 => $temp['mode'],
					4 => octdec( $temp['uid'] ),
					5 => octdec( $temp['gid'] ),
					7 => octdec( $temp['size'] ),
					9 => octdec( $temp['mtime'] ),
				),
				'checksum' => octdec( $temp['checksum'] ),
				'type'     => $temp['type'],
				'magic'    => $temp['magic'],
			);

			if ( 0x00000000 == $file['checksum'] ) {
				break;
			} elseif ( 'ustar' != substr( $file['magic'], 0, 5 ) ) {
				break;
			}
			$block    = substr_replace( $block, '        ', 148, 8 );
			$checksum = 0;
			for ( $i = 0; $i < 512; $i ++ ) {
				$checksum += ord( substr( $block, $i, 1 ) );
			}
			if ( 5 == $file['type'] ) {
				if ( ! is_dir( $to . $file['name'] ) ) {
					if ( ! empty( $wp_filesystem ) ) {
						$wp_filesystem->mkdir( $to . $file['name'], FS_CHMOD_DIR );
					} else {
						mkdir( $to . $file['name'], 0777, true );
					}
				}
			} elseif ( 0 == $file['type'] ) {
				if ( ! is_dir( dirname( $to . $file['name'] ) ) ) {
					if ( ! empty( $wp_filesystem ) ) {
						$wp_filesystem->mkdir( dirname( $to . $file['name'] ), FS_CHMOD_DIR );
					} else {
						mkdir( dirname( $to . $file['name'] ), 0777, true );
					}
				}

				if ( ! empty( $wp_filesystem ) && ( $file['stat'][7] < 2000000 ) ) {
					$contents    = '';
					$bytesToRead = $file['stat'][7];
					while ( $bytesToRead > 0 ) {
						$readNow      = $bytesToRead > 1024 ? 1024 : $bytesToRead;
						$contents    .= fread( $this->archive, $readNow );
						$bytesToRead -= $readNow;
					}

					$toRead = ( 512 - $file['stat'][7] % 512 ) == 512 ? 0 : ( 512 - $file['stat'][7] % 512 );
					if ( $toRead > 0 ) {
						fread( $this->archive, ( 512 - $file['stat'][7] % 512 ) == 512 ? 0 : ( 512 - $file['stat'][7] % 512 ) );
					}

					if ( 'wp-config.php' != $file['name'] ) {
						$wp_filesystem->put_contents( $to . $file['name'], $contents, FS_CHMOD_FILE );
					}
				} else {
					if ( 'wp-config.php' != $file['name'] ) {
						$new = fopen( $to . $file['name'], 'wb+' );
					} else {
						$new = false;
					}
					$bytesToRead = $file['stat'][7];
					while ( $bytesToRead > 0 ) {
						$readNow = $bytesToRead > 1024 ? 1024 : $bytesToRead;
						if ( false !== $new ) {
							fwrite( $new, fread( $this->archive, $readNow ) );
						} else {
							fread( $this->archive, $readNow );
						}
						$bytesToRead -= $readNow;
					}

					$toRead = ( 512 - $file['stat'][7] % 512 ) == 512 ? 0 : ( 512 - $file['stat'][7] % 512 );
					if ( $toRead > 0 ) {
						fread( $this->archive, ( 512 - $file['stat'][7] % 512 ) == 512 ? 0 : ( 512 - $file['stat'][7] % 512 ) );
					}
					if ( false != $new ) {
						fclose( $new );
					}
				}
			}
			unset( $file );
		}

		return null;
	}

	/**
	 * Check if block is valid.
	 *
	 * @param array $block Block of the backup file content.
	 *
	 * @return bool Return true if it's valid block, false if not.
	 */
	public function is_valid_block( $block ) {
		$test = gzinflate( $block );
		if ( false === $test ) {
			return false;
		}
		$crc      = crc32( $test );
		$crcFound = substr( $block, strlen( $block ) - 8, 4 );
		$crcFound = ( ord( $crcFound[3] ) << 24 ) + ( ord( $crcFound[2] ) << 16 ) + ( ord( $crcFound[1] ) << 8 ) + ( ord( $crcFound[0] ) );

		return $crcFound == $crc;
	}
}
