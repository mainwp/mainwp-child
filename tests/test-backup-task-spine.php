<?php
/**
 * Class BackupTaskSpineTest
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Child_Backup_Task;
use MainWP\Child\MainWP_Child_Backup_Watchdog;

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
		MainWP_Child_Backup_Task::instance()->install();
	}

	/**
	 * Drop spine rows between tests.
	 */
	public function tear_down() {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . MainWP_Child_Backup_Task::instance()->get_table_name() ); // phpcs:ignore -- test cleanup.
		parent::tear_down();
	}

	/**
	 * The dedicated dbDelta table exists and is queryable after install.
	 *
	 * The WP test framework creates plugin tables as TEMPORARY tables, which
	 * SHOW TABLES does not list - assert queryability instead.
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
}
