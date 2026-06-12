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
	 * Last config handed to replay_slice.
	 *
	 * @var array|null
	 */
	public $last_replay_config = null;

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
		$this->calls[]            = 'replay_slice' . ( $unclean ? ':unclean' : '' ) . ( ! empty( $config['fk_path'] ) ? ':fk' : '' );
		$this->last_replay_config = $config;
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
 * Restore runner whose cloud downloads are scripted: URLs map to bytes
 * (or false for a failed transfer), and every pulled URL is recorded.
 */
class Cloud_Pull_Test_Restore_Runner extends Testable_Restore_Task_Runner {

	/**
	 * Scripted responses: url => bytes, or false for a failure.
	 *
	 * @var array<string,string|false>
	 */
	public $pull_responses = array();

	/**
	 * Every URL the runner tried to pull.
	 *
	 * @var string[]
	 */
	public $pulled_urls = array();

	/**
	 * Scripted free disk space, or null to skip the check.
	 *
	 * @var float|null
	 */
	public $fake_free_space = null;

	/**
	 * Scripted download.
	 *
	 * @param string $url       Presigned URL.
	 * @param string $dest_path Local target.
	 *
	 * @return bool
	 */
	protected function download_url_to_file( $url, $dest_path ) {
		$this->pulled_urls[] = $url;
		if ( ! array_key_exists( $url, $this->pull_responses ) || false === $this->pull_responses[ $url ] ) {
			return false;
		}
		file_put_contents( $dest_path, $this->pull_responses[ $url ] ); // phpcs:ignore -- fixture.
		return true;
	}

	/**
	 * Scripted free space.
	 *
	 * @param string $dir Directory.
	 *
	 * @return float|null
	 */
	protected function free_disk_space( $dir ) {
		if ( null !== $this->fake_free_space ) {
			return $this->fake_free_space;
		}
		return parent::free_disk_space( $dir );
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
	 * Build a plain-full-style archive in the backup dir: site files plus
	 * the embedded per-table dump and zero-byte marker a full backup
	 * carries, with no separate db artifact.
	 *
	 * @param bool $bannerless Whether the embedded dump omits its banner.
	 *
	 * @return array Artifact entry.
	 */
	protected function make_full_archive_with_embedded_dump( $bannerless = false ) {
		$sql = '<?php exit(); ?>' . "\n";
		if ( ! $bannerless ) {
			$sql .= '-- mainwp-backup-info: {"site_url":"' . site_url() . '","home_url":"' . home_url() . '","table_prefix":"bn_"}' . "\n"
				. '-- mainwp-backup-meta: {"has_foreign_keys":false,"tier":1}' . "\n";
		}
		$sql .= "\nDROP TABLE IF EXISTS `bn_items`;\nCREATE TABLE `bn_items` (id INT);\nINSERT INTO `bn_items` (`id`) VALUES (1),(2);\n";

		$zip_path = $this->dir . '/full001.zip';
		$zip      = new ZipArchive();
		$zip->open( $zip_path, ZipArchive::CREATE );
		$zip->addFromString( 'wp-content/uploads/restored.txt', 'restored-content' );
		$zip->addFromString( 'wp-content/dbBackup-bn_items.sql.php', $sql );
		$zip->addFromString( 'wp-content/dbBackup.sql', '' );
		$zip->close();

		return array(
			'role'       => 'part',
			'file'       => 'full001.zip',
			'size'       => (int) filesize( $zip_path ),
			'sha256'     => hash_file( 'sha256', $zip_path ),
			'adopt_only' => false,
		);
	}

	/**
	 * Build a files-only archive in the backup dir: site files, no
	 * database dump of any kind. A files snapshot's archive rides the
	 * full download slot, so its role is 'full'.
	 *
	 * @return array Artifact entry.
	 */
	protected function make_files_archive_artifact() {
		$zip_path = $this->dir . '/files001.zip';
		$zip      = new ZipArchive();
		$zip->open( $zip_path, ZipArchive::CREATE );
		$zip->addFromString( 'wp-content/uploads/restored.txt', 'restored-content' );
		$zip->close();

		return array(
			'role'       => 'full',
			'file'       => 'files001.zip',
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
	 * A plain full archive with no separate db artifact replays the
	 * embedded dump the file phase extracted, honoring the dump banner's
	 * prefix, and completes - then sweeps the dump files off the site.
	 */
	public function test_full_archive_restore_replays_embedded_dump() {
		$task_id = $this->make_task(
			'full',
			array( $this->make_full_archive_with_embedded_dump() ),
			array(
				'source_prefix'    => '',
				'has_foreign_keys' => false,
			)
		);
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertSame( 'restored-content', file_get_contents( $this->site . '/wp-content/uploads/restored.txt' ) );

		$config = $this->runner->fake_engine->last_replay_config;
		$this->assertSame( 'bn_', $config['source_prefix'], 'The embedded dump banner declares the source prefix.' );
		$this->assertSame( array( $this->site . '/wp-content/dbBackup-bn_items.sql.php' ), $config['files'] );

		// The dump files are restore byproducts: replayed, then swept.
		$this->assertFileDoesNotExist( $this->site . '/wp-content/dbBackup-bn_items.sql.php' );
		$this->assertFileDoesNotExist( $this->site . '/wp-content/dbBackup.sql' );
	}

	/**
	 * A bannerless embedded dump (older full backups) defaults its source
	 * prefix to the live prefix - the only sane default for a same-site
	 * restore.
	 */
	public function test_bannerless_embedded_dump_defaults_to_live_prefix() {
		$task_id = $this->make_task(
			'full',
			array( $this->make_full_archive_with_embedded_dump( true ) ),
			array(
				'source_prefix'    => '',
				'has_foreign_keys' => false,
			)
		);
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertSame( 'rt_', $this->runner->fake_engine->last_replay_config['source_prefix'] );
	}

	/**
	 * Stale dump litter a previous restore deposited is swept after the
	 * replay even when this restore carries its own db artifact.
	 */
	public function test_dump_litter_is_swept_after_replay() {
		mkdir( $this->site . '/wp-content', 0777, true );
		file_put_contents( $this->site . '/dbBackup-old.sql.php', 'stale' ); // phpcs:ignore -- fixture.
		file_put_contents( $this->site . '/dbBackup.sql', '' ); // phpcs:ignore -- fixture.
		file_put_contents( $this->site . '/wp-content/dbBackup-options.sql.php', 'stale' ); // phpcs:ignore -- fixture.

		$task_id = $this->make_task( 'db', array( $this->make_db_artifact() ) );
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertFileDoesNotExist( $this->site . '/dbBackup-old.sql.php' );
		$this->assertFileDoesNotExist( $this->site . '/dbBackup.sql' );
		$this->assertFileDoesNotExist( $this->site . '/wp-content/dbBackup-options.sql.php' );
	}

	/**
	 * No db artifact and no embedded dumps is a terminal failure with a
	 * clear message, not a silent files-only restore.
	 */
	public function test_no_db_artifact_and_no_embedded_dump_fails() {
		$task_id = $this->make_task( 'full', array( $this->make_part_artifact() ) );
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );

		$payload = $this->spine->status_payload( $task );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_DB_REPLAY, $payload['error_code'] );
		$this->assertStringContainsString( 'No database artifact', $payload['error'] );
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
	 * Cancelling an idle restore task lands it terminal failed with the
	 * cancel code and runs the same cleanup as a failure: tmp tables
	 * dropped, maintenance lifted, scratch removed, old tables kept.
	 */
	public function test_cancel_idle_restore_task_lands_failed_with_cancel_code() {
		$task_id = $this->make_task( 'db', array( $this->make_db_artifact() ) );

		$outcome = $this->runner->cancel_restore_task( $task_id );

		$this->assertSame( 'ok', $outcome['result'] );

		$task = $this->spine->get_task( $task_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );

		$payload = $this->spine->status_payload( $task );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_CANCELLED, $payload['error_code'] );

		$calls = $this->runner->fake_engine->calls;
		$this->assertContains( 'cleanup_tmp_tables', $calls );
		$this->assertContains( 'exit_maintenance', $calls );
		$this->assertNotContains( 'drop_old_tables', $calls );
		$this->assertDirectoryDoesNotExist( $this->dir . '/restore-' . $task_id );
	}

	/**
	 * Cancel never breaks a live lease: while another runner owns the
	 * task the cancel reports busy and changes nothing.
	 */
	public function test_cancel_while_lease_held_returns_busy() {
		$task_id = $this->make_task( 'db', array( $this->make_db_artifact() ) );
		$task    = $this->spine->get_task( $task_id );
		$this->assertTrue( $this->spine->acquire_lease( $task_id, 'other-runner', (int) $task->version ) );

		$outcome = $this->runner->cancel_restore_task( $task_id );

		$this->assertSame( 'busy', $outcome['result'] );
		$task = $this->spine->get_task( $task_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_QUEUED, $task->phase );
	}

	/**
	 * Cancelling a finished task is a no-op that reports the terminal
	 * phase instead of failing a completed restore.
	 */
	public function test_cancel_terminal_task_reports_already_terminal() {
		$task_id = $this->make_task( 'db', array( $this->make_db_artifact() ) );
		$this->drive( $task_id );

		$outcome = $this->runner->cancel_restore_task( $task_id );

		$this->assertSame( 'already_terminal', $outcome['result'] );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $outcome['phase'] );

		$task = $this->spine->get_task( $task_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
	}

	/**
	 * Cancelling an unknown or non-restore task reports an error.
	 */
	public function test_cancel_unknown_task_reports_error() {
		$outcome = $this->runner->cancel_restore_task( 'no-such-task' );

		$this->assertArrayHasKey( 'error', $outcome );
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

	/**
	 * The restore runner owns the files-only restore type, so the
	 * watchdog routes its stalled tasks here.
	 */
	public function test_is_restore_type_includes_restore_files() {
		$this->assertTrue( MainWP_Child_Restore_Task_Runner::is_restore_type( 'restore_files' ) );
	}

	/**
	 * A files sub_type creates a restore_files task.
	 */
	public function test_create_restore_files_task_creates_restore_files_type() {
		$task_id = $this->make_task( 'files', array( $this->make_files_archive_artifact() ) );
		$task    = $this->spine->get_task( $task_id );

		$this->assertSame( 'restore_files', $task->type );
	}

	/**
	 * A files-only restore extracts the archive and applies tombstones,
	 * completes through the verify phase, and never touches a database
	 * phase: no replay, no swap, no tmp-table cleanup, no maintenance
	 * mode.
	 */
	public function test_files_only_restore_pipeline_completes_without_db_phases() {
		file_put_contents( $this->site . '/stale.txt', 'old' ); // phpcs:ignore -- fixture.

		$task_id = $this->make_task(
			'files',
			array( $this->make_files_archive_artifact(), $this->make_tombstones_artifact() )
		);

		// Task creation runs the idempotency sweep for every restore
		// type; the assertion below is about the pipeline phases.
		$this->runner->fake_engine->calls = array();

		$task = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertSame( 'restored-content', file_get_contents( $this->site . '/wp-content/uploads/restored.txt' ) );
		$this->assertFileDoesNotExist( $this->site . '/stale.txt' );

		$calls = $this->runner->fake_engine->calls;
		$this->assertContains( 'verify_row_counts', $calls );
		foreach ( $calls as $call ) {
			$this->assertStringNotContainsString( 'replay_slice', $call );
			$this->assertStringNotContainsString( 'swap_tables', $call );
			$this->assertStringNotContainsString( 'cleanup_tmp_tables', $call );
			$this->assertStringNotContainsString( 'exit_maintenance', $call );
		}

		// Scratch staging is gone after a verified restore.
		$this->assertDirectoryDoesNotExist( $this->dir . '/restore-' . $task_id );
	}

	/**
	 * Swap in the cloud-pull runner, keeping the pinned dirs and engine.
	 *
	 * @return Cloud_Pull_Test_Restore_Runner
	 */
	protected function use_cloud_pull_runner() {
		$runner                  = new Cloud_Pull_Test_Restore_Runner();
		$runner->test_backup_dir = $this->dir;
		$runner->test_site_root  = $this->site;
		$runner->fake_engine     = $this->runner->fake_engine;
		$this->runner            = $runner;
		return $runner;
	}

	/**
	 * Build the db artifact, capture its bytes, and remove the local
	 * copy so adoption cannot satisfy it - only the cloud has it.
	 *
	 * @return array { artifact, bytes }
	 */
	protected function make_cloud_only_db_artifact() {
		$artifact = $this->make_db_artifact();
		$bytes    = file_get_contents( $this->dir . '/db.zip' );
		unlink( $this->dir . '/db.zip' );
		return array(
			'artifact' => $artifact,
			'bytes'    => $bytes,
		);
	}

	/**
	 * A restore with a whole-file pull manifest downloads the artifact
	 * from the cloud, verifies it, and completes the full pipeline
	 * without any wire transfer.
	 */
	public function test_cloud_pull_whole_artifact_restore_completes() {
		$runner = $this->use_cloud_pull_runner();
		$cloud  = $this->make_cloud_only_db_artifact();
		$url    = 'https://bucket.example/4/42/files/db.zip?X-Amz-Signature=test';

		$runner->pull_responses[ $url ] = $cloud['bytes'];

		$task_id = $this->make_task(
			'db',
			array( $cloud['artifact'] ),
			array(
				'pull_manifest' => array(
					'mode'      => 'whole_files',
					'artifacts' => array(
						array(
							'file'   => 'db.zip',
							'sha256' => $cloud['artifact']['sha256'],
							'size'   => $cloud['artifact']['size'],
							'url'    => $url,
						),
					),
				),
			)
		);
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertContains( $url, $runner->pulled_urls );
	}

	/**
	 * Pulled bytes that fail the artifact hash land the task terminal
	 * failed with the cloud-pull error code, and the error never echoes
	 * the presigned URL.
	 */
	public function test_cloud_pull_bad_hash_fails_with_pull_code() {
		$runner = $this->use_cloud_pull_runner();
		$cloud  = $this->make_cloud_only_db_artifact();
		$url    = 'https://bucket.example/db.zip?X-Amz-Signature=secret';

		$runner->pull_responses[ $url ] = 'garbage-bytes';

		$task_id = $this->make_task(
			'db',
			array( $cloud['artifact'] ),
			array(
				'pull_manifest' => array(
					'mode'      => 'whole_files',
					'artifacts' => array(
						array(
							'file'   => 'db.zip',
							'sha256' => $cloud['artifact']['sha256'],
							'size'   => $cloud['artifact']['size'],
							'url'    => $url,
						),
					),
				),
			)
		);
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );

		$payload = $this->spine->status_payload( $task );
		$this->assertSame( MainWP_Backup_Error::ERR_CLOUD_PULL_FAILED, $payload['error_code'] );
		$this->assertStringNotContainsString( 'X-Amz', $payload['error'], 'The presigned URL must never appear in errors.' );
	}

	/**
	 * A failed download lands terminal failed with the cloud-pull error
	 * code so the dashboard falls back to the staged transfer.
	 */
	public function test_cloud_pull_download_failure_fails_with_pull_code() {
		$runner = $this->use_cloud_pull_runner();
		$cloud  = $this->make_cloud_only_db_artifact();
		$url    = 'https://bucket.example/db.zip?X-Amz-Signature=test';

		$runner->pull_responses[ $url ] = false;

		$task_id = $this->make_task(
			'db',
			array( $cloud['artifact'] ),
			array(
				'pull_manifest' => array(
					'mode'      => 'whole_files',
					'artifacts' => array(
						array(
							'file'   => 'db.zip',
							'sha256' => $cloud['artifact']['sha256'],
							'size'   => $cloud['artifact']['size'],
							'url'    => $url,
						),
					),
				),
			)
		);
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );
		$this->assertSame( MainWP_Backup_Error::ERR_CLOUD_PULL_FAILED, $this->spine->status_payload( $task )['error_code'] );
	}

	/**
	 * A chunked pull manifest downloads every piece, verifies each chunk
	 * hash, reassembles the artifact, verifies the whole, and completes.
	 */
	public function test_cloud_pull_chunked_artifact_reassembles_and_completes() {
		$runner = $this->use_cloud_pull_runner();
		$cloud  = $this->make_cloud_only_db_artifact();

		$split  = (int) ceil( strlen( $cloud['bytes'] ) / 2 );
		$piece1 = substr( $cloud['bytes'], 0, $split );
		$piece2 = substr( $cloud['bytes'], $split );
		$url1   = 'https://bucket.example/chunks/' . hash( 'sha256', $piece1 ) . '.bin?X-Amz-Signature=a';
		$url2   = 'https://bucket.example/chunks/' . hash( 'sha256', $piece2 ) . '.bin?X-Amz-Signature=b';

		$runner->pull_responses[ $url1 ] = $piece1;
		$runner->pull_responses[ $url2 ] = $piece2;

		$task_id = $this->make_task(
			'db',
			array( $cloud['artifact'] ),
			array(
				'pull_manifest' => array(
					'mode'      => 'chunked',
					'artifacts' => array(
						array(
							'file'   => 'db.zip',
							'sha256' => $cloud['artifact']['sha256'],
							'size'   => $cloud['artifact']['size'],
							'chunks' => array(
								array(
									'hash' => hash( 'sha256', $piece1 ),
									'url'  => $url1,
								),
								array(
									'hash' => hash( 'sha256', $piece2 ),
									'url'  => $url2,
								),
							),
						),
					),
				),
			)
		);
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertSame( array( $url1, $url2 ), $runner->pulled_urls );
	}

	/**
	 * Chunk pieces verify with the algorithm the manifest declares:
	 * production uploads content-address chunks with xxh128 where
	 * available, and the pull must honor that.
	 */
	public function test_cloud_pull_chunked_verifies_with_declared_algo() {
		if ( ! in_array( 'xxh128', hash_algos(), true ) ) {
			$this->markTestSkipped( 'xxh128 is not available on this PHP.' );
		}

		$runner = $this->use_cloud_pull_runner();
		$cloud  = $this->make_cloud_only_db_artifact();

		$split  = (int) ceil( strlen( $cloud['bytes'] ) / 2 );
		$piece1 = substr( $cloud['bytes'], 0, $split );
		$piece2 = substr( $cloud['bytes'], $split );
		$url1   = 'https://bucket.example/chunks/' . hash( 'xxh128', $piece1 ) . '.bin?sig=a';
		$url2   = 'https://bucket.example/chunks/' . hash( 'xxh128', $piece2 ) . '.bin?sig=b';

		$runner->pull_responses[ $url1 ] = $piece1;
		$runner->pull_responses[ $url2 ] = $piece2;

		$task_id = $this->make_task(
			'db',
			array( $cloud['artifact'] ),
			array(
				'pull_manifest' => array(
					'mode'      => 'chunked',
					'artifacts' => array(
						array(
							'file'   => 'db.zip',
							'sha256' => $cloud['artifact']['sha256'],
							'size'   => $cloud['artifact']['size'],
							'chunks' => array(
								array(
									'hash' => hash( 'xxh128', $piece1 ),
									'algo' => 'xxh128',
									'url'  => $url1,
								),
								array(
									'hash' => hash( 'xxh128', $piece2 ),
									'algo' => 'xxh128',
									'url'  => $url2,
								),
							),
						),
					),
				),
			)
		);
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
	}

	/**
	 * The sanitizer accepts xxh128 chunk hashes with their declared
	 * algorithm and rejects algorithms it does not know.
	 */
	public function test_sanitize_pull_manifest_chunk_algos() {
		$artifacts = array(
			array(
				'file'       => 'big.zip',
				'adopt_only' => false,
			),
		);
		$sha       = str_repeat( 'a', 64 );
		$manifest  = function ( $chunk ) use ( $sha ) {
			return array(
				'artifacts' => array(
					array(
						'file'   => 'big.zip',
						'sha256' => $sha,
						'size'   => 10,
						'chunks' => array( $chunk ),
					),
				),
			);
		};

		if ( in_array( 'xxh128', hash_algos(), true ) ) {
			$clean = MainWP_Child_Restore_Task_Runner::sanitize_pull_manifest(
				$manifest(
					array(
						'hash' => str_repeat( '1', 32 ),
						'algo' => 'xxh128',
						'url'  => 'https://bucket.example/c.bin',
					)
				),
				$artifacts
			);
			$this->assertNotNull( $clean );
			$this->assertSame( 'xxh128', $clean['artifacts'][0]['chunks'][0]['algo'] );
		}

		$this->assertNull(
			MainWP_Child_Restore_Task_Runner::sanitize_pull_manifest(
				$manifest(
					array(
						'hash' => str_repeat( '1', 32 ),
						'algo' => 'md5',
						'url'  => 'https://bucket.example/c.bin',
					)
				),
				$artifacts
			),
			'Unknown chunk algorithms must be rejected.'
		);

		$this->assertNull(
			MainWP_Child_Restore_Task_Runner::sanitize_pull_manifest(
				$manifest(
					array(
						'hash' => str_repeat( '1', 32 ),
						'url'  => 'https://bucket.example/c.bin',
					)
				),
				$artifacts
			),
			'A 32-hex hash without a declared algorithm must be rejected.'
		);
	}

	/**
	 * A pull manifest naming a file the task was not created with is
	 * rejected without downloading anything.
	 */
	public function test_cloud_pull_rejects_unknown_basename() {
		$runner = $this->use_cloud_pull_runner();
		$cloud  = $this->make_cloud_only_db_artifact();

		$task_id = $this->make_task(
			'db',
			array( $cloud['artifact'] ),
			array(
				'pull_manifest' => array(
					'mode'      => 'whole_files',
					'artifacts' => array(
						array(
							'file'   => 'evil.zip',
							'sha256' => $cloud['artifact']['sha256'],
							'size'   => 4,
							'url'    => 'https://bucket.example/evil.zip',
						),
					),
				),
			)
		);
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );
		$this->assertSame( MainWP_Backup_Error::ERR_CLOUD_PULL_FAILED, $this->spine->status_payload( $task )['error_code'] );
		$this->assertSame( array(), $runner->pulled_urls, 'Nothing may download for a rejected manifest.' );
	}

	/**
	 * The pull-manifest sanitizer accepts well-formed whole-file and
	 * chunked manifests whose names belong to the task's artifact list.
	 */
	public function test_sanitize_pull_manifest_accepts_valid_manifests() {
		$artifacts = array(
			array(
				'file'       => 'db.zip',
				'adopt_only' => false,
			),
			array(
				'file'       => 'big.zip',
				'adopt_only' => false,
			),
		);
		$sha       = str_repeat( 'a', 64 );

		$clean = MainWP_Child_Restore_Task_Runner::sanitize_pull_manifest(
			array(
				'artifacts' => array(
					array(
						'file'   => 'db.zip',
						'sha256' => $sha,
						'size'   => 10,
						'url'    => 'https://bucket.example/db.zip?X-Amz-Signature=x',
					),
					array(
						'file'   => 'big.zip',
						'sha256' => $sha,
						'size'   => 20,
						'chunks' => array(
							array(
								'hash' => $sha,
								'url'  => 'https://bucket.example/chunks/aa.bin?sig=1',
							),
						),
					),
				),
			),
			$artifacts
		);

		$this->assertNotNull( $clean );
		$this->assertCount( 2, $clean['artifacts'] );
		$this->assertSame( 'db.zip', $clean['artifacts'][0]['file'] );
		$this->assertSame( 10, $clean['artifacts'][0]['size'] );
		$this->assertCount( 1, $clean['artifacts'][1]['chunks'] );
	}

	/**
	 * The sanitizer rejects manifests wholesale on any violation: a
	 * non-https URL, an unknown basename, a malformed hash, an
	 * adopt-only target, or a shape that is not a manifest.
	 */
	public function test_sanitize_pull_manifest_rejects_violations() {
		$sha       = str_repeat( 'a', 64 );
		$artifacts = array(
			array(
				'file'       => 'db.zip',
				'adopt_only' => false,
			),
			array(
				'file'       => 'old.zip',
				'adopt_only' => true,
			),
		);
		$entry     = function ( $over = array() ) use ( $sha ) {
			return array(
				'artifacts' => array(
					array_merge(
						array(
							'file'   => 'db.zip',
							'sha256' => $sha,
							'size'   => 10,
							'url'    => 'https://bucket.example/db.zip',
						),
						$over
					),
				),
			);
		};

		$this->assertNull( MainWP_Child_Restore_Task_Runner::sanitize_pull_manifest( 'not-an-array', $artifacts ) );
		$this->assertNull( MainWP_Child_Restore_Task_Runner::sanitize_pull_manifest( array( 'artifacts' => array() ), $artifacts ) );
		$this->assertNull( MainWP_Child_Restore_Task_Runner::sanitize_pull_manifest( $entry( array( 'url' => 'http://bucket.example/db.zip' ) ), $artifacts ), 'Plain http URLs must be rejected.' );
		$this->assertNull( MainWP_Child_Restore_Task_Runner::sanitize_pull_manifest( $entry( array( 'file' => 'evil.zip' ) ), $artifacts ), 'Unknown basenames must be rejected.' );
		$this->assertNull( MainWP_Child_Restore_Task_Runner::sanitize_pull_manifest( $entry( array( 'file' => 'old.zip' ) ), $artifacts ), 'Adopt-only artifacts must never be pull targets.' );
		$this->assertNull( MainWP_Child_Restore_Task_Runner::sanitize_pull_manifest( $entry( array( 'sha256' => 'nothex' ) ), $artifacts ), 'Malformed hashes must be rejected.' );
		$this->assertNull( MainWP_Child_Restore_Task_Runner::sanitize_pull_manifest( $entry( array( 'url' => null ) ), $artifacts ), 'A whole-file entry without a URL must be rejected.' );
		$this->assertNull(
			MainWP_Child_Restore_Task_Runner::sanitize_pull_manifest(
				$entry(
					array(
						'url'    => null,
						'chunks' => array(
							array(
								'hash' => $sha,
								'url'  => 'ftp://bucket.example/aa.bin',
							),
						),
					)
				),
				$artifacts
			),
			'Non-https chunk URLs must be rejected.'
		);
	}

	/**
	 * The disk preflight fails the task before any download when free
	 * space cannot hold the manifest's artifacts.
	 */
	public function test_cloud_pull_disk_preflight_fails_early() {
		$runner = $this->use_cloud_pull_runner();
		$cloud  = $this->make_cloud_only_db_artifact();

		$runner->fake_free_space = 10.0;

		$task_id = $this->make_task(
			'db',
			array( $cloud['artifact'] ),
			array(
				'pull_manifest' => array(
					'mode'      => 'whole_files',
					'artifacts' => array(
						array(
							'file'   => 'db.zip',
							'sha256' => $cloud['artifact']['sha256'],
							'size'   => $cloud['artifact']['size'],
							'url'    => 'https://bucket.example/db.zip',
						),
					),
				),
			)
		);
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );
		$this->assertSame( MainWP_Backup_Error::ERR_DISK_FULL, $this->spine->status_payload( $task )['error_code'] );
		$this->assertSame( array(), $runner->pulled_urls, 'Nothing may download when disk space is insufficient.' );
	}
}
