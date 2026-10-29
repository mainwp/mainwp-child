<?php
/**
 * Class BackupDumpLitterExclusionTest
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Backup;
use MainWP\Child\MainWP_Helper;
use MainWP\Child\Tar_Archiver;

/**
 * Tests for the backup-time dump-litter exclusion.
 *
 * A restore that replays an embedded dump deposits dbBackup*.sql(.php)
 * files at the site root and wp-content. The restore sweeps them, but any
 * litter present at backup time must never be archived: a stale options
 * dump riding inside a backup can win extraction on a later restore and
 * silently roll the site back. Every archive traversal (zip, tar, manifest
 * walker) shares one litter definition, which must match the restore
 * sweep's globs exactly - dbBackup*.sql and dbBackup*.sql.php at the site
 * root and directly under wp-content, nothing deeper, nothing broader.
 */
class BackupDumpLitterExclusionTest extends WP_UnitTestCase {

	/**
	 * Files planted into the live tree, removed on tear down.
	 *
	 * @var string[]
	 */
	private $planted = array();

	/**
	 * Archive scratch paths removed on tear down.
	 *
	 * @var string[]
	 */
	private $archives = array();

	/**
	 * Reset archiver counters.
	 */
	public function set_up() {
		parent::set_up();
		MainWP_Backup::get()->reset_failed_adds();
		MainWP_Backup::reset_skipped_paths();
	}

	/**
	 * Remove planted litter and scratch archives.
	 */
	public function tear_down() {
		foreach ( $this->planted as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		foreach ( $this->archives as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		MainWP_Backup::get()->reset_failed_adds();
		MainWP_Backup::reset_skipped_paths();
		parent::tear_down();
	}

	/**
	 * Plant a file into the live tree and register it for cleanup.
	 *
	 * @param string $path    Absolute path.
	 * @param string $content File content.
	 *
	 * @return string The planted path.
	 */
	private function plant( $path, $content = 'stale dump' ) {
		file_put_contents( $path, $content ); // phpcs:ignore -- test fixture.
		$this->planted[] = $path;
		return $path;
	}

	/**
	 * List entry names from a tar file by walking its 512-byte headers.
	 *
	 * @param string $path Tar file path.
	 *
	 * @return string[] Entry names.
	 */
	private function list_tar_entries( $path ) {
		$entries = array();
		$fh      = fopen( $path, 'rb' ); // phpcs:ignore -- test reader.
		while ( ! feof( $fh ) ) {
			$header = fread( $fh, 512 );
			if ( false === $header || strlen( $header ) < 512 ) {
				break;
			}
			$name = rtrim( substr( $header, 0, 100 ), "\0 " );
			if ( '' === $name ) {
				break;
			}
			$size      = octdec( trim( substr( $header, 124, 12 ) ) );
			$entries[] = $name;
			if ( $size > 0 ) {
				fseek( $fh, (int) ( ceil( $size / 512 ) * 512 ), SEEK_CUR );
			}
		}
		fclose( $fh );
		return $entries;
	}

	/**
	 * Relpaths the restore sweep would delete are litter.
	 *
	 * @return void
	 */
	public function test_is_dump_litter_matches_the_restore_sweep_definition() {
		$litter = array(
			'dbBackup.sql',
			'dbBackup-wp_options.sql.php',
			'dbBackup-mysite-1781184698-wp_posts.sql.php',
			'wp-content/dbBackup.sql',
			'wp-content/dbBackup-wp_options.sql.php',
			'/dbBackup.sql',
		);
		foreach ( $litter as $relpath ) {
			$this->assertTrue( MainWP_Helper::is_dump_litter( $relpath ), $relpath . ' is restore-sweep litter and must be excluded.' );
		}
	}

	/**
	 * Anything the restore sweep would not delete is site content and must
	 * never be excluded: wrong extension, wrong basename, or too deep.
	 *
	 * @return void
	 */
	public function test_is_dump_litter_never_matches_site_content() {
		$content = array(
			'dbBackupNotes.txt',
			'dbBackup.sql.bak',
			'my-dbBackup.sql',
			'data.sql',
			'wp-content/uploads/dbBackup-deep.sql.php',
			'wp-content/plugins/foo/dbBackup.sql',
			'wp-includes/dbBackup.sql',
			'',
		);
		foreach ( $content as $relpath ) {
			$this->assertFalse( MainWP_Helper::is_dump_litter( $relpath ), $relpath . ' is site content and must stay included.' );
		}
	}

	/**
	 * The zip traversal never archives wp-content dump litter, while a
	 * non-litter sibling file is archived as before.
	 *
	 * @return void
	 */
	public function test_zip_traversal_excludes_dump_litter() {
		if ( ! class_exists( '\ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive unavailable.' );
		}

		$litter  = $this->plant( WP_CONTENT_DIR . '/dbBackup-litter-test.sql.php' );
		$control = $this->plant( WP_CONTENT_DIR . '/litter-control.txt', 'real content' );

		$archive          = sys_get_temp_dir() . '/mwp-litter-' . uniqid() . '.zip';
		$this->archives[] = $archive;

		$backup = MainWP_Backup::get();
		$zip    = new \ZipArchive();
		$zip->open( $archive, \ZipArchive::CREATE );
		$prop = new ReflectionProperty( MainWP_Backup::class, 'zip' );
		$prop->setAccessible( true );
		$prop->setValue( $backup, $zip );

		$excludes = array( 'wp-content/plugins', 'wp-content/themes', 'wp-content/uploads' );
		$backup->zip_add_dir( WP_CONTENT_DIR, $excludes );
		$backup->close_zip_archive();

		$reader = new \ZipArchive();
		$reader->open( $archive );
		$this->assertFalse( $reader->statName( str_replace( ABSPATH, '', $litter ) ), 'Dump litter must never ride into a zip backup.' );
		$this->assertNotFalse( $reader->statName( str_replace( ABSPATH, '', $control ) ), 'Non-litter siblings are archived as before.' );
		$reader->close();
	}

	/**
	 * The tar traversal never archives wp-content dump litter, while a
	 * non-litter sibling file is archived as before.
	 *
	 * @return void
	 */
	public function test_tar_traversal_excludes_dump_litter() {
		$litter  = $this->plant( WP_CONTENT_DIR . '/dbBackup-litter-test.sql.php' );
		$control = $this->plant( WP_CONTENT_DIR . '/litter-control.txt', 'real content' );

		$archive          = sys_get_temp_dir() . '/mwp-litter-' . uniqid() . '.tar';
		$this->archives[] = $archive;

		$archiver = new Tar_Archiver( MainWP_Backup::get(), 'tar' );
		$archiver->create( $archive );
		$excludes = array( 'wp-content/plugins', 'wp-content/themes', 'wp-content/uploads' );
		$archiver->add_dir( WP_CONTENT_DIR, $excludes );
		$archiver->close();

		$entries = $this->list_tar_entries( $archive );
		$this->assertNotContains( str_replace( ABSPATH, '', $litter ), $entries, 'Dump litter must never ride into a tar backup.' );
		$this->assertContains( str_replace( ABSPATH, '', $control ), $entries, 'Non-litter siblings are archived as before.' );
	}
}
