<?php
/**
 * Class TarArchiverLongNamesTest
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Backup;
use MainWP\Child\MainWP_Backup_Error;
use MainWP\Child\MainWP_Child_Backup_File_Restorer;
use MainWP\Child\MainWP_Exception;
use MainWP\Child\MainWP_Helper;
use MainWP\Child\Tar_Archiver;

require_once __DIR__ . '/includes/trait-tar-entry-fixture.php';

/**
 * Names Tar_Archiver writes must read back whole through every reader.
 *
 * Names of 100 bytes or more are stored as a GNU `L` entry whose payload holds the full
 * name. Every reader (extract_to(), the append walk in is_next_file(), get_from_name(),
 * file_exists(), and PharData for native restores) has to step over that payload the way
 * the writer laid it out.
 */
class TarArchiverLongNamesTest extends WP_UnitTestCase {
	use Tar_Entry_Fixture;

	/**
	 * Site-root fixture tree for archive payloads.
	 *
	 * @var string
	 */
	private $fixture;

	/**
	 * Scratch directory for archives and extraction.
	 *
	 * @var string
	 */
	private $scratch;

	/**
	 * Build the scratch directories.
	 */
	public function set_up() {
		parent::set_up();
		$this->fixture = ABSPATH . 'mwp-long-' . uniqid();
		$this->scratch = sys_get_temp_dir() . '/mwp-long-' . uniqid();
		mkdir( $this->fixture );
		mkdir( $this->scratch . '/site', 0777, true );
	}

	/**
	 * Remove fixtures and archives.
	 */
	public function tear_down() {
		MainWP_Helper::delete_dir( $this->fixture );
		MainWP_Helper::delete_dir( $this->scratch );
		parent::tear_down();
	}

	/**
	 * Both formats the backup engine appends to.
	 *
	 * @return array
	 */
	public function archive_types() {
		return array(
			'tar'    => array( 'tar' ),
			'tar.gz' => array( 'tar.gz' ),
		);
	}

	/**
	 * A site-relative name of an exact length under the fixture, in segments a filesystem accepts.
	 *
	 * @param int    $length Name length in bytes.
	 * @param string $tag    Keeps names of different rows apart.
	 *
	 * @return string
	 */
	private function disk_name( $length, $tag = 'f' ) {
		$name = basename( $this->fixture ) . '/';
		while ( $length - strlen( $name ) > 220 ) {
			$name .= str_pad( $tag . $length . '-', 200, 'd' ) . '/';
		}
		return $name . str_pad( $tag . $length . '-', $length - strlen( $name ), 'x' );
	}

	/**
	 * A backup engine with no database dump.
	 *
	 * @return MainWP_Backup
	 */
	private function backup() {
		return new class() extends MainWP_Backup {
			public function extend_archive_mutex_lease() {}
			public function get_or_create_db_dump_files( $prefix, &$archiver = null ) {
				return array();
			}
		};
	}

	/**
	 * Walk only the fixture, as run_file_archiver() walks the site root.
	 *
	 * @param string $path   Archive path.
	 * @param string $type   Archive type.
	 * @param bool   $append Append mode.
	 */
	private function run_pass( $path, $type, $append ) {
		$excludes = array( '.htaccess' );
		foreach ( glob( ABSPATH . '*' ) as $node ) {
			if ( $node !== $this->fixture ) {
				$excludes[] = str_replace( ABSPATH, '', $node );
			}
		}
		$archiver = new Tar_Archiver( $this->backup(), $type );
		try {
			$archiver->create_full_backup( $path, $excludes, true, true, false, false, $append );
		} finally {
			$archiver->close();
		}
	}

	/**
	 * Decoded tar bytes.
	 *
	 * @param string $type Archive type.
	 * @param string $path Archive path.
	 *
	 * @return string
	 */
	private function tar_bytes( $type, $path ) {
		if ( 'tar' === $type ) {
			return file_get_contents( $path );
		}
		// A resumed archive is several gzip members; gzdecode() would stop after the first.
		$stream = gzopen( $path, 'rb' );
		$bytes  = stream_get_contents( $stream );
		gzclose( $stream );
		return $bytes;
	}

	/**
	 * Every entry as GNU tar reads it: an `L` payload spans the blocks its declared size needs.
	 *
	 * @param string $bytes Tar bytes.
	 *
	 * @return array[] Entries with name, type, content, the offset where the entry starts and its `L` block count.
	 */
	private function gnu_entries( $bytes ) {
		$entries = array();
		$offset  = 0;
		while ( $offset + 512 <= strlen( $bytes ) ) {
			$start  = $offset;
			$header = substr( $bytes, $offset, 512 );
			if ( str_repeat( "\0", 512 ) === $header ) {
				break;
			}
			$this->assertSame( 'ustar', substr( $header, 257, 5 ), 'Every header must be a ustar header at offset ' . $offset . '.' );
			$offset += 512;
			$name    = rtrim( substr( $header, 345, 155 ), "\0" ) . rtrim( substr( $header, 0, 100 ), "\0" );
			$blocks  = 0;
			if ( 'L' === $header[156] ) {
				$size    = (int) octdec( trim( substr( $header, 124, 12 ), "\0 " ) );
				$blocks  = (int) ceil( $size / 512 );
				$name    = rtrim( substr( $bytes, $offset, $size ), "\0" );
				$offset += $blocks * 512;
				$header  = substr( $bytes, $offset, 512 );
				$offset += 512;
			}
			$size      = (int) octdec( trim( substr( $header, 124, 12 ), "\0 " ) );
			$entries[] = array(
				'name'    => $name,
				'type'    => $header[156],
				'content' => substr( $bytes, $offset, $size ),
				'start'   => $start,
				'blocks'  => $blocks,
			);
			$offset   += (int) ( ceil( $size / 512 ) * 512 );
		}
		return $entries;
	}

	/**
	 * Entry names, in archive order.
	 *
	 * @param array[] $entries Entries from gnu_entries().
	 *
	 * @return string[]
	 */
	private function names( $entries ) {
		return array_column( $entries, 'name' );
	}

	/**
	 * The three read-only readers against an archive.
	 *
	 * @param string   $path     Archive path.
	 * @param string   $type     Archive type.
	 * @param string[] $files    Contents by entry name.
	 * @param string[] $dirs     Directory entry names.
	 * @param string[] $skipped  Entry names extraction must leave unwritten.
	 */
	private function assert_readers( $path, $type, $files, $dirs, $skipped = array() ) {
		$reader = new Tar_Archiver( null, $type );
		$reader->read( $path );
		try {
			foreach ( $files as $name => $content ) {
				$this->assertSame( $content, $reader->get_from_name( $name ), 'get_from_name() must find ' . strlen( $name ) . ' bytes.' );
				$this->assertTrue( $reader->file_exists( $name ), 'file_exists() must find the ' . strlen( $name ) . '-byte file.' );
			}
			foreach ( $dirs as $name ) {
				$this->assertTrue( $reader->file_exists( $name ), 'file_exists() must find the ' . strlen( $name ) . '-byte directory.' );
			}
			$this->assertFalse( $reader->file_exists( 'missing.txt' ) );
			$reader->extract_to( $this->scratch . '/site' );
		} finally {
			$reader->close();
		}
		foreach ( $files as $name => $content ) {
			if ( in_array( $name, $skipped, true ) ) {
				continue;
			}
			$this->assertSame( $content, file_get_contents( $this->scratch . '/site/' . $name ), 'extract_to() must write the ' . strlen( $name ) . '-byte name in full.' );
		}
		foreach ( $dirs as $name ) {
			$this->assertDirectoryExists( $this->scratch . '/site/' . $name );
		}
		// The native restorer decompresses to a plain tar and reads it through PharData, which joins a ustar prefix with a slash.
		$plain = $this->scratch . '/plain-' . uniqid() . '.tar';
		file_put_contents( $plain, $this->tar_bytes( $type, $path ) );
		$phar = new PharData( $plain );
		foreach ( $files as $name => $content ) {
			if ( ! in_array( $name, $skipped, true ) ) {
				$this->assertSame( $content, file_get_contents( 'phar://' . $phar->getPath() . '/' . $name ), 'PharData must read the ' . strlen( $name ) . '-byte name in full.' );
			}
		}
		unset( $phar );
	}

	/**
	 * Files on disk at every boundary length round-trip through a real backup, a resumed
	 * backup and every reader.
	 *
	 * @dataProvider archive_types
	 * @param string $type Archive type.
	 */
	public function test_long_names_round_trip_through_every_reader( $type ) {
		$files = array();
		foreach ( array( 100, 101, 512, 513, 600, 900 ) as $length ) {
			$name = $this->disk_name( $length );
			$this->assertSame( $length, strlen( $name ) );
			if ( ! is_dir( dirname( ABSPATH . $name ) ) ) {
				mkdir( dirname( ABSPATH . $name ), 0777, true );
			}
			file_put_contents( ABSPATH . $name, 'content of ' . $length );
			$files[ $name ] = 'content of ' . $length;
		}
		$dir = $this->disk_name( 600, 'e' );
		mkdir( ABSPATH . $dir, 0777, true );
		$path = $this->scratch . '/backup.' . $type;
		$this->run_pass( $path, $type, false );

		$entries = $this->gnu_entries( $this->tar_bytes( $type, $path ) );
		$names   = $this->names( $entries );
		foreach ( array_merge( array_keys( $files ), array( $dir ) ) as $name ) {
			$this->assertContains( $name, $names, 'GNU tar must read the ' . strlen( $name ) . '-byte name in full.' );
		}
		foreach ( $entries as $entry ) {
			if ( $entry['blocks'] > 0 ) {
				$this->assertSame( (int) ceil( strlen( $entry['name'] ) / 512 ), $entry['blocks'], 'A long name fills the blocks its length needs.' );
			}
		}
		$this->assertContains( 'clone/config.txt', $names );

		$this->run_pass( $path, $type, true );
		$resumed = $this->names( $this->gnu_entries( $this->tar_bytes( $type, $path ) ) );
		$this->assertSame( $names, $resumed, 'A re-run over a finished archive must match every long name, not add it again.' );

		// A slice that yielded after the long entries ends on a member boundary.
		$bytes   = $this->tar_bytes( $type, $path );
		$entries = $this->gnu_entries( $bytes );
		$cut     = substr( $bytes, 0, $entries[ array_search( 'clone', $this->names( $entries ), true ) ]['start'] );
		file_put_contents( $path, 'tar' === $type ? $cut : gzencode( $cut ) );
		$this->run_pass( $path, $type, true );
		$this->assertSame( $names, $this->names( $this->gnu_entries( $this->tar_bytes( $type, $path ) ) ), 'Appending after the long entries must add only what is missing.' );

		$this->assert_readers( $path, $type, $files, array( $dir ) );
		$reader = new Tar_Archiver( null, $type );
		$reader->read( $path );
		$config = json_decode( $reader->get_from_name( 'clone/config.txt' ), true );
		$this->assertTrue( $reader->file_exists( 'clone/config.txt' ) );
		$reader->close();
		$this->assertSame( ABSPATH, $config['abspath'], 'Entries after long names must still be found.' );
	}

	/**
	 * A resumed archive torn inside a long-name payload is corrupt, as a torn header is.
	 *
	 * @dataProvider archive_types
	 * @param string $type Archive type.
	 */
	public function test_resume_refuses_archive_torn_inside_long_name( $type ) {
		$name = $this->disk_name( 900 );
		mkdir( dirname( ABSPATH . $name ), 0777, true );
		file_put_contents( ABSPATH . $name, 'payload' );
		$path = $this->scratch . '/backup.' . $type;
		$this->run_pass( $path, $type, false );
		$bytes = $this->tar_bytes( $type, $path );
		foreach ( $this->gnu_entries( $bytes ) as $entry ) {
			if ( $name === $entry['name'] ) {
				$torn = substr( $bytes, 0, $entry['start'] + 1024 );
			}
		}
		file_put_contents( $path, 'tar' === $type ? $torn : gzencode( $torn ) );
		try {
			$this->run_pass( $path, $type, true );
			$this->fail( 'A torn long name must not be matched or appended to.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( MainWP_Backup_Error::ERR_FILE_CORRUPT, $e->get_message_error_code() );
		}
	}

	/**
	 * Names no filesystem path could hold, written straight through the string writer,
	 * including payloads of three and four blocks.
	 *
	 * @dataProvider archive_types
	 * @param string $type Archive type.
	 */
	public function test_long_names_written_directly( $type ) {
		$files = array(
			str_repeat( 'a', 100 )                                                     => 'one segment, 100 bytes',
			str_repeat( 'b', 101 )                                                     => 'one segment, long name',
			str_repeat( 'c', 200 ) . '/' . str_repeat( 'c', 200 ) . '/' . str_repeat( 'c', 110 ) => 'exactly 512',
			str_repeat( 'd', 200 ) . '/' . str_repeat( 'd', 200 ) . '/' . str_repeat( 'd', 111 ) => 'exactly 513',
			str_repeat( './', 545 ) . 'wp-config.php'                                  => 'three blocks',
			str_repeat( './', 794 ) . 'wp-config.php'                                  => 'four blocks',
			'after.txt'                                                                => 'after the long names',
		);
		$path   = $this->scratch . '/direct.' . $type;
		$writer = new Tar_Archiver( null, $type );
		$writer->create( $path );
		$add = new ReflectionMethod( $writer, 'add_file_from_string' );
		$add->setAccessible( true );
		foreach ( $files as $name => $content ) {
			$add->invoke( $writer, $name, $content );
		}
		$data = new ReflectionMethod( $writer, 'add_data' );
		$data->setAccessible( true );
		$data->invoke( $writer, pack( 'a1024', '' ) );
		$writer->close();

		$entries = $this->gnu_entries( $this->tar_bytes( $type, $path ) );
		$this->assertSame( array_keys( $files ), $this->names( $entries ) );
		$this->assertSame( array( 1, 1, 1, 2, 3, 4, 0 ), array_column( $entries, 'blocks' ) );
		$protected = array( str_repeat( './', 545 ) . 'wp-config.php', str_repeat( './', 794 ) . 'wp-config.php' );
		$this->assert_readers( $path, $type, $files, array(), $protected );
		$this->assertFileDoesNotExist( $this->scratch . '/site/wp-config.php' );
	}

	/**
	 * Archives written before long names were padded hold one block whatever the declared length.
	 */
	public function test_cut_long_name_from_an_older_writer() {
		$name   = $this->disk_name( 600 );
		$header = $this->tar_entry_bytes( '././@LongLink', substr( $name, 0, 512 ), 'L' );
		$header = substr_replace( $header, sprintf( '%011o', strlen( $name ) ) . "\0", 124, 12 );
		$header = substr_replace( $header, sprintf( "%06o\0 ", array_sum( unpack( 'C*', substr_replace( substr( $header, 0, 512 ), '        ', 148, 8 ) ) ) ), 148, 8 );
		$path   = $this->scratch . '/legacy.tar';
		file_put_contents( $path, $this->tar_entry_bytes( 'before.txt', 'before' ) . $header . $this->tar_entry_bytes( substr( $name, 0, 100 ), 'cut' ) . $this->tar_entry_bytes( 'after.txt', 'after' ) . str_repeat( "\0", 1024 ) );

		$reader = new Tar_Archiver( null, 'tar' );
		$reader->read( $path );
		try {
			$this->assertSame( 'after', $reader->get_from_name( 'after.txt' ), 'Entries after a cut name must still be found.' );
			$this->assertTrue( $reader->file_exists( 'after.txt' ) );
			$error = null;
			try {
				$reader->extract_to( $this->scratch . '/site' );
			} catch ( \Exception $e ) {
				$error = $e->getMessage();
			}
		} finally {
			$reader->close();
		}
		$this->assertNotNull( $error, 'A cut name must not be written at the wrong path.' );
		$this->assertStringContainsString( 'older MainWP Child', $error );
		$this->assertStringContainsString( substr( $name, 0, 512 ), $error );
		$this->assertSame( array( '.', '..' ), scandir( $this->scratch . '/site' ), 'The refusal comes from the validation pass, before any write.' );
	}

	/**
	 * Native restores read Tar_Archiver output through PharData, so every name shape the
	 * writer produces must come back at its full path.
	 */
	public function test_native_restore_reads_every_name_shape() {
		$seg   = str_repeat( 's', 200 );
		$files = array(
			'n/' . str_repeat( 'n', 97 )                         => 'name field',
			'p/' . str_repeat( 'p', 98 )                         => 'split at a slash, 100 bytes',
			str_repeat( 'q', 60 ) . '/' . str_repeat( 'q', 89 ) => 'split at a slash, 150 bytes',
			str_repeat( 'r', 153 ) . '/' . str_repeat( 'r', 99 ) => 'split at a slash, 253 bytes',
			str_repeat( 'a', 100 )                               => 'no slash, 100 bytes',
			$seg . '/' . str_repeat( 'l', 99 )                   => 'long name, 300 bytes',
			$seg . '/' . $seg . '/' . str_repeat( 'm', 198 )     => 'long name, 600 bytes',
		);
		$path   = $this->scratch . '/native.tar';
		$writer = new Tar_Archiver( null, 'tar' );
		$writer->create( $path );
		$add = new ReflectionMethod( $writer, 'add_file_from_string' );
		$add->setAccessible( true );
		foreach ( $files as $name => $content ) {
			$add->invoke( $writer, $name, $content );
		}
		$data = new ReflectionMethod( $writer, 'add_data' );
		$data->setAccessible( true );
		$data->invoke( $writer, pack( 'a1024', '' ) );
		$writer->close();

		$restorer = new MainWP_Child_Backup_File_Restorer();
		$config   = array(
			'parts'      => array( $path ),
			'hashes'     => array( 'native.tar' => hash_file( 'sha256', $path ) ),
			'extract_to' => $this->scratch . '/site',
		);
		$result = array( 'cursor' => array() );
		$guard  = 0;
		do {
			$result = $restorer->restore_slice( $result['cursor'], $config, microtime( true ) + 3600 );
			++$guard;
		} while ( ! $result['done'] && null === $result['error'] && $guard < 100 );
		$this->assertNull( $result['error'] );
		foreach ( $files as $name => $content ) {
			$this->assertSame( $content, @file_get_contents( $this->scratch . '/site/' . $name ), 'The native restore must write the ' . strlen( $name ) . '-byte name (' . $content . ').' );
		}
	}
}
