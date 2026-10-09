<?php
/**
 * Class BackupTaskPruneTest
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Child_Backup_Task;
use MainWP\Child\MainWP_Backup_Db_Restore_Engine;

/**
 * Records the restore ids whose aside tables a prune asked to drop, without
 * touching a database.
 */
class Stub_Restore_Engine extends MainWP_Backup_Db_Restore_Engine {

	/**
	 * Restore id sets passed to prune_aside_tables, newest last.
	 *
	 * @var array<int,string[]>
	 */
	public $pruned_id_sets = array();

	/**
	 * Record the prune instead of running it.
	 *
	 * @param string[] $restore_ids Restore ids.
	 */
	public function prune_aside_tables( array $restore_ids ) {
		$this->pruned_id_sets[] = $restore_ids;
	}
}

/**
 * Tests for the dashboard-driven prune action.
 *
 * Retention on the dashboard prunes old backups; the child side cleans
 * up what those runs left behind: the terminal task row, the artifact
 * files and sidecar in the backup directory, and stale legacy markers
 * (.pid/.done files, old db dump fragments) that no task row tracks.
 */
class BackupTaskPruneTest extends WP_UnitTestCase {

	/**
	 * Scratch backup dir for the test.
	 *
	 * @var string
	 */
	private $backup_dir;

	/**
	 * Install the task table and a scratch dir.
	 */
	public function set_up() {
		parent::set_up();
		MainWP_Child_Backup_Task::instance()->install();
		$this->backup_dir = sys_get_temp_dir() . '/mainwp-prune-test-' . uniqid();
		mkdir( $this->backup_dir );
	}

	/**
	 * Drop spine rows and scratch files between tests.
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
	 * Create a terminal task whose sub_state names artifact files.
	 *
	 * @param string $phase Terminal phase to set.
	 * @param array  $sub_state Sub state to store.
	 *
	 * @return string Task id.
	 */
	private function make_terminal_task( $phase, $sub_state ) {
		$spine   = MainWP_Child_Backup_Task::instance();
		$task_id = $spine->create_task( 'full', array() );
		$spine->checkpoint(
			$task_id,
			0,
			array(
				'phase'     => $phase,
				'sub_state' => $sub_state,
			)
		);
		return $task_id;
	}

	/**
	 * Pruning a terminal task removes its row, artifact files and sidecar.
	 */
	public function test_prune_removes_terminal_row_artifacts_and_sidecar() {
		$artifact = 'backup-prune-test.tar.gz';
		$part     = 'backup-prune-part-1.tar.gz';
		file_put_contents( $this->backup_dir . '/' . $artifact, 'data' );
		file_put_contents( $this->backup_dir . '/' . $part, 'data' );

		$task_id = $this->make_terminal_task(
			MainWP_Child_Backup_Task::PHASE_COMPLETED,
			array(
				'artifact'  => array(
					'file' => $artifact,
					'size' => 4,
				),
				'artifacts' => array(
					array(
						'file' => $part,
						'size' => 4,
						'role' => 'part',
					),
				),
			)
		);

		// A leftover sidecar from a spilled cursor.
		file_put_contents( $this->backup_dir . '/task_substate-' . sanitize_file_name( $task_id ) . '.json', '{}' );

		$spine  = MainWP_Child_Backup_Task::instance();
		$result = $spine->prune_task( $task_id, $this->backup_dir );

		$this->assertSame( 'pruned', $result );
		$this->assertNull( $spine->get_task( $task_id ), 'The terminal row is deleted.' );
		$this->assertFileDoesNotExist( $this->backup_dir . '/' . $artifact );
		$this->assertFileDoesNotExist( $this->backup_dir . '/' . $part );
		$this->assertFileDoesNotExist( $this->backup_dir . '/task_substate-' . sanitize_file_name( $task_id ) . '.json' );
	}

	/**
	 * Create a terminal restore-type task carrying a restore id in its params.
	 *
	 * @param string $restore_id Restore id.
	 * @param string $phase      Terminal phase.
	 *
	 * @return string Task id.
	 */
	private function make_terminal_restore_task( $restore_id, $phase ) {
		$spine   = MainWP_Child_Backup_Task::instance();
		$task_id = $spine->create_task( 'restore_db', array( 'params' => array( 'restore_id' => $restore_id ) ) );
		$spine->checkpoint(
			$task_id,
			0,
			array(
				'phase'     => $phase,
				'sub_state' => array( 'params' => array( 'restore_id' => $restore_id ) ),
			)
		);
		return $task_id;
	}

	/**
	 * Pruning a terminal restore row drops its aside tables before the row is
	 * deleted, so they can never be orphaned.
	 */
	public function test_prune_restore_task_drops_its_aside_tables() {
		$task_id = $this->make_terminal_restore_task( 'prid01', MainWP_Child_Backup_Task::PHASE_FAILED );
		$stub    = new Stub_Restore_Engine();

		$spine  = MainWP_Child_Backup_Task::instance();
		$result = $spine->prune_task( $task_id, $this->backup_dir, $stub );

		$this->assertSame( 'pruned', $result );
		$this->assertNull( $spine->get_task( $task_id ), 'the row is still deleted' );
		$this->assertSame( array( array( 'prid01' ) ), $stub->pruned_id_sets );
	}

	/**
	 * Pruning a non-restore (backup) task never asks the restore engine to
	 * drop anything.
	 */
	public function test_prune_backup_task_skips_aside_table_drop() {
		$task_id = $this->make_terminal_task( MainWP_Child_Backup_Task::PHASE_COMPLETED, array() );
		$stub    = new Stub_Restore_Engine();

		MainWP_Child_Backup_Task::instance()->prune_task( $task_id, $this->backup_dir, $stub );

		$this->assertSame( array(), $stub->pruned_id_sets );
	}

	/**
	 * A running task is never pruned.
	 */
	public function test_prune_refuses_active_task() {
		$spine   = MainWP_Child_Backup_Task::instance();
		$task_id = $spine->create_task( 'full', array() );

		$result = $spine->prune_task( $task_id, $this->backup_dir );

		$this->assertSame( 'active', $result );
		$this->assertNotNull( $spine->get_task( $task_id ), 'An active row survives a prune attempt.' );
	}

	/**
	 * A missing task reports as such; the prune is idempotent.
	 */
	public function test_prune_missing_task_is_idempotent() {
		$result = MainWP_Child_Backup_Task::instance()->prune_task( 'task-never-existed', $this->backup_dir );
		$this->assertSame( 'missing', $result );
	}

	/**
	 * The stale sweep removes old legacy markers and db dump fragments but
	 * leaves fresh files and archives alone.
	 */
	public function test_sweep_stale_markers() {
		$old = time() - 4 * 3600;

		$stale_done = $this->backup_dir . '/backup-123.done';
		$stale_pid  = $this->backup_dir . '/backup-123.pid';
		$stale_dump = $this->backup_dir . '/dbBackup-456.sql.php';
		$fresh_done = $this->backup_dir . '/backup-789.done';
		$archive    = $this->backup_dir . '/backup-old-archive.tar.gz';

		foreach ( array( $stale_done, $stale_pid, $stale_dump, $fresh_done, $archive ) as $file ) {
			file_put_contents( $file, 'x' );
		}
		foreach ( array( $stale_done, $stale_pid, $stale_dump, $archive ) as $file ) {
			touch( $file, $old );
		}

		MainWP_Child_Backup_Task::instance()->sweep_stale_markers( $this->backup_dir );

		$this->assertFileDoesNotExist( $stale_done );
		$this->assertFileDoesNotExist( $stale_pid );
		$this->assertFileDoesNotExist( $stale_dump );
		$this->assertFileExists( $fresh_done, 'Fresh markers may belong to a run still in flight.' );
		$this->assertFileExists( $archive, 'The sweep never touches archives, whatever their age.' );
	}

	/**
	 * A dump fragment an active task still owns survives the sweep even when
	 * it is older than the age cutoff - a long dump on a big site outlives the
	 * window - while a genuinely orphaned old fragment no live task references
	 * is still deleted.
	 */
	public function test_sweep_spares_active_task_dump_fragments() {
		$old   = time() - 4 * 3600;
		$spine = MainWP_Child_Backup_Task::instance();

		$db_prefix = $this->backup_dir . '/dbBackup-runtask' . time();
		$spine->create_task( 'full', array( 'db_prefix' => $db_prefix ) );

		$running_fragment = $db_prefix . '-wp_posts.sql.php';
		$orphan_fragment  = $this->backup_dir . '/dbBackup-orphan-999.sql.php';

		foreach ( array( $running_fragment, $orphan_fragment ) as $file ) {
			file_put_contents( $file, 'x' );
			touch( $file, $old );
		}

		$spine->sweep_stale_markers( $this->backup_dir );

		$this->assertFileExists( $running_fragment, 'A fragment a running task still owns must survive the sweep.' );
		$this->assertFileDoesNotExist( $orphan_fragment );
	}

	/**
	 * An orphaned staged manifest (its task-create never arrived) is
	 * swept once stale; a fresh one may belong to a transfer in flight.
	 */
	public function test_sweep_removes_stale_staged_manifests() {
		$old = time() - 4 * 3600;

		$stale_staged = $this->backup_dir . '/manifest-staged-aaaabbbbcccc0000.list';
		$fresh_staged = $this->backup_dir . '/manifest-staged-dddd111122223333.list';

		foreach ( array( $stale_staged, $fresh_staged ) as $file ) {
			file_put_contents( $file, 'x' );
		}
		touch( $stale_staged, $old );

		MainWP_Child_Backup_Task::instance()->sweep_stale_markers( $this->backup_dir );

		$this->assertFileDoesNotExist( $stale_staged );
		$this->assertFileExists( $fresh_staged );
	}

	/**
	 * Backdate a task row's last write, which for a terminal row is when it
	 * finished.
	 *
	 * @param string $task_id Task id.
	 * @param int    $seconds Seconds in the past.
	 */
	private function backdate_task( $task_id, $seconds ) {
		global $wpdb;
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
			$wpdb->prepare(
				'UPDATE ' . MainWP_Child_Backup_Task::instance()->get_table_name() . ' SET last_progress_at = DATE_SUB(NOW(), INTERVAL %d SECOND) WHERE task_id = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- trusted table name.
				$seconds,
				$task_id
			)
		);
	}

	/**
	 * A completed DB-only backup the Dashboard has not downloaded yet matches
	 * the dbBackup-* fragment pattern, but it is a finished backup, not litter:
	 * the sweep keeps it whatever its age within the collection window.
	 */
	public function test_sweep_keeps_completed_db_artifact_not_yet_collected() {
		$archive = $this->backup_dir . '/dbBackup-1791459958.sql.tar.gz';
		file_put_contents( $archive, 'db' );
		touch( $archive, time() - 4 * 3600 );
		$task_id = $this->make_terminal_task(
			MainWP_Child_Backup_Task::PHASE_COMPLETED,
			array(
				'artifact' => array(
					'file' => basename( $archive ),
					'size' => 2,
				),
			)
		);
		$this->backdate_task( $task_id, 4 * 3600 );

		MainWP_Child_Backup_Task::instance()->sweep_stale_markers( $this->backup_dir );

		$this->assertFileExists( $archive, 'An uncollected completed backup must survive the stale-marker sweep.' );
	}

	/**
	 * A completed backup the Dashboard never collected is deleted once it is
	 * more than seven days old: archives in the uploads folder must not stay
	 * forever. The chain files it names are kept.
	 */
	public function test_sweep_deletes_completed_artifacts_after_seven_days() {
		$archive = $this->backup_dir . '/backup-1790820530.tar.gz';
		$db      = $this->backup_dir . '/dbBackup-1790820530.sql.zip';
		$chain   = $this->backup_dir . '/files.list';
		$tombs   = $this->backup_dir . '/tombstones.list';
		foreach ( array( $archive, $db, $chain, $tombs ) as $file ) {
			file_put_contents( $file, 'x' );
		}
		$expired = $this->make_terminal_task(
			MainWP_Child_Backup_Task::PHASE_COMPLETED,
			array(
				'artifact'  => array(
					'file' => basename( $archive ),
					'size' => 1,
				),
				'artifacts' => array(
					array( 'file' => basename( $archive ), 'role' => 'full' ),
					array( 'file' => basename( $db ), 'role' => 'db' ),
					array( 'file' => 'files.list', 'role' => 'manifest' ),
					array( 'file' => 'tombstones.list', 'role' => 'tombstones' ),
				),
			)
		);
		$this->backdate_task( $expired, 8 * DAY_IN_SECONDS );

		$recent_archive = $this->backup_dir . '/backup-1791459958.tar.gz';
		file_put_contents( $recent_archive, 'x' );
		$recent = $this->make_terminal_task(
			MainWP_Child_Backup_Task::PHASE_COMPLETED,
			array(
				'artifact' => array(
					'file' => basename( $recent_archive ),
					'size' => 1,
				),
			)
		);
		$this->backdate_task( $recent, 6 * DAY_IN_SECONDS );

		MainWP_Child_Backup_Task::instance()->sweep_stale_markers( $this->backup_dir );

		$this->assertFileDoesNotExist( $archive, 'An uncollected archive older than seven days must go.' );
		$this->assertFileDoesNotExist( $db );
		$this->assertFileExists( $chain, 'The chain base is never deleted by a run\'s name.' );
		$this->assertFileExists( $tombs, 'tombstones.list is shared by every incremental run.' );
		$this->assertFileExists( $recent_archive, 'A completed backup inside the collection window stays.' );
	}

	/**
	 * A pre-restore safety backup is the rollback source for the site's last
	 * restore. The Child keeps it with no age limit; the Dashboard retires it.
	 */
	public function test_sweep_keeps_safety_backup_past_seven_days() {
		$archive = $this->backup_dir . '/backup-1791262128.tar.gz';
		file_put_contents( $archive, 'x' );
		$task_id = $this->make_terminal_task(
			MainWP_Child_Backup_Task::PHASE_COMPLETED,
			array(
				'params'   => array( 'purpose' => MainWP_Child_Backup_Task::PURPOSE_PRE_RESTORE ),
				'artifact' => array(
					'file' => basename( $archive ),
					'size' => 1,
				),
			)
		);
		$this->backdate_task( $task_id, 30 * DAY_IN_SECONDS );

		MainWP_Child_Backup_Task::instance()->sweep_stale_markers( $this->backup_dir );

		$this->assertFileExists( $archive, 'A safety backup is kept until the Dashboard retires it.' );
	}

	/**
	 * Lay out the fixed-name chain files.
	 *
	 * @return string[] Absolute paths keyed by name.
	 */
	private function make_chain_files() {
		$chain = array();
		foreach ( array( 'files.list', 'paths.cache', 'files.list.writer.json', 'tombstones.list', 'changes.list' ) as $name ) {
			$chain[ $name ] = $this->backup_dir . '/' . $name;
			file_put_contents( $chain[ $name ], 'chain' );
		}
		return $chain;
	}

	/**
	 * Every incremental names the same files.list and tombstones.list, so a
	 * prune of one run (retention, or an abandoned run) must delete its own
	 * files and leave the chain base the next incremental diffs against.
	 */
	public function test_prune_of_incremental_keeps_chain_files() {
		$chain = $this->make_chain_files();
		$db    = $this->backup_dir . '/dbBackup-1791291867.sql.zip';
		$part  = $this->backup_dir . '/plugins.task1791291867abc.part001.zip';
		foreach ( array( $db, $part ) as $file ) {
			file_put_contents( $file, 'x' );
		}
		$task_id = $this->make_terminal_task(
			MainWP_Child_Backup_Task::PHASE_COMPLETED,
			array(
				'artifact'  => array(
					'file' => 'files.list',
					'size' => 5,
				),
				'artifacts' => array(
					array( 'file' => 'files.list', 'role' => 'manifest' ),
					array( 'file' => 'tombstones.list', 'role' => 'tombstones' ),
					array( 'file' => basename( $db ), 'role' => 'db' ),
					array( 'file' => basename( $part ), 'role' => 'part' ),
				),
			)
		);

		$this->assertSame( 'pruned', MainWP_Child_Backup_Task::instance()->prune_task( $task_id, $this->backup_dir ) );

		$this->assertFileDoesNotExist( $db );
		$this->assertFileDoesNotExist( $part );
		foreach ( $chain as $name => $path ) {
			$this->assertFileExists( $path, $name . ' belongs to the chain, not to the pruned run.' );
		}
	}

	/**
	 * A completed run keeps the name of its spilled parent copy, but
	 * parent-files.list is a fixed name: a prune must not delete the copy a
	 * running incremental is diffing against.
	 */
	public function test_prune_keeps_parent_copy_a_running_task_uses() {
		$spine  = MainWP_Child_Backup_Task::instance();
		$parent = $this->backup_dir . '/parent-files.list';
		file_put_contents( $parent, 'running parent' );
		$old = $this->make_terminal_task(
			MainWP_Child_Backup_Task::PHASE_COMPLETED,
			array( 'params' => array( 'parent_manifest_file' => 'parent-files.list' ) )
		);
		$spine->create_task( 'incremental', array( 'params' => array( 'parent_manifest_file' => 'parent-files.list' ) ) );

		$this->assertSame( 'pruned', $spine->prune_task( $old, $this->backup_dir ) );
		$this->assertFileExists( $parent );
	}

	/**
	 * Run the prune callable's logic for the given ids.
	 *
	 * @param string[] $task_ids Task ids.
	 * @param bool     $sweep    Whether the stale sweep runs after.
	 *
	 * @return array The response the Dashboard receives.
	 */
	private function prune_response( $task_ids, $sweep = false ) {
		return \MainWP\Child\MainWP_Child_Callable::get_instance()->prune_backup_tasks( $task_ids, $sweep, $this->backup_dir );
	}

	/**
	 * The Dashboard prunes a completed run it never downloaded by its child
	 * task id: the archive is deleted and the response carries the aggregate
	 * counts. A second prune of the same id reports it missing.
	 */
	public function test_prune_callable_removes_uncollected_completed_run() {
		$archive = $this->backup_dir . '/backup-1790820530.tar.gz';
		file_put_contents( $archive, 'x' );
		$task_id = $this->make_terminal_task(
			MainWP_Child_Backup_Task::PHASE_COMPLETED,
			array(
				'artifact' => array(
					'file' => basename( $archive ),
					'size' => 1,
				),
			)
		);

		$this->assertSame(
			array(
				'result'  => 'ok',
				'pruned'  => 1,
				'active'  => 0,
				'missing' => 0,
			),
			$this->prune_response( array( $task_id ) )
		);
		$this->assertFileDoesNotExist( $archive );
		$this->assertNull( MainWP_Child_Backup_Task::instance()->get_task( $task_id ) );

		$this->assertSame(
			array(
				'result'  => 'ok',
				'pruned'  => 0,
				'active'  => 0,
				'missing' => 1,
			),
			$this->prune_response( array( $task_id ) )
		);
	}

	/**
	 * A failed run that still names archives (a cancel that could not take
	 * the mutex) is pruned the same way.
	 */
	public function test_prune_callable_removes_failed_run_archives() {
		$archive = $this->backup_dir . '/backup-1791285837.tar.gz';
		file_put_contents( $archive, 'x' );
		$task_id = $this->make_terminal_task(
			MainWP_Child_Backup_Task::PHASE_FAILED,
			array(
				'error'    => array( 'code' => 'mainwp_backup_cancelled' ),
				'artifact' => array(
					'file' => basename( $archive ),
					'size' => 1,
				),
			)
		);

		$response = $this->prune_response( array( $task_id ) );

		$this->assertSame( 1, $response['pruned'] );
		$this->assertSame( 'ok', $response['result'] );
		$this->assertFileDoesNotExist( $archive );
	}

	/**
	 * An active run answers active and keeps its row and files.
	 */
	public function test_prune_callable_refuses_active_run() {
		$spine   = MainWP_Child_Backup_Task::instance();
		$partial = $this->backup_dir . '/backup-1791291867.tar.gz';
		file_put_contents( $partial, 'x' );
		$task_id = $spine->create_task( 'full', array( 'archive_file' => basename( $partial ) ) );

		$this->assertSame(
			array(
				'result'  => 'ok',
				'pruned'  => 0,
				'active'  => 1,
				'missing' => 0,
			),
			$this->prune_response( array( $task_id ), true )
		);
		$this->assertNotNull( $spine->get_task( $task_id ) );
		$this->assertFileExists( $partial );
	}

	/**
	 * The prune action is dashboard-callable.
	 */
	public function test_prune_callable_registered() {
		$callable = \MainWP\Child\MainWP_Child_Callable::get_instance();
		$prop     = new ReflectionProperty( \MainWP\Child\MainWP_Child_Callable::class, 'callableFunctions' );
		$prop->setAccessible( true );
		$map = $prop->getValue( $callable );

		$this->assertArrayHasKey( 'backup_task_prune', $map );
		$this->assertTrue( method_exists( $callable, 'backup_task_prune' ) );
	}

	/**
	 * The by-name deletion helper removes every file the cursor names -
	 * including the mid-flight db fragments and partial archive prune_task
	 * never covered - and nothing the cursor does not name.
	 */
	public function test_delete_artifact_files_removes_named_partials_only() {
		$spine = MainWP_Child_Backup_Task::instance();

		$artifact = $this->backup_dir . '/backup-done.tar';
		$part     = $this->backup_dir . '/plugins.part001.zip';
		$dump     = $this->backup_dir . '/dbBackup-run-part1.sql';
		$marker   = $this->backup_dir . '/dbBackup-run.sql';
		$partial  = $this->backup_dir . '/backup-partial.tar';
		$manifest = $this->backup_dir . '/parent-manifest-abc123def456.list';
		$chain    = $this->backup_dir . '/files.list';
		$keep     = $this->backup_dir . '/backup-keep.tar';
		foreach ( array( $artifact, $part, $dump, $marker, $partial, $manifest, $chain, $keep ) as $file ) {
			file_put_contents( $file, 'x' );
		}

		$spine->delete_artifact_files(
			array(
				'artifact'     => array(
					'file' => 'backup-done.tar',
					'size' => 1,
				),
				'artifacts'    => array(
					array( 'file' => 'plugins.part001.zip' ),
				),
				'db_files'     => array( $dump, $marker ),
				'archive_file' => 'backup-partial.tar',
				'params'       => array( 'parent_manifest_file' => 'parent-manifest-abc123def456.list' ),
			),
			$this->backup_dir
		);

		$this->assertFileDoesNotExist( $artifact );
		$this->assertFileDoesNotExist( $part );
		$this->assertFileDoesNotExist( $dump );
		$this->assertFileDoesNotExist( $marker );
		$this->assertFileDoesNotExist( $partial );
		$this->assertFileDoesNotExist( $manifest );
		$this->assertFileExists( $chain, 'The promoted chain base is never named by a cursor and must survive' );
		$this->assertFileExists( $keep, 'Files the cursor does not name must survive' );
	}
}
