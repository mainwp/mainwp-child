<?php
/**
 * Files-only backup tests: an empty prepared dump set means the engine
 * dumps nothing and embeds nothing.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Backup;
use MainWP\Child\MainWP_Backup_Error;
use MainWP\Child\MainWP_Child_Backup_Mutex;
use MainWP\Child\MainWP_Child_Backup_Task;
use MainWP\Child\MainWP_Exception;

/**
 * Backup with the heavy archive step stubbed, recording how the legacy
 * action dispatched it.
 */
class Files_Only_Legacy_Backup extends MainWP_Backup {

	/**
	 * Whether backup_full ran.
	 *
	 * @var bool
	 */
	public $backup_full_called = false;

	/**
	 * The dump set the archive step would embed.
	 *
	 * @var mixed
	 */
	public $dump_set_at_call = 'unset';

	/**
	 * Record the dispatch instead of archiving the site.
	 *
	 * @param string $fileName Backup archive file name.
	 *
	 * @return array Canned result.
	 */
	public function backup_full( $fileName ) {
		$this->backup_full_called = true;
		$this->dump_set_at_call   = $this->get_or_create_db_dump_files( sys_get_temp_dir() . '/mainwp-never-dumped' );
		return array(
			'file'     => 'https://child.test/files-archive.tar.gz',
			'filesize' => 123,
		);
	}
}

/**
 * Class BackupFilesOnlyTest
 */
class BackupFilesOnlyTest extends WP_UnitTestCase {

	/**
	 * Scratch dir.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Build scratch.
	 */
	public function set_up() {
		parent::set_up();
		$this->dir = sys_get_temp_dir() . '/mainwp-files-only-' . uniqid();
		mkdir( $this->dir );
	}

	/**
	 * Clear the prepared set and scratch.
	 */
	public function tear_down() {
		$holder = MainWP_Child_Backup_Mutex::holder();
		if ( null !== $holder ) {
			MainWP_Child_Backup_Mutex::release( $holder['owner'] );
		}
		MainWP_Backup::get()->set_prepared_db_files( null );
		foreach ( (array) glob( $this->dir . '/*' ) as $file ) {
			unlink( $file );
		}
		if ( is_dir( $this->dir ) ) {
			rmdir( $this->dir );
		}
		parent::tear_down();
	}

	/**
	 * An empty prepared set short-circuits the dump entirely: no dump
	 * files are created and none are handed to the archiver. This is the
	 * seam a files-only backup uses to exclude the database.
	 */
	public function test_empty_prepared_db_files_skips_dump() {
		$backup = MainWP_Backup::get();
		$backup->set_prepared_db_files( array() );

		$files = $backup->get_or_create_db_dump_files( $this->dir . '/dbBackup' );

		$this->assertSame( array(), $files );
		$this->assertSame( array(), (array) glob( $this->dir . '/dbBackup*' ), 'No dump file may be created for a files-only backup.' );
	}

	/**
	 * A null prepared set keeps today's behavior: the engine dumps fresh.
	 * (Guarded by type: an empty array and null must stay distinct.)
	 */
	public function test_null_prepared_db_files_still_dumps_fresh() {
		$backup = MainWP_Backup::get();
		$backup->set_prepared_db_files( null );

		$files = $backup->get_or_create_db_dump_files( $this->dir . '/dbBackup' );

		$this->assertNotEmpty( $files, 'A full backup with no prepared dumps must still dump the database.' );
		foreach ( $files as $file ) {
			$this->assertFileExists( $file );
		}
	}

	/**
	 * The legacy backup action dispatches type files to the file archiver
	 * with an empty dump set, so the archive embeds no database - the
	 * same artifact the spine's files type produces, reachable from the
	 * ad-hoc Backup Now wire.
	 */
	public function test_legacy_backup_action_files_type_archives_without_db() {
		$_POST['type']    = 'files';
		$_POST['fileUID'] = '';

		$backup = new Files_Only_Legacy_Backup();
		$info   = $backup->backup( false );

		unset( $_POST['type'], $_POST['fileUID'] );

		$this->assertTrue( $backup->backup_full_called, 'A files backup must run the file archiver.' );
		$this->assertSame( array(), $backup->dump_set_at_call, 'The archive step must see an empty dump set.' );
		$this->assertSame( 'https://child.test/files-archive.tar.gz', $info['full'], 'The files archive rides the full slot.' );
		$this->assertFalse( $info['db'] );
	}

	/**
	 * Both legacy dump entries must protect fragments through archive completion.
	 *
	 * @dataProvider legacy_mutex_runs
	 * @param string $type   Backup type.
	 * @param bool   $throws Whether the engine fails.
	 */
	public function test_legacy_mutex_covers_run_and_clears_afterward( $type, $throws ) {
		$backup = new class() extends MainWP_Backup {
			public $during_backup;

			public function backup_full( $fileName ) {
				return call_user_func( $this->during_backup );
			}

			public function create_backup_db( $filepath_prefix, $archiveExt = false, &$archiver = null ) {
				return call_user_func( $this->during_backup );
			}
		};
		$artifact = $this->dir . '/legacy.zip';
		file_put_contents( $artifact, 'archive' );
		$failure    = 'db' === $type ? new MainWP_Exception( 'Legacy archive failed.', null, '' ) : new RuntimeException( 'Legacy archive failed.' );
		$called     = false;
		$backup->during_backup = function () use ( $throws, $failure, $artifact, &$called ) {
			$called = true;
			$holder = MainWP_Child_Backup_Mutex::holder();
			$this->assertSame( 'legacy', $holder['engine'] );
			$this->assertNotSame( '', $holder['owner'] );
			$this->assertGreaterThan( time(), $holder['expires_at'] );
			$this->assertLessThanOrEqual( time() + 300, $holder['expires_at'] );
			if ( $throws ) {
				throw $failure;
			}
			return array(
				'file'     => basename( $artifact ),
				'filesize' => 7,
				'filepath' => $artifact,
			);
		};
		$original_post   = $_POST;
		$_POST['type']    = 'nested_db' === $type ? 'db' : $type;
		$_POST['fileUID'] = '';
		$this->assertNull( MainWP_Child_Backup_Mutex::holder() );
		// The legacy entry ends with ob_end_flush(); give it a buffer of ours to close so
		// PHPUnit's own buffer survives, then restore the level we started at.
		$ob_level = ob_get_level();
		ob_start();
		try {
			try {
				if ( 'db' === $type ) {
					$result = $backup->backup_db();
				} else {
					$result = $backup->backup( false );
				}
				$this->assertFalse( $throws, 'The engine exception must propagate.' );
				$this->assertSame( 'legacy.zip', $result[ 'db' === $type ? 'file' : ( 'nested_db' === $type ? 'db' : 'full' ) ] );
			} catch ( Exception $e ) {
				$this->assertTrue( $throws );
				$this->assertSame( $failure, $e );
			}
			$this->assertTrue( $called, 'The legacy engine seam must run.' );
			$this->assertNull( MainWP_Child_Backup_Mutex::holder() );
		} finally {
			$_POST = $original_post;
			while ( ob_get_level() > $ob_level ) {
				ob_end_clean();
			}
		}
	}

	/**
	 * @return array Legacy entry points and completion paths.
	 */
	public function legacy_mutex_runs() {
		return array(
			'full success'   => array( 'full', false ),
			'full exception' => array( 'full', true ),
			'db success'     => array( 'db', false ),
			'nested db'      => array( 'nested_db', false ),
			'db exception'   => array( 'db', true ),
			'files success'  => array( 'files', false ),
		);
	}

	/**
	 * A mutex held by one request cannot be cleared by another request's token.
	 */
	public function test_legacy_mutex_clears_only_for_its_own_token() {
		$token = wp_generate_uuid4();
		$this->assertTrue( MainWP_Child_Backup_Mutex::acquire( 'legacy', $token, 300 ) );
		$this->assertFalse( MainWP_Child_Backup_Mutex::release( 'some-other-token' ) );
		$this->assertSame( $token, MainWP_Child_Backup_Mutex::holder()['owner'] );
		$this->assertTrue( MainWP_Child_Backup_Mutex::release( $token ) );
		$this->assertNull( MainWP_Child_Backup_Mutex::holder() );
	}

	/**
	 * The files dispatch leaves no prepared dump set behind on the
	 * instance - a later full backup must dump fresh.
	 */
	public function test_legacy_files_dispatch_resets_prepared_set() {
		$_POST['type']    = 'files';
		$_POST['fileUID'] = '';

		$backup = new Files_Only_Legacy_Backup();
		$backup->backup( false );

		unset( $_POST['type'], $_POST['fileUID'] );

		$files = $backup->get_or_create_db_dump_files( $this->dir . '/dbBackup' );
		$this->assertNotEmpty( $files, 'The prepared set must reset after the files dispatch.' );
	}

	public function test_legacy_backup_refuses_native_mutex_before_filesystem_work() {
		$this->assertTrue( MainWP_Child_Backup_Mutex::acquire( 'native', 'native-owner', 3600 ) );
		$backup = new Files_Only_Legacy_Backup();
		try {
			$backup->backup( false );
			$this->fail( 'A native owner must refuse the legacy entry.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( MainWP_Backup_Error::ERR_ALREADY_RUNNING, $e->get_message_error_code() );
			$this->assertSame( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_ALREADY_RUNNING ) ), $e->getMessage() );
		}
		$this->assertFalse( $backup->backup_full_called );
		$this->assertSame( 'native-owner', MainWP_Child_Backup_Mutex::holder()['owner'] );
	}

	public function test_legacy_state_guard_refuses_a_live_native_row() {
		$spine = MainWP_Child_Backup_Task::instance();
		$task_id = $spine->create_task( 'full' );
		try {
			( new MainWP_Backup() )->assert_no_active_spine_task();
			$this->fail( 'A live row must refuse legacy even without a mutex.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( MainWP_Backup_Error::ERR_ALREADY_RUNNING, $e->get_message_error_code() );
		} finally {
			$spine->checkpoint( $task_id, 0, array( 'phase' => MainWP_Child_Backup_Task::PHASE_FAILED ) );
		}
	}

	public function test_legacy_prefix_survives_append_requests_and_prefers_file_uid() {
		$original = $_POST;
		try {
			$_POST['fileUID'] = 'run-one';
			$_POST['pid'] = '123';
			$prefix = ( new MainWP_Backup() )->db_dump_prefix( $this->dir );
			$_POST['append'] = '1';
			$this->assertSame( $prefix, ( new MainWP_Backup() )->db_dump_prefix( $this->dir ) );
			$this->assertSame( $this->dir . '/dbBackup-run-one', $prefix );
			unset( $_POST['fileUID'] );
			$this->assertSame( $this->dir . '/dbBackup-123', ( new MainWP_Backup() )->db_dump_prefix( $this->dir ) );
			unset( $_POST['pid'] );
			$this->assertNotSame( ( new MainWP_Backup() )->db_dump_prefix( $this->dir ), ( new MainWP_Backup() )->db_dump_prefix( $this->dir ) );
		} finally {
			$_POST = $original;
		}
	}
}
