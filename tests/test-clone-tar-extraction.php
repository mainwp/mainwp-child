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
			'link entry with data' => array( 'link payload' ),
			'directory entry with data' => array( 'directory payload' ),
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
		} elseif ( 'link payload' === $case || 'directory payload' === $case ) {
			// Entry data is never a header, even when it is shaped like one.
			$before = $this->tar_entry_bytes( 'before.txt', 'before' ) . ( 'link payload' === $case ? $this->tar_entry_bytes( 'link', $this->tar_entry_bytes( '../victim.txt' ), '2' ) : $this->tar_entry_bytes( 'folder/', $this->tar_entry_bytes( '../victim.txt' ), '5' ) );
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
			if ( in_array( $case, array( 'normal', 'leaf', 'directory', 'config', 'config separators', 'config link', 'link payload', 'directory payload' ), true ) ) {
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
			if ( 'link payload' === $case || 'directory payload' === $case ) {
				$this->assertSame( 'before', file_get_contents( $root . '/site/before.txt' ) );
				$this->assertFileDoesNotExist( $root . '/victim.txt' );
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
		file_put_contents( $path, bzcompress( $this->tar_entry_bytes( 'nested/inside.txt', str_repeat( 'a', 513 ) ) . $this->tar_entry_bytes( './wp-config.php', str_repeat( 'c', 700 ) ) . $this->tar_entry_bytes( 'after.txt', 'later entry' ) . str_repeat( "\0", 1024 ) ) );
		$archiver = new Tar_Archiver( null, 'tar.bz2' );
		$archiver->read( $path );
		try {
			$archiver->extract_to( $root . '/site' );
			$this->assertSame( str_repeat( 'a', 513 ), file_get_contents( $root . '/site/nested/inside.txt' ) );
			$this->assertSame( 'later entry', file_get_contents( $root . '/site/after.txt' ) );
			$this->assertFileDoesNotExist( $root . '/site/wp-config.php' );
		} finally {
			$archiver->close();
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $iterator as $file ) {
				$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
			}
			rmdir( $root );
		}
	}
	public function test_clone_tar_nul_padded_octal_fields_do_not_deprecate() {
		$root = sys_get_temp_dir() . '/mainwp-tar-octal-' . uniqid();
		mkdir( $root );
		mkdir( $root . '/site' );
		$path = $root . '/archive.tar';
		file_put_contents( $path, $this->tar_entry_bytes( 'file.txt', 'contents' ) . str_repeat( "\0", 1024 ) );
		$archiver = new Tar_Archiver( null, 'tar' );
		$deprecations = array();
		set_error_handler( static function ( $level, $message ) use ( &$deprecations ) {
			if ( E_DEPRECATED === $level ) { $deprecations[] = $message; return true; }
			return false;
		} );
		try {
			$archiver->read( $path );
			$archiver->extract_to( $root . '/site' );
			$this->assertSame( 'contents', file_get_contents( $root . '/site/file.txt' ) );
			$this->assertSame( array(), $deprecations );
		} finally {
			restore_error_handler();
			$archiver->close();
			unlink( $root . '/site/file.txt' );
			rmdir( $root . '/site' );
			unlink( $path );
			rmdir( $root );
		}
	}

	public static function data_truncated_clone_tar_payloads() {
		$cases = array();
		foreach ( array( 'tar', 'tar.gz', 'tar.bz2' ) as $format ) {
			foreach ( array( '0', '1', '2', '5', 'x', 'g', '7', 'protected' ) as $type ) {
				$cases[ $format . ' ' . $type ] = array( $format, $type );
			}
		}
		return $cases;
	}

	/** @dataProvider data_truncated_clone_tar_payloads */
	public function test_clone_tar_truncated_payload_refuses_before_writing( $format, $type ) {
		if ( 'tar.bz2' === $format && ! function_exists( 'bzcompress' ) ) {
			$this->markTestSkipped( 'bz2 extension not available.' );
		}
		$root = sys_get_temp_dir() . '/mainwp-tar-truncated-' . uniqid();
		mkdir( $root . '/site', 0755, true );
		$name = 'protected' === $type ? 'wp-config.php' : ( '5' === $type ? 'folder/' : 'late.txt' );
		$bytes = $this->tar_entry_bytes( 'before.txt', 'before' ) . substr( $this->tar_entry_bytes( $name, str_repeat( 'x', 1024 ), 'protected' === $type ? '0' : $type ), 0, 513 );
		$path = $root . '/archive.' . $format;
		file_put_contents( $path, 'tar.gz' === $format ? gzencode( $bytes ) : ( 'tar.bz2' === $format ? bzcompress( $bytes ) : $bytes ) );
		$archiver = new Tar_Archiver( null, $format );
		$archiver->read( $path );
		try {
			$caught = null;
			try { $archiver->extract_to( $root . '/site' ); }
			catch ( Exception $error ) { $caught = $error; }
			$this->assertSame( array( '.', '..' ), scandir( $root . '/site' ), 'Validation must refuse a short payload before the first write.' );
			$this->assertInstanceOf( Exception::class, $caught );
			$this->assertStringContainsString( 'Archive ended inside an entry', $caught->getMessage() );
		} finally {
			$archiver->close();
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $iterator as $file ) {
				$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
			}
			rmdir( $root );
		}
	}

	public static function data_clone_tar_damaged_headers() {
		return array( 'checksum mismatch' => array( 'checksum' ), 'regular file named as a directory' => array( 'slash' ) );
	}

	/** @dataProvider data_clone_tar_damaged_headers */
	public function test_clone_tar_damaged_header_refuses_before_writing( $case ) {
		$root = sys_get_temp_dir() . '/mainwp-tar-header-' . uniqid();
		mkdir( $root . '/site', 0755, true );
		$late = 'slash' === $case ? $this->tar_entry_bytes( 'folder/', 'data' ) : $this->tar_entry_bytes( 'late.txt', 'data' );
		if ( 'checksum' === $case ) {
			// The stored checksum no longer matches once a name byte changes.
			$late = substr_replace( $late, 'm', 0, 1 );
		}
		$path = $root . '/archive.tar';
		file_put_contents( $path, $this->tar_entry_bytes( 'before.txt', 'before' ) . $late . str_repeat( "\0", 1024 ) );
		$archiver = new Tar_Archiver( null, 'tar' );
		$archiver->read( $path );
		try {
			$caught = null;
			try { $archiver->extract_to( $root . '/site' ); }
			catch ( Exception $error ) { $caught = $error; }
			$this->assertInstanceOf( Exception::class, $caught );
			$this->assertSame( array( '.', '..' ), scandir( $root . '/site' ), 'Validation must refuse a damaged header before the first write.' );
		} finally {
			$archiver->close();
			rmdir( $root . '/site' );
			unlink( $path );
			rmdir( $root );
		}
	}

	public static function data_clone_tar_longname_lengths() {
		return array( 'limit' => array( 65536 ), 'over limit' => array( 65537 ) );
	}

	/** @dataProvider data_clone_tar_longname_lengths */
	public function test_clone_tar_longname_length_is_bounded_before_reading( $length ) {
		$root = sys_get_temp_dir() . '/mainwp-tar-longname-' . uniqid();
		mkdir( $root . '/site', 0755, true );
		$path = $root . '/archive.tar';
		$before = $this->tar_entry_bytes( 'before.txt', 'before' );
		file_put_contents( $path, $before . $this->tar_entry_bytes( '././@LongLink', str_pad( "inside.txt\0", $length, "\0" ), 'L' ) . $this->tar_entry_bytes( 'placeholder', 'contents' ) . str_repeat( "\0", 1024 ) );
		$archiver = new Tar_Archiver( null, 'tar' );
		$archiver->read( $path );
		try {
			$caught = null;
			try { $archiver->extract_to( $root . '/site' ); }
			catch ( Exception $error ) { $caught = $error; }
			if ( $length > 65536 ) {
				$this->assertInstanceOf( Exception::class, $caught );
				$archive = new ReflectionProperty( $archiver, 'archive' );
				$archive->setAccessible( true );
				$this->assertSame( strlen( $before ) + 512, ftell( $archive->getValue( $archiver ) ), 'An oversized name must be refused before its payload is read.' );
				$this->assertSame( array( '.', '..' ), scandir( $root . '/site' ) );
			} else {
				$this->assertNull( $caught );
				$this->assertSame( 'contents', file_get_contents( $root . '/site/inside.txt' ) );
			}
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
