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
		$wpdb->query( 'DELETE FROM ' . \MainWP\Child\MainWP_Child_Backup_Task::instance()->get_table_name() ); // phpcs:ignore WordPress.DB -- test cleanup.
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

		$second = $this->send( '', 'other-manifest', 0, hash( 'sha256', 'other-manifest' ) );
		$this->assertNotSame( $ack['mtoken'], $second['mtoken'], 'Every session gets its own token.' );
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
	 * An incremental task created with a manifest ref consumes the
	 * staged session into its cursor, exactly as the inline push would.
	 */
	public function test_create_task_with_valid_ref_consumes_staged_manifest() {
		$content = "wp-content/themes/x/style.css\t10\t1700000000\n";
		$total   = hash( 'sha256', $content );
		$ack     = $this->send( '', $content, 0, $total, true );

		$runner  = \MainWP\Child\MainWP_Child_Backup_Task_Runner::instance();
		$task_id = $runner->create_task_for_params(
			'incremental',
			array(
				'ext'                    => 'tar',
				'parent_snapshot_id'     => '7',
				'parent_manifest_ref'    => $ack['mtoken'],
				'parent_manifest_sha256' => $total,
			)
		);

		$spine     = \MainWP\Child\MainWP_Child_Backup_Task::instance();
		$task      = $spine->get_task( $task_id );
		$dirs      = \MainWP\Child\MainWP_Helper::get_mainwp_dir( 'backup', false );
		$sub_state = $spine->read_sub_state( $task, $dirs[0] );

		// The manifest is spilled to a scratch file, not inlined into the row.
		$this->assertArrayNotHasKey( 'parent_manifest_b64', $sub_state['params'], 'The base64 manifest must not stay in the task row.' );
		$this->assertArrayHasKey( 'parent_manifest_file', $sub_state['params'] );
		$this->assertSame( $content, file_get_contents( $dirs[0] . '/' . $sub_state['params']['parent_manifest_file'] ) );
		$this->assertArrayNotHasKey( 'parent_manifest_ref', $sub_state['params'], 'The consumed ref must not persist on the cursor.' );
		$this->assertSame( array(), (array) glob( $this->dir . '/manifest-staged-*' ), 'The staged file is consumed.' );
		$this->assertSame( array(), $this->staging_options() );

		unlink( $dirs[0] . '/' . $sub_state['params']['parent_manifest_file'] );
	}

	/**
	 * A ref whose staged content fails the task-create hash is refused
	 * and the session discarded; no task is created on bad data.
	 */
	public function test_create_task_with_bad_sha256_throws() {
		$content = str_repeat( 'C', 64 );
		$ack     = $this->send( '', $content, 0, hash( 'sha256', $content ), true );

		try {
			\MainWP\Child\MainWP_Child_Backup_Task_Runner::instance()->create_task_for_params(
				'incremental',
				array(
					'parent_manifest_ref'    => $ack['mtoken'],
					'parent_manifest_sha256' => hash( 'sha256', 'something-else' ),
				)
			);
			$this->fail( 'Expected the mismatched manifest to refuse the task' );
		} catch ( \MainWP\Child\MainWP_Exception $e ) {
			$this->assertSame( MainWP_Backup_Error::ERR_MANIFEST_CHUNK_CORRUPT, $e->get_message_error_code() );
		}

		$this->assertSame( array(), (array) glob( $this->dir . '/manifest-staged-*' ) );
		$this->assertSame( array(), \MainWP\Child\MainWP_Child_Backup_Task::instance()->get_active_tasks() );
	}

	/**
	 * An unknown ref is refused: the dashboard restarts the transfer.
	 */
	public function test_create_task_with_unknown_ref_throws() {
		try {
			\MainWP\Child\MainWP_Child_Backup_Task_Runner::instance()->create_task_for_params(
				'incremental',
				array(
					'parent_manifest_ref'    => 'aaaabbbbcccc0000',
					'parent_manifest_sha256' => hash( 'sha256', 'whatever' ),
				)
			);
			$this->fail( 'Expected the unknown ref to refuse the task' );
		} catch ( \MainWP\Child\MainWP_Exception $e ) {
			$this->assertSame( MainWP_Backup_Error::ERR_MANIFEST_CHUNK_WRONG_PHASE, $e->get_message_error_code() );
		}
	}

	/**
	 * A non-incremental task ignores stray ref params entirely.
	 */
	public function test_create_task_ignores_ref_for_full_type() {
		$task_id = \MainWP\Child\MainWP_Child_Backup_Task_Runner::instance()->create_task_for_params(
			'full',
			array(
				'ext'                    => 'tar',
				'parent_manifest_ref'    => 'aaaabbbbcccc0000',
				'parent_manifest_sha256' => hash( 'sha256', 'whatever' ),
			)
		);

		$spine     = \MainWP\Child\MainWP_Child_Backup_Task::instance();
		$dirs      = \MainWP\Child\MainWP_Helper::get_mainwp_dir( 'backup', false );
		$sub_state = $spine->read_sub_state( $spine->get_task( $task_id ), $dirs[0] );

		$this->assertArrayNotHasKey( 'parent_manifest_ref', $sub_state['params'] );
		$this->assertArrayNotHasKey( 'parent_manifest_b64', $sub_state['params'] );
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

	public function test_stale_sweep_deletes_only_removed_manifest_sessions() {
		$spine = \MainWP\Child\MainWP_Child_Backup_Task::instance();
		$spine->install();
		$old = $this->send( '', 'old', 0, hash( 'sha256', 'old' ) );
		$fresh = $this->send( '', 'fresh', 0, hash( 'sha256', 'fresh' ) );
		$old_path = $this->dir . '/manifest-staged-' . $old['mtoken'] . '.list';
		touch( $old_path, time() - 14400 );
		$invalid = 'not-a-valid-token';
		$invalid_path = $this->dir . '/manifest-staged-' . $invalid . '.list';
		file_put_contents( $invalid_path, 'unrelated' );
		touch( $invalid_path, time() - 14400 );
		$prefix = MainWP_Child_Backup_Manifest_Chunk_Receiver::OPTION_PREFIX;
		update_option( $prefix . $invalid, 'keep invalid' );
		update_option( $prefix . '0123456789abcdef', 'keep without file' );
		$holder = \MainWP\Child\MainWP_Child_Backup_Mutex::holder();
		$spine->sweep_stale_markers( $this->dir, 10800, $holder['owner'] ?? null );
		$this->assertFileDoesNotExist( $old_path );
		$this->assertFalse( get_option( $prefix . $old['mtoken'] ) );
		$this->assertFileExists( $this->dir . '/manifest-staged-' . $fresh['mtoken'] . '.list' );
		$this->assertIsArray( get_option( $prefix . $fresh['mtoken'] ) );
		$this->assertSame( 'keep invalid', get_option( $prefix . $invalid ) );
		$this->assertSame( 'keep without file', get_option( $prefix . '0123456789abcdef' ) );
	}

}
