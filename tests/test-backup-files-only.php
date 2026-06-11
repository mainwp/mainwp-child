<?php
/**
 * Files-only backup tests: an empty prepared dump set means the engine
 * dumps nothing and embeds nothing.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Backup;

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
}
