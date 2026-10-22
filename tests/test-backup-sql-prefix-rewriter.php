<?php
/**
 * Class BackupSqlPrefixRewriterTest
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Backup_Sql_Prefix_Rewriter;

/**
 * Tests for the table-position-only prefix rewriter and its
 * preserve-from-live guard.
 *
 * The rewriter touches only the single identifier token in the table
 * position immediately following a known DDL/DML keyword. Values are
 * never rewritten - a serialized option_value containing the prefix as
 * string bytes must survive untouched. Statements naming tables outside
 * the source-prefixed set are dropped, as are statements that could
 * reach beyond the restore's own tables (USE, CREATE DATABASE, GRANT,
 * triggers, routines).
 */
class BackupSqlPrefixRewriterTest extends WP_UnitTestCase {

	/**
	 * Rewriter under test.
	 *
	 * @var MainWP_Backup_Sql_Prefix_Rewriter
	 */
	protected $rewriter;

	/**
	 * Build the rewriter: source prefix wp_, replacement tmp1234_.
	 */
	public function set_up() {
		parent::set_up();
		$this->rewriter = new MainWP_Backup_Sql_Prefix_Rewriter( 'wp_', 'tmp1234_' );
	}

	/**
	 * CREATE TABLE gets its table token rewritten.
	 */
	public function test_create_table_rewrites_source_prefix_to_target() {
		$out = $this->rewriter->rewrite( 'CREATE TABLE `wp_posts` (id INT)' );
		$this->assertSame( 'CREATE TABLE `tmp1234_posts` (id INT)', $out );
	}

	/**
	 * CREATE TABLE IF NOT EXISTS is handled.
	 */
	public function test_create_table_if_not_exists_rewritten() {
		$out = $this->rewriter->rewrite( 'CREATE TABLE IF NOT EXISTS `wp_posts` (id INT)' );
		$this->assertSame( 'CREATE TABLE IF NOT EXISTS `tmp1234_posts` (id INT)', $out );
	}

	/**
	 * DROP TABLE IF EXISTS is handled.
	 */
	public function test_drop_table_if_exists_rewritten() {
		$out = $this->rewriter->rewrite( 'DROP TABLE IF EXISTS `wp_posts`' );
		$this->assertSame( 'DROP TABLE IF EXISTS `tmp1234_posts`', $out );
	}

	/**
	 * A table name carrying a doubled backtick is re-emitted doubled. The
	 * token parser decodes `` to `, so re-emitting it raw would close the
	 * identifier and hand the replay the archive's own trailing statement.
	 */
	public function test_backtick_in_table_name_is_re_doubled() {
		$out = $this->rewriter->rewrite( 'DROP TABLE IF EXISTS `wp_a``; DROP TABLE wp_users; -- `' );
		$this->assertSame( 'DROP TABLE IF EXISTS `tmp1234_a``; DROP TABLE wp_users; -- `', $out );
	}

	/**
	 * A multi-table DROP is dropped wholesale: rewriting only the first
	 * token would pass the remaining (live) tables through to a real DROP.
	 */
	public function test_multi_table_drop_is_dropped() {
		$this->assertNull( $this->rewriter->rewrite( 'DROP TABLE IF EXISTS `wp_a`, `wp_options`' ) );
		$this->assertNull( $this->rewriter->rewrite( 'DROP TABLE `wp_a`,`wp_b`' ) );
		$this->assertNull( $this->rewriter->rewrite( 'DROP TABLE IF EXISTS `wp_a`, `wp_b`, `wp_c`' ) );
	}

	/**
	 * A single-table DROP still rewrites, including RESTRICT/CASCADE which
	 * carry no comma.
	 */
	public function test_single_table_drop_still_rewrites() {
		$this->assertSame( 'DROP TABLE `tmp1234_posts` RESTRICT', $this->rewriter->rewrite( 'DROP TABLE `wp_posts` RESTRICT' ) );
		$this->assertSame( 'DROP TABLE `tmp1234_posts` CASCADE', $this->rewriter->rewrite( 'DROP TABLE `wp_posts` CASCADE' ) );
	}

	/**
	 * A bare (unbacktick'd) table name is rewritten too.
	 */
	public function test_bare_table_name_rewritten() {
		$out = $this->rewriter->rewrite( 'INSERT INTO wp_posts VALUES (1)' );
		$this->assertSame( 'INSERT INTO `tmp1234_posts` VALUES (1)', $out );
	}

	/**
	 * INSERT INTO rewrites the table name only, not the values.
	 */
	public function test_insert_into_rewrites_table_name_only() {
		$out = $this->rewriter->rewrite( "INSERT INTO `wp_options` VALUES (1, 'wp_posts is great')" );
		$this->assertSame( "INSERT INTO `tmp1234_options` VALUES (1, 'wp_posts is great')", $out );
	}

	/**
	 * A serialized value containing the prefix as string-literal bytes
	 * is never touched.
	 */
	public function test_serialized_option_value_with_prefix_not_touched() {
		$serialized = 'a:1:{s:8:"wp_users";b:1;}';
		$out        = $this->rewriter->rewrite( "INSERT INTO `wp_options` VALUES (1, '" . $serialized . "')" );
		$this->assertStringContainsString( $serialized, $out );
		$this->assertStringStartsWith( 'INSERT INTO `tmp1234_options`', $out );
	}

	/**
	 * REPLACE INTO is rewritten.
	 */
	public function test_replace_into_rewritten() {
		$out = $this->rewriter->rewrite( 'REPLACE INTO `wp_options` VALUES (1)' );
		$this->assertSame( 'REPLACE INTO `tmp1234_options` VALUES (1)', $out );
	}

	/**
	 * ALTER TABLE is rewritten.
	 */
	public function test_alter_table_rewritten() {
		$out = $this->rewriter->rewrite( 'ALTER TABLE `wp_posts` ADD INDEX `idx` (`id`)' );
		$this->assertSame( 'ALTER TABLE `tmp1234_posts` ADD INDEX `idx` (`id`)', $out );
	}

	/** @dataProvider second_table_alter_statements */
	public function test_alter_naming_second_table_is_rejected( $sql ) {
		$this->assertNull( $this->rewriter->rewrite( $sql ), $sql );
	}

	public static function second_table_alter_statements() {
		return array(
			array( 'ALTER TABLE `wp_a` RENAME TO `wp_users`' ),
			array( 'ALTER TABLE wp_a RENAME AS wp_users' ),
			array( 'alter table wp_a rename wp_users' ),
			array( 'ALTER TABLE `wp_a` EXCHANGE PARTITION p WITH TABLE `wp_users`' ),
			array( "-- dump header\nALTER TABLE wp_a ADD COLUMN x INT, RENAME /* separator */ TO wp_users" ),
			array( 'ALTER TABLE wp_a /*!50100 EXCHANGE PARTITION p WITH TABLE wp_users */' ),
		);
	}

	/**
	 * LOCK TABLES is rewritten.
	 */
	public function test_lock_tables_rewritten() {
		$out = $this->rewriter->rewrite( 'LOCK TABLES `wp_posts` WRITE' );
		$this->assertSame( 'LOCK TABLES `tmp1234_posts` WRITE', $out );
	}

	/**
	 * TRUNCATE TABLE is rewritten.
	 */
	public function test_truncate_rewritten() {
		$out = $this->rewriter->rewrite( 'TRUNCATE TABLE `wp_posts`' );
		$this->assertSame( 'TRUNCATE TABLE `tmp1234_posts`', $out );
	}

	/**
	 * RENAME TABLE rewrites both sides.
	 */
	public function test_rename_table_rewrites_both_sides() {
		$out = $this->rewriter->rewrite( 'RENAME TABLE `wp_a` TO `wp_b`' );
		$this->assertSame( 'RENAME TABLE `tmp1234_a` TO `tmp1234_b`', $out );
	}

	/**
	 * A table outside the source-prefixed set drops the statement.
	 */
	public function test_create_table_with_unknown_prefix_returns_null_preserve_from_live() {
		$this->assertNull( $this->rewriter->rewrite( 'CREATE TABLE `other_posts` (id INT)' ) );
		$this->assertNull( $this->rewriter->rewrite( 'INSERT INTO `mysql`.`user` VALUES (1)' ) );
	}

	/**
	 * USE is forbidden.
	 */
	public function test_use_statement_returns_null() {
		$this->assertNull( $this->rewriter->rewrite( 'USE `other_db`' ) );
	}

	/**
	 * CREATE DATABASE and DROP DATABASE are forbidden.
	 */
	public function test_create_database_returns_null() {
		$this->assertNull( $this->rewriter->rewrite( 'CREATE DATABASE evil' ) );
		$this->assertNull( $this->rewriter->rewrite( 'DROP DATABASE mysql' ) );
	}

	/**
	 * GRANT is forbidden.
	 */
	public function test_grant_returns_null() {
		$this->assertNull( $this->rewriter->rewrite( "GRANT ALL ON *.* TO 'evil'@'%'" ) );
	}

	/**
	 * Triggers and routines are out of scope and dropped.
	 */
	public function test_trigger_and_routine_returns_null() {
		$this->assertNull( $this->rewriter->rewrite( 'CREATE TRIGGER trg BEFORE INSERT ON `wp_posts` FOR EACH ROW SET @x = 1' ) );
		$this->assertNull( $this->rewriter->rewrite( 'CREATE PROCEDURE p() BEGIN END' ) );
		$this->assertNull( $this->rewriter->rewrite( 'CREATE VIEW `wp_v` AS SELECT 1' ) );
	}

	/**
	 * An unknown statement kind is dropped, not executed.
	 */
	public function test_unknown_statement_returns_null() {
		$this->assertNull( $this->rewriter->rewrite( 'CALL some_procedure()' ) );
		$this->assertNull( $this->rewriter->rewrite( 'DELETE FROM `wp_posts` WHERE 1' ) );
	}

	/**
	 * Leading comments and whitespace before the keyword do not defeat
	 * keyword detection.
	 */
	public function test_leading_comment_and_whitespace_skipped_for_detection() {
		$out = $this->rewriter->rewrite( "-- banner line\n  CREATE TABLE `wp_a` (id INT)" );
		$this->assertNotNull( $out );
		$this->assertStringContainsString( '`tmp1234_a`', $out );
	}

	/**
	 * Case-insensitive keyword matching.
	 */
	public function test_keywords_case_insensitive() {
		$out = $this->rewriter->rewrite( 'insert into `wp_posts` values (1)' );
		$this->assertStringContainsString( '`tmp1234_posts`', $out );
	}

	/**
	 * The exact source prefix must match - a table merely starting with
	 * the same letters passes only when the prefix matches byte-for-byte.
	 */
	public function test_prefix_match_is_exact() {
		$this->assertNull( $this->rewriter->rewrite( 'CREATE TABLE `wpx_posts` (id INT)' ) );
	}

	/**
	 * No SET replays, bare or in a conditional comment: the engine sets the session, the plugin's
	 * dumps carry none, and a dump-supplied sql_mode or client charset changes how the server
	 * reads every later string and comment.
	 *
	 * @dataProvider session_set_statements
	 */
	public function test_set_statements_never_replay( $sql ) {
		$this->assertNull( $this->rewriter->rewrite( $sql ), $sql );
	}

	/** Dump headers, session assignments, statement wrappers, server changes and charset clauses. */
	public static function session_set_statements() {
		$allowed = array(
			"SET TIME_ZONE='+00:00'",
			'SET @OLD_TIME_ZONE=@@TIME_ZONE',
			'SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0',
			'SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0',
			'SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0',
			'SET @@SESSION.SQL_LOG_BIN= 0',
			'SET FOREIGN_KEY_CHECKS=0',
			'SET @@session.foreign_key_checks = 0',
			'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ',
			'SET @saved := 1',
			'SET TIME_ZONE=@OLD_TIME_ZONE',
			'SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS',
			'SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS',
			'SET SQL_NOTES=@OLD_SQL_NOTES',
		);
		$rejected = array(
			// The engine pins sql_mode and utf8mb4; a dump that changes either would make later statements lex differently on the server than in the rewriter.
			'SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT',
			'SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS',
			'SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION',
			'SET NAMES utf8mb4',
			'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
			"SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO'",
			'SET @saved_cs_client = @@character_set_client',
			'SET character_set_client = utf8mb4',
			'SET character_set_client = @saved_cs_client',
			'SET SESSION sql_mode = ""',
			'SET @OLD_SQL_MODE=@@SQL_MODE',
			'SET CHARACTER SET utf8mb4',
			'SET CHARSET utf8mb4',
			'SET LOCAL sql_mode = ""',
			'SET @@local.sql_mode = ""',
			'SET sql_mode := ""',
			'SET SQL_MODE=@OLD_SQL_MODE',
			'SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT',
			'SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS',
			'SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION',
			"SET SESSION sql_mode='NO_BACKSLASH_ESCAPES'",
			'SET @a=1, CHARACTER/**/SET gbk',
			'SET @a=1, CHAR SET gbk',
			"SET @@session.`sql_mode` = 'ANSI_QUOTES'",
			'SET NAMES gbk',
			'SET character_set_connection = big5',
			'SET STATEMENT max_statement_time=0 FOR DROP TABLE wp_users',
			"SET STATEMENT sql_mode='' FOR INSERT INTO wp_users VALUES (1)",
			'set statement x=1 for delete from wp_users',
			"SET\tSTATEMENT x=1 FOR DROP TABLE wp_users",
			'SET RESOURCE GROUP rg FOR 1',
			'SET TRANSACTION ISOLATION LEVEL READ COMMITTED',
			'SET GLOBAL general_log = 1',
			'SeT PeRsIsT max_connections = 100',
			'SET PERSIST_ONLY max_connections = 100',
			"SET PASSWORD = 'changed'",
			"SET ROLE 'admin'",
			"SET DEFAULT ROLE 'admin' TO 'user'",
			'SET @@global.general_log = 1',
			'SET @@GLOBAL.general_log = 1',
			'SET @@`global`.general_log = 1',
			'SET @old = 1, GLOBAL general_log = 1',
			'SET @@persist.max_connections = 100',
			'SET @@persist_only.max_connections = 100',
		);
		$rows = array();
		foreach ( array( array( $allowed, true ), array( $rejected, false ) ) as $group ) {
			foreach ( $group[0] as $sql ) {
				$rows[ $sql ] = array( $sql, $group[1] );
				foreach ( array( '40101', '40103', '40014', '50003', '50503' ) as $version ) {
					$conditional = '/*!' . $version . ' ' . $sql . ' */';
					$rows[ $conditional ] = array( $conditional, $group[1] );
				}
			}
		}
		return $rows;
	}


	/**
	 * Every comment shape the shared executable-comment rule lists, in the positions a Child
	 * dump can put it. A refusal must name the shape and never come back as a statement; a
	 * drop returns null with no refusal; anything else must come back byte-for-byte as expected.
	 *
	 * @dataProvider executable_comment_shapes
	 *
	 * @param string             $sql      Statement.
	 * @param string|null|false  $expected Rewritten statement, null for a drop, false for a refusal.
	 */
	public function test_executable_comment_shape_matrix( $sql, $expected ) {
		$out = $this->rewriter->rewrite( $sql );
		if ( false === $expected ) {
			$this->assertNull( $out, $sql );
			$this->assertIsString( $this->rewriter->refusal(), 'A refused shape must say why: ' . $sql );
			return;
		}
		$this->assertSame( $expected, $out, $sql );
		$this->assertNull( $this->rewriter->refusal(), 'Only refused shapes carry a refusal: ' . $sql );
	}

	/**
	 * Rows follow the matrix in sql-executable-comment-rule.md; the Child adds the positions
	 * where an accepted executable comment is still dropped, because only CREATE TABLE options
	 * after the column list ever carry one in a real dump.
	 */
	public static function executable_comment_shapes() {
		$create = 'CREATE TABLE `wp_a` (id INT)';
		$out    = 'CREATE TABLE `tmp1234_a` (id INT)';
		return array(
			'1 plain comment'                          => array( '/* x */ ' . $create, '/* x */ ' . $out ),
			'2 M without bang is plain'                => array( '/*M x */ ' . $create, '/*M x */ ' . $out ),
			'3 bang, session SET'                      => array( '/*! SET FOREIGN_KEY_CHECKS=0 */', null ),
			'3 bang, other statement'                  => array( '/*! DROP TABLE wp_users */', null ),
			'3 bang before a statement'                => array( '/*! DROP TABLE live_users */ ' . $create, null ),
			'3 bang inside INSERT'                     => array( 'INSERT INTO `wp_a` VALUES (1) /*! , (2) */', null ),
			'4 bang 5 digits, session SET'             => array( '/*!50001 SET FOREIGN_KEY_CHECKS=0 */', null ),
			'4 bang 5 digits, CREATE options'          => array( $create . ' /*!50100 PARTITION BY HASH (id) */', $out . ' /*!50100 PARTITION BY HASH (id) */' ),
			'5 bang 6 digits, session SET'             => array( '/*!100100 SET FOREIGN_KEY_CHECKS=0 */', null ),
			'6 M bang, session SET'                    => array( '/*M! SET FOREIGN_KEY_CHECKS=0 */', null ),
			'6 M bang before a statement'              => array( '/*M! DROP TABLE live_users */ ' . $create, null ),
			'6 M bang inside INSERT'                   => array( 'INSERT INTO `wp_a` /*M! SELECT * FROM otherdb.users */', null ),
			'7 M bang 6 digits, session SET'           => array( '/*M!100100 SET FOREIGN_KEY_CHECKS=0 */', null ),
			'7 M bang 6 digits before a statement'     => array( "/*M!100100 DROP TABLE live_users */\n" . $create, null ),
			'7 M bang 5 digits, CREATE options'        => array( $create . ' /*M!50100 PAGE_CHECKSUM=1 */', $out . ' /*M!50100 PAGE_CHECKSUM=1 */' ),
			'7 M bang inside ALTER'                    => array( 'ALTER TABLE `wp_a` /*M!100100 RENAME TO wp_users */', null ),
			'7 M bang inside DROP'                     => array( 'DROP TABLE IF EXISTS `wp_a` /*M!100100 , live_users */', null ),
			'8 lowercase m bang, session SET'          => array( '/*m!100100 SET FOREIGN_KEY_CHECKS=0 */', null ),
			'8 lowercase m bang before a statement'    => array( '/*m!100100 DROP TABLE live_users */ ' . $create, null ),
			'9 empty executable alone'                 => array( '/*!*/', null ),
			'9 empty executable, CREATE options'       => array( $create . ' /*M!*/', $out . ' /*M!*/' ),
			'10 digits glued to body'                  => array( '/*!500011x */', false ),
			'10 five digits glued to SET'              => array( '/*!50001SET NAMES utf8mb4 */', false ),
			'10 mariadb-dump sandbox line'             => array( '/*M!999999\- enable the sandbox mode */', false ),
			'11 four digits'                           => array( '/*!4010 SET NAMES utf8mb4 */', false ),
			'11 seven digits'                          => array( '/*!1001001 x */', false ),
			'11 seven digits, CREATE options'          => array( $create . ' /*M!1001001 x */', false ),
			'12 quote closes inside body'              => array( $create . " /*M!100100 COMMENT='a;b' */", $out . " /*M!100100 COMMENT='a;b' */" ),
			'13 quote spans the first close'           => array( "/*! '*/' x */", false ),
			'13 quote spans the close, CREATE options' => array( $create . " /*!50100 COMMENT='*/' */", false ),
			'14 backtick spans the first close'        => array( '/*! `a*/b` */', false ),
			'15 nested plain in executable'            => array( '/*! /* n */ x */', false ),
			'16 quoted opener in executable'           => array( "/*!99999 '/*' */", false ),
			'17 hash line comment in executable'       => array( "/*! # */\n" . $create, false ),
			'17 dash line comment in executable'       => array( $create . " /*!50100 -- */\n", false ),
			'18 nested executable'                     => array( '/*! /*! x */ */', false ),
			'19 nested plain in plain'                 => array( '/* a /* b */ ' . $create, '/* a /* b */ ' . $out ),
			'20 optimizer hint'                        => array( "INSERT /*+ SET_VAR(a='*/') */ INTO `wp_a` VALUES (1)", false ),
			'20 hint after the table'                  => array( 'INSERT INTO `wp_a` VALUES (1) /*+ x */', false ),
			'21 unterminated plain comment'            => array( $create . ' /* y', false ),
			'22 unterminated executable'               => array( '/*M! x', false ),
			'23 unterminated quote'                    => array( "INSERT INTO `wp_a` VALUES ('abc", false ),
			'23 unterminated backtick'                 => array( 'INSERT INTO `wp_a', false ),
			'quoted markers are data'                  => array( "INSERT INTO `wp_a` VALUES ('/*M! x */', \"-- */\", '\\'/*!')", "INSERT INTO `tmp1234_a` VALUES ('/*M! x */', \"-- */\", '\\'/*!')" ),
			'line comment hides markers'               => array( $create . " # /*M! x\n", $out . " # /*M! x\n" ),
			'dash comment hides markers'               => array( "-- /*M! x\n" . $create, "-- /*M! x\n" . $out ),
			'control byte after dash dash'             => array( "--\x0b/*M! x */\n" . $create, false ),
			'executable before the column list'        => array( 'CREATE TABLE `wp_a` /*!50100 . users */ (id INT)', null ),
			'executable inside the table name'         => array( 'CREATE TABLE /*!50100 `wp_a` */ (id INT)', null ),
			'qualifier after whitespace'               => array( 'CREATE TABLE `wp_a` . users (id INT)', null ),
			'qualifier after a plain comment'          => array( 'INSERT INTO wp_a /* c */.users VALUES (1)', null ),
			'leading SET then a statement'             => array( '/*!40101 SET FOREIGN_KEY_CHECKS=0 */ DROP TABLE wp_a', null ),
			'leading SET then a second executable'     => array( '/*!40101 SET @a = 1 */ /*!40101 , @@global.general_log = 1 */', null ),
			'leading SET with a trailing plain'        => array( '/*!40101 SET FOREIGN_KEY_CHECKS=0 */ /* trailer */', null ),
			'lexing mode SET before a hidden hint'      => array( "/*!40101 SET SESSION sql_mode='NO_BACKSLASH_ESCAPES' */", null ),
			'second DROP table after a comment'        => array( 'DROP TABLE `wp_a`, /* c */ live_users', null ),
			'second LOCK table after a comment'        => array( 'LOCK TABLES `wp_a` WRITE, /**/ live_users READ', null ),
			'RENAME pair after a comment'              => array( 'RENAME TABLE `wp_a` TO `wp_b` /* c */, live_users TO wp_c', null ),
			'RENAME with a trailing plain comment'     => array( 'RENAME TABLE `wp_a` TO `wp_b` /* c */', 'RENAME TABLE `tmp1234_a` TO `tmp1234_b` /* c */' ),
		);
	}

}
