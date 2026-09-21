<?php
/**
 * Staging abilities-v2 protocol tests.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Child_Staging;

require_once dirname( __DIR__ ) . '/class/class-mainwp-child-staging.php';

/** Provider-free Staging protocol fixture. */
class Test_MainWP_Child_Staging_V2_Fixture extends MainWP_Child_Staging {

	/** @var array */
	public $clones = array();

	/** @var string */
	public $clone_source = 'provider';

	/** @var array */
	public $settings = array();

	/** @var int */
	public $preview_calls = 0;

	/** @var int */
	public $settings_writes = 0;

	/** @var array */
	public $operation_results = array();

	/** @var array */
	public $operation_calls = array();

	/** @var bool */
	public $jobs_ready = true;

	/** @var bool */
	public $preview_ready = true;

	/** @var string|null IS_FREE_LOCK() observed while the provider ran. */
	public $lock_free_during_dispatch = null;

	/** @var array Receipt store as it stood while the provider ran. */
	public $receipts_during_dispatch = array();

	/** @var string|null IS_FREE_LOCK() observed while the settings write ran. */
	public $lock_free_during_settings_write = null;

	/** @var array Settings receipt store as it stood while the settings write ran. */
	public $settings_receipts_during_write = array();

	/** Avoid installed-plugin lookup. */
	public function __construct() {
		$this->is_plugin_installed = true;
		$this->plugin_version      = '5.5.0';
	}

	/** @return array */
	protected function abilities_v2_provider_clones() {
		return $this->clones;
	}

	/** @return string */
	protected function abilities_v2_clone_source() {
		return $this->clone_source;
	}

	/** @return array */
	protected function abilities_v2_provider_settings() {
		return $this->settings;
	}

	/** @return bool */
	protected function abilities_v2_provider_supports_mutation() {
		return $this->jobs_ready;
	}

	/** @return bool */
	protected function abilities_v2_provider_supports_preview() {
		return $this->preview_ready;
	}

	/** @return array */
	protected function abilities_v2_provider_preview( $kind, $clone_ref, $inventory ) {
		++$this->preview_calls;
		return array(
			'table_count'      => 25,
			'file_count'       => 1200,
			'estimated_bytes'  => 500000000,
			'disk_sufficient'  => true,
			'isolation_ready'  => true,
			'warnings'         => array(),
		);
	}

	/** @return bool */
	protected function abilities_v2_provider_store_settings( $settings ) {
		global $wpdb;
		++$this->settings_writes;
		$this->lock_free_during_settings_write = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', $this->abilities_v2_lock_name() ) );
		// update_option() primes the object cache, so only a cold read shows what is actually on
		// disk at this moment - which is the whole question a durable reservation asks.
		wp_cache_delete( MainWP_Child_Staging::ABILITIES_V2_SETTINGS_RECEIPTS_OPTION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		$this->settings_receipts_during_write = get_option( MainWP_Child_Staging::ABILITIES_V2_SETTINGS_RECEIPTS_OPTION, array() );
		$this->settings                       = $settings;
		return true;
	}

	/** @return array|WP_Error */
	protected function abilities_v2_provider_operation( $operation, $payload ) {
		global $wpdb;
		$this->operation_calls[]         = array( $operation, $payload );
		$this->lock_free_during_dispatch = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', $this->abilities_v2_lock_name() ) );
		$this->receipts_during_dispatch  = get_option( 'mainwp_staging_abilities_v2_operation_receipts', array() );
		return isset( $this->operation_results[ $operation ] ) ? $this->operation_results[ $operation ] : new WP_Error( 'provider_unavailable' );
	}

	/** @return array|false */
	public function fixture_inventory_rows() {
		return $this->abilities_v2_inventory_rows();
	}

	/** @return string */
	public function fixture_lock_name() {
		return $this->abilities_v2_lock_name();
	}
}

/** Answers a named-lock query from a script, so each acquire attempt is observable. */
class Test_MainWP_Child_Staging_V2_Lock_Wpdb {

	/** @var string */
	public $last_error = '';

	/** @var array One array( error, result ) per expected attempt. */
	private $answers;

	/** @param array $answers Scripted answers. */
	public function __construct( $answers ) {
		$this->answers = $answers;
	}

	/** @param string $query Query. @return string */
	public function prepare( $query, ...$args ) {
		unset( $args );
		return $query;
	}

	/** @param string $query Query. @return mixed */
	public function get_var( $query ) {
		unset( $query );
		$answer           = array_shift( $this->answers );
		$this->last_error = $answer[0];
		return $answer[1];
	}
}

/** Carries the protocol-2 response out of action() before MainWP_Helper::write() ends the request. */
class Test_MainWP_Child_Staging_V2_Dispatched extends RuntimeException {

	/** @var array */
	public $response;

	/** @param array $response Closed protocol response. */
	public function __construct( $response ) {
		parent::__construct( 'abilities_v2 dispatch reached' );
		$this->response = $response;
	}
}

/**
 * A site with no WP Staging, driven through the real action() dispatcher.
 *
 * MainWP_Helper::write() ends the request with die(), so the response is thrown out of the
 * protocol seam instead: catching it is what proves dispatch got past the provider gates.
 */
class Test_MainWP_Child_Staging_V2_Absent_Provider_Fixture extends MainWP_Child_Staging {

	/** Skip the installed-plugin lookup; this site has no WP Staging. */
	public function __construct() {
	}

	/** @param mixed $request Decoded request. @return array */
	public function abilities_v2( $request ) {
		throw new Test_MainWP_Child_Staging_V2_Dispatched( parent::abilities_v2( $request ) );
	}
}

/** Staging protocol-v2 contract tests. */
class Test_MainWP_Child_Staging_V2 extends WP_UnitTestCase {

	/** @var Test_MainWP_Child_Staging_V2_Fixture */
	private $staging;

	/** @var string */
	private $clone_path;

	/** Set up provider-free clone truth. */
	public function setUp(): void {
		parent::setUp();
		delete_option( 'mainwp_staging_abilities_v2_receipts' );
		delete_option( 'mainwp_staging_abilities_v2_operation_receipts' );
		$this->clone_path = WP_CONTENT_DIR;
		$this->staging         = new Test_MainWP_Child_Staging_V2_Fixture();
		$this->staging->clones = array(
			'provider-clone-secret' => array(
				'directoryName'             => 'private-clone-name',
				'path'                      => $this->clone_path,
				'url'                       => 'https://secret-clone.example.test',
				'databasePrefix'            => 'secret_prefix_',
				'status'                    => 'finished',
				'isolated'                  => true,
				'searchIndexBlocked'        => true,
				'outboundSideEffectsBlocked' => true,
				'createdAt'                 => '2026-08-01T14:00:00Z',
				'updatedAt'                 => '2026-08-01T14:10:00Z',
			),
		);
	}

	/** Finish the fixture. */
	public function tearDown(): void {
		delete_option( 'mainwp_staging_abilities_v2_operation_receipts' );
		delete_option( 'wpstg_settings' );
		parent::tearDown();
	}

	/** Capability negotiation is additive and exact. */
	public function test_capabilities_are_closed_and_do_not_touch_provider_preview() {
		$result = $this->invoke_v2( 'capabilities', array() );

		$this->assertSame( array( 'protocol', 'operation', 'ok', 'operations', 'mutation_supported', 'wp_staging_version' ), array_keys( $result ) );
		$this->assertSame( array( 'inventory', 'settings', 'preview', 'replace_settings', 'create_clone', 'update_clone', 'delete_clone', 'operation_status', 'cancel_operation', 'reconcile_operation' ), $result['operations'] );
		$this->assertTrue( $result['mutation_supported'] );
		$this->assertSame( '5.5.0', $result['wp_staging_version'] );
		$this->assertSame( 0, $this->staging->preview_calls );
	}

	/** Inventory returns only opaque bounded facts. */
	public function test_inventory_is_complete_redacted_and_stable() {
		$this->assertNotFalse(
			$this->staging->fixture_inventory_rows(),
			wp_json_encode(
				array(
					'abspath'    => ABSPATH,
					'real_root'  => realpath( ABSPATH ),
					'clone_path' => $this->clone_path,
					'real_clone' => realpath( $this->clone_path ),
				)
			)
		);
		$result = $this->invoke_v2( 'inventory', array() );

		$this->assertTrue( $result['ok'], wp_json_encode( $result ) );
		$this->assertTrue( $result['complete'] );
		$this->assertCount( 1, $result['clones'] );
		$row = $result['clones'][0];
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $row['clone_ref'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $row['revision'] );
		$this->assertSame( 'ready', $row['state'] );
		$this->assertTrue( $row['isolated'] );
		$this->assertTrue( $row['search_index_blocked'] );
		$this->assertTrue( $row['outbound_side_effects_blocked'] );
		$encoded = wp_json_encode( $result );
		$this->assertStringNotContainsString( 'provider-clone-secret', $encoded );
		$this->assertStringNotContainsString( 'private-clone-name', $encoded );
		$this->assertStringNotContainsString( 'secret-clone.example.test', $encoded );
		$this->assertStringNotContainsString( 'secret_prefix_', $encoded );
		$this->assertStringNotContainsString( $this->clone_path, $encoded );
		$this->assertSame( 0, $this->staging->preview_calls );
	}

	/** Guards WP Staging never records report as unobserved, not as observed-off. */
	public function test_inventory_reports_unknown_state_and_guards_when_the_provider_records_none() {
		unset(
			$this->staging->clones['provider-clone-secret']['status'],
			$this->staging->clones['provider-clone-secret']['isolated'],
			$this->staging->clones['provider-clone-secret']['searchIndexBlocked'],
			$this->staging->clones['provider-clone-secret']['outboundSideEffectsBlocked']
		);

		$row = $this->invoke_v2( 'inventory', array() )['clones'][0];

		$this->assertSame( 'unknown', $row['state'] );
		$this->assertNull( $row['isolated'] );
		$this->assertNull( $row['search_index_blocked'] );
		$this->assertNull( $row['outbound_side_effects_blocked'] );
	}

	/** An interrupted clone is not reported as ready. */
	public function test_inventory_reports_an_unfinished_clone_as_incomplete() {
		$this->staging->clones['provider-clone-secret']['status'] = 'unfinished';

		$this->assertSame( 'incomplete', $this->invoke_v2( 'inventory', array() )['clones'][0]['state'] );
	}

	/** The pre-registry option fallback cannot claim a complete clone set. */
	public function test_legacy_option_fallback_is_not_reported_as_complete() {
		$this->assertTrue( $this->invoke_v2( 'inventory', array() )['complete'] );

		$this->staging->clone_source = 'legacy_option';

		$this->assertFalse( $this->invoke_v2( 'inventory', array() )['complete'] );
	}

	/** Settings are clamped to the public policy and private fields are absent. */
	public function test_settings_are_bounded_and_redacted() {
		$this->staging->settings = array(
			'queryLimit'        => 999999,
			'fileLimit'         => 500,
			'batchSize'         => 0,
			'maxFileSize'       => 5000,
			'cpuLoad'           => 'default',
			'delayRequests'     => 999,
			'debugMode'         => 1,
			'disableAdminLogin' => 1,
			'privatePath'       => '/secret',
		);
		$result                  = $this->invoke_v2( 'settings', array() );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 10000, $result['query_limit'] );
		$this->assertSame( 500, $result['file_limit'] );
		$this->assertSame( 1, $result['batch_size_mb'] );
		$this->assertSame( 1024, $result['max_file_size_mb'] );
		$this->assertSame( 'low', $result['cpu_load'] );
		$this->assertSame( 60, $result['delay_seconds'] );
		$this->assertTrue( $result['debug_enabled'] );
		$this->assertStringNotContainsString( 'disableAdminLogin', wp_json_encode( $result ) );
		$this->assertStringNotContainsString( '/secret', wp_json_encode( $result ) );
	}

	/** Preview is exact, read-only and bound to the opaque inventory generation. */
	public function test_preview_validates_clone_reference_before_provider_work() {
		$inventory = $this->invoke_v2( 'inventory', array() );
		$clone_ref = $inventory['clones'][0]['clone_ref'];
		$result    = $this->invoke_v2(
			'preview',
			array(
				'kind'      => 'update',
				'clone_ref' => $clone_ref,
			)
		);

		$this->assertTrue( $result['ok'] );
		$this->assertSame( $inventory['inventory_revision'], $result['inventory_revision'] );
		$this->assertSame( 25, $result['table_count'] );
		$this->assertSame( 1, $this->staging->preview_calls );

		$missing = $this->invoke_v2(
			'preview',
			array(
				'kind'      => 'update',
				'clone_ref' => str_repeat( 'a', 64 ),
			)
		);
		$this->assertFalse( $missing['ok'] );
		$this->assertSame( 'clone_not_found', $missing['error_code'] );
		$this->assertSame( 1, $this->staging->preview_calls );
	}

	/** Malformed requests fail before any provider preview effect. */
	public function test_malformed_requests_fail_closed_before_provider_work() {
		$cases = array(
			null,
			array( 'protocol' => '1', 'operation' => 'inventory', 'payload' => array() ),
			array( 'protocol' => '2', 'operation' => 'inventory', 'payload' => array(), 'extra' => true ),
			array( 'protocol' => '2', 'operation' => 'preview', 'payload' => array( 'kind' => 'create', 'clone_ref' => str_repeat( 'a', 64 ) ) ),
			array( 'protocol' => '2', 'operation' => 'preview', 'payload' => array( 'kind' => 'update', 'clone_ref' => null ) ),
			array( 'protocol' => '2', 'operation' => 'inventory', 'payload' => 'not-an-array' ),
			array( 'protocol' => '2', 'operation' => 'inventory' ),
			array( 'protocol' => '2', 'operation' => 'create_clone', 'request_ref' => '123e4567-e89b-42d3-a456-426614174940', 'payload' => 'not-an-array' ),
			array( 'protocol' => '2', 'operation' => 'create_clone', 'request_ref' => '123e4567-e89b-42d3-a456-426614174941', 'payload' => array( 'inventory_revision' => str_repeat( 'a', 64 ) ), 'extra' => true ),
			array( 'protocol' => '2', 'operation' => 'create_clone', 'payload' => array( 'inventory_revision' => str_repeat( 'a', 64 ) ) ),
		);
		foreach ( $cases as $case ) {
			$result = $this->staging->abilities_v2( $case );
			$this->assertFalse( $result['ok'] );
			$this->assertArrayHasKey( 'error_code', $result );
		}
		$this->assertSame( 0, $this->staging->preview_calls );
		$this->assertSame( array(), $this->staging->operation_calls );
	}

	/** Settings replacement preserves hidden fields and replays without a second write. */
	public function test_replace_settings_is_cas_bound_and_replay_safe() {
		$this->staging->settings = array(
			'queryLimit'        => 1000,
			'fileLimit'         => 500,
			'batchSize'         => 10,
			'maxFileSize'       => 50,
			'cpuLoad'           => 'medium',
			'delayRequests'     => 1,
			'debugMode'         => 0,
			'disableAdminLogin' => 0,
		);
		$current = $this->invoke_v2( 'settings', array() );
		$request = array(
			'protocol'    => '2',
			'operation'   => 'replace_settings',
			'request_ref' => '123e4567-e89b-42d3-a456-426614174930',
			'payload'     => array(
				'if_match' => $current['revision'],
				'settings' => array(
					'query_limit'      => 900,
					'file_limit'       => 250,
					'batch_size_mb'    => 20,
					'max_file_size_mb' => 100,
					'cpu_load'         => 'low',
					'delay_seconds'    => 2,
					'debug_enabled'    => true,
				),
			),
		);
		$result = $this->staging->abilities_v2( $request );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 1, $this->staging->settings_writes );
		$this->assertSame( 0, $this->staging->settings['disableAdminLogin'] );
		$this->assertSame( 900, $this->staging->settings['queryLimit'] );

		$this->assertSame( $result, $this->staging->abilities_v2( $request ) );
		$this->assertSame( 1, $this->staging->settings_writes );

		$request['payload']['settings']['query_limit'] = 800;
		$conflict = $this->staging->abilities_v2( $request );
		$this->assertFalse( $conflict['ok'] );
		$this->assertSame( 'request_conflict', $conflict['error_code'] );
		$this->assertSame( 1, $this->staging->settings_writes );
	}

	/**
	 * The settings receipt lookup and the provider write are one atomic step, so a concurrent
	 * request cannot read "no receipt" and overwrite the store this one is about to write.
	 */
	public function test_replace_settings_runs_under_the_named_lock_and_a_held_lock_refuses_it() {
		$applied = $this->staging->abilities_v2( $this->settings_request( '123e4567-e89b-42d3-a456-426614174931' ) );

		$this->assertTrue( $applied['ok'] );
		$this->assertSame( '0', $this->staging->lock_free_during_settings_write, 'the lock must be held while the provider is written' );
		$this->assertSame( '1', $this->lock_state( $this->staging->fixture_lock_name() ), 'the lock must be released afterwards' );

		$other   = $this->hold_lock_elsewhere( $this->staging->fixture_lock_name() );
		$refused = $this->staging->abilities_v2( $this->settings_request( '123e4567-e89b-42d3-a456-426614174932' ) );
		$this->release_lock_elsewhere( $other, $this->staging->fixture_lock_name() );

		$this->assertFalse( $refused['ok'] );
		$this->assertSame( 'lock_busy', $refused['error_code'] );
		$this->assertSame( 1, $this->staging->settings_writes, 'a refused request must not reach the provider' );
		$this->assertArrayNotHasKey( '123e4567-e89b-42d3-a456-426614174932', get_option( MainWP_Child_Staging::ABILITIES_V2_SETTINGS_RECEIPTS_OPTION, array() ) );
	}

	/** A key holding null is evidence a receipt was written, so its reference must not write again. */
	public function test_a_null_settings_entry_under_a_reference_refuses_rather_than_writing() {
		update_option( MainWP_Child_Staging::ABILITIES_V2_SETTINGS_RECEIPTS_OPTION, array( '123e4567-e89b-42d3-a456-426614174933' => null ), false );

		$refused = $this->staging->abilities_v2( $this->settings_request( '123e4567-e89b-42d3-a456-426614174933' ) );

		$this->assertFalse( $refused['ok'] );
		$this->assertSame( 'storage_unavailable', $refused['error_code'] );
		$this->assertSame( 0, $this->staging->settings_writes );
	}

	/**
	 * A full settings store refuses rather than giving up an entry a retry could still land on,
	 * and it comes back on its own once the oldest is past the retry horizon.
	 */
	public function test_a_full_settings_receipt_store_refuses_before_writing_and_self_clears_past_the_horizon() {
		$oldest = '123e4567-e89b-42d3-a456-4266141749b0';
		update_option( MainWP_Child_Staging::ABILITIES_V2_SETTINGS_RECEIPTS_OPTION, $this->fill_receipts( 100, time(), $oldest ), false );

		$refused = $this->staging->abilities_v2( $this->settings_request( '123e4567-e89b-42d3-a456-426614174934' ) );

		$this->assertFalse( $refused['ok'] );
		$this->assertSame( 'storage_unavailable', $refused['error_code'] );
		$this->assertSame( 0, $this->staging->settings_writes, 'the provider must not be written when the answer cannot be recorded' );
		$this->assertCount( 100, get_option( MainWP_Child_Staging::ABILITIES_V2_SETTINGS_RECEIPTS_OPTION, array() ) );

		// Only the clock changes: the oldest entry is now past the horizon and nothing else is.
		$receipts                          = get_option( MainWP_Child_Staging::ABILITIES_V2_SETTINGS_RECEIPTS_OPTION, array() );
		$receipts[ $oldest ]['created_at'] = time() - ( DAY_IN_SECONDS + 3600 );
		update_option( MainWP_Child_Staging::ABILITIES_V2_SETTINGS_RECEIPTS_OPTION, $receipts, false );

		$accepted = $this->staging->abilities_v2( $this->settings_request( '123e4567-e89b-42d3-a456-426614174935' ) );
		$stored   = get_option( MainWP_Child_Staging::ABILITIES_V2_SETTINGS_RECEIPTS_OPTION, array() );

		$this->assertTrue( $accepted['ok'] );
		$this->assertSame( 1, $this->staging->settings_writes );
		$this->assertCount( 100, $stored );
		$this->assertArrayNotHasKey( $oldest, $stored, 'the aged receipt is the one given up' );
		$this->assertArrayHasKey( '123e4567-e89b-42d3-a456-426614174935', $stored );
	}

	/**
	 * The reservation is on disk before the provider is written, so a request that dies in
	 * between leaves its retry an honest outcome_unknown instead of a stale_revision that
	 * blames another writer for what this request itself did.
	 */
	public function test_an_interrupted_settings_request_answers_outcome_unknown_rather_than_stale_revision() {
		$request = $this->settings_request( '123e4567-e89b-42d3-a456-426614174936' );

		$applied = $this->staging->abilities_v2( $request );

		$this->assertTrue( $applied['ok'] );
		$this->assertArrayHasKey( '123e4567-e89b-42d3-a456-426614174936', $this->staging->settings_receipts_during_write, 'the reservation must be durable before the provider is written' );
		$this->assertSame( 'dispatching', $this->staging->settings_receipts_during_write['123e4567-e89b-42d3-a456-426614174936']['state'] );

		// The request died before settling: the reservation is all its retry has to go on, and
		// the revision has already moved because this same request moved it.
		$receipts = get_option( MainWP_Child_Staging::ABILITIES_V2_SETTINGS_RECEIPTS_OPTION, array() );
		$receipts['123e4567-e89b-42d3-a456-426614174936'] = $this->staging->settings_receipts_during_write['123e4567-e89b-42d3-a456-426614174936'];
		update_option( MainWP_Child_Staging::ABILITIES_V2_SETTINGS_RECEIPTS_OPTION, $receipts, false );

		$retry = $this->staging->abilities_v2( $request );

		$this->assertFalse( $retry['ok'] );
		$this->assertSame( 'outcome_unknown', $retry['error_code'] );
		$this->assertSame( 1, $this->staging->settings_writes, 'the retry must not write the provider a second time' );
	}

	/** Without WP Staging the Child neither advertises nor performs the settings write. */
	public function test_replace_settings_is_refused_and_writes_nothing_without_wp_staging() {
		delete_option( 'wpstg_settings' );
		$subject = ( new ReflectionClass( MainWP_Child_Staging::class ) )->newInstanceWithoutConstructor();
		$this->assertFalse( $subject->is_plugin_installed, 'WP Staging must be absent for this test to mean anything.' );

		$capabilities = $subject->abilities_v2(
			array(
				'protocol'  => '2',
				'operation' => 'capabilities',
				'payload'   => array(),
			)
		);
		$this->assertSame( array( 'inventory' ), $capabilities['operations'] );
		$this->assertFalse( $capabilities['mutation_supported'] );

		$result = $subject->abilities_v2(
			array(
				'protocol'    => '2',
				'operation'   => 'replace_settings',
				'request_ref' => '123e4567-e89b-42d3-a456-426614174936',
				'payload'     => array(
					'if_match' => str_repeat( 'a', 64 ),
					'settings' => array(
						'query_limit'      => 900,
						'file_limit'       => 250,
						'batch_size_mb'    => 20,
						'max_file_size_mb' => 100,
						'cpu_load'         => 'low',
						'delay_seconds'    => 2,
						'debug_enabled'    => true,
					),
				),
			)
		);

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'unsupported_operation', $result['error_code'] );
		$this->assertFalse( get_option( 'wpstg_settings', false ), 'A provider-absent site must not gain WP Staging settings.' );
		$this->assertSame( array(), get_option( 'mainwp_staging_abilities_v2_receipts', array() ) );
	}

	/**
	 * `wpstg_settings` exists only where WP Staging does. Reading it on a site without the plugin
	 * clamps an absent option into a full set of defaults, so a revision handed back there is a
	 * checksum of settings the site never had.
	 */
	public function test_settings_claims_no_revision_without_wp_staging() {
		delete_option( 'wpstg_settings' );
		$subject = ( new ReflectionClass( MainWP_Child_Staging::class ) )->newInstanceWithoutConstructor();
		$this->assertFalse( $subject->is_plugin_installed, 'WP Staging must be absent for this test to mean anything.' );

		$absent = $subject->abilities_v2(
			array(
				'protocol'  => '2',
				'operation' => 'settings',
				'payload'   => array(),
			)
		);
		$capabilities = $subject->abilities_v2(
			array(
				'protocol'  => '2',
				'operation' => 'capabilities',
				'payload'   => array(),
			)
		);

		$this->assertFalse( $absent['ok'] );
		$this->assertSame( 'unsupported_operation', $absent['error_code'] );
		$this->assertArrayNotHasKey( 'revision', $absent );
		$this->assertNotContains( 'settings', $capabilities['operations'] );

		// With the plugin present the read answers for real provider state and stays available.
		$this->staging->settings = array( 'queryLimit' => 900 );
		$present                 = $this->invoke_v2( 'settings', array() );

		$this->assertTrue( $present['ok'] );
		$this->assertSame( 900, $present['query_limit'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $present['revision'] );
		$this->assertContains( 'settings', $this->invoke_v2( 'capabilities', array() )['operations'] );
	}

	/**
	 * A protocol-2 request has to be answered in protocol 2. The provider gates in action() end the
	 * request with a legacy error blob, which the Dashboard cannot tell apart from an old Child or a
	 * broken transport, so this goes through the real dispatcher rather than calling abilities_v2().
	 */
	public function test_action_answers_a_v2_request_in_protocol_2_without_wp_staging() {
		$subject = new Test_MainWP_Child_Staging_V2_Absent_Provider_Fixture();
		$this->assertFalse( $subject->is_plugin_installed, 'WP Staging must be absent for this test to mean anything.' );

		$_POST['mwp_action'] = 'abilities_v2';
		$_POST['request']    = wp_json_encode(
			array(
				'protocol'  => '2',
				'operation' => 'capabilities',
				'payload'   => array(),
			)
		);
		$gate = static function ( $translation, $text ) {
			if ( in_array( $text, array( 'Please install WP Staging plugin on child website', 'WP Staging failed to load correctly on the child website.' ), true ) ) {
				throw new RuntimeException( 'action() ended the request at the provider gate: ' . $text );
			}
			return $translation;
		};
		add_filter( 'gettext', $gate, 10, 2 );

		$thrown = null;
		try {
			$subject->action();
		} catch ( Throwable $throwable ) {
			$thrown = $throwable;
		}

		remove_filter( 'gettext', $gate, 10 );
		unset( $_POST['mwp_action'], $_POST['request'] );

		$this->assertInstanceOf(
			Test_MainWP_Child_Staging_V2_Dispatched::class,
			$thrown,
			null === $thrown ? 'action() returned without reaching the protocol-2 dispatch.' : $thrown->getMessage()
		);
		$response = $thrown->response;
		$this->assertSame( '2', $response['protocol'] );
		$this->assertSame( 'capabilities', $response['operation'] );
		$this->assertTrue( $response['ok'] );
		$this->assertSame( array( 'inventory' ), $response['operations'] );
		$this->assertFalse( $response['mutation_supported'] );
	}

	/**
	 * Preview answers from a provider scanner, and the base Child has none. Advertising it anyway
	 * sends the Dashboard into a request that can only come back provider_schema_invalid.
	 */
	public function test_preview_is_neither_advertised_nor_dispatched_without_a_provider_scanner() {
		$subject = ( new ReflectionClass( MainWP_Child_Staging::class ) )->newInstanceWithoutConstructor();

		$capabilities = $subject->abilities_v2(
			array(
				'protocol'  => '2',
				'operation' => 'capabilities',
				'payload'   => array(),
			)
		);
		$this->assertNotContains( 'preview', $capabilities['operations'] );

		$preview = $subject->abilities_v2(
			array(
				'protocol'  => '2',
				'operation' => 'preview',
				'payload'   => array(
					'kind'      => 'create',
					'clone_ref' => null,
				),
			)
		);

		$this->assertFalse( $preview['ok'] );
		$this->assertSame( 'unsupported_operation', $preview['error_code'] );
	}

	/** A Child that has wired a scanner keeps advertising and running preview. */
	public function test_preview_stays_available_where_a_provider_scanner_is_wired() {
		$this->assertContains( 'preview', $this->invoke_v2( 'capabilities', array() )['operations'] );

		$this->staging->preview_ready = false;

		$capabilities = $this->invoke_v2( 'capabilities', array() );
		$preview      = $this->invoke_v2(
			'preview',
			array(
				'kind'      => 'create',
				'clone_ref' => null,
			)
		);

		$this->assertNotContains( 'preview', $capabilities['operations'] );
		$this->assertSame( 'unsupported_operation', $preview['error_code'] );
		$this->assertSame( 0, $this->staging->preview_calls, 'A refused preview must never reach the provider seam.' );
	}

	/** Job status is an adapter read, so a Child without the adapter must not offer it. */
	public function test_operation_status_is_neither_advertised_nor_dispatched_without_the_job_adapter() {
		$this->staging->jobs_ready = false;

		$capabilities = $this->invoke_v2( 'capabilities', array() );
		$this->assertSame( array( 'inventory', 'settings', 'preview', 'replace_settings' ), $capabilities['operations'] );

		$status = $this->invoke_v2( 'operation_status', array( 'operation_ref' => '123e4567-e89b-42d3-a456-426614174937' ) );

		$this->assertFalse( $status['ok'] );
		$this->assertSame( 'unsupported_operation', $status['error_code'] );
		$this->assertSame( array(), $this->staging->operation_calls, 'A refused operation must never reach the provider seam.' );
	}

	/** Clone mutations use MCP-safe request references and exact receipt replay. */
	public function test_clone_mutation_contract_is_typed_and_replay_safe() {
		$this->staging->operation_results['create_clone'] = array(
			'operation_ref' => '123e4567-e89b-42d3-a456-426614174931',
			'clone_ref'     => null,
			'status'        => 'queued',
		);
		$request = array(
			'protocol'    => '2',
			'operation'   => 'create_clone',
			'request_ref' => '123e4567-e89b-42d3-a456-426614174932',
			'payload'     => array( 'inventory_revision' => str_repeat( 'a', 64 ) ),
		);
		$result = $this->staging->abilities_v2( $request );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( $request['request_ref'], $result['request_ref'] );
		$this->assertSame( $result, $this->staging->abilities_v2( $request ) );
		$this->assertCount( 1, $this->staging->operation_calls );

		$request['payload']['inventory_revision'] = str_repeat( 'b', 64 );
		$this->assertSame( 'request_conflict', $this->staging->abilities_v2( $request )['error_code'] );
		unset( $request['request_ref'] );
		$request['request_id'] = '123e4567-e89b-42d3-a456-426614174933';
		$this->assertSame( 'invalid_request', $this->staging->abilities_v2( $request )['error_code'] );
	}

	/** Status and reconciliation shapes reject raw or over-broad provider data. */
	public function test_operation_status_and_reconciliation_results_are_closed() {
		$operation_ref = '123e4567-e89b-42d3-a456-426614174934';
		$this->staging->operation_results['operation_status'] = array(
			'operation_ref'   => $operation_ref,
			'kind'            => 'update',
			'status'          => 'running',
			'progress_percent' => 40,
			'current_step'    => 'files',
			'generation'      => str_repeat( 'c', 64 ),
		);
		$status = $this->invoke_v2( 'operation_status', array( 'operation_ref' => $operation_ref ) );
		$this->assertTrue( $status['ok'] );
		$this->assertSame( 40, $status['progress_percent'] );

		$this->staging->operation_results['reconcile_operation'] = array(
			'operation_ref' => $operation_ref,
			'status'        => 'queued',
			'affected_steps' => 2,
			'generation'    => str_repeat( 'd', 64 ),
		);
		$reconcile = $this->staging->abilities_v2(
			array(
				'protocol'    => '2',
				'operation'   => 'reconcile_operation',
				'request_ref' => '123e4567-e89b-42d3-a456-426614174935',
				'payload'     => array( 'operation_ref' => $operation_ref, 'if_match' => str_repeat( 'c', 64 ) ),
			)
		);
		$this->assertTrue( $reconcile['ok'] );
		$this->assertSame( 2, $reconcile['affected_steps'] );
	}

	/** The clone job runs with the mutation lock held, and a lock held elsewhere refuses it outright. */
	public function test_clone_mutation_runs_under_the_named_lock_and_a_held_lock_refuses_it() {
		$this->staging->operation_results['create_clone'] = $this->queued_clone_result();

		$result = $this->staging->abilities_v2( $this->clone_request( '123e4567-e89b-42d3-a456-426614174941' ) );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( '0', (string) $this->staging->lock_free_during_dispatch );
		$this->assertSame( '1', (string) $this->lock_state( $this->staging->fixture_lock_name() ) );

		$this->staging->operation_calls = array();
		$holder                         = $this->hold_lock_elsewhere( $this->staging->fixture_lock_name() );
		$held                           = $this->staging->abilities_v2( $this->clone_request( '123e4567-e89b-42d3-a456-426614174942' ) );
		$this->release_lock_elsewhere( $holder, $this->staging->fixture_lock_name() );

		$this->assertFalse( $held['ok'] );
		$this->assertSame( 'lock_busy', $held['error_code'] );
		$this->assertSame( array(), $this->staging->operation_calls );
		$this->assertArrayNotHasKey( '123e4567-e89b-42d3-a456-426614174942', get_option( 'mainwp_staging_abilities_v2_operation_receipts', array() ) );
	}

	/** A lock backend that cannot answer is refused as a store failure, not as someone else's lock. */
	public function test_an_unusable_lock_backend_is_refused_apart_from_a_held_lock() {
		// The lock name reads home_url(), so it is resolved while the real connection is still in place.
		$this->staging->fixture_lock_name();
		$this->staging->operation_results['create_clone'] = $this->queued_clone_result();
		$request = $this->clone_request( '123e4567-e89b-42d3-a456-426614174943' );

		$backends = array(
			'driver error'     => array( array( 'MySQL server has gone away', null ) ),
			'GET_LOCK is NULL' => array( array( '', null ) ),
		);
		foreach ( $backends as $label => $answers ) {
			$real            = $GLOBALS['wpdb'];
			$GLOBALS['wpdb'] = new Test_MainWP_Child_Staging_V2_Lock_Wpdb( $answers );
			try {
				$result = $this->staging->abilities_v2( $request );
			} finally {
				$GLOBALS['wpdb'] = $real;
			}

			$this->assertFalse( $result['ok'], $label );
			$this->assertSame( 'storage_unavailable', $result['error_code'], $label );
		}
		$this->assertSame( array(), $this->staging->operation_calls );
		$this->assertSame( array(), get_option( 'mainwp_staging_abilities_v2_operation_receipts', array() ) );
	}

	/**
	 * A full store refuses rather than forgetting a receipt a retry still needs, and it comes back
	 * on its own once the oldest entry ages past the retry horizon.
	 */
	public function test_a_full_receipt_store_refuses_before_dispatch_and_self_clears_past_the_horizon() {
		$this->staging->operation_results['create_clone'] = $this->queued_clone_result();
		$oldest = '123e4567-e89b-42d3-a456-4266141749a0';
		update_option( 'mainwp_staging_abilities_v2_operation_receipts', $this->fill_receipts( 100, time(), $oldest ), false );

		$refused = $this->staging->abilities_v2( $this->clone_request( '123e4567-e89b-42d3-a456-426614174944' ) );

		$this->assertFalse( $refused['ok'] );
		$this->assertSame( 'storage_unavailable', $refused['error_code'] );
		$this->assertSame( array(), $this->staging->operation_calls, 'the provider must not run when the outcome cannot be recorded' );
		$this->assertCount( 100, get_option( 'mainwp_staging_abilities_v2_operation_receipts', array() ) );

		// Only the clock changes: the oldest receipt is now past the horizon and nothing else is.
		$receipts                          = get_option( 'mainwp_staging_abilities_v2_operation_receipts', array() );
		$receipts[ $oldest ]['created_at'] = time() - ( DAY_IN_SECONDS + 3600 );
		update_option( 'mainwp_staging_abilities_v2_operation_receipts', $receipts, false );

		$accepted = $this->staging->abilities_v2( $this->clone_request( '123e4567-e89b-42d3-a456-426614174945' ) );
		$stored   = get_option( 'mainwp_staging_abilities_v2_operation_receipts', array() );

		$this->assertTrue( $accepted['ok'] );
		$this->assertCount( 1, $this->staging->operation_calls );
		$this->assertCount( 100, $stored );
		$this->assertArrayNotHasKey( $oldest, $stored, 'the aged receipt is the one given up' );
		$this->assertArrayHasKey( '123e4567-e89b-42d3-a456-426614174945', $stored );
	}

	/**
	 * An unreadable entry is not spare room for somebody else's clone job.
	 *
	 * It is still evidence that a receipt was written for that reference, so dropping it lets the
	 * reference clone a second time on its next retry. It becomes a dated tombstone instead: the
	 * stamp is written once, survives the request that wrote it, and is what lets the entry age
	 * out later instead of holding the store shut.
	 */
	public function test_an_unreadable_entry_becomes_a_dated_tombstone_rather_than_someone_elses_room() {
		$this->staging->operation_results['create_clone'] = $this->queued_clone_result();
		$receipts                                         = $this->fill_receipts( 99, time() );
		// The three-key shape an earlier build of this branch wrote, with no state to read.
		$unreadable              = '123e4567-e89b-42d3-a456-4266141749b0';
		$receipts[ $unreadable ] = array(
			'effect_hash' => str_repeat( 'e', 64 ),
			'response'    => array( 'ok' => true ),
		);
		update_option( 'mainwp_staging_abilities_v2_operation_receipts', $receipts, false );

		$refused = $this->staging->abilities_v2( $this->clone_request( '123e4567-e89b-42d3-a456-426614174946' ) );
		$stored  = get_option( 'mainwp_staging_abilities_v2_operation_receipts', array() );

		$this->assertFalse( $refused['ok'] );
		$this->assertSame( 'storage_unavailable', $refused['error_code'] );
		$this->assertSame( array(), $this->staging->operation_calls, 'no clone job may run behind a refusal' );
		$this->assertArrayHasKey( $unreadable, $stored, 'the damaged entry is still evidence that reference already ran' );
		$this->assertSame( 'unreadable', $stored[ $unreadable ]['state'] );
		$this->assertNull( $stored[ $unreadable ]['response'] );
		$this->assertIsInt( $stored[ $unreadable ]['created_at'], 'the repair has to be persisted or it is re-stamped on every request' );
		foreach ( array_keys( $this->fill_receipts( 99, time() ) ) as $live ) {
			$this->assertArrayHasKey( $live, $stored );
		}

		// The reference behind the tombstone still refuses rather than cloning again.
		$this->assertSame( 'storage_unavailable', $this->staging->abilities_v2( $this->clone_request( $unreadable ) )['error_code'] );
		$this->assertSame( array(), $this->staging->operation_calls );

		// Only the clock changes: a tombstone past the horizon is given up like any other entry,
		// which is only possible because its stamp was frozen rather than renewed on every read.
		$stored[ $unreadable ]['created_at'] = time() - ( DAY_IN_SECONDS + 3600 );
		update_option( 'mainwp_staging_abilities_v2_operation_receipts', $stored, false );

		$accepted = $this->staging->abilities_v2( $this->clone_request( '123e4567-e89b-42d3-a456-426614174950' ) );
		$after    = get_option( 'mainwp_staging_abilities_v2_operation_receipts', array() );

		$this->assertTrue( $accepted['ok'] );
		$this->assertArrayNotHasKey( $unreadable, $after, 'the aged tombstone is the entry given up' );
		$this->assertArrayHasKey( '123e4567-e89b-42d3-a456-426614174950', $after );
	}

	/**
	 * A tombstone this clock cannot date is re-dated, so the store still ages out.
	 *
	 * Keeping the stamp would leave an entry no clock ever reaches, and one of those in a full
	 * store refuses every clone mutation from then on with nothing an operator could do. Re-dating
	 * it costs nothing: the reference behind it still refuses, and now the entry can grow old.
	 */
	public function test_a_tombstone_this_clock_cannot_date_is_redated_rather_than_held_forever() {
		$this->staging->operation_results['create_clone'] = $this->queued_clone_result();
		$receipts                                         = $this->fill_receipts( 99, time() );
		$tombstoned                                       = '123e4567-e89b-42d3-a456-4266141749c0';
		$receipts[ $tombstoned ]                          = array(
			'effect_hash' => str_repeat( '0', 64 ),
			'state'       => 'unreadable',
			'response'    => null,
			'created_at'  => PHP_INT_MAX,
		);
		update_option( 'mainwp_staging_abilities_v2_operation_receipts', $receipts, false );

		$refused = $this->staging->abilities_v2( $this->clone_request( '123e4567-e89b-42d3-a456-426614174952' ) );
		$stored  = get_option( 'mainwp_staging_abilities_v2_operation_receipts', array() );

		$this->assertFalse( $refused['ok'] );
		$this->assertSame( 'storage_unavailable', $refused['error_code'] );
		$this->assertLessThanOrEqual( time(), $stored[ $tombstoned ]['created_at'], 'a stamp no clock reaches has to be replaced with one that ages' );

		// Proof that it now ages out: only the clock changes and the entry becomes the one given up.
		$stored[ $tombstoned ]['created_at'] = time() - ( DAY_IN_SECONDS + 3600 );
		update_option( 'mainwp_staging_abilities_v2_operation_receipts', $stored, false );

		$this->assertTrue( $this->staging->abilities_v2( $this->clone_request( '123e4567-e89b-42d3-a456-426614174953' ) )['ok'] );
		$this->assertArrayNotHasKey( $tombstoned, get_option( 'mainwp_staging_abilities_v2_operation_receipts', array() ) );
	}

	/**
	 * A stamp the tombstone builder rejects must not be taken as a sound tombstone.
	 *
	 * A nonsense stamp sorts to the front of the disposable list, so an entry that is still the
	 * only evidence its reference already ran gets given up to an unrelated request.
	 */
	public function test_a_tombstone_with_an_impossible_stamp_is_rebuilt_rather_than_evicted() {
		$this->staging->operation_results['create_clone'] = $this->queued_clone_result();
		$receipts                                         = $this->fill_receipts( 99, time() );
		$tombstoned                                       = '123e4567-e89b-42d3-a456-4266141749c1';
		$receipts[ $tombstoned ]                          = array(
			'effect_hash' => str_repeat( '0', 64 ),
			'state'       => 'unreadable',
			'response'    => null,
			'created_at'  => -1,
		);
		update_option( 'mainwp_staging_abilities_v2_operation_receipts', $receipts, false );

		$refused = $this->staging->abilities_v2( $this->clone_request( '123e4567-e89b-42d3-a456-426614174954' ) );
		$stored  = get_option( 'mainwp_staging_abilities_v2_operation_receipts', array() );

		$this->assertFalse( $refused['ok'] );
		$this->assertSame( array(), $this->staging->operation_calls );
		$this->assertArrayHasKey( $tombstoned, $stored, 'an impossible stamp is not proof the entry is past the horizon' );
		$this->assertGreaterThan( 0, $stored[ $tombstoned ]['created_at'] );
	}

	/**
	 * A reference whose stored entry is null still refuses rather than cloning again.
	 *
	 * isset() reads a key holding null as absent, and the key existing at all is the evidence
	 * that something wrote a receipt for that reference.
	 */
	public function test_a_null_entry_under_a_reference_refuses_rather_than_dispatching() {
		$this->staging->operation_results['create_clone'] = $this->queued_clone_result();
		$request                                          = $this->clone_request( '123e4567-e89b-42d3-a456-426614174955' );
		update_option( 'mainwp_staging_abilities_v2_operation_receipts', array( $request['request_ref'] => null ), false );

		$result = $this->staging->abilities_v2( $request );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'storage_unavailable', $result['error_code'] );
		$this->assertSame( array(), $this->staging->operation_calls, 'a reference with any stored entry must not reach the provider again' );
	}

	/** A hash the store cannot read is a corrupt store, not a different request. */
	public function test_a_receipt_whose_effect_hash_is_not_a_hash_is_unreadable_rather_than_a_conflict() {
		$this->staging->operation_results['create_clone'] = $this->queued_clone_result();
		$request                                          = $this->clone_request( '123e4567-e89b-42d3-a456-426614174951' );
		update_option(
			'mainwp_staging_abilities_v2_operation_receipts',
			array(
				$request['request_ref'] => array(
					'effect_hash' => 'not-a-sha256',
					'state'       => 'settled',
					'response'    => array( 'protocol' => '2', 'operation' => 'create_clone', 'ok' => true ),
					'created_at'  => time(),
				),
			),
			false
		);

		$result = $this->staging->abilities_v2( $request );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'storage_unavailable', $result['error_code'] );
		$this->assertSame( array(), $this->staging->operation_calls );
	}

	/**
	 * A host clock that jumps backwards must not turn the whole store into spare capacity.
	 *
	 * Every stamp then reads as ahead of the clock, and a receipt that cannot be dated cannot be
	 * shown to be past the retry horizon, so the store owes a refusal rather than someone else's
	 * replay evidence.
	 */
	public function test_a_backwards_clock_jump_refuses_rather_than_dropping_undatable_receipts() {
		$this->staging->operation_results['create_clone'] = $this->queued_clone_result();
		$ahead                                            = time() + ( 2 * DAY_IN_SECONDS );
		update_option( 'mainwp_staging_abilities_v2_operation_receipts', $this->fill_receipts( 100, $ahead ), false );

		$result = $this->staging->abilities_v2( $this->clone_request( '123e4567-e89b-42d3-a456-426614174947' ) );
		$stored = get_option( 'mainwp_staging_abilities_v2_operation_receipts', array() );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'storage_unavailable', $result['error_code'] );
		$this->assertSame( array(), $this->staging->operation_calls, 'no clone job may run behind a refusal' );
		$this->assertCount( 100, $stored, 'not one receipt is given up to make room' );
		foreach ( array_keys( $this->fill_receipts( 100, $ahead ) ) as $reference ) {
			$this->assertArrayHasKey( $reference, $stored );
		}
	}

	/**
	 * The record a retry lands on exists before the clone job starts, not after it returns.
	 *
	 * Between dispatch and the outcome being written there is a window in which the request can
	 * die outright. Without a reservation in the store the Dashboard's retry of the same
	 * reference finds nothing and clones a second time.
	 */
	public function test_a_reservation_is_durable_before_the_clone_job_runs() {
		$this->staging->operation_results['create_clone'] = $this->queued_clone_result();
		$request                                          = $this->clone_request( '123e4567-e89b-42d3-a456-426614174948' );

		$result = $this->staging->abilities_v2( $request );

		$this->assertTrue( $result['ok'] );
		$this->assertArrayHasKey( $request['request_ref'], $this->staging->receipts_during_dispatch, 'the clone job must not run before its reference is recorded' );
		$reserved = $this->staging->receipts_during_dispatch[ $request['request_ref'] ];
		$this->assertSame( 'dispatching', $reserved['state'] );
		$this->assertNull( $reserved['response'] );
		$this->assertSame( hash( 'sha256', wp_json_encode( array( 'create_clone', $request['payload'] ) ) ), $reserved['effect_hash'] );

		$stored = get_option( 'mainwp_staging_abilities_v2_operation_receipts', array() );
		$this->assertSame( 'settled', $stored[ $request['request_ref'] ]['state'] );
		$this->assertSame( $result, $stored[ $request['request_ref'] ]['response'] );
	}

	/** A reservation nobody settled answers outcome_unknown and starts no second clone job. */
	public function test_an_unsettled_reservation_answers_outcome_unknown_without_dispatching() {
		$this->staging->operation_results['create_clone'] = $this->queued_clone_result();
		$request                                          = $this->clone_request( '123e4567-e89b-42d3-a456-426614174949' );
		update_option( 'mainwp_staging_abilities_v2_operation_receipts', array( $request['request_ref'] => $this->reservation( $request, time() ) ), false );

		$result = $this->staging->abilities_v2( $request );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'outcome_unknown', $result['error_code'] );
		$this->assertSame( array(), $this->staging->operation_calls, 'a clone job that may still be running must not be started again' );
	}

	/**
	 * A stamp this clock cannot date holds its reservation open instead of releasing it.
	 *
	 * A host clock that stepped backwards would otherwise retire every reservation in the store
	 * at once, and each retired reference starts its clone job a second time.
	 */
	public function test_an_undatable_reservation_is_not_retired_into_a_second_clone_job() {
		$this->staging->operation_results['create_clone'] = $this->queued_clone_result();
		$request                                          = $this->clone_request( '123e4567-e89b-42d3-a456-42661417494a' );
		update_option( 'mainwp_staging_abilities_v2_operation_receipts', array( $request['request_ref'] => $this->reservation( $request, time() + ( 2 * DAY_IN_SECONDS ) ) ), false );

		$result = $this->staging->abilities_v2( $request );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'outcome_unknown', $result['error_code'] );
		$this->assertSame( array(), $this->staging->operation_calls );
	}

	/** Past the retry horizon a reservation proves nothing, so the reference dispatches again. */
	public function test_a_reservation_past_the_retry_horizon_is_retired_and_dispatches() {
		$this->staging->operation_results['create_clone'] = $this->queued_clone_result();
		$request                                          = $this->clone_request( '123e4567-e89b-42d3-a456-42661417494b' );
		update_option( 'mainwp_staging_abilities_v2_operation_receipts', array( $request['request_ref'] => $this->reservation( $request, time() - ( DAY_IN_SECONDS + 3600 ) ) ), false );

		$result = $this->staging->abilities_v2( $request );
		$stored = get_option( 'mainwp_staging_abilities_v2_operation_receipts', array() );

		$this->assertTrue( $result['ok'] );
		$this->assertCount( 1, $this->staging->operation_calls );
		$this->assertSame( 'settled', $stored[ $request['request_ref'] ]['state'] );
	}

	/** A provider refusal is recorded, so the same reference replays the refusal instead of re-running. */
	public function test_a_refused_clone_job_settles_and_replays_rather_than_running_twice() {
		$this->staging->operation_results['create_clone'] = new WP_Error( 'state_conflict' );
		$request                                          = $this->clone_request( '123e4567-e89b-42d3-a456-42661417494c' );

		$refused = $this->staging->abilities_v2( $request );
		$replay  = $this->staging->abilities_v2( $request );

		$this->assertFalse( $refused['ok'] );
		$this->assertSame( 'state_conflict', $refused['error_code'] );
		$this->assertSame( $refused, $replay );
		$this->assertCount( 1, $this->staging->operation_calls, 'a refusal already recorded must not reach the provider again' );
	}

	/**
	 * An entry filed under a key no request could present is not evidence for anybody.
	 *
	 * References are validated before the store is consulted, so nothing can ever come back for
	 * such an entry. Keeping it would let a store of junk keys hold every later clone mutation
	 * shut, and PHP array keys are not always strings, so one can also be compared as one.
	 */
	public function test_entries_under_keys_no_request_can_present_are_given_up() {
		$this->staging->operation_results['create_clone'] = $this->queued_clone_result();
		$receipts                                         = array();
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
		update_option( 'mainwp_staging_abilities_v2_operation_receipts', $receipts, false );

		$result = $this->staging->abilities_v2( $this->clone_request( '123e4567-e89b-42d3-a456-426614174956' ) );
		$stored = get_option( 'mainwp_staging_abilities_v2_operation_receipts', array() );

		$this->assertTrue( $result['ok'], 'a store nothing can ever replay must not refuse forever' );
		$this->assertArrayHasKey( '123e4567-e89b-42d3-a456-426614174956', $stored );
	}

	/**
	 * A store whose stamps this clock cannot date is re-dated once, so it still ages out.
	 *
	 * Nothing is given up: every receipt is still there and still replays. What changes is that
	 * the store now holds dates it can act on, so it is no longer a store that refuses every
	 * later clone mutation with no way out.
	 */
	public function test_a_store_of_undatable_stamps_is_redated_once_and_then_ages_out() {
		$this->staging->operation_results['create_clone'] = $this->queued_clone_result();
		$ahead                                            = time() + ( 2 * DAY_IN_SECONDS );
		update_option( 'mainwp_staging_abilities_v2_operation_receipts', $this->fill_receipts( 100, $ahead ), false );

		$refused = $this->staging->abilities_v2( $this->clone_request( '123e4567-e89b-42d3-a456-426614174957' ) );
		$stored  = get_option( 'mainwp_staging_abilities_v2_operation_receipts', array() );

		$this->assertFalse( $refused['ok'], 'a full store still refuses rather than dropping evidence' );
		$this->assertSame( array(), $this->staging->operation_calls );
		$this->assertCount( 100, $stored, 'not one receipt is given up to make room' );
		foreach ( $stored as $reference => $receipt ) {
			$this->assertLessThanOrEqual( time(), $receipt['created_at'], $reference );
			$this->assertSame( 'settled', $receipt['state'], $reference );
		}

		// Only the clock changes: what was undatable now ages out like any other receipt.
		foreach ( $stored as $reference => $receipt ) {
			$stored[ $reference ]['created_at'] = time() - ( DAY_IN_SECONDS + 3600 );
		}
		update_option( 'mainwp_staging_abilities_v2_operation_receipts', $stored, false );

		$this->assertTrue( $this->staging->abilities_v2( $this->clone_request( '123e4567-e89b-42d3-a456-426614174958' ) )['ok'] );
	}

	/** @param array $request Clone request. @param int $created_at Stamp. @return array */
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
			$reference = 0 === $index && null !== $first ? $first : sprintf( '123e4567-e89b-42d3-a456-4266141750%02d', $index );
			$receipts[ $reference ] = array(
				'effect_hash' => hash( 'sha256', $reference ),
				'state'       => 'settled',
				'response'    => array(
					'protocol'    => '2',
					'operation'   => 'create_clone',
					'ok'          => true,
					'request_ref' => $reference,
				),
				'created_at'  => $created_at,
			);
		}
		return $receipts;
	}

	/** @return array */
	private function queued_clone_result() {
		return array(
			'operation_ref' => '123e4567-e89b-42d3-a456-426614174940',
			'clone_ref'     => null,
			'status'        => 'queued',
		);
	}

	/** @param string $request_ref Reference. @return array */
	private function clone_request( $request_ref ) {
		return array(
			'protocol'    => '2',
			'operation'   => 'create_clone',
			'request_ref' => $request_ref,
			'payload'     => array( 'inventory_revision' => str_repeat( 'a', 64 ) ),
		);
	}

	/** @param string $request_ref Reference. @return array */
	private function settings_request( $request_ref ) {
		if ( array() === $this->staging->settings ) {
			$this->staging->settings = array(
				'queryLimit'        => 1000,
				'fileLimit'         => 500,
				'batchSize'         => 10,
				'maxFileSize'       => 50,
				'cpuLoad'           => 'medium',
				'delayRequests'     => 1,
				'debugMode'         => 0,
				'disableAdminLogin' => 0,
			);
		}
		$current = $this->invoke_v2( 'settings', array() );
		return array(
			'protocol'    => '2',
			'operation'   => 'replace_settings',
			'request_ref' => $request_ref,
			'payload'     => array(
				'if_match' => $current['revision'],
				'settings' => array(
					'query_limit'      => 900,
					'file_limit'       => 250,
					'batch_size_mb'    => 20,
					'max_file_size_mb' => 100,
					'cpu_load'         => 'low',
					'delay_seconds'    => 2,
					'debug_enabled'    => true,
				),
			),
		);
	}

	/** @param string $name Lock name. @return string|null */
	private function lock_state( $name ) {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', $name ) );
	}

	/** @param string $name Lock name. @return wpdb */
	private function hold_lock_elsewhere( $name ) {
		$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
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

	/** @param wpdb $other Connection. @param string $name Lock name. */
	private function release_lock_elsewhere( $other, $name ) {
		$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		$other->close();
	}

	/** Legacy action names remain available. */
	public function test_legacy_dispatcher_contract_is_preserved() {
		$source = file_get_contents( dirname( __DIR__ ) . '/class/class-mainwp-child-staging.php' );
		foreach ( array( 'get_overview', 'get_scan', 'start_clone', 'clone_database', 'copy_files', 'delete_clone', 'staging_update' ) as $action ) {
			$this->assertStringContainsString( "case '" . $action . "':", $source );
		}
	}

	/** @param string $operation Operation. @param array $payload Payload. @return array */
	private function invoke_v2( $operation, $payload ) {
		return $this->staging->abilities_v2(
			array(
				'protocol'  => '2',
				'operation' => $operation,
				'payload'   => $payload,
			)
		);
	}
}
