<?php
/**
 * Archive write failures and interrupted resume regressions.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Backup;
use MainWP\Child\MainWP_Backup_Error;
use MainWP\Child\MainWP_Exception;
use MainWP\Child\Tar_Archiver;

class Tar_Incomplete_Write_Stream {

	public $context;
	public static $remaining;

	public function stream_open( $path, $mode, $options, &$opened_path ) {
		return true;
	}

	public function stream_write( $data ) {
		$written          = min( strlen( $data ), self::$remaining );
		self::$remaining -= $written;
		return $written;
	}

	public function stream_flush() {
		return true;
	}
}

class Incomplete_Write_Tar_Archiver extends Tar_Archiver {

	public function __construct( $backup, $type, $buffered ) {
		parent::__construct( $backup, $type );
		$this->debug     = $buffered;
		$this->chunkSize = 1;
	}

	public function create( $filepath ) {
		$this->archive = fopen( 'tarincomplete://archive', 'wb' );
	}

	public function discard() {
		$this->chunk = '';
		if ( is_resource( $this->archive ) ) {
			$this->close();
		}
	}
}

class TarArchiverIntegrityTest extends WP_UnitTestCase {

	protected $fixture_dir;
	protected $archive_path;
	protected $contents;

	public function set_up() {
		parent::set_up();
		$uploads            = wp_upload_dir();
		$this->fixture_dir  = $uploads['basedir'] . '/tar-integrity-fixture-' . uniqid();
		$this->archive_path = $uploads['basedir'] . '/tar-integrity-' . uniqid() . '.tar.gz';
		mkdir( $this->fixture_dir, 0777, true );
		$this->contents = array();
		foreach ( array( 277, 512, 1537 ) as $i => $length ) {
			$name = 'file-' . $i . '.txt';
			$content = '';
			for ( $j = 0; strlen( $content ) < $length; ++$j ) {
				$content .= hash( 'sha256', $i . ':' . $j );
			}
			$this->contents[ $name ] = substr( $content, 0, $length );
			file_put_contents( $this->fixture_dir . '/' . $name, $this->contents[ $name ] );
		}
		$this->assertTrue( stream_wrapper_register( 'tarincomplete', Tar_Incomplete_Write_Stream::class ) );
	}

	public function tear_down() {
		stream_wrapper_unregister( 'tarincomplete' );
		foreach ( (array) glob( $this->fixture_dir . '/*' ) as $file ) {
			unlink( $file );
		}
		rmdir( $this->fixture_dir );
		if ( file_exists( $this->archive_path ) ) {
			unlink( $this->archive_path );
		}
		parent::tear_down();
	}

	public static function incomplete_writes() {
		$cases = array();
		foreach ( array( 'tar', 'tar.gz', 'tar.bz2' ) as $type ) {
			if ( 'tar.bz2' === $type && ! function_exists( 'bzopen' ) ) {
				continue;
			}
			foreach ( array( false, true ) as $buffered ) {
				foreach ( array( 0, 1 ) as $bytes ) {
					$cases[] = array( $type, $buffered, $bytes );
				}
			}
		}
		return $cases;
	}

	/** @dataProvider incomplete_writes */
	public function test_zero_or_short_write_fails_backup( $type, $buffered, $bytes ) {
		Tar_Incomplete_Write_Stream::$remaining = $bytes;
		$archiver = new Incomplete_Write_Tar_Archiver( MainWP_Backup::get(), $type, $buffered );
		try {
			$archiver->zip_file( array( $this->fixture_dir . '/file-0.txt' ), $this->archive_path );
			$this->fail( 'The backup accepted a zero or short archive write.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( MainWP_Backup_Error::ERR_TAR_WRITE_FAILED, $e->get_message_error_code() );
		} finally {
			$archiver->discard();
		}
	}

	protected function invoke_private( $archiver, $name, ...$args ) {
		$method = new ReflectionMethod( Tar_Archiver::class, $name );
		$method->setAccessible( true );
		return $method->invokeArgs( $archiver, $args );
	}

	protected function create_entry_prefix() {
		$archiver = new Tar_Archiver( MainWP_Backup::get(), 'tar.gz' );
		$archiver->create( $this->archive_path );
		foreach ( $this->contents as $name => $content ) {
			$this->assertTrue( $this->invoke_private( $archiver, 'add_file', $this->fixture_dir . '/' . $name, basename( WP_CONTENT_DIR ) . '/' . $name ) );
		}
		$archiver->close();
		return gzdecode( file_get_contents( $this->archive_path ) );
	}

	protected function resume_backup( $append = true, $type = 'tar.gz' ) {
		$files = array();
		foreach ( $this->contents as $name => $content ) {
			$files[] = $this->fixture_dir . '/' . $name;
		}
		$backup = new class( $files ) {
			private $files;
			public function __construct( $files ) {
				$this->files = $files;
			}
			public function heartbeat() {}
			public function db_dump_prefix( $directory ) {
				return $directory . '/unused';
			}
			public function get_or_create_db_dump_files( $prefix, $archiver ) {
				return $this->files;
			}
		};
		$archiver = new Tar_Archiver( $backup, $type );
		try {
			return $archiver->create_full_backup( $this->archive_path, array( '*' ), false, true, false, false, $append );
		} finally {
			$handle = new ReflectionProperty( Tar_Archiver::class, 'archive' );
			$handle->setAccessible( true );
			if ( is_resource( $handle->getValue( $archiver ) ) ) {
				$archiver->close();
			}
		}
	}

	public static function gzip_prefixes() {
		return array( array( 'closed' ), array( 'concatenated' ), array( 'entry-prefix' ), array( 'empty-prefix' ), array( 'missing-footer' ), array( 'bad-crc' ) );
	}

	/** @dataProvider gzip_prefixes */
	public function test_resume_after_last_entry_finishes_or_restarts_gzip( $state ) {
		$tar = $this->create_entry_prefix();
		$gzip = file_get_contents( $this->archive_path );
		if ( 'concatenated' === $state ) {
			$gzip = gzencode( substr( $tar, 0, 1024 ) ) . gzencode( substr( $tar, 1024 ) );
		} elseif ( 'entry-prefix' === $state ) {
			$gzip = gzencode( substr( $tar, 0, 1024 ) );
		} elseif ( 'empty-prefix' === $state ) {
			$gzip = gzencode( '' );
		} elseif ( 'missing-footer' === $state ) {
			$gzip = substr( $gzip, 0, -8 );
		} elseif ( 'bad-crc' === $state ) {
			$gzip[ strlen( $gzip ) - 8 ] = chr( ord( $gzip[ strlen( $gzip ) - 8 ] ) ^ 1 );
		}
		file_put_contents( $this->archive_path, $gzip );

		$this->assertTrue( $this->resume_backup() );
		$output = array();
		$status = null;
		exec( 'gzip -t ' . escapeshellarg( $this->archive_path ) . ' 2>&1', $output, $status );
		$this->assertSame( 0, $status, implode( "\n", $output ) );
		$stream = gzopen( $this->archive_path, 'rb' );
		$finished = stream_get_contents( $stream );
		gzclose( $stream );
		$this->assertSame( strlen( $tar ) + 1024, strlen( $finished ), 'Resume must retain every entry and write the tar trailer.' );
		$this->assertSame( hash( 'sha256', $tar ), hash( 'sha256', substr( $finished, 0, strlen( $tar ) ) ) );
		$this->assertSame( str_repeat( "\0", 1024 ), substr( $finished, -1024 ) );

		$entries = array();
		exec( 'tar -tzf ' . escapeshellarg( $this->archive_path ) . ' 2>&1', $entries, $status );
		$this->assertSame( 0, $status, implode( "\n", $entries ) );
		$this->assertCount( count( $this->contents ), $entries, 'Resume must not duplicate entries.' );
		foreach ( $this->contents as $name => $content ) {
			$entry = basename( WP_CONTENT_DIR ) . '/' . $name;
			$command = 'tar -xOzf ' . escapeshellarg( $this->archive_path ) . ' ' . escapeshellarg( $entry );
			$this->assertSame( $content, shell_exec( $command ), 'Extracted bytes for ' . $entry );
		}
	}

	public static function truncated_entries() {
		return array( array( 'tar' ), array( 'tar.gz' ) );
	}

	/** @dataProvider truncated_entries */
	public function test_truncated_entry_is_refused_then_restarted( $type ) {
		$tar = $this->create_entry_prefix();
		if ( 'tar' === $type ) {
			unlink( $this->archive_path );
			$this->archive_path = substr( $this->archive_path, 0, -3 );
		}
		$partial = substr( $tar, 0, -512 );
		file_put_contents( $this->archive_path, 'tar.gz' === $type ? gzencode( $partial ) : $partial );
		try {
			$this->resume_backup( true, $type );
			$this->fail( 'Resume accepted an incomplete entry body.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( MainWP_Backup_Error::ERR_FILE_CORRUPT, $e->get_message_error_code() );
		}
		foreach ( $this->contents as $name => $content ) {
			$this->assertSame( $content, file_get_contents( $this->fixture_dir . '/' . $name ), 'Refusal must preserve the source.' );
		}
		$this->assertTrue( $this->resume_backup( false, $type ) );
		$reader = new Tar_Archiver( MainWP_Backup::get(), $type );
		$reader->read( $this->archive_path );
		try {
			foreach ( $this->contents as $name => $content ) {
				$this->assertSame( $content, $reader->get_from_name( basename( WP_CONTENT_DIR ) . '/' . $name ) );
			}
		} finally {
			$reader->close();
		}
	}
}
