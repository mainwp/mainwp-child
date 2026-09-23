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
		$this->assertSame( 1, $report['restore_create_idempotency'] );
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
	 * The report carries the install's multisite state and whether this
	 * child can restore a multisite network. The test environment is
	 * single-site, so both report 0 here; the keys existing is what the
	 * Dashboard's capability stamp needs.
	 */
	public function test_check_backup_readiness_reports_multisite_state() {
		$report = MainWP_Backup::get()->check_backup_readiness();

		$this->assertSame( 0, $report['is_multisite'] );
		$this->assertSame( 0, $report['restore_multisite'] );
	}

	/**
	 * The report carries a size estimate and a wp-content file count so the
	 * Dashboard can guard against ZIP archiving on large sites. ZIP restarts
	 * from zero on each slice timeout; tar.gz appends and resumes.
	 */
	public function test_check_backup_readiness_reports_size_estimate() {
		$report = MainWP_Backup::get()->check_backup_readiness();

		$this->assertArrayHasKey( 'estimated_bytes', $report );
		$this->assertArrayHasKey( 'file_count', $report );
		$this->assertIsInt( $report['estimated_bytes'] );
		$this->assertIsInt( $report['file_count'] );
		$this->assertGreaterThanOrEqual( 0, $report['estimated_bytes'] );
		$this->assertGreaterThanOrEqual( 0, $report['file_count'] );
	}

	/**
	 * disk_free is a byte count when the host exposes disk_free_space().
	 */
	public function test_check_backup_readiness_reports_disk_space() {
		$report = MainWP_Backup::get()->check_backup_readiness();
		// disk_free_space() existing is not enough: it returns false on
		// restricted or network storage, and the report passes that through.
		if ( function_exists( 'disk_free_space' ) && false !== $report['disk_free'] ) {
			$this->assertIsNumeric( $report['disk_free'] );
			$this->assertGreaterThan( 0, $report['disk_free'] );
		} else {
			$this->assertFalse( $report['disk_free'] );
		}
	}
}
