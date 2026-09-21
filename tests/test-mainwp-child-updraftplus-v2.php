<?php
/**
 * UpdraftPlus abilities-v2 negotiation tests.
 *
 * @package MainWP_Child
 */

namespace MainWP\Child;

use ReflectionClass;
use WP_UnitTestCase;

class Updraftplus_V2_Protocol_Fixture extends MainWP_Child_Updraft_Plus_Backups {

	/** @var array */
	public $results = array();

	/** @var array */
	public $calls = array();

	protected function abilities_v2_supported_operations() {
		return array( 'site', 'policy', 'list_backups', 'backup_manifest', 'operation_status', 'preview_restore', 'replace_policy', 'start_backup', 'cancel_operation', 'prepare_download', 'delete_backup', 'restore_backup' );
	}

	protected function abilities_v2_provider_supports_mutation() {
		return true;
	}

	protected function abilities_v2_provider_operation( $operation, $payload ) {
		$this->calls[] = array( $operation, $payload );
		return isset( $this->results[ $operation ] ) ? $this->results[ $operation ] : new \WP_Error( 'provider_unavailable' );
	}
}

class Test_MainWP_Child_Updraftplus_V2 extends WP_UnitTestCase {

	/** @var MainWP_Child_Updraft_Plus_Backups */
	private $subject;

	public function set_up(): void {
		parent::set_up();
		delete_option( 'mainwp_updraftplus_abilities_v2_receipts' );
		$reflection    = new ReflectionClass( MainWP_Child_Updraft_Plus_Backups::class );
		$this->subject = $reflection->newInstanceWithoutConstructor();
		$this->subject->is_plugin_installed = true;
	}

	public function tear_down(): void {
		delete_option( 'mainwp_updraftplus_abilities_v2_receipts' );
		parent::tear_down();
	}

	/**
	 * No UpdraftPlus adapter is wired on the Child, so capabilities must advertise nothing
	 * and every operation must be refused by name rather than blamed on the provider.
	 */
	public function test_capabilities_advertise_nothing_and_every_operation_is_refused_by_name() {
		$result = $this->subject->abilities_v2(
			array(
				'protocol'  => '2',
				'operation' => 'capabilities',
				'payload'   => array(),
			)
		);

		$this->assertSame( array( 'protocol', 'operation', 'ok', 'operations', 'mutation_supported' ), array_keys( $result ) );
		$this->assertSame( array(), $result['operations'] );
		$this->assertFalse( $result['mutation_supported'] );

		foreach ( $this->protocol_requests() as $operation => $request ) {
			$response = $this->subject->abilities_v2( $request );
			$this->assertFalse( $response['ok'], $operation );
			$this->assertSame( 'unsupported_operation', $response['code'], $operation );
		}
	}

	/** Everything an adapter advertises must reach the provider boundary through the same entry point. */
	public function test_advertised_operations_reach_the_provider_boundary() {
		$fixture                      = new Updraftplus_V2_Protocol_Fixture();
		$fixture->is_plugin_installed = true;

		$reached = array();
		foreach ( $this->protocol_requests() as $operation => $request ) {
			$response = $fixture->abilities_v2( $request );
			$this->assertFalse( $response['ok'], $operation );
			$this->assertSame( 'provider_unavailable', $response['code'], $operation );
			$reached[] = $operation;
		}

		$capabilities = $fixture->abilities_v2(
			array(
				'protocol'  => '2',
				'operation' => 'capabilities',
				'payload'   => array(),
			)
		);
		$this->assertSame( $capabilities['operations'], $reached );
	}

	/**
	 * One request per protocol operation, keyed by operation name.
	 *
	 * @return array Closed protocol requests.
	 */
	private function protocol_requests() {
		$hash      = str_repeat( 'a', 64 );
		$mutations = array( 'replace_policy', 'start_backup', 'cancel_operation', 'prepare_download', 'delete_backup', 'restore_backup' );
		$payloads  = array(
			'site'             => array(),
			'policy'           => array(),
			'list_backups'     => array( 'limit' => 50, 'after_backup_ref' => null ),
			'backup_manifest'  => array( 'backup_ref' => $hash ),
			'operation_status' => array( 'operation_ref' => $hash ),
			'preview_restore'  => array( 'backup_ref' => $hash, 'manifest_generation' => $hash, 'component_refs' => array( $hash ) ),
			'replace_policy'   => array( 'files_interval' => 'daily', 'database_interval' => 'daily', 'retain_files' => 2, 'retain_database' => 2, 'components' => array( 'database' ), 'if_match' => $hash ),
			'start_backup'     => array( 'components' => array( 'database' ), 'placement' => 'both', 'policy_generation' => $hash ),
			'cancel_operation' => array( 'operation_ref' => $hash, 'if_match' => $hash ),
			'prepare_download' => array( 'backup_ref' => $hash, 'component_ref' => $hash, 'manifest_generation' => $hash ),
			'delete_backup'    => array( 'backup_ref' => $hash, 'manifest_generation' => $hash, 'locations' => array( 'local' ) ),
			'restore_backup'   => array( 'backup_ref' => $hash, 'manifest_generation' => $hash, 'component_refs' => array( $hash ), 'preview_token' => str_repeat( 'P', 43 ) ),
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
	 * must replay the first receipt instead of running the mutation a second time.
	 */
	public function test_recased_request_ref_replays_the_same_receipt() {
		$fixture                          = new Updraftplus_V2_Protocol_Fixture();
		$fixture->is_plugin_installed     = true;
		$fixture->results['start_backup'] = array(
			'operation_ref'   => str_repeat( 'a', 64 ),
			'state'           => 'queued',
			'component_count' => 1,
			'placement'       => 'both',
		);
		$request = array(
			'protocol'    => '2',
			'operation'   => 'start_backup',
			'request_ref' => '123E4567-E89B-42D3-A456-426614174983',
			'payload'     => array( 'components' => array( 'database' ), 'placement' => 'both', 'policy_generation' => str_repeat( 'b', 64 ) ),
		);

		$first = $fixture->abilities_v2( $request );
		$this->assertTrue( $first['ok'] );
		$this->assertSame( strtolower( $request['request_ref'] ), $first['request_ref'] );

		$request['request_ref'] = strtolower( $request['request_ref'] );
		$this->assertSame( $first, $fixture->abilities_v2( $request ) );
		$this->assertCount( 1, $fixture->calls );
		$this->assertSame( array( strtolower( '123E4567-E89B-42D3-A456-426614174983' ) ), array_keys( get_option( 'mainwp_updraftplus_abilities_v2_receipts' ) ) );
	}

	public function test_typed_backup_mutation_uses_request_ref_and_exact_replay() {
		$fixture                      = new Updraftplus_V2_Protocol_Fixture();
		$fixture->is_plugin_installed = true;
		$fixture->results['start_backup'] = array(
			'operation_ref' => str_repeat( 'a', 64 ),
			'state'         => 'queued',
			'component_count' => 2,
			'placement'     => 'both',
		);
		$request = array(
			'protocol'    => '2',
			'operation'   => 'start_backup',
			'request_ref' => '123e4567-e89b-42d3-a456-426614174981',
			'payload'     => array( 'components' => array( 'database', 'plugins' ), 'placement' => 'both', 'policy_generation' => str_repeat( 'b', 64 ) ),
		);
		$result = $fixture->abilities_v2( $request );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( $request['request_ref'], $result['request_ref'] );
		$this->assertSame( $result, $fixture->abilities_v2( $request ) );
		$this->assertCount( 1, $fixture->calls );

		$request['payload']['placement'] = 'local';
		$this->assertSame( 'request_conflict', $fixture->abilities_v2( $request )['code'] );
		unset( $request['request_ref'] );
		$request['request_id'] = '123e4567-e89b-42d3-a456-426614174982';
		$this->assertSame( 'invalid_request', $fixture->abilities_v2( $request )['code'] );
	}

	public function test_manifest_and_restore_preview_results_are_closed() {
		$fixture = new Updraftplus_V2_Protocol_Fixture();
		$fixture->results['backup_manifest'] = array(
			'backup_ref'         => str_repeat( 'c', 64 ),
			'created_at'         => '2026-08-16T10:00:00Z',
			'components'         => array(),
			'complete'           => false,
			'manifest_generation' => str_repeat( 'd', 64 ),
		);
		$manifest = $fixture->abilities_v2( array( 'protocol' => '2', 'operation' => 'backup_manifest', 'payload' => array( 'backup_ref' => str_repeat( 'c', 64 ) ) ) );
		$this->assertTrue( $manifest['ok'] );

		$fixture->results['preview_restore'] = array(
			'preview_token'      => str_repeat( 'P', 43 ),
			'expires_at'        => '2026-08-16T10:05:00Z',
			'backup_ref'        => str_repeat( 'c', 64 ),
			'component_count'   => 1,
			'overwrite_expected' => true,
			'preflight'         => 'blocked',
		);
		$preview = $fixture->abilities_v2( array( 'protocol' => '2', 'operation' => 'preview_restore', 'payload' => array( 'backup_ref' => str_repeat( 'c', 64 ), 'manifest_generation' => str_repeat( 'd', 64 ), 'component_refs' => array( str_repeat( 'e', 64 ) ) ) ) );
		$this->assertTrue( $preview['ok'] );
		$this->assertSame( 'blocked', $preview['preflight'] );
	}

	public function test_malformed_and_unknown_requests_fail_closed() {
		$this->assertSame(
			array(
				'protocol'  => '2',
				'operation' => 'unknown',
				'ok'        => false,
				'code'      => 'invalid_request',
			),
			$this->subject->abilities_v2( array() )
		);

		$result = $this->subject->abilities_v2(
			array(
				'protocol'  => '2',
				'operation' => 'history',
				'payload'   => array(),
			)
		);
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'unsupported_operation', $result['code'] );
	}
}
