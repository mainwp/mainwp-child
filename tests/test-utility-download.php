<?php
/**
 * Class UtilityDownloadTest
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Utility;
use MainWP\Child\MainWP_Child_Backup_Incremental_Diff;

/**
 * Utility double that records refusal emissions instead of touching the
 * process-global response state (the WP test bootstrap has already sent
 * output, so http_response_code() cannot be exercised directly in tests).
 */
class Recording_Refusal_Utility extends MainWP_Utility {

	/**
	 * Availability verdicts routed to emission.
	 *
	 * @var array[]
	 */
	public $refusals = array();

	/**
	 * Record instead of emitting.
	 *
	 * @param array $availability Availability verdict.
	 */
	protected function emit_download_refusal( $availability ) {
		$this->refusals[] = $availability;
	}
}

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
	 * This is the historical resume corruption bug: the legacy code sent the full file size
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

	/**
	 * A missing artifact is "not servable yet", not an empty success: 503
	 * with a Retry-After so the dashboard's download retry budget can wait
	 * out the child's post-completion settle window.
	 */
	public function test_resolve_download_availability_missing_file_is_503_with_retry_after() {
		$availability = MainWP_Utility::instance()->resolve_download_availability( $this->fixture . '.does-not-exist' );
		$this->assertFalse( $availability['ok'] );
		$this->assertSame( 503, $availability['status'] );
		$this->assertSame( 5, $availability['retry_after'] );
	}

	/**
	 * An empty artifact other than tombstones remains unavailable.
	 */
	public function test_resolve_download_availability_zero_byte_file_is_503() {
		$empty = tempnam( sys_get_temp_dir(), 'mwp-dl-empty-' );
		file_put_contents( $empty, '' );

		$availability = MainWP_Utility::instance()->resolve_download_availability( $empty );
		unlink( $empty );

		$this->assertFalse( $availability['ok'] );
		$this->assertSame( 503, $availability['status'] );
		$this->assertSame( 5, $availability['retry_after'] );
	}

	/**
	 * Incrementals without deletions still need a downloadable tombstones artifact.
	 */
	public function test_resolve_download_availability_empty_tombstones_is_ok() {
		$upload_dir = wp_upload_dir();
		$backup_dir = $upload_dir['basedir'] . '/mainwp/backup/mwp-dl-test-' . wp_rand();
		wp_mkdir_p( $backup_dir );
		$empty = $backup_dir . '/' . MainWP_Child_Backup_Incremental_Diff::TOMBSTONES_LIST;
		file_put_contents( $empty, '' );

		try {
			$availability = MainWP_Utility::instance()->resolve_download_availability( $empty );
			$this->assertTrue( $availability['ok'] );
			$this->assertSame( 200, $availability['status'] );
			$this->assertNull( $availability['retry_after'] );
		} finally {
			unlink( $empty );
			rmdir( $backup_dir );
		}
	}

	/**
	 * Empty artifacts cannot satisfy a byte range or resume offset.
	 */
	public function test_empty_download_is_200_with_zero_content_length() {
		$utility = MainWP_Utility::instance();
		foreach ( array( null, 'bytes=0-', 'bytes=5-10' ) as $range_header ) {
			$range = $utility->resolve_download_range( 0, $range_header, 5 );
			$this->assertSame( 200, $range['status'] );
			$headers = $utility->get_download_headers( MainWP_Child_Backup_Incremental_Diff::TOMBSTONES_LIST, 0, $range );
			$this->assertContains( 'Content-Length: 0', $headers );
			$this->assertSame( array(), preg_grep( '/^Content-Range:/', $headers ) );
		}
	}

	/**
	 * The wp-config guard is a security refusal, not a transient state: 403
	 * and no retry invitation.
	 */
	public function test_resolve_download_availability_wp_config_path_is_403() {
		$availability = MainWP_Utility::instance()->resolve_download_availability( ABSPATH . 'wp-config.php' );
		$this->assertFalse( $availability['ok'] );
		$this->assertSame( 403, $availability['status'] );
		$this->assertNull( $availability['retry_after'] );
	}

	/**
	 * An existing non-empty file is servable.
	 */
	public function test_resolve_download_availability_existing_nonempty_file_is_ok() {
		$availability = MainWP_Utility::instance()->resolve_download_availability( $this->fixture );
		$this->assertTrue( $availability['ok'] );
		$this->assertSame( 200, $availability['status'] );
		$this->assertNull( $availability['retry_after'] );
	}

	/**
	 * upload_file_backup() must propagate the refusal as a hard false and
	 * stream nothing for a not-ready (here: zero-byte) artifact. Regression
	 * for the dropped return value that let a vanished artifact stream as
	 * an empty HTTP 200.
	 */
	public function test_upload_file_backup_returns_false_and_does_not_stream_when_file_not_ready() {
		$upload_dir = wp_upload_dir();
		$backup_dir = $upload_dir['basedir'] . DIRECTORY_SEPARATOR . 'mainwp' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR;
		wp_mkdir_p( $backup_dir );
		$name = 'mwp-dl-test-notready-' . wp_rand() . '.zip';
		file_put_contents( $backup_dir . $name, '' );

		ob_start();
		$result = MainWP_Utility::instance()->upload_file_backup( $name );
		$output = ob_get_clean();

		unlink( $backup_dir . $name );

		$this->assertFalse( $result );
		$this->assertSame( '', $output );
	}

	/**
	 * upload_file_backup() still refuses a file that does not exist at all.
	 */
	public function test_upload_file_backup_returns_false_when_file_missing() {
		ob_start();
		$result = MainWP_Utility::instance()->upload_file_backup( 'mwp-dl-test-never-existed-' . wp_rand() . '.zip' );
		$output = ob_get_clean();

		$this->assertFalse( $result );
		$this->assertSame( '', $output );
	}

	/**
	 * The wp-config security refusal must route its 403 verdict to emission
	 * on the live refusal path, not collapse into the generic retryable 503
	 * that refuse_unserved_download() stamps on an untouched response.
	 */
	public function test_upload_file_routes_403_to_emission_for_wp_config() {
		$utility = new Recording_Refusal_Utility();

		ob_start();
		$result = $utility->upload_file( ABSPATH . 'wp-config.php' );
		$output = ob_get_clean();

		$this->assertFalse( $result );
		$this->assertSame( '', $output );
		$this->assertCount( 1, $utility->refusals );
		$this->assertSame( 403, $utility->refusals[0]['status'] );
		$this->assertNull( $utility->refusals[0]['retry_after'] );
	}

	/**
	 * The not-ready settle window must route its 503 + Retry-After verdict
	 * to emission on the live refusal path.
	 */
	public function test_upload_file_backup_routes_503_to_emission_when_not_ready() {
		$upload_dir = wp_upload_dir();
		$backup_dir = $upload_dir['basedir'] . DIRECTORY_SEPARATOR . 'mainwp' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR;
		wp_mkdir_p( $backup_dir );
		$name = 'mwp-dl-test-notready-' . wp_rand() . '.zip';
		file_put_contents( $backup_dir . $name, '' );

		$utility = new Recording_Refusal_Utility();

		ob_start();
		$result = $utility->upload_file_backup( $name );
		$output = ob_get_clean();

		unlink( $backup_dir . $name );

		$this->assertFalse( $result );
		$this->assertSame( '', $output );
		$this->assertCount( 1, $utility->refusals );
		$this->assertSame( 503, $utility->refusals[0]['status'] );
		$this->assertSame( 5, $utility->refusals[0]['retry_after'] );
	}

	/**
	 * The refusal header lines mirror the availability verdict: a retryable
	 * refusal carries Retry-After, a security refusal carries nothing.
	 */
	public function test_get_refusal_headers_mirror_the_availability_verdict() {
		$retryable = MainWP_Utility::instance()->get_refusal_headers(
			array(
				'ok'          => false,
				'status'      => 503,
				'retry_after' => 5,
			)
		);
		$this->assertSame( array( 'Retry-After: 5' ), $retryable );

		$security = MainWP_Utility::instance()->get_refusal_headers(
			array(
				'ok'          => false,
				'status'      => 403,
				'retry_after' => null,
			)
		);
		$this->assertSame( array(), $security );
	}
}
