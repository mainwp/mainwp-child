<?php

namespace MainWP\Child;

// phpcs:disable WordPress.WP.AlternativeFunctions -- to custom functions.

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
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Create full backup
	 */
	public function create_full_backup( $excludes, $filePrefix = '', $addConfig = false, $includeCoreFiles = false, $file_descriptors = 0, $fileSuffix = false, $excludezip = false, $excludenonwp = false, $loadFilesBeforeZip = true, $ext = 'zip', $pid = false, $append = false ) {
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

	public function m_zip_file_console( $files, $archive ) {
		return false;
	}

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
	 * Check for default PHP zip support
	 *
	 * @return bool
	 */
	public function check_zip_support() {
		return class_exists( '\ZipArchive' );
	}

	/**
	 * Check if we could run zip on console
	 *
	 * @return bool
	 */
	public function check_zip_console() {
		return false;
	}

	/**
	 * Create full backup using default PHP zip library
	 *
	 * @param string $filepath File path to create
	 *
	 * @return bool
	 */
	public function create_zip_full_backup( $filepath, $excludes, $addConfig, $includeCoreFiles, $excludezip, $excludenonwp ) {
		$this->excludeZip          = $excludezip;
		$this->zip                 = new \ZipArchive();
		$this->zipArchiveFileCount = 0;
		$this->zipArchiveSizeCount = 0;
		$this->zipArchiveFileName  = $filepath;
		$zipRes                    = $this->zip->open( $filepath, \ZipArchive::CREATE );
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
				global $wpdb;
				$plugins = array();
				$dir     = WP_CONTENT_DIR . '/plugins/';
				// phpcs:disable
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
				// phpcs:enable

				$themes = array();
				$dir    = WP_CONTENT_DIR . '/themes/';
				// phpcs:disable
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
				// phpcs:enable

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

			$return = $this->zip->close();
			foreach ( $db_files as $db_file ) {
				unlink( $db_file );
			}

			return true;
		}

		return false;
	}

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
	 * Recursive add directory for default PHP zip library
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

	public function pcl_zip_add_dir( $path, $excludes ) {
		$error = false;
		$nodes = glob( rtrim( $path, '/' ) . '/*' );
		if ( empty( $nodes ) ) {
			return true;
		}

		foreach ( $nodes as $node ) {
			if ( null === $excludes || ! in_array( str_replace( ABSPATH, '', $node ), $excludes, true ) ) {
				if ( is_dir( $node ) ) {
					if ( ! $this->pcl_zip_add_dir( $node, $excludes ) ) {
						$error = true;
						break;
					}
				} elseif ( is_file( $node ) ) {
					$rslt = $this->zip->add( $node, PCLZIP_OPT_REMOVE_PATH, ABSPATH );
					if ( 0 === $rslt ) {
						$error = true;
						break;
					}
				}
			}
		}

		return ! $error;
	}

	public function add_file_from_string_to_zip( $file, $string ) {
		return $this->zip->addFromString( $file, $string );
	}

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

	protected $gcCnt = 0;
	protected $testContent;

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

	public function create_zip_console_full_backup( $filepath, $excludes, $addConfig, $includeCoreFiles, $excludezip, $excludenonwp ) {
		return false;
	}

	public function create_backup_db( $filepath_prefix, $archiveExt = false, &$archiver = null ) {
		
		$timeout = 20 * 60 * 60;
		$mem = '512M';
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
