<?php
/**
 * Time Capsule abilities-v2 protocol tests.
 *
 * @package MainWP_Child
 */

namespace MainWP\Child;

use ReflectionClass;
use WP_UnitTestCase;

class Timecapsule_V2_Config {

	/** @var array */
	private $values;

	public function __construct( $values ) {
		$this->values = $values;
	}

	public function get_option( $key ) {
		return array_key_exists( $key, $this->values ) ? $this->values[ $key ] : false;
	}

	public function set_option( $key, $value ) {
		$this->values[ $key ] = $value;
	}
}

class Timecapsule_V2_Factory {

	/** @var Timecapsule_V2_Config */
	public static $config;

	public static function get( $name ) {
		return 'config' === $name ? self::$config : null;
	}
}

// A real Time Capsule install, or another suite's stub, may already own the global name;
// aliasing over it is a fatal. Autoloading stays on: a real WPTC_Factory that is loadable but
// not yet loaded should win the name, rather than being shadowed for the rest of the run by a
// stub that claimed it first.
if ( ! class_exists( 'WPTC_Factory' ) ) {
	class_alias( __NAMESPACE__ . '\\Timecapsule_V2_Factory', 'WPTC_Factory' );
}

class Timecapsule_V2_Protocol_Fixture extends MainWP_Child_Timecapsule {

	/** @var array */
	public $results = array();

	/** @var array */
	public $calls = array();

	/** @var array Receipt store as it stood while the provider ran. */
	public $receipts_during_call = array();

	protected function abilities_v2_provider_call( $operation, $payload ) {
		$this->calls[]              = array( $operation, $payload );
		$this->receipts_during_call = get_option( 'mainwp_timecapsule_abilities_v2_receipts', array() );
		return isset( $this->results[ $operation ] ) ? $this->results[ $operation ] : new \WP_Error( 'provider_unavailable' );
	}

	public function lock_name() {
		return $this->abilities_v2_lock_name();
	}

	public function end_mutation_lock() {
		return $this->abilities_v2_end_mutation_lock();
	}
}

/** Answers a named-lock query from a script, so each acquire or release attempt is observable. */
class Timecapsule_Lock_Wpdb_Stub {

	/** @var array Queries this substitute was asked to run. */
	public $queries = array();

	/** @var string */
	public $last_error = '';

	/** @var array One array( error, result ) per expected attempt. */
	private $answers;

	public function __construct( $answers ) {
		$this->answers = $answers;
	}

	public function prepare( $query, ...$args ) {
		unset( $args );
		return $query;
	}

	public function get_var( $query ) {
		$this->queries[]  = $query;
		$answer           = array_shift( $this->answers );
		$this->last_error = $answer[0];
		return $answer[1];
	}
}

class Timecapsule_V2_Default_Provider_Fixture extends MainWP_Child_Timecapsule {

	/** @var array */
	public $backups = array();

	/** @var string|null IS_FREE_LOCK() observed while the provider effect ran. */
	public $lock_free_during_effect = null;

	protected function get_backups( $last_time = false ) {
		return $this->backups;
	}

	public function start_fresh_backup_tc_callback_wptc() {
		global $wpdb;
		$this->lock_free_during_effect = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', $this->abilities_v2_lock_name() ) );
		Timecapsule_V2_Factory::$config->set_option( 'in_progress', true );
		return array( 'result' => 'success' );
	}

	public function lock_name() {
		return $this->abilities_v2_lock_name();
	}

	public function stop_fresh_backup_tc_callback_wptc() {
		Timecapsule_V2_Factory::$config->set_option( 'in_progress', false );
		return array( 'result' => 'ok' );
	}
}

class Test_MainWP_Child_Timecapsule_V2 extends WP_UnitTestCase {

	/** @var MainWP_Child_Timecapsule */
	private $subject;

	public function set_up(): void {
		parent::set_up();
		if ( ! is_a( 'WPTC_Factory', Timecapsule_V2_Factory::class, true ) ) {
			$this->markTestSkipped( 'WPTC_Factory is already declared by something else, so the deterministic provider stub is not in place.' );
		}
		delete_option( 'mainwp_timecapsule_abilities_v2_receipts' );
		delete_option( 'mainwp_timecapsule_abilities_v2_operations' );
		$reflection    = new ReflectionClass( MainWP_Child_Timecapsule::class );
		$this->subject = $reflection->newInstanceWithoutConstructor();
		$this->subject->is_plugin_installed = true;
		Timecapsule_V2_Factory::$config     = new Timecapsule_V2_Config(
			array(
				'is_user_logged_in'           => true,
				'last_backup_time'            => 1723456789,
				'in_progress'                 => false,
				'schedule_time_str'           => '6:00 am',
				'revision_limit'              => '30',
				'backup_before_update_setting' => 'always',
			)
		);
	}

	public function tear_down(): void {
		global $wpdb;
		delete_option( 'mainwp_timecapsule_abilities_v2_receipts' );
		delete_option( 'mainwp_timecapsule_abilities_v2_operations' );
		// DDL commits outside the harness transaction, so the fixture table has to be dropped explicitly.
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->base_prefix . 'wptc_processed_files' ); // phpcs:ignore WordPress.DB -- Fixture table for a provider-owned schema.
		parent::tear_down();
	}

	/**
	 * Restore and staging mutations have no Child-side adapter, so capabilities must not
	 * advertise them and dispatch must refuse them by name instead of blaming the provider.
	 */
	public function test_capabilities_advertise_exactly_the_operations_dispatch_accepts() {
		$fixture                      = new Timecapsule_V2_Default_Provider_Fixture();
		$fixture->is_plugin_installed = true;
		$fixture->backups             = array( (object) array( 'backupID' => 1723456789 ) );

		$result = $fixture->abilities_v2(
			array(
				'protocol'  => '2',
				'operation' => 'capabilities',
				'payload'   => array(),
			)
		);
		$this->assertSame( array( 'protocol', 'operation', 'ok', 'operations', 'mutation_supported' ), array_keys( $result ) );
		$this->assertTrue( $result['mutation_supported'] );

		$requests = $this->protocol_requests();
		$refused  = array();
		foreach ( $requests as $operation => $request ) {
			$response = $fixture->abilities_v2( $request );
			if ( isset( $response['code'] ) && 'unsupported_operation' === $response['code'] ) {
				$refused[] = $operation;
			}
		}

		$this->assertSame( array( 'restore_backup', 'start_staging', 'delete_staging' ), $refused );
		$this->assertSame( array_values( array_diff( array_keys( $requests ), $refused ) ), $result['operations'] );
	}

	/**
	 * One request per protocol operation, keyed by operation name and ordered so the wired
	 * operations come first.
	 *
	 * @return array Closed protocol requests.
	 */
	private function protocol_requests() {
		$hash      = str_repeat( 'a', 64 );
		$mutations = array( 'replace_policy', 'start_backup', 'cancel_operation', 'restore_backup', 'start_staging', 'delete_staging' );
		$payloads  = array(
			'site'             => array(),
			'policy'           => array(),
			'list_backups'     => array( 'limit' => 50, 'after_backup_ref' => null ),
			'operation_status' => array( 'operation_ref' => $hash ),
			'preview_restore'  => array( 'backup_ref' => $hash, 'backup_generation' => $hash, 'scope' => 'full' ),
			'list_staging'     => array( 'limit' => 20, 'after_clone_ref' => null ),
			'replace_policy'   => array( 'schedule_time' => '7:00 am', 'retention_days' => 45, 'backup_before_update' => false, 'if_match' => $hash ),
			'start_backup'     => array( 'scope' => 'full', 'label' => null, 'policy_generation' => $hash ),
			'cancel_operation' => array( 'operation_ref' => $hash, 'if_match' => $hash ),
			'restore_backup'   => array( 'backup_ref' => $hash, 'backup_generation' => $hash, 'scope' => 'full', 'preview_token' => str_repeat( 'P', 43 ) ),
			'start_staging'    => array( 'label' => 'staging', 'register_in_mainwp' => false, 'settings_generation' => $hash ),
			'delete_staging'   => array( 'clone_ref' => $hash, 'clone_generation' => $hash ),
		);

		$requests = array();
		$index    = 0;
		foreach ( $payloads as $operation => $payload ) {
			++$index;
			$request = array(
				'protocol'  => '2',
				'operation' => $operation,
				'payload'   => $payload,
			);
			if ( in_array( $operation, $mutations, true ) ) {
				$request['request_ref'] = sprintf( '123e4567-e89b-42d3-a456-4266141749%02d', $index );
			}
			$requests[ $operation ] = $request;
		}
		return $requests;
	}

	/**
	 * The reference is validated case-insensitively, so a re-cased retry of the same UUID
	 * must replay the first receipt instead of starting a second backup.
	 */
	public function test_recased_request_ref_replays_the_same_receipt() {
		$fixture                          = new Timecapsule_V2_Protocol_Fixture();
		$fixture->is_plugin_installed     = true;
		$fixture->results['start_backup'] = array(
			'operation_ref' => str_repeat( 'a', 64 ),
			'state'         => 'queued',
			'scope'         => 'full',
		);
		$request = array(
			'protocol'    => '2',
			'operation'   => 'start_backup',
			'request_ref' => '123E4567-E89B-42D3-A456-426614174965',
			'payload'     => array( 'scope' => 'full', 'label' => null, 'policy_generation' => str_repeat( 'b', 64 ) ),
		);

		$first = $fixture->abilities_v2( $request );
		$this->assertTrue( $first['ok'] );
		$this->assertSame( strtolower( $request['request_ref'] ), $first['request_ref'] );

		$request['request_ref'] = strtolower( $request['request_ref'] );
		$this->assertSame( $first, $fixture->abilities_v2( $request ) );
		$this->assertCount( 1, $fixture->calls );
		$this->assertSame( array( '123e4567-e89b-42d3-a456-426614174965' ), array_keys( get_option( 'mainwp_timecapsule_abilities_v2_receipts' ) ) );
	}

	/**
	 * last_backup_time is site-wide: a backup started anywhere else satisfies it. The status
	 * read must not read success out of it, and must not rewrite the stored operation row.
	 */
	public function test_operation_status_neither_infers_success_from_site_wide_time_nor_writes() {
		$fixture                      = new Timecapsule_V2_Default_Provider_Fixture();
		$fixture->is_plugin_installed = true;
		$policy                       = $fixture->abilities_v2( array( 'protocol' => '2', 'operation' => 'policy', 'payload' => array() ) );
		$start                        = $fixture->abilities_v2(
			array(
				'protocol'    => '2',
				'operation'   => 'start_backup',
				'request_ref' => '123e4567-e89b-42d3-a456-426614174966',
				'payload'     => array( 'scope' => 'full', 'label' => null, 'policy_generation' => $policy['policy_generation'] ),
			)
		);
		$this->assertSame( 'running', $start['state'] );

		// Some other backup finishes after ours started, and ours stops being in progress.
		Timecapsule_V2_Factory::$config->set_option( 'in_progress', false );
		Timecapsule_V2_Factory::$config->set_option( 'last_backup_time', time() + 60 );

		$status = $fixture->abilities_v2( array( 'protocol' => '2', 'operation' => 'operation_status', 'payload' => array( 'operation_ref' => $start['operation_ref'] ) ) );
		$this->assertTrue( $status['ok'] );
		$this->assertSame( 'uncertain', $status['state'] );
		$this->assertNull( $status['result_ref'] );
		$this->assertNull( $status['finished_at'] );

		$stored = get_option( 'mainwp_timecapsule_abilities_v2_operations' );
		$this->assertSame( 'running', $stored[ $start['operation_ref'] ]['state'], 'operation_status is a read and must not settle the stored row.' );
	}

	public function test_typed_mutations_require_request_ref_and_replay_the_exact_receipt() {
		$fixture = new Timecapsule_V2_Protocol_Fixture();
		$fixture->is_plugin_installed = true;
		$fixture->results['start_backup'] = array(
			'operation_ref' => str_repeat( 'a', 64 ),
			'state'         => 'queued',
			'scope'         => 'full',
		);
		$request = array(
			'protocol'    => '2',
			'operation'   => 'start_backup',
			'request_ref' => '123e4567-e89b-42d3-a456-426614174961',
			'payload'     => array(
				'scope'             => 'full',
				'label'             => 'Before release',
				'policy_generation' => str_repeat( 'b', 64 ),
			),
		);

		$result = $fixture->abilities_v2( $request );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( $request['request_ref'], $result['request_ref'] );
		$this->assertSame( str_repeat( 'a', 64 ), $result['operation_ref'] );
		$this->assertSame( $result, $fixture->abilities_v2( $request ) );
		$this->assertCount( 1, $fixture->calls );

		$request['payload']['scope'] = 'files';
		$conflict = $fixture->abilities_v2( $request );
		$this->assertFalse( $conflict['ok'] );
		$this->assertSame( 'request_conflict', $conflict['code'] );

		unset( $request['request_ref'] );
		$request['request_id'] = '123e4567-e89b-42d3-a456-426614174962';
		$this->assertSame( 'invalid_request', $fixture->abilities_v2( $request )['code'] );
	}

	public function test_read_status_restore_and_staging_operations_are_closed() {
		$fixture = new Timecapsule_V2_Protocol_Fixture();
		$fixture->is_plugin_installed = true;
		$fixture->results = array(
			'list_backups'    => array( 'backups' => array(), 'snapshot_generation' => str_repeat( 'c', 64 ), 'next_after_backup_ref' => null, 'truncated' => false ),
			'operation_status' => array( 'operation_ref' => str_repeat( 'd', 64 ), 'kind' => 'restore', 'state' => 'running', 'progress_percent' => 50, 'started_at' => '2026-08-16T10:00:00Z', 'finished_at' => null, 'result_ref' => null, 'generation' => str_repeat( 'e', 64 ) ),
			'preview_restore' => array( 'preview_token' => str_repeat( 'P', 43 ), 'expires_at' => '2026-08-16T10:15:00Z', 'backup_ref' => str_repeat( 'f', 64 ), 'scope' => 'full', 'file_count' => 10, 'table_count' => 5, 'overwrite_expected' => true, 'preflight' => 'ready' ),
			'list_staging'    => array( 'clones' => array(), 'snapshot_generation' => str_repeat( '1', 64 ), 'next_after_clone_ref' => null, 'truncated' => false ),
		);

		$cases = array(
			array( 'list_backups', array( 'limit' => 50, 'after_backup_ref' => null ) ),
			array( 'operation_status', array( 'operation_ref' => str_repeat( 'd', 64 ) ) ),
			array( 'preview_restore', array( 'backup_ref' => str_repeat( 'f', 64 ), 'backup_generation' => str_repeat( '2', 64 ), 'scope' => 'full' ) ),
			array( 'list_staging', array( 'limit' => 20, 'after_clone_ref' => null ) ),
		);
		foreach ( $cases as $case ) {
			$result = $fixture->abilities_v2( array( 'protocol' => '2', 'operation' => $case[0], 'payload' => $case[1] ) );
			$this->assertTrue( $result['ok'], $case[0] );
			$this->assertSame( $case[0], $result['operation'] );
		}
	}

	public function test_default_provider_replaces_policy_and_projects_backups_without_raw_ids() {
		$fixture                      = new Timecapsule_V2_Default_Provider_Fixture();
		$fixture->is_plugin_installed = true;
		$fixture->backups             = array( (object) array( 'backupID' => 1723456789 ), (object) array( 'backupID' => 1723456790 ) );
		$policy                       = $this->request( 'policy' );
		$replace                      = $fixture->abilities_v2(
			array(
				'protocol'    => '2',
				'operation'   => 'replace_policy',
				'request_ref' => '123e4567-e89b-42d3-a456-426614174962',
				'payload'     => array( 'schedule_time' => '7:00 am', 'retention_days' => 45, 'backup_before_update' => false, 'if_match' => $policy['policy_generation'] ),
			)
		);
		$this->assertTrue( $replace['ok'] );
		$this->assertSame( '7:00 am', Timecapsule_V2_Factory::$config->get_option( 'schedule_time_str' ) );
		$this->assertSame( '45', Timecapsule_V2_Factory::$config->get_option( 'revision_limit' ) );

		$list = $fixture->abilities_v2( array( 'protocol' => '2', 'operation' => 'list_backups', 'payload' => array( 'limit' => 1, 'after_backup_ref' => null ) ) );
		$this->assertTrue( $list['ok'] );
		$this->assertCount( 1, $list['backups'] );
		$this->assertTrue( $list['truncated'] );
		$this->assertSame( gmdate( 'Y-m-d\TH:i:s\Z', 1723456790 ), $list['backups'][0]['created_at'] );
		$this->assertStringNotContainsString( '1723456790', wp_json_encode( $list ) );
	}

	public function test_default_provider_starts_tracks_and_cancels_one_backup() {
		$fixture                      = new Timecapsule_V2_Default_Provider_Fixture();
		$fixture->is_plugin_installed = true;
		$policy                       = $this->request( 'policy' );
		$start                        = $fixture->abilities_v2(
			array(
				'protocol'    => '2',
				'operation'   => 'start_backup',
				'request_ref' => '123e4567-e89b-42d3-a456-426614174963',
				'payload'     => array( 'scope' => 'full', 'label' => null, 'policy_generation' => $policy['policy_generation'] ),
			)
		);
		$this->assertTrue( $start['ok'] );
		$this->assertSame( 'running', $start['state'] );

		$status = $fixture->abilities_v2( array( 'protocol' => '2', 'operation' => 'operation_status', 'payload' => array( 'operation_ref' => $start['operation_ref'] ) ) );
		$this->assertTrue( $status['ok'] );
		$this->assertSame( 'running', $status['state'] );

		$cancel = $fixture->abilities_v2(
			array(
				'protocol'    => '2',
				'operation'   => 'cancel_operation',
				'request_ref' => '123e4567-e89b-42d3-a456-426614174964',
				'payload'     => array( 'operation_ref' => $start['operation_ref'], 'if_match' => $status['generation'] ),
			)
		);
		$this->assertTrue( $cancel['ok'] );
		$this->assertSame( 'cancelled', $cancel['state'] );
		$this->assertTrue( $cancel['quiescent'] );
	}

	public function test_site_observation_is_redacted_and_generation_bound() {
		$result = $this->request( 'site' );

		$this->assertSame( array( 'protocol', 'operation', 'ok', 'complete', 'plugin_state', 'account_state', 'last_attempt_at', 'last_verified_at', 'active_operation_count', 'observed_at', 'generation' ), array_keys( $result ) );
		$this->assertSame( 'ready', $result['plugin_state'] );
		$this->assertSame( 'connected', $result['account_state'] );
		$this->assertSame( gmdate( 'Y-m-d\TH:i:s\Z', 1723456789 ), $result['last_attempt_at'] );
		$this->assertNull( $result['last_verified_at'] );
		$this->assertSame( 0, $result['active_operation_count'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/D', $result['generation'] );
		$this->assertStringNotContainsString( 'email', wp_json_encode( $result ) );
		$this->assertStringNotContainsString( 'token', wp_json_encode( $result ) );
	}

	public function test_policy_is_bounded_and_contains_no_secret_fields() {
		$result = $this->request( 'policy' );

		$this->assertSame( array( 'protocol', 'operation', 'ok', 'complete', 'schedule_time', 'retention_days', 'backup_before_update', 'policy_generation' ), array_keys( $result ) );
		$this->assertSame( '6:00 am', $result['schedule_time'] );
		$this->assertSame( 30, $result['retention_days'] );
		$this->assertTrue( $result['backup_before_update'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/D', $result['policy_generation'] );
		$this->assertStringNotContainsString( 'encrypt', wp_json_encode( $result ) );
	}

	public function test_malformed_and_unusable_provider_values_fail_closed() {
		$this->assertFalse( $this->subject->abilities_v2( array() )['ok'] );
		$this->assertFalse(
			$this->subject->abilities_v2(
				array(
					'protocol'  => '2',
					'operation' => 'site',
					'payload'   => array( 'extra' => true ),
				)
			)['ok']
		);

		Timecapsule_V2_Factory::$config = new Timecapsule_V2_Config(
			array(
				'is_user_logged_in'           => 'yes',
				'schedule_time_str'           => '6:30 am',
				'revision_limit'              => 1000,
				'backup_before_update_setting' => array(),
			)
		);
		$this->assertFalse( $this->request( 'site' )['ok'] );
		$this->assertFalse( $this->request( 'policy' )['ok'] );
	}

	/**
	 * wptc_processed_files holds one row per processed file, so a single real backup is tens of
	 * thousands of rows. The v2 reads must see backups, not files, and must not trip the bound.
	 */
	public function test_get_backups_projects_distinct_backups_from_processed_file_rows() {
		global $wpdb;

		$table = $wpdb->base_prefix . 'wptc_processed_files';
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB -- Fixture table for a provider-owned schema.
		$wpdb->query( "CREATE TABLE {$table} ( id BIGINT NOT NULL AUTO_INCREMENT, backupID BIGINT NOT NULL, file_path VARCHAR(190) NOT NULL, PRIMARY KEY (id) )" ); // phpcs:ignore WordPress.DB -- Fixture table for a provider-owned schema.

		$backup_ids = array( 1723456789, 1723543189 );
		foreach ( $backup_ids as $backup_id ) {
			for ( $chunk = 0; $chunk < 3; $chunk++ ) {
				$values = array();
				for ( $index = 0; $index < 2000; $index++ ) {
					$values[] = $wpdb->prepare( '(%d, %s)', $backup_id, 'wp-content/file-' . $chunk . '-' . $index . '.php' );
				}
				$wpdb->query( "INSERT INTO {$table} (backupID, file_path) VALUES " . implode( ',', $values ) ); // phpcs:ignore WordPress.DB -- Fixture rows for a provider-owned schema.
			}
		}
		$this->assertSame( '12000', $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) ); // phpcs:ignore WordPress.DB -- Fixture table for a provider-owned schema.

		$method = new \ReflectionMethod( MainWP_Child_Timecapsule::class, 'get_backups' );
		$method->setAccessible( true );
		$rows = $method->invoke( $this->subject, 1 );
		$this->assertCount( 2, $rows, 'get_backups() must return one row per backup, not per processed file.' );
		$this->assertSame( $backup_ids, array_map( 'intval', wp_list_pluck( $rows, 'backupID' ) ) );

		$list = $this->subject->abilities_v2(
			array(
				'protocol'  => '2',
				'operation' => 'list_backups',
				'payload'   => array( 'limit' => 50, 'after_backup_ref' => null ),
			)
		);
		$this->assertTrue( $list['ok'], 'A site with a realistic processed-file table must not report an invalid schema.' );
		$this->assertCount( 2, $list['backups'] );
		$this->assertFalse( $list['truncated'] );

		$preview = $this->subject->abilities_v2(
			array(
				'protocol'  => '2',
				'operation' => 'preview_restore',
				'payload'   => array(
					'backup_ref'        => $list['backups'][0]['backup_ref'],
					'backup_generation' => $list['backups'][0]['generation'],
					'scope'             => 'full',
				),
			)
		);
		$this->assertTrue( $preview['ok'] );
	}

	/**
	 * Restore has no Child-side adapter, so the preview cannot mint a redeemable token, and Time
	 * Capsule publishes no per-backup table list. Counts it cannot compute must read as unknown,
	 * and the one it can compute must come from the provider's own rows.
	 */
	public function test_preview_restore_reports_real_counts_and_mints_no_unredeemable_token() {
		global $wpdb;

		$table = $wpdb->base_prefix . 'wptc_processed_files';
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB -- Fixture table for a provider-owned schema.
		$wpdb->query( "CREATE TABLE {$table} ( id BIGINT NOT NULL AUTO_INCREMENT, backupID BIGINT NOT NULL, file_path VARCHAR(190) NOT NULL, PRIMARY KEY (id) )" ); // phpcs:ignore WordPress.DB -- Fixture table for a provider-owned schema.
		foreach ( array( 1723456789 => 2, 1723543189 => 3 ) as $backup_id => $files ) {
			for ( $index = 0; $index < $files; $index++ ) {
				$wpdb->insert( $table, array( 'backupID' => $backup_id, 'file_path' => 'wp-content/file-' . $index . '.php' ) ); // phpcs:ignore WordPress.DB -- Fixture rows for a provider-owned schema.
			}
		}

		$list = $this->subject->abilities_v2(
			array(
				'protocol'  => '2',
				'operation' => 'list_backups',
				'payload'   => array( 'limit' => 50, 'after_backup_ref' => null ),
			)
		);
		$this->assertTrue( $list['ok'] );
		$this->assertSame( gmdate( 'Y-m-d\TH:i:s\Z', 1723543189 ), $list['backups'][0]['created_at'] );

		$preview = $this->subject->abilities_v2(
			array(
				'protocol'  => '2',
				'operation' => 'preview_restore',
				'payload'   => array(
					'backup_ref'        => $list['backups'][0]['backup_ref'],
					'backup_generation' => $list['backups'][0]['generation'],
					'scope'             => 'full',
				),
			)
		);
		$this->assertTrue( $preview['ok'] );
		$this->assertSame( 3, $preview['file_count'] );
		$this->assertNull( $preview['table_count'] );
		$this->assertNull( $preview['preview_token'] );
		$this->assertNull( $preview['expires_at'] );
		$this->assertSame( 'blocked', $preview['preflight'] );
	}

	/**
	 * Two concurrent starts would both read in_progress=false and both start a backup, and the
	 * second read-modify-write of the operations option would drop the first row. The provider
	 * effect and the row write have to happen while this Child holds the named lock.
	 */
	public function test_start_backup_runs_the_provider_effect_under_the_named_lock() {
		$fixture                      = new Timecapsule_V2_Default_Provider_Fixture();
		$fixture->is_plugin_installed = true;
		$policy                       = $this->request( 'policy' );

		$start = $fixture->abilities_v2( $this->start_backup_request( $policy['policy_generation'] ) );

		$this->assertTrue( $start['ok'] );
		$this->assertSame( '0', (string) $fixture->lock_free_during_effect, 'The provider effect must run while the lock is held.' );
		$this->assertSame( '1', (string) $this->lock_state( $fixture->lock_name() ), 'The lock must be released on the success path.' );
	}

	/**
	 * A start that cannot take the lock has to refuse honestly, without touching the provider,
	 * the operations store or the receipt store. Reads stay available.
	 */
	public function test_a_held_lock_refuses_the_start_without_starting_a_backup_or_storing() {
		$fixture                      = new Timecapsule_V2_Default_Provider_Fixture();
		$fixture->is_plugin_installed = true;
		$policy                       = $this->request( 'policy' );
		$holder                       = $this->hold_lock_elsewhere( $fixture->lock_name() );

		$result = $fixture->abilities_v2( $this->start_backup_request( $policy['policy_generation'] ) );
		$read   = $fixture->abilities_v2( array( 'protocol' => '2', 'operation' => 'policy', 'payload' => array() ) );
		$this->release_lock_elsewhere( $holder, $fixture->lock_name() );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'lock_busy', $result['code'] );
		$this->assertNull( $fixture->lock_free_during_effect, 'The provider effect must not run when the lock is held elsewhere.' );
		$this->assertFalse( Timecapsule_V2_Factory::$config->get_option( 'in_progress' ) );
		$this->assertSame( array(), get_option( 'mainwp_timecapsule_abilities_v2_operations', array() ) );
		$this->assertSame( array(), get_option( 'mainwp_timecapsule_abilities_v2_receipts', array() ) );
		$this->assertTrue( $read['ok'] );
	}

	/**
	 * A full receipt store refuses rather than forgetting an outcome a retry still needs, and it
	 * comes back on its own once the oldest entry ages past the retry horizon.
	 */
	public function test_a_full_receipt_store_refuses_before_the_provider_and_self_clears_past_the_horizon() {
		$fixture                          = new Timecapsule_V2_Protocol_Fixture();
		$fixture->is_plugin_installed     = true;
		$fixture->results['start_backup'] = array(
			'operation_ref' => str_repeat( 'a', 64 ),
			'state'         => 'queued',
			'scope'         => 'full',
		);
		$oldest = '123e4567-e89b-42d3-a456-4266141749c0';
		update_option( 'mainwp_timecapsule_abilities_v2_receipts', $this->fill_receipts( 100, time(), $oldest ), false );

		$refused = $fixture->abilities_v2( $this->receipt_backup_request( '123e4567-e89b-42d3-a456-426614174971' ) );

		$this->assertFalse( $refused['ok'] );
		$this->assertSame( 'storage_unavailable', $refused['code'] );
		$this->assertSame( array(), $fixture->calls, 'the provider must not run when the outcome cannot be recorded' );
		$this->assertCount( 100, get_option( 'mainwp_timecapsule_abilities_v2_receipts', array() ) );

		// Only the clock changes: the oldest receipt is now past the horizon and nothing else is.
		$receipts                          = get_option( 'mainwp_timecapsule_abilities_v2_receipts', array() );
		$receipts[ $oldest ]['created_at'] = time() - ( DAY_IN_SECONDS + 3600 );
		update_option( 'mainwp_timecapsule_abilities_v2_receipts', $receipts, false );

		$accepted = $fixture->abilities_v2( $this->receipt_backup_request( '123e4567-e89b-42d3-a456-426614174972' ) );
		$stored   = get_option( 'mainwp_timecapsule_abilities_v2_receipts', array() );

		$this->assertTrue( $accepted['ok'] );
		$this->assertCount( 1, $fixture->calls );
		$this->assertCount( 100, $stored );
		$this->assertArrayNotHasKey( $oldest, $stored, 'the aged receipt is the one given up' );
		$this->assertArrayHasKey( '123e4567-e89b-42d3-a456-426614174972', $stored );
	}

	/**
	 * The record a retry lands on exists before the backup starts, not after it returns.
	 *
	 * Between the provider being called and the outcome being written there is a window in which
	 * the request can die outright. Without a reservation in the store the Dashboard's retry of
	 * the same reference finds nothing and starts a second backup.
	 */
	public function test_a_reservation_is_durable_before_the_provider_runs() {
		$fixture = $this->backup_fixture();
		$request = $this->receipt_backup_request( '123e4567-e89b-42d3-a456-426614174973' );

		$result = $fixture->abilities_v2( $request );

		$this->assertTrue( $result['ok'] );
		$this->assertArrayHasKey( $request['request_ref'], $fixture->receipts_during_call, 'the backup must not start before its reference is recorded' );
		$reserved = $fixture->receipts_during_call[ $request['request_ref'] ];
		$this->assertSame( 'dispatching', $reserved['state'] );
		$this->assertNull( $reserved['response'] );
		$this->assertSame( hash( 'sha256', wp_json_encode( array( 'start_backup', $request['payload'] ) ) ), $reserved['effect_hash'] );

		$stored = get_option( 'mainwp_timecapsule_abilities_v2_receipts', array() );
		$this->assertSame( 'settled', $stored[ $request['request_ref'] ]['state'] );
		$this->assertSame( $result, $stored[ $request['request_ref'] ]['response'] );
	}

	/** A reservation nobody settled answers outcome_unknown and starts no second backup. */
	public function test_an_unsettled_reservation_answers_outcome_unknown_without_dispatching() {
		$fixture = $this->backup_fixture();
		$request = $this->receipt_backup_request( '123e4567-e89b-42d3-a456-426614174974' );
		update_option( 'mainwp_timecapsule_abilities_v2_receipts', array( $request['request_ref'] => $this->reservation( $request, time() ) ), false );

		$result = $fixture->abilities_v2( $request );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'outcome_unknown', $result['code'] );
		$this->assertSame( array(), $fixture->calls, 'a backup that may still be running must not be started again' );
	}

	/**
	 * A stamp this clock cannot date holds its reservation open instead of releasing it.
	 *
	 * A host clock that stepped backwards would otherwise retire every reservation in the store
	 * at once, and each retired reference starts its backup a second time.
	 */
	public function test_an_undatable_reservation_is_not_retired_into_a_second_backup() {
		$fixture = $this->backup_fixture();
		$request = $this->receipt_backup_request( '123e4567-e89b-42d3-a456-426614174975' );
		update_option( 'mainwp_timecapsule_abilities_v2_receipts', array( $request['request_ref'] => $this->reservation( $request, time() + ( 2 * DAY_IN_SECONDS ) ) ), false );

		$result = $fixture->abilities_v2( $request );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'outcome_unknown', $result['code'] );
		$this->assertSame( array(), $fixture->calls );
	}

	/** Past the retry horizon a reservation proves nothing, so the reference dispatches again. */
	public function test_a_reservation_past_the_retry_horizon_is_retired_and_dispatches() {
		$fixture = $this->backup_fixture();
		$request = $this->receipt_backup_request( '123e4567-e89b-42d3-a456-426614174976' );
		update_option( 'mainwp_timecapsule_abilities_v2_receipts', array( $request['request_ref'] => $this->reservation( $request, time() - ( DAY_IN_SECONDS + 3600 ) ) ), false );

		$result = $fixture->abilities_v2( $request );
		$stored = get_option( 'mainwp_timecapsule_abilities_v2_receipts', array() );

		$this->assertTrue( $result['ok'] );
		$this->assertCount( 1, $fixture->calls );
		$this->assertSame( 'settled', $stored[ $request['request_ref'] ]['state'] );
	}

	/** A provider refusal is recorded, so the same reference replays the refusal instead of re-running. */
	public function test_a_refused_backup_settles_and_replays_rather_than_running_twice() {
		$fixture                      = new Timecapsule_V2_Protocol_Fixture();
		$fixture->is_plugin_installed = true;
		$request                      = $this->receipt_backup_request( '123e4567-e89b-42d3-a456-426614174977' );

		$refused = $fixture->abilities_v2( $request );
		$replay  = $fixture->abilities_v2( $request );

		$this->assertFalse( $refused['ok'] );
		$this->assertSame( 'provider_unavailable', $refused['code'] );
		$this->assertSame( $refused, $replay );
		$this->assertCount( 1, $fixture->calls, 'a refusal already recorded must not reach the provider again' );
	}

	/**
	 * An unreadable entry is not spare room for somebody else's backup.
	 *
	 * It is still evidence that a receipt was written for that reference, so dropping it lets the
	 * reference run a second time on its next retry. It becomes a dated tombstone instead: the
	 * stamp is written once, survives the request that wrote it, and is what lets the entry age
	 * out later instead of holding the store shut.
	 */
	public function test_an_unreadable_entry_becomes_a_dated_tombstone_rather_than_someone_elses_room() {
		$fixture  = $this->backup_fixture();
		$receipts = $this->fill_receipts( 99, time() );
		// The three-key shape an earlier build of this branch wrote, with no state to read.
		$unreadable              = '123e4567-e89b-42d3-a456-4266141749d0';
		$receipts[ $unreadable ] = array(
			'effect_hash' => str_repeat( 'e', 64 ),
			'response'    => array( 'ok' => true ),
		);
		update_option( 'mainwp_timecapsule_abilities_v2_receipts', $receipts, false );

		$refused = $fixture->abilities_v2( $this->receipt_backup_request( '123e4567-e89b-42d3-a456-426614174978' ) );
		$stored  = get_option( 'mainwp_timecapsule_abilities_v2_receipts', array() );

		$this->assertFalse( $refused['ok'] );
		$this->assertSame( 'storage_unavailable', $refused['code'] );
		$this->assertSame( array(), $fixture->calls, 'no backup may run behind a refusal' );
		$this->assertArrayHasKey( $unreadable, $stored, 'the damaged entry is still evidence that reference already ran' );
		$this->assertSame( 'unreadable', $stored[ $unreadable ]['state'] );
		$this->assertNull( $stored[ $unreadable ]['response'] );
		$this->assertIsInt( $stored[ $unreadable ]['created_at'], 'the repair has to be persisted or it is re-stamped on every request' );
		foreach ( array_keys( $this->fill_receipts( 99, time() ) ) as $live ) {
			$this->assertArrayHasKey( $live, $stored );
		}

		// The reference behind the tombstone still refuses rather than backing up again.
		$this->assertSame( 'storage_unavailable', $fixture->abilities_v2( $this->receipt_backup_request( $unreadable ) )['code'] );
		$this->assertSame( array(), $fixture->calls );

		// Only the clock changes: a tombstone past the horizon is given up like any other entry,
		// which is only possible because its stamp was frozen rather than renewed on every read.
		$stored[ $unreadable ]['created_at'] = time() - ( DAY_IN_SECONDS + 3600 );
		update_option( 'mainwp_timecapsule_abilities_v2_receipts', $stored, false );

		$accepted = $fixture->abilities_v2( $this->receipt_backup_request( '123e4567-e89b-42d3-a456-426614174979' ) );
		$after    = get_option( 'mainwp_timecapsule_abilities_v2_receipts', array() );

		$this->assertTrue( $accepted['ok'] );
		$this->assertArrayNotHasKey( $unreadable, $after, 'the aged tombstone is the entry given up' );
		$this->assertArrayHasKey( '123e4567-e89b-42d3-a456-426614174979', $after );
	}

	/**
	 * A tombstone this clock cannot date is re-dated, so the store still ages out.
	 *
	 * Keeping the stamp would leave an entry no clock ever reaches, and one of those in a full
	 * store refuses every mutation from then on with nothing an operator could do. Re-dating it
	 * costs nothing: the reference behind it still refuses, and now the entry can grow old.
	 */
	public function test_a_tombstone_this_clock_cannot_date_is_redated_rather_than_held_forever() {
		$fixture                 = $this->backup_fixture();
		$receipts                = $this->fill_receipts( 99, time() );
		$tombstoned              = '123e4567-e89b-42d3-a456-4266141749e0';
		$receipts[ $tombstoned ] = array(
			'effect_hash' => str_repeat( '0', 64 ),
			'state'       => 'unreadable',
			'response'    => null,
			'created_at'  => PHP_INT_MAX,
		);
		update_option( 'mainwp_timecapsule_abilities_v2_receipts', $receipts, false );

		$refused = $fixture->abilities_v2( $this->receipt_backup_request( '123e4567-e89b-42d3-a456-42661417497b' ) );
		$stored  = get_option( 'mainwp_timecapsule_abilities_v2_receipts', array() );

		$this->assertFalse( $refused['ok'] );
		$this->assertSame( 'storage_unavailable', $refused['code'] );
		$this->assertLessThanOrEqual( time(), $stored[ $tombstoned ]['created_at'], 'a stamp no clock reaches has to be replaced with one that ages' );

		// Proof that it now ages out: only the clock changes and the entry becomes the one given up.
		$stored[ $tombstoned ]['created_at'] = time() - ( DAY_IN_SECONDS + 3600 );
		update_option( 'mainwp_timecapsule_abilities_v2_receipts', $stored, false );

		$this->assertTrue( $fixture->abilities_v2( $this->receipt_backup_request( '123e4567-e89b-42d3-a456-42661417497e' ) )['ok'] );
		$this->assertArrayNotHasKey( $tombstoned, get_option( 'mainwp_timecapsule_abilities_v2_receipts', array() ) );
	}

	/**
	 * A stamp the tombstone builder rejects must not be taken as a sound tombstone.
	 *
	 * A nonsense stamp sorts to the front of the disposable list, so an entry that is still the
	 * only evidence its reference already ran gets given up to an unrelated request.
	 */
	public function test_a_tombstone_with_an_impossible_stamp_is_rebuilt_rather_than_evicted() {
		$fixture                 = $this->backup_fixture();
		$receipts                = $this->fill_receipts( 99, time() );
		$tombstoned              = '123e4567-e89b-42d3-a456-4266141749e1';
		$receipts[ $tombstoned ] = array(
			'effect_hash' => str_repeat( '0', 64 ),
			'state'       => 'unreadable',
			'response'    => null,
			'created_at'  => -1,
		);
		update_option( 'mainwp_timecapsule_abilities_v2_receipts', $receipts, false );

		$refused = $fixture->abilities_v2( $this->receipt_backup_request( '123e4567-e89b-42d3-a456-42661417497f' ) );
		$stored  = get_option( 'mainwp_timecapsule_abilities_v2_receipts', array() );

		$this->assertFalse( $refused['ok'] );
		$this->assertSame( array(), $fixture->calls );
		$this->assertArrayHasKey( $tombstoned, $stored, 'an impossible stamp is not proof the entry is past the horizon' );
		$this->assertGreaterThan( 0, $stored[ $tombstoned ]['created_at'] );
	}

	/**
	 * A reference whose stored entry is null still refuses rather than backing up again.
	 *
	 * isset() reads a key holding null as absent, and the key existing at all is the evidence
	 * that something wrote a receipt for that reference.
	 */
	public function test_a_null_entry_under_a_reference_refuses_rather_than_dispatching() {
		$fixture = $this->backup_fixture();
		$request = $this->receipt_backup_request( '123e4567-e89b-42d3-a456-426614174980' );
		update_option( 'mainwp_timecapsule_abilities_v2_receipts', array( $request['request_ref'] => null ), false );

		$result = $fixture->abilities_v2( $request );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'storage_unavailable', $result['code'] );
		$this->assertSame( array(), $fixture->calls, 'a reference with any stored entry must not reach the provider again' );
	}

	/** A hash the store cannot read is a corrupt store, not a different request. */
	public function test_a_receipt_whose_effect_hash_is_not_a_hash_is_unreadable_rather_than_a_conflict() {
		$fixture = $this->backup_fixture();
		$request = $this->receipt_backup_request( '123e4567-e89b-42d3-a456-42661417497a' );
		update_option(
			'mainwp_timecapsule_abilities_v2_receipts',
			array(
				$request['request_ref'] => array(
					'effect_hash' => 'not-a-sha256',
					'state'       => 'settled',
					'response'    => array( 'protocol' => '2', 'operation' => 'start_backup', 'ok' => true ),
					'created_at'  => time(),
				),
			),
			false
		);

		$result = $fixture->abilities_v2( $request );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'storage_unavailable', $result['code'] );
		$this->assertSame( array(), $fixture->calls );
	}

	/**
	 * A lock backend that cannot answer is refused as a store failure, not as someone else's lock.
	 *
	 * GET_LOCK() answers NULL on a driver error or an interrupted wait, which says nothing about
	 * who holds the lock. Reporting that as lock_busy tells the Dashboard to wait for a holder
	 * this Child never observed.
	 */
	public function test_an_unusable_lock_backend_is_refused_apart_from_a_held_lock() {
		$fixture = $this->backup_fixture();
		// The lock name reads home_url(), so it is resolved while the real connection is still in place.
		$name = $fixture->lock_name();

		$backends = array(
			'driver error'     => array( array( 'MySQL server has gone away', null ) ),
			'GET_LOCK is NULL' => array( array( '', null ) ),
		);
		foreach ( $backends as $label => $answers ) {
			$real            = $GLOBALS['wpdb'];
			$GLOBALS['wpdb'] = new Timecapsule_Lock_Wpdb_Stub( $answers );
			try {
				$result = $fixture->abilities_v2( $this->receipt_backup_request( '123e4567-e89b-42d3-a456-42661417497c' ) );
			} finally {
				$GLOBALS['wpdb'] = $real;
			}

			$this->assertFalse( $result['ok'], $label );
			$this->assertSame( 'storage_unavailable', $result['code'], $label );
		}

		$holder = $this->hold_lock_elsewhere( $name );
		$held   = $fixture->abilities_v2( $this->receipt_backup_request( '123e4567-e89b-42d3-a456-42661417497d' ) );
		$this->release_lock_elsewhere( $holder, $name );

		$this->assertFalse( $held['ok'] );
		$this->assertSame( 'lock_busy', $held['code'] );
		$this->assertSame( array(), $fixture->calls );
		$this->assertSame( array(), get_option( 'mainwp_timecapsule_abilities_v2_receipts', array() ) );
	}

	/**
	 * A lock this session fails to give back blocks every later mutation with no operator escape,
	 * so one failed release earns a second attempt, and an absent lock counts as released.
	 */
	public function test_release_reads_an_absent_lock_as_released_and_retries_a_failed_release_once() {
		$fixture = new Timecapsule_V2_Protocol_Fixture();
		// The lock name reads home_url(), so it is resolved while the real connection is still in place.
		$fixture->lock_name();
		$real = $GLOBALS['wpdb'];

		try {
			$absent          = new Timecapsule_Lock_Wpdb_Stub( array( array( '', null ) ) );
			$GLOBALS['wpdb'] = $absent;
			$this->assertTrue( $fixture->end_mutation_lock() );
			$this->assertCount( 1, $absent->queries );

			$retried         = new Timecapsule_Lock_Wpdb_Stub( array( array( 'MySQL server has gone away', null ), array( '', '1' ) ) );
			$GLOBALS['wpdb'] = $retried;
			$this->assertTrue( $fixture->end_mutation_lock() );
			$this->assertCount( 2, $retried->queries );

			$failing         = new Timecapsule_Lock_Wpdb_Stub( array( array( 'Lost connection', null ), array( 'Lost connection', null ) ) );
			$GLOBALS['wpdb'] = $failing;
			$this->assertFalse( $fixture->end_mutation_lock() );
			$this->assertCount( 2, $failing->queries );
		} finally {
			$GLOBALS['wpdb'] = $real;
		}
	}

	/**
	 * An entry filed under a key no request could present is not evidence for anybody.
	 *
	 * References are validated before the store is consulted, so nothing can ever come back for
	 * such an entry. Keeping it would let a store of junk keys hold every later mutation shut,
	 * and PHP array keys are not always strings, so one can also be compared as one.
	 */
	public function test_entries_under_keys_no_request_can_present_are_given_up() {
		$fixture  = $this->backup_fixture();
		$receipts = array();
		for ( $index = 0; $index < 99; $index++ ) {
			$receipts[ 'invalid-ref-' . $index ] = array(
				'effect_hash' => hash( 'sha256', (string) $index ),
				'state'       => 'settled',
				'response'    => array( 'ok' => true ),
				'created_at'  => PHP_INT_MAX,
			);
		}
		// An integer key: PHP turns a numeric string key into one, and it is not a reference.
		$receipts[0] = array(
			'effect_hash' => hash( 'sha256', 'numeric' ),
			'state'       => 'settled',
			'response'    => array( 'ok' => true ),
			'created_at'  => PHP_INT_MAX,
		);
		update_option( 'mainwp_timecapsule_abilities_v2_receipts', $receipts, false );

		$result = $fixture->abilities_v2( $this->receipt_backup_request( '123e4567-e89b-42d3-a456-426614174981' ) );
		$stored = get_option( 'mainwp_timecapsule_abilities_v2_receipts', array() );

		$this->assertTrue( $result['ok'], 'a store nothing can ever replay must not refuse forever' );
		$this->assertArrayHasKey( '123e4567-e89b-42d3-a456-426614174981', $stored );
	}

	/**
	 * A store keyed by references nothing can address after folding must still be reclaimable.
	 *
	 * A reference is folded to lowercase before it keys a receipt, so an uppercase key is
	 * unreachable however well formed it looks. Held live it refuses every later mutation.
	 */
	public function test_full_store_of_noncanonical_uppercase_keys_is_reclaimed() {
		$fixture  = $this->backup_fixture();
		$receipts = array();
		for ( $index = 0; $index < 100; $index++ ) {
			$receipts[ sprintf( '123E4567-E89B-42D3-A456-42661417%04X', $index ) ] = array(
				'effect_hash' => str_repeat( 'a', 64 ),
				'state'       => 'dispatching',
				'response'    => null,
				'created_at'  => PHP_INT_MAX,
			);
		}
		update_option( 'mainwp_timecapsule_abilities_v2_receipts', $receipts, false );

		$result = $fixture->abilities_v2( $this->receipt_backup_request( '123e4567-e89b-42d3-a456-426614174982' ) );
		$stored = get_option( 'mainwp_timecapsule_abilities_v2_receipts', array() );

		$this->assertTrue( $result['ok'], 'a store nothing can address must not refuse forever' );
		$this->assertCount( 1, $fixture->calls );
		$this->assertArrayHasKey( '123e4567-e89b-42d3-a456-426614174982', $stored );
	}

	/**
	 * A store whose stamps this clock cannot date is re-dated once, so it still ages out.
	 *
	 * Nothing is given up: every receipt is still there and still replays. What changes is that
	 * the store now holds dates it can act on.
	 */
	public function test_a_store_of_undatable_stamps_is_redated_once_and_then_ages_out() {
		$fixture = $this->backup_fixture();
		$ahead   = time() + ( 2 * DAY_IN_SECONDS );
		update_option( 'mainwp_timecapsule_abilities_v2_receipts', $this->fill_receipts( 100, $ahead ), false );

		$refused = $fixture->abilities_v2( $this->receipt_backup_request( '123e4567-e89b-42d3-a456-426614174983' ) );
		$stored  = get_option( 'mainwp_timecapsule_abilities_v2_receipts', array() );

		$this->assertFalse( $refused['ok'], 'a full store still refuses rather than dropping evidence' );
		$this->assertSame( array(), $fixture->calls );
		$this->assertCount( 100, $stored, 'not one receipt is given up to make room' );
		foreach ( $stored as $reference => $receipt ) {
			$this->assertLessThanOrEqual( time(), $receipt['created_at'], $reference );
			$this->assertSame( 'settled', $receipt['state'], $reference );
		}

		foreach ( $stored as $reference => $receipt ) {
			$stored[ $reference ]['created_at'] = time() - ( DAY_IN_SECONDS + 3600 );
		}
		update_option( 'mainwp_timecapsule_abilities_v2_receipts', $stored, false );

		$this->assertTrue( $fixture->abilities_v2( $this->receipt_backup_request( '123e4567-e89b-42d3-a456-426614174984' ) )['ok'] );
	}

	/** @return Timecapsule_V2_Protocol_Fixture */
	private function backup_fixture() {
		$fixture                          = new Timecapsule_V2_Protocol_Fixture();
		$fixture->is_plugin_installed     = true;
		$fixture->results['start_backup'] = array(
			'operation_ref' => str_repeat( 'a', 64 ),
			'state'         => 'queued',
			'scope'         => 'full',
		);
		return $fixture;
	}

	/** @param array $request Backup request. @param int $created_at Stamp. @return array */
	private function reservation( $request, $created_at ) {
		return array(
			'effect_hash' => hash( 'sha256', wp_json_encode( array( $request['operation'], $request['payload'] ) ) ),
			'state'       => 'dispatching',
			'response'    => null,
			'created_at'  => $created_at,
		);
	}

	/** @param int $count How many. @param int $created_at Stamp. @param string|null $first Reference of the first entry. @return array */
	private function fill_receipts( $count, $created_at, $first = null ) {
		$receipts = array();
		for ( $index = 0; $index < $count; $index++ ) {
			$reference              = 0 === $index && null !== $first ? $first : sprintf( '123e4567-e89b-42d3-a456-4266141751%02d', $index );
			$receipts[ $reference ] = array(
				'effect_hash' => hash( 'sha256', $reference ),
				'state'       => 'settled',
				'response'    => array(
					'protocol'    => '2',
					'operation'   => 'start_backup',
					'ok'          => true,
					'request_ref' => $reference,
				),
				'created_at'  => $created_at,
			);
		}
		return $receipts;
	}

	/** @param string $request_ref Reference. @return array */
	private function receipt_backup_request( $request_ref ) {
		return array(
			'protocol'    => '2',
			'operation'   => 'start_backup',
			'request_ref' => $request_ref,
			'payload'     => array(
				'scope'             => 'full',
				'label'             => null,
				'policy_generation' => str_repeat( 'b', 64 ),
			),
		);
	}

	private function start_backup_request( $policy_generation ) {
		return array(
			'protocol'    => '2',
			'operation'   => 'start_backup',
			'request_ref' => '123e4567-e89b-42d3-a456-426614174967',
			'payload'     => array( 'scope' => 'full', 'label' => null, 'policy_generation' => $policy_generation ),
		);
	}

	private function lock_state( $name ) {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', $name ) );
	}

	private function hold_lock_elsewhere( $name ) {
		$other = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$other->suppress_errors( true );
		$this->assertSame( '1', (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s,0)', $name ) ) );
		return $other;
	}

	private function release_lock_elsewhere( $other, $name ) {
		$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		$other->close();
	}

	private function request( $operation ) {
		return $this->subject->abilities_v2(
			array(
				'protocol'  => '2',
				'operation' => $operation,
				'payload'   => array(),
			)
		);
	}
}
