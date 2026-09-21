<?php
/**
 * Jetpack Scan abilities-v2 negotiation tests.
 *
 * @package MainWP_Child
 */

namespace MainWP\Child;

use ReflectionClass;
use WP_UnitTestCase;

class Test_MainWP_Child_Jetpack_Scan_V2 extends WP_UnitTestCase {

	/** @var MainWP_Child_Jetpack_Scan */
	private $subject;

	public function set_up(): void {
		parent::set_up();
		$reflection    = new ReflectionClass( MainWP_Child_Jetpack_Scan::class );
		$this->subject = $reflection->newInstanceWithoutConstructor();
	}

	public function test_capabilities_are_closed_and_claim_no_unimplemented_operation() {
		$result = $this->request( 'capabilities', array() );

		$this->assertSame( array( 'protocol', 'operation', 'ok', 'operations', 'mutation_supported' ), array_keys( $result ) );
		$this->assertSame( '2', $result['protocol'] );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( array( 'visibility_get', 'visibility_set' ), $result['operations'] );
		$this->assertTrue( $result['mutation_supported'] );
	}

	public function test_visibility_get_returns_closed_active_state() {
		$this->subject->is_plugin_installed = true;
		delete_option( 'mainwp_child_jetpack_scan_hide_plugin' );

		$result = $this->request( 'visibility_get', array() );

		$this->assertSame(
			array( 'protocol', 'operation', 'ok', 'plugin_state', 'visibility', 'revision', 'observed_at' ),
			array_keys( $result )
		);
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'active', $result['plugin_state'] );
		$this->assertSame( 'visible', $result['visibility'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $result['revision'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $result['observed_at'] );
	}

	public function test_visibility_get_normalizes_hidden_missing_and_malformed_state() {
		$this->subject->is_plugin_installed = true;
		update_option( 'mainwp_child_jetpack_scan_hide_plugin', 'hide' );
		update_option( 'mainwp_child_jetpack_protect_hide_plugin', 'hide' );
		$hidden = $this->request( 'visibility_get', array() );
		$this->assertSame( 'hidden', $hidden['visibility'] );

		update_option( 'mainwp_child_jetpack_scan_hide_plugin', array( 'hide' ) );
		$malformed = $this->request( 'visibility_get', array() );
		$this->assertSame( 'unknown', $malformed['visibility'] );

		$this->subject->is_plugin_installed = false;
		$missing                            = $this->request( 'visibility_get', array() );
		$this->assertContains( $missing['plugin_state'], array( 'inactive', 'missing' ) );
		$this->assertSame( 'unknown', $missing['visibility'] );
	}

	public function test_visibility_get_rejects_nonempty_payload() {
		$result = $this->request( 'visibility_get', array( 'request_ref' => '123e4567-e89b-42d3-a456-426614174507' ) );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'invalid_request', $result['code'] );
	}

	public function test_visibility_set_is_revision_bound_and_readback_verified() {
		$this->subject->is_plugin_installed = true;
		delete_option( 'mainwp_child_jetpack_scan_hide_plugin' );
		$current = $this->request( 'visibility_get', array() );

		$result = $this->request(
			'visibility_set',
			array(
				'desired_state' => 'hidden',
				'if_match'      => $current['revision'],
			)
		);

		$this->assertSame(
			array( 'protocol', 'operation', 'ok', 'plugin_state', 'visibility', 'revision', 'observed_at', 'changed' ),
			array_keys( $result )
		);
		$this->assertTrue( $result['ok'] );
		$this->assertTrue( $result['changed'] );
		$this->assertSame( 'hidden', $result['visibility'] );
		$this->assertSame( 'hide', get_option( 'mainwp_child_jetpack_scan_hide_plugin' ) );
		$this->assertSame( hash( 'sha256', 'active|hidden|hide|hide' ), $result['revision'] );

		$replay = $this->request(
			'visibility_set',
			array(
				'desired_state' => 'hidden',
				'if_match'      => $result['revision'],
			)
		);
		$this->assertTrue( $replay['ok'] );
		$this->assertFalse( $replay['changed'] );
	}

	public function test_visibility_set_rejects_stale_malformed_and_unavailable_state_without_writing() {
		$this->subject->is_plugin_installed = true;
		update_option( 'mainwp_child_jetpack_scan_hide_plugin', 'show' );

		$stale = $this->request(
			'visibility_set',
			array(
				'desired_state' => 'hidden',
				'if_match'      => str_repeat( 'a', 64 ),
			)
		);
		$this->assertFalse( $stale['ok'] );
		$this->assertSame( 'stale_revision', $stale['code'] );
		$this->assertSame( 'show', get_option( 'mainwp_child_jetpack_scan_hide_plugin' ) );

		foreach (
			array(
				array(),
				array( 'desired_state' => 'hidden', 'if_match' => str_repeat( 'a', 63 ) ),
				array( 'desired_state' => 'unknown', 'if_match' => str_repeat( 'a', 64 ) ),
				array( 'desired_state' => 'hidden', 'if_match' => str_repeat( 'a', 64 ), 'extra' => true ),
			) as $payload
		) {
			$invalid = $this->request( 'visibility_set', $payload );
			$this->assertFalse( $invalid['ok'] );
			$this->assertSame( 'invalid_request', $invalid['code'] );
		}
		$this->assertSame( 'show', get_option( 'mainwp_child_jetpack_scan_hide_plugin' ) );

		$this->subject->is_plugin_installed = false;
		$missing                            = $this->request(
			'visibility_set',
			array(
				'desired_state' => 'hidden',
				'if_match'      => hash( 'sha256', 'missing|unknown' ),
			)
		);
		$this->assertFalse( $missing['ok'] );
		$this->assertSame( 'unsupported_version', $missing['code'] );
		$this->assertSame( 'show', get_option( 'mainwp_child_jetpack_scan_hide_plugin' ) );
	}

	public function test_visibility_set_reports_write_failure_and_restores_contradictory_readback() {
		$this->subject->is_plugin_installed = true;
		update_option( 'mainwp_child_jetpack_scan_hide_plugin', 'show' );
		// Seeded, not absent: MainWP_Helper::update_option() tries add_option() first, which no
		// pre_update_option filter can block, so only an existing option has a blockable write.
		update_option( 'mainwp_child_jetpack_protect_hide_plugin', 'show' );
		$current = $this->request( 'visibility_get', array() );
		// Both writes must be blocked: a hide that lands on either option genuinely hides the
		// plugin, so one blocked write and one successful one is a success, not a failure.
		$block = static function ( $value, $old_value ) {
			return $old_value;
		};
		add_filter( 'pre_update_option_mainwp_child_jetpack_scan_hide_plugin', $block, 10, 2 );
		add_filter( 'pre_update_option_mainwp_child_jetpack_protect_hide_plugin', $block, 10, 2 );
		$failed = $this->request(
			'visibility_set',
			array(
				'desired_state' => 'hidden',
				'if_match'      => $current['revision'],
			)
		);
		remove_filter( 'pre_update_option_mainwp_child_jetpack_scan_hide_plugin', $block, 10 );
		remove_filter( 'pre_update_option_mainwp_child_jetpack_protect_hide_plugin', $block, 10 );
		$this->assertFalse( $failed['ok'] );
		$this->assertSame( 'write_failed', $failed['code'] );
		$this->assertSame( 'show', get_option( 'mainwp_child_jetpack_scan_hide_plugin' ) );
		$this->assertSame( 'show', get_option( 'mainwp_child_jetpack_protect_hide_plugin' ) );

		$contradict_scan    = null;
		$contradict_scan    = static function ( $value ) use ( &$contradict_scan ) {
			if ( 'hide' === $value ) {
				remove_filter( 'option_mainwp_child_jetpack_scan_hide_plugin', $contradict_scan );
				return 'show';
			}
			return $value;
		};
		$contradict_protect = null;
		$contradict_protect = static function ( $value ) use ( &$contradict_protect ) {
			if ( 'hide' === $value ) {
				remove_filter( 'option_mainwp_child_jetpack_protect_hide_plugin', $contradict_protect );
				return 'show';
			}
			return $value;
		};
		add_filter( 'option_mainwp_child_jetpack_scan_hide_plugin', $contradict_scan );
		add_filter( 'option_mainwp_child_jetpack_protect_hide_plugin', $contradict_protect );
		$unknown = $this->request(
			'visibility_set',
			array(
				'desired_state' => 'hidden',
				'if_match'      => $current['revision'],
			)
		);
		remove_filter( 'option_mainwp_child_jetpack_scan_hide_plugin', $contradict_scan );
		remove_filter( 'option_mainwp_child_jetpack_protect_hide_plugin', $contradict_protect );
		$this->assertFalse( $unknown['ok'] );
		$this->assertSame( 'contradictory_readback', $unknown['code'] );
		$this->assertSame( 'show', get_option( 'mainwp_child_jetpack_scan_hide_plugin' ) );
		$this->assertSame( 'show', get_option( 'mainwp_child_jetpack_protect_hide_plugin' ) );
	}

	/**
	 * The plugin is hidden when EITHER hide option fires: this class reads the scan option and
	 * MainWP_Child_Jetpack_Protect reads the protect option, each behind its own all_plugins
	 * filter. The legacy set_showhide() writes both, so the ability writing one leaves a state
	 * where the ability's answer and the admin screen disagree.
	 */
	public function test_visibility_set_writes_both_hide_options_like_the_legacy_action() {
		$this->subject->is_plugin_installed = true;
		update_option( 'mainwp_child_jetpack_scan_hide_plugin', 'hide' );
		update_option( 'mainwp_child_jetpack_protect_hide_plugin', 'hide' );

		$current = $this->request( 'visibility_get', array() );
		$this->assertSame( 'hidden', $current['visibility'] );

		$result = $this->request(
			'visibility_set',
			array(
				'desired_state' => 'visible',
				'if_match'      => $current['revision'],
			)
		);

		$this->assertTrue( $result['ok'] );
		$this->assertTrue( $result['changed'] );
		$this->assertSame( 'visible', $result['visibility'] );
		$this->assertSame( 'show', get_option( 'mainwp_child_jetpack_scan_hide_plugin' ) );
		$this->assertSame( 'show', get_option( 'mainwp_child_jetpack_protect_hide_plugin' ) );

		$hide = $this->request(
			'visibility_set',
			array(
				'desired_state' => 'hidden',
				'if_match'      => $result['revision'],
			)
		);

		$this->assertTrue( $hide['ok'] );
		$this->assertSame( 'hide', get_option( 'mainwp_child_jetpack_scan_hide_plugin' ) );
		$this->assertSame( 'hide', get_option( 'mainwp_child_jetpack_protect_hide_plugin' ) );
	}

	/**
	 * The two options hide different plugin rows (scan hides 'jetpack', protect hides
	 * 'jetpack-protect'), so no single row's state follows from one option. Visibility reports
	 * the toggle pair the legacy set_showhide() flips as one switch, and refuses a definite
	 * answer on a pair it cannot attest: mixed or malformed is unknown, never a guess a plugin
	 * row contradicts.
	 */
	public function test_visibility_get_reports_only_the_toggle_pair_it_can_attest() {
		$this->subject->is_plugin_installed = true;

		update_option( 'mainwp_child_jetpack_scan_hide_plugin', 'show' );
		update_option( 'mainwp_child_jetpack_protect_hide_plugin', 'hide' );
		$mixed = $this->request( 'visibility_get', array() );
		$this->assertSame( 'unknown', $mixed['visibility'] );

		update_option( 'mainwp_child_jetpack_scan_hide_plugin', 'hide' );
		$both_hidden = $this->request( 'visibility_get', array() );
		$this->assertSame( 'hidden', $both_hidden['visibility'] );

		update_option( 'mainwp_child_jetpack_protect_hide_plugin', array( 'hide' ) );
		$malformed = $this->request( 'visibility_get', array() );
		$this->assertSame( 'unknown', $malformed['visibility'] );

		update_option( 'mainwp_child_jetpack_scan_hide_plugin', 'show' );
		delete_option( 'mainwp_child_jetpack_protect_hide_plugin' );
		$clean = $this->request( 'visibility_get', array() );
		$this->assertSame( 'visible', $clean['visibility'] );
	}

	/**
	 * The revision must cover both raw toggles, not only the derived visibility: other writers
	 * (the legacy actions, the protect class's own ability) can move the pair to a different
	 * state with the same derived string, and a set holding the older revision would silently
	 * erase those newer writes.
	 */
	public function test_visibility_set_rejects_a_revision_from_before_another_writers_change() {
		$this->subject->is_plugin_installed = true;
		update_option( 'mainwp_child_jetpack_scan_hide_plugin', 'hide' );
		update_option( 'mainwp_child_jetpack_protect_hide_plugin', 'show' );
		$before = $this->request( 'visibility_get', array() );
		$this->assertSame( 'unknown', $before['visibility'] );

		// Another writer swaps the pair; the derived visibility is still 'unknown'.
		update_option( 'mainwp_child_jetpack_scan_hide_plugin', 'show' );
		update_option( 'mainwp_child_jetpack_protect_hide_plugin', 'hide' );

		$stale = $this->request(
			'visibility_set',
			array(
				'desired_state' => 'visible',
				'if_match'      => $before['revision'],
			)
		);

		$this->assertFalse( $stale['ok'] );
		$this->assertSame( 'stale_revision', $stale['code'] );
		$this->assertSame( 'show', get_option( 'mainwp_child_jetpack_scan_hide_plugin' ) );
		$this->assertSame( 'hide', get_option( 'mainwp_child_jetpack_protect_hide_plugin' ) );
	}

	/**
	 * A mixed pair is a state the legacy actions can produce, and the converging write is the
	 * only escape this ability offers from it: refusing 'unknown' outright would leave the
	 * Dashboard no move but the legacy action the mixed state came from.
	 */
	public function test_visibility_set_recovers_a_mixed_toggle_pair() {
		$this->subject->is_plugin_installed = true;
		update_option( 'mainwp_child_jetpack_scan_hide_plugin', 'show' );
		update_option( 'mainwp_child_jetpack_protect_hide_plugin', 'hide' );
		$mixed = $this->request( 'visibility_get', array() );
		$this->assertSame( 'unknown', $mixed['visibility'] );

		$result = $this->request(
			'visibility_set',
			array(
				'desired_state' => 'hidden',
				'if_match'      => $mixed['revision'],
			)
		);

		$this->assertTrue( $result['ok'] );
		$this->assertTrue( $result['changed'] );
		$this->assertSame( 'hidden', $result['visibility'] );
		$this->assertSame( 'hide', get_option( 'mainwp_child_jetpack_scan_hide_plugin' ) );
		$this->assertSame( 'hide', get_option( 'mainwp_child_jetpack_protect_hide_plugin' ) );
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

		$unknown = $this->request( 'visibility_set', array() );
		$this->assertFalse( $unknown['ok'] );
		$this->assertSame( 'invalid_request', $unknown['code'] );

		$alias = $this->subject->abilities_v2(
			array(
				'protocol'   => '2',
				'operation'  => 'capabilities',
				'payload'    => array(),
				'request_id' => '123e4567-e89b-42d3-a456-426614174507',
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

		$unknown = $this->request( 'jetpack_scan_future_v2', array() );
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
}
