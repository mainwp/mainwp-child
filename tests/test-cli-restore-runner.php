<?php
/**
 * Class CLIRestoreRunnerTest
 *
 * Tests the engine-agnostic core behind `wp mainwp-child backup restore`:
 * the destructive-confirm guard, archive resolution and readability, artifact
 * shaping, the slice-driving loop, and how engine failures surface. The real
 * restore pipeline is covered by BackupRestoreTaskRunnerTest; here the runner
 * and spine are doubles so the CLI orchestration is tested in isolation.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Child_CLI_Restore_Runner;
use MainWP\Child\MainWP_Child_Backup_Task;
use MainWP\Child\MainWP_Backup_Error;
use MainWP\Child\MainWP_Exception;
use MainWP\Child\MainWP_Helper;

/**
 * A restore-runner double: records what it was asked to create and replays a
 * scripted sequence of task rows from advance_one_slice.
 */
class Fake_CLI_Restore_Engine {

	/**
	 * Whether create_restore_task was called.
	 *
	 * @var bool
	 */
	public $created = false;

	/**
	 * Sub type passed to create_restore_task.
	 *
	 * @var string|null
	 */
	public $created_sub_type = null;

	/**
	 * Params passed to create_restore_task.
	 *
	 * @var array|null
	 */
	public $created_params = null;

	/**
	 * Exception to throw from create_restore_task, or null.
	 *
	 * @var \Throwable|null
	 */
	public $create_throws = null;

	/**
	 * Task id create_restore_task returns.
	 *
	 * @var string
	 */
	public $task_id = 'cli-task-1';

	/**
	 * Scripted rows advance_one_slice returns in order.
	 *
	 * @var object[]
	 */
	public $rows = array();

	/**
	 * Cursor into the scripted rows.
	 *
	 * @var int
	 */
	public $cursor = 0;

	/**
	 * Record the create call and return the task id (or throw).
	 *
	 * @param string $sub_type Sub type.
	 * @param array  $params   Params.
	 *
	 * @return string
	 *
	 * @throws \Throwable Configured exception.
	 */
	public function create_restore_task( $sub_type, $params ) {
		$this->created          = true;
		$this->created_sub_type = $sub_type;
		$this->created_params   = $params;
		if ( null !== $this->create_throws ) {
			throw $this->create_throws;
		}
		return $this->task_id;
	}

	/**
	 * Return the next scripted row, or echo the task when exhausted.
	 *
	 * @param object $task  Current row.
	 * @param string $owner Lease owner.
	 *
	 * @return object
	 */
	public function advance_one_slice( $task, $owner ) {
		unset( $owner );
		if ( $this->cursor < count( $this->rows ) ) {
			return $this->rows[ $this->cursor++ ];
		}
		return $task;
	}
}

/**
 * A spine double that hands back a fixed starting row.
 */
class Fake_CLI_Spine {

	/**
	 * The row get_task returns.
	 *
	 * @var object
	 */
	public $start_row;

	/**
	 * Return the starting row.
	 *
	 * @param string $task_id Task id.
	 *
	 * @return object
	 */
	public function get_task( $task_id ) {
		unset( $task_id );
		return $this->start_row;
	}
}

/**
 * Tests for MainWP_Child_CLI_Restore_Runner.
 */
class CLIRestoreRunnerTest extends WP_UnitTestCase {

	/**
	 * Backup directory the runner resolves archives from.
	 *
	 * @var string
	 */
	private $backup_dir;

	/**
	 * Fixture files to remove in tear_down.
	 *
	 * @var string[]
	 */
	private $fixtures = array();

	/**
	 * Resolve the backup directory once.
	 */
	public function set_up() {
		parent::set_up();
		$dirs             = MainWP_Helper::get_mainwp_dir( 'backup' );
		$this->backup_dir = rtrim( $dirs[0], '/' ) . '/';
	}

	/**
	 * Remove fixture archives.
	 */
	public function tear_down() {
		foreach ( $this->fixtures as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test cleanup.
			}
		}
		$this->fixtures = array();
		parent::tear_down();
	}

	/**
	 * Build the runner under test with doubles and no idle wait.
	 *
	 * @param Fake_CLI_Restore_Engine $engine    Runner double.
	 * @param object|null             $start_row Starting task row.
	 *
	 * @return MainWP_Child_CLI_Restore_Runner
	 */
	private function make_runner( $engine, $start_row = null ) {
		$spine            = new Fake_CLI_Spine();
		$spine->start_row = null !== $start_row ? $start_row : $this->row( 'queued', 1 );
		return new MainWP_Child_CLI_Restore_Runner( $engine, $spine, 0 );
	}

	/**
	 * A task row stub.
	 *
	 * @param string $phase     Phase.
	 * @param int    $version   Version.
	 * @param array  $sub_state Optional sub_state to JSON-encode.
	 *
	 * @return object
	 */
	private function row( $phase, $version, $sub_state = array() ) {
		return (object) array(
			'task_id'   => 'cli-task-1',
			'phase'     => $phase,
			'version'   => $version,
			'sub_state' => wp_json_encode( $sub_state ),
		);
	}

	/**
	 * Write a real, openable zip into the backup dir.
	 *
	 * @param string $name Basename.
	 *
	 * @return string Absolute path.
	 */
	private function write_zip_fixture( $name ) {
		$path = $this->backup_dir . $name;
		$zip  = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE );
		$zip->addFromString( 'hello.txt', 'world' );
		$zip->close();
		$this->fixtures[] = $path;
		return $path;
	}

	/**
	 * Write raw bytes into the backup dir.
	 *
	 * @param string $name  Basename.
	 * @param string $bytes Contents.
	 *
	 * @return string Absolute path.
	 */
	private function write_raw_fixture( $name, $bytes ) {
		$path = $this->backup_dir . $name;
		file_put_contents( $path, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
		$this->fixtures[] = $path;
		return $path;
	}

	/**
	 * A db restore without --yes is refused before any task is created.
	 */
	public function test_db_restore_requires_yes() {
		$engine = new Fake_CLI_Restore_Engine();
		$result = $this->make_runner( $engine )->run( 'anything.zip', 'db', false );

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( '--yes', $result['error'] );
		$this->assertFalse( $engine->created, 'No task may be created without confirmation.' );
	}

	/**
	 * A full restore without --yes is refused before any task is created.
	 */
	public function test_full_restore_requires_yes() {
		$engine = new Fake_CLI_Restore_Engine();
		$result = $this->make_runner( $engine )->run( 'anything.zip', 'full', false );

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( '--yes', $result['error'] );
		$this->assertFalse( $engine->created );
	}

	/**
	 * A files restore needs no confirmation: it passes the guard and fails
	 * only on the missing archive, not on a confirm requirement.
	 */
	public function test_files_restore_skips_confirm_gate() {
		$engine = new Fake_CLI_Restore_Engine();
		$result = $this->make_runner( $engine )->run( 'missing-files.zip', 'files', false );

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'not found', $result['error'] );
		$this->assertStringNotContainsString( '--yes', $result['error'] );
	}

	/**
	 * A missing archive is reported as not found.
	 */
	public function test_missing_archive_errors() {
		$engine = new Fake_CLI_Restore_Engine();
		$result = $this->make_runner( $engine )->run( 'no-such-archive.zip', 'full', true );

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'not found', $result['error'] );
		$this->assertFalse( $engine->created );
	}

	/**
	 * A file that does not open as the zip it claims to be is refused (the
	 * encrypted/corrupt path).
	 */
	public function test_unreadable_zip_is_refused() {
		$this->write_raw_fixture( 'garbage.zip', 'not a real zip at all' );

		$engine = new Fake_CLI_Restore_Engine();
		$result = $this->make_runner( $engine )->run( 'garbage.zip', 'full', true );

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'not a readable backup', $result['error'] );
		$this->assertFalse( $engine->created );
	}

	/**
	 * A plaintext tar.gz is recognised by its gzip magic and is NOT mistaken
	 * for an encrypted archive: the run proceeds to create a task.
	 */
	public function test_plaintext_targz_is_not_refused() {
		// Gzip magic followed by arbitrary bytes; readability, not validity,
		// is what the pre-check asserts.
		$this->write_raw_fixture( 'plain.tar.gz', "\x1f\x8b\x08\x00rest-of-stream" );

		$engine            = new Fake_CLI_Restore_Engine();
		$runner            = $this->make_runner( $engine, $this->row( 'completed', 9 ) );
		$result            = $runner->run( 'plain.tar.gz', 'full', true );

		$this->assertTrue( $engine->created, 'A plaintext tar.gz must pass the readability check.' );
		$this->assertTrue( $result['ok'] );
	}

	/**
	 * build_artifacts computes the sha256 and tags the role by sub type.
	 */
	public function test_build_artifacts_shapes_entry() {
		$path = $this->write_raw_fixture( 'sample.zip', 'abc' );

		$runner = $this->make_runner( new Fake_CLI_Restore_Engine() );

		$full = $runner->build_artifacts( $path, 'sample.zip', 'full' );
		$this->assertCount( 1, $full );
		$this->assertSame( 'full', $full[0]['role'] );
		$this->assertSame( 'sample.zip', $full[0]['file'] );
		$this->assertSame( 3, $full[0]['size'] );
		$this->assertSame( hash( 'sha256', 'abc' ), $full[0]['sha256'] );
		$this->assertFalse( $full[0]['adopt_only'] );

		$db = $runner->build_artifacts( $path, 'sample.zip', 'db' );
		$this->assertSame( 'db', $db[0]['role'] );
	}

	/**
	 * An already-running task surfaces with its engine error code.
	 */
	public function test_already_running_is_surfaced() {
		$this->write_zip_fixture( 'busy.zip' );

		$engine                = new Fake_CLI_Restore_Engine();
		$engine->create_throws = new MainWP_Exception( 'A task is already running.', null, MainWP_Backup_Error::ERR_ALREADY_RUNNING );

		$result = $this->make_runner( $engine )->run( 'busy.zip', 'full', true );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( MainWP_Backup_Error::ERR_ALREADY_RUNNING, $result['error_code'] );
	}

	/**
	 * A run that reaches completed reports success and the new task id.
	 */
	public function test_run_completes() {
		$this->write_zip_fixture( 'good.zip' );

		$engine        = new Fake_CLI_Restore_Engine();
		$engine->rows  = array(
			$this->row( 'restoring_files', 2 ),
			$this->row( 'replaying_db', 3 ),
			$this->row( 'completed', 4 ),
		);

		$result = $this->make_runner( $engine )->run( 'good.zip', 'full', true );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $result['phase'] );
		$this->assertSame( 'cli-task-1', $result['task_id'] );
		$this->assertSame( 'full', $engine->created_sub_type );
	}

	/**
	 * A run that ends failed reports the engine error message and code.
	 */
	public function test_run_reports_failure() {
		$this->write_zip_fixture( 'doomed.zip' );

		$engine       = new Fake_CLI_Restore_Engine();
		$engine->rows = array(
			$this->row( 'restoring_files', 2 ),
			$this->row(
				'failed',
				3,
				array(
					'error' => array(
						'message' => 'Replay failed on table items.',
						'code'    => MainWP_Backup_Error::ERR_RESTORE_DB_REPLAY,
					),
				)
			),
		);

		$result = $this->make_runner( $engine )->run( 'doomed.zip', 'full', true );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $result['phase'] );
		$this->assertStringContainsString( 'Replay failed', $result['error'] );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_DB_REPLAY, $result['error_code'] );
		$this->assertIsBool( $result['maintenance_left'] );
	}

	public function test_command_reads_environment_passphrase_and_warns_for_argv() {
		$script = <<<'PHP'
namespace {
	define( 'ABSPATH', '/' );
	define( 'WP_CLI', true );
	class WP_CLI_Command {}
	class WP_CLI {
		public static $warnings = array();
		public static function warning( $message ) { self::$warnings[] = $message; }
		public static function log( $message ) {}
		public static function success( $message ) {}
	}
	function WP_Filesystem() {}
}
namespace MainWP\Child {
	class MainWP_Child_Restore_Task_Runner { public static function instance() { return new self(); } }
	class MainWP_Child_Backup_Task { public static function instance() { return new self(); } }
	class MainWP_Child_CLI_Restore_Runner {
		public static $passphrases = array();
		public function __construct( $runner, $spine ) {}
		public function run( $archive, $type, $confirmed, $progress, $passphrase, $bundle ) {
			self::$passphrases[] = $passphrase;
			return array( 'ok' => true );
		}
	}
}
namespace {
	require COMMAND_FILE;
	putenv( "MAINWP_BACKUP_PASSPHRASE=  secret\twith spaces  " );
	$command = new \MainWP\Child\MainWP_Child_WP_CLI_Command();
	$command->backup( array( 'restore', 'backup.zip' ), array( 'type' => 'files', 'bundle' => '/bundle' ) );
	$environment_warnings = WP_CLI::$warnings;
	$command->backup( array( 'restore', 'backup.zip' ), array( 'type' => 'files', 'bundle' => '/bundle', 'passphrase' => ' argv secret ' ) );
	echo json_encode( array( \MainWP\Child\MainWP_Child_CLI_Restore_Runner::$passphrases, $environment_warnings, WP_CLI::$warnings ) );
}
PHP;
		$script = str_replace( 'COMMAND_FILE', var_export( dirname( __DIR__ ) . '/class/class-mainwp-child-wp-cli-command.php', true ), $script );
		$output = array();
		$status = null;
		exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $script ), $output, $status );
		$this->assertSame( 0, $status );
		$result = json_decode( implode( "\n", $output ), true );
		$this->assertIsArray( $result );
		$this->assertSame( array( "  secret\twith spaces  ", ' argv secret ' ), $result[0] );
		$this->assertSame( array(), $result[1] );
		$this->assertCount( 1, $result[2] );
		$this->assertStringContainsString( '--passphrase exposes', $result[2][0] );
		$this->assertStringNotContainsString( 'argv secret', $result[2][0] );
	}

	public function test_idle_timeout_reports_task_still_active_with_id() {
		$this->write_zip_fixture( 'idle.zip' );
		$engine = new Fake_CLI_Restore_Engine();
		$task = $this->row( 'restoring_files', 2 );
		$result = $this->make_runner( $engine, $task )->run( 'idle.zip', 'full', true );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'restoring_files', $result['phase'] );
		$this->assertSame( 'cli-task-1', $result['task_id'] );
		$this->assertStringContainsString( 'cli-task-1 is still active', $result['error'] );
		$this->assertStringNotContainsString( 'failed', $result['error'] );
		$this->assertStringNotContainsString( 'another process', $result['error'] );
		$this->assertSame( MainWP_Backup_Error::ERR_ALREADY_RUNNING, $result['error_code'] );
		$this->assertSame( 'restoring_files', $task->phase );
		$this->assertSame( 2, $task->version );
	}

}
