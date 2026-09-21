<?php
/**
 * Solid Security abilities-v2 negotiation tests.
 *
 * @package MainWP_Child
 */

namespace MainWP\Child;

use ReflectionClass;
use WP_UnitTestCase;

class IThemes_Security_V2_Protocol_Fixture extends MainWP_Child_IThemes_Security {

	/** @var array */
	public $results = array();

	/** @var array */
	public $calls = array();

	protected function abilities_v2_provider_available() {
		return true;
	}

	protected function abilities_v2_provider_operation( $operation, $payload ) {
		$this->calls[] = array( $operation, $payload );
		return isset( $this->results[ $operation ] ) ? $this->results[ $operation ] : new \WP_Error( 'plugin_unavailable' );
	}
}

/** Exercise the production option/database adapters without requiring Solid itself. */
class IThemes_Security_V2_Production_Adapter_Fixture extends MainWP_Child_IThemes_Security {
	protected function abilities_v2_provider_available() {
		return true;
	}
}

class Test_MainWP_Child_IThemes_Security_V2 extends WP_UnitTestCase {

	/** @var MainWP_Child_IThemes_Security */
	private $subject;

	public function set_up(): void {
		parent::set_up();
		delete_site_option( 'itsec_temp_whitelist_ip' );
		delete_option( 'mainwp_solid_abilities_v2_receipts' );
		$reflection    = new ReflectionClass( MainWP_Child_IThemes_Security::class );
		$this->subject = $reflection->newInstanceWithoutConstructor();
	}

	public function tear_down(): void {
		delete_site_option( 'itsec_temp_whitelist_ip' );
		delete_option( 'mainwp_solid_abilities_v2_receipts' );
		parent::tear_down();
	}

	public function test_capabilities_are_closed_and_claim_no_unimplemented_operation() {
		$result = $this->request( 'capabilities', array() );

		$this->assertSame( array( 'protocol', 'operation', 'ok', 'operations', 'mutation_supported' ), array_keys( $result ) );
		$this->assertSame( '2', $result['protocol'] );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( array( 'ability_solid_file_permissions_v2', 'ability_solid_summary_v2', 'ability_solid_whitelist_v2', 'ability_solid_lockouts_v2', 'ability_solid_release_lockouts_v2', 'ability_solid_replace_whitelist_v2', 'ability_solid_file_scan_v2', 'ability_solid_backup_v2', 'ability_solid_malware_scan_v2', 'ability_solid_clear_logs_v2' ), $result['operations'] );
		$this->assertFalse( $result['mutation_supported'] );
	}

	public function test_summary_fails_closed_when_solid_is_unavailable() {
		$result = $this->request( 'ability_solid_summary_v2', array() );

		$this->assertSame( array( 'protocol', 'operation', 'ok', 'code' ), array_keys( $result ) );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'plugin_unavailable', $result['code'] );
	}

	public function test_every_v2_read_fails_closed_when_solid_is_absent() {
		$reads = array(
			'ability_solid_file_permissions_v2' => array(),
			'ability_solid_summary_v2'          => array(),
			'ability_solid_whitelist_v2'        => array(),
			'ability_solid_lockouts_v2'         => array(
				'limit'             => 50,
				'after_lockout_ref' => null,
			),
		);

		foreach ( $reads as $operation => $payload ) {
			$result = $this->request( $operation, $payload );

			$this->assertSame( array( 'protocol', 'operation', 'ok', 'code' ), array_keys( $result ), $operation );
			$this->assertSame( $operation, $result['operation'] );
			$this->assertFalse( $result['ok'], $operation );
			$this->assertSame( 'plugin_unavailable', $result['code'], $operation );
		}
	}

	public function test_v2_requests_reach_the_protocol_on_a_site_without_solid() {
		$this->assertFalse( class_exists( '\ITSEC_Core' ), 'Solid must be absent, otherwise this test cannot see the v1 bail.' );
		$respond = $this->action_response_method();

		try {
			$_POST['request'] = wp_json_encode(
				array(
					'protocol'  => '2',
					'operation' => 'capabilities',
					'payload'   => array(),
				)
			);
			$capabilities     = $respond->invoke( $this->subject, 'abilities_v2' );

			$_POST['request'] = wp_json_encode(
				array(
					'protocol'  => '2',
					'operation' => 'ability_solid_summary_v2',
					'payload'   => array(),
				)
			);
			$summary          = $respond->invoke( $this->subject, 'abilities_v2' );
		} finally {
			unset( $_POST['request'] );
		}

		$this->assertSame( array( 'protocol', 'operation', 'ok', 'operations', 'mutation_supported' ), array_keys( $capabilities ) );
		$this->assertTrue( $capabilities['ok'] );
		$this->assertFalse( $capabilities['mutation_supported'] );

		$this->assertSame( array( 'protocol', 'operation', 'ok', 'code' ), array_keys( $summary ) );
		$this->assertFalse( $summary['ok'] );
		$this->assertSame( 'plugin_unavailable', $summary['code'] );
	}

	/**
	 * release_lockouts accepts 100 refs, so the biggest envelope the validator calls legal is about
	 * 6.9 KB. The transport bound has to admit it, or the Child refuses a request it advertises.
	 */
	public function test_transport_bound_admits_a_maximal_legal_release_lockouts_envelope() {
		$refs = array();
		for ( $index = 0; $index < 100; $index++ ) {
			$refs[] = hash( 'sha256', 'lockout-' . $index );
		}
		$envelope = wp_json_encode(
			array(
				'protocol'    => '2',
				'operation'   => 'ability_solid_release_lockouts_v2',
				'request_ref' => '123e4567-e89b-42d3-a456-426614174900',
				'payload'     => array(
					'lockout_refs' => $refs,
					'if_match'     => hash( 'sha256', 'generation' ),
				),
			)
		);
		$respond = $this->action_response_method();

		try {
			$_POST['request'] = $envelope;
			$result           = $respond->invoke( $this->subject, 'abilities_v2' );
		} finally {
			unset( $_POST['request'] );
		}

		$this->assertGreaterThan( 4096, strlen( $envelope ) );
		// Solid is absent here, so the honest answer is plugin_unavailable. invalid_request would
		// mean the transport dropped the envelope and handed the protocol a null request.
		$this->assertSame( 'plugin_unavailable', $result['code'] );
	}

	public function test_v1_actions_are_not_diverted_into_the_v2_protocol() {
		$respond = $this->action_response_method();

		$this->assertNull( $respond->invoke( $this->subject, 'file_change' ) );
		$this->assertNull( $respond->invoke( $this->subject, '' ) );
	}

	public function test_file_scan_repoll_keeps_the_intermediate_state_closed_without_rescanning() {
		$fixture                                       = new IThemes_Security_V2_Protocol_Fixture();
		$fixture->results['ability_solid_file_scan_v2'] = array(
			'accepted'   => true,
			'completed'  => false,
			'outcome'    => 'accepted',
			'generation' => str_repeat( 'a', 64 ),
		);
		$request                                       = array(
			'protocol'    => '2',
			'operation'   => 'ability_solid_file_scan_v2',
			'request_ref' => '123e4567-e89b-42d3-a456-426614174705',
			'payload'     => array(),
		);

		$accepted = $fixture->abilities_v2( $request );
		$this->assertTrue( $accepted['ok'] );
		$this->assertTrue( $accepted['accepted'] );
		$this->assertFalse( $accepted['completed'] );
		$this->assertSame( 'accepted', $accepted['outcome'] );

		$repoll = $fixture->abilities_v2( $request );

		$this->assertCount( 1, $fixture->calls, 'A repoll must read the running scan, never submit a second one.' );
		$this->assertSame( array( 'protocol', 'operation', 'ok', 'code' ), array_keys( $repoll ) );
		$this->assertFalse( $repoll['ok'] );
		$this->assertSame( 'plugin_unavailable', $repoll['code'] );

		$receipts = get_option( 'mainwp_solid_abilities_v2_receipts', array() );
		$this->assertSame( $accepted, $receipts[ $request['request_ref'] ]['response'] );
	}

	public function test_file_scan_poll_loads_the_file_change_module_before_judging_it_absent() {
		global $mainwp_itsec_modules_path;
		$previous = $mainwp_itsec_modules_path;
		$modules  = get_temp_dir() . uniqid( 'solid-modules-' ) . '/';
		$marker   = $modules . 'loaded.txt';
		mkdir( $modules . 'file-change', 0777, true );
		// Stubs record the load and define nothing, so the poll must still fail closed after requiring them.
		file_put_contents( $modules . 'file-change/scanner.php', '<?php file_put_contents( ' . var_export( $marker, true ) . ", 'scanner', FILE_APPEND );" );
		file_put_contents( $modules . 'file-change/class-itsec-file-change.php', '<?php file_put_contents( ' . var_export( $marker, true ) . ", 'change', FILE_APPEND );" );

		try {
			$mainwp_itsec_modules_path = $modules;
			$result                    = $this->poll_file_scan_method()->invoke( $this->subject );
			$loaded                    = file_exists( $marker ) ? file_get_contents( $marker ) : '';
		} finally {
			$mainwp_itsec_modules_path = $previous;
			array_map( 'unlink', glob( $modules . 'file-change/*' ) );
			array_map( 'unlink', glob( $modules . '*.txt' ) );
			rmdir( $modules . 'file-change' );
			rmdir( $modules );
		}

		$this->assertSame( 'scannerchange', $loaded );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'plugin_unavailable', $result->get_error_code() );
	}

	public function test_file_scan_poll_fails_closed_without_a_module_path_or_module_files() {
		global $mainwp_itsec_modules_path;
		$previous = $mainwp_itsec_modules_path;
		$poll     = $this->poll_file_scan_method();

		try {
			$mainwp_itsec_modules_path = null;
			$unset_path                = $poll->invoke( $this->subject );

			$mainwp_itsec_modules_path = get_temp_dir() . uniqid( 'solid-absent-' ) . '/';
			$missing_files             = $poll->invoke( $this->subject );
		} finally {
			$mainwp_itsec_modules_path = $previous;
		}

		$this->assertInstanceOf( \WP_Error::class, $unset_path );
		$this->assertSame( 'plugin_unavailable', $unset_path->get_error_code() );
		$this->assertInstanceOf( \WP_Error::class, $missing_files );
		$this->assertSame( 'plugin_unavailable', $missing_files->get_error_code() );
	}

	public function test_summary_projection_is_closed_bounded_and_redacted() {
		$method = ( new ReflectionClass( MainWP_Child_IThemes_Security::class ) )->getMethod( 'abilities_v2_summary_response' );
		$method->setAccessible( true );
		$result = $method->invoke(
			$this->subject,
			2,
			5,
			array(
				'status'       => 'clean',
				'completed_at' => '2026-08-10T06:00:00Z',
			),
			true,
			'2026-08-10T06:05:00Z'
		);

		$this->assertSame( array( 'protocol', 'operation', 'ok', 'complete', 'active_lockout_count', 'banned_count', 'latest_scan', 'observed_at', 'source_generation' ), array_keys( $result ) );
		$this->assertTrue( $result['ok'] );
		$this->assertTrue( $result['complete'] );
		$this->assertSame( 2, $result['active_lockout_count'] );
		$this->assertSame( 5, $result['banned_count'] );
		$this->assertSame( array( 'status' => 'clean', 'completed_at' => '2026-08-10T06:00:00Z' ), $result['latest_scan'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $result['source_generation'] );
		$this->assertStringNotContainsString( 'description', wp_json_encode( $result ) );
		$this->assertStringNotContainsString( 'lockout_id', wp_json_encode( $result ) );
	}

	public function test_summary_rejects_nonempty_payload() {
		$result = $this->request( 'ability_solid_summary_v2', array( 'request_ref' => '123e4567-e89b-42d3-a456-426614174401' ) );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'invalid_request', $result['code'] );
	}

	public function test_summary_scan_projection_is_coarse_and_fail_closed() {
		$method = ( new ReflectionClass( MainWP_Child_IThemes_Security::class ) )->getMethod( 'abilities_v2_scan_projection' );
		$method->setAccessible( true );

		$complete = true;
		$issues   = $method->invokeArgs(
			$this->subject,
			array(
				array(
					'status'      => 'issues_found',
					'time'        => '2026-08-10T06:00:00Z',
					'description' => 'secret finding and path',
				),
				&$complete,
			)
		);
		$this->assertTrue( $complete );
		$this->assertSame( array( 'status' => 'issues_found', 'completed_at' => '2026-08-10T06:00:00Z' ), $issues );
		$this->assertStringNotContainsString( 'secret', wp_json_encode( $issues ) );

		$complete = true;
		$unknown  = $method->invokeArgs( $this->subject, array( array( 'status' => 'new-provider-state', 'time' => 1 ), &$complete ) );
		$this->assertFalse( $complete );
		$this->assertSame( 'unknown', $unknown['status'] );

		$complete = true;
		$malformed = $method->invokeArgs( $this->subject, array( array( 'status' => 'clean' ), &$complete ) );
		$this->assertFalse( $complete );
		$this->assertSame( array( 'status' => 'unknown', 'completed_at' => null ), $malformed );
	}

	public function test_temporary_whitelist_read_is_closed_and_redacted() {
		$expires = time() + 3600;
		update_site_option(
			'itsec_temp_whitelist_ip',
			array(
				'ip'  => '192.0.2.10',
				'exp' => $expires,
			)
		);

		$result = $this->request_with_provider( 'ability_solid_whitelist_v2', array() );

		$this->assertSame( array( 'protocol', 'operation', 'ok', 'active', 'expires_at', 'address_family', 'revision' ), array_keys( $result ) );
		$this->assertTrue( $result['ok'] );
		$this->assertTrue( $result['active'] );
		$this->assertSame( gmdate( 'Y-m-d\TH:i:s\Z', $expires ), $result['expires_at'] );
		$this->assertSame( 'ipv4', $result['address_family'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $result['revision'] );
		$this->assertStringNotContainsString( '192.0.2.10', wp_json_encode( $result ) );

		update_site_option(
			'itsec_temp_whitelist_ip',
			array(
				'ip'  => '192.0.2.11',
				'exp' => $expires,
			)
		);
		$changed_address = $this->request_with_provider( 'ability_solid_whitelist_v2', array() );
		$this->assertNotSame( $result['revision'], $changed_address['revision'] );
	}

	public function test_temporary_whitelist_read_handles_absent_and_expired_without_writes() {
		$absent = $this->request_with_provider( 'ability_solid_whitelist_v2', array() );
		$this->assertTrue( $absent['ok'] );
		$this->assertFalse( $absent['active'] );
		$this->assertNull( $absent['expires_at'] );
		$this->assertNull( $absent['address_family'] );

		$expired = array(
			'ip'  => '2001:db8::10',
			'exp' => time() - 60,
		);
		update_site_option( 'itsec_temp_whitelist_ip', $expired );
		$result = $this->request_with_provider( 'ability_solid_whitelist_v2', array() );

		$this->assertTrue( $result['ok'] );
		$this->assertFalse( $result['active'] );
		$this->assertNull( $result['expires_at'] );
		$this->assertNull( $result['address_family'] );
		$this->assertSame( $expired, get_site_option( 'itsec_temp_whitelist_ip' ) );
	}

	public function test_temporary_whitelist_read_rejects_malformed_state_and_payload() {
		update_site_option(
			'itsec_temp_whitelist_ip',
			array(
				'ip'    => '192.0.2.10',
				'exp'   => time() + 3600,
				'extra' => true,
			)
		);

		$result = $this->request_with_provider( 'ability_solid_whitelist_v2', array() );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'invalid_stored_state', $result['code'] );

		// Bare subject: a bad payload must be rejected before the Solid gate can mask it as plugin_unavailable.
		$result = $this->request( 'ability_solid_whitelist_v2', array( 'request_ref' => '123e4567-e89b-42d3-a456-426614174401' ) );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'invalid_request', $result['code'] );
	}

	public function test_file_permissions_are_closed_bounded_and_path_free() {
		$result = $this->request_with_provider( 'ability_solid_file_permissions_v2', array() );

		$this->assertSame( array( 'protocol', 'operation', 'ok', 'targets', 'observed_at' ), array_keys( $result ) );
		$this->assertTrue( $result['ok'] );
		$this->assertCount( 10, $result['targets'] );
		$this->assertSame(
			array( 'wordpress_root', 'wp_includes', 'wp_admin', 'wp_admin_js', 'wp_content', 'themes', 'plugins', 'uploads', 'wp_config', 'server_config' ),
			array_column( $result['targets'], 'target' )
		);

		foreach ( $result['targets'] as $target ) {
			$this->assertSame( array( 'target', 'expected_mode', 'actual_mode', 'status' ), array_keys( $target ) );
			$this->assertMatchesRegularExpression( '/^[0-7]{4}$/', $target['expected_mode'] );
			$this->assertTrue( null === $target['actual_mode'] || 1 === preg_match( '/^[0-7]{4}$/', $target['actual_mode'] ) );
			$this->assertContains( $target['status'], array( 'ok', 'warning', 'missing', 'unreadable' ) );
		}

		$encoded = wp_json_encode( $result );
		$this->assertStringNotContainsString( ABSPATH, $encoded );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $result['observed_at'] );
	}

	public function test_file_permissions_reject_nonempty_payload() {
		$result = $this->request( 'ability_solid_file_permissions_v2', array( 'request_ref' => '123e4567-e89b-42d3-a456-426614174401' ) );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'invalid_request', $result['code'] );
	}

	public function test_permission_projection_distinguishes_missing_warning_ok_and_symlink() {
		$method = ( new ReflectionClass( MainWP_Child_IThemes_Security::class ) )->getMethod( 'abilities_v2_permission_target' );
		$method->setAccessible( true );
		$file = wp_tempnam( 'solid-permissions' );
		$link = $file . '.link';

		try {
			$this->assertSame( 'missing', $method->invoke( $this->subject, 'wp_config', $file . '.missing', '0444' )['status'] );

			chmod( $file, 0600 );
			$this->assertSame( 'warning', $method->invoke( $this->subject, 'wp_config', $file, '0444' )['status'] );

			chmod( $file, 0644 );
			$actual = $method->invoke( $this->subject, 'wp_config', $file, '0644' );
			$this->assertSame( 'ok', $actual['status'] );
			$this->assertSame( '0644', $actual['actual_mode'] );

			$this->assertTrue( symlink( $file, $link ) );
			$this->assertSame( 'unreadable', $method->invoke( $this->subject, 'wp_config', $link, '0444' )['status'] );
		} finally {
			if ( is_link( $link ) ) {
				unlink( $link );
			}
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
	}

	public function test_malformed_unknown_and_alias_requests_fail_closed() {
		$this->assertSame(
			array(
				'protocol'  => '2',
				'operation' => 'unknown',
				'ok'        => false,
				'code'      => 'invalid_request',
			),
			$this->subject->abilities_v2( array() )
		);

		$unknown = $this->request( 'ability_solid_missing_v2', array() );
		$this->assertFalse( $unknown['ok'] );
		$this->assertSame( 'unsupported_operation', $unknown['code'] );

		$alias = $this->subject->abilities_v2(
			array(
				'protocol'   => '2',
				'operation'  => 'capabilities',
				'payload'    => array(),
				'request_id' => '123e4567-e89b-42d3-a456-426614174401',
			)
		);
		$this->assertFalse( $alias['ok'] );
		$this->assertSame( 'invalid_request', $alias['code'] );
	}

	public function test_lockout_read_and_release_are_closed_and_replay_exactly() {
		$fixture = new IThemes_Security_V2_Protocol_Fixture();
		$fixture->results['ability_solid_lockouts_v2'] = array(
			'lockouts' => array( array( 'lockout_ref' => str_repeat( 'a', 64 ), 'kind' => 'host', 'expires_at' => '2026-08-16T13:00:00Z' ) ),
			'next_after_lockout_ref' => null,
			'truncated' => false,
			'revision' => str_repeat( 'b', 64 ),
		);
		$read = $fixture->abilities_v2( array( 'protocol' => '2', 'operation' => 'ability_solid_lockouts_v2', 'payload' => array( 'limit' => 50, 'after_lockout_ref' => null ) ) );
		$this->assertTrue( $read['ok'] );
		$this->assertStringNotContainsString( 'ip', wp_json_encode( $read ) );

		$fixture->results['ability_solid_release_lockouts_v2'] = array(
			'requested_count'      => 1,
			'releasable_count'     => 1,
			'released_count'       => 1,
			'already_absent_count' => 0,
			'failed_count'         => 0,
			'revision'             => str_repeat( 'c', 64 ),
		);
		$request = array(
			'protocol'    => '2',
			'operation'   => 'ability_solid_release_lockouts_v2',
			'request_ref' => '123e4567-e89b-42d3-a456-426614174701',
			'payload'     => array( 'lockout_refs' => array( str_repeat( 'a', 64 ) ), 'if_match' => str_repeat( 'b', 64 ) ),
		);
		$result = $fixture->abilities_v2( $request );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( $result, $fixture->abilities_v2( $request ) );
		$this->assertCount( 2, $fixture->calls );
		$request['payload']['lockout_refs'][] = str_repeat( 'd', 64 );
		$this->assertSame( 'request_conflict', $fixture->abilities_v2( $request )['code'] );
	}

	public function test_mutation_alias_and_malformed_provider_result_fail_closed() {
		$fixture = new IThemes_Security_V2_Protocol_Fixture();
		$fixture->results['ability_solid_backup_v2'] = array( 'accepted' => true, 'completed' => false, 'outcome' => 'accepted', 'generation' => str_repeat( 'a', 64 ), 'extra' => true );
		$request = array( 'protocol' => '2', 'operation' => 'ability_solid_backup_v2', 'request_ref' => '123e4567-e89b-42d3-a456-426614174702', 'payload' => array() );
		$this->assertSame( 'provider_schema_invalid', $fixture->abilities_v2( $request )['code'] );
		unset( $request['request_ref'] );
		$request['request_id'] = '123e4567-e89b-42d3-a456-426614174702';
		$this->assertSame( 'invalid_request', $fixture->abilities_v2( $request )['code'] );
	}

	public function test_production_whitelist_adapter_replaces_state_without_disclosing_address() {
		$fixture  = new IThemes_Security_V2_Production_Adapter_Fixture();
		$current  = $fixture->abilities_v2( array( 'protocol' => '2', 'operation' => 'ability_solid_whitelist_v2', 'payload' => array() ) );
		$request  = array(
			'protocol'    => '2',
			'operation'   => 'ability_solid_replace_whitelist_v2',
			'request_ref' => '123e4567-e89b-42d3-a456-426614174703',
			'payload'     => array(
				'ip_address'  => '192.0.2.44',
				'ttl_seconds' => 3600,
				'if_match'    => $current['revision'],
				'dry_run'     => true,
			),
		);
		$preview = $fixture->abilities_v2( $request );
		$this->assertTrue( $preview['ok'] );
		$this->assertTrue( $preview['changed'] );
		$this->assertFalse( get_site_option( 'itsec_temp_whitelist_ip', false ) );
		$this->assertArrayNotHasKey( $request['request_ref'], get_option( 'mainwp_solid_abilities_v2_receipts', array() ) );

		$request['payload']['dry_run'] = false;
		$response                      = $fixture->abilities_v2( $request );

		$this->assertTrue( $response['ok'] );
		$this->assertTrue( $response['active'] );
		$this->assertSame( 'ipv4', $response['address_family'] );
		$this->assertStringNotContainsString( '192.0.2.44', wp_json_encode( $response ) );
		$this->assertSame( '192.0.2.44', get_site_option( 'itsec_temp_whitelist_ip' )['ip'] );
		$this->assertSame( $response, $fixture->abilities_v2( $request ) );
	}

	public function test_production_log_preview_is_receipt_free_and_confirm_is_replay_safe() {
		global $wpdb;
		$table = $wpdb->base_prefix . 'itsec_log';
		$wpdb->query( 'DROP TABLE IF EXISTS `' . $table . '`' );
		$created = $wpdb->query( 'CREATE TABLE `' . $table . '` (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, payload text NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB' );
		$this->assertNotFalse( $created, $wpdb->last_error );
		$wpdb->insert( $table, array( 'payload' => 'private-a' ), array( '%s' ) );
		$wpdb->insert( $table, array( 'payload' => 'private-b' ), array( '%s' ) );

		try {
			$fixture = new IThemes_Security_V2_Production_Adapter_Fixture();
			$root    = array(
				'protocol'    => '2',
				'operation'   => 'ability_solid_clear_logs_v2',
				'request_ref' => '123e4567-e89b-42d3-a456-426614174704',
			);
			$preview = $fixture->abilities_v2( $root + array( 'payload' => array( 'dry_run' => true ) ) );
			$this->assertTrue( $preview['ok'], wp_json_encode( $preview ) );
			$this->assertSame( 2, $preview['rows_before'] );
			$this->assertSame( 0, $preview['rows_deleted'] );
			$this->assertSame( 2, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $table . '`' ) );
			$this->assertSame( array(), get_option( 'mainwp_solid_abilities_v2_receipts', array() ) );

			$confirm = $fixture->abilities_v2( $root + array( 'payload' => array( 'dry_run' => false ) ) );
			$this->assertTrue( $confirm['ok'] );
			$this->assertSame( 2, $confirm['rows_deleted'] );
			$this->assertSame( 0, $confirm['rows_after'] );
			$this->assertSame( $confirm, $fixture->abilities_v2( $root + array( 'payload' => array( 'dry_run' => false ) ) ) );
			$this->assertStringNotContainsString( 'private-a', wp_json_encode( $confirm ) );
		} finally {
			$wpdb->query( 'DROP TABLE IF EXISTS `' . $table . '`' );
		}
	}

	public function test_reordered_envelope_is_accepted_but_extra_payload_is_not() {
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

		$unknown = $this->request( 'ability_solid_missing_v2', array() );
		$this->assertSame( 'unsupported_operation', $unknown['code'] );
	}

	/**
	 * The receipt store is what stops a repeated request from running the same mutation twice, so a
	 * full store must refuse the new mutation rather than evict an accepted scan nobody has an
	 * answer for yet.
	 */
	public function test_a_full_receipt_store_refuses_a_mutation_instead_of_evicting_an_unconfirmed_one() {
		$scan_ref                                      = '123e4567-e89b-42d3-a456-426614174800';
		$fixture                                       = new IThemes_Security_V2_Protocol_Fixture();
		$fixture->results['ability_solid_file_scan_v2'] = array(
			'accepted'   => true,
			'completed'  => false,
			'outcome'    => 'accepted',
			'generation' => str_repeat( 'a', 64 ),
		);
		$fixture->results['ability_solid_release_lockouts_v2'] = array(
			'requested_count'      => 1,
			'releasable_count'     => 1,
			'released_count'       => 1,
			'already_absent_count' => 0,
			'failed_count'         => 0,
			'revision'             => str_repeat( 'c', 64 ),
		);
		// The accepted scan is the oldest entry, so a store that makes room by force drops exactly it.
		$receipts = array( $scan_ref => $this->accepted_scan_receipt( $scan_ref, time() - ( 3 * DAY_IN_SECONDS ) ) );
		for ( $index = 1; $index < 100; $index++ ) {
			$ref              = sprintf( '123e4567-e89b-42d3-a456-%012d', $index );
			$receipts[ $ref ] = $this->completed_scan_receipt( $ref, time() );
		}
		update_option( 'mainwp_solid_abilities_v2_receipts', $receipts, false );

		$refused = $fixture->abilities_v2(
			array(
				'protocol'    => '2',
				'operation'   => 'ability_solid_release_lockouts_v2',
				'request_ref' => '123e4567-e89b-42d3-a456-426614174801',
				'payload'     => array(
					'lockout_refs' => array( str_repeat( 'a', 64 ) ),
					'if_match'     => str_repeat( 'b', 64 ),
				),
			)
		);

		$this->assertFalse( $refused['ok'] );
		$this->assertSame( 'storage_unavailable', $refused['code'] );
		$this->assertSame( array(), $fixture->calls, 'A mutation the Child cannot record must not reach the provider.' );
		$this->assertArrayHasKey( $scan_ref, get_option( 'mainwp_solid_abilities_v2_receipts', array() ) );

		$repoll = $fixture->abilities_v2(
			array(
				'protocol'    => '2',
				'operation'   => 'ability_solid_file_scan_v2',
				'request_ref' => $scan_ref,
				'payload'     => array(),
			)
		);

		$this->assertSame( array(), $fixture->calls, 'The accepted scan still has its receipt, so retrying it must not submit a second scan.' );
		$this->assertFalse( $repoll['ok'] );
		$this->assertSame( 'plugin_unavailable', $repoll['code'] );
	}

	/**
	 * Receipts past the retry horizon are the ones eviction is allowed to take, so the store cannot lock shut.
	 *
	 * The unconfirmed scan is deliberately the first entry: insertion order must not decide what
	 * goes, or making room would drop the one receipt the Dashboard is still waiting on.
	 */
	public function test_receipts_past_the_retry_horizon_make_room_for_a_new_mutation() {
		$fixture = new IThemes_Security_V2_Protocol_Fixture();
		$fixture->results['ability_solid_release_lockouts_v2'] = array(
			'requested_count'      => 1,
			'releasable_count'     => 1,
			'released_count'       => 1,
			'already_absent_count' => 0,
			'failed_count'         => 0,
			'revision'             => str_repeat( 'c', 64 ),
		);
		$scan_ref         = '123e4567-e89b-42d3-a456-426614174803';
		$oldest_evictable = sprintf( '123e4567-e89b-42d3-a456-%012d', 1 );
		$receipts         = array( $scan_ref => $this->accepted_scan_receipt( $scan_ref, time() ) );
		for ( $index = 1; $index < 100; $index++ ) {
			$ref              = sprintf( '123e4567-e89b-42d3-a456-%012d', $index );
			$receipts[ $ref ] = $this->completed_scan_receipt( $ref, time() - ( 2 * DAY_IN_SECONDS ) - ( 101 - $index ) );
		}
		update_option( 'mainwp_solid_abilities_v2_receipts', $receipts, false );

		$new_ref = '123e4567-e89b-42d3-a456-426614174802';
		$result  = $fixture->abilities_v2(
			array(
				'protocol'    => '2',
				'operation'   => 'ability_solid_release_lockouts_v2',
				'request_ref' => $new_ref,
				'payload'     => array(
					'lockout_refs' => array( str_repeat( 'a', 64 ) ),
					'if_match'     => str_repeat( 'b', 64 ),
				),
			)
		);
		$stored  = get_option( 'mainwp_solid_abilities_v2_receipts', array() );

		$this->assertTrue( $result['ok'] );
		$this->assertCount( 1, $fixture->calls );
		$this->assertCount( 100, $stored );
		$this->assertArrayHasKey( $new_ref, $stored );
		$this->assertArrayHasKey( $scan_ref, $stored, 'The unconfirmed scan is not the receipt eviction may take, whatever its position in the store.' );
		$this->assertArrayNotHasKey( $oldest_evictable, $stored, 'The oldest receipt past the horizon is the one that goes.' );
	}

	/**
	 * Entries that are not readable receipts must never be mistaken for protected open scans.
	 *
	 * A stored option is untrusted input, and an entry whose response merely looks accepted carries
	 * no reference binding of its own. Protecting those would refuse every future mutation on the
	 * site until someone repaired the option by hand.
	 */
	public function test_unreadable_receipts_never_hold_the_mutation_store_shut() {
		$fixture = new IThemes_Security_V2_Protocol_Fixture();
		$fixture->results['ability_solid_release_lockouts_v2'] = array(
			'requested_count'      => 1,
			'releasable_count'     => 1,
			'released_count'       => 1,
			'already_absent_count' => 0,
			'failed_count'         => 0,
			'revision'             => str_repeat( 'c', 64 ),
		);
		$receipts = array();
		for ( $index = 1; $index <= 100; $index++ ) {
			// No effect_hash, no created_at, no operation or reference binding: only the three
			// fields that make abilities_v2_receipt_awaits_completion() say "still open".
			$receipts[ sprintf( '123e4567-e89b-42d3-a456-%012d', $index ) ] = array(
				'response' => array(
					'accepted'  => true,
					'completed' => false,
					'outcome'   => 'accepted',
				),
			);
		}
		update_option( 'mainwp_solid_abilities_v2_receipts', $receipts, false );

		$new_ref = '123e4567-e89b-42d3-a456-426614174804';
		$result  = $fixture->abilities_v2(
			array(
				'protocol'    => '2',
				'operation'   => 'ability_solid_release_lockouts_v2',
				'request_ref' => $new_ref,
				'payload'     => array(
					'lockout_refs' => array( str_repeat( 'a', 64 ) ),
					'if_match'     => str_repeat( 'b', 64 ),
				),
			)
		);
		$stored  = get_option( 'mainwp_solid_abilities_v2_receipts', array() );

		$this->assertTrue( $result['ok'], wp_json_encode( $result ) );
		$this->assertCount( 1, $fixture->calls );
		$this->assertArrayHasKey( $new_ref, $stored );
		$this->assertLessThanOrEqual( 100, count( $stored ) );
	}

	/**
	 * A store full of receipts stamped in the future must not lock every mutation out.
	 *
	 * Option data is untrusted input, so an impossible timestamp is not a young receipt: nothing may
	 * read age from it, and eviction has to treat it like any other entry it cannot use. These scans
	 * are completed, so the deliberate refusal that protects open scans has no part in this.
	 */
	public function test_future_dated_receipts_never_hold_the_mutation_store_shut() {
		$fixture = new IThemes_Security_V2_Protocol_Fixture();
		$fixture->results['ability_solid_release_lockouts_v2'] = array(
			'requested_count'      => 1,
			'releasable_count'     => 1,
			'released_count'       => 1,
			'already_absent_count' => 0,
			'failed_count'         => 0,
			'revision'             => str_repeat( 'c', 64 ),
		);
		$receipts = array();
		for ( $index = 1; $index <= 100; $index++ ) {
			$ref              = sprintf( '123e4567-e89b-42d3-a456-%012d', $index );
			$receipts[ $ref ] = $this->completed_scan_receipt( $ref, time() + ( 10 * YEAR_IN_SECONDS ) );
		}
		update_option( 'mainwp_solid_abilities_v2_receipts', $receipts, false );

		$new_ref = '123e4567-e89b-42d3-a456-426614174805';
		$result  = $fixture->abilities_v2(
			array(
				'protocol'    => '2',
				'operation'   => 'ability_solid_release_lockouts_v2',
				'request_ref' => $new_ref,
				'payload'     => array(
					'lockout_refs' => array( str_repeat( 'a', 64 ) ),
					'if_match'     => str_repeat( 'b', 64 ),
				),
			)
		);
		$stored  = get_option( 'mainwp_solid_abilities_v2_receipts', array() );

		$this->assertTrue( $result['ok'], wp_json_encode( $result ) );
		$this->assertCount( 1, $fixture->calls );
		$this->assertArrayHasKey( $new_ref, $stored );
		$this->assertLessThanOrEqual( 100, count( $stored ) );
	}

	/** A receipt stamped in the future is not an outcome the Child may hand back as this request's answer. */
	public function test_a_future_dated_receipt_is_refused_instead_of_replayed() {
		$fixture = new IThemes_Security_V2_Protocol_Fixture();
		$ref     = '123e4567-e89b-42d3-a456-426614174806';
		update_option( 'mainwp_solid_abilities_v2_receipts', array( $ref => $this->completed_scan_receipt( $ref, time() + YEAR_IN_SECONDS ) ), false );

		$result = $fixture->abilities_v2(
			array(
				'protocol'    => '2',
				'operation'   => 'ability_solid_file_scan_v2',
				'request_ref' => $ref,
				'payload'     => array(),
			)
		);

		$this->assertFalse( $result['ok'], wp_json_encode( $result ) );
		$this->assertSame( 'storage_unavailable', $result['code'] );
		$this->assertSame( array(), $fixture->calls, 'An unusable receipt is not a reason to run the scan again either.' );
	}

	/**
	 * @param string $request_ref Request reference.
	 * @param int    $created_at  Receipt age.
	 * @return array
	 */
	private function accepted_scan_receipt( $request_ref, $created_at ) {
		return $this->scan_receipt( $request_ref, $created_at, false, 'accepted' );
	}

	/**
	 * @param string $request_ref Request reference.
	 * @param int    $created_at  Receipt age.
	 * @return array
	 */
	private function completed_scan_receipt( $request_ref, $created_at ) {
		return $this->scan_receipt( $request_ref, $created_at, true, 'clean' );
	}

	/**
	 * @param string $request_ref Request reference.
	 * @param int    $created_at  Receipt age.
	 * @param bool   $completed   Whether the scan finished.
	 * @param string $outcome     Recorded outcome.
	 * @return array
	 */
	private function scan_receipt( $request_ref, $created_at, $completed, $outcome ) {
		return array(
			'effect_hash' => hash( 'sha256', wp_json_encode( array( 'ability_solid_file_scan_v2', array() ) ) ),
			'created_at'  => $created_at,
			'response'    => array(
				'protocol'    => '2',
				'operation'   => 'ability_solid_file_scan_v2',
				'ok'          => true,
				'request_ref' => $request_ref,
				'accepted'    => true,
				'completed'   => $completed,
				'outcome'     => $outcome,
				'generation'  => str_repeat( 'a', 64 ),
			),
		);
	}

	private function action_response_method() {
		$method = ( new ReflectionClass( MainWP_Child_IThemes_Security::class ) )->getMethod( 'abilities_v2_action_response' );
		$method->setAccessible( true );

		return $method;
	}

	private function poll_file_scan_method() {
		$method = ( new ReflectionClass( MainWP_Child_IThemes_Security::class ) )->getMethod( 'abilities_v2_poll_file_scan' );
		$method->setAccessible( true );

		return $method;
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

	/** Dispatch past the Solid availability gate, for reads whose subject is the projection rather than the gate. */
	private function request_with_provider( $operation, $payload ) {
		$fixture = new IThemes_Security_V2_Production_Adapter_Fixture();

		return $fixture->abilities_v2(
			array(
				'protocol'  => '2',
				'operation' => $operation,
				'payload'   => $payload,
			)
		);
	}
}
