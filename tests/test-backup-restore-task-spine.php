<?php
/**
 * Class BackupRestoreTaskSpineTest
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Child_Backup_Task;

/**
 * Tests for the restore task type on the shared task spine: the phase
 * chains, the deadline and resume-cap overrides, and terminal handling.
 */
class BackupRestoreTaskSpineTest extends WP_UnitTestCase {

	/**
	 * Spine under test.
	 *
	 * @var MainWP_Child_Backup_Task
	 */
	protected $spine;

	/**
	 * Install the spine table.
	 */
	public function set_up() {
		parent::set_up();
		$this->spine = MainWP_Child_Backup_Task::instance();
		$this->spine->install();
	}

	/**
	 * Distinct secret values make accidental disclosure visible in encoded responses.
	 *
	 * @return array Restore cursor.
	 */
	protected function secret_restore_cursor() {
		return array(
			'params'            => array(
				'restore_id'    => 'restore123',
				'pull_manifest' => array( 'artifacts' => array( array( 'url' => 'https://example.test/dump.sql?signature=private123' ) ) ),
			),
			'identity_preserve' => array( array( 'option_name' => 'mainwp_child_pubkey', 'option_value' => 'private-connection-value', 'autoload' => 'yes' ) ),
			'verification'      => array( 'sentinel_ok' => true ),
			'progress'          => array( 'woo_preserve' => array( 'orders' => 4 ) ),
			'error'             => array( 'code' => 'restore_test_error', 'message' => 'Restore test error.' ),
			'db_replay'         => array( 'offset' => 1024 ),
			'restored_mutex'    => 'private-mutex-value',
		);
	}

	/**
	 * Restore polling needs routing and outcome fields without resume credentials.
	 */
	public function test_restore_status_payload_omits_resume_secrets() {
		foreach ( array( 'restore', 'restore_db', 'restore_files' ) as $type ) {
			$cursor  = $this->secret_restore_cursor();
			$task_id = $this->spine->create_task( $type );
			$task    = $this->spine->get_task( $task_id );
			$this->assertNotFalse( $this->spine->checkpoint( $task_id, (int) $task->version, array( 'sub_state' => $cursor ) ) );
			$task    = $this->spine->get_task( $task_id );
			$payload = $this->spine->status_payload( $task );
			$state   = $payload['sub_state'];

			$this->assertSame( array( 'pull_manifest' => true ), $state['params'] );
			$this->assertTrue( $state['identity_preserved'] );
			foreach ( array( 'verification', 'progress', 'error' ) as $key ) {
				$this->assertSame( $cursor[ $key ], $state[ $key ] );
			}
			$this->assertCount( 5, $state );
			$this->assertArrayNotHasKey( 'identity_preserve', $state );
			$this->assertArrayNotHasKey( 'db_replay', $state );
			$this->assertArrayNotHasKey( 'restored_mutex', $state );
			$this->assertStringNotContainsString( 'signature=private123', wp_json_encode( $payload ) );
			$this->assertStringNotContainsString( 'private-connection-value', wp_json_encode( $payload ) );
			$this->assertSame( $cursor, json_decode( $task->sub_state, true ) );
			$this->assertNotFalse( $this->spine->checkpoint( $task_id, (int) $task->version, array( 'phase' => MainWP_Child_Backup_Task::PHASE_COMPLETED ) ) );
			$payload = $this->spine->status_payload( $this->spine->get_task( $task_id ) );
			$this->assertEmpty( $payload['sub_state']['params'] );
		}
	}

	/**
	 * The restore projection must not change backup polling or persistence.
	 */
	public function test_backup_status_payload_preserves_cursor() {
		$cursor             = $this->secret_restore_cursor();
		$cursor['artifact'] = array( 'file' => 'backup-123.tar', 'size' => 4096 );
		$task_id            = $this->spine->create_task( 'db' );
		$task               = $this->spine->get_task( $task_id );
		$this->assertNotFalse( $this->spine->checkpoint( $task_id, (int) $task->version, array( 'phase' => MainWP_Child_Backup_Task::PHASE_COMPLETED, 'sub_state' => $cursor ) ) );
		$payload = $this->spine->status_payload( $this->spine->get_task( $task_id ) );
		$this->assertSame( $cursor, $payload['sub_state'] );
		$this->assertSame( 'backup-123.tar', $payload['file'] );
		$this->assertSame( 4096, $payload['size'] );
		$this->assertSame( $cursor['progress'], $payload['progress'] );
	}

	/**
	 * Terminal rows need their restore id and outcome, but no resume credentials.
	 */
	public function test_restore_terminal_checkpoint_scrubs_resume_secrets() {
		foreach ( array( 'completed', 'failed', 'cancelled' ) as $outcome ) {
			$cursor                         = $this->secret_restore_cursor();
			$cursor['params']['restore_id'] = 'restore' . $outcome;
			$phase                          = 'completed' === $outcome ? MainWP_Child_Backup_Task::PHASE_COMPLETED : MainWP_Child_Backup_Task::PHASE_FAILED;
			if ( 'cancelled' === $outcome ) {
				$cursor['error']['code'] = \MainWP\Child\MainWP_Backup_Error::ERR_RESTORE_CANCELLED;
			}
			$task_id = $this->spine->create_task( 'restore_db', $cursor );
			$task    = $this->spine->get_task( $task_id );
			$fields  = array( 'phase' => $phase );
			if ( 'completed' === $outcome ) {
				$fields['sub_state'] = $cursor;
			} elseif ( 'failed' === $outcome ) {
				$fields['sub_state'] = wp_json_encode( $cursor );
			}
			$this->assertNotFalse( $this->spine->checkpoint( $task_id, (int) $task->version, $fields ) );
			$stored = json_decode( $this->spine->get_task( $task_id )->sub_state, true );
			$this->assertArrayNotHasKey( 'pull_manifest', $stored['params'] );
			$this->assertArrayNotHasKey( 'identity_preserve', $stored );
			$this->assertTrue( $stored['identity_preserved'] );
			$this->assertSame( $cursor['params']['restore_id'], $stored['params']['restore_id'] );
			foreach ( array( 'verification', 'progress', 'error', 'db_replay' ) as $key ) {
				$this->assertSame( $cursor[ $key ], $stored[ $key ] );
			}
			$this->assertSame( $task_id, $this->spine->get_task_by_restore_id( $cursor['params']['restore_id'] )->task_id );
		}
	}

	/**
	 * Completion must retain the outcome after the runner deletes its sidecars.
	 */
	public function test_restore_terminal_checkpoint_scrubs_sidecar_cursor() {
		$cursor            = $this->secret_restore_cursor();
		$cursor['padding'] = str_repeat( 'x', MainWP_Child_Backup_Task::SUB_STATE_INLINE_MAX + 1 );
		$task_id           = $this->spine->create_task( 'restore' );
		$task              = $this->spine->get_task( $task_id );
		$backup_dir        = sys_get_temp_dir() . '/mainwp-terminal-cursor-' . uniqid();
		mkdir( $backup_dir );
		try {
			$serialized = $this->spine->write_sub_state( $task_id, $cursor, $backup_dir, (int) $task->version );
			$this->assertNotFalse( $serialized );
			$this->assertTrue( json_decode( $serialized, true )['_sidecar'] );
			$this->assertNotFalse( $this->spine->checkpoint( $task_id, (int) $task->version, array( 'phase' => MainWP_Child_Backup_Task::PHASE_COMPLETED, 'sub_state' => $serialized ), true, $backup_dir ) );
			$this->spine->delete_sidecar( $task_id, $backup_dir );
			$stored = $this->spine->read_sub_state( $this->spine->get_task( $task_id ), $backup_dir );
			$this->assertArrayNotHasKey( 'pull_manifest', $stored['params'] );
			$this->assertArrayNotHasKey( 'identity_preserve', $stored );
			$this->assertSame( $cursor['verification'], $stored['verification'] );
		} finally {
			$this->spine->delete_sidecar( $task_id, $backup_dir );
			rmdir( $backup_dir );
		}
	}

	/**
	 * A basename-only pointer needs its owner's directory; missing cursors must survive reclamation.
	 */
	public function test_reclaim_expired_tasks_scrubs_sidecar_in_supplied_directory() {
		global $wpdb;
		$cursor            = $this->secret_restore_cursor();
		$cursor['padding'] = str_repeat( 'x', MainWP_Child_Backup_Task::SUB_STATE_INLINE_MAX + 1 );
		$task_id           = $this->spine->create_task( 'restore', $cursor );
		$backup_dir        = sys_get_temp_dir() . '/mainwp-reclaim-cursor-' . uniqid();
		mkdir( $backup_dir );
		try {
			$serialized = $this->spine->write_sub_state( $task_id, $cursor, $backup_dir, 0 );
			$this->assertNotFalse( $serialized );
			$this->assertTrue( json_decode( $serialized, true )['_sidecar'] );
			$this->assertSame( 1, $wpdb->update( $this->spine->get_table_name(), array( 'phase' => MainWP_Child_Backup_Task::PHASE_FAILED, 'active_slot' => null, 'sub_state' => $serialized ), array( 'task_id' => $task_id ) ) );
			$before = $this->spine->get_task( $task_id );
			$this->assertSame( 0, $this->spine->reclaim_expired_tasks( $backup_dir . '/missing' ) );
			$this->assertEquals( $before, $this->spine->get_task( $task_id ) );
			$this->assertSame( 0, $this->spine->reclaim_expired_tasks( $backup_dir ) );
			$stored = json_decode( $this->spine->get_task( $task_id )->sub_state, true );
			$this->assertArrayNotHasKey( 'pull_manifest', $stored['params'] );
			$this->assertArrayNotHasKey( 'identity_preserve', $stored );
			$this->assertSame( $cursor['verification'], $stored['verification'] );
			$this->assertSame( $cursor['error'], $stored['error'] );
			$this->assertSame( $task_id, $this->spine->get_task_by_restore_id( 'restore123' )->task_id );
		} finally {
			$this->spine->delete_sidecar( $task_id, $backup_dir );
			rmdir( $backup_dir );
		}
	}

	/**
	 * Older terminal rows must remain reusable after their credentials are removed.
	 */
	public function test_reclaim_expired_tasks_scrubs_terminal_restore_cursor() {
		global $wpdb;
		$cursor  = $this->secret_restore_cursor();
		$task_id = $this->spine->create_task( 'restore_files', $cursor );
		$this->assertSame( 1, $wpdb->update( $this->spine->get_table_name(), array( 'phase' => MainWP_Child_Backup_Task::PHASE_COMPLETED, 'active_slot' => null ), array( 'task_id' => $task_id ) ) );
		$backup_cursor = $cursor;
		unset( $backup_cursor['params']['restore_id'] );
		$backup_id = $this->spine->create_task( 'db', $backup_cursor );
		$this->assertSame( 0, $this->spine->reclaim_expired_tasks() );
		$task   = $this->spine->get_task( $task_id );
		$stored = json_decode( $task->sub_state, true );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertArrayNotHasKey( 'pull_manifest', $stored['params'] );
		$this->assertArrayNotHasKey( 'identity_preserve', $stored );
		$this->assertSame( $cursor['verification'], $stored['verification'] );
		$this->assertSame( $cursor['error'], $stored['error'] );
		$this->assertSame( $task_id, $this->spine->get_task_by_restore_id( 'restore123' )->task_id );
		$this->assertSame( $backup_cursor, json_decode( $this->spine->get_task( $backup_id )->sub_state, true ) );
		$this->assertSame( 0, $this->spine->reclaim_expired_tasks() );
		$this->assertSame( $task->version, $this->spine->get_task( $task_id )->version );
	}

	/**
	 * Walk a phase chain via next_phase.
	 *
	 * @param string $type Task type.
	 *
	 * @return string[] Phases from queued to terminal.
	 */
	protected function walk_chain( $type ) {
		$phases  = array( MainWP_Child_Backup_Task::PHASE_QUEUED );
		$current = MainWP_Child_Backup_Task::PHASE_QUEUED;
		$guard   = 0;
		while ( $guard < 20 ) {
			$next = MainWP_Child_Backup_Task::next_phase( $current, $type );
			if ( null === $next ) {
				break;
			}
			$phases[] = $next;
			$current  = $next;
			++$guard;
		}
		return $phases;
	}

	/**
	 * The full restore chain visits every restore phase in order.
	 */
	public function test_restore_type_phase_order() {
		$this->assertSame(
			array(
				MainWP_Child_Backup_Task::PHASE_QUEUED,
				MainWP_Child_Backup_Task::PHASE_PULLING_ARTIFACTS,
				MainWP_Child_Backup_Task::PHASE_RECEIVING_ARTIFACTS,
				MainWP_Child_Backup_Task::PHASE_VERIFYING_ARTIFACTS,
				MainWP_Child_Backup_Task::PHASE_RESTORING_FILES,
				MainWP_Child_Backup_Task::PHASE_REPLAYING_DB,
				MainWP_Child_Backup_Task::PHASE_SWAPPING_DB,
				MainWP_Child_Backup_Task::PHASE_REWRITING_URLS,
				MainWP_Child_Backup_Task::PHASE_POST_RESTORE_VERIFY,
				MainWP_Child_Backup_Task::PHASE_COMPLETED,
			),
			$this->walk_chain( 'restore' )
		);
	}

	/**
	 * The db-only restore chain skips the file phase.
	 */
	public function test_restore_db_type_skips_file_phase() {
		$chain = $this->walk_chain( 'restore_db' );
		$this->assertNotContains( MainWP_Child_Backup_Task::PHASE_RESTORING_FILES, $chain );
		$this->assertContains( MainWP_Child_Backup_Task::PHASE_PULLING_ARTIFACTS, $chain );
		$this->assertContains( MainWP_Child_Backup_Task::PHASE_REPLAYING_DB, $chain );
		$this->assertContains( MainWP_Child_Backup_Task::PHASE_SWAPPING_DB, $chain );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, end( $chain ) );
	}

	/**
	 * The pulling phase slots between queued and receiving on every
	 * restore type, and never enters a backup chain.
	 */
	public function test_pulling_phase_position() {
		foreach ( array( 'restore', 'restore_db', 'restore_files' ) as $type ) {
			$this->assertSame(
				MainWP_Child_Backup_Task::PHASE_PULLING_ARTIFACTS,
				MainWP_Child_Backup_Task::next_phase( MainWP_Child_Backup_Task::PHASE_QUEUED, $type ),
				"Queued must advance to pulling for {$type}."
			);
			$this->assertSame(
				MainWP_Child_Backup_Task::PHASE_RECEIVING_ARTIFACTS,
				MainWP_Child_Backup_Task::next_phase( MainWP_Child_Backup_Task::PHASE_PULLING_ARTIFACTS, $type ),
				"Pulling must advance to receiving for {$type}."
			);
		}

		foreach ( array( 'full', 'db', 'files', 'incremental' ) as $type ) {
			$this->assertNotContains(
				MainWP_Child_Backup_Task::PHASE_PULLING_ARTIFACTS,
				$this->walk_chain( $type ),
				"Backup type {$type} must not pull artifacts."
			);
		}
	}

	/**
	 * Every restore phase name fits the varchar(20) phase column.
	 */
	public function test_restore_phase_names_fit_column() {
		$phases = $this->walk_chain( 'restore' );
		foreach ( $phases as $phase ) {
			$this->assertLessThanOrEqual( 20, strlen( $phase ), $phase );
		}
	}

	/**
	 * A restore task is created with the requested resume cap and an
	 * extended wall-clock deadline.
	 */
	public function test_restore_task_created_with_overrides() {
		$task_id = $this->spine->create_task( 'restore', array( 'params' => array() ), 20, 7200 );
		$this->assertNotFalse( $task_id );

		$task = $this->spine->get_task( $task_id );
		$this->assertSame( 'restore', $task->type );
		$this->assertSame( 20, (int) $task->max_resumes );

		$horizon = strtotime( $task->max_wall_clock_at ) - strtotime( $task->started_at );
		$this->assertSame( 7200, $horizon );
	}

	/**
	 * Default creation behavior is unchanged.
	 */
	public function test_default_create_task_unchanged() {
		$task_id = $this->spine->create_task( 'full', array() );
		$task    = $this->spine->get_task( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::DEFAULT_MAX_RESUMES, (int) $task->max_resumes );
		$expected = ( MainWP_Child_Backup_Task::DEFAULT_MAX_RESUMES + 2 ) * MainWP_Child_Backup_Task::SLICE_BUDGET_SECONDS * 4;
		$horizon  = strtotime( $task->max_wall_clock_at ) - strtotime( $task->started_at );
		$this->assertSame( $expected, $horizon );
	}

	/**
	 * A queued restore task counts as active, so the single-slot
	 * invariant blocks a backup (and a second restore) while it runs.
	 */
	public function test_restore_task_counts_as_active() {
		$this->spine->create_task( 'restore', array() );
		$this->assertNotEmpty( $this->spine->get_active_tasks() );
	}

	/**
	 * The files-only restore chain extracts files and verifies the site,
	 * never entering a database phase.
	 */
	public function test_restore_files_type_phase_order() {
		$this->assertSame(
			array(
				MainWP_Child_Backup_Task::PHASE_QUEUED,
				MainWP_Child_Backup_Task::PHASE_PULLING_ARTIFACTS,
				MainWP_Child_Backup_Task::PHASE_RECEIVING_ARTIFACTS,
				MainWP_Child_Backup_Task::PHASE_VERIFYING_ARTIFACTS,
				MainWP_Child_Backup_Task::PHASE_RESTORING_FILES,
				MainWP_Child_Backup_Task::PHASE_POST_RESTORE_VERIFY,
				MainWP_Child_Backup_Task::PHASE_COMPLETED,
			),
			$this->walk_chain( 'restore_files' )
		);

		$chain = $this->walk_chain( 'restore_files' );
		$this->assertNotContains( MainWP_Child_Backup_Task::PHASE_REPLAYING_DB, $chain );
		$this->assertNotContains( MainWP_Child_Backup_Task::PHASE_SWAPPING_DB, $chain );
		$this->assertNotContains( MainWP_Child_Backup_Task::PHASE_REWRITING_URLS, $chain );
	}
}
