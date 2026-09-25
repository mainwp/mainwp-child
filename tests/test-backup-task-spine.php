<?php
/**
 * Class BackupTaskSpineTest
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Backup_Error;
use MainWP\Child\MainWP_Child_Backup_Task;
use MainWP\Child\MainWP_Child_Backup_Watchdog;
use MainWP\Child\MainWP_Child_Stats;
use MainWP\Child\MainWP_Exception;

/**
 * Tests for the minimal child backup task spine.
 *
 * The spine is what lets a large backup survive proxy-killed requests:
 * a persisted task row, an atomic DB-engine lease, and a watchdog that
 * resumes stalled work from the sub_state cursor.
 */
class BackupTaskSpineTest extends WP_UnitTestCase {

	/**
	 * Install the task table.
	 */
	public function set_up() {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		try {
			delete_option( MainWP_Child_Backup_Task::DB_VERSION_OPTION );
			MainWP_Child_Backup_Task::instance()->install();
		} finally {
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		}
	}

	/**
	 * Remove scratch files and the real spine table.
	 */
	public function tear_down() {
		global $wpdb;

		// Walk-run scratch files are not rolled back with the DB transaction;
		// sweep any a test seeded so they cannot leak into another test.
		$dirs = \MainWP\Child\MainWP_Helper::get_mainwp_dir( 'backup' );
		foreach ( (array) glob( rtrim( $dirs[0], '/' ) . '/' . \MainWP\Child\MainWP_Child_Backup_File_Walker::RUN_PREFIX . '*' ) as $run ) {
			unlink( $run ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test cleanup.
		}
		parent::tear_down();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . MainWP_Child_Backup_Task::instance()->get_table_name() );
	}

	/**
	 * The dedicated dbDelta table exists and is queryable after install.
	 */
	public function test_table_is_created() {
		global $wpdb;
		$table = MainWP_Child_Backup_Task::instance()->get_table_name();
		$this->assertStringContainsString( 'mainwp_child_backup_task', $table );

		$wpdb->last_error = '';
		$count            = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table ); // phpcs:ignore -- test probe.
		$this->assertSame( '', $wpdb->last_error, 'Task table must be queryable' );
		$this->assertSame( 0, (int) $count );
	}

	/**
	 * A new task starts queued with version 0 and a wall-clock deadline.
	 */
	public function test_create_task_initial_state() {
		$task_id = MainWP_Child_Backup_Task::instance()->create_task( 'full' );
		$this->assertNotEmpty( $task_id );

		$task = MainWP_Child_Backup_Task::instance()->get_task( $task_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_QUEUED, $task->phase );
		$this->assertSame( 0, (int) $task->version );
		$this->assertSame( 0, (int) $task->resume_count );
		$this->assertGreaterThan( 0, (int) $task->max_resumes );
		$this->assertNotEmpty( $task->max_wall_clock_at, 'Wall-clock deadline must be stamped at creation' );
		$this->assertNull( $task->lease_owner );
	}

	public function test_guarded_create_task_refuses_active_task() {
		global $wpdb;
		$spine   = MainWP_Child_Backup_Task::instance();
		$task_id = $spine->create_task( 'full' );
		$this->assertNotFalse( $task_id );

		foreach ( array( MainWP_Child_Backup_Task::PHASE_QUEUED, MainWP_Child_Backup_Task::PHASE_DUMPING_DB ) as $phase ) {
			$task = $spine->get_task( $task_id );
			$this->assertNotFalse( $spine->checkpoint( $task_id, (int) $task->version, array( 'phase' => $phase ) ) );
			$this->assertNull( $spine->create_task( 'restore', array(), null, null, null, true ), $phase );
			$this->assertSame( 1, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $spine->get_table_name() ), $phase );
		}
	}

	public function test_create_task_query_failure_is_false_for_guarded_and_unguarded_inserts() {
		$spine = MainWP_Child_Backup_Task::instance();
		$fail = static function ( $query ) use ( $spine ) {
			return 0 === strpos( $query, 'INSERT INTO ' . $spine->get_table_name() . ' ' ) ? '' : $query;
		};
		add_filter( 'query', $fail );
		try {
			$this->assertFalse( $spine->create_task( 'full' ) );
			$this->assertFalse( $spine->create_task( 'restore', array(), null, null, null, true ) );
			$this->assertEmpty( $spine->get_active_tasks() );
		} finally {
			remove_filter( 'query', $fail );
		}
	}

	public function test_guarded_create_task_allows_terminal_tasks() {
		global $wpdb;
		$spine = MainWP_Child_Backup_Task::instance();
		foreach ( array( MainWP_Child_Backup_Task::PHASE_COMPLETED, MainWP_Child_Backup_Task::PHASE_FAILED ) as $phase ) {
			$task_id = $spine->create_task( 'full' );
			$this->assertNotFalse( $task_id );
			$this->assertNotFalse( $spine->checkpoint( $task_id, 0, array( 'phase' => $phase ) ) );
		}

		$sub_state = array( 'params' => array( 'restore_id' => 'guard01' ) );
		$task_id   = $spine->create_task( 'restore', $sub_state, 20, 7200, null, true );
		$this->assertNotFalse( $task_id );
		$this->assertStringStartsWith( 'task-', $task_id );
		$this->assertSame( 3, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $spine->get_table_name() ) );
		$task = $spine->get_task( $task_id );
		$this->assertSame( $task_id, $task->task_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_QUEUED, $task->phase );
		$this->assertSame( 'restore', $task->type );
		$this->assertSame( $sub_state, json_decode( $task->sub_state, true ) );
		$this->assertSame( 20, (int) $task->max_resumes );
		$this->assertSame( 7200, strtotime( $task->max_wall_clock_at ) - strtotime( $task->started_at ) );
		$this->assertSame( $task->started_at, $task->last_progress_at );
		$this->assertSame( 0, (int) $task->resume_count );
		$this->assertSame( 0, (int) $task->version );
		$this->assertNull( $task->lease_owner );
		$this->assertNull( $task->lease_expires_at );
	}

	public function test_unguarded_create_task_refuses_active_task() {
		global $wpdb;
		$spine = MainWP_Child_Backup_Task::instance();
		$first = $spine->create_task( 'full' );
		$this->assertNotFalse( $first );
		try {
			$spine->create_task( 'restore' );
			$this->fail( 'The unique active slot must refuse a second non-terminal task.' );
		} catch ( MainWP_Exception $error ) {
			$this->assertSame( MainWP_Backup_Error::ERR_ALREADY_RUNNING, $error->get_message_error_code() );
		}
		$this->assertCount( 1, $spine->get_active_tasks() );
		$this->assertSame( 1, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $spine->get_table_name() ) );
	}

	/**
	 * Acquiring the lease wins exactly once per version - the second caller
	 * with the same (now stale) version must lose. This is what serializes a
	 * dashboard continuation racing the child-cron watchdog.
	 */
	public function test_lease_two_acquirers_exactly_one_wins() {
		$spine   = MainWP_Child_Backup_Task::instance();
		$task_id = $spine->create_task( 'full' );
		$task    = $spine->get_task( $task_id );

		$first  = $spine->acquire_lease( $task_id, 'dashboard', (int) $task->version, 120 );
		$second = $spine->acquire_lease( $task_id, 'watchdog', (int) $task->version, 120 );

		$this->assertTrue( $first );
		$this->assertFalse( $second, 'Stale version must not acquire the lease' );

		$task = $spine->get_task( $task_id );
		$this->assertSame( 'dashboard', $task->lease_owner );
	}

	/**
	 * A live lease blocks acquisition even with the current version.
	 */
	public function test_live_lease_blocks_other_owner() {
		$spine   = MainWP_Child_Backup_Task::instance();
		$task_id = $spine->create_task( 'full' );
		$task    = $spine->get_task( $task_id );

		$this->assertTrue( $spine->acquire_lease( $task_id, 'dashboard', (int) $task->version, 120 ) );

		$task = $spine->get_task( $task_id );
		$this->assertFalse( $spine->acquire_lease( $task_id, 'watchdog', (int) $task->version, 120 ) );
	}

	/**
	 * An expired lease can be taken over by another runner.
	 */
	public function test_expired_lease_can_be_stolen() {
		$spine   = MainWP_Child_Backup_Task::instance();
		$task_id = $spine->create_task( 'full' );
		$task    = $spine->get_task( $task_id );

		// Negative TTL: the lease is already expired the moment it is taken.
		$this->assertTrue( $spine->acquire_lease( $task_id, 'dashboard', (int) $task->version, -5 ) );

		$task = $spine->get_task( $task_id );
		$this->assertTrue( $spine->acquire_lease( $task_id, 'watchdog', (int) $task->version, 120 ) );

		$task = $spine->get_task( $task_id );
		$this->assertSame( 'watchdog', $task->lease_owner );
	}

	/**
	 * Releasing the lease frees it for the next runner.
	 */
	public function test_release_lease() {
		$spine   = MainWP_Child_Backup_Task::instance();
		$task_id = $spine->create_task( 'full' );
		$task    = $spine->get_task( $task_id );

		$spine->acquire_lease( $task_id, 'dashboard', (int) $task->version, 120 );
		$task = $spine->get_task( $task_id );
		$this->assertTrue( $spine->release_lease( $task_id, 'dashboard', (int) $task->version ) );

		$task = $spine->get_task( $task_id );
		$this->assertNull( $task->lease_owner );
		$this->assertTrue( $spine->acquire_lease( $task_id, 'watchdog', (int) $task->version, 120 ) );
	}

	/**
	 * Checkpoints are compare-and-swap writes: a stale version is rejected.
	 */
	public function test_checkpoint_cas_rejects_stale_version() {
		$spine   = MainWP_Child_Backup_Task::instance();
		$task_id = $spine->create_task( 'full' );
		$task    = $spine->get_task( $task_id );

		$new_version = $spine->checkpoint(
			$task_id,
			(int) $task->version,
			array(
				'phase'     => MainWP_Child_Backup_Task::PHASE_DUMPING_DB,
				'sub_state' => array( 'cursor' => 5 ),
			)
		);
		$this->assertNotFalse( $new_version );
		$this->assertGreaterThan( (int) $task->version, $new_version );

		// Replaying the same (stale) version must fail.
		$replay = $spine->checkpoint( $task_id, (int) $task->version, array( 'phase' => MainWP_Child_Backup_Task::PHASE_FAILED ) );
		$this->assertFalse( $replay );

		$task = $spine->get_task( $task_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_DUMPING_DB, $task->phase );
		$sub_state = json_decode( $task->sub_state, true );
		$this->assertSame( 5, $sub_state['cursor'] );
	}

	/**
	 * Terminal phases are recognized.
	 */
	public function test_terminal_phases() {
		$this->assertTrue( MainWP_Child_Backup_Task::is_terminal_phase( MainWP_Child_Backup_Task::PHASE_COMPLETED ) );
		$this->assertTrue( MainWP_Child_Backup_Task::is_terminal_phase( MainWP_Child_Backup_Task::PHASE_FAILED ) );
		$this->assertFalse( MainWP_Child_Backup_Task::is_terminal_phase( MainWP_Child_Backup_Task::PHASE_QUEUED ) );
		$this->assertFalse( MainWP_Child_Backup_Task::is_terminal_phase( MainWP_Child_Backup_Task::PHASE_DUMPING_DB ) );
		$this->assertFalse( MainWP_Child_Backup_Task::is_terminal_phase( MainWP_Child_Backup_Task::PHASE_ARCHIVING_FILES ) );
	}

	/**
	 * The full machine has upload and submit phases; neither is terminal.
	 */
	public function test_upload_and_submit_phases_exist_and_are_not_terminal() {
		$this->assertSame( 'uploading', MainWP_Child_Backup_Task::PHASE_UPLOADING );
		$this->assertSame( 'submitting', MainWP_Child_Backup_Task::PHASE_SUBMITTING );
		$this->assertFalse( MainWP_Child_Backup_Task::is_terminal_phase( MainWP_Child_Backup_Task::PHASE_UPLOADING ) );
		$this->assertFalse( MainWP_Child_Backup_Task::is_terminal_phase( MainWP_Child_Backup_Task::PHASE_SUBMITTING ) );
	}

	/**
	 * Phase routing for a full backup runs every work phase in order.
	 */
	public function test_next_phase_full_runs_all_phases() {
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_DUMPING_DB, MainWP_Child_Backup_Task::next_phase( MainWP_Child_Backup_Task::PHASE_QUEUED, 'full' ) );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_ARCHIVING_FILES, MainWP_Child_Backup_Task::next_phase( MainWP_Child_Backup_Task::PHASE_DUMPING_DB, 'full' ) );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_UPLOADING, MainWP_Child_Backup_Task::next_phase( MainWP_Child_Backup_Task::PHASE_ARCHIVING_FILES, 'full' ) );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_SUBMITTING, MainWP_Child_Backup_Task::next_phase( MainWP_Child_Backup_Task::PHASE_UPLOADING, 'full' ) );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, MainWP_Child_Backup_Task::next_phase( MainWP_Child_Backup_Task::PHASE_SUBMITTING, 'full' ) );
	}

	/**
	 * A db backup has no file-archive phase: the dump goes straight to upload.
	 */
	public function test_next_phase_db_skips_archiving() {
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_DUMPING_DB, MainWP_Child_Backup_Task::next_phase( MainWP_Child_Backup_Task::PHASE_QUEUED, 'db' ) );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_UPLOADING, MainWP_Child_Backup_Task::next_phase( MainWP_Child_Backup_Task::PHASE_DUMPING_DB, 'db' ) );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_SUBMITTING, MainWP_Child_Backup_Task::next_phase( MainWP_Child_Backup_Task::PHASE_UPLOADING, 'db' ) );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, MainWP_Child_Backup_Task::next_phase( MainWP_Child_Backup_Task::PHASE_SUBMITTING, 'db' ) );
	}

	/**
	 * A files backup has no database phase: queued goes straight to archiving.
	 */
	public function test_next_phase_files_skips_dump() {
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_ARCHIVING_FILES, MainWP_Child_Backup_Task::next_phase( MainWP_Child_Backup_Task::PHASE_QUEUED, 'files' ) );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_UPLOADING, MainWP_Child_Backup_Task::next_phase( MainWP_Child_Backup_Task::PHASE_ARCHIVING_FILES, 'files' ) );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_SUBMITTING, MainWP_Child_Backup_Task::next_phase( MainWP_Child_Backup_Task::PHASE_UPLOADING, 'files' ) );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, MainWP_Child_Backup_Task::next_phase( MainWP_Child_Backup_Task::PHASE_SUBMITTING, 'files' ) );
	}

	/**
	 * Terminal phases and unknown input have no next phase, and an unknown
	 * type falls back to the full machine.
	 */
	public function test_next_phase_edge_cases() {
		$this->assertNull( MainWP_Child_Backup_Task::next_phase( MainWP_Child_Backup_Task::PHASE_COMPLETED, 'full' ) );
		$this->assertNull( MainWP_Child_Backup_Task::next_phase( MainWP_Child_Backup_Task::PHASE_FAILED, 'full' ) );
		$this->assertNull( MainWP_Child_Backup_Task::next_phase( 'bogus_phase', 'full' ) );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_DUMPING_DB, MainWP_Child_Backup_Task::next_phase( MainWP_Child_Backup_Task::PHASE_QUEUED, 'unknown-type' ) );
	}

	/**
	 * Stalled tasks are found only past the threshold and only with a free lease.
	 */
	public function test_find_stalled_tasks() {
		global $wpdb;
		$spine   = MainWP_Child_Backup_Task::instance();
		$task_id = $spine->create_task( 'full' );

		// Fresh task: not stalled.
		$this->assertCount( 0, $spine->find_stalled_tasks( 180 ) );

		// Age the progress timestamp past the threshold.
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . $spine->get_table_name() . ' SET last_progress_at = DATE_SUB(NOW(), INTERVAL 600 SECOND) WHERE task_id = %s', $task_id ) ); // phpcs:ignore -- test fixture.
		$stalled = $spine->find_stalled_tasks( 180 );
		$this->assertCount( 1, $stalled );
		$this->assertSame( $task_id, $stalled[0]->task_id );

		// A held, unexpired lease excludes the task from stall pickup.
		$task = $spine->get_task( $task_id );
		$spine->acquire_lease( $task_id, 'dashboard', (int) $task->version, 300 );
		$this->assertCount( 0, $spine->find_stalled_tasks( 180 ) );
	}

	/**
	 * The watchdog marks a task failed once the resume cap is exhausted.
	 */
	public function test_watchdog_marks_failed_at_resume_cap() {
		global $wpdb;
		$spine   = MainWP_Child_Backup_Task::instance();
		$task_id = $spine->create_task( 'full' );

		$wpdb->query( $wpdb->prepare( 'UPDATE ' . $spine->get_table_name() . ' SET last_progress_at = DATE_SUB(NOW(), INTERVAL 600 SECOND), resume_count = max_resumes WHERE task_id = %s', $task_id ) ); // phpcs:ignore -- test fixture.

		MainWP_Child_Backup_Watchdog::instance()->check_tasks( 0 );

		$task = $spine->get_task( $task_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );
	}

	/**
	 * A stalled, in-flight foreign-key replay at the resume cap is rerouted to
	 * the rollback instead of failed, so a half-applied live database is rolled
	 * back to the aside snapshot. The rollback gets fresh resume budget.
	 */
	public function test_watchdog_cap_reroutes_in_flight_fk_replay() {
		global $wpdb;
		$spine   = MainWP_Child_Backup_Task::instance();
		$task_id = $spine->create_task( 'restore', array( 'db_replay' => array( 'fk_path' => true, 'snapshot_done' => true ) ) );

		$wpdb->query( $wpdb->prepare( 'UPDATE ' . $spine->get_table_name() . ' SET phase = %s, last_progress_at = DATE_SUB(NOW(), INTERVAL 600 SECOND), resume_count = max_resumes WHERE task_id = %s', MainWP_Child_Backup_Task::PHASE_REPLAYING_DB, $task_id ) ); // phpcs:ignore -- test fixture.

		MainWP_Child_Backup_Watchdog::instance()->check_tasks( 0 );

		$task = $spine->get_task( $task_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FK_ROLLBACK, $task->phase, 'A capped in-flight FK replay must roll back, not fail.' );
		$this->assertSame( 0, (int) $task->resume_count, 'The rerouted rollback must get fresh resume budget.' );
	}

	/**
	 * A capped FK task whose aside snapshot is not yet taken still fails: the
	 * live database has not been overwritten, so there is nothing to roll back
	 * and the reroute must not fire (it is specific to the half-applied case).
	 */
	public function test_watchdog_cap_fails_fk_replay_without_snapshot() {
		global $wpdb;
		$spine   = MainWP_Child_Backup_Task::instance();
		$task_id = $spine->create_task( 'restore', array( 'db_replay' => array( 'fk_path' => true, 'snapshot_done' => false ) ) );

		$wpdb->query( $wpdb->prepare( 'UPDATE ' . $spine->get_table_name() . ' SET phase = %s, last_progress_at = DATE_SUB(NOW(), INTERVAL 600 SECOND), resume_count = max_resumes WHERE task_id = %s', MainWP_Child_Backup_Task::PHASE_REPLAYING_DB, $task_id ) ); // phpcs:ignore -- test fixture.

		MainWP_Child_Backup_Watchdog::instance()->check_tasks( 0 );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $spine->get_task( $task_id )->phase, 'No aside snapshot yet means nothing to roll back, so it fails.' );
	}

	/**
	 * The watchdog resumes a stalled task: increments resume_count and
	 * re-enters under its own lease.
	 */
	public function test_watchdog_resumes_stalled_task() {
		global $wpdb;
		$spine   = MainWP_Child_Backup_Task::instance();
		$task_id = $spine->create_task( 'full' );

		$wpdb->query( $wpdb->prepare( 'UPDATE ' . $spine->get_table_name() . ' SET last_progress_at = DATE_SUB(NOW(), INTERVAL 600 SECOND) WHERE task_id = %s', $task_id ) ); // phpcs:ignore -- test fixture.

		MainWP_Child_Backup_Watchdog::instance()->check_tasks( 0 );

		$task = $spine->get_task( $task_id );
		$this->assertSame( 1, (int) $task->resume_count );
		$this->assertNotSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );
	}

	/**
	 * disarm() must not unschedule the shared cron event while an active task
	 * exists. A task created between run()'s empty-check and the unschedule
	 * would arm() to a no-op (the event is still scheduled) and then be left
	 * unguarded; the re-read before unscheduling closes that.
	 */
	public function test_disarm_aborts_when_a_task_exists() {
		// The custom interval is registered by the watchdog's init() at plugin
		// load; register it here so wp_schedule_event() accepts the recurrence.
		add_filter( 'cron_schedules', array( MainWP_Child_Backup_Watchdog::instance(), 'register_schedule' ) );
		wp_clear_scheduled_hook( MainWP_Child_Backup_Watchdog::CRON_HOOK );

		// create_task() arms the watchdog and leaves an active row behind.
		MainWP_Child_Backup_Task::instance()->create_task( 'full' );
		$this->assertNotFalse( wp_next_scheduled( MainWP_Child_Backup_Watchdog::CRON_HOOK ) );

		MainWP_Child_Backup_Watchdog::instance()->disarm();

		$this->assertNotFalse( wp_next_scheduled( MainWP_Child_Backup_Watchdog::CRON_HOOK ), 'disarm must not unschedule while an active task exists.' );

		wp_clear_scheduled_hook( MainWP_Child_Backup_Watchdog::CRON_HOOK );
	}

	/**
	 * With no active tasks, disarm() still unschedules the event as before.
	 */
	public function test_disarm_unschedules_when_no_active_tasks() {
		add_filter( 'cron_schedules', array( MainWP_Child_Backup_Watchdog::instance(), 'register_schedule' ) );
		wp_clear_scheduled_hook( MainWP_Child_Backup_Watchdog::CRON_HOOK );

		MainWP_Child_Backup_Watchdog::instance()->arm();
		$this->assertNotFalse( wp_next_scheduled( MainWP_Child_Backup_Watchdog::CRON_HOOK ) );

		MainWP_Child_Backup_Watchdog::instance()->disarm();

		$this->assertFalse( wp_next_scheduled( MainWP_Child_Backup_Watchdog::CRON_HOOK ), 'disarm must unschedule when no task is active.' );
	}

	/**
	 * The backup_task_status action is registered in the callable router so
	 * the Dashboard can poll task state.
	 */
	public function test_backup_task_status_action_is_registered() {
		$callable = \MainWP\Child\MainWP_Child_Callable::get_instance();
		$prop     = new ReflectionProperty( \MainWP\Child\MainWP_Child_Callable::class, 'callableFunctions' );
		$prop->setAccessible( true );
		$map = $prop->getValue( $callable );

		$this->assertArrayHasKey( 'backup_task_status', $map );
		$this->assertTrue( method_exists( $callable, 'backup_task_status' ) );
	}

	/**
	 * The task create and run actions are registered so the Dashboard can
	 * start a task and drive it one slice per continuation call.
	 */
	public function test_task_create_and_run_actions_are_registered() {
		$callable = \MainWP\Child\MainWP_Child_Callable::get_instance();
		$prop     = new ReflectionProperty( \MainWP\Child\MainWP_Child_Callable::class, 'callableFunctions' );
		$prop->setAccessible( true );
		$map = $prop->getValue( $callable );

		$this->assertArrayHasKey( 'backup_task_create', $map );
		$this->assertTrue( method_exists( $callable, 'backup_task_create' ) );
		$this->assertArrayHasKey( 'backup_task_run', $map );
		$this->assertTrue( method_exists( $callable, 'backup_task_run' ) );
	}

	/**
	 * Creating a task from request params persists them into the resume
	 * cursor and starts the machine queued.
	 */
	public function test_create_task_for_params_persists_params() {
		$runner  = \MainWP\Child\MainWP_Child_Backup_Task_Runner::instance();
		$task_id = $runner->create_task_for_params(
			'db',
			array(
				'ext'      => 'tar.gz',
				'file'     => 'mysite',
				'excludes' => array( 'wp-content/cache' ),
			)
		);

		$this->assertNotEmpty( $task_id );

		$spine = MainWP_Child_Backup_Task::instance();
		$task  = $spine->get_task( $task_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_QUEUED, $task->phase );
		$this->assertSame( 'db', $task->type );

		$sub_state = json_decode( $task->sub_state, true );
		$this->assertSame( 'tar.gz', $sub_state['params']['ext'] );
		$this->assertSame( 'mysite', $sub_state['params']['file'] );
		$this->assertSame( array( 'wp-content/cache' ), $sub_state['params']['excludes'] );
	}

	/**
	 * A pushed parent manifest is spilled to a scratch file at create time, so
	 * a manifest large enough to bloat (or overflow) the task row never lands
	 * in the sub_state column.
	 */
	public function test_large_parent_manifest_spills_out_of_the_task_row() {
		$runner = \MainWP\Child\MainWP_Child_Backup_Task_Runner::instance();
		$spine  = MainWP_Child_Backup_Task::instance();

		$manifest = str_repeat( "wp-content/uploads/photo.jpg\t1234\t5678\n", 50000 ); // ~2 MB files.list.
		$b64      = base64_encode( $manifest );
		$this->assertGreaterThan( 1000000, strlen( $b64 ), 'Fixture must be large enough to matter.' );

		$task_id = $runner->create_task_for_params( 'incremental', array( 'ext' => 'zip', 'parent_manifest_b64' => $b64 ) );
		$task    = $spine->get_task( $task_id );
		$sub     = json_decode( $task->sub_state, true );

		$this->assertArrayNotHasKey( 'parent_manifest_b64', $sub['params'], 'The base64 manifest must not stay in the row.' );
		$this->assertArrayHasKey( 'parent_manifest_file', $sub['params'] );
		$this->assertLessThan( 4000, strlen( (string) $task->sub_state ), 'The manifest must not bloat the task row.' );

		$dir = rtrim( \MainWP\Child\MainWP_Helper::get_mainwp_dir( 'backup' )[0], '/' );
		$this->assertSame( $manifest, file_get_contents( $dir . '/' . $sub['params']['parent_manifest_file'] ) );

		unlink( $dir . '/' . $sub['params']['parent_manifest_file'] );
	}

	/**
	 * Resolving a spilled parent preserves its path, bytes, and cursor reference
	 * so a resumed slice can read the same file.
	 */
	public function test_resolve_parent_manifest_retains_spilled_file() {
		$runner     = \MainWP\Child\MainWP_Child_Backup_Task_Runner::instance();
		$backup_dir = rtrim( \MainWP\Child\MainWP_Helper::get_mainwp_dir( 'backup' )[0], '/' );
		$name       = 'parent-manifest-testspill.list';
		file_put_contents( $backup_dir . '/' . $name, "a.php\t1\t1\n" ); // phpcs:ignore -- test fixture.

		$sub_state = array( 'params' => array( 'parent_manifest_file' => $name ) );
		$method    = new ReflectionMethod( \MainWP\Child\MainWP_Child_Backup_Task_Runner::class, 'resolve_parent_manifest' );
		$method->setAccessible( true );
		$parent = $method->invokeArgs( $runner, array( &$sub_state, $backup_dir ) );

		$this->assertSame( $backup_dir . '/' . $name, $parent );
		$this->assertSame( "a.php\t1\t1\n", file_get_contents( $parent ) );
		$this->assertFileExists( $backup_dir . '/' . $name, 'The spilled file must remain available for retry.' );
		$this->assertSame( $name, $sub_state['params']['parent_manifest_file'] );

		unlink( $parent );
	}

	/**
	 * An unknown backup type is rejected.
	 */
	public function test_create_task_for_params_rejects_invalid_type() {
		$this->expectException( \MainWP\Child\MainWP_Exception::class );
		\MainWP\Child\MainWP_Child_Backup_Task_Runner::instance()->create_task_for_params( 'bogus', array() );
	}

	/**
	 * A second task cannot start while one is active - the single-backup
	 * invariant the legacy pid files enforced.
	 */
	public function test_create_task_for_params_rejects_active_task() {
		$runner = \MainWP\Child\MainWP_Child_Backup_Task_Runner::instance();
		$runner->create_task_for_params( 'db', array( 'ext' => 'tar' ) );

		try {
			$runner->create_task_for_params( 'full', array( 'ext' => 'tar' ) );
			$this->fail( 'A second active task must be rejected' );
		} catch ( \MainWP\Child\MainWP_Exception $e ) {
			$this->assertSame( \MainWP\Child\MainWP_Backup_Error::ERR_ALREADY_RUNNING, $e->get_message_error_code() );
		}
	}

	/**
	 * The legacy Run Now entry point refuses to start while a spine task is
	 * active - the two single-backup invariants (spine active-task, legacy
	 * pid lock) do not see each other on their own, so the legacy engine
	 * must check the spine explicitly instead of only its own pid file.
	 */
	public function test_backup_refuses_when_spine_task_active() {
		\MainWP\Child\MainWP_Child_Backup_Task_Runner::instance()->create_task_for_params( 'db', array( 'ext' => 'tar' ) );

		try {
			\MainWP\Child\MainWP_Backup::get()->assert_no_active_spine_task();
			$this->fail( 'A legacy Run Now must be refused while a spine task is active' );
		} catch ( \MainWP\Child\MainWP_Exception $e ) {
			$this->assertSame( \MainWP\Child\MainWP_Backup_Error::ERR_ALREADY_RUNNING, $e->get_message_error_code() );
		}
	}

	/**
	 * With no spine task active, the legacy entry point's guard is a no-op.
	 */
	public function test_backup_allows_when_no_spine_task_active() {
		$this->expectNotToPerformAssertions();
		\MainWP\Child\MainWP_Backup::get()->assert_no_active_spine_task();
	}

	/**
	 * A fresh legacy pid lock blocks a new spine task the same way an
	 * active spine task blocks a legacy Run Now - the other half of the
	 * single-backup invariant. No task row is left behind by the refusal.
	 */
	public function test_create_task_for_params_rejects_fresh_pid_lock() {
		$runner     = \MainWP\Child\MainWP_Child_Backup_Task_Runner::instance();
		$backup_dir = rtrim( \MainWP\Child\MainWP_Helper::get_mainwp_dir( 'backup' )[0], '/' );
		$pid_file   = $backup_dir . '/backup-live999.pid';
		file_put_contents( $pid_file, 'legacy' ); // phpcs:ignore -- test fixture.
		touch( $pid_file, time() );

		try {
			$runner->create_task_for_params( 'db', array( 'ext' => 'tar' ) );
			$this->fail( 'A fresh legacy pid lock must block a new spine task' );
		} catch ( \MainWP\Child\MainWP_Exception $e ) {
			$this->assertSame( \MainWP\Child\MainWP_Backup_Error::ERR_ALREADY_RUNNING, $e->get_message_error_code() );
		} finally {
			unlink( $pid_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test cleanup.
		}

		$this->assertEmpty( MainWP_Child_Backup_Task::instance()->get_active_tasks() );
	}

	/**
	 * A pid file past the 160s freshness window is a stale lock and does not
	 * block a new spine task.
	 */
	public function test_create_task_for_params_allows_stale_pid_lock() {
		$runner     = \MainWP\Child\MainWP_Child_Backup_Task_Runner::instance();
		$backup_dir = rtrim( \MainWP\Child\MainWP_Helper::get_mainwp_dir( 'backup' )[0], '/' );
		$pid_file   = $backup_dir . '/backup-stale999.pid';
		file_put_contents( $pid_file, 'legacy' ); // phpcs:ignore -- test fixture.
		touch( $pid_file, time() - 161 );

		try {
			$this->assertNotEmpty( $runner->create_task_for_params( 'db', array( 'ext' => 'tar' ) ) );
		} finally {
			unlink( $pid_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test cleanup.
		}
	}

	/**
	 * A terminal task can be created over - finished work never blocks the
	 * next backup.
	 */
	public function test_create_task_for_params_allows_after_terminal() {
		$spine   = MainWP_Child_Backup_Task::instance();
		$runner  = \MainWP\Child\MainWP_Child_Backup_Task_Runner::instance();
		$task_id = $runner->create_task_for_params( 'db', array( 'ext' => 'tar' ) );

		$task = $spine->get_task( $task_id );
		$spine->checkpoint( $task_id, (int) $task->version, array( 'phase' => MainWP_Child_Backup_Task::PHASE_COMPLETED ) );

		$this->assertNotEmpty( $runner->create_task_for_params( 'db', array( 'ext' => 'tar' ) ) );
	}

	/**
	 * A new backup reclaims an orphan that is already past its wall-clock
	 * deadline. On a low/no-traffic child the watchdog cron may never fire to
	 * clear an interrupted task, so the new request self-heals the blocking row
	 * itself instead of failing forever with ERR_ALREADY_RUNNING.
	 */
	public function test_create_task_for_params_reclaims_expired_orphan() {
		global $wpdb;
		$spine  = MainWP_Child_Backup_Task::instance();
		$runner = \MainWP\Child\MainWP_Child_Backup_Task_Runner::instance();

		// An interrupted task left non-terminal, backdated past its deadline.
		$orphan = $spine->create_task( 'full', array( 'params' => array( 'ext' => 'tar' ) ) );
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- test fixture: backdate the deadline.
			$wpdb->prepare(
				'UPDATE ' . $spine->get_table_name() . ' SET max_wall_clock_at = DATE_SUB(NOW(), INTERVAL 60 SECOND) WHERE task_id = %s',
				$orphan
			)
		);

		// The new request must succeed, not throw the single-backup guard.
		$new_id = $runner->create_task_for_params( 'db', array( 'ext' => 'tar' ) );
		$this->assertNotEmpty( $new_id );
		$this->assertNotSame( $orphan, $new_id );

		// The orphan was driven terminal failed; only the new task is active.
		$this->assertSame( 'failed', $spine->get_task( $orphan )->phase );
		$active = $spine->get_active_tasks();
		$this->assertCount( 1, $active );
		$this->assertSame( $new_id, $active[0]->task_id );
	}

	/**
	 * An active task still inside its wall-clock deadline is NOT reclaimed: a
	 * legitimately running backup keeps blocking a second one. Reclaim only
	 * clears deadline-expired orphans, never a live operation.
	 */
	public function test_create_task_for_params_keeps_active_within_deadline() {
		$runner = \MainWP\Child\MainWP_Child_Backup_Task_Runner::instance();
		$runner->create_task_for_params( 'db', array( 'ext' => 'tar' ) ); // Fresh deadline ~48 min out.

		try {
			$runner->create_task_for_params( 'full', array( 'ext' => 'tar' ) );
			$this->fail( 'A non-expired active task must still be rejected' );
		} catch ( \MainWP\Child\MainWP_Exception $e ) {
			$this->assertSame( \MainWP\Child\MainWP_Backup_Error::ERR_ALREADY_RUNNING, $e->get_message_error_code() );
		}
	}

	/**
	 * A plain dashboard sync reclaims an orphan past its wall-clock deadline.
	 * The sync runs far more often than a backup attempt, so an idle child with
	 * no cron traffic self-heals the blocking row on the next sync instead of
	 * staying stuck until someone starts another backup.
	 */
	public function test_site_stats_reclaims_expired_orphan() {
		global $wpdb;
		$spine = MainWP_Child_Backup_Task::instance();

		$orphan = $spine->create_task( 'full', array( 'params' => array( 'ext' => 'tar' ) ) );
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- test fixture: backdate the deadline.
			$wpdb->prepare(
				'UPDATE ' . $spine->get_table_name() . ' SET max_wall_clock_at = DATE_SUB(NOW(), INTERVAL 60 SECOND) WHERE task_id = %s',
				$orphan
			)
		);

		MainWP_Child_Stats::get_instance()->reclaim_expired_backup_tasks();

		$this->assertSame( 'failed', $spine->get_task( $orphan )->phase );
		$this->assertEmpty( $spine->get_active_tasks() );
	}

	/**
	 * A sync does NOT reclaim a backup still inside its wall-clock deadline: a
	 * legitimately running backup keeps its task across syncs. Reclaim only
	 * clears deadline-expired orphans, never a live operation.
	 */
	public function test_site_stats_does_not_reclaim_active_within_deadline() {
		$spine  = MainWP_Child_Backup_Task::instance();
		$active = $spine->create_task( 'full', array( 'params' => array( 'ext' => 'tar' ) ) ); // Fresh deadline ~48 min out.

		MainWP_Child_Stats::get_instance()->reclaim_expired_backup_tasks();

		$this->assertNotSame( 'failed', $spine->get_task( $active )->phase );
		$this->assertCount( 1, $spine->get_active_tasks() );
	}

	/**
	 * On a site that never activated native backups the spine version option is
	 * absent, so the gate skips the reclaim entirely - a sync must not query a
	 * table that may not exist. The gate firing leaves even an expired orphan
	 * untouched, which is how the test observes that no reclaim ran.
	 */
	public function test_site_stats_reclaim_is_no_op_without_table() {
		global $wpdb;
		$spine = MainWP_Child_Backup_Task::instance();

		$orphan = $spine->create_task( 'full', array( 'params' => array( 'ext' => 'tar' ) ) );
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- test fixture: backdate the deadline.
			$wpdb->prepare(
				'UPDATE ' . $spine->get_table_name() . ' SET max_wall_clock_at = DATE_SUB(NOW(), INTERVAL 60 SECOND) WHERE task_id = %s',
				$orphan
			)
		);

		// Simulate native backups never installed on this site: drop the version
		// option so the gate short-circuits before touching the table.
		delete_option( MainWP_Child_Backup_Task::DB_VERSION_OPTION );

		MainWP_Child_Stats::get_instance()->reclaim_expired_backup_tasks();

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_QUEUED, $spine->get_task( $orphan )->phase );
	}

	/**
	 * Cancel drives the single active backup terminal even when the Dashboard
	 * sends no task id (the orphan case): it resolves the one active backup,
	 * lands it failed with the cancel code, and clears the active set.
	 */
	public function test_cancel_backup_task_drives_active_terminal() {
		$spine   = MainWP_Child_Backup_Task::instance();
		$runner  = \MainWP\Child\MainWP_Child_Backup_Task_Runner::instance();
		$task_id = $runner->create_task_for_params( 'db', array( 'ext' => 'tar' ) );

		$outcome = $runner->cancel_backup_task();

		$this->assertSame( 'ok', $outcome['result'] );
		$task = $spine->get_task( $task_id );
		$this->assertSame( 'failed', $task->phase );
		$this->assertSame( \MainWP\Child\MainWP_Backup_Error::ERR_BACKUP_CANCELLED, $spine->status_payload( $task )['error_code'] );
		$this->assertEmpty( $spine->get_active_tasks() );
	}

	/**
	 * Cancel by explicit task id lands that task failed with the cancel code.
	 */
	public function test_cancel_backup_task_by_explicit_id() {
		$spine   = MainWP_Child_Backup_Task::instance();
		$runner  = \MainWP\Child\MainWP_Child_Backup_Task_Runner::instance();
		$task_id = $runner->create_task_for_params( 'full', array( 'ext' => 'tar' ) );

		$outcome = $runner->cancel_backup_task( $task_id );

		$this->assertSame( 'ok', $outcome['result'] );
		$this->assertSame( 'failed', $spine->get_task( $task_id )->phase );
	}

	/**
	 * Cancel with nothing running reports no_active rather than erroring.
	 */
	public function test_cancel_backup_task_no_active_task() {
		$outcome = \MainWP\Child\MainWP_Child_Backup_Task_Runner::instance()->cancel_backup_task();
		$this->assertSame( 'no_active', $outcome['result'] );
	}

	/**
	 * Cancelling a finished backup is a no-op that reports the terminal phase
	 * instead of failing a completed backup.
	 */
	public function test_cancel_backup_task_already_terminal() {
		$spine   = MainWP_Child_Backup_Task::instance();
		$runner  = \MainWP\Child\MainWP_Child_Backup_Task_Runner::instance();
		$task_id = $runner->create_task_for_params( 'db', array( 'ext' => 'tar' ) );
		$task    = $spine->get_task( $task_id );
		$spine->checkpoint( $task_id, (int) $task->version, array( 'phase' => MainWP_Child_Backup_Task::PHASE_COMPLETED ) );

		$outcome = $runner->cancel_backup_task( $task_id );

		$this->assertSame( 'already_terminal', $outcome['result'] );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $spine->get_task( $task_id )->phase );
	}

	/**
	 * Cancel never breaks a live lease: while another runner owns the task the
	 * cancel reports busy and changes nothing.
	 */
	public function test_cancel_backup_task_while_lease_held_returns_busy() {
		$spine   = MainWP_Child_Backup_Task::instance();
		$runner  = \MainWP\Child\MainWP_Child_Backup_Task_Runner::instance();
		$task_id = $runner->create_task_for_params( 'db', array( 'ext' => 'tar' ) );
		$task    = $spine->get_task( $task_id );
		$this->assertTrue( $spine->acquire_lease( $task_id, 'other-runner', (int) $task->version ) );

		$outcome = $runner->cancel_backup_task( $task_id );

		$this->assertSame( 'busy', $outcome['result'] );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_QUEUED, $spine->get_task( $task_id )->phase );
	}

	/**
	 * A late cancel keeps the artifact and spilled-manifest references in
	 * sub_state so the retention prune can still delete those leftover files.
	 * checkpoint() overwrites sub_state, so the cancel must carry them forward.
	 */
	public function test_cancel_backup_task_preserves_artifact_references_for_prune() {
		$spine   = MainWP_Child_Backup_Task::instance();
		$runner  = \MainWP\Child\MainWP_Child_Backup_Task_Runner::instance();
		$task_id = $runner->create_task_for_params( 'incremental', array( 'ext' => 'zip' ) );

		// A backup that has produced an artifact and spilled a parent manifest.
		$task = $spine->get_task( $task_id );
		$spine->checkpoint(
			$task_id,
			(int) $task->version,
			array(
				'sub_state' => array(
					'artifact' => array( 'file' => 'snapshot-123.zip' ),
					'params'   => array( 'parent_manifest_file' => 'parent-manifest-abc.list' ),
				),
			)
		);

		$this->assertSame( 'ok', $runner->cancel_backup_task( $task_id )['result'] );

		$sub = json_decode( $spine->get_task( $task_id )->sub_state, true );
		$this->assertSame( 'snapshot-123.zip', $sub['artifact']['file'], 'prune_task needs the artifact file reference.' );
		$this->assertSame( 'parent-manifest-abc.list', $sub['params']['parent_manifest_file'], 'prune_task needs the spilled manifest reference.' );
		$this->assertSame( \MainWP\Child\MainWP_Backup_Error::ERR_BACKUP_CANCELLED, $sub['error']['code'] );
	}

	/**
	 * The backup cancel endpoint never touches a restore task - restores have
	 * their own cancel. An active restore reads as no backup to cancel.
	 */
	public function test_cancel_backup_task_ignores_restore() {
		$spine  = MainWP_Child_Backup_Task::instance();
		$runner = \MainWP\Child\MainWP_Child_Backup_Task_Runner::instance();
		$restore_id = $spine->create_task( 'restore', array( 'params' => array() ) );

		$this->assertSame( 'no_active', $runner->cancel_backup_task()['result'] );
		$this->assertArrayHasKey( 'error', $runner->cancel_backup_task( $restore_id ) );
		$this->assertNotSame( 'failed', $spine->get_task( $restore_id )->phase );
	}

	/**
	 * The status payload surfaces the artifact top-level on completion and
	 * the error on failure - what the Dashboard consumes.
	 */
	public function test_status_payload_shapes() {
		$spine   = MainWP_Child_Backup_Task::instance();
		$task_id = $spine->create_task( 'db', array( 'params' => array( 'ext' => 'tar' ) ) );
		$task    = $spine->get_task( $task_id );

		$payload = $spine->status_payload( $task );
		$this->assertSame( $task_id, $payload['task_id'] );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_QUEUED, $payload['phase'] );
		$this->assertArrayNotHasKey( 'file', $payload );

		$spine->checkpoint(
			$task_id,
			(int) $task->version,
			array(
				'phase'     => MainWP_Child_Backup_Task::PHASE_COMPLETED,
				'sub_state' => array(
					'artifact' => array(
						'file' => 'backup-123.tar',
						'size' => 4096,
					),
				),
			)
		);
		$payload = $spine->status_payload( $spine->get_task( $task_id ) );
		$this->assertSame( 'backup-123.tar', $payload['file'] );
		$this->assertSame( 4096, $payload['size'] );

		$task_id2 = $spine->create_task( 'db' );
		$task2    = $spine->get_task( $task_id2 );
		$spine->checkpoint(
			$task_id2,
			(int) $task2->version,
			array(
				'phase'     => MainWP_Child_Backup_Task::PHASE_FAILED,
				'sub_state' => array(
					'error' => array(
						'message' => 'dump exploded',
						'code'    => 'mainwp_backup_db_query_failed',
					),
				),
			)
		);
		$payload = $spine->status_payload( $spine->get_task( $task_id2 ) );
		$this->assertSame( 'dump exploded', $payload['error'] );
		$this->assertSame( 'mainwp_backup_db_query_failed', $payload['error_code'] );
	}

	/**
	 * Advancing a terminal task is a no-op - no lease churn, no version bump.
	 */
	public function test_advance_terminal_task_is_noop() {
		$spine   = MainWP_Child_Backup_Task::instance();
		$task_id = $spine->create_task( 'db' );
		$task    = $spine->get_task( $task_id );
		$spine->checkpoint( $task_id, (int) $task->version, array( 'phase' => MainWP_Child_Backup_Task::PHASE_COMPLETED ) );

		$before = $spine->get_task( $task_id );
		$after  = \MainWP\Child\MainWP_Child_Backup_Task_Runner::instance()->advance_one_slice( $before, 'dashboard' );

		$this->assertSame( (int) $before->version, (int) $after->version );
		$this->assertNull( $after->lease_owner );
	}

	/**
	 * The preflight readiness report advertises the task machine so the
	 * Dashboard can detect support.
	 */
	public function test_preflight_advertises_task_machine() {
		$report = \MainWP\Child\MainWP_Backup::get()->check_backup_readiness();
		$this->assertSame( 1, $report['task_spine'] );
		$this->assertSame( 1, $report['restore_create_idempotency'] );
	}

	/**
	 * The exclude list assembly applies the standard MainWP exclusions and
	 * the optional backup/cache expansions.
	 */
	public function test_assemble_exclude_list() {
		$backup = \MainWP\Child\MainWP_Backup::get();

		$plain = $backup->assemble_exclude_list( array( 'wp-content/uploads/big' ), false, false );
		$this->assertContains( 'wp-content/uploads/big', $plain );
		$this->assertContains( 'wp-content/uploads/mainwp', $plain );
		$this->assertContains( 'wp-content/object-cache.php', $plain );
		$this->assertNotContains( 'wp-content/uploads/backupbuddy_backups', $plain );

		$expanded = $backup->assemble_exclude_list( array(), true, true );
		$this->assertContains( 'wp-content/uploads/backupbuddy_backups', $expanded );
		$this->assertContains( 'wp-content/cache/wp-rocket', $expanded );
	}

	public function test_wall_clock_deadline_waits_for_lease_expiry() {
		global $wpdb;
		$spine = MainWP_Child_Backup_Task::instance();
		foreach ( array( 'full', 'restore_db' ) as $type ) {
			$task_id = $spine->create_task( $type );
			$task = $spine->get_task( $task_id );
			$this->assertTrue( $spine->acquire_lease( $task_id, 'live-worker', (int) $task->version, 180 ) );
			$this->assertSame( 1, $wpdb->query( $wpdb->prepare( 'UPDATE ' . $spine->get_table_name() . ' SET max_wall_clock_at = DATE_SUB(NOW(), INTERVAL 60 SECOND) WHERE task_id = %s', $task_id ) ) );
			$before = $spine->get_task( $task_id );
			$this->assertFalse( $spine->enforce_wall_clock_deadline( $task_id ) );
			$after = $spine->get_task( $task_id );
			$this->assertSame( $before->phase, $after->phase );
			$this->assertSame( $before->version, $after->version );
			$this->assertSame( 'live-worker', $after->lease_owner );

			$this->assertSame( 1, $wpdb->query( $wpdb->prepare( 'UPDATE ' . $spine->get_table_name() . ' SET lease_expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE task_id = %s', $task_id ) ) );
			$this->assertTrue( $spine->enforce_wall_clock_deadline( $task_id ) );
			$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $spine->get_task( $task_id )->phase );
		}
	}

	/**
	 * A task past its wall-clock deadline is marked failed regardless of
	 * resume_count - the dashboard-checked deadline closes the stuck-forever
	 * case on low-traffic children whose cron never fires.
	 */
	public function test_wall_clock_deadline_marks_failed() {
		global $wpdb;
		$spine   = MainWP_Child_Backup_Task::instance();
		$task_id = $spine->create_task( 'full' );

		$wpdb->query( $wpdb->prepare( 'UPDATE ' . $spine->get_table_name() . ' SET max_wall_clock_at = DATE_SUB(NOW(), INTERVAL 60 SECOND) WHERE task_id = %s', $task_id ) ); // phpcs:ignore -- test fixture.

		$this->assertTrue( $spine->enforce_wall_clock_deadline( $task_id ) );
		$task = $spine->get_task( $task_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );

		// The Dashboard sees a timeout reason and code, not a bare "failed".
		$payload = $spine->status_payload( $task );
		$this->assertSame( \MainWP\Child\MainWP_Backup_Error::ERR_WALL_CLOCK, $payload['error_code'] );
		$this->assertNotEmpty( $payload['error'] );

		// A healthy task is untouched.
		$task_id2 = $spine->create_task( 'full' );
		$this->assertFalse( $spine->enforce_wall_clock_deadline( $task_id2 ) );
		$task2 = $spine->get_task( $task_id2 );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_QUEUED, $task2->phase );
	}

	/**
	 * A task that has never left the queued phase is reclaimed after the
	 * shorter 15-minute stall window, even though its full wall-clock
	 * deadline (~48 min) has not expired - resume_count is not a reliable
	 * staleness signal for a task that never advanced its first slice.
	 */
	public function test_enforce_wall_clock_deadline_reclaims_stale_queued_task() {
		global $wpdb;
		$spine   = MainWP_Child_Backup_Task::instance();
		$task_id = $spine->create_task( 'full' );

		$wpdb->query( $wpdb->prepare( 'UPDATE ' . $spine->get_table_name() . ' SET started_at = DATE_SUB(NOW(), INTERVAL 901 SECOND) WHERE task_id = %s', $task_id ) ); // phpcs:ignore -- test fixture.

		$task = $spine->get_task( $task_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_QUEUED, $task->phase );

		// Compared DB-side to avoid a PHP/MySQL timezone mismatch skewing a
		// PHP-side strtotime() comparison against the stored datetime.
		$still_future = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT max_wall_clock_at > NOW() FROM ' . $spine->get_table_name() . ' WHERE task_id = %s', $task_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from trusted helper, test assertion query.
		$this->assertSame( 1, $still_future, 'The full wall-clock deadline must still be in the future.' );

		$this->assertTrue( $spine->enforce_wall_clock_deadline( $task_id ) );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $spine->get_task( $task_id )->phase );
	}

	/**
	 * A queued task younger than the 15-minute stall window is left alone.
	 */
	public function test_enforce_wall_clock_deadline_keeps_fresh_queued_task() {
		$spine   = MainWP_Child_Backup_Task::instance();
		$task_id = $spine->create_task( 'full' );

		$this->assertFalse( $spine->enforce_wall_clock_deadline( $task_id ) );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_QUEUED, $spine->get_task( $task_id )->phase );
	}

	/**
	 * The 15-minute queued stall window only shortens the deadline for a
	 * task still in the queued phase - a task that has actually started work
	 * keeps its full wall-clock window.
	 */
	public function test_enforce_wall_clock_deadline_does_not_shorten_started_task() {
		global $wpdb;
		$spine   = MainWP_Child_Backup_Task::instance();
		$task_id = $spine->create_task( 'full' );

		$wpdb->query( $wpdb->prepare( 'UPDATE ' . $spine->get_table_name() . " SET phase = 'dumping_db', started_at = DATE_SUB(NOW(), INTERVAL 901 SECOND) WHERE task_id = %s", $task_id ) ); // phpcs:ignore -- test fixture.

		$this->assertFalse( $spine->enforce_wall_clock_deadline( $task_id ), 'A started task must not be reclaimed by the queued stall window.' );
		$this->assertSame( 'dumping_db', $spine->get_task( $task_id )->phase );
	}

	/**
	 * A backup forced terminal by the watchdog (resume cap) sweeps its spilled
	 * walk-run files. The runner's own terminal branch cleans them, but a task
	 * killed by the watchdog never runs the runner, so the watchdog must sweep.
	 */
	public function test_watchdog_terminal_sweeps_walk_runs() {
		global $wpdb;
		$spine   = MainWP_Child_Backup_Task::instance();
		$task_id = $spine->create_task( 'full' );

		$dirs       = \MainWP\Child\MainWP_Helper::get_mainwp_dir( 'backup' );
		$backup_dir = rtrim( $dirs[0], '/' ) . '/';
		$prefix     = \MainWP\Child\MainWP_Child_Backup_File_Walker::RUN_PREFIX;
		file_put_contents( $backup_dir . $prefix . '0', 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
		file_put_contents( $backup_dir . $prefix . '1', 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.

		$wpdb->query( $wpdb->prepare( 'UPDATE ' . $spine->get_table_name() . ' SET last_progress_at = DATE_SUB(NOW(), INTERVAL 600 SECOND), resume_count = max_resumes WHERE task_id = %s', $task_id ) ); // phpcs:ignore -- test fixture.

		MainWP_Child_Backup_Watchdog::instance()->check_tasks( 0 );

		$task = $spine->get_task( $task_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );
		$this->assertCount( 0, (array) glob( $backup_dir . $prefix . '*' ), 'Watchdog terminal must sweep walk-run files' );
	}

	/**
	 * A backup forced terminal by the wall-clock enforcer sweeps its spilled
	 * walk-run files too, matching the runner's terminal disk hygiene.
	 */
	public function test_wall_clock_terminal_sweeps_walk_runs() {
		global $wpdb;
		$spine   = MainWP_Child_Backup_Task::instance();
		$task_id = $spine->create_task( 'full' );

		$dirs       = \MainWP\Child\MainWP_Helper::get_mainwp_dir( 'backup' );
		$backup_dir = rtrim( $dirs[0], '/' ) . '/';
		$prefix     = \MainWP\Child\MainWP_Child_Backup_File_Walker::RUN_PREFIX;
		file_put_contents( $backup_dir . $prefix . '0', 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
		file_put_contents( $backup_dir . $prefix . '1', 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.

		$wpdb->query( $wpdb->prepare( 'UPDATE ' . $spine->get_table_name() . ' SET max_wall_clock_at = DATE_SUB(NOW(), INTERVAL 60 SECOND) WHERE task_id = %s', $task_id ) ); // phpcs:ignore -- test fixture.

		$this->assertTrue( $spine->enforce_wall_clock_deadline( $task_id ) );
		$task = $spine->get_task( $task_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );
		$this->assertCount( 0, (array) glob( $backup_dir . $prefix . '*' ), 'Wall-clock terminal must sweep walk-run files' );
	}

	/**
	 * A progress refresh slides an expired horizon forward, so a large backup
	 * that is actively advancing past its original wall clock is not failed -
	 * only a task that stops checkpointing (truly stuck) still expires.
	 */
	public function test_refresh_wall_clock_revives_an_expired_horizon() {
		global $wpdb;
		$spine = MainWP_Child_Backup_Task::instance();

		// Without a refresh, an expired horizon is failed (the existing guard).
		$stuck = $spine->create_task( 'full' );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . $spine->get_table_name() . ' SET max_wall_clock_at = DATE_SUB(NOW(), INTERVAL 120 SECOND) WHERE task_id = %s', $stuck ) ); // phpcs:ignore -- test fixture.
		$this->assertTrue( $spine->enforce_wall_clock_deadline( $stuck ) );

		// A progressing task whose original horizon has passed survives once a
		// checkpoint refreshes the wall clock.
		$advancing = $spine->create_task( 'full' );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . $spine->get_table_name() . ' SET max_wall_clock_at = DATE_SUB(NOW(), INTERVAL 120 SECOND) WHERE task_id = %s', $advancing ) ); // phpcs:ignore -- test fixture.
		$this->assertTrue( $spine->refresh_wall_clock( $advancing, MainWP_Child_Backup_Task::WALL_CLOCK_WINDOW_SECONDS ) );
		$this->assertFalse( $spine->enforce_wall_clock_deadline( $advancing ), 'An advancing backup must survive past its original horizon.' );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_QUEUED, $spine->get_task( $advancing )->phase );
	}

	/**
	 * Refresh is a monotonic floor: it never shortens a horizon that is already
	 * further out than NOW + window (e.g. a larger upfront grant).
	 */
	public function test_refresh_wall_clock_never_shortens() {
		global $wpdb;
		$spine   = MainWP_Child_Backup_Task::instance();
		$task_id = $spine->create_task( 'full' );

		$wpdb->query( $wpdb->prepare( 'UPDATE ' . $spine->get_table_name() . ' SET max_wall_clock_at = DATE_ADD(NOW(), INTERVAL 999999 SECOND) WHERE task_id = %s', $task_id ) ); // phpcs:ignore -- test fixture.
		$before = $spine->get_task( $task_id )->max_wall_clock_at;

		$spine->refresh_wall_clock( $task_id, 60 );
		$this->assertSame( $before, $spine->get_task( $task_id )->max_wall_clock_at, 'A small refresh must not shorten a far-future horizon.' );
	}

	/**
	 * A non-positive window is a no-op, never zeroing or shortening the horizon.
	 */
	public function test_refresh_wall_clock_ignores_nonpositive_window() {
		$spine   = MainWP_Child_Backup_Task::instance();
		$task_id = $spine->create_task( 'full' );
		$this->assertFalse( $spine->refresh_wall_clock( $task_id, 0 ) );
		$this->assertFalse( $spine->refresh_wall_clock( $task_id, -5 ) );
	}

	/**
	 * A stalled task at its resume cap is failed by the watchdog with a
	 * reason and code, so the Dashboard can explain why it gave up instead
	 * of showing a bare "failed".
	 */
	public function test_watchdog_resume_cap_records_reason() {
		global $wpdb;
		$spine   = MainWP_Child_Backup_Task::instance();
		$task_id = $spine->create_task( 'full' );

		// At the resume cap and stalled well past any threshold.
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . $spine->get_table_name() . ' SET resume_count = max_resumes, last_progress_at = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE task_id = %s', $task_id ) ); // phpcs:ignore -- test fixture.

		\MainWP\Child\MainWP_Child_Backup_Watchdog::instance()->check_tasks( 1 );

		$task = $spine->get_task( $task_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );

		$payload = $spine->status_payload( $task );
		$this->assertSame( \MainWP\Child\MainWP_Backup_Error::ERR_STALLED, $payload['error_code'] );
		$this->assertNotEmpty( $payload['error'] );
	}

	/**
	 * A wall-clock forced failure deletes the partial artifacts the task's
	 * own cursor names, plus its sidecar, while still recording the error.
	 * Files the cursor does not name survive.
	 */
	public function test_wall_clock_failure_deletes_partial_artifacts() {
		global $wpdb;
		$spine   = MainWP_Child_Backup_Task::instance();
		$dirs    = \MainWP\Child\MainWP_Helper::get_mainwp_dir( 'backup' );
		$dir     = rtrim( $dirs[0], '/' );
		$task_id = $spine->create_task( 'full' );

		$partial  = $dir . '/backup-wallclock-partial.tar';
		$dump     = $dir . '/dbBackup-wallclock-part1.sql';
		$survivor = $dir . '/files.list';
		$sidecar  = $dir . '/task_substate-' . $task_id . '-v1.json';
		foreach ( array( $partial, $dump, $survivor, $sidecar ) as $file ) {
			file_put_contents( $file, 'x' ); // phpcs:ignore -- test fixture.
		}

		try {
			$spine->checkpoint(
				$task_id,
				0,
				array(
					'phase'     => MainWP_Child_Backup_Task::PHASE_ARCHIVING_FILES,
					'sub_state' => array(
						'archive_file' => 'backup-wallclock-partial.tar',
						'db_files'     => array( $dump ),
					),
				)
			);
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . $spine->get_table_name() . ' SET max_wall_clock_at = DATE_SUB(NOW(), INTERVAL 60 SECOND) WHERE task_id = %s', $task_id ) ); // phpcs:ignore -- test fixture.

			$this->assertTrue( $spine->enforce_wall_clock_deadline( $task_id ) );

			$task = $spine->get_task( $task_id );
			$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );

			$payload = $spine->status_payload( $task );
			$this->assertSame( \MainWP\Child\MainWP_Backup_Error::ERR_WALL_CLOCK, $payload['error_code'], 'The recorded reason must survive the cleanup' );

			$this->assertFileDoesNotExist( $partial );
			$this->assertFileDoesNotExist( $dump );
			$this->assertFileDoesNotExist( $sidecar, 'The wall-clock path must sweep the sidecar like every other terminal transition' );
			$this->assertFileExists( $survivor, 'The promoted chain base must survive a forced failure' );
		} finally {
			foreach ( array( $partial, $dump, $survivor, $sidecar ) as $file ) {
				if ( is_file( $file ) ) {
					unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test cleanup.
				}
			}
		}
	}

	/**
	 * The watchdog's resume-cap forced failure deletes the partial artifacts
	 * the task's own cursor names and its sidecar, like every other path to
	 * a failed backup.
	 */
	public function test_watchdog_cap_failure_deletes_partial_artifacts() {
		global $wpdb;
		$spine   = MainWP_Child_Backup_Task::instance();
		$dirs    = \MainWP\Child\MainWP_Helper::get_mainwp_dir( 'backup' );
		$dir     = rtrim( $dirs[0], '/' );
		$task_id = $spine->create_task( 'full' );

		$partial  = $dir . '/backup-capped-partial.tar';
		$dump     = $dir . '/dbBackup-capped-part1.sql';
		$survivor = $dir . '/files.list';
		$sidecar  = $dir . '/task_substate-' . $task_id . '-v1.json';
		foreach ( array( $partial, $dump, $survivor, $sidecar ) as $file ) {
			file_put_contents( $file, 'x' ); // phpcs:ignore -- test fixture.
		}

		try {
			$spine->checkpoint(
				$task_id,
				0,
				array(
					'phase'     => MainWP_Child_Backup_Task::PHASE_DUMPING_DB,
					'sub_state' => array(
						'archive_file' => 'backup-capped-partial.tar',
						'db_files'     => array( $dump ),
					),
				)
			);
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . $spine->get_table_name() . ' SET resume_count = max_resumes, last_progress_at = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE task_id = %s', $task_id ) ); // phpcs:ignore -- test fixture.

			\MainWP\Child\MainWP_Child_Backup_Watchdog::instance()->check_tasks( 1 );

			$task = $spine->get_task( $task_id );
			$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );

			$this->assertFileDoesNotExist( $partial );
			$this->assertFileDoesNotExist( $dump );
			$this->assertFileDoesNotExist( $sidecar );
			$this->assertFileExists( $survivor );
		} finally {
			foreach ( array( $partial, $dump, $survivor, $sidecar ) as $file ) {
				if ( is_file( $file ) ) {
					unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test cleanup.
				}
			}
		}
	}
}
