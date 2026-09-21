<?php
/**
 * Patchstack abilities-v2 negotiation tests.
 *
 * @package MainWP_Child
 */

namespace MainWP\Child;

use ReflectionClass;
use WP_UnitTestCase;

class Test_MainWP_Child_Patchstack_V2 extends WP_UnitTestCase {

	/** @var MainWP_Child_Patchstack */
	private $subject;

	public function set_up(): void {
		parent::set_up();
		$reflection    = new ReflectionClass( Testable_MainWP_Child_Patchstack::class );
		$this->subject = $reflection->newInstanceWithoutConstructor();
	}

	/**
	 * Protection execution has no Child-side package or licensing adapter, so capabilities must not
	 * advertise it and dispatch must refuse it by name before it judges the payload.
	 */
	public function test_capabilities_advertise_exactly_the_operations_dispatch_accepts() {
		$production = ( new ReflectionClass( MainWP_Child_Patchstack::class ) )->newInstanceWithoutConstructor();

		$result = $production->abilities_v2(
			array(
				'protocol'  => '2',
				'operation' => 'capabilities',
				'payload'   => array(),
			)
		);

		$this->assertSame( array( 'protocol', 'operation', 'ok', 'operations', 'mutation_supported' ), array_keys( $result ) );
		$this->assertSame( '2', $result['protocol'] );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( array( 'patchstack_protection_preview_v2', 'patchstack_protection_status_v2', 'patchstack_visibility_replace_v2' ), $result['operations'] );
		$this->assertTrue( $result['mutation_supported'] );

		$refused = array();
		foreach ( array( 'patchstack_protection_preview_v2', 'patchstack_protection_execute_v2', 'patchstack_protection_status_v2', 'patchstack_visibility_replace_v2' ) as $operation ) {
			// An empty payload is invalid for every one of these, so only a by-name refusal can outrank invalid_request.
			$response = $production->abilities_v2(
				array(
					'protocol'  => '2',
					'operation' => $operation,
					'payload'   => array(),
				)
			);
			if ( isset( $response['code'] ) && 'unsupported_operation' === $response['code'] ) {
				$refused[] = $operation;
			}
		}

		$this->assertSame( array( 'patchstack_protection_execute_v2' ), $refused );
	}

	public function test_protection_preview_is_local_bounded_and_generation_bound() {
		$this->subject->plugin_state = 'active';
		$this->subject->visibility   = 'hidden';
		$result                      = $this->request( 'patchstack_protection_preview_v2', $this->base_payload() );

		$this->assertSame( array( 'protocol', 'operation', 'ok', 'operation_ref', 'current_state', 'planned_action', 'package_state', 'visibility', 'state_revision', 'observed_at' ), array_keys( $result ) );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'active', $result['current_state'] );
		$this->assertSame( 'license', $result['planned_action'] );
		$this->assertSame( 'not_needed', $result['package_state'] );
		$this->assertSame( 'hidden', $result['visibility'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $result['state_revision'] );
		$this->assertSame( 0, $this->subject->writes );
		$active_revision = $result['state_revision'];

		$this->subject->plugin_state = 'absent';
		$result                      = $this->request( 'patchstack_protection_preview_v2', $this->base_payload( '123e4567-e89b-42d3-a456-426614174511' ) );
		$this->assertSame( 'install', $result['planned_action'] );
		$this->assertSame( 'verification_unavailable', $result['package_state'] );

		$this->subject->manifest = $this->verified_manifest();
		$result                  = $this->request( 'patchstack_protection_preview_v2', $this->base_payload( '123e4567-e89b-42d3-a456-426614174512' ) );
		$this->assertSame( 'verified_available', $result['package_state'] );
		$this->assertNotSame( $active_revision, $result['state_revision'] );
	}

	public function test_protection_execute_reserves_converges_replays_and_reports_status() {
		$this->subject->plugin_state = 'absent';
		$this->subject->manifest     = $this->verified_manifest();
		$common                      = $this->base_payload();
		$preview                     = $this->request( 'patchstack_protection_preview_v2', $common );
		$payload                     = array_merge(
			$common,
			array(
				'if_match'     => $preview['state_revision'],
				'license_token' => 'private-one-use-license-token',
			)
		);

		$result = $this->request( 'patchstack_protection_execute_v2', $payload );
		$this->assertSame( array( 'protocol', 'operation', 'ok', 'operation_ref', 'status', 'current_state', 'changed', 'code' ), array_keys( $result ) );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'completed', $result['status'] );
		$this->assertSame( 'protected', $result['current_state'] );
		$this->assertTrue( $result['changed'] );
		$this->assertNull( $result['code'] );
		$this->assertSame( 1, $this->subject->protection_writes );
		$this->assertSame( 'dispatching', $this->subject->protection_receipt_writes[0][ $common['operation_ref'] ]['state'] );
		$this->assertStringNotContainsString( 'private-one-use-license-token', wp_json_encode( $this->subject->protection_receipts ) );

		$this->assertSame( $result, $this->request( 'patchstack_protection_execute_v2', $payload ) );
		$this->assertSame( 1, $this->subject->protection_writes );

		$status = $this->request( 'patchstack_protection_status_v2', $common );
		$this->assertSame( array( 'protocol', 'operation', 'ok', 'operation_ref', 'status', 'current_state', 'changed', 'code' ), array_keys( $status ) );
		$this->assertSame( 'patchstack_protection_status_v2', $status['operation'] );
		$this->assertSame( 'completed', $status['status'] );
		$this->assertSame( 'protected', $status['current_state'] );

		$changed                  = $payload;
		$changed['license_token'] = 'different-private-token';
		$this->assertSame( 'request_conflict', $this->request( 'patchstack_protection_execute_v2', $changed )['code'] );
	}

	public function test_protection_execute_fails_closed_without_trust_and_rolls_back_contradiction() {
		$this->subject->plugin_state = 'absent';
		$common                      = $this->base_payload();
		$preview                     = $this->request( 'patchstack_protection_preview_v2', $common );
		$payload                     = array_merge( $common, array( 'if_match' => $preview['state_revision'], 'license_token' => 'private-license-token' ) );

		$this->assertSame( 'package_verification_unavailable', $this->request( 'patchstack_protection_execute_v2', $payload )['code'] );
		$this->assertSame( 0, $this->subject->protection_writes );
		$this->assertSame( array(), $this->subject->protection_receipts );

		$this->subject->manifest                = $this->verified_manifest();
		$preview                                = $this->request( 'patchstack_protection_preview_v2', $common );
		$payload['if_match']                    = $preview['state_revision'];
		$this->subject->protection_readback     = 'active';
		$result                                 = $this->request( 'patchstack_protection_execute_v2', $payload );
		$this->assertSame( 'write_failed', $result['code'] );
		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( 'absent', $this->subject->plugin_state );
		$this->assertSame( 1, $this->subject->protection_rollbacks );
	}

	/** An installed plugin needs no package, so execution must not be refused for missing package verification. */
	public function test_protection_execute_runs_when_the_plugin_is_already_installed_and_no_package_is_needed() {
		$this->subject->plugin_state = 'active';
		$this->subject->manifest     = null;
		$common                      = $this->base_payload();
		$preview                     = $this->request( 'patchstack_protection_preview_v2', $common );
		$this->assertSame( 'not_needed', $preview['package_state'] );

		$result = $this->request(
			'patchstack_protection_execute_v2',
			array_merge( $common, array( 'if_match' => $preview['state_revision'], 'license_token' => 'private-license-token' ) )
		);

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'completed', $result['status'] );
		$this->assertSame( 'protected', $result['current_state'] );
		$this->assertNull( $result['code'] );
		$this->assertSame( 1, $this->subject->protection_writes );
	}

	public function test_dispatching_protection_receipt_is_unknown_and_status_never_dispatches() {
		$this->subject->plugin_state = 'absent';
		$this->subject->manifest     = $this->verified_manifest();
		$common                      = $this->base_payload();
		$preview                     = $this->request( 'patchstack_protection_preview_v2', $common );
		$payload                     = array_merge( $common, array( 'if_match' => $preview['state_revision'], 'license_token' => 'private-license-token' ) );
		$this->subject->seed_dispatching_protection( $payload );

		$result = $this->request( 'patchstack_protection_execute_v2', $payload );
		$this->assertSame( 'unknown', $result['status'] );
		$this->assertSame( 'outcome_unknown', $result['code'] );
		$this->assertSame( 0, $this->subject->protection_writes );

		$status = $this->request( 'patchstack_protection_status_v2', $common );
		$this->assertSame( 'unknown', $status['status'] );
		$this->assertSame( 0, $this->subject->protection_writes );
	}

	public function test_visibility_replace_stages_replays_and_restores_on_contradiction() {
		$this->subject->plugin_state = 'active';
		$this->subject->visibility   = 'shown';
		$preview                     = $this->request( 'patchstack_protection_preview_v2', $this->base_payload() );
		$payload                     = array_merge(
			$this->base_payload(),
			array(
				'desired_state' => 'hidden',
				'if_match'      => $preview['state_revision'],
			)
		);

		$result = $this->request( 'patchstack_visibility_replace_v2', $payload );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'hidden', $result['visibility'] );
		$this->assertTrue( $result['changed'] );
		$this->assertSame( 1, $this->subject->writes );
		$this->assertSame( 'pending', $this->subject->receipt_writes[0][ $payload['operation_ref'] ]['state'] );

		$replay = $this->request( 'patchstack_visibility_replace_v2', $payload );
		$this->assertSame( $result, $replay );
		$this->assertSame( 1, $this->subject->writes );

		$this->subject->visibility_write_readback = 'hidden';
		$failed_payload                            = array_merge(
			$this->base_payload( '123e4567-e89b-42d3-a456-426614174512' ),
			array(
				'desired_state' => 'shown',
				'if_match'      => $result['state_revision'],
			)
		);
		$failed = $this->request( 'patchstack_visibility_replace_v2', $failed_payload );
		$this->assertSame( 'write_failed', $failed['code'] );
		$this->assertSame( 'hidden', $this->subject->visibility );
	}

	public function test_visibility_replace_accepts_the_preview_revision_when_patchstack_is_absent() {
		$this->subject->plugin_state = 'absent';
		$this->subject->visibility   = 'shown';
		$common                      = $this->base_payload();
		$preview                     = $this->request( 'patchstack_protection_preview_v2', $common );
		$this->assertSame( 'verification_unavailable', $preview['package_state'] );

		$payload = array_merge(
			$common,
			array(
				'desired_state' => 'hidden',
				'if_match'      => $preview['state_revision'],
			)
		);
		$result  = $this->request( 'patchstack_visibility_replace_v2', $payload );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'hidden', $result['visibility'] );
		$this->assertTrue( $result['changed'] );

		$follow_up = $this->request( 'patchstack_protection_preview_v2', $this->base_payload( '123e4567-e89b-42d3-a456-426614174513' ) );
		$this->assertSame( 'hidden', $follow_up['visibility'] );
		$this->assertSame( $follow_up['state_revision'], $result['state_revision'] );
	}

	public function test_patchstack_v2_rejects_stale_conflicting_and_malformed_effects() {
		$this->subject->plugin_state = 'installed';
		$this->subject->visibility   = 'shown';
		$preview                     = $this->request( 'patchstack_protection_preview_v2', $this->base_payload() );
		$payload                     = array_merge(
			$this->base_payload(),
			array(
				'desired_state' => 'hidden',
				'if_match'      => str_repeat( 'a', 64 ),
			)
		);
		$this->assertSame( 'stale_revision', $this->request( 'patchstack_visibility_replace_v2', $payload )['code'] );
		$payload['if_match'] = $preview['state_revision'];
		$this->assertTrue( $this->request( 'patchstack_visibility_replace_v2', $payload )['ok'] );
		$payload['desired_state'] = 'shown';
		$this->assertSame( 'request_conflict', $this->request( 'patchstack_visibility_replace_v2', $payload )['code'] );

		$invalid                     = $this->base_payload();
		$invalid['expected_plugin_slug'] = 'other/plugin.php';
		$this->assertSame( 'invalid_request', $this->request( 'patchstack_protection_preview_v2', $invalid )['code'] );
		$invalid                      = $this->base_payload();
		$invalid['expires_at']        = time() - 1;
		$this->assertSame( 'invalid_request', $this->request( 'patchstack_protection_preview_v2', $invalid )['code'] );
	}

	public function test_malformed_unknown_and_uuid_alias_requests_fail_closed() {
		$this->assertSame(
			array(
				'protocol'  => '2',
				'operation' => 'unknown',
				'ok'        => false,
				'code'      => 'invalid_request',
			),
			$this->subject->abilities_v2( array() )
		);

		$unknown = $this->request( 'patchstack_unknown_v2', array() );
		$this->assertFalse( $unknown['ok'] );
		$this->assertSame( 'unsupported_operation', $unknown['code'] );

		$alias = $this->subject->abilities_v2(
			array(
				'protocol'     => '2',
				'operation'    => 'capabilities',
				'payload'      => array(),
				'operation_id' => '123e4567-e89b-42d3-a456-426614174508',
			)
		);
		$this->assertFalse( $alias['ok'] );
		$this->assertSame( 'invalid_request', $alias['code'] );
	}

	public function test_reordered_envelope_is_accepted_but_nonempty_payload_is_not() {
		$result = $this->subject->abilities_v2(
			array(
				'payload'   => array(),
				'operation' => 'capabilities',
				'protocol'  => '2',
			)
		);
		$this->assertTrue( $result['ok'] );

		// Negotiation is supported, so a payload on it is a malformed request, not an unknown operation.
		$result = $this->request( 'capabilities', array( 'extra' => true ) );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'capabilities', $result['operation'] );
		$this->assertSame( 'invalid_request', $result['code'] );

		$unknown = $this->request( 'patchstack_unknown_v2', array() );
		$this->assertSame( 'unsupported_operation', $unknown['code'] );
	}

	private function request( $operation, $payload ) {
		return $this->subject->abilities_v2(
			array(
				'protocol'  => '2',
				'operation' => $operation,
				'payload'   => $payload,
			)
		);
	}

	private function base_payload( $operation_ref = '123e4567-e89b-42d3-a456-426614174510' ) {
		return array(
			'operation_ref'          => $operation_ref,
			'expected_plugin_slug'   => 'patchstack/patchstack.php',
			'provider_binding_digest' => str_repeat( 'b', 64 ),
			'expires_at'             => time() + 300,
		);
	}

	private function verified_manifest() {
		return array(
			'version'         => '2.3.4',
			'package_sha256'  => str_repeat( 'c', 64 ),
			'manifest_digest' => str_repeat( 'd', 64 ),
		);
	}
}

class Testable_MainWP_Child_Patchstack extends MainWP_Child_Patchstack {

	public $plugin_state = 'absent';
	public $visibility = 'shown';
	public $visibility_write_readback = null;
	public $writes = 0;
	public $receipts = array();
	public $receipt_writes = array();
	public $manifest = null;
	public $protection_writes = 0;
	public $protection_rollbacks = 0;
	public $protection_readback = 'protected';
	public $protection_receipts = array();
	public $protection_receipt_writes = array();

	/** This fixture wires the package and licensing seams, so it can advertise and run execution. */
	protected function abilities_v2_supported_operations() {
		return array_merge( parent::abilities_v2_supported_operations(), array( 'patchstack_protection_execute_v2' ) );
	}

	protected function abilities_v2_plugin_state() {
		return $this->plugin_state;
	}

	protected function abilities_v2_visibility() {
		return $this->visibility;
	}

	protected function abilities_v2_write_visibility( $visibility ) {
		++$this->writes;
		$prior            = $this->visibility;
		$this->visibility = null === $this->visibility_write_readback ? $visibility : $this->visibility_write_readback;
		return array( 'prior' => $prior, 'current' => $this->visibility );
	}

	protected function abilities_v2_restore_visibility( $visibility ) {
		$this->visibility = $visibility;
		return true;
	}

	protected function abilities_v2_read_receipts() {
		return $this->receipts;
	}

	protected function abilities_v2_write_receipts( $receipts ) {
		$this->receipt_writes[] = $receipts;
		$this->receipts         = $receipts;
		return true;
	}

	protected function abilities_v2_begin_mutation() {
		return true;
	}

	protected function abilities_v2_end_mutation() {
		return true;
	}

	protected function abilities_v2_verified_package_manifest() {
		return $this->manifest;
	}

	protected function abilities_v2_apply_protection( $payload, $before, $manifest ) {
		unset( $payload, $before, $manifest );
		++$this->protection_writes;
		$this->plugin_state = $this->protection_readback;
		return array( 'changed' => true );
	}

	protected function abilities_v2_rollback_protection( $before ) {
		++$this->protection_rollbacks;
		$this->plugin_state = $before;
		return true;
	}

	protected function abilities_v2_read_protection_receipts() {
		return $this->protection_receipts;
	}

	protected function abilities_v2_write_protection_receipts( $receipts ) {
		$this->protection_receipt_writes[] = $receipts;
		$this->protection_receipts         = $receipts;
		return true;
	}

	public function seed_dispatching_protection( $payload ) {
		$this->protection_receipts[ $payload['operation_ref'] ] = array(
			'effect_hash'     => $this->protection_effect_hash_for_test( $payload ),
			'binding_digest'  => $payload['provider_binding_digest'],
			'operation_ref'   => $payload['operation_ref'],
			'prior_state'     => $this->plugin_state,
			'state'           => 'dispatching',
			'result'          => null,
			'updated_at'      => time(),
		);
	}

	public function protection_effect_hash_for_test( $payload ) {
		return $this->abilities_v2_protection_effect_hash( $payload );
	}
}
