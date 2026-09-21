<?php
/**
 * Post Plus Child protocol tests.
 *
 * @package MainWP_Child
 */

namespace MainWP\Child;

use WP_UnitTestCase;

class Test_Post_Plus_Child_V2 extends WP_UnitTestCase {

	private $actor;
	private $server_option;

	public function set_up(): void {
		parent::set_up();
		$this->server_option = get_option( 'mainwp_child_server', null );
		update_option( 'mainwp_child_server', 'https://dashboard.example.test/wp-admin/' );
		$this->actor = self::factory()->user->create( array( 'role' => 'administrator' ) );
		self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $this->actor );
		wp_insert_term( 'Post Plus Fixture', 'category', array( 'slug' => 'post-plus-fixture' ) );
		delete_option( 'mainwp_child_post_plus_operations_v2' );
	}

	public function tear_down(): void {
		delete_option( 'mainwp_child_post_plus_operations_v2' );
		if ( null === $this->server_option ) {
			delete_option( 'mainwp_child_server' );
		} else {
			update_option( 'mainwp_child_server', $this->server_option );
		}
		parent::tear_down();
	}

	public function test_capability_callable_advertises_create_status_and_readback() {
		$this->assertTrue( MainWP_Child_Callable::get_instance()->is_callable_function( 'post_plus_capabilities_v2' ) );
		$result = $this->request( 'capabilities', array() );
		$this->assertSame( array( 'post_plus_newpost_v2', 'post_plus_status_v2', 'post_plus_readback_v2' ), $result['operations'] );
		$this->assertTrue( $result['mutation_supported'] );
	}

	public function test_create_freezes_random_choices_and_replays_one_post() {
		$payload = $this->delivery_payload( '123e4567-e89b-42d3-a456-426614174611', 'Post Plus create' );
		$result  = $this->request( 'post_plus_newpost_v2', $payload );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'applied', $result['state'] );
		$this->assertSame( $result, $this->request( 'post_plus_newpost_v2', $payload ) );

		$posts = $this->operation_posts( $payload['operation_ref'] );
		$this->assertCount( 1, $posts );
		$this->assertSame( 'author', get_userdata( $posts[0]->post_author )->roles[0] );
		$this->assertNotEmpty( wp_get_post_categories( $posts[0]->ID ) );
	}

	public function test_status_and_readback_are_closed_and_revision_bound() {
		$payload = $this->delivery_payload( '123e4567-e89b-42d3-a456-426614174612', 'Post Plus status' );
		$created = $this->request( 'post_plus_newpost_v2', $payload );
		$status  = $this->request( 'post_plus_status_v2', $this->identity( $payload ) );
		$this->assertSame( 'post_plus_status_v2', $status['operation'] );
		unset( $created['operation'], $status['operation'] );
		$this->assertSame( $created, $status );

		$readback = $this->request( 'post_plus_readback_v2', $this->identity( $payload ) );
		$this->assertSame( array( 'protocol', 'operation', 'ok', 'state', 'operation_ref', 'content_digest', 'post_revision', 'remote_post_ref', 'retryable', 'updated_at', 'readback' ), array_keys( $readback ) );
		$this->assertSame( array( 'post_type', 'status', 'revision' ), array_keys( $readback['readback'] ) );
		$this->assertSame( 'post', $readback['readback']['post_type'] );
		$this->assertSame( $created['post_revision'], $readback['readback']['revision'] );
	}

	public function test_readback_source_mode_is_bounded_and_does_not_take_an_edit_lock() {
		$post_id = self::factory()->post->create(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'post_title'     => 'Clone source',
				'post_content'   => 'Bounded source content.',
				'post_excerpt'   => 'Source excerpt.',
				'post_name'      => 'clone-source',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			)
		);
		wp_set_post_terms( $post_id, array( 'post-plus-fixture' ), 'category' );
		$payload = array(
			'dashboard_ref'  => hash( 'sha256', 'https://dashboard.example.test' ),
			'source_post_id' => $post_id,
			'source_type'    => 'post',
		);
		$result = $this->request( 'post_plus_readback_v2', $payload );

		$this->assertSame( array( 'protocol', 'operation', 'complete', 'source_post_id', 'source_type', 'source_ref', 'title', 'content_digest', 'compatibility', 'private_context', 'source_revision' ), array_keys( $result ) );
		$this->assertTrue( $result['complete'] );
		$this->assertSame( 'supported', $result['compatibility'] );
		$this->assertSame( '', get_post_meta( $post_id, '_edit_lock', true ) );
		$private = json_decode( $result['private_context'], true );
		$this->assertSame( 'Bounded source content.', $private['post']['content'] );
		$this->assertSame( $result['content_digest'], hash( 'sha256', wp_json_encode( array( $private['post'], $private['randomization'] ) ) ) );
		$binding = $result;
		unset( $binding['source_revision'] );
		$this->assertSame( hash( 'sha256', wp_json_encode( $binding ) ), $result['source_revision'] );

		$payload['source_post_id'] = (string) $post_id;
		$this->assertSame( 'invalid_request', $this->request( 'post_plus_readback_v2', $payload )['code'] );
	}

	/**
	 * A source can trip several blockers at once and the response carries only one of them, hashed
	 * into source_revision. Reporting whichever meta row happened to be read last both mis-describes
	 * the post and makes an unrelated custom field look to the Dashboard like the source changed.
	 */
	public function test_compatibility_reports_the_strongest_blocker_not_the_last_meta_key() {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => 'Ranked blockers',
				'post_content' => 'Bounded source content.',
			)
		);
		add_post_meta( $post_id, '_thumbnail_id', self::factory()->post->create( array( 'post_type' => 'attachment' ) ) );
		add_post_meta( $post_id, 'fixture_ordinary_meta', 'plain value' );

		// get_post_meta() hands back meta_id order, so the ranking is only exercised when the
		// ordinary key is read after the featured image.
		$keys     = array_keys( get_post_meta( $post_id ) );
		$featured = array_search( '_thumbnail_id', $keys, true );
		$ordinary = array_search( 'fixture_ordinary_meta', $keys, true );
		// A missing key returns false, and false compares below any position, so the ordering
		// assertion would pass while the fixture had quietly stopped exercising the ranking.
		$this->assertIsInt( $featured );
		$this->assertIsInt( $ordinary );
		$this->assertGreaterThan( $featured, $ordinary );
		$this->assertSame( 'unsupported_media', $this->source_compatibility( $post_id ) );

		add_post_meta( $post_id, '_elementor_data', '[]' );
		$this->assertSame( 'unsupported_builder', $this->source_compatibility( $post_id ) );
	}

	private function source_compatibility( $post_id ) {
		$result = $this->request(
			'post_plus_readback_v2',
			array(
				'dashboard_ref'  => hash( 'sha256', 'https://dashboard.example.test' ),
				'source_post_id' => $post_id,
				'source_type'    => 'post',
			)
		);
		$this->assertTrue( $result['complete'] );
		return $result['compatibility'];
	}

	public function test_update_and_conflict_paths_do_not_duplicate_content() {
		$create = $this->delivery_payload( '123e4567-e89b-42d3-a456-426614174613', 'Before Plus update' );
		$first  = $this->request( 'post_plus_newpost_v2', $create );
		$posts  = $this->operation_posts( $create['operation_ref'] );

		$update                      = $this->delivery_payload( '123e4567-e89b-42d3-a456-426614174614', 'After Plus update' );
		$update['mode']              = 'update';
		$update['target_post_id']    = $posts[0]->ID;
		$update['expected_revision'] = $first['post_revision'];
		$result                      = $this->request( 'post_plus_newpost_v2', $update );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'After Plus update', get_post( $posts[0]->ID )->post_title );

		$update['post']['title']  = 'Conflicting replay';
		$update['content_digest'] = hash( 'sha256', wp_json_encode( array( $update['post'], $update['randomization'] ) ) );
		$this->assertSame( 'request_conflict', $this->request( 'post_plus_newpost_v2', $update )['code'] );
		$this->assertCount( 1, $this->operation_posts( $update['operation_ref'] ) );
	}

	public function test_random_category_appends_to_explicit_categories() {
		$explicit = wp_insert_term( 'Post Plus Explicit', 'category', array( 'slug' => 'post-plus-explicit' ) );
		$this->assertIsArray( $explicit );
		$payload = null;
		$chosen  = null;
		for ( $suffix = 618; $suffix < 638; $suffix++ ) {
			$candidate                        = $this->delivery_payload( '123e4567-e89b-42d3-a456-426614174' . $suffix, 'Append categories' );
			$candidate['post']['categories']  = array( 'post-plus-explicit' );
			$candidate['content_digest']      = hash( 'sha256', wp_json_encode( array( $candidate['post'], $candidate['randomization'] ) ) );
			$category_ids                     = get_categories( array( 'hide_empty' => false, 'number' => 1001, 'fields' => 'ids' ) );
			$category_ids                     = array_values( array_unique( array_map( 'intval', $category_ids ) ) );
			sort( $category_ids, SORT_NUMERIC );
			$seed                             = hash( 'sha256', $candidate['dashboard_ref'] . '|' . $candidate['operation_ref'] . '|' . $candidate['content_digest'] );
			$candidate_choice                 = $category_ids[ hexdec( substr( $seed, 8, 8 ) ) % count( $category_ids ) ];
			if ( (int) $explicit['term_id'] !== $candidate_choice ) {
				$payload = $candidate;
				$chosen  = $candidate_choice;
				break;
			}
		}
		$this->assertIsArray( $payload );
		$this->assertTrue( $this->request( 'post_plus_newpost_v2', $payload )['ok'] );
		$records = get_option( 'mainwp_child_post_plus_operations_v2' );
		$this->assertSame( $chosen, $records[ $payload['operation_ref'] ]['choices']['category_id'] );
		$posts      = $this->operation_posts( $payload['operation_ref'] );
		$categories = wp_get_post_categories( $posts[0]->ID );
		$this->assertContains( (int) $explicit['term_id'], $categories );
		$this->assertContains( $chosen, $categories );
	}

	public function test_malformed_randomization_and_uuid_id_alias_fail_closed() {
		$payload = $this->delivery_payload( '123e4567-e89b-42d3-a456-426614174615', 'Malformed' );
		$payload['randomization']['roles'][] = 'subscriber';
		$this->assertSame( 'invalid_request', $this->request( 'post_plus_newpost_v2', $payload )['code'] );

		$payload                 = $this->delivery_payload( '123e4567-e89b-42d3-a456-426614174616', 'Alias' );
		$payload['operation_id'] = $payload['operation_ref'];
		$this->assertSame( 'invalid_request', $this->request( 'post_plus_newpost_v2', $payload )['code'] );

		$page                                      = $this->delivery_payload( '123e4567-e89b-42d3-a456-426614174617', 'Page category' );
		$page['post']['post_type']                  = 'page';
		$page['randomization']['random_category']   = true;
		$page['content_digest']                     = hash( 'sha256', wp_json_encode( array( $page['post'], $page['randomization'] ) ) );
		$this->assertSame( 'invalid_request', $this->request( 'post_plus_newpost_v2', $page )['code'] );
	}

	public function test_rolled_back_mutation_is_purged_from_the_post_cache() {
		$payload                   = $this->delivery_payload( '123e4567-e89b-42d3-a456-426614174622', 'Rollback cache' );
		$payload['post']['tags']   = array( 'rollback-cache-fixture-tag' );
		$payload['content_digest'] = hash( 'sha256', wp_json_encode( array( $payload['post'], $payload['randomization'] ) ) );
		$this->assertEmpty( term_exists( 'rollback-cache-fixture-tag', 'post_tag' ) );

		$captured = 0;
		$capture  = static function ( $post_id ) use ( &$captured ) {
			if ( 0 === $captured ) {
				$captured = (int) $post_id;
			}
		};
		$refuse = static function () {
			return new \WP_Error( 'fixture_term_refused', 'Term creation refused.' );
		};
		add_action( 'wp_insert_post', $capture );
		add_filter( 'pre_insert_term', $refuse );
		$result = $this->request( 'post_plus_newpost_v2', $payload );
		remove_filter( 'pre_insert_term', $refuse );
		remove_action( 'wp_insert_post', $capture );

		$this->assertSame( 'mutation_failed', $result['code'] );
		$this->assertGreaterThan( 0, $captured );
		$this->assertFalse( wp_cache_get( $captured, 'posts' ) );
		$this->assertNull( get_post( $captured ) );
		$this->assertSame( '', get_post_meta( $captured, '_mainwp_child_content_operation_v2', true ) );
	}

	public function test_rolled_back_mutation_is_purged_from_the_term_cache() {
		$existing = term_exists( 'rollback-term-cache', 'category' );
		if ( is_array( $existing ) ) {
			$category_id = (int) $existing['term_id'];
		} else {
			$created = wp_insert_term( 'Rollback Term Cache', 'category', array( 'slug' => 'rollback-term-cache' ) );
			$this->assertIsArray( $created );
			$category_id = (int) $created['term_id'];
		}
		$this->assertSame( 0, (int) get_term( $category_id )->count );

		$payload                                     = $this->delivery_payload( '123e4567-e89b-42d3-a456-426614174625', 'Rollback terms' );
		$payload['post']['categories']               = array( 'rollback-term-cache' );
		$payload['post']['tags']                     = array( 'rollback-term-fixture-tag' );
		$payload['randomization']['random_category'] = false;
		$payload['content_digest']                   = hash( 'sha256', wp_json_encode( array( $payload['post'], $payload['randomization'] ) ) );

		// The category is attached first, which moves its count inside the transaction. Reading it
		// there is what a concurrent request does on a persistent object cache; refusing the tag
		// that follows is what makes the transaction roll the count back.
		$refuse = static function () use ( $category_id ) {
			get_term( $category_id );
			return new \WP_Error( 'fixture_term_refused', 'Term creation refused.' );
		};
		add_filter( 'pre_insert_term', $refuse );
		$result = $this->request( 'post_plus_newpost_v2', $payload );
		remove_filter( 'pre_insert_term', $refuse );

		$this->assertSame( 'mutation_failed', $result['code'] );
		$term = get_term( $category_id );
		$this->assertInstanceOf( \WP_Term::class, $term );
		$this->assertSame( 0, (int) $term->count, 'A rolled-back term count must not keep being served from cache.' );
	}

	/**
	 * A listener on mainwp_before_post_update that issues DDL trips MySQL's implicit commit, and the
	 * ROLLBACK a later failure runs then discards nothing. mutation_failed tells the Dashboard the
	 * site is untouched and is the answer it retries on, so a post that outlived its own transaction
	 * may only be reported as outcome_unknown.
	 */
	public function test_a_write_that_outlived_its_transaction_is_not_reported_as_mutation_failed() {
		global $wpdb;

		$payload                   = $this->delivery_payload( '123e4567-e89b-42d3-a456-426614174633', 'Closed transaction' );
		$payload['post']['tags']   = array( 'closed-transaction-fixture-tag' );
		$payload['content_digest'] = hash( 'sha256', wp_json_encode( array( $payload['post'], $payload['randomization'] ) ) );

		$captured = 0;
		$capture  = static function ( $post_id ) use ( &$captured ) {
			if ( 0 === $captured ) {
				$captured = (int) $post_id;
			}
		};
		// DDL is the real-world trigger, but the suite holds the connection at autocommit = 0, where
		// ending one transaction only opens another around the insert that follows - so the ROLLBACK
		// still discards it and the hazard never shows. Restoring autocommit commits the same
		// transaction and leaves the connection where an implicit commit leaves it on a live site.
		$close = static function () use ( $wpdb ) {
			$wpdb->query( 'SET autocommit = 1' );
		};
		$refuse = static function () {
			return new \WP_Error( 'fixture_term_refused', 'Term creation refused.' );
		};
		$result  = null;
		$durable = null;
		$stamp   = null;
		try {
			add_action( 'mainwp_before_post_update', $close );
			add_action( 'wp_insert_post', $capture );
			add_filter( 'pre_insert_term', $refuse );
			$result = $this->request( 'post_plus_newpost_v2', $payload );

			clean_post_cache( $captured );
			$durable = get_post( $captured );
			$stamp   = get_post_meta( $captured, '_mainwp_child_content_operation_v2', true );
			// The commit moved this row outside the transaction the suite rolls back, so it is only gone
			// if this test removes it - and it has to go before the first assertion that can fail.
			if ( $durable instanceof \WP_Post ) {
				wp_delete_post( $captured, true );
			}
		} finally {
			remove_filter( 'pre_insert_term', $refuse );
			remove_action( 'wp_insert_post', $capture );
			remove_action( 'mainwp_before_post_update', $close );
			// The delete above has to be durable too, so autocommit goes back only once it has run.
			// Everything this test writes from here is inside the suite's transaction again, which is
			// what stops one fixture from making the rest of the case durable for the next test.
			$wpdb->query( 'SET autocommit = 0' );
		}

		$this->assertGreaterThan( 0, $captured );
		$this->assertInstanceOf( \WP_Post::class, $durable, 'The fixture must leave a durable post or it is not exercising this hazard.' );
		$this->assertSame( $payload['operation_ref'], $stamp );
		$this->assertSame( 'outcome_unknown', $result['code'], 'mutation_failed asserts the site is untouched, and this post survived.' );
	}

	/**
	 * wp_insert_post() throws away what update_post_meta() returns, so the row can be written and
	 * made durable by the implicit commit while the operation stamp never lands. A verification that
	 * reads the stamp then sees a clean site and answers mutation_failed for a post that is still
	 * there. On a create the row itself is the trace, stamp or no stamp.
	 */
	public function test_a_durable_create_that_lost_its_stamp_is_not_reported_as_mutation_failed() {
		global $wpdb;

		$payload                   = $this->delivery_payload( '123e4567-e89b-42d3-a456-426614174640', 'Unstamped create' );
		$payload['post']['tags']   = array( 'unstamped-create-fixture-tag' );
		$payload['content_digest'] = hash( 'sha256', wp_json_encode( array( $payload['post'], $payload['randomization'] ) ) );

		$captured = 0;
		$capture  = static function ( $post_id ) use ( &$captured ) {
			if ( 0 === $captured ) {
				$captured = (int) $post_id;
			}
		};
		$close = static function () use ( $wpdb ) {
			$wpdb->query( 'SET autocommit = 1' );
		};
		$unstamp = static function ( $check, $object_id, $meta_key ) {
			return '_mainwp_child_content_operation_v2' === $meta_key ? false : $check;
		};
		$refuse = static function () {
			return new \WP_Error( 'fixture_term_refused', 'Term creation refused.' );
		};
		$result  = null;
		$durable = null;
		$stamp   = null;
		try {
			add_action( 'mainwp_before_post_update', $close );
			add_action( 'wp_insert_post', $capture );
			add_filter( 'update_post_metadata', $unstamp, 10, 3 );
			add_filter( 'pre_insert_term', $refuse );
			$result = $this->request( 'post_plus_newpost_v2', $payload );

			clean_post_cache( $captured );
			$durable = get_post( $captured );
			$stamp   = get_post_meta( $captured, '_mainwp_child_content_operation_v2', true );
			if ( $durable instanceof \WP_Post ) {
				wp_delete_post( $captured, true );
			}
		} finally {
			remove_filter( 'pre_insert_term', $refuse );
			remove_filter( 'update_post_metadata', $unstamp, 10 );
			remove_action( 'wp_insert_post', $capture );
			remove_action( 'mainwp_before_post_update', $close );
			$wpdb->query( 'SET autocommit = 0' );
		}

		$this->assertGreaterThan( 0, $captured );
		$this->assertInstanceOf( \WP_Post::class, $durable, 'The fixture must leave a durable post or it is not exercising this hazard.' );
		$this->assertSame( '', $stamp, 'The fixture must leave that post unstamped or it proves nothing about the stamp.' );
		$this->assertSame( 'outcome_unknown', $result['code'], 'mutation_failed asserts the site is untouched, and this post survived.' );
	}

	/**
	 * The same hole from the other side: an update target legitimately carries an earlier operation's
	 * stamp, so a content change that survived its transaction with no stamp of its own reads as no
	 * trace of this operation. What is stored in the post is the only thing that says whose write is
	 * in place.
	 */
	public function test_a_durable_update_that_lost_its_stamp_is_not_reported_as_mutation_failed() {
		global $wpdb;

		$create = $this->delivery_payload( '123e4567-e89b-42d3-a456-426614174641', 'Before durable update' );
		$first  = $this->request( 'post_plus_newpost_v2', $create );
		$this->assertTrue( $first['ok'] );
		$posts = $this->operation_posts( $create['operation_ref'] );
		$this->assertCount( 1, $posts );
		$target = (int) $posts[0]->ID;

		$update                      = $this->delivery_payload( '123e4567-e89b-42d3-a456-426614174642', 'After durable update' );
		$update['mode']              = 'update';
		$update['target_post_id']    = $target;
		$update['expected_revision'] = $first['post_revision'];
		$update['post']['tags']      = array( 'durable-update-fixture-tag' );
		$update['content_digest']    = hash( 'sha256', wp_json_encode( array( $update['post'], $update['randomization'] ) ) );

		$close = static function () use ( $wpdb ) {
			$wpdb->query( 'SET autocommit = 1' );
		};
		$unstamp = static function ( $check, $object_id, $meta_key ) {
			return '_mainwp_child_content_operation_v2' === $meta_key ? false : $check;
		};
		$refuse = static function () {
			return new \WP_Error( 'fixture_term_refused', 'Term creation refused.' );
		};
		$result  = null;
		$durable = null;
		$stamp   = null;
		try {
			add_action( 'mainwp_before_post_update', $close );
			add_filter( 'update_post_metadata', $unstamp, 10, 3 );
			add_filter( 'pre_insert_term', $refuse );
			$result = $this->request( 'post_plus_newpost_v2', $update );

			clean_post_cache( $target );
			$durable = get_post( $target );
			$stamp   = get_post_meta( $target, '_mainwp_child_content_operation_v2', true );
			if ( $durable instanceof \WP_Post ) {
				wp_delete_post( $target, true );
			}
		} finally {
			remove_filter( 'pre_insert_term', $refuse );
			remove_filter( 'update_post_metadata', $unstamp, 10 );
			remove_action( 'mainwp_before_post_update', $close );
			$wpdb->query( 'SET autocommit = 0' );
		}

		$this->assertInstanceOf( \WP_Post::class, $durable );
		$this->assertSame( 'After durable update', $durable->post_title, 'The fixture must leave the changed title durable or it is not exercising this hazard.' );
		$this->assertSame( $create['operation_ref'], $stamp, 'The target must still carry the earlier operation stamp or the stamp check was never fooled.' );
		$this->assertSame( 'outcome_unknown', $result['code'], 'mutation_failed asserts the site is untouched, and this change survived.' );
	}

	/**
	 * A valid update may carry nothing but a title, and wp_insert_post_data lets a site rewrite that
	 * title on the way into the row. Every value the operation meant to write is then absent from
	 * storage while the write itself is durable, so a verification that looks for the intended values
	 * sees a clean site and answers mutation_failed. Only the pre-operation row can say whether the
	 * rollback landed, because it is the one witness the write path cannot rewrite.
	 */
	public function test_a_durable_update_whose_title_a_filter_rewrote_is_not_reported_as_mutation_failed() {
		global $wpdb;

		$create = $this->delivery_payload( '123e4567-e89b-42d3-a456-426614174643', 'Before rewritten update' );
		$first  = $this->request( 'post_plus_newpost_v2', $create );
		$this->assertTrue( $first['ok'] );
		$posts = $this->operation_posts( $create['operation_ref'] );
		$this->assertCount( 1, $posts );
		$target = (int) $posts[0]->ID;

		// Title only: with content and excerpt empty, the title is the whole of what the payload
		// intends to store, so rewriting it leaves the intended-value comparison nothing to match.
		$update                      = $this->delivery_payload( '123e4567-e89b-42d3-a456-426614174644', 'Intended rewritten title' );
		$update['mode']              = 'update';
		$update['target_post_id']    = $target;
		$update['expected_revision'] = $first['post_revision'];
		$update['post']['content']   = '';
		$update['post']['excerpt']   = '';
		$update['post']['tags']      = array( 'rewritten-title-fixture-tag' );
		$update['content_digest']    = hash( 'sha256', wp_json_encode( array( $update['post'], $update['randomization'] ) ) );

		$close = static function () use ( $wpdb ) {
			$wpdb->query( 'SET autocommit = 1' );
		};
		$rewrite = static function ( $data ) {
			$data['post_title'] = 'Rewritten by a site filter';
			return $data;
		};
		$refuse = static function () {
			return new \WP_Error( 'fixture_term_refused', 'Term creation refused.' );
		};
		$result  = null;
		$durable = null;
		try {
			add_action( 'mainwp_before_post_update', $close );
			add_filter( 'wp_insert_post_data', $rewrite );
			add_filter( 'pre_insert_term', $refuse );
			$result = $this->request( 'post_plus_newpost_v2', $update );

			clean_post_cache( $target );
			$durable = get_post( $target );
			if ( $durable instanceof \WP_Post ) {
				wp_delete_post( $target, true );
			}
		} finally {
			remove_filter( 'pre_insert_term', $refuse );
			remove_filter( 'wp_insert_post_data', $rewrite );
			remove_action( 'mainwp_before_post_update', $close );
			$wpdb->query( 'SET autocommit = 0' );
		}

		$this->assertInstanceOf( \WP_Post::class, $durable );
		$this->assertSame( 'Rewritten by a site filter', $durable->post_title, 'The fixture must leave the rewritten title durable or it is not exercising this hazard.' );
		$this->assertNotSame( $update['post']['title'], $durable->post_title, 'The intended title must be absent from storage or the intended-value comparison was never fooled.' );
		$this->assertSame( 'outcome_unknown', $result['code'], 'mutation_failed asserts the site is untouched, and this change survived.' );
	}

	/**
	 * A Dashboard may resend an update whose fields already hold the values the row holds, and every
	 * post column then comes back equal to the pre-operation snapshot. The operation still writes its
	 * two meta values, so the implicit commit leaves this operation's stamp on a row the column
	 * comparison reads as untouched, and mutation_failed sends the Dashboard back to repeat a write
	 * that is already durable.
	 */
	public function test_a_durable_update_that_changed_no_column_is_caught_by_its_own_stamp() {
		global $wpdb;

		$create = $this->unchanged_update_payload( '123e4567-e89b-42d3-a456-426614174645', 'Unchanged durable update' );
		$first  = $this->request( 'post_plus_newpost_v2', $create );
		$this->assertTrue( $first['ok'] );
		$posts = $this->operation_posts( $create['operation_ref'] );
		$this->assertCount( 1, $posts );
		$target = (int) $posts[0]->ID;
		$before = get_post( $target );

		// Resending the stored values verbatim - down to the category the create fell back to and the
		// tag it attached - is what leaves the columns and both taxonomies equal. Only the second tag
		// is new, and wp_set_object_terms() gives up on it before it can move anything.
		$default                      = get_term( (int) get_option( 'default_category' ), 'category' );
		$fixture_tag                  = get_term_by( 'slug', 'fixture', 'post_tag' );
		$update                       = $this->unchanged_update_payload( '123e4567-e89b-42d3-a456-426614174646', 'Unchanged durable update' );
		$update['mode']               = 'update';
		$update['target_post_id']     = $target;
		$update['expected_revision']  = $first['post_revision'];
		$update['post']['categories'] = array( $default->slug );
		$update['post']['tags']       = array( 'fixture', 'unchanged-update-refused-tag' );
		$update['content_digest']     = hash( 'sha256', wp_json_encode( array( $update['post'], $update['randomization'] ) ) );

		$close = static function () use ( $wpdb ) {
			$wpdb->query( 'SET autocommit = 1' );
		};
		$pin    = $this->same_second_pin( $target, $before );
		$refuse = static function () {
			return new \WP_Error( 'fixture_term_refused', 'Term creation refused.' );
		};
		$result     = null;
		$durable    = null;
		$stamp      = null;
		$categories = array();
		$tags       = array();
		try {
			add_action( 'mainwp_before_post_update', $close );
			add_filter( 'wp_insert_post_data', $pin, 10, 2 );
			add_filter( 'pre_insert_term', $refuse );
			$result = $this->request( 'post_plus_newpost_v2', $update );

			clean_post_cache( $target );
			$durable    = get_post( $target );
			$stamp      = get_post_meta( $target, '_mainwp_child_content_operation_v2', true );
			$categories = array_map( 'intval', wp_get_object_terms( $target, 'category', array( 'fields' => 'ids' ) ) );
			$tags       = array_map( 'intval', wp_get_object_terms( $target, 'post_tag', array( 'fields' => 'ids' ) ) );
			if ( $durable instanceof \WP_Post ) {
				wp_delete_post( $target, true );
			}
		} finally {
			remove_filter( 'pre_insert_term', $refuse );
			remove_filter( 'wp_insert_post_data', $pin, 10 );
			remove_action( 'mainwp_before_post_update', $close );
			$wpdb->query( 'SET autocommit = 0' );
		}

		$this->assertInstanceOf( \WP_Post::class, $durable, 'The fixture must leave a durable post or it is not exercising this hazard.' );
		$this->assertSame( $this->post_columns( $before ), $this->post_columns( $durable ), 'The fixture must leave every post column equal or the column comparison was never fooled.' );
		$this->assertSame( array( (int) $default->term_id ), $categories, 'The categories must be unchanged or the term comparison, not the stamp, is what caught this.' );
		$this->assertSame( array( (int) $fixture_tag->term_id ), $tags, 'The tags must be unchanged or the term comparison, not the stamp, is what caught this.' );
		$this->assertSame( $update['operation_ref'], $stamp, 'The fixture must leave this operation stamp durable or it proves nothing about the stamp.' );
		$this->assertSame( 'outcome_unknown', $result['code'], 'mutation_failed asserts the site is untouched, and this operation stamp survived.' );
	}

	/**
	 * The same blind spot with the stamp write suppressed: the columns are equal and the row still
	 * carries the earlier operation's stamp, while the category this operation attached before the
	 * tag write failed is durable. The terms are the rest of what the operation writes, so they have
	 * to answer for themselves.
	 */
	public function test_a_durable_update_that_changed_no_column_is_caught_by_its_terms() {
		global $wpdb;

		$existing = term_exists( 'taxonomy-witness-category', 'category' );
		if ( is_array( $existing ) ) {
			$category_id = (int) $existing['term_id'];
		} else {
			$created = wp_insert_term( 'Taxonomy Witness', 'category', array( 'slug' => 'taxonomy-witness-category' ) );
			$this->assertIsArray( $created );
			$category_id = (int) $created['term_id'];
		}

		$create = $this->unchanged_update_payload( '123e4567-e89b-42d3-a456-426614174647', 'Unchanged durable terms' );
		$first  = $this->request( 'post_plus_newpost_v2', $create );
		$this->assertTrue( $first['ok'] );
		$posts = $this->operation_posts( $create['operation_ref'] );
		$this->assertCount( 1, $posts );
		$target = (int) $posts[0]->ID;
		$before = get_post( $target );

		// The category exists already, so the refusal below cannot reach it and that write lands; the
		// tag slug is new, so the refusal ends the operation on the very next write.
		$update                       = $this->unchanged_update_payload( '123e4567-e89b-42d3-a456-426614174648', 'Unchanged durable terms' );
		$update['mode']               = 'update';
		$update['target_post_id']     = $target;
		$update['expected_revision']  = $first['post_revision'];
		$update['post']['categories'] = array( 'taxonomy-witness-category' );
		$update['post']['tags']       = array( 'taxonomy-witness-refused-tag' );
		$update['content_digest']     = hash( 'sha256', wp_json_encode( array( $update['post'], $update['randomization'] ) ) );

		$close = static function () use ( $wpdb ) {
			$wpdb->query( 'SET autocommit = 1' );
		};
		$pin     = $this->same_second_pin( $target, $before );
		$unstamp = static function ( $check, $object_id, $meta_key ) {
			return '_mainwp_child_content_operation_v2' === $meta_key ? false : $check;
		};
		$refuse = static function () {
			return new \WP_Error( 'fixture_term_refused', 'Term creation refused.' );
		};
		$result     = null;
		$durable    = null;
		$stamp      = null;
		$categories = array();
		try {
			add_action( 'mainwp_before_post_update', $close );
			add_filter( 'wp_insert_post_data', $pin, 10, 2 );
			add_filter( 'update_post_metadata', $unstamp, 10, 3 );
			add_filter( 'pre_insert_term', $refuse );
			$result = $this->request( 'post_plus_newpost_v2', $update );

			clean_post_cache( $target );
			$durable    = get_post( $target );
			$stamp      = get_post_meta( $target, '_mainwp_child_content_operation_v2', true );
			$categories = array_map( 'intval', wp_get_object_terms( $target, 'category', array( 'fields' => 'ids' ) ) );
			if ( $durable instanceof \WP_Post ) {
				wp_delete_post( $target, true );
			}
		} finally {
			remove_filter( 'pre_insert_term', $refuse );
			remove_filter( 'update_post_metadata', $unstamp, 10 );
			remove_filter( 'wp_insert_post_data', $pin, 10 );
			remove_action( 'mainwp_before_post_update', $close );
			$wpdb->query( 'SET autocommit = 0' );
		}

		$this->assertInstanceOf( \WP_Post::class, $durable, 'The fixture must leave a durable post or it is not exercising this hazard.' );
		$this->assertSame( $this->post_columns( $before ), $this->post_columns( $durable ), 'The fixture must leave every post column equal or the column comparison was never fooled.' );
		$this->assertSame( $create['operation_ref'], $stamp, 'The target must still carry the earlier operation stamp or the stamp check, not the terms, is what caught this.' );
		$this->assertSame( array( $category_id ), $categories, 'The fixture must leave the attached category durable or it is not exercising this hazard.' );
		$this->assertSame( 'outcome_unknown', $result['code'], 'mutation_failed asserts the site is untouched, and this term write survived.' );
	}

	/**
	 * The operation writes two meta values in one meta_input, and wp_insert_post() ignores what each
	 * update_post_meta() returns, so the digest can land durably while the stamp write is refused.
	 * With the columns restored and the terms untouched, that durable digest is the only trace left
	 * and it has to be the thing that answers.
	 */
	public function test_a_durable_update_that_changed_no_column_is_caught_by_its_digest() {
		global $wpdb;

		$create = $this->unchanged_update_payload( '123e4567-e89b-42d3-a456-426614174649', 'Unchanged durable digest' );
		$first  = $this->request( 'post_plus_newpost_v2', $create );
		$this->assertTrue( $first['ok'] );
		$posts = $this->operation_posts( $create['operation_ref'] );
		$this->assertCount( 1, $posts );
		$target = (int) $posts[0]->ID;
		$before = get_post( $target );

		// Content the row does not hold is what makes the digest differ; the filter below puts the
		// stored column back so the digest is the only signal left standing. The taxonomies repeat
		// what the create left, and only the second tag is new, so wp_set_object_terms() gives up on
		// it before it can move anything.
		$default                      = get_term( (int) get_option( 'default_category' ), 'category' );
		$fixture_tag                  = get_term_by( 'slug', 'fixture', 'post_tag' );
		$update                       = $this->unchanged_update_payload( '123e4567-e89b-42d3-a456-426614174650', 'Unchanged durable digest' );
		$update['mode']               = 'update';
		$update['target_post_id']     = $target;
		$update['expected_revision']  = $first['post_revision'];
		$update['post']['content']    = 'Content that only the digest will remember.';
		$update['post']['categories'] = array( $default->slug );
		$update['post']['tags']       = array( 'fixture', 'unchanged-digest-refused-tag' );
		$update['content_digest']     = hash( 'sha256', wp_json_encode( array( $update['post'], $update['randomization'] ) ) );
		$this->assertNotSame( $create['content_digest'], $update['content_digest'] );

		$close   = static function () use ( $wpdb ) {
			$wpdb->query( 'SET autocommit = 1' );
		};
		$pin     = $this->same_second_pin( $target, $before );
		$restore = static function ( $data, $postarr ) use ( $target, $before, $pin ) {
			$data = $pin( $data, $postarr );
			if ( isset( $postarr['ID'] ) && $target === (int) $postarr['ID'] ) {
				$data['post_content'] = $before->post_content;
			}
			return $data;
		};
		$unstamp = static function ( $check, $object_id, $meta_key ) {
			return '_mainwp_child_content_operation_v2' === $meta_key ? false : $check;
		};
		$refuse  = static function () {
			return new \WP_Error( 'fixture_term_refused', 'Term creation refused.' );
		};
		$result     = null;
		$durable    = null;
		$stamp      = null;
		$digest     = null;
		$categories = array();
		$tags       = array();
		try {
			add_action( 'mainwp_before_post_update', $close );
			add_filter( 'wp_insert_post_data', $restore, 10, 2 );
			add_filter( 'update_post_metadata', $unstamp, 10, 3 );
			add_filter( 'pre_insert_term', $refuse );
			$result = $this->request( 'post_plus_newpost_v2', $update );

			clean_post_cache( $target );
			$durable    = get_post( $target );
			$stamp      = get_post_meta( $target, '_mainwp_child_content_operation_v2', true );
			$digest     = get_post_meta( $target, '_mainwp_child_content_digest_v2', true );
			$categories = array_map( 'intval', wp_get_object_terms( $target, 'category', array( 'fields' => 'ids' ) ) );
			$tags       = array_map( 'intval', wp_get_object_terms( $target, 'post_tag', array( 'fields' => 'ids' ) ) );
			if ( $durable instanceof \WP_Post ) {
				wp_delete_post( $target, true );
			}
		} finally {
			remove_filter( 'pre_insert_term', $refuse );
			remove_filter( 'update_post_metadata', $unstamp, 10 );
			remove_filter( 'wp_insert_post_data', $restore, 10 );
			remove_action( 'mainwp_before_post_update', $close );
			$wpdb->query( 'SET autocommit = 0' );
		}

		$this->assertInstanceOf( \WP_Post::class, $durable, 'The fixture must leave a durable post or it is not exercising this hazard.' );
		$this->assertSame( $this->post_columns( $before ), $this->post_columns( $durable ), 'The fixture must leave every post column equal or the column comparison, not the digest, is what caught this.' );
		$this->assertSame( $create['operation_ref'], $stamp, 'The target must still carry the earlier operation stamp or the stamp check, not the digest, is what caught this.' );
		$this->assertSame( array( (int) $default->term_id ), $categories, 'The categories must be unchanged or the term comparison, not the digest, is what caught this.' );
		$this->assertSame( array( (int) $fixture_tag->term_id ), $tags, 'The tags must be unchanged or the term comparison, not the digest, is what caught this.' );
		$this->assertSame( $update['content_digest'], $digest, 'The fixture must leave this operation digest durable or it proves nothing about the digest.' );
		$this->assertSame( 'outcome_unknown', $result['code'], 'mutation_failed asserts the site is untouched, and this digest write survived.' );
	}

	public function test_full_ledger_evicts_only_the_receipts_that_can_no_longer_be_replayed() {
		update_option( 'mainwp_child_post_plus_operations_v2', $this->aged_ledger( 'applied' ), false );
		$payload = $this->delivery_payload( '123e4567-e89b-42d3-a456-426614174623', 'Ledger eviction' );

		$result = $this->request( 'post_plus_newpost_v2', $payload );

		$this->assertTrue( $result['ok'] );
		$records = get_option( 'mainwp_child_post_plus_operations_v2' );
		$this->assertCount( 500, $records );
		$this->assertArrayHasKey( $payload['operation_ref'], $records );
		$this->assertArrayNotHasKey( '123e4567-e89b-42d3-a456-426500000000', $records );
		$this->assertArrayHasKey( '123e4567-e89b-42d3-a456-426500000001', $records );
	}

	public function test_full_ledger_of_unconfirmed_receipts_refuses_rather_than_evicting_one() {
		update_option( 'mainwp_child_post_plus_operations_v2', $this->aged_ledger( 'reserved' ), false );
		$payload = $this->delivery_payload( '123e4567-e89b-42d3-a456-426614174624', 'Ledger full' );

		$result = $this->request( 'post_plus_newpost_v2', $payload );

		$this->assertSame( 'storage_unavailable', $result['code'] );
		$records = get_option( 'mainwp_child_post_plus_operations_v2' );
		$this->assertCount( 500, $records );
		$this->assertArrayHasKey( '123e4567-e89b-42d3-a456-426500000000', $records );
	}

	/**
	 * One entry nothing can read any more must cost that entry, not the whole ledger. Failing the
	 * store instead makes every later mutation on the site answer storage_unavailable for good.
	 * The key here is not an operation reference, so no request can ever replay against it and
	 * dropping it cannot cost a replay defense.
	 */
	public function test_one_unreadable_ledger_entry_does_not_brick_the_store() {
		$applied  = array_slice( $this->aged_ledger( 'applied' ), 0, 3, true );
		$reserved = array_slice( $this->aged_ledger( 'reserved' ), 3, 1, true );
		$ledger   = $applied + $reserved;
		$ledger['not-an-operation-reference'] = array( 'operation_ref' => 'not a record' );
		update_option( 'mainwp_child_post_plus_operations_v2', $ledger, false );

		$payload = $this->delivery_payload( '123e4567-e89b-42d3-a456-426614174625', 'Salvaged ledger' );
		$result  = $this->request( 'post_plus_newpost_v2', $payload );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'applied', $result['state'] );

		$records = get_option( 'mainwp_child_post_plus_operations_v2' );
		$this->assertArrayNotHasKey( 'not-an-operation-reference', $records );
		$this->assertArrayHasKey( $payload['operation_ref'], $records );
		foreach ( array_keys( $applied + $reserved ) as $kept ) {
			$this->assertArrayHasKey( $kept, $records );
		}
		$this->assertSame( 'reserved', $records[ array_key_first( $reserved ) ]['state'] );
	}

	/**
	 * A damaged entry filed under a real operation reference is still the evidence that the
	 * operation ran. Drop it and the original request - still live, still retrying - reserves
	 * again and commits a second post carrying the same operation metadata.
	 */
	public function test_a_damaged_entry_for_a_committed_post_still_refuses_a_second_insert() {
		$payload = $this->delivery_payload( '123e4567-e89b-42d3-a456-426614174626', 'Damaged receipt' );
		$created = $this->request( 'post_plus_newpost_v2', $payload );
		$this->assertSame( 'applied', $created['state'] );
		$this->assertCount( 1, $this->operation_posts( $payload['operation_ref'] ) );

		$records = get_option( 'mainwp_child_post_plus_operations_v2' );
		$records[ $payload['operation_ref'] ]['post_revision'] = substr( $records[ $payload['operation_ref'] ]['post_revision'], 0, 32 );
		update_option( 'mainwp_child_post_plus_operations_v2', $records, false );

		$retry = $this->request( 'post_plus_newpost_v2', $payload );

		$this->assertCount( 1, $this->operation_posts( $payload['operation_ref'] ), 'A damaged entry must not let the original request commit the post a second time.' );
		$this->assertTrue( $retry['ok'] );
		$this->assertSame( 'unknown', $retry['state'] );
		$this->assertFalse( $retry['retryable'] );
		$this->assertNull( $retry['post_revision'] );
	}

	/**
	 * A ledger nothing can read is quarantined on every load, and a quarantine carries the time it
	 * was observed. Left in memory that time is rewritten on every request, so the entries stay
	 * permanently younger than the eviction horizon and the site refuses every mutation from then
	 * on. Persisting the repair is what freezes those timestamps so the ledger can age out.
	 */
	public function test_a_ledger_of_unreadable_entries_is_repaired_once_instead_of_restamped() {
		update_option( 'mainwp_child_post_plus_operations_v2', $this->damaged_ledger(), false );
		$payload = $this->delivery_payload( '123e4567-e89b-42d3-a456-426614174627', 'Damaged ledger' );

		$first = $this->request( 'post_plus_newpost_v2', $payload );

		$this->assertSame( 'storage_unavailable', $first['code'] );
		$persisted = get_option( 'mainwp_child_post_plus_operations_v2' );
		$this->assertCount( 500, $persisted );
		$entry = $persisted['123e4567-e89b-42d3-a456-426500000000'];
		$this->assertIsArray( $entry, 'Observed damage must be persisted, not rebuilt on every request.' );
		$this->assertSame( 'unknown', $entry['state'] );
		$this->assertFalse( $entry['retryable'] );

		$second = $this->request( 'post_plus_newpost_v2', $payload );

		$this->assertSame( 'storage_unavailable', $second['code'] );
		$stored = get_option( 'mainwp_child_post_plus_operations_v2' );
		$this->assertSame( $entry['accepted_at'], $stored['123e4567-e89b-42d3-a456-426500000000']['accepted_at'], 'A quarantine must keep the timestamp it was first given.' );
		$this->assertSame( $entry['updated_at'], $stored['123e4567-e89b-42d3-a456-426500000000']['updated_at'] );
		$this->assertSame( $persisted, $stored );
		$this->assertCount( 0, $this->operation_posts( $payload['operation_ref'] ) );
	}

	/**
	 * Refusing the mutation is correct while the quarantined entries can still be replayed against,
	 * but it has to stop being correct eventually: once the persisted quarantine is older than any
	 * request the site would still accept, the slot may be freed and mutations work again.
	 */
	public function test_a_persisted_quarantine_ages_out_and_the_store_accepts_mutations_again() {
		update_option( 'mainwp_child_post_plus_operations_v2', $this->damaged_ledger(), false );
		$refused = $this->request( 'post_plus_newpost_v2', $this->delivery_payload( '123e4567-e89b-42d3-a456-426614174628', 'Quarantine aged' ) );
		$this->assertSame( 'storage_unavailable', $refused['code'] );

		$records = get_option( 'mainwp_child_post_plus_operations_v2' );
		foreach ( $records as $operation_ref => $record ) {
			$this->assertIsArray( $record, 'The quarantine must be stored before it can age at all.' );
			$record['accepted_at']    -= 2 * DAY_IN_SECONDS;
			$record['updated_at']     -= 2 * DAY_IN_SECONDS;
			$records[ $operation_ref ] = $record;
		}
		update_option( 'mainwp_child_post_plus_operations_v2', $records, false );

		$payload = $this->delivery_payload( '123e4567-e89b-42d3-a456-426614174629', 'Recovered ledger' );
		$result  = $this->request( 'post_plus_newpost_v2', $payload );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'applied', $result['state'] );
		$stored = get_option( 'mainwp_child_post_plus_operations_v2' );
		$this->assertCount( 500, $stored );
		$this->assertArrayHasKey( $payload['operation_ref'], $stored );
	}

	/**
	 * An unpersisted repair is what puts the store back on the re-stamping loop, so a refused repair
	 * write has to end the request rather than let it reserve on top of a ledger that never changed.
	 */
	public function test_a_refused_repair_write_fails_the_request_instead_of_reserving_on_top_of_it() {
		$damaged = array_slice( $this->damaged_ledger(), 0, 3, true );
		update_option( 'mainwp_child_post_plus_operations_v2', $damaged, false );
		$attempts = array();
		$refuse   = static function ( $value, $old_value ) use ( &$attempts ) {
			$attempts[] = $value;
			return $old_value;
		};
		add_filter( 'pre_update_option_mainwp_child_post_plus_operations_v2', $refuse, 10, 2 );
		$payload = $this->delivery_payload( '123e4567-e89b-42d3-a456-426614174630', 'Refused repair' );
		$result  = $this->request( 'post_plus_newpost_v2', $payload );
		remove_filter( 'pre_update_option_mainwp_child_post_plus_operations_v2', $refuse, 10 );

		$this->assertSame( 'storage_unavailable', $result['code'] );
		$this->assertSame( $damaged, get_option( 'mainwp_child_post_plus_operations_v2' ) );
		$this->assertNotEmpty( $attempts );
		$this->assertArrayNotHasKey( $payload['operation_ref'], $attempts[0], 'The repair must be persisted before the request may reserve.' );
		$this->assertSame( 'unknown', $attempts[0]['123e4567-e89b-42d3-a456-426500000000']['state'] );
		$this->assertCount( 0, $this->operation_posts( $payload['operation_ref'] ) );
	}

	/**
	 * A damaged entry elsewhere in the ledger says nothing about the operation being retried. The
	 * outcome for that operation has already been read out of storage, so refusing to state it
	 * because an unrelated repair could not be written withholds a result the Dashboard is entitled
	 * to. Reserving is the write that still has to wait for the repair.
	 */
	public function test_a_refused_repair_write_still_replays_a_settled_receipt() {
		$payload = $this->delivery_payload( '123e4567-e89b-42d3-a456-426614174631', 'Replayed receipt' );
		$created = $this->request( 'post_plus_newpost_v2', $payload );
		$this->assertSame( 'applied', $created['state'] );

		$ledger = get_option( 'mainwp_child_post_plus_operations_v2' );
		$ledger['123e4567-e89b-42d3-a456-426500000000'] = 'damaged';
		update_option( 'mainwp_child_post_plus_operations_v2', $ledger, false );

		$attempts = array();
		$refuse   = static function ( $value, $old_value ) use ( &$attempts ) {
			$attempts[] = $value;
			return $old_value;
		};
		add_filter( 'pre_update_option_mainwp_child_post_plus_operations_v2', $refuse, 10, 2 );
		$replay = $this->request( 'post_plus_newpost_v2', $payload );
		$fresh  = $this->request( 'post_plus_newpost_v2', $this->delivery_payload( '123e4567-e89b-42d3-a456-426614174632', 'Refused reservation' ) );
		remove_filter( 'pre_update_option_mainwp_child_post_plus_operations_v2', $refuse, 10 );

		$this->assertSame( $created, $replay, 'A settled receipt read out of the ledger must still be the answer.' );
		$this->assertCount( 1, $this->operation_posts( $payload['operation_ref'] ) );
		$this->assertSame( 'storage_unavailable', $fresh['code'], 'A new reservation must wait for the repair to persist.' );
		$this->assertNotEmpty( $attempts );
		$this->assertSame( 'unknown', $attempts[0]['123e4567-e89b-42d3-a456-426500000000']['state'] );
		foreach ( $attempts as $attempt ) {
			$this->assertArrayNotHasKey( '123e4567-e89b-42d3-a456-426614174632', $attempt, 'A reservation must never be written on top of a ledger whose repair was refused.' );
		}
		$this->assertCount( 0, $this->operation_posts( '123e4567-e89b-42d3-a456-426614174632' ) );
		$this->assertSame( $ledger, get_option( 'mainwp_child_post_plus_operations_v2' ) );
	}

	/**
	 * Build a ledger at the cap whose receipts are older than any replayable request.
	 */
	private function aged_ledger( $state ) {
		$applied  = 'applied' === $state;
		$accepted = time() - ( 2 * DAY_IN_SECONDS );
		$records  = array();
		for ( $index = 0; $index < 500; $index++ ) {
			$operation_ref             = sprintf( '123e4567-e89b-42d3-a456-4265%08d', $index );
			$records[ $operation_ref ] = array(
				'dashboard_ref'     => hash( 'sha256', 'https://dashboard.example.test' ),
				'operation_ref'     => $operation_ref,
				'effect_hash'       => hash( 'sha256', 'effect-' . $index ),
				'content_digest'    => hash( 'sha256', 'digest-' . $index ),
				'mode'              => 'create',
				'target_post_id'    => null,
				'expected_revision' => null,
				'post_id'           => $applied ? $index + 1 : null,
				'state'             => $state,
				'post_revision'     => $applied ? hash( 'sha256', 'revision-' . $index ) : null,
				'remote_post_ref'   => $applied ? hash( 'sha256', 'remote-' . $index ) : null,
				'retryable'         => false,
				'choices'           => array(
					'author_id'     => $this->actor,
					'category_id'   => null,
					'post_date_gmt' => null,
				),
				'accepted_at'       => $accepted + $index,
				'updated_at'        => $accepted + $index,
			);
		}
		return $records;
	}

	/**
	 * Build a ledger at the cap whose entries are filed under real operation references but hold
	 * nothing readable, the shape a truncated or externally restored option leaves behind.
	 */
	private function damaged_ledger() {
		$records = array();
		for ( $index = 0; $index < 500; $index++ ) {
			$records[ sprintf( '123e4567-e89b-42d3-a456-4265%08d', $index ) ] = 'damaged-' . $index;
		}
		return $records;
	}

	public function test_transport_size_cap_admits_a_maximal_legal_create_envelope() {
		$envelope = wp_json_encode(
			array(
				'protocol'  => '2',
				'operation' => 'post_plus_newpost_v2',
				'payload'   => $this->maximal_delivery_payload( '123e4567-e89b-42d3-a456-426614174619' ),
			)
		);

		// A CJK body at the field limit doubles once JSON escapes it, which is what the old 256 KB
		// transport cap rejected; without this bound the test would still pass on an empty envelope.
		$this->assertGreaterThan( 262144, strlen( $envelope ) );
		$this->assertLessThanOrEqual( $this->callable_request_cap( 'post_plus_capabilities_v2' ), strlen( $envelope ) );
		$this->assertTrue( MainWP_Child_Posts::get_instance()->post_plus_capabilities_v2( json_decode( $envelope, true ) )['ok'] );
	}

	/**
	 * Read the request-size cap the callable transport enforces on the raw envelope.
	 *
	 * MainWP_Helper::write() ends the request with die(), so the dispatcher itself cannot run
	 * inside the suite; reading its own bound keeps the assertion tied to the shipped guard
	 * instead of a copy of the number.
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
		$randomization = array(
			'roles'           => array( 'author' ),
			'random_category' => false,
			'date_from'       => '2025-08-01',
			'date_to'         => '2025-08-31',
			'timezone'        => 'America/New_York',
		);
		return array(
			'dashboard_ref'     => hash( 'sha256', 'https://dashboard.example.test' ),
			'operation_ref'     => $operation_ref,
			'mode'              => 'create',
			'target_post_id'    => null,
			'expected_revision' => null,
			'content_digest'    => hash( 'sha256', wp_json_encode( array( $post, $randomization ) ) ),
			'expires_at'        => time() + 300,
			'post'              => $post,
			'randomization'     => $randomization,
		);
	}

	private function delivery_payload( $operation_ref, $title ) {
		$post = array(
			'post_type'      => 'post',
			'status'         => 'publish',
			'title'          => $title,
			'content'        => 'Typed Post Plus content.',
			'excerpt'        => 'Typed excerpt.',
			'slug'           => sanitize_title( $title ),
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
			'categories'     => array(),
			'tags'           => array( 'fixture' ),
		);
		$randomization = array(
			'roles'           => array( 'author' ),
			'random_category' => true,
			'date_from'       => '2025-08-01',
			'date_to'         => '2025-08-31',
			'timezone'        => 'America/New_York',
		);
		return array(
			'dashboard_ref'     => hash( 'sha256', 'https://dashboard.example.test' ),
			'operation_ref'     => $operation_ref,
			'mode'              => 'create',
			'target_post_id'    => null,
			'expected_revision' => null,
			'content_digest'    => hash( 'sha256', wp_json_encode( array( $post, $randomization ) ) ),
			'expires_at'        => time() + 300,
			'post'              => $post,
			'randomization'     => $randomization,
		);
	}

	/**
	 * Build a delivery payload whose choices are the same on every operation_ref.
	 *
	 * The frozen random choices are seeded from the operation_ref, so the stock payload gives a
	 * create and the update that follows it a different author and a different post date - which is
	 * a changed column, and a changed column is a trace the witness is already able to see. An empty
	 * role list pins the author to the current user and an absent date range leaves post_date alone,
	 * so the two operations agree on everything the row stores.
	 */
	private function unchanged_update_payload( $operation_ref, $title ) {
		$payload                  = $this->delivery_payload( $operation_ref, $title );
		$payload['randomization'] = array(
			'roles'           => array(),
			'random_category' => false,
			'date_from'       => null,
			'date_to'         => null,
			'timezone'        => 'UTC',
		);
		$payload['content_digest'] = hash( 'sha256', wp_json_encode( array( $payload['post'], $payload['randomization'] ) ) );
		return $payload;
	}

	/**
	 * Hold an update's four date columns at the values the row already carries.
	 *
	 * wp_insert_post() restamps post_date and post_modified from the clock on every update, so an
	 * update that stores no change still differs from its snapshot unless it lands inside the same
	 * second. That is a race, not a difference, and pinning the four columns is how the case gets
	 * tested without one.
	 */
	private function same_second_pin( $target, $before ) {
		return static function ( $data, $postarr ) use ( $target, $before ) {
			if ( isset( $postarr['ID'] ) && $target === (int) $postarr['ID'] ) {
				$data['post_date']         = $before->post_date;
				$data['post_date_gmt']     = $before->post_date_gmt;
				$data['post_modified']     = $before->post_modified;
				$data['post_modified_gmt'] = $before->post_modified_gmt;
			}
			return $data;
		};
	}

	/**
	 * Read every post column wp_insert_post() writes, so "no column changed" is an assertion.
	 */
	private function post_columns( $post ) {
		$columns = array(
			'post_author',
			'post_date',
			'post_date_gmt',
			'post_content',
			'post_content_filtered',
			'post_title',
			'post_excerpt',
			'post_status',
			'post_type',
			'comment_status',
			'ping_status',
			'post_password',
			'post_name',
			'to_ping',
			'pinged',
			'post_modified',
			'post_modified_gmt',
			'post_parent',
			'menu_order',
			'post_mime_type',
			'guid',
		);
		$values  = array();
		foreach ( $columns as $column ) {
			$values[ $column ] = (string) $post->$column;
		}
		return $values;
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
				'post_type'      => 'post',
				'post_status'    => 'any',
				'meta_key'       => '_mainwp_child_content_operation_v2',
				'meta_value'     => $operation_ref,
				'posts_per_page' => 2,
			)
		);
	}

	private function request( $operation, $payload ) {
		return MainWP_Child_Posts::get_instance()->post_plus_capabilities_v2(
			array(
				'payload'   => $payload,
				'operation' => $operation,
				'protocol'  => '2',
			)
		);
	}
}
