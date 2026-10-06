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

	/** @dataProvider failed_dump_write_cases */
	public function test_failed_dump_write_uses_confirmed_space( $free, $expected, $tier1 ) {
		$dumper = new class( $free ) extends MainWP_Backup_Db_Dumper {
			public $probed_dir;
			private $free;
			public function __construct( $free ) { $this->free = $free; }
			protected function free_disk_space( $directory ) {
				$this->probed_dir = $directory;
				return $this->free;
			}
			protected function write_checked( $fh, $data, $heartbeat = null ) {
				$readonly = fopen( stream_get_meta_data( $fh )['uri'], 'rb' );
				try { parent::write_checked( $readonly, $data, $heartbeat ); }
				finally { fclose( $readonly ); }
			}
		};
		$handle = fopen( $this->scratch . '/failed-write', 'wb' );
		$method = new ReflectionMethod( $dumper, 'write_checked' );
		$method->setAccessible( true );
		try {
			try {
				if ( $tier1 ) {
					@$dumper->dump_to_files( $this->scratch . '/failed-dump' );
				} else {
					@$method->invoke( $dumper, $handle, str_repeat( 'x', 8192 ) );
				}
				$this->fail( 'A write to a read-only handle must fail.' );
			} catch ( \MainWP\Child\MainWP_Exception $e ) {
				$this->assertSame( $expected, $e->get_message_error_code(), 'An I/O failure needs evidence before it can be classified as disk_full.' );
			}
			$this->assertSame( $this->scratch, $dumper->probed_dir );
			$this->assertContains( $expected, \MainWP\Child\MainWP_Backup_Error::get_registered_codes() );
		} finally {
			fclose( $handle );
		}
	}

	public static function failed_dump_write_cases() {
		$cases = array();
		foreach ( array( 'direct' => false, 'tier1' => true ) as $tier => $tier1 ) {
			$cases[ $tier . '-insufficient' ] = array( 0, 'mainwp_backup_disk_full', $tier1 );
			$cases[ $tier . '-available' ] = array( PHP_INT_MAX, 'mainwp_backup_db_write_failed', $tier1 );
			$cases[ $tier . '-unavailable' ] = array( false, 'mainwp_backup_db_write_failed', $tier1 );
		}
		$cases['write-larger-than-free'] = array( 8191, 'mainwp_backup_disk_full', false );
		return $cases;
	}

	/** @dataProvider failed_dump_rename_cases */
	public function test_failed_dump_rename_uses_confirmed_space( $free, $expected ) {
		$dumper = new class( $free ) extends MainWP_Backup_Db_Dumper {
			public $probed_dir;
			private $free;
			public function __construct( $free ) { $this->free = $free; }
			protected function free_disk_space( $directory ) {
				$this->probed_dir = $directory;
				return $this->free;
			}
		};
		$prefix = $this->scratch . '/rename-failure';
		$final = $prefix . '-' . $this->fixture_table . '.sql.php';
		$cursor = $dumper->dump_tables_tier2_slice( array( $this->fixture_table ), $prefix, array(), 0 )['cursor'];
		mkdir( $final ); // Renaming a file onto a directory fails with space available.
		try {
			try {
				for ( $slice = 0; $slice < 4; ++$slice ) {
					$cursor = @$dumper->dump_tier2_slice( $prefix, $cursor, 0 )['cursor'];
				}
				$this->fail( 'A failed final rename must abort the dump.' );
			} catch ( \MainWP\Child\MainWP_Exception $e ) {
				$this->assertSame( $expected, $e->get_message_error_code(), 'A failed rename with unconfirmed disk exhaustion must use the rename code.' );
			}
			$this->assertSame( $this->scratch, $dumper->probed_dir );
			$this->assertContains( $expected, \MainWP\Child\MainWP_Backup_Error::get_registered_codes() );
		} finally {
			rmdir( $final );
		}
	}

	public static function failed_dump_rename_cases() {
		return array(
			'insufficient' => array( 0, 'mainwp_backup_disk_full' ),
			'available' => array( PHP_INT_MAX, 'mainwp_backup_db_rename_failed' ),
			'unavailable' => array( false, 'mainwp_backup_db_rename_failed' ),
		);
	}

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

	public static function backtick_identifier_dump_modes() {
		return array(
			'snapshot' => array( 1, true ),
			'primary-key seek' => array( 2, true ),
			'offset' => array( 2, false ),
		);
	}

	/**
	 * Schema names must stay identifiers in both the SELECT and the emitted SQL.
	 *
	 * @dataProvider backtick_identifier_dump_modes
	 */
	public function test_dump_backtick_identifiers_round_trip( $tier, $primary_key ) {
		global $wpdb;
		$table = $wpdb->prefix . 'mwp_dump_`fixture';
		$quoted_table = '`' . str_replace( '`', '``', $table ) . '`';
		$mysqli = null;
		try {
			$this->assertNotFalse( $wpdb->query( 'DROP TABLE IF EXISTS ' . $quoted_table ), $wpdb->last_error );
			$this->assertNotFalse(
				$wpdb->query( 'CREATE TABLE ' . $quoted_table . ' (`id``key` bigint NOT NULL, `val``ue` varchar(40), `geo``point` point NOT NULL' . ( $primary_key ? ', PRIMARY KEY (`id``key`)' : '' ) . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' ),
				$wpdb->last_error
			);
			$this->assertSame( 2, $wpdb->query( 'INSERT INTO ' . $quoted_table . " (`id``key`, `val``ue`, `geo``point`) VALUES (1, 'first', ST_GeomFromText('POINT(1 2)')), (2, 'second', ST_GeomFromText('POINT(3 4)'))" ), $wpdb->last_error );
			$select = 'SELECT `id``key`, `val``ue`, ST_AsText(`geo``point`) AS point FROM ' . $quoted_table . ' ORDER BY `id``key`';
			$original = $wpdb->get_results( $select, ARRAY_A );
			$this->assertCount( 2, $original );
			// Make rows visible to the snapshot connection and release metadata locks before replay.
			$wpdb->query( 'COMMIT' );

			$dumper = MainWP_Backup_Db_Dumper::instance();
			$prefix = $this->scratch . '/quoted';
			if ( 1 === $tier ) {
				$result = $dumper->dump_to_files( $prefix );
			} else {
				$cursor = array();
				for ( $round = 0; $round < 10; ++$round ) {
					$result = $dumper->dump_tables_tier2_slice( array( $table ), $prefix, $cursor, 0 );
					$cursor = $result['cursor'];
					if ( 0 === $round ) {
						$this->assertSame( $primary_key ? 'id`key' : null, $cursor['pk_col'] );
					}
					if ( $result['done'] ) {
						break;
					}
				}
				$this->assertTrue( $result['done'], 'Tier 2 must finish the quoted table.' );
			}
			$this->assertSame( $tier, $result['tier'] );
			$file = $prefix . '-' . MainWP\Child\MainWP_Helper::sanitize_filename( $table ) . '.sql.php';
			$this->assertContains( $file, $result['files'] );
			$sql = file_get_contents( $file );
			$this->assertStringContainsString( 'INSERT INTO ' . $quoted_table . ' (`id``key`, `val``ue`, `geo``point`) VALUES ', $sql );
			$wpdb->query( 'COMMIT' );
			$sql = preg_replace( '/^<\?php exit\(\); \?>/', '', $sql );
			$sql = preg_replace( '/^\s*--.*$/m', '', $sql );

			$mysqli = mysqli_init();
			$host = $wpdb->parse_db_host( DB_HOST );
			$mysqli->real_connect( $host[0], DB_USER, DB_PASSWORD, DB_NAME, null === $host[1] ? 0 : (int) $host[1], $host[2] );
			$mysqli->set_charset( 'utf8mb4' );
			$this->assertTrue( $mysqli->multi_query( $sql ), 'Restore failed: ' . $mysqli->error );
			while ( $mysqli->more_results() && $mysqli->next_result() ) {
				$res = $mysqli->store_result();
				if ( $res ) {
					$res->free();
				}
			}
			$this->assertSame( '', $mysqli->error, 'Restore reported: ' . $mysqli->error );
			$this->assertSame( $original, $wpdb->get_results( $select, ARRAY_A ) );
		} finally {
			if ( null !== $mysqli ) {
				$mysqli->close();
			}
			$wpdb->query( 'DROP TABLE IF EXISTS ' . $quoted_table );
		}
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

	/** @dataProvider tier2_row_estimates */
	public function test_tier2_chunk_size_limits_rows_and_survives_resume( $estimate, $expected, $seek ) {
		global $wpdb;
		$this->assertNotFalse( $wpdb->query( "UPDATE `{$this->fixture_table}` SET payload = REPEAT('x', 32768)" ), $wpdb->last_error );
		$reads = 0;
		$selects = array();
		$filter = function ( $sql ) use ( &$estimate, &$reads, &$selects ) {
			// Fix the estimate so engine statistics cannot change the expected LIMIT.
			if ( false !== stripos( $sql, 'AVG_ROW_LENGTH' ) ) {
				++$reads;
				return false === $estimate ? 'SELECT NULL WHERE 1 = 0' : 'SELECT ' . ( null === $estimate ? 'NULL' : (int) $estimate );
			}
			if ( 0 === strpos( $sql, 'SELECT ' ) && false !== strpos( $sql, '`' . $this->fixture_table . '`' ) && false !== strpos( $sql, ' LIMIT ' ) ) {
				$selects[] = $sql;
			}
			return $sql;
		};
		add_filter( 'query', $filter );
		try {
			$dumper = MainWP_Backup_Db_Dumper::instance();
			$prefix = $this->scratch . '/sized';
			$result = $dumper->dump_tables_tier2_slice( array( $this->fixture_table ), $prefix, array(), 0 );
			$this->assertArrayHasKey( 'chunk_rows', $result['cursor'], 'Persist the table estimate before yielding.' );
			$this->assertSame( $expected, $result['cursor']['chunk_rows'] );
			$cursor = json_decode( wp_json_encode( $result['cursor'] ), true );
			if ( ! $seek ) {
				$cursor['pk_col'] = null;
			}
			$estimate = 1;
			$result = $dumper->dump_tables_tier2_slice( array( $this->fixture_table ), $prefix, $cursor, 0 );
			$this->assertTrue( $result['done'] );
			$this->assertSame( 1, $reads, 'A resumed table must keep its original estimate.' );
			$this->assertCount( 1, $selects );
			$this->assertMatchesRegularExpression( '/ LIMIT ' . $expected . '(?: OFFSET 0)?$/', $selects[0] );
		} finally {
			remove_filter( 'query', $filter );
		}
	}

	public static function tier2_row_estimates() {
		$cases = array();
		foreach ( array( 'seek' => true, 'offset' => false ) as $mode => $seek ) {
			$cases[ $mode . '-large' ] = array( 32768, 256, $seek );
			$cases[ $mode . '-floor' ] = array( 32769, 255, $seek );
			$cases[ $mode . '-minimum' ] = array( 8388608, 100, $seek );
			$cases[ $mode . '-small' ] = array( 1, 10000, $seek );
			$cases[ $mode . '-zero' ] = array( 0, 10000, $seek );
			$cases[ $mode . '-null' ] = array( null, 10000, $seek );
			$cases[ $mode . '-missing' ] = array( false, 10000, $seek );
		}
		return $cases;
	}

	public function test_tier2_full_sized_chunk_does_not_finish_table() {
		global $wpdb;
		for ( $id = 3; $id <= 103; ++$id ) {
			$this->assertSame( 1, $wpdb->insert( $this->fixture_table, array( 'id' => $id, 'name' => 'row-' . $id ) ), $wpdb->last_error );
		}
		$filter = function ( $sql ) {
			return false !== stripos( $sql, 'AVG_ROW_LENGTH' ) ? 'SELECT 8388608' : $sql;
		};
		add_filter( 'query', $filter );
		try {
			$dumper = MainWP_Backup_Db_Dumper::instance();
			$prefix = $this->scratch . '/completion';
			$result = $dumper->dump_tables_tier2_slice( array( $this->fixture_table ), $prefix, array(), 0 );
			$result = $dumper->dump_tables_tier2_slice( array( $this->fixture_table ), $prefix, $result['cursor'], 0 );
			$this->assertFalse( $result['done'], 'A full computed chunk must leave the table open.' );
			$this->assertSame( 100, $result['cursor']['offset'] );
			$this->assertSame( 100, $result['cursor']['last_id'] );
			$result = $dumper->dump_tables_tier2_slice( array( $this->fixture_table ), $prefix, $result['cursor'], 0 );
			$this->assertTrue( $result['done'], 'The short final chunk must finish the table.' );
			$this->replay_tier2_fixture( $result['files'][0] );
			$this->assertSame( '103', $wpdb->get_var( "SELECT COUNT(*) FROM `{$this->fixture_table}`" ) );
		} finally {
			remove_filter( 'query', $filter );
		}
	}

	public function test_tier2_cursor_without_chunk_size_resumes() {
		$dumper = MainWP_Backup_Db_Dumper::instance();
		$prefix = $this->scratch . '/legacy-cursor';
		$result = $dumper->dump_tables_tier2_slice( array( $this->fixture_table ), $prefix, array(), 0 );
		unset( $result['cursor']['chunk_rows'] );
		$selects = array();
		$reads = 0;
		$filter = function ( $sql ) use ( &$selects, &$reads ) {
			if ( false !== stripos( $sql, 'AVG_ROW_LENGTH' ) ) {
				++$reads;
			}
			if ( 0 === strpos( $sql, 'SELECT ' ) && false !== strpos( $sql, '`' . $this->fixture_table . '`' ) && false !== strpos( $sql, ' LIMIT ' ) ) {
				$selects[] = $sql;
			}
			return $sql;
		};
		add_filter( 'query', $filter );
		try {
			$result = $dumper->dump_tables_tier2_slice( array( $this->fixture_table ), $prefix, $result['cursor'], 0 );
			$this->assertTrue( $result['done'] );
			$this->assertSame( 0, $reads );
			$this->assertCount( 1, $selects );
			$this->assertStringEndsWith( ' LIMIT 10000', $selects[0] );
			$this->assertStringContainsString( 'INSERT INTO', file_get_contents( $result['files'][0] ) );
		} finally {
			remove_filter( 'query', $filter );
		}
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

	public function test_tier1_heartbeat_runs_after_every_table() {
		$prefix = $this->scratch . '/heartbeat-tier1';
		$seen = array();
		$result = MainWP_Backup_Db_Dumper::instance()->dump_to_files(
			$prefix,
			null,
			function () use ( $prefix, &$seen ) {
				$seen[] = count( (array) glob( $prefix . '-*.sql.php' ) );
			}
		);
		$this->assertNotEmpty( $result['files'] );
		foreach ( range( 1, count( $result['files'] ) ) as $count ) {
			$this->assertContains( $count, $seen, 'Each completed table must heartbeat before the next one.' );
		}
	}

	public function test_tier2_heartbeat_runs_after_every_table() {
		$prefix = $this->scratch . '/heartbeat-tier2';
		$seen = array();
		$result = MainWP_Backup_Db_Dumper::instance()->dump_tier2_slice(
			$prefix,
			null,
			3600,
			function () use ( $prefix, &$seen ) {
				$seen[] = count( (array) glob( $prefix . '-*.sql.php' ) );
			}
		);
		$this->assertTrue( $result['done'] );
		$this->assertNotEmpty( $result['files'] );
		foreach ( range( 1, count( $result['files'] ) ) as $count ) {
			$this->assertContains( $count, $seen, 'Each completed table must heartbeat before the next one.' );
		}
	}

	public function test_streamed_writes_heartbeat_before_writes_and_propagate_loss() {
		$dumper = new MainWP_Backup_Db_Dumper();
		$write = new ReflectionMethod( $dumper, 'write_checked' );
		$write->setAccessible( true );
		$fh = fopen( $this->scratch . '/heartbeat-stream', 'w' );
		$calls = 0;
		$heartbeat = function () use ( &$calls ) { ++$calls; };
		try {
			for ( $i = 0; $i < 4; ++$i ) {
				$write->invoke( $dumper, $fh, str_repeat( 'a', 1024 * 1024 ), $heartbeat );
			}
			$this->assertSame( 4, $calls );
			$write->invoke( $dumper, $fh, str_repeat( 'a', 1024 * 1024 ), $heartbeat );
			$this->assertSame( 6, $calls );
			$write->invoke( $dumper, $fh, str_repeat( 'a', 10 * 1024 * 1024 ), $heartbeat );
			$this->assertSame( 9, $calls );
			$failure = new \MainWP\Child\MainWP_Exception( 'Ownership lost', null, \MainWP\Child\MainWP_Backup_Error::ERR_ALREADY_RUNNING );
			try {
				$write->invoke( $dumper, $fh, str_repeat( 'a', 5 * 1024 * 1024 ), function () use ( $failure ) { throw $failure; } );
				$this->fail( 'The writer must stop when its heartbeat fails.' );
			} catch ( \MainWP\Child\MainWP_Exception $e ) {
				$this->assertSame( $failure, $e );
				$this->assertSame( 15 * 1024 * 1024, fstat( $fh )['size'], 'Ownership loss must stop before another write.' );
			}
		} finally {
			fclose( $fh );
		}
	}


	/** Percent bytes, including serialized lengths, survive raw SQL replay. */
	public function test_tier2_percent_values_round_trip() {
		global $wpdb;
		$values = array( "100% O'Reilly\\path", serialize( array( 'url' => 'a%20b', 'width' => '50%' ) ) );
		$wpdb->query( "DROP TABLE `{$this->fixture_table}`" );
		$wpdb->query( "CREATE TABLE `{$this->fixture_table}` (id INT PRIMARY KEY, value TEXT)" );
		foreach ( $values as $id => $value ) {
			$wpdb->insert( $this->fixture_table, array( 'id' => $id + 1, 'value' => $value ) );
		}
		$result = MainWP_Backup_Db_Dumper::instance()->dump_tables_tier2_slice( array( $this->fixture_table ), $this->scratch . '/percent', array(), 45 );
		$this->assertTrue( $result['done'] );
		$this->replay_tier2_fixture( $result['files'][0] );
		$this->assertSame( $values, $wpdb->get_col( "SELECT value FROM `{$this->fixture_table}` ORDER BY id" ) );
	}

	/** The initial seek includes negative and zero primary keys. */
	public function test_tier2_signed_primary_keys_round_trip() {
		global $wpdb;
		$wpdb->query( "DROP TABLE `{$this->fixture_table}`" );
		$wpdb->query( "CREATE TABLE `{$this->fixture_table}` (id INT PRIMARY KEY)" );
		$wpdb->query( "INSERT INTO `{$this->fixture_table}` VALUES (-2),(-1),(0),(1)" );
		$dumper = MainWP_Backup_Db_Dumper::instance();
		$result = $dumper->dump_tables_tier2_slice( array( $this->fixture_table ), $this->scratch . '/signed', array(), 0 );
		$this->assertNull( $result['cursor']['last_id'], 'The first chunk must have no lower bound.' );
		$result = $dumper->dump_tables_tier2_slice( array( $this->fixture_table ), $this->scratch . '/signed', $result['cursor'], 45 );
		$this->assertTrue( $result['done'] );
		$this->assertNull( $result['cursor']['last_id'], 'Completing a table must reset the sentinel.' );
		$this->replay_tier2_fixture( $result['files'][0] );
		$this->assertSame( array( '-2', '-1', '0', '1' ), $wpdb->get_col( "SELECT id FROM `{$this->fixture_table}` ORDER BY id" ) );
	}

	/** OFFSET pagination orders composite keys or all columns across chunks. */
	public function test_tier2_offset_order_is_deterministic() {
		global $wpdb;
		foreach ( array( true, false ) as $primary ) {
			$wpdb->query( "DROP TABLE `{$this->fixture_table}`" );
			$wpdb->query( "CREATE TABLE `{$this->fixture_table}` (`z` INT, `a``key` INT" . ( $primary ? ', PRIMARY KEY (`a``key`, `z`)' : '' ) . ')' );
			$values = array();
			for ( $id = 10001; $id >= 0; --$id ) {
				$values[] = '(' . $id . ', ' . ( 10001 - $id ) . ')';
			}
			$this->assertSame( 10002, $wpdb->query( "INSERT INTO `{$this->fixture_table}` VALUES " . implode( ',', $values ) ) );
			$queries = array();
			$capture = function ( $sql ) use ( &$queries ) {
				if ( 0 === strpos( $sql, 'SELECT `z`, `a``key` FROM' ) ) {
					$queries[] = $sql;
				}
				return $sql;
			};
			add_filter( 'query', $capture );
			try {
				$result = MainWP_Backup_Db_Dumper::instance()->dump_tables_tier2_slice( array( $this->fixture_table ), $this->scratch . '/order-' . (int) $primary, array(), 45 );
			} finally {
				remove_filter( 'query', $capture );
			}
			$this->assertTrue( $result['done'] );
			$this->assertCount( 2, $queries );
			$order = $primary ? '`a``key`, `z`' : '`z`, `a``key`';
			$this->assertStringContainsString( 'ORDER BY ' . $order . ' LIMIT 10000 OFFSET 0', $queries[0] );
			$this->assertStringContainsString( 'ORDER BY ' . $order . ' LIMIT 10000 OFFSET 10000', $queries[1] );
			$this->replay_tier2_fixture( $result['files'][0] );
			$this->assertSame( '10002', $wpdb->get_var( "SELECT COUNT(DISTINCT z) FROM `{$this->fixture_table}`" ) );
		}
	}

	/** Replay without wpdb removing placeholder tokens on our behalf. */
	private function replay_tier2_fixture( $file ) {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		$host = $wpdb->parse_db_host( DB_HOST );
		$conn = mysqli_init();
		$conn->real_connect( $host[0], DB_USER, DB_PASSWORD, DB_NAME, null === $host[1] ? 0 : (int) $host[1], $host[2] );
		$conn->set_charset( 'utf8mb4' );
		try {
			$sql = str_replace( '<?php exit(); ?>', '', file_get_contents( $file ) );
			$this->assertTrue( $conn->multi_query( $sql ), $conn->error );
			while ( $conn->more_results() && $conn->next_result() ) {
				$result = $conn->store_result();
				if ( $result ) {
					$result->free();
				}
			}
			$this->assertSame( '', $conn->error );
		} finally {
			$conn->close();
		}
	}

	public function test_tier2_invalid_schema_removes_incomplete_output() {
		global $wpdb;
		foreach ( array( 'SELECT 1 WHERE 1 = 0', "SELECT 'table', ''", "SELECT 'table', 'not a schema'", 'SELECT missing_schema_column' ) as $replacement ) {
			$filter = function ( $sql ) use ( $replacement ) {
				return 'SHOW CREATE TABLE `' . $this->fixture_table . '`' === $sql ? $replacement : $sql;
			};
			$errors = $wpdb->suppress_errors( true );
			add_filter( 'query', $filter );
			try {
				MainWP_Backup_Db_Dumper::instance()->dump_tables_tier2_slice( array( $this->fixture_table ), $this->scratch . '/schema', array(), 0 );
				$this->fail( 'An invalid schema must abort the header.' );
			} catch ( \MainWP\Child\MainWP_Exception $e ) {
				$this->assertSame( \MainWP\Child\MainWP_Backup_Error::ERR_DB_QUERY_FAILED, $e->get_message_error_code() );
			} finally {
				remove_filter( 'query', $filter );
				$wpdb->suppress_errors( $errors );
			}
			$this->assertFileDoesNotExist( $this->scratch . '/schema-' . $this->fixture_table . '.sql.php.tmp' );
			$this->assertFileDoesNotExist( $this->scratch . '/schema-' . $this->fixture_table . '.sql.php' );
		}
	}

	public function test_tier2_header_write_failure_closes_handle_and_removes_output() {
		$dumper = new class() extends MainWP_Backup_Db_Dumper {
			public $handle;
			protected function write_checked( $fh, $data, $heartbeat = null ) {
				$this->handle = $fh;
				parent::write_checked( $fh, $data, $heartbeat );
				throw new \MainWP\Child\MainWP_Exception( 'disk full', null, \MainWP\Child\MainWP_Backup_Error::ERR_DISK_FULL );
			}
		};
		try {
			$dumper->dump_tables_tier2_slice( array( $this->fixture_table ), $this->scratch . '/header', array(), 0 );
			$this->fail( 'A header write failure must abort.' );
		} catch ( \MainWP\Child\MainWP_Exception $e ) {
			$this->assertSame( \MainWP\Child\MainWP_Backup_Error::ERR_DISK_FULL, $e->get_message_error_code() );
		}
		$this->assertFalse( is_resource( $dumper->handle ) );
		$this->assertFileDoesNotExist( $this->scratch . '/header-' . $this->fixture_table . '.sql.php.tmp' );
	}

	public function test_lease_loss_preserves_successor_dump_fragment() {
		foreach ( array( 1, 2 ) as $tier ) {
			$prefix = $this->scratch . '/lost-lease-' . $tier;
			$fragment = null;
			$heartbeat = function () use ( $prefix, &$fragment ) {
				$files = glob( $prefix . '-*.sql.php.tmp' );
				if ( ! empty( $files ) ) {
					$fragment = $files[0];
					file_put_contents( $fragment, 'successor dump' );
					throw new MainWP_Backup_Budget_Exception( 'lease lost', null, \MainWP\Child\MainWP_Backup_Error::ERR_ALREADY_RUNNING );
				}
			};
			$dumper = new MainWP_Backup_Db_Dumper();
			try {
				if ( 1 === $tier ) {
					$dumper->dump_to_files( $prefix, null, $heartbeat );
				} else {
					$dumper->dump_tier2_slice( $prefix, null, 45, $heartbeat );
				}
				$this->fail( 'Lease loss must interrupt tier ' . $tier );
			} catch ( MainWP_Backup_Budget_Exception $e ) {
				$this->assertSame( \MainWP\Child\MainWP_Backup_Error::ERR_ALREADY_RUNNING, $e->get_message_error_code() );
			}
			$this->assertNotNull( $fragment );
			$this->assertFileExists( $fragment );
			$this->assertSame( 'successor dump', file_get_contents( $fragment ) );
		}
	}

}
