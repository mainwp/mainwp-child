<?php
/**
 * Class BackupDirTest
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Helper;

/**
 * Tests for the randomized backup directory.
 *
 * The directory name carries a per-site secret so backup artifacts are not
 * enumerable at a predictable path, independent of .htaccess support.
 */
class BackupDirTest extends WP_UnitTestCase {

	const LIKELY_CAUSE = 'A security or folder-lock plugin may be locking that folder, or its permissions may not allow the web server to write to it. Unlock the folder or fix its permissions, then try again.';

	const PROTECTION_CAUSE = 'A security or folder-lock plugin may be locking that parent folder or the folders inside it, or permissions may not allow the web server to write there. Unlock the parent folder or fix its permissions, then try again.';

	/**
	 * Both backup-folder failures name the folder relative to the site root, never the hash.
	 *
	 * @dataProvider backup_folder_failures
	 */
	public function test_backup_folder_errors_name_the_site_relative_folder( $locked, $expected ) {
		$name       = 'mwp1890-' . uniqid();
		$root       = ABSPATH . 'wp-content/uploads/' . $name;
		$backup_dir = $root . '/mainwp/backup-a1B2c3D4e5F6g7H8';
		$locked_dir = 'backup' === $locked ? $backup_dir : $root . '/mainwp';
		$filter     = static function ( $uploads ) use ( $root ) {
			$uploads['basedir'] = $root;
			return $uploads;
		};
		$error = null;
		try {
			$this->assertTrue( mkdir( $root . '/mainwp', 0755, true ) );
			$this->assertNotFalse( file_put_contents( $root . '/mainwp/index.php', '' ) );
			if ( 'backup' === $locked ) {
				$this->assertTrue( mkdir( $backup_dir, 0755 ) );
			}
			update_option( 'mainwp_backup_dir_hash', 'a1B2c3D4e5F6g7H8' );
			add_filter( 'mainwp_child_get_wp_upload_dir', $filter );
			chmod( $locked_dir, 0555 );
			clearstatcache( true, $locked_dir );
			if ( is_writable( $locked_dir ) ) { $this->markTestSkipped( 'The test user can write to a read-only directory.' ); }
			try {
				@MainWP_Helper::get_mainwp_dir( 'backup', false );
			} catch ( \MainWP\Child\MainWP_Exception $e ) {
				$error = $e;
			}
			$this->assertInstanceOf( \MainWP\Child\MainWP_Exception::class, $error );
			$this->assertSame( sprintf( $expected, 'wp-content/uploads/' . $name . '/mainwp' ), $error->getMessage() );
			$this->assertStringNotContainsString( 'a1B2c3D4e5F6g7H8', $error->getMessage() );
			$this->assertStringNotContainsString( ABSPATH, $error->getMessage() );
			$this->assertSame( '', $error->get_message_error_code(), 'A registered code would make the Dashboard show its generic text instead.' );
		} finally {
			remove_filter( 'mainwp_child_get_wp_upload_dir', $filter );
			// Guarded: setup may have stopped part way, and a cleanup warning must not hide the assertion that failed.
			if ( is_dir( $locked_dir ) ) { chmod( $locked_dir, 0755 ); }
			foreach ( array( $backup_dir . '/index.php', $backup_dir . '/.htaccess', $root . '/mainwp/index.php' ) as $file ) {
				if ( file_exists( $file ) ) { unlink( $file ); }
			}
			foreach ( array( $backup_dir, $root . '/mainwp', $root ) as $dir ) {
				if ( is_dir( $dir ) ) { rmdir( $dir ); }
			}
		}
	}

	/**
	 * Folder labels never carry an absolute path, a drive letter or a root slash.
	 *
	 * @dataProvider folder_label_cases
	 */
	public function test_folder_label_is_site_relative_or_last_two_names( $dir, $expected ) {
		$label = new ReflectionMethod( MainWP_Helper::class, 'message_dir_label' );
		$label->setAccessible( true );
		$this->assertSame( $expected, $label->invoke( null, $dir ) );
	}

	public static function folder_label_cases() {
		return array(
			'under the site root' => array( ABSPATH . 'wp-content/uploads/mainwp/', 'wp-content/uploads/mainwp' ),
			'site root sibling with a shared prefix' => array( untrailingslashit( ABSPATH ) . '2/uploads/mainwp/', 'uploads/mainwp' ),
			'outside the site root' => array( '/srv/shared/uploads/mainwp/', 'uploads/mainwp' ),
			'filesystem root' => array( '/mainwp/', 'mainwp' ),
			'drive root' => array( 'C:\\mainwp\\', 'mainwp' ),
			'drive path' => array( 'C:\\uploads\\mainwp\\', 'uploads/mainwp' ),
			'relative' => array( 'mainwp/', 'mainwp' ),
		);
	}

	public static function backup_folder_failures() {
		return array(
			'folder cannot be created' => array( 'mainwp', 'Could not create the backup folder inside %s. ' . self::LIKELY_CAUSE ),
			'protection files cannot be written' => array( 'backup', 'The backup folder inside %s exists, but its index.php and .htaccess protection files could not be written. ' . self::PROTECTION_CAUSE ),
		);
	}

	/** @dataProvider directory_error_paths */
	public function test_check_dir_error_hides_backup_hash_only( $name, $private ) {
		$parent = tempnam( sys_get_temp_dir(), 'mainwp-dir-error-' );
		$path = $parent . '/' . $name . '/';
		try {
			try {
				@MainWP_Helper::check_dir( $path, false );
				$this->fail( 'A file cannot be the parent of a new directory.' );
			} catch ( \MainWP\Child\MainWP_Exception $e ) {
				$message = $e->getMessage();
			}
			if ( $private ) {
				$this->assertStringNotContainsString( 'backup-a1B2c3D4e5F6g7H8', $message, 'Directory errors must not disclose the backup-directory hash.' );
				$this->assertStringNotContainsString( $parent, $message );
				$this->assertSame( 'Could not create the backup folder inside ' . basename( dirname( $parent ) ) . '/' . basename( $parent ) . '. ' . self::LIKELY_CAUSE, $message );
				$this->assertSame( '', $e->get_message_error_code(), 'A registered code would make the Dashboard show its generic text instead.' );
			} else {
				$this->assertSame( 'Unable to create directory ' . str_replace( ABSPATH, '', $path ) . '. Is its parent directory writable by the server?', $message );
			}
		} finally {
			unlink( $parent );
		}
	}

	/** @dataProvider protection_file_failures */
	public function test_backup_protection_write_failure_hides_path( $existing_index ) {
		$root = sys_get_temp_dir() . '/mainwp-protection-' . uniqid();
		$directory = $root . '/mainwp/backup-a1B2c3D4e5F6g7H8';
		mkdir( $directory, 0755, true );
		file_put_contents( $root . '/mainwp/index.php', '' );
		if ( $existing_index ) { file_put_contents( $directory . '/index.php', '' ); }
		update_option( 'mainwp_backup_dir_hash', 'a1B2c3D4e5F6g7H8' );
		$filter = static function ( $uploads ) use ( $root ) {
			$uploads['basedir'] = $root;
			return $uploads;
		};
		add_filter( 'mainwp_child_get_wp_upload_dir', $filter );
		chmod( $directory, 0555 );
		try {
			clearstatcache( true, $directory );
			if ( is_writable( $directory ) ) { $this->markTestSkipped( 'The test user can write to a read-only directory.' ); }
			$warnings = array();
			$error = null;
			set_error_handler( static function ( $severity, $message ) use ( &$warnings ) {
				if ( error_reporting() & $severity ) { $warnings[] = $message; }
				return true;
			} );
			try {
				MainWP_Helper::get_mainwp_dir( 'backup', false );
			} catch ( Throwable $e ) {
				$error = $e;
			} finally {
				restore_error_handler();
			}
			$this->assertSame( array(), $warnings, 'Protection-file warnings must not disclose the backup path.' );
			$this->assertInstanceOf( \MainWP\Child\MainWP_Exception::class, $error );
			$this->assertSame( 'The backup folder inside ' . basename( $root ) . '/mainwp exists, but its index.php and .htaccess protection files could not be written. ' . self::PROTECTION_CAUSE, $error->getMessage() );
			$this->assertSame( '', $error->get_message_error_code(), 'A registered code would make the Dashboard show its generic text instead.' );
		} finally {
			remove_filter( 'mainwp_child_get_wp_upload_dir', $filter );
			chmod( $directory, 0755 );
			if ( $existing_index ) { unlink( $directory . '/index.php' ); }
			rmdir( $directory );
			unlink( $root . '/mainwp/index.php' );
			rmdir( $root . '/mainwp' );
			rmdir( $root );
		}
	}

	public static function protection_file_failures() {
		return array( 'index' => array( false ), 'htaccess' => array( true ) );
	}

	public static function directory_error_paths() {
		return array(
			'private backup' => array( 'backup-a1B2c3D4e5F6g7H8', true ),
			'ordinary directory' => array( 'uploads', false ),
			'ordinary backup name' => array( 'backup-reports', false ),
		);
	}

	/**
	 * Reset the stored hash between tests.
	 */
	public function set_up() {
		parent::set_up();
		delete_option( 'mainwp_backup_dir_hash' );
	}

	/**
	 * Remove created directories.
	 */
	public function tear_down() {
		delete_option( 'mainwp_backup_dir_hash' );
		parent::tear_down();
	}

	/**
	 * First use generates a 16-char hash and persists it without autoloading.
	 */
	public function test_backup_dir_hash_generated_and_persisted() {
		global $wpdb;
		$hash = MainWP_Helper::get_backup_dir_hash();
		$this->assertSame( 16, strlen( $hash ) );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9]{16}$/', $hash );
		$this->assertSame( $hash, get_option( 'mainwp_backup_dir_hash' ) );

		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'mainwp_backup_dir_hash' ) );
		$this->assertNotContains( $autoload, array( 'yes', 'on', 'auto-on', 'auto' ), 'Hash option must not be autoloaded' );
	}

	/**
	 * The hash is generated once and reused afterwards.
	 */
	public function test_backup_dir_hash_is_stable() {
		$first  = MainWP_Helper::get_backup_dir_hash();
		$second = MainWP_Helper::get_backup_dir_hash();
		$this->assertSame( $first, $second );
	}

	public function test_existing_valid_backup_dir_hash_is_returned_unchanged() {
		$hash = 'a1B2c3D4e5F6g7H8';
		$this->assertTrue( add_option( 'mainwp_backup_dir_hash', $hash, '', 'no' ) );
		$this->assertSame( $hash, MainWP_Helper::get_backup_dir_hash() );
		$this->assertSame( $hash, get_option( 'mainwp_backup_dir_hash' ) );
	}

	/** @dataProvider invalid_backup_dir_hashes */
	public function test_malformed_backup_dir_hash_is_refused_without_replacement( $stored ) {
		$this->assertTrue( add_option( 'mainwp_backup_dir_hash', $stored, '', 'no' ) );
		$before = get_option( 'mainwp_backup_dir_hash' );
		$attempts = 0;
		$filter = static function ( $value ) use ( &$attempts ) {
			++$attempts;
			return $value;
		};
		$failure = null;
		add_filter( 'random_password', $filter );
		try {
			try {
				MainWP_Helper::get_backup_dir_hash();
			} catch ( \MainWP\Child\MainWP_Exception $e ) {
				$failure = $e;
			}
			$this->assertInstanceOf( \MainWP\Child\MainWP_Exception::class, $failure, 'An invalid directory secret must refuse the backup.' );
			$this->assertSame( \MainWP\Child\MainWP_Backup_Error::ERR_DIR_NOT_WRITABLE, $failure->get_message_error_code() );
			$this->assertStringContainsString( 'backup directory secret', $failure->getMessage() );
			$this->assertSame( $before, get_option( 'mainwp_backup_dir_hash' ), 'Never replace an existing malformed secret.' );
			$this->assertSame( 0, $attempts, 'Only an absent option may generate a secret.' );
		} finally {
			remove_filter( 'random_password', $filter );
		}
	}

	public static function invalid_backup_dir_hashes() {
		return array(
			'empty' => array( '' ),
			'short' => array( 'bad' ),
			'long' => array( 'a1B2c3D4e5F6g7H8x' ),
			'path' => array( '../outside' ),
			'array' => array( array( 'bad' ) ),
			'integer' => array( 123 ),
			'newline' => array( "a1B2c3D4e5F6g7H8\n" ),
		);
	}

	/** @dataProvider backup_dir_hash_failure_paths */
	public function test_backup_dir_hash_write_failure_refuses_callers( $filtered_read, $preflight ) {
		global $wpdb;
		$root = sys_get_temp_dir() . '/mainwp-secret-failure-' . uniqid();
		mkdir( $root );
		$upload_filter = static function ( $uploads ) use ( $root ) {
			$uploads['basedir'] = $root;
			return $uploads;
		};
		$writes = 0;
		$query_filter = static function ( $sql ) use ( $wpdb, &$writes ) {
			if ( 0 === strpos( $sql, 'INSERT INTO `' . $wpdb->options . '`' ) && false !== strpos( $sql, 'mainwp_backup_dir_hash' ) ) {
				++$writes;
				return 'SELECT 1 WHERE 0'; // A zero-row result makes add_option() report write failure.
			}
			return $sql;
		};
		add_filter( 'mainwp_child_get_wp_upload_dir', $upload_filter );
		if ( $filtered_read ) {
			add_filter( 'pre_option_mainwp_backup_dir_hash', '__return_empty_string' );
		} else {
			add_filter( 'query', $query_filter );
		}
		$failure = null;
		try {
			try {
				if ( $preflight ) {
					\MainWP\Child\MainWP_Backup::get()->check_backup_readiness();
				} else {
					MainWP_Helper::get_mainwp_dir( 'backup', false );
				}
			} catch ( \MainWP\Child\MainWP_Exception $e ) {
				$failure = $e;
			}
			$this->assertDirectoryDoesNotExist( $root . '/mainwp/backup-', 'A failed option read or write must never create backup-/.' );
			$this->assertSame( array(), glob( $root . '/mainwp/backup-*', GLOB_ONLYDIR ) );
			$this->assertInstanceOf( \MainWP\Child\MainWP_Exception::class, $failure );
			$this->assertSame( \MainWP\Child\MainWP_Backup_Error::ERR_DIR_NOT_WRITABLE, $failure->get_message_error_code() );
			$this->assertSame( 'The backup directory secret is missing or invalid. Check the child site database options before retrying the backup.', $failure->getMessage() );
			$this->assertSame( $filtered_read ? 0 : 1, $writes );
		} finally {
			remove_filter( 'mainwp_child_get_wp_upload_dir', $upload_filter );
			remove_filter( 'pre_option_mainwp_backup_dir_hash', '__return_empty_string' );
			remove_filter( 'query', $query_filter );
			// Remove artifacts even when the unchanged code creates backup-/.
			$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $files as $file ) {
				if ( $file->isDir() ) {
					rmdir( $file->getPathname() );
				} else {
					unlink( $file->getPathname() );
				}
			}
			rmdir( $root );
		}
	}

	public static function backup_dir_hash_failure_paths() {
		return array(
			'filtered read directory' => array( true, false ),
			'write failure directory' => array( false, false ),
			'filtered read preflight' => array( true, true ),
			'write failure preflight' => array( false, true ),
		);
	}

	/**
	 * The backup directory path embeds the hash and is protected.
	 */
	public function test_backup_dir_uses_randomized_path() {
		$dirs = MainWP_Helper::get_mainwp_dir( 'backup' );
		$dir  = $dirs[0];
		$hash = MainWP_Helper::get_backup_dir_hash();

		$this->assertStringContainsString( 'backup-' . $hash, $dir );
		$this->assertStringNotContainsString( DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR, $dir );
		$this->assertDirectoryExists( $dir );
		$this->assertFileExists( $dir . 'index.php' );
		$this->assertFileExists( $dir . '.htaccess' );
	}

	/**
	 * Consecutive calls resolve to the same directory.
	 */
	public function test_backup_dir_is_stable_across_calls() {
		$first  = MainWP_Helper::get_mainwp_dir( 'backup' );
		$second = MainWP_Helper::get_mainwp_dir( 'backup' );
		$this->assertSame( $first[0], $second[0] );
	}

	/**
	 * Files in the new randomized directory are found first.
	 */
	public function test_locate_backup_file_finds_new_dir_file() {
		$dirs = MainWP_Helper::get_mainwp_dir( 'backup' );
		file_put_contents( $dirs[0] . 'backup-test-new.zip', 'new' );

		$located = MainWP_Helper::locate_backup_file( 'backup-test-new.zip' );
		$this->assertSame( $dirs[0] . 'backup-test-new.zip', $located );
		unlink( $dirs[0] . 'backup-test-new.zip' );
	}

	/**
	 * Files that only exist in the legacy backup/ directory are still found.
	 *
	 * Pre-upgrade backups live in uploads/mainwp/backup/; the legacy dir is
	 * never renamed (an in-flight backup may be writing to it), so lookups
	 * fall back to it.
	 */
	public function test_locate_backup_file_falls_back_to_legacy_dir() {
		$upload_dir = wp_upload_dir();
		$legacy_dir = $upload_dir['basedir'] . DIRECTORY_SEPARATOR . 'mainwp' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR;
		wp_mkdir_p( $legacy_dir );
		file_put_contents( $legacy_dir . 'backup-test-legacy.zip', 'legacy' );

		$located = MainWP_Helper::locate_backup_file( 'backup-test-legacy.zip' );
		$this->assertSame( $legacy_dir . 'backup-test-legacy.zip', $located );
		unlink( $legacy_dir . 'backup-test-legacy.zip' );
	}

	/**
	 * A file that exists nowhere returns false.
	 */
	public function test_locate_backup_file_missing_returns_false() {
		$this->assertFalse( MainWP_Helper::locate_backup_file( 'backup-test-nonexistent.zip' ) );
	}

	/**
	 * Path traversal in the requested file name is rejected.
	 */
	public function test_locate_backup_file_rejects_traversal() {
		$this->assertFalse( MainWP_Helper::locate_backup_file( '../../wp-config.php' ) );
		$this->assertFalse( MainWP_Helper::locate_backup_file( 'foo/../../secret.zip' ) );
		$this->assertFalse( MainWP_Helper::locate_backup_file( '' ) );
	}
}
