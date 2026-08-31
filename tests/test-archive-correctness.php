<?php
/**
 * Class ArchiveCorrectnessTest
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Backup;
use MainWP\Child\MainWP_Backup_Error;
use MainWP\Child\MainWP_Exception;

/**
 * Tests for archive write correctness.
 *
 * Failed file adds must count toward an abort threshold instead of silently
 * producing an incomplete archive, and ZipArchive::close() failures must
 * surface instead of reporting success on a corrupt archive.
 */
class ArchiveCorrectnessTest extends WP_UnitTestCase {

	/**
	 * Scratch directory.
	 *
	 * @var string
	 */
	private $scratch;

	/**
	 * Set up scratch space and reset counters.
	 */
	public function set_up() {
		parent::set_up();
		$this->scratch = sys_get_temp_dir() . '/mwp-archive-test-' . wp_rand( 1000, 999999 );
		mkdir( $this->scratch, 0755, true );
		MainWP_Backup::get()->reset_failed_adds();
		MainWP_Backup::reset_skipped_paths();
	}

	/**
	 * Clean up scratch space.
	 */
	public function tear_down() {
		$this->remove_tree( $this->scratch );
		MainWP_Backup::get()->reset_failed_adds();
		MainWP_Backup::reset_skipped_paths();
		parent::tear_down();
	}

	/**
	 * Recursively delete a scratch tree.
	 *
	 * @param string $dir Directory to remove.
	 */
	private function remove_tree( $dir ) {
		if ( ! is_dir( $dir ) ) {
			if ( file_exists( $dir ) ) {
				unlink( $dir );
			}
			return;
		}
		chmod( $dir, 0755 );
		foreach ( scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_file( $path ) || is_link( $path ) ) {
				unlink( $path );
			} else {
				$this->remove_tree( $path );
			}
		}
		rmdir( $dir );
	}

	/**
	 * Failed adds below the threshold are recorded without aborting.
	 */
	public function test_failed_adds_below_threshold_are_recorded() {
		$backup = MainWP_Backup::get();
		for ( $i = 0; $i < MainWP_Backup::MAX_FAILED_ADDS; $i++ ) {
			$backup->record_failed_add( '/tmp/failed-' . $i );
		}
		$this->assertSame( MainWP_Backup::MAX_FAILED_ADDS, $backup->get_failed_add_count() );
		$this->assertContains( '/tmp/failed-0', MainWP_Backup::get_skipped_paths() );
	}

	/**
	 * One failed add past the threshold aborts the backup with a coded error.
	 */
	public function test_failed_adds_above_threshold_throw() {
		$backup = MainWP_Backup::get();
		try {
			for ( $i = 0; $i <= MainWP_Backup::MAX_FAILED_ADDS; $i++ ) {
				$backup->record_failed_add( '/tmp/failed-' . $i );
			}
			$this->fail( 'Expected a too-many-skipped exception' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( MainWP_Backup_Error::ERR_TOO_MANY_SKIPPED, $e->get_message_error_code() );
		}
	}

	/**
	 * A successful ZipArchive close passes the check silently.
	 */
	public function test_close_zip_archive_success() {
		if ( ! class_exists( '\ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive unavailable.' );
		}
		$backup = MainWP_Backup::get();
		$zip    = new \ZipArchive();
		$zip->open( $this->scratch . '/ok.zip', \ZipArchive::CREATE );
		$zip->addFromString( 'a.txt', 'data' );

		$prop = new ReflectionProperty( MainWP_Backup::class, 'zip' );
		$prop->setAccessible( true );
		$prop->setValue( $backup, $zip );

		$backup->close_zip_archive();
		$this->assertFileExists( $this->scratch . '/ok.zip' );
	}

	/**
	 * A failed ZipArchive close throws a coded error instead of returning
	 * success over a corrupt archive.
	 */
	public function test_close_zip_archive_failure_throws() {
		if ( ! class_exists( '\ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive unavailable.' );
		}
		$backup = MainWP_Backup::get();
		$gone   = $this->scratch . '/gone';
		mkdir( $gone );
		$zip = new \ZipArchive();
		$zip->open( $gone . '/broken.zip', \ZipArchive::CREATE );
		$zip->addFromString( 'a.txt', 'data' );
		rmdir( $gone ); // The archive file is only written on close - which now cannot succeed.

		$prop = new ReflectionProperty( MainWP_Backup::class, 'zip' );
		$prop->setAccessible( true );
		$prop->setValue( $backup, $zip );

		try {
			$backup->close_zip_archive();
			$this->fail( 'Expected a zip-failed exception' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( MainWP_Backup_Error::ERR_ZIP_FAILED, $e->get_message_error_code() );
		}
	}

	/**
	 * copy_dir() throws a coded permissions error when it cannot create the
	 * target directory, instead of silently producing an empty copy (A1).
	 */
	public function test_copy_dir_mkdir_failure_throws() {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'Root ignores directory permissions, so the mkdir under test would succeed.' );
		}

		$backup   = MainWP_Backup::get();
		$readonly = $this->scratch . '/readonly';
		mkdir( $readonly, 0555 );

		try {
			$backup->copy_dir( array( ABSPATH . 'wp-admin' ), array(), $readonly . '/', false );
			$this->fail( 'Expected a permissions exception' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( MainWP_Backup_Error::ERR_PERMISSIONS, $e->get_message_error_code() );
		}
	}

	/**
	 * copy_dir() records a failed file copy instead of ignoring it (A2).
	 */
	public function test_copy_dir_failed_copy_is_recorded() {
		$backup = MainWP_Backup::get();
		// Target folder does not exist, so the file copy fails.
		$backup->copy_dir( array( ABSPATH . 'wp-load.php' ), array(), $this->scratch . '/missing-target/', false );
		$this->assertGreaterThan( 0, $backup->get_failed_add_count() );
	}
}
