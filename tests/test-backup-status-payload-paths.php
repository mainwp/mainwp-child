<?php
/**
 * Backup status diagnostics must preserve the Dashboard's polling contracts.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Backup_Error;
use MainWP\Child\MainWP_Child_Backup_Mutex;
use MainWP\Child\MainWP_Child_Backup_Task;
use MainWP\Child\MainWP_Child_Callable;
use MainWP\Child\MainWP_Helper;

require_once __DIR__ . '/test-backup-task-runner.php';

class Backup_Status_Payload_Response extends RuntimeException {
	public $payload;

	public function __construct( $payload ) {
		parent::__construct( 'Status payload response' );
		$this->payload = $payload;
	}
}

class Backup_Status_Payload_Response_Helper extends MainWP_Helper {
	public static function write( $value ) {
		throw new Backup_Status_Payload_Response( $value );
	}
}

class BackupStatusPayloadPathsTest extends WP_UnitTestCase {
	private $backup_dir;
	private $scratch;
	private $installed_table = false;

	public function set_up() {
		parent::set_up();
		$this->backup_dir = trailingslashit( ABSPATH ) . 'wp-content/uploads/mainwp/backup-a1b2c3d4e5f60718';
	}

	public function tear_down() {
		if ( $this->scratch ) {
			foreach ( glob( $this->scratch . '/*' ) as $file ) {
				unlink( $file );
			}
			rmdir( $this->scratch );
		}
		parent::tear_down();
		if ( $this->installed_table ) {
			global $wpdb;
			remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
			$wpdb->query( 'DROP TABLE IF EXISTS ' . MainWP_Child_Backup_Task::instance()->get_table_name() );
		}
	}

	private function install_task_table() {
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		try {
			delete_option( MainWP_Child_Backup_Task::DB_VERSION_OPTION );
			MainWP_Child_Backup_Task::instance()->install();
			$this->installed_table = true;
		} finally {
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		}
	}

	private function task( $state, $type = 'full', $phase = 'completed' ) {
		return (object) array(
			'task_id' => 'statuspaths123',
			'type' => $type,
			'phase' => $phase,
			'sub_state' => wp_json_encode( $state ),
			'started_at' => '2026-10-17 12:00:00',
			'last_progress_at' => '2026-10-17 12:01:00',
			'max_wall_clock_at' => '2026-10-17 14:00:00',
			'resume_count' => 0,
			'max_resumes' => 6,
		);
	}

	public function test_beta6_status_payload_compatibility_before_path_projection() {
		$spine = MainWP_Child_Backup_Task::instance();
		$walk = array( 'excludes' => array( 'wp-content/cache/*' ), 'skipped_paths' => array( 'host-link.php' ), 'excludenonwp' => 0, 'excludezip' => 0 );
		foreach ( array( 'full', 'incremental', 'db' ) as $type ) {
			$state = array(
				'artifact' => array( 'file' => 'backup-artifact.zip', 'size' => 123, 'sha256' => str_repeat( 'a', 64 ) ),
				'artifacts' => array(
					array( 'file' => 'backup-artifact.zip', 'size' => 123, 'role' => $type ),
					array( 'file' => 'files.list', 'size' => 20, 'role' => 'manifest', 'walk' => $walk ),
				),
			);
			$payload = $spine->status_payload( $this->task( $state, $type ), '/unused-backup' );
			$this->assertSame( basename( $payload['file'] ), $payload['file'], $type );
			foreach ( $payload['artifacts'] as $artifact ) {
				$this->assertSame( basename( $artifact['file'] ), $artifact['file'], $type );
			}
			$this->assertSame( $state['artifacts'], $payload['artifacts'], $type );
			$this->assertSame( wp_json_encode( $walk ), wp_json_encode( $payload['artifacts'][1]['walk'] ), $type );
		}

		$error = array( 'code' => MainWP_Backup_Error::ERR_BACKUP_CANCELLED, 'message' => MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_BACKUP_CANCELLED ) );
		$failed = $spine->status_payload( $this->task( array( 'error' => $error ), 'full', 'failed' ), '/unused-backup' );
		$this->assertIsString( $failed['error'] );
		$this->assertIsString( $failed['error_code'] );
		$this->assertSame( $error['message'], $failed['error'] );
		$this->assertSame( $error['code'], $failed['error_code'] );

		$state = array(
			'verification' => array( 'reconcile' => array( 'kept_paths' => array( 'wp-content/uploads/kept.jpg' ) ) ),
			'progress' => array( 'woo_preserve' => array( 'orders_kept' => 4 ) ),
			'error' => $error,
			'params' => array( 'pull_manifest' => array( 'mode' => 'whole_files', 'artifacts' => array() ) ),
			'identity_preserve' => array( 'private' => true ),
			'dump_files' => array( '/private/dump.sql' ),
		);
		foreach ( array( 'restore', 'restore_db' ) as $type ) {
			$payload = $spine->status_payload( $this->task( $state, $type, 'failed' ), '/unused-backup' );
			$this->assertSame( array(
				'verification' => $state['verification'],
				'progress' => $state['progress'],
				'error' => $error,
				'params' => array( 'pull_manifest' => true ),
				'identity_preserved' => true,
			), $payload['sub_state'] );
		}
	}

	private function expand_fixture( $value ) {
		if ( is_array( $value ) ) {
			return array_map( array( $this, 'expand_fixture' ), $value );
		}
		$tokens = array(
			'<task_id>' => 'statuspaths123',
			'<started_at>' => '2026-10-17 12:00:00',
			'<last_progress_at>' => '2026-10-17 12:01:00',
			'<max_wall_clock_at>' => '2026-10-17 14:00:00',
			'<size>' => 123,
			'<sha256>' => str_repeat( 'a', 64 ),
			'<archive>' => 'backup-artifact.tar',
			'<core-part>' => 'core.status.part1.zip',
			'<plugins-part>' => 'plugins.status.part1.zip',
			'<parent_manifest_file>' => 'parent-files.list',
		);
		if ( is_string( $value ) ) {
			return $tokens[ $value ] ?? strtr( $value, array( '<ABSPATH>' => trailingslashit( ABSPATH ), '<backup_dir>' => $this->backup_dir ) );
		}
		return $value;
	}

	private function fixture( $name ) {
		$value = json_decode( file_get_contents( __DIR__ . '/fixtures/backup-status-' . $name . '.json' ), true );
		$this->assertIsArray( $value, $name );
		return $this->expand_fixture( $value );
	}

	private function assert_no_absolute_paths( $value ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				$this->assert_no_absolute_paths( $item );
			}
		} elseif ( is_string( $value ) ) {
			$this->assertDoesNotMatchRegularExpression( '~^(?:/|[A-Za-z]:/|//)~', str_replace( '\\', '/', $value ), $value );
		}
	}

	private function assert_fixture_projection( $name ) {
		$fixture = $this->fixture( $name );
		$task = $this->task( $fixture['cursor'], $fixture['type'], $fixture['phase'] );
		$before = $task->sub_state;
		$spine = MainWP_Child_Backup_Task::instance();
		$payload = $spine->status_payload( $task, $this->backup_dir );
		$this->assertSame( $fixture['projected'], $payload['sub_state'], $name );
		$this->assertSame( $before, $task->sub_state );
		$this->assertSame( $fixture['cursor'], $spine->read_sub_state( $task, $this->backup_dir ) );
		$this->assert_no_absolute_paths( $payload );
		$this->assertStringNotContainsString( basename( $this->backup_dir ), wp_json_encode( $payload ) );
	}

	public static function dumping_tiers() {
		return array( 'tier1' => array( 'tier1' ), 'tier2' => array( 'tier2' ) );
	}

	/** @dataProvider dumping_tiers */
	public function test_dumping_phase_paths_are_projected( $tier ) {
		$this->assert_fixture_projection( 'dumping-' . $tier );
	}

	public static function archive_engines() {
		return array( 'zip' => array( 'zip' ), 'tar' => array( 'tar' ) );
	}

	/** @dataProvider archive_engines */
	public function test_archiving_phase_paths_are_projected( $engine ) {
		$this->assert_fixture_projection( 'archiving-' . $engine );
	}

	public static function completed_cases() {
		return array_map( static function ( $case ) { return array( $case ); }, array( 'db', 'files', 'full', 'full-hash-skipped', 'incremental' ) );
	}

	/** @dataProvider completed_cases */
	public function test_completed_phase_fixtures_have_projected_prefix_and_unchanged_artifacts( $case ) {
		$expected = $this->fixture( 'completed-' . $case );
		$state = $expected['sub_state'];
		if ( isset( $state['db_prefix'] ) ) {
			$state['db_prefix'] = $this->backup_dir . '/' . $state['db_prefix'];
		}
		$payload = MainWP_Child_Backup_Task::instance()->status_payload( $this->task( $state, $expected['type'] ), $this->backup_dir );
		ksort( $expected );
		ksort( $payload );
		$this->assertSame( $expected, $payload, $case );
		$this->assertSame( $state['artifact'], $payload['sub_state']['artifact'] );
		if ( isset( $state['artifacts'] ) ) {
			$this->assertSame( $state['artifacts'], $payload['artifacts'] );
		}
		$this->assert_no_absolute_paths( $payload );
		$this->assertStringNotContainsString( basename( $this->backup_dir ), wp_json_encode( $payload ) );
	}

	public static function task_types() {
		return array( array( 'full' ), array( 'restore' ), array( 'restore_db' ) );
	}

	/** @dataProvider task_types */
	public function test_failed_status_redacts_embedded_paths_and_keeps_registry_messages( $type ) {
		$site_file = trailingslashit( ABSPATH ) . 'wp-content/plugins/site.php';
		$message = 'Cannot open ' . $this->backup_dir . '/dump.sql; reading ' . $site_file . '; retry ' . str_replace( '/', '\\', $this->backup_dir . '/dump.sql' );
		$state = array( 'error' => array( 'code' => MainWP_Backup_Error::ERR_DB_DUMP_EMPTY, 'message' => $message ) );
		$spine = MainWP_Child_Backup_Task::instance();
		$payload = $spine->status_payload( $this->task( $state, $type, 'failed' ), $this->backup_dir );
		$expected = 'Cannot open dump.sql; reading wp-content/plugins/site.php; retry dump.sql';
		$this->assertSame( $expected, $payload['error'] );
		$this->assertSame( $expected, $payload['sub_state']['error']['message'] );
		$this->assertSame( $state['error']['code'], $payload['error_code'] );
		$this->assertStringNotContainsString( basename( $this->backup_dir ), wp_json_encode( $payload ) );
		$state['error']['message'] = 'mkdir(' . $this->backup_dir . '): Permission denied';
		$bare = $spine->status_payload( $this->task( $state, $type, 'failed' ), $this->backup_dir );
		$this->assertSame( 'mkdir(wp-content/uploads/mainwp/backup-[redacted]): Permission denied', $bare['error'] );
		$this->assertStringNotContainsString( basename( $this->backup_dir ), wp_json_encode( $bare ) );
		$state['error']['message'] = MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_DB_DUMP_EMPTY );
		$registry = $spine->status_payload( $this->task( $state, $type, 'failed' ), $this->backup_dir );
		$this->assertSame( $state['error'], $registry['sub_state']['error'] );
		$this->assertSame( $state['error']['message'], $registry['error'] );
	}

	public function test_cancelled_cursor_projects_retained_dump_files() {
		$state = array(
			'error' => array( 'code' => MainWP_Backup_Error::ERR_BACKUP_CANCELLED, 'message' => MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_BACKUP_CANCELLED ) ),
			'db_files' => array( $this->backup_dir . '/db-posts.sql', $this->backup_dir . '/db-options.sql' ),
			'archive_file' => 'backup-partial.tar',
			'completed_parts' => array( array( 'file' => 'core.1.part1.zip', 'size' => 20 ) ),
			'params' => array( 'parent_manifest_file' => 'parent-files.list' ),
		);
		$payload = MainWP_Child_Backup_Task::instance()->status_payload( $this->task( $state, 'full', 'failed' ), $this->backup_dir );
		$state['db_files'] = array( 'db-posts.sql', 'db-options.sql' );
		$this->assertSame( $state, $payload['sub_state'] );
		$this->assert_no_absolute_paths( $payload );
	}

	public function test_absolute_paths_outside_site_use_basenames_and_preserve_relative_values() {
		$state = array( 'params' => array(
			'excludes' => array( '/srv/external/content/cache', 'C:\\hosting\\content\\uploads', '\\\\server\\share\\content\\media', 'wp-content/cache/*', 'relative\\pattern/*' ),
			'nested' => array( 'path' => $this->backup_dir . '/restore-123/scratch.sql', 'backup_dir' => $this->backup_dir . '/', 'site_root' => ABSPATH, 'flag' => true, 'count' => 4, 'unset' => null ),
			'sibling' => untrailingslashit( ABSPATH ) . '-sibling/outside.php',
		) );
		$payload = MainWP_Child_Backup_Task::instance()->status_payload( $this->task( $state ), $this->backup_dir );
		$this->assertSame( array( 'cache', 'uploads', 'media', 'wp-content/cache/*', 'relative\\pattern/*' ), $payload['sub_state']['params']['excludes'] );
		$this->assertSame( array( 'path' => 'scratch.sql', 'backup_dir' => '', 'site_root' => '', 'flag' => true, 'count' => 4, 'unset' => null ), $payload['sub_state']['params']['nested'] );
		$this->assertSame( 'outside.php', $payload['sub_state']['params']['sibling'] );
		$this->assert_no_absolute_paths( $payload );
	}

	public static function backup_root_styles() {
		return array( array( 'C:\\hosting\\backup-a1b2c3d4e5f60718' ), array( 'C:/hosting/backup-a1b2c3d4e5f60718' ), array( '\\\\server\\share\\backup-a1b2c3d4e5f60718' ) );
	}

	/** @dataProvider backup_root_styles */
	public function test_windows_and_unc_backup_roots_are_private( $root ) {
		$state = array( 'db_prefix' => $root . '/db-prefix', 'backup_dir' => $root, 'error' => array( 'message' => 'Read ' . $root . '/dump.sql', 'code' => 'dump_error' ) );
		$payload = MainWP_Child_Backup_Task::instance()->status_payload( $this->task( $state, 'db', 'failed' ), $root );
		$this->assertSame( 'db-prefix', $payload['sub_state']['db_prefix'] );
		$this->assertSame( '', $payload['sub_state']['backup_dir'] );
		$this->assertSame( 'Read dump.sql', $payload['error'] );
	}

	public static function cursor_storage_modes() {
		return array( 'inline' => array( false ), 'sidecar' => array( true ) );
	}

	/** @dataProvider cursor_storage_modes */
	public function test_polling_preserves_persisted_cursor_and_sidecar_bytes( $sidecar ) {
		global $wpdb;
		$this->install_task_table();
		$this->scratch = sys_get_temp_dir() . '/backup-' . strtolower( wp_generate_password( 16, false ) );
		$this->assertTrue( mkdir( $this->scratch ) );
		$state = array( 'db_prefix' => $this->scratch . '/db-prefix', 'db_tier' => 2, 'db_cursor' => array( 'files' => array( $this->scratch . '/posts.sql' ) ), 'walk_cursor' => array( 'stack' => array( array( 'dir' => ABSPATH, 'index' => 1 ) ) ) );
		if ( $sidecar ) {
			$state['padding'] = str_repeat( 'x', MainWP_Child_Backup_Task::SUB_STATE_INLINE_MAX );
		}
		$spine = MainWP_Child_Backup_Task::instance();
		$id = $spine->create_task( 'full' );
		$this->assertIsString( $id );
		$serialized = $spine->write_sub_state( $id, $state, $this->scratch, 0 );
		$this->assertNotFalse( $serialized );
		$this->assertNotFalse( $spine->checkpoint( $id, 0, array( 'phase' => 'dumping_db', 'sub_state' => $serialized ), true, $this->scratch ) );
		$task = $spine->get_task( $id );
		$before = $task->sub_state;
		if ( $sidecar ) {
			$pointer = json_decode( $before, true );
			$this->assertTrue( $pointer['_sidecar'] );
			$path = $this->scratch . '/' . $pointer['file'];
			$bytes = file_get_contents( $path );
		}
		$one = $spine->status_payload( $task, $this->scratch );
		$two = $spine->status_payload( $spine->get_task( $id ), $this->scratch );
		$this->assertSame( $one, $two );
		$this->assertSame( 'db-prefix', $one['sub_state']['db_prefix'] );
		$this->assertSame( array( 'posts.sql' ), $one['sub_state']['db_cursor']['files'] );
		$this->assertSame( $before, $task->sub_state );
		$this->assertSame( $before, $wpdb->get_var( $wpdb->prepare( 'SELECT sub_state FROM ' . $spine->get_table_name() . ' WHERE task_id = %s', $id ) ) );
		$this->assertSame( $state, $spine->read_sub_state( $spine->get_task( $id ), $this->scratch ) );
		if ( $sidecar ) {
			$this->assertSame( $bytes, file_get_contents( $path ) );
		}
		$runner = new Testable_Task_Runner();
		$runner->test_backup_dir = $this->scratch;
		$resumed = false;
		$runner->stubs['dump_tier2'] = function ( $prefix, $cursor ) use ( $state, &$resumed ) {
			$this->assertSame( $state['db_prefix'], $prefix );
			$this->assertSame( $state['db_cursor'], $cursor );
			$resumed = true;
			return array( 'done' => false, 'cursor' => $cursor );
		};
		$this->assertTrue( MainWP_Child_Backup_Mutex::acquire( 'native', $id, 3600 ) );
		try {
			$this->assertSame( 'dumping_db', $runner->advance_one_slice( $spine->get_task( $id ), 'status-resume' )->phase );
		} finally {
			MainWP_Child_Backup_Mutex::release( $id );
		}
		$this->assertTrue( $resumed, 'The next dump slice must receive the original absolute cursor paths.' );
	}

	public function test_restore_verification_paths_are_projected_without_expanding_cursor() {
		$state = array(
			'verification' => array( 'reconcile' => array( 'kept_paths' => array( 'wp-content/uploads/kept.jpg', ABSPATH . 'wp-content/plugins/kept.php', '/srv/external/kept.jpg', $this->backup_dir . '/scratch.sql' ) ) ),
			'progress' => array( 'woo_preserve' => array( 'orders_kept' => 4 ) ),
			'params' => array( 'pull_manifest' => array( 'url' => 'https://bucket.example/file' ), 'scratch' => $this->backup_dir ),
			'dump_files' => array( $this->backup_dir . '/dump.sql' ),
		);
		$payload = MainWP_Child_Backup_Task::instance()->status_payload( $this->task( $state, 'restore' ), $this->backup_dir );
		$this->assertSame( array( 'verification', 'progress', 'params' ), array_keys( $payload['sub_state'] ) );
		$this->assertSame( array( 'wp-content/uploads/kept.jpg', 'wp-content/plugins/kept.php', 'kept.jpg', 'scratch.sql' ), $payload['sub_state']['verification']['reconcile']['kept_paths'] );
		$this->assertSame( $state['progress'], $payload['progress'] );
		$this->assertSame( array( 'pull_manifest' => true ), $payload['sub_state']['params'] );
	}

	public function test_restore_create_replay_keeps_reused_phase_and_error_code() {
		$this->install_task_table();
		$spine = MainWP_Child_Backup_Task::instance();
		$dirs = MainWP_Helper::get_mainwp_dir( 'backup' );
		$artifact = array( 'file' => 'db.sql', 'role' => 'db', 'size' => 1, 'sha256' => hash( 'sha256', 'x' ) );
		$state = array(
			'params' => array( 'restore_id' => 'pathreplay123', 'artifacts' => array( $artifact ), 'pull_manifest' => array( 'mode' => 'whole_files' ) ),
			'verification' => array( 'reconcile' => array( 'kept_paths' => array( 'wp-content/kept.php' ) ) ),
			'progress' => array( 'woo_preserve' => array( 'orders_kept' => 4 ) ),
			'error' => array( 'code' => MainWP_Backup_Error::ERR_RESTORE_DB_REPLAY, 'message' => 'Cannot read ' . trailingslashit( $dirs[0] ) . 'dump.sql' ),
		);
		$id = $spine->create_task( 'restore_db', $state );
		$this->assertIsString( $id );
		$this->assertNotFalse( $spine->checkpoint( $id, 0, array( 'phase' => 'failed' ) ) );
		if ( ! class_exists( 'MainWP\\Child\\Backup_Status_Callable_Fixture', false ) ) {
			$methods = '';
			foreach ( array( 'restore_task_create', 'sanitize_restore_artifacts' ) as $name ) {
				$method = new ReflectionMethod( MainWP_Child_Callable::class, $name );
				$lines = file( $method->getFileName() );
				$methods .= str_replace( 'MainWP_Helper', '\\Backup_Status_Payload_Response_Helper', implode( '', array_slice( $lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1 ) ) );
			}
			// The real callable exits when writing; this fixture substitutes only its response boundary.
			// nosemgrep: AIK_eval-use -- test fixture; evaluates source copied from this repo's own MainWP_Child_Callable methods.
			eval( 'namespace MainWP\\Child; class Backup_Status_Callable_Fixture extends MainWP_Child_Callable {' . $methods . '}' );
		}
		$previous_post = $_POST;
		$_POST = array( 'restore_id' => 'pathreplay123', 'artifacts_json' => wp_json_encode( array( $artifact ) ), 'options_json' => wp_json_encode( array( 'sub_type' => 'db' ) ) );
		try {
			( new \MainWP\Child\Backup_Status_Callable_Fixture() )->restore_task_create();
			$this->fail( 'The create replay must write its response.' );
		} catch ( Backup_Status_Payload_Response $response ) {
			$this->assertTrue( $response->payload['reused'] );
			$this->assertSame( $id, $response->payload['task_id'] );
			$this->assertSame( 'failed', $response->payload['phase'] );
			$this->assertSame( $state['error']['code'], $response->payload['error_code'] );
			$this->assertSame( 'Cannot read dump.sql', $response->payload['error'] );
			$this->assertSame( array(), $response->payload['sub_state']['params'] );
			$this->assertSame( $state['verification'], $response->payload['sub_state']['verification'] );
			$this->assertSame( $state['progress'], $response->payload['progress'] );
		} finally {
			$_POST = $previous_post;
		}
	}

	public function test_beta6_exempt_subtrees_keep_absolute_values_byte_identical() {
		$absolute = '/srv/external-content/cache';
		$state = array(
			'artifact' => array( 'file' => 'backup-artifact.tar', 'size' => 123, 'metadata' => $absolute ),
			'artifacts' => array( array( 'file' => 'files.list', 'role' => 'manifest', 'walk' => array( 'excludes' => array( $absolute ), 'skipped_paths' => array( 'host-link.php' ) ) ) ),
			'woo_meta' => array( 'has_wc_orders' => true, 'probe' => $absolute ),
			'progress' => array( 'current_file' => 'wp_posts' ),
		);
		$payload = MainWP_Child_Backup_Task::instance()->status_payload( $this->task( $state ), $this->backup_dir );
		foreach ( array( 'artifact', 'artifacts', 'woo_meta' ) as $key ) {
			$this->assertSame( wp_json_encode( $state[ $key ] ), wp_json_encode( $payload['sub_state'][ $key ] ), $key );
		}
		$this->assertSame( $state['artifacts'], $payload['artifacts'] );
		$this->assertSame( $state['woo_meta'], $payload['woo'] );
		$this->assertSame( 'wp_posts', $payload['progress']['current_file'] );
	}

	public function test_default_backup_directory_is_redacted() {
		$dirs = MainWP_Helper::get_mainwp_dir( 'backup' );
		$state = array( 'db_prefix' => trailingslashit( $dirs[0] ) . 'db-prefix', 'backup_dir' => $dirs[0] );
		$payload = MainWP_Child_Backup_Task::instance()->status_payload( $this->task( $state ) );
		$this->assertSame( array( 'db_prefix' => 'db-prefix', 'backup_dir' => '' ), $payload['sub_state'] );
	}

	public function test_path_projection_applies_to_every_backup_phase_and_type() {
		$state = array( 'params' => array( 'nested' => array( ABSPATH . 'wp-config.php' ) ), 'progress' => array( 'current_file' => ABSPATH . 'wp-content/site.php' ), 'db_prefix' => $this->backup_dir . '/db-prefix' );
		foreach ( array( 'full', 'db', 'files', 'incremental' ) as $type ) {
			foreach ( array( 'queued', 'dumping_db', 'archiving_files', 'uploading', 'submitting', 'completed', 'failed' ) as $phase ) {
				$payload = MainWP_Child_Backup_Task::instance()->status_payload( $this->task( $state, $type, $phase ), $this->backup_dir );
				$this->assertSame( array( 'nested' => array( 'wp-config.php' ) ), $payload['sub_state']['params'], $type . ':' . $phase );
				$this->assertSame( 'wp-content/site.php', $payload['progress']['current_file'] );
				$this->assertSame( 'db-prefix', $payload['sub_state']['db_prefix'] );
				$this->assert_no_absolute_paths( $payload );
				$this->assertStringNotContainsString( basename( $this->backup_dir ), wp_json_encode( $payload ) );
			}
		}
	}
}
