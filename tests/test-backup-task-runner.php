<?php
/**
 * Class BackupTaskRunnerTest
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Backup;
use MainWP\Child\MainWP_Child_Backup_Mutex;
use MainWP\Child\MainWP_Backup_Error;
use MainWP\Child\MainWP_Backup_Budget_Exception;
use MainWP\Child\MainWP_Child_Backup_Task;
use MainWP\Child\MainWP_Child_Backup_Task_Runner;
use MainWP\Child\MainWP_Child_Callable;
use MainWP\Child\MainWP_Exception;

class Backup_Slice_Limit_Helper extends \MainWP\Child\MainWP_Helper {
	public static $limits = array();
	public static $response;

	public static function set_limit( $timeout, $mem = false ) {
		self::$limits[] = array( $timeout, $mem );
	}

	public static function write( $value ) {
		self::$response = $value;
	}
}

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
	 * @param bool   $skip_db      Whether the archive embeds no database dump.
	 *
	 * @return array Result with file/filesize.
	 */
	protected function run_file_archiver( $params, $archive_file, $append, $deadline, $db_files, $skip_db = false ) {
		return $this->stub( 'run_file_archiver', array( $params, $archive_file, $append, $deadline, $db_files, $skip_db ) );
	}

	/**
	 * Manifest walk slice stub.
	 *
	 * @param array      $params     Backup params.
	 * @param string     $backup_dir Scratch directory.
	 * @param array|null $cursor     Resume cursor.
	 * @param int        $deadline   Slice deadline.
	 *
	 * @return array Slice result with done/cursor and file/size/count when done.
	 */
	protected function walk_manifest_slice( $params, $backup_dir, $cursor, $deadline ) {
		return $this->stub( 'walk_manifest_slice', array( $params, $backup_dir, $cursor, $deadline ) );
	}

	/**
	 * Site root override for the pack loop.
	 *
	 * @var string
	 */
	public $test_site_root = '';

	/** @var array|null Content roots override; null uses the site configuration. */
	public $test_content_roots = null;

	protected function content_roots() {
		return null !== $this->test_content_roots ? $this->test_content_roots : parent::content_roots();
	}

	/**
	 * Pack-loop site root: the test fixture tree when set.
	 *
	 * @return string Root with a trailing slash.
	 */
	protected function site_root() {
		return '' !== $this->test_site_root ? $this->test_site_root : parent::site_root();
	}

	/**
	 * Incremental diff seam: stubbed when set, otherwise the real diff
	 * runs against the fixture lists.
	 *
	 * @param string $parent_path     Parent files.list path.
	 * @param string $current_path    Current staged files.list path.
	 * @param string $changes_path    changes.list output path.
	 * @param string $tombstones_path tombstones.list output path.
	 *
	 * @return array Diff result.
	 */
	protected function run_incremental_diff( $parent_path, $current_path, $changes_path, $tombstones_path ) {
		if ( isset( $this->stubs['run_incremental_diff'] ) ) {
			return $this->stub( 'run_incremental_diff', array( $parent_path, $current_path, $changes_path, $tombstones_path ) );
		}
		$this->calls[] = array( 'run_incremental_diff', $parent_path, $current_path, $changes_path, $tombstones_path );
		return parent::run_incremental_diff( $parent_path, $current_path, $changes_path, $tombstones_path );
	}

	/**
	 * Pack budget seam: stubbed when set, otherwise the real clock.
	 *
	 * @param int $deadline Slice deadline.
	 *
	 * @return bool Exhausted state.
	 */
	protected function pack_budget_exhausted( $deadline ) {
		if ( isset( $this->stubs['pack_budget_exhausted'] ) ) {
			return (bool) $this->stub( 'pack_budget_exhausted', array( $deadline ) );
		}
		return parent::pack_budget_exhausted( $deadline );
	}

	/**
	 * Manifest sidecar promotion stub.
	 *
	 * @param string $backup_dir Scratch directory.
	 *
	 * @return bool Promotion result.
	 */
	protected function promote_manifest_sidecars( $backup_dir ) {
		return (bool) $this->stub( 'promote_manifest_sidecars', array( $backup_dir ) );
	}

	/**
	 * Woo backup-meta seam: stubbed when set, otherwise the real reads.
	 *
	 * @return array Woo meta.
	 */
	protected function woo_backup_meta() {
		if ( isset( $this->stubs['woo_backup_meta'] ) ) {
			return $this->stub( 'woo_backup_meta', array() );
		}
		return parent::woo_backup_meta();
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
		$holder = MainWP_Child_Backup_Mutex::holder();
		if ( null !== $holder ) {
			MainWP_Child_Backup_Mutex::release( $holder['owner'] );
		}
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . MainWP_Child_Backup_Task::instance()->get_table_name() ); // phpcs:ignore -- test cleanup.
		$touch = new ReflectionProperty( MainWP_Child_Backup_Task::instance(), 'last_touch' );
		$touch->setAccessible( true );
		$touch->setValue( MainWP_Child_Backup_Task::instance(), 0 );
		foreach ( (array) glob( $this->backup_dir . '/*' ) as $file ) {
			unlink( $file );
		}
		if ( is_dir( $this->backup_dir ) ) {
			rmdir( $this->backup_dir );
		}
		parent::tear_down();
	}

	public function test_runner_slice_budget_is_40_and_callable_limits_derive_from_it() {
		$this->assertSame( 40, MainWP_Child_Backup_Task_Runner::SLICE_BUDGET_SECONDS );
		$this->assertSame( 90, MainWP_Child_Backup_Task::SLICE_BUDGET_SECONDS );
		$budget = new ReflectionMethod( MainWP_Child_Backup_Task_Runner::class, 'slice_budget' );
		$budget->setAccessible( true );
		$this->assertSame( 40, $budget->invoke( MainWP_Child_Backup_Task_Runner::instance() ) );

		// Capture limits and responses because the real writer exits PHP.
		// The copied bodies may only use MainWP_Helper:: unqualified and no private members beyond the copied methods; anything else breaks the eval.
		if ( ! class_exists( 'MainWP\\Child\\Slice_Budget_Callable_Fixture', false ) ) {
			$methods = '';
			foreach ( array( 'backup_task_run', 'restore_task_run', 'load_restore_task_from_request' ) as $name ) {
				$method = new ReflectionMethod( MainWP_Child_Callable::class, $name );
				$lines = file( $method->getFileName() );
				$methods .= str_replace( 'MainWP_Helper', '\\Backup_Slice_Limit_Helper', implode( '', array_slice( $lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1 ) ) );
			}
			eval( 'namespace MainWP\\Child; class Slice_Budget_Callable_Fixture extends MainWP_Child_Callable {' . $methods . '}' );
		}
		$previous_post = $_POST;
		$spine = MainWP_Child_Backup_Task::instance();
		try {
			foreach ( array( 'full' => 'backup_task_run', 'restore' => 'restore_task_run' ) as $type => $method ) {
				$task_id = $spine->create_task( $type );
				$this->assertNotFalse( $task_id );
				$this->assertNotFalse( $spine->checkpoint( $task_id, 0, array( 'phase' => MainWP_Child_Backup_Task::PHASE_COMPLETED ) ) );
				$_POST = array( 'task_id' => $task_id );
				Backup_Slice_Limit_Helper::$limits = array();
				Backup_Slice_Limit_Helper::$response = null;
				( new \MainWP\Child\Slice_Budget_Callable_Fixture() )->$method();
				$this->assertSame( array( array( 2 * MainWP_Child_Backup_Task_Runner::SLICE_BUDGET_SECONDS, '512M' ) ), Backup_Slice_Limit_Helper::$limits, $method );
				$this->assertSame( 80, Backup_Slice_Limit_Helper::$limits[0][0], $method );
				$this->assertSame( $task_id, Backup_Slice_Limit_Helper::$response['task_id'] );
			}
		} finally {
			$_POST = $previous_post;
			Backup_Slice_Limit_Helper::$limits = array();
			Backup_Slice_Limit_Helper::$response = null;
		}
	}

	public function test_create_task_mutex_insert_failure_has_write_failed_error_code() {
		global $wpdb;
		$this->assertTrue( MainWP_Child_Backup_Mutex::acquire( 'legacy', 'holder', 300 ) );
		$filter = static function ( $query ) {
			if ( 0 === strpos( $query, 'INSERT IGNORE INTO' ) && false !== strpos( $query, MainWP_Child_Backup_Mutex::OPTION_NAME ) ) {
				return 'SELECT mainwp_mutex_forced_failure';
			}
			return $query;
		};
		$suppress = $wpdb->suppress_errors();
		add_filter( 'query', $filter );
		try {
			$this->runner->create_task_for_params( 'full', array() );
			$this->fail( 'A mutex INSERT failure must refuse creation.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( MainWP_Backup_Error::ERR_MUTEX_WRITE_FAILED, $e->get_message_error_code() );
			$this->assertSame( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_MUTEX_WRITE_FAILED ) ), $e->getMessage() );
			$this->assertSame( 'holder', MainWP_Child_Backup_Mutex::holder()['owner'] );
			$this->assertEmpty( MainWP_Child_Backup_Task::instance()->get_active_tasks() );
		} finally {
			remove_filter( 'query', $filter );
			$wpdb->suppress_errors( $suppress );
			MainWP_Child_Backup_Mutex::release( 'holder' );
		}
	}

	public function test_create_task_distinguishes_mutex_write_failure_from_unreadable_holder() {
		global $wpdb;
		foreach ( array( 'SELECT mainwp_mutex_forced_failure' => MainWP_Backup_Error::ERR_MUTEX_WRITE_FAILED, 'SELECT 1 WHERE 1 = 0' => MainWP_Backup_Error::ERR_ALREADY_RUNNING ) as $replacement => $expected_code ) {
			$inserts = 0;
			$filter = static function ( $query ) use ( $replacement, &$inserts ) {
				if ( 0 === strpos( $query, 'INSERT IGNORE INTO' ) && false !== strpos( $query, MainWP_Child_Backup_Mutex::OPTION_NAME ) ) {
					++$inserts;
					return $replacement;
				}
				return $query;
			};
			$this->assertNull( MainWP_Child_Backup_Mutex::holder() );
			$suppress = $wpdb->suppress_errors();
			add_filter( 'query', $filter );
			try {
				$this->runner->create_task_for_params( 'full', array() );
				$this->fail( 'Creation must refuse a mutex write failure or unreadable holder.' );
			} catch ( MainWP_Exception $e ) {
				$this->assertSame( $expected_code, $e->get_message_error_code() );
				$this->assertSame( MainWP_Backup_Error::ERR_MUTEX_WRITE_FAILED === $expected_code ? 1 : 2, $inserts );
				$this->assertSame( rtrim( $e->getMessage() ), $e->getMessage() );
				$this->assertEmpty( MainWP_Child_Backup_Task::instance()->get_active_tasks() );
			} finally {
				remove_filter( 'query', $filter );
				$wpdb->suppress_errors( $suppress );
			}
		}
	}

	/**
	 * An unwritable marker must produce the coded backup failure.
	 */
	public function test_finalize_dump_files_reports_unwritable_directory() {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'Root can write to directories without write permission.' );
		}
		$prefix = $this->backup_dir . '/dbBackup';
		$method = new ReflectionMethod( MainWP_Child_Backup_Task_Runner::class, 'finalize_dump_files' );
		$method->setAccessible( true );
		$this->assertTrue( chmod( $this->backup_dir, 0500 ) );
		$failure = null;
		set_error_handler(
			function ( $severity, $message ) use ( $prefix ) {
				return E_WARNING === $severity && false !== strpos( $message, 'fopen(' . $prefix . '.sql)' );
			}
		);
		try {
			$method->invoke( $this->runner, array(), $prefix );
		} catch ( MainWP_Exception $e ) {
			$failure = $e;
		} finally {
			restore_error_handler();
			chmod( $this->backup_dir, 0755 );
		}
		$this->assertInstanceOf( MainWP_Exception::class, $failure );
		$this->assertSame( \MainWP\Child\MainWP_Backup_Error::ERR_PERMISSIONS, $failure->get_message_error_code() );
		$this->assertFileDoesNotExist( $prefix . '.sql' );
		$this->assertSame( array( $prefix . '.sql' ), $method->invoke( $this->runner, array(), $prefix ) );
		$this->assertSame( 0, filesize( $prefix . '.sql' ) );
	}

	/**
	 * Create a task with standard params.
	 *
	 * @param string $type Backup type.
	 *
	 * @return string Task id.
	 */
	protected function create_task( $type = 'db' ) {
		$task_id = MainWP_Child_Backup_Task::instance()->create_task(
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
		$this->assertTrue( MainWP_Child_Backup_Mutex::acquire( 'native', $task_id, 3600 ) );
		return $task_id;
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
	 * A files backup never dumps the database - neither in its own dump
	 * phase (it has none) nor through the archiver's embedded fallback.
	 */
	public function test_files_kind_skips_dump() {
		$task_id = $this->create_task( 'files' );
		$task    = $this->advance( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertNotContains( 'tier1_available', $this->runner->seam_names() );
		$this->assertNotContains( 'dump_tier1', $this->runner->seam_names() );
		$this->assertContains( 'run_file_archiver', $this->runner->seam_names() );

		$archiver_call = null;
		foreach ( $this->runner->calls as $call ) {
			if ( 'run_file_archiver' === $call[0] ) {
				$archiver_call = $call;
			}
		}
		$this->assertNotNull( $archiver_call );
		$this->assertTrue( $archiver_call[6], 'A files backup must tell the archiver to embed no database dump.' );
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
				$this->backup_dir . '/dbBackup-' . $task_id . '.sql', // The completion marker shares the task's scratch prefix.
			),
			$archiver_call[5],
			'The archiver must receive the dump files produced by the dump phase'
		);
		$this->assertFalse( $archiver_call[6], 'A full backup embeds its dump set.' );

		$dump_calls = array_filter(
			$this->runner->seam_names(),
			function ( $name ) {
				return 'dump_tier1' === $name || 'dump_tier2' === $name;
			}
		);
		$this->assertCount( 1, $dump_calls, 'The database must be dumped exactly once' );
	}

	/** Unsupported layouts must be refused before creating a task or taking the mutex. */
	public function test_create_task_refuses_external_content_without_state() {
		global $wpdb;
		$spine = MainWP_Child_Backup_Task::instance();
		$this->runner->test_site_root = $this->backup_dir . '/core/';
		$this->runner->test_content_roots = array( $this->backup_dir . '/wp-content' );
		foreach ( array( 'full', 'files', 'incremental' ) as $type ) {
			try {
				$this->runner->create_task_for_params( $type, array() );
				$this->fail( 'File backups must refuse content outside the site root: ' . $type );
			} catch ( MainWP_Exception $e ) {
				$this->assertSame( MainWP_Backup_Error::ERR_UNSUPPORTED_LAYOUT, $e->get_message_error_code() );
				$this->assertSame( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_UNSUPPORTED_LAYOUT ) ), $e->getMessage() );
			}
			$this->assertSame( 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $spine->get_table_name() ) );
			$this->assertNull( MainWP_Child_Backup_Mutex::holder() );
			$this->assertSame( array(), $this->runner->calls );
		}
	}

	public function test_create_db_task_allows_external_content() {
		$this->runner->test_site_root = $this->backup_dir . '/core/';
		$this->runner->test_content_roots = array( $this->backup_dir . '/wp-content' );
		$task_id = $this->runner->create_task_for_params( 'db', array() );
		$this->assertSame( 'db', MainWP_Child_Backup_Task::instance()->get_task( $task_id )->type );
	}

	/** The clone entry written after the walk is what catches a truncated last entry on tar resume. */
	public function test_native_file_archiver_keeps_clone_sentinel() {
		$backup = new class() extends MainWP_Backup {
			public $archive_args;
			public function create_full_backup( $excludes = array(), $filePrefix = '', $addConfig = true, $includeCoreFiles = true, $file_descriptors = 0, $fileSuffix = false, $excludezip = false, $excludenonwp = false, $loadFilesBeforeZip = true, $ext = 'zip', $pid = false, $append = false ) {
				$this->archive_args = func_get_args();
				return array( 'file' => $fileSuffix, 'filesize' => 2048 );
			}
		};
		$slot = new ReflectionProperty( MainWP_Backup::class, 'instance' );
		$slot->setAccessible( true );
		$original = $slot->getValue();
		$slot->setValue( null, $backup );
		$method = new ReflectionMethod( MainWP_Child_Backup_Task_Runner::class, 'run_file_archiver' );
		$method->setAccessible( true );
		try {
			$result = $method->invoke( $this->runner, array( 'ext' => 'tar' ), 'native.tar', false, time() + 30, array(), true );
			$this->assertSame( 'native.tar', $result['file'] );
			$this->assertTrue( $backup->archive_args[2], 'Native archives keep the clone entry as the tar resume sentinel; restore skips it.' );
			$this->assertTrue( $backup->archive_args[3], 'Native archives must still include core files.' );
		} finally {
			$slot->setValue( null, $original );
		}
	}

	/**
	 * Legacy work has no task row for the native creation guard to find.
	 */
	public function test_create_task_refuses_legacy_mutex() {
		$spine = MainWP_Child_Backup_Task::instance();
		$this->assertEmpty( $spine->get_active_tasks() );
		$this->assertTrue( MainWP_Child_Backup_Mutex::acquire( 'legacy', 'legacy-owner', 300 ) );

		try {
			$this->runner->create_task_for_params( 'full', array() );
			$this->fail( 'A native task must not start during a legacy backup.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( MainWP_Backup_Error::ERR_ALREADY_RUNNING, $e->get_message_error_code() );
		}
		$this->assertEmpty( $spine->get_active_tasks() );
	}

	/**
	 * Legacy work starting after task creation must keep its queued fragments.
	 */
	public function test_fresh_dump_refuses_legacy_mutex_without_deleting_fragments() {
		global $wpdb;
		$fragment = $this->backup_dir . '/dbBackup-' . \MainWP\Child\MainWP_Helper::sanitize_filename( $wpdb->posts ) . '.sql.php';
		file_put_contents( $fragment, 'legacy dump' );
		file_put_contents( $fragment . '.tmp', 'legacy partial' );
		$task_id = $this->runner->create_task_for_params( 'full', array() );
		$this->assertTrue( MainWP_Child_Backup_Mutex::release( $task_id ) );
		$this->assertTrue( MainWP_Child_Backup_Mutex::acquire( 'legacy', 'legacy-owner', 300 ) );

		$task = $this->advance( $task_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );
		$this->assertSame( MainWP_Backup_Error::ERR_ALREADY_RUNNING, MainWP_Child_Backup_Task::instance()->status_payload( $task )['error_code'] );
		$this->assertSame( 'legacy dump', file_get_contents( $fragment ) );
		$this->assertSame( 'legacy partial', file_get_contents( $fragment . '.tmp' ) );
		$this->assertSame( array(), $this->runner->seam_names() );
		$this->assertNull( $task->lease_owner );
		$this->assertSame( $task->version, $this->advance( $task_id )->version, 'A refused dump must stay terminal.' );
	}

	/**
	 * Stale fragments must not be published as the fresh dump's contents.
	 */
	public function test_fresh_dump_rewrites_stale_target_fragment() {
		global $wpdb;
		$task_id = $this->create_task( 'full' );
		$fragment = $this->backup_dir . '/dbBackup-' . $task_id . '-' . \MainWP\Child\MainWP_Helper::sanitize_filename( $wpdb->posts ) . '.sql.php';
		file_put_contents( $fragment, 'stale dump' );
		file_put_contents( $fragment . '.tmp', 'stale partial' );

		$this->runner->stubs['dump_tier1'] = function ( $prefix ) use ( $task_id, $fragment ) {
			$spine     = MainWP_Child_Backup_Task::instance();
			$task      = $spine->get_task( $task_id );
			$sub_state = $spine->read_sub_state( $task, $this->backup_dir );
			$this->assertSame( $prefix, $sub_state['db_prefix'], 'The prefix must be persisted before the first dump write.' );
			$this->assertFileDoesNotExist( $fragment . '.tmp' );
			if ( ! file_exists( $fragment ) ) {
				file_put_contents( $fragment, 'fresh dump' );
			}
			return array( $fragment );
		};

		$task = $this->advance( $task_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertSame( 'fresh dump', file_get_contents( $fragment ) );
	}

	/**
	 * A foreign engine owner also stops a dump with a persisted cursor.
	 */
	public function test_resumed_dump_refuses_foreign_mutex_without_deleting_fragments() {
		global $wpdb;
		$prefix   = $this->backup_dir . '/dbBackup';
		$fragment = $prefix . '-' . \MainWP\Child\MainWP_Helper::sanitize_filename( $wpdb->posts ) . '.sql.php';
		file_put_contents( $fragment, 'completed table' );
		file_put_contents( $fragment . '.tmp', 'partial table' );
		$cursor  = array( 'index' => 1 );
		$task_id = MainWP_Child_Backup_Task::instance()->create_task(
			'full',
			array(
				'db_prefix' => $prefix,
				'db_tier'   => 2,
				'db_cursor' => $cursor,
			)
		);
		$this->runner->stubs['dump_tier2'] = function ( $dump_prefix, $dump_cursor ) use ( $prefix, $fragment, $cursor ) {
			$this->assertSame( $prefix, $dump_prefix );
			$this->assertSame( $cursor, $dump_cursor );
			$this->assertSame( 'completed table', file_get_contents( $fragment ) );
			$this->assertSame( 'partial table', file_get_contents( $fragment . '.tmp' ) );
			return array(
				'done'   => false,
				'cursor' => $dump_cursor,
			);
		};

		$this->assertTrue( MainWP_Child_Backup_Mutex::acquire( 'legacy', 'legacy-owner', 300 ) );
		$task = $this->advance( $task_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );
		$this->assertSame( MainWP_Backup_Error::ERR_ALREADY_RUNNING, MainWP_Child_Backup_Task::instance()->status_payload( $task )['error_code'] );
		$this->assertSame( array(), $this->runner->seam_names() );
		$this->assertSame( 'legacy-owner', MainWP_Child_Backup_Mutex::holder()['owner'] );
		$this->assertSame( 'completed table', file_get_contents( $fragment ) );
		$this->assertSame( 'partial table', file_get_contents( $fragment . '.tmp' ) );
	}

	public function test_restore_dump_heartbeat_does_not_refresh_foreign_mutex() {
		$spine = MainWP_Child_Backup_Task::instance();
		$task_id = $spine->create_task( 'restore', array() );
		$runner = new \MainWP\Child\MainWP_Child_Restore_Task_Runner();
		$id = new ReflectionProperty( MainWP_Child_Backup_Task_Runner::class, 'current_task_id' );
		$id->setAccessible( true );
		$id->setValue( $runner, $task_id );
		$this->assertTrue( $spine->acquire_lease( $task_id, 'restore-test', 0 ) );
		$owner = new ReflectionProperty( MainWP_Child_Backup_Task_Runner::class, 'current_lease_owner' );
		$owner->setAccessible( true );
		$owner->setValue( $runner, 'restore-test' );
		$method = new ReflectionMethod( MainWP_Child_Backup_Task_Runner::class, 'dump_heartbeat' );
		$method->setAccessible( true );
		$this->assertTrue( MainWP_Child_Backup_Mutex::acquire( 'legacy', 'restore-foreign', 300 ) );
		$before = MainWP_Child_Backup_Mutex::holder();
		$heartbeat = $method->invoke( $runner );
		$heartbeat();
		$this->assertSame( $before, MainWP_Child_Backup_Mutex::holder() );
		MainWP_Child_Backup_Mutex::release( 'restore-foreign' );
		$heartbeat = $method->invoke( $runner );
		$heartbeat();
		$this->assertNull( MainWP_Child_Backup_Mutex::holder() );
	}

	public function test_heartbeat_renews_invocation_without_version_change_and_abandons_takeover() {
		global $wpdb;
		$spine = MainWP_Child_Backup_Task::instance();
		$task_id = $this->create_task( 'db' );
		$successor = null;
		$this->runner->stubs['dump_tier1'] = function () use ( $wpdb, $spine, $task_id, &$successor ) {
			$method = new ReflectionMethod( MainWP_Child_Backup_Task_Runner::class, 'dump_heartbeat' );
			$method->setAccessible( true );
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . $spine->get_table_name() . ' SET lease_expires_at = DATE_ADD(NOW(), INTERVAL 5 SECOND) WHERE task_id = %s', $task_id ) );
			$before = $spine->get_task( $task_id );
			$heartbeat = $method->invoke( $this->runner );
			$heartbeat();
			$after = $spine->get_task( $task_id );
			$this->assertSame( $before->version, $after->version );
			$this->assertSame( $before->lease_owner, $after->lease_owner );
			$this->assertGreaterThan( $before->lease_expires_at, $after->lease_expires_at );
			$this->assertTrue( $spine->renew_lease( $task_id, $before->lease_owner ), 'Same-second renewal must succeed.' );
			$this->assertFalse( $spine->acquire_lease( $task_id, 'competing-runner', (int) $after->version ) );
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . $spine->get_table_name() . ' SET lease_expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE task_id = %s', $task_id ) );
			$this->assertFalse( $spine->renew_lease( $task_id, $before->lease_owner ), 'Expired leases must not be revived.' );
			$this->assertTrue( $spine->acquire_lease( $task_id, 'successor', (int) $after->version ) );
			$successor = $spine->get_task( $task_id );
			$this->assertFalse( $spine->renew_lease( $task_id, $before->lease_owner ) );
			file_put_contents( $this->backup_dir . '/files.list.staged', 'successor bytes' );
			$heartbeat = $method->invoke( $this->runner );
			$heartbeat();
			$this->fail( 'A stolen lease must interrupt engine work.' );
		};
		$task = $this->advance( $task_id );
		$this->assertNotNull( $successor );
		$this->assertSame( $successor->version, $task->version );
		$this->assertSame( $successor->sub_state, $task->sub_state );
		$this->assertSame( 'successor', $task->lease_owner );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_DUMPING_DB, $task->phase );
		$this->assertSame( 'successor bytes', file_get_contents( $this->backup_dir . '/files.list.staged' ) );
		$this->assertSame( $task_id, MainWP_Child_Backup_Mutex::holder()['owner'] );
		$this->assertNotContains( 'archive_db_artifact', $this->runner->seam_names() );
	}

	public function test_dump_callbacks_refresh_progress_throttle_and_fail_after_takeover() {
		global $wpdb;
		$slot = new ReflectionProperty( \MainWP\Child\MainWP_Backup_Db_Dumper::class, 'instance' );
		$slot->setAccessible( true );
		$original = $slot->getValue();
		$dumper = new class() extends \MainWP\Child\MainWP_Backup_Db_Dumper {
			public $invoke;
			public function dump_to_files( $prefix, $budget = null, $heartbeat = null ) {
				call_user_func( $this->invoke, $heartbeat );
				throw new MainWP_Backup_Budget_Exception( 'stop slice', null, '' );
			}
			public function dump_tier2_slice( $prefix, $cursor, $budget = 45, $heartbeat = null ) {
				call_user_func( $this->invoke, $heartbeat );
				return array( 'done' => false, 'cursor' => array( 'index' => 0 ) );
			}
		};
		$slot->setValue( null, $dumper );
		try {
			foreach ( array( 1, 2 ) as $tier ) {
				foreach ( array( false, true ) as $foreign ) {
					$successor_id = null;
					$task_id = $this->create_task( 'full' );
					$this->runner->stubs['tier1_available'] = 1 === $tier;
					$method = new ReflectionMethod( MainWP_Child_Backup_Task_Runner::class, 'dump_tier' . $tier );
					$method->setAccessible( true );
					$this->runner->stubs['dump_tier' . $tier] = function ( ...$args ) use ( $method ) {
						return $method->invokeArgs( $this->runner, $args );
					};
					$dumper->invoke = function ( $heartbeat ) use ( $task_id, $foreign, $wpdb, &$successor_id ) {
						$this->assertIsCallable( $heartbeat );
						if ( $foreign ) {
							MainWP_Child_Backup_Mutex::release( $task_id );
							// The successor only has to own the mutex; the table allows one non-terminal task.
							$successor_id = 'task-successor-' . uniqid();
							$this->assertTrue( MainWP_Child_Backup_Mutex::acquire( 'native', $successor_id, 3600 ) );
							file_put_contents( $this->backup_dir . '/files.list.staged', 'successor manifest' );
							file_put_contents( $this->backup_dir . '/walk-run-successor', 'successor walk' );
							$heartbeat();
							$this->fail( 'Lost ownership must interrupt the dump.' );
						}
						$spine = MainWP_Child_Backup_Task::instance();
						$touch = new ReflectionProperty( $spine, 'last_touch' );
						$touch->setAccessible( true );
						$touch->setValue( $spine, 0 );
						$wpdb->update( $spine->get_table_name(), array( 'last_progress_at' => '2000-01-01 00:00:00' ), array( 'task_id' => $task_id ) );
						$before = MainWP_Child_Backup_Mutex::holder()['expires_at'];
						$heartbeat();
						$this->assertNotSame( '2000-01-01 00:00:00', $spine->get_task( $task_id )->last_progress_at );
						$after = MainWP_Child_Backup_Mutex::holder()['expires_at'];
						$this->assertGreaterThan( $before, $after );
						$touch->setValue( $spine, 0 );
						$wpdb->update( $spine->get_table_name(), array( 'last_progress_at' => '2000-01-01 00:00:00' ), array( 'task_id' => $task_id ) );
						$heartbeat();
						$this->assertSame( $after, MainWP_Child_Backup_Mutex::holder()['expires_at'] );
						$this->assertSame( '2000-01-01 00:00:00', $spine->get_task( $task_id )->last_progress_at );
					};
					$task = $this->advance( $task_id );
					$this->assertSame( $foreign ? MainWP_Child_Backup_Task::PHASE_FAILED : MainWP_Child_Backup_Task::PHASE_DUMPING_DB, $task->phase );
					if ( $foreign ) {
						$state = MainWP_Child_Backup_Task::instance()->read_sub_state( $task, $this->backup_dir );
						$this->assertSame( \MainWP\Child\MainWP_Backup_Error::ERR_ALREADY_RUNNING, $state['error']['code'] );
						$this->assertFileExists( $this->backup_dir . '/files.list.staged' );
						$this->assertFileExists( $this->backup_dir . '/walk-run-successor' );
						$this->assertSame( $successor_id, MainWP_Child_Backup_Mutex::holder()['owner'] );
						unlink( $this->backup_dir . '/files.list.staged' );
						unlink( $this->backup_dir . '/walk-run-successor' );
						MainWP_Child_Backup_Mutex::release( $successor_id );
					}
					MainWP_Child_Backup_Mutex::release( $task_id );
					$wpdb->delete( MainWP_Child_Backup_Task::instance()->get_table_name(), array( 'task_id' => $task_id ) );
				}
			}
		} finally {
			$slot->setValue( null, $original );
		}
	}

	public function test_resumed_dump_preserves_existing_target_fragments() {
		global $wpdb;
		$task_id = $this->create_task( 'full' );
		$this->runner->stubs['dump_tier1'] = new MainWP_Backup_Budget_Exception( 'over budget', null, '' );
		$task = $this->advance( $task_id );
		$state = MainWP_Child_Backup_Task::instance()->read_sub_state( $task, $this->backup_dir );
		$prefix = $state['db_prefix'];
		$this->assertSame( $this->backup_dir . '/dbBackup-' . $task_id, $prefix );
		$fragment = $prefix . '-' . \MainWP\Child\MainWP_Helper::sanitize_filename( $wpdb->posts ) . '.sql.php';
		$cursor = array( 'index' => 1 );
		$this->runner->stubs['dump_tier2'] = function ( $dump_prefix ) use ( $prefix, $fragment, $cursor ) {
			$this->assertSame( $prefix, $dump_prefix );
			file_put_contents( $fragment, 'completed table' );
			file_put_contents( $fragment . '.tmp', 'partial table' );
			return array( 'done' => false, 'cursor' => $cursor );
		};
		$this->advance( $task_id );
		$this->runner->stubs['dump_tier2'] = function ( $dump_prefix, $dump_cursor ) use ( $prefix, $fragment, $cursor ) {
			$this->assertSame( $prefix, $dump_prefix );
			$this->assertSame( $cursor, $dump_cursor );
			$this->assertSame( 'completed table', file_get_contents( $fragment ) );
			$this->assertSame( 'partial table', file_get_contents( $fragment . '.tmp' ) );
			return array(
				'done'   => false,
				'cursor' => $dump_cursor,
			);
		};

		$task = $this->advance( $task_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_DUMPING_DB, $task->phase );
		$this->assertContains( 'dump_tier2', $this->runner->seam_names() );
		$this->assertSame( $prefix, MainWP_Child_Backup_Task::instance()->read_sub_state( $task, $this->backup_dir )['db_prefix'] );
		$this->assertSame( 'completed table', file_get_contents( $fragment ) );
		$this->assertSame( 'partial table', file_get_contents( $fragment . '.tmp' ) );
	}

	/**
	 * A fresh dump owns only its table names under its exact prefix.
	 */
	public function test_fresh_dump_preserves_other_fragment_names() {
		global $wpdb;
		$task_id = $this->create_task( 'full' );
		$table    = \MainWP\Child\MainWP_Helper::sanitize_filename( $wpdb->posts );
		$fragment = $this->backup_dir . '/dbBackup-' . $task_id . '-' . $table . '.sql.php';
		$others   = array(
			$this->backup_dir . '/dbBackup-1700000000-' . $table . '.sql.php',
			$this->backup_dir . '/dbBackup-not_a_dump_table.sql.php',
		);
		foreach ( array_merge( array( $fragment, $fragment . '.tmp' ), $others ) as $file ) {
			file_put_contents( $file, 'existing dump' );
		}
		$this->runner->stubs['tier1_available'] = false;
		$this->runner->stubs['dump_tier2']      = function ( $prefix, $cursor ) use ( $task_id, $fragment ) {
			$spine     = MainWP_Child_Backup_Task::instance();
			$sub_state = $spine->read_sub_state( $spine->get_task( $task_id ), $this->backup_dir );
			$this->assertSame( $prefix, $sub_state['db_prefix'] );
			$this->assertNull( $cursor );
			$this->assertFileDoesNotExist( $fragment );
			$this->assertFileDoesNotExist( $fragment . '.tmp' );
			file_put_contents( $fragment, 'fresh dump' );
			return array(
				'done'  => true,
				'files' => array( $fragment ),
			);
		};

		$task = $this->advance( $task_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		foreach ( $others as $file ) {
			$this->assertSame( 'existing dump', file_get_contents( $file ) );
		}
	}

	public function test_interrupted_tier1_attempt_is_checkpointed_and_resumes_in_tier2() {
		$spine = MainWP_Child_Backup_Task::instance();
		$task_id = $this->create_task( 'db' );
		$interrupted = null;
		$this->runner->stubs['dump_tier1'] = function () use ( $spine, $task_id, &$interrupted ) {
			$interrupted = $spine->read_sub_state( $spine->get_task( $task_id ), $this->backup_dir );
			$this->assertSame( 1, $interrupted['db_tier'] );
			$this->assertSame( 1, $interrupted['tier1_started'] );
			$this->assertEmpty( $interrupted['db_files'] ?? array() );
			throw new MainWP_Backup_Budget_Exception( 'interrupt', null, '' );
		};
		$task = $this->advance( $task_id );
		$this->assertNotNull( $interrupted );
		// Restore the durable state a hard kill would leave, before the exception handler ran.
		$this->assertNotFalse( $spine->checkpoint( $task_id, (int) $task->version, array( 'sub_state' => $interrupted ) ) );
		$this->runner->calls = array();
		$this->runner->stubs['dump_tier2'] = array( 'done' => true, 'files' => array( 'table.sql.php' ) );
		$task = $this->advance( $task_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertContains( 'dump_tier2', $this->runner->seam_names() );
		$this->assertNotContains( 'dump_tier1', $this->runner->seam_names() );
		$this->assertSame( 1, $spine->read_sub_state( $task, $this->backup_dir )['tier1_attempted'] );
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

	public function test_manifest_walk_exception_marks_failed() {
		$this->runner->stubs['walk_manifest_slice'] = new \RuntimeException( 'walk exploded' );
		$spine   = MainWP_Child_Backup_Task::instance();
		$task_id = $this->runner->create_task_for_params( 'full', array( 'ext' => 'tar', 'emit_manifest' => true ) );
		$this->assertFileExists( $this->backup_dir . '/backup-artifact.tar' );

		$task = $this->advance( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );
		$sub_state = $spine->read_sub_state( $task, $this->backup_dir );
		$this->assertSame( MainWP_Backup_Error::ERR_MANIFEST_WALK_FAILED, $sub_state['error']['code'] );
		$this->assertStringContainsString( 'walk exploded', $sub_state['error']['message'] );
		$this->assertFileDoesNotExist( $this->backup_dir . '/task_substate-' . $task_id . '.json' );
		$this->assertFileDoesNotExist( $this->backup_dir . '/backup-artifact.tar' );
		$payload = $spine->status_payload( $task, $this->backup_dir );
		$this->assertSame( MainWP_Backup_Error::ERR_MANIFEST_WALK_FAILED, $payload['error_code'] );
	}

	public function test_manifest_walk_exception_preserves_error_code() {
		$this->runner->stubs['walk_manifest_slice'] = new MainWP_Exception( 'no dir', null, MainWP_Backup_Error::ERR_DIR_NOT_WRITABLE );
		$spine   = MainWP_Child_Backup_Task::instance();
		$task_id = $this->runner->create_task_for_params( 'full', array( 'ext' => 'tar', 'emit_manifest' => true ) );
		$this->assertFileExists( $this->backup_dir . '/backup-artifact.tar' );

		$task = $this->advance( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );
		$sub_state = $spine->read_sub_state( $task, $this->backup_dir );
		$this->assertSame( MainWP_Backup_Error::ERR_DIR_NOT_WRITABLE, $sub_state['error']['code'] );
		$this->assertStringContainsString( 'no dir', $sub_state['error']['message'] );
		$this->assertFileDoesNotExist( $this->backup_dir . '/task_substate-' . $task_id . '.json' );
		$this->assertFileDoesNotExist( $this->backup_dir . '/backup-artifact.tar' );
		$payload = $spine->status_payload( $task, $this->backup_dir );
		$this->assertSame( MainWP_Backup_Error::ERR_DIR_NOT_WRITABLE, $payload['error_code'] );
	}

	public function test_incremental_manifest_walk_exception_marks_failed() {
		$this->runner->stubs['walk_manifest_slice'] = new \RuntimeException( 'walk exploded' );
		$spine   = MainWP_Child_Backup_Task::instance();
		$task_id = $this->create_task( 'incremental' );

		$task = $this->advance( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );
		$this->assertNull( $task->lease_owner );
		$sub_state = $spine->read_sub_state( $task, $this->backup_dir );
		$this->assertSame( MainWP_Backup_Error::ERR_MANIFEST_WALK_FAILED, $sub_state['error']['code'] );
		$this->assertSame( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_MANIFEST_WALK_FAILED ) . ' walk exploded', $sub_state['error']['message'] );
		$this->assertNotContains( 'run_incremental_diff', $this->runner->seam_names() );
		$this->assertFileDoesNotExist( $this->backup_dir . '/task_substate-' . $task_id . '.json' );
		$payload = $spine->status_payload( $task, $this->backup_dir );
		$this->assertSame( MainWP_Backup_Error::ERR_MANIFEST_WALK_FAILED, $payload['error_code'] );
	}

	public function test_full_manifest_walk_completes_with_artifacts() {
		$this->runner->stubs['walk_manifest_slice'] = array(
			'done'        => true,
			'size'        => 128,
			'count'       => 1,
			'total_bytes' => 2048,
		);
		$spine   = MainWP_Child_Backup_Task::instance();
		$task_id = $this->runner->create_task_for_params( 'full', array( 'ext' => 'tar', 'emit_manifest' => true ) );

		$this->runner->stubs['promote_manifest_sidecars'] = function () use ( $spine, $task_id ) {
			$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $spine->get_task( $task_id )->phase );
			$this->assertSame( $task_id, MainWP_Child_Backup_Mutex::holder()['owner'] );
			return true;
		};

		$task = $this->advance( $task_id );
		$this->assertNull( MainWP_Child_Backup_Mutex::holder() );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$sub_state = $spine->read_sub_state( $task, $this->backup_dir );
		$this->assertSame( array( 'full', 'manifest' ), array_column( $sub_state['artifacts'], 'role' ) );
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
			global $wp_filter;
			$key = _wp_filter_build_unique_id( 'mainwp_child_backup_task_resume', array( $runner, 'handle_watchdog_resume' ), 10 );
			$this->assertSame( 3, $wp_filter['mainwp_child_backup_task_resume']->callbacks[10][ $key ]['accepted_args'] );
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

	public static function required_dump_archives() {
		return array(
			'full tar' => array( 'full', 'tar', MainWP_Backup_Error::ERR_TAR_WRITE_FAILED ),
			'full zip' => array( 'full', 'zip', MainWP_Backup_Error::ERR_ZIP_FAILED ),
			'db tar'   => array( 'db', 'tar', MainWP_Backup_Error::ERR_TAR_WRITE_FAILED ),
			'db zip'   => array( 'db', 'zip', MainWP_Backup_Error::ERR_ZIP_FAILED ),
		);
	}

	/** @dataProvider required_dump_archives */
	public function test_prepared_dump_failed_add_fails_backup( $kind, $ext, $code ) {
		$uploads = wp_upload_dir();
		$source = $uploads['basedir'] . '/required-' . wp_generate_uuid4() . '.payload';
		file_put_contents( $source, 'prepared dump bytes' );
		$backup = new MainWP_Backup();
		$files = array( $source, $source . '.missing' );
		$backup->set_prepared_db_files( $files );
		$archive = $this->backup_dir . '/required.' . $ext;
		$tar = null;
		try {
			try {
				if ( 'db' === $kind ) {
					$backup->archive_db_files( $files, $this->backup_dir . '/required', $ext );
				} elseif ( 'zip' === $ext ) {
					$backup->create_zip_full_backup( $archive, array( '*' ), false, true, false, false );
				} else {
					$tar = new \MainWP\Child\Tar_Archiver( $backup, $ext );
					$tar->create_full_backup( $archive, array( '*' ), false, true, false, false );
				}
				$this->fail( 'A required prepared dump was omitted without failing the backup.' );
			} catch ( MainWP_Exception $e ) {
				$this->assertSame( $code, $e->get_message_error_code() );
			}
			if ( 'db' === $kind ) {
				$this->assertFileDoesNotExist( $this->backup_dir . '/required.sql.' . $ext );
			}
		} finally {
			$backup->set_prepared_db_files( null );
			if ( null !== $tar && $tar->is_open() ) { $tar->close(); }
			if ( file_exists( $source ) ) { unlink( $source ); }
			MainWP_Backup::reset_skipped_paths();
		}
	}

	public static function interrupted_db_archive_formats() {
		return array(
			'zip' => array( 'zip' ),
			'tar' => array( 'tar' ),
		);
	}

	/** @dataProvider interrupted_db_archive_formats */
	public function test_db_archive_heartbeat_failure_releases_archive( $ext ) {
		if ( 'zip' === $ext && ! class_exists( '\\ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive is required to exercise deferred archive writes.' );
		}
		$uploads = wp_upload_dir();
		$source = $uploads['basedir'] . '/heartbeat-' . wp_generate_uuid4();
		$files = array( $source . '-first.sql.php', $source . '-second.sql.php' );
		$backup = new class() extends MainWP_Backup {
			public $heartbeat_error;
			public $failed_archiver;
			private $heartbeats = 0;
			public function heartbeat() {
				if ( 2 === ++$this->heartbeats ) {
					$this->failed_archiver = $this->archiver;
					throw $this->heartbeat_error;
				}
			}
		};
		$error = new MainWP_Exception( 'heartbeat lost ownership', null, MainWP_Backup_Error::ERR_ALREADY_RUNNING );
		$backup->heartbeat_error = $error;
		$prefix = $this->backup_dir . '/heartbeat';
		$archive = $prefix . '.sql.' . $ext;
		try {
			foreach ( $files as $file ) {
				$this->assertNotFalse( file_put_contents( $file, 'prepared dump bytes' ) );
			}
			if ( 'tar' === $ext ) {
				// Tar throttles heartbeats for 40 seconds, so fail its first call with an open archive.
				$backup->heartbeat();
			}
			try {
				$backup->archive_db_files( $files, $prefix, $ext );
				$this->fail( 'The second heartbeat must interrupt database archiving.' );
			} catch ( MainWP_Exception $e ) {
				$this->assertSame( $error, $e );
			}
			$this->assertFileDoesNotExist( $archive );
			if ( 'tar' === $ext ) {
				$this->assertFalse( $backup->failed_archiver->is_open(), 'A failed database archive must release its tar stream.' );
			}
			unset( $backup );
			gc_collect_cycles();
			clearstatcache( true, $archive );
			$this->assertFileDoesNotExist( $archive, 'Releasing the backup must not recreate a partial archive.' );
		} finally {
			unset( $backup );
			gc_collect_cycles();
			foreach ( $files as $file ) {
				if ( file_exists( $file ) ) { unlink( $file ); }
			}
			MainWP_Backup::reset_skipped_paths();
		}
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
	 * Completion removes every superseded sidecar. A cursor still too big for
	 * the row keeps the one sidecar the row points to, so the finished
	 * artifact stays readable until prune_task() removes it with the row.
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
		$serialized = $spine->write_sub_state( $task_id, $big, $this->backup_dir, (int) $task->version );
		$spine->checkpoint( $task_id, (int) $task->version, array( 'sub_state' => $serialized ) );
		$this->assertFileExists( $this->backup_dir . '/' . json_decode( $serialized, true )['file'] );

		$task = $this->advance( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertFileDoesNotExist( $this->backup_dir . '/' . json_decode( $serialized, true )['file'], 'Superseded sidecars go at completion' );
		$live = json_decode( $task->sub_state, true );
		$this->assertSame( array( $this->backup_dir . '/' . $live['file'] ), (array) glob( $this->backup_dir . '/task_substate-' . $task_id . '-v*.json' ), 'Only the sidecar the row points to remains' );
		$this->assertSame( 'backup-artifact.tar', $spine->status_payload( $task, $this->backup_dir )['file'] );

		$this->assertSame( 'pruned', $spine->prune_task( $task_id, $this->backup_dir ) );
		$this->assertCount( 0, (array) glob( $this->backup_dir . '/task_substate-' . $task_id . '-v*.json' ), 'No sidecar litter after prune' );
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
	 * The recorded artifact size comes from the archive on disk, not from
	 * the engine's reported figure. The engine reads its own size right
	 * after closing the file it has been statting all along, where a
	 * stale stat-cache entry would report a pre-close byte count.
	 */
	public function test_full_backup_records_disk_size_not_engine_report() {
		$this->runner->stubs['run_file_archiver'] = array(
			'file'     => 'backup-artifact.tar',
			'filesize' => 99,
		);

		$task_id = $this->create_task( 'files' );
		$task    = $this->advance( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );

		$sub_state = MainWP_Child_Backup_Task::instance()->read_sub_state( $task, $this->backup_dir );
		$this->assertSame( 'backup-artifact.tar', $sub_state['artifact']['file'] );
		$this->assertSame( 2048, $sub_state['artifact']['size'], 'The size must be re-read from disk after the engine closes the archive.' );
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

	/**
	 * A completed database-bearing backup surfaces the Woo order-storage
	 * facts captured at dump time, so the dashboard can stamp them onto
	 * the snapshot and refuse a doomed preserve-orders restore at
	 * initiate time instead of after the whole transfer.
	 */
	public function test_completed_db_backup_payload_carries_woo_meta() {
		$this->runner->stubs['woo_backup_meta'] = array(
			'has_wc_orders'          => true,
			'woocommerce_db_version' => '9.4.0',
		);

		$task_id = $this->create_task( 'db' );
		$task    = $this->advance( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );

		$payload = MainWP_Child_Backup_Task::instance()->status_payload( $task, $this->backup_dir );
		$this->assertArrayHasKey( 'woo', $payload );
		$this->assertTrue( $payload['woo']['has_wc_orders'] );
		$this->assertSame( '9.4.0', $payload['woo']['woocommerce_db_version'] );
	}

	/**
	 * A files-only backup dumps no database, so its payload carries no
	 * Woo block - preserve-orders never applies to it.
	 */
	public function test_files_backup_payload_omits_woo_meta() {
		$task_id = $this->create_task( 'files' );
		$task    = $this->advance( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );

		$payload = MainWP_Child_Backup_Task::instance()->status_payload( $task, $this->backup_dir );
		$this->assertArrayNotHasKey( 'woo', $payload );
	}

	/**
	 * An engine failure deletes the partial artifacts the task produced -
	 * the cursor-named files, its token-named incremental parts, and staged
	 * scratch - while the chain files and files it never produced survive.
	 * The recorded error must survive the cleanup.
	 */
	public function test_engine_failure_deletes_partial_artifacts() {
		$spine   = MainWP_Child_Backup_Task::instance();
		$task_id = $this->create_task( 'db' );
		$token   = preg_replace( '/[^A-Za-z0-9]/', '', $task_id );

		$artifact = $this->backup_dir . '/backup-failing.tar';
		$dump     = $this->backup_dir . '/dbBackup-failing-part1.sql';
		$part     = $this->backup_dir . '/plugins.' . $token . '.part001.zip';
		$staged   = $this->backup_dir . '/files.list.staged';
		$changes  = $this->backup_dir . '/changes.list';
		$chain    = $this->backup_dir . '/files.list';
		foreach ( array( $artifact, $dump, $part, $staged, $changes, $chain ) as $file ) {
			file_put_contents( $file, 'x' ); // phpcs:ignore -- test fixture.
		}

		$task = $spine->get_task( $task_id );
		$spine->checkpoint(
			$task_id,
			(int) $task->version,
			array(
				'phase'     => MainWP_Child_Backup_Task::PHASE_UPLOADING,
				'sub_state' => array(
					'params'   => array( 'ext' => 'tar' ),
					'artifact' => array(
						'file' => 'backup-failing.tar',
						'size' => 1,
					),
					'db_files' => array( $dump ),
				),
			)
		);
		$this->runner->stubs['perform_upload'] = new MainWP_Exception( 'upload exploded', null, 'mainwp_backup_upload_failed' );

		$task = $this->advance( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );
		$sub_state = $spine->read_sub_state( $task, $this->backup_dir );
		$this->assertSame( 'upload exploded', $sub_state['error']['message'], 'The recorded error must survive the cleanup' );

		$this->assertFileDoesNotExist( $artifact );
		$this->assertFileDoesNotExist( $dump );
		$this->assertFileDoesNotExist( $part, 'Token-named incremental parts belong to the failed task' );
		$this->assertFileDoesNotExist( $staged, 'Unpromoted staged manifests belong to the failed run' );
		$this->assertFileExists( $changes, 'changes.list is a chain file; the next incremental overwrites it' );
		$this->assertFileExists( $chain, 'The promoted chain base must survive' );
		$this->assertFileExists( $this->backup_dir . '/backup-artifact.tar', 'Files the failed task never produced must survive' );
	}

	/**
	 * A runner whose lease has already been reclaimed by another owner (the
	 * watchdog handing the task to a second runner) must not let its own
	 * failure checkpoint win the CAS: doing so would stamp PHASE_FAILED over
	 * the new holder's in-flight state and delete its artifacts.
	 */
	public function test_stolen_lease_failure_does_not_overwrite_new_holder() {
		$spine    = MainWP_Child_Backup_Task::instance();
		$task_id  = $this->create_task( 'db' );
		$artifact = $this->backup_dir . '/backup-failing.tar';
		file_put_contents( $artifact, 'x' ); // phpcs:ignore -- test fixture.

		$task = $spine->get_task( $task_id );
		$spine->checkpoint(
			$task_id,
			(int) $task->version,
			array(
				'phase'     => MainWP_Child_Backup_Task::PHASE_UPLOADING,
				'sub_state' => array(
					'params'   => array( 'ext' => 'tar' ),
					'artifact' => array(
						'file' => 'backup-failing.tar',
						'size' => 1,
					),
				),
			)
		);

		// The stub simulates the watchdog reclaiming this task's expired
		// lease and dispatching a resume to another runner before this
		// slice's own engine call throws.
		$this->runner->stubs['perform_upload'] = function () use ( $spine, $task_id ) {
			global $wpdb;
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . $spine->get_table_name() . " SET lease_owner = 'watchdog-b', version = version + 1 WHERE task_id = %s", $task_id ) ); // phpcs:ignore -- test fixture.
			throw new MainWP_Exception( 'upload exploded', null, 'mainwp_backup_upload_failed' );
		};

		$task = $this->advance( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_UPLOADING, $task->phase, 'A stale runner must not overwrite the new holder\'s phase' );
		$this->assertSame( 'watchdog-b', $task->lease_owner, 'The new holder\'s lease must survive the stale runner\'s failed CAS' );
		$this->assertFileExists( $artifact, 'A runner that lost its lease must not delete artifacts it no longer owns' );
	}

	public function test_watchdog_resume_uses_one_unique_token_from_acquisition() {
		global $wpdb;
		$spine   = MainWP_Child_Backup_Task::instance();
		$task_id = $this->create_task( 'db' );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . $spine->get_table_name() . ' SET last_progress_at = DATE_SUB(NOW(), INTERVAL 600 SECOND) WHERE task_id = %s', $task_id ) );

		$hook_owner = null;
		$held_owner = null;
		$slice_owner = null;
		$resume_count = null;
		$observe = function ( $task, $version, $owner = null ) use ( $spine, &$hook_owner, &$held_owner, &$resume_count ) {
			$held = $spine->get_task( $task->task_id );
			$hook_owner = $owner;
			$held_owner = $held->lease_owner;
			$resume_count = (int) $held->resume_count;
		};
		$this->runner->stubs['tier1_available'] = function () use ( $spine, $task_id, &$slice_owner ) {
			$slice_owner = $spine->get_task( $task_id )->lease_owner;
			return true;
		};

		remove_all_actions( 'mainwp_child_backup_task_resume' );
		add_action( 'mainwp_child_backup_task_resume', $observe, 1, 3 );
		$this->runner->register_hooks();
		try {
			\MainWP\Child\MainWP_Child_Backup_Watchdog::instance()->check_tasks( 0 );
		} finally {
			remove_all_actions( 'mainwp_child_backup_task_resume' );
		}

		$this->assertNotSame( 'watchdog', $held_owner );
		$this->assertMatchesRegularExpression( '/^watchdog:[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $held_owner );
		$this->assertLessThanOrEqual( 64, strlen( $held_owner ) );
		$this->assertSame( $held_owner, $hook_owner, 'The hook must carry the acquired token' );
		$this->assertSame( $held_owner, $slice_owner, 'The runner must retain the acquired token' );
		$this->assertSame( 1, $resume_count );
		$task = $spine->get_task( $task_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertNull( $task->lease_owner );
	}

	public function test_old_runner_handoff_accepts_live_lease() {
		$spine   = MainWP_Child_Backup_Task::instance();
		$task_id = $this->create_task( 'db' );
		$task    = $spine->get_task( $task_id );
		$owner   = 'watchdog:' . wp_generate_uuid4();
		$this->assertTrue( $spine->acquire_lease( $task_id, 'watchdog', (int) $task->version, 120 ) );
		$held = $spine->get_task( $task_id );

		// An already-loaded old runner calls this on the newly loaded spine.
		$this->assertTrue( $spine->handoff_lease( $task_id, 'watchdog', $owner, (int) $held->version ) );
		$task = $spine->get_task( $task_id );
		$this->assertSame( $owner, $task->lease_owner );
		$this->assertSame( (int) $held->version + 1, (int) $task->version );
		$this->assertSame( $held->lease_expires_at, $task->lease_expires_at, 'Handoff must preserve the watchdog expiry' );
	}

	public function test_old_runner_handoff_refuses_expired_lease() {
		global $wpdb;
		$spine   = MainWP_Child_Backup_Task::instance();
		$task_id = $this->create_task( 'db' );
		$task    = $spine->get_task( $task_id );
		$owner   = 'watchdog:' . wp_generate_uuid4();
		$this->assertTrue( $spine->acquire_lease( $task_id, 'watchdog', (int) $task->version, 120 ) );
		$this->assertSame( 1, $wpdb->query( $wpdb->prepare( 'UPDATE ' . $spine->get_table_name() . ' SET lease_expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE task_id = %s', $task_id ) ) );
		$held = $spine->get_task( $task_id );

		$this->assertFalse( $spine->handoff_lease( $task_id, 'watchdog', $owner, (int) $held->version ) );
		$task = $spine->get_task( $task_id );
		$this->assertSame( $held->lease_owner, $task->lease_owner );
		$this->assertSame( $held->version, $task->version );
		$this->assertSame( $held->lease_expires_at, $task->lease_expires_at );
	}

	public function test_two_argument_watchdog_resume_refuses_stale_version() {
		$spine   = MainWP_Child_Backup_Task::instance();
		$task_id = $this->create_task( 'db' );
		$task    = $spine->get_task( $task_id );
		$owner   = 'watchdog:' . wp_generate_uuid4();
		$this->assertTrue( $spine->acquire_lease( $task_id, $owner, (int) $task->version, 120 ) );
		$held = $spine->get_task( $task_id );

		remove_all_actions( 'mainwp_child_backup_task_resume' );
		$this->runner->register_hooks();
		try {
			do_action( 'mainwp_child_backup_task_resume', $task, (int) $task->version );
			$this->assertSame( array(), $this->runner->calls );
			$this->assertSame( $held->version, $spine->get_task( $task_id )->version );
			do_action( 'mainwp_child_backup_task_resume', $task, (int) $held->version );
		} finally {
			remove_all_actions( 'mainwp_child_backup_task_resume' );
		}

		$task = $spine->get_task( $task_id );
		$this->assertSame( array(), $this->runner->calls );
		$this->assertSame( $held->phase, $task->phase );
		$this->assertSame( $held->version, $task->version );
		$this->assertSame( $held->lease_owner, $task->lease_owner );
		$this->assertSame( $held->sub_state, $task->sub_state );
	}

	public function test_two_argument_watchdog_resume_refuses_literal_owner() {
		$spine    = MainWP_Child_Backup_Task::instance();
		$task_id  = $this->create_task( 'db' );
		$artifact = $this->backup_dir . '/backup-artifact.tar';
		$task     = $spine->get_task( $task_id );
		$version  = $spine->checkpoint(
			$task_id,
			(int) $task->version,
			array(
				'phase'     => MainWP_Child_Backup_Task::PHASE_UPLOADING,
				'sub_state' => array(
					'params'   => array( 'ext' => 'tar' ),
					'artifact' => array( 'file' => 'backup-artifact.tar', 'size' => 2048 ),
				),
			)
		);
		$this->assertNotFalse( $version );
		$this->assertTrue( $spine->acquire_lease( $task_id, 'watchdog', $version, 120 ) );
		$held = $spine->get_task( $task_id );
		$this->assertSame( 'watchdog', $held->lease_owner );
		$this->assertFileExists( $artifact );
		$this->runner->stubs['perform_upload'] = new MainWP_Exception( 'upload exploded', null, 'mainwp_backup_upload_failed' );

		// An old watchdog supplies only the task and its matching version.
		remove_all_actions( 'mainwp_child_backup_task_resume' );
		$this->runner->register_hooks();
		try {
			do_action( 'mainwp_child_backup_task_resume', $task, (int) $held->version );
		} finally {
			remove_all_actions( 'mainwp_child_backup_task_resume' );
		}

		$task = $spine->get_task( $task_id );
		$this->assertSame( array(), $this->runner->calls, 'A dispatch without a token must not run a slice' );
		$this->assertSame( $held->version, $task->version, 'Refusal must not checkpoint or release the lease' );
		$this->assertSame( $held->phase, $task->phase );
		$this->assertSame( $held->sub_state, $task->sub_state );
		$this->assertSame( $held->lease_owner, $task->lease_owner );
		$this->assertFileExists( $artifact, 'Refusal must preserve the task artifact' );
	}

	public function test_legacy_watchdog_failure_does_not_overwrite_new_holder() {
		global $wpdb;
		$spine    = MainWP_Child_Backup_Task::instance();
		$task_id  = $this->create_task( 'db' );
		$artifact = $this->backup_dir . '/backup-failing.tar';
		file_put_contents( $artifact, 'x' );
		$task = $spine->get_task( $task_id );
		$version = $spine->checkpoint(
			$task_id,
			(int) $task->version,
			array(
				'phase' => MainWP_Child_Backup_Task::PHASE_UPLOADING,
				'sub_state' => array(
					'params' => array( 'ext' => 'tar' ),
					'artifact' => array( 'file' => 'backup-failing.tar', 'size' => 1 ),
				),
			)
		);
		$this->assertTrue( $spine->acquire_lease( $task_id, 'watchdog', $version, 120 ) );
		$legacy = $spine->get_task( $task_id );
		$this->assertSame( 'watchdog', $legacy->lease_owner );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . $spine->get_table_name() . ' SET lease_expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND), last_progress_at = DATE_SUB(NOW(), INTERVAL 600 SECOND) WHERE task_id = %s', $task_id ) );

		$new_holder = null;
		$after_failure = null;
		$legacy_failure = function () use ( $spine, $task_id, $legacy, &$new_holder, &$after_failure ) {
			$new_holder = $spine->get_task( $task_id );
			$pre_fail = $spine->read_sub_state( $new_holder, $this->backup_dir );
			// Reproduce the in-flight old runner's catch before the new runner is dispatched.
			$own_row = (string) $new_holder->lease_owner === $legacy->lease_owner;
			$failed = $spine->checkpoint(
				$task_id,
				$own_row ? (int) $new_holder->version : (int) $legacy->version,
				array( 'phase' => MainWP_Child_Backup_Task::PHASE_FAILED, 'sub_state' => array( 'error' => array( 'message' => 'old upload exploded' ) ) )
			);
			if ( false !== $failed ) {
				$this->runner->cleanup_failed_task_artifacts( $task_id, $pre_fail, $this->backup_dir );
				$spine->delete_sidecar( $task_id, $this->backup_dir );
			}
			$spine->release_lease( $task_id, $legacy->lease_owner, false !== $failed ? $failed : (int) $legacy->version );
			$after_failure = $spine->get_task( $task_id );
		};

		remove_all_actions( 'mainwp_child_backup_task_resume' );
		add_action( 'mainwp_child_backup_task_resume', $legacy_failure, 1, 0 );
		try {
			\MainWP\Child\MainWP_Child_Backup_Watchdog::instance()->check_tasks( 0 );
		} finally {
			remove_all_actions( 'mainwp_child_backup_task_resume' );
		}

		$this->assertNotNull( $new_holder );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_UPLOADING, $after_failure->phase );
		$this->assertSame( $new_holder->lease_owner, $after_failure->lease_owner );
		$this->assertSame( $new_holder->version, $after_failure->version, 'The legacy runner must not adopt the successor version' );
		$this->assertSame( $new_holder->sub_state, $after_failure->sub_state );
		$this->assertFileExists( $artifact, 'The successor artifacts must survive the legacy failure' );
		$this->assertNull( $spine->get_task( $task_id )->lease_owner, 'The watchdog releases its token when no runner advances it' );
	}

	public function test_same_lane_stolen_lease_failure_does_not_overwrite_new_holder() {
		global $wpdb;
		$spine = MainWP_Child_Backup_Task::instance();
		foreach ( array( 'dashboard', 'watchdog' ) as $owner ) {
			$task_id  = $this->create_task( 'db' );
			$artifact = $this->backup_dir . '/backup-failing.tar';
			file_put_contents( $artifact, 'x' ); // phpcs:ignore -- test fixture.
			$task = $spine->get_task( $task_id );
			$spine->checkpoint(
				$task_id,
				(int) $task->version,
				array(
					'phase'     => MainWP_Child_Backup_Task::PHASE_UPLOADING,
					'sub_state' => array(
						'params'   => array( 'ext' => 'tar' ),
						'artifact' => array(
							'file' => 'backup-failing.tar',
							'size' => 1,
						),
					),
				)
			);
			$held = 'watchdog' === $owner;
			$first_owner = $held ? $owner . ':' . wp_generate_uuid4() : $owner;
			$next_owner = $owner . ':' . wp_generate_uuid4();
			if ( $held ) {
				$task = $spine->get_task( $task_id );
				$this->assertTrue( $spine->acquire_lease( $task_id, $first_owner, (int) $task->version, 120 ) );
			}

			$new_holder = null;
			$this->runner->stubs['perform_upload'] = function () use ( $spine, $task_id, $next_owner, &$new_holder ) {
				global $wpdb;
				$wpdb->query( $wpdb->prepare( 'UPDATE ' . $spine->get_table_name() . ' SET lease_expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE task_id = %s', $task_id ) ); // phpcs:ignore -- test fixture.
				$task = $spine->get_task( $task_id );
				$this->assertTrue( $spine->acquire_lease( $task_id, $next_owner, (int) $task->version, 120 ) );
				$new_holder = $spine->get_task( $task_id );
				throw new MainWP_Exception( 'upload exploded', null, 'mainwp_backup_upload_failed' );
			};

			$task = $this->advance( $task_id, $first_owner, $held );

			$this->assertNotNull( $new_holder );
			$this->assertSame( MainWP_Child_Backup_Task::PHASE_UPLOADING, $task->phase, 'A stale runner must not overwrite the same lane\'s new holder' );
			$this->assertSame( $next_owner, $task->lease_owner );
			$this->assertSame( $new_holder->version, $task->version, 'The stale runner must neither checkpoint nor release the new lease' );
			$this->assertSame( $new_holder->sub_state, $task->sub_state );
			$this->assertFileExists( $artifact, 'The new holder\'s artifacts must survive' );
			// The task is left non-terminal by design, so nothing releases its mutex for the next lane,
			// and the next lane cannot insert while its row still holds the active slot.
			MainWP_Child_Backup_Mutex::release( $task_id );
			$wpdb->delete( $spine->get_table_name(), array( 'task_id' => $task_id ) );
		}
	}

	public function test_task_creation_reserves_final_owner_and_terminal_checkpoint_releases() {
		$spine = MainWP_Child_Backup_Task::instance();
		$task_id = $this->runner->create_task_for_params( 'full', array() );
		$this->assertSame( 'native', MainWP_Child_Backup_Mutex::holder()['engine'] );
		$this->assertSame( $task_id, MainWP_Child_Backup_Mutex::holder()['owner'] );
		$this->assertFalse( $spine->checkpoint( $task_id, 99, array( 'phase' => MainWP_Child_Backup_Task::PHASE_FAILED ) ) );
		$this->assertSame( $task_id, MainWP_Child_Backup_Mutex::holder()['owner'] );
		$this->assertSame( 1, $spine->checkpoint( $task_id, 0, array( 'phase' => MainWP_Child_Backup_Task::PHASE_COMPLETED ) ) );
		$this->assertNull( MainWP_Child_Backup_Mutex::holder() );
	}

	public function test_cancel_and_deadline_failure_release_mutex() {
		$spine = MainWP_Child_Backup_Task::instance();
		$task_id = $this->runner->create_task_for_params( 'full', array() );
		$this->runner->cancel_backup_task( $task_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $spine->get_task( $task_id )->phase );
		$this->assertNull( MainWP_Child_Backup_Mutex::holder() );

		$task_id = $this->runner->create_task_for_params( 'full', array() );
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . $spine->get_table_name() . ' SET max_wall_clock_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE task_id = %s', $task_id ) );
		$this->assertTrue( $spine->enforce_wall_clock_deadline( $task_id ) );
		$this->assertNull( MainWP_Child_Backup_Mutex::holder() );
	}

	public function test_failed_insert_and_fresh_pid_refusal_release_mutex() {
		$fail_insert = function ( $sql ) {
			if ( 0 === strpos( $sql, 'INSERT INTO ' . MainWP_Child_Backup_Task::instance()->get_table_name() . ' ' ) ) {
				return 'SELECT 1 WHERE 1 = 0';
			}
			return $sql;
		};
		add_filter( 'query', $fail_insert );
		try {
			$this->runner->create_task_for_params( 'full', array() );
			$this->fail( 'A failed insert must refuse creation.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertNull( MainWP_Child_Backup_Mutex::holder() );
		} finally {
			remove_filter( 'query', $fail_insert );
		}
		file_put_contents( $this->backup_dir . '/backup-old-worker.pid', 'old worker' );
		try {
			$this->runner->create_task_for_params( 'full', array() );
			$this->fail( 'A fresh legacy pid must refuse creation.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( MainWP_Backup_Error::ERR_ALREADY_RUNNING, $e->get_message_error_code() );
			$this->assertNull( MainWP_Child_Backup_Mutex::holder() );
		}
	}

	public function test_new_full_dump_does_not_adopt_another_tasks_fragment() {
		global $wpdb;
		$dumper = \MainWP\Child\MainWP_Backup_Db_Dumper::instance();
		$preflight = $dumper->check_dedicated_connection();
		$this->assertTrue( $preflight['ok'] );
		$stale = $this->backup_dir . '/dbBackup-task-dead-' . \MainWP\Child\MainWP_Helper::sanitize_filename( $wpdb->posts ) . '.sql.php';
		file_put_contents( $stale, 'dead run contents' );
		$task_id = $this->runner->create_task_for_params( 'full', array( 'ext' => 'tar' ) );
		$this->runner->stubs['dump_tier1'] = function ( $prefix ) use ( $dumper, $task_id, $wpdb, $stale ) {
			$this->assertSame( $this->backup_dir . '/dbBackup-' . $task_id, $prefix );
			$result = $dumper->dump_to_files( $prefix );
			$fresh = $prefix . '-' . \MainWP\Child\MainWP_Helper::sanitize_filename( $wpdb->posts ) . '.sql.php';
			$this->assertContains( $fresh, $result['files'] );
			$this->assertFileExists( $fresh );
			$this->assertStringContainsString( 'CREATE TABLE', file_get_contents( $fresh ) );
			$this->assertNotContains( $stale, $result['files'] );
			return $result['files'];
		};
		$task = $this->advance( $task_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertSame( 'dead run contents', file_get_contents( $stale ) );
		$this->assertNull( MainWP_Child_Backup_Mutex::holder() );
	}

	public function test_watchdog_resume_cap_releases_mutex() {
		global $wpdb;
		$spine = MainWP_Child_Backup_Task::instance();
		$task_id = $this->runner->create_task_for_params( 'full', array() );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . $spine->get_table_name() . ' SET resume_count = max_resumes, last_progress_at = DATE_SUB(NOW(), INTERVAL 10 MINUTE) WHERE task_id = %s', $task_id ) );
		\MainWP\Child\MainWP_Child_Backup_Watchdog::instance()->check_tasks( 0 );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $spine->get_task( $task_id )->phase );
		$this->assertSame( MainWP_Backup_Error::ERR_STALLED, $spine->status_payload( $spine->get_task( $task_id ) )['error_code'] );
		$this->assertNull( MainWP_Child_Backup_Mutex::holder() );
	}

	public function test_creation_sweeps_stale_fragments_only_after_acquiring_mutex() {
		$fragment = $this->backup_dir . '/dbBackup-dead-run.sql.php';
		file_put_contents( $fragment, 'dead dump' );
		touch( $fragment, time() - 14400 );
		$this->assertTrue( MainWP_Child_Backup_Mutex::acquire( 'legacy', 'legacy-owner', 300 ) );
		try {
			$this->runner->create_task_for_params( 'full', array() );
			$this->fail( 'A foreign owner must block the sweep.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( MainWP_Backup_Error::ERR_ALREADY_RUNNING, $e->get_message_error_code() );
			$this->assertFileExists( $fragment );
		}
		MainWP_Child_Backup_Mutex::release( 'legacy-owner' );
		$task_id = $this->runner->create_task_for_params( 'full', array() );
		$this->assertSame( $task_id, MainWP_Child_Backup_Mutex::holder()['owner'] );
		$this->assertFileDoesNotExist( $fragment );
	}


	/** Cancellation removes the artifacts named by the pre-checkpoint cursor. */
	public function test_cancel_cleans_partial_artifacts() {
		$spine = MainWP_Child_Backup_Task::instance();
		$task_id = $this->runner->create_task_for_params( 'db', array() );
		$token = preg_replace( '/[^A-Za-z0-9]/', '', $task_id );
		$dump = $this->backup_dir . '/dbBackup-cancel.sql';
		$part = $this->backup_dir . '/plugins.' . $token . '.part001.zip';
		$staged = $this->backup_dir . '/files.list.staged';
		$chain = $this->backup_dir . '/files.list';
		foreach ( array( $dump, $part, $staged, $chain ) as $path ) {
			file_put_contents( $path, 'preserve or remove' );
		}
		$spine->checkpoint( $task_id, 0, array( 'sub_state' => array( 'db_files' => array( $dump ) ) ) );
		$result = $this->runner->cancel_backup_task( $task_id );
		$this->assertSame( 'ok', $result['result'] );
		$this->assertFileDoesNotExist( $dump );
		$this->assertFileDoesNotExist( $part );
		$this->assertFileDoesNotExist( $staged );
		$this->assertFileExists( $chain );
		$this->assertNull( MainWP_Child_Backup_Mutex::holder() );
	}

	/** A successor claims shared scratch files after cancellation checkpoints. */
	public function test_cancel_preserves_successor_scratch_and_deletes_sidecar() {
		global $wpdb;
		$spine = MainWP_Child_Backup_Task::instance();
		$task_id = $this->runner->create_task_for_params( 'db', array() );
		$serialized = $spine->write_sub_state( $task_id, array( 'pad' => str_repeat( 'x', 100000 ) ), $this->backup_dir, 0 );
		$sidecar = $this->backup_dir . '/' . json_decode( $serialized, true )['file'];
		$this->assertFileExists( $sidecar );
		$this->assertNotFalse( $spine->checkpoint( $task_id, 0, array( 'sub_state' => $serialized ) ) );
		MainWP_Child_Backup_Mutex::release( $task_id );
		$scratch = $this->backup_dir . '/files.list.staged';
		$claimed = null;
		$filter = null;
		$filter = function ( $query ) use ( &$filter, &$claimed, $wpdb, $spine, $task_id, $scratch ) {
			if ( 0 === strpos( $query, 'UPDATE ' . $spine->get_table_name() ) && false !== strpos( $query, "phase = 'failed'" ) && false !== strpos( $query, $task_id ) ) {
				remove_filter( 'query', $filter );
				// Execute the checkpoint first, then let the successor win before cleanup.
				$this->assertSame( 1, $wpdb->query( $query ) );
				$claimed = MainWP_Child_Backup_Mutex::acquire( 'native', 'successor-task', 60 );
				file_put_contents( $scratch, 'successor scratch' );
				return 'SELECT 1';
			}
			return $query;
		};
		add_filter( 'query', $filter );
		try {
			$result = $this->runner->cancel_backup_task( $task_id );
		} finally {
			remove_filter( 'query', $filter );
		}
		$this->assertTrue( $claimed );
		$this->assertSame( 'ok', $result['result'] );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $spine->get_task( $task_id )->phase );
		$this->assertFileDoesNotExist( $sidecar );
		$this->assertFileExists( $scratch, 'Cancellation must preserve the successor scratch file.' );
		$this->assertSame( 'successor scratch', file_get_contents( $scratch ) );
		$this->assertSame( 'successor-task', MainWP_Child_Backup_Mutex::holder()['owner'] );
	}

	/**
	 * Lay out the chain files every incremental shares, so a test can prove a
	 * cancel or sweep never deletes them.
	 *
	 * @return string[] Absolute paths.
	 */
	protected function make_chain_files() {
		$chain = array();
		foreach ( array( 'files.list', 'paths.cache', 'files.list.writer.json', 'tombstones.list', 'changes.list', 'parent-files.list' ) as $name ) {
			$chain[ $name ] = $this->backup_dir . '/' . $name;
			file_put_contents( $chain[ $name ], 'chain ' . $name );
		}
		return $chain;
	}

	/**
	 * Checkpoint a task as a run that finished its archive and is waiting in
	 * the upload phase, naming its finished files and the chain files the way
	 * a manifest-emitting run does.
	 *
	 * @param string $task_id Task id.
	 *
	 * @return string[] Absolute paths of the run's own finished files.
	 */
	protected function finish_archive_for_cancel( $task_id ) {
		$spine = MainWP_Child_Backup_Task::instance();
		$own   = array(
			'archive'  => $this->backup_dir . '/backup-safety-1791262128.tar.gz',
			'part'     => $this->backup_dir . '/plugins.part001.zip',
			'manifest' => $this->backup_dir . '/parent-manifest-abcdefabcdef.list',
		);
		foreach ( $own as $path ) {
			file_put_contents( $path, 'finished' );
		}
		$task = $spine->get_task( $task_id );
		$this->assertNotFalse(
			$spine->checkpoint(
				$task_id,
				(int) $task->version,
				array(
					'phase'     => MainWP_Child_Backup_Task::PHASE_UPLOADING,
					'sub_state' => array(
						'params'    => array( 'parent_manifest_file' => basename( $own['manifest'] ) ),
						'artifact'  => array(
							'file' => basename( $own['archive'] ),
							'size' => 8,
						),
						'artifacts' => array(
							array( 'file' => basename( $own['archive'] ), 'role' => 'full' ),
							array( 'file' => basename( $own['part'] ), 'role' => 'part' ),
							array( 'file' => 'files.list', 'role' => 'manifest' ),
							array( 'file' => 'tombstones.list', 'role' => 'tombstones' ),
						),
					),
				)
			)
		);
		return $own;
	}

	/**
	 * A cancelled run never gets a snapshot on the Dashboard, so retention
	 * never names it: the cancel itself deletes the archive it already
	 * finished and its spilled parent manifest, but never the chain base the
	 * next incremental diffs against.
	 */
	public function test_cancel_deletes_finished_artifacts_but_not_chain_files() {
		$task_id = $this->runner->create_task_for_params( 'full', array( 'ext' => 'tar.gz' ) );
		$chain   = $this->make_chain_files();
		$own     = $this->finish_archive_for_cancel( $task_id );

		$result = $this->runner->cancel_backup_task( $task_id );

		$this->assertSame( 'ok', $result['result'] );
		foreach ( $own as $path ) {
			$this->assertFileDoesNotExist( $path, 'A cancelled run must not leave its finished files behind.' );
		}
		foreach ( $chain as $name => $path ) {
			$this->assertFileExists( $path, $name . ' belongs to the chain and must survive a cancel.' );
		}
		$this->assertNull( MainWP_Child_Backup_Mutex::holder() );
	}

	/**
	 * A file another task row still names is never deleted by a cancel, even
	 * when the cancelled cursor names it too.
	 */
	public function test_cancel_keeps_finished_file_another_task_names() {
		$spine  = MainWP_Child_Backup_Task::instance();
		$shared = $this->backup_dir . '/backup-shared.tar.gz';
		file_put_contents( $shared, 'owned by a completed run' );
		$other = $spine->create_task( 'full', array() );
		$spine->checkpoint(
			$other,
			0,
			array(
				'phase'     => MainWP_Child_Backup_Task::PHASE_COMPLETED,
				'sub_state' => array(
					'artifact' => array(
						'file' => 'backup-shared.tar.gz',
						'size' => 1,
					),
				),
			)
		);

		$task_id = $this->runner->create_task_for_params( 'full', array( 'ext' => 'tar.gz' ) );
		$task    = $spine->get_task( $task_id );
		$spine->checkpoint(
			$task_id,
			(int) $task->version,
			array(
				'sub_state' => array(
					'artifact' => array(
						'file' => 'backup-shared.tar.gz',
						'size' => 1,
					),
				),
			)
		);

		$this->assertSame( 'ok', $this->runner->cancel_backup_task( $task_id )['result'] );
		$this->assertFileExists( $shared, 'A file a completed run names belongs to that run.' );
	}

	/**
	 * When another owner holds the backup mutex the cancel cannot know the
	 * shared folder is quiet, so it keeps the finished files and their names
	 * on the row. The next sweep that runs with the mutex free deletes them,
	 * and still never touches the chain files.
	 */
	public function test_cancel_without_mutex_defers_finished_artifacts_to_next_sweep() {
		$spine   = MainWP_Child_Backup_Task::instance();
		$task_id = $this->runner->create_task_for_params( 'full', array( 'ext' => 'tar.gz' ) );
		$chain   = $this->make_chain_files();
		$own     = $this->finish_archive_for_cancel( $task_id );
		MainWP_Child_Backup_Mutex::release( $task_id );
		$this->assertTrue( MainWP_Child_Backup_Mutex::acquire( 'legacy', 'other-engine', 300 ) );

		try {
			$this->assertSame( 'ok', $this->runner->cancel_backup_task( $task_id )['result'] );
			foreach ( $own as $path ) {
				$this->assertFileExists( $path, 'Without the mutex the cancel must not delete files.' );
			}
			$sub = $spine->read_sub_state( $spine->get_task( $task_id ), $this->backup_dir );
			$this->assertSame( basename( $own['archive'] ), $sub['artifact']['file'], 'The row must keep the names for the deferred delete.' );
		} finally {
			MainWP_Child_Backup_Mutex::release( 'other-engine' );
		}

		$spine->sweep_stale_markers( $this->backup_dir );

		foreach ( $own as $path ) {
			$this->assertFileDoesNotExist( $path, 'The next sweep deletes what the cancel deferred.' );
		}
		foreach ( $chain as $name => $path ) {
			$this->assertFileExists( $path, $name . ' belongs to the chain and must survive the sweep.' );
		}
	}

	/**
	 * A cancel without the mutex also leaves the half-written archive, dump
	 * fragments and incremental parts named on the row, so the next sweep
	 * deletes them instead of leaving them in the uploads folder for good.
	 */
	public function test_cancel_without_mutex_defers_mid_flight_files_to_next_sweep() {
		$spine   = MainWP_Child_Backup_Task::instance();
		$task_id = $this->runner->create_task_for_params( 'incremental', array( 'ext' => 'zip' ) );
		$token   = preg_replace( '/[^A-Za-z0-9]/', '', $task_id );
		$chain   = $this->make_chain_files();
		$prefix  = $this->backup_dir . '/dbBackup-' . $task_id;
		$own     = array(
			'partial'   => $this->backup_dir . '/backup-1791291867.zip',
			'fragment'  => $prefix . '-wp_posts.sql.php',
			'closed'    => $this->backup_dir . '/plugins.' . $token . '.part001.zip',
			'open_part' => $this->backup_dir . '/themes.' . $token . '.part001.zip',
		);
		foreach ( $own as $path ) {
			file_put_contents( $path, 'mid-flight' );
		}
		$task = $spine->get_task( $task_id );
		$spine->checkpoint(
			$task_id,
			(int) $task->version,
			array(
				'phase'     => MainWP_Child_Backup_Task::PHASE_ARCHIVING_FILES,
				'sub_state' => array(
					'db_prefix'       => $prefix,
					'db_files'        => array( $own['fragment'] ),
					'archive_file'    => basename( $own['partial'] ),
					'completed_parts' => array( array( 'file' => basename( $own['closed'] ) ) ),
				),
			)
		);
		MainWP_Child_Backup_Mutex::release( $task_id );
		$this->assertTrue( MainWP_Child_Backup_Mutex::acquire( 'legacy', 'other-engine', 300 ) );
		try {
			$this->assertSame( 'ok', $this->runner->cancel_backup_task( $task_id )['result'] );
			foreach ( $own as $path ) {
				$this->assertFileExists( $path, 'Without the mutex the cancel must not delete files.' );
			}
		} finally {
			MainWP_Child_Backup_Mutex::release( 'other-engine' );
		}

		$spine->sweep_stale_markers( $this->backup_dir );

		foreach ( $own as $name => $path ) {
			$this->assertFileDoesNotExist( $path, $name . ' of a cancelled run must go at the next sweep.' );
		}
		foreach ( $chain as $name => $path ) {
			$this->assertFileExists( $path, $name . ' belongs to the chain and must survive the sweep.' );
		}
	}

	/**
	 * A completed run whose cursor is too big for the row spills it to a
	 * sidecar. The terminal cleanup must not leave the row pointing at a
	 * deleted sidecar: the Dashboard still needs the artifact from the status
	 * payload, and a prune needs its name.
	 */
	public function test_completed_run_with_large_cursor_keeps_artifact_visible() {
		$spine   = MainWP_Child_Backup_Task::instance();
		$task_id = $spine->create_task(
			'db',
			array(
				'params' => array(
					'ext'      => 'tar',
					'file'     => '',
					'excludes' => array_fill( 0, 2000, str_repeat( 'x', 40 ) ),
				),
			)
		);
		$this->assertTrue( MainWP_Child_Backup_Mutex::acquire( 'native', $task_id, 3600 ) );

		$task = $this->advance( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$payload = $spine->status_payload( $task, $this->backup_dir );
		$this->assertSame( 'backup-artifact.tar', $payload['file'] ?? null, 'The completed status must still name the artifact.' );

		$this->assertSame( 'pruned', $spine->prune_task( $task_id, $this->backup_dir ) );
		$this->assertFileDoesNotExist( $this->backup_dir . '/backup-artifact.tar', 'A prune must find the artifact of a large-cursor run.' );
	}

	/**
	 * A failed run's cleanup goes through the same ownership and chain rule
	 * as a prune: it deletes the run's own files and scratch, but never the
	 * chain files a completed incremental still needs.
	 */
	public function test_failure_cleanup_keeps_complete_chain_set() {
		$spine   = MainWP_Child_Backup_Task::instance();
		$chain   = $this->make_chain_files();
		$task_id = $this->runner->create_task_for_params( 'full', array( 'ext' => 'tar.gz' ) );
		$token   = preg_replace( '/[^A-Za-z0-9]/', '', $task_id );
		$own     = array(
			$this->backup_dir . '/backup-1791262128.tar.gz',
			$this->backup_dir . '/plugins.' . $token . '.part001.zip',
			$this->backup_dir . '/files.list.staged',
			$this->backup_dir . '/walk-run-0001',
		);
		foreach ( $own as $path ) {
			file_put_contents( $path, 'own' );
		}
		$task = $spine->get_task( $task_id );
		$spine->checkpoint(
			$task_id,
			(int) $task->version,
			array(
				'phase'     => MainWP_Child_Backup_Task::PHASE_FAILED,
				'sub_state' => array( 'error' => array( 'code' => 'x' ) ),
			),
			false
		);

		$this->runner->cleanup_failed_task_artifacts( $task_id, array( 'archive_file' => basename( $own[0] ) ), $this->backup_dir );

		foreach ( $own as $path ) {
			$this->assertFileDoesNotExist( $path );
		}
		foreach ( $chain as $name => $path ) {
			$this->assertFileExists( $path, $name . ' belongs to the chain and must survive a failure cleanup.' );
		}
	}

	/**
	 * Cancelling a pre-restore safety backup mid-archive stops it and leaves
	 * no archive, dump fragment or marker behind.
	 */
	public function test_cancel_running_safety_backup_leaves_no_archive() {
		$spine   = MainWP_Child_Backup_Task::instance();
		$task_id = $this->runner->create_task_for_params(
			'full',
			array(
				'ext'                => 'tar.gz',
				'file'               => '',
				'excludes'           => array(),
				'loadFilesBeforeZip' => false,
			)
		);
		$prefix   = $this->backup_dir . '/dbBackup-' . $task_id;
		$fragment = $prefix . '-wp_posts.sql.php';
		$marker   = $prefix . '.sql';
		$partial  = $this->backup_dir . '/backup-1791262128.tar.gz';
		foreach ( array( $fragment, $marker, $partial ) as $path ) {
			file_put_contents( $path, 'mid-flight' );
		}
		$task = $spine->get_task( $task_id );
		$spine->checkpoint(
			$task_id,
			(int) $task->version,
			array(
				'phase'     => MainWP_Child_Backup_Task::PHASE_ARCHIVING_FILES,
				'sub_state' => array(
					'db_prefix'       => $prefix,
					'db_files'        => array( $fragment, $marker ),
					'archive_file'    => basename( $partial ),
					'archive_started' => true,
				),
			)
		);

		$result = $this->runner->cancel_backup_task( $task_id );

		$this->assertSame( 'ok', $result['result'] );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $spine->get_task( $task_id )->phase );
		foreach ( array( $fragment, $marker, $partial ) as $path ) {
			$this->assertFileDoesNotExist( $path );
		}
		$this->assertNull( MainWP_Child_Backup_Mutex::holder() );

		$this->runner->calls = array();
		$this->advance( $task_id );
		$this->assertSame( array(), $this->runner->seam_names(), 'A cancelled safety backup must not resume any work.' );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $spine->get_task( $task_id )->phase );
	}

}
