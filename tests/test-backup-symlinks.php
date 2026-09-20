<?php
/**
 * Class BackupSymlinksTest
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Backup;
use MainWP\Child\Tar_Archiver;
use MainWP\Child\MainWP_Child_Backup_File_Walker;

/**
 * Linked content must retain its archive path without escaping the allowed bases.
 */
class BackupSymlinksTest extends WP_UnitTestCase {

	/**
	 * Scratch directory for fixtures.
	 *
	 * @var string
	 */
	private $scratch;
	private $external;

	/**
	 * Build a scratch tree.
	 */
	public function set_up() {
		parent::set_up();
		$this->scratch = ABSPATH . 'mwp-symlink-test-' . wp_rand( 1000, 999999 );
		mkdir( $this->scratch, 0755, true );
		MainWP_Backup::reset_skipped_paths();
		$this->external = sys_get_temp_dir() . '/mwp-symlink-external-' . uniqid();
		mkdir( $this->external . '/uploads', 0755, true );
		add_filter( 'upload_dir', array( $this, 'external_uploads' ) );
	}

	/**
	 * Remove the scratch tree.
	 */
	public function tear_down() {
		remove_filter( 'upload_dir', array( $this, 'external_uploads' ) );
		$this->remove_tree( $this->scratch );
		$this->remove_tree( $this->external );
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

	public function external_uploads( $uploads ) {
		$uploads['basedir'] = $this->external . '/uploads';
		return $uploads;
	}

	private function link_tree() {
		$tree = $this->scratch . '/tree';
		mkdir( $tree . '/dir', 0755, true );
		mkdir( $tree . '/a' );
		mkdir( $tree . '/b' );
		file_put_contents( $tree . '/real.txt', 'target bytes' );
		file_put_contents( $tree . '/dir/inner.txt', 'directory bytes' );
		file_put_contents( $this->external . '/uploads/photo.txt', 'upload bytes' );
		file_put_contents( $this->external . '/outside.txt', 'outside bytes' );
		symlink( 'real.txt', $tree . '/linked.txt' );
		symlink( 'dir', $tree . '/linked-dir' );
		symlink( $this->external . '/uploads', $tree . '/uploads' );
		symlink( $this->external . '/outside.txt', $tree . '/outside.txt' );
		symlink( $this->external, $tree . '/outside-dir' );
		symlink( 'missing', $tree . '/dangling' );
		symlink( '..', $tree . '/dir/loop' );
		symlink( '../b', $tree . '/a/to-b' );
		symlink( '../a', $tree . '/b/to-a' );
		return $tree;
	}

	public function test_safe_path_matrix() {
		$tree = $this->link_tree();
		$cases = array(
			'real.txt'       => true,
			'linked.txt'     => true,
			'linked-dir'     => true,
			'uploads'        => true,
			'outside.txt'    => false,
			'outside-dir'    => false,
			'dangling'       => false,
			'dir/loop'       => false,
			'a/to-b/to-a'    => false,
		);
		foreach ( $cases as $path => $expected ) {
			$ancestors = array( $tree );
			if ( 'dir/loop' === $path ) {
				$ancestors[] = $tree . '/dir';
			} elseif ( 'a/to-b/to-a' === $path ) {
				$ancestors[] = $tree . '/a';
				$ancestors[] = $tree . '/a/to-b';
			}
			$this->assertSame( $expected, MainWP_Backup::is_safe_backup_path( $tree . '/' . $path, null, $ancestors ), $path );
		}
		$this->assertTrue( MainWP_Backup::is_safe_backup_path( $tree . '/linked.txt', array( $tree ) ) );
		$this->assertFalse( MainWP_Backup::is_safe_backup_path( $tree . '/uploads', array( $tree ) ) );
	}

	/**
	 * Paths that resolve outside the allowed bases are rejected.
	 */
	public function test_rejects_paths_outside_base() {
		$this->assertFalse( MainWP_Backup::is_safe_backup_path( '/etc/hosts' ) );
		$this->assertFalse( MainWP_Backup::is_safe_backup_path( $this->external ) );
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

	private function backup() {
		return new class() extends MainWP_Backup {
			public function extend_archive_mutex_lease() {}
		};
	}

	private function assert_link_entries( $entries, $tree ) {
		$this->assertSame( 'target bytes', isset( $entries['linked.txt'] ) ? $entries['linked.txt'] : null );
		$this->assertSame( 'directory bytes', isset( $entries['linked-dir/inner.txt'] ) ? $entries['linked-dir/inner.txt'] : null );
		$this->assertSame( 'upload bytes', isset( $entries['uploads/photo.txt'] ) ? $entries['uploads/photo.txt'] : null );
		foreach ( array( 'outside.txt', 'outside-dir', 'dangling', 'dir/loop', 'linked-dir/loop', 'a/to-b/to-a', 'b/to-a/to-b' ) as $refused ) {
			$this->assertContains( $tree . '/' . $refused, MainWP_Backup::get_skipped_paths(), $refused );
			foreach ( array_keys( $entries ) as $entry ) {
				$this->assertFalse( $entry === $refused || 0 === strpos( $entry, $refused . '/' ), $entry );
			}
		}
	}

	public function test_zip_descends_links_and_records_refusals() {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive unavailable.' );
		}
		$tree = $this->link_tree();
		$backup = $this->backup();
		$zip = new ZipArchive();
		$archive = $this->external . '/test.zip';
		$this->assertTrue( $zip->open( $archive, ZipArchive::CREATE ) );
		$slot = new ReflectionProperty( MainWP_Backup::class, 'zip' );
		$slot->setAccessible( true );
		$slot->setValue( $backup, $zip );
		$backup->zip_add_dir( $tree, array() );
		$this->assertTrue( $zip->close() );
		$this->assertTrue( $zip->open( $archive ) );
		$prefix = str_replace( ABSPATH, '', $tree ) . '/';
		$entries = array();
		for ( $i = 0; $i < $zip->numFiles; ++$i ) {
			$entries[ substr( $zip->getNameIndex( $i ), strlen( $prefix ) ) ] = $zip->getFromIndex( $i );
		}
		$zip->close();
		$this->assert_link_entries( $entries, $tree );
	}

	public function test_tar_descends_links_and_records_refusals() {
		$tree = $this->link_tree();
		$archive = $this->external . '/test.tar';
		$tar = new Tar_Archiver( $this->backup(), 'tar' );
		$tar->create( $archive );
		$tar->add_dir( $tree, array() );
		$tar->close();
		$prefix = str_replace( ABSPATH, '', $tree ) . '/';
		$entries = array();
		$handle = fopen( $archive, 'rb' );
		while ( 512 === strlen( $header = fread( $handle, 512 ) ) && trim( $header, "\0" ) !== '' ) {
			$name = rtrim( substr( $header, 0, 100 ), "\0" );
			$size = octdec( trim( substr( $header, 124, 12 ), " \0" ) );
			$data = $size > 0 ? fread( $handle, $size ) : '';
			$entries[ substr( $name, strlen( $prefix ) ) ] = $data;
			if ( $size % 512 ) {
				fseek( $handle, 512 - $size % 512, SEEK_CUR );
			}
		}
		fclose( $handle );
		$this->assert_link_entries( $entries, $tree );
	}

	public function test_copy_descends_links_and_records_refusals() {
		$tree = $this->link_tree();
		$out = $this->external . '/copy';
		$copied = $out . '/' . str_replace( ABSPATH, '', $tree );
		mkdir( dirname( $copied ), 0755, true );
		$this->backup()->copy_dir( array( $tree ), array(), $out . '/', false );
		$entries = array();
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $copied, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			if ( $file->isFile() ) {
				$entries[ substr( $file->getPathname(), strlen( $copied ) + 1 ) ] = file_get_contents( $file->getPathname() );
			}
		}
		$this->assert_link_entries( $entries, $tree );
	}

	public function test_walker_descends_links_and_records_refusals_after_resume() {
		$tree = $this->link_tree();
		$out = $this->external . '/sidecars';
		mkdir( $out );
		$cursor = null;
		$done = false;
		for ( $slice = 0; $slice < 100 && ! $done; ++$slice ) {
			$walker = new class( $tree ) extends MainWP_Child_Backup_File_Walker {
				private $steps = 0;
				protected function walk_budget_exhausted( $deadline ) {
					return ++$this->steps > 2;
				}
			};
			$result = $walker->emit_sidecars_slice( $out, $cursor, PHP_INT_MAX );
			$cursor = $result['cursor'];
			$done = $result['done'];
		}
		$this->assertTrue( $done, 'Cycles must terminate across resumed slices.' );
		$entries = array();
		foreach ( file( $out . '/files.list.staged', FILE_IGNORE_NEW_LINES ) as $line ) {
			$row = explode( "\t", $line );
			$entries[ $row[0] ] = file_get_contents( $tree . '/' . $row[0] );
		}
		$this->assert_link_entries( $entries, $tree );
	}
}
