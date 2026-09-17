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
	 * A new full backup must not reuse fragments left by an interrupted dump, even if recent.
	 */
	public function test_full_task_creation_removes_orphaned_dump_fragments() {
		$spine    = MainWP_Child_Backup_Task::instance();
		$dirs     = \MainWP\Child\MainWP_Helper::get_mainwp_dir( 'backup' );
		$prefix   = rtrim( $dirs[0], '/' ) . '/dbBackup-create-' . uniqid();
		$fragment = $prefix . '-wp_posts.sql.php';
		$archive  = $prefix . '.sql.zip';
		$marker   = rtrim( $dirs[0], '/' ) . '/backup-create-' . uniqid() . '.done';

		try {
			foreach ( array( $fragment, $archive, $marker ) as $file ) {
				file_put_contents( $file, 'old dump' );
			}
			touch( $archive, time() - 4 * 3600 );
			touch( $marker, time() - 4 * 3600 );

			$this->assertNotFalse( $spine->create_task( 'db' ) );
			$this->assertFileExists( $fragment, 'Only full task creation should sweep fragments.' );
			$this->assertNotFalse( $spine->create_task( 'full' ) );
			$this->assertFileDoesNotExist( $fragment );
			$this->assertFileExists( $archive );
			$this->assertFileExists( $marker );
		} finally {
			foreach ( array( $fragment, $archive, $marker ) as $file ) {
				if ( file_exists( $file ) ) {
					unlink( $file );
				}
			}
		}
	}

	/**
	 * Creating another full task must preserve fragments an active task still needs.
	 */
	public function test_full_task_creation_spares_active_dump_fragments() {
		$spine  = MainWP_Child_Backup_Task::instance();
		$dirs   = \MainWP\Child\MainWP_Helper::get_mainwp_dir( 'backup' );
		$prefix = rtrim( $dirs[0], '/' ) . '/dbBackup-active-' . uniqid();
		$files  = array( $prefix . '-wp_posts.sql.php', $prefix . '-named.sql.php', $prefix . '-orphan.sql.php' );
		$this->assertNotFalse(
			$spine->create_task(
				'full',
				array(
					'db_prefix' => $prefix . '-wp',
					'db_files'  => array( $files[1] ),
				)
			)
		);

		try {
			foreach ( $files as $file ) {
				file_put_contents( $file, 'active dump' );
				touch( $file, time() - 4 * 3600 );
			}

			$this->assertNotFalse( $spine->create_task( 'full' ) );
			$this->assertFileExists( $files[0], 'The active dump prefix must remain guarded.' );
			$this->assertFileExists( $files[1], 'Explicitly recorded dump files must remain guarded.' );
			$this->assertFileDoesNotExist( $files[2] );
		} finally {
			foreach ( $files as $file ) {
				if ( file_exists( $file ) ) {
					unlink( $file );
				}
			}
		}
	}

	/**
	 * The legacy engine dumps under the same names with no task row and reads
	 * its dump files long after its pid goes stale, so any pid stands the
	 * age-0 sweep down and the aged sweep is what clears the way again.
	 */
	public function test_full_task_creation_spares_fragments_while_a_legacy_pid_is_present() {
		$spine    = MainWP_Child_Backup_Task::instance();
		$dirs     = \MainWP\Child\MainWP_Helper::get_mainwp_dir( 'backup' );
		$prefix   = rtrim( $dirs[0], '/' ) . '/dbBackup-legacy-' . uniqid();
		$fragment = $prefix . '-wp_posts.sql.php';
		$pid      = rtrim( $dirs[0], '/' ) . '/backup-legacy-' . uniqid() . '.pid';

		try {
			file_put_contents( $fragment, 'legacy dump in flight' );
			file_put_contents( $pid, '12345' );

			$this->assertNotFalse( $spine->create_task( 'full' ) );
			$this->assertFileExists( $fragment, 'A fresh pid must stand the sweep down.' );

			touch( $pid, time() - 4 * 3600 );

			$this->assertNotFalse( $spine->create_task( 'full' ) );
			$this->assertFileExists( $fragment, 'A stale pid must stand the sweep down too: the legacy zip path never heartbeats.' );

			unlink( $pid );

			$this->assertNotFalse( $spine->create_task( 'full' ) );
			$this->assertFileDoesNotExist( $fragment, 'With no pid in the directory the sweep runs.' );
		} finally {
			foreach ( array( $fragment, $pid ) as $file ) {
				if ( file_exists( $file ) ) {
					unlink( $file );
				}
			}
		}
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
