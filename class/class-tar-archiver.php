<?php

//todo: BZ2; support fseek!

class Tar_Archiver {
	const IDLE = 0;
	const APPEND = 1;
	const CREATE = 2;

	protected $excludeZip;

	protected $archive;
	protected $archivePath;
	protected $archiveSize;
	protected $lastRun = 0;

	protected $debug;

	protected $chunk = ''; //1024 * 1024 * 4
	protected $chunkSize = 4194304; //1024 * 1024 * 4

	/** @var $backup MainWP_Backup */
	protected $backup;

	protected $type;
	protected $pidFile; //filepath of pid file
	protected $pidContent; //content of pid file
	protected $pidUpdated; //last updated pid file

	protected $mode = self::IDLE;

	protected $logHandle = false;

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

	public function getExtension() {
		if ( 'tar.bz2' == $this->type ) {
			return '.tar.bz2';
		}
		if ( 'tar.gz' == $this->type ) {
			return '.tar.gz';
		}

		return '.tar';
	}

	public function zipFile( $files, $archive ) {
		$this->create( $archive );
		if ( $this->archive ) {
			foreach ( $files as $filepath ) {
				$this->addFile( $filepath, basename( $filepath ) );
			}

			$this->addData( pack( 'a1024', '' ) );
			$this->close();

			return true;
		}

		return false;
	}

	private function createPidFile( $file ) {
		if ( $this->pidFile === false ) {
			return false;
		}
		$this->pidContent = $file;

		/** @var $wp_filesystem WP_Filesystem_Base */
		global $wp_filesystem;

		$wp_filesystem->put_contents( $this->pidFile, $this->pidContent );

		$this->pidUpdated = time();

		return true;
	}

	public function updatePidFile() {
		if ( $this->pidFile === false ) {
			return false;
		}
		if ( time() - $this->pidUpdated < 40 ) {
			return false;
		}

		/** @var $wp_filesystem WP_Filesystem_Base */
		global $wp_filesystem;

		$wp_filesystem->put_contents( $this->pidFile, $this->pidContent );
		$this->pidUpdated = time();

		return true;
	}

	private function completePidFile() {
		if ( $this->pidFile === false ) {
			return false;
		}

		/** @var $wp_filesystem WP_Filesystem_Base */
		global $wp_filesystem;

		$filename = basename( $this->pidFile );
		$wp_filesystem->move( $this->pidFile, trailingslashit( dirname( $this->pidFile ) ) . substr( $filename, 0, strlen( $filename ) - 4 ) . '.done' );
		$this->pidFile = false;

		return true;
	}

	public function createFullBackup( $filepath, $excludes, $addConfig, $includeCoreFiles, $excludezip, $excludenonwp, $append = false ) {
		//$this->logHandle = fopen($filepath . ".log", "a+");
		$this->createPidFile( $filepath );

		$this->excludeZip = $excludezip;

		$this->archivePath = $filepath;

		//        if (!file_exists($filepath))
		//        {
		//            $this->limit = true;
		//        }

		if ( $append && @file_exists( $filepath ) ) { //todo: use wpFS
			$this->mode = self::APPEND;
			$this->prepareAppend( $filepath );
		} else {
			$this->mode = self::CREATE;
			$this->create( $filepath );
		}

		if ( $this->archive ) {
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

			$db_files = $this->backup->createBackupDB( dirname( $filepath ) . DIRECTORY_SEPARATOR . 'dbBackup', false, $this );

			foreach ( $db_files as $db_file ) {
				$this->addFile( $db_file, basename( WP_CONTENT_DIR ) . '/' . basename( $db_file ) );
			}

			if ( file_exists( ABSPATH . '.htaccess' ) ) {
				$this->addFile( ABSPATH . '.htaccess', 'mainwp-htaccess' );
			}

			foreach ( $nodes as $node ) {
				if ( $excludenonwp && is_dir( $node ) ) {
					if ( ! MainWP_Helper::startsWith( $node, WP_CONTENT_DIR ) && ! MainWP_Helper::startsWith( $node, ABSPATH . 'wp-admin' ) && ! MainWP_Helper::startsWith( $node, ABSPATH . WPINC ) ) {
						continue;
					}
				}

				if ( ! MainWP_Helper::inExcludes( $excludes, str_replace( ABSPATH, '', $node ) ) ) {
					if ( is_dir( $node ) ) {
						$this->addDir( $node, $excludes );
					} else if ( is_file( $node ) ) {
						$this->addFile( $node, str_replace( ABSPATH, '', $node ) );
					}
				}
			}

			if ( $addConfig ) {
				global $wpdb;
				$plugins = array();
				$dir     = WP_CONTENT_DIR . '/plugins/';
				$fh      = @opendir( $dir );
				while ( $entry = @readdir( $fh ) ) {
					if ( ! @is_dir( $dir . $entry ) ) {
						continue;
					}
					if ( ( '.' === $entry ) || ( '..' === $entry ) ) {
						continue;
					}
					$plugins[] = $entry;
				}
				@closedir( $fh );

				$themes = array();
				$dir    = WP_CONTENT_DIR . '/themes/';
				$fh     = @opendir( $dir );
				while ( $entry = @readdir( $fh ) ) {
					if ( ! @is_dir( $dir . $entry ) ) {
						continue;
					}
					if ( ( '.' == $entry ) || ( '..' == $entry ) ) {
						continue;
					}
					$themes[] = $entry;
				}
				@closedir( $fh );

				$string = base64_encode( serialize( array(
					'siteurl' => get_option( 'siteurl' ),
					'home'    => get_option( 'home' ),
					'abspath' => ABSPATH,
					'prefix'  => $wpdb->prefix,
					'lang'    => get_bloginfo("language"),
					'plugins' => $plugins,
					'themes'  => $themes,
				) ) );

				//                $configFile = dirname($filepath) . DIRECTORY_SEPARATOR . time() . 'config.txt';
				//                $fh = fopen($filepath, 'w'); //or error;
				//                dirname($filepath) . DIRECTORY_SEPARATOR
				$this->addEmptyDirectory( 'clone', 0, 0, 0, time() );
				$this->addFileFromString( 'clone/config.txt', $string );
			}

			$this->addData( pack( 'a1024', '' ) );
			$this->close();
			foreach ( $db_files as $db_file ) {
				@unlink( $db_file );
			}

			$this->completePidFile();

			return true;
		}

		return false;
	}

	public function addDir( $path, $excludes ) {
		if ( ( '.' == basename( $path ) ) || ( '..' == basename( $path ) ) ) {
			return;
		}

		$this->addEmptyDir( $path, str_replace( ABSPATH, '', $path ) );

		if ( file_exists( rtrim( $path, '/' ) . '/.htaccess' ) ) {
			$this->addFile( rtrim( $path, '/' ) . '/.htaccess', rtrim( str_replace( ABSPATH, '', $path ), '/' ) . '/mainwp-htaccess' );
		}

		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path ), RecursiveIteratorIterator::SELF_FIRST,
			RecursiveIteratorIterator::CATCH_GET_CHILD );

		if ( class_exists( 'ExampleSortedIterator' ) ) {
			$iterator = new ExampleSortedIterator( $iterator );
		}

		/** @var $path DirectoryIterator */
		foreach ( $iterator as $path ) {
			$name = $path->__toString();
			if ( ( '.' == basename( $name ) ) || ( '..' == basename( $name ) ) ) {
				continue;
			}

			if ( ! MainWP_Helper::inExcludes( $excludes, str_replace( ABSPATH, '', $name ) ) ) {
				if ( $path->isDir() ) {
					$this->addEmptyDir( $name, str_replace( ABSPATH, '', $name ) );
				} else {
					$this->addFile( $name, str_replace( ABSPATH, '', $name ) );
				}
			}
			$name = null;
			unset( $name );
		}

		$iterator = null;
		unset( $iterator );
	}

	private function addData( $data ) {
		if ( $this->debug ) {
			$this->chunk .= $data;

			if ( strlen( $this->chunk ) > $this->chunkSize ) {
				$this->writeChunk();
			}

			return;
		}

		if ( 'tar.gz' == $this->type ) {
			//if (@fwrite($this->archive, $data, strlen($data)) === false)
			if ( false === @gzwrite( $this->archive, $data, strlen( $data ) ) ) {
				throw new Exception( 'Could not write to archive' );
			}
			//@fflush($this->archive);
		} else if ( 'tar.bz2' == $this->type ) {
			if ( false === @bzwrite( $this->archive, $data, strlen( $data ) ) ) {
				throw new Exception( 'Could not write to archive' );
			}
		} else {
			if ( false === @fwrite( $this->archive, $data, strlen( $data ) ) ) {
				throw new Exception( 'Could not write to archive' );
			}
			@fflush( $this->archive );
		}
	}

	private function writeChunk() {
		$len = strlen( $this->chunk );
		if ( 0 == $len ) {
			return;
		}

		//        if ($this->cnt++ > 3)
		//        {
		//            $this->log('error?');
		//            $this->cnt = 0;
		//            throw new Exception('error!');
		//        }

		if ( 'tar.gz' == $this->type ) {
			$this->log( 'writing & flushing ' . $len );
			$this->chunk = gzencode( $this->chunk );
			if ( false === @fwrite( $this->archive, $this->chunk, strlen( $this->chunk ) ) ) {
				throw new Exception( 'Could not write to archive' );
			}
			@fflush( $this->archive );
		} else if ( 'tar.bz2' == $this->type ) {
			if ( false === @bzwrite( $this->archive, $this->chunk, strlen( $len ) ) ) {
				throw new Exception( 'Could not write to archive' );
			}
		} else {
			if ( false === @fwrite( $this->archive, $len, strlen( $len ) ) ) {
				throw new Exception( 'Could not write to archive' );
			}
			@fflush( $this->archive );
		}

		$this->chunk = '';
	}

	private function addEmptyDir( $path, $entryName ) {
		$stat = @stat( $path );

		$this->addEmptyDirectory( $entryName, $stat['mode'], $stat['uid'], $stat['gid'], $stat['mtime'] );
	}

	private function addEmptyDirectory( $entryName, $mode, $uid, $gid, $mtime ) {
		if ( $this->mode == self::APPEND ) {
			if ( $this->checkBeforeAppend( $entryName ) === true ) {
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

				$block = pack( 'a100a8a8a8a12a12a8a1a100a6a2a32a32a8a8a155a12',
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
					'' );

				$checksum = 0;
				for ( $i = 0; $i < 512; $i ++ ) {
					$checksum += ord( substr( $block, $i, 1 ) );
				}
				$checksum = pack( 'a8', sprintf( '%07o', $checksum ) );
				$block    = substr_replace( $block, $checksum, 148, 8 );

				$this->addData( $block );
				$this->addData( pack( 'a512', $entryName ) );
				$entryName = substr( $entryName, 0, 100 );
			}
		}

		$block = pack( 'a100a8a8a8a12a12a8a1a100a6a2a32a32a8a8a155a12',
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
			'' );

		$checksum = 0;
		for ( $i = 0; $i < 512; $i ++ ) {
			$checksum += ord( substr( $block, $i, 1 ) );
		}
		$checksum = pack( 'a8', sprintf( '%07o', $checksum ) );
		$block    = substr_replace( $block, $checksum, 148, 8 );

		$this->addData( $block );

		return true;
	}

	protected $block;
	protected $tempContent;
	protected $gcCnt = 0;

	//    protected $limit;
	protected $cnt = 0;

	private function addFile( $path, $entryName ) {
		if ( ( '.' == basename( $path ) ) || ( '..' == basename( $path ) ) ) {
			return false;
		}

		if ( $this->excludeZip && MainWP_Helper::endsWith( $path, '.zip' ) ) {
			$this->log( 'Skipping ' . $path );

			return false;
		}

		$this->log( 'Adding ' . $path );

		//        if ($this->limit)
		//        {
		//            $this->cnt++;
		//
		//            if ($this->cnt > 250) throw new Exception('Some error..' . $this->archivePath);
		//        }

		$this->updatePidFile();

		$rslt = false;
		if ( $this->mode == self::APPEND ) {
			$rslt = $this->checkBeforeAppend( $entryName );
			if ( true === $rslt ) {
				return true;
			}
		}

		if ( time() - $this->lastRun > 60 ) {
			@set_time_limit( 20 * 60 * 60 ); /*20 minutes*/
			$this->lastRun = time();
		}

		$this->gcCnt ++;
		if ( $this->gcCnt > 20 ) {
			if ( function_exists( 'gc_enable' ) ) {
				@gc_enable();
			}
			if ( function_exists( 'gc_collect_cycles' ) ) {
				@gc_collect_cycles();
			}
			$this->gcCnt = 0;
		}

		$stat = @stat( $path );
		$fp   = @fopen( $path, 'rb' );
		if ( ! $fp ) {
			//todo: add some error feedback!
			return;
		}

		$prefix = '';
		if ( strlen( $entryName ) > 99 ) {
			$prefix    = substr( $entryName, 0, strpos( $entryName, '/', strlen( $entryName ) - 100 ) + 1 );
			$entryName = substr( $entryName, strlen( $prefix ) );
			if ( strlen( $prefix ) > 154 || strlen( $entryName ) > 99 ) {
				$entryName = $prefix . $entryName;
				$prefix    = '';

				$block = pack( 'a100a8a8a8a12a12a8a1a100a6a2a32a32a8a8a155a12',
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
					'' );

				$checksum = 0;
				for ( $i = 0; $i < 512; $i ++ ) {
					$checksum += ord( substr( $block, $i, 1 ) );
				}
				$checksum = pack( 'a8', sprintf( '%07o', $checksum ) );
				$block    = substr_replace( $block, $checksum, 148, 8 );

				if ( ! isset( $rslt['bytesRead'] ) ) {
					$this->addData( $block );
				}
				if ( ! isset( $rslt['bytesRead'] ) ) {
					$this->addData( pack( 'a512', $entryName ) );
				}
				$entryName = substr( $entryName, 0, 100 );
			}
		}

		$this->block = pack( 'a100a8a8a8a12a12a8a1a100a6a2a32a32a8a8a155a12',
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
			'' );

		$checksum = 0;
		for ( $i = 0; $i < 512; $i ++ ) {
			$checksum += ord( substr( $this->block, $i, 1 ) );
		}
		$checksum    = pack( 'a8', sprintf( '%07o', $checksum ) );
		$this->block = substr_replace( $this->block, $checksum, 148, 8 );

		if ( ! isset( $rslt['bytesRead'] ) ) {
			$this->addData( $this->block );
		}

		if ( isset( $rslt['bytesRead'] ) ) {
			@fseek( $fp, $rslt['bytesRead'] );

			$alreadyRead = ( $rslt['bytesRead'] % 512 );
			$toRead      = 512 - $alreadyRead;
			if ( $toRead > 0 ) {
				$this->tempContent = fread( $fp, $toRead );

				$this->addData( $this->tempContent );

				$remainder = 512 - ( strlen( $this->tempContent ) + $alreadyRead );
				$this->log( 'DEBUG-Added ' . strlen( $this->tempContent ) . '(before: ' . $alreadyRead . ') will pack: ' . $remainder . ' (packed: ' . strlen( pack( 'a' . $remainder, '' ) ) );
				if ( $remainder > 0 ) {
					$this->addData( pack( 'a' . $remainder ), '' );
				}
			}
		}

		while ( ! feof( $fp ) ) {
			//0.1MB = 1024 000
			$this->tempContent = fread( $fp, 1024000 * 5 );

			$read   = strlen( $this->tempContent );
			$divide = $read % 512;

			$this->addData( substr( $this->tempContent, 0, $read - $divide ) );

			if ( $divide > 0 ) {
				$this->addData( pack( 'a512', substr( $this->tempContent, - 1 * $divide ) ) );
			}

			$this->updatePidFile();

			//            if ($this->limit) throw new Exception('Some error..' . $entryName);
		}

		@fclose( $fp );

		return true;
	}

	private function addFileFromString( $entryName, $content ) {
		$this->log( 'Add from string ' . $entryName );

		if ( $this->mode == self::APPEND ) {
			if ( $this->checkBeforeAppend( $entryName ) === true ) {
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

				$block = pack( 'a100a8a8a8a12a12a8a1a100a6a2a32a32a8a8a155a12',
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
					'' );

				$checksum = 0;
				for ( $i = 0; $i < 512; $i ++ ) {
					$checksum += ord( substr( $block, $i, 1 ) );
				}
				$checksum = pack( 'a8', sprintf( '%07o', $checksum ) );
				$block    = substr_replace( $block, $checksum, 148, 8 );

				$this->addData( $block );
				$this->addData( pack( 'a512', $entryName ) );
				$entryName = substr( $entryName, 0, 100 );
			}
		}

		$block = pack( 'a100a8a8a8a12a12a8a1a100a6a2a32a32a8a8a155a12',
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
			'' );

		$checksum = 0;
		for ( $i = 0; $i < 512; $i ++ ) {
			$checksum += ord( substr( $block, $i, 1 ) );
		}
		$checksum = pack( 'a8', sprintf( '%07o', $checksum ) );
		$block    = substr_replace( $block, $checksum, 148, 8 );

		$this->addData( $block );
		$i = 0;
		while ( ( $line = substr( $content, $i ++ * 512, 512 ) ) != '' ) {
			$this->addData( pack( 'a512', $line ) );
		}

		return true;
	}

	private function checkBeforeAppend( $entryName ) {
		$rslt = $this->isNextFile( $entryName );

		//Correct file
		if ( true === $rslt ) {
			return true;
		}

		$out = false;

		//close, reopen with append & ftruncate
		$this->close( false );
		$this->log( 'Reopen archive to append from here' );
		$this->append( $this->archivePath );
		if ( is_array( $rslt ) ) {
			if ( 'tar' == $this->type ) {
				$startOffset = $rslt['startOffset'];
				@fseek( $this->archive, $startOffset );
				@ftruncate( $this->archive, $startOffset );
			} else if ( 'tar.gz' == $this->type ) {
				$readOffset = $rslt['readOffset'];
				$bytesRead  = $rslt['bytesRead'];
				//@fseek($this->archive, $readOffset + $bytesRead);

				$out = array( 'bytesRead' => $bytesRead );
			}
		} else if ( false === $rslt ) {
			if ( 'tar' == $this->type ) {
				@fseek( $this->archive, 0, SEEK_END );
			}
		} else {
			//todo: check for tar.gz & tar!
			@fseek( $this->archive, $rslt );
			@ftruncate( $this->archive, $rslt );
		}
		$this->mode = self::CREATE;

		return $out;
	}

	/**
	 * return true: skip file
	 * return number: nothing to read, will continue with current file..
	 * return false: nothing to read, will continue with current file..
	 * exception: corrupt zip - invalid file order!
	 *
	 * return array: continue the busy directory or file..
	 *
	 * @param $entryName
	 *
	 * @return array|bool
	 * @throws Exception
	 */
	private function isNextFile( $entryName ) {
		$currentOffset = @ftell( $this->archive );
		$rslt          = array( 'startOffset' => $currentOffset );
		try {
			$block = @fread( $this->archive, 512 );

			if ( $block === false || 0 == strlen( $block ) ) {
				return $rslt;
			}

			if ( 512 != strlen( $block ) ) {
				throw new Exception( 'Invalid block found' );
			}

			$temp = unpack( 'a100name/a8mode/a8uid/a8gid/a12size/a12mtime/a8checksum/a1type/a100symlink/a6magic/a2temp/a32temp/a32temp/a8temp/a8temp/a155prefix/a12temp', $block );
			//Check for long file!!
			if ( 'L' == $temp['type'] ) {
				$fname          = trim( @fread( $this->archive, 512 ) );
				$block          = @fread( $this->archive, 512 );
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
					throw new Exception( 'Unexpected directory [' . $file['name'] . ']' );
				}
			} else if ( 0 == $file['type'] ) {
				if ( 0 == strcmp( trim( $file['name'] ), trim( $entryName ) ) ) {
					$previousFtell = @ftell( $this->archive );

					$bytes = $file['stat'][7] + ( 512 == ( 512 - $file['stat'][7] % 512 ) ? 0 : ( 512 - $file['stat'][7] % 512 ) );
					@fseek( $this->archive, @ftell( $this->archive ) + $bytes );

					$ftell = @ftell( $this->archive );
					if ( 'tar.gz' == $this->type ) {
						if ( ( false === $ftell ) || ( -1 == $ftell ) ) {
							@fseek( $this->archive, $previousFtell );

							$bytesRead   = 0;
							$bytesToRead = $file['stat'][7];

							while ( $bytesToRead > 0 ) {
								$readNow            = $bytesToRead > 1024 ? 1024 : $bytesToRead;
								$bytesCurrentlyRead = strlen( fread( $this->archive, $readNow ) );

								if ( 0 == $bytesCurrentlyRead ) {
									break;
								}

								$bytesRead += $bytesCurrentlyRead;
								$bytesToRead -= $bytesCurrentlyRead;
							}

							if ( 0 == $bytesToRead ) {
								$toRead = ( 512 - $file['stat'][7] % 512 ) == 512 ? 0 : ( 512 - $file['stat'][7] % 512 );
								if ( $toRead > 0 ) {
									$read = strlen( fread( $this->archive, $toRead ) );
									$bytesRead += $read;
								}
							}

							$rslt['bytesRead']  = $bytesRead;
							$rslt['readOffset'] = $previousFtell;

							$this->log( 'Will append this: ' . print_r( $rslt, 1 ) );

							return $rslt;
						}
					} else if ( ( 'tar' == $this->type ) && ( ( false === $ftell ) || ( -1 == $ftell ) ) ) {
						$this->log( 'Will append this: ' . print_r( $rslt, 1 ) );

						return $rslt;
					}

					$this->log( 'Skipping file [' . $file['name'] . ']' );

					return true;
				} else {
					$this->log( 'Unexpected file [' . $file['name'] . ']' );
					throw new Exception( 'Unexpected file' );
				}
			}

			$this->log( 'ERROR' );
			throw new Exception( 'Should never get here?' );
		} catch ( Exception $e ) {
			$this->log( $e->getMessage() );
			throw $e;
		}
	}

	function log( $text ) {
		if ( $this->logHandle ) {
			@fwrite( $this->logHandle, $text . "\n" );
		}
	}

	function create( $filepath ) {
		$this->log( 'Creating ' . $filepath );
		if ( $this->debug ) {
			if ( 'tar.bz2' == $this->type ) {
				$this->archive = @bzopen( $filepath, 'w' );
			} else {
				$this->archive = @fopen( $filepath, 'wb+' );
			}

			return;
		}

		if ( 'tar.gz' == $this->type ) {
			//$this->archive = @fopen('compress.zlib://' . $filepath, 'ab');
			$this->archive = @gzopen( $filepath, 'wb' );
		} else if ( 'tar.bz2' == $this->type ) {
			$this->archive = @bzopen( $filepath, 'w' );
		} else {
			$this->archive = @fopen( $filepath, 'wb+' );
		}
	}

	function append( $filepath ) {
		$this->log( 'Appending to ' . $filepath );
		if ( $this->debug ) {
			if ( 'tar.bz2' == $this->type ) {
				$this->archive = @bzopen( $filepath, 'a' );
			} else {
				$this->archive = @fopen( $filepath, 'ab+' );
			}

			return;
		}

		if ( 'tar.gz' == $this->type ) {
			//$this->archive = @fopen('compress.zlib://' . $filepath, 'ab');
			$this->archive = @gzopen( $filepath, 'ab' );
		} else if ( $this->type == 'tar.bz2' ) {
			$this->archive = @bzopen( $filepath, 'a' );
		} else {
			$this->archive = @fopen( $filepath, 'ab+' );
		}
	}

	function isOpen() {
		return ! empty( $this->archive );
	}

	function prepareAppend( $filepath ) {
		if ( $this->debug ) {
			if ( 'tar.gz' == substr( $filepath, - 6 ) ) {
				$text = chr( 31 ) . chr( 139 ) . chr( 8 ) . chr( 0 ) . chr( 0 ) . chr( 0 ) . chr( 0 ) . chr( 0 ) . chr( 0 ); //magic header!!

				//Check if valid, if not, crop to valid!
				$fh          = @fopen( $filepath, 'rb' );
				$read        = '';
				$lastCorrect = 0;
				try {
					while ( ! feof( $fh ) ) {
						$read .= fread( $fh, 1000 );
						while ( ( $pos = strpos( $read, $text, 2 ) ) !== false ) {
							for ( $i = 0; $i < 10; $i ++ ) {
								echo ord( $read[ $i ] ) . "\n";
							}

							if ( ! $this->isValidBlock( substr( $read, 10, $pos - 10 ) ) ) {
								throw new Exception( 'invalid!' );
							}

							$lastCorrect += $pos;
							$read = substr( $read, $pos );
						}
					}

					if ( ! $this->isValidBlock( substr( $read, 10 ) ) ) {
						throw new Exception( 'invalid!' );
					}

					@fclose( $fh );
				} catch ( Exception $e ) {
					@fclose( $fh );
					//reopen & truncate
					$fh = @fopen( $filepath, 'ab+' );
					@fseek( $fh, $lastCorrect );
					@ftruncate( $fh, $lastCorrect );
					@fclose( $fh );
				}
			}
		}

		$this->read( $filepath );
	}

	function read( $filepath ) {
		$this->log( 'Reading ' . $filepath );
		$this->archiveSize = false;

		if ( 'tar.gz' == substr( $filepath, - 6 ) ) {
			$this->type = 'tar.gz';
			//            $this->archive = @fopen('compress.zlib://' . $filepath, 'rb');
			$this->archive = @gzopen( $filepath, 'r' );
		} else if ( 'tar.bz2' == substr( $filepath, - 7 ) ) {
			$this->type    = 'tar.bz2';
			$this->archive = @bzopen( $filepath, 'r' );
		} else {
			$currentPos = @ftell( $this->archive );
			@fseek( $this->archive, 0, SEEK_END );
			$lastPos = @ftell( $this->archive );
			@fseek( $this->archive, $currentPos );

			$this->archiveSize = $lastPos;

			$this->type    = 'tar';
			$this->archive = @fopen( $filepath, 'rb' );
		}
	}

	function close( $closeLog = true ) {
		//Write chunk if it's not empty..
		$this->writeChunk();

		$this->log( 'Closing archive' );

		if ( $closeLog && $this->logHandle ) {
			@fclose( $this->logHandle );
		}

		if ( $this->archive ) {
			if ( 'tar.gz' == $this->type ) {
				//@fclose($this->archive);
				@gzclose( $this->archive );
			} else if ( 'tar.bz2' == $this->type ) {
				@bzclose( $this->archive );
			} else {
				@fclose( $this->archive );
			}
		}
	}

	function getFromName( $entryName ) {
		if ( ! $this->archive ) {
			return false;
		}
		if ( empty( $entryName ) ) {
			return false;
		}
		$content = false;
		@fseek( $this->archive, 0 );
		while ( $block = @fread( $this->archive, 512 ) ) {
			$temp = unpack( 'a100name/a8mode/a8uid/a8gid/a12size/a12mtime/a8checksum/a1type/a100symlink/a6magic/a2temp/a32temp/a32temp/a8temp/a8temp/a155prefix/a12temp', $block );
			//Check for long file!!
			if ( 'L' == $temp['type'] ) {
				$fname          = trim( @fread( $this->archive, 512 ) );
				$block          = @fread( $this->archive, 512 );
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

			if ( $file['checksum'] == 0x00000000 ) {
				break;
			} else if ( substr( $file['magic'], 0, 5 ) != 'ustar' ) {
				//                $this->error[] = "This script does not support extracting this type of tar file.";
				break;
			}

			$block    = substr_replace( $block, '        ', 148, 8 );
			$checksum = 0;
			for ( $i = 0; $i < 512; $i ++ ) {
				$checksum += ord( substr( $block, $i, 1 ) );
			}
			//            if ($file['checksum'] != $checksum)
			//                $this->error[] = "Could not extract from {$this->options['name']}, it is corrupt.";

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
					@fseek( $this->archive, ftell( $this->archive ) + $bytes );
				}
			}

			unset( $file );
		}

		return $content;
	}

	function file_exists( $entryName ) {
		if ( ! $this->archive ) {
			return false;
		}
		$entryName = untrailingslashit( $entryName );
		if ( empty( $entryName ) ) {
			return false;
		}
		@fseek( $this->archive, 0 );
		while ( $block = @fread( $this->archive, 512 ) ) {
			$temp = unpack( 'a100name/a8mode/a8uid/a8gid/a12size/a12mtime/a8checksum/a1type/a100symlink/a6magic/a2temp/a32temp/a32temp/a8temp/a8temp/a155prefix/a12temp', $block );
			//Check for long file!!
			if ( 'L' == $temp['type'] ) {
				$fname          = trim( @fread( $this->archive, 512 ) );
				$block          = @fread( $this->archive, 512 );
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

			if ( $file['checksum'] == 0x00000000 ) {
				break;
			} else if ( 'ustar' != substr( $file['magic'], 0, 5 ) ) {
				//                $this->error[] = "This script does not support extracting this type of tar file.";
				break;
			}

			$block    = substr_replace( $block, '        ', 148, 8 );
			$checksum = 0;
			for ( $i = 0; $i < 512; $i ++ ) {
				$checksum += ord( substr( $block, $i, 1 ) );
			}
			//            if ($file['checksum'] != $checksum)
			//                $this->error[] = "Could not extract from {$this->options['name']}, it is corrupt.";

			if ( 5 == $file['type'] ) {
				if ( 0 == strcmp( trim( $file['name'] ), trim( $entryName ) ) ) {
					return true;
				}
			} else if ( 0 == $file['type'] ) {
				if ( 0 == strcmp( trim( $file['name'] ), trim( $entryName ) ) ) {
					return true;
				} else {
					$bytes = $file['stat'][7] + ( ( 512 - $file['stat'][7] % 512 ) == 512 ? 0 : ( 512 - $file['stat'][7] % 512 ) );
					@fseek( $this->archive, ftell( $this->archive ) + $bytes );
				}
			}

			unset( $file );
		}

		return false;
	}

	function extractTo( $to ) {
		/** @var $wp_filesystem WP_Filesystem_Base */
		global $wp_filesystem;

		$to = trailingslashit( $to );
		@fseek( $this->archive, 0 );
		while ( $block = fread( $this->archive, 512 ) ) {
			$temp = unpack( 'a100name/a8mode/a8uid/a8gid/a12size/a12mtime/a8checksum/a1type/a100symlink/a6magic/a2temp/a32temp/a32temp/a8temp/a8temp/a155prefix/a12temp', $block );
			//Check for long file!!
			if ( 'L' == $temp['type'] ) {
				$fname          = trim( @fread( $this->archive, 512 ) );
				$block          = @fread( $this->archive, 512 );
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

			if ( $file['checksum'] == 0x00000000 ) {
				break;
			} else if ( 'ustar' != substr( $file['magic'], 0, 5 ) ) {
				//                $this->error[] = "This script does not support extracting this type of tar file.";
				break;
			}
			$block    = substr_replace( $block, '        ', 148, 8 );
			$checksum = 0;
			for ( $i = 0; $i < 512; $i ++ ) {
				$checksum += ord( substr( $block, $i, 1 ) );
			}
			//            if ($file['checksum'] != $checksum)
			//                $this->error[] = "Could not extract from {$this->options['name']}, it is corrupt.";
			if ( 5 == $file['type'] ) {
				if ( ! is_dir( $to . $file['name'] ) ) {
					if ( ! empty( $wp_filesystem ) ) {
						$wp_filesystem->mkdir( $to . $file['name'], FS_CHMOD_DIR );
					} else {
						mkdir( $to . $file['name'], 0777, true );
					}
				}
			} else if ( 0 == $file['type'] ) {
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
						$readNow = $bytesToRead > 1024 ? 1024 : $bytesToRead;
						$contents .= fread( $this->archive, $readNow );
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
						$new = @fopen( $to . $file['name'], 'wb+' );
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

	function isValidBlock( $block ) {
		$test = @gzinflate( $block );
		if ( false === $test ) {
			return false;
		}
		$crc      = crc32( $test );
		$crcFound = substr( $block, strlen( $block ) - 8, 4 );
		$crcFound = ( ord( $crcFound[3] ) << 24 ) + ( ord( $crcFound[2] ) << 16 ) + ( ord( $crcFound[1] ) << 8 ) + ( ord( $crcFound[0] ) );

		return $crcFound == $crc;
	}
}

if ( class_exists( 'SplHeap' ) ) {
	class ExampleSortedIterator extends SplHeap {
		public function __construct( Iterator $iterator ) {
			foreach ( $iterator as $item ) {
				$this->insert( $item );
			}
		}

		public function compare( $b, $a ) {
			$pathA    = $a->__toString();
			$pathB    = $b->__toString();
			$dirnameA = ( is_file( $pathA ) ? dirname( $pathA ) : $pathA );
			$dirnameB = ( is_file( $pathB ) ? dirname( $pathB ) : $pathB );

			//if both are in the same folder, first show the files, then the directories
			if ( dirname( $pathA ) == dirname( $pathB ) ) {
				if ( is_file( $pathA ) && ! is_file( $pathB ) ) {
					return - 1;
				} else if ( ! is_file( $pathA ) && is_file( $pathB ) ) {
					return 1;
				}

				return strcmp( $pathA, $pathB );
			} else if ( $dirnameA == $dirnameB ) {
				return strcmp( $pathA, $pathB );
			} else if ( MainWP_Helper::startsWith( $dirnameA, $dirnameB ) ) {
				return 1;
			} else if ( MainWP_Helper::startsWith( $dirnameB, $dirnameA ) ) {
				return - 1;
			} else {
				$cmp = strcmp( $dirnameA, $dirnameB );
				if ( 0 == $cmp ) {
					return strcmp( $pathA, $pathB );
				}

				return $cmp;
			}
		}
	}
}
