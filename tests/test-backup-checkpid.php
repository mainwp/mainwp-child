<?php
/**
 * Class BackupCheckpidTest
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Backup;
use MainWP\Child\MainWP_Child_Backup_Mutex;

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

	/**
	 * A fresh pid file (mtime under the 160s window) is a held lock.
	 */
	public function test_is_pid_lock_fresh_detects_recent_pid_file() {
		$pid_file = $this->scratch . '/backup-123.pid';
		file_put_contents( $pid_file, 'legacy' );
		touch( $pid_file, time() );

		$this->assertTrue( MainWP_Backup::is_pid_lock_fresh( $this->scratch ) );
	}

	/**
	 * A pid file older than the 160s window is not treated as a live lock -
	 * the same freshness boundary create_full_backup() uses.
	 */
	public function test_is_pid_lock_fresh_ignores_stale_pid_file() {
		$pid_file = $this->scratch . '/backup-123.pid';
		file_put_contents( $pid_file, 'legacy' );
		touch( $pid_file, time() - 161 );

		$this->assertFalse( MainWP_Backup::is_pid_lock_fresh( $this->scratch ) );
	}

	/**
	 * A caller's own pid file (the $except_pid_path it will itself write) is
	 * never counted against it, matching create_full_backup()'s original
	 * basename-skip behavior.
	 */
	public function test_is_pid_lock_fresh_skips_the_callers_own_pid_file() {
		$own_pid = $this->scratch . '/backup-999.pid';
		file_put_contents( $own_pid, 'self' );
		touch( $own_pid, time() );

		$this->assertFalse( MainWP_Backup::is_pid_lock_fresh( $this->scratch, $own_pid ) );
	}

	/**
	 * No pid files at all is not a held lock.
	 */
	public function test_is_pid_lock_fresh_false_when_no_pid_files() {
		$this->assertFalse( MainWP_Backup::is_pid_lock_fresh( $this->scratch ) );
	}

	public function test_zip_full_entry_creates_and_refreshes_pid_with_a_tiny_driver() {
		$backup = new class() extends MainWP_Backup {
			public $pid_path;
			public $observed = array();

			public function heartbeat_after_completion() {
				$this->heartbeat_at = 0;
				$this->heartbeat();
				return $this->pid_file;
			}

			public function check_zip_support() {
				return true;
			}

			public function create_zip_full_backup( $filepath, $excludes, $addConfig, $includeCoreFiles, $excludezip, $excludenonwp ) {
				$this->pid_path = $this->pid_file;
				$this->observed['exists'] = is_file( $this->pid_file );
				$this->observed['content'] = file_get_contents( $this->pid_file );
				$old = time() - 100;
				touch( $this->pid_file, $old );
				$this->heartbeat();
				clearstatcache( true, $this->pid_file );
				$this->observed['throttled'] = filemtime( $this->pid_file );
				$this->heartbeat_at = time() - 41;
				$this->heartbeat();
				clearstatcache( true, $this->pid_file );
				$this->observed['refreshed'] = filemtime( $this->pid_file ) > $old;
				$this->observed['status'] = $this->get_checkpid_status( filemtime( $this->pid_file ), dirname( $this->pid_file ) );
				$this->observed['old'] = $old;
				$this->observed['path'] = $filepath;
				file_put_contents( $filepath, 'tiny archive fixture' );
				return true;
			}
		};
		$pid = 'zip-heartbeat-' . wp_generate_uuid4();
		try {
			$result = $backup->create_full_backup( array(), $pid, true, true, 0, false, false, false, true, 'zip', $pid );
			$this->assertIsArray( $result );
			$this->assertTrue( $backup->observed['exists'] );
			$this->assertSame( $backup->observed['path'], $backup->observed['content'] );
			$this->assertSame( $backup->observed['old'], $backup->observed['throttled'] );
			$this->assertTrue( $backup->observed['refreshed'] );
			$this->assertSame( 'busy', $backup->observed['status'] );
			$done = substr( $backup->pid_path, 0, -4 ) . '.done';
			$this->assertFileExists( $done );
			$this->assertSame( $backup->observed['path'], file_get_contents( $done ) );
			$this->assertFileDoesNotExist( $backup->pid_path );
			$this->assertFalse( MainWP_Backup::is_pid_lock_fresh( dirname( $backup->pid_path ) ) );
			$this->assertFalse( $backup->heartbeat_after_completion() );
			$this->assertFileDoesNotExist( $backup->pid_path );
		} finally {
			$done = substr( (string) $backup->pid_path, 0, -4 ) . '.done';
			if ( is_file( $done ) ) {
				unlink( $done );
			}
			if ( is_file( $backup->pid_path ) ) {
				unlink( $backup->pid_path );
			}
			if ( isset( $backup->observed['path'] ) && is_file( $backup->observed['path'] ) ) {
				unlink( $backup->observed['path'] );
			}
		}
	}

	public function test_legacy_heartbeat_refreshes_without_pid_and_stops_after_takeover() {
		$backup = new class() extends MainWP_Backup {
			public function own( $owner ) {
				$this->mutex_owner = $owner;
				$this->heartbeat_at = 0;
			}
		};
		$this->assertTrue( MainWP_Child_Backup_Mutex::acquire( 'legacy', 'heartbeat-owner', 300 ) );
		$backup->own( 'heartbeat-owner' );
		$before = MainWP_Child_Backup_Mutex::holder();
		try {
			$backup->heartbeat();
			$this->assertGreaterThan( $before['expires_at'], MainWP_Child_Backup_Mutex::holder()['expires_at'] );
			MainWP_Child_Backup_Mutex::release( 'heartbeat-owner' );
			$this->assertTrue( MainWP_Child_Backup_Mutex::acquire( 'native', 'successor', 3600 ) );
			$backup->own( 'heartbeat-owner' );
			try {
				$backup->heartbeat();
				$this->fail( 'A displaced writer must stop.' );
			} catch ( \MainWP\Child\MainWP_Exception $e ) {
				$this->assertSame( \MainWP\Child\MainWP_Backup_Error::ERR_ALREADY_RUNNING, $e->get_message_error_code() );
			}
			$this->assertSame( 'successor', MainWP_Child_Backup_Mutex::holder()['owner'] );
		} finally {
			$holder = MainWP_Child_Backup_Mutex::holder();
			if ( null !== $holder ) {
				MainWP_Child_Backup_Mutex::release( $holder['owner'] );
			}
		}
	}

	public function test_copy_dir_heartbeats_per_node_and_aborts_after_mutex_loss() {
		$source = ABSPATH . 'mwp-copy-test-' . wp_generate_uuid4();
		$target = $this->scratch . '/' . basename( $source );
		mkdir( $source );
		mkdir( $source . '/nested' );
		file_put_contents( $source . '/one.txt', 'one' );
		file_put_contents( $source . '/nested/two.txt', 'two' );
		$backup = new class() extends MainWP_Backup {
			public $beats = 0;
			public function own( $owner ) {
				$this->mutex_owner = $owner;
				$this->heartbeat_at = 0;
			}
			public function heartbeat() {
				++$this->beats;
				parent::heartbeat();
			}
		};
		try {
			$this->assertTrue( MainWP_Child_Backup_Mutex::acquire( 'legacy', 'copy-owner', 300 ) );
			$backup->own( 'copy-owner' );
			$before = MainWP_Child_Backup_Mutex::holder()['expires_at'];
			$backup->copy_dir( array( $source ), array(), $this->scratch . '/', false );
			$this->assertSame( 4, $backup->beats );
			$this->assertGreaterThan( $before, MainWP_Child_Backup_Mutex::holder()['expires_at'] );
			$this->assertSame( 'one', file_get_contents( $target . '/one.txt' ) );
			$this->assertSame( 'two', file_get_contents( $target . '/nested/two.txt' ) );
			MainWP_Child_Backup_Mutex::release( 'copy-owner' );
			$this->assertTrue( MainWP_Child_Backup_Mutex::acquire( 'native', 'copy-successor', 3600 ) );
			$backup->own( 'copy-owner' );
			file_put_contents( $source . '/one.txt', 'must not overwrite' );
			try {
				$backup->copy_dir( array( $source ), array(), $this->scratch . '/', false );
				$this->fail( 'A displaced copy must stop.' );
			} catch ( \MainWP\Child\MainWP_Exception $e ) {
				$this->assertSame( \MainWP\Child\MainWP_Backup_Error::ERR_ALREADY_RUNNING, $e->get_message_error_code() );
			}
			$this->assertSame( 'one', file_get_contents( $target . '/one.txt' ) );
			$this->assertSame( 'copy-successor', MainWP_Child_Backup_Mutex::holder()['owner'] );
		} finally {
			MainWP_Child_Backup_Mutex::release( 'copy-owner' );
			MainWP_Child_Backup_Mutex::release( 'copy-successor' );
			foreach ( array( $source, $target ) as $dir ) {
				foreach ( array( '/nested/two.txt', '/one.txt' ) as $file ) {
					if ( is_file( $dir . $file ) ) {
						unlink( $dir . $file );
					}
				}
				if ( is_dir( $dir . '/nested' ) ) {
					rmdir( $dir . '/nested' );
				}
				if ( is_dir( $dir ) ) {
					rmdir( $dir );
				}
			}
		}
	}

	public function test_blocking_archive_lease_uses_request_budget_and_rejects_lost_owner() {
		$backup = new class() extends MainWP_Backup {
			public $budget = 72000;
			public function get_timeout_from_request() {
				return $this->budget;
			}
			public function own( $owner ) {
				$this->mutex_owner = $owner;
			}
		};
		$backup->own( 'archive-owner' );
		try {
			// 72000 is the 20-hour preset: the lease is capped so a run killed
			// without its shutdown hook cannot wedge the child for that long.
			foreach ( array( 72000 => 10800, 1 => 300 ) as $budget => $expected ) {
				$this->assertTrue( MainWP_Child_Backup_Mutex::acquire( 'legacy', 'archive-owner', -1 ) );
				$backup->budget = $budget;
				$before = time();
				$backup->extend_archive_mutex_lease();
				$expires = MainWP_Child_Backup_Mutex::holder()['expires_at'];
				$this->assertGreaterThanOrEqual( $before + $expected, $expires );
				$this->assertLessThanOrEqual( time() + $expected, $expires );
				MainWP_Child_Backup_Mutex::release( 'archive-owner' );
			}
			$this->assertTrue( MainWP_Child_Backup_Mutex::acquire( 'native', 'archive-successor', 3600 ) );
			try {
				$backup->extend_archive_mutex_lease();
				$this->fail( 'A displaced archiver must stop.' );
			} catch ( \MainWP\Child\MainWP_Exception $e ) {
				$this->assertSame( \MainWP\Child\MainWP_Backup_Error::ERR_ALREADY_RUNNING, $e->get_message_error_code() );
			}
		} finally {
			MainWP_Child_Backup_Mutex::release( 'archive-owner' );
			MainWP_Child_Backup_Mutex::release( 'archive-successor' );
		}
	}

	public function test_backup_full_sanitizes_pid_before_building_path() {
		$backup = new class() extends MainWP_Backup {
			public $pid_path;
			public $archive_path;
			public function check_zip_support() {
				return true;
			}
			public function create_zip_full_backup( $filepath, $excludes, $addConfig, $includeCoreFiles, $excludezip, $excludenonwp ) {
				$this->pid_path = $this->pid_file;
				$this->archive_path = $filepath;
				file_put_contents( $filepath, 'tiny archive fixture' );
				return true;
			}
		};
		$post = $_POST;
		$result = null;
		try {
			$_POST = array( 'pid' => '../../../../wp-content/uploads/x', 'ext' => 'zip' );
			$result = $backup->backup_full( 'pid-safety-' . wp_generate_uuid4() );
			$this->assertIsArray( $result );
			$dirs = \MainWP\Child\MainWP_Helper::get_mainwp_dir( 'backup' );
			$this->assertSame( realpath( $dirs[0] ), realpath( dirname( $backup->pid_path ) ) );
			$this->assertStringNotContainsString( '..', basename( $backup->pid_path ) );
			$this->assertFileExists( substr( $backup->pid_path, 0, -4 ) . '.done' );
		} finally {
			$_POST = $post;
			foreach ( array( $backup->pid_path, substr( (string) $backup->pid_path, 0, -4 ) . '.done', $backup->archive_path ) as $file ) {
				if ( $file && is_file( $file ) ) {
					unlink( $file );
				}
			}
		}
	}

	public function test_tar_pid_update_allows_null_backup() {
		$archiver = new \MainWP\Child\Tar_Archiver( null, 'tar.gz' );
		$this->assertTrue( $archiver->update_pid_file() );
		$this->assertFalse( $archiver->update_pid_file() );
	}

}
