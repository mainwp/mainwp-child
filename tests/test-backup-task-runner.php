<?php
/**
 * Class BackupTaskRunnerTest
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Backup_Budget_Exception;
use MainWP\Child\MainWP_Child_Backup_Task;
use MainWP\Child\MainWP_Child_Backup_Task_Runner;
use MainWP\Child\MainWP_Exception;

/**
 * Runner subclass with the engine seams stubbed and recorded.
 *
 * The spine table, lease, and CAS logic run for real; only the heavy
 * dump/archive engine invocations are replaced.
 */
class Testable_Task_Runner extends MainWP_Child_Backup_Task_Runner {

	/**
	 * Recorded seam calls, in order.
	 *
	 * @var array
	 */
	public $calls = array();

	/**
	 * Scratch dir used as the backup dir.
	 *
	 * @var string
	 */
	public $test_backup_dir;

	/**
	 * Stub behaviors keyed by seam name. Each is a callable or a value.
	 *
	 * @var array
	 */
	public $stubs = array();

	/**
	 * Resolve a stub result, recording the call.
	 *
	 * @param string $seam Seam name.
	 * @param array  $args Call arguments.
	 *
	 * @return mixed Stub result.
	 * @throws \Exception When the stub is an exception.
	 */
	protected function stub( $seam, $args ) {
		$this->calls[] = array_merge( array( $seam ), $args );
		if ( ! isset( $this->stubs[ $seam ] ) ) {
			return null;
		}
		$result = $this->stubs[ $seam ];
		if ( $result instanceof \Exception ) {
			throw $result;
		}
		if ( is_callable( $result ) ) {
			return call_user_func_array( $result, $args );
		}
		return $result;
	}

	/**
	 * Names of seam calls only.
	 *
	 * @return string[] Seam names in call order.
	 */
	public function seam_names() {
		return array_map(
			function ( $call ) {
				return $call[0];
			},
			$this->calls
		);
	}

	// ---- seams ----------------------------------------------------------

	/**
	 * Scratch dir override.
	 *
	 * @return string Backup dir.
	 */
	protected function backup_dir() {
		return $this->test_backup_dir;
	}

	/**
	 * Tier-1 availability stub.
	 *
	 * @return bool Availability.
	 */
	protected function tier1_available() {
		return (bool) $this->stub( 'tier1_available', array() );
	}

	/**
	 * Tier-1 dump stub.
	 *
	 * @param string $prefix File prefix.
	 * @param int    $budget Seconds.
	 *
	 * @return array Dump file list.
	 */
	protected function dump_tier1( $prefix, $budget ) {
		return $this->stub( 'dump_tier1', array( $prefix, $budget ) );
	}

	/**
	 * Tier-2 dump slice stub.
	 *
	 * @param string     $prefix File prefix.
	 * @param array|null $cursor Resume cursor.
	 * @param int        $budget Seconds.
	 *
	 * @return array Slice result.
	 */
	protected function dump_tier2( $prefix, $cursor, $budget ) {
		return $this->stub( 'dump_tier2', array( $prefix, $cursor, $budget ) );
	}

	/**
	 * DB artifact archiving stub.
	 *
	 * @param array  $db_files Dump files.
	 * @param string $prefix   File prefix.
	 * @param string $ext      Archive extension.
	 *
	 * @return string Artifact path.
	 */
	protected function archive_db_artifact( $db_files, $prefix, $ext ) {
		return $this->stub( 'archive_db_artifact', array( $db_files, $prefix, $ext ) );
	}

	/**
	 * File archiver stub.
	 *
	 * @param array  $params       Backup params.
	 * @param string $archive_file Archive file name.
	 * @param bool   $append       Resume an existing archive.
	 * @param int    $deadline     Slice deadline.
	 * @param array  $db_files     Pre-produced dump files.
	 *
	 * @return array Result with file/filesize.
	 */
	protected function run_file_archiver( $params, $archive_file, $append, $deadline, $db_files ) {
		return $this->stub( 'run_file_archiver', array( $params, $archive_file, $append, $deadline, $db_files ) );
	}

	/**
	 * Upload stub work.
	 *
	 * @param array $sub_state Cursor.
	 */
	protected function perform_upload( $sub_state ) {
		$this->stub( 'perform_upload', array( $sub_state ) );
	}

	/**
	 * Submit stub work.
	 *
	 * @param array $sub_state Cursor.
	 */
	protected function perform_submit( $sub_state ) {
		$this->stub( 'perform_submit', array( $sub_state ) );
	}
}

/**
 * Tests for the resumable task runner.
 */
class BackupTaskRunnerTest extends WP_UnitTestCase {

	/**
	 * Runner under test.
	 *
	 * @var Testable_Task_Runner
	 */
	protected $runner;

	/**
	 * Scratch backup dir.
	 *
	 * @var string
	 */
	protected $backup_dir;

	/**
	 * Install the table, scratch dir, and a runner with happy-path stubs.
	 */
	public function set_up() {
		parent::set_up();
		MainWP_Child_Backup_Task::instance()->install();

		$this->backup_dir = sys_get_temp_dir() . '/mainwp-runner-test-' . uniqid();
		mkdir( $this->backup_dir );

		$artifact = $this->backup_dir . '/backup-artifact.tar';
		file_put_contents( $artifact, str_repeat( 'a', 2048 ) ); // phpcs:ignore -- test fixture.

		$this->runner                  = new Testable_Task_Runner();
		$this->runner->test_backup_dir = $this->backup_dir;
		$this->runner->stubs           = array(
			'tier1_available'     => true,
			'dump_tier1'          => array( $this->backup_dir . '/dbBackup-table1.sql.php' ),
			'archive_db_artifact' => $artifact,
			'run_file_archiver'   => array(
				'file'     => 'backup-artifact.tar',
				'filesize' => 2048,
			),
		);
	}

	/**
	 * Drop spine rows and the scratch dir.
	 */
	public function tear_down() {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . MainWP_Child_Backup_Task::instance()->get_table_name() ); // phpcs:ignore -- test cleanup.
		foreach ( (array) glob( $this->backup_dir . '/*' ) as $file ) {
			unlink( $file );
		}
		if ( is_dir( $this->backup_dir ) ) {
			rmdir( $this->backup_dir );
		}
		parent::tear_down();
	}

	/**
	 * Create a task with standard params.
	 *
	 * @param string $type Backup type.
	 *
	 * @return string Task id.
	 */
	protected function create_task( $type = 'db' ) {
		return MainWP_Child_Backup_Task::instance()->create_task(
			$type,
			array(
				'params' => array(
					'ext'                => 'tar',
					'file'               => '',
					'excludes'           => array(),
					'excludezip'         => false,
					'excludenonwp'       => false,
					'file_descriptors'   => 0,
					'loadFilesBeforeZip' => true,
				),
			)
		);
	}

	/**
	 * Advance helper.
	 *
	 * @param string $task_id Task id.
	 * @param string $owner   Lease owner.
	 * @param bool   $held    Lease pre-held.
	 *
	 * @return object Fresh task row.
	 */
	protected function advance( $task_id, $owner = 'dashboard', $held = false ) {
		$task = MainWP_Child_Backup_Task::instance()->get_task( $task_id );
		return $this->runner->advance_one_slice( $task, $owner, $held );
	}

	/**
	 * A db backup runs dump, db-artifact, upload and submit stubs in one
	 * slice and completes with the artifact recorded - never touching the
	 * file archiver.
	 */
	public function test_db_kind_completes_with_artifact() {
		$task_id = $this->create_task( 'db' );
		$task    = $this->advance( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertContains( 'dump_tier1', $this->runner->seam_names() );
		$this->assertContains( 'archive_db_artifact', $this->runner->seam_names() );
		$this->assertNotContains( 'run_file_archiver', $this->runner->seam_names() );

		$sub_state = MainWP_Child_Backup_Task::instance()->read_sub_state( $task, $this->backup_dir );
		$this->assertSame( 'backup-artifact.tar', $sub_state['artifact']['file'] );
		$this->assertSame( 2048, $sub_state['artifact']['size'] );
		$this->assertNull( $task->lease_owner, 'Lease must be released after the slice' );
	}

	/**
	 * A files backup never dumps the database.
	 */
	public function test_files_kind_skips_dump() {
		$task_id = $this->create_task( 'files' );
		$task    = $this->advance( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertNotContains( 'tier1_available', $this->runner->seam_names() );
		$this->assertNotContains( 'dump_tier1', $this->runner->seam_names() );
		$this->assertContains( 'run_file_archiver', $this->runner->seam_names() );
	}

	/**
	 * A full backup dumps once and hands the produced dump files to the
	 * file archiver - the archive phase must consume them, not re-dump.
	 */
	public function test_full_kind_hands_dump_files_to_archiver() {
		$task_id = $this->create_task( 'full' );
		$task    = $this->advance( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );

		$archiver_call = null;
		foreach ( $this->runner->calls as $call ) {
			if ( 'run_file_archiver' === $call[0] ) {
				$archiver_call = $call;
			}
		}
		$this->assertNotNull( $archiver_call );
		$this->assertSame(
			array(
				$this->backup_dir . '/dbBackup-table1.sql.php',
				$this->backup_dir . '/dbBackup.sql', // The legacy completion marker travels with the dump set.
			),
			$archiver_call[5],
			'The archiver must receive the dump files produced by the dump phase'
		);

		$dump_calls = array_filter(
			$this->runner->seam_names(),
			function ( $name ) {
				return 'dump_tier1' === $name || 'dump_tier2' === $name;
			}
		);
		$this->assertCount( 1, $dump_calls, 'The database must be dumped exactly once' );
	}

	/**
	 * A Tier-1 budget overrun downgrades to Tier-2: the slice yields with
	 * the phase unchanged, and the next slice dumps via Tier-2 without
	 * retrying Tier-1.
	 */
	public function test_tier1_budget_overrun_downgrades_to_tier2() {
		$this->runner->stubs['dump_tier1'] = new MainWP_Backup_Budget_Exception( 'over budget', null, '' );

		$task_id = $this->create_task( 'db' );
		$task    = $this->advance( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_DUMPING_DB, $task->phase, 'The slice yields after the downgrade' );
		$this->assertNull( $task->lease_owner );

		$this->runner->calls               = array();
		$this->runner->stubs['dump_tier2'] = array(
			'done'   => true,
			'cursor' => array(),
			'files'  => array( $this->backup_dir . '/dbBackup-t2.sql.php' ),
		);

		$task = $this->advance( $task_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertContains( 'dump_tier2', $this->runner->seam_names() );
		$this->assertNotContains( 'dump_tier1', $this->runner->seam_names(), 'Tier-1 must not be retried after a budget overrun' );
	}

	/**
	 * A partial Tier-2 slice persists its cursor and the next slice resumes
	 * from it.
	 */
	public function test_tier2_cursor_persists_across_slices() {
		$this->runner->stubs['tier1_available'] = false;
		$this->runner->stubs['dump_tier2']      = array(
			'done'   => false,
			'cursor' => array(
				'index'   => 3,
				'last_id' => 500,
			),
		);

		$task_id = $this->create_task( 'db' );
		$task    = $this->advance( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_DUMPING_DB, $task->phase );
		$sub_state = MainWP_Child_Backup_Task::instance()->read_sub_state( $task, $this->backup_dir );
		$this->assertSame( 3, $sub_state['db_cursor']['index'] );

		$this->runner->calls               = array();
		$this->runner->stubs['dump_tier2'] = function ( $prefix, $cursor, $budget ) {
			return array(
				'done'    => true,
				'cursor'  => $cursor,
				'files'   => array( 'f.sql.php' ),
				'resumed' => $cursor,
			);
		};

		$this->advance( $task_id );
		$tier2_call = null;
		foreach ( $this->runner->calls as $call ) {
			if ( 'dump_tier2' === $call[0] ) {
				$tier2_call = $call;
				break;
			}
		}
		$this->assertSame( 500, $tier2_call[2]['last_id'], 'The next slice must resume from the persisted cursor' );
	}

	/**
	 * An archiver budget bail keeps the phase and the archive file name, and
	 * the next slice reopens the same file in append mode.
	 */
	public function test_archiver_budget_bail_resumes_with_append() {
		$this->runner->stubs['run_file_archiver'] = new MainWP_Backup_Budget_Exception( 'over budget', null, '' );

		$task_id = $this->create_task( 'files' );
		$task    = $this->advance( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_ARCHIVING_FILES, $task->phase );
		$sub_state = MainWP_Child_Backup_Task::instance()->read_sub_state( $task, $this->backup_dir );
		$this->assertNotEmpty( $sub_state['archive_file'] );
		$first_name = $sub_state['archive_file'];

		$first_call = null;
		foreach ( $this->runner->calls as $call ) {
			if ( 'run_file_archiver' === $call[0] ) {
				$first_call = $call;
			}
		}
		$this->assertFalse( $first_call[3], 'The first slice must not append' );

		$this->runner->calls                      = array();
		$this->runner->stubs['run_file_archiver'] = array(
			'file'     => $first_name,
			'filesize' => 2048,
		);

		$task = $this->advance( $task_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );

		$second_call = null;
		foreach ( $this->runner->calls as $call ) {
			if ( 'run_file_archiver' === $call[0] ) {
				$second_call = $call;
			}
		}
		$this->assertSame( $first_name, $second_call[2], 'The resumed slice must reuse the archive file name' );
		$this->assertTrue( $second_call[3], 'The resumed slice must open the archive in append mode' );
	}

	/**
	 * With the lease pre-held by the watchdog the runner skips acquisition,
	 * advances, and releases the lease with the final version so the task
	 * is immediately available again.
	 */
	public function test_lease_pre_held_skips_acquire() {
		$spine   = MainWP_Child_Backup_Task::instance();
		$task_id = $this->create_task( 'db' );
		$task    = $spine->get_task( $task_id );

		$this->assertTrue( $spine->acquire_lease( $task_id, 'watchdog', (int) $task->version, 120 ) );

		$task = $this->advance( $task_id, 'watchdog', true );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertNull( $task->lease_owner, 'The runner releases with the final version it owns' );
	}

	/**
	 * A live lease held by someone else means no work happens at all.
	 */
	public function test_live_foreign_lease_blocks_slice() {
		$spine   = MainWP_Child_Backup_Task::instance();
		$task_id = $this->create_task( 'db' );
		$task    = $spine->get_task( $task_id );

		$spine->acquire_lease( $task_id, 'watchdog', (int) $task->version, 300 );

		$task = $this->advance( $task_id, 'dashboard', false );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_QUEUED, $task->phase );
		$this->assertSame( array(), $this->runner->calls, 'No engine work without the lease' );
		$this->assertSame( 'watchdog', $task->lease_owner );
	}

	/**
	 * An engine failure marks the task failed (terminal), records the error,
	 * releases the lease, and removes any sidecar.
	 */
	public function test_engine_exception_marks_failed() {
		$this->runner->stubs['dump_tier1'] = new MainWP_Exception( 'dump exploded', null, 'mainwp_backup_db_query_failed' );

		$task_id = $this->create_task( 'db' );
		$task    = $this->advance( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );
		$this->assertNull( $task->lease_owner );

		$sub_state = MainWP_Child_Backup_Task::instance()->read_sub_state( $task, $this->backup_dir );
		$this->assertSame( 'dump exploded', $sub_state['error']['message'] );
		$this->assertSame( 'mainwp_backup_db_query_failed', $sub_state['error']['code'] );
		$this->assertFileDoesNotExist( $this->backup_dir . '/task_substate-' . $task_id . '.json' );
	}

	/**
	 * Upload and submit are stubs with a late-phase safety guard: once the
	 * acceptance marker is set, the work is never re-issued.
	 */
	public function test_upload_submit_guard_prevents_reissue() {
		$spine   = MainWP_Child_Backup_Task::instance();
		$task_id = $this->create_task( 'db' );

		// Walk to completion once - both stubs perform work.
		$this->advance( $task_id );
		$this->assertContains( 'perform_upload', $this->runner->seam_names() );
		$this->assertContains( 'perform_submit', $this->runner->seam_names() );

		// Craft a task already in uploading with the upload accepted.
		$task_id2 = $this->create_task( 'db' );
		$task2    = $spine->get_task( $task_id2 );
		$spine->checkpoint(
			$task_id2,
			(int) $task2->version,
			array(
				'phase'     => MainWP_Child_Backup_Task::PHASE_UPLOADING,
				'sub_state' => array(
					'params'          => array( 'ext' => 'tar' ),
					'artifact'        => array(
						'file' => 'x.tar',
						'size' => 1,
					),
					'upload_accepted' => true,
				),
			)
		);

		$this->runner->calls = array();
		$task2               = $this->advance( $task_id2 );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task2->phase );
		$this->assertNotContains( 'perform_upload', $this->runner->seam_names(), 'Accepted upload work must never be re-issued' );
		$this->assertContains( 'perform_submit', $this->runner->seam_names() );
	}

	/**
	 * A concurrent write between checkpoints makes the CAS fail and the
	 * runner abandons the slice without corrupting state.
	 */
	public function test_stale_version_abandons_slice() {
		$spine   = MainWP_Child_Backup_Task::instance();
		$task_id = $this->create_task( 'db' );

		// The dump stub simulates an out-of-band writer bumping the version
		// mid-slice (e.g. a racing process that ignored the lease).
		$this->runner->stubs['dump_tier1'] = function () use ( $spine, $task_id ) {
			global $wpdb;
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . $spine->get_table_name() . ' SET version = version + 10 WHERE task_id = %s', $task_id ) ); // phpcs:ignore -- test fixture.
			return array( 'f.sql.php' );
		};

		$task = $this->advance( $task_id );

		$this->assertNotSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase, 'A stale slice must not complete the task' );
		$this->assertNotContains( 'archive_db_artifact', $this->runner->seam_names(), 'Work after a failed CAS must not run' );
	}

	/**
	 * register_hooks() subscribes the runner's resume handler - the wiring
	 * the full plugin init performs for cron/MainWP request contexts (the
	 * test bootstrap takes the frontend init path, where the watchdog never
	 * fires).
	 */
	public function test_register_hooks_subscribes_resume_handler() {
		$runner = MainWP_Child_Backup_Task_Runner::instance();
		$runner->register_hooks();
		try {
			$this->assertSame( 10, has_action( 'mainwp_child_backup_task_resume', array( $runner, 'handle_watchdog_resume' ) ) );
		} finally {
			remove_action( 'mainwp_child_backup_task_resume', array( $runner, 'handle_watchdog_resume' ) );
		}
	}

	/**
	 * A watchdog firing on a stalled task advances it one slice through the
	 * pre-held lease: resume bookkeeping recorded, work done, lease free
	 * afterwards.
	 */
	public function test_watchdog_resume_advances_stalled_task() {
		global $wpdb;
		$spine   = MainWP_Child_Backup_Task::instance();
		$task_id = $this->create_task( 'db' );

		$wpdb->query( $wpdb->prepare( 'UPDATE ' . $spine->get_table_name() . ' SET last_progress_at = DATE_SUB(NOW(), INTERVAL 600 SECOND) WHERE task_id = %s', $task_id ) ); // phpcs:ignore -- test fixture.

		// Route the resume through the stubbed runner, not the booted singleton.
		remove_all_actions( 'mainwp_child_backup_task_resume' );
		$this->runner->register_hooks();

		try {
			\MainWP\Child\MainWP_Child_Backup_Watchdog::instance()->check_tasks( 0 );
		} finally {
			remove_all_actions( 'mainwp_child_backup_task_resume' );
		}

		$task = $spine->get_task( $task_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase, 'The watchdog resume must advance the slice' );
		$this->assertSame( 1, (int) $task->resume_count );
		$this->assertNull( $task->lease_owner );
		$this->assertContains( 'dump_tier1', $this->runner->seam_names() );
	}

	/**
	 * The full-backup engine consumes prepared dump files instead of
	 * dumping again - the dump-once seam.
	 */
	public function test_engine_prefers_prepared_dump_files() {
		$backup   = \MainWP\Child\MainWP_Backup::get();
		$prepared = array( $this->backup_dir . '/dbBackup-ready.sql.php' );

		$backup->set_prepared_db_files( $prepared );
		try {
			$this->assertSame( $prepared, $backup->get_or_create_db_dump_files( $this->backup_dir . '/should-not-dump' ) );
		} finally {
			$backup->set_prepared_db_files( null );
		}
	}

	/**
	 * A budget interruption keeps the partial archive (the resume point);
	 * any other failure discards it.
	 */
	public function test_partial_archive_discard_rule() {
		$backup = \MainWP\Child\MainWP_Backup::get();
		$method = new ReflectionMethod( \MainWP\Child\MainWP_Backup::class, 'should_discard_partial_on' );
		$method->setAccessible( true );

		$this->assertFalse( $method->invoke( $backup, new MainWP_Backup_Budget_Exception( 'budget', null, '' ) ), 'A budget bail must keep the partial archive for the append resume' );
		$this->assertTrue( $method->invoke( $backup, new MainWP_Exception( 'real failure', null, '' ) ) );
		$this->assertTrue( $method->invoke( $backup, new \Exception( 'unknown failure' ) ) );
	}

	/**
	 * Completion removes the sidecar file.
	 */
	public function test_completed_terminal_cleans_sidecar() {
		$spine   = MainWP_Child_Backup_Task::instance();
		$task_id = $this->create_task( 'db' );

		// Force a sidecar by spilling an oversized cursor first.
		$big        = array(
			'params' => array( 'ext' => 'tar' ),
			'pad'    => array_fill( 0, 4000, str_repeat( 'x', 20 ) ),
		);
		$task       = $spine->get_task( $task_id );
		$serialized = $spine->write_sub_state( $task_id, $big, $this->backup_dir );
		$spine->checkpoint( $task_id, (int) $task->version, array( 'sub_state' => $serialized ) );
		$this->assertFileExists( $this->backup_dir . '/task_substate-' . $task_id . '.json' );

		$task = $this->advance( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertFileDoesNotExist( $this->backup_dir . '/task_substate-' . $task_id . '.json' );
	}

	/**
	 * A tier-2 yield records table counters in the progress snapshot so the
	 * Dashboard can show how far the dump has come.
	 */
	public function test_tier2_yield_records_table_progress() {
		$this->runner->stubs['tier1_available'] = false;
		$this->runner->stubs['dump_tier2']      = array(
			'done'   => false,
			'cursor' => array(
				'tables' => array( 'wp_posts', 'wp_options', 'wp_users' ),
				'index'  => 1,
			),
		);

		$task_id = $this->create_task( 'db' );
		$task    = $this->advance( $task_id );

		$sub_state = MainWP_Child_Backup_Task::instance()->read_sub_state( $task, $this->backup_dir );

		$this->assertSame( 3, $sub_state['progress']['files_total'] );
		$this->assertSame( 1, $sub_state['progress']['files_processed'] );
		$this->assertSame( 'wp_options', $sub_state['progress']['current_file'] );
	}

	/**
	 * An archiver budget bail surfaces the growing archive size on the next
	 * slice's checkpoint.
	 */
	public function test_archiver_yield_records_archive_bytes() {
		$backup_dir = $this->backup_dir;

		$this->runner->stubs['run_file_archiver'] = function ( $params, $archive_file, $append, $deadline, $db_files ) use ( $backup_dir ) {
			file_put_contents( $backup_dir . '/' . $archive_file, str_repeat( 'x', 512 ) ); // phpcs:ignore -- test fixture partial archive.
			throw new MainWP_Backup_Budget_Exception( 'over budget', null, '' );
		};

		$task_id = $this->create_task( 'files' );
		$this->advance( $task_id );
		// The budget bail itself does not checkpoint; the next slice's
		// pre-archiver checkpoint captures the partial size.
		$task = $this->advance( $task_id );

		$sub_state = MainWP_Child_Backup_Task::instance()->read_sub_state( $task, $this->backup_dir );

		$this->assertSame( $sub_state['archive_file'], $sub_state['progress']['current_file'] );
		$this->assertSame( 512, $sub_state['progress']['bytes_done'] );
	}

	/**
	 * The status payload exposes the progress snapshot as an optional
	 * top-level key for the Dashboard.
	 */
	public function test_status_payload_exposes_progress() {
		$this->runner->stubs['tier1_available'] = false;
		$this->runner->stubs['dump_tier2']      = array(
			'done'   => false,
			'cursor' => array(
				'tables' => array( 'wp_posts', 'wp_options' ),
				'index'  => 0,
			),
		);

		$task_id = $this->create_task( 'db' );
		$task    = $this->advance( $task_id );

		$payload = MainWP_Child_Backup_Task::instance()->status_payload( $task, $this->backup_dir );

		$this->assertArrayHasKey( 'progress', $payload );
		$this->assertSame( 2, $payload['progress']['files_total'] );
		$this->assertSame( 0, $payload['progress']['files_processed'] );
	}

	/**
	 * A payload without recorded progress omits the key rather than sending
	 * an empty structure.
	 */
	public function test_status_payload_omits_progress_when_absent() {
		$task_id = $this->create_task( 'db' );
		$spine   = MainWP_Child_Backup_Task::instance();
		$task    = $spine->get_task( $task_id );

		$payload = $spine->status_payload( $task, $this->backup_dir );

		$this->assertArrayNotHasKey( 'progress', $payload );
	}
}
