<?php
/**
 * MainWP Clone Installer.
 *
 * This file handles installing a cloned child site.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

// phpcs:disable WordPress.WP.AlternativeFunctions, Generic.Metrics.CyclomaticComplexity -- Required to achieve desired results, pull request solutions appreciated.

/**
 * Class MainWP_Clone_Install
 *
 * This file handles installing a cloned child site.
 */
class MainWP_Clone_Install {

	/**
	 * Public static variable to hold the single instance of the class.
	 *
	 * @var mixed Default null
	 */
	protected static $instance = null;

	/**
	 * The zip backup file path.
	 *
	 * @var string
	 */
	protected $file;

	/**
	 * Clone configuration settings.
	 *
	 * @var array
	 */
	public $config;

	/**
	 * Tar archiver.
	 *
	 * @var object
	 */
	protected $archiver;

	/**
	 * MainWP_Clone_Install constructor.
	 *
	 * Run any time class is called.
	 *
	 * @param string $file Archive file.
	 *
	 * @uses \MainWP\Child\Tar_Archiver()
	 */
	public function __construct( $file = '' ) {
		require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';

		$this->file = $file;
		if ( '.zip' === substr( $this->file, - 4 ) ) {
			$this->archiver = null;
		} elseif ( '.tar.gz' === substr( $this->file, - 7 ) ) {
			$this->archiver = new Tar_Archiver( null, 'tar.gz' );
		} elseif ( '.tar.bz2' === substr( $this->file, - 8 ) ) {
			$this->archiver = new Tar_Archiver( null, 'tar.bz2' );
		} elseif ( '.tar' === substr( $this->file, - 4 ) ) {
			$this->archiver = new Tar_Archiver( null, 'tar' );
		}
	}

	/**
	 * Create a public static instance of MainWP_Clone_Install.
	 *
	 * @return MainWP_Clone_Install
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Check for default PHP zip support.
	 *
	 * @return bool true|false.
	 */
	public function check_zip_support() {
		return class_exists( '\ZipArchive' );
	}

	/**
	 * Check if we could run zip on console.
	 *
	 * @return bool true|false.
	 */
	public function check_zip_console() {
		return false;
	}

	/**
	 * Check if unzip_file function exists.
	 *
	 * @return bool true|false.
	 */
	public function check_wp_zip() {
		return function_exists( '\unzip_file' );
	}

	/**
	 * Remove wp-config.php file.
	 *
	 * @return bool true|false.
	 */
	public function remove_config_file() {
		if ( ! $this->file || ! file_exists( $this->file ) ) {
			return false;
		}

		if ( null !== $this->archiver ) {
			return false;
		} elseif ( $this->check_zip_console() ) {
			return false;
		} elseif ( $this->check_zip_support() ) {
			$zip    = new \ZipArchive();
			$zipRes = $zip->open( $this->file );
			if ( $zipRes ) {
				$zip->deleteName( 'wp-config.php' );
				$zip->deleteName( 'clone' );
				$zip->close();

				return true;
			}

			return false;
		} else {
			$zip   = new \PclZip( $this->file );
			$list  = $zip->delete( PCLZIP_OPT_BY_NAME, 'wp-config.php' );
			$list2 = $zip->delete( PCLZIP_OPT_BY_NAME, 'clone' );
			if ( 0 === $list ) {
				return false;
			}

			return true;
		}

		return false;
	}

	/**
	 * Test the download.
	 *
	 * @throws \Exception Error message.
	 */
	public function test_download() {
		if ( ! $this->file_exists( 'wp-content/' ) ) {
			throw new \Exception( esc_html__( 'This is not a full backup.', 'mainwp-child' ) );
		}
		if ( ! $this->file_exists( 'wp-admin/' ) ) {
			throw new \Exception( esc_html__( 'This is not a full backup.', 'mainwp-child' ) );
		}
		if ( ! $this->file_exists( 'wp-content/dbBackup.sql' ) ) {
			throw new \Exception( esc_html__( 'Database backup is missing.', 'mainwp-child' ) );
		}
	}

	/**
	 * Check if clone config.txt exists.
	 *
	 * @param string $file Config.txt file path.
	 *
	 * @return bool|string False or True on success. Return config.txt content on true.
	 */
	private function file_exists( $file ) {
		if ( 'extracted' === $this->file ) {
			return file_get_contents( '../clone/config.txt' );
		}

		if ( ! $this->file || ! file_exists( $this->file ) ) {
			return false;
		}

		if ( null !== $this->archiver ) {
			if ( ! $this->archiver->is_open() ) {
				$this->archiver->read( $this->file );
			}

			return $this->archiver->file_exists( $file );
		} elseif ( $this->check_zip_console() ) {
			return false;
		} elseif ( $this->check_zip_support() ) {
			$zip    = new \ZipArchive();
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

	/**
	 * Read configuration file.
	 *
	 * @throws \Exception Error message on failure.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::update_option()
	 */
	public function read_configuration_file() {
		$configContents = $this->get_config_contents();
		if ( false === $configContents ) {
			throw new \Exception( esc_html__( 'Cant read configuration file from the backup.', 'mainwp-child' ) );
		}
		$this->config = json_decode( $configContents, 1 );
		if ( isset( $this->config['plugins'] ) ) {
			MainWP_Helper::update_option( 'mainwp_temp_clone_plugins', $this->config['plugins'] );
		}
		if ( isset( $this->config['themes'] ) ) {
			MainWP_Helper::update_option( 'mainwp_temp_clone_themes', $this->config['themes'] );
		}
	}

	/**
	 * Clean file structure after installation.
	 *
	 * @uses \MainWP\Child\MainWP_Clone::is_archive()
	 * @uses \MainWP\Child\MainWP_Helper::is_dir_empty()
	 * @uses \MainWP\Child\MainWP_Helper::get_mainwp_dir()
	 */
	public function clean() {
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

		try {
			$dirs      = MainWP_Helper::get_mainwp_dir( 'backup', false );
			$backupdir = $dirs[0];

			$files = glob( $backupdir . '*' );
			foreach ( $files as $file ) {
				if ( MainWP_Clone::is_archive( $file ) ) {
					unlink( $file );
				}
			}
		} catch ( \Exception $e ) {
			// ok!
		}
	}

	/**
	 * Update wp-config.php file.
	 */
	public function update_wp_config() {
		$wpConfig = file_get_contents( ABSPATH . 'wp-config.php' );
		$wpConfig = $this->replace_var( 'table_prefix', $this->config['prefix'], $wpConfig );
		if ( isset( $this->config['lang'] ) ) {
			$wpConfig = $this->replace_define( 'WPLANG', $this->config['lang'], $wpConfig );
		}
		file_put_contents( ABSPATH . 'wp-config.php', $wpConfig );
	}

	/**
	 * Update DB options by name.
	 *
	 * @param string $name  Option name.
	 * @param string $value Option value to update.
	 *
	 * @uses \MainWP\Child\MainWP_Child_DB::real_escape_string()
	 */
	public function update_option( $name, $value ) {

		/**
		 * Object, providing access to the WordPress database.
		 *
		 * @global $wpdb WordPress Database instance.
		 */
		global $wpdb;

		$var = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM ' . $this->config['prefix'] . 'options WHERE option_name = %s', $name ) ); // phpcs:ignore -- safe query.
		if ( null === $var ) {
			$wpdb->query( $wpdb->prepare( 'INSERT INTO ' . $this->config['prefix'] . 'options (`option_name`, `option_value`) VALUES (%s, %s)', $name, MainWP_Child_DB::real_escape_string( maybe_serialize( $value ) ) ) ); // phpcs:ignore -- safe query.
		} else {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . $this->config['prefix'] . 'options SET option_value = %s WHERE option_name = %s', MainWP_Child_DB::real_escape_string( maybe_serialize( $value ) ), $name ) );  // phpcs:ignore -- safe query.
		}
	}

	/**
	 * Database Installation.
	 *
	 * @return bool true|false.
	 * @throws \Exception Error message on failure.
	 */
	public function install() {

		/**
		 * Object, providing access to the WordPress database.
		 *
		 * @global $wpdb WordPress Database instance.
		 */
		global $wpdb;

		$table_prefix = $this->config['prefix'];
		$home         = get_option( 'home' );
		$site_url     = get_option( 'siteurl' );

		// Install database!
		/**
		 * Defines whether WP is being installed.
		 *
		 * @const ( string ) Default: true
		 * @source https://code-reference.mainwp.com/classes/MainWP.Child.MainWP_Clone_Install.html
		 */
		define( 'WP_INSTALLING', true );

		/**
		 * Defines if WP is debug mode.
		 *
		 * @const ( string ) Default: true
		 * @source https://code-reference.mainwp.com/classes/MainWP.Child.MainWP_Clone_Install.html
		 */
		define( 'WP_DEBUG', false );

		$query     = '';
		$tableName = '';
		$wpdb->query( 'SET foreign_key_checks = 0' );

		$files = glob( WP_CONTENT_DIR . '/dbBackup*.sql' );
		foreach ( $files as $file ) {
			$handle = fopen( $file, 'r' );

			$lastRun = 0;
			if ( $handle ) {
				$readline = '';
				while ( ( $line = fgets( $handle, 81920 ) ) !== false ) {
					if ( time() - $lastRun > 20 ) {
						set_time_limit( 0 ); // reset timer..
						$lastRun = time();
					}

					$readline .= $line;
					if ( ! stristr( $line, ";\n" ) && ! feof( $handle ) ) {
						continue;
					}

					$splitLine       = explode( ";\n", $readline );
					$splitLineLength = count( $splitLine );
					for ( $i = 0; $i < $splitLineLength - 1; $i ++ ) {
						$wpdb->query( $splitLine[ $i ] ); // phpcs:ignore -- safe query.
					}

					$readline = $splitLine[ count( $splitLine ) - 1 ];
				}

				if ( trim( $readline ) != '' ) {
					$wpdb->query( $readline ); // phpcs:ignore -- safe query.
				}

				if ( ! feof( $handle ) ) {
					throw new \Exception( esc_html__( 'Error: unexpected end of file for database.', 'mainwp-child' ) );
				}
				fclose( $handle );
			}
		}

		$tables    = array();
		$tables_db = $wpdb->get_results( 'SHOW TABLES FROM `' . DB_NAME . '`', ARRAY_N ); // phpcs:ignore -- safe query.

		foreach ( $tables_db as $curr_table ) {
			// fix for more table prefix in one database.
			if ( ( false !== strpos( $curr_table[0], $wpdb->prefix ) ) || ( false !== strpos( $curr_table[0], $table_prefix ) ) ) {
				$tables[] = $curr_table[0];
			}
		}
		// Replace importance data first so if other replace failed, the website still work.
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . $table_prefix . 'options SET option_value = %s WHERE option_name = "siteurl"', $site_url ) ); //phpcs:ignore -- safe query.
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . $table_prefix . 'options SET option_value = %s WHERE option_name = "home"', $home ) ); //phpcs:ignore -- safe query.
		$this->icit_srdb_replacer( $wpdb->dbh, $this->config['home'], $home, $tables );
		$this->icit_srdb_replacer( $wpdb->dbh, $this->config['siteurl'], $site_url, $tables );

		$wpdb->query( 'SET foreign_key_checks = 1' );

		return true;
	}

	/**
	 * Get config contents.
	 *
	 * @return bool|false|mixed|string
	 */
	public function get_config_contents() {
		if ( 'extracted' === $this->file ) {
			return file_get_contents( '../clone/config.txt' );
		}

		if ( ! $this->file || ! file_exists( $this->file ) ) {
			return false;
		}

		if ( null !== $this->archiver ) {
			if ( ! $this->archiver->is_open() ) {
				$this->archiver->read( $this->file );
			}
			$content = $this->archiver->get_from_name( 'clone/config.txt' );

			return $content;
		} else {

			if ( $this->check_zip_console() ) {
				return false;
			} elseif ( $this->check_zip_support() ) {
				$zip    = new \ZipArchive();
				$zipRes = $zip->open( $this->file );
				if ( $zipRes ) {
					$content = $zip->getFromName( 'clone/config.txt' );
					$zip->close();

					return $content;
				}

				return false;
			} else {
				$zip     = new \PclZip( $this->file );
				$content = $zip->extract( PCLZIP_OPT_BY_NAME, 'clone/config.txt', PCLZIP_OPT_EXTRACT_AS_STRING );
				if ( ! is_array( $content ) || ! isset( $content[0]['content'] ) ) {
					return false;
				}

				return $content[0]['content'];
			}
		}

		return false;
	}

	/**
	 * Extract backup file.
	 *
	 * @return bool|null true or null.
	 * @throws \Exception Error message on failure.
	 */
	public function extract_backup() {
		if ( ! $this->file || ! file_exists( $this->file ) ) {
			return false;
		}

		if ( null !== $this->archiver ) {
			if ( ! $this->archiver->is_open() ) {
				$this->archiver->read( $this->file );
			}
			return $this->archiver->extract_to( ABSPATH );
		} elseif ( ( filesize( $this->file ) >= 50000000 ) && $this->check_wp_zip() ) {
			return $this->extract_wp_zip_backup();
		} elseif ( $this->check_zip_console() ) {
			return $this->extract_zip_console_backup();
		} elseif ( $this->check_zip_support() ) {
			return $this->extract_zip_backup();
		} elseif ( ( filesize( $this->file ) < 50000000 ) && $this->check_wp_zip() ) {
			return $this->extract_wp_zip_backup();
		} else {
			return $this->extract_zip_pcl_backup();
		}
	}

	/**
	 * Extract backup using default PHP zip library.
	 *
	 * @return bool true|false.
	 */
	public function extract_zip_backup() {
		$zip    = new \ZipArchive();
		$zipRes = $zip->open( $this->file );
		if ( $zipRes ) {
			$zip->extract_to( ABSPATH );
			$zip->close();

			return true;
		}

		return false;
	}

	/**
	 * Extract with unzip_file.
	 *
	 * @return bool true|false.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::get_wp_filesystem()
	 */
	public function extract_wp_zip_backup() {
		MainWP_Helper::get_wp_filesystem();

		/**
		 * Global variable containing the instance of the (auto-)configured filesystem object after the filesystem "factory" has been run.
		 *
		 * @global object $wp_filesystem Filesystem object.
		 */
		global $wp_filesystem;

		$tmpdir = ABSPATH;
		if ( ( 'ftpext' === $wp_filesystem->method ) && defined( 'FTP_BASE' ) ) {
			$ftpBase = FTP_BASE;
			$ftpBase = trailingslashit( $ftpBase );
			$tmpdir  = str_replace( ABSPATH, $ftpBase, $tmpdir );
		}

		\unzip_file( $this->file, $tmpdir );

		return true;
	}

	/**
	 * Extract PCLZIP.
	 *
	 * @return bool true|false.
	 * @throws \Exception Error on failure.
	 */
	public function extract_zip_pcl_backup() {
		$zip = new \PclZip( $this->file );
		if ( 0 === $zip->extract( PCLZIP_OPT_PATH, ABSPATH, PCLZIP_OPT_REPLACE_NEWER ) ) {
			return false;
		}
		if ( PCLZIP_ERR_NO_ERROR !== $zip->error_code ) {
			throw new \Exception( $zip->errorInfo( true ) );
		}

		return true;
	}

	/**
	 * Extract backup using zip on console.
	 *
	 * @return bool true|false.
	 */
	public function extract_zip_console_backup() {
		return false;
	}

	/**
	 * Replace define statement to work with wp-config.php.
	 *
	 * @param string $constant The constant name.
	 * @param string $value The new value.
	 * @param string $content The PHP file content.
	 *
	 * @return string Replaced define statement with new value.
	 */
	protected function replace_define( $constant, $value, $content ) {
		return preg_replace( '/(define *\( *[\'"]' . $constant . '[\'"] *, *[\'"])(.*?)([\'"] *\))/is', '${1}' . $value . '${3}', $content );
	}

	/**
	 * Replace variable value to work with wp-config.php.
	 *
	 * @param string $varname The variable name.
	 * @param string $value The new value.
	 * @param string $content The PHP file content.
	 *
	 * @return string Replaced variable value with new value.
	 */
	protected function replace_var( $varname, $value, $content ) {
		return preg_replace( '/(\$' . $varname . ' *= *[\'"])(.*?)([\'"] *;)/is', '${1}' . $value . '${3}', $content );
	}

	/**
	 * Recursively chmod file structure.
	 *
	 * @param string $mypath Path to files.
	 * @param string $arg    chmod arguments.
	 */
	public function recurse_chmod( $mypath, $arg ) {
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
	 * walks every row and column replacing all occurrences of a string with another.
	 * We split large tables into 50,000 row blocks when dealing with them to save
	 * on memory consumption.
	 *
	 * @param mysql  $connection The db connection object.
	 * @param string $search     What we want to replace.
	 * @param string $replace    What we want to replace it with.
	 * @param array  $tables     The tables we want to look at.
	 *
	 * @return array Collection of information gathered during the run.
	 *
	 * @uses \MainWP\Child\MainWP_Child_DB::to_query()
	 * @uses \MainWP\Child\MainWP_Child_DB::fetch_array()
	 * @uses \MainWP\Child\MainWP_Child_DB::error()
	 * @uses \MainWP\Child\MainWP_Child_DB::real_escape_string()
	 */
	public function icit_srdb_replacer( $connection, $search = '', $replace = '', $tables = array() ) {

		/**
		 * Globally Unique Identifier.
		 *
		 * @global string $guid Globally Unique Identifier.
		 */
		global $guid;

		/**
		 * Excluded clumns array.
		 *
		 * @global array $exclude_cols Excluded clumns array.
		 */
		global $exclude_cols;

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

				// Get a list of columns in this table.
				$fields = MainWP_Child_DB::to_query( 'DESCRIBE ' . $table, $connection );
				while ( $column = MainWP_Child_DB::fetch_array( $fields ) ) {
					$columns[ $column['Field'] ] = 'PRI' === $column['Key'] ? true : false;
				}

				// Count the number of rows we have in the table if large we'll split into blocks, This is a mod from Simon Wheatley.
				$row_count   = MainWP_Child_DB::to_query( 'SELECT COUNT(*) as count FROM ' . $table, $connection );
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
					// Grab the content of the table.
					$data = MainWP_Child_DB::to_query( sprintf( 'SELECT * FROM %s LIMIT %d, %d', $table, $start, $end ), $connection );
					if ( ! $data ) {
						$report['errors'][] = MainWP_Child_DB::error();
					}

					while ( $row = MainWP_Child_DB::fetch_array( $data ) ) {

						$report['rows'] ++; // Increment the row counter.
						$current_row ++;

						$update_sql = array();
						$where_sql  = array();
						$upd        = false;

						foreach ( $columns as $column => $primary_key ) {
							if ( 1 === $guid && in_array( $column, $exclude_cols ) ) {
								continue;
							}

							$edited_data = $row[ $column ];
							$data_to_fix = $edited_data;
							// Run a search replace on the data that'll respect the serialisation.
							$edited_data = $this->recursive_unserialize_replace( $search, $replace, $data_to_fix );
							// Something was changed.
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
							$result = MainWP_Child_DB::to_query( $sql, $connection );
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
	 * Take a serialised array and un-serialize it replacing elements as needed and
	 * un-serializing any subordinate arrays and performing the replace on those too.
	 *
	 * @param string $from String we're looking to replace.
	 * @param string $to What we want it to be replaced with.
	 * @param array  $data Used to pass any subordinate arrays back.
	 * @param bool   $serialised Does the array passed via $data need serialising.
	 *
	 * @return array The original array with all elements replaced as needed.
	 */
	public function recursive_unserialize_replace( $from = '', $to = '', $data = '', $serialised = false ) {

		// some unseriliased data cannot be re-serialised eg. SimpleXMLElements.
		try {
			$unserialized = ( is_string( $data ) && is_serialized( $data ) ) ? unserialize( $data ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			if ( is_string( $data ) && is_serialized( $data ) && ! is_serialized_string( $data ) && false !== $unserialized ) {
				$data = $this->recursive_unserialize_replace( $from, $to, $unserialized, true );
			} elseif ( is_array( $data ) ) {
				$_tmp = array();
				foreach ( $data as $key => $value ) {
					$_tmp[ $key ] = $this->recursive_unserialize_replace( $from, $to, $value, false );
				}
				$data = $_tmp;
				unset( $_tmp );
			} elseif ( is_object( $data ) ) {
				$_tmp     = $data;
				$cls_name = get_class( $data );
				// to fix: The script tried to modify a property on an incomplete object.
				if ( '__PHP_Incomplete_Class' !== $cls_name ) {
					$props = get_object_vars( $data );
					foreach ( $props as $key => $value ) {
						$_tmp->{$key} = $this->recursive_unserialize_replace( $from, $to, $value, false );
					}
				}
				$data = $_tmp;
				unset( $_tmp );
			} elseif ( is_serialized_string( $data ) && is_serialized( $data ) ) {
				$data = unserialize( $data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
				if ( false !== $data ) {
					$data = str_replace( $from, $to, $data );
					$data = serialize( $data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
				}
			} else {
				if ( is_string( $data ) ) {
					$data = str_replace( $from, $to, $data );
				}
			}

			if ( $serialised ) {
				return serialize( $data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			}
		} catch ( \Exception $error ) {
			// ok!
		}

		return $data;
	}

}
