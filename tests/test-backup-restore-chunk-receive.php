<?php
/**
 * Class BackupRestoreChunkReceiveTest
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Backup_Error;
use MainWP\Child\MainWP_Child_Backup_Task;
use MainWP\Child\MainWP_Child_Restore_Task_Runner;

/**
 * Restore runner with the backup directory pinned to a scratch dir.
 */
class Chunk_Test_Restore_Runner extends MainWP_Child_Restore_Task_Runner {

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
 * Tests for the chunk-receive path: offset semantics, per-chunk and
 * whole-artifact integrity, manifest membership, phase gating, and the
 * task-id-derived write path.
 */
class BackupRestoreChunkReceiveTest extends WP_UnitTestCase {

	/**
	 * Scratch backup dir.
	 *
	 * @var string
	 */
	protected $dir;

	/**
	 * Runner under test.
	 *
	 * @var Chunk_Test_Restore_Runner
	 */
	protected $runner;

	/**
	 * Spine.
	 *
	 * @var MainWP_Child_Backup_Task
	 */
	protected $spine;

	/**
	 * Build scratch dirs and the spine table.
	 */
	public function set_up() {
		parent::set_up();
		$this->dir = sys_get_temp_dir() . '/mainwp-chunk-test-' . uniqid();
		mkdir( $this->dir, 0777, true );
		$this->runner                  = new Chunk_Test_Restore_Runner();
		$this->runner->test_backup_dir = $this->dir;
		$this->spine                   = MainWP_Child_Backup_Task::instance();
		$this->spine->install();
	}

	/**
	 * Remove scratch dirs and task rows.
	 */
	public function tear_down() {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $this->spine->get_table_name() ); // phpcs:ignore WordPress.DB -- test cleanup.
		$this->rrmdir( $this->dir );
		parent::tear_down();
	}

	/**
	 * Recursive cleanup.
	 *
	 * @param string $dir Directory.
	 */
	protected function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_dir( $path ) ) {
				$this->rrmdir( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}

	/**
	 * Create a restore task expecting the given artifact bytes.
	 *
	 * @param string $content    Full artifact content.
	 * @param string $file       Artifact basename.
	 * @param bool   $adopt_only Whether the artifact is adoption-only.
	 *
	 * @return string Task id.
	 */
	protected function make_task( $content, $file = 'db.zip', $adopt_only = false ) {
		$params = array(
			'restore_id'       => 'chunk01',
			'source_prefix'    => 'wp_',
			'artifacts'        => array(
				array(
					'role'       => 'db',
					'file'       => $file,
					'size'       => strlen( $content ),
					'sha256'     => hash( 'sha256', $content ),
					'adopt_only' => $adopt_only,
				),
			),
			'has_foreign_keys' => false,
			'file_rollback'    => false,
		);
		return $this->spine->create_task( 'restore_db', array( 'params' => $params ), 20, 7200 );
	}

	/**
	 * Send one chunk.
	 *
	 * @param string $task_id Task id.
	 * @param string $raw     Chunk bytes.
	 * @param int    $offset  Offset.
	 * @param bool   $final   Final flag.
	 * @param string $file    Artifact basename.
	 * @param string $hash    Chunk hash override.
	 *
	 * @return array Outcome.
	 */
	protected function send( $task_id, $raw, $offset, $final = false, $file = 'db.zip', $hash = null ) {
		return $this->runner->receive_chunk( $task_id, $file, $offset, $raw, null === $hash ? hash( 'sha256', $raw ) : $hash, $final );
	}

	/**
	 * Chunks append in order and the final chunk verifies the artifact.
	 */
	public function test_chunks_written_in_order_and_final_verifies() {
		$content = str_repeat( 'A', 100 ) . str_repeat( 'B', 100 );
		$task_id = $this->make_task( $content );

		$first = $this->send( $task_id, substr( $content, 0, 100 ), 0 );
		$this->assertSame( 'ok', $first['result'] );
		$this->assertSame( 100, $first['bytes_received'] );

		$second = $this->send( $task_id, substr( $content, 100 ), 100, true );
		$this->assertSame( 'ok', $second['result'] );
		$this->assertSame( 200, $second['bytes_received'] );

		$path = $this->runner->scratch_dir( $task_id, $this->dir ) . 'db.zip';
		$this->assertSame( $content, file_get_contents( $path ) );
	}

	/**
	 * A duplicate of a confirmed chunk is acknowledged without writing.
	 */
	public function test_duplicate_chunk_at_same_offset_idempotent() {
		$content = str_repeat( 'A', 100 );
		$task_id = $this->make_task( $content );

		$this->send( $task_id, substr( $content, 0, 50 ), 0 );
		$dup = $this->send( $task_id, substr( $content, 0, 50 ), 0 );

		$this->assertSame( 'ok', $dup['result'] );
		$this->assertSame( 50, $dup['bytes_received'] );

		$path = $this->runner->scratch_dir( $task_id, $this->dir ) . 'db.zip';
		$this->assertSame( 50, (int) filesize( $path ) );
	}

	/**
	 * A chunk past the current size is a gap; the response carries the
	 * size so the sender realigns.
	 */
	public function test_chunk_with_gap_returns_current_size() {
		$content = str_repeat( 'A', 100 );
		$task_id = $this->make_task( $content );

		$this->send( $task_id, substr( $content, 0, 30 ), 0 );
		$gap = $this->send( $task_id, substr( $content, 60, 30 ), 60 );

		$this->assertSame( MainWP_Backup_Error::ERR_CHUNK_GAP, $gap['error_code'] );
		$this->assertSame( 30, $gap['bytes_received'] );
	}

	/**
	 * A chunk whose bytes do not match its hash is rejected unwritten.
	 */
	public function test_bad_chunk_hash_rejected() {
		$content = str_repeat( 'A', 100 );
		$task_id = $this->make_task( $content );

		$bad = $this->send( $task_id, substr( $content, 0, 50 ), 0, false, 'db.zip', str_repeat( '0', 64 ) );
		$this->assertSame( MainWP_Backup_Error::ERR_CHUNK_HASH, $bad['error_code'] );

		$path = $this->runner->scratch_dir( $task_id, $this->dir ) . 'db.zip';
		$this->assertFileDoesNotExist( $path );
	}

	/**
	 * A final chunk failing the whole-artifact hash discards the file so
	 * the transfer restarts clean.
	 */
	public function test_final_chunk_wrong_artifact_hash_discards_file() {
		$content = str_repeat( 'A', 100 );
		$task_id = $this->make_task( $content );

		// Send different bytes than the manifest hash was computed over.
		$wrong = str_repeat( 'X', 100 );
		$out   = $this->send( $task_id, $wrong, 0, true );

		$this->assertSame( MainWP_Backup_Error::ERR_ARTIFACT_CORRUPT, $out['error_code'] );
		$path = $this->runner->scratch_dir( $task_id, $this->dir ) . 'db.zip';
		$this->assertFileDoesNotExist( $path );
	}

	/**
	 * A file outside the manifest is refused.
	 */
	public function test_file_not_in_manifest_rejected() {
		$task_id = $this->make_task( 'abc' );
		$out     = $this->send( $task_id, 'abc', 0, false, 'other.zip' );
		$this->assertSame( MainWP_Backup_Error::ERR_ARTIFACT_UNKNOWN, $out['error_code'] );
	}

	/**
	 * An adopt-only artifact never accepts wire bytes.
	 */
	public function test_adopt_only_artifact_refuses_wire_transfer() {
		$task_id = $this->make_task( 'abc', 'db.zip', true );
		$out     = $this->send( $task_id, 'abc', 0 );
		$this->assertSame( MainWP_Backup_Error::ERR_ARTIFACT_UNKNOWN, $out['error_code'] );
	}

	/**
	 * Chunks are refused once the task has left the receiving phase.
	 */
	public function test_wrong_phase_rejected() {
		$content = 'abc';
		$task_id = $this->make_task( $content );
		$task    = $this->spine->get_task( $task_id );
		$this->spine->checkpoint( $task_id, (int) $task->version, array( 'phase' => MainWP_Child_Backup_Task::PHASE_REPLAYING_DB ) );

		$out = $this->send( $task_id, $content, 0 );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_WRONG_PHASE, $out['error_code'] );
	}

	/**
	 * An unknown task id is refused.
	 */
	public function test_unknown_task_rejected() {
		$out = $this->runner->receive_chunk( 'task-0-nope', 'db.zip', 0, 'x', hash( 'sha256', 'x' ), false );
		$this->assertArrayHasKey( 'error', $out );
	}

	/**
	 * Receive progress lands on the task cursor so the runner can see
	 * completed artifacts.
	 */
	public function test_progress_recorded_on_cursor() {
		$content = str_repeat( 'A', 80 );
		$task_id = $this->make_task( $content );
		$this->send( $task_id, $content, 0, true );

		$task      = $this->spine->get_task( $task_id );
		$sub_state = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertTrue( $sub_state['receiving']['files']['db.zip']['done'] );
		$this->assertSame( 80, $sub_state['receiving']['files']['db.zip']['bytes'] );
	}

	/**
	 * The write path derives from the task id: the artifact lands inside
	 * the per-task scratch dir, nowhere else.
	 */
	public function test_write_path_derived_from_task_id() {
		$content = 'payload';
		$task_id = $this->make_task( $content );
		$this->send( $task_id, $content, 0, true );

		$this->assertFileExists( $this->dir . '/restore-' . $task_id . '/db.zip' );
		$this->assertFileDoesNotExist( $this->dir . '/db.zip' );
	}

	/**
	 * The restore callables are dashboard-callable.
	 */
	public function test_restore_callables_registered() {
		$callable = \MainWP\Child\MainWP_Child_Callable::get_instance();
		$prop     = new ReflectionProperty( \MainWP\Child\MainWP_Child_Callable::class, 'callableFunctions' );
		$prop->setAccessible( true );
		$map = $prop->getValue( $callable );

		foreach ( array( 'restore_task_create', 'restore_task_run', 'restore_task_status', 'restore_artifact_chunk' ) as $action ) {
			$this->assertArrayHasKey( $action, $map );
			$this->assertTrue( method_exists( $callable, $map[ $action ] ), $action );
		}
	}
}
