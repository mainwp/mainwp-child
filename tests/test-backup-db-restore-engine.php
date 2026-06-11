<?php
/**
 * Class BackupDbRestoreEngineTest
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Backup_Db_Restore_Engine;
use MainWP\Child\MainWP_Backup_Error;

/**
 * Testable engine: every database and filesystem side effect is a seam.
 *
 * Statements are recorded instead of executed; the connection is a
 * stand-in object; maintenance-mode toggles are logged. Chunk size is
 * tiny so multi-chunk scanning and budget yields are exercised with
 * small fixtures.
 */
class Testable_Restore_Engine extends MainWP_Backup_Db_Restore_Engine {

	/**
	 * Executed statements, in order.
	 *
	 * @var string[]
	 */
	public $executed = array();

	/**
	 * Substring that forces an execution error.
	 *
	 * @var string|null
	 */
	public $fail_on = null;

	/**
	 * Reported max_allowed_packet.
	 *
	 * @var int
	 */
	public $packet = 1048576;

	/**
	 * Chunk size override.
	 *
	 * @var int
	 */
	public $chunk = 64;

	/**
	 * Live table names reported by SHOW TABLES.
	 *
	 * @var string[]
	 */
	public $live_tables = array();

	/**
	 * Maintenance toggle log.
	 *
	 * @var string[]
	 */
	public $maintenance_calls = array();

	/**
	 * Connection stand-in.
	 *
	 * @return object
	 */
	protected function connect() {
		return new stdClass();
	}

	/**
	 * No-op close.
	 *
	 * @param object $conn Connection.
	 */
	protected function close_connection( $conn ) {
	}

	/**
	 * Record the statement; optionally fail.
	 *
	 * @param object $conn Connection.
	 * @param string $sql  Statement.
	 *
	 * @return int|string Affected rows or error text.
	 */
	protected function execute_statement( $conn, $sql ) {
		$this->executed[] = $sql;
		if ( null !== $this->fail_on && strpos( $sql, $this->fail_on ) !== false ) {
			return 'forced failure for test';
		}
		return 1;
	}

	/**
	 * Canned query results.
	 *
	 * @param object $conn Connection.
	 * @param string $sql  Query.
	 *
	 * @return array|null
	 */
	protected function query_rows( $conn, $sql ) {
		if ( false !== stripos( $sql, 'max_allowed_packet' ) ) {
			return array( array( 'map' => $this->packet ) );
		}
		if ( false !== stripos( $sql, 'SHOW TABLES' ) ) {
			$rows = array();
			foreach ( $this->live_tables as $table ) {
				$rows[] = array( 'name' => $table );
			}
			return $rows;
		}
		return array();
	}

	/**
	 * Tiny chunks for boundary coverage.
	 *
	 * @return int
	 */
	protected function chunk_bytes() {
		return $this->chunk;
	}

	/**
	 * Log maintenance entry.
	 */
	protected function begin_maintenance() {
		$this->maintenance_calls[] = 'begin';
	}

	/**
	 * Log maintenance exit.
	 */
	protected function end_maintenance() {
		$this->maintenance_calls[] = 'end';
	}
}

/**
 * Tests for the DB restore engine: chunked replay with byte-offset
 * resume, per-table fail isolation, the atomic RENAME builder, banner
 * and foreign-key probing, and the in-place maintenance path.
 */
class BackupDbRestoreEngineTest extends WP_UnitTestCase {

	/**
	 * Fixture dir.
	 *
	 * @var string
	 */
	protected $dir;

	/**
	 * Engine under test.
	 *
	 * @var Testable_Restore_Engine
	 */
	protected $engine;

	/**
	 * Tables created on the real test DB, dropped in tear_down.
	 *
	 * @var string[]
	 */
	protected $real_tables = array();

	/**
	 * Build scratch dir and engine.
	 */
	public function set_up() {
		parent::set_up();
		$this->dir = sys_get_temp_dir() . '/mainwp-restore-engine-' . uniqid();
		mkdir( $this->dir );
		$this->engine = new Testable_Restore_Engine();
	}

	/**
	 * Remove fixtures and any real tables.
	 */
	public function tear_down() {
		foreach ( (array) glob( $this->dir . '/*' ) as $file ) {
			unlink( $file );
		}
		if ( is_dir( $this->dir ) ) {
			rmdir( $this->dir );
		}
		global $wpdb;
		foreach ( $this->real_tables as $table ) {
			$wpdb->query( 'DROP TABLE IF EXISTS `' . $table . '`' ); // phpcs:ignore WordPress.DB -- test cleanup.
		}
		parent::tear_down();
	}

	/**
	 * Write a guarded per-table dump fixture.
	 *
	 * @param string $name   File name.
	 * @param string $sql    SQL body.
	 * @param string $banner Optional banner block.
	 *
	 * @return string Path.
	 */
	protected function write_dump( $name, $sql, $banner = '' ) {
		$path = $this->dir . '/' . $name;
		file_put_contents( $path, '<?php exit(); ?>' . ( '' !== $banner ? "\n" . $banner : '' ) . "\n\n" . $sql ); // phpcs:ignore -- test fixture.
		return $path;
	}

	/**
	 * Default replay config.
	 *
	 * @param array $files Dump files.
	 * @param array $over  Overrides.
	 *
	 * @return array Config.
	 */
	protected function config( $files, $over = array() ) {
		return array_merge(
			array(
				'restore_id'    => 'abc123',
				'source_prefix' => 'wp_',
				'target_prefix' => 'wp_',
				'files'         => $files,
				'fk_path'       => false,
			),
			$over
		);
	}

	/**
	 * Future deadline.
	 *
	 * @return float
	 */
	protected function no_deadline() {
		return microtime( true ) + 3600;
	}

	/**
	 * Replay walks every file and reports per-table success.
	 */
	public function test_replay_slice_advances_file_cursor() {
		$files  = array(
			$this->write_dump( 'dbBackup-wp_a.sql.php', "DROP TABLE IF EXISTS `wp_a`;\nCREATE TABLE `wp_a` (id INT);\nINSERT INTO `wp_a` (`id`) VALUES (1),(2);\n" ),
			$this->write_dump( 'dbBackup-wp_b.sql.php', "DROP TABLE IF EXISTS `wp_b`;\nCREATE TABLE `wp_b` (id INT);\n" ),
		);
		$result = $this->engine->replay_slice( array(), $this->config( $files ), $this->no_deadline() );

		$this->assertNull( $result['error'] );
		$this->assertTrue( $result['done'] );
		$this->assertSame( 'ok', $result['cursor']['tables']['a']['status'] );
		$this->assertSame( 'ok', $result['cursor']['tables']['b']['status'] );

		$joined = implode( "\n", $this->engine->executed );
		$this->assertStringContainsString( 'CREATE TABLE `tmpabc123_a`', $joined );
		$this->assertStringContainsString( 'INSERT INTO `tmpabc123_a`', $joined );
		$this->assertStringContainsString( 'CREATE TABLE `tmpabc123_b`', $joined );
	}

	/**
	 * The permissive session is applied before any replay statement.
	 */
	public function test_permissive_session_applied_first() {
		$files = array(
			$this->write_dump( 'dbBackup-wp_a.sql.php', "CREATE TABLE `wp_a` (id INT);\n" ),
		);
		$this->engine->replay_slice( array(), $this->config( $files ), $this->no_deadline() );

		$this->assertStringContainsString( 'ALLOW_INVALID_DATES', $this->engine->executed[0] );
		$this->assertStringContainsString( 'FOREIGN_KEY_CHECKS=0', $this->engine->executed[1] );
		$this->assertStringContainsString( 'UNIQUE_CHECKS=0', $this->engine->executed[2] );
	}

	/**
	 * The guard header never reaches the connection.
	 */
	public function test_guard_strip_applied_only_at_offset_zero() {
		$files = array(
			$this->write_dump( 'dbBackup-wp_a.sql.php', "CREATE TABLE `wp_a` (id INT);\n" ),
		);
		$this->engine->replay_slice( array(), $this->config( $files ), $this->no_deadline() );

		foreach ( $this->engine->executed as $sql ) {
			$this->assertStringNotContainsString( '<?php', $sql );
		}
	}

	/**
	 * An exhausted budget yields a resumable cursor after minimum work.
	 */
	public function test_replay_slice_yields_on_budget_exhaustion() {
		$rows = array();
		for ( $i = 0; $i < 50; $i++ ) {
			$rows[] = "INSERT INTO `wp_a` (`id`) VALUES ($i);";
		}
		$files  = array(
			$this->write_dump( 'dbBackup-wp_a.sql.php', "CREATE TABLE `wp_a` (id INT);\n" . implode( "\n", $rows ) . "\n" ),
		);
		$result = $this->engine->replay_slice( array(), $this->config( $files ), 0 );

		$this->assertFalse( $result['done'] );
		$this->assertNull( $result['error'] );
		$this->assertGreaterThan( 0, $result['cursor']['offset'] );
		$this->assertNotEmpty( $this->engine->executed );
	}

	/**
	 * A second slice resumes from the cursor without repeating work.
	 */
	public function test_replay_slice_resumes_from_byte_offset() {
		$rows = array();
		for ( $i = 0; $i < 50; $i++ ) {
			$rows[] = "INSERT INTO `wp_a` (`id`) VALUES ($i);";
		}
		$files  = array(
			$this->write_dump( 'dbBackup-wp_a.sql.php', "CREATE TABLE `wp_a` (id INT);\n" . implode( "\n", $rows ) . "\n" ),
		);
		$config = $this->config( $files );

		$result = $this->engine->replay_slice( array(), $config, 0 );
		$this->assertFalse( $result['done'] );
		$first_run = $this->engine->executed;

		// Drain the rest.
		$guard = 0;
		while ( ! $result['done'] && $guard < 100 ) {
			$result = $this->engine->replay_slice( $result['cursor'], $config, 0 );
			$this->assertNull( $result['error'] );
			++$guard;
		}
		$this->assertTrue( $result['done'] );

		// Count INSERT statements across all slices: exactly 50, no repeats.
		$inserts = 0;
		foreach ( $this->engine->executed as $sql ) {
			if ( 0 === strpos( $sql, 'INSERT INTO `tmpabc123_a`' ) ) {
				++$inserts;
			}
		}
		$this->assertSame( 50, $inserts );
		$this->assertGreaterThan( 0, count( $first_run ) );
	}

	/**
	 * An unclean resume restarts the in-flight file from offset zero;
	 * the file's own leading DROP keeps the rerun idempotent.
	 */
	public function test_unclean_resume_restarts_current_file() {
		$rows = array();
		for ( $i = 0; $i < 50; $i++ ) {
			$rows[] = "INSERT INTO `wp_a` (`id`) VALUES ($i);";
		}
		$files  = array(
			$this->write_dump( 'dbBackup-wp_a.sql.php', "DROP TABLE IF EXISTS `wp_a`;\nCREATE TABLE `wp_a` (id INT);\n" . implode( "\n", $rows ) . "\n" ),
		);
		$config = $this->config( $files );

		$result = $this->engine->replay_slice( array(), $config, 0 );
		$this->assertFalse( $result['done'] );
		$this->assertGreaterThan( 0, $result['cursor']['offset'] );

		$resumed = $this->engine->replay_slice( $result['cursor'], $config, $this->no_deadline(), true );
		$this->assertTrue( $resumed['done'] );

		// The file restarted: its DROP ran twice (once per attempt).
		$drops = 0;
		foreach ( $this->engine->executed as $sql ) {
			if ( 0 === strpos( $sql, 'DROP TABLE IF EXISTS `tmpabc123_a`' ) ) {
				++$drops;
			}
		}
		$this->assertSame( 2, $drops );
	}

	/**
	 * An execution error marks the table failed and aborts the replay.
	 */
	public function test_table_failure_marks_table_failed_and_aborts() {
		$files = array(
			$this->write_dump( 'dbBackup-wp_a.sql.php', "CREATE TABLE `wp_a` (id INT);\nINSERT INTO `wp_a` (`id`) VALUES (1);\n" ),
			$this->write_dump( 'dbBackup-wp_b.sql.php', "CREATE TABLE `wp_b` (id INT);\n" ),
		);

		$this->engine->fail_on = 'INSERT INTO `tmpabc123_a`';
		$result                = $this->engine->replay_slice( array(), $this->config( $files ), $this->no_deadline() );

		$this->assertFalse( $result['done'] );
		$this->assertNotNull( $result['error'] );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_DB_REPLAY, $result['error_code'] );
		$this->assertSame( 'failed', $result['cursor']['tables']['a']['status'] );

		// Nothing from the second file ran.
		$joined = implode( "\n", $this->engine->executed );
		$this->assertStringNotContainsString( 'tmpabc123_b', $joined );
	}

	/**
	 * An INSERT larger than max_allowed_packet is split before execution.
	 */
	public function test_insert_split_when_statement_exceeds_packet() {
		// 200 tuples of ~37 bytes: one ~7 KB INSERT statement.
		$tuples = array();
		for ( $i = 0; $i < 200; $i++ ) {
			$tuples[] = "($i,'" . str_repeat( 'x', 30 ) . "')";
		}
		$files = array(
			$this->write_dump( 'dbBackup-wp_a.sql.php', "CREATE TABLE `wp_a` (id INT, v TEXT);\nINSERT INTO `wp_a` (`id`, `v`) VALUES " . implode( ',', $tuples ) . ";\n" ),
		);

		// Budget is packet minus the engine's 1024-byte overhead reserve,
		// with a 4096 floor - so the smallest simulable budget is 4096.
		$this->engine->packet = 5120;

		$result = $this->engine->replay_slice( array(), $this->config( $files ), $this->no_deadline() );
		$this->assertNull( $result['error'] );

		$inserts = 0;
		foreach ( $this->engine->executed as $sql ) {
			if ( 0 === strpos( $sql, 'INSERT INTO' ) ) {
				++$inserts;
				$this->assertLessThanOrEqual( 4096, strlen( $sql ) );
			}
		}
		$this->assertGreaterThan( 1, $inserts );
	}

	/**
	 * Statements naming foreign-prefix tables are dropped, counted, and
	 * never executed.
	 */
	public function test_preserve_from_live_drops_foreign_prefix_tables() {
		$files  = array(
			$this->write_dump( 'dbBackup-wp_a.sql.php', "CREATE TABLE `wp_a` (id INT);\nINSERT INTO `other_users` VALUES (1);\nDROP DATABASE mysql;\nUSE `mysql`;\n" ),
		);
		$result = $this->engine->replay_slice( array(), $this->config( $files ), $this->no_deadline() );

		$this->assertNull( $result['error'] );
		$this->assertTrue( $result['done'] );
		$this->assertSame( 3, $result['cursor']['dropped'] );

		$joined = implode( "\n", $this->engine->executed );
		$this->assertStringNotContainsString( 'other_users', $joined );
		$this->assertStringNotContainsString( 'DATABASE', $joined );
	}

	/**
	 * Stems on the skip list never replay - the running restore's own
	 * spine table must survive the swap.
	 */
	public function test_skip_stems_excluded_from_replay() {
		$files  = array(
			$this->write_dump( 'dbBackup-wp_mainwp_child_backup_task.sql.php', "DROP TABLE IF EXISTS `wp_mainwp_child_backup_task`;\nCREATE TABLE `wp_mainwp_child_backup_task` (id INT);\n" ),
			$this->write_dump( 'dbBackup-wp_a.sql.php', "CREATE TABLE `wp_a` (id INT);\n" ),
		);
		$config = $this->config( $files );
		$config['skip_stems'] = array( 'mainwp_child_backup_task' );

		$result = $this->engine->replay_slice( array(), $config, $this->no_deadline() );
		$this->assertNull( $result['error'] );
		$this->assertTrue( $result['done'] );
		$this->assertArrayNotHasKey( 'mainwp_child_backup_task', $result['cursor']['tables'] );

		$joined = implode( "\n", $this->engine->executed );
		$this->assertStringNotContainsString( 'mainwp_child_backup_task', $joined );
		$this->assertStringContainsString( 'CREATE TABLE `tmpabc123_a`', $joined );
	}

	/**
	 * A dump file with bytes after the last terminator is truncated and
	 * fails rather than silently losing the tail.
	 */
	public function test_truncated_dump_tail_is_an_error() {
		$files  = array(
			$this->write_dump( 'dbBackup-wp_a.sql.php', "CREATE TABLE `wp_a` (id INT);\nINSERT INTO `wp_a` (`id`) VALUES (1" ),
		);
		$result = $this->engine->replay_slice( array(), $this->config( $files ), $this->no_deadline() );

		$this->assertFalse( $result['done'] );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_DB_REPLAY, $result['error_code'] );
	}

	/**
	 * The in-place FK path enters maintenance mode and replays against
	 * real table names.
	 */
	public function test_fk_path_enters_maintenance_and_uses_target_prefix() {
		$files  = array(
			$this->write_dump( 'dbBackup-wp_a.sql.php', "DROP TABLE IF EXISTS `wp_a`;\nCREATE TABLE `wp_a` (id INT);\n" ),
		);
		$result = $this->engine->replay_slice( array(), $this->config( $files, array( 'fk_path' => true, 'target_prefix' => 'live_' ) ), $this->no_deadline() );

		$this->assertNull( $result['error'] );
		$this->assertContains( 'begin', $this->engine->maintenance_calls );

		$joined = implode( "\n", $this->engine->executed );
		$this->assertStringContainsString( 'CREATE TABLE `live_a`', $joined );
		$this->assertStringNotContainsString( 'tmpabc123', $joined );
	}

	/**
	 * exit_maintenance() is exposed for the runner's phase transitions
	 * and terminal failures.
	 */
	public function test_exit_maintenance_exposed() {
		$this->engine->exit_maintenance();
		$this->assertContains( 'end', $this->engine->maintenance_calls );
	}

	/**
	 * The banner reader parses info and meta lines.
	 */
	public function test_read_banner_parses_info_and_meta() {
		$banner = '-- mainwp-backup-info: {"site_url":"https://old.example","home_url":"https://old.example","table_prefix":"wp_"}' . "\n" . '-- mainwp-backup-meta: {"has_foreign_keys":false,"tier":1}';
		$file   = $this->write_dump( 'dbBackup-wp_a.sql.php', "CREATE TABLE `wp_a` (id INT);\n", $banner );

		$parsed = $this->engine->read_banner( $file );
		$this->assertSame( 'https://old.example', $parsed['info']['site_url'] );
		$this->assertSame( 'wp_', $parsed['info']['table_prefix'] );
		$this->assertFalse( $parsed['meta']['has_foreign_keys'] );
	}

	/**
	 * A file without banner lines reports nulls.
	 */
	public function test_read_banner_handles_missing_banner() {
		$file   = $this->write_dump( 'dbBackup-wp_a.sql.php', "CREATE TABLE `wp_a` (id INT);\n" );
		$parsed = $this->engine->read_banner( $file );
		$this->assertNull( $parsed['info'] );
		$this->assertNull( $parsed['meta'] );
	}

	/**
	 * The foreign-key probe detects FOREIGN KEY clauses in a dump whose
	 * banner lacks the flag (tier-2 dumps record null).
	 */
	public function test_probe_fk_detects_foreign_key() {
		$with    = $this->write_dump( 'dbBackup-wp_fk.sql.php', "CREATE TABLE `wp_fk` (id INT, CONSTRAINT `c` FOREIGN KEY (`id`) REFERENCES `wp_a` (`id`));\n" );
		$without = $this->write_dump( 'dbBackup-wp_plain.sql.php', "CREATE TABLE `wp_plain` (id INT);\nINSERT INTO `wp_plain` (`id`) VALUES (1);\n" );

		$this->assertTrue( $this->engine->probe_fk( array( $without, $with ) ) );
		$this->assertFalse( $this->engine->probe_fk( array( $without ) ) );
	}

	/**
	 * The probe scans across chunk boundaries.
	 */
	public function test_probe_fk_across_chunk_boundary() {
		$pad  = str_repeat( '-', 60 );
		$file = $this->write_dump( 'dbBackup-wp_fk.sql.php', $pad . "FOREIGN KEY" . $pad );
		$this->assertTrue( $this->engine->probe_fk( array( $file ) ) );
	}

	/**
	 * The RENAME builder emits one atomic statement: live tables move
	 * aside, tmp tables promote, missing live tables get no aside pair.
	 */
	public function test_build_rename_sql_atomic_pairs() {
		$sql = $this->engine->build_rename_sql(
			array( 'a', 'b' ),
			'abc123',
			'wp_',
			array( 'wp_a' ),
			1048576
		);

		$this->assertNotNull( $sql );
		$this->assertSame( 0, strpos( $sql, 'RENAME TABLE ' ) );
		$this->assertStringContainsString( '`wp_a` TO `oldabc123_a`', $sql );
		$this->assertStringContainsString( '`tmpabc123_a` TO `wp_a`', $sql );
		$this->assertStringContainsString( '`tmpabc123_b` TO `wp_b`', $sql );
		$this->assertStringNotContainsString( '`wp_b` TO', $sql );
		$this->assertSame( 1, substr_count( $sql, 'RENAME TABLE' ) );
	}

	/**
	 * A RENAME that would exceed max_allowed_packet aborts rather than
	 * splitting into a non-atomic sequence.
	 */
	public function test_build_rename_sql_aborts_over_packet() {
		$this->assertNull(
			$this->engine->build_rename_sql( array( 'a', 'b' ), 'abc123', 'wp_', array(), 32 )
		);
	}

	/**
	 * swap_tables() executes the single RENAME and reports the aside
	 * tables for later drop.
	 */
	public function test_swap_tables_executes_single_rename() {
		$this->engine->live_tables = array( 'wp_a', 'wp_unrelated' );

		$result = $this->engine->swap_tables(
			array( 'a', 'b' ),
			$this->config( array() )
		);

		$this->assertTrue( $result['done'] );
		$this->assertSame( array( 'oldabc123_a' ), $result['old_tables'] );

		$renames = 0;
		foreach ( $this->engine->executed as $sql ) {
			if ( 0 === strpos( $sql, 'RENAME TABLE' ) ) {
				++$renames;
			}
		}
		$this->assertSame( 1, $renames );
	}

	/**
	 * Real-database round trip: handcrafted per-table dumps replay into
	 * tmp tables on the live test DB, swap into a scratch prefix, and
	 * the data survives intact - including a value whose semicolons and
	 * quotes would break a naive splitter.
	 */
	public function test_real_replay_and_swap_round_trip() {
		global $wpdb;

		$this->real_tables = array( 'tmpr34l01_items', 'rdst77_items', 'oldr34l01_items' );

		$nasty = "a:1:{s:3:\"url\";s:14:\"http://x;y'z/w\";}";
		$sql   = "DROP TABLE IF EXISTS `src_items`;\n"
			. "CREATE TABLE `src_items` (id INT NOT NULL, val TEXT, PRIMARY KEY (id));\n"
			. "INSERT INTO `src_items` (`id`, `val`) VALUES (1,'" . str_replace( array( "'", '"' ), array( "\\'", '\\"' ), $nasty ) . "'),(2,'plain; enough');\n";

		$files  = array( $this->write_dump( 'dbBackup-src_items.sql.php', $sql ) );
		$engine = new MainWP_Backup_Db_Restore_Engine();
		$config = array(
			'restore_id'    => 'r34l01',
			'source_prefix' => 'src_',
			'target_prefix' => 'rdst77_',
			'files'         => $files,
			'fk_path'       => false,
		);

		$result = $engine->replay_slice( array(), $config, microtime( true ) + 3600 );
		$this->assertNull( $result['error'] );
		$this->assertTrue( $result['done'] );
		$this->assertSame( 2, $result['cursor']['tables']['items']['rows'] );

		$swap = $engine->swap_tables( array( 'items' ), $config );
		$this->assertTrue( $swap['done'] );

		$rows = $wpdb->get_results( 'SELECT id, val FROM `rdst77_items` ORDER BY id', ARRAY_A ); // phpcs:ignore WordPress.DB -- test verification.
		$this->assertCount( 2, $rows );
		$this->assertSame( $nasty, $rows[0]['val'] );

		$counts = $engine->verify_row_counts( array( 'items' => 2 ), 'rdst77_' );
		$this->assertTrue( $counts['ok'] );

		$engine->cleanup_tmp_tables( 'r34l01' );
		$engine->drop_old_tables( 'r34l01' );
		$this->assertEmpty( $wpdb->get_var( "SHOW TABLES LIKE 'tmpr34l01%'" ) ); // phpcs:ignore WordPress.DB -- test verification.
	}
}
