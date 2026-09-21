<?php
/**
 * Virusdie signed installer protocol tests.
 *
 * @package MainWP_Child
 */

namespace MainWP\Child;

use ReflectionClass;
use WP_UnitTestCase;

class Test_MainWP_Child_Virusdie_V1 extends WP_UnitTestCase {

	public function test_capabilities_advertise_only_the_narrow_operations() {
		$result = ( new Testable_MainWP_Child_Virusdie() )->request_v1( $this->request( 'capabilities', array() ) );

		$this->assertSame( array( 'protocol', 'operation', 'ok', 'operations', 'max_artifact_bytes' ), array_keys( $result ) );
		$this->assertSame( array( 'install', 'status', 'remove' ), $result['operations'] );
		$this->assertSame( 262144, $result['max_artifact_bytes'] );
	}

	public function test_install_reserves_downloads_once_and_replays_truth() {
		$subject                = new Testable_MainWP_Child_Virusdie();
		$subject->gateway_bytes = '<?php // signed fixture';
		$request                = $this->install_request( $subject->gateway_bytes );

		$result = $subject->request_v1( $request );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'completed', $result['status'] );
		$this->assertTrue( $result['installed'] );
		$this->assertSame( 1, $subject->gateway_reads );
		$this->assertSame( 1, $subject->installs );
		$this->assertStringNotContainsString( 'one-use-private-token', wp_json_encode( $subject->receipts ) );

		$this->assertSame( $result, $subject->request_v1( $request ) );
		$this->assertSame( 1, $subject->gateway_reads );
		$this->assertSame( 1, $subject->installs );
		$this->assertSame( $result, $subject->request_v1( $this->request( 'status', array( 'request_ref' => $request['payload']['request_ref'] ) ) ) );
	}

	public function test_dispatching_install_is_unknown_without_blind_retry() {
		$subject                = new Testable_MainWP_Child_Virusdie();
		$subject->gateway_bytes = '<?php // signed fixture';
		$request                = $this->install_request( $subject->gateway_bytes );
		$subject->seed_dispatching( $request );

		$result = $subject->request_v1( $request );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'unknown', $result['status'] );
		$this->assertSame( 'outcome_unknown', $result['code'] );
		$this->assertSame( 0, $subject->gateway_reads );
		$this->assertSame( 0, $subject->installs );
	}

	public function test_digest_failure_and_existing_target_are_non_mutating() {
		$subject                = new Testable_MainWP_Child_Virusdie();
		$subject->gateway_bytes = 'wrong';
		$result                 = $subject->request_v1( $this->install_request( 'expected' ) );
		$this->assertSame( 'digest_mismatch', $result['code'] );
		$this->assertSame( 0, $subject->installs );

		$existing                = new Testable_MainWP_Child_Virusdie();
		$existing->target_exists = true;
		$existing->target_bytes  = 'existing';
		$result                  = $existing->request_v1( $this->install_request( 'expected' ) );
		$this->assertSame( 'target_exists', $result['code'] );
		$this->assertSame( 0, $existing->gateway_reads );
	}

	public function test_failed_install_reports_the_observed_absent_target() {
		$subject                = new Testable_MainWP_Child_Virusdie();
		$subject->gateway_bytes = 'wrong';
		$request                = $this->install_request( 'expected' );

		$result = $subject->request_v1( $request );
		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( 'digest_mismatch', $result['code'] );
		$this->assertFalse( $result['installed'] );
		$this->assertSame( 0, $subject->installs );

		$status = $subject->request_v1( $this->request( 'status', array( 'request_ref' => $request['payload']['request_ref'] ) ) );
		$this->assertSame( $result, $status );
	}

	public function test_failed_remove_reports_the_still_present_target() {
		$subject                = new Testable_MainWP_Child_Virusdie();
		$subject->target_exists = true;
		$subject->target_bytes  = 'installed bytes';
		$subject->remove_fails  = true;
		$request                = $this->remove_request( $subject->target_bytes );

		$result = $subject->request_v1( $request );
		$this->assertSame( 'unknown', $result['status'] );
		$this->assertSame( 'outcome_unknown', $result['code'] );
		$this->assertTrue( $result['installed'] );
		$this->assertTrue( $subject->target_exists );

		$status = $subject->request_v1( $this->request( 'status', array( 'request_ref' => $request['payload']['request_ref'] ) ) );
		$this->assertSame( $result, $status );
	}

	public function test_unreadable_target_settles_installed_as_unknown() {
		$subject                             = new Testable_MainWP_Child_Virusdie();
		$subject->durable_receipts           = true;
		$subject->gateway_bytes              = 'wrong';
		$subject->break_snapshot_on_download = true;
		$request                             = $this->install_request( 'expected' );

		$result = $subject->request_v1( $request );
		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( 'digest_mismatch', $result['code'] );
		$this->assertNull( $result['installed'] );

		$status = $subject->request_v1( $this->request( 'status', array( 'request_ref' => $request['payload']['request_ref'] ) ) );
		$this->assertSame( $result, $status );
	}

	public function test_remove_requires_exact_current_hash_and_replays() {
		$subject                = new Testable_MainWP_Child_Virusdie();
		$subject->target_exists = true;
		$subject->target_bytes  = 'installed bytes';
		$request                = $this->request(
			'remove',
			array(
				'request_ref'     => '123e4567-e89b-42d3-a456-426614175003',
				'basename'        => 'virusdie_fixture.php',
				'expected_sha256' => hash( 'sha256', 'installed bytes' ),
				'site_generation' => str_repeat( 'b', 64 ),
			)
		);

		$result = $subject->request_v1( $request );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'completed', $result['status'] );
		$this->assertFalse( $result['installed'] );
		$this->assertSame( 1, $subject->removals );
		$this->assertSame( $result, $subject->request_v1( $request ) );
		$this->assertSame( 1, $subject->removals );

		$drifted                = new Testable_MainWP_Child_Virusdie();
		$drifted->target_exists = true;
		$drifted->target_bytes  = 'drifted';
		$this->assertSame( 'stale_target', $drifted->request_v1( $request )['code'] );
		$this->assertSame( 0, $drifted->removals );
	}

	public function test_malformed_alias_path_and_gateway_fail_closed() {
		$subject = new Testable_MainWP_Child_Virusdie();
		$request = $this->install_request( 'bytes' );
		$request['payload']['request_id'] = $request['payload']['request_ref'];
		unset( $request['payload']['request_ref'] );
		$this->assertSame( 'invalid_request', $subject->request_v1( $request )['code'] );

		$request = $this->install_request( 'bytes' );
		$request['payload']['basename'] = '../virusdie.php';
		$this->assertSame( 'invalid_request', $subject->request_v1( $request )['code'] );
		$request = $this->install_request( 'bytes' );
		$request['payload']['gateway_url'] = 'https://attacker.example/file';
		$this->assertSame( 'gateway_rejected', $subject->request_v1( $request )['code'] );
	}

	public function test_expired_receipt_is_never_replayed_and_status_does_not_delete_it() {
		$subject                   = new Testable_MainWP_Child_Virusdie();
		$subject->durable_receipts = true;
		$subject->gateway_bytes    = '<?php // signed fixture';
		$request                   = $this->install_request( $subject->gateway_bytes );
		$ref                       = $request['payload']['request_ref'];
		$key                       = 'mainwp_child_virusdie_v1_' . hash( 'sha256', $ref );
		$expired                   = $this->expired_receipt( $ref );
		$this->assertTrue( $subject->seed_durable_receipt( $ref, $expired ) );

		$status = $subject->request_v1( $this->request( 'status', array( 'request_ref' => $ref ) ) );
		$this->assertFalse( $status['ok'] );
		$this->assertSame( 'not_found', $status['code'] );
		$this->assertSame( $expired, get_option( $key, false ) );

		$result = $subject->request_v1( $request );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'completed', $result['status'] );
		$this->assertSame( 1, $subject->gateway_reads );
		$this->assertSame( 1, $subject->installs );
		$this->assertSame( $result, get_option( $key, false )['result'] );

		delete_option( $key );
	}

	/**
	 * A request reference is one-shot, so nothing ever comes back to the row it created and the
	 * eviction on the mutation path only reaches the reference the current request names. Every
	 * other expired row is left behind, and the index is what a later mutation finds them by.
	 */
	public function test_a_mutation_reclaims_a_leaked_receipt_row_from_an_earlier_reference() {
		$subject                   = new Testable_MainWP_Child_Virusdie();
		$subject->durable_receipts = true;
		$subject->gateway_bytes    = '<?php // signed fixture';
		$request                   = $this->install_request( $subject->gateway_bytes );
		$key                       = 'mainwp_child_virusdie_v1_' . hash( 'sha256', $request['payload']['request_ref'] );
		$leaked                    = 'mainwp_child_virusdie_v1_' . hash( 'sha256', '123e4567-e89b-42d3-a456-426614175020' );
		$this->assertTrue( add_option( $leaked, $this->expired_receipt( '123e4567-e89b-42d3-a456-426614175020' ), '', false ) );
		$this->assertTrue( update_option( 'mainwp_child_virusdie_receipt_index', array( array( 'key' => $leaked, 'expires_at' => time() - 86400 ) ), false ) );

		$result = $subject->request_v1( $request );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'completed', $result['status'] );
		$this->assertNull( $this->cold_option( $leaked ) );
		// The reclaimed entry left with its row, and this request's own row took its place.
		$this->assertSame( array( $key ), wp_list_pluck( get_option( 'mainwp_child_virusdie_receipt_index', array() ), 'key' ) );

		delete_option( $key );
		delete_option( 'mainwp_child_virusdie_receipt_index' );
	}

	/**
	 * A row nothing can parse is exactly the row a resent request still needs standing in its way:
	 * deleting one would let the same reference run its effect a second time.
	 */
	public function test_a_malformed_indexed_receipt_row_is_kept_rather_than_reclaimed() {
		$subject                   = new Testable_MainWP_Child_Virusdie();
		$subject->durable_receipts = true;
		$subject->gateway_bytes    = '<?php // signed fixture';
		$request                   = $this->install_request( $subject->gateway_bytes );
		$malformed                 = 'mainwp_child_virusdie_v1_' . hash( 'sha256', '123e4567-e89b-42d3-a456-426614175021' );
		$this->assertTrue( add_option( $malformed, array( 'effect_hash' => 'unreadable' ), '', false ) );
		update_option( 'mainwp_child_virusdie_receipt_index', array( array( 'key' => $malformed, 'expires_at' => time() - 86400 ) ), false );

		$this->assertTrue( $subject->request_v1( $request )['ok'] );

		$this->assertSame( array( 'effect_hash' => 'unreadable' ), $this->cold_option( $malformed ) );
		$this->assertContains( $malformed, wp_list_pluck( get_option( 'mainwp_child_virusdie_receipt_index', array() ), 'key' ) );

		delete_option( $malformed );
		delete_option( 'mainwp_child_virusdie_v1_' . hash( 'sha256', $request['payload']['request_ref'] ) );
		delete_option( 'mainwp_child_virusdie_receipt_index' );
	}

	/**
	 * The index is bookkeeping, so a full one gives up its oldest entry rather than travelling back
	 * into a mutation as a refusal. The row that entry named goes back to leaking, which is where it
	 * was before there was an index at all.
	 */
	public function test_a_full_index_drops_its_oldest_entry_instead_of_refusing_the_mutation() {
		$subject                   = new Testable_MainWP_Child_Virusdie();
		$subject->durable_receipts = true;
		$subject->gateway_bytes    = '<?php // signed fixture';
		$request                   = $this->install_request( $subject->gateway_bytes );
		$key                       = 'mainwp_child_virusdie_v1_' . hash( 'sha256', $request['payload']['request_ref'] );
		$full                      = array();
		for ( $index = 0; $index < 200; ++$index ) {
			$full[] = array(
				'key'        => 'mainwp_child_virusdie_v1_' . hash( 'sha256', 'filler-' . $index ),
				'expires_at' => time() + 86400,
			);
		}
		update_option( 'mainwp_child_virusdie_receipt_index', $full, false );

		$result = $subject->request_v1( $request );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 1, $subject->installs );
		$stored = wp_list_pluck( get_option( 'mainwp_child_virusdie_receipt_index', array() ), 'key' );
		$this->assertCount( 200, $stored );
		$this->assertNotContains( $full[0]['key'], $stored );
		$this->assertSame( $key, end( $stored ) );

		delete_option( $key );
		delete_option( 'mainwp_child_virusdie_receipt_index' );
	}

	/**
	 * This class holds no lane, so the sweep's read and its delete are separated by a window a
	 * concurrent request can land in: the reference it judged is reclaimed and reserved again, and
	 * the delete arrives after the fresh dispatch marker was written. A delete matched on the option
	 * key alone would take that marker with it, leaving the request that wrote it an effect to
	 * perform and no receipt to settle - and a retry against restored preconditions to perform it
	 * twice. The filter puts this test inside that window: the sweep reads the value the racer
	 * replaced, while the row already holds the replacement.
	 */
	public function test_a_receipt_row_rewritten_after_the_sweep_read_it_survives_the_delete() {
		$subject                   = new Testable_MainWP_Child_Virusdie();
		$subject->durable_receipts = true;
		$subject->gateway_bytes    = '<?php // signed fixture';
		$request                   = $this->install_request( $subject->gateway_bytes );
		$key                       = 'mainwp_child_virusdie_v1_' . hash( 'sha256', $request['payload']['request_ref'] );
		$raced                     = '123e4567-e89b-42d3-a456-426614175022';
		$leaked                    = 'mainwp_child_virusdie_v1_' . hash( 'sha256', $raced );
		$replacement               = $this->dispatching_receipt_row( $raced );
		$this->assertTrue( add_option( $leaked, $replacement, '', false ) );
		update_option( 'mainwp_child_virusdie_receipt_index', array( array( 'key' => $leaked, 'expires_at' => time() - 86400 ) ), false );
		$stale = $this->expired_receipt( $raced );
		$read  = static function () use ( $stale ) {
			return $stale;
		};
		add_filter( "option_$leaked", $read );

		$this->assertTrue( $subject->request_v1( $request )['ok'] );

		remove_filter( "option_$leaked", $read );
		$this->assertSame( $replacement, $this->cold_option( $leaked ) );
		// Nothing was reclaimed, so the entry stays where a refused predicate would leave it.
		$this->assertContains( $leaked, wp_list_pluck( get_option( 'mainwp_child_virusdie_receipt_index', array() ), 'key' ) );

		delete_option( $key );
		delete_option( $leaked );
		delete_option( 'mainwp_child_virusdie_receipt_index' );
	}

	/**
	 * add() can never write past the cap, so an index longer than any cap could produce was put
	 * there by a hand edit, a partial restore or corruption. Reading it entry by entry is unbounded
	 * work on a path that has an effect to reserve, and refusing over it is not on the table either.
	 */
	public function test_an_index_beyond_the_ceiling_is_discarded_rather_than_walked() {
		$subject                   = new Testable_MainWP_Child_Virusdie();
		$subject->durable_receipts = true;
		$subject->gateway_bytes    = '<?php // signed fixture';
		$request                   = $this->install_request( $subject->gateway_bytes );
		$key                       = 'mainwp_child_virusdie_v1_' . hash( 'sha256', $request['payload']['request_ref'] );
		$oversized                 = array();
		for ( $entry = 0; $entry <= MainWP_Child_Receipt_Index::RAW_ENTRY_CEILING; ++$entry ) {
			$oversized[] = array(
				'key'        => 'mainwp_child_virusdie_v1_' . hash( 'sha256', 'filler-' . $entry ),
				'expires_at' => time() + 86400,
			);
		}
		update_option( 'mainwp_child_virusdie_receipt_index', $oversized, false );

		$result = $subject->request_v1( $request );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 1, $subject->installs );
		// Discarded whole and written over, rather than normalized down to the cap one entry at a time.
		$this->assertSame( array( $key ), wp_list_pluck( get_option( 'mainwp_child_virusdie_receipt_index', array() ), 'key' ) );

		delete_option( $key );
		delete_option( 'mainwp_child_virusdie_receipt_index' );
	}

	/**
	 * Index work is where a poisoned index makes a request die, and a request that dies after
	 * reserving leaves a marker nothing can settle - which every later request under that reference
	 * then reads. Sweeping before the reservation exists keeps a fatal in here from stranding one.
	 */
	public function test_the_sweep_runs_before_the_request_reserves_its_own_receipt() {
		global $wpdb;
		$subject                   = new Testable_MainWP_Child_Virusdie();
		$subject->durable_receipts = true;
		$subject->gateway_bytes    = '<?php // signed fixture';
		$request                   = $this->install_request( $subject->gateway_bytes );
		$key                       = 'mainwp_child_virusdie_v1_' . hash( 'sha256', $request['payload']['request_ref'] );
		$raced                     = '123e4567-e89b-42d3-a456-426614175023';
		$leaked                    = 'mainwp_child_virusdie_v1_' . hash( 'sha256', $raced );
		$this->assertTrue( add_option( $leaked, $this->expired_receipt( $raced ), '', false ) );
		update_option( 'mainwp_child_virusdie_receipt_index', array( array( 'key' => $leaked, 'expires_at' => time() - 86400 ) ), false );
		$reserved = null;
		$probe    = static function ( $value ) use ( &$reserved, $key, $wpdb ) {
			$reserved = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->options WHERE option_name = %s", $key ) );
			return $value;
		};
		add_filter( "option_$leaked", $probe );

		$this->assertTrue( $subject->request_v1( $request )['ok'] );

		remove_filter( "option_$leaked", $probe );
		$this->assertSame( 0, $reserved );
		$this->assertNull( $this->cold_option( $leaked ) );

		delete_option( $key );
		delete_option( 'mainwp_child_virusdie_receipt_index' );
	}

	/**
	 * Every key this class writes is its prefix followed by a sha256 of the request reference, so a
	 * key that only shares the prefix names a row this class never wrote. The index stops tracking
	 * it and nothing is deleted, because dropping an entry is bookkeeping and deleting a foreign row
	 * is not.
	 */
	public function test_an_indexed_key_that_is_not_a_receipt_key_is_dropped_rather_than_deleted() {
		$subject                   = new Testable_MainWP_Child_Virusdie();
		$subject->durable_receipts = true;
		$subject->gateway_bytes    = '<?php // signed fixture';
		$request                   = $this->install_request( $subject->gateway_bytes );
		$key                       = 'mainwp_child_virusdie_v1_' . hash( 'sha256', $request['payload']['request_ref'] );
		$foreign                   = 'mainwp_child_virusdie_v1_retention_settings';
		$stored                    = $this->expired_receipt( '123e4567-e89b-42d3-a456-426614175024' );
		$this->assertTrue( add_option( $foreign, $stored, '', false ) );
		update_option( 'mainwp_child_virusdie_receipt_index', array( array( 'key' => $foreign, 'expires_at' => time() - 86400 ) ), false );

		$this->assertTrue( $subject->request_v1( $request )['ok'] );

		$this->assertSame( $stored, $this->cold_option( $foreign ) );
		$this->assertSame( array( $key ), wp_list_pluck( get_option( 'mainwp_child_virusdie_receipt_index', array() ), 'key' ) );

		delete_option( $key );
		delete_option( $foreign );
		delete_option( 'mainwp_child_virusdie_receipt_index' );
	}

	/**
	 * Every option this index reads and writes goes through filters any plugin on the site can hook,
	 * so a failure in one arrives as a throw rather than a false. add() runs after the durable
	 * dispatch marker exists, so a throw that escapes it kills the request holding a reservation for
	 * an effect that already ran, and every later request under that reference reads the marker.
	 */
	public function test_a_throwing_index_write_does_not_fail_the_mutation_it_bookkeeps() {
		$subject                   = new Testable_MainWP_Child_Virusdie();
		$subject->durable_receipts = true;
		$subject->gateway_bytes    = '<?php // signed fixture';
		$request                   = $this->install_request( $subject->gateway_bytes );
		$key                       = 'mainwp_child_virusdie_v1_' . hash( 'sha256', $request['payload']['request_ref'] );
		$raced                     = '123e4567-e89b-42d3-a456-426614175025';
		$leaked                    = 'mainwp_child_virusdie_v1_' . hash( 'sha256', $raced );
		$seeded                    = array( array( 'key' => $leaked, 'expires_at' => time() - 86400 ) );
		$this->assertTrue( add_option( $leaked, $this->expired_receipt( $raced ), '', false ) );
		update_option( 'mainwp_child_virusdie_receipt_index', $seeded, false );
		// Poisons both index writes at once: the sweep's rewrite before the reservation, and the
		// record that follows it.
		$poison = static function () {
			throw new \RuntimeException( 'hostile option filter' );
		};
		add_filter( 'pre_update_option_mainwp_child_virusdie_receipt_index', $poison );

		try {
			$result = $subject->request_v1( $request );

			$this->assertTrue( $result['ok'] );
			$this->assertSame( 'completed', $result['status'] );
			$this->assertSame( 1, $subject->installs );
			$this->assertSame( $result, $this->cold_option( $key )['result'] );
			// The sweep's delete was committed before its own rewrite threw, so it stands; this
			// request's row is untracked, which is the leak the index reduces rather than a
			// mutation refused over bookkeeping.
			$this->assertNull( $this->cold_option( $leaked ) );
			$this->assertSame( $seeded, $this->cold_option( 'mainwp_child_virusdie_receipt_index' ) );
		} finally {
			remove_filter( 'pre_update_option_mainwp_child_virusdie_receipt_index', $poison );
		}

		delete_option( $key );
		delete_option( 'mainwp_child_virusdie_receipt_index' );
	}

	/**
	 * Read one option past the request-local cache.
	 *
	 * update_option() primes that cache, so a row asserted straight after a write reads back from
	 * memory whether or not the store ever kept it.
	 */
	private function cold_option( $key ) {
		wp_cache_delete( $key, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		return get_option( $key, null );
	}

	public function test_callable_dispatch_map_registers_the_narrow_protocol() {
		$reflection = new ReflectionClass( MainWP_Child_Callable::class );
		$callable   = $reflection->newInstanceWithoutConstructor();
		$property   = $reflection->getProperty( 'callableFunctions' );
		$property->setAccessible( true );

		$this->assertSame( 'virusdie_sync_install_v1', $property->getValue( $callable )['virusdie_sync_install_v1'] );
		$this->assertTrue( method_exists( $callable, 'virusdie_sync_install_v1' ) );
	}

	public function test_the_staging_file_carries_a_php_suffix_in_the_web_root() {
		$token = 'stagingsuffixprobe';
		$pin   = static function () use ( $token ) {
			return $token;
		};
		add_filter( 'random_password', $pin );
		$probe         = new Virusdie_Staging_Probe();
		$extensionless = ABSPATH . '.mainwp-virusdie-' . $token;
		$suffixed      = $extensionless . '.php';
		$target        = ABSPATH . 'virusdie_staging_probe.php';

		try {
			// The staging file is unlinked before install_artifact() returns, so occupying a
			// candidate name and watching the exclusive create collide is the only way to see
			// which of the two names it actually opened.
			file_put_contents( $suffixed, 'occupied' );
			$this->assertFalse( $probe->stage( 'virusdie_staging_probe.php', '<?php // artifact' ) );
			unlink( $suffixed );

			file_put_contents( $extensionless, 'occupied' );
			$this->assertTrue( $probe->stage( 'virusdie_staging_probe.php', '<?php // artifact' ) );
			$this->assertSame( '<?php // artifact', file_get_contents( $target ) );
		} finally {
			remove_filter( 'random_password', $pin );
			foreach ( array( $extensionless, $suffixed, $target ) as $leftover ) {
				if ( file_exists( $leftover ) ) {
					unlink( $leftover );
				}
			}
		}
	}

	private function install_request( $bytes ) {
		return $this->request(
			'install',
			array(
				'request_ref'     => '123e4567-e89b-42d3-a456-426614175001',
				'basename'        => 'virusdie_fixture.php',
				'expected_bytes'  => strlen( $bytes ),
				'expected_sha256' => hash( 'sha256', $bytes ),
				'gateway_url'     => 'https://dashboard.example/mainwp-virusdie-artifact',
				'gateway_token'   => 'one-use-private-token',
				'site_generation' => str_repeat( 'a', 64 ),
				'expires_at'      => time() + 300,
			)
		);
	}

	private function expired_receipt( $ref ) {
		return array(
			'effect_hash'     => str_repeat( 'd', 64 ),
			'operation'       => 'install',
			'request_ref'     => $ref,
			'basename'        => 'virusdie_fixture.php',
			'expected_sha256' => str_repeat( 'e', 64 ),
			'site_generation' => str_repeat( 'a', 64 ),
			'state'           => 'settled',
			'result'          => array(
				'protocol'    => '1',
				'operation'   => 'install',
				'ok'          => false,
				'request_ref' => $ref,
				'status'      => 'failed',
				'installed'   => false,
				'bytes'       => null,
				'sha256'      => null,
				'code'        => 'digest_mismatch',
			),
			'updated_at'      => time() - 172800,
			'expires_at'      => time() - 86400,
		);
	}

	/** The row a request writes the moment it reserves a reference, before any effect is performed. */
	private function dispatching_receipt_row( $ref ) {
		return array(
			'effect_hash'     => str_repeat( 'd', 64 ),
			'operation'       => 'install',
			'request_ref'     => $ref,
			'basename'        => 'virusdie_fixture.php',
			'expected_sha256' => str_repeat( 'e', 64 ),
			'site_generation' => str_repeat( 'a', 64 ),
			'state'           => 'dispatching',
			'result'          => null,
			'updated_at'      => time(),
			'expires_at'      => time() + 86400,
		);
	}

	private function remove_request( $bytes ) {
		return $this->request(
			'remove',
			array(
				'request_ref'     => '123e4567-e89b-42d3-a456-426614175004',
				'basename'        => 'virusdie_fixture.php',
				'expected_sha256' => hash( 'sha256', $bytes ),
				'site_generation' => str_repeat( 'b', 64 ),
			)
		);
	}

	private function request( $operation, $payload ) {
		return array( 'protocol' => '1', 'operation' => $operation, 'payload' => $payload );
	}
}

class Testable_MainWP_Child_Virusdie extends MainWP_Child_Virusdie {

	public $target_exists = false;
	public $target_bytes = '';
	public $gateway_bytes = '';
	public $gateway_reads = 0;
	public $installs = 0;
	public $removals = 0;
	public $receipts = array();
	public $remove_fails = false;
	public $durable_receipts = false;
	public $break_snapshot_on_download = false;

	/** Target becomes unreadable once the gateway has been read. */
	private $snapshot_unreadable = false;

	protected function target_snapshot( $basename ) {
		unset( $basename );
		if ( $this->snapshot_unreadable ) {
			return false;
		}
		return array(
			'exists' => $this->target_exists,
			'bytes'  => $this->target_exists ? strlen( $this->target_bytes ) : null,
			'sha256' => $this->target_exists ? hash( 'sha256', $this->target_bytes ) : null,
		);
	}

	protected function gateway_allowed( $url ) {
		return 'https://dashboard.example/mainwp-virusdie-artifact' === $url;
	}

	protected function download_artifact( $url, $token, $expected_bytes ) {
		unset( $url, $token, $expected_bytes );
		++$this->gateway_reads;
		if ( $this->break_snapshot_on_download ) {
			$this->snapshot_unreadable = true;
		}
		return $this->gateway_bytes;
	}

	protected function install_artifact( $basename, $bytes ) {
		unset( $basename );
		++$this->installs;
		$this->target_exists = true;
		$this->target_bytes  = $bytes;
		return true;
	}

	protected function remove_artifact( $basename ) {
		unset( $basename );
		++$this->removals;
		if ( $this->remove_fails ) {
			return false;
		}
		$this->target_exists = false;
		$this->target_bytes  = '';
		return true;
	}

	protected function load_receipt( $request_ref ) {
		if ( $this->durable_receipts ) {
			return parent::load_receipt( $request_ref );
		}
		return isset( $this->receipts[ $request_ref ] ) ? $this->receipts[ $request_ref ] : null;
	}

	protected function create_receipt( $request_ref, $receipt ) {
		if ( $this->durable_receipts ) {
			return parent::create_receipt( $request_ref, $receipt );
		}
		if ( isset( $this->receipts[ $request_ref ] ) ) {
			return false;
		}
		$this->receipts[ $request_ref ] = $receipt;
		return true;
	}

	protected function settle_receipt( $request_ref, $expected, $receipt ) {
		if ( $this->durable_receipts ) {
			return parent::settle_receipt( $request_ref, $expected, $receipt );
		}
		if ( ! isset( $this->receipts[ $request_ref ] ) || $expected !== $this->receipts[ $request_ref ] ) {
			return false;
		}
		$this->receipts[ $request_ref ] = $receipt;
		return true;
	}

	protected function delete_receipt( $request_ref ) {
		if ( $this->durable_receipts ) {
			return parent::delete_receipt( $request_ref );
		}
		unset( $this->receipts[ $request_ref ] );
		return true;
	}

	public function seed_dispatching( $request ) {
		$this->receipts[ $request['payload']['request_ref'] ] = $this->dispatching_receipt_for_test( $request );
	}

	public function seed_durable_receipt( $request_ref, $receipt ) {
		return parent::create_receipt( $request_ref, $receipt );
	}
}

/** Reaches the real staging and publication path, which Testable_MainWP_Child_Virusdie replaces. */
class Virusdie_Staging_Probe extends MainWP_Child_Virusdie {

	public function stage( $basename, $bytes ) {
		return $this->install_artifact( $basename, $bytes );
	}
}
