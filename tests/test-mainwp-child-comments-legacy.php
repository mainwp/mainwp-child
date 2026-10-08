<?php
/**
 * Legacy Comments callable tests.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Child_Comments;
use MainWP\Child\MainWP_Child_Links_Checker;

/** Released Dashboard reply and moderation contracts. */
class Test_MainWP_Child_Comments_Legacy extends WP_UnitTestCase {

	/** @var MainWP_Child_Comments */
	private $comments;

	/** @var int */
	private $post_id;

	/** Set up a fresh post and callable handler. */
	public function setUp(): void {
		parent::setUp();
		$this->comments = new MainWP_Child_Comments();
		$this->post_id  = self::factory()->post->create( array( 'post_title' => 'Legacy Post' ) );
	}

	/** @dataProvider status_provider */
	public function test_single_status_matrix( $action, $before, $after ) {
		$comment_id = $this->create_comment( $before );
		$original   = $this->stored_comment( $comment_id );
		$expected   = false === $after ? $this->conflict_reply() : array( 'status' => 'SUCCESS' );

		$this->assertSame( $expected, $this->comments->comment_action_result( $action, $comment_id ) );
		$this->assert_stored_state( $comment_id, false === $after ? $before : $after );
		if ( false === $after ) {
			$this->assertSame( $original, $this->stored_comment( $comment_id ) );
		}
	}

	/** @dataProvider status_provider */
	public function test_bulk_status_matrix( $action, $before, $after ) {
		$comment_id = $this->create_comment( $before );
		$original   = $this->stored_comment( $comment_id );
		$expected   = false === $after ? array( 'success' => 0, 'failed' => array( $comment_id ) ) : array( 'success' => 1 );

		$this->assertSame( $expected, $this->comments->comment_bulk_action_result( $action, (string) $comment_id ) );
		$this->assert_stored_state( $comment_id, false === $after ? $before : $after );
		if ( false === $after ) {
			$this->assertSame( $original, $this->stored_comment( $comment_id ) );
		}
	}

	/** @return array */
	public static function status_provider() {
		$transitions = array(
			'approve'   => array( '0' => '1', '1' => false, 'spam' => '1', 'trash' => '1', 'post-trashed' => '1', 'mainwp-claim' => false ),
			'unapprove' => array( '0' => false, '1' => '0', 'spam' => '0', 'trash' => '0', 'post-trashed' => '0', 'mainwp-claim' => false ),
			'spam'      => array( '0' => 'spam', '1' => 'spam', 'spam' => false, 'trash' => 'spam', 'post-trashed' => 'spam', 'mainwp-claim' => false ),
			'unspam'    => array( '0' => false, '1' => false, 'spam' => '0', 'trash' => false, 'post-trashed' => false, 'mainwp-claim' => false ),
			'trash'     => array( '0' => 'trash', '1' => 'trash', 'spam' => 'trash', 'trash' => false, 'post-trashed' => 'trash', 'mainwp-claim' => false ),
			'restore'   => array( '0' => false, '1' => false, 'spam' => false, 'trash' => '0', 'post-trashed' => false, 'mainwp-claim' => false ),
			'delete'    => array( '0' => null, '1' => null, 'spam' => null, 'trash' => null, 'post-trashed' => null, 'mainwp-claim' => false ),
		);
		$cases = array();
		foreach ( $transitions as $action => $statuses ) {
			foreach ( $statuses as $before => $after ) {
				$cases[ $action . '-from-' . $before ] = array( $action, (string) $before, $after );
			}
		}
		return $cases;
	}

	/** @dataProvider missing_id_provider */
	public function test_single_missing_or_malformed_id_preserves_comment_12( $action, $comment_id, $before ) {
		$protected = $this->create_comment_12( $before );
		$original  = $this->stored_comment( $protected );

		$this->assertSame(
			array( 'error' => 'The comment was not found.', 'error_code' => 'comment_not_found' ),
			$this->comments->comment_action_result( $action, $comment_id )
		);
		$this->assertSame( $original, $this->stored_comment( 12 ) );
		$this->assert_stored_state( 12, $before );
		$this->assertNull( $this->stored_comment( PHP_INT_MAX ) );
	}

	/** @return array */
	public static function missing_id_provider() {
		$ids = array(
			'missing'      => PHP_INT_MAX,
			'abc'          => 'abc',
			'12abc'        => '12abc',
			'negative'     => '-3',
			'decimal'      => '1.5',
			'empty'        => '',
			'zero'         => '0',
			'leading-zero' => '012',
			'whitespace'   => ' 12 ',
			'overflow'     => (string) PHP_INT_MAX . '0',
		);
		$cases = array();
		foreach ( self::action_provider() as $action_case ) {
			foreach ( $ids as $label => $id ) {
				$cases[ $action_case[0] . '-' . $label ] = array( $action_case[0], $id, $action_case[1] );
			}
		}
		return $cases;
	}

	/** @dataProvider bulk_provider */
	public function test_bulk_input_matrix( $action, $before, $after, $wrong_status, $row ) {
		if ( 'malformed' === $row ) {
			$this->create_comment_12( $before );
			$original_12 = $this->stored_comment( 12 );
		}
		$a          = $this->create_comment( $before );
		$b          = $this->create_comment( 'wrong-status' === $row ? $wrong_status : $before );
		$original_a = $this->stored_comment( $a );
		$original_b = $this->stored_comment( $b );
		$rows = array(
			'all-apply'         => array( "$a,$b", array( 'success' => 2 ), true, true ),
			'missing-between'   => array( $a . ',' . PHP_INT_MAX . ',' . $b, array( 'success' => 2, 'not_found' => array( PHP_INT_MAX ) ), true, true ),
			'wrong-status'      => array( "$a,$b", array( 'success' => 1, 'failed' => array( $b ) ), true, false ),
			'malformed'         => array( "$a,abc,12abc", array( 'success' => 1, 'invalid' => 2 ), true, false ),
			'duplicate'         => array( "$a,$a", array( 'success' => 1 ), true, false ),
			'skipped'           => array( ",0,$a", array( 'success' => 1 ), true, false ),
			'all-missing'       => array( ( PHP_INT_MAX - 1 ) . ',' . PHP_INT_MAX, array( 'success' => 0, 'not_found' => array( PHP_INT_MAX - 1, PHP_INT_MAX ) ), false, false ),
			'unknown-action'    => array( "$a,abc," . PHP_INT_MAX . ',' . $b, array( 'success' => 0 ), false, false ),
			'trimmed'           => array( " \t$a , \t0 , , $b\t ", array( 'success' => 2 ), true, true ),
			'duplicate-missing' => array( $a . ',' . PHP_INT_MAX . ',' . PHP_INT_MAX, array( 'success' => 1, 'not_found' => array( PHP_INT_MAX ) ), true, false ),
			'invalid-forms'     => array( "$a,-3,1.5,012,+12," . PHP_INT_MAX . '0', array( 'success' => 1, 'invalid' => 5 ), true, false ),
		);
		$case = $rows[ $row ];

		$this->assertSame( $case[1], $this->comments->comment_bulk_action_result( 'unknown-action' === $row ? 'unknown-' . $action : $action, $case[0] ) );
		foreach ( array( array( $a, $case[2], $original_a ), array( $b, $case[3], $original_b ) ) as $state ) {
			$this->assert_stored_state( $state[0], $state[1] ? $after : $state[2]['comment_approved'] );
			if ( ! $state[1] ) {
				$this->assertSame( $state[2], $this->stored_comment( $state[0] ) );
			}
		}
		if ( 'malformed' === $row ) {
			$this->assertSame( $original_12, $this->stored_comment( 12 ) );
		}
		$this->assertNull( $this->stored_comment( PHP_INT_MAX ) );
		$this->assertNull( $this->stored_comment( PHP_INT_MAX - 1 ) );
	}

	/** @return array */
	public static function bulk_provider() {
		$cases = array();
		foreach ( self::action_provider() as $action_case ) {
			foreach ( array( 'all-apply', 'missing-between', 'wrong-status', 'malformed', 'duplicate', 'skipped', 'all-missing', 'unknown-action', 'trimmed', 'duplicate-missing', 'invalid-forms' ) as $row ) {
				if ( 'wrong-status' === $row && 'delete' === $action_case[0] ) {
					continue;
				}
				$cases[ $action_case[0] . '-' . $row ] = array_merge( $action_case, array( $row ) );
			}
		}
		return $cases;
	}

	/** @dataProvider unknown_action_provider */
	public function test_unknown_single_action_does_not_look_up_or_change_comments( $id ) {
		$this->create_comment_12( '1' );
		$original = $this->stored_comment( 12 );
		$queries  = array();
		$record   = static function ( $query ) use ( &$queries ) {
			$queries[] = $query;
			return $query;
		};
		add_filter( 'query', $record );
		try {
			$result = $this->comments->comment_action_result( 'unknown', $id );
		} finally {
			remove_filter( 'query', $record );
		}

		$this->assertSame( array( 'status' => 'FAIL' ), $result );
		$this->assertSame( array(), $queries );
		$this->assertSame( $original, $this->stored_comment( 12 ) );
	}

	/** @return array */
	public static function unknown_action_provider() {
		return array( array( 12 ), array( PHP_INT_MAX ), array( 'abc' ), array( '12abc' ), array( '-3' ), array( '1.5' ), array( '' ) );
	}

	/** @dataProvider mutation_failure_provider */
	public function test_mutation_requires_true_and_a_matching_stored_state( $action, $before, $bulk, $reported_success ) {
		global $wpdb;
		$comment_id = $this->create_comment( $before );
		$original   = $this->stored_comment( $comment_id );
		$interrupt  = static function ( $query ) use ( $wpdb, $reported_success ) {
			if ( preg_match( '/^\s*(?:UPDATE|DELETE\s+FROM)\s+`?' . preg_quote( $wpdb->comments, '/' ) . '`?\s/i', $query ) ) {
				return $reported_success ? 'SELECT 1' : 'SELECT 1 WHERE 1 = 0';
			}
			return $query;
		};
		add_filter( 'query', $interrupt );
		try {
			$result = $bulk ? $this->comments->comment_bulk_action_result( $action, (string) $comment_id ) : $this->comments->comment_action_result( $action, $comment_id );
		} finally {
			remove_filter( 'query', $interrupt );
		}

		$expected = $bulk ? array( 'success' => 0, 'failed' => array( $comment_id ) ) : array( 'error' => 'The comment action could not be completed.', 'error_code' => 'mutation_failed' );
		$this->assertSame( $expected, $result );
		$this->assertSame( $original, $this->stored_comment( $comment_id ) );
		$this->assert_stored_state( $comment_id, $before );
	}

	/** @return array */
	public static function mutation_failure_provider() {
		$cases = array();
		foreach ( self::action_provider() as $action_case ) {
			foreach ( array( false, true ) as $bulk ) {
				foreach ( array( false, true ) as $reported_success ) {
					$cases[ $action_case[0] . ( $bulk ? '-bulk' : '-single' ) . ( $reported_success ? '-post-read' : '-false' ) ] = array( $action_case[0], $action_case[1], $bulk, $reported_success );
				}
			}
		}
		return $cases;
	}

	/** Only the single trash callable registers the Links Checker hook. */
	public function test_trash_hook_and_v2_selector_are_preserved() {
		$hook = array( MainWP_Child_Links_Checker::get_class_name(), 'hook_trashed_comment' );
		remove_action( 'trashed_comment', $hook, 10 );
		try {
			$bulk_id = $this->create_comment( '1' );
			$this->assertSame( array( 'success' => 1 ), $this->comments->comment_bulk_action_result( 'trash', (string) $bulk_id ) );
			$this->assertFalse( has_action( 'trashed_comment', $hook ) );
			$this->assert_stored_state( $bulk_id, 'trash' );
			$single_id = $this->create_comment( '1' );
			$this->assertSame( array( 'status' => 'SUCCESS' ), $this->comments->comment_action_result( 'trash', $single_id ) );
			$this->assertSame( 10, has_action( 'trashed_comment', $hook ) );
			$this->assert_stored_state( $single_id, 'trash' );
		} finally {
			remove_action( 'trashed_comment', $hook, 10 );
		}

		$selector = <<<'PHP'
    public function comment_bulk_action() {
        if ( $this->has_contract_selector() ) {
            if ( $this->is_v2_request() ) {
                $this->comment_bulk_action_v2();
            } else {
                MainWP_Helper::write( $this->v2_error( 'unknown' ) );
            }
            return;
        }
PHP;
		$this->assertStringContainsString( $selector, file_get_contents( dirname( __DIR__ ) . '/class/class-mainwp-child-comments.php' ) );
	}

	/** A trashed comment marked as spam goes back to trash on unspam, and that is a success. */
	public function test_unspam_returns_a_comment_spammed_from_trash_to_trash() {
		$single = $this->create_comment( '1' );
		$bulk   = $this->create_comment( '1' );
		foreach ( array( $single, $bulk ) as $comment_id ) {
			$this->assertSame( array( 'status' => 'SUCCESS' ), $this->comments->comment_action_result( 'trash', $comment_id ) );
			$this->assertSame( array( 'status' => 'SUCCESS' ), $this->comments->comment_action_result( 'spam', $comment_id ) );
			$this->assert_stored_state( $comment_id, 'spam' );
		}

		$this->assertSame( array( 'status' => 'SUCCESS' ), $this->comments->comment_action_result( 'unspam', $single ) );
		$this->assert_stored_state( $single, 'trash' );
		$this->assertSame( array( 'success' => 1 ), $this->comments->comment_bulk_action_result( 'unspam', (string) $bulk ) );
		$this->assert_stored_state( $bulk, 'trash' );
	}

	/** @return array */
	public static function action_provider() {
		return array(
			array( 'approve', '0', '1', '1' ),
			array( 'unapprove', '1', '0', '0' ),
			array( 'spam', '1', 'spam', 'spam' ),
			array( 'unspam', 'spam', '0', '1' ),
			array( 'trash', '1', 'trash', 'trash' ),
			array( 'restore', 'trash', '0', '1' ),
			array( 'delete', '1', null, null ),
		);
	}

	/** A mainwp-claim fixture is a live v2 claim: inside the grace period, so it is not recovered. */
	private function create_comment( $approved ) {
		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $this->post_id,
				'comment_approved' => $approved,
				'comment_author'   => 'Fixture Author',
				'comment_content'  => 'Fixture comment',
			)
		);
		if ( 'mainwp-claim' === $approved ) {
			update_comment_meta( $comment_id, '_mainwp_v2_claim', '1|' . time() );
		}
		return $comment_id;
	}

	/** A fixed ID exposes WordPress coercing 12abc into comment 12. */
	private function create_comment_12( $approved ) {
		global $wpdb;
		$comment_id = $this->create_comment( $approved );
		if ( 12 !== $comment_id ) {
			$this->assertNull( $this->stored_comment( 12 ) );
			$this->assertSame( 1, $wpdb->update( $wpdb->comments, array( 'comment_ID' => 12 ), array( 'comment_ID' => $comment_id ) ) );
			clean_comment_cache( $comment_id );
			clean_comment_cache( 12 );
		}
		return 12;
	}

	/** @return array|null */
	private function stored_comment( $comment_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->comments} WHERE comment_ID = %d", $comment_id ), ARRAY_A );
	}

	/** Storage reads prove unchanged rows and permanent deletion without trusting the cache. */
	private function assert_stored_state( $comment_id, $approved ) {
		$stored = $this->stored_comment( $comment_id );
		if ( null === $approved ) {
			$this->assertNull( $stored, 'The comment must be gone from storage.' );
			return;
		}
		$this->assertNotNull( $stored );
		$this->assertSame( $approved, $stored['comment_approved'] );
	}

	/** @return array */
	private function conflict_reply() {
		return array( 'error' => 'The comment is not in a status this action applies to.', 'error_code' => 'status_conflict' );
	}
}
