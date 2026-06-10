<?php
/**
 * Class BackupTaskIncrementalTest
 *
 * @package Mainwp_Child
 */

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
			'emit_manifest_sidecars'   => array(
				'file'  => 'files.list',
				'size'  => 123,
				'count' => 2,
			),
			'promote_manifest_sidecars' => true,
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
		$this->assertContains( 'emit_manifest_sidecars', $this->runner->seam_names() );
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
		$this->assertNotContains( 'emit_manifest_sidecars', $this->runner->seam_names() );
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
		$this->runner->stubs['emit_manifest_sidecars'] = new \RuntimeException( 'walk failed' );

		$task_id = $this->create_task( 'full', array( 'emit_manifest' => true ) );
		$task    = $this->advance( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );

		$payload = MainWP_Child_Backup_Task::instance()->status_payload( $task, $this->backup_dir );
		$this->assertSame( 'backup-artifact.tar', $payload['file'] );
		$this->assertArrayNotHasKey( 'artifacts', $payload );
	}
}
