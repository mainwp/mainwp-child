<?php
/**
 * Archive write failures and interrupted resume regressions.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Backup;
use MainWP\Child\MainWP_Backup_Budget_Exception;
use MainWP\Child\MainWP_Backup_Error;
use MainWP\Child\MainWP_Exception;
use MainWP\Child\Tar_Archiver;

class Tar_Incomplete_Write_Stream {

	public $context;
	public static $remaining;
	public static $flush_ok = true;

	public function stream_open( $path, $mode, $options, &$opened_path ) {
		return true;
	}

	public function stream_write( $data ) {
		$written          = min( strlen( $data ), self::$remaining );
		self::$remaining -= $written;
		return $written;
	}

	public function stream_flush() {
		return self::$flush_ok;
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

class Final_Failure_Tar_Archiver extends Tar_Archiver {
	public $failure;
	public function __construct( $type, $buffered, $failure ) {
		parent::__construct( MainWP_Backup::get(), $type );
		$this->debug = $buffered;
		$this->failure = $failure;
	}
	protected function close_archive_stream() {
		$result = parent::close_archive_stream();
		if ( 'close' === $this->failure ) { return false; }
		if ( 'footer' === $this->failure || 'short' === $this->failure ) {
			$handle = fopen( $this->archivePath, 'r+b' );
			if ( 'short' === $this->failure ) {
				ftruncate( $handle, 17 );
			} else {
				fseek( $handle, -4, SEEK_END );
				fwrite( $handle, pack( 'V', 1 ) );
			}
			fclose( $handle );
		}
		return $result;
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

	public function test_mid_file_read_failure_records_path_and_aborts_archive() {
		$backup = MainWP_Backup::get();
		$backup->reset_failed_adds();
		$source = $this->fixture_dir . '/read-failure.txt';
		file_put_contents( $source, str_repeat( 'x', 1024000 * 5 + 1 ) );
		$archiver = new class( $backup, 'tar.gz' ) extends Tar_Archiver {
			private $reads = 0;
			protected function read_source_data( $handle, $length ) {
				return ++$this->reads > 1 ? false : parent::read_source_data( $handle, $length );
			}
		};
		$archiver->create( $this->archive_path );
		try {
			try {
				$this->invoke_private( $archiver, 'add_file', $source, 'read-failure.txt' );
				$this->fail( 'A source read failure must abort rather than publish a short entry.' );
			} catch ( MainWP_Exception $e ) {
				$this->assertSame( MainWP_Backup_Error::ERR_TAR_WRITE_FAILED, $e->get_message_error_code() );
			}
			$this->assertSame( array( $source ), $backup->get_failed_add_paths() );
		} finally {
			$archiver->close();
			$backup->reset_failed_adds();
			MainWP_Backup::reset_skipped_paths();
		}
	}

	public function test_unreadable_tar_add_aborts_past_failure_limit() {
		$backup = MainWP_Backup::get();
		$backup->reset_failed_adds();
		$source = $this->fixture_dir . '/file-0.txt';
		chmod( $source, 0000 );
		$archiver = new Tar_Archiver( $backup, 'tar.gz' );
		try {
			clearstatcache( true, $source );
			if ( is_readable( $source ) ) { $this->markTestSkipped( 'The test user can read a chmod 0000 file.' ); }
			for ( $i = 0; $i < MainWP_Backup::MAX_FAILED_ADDS; ++$i ) { $backup->record_failed_add( $source ); }
			$archiver->create( $this->archive_path );
			try {
				@$this->invoke_private( $archiver, 'add_file', $source, 'unreadable.txt' );
				$this->fail( 'Tar additions must enforce the failed-add limit.' );
			} catch ( MainWP_Exception $e ) {
				$this->assertSame( MainWP_Backup_Error::ERR_TOO_MANY_SKIPPED, $e->get_message_error_code() );
			}
		} finally {
			if ( $archiver->is_open() ) { $archiver->close(); }
			chmod( $source, 0644 );
			$backup->reset_failed_adds();
			MainWP_Backup::reset_skipped_paths();
		}
	}

	public static function final_write_failures() {
		return array(
			'tar close' => array( 'tar', false, 'close', false ),
			'chunk gzip close' => array( 'tar.gz', true, 'close', false ),
			'gzip footer' => array( 'tar.gz', false, 'footer', false ),
			'gzip too short' => array( 'tar.gz', false, 'short', false ),
			'appended gzip footer' => array( 'tar.gz', false, 'footer', true ),
		);
	}

	/** @dataProvider final_write_failures */
	public function test_final_flush_or_close_failure_aborts_backup( $type, $buffered, $failure, $append ) {
		$archiver = new Final_Failure_Tar_Archiver( $type, $buffered, $failure );
		$archiver->create( $this->archive_path );
		$this->invoke_private( $archiver, 'add_file_from_string', 'before.txt', 'prefix bytes' );
		if ( $append ) {
			$archiver->failure = null;
			$archiver->close();
			$archiver->append( $this->archive_path );
			$archiver->failure = $failure;
			$this->invoke_private( $archiver, 'add_file_from_string', 'after.txt', 'appended bytes' );
		}
		$this->expectException( MainWP_Exception::class );
		try {
			$archiver->close();
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( MainWP_Backup_Error::ERR_TAR_WRITE_FAILED, $e->get_message_error_code() );
			throw $e;
		}
	}

	public function test_gzip_final_flush_failure_aborts_backup() {
		$archiver = new class() extends Tar_Archiver {
			public function __construct() { parent::__construct( MainWP_Backup::get(), 'tar.gz' ); }
			public function create( $path ) {
				parent::create( $path );
				gzclose( $this->archive );
				$this->archive = fopen( 'tarincomplete://archive', 'wb' );
			}
		};
		Tar_Incomplete_Write_Stream::$remaining = PHP_INT_MAX;
		Tar_Incomplete_Write_Stream::$flush_ok = false;
		try {
			$archiver->create( $this->archive_path );
			$this->expectException( MainWP_Exception::class );
			try {
				$archiver->close();
			} catch ( MainWP_Exception $e ) {
				$this->assertSame( MainWP_Backup_Error::ERR_TAR_WRITE_FAILED, $e->get_message_error_code() );
				throw $e;
			}
		} finally {
			Tar_Incomplete_Write_Stream::$flush_ok = true;
		}
	}

	public static function unreadable_archived_slices() {
		return array(
			'tar, two slices' => array( 'tar', false ),
			'tar.gz, two slices' => array( 'tar.gz', false ),
			'tar, three slices' => array( 'tar', true ),
			'tar.gz, three slices' => array( 'tar.gz', true ),
		);
	}

	/** @dataProvider unreadable_archived_slices */
	public function test_resume_keeps_file_unreadable_since_an_archived_slice( $type, $yield_after_unreadable ) {
		if ( 'tar' === $type ) {
			$this->archive_path = substr( $this->archive_path, 0, -3 );
		}
		$backup = MainWP_Backup::get();
		$backup->reset_failed_adds();
		MainWP_Backup::reset_skipped_paths();
		$unreadable = $this->fixture_dir . '/file-0.txt';
		$archiver = new Tar_Archiver( $backup, $type );
		$mode = new ReflectionProperty( Tar_Archiver::class, 'mode' );
		$mode->setAccessible( true );
		$archive_path = new ReflectionProperty( Tar_Archiver::class, 'archivePath' );
		$archive_path->setAccessible( true );
		$failed_paths = array();
		try {
			$archiver->create( $this->archive_path );
			$archiver->set_budget_deadline( time() );
			try {
				$archiver->add_dir( $this->fixture_dir, array() );
				$this->fail( 'Slice one must yield after archiving the first file.' );
			} catch ( MainWP_Backup_Budget_Exception $e ) {
				$this->assertSame( MainWP_Backup_Error::ERR_TAR_WRITE_FAILED, $e->get_message_error_code() );
			} finally {
				$archiver->close();
			}
			$prefix = file_get_contents( $this->archive_path );
			if ( 'tar.gz' === $type ) { $prefix = gzdecode( $prefix ); }
			$name = basename( rtrim( substr( $prefix, 512, 100 ), "\0 " ) );
			$this->assertArrayHasKey( $name, $this->contents );
			$unreadable = $this->fixture_dir . '/' . $name;
			$prefix_length = 1024 + (int) ( ceil( strlen( $this->contents[ $name ] ) / 512 ) * 512 );
			$this->assertSame( $prefix_length, strlen( $prefix ), 'Slice one contains a directory header and the first complete file.' );
			chmod( $unreadable, 0000 );
			clearstatcache( true, $unreadable );
			if ( is_readable( $unreadable ) ) {
				$this->markTestSkipped( 'The test user can read a chmod 0000 file.' );
			}

			$last_slice = $yield_after_unreadable ? 3 : 2;
			for ( $slice = 2; $slice <= $last_slice; ++$slice ) {
				$backup->reset_failed_adds();
				$excludes = array();
				// Checkpointed failed paths become excludes on the next slice.
				foreach ( $failed_paths as $path ) {
					$excludes[] = str_replace( ABSPATH, '', $path );
				}
				$archiver = new Tar_Archiver( $backup, $type );
				$mode->setValue( $archiver, Tar_Archiver::APPEND );
				$archive_path->setValue( $archiver, $this->archive_path );
				$archiver->prepare_append( $this->archive_path );
				$should_yield = $slice < $last_slice;
				$archiver->set_budget_deadline( $should_yield ? time() : null );
				$yielded = false;
				try {
					$archiver->add_dir( $this->fixture_dir, $excludes );
					$this->invoke_private( $archiver, 'add_data', str_repeat( "\0", 1024 ) );
				} catch ( MainWP_Backup_Budget_Exception $e ) {
					$yielded = true;
				} catch ( MainWP_Exception $e ) {
					$this->fail( 'Resume failed with ' . $e->get_message_error_code() . ': ' . $e->getMessage() );
				} finally {
					$failed_paths = array_merge( $failed_paths, $backup->get_failed_add_paths() );
					$archiver->close();
				}
				$this->assertSame( $should_yield, $yielded, 'Only the middle slice yields after the unreadable file.' );
			}
			$this->assertSame( array(), $failed_paths, 'An archived copy must never become a failed-add exclude.' );
			$this->assertSame( 0, $backup->get_failed_add_count() );
			$stream = 'tar.gz' === $type ? gzopen( $this->archive_path, 'rb' ) : fopen( $this->archive_path, 'rb' );
			$finished = stream_get_contents( $stream );
			if ( 'tar.gz' === $type ) { gzclose( $stream ); } else { fclose( $stream ); }
			$this->assertSame( 6144, strlen( $finished ), 'The archive contains one directory, three files and the trailer, without duplicates.' );
			$this->assertSame( $prefix, substr( $finished, 0, strlen( $prefix ) ), 'The archived copy must remain unchanged.' );
			$this->assertSame( str_repeat( "\0", 1024 ), substr( $finished, -1024 ) );
			$archiver->read( $this->archive_path );
			foreach ( $this->contents as $name => $content ) {
				$entry = str_replace( ABSPATH, '', $this->fixture_dir ) . '/' . $name;
				$this->assertSame( $content, $archiver->get_from_name( $entry ), 'Extracted bytes for ' . $entry );
			}
		} finally {
			$archiver->close();
			chmod( $unreadable, 0644 );
			$backup->reset_failed_adds();
			MainWP_Backup::reset_skipped_paths();
		}
	}

	/** @dataProvider truncated_entries */
	public function test_resume_rejects_prepared_dump_unreadable_since_an_uncheckpointed_slice( $type ) {
		if ( 'tar' === $type ) {
			$this->archive_path = substr( $this->archive_path, 0, -3 );
		}
		$unreadable = $this->fixture_dir . '/file-0.txt';
		$archiver   = new Tar_Archiver( MainWP_Backup::get(), $type );
		$archiver->create( $this->archive_path );
		foreach ( array( 'file-1.txt', 'file-2.txt' ) as $name ) {
			$this->assertTrue( $this->invoke_private( $archiver, 'add_file', $this->fixture_dir . '/' . $name, basename( WP_CONTENT_DIR ) . '/' . $name ) );
		}
		$archiver->close();
		chmod( $unreadable, 0000 );
		try {
			clearstatcache( true, $unreadable );
			if ( is_readable( $unreadable ) ) {
				$this->markTestSkipped( 'The test user can read a chmod 0000 file.' );
			}
			try {
				$this->resume_backup( true, $type );
				$this->fail( 'An unreadable prepared dump must fail even on resume.' );
			} catch ( MainWP_Exception $e ) {
				$this->assertSame( MainWP_Backup_Error::ERR_TAR_WRITE_FAILED, $e->get_message_error_code() );
			}
		} finally {
			// The resume deletes its prepared files after archiving them.
			if ( file_exists( $unreadable ) ) {
				chmod( $unreadable, 0644 );
			}
		}
	}

	public function test_read_close_does_not_validate_gzip_footer() {
		file_put_contents( $this->archive_path, gzencode( 'read bytes' ) );
		$reader = new Tar_Archiver( MainWP_Backup::get(), 'tar.gz' );
		$reader->read( $this->archive_path );
		$reader->close( false );
		$this->assertSame( 'read bytes', gzdecode( file_get_contents( $this->archive_path ) ) );
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
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		foreach ( array( 'exec', 'shell_exec' ) as $function ) {
			if ( ! function_exists( $function ) || in_array( $function, $disabled, true ) ) {
				$this->markTestSkipped( 'Gzip resume integrity needs exec and shell_exec.' );
			}
		}
		foreach ( array( 'gzip', 'tar' ) as $binary ) {
			$output = array();
			$status = null;
			exec( 'command -v ' . escapeshellarg( $binary ) . ' 2>/dev/null', $output, $status );
			if ( 0 !== $status || array() === $output ) {
				$this->markTestSkipped( 'Gzip resume integrity needs gzip and tar on PATH.' );
			}
		}
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
