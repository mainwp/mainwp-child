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
