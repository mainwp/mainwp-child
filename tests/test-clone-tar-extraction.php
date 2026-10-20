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
			'linked directory' => array( 'directory' ),
			'dotted config' => array( 'config' ),
			'repeated separator config' => array( 'config separators' ),
			'linked config' => array( 'config link' ),
			'late traversal' => array( 'late traversal' ),
			'late longname traversal' => array( 'late longname traversal' ),
			'normal' => array( 'normal' ),
		);
	}

	/** @dataProvider data_clone_tar_paths */
	public function test_clone_tar_confines_entries_to_target( $case ) {
		$root = sys_get_temp_dir() . '/mainwp-clone-tar-' . uniqid();
		mkdir( $root . '/site', 0777, true );
		mkdir( $root . '/outside' );
		$name = 'nested/inside.txt';
		$type = '0';
		$before = '';
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
		} elseif ( 'directory' === $case ) {
			symlink( $root . '/outside', $root . '/site/linked' );
			$name = 'linked/';
			$type = '5';
		} elseif ( 'config' === $case || 'config separators' === $case ) {
			$name = 'config' === $case ? './wp-config.php' : './/wp-config.php';
		} elseif ( 'config link' === $case ) {
			file_put_contents( $root . '/outside/secret.txt', 'original' );
			symlink( $root . '/outside/secret.txt', $root . '/site/wp-config.php' );
			$name = 'wp-config.php';
		} elseif ( 'late traversal' === $case || 'late longname traversal' === $case ) {
			$before = $this->tar_entry_bytes( 'before.txt', str_repeat( 'a', 513 ) );
			$name = '../outside.txt';
			if ( 'late longname traversal' === $case ) {
				$before .= $this->tar_entry_bytes( '././@LongLink', $name . "\0", 'L' );
				$name = 'placeholder.txt';
			}
		}
		$path = $root . '/archive.tar';
		file_put_contents( $path, $before . $this->tar_entry_bytes( $name, '5' === $type ? '' : 'archive content', $type ) . $this->tar_entry_bytes( 'after.txt', 'later entry' ) . $this->tar_entry_bytes( 'wp-config.php', 'source config' ) . str_repeat( "\0", 1024 ) );
		$archiver = new Tar_Archiver( null, 'tar' );
		$archiver->read( $path );
		try {
			$error = null;
			try {
				$archiver->extract_to( $root . '/site' );
			} catch ( Exception $caught ) {
				$error = $caught;
			}
			if ( in_array( $case, array( 'normal', 'leaf', 'directory', 'config', 'config separators', 'config link' ), true ) ) {
				$this->assertNull( $error );
				if ( 'normal' === $case ) {
					$this->assertSame( 'archive content', file_get_contents( $root . '/site/nested/inside.txt' ) );
				}
				$this->assertSame( 'later entry', file_get_contents( $root . '/site/after.txt' ) );
				if ( 'config link' !== $case ) {
					$this->assertFileDoesNotExist( $root . '/site/wp-config.php' );
				}
			} else {
				$this->assertInstanceOf( Exception::class, $error, 'Unsafe entries must abort the extraction.' );
				$this->assertFileDoesNotExist( $root . '/site/before.txt' );
				$this->assertFileDoesNotExist( $root . '/site/after.txt' );
			}
			$this->assertFileDoesNotExist( $root . '/outside.txt' );
			$this->assertFileDoesNotExist( $root . '/absolute.txt' );
			$this->assertFileDoesNotExist( $root . '/outside/escape.txt' );
			if ( 'leaf' === $case || 'config link' === $case ) {
				$this->assertTrue( is_link( $root . '/site/' . ( 'leaf' === $case ? 'linked.txt' : 'wp-config.php' ) ) );
				$this->assertSame( 'original', file_get_contents( $root . '/outside/secret.txt' ) );
			}
			if ( 'directory' === $case ) {
				$this->assertTrue( is_link( $root . '/site/linked' ) );
				$this->assertSame( array( '.', '..' ), scandir( $root . '/outside' ) );
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

	/**
	 * The validation pass reads to the end of the archive, and bzip2 streams cannot seek back for the write pass.
	 */
	public function test_clone_tar_bz2_extracts_after_validation_pass() {
		if ( ! function_exists( 'bzcompress' ) ) {
			$this->markTestSkipped( 'bz2 extension not available.' );
		}
		$root = sys_get_temp_dir() . '/mainwp-clone-bz2-' . uniqid();
		mkdir( $root . '/site', 0777, true );
		$path = $root . '/archive.tar.bz2';
		file_put_contents( $path, bzcompress( $this->tar_entry_bytes( 'nested/inside.txt', str_repeat( 'a', 513 ) ) . $this->tar_entry_bytes( 'after.txt', 'later entry' ) . str_repeat( "\0", 1024 ) ) );
		$archiver = new Tar_Archiver( null, 'tar.bz2' );
		$archiver->read( $path );
		try {
			$archiver->extract_to( $root . '/site' );
			$this->assertSame( str_repeat( 'a', 513 ), file_get_contents( $root . '/site/nested/inside.txt' ) );
			$this->assertSame( 'later entry', file_get_contents( $root . '/site/after.txt' ) );
		} finally {
			$archiver->close();
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $iterator as $file ) {
				$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
			}
			rmdir( $root );
		}
	}
}
