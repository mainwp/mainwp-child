<?php
/**
 * Class BackupChunkAppendHelperTest
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Backup_Error;
use MainWP\Child\MainWP_Child_Chunk_Append_Helper;

/**
 * Tests for the shared chunk-append helper: per-chunk hash validation,
 * verified appends with the zero-byte touch, and the whole-file hash
 * check both receivers run on the final chunk.
 */
class BackupChunkAppendHelperTest extends WP_UnitTestCase {

	/**
	 * Scratch dir.
	 *
	 * @var string
	 */
	protected $dir;

	/**
	 * Build the scratch dir.
	 */
	public function set_up() {
		parent::set_up();
		$this->dir = sys_get_temp_dir() . '/mainwp-chunk-append-' . uniqid();
		mkdir( $this->dir, 0777, true );
	}

	/**
	 * Remove the scratch dir.
	 */
	public function tear_down() {
		if ( is_dir( $this->dir ) ) {
			foreach ( (array) glob( $this->dir . '/*' ) as $file ) {
				unlink( $file );
			}
			rmdir( $this->dir );
		}
		parent::tear_down();
	}

	/**
	 * A chunk hash matching the bytes validates.
	 */
	public function test_valid_chunk_hash_accepts_matching_hash() {
		$raw = 'chunk-bytes';
		$this->assertTrue( MainWP_Child_Chunk_Append_Helper::valid_chunk_hash( $raw, hash( 'sha256', $raw ) ) );
	}

	/**
	 * A wrong hash is rejected.
	 */
	public function test_valid_chunk_hash_rejects_wrong_hash() {
		$this->assertFalse( MainWP_Child_Chunk_Append_Helper::valid_chunk_hash( 'chunk-bytes', hash( 'sha256', 'other-bytes' ) ) );
	}

	/**
	 * An empty hash is rejected, never treated as skip-the-check.
	 */
	public function test_valid_chunk_hash_rejects_empty_hash() {
		$this->assertFalse( MainWP_Child_Chunk_Append_Helper::valid_chunk_hash( 'chunk-bytes', '' ) );
	}

	/**
	 * Appends land in order and grow the file.
	 */
	public function test_append_chunk_appends_in_order() {
		$path = $this->dir . '/artifact.bin';

		$this->assertNull( MainWP_Child_Chunk_Append_Helper::append_chunk( $path, 'first-', 0 ) );
		$this->assertNull( MainWP_Child_Chunk_Append_Helper::append_chunk( $path, 'second', 6 ) );

		$this->assertSame( 'first-second', file_get_contents( $path ) );
	}

	/**
	 * A zero-byte first chunk still creates the file, so the final hash
	 * check and the apply phase can see it.
	 */
	public function test_append_chunk_touches_empty_file_at_offset_zero() {
		$path = $this->dir . '/empty.list';

		$this->assertNull( MainWP_Child_Chunk_Append_Helper::append_chunk( $path, '', 0 ) );

		$this->assertFileExists( $path );
		$this->assertSame( '', file_get_contents( $path ) );
	}

	/**
	 * A zero-byte chunk past offset zero does not create a file.
	 */
	public function test_append_chunk_empty_past_zero_does_not_touch() {
		$path = $this->dir . '/never.list';

		$this->assertNull( MainWP_Child_Chunk_Append_Helper::append_chunk( $path, '', 6 ) );

		$this->assertFileDoesNotExist( $path );
	}

	/**
	 * An unwritable path reports the disk-full code.
	 *
	 * The failed fopen also raises a PHP warning in production (logged,
	 * not fatal); the harness would turn it into an exception, so it is
	 * muted around the call.
	 */
	public function test_append_chunk_unwritable_path_reports_disk_full() {
		$path = $this->dir . '/missing-subdir/artifact.bin';

		set_error_handler( '__return_true' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_set_error_handler
		$code = MainWP_Child_Chunk_Append_Helper::append_chunk( $path, 'bytes', 0 );
		restore_error_handler();

		$this->assertSame( MainWP_Backup_Error::ERR_DISK_FULL, $code );
	}

	/**
	 * The whole-file check passes on a matching hash.
	 */
	public function test_verify_final_accepts_matching_file() {
		$path = $this->dir . '/whole.bin';
		file_put_contents( $path, 'whole-file-bytes' );

		$this->assertTrue( MainWP_Child_Chunk_Append_Helper::verify_final( $path, hash( 'sha256', 'whole-file-bytes' ) ) );
	}

	/**
	 * The whole-file check fails on a mismatch.
	 */
	public function test_verify_final_rejects_wrong_hash() {
		$path = $this->dir . '/whole.bin';
		file_put_contents( $path, 'whole-file-bytes' );

		$this->assertFalse( MainWP_Child_Chunk_Append_Helper::verify_final( $path, hash( 'sha256', 'tampered' ) ) );
	}

	/**
	 * An empty expected hash never passes.
	 */
	public function test_verify_final_rejects_empty_expected_hash() {
		$path = $this->dir . '/whole.bin';
		file_put_contents( $path, 'whole-file-bytes' );

		$this->assertFalse( MainWP_Child_Chunk_Append_Helper::verify_final( $path, '' ) );
	}
}
