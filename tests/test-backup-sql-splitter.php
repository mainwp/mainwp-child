<?php
/**
 * Class BackupSqlSplitterTest
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Backup_Sql_Splitter;

/**
 * Tests for the state-machine SQL statement splitter.
 *
 * The splitter consumes a dump in arbitrary-sized chunks and yields
 * complete statements, treating a semicolon as a terminator only when
 * it sits outside every parser context: single-quoted strings,
 * double-quoted strings, backtick identifiers, block comments, line
 * comments (both dash-dash and hash), and 0x hex literal runs. A naive
 * explode on semicolons breaks on multi-line INSERTs whose values
 * contain semicolons, which is exactly what these cases cover.
 */
class BackupSqlSplitterTest extends WP_UnitTestCase {

	/**
	 * Feed the whole input in one chunk and return statements plus tail.
	 *
	 * @param string $sql Input SQL.
	 *
	 * @return array { statements: string[], tail: string }
	 */
	protected function split_all( $sql ) {
		$splitter   = new MainWP_Backup_Sql_Splitter();
		$statements = $splitter->feed( $sql );
		return array(
			'statements' => $statements,
			'tail'       => $splitter->flush(),
		);
	}

	/**
	 * A simple terminated statement is yielded whole.
	 */
	public function test_simple_statement_terminated_correctly() {
		$result = $this->split_all( "CREATE TABLE `wp_a` (id INT);\n" );
		$this->assertCount( 1, $result['statements'] );
		$this->assertSame( 'CREATE TABLE `wp_a` (id INT)', trim( $result['statements'][0] ) );
		$this->assertSame( '', trim( $result['tail'] ) );
	}

	/**
	 * Two statements split at the unquoted semicolon.
	 */
	public function test_two_statements_split() {
		$result = $this->split_all( "INSERT INTO `wp_a` VALUES (1);INSERT INTO `wp_a` VALUES (2);" );
		$this->assertCount( 2, $result['statements'] );
	}

	/**
	 * A semicolon inside a single-quoted string is not a terminator.
	 */
	public function test_semicolon_inside_single_quoted_string_not_a_terminator() {
		$sql    = "INSERT INTO `wp_posts` VALUES (1, 'hello; world');";
		$result = $this->split_all( $sql );
		$this->assertCount( 1, $result['statements'] );
		$this->assertStringContainsString( 'hello; world', $result['statements'][0] );
	}

	/**
	 * A semicolon inside a double-quoted string is not a terminator.
	 */
	public function test_semicolon_inside_double_quoted_string_not_a_terminator() {
		$sql    = 'INSERT INTO `wp_posts` VALUES (1, "a;b");';
		$result = $this->split_all( $sql );
		$this->assertCount( 1, $result['statements'] );
	}

	/**
	 * A semicolon inside a backtick identifier is not a terminator.
	 */
	public function test_semicolon_inside_backtick_identifier_not_a_terminator() {
		$sql    = 'CREATE TABLE `weird;name` (id INT);';
		$result = $this->split_all( $sql );
		$this->assertCount( 1, $result['statements'] );
		$this->assertStringContainsString( 'weird;name', $result['statements'][0] );
	}

	/**
	 * A semicolon inside a block comment is not a terminator.
	 */
	public function test_semicolon_inside_block_comment_not_a_terminator() {
		$sql    = "CREATE TABLE /* not; here */ `wp_a` (id INT);";
		$result = $this->split_all( $sql );
		$this->assertCount( 1, $result['statements'] );
	}

	/**
	 * A semicolon after dash-dash on the same line is inside the comment.
	 */
	public function test_semicolon_inside_line_comment_dash_not_a_terminator() {
		$sql    = "CREATE TABLE `wp_a` -- trailing; comment\n(id INT);";
		$result = $this->split_all( $sql );
		$this->assertCount( 1, $result['statements'] );
	}

	/**
	 * A semicolon after a hash comment marker is inside the comment.
	 */
	public function test_semicolon_inside_hash_comment_not_a_terminator() {
		$sql    = "CREATE TABLE `wp_a` # nope; nope\n(id INT);";
		$result = $this->split_all( $sql );
		$this->assertCount( 1, $result['statements'] );
	}

	/**
	 * A backslash-escaped quote does not close the string.
	 */
	public function test_escaped_quote_inside_string_does_not_end_string() {
		$sql    = "INSERT INTO `wp_posts` VALUES (1, 'it\\'s; fine');";
		$result = $this->split_all( $sql );
		$this->assertCount( 1, $result['statements'] );
		$this->assertStringContainsString( "it\\'s; fine", $result['statements'][0] );
	}

	/**
	 * A doubled quote escapes itself and does not close the string.
	 */
	public function test_doubled_quote_inside_string_does_not_end_string() {
		$sql    = "INSERT INTO `wp_posts` VALUES (1, 'two''quotes; here');";
		$result = $this->split_all( $sql );
		$this->assertCount( 1, $result['statements'] );
	}

	/**
	 * A 0x hex literal run is consumed as a unit.
	 */
	public function test_hex_literal_consumed_as_unit() {
		$sql    = "INSERT INTO `wp_blobs` VALUES (1, 0xDEADBEEF00);INSERT INTO `wp_blobs` VALUES (2, 0xAB);";
		$result = $this->split_all( $sql );
		$this->assertCount( 2, $result['statements'] );
		$this->assertStringContainsString( '0xDEADBEEF00', $result['statements'][0] );
	}

	/**
	 * A multi-line INSERT with embedded semicolons in several values is
	 * one statement.
	 */
	public function test_multiline_insert_with_embedded_semicolons_is_single_statement() {
		$sql    = "INSERT INTO `wp_options` VALUES\n(1, 'a:1:{s:3:\"url\";s:7:\"x;y;z\";}'),\n(2, 'plain');";
		$result = $this->split_all( $sql );
		$this->assertCount( 1, $result['statements'] );
		$this->assertStringContainsString( 'x;y;z', $result['statements'][0] );
	}

	/**
	 * Feeding the same input split across many small chunks yields the
	 * same statements as one big feed, including a chunk boundary in the
	 * middle of a quoted string.
	 */
	public function test_feed_in_multiple_chunks_assembles_correctly() {
		$sql      = "INSERT INTO `wp_posts` VALUES (1, 'semi;colon');INSERT INTO `wp_posts` VALUES (2, 'plain');";
		$splitter = new MainWP_Backup_Sql_Splitter();
		$got      = array();
		foreach ( str_split( $sql, 7 ) as $chunk ) {
			$got = array_merge( $got, $splitter->feed( $chunk ) );
		}
		$this->assertCount( 2, $got );
		$this->assertStringContainsString( 'semi;colon', $got[0] );
		$this->assertSame( '', trim( $splitter->flush() ) );
	}

	/**
	 * flush() returns an unterminated tail.
	 */
	public function test_flush_returns_incomplete_tail() {
		$splitter   = new MainWP_Backup_Sql_Splitter();
		$statements = $splitter->feed( 'INSERT INTO `wp_a` VALUES (1' );
		$this->assertCount( 0, $statements );
		$this->assertSame( 'INSERT INTO `wp_a` VALUES (1', $splitter->flush() );
	}

	/**
	 * Empty input yields nothing.
	 */
	public function test_empty_input_returns_no_statements() {
		$result = $this->split_all( '' );
		$this->assertCount( 0, $result['statements'] );
		$this->assertSame( '', $result['tail'] );
	}

	/**
	 * Whitespace-only fragments between semicolons are not statements.
	 */
	public function test_blank_segments_skipped() {
		$result = $this->split_all( ";\n;  ;CREATE TABLE `wp_a` (id INT);" );
		$this->assertCount( 1, $result['statements'] );
	}

	/**
	 * Splitter state can be exported and restored so a replay can resume
	 * mid-statement after a process restart.
	 */
	public function test_state_export_and_restore_resumes_mid_statement() {
		$first  = new MainWP_Backup_Sql_Splitter();
		$got    = $first->feed( "INSERT INTO `wp_a` VALUES (1, 'se" );
		$this->assertCount( 0, $got );

		$second = new MainWP_Backup_Sql_Splitter();
		$second->restore_state( $first->export_state() );
		$got = $second->feed( "mi;colon');" );
		$this->assertCount( 1, $got );
		$this->assertStringContainsString( "semi;colon", $got[0] );
	}
}
