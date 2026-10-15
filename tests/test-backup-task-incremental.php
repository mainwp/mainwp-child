<?php
/**
 * Class BackupTaskIncrementalTest
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Backup_Error;
use MainWP\Child\MainWP_Child_Backup_Mutex;
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

		$this->runner = new class() extends Testable_Task_Runner {
			protected function write_parent_manifest( $path, $decoded ) {
				if ( isset( $this->stubs['write_parent_manifest'] ) ) {
					return $this->stub( 'write_parent_manifest', array( $path, $decoded ) );
				}
				return parent::write_parent_manifest( $path, $decoded );
			}
		};
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
		$holder = MainWP_Child_Backup_Mutex::holder();
		if ( null !== $holder ) {
			MainWP_Child_Backup_Mutex::release( $holder['owner'] );
		}
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
		$task_id = MainWP_Child_Backup_Task::instance()->create_task(
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
		$this->assertTrue( MainWP_Child_Backup_Mutex::acquire( 'native', $task_id, 3600 ) );
		return $task_id;
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


	public function test_manifest_walk_metadata_matches_walker_inputs() {
		$params = array( 'emit_manifest' => true, 'excludes' => array( 'wp-content/private', 'wp-content/plugins/mainwp-child' ), 'excludenonwp' => 1, 'excludezip' => 1 );
		$walked = null;
		$this->runner->stubs['walk_manifest_slice'] = function ( $actual ) use ( &$walked ) {
			$walked = $actual;
			return array( 'done' => true, 'file' => 'files.list', 'size' => 123, 'count' => 2 );
		};
		$task = $this->advance( $this->create_task( 'full', $params ) );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$payload = MainWP_Child_Backup_Task::instance()->status_payload( $task, $this->backup_dir );
		$roles = $this->artifacts_by_role( $payload );
		$method = new ReflectionMethod( \MainWP\Child\MainWP_Child_Backup_Task_Runner::class, 'make_walker' );
		$method->setAccessible( true );
		$walker = $method->invoke( $this->runner, $walked, $this->backup_dir );
		foreach ( array( 'excludes', 'excludenonwp', 'excludezip' ) as $key ) {
			$property = new ReflectionProperty( $walker, $key );
			$property->setAccessible( true );
			$actual = $property->getValue( $walker );
			$this->assertSame( 'excludes' === $key ? $actual : (int) $actual, $roles['manifest']['walk'][ $key ] );
		}
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
	 * A full archive without a manifest cannot seed the incremental chain,
	 * so the Dashboard needs the walk failure code instead of an unusable base.
	 */
	public function test_manifest_emission_failure_fails_the_task() {
		$this->runner->stubs['walk_manifest_slice'] = new \RuntimeException( 'walk failed' );

		$task_id = $this->create_task( 'full', array( 'emit_manifest' => true ) );
		$task    = $this->advance( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );
		$sub_state = MainWP_Child_Backup_Task::instance()->read_sub_state( $task, $this->backup_dir );
		$this->assertSame( MainWP_Backup_Error::ERR_MANIFEST_WALK_FAILED, $sub_state['error']['code'] );

		$payload = MainWP_Child_Backup_Task::instance()->status_payload( $task, $this->backup_dir );
		$this->assertSame( MainWP_Backup_Error::ERR_MANIFEST_WALK_FAILED, $payload['error_code'] );
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

	public static function data_unarchived_artifact_metadata() {
		return array(
			'full' => array( 'full', 2 ),
			'incremental' => array( 'incremental', 2 ),
			'full at cap' => array( 'full', 1000 ),
			'incremental at cap' => array( 'incremental', 1000 ),
			'full overflow' => array( 'full', 1001 ),
			'incremental overflow' => array( 'incremental', 1001 ),
		);
	}

	/** @dataProvider data_unarchived_artifact_metadata */
	public function test_unarchived_artifact_metadata_uses_persisted_failures( $type, $count ) {
		$root = ABSPATH . 'unarchived-tree/';
		$this->runner->test_site_root = $root;
		$paths = array();
		for ( $i = 0; $i < $count; ++$i ) {
			$paths[] = 'wp-content/Host File..' . $i . '.php';
		}
		$task_id = $this->create_task( $type, array( 'emit_manifest' => true, 'excludes' => array( 'private' ), 'parent_manifest_b64' => base64_encode( "snapshot.txt\t1\t1\n" ) ) );
		$spine = MainWP_Child_Backup_Task::instance();
		$task = $spine->get_task( $task_id );
		$state = $spine->read_sub_state( $task, $this->backup_dir );
		$state['failed_add_paths'] = array_map( static function ( $path ) use ( $root ) { return $root . $path; }, $paths );
		$state['failed_add_paths'][] = $root . $paths[0];
		$this->assertSame( 1, $spine->checkpoint( $task_id, (int) $task->version, array( 'sub_state' => $state ) ) );
		$this->runner->stubs['run_incremental_diff'] = array( 'changes' => 0, 'tombstones' => 0, 'force_full' => false );
		file_put_contents( $this->backup_dir . '/changes.list', '' );
		for ( $slice = 0; $slice < 4; ++$slice ) {
			$task = $this->advance( $task_id );
			if ( MainWP_Child_Backup_Task::PHASE_COMPLETED === $task->phase ) { break; }
		}
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$roles = $this->artifacts_by_role( $spine->status_payload( $task, $this->backup_dir ) );
		$walk = $roles['manifest']['walk'];
		$this->assertSame( array( 'private' ), $walk['excludes'] );
		if ( $count > 1000 ) {
			$this->assertSame( true, $walk['unarchived_overflow'] ?? null );
			$this->assertArrayNotHasKey( 'unarchived', $walk );
		} else {
			$this->assertSame( $paths, $walk['unarchived'] ?? null );
			$this->assertArrayNotHasKey( 'unarchived_overflow', $walk );
		}
	}

	public function test_unarchived_ziparchive_failures_union_across_requests() {
		$this->site = ABSPATH . 'mainwp-unarchived-' . uniqid();
		mkdir( $this->site );
		file_put_contents( $this->site . '/First..txt', 'first live bytes' );
		file_put_contents( $this->site . '/second.txt', 'second live bytes' );
		$this->runner->test_site_root = $this->site . '/';
		$backup = \MainWP\Child\MainWP_Backup::get();
		$zip_property = new ReflectionProperty( $backup, 'zip' );
		$zip_property->setAccessible( true );
		$previous_zip = $zip_property->getValue( $backup );
		$zip = new class extends ZipArchive {
			public function addFile( $filename, $entryname = '', $start = 0, $length = 0, $flags = 0 ): bool {
				return false;
			}
		};
		$this->assertTrue( $zip->open( $this->backup_dir . '/failed-add.zip', ZipArchive::CREATE ) );
		$zip->addFromString( 'snapshot.txt', 'snapshot' );
		$zip_property->setValue( $backup, $zip );
		$attempt = 0;
		$this->runner->stubs['run_file_archiver'] = function () use ( $backup, &$attempt ) {
			$backup->add_file_to_zipp( $this->site . '/First..txt', 'First..txt' );
			// Only the middle slice fails second.txt, so the final list proves the union.
			if ( 2 === ++$attempt ) {
				$backup->add_file_to_zipp( $this->site . '/second.txt', 'second.txt' );
			}
			if ( $attempt < 3 ) {
				throw new \MainWP\Child\MainWP_Backup_Budget_Exception( 'Forced archive yield.' );
			}
			return array( 'file' => 'backup-artifact.tar', 'filesize' => 2048 );
		};
		try {
			$task_id = $this->create_task( 'full', array( 'ext' => 'zip', 'emit_manifest' => true ) );
			for ( $slice = 0; $slice < 3; ++$slice ) {
				$backup->reset_failed_adds();
				$task = $this->advance( $task_id );
				if ( $slice < 2 ) { $this->assertSame( MainWP_Child_Backup_Task::PHASE_ARCHIVING_FILES, $task->phase ); }
			}
			$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
			$roles = $this->artifacts_by_role( MainWP_Child_Backup_Task::instance()->status_payload( $task, $this->backup_dir ) );
			$this->assertSame( array( 'First..txt', 'second.txt' ), $roles['manifest']['walk']['unarchived'] ?? null );
		} finally {
			$zip_property->setValue( $backup, $previous_zip );
			$zip->close();
			$backup->reset_failed_adds();
			\MainWP\Child\MainWP_Backup::reset_skipped_paths();
		}
	}

	public static function data_unlisted_failed_adds() {
		return array(
			'invalid UTF-8, one slice' => array( false, false ),
			'invalid UTF-8, resumed slice' => array( false, true ),
			'outside the site root' => array( true, false ),
		);
	}

	/** @dataProvider data_unlisted_failed_adds */
	public function test_unlisted_failed_add_sets_overflow_instead_of_a_list( $outside_root, $yield_first ) {
		$root = ABSPATH . 'unarchived-tree/';
		$this->runner->test_site_root = $root;
		$backup = \MainWP\Child\MainWP_Backup::get();
		$attempt = 0;
		$this->runner->stubs['run_file_archiver'] = function () use ( $backup, $root, $outside_root, $yield_first, &$attempt ) {
			$backup->record_failed_add( $root . 'wp-content/listed.php' );
			if ( 0 === $attempt++ ) {
				$backup->record_failed_add( $outside_root ? '/elsewhere/wp-content/unlisted.php' : $root . "wp-content/caf\xE9.txt" );
				if ( $yield_first ) {
					throw new \MainWP\Child\MainWP_Backup_Budget_Exception( 'Forced archive yield.' );
				}
			}
			return array( 'file' => 'backup-artifact.tar', 'filesize' => 2048 );
		};
		try {
			$task_id = $this->create_task( 'full', array( 'emit_manifest' => true ) );
			for ( $slice = 0; $slice < 3; ++$slice ) {
				$backup->reset_failed_adds();
				$task = $this->advance( $task_id );
				if ( MainWP_Child_Backup_Task::PHASE_COMPLETED === $task->phase ) { break; }
			}
			$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
			$walk = $this->artifacts_by_role( MainWP_Child_Backup_Task::instance()->status_payload( $task, $this->backup_dir ) )['manifest']['walk'];
			$this->assertSame( true, $walk['unarchived_overflow'] ?? null );
			$this->assertArrayNotHasKey( 'unarchived', $walk );
		} finally {
			$backup->reset_failed_adds();
		}
	}

	public static function tar_failed_add_slices() {
		return array( 'single slice' => array( false ), 'resumed slice' => array( true ) );
	}

	/** @dataProvider tar_failed_add_slices */
	public function test_failed_tar_full_add_is_recovered_by_unchanged_incremental( $sliced ) {
		$this->site = ABSPATH . 'mainwp-tar-failed-add-' . uniqid();
		mkdir( $this->site );
		file_put_contents( $this->site . '/missed.txt', 'recovered bytes' );
		file_put_contents( $this->site . '/kept.txt', 'base bytes' );
		file_put_contents( $this->site . '/later.txt', 'later bytes' );
		$missed = $this->site . '/missed.txt';
		chmod( $missed, 0000 );
		$backup = \MainWP\Child\MainWP_Backup::get();
		$backup->reset_failed_adds();
		try {
			clearstatcache( true, $missed );
			if ( is_readable( $missed ) ) { $this->markTestSkipped( 'The test user can read a chmod 0000 file.' ); }
			$excludes = array();
			foreach ( glob( ABSPATH . '*' ) as $node ) {
				if ( $node !== $this->site ) { $excludes[] = str_replace( ABSPATH, '', $node ); }
			}
			$excludes[] = '.htaccess';
			$this->runner = new class() extends Testable_Task_Runner {
				protected function walk_manifest_slice( $params, $backup_dir, $cursor, $deadline ) {
					return \MainWP\Child\MainWP_Child_Backup_Task_Runner::walk_manifest_slice( $params, $backup_dir, $cursor, $deadline );
				}
			};
			$this->runner->test_backup_dir = $this->backup_dir;
			$this->runner->test_site_root = ABSPATH;
			$this->runner->stubs = array(
				'tier1_available' => true,
				'dump_tier1' => array( $this->backup_dir . '/dbBackup-table1.sql.php' ),
				'archive_db_artifact' => $this->backup_dir . '/backup-artifact.tar',
				'promote_manifest_sidecars' => static function ( $dir ) {
					return \MainWP\Child\MainWP_Child_Backup_File_Walker::promote_sidecars( $dir );
				},
			);
			$attempt = 0;
			$this->runner->stubs['run_file_archiver'] = function ( $params, $archive, $append ) use ( $backup, $sliced, &$attempt ) {
				$path = $this->backup_dir . '/' . $archive;
				$archiver = new \MainWP\Child\Tar_Archiver( $backup, 'tar.gz' );
				$first = 0 === $attempt++;
				$processed = 0;
				if ( $sliced && $first ) {
					$archiver->set_progress_callback( static function () use ( &$processed ) {
						if ( ++$processed === 3 ) { throw new \MainWP\Child\MainWP_Backup_Budget_Exception( 'Forced yield after the skipped file and a valid entry.' ); }
					} );
				}
				$backup->set_prepared_db_files( array() );
				try {
					@$archiver->create_full_backup( $path, $params['excludes'], false, true, false, false, $append );
				} finally {
					$backup->set_prepared_db_files( null );
					$handle = new ReflectionProperty( $archiver, 'archive' );
					$handle->setAccessible( true );
					if ( is_resource( $handle->getValue( $archiver ) ) ) { $archiver->close(); }
				}
				clearstatcache( true, $path );
				return array( 'file' => $archive, 'filesize' => filesize( $path ) );
			};
			$full_id = $this->create_task( 'full', array( 'ext' => 'tar.gz', 'excludes' => $excludes, 'emit_manifest' => true ) );
			$full = $this->advance( $full_id );
			if ( $sliced ) {
				$this->assertSame( MainWP_Child_Backup_Task::PHASE_ARCHIVING_FILES, $full->phase );
				$backup->reset_failed_adds();
				$full = $this->advance( $full_id );
			}
			$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $full->phase, 'A skipped file must not corrupt resume alignment.' );
			$full_payload = MainWP_Child_Backup_Task::instance()->status_payload( $full, $this->backup_dir );
			$full_roles = $this->artifacts_by_role( $full_payload );
			$this->assertSame( array( basename( $this->site ) . '/missed.txt' ), $full_roles['manifest']['walk']['unarchived'] ?? null, 'The engine path and the walker relpath must agree.' );
			$base = $this->backup_dir . '/' . $full_roles['full']['file'];
			$parent = file_get_contents( $this->backup_dir . '/files.list' );
			chmod( $missed, 0644 );
			$incremental_id = $this->create_task( 'incremental', array( 'excludes' => $excludes, 'parent_manifest_b64' => base64_encode( $parent ) ) );
			$incremental = $this->advance( $incremental_id );
			for ( $slice = 0; $slice < 4 && MainWP_Child_Backup_Task::PHASE_COMPLETED !== $incremental->phase; ++$slice ) {
				$incremental = $this->advance( $incremental_id );
			}
			$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $incremental->phase );
			$payload = MainWP_Child_Backup_Task::instance()->status_payload( $incremental, $this->backup_dir );
			$entry = basename( $this->site ) . '/missed.txt';
			$this->assertSame( array( $entry ), $this->packed_entries( $payload ), 'An unchanged file absent from the base must be archived by the incremental.' );
			$this->assertStringNotContainsString( 'missed.txt', $parent );
			$parts = array( $base );
			foreach ( $payload['artifacts'] as $artifact ) {
				if ( 'part' === $artifact['role'] ) { $parts[] = $this->backup_dir . '/' . $artifact['file']; }
			}
			$hashes = array();
			foreach ( $parts as $part ) { $hashes[ basename( $part ) ] = hash_file( 'sha256', $part ); }
			$target = $this->backup_dir . '/tar-restored';
			mkdir( $target );
			$result = ( new \MainWP\Child\MainWP_Child_Backup_File_Restorer() )->restore_slice( array(), array( 'parts' => $parts, 'hashes' => $hashes, 'extract_to' => $target ), PHP_INT_MAX );
			$this->assertNull( $result['error'] );
			$this->assertTrue( $result['done'] );
			$this->assertSame( 'recovered bytes', file_get_contents( $target . '/' . $entry ) );
			$this->assertSame( 'base bytes', file_get_contents( $target . '/' . basename( $this->site ) . '/kept.txt' ) );
		} finally {
			chmod( $missed, 0644 );
			$backup->reset_failed_adds();
			\MainWP\Child\MainWP_Backup::reset_skipped_paths();
		}
	}

	public function test_failed_full_add_is_recovered_by_unchanged_incremental() {
		$this->site = ABSPATH . 'mainwp-failed-add-' . uniqid();
		mkdir( $this->site );
		file_put_contents( $this->site . '/kept.txt', 'base bytes' );
		file_put_contents( $this->site . '/missed.txt', 'recovered bytes' );
		$site = $this->site;
		$this->runner = new class() extends Testable_Task_Runner {
			protected function walk_manifest_slice( $params, $backup_dir, $cursor, $deadline ) {
				return \MainWP\Child\MainWP_Child_Backup_Task_Runner::walk_manifest_slice( $params, $backup_dir, $cursor, $deadline );
			}
			protected function make_walker( $params, $backup_dir ) {
				$walker = parent::make_walker( $params, $backup_dir );
				foreach ( array( 'root' => $this->test_site_root, 'root_real' => realpath( $this->test_site_root ) ) as $name => $value ) {
					$property = new ReflectionProperty( $walker, $name );
					$property->setAccessible( true );
					$property->setValue( $walker, $value );
				}
				return $walker;
			}
		};
		$this->runner->test_backup_dir = $this->backup_dir;
		$this->runner->test_site_root = $site . '/';
		$this->runner->stubs = array(
			'tier1_available' => true,
			'dump_tier1' => array( $this->backup_dir . '/dbBackup-table1.sql.php' ),
			'archive_db_artifact' => $this->backup_dir . '/backup-artifact.tar',
			'promote_manifest_sidecars' => static function ( $dir ) {
				return \MainWP\Child\MainWP_Child_Backup_File_Walker::promote_sidecars( $dir );
			},
		);
		$attempt = 0;
		$this->runner->stubs['run_file_archiver'] = function ( $params, $archive, $append, $deadline, $db_files ) use ( $site, &$attempt ) {
			$path = $this->backup_dir . '/' . $archive;
			$zip = new ZipArchive();
			$this->assertTrue( $zip->open( $path, ZipArchive::CREATE ) );
			$adapter = new class( $zip ) {
				private $zip;
				public function __construct( $zip ) { $this->zip = $zip; }
				public function add_file( $path, $name ) {
					return 'missed.txt' === $name ? false : $this->zip->addFile( $path, $name );
				}
			};
			$backup = \MainWP\Child\MainWP_Backup::get();
			$property = new ReflectionProperty( $backup, 'zip' );
			$property->setAccessible( true );
			$previous = $property->getValue( $backup );
			$property->setValue( $backup, $adapter );
			try {
				if ( 0 === $attempt++ ) {
					$backup->add_file_to_zipp( $site . '/kept.txt', 'kept.txt' );
					$backup->add_file_to_zipp( $site . '/missed.txt', 'missed.txt' );
				}
			} finally {
				$property->setValue( $backup, $previous );
				$zip->close();
			}
			if ( 1 === $attempt ) {
				throw new \MainWP\Child\MainWP_Backup_Budget_Exception( 'Forced archive yield.' );
			}
			return array( 'file' => $archive, 'filesize' => filesize( $path ) );
		};
		$backup = \MainWP\Child\MainWP_Backup::get();
		$backup->reset_failed_adds();
		try {
			$full_id = $this->create_task( 'full', array( 'ext' => 'zip', 'emit_manifest' => true ) );
			$full = $this->advance( $full_id );
			$this->assertSame( MainWP_Child_Backup_Task::PHASE_ARCHIVING_FILES, $full->phase );
			$backup->reset_failed_adds(); // A new request has no in-memory failure record.
			$full = $this->advance( $full_id );
			$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $full->phase );
			$full_payload = MainWP_Child_Backup_Task::instance()->status_payload( $full, $this->backup_dir );
			$full_roles = $this->artifacts_by_role( $full_payload );
			$base = $this->backup_dir . '/' . $full_roles['full']['file'];
			$parent = file_get_contents( $this->backup_dir . '/files.list' );
			$incremental_id = $this->create_task( 'incremental', array( 'parent_manifest_b64' => base64_encode( $parent ) ) );
			$incremental = $this->advance( $incremental_id );
			for ( $slice = 0; $slice < 4 && MainWP_Child_Backup_Task::PHASE_COMPLETED !== $incremental->phase; ++$slice ) {
				$incremental = $this->advance( $incremental_id );
			}
			$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $incremental->phase );
			$payload = MainWP_Child_Backup_Task::instance()->status_payload( $incremental, $this->backup_dir );
			$parts = array( $base );
			foreach ( $payload['artifacts'] as $artifact ) {
				if ( 'part' === $artifact['role'] ) { $parts[] = $this->backup_dir . '/' . $artifact['file']; }
			}
			$target = $this->backup_dir . '/restored';
			mkdir( $target );
			$hashes = array();
			foreach ( $parts as $part ) { $hashes[ basename( $part ) ] = hash_file( 'sha256', $part ); }
			$result = ( new \MainWP\Child\MainWP_Child_Backup_File_Restorer() )->restore_slice( array(), array( 'parts' => $parts, 'hashes' => $hashes, 'extract_to' => $target ), PHP_INT_MAX );
			$this->assertNull( $result['error'] );
			$this->assertTrue( $result['done'] );
			$this->assertFileExists( $target . '/missed.txt', 'An unchanged file missing from the base must be restored from the incremental.' );
			$this->assertSame( 'recovered bytes', file_get_contents( $target . '/missed.txt' ) );
			$this->assertSame( 'base bytes', file_get_contents( $target . '/kept.txt' ) );
			$this->assertStringNotContainsString( 'missed.txt', $parent );
			$this->assertSame( array( 'missed.txt' ), $this->packed_entries( $payload ) );
		} finally {
			$backup->reset_failed_adds();
			\MainWP\Child\MainWP_Backup::reset_skipped_paths();
		}
	}

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

	/**
	 * The stamp binds the writer to bytes, including interrupted promotions.
	 *
	 * @dataProvider writer_identity_events
	 */
	public function test_manifest_writer_identity_matrix( $events, $expected_writer, $allowed ) {
		$this->arrange_incremental_fixtures();
		$stamp = new ReflectionMethod( \MainWP\Child\MainWP_Child_Backup_Task_Runner::class, 'stamp_manifest_writer' );
		$stamp->setAccessible( true );
		foreach ( $events as $event ) {
			list( $operation, $writer ) = explode( ':', $event );
			if ( 'stage' === $operation ) {
				file_put_contents( $this->backup_dir . '/files.list.staged', $this->parent_manifest_content() . $writer . ".php\t1\t1\n" );
				file_put_contents( $this->backup_dir . '/paths.cache.staged', '' );
			} elseif ( 'stamp' === $operation ) {
				$this->assertTrue( $stamp->invoke( $this->runner, $this->backup_dir, $writer ) );
			} elseif ( 'promote' === $operation ) {
				// Exercise the real rename rather than the runner fixture's promotion stub.
				rename( $this->backup_dir . '/files.list.staged', $this->backup_dir . '/files.list' );
			} elseif ( 'corrupt' === $operation ) {
				file_put_contents( $this->backup_dir . '/files.list', 'torn bytes' );
			} elseif ( 'torn' === $operation ) {
				file_put_contents( $this->backup_dir . '/files.list.writer.json', '{' );
			} elseif ( 'failedstamp' === $operation ) {
				unlink( $this->backup_dir . '/files.list.writer.json' );
				mkdir( $this->backup_dir . '/files.list.writer.json' );
				$this->assertFalse( $stamp->invoke( $this->runner, $this->backup_dir, $writer ) );
			}
		}
		$task_id = $this->create_task( 'incremental', array( 'parent_child_task_id' => $expected_writer ) );
		$task = $this->advance( $task_id );
		$this->assertSame( $allowed ? MainWP_Child_Backup_Task::PHASE_ARCHIVING_FILES : MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );
		if ( ! $allowed ) {
			$payload = MainWP_Child_Backup_Task::instance()->status_payload( $task, $this->backup_dir );
			$this->assertSame( MainWP_Backup_Error::ERR_INCREMENTAL_BASE_MISSING, $payload['error_code'] );
		}
	}

	/** @return array Interleavings at the shared manifest. */
	public static function writer_identity_events() {
		$i = array( 'stage:I', 'stamp:I', 'promote:I' );
		$f = array( 'stage:F', 'stamp:F', 'promote:F' );
		return array(
			'matching bytes and writer' => array( $i, 'I', true ),
			'I completes F completes I records F record fails' => array( array_merge( $i, $f ), 'I', false ),
			'F delayed until after I records' => array( array_merge( $i, $f ), 'I', false ),
			'I delayed until after F records' => array( array_merge( $f, $i ), 'F', false ),
			'new stamp before failed promotion old writer' => array( array_merge( $i, array( 'stage:F', 'stamp:F' ) ), 'I', false ),
			'new stamp before failed promotion new writer' => array( array_merge( $i, array( 'stage:F', 'stamp:F' ) ), 'F', false ),
			'promotion without new stamp' => array( array_merge( $i, array( 'stage:F', 'promote:F' ) ), 'I', false ),
			'corrupt list' => array( array_merge( $i, array( 'corrupt:I' ) ), 'I', false ),
			'torn stamp' => array( array_merge( $i, array( 'torn:I' ) ), 'I', false ),
			'stamp publication fails' => array( array_merge( $i, array( 'stage:F', 'failedstamp:F', 'promote:F' ) ), 'F', false ),
			'unstamped list' => array( array( 'stage:I', 'promote:I' ), 'I', false ),
			'legacy caller has no proof' => array( $i, '', false ),
		);
	}

	/**
	 * An explicit unusable push must not read the unrelated local list.
	 *
	 * @dataProvider unusable_parent_pushes
	 */
	public function test_explicit_parent_failure_never_uses_local_list( $params, $block_write ) {
		$this->arrange_incremental_fixtures();
		file_put_contents( $this->backup_dir . '/files.list', $this->parent_manifest_content() );
		if ( $block_write ) {
			mkdir( $this->backup_dir . '/parent-files.list' );
		}
		$task_id = $this->create_task( 'incremental', $params );
		$task = $this->advance( $task_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );
		$payload = MainWP_Child_Backup_Task::instance()->status_payload( $task, $this->backup_dir );
		$this->assertSame( MainWP_Backup_Error::ERR_INCREMENTAL_BASE_MISSING, $payload['error_code'] );
	}

	/** @return array Invalid explicit inputs. */
	public static function unusable_parent_pushes() {
		return array(
			'bad base64' => array( array( 'parent_manifest_b64' => '!!!' ), false ),
			'missing spilled file' => array( array( 'parent_manifest_file' => 'gone.list' ), false ),
			'write refused' => array( array( 'parent_manifest_b64' => base64_encode( "a.php\t1\t1\n" ) ), true ),
		);
	}

	/**
	 * Explicit parents must survive checkpoints without becoming shared-list lookups.
	 *
	 * @dataProvider spilled_parent_cleanup_cases
	 */
	public function test_spilled_parent_survives_resume_and_cleanup( $terminal, $source = 'spill' ) {
		$this->arrange_incremental_fixtures();
		$this->runner->stubs['promote_manifest_sidecars'] = static function ( $backup_dir ) {
			return \MainWP\Child\MainWP_Child_Backup_File_Walker::promote_sidecars( $backup_dir );
		};
		$bytes = $this->parent_manifest_content();
		$path = $this->backup_dir . '/pushed-parent.list';
		$params = array();
		if ( 'inline' === $source ) {
			$this->runner->stubs['write_parent_manifest'] = static function ( $path, $decoded ) {
				return file_put_contents( $path, substr( $decoded, 0, -1 ) );
			};
			$spill = new \ReflectionMethod( $this->runner, 'spill_parent_manifest' );
			$spill->setAccessible( true );
			$params = $spill->invoke( $this->runner, array( 'parent_manifest_b64' => base64_encode( $bytes ) ) );
			$this->assertArrayHasKey( 'parent_manifest_b64', $params );
			$this->assertArrayNotHasKey( 'parent_manifest_file', $params );
			$path = $this->backup_dir . '/parent-files.list';
		} elseif ( 'spill' === $source ) {
			file_put_contents( $path, $bytes );
			$params['parent_manifest_file'] = basename( $path );
		}
		$persisted = array( 'params' => $params );
		$resolve = new \ReflectionMethod( $this->runner, 'resolve_parent_manifest' );
		$resolve->setAccessible( true );
		if ( 'missing' === $source ) {
			$this->assertSame( array(), glob( $this->backup_dir . '/*.list' ) );
			try {
				$resolve->invokeArgs( $this->runner, array( &$persisted, $this->backup_dir ) );
				$this->fail( 'A missing parent was accepted.' );
			} catch ( MainWP_Exception $e ) {
				$this->assertSame( MainWP_Backup_Error::ERR_INCREMENTAL_BASE_MISSING, $e->get_message_error_code() );
			}
			return;
		}
		file_put_contents( $this->backup_dir . '/files.list', "unrelated.php\t1\t1\n" );
		for ( $i = 0; $i < 2; ++$i ) {
			$cursor = $persisted;
			$resolved = $resolve->invokeArgs( $this->runner, array( &$cursor, $this->backup_dir ) );
			$this->assertSame( $path, $resolved );
			$this->assertFileExists( $path );
			$this->assertSame( $bytes, file_get_contents( $resolved ) );
			$this->assertSame( basename( $path ), $cursor['params']['parent_manifest_file'] );
			$this->assertArrayNotHasKey( 'parent_manifest_b64', $cursor['params'] );
			$persisted = $cursor;
		}
		$task_id = $this->create_task( 'incremental', $persisted['params'] );
		$spine = MainWP_Child_Backup_Task::instance();
		// The inline fallback writes the fixed name parent-files.list, which a
		// cleanup by name never deletes: another run may be using it, and the
		// next inline run overwrites it. Only completion removes it.
		$shared_name = 'inline' === $source;
		if ( 'cancel' === $terminal ) {
			$this->runner->cancel_backup_task( $task_id );
			$task = $spine->get_task( $task_id );
			$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );
			$this->assertSame( $shared_name, file_exists( $path ), 'The cancel deletes a spilled parent copy, as completion and failure do.' );
		} elseif ( 'failure' === $terminal || 'failure_before_manifest' === $terminal ) {
			$seam = 'failure' === $terminal ? 'run_incremental_diff' : 'walk_manifest_slice';
			$this->runner->stubs[ $seam ] = static function () {
				throw new MainWP_Exception( 'Injected incremental failure' );
			};
			$task = $this->advance( $task_id );
			$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );
			$this->assertSame( $shared_name, file_exists( $path ) );
			$this->assertSame( "unrelated.php\t1\t1\n", file_get_contents( $this->backup_dir . '/files.list' ) );
		} else {
			$task = $this->advance( $task_id );
			$this->assertSame( MainWP_Child_Backup_Task::PHASE_ARCHIVING_FILES, $task->phase );
			$checkpoint = $spine->read_sub_state( $task, $this->backup_dir );
			$this->assertTrue( $checkpoint['diff_done'] );
			$this->assertFileExists( $path );
			$this->assertSame( $bytes, file_get_contents( $path ) );
			$manifest = file_get_contents( $this->backup_dir . '/files.list.staged' );
			$paths = file_get_contents( $this->backup_dir . '/paths.cache.staged' );
			$task = $this->advance( $task_id );
			$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
			$this->assertFileDoesNotExist( $path );
			$this->assertSame( $manifest, file_get_contents( $this->backup_dir . '/files.list' ) );
			$this->assertSame( $paths, file_get_contents( $this->backup_dir . '/paths.cache' ) );
			$roles = $this->artifacts_by_role( $spine->status_payload( $task, $this->backup_dir ) );
			$this->assertSame( 'files.list', $roles['manifest']['file'] );
			$this->assertSame( hash( 'sha256', $manifest ), $roles['manifest']['sha256'] );
		}
		$terminal_state = $spine->read_sub_state( $task, $this->backup_dir );
		if ( 'completion' === $terminal || 'cancel' === $terminal ) {
			$this->assertSame( basename( $path ), $terminal_state['params']['parent_manifest_file'] );
		}
		$this->assertSame( 'pruned', $spine->prune_task( $task_id, $this->backup_dir ) );
		$this->assertSame( $shared_name && 'completion' !== $terminal, file_exists( $path ) );
	}

	/**
	 * Every incremental names the shared files.list and tombstones.list. A
	 * prune of the run that promoted the chain base (retention, or an
	 * abandoned run) must leave that base, so the next incremental's local
	 * base check still passes.
	 */
	public function test_prune_of_completed_incremental_keeps_chain_base() {
		$this->arrange_incremental_fixtures();
		$this->runner->stubs['promote_manifest_sidecars'] = static function ( $backup_dir ) {
			return \MainWP\Child\MainWP_Child_Backup_File_Walker::promote_sidecars( $backup_dir );
		};
		$parent = $this->backup_dir . '/pushed-parent.list';
		file_put_contents( $parent, $this->parent_manifest_content() );
		$task_id = $this->create_task( 'incremental', array( 'parent_manifest_file' => basename( $parent ) ) );
		$this->advance( $task_id );
		$task  = $this->advance( $task_id );
		$spine = MainWP_Child_Backup_Task::instance();
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$roles = $this->artifacts_by_role( $spine->status_payload( $task, $this->backup_dir ) );
		$this->assertSame( 'files.list', $roles['manifest']['file'] );
		$this->assertSame( 'tombstones.list', $roles['tombstones']['file'] );

		$this->assertSame( 'pruned', $spine->prune_task( $task_id, $this->backup_dir ) );

		foreach ( array( 'files.list', 'paths.cache', 'files.list.writer.json' ) as $name ) {
			$this->assertFileExists( $this->backup_dir . '/' . $name, $name . ' is the chain base and must survive the prune.' );
		}
		$this->assertFileDoesNotExist( $this->backup_dir . '/' . $roles['db']['file'], 'The pruned run\'s own files still go.' );

		$next = 'task-next-' . uniqid();
		$this->assertTrue( MainWP_Child_Backup_Mutex::acquire( 'native', $next, 3600 ) );
		$current = new ReflectionProperty( \MainWP\Child\MainWP_Child_Backup_Task_Runner::class, 'current_task_id' );
		$current->setAccessible( true );
		$current->setValue( $this->runner, $next );
		$cursor  = array( 'params' => array( 'parent_child_task_id' => $task_id ) );
		$resolve = new ReflectionMethod( $this->runner, 'resolve_parent_manifest' );
		$resolve->setAccessible( true );
		$this->assertSame( $this->backup_dir . '/files.list', $resolve->invokeArgs( $this->runner, array( &$cursor, $this->backup_dir ) ), 'The next incremental can still use the local base.' );
	}

	/** @return array Terminal spill cleanup paths. */
	public static function spilled_parent_cleanup_cases() {
		return array(
			'completion' => array( 'completion' ),
			'failure' => array( 'failure' ),
			'failure before manifest' => array( 'failure_before_manifest' ),
			'cancel' => array( 'cancel' ),
			'inline fallback after a failed spill, killed and resumed' => array( 'completion', 'inline' ),
			'inline fallback failure' => array( 'failure', 'inline' ),
			'inline fallback failure before manifest' => array( 'failure_before_manifest', 'inline' ),
			'inline fallback cancel' => array( 'cancel', 'inline' ),
			'no params, expected id, or file' => array( null, 'missing' ),
		);
	}

	/** A short spill must not orphan bytes or prevent the inline retry. */
	public function test_short_parent_manifest_spill_removes_partial_file_and_keeps_inline_fallback() {
		$this->arrange_incremental_fixtures();
		$bytes = $this->parent_manifest_content();
		$this->runner->stubs['write_parent_manifest'] = function ( $path, $decoded ) {
			$written = file_put_contents( $path, substr( $decoded, 0, -1 ) );
			$this->assertSame( strlen( $decoded ) - 1, $written );
			$this->assertFileExists( $path );
			return $written;
		};
		$spill = new \ReflectionMethod( $this->runner, 'spill_parent_manifest' );
		$spill->setAccessible( true );
		$params = array( 'parent_manifest_b64' => base64_encode( $bytes ) );
		$cursor = array( 'params' => $spill->invoke( $this->runner, $params ) );
		$this->assertSame( array(), glob( $this->backup_dir . '/parent-manifest-*.list' ) );
		$this->assertArrayNotHasKey( 'parent_manifest_file', $cursor['params'] );
		$this->assertSame( $params, $cursor['params'] );
		$resolve = new \ReflectionMethod( $this->runner, 'resolve_parent_manifest' );
		$resolve->setAccessible( true );
		$resolved = $resolve->invokeArgs( $this->runner, array( &$cursor, $this->backup_dir ) );
		$this->assertSame( $this->backup_dir . '/parent-files.list', $resolved );
		$this->assertSame( $bytes, file_get_contents( $resolved ) );
	}

	/** Completion writes the proof before promotion, with its actual task identity. */
	public function test_completion_stamps_staged_manifest_before_promotion() {
		$this->arrange_incremental_fixtures();
		$task_id = $this->create_task( 'full', array( 'emit_manifest' => true ) );
		$this->runner->stubs['promote_manifest_sidecars'] = function () use ( $task_id ) {
			$stamp = json_decode( file_get_contents( $this->backup_dir . '/files.list.writer.json' ), true );
			$this->assertSame( $task_id, $stamp['task_id'] );
			$this->assertSame( hash_file( 'sha256', $this->backup_dir . '/files.list.staged' ), $stamp['sha256'] );
			return true;
		};
		$task = $this->advance( $task_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$payload = MainWP_Child_Backup_Task::instance()->status_payload( $task, $this->backup_dir );
		$roles = $this->artifacts_by_role( $payload );
		$this->assertSame( hash_file( 'sha256', $this->backup_dir . '/files.list.staged' ), $roles['manifest']['sha256'] );
	}

	/** Invalid inline bytes must also fail at the create-time spill. */
	public function test_invalid_push_fails_before_task_creation() {
		try {
			$this->runner->create_task_for_params( 'incremental', array( 'parent_manifest_b64' => '!!!' ) );
			$this->fail( 'Invalid push was accepted.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( MainWP_Backup_Error::ERR_INCREMENTAL_BASE_MISSING, $e->get_message_error_code() );
			$this->assertSame( array(), MainWP_Child_Backup_Task::instance()->get_active_tasks() );
		}
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
		file_put_contents( $this->backup_dir . '/files.list', $this->parent_manifest_content() );

		$task_id = $this->create_task( 'incremental', array( 'parent_manifest_b64' => base64_encode( $this->parent_manifest_content() ) ) );

		$task = $this->advance( $task_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_ARCHIVING_FILES, $task->phase, 'The diff step yields so packing starts with a fresh budget.' );

		$task = $this->advance( $task_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );

		$payload = MainWP_Child_Backup_Task::instance()->status_payload( $task, $this->backup_dir );

		$this->assertSame( 'files.list', $payload['file'], 'The manifest is the top-level artifact for incremental runs.' );

		$roles = $this->artifacts_by_role( $payload );
		$this->assertSame( 'files.list', $roles['manifest']['file'] );
		$this->assertSame( array( 'excludes' => array(), 'excludenonwp' => 0, 'excludezip' => 0 ), $roles['manifest']['walk'] );
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
		// The pushed parent is a per-run copy: the terminal phase removes it like a spilled one.
		$this->assertFileDoesNotExist( $this->backup_dir . '/parent-files.list' );

		$payload = MainWP_Child_Backup_Task::instance()->status_payload( $task, $this->backup_dir );
		$this->assertArrayNotHasKey( 'parent_manifest_b64', $payload['sub_state']['params'] );
		$this->assertSame( 'parent-files.list', $payload['sub_state']['params']['parent_manifest_file'] );
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
		file_put_contents( $this->backup_dir . '/files.list', $this->parent_manifest_content() );

		$task_id = $this->create_task( 'incremental', array( 'parent_manifest_b64' => base64_encode( $this->parent_manifest_content() ) ) );
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
		file_put_contents( $this->backup_dir . '/files.list', $this->parent_manifest_content() );

		$task_id = $this->create_task( 'incremental', array( 'parent_manifest_b64' => base64_encode( $this->parent_manifest_content() ) ) );
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
