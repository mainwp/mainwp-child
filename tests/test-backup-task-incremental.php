<?php
/**
 * Class BackupTaskIncrementalTest
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Child_Backup_Incremental_Zip_Writer;
use MainWP\Child\MainWP_Child_Backup_Task;
use MainWP\Child\MainWP_Exception;

require_once __DIR__ . '/test-backup-task-runner.php';

/**
 * Tests for the incremental backup type on the task machine: type
 * acceptance, phase routing, and the base-run manifest emission a full
 * backup performs when the Dashboard marks it as the base of an
 * incremental chain.
 */
class BackupTaskIncrementalTest extends WP_UnitTestCase {

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

		$this->backup_dir = sys_get_temp_dir() . '/mainwp-incr-test-' . uniqid();
		mkdir( $this->backup_dir );

		$artifact = $this->backup_dir . '/backup-artifact.tar';
		file_put_contents( $artifact, str_repeat( 'a', 2048 ) ); // phpcs:ignore -- test fixture.

		$this->runner                  = new Testable_Task_Runner();
		$this->runner->test_backup_dir = $this->backup_dir;
		$this->runner->stubs           = array(
			'tier1_available'          => true,
			'dump_tier1'               => array( $this->backup_dir . '/dbBackup-table1.sql.php' ),
			'archive_db_artifact'      => $artifact,
			'run_file_archiver'        => array(
				'file'     => 'backup-artifact.tar',
				'filesize' => 2048,
			),
			'walk_manifest_slice'      => array(
				'done'  => true,
				'file'  => 'files.list',
				'size'  => 123,
				'count' => 2,
			),
			'promote_manifest_sidecars' => true,
		);
	}

	/**
	 * Site-tree fixture for pack tests.
	 *
	 * @var string
	 */
	protected $site;

	/**
	 * Drop spine rows and the scratch dirs.
	 */
	public function tear_down() {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . MainWP_Child_Backup_Task::instance()->get_table_name() ); // phpcs:ignore -- test cleanup.
		$this->rrmdir( $this->backup_dir );
		if ( null !== $this->site ) {
			$this->rrmdir( $this->site );
		}
		parent::tear_down();
	}

	/**
	 * Recursive fixture cleanup.
	 *
	 * @param string $dir Directory.
	 */
	private function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				$this->rrmdir( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}

	/**
	 * Create a task with standard params.
	 *
	 * @param string $type   Backup type.
	 * @param array  $extra  Extra params merged into the cursor params.
	 *
	 * @return string Task id.
	 */
	protected function create_task( $type, $extra = array() ) {
		return MainWP_Child_Backup_Task::instance()->create_task(
			$type,
			array(
				'params' => array_merge(
					array(
						'ext'                => 'tar',
						'file'               => '',
						'excludes'           => array(),
						'excludezip'         => false,
						'excludenonwp'       => false,
						'file_descriptors'   => 0,
						'loadFilesBeforeZip' => true,
					),
					$extra
				),
			)
		);
	}

	/**
	 * Advance helper.
	 *
	 * @param string $task_id Task id.
	 *
	 * @return object Fresh task row.
	 */
	protected function advance( $task_id ) {
		$task = MainWP_Child_Backup_Task::instance()->get_task( $task_id );
		return $this->runner->advance_one_slice( $task, 'dashboard' );
	}

	/**
	 * The incremental type passes request validation and creates a task.
	 */
	public function test_incremental_type_accepted_by_create() {
		$task_id = $this->runner->create_task_for_params( 'incremental', array() );

		$this->assertNotEmpty( $task_id );
		$task = MainWP_Child_Backup_Task::instance()->get_task( $task_id );
		$this->assertSame( 'incremental', $task->type );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_QUEUED, $task->phase );
	}

	/**
	 * Unknown types are still rejected.
	 */
	public function test_unknown_type_still_rejected() {
		$this->expectException( MainWP_Exception::class );
		$this->runner->create_task_for_params( 'bogus', array() );
	}

	/**
	 * An incremental task runs every work phase a full backup runs: the
	 * database is dumped fresh on every incremental run, so each snapshot
	 * restores to its own point in time.
	 */
	public function test_incremental_phase_order_matches_full() {
		$order = array( MainWP_Child_Backup_Task::PHASE_QUEUED );
		$phase = MainWP_Child_Backup_Task::PHASE_QUEUED;

		while ( null !== ( $phase = MainWP_Child_Backup_Task::next_phase( $phase, 'incremental' ) ) ) {
			$order[] = $phase;
		}

		$this->assertSame(
			array(
				MainWP_Child_Backup_Task::PHASE_QUEUED,
				MainWP_Child_Backup_Task::PHASE_DUMPING_DB,
				MainWP_Child_Backup_Task::PHASE_ARCHIVING_FILES,
				MainWP_Child_Backup_Task::PHASE_UPLOADING,
				MainWP_Child_Backup_Task::PHASE_SUBMITTING,
				MainWP_Child_Backup_Task::PHASE_COMPLETED,
			),
			$order
		);
	}

	/**
	 * A full run flagged as a chain base emits the manifest sidecars, lists
	 * the manifest in the completed payload's artifacts, keeps the archive
	 * as the top-level file for older dashboards, and promotes the staged
	 * sidecars when the task completes.
	 */
	public function test_full_run_with_emit_manifest_records_artifacts() {
		$task_id = $this->create_task( 'full', array( 'emit_manifest' => true ) );
		$task    = $this->advance( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertContains( 'walk_manifest_slice', $this->runner->seam_names() );
		$this->assertContains( 'promote_manifest_sidecars', $this->runner->seam_names() );

		$payload = MainWP_Child_Backup_Task::instance()->status_payload( $task, $this->backup_dir );

		$this->assertSame( 'backup-artifact.tar', $payload['file'], 'Back-compat: the archive stays the top-level artifact.' );
		$this->assertSame( 2048, $payload['size'] );

		$this->assertArrayHasKey( 'artifacts', $payload );
		$roles = array();
		foreach ( $payload['artifacts'] as $artifact ) {
			$this->assertArrayHasKey( 'file', $artifact );
			$this->assertArrayHasKey( 'size', $artifact );
			$this->assertArrayHasKey( 'role', $artifact );
			$roles[ $artifact['role'] ] = $artifact;
		}

		$this->assertSame( 'backup-artifact.tar', $roles['full']['file'] );
		$this->assertSame( 'files.list', $roles['manifest']['file'] );
		$this->assertSame( 123, $roles['manifest']['size'] );
	}

	/**
	 * A plain full run never pays for the manifest walk and exposes no
	 * artifacts list.
	 */
	public function test_full_run_without_emit_manifest_skips_manifest() {
		$task_id = $this->create_task( 'full' );
		$task    = $this->advance( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertNotContains( 'walk_manifest_slice', $this->runner->seam_names() );
		$this->assertNotContains( 'promote_manifest_sidecars', $this->runner->seam_names() );

		$payload = MainWP_Child_Backup_Task::instance()->status_payload( $task, $this->backup_dir );
		$this->assertArrayNotHasKey( 'artifacts', $payload );
	}

	/**
	 * A manifest emission failure never fails the backup itself - the
	 * archive completed; the chain just has no base manifest, which the
	 * next incremental resolves by forcing a full.
	 */
	public function test_manifest_emission_failure_keeps_backup_completed() {
		$this->runner->stubs['walk_manifest_slice'] = new \RuntimeException( 'walk failed' );

		$task_id = $this->create_task( 'full', array( 'emit_manifest' => true ) );
		$task    = $this->advance( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );

		$payload = MainWP_Child_Backup_Task::instance()->status_payload( $task, $this->backup_dir );
		$this->assertSame( 'backup-artifact.tar', $payload['file'] );
		$this->assertArrayNotHasKey( 'artifacts', $payload );
	}

	/**
	 * While the manifest walk is mid-flight, the running scanned count from the
	 * walk cursor surfaces in the status payload so the dashboard can render a
	 * climbing number before the walk's totals are known.
	 */
	public function test_walk_scanned_count_surfaces_mid_walk() {
		$this->runner->stubs['walk_manifest_slice'] = array(
			'done'        => false,
			'cursor'      => array(
				'count' => 1234,
				'phase' => 'enumerate',
			),
			'count'       => 1234,
			'total_bytes' => 0,
		);

		$task_id = $this->create_task( 'incremental' );
		$task    = $this->advance( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_ARCHIVING_FILES, $task->phase, 'The walk yields while still scanning.' );

		$payload = MainWP_Child_Backup_Task::instance()->status_payload( $task, $this->backup_dir );
		$this->assertArrayHasKey( 'progress', $payload );
		$this->assertSame( 1234, $payload['progress']['files_scanned'], 'The live scanned count must surface mid-walk.' );
	}

	// ---- incremental run fixtures -----------------------------------------

	/**
	 * Build a site tree (added/changed/carried files plus a plugin file),
	 * point the runner's pack loop at it, and wire the emit seam to stage
	 * a real files.list from the tree's actual stats.
	 *
	 * @return void
	 */
	protected function arrange_incremental_fixtures() {
		$this->site = sys_get_temp_dir() . '/mainwp-incr-site-' . uniqid();
		mkdir( $this->site );
		mkdir( $this->site . '/wp-content' );
		mkdir( $this->site . '/wp-content/plugins' );
		mkdir( $this->site . '/wp-content/plugins/hello' );

		file_put_contents( $this->site . '/added.php', 'added-file' ); // phpcs:ignore -- test fixture.
		file_put_contents( $this->site . '/changed.php', 'changed-file' ); // phpcs:ignore -- test fixture.
		file_put_contents( $this->site . '/carried.php', 'carried-file' ); // phpcs:ignore -- test fixture.
		file_put_contents( $this->site . '/wp-content/plugins/hello/hello.php', '<?php // plugin' ); // phpcs:ignore -- test fixture.

		$this->runner->test_site_root = rtrim( $this->site, '/' ) . '/';

		$site = $this->site;

		$this->runner->stubs['walk_manifest_slice'] = function ( $params, $backup_dir, $cursor, $deadline ) use ( $site ) {
			unset( $params, $cursor, $deadline );
			$relpaths = array( 'added.php', 'carried.php', 'changed.php', 'wp-content/plugins/hello/hello.php' );
			$lines    = '';
			$cache    = '';
			foreach ( $relpaths as $relpath ) {
				$abs    = $site . '/' . $relpath;
				$lines .= $relpath . "\t" . filesize( $abs ) . "\t" . filemtime( $abs ) . "\n";
				$cache .= \MainWP\Child\MainWP_Child_Backup_File_Walker::classify_component( $relpath ) . "\t" . $relpath . "\n";
			}
			file_put_contents( $backup_dir . '/files.list.staged', $lines ); // phpcs:ignore -- test fixture.
			file_put_contents( $backup_dir . '/paths.cache.staged', $cache ); // phpcs:ignore -- test fixture.
			return array(
				'done'  => true,
				'file'  => 'files.list',
				'size'  => (int) filesize( $backup_dir . '/files.list.staged' ),
				'count' => count( $relpaths ),
			);
		};
	}

	/**
	 * The parent manifest content matching the fixture tree: carried.php
	 * matches the disk stats, changed.php has a stale size, deleted.php no
	 * longer exists on disk.
	 *
	 * @return string Parent files.list content.
	 */
	protected function parent_manifest_content() {
		return 'carried.php' . "\t" . filesize( $this->site . '/carried.php' ) . "\t" . filemtime( $this->site . '/carried.php' ) . "\n"
			. 'changed.php' . "\t" . ( filesize( $this->site . '/changed.php' ) + 7 ) . "\t" . filemtime( $this->site . '/changed.php' ) . "\n"
			. 'deleted.php' . "\t" . '11' . "\t" . '1000000000' . "\n";
	}

	/**
	 * Entry names across all recorded part archives.
	 *
	 * @param array $payload Completed status payload.
	 *
	 * @return string[] Entry names from every role=part artifact.
	 */
	protected function packed_entries( $payload ) {
		$entries = array();
		foreach ( $payload['artifacts'] as $artifact ) {
			if ( 'part' !== $artifact['role'] ) {
				continue;
			}
			$zip = new ZipArchive();
			$this->assertTrue( $zip->open( $this->backup_dir . '/' . $artifact['file'] ), "Part {$artifact['file']} must be a valid archive." );
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$entries[] = $zip->getNameIndex( $i );
			}
			$zip->close();
		}
		sort( $entries, SORT_STRING );
		return $entries;
	}

	/**
	 * Artifacts keyed by role (parts collected into a list).
	 *
	 * @param array $payload Completed status payload.
	 *
	 * @return array Role map.
	 */
	protected function artifacts_by_role( $payload ) {
		$roles = array( 'part' => array() );
		foreach ( $payload['artifacts'] as $artifact ) {
			if ( 'part' === $artifact['role'] ) {
				$roles['part'][] = $artifact;
			} else {
				$roles[ $artifact['role'] ] = $artifact;
			}
		}
		return $roles;
	}

	// ---- incremental run tests --------------------------------------------

	/**
	 * An incremental run against the child-local parent manifest packs
	 * exactly the new and changed files into per-component parts, lists
	 * the deletion in tombstones.list, ships the fresh database dump, and
	 * keeps the manifest as the top-level artifact.
	 */
	public function test_incremental_run_packs_only_changes() {
		$this->arrange_incremental_fixtures();
		file_put_contents( $this->backup_dir . '/files.list', $this->parent_manifest_content() ); // phpcs:ignore -- test fixture: previous snapshot manifest.

		$task_id = $this->create_task( 'incremental' );

		$task = $this->advance( $task_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_ARCHIVING_FILES, $task->phase, 'The diff step yields so packing starts with a fresh budget.' );

		$task = $this->advance( $task_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );

		$payload = MainWP_Child_Backup_Task::instance()->status_payload( $task, $this->backup_dir );

		$this->assertSame( 'files.list', $payload['file'], 'The manifest is the top-level artifact for incremental runs.' );

		$roles = $this->artifacts_by_role( $payload );
		$this->assertSame( 'files.list', $roles['manifest']['file'] );
		$this->assertSame( 'tombstones.list', $roles['tombstones']['file'] );
		$this->assertSame( 'backup-artifact.tar', $roles['db']['file'], 'The fresh database dump ships with every incremental run.' );

		$this->assertSame(
			array( 'added.php', 'changed.php', 'wp-content/plugins/hello/hello.php' ),
			$this->packed_entries( $payload ),
			'Exactly the new and changed files are packed.'
		);

		// Parts are namespaced by task (component.<task-token>.part001.zip) so
		// two tasks never collide on the same component+index.
		$part_files = array_column( $roles['part'], 'file' );
		$this->assertNotEmpty( preg_grep( '#^core\..+\.part001\.zip$#', $part_files ), 'A namespaced core part001 must be recorded.' );
		$this->assertNotEmpty( preg_grep( '#^plugins\..+\.part001\.zip$#', $part_files ), 'A namespaced plugins part001 must be recorded.' );

		$this->assertSame( "deleted.php\n", file_get_contents( $this->backup_dir . '/tombstones.list' ) );
	}

	/**
	 * With no parent manifest anywhere the task fails with the
	 * base-missing code - the Dashboard reruns the site as a full backup.
	 * The child never silently switches type.
	 */
	public function test_incremental_without_parent_fails_with_base_missing() {
		$this->arrange_incremental_fixtures();

		$task_id = $this->create_task( 'incremental' );
		$task    = $this->advance( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );

		$payload = MainWP_Child_Backup_Task::instance()->status_payload( $task, $this->backup_dir );
		$this->assertSame( 'mainwp_backup_incremental_base_missing', $payload['error_code'] );
	}

	/**
	 * A Dashboard-pushed parent manifest wins over the local sidecar and
	 * is cleared from the persisted cursor as soon as it lands on disk -
	 * a megabytes-large base64 blob must never ride every checkpoint.
	 */
	public function test_pushed_parent_manifest_used_and_cleared() {
		$this->arrange_incremental_fixtures();

		$task_id = $this->create_task(
			'incremental',
			array( 'parent_manifest_b64' => base64_encode( $this->parent_manifest_content() ) )
		);

		$task = $this->advance( $task_id );
		$task = $this->advance( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertFileExists( $this->backup_dir . '/parent-files.list' );

		$payload = MainWP_Child_Backup_Task::instance()->status_payload( $task, $this->backup_dir );
		$this->assertArrayNotHasKey( 'parent_manifest_b64', $payload['sub_state']['params'] );
		$this->assertSame(
			array( 'added.php', 'changed.php', 'wp-content/plugins/hello/hello.php' ),
			$this->packed_entries( $payload )
		);
	}

	/**
	 * A budget bail mid-pack closes the open part, checkpoints the cursor,
	 * and the next slice resumes by appending - every changed file lands
	 * exactly once across the parts.
	 */
	public function test_pack_resumes_after_budget_bail() {
		$this->arrange_incremental_fixtures();
		file_put_contents( $this->backup_dir . '/files.list', $this->parent_manifest_content() ); // phpcs:ignore -- test fixture.

		$task_id = $this->create_task( 'incremental' );
		$task    = $this->advance( $task_id ); // Diff step.

		$checks = 0;
		$this->runner->stubs['pack_budget_exhausted'] = function () use ( &$checks ) {
			++$checks;
			return $checks > 1; // Pack one file, then bail.
		};

		$task = $this->advance( $task_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_ARCHIVING_FILES, $task->phase, 'The budget bail yields mid-pack.' );

		$sub_state = MainWP_Child_Backup_Task::instance()->read_sub_state( $task, $this->backup_dir );
		$this->assertSame( 1, $sub_state['pack_cursor']['line'] );
		$this->assertSame( 'core', $sub_state['pack_cursor']['component'] );
		$this->assertSame( 1, $sub_state['pack_cursor']['part_entry_count'] );

		unset( $this->runner->stubs['pack_budget_exhausted'] );

		$task = $this->advance( $task_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );

		$payload = MainWP_Child_Backup_Task::instance()->status_payload( $task, $this->backup_dir );
		$this->assertSame(
			array( 'added.php', 'changed.php', 'wp-content/plugins/hello/hello.php' ),
			$this->packed_entries( $payload ),
			'Resume must append, never duplicate or drop files.'
		);
	}

	/**
	 * A part torn after the checkpoint - killed mid-write, no central
	 * directory - is discarded on re-entry and rebuilt from the part's
	 * first file. Every changed file still lands exactly once.
	 */
	public function test_torn_part_rebuilt_on_resume() {
		$this->arrange_incremental_fixtures();
		file_put_contents( $this->backup_dir . '/files.list', $this->parent_manifest_content() ); // phpcs:ignore -- test fixture.

		$task_id = $this->create_task( 'incremental' );
		$task    = $this->advance( $task_id ); // Diff step.

		$checks = 0;
		$this->runner->stubs['pack_budget_exhausted'] = function () use ( &$checks ) {
			++$checks;
			return $checks > 1;
		};
		$task = $this->advance( $task_id ); // Packs one file, checkpoints.

		// Tear the checkpointed part: a kill mid-write leaves no central directory.
		// The name carries the per-task token, so derive it from the cursor
		// rather than the legacy core.part001.zip the writer no longer uses.
		$cursor = MainWP_Child_Backup_Task::instance()->read_sub_state( $task, $this->backup_dir )['pack_cursor'];
		$part   = MainWP_Child_Backup_Incremental_Zip_Writer::part_basename( $cursor['component'], (int) $cursor['part_index'], $task_id );
		$this->assertFileExists( $this->backup_dir . '/' . $part );
		file_put_contents( $this->backup_dir . '/' . $part, 'PK torn mid-write' ); // phpcs:ignore -- test fixture.

		unset( $this->runner->stubs['pack_budget_exhausted'] );

		$task = $this->advance( $task_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );

		$payload = MainWP_Child_Backup_Task::instance()->status_payload( $task, $this->backup_dir );
		$this->assertSame(
			array( 'added.php', 'changed.php', 'wp-content/plugins/hello/hello.php' ),
			$this->packed_entries( $payload ),
			'The torn part must be rebuilt from its first file with no duplicates.'
		);
	}
}
