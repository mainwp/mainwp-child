<?php

class MainWP_Backup {
	protected static $instance = null;
	protected $excludeZip;
	protected $zip;
	protected $zipArchiveFileCount;
	protected $zipArchiveSizeCount;
	protected $zipArchiveFileName;
	protected $file_descriptors;
	protected $loadFilesBeforeZip;

	protected $timeout;
	protected $lastRun;

	/**
	 * @var Tar_Archiver
	 */
	protected $archiver = null;

	public static function get() {
		if ( null === self::$instance ) {
			self::$instance = new MainWP_Backup();
		}

		return self::$instance;
	}

	/**
	 * Create full backup
	 */
	public function createFullBackup( $excludes, $filePrefix = '', $addConfig = false, $includeCoreFiles = false, $file_descriptors = 0, $fileSuffix = false, $excludezip = false, $excludenonwp = false, $loadFilesBeforeZip = true, $ext = 'zip', $pid = false, $append = false ) {
		$this->file_descriptors   = $file_descriptors;
		$this->loadFilesBeforeZip = $loadFilesBeforeZip;

		$dirs      = MainWP_Helper::getMainWPDir( 'backup' );
		$backupdir = $dirs[0];
		if ( ! defined( 'PCLZIP_TEMPORARY_DIR' ) ) {
			define( 'PCLZIP_TEMPORARY_DIR', $backupdir );
		}

		if ( false !== $pid ) {
			$pid = trailingslashit( $backupdir ) . 'backup-' . $pid . '.pid';
		}

		//Verify if another backup is running, if so, return an error
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
			$ext            = $this->archiver->getExtension();
		}

		//        throw new Exception('Test 1 2 : ' . print_r($append,1));
		if ( ( false !== $fileSuffix ) && ! empty( $fileSuffix ) ) {
			$file = $fileSuffix . ( true === $append ? '' : $ext ); //Append already contains extension!
		} else {
			$file = 'backup-' . $filePrefix . $timestamp . $ext;
		}
		$filepath = $backupdir . $file;
		$fileurl  = $file;

		//        if (!$append)
		//        {
		//            if ($dh = opendir($backupdir))
		//            {
		//                while (($file = readdir($dh)) !== false)
		//                {
		//                    if ($file != '.' && $file != '..' && preg_match('/(.*).(zip|tar|tar.gz|tar.bz2|pid|done)$/', $file))
		//                    {
		//                        @unlink($backupdir . $file);
		//                    }
		//                }
		//                closedir($dh);
		//            }
		//        }

		if ( ! $addConfig ) {
			if ( ! in_array( str_replace( ABSPATH, '', WP_CONTENT_DIR ), $excludes ) && ! in_array( 'wp-admin', $excludes ) && ! in_array( WPINC, $excludes ) ) {
				$addConfig        = true;
				$includeCoreFiles = true;
			}
		}

		$this->timeout = 20 * 60 * 60; /*20 minutes*/
		$mem           = '512M';
		// @codingStandardsIgnoreStart
		@ini_set( 'memory_limit', $mem );
		@set_time_limit( $this->timeout );
		@ini_set( 'max_execution_time', $this->timeout );
		// @codingStandardsIgnoreEnd

		if ( null !== $this->archiver ) {
			$success = $this->archiver->createFullBackup( $filepath, $excludes, $addConfig, $includeCoreFiles, $excludezip, $excludenonwp, $append );
		} else if ( $this->checkZipSupport() ) {
			$success = $this->createZipFullBackup( $filepath, $excludes, $addConfig, $includeCoreFiles, $excludezip, $excludenonwp );
		} else if ( $this->checkZipConsole() ) {
			$success = $this->createZipConsoleFullBackup( $filepath, $excludes, $addConfig, $includeCoreFiles, $excludezip, $excludenonwp );
		} else {
			$success = $this->createZipPclFullBackup2( $filepath, $excludes, $addConfig, $includeCoreFiles, $excludezip, $excludenonwp );
		}

		return ( $success ) ? array(
			'timestamp' => $timestamp,
			'file'      => $fileurl,
			'filesize'  => filesize( $filepath ),
		) : false;
	}

	public function zipFile( $files, $archive ) {
		$this->timeout = 20 * 60 * 60; /*20 minutes*/
		$mem           = '512M';
		// @codingStandardsIgnoreStart
		@ini_set( 'memory_limit', $mem );
		@set_time_limit( $this->timeout );
		@ini_set( 'max_execution_time', $this->timeout );
		// @codingStandardsIgnoreEnd

		if ( !is_array( $files ) ) {
			$files = array ($files );
		}

		if ( null !== $this->archiver ) {
			$success = $this->archiver->zipFile( $files, $archive );
		} else if ( $this->checkZipSupport() ) {
			$success = $this->_zipFile( $files, $archive );
		} else if ( $this->checkZipConsole() ) {
			$success = $this->_zipFileConsole( $files, $archive );
		} else {
			$success = $this->_zipFilePcl( $files, $archive );
		}

		return $success;
	}

	function _zipFile( $files, $archive ) {
		$this->zip                 = new ZipArchive();
		$this->zipArchiveFileCount = 0;
		$this->zipArchiveSizeCount = 0;

		$zipRes = $this->zip->open( $archive, ZipArchive::CREATE );
		if ( $zipRes ) {
			foreach ( $files as $file ) {
				$this->addFileToZip( $file, basename( $file ) );
			}

			return $this->zip->close();
		}

		return false;
	}

	function _zipFileConsole( $files, $archive ) {
		return false;
	}

	public function _zipFilePcl( $files, $archive ) {
		//Zip this backup folder..
		require_once( ABSPATH . 'wp-admin/includes/class-pclzip.php' );
		$this->zip = new PclZip( $archive );

		$error = false;
		foreach ( $files as $file ) {
			if ( 0 === ( $rslt = $this->zip->add( $file, PCLZIP_OPT_REMOVE_PATH, dirname( $file ) ) ) ) {
				$error = true;
			}
		}

		return !$error;
	}

	/**
	 * Check for default PHP zip support
	 *
	 * @return bool
	 */
	public function checkZipSupport() {
		return class_exists( 'ZipArchive' );
	}

	/**
	 * Check if we could run zip on console
	 *
	 * @return bool
	 */
	public function checkZipConsole() {
		return false;
		//        return function_exists('system');
	}

	/**
	 * Create full backup using default PHP zip library
	 *
	 * @param string $filepath File path to create
	 *
	 * @return bool
	 */
	public function createZipFullBackup( $filepath, $excludes, $addConfig, $includeCoreFiles, $excludezip, $excludenonwp ) {
		$this->excludeZip          = $excludezip;
		$this->zip                 = new ZipArchive();
		$this->zipArchiveFileCount = 0;
		$this->zipArchiveSizeCount = 0;
		$this->zipArchiveFileName  = $filepath;
		$zipRes                    = $this->zip->open( $filepath, ZipArchive::CREATE );
		if ( $zipRes ) {
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
					if ( MainWP_Helper::startsWith( $node, ABSPATH . WPINC ) ) {
						unset( $nodes[ $key ] );
					} else if ( MainWP_Helper::startsWith( $node, ABSPATH . basename( admin_url( '' ) ) ) ) {
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

			$db_files = $this->createBackupDB( dirname( $filepath ) . DIRECTORY_SEPARATOR . 'dbBackup' );
			foreach ( $db_files as $db_file ) {
				$this->addFileToZip( $db_file, basename( WP_CONTENT_DIR ) . '/' . basename( $db_file ) );
			}

			if ( file_exists( ABSPATH . '.htaccess' ) ) {
				$this->addFileToZip( ABSPATH . '.htaccess', 'mainwp-htaccess' );
			}

			foreach ( $nodes as $node ) {
				if ( $excludenonwp && is_dir( $node ) ) {
					if ( ! MainWP_Helper::startsWith( $node, WP_CONTENT_DIR ) && ! MainWP_Helper::startsWith( $node, ABSPATH . 'wp-admin' ) && ! MainWP_Helper::startsWith( $node, ABSPATH . WPINC ) ) {
						continue;
					}
				}

				if ( ! MainWP_Helper::inExcludes( $excludes, str_replace( ABSPATH, '', $node ) ) ) {
					if ( is_dir( $node ) ) {
						$this->zipAddDir( $node, $excludes );
					} else if ( is_file( $node ) ) {
						$this->addFileToZip( $node, str_replace( ABSPATH, '', $node ) );
					}
				}
			}

			if ( $addConfig ) {
				global $wpdb;
				$plugins = array();
				$dir     = WP_CONTENT_DIR . '/plugins/';
				// @codingStandardsIgnoreStart
				$fh      = @opendir( $dir );
				while ( $entry = @readdir( $fh ) ) {
					if ( ! @is_dir( $dir . $entry ) ) {
						continue;
					}
					if ( ( $entry == '.' ) || ( $entry == '..' ) ) {
						continue;
					}
					$plugins[] = $entry;
				}
				@closedir( $fh );
				// @codingStandardsIgnoreEnd

				$themes = array();
				$dir    = WP_CONTENT_DIR . '/themes/';
				// @codingStandardsIgnoreStart
				$fh     = @opendir( $dir );
				while ( $entry = @readdir( $fh ) ) {
					if ( ! @is_dir( $dir . $entry ) ) {
						continue;
					}
					if ( ( $entry == '.' ) || ( $entry == '..' ) ) {
						continue;
					}
					$themes[] = $entry;
				}
				@closedir( $fh );
				// @codingStandardsIgnoreEnd

				$string = base64_encode( serialize( array(
					'siteurl' => get_option( 'siteurl' ),
					'home'    => get_option( 'home' ),
					'abspath' => ABSPATH,
					'prefix'  => $wpdb->prefix,
					'lang'    => defined( 'WPLANG' ) ? WPLANG : '',
					'plugins' => $plugins,
					'themes'  => $themes,
				) ) );

				$this->addFileFromStringToZip( 'clone/config.txt', $string );
			}

			$return = $this->zip->close();
			foreach ( $db_files as $db_file ) {
				@unlink( $db_file );
			}

			return true;
		}

		return false;
	}

	/**
	 * Create full backup using pclZip library
	 *
	 * @param string $filepath File path to create
	 *
	 * @return bool
	 */
	public function createZipPclFullBackup( $filepath, $excludes, $addConfig, $includeCoreFiles ) {
		require_once( ABSPATH . 'wp-admin/includes/class-pclzip.php' );
		$this->zip = new PclZip( $filepath );
		$nodes     = glob( ABSPATH . '*' );
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
				if ( MainWP_Helper::startsWith( $node, ABSPATH . WPINC ) ) {
					unset( $nodes[ $key ] );
				} else if ( MainWP_Helper::startsWith( $node, ABSPATH . basename( admin_url( '' ) ) ) ) {
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

		$db_files = $this->createBackupDB( dirname( $filepath ) . DIRECTORY_SEPARATOR . 'dbBackup' );
		$error = false;
		foreach ( $db_files as $db_file ) {
			if ( 0 === ( $rslt = $this->zip->add( $db_file, PCLZIP_OPT_REMOVE_PATH, dirname( $db_file ), PCLZIP_OPT_ADD_PATH, basename( WP_CONTENT_DIR ) ) ) ) {
				$error = true;
			}
		}

		foreach ( $db_files as $db_file ) {
			@unlink( $db_file );
		}
		if ( ! $error ) {
			foreach ( $nodes as $node ) {
				if ( null === $excludes || ! in_array( str_replace( ABSPATH, '', $node ), $excludes ) ) {
					if ( is_dir( $node ) ) {
						if ( ! $this->pclZipAddDir( $node, $excludes ) ) {
							$error = true;
							break;
						}
					} else if ( is_file( $node ) ) {
						if ( 0 === ( $rslt = $this->zip->add( $node, PCLZIP_OPT_REMOVE_PATH, ABSPATH ) ) ) {
							$error = true;
							break;
						}
					}
				}
			}
		}

		if ( $addConfig ) {
			global $wpdb;
			$string = base64_encode( serialize( array(
				'siteurl' => get_option( 'siteurl' ),
				'home'    => get_option( 'home' ),
				'abspath' => ABSPATH,
				'prefix'  => $wpdb->prefix,
				'lang'    => WPLANG,
			) ) );

			$this->addFileFromStringToPCLZip( 'clone/config.txt', $string, $filepath );
		}

		if ( $error ) {
			// @codingStandardsIgnoreStart
			@unlink( $filepath );
			// @codingStandardsIgnoreEnd

			return false;
		}

		return true;
	}

	function copy_dir( $nodes, $excludes, $backupfolder, $excludenonwp, $root ) {
		if ( ! is_array( $nodes ) ) {
			return;
		}

		foreach ( $nodes as $node ) {
			if ( $excludenonwp && is_dir( $node ) ) {
				if ( ! MainWP_Helper::startsWith( $node, WP_CONTENT_DIR ) && ! MainWP_Helper::startsWith( $node, ABSPATH . 'wp-admin' ) && ! MainWP_Helper::startsWith( $node, ABSPATH . WPINC ) ) {
					continue;
				}
			}

			if ( ! MainWP_Helper::inExcludes( $excludes, str_replace( ABSPATH, '', $node ) ) ) {
				if ( is_dir( $node ) ) {
					if ( ! file_exists( str_replace( ABSPATH, $backupfolder, $node ) ) ) {
						// @codingStandardsIgnoreStart
						@mkdir( str_replace( ABSPATH, $backupfolder, $node ) );
						// @codingStandardsIgnoreEnd
					}

					$newnodes = glob( $node . DIRECTORY_SEPARATOR . '*' );
					$this->copy_dir( $newnodes, $excludes, $backupfolder, $excludenonwp, false );
					unset( $newnodes );
				} else if ( is_file( $node ) ) {
					if ( $this->excludeZip && MainWP_Helper::endsWith( $node, '.zip' ) ) {
						continue;
					}

					// @codingStandardsIgnoreStart
					@copy( $node, str_replace( ABSPATH, $backupfolder, $node ) );
					// @codingStandardsIgnoreEnd
				}
			}
		}
	}

	public function createZipPclFullBackup2( $filepath, $excludes, $addConfig, $includeCoreFiles, $excludezip, $excludenonwp ) {
		//Create backup folder
		$backupFolder = dirname( $filepath ) . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR;
		// @codingStandardsIgnoreStart
		@mkdir( $backupFolder );
		// @codingStandardsIgnoreEnd

		//Create DB backup
		$db_files = $this->createBackupDB( $backupFolder . 'dbBackup' );

		//Copy installation to backup folder
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
				if ( MainWP_Helper::startsWith( $node, ABSPATH . WPINC ) ) {
					unset( $nodes[ $key ] );
				} else if ( MainWP_Helper::startsWith( $node, ABSPATH . basename( admin_url( '' ) ) ) ) {
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
		// to fix bug wrong folder
		// @codingStandardsIgnoreStart

		foreach ( $db_files as $db_file ) {
			@copy( $db_file, $backupFolder . basename( WP_CONTENT_DIR ) . '/' . basename( $db_file ) );
			@unlink( $db_file );
		}
		// @codingStandardsIgnoreEnd
		unset( $nodes );

		//Zip this backup folder..
		require_once( ABSPATH . 'wp-admin/includes/class-pclzip.php' );
		$this->zip = new PclZip( $filepath );
		$this->zip->create( $backupFolder, PCLZIP_OPT_REMOVE_PATH, $backupFolder );
		if ( $addConfig ) {
			global $wpdb;
			$string = base64_encode( serialize( array(
				'siteurl' => get_option( 'siteurl' ),
				'home'    => get_option( 'home' ),
				'abspath' => ABSPATH,
				'prefix'  => $wpdb->prefix,
				'lang'    => WPLANG,
			) ) );

			$this->addFileFromStringToPCLZip( 'clone/config.txt', $string, $filepath );
		}
		//Remove backup folder
		MainWP_Helper::delete_dir( $backupFolder );

		return true;
	}

	/**
	 * Recursive add directory for default PHP zip library
	 */
	public function zipAddDir( $path, $excludes ) {
		$this->zip->addEmptyDir( str_replace( ABSPATH, '', $path ) );

		if ( file_exists( rtrim( $path, '/' ) . '/.htaccess' ) ) {
			$this->addFileToZip( rtrim( $path, '/' ) . '/.htaccess', rtrim( str_replace( ABSPATH, '', $path ), '/' ) . '/mainwp-htaccess' );
		}

		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path ), RecursiveIteratorIterator::SELF_FIRST );

		foreach ( $iterator as $path ) {
			$name = $path->__toString();
			if ( ( '.' === basename( $name ) ) || ( '..' === basename( $name ) ) ) {
				continue;
			}

			if ( ! MainWP_Helper::inExcludes( $excludes, str_replace( ABSPATH, '', $name ) ) ) {
				if ( $path->isDir() ) {
					$this->zipAddDir( $name, $excludes );
				} else {
					$this->addFileToZip( $name, str_replace( ABSPATH, '', $name ) );
				}
			}
			$name = null;
			unset( $name );
		}

		$iterator = null;
		unset( $iterator );

		//        $nodes = glob(rtrim($path, '/') . '/*');
		//        if (empty($nodes)) return true;
		//
		//        foreach ($nodes as $node)
		//        {
		//            if (!MainWP_Helper::inExcludes($excludes, str_replace(ABSPATH, '', $node)))
		//            {
		//                if (is_dir($node))
		//                {
		//                    $this->zipAddDir($node, $excludes);
		//                }
		//                else if (is_file($node))
		//                {
		//                    $this->addFileToZip($node, str_replace(ABSPATH, '', $node));
		//                }
		//            }
		//        }
	}

	public function pclZipAddDir( $path, $excludes ) {
		$error = false;
		$nodes = glob( rtrim( $path, '/' ) . '/*' );
		if ( empty( $nodes ) ) {
			return true;
		}

		foreach ( $nodes as $node ) {
			if ( null === $excludes || ! in_array( str_replace( ABSPATH, '', $node ), $excludes ) ) {
				if ( is_dir( $node ) ) {
					if ( ! $this->pclZipAddDir( $node, $excludes ) ) {
						$error = true;
						break;
					}
				} else if ( is_file( $node ) ) {
					if ( 0 === ( $rslt = $this->zip->add( $node, PCLZIP_OPT_REMOVE_PATH, ABSPATH ) ) ) {
						$error = true;
						break;
					}
				}
			}
		}

		return ! $error;
	}

	function addFileFromStringToZip( $file, $string ) {
		return $this->zip->addFromString( $file, $string );
	}

	public function addFileFromStringToPCLZip( $file, $string, $filepath ) {
		$file        = preg_replace( '/(?:\.|\/)*(.*)/', '$1', $file );
		$localpath   = dirname( $file );
		$tmpfilename = dirname( $filepath ) . '/' . basename( $file );
		if ( false !== file_put_contents( $tmpfilename, $string ) ) {
			$this->zip->delete( PCLZIP_OPT_BY_NAME, $file );
			$add = $this->zip->add( $tmpfilename,
				PCLZIP_OPT_REMOVE_PATH, dirname( $filepath ),
			PCLZIP_OPT_ADD_PATH, $localpath );
			unlink( $tmpfilename );
			if ( ! empty( $add ) ) {
				return true;
			}
		}

		return false;
	}

	protected $gcCnt = 0;
	protected $testContent;

	function addFileToZip( $path, $zipEntryName ) {
		if ( time() - $this->lastRun > 20 ) {
			// @codingStandardsIgnoreStart
			@set_time_limit( $this->timeout );
			// @codingStandardsIgnoreEnd
			$this->lastRun = time();
		}

		if ( $this->excludeZip && MainWP_Helper::endsWith( $path, '.zip' ) ) {
			return false;
		}

		// this would fail with status ZIPARCHIVE::ER_OPEN
		// after certain number of files is added since
		// ZipArchive internally stores the file descriptors of all the
		// added files and only on close writes the contents to the ZIP file
		// see: http://bugs.php.net/bug.php?id=40494
		// and: http://pecl.php.net/bugs/bug.php?id=9443
		// return $zip->addFile( $path, $zipEntryName );

		$this->zipArchiveSizeCount += filesize( $path );
		$this->gcCnt ++;

		//5 mb limit!
		if ( ! $this->loadFilesBeforeZip || ( filesize( $path ) > 5 * 1024 * 1024 ) ) {
			$this->zipArchiveFileCount ++;
			$added = $this->zip->addFile( $path, $zipEntryName );
		} else {
			$this->zipArchiveFileCount ++;

			$this->testContent = file_get_contents( $path );
			if ( $this->testContent === false ) {
				return false;
			}
			$added = $this->zip->addFromString( $zipEntryName, $this->testContent );
		}

		if ( $this->gcCnt > 20 ) {
			// @codingStandardsIgnoreStart
			if ( function_exists( 'gc_enable' ) ) {
				@gc_enable();
			}
			if ( function_exists( 'gc_collect_cycles' ) ) {
				@gc_collect_cycles();
			}
			// @codingStandardsIgnoreEnd
			$this->gcCnt = 0;
		}

		//Over limits?
		if ( ( ( $this->file_descriptors > 0 ) && ( $this->zipArchiveFileCount > $this->file_descriptors ) ) ) { // || $this->zipArchiveSizeCount >= (31457280 * 2))
			$this->zip->close();
			$this->zip = null;
			unset( $this->zip );
			// @codingStandardsIgnoreStart
			if ( function_exists( 'gc_enable' ) ) {
				@gc_enable();
			}
			if ( function_exists( 'gc_collect_cycles' ) ) {
				@gc_collect_cycles();
			}
			// @codingStandardsIgnoreEnd
			$this->zip = new ZipArchive();
			$this->zip->open( $this->zipArchiveFileName );
			$this->zipArchiveFileCount = 0;
			$this->zipArchiveSizeCount = 0;
		}

		return $added;
	}

	public function createZipConsoleFullBackup( $filepath, $excludes, $addConfig, $includeCoreFiles, $excludezip, $excludenonwp ) {
		// @TODO to work with 'zip' from system if PHP Zip library not available
		//system('zip');
		return false;
	}

	public function createBackupDB( $filepath_prefix, $archiveExt = false, &$archiver = null ) {
		$timeout = 20 * 60 * 60; //20minutes
		// @codingStandardsIgnoreStart
		@set_time_limit( $timeout );
		@ini_set( 'max_execution_time', $timeout );
		$mem = '512M';
		@ini_set( 'memory_limit', $mem );
		// @codingStandardsIgnoreEnd

		/** @var $wpdb wpdb */
		global $wpdb;

		$db_files = array();
		//Get all the tables
		$tables_db = $wpdb->get_results( 'SHOW TABLES FROM `' . DB_NAME . '`', ARRAY_N );
		foreach ( $tables_db as $curr_table ) {
			if ( null !== $archiver ) {
				$archiver->updatePidFile();
			}

			$table = $curr_table[0];

			$currentfile = $filepath_prefix . '-' . MainWP_Helper::sanitize_filename( $table ) . '.sql';
			$db_files[] = $currentfile;
			if ( file_exists( $currentfile ) ) {
				continue;
			}
			$fh = fopen( $currentfile . '.tmp', 'w' ); //or error;

			fwrite( $fh, "\n\n" . 'DROP TABLE IF EXISTS ' . $table . ';' );
			//todo fix this
			//$table_create = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %s', $table ), ARRAY_N );
			$table_create = $wpdb->get_row( 'SHOW CREATE TABLE ' . $table, ARRAY_N );
			fwrite( $fh, "\n" . $table_create[1] . ";\n\n" );

			// @codingStandardsIgnoreStart
			$rows = @MainWP_Child_DB::_query( 'SELECT * FROM ' . $table, $wpdb->dbh );
			// @codingStandardsIgnoreEnd

			if ( $rows ) {
				$i            = 0;
				$table_insert = 'INSERT INTO `' . $table . '` VALUES (';

				// @codingStandardsIgnoreStart
				while ( $row = @MainWP_Child_DB::fetch_array( $rows ) ) {
					// @codingStandardsIgnoreEnd
					$query = $table_insert;
					foreach ( $row as $value ) {
						if ( $value === null ) {
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

			if ( $this->zipFile( $db_files, $archivefilePath ) && file_exists( $archivefilePath ) ) {
				foreach ( $db_files as $db_file ) {
					@unlink( $db_file );
				}
			} else {
				//todo: throw exception!
			}
		}


		return ( false !== $archiveExt ? array( 'filepath' => $archivefilePath ) : $db_files );
	}
}
