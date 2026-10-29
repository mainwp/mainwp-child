<?php
/**
 * Class BackupRestoreTaskSpineTest
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Child_Backup_Task;
use MainWP\Child\MainWP_Backup_Error;

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

	public function test_restore_tombstone_insert_distinguishes_duplicate_and_query_failure() {
		$task_id = $this->spine->insert_restore_tombstone( 'spinecancel' );
		$this->assertIsString( $task_id );
		$before = $this->spine->get_task( $task_id );
		$this->assertNull( $this->spine->insert_restore_tombstone( 'spinecancel' ) );
		$this->assertEquals( $before, $this->spine->get_task( $task_id ) );
		$this->assertContains( MainWP_Backup_Error::ERR_RESTORE_CANCELLED_BEFORE_START, MainWP_Backup_Error::get_registered_codes() );
		$this->assertSame( 'This restore was cancelled before the site started it.', MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_RESTORE_CANCELLED_BEFORE_START ) );
		$table = $this->spine->get_table_name();
		$break = static function ( $query ) use ( $table ) {
			return 0 === strpos( $query, "INSERT INTO {$table} " ) ? 'INSERT INTO mainwp_child_missing_tombstone_table (task_id) VALUES (1)' : $query;
		};
		add_filter( 'query', $break );
		try {
			$this->assertFalse( $this->spine->insert_restore_tombstone( 'spinefailed' ) );
		} finally {
			remove_filter( 'query', $break );
		}
		$this->assertNull( $this->spine->get_task_by_restore_id( 'spinefailed' ) );
	}

	public function test_restore_tombstone_predicate_reads_inline_and_sidecar_cursors() {
		$task_id = $this->spine->insert_restore_tombstone( 'predicate1' );
		$this->assertIsString( $task_id );
		$task = $this->spine->get_task( $task_id );
		$dir = sys_get_temp_dir() . '/mainwp-tombstone-' . uniqid();
		mkdir( $dir );
		$sidecar = 'task_substate-' . $task_id . '-v0.json';
		try {
			$this->assertTrue( $this->spine->is_restore_tombstone( $task, $dir ) );
			file_put_contents( $dir . '/' . $sidecar, $task->sub_state );
			$task->sub_state = wp_json_encode( array( '_sidecar' => true, 'file' => $sidecar ) );
			$this->assertTrue( $this->spine->is_restore_tombstone( $task, $dir ) );
			$task->phase = MainWP_Child_Backup_Task::PHASE_COMPLETED;
			$this->assertFalse( $this->spine->is_restore_tombstone( $task, $dir ) );
			$task->phase = MainWP_Child_Backup_Task::PHASE_FAILED;
			$task->sub_state = wp_json_encode( array( 'error' => array( 'code' => MainWP_Backup_Error::ERR_RESTORE_CANCELLED ) ) );
			$this->assertFalse( $this->spine->is_restore_tombstone( $task, $dir ) );
		} finally {
			$this->spine->delete_sidecar( $task_id, $dir );
			rmdir( $dir );
		}
	}

	public function test_reclaim_expired_tasks_deletes_only_old_tombstones() {
		global $wpdb;
		$this->assertSame( 7 * DAY_IN_SECONDS, MainWP_Child_Backup_Task::RESTORE_TOMBSTONE_TTL_SECONDS );
		$old = $this->spine->insert_restore_tombstone( 'oldcancel' );
		$young = $this->spine->insert_restore_tombstone( 'youngcancel' );
		$this->assertIsString( $old );
		$this->assertIsString( $young );
		$real = $this->spine->create_task( 'restore_db', array( 'params' => array( 'restore_id' => 'realcancel' ) ) );
		$this->assertIsString( $real );
		$this->assertSame( 1, $this->spine->checkpoint( $real, 0, array( 'phase' => MainWP_Child_Backup_Task::PHASE_FAILED ) ) );
		$table = $this->spine->get_table_name();
		foreach ( array( $old, $real ) as $id ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET started_at = DATE_SUB(NOW(), INTERVAL 8 DAY) WHERE task_id = %s", $id ) );
		}
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET started_at = DATE_SUB(NOW(), INTERVAL 6 DAY) WHERE task_id = %s", $young ) );
		$young_before = $this->spine->get_task( $young );
		$real_before = $this->spine->get_task( $real );
		$dir = sys_get_temp_dir() . '/mainwp-tombstone-expiry-' . uniqid();
		mkdir( $dir );
		$sidecar = $dir . '/task_substate-' . $old . '-v0.json';
		file_put_contents( $sidecar, '{}' );
		try {
			$this->assertSame( 0, $this->spine->reclaim_expired_tasks( $dir ) );
			$this->assertNull( $this->spine->get_task( $old ) );
			$this->assertNull( $this->spine->get_task_by_restore_id( 'oldcancel' ) );
			$this->assertFileDoesNotExist( $sidecar );
			$this->assertEquals( $young_before, $this->spine->get_task( $young ) );
			$this->assertEquals( $real_before, $this->spine->get_task( $real ) );
		} finally {
			$this->spine->delete_sidecar( $old, $dir );
			rmdir( $dir );
		}
	}

	/** @dataProvider tombstone_expiry_guards */
	public function test_tombstone_expiry_delete_rechecks_phase_slot_and_age( $change ) {
		global $wpdb;
		$task_id = $this->spine->insert_restore_tombstone( 'guardcancel' );
		$this->assertIsString( $task_id );
		$table = $this->spine->get_table_name();
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET started_at = DATE_SUB(NOW(), INTERVAL 8 DAY) WHERE task_id = %s", $task_id ) );
		$attempted = false;
		$race = static function ( $query ) use ( &$race, &$attempted, $change, $wpdb, $table, $task_id ) {
			if ( 0 === strpos( $query, "DELETE FROM {$table} WHERE task_id =" ) ) {
				remove_filter( 'query', $race );
				$attempted = true;
				// nosemgrep: AIK_tainted-sql-string -- $change comes only from the static data provider below.
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET {$change} WHERE task_id = %s", $task_id ) );
			}
			return $query;
		};
		add_filter( 'query', $race );
		try {
			$this->spine->reclaim_expired_tasks();
		} finally {
			remove_filter( 'query', $race );
		}
		$this->assertTrue( $attempted );
		$this->assertNotNull( $this->spine->get_task( $task_id ) );
	}

	public static function tombstone_expiry_guards() {
		return array(
			'phase' => array( "phase = 'completed'" ),
			'active slot' => array( 'active_slot = 1' ),
			'age' => array( 'started_at = NOW()' ),
		);
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
	 * Terminal checkpointing must delete sidecars and retain the outcome inline.
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
			$this->assertNotFalse( $this->spine->write_sub_state( $task_id, $cursor, $backup_dir, (int) $task->version + 1 ) );
			$this->assertFalse( $this->spine->checkpoint( $task_id, (int) $task->version + 100, array( 'phase' => MainWP_Child_Backup_Task::PHASE_COMPLETED, 'sub_state' => $serialized ), true, $backup_dir ) );
			$this->assertCount( 2, glob( $backup_dir . '/task_substate-' . $task_id . '-v*.json' ) );
			$this->assertNotFalse( $this->spine->checkpoint( $task_id, (int) $task->version, array( 'phase' => MainWP_Child_Backup_Task::PHASE_COMPLETED, 'sub_state' => $serialized ), true, $backup_dir ) );
			$this->assertSame( array(), glob( $backup_dir . '/task_substate-' . $task_id . '-v*.json' ) );
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
			$this->assertSame( array(), glob( $backup_dir . '/task_substate-' . $task_id . '-v*.json' ) );
			$stored = json_decode( $this->spine->get_task( $task_id )->sub_state, true );
			$this->assertArrayNotHasKey( 'pull_manifest', $stored['params'] );
			$this->assertArrayNotHasKey( 'identity_preserve', $stored );
			$this->assertSame( $cursor['verification'], $stored['verification'] );
			$this->assertSame( $cursor['error'], $stored['error'] );
			$this->assertSame( $task_id, $this->spine->get_task_by_restore_id( 'restore123' )->task_id );
			$this->assertNotFalse( $this->spine->write_sub_state( $task_id, $cursor, $backup_dir, 99 ) );
			$this->assertCount( 1, glob( $backup_dir . '/task_substate-' . $task_id . '-v*.json' ) );
			$this->assertSame( 0, $this->spine->reclaim_expired_tasks( $backup_dir ) );
			$this->assertSame( array(), glob( $backup_dir . '/task_substate-' . $task_id . '-v*.json' ) );
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
