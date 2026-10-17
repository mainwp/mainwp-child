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
	 * the sweep keeps it whatever its age. delete_backup after the download,
	 * or a prune, removes it.
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
		$this->backdate_task( $task_id, 30 * DAY_IN_SECONDS );

		MainWP_Child_Backup_Task::instance()->sweep_stale_markers( $this->backup_dir );

		$this->assertFileExists( $archive, 'An uncollected completed backup must survive the stale-marker sweep.' );
	}

	/**
	 * Lay out the fixed-name chain files.
	 *
	 * @return string[] Absolute paths keyed by name.
	 */
	private function make_chain_files() {
		$chain = array();
		foreach ( array( 'files.list', 'paths.cache', 'files.list.writer.json', 'tombstones.list', 'changes.list', 'parent-files.list' ) as $name ) {
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
	 * running incremental is diffing against, even in the window where the
	 * running cursor still carries only the inline bytes and has not yet
	 * checkpointed the file name.
	 */
	public function test_prune_keeps_parent_copy_a_running_task_uses() {
		$spine  = MainWP_Child_Backup_Task::instance();
		$parent = $this->backup_dir . '/parent-files.list';
		file_put_contents( $parent, 'running parent' );
		$old = $this->make_terminal_task(
			MainWP_Child_Backup_Task::PHASE_COMPLETED,
			array( 'params' => array( 'parent_manifest_file' => 'parent-files.list' ) )
		);
		$spine->create_task( 'incremental', array( 'params' => array( 'parent_manifest_b64' => base64_encode( 'running parent' ) ) ) );

		$this->assertSame( 'pruned', $spine->prune_task( $old, $this->backup_dir ) );
		$this->assertFileExists( $parent );
	}

	/**
	 * A safety backup whose archive a running rollback is about to adopt
	 * cannot be deleted yet. The prune answers active and keeps the row and
	 * its sidecar, so the Dashboard retries instead of losing the only record
	 * of that archive.
	 */
	public function test_prune_of_archive_a_running_restore_adopts_answers_active() {
		$spine   = MainWP_Child_Backup_Task::instance();
		$archive = $this->backup_dir . '/backup-1791262128.tar.gz';
		file_put_contents( $archive, 'safety' );
		$safety  = $spine->create_task( 'full', array() );
		$state   = array(
			'artifact' => array(
				'file' => basename( $archive ),
				'size' => 6,
			),
			'pad'      => array_fill( 0, 4000, str_repeat( 'x', 20 ) ),
		);
		$pointer = $spine->write_sub_state( $safety, $state, $this->backup_dir, 0 );
		$spine->checkpoint(
			$safety,
			0,
			array(
				'phase'     => MainWP_Child_Backup_Task::PHASE_COMPLETED,
				'sub_state' => $pointer,
			)
		);
		$sidecar = $this->backup_dir . '/' . json_decode( $pointer, true )['file'];
		$this->assertFileExists( $sidecar );
		$spine->create_task( 'restore', array( 'params' => array( 'artifacts' => array( array( 'file' => basename( $archive ) ) ) ) ) );

		$this->assertSame(
			array(
				'result'  => 'ok',
				'pruned'  => 0,
				'active'  => 1,
				'missing' => 0,
			),
			$this->prune_response( array( $safety ) )
		);
		$this->assertFileExists( $archive );
		$this->assertNotNull( $spine->get_task( $safety ), 'The row is the record of what is still to delete.' );
		$this->assertFileExists( $sidecar, 'The sidecar lives as long as its row.' );
	}

	/**
	 * A cancel that could not take the mutex leaves an incremental part that
	 * was still open, so no cursor names it. A prune before the next sweep
	 * finds it by the task token in its name.
	 */
	public function test_prune_finds_unnamed_part_by_task_token() {
		$task_id = $this->make_terminal_task(
			MainWP_Child_Backup_Task::PHASE_FAILED,
			array( 'error' => array( 'code' => 'mainwp_backup_cancelled' ) )
		);
		$token   = preg_replace( '/[^A-Za-z0-9]/', '', $task_id );
		$open    = $this->backup_dir . '/themes.' . $token . '.part001.zip';
		$other   = $this->backup_dir . '/themes.othertask123.part001.zip';
		file_put_contents( $open, 'open part' );
		file_put_contents( $other, 'another run' );

		$this->assertSame( 'pruned', MainWP_Child_Backup_Task::instance()->prune_task( $task_id, $this->backup_dir ) );

		$this->assertFileDoesNotExist( $open );
		$this->assertFileExists( $other );
	}

	/**
	 * A fatal between a cancel's checkpoint and its sidecar cleanup leaves
	 * sidecars behind. The sweep reclaims them, keeping the one a row still
	 * points to.
	 */
	public function test_sweep_reclaims_superseded_sidecars_of_failed_rows() {
		$spine  = MainWP_Child_Backup_Task::instance();
		$inline = $this->make_terminal_task(
			MainWP_Child_Backup_Task::PHASE_FAILED,
			array( 'error' => array( 'code' => 'mainwp_backup_cancelled' ) )
		);
		$stale  = $this->backup_dir . '/task_substate-' . sanitize_file_name( $inline ) . '-v3.json';
		file_put_contents( $stale, '{}' );

		$pointed = $spine->create_task( 'full', array() );
		$old     = $this->backup_dir . '/task_substate-' . sanitize_file_name( $pointed ) . '-v1.json';
		file_put_contents( $old, '{}' );
		$pointer = $spine->write_sub_state( $pointed, array( 'error' => array( 'code' => 'x' ), 'pad' => array_fill( 0, 4000, str_repeat( 'x', 20 ) ) ), $this->backup_dir, 7 );
		$spine->checkpoint(
			$pointed,
			0,
			array(
				'phase'     => MainWP_Child_Backup_Task::PHASE_FAILED,
				'sub_state' => $pointer,
			)
		);
		$live = $this->backup_dir . '/' . json_decode( $pointer, true )['file'];

		$spine->sweep_stale_markers( $this->backup_dir );

		$this->assertFileDoesNotExist( $stale );
		$this->assertFileDoesNotExist( $old );
		$this->assertFileExists( $live, 'The sidecar a failed row points to stays.' );
	}

	/**
	 * The sweep holds the backup mutex from reading who owns what until the
	 * last delete, so a restore or backup cannot be created in between with a
	 * claim the sweep never saw.
	 */
	public function test_sweep_holds_mutex_across_ownership_and_deletion() {
		global $wpdb;
		$table    = MainWP_Child_Backup_Task::instance()->get_table_name();
		$attempts = array();
		$probe    = function ( $query ) use ( $table, &$attempts ) {
			if ( 0 === strpos( ltrim( $query ), 'SELECT task_id, phase, sub_state' ) && false !== strpos( $query, $table ) ) {
				$attempts[] = \MainWP\Child\MainWP_Child_Backup_Mutex::acquire( 'native', 'restore-create-probe', 60 );
				if ( true === end( $attempts ) ) {
					\MainWP\Child\MainWP_Child_Backup_Mutex::release( 'restore-create-probe' );
				}
			}
			return $query;
		};
		add_filter( 'query', $probe );
		try {
			MainWP_Child_Backup_Task::instance()->sweep_stale_markers( $this->backup_dir );
		} finally {
			remove_filter( 'query', $probe );
		}

		$this->assertNotEmpty( $attempts, 'The sweep reads task ownership.' );
		foreach ( $attempts as $attempt ) {
			$this->assertNotTrue( $attempt, 'A create must not get the mutex while the sweep runs.' );
		}
		$this->assertNull( \MainWP\Child\MainWP_Child_Backup_Mutex::holder(), 'The sweep releases the mutex it took.' );
	}

	/**
	 * A cancel during a tier-2 dump leaves table fragments and a .tmp no
	 * cursor names. They match the dbBackup-* pattern, so the next sweep
	 * after the 3-hour threshold removes them.
	 */
	public function test_sweep_removes_cancelled_mid_dump_fragments_after_threshold() {
		$runner  = \MainWP\Child\MainWP_Child_Backup_Task_Runner::instance();
		$spine   = MainWP_Child_Backup_Task::instance();
		$prefix  = $this->backup_dir . '/dbBackup-1791459958';
		$task_id = $spine->create_task( 'db', array( 'db_prefix' => $prefix, 'db_tier' => 2, 'db_cursor' => array( 'index' => 1 ) ) );
		$files   = array( $prefix . '-wp_posts.sql.php', $prefix . '-wp_options.sql.php.tmp' );
		foreach ( $files as $file ) {
			file_put_contents( $file, 'dump' );
		}
		$this->assertSame( 'ok', $runner->cancel_backup_task( $task_id )['result'] );
		foreach ( $files as $file ) {
			touch( $file, time() - 4 * 3600 );
		}

		$spine->sweep_stale_markers( $this->backup_dir );

		foreach ( $files as $file ) {
			$this->assertFileDoesNotExist( $file );
		}
	}

	/**
	 * A prune deletes only while it holds the backup mutex, so a restore
	 * cannot be created between its ownership read and its deletes. While
	 * another owner holds the mutex it answers active and keeps the row.
	 */
	public function test_prune_answers_active_while_mutex_is_live_elsewhere() {
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
		$this->assertTrue( \MainWP\Child\MainWP_Child_Backup_Mutex::acquire( 'legacy', 'clone-owner', 300 ) );
		try {
			$response = $this->prune_response( array( $task_id ) );
		} finally {
			\MainWP\Child\MainWP_Child_Backup_Mutex::release( 'clone-owner' );
		}

		$this->assertSame( 1, $response['active'] );
		$this->assertSame( 0, $response['pruned'] );
		$this->assertFileExists( $archive );
		$this->assertNotNull( MainWP_Child_Backup_Task::instance()->get_task( $task_id ) );

		$this->assertSame( 1, $this->prune_response( array( $task_id ) )['pruned'], 'Once the mutex is free the prune goes through.' );
		$this->assertFileDoesNotExist( $archive );
	}

	/**
	 * If the sweep loses the mutex mid-pass (its lease ran out and another
	 * owner took over), it stops before its next delete.
	 */
	public function test_sweep_stops_when_mutex_ownership_is_lost() {
		global $wpdb;
		$doomed = $this->backup_dir . '/backup-1791285837.tar.gz';
		$stale  = $this->backup_dir . '/dbBackup-dead-run.sql';
		file_put_contents( $doomed, 'x' );
		file_put_contents( $stale, 'x' );
		touch( $stale, time() - 4 * 3600 );
		$this->make_terminal_task(
			MainWP_Child_Backup_Task::PHASE_FAILED,
			array(
				'error'    => array( 'code' => 'mainwp_backup_cancelled' ),
				'artifact' => array(
					'file' => basename( $doomed ),
					'size' => 1,
				),
			)
		);
		$table  = MainWP_Child_Backup_Task::instance()->get_table_name();
		$option = \MainWP\Child\MainWP_Child_Backup_Mutex::OPTION_NAME;
		$thief  = function ( $query ) use ( $table, $option, $wpdb, &$thief ) {
			if ( 0 === strpos( ltrim( $query ), 'SELECT task_id, phase, sub_state' ) && false !== strpos( $query, $table ) ) {
				remove_filter( 'query', $thief );
				$wpdb->query( $wpdb->prepare( "UPDATE $wpdb->options SET option_value = %s WHERE option_name = %s", 'native|restore-create-thief|' . ( time() + 600 ), $option ) );
				wp_cache_delete( $option, 'options' );
			}
			return $query;
		};
		add_filter( 'query', $thief );
		try {
			MainWP_Child_Backup_Task::instance()->sweep_stale_markers( $this->backup_dir );
		} finally {
			remove_filter( 'query', $thief );
			$holder = \MainWP\Child\MainWP_Child_Backup_Mutex::holder();
			delete_option( $option );
		}

		$this->assertSame( 'restore-create-thief', $holder['owner'], 'The sweep must not release a mutex it no longer owns.' );
		$this->assertFileExists( $doomed, 'No delete after ownership is lost.' );
		$this->assertFileExists( $stale, 'No delete after ownership is lost.' );
	}

	/**
	 * A restore past its deadline whose worker is gone stops protecting the
	 * archive it named: the prune first runs the existing lease-aware
	 * deadline reclamation, then deletes.
	 */
	public function test_prune_reclaims_expired_protector_first() {
		global $wpdb;
		$spine   = MainWP_Child_Backup_Task::instance();
		$archive = $this->backup_dir . '/backup-1791262128.tar.gz';
		file_put_contents( $archive, 'safety' );
		$safety  = $this->make_terminal_task(
			MainWP_Child_Backup_Task::PHASE_COMPLETED,
			array(
				'artifact' => array(
					'file' => basename( $archive ),
					'size' => 6,
				),
			)
		);
		$restore = $spine->create_task( 'restore', array( 'params' => array( 'artifacts' => array( array( 'file' => basename( $archive ) ) ) ) ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . $spine->get_table_name() . ' SET max_wall_clock_at = DATE_SUB(NOW(), INTERVAL 60 SECOND), started_at = DATE_SUB(NOW(), INTERVAL 2 HOUR) WHERE task_id = %s', $restore ) );

		$this->assertSame( 1, $this->prune_response( array( $safety ) )['pruned'] );
		$this->assertFileDoesNotExist( $archive );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $spine->get_task( $restore )->phase );
	}

	/**
	 * A failed row may still point to the legacy unversioned sidecar name.
	 * The sweep keeps that file like a versioned one.
	 */
	public function test_sweep_keeps_legacy_sidecar_a_failed_row_points_to() {
		global $wpdb;
		$spine   = MainWP_Child_Backup_Task::instance();
		$task_id = $this->make_terminal_task(
			MainWP_Child_Backup_Task::PHASE_FAILED,
			array( 'error' => array( 'code' => 'x' ) )
		);
		$legacy  = 'task_substate-' . sanitize_file_name( $task_id ) . '.json';
		file_put_contents( $this->backup_dir . '/' . $legacy, wp_json_encode( array( 'error' => array( 'code' => 'x' ) ) ) );
		$wpdb->update(
			$spine->get_table_name(),
			array(
				'sub_state' => wp_json_encode(
					array(
						'_sidecar' => true,
						'file'     => $legacy,
					)
				),
			),
			array( 'task_id' => $task_id )
		);

		$spine->sweep_stale_markers( $this->backup_dir );

		$this->assertFileExists( $this->backup_dir . '/' . $legacy );
	}

	public function test_prune_retries_pending_list_under_its_existing_fence() {
		global $wpdb;
		$root = $this->backup_dir . '/uploads';
		$upload = static function ( $dirs ) use ( $root ) {
			$dirs['basedir'] = $root;
			return $dirs;
		};
		wp_mkdir_p( $root );
		add_filter( 'upload_dir', $upload );
		add_filter( 'mainwp_child_get_wp_upload_dir', $upload );
		$dir = \MainWP\Child\MainWP_Helper::get_mainwp_dir( 'backup' )[0];
		$name = 'backup-pending-prune.zip';
		file_put_contents( $dir . $name, 'pending' );
		$task_id = $this->make_terminal_task( MainWP_Child_Backup_Task::PHASE_COMPLETED, array() );
		$owner = null;
		// Reclaim runs first. Insert the intent only when prune takes its mutex.
		$filter = function ( $query ) use ( &$filter, $dir, $name ) {
			if ( 0 === strpos( $query, 'INSERT IGNORE INTO' ) && false !== strpos( $query, 'native|prune-' ) ) {
				remove_filter( 'query', $filter );
				file_put_contents( $dir . 'pending-delete.json', wp_json_encode( array( array(
					'name' => $name, 'size' => 7, 'mtime' => filemtime( $dir . $name ), 'added' => time(),
				) ) ) );
			}
			return $query;
		};
		$observe = function ( $file ) use ( &$owner, $name ) {
			if ( basename( $file ) === $name ) {
				$owner = \MainWP\Child\MainWP_Child_Backup_Mutex::holder();
			}
			return $file;
		};
		add_filter( 'query', $filter );
		add_filter( 'wp_delete_file', $observe );
		try {
			$this->assertSame( 'pruned', MainWP_Child_Backup_Task::instance()->prune_task( $task_id, $dir ) );
			$this->assertFileDoesNotExist( $dir . $name );
			$this->assertFileDoesNotExist( $dir . 'pending-delete.json' );
			$this->assertIsArray( $owner );
			$this->assertSame( 0, strpos( $owner['owner'], 'prune-' ) );
			$this->assertNull( \MainWP\Child\MainWP_Child_Backup_Mutex::holder() );
		} finally {
			remove_filter( 'query', $filter );
			remove_filter( 'wp_delete_file', $observe );
			wp_clear_scheduled_hook( 'mainwp_child_backup_pending_delete' );
			remove_filter( 'upload_dir', $upload );
			remove_filter( 'mainwp_child_get_wp_upload_dir', $upload );
			global $wp_filesystem;
			$wp_filesystem->delete( $root, true );
		}
	}

	public function test_unreadable_active_cursor_preserves_existing_prune_and_sweep_behavior() {
		global $wpdb;
		$spine = MainWP_Child_Backup_Task::instance();
		$name = 'backup-unreadable-prune.zip';
		file_put_contents( $this->backup_dir . '/' . $name, 'x' );
		$terminal = $this->make_terminal_task( MainWP_Child_Backup_Task::PHASE_COMPLETED, array( 'artifact' => array( 'file' => $name, 'size' => 1 ) ) );
		$active = $spine->create_task( 'full', array() );
		$wpdb->update( $spine->get_table_name(), array( 'sub_state' => '{broken' ), array( 'task_id' => $active ) );
		$this->assertSame( 'pruned', $spine->prune_task( $terminal, $this->backup_dir ) );
		$this->assertFileDoesNotExist( $this->backup_dir . '/' . $name );
		$stale = $this->backup_dir . '/dbBackup-unreadable.sql';
		file_put_contents( $stale, 'old dump' );
		touch( $stale, time() - 4 * HOUR_IN_SECONDS );
		$spine->sweep_stale_markers( $this->backup_dir );
		$this->assertFileDoesNotExist( $stale );
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
