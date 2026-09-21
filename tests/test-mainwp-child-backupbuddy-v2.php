<?php

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, WordPress.Files.FileName.InvalidClassFileName -- The focused fixture and its test case intentionally share this integration-test file.
/**
 * BackupBuddy abilities v2 protocol tests.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Child_Back_Up_Buddy;

/** Deterministic BackupBuddy v2 boundary fixture. */
class Test_MainWP_Child_BackupBuddy_V2_Fixture extends MainWP_Child_Back_Up_Buddy {

	/** @var int */
	public $now = 1786300000;

	/** @var array */
	public $options = array();

	/** @var array */
	public $archives = array();

	/** @var array */
	public $records = array();

	/** @var array */
	public $effects = array();

	/** @var int */
	public $writes = 0;

	/** @var bool */
	public $lock_busy = false;

	/** @var bool */
	public $lock_held = false;

	/** @var bool */
	public $delete_saw_pending = false;

	/** @var bool */
	public $transfer_result = true;

	/** @var bool */
	public $release_result = true;

	/** @var int */
	public $archive_scans = 0;

	/**
	 * Scan number at which the fixture drops every profile, so a test can put a provider state
	 * change inside one request the way the real archive scan can: it calls into BackupBuddy, and
	 * BackupBuddy reloads pb_backupbuddy::$options from the database while it is there.
	 *
	 * @var int
	 */
	public $forget_profiles_at_scan = 0;

	/** Avoid product hooks in the isolated fixture. */
	public function __construct() {
		$this->is_backupbuddy_installed = true;
	}

	/** The fixture supplies provider state directly, so no globally defined provider class may decide these tests. */
	protected function abilities_v2_load_provider() {
	}

	/** @return int */
	protected function abilities_v2_now() {
		return $this->now;
	}

	/** @return string */
	protected function abilities_v2_secret() {
		return 'fixture-secret-without-private-provider-data';
	}

	/** @return array */
	protected function abilities_v2_read_options() {
		return $this->options;
	}

	/** @return array */
	protected function abilities_v2_archive_candidates() {
		++$this->archive_scans;
		if ( 0 < $this->forget_profiles_at_scan && $this->archive_scans >= $this->forget_profiles_at_scan ) {
			$this->options['profiles'] = array();
		}
		return $this->archives;
	}

	/** @return array */
	protected function abilities_v2_read_records() {
		return $this->records;
	}

	/**
	 * @param array $records Records.
	 * @return bool
	 */
	protected function abilities_v2_write_records( $records ) {
		++$this->writes;
		$this->records = $records;
		return true;
	}

	/** @return string|false */
	protected function abilities_v2_acquire_effect_lock() {
		if ( $this->lock_busy || $this->lock_held ) {
			return false;
		}
		$this->lock_held = true;
		return 'fixture-lock-owner';
	}

	/**
	 * @param string $owner Lock owner.
	 * @return bool
	 */
	protected function abilities_v2_release_effect_lock( $owner ) {
		if ( ! $this->lock_held || 'fixture-lock-owner' !== $owner ) {
			return false;
		}
		$this->lock_held = false;
		return $this->release_result;
	}

	/**
	 * @param array  $profile Profile.
	 * @param string $serial  Serial.
	 * @param array  $steps   Post-backup steps.
	 * @return bool
	 */
	protected function abilities_v2_start_backup_effect( $profile, $serial, $steps ) {
		$this->effects[] = array( 'backup', $profile['type'], $serial, $steps );
		return true;
	}

	/**
	 * @param array $record Record.
	 * @return array
	 */
	protected function abilities_v2_probe_operation( $record ) {
		return $record;
	}

	/**
	 * @param string $serial Serial.
	 * @return bool
	 */
	protected function abilities_v2_set_stop_signal( $serial ) {
		$this->effects[] = array( 'cancel', $serial );
		return true;
	}

	/**
	 * @param array $archive Archive.
	 * @return array
	 */
	protected function abilities_v2_delete_archive_effect( $archive ) {
		$receipts                 = isset( $this->records['receipts'] ) && is_array( $this->records['receipts'] ) ? array_values( $this->records['receipts'] ) : array();
		$this->delete_saw_pending = 1 === count( $receipts ) && isset( $receipts[0]['state'] ) && 'pending' === $receipts[0]['state'];
		$this->effects[]          = array( 'delete', $archive['internal_id'] );
		foreach ( $this->archives as $index => $candidate ) {
			if ( $candidate['internal_id'] === $archive['internal_id'] ) {
				unset( $this->archives[ $index ] );
			}
		}
		$this->archives = array_values( $this->archives );
		return array(
			'deleted'                   => true,
			'auxiliary_records_removed' => 2,
		);
	}

	/**
	 * @param array  $archive     Archive.
	 * @param string $destination Destination identifier.
	 * @param string $serial      Serial.
	 * @return bool
	 */
	protected function abilities_v2_start_transfer_effect( $archive, $destination, $serial ) {
		$this->effects[] = array( 'transfer', $archive['internal_id'], $destination, $serial );
		return $this->transfer_result;
	}
}

/**
 * Smallest usable stand-in for the BackupBuddy load seam. The production loader only ever calls
 * plugin_path(), load(), and reads $options, so the ordering it must honour is observable here.
 *
 * Named for this file and aliased onto the provider name inside set_up(), so loading the suite
 * never puts a fake BackupBuddy in front of another test file's bridge.
 */
class Test_MainWP_BackupBuddy_Provider_Stub {

	/** @var array|null */
	public static $options;

	/** @var string */
	public static $path = '';

	/** @var int */
	public static $load_calls = 0;

	/** @return string */
	public static function plugin_path() {
		return self::$path;
	}

	/** @return void */
	public static function load() {
		++self::$load_calls;
		self::$options = array(
			'profiles' => array(
				0 => array(
					'title' => 'Full',
					'type'  => 'full',
				),
				4 => array(
					'title' => 'Database',
					'type'  => 'db',
				),
			),
		);
	}
}

/** Stand-in for the BackupBuddy core helpers the archive and delete paths call statically. */
class Test_MainWP_BackupBuddy_Core_Stub {

	/** @var string */
	public static $backup_directory = '';

	/** @var string */
	public static $log_directory = '';

	/** @return string */
	public static function getBackupDirectory() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Mirrors the BackupBuddy method name.
		return self::$backup_directory;
	}

	/** @return string */
	public static function getLogDirectory() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Mirrors the BackupBuddy method name.
		return self::$log_directory;
	}

	/**
	 * @param string $file Archive file name.
	 * @return string
	 */
	public static function get_serial_from_file( $file ) {
		$parts = explode( '-', str_replace( '.zip', '', $file ) );
		return (string) array_pop( $parts );
	}
}

/**
 * A BackupBuddy core that predates getLogDirectory(). The legacy path asserts that method through
 * check_methods() precisely because a build without it exists, so the v2 paths have to survive it.
 */
class Test_MainWP_BackupBuddy_Core_Without_Log_Directory {

	/** @return string */
	public static function getBackupDirectory() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Mirrors the BackupBuddy method name.
		return Test_MainWP_BackupBuddy_Core_Stub::$backup_directory;
	}
}

/**
 * Stand-in for the fileoptions reader the operation probe uses. BackupBuddy stores these as
 * serialized PHP behind a lock; the probe only ever asks is_ok() and reads ->options, so the
 * stub keeps the same surface over a format the test can write by hand.
 */
class Test_MainWP_BackupBuddy_Fileoptions_Stub {

	/** @var array */
	public $options = array();

	/** @var bool */
	private $ok = false;

	/**
	 * @param string $file      Fileoptions file.
	 * @param bool   $read_only Read-only flag, as BackupBuddy takes it.
	 */
	public function __construct( $file, $read_only = false ) {
		unset( $read_only );
		$data          = file_exists( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
		$this->ok      = is_array( $data );
		$this->options = $this->ok ? $data : array();
	}

	/** @return bool */
	public function is_ok() {
		return $this->ok;
	}
}

/** Runs the real provider-facing v2 code: real options read, real archive scan, real file effects. */
class Test_MainWP_Child_BackupBuddy_V2_Effect_Fixture extends MainWP_Child_Back_Up_Buddy {

	/** Avoid product hooks in the isolated fixture. */
	public function __construct() {
		$this->is_backupbuddy_installed = true;
	}

	/**
	 * @param array $archive Archive.
	 * @return array
	 */
	public function call_delete_archive_effect( $archive ) {
		return $this->abilities_v2_delete_archive_effect( $archive );
	}
}

/** BackupBuddy v2 provider-boundary tests that execute the real filesystem and load paths. */
class Test_MainWP_Child_BackupBuddy_V2_Provider_Boundary extends WP_UnitTestCase {

	/** @var string */
	private $root = '';

	/** @var string */
	private $request_ref = '123e4567-e89b-42d3-a456-426614174900';

	public function set_up(): void {
		parent::set_up();
		// The production code calls \pb_backupbuddy and \backupbuddy_core by their fixed names. Claim
		// those names here rather than at file scope, and refuse to run against anyone else's version.
		foreach (
			array(
				'pb_backupbuddy'             => Test_MainWP_BackupBuddy_Provider_Stub::class,
				'backupbuddy_core'           => Test_MainWP_BackupBuddy_Core_Stub::class,
				'pb_backupbuddy_fileoptions' => Test_MainWP_BackupBuddy_Fileoptions_Stub::class,
			) as $provider => $stub
		) {
			if ( ! class_exists( $provider, false ) ) {
				class_alias( $stub, $provider );
			} elseif ( ! is_a( $provider, $stub, true ) ) {
				$this->markTestSkipped( $provider . ' is already defined elsewhere, so this provider boundary cannot be observed.' );
			}
		}
		$this->root = rtrim( get_temp_dir(), '/' ) . '/mainwp-bb-v2-' . wp_generate_password( 12, false );
		wp_mkdir_p( $this->root . '/backups' );
		wp_mkdir_p( $this->root . '/logs/fileoptions' );
		wp_mkdir_p( $this->root . '/plugin/classes' );
		// The archive scan resolves symlinked temp paths, so the fixture must speak the resolved form too.
		$this->root = realpath( $this->root );
		Test_MainWP_BackupBuddy_Core_Stub::$backup_directory = $this->root . '/backups/';
		Test_MainWP_BackupBuddy_Core_Stub::$log_directory    = $this->root . '/logs/';
		Test_MainWP_BackupBuddy_Provider_Stub::$path         = $this->root . '/plugin';
		Test_MainWP_BackupBuddy_Provider_Stub::$options      = null;
		Test_MainWP_BackupBuddy_Provider_Stub::$load_calls   = 0;
		delete_option( 'mainwp_backupbuddy_ability_operations_v1' );
		delete_option( 'mainwp_backupbuddy_ability_effect_lock_v1' );
	}

	public function tear_down(): void {
		$this->remove_tree( $this->root );
		Test_MainWP_BackupBuddy_Provider_Stub::$options = null;
		unset( $GLOBALS['mainwp_test_bb_core_loaded'], $GLOBALS['mainwp_test_bb_v1_core_path'] );
		// The legacy-action test throws out of action(), so its request state can only be cleared here.
		unset( $_POST['mwp_action'] );
		delete_option( 'mainwp_backupbuddy_ability_operations_v1' );
		delete_option( 'mainwp_backupbuddy_ability_effect_lock_v1' );
		parent::tear_down();
	}

	/**
	 * @param string $path Directory to remove.
	 * @return void
	 */
	private function remove_tree( $path ) {
		if ( '' === $path || ! is_dir( $path ) ) {
			return;
		}
		foreach ( array_diff( scandir( $path ), array( '.', '..' ) ) as $entry ) {
			$child = $path . '/' . $entry;
			if ( is_dir( $child ) ) {
				$this->remove_tree( $child );
				continue;
			}
			unlink( $child );
		}
		rmdir( $path );
	}

	/** Deleting an archive reports the outcome the filesystem actually has, and the receipt settles. */
	public function test_delete_archive_effect_reports_real_filesystem_outcome() {
		$archive_path = Test_MainWP_BackupBuddy_Core_Stub::$backup_directory . 'backup-example_com-full-serial01.zip';
		file_put_contents( $archive_path, str_repeat( 'z', 64 ) );
		$auxiliary = array(
			Test_MainWP_BackupBuddy_Core_Stub::$log_directory . 'fileoptions/serial01.txt',
			Test_MainWP_BackupBuddy_Core_Stub::$log_directory . 'fileoptions/serial01.txt.lock',
		);
		foreach ( $auxiliary as $file ) {
			file_put_contents( $file, 'x' );
		}

		$fixture  = new Test_MainWP_Child_BackupBuddy_V2_Effect_Fixture();
		$archives = $fixture->abilities_v2(
			array(
				'operation' => 'list_archives',
				'page'      => 1,
				'per_page'  => 25,
				'type'      => 'all',
			)
		);
		$this->assertSame( 1, $archives['total'] );

		$request = array(
			'operation'            => 'delete_archive',
			'archive_ref'          => $archives['archives'][0]['archive_ref'],
			'expected_size_bytes'  => $archives['archives'][0]['size_bytes'],
			'expected_modified_at' => $archives['archives'][0]['modified_at'],
			'request_ref'          => $this->request_ref,
		);
		$deleted = $fixture->abilities_v2( $request );

		$this->assertArrayNotHasKey( 'code', $deleted, 'A completed unlink must not be reported as an unknown outcome.' );
		$this->assertTrue( $deleted['deleted'] );
		$this->assertFalse( $deleted['already_absent'] );
		$this->assertSame( 2, $deleted['auxiliary_records_removed'] );
		$this->assertFileDoesNotExist( $archive_path );
		foreach ( $auxiliary as $file ) {
			$this->assertFileDoesNotExist( $file );
		}

		$this->assertSame( $deleted, $fixture->abilities_v2( $request ), 'The settled receipt must replay the recorded response.' );
	}

	/**
	 * Deletion is decided by reading the filesystem back, never by trusting wp_delete_file()'s
	 * return value: core returned nothing at all before 6.7, and the documented wp_delete_file
	 * filter lets a site remove the file through its own storage layer and report failure.
	 */
	public function test_delete_archive_is_decided_by_readback_not_by_the_return_value() {
		$archive_path = Test_MainWP_BackupBuddy_Core_Stub::$backup_directory . 'backup-example_com-full-serial03.zip';
		file_put_contents( $archive_path, str_repeat( 'z', 64 ) );
		$auxiliary = array(
			Test_MainWP_BackupBuddy_Core_Stub::$log_directory . 'fileoptions/serial03.txt',
			Test_MainWP_BackupBuddy_Core_Stub::$log_directory . 'fileoptions/serial03.txt.lock',
		);
		foreach ( $auxiliary as $file ) {
			file_put_contents( $file, 'x' );
		}

		$fixture  = new Test_MainWP_Child_BackupBuddy_V2_Effect_Fixture();
		$archives = $fixture->abilities_v2(
			array(
				'operation' => 'list_archives',
				'page'      => 1,
				'per_page'  => 25,
				'type'      => 'all',
			)
		);

		$root = $this->root;
		add_filter(
			'wp_delete_file',
			static function ( $file ) use ( $root ) {
				if ( is_string( $file ) && 0 === strpos( $file, $root ) && is_file( $file ) ) {
					unlink( $file );
				}
				return '';
			}
		);

		$request = array(
			'operation'            => 'delete_archive',
			'archive_ref'          => $archives['archives'][0]['archive_ref'],
			'expected_size_bytes'  => $archives['archives'][0]['size_bytes'],
			'expected_modified_at' => $archives['archives'][0]['modified_at'],
			'request_ref'          => $this->request_ref,
		);
		$deleted = $fixture->abilities_v2( $request );

		$this->assertFileDoesNotExist( $archive_path );
		$this->assertArrayNotHasKey( 'code', $deleted, 'A removed archive must not be reported as an unknown outcome.' );
		$this->assertTrue( $deleted['deleted'] );
		$this->assertSame( 2, $deleted['auxiliary_records_removed'] );
		$this->assertSame( $deleted, $fixture->abilities_v2( $request ), 'The settled receipt must replay the recorded response.' );
	}

	/** An archive that cannot be removed is reported as an unknown outcome, not a success. */
	public function test_delete_archive_effect_reports_failure_when_the_file_survives() {
		$directory = Test_MainWP_BackupBuddy_Core_Stub::$backup_directory . 'backup-example_com-full-serial02.zip';
		wp_mkdir_p( $directory );

		$fixture = new Test_MainWP_Child_BackupBuddy_V2_Effect_Fixture();
		$effect  = $fixture->call_delete_archive_effect(
			array(
				'internal_id' => 'backup-example_com-full-serial02.zip',
				'path'        => $directory,
			)
		);

		$this->assertFalse( $effect['deleted'] );
		$this->assertSame( 0, $effect['auxiliary_records_removed'] );
	}

	/** v2 reads run after the provider is loaded, so they see real profiles instead of an empty site. */
	public function test_v2_reads_see_loaded_provider_state() {
		$core_class = 'Test_MainWP_BackupBuddy_Late_Core';
		file_put_contents(
			Test_MainWP_BackupBuddy_Provider_Stub::$path . '/classes/core.php',
			"<?php\n\$GLOBALS['mainwp_test_bb_core_loaded'] = true;\nclass " . $core_class . " {}\n"
		);
		unset( $GLOBALS['mainwp_test_bb_core_loaded'] );

		$fixture                          = new Test_MainWP_Child_BackupBuddy_V2_Effect_Fixture();
		$fixture->backupbuddy_core_class  = $core_class;
		$profiles                         = $fixture->abilities_v2(
			array(
				'operation' => 'list_profiles',
				'page'      => 1,
				'per_page'  => 25,
			)
		);

		$this->assertSame( 1, Test_MainWP_BackupBuddy_Provider_Stub::$load_calls, 'The dispatcher must load provider options before reading them.' );
		$this->assertTrue( isset( $GLOBALS['mainwp_test_bb_core_loaded'] ), 'The dispatcher must require the provider core file before reading.' );
		$this->assertSame( 2, $profiles['total'], 'A site with real profiles must not report an empty page.' );
		$this->assertTrue( class_exists( $core_class, false ) );
	}

	/** A site without BackupBuddy still gets the closed unavailable envelope rather than a fatal. */
	public function test_missing_backupbuddy_still_fails_closed() {
		$fixture                         = new Test_MainWP_Child_BackupBuddy_V2_Effect_Fixture();
		$fixture->is_backupbuddy_installed = false;
		$result                          = $fixture->abilities_v2(
			array(
				'operation' => 'list_profiles',
				'page'      => 1,
				'per_page'  => 25,
			)
		);

		$this->assertSame( 'backupbuddy_unavailable', $result['code'] );
		$this->assertSame( 0, Test_MainWP_BackupBuddy_Provider_Stub::$load_calls );
	}

	/** A malformed request is malformed on a site without BackupBuddy too, and never loads the provider. */
	public function test_invalid_payload_is_invalid_request_on_a_site_without_backupbuddy() {
		$fixture                           = new Test_MainWP_Child_BackupBuddy_V2_Effect_Fixture();
		$fixture->is_backupbuddy_installed = false;

		$bad_type = $fixture->abilities_v2(
			array(
				'operation' => 'list_archives',
				'page'      => 1,
				'per_page'  => 25,
				'type'      => 'everything',
			)
		);
		$this->assertSame( 'invalid_request', $bad_type['code'], 'An unknown archive type is an invalid request, not a missing provider.' );

		$bad_kind = $fixture->abilities_v2(
			array(
				'operation'   => 'preview_run',
				'target_kind' => 'site',
				'target_ref'  => str_repeat( 'a', 24 ),
			)
		);
		$this->assertSame( 'invalid_request', $bad_kind['code'], 'An unknown preview target kind is an invalid request, not a missing provider.' );

		$bad_ref = $fixture->abilities_v2(
			array(
				'operation'            => 'delete_archive',
				'archive_ref'          => 'too-short',
				'expected_size_bytes'  => 1,
				'expected_modified_at' => 1,
				'request_ref'          => $this->request_ref,
			)
		);
		$this->assertSame( 'invalid_request', $bad_ref['code'] );
		$this->assertSame( 0, Test_MainWP_BackupBuddy_Provider_Stub::$load_calls );
	}

	/**
	 * The legacy v1 path loads the provider core file from the configured path. Before the fix the
	 * expression was $$this->path_core_file, a variable-variable that PHP resolves by casting $this
	 * to a string, so every v1 action on a site whose core class was not already loaded died with a
	 * PHP Error instead of requiring the file.
	 */
	public function test_legacy_action_requires_the_provider_core_file_from_the_configured_path() {
		$core_file = Test_MainWP_BackupBuddy_Provider_Stub::$path . '/classes/core.php';
		file_put_contents(
			$core_file,
			"<?php\n\$GLOBALS['mainwp_test_bb_v1_core_path'] = __FILE__;\nthrow new RuntimeException( 'mainwp-test-v1-core-loaded' );\n"
		);

		$fixture                         = new Test_MainWP_Child_BackupBuddy_V2_Effect_Fixture();
		$fixture->backupbuddy_core_class = 'Test_MainWP_BackupBuddy_Absent_Core';
		$_POST['mwp_action']             = 'get_notifications';

		$thrown = null;
		try {
			$fixture->action();
		} catch ( \Throwable $throwable ) {
			$thrown = $throwable;
		}

		$this->assertInstanceOf( RuntimeException::class, $thrown, 'v1 must reach the required core file instead of failing on the path expression.' );
		$this->assertSame( 'mainwp-test-v1-core-loaded', $thrown->getMessage() );
		$this->assertSame( $core_file, $GLOBALS['mainwp_test_bb_v1_core_path'] );
	}

	/**
	 * A backup BackupBuddy is still stepping outranks the one-day staleness horizon, while one it
	 * stopped touching days ago still settles. Only the production probe reads BackupBuddy's own
	 * record, so the protocol fixture's identity probe cannot tell these two apart.
	 */
	public function test_live_provider_evidence_outranks_the_staleness_horizon() {
		$now         = time();
		$live        = $this->long_running_operation_record( 1, $now - ( 2 * DAY_IN_SECONDS ) );
		$abandoned   = $this->long_running_operation_record( 2, $now - ( 2 * DAY_IN_SECONDS ) );
		$fileoptions = Test_MainWP_BackupBuddy_Core_Stub::$log_directory . 'fileoptions/';
		update_option(
			'mainwp_backupbuddy_ability_operations_v1',
			array(
				'operations' => array(
					$live['operation_ref']      => $live,
					$abandoned['operation_ref'] => $abandoned,
				),
				'receipts'   => array(),
			)
		);
		// Neither run has finished or errored. The only thing separating them is the step time
		// BackupBuddy stamps on its own record.
		file_put_contents( $fileoptions . $live['serial'] . '.txt', wp_json_encode( array( 'updated_time' => $now - 90, 'finish_time' => 0, 'archive_file' => '' ) ) );
		file_put_contents( $fileoptions . $abandoned['serial'] . '.txt', wp_json_encode( array( 'updated_time' => $now - ( 3 * DAY_IN_SECONDS ), 'finish_time' => 0, 'archive_file' => '' ) ) );

		$fixture = new Test_MainWP_Child_BackupBuddy_V2_Effect_Fixture();
		$running = $fixture->abilities_v2(
			array(
				'operation'     => 'get_operation',
				'operation_ref' => $live['operation_ref'],
			)
		);
		$settled = $fixture->abilities_v2(
			array(
				'operation'     => 'get_operation',
				'operation_ref' => $abandoned['operation_ref'],
			)
		);

		$this->assertSame( 'running', $running['operation']['state'], 'A backup the Child just observed running must not be reported unknown.' );
		$this->assertSame( $now - 90, $running['operation']['updated_at'], 'The provider step time is what the Child observed.' );
		$this->assertSame( 'unknown', $settled['operation']['state'], 'A run nothing has stepped for days still settles.' );
		$this->assertSame( $now - ( 2 * DAY_IN_SECONDS ), $settled['operation']['updated_at'] );
	}

	/**
	 * A mutation ages the ledger out, and the last thing the Child heard about an operation can be a
	 * week old because reads never write. Nothing may be removed on that timestamp alone: the record
	 * is the replay proof for its request_ref, so deleting a run that is still going lets the same
	 * request start a second backup.
	 */
	public function test_a_mutation_cannot_age_out_a_backup_backupbuddy_is_still_running() {
		$now         = time();
		$live        = $this->long_running_operation_record( 3, $now - ( 8 * DAY_IN_SECONDS ) );
		$abandoned   = $this->long_running_operation_record( 4, $now - ( 8 * DAY_IN_SECONDS ) );
		$fileoptions = Test_MainWP_BackupBuddy_Core_Stub::$log_directory . 'fileoptions/';
		update_option(
			'mainwp_backupbuddy_ability_operations_v1',
			array(
				'operations' => array(
					$live['operation_ref']      => $live,
					$abandoned['operation_ref'] => $abandoned,
				),
				'receipts'   => array(),
			)
		);
		file_put_contents( $fileoptions . $live['serial'] . '.txt', wp_json_encode( array( 'updated_time' => $now - 90, 'finish_time' => 0, 'archive_file' => '' ) ) );
		file_put_contents( $fileoptions . $abandoned['serial'] . '.txt', wp_json_encode( array( 'updated_time' => $now - ( 9 * DAY_IN_SECONDS ), 'finish_time' => 0, 'archive_file' => '' ) ) );

		$fixture = new Test_MainWP_Child_BackupBuddy_V2_Effect_Fixture();
		$deleted = $this->delete_one_archive( $fixture, 'serial09', $this->request_ref );
		$this->assertTrue( $deleted['deleted'], 'The mutation itself must still succeed.' );

		$stored = get_option( 'mainwp_backupbuddy_ability_operations_v1' );
		$this->assertArrayHasKey( $live['operation_ref'], $stored['operations'], 'A backup BackupBuddy stepped a minute ago must survive the age-out an unrelated mutation performs.' );
		$this->assertSame( 'running', $stored['operations'][ $live['operation_ref'] ]['state'] );
		$this->assertSame( $now - 90, $stored['operations'][ $live['operation_ref'] ]['updated_at'], 'The mutation writes back what it observed, so the next one starts from the fresh time.' );
		$this->assertArrayNotHasKey( $abandoned['operation_ref'], $stored['operations'], 'A run nothing has stepped for nine days still ages out of the ledger.' );

		$status = $fixture->abilities_v2(
			array(
				'operation'     => 'get_operation',
				'operation_ref' => $live['operation_ref'],
			)
		);
		$this->assertSame( 'running', $status['operation']['state'], 'The surviving record is what stops the original request being dispatched twice.' );
	}

	/**
	 * A transfer's stored status is written once at dispatch and never cleared, so a send whose
	 * worker died stays 'running' on disk forever. Reading that status is not observing the send.
	 */
	public function test_a_transfer_status_alone_is_not_evidence_the_send_is_alive() {
		$now     = time();
		$live    = $this->long_running_operation_record( 5, $now - ( 8 * DAY_IN_SECONDS ), 'transfer' );
		$zombie  = $this->long_running_operation_record( 6, $now - ( 8 * DAY_IN_SECONDS ), 'transfer' );
		$sends   = Test_MainWP_BackupBuddy_Core_Stub::$log_directory . 'fileoptions/send-mainwp-ability-';
		update_option(
			'mainwp_backupbuddy_ability_operations_v1',
			array(
				'operations' => array(
					$live['operation_ref']   => $live,
					$zombie['operation_ref'] => $zombie,
				),
				'receipts'   => array(),
			)
		);
		file_put_contents( $sends . $live['serial'] . '.txt', wp_json_encode( array( 'status' => 'running', 'update_time' => $now - 120 ) ) );
		// The dead send carries no stamp of its own, so the only thing left to date it by is when
		// BackupBuddy last rewrote the record.
		file_put_contents( $sends . $zombie['serial'] . '.txt', wp_json_encode( array( 'status' => 'running' ) ) );
		touch( $sends . $zombie['serial'] . '.txt', $now - ( 9 * DAY_IN_SECONDS ) );
		clearstatcache();

		$fixture = new Test_MainWP_Child_BackupBuddy_V2_Effect_Fixture();
		$running = $fixture->abilities_v2(
			array(
				'operation'     => 'get_operation',
				'operation_ref' => $live['operation_ref'],
			)
		);
		$settled = $fixture->abilities_v2(
			array(
				'operation'     => 'get_operation',
				'operation_ref' => $zombie['operation_ref'],
			)
		);

		$this->assertSame( 'running', $running['operation']['state'] );
		$this->assertSame( $now - 120, $running['operation']['updated_at'], 'A send still moving files is dated by its own activity.' );
		$this->assertSame( 'unknown', $settled['operation']['state'], 'A send nothing has touched for days is not running just because the record still says so.' );
		$this->assertSame( $now - ( 8 * DAY_IN_SECONDS ), $settled['operation']['updated_at'], 'Reading a dead send must not refresh when the Child last saw it.' );

		$deleted = $this->delete_one_archive( $fixture, 'serial11', $this->request_ref );
		$this->assertTrue( $deleted['deleted'] );
		$stored = get_option( 'mainwp_backupbuddy_ability_operations_v1' );
		$this->assertArrayHasKey( $live['operation_ref'], $stored['operations'], 'A live send must survive the age-out too.' );
		$this->assertArrayNotHasKey( $zombie['operation_ref'], $stored['operations'], 'A dead send must still age out, or a hundred of them refuse every new mutation.' );
	}

	/**
	 * A timestamp ahead of this site's clock is unusable, not fresh. Dating a record by it would
	 * report the current time on every probe of a record that stopped changing days ago, so status
	 * would keep claiming 'running' and the age-out pass would keep rescuing the same zombie. Only a
	 * stamp inside the skew allowance is a real step, and a ledger timestamp nothing can believe is
	 * no observation either.
	 */
	public function test_a_future_timestamp_is_not_evidence_a_run_advanced() {
		$now             = time();
		$far             = $now + ( 30 * DAY_IN_SECONDS );
		$backup_zombie   = $this->long_running_operation_record( 7, $now - ( 8 * DAY_IN_SECONDS ) );
		$transfer_zombie = $this->long_running_operation_record( 8, $now - ( 8 * DAY_IN_SECONDS ), 'transfer' );
		$skewed          = $this->long_running_operation_record( 9, $now - ( 8 * DAY_IN_SECONDS ) );
		$corrupt_ledger  = $this->long_running_operation_record( 10, $far );
		$fileoptions     = Test_MainWP_BackupBuddy_Core_Stub::$log_directory . 'fileoptions/';
		update_option(
			'mainwp_backupbuddy_ability_operations_v1',
			array(
				'operations' => array(
					$backup_zombie['operation_ref']   => $backup_zombie,
					$transfer_zombie['operation_ref'] => $transfer_zombie,
					$skewed['operation_ref']          => $skewed,
					$corrupt_ledger['operation_ref']  => $corrupt_ledger,
				),
				'receipts'   => array(),
			)
		);
		// A crashed backup whose step time was written far ahead of this clock: nothing has advanced.
		file_put_contents( $fileoptions . $backup_zombie['serial'] . '.txt', wp_json_encode( array( 'updated_time' => $far, 'finish_time' => 0, 'archive_file' => '' ) ) );
		// The same for a send, with the record itself frozen too, so the only current-looking thing
		// about it is the stamp the provider left in the future.
		file_put_contents( $fileoptions . 'send-mainwp-ability-' . $transfer_zombie['serial'] . '.txt', wp_json_encode( array( 'status' => 'running', 'update_time' => $far ) ) );
		touch( $fileoptions . 'send-mainwp-ability-' . $transfer_zombie['serial'] . '.txt', $now - ( 9 * DAY_IN_SECONDS ) );
		// A running backup on a host whose clock is a couple of minutes ahead is still stepping.
		file_put_contents( $fileoptions . $skewed['serial'] . '.txt', wp_json_encode( array( 'updated_time' => $now + 120, 'finish_time' => 0, 'archive_file' => '' ) ) );
		clearstatcache();

		$fixture  = new Test_MainWP_Child_BackupBuddy_V2_Effect_Fixture();
		$statuses = array();
		foreach ( array( 'backup' => $backup_zombie, 'transfer' => $transfer_zombie, 'skewed' => $skewed, 'ledger' => $corrupt_ledger ) as $label => $record ) {
			$statuses[ $label ] = $fixture->abilities_v2(
				array(
					'operation'     => 'get_operation',
					'operation_ref' => $record['operation_ref'],
				)
			);
		}

		$this->assertSame( 'unknown', $statuses['backup']['operation']['state'], 'A backup whose step time is in the future has not been observed advancing, so it must not be reported running.' );
		$this->assertSame( $now - ( 8 * DAY_IN_SECONDS ), $statuses['backup']['operation']['updated_at'], 'An unusable stamp must not become a fresh observation time.' );
		$this->assertSame( 'unknown', $statuses['transfer']['operation']['state'], 'A send whose update_time is in the future is not alive, and the frozen record does not say otherwise.' );
		$this->assertSame( $now - ( 8 * DAY_IN_SECONDS ), $statuses['transfer']['operation']['updated_at'] );
		$this->assertSame( 'running', $statuses['skewed']['operation']['state'], 'A stamp inside the skew allowance is still evidence the run advanced.' );
		$this->assertGreaterThanOrEqual( $now, $statuses['skewed']['operation']['updated_at'], 'Genuine provider activity still buys the operation time.' );
		$this->assertSame( 'unknown', $statuses['ledger']['operation']['state'], 'A stored timestamp in the future is not a recent observation either.' );
		$this->assertSame( $far, $statuses['ledger']['operation']['updated_at'], 'Settling is not an observation, so the stored time still stands as read.' );

		$deleted = $this->delete_one_archive( $fixture, 'serial13', $this->request_ref );
		$this->assertTrue( $deleted['deleted'], 'The mutation itself must still succeed.' );

		$stored = get_option( 'mainwp_backupbuddy_ability_operations_v1' );
		$this->assertArrayNotHasKey( $backup_zombie['operation_ref'], $stored['operations'], 'A future step time must not rescue a dead backup from the age-out on every pass.' );
		$this->assertArrayNotHasKey( $transfer_zombie['operation_ref'], $stored['operations'], 'A future update_time must not rescue a dead send either.' );
		$this->assertArrayNotHasKey( $corrupt_ledger['operation_ref'], $stored['operations'], 'A hundred records carrying future timestamps would otherwise refuse every new operation forever.' );
		$this->assertArrayHasKey( $skewed['operation_ref'], $stored['operations'], 'A backup BackupBuddy is really stepping must still survive the age-out.' );
		$this->assertSame( 'running', $stored['operations'][ $skewed['operation_ref'] ]['state'] );
	}

	/**
	 * A BackupBuddy build without getLogDirectory() cannot be probed at all, and the probe used to
	 * call it behind a class_exists() check alone. On such a build the status read - and every
	 * mutation, which probes through the ledger age-out pass - died with a PHP fatal instead of
	 * answering the protocol. A record the Child cannot probe comes back exactly as stored: no new
	 * evidence, not a clean bill of health.
	 */
	public function test_an_unprobeable_provider_leaves_the_record_as_stored() {
		$now    = time();
		$record = $this->long_running_operation_record( 11, $now - HOUR_IN_SECONDS );
		update_option(
			'mainwp_backupbuddy_ability_operations_v1',
			array(
				'operations' => array( $record['operation_ref'] => $record ),
				'receipts'   => array(),
			)
		);
		// A finished run sitting on disk, so a probe that ran anyway would visibly rewrite the record.
		$archive = Test_MainWP_BackupBuddy_Core_Stub::$backup_directory . 'backup-example_com-full-serial21.zip';
		file_put_contents( $archive, str_repeat( 'z', 32 ) );
		file_put_contents(
			Test_MainWP_BackupBuddy_Core_Stub::$log_directory . 'fileoptions/' . $record['serial'] . '.txt',
			wp_json_encode( array( 'updated_time' => $now - 10, 'finish_time' => $now - 5, 'archive_file' => $archive ) )
		);

		$fixture                         = new Test_MainWP_Child_BackupBuddy_V2_Effect_Fixture();
		$fixture->backupbuddy_core_class = 'Test_MainWP_BackupBuddy_Core_Without_Log_Directory';
		$status                          = $fixture->abilities_v2(
			array(
				'operation'     => 'get_operation',
				'operation_ref' => $record['operation_ref'],
			)
		);

		$this->assertSame( 'running', $status['operation']['state'], 'A record the Child cannot probe keeps the state it was stored with.' );
		$this->assertNull( $status['operation']['archive_ref'], 'An unreadable provider record cannot name a finished archive.' );
		$this->assertSame( $now - HOUR_IN_SECONDS, $status['operation']['updated_at'], 'A probe that could not run is not an observation.' );
	}

	/**
	 * Dispatching a backup on an installation whose classes/backup.php is gone answers effect_failed.
	 * The starter used to require_once that constructed path unconditionally, and a require_once that
	 * misses is an uncatchable compile error: it took the request down past the caller's failure
	 * handling and past the finally that releases the effect lock, leaving the record it had just
	 * written stranded in 'queued' and every later v2 effect answering lock_busy for the lock's TTL.
	 */
	public function test_start_backup_fails_closed_when_the_provider_backup_class_file_is_missing() {
		$this->assertFalse( class_exists( 'pb_backupbuddy_backup', false ), 'The starter must reach the require path for this to be observed.' );
		$this->assertFileDoesNotExist( Test_MainWP_BackupBuddy_Provider_Stub::$path . '/classes/backup.php' );

		$fixture     = new Test_MainWP_Child_BackupBuddy_V2_Effect_Fixture();
		$profile_ref = $this->full_profile_ref( $fixture );
		$preview     = $fixture->abilities_v2(
			array(
				'operation'   => 'preview_run',
				'target_kind' => 'profile',
				'target_ref'  => $profile_ref,
			)
		);
		$this->assertArrayHasKey( 'preview_token', $preview );

		$started = $fixture->abilities_v2(
			array(
				'operation'     => 'start_backup',
				'profile_ref'   => $profile_ref,
				'preview_token' => $preview['preview_token'],
				'request_ref'   => $this->request_ref,
			)
		);

		$this->assertFalse( $started['ok'] );
		$this->assertSame( 'effect_failed', $started['code'] );
		$stored = get_option( 'mainwp_backupbuddy_ability_operations_v1' );
		$this->assertCount( 1, $stored['operations'] );
		$this->assertSame( 'failed', array_values( $stored['operations'] )[0]['state'], 'A dispatch that never happened must not stay recorded as queued.' );
		$this->assertFalse( get_option( 'mainwp_backupbuddy_ability_effect_lock_v1', false ), 'The effect lock is released rather than left to expire on its 120s TTL.' );
	}

	/**
	 * A malformed stored lock value is cleared so acquisition is not wedged into permanent lock_busy.
	 *
	 * The acquire path only deleted a well-formed expired lock, so a non-array value, or an array
	 * missing an int expires_at, survived every request and add_option() failed against it forever.
	 * The fix drops any unusable stored value while still refusing to evict a live holder.
	 */
	public function test_acquire_effect_lock_clears_an_unusable_stored_value() {
		$subject = new class() extends MainWP_Child_Back_Up_Buddy {
			/** Skip the product hooks the real constructor wires. */
			public function __construct() {
				$this->is_backupbuddy_installed = true;
			}

			/** @return string|false */
			public function call_acquire() {
				return $this->abilities_v2_acquire_effect_lock();
			}
		};

		// A non-array value is unusable and must be cleared rather than wedge the lock.
		update_option( 'mainwp_backupbuddy_ability_effect_lock_v1', 'corrupt-not-an-array' );
		$owner = $subject->call_acquire();
		$this->assertIsString( $owner );
		$stored = get_option( 'mainwp_backupbuddy_ability_effect_lock_v1' );
		$this->assertIsArray( $stored );
		$this->assertSame( $owner, $stored['owner'] );

		// The live lock just taken is still refused: the malformed-clear path must not evict a holder.
		$this->assertFalse( $subject->call_acquire() );

		// An array missing an int expires_at is equally unusable and is cleared the same way.
		delete_option( 'mainwp_backupbuddy_ability_effect_lock_v1' );
		update_option( 'mainwp_backupbuddy_ability_effect_lock_v1', array( 'owner' => 'stale', 'expires_at' => 'soon' ) );
		$reacquired = $subject->call_acquire();
		$this->assertIsString( $reacquired );
		$this->assertSame( $reacquired, get_option( 'mainwp_backupbuddy_ability_effect_lock_v1' )['owner'] );
	}

	/**
	 * @param Test_MainWP_Child_BackupBuddy_V2_Effect_Fixture $fixture Fixture.
	 * @return string
	 */
	private function full_profile_ref( $fixture ) {
		$profiles = $fixture->abilities_v2(
			array(
				'operation' => 'list_profiles',
				'page'      => 1,
				'per_page'  => 25,
			)
		);
		foreach ( $profiles['profiles'] as $profile ) {
			if ( 'full' === $profile['type'] ) {
				return $profile['profile_ref'];
			}
		}
		$this->fail( 'The provider stub did not expose a full profile.' );
	}

	/**
	 * Delete an archive through the real dispatcher. Any mutation runs the ledger age-out pass; this
	 * is the cheapest one to drive against the real filesystem.
	 *
	 * @param Test_MainWP_Child_BackupBuddy_V2_Effect_Fixture $fixture     Fixture.
	 * @param string                                          $serial      Archive serial.
	 * @param string                                          $request_ref Request reference.
	 * @return array
	 */
	private function delete_one_archive( $fixture, $serial, $request_ref ) {
		file_put_contents( Test_MainWP_BackupBuddy_Core_Stub::$backup_directory . 'backup-example_com-full-' . $serial . '.zip', str_repeat( 'z', 32 ) );
		$archives = $fixture->abilities_v2(
			array(
				'operation' => 'list_archives',
				'page'      => 1,
				'per_page'  => 25,
				'type'      => 'all',
			)
		);
		$this->assertSame( 1, $archives['total'] );
		return $fixture->abilities_v2(
			array(
				'operation'            => 'delete_archive',
				'archive_ref'          => $archives['archives'][0]['archive_ref'],
				'expected_size_bytes'  => $archives['archives'][0]['size_bytes'],
				'expected_modified_at' => $archives['archives'][0]['modified_at'],
				'request_ref'          => $request_ref,
			)
		);
	}

	/**
	 * @param int    $index      Record index.
	 * @param int    $updated_at Last time the Child observed the operation.
	 * @param string $kind       Operation kind.
	 * @return array
	 */
	private function long_running_operation_record( $index, $updated_at, $kind = 'backup' ) {
		$digest = hash( 'sha256', 'boundary-operation-' . $index );
		return array(
			'operation_ref'   => 'op.v1.' . $digest,
			'request_ref'     => sprintf( '123e4567-e89b-42d3-a456-%012d', $index ),
			'request_hash'    => $digest,
			'kind'            => $kind,
			'state'           => 'running',
			'created_at'      => $updated_at,
			'updated_at'      => $updated_at,
			'progress'        => 0,
			'archive_ref'     => null,
			'destination_ref' => null,
			'warnings'        => array(),
			'serial'          => substr( $digest, 0, 10 ),
		);
	}
}

/** BackupBuddy v2 protocol contract tests. */
class Test_MainWP_Child_BackupBuddy_Abilities_V2 extends WP_UnitTestCase {

	/** @var string */
	private $request_ref = '123e4567-e89b-42d3-a456-426614174000';

	/**
	 * @return Test_MainWP_Child_BackupBuddy_V2_Fixture
	 */
	private function fixture() {
		$fixture           = new Test_MainWP_Child_BackupBuddy_V2_Fixture();
		$fixture->options  = array(
			'archive_limit'       => 1,
			'archive_limit_full'  => 1,
			'profiles'            => array(
				0 => array(
					'title' => 'Full',
					'type'  => 'full',
				),
				4 => array(
					'title' => 'Database',
					'type'  => 'db',
				),
			),
			'schedules'           => array(
				8 => array(
					'title'               => 'Nightly',
					'profile'             => 0,
					'interval'            => 'daily',
					'last_run'            => 1786200000,
					'remote_destinations' => array( 3 ),
					'delete_after'        => false,
				),
			),
			'remote_destinations' => array(
				3 => array(
					'title'     => 'Offsite',
					'type'      => 's3',
					'accesskey' => 'PRIVATE-ACCESS',
					'secretkey' => 'PRIVATE-SECRET',
					'bucket'    => 'private-bucket',
				),
			),
		);
		$fixture->archives = array(
			array(
				'internal_id' => 'backup-old-full.zip',
				'type'        => 'full',
				'size_bytes'  => 100,
				'modified_at' => 1786100000,
				'status'      => 'complete',
				'path'        => '/private/backup-old-full.zip',
			),
			array(
				'internal_id' => 'backup-new-full.zip',
				'type'        => 'full',
				'size_bytes'  => 200,
				'modified_at' => 1786200000,
				'status'      => 'complete',
				'path'        => '/private/backup-new-full.zip',
			),
		);
		return $fixture;
	}

	/**
	 * @param MainWP_Child_Back_Up_Buddy $fixture Fixture.
	 * @param array                       $request Request.
	 * @return array
	 */
	private function dispatch( $fixture, $request ) {
		$this->assertTrue( method_exists( $fixture, 'abilities_v2' ) );
		return $fixture->abilities_v2( $request );
	}

	/**
	 * @param array $profiles Profile list response.
	 * @return string
	 */
	private function full_profile_ref( $profiles ) {
		foreach ( $profiles['profiles'] as $profile ) {
			if ( 'full' === $profile['type'] ) {
				return $profile['profile_ref'];
			}
		}
		$this->fail( 'The fixture did not expose a full profile.' );
	}

	/** Capability and malformed request envelopes are closed. */
	public function test_capabilities_and_closed_request_validation() {
		$fixture = $this->fixture();
		$result  = $this->dispatch( $fixture, array( 'operation' => 'capabilities' ) );
		$this->assertSame( array( 'protocol', 'operations' ), array_keys( $result ) );
		$this->assertSame( '2', $result['protocol'] );
		$this->assertContains( 'start_transfer', $result['operations'] );
		$this->assertSame( 0, $fixture->writes );

		$result = $this->dispatch(
			$fixture,
			array(
				'operation' => 'capabilities',
				'extra'     => 'PRIVATE',
			)
		);
		$this->assertSame( 'invalid_request', $result['code'] );
		$this->assertStringNotContainsString( 'PRIVATE', wp_json_encode( $result ) );

		$result = $this->dispatch(
			$fixture,
			array(
				'per_page'  => 25,
				'operation' => 'list_profiles',
				'page'      => 1,
			)
		);
		$this->assertSame( 2, $result['total'], 'JSON object member order must not affect validation.' );
	}

	/** List projections are bounded, opaque, stable, and secret-free. */
	public function test_list_projections_are_read_only_and_redacted() {
		$fixture      = $this->fixture();
		$profiles     = $this->dispatch(
			$fixture,
			array(
				'operation' => 'list_profiles',
				'page'      => 1,
				'per_page'  => 25,
			)
		);
		$schedules    = $this->dispatch(
			$fixture,
			array(
				'operation' => 'list_schedules',
				'page'      => 1,
				'per_page'  => 25,
			)
		);
		$destinations = $this->dispatch(
			$fixture,
			array(
				'operation' => 'list_destinations',
				'page'      => 1,
				'per_page'  => 25,
			)
		);
		$archives     = $this->dispatch(
			$fixture,
			array(
				'operation' => 'list_archives',
				'page'      => 1,
				'per_page'  => 25,
				'type'      => 'all',
			)
		);

		$this->assertSame( 2, $profiles['total'] );
		$this->assertStringStartsWith( 'prf.v1.', $profiles['profiles'][0]['profile_ref'] );
		$this->assertStringStartsWith( 'sch.v1.', $schedules['schedules'][0]['schedule_ref'] );
		$this->assertStringStartsWith( 'dst.v1.', $destinations['destinations'][0]['destination_ref'] );
		$this->assertStringStartsWith( 'arc.v1.', $archives['archives'][0]['archive_ref'] );
		$this->assertStringNotContainsString( 'PRIVATE', wp_json_encode( array( $profiles, $schedules, $destinations, $archives ) ) );
		$this->assertStringNotContainsString( '.zip', wp_json_encode( $archives ) );
		$this->assertSame( 0, $fixture->writes );
	}

	/** Preview is pure and binds the exact retention and schedule snapshot. */
	public function test_preview_is_pure_and_snapshot_bound() {
		$fixture     = $this->fixture();
		$profiles    = $this->dispatch(
			$fixture,
			array(
				'operation' => 'list_profiles',
				'page'      => 1,
				'per_page'  => 25,
			)
		);
		$profile_ref = $this->full_profile_ref( $profiles );
		$preview     = $this->dispatch(
			$fixture,
			array(
				'operation'   => 'preview_run',
				'target_kind' => 'profile',
				'target_ref'  => $profile_ref,
			)
		);

		$this->assertSame( 'full', $preview['backup_type'] );
		$this->assertCount( 1, $preview['retention_delete_archive_refs'] );
		$this->assertStringStartsWith( 'pre.v1.', $preview['preview_token'] );
		$this->assertSame( $fixture->now + 600, $preview['expires_at'] );
		$this->assertArrayNotHasKey( '_snapshot', $preview );
		$this->assertSame( 0, $fixture->writes );

		++$fixture->archives[0]['modified_at'];
		$result = $this->dispatch(
			$fixture,
			array(
				'operation'     => 'start_backup',
				'profile_ref'   => $profile_ref,
				'preview_token' => $preview['preview_token'],
				'request_ref'   => $this->request_ref,
			)
		);
		$this->assertSame( 'preview_stale', $result['code'] );
		$this->assertSame( array(), $fixture->effects );
	}

	/** Preview applies age/count/size bounds and rejects unsupported destinations. */
	public function test_preview_retention_and_schedule_destination_bounds() {
		$fixture                                = $this->fixture();
		$fixture->options['archive_limit']      = 0;
		$fixture->options['archive_limit_full'] = 0;
		$fixture->options['archive_limit_age']  = 1;
		$profiles                               = $this->dispatch(
			$fixture,
			array(
				'operation' => 'list_profiles',
				'page'      => 1,
				'per_page'  => 25,
			)
		);
		$profile_ref                            = $this->full_profile_ref( $profiles );
		$preview                                = $this->dispatch(
			$fixture,
			array(
				'operation'   => 'preview_run',
				'target_kind' => 'profile',
				'target_ref'  => $profile_ref,
			)
		);
		$this->assertCount( 1, $preview['retention_delete_archive_refs'] );

		$fixture->options['archive_limit_age']      = 0;
		$fixture->options['archive_limit_size']     = 1;
		$fixture->options['archive_limit_size_big'] = 0;
		$fixture->archives[0]['size_bytes']         = 2 * MB_IN_BYTES;
		$fixture->archives[1]['size_bytes']         = 2 * MB_IN_BYTES;
		$preview                                    = $this->dispatch(
			$fixture,
			array(
				'operation'   => 'preview_run',
				'target_kind' => 'profile',
				'target_ref'  => $profile_ref,
			)
		);
		$this->assertCount( 2, $preview['retention_delete_archive_refs'] );

		$schedules    = $this->dispatch(
			$fixture,
			array(
				'operation' => 'list_schedules',
				'page'      => 1,
				'per_page'  => 25,
			)
		);
		$schedule_ref = $schedules['schedules'][0]['schedule_ref'];
		unset( $fixture->options['remote_destinations'][3]['bucket'] );
		$result = $this->dispatch(
			$fixture,
			array(
				'operation'   => 'preview_run',
				'target_kind' => 'schedule',
				'target_ref'  => $schedule_ref,
			)
		);
		$this->assertSame( 'request_conflict', $result['code'] );

		$fixture = $this->fixture();
		$fixture->options['schedules'][8]['remote_destinations'] = range( 1, 11 );
		$schedule_ref = $this->dispatch(
			$fixture,
			array(
				'operation' => 'list_schedules',
				'page'      => 1,
				'per_page'  => 25,
			)
		)['schedules'][0]['schedule_ref'];
		$result       = $this->dispatch(
			$fixture,
			array(
				'operation'   => 'preview_run',
				'target_kind' => 'schedule',
				'target_ref'  => $schedule_ref,
			)
		);
		$this->assertSame( 'too_large', $result['code'] );
	}

	/** Start, replay, status, and cancellation use one durable operation identity. */
	public function test_start_replay_status_and_cancel_state_machine() {
		$fixture     = $this->fixture();
		$profiles    = $this->dispatch(
			$fixture,
			array(
				'operation' => 'list_profiles',
				'page'      => 1,
				'per_page'  => 25,
			)
		);
		$profile_ref = $this->full_profile_ref( $profiles );
		$preview     = $this->dispatch(
			$fixture,
			array(
				'operation'   => 'preview_run',
				'target_kind' => 'profile',
				'target_ref'  => $profile_ref,
			)
		);
		$request     = array(
			'operation'     => 'start_backup',
			'profile_ref'   => $profile_ref,
			'preview_token' => $preview['preview_token'],
			'request_ref'   => $this->request_ref,
		);
		$started     = $this->dispatch( $fixture, $request );
		$replay      = $this->dispatch( $fixture, $request );

		$this->assertSame( $started, $replay );
		$this->assertSame( 'queued', $started['operation']['state'] );
		$this->assertStringStartsWith( 'op.v1.', $started['operation']['operation_ref'] );
		$this->assertCount( 1, $fixture->effects );

		$status = $this->dispatch(
			$fixture,
			array(
				'operation'     => 'get_operation',
				'operation_ref' => $started['operation']['operation_ref'],
			)
		);
		$this->assertSame( 'queued', $status['operation']['state'] );
		$cancel = $this->dispatch(
			$fixture,
			array(
				'operation'     => 'cancel_operation',
				'operation_ref' => $started['operation']['operation_ref'],
			)
		);
		$this->assertSame( 'cancel_requested', $cancel['operation']['state'] );
		$this->assertSame( 'cancel_not_immediate', $cancel['operation']['warning_codes'][0] );
	}

	/**
	 * The preview token verifies against a profile that is gone by the time the start path resolves
	 * it again, because the archive scan behind the token runs BackupBuddy's own code and that can
	 * reload the options. Reading a profile out of the unresolved target dispatches a backup with no
	 * profile at all and reports it as queued.
	 */
	public function test_a_target_that_vanishes_after_its_preview_is_refused_instead_of_dispatched() {
		$fixture     = $this->fixture();
		$profiles    = $this->dispatch(
			$fixture,
			array(
				'operation' => 'list_profiles',
				'page'      => 1,
				'per_page'  => 25,
			)
		);
		$profile_ref = $this->full_profile_ref( $profiles );
		$preview     = $this->dispatch(
			$fixture,
			array(
				'operation'   => 'preview_run',
				'target_kind' => 'profile',
				'target_ref'  => $profile_ref,
			)
		);
		$this->assertStringStartsWith( 'pre.v1.', $preview['preview_token'] );

		// The token is verified on the second scan, which is where the profiles go, so the token
		// still matches the snapshot it was issued for and the start path resolves an empty store.
		$fixture->forget_profiles_at_scan = 2;
		$started                          = $this->dispatch(
			$fixture,
			array(
				'operation'     => 'start_backup',
				'profile_ref'   => $profile_ref,
				'preview_token' => $preview['preview_token'],
				'request_ref'   => $this->request_ref,
			)
		);

		$this->assertSame( array(), $fixture->effects, 'A backup must not be dispatched for a target that no longer resolves.' );
		$this->assertSame( 'not_found', $started['code'] );
		$this->assertFalse( $started['ok'] );
		$this->assertSame( 0, $fixture->writes );
		$this->assertSame( array(), $fixture->records );
	}

	/** Same request reference with a different effect is a stable conflict. */
	public function test_request_reference_hash_conflict() {
		$fixture     = $this->fixture();
		$profiles    = $this->dispatch(
			$fixture,
			array(
				'operation' => 'list_profiles',
				'page'      => 1,
				'per_page'  => 25,
			)
		);
		$profile_ref = $this->full_profile_ref( $profiles );
		$preview     = $this->dispatch(
			$fixture,
			array(
				'operation'   => 'preview_run',
				'target_kind' => 'profile',
				'target_ref'  => $profile_ref,
			)
		);
		$this->dispatch(
			$fixture,
			array(
				'operation'     => 'start_backup',
				'profile_ref'   => $profile_ref,
				'preview_token' => $preview['preview_token'],
				'request_ref'   => $this->request_ref,
			)
		);

		$result = $this->dispatch(
			$fixture,
			array(
				'operation'          => 'start_transfer',
				'archive_ref'        => $this->dispatch(
					$fixture,
					array(
						'operation' => 'list_archives',
						'page'      => 1,
						'per_page'  => 25,
						'type'      => 'all',
					)
				)['archives'][0]['archive_ref'],
				'destination_ref'    => $this->dispatch(
					$fixture,
					array(
						'operation' => 'list_destinations',
						'page'      => 1,
						'per_page'  => 25,
					)
				)['destinations'][0]['destination_ref'],
				'request_ref'        => $this->request_ref,
				'delete_local_after' => false,
			)
		);
		$this->assertSame( 'request_conflict', $result['code'] );
	}

	/** Archive delete is metadata-bound, verified, replay-safe, and exact. */
	public function test_archive_delete_and_replay() {
		$fixture = $this->fixture();
		$archive = $this->dispatch(
			$fixture,
			array(
				'operation' => 'list_archives',
				'page'      => 1,
				'per_page'  => 25,
				'type'      => 'all',
			)
		)['archives'][0];
		$request = array(
			'operation'            => 'delete_archive',
			'archive_ref'          => $archive['archive_ref'],
			'expected_size_bytes'  => $archive['size_bytes'],
			'expected_modified_at' => $archive['modified_at'],
			'request_ref'          => $this->request_ref,
		);
		$deleted = $this->dispatch( $fixture, $request );
		$replay  = $this->dispatch( $fixture, $request );

		$this->assertSame( $deleted, $replay );
		$this->assertTrue( $deleted['deleted'] );
		$this->assertFalse( $deleted['already_absent'] );
		$this->assertSame( 2, $deleted['auxiliary_records_removed'] );
		$this->assertCount( 1, $fixture->archives );
	}

	/** Transfer schedules once and never requests local deletion. */
	public function test_transfer_is_replay_safe_and_never_deletes_local() {
		$fixture     = $this->fixture();
		$archive     = $this->dispatch(
			$fixture,
			array(
				'operation' => 'list_archives',
				'page'      => 1,
				'per_page'  => 25,
				'type'      => 'all',
			)
		)['archives'][0];
		$destination = $this->dispatch(
			$fixture,
			array(
				'operation' => 'list_destinations',
				'page'      => 1,
				'per_page'  => 25,
			)
		)['destinations'][0];
		$request     = array(
			'operation'          => 'start_transfer',
			'archive_ref'        => $archive['archive_ref'],
			'destination_ref'    => $destination['destination_ref'],
			'request_ref'        => $this->request_ref,
			'delete_local_after' => false,
		);
		$first       = $this->dispatch( $fixture, $request );
		$replay      = $this->dispatch( $fixture, $request );

		$this->assertSame( $first, $replay );
		$this->assertSame( 'transfer', $first['operation']['kind'] );
		$this->assertCount( 1, $fixture->effects );
		$this->assertSame( 'transfer', $fixture->effects[0][0] );
		$this->assertCount( 2, $fixture->archives );
	}

	/** Mutations serialize, deletion is staged, and effect failure is durable. */
	public function test_mutation_lock_staging_and_failure_state() {
		$fixture            = $this->fixture();
		$fixture->lock_busy = true;
		$archive            = $this->dispatch(
			$fixture,
			array(
				'operation' => 'list_archives',
				'page'      => 1,
				'per_page'  => 25,
				'type'      => 'all',
			)
		)['archives'][0];
		$delete_request     = array(
			'operation'            => 'delete_archive',
			'archive_ref'          => $archive['archive_ref'],
			'expected_size_bytes'  => $archive['size_bytes'],
			'expected_modified_at' => $archive['modified_at'],
			'request_ref'          => $this->request_ref,
		);
		$busy               = $this->dispatch( $fixture, $delete_request );
		$this->assertSame( 'lock_busy', $busy['code'] );
		$this->assertSame( array(), $fixture->effects );

		$fixture->lock_busy = false;
		$deleted            = $this->dispatch( $fixture, $delete_request );
		$this->assertTrue( $deleted['deleted'] );
		$this->assertTrue( $fixture->delete_saw_pending, 'The durable pending receipt must precede the file effect.' );
		$this->assertFalse( $fixture->lock_held );

		$fixture                  = $this->fixture();
		$fixture->transfer_result = false;
		$archive                  = $this->dispatch(
			$fixture,
			array(
				'operation' => 'list_archives',
				'page'      => 1,
				'per_page'  => 25,
				'type'      => 'all',
			)
		)['archives'][0];
		$destination              = $this->dispatch(
			$fixture,
			array(
				'operation' => 'list_destinations',
				'page'      => 1,
				'per_page'  => 25,
			)
		)['destinations'][0];
		$request                  = array(
			'operation'          => 'start_transfer',
			'archive_ref'        => $archive['archive_ref'],
			'destination_ref'    => $destination['destination_ref'],
			'request_ref'        => $this->request_ref,
			'delete_local_after' => false,
		);
		$failed                   = $this->dispatch( $fixture, $request );
		$this->assertSame( 'effect_failed', $failed['code'] );
		$replay = $this->dispatch( $fixture, $request );
		$this->assertSame( 'failed', $replay['operation']['state'] );
	}

	/** Malformed durable state fails closed instead of being normalized away. */
	public function test_corrupt_operation_ledger_fails_closed() {
		$fixture          = $this->fixture();
		$fixture->records = array(
			'operations' => array( 'bad' => array( 'request_ref' => $this->request_ref ) ),
			'receipts'   => array(),
		);
		$result           = $this->dispatch(
			$fixture,
			array(
				'operation'     => 'get_operation',
				'operation_ref' => str_repeat( 'a', 24 ),
			)
		);
		$this->assertSame( 'storage_failed', $result['code'] );
	}

	/** Tampered refs, invalid UUID names, and private input never escape. */
	public function test_tamper_bounds_and_redacted_errors() {
		$fixture  = $this->fixture();
		$profiles = $this->dispatch(
			$fixture,
			array(
				'operation' => 'list_profiles',
				'page'      => 1,
				'per_page'  => 25,
			)
		);
		$tampered = $profiles['profiles'][0]['profile_ref'] . 'x';
		$result   = $this->dispatch(
			$fixture,
			array(
				'operation'   => 'preview_run',
				'target_kind' => 'profile',
				'target_ref'  => $tampered,
			)
		);
		$this->assertSame( 'not_found', $result['code'] );

		$result = $this->dispatch(
			$fixture,
			array(
				'operation'     => 'start_backup',
				'profile_ref'   => $profiles['profiles'][0]['profile_ref'],
				'preview_token' => str_repeat( 'PRIVATE', 100 ),
				'request_ref'   => 'bad-id',
			)
		);
		$this->assertSame( 'invalid_request', $result['code'] );
		$this->assertStringNotContainsString( 'PRIVATE', wp_json_encode( $result ) );
	}

	/**
	 * Protocol errors are a flat envelope with a scalar code, like every other v2 bridge. The
	 * Dashboard reads $information['error'] as a string, so an array under that reserved key was
	 * unreadable to it.
	 */
	public function test_protocol_errors_are_a_flat_scalar_code_envelope() {
		$fixture = $this->fixture();
		$result  = $this->dispatch(
			$fixture,
			array(
				'operation'     => 'get_operation',
				'operation_ref' => str_repeat( 'a', 24 ),
			)
		);

		$this->assertSame( array( 'protocol', 'operation', 'ok', 'code' ), array_keys( $result ) );
		$this->assertSame( '2', $result['protocol'] );
		$this->assertSame( 'get_operation', $result['operation'] );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'not_found', $result['code'] );
		$this->assertArrayNotHasKey( 'error', $result );

		$unknown = $this->dispatch( $fixture, array( 'operation' => 'PRIVATE_operation' ) );
		$this->assertSame( 'unknown', $unknown['operation'], 'An unrecognised operation must not be echoed back.' );
		$this->assertSame( 'invalid_request', $unknown['code'] );
		$this->assertStringNotContainsString( 'PRIVATE', wp_json_encode( $unknown ) );
	}

	/** A committed mutation keeps its outcome when the lock release fails, and says the release failed. */
	public function test_committed_mutation_survives_a_failed_lock_release() {
		$fixture                 = $this->fixture();
		$fixture->release_result = false;
		$archive                 = $this->dispatch(
			$fixture,
			array(
				'operation' => 'list_archives',
				'page'      => 1,
				'per_page'  => 25,
				'type'      => 'all',
			)
		)['archives'][0];
		$request                 = array(
			'operation'            => 'delete_archive',
			'archive_ref'          => $archive['archive_ref'],
			'expected_size_bytes'  => $archive['size_bytes'],
			'expected_modified_at' => $archive['modified_at'],
			'request_ref'          => $this->request_ref,
		);
		$deleted                 = $this->dispatch( $fixture, $request );

		$this->assertArrayNotHasKey( 'code', $deleted, 'The archive really was deleted, so the response must not be an error.' );
		$this->assertTrue( $deleted['deleted'] );
		$this->assertSame( 2, $deleted['auxiliary_records_removed'] );
		$this->assertCount( 1, $fixture->archives, 'The deletion committed before the release failed.' );
		$this->assertSame( array( 'lock_release_failed' ), $deleted['warning_codes'] );

		$fixture->release_result = true;
		$replay                  = $this->dispatch( $fixture, $request );
		$this->assertTrue( $replay['deleted'] );
		$this->assertArrayNotHasKey( 'warning_codes', $replay, 'A later request that released the lock has nothing to warn about.' );

		$fixture                 = $this->fixture();
		$fixture->release_result = false;
		$profiles                = $this->dispatch(
			$fixture,
			array(
				'operation' => 'list_profiles',
				'page'      => 1,
				'per_page'  => 25,
			)
		);
		$profile_ref             = $this->full_profile_ref( $profiles );
		$preview                 = $this->dispatch(
			$fixture,
			array(
				'operation'   => 'preview_run',
				'target_kind' => 'profile',
				'target_ref'  => $profile_ref,
			)
		);
		$started                 = $this->dispatch(
			$fixture,
			array(
				'operation'     => 'start_backup',
				'profile_ref'   => $profile_ref,
				'preview_token' => $preview['preview_token'],
				'request_ref'   => $this->request_ref,
			)
		);

		$this->assertCount( 1, $fixture->effects, 'The backup was started, so the response must report the operation.' );
		$this->assertSame( 'queued', $started['operation']['state'] );
		$this->assertSame( array( 'lock_release_failed' ), $started['warning_codes'] );
	}

	/** An operation nothing has reported on for a day is reported unknown, not still queued. */
	public function test_a_stalled_operation_is_reported_unknown_instead_of_in_flight() {
		$fixture           = $this->fixture();
		$stalled           = $this->operation_record( 1, 'queued', $fixture->now - ( 2 * DAY_IN_SECONDS ) );
		$live              = $this->operation_record( 2, 'running', $fixture->now - 60 );
		$fixture->records  = array(
			'operations' => array(
				$stalled['operation_ref'] => $stalled,
				$live['operation_ref']    => $live,
			),
			'receipts'   => array(),
		);

		$stalled_status = $this->dispatch(
			$fixture,
			array(
				'operation'     => 'get_operation',
				'operation_ref' => $stalled['operation_ref'],
			)
		);
		$live_status    = $this->dispatch(
			$fixture,
			array(
				'operation'     => 'get_operation',
				'operation_ref' => $live['operation_ref'],
			)
		);

		$this->assertSame( 'unknown', $stalled_status['operation']['state'] );
		$this->assertSame( $fixture->now - ( 2 * DAY_IN_SECONDS ), $stalled_status['operation']['updated_at'], 'Settling is not an observation, so the last-seen time stands.' );
		$this->assertSame( 'running', $live_status['operation']['state'] );
		$this->assertSame( 0, $fixture->writes, 'Reading an operation must not write the ledger.' );
	}

	/** Abandoned in-flight records age out, so they cannot fill the ledger and refuse every new mutation. */
	public function test_abandoned_operations_cannot_brick_the_operation_ledger() {
		$fixture    = $this->fixture();
		$operations = array();
		for ( $index = 1; $index <= 100; $index++ ) {
			$record = $this->operation_record( $index, 'running', $fixture->now - ( 8 * DAY_IN_SECONDS ) );
			$operations[ $record['operation_ref'] ] = $record;
		}
		$fixture->records = array(
			'operations' => $operations,
			'receipts'   => array(),
		);

		$archive = $this->dispatch(
			$fixture,
			array(
				'operation' => 'list_archives',
				'page'      => 1,
				'per_page'  => 25,
				'type'      => 'all',
			)
		)['archives'][0];
		$deleted = $this->dispatch(
			$fixture,
			array(
				'operation'            => 'delete_archive',
				'archive_ref'          => $archive['archive_ref'],
				'expected_size_bytes'  => $archive['size_bytes'],
				'expected_modified_at' => $archive['modified_at'],
				'request_ref'          => $this->request_ref,
			)
		);

		$this->assertArrayNotHasKey( 'code', $deleted, 'Records nothing has reported on for eight days must not refuse a new mutation.' );
		$this->assertTrue( $deleted['deleted'] );
		$this->assertSame( array(), $fixture->records['operations'], 'The abandoned records settle and then age out.' );
	}

	/**
	 * @param int    $index      Record index.
	 * @param string $state      Stored state.
	 * @param int    $updated_at Last time the Child observed the operation.
	 * @return array
	 */
	private function operation_record( $index, $state, $updated_at ) {
		$digest = hash( 'sha256', 'fixture-operation-' . $index );
		return array(
			'operation_ref'   => 'op.v1.' . $digest,
			'request_ref'     => sprintf( '123e4567-e89b-42d3-a456-%012d', $index ),
			'request_hash'    => $digest,
			'kind'            => 'backup',
			'state'           => $state,
			'created_at'      => $updated_at,
			'updated_at'      => $updated_at,
			'progress'        => 0,
			'archive_ref'     => null,
			'destination_ref' => null,
			'warnings'        => array(),
			'serial'          => substr( $digest, 0, 10 ),
		);
	}
}
