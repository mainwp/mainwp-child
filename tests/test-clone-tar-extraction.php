<?php

use MainWP\Child\Tar_Archiver;

require_once __DIR__ . '/includes/trait-tar-entry-fixture.php';

class CloneTarExtractionTest extends WP_UnitTestCase {
	use Tar_Entry_Fixture;

	public static function data_clone_tar_paths() {
		return array(
			'traversal' => array( 'traversal' ),
			'absolute' => array( 'absolute' ),
			'linked parent' => array( 'parent' ),
			'linked file' => array( 'leaf' ),
			'normal' => array( 'normal' ),
		);
	}

	/** @dataProvider data_clone_tar_paths */
	public function test_clone_tar_confines_entries_to_target( $case ) {
		$root = sys_get_temp_dir() . '/mainwp-clone-tar-' . uniqid();
		mkdir( $root . '/site', 0777, true );
		mkdir( $root . '/outside' );
		$name = 'nested/inside.txt';
		if ( 'traversal' === $case ) {
			$name = '../outside.txt';
		} elseif ( 'absolute' === $case ) {
			$name = $root . '/absolute.txt';
		} elseif ( 'parent' === $case ) {
			symlink( $root . '/outside', $root . '/site/linked' );
			$name = 'linked/escape.txt';
		} elseif ( 'leaf' === $case ) {
			file_put_contents( $root . '/outside/secret.txt', 'original' );
			symlink( $root . '/outside/secret.txt', $root . '/site/linked.txt' );
			$name = 'linked.txt';
		}
		$path = $root . '/archive.tar';
		file_put_contents( $path, $this->tar_entry_bytes( $name, 'archive content' ) . $this->tar_entry_bytes( 'after.txt', 'later entry' ) . $this->tar_entry_bytes( 'wp-config.php', 'source config' ) . str_repeat( "\0", 1024 ) );
		$archiver = new Tar_Archiver( null, 'tar' );
		$archiver->read( $path );
		try {
			$error = null;
			try {
				$archiver->extract_to( $root . '/site' );
			} catch ( Exception $caught ) {
				$error = $caught;
			}
			if ( 'normal' === $case ) {
				$this->assertNull( $error );
				$this->assertSame( 'archive content', file_get_contents( $root . '/site/nested/inside.txt' ) );
				$this->assertSame( 'later entry', file_get_contents( $root . '/site/after.txt' ) );
				$this->assertFileDoesNotExist( $root . '/site/wp-config.php' );
			} else {
				$this->assertInstanceOf( Exception::class, $error, 'Unsafe entries must abort the extraction.' );
				$this->assertFileDoesNotExist( $root . '/site/after.txt' );
			}
			$this->assertFileDoesNotExist( $root . '/outside.txt' );
			$this->assertFileDoesNotExist( $root . '/absolute.txt' );
			$this->assertFileDoesNotExist( $root . '/outside/escape.txt' );
			if ( 'leaf' === $case ) {
				$this->assertSame( 'original', file_get_contents( $root . '/outside/secret.txt' ) );
			}
		} finally {
			$archiver->close();
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $iterator as $file ) {
				$file->isLink() || ! $file->isDir() ? unlink( $file->getPathname() ) : rmdir( $file->getPathname() );
			}
			rmdir( $root );
		}
	}
}
