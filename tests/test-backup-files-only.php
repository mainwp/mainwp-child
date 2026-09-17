<?php
/**
 * Files-only backup tests: an empty prepared dump set means the engine
 * dumps nothing and embeds nothing.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Backup;
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
	 * @dataProvider legacy_marker_runs
	 * @param string $type   Backup type.
	 * @param bool   $throws Whether the engine fails.
	 */
	public function test_legacy_marker_covers_run_and_clears_afterward( $type, $throws ) {
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
		$ttl        = null;
		$record_ttl = function ( $expiration ) use ( &$ttl ) {
			$ttl = $expiration;
			return $expiration;
		};
		$backup->during_backup = function () use ( $throws, $failure, $artifact, &$called, &$ttl ) {
			$called = true;
			$marker = get_transient( MainWP_Backup::LEGACY_RUNNING_TRANSIENT );
			$this->assertIsString( $marker );
			$this->assertNotSame( '', $marker );
			$this->assertSame( 10800, $ttl );
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
		$_POST['type']    = $type;
		$_POST['fileUID'] = '';
		$this->assertFalse( get_transient( MainWP_Backup::LEGACY_RUNNING_TRANSIENT ) );
		add_filter( 'expiration_of_transient_' . MainWP_Backup::LEGACY_RUNNING_TRANSIENT, $record_ttl );
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
				$this->assertSame( 'legacy.zip', $result[ 'db' === $type ? 'file' : 'full' ] );
			} catch ( Exception $e ) {
				$this->assertTrue( $throws );
				$this->assertSame( $failure, $e );
			}
			$this->assertTrue( $called, 'The legacy engine seam must run.' );
			$this->assertFalse( get_transient( MainWP_Backup::LEGACY_RUNNING_TRANSIENT ) );
		} finally {
			$_POST = $original_post;
			remove_filter( 'expiration_of_transient_' . MainWP_Backup::LEGACY_RUNNING_TRANSIENT, $record_ttl );
			MainWP_Backup::clear_legacy_backup_running( (string) get_transient( MainWP_Backup::LEGACY_RUNNING_TRANSIENT ) );
			while ( ob_get_level() > $ob_level ) {
				ob_end_clean();
			}
		}
	}

	/**
	 * @return array Legacy entry points and completion paths.
	 */
	public function legacy_marker_runs() {
		return array(
			'full success'   => array( 'full', false ),
			'full exception' => array( 'full', true ),
			'db success'     => array( 'db', false ),
			'db exception'   => array( 'db', true ),
			'files success'  => array( 'files', false ),
		);
	}

	/**
	 * A marker set by one request cannot be cleared by another request's token.
	 */
	public function test_legacy_marker_clears_only_for_its_own_token() {
		try {
			$token = MainWP_Backup::set_legacy_backup_running();
			$this->assertIsString( $token );
			$this->assertSame( $token, get_transient( MainWP_Backup::LEGACY_RUNNING_TRANSIENT ) );

			MainWP_Backup::clear_legacy_backup_running( 'some-other-token' );
			$this->assertSame( $token, get_transient( MainWP_Backup::LEGACY_RUNNING_TRANSIENT ), 'A foreign token must not clear the marker.' );

			MainWP_Backup::clear_legacy_backup_running( $token );
			$this->assertFalse( get_transient( MainWP_Backup::LEGACY_RUNNING_TRANSIENT ) );
		} finally {
			delete_transient( MainWP_Backup::LEGACY_RUNNING_TRANSIENT );
		}
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
}
