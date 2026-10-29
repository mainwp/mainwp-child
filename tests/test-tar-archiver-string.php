<?php
/**
 * String entry size and padding regressions.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Backup;
use MainWP\Child\Tar_Archiver;

/** Limits runaway writes to 4 KiB on PHP 7.x. */
class Tar_String_Capped_Stream {

	public $context;
	private $handle;

	public function stream_open( $path, $mode, $options, &$opened_path ) {
		$this->handle = fopen( substr( $path, strlen( 'tarstringcap://' ) ), $mode );
		return false !== $this->handle;
	}

	public function stream_write( $data ) {
		if ( ftell( $this->handle ) + strlen( $data ) > 4096 ) {
			throw new RuntimeException( 'String entry exceeded the 4096-byte cap (runaway padding).' );
		}
		return fwrite( $this->handle, $data );
	}

	public function stream_flush() {
		return fflush( $this->handle );
	}

	public function stream_close() {
		fclose( $this->handle );
	}
}

class TarArchiverStringTest extends WP_UnitTestCase {

	protected $archive_path;

	public function set_up() {
		parent::set_up();
		$uploads            = wp_upload_dir();
		$this->archive_path = $uploads['basedir'] . '/tar-string-' . uniqid() . '.tar';
		$this->assertTrue( stream_wrapper_register( 'tarstringcap', Tar_String_Capped_Stream::class ) );
	}

	public function tear_down() {
		stream_wrapper_unregister( 'tarstringcap' );
		if ( file_exists( $this->archive_path ) ) {
			unlink( $this->archive_path );
		}
		parent::tear_down();
	}

	public static function content_lengths() {
		return array( array( 0 ), array( 1 ), array( 277 ), array( 511 ), array( 512 ), array( 513 ) );
	}

	/** @dataProvider content_lengths */
	public function test_string_entry_size_and_padding( $length ) {
		$content  = str_repeat( 'x', $length );
		$archiver = new Tar_Archiver( MainWP_Backup::get(), 'tar' );
		$archiver->create( 'tarstringcap://' . $this->archive_path );
		$method = new ReflectionMethod( Tar_Archiver::class, 'add_file_from_string' );
		$method->setAccessible( true );
		try {
			$this->assertTrue( $method->invoke( $archiver, 'clone/config.txt', $content ) );
		} catch ( RuntimeException $e ) {
			$this->fail( $e->getMessage() );
		} finally {
			$archiver->close();
		}

		$archive = file_get_contents( $this->archive_path );
		$padded  = (int) ( ceil( $length / 512 ) * 512 );
		$this->assertSame( 'clone/config.txt', rtrim( substr( $archive, 0, 100 ), "\0" ) );
		$this->assertSame( $length, octdec( trim( substr( $archive, 124, 12 ) ) ) );
		$this->assertSame( 512 + $padded, strlen( $archive ), 'Exactly one header plus the padded content blocks.' );
		$this->assertSame( $content, substr( $archive, 512, $length ) );
		$this->assertSame( str_repeat( "\0", $padded - $length ), substr( $archive, 512 + $length ) );
	}
}
