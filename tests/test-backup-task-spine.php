<?php
/**
 * Class BackupTaskSpineTest
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Child_Backup_Task;
use MainWP\Child\MainWP_Child_Backup_Watchdog;

/**
 * Tests for the minimal child backup task spine (Phase 1G).
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

		// A healthy task is untouched.
		$task_id2 = $spine->create_task( 'full' );
		$this->assertFalse( $spine->enforce_wall_clock_deadline( $task_id2 ) );
		$task2 = $spine->get_task( $task_id2 );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_QUEUED, $task2->phase );
	}
}
