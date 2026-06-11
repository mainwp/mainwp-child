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
		$this->assertContains( MainWP_Child_Backup_Task::PHASE_REPLAYING_DB, $chain );
		$this->assertContains( MainWP_Child_Backup_Task::PHASE_SWAPPING_DB, $chain );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, end( $chain ) );
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
