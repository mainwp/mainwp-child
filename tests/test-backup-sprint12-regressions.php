<?php
/**
 * Regression coverage for sprint 12 archive and directory failures.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Backup;
use MainWP\Child\MainWP_Backup_Error;
use MainWP\Child\MainWP_Child_Backup_Mutex;
use MainWP\Child\MainWP_Child_Backup_Task;
use MainWP\Child\MainWP_Child_Backup_Task_Runner;
use MainWP\Child\MainWP_Exception;

class BackupSprint12RegressionsTest extends WP_UnitTestCase {

	/**
	 * The lease error takes precedence because Dashboard uses its code to retry.
	 * T/F/E mean true, false, or thrown Throwable (ValueError on PHP 8).
	 * A dash means the operation must not be called. Every row ends with zip=null.
	 *
	 * Handle          Lease  unchangeAll  close  Caller sees
	 * open            ok     -            T      no exception
	 * open            ok     -            F/E    ERR_ZIP_FAILED
	 * open            throws T            T/F/E  original lease exception/code
	 * open            throws F/E          -      original lease exception/code
	 * closed/unopened ok     -            F/E    ERR_ZIP_FAILED
	 * closed/unopened throws F/E          -      original lease exception/code
	 * null            ok     -            -      ERR_ZIP_FAILED
	 * null            throws -            -      original lease exception/code
	 *
	 * The provider expands all nine discard/close combinations for each lease
	 * outcome on an open stub, including outcomes that must remain unused.
	 * Closed and unopened rows use real handles to catch PHP API differences.
	 */
	public static function close_matrix() {
		foreach ( array( false, true ) as $lease_fails ) {
			foreach ( array( 'true', 'false', 'throws' ) as $discard ) {
				foreach ( array( 'true', 'false', 'throws' ) as $close ) {
					yield 'open/lease-' . ( $lease_fails ? 'throws' : 'ok' ) . '/discard-' . $discard . '/close-' . $close => array( 'open', $lease_fails, $discard, $close );
				}
			}
			foreach ( array( 'closed', 'unopened', 'null' ) as $state ) {
				yield $state . '/lease-' . ( $lease_fails ? 'throws' : 'ok' ) => array( $state, $lease_fails, 'throws', 'throws' );
			}
		}
	}

	/** @dataProvider close_matrix */
	public function test_close_zip_archive_matrix( $state, $lease_fails, $discard, $close ) {
		$backup = new class() extends MainWP_Backup {
			public $lease_error;
			public $lease_calls = 0;
			public function extend_archive_mutex_lease() {
				++$this->lease_calls;
				if ( null !== $this->lease_error ) {
					throw $this->lease_error;
				}
			}
		};
		$lease_error = new MainWP_Exception( 'lease lost', null, MainWP_Backup_Error::ERR_ALREADY_RUNNING );
		$backup->lease_error = $lease_fails ? $lease_error : null;
		$zip = null;
		if ( 'open' === $state ) {
			$zip = new class() extends ZipArchive {
				public $discard_result;
				public $close_result;
				public $calls = array();
				public function unchangeAll(): bool {
					$this->calls[] = 'discard';
					return $this->result( $this->discard_result );
				}
				public function close(): bool {
					$this->calls[] = 'close';
					return $this->result( $this->close_result );
				}
				private function result( $result ) {
					if ( 'throws' === $result ) {
						if ( class_exists( 'ValueError' ) ) {
							throw new ValueError( 'Invalid or uninitialized Zip object' );
						}
						throw new Error( 'Invalid or uninitialized Zip object' );
					}
					return 'true' === $result;
				}
			};
			$zip->discard_result = $discard;
			$zip->close_result = $close;
		} elseif ( 'null' !== $state ) {
			$zip = new ZipArchive();
			if ( 'closed' === $state ) {
				$path = tempnam( sys_get_temp_dir(), 'mwp-closed-' );
				try {
					$this->assertTrue( $zip->open( $path, ZipArchive::OVERWRITE ) );
					$this->assertTrue( $zip->close() );
				} finally {
					if ( is_file( $path ) ) {
						unlink( $path );
					}
				}
			}
		}
		$slot = new ReflectionProperty( MainWP_Backup::class, 'zip' );
		$slot->setAccessible( true );
		$slot->setValue( $backup, $zip );
		$caught = null;
		try {
			$backup->close_zip_archive();
		} catch ( Throwable $e ) {
			$caught = $e;
		}
		if ( $lease_fails ) {
			$this->assertSame( $lease_error, $caught, 'Discard must preserve the original lease exception.' );
			$this->assertSame( MainWP_Backup_Error::ERR_ALREADY_RUNNING, $caught->get_message_error_code() );
		} elseif ( 'open' === $state && 'true' === $close ) {
			$this->assertNull( $caught );
		} else {
			$this->assertInstanceOf( MainWP_Exception::class, $caught, 'Close failure must be coded, never a raw ZIP error.' );
			$this->assertSame( MainWP_Backup_Error::ERR_ZIP_FAILED, $caught->get_message_error_code() );
		}
		$this->assertNull( $slot->getValue( $backup ), 'Every exit must clear the ZIP handle.' );
		$this->assertSame( 1, $backup->lease_calls );
		if ( 'open' === $state ) {
			$expected = $lease_fails ? ( 'true' === $discard ? array( 'discard', 'close' ) : array( 'discard' ) ) : array( 'close' );
			$this->assertSame( $expected, $zip->calls, 'A failed discard must not commit pending entries.' );
		}
	}

	public function test_unresolved_backup_directory_skips_cleanup_and_records_failure() {
		$slot = new ReflectionProperty( MainWP_Child_Backup_Task::class, 'instance' );
		$slot->setAccessible( true );
		$original = MainWP_Child_Backup_Task::instance();
		$spine = new class() extends MainWP_Child_Backup_Task {
			public $directory_calls = array();
			public function read_sub_state( $task, $backup_dir ) {
				$this->directory_calls[] = array( 'read', $backup_dir );
				return null;
			}
			public function delete_sidecar( $task_id, $backup_dir ) {
				$this->directory_calls[] = array( 'delete', $backup_dir );
			}
		};
		$runner = new class() extends MainWP_Child_Backup_Task_Runner {
			public $cleanup_dirs = array();
			public $holder_at_failure;
			protected function backup_dir() {
				$this->holder_at_failure = MainWP_Child_Backup_Mutex::holder();
				throw new RuntimeException( 'backup directory unavailable' );
			}
			public function cleanup_failed_task_artifacts( $task_id, $sub_state, $backup_dir ) {
				$this->cleanup_dirs[] = $backup_dir;
			}
		};
		$slot->setValue( null, $spine );
		$task_id = false;
		try {
			$task_id = $spine->create_task( 'full', array( '_sidecar' => true, 'file' => 'task_substate-fixture.json' ) );
			$this->assertNotFalse( $task_id );
			$this->assertTrue( MainWP_Child_Backup_Mutex::acquire( 'native', $task_id, 3600 ) );
			$task = $runner->advance_one_slice( $spine->get_task( $task_id ), 'regression' );
			$this->assertSame( $task_id, $runner->holder_at_failure['owner'] );
			$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );
			$this->assertSame( array( 'message' => 'backup directory unavailable', 'code' => '' ), json_decode( $task->sub_state, true )['error'] );
			$this->assertNull( MainWP_Child_Backup_Mutex::holder() );
			$this->assertEmpty( $task->lease_owner );
			$this->assertSame( array(), $runner->cleanup_dirs, 'Unresolved directory must never reach artifact cleanup.' );
			$this->assertSame( array(), $spine->directory_calls, 'Unresolved directory must never reach sidecar reads or deletion.' );
		} finally {
			if ( false !== $task_id ) {
				MainWP_Child_Backup_Mutex::release( $task_id );
				global $wpdb;
				$wpdb->delete( $spine->get_table_name(), array( 'task_id' => $task_id ) );
			}
			$slot->setValue( null, $original );
		}
	}

	/**
	 * close_zip_archive() rethrows the archive-lease failure unchanged, and on
	 * PHP 8 a closed or never-opened ZipArchive handle raises ValueError, which
	 * is an Error and not an Exception. create_full_backup()'s discard arm has
	 * to catch Throwable or that failure publishes the partial archive it was
	 * written to delete.
	 */
	public function test_error_from_the_close_path_still_discards_the_partial_archive() {
		$backup = new class() extends MainWP_Backup {
			public $root;
			public function get_or_create_db_dump_files( $prefix, &$archiver = null ) {
				$this->allowed_backup_bases = array( $this->root );
				return array( $this->root . '/only.sql.php' );
			}
			public function add_config() {
			}
			public function close_zip_archive() {
				$this->zip = null;
				throw new \ValueError( 'Invalid or uninitialized Zip object' );
			}
		};
		$scratch = sys_get_temp_dir() . '/mwp-throwable-discard-' . wp_generate_uuid4();
		mkdir( $scratch, 0755, true );
		$backup->root = $scratch;
		file_put_contents( $scratch . '/only.sql.php', 'one entry' );
		$name = 'throwable-' . wp_generate_uuid4() . '.zip';
		$dirs = \MainWP\Child\MainWP_Helper::get_mainwp_dir( 'backup' );
		$archive = $dirs[0] . $name;

		$caught = null;
		try {
			$backup->create_full_backup( array(), '', true, true, 0, $name, false, false, true, 'zip', false, true );
		} catch ( \Throwable $e ) {
			$caught = $e;
		}

		unlink( $scratch . '/only.sql.php' );
		rmdir( $scratch );

		$this->assertInstanceOf( \ValueError::class, $caught, 'The Error must reach the caller unchanged.' );
		$this->assertFileDoesNotExist( $archive, 'An Error on the close path must discard the partial archive.' );
	}
}
