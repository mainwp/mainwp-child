<?php
/**
 * WooCommerce Status abilities-v2 protocol tests.
 *
 * @package MainWP_Child
 */

namespace MainWP\Child;

use ReflectionClass;
use WP_UnitTestCase;

class WooCommerce_Status_V2_Fixture extends MainWP_Child_WooCommerce_Status {

	/** @var mixed */
	public $runtime;

	/** @var mixed */
	public $page;

	/** @var mixed */
	public $readiness;

	/** @var int */
	public $started = 0;

	/** @var array */
	public $receipts = array();

	/** @var string|null */
	public $lease = null;

	/** @var string|false */
	public $source_generation;

	protected function abilities_v2_runtime() {
		return $this->runtime;
	}

	protected function abilities_v2_order_page( $payload, $runtime ) {
		return $this->page;
	}

	protected function abilities_v2_source_generation( $payload ) {
		return $this->source_generation;
	}

	protected function abilities_v2_db_readiness( $runtime ) {
		return $this->readiness;
	}

	protected function abilities_v2_start_db_update() {
		++$this->started;
		return 2;
	}

	protected function abilities_v2_acquire_db_lease( $request_ref ) {
		if ( null !== $this->lease && $this->lease !== $request_ref ) {
			return false;
		}
		$this->lease = $request_ref;
		return true;
	}

	protected function abilities_v2_release_db_lease( $request_ref ) {
		if ( $this->lease === $request_ref ) {
			$this->lease = null;
		}
		return true;
	}

	protected function abilities_v2_db_receipt( $request_ref ) {
		return isset( $this->receipts[ $request_ref ] ) ? $this->receipts[ $request_ref ] : null;
	}

	protected function abilities_v2_store_db_receipt( $request_ref, $payload, $response ) {
		$this->receipts[ $request_ref ] = array(
			'request_hash' => hash( 'sha256', wp_json_encode( $payload ) ),
			'response'     => $response,
			'requested_at' => 100,
		);
		return true;
	}
}

/** Fixture that keeps the real option storage so read paths can be watched. */
class WooCommerce_Status_V2_Storage_Fixture extends MainWP_Child_WooCommerce_Status {

	/** @var mixed */
	public $runtime;

	/** @var mixed */
	public $page;

	/** @var mixed */
	public $readiness;

	/** @var string|false */
	public $source_generation;

	protected function abilities_v2_runtime() {
		return $this->runtime;
	}

	protected function abilities_v2_order_page( $payload, $runtime ) {
		return $this->page;
	}

	protected function abilities_v2_source_generation( $payload ) {
		return $this->source_generation;
	}

	protected function abilities_v2_db_readiness( $runtime ) {
		return $this->readiness;
	}
}

/** Fixture that keeps the real lease and receipt storage while stubbing the provider. */
class WooCommerce_Status_V2_Lease_Fixture extends MainWP_Child_WooCommerce_Status {

	/** @var mixed */
	public $runtime;

	/** @var mixed */
	public $readiness;

	/** @var int */
	public $started = 0;

	protected function abilities_v2_runtime() {
		return $this->runtime;
	}

	protected function abilities_v2_db_readiness( $runtime ) {
		return $this->readiness;
	}

	protected function abilities_v2_start_db_update() {
		++$this->started;
		return 2;
	}
}

/** Fixture that keeps the real aggregation and generation code. */
class WooCommerce_Status_V2_Order_Fixture extends MainWP_Child_WooCommerce_Status {

	/** @var mixed */
	public $runtime;

	/** @var array */
	public $inventory = array(
		'processing_orders'     => 2,
		'on_hold_orders'        => 1,
		'low_stock'             => 3,
		'out_of_stock'          => 0,
		'inventory_observed_at' => '2026-08-10T12:00:00Z',
	);

	/** @var array */
	public $orders = array();

	protected function abilities_v2_runtime() {
		return $this->runtime;
	}

	protected function abilities_v2_inventory_counts() {
		return $this->inventory;
	}

	protected function abilities_v2_query_orders( $args ) {
		$result = new \stdClass();
		if ( 1 === $args['limit'] && isset( $args['orderby'] ) && 'modified' === $args['orderby'] ) {
			$result->orders = array_slice( $this->orders, 0, 1 );
			$result->total  = count( $this->orders );
			return $result;
		}
		$result->orders = array_slice( $this->orders, isset( $args['offset'] ) ? $args['offset'] : 0, $args['limit'] );
		$result->total  = count( $this->orders );
		return $result;
	}
}

/** Minimal WooCommerce order line item. */
class WooCommerce_Status_V2_Item {

	/** @var int */
	private $product_id;

	/** @var string */
	private $name;

	/** @var int */
	private $quantity;

	public function __construct( $product_id, $name, $quantity ) {
		$this->product_id = $product_id;
		$this->name       = $name;
		$this->quantity   = $quantity;
	}

	public function get_product_id() {
		return $this->product_id;
	}

	public function get_name() {
		return $this->name;
	}

	public function get_quantity() {
		return $this->quantity;
	}
}

/** Minimal WooCommerce order. */
class WooCommerce_Status_V2_Order {

	/** @var int */
	private $id;

	/** @var array */
	private $items;

	public function __construct( $id, $items ) {
		$this->id    = $id;
		$this->items = $items;
	}

	public function get_id() {
		return $this->id;
	}

	public function get_date_modified() {
		return new \DateTimeImmutable( '@1770000000' );
	}

	public function get_currency() {
		return 'usd';
	}

	public function get_total() {
		return '10.00';
	}

	public function get_total_refunded() {
		return '0';
	}

	public function get_items( $type = 'line_item' ) {
		unset( $type );
		return $this->items;
	}

	public function get_qty_refunded_for_item( $item_key ) {
		unset( $item_key );
		return 0;
	}
}

class Test_MainWP_Child_WooCommerce_Status_V2 extends WP_UnitTestCase {

	/** @var WooCommerce_Status_V2_Fixture */
	private $subject;

	public function set_up(): void {
		parent::set_up();
		$reflection    = new ReflectionClass( WooCommerce_Status_V2_Fixture::class );
		$this->subject = $reflection->newInstanceWithoutConstructor();
		$this->subject->runtime = array(
			'wc_version'               => '10.9.4',
			'storage_mode'             => 'hpos',
			'store_timezone'           => 'America/New_York',
			'current_db_version'       => '10.8.0',
			'target_db_version'        => '10.9.4',
			'database_update_needed'   => true,
		);
		$this->subject->page = array(
			'next_cursor'           => null,
			'complete'              => true,
			'page_size'             => 100,
			'order_count'           => 2,
			'total_orders'          => 2,
			'currency_totals'       => array( array( 'currency' => 'USD', 'minor_unit' => 2, 'net_sales_minor' => '125050' ) ),
			'top_sellers'           => array( array( 'product_id' => 41, 'name' => 'Widget', 'net_quantity' => '9' ) ),
			'processing_orders'     => 2,
			'on_hold_orders'        => 1,
			'low_stock'             => 3,
			'out_of_stock'          => 0,
			'inventory_observed_at' => '2026-08-10T12:00:00Z',
			'generated_at'          => '2026-08-10T12:01:00Z',
			'source'                => 'woocommerce_crud',
			'storage_mode'          => 'hpos',
			'accounting_profile'    => 'net-order-total-v1',
		);
		$this->subject->readiness = array(
			'callback_hashes' => array( str_repeat( 'b', 64 ), str_repeat( 'c', 64 ) ),
			'conflict_hashes' => array(),
			'state'           => 'ready',
		);
		$this->subject->source_generation = str_repeat( 'e', 64 );
	}

	public function test_capabilities_are_closed_and_claim_the_complete_protocol() {
		$result = $this->request( 'capabilities', array() );

		$this->assertSame( array( 'protocol', 'operation', 'ok', 'operations', 'mutation_supported' ), array_keys( $result ) );
		$this->assertSame( array( 'status_v2_prepare', 'status_v2_page', 'db_update_v2_prepare', 'db_update_v2_start', 'db_update_v2_status' ), $result['operations'] );
		$this->assertTrue( $result['mutation_supported'] );
	}

	public function test_prepare_returns_bounded_runtime_identity_without_commerce_data() {
		$result = $this->prepare();

		$this->assertTrue( $result['ok'] );
		$this->assertSame( '123e4567-e89b-42d3-a456-426614174001', $result['request_ref'] );
		$this->assertSame( str_repeat( 'a', 64 ), $result['site_fingerprint'] );
		$this->assertSame( 'hpos', $result['storage_mode'] );
		$this->assertSame( 'America/New_York', $result['store_timezone'] );
		$this->assertTrue( $result['database_update_needed'] );
		$this->assertSame( 'net-order-total-v1', $result['accounting_profile'] );
		$this->assertSame( 250, $result['page_size_max'] );
		$this->assertSame( 100000, $result['order_limit'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/D', $result['preparation_generation'] );
		$this->assertSame( array(), array_intersect( array( 'currency_totals', 'top_sellers', 'orders', 'customers' ), array_keys( $result ) ) );
	}

	public function test_prepare_rejects_invalid_period_identity_and_uuid_alias() {
		$payload                         = $this->payload();
		$payload['end_at']               = $payload['start_at'];
		$this->assertSame( 'invalid_request', $this->request( 'status_v2_prepare', $payload )['code'] );

		$payload                         = $this->payload();
		$payload['site_fingerprint']      = 'not-a-fingerprint';
		$this->assertSame( 'invalid_request', $this->request( 'status_v2_prepare', $payload )['code'] );

		$payload                         = $this->payload();
		$payload['request_id']           = $payload['request_ref'];
		unset( $payload['request_ref'] );
		$this->assertSame( 'invalid_request', $this->request( 'status_v2_prepare', $payload )['code'] );
	}

	public function test_missing_or_malformed_runtime_fails_closed() {
		$this->subject->runtime = false;
		$this->assertSame( 'woocommerce_unavailable', $this->prepare()['code'] );

		$this->subject->runtime = array(
			'wc_version'             => '10.9.4',
			'storage_mode'           => 'maybe',
			'store_timezone'         => 'UTC',
			'current_db_version'     => '10.8.0',
			'target_db_version'      => '10.9.4',
			'database_update_needed' => true,
		);
		$this->assertSame( 'runtime_invalid', $this->prepare()['code'] );
	}

	public function test_status_page_binds_prepare_cursor_completeness_and_hash() {
		$prepare = $this->prepare();
		$payload = array_merge(
			$this->payload(),
			array(
				'preparation_generation' => $prepare['preparation_generation'],
				'cursor'                 => null,
				'page_size'              => 100,
			)
		);
		$result = $this->request( 'status_v2_page', $payload );

		$this->assertTrue( $result['ok'] );
		$this->assertTrue( $result['complete'] );
		$this->assertNull( $result['next_cursor'] );
		$this->assertSame( '125050', $result['currency_totals'][0]['net_sales_minor'] );
		$hash = $result['response_hash'];
		unset( $result['response_hash'] );
		$this->assertSame( hash( 'sha256', wp_json_encode( $result ) ), $hash );

		$payload['preparation_generation'] = str_repeat( 'f', 64 );
		$this->assertSame( 'preparation_drift', $this->request( 'status_v2_page', $payload )['code'] );

		$payload['preparation_generation'] = $prepare['preparation_generation'];
		$this->subject->source_generation  = str_repeat( 'f', 64 );
		$this->assertSame( 'preparation_drift', $this->request( 'status_v2_page', $payload )['code'] );
	}

	public function test_malformed_or_oversized_status_page_is_partial_not_zero() {
		$prepare = $this->prepare();
		$payload = array_merge( $this->payload(), array( 'preparation_generation' => $prepare['preparation_generation'], 'cursor' => null, 'page_size' => 100 ) );
		$this->subject->page['currency_totals'][0]['net_sales_minor'] = '1.5';

		$this->assertSame( 'partial_result', $this->request( 'status_v2_page', $payload )['code'] );
	}

	public function test_database_prepare_start_replay_and_terminal_status_are_truthful() {
		$identity = array( 'request_ref' => '123e4567-e89b-42d3-a456-426614174002', 'site_fingerprint' => str_repeat( 'a', 64 ) );
		$prepare  = $this->request( 'db_update_v2_prepare', $identity );
		$this->assertTrue( $prepare['ok'] );
		$this->assertSame( 2, $prepare['pending_callback_count'] );
		$this->assertSame( array( str_repeat( 'b', 64 ), str_repeat( 'c', 64 ) ), $prepare['callback_hashes'] );

		$start_payload = array_merge(
			$identity,
			array(
				'current_version'      => $prepare['current_version'],
				'target_version'       => $prepare['target_version'],
				'readiness_generation' => $prepare['readiness_generation'],
			)
		);
		$started = $this->request( 'db_update_v2_start', $start_payload );
		$this->assertSame( 'requested', $started['state'] );
		$this->assertSame( 2, $started['queued_callbacks'] );
		$this->assertSame( $started, $this->request( 'db_update_v2_start', $start_payload ) );
		$this->assertSame( 1, $this->subject->started );

		$this->subject->runtime['current_db_version']       = '10.9.4';
		$this->subject->runtime['database_update_needed']   = false;
		$this->subject->readiness['callback_hashes']        = array();
		$this->subject->readiness['state']                  = 'current';
		$status = $this->request( 'db_update_v2_status', $identity );
		$this->assertSame( 'completed', $status['state'] );
		$this->assertSame( 0, $status['pending_callback_count'] );
		$this->assertSame( $identity['request_ref'], $this->subject->lease );
	}

	public function test_status_page_read_writes_no_observation_option() {
		delete_option( 'mainwp_wc_status_v2_last_observation' );
		$subject = $this->storage_subject();
		$prepare = $subject->abilities_v2( $this->envelope( 'status_v2_prepare', $this->payload() ) );
		$payload = array_merge( $this->payload(), array( 'preparation_generation' => $prepare['preparation_generation'], 'cursor' => null, 'page_size' => 100 ) );

		$page = $subject->abilities_v2( $this->envelope( 'status_v2_page', $payload ) );

		$this->assertTrue( $page['ok'] );
		$this->assertTrue( $page['complete'] );
		$this->assertNull( get_option( 'mainwp_wc_status_v2_last_observation', null ) );
	}

	public function test_db_status_read_keeps_the_lease_it_did_not_take() {
		$identity = array( 'request_ref' => '123e4567-e89b-42d3-a456-426614174004', 'site_fingerprint' => str_repeat( 'a', 64 ) );
		$lease    = array( 'request_ref' => $identity['request_ref'], 'expires_at' => time() + 3600 );
		update_option(
			'mainwp_wc_status_db_update_v2_receipts',
			array(
				$identity['request_ref'] => array(
					'request_hash' => str_repeat( 'a', 64 ),
					'response'     => array(
						'protocol'         => '2',
						'operation'        => 'db_update_v2_start',
						'ok'               => true,
						'request_ref'      => $identity['request_ref'],
						'site_fingerprint' => $identity['site_fingerprint'],
						'current_version'  => '10.8.0',
						'target_version'   => '10.9.4',
						'queued_callbacks' => 2,
						'state'            => 'requested',
					),
					'requested_at' => 100,
				),
			),
			false
		);
		update_option( 'mainwp_wc_status_db_update_v2_lease', $lease, false );
		// A stale observation is exactly what the old read path used to justify deleting the lease.
		update_option( 'mainwp_wc_status_v2_last_observation', array( 'generation' => str_repeat( 'd', 64 ), 'observed_at' => 200 ), false );

		$subject                                  = $this->storage_subject();
		$subject->runtime['current_db_version']   = '10.9.4';
		$subject->runtime['database_update_needed'] = false;
		$subject->readiness                       = array( 'callback_hashes' => array(), 'conflict_hashes' => array(), 'state' => 'current' );

		$status = $subject->abilities_v2( $this->envelope( 'db_update_v2_status', $identity ) );

		$this->assertSame( 'completed', $status['state'] );
		$this->assertSame( $lease, get_option( 'mainwp_wc_status_db_update_v2_lease', null ) );
	}

	/**
	 * The status read cannot release the lease it did not take, so a finished update would hold
	 * its hour-long lease against every later request. A start whose predecessor provably reached
	 * its target version reclaims that lease instead of refusing forever.
	 */
	public function test_start_reclaims_the_lease_of_an_update_that_reached_its_target() {
		$prior = '123e4567-e89b-42d3-a456-426614174005';
		$this->seed_db_lease( $prior, '10.8.0' );
		$subject                                = $this->lease_subject();
		$subject->runtime['current_db_version'] = '10.8.0';

		$identity = array( 'request_ref' => '123e4567-e89b-42d3-a456-426614174006', 'site_fingerprint' => str_repeat( 'a', 64 ) );
		$prepare  = $subject->abilities_v2( $this->envelope( 'db_update_v2_prepare', $identity ) );
		$started  = $subject->abilities_v2( $this->envelope( 'db_update_v2_start', $this->start_payload( $identity, $prepare ) ) );

		$this->assertTrue( $started['ok'], wp_json_encode( $started ) );
		$this->assertSame( 'requested', $started['state'] );
		$this->assertSame( 1, $subject->started );
		$this->assertSame( $identity['request_ref'], get_option( 'mainwp_wc_status_db_update_v2_lease' )['request_ref'] );
	}

	/**
	 * Reclaiming is only allowed on proof. A lease whose update has not reached its target version
	 * is still guarding running work, and the request must be refused without starting one - but
	 * refused because of what the evidence says, not because reclaiming never happens. The same
	 * request, retried once the site reaches that target, has to get through.
	 */
	public function test_start_refuses_a_lease_whose_update_has_not_reached_its_target() {
		$prior = '123e4567-e89b-42d3-a456-426614174007';
		$this->seed_db_lease( $prior, '10.8.0' );
		$lease                                  = get_option( 'mainwp_wc_status_db_update_v2_lease' );
		$subject                                = $this->lease_subject();
		$subject->runtime['current_db_version'] = '10.7.0';

		$identity = array( 'request_ref' => '123e4567-e89b-42d3-a456-426614174008', 'site_fingerprint' => str_repeat( 'a', 64 ) );
		$prepare  = $subject->abilities_v2( $this->envelope( 'db_update_v2_prepare', $identity ) );
		$refused  = $subject->abilities_v2( $this->envelope( 'db_update_v2_start', $this->start_payload( $identity, $prepare ) ) );

		$this->assertFalse( $refused['ok'] );
		$this->assertSame( 'lease_conflict', $refused['code'] );
		$this->assertSame( 0, $subject->started );
		$this->assertSame( $lease, get_option( 'mainwp_wc_status_db_update_v2_lease' ) );

		// The held update drains and stamps its target version; nothing else about the request
		// changes, so only the evidence moved.
		$subject->runtime['current_db_version'] = '10.8.0';
		$prepare                                = $subject->abilities_v2( $this->envelope( 'db_update_v2_prepare', $identity ) );
		$started                                = $subject->abilities_v2( $this->envelope( 'db_update_v2_start', $this->start_payload( $identity, $prepare ) ) );

		$this->assertTrue( $started['ok'], wp_json_encode( $started ) );
		$this->assertSame( 'requested', $started['state'] );
		$this->assertSame( 1, $subject->started );
		$this->assertSame( $identity['request_ref'], get_option( 'mainwp_wc_status_db_update_v2_lease' )['request_ref'] );
	}

	/**
	 * A receipt filed under the lease holder's key but carrying another request's reference is
	 * evidence about that other update, not about the holder. Reclaiming on it would release a
	 * live lease and let a second update run beside the one it guards.
	 */
	public function test_start_refuses_to_reclaim_a_lease_on_another_requests_receipt() {
		$prior = '123e4567-e89b-42d3-a456-426614174009';
		$this->seed_db_lease( $prior, '10.8.0' );
		$receipts = get_option( 'mainwp_wc_status_db_update_v2_receipts' );
		// Everything about this receipt is well formed and terminal - it just belongs to someone else.
		$receipts[ $prior ]['response']['request_ref'] = '123e4567-e89b-42d3-a456-42661417400a';
		update_option( 'mainwp_wc_status_db_update_v2_receipts', $receipts, false );
		$lease                                  = get_option( 'mainwp_wc_status_db_update_v2_lease' );
		$subject                                = $this->lease_subject();
		$subject->runtime['current_db_version'] = '10.8.0';

		$identity = array( 'request_ref' => '123e4567-e89b-42d3-a456-42661417400b', 'site_fingerprint' => str_repeat( 'a', 64 ) );
		$prepare  = $subject->abilities_v2( $this->envelope( 'db_update_v2_prepare', $identity ) );
		$refused  = $subject->abilities_v2( $this->envelope( 'db_update_v2_start', $this->start_payload( $identity, $prepare ) ) );

		$this->assertFalse( $refused['ok'] );
		$this->assertSame( 'lease_conflict', $refused['code'] );
		$this->assertSame( 0, $subject->started );
		$this->assertSame( $lease, get_option( 'mainwp_wc_status_db_update_v2_lease' ) );
	}

	/**
	 * Receipt eviction drops only entries past the retry horizon and fails closed otherwise.
	 *
	 * Insertion-order trimming could discard a receipt db_update_v2_status or the terminal-lease
	 * release still needs. A full store whose every receipt is inside the horizon refuses the write
	 * instead; a single aged receipt frees exactly one slot; a key no request could present goes
	 * first.
	 */
	public function test_db_receipt_eviction_is_horizon_bound_and_fails_closed() {
		$reflection = new ReflectionClass( MainWP_Child_WooCommerce_Status::class );
		$evict      = $reflection->getMethod( 'abilities_v2_evict_db_receipts' );
		$evict->setAccessible( true );
		$subject = $reflection->newInstanceWithoutConstructor();

		$fresh = time();
		$stale = time() - ( 2 * DAY_IN_SECONDS );

		$full = array();
		for ( $i = 1; $i <= 100; $i++ ) {
			$full[ sprintf( '123e4567-e89b-42d3-a456-%012d', $i ) ] = array( 'requested_at' => $fresh );
		}
		$this->assertFalse( $evict->invoke( $subject, $full ), 'A store at cap with only live receipts cannot free a slot.' );

		$victim          = sprintf( '123e4567-e89b-42d3-a456-%012d', 1 );
		$full[ $victim ] = array( 'requested_at' => $stale );
		$freed           = $evict->invoke( $subject, $full );
		$this->assertIsArray( $freed );
		$this->assertArrayNotHasKey( $victim, $freed );
		$this->assertCount( 99, $freed );

		$mixed = array();
		for ( $i = 1; $i <= 99; $i++ ) {
			$mixed[ sprintf( '123e4567-e89b-42d3-a456-%012d', $i ) ] = array( 'requested_at' => $fresh );
		}
		$mixed['not-a-uuid'] = array( 'requested_at' => $fresh );
		$dropped_key         = $evict->invoke( $subject, $mixed );
		$this->assertIsArray( $dropped_key );
		$this->assertArrayNotHasKey( 'not-a-uuid', $dropped_key );
		$this->assertCount( 99, $dropped_key );

		$under_cap = array_slice( $mixed, 0, 50, true );
		$this->assertSame( $under_cap, $evict->invoke( $subject, $under_cap ), 'A store below cap is returned untouched.' );
	}

	private function seed_db_lease( $request_ref, $target_version ) {
		update_option( 'mainwp_wc_status_db_update_v2_lease', array( 'request_ref' => $request_ref, 'expires_at' => time() + 3600 ), false );
		update_option(
			'mainwp_wc_status_db_update_v2_receipts',
			array(
				$request_ref => array(
					'request_hash' => str_repeat( 'a', 64 ),
					'response'     => array(
						'protocol'         => '2',
						'operation'        => 'db_update_v2_start',
						'ok'               => true,
						'request_ref'      => $request_ref,
						'site_fingerprint' => str_repeat( 'a', 64 ),
						'current_version'  => '10.7.0',
						'target_version'   => $target_version,
						'queued_callbacks' => 2,
						'state'            => 'requested',
					),
					'requested_at' => 100,
				),
			),
			false
		);
	}

	private function lease_subject() {
		$subject            = ( new ReflectionClass( WooCommerce_Status_V2_Lease_Fixture::class ) )->newInstanceWithoutConstructor();
		$subject->runtime   = $this->subject->runtime;
		$subject->readiness = $this->subject->readiness;
		return $subject;
	}

	private function start_payload( $identity, $prepare ) {
		return array_merge(
			$identity,
			array(
				'current_version'      => $prepare['current_version'],
				'target_version'       => $prepare['target_version'],
				'readiness_generation' => $prepare['readiness_generation'],
			)
		);
	}

	public function test_status_page_truncates_top_sellers_to_the_requested_limit() {
		$subject = $this->order_subject();
		$payload = array_merge( $this->payload(), array( 'top_limit' => 2 ) );
		$prepare = $subject->abilities_v2( $this->envelope( 'status_v2_prepare', $payload ) );
		$this->assertTrue( $prepare['ok'] );

		$page = $subject->abilities_v2(
			$this->envelope(
				'status_v2_page',
				array_merge( $payload, array( 'preparation_generation' => $prepare['preparation_generation'], 'cursor' => null, 'page_size' => 100 ) )
			)
		);

		$this->assertTrue( $page['ok'], wp_json_encode( $page ) );
		$this->assertCount( 2, $page['top_sellers'] );
		$this->assertSame( array( 43, 42 ), array_column( $page['top_sellers'], 'product_id' ) );
		$this->assertSame( array( '7', '5' ), array_column( $page['top_sellers'], 'net_quantity' ) );
	}

	public function test_inventory_change_between_prepare_and_page_is_reported_as_drift() {
		$prepare_subject = $this->order_subject();
		$prepare         = $prepare_subject->abilities_v2( $this->envelope( 'status_v2_prepare', $this->payload() ) );
		$this->assertTrue( $prepare['ok'] );

		$page_subject                          = $this->order_subject();
		$page_subject->inventory['low_stock']  = 4;
		$page                                  = $page_subject->abilities_v2(
			$this->envelope(
				'status_v2_page',
				array_merge( $this->payload(), array( 'preparation_generation' => $prepare['preparation_generation'], 'cursor' => null, 'page_size' => 100 ) )
			)
		);

		$this->assertFalse( $page['ok'] );
		$this->assertSame( 'preparation_drift', $page['code'] );
	}

	public function test_low_stock_counts_managed_products_at_or_below_the_store_threshold() {
		update_option( 'woocommerce_notify_low_stock_amount', 5 );
		update_option( 'woocommerce_notify_no_stock_amount', 0 );
		$this->product( 'product', '2', 'yes' );
		$this->product( 'product', '0', 'yes' );
		$this->product( 'product', '9', 'yes' );
		$this->product( 'product', '2', 'no' );
		$this->product( 'product_variation', '1', null );

		$reflection = new ReflectionClass( MainWP_Child_WooCommerce_Status::class );
		$method     = $reflection->getMethod( 'abilities_v2_low_stock_count' );
		$method->setAccessible( true );

		$this->assertSame( 2, $method->invoke( $reflection->newInstanceWithoutConstructor() ) );
	}

	/**
	 * The inventory counters and the page validator have to agree on how large a store may be.
	 * A count between the two bounds used to make the counter fail, and the whole status request
	 * with it, on a store the validator would have accepted.
	 *
	 * Two counters carry that bound: the low-stock query, driven here, and the loop over the
	 * order and product query objects. The second cannot be driven from the suite - WooCommerce
	 * is absent, so wc_get_products() does not exist and the whole read returns before reaching
	 * the loop, and a global stub cannot be declared from this namespaced file. Its bound is read
	 * out of the shipped source instead and held to the one the page validator enforces.
	 */
	public function test_inventory_count_between_the_old_and_the_validator_bound_is_reported() {
		$rewrite = static function ( $query ) {
			return false !== strpos( $query, "meta_key = '_stock'" ) ? 'SELECT 50000' : $query;
		};
		add_filter( 'query', $rewrite );

		try {
			$reflection = new ReflectionClass( MainWP_Child_WooCommerce_Status::class );
			$method     = $reflection->getMethod( 'abilities_v2_low_stock_count' );
			$method->setAccessible( true );

			$this->assertSame( 50000, $method->invoke( $reflection->newInstanceWithoutConstructor() ) );
		} finally {
			remove_filter( 'query', $rewrite );
		}

		$validator = $reflection->getMethod( 'abilities_v2_valid_page_data' );
		$validator->setAccessible( true );
		$page = $this->page_data( 50000 );
		$this->assertTrue( $validator->invoke( $reflection->newInstanceWithoutConstructor(), $page, array( 'page_size' => 50, 'top_limit' => 5 ) ) );

		$accepted = $this->enforced_bound( 'abilities_v2_valid_page_data', '/([0-9]+)\s*<\s*\$page\[\s*\$field\s*\]/' );
		$this->assertSame( $accepted, $this->enforced_bound( 'abilities_v2_inventory_counts', '/([0-9]+)\s*<\s*\$counted->total/' ), 'The inventory objects and the page validator must accept the same store size.' );
		$this->assertGreaterThanOrEqual( 50000, $accepted );
	}

	/**
	 * Read one numeric bound out of the shipped method that enforces it.
	 *
	 * Nothing in the suite can reach the guard behind an absent WooCommerce, and a copy of the
	 * number here would keep passing after the guard changed. The assertion is tied to the
	 * source of the method it is about instead.
	 */
	private function enforced_bound( $method, $pattern ) {
		$reflection = new \ReflectionMethod( MainWP_Child_WooCommerce_Status::class, $method );
		$lines      = file( $reflection->getFileName() );
		$source     = implode( '', array_slice( $lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1 ) );
		$this->assertSame( 1, preg_match( $pattern, $source, $bound ), sprintf( 'No bound matching %s was found in %s().', $pattern, $method ) );
		return (int) $bound[1];
	}

	/**
	 * One internal page carrying the given inventory counts.
	 */
	private function page_data( $count ) {
		return array(
			'next_cursor'           => null,
			'complete'              => true,
			'page_size'             => 50,
			'order_count'           => 0,
			'total_orders'          => 0,
			'currency_totals'       => array(),
			'top_sellers'           => array(),
			'processing_orders'     => $count,
			'on_hold_orders'        => $count,
			'low_stock'             => $count,
			'out_of_stock'          => $count,
			'inventory_observed_at' => '2026-08-10T12:00:00Z',
			'generated_at'          => '2026-08-10T12:00:00Z',
			'source'                => 'woocommerce_crud',
			'storage_mode'          => 'legacy',
			'accounting_profile'    => 'net-order-total-v1',
		);
	}

	private function product( $post_type, $stock, $managed ) {
		$post_id = self::factory()->post->create( array( 'post_type' => $post_type, 'post_status' => 'publish' ) );
		update_post_meta( $post_id, '_stock', $stock );
		if ( null !== $managed ) {
			update_post_meta( $post_id, '_manage_stock', $managed );
		}
		return $post_id;
	}

	private function storage_subject() {
		$subject                  = ( new ReflectionClass( WooCommerce_Status_V2_Storage_Fixture::class ) )->newInstanceWithoutConstructor();
		$subject->runtime         = $this->subject->runtime;
		$subject->page            = $this->subject->page;
		$subject->readiness       = $this->subject->readiness;
		$subject->source_generation = str_repeat( 'e', 64 );
		return $subject;
	}

	private function order_subject() {
		$subject          = ( new ReflectionClass( WooCommerce_Status_V2_Order_Fixture::class ) )->newInstanceWithoutConstructor();
		$subject->runtime = $this->subject->runtime;
		$subject->orders  = array(
			new WooCommerce_Status_V2_Order(
				7001,
				array(
					'line_1' => new WooCommerce_Status_V2_Item( 41, 'Small', 3 ),
					'line_2' => new WooCommerce_Status_V2_Item( 42, 'Medium', 5 ),
					'line_3' => new WooCommerce_Status_V2_Item( 43, 'Large', 7 ),
				)
			),
		);
		return $subject;
	}

	private function envelope( $operation, $payload ) {
		return array(
			'protocol'  => '2',
			'operation' => $operation,
			'payload'   => $payload,
		);
	}

	public function test_database_start_rejects_uuid_id_alias_and_readiness_drift() {
		$identity = array( 'request_ref' => '123e4567-e89b-42d3-a456-426614174003', 'site_fingerprint' => str_repeat( 'a', 64 ) );
		$prepare  = $this->request( 'db_update_v2_prepare', $identity );
		$payload  = array_merge( $identity, array( 'current_version' => $prepare['current_version'], 'target_version' => $prepare['target_version'], 'readiness_generation' => str_repeat( 'f', 64 ) ) );
		$this->assertSame( 'readiness_drift', $this->request( 'db_update_v2_start', $payload )['code'] );

		$identity['request_id'] = $identity['request_ref'];
		unset( $identity['request_ref'] );
		$this->assertSame( 'invalid_request', $this->request( 'db_update_v2_prepare', $identity )['code'] );
	}

	public function test_decimal_and_signed_integer_arithmetic_is_exact_without_floats() {
		$reflection = new ReflectionClass( MainWP_Child_WooCommerce_Status::class );
		$decimal    = $reflection->getMethod( 'abilities_v2_decimal_to_minor' );
		$add        = $reflection->getMethod( 'abilities_v2_integer_add' );
		$subtract   = $reflection->getMethod( 'abilities_v2_integer_subtract' );
		$decimal->setAccessible( true );
		$add->setAccessible( true );
		$subtract->setAccessible( true );

		$this->assertSame( '125050', $decimal->invoke( $this->subject, '1250.50', 2 ) );
		$this->assertSame( '-5', $decimal->invoke( $this->subject, '-0.05', 2 ) );
		$this->assertSame( '1000000000000000000000000000000', $add->invoke( $this->subject, '999999999999999999999999999999', '1' ) );
		$this->assertSame( '-25', $subtract->invoke( $this->subject, '75', '100' ) );
		$this->assertFalse( $decimal->invoke( $this->subject, '1.005', 2 ) );
	}

	private function prepare() {
		return $this->request( 'status_v2_prepare', $this->payload() );
	}

	private function payload() {
		return array(
			'request_ref'      => '123e4567-e89b-42d3-a456-426614174001',
			'site_fingerprint' => str_repeat( 'a', 64 ),
			'start_at'         => '2026-08-01T04:00:00Z',
			'end_at'           => '2026-08-10T12:00:00Z',
			'top_limit'        => 10,
		);
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
