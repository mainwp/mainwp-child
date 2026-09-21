<?php
/**
 * Comments Child protocol-v2 tests.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Child_Comments;

/** Comments protocol-v2 contract tests. */
class Test_MainWP_Child_Comments_V2 extends WP_UnitTestCase {

	/** @var MainWP_Child_Comments */
	private $comments;

	/** @var int */
	private $post_id;

	/** Set up a fresh post and protocol handler. */
	public function setUp(): void {
		parent::setUp();
		$this->comments = new MainWP_Child_Comments();
		$this->post_id  = self::factory()->post->create( array( 'post_title' => 'Protocol Post' ) );
	}

	/** Exact reads are redacted and return a truthful absence record. */
	public function test_get_comment_is_exact_bounded_and_redacted() {
		$comment_id = $this->create_comment( '0', 'Private body' );
		$result     = $this->comments->comments_v2(
			'get_comment',
			array(
				'operation'  => 'get_comment',
				'comment_id' => $comment_id,
			)
		);

		$this->assertSame( array( 'contract_version', 'operation', 'found', 'comment' ), array_keys( $result ) );
		$this->assertTrue( $result['found'] );
		$this->assertSame( 'pending', $result['comment']['comment_status'] );
		$this->assertSame( 'Private body', $result['comment']['comment_content'] );
		$this->assertArrayNotHasKey( 'author_email', $result['comment'] );
		$this->assertArrayNotHasKey( 'author_ip', $result['comment'] );
		$this->assertArrayNotHasKey( 'author_url', $result['comment'] );

		$missing = $this->comments->comments_v2(
			'get_comment',
			array(
				'operation'  => 'get_comment',
				'comment_id' => PHP_INT_MAX,
			)
		);
		$this->assertFalse( $missing['found'] );
		$this->assertNull( $missing['comment'] );
	}

	/** Closed malformed inputs are rejected before state changes. */
	public function test_malformed_duplicate_and_transition_inputs_do_not_mutate() {
		$comment_id = $this->create_comment( '0' );
		$valid      = array(
			'operation' => 'moderate',
			'action'    => 'approve',
			'items'     => array(
				array(
					'comment_id'      => $comment_id,
					'expected_status' => 'pending',
				),
			),
		);
		$cases      = array(
			array_merge( $valid, array( 'extra' => true ) ),
			array(
				'operation' => 'moderate',
				'action'    => 'approve',
				'items'     => array( $valid['items'][0], $valid['items'][0] ),
			),
			array(
				'operation' => 'moderate',
				'action'    => 'approve',
				'items'     => array(
					array(
						'comment_id'      => '01',
						'expected_status' => 'pending',
					),
				),
			),
			array(
				'operation' => 'moderate',
				'action'    => 'restore',
				'items'     => $valid['items'],
			),
			array(
				'operation' => 'moderate',
				'action'    => 'approve',
				'items'     => array_fill( 0, 101, $valid['items'][0] ),
			),
		);
		foreach ( $cases as $case ) {
			$result = $this->comments->comments_v2( 'moderate', $case );
			$this->assertSame( 'invalid_request', $result['error_code'] );
			$this->assertSame( 'unapproved', wp_get_comment_status( $comment_id ) );
		}
		$mismatched              = $valid;
		$mismatched['operation'] = 'delete_permanently';
		$this->assertSame( 'invalid_request', $this->comments->comments_v2( 'moderate', $mismatched )['error_code'] );
		$source = file_get_contents( dirname( __DIR__ ) . '/class/class-mainwp-child-comments.php' );
		$this->assertStringContainsString( 'if ( $this->has_contract_selector() )', $source );
		$this->assertStringContainsString( "MainWP_Helper::write( \$this->v2_error( 'unknown' ) )", $source );
	}

	/** Every accepted reversible transition is applied and post-read verified. */
	public function test_all_reversible_transitions_are_verified() {
		$cases = array(
			array( 'approve', '0', 'pending', 'approved' ),
			array( 'unapprove', '1', 'approved', 'pending' ),
			array( 'spam', '1', 'approved', 'spam' ),
		);
		foreach ( $cases as $case ) {
			$comment_id = $this->create_comment( $case[1] );
			$result     = $this->moderate( $case[0], $comment_id, $case[2] );
			$this->assertSame( 1, $result['applied'], $case[0] );
			$this->assertSame( $case[3], $result['results'][0]['status_after'], $case[0] );
		}

		$unspam_id = $this->create_comment( '1' );
		wp_spam_comment( $unspam_id );
		$unspam = $this->moderate( 'unspam', $unspam_id, 'spam' );
		$this->assertSame( 'approved', $unspam['results'][0]['status_after'] );

		$trash_id = $this->create_comment( '0' );
		$trash    = $this->moderate( 'trash', $trash_id, 'pending' );
		$this->assertSame( 'trash', $trash['results'][0]['status_after'] );
		$restore = $this->moderate( 'restore', $trash_id, 'trash' );
		$this->assertSame( 'pending', $restore['results'][0]['status_after'] );
	}

	/** Batches sort by ID and preserve truthful mixed outcomes. */
	public function test_moderation_sorts_and_reports_conflict_and_absence() {
		$low    = $this->create_comment( '0' );
		$high   = $this->create_comment( '1' );
		$result = $this->comments->comments_v2(
			'moderate',
			array(
				'operation' => 'moderate',
				'action'    => 'approve',
				'items'     => array(
					array(
						'comment_id'      => PHP_INT_MAX,
						'expected_status' => 'pending',
					),
					array(
						'comment_id'      => $high,
						'expected_status' => 'pending',
					),
					array(
						'comment_id'      => $low,
						'expected_status' => 'pending',
					),
				),
			)
		);
		$this->assertSame( array( $low, $high, PHP_INT_MAX ), wp_list_pluck( $result['results'], 'comment_id' ) );
		$this->assertSame( array( 'applied', 'conflict', 'not_found' ), wp_list_pluck( $result['results'], 'outcome' ) );
		$this->assertSame( 1, $result['applied'] );
	}

	/** Preview performs no deletion; execution proves absence and mixed facts. */
	public function test_permanent_delete_preview_and_execution_are_truthful() {
		$trashed = $this->create_comment( '1' );
		wp_trash_comment( $trashed );
		$approved = $this->create_comment( '1' );
		$items    = array(
			array(
				'comment_id'      => $approved,
				'expected_status' => 'trash',
			),
			array(
				'comment_id'      => $trashed,
				'expected_status' => 'trash',
			),
			array(
				'comment_id'      => PHP_INT_MAX,
				'expected_status' => 'trash',
			),
		);

		$preview = $this->comments->comments_v2(
			'delete_permanently',
			array(
				'operation' => 'delete_permanently',
				'dry_run'   => true,
				'items'     => $items,
			)
		);
		$this->assertSame( 1, $preview['eligible'] );
		$this->assertSame( 0, $preview['deleted'] );
		$this->assertNotNull( get_comment( $trashed ) );
		$this->assertSame( array( 'would_delete', 'conflict', 'not_found' ), wp_list_pluck( $preview['results'], 'outcome' ) );

		$execute = $this->comments->comments_v2(
			'delete_permanently',
			array(
				'operation' => 'delete_permanently',
				'dry_run'   => false,
				'items'     => $items,
			)
		);
		$this->assertSame( 1, $execute['eligible'] );
		$this->assertSame( 1, $execute['deleted'] );
		$this->assertNull( get_comment( $trashed ) );
		$this->assertFalse( $execute['results'][0]['exists_after'] );
	}

	/** A status change landing between the precondition and the write is refused, not overwritten. */
	public function test_moderation_refuses_a_status_changed_at_the_write_boundary() {
		$comment_id = $this->create_comment( '0' );
		$interrupt  = $this->change_status_at_first_write( $comment_id, 'spam' );

		$result = $this->moderate( 'approve', $comment_id, 'pending' );
		remove_filter( 'query', $interrupt );

		$this->assertSame( 0, $result['applied'] );
		$this->assertSame( 'conflict', $result['results'][0]['outcome'] );
		$this->assertSame( 'status_conflict', $result['results'][0]['error_code'] );
		$this->assertSame( 'spam', wp_get_comment_status( $comment_id ) );
	}

	/** A comment restored between the precondition and the write is not permanently deleted. */
	public function test_permanent_delete_refuses_a_comment_restored_at_the_write_boundary() {
		$comment_id = $this->create_comment( '1' );
		wp_trash_comment( $comment_id );
		$interrupt = $this->change_status_at_first_write( $comment_id, '1' );

		$result = $this->comments->comments_v2(
			'delete_permanently',
			array(
				'operation' => 'delete_permanently',
				'dry_run'   => false,
				'items'     => array(
					array(
						'comment_id'      => $comment_id,
						'expected_status' => 'trash',
					),
				),
			)
		);
		remove_filter( 'query', $interrupt );

		$this->assertSame( 0, $result['deleted'] );
		$this->assertSame( 0, $result['eligible'] );
		$this->assertSame( 'conflict', $result['results'][0]['outcome'] );
		$this->assertTrue( $result['results'][0]['exists_after'] );
		$this->assertNotNull( get_comment( $comment_id ) );
		$this->assertSame( 'approved', wp_get_comment_status( $comment_id ) );
	}

	/**
	 * A status change landing inside the claim is refused, not overwritten by the transition.
	 *
	 * The claim guards the swap into the holding value, not the WordPress call it was taken for, and
	 * that call updates the row by ID alone. A competing moderator that lands after the claim would
	 * otherwise have its result overwritten and the Dashboard told the batch applied.
	 */
	public function test_moderation_refuses_a_status_changed_inside_the_claim() {
		$comment_id = $this->create_comment( '0' );
		$interrupt  = $this->change_status_inside_the_claim( $comment_id, 'spam' );

		$result = $this->moderate( 'approve', $comment_id, 'pending' );
		remove_action( 'added_comment_meta', $interrupt, 10 );

		$this->assertSame( 0, $result['applied'] );
		$this->assertSame( 'conflict', $result['results'][0]['outcome'] );
		$this->assertSame( 'status_conflict', $result['results'][0]['error_code'] );
		$this->assertSame( 'spam', $result['results'][0]['status_after'] );
		$this->assertSame( 'spam', wp_get_comment_status( $comment_id ), 'The competing change must survive the transition this request skipped.' );
		$this->assertSame( '', get_comment_meta( $comment_id, '_mainwp_v2_claim', true ) );
	}

	/** A comment restored inside the claim is not permanently deleted by the request that claimed it. */
	public function test_permanent_delete_refuses_a_comment_restored_inside_the_claim() {
		$comment_id = $this->create_comment( '1' );
		wp_trash_comment( $comment_id );
		$interrupt = $this->change_status_inside_the_claim( $comment_id, '1' );

		$result = $this->comments->comments_v2(
			'delete_permanently',
			array(
				'operation' => 'delete_permanently',
				'dry_run'   => false,
				'items'     => array(
					array(
						'comment_id'      => $comment_id,
						'expected_status' => 'trash',
					),
				),
			)
		);
		remove_action( 'added_comment_meta', $interrupt, 10 );

		$this->assertSame( 0, $result['deleted'] );
		$this->assertSame( 0, $result['eligible'] );
		$this->assertSame( 'conflict', $result['results'][0]['outcome'] );
		$this->assertSame( 'status_conflict', $result['results'][0]['error_code'] );
		$this->assertTrue( $result['results'][0]['exists_after'] );
		$this->assertNotNull( get_comment( $comment_id ), 'A comment another actor restored must not be destroyed by the claim it landed inside.' );
		$this->assertSame( 'approved', wp_get_comment_status( $comment_id ) );
	}

	/**
	 * A claim stranded by a fatal is put back, but not while its owner could still be running.
	 *
	 * The claim swaps the comment out of its real status, so a request that dies between the claim and
	 * the WordPress transition leaves the row holding a value wp-admin does not list: the comment is
	 * gone from the site owner's view. A later request has to put it back where it was.
	 */
	public function test_a_claim_stranded_by_a_fatal_is_recovered_to_its_prior_status() {
		$comment_id = $this->create_comment( '0' );
		$fatal      = $this->die_at_the_transition();

		try {
			$this->moderate( 'approve', $comment_id, 'pending' );
			$this->fail( 'The simulated fatal never reached the transition.' );
		} catch ( MainWP_Comments_V2_Simulated_Fatal $stopped ) {
			$this->assertSame( 'fatal-at-the-transition', $stopped->getMessage() );
		} finally {
			remove_filter( 'query', $fatal );
		}

		// The claim writes past the comment cache, so the next request reads the row, not the copy the
		// dead one left behind.
		clean_comment_cache( $comment_id );
		$this->assertSame( 'mainwp-claim', $this->raw_status( $comment_id ) );

		$live = $this->moderate( 'approve', $comment_id, 'pending' );
		$this->assertSame( 0, $live['applied'], 'A claim young enough to still be running must not be taken away.' );
		$this->assertSame( 'failed', $live['results'][0]['outcome'] );
		$this->assertSame( 'mainwp-claim', $this->raw_status( $comment_id ) );

		$this->age_claim( $comment_id, 301 );
		$recovered = $this->moderate( 'approve', $comment_id, 'pending' );

		$this->assertSame( 1, $recovered['applied'] );
		$this->assertSame( 'pending', $recovered['results'][0]['status_before'] );
		$this->assertSame( 'approved', $recovered['results'][0]['status_after'] );
		$this->assertSame( '1', $this->raw_status( $comment_id ) );
		$this->assertSame( '', get_comment_meta( $comment_id, '_mainwp_v2_claim', true ) );
	}

	/**
	 * End the request the way a fatal would: after the claim is taken, before the transition lands.
	 *
	 * The claim's compare-and-swap is the first UPDATE against the comments table; the second is the
	 * WordPress transition the claim was taken for, and that is the statement that never runs.
	 *
	 * @return callable
	 */
	private function die_at_the_transition() {
		global $wpdb;
		$writes    = 0;
		$interrupt = static function ( $query ) use ( &$writes, $wpdb ) {
			if ( 1 !== preg_match( '/^\s*UPDATE\s+\S*' . preg_quote( $wpdb->comments, '/' ) . '\b/i', $query ) ) {
				return $query;
			}
			++$writes;
			if ( 2 === $writes ) {
				throw new MainWP_Comments_V2_Simulated_Fatal( 'fatal-at-the-transition' );
			}
			return $query;
		};
		add_filter( 'query', $interrupt );
		return $interrupt;
	}

	/** Move a stored claim back in time, leaving whatever else it records alone. */
	private function age_claim( $comment_id, $seconds ) {
		$marker = get_comment_meta( $comment_id, '_mainwp_v2_claim', true );
		if ( is_string( $marker ) && 1 === preg_match( '/^(.+)\|([0-9]+)$/D', $marker, $parts ) ) {
			update_comment_meta( $comment_id, '_mainwp_v2_claim', $parts[1] . '|' . ( (int) $parts[2] - $seconds ) );
		}
	}

	/** @return string|null */
	private function raw_status( $comment_id ) {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( "SELECT comment_approved FROM {$wpdb->comments} WHERE comment_ID = %d", $comment_id ) );
	}

	/**
	 * Act as a competing moderator that lands the moment the protocol reaches its write boundary.
	 *
	 * The first statement that writes to the comment tables is the boundary in both the guarded and
	 * the unguarded implementation, so hooking `query` puts the competing change in exactly the gap
	 * between reading the precondition and acting on it.
	 *
	 * @param int    $comment_id Comment to change.
	 * @param string $approved   Raw comment_approved value the competitor writes.
	 * @return callable
	 */
	private function change_status_at_first_write( $comment_id, $approved ) {
		global $wpdb;
		$fired     = false;
		$interrupt = static function ( $query ) use ( &$fired, $comment_id, $approved, $wpdb ) {
			$touches_comments = false !== strpos( $query, $wpdb->comments ) || false !== strpos( $query, $wpdb->commentmeta );
			if ( $fired || ! $touches_comments || 1 === preg_match( '/^\s*SELECT/i', $query ) ) {
				return $query;
			}
			$fired = true;
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->comments} SET comment_approved = %s WHERE comment_ID = %d", $approved, $comment_id ) );
			clean_comment_cache( $comment_id );
			return $query;
		};
		add_filter( 'query', $interrupt );
		return $interrupt;
	}

	/**
	 * Act as a competing actor that lands inside the window the claim is held open.
	 *
	 * The claim's compare-and-swap is followed by the meta write that records it, so hooking the
	 * moment that marker is stored puts the competing change after the claim was taken and before
	 * anything the claim was taken for runs. That is the gap the swap itself does not cover.
	 *
	 * @param int    $comment_id Comment to change.
	 * @param string $approved   Raw comment_approved value the competitor writes.
	 * @return callable
	 */
	private function change_status_inside_the_claim( $comment_id, $approved ) {
		global $wpdb;
		$interrupt = static function ( $meta_id, $object_id, $meta_key ) use ( $comment_id, $approved, $wpdb ) {
			if ( '_mainwp_v2_claim' !== $meta_key || (int) $comment_id !== (int) $object_id ) {
				return;
			}
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->comments} SET comment_approved = %s WHERE comment_ID = %d", $approved, $comment_id ) );
			clean_comment_cache( $comment_id );
		};
		add_action( 'added_comment_meta', $interrupt, 10, 3 );
		return $interrupt;
	}

	/** @return int */
	private function create_comment( $approved, $content = 'Fixture comment' ) {
		return self::factory()->comment->create(
			array(
				'comment_post_ID'      => $this->post_id,
				'comment_approved'     => $approved,
				'comment_author'       => 'Fixture Author',
				'comment_author_email' => 'private@example.test',
				'comment_author_IP'    => '192.0.2.10',
				'comment_author_url'   => 'https://private.example.test/',
				'comment_content'      => $content,
			)
		);
	}

	/** @return array */
	private function moderate( $action, $comment_id, $expected_status ) {
		return $this->comments->comments_v2(
			'moderate',
			array(
				'operation' => 'moderate',
				'action'    => $action,
				'items'     => array(
					array(
						'comment_id'      => $comment_id,
						'expected_status' => $expected_status,
					),
				),
			)
		);
	}
}

/** Stand-in for a fatal that ends the request in the middle of a claimed mutation. */
class MainWP_Comments_V2_Simulated_Fatal extends Exception {}
