<?php
/**
 * Class BackupDbRestoreEngineTest
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Backup_Db_Restore_Engine;
use MainWP\Child\MainWP_Backup_Error;
use MainWP\Child\MainWP_Exception;

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
	 * Substring that forces a transient (gone-away) execution error while
	 * $fail_transient_remaining is above zero.
	 *
	 * @var string|null
	 */
	public $transient_fail_on = null;

	/**
	 * Remaining transient failures to inject for $transient_fail_on.
	 *
	 * @var int
	 */
	public $fail_transient_remaining = 0;

	/**
	 * Error number reported for the most recent statement.
	 *
	 * @var int
	 */
	public $last_errno = 0;

	/**
	 * Number of connect() calls.
	 *
	 * @var int
	 */
	public $connect_calls = 0;

	/**
	 * Connect attempts beyond this count fail (null = never fail).
	 *
	 * @var int|null
	 */
	public $fail_connect_after = null;

	/**
	 * Connection stand-in.
	 *
	 * @return object|false
	 */
	protected function connect() {
		++$this->connect_calls;
		if ( null !== $this->fail_connect_after && $this->connect_calls > $this->fail_connect_after ) {
			return false;
		}
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
	protected function run_statement( $conn, $sql ) {
		$this->executed[] = $sql;
		if ( null !== $this->transient_fail_on && $this->fail_transient_remaining > 0 && strpos( $sql, $this->transient_fail_on ) !== false ) {
			--$this->fail_transient_remaining;
			$this->last_errno = 2006;
			return 'MySQL server has gone away';
		}
		if ( null !== $this->fail_on && strpos( $sql, $this->fail_on ) !== false ) {
			$this->last_errno = 0;
			return 'forced failure for test';
		}
		$this->last_errno = 0;
		return 1;
	}

	/**
	 * Report the injected error number (the fake connection is a stdClass).
	 *
	 * @param object $conn Connection.
	 *
	 * @return int
	 */
	protected function connection_errno( $conn ) {
		return $this->last_errno;
	}

	/**
	 * Whether a SHOW TABLES query reports a failure (null), modelling a
	 * connection that cannot enumerate the live tables.
	 *
	 * @var bool
	 */
	public $fail_show_tables = false;

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
			if ( $this->fail_show_tables ) {
				return null;
			}
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
	 * Log maintenance entry. Public to match the parent's public
	 * begin_maintenance(), which the runner calls cross-class.
	 */
	public function begin_maintenance() {
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
 * Engine that records the config handed to replay_slice instead of
 * running it, so the rollback's prefix handling can be asserted without a
 * live database.
 */
class Capturing_Rollback_Engine extends Testable_Restore_Engine {

	/**
	 * Configs passed to replay_slice, in order.
	 *
	 * @var array[]
	 */
	public $replay_configs = array();

	/**
	 * Record the config; report success without touching anything.
	 *
	 * @param array     $cursor   Cursor.
	 * @param array     $config   Config.
	 * @param float|int $deadline Deadline.
	 * @param bool      $unclean  Unclean resume flag.
	 *
	 * @return array Slice result.
	 */
	public function replay_slice( $cursor, $config, $deadline, $unclean = false ) {
		$this->replay_configs[] = $config;
		return array(
			'done'       => true,
			'cursor'     => array( 'tables' => array() ),
			'error'      => null,
			'error_code' => null,
		);
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
		// The in-place replay path writes a real .maintenance flag through the
		// engine; clear it so it never bleeds into the next test's WP bootstrap.
		$maintenance = ABSPATH . '.maintenance';
		if ( file_exists( $maintenance ) ) {
			unlink( $maintenance ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test cleanup of a flag the engine wrote.
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

		// The best-effort timeout tuning runs after the mandatory three.
		$this->assertStringContainsString( 'wait_timeout', $this->engine->executed[3] );
		$this->assertStringContainsString( 'net_read_timeout', $this->engine->executed[4] );
		$this->assertStringContainsString( 'net_write_timeout', $this->engine->executed[5] );
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
	 * A transient gone-away error mid-replay reconnects, re-applies the
	 * session, re-executes the failed statement, and completes.
	 */
	public function test_reconnects_and_retries_on_transient_gone_away_error() {
		$files = array(
			$this->write_dump( 'dbBackup-wp_a.sql.php', "CREATE TABLE `wp_a` (id INT);\nINSERT INTO `wp_a` (`id`) VALUES (1);\n" ),
		);

		$this->engine->transient_fail_on        = 'INSERT INTO `tmpabc123_a`';
		$this->engine->fail_transient_remaining = 1;

		$result = $this->engine->replay_slice( array(), $this->config( $files ), $this->no_deadline() );

		$this->assertNull( $result['error'] );
		$this->assertTrue( $result['done'] );
		$this->assertSame( 'ok', $result['cursor']['tables']['a']['status'] );
		$this->assertSame( 2, $this->engine->connect_calls );

		$insert_runs  = 0;
		$session_runs = 0;
		foreach ( $this->engine->executed as $sql ) {
			if ( false !== strpos( $sql, 'INSERT INTO `tmpabc123_a`' ) ) {
				++$insert_runs;
			}
			if ( false !== strpos( $sql, 'ALLOW_INVALID_DATES' ) ) {
				++$session_runs;
			}
		}
		$this->assertSame( 2, $insert_runs, 'The failed statement must run exactly twice: fail, then retry.' );
		$this->assertSame( 2, $session_runs, 'The permissive session must be re-applied on the new connection.' );
	}

	/**
	 * A persistently failing connection exhausts the bounded reconnect
	 * budget and surfaces the original replay failure.
	 */
	public function test_gives_up_after_max_reconnect_attempts_and_marks_table_failed() {
		$files = array(
			$this->write_dump( 'dbBackup-wp_a.sql.php', "CREATE TABLE `wp_a` (id INT);\nINSERT INTO `wp_a` (`id`) VALUES (1);\n" ),
		);

		$this->engine->transient_fail_on        = 'INSERT INTO `tmpabc123_a`';
		$this->engine->fail_transient_remaining = PHP_INT_MAX;

		$result = $this->engine->replay_slice( array(), $this->config( $files ), $this->no_deadline() );

		$this->assertFalse( $result['done'] );
		$this->assertStringContainsString( 'gone away', $result['error'] );
		$this->assertStringContainsString(
			'after ' . MainWP_Backup_Db_Restore_Engine::MAX_RECONNECT_ATTEMPTS . ' reconnect attempts',
			$result['error'],
			'An exhausted reconnect budget must be named in the surfaced error, or a repeat failure is indistinguishable from never retrying.'
		);
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_DB_REPLAY, $result['error_code'] );
		$this->assertSame( 'failed', $result['cursor']['tables']['a']['status'] );

		$max          = MainWP_Backup_Db_Restore_Engine::MAX_RECONNECT_ATTEMPTS;
		$insert_runs  = 0;
		foreach ( $this->engine->executed as $sql ) {
			if ( false !== strpos( $sql, 'INSERT INTO `tmpabc123_a`' ) ) {
				++$insert_runs;
			}
		}
		$this->assertSame( $max + 1, $insert_runs, 'Attempts must be bounded: the initial run plus one per reconnect.' );
		$this->assertSame( $max + 1, $this->engine->connect_calls, 'One initial connect plus one per reconnect.' );
	}

	/**
	 * A dead reconnect is named in the surfaced error so the dashboard can
	 * tell "retried and the server stayed gone" from "never reached retry".
	 */
	public function test_failed_reconnect_is_named_in_the_replay_error() {
		$files = array(
			$this->write_dump( 'dbBackup-wp_a.sql.php', "CREATE TABLE `wp_a` (id INT);\nINSERT INTO `wp_a` (`id`) VALUES (1);\n" ),
		);

		$this->engine->transient_fail_on        = 'INSERT INTO `tmpabc123_a`';
		$this->engine->fail_transient_remaining = PHP_INT_MAX;
		$this->engine->fail_connect_after       = 1;

		$result = $this->engine->replay_slice( array(), $this->config( $files ), $this->no_deadline() );

		$this->assertFalse( $result['done'] );
		$this->assertStringContainsString( 'gone away', $result['error'] );
		$this->assertStringContainsString( 'reconnect attempt 1 failed', $result['error'] );
		$this->assertSame( 'failed', $result['cursor']['tables']['a']['status'] );
	}

	/**
	 * A non-transient statement error aborts without any reconnect.
	 */
	public function test_non_transient_error_never_triggers_reconnect() {
		$files = array(
			$this->write_dump( 'dbBackup-wp_a.sql.php', "CREATE TABLE `wp_a` (id INT);\nINSERT INTO `wp_a` (`id`) VALUES (1);\n" ),
		);

		$this->engine->fail_on = 'INSERT INTO `tmpabc123_a`';
		$result                = $this->engine->replay_slice( array(), $this->config( $files ), $this->no_deadline() );

		$this->assertFalse( $result['done'] );
		$this->assertNotNull( $result['error'] );
		$this->assertStringNotContainsString( 'reconnect', $result['error'], 'An error that never retried must not claim reconnect attempts.' );
		$this->assertSame( 1, $this->engine->connect_calls, 'A plain SQL error must not reconnect.' );

		$insert_runs = 0;
		foreach ( $this->engine->executed as $sql ) {
			if ( false !== strpos( $sql, 'INSERT INTO `tmpabc123_a`' ) ) {
				++$insert_runs;
			}
		}
		$this->assertSame( 1, $insert_runs );
	}

	/**
	 * The atomic swap RENAME is never retried, even on a transient-looking
	 * connection error: a re-executed RENAME after an ambiguous ack loss
	 * would misreport a swap that actually landed.
	 */
	public function test_swap_statement_failure_is_never_retried() {
		$this->engine->live_tables              = array( 'wp_a' );
		$this->engine->transient_fail_on        = 'RENAME TABLE';
		$this->engine->fail_transient_remaining = PHP_INT_MAX;

		$result = $this->engine->swap_tables( array( 'a' ), $this->config( array() ) );

		$this->assertFalse( $result['done'] );
		$this->assertStringContainsString( 'gone away', $result['error'] );
		$this->assertSame( 1, $this->engine->connect_calls, 'The swap must stay one-shot.' );

		$renames = 0;
		foreach ( $this->engine->executed as $sql ) {
			if ( 0 === strpos( $sql, 'RENAME TABLE' ) ) {
				++$renames;
			}
		}
		$this->assertSame( 1, $renames, 'The RENAME must execute exactly once.' );
	}

	/**
	 * The session timeout tuning is best-effort: a host that refuses the
	 * SET must not abort the replay.
	 */
	public function test_session_timeout_tuning_is_best_effort() {
		$files = array(
			$this->write_dump( 'dbBackup-wp_a.sql.php', "CREATE TABLE `wp_a` (id INT);\n" ),
		);

		$this->engine->fail_on = 'wait_timeout';
		$result                = $this->engine->replay_slice( array(), $this->config( $files ), $this->no_deadline() );

		$this->assertNull( $result['error'] );
		$this->assertTrue( $result['done'] );
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
	 * A comment shape servers read in more than one way stops the restore and names the shape,
	 * and a MariaDB executable comment in front of an allowed statement no longer rides along with it.
	 */
	public function test_refused_comment_shape_fails_the_restore_and_names_it() {
		$files  = array(
			$this->write_dump( 'dbBackup-wp_a.sql.php', "CREATE TABLE `wp_a` (id INT);\n/*M!100100 DROP TABLE `live_users` */ CREATE TABLE `wp_b` (id INT);\nINSERT INTO `wp_a` VALUES (1) /*+ x */;\nINSERT INTO `wp_a` VALUES (2);\n" ),
		);
		$result = $this->engine->replay_slice( array(), $this->config( $files ), $this->no_deadline() );

		$this->assertFalse( $result['done'] );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_DB_REPLAY, $result['error_code'] );
		$this->assertStringContainsString( 'optimizer hint', $result['error'] );

		$joined = implode( "\n", $this->engine->executed );
		$this->assertStringContainsString( 'CREATE TABLE `tmpabc123_a`', $joined );
		$this->assertStringNotContainsString( 'live_users', $joined );
		$this->assertStringNotContainsString( 'VALUES (2)', $joined, 'Nothing after the refused statement may run.' );
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

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

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

	/**
	 * peek_table_name() reads the table a dump file leads with, from both
	 * the DROP and the CREATE forms, banner or no banner.
	 */
	public function test_peek_table_name_reads_leading_table() {
		$drop   = $this->write_dump( 'dbBackup-wp_a.sql.php', "DROP TABLE IF EXISTS `wp_a`;\nCREATE TABLE `wp_a` (id INT);\n" );
		$create = $this->write_dump( 'dbBackup-wp_b.sql.php', "CREATE TABLE `wp_b` (id INT);\n" );
		$banner = $this->write_dump( 'dbBackup-wp_c.sql.php', "DROP TABLE IF EXISTS `wp_c`;\n", '-- mainwp-backup-meta: {"tier":2}' );

		$this->assertSame( 'wp_a', $this->engine->peek_table_name( $drop ) );
		$this->assertSame( 'wp_b', $this->engine->peek_table_name( $create ) );
		$this->assertSame( 'wp_c', $this->engine->peek_table_name( $banner ) );
	}

	/**
	 * A file naming no table returns null.
	 */
	public function test_peek_table_name_null_when_absent() {
		$file = $this->write_dump( 'dbBackup-empty.sql.php', "SET FOREIGN_KEY_CHECKS=0;\n" );
		$this->assertNull( $this->engine->peek_table_name( $file ) );
	}

	/**
	 * An unpaired interior backtick or an unterminated identifier cannot name a snapshot.
	 */
	public function test_peek_table_name_rejects_malformed_header() {
		foreach ( array( 'DROP TABLE IF EXISTS `wp_a`b`;', 'DROP TABLE IF EXISTS `wp_a``;' ) as $sql ) {
			$file = $this->write_dump( 'dbBackup-malformed.sql.php', $sql . "\n" );
			$this->assertNull( $this->engine->peek_table_name( $file ) );
		}
	}

	/**
	 * A SQL-legal table name that sanitize_filename() would alter (here a
	 * space) still names a real live table the replay overwrites in place, so
	 * it must be snapshotted, not skipped: the peek reads the name from the
	 * DDL content, quote_ident() makes every downstream use inert, and a
	 * dropped entry would leave that table unrollbackable on a replay failure.
	 */
	public function test_list_dump_live_tables_snapshots_filename_altering_stem() {
		$this->engine->live_tables = array( 'wp_a b' );
		$files                     = array(
			$this->write_dump( 'dbBackup-wp_ab.sql.php', "DROP TABLE IF EXISTS `wp_a b`;\n" ),
		);

		$split = $this->engine->list_dump_live_tables( $files, 'wp_', 'wp_' );
		$this->assertSame( array( 'wp_a b' ), $split['existing'] );
		$this->assertSame( array(), $split['new_stems'] );
	}

	/**
	 * The dump's tables split into live tables that exist and stems the
	 * dump would create fresh; the live names use the target prefix.
	 */
	public function test_list_dump_live_tables_splits_existing_and_new() {
		$this->engine->live_tables = array( 'wp_a', 'wp_options', 'wp_a`b' );
		$files                     = array(
			$this->write_dump( 'dbBackup-wp_a.sql.php', "CREATE TABLE `wp_a` (id INT);\n" ),
			$this->write_dump( 'dbBackup-wp_tick.sql.php', "CREATE TABLE `wp_a``b` (id INT);\n" ),
			$this->write_dump( 'dbBackup-wp_new.sql.php', "CREATE TABLE `wp_new` (id INT);\n" ),
		);

		$result = $this->engine->list_dump_live_tables( $files, 'wp_', 'wp_', array() );

		$this->assertSame( array( 'wp_a', 'wp_a`b' ), $result['existing'] );
		$this->assertSame( array( 'new' ), $result['new_stems'] );
	}

	/**
	 * Skip-list stems are excluded from both buckets.
	 */
	public function test_list_dump_live_tables_excludes_skip_stems() {
		$this->engine->live_tables = array( 'wp_a', 'wp_mainwp_child_backup_task' );
		$files                     = array(
			$this->write_dump( 'dbBackup-wp_a.sql.php', "CREATE TABLE `wp_a` (id INT);\n" ),
			$this->write_dump( 'dbBackup-wp_spine.sql.php', "CREATE TABLE `wp_mainwp_child_backup_task` (id INT);\n" ),
		);

		$result = $this->engine->list_dump_live_tables( $files, 'wp_', 'wp_', array( 'mainwp_child_backup_task' ) );

		$this->assertSame( array( 'wp_a' ), $result['existing'] );
		$this->assertSame( array(), $result['new_stems'] );
	}

	/**
	 * A prefix-changing restore maps the dump's source-prefixed stems onto
	 * the live target prefix.
	 */
	public function test_list_dump_live_tables_maps_changed_prefix() {
		$this->engine->live_tables = array( 'live_a' );
		$files                     = array(
			$this->write_dump( 'dbBackup-old_a.sql.php', "CREATE TABLE `old_a` (id INT);\n" ),
			$this->write_dump( 'dbBackup-old_b.sql.php', "CREATE TABLE `old_b` (id INT);\n" ),
		);

		$result = $this->engine->list_dump_live_tables( $files, 'old_', 'live_', array() );

		$this->assertSame( array( 'live_a' ), $result['existing'] );
		$this->assertSame( array( 'b' ), $result['new_stems'] );
	}

	/**
	 * A failed connection must not read as an empty live database. If it did,
	 * every dump table would classify as new, the snapshot would be skipped as
	 * a fresh install, and the in-place replay would run with no rollback
	 * source. The enumeration failure aborts the snapshot with a coded error.
	 */
	public function test_list_dump_live_tables_throws_on_connect_failure() {
		$this->engine->fail_connect_after = 0;
		$files                            = array(
			$this->write_dump( 'dbBackup-wp_a.sql.php', "CREATE TABLE `wp_a` (id INT);\n" ),
		);

		try {
			$this->engine->list_dump_live_tables( $files, 'wp_', 'wp_', array() );
			$this->fail( 'A failed connect must abort with a snapshot error, not report an empty live set.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_SNAPSHOT_FAILED, $e->get_message_error_code() );
		}
	}

	/**
	 * A SHOW TABLES that fails on an open connection is likewise an enumeration
	 * failure, not an empty database, so it aborts with the same coded error
	 * rather than treating the target as a fresh install.
	 */
	public function test_list_dump_live_tables_throws_on_enumeration_failure() {
		$this->engine->fail_show_tables = true;
		$files                          = array(
			$this->write_dump( 'dbBackup-wp_a.sql.php', "CREATE TABLE `wp_a` (id INT);\n" ),
		);

		try {
			$this->engine->list_dump_live_tables( $files, 'wp_', 'wp_', array() );
			$this->fail( 'A failed SHOW TABLES must abort with a snapshot error, not report an empty live set.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_SNAPSHOT_FAILED, $e->get_message_error_code() );
		}
	}

	/**
	 * The non-FK swap path enumerates live tables too; a failed SHOW TABLES
	 * there would turn its in_array() guards into a no-op and let the swap
	 * skip live tables it must rename aside, so it aborts with a coded swap
	 * failure instead of a silent partial swap.
	 */
	public function test_swap_tables_aborts_on_enumeration_failure() {
		$this->engine->fail_show_tables = true;

		$result = $this->engine->swap_tables(
			array( 'a' ),
			array( 'restore_id' => 'abc123', 'target_prefix' => 'wp_' )
		);

		$this->assertFalse( $result['done'] );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_SWAP_FAILED, $result['error_code'] );
		$this->assertSame( array(), $result['old_tables'] );
	}

	/**
	 * A genuinely empty live database (SHOW TABLES succeeds, returns no rows)
	 * is not an error: every dump table classifies as new and the split is
	 * empty-existing, so the fresh-install path stays available.
	 */
	public function test_list_dump_live_tables_empty_live_is_not_a_failure() {
		$this->engine->live_tables = array();
		$files                     = array(
			$this->write_dump( 'dbBackup-wp_a.sql.php', "CREATE TABLE `wp_a` (id INT);\n" ),
		);

		$result = $this->engine->list_dump_live_tables( $files, 'wp_', 'wp_', array() );

		$this->assertSame( array(), $result['existing'] );
		$this->assertSame( array( 'a' ), $result['new_stems'] );
	}

	/**
	 * The grant probe now also exercises INSERT and SELECT, the rollback's
	 * refill grants.
	 */
	public function test_probe_swap_grants_exercises_insert_and_select() {
		$this->assertTrue( $this->engine->probe_swap_grants() );

		$joined = implode( "\n", $this->engine->executed );
		$this->assertStringContainsString( 'INSERT INTO `mainwp_restore_preflight_', $joined );
	}

	/**
	 * A denied INSERT fails the probe.
	 */
	public function test_probe_swap_grants_false_when_insert_denied() {
		$this->engine->fail_on = 'INSERT INTO `mainwp_restore_preflight_';
		$this->assertFalse( $this->engine->probe_swap_grants() );
	}

	/**
	 * Rollback drops every dump-stem table first, then replays the aside
	 * dump with the live prefix on BOTH sides - the origin prefix would
	 * make the rewriter discard every aside statement.
	 */
	public function test_rollback_aside_slice_drops_then_replays_with_live_prefix() {
		$engine = new Capturing_Rollback_Engine();

		$result = $engine->rollback_aside_slice(
			array(),
			array(
				'drop_tables'   => array( 'live_a', 'live_b' ),
				'aside_files'   => array( '/tmp/aside-live_a.sql.php' ),
				'target_prefix' => 'live_',
				'restore_id'    => 'rid',
				'skip_stems'    => array(),
			),
			microtime( true ) + 3600
		);

		$this->assertTrue( $result['done'] );

		$joined = implode( "\n", $engine->executed );
		$this->assertStringContainsString( 'SET FOREIGN_KEY_CHECKS=0', $joined );
		$this->assertStringContainsString( 'DROP TABLE IF EXISTS `live_a`', $joined );
		$this->assertStringContainsString( 'DROP TABLE IF EXISTS `live_b`', $joined );

		$this->assertCount( 1, $engine->replay_configs );
		$config = $engine->replay_configs[0];
		$this->assertSame( 'live_', $config['source_prefix'] );
		$this->assertSame( 'live_', $config['target_prefix'] );
		$this->assertTrue( $config['fk_path'] );
		$this->assertSame( array( '/tmp/aside-live_a.sql.php' ), $config['files'] );
	}

	/**
	 * A drop failure during rollback surfaces ERR_RESTORE_ROLLBACK_FAILED
	 * and never reaches the replay.
	 */
	public function test_rollback_aside_slice_drop_failure_reports_rollback_failed() {
		$engine          = new Capturing_Rollback_Engine();
		$engine->fail_on = 'DROP TABLE IF EXISTS `live_a`';

		$result = $engine->rollback_aside_slice(
			array(),
			array(
				'drop_tables'   => array( 'live_a' ),
				'aside_files'   => array( '/tmp/aside-live_a.sql.php' ),
				'target_prefix' => 'live_',
				'restore_id'    => 'rid',
				'skip_stems'    => array(),
			),
			microtime( true ) + 3600
		);

		$this->assertFalse( $result['done'] );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_ROLLBACK_FAILED, $result['error_code'] );
		$this->assertEmpty( $engine->replay_configs );
	}

	/**
	 * Real-database foreign-key round trip: snapshot two FK-linked InnoDB
	 * tables aside, destructively mutate the live tables (as a failed
	 * in-place replay would), then roll back and confirm the original rows
	 * and the foreign-key constraint return, and a table the failed replay
	 * created is gone.
	 */
	public function test_fk_snapshot_and_rollback_round_trip() {
		global $wpdb;

		// Real (not TEMPORARY) tables: the dumper reads on its own connection
		// and temporary tables cannot carry foreign keys, so the test opts out
		// of the harness's temporary-table rewriting and cleans up by hand.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$prefix = 'fkt_';
		$parent = $prefix . 'parent';
		$child  = $prefix . 'child';
		$orphan = $prefix . 'orphan';
		// Child (the referencing table) drops first so tear_down is FK-safe.
		$this->real_tables = array( $child, $orphan, $parent );

		$wpdb->query( "DROP TABLE IF EXISTS `$child`" ); // phpcs:ignore WordPress.DB -- test fixture.
		$wpdb->query( "DROP TABLE IF EXISTS `$orphan`" ); // phpcs:ignore WordPress.DB -- test fixture.
		$wpdb->query( "DROP TABLE IF EXISTS `$parent`" ); // phpcs:ignore WordPress.DB -- test fixture.
		$wpdb->query( "CREATE TABLE `$parent` (id INT NOT NULL, label VARCHAR(40), PRIMARY KEY (id)) ENGINE=InnoDB" ); // phpcs:ignore WordPress.DB -- test fixture.
		$wpdb->query( "CREATE TABLE `$child` (id INT NOT NULL, pid INT, body TEXT, PRIMARY KEY (id), KEY pid (pid), CONSTRAINT `fkt_link` FOREIGN KEY (pid) REFERENCES `$parent` (id)) ENGINE=InnoDB" ); // phpcs:ignore WordPress.DB -- test fixture.
		$wpdb->query( "INSERT INTO `$parent` (id,label) VALUES (1,'one'),(2,'two')" ); // phpcs:ignore WordPress.DB -- test fixture.
		$wpdb->query( "INSERT INTO `$child` (id,pid,body) VALUES (10,1,'a'),(11,2,'b')" ); // phpcs:ignore WordPress.DB -- test fixture.
		// The dumper reads on its own connection, so the fixtures must commit.
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB -- cross-connection visibility.

		$engine = new MainWP_Backup_Db_Restore_Engine();

		// Snapshot the live tables aside.
		$cursor = array();
		$files  = array();
		$guard  = 0;
		do {
			$snap   = $engine->snapshot_tables_slice(
				$cursor,
				array(
					'tables'       => array( $parent, $child ),
					'aside_prefix' => $this->dir . '/aside',
				),
				microtime( true ) + 3600
			);
			$cursor = $snap['cursor'];
			$files  = $snap['files'];
			$this->assertNull( $snap['error'] );
		} while ( ! $snap['done'] && ++$guard < 50 );
		$this->assertTrue( $snap['done'] );
		$this->assertNotEmpty( $files );

		// Simulate the damage a failed in-place replay leaves behind.
		$wpdb->query( "DELETE FROM `$child`" ); // phpcs:ignore WordPress.DB -- corruption fixture.
		$wpdb->query( "DELETE FROM `$parent`" ); // phpcs:ignore WordPress.DB -- corruption fixture.
		$wpdb->query( "INSERT INTO `$parent` (id,label) VALUES (99,'corrupt')" ); // phpcs:ignore WordPress.DB -- corruption fixture.
		$wpdb->query( "CREATE TABLE `$orphan` (id INT) ENGINE=InnoDB" ); // phpcs:ignore WordPress.DB -- corruption fixture.
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB -- cross-connection visibility.

		// Roll back from the aside snapshot.
		$rcursor = array();
		$guard   = 0;
		do {
			$rb      = $engine->rollback_aside_slice(
				$rcursor,
				array(
					'drop_tables'   => array( $child, $parent, $orphan ),
					'aside_files'   => $files,
					'target_prefix' => $prefix,
					'restore_id'    => 'fktid',
					'skip_stems'    => array(),
				),
				microtime( true ) + 3600
			);
			$rcursor = $rb['cursor'];
			$this->assertNull( $rb['error'] );
		} while ( ! $rb['done'] && ++$guard < 50 );
		$this->assertTrue( $rb['done'] );
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB -- read fresh after the rollback connection committed.

		$prows = $wpdb->get_results( "SELECT id,label FROM `$parent` ORDER BY id", ARRAY_A ); // phpcs:ignore WordPress.DB -- verification.
		$this->assertCount( 2, $prows );
		$this->assertSame( 'one', $prows[0]['label'] );

		$crows = $wpdb->get_results( "SELECT id,pid,body FROM `$child` ORDER BY id", ARRAY_A ); // phpcs:ignore WordPress.DB -- verification.
		$this->assertCount( 2, $crows );
		$this->assertSame( 'b', $crows[1]['body'] );

		$create = $wpdb->get_row( "SHOW CREATE TABLE `$child`", ARRAY_N ); // phpcs:ignore WordPress.DB -- verification.
		$this->assertStringContainsString( 'FOREIGN KEY', $create[1] );

		$this->assertEmpty( $wpdb->get_var( "SHOW TABLES LIKE '$orphan'" ) ); // phpcs:ignore WordPress.DB -- verification.
	}

	public function test_fk_snapshot_restores_backtick_table_after_replay_failure() {
		global $wpdb;
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$table = 'fkt_a`b';
		$quoted = '`fkt_a``b`';
		$engine = new MainWP_Backup_Db_Restore_Engine();
		try {
			$this->assertNotFalse( $wpdb->query( 'DROP TABLE IF EXISTS ' . $quoted ) );
			$this->assertNotFalse( $wpdb->query( 'CREATE TABLE ' . $quoted . ' (id INT NOT NULL, body LONGBLOB, PRIMARY KEY (id)) ENGINE=InnoDB' ) );
			$this->assertSame( 1, $wpdb->query( "INSERT INTO $quoted (id, body) VALUES (1, UNHEX('006162ff6027'))" ) );
			$this->assertNotFalse( $wpdb->query( 'COMMIT' ) );
			$before = $wpdb->get_results( 'SELECT * FROM ' . $quoted . ' ORDER BY id', ARRAY_A );
			$schema = $wpdb->get_row( 'SHOW CREATE TABLE ' . $quoted, ARRAY_N );
			// The reads above opened an implicit transaction (autocommit is off);
			// release its metadata lock before the engine's dedicated connection
			// runs DDL on the same table, or it waits out MySQL's lock_wait_timeout.
			$this->assertNotFalse( $wpdb->query( 'COMMIT' ) );
			$file = $this->write_dump( 'dbBackup-src_tick.sql.php', "DROP TABLE IF EXISTS `src_a``b`;\nCREATE TABLE `src_a``b` (id INT NOT NULL, body LONGBLOB, PRIMARY KEY (id)) ENGINE=InnoDB;\nINSERT INTO `src_a``b` VALUES (9, 'replacement');\nINSERT INTO `src_a``b` (missing_column) VALUES (1);\n" );
			$inventory = $engine->list_dump_live_tables( array( $file ), 'src_', 'fkt_' );
			$this->assertSame( array( $table ), $inventory['existing'] );
			$this->assertSame( array(), $inventory['new_stems'] );
			$snap = array( 'cursor' => array() );
			$guard = 0;
			do {
				$snap = $engine->snapshot_tables_slice( $snap['cursor'], array( 'tables' => $inventory['existing'], 'aside_prefix' => $this->dir . '/aside' ), microtime( true ) + 3600 );
				$this->assertNull( $snap['error'] );
			} while ( ! $snap['done'] && ++$guard < 50 );
			$this->assertTrue( $snap['done'] );
			$this->assertNotEmpty( $snap['files'] );
			// The tier-2 dumper behind snapshot_tables_slice() reads schema and rows
			// through the shared $wpdb handle (SHOW CREATE TABLE, SELECT ...), which
			// left the same implicit transaction open; release it before replay_slice's
			// dedicated connection drops/recreates the table.
			$this->assertNotFalse( $wpdb->query( 'COMMIT' ) );
			$result = $engine->replay_slice( array(), array( 'restore_id' => 'fktick', 'source_prefix' => 'src_', 'target_prefix' => 'fkt_', 'files' => array( $file ), 'fk_path' => true ), microtime( true ) + 3600 );
			$this->assertFalse( $result['done'] );
			$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_DB_REPLAY, $result['error_code'] );
			$this->assertSame( 'failed', $result['cursor']['tables']['a`b']['status'] );
			$this->assertSame( 'replacement', $wpdb->get_var( 'SELECT body FROM ' . $quoted ) );
			// Same lock-release as above: release the read's implicit transaction
			// before the rollback loop's dedicated connection drops/renames the table.
			$this->assertNotFalse( $wpdb->query( 'COMMIT' ) );
			$rollback = array( 'cursor' => array() );
			$guard = 0;
			do {
				$rollback = $engine->rollback_aside_slice( $rollback['cursor'], array( 'drop_tables' => $inventory['existing'], 'aside_files' => $snap['files'], 'target_prefix' => 'fkt_', 'restore_id' => 'fktick', 'skip_stems' => array() ), microtime( true ) + 3600 );
				$this->assertNull( $rollback['error'] );
			} while ( ! $rollback['done'] && ++$guard < 50 );
			$this->assertTrue( $rollback['done'] );
			$this->assertNotFalse( $wpdb->query( 'COMMIT' ) );
			$this->assertSame( $before, $wpdb->get_results( 'SELECT * FROM ' . $quoted . ' ORDER BY id', ARRAY_A ) );
			$this->assertSame( $schema, $wpdb->get_row( 'SHOW CREATE TABLE ' . $quoted, ARRAY_N ) );
		} finally {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . $quoted );
			$engine->exit_maintenance();
		}
	}

	/**
	 * prune_aside_tables drops the old/tmp leftovers of the given restore ids
	 * in one pass and never touches a table outside that id set - not an
	 * unrelated `old...`-named table, not a live table.
	 */
	public function test_prune_aside_tables_drops_only_the_given_restore_ids() {
		global $wpdb;
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$this->real_tables = array( 'oldabc123_x', 'tmpabc123_x', 'olddef456_y', 'oldzz_keep', 'keepme_live' );
		foreach ( $this->real_tables as $table ) {
			$wpdb->query( "CREATE TABLE `$table` ( id INT )" ); // phpcs:ignore WordPress.DB -- test fixture.
		}

		( new MainWP_Backup_Db_Restore_Engine() )->prune_aside_tables( array( 'abc123', 'def456' ) );

		$tables = $wpdb->get_col( 'SHOW TABLES' ); // phpcs:ignore WordPress.DB -- verification.
		$this->assertNotContains( 'oldabc123_x', $tables, 'aside table for a swept id is dropped' );
		$this->assertNotContains( 'tmpabc123_x', $tables, 'staging table for a swept id is dropped' );
		$this->assertNotContains( 'olddef456_y', $tables, 'second swept id is dropped' );
		$this->assertContains( 'oldzz_keep', $tables, 'an unrelated old-named table outside the id set survives' );
		$this->assertContains( 'keepme_live', $tables, 'a live table is never matched' );
	}

	/**
	 * An empty id set is a no-op: nothing is dropped.
	 */
	public function test_prune_aside_tables_empty_set_is_noop() {
		global $wpdb;
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$this->real_tables = array( 'oldabc123_x' );
		$wpdb->query( 'CREATE TABLE `oldabc123_x` ( id INT )' ); // phpcs:ignore WordPress.DB -- test fixture.

		( new MainWP_Backup_Db_Restore_Engine() )->prune_aside_tables( array() );

		$this->assertContains( 'oldabc123_x', $wpdb->get_col( 'SHOW TABLES' ) ); // phpcs:ignore WordPress.DB -- verification.
	}

	/**
	 * A blank restore id collapses old<id>_/tmp<id>_ to the bare prefixes
	 * old_/tmp_, which would match unrelated user tables. The guard must skip
	 * blank ids so a stray empty value (e.g. an unset restore_id param reaching
	 * the caller as (string) null) cannot drop tables outside this engine's own
	 * aside/staging namespace.
	 */
	public function test_prune_aside_tables_skips_blank_restore_ids() {
		global $wpdb;
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$this->real_tables = array( 'oldabc123_x', 'tmpabc123_x', 'old_collateral', 'tmp_collateral', 'keepme_live' );
		foreach ( $this->real_tables as $table ) {
			$wpdb->query( "CREATE TABLE `$table` ( id INT )" ); // phpcs:ignore WordPress.DB -- test fixture.
		}

		// A blank id alongside a valid one: the blank must be skipped while the
		// valid id is still pruned.
		( new MainWP_Backup_Db_Restore_Engine() )->prune_aside_tables( array( '', 'abc123' ) );

		$tables = $wpdb->get_col( 'SHOW TABLES' ); // phpcs:ignore WordPress.DB -- verification.
		$this->assertNotContains( 'oldabc123_x', $tables, 'the valid id is still pruned when a blank id is also present' );
		$this->assertNotContains( 'tmpabc123_x', $tables, 'the valid id is still pruned when a blank id is also present' );
		$this->assertContains( 'old_collateral', $tables, 'a blank id must not collapse the prefix to old_ and drop an unrelated table' );
		$this->assertContains( 'tmp_collateral', $tables, 'a blank id must not collapse the prefix to tmp_ and drop an unrelated table' );
		$this->assertContains( 'keepme_live', $tables, 'a live table is never matched' );
	}

	/** Replaying a partially imported file resets only its own row count. */
	public function test_unclean_resume_row_counts_match_replayed_file() {
		$engine = new class() extends Testable_Restore_Engine {
			public $actual_rows = 0;
			protected function run_statement( $conn, $sql ) {
				if ( 0 === strpos( $sql, 'DROP TABLE IF EXISTS' ) ) {
					$this->actual_rows = 0;
				} elseif ( 0 === strpos( $sql, 'INSERT INTO' ) ) {
					++$this->actual_rows;
				}
				return parent::run_statement( $conn, $sql );
			}
			protected function query_rows( $conn, $sql ) {
				if ( 0 === strpos( $sql, 'SELECT COUNT(*)' ) ) {
					return array( array( 'c' => $this->actual_rows ) );
				}
				return parent::query_rows( $conn, $sql );
			}
		};
		$sql = "DROP TABLE IF EXISTS `wp_a`;\nCREATE TABLE `wp_a` (id INT);\n";
		for ( $id = 0; $id < 50; ++$id ) {
			$sql .= "INSERT INTO `wp_a` VALUES ($id);\n";
		}
		$config = $this->config( array( $this->write_dump( 'replayed.sql.php', $sql ) ) );
		$result = $engine->replay_slice( array(), $config, 0 );
		while ( empty( $result['cursor']['tables']['a']['rows'] ) ) {
			$this->assertFalse( $result['done'] );
			$result = $engine->replay_slice( $result['cursor'], $config, 0 );
		}
		$this->assertFalse( $result['done'] );
		$result = $engine->replay_slice( $result['cursor'], $config, $this->no_deadline(), true );
		$this->assertTrue( $result['done'] );
		$this->assertSame( 50, $result['cursor']['tables']['a']['rows'] );
		$verified = $engine->verify_row_counts( array( 'a' => $result['cursor']['tables']['a']['rows'] ), 'tmpabc123_' );
		$this->assertTrue( $verified['ok'] );
	}

}
