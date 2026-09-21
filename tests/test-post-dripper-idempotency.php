<?php
/**
 * Post Dripper Child protocol tests.
 *
 * @package MainWP_Child
 */

namespace MainWP\Child;

use WP_UnitTestCase;

class Test_Post_Dripper_Idempotency extends WP_UnitTestCase {

	private $actor;
	private $server_option;

	public function set_up(): void {
		parent::set_up();
		$this->server_option = get_option( 'mainwp_child_server', null );
		update_option( 'mainwp_child_server', 'https://dashboard.example.test/wp-admin/' );
		$this->actor = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->actor );
		delete_option( 'mainwp_child_post_dripper_operations_v2' );
	}

	public function tear_down(): void {
		delete_option( 'mainwp_child_post_dripper_operations_v2' );
		if ( null === $this->server_option ) {
			delete_option( 'mainwp_child_server' );
		} else {
			update_option( 'mainwp_child_server', $this->server_option );
		}
		parent::tear_down();
	}

	public function test_capability_callable_advertises_the_closed_delivery_contract() {
		$this->assertTrue( MainWP_Child_Callable::get_instance()->is_callable_function( 'post_dripper_capabilities_v2' ) );

		$result = $this->request( 'capabilities', array() );
		$this->assertSame( array( 'protocol', 'operation', 'ok', 'operations', 'mutation_supported' ), array_keys( $result ) );
		$this->assertSame( array( 'post_dripper_delivery_v2', 'post_dripper_status_v2' ), $result['operations'] );
		$this->assertTrue( $result['mutation_supported'] );
	}

	/**
	 * Post Dripper shares Post Plus's content normalizer, so it must admit the same maximal post.
	 * A CJK body at the 200 KB field limit doubles once JSON escapes every character, which the
	 * old 256 KB transport cap rejected before the handler ever saw it.
	 */
	public function test_transport_size_cap_admits_a_maximal_legal_delivery_envelope() {
		$envelope = wp_json_encode(
			array(
				'protocol'  => '2',
				'operation' => 'post_dripper_delivery_v2',
				'payload'   => $this->maximal_delivery_payload( '123e4567-e89b-42d3-a456-426614174590' ),
			)
		);

		$this->assertGreaterThan( 262144, strlen( $envelope ) );
		$this->assertLessThanOrEqual( $this->callable_request_cap( 'post_dripper_capabilities_v2' ), strlen( $envelope ) );
		$this->assertTrue( $this->request( 'post_dripper_delivery_v2', json_decode( $envelope, true )['payload'] )['ok'] );
	}

	public function test_create_replay_and_status_are_exactly_once() {
		$payload = $this->delivery_payload( '123e4567-e89b-42d3-a456-426614174511', 'Dripped title' );
		$result  = $this->request( 'post_dripper_delivery_v2', $payload );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'applied', $result['state'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/D', $result['remote_post_ref'] );
		$this->assertSame( $result, $this->request( 'post_dripper_delivery_v2', $payload ) );

		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'any',
				'meta_key'       => '_mainwp_child_content_operation_v2',
				'meta_value'     => $payload['operation_ref'],
				'posts_per_page' => 2,
			)
		);
		$this->assertCount( 1, $posts );

		$status = $this->request(
			'post_dripper_status_v2',
			array(
				'dashboard_ref' => $payload['dashboard_ref'],
				'operation_ref' => $payload['operation_ref'],
			)
		);
		$this->assertSame( 'post_dripper_status_v2', $status['operation'] );
		unset( $result['operation'], $status['operation'] );
		$this->assertSame( $result, $status );
	}

	public function test_update_requires_the_exact_current_revision_and_replays() {
		$create = $this->delivery_payload( '123e4567-e89b-42d3-a456-426614174512', 'Before update' );
		$first  = $this->request( 'post_dripper_delivery_v2', $create );
		$posts  = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'any',
				'meta_key'       => '_mainwp_child_content_operation_v2',
				'meta_value'     => $create['operation_ref'],
				'posts_per_page' => 1,
			)
		);

		$update                      = $this->delivery_payload( '123e4567-e89b-42d3-a456-426614174513', 'After update' );
		$update['mode']              = 'update';
		$update['target_post_id']    = $posts[0]->ID;
		$update['expected_revision'] = $first['post_revision'];
		$result                      = $this->request( 'post_dripper_delivery_v2', $update );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'After update', get_post( $posts[0]->ID )->post_title );
		$this->assertSame( $result, $this->request( 'post_dripper_delivery_v2', $update ) );

		$stale                      = $this->delivery_payload( '123e4567-e89b-42d3-a456-426614174514', 'Stale update' );
		$stale['mode']              = 'update';
		$stale['target_post_id']    = $posts[0]->ID;
		$stale['expected_revision'] = str_repeat( 'f', 64 );
		$this->assertSame( 'stale_revision', $this->request( 'post_dripper_delivery_v2', $stale )['code'] );

		$mismatch                       = $this->delivery_payload( '123e4567-e89b-42d3-a456-426614174525', 'Type mismatch' );
		$mismatch['mode']               = 'update';
		$mismatch['target_post_id']     = $posts[0]->ID;
		$mismatch['expected_revision']  = $result['post_revision'];
		$mismatch['post']['post_type']  = 'page';
		$mismatch['post']['categories'] = array();
		$mismatch['post']['tags']       = array();
		$mismatch['content_digest']      = hash( 'sha256', wp_json_encode( $mismatch['post'] ) );
		$this->assertSame( 'target_not_found', $this->request( 'post_dripper_delivery_v2', $mismatch )['code'] );
	}

	public function test_conflicts_malformed_expired_and_uuid_aliases_have_zero_effect() {
		$payload = $this->delivery_payload( '123e4567-e89b-42d3-a456-426614174515', 'Original' );
		$this->assertTrue( $this->request( 'post_dripper_delivery_v2', $payload )['ok'] );
		$payload['post']['title']  = 'Changed';
		$payload['content_digest'] = hash( 'sha256', wp_json_encode( $payload['post'] ) );
		$this->assertSame( 'request_conflict', $this->request( 'post_dripper_delivery_v2', $payload )['code'] );

		$expired               = $this->delivery_payload( '123e4567-e89b-42d3-a456-426614174516', 'Expired' );
		$expired['expires_at'] = time() - 120;
		$this->assertSame( 'expired_request', $this->request( 'post_dripper_delivery_v2', $expired )['code'] );

		$alias               = $this->delivery_payload( '123e4567-e89b-42d3-a456-426614174517', 'Alias' );
		$alias['request_id'] = $alias['operation_ref'];
		$this->assertSame( 'invalid_request', $this->request( 'post_dripper_delivery_v2', $alias )['code'] );

		$wrong_dashboard                  = $this->delivery_payload( '123e4567-e89b-42d3-a456-426614174518', 'Wrong Dashboard' );
		$wrong_dashboard['dashboard_ref'] = str_repeat( 'f', 64 );
		$this->assertSame( 'invalid_request', $this->request( 'post_dripper_delivery_v2', $wrong_dashboard )['code'] );

		$invalid_operation = $this->request( "bad\noperation", array() );
		$this->assertSame( 'unknown', $invalid_operation['operation'] );
		$this->assertSame( 'invalid_request', $invalid_operation['code'] );
	}

	public function test_status_repairs_response_loss_without_duplicate_insert() {
		$payload = $this->delivery_payload( '123e4567-e89b-42d3-a456-426614174519', 'Repair response loss' );
		$created = $this->request( 'post_dripper_delivery_v2', $payload );
		$this->force_reserved( $payload['operation_ref'] );

		$status = $this->request( 'post_dripper_status_v2', $this->identity( $payload ) );
		$this->assertTrue( $status['ok'] );
		$this->assertSame( 'applied', $status['state'] );
		$this->assertSame( $created['post_revision'], $status['post_revision'] );
		$this->assertSame( $created['remote_post_ref'], $status['remote_post_ref'] );
		$this->assertCount( 1, $this->operation_posts( $payload['operation_ref'] ) );
	}

	public function test_not_applied_status_allows_one_safe_same_effect_retry() {
		$payload = $this->delivery_payload( '123e4567-e89b-42d3-a456-426614174520', 'Retry after proof' );
		$this->request( 'post_dripper_delivery_v2', $payload );
		$posts = $this->operation_posts( $payload['operation_ref'] );
		wp_delete_post( $posts[0]->ID, true );
		$this->force_reserved( $payload['operation_ref'] );

		$status = $this->request( 'post_dripper_status_v2', $this->identity( $payload ) );
		$this->assertSame( 'not_applied', $status['state'] );
		$this->assertTrue( $status['retryable'] );

		$retried = $this->request( 'post_dripper_delivery_v2', $payload );
		$this->assertSame( 'applied', $retried['state'] );
		$this->assertCount( 1, $this->operation_posts( $payload['operation_ref'] ) );
	}

	public function test_duplicate_repair_is_unknown_and_never_republishes() {
		$payload = $this->delivery_payload( '123e4567-e89b-42d3-a456-426614174521', 'Duplicate repair' );
		$this->request( 'post_dripper_delivery_v2', $payload );
		$duplicate_id = wp_insert_post(
			array(
				'post_title'  => 'Injected duplicate',
				'post_status' => 'publish',
				'meta_input'  => array( '_mainwp_child_content_operation_v2' => $payload['operation_ref'] ),
			)
		);
		$this->assertIsInt( $duplicate_id );
		$this->assertGreaterThan( 0, $duplicate_id );
		$this->assertCount( 2, $this->operation_posts( $payload['operation_ref'] ) );
		$this->force_reserved( $payload['operation_ref'] );

		$status = $this->request( 'post_dripper_status_v2', $this->identity( $payload ) );
		$this->assertSame( 'unknown', $status['state'] );
		$this->assertFalse( $status['retryable'] );
		$replay = $this->request( 'post_dripper_delivery_v2', $payload );
		unset( $status['operation'], $replay['operation'] );
		$this->assertSame( $status, $replay );
		$this->assertCount( 2, $this->operation_posts( $payload['operation_ref'] ) );
	}

	public function test_receipts_are_retained_for_ninety_days_then_pruned() {
		$old = $this->delivery_payload( '123e4567-e89b-42d3-a456-426614174522', 'Old receipt' );
		$this->request( 'post_dripper_delivery_v2', $old );
		$records                                      = get_option( 'mainwp_child_post_dripper_operations_v2' );
		$records[ $old['operation_ref'] ]['accepted_at'] = time() - 7776001;
		$records[ $old['operation_ref'] ]['updated_at']  = time() - 7776001;
		update_option( 'mainwp_child_post_dripper_operations_v2', $records );

		$fresh = $this->delivery_payload( '123e4567-e89b-42d3-a456-426614174523', 'Fresh receipt' );
		$this->assertTrue( $this->request( 'post_dripper_delivery_v2', $fresh )['ok'] );
		$stored = get_option( 'mainwp_child_post_dripper_operations_v2' );
		$this->assertArrayNotHasKey( $old['operation_ref'], $stored );
		$this->assertArrayHasKey( $fresh['operation_ref'], $stored );
	}

	public function test_shared_post_hooks_fire_once_with_legacy_shapes() {
		$before = array();
		$after  = array();
		$before_callback = static function ( $post, $custom, $categories, $tags, $others ) use ( &$before ) {
			$before[] = array( $post, $custom, $categories, $tags, $others );
		};
		$after_callback = static function ( $result ) use ( &$after ) {
			$after[] = $result;
		};
		add_action( 'mainwp_before_post_update', $before_callback, 10, 5 );
		add_action( 'mainwp_child_after_newpost', $after_callback );

		$payload = $this->delivery_payload( '123e4567-e89b-42d3-a456-426614174524', 'Hook compatibility' );
		$this->request( 'post_dripper_delivery_v2', $payload );
		$this->request( 'post_dripper_delivery_v2', $payload );

		remove_action( 'mainwp_before_post_update', $before_callback, 10 );
		remove_action( 'mainwp_child_after_newpost', $after_callback, 10 );
		$this->assertCount( 1, $before );
		$this->assertCount( 1, $after );
		$this->assertArrayNotHasKey( 'meta_input', $before[0][0] );
		$this->assertSame( array(), $before[0][1] );
		$this->assertSame( 'dripped', $before[0][2] );
		$this->assertSame( 'fixture', $before[0][3] );
		$this->assertSame( array(), $before[0][4] );
		$this->assertSame( array( 'success', 'link', 'added_id', 'new_post_data' ), array_keys( $after[0] ) );
		$this->assertTrue( $after[0]['success'] );
		$this->assertSame( array( 'post_id', 'post_type', 'post_title', 'post_date', 'post_date_gmt', 'new_status', 'old_status', 'singular_name', 'is_editing' ), array_keys( $after[0]['new_post_data'] ) );
	}

	private function delivery_payload( $operation_ref, $title ) {
		$post = array(
			'post_type'      => 'post',
			'status'         => 'publish',
			'title'          => $title,
			'content'        => 'Typed Post Dripper content.',
			'excerpt'        => 'Typed excerpt.',
			'slug'           => sanitize_title( $title ),
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
			'categories'     => array( 'dripped' ),
			'tags'           => array( 'fixture' ),
		);
		return array(
			'dashboard_ref'     => hash( 'sha256', 'https://dashboard.example.test' ),
			'operation_ref'     => $operation_ref,
			'mode'              => 'create',
			'target_post_id'    => null,
			'expected_revision' => null,
			'content_digest'    => hash( 'sha256', wp_json_encode( $post ) ),
			'expires_at'        => time() + 300,
			'post'              => $post,
		);
	}

	private function identity( $payload ) {
		return array(
			'dashboard_ref' => $payload['dashboard_ref'],
			'operation_ref' => $payload['operation_ref'],
		);
	}

	private function operation_posts( $operation_ref ) {
		return get_posts(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'any',
				'meta_key'       => '_mainwp_child_content_operation_v2',
				'meta_value'     => $operation_ref,
				'posts_per_page' => 2,
			)
		);
	}

	/**
	 * Read the request-size cap the callable transport enforces on the raw envelope.
	 *
	 * MainWP_Helper::write() ends the request with die(), so the dispatcher cannot run inside the
	 * suite; reading its own bound keeps the assertion tied to the shipped guard.
	 */
	private function callable_request_cap( $method ) {
		$reflection = new \ReflectionMethod( MainWP_Child_Callable::class, $method );
		$lines      = file( $reflection->getFileName() );
		$source     = implode( '', array_slice( $lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1 ) );
		$this->assertSame( 1, preg_match( '/([A-Za-z0-9_:]+)\s*<\s*strlen\(\s*\$raw\s*\)|strlen\(\s*\$raw\s*\)\s*>\s*([A-Za-z0-9_:]+)/', $source, $bound ) );
		$token = '' !== $bound[1] ? $bound[1] : $bound[2];
		if ( 0 === strpos( $token, 'self::' ) ) {
			return constant( MainWP_Child_Callable::class . '::' . substr( $token, 6 ) );
		}
		$this->assertTrue( ctype_digit( $token ), 'The transport size bound must be a literal or a self:: constant.' );
		return (int) $token;
	}

	private function maximal_delivery_payload( $operation_ref ) {
		$slugs = array();
		for ( $index = 0; $index < 100; $index++ ) {
			$slugs[] = str_pad( 'bound-' . $index . '-', 200, 'x' );
		}
		sort( $slugs, SORT_STRING );
		$post = array(
			'post_type'      => 'post',
			'status'         => 'draft',
			'title'          => str_repeat( '記', 170 ),
			'content'        => str_repeat( '記', 66666 ),
			'excerpt'        => str_repeat( '記', 1666 ),
			'slug'           => str_pad( 'maximal-', 200, 'x' ),
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
			'categories'     => $slugs,
			'tags'           => $slugs,
		);
		return array(
			'dashboard_ref'     => hash( 'sha256', 'https://dashboard.example.test' ),
			'operation_ref'     => $operation_ref,
			'mode'              => 'create',
			'target_post_id'    => null,
			'expected_revision' => null,
			'content_digest'    => hash( 'sha256', wp_json_encode( $post ) ),
			'expires_at'        => time() + 300,
			'post'              => $post,
		);
	}

	private function force_reserved( $operation_ref ) {
		$records                                       = get_option( 'mainwp_child_post_dripper_operations_v2' );
		$records[ $operation_ref ]['post_id']          = null;
		$records[ $operation_ref ]['state']            = 'reserved';
		$records[ $operation_ref ]['post_revision']    = null;
		$records[ $operation_ref ]['remote_post_ref']  = null;
		$records[ $operation_ref ]['retryable']        = false;
		$records[ $operation_ref ]['updated_at']       = time();
		update_option( 'mainwp_child_post_dripper_operations_v2', $records );
	}

	private function request( $operation, $payload ) {
		return MainWP_Child_Posts::get_instance()->post_dripper_capabilities_v2(
			array(
				'payload'   => $payload,
				'operation' => $operation,
				'protocol'  => '2',
			)
		);
	}
}
