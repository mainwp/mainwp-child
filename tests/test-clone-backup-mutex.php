<?php
/**
 * Clone fragments must survive native backup admission attempts.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Backup;
use MainWP\Child\MainWP_Backup_Error;
use MainWP\Child\MainWP_Child_Backup_Mutex;
use MainWP\Child\MainWP_Child_Backup_Task;
use MainWP\Child\MainWP_Child_Backup_Task_Runner;
use MainWP\Child\MainWP_Exception;
use MainWP\Child\MainWP_Helper;

class CloneBackupMutexTest extends WP_UnitTestCase {

	private $dir;
	private $backup_dir;
	private $upload_filter;

	public function set_up() {
		parent::set_up();
		MainWP_Child_Backup_Task::instance()->install();
		$this->dir = sys_get_temp_dir() . '/mainwp-clone-mutex-' . uniqid();
		mkdir( $this->dir );
		$this->upload_filter = function ( $uploads ) {
			$uploads['basedir'] = $this->dir;
			return $uploads;
		};
		add_filter( 'mainwp_child_get_wp_upload_dir', $this->upload_filter );
		$this->backup_dir = MainWP_Helper::get_mainwp_dir( 'backup' )[0];
	}

	public function tear_down() {
		$holder = MainWP_Child_Backup_Mutex::holder();
		if ( null !== $holder ) {
			MainWP_Child_Backup_Mutex::release( $holder['owner'] );
		}
		remove_filter( 'mainwp_child_get_wp_upload_dir', $this->upload_filter );
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . MainWP_Child_Backup_Task::instance()->get_table_name() );
		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $this->dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $files as $file ) {
			if ( $file->isDir() ) {
				rmdir( $file->getPathname() );
			} else {
				unlink( $file->getPathname() );
			}
		}
		rmdir( $this->dir );
		parent::tear_down();
	}

	private function make_backup() {
		// Keep production heartbeat and completion handling so marker assertions cover real behavior.
		return new class() extends MainWP_Backup {
			public $during_backup;
			public $pid_path;
			public $archive_path;
			public $archive_called = false;

			public function check_zip_support() {
				return true;
			}

			public function create_zip_full_backup( $filepath, $excludes, $addConfig, $includeCoreFiles, $excludezip, $excludenonwp ) {
				$this->archive_called = true;
				$this->pid_path = $this->pid_file;
				$this->archive_path = $filepath;
				if ( null !== $this->during_backup ) {
					return call_user_func( $this->during_backup );
				}
				file_put_contents( $filepath, 'clone archive fixture' );
				return true;
			}

			public function mutex_owner() {
				return $this->mutex_owner;
			}
		};
	}

	public function test_native_admission_during_clone_preserves_old_dump_fragments() {
		$fragment = $this->backup_dir . 'dbBackup-clone-fragment.sql';
		file_put_contents( $fragment, 'clone dump fragment' );
		touch( $fragment, time() - 4 * 3600 );
		$backup = $this->make_backup();
		$backup->during_backup = function () use ( $backup, $fragment ) {
			$this->assertSame( array(), MainWP_Child_Backup_Task::instance()->get_active_tasks() );
			$holder = MainWP_Child_Backup_Mutex::holder();
			$this->assertSame( 'legacy', $holder['engine'] );
			$this->assertSame( $backup->mutex_owner(), $holder['owner'] );
			$this->assertFileExists( $backup->pid_path );
			$this->assertStringStartsWith( 'backup-clone-', basename( $backup->pid_path ) );
			$this->assertSame( $backup->archive_path, file_get_contents( $backup->pid_path ) );
			try {
				( new MainWP_Child_Backup_Task_Runner() )->create_task_for_params( 'full', array() );
				$this->fail( 'Native admission must refuse while the clone owns the mutex.' );
			} catch ( MainWP_Exception $e ) {
				$this->assertSame( MainWP_Backup_Error::ERR_ALREADY_RUNNING, $e->get_message_error_code() );
				$this->assertStringContainsString( 'legacy', $e->getMessage() );
			}
			$this->assertFileExists( $fragment );
			$this->assertSame( $holder, MainWP_Child_Backup_Mutex::holder() );
			$this->assertSame( array(), MainWP_Child_Backup_Task::instance()->get_active_tasks() );
			file_put_contents( $backup->archive_path, 'clone archive fixture' );
			return true;
		};
		$this->assertIsArray( $backup->create_clone_backup( array(), 'clone', true, 'zip' ) );
		$this->assertTrue( $backup->archive_called );
		$this->assertFileExists( $fragment );
	}

	public function test_stale_sweep_defers_all_patterns_until_clone_releases_mutex_and_pid() {
		$names = array( 'dbBackup-clone-fragment.sql', 'backup-old.pid', 'backup-old.done', 'manifest-staged-old.list' );
		foreach ( $names as $name ) {
			file_put_contents( $this->backup_dir . $name, 'clone residue' );
			touch( $this->backup_dir . $name, time() - 4 * 3600 );
		}
		$spine = MainWP_Child_Backup_Task::instance();
		$backup = $this->make_backup();
		$backup->during_backup = function () use ( $backup, $spine, $names ) {
			$this->assertSame( array(), $spine->get_active_tasks() );
			$this->assertNotNull( MainWP_Child_Backup_Mutex::holder() );
			$this->assertTrue( MainWP_Backup::is_pid_lock_fresh( $this->backup_dir ) );
			$spine->sweep_stale_markers( $this->backup_dir );
			foreach ( $names as $name ) {
				$this->assertFileExists( $this->backup_dir . $name );
			}
			file_put_contents( $backup->archive_path, 'clone archive fixture' );
			return true;
		};
		$this->assertIsArray( $backup->create_clone_backup( array(), 'clone', true, 'zip' ) );
		$this->assertNull( MainWP_Child_Backup_Mutex::holder() );
		$this->assertFalse( MainWP_Backup::is_pid_lock_fresh( $this->backup_dir ) );
		$spine->sweep_stale_markers( $this->backup_dir );
		foreach ( $names as $name ) {
			$this->assertFileDoesNotExist( $this->backup_dir . $name );
		}
	}

	public function test_stale_sweep_respects_mutex_and_pid_independently() {
		$fragment = $this->backup_dir . 'dbBackup-held.sql';
		file_put_contents( $fragment, 'keep' );
		touch( $fragment, time() - 4 * 3600 );
		$spine = MainWP_Child_Backup_Task::instance();
		$this->assertTrue( MainWP_Child_Backup_Mutex::acquire( 'legacy', 'clone-owner', 3600 ) );
		$spine->sweep_stale_markers( $this->backup_dir );
		$this->assertFileExists( $fragment );
		MainWP_Child_Backup_Mutex::release( 'clone-owner' );
		$pid = $this->backup_dir . 'backup-clone-fresh.pid';
		file_put_contents( $pid, 'archive.zip' );
		$spine->sweep_stale_markers( $this->backup_dir );
		$this->assertFileExists( $fragment );
		unlink( $pid );
		$spine->sweep_stale_markers( $this->backup_dir );
		$this->assertFileDoesNotExist( $fragment );
	}

	public static function sweep_nonblocking_holders() {
		return array(
			'expired holder' => array( -1, null, false ),
			'expiry equals now' => array( 0, null, true ),
			'caller owns live holder' => array( 3600, 'sweep-owner', false ),
		);
	}

	/** @dataProvider sweep_nonblocking_holders */
	public function test_stale_sweep_allows_expired_or_caller_owned_mutex( $ttl, $caller_owner, $deferred ) {
		$fragment = $this->backup_dir . 'dbBackup-dead-run.sql';
		file_put_contents( $fragment, 'stale fragment' );
		touch( $fragment, time() - 4 * 3600 );
		$spine = MainWP_Child_Backup_Task::instance();
		$this->assertSame( array(), $spine->get_active_tasks() );
		$this->assertTrue( MainWP_Child_Backup_Mutex::acquire( 'native', 'sweep-owner', $ttl ) );
		$holder = MainWP_Child_Backup_Mutex::holder();
		$this->assertNotNull( $holder );
		$this->assertFalse( MainWP_Backup::is_pid_lock_fresh( $this->backup_dir ) );
		$spine->sweep_stale_markers( $this->backup_dir, 10800, $caller_owner );
		if ( 0 === $ttl && time() !== $holder['expires_at'] ) {
			$this->markTestSkipped( 'The clock crossed a second during the expiry-boundary check.' );
		}
		if ( $deferred ) {
			$this->assertFileExists( $fragment );
		} else {
			$this->assertFileDoesNotExist( $fragment );
		}
		$this->assertSame( $holder, MainWP_Child_Backup_Mutex::holder() );
	}

	public function test_clone_releases_mutex_and_completes_marker_on_success() {
		$backup = $this->make_backup();
		$result = $backup->create_clone_backup( array(), 'clone', true, 'zip' );

		$this->assertSame( basename( $backup->archive_path ), $result['file'] );
		$this->assertSame( strlen( 'clone archive fixture' ), $result['filesize'] );
		$this->assertNull( MainWP_Child_Backup_Mutex::holder() );
		$this->assertSame( '', $backup->mutex_owner() );
		$this->assertFileDoesNotExist( $backup->pid_path );
		$done = substr( $backup->pid_path, 0, -4 ) . '.done';
		$this->assertFileExists( $done );
		$this->assertSame( $backup->archive_path, file_get_contents( $done ) );
	}

	public function test_clone_releases_mutex_and_propagates_archive_exception() {
		$backup = $this->make_backup();
		$failure = new RuntimeException( 'Clone archive failed.' );
		$backup->during_backup = function () use ( $failure ) {
			$this->assertSame( 'legacy', MainWP_Child_Backup_Mutex::holder()['engine'] );
			throw $failure;
		};
		try {
			$backup->create_clone_backup( array(), 'clone', true, 'zip' );
			$this->fail( 'The archive exception must propagate.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( $failure, $e );
		}
		$this->assertTrue( $backup->archive_called );
		$this->assertNull( MainWP_Child_Backup_Mutex::holder() );
		$this->assertSame( '', $backup->mutex_owner() );
	}

	public function test_clone_preserves_false_archive_result_and_releases_mutex() {
		$backup = $this->make_backup();
		$backup->during_backup = function () {
			return false;
		};
		$this->assertFalse( $backup->create_clone_backup( array(), 'clone', true, 'zip' ) );
		$this->assertTrue( $backup->archive_called );
		$this->assertNull( MainWP_Child_Backup_Mutex::holder() );
		$this->assertSame( '', $backup->mutex_owner() );
	}

	public function test_clone_refuses_native_mutex_before_archiving() {
		$this->assertTrue( MainWP_Child_Backup_Mutex::acquire( 'native', 'native-owner', 3600 ) );
		$backup = $this->make_backup();
		try {
			$backup->create_clone_backup( array(), 'clone', true, 'zip' );
			$this->fail( 'A native owner must refuse the clone entry.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( MainWP_Backup_Error::ERR_ALREADY_RUNNING, $e->get_message_error_code() );
			$this->assertStringContainsString( 'native', $e->getMessage() );
		}
		$this->assertFalse( $backup->archive_called );
		$this->assertSame( 'native-owner', MainWP_Child_Backup_Mutex::holder()['owner'] );
		$this->assertSame( array(), glob( $this->backup_dir . '*.pid' ) );
	}

	public function test_clone_refuses_active_spine_task_without_mutex() {
		$spine = MainWP_Child_Backup_Task::instance();
		$task_id = $spine->create_task( 'full' );
		$this->assertNotFalse( $task_id );
		$this->assertNull( MainWP_Child_Backup_Mutex::holder() );
		$backup = $this->make_backup();
		try {
			$backup->create_clone_backup( array(), 'clone', true, 'zip' );
			$this->fail( 'A live task must refuse the clone even without a mutex.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( MainWP_Backup_Error::ERR_ALREADY_RUNNING, $e->get_message_error_code() );
		}
		$this->assertFalse( $backup->archive_called );
		$this->assertNull( MainWP_Child_Backup_Mutex::holder() );
		$this->assertSame( array(), glob( $this->backup_dir . '*.pid' ) );
	}
}
