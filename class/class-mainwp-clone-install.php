<?php

class MainWP_Clone_Install {
	protected $file;
	public $config;
	/** @var $archiver Tar_Archiver */
	protected $archiver;

	/**
	 * Class constructor
	 *
	 * @param string $file The zip backup file path
	 */
	public function __construct( $file ) {
		require_once( ABSPATH . 'wp-admin/includes/class-pclzip.php' );

		$this->file = $file;
		if ( '.zip' === substr( $this->file, - 4 ) ) {
			$this->archiver = null;
		} else if ( '.tar.gz' === substr( $this->file, - 7 ) ) {
			$this->archiver = new Tar_Archiver( null, 'tar.gz' );
		} else if ( '.tar.bz2' === substr( $this->file, - 8 ) ) {
			$this->archiver = new Tar_Archiver( null, 'tar.bz2' );
		} else if ( '.tar' === substr( $this->file, - 4 ) ) {
			$this->archiver = new Tar_Archiver( null, 'tar' );
		}
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
		//todo: implement
		//        return function_exists('system');
		return false;
	}

	public function checkWPZip() {
		return function_exists( 'unzip_file' );
	}


	public function removeConfigFile() {
		if ( ! $this->file || ! file_exists( $this->file ) ) {
			return false;
		}

		if ( null !== $this->archiver ) {

		} else if ( $this->checkZipConsole() ) {
			//todo: implement
		} else if ( $this->checkZipSupport() ) {
			$zip    = new ZipArchive();
			$zipRes = $zip->open( $this->file );
			if ( $zipRes ) {
				$zip->deleteName( 'wp-config.php' );
				$zip->deleteName( 'clone' );
				$zip->close();

				return true;
			}

			return false;
		} else {
			//use pclzip
			$zip   = new PclZip( $this->file );
			$list  = $zip->delete( PCLZIP_OPT_BY_NAME, 'wp-config.php' );
			$list2 = $zip->delete( PCLZIP_OPT_BY_NAME, 'clone' );
			if ( 0 === $list ) {
				return false;
			}

			return true;
		}

		return false;
	}

	public function testDownload() {
		if ( ! $this->file_exists( 'wp-content/' ) ) {
			throw new Exception( __( 'This is not a full backup.', 'mainwp-child' ) );
		}
		if ( ! $this->file_exists( 'wp-admin/' ) ) {
			throw new Exception( __( 'This is not a full backup.', 'mainwp-child' ) );
		}
		if ( ! $this->file_exists( 'wp-content/dbBackup.sql' ) ) {
			throw new Exception( __( 'Database backup is missing.', 'mainwp-child' ) );
		}
	}

	private function file_exists( $file ) {
		if ( 'extracted' === $this->file ) {
			return file_get_contents( '../clone/config.txt' );
		}

		if ( ! $this->file || ! file_exists( $this->file ) ) {
			return false;
		}

		if ( null !== $this->archiver ) {
			if ( ! $this->archiver->isOpen() ) {
				$this->archiver->read( $this->file );
			}

			return $this->archiver->file_exists( $file );
		} else if ( $this->checkZipConsole() ) {
			//todo: implement
		} else if ( $this->checkZipSupport() ) {
			$zip    = new ZipArchive();
			$zipRes = $zip->open( $this->file );
			if ( $zipRes ) {
				$content = $zip->locateName( $file );
				$zip->close();

				return false !== $content;
			}

			return false;
		} else {
			return true;
		}

		return false;
	}

	public function readConfigurationFile() {
		$configContents = $this->getConfigContents();
		if ( false === $configContents ) {
			throw new Exception( __( 'Cant read configuration file from the backup.', 'mainwp-child' ) );
		}
		$this->config = maybe_unserialize( base64_decode( $configContents ) );

		if ( isset( $this->config['plugins'] ) ) {
			MainWP_Helper::update_option( 'mainwp_temp_clone_plugins', $this->config['plugins'] );
		}
		if ( isset( $this->config['themes'] ) ) {
			MainWP_Helper::update_option( 'mainwp_temp_clone_themes', $this->config['themes'] );
		}
	}

	public function setConfig( $key, $val ) {
		$this->config[ $key ] = $val;
	}

	public function testDatabase() {
		$link = @MainWP_Child_DB::connect( $this->config['dbHost'], $this->config['dbUser'], $this->config['dbPass'] );
		if ( ! $link ) {
			throw new Exception( __( 'Invalid database host or user/password.', 'mainwp-child' ) );
		}

		$db_selected = @MainWP_Child_DB::select_db( $this->config['dbName'], $link );
		if ( ! $db_selected ) {
			throw new Exception( __( 'Invalid database name.', 'mainwp-child' ) );
		}
	}

	public function clean() {
		$files = glob( WP_CONTENT_DIR . '/dbBackup*.sql' );
		foreach ( $files as $file ) {
			@unlink( $file );
		}
		if ( file_exists( ABSPATH . 'clone/config.txt' ) ) {
			@unlink( ABSPATH . 'clone/config.txt' );
		}
		if ( MainWP_Helper::is_dir_empty( ABSPATH . 'clone' ) ) {
			@rmdir( ABSPATH . 'clone' );
		}

		try {
			$dirs      = MainWP_Helper::getMainWPDir( 'backup', false );
			$backupdir = $dirs[0];

			$files = glob( $backupdir . '*' );
			foreach ( $files as $file ) {
				if ( MainWP_Helper::isArchive( $file ) ) {
					@unlink( $file );
				}
			}
		} catch ( Exception $e ) {

		}
	}

	public function updateWPConfig() {
		$wpConfig = file_get_contents( ABSPATH . 'wp-config.php' );
		$wpConfig = $this->replaceVar( 'table_prefix', $this->config['prefix'], $wpConfig );
		if ( isset( $this->config['lang'] ) ) {
			$wpConfig = $this->replaceDefine( 'WPLANG', $this->config['lang'], $wpConfig );
		}
		file_put_contents( ABSPATH . 'wp-config.php', $wpConfig );
	}

	public function update_option( $name, $value ) {
		/** @var $wpdb wpdb */
		global $wpdb;

		$var = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM ' . $this->config['prefix'] . 'options WHERE option_name = %s', $name) );
		if ( null === $var ) {
			$wpdb->query( $wpdb->prepare( 'INSERT INTO ' . $this->config['prefix'] . 'options (`option_name`, `option_value`) VALUES (%s, "' . MainWP_Child_DB::real_escape_string( maybe_serialize( $value ) ) . '")', $name) );
		} else {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . $this->config['prefix'] . 'options SET option_value = "' . MainWP_Child_DB::real_escape_string( maybe_serialize( $value ) ) . '" WHERE option_name = %s', $name) );
		}
	}

	public function install() {
		/** @var $wpdb wpdb */
		global $wpdb;

		$table_prefix = $this->config['prefix'];
		$home         = get_option( 'home' );
		$site_url     = get_option( 'siteurl' );
		// Install database
		define( 'WP_INSTALLING', true );
		define( 'WP_DEBUG', false );
		$query     = '';
		$tableName = '';
		$wpdb->query( 'SET foreign_key_checks = 0' );

		$files = glob( WP_CONTENT_DIR . '/dbBackup*.sql' );
		foreach ( $files as $file ) {
			$handle = @fopen( $file, 'r' );

			$lastRun = 0;
			if ( $handle ) {
				$readline = '';
				while ( ( $line = fgets( $handle, 81920 ) ) !== false ) {
					if ( time() - $lastRun > 20 ) {
						@set_time_limit( 0 ); //reset timer..
						$lastRun = time();
					}

					$readline .= $line;
					if ( ! stristr( $line, ";\n" ) && ! feof( $handle ) ) {
						continue;
					}

					$splitLine       = explode( ";\n", $readline );
					$splitLineLength = count( $splitLine );
					for ( $i = 0; $i < $splitLineLength - 1; $i ++ ) {
						$wpdb->query( $splitLine[ $i ] );
					}

					$readline = $splitLine[ count( $splitLine ) - 1 ];
				}

				if ( trim( $readline ) != '' ) {
					$wpdb->query( $readline );
				}

				if ( ! feof( $handle ) ) {
					throw new Exception( __( 'Error: unexpected end of file for database.', 'mainwp-child' ) );
				}
				fclose( $handle );
			}
		}

		$tables    = array();
		$tables_db = $wpdb->get_results( 'SHOW TABLES FROM `' . DB_NAME . '`', ARRAY_N );

		foreach ( $tables_db as $curr_table ) {
			// fix for more table prefix in one database
			if ( ( strpos( $curr_table[0], $wpdb->prefix ) !== false ) || ( strpos( $curr_table[0], $table_prefix ) !== false ) ) {
				$tables[] = $curr_table[0];
			}
		}
		// Replace importance data first so if other replace failed, the website still work
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . $table_prefix . 'options SET option_value = %s WHERE option_name = "siteurl"', $site_url ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . $table_prefix . 'options SET option_value = %s WHERE option_name = "home"', $home ) );
		// Replace others
		$this->icit_srdb_replacer( $wpdb->dbh, $this->config['home'], $home, $tables );
		$this->icit_srdb_replacer( $wpdb->dbh, $this->config['siteurl'], $site_url, $tables );

		$wpdb->query( 'SET foreign_key_checks = 1' );

		return true;
	}

	protected function recalculateSerializedLengths( $pObject ) {
		return preg_replace_callback( '|s:(\d+):"(.*?)";|', array(
			$this,
			'recalculateSerializedLengths_callback',
		), $pObject );
	}

	protected function recalculateSerializedLengths_callback( $matches ) {
		return 's:' . strlen( $matches[2] ) . ':"' . $matches[2] . '";';
	}

	/**
	 * Check value to find if it was serialized.
	 *
	 * If $data is not an string, then returned value will always be false.
	 * Serialized data is always a string.
	 *
	 * @since 2.0.5
	 *
	 * @param mixed $data Value to check to see if was serialized.
	 *
	 * @return bool False if not serialized and true if it was.
	 */
	function is_serialized( $data ) {
		// if it isn't a string, it isn't serialized
		if ( ! is_string( $data ) ) {
			return false;
		}
		$data = trim( $data );
		if ( 'N;' === $data ) {
			return true;
		}
		$length = strlen( $data );
		if ( $length < 4 ) {
			return false;
		}
		if ( ':' !== $data[1] ) {
			return false;
		}
		$lastc = $data[ $length - 1 ];
		if ( ';' !== $lastc && '}' !== $lastc ) {
			return false;
		}
		$token = $data[0];
		switch ( $token ) {
			case 's' :
				if ( '"' !== $data[ $length - 2 ] ) {
					return false;
				}
			case 'a' :
			case 'O' :
				return (bool) preg_match( "/^{$token}:[0-9]+:/s", $data );
			case 'b' :
			case 'i' :
			case 'd' :
				return (bool) preg_match( "/^{$token}:[0-9.E-]+;\$/", $data );
		}

		return false;
	}

	public function cleanUp() {
		// Clean up!
		$files = glob( '../dbBackup*.sql' );
		foreach ( $files as $file ) {
			@unlink( $file );
		}
	}

	public function getConfigContents() {
		if ( 'extracted' === $this->file ) {
			return file_get_contents( '../clone/config.txt' );
		}

		if ( ! $this->file || ! file_exists( $this->file ) ) {
			return false;
		}

		if ( null !== $this->archiver ) {
			if ( ! $this->archiver->isOpen() ) {
				$this->archiver->read( $this->file );
			}
			$content = $this->archiver->getFromName( 'clone/config.txt' );

			return $content;
		} else {
			if ( $this->checkZipConsole() ) {
				//todo: implement
			} else if ( $this->checkZipSupport() ) {
				$zip    = new ZipArchive();
				$zipRes = $zip->open( $this->file );
				if ( $zipRes ) {
					$content = $zip->getFromName( 'clone/config.txt' );
					//                $zip->deleteName('clone/config.txt');
					//                $zip->deleteName('clone/');
					$zip->close();

					return $content;
				}

				return false;
			} else {
				//use pclzip
				$zip     = new PclZip( $this->file );
				$content = $zip->extract( PCLZIP_OPT_BY_NAME, 'clone/config.txt',
				PCLZIP_OPT_EXTRACT_AS_STRING );
				if ( ! is_array( $content ) || ! isset( $content[0]['content'] ) ) {
					return false;
				}

				return $content[0]['content'];
			}
		}

		return false;
	}

	/**
	 * Extract backup
	 *
	 * @return bool
	 */
	public function extractBackup() {
		if ( ! $this->file || ! file_exists( $this->file ) ) {
			return false;
		}

		if ( null !== $this->archiver ) {
			if ( ! $this->archiver->isOpen() ) {
				$this->archiver->read( $this->file );
			}

			return $this->archiver->extractTo( ABSPATH );
		} else if ( ( filesize( $this->file ) >= 50000000 ) && $this->checkWPZip() ) {
			return $this->extractWPZipBackup();
		} else if ( $this->checkZipConsole() ) {
			return $this->extractZipConsoleBackup();
		} else if ( $this->checkZipSupport() ) {
			return $this->extractZipBackup();
		} else if ( ( filesize( $this->file ) < 50000000 ) && $this->checkWPZip() ) {
			return $this->extractWPZipBackup();
		} else {
			return $this->extractZipPclBackup();
		}
	}

	/**
	 * Extract backup using default PHP zip library
	 *
	 * @return bool
	 */
	public function extractZipBackup() {
		$zip    = new ZipArchive();
		$zipRes = $zip->open( $this->file );
		if ( $zipRes ) {
			@$zip->extractTo( ABSPATH );
			$zip->close();

			return true;
		}

		return false;
	}

	public function extractWPZipBackup() {
		MainWP_Helper::getWPFilesystem();
		global $wp_filesystem;

		$tmpdir = ABSPATH;
		if ( ( 'ftpext' === $wp_filesystem->method ) && defined( 'FTP_BASE' ) ) {
			$ftpBase = FTP_BASE;
			$ftpBase = trailingslashit( $ftpBase );
			$tmpdir  = str_replace( ABSPATH, $ftpBase, $tmpdir );
		}

		unzip_file( $this->file, $tmpdir );

		return true;
	}

	public function extractZipPclBackup() {
		$zip = new PclZip( $this->file );
		if ( 0 === $zip->extract( PCLZIP_OPT_PATH, ABSPATH, PCLZIP_OPT_REPLACE_NEWER ) ) {
			return false;
		}
		if ( $zip->error_code !== PCLZIP_ERR_NO_ERROR ) {
			throw new Exception( $zip->errorInfo( true ) );
		}

		return true;
	}

	/**
	 * Extract backup using zip on console
	 *
	 * @return bool
	 */
	public function extractZipConsoleBackup() {
		//todo implement
		//system('zip');
		return false;
	}

	/**
	 * Replace define statement to work with wp-config.php
	 *
	 * @param string $constant The constant name
	 * @param string $value The new value
	 * @param string $content The PHP file content
	 *
	 * @return string Replaced define statement with new value
	 */
	protected function replaceDefine( $constant, $value, $content ) {
		return preg_replace( '/(define *\( *[\'"]' . $constant . '[\'"] *, *[\'"])(.*?)([\'"] *\))/is', '${1}' . $value . '${3}', $content );
	}

	/**
	 * Replace variable value to work with wp-config.php
	 *
	 * @param string $varname The variable name
	 * @param string $value The new value
	 * @param string $content The PHP file content
	 *
	 * @return string Replaced variable value with new value
	 */
	protected function replaceVar( $varname, $value, $content ) {
		return preg_replace( '/(\$' . $varname . ' *= *[\'"])(.*?)([\'"] *;)/is', '${1}' . $value . '${3}', $content );
	}

	function recurse_chmod( $mypath, $arg ) {
		$d = opendir( $mypath );
		while ( ( $file = readdir( $d ) ) !== false ) {
			if ( '.' !== $file && '..' !== $file ) {
				$typepath = $mypath . '/' . $file;
				if ( 'dir' === filetype( $typepath ) ) {
					recurse_chmod( $typepath, $arg );
				}
				chmod( $typepath, $arg );
			}
		}
	}


	/**
	 * The main loop triggered in step 5. Up here to keep it out of the way of the
	 * HTML. This walks every table in the db that was selected in step 3 and then
	 * walks every row and column replacing all occurences of a string with another.
	 * We split large tables into 50,000 row blocks when dealing with them to save
	 * on memmory consumption.
	 *
	 * @param mysql $connection The db connection object
	 * @param string $search What we want to replace
	 * @param string $replace What we want to replace it with.
	 * @param array $tables The tables we want to look at.
	 *
	 * @return array    Collection of information gathered during the run.
	 */
	function icit_srdb_replacer( $connection, $search = '', $replace = '', $tables = array() ) {
		global $guid, $exclude_cols;

		$report = array(
			'tables'  => 0,
			'rows'    => 0,
			'change'  => 0,
			'updates' => 0,
			'start'   => microtime(),
			'end'     => microtime(),
			'errors'  => array(),
		);
		if ( is_array( $tables ) && ! empty( $tables ) ) {
			foreach ( $tables as $table ) {
				$report['tables'] ++;

				$columns = array();

				// Get a list of columns in this table
				$fields = MainWP_Child_DB::_query( 'DESCRIBE ' . $table, $connection );
				while ( $column = MainWP_Child_DB::fetch_array( $fields ) ) {
					$columns[ $column['Field'] ] = 'PRI' === $column['Key'] ? true : false;
				}

				// Count the number of rows we have in the table if large we'll split into blocks, This is a mod from Simon Wheatley
				$row_count   = MainWP_Child_DB::_query( 'SELECT COUNT(*) as count FROM ' . $table, $connection ); // to fix bug
				$rows_result = MainWP_Child_DB::fetch_array( $row_count );
				$row_count   = $rows_result['count'];
				if ( 0 === $row_count ) {
					continue;
				}

				$page_size = 50000;
				$pages     = ceil( $row_count / $page_size );
				for ( $page = 0; $page < $pages; $page ++ ) {
					$current_row = 0;
					$start       = $page * $page_size;
					$end         = $start + $page_size;
					// Grab the content of the table
					$data = MainWP_Child_DB::_query( sprintf( 'SELECT * FROM %s LIMIT %d, %d', $table, $start, $end ), $connection );
					if ( ! $data ) {
						$report['errors'][] = MainWP_Child_DB::error();
					}

					while ( $row = MainWP_Child_DB::fetch_array( $data ) ) {

						$report['rows'] ++; // Increment the row counter
						$current_row ++;

						$update_sql = array();
						$where_sql  = array();
						$upd        = false;

						foreach ( $columns as $column => $primary_key ) {
							if ( 1 === $guid && in_array( $column, $exclude_cols ) ) {
								continue;
							}

							$edited_data = $data_to_fix = $row[ $column ];
							// Run a search replace on the data that'll respect the serialisation.
							$edited_data = $this->recursive_unserialize_replace( $search, $replace, $data_to_fix );
							// Something was changed
							if ( $edited_data !== $data_to_fix ) {
								$report['change'] ++;
								$update_sql[] = $column . ' = "' . MainWP_Child_DB::real_escape_string( $edited_data ) . '"';
								$upd          = true;
							}

							if ( $primary_key ) {
								$where_sql[] = $column . ' = "' . MainWP_Child_DB::real_escape_string( $data_to_fix ) . '"';
							}
						}

						if ( $upd && ! empty( $where_sql ) ) {
							$sql    = 'UPDATE ' . $table . ' SET ' . implode( ', ', $update_sql ) . ' WHERE ' . implode( ' AND ', array_filter( $where_sql ) );
							$result = MainWP_Child_DB::_query( $sql, $connection );
							if ( ! $result ) {
								$report['errors'][] = MainWP_Child_DB::error();
							} else {
								$report['updates'] ++;
							}
						} elseif ( $upd ) {
							$report['errors'][] = sprintf( '"%s" has no primary key, manual change needed on row %s.', $table, $current_row );
						}
					}
				}
			}
		}
		$report['end'] = microtime();

		return $report;
	}

	/**
	 * Take a serialised array and unserialise it replacing elements as needed and
	 * unserialising any subordinate arrays and performing the replace on those too.
	 *
	 * @param string $from String we're looking to replace.
	 * @param string $to What we want it to be replaced with
	 * @param array $data Used to pass any subordinate arrays back to in.
	 * @param bool $serialised Does the array passed via $data need serialising.
	 *
	 * @return array    The original array with all elements replaced as needed.
	 */
    
    /* Fixed serialize issue */
	function recursive_unserialize_replace( $from = '', $to = '', $data = '', $serialised = false ) {

		// some unseriliased data cannot be re-serialised eg. SimpleXMLElements
		try {

			if ( is_string( $data ) && is_serialized( $data ) && !is_serialized_string( $data ) && ( $unserialized = @unserialize( $data ) ) !== false ) {
				$data = $this->recursive_unserialize_replace( $from, $to, $unserialized, true );
			} elseif ( is_array( $data ) ) {
				$_tmp = array();
				foreach ( $data as $key => $value ) {
					$_tmp[ $key ] = $this->recursive_unserialize_replace( $from, $to, $value, false );
				}

				$data = $_tmp;
				unset( $_tmp );
			} elseif ( is_object( $data ) ) {
				$_tmp = $data;
                $props = get_object_vars( $data );
				foreach ( $props as $key => $value ) {
					$_tmp->{$key} = $this->recursive_unserialize_replace( $from, $to, $value, false );
				}

				$data = $_tmp;
				unset( $_tmp );
			}  elseif (is_serialized_string($data) && is_serialized($data)) {
                // TODO: apply solution like phpmyadmin project have!
				if ( ($data = @unserialize( $data )) !== false ) {
					$data = str_replace( $from, $to, $data );
                    $data = serialize( $data );
				}
			} else {
				if ( is_string( $data ) ) {
					$data = str_replace( $from, $to, $data );
				}
			}

			if ( $serialised ) {
				return serialize( $data );
			}
		} catch ( Exception $error ) {

		}

		return $data;
	}
}
