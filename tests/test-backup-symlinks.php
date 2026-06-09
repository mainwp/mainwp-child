<?php
/**
 * Class BackupSymlinksTest
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Backup;

/**
 * Tests for symlink skipping and realpath containment during backup traversal (1B.16).
 */
class BackupSymlinksTest extends WP_UnitTestCase {

	/**
	 * Scratch directory for fixtures.
	 *
	 * @var string
	 */
	private $scratch;

	/**
	 * Build a scratch tree.
	 */
	public function set_up() {
		parent::set_up();
		$this->scratch = sys_get_temp_dir() . '/mwp-symlink-test-' . wp_rand( 1000, 999999 );
		mkdir( $this->scratch, 0755, true );
		MainWP_Backup::reset_skipped_paths();
	}

	/**
	 * Remove the scratch tree.
	 */
	public function tear_down() {
		$this->remove_tree( $this->scratch );
		MainWP_Backup::reset_skipped_paths();
		parent::tear_down();
	}

	/**
	 * Recursively delete a scratch tree without following symlinks.
	 *
	 * @param string $dir Directory to remove.
	 */
	private function remove_tree( $dir ) {
		if ( ! is_dir( $dir ) || is_link( $dir ) ) {
			if ( file_exists( $dir ) || is_link( $dir ) ) {
				unlink( $dir );
			}
			return;
		}
		foreach ( scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_link( $path ) || is_file( $path ) ) {
				unlink( $path );
			} else {
				$this->remove_tree( $path );
			}
		}
		rmdir( $dir );
	}

	/**
	 * Regular files inside the site root are safe.
	 */
	public function test_accepts_files_inside_abspath() {
		$this->assertTrue( MainWP_Backup::is_safe_backup_path( ABSPATH . 'wp-load.php' ) );
		$this->assertTrue( MainWP_Backup::is_safe_backup_path( rtrim( ABSPATH, '/' ) ) );
	}

	/**
	 * Symlinks are rejected even when they point inside the site root.
	 */
	public function test_rejects_symlinks() {
		$target = $this->scratch . '/target.txt';
		file_put_contents( $target, 'data' );
		$link = $this->scratch . '/link.txt';
		symlink( $target, $link );

		$this->assertFalse( MainWP_Backup::is_safe_backup_path( $link, array( $this->scratch ) ) );
		// The non-link target itself is fine.
		$this->assertTrue( MainWP_Backup::is_safe_backup_path( $target, array( $this->scratch ) ) );
	}

	/**
	 * Paths that resolve outside the allowed bases are rejected.
	 */
	public function test_rejects_paths_outside_base() {
		$this->assertFalse( MainWP_Backup::is_safe_backup_path( '/etc/hosts' ) );
		$this->assertFalse( MainWP_Backup::is_safe_backup_path( $this->scratch ) );
	}

	/**
	 * A sibling directory sharing the base as a string prefix is rejected.
	 *
	 * realpath() has no trailing slash, so a plain strpos prefix check passes
	 * for /base-evil when the base is /base. The trailing-slash compare closes
	 * that hole.
	 */
	public function test_rejects_sibling_prefix_directory() {
		$base = $this->scratch . '/backup-abc123';
		$evil = $this->scratch . '/backup-abc123-evil';
		mkdir( $base );
		mkdir( $evil );
		file_put_contents( $evil . '/file.txt', 'evil' );

		$this->assertFalse( MainWP_Backup::is_safe_backup_path( $evil . '/file.txt', array( $base ) ) );
		$this->assertTrue( MainWP_Backup::is_safe_backup_path( $base, array( $base ) ) );
	}

	/**
	 * Nonexistent paths are rejected (realpath fails).
	 */
	public function test_rejects_nonexistent_paths() {
		$this->assertFalse( MainWP_Backup::is_safe_backup_path( $this->scratch . '/does-not-exist' ) );
		$this->assertFalse( MainWP_Backup::is_safe_backup_path( '' ) );
	}

	/**
	 * Skipped paths are recorded for the backup report, with a cap.
	 */
	public function test_skipped_paths_are_recorded_and_capped() {
		for ( $i = 0; $i < 150; $i++ ) {
			MainWP_Backup::record_skipped_path( '/tmp/skipped-' . $i );
		}
		$skipped = MainWP_Backup::get_skipped_paths();
		$this->assertContains( '/tmp/skipped-0', $skipped );
		$this->assertLessThanOrEqual( 100, count( $skipped ) );
	}

	/**
	 * zip_add_dir() excludes symlinked files and directories from the archive.
	 */
	public function test_zip_add_dir_excludes_symlinks() {
		if ( ! class_exists( '\ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive unavailable.' );
		}

		// Tree: real file, symlinked file, symlinked dir with a file inside.
		$tree = $this->scratch . '/tree';
		mkdir( $tree );
		file_put_contents( $tree . '/real.txt', 'real' );
		file_put_contents( $this->scratch . '/outside.txt', 'outside' );
		symlink( $this->scratch . '/outside.txt', $tree . '/linked.txt' );
		mkdir( $this->scratch . '/outside-dir' );
		file_put_contents( $this->scratch . '/outside-dir/inner.txt', 'inner' );
		symlink( $this->scratch . '/outside-dir', $tree . '/linked-dir' );

		$backup  = MainWP_Backup::get();
		$zip     = new \ZipArchive();
		$zipfile = $this->scratch . '/test.zip';
		$zip->open( $zipfile, \ZipArchive::CREATE );

		$prop = new ReflectionProperty( MainWP_Backup::class, 'zip' );
		$prop->setAccessible( true );
		$prop->setValue( $backup, $zip );

		// Allow the scratch tree as a base so the real file is addable.
		$backup->zip_add_dir( $tree, array(), array( $this->scratch ) );
		$zip->close();

		$zip = new \ZipArchive();
		$zip->open( $zipfile );
		$names = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$names[] = $zip->getNameIndex( $i );
		}
		$zip->close();

		$joined = implode( "\n", $names );
		$this->assertStringContainsString( 'real.txt', $joined );
		$this->assertStringNotContainsString( 'linked.txt', $joined );
		$this->assertStringNotContainsString( 'inner.txt', $joined );
		$this->assertNotEmpty( MainWP_Backup::get_skipped_paths() );
	}
}
