<?php
/**
 * Class BackupCheckpidTest
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Backup;

/**
 * Tests for backup stall detection.
 *
 * The legacy check computed the elapsed time from clock-face minutes
 * (date('i')) and only special-cased a wrap of exactly 59, so any check
 * crossing an hour boundary produced a wrong (often negative) elapsed time.
 */
class BackupCheckpidTest extends WP_UnitTestCase {

	/**
	 * Scratch backup dir.
	 *
	 * @var string
	 */
	private $scratch;

	/**
	 * Create scratch dir.
	 */
	public function set_up() {
		parent::set_up();
		$this->scratch = sys_get_temp_dir() . '/mwp-checkpid-test-' . wp_rand( 1000, 999999 );
		mkdir( $this->scratch, 0755, true );
	}

	/**
	 * Remove scratch dir.
	 */
	public function tear_down() {
		foreach ( (array) glob( $this->scratch . '/*' ) as $file ) {
			unlink( $file );
		}
		if ( is_dir( $this->scratch ) ) {
			rmdir( $this->scratch );
		}
		parent::tear_down();
	}

	/**
	 * Recent progress reports busy, old progress reports stalled.
	 */
	public function test_busy_then_stalled_at_threshold() {
		$backup = MainWP_Backup::get();
		$now    = 1700000000;

		$this->assertSame( 'busy', $backup->get_checkpid_status( $now - 30, $this->scratch, $now ) );
		$this->assertSame( 'busy', $backup->get_checkpid_status( $now - 79, $this->scratch, $now ) );
		$this->assertSame( 'stalled', $backup->get_checkpid_status( $now - 80, $this->scratch, $now ) );
		$this->assertSame( 'stalled', $backup->get_checkpid_status( $now - 600, $this->scratch, $now ) );
	}

	/**
	 * Crossing an hour boundary must not corrupt the elapsed time.
	 *
	 * mtime 10:59:50, now 11:01:00 - 70 seconds elapsed, busy. The legacy
	 * minute arithmetic computed 1 - 59 = -58 minutes here.
	 */
	public function test_hour_boundary_is_plain_delta() {
		$backup = MainWP_Backup::get();
		$mtime  = mktime( 10, 59, 50, 6, 9, 2026 );
		$now    = mktime( 11, 1, 0, 6, 9, 2026 );

		$this->assertSame( 'busy', $backup->get_checkpid_status( $mtime, $this->scratch, $now ) );

		// And 10:59:50 -> 11:01:20 is 90 seconds: stalled.
		$now = mktime( 11, 1, 20, 6, 9, 2026 );
		$this->assertSame( 'stalled', $backup->get_checkpid_status( $mtime, $this->scratch, $now ) );
	}

	/**
	 * A .sql.tmp file in the backup dir means the DB dump is running - the
	 * threshold is relaxed to 300 seconds. file_exists() does not expand
	 * globs, so this must use glob().
	 */
	public function test_db_phase_relaxes_threshold() {
		$backup = MainWP_Backup::get();
		$now    = 1700000000;

		file_put_contents( $this->scratch . '/dbBackup-wp_options.sql.php.tmp', 'in progress' );

		$this->assertSame( 'busy', $backup->get_checkpid_status( $now - 120, $this->scratch, $now ) );
		$this->assertSame( 'busy', $backup->get_checkpid_status( $now - 299, $this->scratch, $now ) );
		$this->assertSame( 'stalled', $backup->get_checkpid_status( $now - 300, $this->scratch, $now ) );
	}
}
