<?php
/**
 * Class BackupUrlRewriterTest
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Backup_Url_Rewriter;

/**
 * Small-page rewriter so pagination and budget yields are exercised
 * with a handful of rows.
 */
class Testable_Url_Rewriter extends MainWP_Backup_Url_Rewriter {

	/**
	 * Page size override.
	 *
	 * @var int
	 */
	public $page = 2;

	/**
	 * Tiny pages.
	 *
	 * @return int
	 */
	protected function page_size() {
		return $this->page;
	}
}

/**
 * Rewriter whose row writes always fail, to exercise the failure-
 * surfacing path without forcing a real SQL error.
 */
class Failing_Url_Rewriter extends MainWP_Backup_Url_Rewriter {

	/**
	 * Simulate a failed write.
	 *
	 * @param string $table   Table name.
	 * @param array  $updates Columns to set.
	 * @param array  $where   Match clause.
	 *
	 * @return false
	 */
	protected function persist_update( $table, $updates, $where ) {
		return false;
	}
}

/**
 * Tests for the paginated serialization-aware URL rewriter.
 *
 * Runs against real tables on the WP test database. Serialized values
 * must come back with corrected string lengths; the posts guid column
 * is excluded by configuration; an exhausted budget yields a cursor
 * that resumes mid-table.
 */
class BackupUrlRewriterTest extends WP_UnitTestCase {

	/**
	 * Test table name.
	 *
	 * @var string
	 */
	protected $table = 'urwt_options';

	/**
	 * Rewriter under test.
	 *
	 * @var Testable_Url_Rewriter
	 */
	protected $rewriter;

	/**
	 * Create the scratch table.
	 */
	public function set_up() {
		parent::set_up();
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS `' . $this->table . '`' ); // phpcs:ignore WordPress.DB -- test fixture.
		$wpdb->query( 'CREATE TABLE `' . $this->table . '` (id INT NOT NULL AUTO_INCREMENT, name VARCHAR(64), value LONGTEXT, guid VARCHAR(255), PRIMARY KEY (id))' ); // phpcs:ignore WordPress.DB -- test fixture.
		$this->rewriter = new Testable_Url_Rewriter();
	}

	/**
	 * Drop the scratch table.
	 */
	public function tear_down() {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS `' . $this->table . '`' ); // phpcs:ignore WordPress.DB -- test cleanup.
		parent::tear_down();
	}

	/**
	 * Insert a row.
	 *
	 * @param string $name  Name.
	 * @param string $value Value.
	 * @param string $guid  Guid.
	 */
	protected function insert( $name, $value, $guid = '' ) {
		global $wpdb;
		$wpdb->insert(
			$this->table,
			array(
				'name'  => $name,
				'value' => $value,
				'guid'  => $guid,
			)
		);
	}

	/**
	 * Read one value back.
	 *
	 * @param string $name Row name.
	 *
	 * @return array Row.
	 */
	protected function fetch( $name ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM `' . $this->table . '` WHERE name = %s', $name ), ARRAY_A ); // phpcs:ignore WordPress.DB -- test verification.
	}

	/**
	 * A failed row update is surfaced in the slice outcome rather than
	 * being silently swallowed, while the slice still completes.
	 */
	public function test_failed_update_is_surfaced_not_silent() {
		$this->insert( 'home', 'http://old.example', '' );

		$rewriter = new Failing_Url_Rewriter();
		$outcome  = $rewriter->rewrite_slice(
			array(),
			array(
				'tables'  => array( $this->table ),
				'pairs'   => array( array( 'from' => 'http://old.example', 'to' => 'http://new.example' ) ),
				'exclude' => array(),
			),
			microtime( true ) + 3600
		);

		$this->assertTrue( $outcome['done'] );
		$this->assertSame( 1, $outcome['failed_updates'] );
		$this->assertNotSame( '', $outcome['last_error'] );
	}

	/**
	 * A clean rewrite reports zero failed updates.
	 */
	public function test_successful_rewrite_reports_no_failures() {
		$this->insert( 'home', 'http://old.example', '' );

		$outcome = $this->rewriter->rewrite_slice(
			array(),
			array(
				'tables'  => array( $this->table ),
				'pairs'   => array( array( 'from' => 'http://old.example', 'to' => 'http://new.example' ) ),
				'exclude' => array(),
			),
			microtime( true ) + 3600
		);

		$this->assertTrue( $outcome['done'] );
		$this->assertSame( 0, $outcome['failed_updates'] );
	}

	public function test_exact_column_renames_are_scoped_and_override_pair_exclusions() {
		$this->insert( 'wp_capabilities', 'http://old.example/wp_value', 'wp_capabilities' );
		$this->insert( 'plugin_wp_capabilities', 'http://old.example/wp_value', 'plugin_wp_capabilities' );
		$result = $this->run_all(
			$this->config(
				array(
					'pairs' => array( array( 'from' => 'wp_', 'to' => 'rt_' ) ),
					'exclude' => array( $this->table => array( 'name', 'guid' ) ),
					'rename_columns' => array(
						$this->table => array( 'name' => array( 'wp_capabilities' => 'rt_capabilities' ) ),
						'other_options' => array( 'guid' => array( 'wp_capabilities' => 'wrong' ) ),
					),
				)
			)
		);
		$this->assertSame( 0, $result['failed_updates'] );
		$renamed = $this->fetch( 'rt_capabilities' );
		$this->assertNotNull( $renamed );
		$this->assertSame( 'wp_capabilities', $renamed['guid'] );
		$this->assertSame( 'http://old.example/rt_value', $renamed['value'] );
		$this->assertNotNull( $this->fetch( 'plugin_wp_capabilities' ) );
	}

	/**
	 * Default config for the scratch table.
	 *
	 * @param array $over Overrides.
	 *
	 * @return array Config.
	 */
	protected function config( $over = array() ) {
		return array_merge(
			array(
				'tables'  => array( $this->table ),
				'pairs'   => array(
					array(
						'from' => 'http://old.example',
						'to'   => 'https://new.example',
					),
				),
				'exclude' => array(),
			),
			$over
		);
	}

	/**
	 * Run the rewriter to completion.
	 *
	 * @param array $config Config.
	 *
	 * @return array Final result.
	 */
	protected function run_all( $config ) {
		$result = array( 'cursor' => array() );
		$guard  = 0;
		do {
			$result = $this->rewriter->rewrite_slice( $result['cursor'], $config, microtime( true ) + 3600 );
			++$guard;
		} while ( ! $result['done'] && $guard < 50 );
		return $result;
	}

	/**
	 * Restricted decoding preserves back-references, so traversal must be bounded.
	 */
	public function test_recursive_replace_terminates_on_self_referencing_array() {
		$a    = array();
		$a[0] = &$a;

		$this->assertIsArray( $this->rewriter->recursive_replace( 'old', 'new', $a ) );
		$result = $this->rewriter->recursive_replace( 'old', 'new', serialize( $a ) );
		$this->assertIsString( $result );
		$this->assertIsArray( unserialize( $result, array( 'allowed_classes' => false ) ) );
	}

	/**
	 * The depth limit must leave ordinary nested URL replacement usable.
	 */
	public function test_recursive_replace_rewrites_five_deep_array() {
		$data     = 'http://old.example/page';
		$expected = 'https://new.example/page';
		for ( $depth = 0; $depth < 5; ++$depth ) {
			$data     = array( $data );
			$expected = array( $expected );
		}

		$this->assertSame( $expected, $this->rewriter->recursive_replace( 'http://old.example', 'https://new.example', $data ) );
		$this->assertSame( serialize( $expected ), $this->rewriter->recursive_replace( 'http://old.example', 'https://new.example', serialize( $data ) ) );
	}

	/**
	 * A plain string URL is rewritten.
	 */
	public function test_plain_string_url_rewritten() {
		$this->insert( 'site', 'http://old.example/page' );
		$result = $this->run_all( $this->config() );

		$this->assertTrue( $result['done'] );
		$this->assertSame( 'https://new.example/page', $this->fetch( 'site' )['value'] );
	}

	/**
	 * A serialized array value is rewritten with corrected lengths.
	 */
	public function test_serialized_string_url_rewritten_safely() {
		$this->insert( 'widget', serialize( array( 'url' => 'http://old.example/x', 'n' => 5 ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- fixture.
		$this->run_all( $this->config() );

		$value = $this->fetch( 'widget' )['value'];
		$data  = unserialize( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- verification.
		$this->assertSame( 'https://new.example/x', $data['url'] );
		$this->assertSame( 5, $data['n'] );
	}

	/**
	 * A naive str_replace would corrupt the length prefix; the rewritten
	 * serialized value must still unserialize.
	 */
	public function test_rewritten_serialized_value_remains_parseable() {
		// Replacement is one byte longer than the original.
		$this->insert( 'lens', serialize( array( 'u' => 'http://old.example' ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- fixture.
		$this->run_all( $this->config() );

		$data = unserialize( $this->fetch( 'lens' )['value'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- verification.
		$this->assertNotFalse( $data );
		$this->assertSame( 'https://new.example', $data['u'] );
	}

	/**
	 * Excluded columns are never rewritten.
	 */
	public function test_excluded_column_not_rewritten() {
		$this->insert( 'post', 'http://old.example/content', 'http://old.example/?p=1' );
		$this->run_all( $this->config( array( 'exclude' => array( $this->table => array( 'guid' ) ) ) ) );

		$row = $this->fetch( 'post' );
		$this->assertSame( 'https://new.example/content', $row['value'] );
		$this->assertSame( 'http://old.example/?p=1', $row['guid'] );
	}

	/**
	 * Multiple pairs apply in one pass (URL plus prefix rewrite).
	 */
	public function test_multiple_pairs_applied() {
		$this->insert( 'meta', serialize( array( 'k' => 'src_users', 'u' => 'http://old.example' ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- fixture.
		$config = $this->config();
		$config['pairs'][] = array(
			'from' => 'src_',
			'to'   => 'dst_',
		);
		$this->run_all( $config );

		$data = unserialize( $this->fetch( 'meta' )['value'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- verification.
		$this->assertSame( 'dst_users', $data['k'] );
		$this->assertSame( 'https://new.example', $data['u'] );
	}

	/**
	 * An exhausted budget yields mid-table and the cursor resumes
	 * without skipping or double-processing rows.
	 */
	public function test_paginated_resume_from_cursor() {
		for ( $i = 0; $i < 7; $i++ ) {
			$this->insert( 'row' . $i, 'http://old.example/' . $i );
		}

		$config = $this->config();
		$result = $this->rewriter->rewrite_slice( array(), $config, 0 );
		$this->assertFalse( $result['done'] );

		$guard = 0;
		while ( ! $result['done'] && $guard < 50 ) {
			$result = $this->rewriter->rewrite_slice( $result['cursor'], $config, 0 );
			++$guard;
		}
		$this->assertTrue( $result['done'] );

		for ( $i = 0; $i < 7; $i++ ) {
			$this->assertSame( 'https://new.example/' . $i, $this->fetch( 'row' . $i )['value'], "row$i" );
		}
	}

	/**
	 * A serialized object passes through unrewritten but uncorrupted:
	 * restricted unserialization means object properties are not
	 * touched, and the value still round-trips byte-identical.
	 */
	public function test_serialized_object_passes_through_uncorrupted() {
		$original = 'O:8:"stdClass":1:{s:3:"url";s:18:"http://old.example";}';
		$this->insert( 'obj', $original );
		$this->run_all( $this->config() );

		$this->assertSame( $original, $this->fetch( 'obj' )['value'] );
	}
}
