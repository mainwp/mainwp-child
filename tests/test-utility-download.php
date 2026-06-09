<?php
/**
 * Class UtilityDownloadTest
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Utility;

/**
 * Tests for backup download streaming: readfile_chunked() and download headers.
 *
 * Covers the resumed-download corruption bug: Content-Length must account for
 * the resume offset, and streaming must send exactly the declared byte count.
 */
class UtilityDownloadTest extends WP_UnitTestCase {

	/**
	 * Path to the fixture file.
	 *
	 * @var string
	 */
	private $fixture;

	/**
	 * Fixture file contents.
	 *
	 * @var string
	 */
	private $content;

	/**
	 * Create a fixture file with a known, position-dependent byte pattern.
	 */
	public function set_up() {
		parent::set_up();
		// 150000 bytes - larger than one 64KB chunk, not chunk-aligned, every
		// position unique enough to detect offset errors.
		$this->content = '';
		for ( $i = 0; $this->content !== '' ? strlen( $this->content ) < 150000 : true; $i++ ) {
			$this->content .= sprintf( '%08d|', $i );
			if ( strlen( $this->content ) >= 150000 ) {
				break;
			}
		}
		$this->content = substr( $this->content, 0, 150000 );
		$this->fixture = tempnam( sys_get_temp_dir(), 'mwp-dl-test-' );
		file_put_contents( $this->fixture, $this->content );
	}

	/**
	 * Remove the fixture file.
	 */
	public function tear_down() {
		if ( file_exists( $this->fixture ) ) {
			unlink( $this->fixture );
		}
		parent::tear_down();
	}

	/**
	 * Capture everything readfile_chunked() emits, including ob_flush()ed chunks.
	 *
	 * @param string   $filename File to stream.
	 * @param int      $offset   Byte offset to start from.
	 * @param int|null $length   Number of bytes to stream, null for to-EOF.
	 *
	 * @return array{0: mixed, 1: string} Return value and captured output.
	 */
	private function capture_stream( $filename, $offset, $length = null ) {
		ob_start(); // Outer buffer catches what the inner buffer flushes.
		ob_start(); // Inner buffer is the one readfile_chunked() echoes into.
		$result = MainWP_Utility::instance()->readfile_chunked( $filename, $offset, $length );
		$inner  = ob_get_clean();
		$outer  = ob_get_clean();
		return array( $result, $outer . $inner );
	}

	/**
	 * Full-file stream from offset zero is byte-identical to the file.
	 */
	public function test_readfile_chunked_streams_full_file() {
		list( $result, $output ) = $this->capture_stream( $this->fixture, 0 );
		$this->assertTrue( $result );
		$this->assertSame( strlen( $this->content ), strlen( $output ) );
		$this->assertSame( $this->content, $output );
	}

	/**
	 * Resumed stream sends exactly the bytes after the offset.
	 */
	public function test_readfile_chunked_streams_from_offset() {
		$offset = 70001; // Deliberately not chunk-aligned.
		list( $result, $output ) = $this->capture_stream( $this->fixture, $offset );
		$this->assertTrue( $result );
		$this->assertSame( strlen( $this->content ) - $offset, strlen( $output ) );
		$this->assertSame( substr( $this->content, $offset ), $output );
	}

	/**
	 * A bounded stream stops at the requested length instead of EOF.
	 */
	public function test_readfile_chunked_stops_at_length() {
		$offset = 1000;
		$length = 80000; // Crosses a chunk boundary, ends before EOF.
		list( $result, $output ) = $this->capture_stream( $this->fixture, $offset, $length );
		$this->assertTrue( $result );
		$this->assertSame( $length, strlen( $output ) );
		$this->assertSame( substr( $this->content, $offset, $length ), $output );
	}

	/**
	 * A zero-length bounded stream emits nothing.
	 */
	public function test_readfile_chunked_zero_length_streams_nothing() {
		list( $result, $output ) = $this->capture_stream( $this->fixture, 0, 0 );
		$this->assertTrue( $result );
		$this->assertSame( '', $output );
	}

	/**
	 * Missing file returns false and streams nothing.
	 */
	public function test_readfile_chunked_missing_file_returns_false() {
		list( $result, $output ) = $this->capture_stream( $this->fixture . '.does-not-exist', 0 );
		$this->assertFalse( $result );
		$this->assertSame( '', $output );
	}

	/**
	 * Content-Length for a fresh download is the full file size.
	 */
	public function test_download_headers_full_file() {
		$headers = MainWP_Utility::instance()->get_download_headers( $this->fixture, basename( $this->fixture ), 0 );
		$this->assertContains( 'Content-Length: ' . strlen( $this->content ), $headers );
	}

	/**
	 * Content-Length for a resumed download accounts for the offset.
	 *
	 * This is the 1B.0 corruption bug: the legacy code sent the full file size
	 * while streaming fewer bytes, so every resumed download corrupted.
	 */
	public function test_download_headers_resumed_download_accounts_for_offset() {
		$offset  = 70001;
		$headers = MainWP_Utility::instance()->get_download_headers( $this->fixture, basename( $this->fixture ), $offset );
		$this->assertContains( 'Content-Length: ' . ( strlen( $this->content ) - $offset ), $headers );
		$this->assertNotContains( 'Content-Length: ' . strlen( $this->content ), $headers );
	}
}
