<?php
/**
 * Jetpack Protect abilities-v2 negotiation tests.
 *
 * @package MainWP_Child
 */

namespace MainWP\Child;

use ReflectionClass;
use WP_UnitTestCase;

class Test_MainWP_Child_Jetpack_Protect_V2 extends WP_UnitTestCase {

	/** @var MainWP_Child_Jetpack_Protect */
	private $subject;

	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( '\\Automattic\\Jetpack\\My_Jetpack\\Products\\Scan', false ) ) {
			class_alias( Test_Jetpack_Protect_Scan_Product::class, '\\Automattic\\Jetpack\\My_Jetpack\\Products\\Scan' );
		}
		$reflection    = new ReflectionClass( Testable_MainWP_Child_Jetpack_Protect::class );
		$this->subject = $reflection->newInstanceWithoutConstructor();
	}

	public function test_capabilities_are_closed_and_claim_no_unimplemented_operation() {
		$result = $this->request( 'capabilities', array() );

		$this->assertSame( array( 'protocol', 'operation', 'ok', 'operations', 'mutation_supported' ), array_keys( $result ) );
		$this->assertSame( '2', $result['protocol'] );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( array( 'ability_protect_observation_v2', 'ability_protect_control_state_v2', 'ability_protect_connection_v2', 'ability_protect_visibility_v2' ), $result['operations'] );
		$this->assertTrue( $result['mutation_supported'] );
	}

	public function test_observation_returns_closed_bounded_redacted_generation() {
		$this->subject->is_plugin_installed = true;
		$this->set_connection( new Test_Jetpack_Protect_Connection( true ) );
		$this->subject->scan_product_active = true;
		$this->subject->scan_status         = array(
			'status' => (object) array(
				'last_checked'       => '2026-08-13T12:00:00Z',
				'has_unchecked_items' => false,
				'error'              => false,
				'num_threats'        => 2,
				'threats'            => array(
					(object) array(
						'id'        => 'provider-threat-1',
						'title'     => '<b>Known vulnerable component</b>',
						'fixed_in'  => '2.0.0',
						'extension' => (object) array(
							'type'    => 'plugin',
							'name'    => 'Example Plugin',
							'slug'    => 'example-plugin',
							'version' => '1.0.0',
						),
					),
					(object) array(
						'id'          => 'provider-threat-2',
						'title'       => "Injected file\nfinding",
						'filename'    => '/private/secret.php',
						'description' => 'Sensitive raw description',
						'source'      => 'https://provider.invalid/finding/2',
					),
				),
			),
		);

		$result = $this->request( 'ability_protect_observation_v2', array() );

		$this->assertSame(
			array( 'protocol', 'operation', 'ok', 'observed_at', 'source_generation', 'completeness', 'connected', 'scan_product_active', 'counts', 'findings' ),
			array_keys( $result )
		);
		$this->assertTrue( $result['ok'] );
		$this->assertSame( '2026-08-13T12:00:00Z', $result['observed_at'] );
		$this->assertSame( 'complete', $result['completeness'] );
		$this->assertTrue( $result['connected'] );
		$this->assertTrue( $result['scan_product_active'] );
		$this->assertSame(
			array( 'total' => 2, 'core' => 0, 'plugins' => 1, 'themes' => 0, 'files' => 1, 'database' => 0 ),
			$result['counts']
		);
		$this->assertCount( 2, $result['findings'] );
		$this->assertSame( array( 'source_ref', 'kind', 'title', 'component_name', 'remediation' ), array_keys( $result['findings'][0] ) );
		$by_kind = array_column( $result['findings'], null, 'kind' );
		$this->assertSame( 'Known vulnerable component', $by_kind['plugin']['title'] );
		$this->assertSame( 'Example Plugin', $by_kind['plugin']['component_name'] );
		$this->assertSame( 'component_update_available', $by_kind['plugin']['remediation'] );
		$this->assertSame( 'Injected file finding', $by_kind['file']['title'] );
		$this->assertNull( $by_kind['file']['component_name'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $result['source_generation'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $result['findings'][0]['source_ref'] );

		$encoded = wp_json_encode( $result );
		$this->assertStringNotContainsString( 'provider-threat', $encoded );
		$this->assertStringNotContainsString( 'example-plugin', $encoded );
		$this->assertStringNotContainsString( '/private/secret.php', $encoded );
		$this->assertStringNotContainsString( 'Sensitive raw description', $encoded );
		$this->assertStringNotContainsString( 'provider.invalid', $encoded );
	}

	public function test_observation_distinguishes_clean_and_partial_generations() {
		$this->subject->is_plugin_installed = true;
		$this->set_connection( new Test_Jetpack_Protect_Connection( false ) );
		$this->subject->scan_status = array(
			'status' => (object) array(
				'last_checked'       => '2026-08-13 12:00:00',
				'has_unchecked_items' => false,
				'error'              => false,
				'num_threats'        => 0,
				'threats'            => array(),
			),
		);

		$clean = $this->request( 'ability_protect_observation_v2', array() );
		$this->assertTrue( $clean['ok'] );
		$this->assertSame( 'complete', $clean['completeness'] );
		$this->assertSame( 0, $clean['counts']['total'] );
		$this->assertSame( array(), $clean['findings'] );

		$this->subject->scan_status['status']->has_unchecked_items = true;
		$partial = $this->request( 'ability_protect_observation_v2', array() );
		$this->assertTrue( $partial['ok'] );
		$this->assertSame( 'partial', $partial['completeness'] );
		$this->assertNotSame( $clean['source_generation'], $partial['source_generation'] );
	}

	public function test_legacy_sync_reuses_the_fetched_status_for_the_safe_projection() {
		$this->subject->is_plugin_installed = true;
		$this->set_connection( new Test_Jetpack_Protect_Connection( true ) );
		$this->subject->scan_status = array(
			'status' => (object) array(
				'last_checked'       => '2026-08-13T12:00:00Z',
				'has_unchecked_items' => false,
				'error'              => false,
				'num_threats'        => 0,
				'threats'            => array(),
			),
		);

		$result = $this->subject->sync_others_data( array(), array( 'sync_JetpackProtect' => 1 ) );

		$this->assertArrayHasKey( 'sync_JetpackProtect_Data', $result );
		$this->assertSame( $this->subject->scan_status['status'], $result['sync_JetpackProtect_Data']['status'] );
		$this->assertTrue( $result['sync_JetpackProtect_Data']['ability_v2']['ok'] );
		$this->assertSame( 'ability_protect_observation_v2', $result['sync_JetpackProtect_Data']['ability_v2']['operation'] );
		$this->assertSame( 0, $result['sync_JetpackProtect_Data']['ability_v2']['counts']['total'] );
	}

	public function test_observation_rejects_malformed_mismatched_and_oversized_status() {
		$this->subject->is_plugin_installed = true;
		$this->set_connection( new Test_Jetpack_Protect_Connection( true ) );

		$invalid_cases = array(
			array( 'status' => array() ),
			array(
				'status' => (object) array(
					'last_checked'       => '2026-02-31T12:00:00Z',
					'has_unchecked_items' => false,
					'error'              => false,
					'threats'            => array(),
				),
			),
			array(
				'status' => (object) array(
					'last_checked'       => '2026-08-13T12:00:00Z',
					'has_unchecked_items' => false,
					'error'              => false,
					'num_threats'        => 2,
					'threats'            => array( (object) array( 'id' => 'one', 'title' => 'One', 'filename' => '/one' ) ),
				),
			),
			array(
				'status' => (object) array(
					'last_checked'       => '2026-08-13T12:00:00Z',
					'has_unchecked_items' => false,
					'error'              => false,
					'threats'            => array_fill( 0, 1001, (object) array( 'id' => 'many', 'title' => 'Many', 'filename' => '/many' ) ),
				),
			),
		);

		foreach ( $invalid_cases as $case ) {
			$this->subject->scan_status = $case;
			$result                     = $this->request( 'ability_protect_observation_v2', array() );
			$this->assertFalse( $result['ok'] );
			$this->assertSame( 'invalid_snapshot', $result['code'] );
		}

		$this->subject->scan_status = array(
			'status' => (object) array(
				'last_checked'       => '2026-08-13T12:00:00Z',
				'has_unchecked_items' => false,
				'error'              => true,
				'error_message'      => 'Sensitive upstream failure',
				'threats'            => array(),
			),
		);
		$result = $this->request( 'ability_protect_observation_v2', array() );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'remote_unavailable', $result['code'] );
		$this->assertStringNotContainsString( 'Sensitive upstream failure', wp_json_encode( $result ) );
	}

	public function test_control_state_returns_closed_connected_visible_state() {
		$this->subject->is_plugin_installed = true;
		$this->set_connection( new Test_Jetpack_Protect_Connection( true ) );
		delete_option( 'mainwp_child_jetpack_protect_hide_plugin' );

		$result = $this->request( 'ability_protect_control_state_v2', array() );

		$this->assertSame(
			array( 'protocol', 'operation', 'ok', 'plugin_state', 'connection', 'visibility', 'revision', 'observed_at' ),
			array_keys( $result )
		);
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'active', $result['plugin_state'] );
		$this->assertSame( 'connected', $result['connection'] );
		$this->assertSame( 'visible', $result['visibility'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $result['revision'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $result['observed_at'] );
	}

	public function test_control_state_normalizes_hidden_disconnected_and_missing_plugin() {
		$this->subject->is_plugin_installed = true;
		$this->set_connection( new Test_Jetpack_Protect_Connection( false ) );
		update_option( 'mainwp_child_jetpack_protect_hide_plugin', 'hide' );

		$hidden = $this->request( 'ability_protect_control_state_v2', array() );
		$this->assertSame( 'active', $hidden['plugin_state'] );
		$this->assertSame( 'disconnected', $hidden['connection'] );
		$this->assertSame( 'hidden', $hidden['visibility'] );

		update_option( 'mainwp_child_jetpack_protect_hide_plugin', array( 'hide' ) );
		$malformed = $this->request( 'ability_protect_control_state_v2', array() );
		$this->assertSame( 'unknown', $malformed['visibility'] );

		$this->subject->is_plugin_installed = false;
		$missing                            = $this->request( 'ability_protect_control_state_v2', array() );
		$this->assertSame( 'missing', $missing['plugin_state'] );
		$this->assertSame( 'unknown', $missing['connection'] );
		$this->assertSame( 'unknown', $missing['visibility'] );
	}

	public function test_control_state_rejects_nonempty_payload_and_malformed_runtime_state() {
		$invalid = $this->request( 'ability_protect_control_state_v2', array( 'extra' => true ) );
		$this->assertFalse( $invalid['ok'] );
		$this->assertSame( 'invalid_request', $invalid['code'] );

		$this->subject->is_plugin_installed = true;
		$this->set_connection( new \stdClass() );
		$unsupported = $this->request( 'ability_protect_control_state_v2', array() );
		$this->assertTrue( $unsupported['ok'] );
		$this->assertSame( 'unsupported', $unsupported['plugin_state'] );
		$this->assertSame( 'unknown', $unsupported['connection'] );
	}

	public function test_visibility_replace_is_revision_bound_and_readback_verified() {
		$this->subject->is_plugin_installed = true;
		$this->set_connection( new Test_Jetpack_Protect_Connection( true ) );
		delete_option( 'mainwp_child_jetpack_protect_hide_plugin' );
		$current = $this->request( 'ability_protect_control_state_v2', array() );

		$result = $this->request(
			'ability_protect_visibility_v2',
			array(
				'desired_state' => 'hidden',
				'if_match'      => $current['revision'],
			)
		);

		$this->assertSame(
			array( 'protocol', 'operation', 'ok', 'plugin_state', 'connection', 'visibility', 'revision', 'observed_at', 'changed' ),
			array_keys( $result )
		);
		$this->assertTrue( $result['ok'] );
		$this->assertTrue( $result['changed'] );
		$this->assertSame( 'hidden', $result['visibility'] );
		$this->assertSame( 'hide', get_option( 'mainwp_child_jetpack_protect_hide_plugin' ) );

		$replay = $this->request(
			'ability_protect_visibility_v2',
			array(
				'desired_state' => 'hidden',
				'if_match'      => $result['revision'],
			)
		);
		$this->assertTrue( $replay['ok'] );
		$this->assertFalse( $replay['changed'] );
	}

	public function test_visibility_replace_rejects_stale_malformed_and_unavailable_state_without_writing() {
		$this->subject->is_plugin_installed = true;
		$this->set_connection( new Test_Jetpack_Protect_Connection( true ) );
		update_option( 'mainwp_child_jetpack_protect_hide_plugin', 'show' );

		$stale = $this->request(
			'ability_protect_visibility_v2',
			array(
				'desired_state' => 'hidden',
				'if_match'      => str_repeat( 'a', 64 ),
			)
		);
		$this->assertFalse( $stale['ok'] );
		$this->assertSame( 'stale_revision', $stale['code'] );
		$this->assertSame( 'show', get_option( 'mainwp_child_jetpack_protect_hide_plugin' ) );

		foreach (
			array(
				array(),
				array( 'desired_state' => 'hidden', 'if_match' => str_repeat( 'a', 63 ) ),
				array( 'desired_state' => 'unknown', 'if_match' => str_repeat( 'a', 64 ) ),
				array( 'desired_state' => 'hidden', 'if_match' => str_repeat( 'a', 64 ), 'extra' => true ),
			) as $payload
		) {
			$invalid = $this->request( 'ability_protect_visibility_v2', $payload );
			$this->assertFalse( $invalid['ok'] );
			$this->assertSame( 'invalid_request', $invalid['code'] );
		}

		$this->subject->is_plugin_installed = false;
		$missing                            = $this->request(
			'ability_protect_visibility_v2',
			array(
				'desired_state' => 'hidden',
				'if_match'      => hash( 'sha256', 'missing|unknown|unknown' ),
			)
		);
		$this->assertFalse( $missing['ok'] );
		$this->assertSame( 'unsupported_version', $missing['code'] );
		$this->assertSame( 'show', get_option( 'mainwp_child_jetpack_protect_hide_plugin' ) );
	}

	public function test_visibility_replace_reports_write_failure_and_restores_contradictory_readback() {
		$this->subject->is_plugin_installed = true;
		$this->set_connection( new Test_Jetpack_Protect_Connection( true ) );
		update_option( 'mainwp_child_jetpack_protect_hide_plugin', 'show' );
		$current = $this->request( 'ability_protect_control_state_v2', array() );
		$block   = static function ( $value, $old_value ) {
			return $old_value;
		};
		add_filter( 'pre_update_option_mainwp_child_jetpack_protect_hide_plugin', $block, 10, 2 );
		$failed = $this->request(
			'ability_protect_visibility_v2',
			array(
				'desired_state' => 'hidden',
				'if_match'      => $current['revision'],
			)
		);
		remove_filter( 'pre_update_option_mainwp_child_jetpack_protect_hide_plugin', $block, 10 );
		$this->assertFalse( $failed['ok'] );
		$this->assertSame( 'write_failed', $failed['code'] );
		$this->assertSame( 'show', get_option( 'mainwp_child_jetpack_protect_hide_plugin' ) );

		$contradict = null;
		$contradict = static function ( $value ) use ( &$contradict ) {
			if ( 'hide' === $value ) {
				remove_filter( 'option_mainwp_child_jetpack_protect_hide_plugin', $contradict );
				return 'show';
			}
			return $value;
		};
		add_filter( 'option_mainwp_child_jetpack_protect_hide_plugin', $contradict );
		$contradictory = $this->request(
			'ability_protect_visibility_v2',
			array(
				'desired_state' => 'hidden',
				'if_match'      => $current['revision'],
			)
		);
		remove_filter( 'option_mainwp_child_jetpack_protect_hide_plugin', $contradict );
		$this->assertFalse( $contradictory['ok'] );
		$this->assertSame( 'contradictory_readback', $contradictory['code'] );
		$this->assertSame( 'show', get_option( 'mainwp_child_jetpack_protect_hide_plugin' ) );
	}

	public function test_connection_replace_is_revision_bound_and_readback_verified() {
		$this->subject->is_plugin_installed = true;
		$connection                         = new Test_Jetpack_Protect_Connection( true );
		$this->set_connection( $connection );
		delete_option( 'mainwp_child_jetpack_protect_hide_plugin' );
		$current = $this->request( 'ability_protect_control_state_v2', array() );

		$disconnected = $this->request(
			'ability_protect_connection_v2',
			array(
				'desired_state' => 'disconnected',
				'if_match'      => $current['revision'],
			)
		);
		$this->assertTrue( $disconnected['ok'] );
		$this->assertTrue( $disconnected['changed'] );
		$this->assertSame( 'disconnected', $disconnected['connection'] );
		$this->assertSame( 1, $connection->disconnect_calls );

		$connected = $this->request(
			'ability_protect_connection_v2',
			array(
				'desired_state' => 'connected',
				'if_match'      => $disconnected['revision'],
			)
		);
		$this->assertTrue( $connected['ok'] );
		$this->assertTrue( $connected['changed'] );
		$this->assertSame( 'connected', $connected['connection'] );
		$this->assertSame( 1, $connection->registration_calls );

		$replay = $this->request(
			'ability_protect_connection_v2',
			array(
				'desired_state' => 'connected',
				'if_match'      => $connected['revision'],
			)
		);
		$this->assertTrue( $replay['ok'] );
		$this->assertFalse( $replay['changed'] );
		$this->assertSame( 1, $connection->registration_calls );
	}

	public function test_connection_replace_rejects_stale_malformed_and_unavailable_state_without_dispatch() {
		$this->subject->is_plugin_installed = true;
		$connection                         = new Test_Jetpack_Protect_Connection( true );
		$this->set_connection( $connection );

		$stale = $this->request(
			'ability_protect_connection_v2',
			array(
				'desired_state' => 'disconnected',
				'if_match'      => str_repeat( 'a', 64 ),
			)
		);
		$this->assertFalse( $stale['ok'] );
		$this->assertSame( 'stale_revision', $stale['code'] );
		$this->assertSame( 0, $connection->disconnect_calls );

		foreach (
			array(
				array(),
				array( 'desired_state' => 'disconnected', 'if_match' => str_repeat( 'a', 63 ) ),
				array( 'desired_state' => 'unknown', 'if_match' => str_repeat( 'a', 64 ) ),
				array( 'desired_state' => 'disconnected', 'if_match' => str_repeat( 'a', 64 ), 'extra' => true ),
			) as $payload
		) {
			$invalid = $this->request( 'ability_protect_connection_v2', $payload );
			$this->assertFalse( $invalid['ok'] );
			$this->assertSame( 'invalid_request', $invalid['code'] );
		}

		$this->subject->is_plugin_installed = false;
		$missing                            = $this->request(
			'ability_protect_connection_v2',
			array(
				'desired_state' => 'connected',
				'if_match'      => hash( 'sha256', 'missing|unknown|unknown' ),
			)
		);
		$this->assertFalse( $missing['ok'] );
		$this->assertSame( 'unsupported_version', $missing['code'] );
	}

	public function test_connection_replace_redacts_failure_and_reports_contradictory_readback() {
		$this->subject->is_plugin_installed = true;
		$failed                             = new Test_Jetpack_Protect_Connection( false );
		$failed->registration_error         = 'Sensitive Jetpack account failure';
		$this->set_connection( $failed );
		$current = $this->request( 'ability_protect_control_state_v2', array() );

		$result = $this->request(
			'ability_protect_connection_v2',
			array(
				'desired_state' => 'connected',
				'if_match'      => $current['revision'],
			)
		);
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'write_failed', $result['code'] );
		$this->assertStringNotContainsString( 'Sensitive', wp_json_encode( $result ) );

		$contradict                  = new Test_Jetpack_Protect_Connection( false );
		$contradict->contradict_read = true;
		$this->set_connection( $contradict );
		$current = $this->request( 'ability_protect_control_state_v2', array() );
		$result  = $this->request(
			'ability_protect_connection_v2',
			array(
				'desired_state' => 'connected',
				'if_match'      => $current['revision'],
			)
		);
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'contradictory_readback', $result['code'] );
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

		$unknown = $this->request( 'ability_protect_future_v2', array() );
		$this->assertFalse( $unknown['ok'] );
		$this->assertSame( 'unsupported_operation', $unknown['code'] );

		$alias = $this->subject->abilities_v2(
			array(
				'protocol'     => '2',
				'operation'    => 'capabilities',
				'payload'      => array(),
				'operation_id' => '123e4567-e89b-42d3-a456-426614174501',
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

		$unknown = $this->request( 'ability_protect_future_v2', array() );
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

	private function set_connection( $connection ) {
		$property = ( new ReflectionClass( MainWP_Child_Jetpack_Protect::class ) )->getProperty( 'connection' );
		$property->setAccessible( true );
		$property->setValue( $this->subject, $connection );
	}
}

class Test_Jetpack_Protect_Connection {

	private $connected;

	public $registration_calls = 0;

	public $disconnect_calls = 0;

	public $registration_error = '';

	public $contradict_read = false;

	public function __construct( $connected ) {
		$this->connected = (bool) $connected;
	}

	public function is_connected() {
		return $this->connected;
	}

	public function try_registration() {
		++$this->registration_calls;
		if ( '' !== $this->registration_error ) {
			return new \WP_Error( 'provider_failed', $this->registration_error );
		}
		if ( ! $this->contradict_read ) {
			$this->connected = true;
		}
		return true;
	}

	public function disconnect_site() {
		++$this->disconnect_calls;
		if ( ! $this->contradict_read ) {
			$this->connected = false;
		}
		return true;
	}
}

class Test_Jetpack_Protect_Scan_Product {

	public static function is_active() {
		return false;
	}
}

class Testable_MainWP_Child_Jetpack_Protect extends MainWP_Child_Jetpack_Protect {

	public $scan_status = array();

	public $scan_product_active = false;

	public function get_scan_status() {
		return $this->scan_status;
	}

	protected function abilities_v2_scan_product_active() {
		return $this->scan_product_active;
	}
}
