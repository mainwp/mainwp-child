<?php
/**
 * Wordfence abilities-v2 protocol tests.
 *
 * @package MainWP_Child
 */

namespace MainWP\Child;

use ReflectionClass;
use WP_UnitTestCase;

class Wordfence_V2_Protocol_Fixture extends MainWP_Child_Wordfence {

	/** @var array */
	public $results = array();

	/** @var array */
	public $calls = array();

	/** @var string|null IS_FREE_LOCK() observed while the provider ran. */
	public $lock_free_during_dispatch = null;

	/** @var array Receipt store as it stood while the provider ran. */
	public $receipts_during_dispatch = array();

	protected function abilities_v2_provider_supports_mutation() {
		return true;
	}

	protected function abilities_v2_provider_operation( $operation, $payload, $request_ref ) {
		global $wpdb;
		$this->calls[]                   = array( $operation, $payload, $request_ref );
		$this->lock_free_during_dispatch = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', $this->abilities_v2_lock_name() ) );
		$this->receipts_during_dispatch  = get_option( 'mainwp_wordfence_abilities_v2_receipts', array() );
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
class Wordfence_Lock_Wpdb_Stub {

	/** @var array Queries this substitute was asked to run. */
	public $queries = array();

	/** @var string */
	public $last_error = '';

	/** @var array One array( error, result ) per expected release attempt. */
	private $answers;

	public function __construct( $answers ) {
		$this->answers = $answers;
	}

	public function prepare( $query, ...$args ) {
		return $query;
	}

	public function get_var( $query ) {
		$this->queries[]  = $query;
		$answer           = array_shift( $this->answers );
		$this->last_error = $answer[0];
		return $answer[1];
	}
}

/** Stand-in for the Wordfence issue store the file-deletion paths construct directly. */
class Wordfence_Issue_Store_Stub {

	/** @var array */
	public static $issues = array();

	public function getIssueByID( $id ) {
		return isset( self::$issues[ $id ] ) ? self::$issues[ $id ] : false;
	}

	public function updateIssue( $id, $operation ) {
		unset( $id, $operation );
	}
}

/** Stand-in for the Wordfence facade the repair paths read original file content from. */
class Wordfence_Facade_Stub {

	public static function getWPFileContent( $file, $c_type, $c_name = '', $c_version = '' ) {
		unset( $file, $c_type, $c_name, $c_version );
		return array(
			'cerrorMsg'   => '',
			'fileContent' => 'original contents',
		);
	}
}

/** Stand-in for the Wordfence cache helper the .htaccess paths ask for a path. */
class Wordfence_Cache_Stub {

	/** @var string Path the Child will try to open for writing. */
	public static $htaccess_path = '';

	public static function getHtaccessPath() {
		return self::$htaccess_path;
	}

	public static function getHtaccessCode() {
		return 'htaccess-code';
	}
}

/** Stand-in for the Wordfence server-detection helper. */
class Wordfence_Utils_Stub {

	public static function isNginx() {
		return false;
	}
}

// Wordfence is not installed in the harness, so the global names the Child constructs are aliased
// rather than declared - this file cannot open a global namespace block without being rewritten.
if ( ! class_exists( '\wfIssues', false ) ) {
	class_alias( Wordfence_Issue_Store_Stub::class, 'wfIssues' );
}
if ( ! class_exists( '\wordfence', false ) ) {
	class_alias( Wordfence_Facade_Stub::class, 'wordfence' );
}
if ( ! class_exists( '\wfCache', false ) ) {
	class_alias( Wordfence_Cache_Stub::class, 'wfCache' );
}
if ( ! class_exists( '\wfUtils', false ) ) {
	class_alias( Wordfence_Utils_Stub::class, 'wfUtils' );
}

class Test_MainWP_Child_Wordfence_V2 extends WP_UnitTestCase {

	/** Web-root file the deletion paths are pointed at and must leave alone. */
	const VICTIM = 'mainwp-wordfence-delete-probe.php';

	/** Substring that only the earlier, unrelated warning can contribute to a message. */
	const STALE_MARKER = 'mainwp-unrelated-earlier-warning';

	/** Web-root directory the write paths are pointed at, so every fopen() for writing fails. */
	const PROBE_DIR = 'mainwp-wordfence-write-probe';

	/** @var MainWP_Child_Wordfence */
	private $subject;

	public function set_up(): void {
		parent::set_up();
		delete_option( 'mainwp_wordfence_abilities_v2_receipts' );
		$reflection    = new ReflectionClass( MainWP_Child_Wordfence::class );
		$this->subject = $reflection->newInstanceWithoutConstructor();
	}

	public function tear_down(): void {
		delete_option( 'mainwp_wordfence_abilities_v2_receipts' );
		parent::tear_down();
	}

	public function test_capabilities_are_closed_and_claim_no_unimplemented_operation() {
		$result = $this->request( 'capabilities', array() );

		$this->assertSame( array( 'protocol', 'operation', 'ok', 'operations', 'mutation_supported' ), array_keys( $result ) );
		$this->assertSame( array( 'site_v2', 'scan_v2_status', 'findings_v2', 'firewall_v2_get', 'blocks_v2_list', 'operation_v2_status', 'file_v2_prepare', 'scan_v2_start', 'scan_v2_cancel', 'finding_v2_classify', 'file_v2_repair', 'firewall_v2_replace', 'blocks_v2_replace' ), $result['operations'] );
		$this->assertFalse( $result['mutation_supported'] );
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

		$result = $this->request( 'site_v2', array() );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'provider_unavailable', $result['code'] );
	}

	public function test_typed_blocks_replace_uses_request_ref_and_exact_replay() {
		$fixture = new Wordfence_V2_Protocol_Fixture();
		$fixture->results['blocks_v2_replace'] = array(
			'operation_ref'   => str_repeat( 'a', 64 ),
			'state'           => 'completed',
			'added'           => 1,
			'removed'         => 0,
			'management_probe'=> 'safe',
			'generation'      => str_repeat( 'b', 64 ),
		);
		$request = array(
			'protocol'    => '2',
			'operation'   => 'blocks_v2_replace',
			'request_ref' => '123e4567-e89b-42d3-a456-426614174601',
			'payload'     => array(
				'if_match' => str_repeat( 'c', 64 ),
				'blocks'   => array( array( 'kind' => 'cidr', 'value' => '203.0.113.0/24', 'reason' => 'abuse', 'expires_at' => null ) ),
			),
		);

		$result = $fixture->abilities_v2( $request );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( $request['request_ref'], $result['request_ref'] );
		$this->assertSame( $result, $fixture->abilities_v2( $request ) );
		$this->assertCount( 1, $fixture->calls );

		$request['payload']['blocks'][0]['reason'] = 'changed';
		$this->assertSame( 'request_conflict', $fixture->abilities_v2( $request )['code'] );
		unset( $request['request_ref'] );
		$request['request_id'] = '123e4567-e89b-42d3-a456-426614174602';
		$this->assertSame( 'invalid_request', $fixture->abilities_v2( $request )['code'] );
	}

	/**
	 * The record a retry lands on exists before the provider runs, not after it returns.
	 *
	 * Between dispatch and the outcome being written there is a window in which the request can
	 * die outright. Without a reservation in the store the Dashboard's retry of the same
	 * reference finds nothing and repairs or blocks a second time.
	 */
	public function test_a_reservation_is_durable_before_the_provider_runs() {
		$fixture = $this->blocks_fixture();
		$request = $this->blocks_request( '123e4567-e89b-42d3-a456-426614174610' );

		$result = $fixture->abilities_v2( $request );

		$this->assertTrue( $result['ok'] );
		$this->assertArrayHasKey( $request['request_ref'], $fixture->receipts_during_dispatch, 'the provider must not run before its reference is recorded' );
		$reserved = $fixture->receipts_during_dispatch[ $request['request_ref'] ];
		$this->assertSame( 'dispatching', $reserved['state'] );
		$this->assertNull( $reserved['response'] );
		$this->assertSame( hash( 'sha256', wp_json_encode( array( 'blocks_v2_replace', $request['payload'] ) ) ), $reserved['effect_hash'] );

		$stored = get_option( 'mainwp_wordfence_abilities_v2_receipts', array() );
		$this->assertSame( 'settled', $stored[ $request['request_ref'] ]['state'] );
		$this->assertSame( $result, $stored[ $request['request_ref'] ]['response'] );
	}

	/**
	 * A full receipt store refuses rather than forgetting an outcome a retry still needs, and it
	 * comes back on its own once the oldest entry ages past the retry horizon.
	 *
	 * The store used to make room with a bare array_shift(), which gives up whatever happens to
	 * sit first regardless of age - including a receipt whose reference is still being retried.
	 */
	public function test_a_full_receipt_store_refuses_before_the_provider_and_self_clears_past_the_horizon() {
		$fixture = $this->blocks_fixture();
		$oldest  = '123e4567-e89b-42d3-a456-4266141746a0';
		update_option( 'mainwp_wordfence_abilities_v2_receipts', $this->fill_receipts( 100, time(), $oldest ), false );

		$refused = $fixture->abilities_v2( $this->blocks_request( '123e4567-e89b-42d3-a456-426614174611' ) );

		$this->assertFalse( $refused['ok'] );
		$this->assertSame( 'storage_unavailable', $refused['code'] );
		$this->assertSame( array(), $fixture->calls, 'the provider must not run when the outcome cannot be recorded' );
		$this->assertCount( 100, get_option( 'mainwp_wordfence_abilities_v2_receipts', array() ) );

		// Only the clock changes: the oldest receipt is now past the horizon and nothing else is.
		$receipts                          = get_option( 'mainwp_wordfence_abilities_v2_receipts', array() );
		$receipts[ $oldest ]['created_at'] = time() - ( DAY_IN_SECONDS + 3600 );
		update_option( 'mainwp_wordfence_abilities_v2_receipts', $receipts, false );

		$accepted = $fixture->abilities_v2( $this->blocks_request( '123e4567-e89b-42d3-a456-426614174612' ) );
		$stored   = get_option( 'mainwp_wordfence_abilities_v2_receipts', array() );

		$this->assertTrue( $accepted['ok'] );
		$this->assertCount( 1, $fixture->calls );
		$this->assertCount( 100, $stored );
		$this->assertArrayNotHasKey( $oldest, $stored, 'the aged receipt is the one given up' );
		$this->assertArrayHasKey( '123e4567-e89b-42d3-a456-426614174612', $stored );
	}

	/** An unreadable entry is kept as a dated tombstone rather than freed for an unrelated request. */
	public function test_an_unreadable_entry_becomes_a_dated_tombstone_rather_than_someone_elses_room() {
		$fixture                 = $this->blocks_fixture();
		$receipts                = $this->fill_receipts( 99, time() );
		$unreadable              = '123e4567-e89b-42d3-a456-4266141746b0';
		$receipts[ $unreadable ] = array(
			'effect_hash' => str_repeat( 'e', 64 ),
			'response'    => array( 'ok' => true ),
		);
		update_option( 'mainwp_wordfence_abilities_v2_receipts', $receipts, false );

		$refused = $fixture->abilities_v2( $this->blocks_request( '123e4567-e89b-42d3-a456-426614174613' ) );
		$stored  = get_option( 'mainwp_wordfence_abilities_v2_receipts', array() );

		$this->assertFalse( $refused['ok'] );
		$this->assertSame( 'storage_unavailable', $refused['code'] );
		$this->assertSame( array(), $fixture->calls );
		$this->assertArrayHasKey( $unreadable, $stored, 'the damaged entry is still evidence that reference already ran' );
		$this->assertSame( 'unreadable', $stored[ $unreadable ]['state'] );
		$this->assertIsInt( $stored[ $unreadable ]['created_at'], 'the repair has to be persisted or it is re-stamped on every request' );
		$this->assertSame( 'storage_unavailable', $fixture->abilities_v2( $this->blocks_request( $unreadable ) )['code'] );
	}

	/** A reservation nobody settled answers outcome_unknown and dispatches nothing. */
	public function test_an_unsettled_reservation_answers_outcome_unknown_without_dispatching() {
		$fixture = $this->blocks_fixture();
		$request = $this->blocks_request( '123e4567-e89b-42d3-a456-426614174614' );
		update_option( 'mainwp_wordfence_abilities_v2_receipts', array( $request['request_ref'] => $this->reservation( $request, time() ) ), false );

		$result = $fixture->abilities_v2( $request );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'outcome_unknown', $result['code'] );
		$this->assertSame( array(), $fixture->calls, 'work that may already stand must not be dispatched again' );
	}

	/**
	 * A stamp this clock cannot date holds its reservation open instead of releasing it.
	 *
	 * A host clock that stepped backwards would otherwise retire every reservation in the store
	 * at once, and each retired reference would repair or block a second time.
	 */
	public function test_an_undatable_reservation_is_not_retired_into_a_second_dispatch() {
		$fixture = $this->blocks_fixture();
		$request = $this->blocks_request( '123e4567-e89b-42d3-a456-426614174615' );
		update_option( 'mainwp_wordfence_abilities_v2_receipts', array( $request['request_ref'] => $this->reservation( $request, time() + ( 2 * DAY_IN_SECONDS ) ) ), false );

		$result = $fixture->abilities_v2( $request );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'outcome_unknown', $result['code'] );
		$this->assertSame( array(), $fixture->calls );
	}

	/**
	 * A tombstone this clock cannot date is re-dated, so the store still ages out.
	 *
	 * Keeping the stamp would leave an entry no clock ever reaches, and one of those in a full
	 * store refuses every mutation from then on with nothing an operator could do.
	 */
	public function test_a_tombstone_this_clock_cannot_date_is_redated_rather_than_held_forever() {
		$fixture                 = $this->blocks_fixture();
		$receipts                = $this->fill_receipts( 99, time() );
		$tombstoned              = '123e4567-e89b-42d3-a456-4266141746c0';
		$receipts[ $tombstoned ] = array(
			'effect_hash' => str_repeat( '0', 64 ),
			'state'       => 'unreadable',
			'response'    => null,
			'created_at'  => PHP_INT_MAX,
		);
		update_option( 'mainwp_wordfence_abilities_v2_receipts', $receipts, false );

		$refused = $fixture->abilities_v2( $this->blocks_request( '123e4567-e89b-42d3-a456-426614174616' ) );
		$stored  = get_option( 'mainwp_wordfence_abilities_v2_receipts', array() );

		$this->assertFalse( $refused['ok'] );
		$this->assertSame( 'storage_unavailable', $refused['code'] );
		$this->assertLessThanOrEqual( time(), $stored[ $tombstoned ]['created_at'], 'a stamp no clock reaches has to be replaced with one that ages' );

		// Proof that it now ages out: only the clock changes and the entry becomes the one given up.
		$stored[ $tombstoned ]['created_at'] = time() - ( DAY_IN_SECONDS + 3600 );
		update_option( 'mainwp_wordfence_abilities_v2_receipts', $stored, false );

		$this->assertTrue( $fixture->abilities_v2( $this->blocks_request( '123e4567-e89b-42d3-a456-426614174617' ) )['ok'] );
		$this->assertArrayNotHasKey( $tombstoned, get_option( 'mainwp_wordfence_abilities_v2_receipts', array() ) );
	}

	/**
	 * A settled entry no replay could ever be answered from is not evidence, and must not pin the store.
	 *
	 * Shape alone says a settled entry with an empty response is readable, but the replay path
	 * rejects it for its own reference, so it can answer nothing. Left standing with a stamp no
	 * clock reaches, a full store of them refuses every mutation from then on.
	 */
	public function test_a_full_store_of_shape_valid_unreplayable_receipts_is_rebuilt_and_self_clears() {
		$fixture  = $this->blocks_fixture();
		$receipts = array();
		for ( $index = 0; $index < 100; $index++ ) {
			$receipts[ sprintf( '123e4567-e89b-42d3-a456-4266141753%02d', $index ) ] = array(
				'effect_hash' => str_repeat( 'a', 64 ),
				'state'       => 'settled',
				'response'    => array(),
				'created_at'  => PHP_INT_MAX,
			);
		}
		update_option( 'mainwp_wordfence_abilities_v2_receipts', $receipts, false );

		$refused = $fixture->abilities_v2( $this->blocks_request( '123e4567-e89b-42d3-a456-426614174620' ) );
		$stored  = get_option( 'mainwp_wordfence_abilities_v2_receipts', array() );

		$this->assertFalse( $refused['ok'] );
		$this->assertSame( 'storage_unavailable', $refused['code'] );
		$this->assertSame( array(), $fixture->calls );
		foreach ( $stored as $reference => $entry ) {
			$this->assertSame( 'unreadable', $entry['state'], $reference );
			$this->assertLessThanOrEqual( time(), $entry['created_at'], $reference );
		}

		// Having been given a date, the store now clears itself as those entries age out.
		foreach ( $stored as $reference => $entry ) {
			$stored[ $reference ]['created_at'] = time() - ( DAY_IN_SECONDS + 3600 );
		}
		update_option( 'mainwp_wordfence_abilities_v2_receipts', $stored, false );

		$this->assertTrue( $fixture->abilities_v2( $this->blocks_request( '123e4567-e89b-42d3-a456-426614174621' ) )['ok'] );
	}

	/**
	 * An entry filed under a key no request could present is not evidence for anybody.
	 *
	 * References are validated before the store is consulted, so nothing can ever come back for
	 * such an entry. Keeping it would let a store of junk keys hold every later mutation shut,
	 * and PHP array keys are not always strings, so one can also be compared as one.
	 */
	public function test_entries_under_keys_no_request_can_present_are_given_up() {
		$fixture  = $this->blocks_fixture();
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
			'response'    => array( 'protocol' => '2', 'operation' => 'blocks_v2_replace', 'ok' => true, 'request_ref' => '0' ),
			'created_at'  => PHP_INT_MAX,
		);
		update_option( 'mainwp_wordfence_abilities_v2_receipts', $receipts, false );

		$result = $fixture->abilities_v2( $this->blocks_request( '123e4567-e89b-42d3-a456-426614174622' ) );

		$this->assertTrue( $result['ok'], 'a store nothing can ever replay must not refuse forever' );
		$this->assertArrayHasKey( '123e4567-e89b-42d3-a456-426614174622', get_option( 'mainwp_wordfence_abilities_v2_receipts', array() ) );
	}

	/**
	 * A settled response naming a read is something no request can ever come back for.
	 *
	 * Only mutations write receipts and only mutations consult the store, so a stored read
	 * response is unreachable evidence; held live it refuses every later mutation.
	 */
	public function test_a_full_store_of_nonmutation_responses_is_rebuilt_and_self_clears() {
		$fixture  = $this->blocks_fixture();
		$receipts = array();
		for ( $index = 0; $index < 100; $index++ ) {
			$reference              = sprintf( '123e4567-e89b-42d3-a456-4266141754%02d', $index );
			$receipts[ $reference ] = array(
				'effect_hash' => hash( 'sha256', $reference ),
				'state'       => 'settled',
				// A complete, schema-valid read response: only the operation being a read makes it
				// unreachable, so nothing else about it can be why the store gives it up.
				'response'    => array(
					'protocol'               => '2',
					'operation'              => 'site_v2',
					'ok'                     => true,
					'request_ref'            => $reference,
					'plugin_version'         => '8.0.5',
					'state'                  => 'complete',
					'definitions_generation' => str_repeat( 'a', 64 ),
					'config_generation'      => str_repeat( 'b', 64 ),
					'scan_ref'               => null,
					'scan_state'             => 'clean',
					'finding_count'          => 0,
					'firewall_mode'          => 'enabled',
					'blocked_attack_count'   => 0,
					'observed_at'            => '2026-08-01T14:00:00Z',
					'generation'             => str_repeat( 'c', 64 ),
				),
				'created_at'  => PHP_INT_MAX,
			);
		}
		update_option( 'mainwp_wordfence_abilities_v2_receipts', $receipts, false );

		$refused = $fixture->abilities_v2( $this->blocks_request( '123e4567-e89b-42d3-a456-426614174623' ) );
		$stored  = get_option( 'mainwp_wordfence_abilities_v2_receipts', array() );

		$this->assertFalse( $refused['ok'] );
		$this->assertSame( array(), $fixture->calls );
		foreach ( $stored as $reference => $entry ) {
			$this->assertSame( 'unreadable', $entry['state'], $reference );
			$this->assertLessThanOrEqual( time(), $entry['created_at'], $reference );
		}

		foreach ( $stored as $reference => $entry ) {
			$stored[ $reference ]['created_at'] = time() - ( DAY_IN_SECONDS + 3600 );
		}
		update_option( 'mainwp_wordfence_abilities_v2_receipts', $stored, false );

		$this->assertTrue( $fixture->abilities_v2( $this->blocks_request( '123e4567-e89b-42d3-a456-426614174624' ) )['ok'] );
	}

	/** A stamp the tombstone builder rejects must not be taken as a sound tombstone. */
	public function test_a_tombstone_with_an_impossible_stamp_is_rebuilt_rather_than_evicted() {
		$fixture                 = $this->blocks_fixture();
		$receipts                = $this->fill_receipts( 99, time() );
		$tombstoned              = '123e4567-e89b-42d3-a456-4266141746c1';
		$receipts[ $tombstoned ] = array(
			'effect_hash' => str_repeat( '0', 64 ),
			'state'       => 'unreadable',
			'response'    => null,
			'created_at'  => -1,
		);
		update_option( 'mainwp_wordfence_abilities_v2_receipts', $receipts, false );

		$refused = $fixture->abilities_v2( $this->blocks_request( '123e4567-e89b-42d3-a456-426614174618' ) );
		$stored  = get_option( 'mainwp_wordfence_abilities_v2_receipts', array() );

		$this->assertFalse( $refused['ok'] );
		$this->assertSame( array(), $fixture->calls );
		$this->assertArrayHasKey( $tombstoned, $stored, 'an impossible stamp is not proof the entry is past the horizon' );
		$this->assertGreaterThan( 0, $stored[ $tombstoned ]['created_at'] );
	}

	/**
	 * A reference whose stored entry is null still refuses rather than dispatching again.
	 *
	 * isset() reads a key holding null as absent, and the key existing at all is the evidence
	 * that something wrote a receipt for that reference.
	 */
	public function test_a_null_entry_under_a_reference_refuses_rather_than_dispatching() {
		$fixture = $this->blocks_fixture();
		$request = $this->blocks_request( '123e4567-e89b-42d3-a456-426614174619' );
		update_option( 'mainwp_wordfence_abilities_v2_receipts', array( $request['request_ref'] => null ), false );

		$result = $fixture->abilities_v2( $request );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'storage_unavailable', $result['code'] );
		$this->assertSame( array(), $fixture->calls, 'a reference with any stored entry must not reach the provider again' );
	}

	/**
	 * A store keyed by references nothing can address after folding must still be reclaimable.
	 *
	 * A reference is folded to lowercase before it keys a receipt, so an uppercase key is
	 * unreachable however well formed it looks. Held live it refuses every later mutation.
	 */
	public function test_full_store_of_noncanonical_uppercase_keys_is_reclaimed() {
		$fixture  = $this->blocks_fixture();
		$receipts = array();
		for ( $index = 0; $index < 100; $index++ ) {
			$receipts[ sprintf( '123E4567-E89B-42D3-A456-42661417%04X', $index ) ] = array(
				'effect_hash' => str_repeat( 'a', 64 ),
				'state'       => 'dispatching',
				'response'    => null,
				'created_at'  => PHP_INT_MAX,
			);
		}
		update_option( 'mainwp_wordfence_abilities_v2_receipts', $receipts, false );

		$result = $fixture->abilities_v2( $this->blocks_request( '123e4567-e89b-42d3-a456-426614174626' ) );
		$stored = get_option( 'mainwp_wordfence_abilities_v2_receipts', array() );

		$this->assertTrue( $result['ok'], 'a store nothing can address must not refuse forever' );
		$this->assertCount( 1, $fixture->calls );
		$this->assertArrayHasKey( '123e4567-e89b-42d3-a456-426614174626', $stored );
	}

	/**
	 * A settled entry whose stored hash and response name different operations still ages out.
	 *
	 * Nothing here can bind an effect hash to a response operation, so such an entry cannot be
	 * told from a sound one. What it must not do is sit undatable forever, and re-dating is what
	 * stops a full store of them refusing every later mutation.
	 */
	public function test_a_full_store_of_cross_operation_receipts_is_redated_and_self_clears() {
		$fixture  = $this->blocks_fixture();
		$receipts = array();
		for ( $index = 0; $index < 100; $index++ ) {
			$reference              = sprintf( '123e4567-e89b-42d3-a456-4266141760%02d', $index );
			$receipts[ $reference ] = array(
				// The hash of some other mutation entirely, so no request can ever match it.
				'effect_hash' => hash( 'sha256', 'scan_v2_start:' . $reference ),
				'state'       => 'settled',
				'response'    => array(
					'protocol'         => '2',
					'operation'        => 'blocks_v2_replace',
					'ok'               => true,
					'request_ref'      => $reference,
					'operation_ref'    => str_repeat( 'a', 64 ),
					'state'            => 'completed',
					'added'            => 1,
					'removed'          => 0,
					'management_probe' => 'safe',
					'generation'       => str_repeat( 'b', 64 ),
				),
				'created_at'  => PHP_INT_MAX,
			);
		}
		update_option( 'mainwp_wordfence_abilities_v2_receipts', $receipts, false );

		$refused = $fixture->abilities_v2( $this->blocks_request( '123e4567-e89b-42d3-a456-426614174627' ) );
		$stored  = get_option( 'mainwp_wordfence_abilities_v2_receipts', array() );

		$this->assertFalse( $refused['ok'] );
		$this->assertSame( array(), $fixture->calls );
		$this->assertCount( 100, $stored, 'evidence a reference could still be answered from is not given up' );
		foreach ( $stored as $reference => $entry ) {
			$this->assertLessThanOrEqual( time(), $entry['created_at'], $reference );
		}

		foreach ( $stored as $reference => $entry ) {
			$stored[ $reference ]['created_at'] = time() - ( DAY_IN_SECONDS + 3600 );
		}
		update_option( 'mainwp_wordfence_abilities_v2_receipts', $stored, false );

		$this->assertTrue( $fixture->abilities_v2( $this->blocks_request( '123e4567-e89b-42d3-a456-426614174628' ) )['ok'] );
	}

	/** @param array $request Blocks request. @param int $created_at Stamp. @return array */
	private function reservation( $request, $created_at ) {
		return array(
			'effect_hash' => hash( 'sha256', wp_json_encode( array( $request['operation'], $request['payload'] ) ) ),
			'state'       => 'dispatching',
			'response'    => null,
			'created_at'  => $created_at,
		);
	}

	public function test_read_results_are_closed_and_malformed_provider_data_fails() {
		$fixture = new Wordfence_V2_Protocol_Fixture();
		$fixture->results['site_v2'] = array(
			'plugin_version'        => '8.0.5',
			'state'                 => 'complete',
			'definitions_generation'=> str_repeat( 'a', 64 ),
			'config_generation'     => str_repeat( 'b', 64 ),
			'scan_ref'              => null,
			'scan_state'            => 'never',
			'finding_count'         => 0,
			'firewall_mode'         => 'enabled',
			'blocked_attack_count'  => 4,
			'observed_at'           => '2026-08-16T12:00:00Z',
			'generation'            => str_repeat( 'c', 64 ),
		);
		$result = $fixture->abilities_v2( array( 'protocol' => '2', 'operation' => 'site_v2', 'payload' => array() ) );
		$this->assertTrue( $result['ok'] );
		$this->assertArrayNotHasKey( 'request_ref', $result );

		$fixture->results['site_v2']['private_path'] = '/secret';
		$this->assertSame( 'provider_schema_invalid', $fixture->abilities_v2( array( 'protocol' => '2', 'operation' => 'site_v2', 'payload' => array() ) )['code'] );
	}

	public function test_reordered_envelope_is_accepted_but_extra_fields_are_not() {
		$result = $this->subject->abilities_v2(
			array(
				'payload'   => array(),
				'operation' => 'capabilities',
				'protocol'  => '2',
			)
		);
		$this->assertTrue( $result['ok'] );

		$result = $this->subject->abilities_v2(
			array(
				'protocol'  => '2',
				'operation' => 'capabilities',
				'payload'   => array(),
				'extra'     => true,
			)
		);
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'invalid_request', $result['code'] );
	}

	public function test_mutation_dispatch_runs_under_the_named_lock() {
		$fixture = $this->blocks_fixture();
		$request = $this->blocks_request();

		$result = $fixture->abilities_v2( $request );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( '0', (string) $fixture->lock_free_during_dispatch );
		$this->assertSame( '1', (string) $this->lock_state( $fixture->lock_name() ) );
	}

	public function test_a_held_lock_refuses_the_mutation_without_dispatching_or_storing() {
		$fixture = $this->blocks_fixture();
		$holder  = $this->hold_lock_elsewhere( $fixture->lock_name() );

		$result           = $fixture->abilities_v2( $this->blocks_request() );
		$dispatched_calls = count( $fixture->calls );
		$read             = $fixture->abilities_v2(
			array(
				'protocol'  => '2',
				'operation' => 'firewall_v2_get',
				'payload'   => array(),
			)
		);
		$this->release_lock_elsewhere( $holder, $fixture->lock_name() );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'lock_busy', $result['code'] );
		$this->assertSame( 0, $dispatched_calls );
		$this->assertSame( array(), get_option( 'mainwp_wordfence_abilities_v2_receipts', array() ) );
		$this->assertSame( 'provider_unavailable', $read['code'] );
	}

	/** A lock backend that cannot answer is refused as a store failure, not as someone else's lock. */
	public function test_an_unusable_lock_backend_is_refused_apart_from_a_held_lock() {
		$fixture = $this->blocks_fixture();
		// The lock name reads home_url(), so it is resolved while the real connection is still in place.
		$name = $fixture->lock_name();

		$backends = array(
			'driver error'     => array( array( 'MySQL server has gone away', null ) ),
			'GET_LOCK is NULL' => array( array( '', null ) ),
		);
		foreach ( $backends as $label => $answers ) {
			$real            = $GLOBALS['wpdb'];
			$GLOBALS['wpdb'] = new Wordfence_Lock_Wpdb_Stub( $answers );
			try {
				$result = $fixture->abilities_v2( $this->blocks_request() );
			} finally {
				$GLOBALS['wpdb'] = $real;
			}

			$this->assertFalse( $result['ok'], $label );
			$this->assertSame( 'storage_unavailable', $result['code'], $label );
		}

		$holder = $this->hold_lock_elsewhere( $name );
		$held   = $fixture->abilities_v2( $this->blocks_request() );
		$this->release_lock_elsewhere( $holder, $name );

		$this->assertFalse( $held['ok'] );
		$this->assertSame( 'lock_busy', $held['code'] );
		$this->assertSame( array(), $fixture->calls );
		$this->assertSame( array(), get_option( 'mainwp_wordfence_abilities_v2_receipts', array() ) );
	}

	public function test_release_reads_an_absent_lock_as_released_and_retries_a_failed_release_once() {
		$fixture = new Wordfence_V2_Protocol_Fixture();
		// The lock name reads home_url(), so it is resolved while the real connection is still in place.
		$fixture->lock_name();
		$real = $GLOBALS['wpdb'];

		try {
			$absent          = new Wordfence_Lock_Wpdb_Stub( array( array( '', null ) ) );
			$GLOBALS['wpdb'] = $absent;
			$this->assertTrue( $fixture->end_mutation_lock() );
			$this->assertCount( 1, $absent->queries );

			$retried         = new Wordfence_Lock_Wpdb_Stub( array( array( 'MySQL server has gone away', null ), array( '', '1' ) ) );
			$GLOBALS['wpdb'] = $retried;
			$this->assertTrue( $fixture->end_mutation_lock() );
			$this->assertCount( 2, $retried->queries );

			$failing         = new Wordfence_Lock_Wpdb_Stub( array( array( 'Lost connection', null ), array( 'Lost connection', null ) ) );
			$GLOBALS['wpdb'] = $failing;
			$this->assertFalse( $fixture->end_mutation_lock() );
			$this->assertCount( 2, $failing->queries );
		} finally {
			$GLOBALS['wpdb'] = $real;
		}
	}

	public function test_a_failed_deletion_reports_its_own_error_and_not_an_earlier_warning() {
		$victim = ABSPATH . self::VICTIM;
		file_put_contents( $victim, 'survives' );
		Wordfence_Issue_Store_Stub::$issues = array( 'issue-1' => array( 'data' => array( 'file' => self::VICTIM ) ) );
		// An empty path makes wp_delete_file() skip unlink() altogether, so the file survives and
		// nothing at all is raised for this deletion - exactly when a stale error gets borrowed.
		add_filter( 'wp_delete_file', '__return_empty_string' );

		$_POST['issueID'] = 'issue-1';
		$_POST['op']      = 'del';
		$_POST['ids']     = array( 'issue-1' );
		try {
			$this->raise_unrelated_warning();
			$single = $this->subject->delete_file();
			$this->raise_unrelated_warning();
			$bulk = $this->subject->bulk_operation();
		} finally {
			remove_filter( 'wp_delete_file', '__return_empty_string' );
			unset( $_POST['issueID'], $_POST['op'], $_POST['ids'] );
			if ( file_exists( $victim ) ) {
				unlink( $victim );
			}
		}

		$this->assertStringNotContainsString( self::STALE_MARKER, $single['errorMsg'] );
		$this->assertStringNotContainsString( ABSPATH, $single['errorMsg'] );
		$this->assertStringContainsString( MainWP_Child_Wordfence::ERROR_LOG_HINT, $single['errorMsg'] );
		$this->assertStringNotContainsString( self::STALE_MARKER, $bulk['bulkBody'] );
		$this->assertStringNotContainsString( ABSPATH, $bulk['bulkBody'] );
		$this->assertStringContainsString( MainWP_Child_Wordfence::ERROR_LOG_HINT, $bulk['bulkBody'] );
	}

	/**
	 * Every path that reports a failed write must answer with its own fixed reason.
	 *
	 * Two conditions, because error_get_last() misleads in both directions once another plugin has
	 * installed an error handler that swallows warnings: it keeps returning some earlier, unrelated
	 * error, or it returns null and there is nothing to read at all.
	 */
	public function test_write_failures_report_a_fixed_reason_and_never_read_a_swallowed_error() {
		$probe = ABSPATH . self::PROBE_DIR;
		mkdir( $probe );
		Wordfence_Cache_Stub::$htaccess_path = $probe;
		Wordfence_Issue_Store_Stub::$issues  = array(
			'issue-1' => array(
				'data' => array(
					'file'     => self::PROBE_DIR,
					'cType'    => 'plugin',
					'cName'    => 'probe',
					'cVersion' => '1.0',
				),
			),
		);
		$subject = $this->subject;
		$calls   = array(
			'restore_file'          => function () use ( $subject ) {
				$_POST['issueID'] = 'issue-1';
				try {
					return $subject->restore_file();
				} finally {
					unset( $_POST['issueID'] );
				}
			},
			'bulk_operation repair' => function () use ( $subject ) {
				$_POST['op']  = 'repair';
				$_POST['ids'] = array( 'issue-1' );
				try {
					return $subject->bulk_operation();
				} finally {
					unset( $_POST['op'], $_POST['ids'] );
				}
			},
			'check_htaccess'        => function () {
				return MainWP_Child_Wordfence::check_htaccess();
			},
			'check_falcon_htaccess' => function () {
				return MainWP_Child_Wordfence::check_falcon_htaccess();
			},
		);

		try {
			foreach ( $calls as $label => $call ) {
				// An error another plugin raised earlier in the request is all error_get_last() holds.
				$this->raise_unrelated_warning();
				list( $borrowed ) = $this->swallow_errors( $call );
				$borrowed_text    = wp_json_encode( $borrowed );
				$this->assertStringNotContainsString( self::STALE_MARKER, $borrowed_text, $label );
				$this->assertStringNotContainsString( ABSPATH, $borrowed_text, $label );
				$this->assertStringNotContainsString( 'The error was', $borrowed_text, $label );
				$this->assertStringNotContainsString( 'could not open it for writing:', $borrowed_text, $label );

				// Someone else's "Permission denied" must not become this open's diagnosis.
				@trigger_error( self::STALE_MARKER . ' Permission denied', E_USER_WARNING ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_trigger_error,WordPress.PHP.NoSilencedErrors.Discouraged -- Seeds error_get_last() without tripping the harness handler.
				list( $misdiagnosed ) = $this->swallow_errors( $call );
				$this->assertStringNotContainsString( 'permission', strtolower( wp_json_encode( $misdiagnosed ) ), $label );

				// Nothing pending at all: the path must not read the null back out.
				error_clear_last();
				list( , $raised ) = $this->swallow_errors( $call );
				$nulls            = array_values(
					array_filter(
						$raised,
						function ( $message ) {
							return false !== stripos( $message, 'null' );
						}
					)
				);
				$this->assertSame( array(), $nulls, $label );
			}
		} finally {
			Wordfence_Issue_Store_Stub::$issues  = array();
			Wordfence_Cache_Stub::$htaccess_path = '';
			rmdir( $probe );
		}
	}

	/**
	 * Run one call with every error it raises swallowed and recorded.
	 *
	 * A handler that returns true leaves error_get_last() holding whatever was already there, which
	 * is how another plugin's error handler makes both misreadings above reachable in production.
	 *
	 * @param callable $call Call to run.
	 * @return array array( return value, messages raised )
	 */
	private function swallow_errors( $call ) {
		$raised = array();
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_set_error_handler -- Scoped to one call and restored below.
			function ( $errno, $errstr ) use ( &$raised ) {
				unset( $errno );
				$raised[] = $errstr;
				return true;
			}
		);
		try {
			$result = $call();
		} finally {
			restore_error_handler();
		}
		return array( $result, $raised );
	}

	/** Leave a warning of the kind any other plugin can raise earlier in the same request. */
	private function raise_unrelated_warning() {
		@file_get_contents( ABSPATH . self::STALE_MARKER );
		$last = error_get_last();
		$this->assertIsArray( $last );
		$this->assertStringContainsString( self::STALE_MARKER, $last['message'] );
	}

	/** @param int $count How many. @param int $created_at Stamp. @param string|null $first Reference of the first entry. @return array */
	private function fill_receipts( $count, $created_at, $first = null ) {
		$receipts = array();
		for ( $index = 0; $index < $count; $index++ ) {
			$reference              = 0 === $index && null !== $first ? $first : sprintf( '123e4567-e89b-42d3-a456-4266141752%02d', $index );
			$receipts[ $reference ] = array(
				'effect_hash' => hash( 'sha256', $reference ),
				'state'       => 'settled',
				'response'    => array(
					'protocol'    => '2',
					'operation'   => 'blocks_v2_replace',
					'ok'          => true,
					'request_ref' => $reference,
				),
				'created_at'  => $created_at,
			);
		}
		return $receipts;
	}

	private function blocks_fixture() {
		$fixture                               = new Wordfence_V2_Protocol_Fixture();
		$fixture->results['blocks_v2_replace'] = array(
			'operation_ref'    => str_repeat( 'a', 64 ),
			'state'            => 'completed',
			'added'            => 1,
			'removed'          => 0,
			'management_probe' => 'safe',
			'generation'       => str_repeat( 'b', 64 ),
		);
		return $fixture;
	}

	/** @param string $request_ref Reference. @return array */
	private function blocks_request( $request_ref = '123e4567-e89b-42d3-a456-426614174603' ) {
		return array(
			'protocol'    => '2',
			'operation'   => 'blocks_v2_replace',
			'request_ref' => $request_ref,
			'payload'     => array(
				'if_match' => str_repeat( 'c', 64 ),
				'blocks'   => array(
					array(
						'kind'       => 'cidr',
						'value'      => '203.0.113.0/24',
						'reason'     => 'abuse',
						'expires_at' => null,
					),
				),
			),
		);
	}

	private function lock_state( $name ) {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', $name ) );
	}

	private function hold_lock_elsewhere( $name ) {
		$other = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$other->suppress_errors( true );
		$held = (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s,0)', $name ) );
		if ( '1' !== $held ) {
			// A lock this connection never took needs no release, but the connection is ours either
			// way and a failing assertion would otherwise strand it for the rest of the run.
			$other->close();
		}
		$this->assertSame( '1', $held );
		return $other;
	}

	private function release_lock_elsewhere( $other, $name ) {
		$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		$other->close();
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
