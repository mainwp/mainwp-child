<?php
/**
 * Files-only backup tests: an empty prepared dump set means the engine
 * dumps nothing and embeds nothing.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Backup;

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
