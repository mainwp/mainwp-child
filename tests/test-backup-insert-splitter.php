<?php
/**
 * Class BackupInsertSplitterTest
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Backup_Insert_Splitter;

/**
 * Tests for max_allowed_packet-aware INSERT splitting.
 *
 * The restore target may cap max_allowed_packet far below the ~1 MiB
 * batches the dumper writes. An oversized multi-row INSERT is split at
 * top-level ),( value-tuple boundaries into several smaller INSERTs;
 * tuple boundaries inside string literals or nested parentheses are
 * never split points.
 */
class BackupInsertSplitterTest extends WP_UnitTestCase {

	/**
	 * Splitter under test.
	 *
	 * @var MainWP_Backup_Insert_Splitter
	 */
	protected $splitter;

	/**
	 * Build the splitter.
	 */
	public function set_up() {
		parent::set_up();
		$this->splitter = new MainWP_Backup_Insert_Splitter();
	}

	/**
	 * A statement under the limit is returned unchanged as one element.
	 */
	public function test_insert_under_limit_not_split() {
		$sql = "INSERT INTO `t` VALUES (1,'a'),(2,'b')";
		$out = $this->splitter->split( $sql, 1024 );
		$this->assertSame( array( $sql ), $out );
	}

	/**
	 * An oversized INSERT splits at tuple boundaries and every part is a
	 * complete INSERT under the limit.
	 */
	public function test_insert_over_limit_split_at_tuple_boundary() {
		$tuples = array();
		for ( $i = 0; $i < 20; $i++ ) {
			$tuples[] = '(' . $i . ",'" . str_repeat( 'x', 50 ) . "')";
		}
		$sql   = 'INSERT INTO `t` VALUES ' . implode( ',', $tuples );
		$limit = 200;
		$out   = $this->splitter->split( $sql, $limit );

		$this->assertGreaterThan( 1, count( $out ) );
		foreach ( $out as $part ) {
			$this->assertLessThanOrEqual( $limit, strlen( $part ) );
			$this->assertMatchesRegularExpression( '/^INSERT INTO `t` VALUES \(/', $part );
			$this->assertStringEndsWith( ')', $part );
		}

		// Reassembling all tuples yields the original set.
		$reassembled = array();
		foreach ( $out as $part ) {
			$reassembled[] = substr( $part, strlen( 'INSERT INTO `t` VALUES ' ) );
		}
		$this->assertSame( implode( ',', $tuples ), implode( ',', $reassembled ) );
	}

	/**
	 * A ),( sequence inside a string literal is not a split point.
	 */
	public function test_tuple_boundary_inside_string_not_split() {
		$evil = "(1,'a),(b'),(2,'c')";
		$sql  = 'INSERT INTO `t` VALUES ' . $evil;
		$out  = $this->splitter->split( $sql, 40 );

		// Whatever the parts, none may cut inside the quoted ),( bytes.
		$joined = implode( "\n", $out );
		$this->assertStringContainsString( "'a),(b'", $joined );
	}

	/**
	 * Nested parentheses inside a tuple do not create false boundaries.
	 */
	public function test_tuple_boundary_detection_with_nested_parens() {
		$sql = "INSERT INTO `t` VALUES (1,POINT(1,2)),(2,POINT(3,4))";
		$out = $this->splitter->split( $sql, 45 );
		$this->assertCount( 2, $out );
		$this->assertStringContainsString( 'POINT(1,2)', $out[0] );
		$this->assertStringContainsString( 'POINT(3,4)', $out[1] );
	}

	/**
	 * A single tuple that alone exceeds the limit is passed through
	 * rather than corrupted.
	 */
	public function test_single_row_over_limit_returned_as_is() {
		$sql = "INSERT INTO `t` VALUES (1,'" . str_repeat( 'y', 500 ) . "')";
		$out = $this->splitter->split( $sql, 100 );
		$this->assertSame( array( $sql ), $out );
	}

	/**
	 * Non-INSERT statements pass through untouched regardless of size.
	 */
	public function test_non_insert_passed_through() {
		$sql = 'CREATE TABLE `t` (' . str_repeat( 'c INT,', 100 ) . 'id INT)';
		$out = $this->splitter->split( $sql, 50 );
		$this->assertSame( array( $sql ), $out );
	}
}
