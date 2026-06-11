<?php
/**
 * Class BackupManifestChunkReceiveTest
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Backup_Error;
use MainWP\Child\MainWP_Child_Backup_Manifest_Chunk_Receiver;

/**
 * Receiver with the backup directory pinned to a scratch dir.
 */
class Manifest_Chunk_Test_Receiver extends MainWP_Child_Backup_Manifest_Chunk_Receiver {

	/**
	 * Pinned backup dir.
	 *
	 * @var string
	 */
	public $test_backup_dir = '';

	/**
	 * Use the pinned dir.
	 *
	 * @return string
	 */
	protected function backup_dir() {
		return $this->test_backup_dir;
	}
}

/**
 * Tests for the chunked parent-manifest staging path: token derivation,
 * offset semantics, per-chunk and whole-file integrity, and the
 * consume-once handover into a backup task.
 */
class BackupManifestChunkReceiveTest extends WP_UnitTestCase {

	/**
	 * Scratch backup dir.
	 *
	 * @var string
	 */
	protected $dir;

	/**
	 * Receiver under test.
	 *
	 * @var Manifest_Chunk_Test_Receiver
	 */
	protected $receiver;

	/**
	 * Build the scratch dir.
	 */
	public function set_up() {
		parent::set_up();
		$this->dir = sys_get_temp_dir() . '/mainwp-manifest-chunk-' . uniqid();
		mkdir( $this->dir, 0777, true );
		$this->receiver                  = new Manifest_Chunk_Test_Receiver();
		$this->receiver->test_backup_dir = $this->dir;
	}

	/**
	 * Remove scratch files and staging options.
	 */
	public function tear_down() {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'mainwp_manifest_staging_%'" ); // phpcs:ignore WordPress.DB -- test cleanup.
		foreach ( (array) glob( $this->dir . '/*' ) as $file ) {
			unlink( $file );
		}
		if ( is_dir( $this->dir ) ) {
			rmdir( $this->dir );
		}
		parent::tear_down();
	}

	/**
	 * Send one manifest chunk.
	 *
	 * @param string $mtoken Staging token ('' on first).
	 * @param string $raw    Chunk bytes.
	 * @param int    $offset Offset.
	 * @param string $total  Whole-manifest sha256.
	 * @param bool   $final  Final flag.
	 * @param string $hash   Chunk hash override.
	 *
	 * @return array Outcome.
	 */
	protected function send( $mtoken, $raw, $offset, $total, $final = false, $hash = null ) {
		return $this->receiver->receive_chunk( $mtoken, $offset, $raw, null === $hash ? hash( 'sha256', $raw ) : $hash, $total, $final );
	}

	/**
	 * The first chunk creates the staging session: a child-generated
	 * token comes back and the staged file exists in the backup dir.
	 */
	public function test_first_chunk_creates_staging_session() {
		$content = str_repeat( 'L', 100 );
		$ack     = $this->send( '', substr( $content, 0, 50 ), 0, hash( 'sha256', $content ) );

		$this->assertSame( 'ok', $ack['result'] );
		$this->assertSame( 50, $ack['bytes_received'] );
		$this->assertMatchesRegularExpression( '/^[a-z0-9]{16}$/', $ack['mtoken'], 'The token is child-generated and path-safe.' );
		$this->assertFileExists( $this->dir . '/manifest-staged-' . $ack['mtoken'] . '.list' );
	}

	/**
	 * Chunks append in order and the final chunk verifies the whole
	 * manifest and marks the session ready.
	 */
	public function test_chunks_append_and_final_marks_ready() {
		$content = str_repeat( 'A', 80 ) . str_repeat( 'B', 80 );
		$total   = hash( 'sha256', $content );

		$first  = $this->send( '', substr( $content, 0, 80 ), 0, $total );
		$second = $this->send( $first['mtoken'], substr( $content, 80 ), 80, $total, true );

		$this->assertSame( 'ok', $second['result'] );
		$this->assertSame( 160, $second['bytes_received'] );
		$this->assertSame( $content, file_get_contents( $this->dir . '/manifest-staged-' . $first['mtoken'] . '.list' ) );

		$staging = get_option( 'mainwp_manifest_staging_' . $first['mtoken'] );
		$this->assertTrue( ! empty( $staging['ready'] ) );
	}

	/**
	 * A duplicate of a confirmed chunk is acknowledged without writing.
	 */
	public function test_duplicate_chunk_idempotent() {
		$content = str_repeat( 'A', 60 );
		$total   = hash( 'sha256', $content );

		$first = $this->send( '', substr( $content, 0, 30 ), 0, $total );
		$dup   = $this->send( $first['mtoken'], substr( $content, 0, 30 ), 0, $total );

		$this->assertSame( 'ok', $dup['result'] );
		$this->assertSame( 30, $dup['bytes_received'] );
		$this->assertSame( 30, (int) filesize( $this->dir . '/manifest-staged-' . $first['mtoken'] . '.list' ) );
	}

	/**
	 * A chunk past the current size is a gap; the response carries the
	 * size so the sender realigns.
	 */
	public function test_chunk_gap_returns_current_size() {
		$content = str_repeat( 'A', 90 );
		$total   = hash( 'sha256', $content );

		$first = $this->send( '', substr( $content, 0, 30 ), 0, $total );
		$gap   = $this->send( $first['mtoken'], substr( $content, 60, 30 ), 60, $total );

		$this->assertSame( MainWP_Backup_Error::ERR_CHUNK_GAP, $gap['error_code'] );
		$this->assertSame( 30, $gap['bytes_received'] );
	}

	/**
	 * A chunk whose bytes fail their integrity check is not written.
	 */
	public function test_bad_chunk_hash_rejected() {
		$content = str_repeat( 'A', 50 );
		$bad     = $this->send( '', $content, 0, hash( 'sha256', $content ), false, hash( 'sha256', 'other-bytes' ) );

		$this->assertSame( MainWP_Backup_Error::ERR_CHUNK_HASH, $bad['error_code'] );
		$this->assertSame( array(), (array) glob( $this->dir . '/manifest-staged-*' ) );
	}

	/**
	 * A final chunk whose whole-file hash mismatches discards the staged
	 * file and the session.
	 */
	public function test_final_wrong_whole_file_hash_discards_session() {
		$content = str_repeat( 'A', 70 );

		$ack = $this->send( '', $content, 0, hash( 'sha256', 'a-different-manifest' ), true );

		$this->assertSame( MainWP_Backup_Error::ERR_MANIFEST_CHUNK_CORRUPT, $ack['error_code'] );
		$this->assertSame( array(), (array) glob( $this->dir . '/manifest-staged-*' ) );
		$this->assertSame( array(), $this->staging_options() );
	}

	/**
	 * A chunk with an unknown token is refused: the session does not
	 * exist, so the dashboard restarts the transfer.
	 */
	public function test_unknown_token_rejected() {
		$ack = $this->send( 'aaaabbbbcccc0000', 'bytes', 0, hash( 'sha256', 'bytes' ) );

		$this->assertSame( MainWP_Backup_Error::ERR_MANIFEST_CHUNK_WRONG_PHASE, $ack['error_code'] );
	}

	/**
	 * The write path derives from the child-stored session, never from
	 * wire input: a traversal-shaped token is refused outright.
	 */
	public function test_traversal_shaped_token_rejected() {
		$ack = $this->send( '../../evil', 'bytes', 0, hash( 'sha256', 'bytes' ) );

		$this->assertSame( MainWP_Backup_Error::ERR_MANIFEST_CHUNK_WRONG_PHASE, $ack['error_code'] );
		$this->assertFileDoesNotExist( dirname( $this->dir ) . '/evil' );
	}

	/**
	 * Consuming a ready session verifies the hash, returns the manifest,
	 * and removes the staged file and the session (consume-once).
	 */
	public function test_consume_staged_returns_content_and_cleans_up() {
		$content = "wp-content/uploads/a.jpg\t100\t1700000000\n";
		$total   = hash( 'sha256', $content );
		$ack     = $this->send( '', $content, 0, $total, true );

		$consumed = $this->receiver->consume_staged( $ack['mtoken'], $total );

		$this->assertArrayNotHasKey( 'error', $consumed );
		$this->assertSame( $content, base64_decode( $consumed['b64'] ) );
		$this->assertSame( array(), (array) glob( $this->dir . '/manifest-staged-*' ) );
		$this->assertSame( array(), $this->staging_options() );

		$again = $this->receiver->consume_staged( $ack['mtoken'], $total );
		$this->assertSame( MainWP_Backup_Error::ERR_MANIFEST_CHUNK_WRONG_PHASE, $again['error_code'] );
	}

	/**
	 * Consuming with a mismatched hash discards the session: a manifest
	 * the dashboard cannot vouch for is never handed to a task.
	 */
	public function test_consume_staged_hash_mismatch_discards() {
		$content = str_repeat( 'M', 40 );
		$total   = hash( 'sha256', $content );
		$ack     = $this->send( '', $content, 0, $total, true );

		$consumed = $this->receiver->consume_staged( $ack['mtoken'], hash( 'sha256', 'other' ) );

		$this->assertSame( MainWP_Backup_Error::ERR_MANIFEST_CHUNK_CORRUPT, $consumed['error_code'] );
		$this->assertSame( array(), (array) glob( $this->dir . '/manifest-staged-*' ) );
	}

	/**
	 * A session that never saw its final chunk is not consumable.
	 */
	public function test_consume_staged_refuses_unready_session() {
		$content = str_repeat( 'A', 80 );
		$ack     = $this->send( '', substr( $content, 0, 40 ), 0, hash( 'sha256', $content ) );

		$consumed = $this->receiver->consume_staged( $ack['mtoken'], hash( 'sha256', $content ) );

		$this->assertSame( MainWP_Backup_Error::ERR_MANIFEST_CHUNK_WRONG_PHASE, $consumed['error_code'] );
	}

	/**
	 * The manifest chunk callable is dashboard-callable.
	 */
	public function test_manifest_chunk_callable_registered() {
		$callable = \MainWP\Child\MainWP_Child_Callable::get_instance();
		$prop     = new ReflectionProperty( \MainWP\Child\MainWP_Child_Callable::class, 'callableFunctions' );
		$prop->setAccessible( true );
		$map = $prop->getValue( $callable );

		$this->assertArrayHasKey( 'backup_manifest_chunk', $map );
	}

	/**
	 * Read all staging options left behind.
	 *
	 * @return array Option rows.
	 */
	protected function staging_options() {
		global $wpdb;
		return $wpdb->get_results( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'mainwp_manifest_staging_%'" ); // phpcs:ignore WordPress.DB -- test helper.
	}
}
