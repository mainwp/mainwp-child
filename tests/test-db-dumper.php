<?php
/**
 * Class DbDumperTest
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Backup_Db_Dumper;
use MainWP\Child\MainWP_Backup_Budget_Exception;

/**
 * Dumper spy that records topology-guard calls and aborts immediately, so a
 * test can assert the guard fires (or not) without touching the database.
 */
class Topology_Probing_Dumper extends MainWP_Backup_Db_Dumper {

	/**
	 * How many times the topology guard ran.
	 *
	 * @var int
	 */
	public $topology_calls = 0;

	/**
	 * Count the call and abort before any table read.
	 *
	 * @return void
	 */
	public function assert_supported_topology() {
		++$this->topology_calls;
		throw new \RuntimeException( 'topology-checked' );
	}
}

/**
 * Tests for the consistent-snapshot DB dumper and its Tier-2 PK-seek
 * fallback for databases too large to dump in one bounded request.
 */
class DbDumperTest extends WP_UnitTestCase {

	/**
	 * Scratch directory for dump files.
	 *
	 * @var string
	 */
	private $scratch;

	/**
	 * Fixture table name (real, non-temporary - the dedicated connection
	 * cannot see this connection's temporary tables).
	 *
	 * @var string
	 */
	private $fixture_table;

	/**
	 * Rows inserted into the fixture table.
	 *
	 * @var array
	 */
	private $fixture_rows = array();

	/**
	 * Create scratch dir and a fixture table with awkward column types.
	 */
	public function set_up() {
		parent::set_up();
		$this->scratch = sys_get_temp_dir() . '/mwp-dump-test-' . wp_rand( 1000, 999999 );
		mkdir( $this->scratch, 0755, true );

		global $wpdb;
		$this->fixture_table = $wpdb->prefix . 'mwp_dump_fixture';

		// The WP test framework rewrites CREATE TABLE to CREATE TEMPORARY
		// TABLE - invisible to the dumper's dedicated connection and to
		// information_schema. Create the fixture as a real table.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$wpdb->query( "DROP TABLE IF EXISTS `{$this->fixture_table}`" ); // phpcs:ignore -- test fixture.
		$wpdb->query( // phpcs:ignore -- test fixture.
			"CREATE TABLE `{$this->fixture_table}` (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(190) NOT NULL DEFAULT '',
				payload blob,
				flags bit(8) DEFAULT NULL,
				meta json DEFAULT NULL,
				num decimal(10,4) DEFAULT NULL,
				created datetime DEFAULT NULL,
				name_len int GENERATED ALWAYS AS (CHAR_LENGTH(name)) STORED,
				PRIMARY KEY (id)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
		);

		$this->fixture_rows = array(
			array(
				'name'    => "O'Reilly \"quoted\" – emoji 😀",
				'payload' => "\x00\x01\xFF\xFEbinary\x00data",
				'flags'   => 5,
				'meta'    => '{"a": 1, "b": [true, null, "x"]}',
				'num'     => '-12.3450',
				'created' => '2024-02-29 23:59:59',
			),
			array(
				'name'    => 'plain',
				'payload' => null,
				'flags'   => null,
				'meta'    => null,
				'num'     => null,
				'created' => null,
			),
		);

		foreach ( $this->fixture_rows as $row ) {
			$values = array();
			foreach ( array( 'name', 'payload', 'flags', 'meta', 'num', 'created' ) as $col ) {
				if ( null === $row[ $col ] ) {
					$values[] = 'NULL';
				} elseif ( 'flags' === $col ) {
					$values[] = (int) $row[ $col ];
				} else {
					$values[] = "'" . esc_sql( $row[ $col ] ) . "'";
				}
			}
			$wpdb->query( "INSERT INTO `{$this->fixture_table}` (name, payload, flags, meta, num, created) VALUES (" . implode( ', ', $values ) . ')' ); // phpcs:ignore -- test fixture.
		}

		// The framework wraps each test in an uncommitted transaction; the
		// dumper's dedicated connection only sees committed data.
		$wpdb->query( 'COMMIT' ); // phpcs:ignore -- test fixture visibility for the second connection.
	}

	/**
	 * Drop fixtures and scratch files.
	 */
	public function tear_down() {
		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS `{$this->fixture_table}`" ); // phpcs:ignore -- test cleanup.
		foreach ( (array) glob( $this->scratch . '/*' ) as $file ) {
			unlink( $file );
		}
		if ( is_dir( $this->scratch ) ) {
			rmdir( $this->scratch );
		}
		parent::tear_down();
	}

	/**
	 * The dedicated second connection preflight succeeds on this host.
	 */
	public function test_dedicated_connection_preflight() {
		$result = MainWP_Backup_Db_Dumper::instance()->check_dedicated_connection();
		$this->assertTrue( $result['ok'], 'Preflight failed: ' . ( isset( $result['reason'] ) ? $result['reason'] : '' ) );
	}

	/**
	 * A failed preflight never leaks credentials in its reason.
	 */
	public function test_preflight_failure_scrubs_credentials() {
		$result = MainWP_Backup_Db_Dumper::instance()->check_dedicated_connection(
			array(
				'host'     => '127.0.0.1',
				'port'     => 1, // Nothing listens here.
				'socket'   => null,
				'user'     => DB_USER,
				'password' => DB_PASSWORD,
				'name'     => DB_NAME,
			)
		);
		$this->assertFalse( $result['ok'] );
		$this->assertNotEmpty( $result['reason'] );
		if ( '' !== DB_PASSWORD ) {
			$this->assertStringNotContainsString( DB_PASSWORD, $result['reason'] );
		}
		$this->assertStringNotContainsString( DB_USER, $result['reason'] );
	}

	/**
	 * The Tier-1 dump produces guarded per-table .sql.php files with the
	 * legacy naming, a metadata banner, and explicit column lists.
	 */
	public function test_dump_creates_guarded_per_table_files() {
		$result = MainWP_Backup_Db_Dumper::instance()->dump_to_files( $this->scratch . '/dbBackup' );

		$this->assertIsArray( $result['files'] );
		$this->assertNotEmpty( $result['files'] );
		$this->assertContains( $result['consistency'], array( 'consistent', 'mixed' ) );
		$this->assertArrayHasKey( 'has_foreign_keys', $result );
		$this->assertSame( 1, $result['tier'] );

		$fixture_file = $this->scratch . '/dbBackup-' . $this->fixture_table . '.sql.php';
		$this->assertContains( $fixture_file, $result['files'] );
		$this->assertFileExists( $fixture_file );

		$content = file_get_contents( $fixture_file );
		$this->assertStringStartsWith( '<?php exit(); ?>', $content );
		$this->assertStringContainsString( 'DROP TABLE IF EXISTS', $content );
		$this->assertStringContainsString( 'CREATE TABLE', $content );
		// Explicit column list excluding the generated column.
		$this->assertStringContainsString( 'INSERT INTO `' . $this->fixture_table . '` (`id`, `name`, `payload`, `flags`, `meta`, `num`, `created`)', $content );
		$this->assertStringNotContainsString( '`name_len`', substr( $content, strpos( $content, 'INSERT INTO' ) ) );
		// Blob emitted as hex, bit as b'...'.
		$this->assertStringContainsString( '0x0001fffe', strtolower( $content ) );
		$this->assertStringContainsString( "b'00000101'", $content );

		// The first dump file carries the restorer banner and metadata.
		$first   = $result['files'][0];
		$banner  = file_get_contents( $first );
		$this->assertStringContainsString( 'mainwp-backup-meta:', $banner );
		$this->assertStringContainsString( 'table_prefix', $banner );
	}

	/**
	 * Restoring the dumped SQL reproduces the fixture rows byte-for-byte -
	 * including binary blobs, bit fields, JSON and quotes.
	 */
	public function test_dump_round_trip_restores_data() {
		global $wpdb;

		$result       = MainWP_Backup_Db_Dumper::instance()->dump_to_files( $this->scratch . '/dbBackup' );
		$fixture_file = $this->scratch . '/dbBackup-' . $this->fixture_table . '.sql.php';
		$this->assertContains( $fixture_file, $result['files'] );

		$original = $wpdb->get_results( "SELECT id, name, payload, flags, meta, num, created FROM `{$this->fixture_table}` ORDER BY id", ARRAY_A ); // phpcs:ignore -- test assertion.

		// The test framework runs with autocommit=0, so the SELECT above holds
		// a metadata lock on the fixture table that would block the restore
		// connection's DROP TABLE forever. Release it.
		$wpdb->query( 'COMMIT' ); // phpcs:ignore -- release metadata locks for the second connection.

		// Restore: strip the guard, execute the statements (DROP + CREATE + INSERTs).
		$sql = file_get_contents( $fixture_file );
		$sql = preg_replace( '/^<\?php exit\(\); \?>/', '', $sql );
		$sql = preg_replace( '/^\s*--.*$/m', '', $sql );

		$mysqli = mysqli_init();
		$host   = $wpdb->parse_db_host( DB_HOST );
		$mysqli->real_connect( $host[0], DB_USER, DB_PASSWORD, DB_NAME, null === $host[1] ? 0 : (int) $host[1], $host[2] );
		$mysqli->set_charset( 'utf8mb4' );
		$this->assertTrue( $mysqli->multi_query( $sql ), 'Restore failed: ' . $mysqli->error );
		while ( $mysqli->more_results() && $mysqli->next_result() ) {
			// Drain multi_query results.
			$res = $mysqli->store_result();
			if ( $res ) {
				$res->free();
			}
		}
		$this->assertSame( '', $mysqli->error, 'Restore reported: ' . $mysqli->error );
		$mysqli->close();

		$restored = $wpdb->get_results( "SELECT id, name, payload, flags, meta, num, created FROM `{$this->fixture_table}` ORDER BY id", ARRAY_A ); // phpcs:ignore -- test assertion.
		$this->assertSame( $original, $restored );
	}

	/**
	 * A zero time budget makes Tier 1 fail fast with the budget exception so
	 * the caller can auto-downgrade to Tier 2 after wasting at most one request.
	 */
	public function test_budget_overrun_raises_budget_exception() {
		$this->expectException( MainWP_Backup_Budget_Exception::class );
		MainWP_Backup_Db_Dumper::instance()->dump_to_files( $this->scratch . '/dbBackup', 0 );
	}

	/**
	 * The Tier-2 slice dump resumes from its cursor across calls and labels
	 * the result as paginated (not point-in-time).
	 */
	public function test_tier2_slice_dump_resumes_and_completes() {
		$dumper = MainWP_Backup_Db_Dumper::instance();

		$cursor = null;
		$rounds = 0;
		do {
			$slice  = $dumper->dump_tier2_slice( $this->scratch . '/dbBackup', $cursor, 0 );
			$cursor = $slice['cursor'];
			++$rounds;
		} while ( ! $slice['done'] && $rounds < 1000 );

		$this->assertTrue( $slice['done'] );
		$this->assertGreaterThan( 1, $rounds, 'A zero budget must take multiple slices' );
		$this->assertSame( 2, $slice['tier'] );
		$this->assertSame( 'paginated', $slice['consistency'] );

		$fixture_file = $this->scratch . '/dbBackup-' . $this->fixture_table . '.sql.php';
		$this->assertFileExists( $fixture_file );
		$content = file_get_contents( $fixture_file );
		$this->assertStringStartsWith( '<?php exit(); ?>', $content );
		$this->assertStringContainsString( 'INSERT INTO', $content );
	}

	/**
	 * A failed row read must not finalize a schema-only backup.
	 */
	public function test_tier2_query_failure_does_not_finalize_table() {
		global $wpdb;
		$dumper = MainWP_Backup_Db_Dumper::instance();
		$prefix = $this->scratch . '/dbBackup';
		$tables = array( $this->fixture_table );
		$slice  = $dumper->dump_tables_tier2_slice( $tables, $prefix, array(), 0 );
		$this->assertFalse( $slice['done'] );
		$this->assertTrue( $slice['cursor']['started'] );
		$final = $prefix . '-' . $this->fixture_table . '.sql.php';
		$filter = function ( $sql ) {
			if ( false !== strpos( $sql, $this->fixture_table ) && false !== stripos( $sql, 'LIMIT' ) ) {
				return 'SELECT * FROM `' . $this->fixture_table . '_missing`';
			}
			return $sql;
		};
		$failure = null;
		$errors  = $wpdb->suppress_errors( true );
		add_filter( 'query', $filter );
		try {
			$slice = $dumper->dump_tables_tier2_slice( $tables, $prefix, $slice['cursor'], 0 );
		} catch ( \MainWP\Child\MainWP_Exception $e ) {
			$failure = $e;
		} finally {
			$db_error = $wpdb->last_error;
			remove_filter( 'query', $filter );
			$wpdb->suppress_errors( $errors );
		}

		$this->assertInstanceOf( \MainWP\Child\MainWP_Exception::class, $failure );
		$this->assertSame( \MainWP\Child\MainWP_Backup_Error::ERR_DB_QUERY_FAILED, $failure->get_message_error_code() );
		$this->assertNotEmpty( $db_error );
		$this->assertStringContainsString( esc_html( \MainWP\Child\MainWP_Backup_Error::scrub_db_credentials( $db_error ) ), $failure->getMessage() );
		$this->assertStringContainsString( $this->fixture_table, $failure->getMessage() );
		$this->assertStringNotContainsString( 'SELECT', $failure->getMessage() );
		$this->assertFalse( $slice['done'] );
		$this->assertSame( 0, $slice['cursor']['index'] );
		$this->assertSame( array(), $slice['files'] );
		$this->assertFileDoesNotExist( $final );
		$this->assertFileDoesNotExist( $final . '.tmp' );

		// The cleaned fragment restarts the table, which costs one extra slice at a zero budget.
		for ( $i = 0; $i < 3 && empty( $slice['done'] ); $i++ ) {
			$slice = $dumper->dump_tables_tier2_slice( $tables, $prefix, $slice['cursor'], 0 );
		}
		$this->assertTrue( $slice['done'] );
		$this->assertSame( array( $final ), $slice['files'] );
		$this->assertStringContainsString( 'CREATE TABLE', file_get_contents( $final ) );
		$this->assertStringContainsString( 'INSERT INTO', file_get_contents( $final ) );
	}

	/**
	 * A whole-DB Tier-2 dump (null cursor) must run the topology guard before
	 * reading $wpdb, so a sharded/replicated site cannot silently dump one
	 * shard through the dedicated-connection fallback.
	 *
	 * @return void
	 */
	public function test_tier2_whole_db_runs_topology_guard() {
		$dumper = new Topology_Probing_Dumper();

		$threw = false;
		try {
			$dumper->dump_tier2_slice( $this->scratch . '/dbBackup', null, 0 );
		} catch ( \RuntimeException $e ) {
			$threw = true;
		}

		$this->assertTrue( $threw, 'The guard must abort the whole-DB Tier-2 dump.' );
		$this->assertSame( 1, $dumper->topology_calls, 'Topology must be checked once before any table read.' );
	}

	/**
	 * The FK-rollback aside dump seeds its own cursor and must NOT trip the
	 * whole-DB topology guard - it dumps an explicit, already-known table list.
	 *
	 * @return void
	 */
	public function test_tier2_seeded_cursor_skips_topology_guard() {
		$dumper = new Topology_Probing_Dumper();
		$seeded = array(
			'tables'  => array(),
			'index'   => 0,
			'started' => false,
			'pk_col'  => null,
			'last_id' => 0,
			'offset'  => 0,
			'files'   => array(),
			'first'   => true,
		);

		$result = $dumper->dump_tier2_slice( $this->scratch . '/dbBackup', $seeded, 0 );

		$this->assertTrue( $result['done'] );
		$this->assertSame( 0, $dumper->topology_calls, 'A pre-seeded cursor must skip the whole-DB topology guard.' );
	}

	/**
	 * resolve_client_flags() honors MYSQL_CLIENT_FLAGS so the dump and the
	 * restore connections negotiate SSL identically on hosts that require it;
	 * without the constant it must default to 0 (no flags).
	 *
	 * @return void
	 */
	public function test_resolve_client_flags_honors_constant() {
		$flags = MainWP_Backup_Db_Dumper::resolve_client_flags();
		$this->assertIsInt( $flags );
		if ( defined( 'MYSQL_CLIENT_FLAGS' ) ) {
			$this->assertSame( (int) MYSQL_CLIENT_FLAGS, $flags );
		} else {
			$this->assertSame( 0, $flags );
		}
	}
}
