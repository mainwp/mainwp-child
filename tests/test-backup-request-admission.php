<?php
/**
 * Native backup format admission.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Backup;
use MainWP\Child\MainWP_Backup_Error;
use MainWP\Child\MainWP_Child_Backup_Task;
use MainWP\Child\MainWP_Child_Backup_Mutex;
use MainWP\Child\MainWP_Child_Backup_Task_Runner;
use MainWP\Child\MainWP_Child_Restore_Task_Runner;
use MainWP\Child\MainWP_Child_Callable;
use MainWP\Child\MainWP_Child_Backup_Layout_Codec;
use MainWP\Child\MainWP_Exception;

require_once __DIR__ . '/test-backup-task-runner.php';
require_once __DIR__ . '/test-backup-restore-task-runner.php';

class Admission_Response extends RuntimeException {
	public $payload;
	public function __construct( $payload ) {
		parent::__construct( 'Admission response' );
		$this->payload = $payload;
	}
}

class Admission_Response_Helper extends \MainWP\Child\MainWP_Helper {
	public static function instance() {
		return new self();
	}
	public static function write( $value ) {
		throw new Admission_Response( $value );
	}
}

class Admission_Legacy_Backup_Probe extends MainWP_Backup {
	public function backup( $write = true ) {
		return array( 'legacy_engine_called' => true );
	}
}

class BackupRequestAdmissionTest extends WP_UnitTestCase {
	private $dir;
	private $site;
	private $backup;
	private $restore;
	private $spine;

	public function set_up() {
		parent::set_up();
		$this->dir = sys_get_temp_dir() . '/mainwp-admission-' . uniqid();
		$this->site = $this->dir . '/site';
		mkdir( $this->site . '/wp-content/uploads', 0755, true );
		$this->backup = new Testable_Task_Runner();
		$this->restore = new Testable_Restore_Task_Runner();
		foreach ( array( $this->backup, $this->restore ) as $runner ) {
			$runner->test_backup_dir = $this->dir;
			$runner->test_site_root = $this->site;
			$runner->test_content_roots = array( $this->site . '/wp-content', $this->site . '/wp-content/uploads' );
		}
		$this->restore->fake_engine = new Fake_Restore_Engine();
		$this->spine = MainWP_Child_Backup_Task::instance();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		try {
			delete_option( MainWP_Child_Backup_Task::DB_VERSION_OPTION );
			$this->spine->install();
		} finally {
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		}
		delete_option( MainWP_Child_Backup_Mutex::OPTION_NAME );
	}

	public function tear_down() {
		delete_option( MainWP_Child_Backup_Mutex::OPTION_NAME );
		$this->remove_tree( $this->dir );
		parent::tear_down();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		delete_option( MainWP_Child_Backup_Task::DB_VERSION_OPTION );
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $this->spine->get_table_name() );
	}

	private function remove_tree( $dir ) {
		foreach ( scandir( $dir ) as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$path = $dir . '/' . $name;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				$this->remove_tree( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}

	private function artifact( $file = 'part.zip' ) {
		return array( 'role' => 'part', 'file' => $file, 'size' => 1, 'sha256' => hash( 'sha256', 'x' ) );
	}

	private function params() {
		return array( 'restore_id' => 'admit123', 'source_prefix' => 'src_', 'artifacts' => array( $this->artifact() ) );
	}

	private function snapshot() {
		global $wpdb;
		return array(
			$wpdb->get_results( 'SELECT * FROM ' . $this->spine->get_table_name(), ARRAY_A ),
			get_option( MainWP_Child_Backup_Mutex::OPTION_NAME ),
			glob( $this->dir . '/*' ),
		);
	}

	private function assert_refused( $call ) {
		try {
			$call();
			$this->fail( 'An explicit unsupported format must be refused.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( MainWP_Backup_Error::ERR_UNSUPPORTED_BACKUP_FORMAT, $e->get_message_error_code() );
		}
	}

	/** @dataProvider unsupported_formats */
	public function test_direct_backup_create_refuses_before_side_effects( $format ) {
		$before = $this->snapshot();
		$this->assert_refused( function () use ( $format ) {
			$this->backup->create_task_for_params( 'db', array( 'backup_format' => $format ) );
		} );
		$this->assertSame( $before, $this->snapshot() );
	}

	public static function unsupported_formats() {
		return array( 'v2' => array( 2 ), 'wire v2' => array( '2' ), 'unknown' => array( 3 ), 'garbage' => array( 'garbage' ), 'null' => array( null ) );
	}

	/** @dataProvider restore_markers */
	public function test_direct_restore_create_refuses_before_side_effects( $options, $artifacts ) {
		$params = array_merge( $this->params(), $options, array( 'artifacts' => $artifacts ) );
		$before = $this->snapshot();
		$this->assert_refused( function () use ( $params ) { $this->restore->create_restore_task( 'files', $params ); } );
		$this->assertSame( $before, $this->snapshot() );
		$this->assertSame( array(), $this->restore->fake_engine->calls );
	}

	public static function restore_markers() {
		$artifact = array( 'role' => 'part', 'file' => 'part.zip', 'size' => 1, 'sha256' => hash( 'sha256', 'x' ) );
		return array(
			'options v2' => array( array( 'backup_format' => 2 ), array( $artifact ) ),
			'options unknown' => array( array( 'backup_format' => 'future' ), array( $artifact ) ),
			'artifact v2' => array( array(), array( $artifact + array( 'backup_format' => 2 ) ) ),
			'artifact unknown' => array( array(), array( $artifact + array( 'backup_format' => 4 ) ) ),
			'mixed artifacts' => array( array( 'backup_format' => 1 ), array( $artifact + array( 'backup_format' => 1 ), array_merge( $artifact, array( 'file' => 'other.zip', 'backup_format' => 2 ) ) ) ),
		);
	}

	public function test_replay_with_different_format_keeps_original_task() {
		$params = $this->params();
		$id = $this->restore->create_restore_task( 'files', $params );
		$before = $this->snapshot();
		$params['backup_format'] = 2;
		$this->assert_refused( function () use ( $params ) { $this->restore->create_restore_task( 'files', $params ); } );
		$this->assertSame( $before, $this->snapshot() );
		$this->assertSame( $id, $this->spine->get_task_by_restore_id( 'admit123' )->task_id );
	}

	public function test_callable_backup_refuses_before_readiness() {
		$before = $this->snapshot();
		$response = $this->callable( 'backup_task_create', array( 'type' => 'db', 'backup_format' => '2' ) );
		$this->assertSame( MainWP_Backup_Error::ERR_UNSUPPORTED_BACKUP_FORMAT, $response['error_code'] ?? null );
		$this->assertSame( array( 'error', 'error_code' ), array_keys( $response ) );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_legacy_backup_callable_cannot_downgrade_an_explicit_v2_request() {
		$before = $this->snapshot();
		$response = $this->callable( 'backup', array( 'type' => 'db', 'backup_format' => '2' ) );
		$this->assertSame( MainWP_Backup_Error::ERR_UNSUPPORTED_BACKUP_FORMAT, $response['error_code'] ?? null );
		$this->assertSame( $before, $this->snapshot() );
	}

	/** @dataProvider restore_markers */
	public function test_callable_restore_checks_raw_markers( $options, $artifacts ) {
		$before = $this->snapshot();
		$response = $this->callable( 'restore_task_create', array(
			'restore_id' => 'admit123',
			'options_json' => wp_json_encode( $options + array( 'sub_type' => 'files' ) ),
			'artifacts_json' => wp_json_encode( $artifacts ),
		) );
		$this->assertSame( MainWP_Backup_Error::ERR_UNSUPPORTED_BACKUP_FORMAT, $response['error_code'] ?? null );
		$this->assertSame( $before, $this->snapshot() );
	}

	/** @dataProvider backup_layout_markers */
	public function test_direct_backup_layout_marker_alone_refuses_without_side_effects( $marker ) {
		$before = $this->snapshot();
		$this->assert_refused( function () use ( $marker ) {
			$this->backup->create_task_for_params( 'db', $marker );
		} );
		$this->assertSame( $before, $this->snapshot() );
	}

	/** @dataProvider callable_backup_layout_markers */
	public function test_callable_backup_layout_marker_alone_refuses_without_side_effects( $method, $marker ) {
		$before = $this->snapshot();
		$response = $this->callable( $method, $marker + array( 'type' => 'db' ) );
		$this->assertSame( MainWP_Backup_Error::ERR_UNSUPPORTED_BACKUP_FORMAT, $response['error_code'] ?? null );
		$this->assertSame( array( 'error', 'error_code' ), array_keys( $response ) );
		$this->assertSame( $before, $this->snapshot() );
	}

	public static function callable_backup_layout_markers() {
		$cases = array();
		foreach ( array( 'backup_task_create', 'backup' ) as $method ) {
			foreach ( self::backup_layout_markers() as $name => $case ) {
				$cases[ $method . ' ' . $name ] = array( $method, $case[0] );
			}
		}
		return $cases;
	}

	public static function backup_layout_markers() {
		return self::restore_layout_markers();
	}

	/** @dataProvider restore_layout_markers */
	public function test_direct_restore_negotiation_marker_alone_refuses_without_side_effects( $marker ) {
		$params = array_merge( $this->params(), $marker );
		$before = $this->snapshot();
		$this->assert_refused( function () use ( $params ) { $this->restore->create_restore_task( 'files', $params ); } );
		$this->assertSame( $before, $this->snapshot() );
		$this->assertSame( array(), $this->restore->fake_engine->calls );
	}

	/** @dataProvider restore_layout_markers */
	public function test_callable_restore_negotiation_marker_alone_refuses_without_side_effects( $marker ) {
		$before = $this->snapshot();
		$response = $this->callable( 'restore_task_create', array( 'restore_id' => 'admit123', 'options_json' => wp_json_encode( $marker + array( 'sub_type' => 'files' ) ), 'artifacts_json' => wp_json_encode( array( $this->artifact() ) ) ) );
		$this->assertSame( MainWP_Backup_Error::ERR_UNSUPPORTED_BACKUP_FORMAT, $response['error_code'] ?? null );
		$this->assertSame( array( 'error', 'error_code' ), array_keys( $response ) );
		$this->assertSame( $before, $this->snapshot() );
		$this->assertSame( array(), $this->restore->fake_engine->calls );
	}

	public static function restore_layout_markers() {
		return array(
			'layout id' => array( array( 'expected_layout_id' => str_repeat( 'a', 64 ) ) ),
			'descriptor' => array( array( 'layout_descriptor' => array( 'format' => 2 ) ) ),
			'null layout id' => array( array( 'expected_layout_id' => null ) ),
			'null descriptor' => array( array( 'layout_descriptor' => null ) ),
		);
	}

	/** @dataProvider supported_formats */
	public function test_v1_restore_create_and_replay_keep_params( $format ) {
		$params = $this->params();
		if ( null !== $format ) {
			$params['backup_format'] = $format;
			$params['artifacts'][0]['backup_format'] = $format;
		}
		$id = $this->restore->create_restore_task( 'files', $params );
		$task = $this->spine->get_task( $id );
		$this->assertSame( $params, $this->spine->read_sub_state( $task, $this->dir )['params'] );
		$before = $this->snapshot();
		$reused = false;
		$this->assertSame( $id, $this->restore->create_restore_task( 'files', $params, $reused ) );
		$this->assertTrue( $reused );
		$this->assertSame( $before, $this->snapshot() );
	}

	public static function supported_formats() {
		return array( 'absent' => array( null ), 'integer v1' => array( 1 ), 'wire v1' => array( '1' ) );
	}

	/** @dataProvider supported_formats */
	public function test_v1_backup_create_keeps_exact_params( $format ) {
		$params = array( 'ext' => 'zip', 'emit_manifest' => false );
		if ( null !== $format ) {
			$params['backup_format'] = $format;
		}
		$id = $this->backup->create_task_for_params( 'db', $params );
		$state = $this->spine->read_sub_state( $this->spine->get_task( $id ), $this->dir );
		$this->assertSame( wp_json_encode( $params ), wp_json_encode( $state['params'] ) );
	}

	/** @dataProvider supported_formats */
	public function test_v1_callable_backup_response_and_params_keep_exact_bytes( $format ) {
		$post = array( 'type' => 'db' );
		if ( null !== $format ) {
			$post['backup_format'] = $format;
		}
		$response = $this->callable( 'backup_task_create', $post );
		$this->assertSame( wp_json_encode( array( 'task_id' => $response['task_id'], 'phase' => MainWP_Child_Backup_Task::PHASE_QUEUED ) ), wp_json_encode( $response ) );
		$expected = array( 'ext' => 'zip', 'file' => '', 'excludes' => MainWP_Backup::get()->assemble_exclude_list( array(), false, false ), 'excludezip' => false, 'excludenonwp' => false, 'file_descriptors' => 0, 'loadFilesBeforeZip' => true, 'emit_manifest' => false );
		$state = $this->spine->read_sub_state( $this->spine->get_task( $response['task_id'] ), $this->dir );
		$this->assertSame( wp_json_encode( $expected ), wp_json_encode( $state['params'] ) );
	}

	/** @dataProvider supported_formats */
	public function test_v1_legacy_callable_keeps_engine_response( $format ) {
		$post = array( 'type' => 'db' );
		if ( null !== $format ) {
			$post['backup_format'] = $format;
		}
		$this->assertSame( '{"legacy_engine_called":true}', wp_json_encode( $this->callable( 'backup', $post ) ) );
	}

	/** @dataProvider supported_formats */
	public function test_v1_callable_restore_keeps_exact_params_and_response_shape( $format ) {
		$artifact = $this->artifact();
		$options = array( 'sub_type' => 'files' );
		if ( null !== $format ) {
			$options['backup_format'] = $format;
			$artifact['backup_format'] = $format;
		}
		$response = $this->callable( 'restore_task_create', array( 'restore_id' => 'admit123', 'source_prefix' => 'src_', 'options_json' => wp_json_encode( $options ), 'artifacts_json' => wp_json_encode( array( $artifact ) ) ) );
		$this->assertSame( array( 'task_id', 'phase', 'disk_free_bytes' ), array_keys( $response ) );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_QUEUED, $response['phase'] );
		$expected = array( 'restore_id' => 'admit123', 'source_prefix' => 'src_', 'artifacts' => array( $this->artifact() + array( 'adopt_only' => false ) ), 'has_foreign_keys' => null, 'preserve_orders' => false, 'woo_order_since' => 0 );
		$state = $this->spine->read_sub_state( $this->spine->get_task( $response['task_id'] ), $this->dir );
		$this->assertSame( wp_json_encode( $expected ), wp_json_encode( $state['params'] ) );
	}

	/** @dataProvider resume_kinds */
	public function test_resumed_task_never_reinterprets_a_format_marker( $restore, $watchdog ) {
		$params = $restore ? $this->params() : array();
		$params['backup_format'] = 2;
		$id = $this->spine->create_task( $restore ? 'restore_files' : 'db', array( 'params' => $params ) );
		$runner = $restore ? $this->restore : $this->backup;
		$task = $this->spine->get_task( $id );
		if ( ! $restore ) {
			MainWP_Child_Backup_Mutex::acquire( 'native', $id, MainWP_Child_Backup_Mutex::NATIVE_TTL );
		}
		if ( $watchdog ) {
			$this->assertTrue( $this->spine->acquire_lease( $id, 'admission-watchdog', (int) $task->version ) );
			$task = $this->spine->get_task( $id );
			$runner->handle_watchdog_resume( $task, (int) $task->version, 'admission-watchdog' );
			$task = $this->spine->get_task( $id );
		} else {
			$task = $runner->advance_one_slice( $task, 'admission' );
		}
		$this->assertSame( MainWP_Backup_Error::ERR_UNSUPPORTED_BACKUP_FORMAT, $this->spine->status_payload( $task )['error_code'] ?? null );
		$this->assertSame( array(), $this->backup->calls );
		$this->assertSame( array(), array_intersect( array( 'replay_slice', 'begin_maintenance', 'snapshot_tables_slice' ), $this->restore->fake_engine->calls ) );
	}

	public static function resume_kinds() {
		return array( 'backup continuation' => array( false, false ), 'backup watchdog' => array( false, true ), 'restore continuation' => array( true, false ), 'restore watchdog' => array( true, true ) );
	}

	/** @dataProvider artifact_encodings */
	public function test_runner_refuses_container_before_any_target_mutation( $file, $bytes ) {
		$params = $this->params();
		$id = $this->restore->create_restore_task( 'files', $params );
		$scratch = $this->restore->scratch_dir( $id, $this->dir );
		$zip = new ZipArchive();
		$zip->open( $scratch . 'first.zip', ZipArchive::CREATE );
		$zip->addFromString( 'ordinary.txt', 'must not restore' );
		$zip->close();
		file_put_contents( $scratch . $file, $bytes );
		$params['artifacts'] = array( $this->artifact( 'first.zip' ), $this->artifact( $file ) );
		foreach ( $params['artifacts'] as &$artifact ) {
			$artifact['sha256'] = hash_file( 'sha256', $scratch . $artifact['file'] );
		}
		unset( $artifact );
		$state = array( 'params' => $params, 'files_touched' => false );
		$task = $this->spine->get_task( $id );
		$this->spine->checkpoint( $id, (int) $task->version, array( 'phase' => MainWP_Child_Backup_Task::PHASE_RESTORING_FILES, 'sub_state' => $state ) );
		$this->assertSame( array( 'cleanup_tmp_tables', 'prune_aside_tables' ), $this->restore->fake_engine->calls );
		$this->restore->fake_engine->calls = array();
		$task = $this->restore->advance_one_slice( $this->spine->get_task( $id ), 'admission' );
		$this->assertSame( MainWP_Backup_Error::ERR_UNSUPPORTED_BACKUP_FORMAT, $this->spine->status_payload( $task )['error_code'] ?? null );
		$this->assertFileDoesNotExist( $this->site . '/ordinary.txt' );
		$this->assertSame( array(), $this->restore->fake_engine->calls );
		$this->assertDirectoryDoesNotExist( $scratch );
	}

	public static function artifact_encodings() {
		return array(
			'suffix' => array( 'later.mwp2', 'opaque' ),
			'renamed tar magic' => array( 'later.tar', MainWP_Child_Backup_Layout_Codec::MAGIC . 'opaque' ),
			'renamed zip magic' => array( 'later.zip', MainWP_Child_Backup_Layout_Codec::MAGIC . 'opaque' ),
		);
	}

	public function test_preflight_capabilities_keep_exact_bytes_and_no_v2_options() {
		$before = array( get_option( 'backup_layout_v2_support', 'absent' ), get_option( 'restore_layout_v2_support', 'absent' ) );
		$report = MainWP_Backup::get()->check_backup_readiness( true );
		$expected = array( 'task_spine' => 1, 'download_token' => 1, 'incremental' => 1, 'manifest_chunk' => 1, 'manifest_writer_identity' => 1, 'restore_create_idempotency' => 1, 'restore_files' => 1, 'cloud_restore_pull' => 1, 'is_multisite' => 0, 'restore_multisite' => 0, 'reconcile_unarchived_support' => true, 'restore_outcome_report' => true );
		$this->assertSame( wp_json_encode( $expected ), wp_json_encode( array_slice( $report, 10, null, true ) ) );
		foreach ( array( 'backup_layout_v2', 'restore_layout_v2', 'layout_id', 'layout_roles', 'core_policy' ) as $key ) {
			$this->assertArrayNotHasKey( $key, $report );
		}
		$this->assertSame( $before, array( get_option( 'backup_layout_v2_support', 'absent' ), get_option( 'restore_layout_v2_support', 'absent' ) ) );
	}

	private function callable( $method_name, $post ) {
		if ( ! class_exists( 'MainWP\\Child\\Admission_Callable_Fixture', false ) ) {
			$methods = '';
			foreach ( array( 'backup', 'backup_task_create', 'restore_task_create', 'sanitize_restore_artifacts' ) as $name ) {
				$method = new ReflectionMethod( MainWP_Child_Callable::class, $name );
				$lines = file( $method->getFileName() );
				$methods .= str_replace( 'MainWP_Helper', '\\Admission_Response_Helper', implode( '', array_slice( $lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1 ) ) );
			}
			// nosemgrep: AIK_eval-use -- test-only copy of the real callable methods replaces only the exiting response writer.
			eval( 'namespace MainWP\\Child; class Admission_Callable_Fixture extends MainWP_Child_Callable {' . $methods . '}' );
		}
		$previous_post = $_POST;
		$properties = array();
		foreach ( array( array( MainWP_Child_Backup_Task_Runner::class, 'instance', $this->backup ), array( MainWP_Child_Restore_Task_Runner::class, 'restore_instance', $this->restore ) ) as $entry ) {
			$property = new ReflectionProperty( $entry[0], $entry[1] );
			$property->setAccessible( true );
			$properties[] = array( $property, $property->getValue() );
			$property->setValue( null, $entry[2] );
		}
		if ( 'backup' === $method_name ) {
			$property = new ReflectionProperty( MainWP_Backup::class, 'instance' );
			$property->setAccessible( true );
			$properties[] = array( $property, $property->getValue() );
			$property->setValue( null, new Admission_Legacy_Backup_Probe() );
		}
		$_POST = $post;
		try {
			$result = ( new \MainWP\Child\Admission_Callable_Fixture() )->$method_name();
			if ( 'backup' === $method_name ) {
				return $result;
			}
			$this->fail( 'A callable must write a response.' );
		} catch ( Admission_Response $response ) {
			return $response->payload;
		} finally {
			$_POST = $previous_post;
			foreach ( $properties as $entry ) {
				$entry[0]->setValue( null, $entry[1] );
			}
		}
	}
}
