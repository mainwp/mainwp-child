<?php
/**
 * Class BackupDiskSpaceTest
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Backup;
use MainWP\Child\MainWP_Backup_Error;
use MainWP\Child\MainWP_Exception;

/**
 * Tests for pre-backup and during-backup disk space validation.
 */
class BackupDiskSpaceTest extends WP_UnitTestCase {

	/**
	 * The database-only estimate is a positive byte count.
	 */
	public function test_estimate_backup_size_db_only_is_positive() {
		$size = MainWP_Backup::get()->estimate_backup_size( false );
		$this->assertGreaterThan( 0, $size, 'A WordPress install always has non-empty tables' );
	}

	/**
	 * Including site files never shrinks the estimate.
	 */
	public function test_estimate_backup_size_with_files_is_larger() {
		$db_only    = MainWP_Backup::get()->estimate_backup_size( false );
		$with_files = MainWP_Backup::get()->estimate_backup_size( true );
		$this->assertGreaterThanOrEqual( $db_only, $with_files );
	}

	/**
	 * The during-backup check passes when plenty of space is free.
	 */
	public function test_during_backup_check_passes_with_free_space() {
		MainWP_Backup::assert_disk_space_during_backup( sys_get_temp_dir(), 1024 );
		$this->assertTrue( true ); // No exception thrown.
	}

	/**
	 * The during-backup check throws a coded disk-full error when below the floor.
	 */
	public function test_during_backup_check_throws_when_low() {
		if ( ! function_exists( 'disk_free_space' ) ) {
			$this->markTestSkipped( 'disk_free_space() unavailable.' );
		}
		try {
			MainWP_Backup::assert_disk_space_during_backup( sys_get_temp_dir(), PHP_INT_MAX );
			$this->fail( 'Expected a disk-full exception' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( MainWP_Backup_Error::ERR_DISK_FULL, $e->get_message_error_code() );
		}
	}

	/**
	 * The pre-backup check throws when free space is below the safety margin.
	 */
	public function test_pre_backup_check_throws_when_insufficient() {
		if ( ! function_exists( 'disk_free_space' ) ) {
			$this->markTestSkipped( 'disk_free_space() unavailable.' );
		}
		try {
			// An absurd safety multiplier forces the failure path without
			// depending on the machine's actual free space.
			MainWP_Backup::get()->assert_disk_space_for_backup( sys_get_temp_dir(), false, 1.0e15 );
			$this->fail( 'Expected a disk-full exception' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( MainWP_Backup_Error::ERR_DISK_FULL, $e->get_message_error_code() );
			$this->assertStringNotContainsString( ABSPATH, $e->getMessage() );
		}
	}

	/**
	 * The pre-backup check passes with the normal 1.5x margin on a healthy disk.
	 */
	public function test_pre_backup_check_passes_normally() {
		MainWP_Backup::get()->assert_disk_space_for_backup( sys_get_temp_dir(), false );
		$this->assertTrue( true ); // No exception thrown.
	}
}
