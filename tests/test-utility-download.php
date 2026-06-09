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
	 * Resolve a range against the fixture size.
	 *
	 * @param string|null $range_header Raw Range header value or null.
	 * @param int         $offset       Legacy foffset value.
	 *
	 * @return array Resolved range: status, start, end.
	 */
	private function resolve( $range_header, $offset = 0 ) {
		return MainWP_Utility::instance()->resolve_download_range( strlen( $this->content ), $range_header, $offset );
	}

	/**
	 * No Range header and no offset is a plain 200 full-file response.
	 */
	public function test_resolve_no_range_no_offset_is_200() {
		$range = $this->resolve( null );
		$this->assertSame( 200, $range['status'] );
		$this->assertSame( 0, $range['start'] );
		$this->assertSame( strlen( $this->content ) - 1, $range['end'] );
	}

	/**
	 * A bounded Range request resolves to 206 with the requested bytes.
	 */
	public function test_resolve_bounded_range_is_206() {
		$range = $this->resolve( 'bytes=100-999' );
		$this->assertSame( 206, $range['status'] );
		$this->assertSame( 100, $range['start'] );
		$this->assertSame( 999, $range['end'] );
	}

	/**
	 * An open-ended Range request runs to the last byte.
	 */
	public function test_resolve_open_ended_range_runs_to_eof() {
		$range = $this->resolve( 'bytes=70001-' );
		$this->assertSame( 206, $range['status'] );
		$this->assertSame( 70001, $range['start'] );
		$this->assertSame( strlen( $this->content ) - 1, $range['end'] );
	}

	/**
	 * bytes=0-0 means exactly the first byte - a '0' end must not be treated as empty.
	 */
	public function test_resolve_first_byte_only_range() {
		$range = $this->resolve( 'bytes=0-0' );
		$this->assertSame( 206, $range['status'] );
		$this->assertSame( 0, $range['start'] );
		$this->assertSame( 0, $range['end'] );
	}

	/**
	 * An end beyond EOF is clamped to the last byte (RFC 7233).
	 */
	public function test_resolve_end_beyond_eof_is_clamped() {
		$range = $this->resolve( 'bytes=100-999999999' );
		$this->assertSame( 206, $range['status'] );
		$this->assertSame( strlen( $this->content ) - 1, $range['end'] );
	}

	/**
	 * Multi-range and malformed Range headers are rejected with 416.
	 */
	public function test_resolve_rejects_multirange_and_malformed() {
		$invalid = array(
			'bytes=0-5,10-20',
			'bytes=abc-',
			'characters=0-5',
			'bytes=-',
			'bytes=-500',
			'bytes=5-2',
		);
		foreach ( $invalid as $header ) {
			$range = $this->resolve( $header );
			$this->assertSame( 416, $range['status'], "Header '$header' must be rejected" );
		}
	}

	/**
	 * A start at or past EOF is unsatisfiable.
	 */
	public function test_resolve_start_past_eof_is_416() {
		$range = $this->resolve( 'bytes=' . strlen( $this->content ) . '-' );
		$this->assertSame( 416, $range['status'] );
	}

	/**
	 * Values too large to represent as integers are rejected, not clamped.
	 */
	public function test_resolve_overflow_values_rejected() {
		$range = $this->resolve( 'bytes=99999999999999999999-' );
		$this->assertSame( 416, $range['status'] );
		$range = $this->resolve( 'bytes=0-99999999999999999999' );
		$this->assertSame( 416, $range['status'] );
	}

	/**
	 * Legacy foffset translates into an equivalent 206 when no Range header is sent.
	 */
	public function test_resolve_foffset_fallback_is_206() {
		$offset = 70001;
		$range  = $this->resolve( null, $offset );
		$this->assertSame( 206, $range['status'] );
		$this->assertSame( $offset, $range['start'] );
		$this->assertSame( strlen( $this->content ) - 1, $range['end'] );
	}

	/**
	 * A foffset at or past EOF is unsatisfiable - no zero-byte 200/206.
	 */
	public function test_resolve_foffset_past_eof_is_416() {
		$range = $this->resolve( null, strlen( $this->content ) );
		$this->assertSame( 416, $range['status'] );
	}

	/**
	 * When both Range and foffset are present, Range wins.
	 */
	public function test_resolve_range_header_wins_over_foffset() {
		$range = $this->resolve( 'bytes=100-999', 70001 );
		$this->assertSame( 100, $range['start'] );
		$this->assertSame( 999, $range['end'] );
	}

	/**
	 * A negative offset is treated as a fresh full download.
	 */
	public function test_resolve_negative_offset_is_200() {
		$range = $this->resolve( null, -5 );
		$this->assertSame( 200, $range['status'] );
		$this->assertSame( 0, $range['start'] );
	}

	/**
	 * Full-file headers carry the full size, Accept-Ranges, and no-store caching.
	 */
	public function test_download_headers_full_file() {
		$size    = strlen( $this->content );
		$range   = $this->resolve( null );
		$headers = MainWP_Utility::instance()->get_download_headers( basename( $this->fixture ), $size, $range );
		$this->assertContains( 'Content-Length: ' . $size, $headers );
		$this->assertContains( 'Accept-Ranges: bytes', $headers );
		$this->assertContains( 'Cache-Control: no-store, private, max-age=0', $headers );
		$this->assertContains( 'X-Content-Type-Options: nosniff', $headers );
		$this->assertNotContains( 'Pragma: public', $headers );
		$this->assertNotContains( 'Cache-Control: must-revalidate', $headers );
	}

	/**
	 * Partial-content headers carry Content-Range and the partial length.
	 *
	 * This is the 1B.0 corruption bug: the legacy code sent the full file size
	 * while streaming fewer bytes, so every resumed download corrupted.
	 */
	public function test_download_headers_partial_content() {
		$size    = strlen( $this->content );
		$offset  = 70001;
		$range   = $this->resolve( null, $offset );
		$headers = MainWP_Utility::instance()->get_download_headers( basename( $this->fixture ), $size, $range );
		$this->assertContains( 'Content-Length: ' . ( $size - $offset ), $headers );
		$this->assertContains( 'Content-Range: bytes ' . $offset . '-' . ( $size - 1 ) . '/' . $size, $headers );
		$this->assertNotContains( 'Content-Length: ' . $size, $headers );
	}

	/**
	 * Unsatisfiable-range headers carry the "bytes asterisk/size" Content-Range form.
	 */
	public function test_download_headers_unsatisfiable_range() {
		$size    = strlen( $this->content );
		$range   = $this->resolve( 'bytes=0-5,10-20' );
		$headers = MainWP_Utility::instance()->get_download_headers( basename( $this->fixture ), $size, $range );
		$this->assertContains( 'Content-Range: bytes */' . $size, $headers );
	}
}
