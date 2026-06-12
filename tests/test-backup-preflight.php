<?php
/**
 * Class BackupPreflightTest
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Backup;
use MainWP\Child\MainWP_Child_Callable;
use MainWP\Child\MainWP_Helper;

/**
 * Tests for the backup_preflight callable and check_backup_readiness().
 */
class BackupPreflightTest extends WP_UnitTestCase {

	/**
	 * The backup_preflight action is registered in the callable router.
	 */
	public function test_backup_preflight_action_is_registered() {
		$callable = MainWP_Child_Callable::get_instance();
		$prop     = new ReflectionProperty( MainWP_Child_Callable::class, 'callableFunctions' );
		$prop->setAccessible( true );
		$map = $prop->getValue( $callable );

		$this->assertArrayHasKey( 'backup_preflight', $map );
		$this->assertSame( 'backup_preflight', $map['backup_preflight'] );
		$this->assertTrue( method_exists( $callable, 'backup_preflight' ), 'Callable must implement backup_preflight()' );
	}

	/**
	 * The readiness report contains every key the Dashboard evaluates.
	 */
	public function test_check_backup_readiness_structure() {
		$report = MainWP_Backup::get()->check_backup_readiness();

		$expected_keys = array(
			'disk_free',
			'dir_writable',
			'db_connected',
			'memory_limit',
			'max_exec_time',
			'zip_support',
			'gz_support',
			'bz2_support',
			'time_limit_ok',
			'backup_dir_hash',
		);
		foreach ( $expected_keys as $key ) {
			$this->assertArrayHasKey( $key, $report, "Readiness report must include $key" );
		}

		$this->assertTrue( $report['dir_writable'] );
		$this->assertTrue( $report['db_connected'] );
		$this->assertIsBool( $report['gz_support'] );
		$this->assertIsBool( $report['bz2_support'] );
		$this->assertIsBool( $report['time_limit_ok'] );
	}

	/**
	 * The report carries the backup directory secret so the Dashboard can
	 * track the child's randomized directory.
	 */
	public function test_check_backup_readiness_includes_dir_hash() {
		$report = MainWP_Backup::get()->check_backup_readiness();
		$this->assertSame( MainWP_Helper::get_backup_dir_hash(), $report['backup_dir_hash'] );
		$this->assertSame( 16, strlen( $report['backup_dir_hash'] ) );
	}

	/**
	 * The report advertises the capabilities this child supports so the
	 * Dashboard can gate features per site: the resumable task machine,
	 * one-time download tokens, and incremental backups.
	 */
	public function test_check_backup_readiness_advertises_capabilities() {
		$report = MainWP_Backup::get()->check_backup_readiness();

		$this->assertSame( 1, $report['task_spine'] );
		$this->assertSame( 1, $report['download_token'] );
		$this->assertSame( 1, $report['incremental'] );
	}

	/**
	 * The report advertises files-only restore support, so the Dashboard
	 * only offers the Restore action on a files snapshot to children
	 * that run the db-skipping pipeline.
	 */
	public function test_check_backup_readiness_advertises_restore_files_capability() {
		$report = MainWP_Backup::get()->check_backup_readiness();

		$this->assertSame( 1, $report['restore_files'] );
	}

	/**
	 * The report advertises cloud-pull restore support, so the Dashboard
	 * only sends presigned pull manifests to children that can download
	 * restore artifacts straight from cloud storage.
	 */
	public function test_check_backup_readiness_advertises_cloud_restore_pull() {
		$report = MainWP_Backup::get()->check_backup_readiness();

		$this->assertSame( 1, $report['cloud_restore_pull'] );
	}

	/**
	 * disk_free is a byte count when the host exposes disk_free_space().
	 */
	public function test_check_backup_readiness_reports_disk_space() {
		$report = MainWP_Backup::get()->check_backup_readiness();
		if ( function_exists( 'disk_free_space' ) ) {
			$this->assertIsNumeric( $report['disk_free'] );
			$this->assertGreaterThan( 0, $report['disk_free'] );
		} else {
			$this->assertFalse( $report['disk_free'] );
		}
	}
}
