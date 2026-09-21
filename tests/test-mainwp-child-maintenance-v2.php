<?php
/**
 * Maintenance abilities-v2 negotiation tests.
 *
 * @package MainWP_Child
 */

namespace MainWP\Child;

use ReflectionClass;
use WP_UnitTestCase;

class Test_MainWP_Child_Maintenance_V2 extends WP_UnitTestCase {

	/** @var MainWP_Child_Maintenance */
	private $subject;

	public function set_up(): void {
		parent::set_up();
		delete_option( MainWP_Child_Maintenance::ABILITIES_V2_LOCK_OPTION );
		delete_option( MainWP_Child_Maintenance::ABILITIES_V2_OPERATIONS_OPTION );
		$reflection    = new ReflectionClass( Testable_MainWP_Child_Maintenance::class );
		$this->subject = $reflection->newInstanceWithoutConstructor();
	}

	public function tear_down(): void {
		delete_option( MainWP_Child_Maintenance::ABILITIES_V2_LOCK_OPTION );
		delete_option( MainWP_Child_Maintenance::ABILITIES_V2_OPERATIONS_OPTION );
		parent::tear_down();
	}

	public function test_capabilities_are_closed_and_claim_no_unimplemented_operation() {
		$result = $this->request( 'capabilities', array() );

		$this->assertSame( array( 'protocol', 'operation', 'ok', 'operations', 'mutation_supported' ), array_keys( $result ) );
		$this->assertSame( '2', $result['protocol'] );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( array( 'ability_maintenance_preview_v2', 'ability_maintenance_execute_v2', 'ability_maintenance_operation_v2' ), $result['operations'] );
		$this->assertTrue( $result['mutation_supported'] );
	}

	public function test_execute_stages_replays_and_reports_exactly_once() {
		$this->subject->preview_impacts = array(
			'revisions'          => array( 'would_affect' => 3, 'capability' => true ),
			'transients_expired' => array( 'would_affect' => 2, 'capability' => true ),
		);
		$this->subject->action_results  = array(
			'revisions'          => array( 'status' => 'succeeded', 'affected' => 3, 'error_code' => null ),
			'transients_expired' => array( 'status' => 'succeeded', 'affected' => 2, 'error_code' => null ),
		);
		$preview                       = $this->request(
			'ability_maintenance_preview_v2',
			array( 'actions' => array( 'transients_expired', 'revisions' ), 'revision_retention' => 5 )
		);
		$payload                       = array(
			'operation_ref'     => '123e4567-e89b-42d3-a456-426614174506',
			'actions'           => array( 'transients_expired', 'revisions' ),
			'revision_retention' => 5,
			'snapshot_revision' => $preview['snapshot_revision'],
			'action_hash'       => hash( 'sha256', wp_json_encode( array( array( 'revisions', 'transients_expired' ), 5 ) ) ),
		);

		$result = $this->request( 'ability_maintenance_execute_v2', $payload );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'succeeded', $result['status'] );
		$this->assertSame( array( 'revisions', 'transients_expired' ), array_column( $result['outcomes'], 'action' ) );
		$this->assertSame( array( 'revisions', 'transients_expired' ), $this->subject->execution_log );
		$this->assertSame( 1, $this->subject->report_count );
		$this->assertNotEmpty( $this->subject->write_snapshots );
		$this->assertSame( 'running', $this->subject->write_snapshots[0][ $payload['operation_ref'] ]['status'] );

		$replay = $this->request( 'ability_maintenance_execute_v2', $payload );
		$this->assertSame( $result, $replay );
		$this->assertSame( array( 'revisions', 'transients_expired' ), $this->subject->execution_log );
		$this->assertSame( 1, $this->subject->report_count );

		$status = $this->request( 'ability_maintenance_operation_v2', array( 'operation_ref' => $payload['operation_ref'] ) );
		$this->assertTrue( $status['ok'] );
		$this->assertSame( 'ability_maintenance_operation_v2', $status['operation'] );
		$this->assertSame( 'succeeded', $status['status'] );
		$this->assertSame( $result['outcomes'], $status['outcomes'] );
	}

	public function test_execute_rejects_stale_preview_conflicts_and_failed_staging() {
		$this->subject->preview_impacts = array( 'spam' => array( 'would_affect' => 1, 'capability' => true ) );
		$preview                       = $this->request( 'ability_maintenance_preview_v2', array( 'actions' => array( 'spam' ), 'revision_retention' => 5 ) );
		$payload                       = array(
			'operation_ref'      => '123e4567-e89b-42d3-a456-426614174507',
			'actions'            => array( 'spam' ),
			'revision_retention' => 5,
			'snapshot_revision'  => $preview['snapshot_revision'],
			'action_hash'        => hash( 'sha256', wp_json_encode( array( array( 'spam' ), 5 ) ) ),
		);

		$stale                      = $payload;
		$stale['snapshot_revision'] = str_repeat( 'a', 64 );
		$this->assertSame( 'stale_snapshot', $this->request( 'ability_maintenance_execute_v2', $stale )['code'] );

		$this->subject->write_operations_succeeds = false;
		$this->assertSame( 'storage_unavailable', $this->request( 'ability_maintenance_execute_v2', $payload )['code'] );
		$this->assertSame( array(), $this->subject->execution_log );
		$this->subject->write_operations_succeeds = true;
		$this->subject->begin_mutation_succeeds    = false;
		$this->assertSame( 'lock_busy', $this->request( 'ability_maintenance_execute_v2', $payload )['code'] );
		$this->assertSame( array(), $this->subject->execution_log );
		$this->subject->begin_mutation_succeeds = true;
		$this->subject->action_results['spam']  = array( 'status' => 'succeeded', 'affected' => 1, 'error_code' => null );
		$this->assertTrue( $this->request( 'ability_maintenance_execute_v2', $payload )['ok'] );
		$this->assertSame( array( 'spam' ), $this->subject->execution_log );

		$conflict                     = $payload;
		$conflict['revision_retention'] = 4;
		$conflict['action_hash']        = hash( 'sha256', wp_json_encode( array( array( 'spam' ), 4 ) ) );
		$this->subject->preview_impacts = array( 'spam' => array( 'would_affect' => 1, 'capability' => true ) );
		$conflict_preview               = $this->request( 'ability_maintenance_preview_v2', array( 'actions' => array( 'spam' ), 'revision_retention' => 4 ) );
		$conflict['snapshot_revision']  = $conflict_preview['snapshot_revision'];
		$this->assertSame( 'request_conflict', $this->request( 'ability_maintenance_execute_v2', $conflict )['code'] );
	}

	public function test_execute_persists_partial_results_and_status_fails_closed() {
		$this->subject->preview_impacts = array(
			'autodraft' => array( 'would_affect' => 2, 'capability' => true ),
			'optimize'  => array( 'would_affect' => 1, 'capability' => true ),
		);
		$this->subject->action_results  = array(
			'autodraft' => array( 'status' => 'succeeded', 'affected' => 2, 'error_code' => null ),
			'optimize'  => array( 'status' => 'failed', 'affected' => null, 'error_code' => 'mutation_failed' ),
		);
		$preview                       = $this->request( 'ability_maintenance_preview_v2', array( 'actions' => array( 'optimize', 'autodraft' ), 'revision_retention' => 5 ) );
		$payload                       = array(
			'operation_ref'      => '123e4567-e89b-42d3-a456-426614174508',
			'actions'            => array( 'optimize', 'autodraft' ),
			'revision_retention' => 5,
			'snapshot_revision'  => $preview['snapshot_revision'],
			'action_hash'        => hash( 'sha256', wp_json_encode( array( array( 'autodraft', 'optimize' ), 5 ) ) ),
		);

		$result = $this->request( 'ability_maintenance_execute_v2', $payload );
		$this->assertSame( 'partial', $result['status'] );
		$this->assertSame( array( 'succeeded', 'failed' ), array_column( $result['outcomes'], 'status' ) );
		$this->assertFalse( $result['retryable'] );
		$this->assertSame( 1, $this->subject->report_count );

		$missing = $this->request( 'ability_maintenance_operation_v2', array( 'operation_ref' => '123e4567-e89b-42d3-a456-426614174509' ) );
		$this->assertFalse( $missing['ok'] );
		$this->assertSame( 'operation_not_found', $missing['code'] );
		$invalid = $this->request( 'ability_maintenance_operation_v2', array( 'operation_ref' => 'not-a-uuid' ) );
		$this->assertSame( 'invalid_request', $invalid['code'] );
	}

	public function test_real_storage_lock_and_checked_sql_action_converge() {
		$reflection = new ReflectionClass( MainWP_Child_Maintenance::class );
		$subject    = $reflection->newInstanceWithoutConstructor();
		$preview    = $subject->abilities_v2(
			array(
				'protocol'  => '2',
				'operation' => 'ability_maintenance_preview_v2',
				'payload'   => array( 'actions' => array( 'autodraft' ), 'revision_retention' => 5 ),
			)
		);
		$this->assertTrue( $preview['ok'] );
		$payload = array(
			'operation_ref'      => '123e4567-e89b-42d3-a456-426614174510',
			'actions'            => array( 'autodraft' ),
			'revision_retention' => 5,
			'snapshot_revision'  => $preview['snapshot_revision'],
			'action_hash'        => hash( 'sha256', wp_json_encode( array( array( 'autodraft' ), 5 ) ) ),
		);
		$request = array(
			'protocol'  => '2',
			'operation' => 'ability_maintenance_execute_v2',
			'payload'   => $payload,
		);

		$result = $subject->abilities_v2( $request );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'succeeded', $result['status'] );
		$this->assertSame( 'autodraft', $result['outcomes'][0]['action'] );
		$this->assertIsInt( $result['outcomes'][0]['affected'] );
		$this->assertNull( get_option( MainWP_Child_Maintenance::ABILITIES_V2_LOCK_OPTION, null ) );
		$this->assertArrayHasKey( $payload['operation_ref'], get_option( MainWP_Child_Maintenance::ABILITIES_V2_OPERATIONS_OPTION, array() ) );
		$this->assertSame( $result, $subject->abilities_v2( $request ) );

		$status = $subject->abilities_v2(
			array(
				'protocol'  => '2',
				'operation' => 'ability_maintenance_operation_v2',
				'payload'   => array( 'operation_ref' => $payload['operation_ref'] ),
			)
		);
		$this->assertTrue( $status['ok'] );
		$this->assertSame( 'succeeded', $status['status'] );
	}

	public function test_preview_returns_actions_in_canonical_order_with_stable_risk() {
		$actions = array( 'revisions', 'autodraft', 'trashpost', 'spam', 'pending', 'trashcomment', 'tags', 'categories', 'optimize', 'transients_expired', 'transients_all' );
		foreach ( $actions as $index => $action ) {
			$this->subject->preview_impacts[ $action ] = array(
				'would_affect' => $index + 1,
				'capability'   => true,
			);
		}

		$result = $this->request(
			'ability_maintenance_preview_v2',
			array(
				'actions'            => array_reverse( array_slice( $actions, 0, 10 ) ),
				'revision_retention' => 5,
			)
		);

		$this->assertSame( array( 'protocol', 'operation', 'ok', 'impacts', 'snapshot_revision', 'observed_at' ), array_keys( $result ) );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( array_slice( $actions, 0, 10 ), array_column( $result['impacts'], 'action' ) );
		$this->assertSame( range( 1, 10 ), array_column( $result['impacts'], 'would_affect' ) );
		$this->assertSame( array_fill( 0, 10, true ), array_column( $result['impacts'], 'capability' ) );
		$this->assertSame(
			array( 'standard', 'standard', 'standard', 'standard', 'elevated', 'standard', 'elevated', 'elevated', 'elevated', 'standard' ),
			array_column( $result['impacts'], 'risk' )
		);
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $result['snapshot_revision'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $result['observed_at'] );

		$all_transients = $this->request(
			'ability_maintenance_preview_v2',
			array(
				'actions'            => array( 'transients_all' ),
				'revision_retention' => 5,
			)
		);
		$this->assertTrue( $all_transients['ok'] );
		$this->assertSame( 'transients_all', $all_transients['impacts'][0]['action'] );
		$this->assertSame( 'elevated', $all_transients['impacts'][0]['risk'] );
	}

	public function test_preview_is_read_only_saturates_counts_and_marks_revision_zero_elevated() {
		$this->subject->preview_impacts = array(
			'revisions' => array( 'would_affect' => 1000001, 'capability' => true ),
			'optimize'  => array( 'would_affect' => 4, 'capability' => false ),
		);
		$reports                       = 0;
		$report                        = static function () use ( &$reports ) {
			++$reports;
		};
		add_action( 'mainwp_reports_maintenance', $report );

		$result = $this->request(
			'ability_maintenance_preview_v2',
			array(
				'actions'            => array( 'optimize', 'revisions' ),
				'revision_retention' => 0,
			)
		);
		remove_action( 'mainwp_reports_maintenance', $report );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'revisions', $result['impacts'][0]['action'] );
		$this->assertSame( 1000000, $result['impacts'][0]['would_affect'] );
		$this->assertSame( 'elevated', $result['impacts'][0]['risk'] );
		$this->assertFalse( $result['impacts'][1]['capability'] );
		$this->assertSame( 0, $reports );
	}

	public function test_preview_rejects_malformed_duplicate_conflicting_and_failed_counts() {
		$invalid_payloads = array(
			array(),
			array( 'actions' => array(), 'revision_retention' => 5 ),
			array( 'actions' => array( 'unknown' ), 'revision_retention' => 5 ),
			array( 'actions' => array( 'spam', 'spam' ), 'revision_retention' => 5 ),
			array( 'actions' => array( 'transients_all', 'transients_expired' ), 'revision_retention' => 5 ),
			array( 'actions' => array( 'spam' ), 'revision_retention' => -1 ),
			array( 'actions' => array( 'spam' ), 'revision_retention' => 1001 ),
			array( 'actions' => array( 'spam' ), 'revision_retention' => '5' ),
			array( 'actions' => array( 'spam' ), 'revision_retention' => 5, 'extra' => true ),
		);
		foreach ( $invalid_payloads as $payload ) {
			$result = $this->request( 'ability_maintenance_preview_v2', $payload );
			$this->assertFalse( $result['ok'] );
			$this->assertSame( 'invalid_request', $result['code'] );
		}

		$this->subject->preview_impacts = array( 'spam' => false );
		$result                         = $this->request(
			'ability_maintenance_preview_v2',
			array( 'actions' => array( 'spam' ), 'revision_retention' => 5 )
		);
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'invalid_snapshot', $result['code'] );
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

		$unknown = $this->request( 'ability_maintenance_future_v2', array() );
		$this->assertFalse( $unknown['ok'] );
		$this->assertSame( 'unsupported_operation', $unknown['code'] );

		$alias = $this->subject->abilities_v2(
			array(
				'protocol'     => '2',
				'operation'    => 'capabilities',
				'payload'      => array(),
				'operation_id' => '123e4567-e89b-42d3-a456-426614174510',
			)
		);
		$this->assertFalse( $alias['ok'] );
		$this->assertSame( 'invalid_request', $alias['code'] );
	}

	public function test_reordered_envelope_is_accepted_and_capabilities_with_a_payload_is_invalid() {
		$result = $this->subject->abilities_v2(
			array(
				'payload'   => array(),
				'operation' => 'capabilities',
				'protocol'  => '2',
			)
		);
		$this->assertTrue( $result['ok'] );

		$result = $this->request( 'capabilities', array( 'extra' => true ) );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'invalid_request', $result['code'] );
		$this->assertSame( 'capabilities', $result['operation'] );
	}

	public function test_invalid_execute_payload_is_rejected_before_the_lock_is_taken() {
		update_option(
			MainWP_Child_Maintenance::ABILITIES_V2_LOCK_OPTION,
			array(
				'owner'      => '123e4567-e89b-42d3-a456-426614174777',
				'expires_at' => time() + 300,
			),
			false
		);
		$subject = $this->real_subject();

		$result = $this->real_request( $subject, 'ability_maintenance_execute_v2', array( 'operation_ref' => 'not-a-uuid' ) );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'invalid_request', $result['code'] );

		$held = array(
			'operation_ref'      => '123e4567-e89b-42d3-a456-426614174511',
			'actions'            => array( 'autodraft' ),
			'revision_retention' => 5,
			'snapshot_revision'  => str_repeat( 'a', 64 ),
			'action_hash'        => hash( 'sha256', wp_json_encode( array( array( 'autodraft' ), 5 ) ) ),
		);
		$this->assertSame( 'lock_busy', $this->real_request( $subject, 'ability_maintenance_execute_v2', $held )['code'] );
		$this->assertSame( '123e4567-e89b-42d3-a456-426614174777', get_option( MainWP_Child_Maintenance::ABILITIES_V2_LOCK_OPTION )['owner'] );
	}

	public function test_committed_execute_survives_a_failed_lock_release() {
		$this->subject->preview_impacts       = array( 'spam' => array( 'would_affect' => 1, 'capability' => true ) );
		$this->subject->action_results        = array( 'spam' => array( 'status' => 'succeeded', 'affected' => 1, 'error_code' => null ) );
		$this->subject->end_mutation_succeeds = false;
		$preview                              = $this->request( 'ability_maintenance_preview_v2', array( 'actions' => array( 'spam' ), 'revision_retention' => 5 ) );
		$payload                              = array(
			'operation_ref'      => '123e4567-e89b-42d3-a456-426614174512',
			'actions'            => array( 'spam' ),
			'revision_retention' => 5,
			'snapshot_revision'  => $preview['snapshot_revision'],
			'action_hash'        => hash( 'sha256', wp_json_encode( array( array( 'spam' ), 5 ) ) ),
		);

		$result = $this->request( 'ability_maintenance_execute_v2', $payload );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'succeeded', $result['status'] );
		$this->assertSame( array( 'succeeded' ), array_column( $result['outcomes'], 'status' ) );
		$this->assertSame( array( 'lock_release_failed' ), $result['warning_codes'] );

		$this->subject->end_mutation_succeeds = true;
		$replay                               = $this->request( 'ability_maintenance_execute_v2', $payload );
		$this->assertTrue( $replay['ok'] );
		$this->assertArrayNotHasKey( 'warning_codes', $replay );
		$this->assertSame( array( 'spam' ), $this->subject->execution_log );
	}

	public function test_failed_action_outcome_reports_the_rows_it_destroyed() {
		$this->subject->preview_impacts = array( 'tags' => array( 'would_affect' => 9, 'capability' => true ) );
		$this->subject->action_results  = array( 'tags' => array( 'status' => 'failed', 'affected' => 4, 'error_code' => 'mutation_failed' ) );
		$preview                        = $this->request( 'ability_maintenance_preview_v2', array( 'actions' => array( 'tags' ), 'revision_retention' => 5 ) );

		$result = $this->request(
			'ability_maintenance_execute_v2',
			array(
				'operation_ref'      => '123e4567-e89b-42d3-a456-426614174513',
				'actions'            => array( 'tags' ),
				'revision_retention' => 5,
				'snapshot_revision'  => $preview['snapshot_revision'],
				'action_hash'        => hash( 'sha256', wp_json_encode( array( array( 'tags' ), 5 ) ) ),
			)
		);

		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame(
			array(
				'action'     => 'tags',
				'status'     => 'failed',
				'affected'   => 4,
				'error_code' => 'mutation_failed',
			),
			$result['outcomes'][0]
		);
		$this->assertTrue( $result['retryable'] );
	}

	public function test_stale_running_records_settle_instead_of_bricking_the_operation_store() {
		$records = array();
		for ( $index = 1; $index <= 99; $index++ ) {
			$abandoned                             = $this->running_record( $index, 8 * DAY_IN_SECONDS );
			$records[ $abandoned['operation_ref'] ] = $abandoned;
		}
		$recent                              = $this->running_record( 900, 2 * DAY_IN_SECONDS );
		$records[ $recent['operation_ref'] ] = $recent;
		update_option( MainWP_Child_Maintenance::ABILITIES_V2_OPERATIONS_OPTION, $records, false );

		list( , $result ) = $this->real_execute( $this->real_subject(), array( 'autodraft' ), 5, '123e4567-e89b-42d3-a456-426614174514' );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'succeeded', $result['status'] );
		$stored = get_option( MainWP_Child_Maintenance::ABILITIES_V2_OPERATIONS_OPTION, array() );
		$this->assertCount( 2, $stored );
		$this->assertSame( 'unknown', $stored[ $recent['operation_ref'] ]['status'] );
		$this->assertSame( $recent['updated_at'], $stored[ $recent['operation_ref'] ]['finished_at'] );
		$this->assertSame(
			array(
				array(
					'action'     => 'autodraft',
					'status'     => 'unknown',
					'affected'   => null,
					'error_code' => 'outcome_unknown',
				),
			),
			$stored[ $recent['operation_ref'] ]['outcomes']
		);
	}

	/**
	 * A store filled with runs that all finished this week is out of the seven-day prune's reach, so
	 * at the cap there is no slot to free. Freeing one anyway means dropping a record a Dashboard
	 * retry can still land on, and that retry then runs the same destructive actions a second time
	 * against rows the first run never saw. The write refuses instead.
	 */
	public function test_a_full_operation_store_refuses_the_write_instead_of_forgetting_a_receipt() {
		$in_flight               = $this->running_record( 1, 6 * DAY_IN_SECONDS );
		$in_flight['updated_at'] = time() - 30;
		$replayable              = $this->settled_record( 2, 2 * DAY_IN_SECONDS );
		$records                 = array(
			$in_flight['operation_ref']  => $in_flight,
			$replayable['operation_ref'] => $replayable,
		);
		for ( $index = 3; $index <= 100; $index++ ) {
			$settled                              = $this->settled_record( $index, 60 );
			$records[ $settled['operation_ref'] ] = $settled;
		}
		update_option( MainWP_Child_Maintenance::ABILITIES_V2_OPERATIONS_OPTION, $records, false );

		list( , $result ) = $this->real_execute( $this->real_subject(), array( 'autodraft' ), 5, '123e4567-e89b-42d3-a456-426614174520' );

		$stored = get_option( MainWP_Child_Maintenance::ABILITIES_V2_OPERATIONS_OPTION, array() );
		$this->assertArrayHasKey( $replayable['operation_ref'], $stored, 'A record inside the retention window is what a retry replays instead of re-running.' );
		$this->assertArrayHasKey( $in_flight['operation_ref'], $stored, 'The oldest record is a live run and is not a slot to free.' );
		$this->assertSame( 'running', $stored[ $in_flight['operation_ref'] ]['status'] );
		$this->assertCount( 100, $stored );
		$this->assertArrayNotHasKey( '123e4567-e89b-42d3-a456-426614174520', $stored );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'storage_unavailable', $result['code'] );
	}

	/**
	 * The settle turns a day-old running record into a terminal one, and the same write then reads
	 * every terminal record. A record that was running when the write started carries a finished_at
	 * from before that write by definition, so anything that drops old terminal records to free a
	 * slot takes it first, and the run it proves happened is left with no record at all.
	 */
	public function test_a_record_settled_during_a_write_survives_that_same_write() {
		$stale   = $this->running_record( 1, 2 * DAY_IN_SECONDS );
		$records = array( $stale['operation_ref'] => $stale );
		for ( $index = 2; $index <= 100; $index++ ) {
			$settled                              = $this->settled_record( $index, 60 );
			$records[ $settled['operation_ref'] ] = $settled;
		}
		update_option( MainWP_Child_Maintenance::ABILITIES_V2_OPERATIONS_OPTION, $records, false );

		list( , $result ) = $this->real_execute( $this->real_subject(), array( 'autodraft' ), 5, '123e4567-e89b-42d3-a456-426614174521' );

		$stored = get_option( MainWP_Child_Maintenance::ABILITIES_V2_OPERATIONS_OPTION, array() );
		$this->assertArrayHasKey( $stale['operation_ref'], $stored, 'A record that was running when the write started must still be there when it returns.' );
		$this->assertCount( 100, $stored );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'storage_unavailable', $result['code'] );
	}

	/**
	 * The property the rest of the store rules exist to protect. The execute payload has no expiry,
	 * so a retry can arrive long after the run it repeats, and the snapshot it carries matches again
	 * as soon as the site is back at the same counts. The stored record is the only thing between
	 * that retry and a second pass of the same destructive actions over rows the first run never saw.
	 */
	public function test_a_retry_of_a_settled_unknown_run_replays_instead_of_deleting_again() {
		global $wpdb;
		$subject = $this->real_subject();
		self::factory()->post->create( array( 'post_status' => 'auto-draft' ) );
		self::factory()->post->create( array( 'post_status' => 'auto-draft' ) );
		$auto_drafts = "SELECT COUNT(*) FROM $wpdb->posts WHERE post_status = 'auto-draft'";
		$before      = $wpdb->get_var( $auto_drafts );
		$preview     = $this->real_request( $subject, 'ability_maintenance_preview_v2', array( 'actions' => array( 'autodraft' ), 'revision_retention' => 5 ) );
		$this->assertTrue( $preview['ok'] );

		$abandoned = $this->settled_unknown_record( 1, 3 * DAY_IN_SECONDS, $preview['snapshot_revision'] );
		$spare     = $this->settled_record( 2, 2 * DAY_IN_SECONDS );
		$records   = array(
			$abandoned['operation_ref'] => $abandoned,
			$spare['operation_ref']     => $spare,
		);
		for ( $index = 3; $index <= 100; $index++ ) {
			$settled                              = $this->settled_record( $index, 60 );
			$records[ $settled['operation_ref'] ] = $settled;
		}
		update_option( MainWP_Child_Maintenance::ABILITIES_V2_OPERATIONS_OPTION, $records, false );

		// A later run is the write that has to find a slot for its own record, and the abandoned run
		// is the oldest thing in the store it could take one from. Whether that write is refused or
		// makes room is what the retry below then depends on, so it is left unasserted here.
		$this->real_execute( $subject, array( 'spam' ), 5, '123e4567-e89b-42d3-a456-426614174524' );

		$retry = $this->real_request(
			$subject,
			'ability_maintenance_execute_v2',
			array(
				'operation_ref'      => $abandoned['operation_ref'],
				'actions'            => array( 'autodraft' ),
				'revision_retention' => 5,
				'snapshot_revision'  => $preview['snapshot_revision'],
				'action_hash'        => hash( 'sha256', wp_json_encode( array( array( 'autodraft' ), 5 ) ) ),
			)
		);

		$this->assertTrue( $retry['ok'] );
		$this->assertSame( 'unknown', $retry['status'] );
		$this->assertSame( 'unknown', $retry['outcomes'][0]['status'] );
		$this->assertSame( $before, $wpdb->get_var( $auto_drafts ), 'The retry has to replay the stored outcome, not delete the rows a second time.' );
	}

	/**
	 * A timeout row whose value option is already gone is a transient that is deleted, not one the
	 * Child was refused. The run clears the leftover row and counts it, instead of reporting a
	 * failure for work nothing is left to do.
	 */
	public function test_orphaned_transient_row_is_cleared_and_counted_instead_of_failing_the_run() {
		if ( wp_using_ext_object_cache() ) {
			$this->markTestSkipped( 'Transient maintenance reports unsupported behind an external object cache.' );
		}
		$this->clear_transients();
		set_transient( 'mwp_maint_a', 'a', -100 );
		set_transient( 'mwp_maint_b', 'b', -100 );
		add_option( '_transient_timeout_mwp_maint_ghost', time() - 100, '', false );

		list( $preview, $result ) = $this->real_execute( $this->real_subject(), array( 'transients_expired' ), 5, '123e4567-e89b-42d3-a456-426614174515' );

		$this->assertSame( 3, $preview['impacts'][0]['would_affect'] );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'succeeded', $result['status'] );
		$this->assertSame(
			array(
				'action'     => 'transients_expired',
				'status'     => 'succeeded',
				'affected'   => 3,
				'error_code' => null,
			),
			$result['outcomes'][0]
		);
		$this->assertFalse( get_option( '_transient_mwp_maint_a' ) );
		$this->assertFalse( get_option( '_transient_mwp_maint_b' ) );
		$this->assertFalse( get_option( '_transient_timeout_mwp_maint_ghost' ) );
	}

	/**
	 * A transient can be named after the prefix itself, and `_transient_ghost` is stored as
	 * `_transient__transient_ghost`. Stripping every occurrence instead of the leading one turns
	 * that into `ghost`, a different transient the run would delete in its place.
	 */
	public function test_a_transient_named_after_the_prefix_round_trips_through_preview_and_execute() {
		if ( wp_using_ext_object_cache() ) {
			$this->markTestSkipped( 'Transient maintenance reports unsupported behind an external object cache.' );
		}
		$this->clear_transients();
		set_transient( '_transient_ghost', 'target', HOUR_IN_SECONDS );
		set_transient( 'ghost', 'bystander', HOUR_IN_SECONDS );

		list( $preview, $result ) = $this->real_execute( $this->real_subject(), array( 'transients_all' ), 5, '123e4567-e89b-42d3-a456-426614174519' );

		$this->assertSame( 2, $preview['impacts'][0]['would_affect'] );
		$this->assertSame( 'succeeded', $result['status'] );
		$this->assertSame( 2, $result['outcomes'][0]['affected'] );
		$this->assertFalse( get_option( '_transient__transient_ghost' ) );
		$this->assertFalse( get_option( '_transient_ghost' ) );
	}

	/**
	 * The legacy maintenance paths strip the same prefixes. Unanchored, an expired transient named
	 * `x_transient_timeout_y` resolves to `xy` and the sweep deletes that unexpired transient
	 * instead, and a `_transient_ghost` row survives a delete-all run for the same reason.
	 */
	public function test_legacy_transient_cleanup_strips_only_the_leading_prefix() {
		if ( wp_using_ext_object_cache() ) {
			$this->markTestSkipped( 'Transient maintenance reports unsupported behind an external object cache.' );
		}
		$this->clear_transients();
		$subject = $this->real_subject();
		set_transient( 'x_transient_timeout_y', 'target', -100 );
		set_transient( 'xy', 'bystander', HOUR_IN_SECONDS );

		$this->invoke_private( $subject, 'maintenance_delete_expired_transients' );

		$this->assertFalse( get_option( '_transient_x_transient_timeout_y' ) );
		$this->assertSame( 'bystander', get_transient( 'xy' ) );

		set_transient( '_transient_ghost', 'target', HOUR_IN_SECONDS );

		$this->invoke_private( $subject, 'maintenance_delete_all_transients' );

		$this->assertFalse( get_option( '_transient__transient_ghost' ) );
		$this->assertFalse( get_option( '_transient_xy' ) );
	}

	public function test_transient_preview_counts_each_transient_once() {
		if ( wp_using_ext_object_cache() ) {
			$this->markTestSkipped( 'Transient maintenance reports unsupported behind an external object cache.' );
		}
		$this->clear_transients();
		set_transient( 'mwp_preview_a', 'a', HOUR_IN_SECONDS );
		set_transient( 'mwp_preview_b', 'b', HOUR_IN_SECONDS );
		set_transient( 'mwp_preview_c', 'c', HOUR_IN_SECONDS );
		set_transient( 'mwp_preview_d', 'd' );

		list( $preview, $result ) = $this->real_execute( $this->real_subject(), array( 'transients_all' ), 5, '123e4567-e89b-42d3-a456-426614174516' );

		$this->assertSame( 4, $preview['impacts'][0]['would_affect'] );
		$this->assertSame( 'succeeded', $result['status'] );
		$this->assertSame( $preview['impacts'][0]['would_affect'], $result['outcomes'][0]['affected'] );
	}

	public function test_revision_sweep_batches_its_deletes_and_keeps_the_newest() {
		global $wpdb;
		$parent_id = self::factory()->post->create();
		$revisions = array();
		for ( $index = 0; $index < 20; $index++ ) {
			$when        = gmdate( 'Y-m-d H:i:s', time() - ( 3600 * ( 20 - $index ) ) );
			$revisions[] = wp_insert_post(
				array(
					'post_type'         => 'revision',
					'post_parent'       => $parent_id,
					'post_status'       => 'inherit',
					'post_title'        => 'revision-' . $index,
					'post_name'         => $parent_id . '-revision-' . $index,
					'post_date'         => $when,
					'post_date_gmt'     => $when,
					'post_modified'     => $when,
					'post_modified_gmt' => $when,
				)
			);
		}
		$deletes = array();
		$spy     = static function ( $query ) use ( &$deletes ) {
			if ( 1 === preg_match( '/^\s*DELETE\s+FROM\s+\S*posts\b/i', $query ) ) {
				$deletes[] = $query;
			}
			return $query;
		};

		add_filter( 'query', $spy );
		list( $preview, $result ) = $this->real_execute( $this->real_subject(), array( 'revisions' ), 2, '123e4567-e89b-42d3-a456-426614174517' );
		remove_filter( 'query', $spy );

		$this->assertSame( 18, $preview['impacts'][0]['would_affect'] );
		$this->assertSame( 'succeeded', $result['status'] );
		$this->assertSame( 18, $result['outcomes'][0]['affected'] );
		$this->assertLessThanOrEqual( 2, count( $deletes ) );
		$remaining = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_type = 'revision' AND post_parent = %d ORDER BY post_modified DESC, ID DESC", $parent_id ) );
		$this->assertSame( array( $revisions[19], $revisions[18] ), array_map( 'intval', $remaining ) );
	}

	/**
	 * A sweep that pages can run past the 300s mutation lock. Once the lock cannot be renewed the
	 * run has to stop deleting, and still report the rows it destroyed before it stopped.
	 */
	public function test_revision_sweep_stops_when_the_mutation_lock_cannot_be_renewed() {
		global $wpdb;
		$parent_id = self::factory()->post->create();
		for ( $index = 0; $index < 6; $index++ ) {
			$when = gmdate( 'Y-m-d H:i:s', time() - ( 3600 * ( 6 - $index ) ) );
			wp_insert_post(
				array(
					'post_type'         => 'revision',
					'post_parent'       => $parent_id,
					'post_status'       => 'inherit',
					'post_title'        => 'lock-revision-' . $index,
					'post_name'         => $parent_id . '-lock-revision-' . $index,
					'post_date'         => $when,
					'post_date_gmt'     => $when,
					'post_modified'     => $when,
					'post_modified_gmt' => $when,
				)
			);
		}
		$subject = new Renewal_Limited_MainWP_Child_Maintenance();
		// The execute loop renews once before the action, and the first parent page renews once more.
		$subject->renewals_before_failure = 2;

		list( , $result ) = $this->real_execute( $subject, array( 'revisions' ), 2, '123e4567-e89b-42d3-a456-426614174518' );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'unknown', $result['status'] );
		$this->assertSame(
			array(
				'action'     => 'revisions',
				'status'     => 'unknown',
				'affected'   => 4,
				'error_code' => 'outcome_unknown',
			),
			$result['outcomes'][0]
		);
		$this->assertFalse( $result['retryable'] );
		$this->assertSame(
			'2',
			$wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'revision' AND post_parent = %d", $parent_id ) ),
			'The rows the outcome reports as destroyed must really be gone.'
		);
	}

	/**
	 * One parent can hold enough surplus to outlive the 300s lock on its own, so the lock is renewed
	 * between its batches too. Once it cannot be renewed the sweep stops mid-parent, and still reports
	 * every row it destroyed before it stopped.
	 */
	public function test_revision_sweep_stops_between_the_batches_of_one_parent_when_the_lock_is_lost() {
		global $wpdb;
		$parent_id = self::factory()->post->create();
		$this->insert_revisions( $parent_id, 503 );

		$subject = new Renewal_Limited_MainWP_Child_Maintenance();
		// The execute loop renews once before the action and the parent page renews once more, which
		// leaves this parent's first batch as the last one that runs under a lock it can prove it holds.
		$subject->renewals_before_failure = 2;

		list( , $result ) = $this->real_execute( $subject, array( 'revisions' ), 2, '123e4567-e89b-42d3-a456-426614174519' );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'unknown', $result['status'] );
		$this->assertSame(
			array(
				'action'     => 'revisions',
				'status'     => 'unknown',
				'affected'   => MainWP_Child_Maintenance::ABILITIES_V2_REVISION_DELETE_BATCH,
				'error_code' => 'outcome_unknown',
			),
			$result['outcomes'][0]
		);
		$this->assertSame(
			'3',
			$wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'revision' AND post_parent = %d", $parent_id ) ),
			'The sweep must stop at the batch it lost the lock on instead of running this parent to the end.'
		);
	}

	/**
	 * Lock renewal re-arms the lock TTL and every page keeps deleting something, so neither existing
	 * bound ends a sweep on a busy site: the request would run to PHP's execution limit and die with
	 * the record unsettled. The wall clock has to stop it, and report the rows it destroyed.
	 */
	public function test_revision_sweep_stops_when_the_wall_clock_budget_runs_out() {
		global $wpdb, $timestart;
		$parent_id = self::factory()->post->create();
		$this->insert_revisions( $parent_id, 503 );
		// Two seconds of sweep budget once the settle margin comes off, so the stop is reachable
		// without waiting out the 120s ceiling and the first batch still gets to run. $timestart is
		// the harness bootstrap here, seconds back by the time this case runs, and that elapsed time
		// comes off the same budget: pinning it to now is what leaves the two seconds.
		$original_limit     = ini_get( 'max_execution_time' );
		$original_timestart = $timestart;
		ini_set( 'max_execution_time', MainWP_Child_Maintenance::ABILITIES_V2_REVISION_SWEEP_MARGIN + 2 );
		$timestart = microtime( true );
		// Burn the budget inside the first batch, so the sweep is over time by the time it decides
		// whether to start the second one.
		$stall = static function ( $query ) {
			static $stalled = false;
			if ( ! $stalled && 1 === preg_match( '/^\s*DELETE\s+FROM\s+\S*posts\b/i', $query ) ) {
				$stalled = true;
				$until   = time() + 2;
				while ( time() < $until ) {
					usleep( 50000 );
				}
			}
			return $query;
		};

		add_filter( 'query', $stall );
		try {
			list( , $result ) = $this->real_execute( $this->real_subject(), array( 'revisions' ), 2, '123e4567-e89b-42d3-a456-426614174520' );
		} finally {
			remove_filter( 'query', $stall );
			ini_set( 'max_execution_time', $original_limit );
			$timestart = $original_timestart;
		}

		$remaining = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'revision' AND post_parent = %d", $parent_id ) );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'unknown', $result['status'] );
		$this->assertSame(
			array(
				'action'     => 'revisions',
				'status'     => 'unknown',
				'affected'   => 503 - $remaining,
				'error_code' => 'outcome_unknown',
			),
			$result['outcomes'][0],
			'The outcome has to count the rows that really left the table.'
		);
		$this->assertGreaterThan( 0, $result['outcomes'][0]['affected'] );
		$this->assertGreaterThan( 2, $remaining, 'Revisions over retention must still be there: the sweep stopped, it did not finish.' );
	}

	/**
	 * max_execution_time bounds the whole request, so whatever validation, the preview and earlier
	 * actions already spent is gone before the sweep starts. A deadline that ignores that time sits
	 * behind the fatal it was added to prevent: here 58 of 60 seconds are already spent, and a sweep
	 * granted the full limit would keep deleting for another 50.
	 */
	public function test_revision_sweep_deadline_accounts_for_time_already_spent_in_the_request() {
		global $wpdb, $timestart;
		$parent_id = self::factory()->post->create();
		$this->insert_revisions( $parent_id, 503 );

		$original_limit     = ini_get( 'max_execution_time' );
		$original_timestart = $timestart;
		ini_set( 'max_execution_time', 60 );
		$timestart = microtime( true ) - 58;
		// The sweep only reads the wall clock between batches, so time has to pass inside one.
		$stall = static function ( $query ) {
			static $stalled = false;
			if ( ! $stalled && 1 === preg_match( '/^\s*DELETE\s+FROM\s+\S*posts\b/i', $query ) ) {
				$stalled = true;
				$until   = time() + 2;
				while ( time() < $until ) {
					usleep( 50000 );
				}
			}
			return $query;
		};

		add_filter( 'query', $stall );
		try {
			list( , $result ) = $this->real_execute( $this->real_subject(), array( 'revisions' ), 2, '123e4567-e89b-42d3-a456-426614174521' );
		} finally {
			remove_filter( 'query', $stall );
			ini_set( 'max_execution_time', $original_limit );
			$timestart = $original_timestart;
		}

		$remaining = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'revision' AND post_parent = %d", $parent_id ) );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'unknown', $result['status'] );
		$this->assertSame(
			array(
				'action'     => 'revisions',
				'status'     => 'unknown',
				'affected'   => 503 - $remaining,
				'error_code' => 'outcome_unknown',
			),
			$result['outcomes'][0],
			'A sweep that stops on the request budget reports the rows it destroyed, not a finished run.'
		);
		$this->assertGreaterThan( 2, $remaining, 'The sweep must stop on the time the request already spent, not run the parent to the end.' );
	}

	/**
	 * A request that has already spent its limit before the sweep starts has no budget left to hand
	 * out. Starting one page query and one 500-row delete anyway is the fatal the deadline exists to
	 * prevent, so the sweep has to destroy nothing and say so; the next request gets the whole limit.
	 */
	public function test_revision_sweep_does_no_work_when_the_request_budget_is_already_spent() {
		global $wpdb, $timestart;
		$parent_id = self::factory()->post->create();
		$this->insert_revisions( $parent_id, 12 );

		$original_limit     = ini_get( 'max_execution_time' );
		$original_timestart = $timestart;
		ini_set( 'max_execution_time', 30 );
		// Past the limit minus the settle margin, so what is left of the request is negative.
		$timestart = microtime( true ) - 25;

		try {
			list( , $result ) = $this->real_execute( $this->real_subject(), array( 'revisions' ), 2, '123e4567-e89b-42d3-a456-426614174522' );
		} finally {
			ini_set( 'max_execution_time', $original_limit );
			$timestart = $original_timestart;
		}

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'unknown', $result['status'] );
		$this->assertSame(
			array(
				'action'     => 'revisions',
				'status'     => 'unknown',
				'affected'   => 0,
				'error_code' => 'outcome_unknown',
			),
			$result['outcomes'][0],
			'An exhausted budget reports the same unknown as the other early stops, with nothing destroyed.'
		);
		$this->assertSame(
			'12',
			$wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'revision' AND post_parent = %d", $parent_id ) ),
			'Every revision over retention has to still be there: a sweep with no budget runs no batch at all.'
		);
	}

	/**
	 * The read that picks the batch is the part of an iteration that can take unbounded time, and it
	 * hands back a valid batch either way. Checking the clock only at the top of the loop lets a slow
	 * read spend the whole budget and the 500-row delete run anyway, on the margin reserved for
	 * settling the record. The sweep has to stop on the completed read, with nothing destroyed.
	 */
	public function test_revision_sweep_stops_when_the_batch_read_itself_consumes_the_budget() {
		global $wpdb, $timestart;
		$parent_id = self::factory()->post->create();
		$this->insert_revisions( $parent_id, 503 );

		$original_limit     = ini_get( 'max_execution_time' );
		$original_timestart = $timestart;
		ini_set( 'max_execution_time', MainWP_Child_Maintenance::ABILITIES_V2_REVISION_SWEEP_MARGIN + 2 );
		$timestart = microtime( true );
		// Burn the budget inside the batch's own ID query and let it complete normally, so the sweep
		// holds a valid batch of 500 IDs and no time left to delete them in.
		$stall = static function ( $query ) {
			static $stalled = false;
			if ( ! $stalled && 1 === preg_match( '/^\s*SELECT\s+ID\s+FROM\s+\S*posts\b.*post_parent\s*=/is', $query ) ) {
				$stalled = true;
				$until   = time() + 2;
				while ( time() < $until ) {
					usleep( 50000 );
				}
			}
			return $query;
		};

		add_filter( 'query', $stall );
		try {
			list( , $result ) = $this->real_execute( $this->real_subject(), array( 'revisions' ), 2, '123e4567-e89b-42d3-a456-426614174523' );
		} finally {
			remove_filter( 'query', $stall );
			ini_set( 'max_execution_time', $original_limit );
			$timestart = $original_timestart;
		}

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'unknown', $result['status'] );
		$this->assertSame(
			array(
				'action'     => 'revisions',
				'status'     => 'unknown',
				'affected'   => 0,
				'error_code' => 'outcome_unknown',
			),
			$result['outcomes'][0],
			'A read that ate the budget has destroyed nothing, and the outcome has to say so.'
		);
		$this->assertSame(
			'503',
			$wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'revision' AND post_parent = %d", $parent_id ) ),
			'The batch the read returned must not be deleted on a budget that is already gone.'
		);
	}

	/**
	 * The deadline bounds one sweep, but the margin it holds back settles the record and releases the
	 * lock for the whole request. An operation that runs the rest of its action list after the sweep
	 * gave up spends that margin on new destructive work, and the fatal that follows leaves the record
	 * running with the lock held: exactly what the deadline exists to prevent, one level up.
	 */
	public function test_operation_stops_starting_actions_once_the_request_budget_is_spent() {
		global $wpdb, $timestart;
		$parent_id = self::factory()->post->create();
		$this->insert_revisions( $parent_id, 10 );
		self::factory()->post->create( array( 'post_status' => 'auto-draft' ) );
		self::factory()->post->create( array( 'post_status' => 'auto-draft' ) );
		$auto_drafts = "SELECT COUNT(*) FROM $wpdb->posts WHERE post_status = 'auto-draft'";
		$before      = (int) $wpdb->get_var( $auto_drafts );
		$this->assertGreaterThan( 0, $before );

		// Three seconds of sweep budget once the settle margin comes off: enough for the first batch
		// of revisions to run, and gone by the time the operation decides whether to start autodraft.
		$original_limit     = ini_get( 'max_execution_time' );
		$original_timestart = $timestart;
		ini_set( 'max_execution_time', MainWP_Child_Maintenance::ABILITIES_V2_REVISION_SWEEP_MARGIN + 3 );
		$timestart = microtime( true );
		$stall     = static function ( $query ) {
			static $stalled = false;
			if ( ! $stalled && 1 === preg_match( '/^\s*DELETE\s+FROM\s+\S*posts\b/i', $query ) ) {
				$stalled = true;
				$until   = time() + 4;
				while ( time() < $until ) {
					usleep( 50000 );
				}
			}
			return $query;
		};

		add_filter( 'query', $stall );
		try {
			list( , $result ) = $this->real_execute( $this->real_subject(), array( 'revisions', 'autodraft' ), 2, '123e4567-e89b-42d3-a456-426614174525' );
		} finally {
			remove_filter( 'query', $stall );
			ini_set( 'max_execution_time', $original_limit );
			$timestart = $original_timestart;
		}

		$this->assertSame(
			$before,
			(int) $wpdb->get_var( $auto_drafts ),
			'The action after the one that ran out of time must not have started: its rows are the evidence.'
		);
		$this->assertSame(
			'2',
			$wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'revision' AND post_parent = %d", $parent_id ) ),
			'The sweep itself still ran its first batch before the budget went.'
		);
		$stored = get_option( MainWP_Child_Maintenance::ABILITIES_V2_OPERATIONS_OPTION, array() );
		$this->assertSame(
			array( 'revisions' ),
			array_column( $stored['123e4567-e89b-42d3-a456-426614174525']['outcomes'], 'action' ),
			'The record holds an outcome for what ran and none for what was never started.'
		);
		$this->assertSame( 8, $stored['123e4567-e89b-42d3-a456-426614174525']['outcomes'][0]['affected'] );
		$this->assertSame( 'unknown', $stored['123e4567-e89b-42d3-a456-426614174525']['status'] );
		$this->assertNotNull( $stored['123e4567-e89b-42d3-a456-426614174525']['finished_at'], 'A run that stops still settles its record instead of leaving it running.' );
		$this->assertNull( get_option( MainWP_Child_Maintenance::ABILITIES_V2_LOCK_OPTION, null ), 'The margin the stop preserved is what releases the lock.' );
		// The wire shape is unchanged: readers pad an unknown record out to its full action list, so
		// the Dashboard still gets one outcome per action it asked for.
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'unknown', $result['status'] );
		$this->assertSame( array( 'revisions', 'autodraft' ), array_column( $result['outcomes'], 'action' ) );
		$this->assertSame(
			array(
				'action'     => 'autodraft',
				'status'     => 'unknown',
				'affected'   => null,
				'error_code' => 'outcome_unknown',
			),
			$result['outcomes'][1]
		);
	}

	/**
	 * With no execution limit the deadline arithmetic answers "now plus the whole budget" every time
	 * it is asked, so an operation-level stop that asks again after each action is handed a fresh two
	 * minutes and never fires: the later action runs on the margin the sweep gave up to settle the
	 * record. The instant has to be pinned once for the request and consulted, not re-derived.
	 */
	public function test_operation_stops_starting_actions_under_an_unlimited_execution_limit() {
		global $wpdb;
		$parent_id = self::factory()->post->create();
		$this->insert_revisions( $parent_id, 10 );
		self::factory()->post->create( array( 'post_status' => 'auto-draft' ) );
		self::factory()->post->create( array( 'post_status' => 'auto-draft' ) );
		$auto_drafts = "SELECT COUNT(*) FROM $wpdb->posts WHERE post_status = 'auto-draft'";
		$before      = (int) $wpdb->get_var( $auto_drafts );
		$this->assertGreaterThan( 0, $before );

		$subject        = $this->real_subject();
		$original_limit = ini_get( 'max_execution_time' );
		// CLI and explicitly unlimited requests take the branch that grants the full 120s budget, and
		// no request clock can shorten it. Waiting two minutes out is not a test, so the request's
		// deadline is pinned two seconds ahead and the first action is stalled past it: the state the
		// operation reaches on its own once the sweep has spent the budget.
		ini_set( 'max_execution_time', 0 );
		$deadline = new \ReflectionProperty( MainWP_Child_Maintenance::class, 'abilities_v2_deadline' );
		$deadline->setAccessible( true );
		$stall = static function ( $query ) {
			static $stalled = false;
			if ( ! $stalled && 1 === preg_match( '/^\s*DELETE\s+FROM\s+\S*posts\b/i', $query ) ) {
				$stalled = true;
				$until   = time() + 3;
				while ( time() < $until ) {
					usleep( 50000 );
				}
			}
			return $query;
		};

		$preview = $this->real_request(
			$subject,
			'ability_maintenance_preview_v2',
			array(
				'actions'            => array( 'revisions', 'autodraft' ),
				'revision_retention' => 2,
			)
		);
		$this->assertTrue( $preview['ok'] );
		$deadline->setValue( $subject, time() + 2 );

		add_filter( 'query', $stall );
		try {
			$result = $this->real_request(
				$subject,
				'ability_maintenance_execute_v2',
				array(
					'operation_ref'      => '123e4567-e89b-42d3-a456-426614174526',
					'actions'            => array( 'revisions', 'autodraft' ),
					'revision_retention' => 2,
					'snapshot_revision'  => $preview['snapshot_revision'],
					'action_hash'        => hash( 'sha256', wp_json_encode( array( array( 'revisions', 'autodraft' ), 2 ) ) ),
				)
			);
		} finally {
			remove_filter( 'query', $stall );
			ini_set( 'max_execution_time', $original_limit );
		}

		$this->assertSame(
			$before,
			(int) $wpdb->get_var( $auto_drafts ),
			'An unlimited execution limit is not an unlimited request: the action after the stop must not have started, and its rows are the evidence.'
		);
		$this->assertSame(
			'2',
			$wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'revision' AND post_parent = %d", $parent_id ) ),
			'The sweep itself still ran its first batch before the pinned deadline passed.'
		);
		$stored = get_option( MainWP_Child_Maintenance::ABILITIES_V2_OPERATIONS_OPTION, array() );
		$this->assertSame(
			array( 'revisions' ),
			array_column( $stored['123e4567-e89b-42d3-a456-426614174526']['outcomes'], 'action' ),
			'The record holds an outcome for what ran and none for what was never started.'
		);
		$this->assertSame( 8, $stored['123e4567-e89b-42d3-a456-426614174526']['outcomes'][0]['affected'] );
		$this->assertSame( 'unknown', $stored['123e4567-e89b-42d3-a456-426614174526']['status'] );
		$this->assertNull( get_option( MainWP_Child_Maintenance::ABILITIES_V2_LOCK_OPTION, null ), 'The margin the stop preserved is what releases the lock.' );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'unknown', $result['status'] );
		$this->assertSame( array( 'revisions', 'autodraft' ), array_column( $result['outcomes'], 'action' ) );
	}

	/**
	 * Retention zero deletes every revision on the site in one statement, and nothing can interrupt a
	 * statement once it is issued, so a request with no budget left has to decline to start it. The
	 * operation-level stop above never reaches this: it lets the first action run, which is what makes
	 * the guard belong here rather than only in the caller.
	 */
	public function test_zero_retention_revision_delete_is_declined_when_the_budget_is_already_spent() {
		global $wpdb, $timestart;
		$parent_id = self::factory()->post->create();
		$this->insert_revisions( $parent_id, 5 );
		$revisions = $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'revision' AND post_parent = %d", $parent_id );

		$original_limit     = ini_get( 'max_execution_time' );
		$original_timestart = $timestart;
		ini_set( 'max_execution_time', 30 );
		// Past the limit minus the settle margin, so what is left of the request is negative.
		$timestart = microtime( true ) - 25;
		$sweep     = new \ReflectionMethod( MainWP_Child_Maintenance::class, 'abilities_v2_delete_revisions' );
		$sweep->setAccessible( true );

		try {
			$outcome = $sweep->invoke( $this->real_subject(), 0 );
		} finally {
			ini_set( 'max_execution_time', $original_limit );
			$timestart = $original_timestart;
		}

		$this->assertSame(
			array(
				'status'     => 'unknown',
				'affected'   => 0,
				'error_code' => 'outcome_unknown',
			),
			$outcome,
			'An exhausted budget reports the same unknown the paged path reports, with nothing destroyed.'
		);
		$this->assertSame( '5', $wpdb->get_var( $revisions ), 'The single bulk delete must not be issued on a budget that is already gone.' );
	}

	/**
	 * The action loop exempts the first action from its budget break, on the understanding that a
	 * destructive action decides for itself and reports the decision truthfully. A row delete that
	 * skips that decision issues its DELETE on a request with nothing left, and no statement can be
	 * interrupted once it is out: the margin that settles the record goes with it.
	 */
	public function test_row_delete_is_declined_when_the_budget_is_already_spent() {
		global $wpdb;
		self::factory()->post->create( array( 'post_status' => 'auto-draft' ) );
		$auto_drafts = "SELECT COUNT(*) FROM $wpdb->posts WHERE post_status = 'auto-draft'";
		$before      = (int) $wpdb->get_var( $auto_drafts );
		$this->assertGreaterThan( 0, $before );

		$this->spent_budget_execute( $this->real_subject(), 'autodraft', '123e4567-e89b-42d3-a456-426614174528' );

		$this->assertSame( $before, (int) $wpdb->get_var( $auto_drafts ), 'The rows the delete would have taken are the evidence it never ran.' );
	}

	/**
	 * The term loop deletes one term per iteration and holds no bound of its own, so a request that
	 * arrives with its limit already spent has to decline before the first wp_delete_term rather
	 * than partway through a taxonomy.
	 */
	public function test_term_delete_is_declined_when_the_budget_is_already_spent() {
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'post_tag' ) );

		$this->spent_budget_execute( $this->real_subject(), 'tags', '123e4567-e89b-42d3-a456-426614174529' );

		$this->assertInstanceOf( \WP_Term::class, get_term( $term_id, 'post_tag' ), 'The empty tag the loop would have deleted is the evidence it never ran.' );
	}

	/**
	 * OPTIMIZE TABLE rebuilds a table and runs for as long as that takes, once per prefixed table.
	 * Starting that series on an exhausted request is the fatal the deadline exists to prevent.
	 */
	public function test_table_optimize_is_declined_when_the_budget_is_already_spent() {
		$optimizes = array();
		$spy       = static function ( $query ) use ( &$optimizes ) {
			if ( 1 === preg_match( '/^\s*OPTIMIZE\s+TABLE\b/i', $query ) ) {
				$optimizes[] = $query;
			}
			return $query;
		};

		add_filter( 'query', $spy );
		try {
			$this->spent_budget_execute( $this->real_subject(), 'optimize', '123e4567-e89b-42d3-a456-426614174530' );
		} finally {
			remove_filter( 'query', $spy );
		}

		$this->assertSame( array(), $optimizes, 'No table may be rebuilt by a request that has nothing left to spend.' );
	}

	/**
	 * A delete-all transient run walks every name it listed, with an option write per name and no
	 * check between them, so the whole walk has to be declined up front.
	 */
	public function test_transient_delete_is_declined_when_the_budget_is_already_spent() {
		if ( wp_using_ext_object_cache() ) {
			$this->markTestSkipped( 'Transient maintenance reports unsupported behind an external object cache.' );
		}
		$this->clear_transients();
		set_transient( 'mwp_budget_guard', 'survivor', HOUR_IN_SECONDS );

		$this->spent_budget_execute( $this->real_subject(), 'transients_all', '123e4567-e89b-42d3-a456-426614174531' );

		$this->assertSame( 'survivor', get_transient( 'mwp_budget_guard' ), 'The transient the run listed is the evidence it never started deleting.' );
	}

	/**
	 * The entry guard bounds a run that arrives with nothing left, but a taxonomy of empty terms is
	 * spent inside the loop, one wp_delete_term at a time. A stop between terms leaves terms really
	 * deleted, so the outcome carries the count: the zero an entry decline reports would tell the
	 * Dashboard the taxonomy is untouched while the tags are gone.
	 */
	public function test_term_delete_reports_the_terms_it_destroyed_when_the_budget_runs_out_mid_loop() {
		global $wpdb;
		$seeded = 5;
		for ( $index = 0; $index < $seeded; $index++ ) {
			self::factory()->term->create(
				array(
					'taxonomy' => 'post_tag',
					'name'     => 'mwp-budget-tag-' . $index,
				)
			);
		}
		$tags = $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->term_taxonomy WHERE taxonomy = %s", 'post_tag' );
		$this->assertSame( $seeded, (int) $wpdb->get_var( $tags ) );

		$subject  = $this->real_subject();
		$deadline = $this->deadline_property();
		// The clock is moved rather than waited out: a stall long enough to expire a real deadline
		// costs the suite seconds per test and still leaves which iteration it lands on to chance.
		// delete_term fires once per term the loop actually removed, which is where the request runs
		// out here.
		$stop = static function () use ( $subject, $deadline ) {
			static $deleted = 0;
			++$deleted;
			if ( 2 === $deleted ) {
				$deadline->setValue( $subject, time() - 1 );
			}
		};

		add_action( 'delete_term', $stop );
		try {
			$outcome = $this->mid_loop_execute( $subject, 'tags', '123e4567-e89b-42d3-a456-426614174532' );
		} finally {
			remove_action( 'delete_term', $stop );
		}

		$this->assertSame( 'unknown', $outcome['status'] );
		$this->assertSame( 'outcome_unknown', $outcome['error_code'] );
		$this->assertGreaterThan( 0, $outcome['affected'], 'The run deleted terms before it stopped, and an entry decline is not what happened.' );
		$this->assertLessThan( $seeded, $outcome['affected'], 'A run that reached the end of the taxonomy is not the stop this pins.' );
		$this->assertSame(
			$seeded - $outcome['affected'],
			(int) $wpdb->get_var( $tags ),
			'The terms missing from the taxonomy are exactly the ones the outcome claims.'
		);
	}

	/**
	 * One OPTIMIZE TABLE cannot be interrupted, but the series over a site's prefixed tables can. The
	 * tables the run rebuilt stay rebuilt, so the count it reports is what the Dashboard reconciles
	 * against, and it has to be the tables it issued rather than none or all of them.
	 */
	public function test_table_optimize_reports_the_tables_it_rebuilt_when_the_budget_runs_out_mid_loop() {
		global $wpdb;
		$prefixed = 0;
		foreach ( $wpdb->get_results( 'SHOW TABLE STATUS FROM `' . esc_sql( DB_NAME ) . '`', ARRAY_A ) as $table ) {
			if ( 0 === strpos( $table['Name'], $wpdb->prefix ) ) {
				++$prefixed;
			}
		}
		$this->assertGreaterThan( 2, $prefixed, 'The site needs more prefixed tables than the run is allowed to reach.' );

		$subject   = $this->real_subject();
		$deadline  = $this->deadline_property();
		$optimizes = array();
		// OPTIMIZE TABLE commits implicitly, which would take the harness transaction and every
		// fixture in it, so the statement is answered instead of run. What is under test is the count
		// the loop reports against the statements it issued, and the spy sees every one of those.
		$spy = static function ( $query ) use ( $subject, $deadline, &$optimizes ) {
			if ( 1 !== preg_match( '/^\s*OPTIMIZE\s+TABLE\b/i', $query ) ) {
				return $query;
			}
			$optimizes[] = $query;
			if ( 2 === count( $optimizes ) ) {
				$deadline->setValue( $subject, time() - 1 );
			}
			return 'SELECT 1';
		};

		add_filter( 'query', $spy );
		try {
			$outcome = $this->mid_loop_execute( $subject, 'optimize', '123e4567-e89b-42d3-a456-426614174533' );
		} finally {
			remove_filter( 'query', $spy );
		}

		$this->assertSame( 'unknown', $outcome['status'] );
		$this->assertSame( 'outcome_unknown', $outcome['error_code'] );
		$this->assertSame( count( $optimizes ), $outcome['affected'], 'The outcome counts the tables the run rebuilt, not the ones it listed.' );
		$this->assertGreaterThan( 0, $outcome['affected'] );
		$this->assertLessThan( $prefixed, $outcome['affected'], 'A run that reached every prefixed table is not the stop this pins.' );
	}

	/**
	 * A delete-all run walks every name the site made, and the walk outlasts the request rather than
	 * any single delete inside it. The transients it already removed are gone from the options table,
	 * so the stop reports them instead of the zero an entry decline reports.
	 */
	public function test_transient_delete_reports_the_entries_it_removed_when_the_budget_runs_out_mid_loop() {
		if ( wp_using_ext_object_cache() ) {
			$this->markTestSkipped( 'Transient maintenance reports unsupported behind an external object cache.' );
		}
		global $wpdb;
		$this->clear_transients();
		$seeded = 5;
		for ( $index = 0; $index < $seeded; $index++ ) {
			set_transient( 'mwp_budget_walk_' . $index, 'value-' . $index, HOUR_IN_SECONDS );
		}
		$values = $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->options WHERE option_name LIKE %s AND option_name NOT LIKE %s", $wpdb->esc_like( '_transient_' ) . '%', $wpdb->esc_like( '_transient_timeout_' ) . '%' );
		$this->assertSame( $seeded, (int) $wpdb->get_var( $values ) );

		$subject  = $this->real_subject();
		$deadline = $this->deadline_property();
		// deleted_transient fires once per name the walk really removed, which is the same event the
		// loop counts, so the request runs out between two of them rather than at some point the
		// wall clock happens to land on.
		$stop = static function () use ( $subject, $deadline ) {
			static $deleted = 0;
			++$deleted;
			if ( 2 === $deleted ) {
				$deadline->setValue( $subject, time() - 1 );
			}
		};

		add_action( 'deleted_transient', $stop );
		try {
			$outcome = $this->mid_loop_execute( $subject, 'transients_all', '123e4567-e89b-42d3-a456-426614174534' );
		} finally {
			remove_action( 'deleted_transient', $stop );
		}

		$this->assertSame( 'unknown', $outcome['status'] );
		$this->assertSame( 'outcome_unknown', $outcome['error_code'] );
		$this->assertGreaterThan( 0, $outcome['affected'], 'The walk removed transients before it stopped, and an entry decline is not what happened.' );
		$this->assertLessThan( $seeded, $outcome['affected'], 'A walk that reached the end of the list is not the stop this pins.' );
		$this->assertSame(
			$seeded - $outcome['affected'],
			(int) $wpdb->get_var( $values ),
			'The transients missing from the options table are exactly the ones the outcome claims.'
		);
	}

	/**
	 * A terminal unknown record may hold fewer outcomes than actions, and every reader treats the
	 * ones it holds as a prefix of the action list. The option behind it is untrusted - a hand edit
	 * or a partial restore lands here - so a list keyed [1] rather than [0] pins its outcome to the
	 * second action while the readers count it against the first: the projection then drops one
	 * action, repeats another, and hands back integer keys that encode as a JSON object.
	 */
	public function test_a_record_whose_outcome_keys_skip_an_index_is_refused_instead_of_projected() {
		$operation_ref = '123e4567-e89b-42d3-a456-426614174527';
		$actions       = array( 'revisions', 'autodraft' );
		$retention     = 5;
		$snapshot      = str_repeat( 'a', 64 );
		$action_hash   = hash( 'sha256', wp_json_encode( array( $actions, $retention ) ) );
		$at            = time() - 60;
		$record        = array(
			'operation_ref'      => $operation_ref,
			'effect_hash'        => hash( 'sha256', wp_json_encode( array( $operation_ref, $actions, $retention, $snapshot, $action_hash ) ) ),
			'action_hash'        => $action_hash,
			'snapshot_revision'  => $snapshot,
			'actions'            => $actions,
			'revision_retention' => $retention,
			'status'             => 'unknown',
			'outcomes'           => array(
				1 => array(
					'action'     => 'autodraft',
					'status'     => 'succeeded',
					'affected'   => 1,
					'error_code' => null,
				),
			),
			'accepted_at'        => $at,
			'finished_at'        => $at,
			'retryable'          => false,
			'report_emitted'     => false,
			'updated_at'         => $at,
		);
		$validator     = new \ReflectionMethod( MainWP_Child_Maintenance::class, 'abilities_v2_valid_operation_record' );
		$validator->setAccessible( true );
		$subject = $this->real_subject();

		$this->assertFalse( $validator->invoke( $subject, $record ), 'Outcome keys that skip an index are a damaged row, not a short one.' );

		// The same short list keyed from zero is what a run that stopped between actions really
		// writes, and refusing that would refuse every stopped run in the store.
		$contiguous             = $record;
		$contiguous['outcomes'] = array(
			array(
				'action'     => 'revisions',
				'status'     => 'succeeded',
				'affected'   => 3,
				'error_code' => null,
			),
		);
		$this->assertTrue( $validator->invoke( $subject, $contiguous ) );

		update_option( MainWP_Child_Maintenance::ABILITIES_V2_OPERATIONS_OPTION, array( $operation_ref => $record ), false );
		$result = $this->real_request( $subject, 'ability_maintenance_operation_v2', array( 'operation_ref' => $operation_ref ) );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'storage_unavailable', $result['code'] );
		$this->assertArrayNotHasKey( 'outcomes', $result, 'A damaged row is refused, not projected into a response.' );
	}

	/** Fill one parent with revisions cheaply; the sweep only ever reads them through SQL. */
	private function insert_revisions( $parent_id, $count ) {
		global $wpdb;
		for ( $index = 0; $index < $count; $index++ ) {
			$when = gmdate( 'Y-m-d H:i:s', time() - ( 60 * ( $count - $index ) ) );
			$wpdb->insert(
				$wpdb->posts,
				array(
					'post_author'           => 1,
					'post_date'             => $when,
					'post_date_gmt'         => $when,
					'post_content'          => 'batch revision',
					'post_title'            => 'batch-revision-' . $index,
					'post_excerpt'          => '',
					'post_status'           => 'inherit',
					'comment_status'        => 'closed',
					'ping_status'           => 'closed',
					'post_password'         => '',
					'post_name'             => $parent_id . '-batch-revision-' . $index,
					'to_ping'               => '',
					'pinged'                => '',
					'post_modified'         => $when,
					'post_modified_gmt'     => $when,
					'post_content_filtered' => '',
					'post_parent'           => $parent_id,
					'guid'                  => '',
					'menu_order'            => 0,
					'post_type'             => 'revision',
					'post_mime_type'        => '',
				)
			);
		}
	}

	private function invoke_private( $subject, $method ) {
		$reflection = new \ReflectionMethod( MainWP_Child_Maintenance::class, $method );
		$reflection->setAccessible( true );
		$reflection->invoke( $subject );
	}

	private function real_subject() {
		$reflection = new ReflectionClass( MainWP_Child_Maintenance::class );
		return $reflection->newInstanceWithoutConstructor();
	}

	private function real_request( $subject, $operation, $payload ) {
		return $subject->abilities_v2(
			array(
				'protocol'  => '2',
				'operation' => $operation,
				'payload'   => $payload,
			)
		);
	}

	/**
	 * Preview then execute one action set through the real class, as the Dashboard does.
	 *
	 * @return array Preview response and execute response.
	 */
	private function real_execute( $subject, $actions, $retention, $operation_ref ) {
		$catalog   = array( 'revisions', 'autodraft', 'trashpost', 'spam', 'pending', 'trashcomment', 'tags', 'categories', 'optimize', 'transients_expired', 'transients_all' );
		$canonical = array_values(
			array_filter(
				$catalog,
				static function ( $action ) use ( $actions ) {
					return in_array( $action, $actions, true );
				}
			)
		);
		$preview   = $this->real_request(
			$subject,
			'ability_maintenance_preview_v2',
			array(
				'actions'            => $actions,
				'revision_retention' => $retention,
			)
		);
		$this->assertTrue( $preview['ok'] );
		$result = $this->real_request(
			$subject,
			'ability_maintenance_execute_v2',
			array(
				'operation_ref'      => $operation_ref,
				'actions'            => $actions,
				'revision_retention' => $retention,
				'snapshot_revision'  => $preview['snapshot_revision'],
				'action_hash'        => hash( 'sha256', wp_json_encode( array( $canonical, $retention ) ) ),
			)
		);
		return array( $preview, $result );
	}

	/** The instant this request stops at, reachable so a test can pin it or move it as the clock would. */
	private function deadline_property() {
		$deadline = new \ReflectionProperty( MainWP_Child_Maintenance::class, 'abilities_v2_deadline' );
		$deadline->setAccessible( true );
		return $deadline;
	}

	/**
	 * Preview then execute one action on a request whose budget outlives the entry check.
	 *
	 * The deadline is pinned ahead of the run rather than behind it, so the action starts its loop
	 * and only the caller's own trigger ends it. Pinned behind, this would be the entry guard again.
	 *
	 * @return array Durable outcome the run left for the action.
	 */
	private function mid_loop_execute( $subject, $action, $operation_ref ) {
		$preview = $this->real_request(
			$subject,
			'ability_maintenance_preview_v2',
			array(
				'actions'            => array( $action ),
				'revision_retention' => 5,
			)
		);
		$this->assertTrue( $preview['ok'] );
		$this->deadline_property()->setValue( $subject, time() + 60 );

		$result = $this->real_request(
			$subject,
			'ability_maintenance_execute_v2',
			array(
				'operation_ref'      => $operation_ref,
				'actions'            => array( $action ),
				'revision_retention' => 5,
				'snapshot_revision'  => $preview['snapshot_revision'],
				'action_hash'        => hash( 'sha256', wp_json_encode( array( array( $action ), 5 ) ) ),
			)
		);

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'unknown', $result['status'] );
		$stored = get_option( MainWP_Child_Maintenance::ABILITIES_V2_OPERATIONS_OPTION, array() );
		$this->assertSame( 'unknown', $stored[ $operation_ref ]['status'] );
		$this->assertSame( $action, $stored[ $operation_ref ]['outcomes'][0]['action'] );
		return $stored[ $operation_ref ]['outcomes'][0];
	}

	/**
	 * Run one action as the only action of an operation whose budget is already gone, and assert
	 * the durable record it leaves behind.
	 *
	 * The deadline is fixed the first time it is read, so it is pinned after the preview: that is
	 * the state the operation reaches on its own once earlier work has spent the request. Only
	 * action one is exercised, because the loop's own break covers every later one.
	 */
	private function spent_budget_execute( $subject, $action, $operation_ref ) {
		$preview = $this->real_request(
			$subject,
			'ability_maintenance_preview_v2',
			array(
				'actions'            => array( $action ),
				'revision_retention' => 5,
			)
		);
		$this->assertTrue( $preview['ok'] );
		$deadline = new \ReflectionProperty( MainWP_Child_Maintenance::class, 'abilities_v2_deadline' );
		$deadline->setAccessible( true );
		$deadline->setValue( $subject, time() - 1 );

		$result = $this->real_request(
			$subject,
			'ability_maintenance_execute_v2',
			array(
				'operation_ref'      => $operation_ref,
				'actions'            => array( $action ),
				'revision_retention' => 5,
				'snapshot_revision'  => $preview['snapshot_revision'],
				'action_hash'        => hash( 'sha256', wp_json_encode( array( array( $action ), 5 ) ) ),
			)
		);

		$this->assertTrue( $result['ok'] );
		$stored = get_option( MainWP_Child_Maintenance::ABILITIES_V2_OPERATIONS_OPTION, array() );
		$this->assertSame(
			array(
				'action'     => $action,
				'status'     => 'unknown',
				'affected'   => 0,
				'error_code' => 'outcome_unknown',
			),
			$stored[ $operation_ref ]['outcomes'][0],
			'Nothing was destroyed, and what the action would have removed is not known.'
		);
		$this->assertSame( 'unknown', $stored[ $operation_ref ]['status'] );
	}

	/** Build one durable record left in 'running' by a run that never came back. */
	private function running_record( $index, $age, $snapshot = null ) {
		$operation_ref = sprintf( '123e4567-e89b-42d3-a456-%012d', $index );
		$actions       = array( 'autodraft' );
		$retention     = 5;
		$snapshot      = null === $snapshot ? str_repeat( 'b', 64 ) : $snapshot;
		$action_hash   = hash( 'sha256', wp_json_encode( array( $actions, $retention ) ) );
		$at            = time() - $age;
		return array(
			'operation_ref'      => $operation_ref,
			'effect_hash'        => hash( 'sha256', wp_json_encode( array( $operation_ref, $actions, $retention, $snapshot, $action_hash ) ) ),
			'action_hash'        => $action_hash,
			'snapshot_revision'  => $snapshot,
			'actions'            => $actions,
			'revision_retention' => $retention,
			'status'             => 'running',
			'outcomes'           => array(),
			'accepted_at'        => $at,
			'finished_at'        => null,
			'retryable'          => false,
			'report_emitted'     => false,
			'updated_at'         => $at,
		);
	}

	/** Build one durable record whose run finished $age seconds ago. */
	private function settled_record( $index, $age ) {
		$record                   = $this->running_record( $index, $age + 5 );
		$record['status']         = 'succeeded';
		$record['outcomes']       = array(
			array(
				'action'     => 'autodraft',
				'status'     => 'succeeded',
				'affected'   => 1,
				'error_code' => null,
			),
		);
		$record['finished_at']    = time() - $age;
		$record['report_emitted'] = true;
		$record['updated_at']     = time() - $age;
		return $record;
	}

	/** Build the record the store leaves behind when it settles a run abandoned $age seconds ago. */
	private function settled_unknown_record( $index, $age, $snapshot = null ) {
		$record                = $this->running_record( $index, $age, $snapshot );
		$record['status']      = 'unknown';
		$record['outcomes']    = array(
			array(
				'action'     => 'autodraft',
				'status'     => 'unknown',
				'affected'   => null,
				'error_code' => 'outcome_unknown',
			),
		);
		$record['finished_at'] = $record['updated_at'];
		return $record;
	}

	private function clear_transients() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->options WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_' ) . '%', $wpdb->esc_like( '_site_transient_' ) . '%' ) );
		wp_cache_flush();
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

class Testable_MainWP_Child_Maintenance extends MainWP_Child_Maintenance {

	public $preview_impacts = array();
	public $action_results = array();
	public $execution_log = array();
	public $operation_records = array();
	public $write_snapshots = array();
	public $write_operations_succeeds = true;
	public $report_count = 0;
	public $begin_mutation_succeeds = true;
	public $end_mutation_succeeds = true;

	protected function abilities_v2_preview_impact( $action, $revision_retention ) {
		unset( $revision_retention );
		return array_key_exists( $action, $this->preview_impacts ) ? $this->preview_impacts[ $action ] : false;
	}

	protected function abilities_v2_execute_action( $action, $revision_retention ) {
		unset( $revision_retention );
		$this->execution_log[] = $action;
		return array_key_exists( $action, $this->action_results ) ? $this->action_results[ $action ] : false;
	}

	protected function abilities_v2_read_operations() {
		return $this->operation_records;
	}

	protected function abilities_v2_write_operations( $operations ) {
		$this->write_snapshots[] = $operations;
		if ( $this->write_operations_succeeds ) {
			$this->operation_records = $operations;
		}
		return $this->write_operations_succeeds;
	}

	protected function abilities_v2_emit_report( $actions, $revision_retention ) {
		unset( $actions, $revision_retention );
		++$this->report_count;
	}

	protected function abilities_v2_begin_mutation() {
		return $this->begin_mutation_succeeds;
	}

	protected function abilities_v2_end_mutation() {
		return $this->end_mutation_succeeds;
	}

	protected function abilities_v2_renew_mutation() {
		return true;
	}
}

/** Real maintenance behaviour, with a mutation lock that stops renewing after a set number of calls. */
class Renewal_Limited_MainWP_Child_Maintenance extends MainWP_Child_Maintenance {

	public $renewals_before_failure = 0;
	public $renewal_calls = 0;

	protected function abilities_v2_renew_mutation() {
		++$this->renewal_calls;
		return $this->renewal_calls <= $this->renewals_before_failure && parent::abilities_v2_renew_mutation();
	}
}
