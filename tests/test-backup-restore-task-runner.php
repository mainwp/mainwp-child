<?php
/**
 * Class BackupRestoreTaskRunnerTest
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Backup_Db_Restore_Engine;
use MainWP\Child\MainWP_Backup_Error;
use MainWP\Child\MainWP_Child_Backup_Task;
use MainWP\Child\MainWP_Child_Backup_Task_Runner;
use MainWP\Child\MainWP_Child_Restore_Task_Runner;

/**
 * Scripted DB restore engine: no database side effects, behavior set
 * by the test.
 */
class Fake_Restore_Engine extends MainWP_Backup_Db_Restore_Engine {

	/**
	 * Error the replay should report, or null.
	 *
	 * @var string|null
	 */
	public $replay_error = null;

	/**
	 * Tables the replay reports.
	 *
	 * @var array
	 */
	public $tables = array(
		'items' => array(
			'status' => 'ok',
			'rows'   => 2,
		),
	);

	/**
	 * Call log.
	 *
	 * @var string[]
	 */
	public $calls = array();

	/**
	 * Scripted replay.
	 *
	 * @param array     $cursor   Cursor.
	 * @param array     $config   Config.
	 * @param float|int $deadline Deadline.
	 * @param bool      $unclean  Unclean resume flag.
	 *
	 * @return array Slice result.
	 */
	public function replay_slice( $cursor, $config, $deadline, $unclean = false ) {
		$this->calls[] = 'replay_slice' . ( $unclean ? ':unclean' : '' ) . ( ! empty( $config['fk_path'] ) ? ':fk' : '' );
		if ( null !== $this->replay_error ) {
			return array(
				'done'       => false,
				'cursor'     => array( 'tables' => array() ),
				'error'      => $this->replay_error,
				'error_code' => MainWP_Backup_Error::ERR_RESTORE_DB_REPLAY,
			);
		}
		return array(
			'done'       => true,
			'cursor'     => array( 'tables' => $this->tables ),
			'error'      => null,
			'error_code' => null,
		);
	}

	/**
	 * Scripted swap.
	 *
	 * @param array $stems  Stems.
	 * @param array $config Config.
	 *
	 * @return array Swap result.
	 */
	public function swap_tables( $stems, $config ) {
		$this->calls[] = 'swap_tables:' . implode( ',', $stems );
		return array(
			'done'       => true,
			'old_tables' => array( 'old' . $config['restore_id'] . '_items' ),
			'rename_sql' => 'RENAME TABLE ...',
			'error'      => null,
			'error_code' => null,
		);
	}

	/**
	 * Scripted verification.
	 *
	 * @param array  $expected      Expected counts.
	 * @param string $target_prefix Prefix.
	 *
	 * @return array Verification result.
	 */
	public function verify_row_counts( $expected, $target_prefix ) {
		$this->calls[] = 'verify_row_counts';
		return array(
			'ok'         => true,
			'mismatches' => array(),
		);
	}

	/**
	 * Tracked drop.
	 *
	 * @param string $restore_id Restore id.
	 */
	public function drop_old_tables( $restore_id ) {
		$this->calls[] = 'drop_old_tables';
	}

	/**
	 * Tracked cleanup.
	 *
	 * @param string $restore_id Restore id.
	 */
	public function cleanup_tmp_tables( $restore_id ) {
		$this->calls[] = 'cleanup_tmp_tables';
	}

	/**
	 * Tracked maintenance exit.
	 */
	public function exit_maintenance() {
		$this->calls[] = 'exit_maintenance';
	}
}

/**
 * Restore runner with every environment dependency pinned.
 */
class Testable_Restore_Task_Runner extends MainWP_Child_Restore_Task_Runner {

	/**
	 * Pinned backup dir.
	 *
	 * @var string
	 */
	public $test_backup_dir = '';

	/**
	 * Pinned site root.
	 *
	 * @var string
	 */
	public $test_site_root = '';

	/**
	 * Shared fake engine.
	 *
	 * @var Fake_Restore_Engine
	 */
	public $fake_engine;

	/**
	 * Sentinel result.
	 *
	 * @var bool
	 */
	public $sentinel_ok = true;

	/**
	 * Pinned dir.
	 *
	 * @return string
	 */
	protected function backup_dir() {
		return $this->test_backup_dir;
	}

	/**
	 * Pinned root.
	 *
	 * @return string
	 */
	protected function site_root() {
		return $this->test_site_root;
	}

	/**
	 * Shared fake.
	 *
	 * @return MainWP_Backup_Db_Restore_Engine
	 */
	protected function restore_engine() {
		return $this->fake_engine;
	}

	/**
	 * Scratch prefix.
	 *
	 * @return string
	 */
	protected function target_prefix() {
		return 'rt_';
	}

	/**
	 * Stubbed sentinel.
	 *
	 * @param string $target_prefix Prefix.
	 * @param string $restore_id    Restore id.
	 *
	 * @return bool
	 */
	protected function sentinel_round_trip( $target_prefix, $restore_id ) {
		return $this->sentinel_ok;
	}

	/**
	 * Loopback stub.
	 *
	 * @return int
	 */
	protected function loopback_status() {
		return 200;
	}

	/**
	 * No cache side effects in tests.
	 */
	protected function flush_caches() {
	}
}

/**
 * Tests for the restore task runner: the full phase pipeline over real
 * artifacts, failure cleanup discipline, the in-place FK route, and
 * watchdog ownership routing.
 */
class BackupRestoreTaskRunnerTest extends WP_UnitTestCase {

	/**
	 * Scratch dir.
	 *
	 * @var string
	 */
	protected $dir;

	/**
	 * Fake site root.
	 *
	 * @var string
	 */
	protected $site;

	/**
	 * Runner under test.
	 *
	 * @var Testable_Restore_Task_Runner
	 */
	protected $runner;

	/**
	 * Spine.
	 *
	 * @var MainWP_Child_Backup_Task
	 */
	protected $spine;

	/**
	 * Build scratch dirs, runner, fake engine.
	 */
	public function set_up() {
		parent::set_up();
		$this->dir  = sys_get_temp_dir() . '/mainwp-restore-runner-' . uniqid();
		$this->site = $this->dir . '/site';
		mkdir( $this->site, 0777, true );

		$this->runner                  = new Testable_Restore_Task_Runner();
		$this->runner->test_backup_dir = $this->dir;
		$this->runner->test_site_root  = $this->site;
		$this->runner->fake_engine     = new Fake_Restore_Engine();

		$this->spine = MainWP_Child_Backup_Task::instance();
		$this->spine->install();

		// The pinned target prefix is rt_; the rewrite phase pages this
		// table for real.
		global $wpdb;
		$wpdb->query( 'CREATE TABLE IF NOT EXISTS `rt_items` (id INT NOT NULL, val TEXT, PRIMARY KEY (id))' ); // phpcs:ignore WordPress.DB -- test fixture.
	}

	/**
	 * Cleanup.
	 */
	public function tear_down() {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $this->spine->get_table_name() ); // phpcs:ignore WordPress.DB -- test cleanup.
		$wpdb->query( 'DROP TABLE IF EXISTS `rt_items`' ); // phpcs:ignore WordPress.DB -- test cleanup.
		$this->rrmdir( $this->dir );
		parent::tear_down();
	}

	/**
	 * Recursive cleanup.
	 *
	 * @param string $dir Directory.
	 */
	protected function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_dir( $path ) ) {
				$this->rrmdir( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}

	/**
	 * Build the db dump zip artifact in the backup dir (adoptable).
	 *
	 * @param bool $fk Whether the banner declares foreign keys.
	 *
	 * @return array Artifact entry.
	 */
	protected function make_db_artifact( $fk = false ) {
		$banner = '-- mainwp-backup-info: {"site_url":"' . site_url() . '","home_url":"' . home_url() . '","table_prefix":"src_"}' . "\n"
			. '-- mainwp-backup-meta: {"has_foreign_keys":' . ( $fk ? 'true' : 'false' ) . ',"tier":1}';
		$sql    = '<?php exit(); ?>' . "\n" . $banner . "\n\nDROP TABLE IF EXISTS `src_items`;\nCREATE TABLE `src_items` (id INT);\nINSERT INTO `src_items` (`id`) VALUES (1),(2);\n";

		$zip_path = $this->dir . '/db.zip';
		$zip      = new ZipArchive();
		$zip->open( $zip_path, ZipArchive::CREATE );
		$zip->addFromString( 'dbBackup-src_items.sql.php', $sql );
		$zip->close();

		return array(
			'role'       => 'db',
			'file'       => 'db.zip',
			'size'       => (int) filesize( $zip_path ),
			'sha256'     => hash_file( 'sha256', $zip_path ),
			'adopt_only' => false,
		);
	}

	/**
	 * Build a file-part zip artifact in the backup dir.
	 *
	 * @return array Artifact entry.
	 */
	protected function make_part_artifact() {
		$zip_path = $this->dir . '/part001.zip';
		$zip      = new ZipArchive();
		$zip->open( $zip_path, ZipArchive::CREATE );
		$zip->addFromString( 'wp-content/uploads/restored.txt', 'restored-content' );
		$zip->close();

		return array(
			'role'       => 'part',
			'file'       => 'part001.zip',
			'size'       => (int) filesize( $zip_path ),
			'sha256'     => hash_file( 'sha256', $zip_path ),
			'adopt_only' => false,
		);
	}

	/**
	 * Build a tombstones artifact in the backup dir.
	 *
	 * @return array Artifact entry.
	 */
	protected function make_tombstones_artifact() {
		$path = $this->dir . '/tombstones.list';
		file_put_contents( $path, "stale.txt\n" ); // phpcs:ignore -- fixture.
		return array(
			'role'       => 'tombstones',
			'file'       => 'tombstones.list',
			'size'       => (int) filesize( $path ),
			'sha256'     => hash_file( 'sha256', $path ),
			'adopt_only' => false,
		);
	}

	/**
	 * Create a restore task whose artifacts sit in the backup dir for
	 * adoption.
	 *
	 * @param string $sub_type  'full' or 'db'.
	 * @param array  $artifacts Artifact entries.
	 * @param array  $over      Param overrides.
	 *
	 * @return string Task id.
	 */
	protected function make_task( $sub_type, $artifacts, $over = array() ) {
		$params = array_merge(
			array(
				'restore_id'       => 'rrun01',
				'source_prefix'    => 'src_',
				'artifacts'        => $artifacts,
				'has_foreign_keys' => null,
				'file_rollback'    => false,
			),
			$over
		);
		return $this->runner->create_restore_task( $sub_type, $params );
	}

	/**
	 * Drive the task to a terminal phase.
	 *
	 * @param string $task_id Task id.
	 *
	 * @return object Final task row.
	 */
	protected function drive( $task_id ) {
		$guard = 0;
		$task  = $this->spine->get_task( $task_id );
		while ( ! MainWP_Child_Backup_Task::is_terminal_phase( $task->phase ) && $guard < 30 ) {
			$task = $this->runner->advance_one_slice( $task, MainWP_Child_Backup_Task_Runner::LEASE_OWNER_DASHBOARD );
			++$guard;
		}
		return $task;
	}

	/**
	 * A db-only restore runs the whole pipeline to completed: adoption,
	 * verification, dump extraction, replay, swap, rewrite, verify.
	 */
	public function test_db_restore_pipeline_completes() {
		$task_id = $this->make_task( 'db', array( $this->make_db_artifact() ) );
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );

		$sub_state = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertTrue( $sub_state['verification']['row_counts_ok'] );
		$this->assertTrue( $sub_state['verification']['sentinel_ok'] );

		$calls = $this->runner->fake_engine->calls;
		$this->assertContains( 'swap_tables:items', $calls );
		$this->assertContains( 'verify_row_counts', $calls );
		$this->assertContains( 'drop_old_tables', $calls );

		// Scratch staging is gone after a verified restore.
		$this->assertDirectoryDoesNotExist( $this->dir . '/restore-' . $task_id );
	}

	/**
	 * A full restore extracts file parts over the site root and applies
	 * tombstones before the database phases.
	 */
	public function test_full_restore_applies_files_and_tombstones() {
		file_put_contents( $this->site . '/stale.txt', 'old' ); // phpcs:ignore -- fixture.

		$task_id = $this->make_task(
			'full',
			array( $this->make_part_artifact(), $this->make_tombstones_artifact(), $this->make_db_artifact() )
		);
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertSame( 'restored-content', file_get_contents( $this->site . '/wp-content/uploads/restored.txt' ) );
		$this->assertFileDoesNotExist( $this->site . '/stale.txt' );
	}

	/**
	 * A replay failure lands terminal failed with tmp tables cleaned,
	 * maintenance lifted, scratch removed - and old tables NOT dropped.
	 */
	public function test_replay_failure_cleans_up_but_keeps_old_tables() {
		$this->runner->fake_engine->replay_error = 'Replay failed on table items: forced';

		$task_id = $this->make_task( 'db', array( $this->make_db_artifact() ) );
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );

		$payload = $this->spine->status_payload( $task );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_DB_REPLAY, $payload['error_code'] );

		$calls = $this->runner->fake_engine->calls;
		$this->assertContains( 'cleanup_tmp_tables', $calls );
		$this->assertContains( 'exit_maintenance', $calls );
		$this->assertNotContains( 'drop_old_tables', $calls );
		$this->assertDirectoryDoesNotExist( $this->dir . '/restore-' . $task_id );
	}

	/**
	 * A dump declaring foreign keys takes the in-place route: replay
	 * runs with fk_path, the swap phase never executes, and maintenance
	 * mode is exited on the way out.
	 */
	public function test_fk_dump_takes_in_place_route_and_skips_swap() {
		$task_id = $this->make_task( 'db', array( $this->make_db_artifact( true ) ) );
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );

		$calls = $this->runner->fake_engine->calls;
		$this->assertContains( 'replay_slice:fk', $calls );
		$this->assertContains( 'exit_maintenance', $calls );
		foreach ( $calls as $call ) {
			$this->assertStringNotContainsString( 'swap_tables', $call );
		}
	}

	/**
	 * An artifact whose bytes differ from the manifest hash is not
	 * adopted, and the missing transfer keeps the task in receiving.
	 */
	public function test_adoption_refuses_hash_mismatch() {
		$artifact = $this->make_db_artifact();
		// Corrupt the on-disk copy after recording the manifest hash.
		file_put_contents( $this->dir . '/db.zip', 'tampered bytes' ); // phpcs:ignore -- fixture.

		$task_id = $this->make_task( 'db', array( $artifact ) );
		$task    = $this->spine->get_task( $task_id );
		$task    = $this->runner->advance_one_slice( $task, MainWP_Child_Backup_Task_Runner::LEASE_OWNER_DASHBOARD );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_RECEIVING_ARTIFACTS, $task->phase );

		$sub_state = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertEmpty( $sub_state['receiving']['files'] );
	}

	/**
	 * The backup runner's watchdog listener leaves restore tasks alone.
	 */
	public function test_backup_runner_ignores_restore_tasks_on_resume() {
		$task_id = $this->make_task( 'db', array( $this->make_db_artifact() ) );
		$before  = $this->spine->get_task( $task_id );

		MainWP_Child_Backup_Task_Runner::instance()->handle_watchdog_resume( $before, (int) $before->version );

		$after = $this->spine->get_task( $task_id );
		$this->assertSame( $before->phase, $after->phase );
		$this->assertSame( (int) $before->version, (int) $after->version );
	}

	/**
	 * The restore runner's listener leaves backup tasks alone.
	 */
	public function test_restore_runner_ignores_backup_tasks_on_resume() {
		$task_id = $this->spine->create_task( 'db', array() );
		$before  = $this->spine->get_task( $task_id );

		$this->runner->handle_watchdog_resume( $before, (int) $before->version );

		$after = $this->spine->get_task( $task_id );
		$this->assertSame( $before->phase, $after->phase );
		$this->assertSame( (int) $before->version, (int) $after->version );
	}

	/**
	 * The single-slot invariant blocks a second task while a restore is
	 * active.
	 */
	public function test_active_restore_blocks_second_task() {
		$this->make_task( 'db', array( $this->make_db_artifact() ) );

		$this->expectException( \MainWP\Child\MainWP_Exception::class );
		$this->make_task( 'db', array( $this->make_db_artifact() ) );
	}
}
