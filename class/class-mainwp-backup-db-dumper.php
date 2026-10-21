<?php
/**
 * MainWP Backup database dumper.
 *
 * Tier 1: a dedicated mysqli connection takes a REPEATABLE READ consistent
 * snapshot and streams every table through a server-side cursor
 * (MYSQLI_USE_RESULT) - memory stays flat regardless of table size and the
 * dump is point-in-time for InnoDB tables.
 *
 * Tier 2: PK-seek pagination on the shared $wpdb handle with a persisted
 * cursor, for hosts where the snapshot cannot finish inside one bounded
 * request. Not point-in-time - the result is labeled paginated.
 *
 * Output keeps the legacy per-table .sql.php guarded format so the existing
 * restore/download/cleanup paths work unchanged.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Backup_Db_Dumper
 *
 * @package MainWP\Child
 */
class MainWP_Backup_Db_Dumper { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Multi-row INSERT batch flush threshold (~matches max_allowed_packet floors).
     */
    const BATCH_BYTES = 1048576;

    /**
     * Rows per Tier-2 chunk.
     */
    const TIER2_CHUNK_ROWS = 10000;

    /**
     * Aim for 8 MiB of row data because wpdb buffers each Tier-2 read.
     */
    const TIER2_TARGET_CHUNK_BYTES = 8388608;

    /**
     * Keep at least 100 rows per read so large-row tables still make progress.
     */
    const TIER2_MIN_CHUNK_ROWS = 100;

    /**
     * Singleton instance.
     *
     * @var MainWP_Backup_Db_Dumper|null
     */
    protected static $instance = null;

    /**
     * Geometry data types that must be selected via ST_AsWKB.
     *
     * @var string[]
     */
    protected static $geometry_types = array( 'geometry', 'point', 'linestring', 'polygon', 'multipoint', 'multilinestring', 'multipolygon', 'geometrycollection', 'geomcollection' );

    /**
     * Blob/binary data types emitted as hex literals.
     *
     * @var string[]
     */
    protected static $binary_types = array( 'blob', 'tinyblob', 'mediumblob', 'longblob', 'binary', 'varbinary' );

    /**
     * Numeric data types emitted as raw unquoted values.
     *
     * @var string[]
     */
    protected static $numeric_types = array( 'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'decimal', 'numeric', 'float', 'double', 'real' );

    /**
     * Date/time types emitted verbatim quoted (covers zero dates).
     *
     * @var string[]
     */
    protected static $datetime_types = array( 'date', 'datetime', 'timestamp', 'time', 'year' );

    /**
     * Streaming heartbeat cadence.
     *
     * @var int Bytes streamed since the last heartbeat.
     */
    protected $heartbeat_bytes = 0;

    /**
     * Bound disk checks even when one SQL value exceeds the usual batch size.
     *
     * @var int Bytes streamed since the last disk-floor check.
     */
    private $disk_floor_bytes = 0;

    /**
     * Short slices still need a check even when they never stream 16 MiB.
     *
     * @var bool
     */
    private $disk_floor_checked = false;

    /**
     * Restore snapshots use the restore engine's existing disk margins.
     *
     * @var bool Restore aside dumps retain the restore engine's disk policy.
     */
    private $enforce_disk_floor = true;

    /**
     * Get the singleton instance.
     *
     * @return MainWP_Backup_Db_Dumper
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Resolve connection parameters from the WP constants, handling every
     * DB_HOST form (host, host:port, host:socket, [::1]:port).
     *
     * @return array{host:string,port:int|null,socket:string|null,user:string,password:string,name:string}
     */
    public function get_connection_params() {
        global $wpdb;
        $parsed = $wpdb->parse_db_host( DB_HOST );
        if ( ! is_array( $parsed ) ) {
            $parsed = array( DB_HOST, null, null, false );
        }
        return array(
            'host'     => (string) $parsed[0],
            'port'     => null !== $parsed[1] ? (int) $parsed[1] : null,
            'socket'   => $parsed[2],
            'user'     => DB_USER,
            'password' => DB_PASSWORD,
            'name'     => DB_NAME,
        );
    }

    /**
     * Refuse to dump sharded/replicated databases.
     *
     * With a wp-content/db.php drop-in routing $wpdb across multiple servers,
     * both the dedicated DB_HOST connection and $wpdb->dbh can point at a
     * single shard or a read replica - a silently incomplete dump. Fail
     * explicitly; only proceed when $wpdb resolves to one canonical host.
     *
     * @throws MainWP_Exception When a multi-server topology is detected.
     */
    public function assert_supported_topology() {
        global $wpdb;
        if ( ! file_exists( WP_CONTENT_DIR . '/db.php' ) ) {
            return;
        }
        foreach ( array( 'db_servers', 'hyper_servers', 'ludicrous_servers' ) as $prop ) {
            if ( isset( $wpdb->{$prop} ) && is_array( $wpdb->{$prop} ) && count( $wpdb->{$prop} ) > 1 ) {
                throw new MainWP_Exception( esc_html__( 'This site uses a sharded or replicated database (multiple database servers), which native backup cannot dump completely. Use a host-level or provider backup for this site.', 'mainwp-child' ), null, MainWP_Backup_Error::ERR_DB_QUERY_FAILED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message is escaped, code is a registry class constant.
            }
        }
    }

    /**
     * Preflight: verify a dedicated second connection works on this host
     * (open + SELECT 1 + close). Failures (max_user_connections, missing
     * grants, SSL requirements, socket mismatch) route the dump to the
     * Tier-2 fallback on $wpdb.
     *
     * @param array|null $params Connection parameter override (tests).
     *
     * @return array{ok:bool,reason?:string} Result with a credential-scrubbed reason on failure.
     */
    public function check_dedicated_connection( $params = null ) {
        try {
            $mysqli = $this->open_dedicated_connection( $params );
        } catch ( \Exception $e ) {
            return array(
                'ok'     => false,
                'reason' => MainWP_Backup_Error::scrub_db_credentials( $e->getMessage() ),
            );
        }
        $res = $mysqli->query( 'SELECT 1' );
        $ok  = false !== $res;
        if ( $res instanceof \mysqli_result ) {
            $res->free();
        }
        $mysqli->close();
        if ( ! $ok ) {
            return array(
                'ok'     => false,
                'reason' => 'SELECT 1 failed on the dedicated connection.',
            );
        }
        return array( 'ok' => true );
    }

    /**
     * Resolve mysqli client flags, honoring a host's MYSQL_CLIENT_FLAGS the
     * same way wpdb does (e.g. forced SSL). Shared by the dump and restore
     * connections so the two can never drift on SSL-required hosts.
     *
     * @return int Client-flags bitmask, 0 when none is defined.
     */
    public static function resolve_client_flags() {
        return defined( 'MYSQL_CLIENT_FLAGS' ) ? (int) MYSQL_CLIENT_FLAGS : 0;
    }

    /**
     * Open the dedicated mysqli connection.
     *
     * @param array|null $params Connection parameter override (tests).
     *
     * @return \mysqli Connected handle, utf8mb4 charset set.
     *
     * @throws MainWP_Exception On connection failure (credential-scrubbed).
     */
    protected function open_dedicated_connection( $params = null ) {
        if ( null === $params ) {
            $params = $this->get_connection_params();
        }

        //phpcs:disable WordPress.DB -- raw mysqli is the point: a dedicated connection independent of $wpdb.
        $mysqli = mysqli_init();
        $mysqli->options( MYSQLI_OPT_CONNECT_TIMEOUT, 10 );

        $flags = self::resolve_client_flags();

        $connected = @$mysqli->real_connect( // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- failure converted to a coded, scrubbed exception below.
            $params['host'],
            $params['user'],
            $params['password'],
            $params['name'],
            null !== $params['port'] ? (int) $params['port'] : 0,
            null !== $params['socket'] ? $params['socket'] : '',
            $flags
        );

        if ( ! $connected ) {
            $error = mysqli_connect_error();
        }
        //phpcs:enable WordPress.DB

        if ( ! $connected ) {
            throw new MainWP_Exception(
                esc_html( MainWP_Backup_Error::scrub_db_credentials( 'Dedicated DB connection failed: ' . $error ) ),
                null,
                MainWP_Backup_Error::ERR_DB_CONNECTION_LOST // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message escaped and scrubbed, code is a registry class constant.
            );
        }

        $mysqli->set_charset( 'utf8mb4' );
        return $mysqli;
    }

    /**
     * Tier-1 dump: consistent snapshot of every base table into per-table
     * guarded .sql.php files.
     *
     * @param string        $filepath_prefix Per-table file prefix (legacy dbBackup naming).
     * @param int|null      $time_budget     Seconds of dump work allowed; checked at
     *                                  table boundaries. Null for unbounded.
     * @param callable|null $heartbeat       Optional liveness callback.
     *
     * @return array{files:string[],consistency:string,non_innodb_tables:string[],has_foreign_keys:bool,tier:int}
     *
     * @throws MainWP_Backup_Budget_Exception When the budget is exceeded (partials cleaned up).
     * @throws MainWP_Exception On any dump failure (partials cleaned up, credentials scrubbed).
     * @throws \Exception Internal failures, wrapped into MainWP_Exception before leaving this method.
     */
    public function dump_to_files( $filepath_prefix, $time_budget = null, $heartbeat = null ) { //phpcs:ignore -- NOSONAR - complexity required for cleanup-on-failure semantics.
        $this->assert_supported_topology();
        $this->heartbeat_bytes    = 0;
        $this->disk_floor_bytes   = 0;
        $this->disk_floor_checked = false;

        $start  = time();
        $mysqli = $this->open_dedicated_connection();
        $files  = array();

        //phpcs:disable WordPress.DB -- raw mysqli on the dedicated connection.
        try {
            $mysqli->query( 'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ' );
            if ( false === $mysqli->query( 'START TRANSACTION WITH CONSISTENT SNAPSHOT' ) ) {
                throw new \Exception( 'Could not start a consistent snapshot: ' . $mysqli->error );
            }

            // Engine inventory - the snapshot is InnoDB-only; any other engine
            // demotes the backup's consistency class to mixed/best-effort.
            $non_innodb = array();
            $res        = $mysqli->query( "SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'" );
            if ( false === $res ) {
                throw new \Exception( 'Engine inventory failed: ' . $mysqli->error );
            }
            while ( $row = $res->fetch_assoc() ) {
                $engine = isset( $row['ENGINE'] ) ? $row['ENGINE'] : ( isset( $row['engine'] ) ? $row['engine'] : '' );
                $name   = isset( $row['TABLE_NAME'] ) ? $row['TABLE_NAME'] : ( isset( $row['table_name'] ) ? $row['table_name'] : '' );
                if ( 'InnoDB' !== $engine ) {
                    $non_innodb[] = $name;
                }
            }
            $res->free();

            $has_foreign_keys = false;
            $res              = $mysqli->query( 'SELECT COUNT(*) AS c FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE()' );
            if ( false !== $res ) {
                $row              = $res->fetch_assoc();
                $has_foreign_keys = ! empty( $row['c'] );
                $res->free();
            }

            $tables = array();
            $res    = $mysqli->query( "SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'" );
            if ( false === $res ) {
                throw new \Exception( 'Table inventory failed: ' . $mysqli->error );
            }
            while ( $row = $res->fetch_row() ) {
                $tables[] = $row[0];
            }
            $res->free();

            $meta = array(
                'has_foreign_keys'  => $has_foreign_keys,
                'consistency'       => empty( $non_innodb ) ? 'consistent' : 'mixed',
                'non_innodb_tables' => $non_innodb,
                'tier'              => 1,
            );

            $first = true;
            foreach ( $tables as $table ) {
                if ( null !== $time_budget && ( time() - $start ) >= $time_budget ) {
                    throw new MainWP_Backup_Budget_Exception( 'Tier-1 snapshot dump exceeded its time budget.', null, MainWP_Backup_Error::ERR_DB_QUERY_FAILED );
                }
                $files[] = $this->dump_table_snapshot( $mysqli, $table, $filepath_prefix, $first ? $meta : null, $heartbeat );
                $first   = false;
                if ( null !== $heartbeat ) {
                    call_user_func( $heartbeat );
                }
                set_time_limit( 600 ); // phpcs:ignore -- per-table timer reset so long dumps survive max_execution_time.
            }

            $mysqli->commit();
            $mysqli->close();
        } catch ( \Exception $e ) {
            // Clean up partials so a resume restarts from a clean slate.
            @$mysqli->close(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort cleanup on a failed connection.
            if ( $e instanceof MainWP_Exception && MainWP_Backup_Error::ERR_ALREADY_RUNNING === $e->get_message_error_code() ) {
                throw $e;
            }
            foreach ( $files as $file ) {
                if ( file_exists( $file ) ) {
                    unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- partial dump cleanup.
                }
            }
            foreach ( (array) glob( $filepath_prefix . '-*.sql.php.tmp' ) as $tmp ) {
                unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- partial dump cleanup.
            }
            if ( $e instanceof MainWP_Backup_Budget_Exception || $e instanceof MainWP_Exception ) {
                throw $e;
            }
            throw new MainWP_Exception(
                esc_html( MainWP_Backup_Error::scrub_db_credentials( $e->getMessage() ) ),
                null,
                MainWP_Backup_Error::ERR_DB_QUERY_FAILED // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message escaped and scrubbed, code is a registry class constant.
            );
        }
        //phpcs:enable WordPress.DB

        return array(
            'files'             => $files,
            'consistency'       => $meta['consistency'],
            'non_innodb_tables' => $meta['non_innodb_tables'],
            'has_foreign_keys'  => $meta['has_foreign_keys'],
            'tier'              => 1,
        );
    }

    /**
     * Dump one table from the snapshot connection into its guarded file.
     *
     * @param \mysqli       $mysqli    Dedicated connection holding the snapshot.
     * @param string        $table     Table name.
     * @param string        $prefix    File path prefix.
     * @param array|null    $meta      Backup metadata banner (first file only).
     * @param callable|null $heartbeat Optional liveness callback.
     *
     * @return string Final file path.
     *
     * @throws \Exception On any read/write failure.
     */
    protected function dump_table_snapshot( $mysqli, $table, $prefix, $meta, $heartbeat = null ) { //phpcs:ignore -- NOSONAR - streaming dump loop.
        //phpcs:disable WordPress.DB, WordPress.WP.AlternativeFunctions -- raw mysqli + stream writes are the point.
        $final = $prefix . '-' . MainWP_Helper::sanitize_filename( $table ) . '.sql.php';
        if ( file_exists( $final ) ) {
            return $final; // Already dumped (resume semantics match the legacy dump).
        }

        $columns = $this->get_column_map(
            function ( $sql ) use ( $mysqli ) {
                $res  = $mysqli->query( $sql );
                $rows = array();
                if ( false !== $res ) {
                    while ( $row = $res->fetch_assoc() ) {
                        $rows[] = $row;
                    }
                    $res->free();
                }
                return $rows;
            },
            $table
        );
        if ( empty( $columns ) ) {
            throw new \Exception( "Could not read the column map for table `$table`." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal control flow; wrapped, escaped and scrubbed by dump_to_files before leaving the child.
        }

        if ( null !== $heartbeat ) {
            call_user_func( $heartbeat );
        }
        $tmp = $final . '.tmp';
        $fh  = fopen( $tmp, 'w' );
        MainWP_Helper::protect_backup_path( $tmp );
        if ( false === $fh ) {
            throw new \Exception( "Could not open the dump file for table `$table` - check disk space and permissions." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal control flow; wrapped, escaped and scrubbed by dump_to_files before leaving the child.
        }

        try {
            $this->write_checked( $fh, '<?php exit(); ?>', $heartbeat );
            if ( null !== $meta ) {
                $this->write_checked( $fh, "\n" . $this->build_banner( $meta ), $heartbeat );
            }

            $this->write_checked( $fh, "\n\nDROP TABLE IF EXISTS " . $this->quote_ident( $table ) . ';', $heartbeat );

            $res = $mysqli->query( 'SHOW CREATE TABLE ' . $this->quote_ident( $table ) );
            if ( false === $res ) {
                throw new \Exception( "SHOW CREATE TABLE failed for `$table`: " . $mysqli->error );
            }
            $create = $res->fetch_row();
            $res->free();
            $this->write_checked( $fh, "\n" . $create[1] . ";\n", $heartbeat );

            $select_list = array();
            $insert_cols = array();
            foreach ( $columns as $col ) {
                if ( $col['generated'] ) {
                    continue; // MySQL rejects any inserted value but DEFAULT.
                }
                $insert_cols[] = $this->quote_ident( $col['name'] );
                if ( in_array( $col['type'], static::$geometry_types, true ) ) {
                    // A plain SELECT returns internal geometry bytes that cannot
                    // be converted to WKB after the fact - select as WKB.
                    $select_list[] = 'ST_AsWKB(' . $this->quote_ident( $col['name'] ) . ') AS ' . $this->quote_ident( $col['name'] );
                } else {
                    $select_list[] = $this->quote_ident( $col['name'] );
                }
            }

            $res = $mysqli->query( 'SELECT ' . implode( ', ', $select_list ) . ' FROM ' . $this->quote_ident( $table ), MYSQLI_USE_RESULT );
            if ( false === $res ) {
                throw new \Exception( "Reading table `$table` failed: " . $mysqli->error );
            }

            $insert_prefix = 'INSERT INTO ' . $this->quote_ident( $table ) . ' (' . implode( ', ', $insert_cols ) . ') VALUES ';
            $batch         = '';
            $escaper       = function ( $value ) use ( $mysqli ) {
                return $mysqli->real_escape_string( $value );
            };
            $value_columns = array_values(
                array_filter(
                    $columns,
                    function ( $col ) {
                        return ! $col['generated'];
                    }
                )
            );

            while ( $row = $res->fetch_row() ) {
                if ( null !== $heartbeat ) {
                    call_user_func( $heartbeat );
                }
                $values = array();
                foreach ( $row as $index => $value ) {
                    $values[] = $this->format_value( $value, $value_columns[ $index ], $escaper );
                }
                $tuple = '(' . implode( ', ', $values ) . ')';
                if ( '' === $batch ) {
                    $batch = $insert_prefix . $tuple;
                } else {
                    $batch .= ', ' . $tuple;
                }
                if ( strlen( $batch ) >= self::BATCH_BYTES ) {
                    $this->write_checked( $fh, "\n" . $batch . ';', $heartbeat );
                    $batch = '';
                }
            }
            $res->free();
            if ( '' !== $mysqli->error ) {
                throw new \Exception( "Reading table `$table` failed mid-stream: " . $mysqli->error );
            }
            if ( '' !== $batch ) {
                $this->write_checked( $fh, "\n" . $batch . ';', $heartbeat );
            }

            fflush( $fh );
            fclose( $fh );
        } catch ( \Exception $e ) {
            fclose( $fh );
            if ( $e instanceof MainWP_Exception && MainWP_Backup_Error::ERR_ALREADY_RUNNING === $e->get_message_error_code() ) {
                throw $e;
            }
            if ( file_exists( $tmp ) ) {
                unlink( $tmp );
            }
            throw $e;
        }

        if ( ! rename( $tmp, $final ) ) {
            unlink( $tmp );
            throw new \Exception( "Could not finalize the dump file for table `$table`." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal control flow; wrapped, escaped and scrubbed by dump_to_files before leaving the child.
        }
        clearstatcache();
        //phpcs:enable WordPress.DB, WordPress.WP.AlternativeFunctions

        return $final;
    }

    /**
     * Tier-2 dump: PK-seek pagination on $wpdb with a persisted cursor, so a
     * huge database finishes across multiple bounded requests on the task
     * spine. Not point-in-time - labeled paginated.
     *
     * Each call performs at least one unit of work (table header, row chunk,
     * or table finalize) and returns once the time budget is spent.
     *
     * @param string        $filepath_prefix File path prefix.
     * @param array|null    $cursor          Cursor from the previous slice, null to start.
     * @param int           $time_budget     Seconds of work per slice.
     * @param callable|null $heartbeat       Optional liveness callback.
     *
     * @return array{done:bool,cursor:array,files:string[],tier:int,consistency:string}
     *
     * @throws MainWP_Exception On read/write failure.
     */
    public function dump_tier2_slice( $filepath_prefix, $cursor, $time_budget = 45, $heartbeat = null ) { //phpcs:ignore -- NOSONAR - resumable slice loop.
        global $wpdb;
        $session = $wpdb->get_row( 'SELECT @@character_set_client AS charset, @@collation_connection AS collation', ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- restore the actual session, which may differ from DB_CHARSET.
        if ( ! is_array( $session ) || empty( $session['charset'] ) || empty( $session['collation'] ) ) {
            throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_DB_QUERY_FAILED ) ), null, MainWP_Backup_Error::ERR_DB_QUERY_FAILED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped registry message.
        }

        $charset = $wpdb->charset;
        $collate = $wpdb->collate;
        try {
            // Restore replays as utf8mb4, so both fetched text and SQL escaping must use it.
            // wpdb also needs these defaults if it reconnects during the slice.
            $wpdb->charset = 'utf8mb4';
            $wpdb->collate = 'utf8mb4_unicode_ci';
            $wpdb->set_charset( $wpdb->dbh, 'utf8mb4', 'utf8mb4_unicode_ci' );
            return $this->dump_tier2_slice_utf8mb4( $filepath_prefix, $cursor, $time_budget, $heartbeat );
        } finally {
            // This shared handle also serves WordPress after a yield or failed dump.
            $wpdb->charset = $charset;
            $wpdb->collate = $collate;
            $wpdb->set_charset( $wpdb->dbh, $session['charset'], $session['collation'] );
        }
    }

    /**
     * Tier-2 slice body; dump_tier2_slice() holds the connection at utf8mb4 around it.
     *
     * @param string        $filepath_prefix File path prefix.
     * @param array|null    $cursor          Cursor from the previous slice, null to start.
     * @param int           $time_budget     Seconds of work per slice.
     * @param callable|null $heartbeat       Optional liveness callback.
     *
     * @return array{done:bool,cursor:array,files:string[],tier:int,consistency:string}
     *
     * @throws MainWP_Exception On read/write failure.
     * @throws \Exception Propagates callback and streaming failures.
     */
    private function dump_tier2_slice_utf8mb4( $filepath_prefix, $cursor, $time_budget, $heartbeat ) { //phpcs:ignore -- NOSONAR - resumable slice loop.
        global $wpdb;
        $start                    = time();
        $this->heartbeat_bytes    = 0;
        $this->disk_floor_bytes   = 0;
        $this->disk_floor_checked = false;

        if ( null === $cursor || ! is_array( $cursor ) ) {
            // A whole-DB Tier-2 dump reads $wpdb, which on a sharded/replicated
            // topology routes to a single shard and would silently produce an
            // incomplete backup. Refuse here the same way Tier-1 does, so the
            // dedicated-connection fallback cannot bypass the topology guard.
            // The FK-rollback aside dump seeds its own cursor and never enters
            // this branch, so its targeted table list stays unaffected.
            $this->assert_supported_topology();

            $tables = $wpdb->get_col( "SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'", 0 ); // phpcs:ignore -- table inventory.
            $cursor = array(
                'tables'  => is_array( $tables ) ? $tables : array(),
                'index'   => 0,
                'started' => false,
                'pk_col'  => null,
                'last_id' => null,
                'offset'  => 0,
                'files'   => array(),
                'first'   => true,
            );
        }

        $worked      = false;
        $table_count = count( $cursor['tables'] );
        while ( $cursor['index'] < $table_count ) {
            if ( $worked && ( time() - $start ) >= $time_budget ) {
                return array(
                    'done'        => false,
                    'cursor'      => $cursor,
                    'files'       => $cursor['files'],
                    'tier'        => 2,
                    'consistency' => 'paginated',
                );
            }

            if ( null !== $heartbeat ) {
                call_user_func( $heartbeat );
            }
            $table = $cursor['tables'][ $cursor['index'] ];
            $final = $filepath_prefix . '-' . MainWP_Helper::sanitize_filename( $table ) . '.sql.php';
            $tmp   = $final . '.tmp';

            // A missing fragment under a started cursor means a failed read cleaned it
            // up; restarting the table rewrites its header instead of appending rows
            // to a headerless file.
            if ( ! $cursor['started'] || ! file_exists( $tmp ) ) {
                $this->tier2_start_table( $table, $tmp, $cursor, $heartbeat );
                $worked = true;
                continue;
            }

            try {
                $finished_rows = $this->tier2_dump_chunk( $table, $tmp, $cursor, $heartbeat );
            } catch ( \Exception $e ) {
                if ( $e instanceof MainWP_Exception && MainWP_Backup_Error::ERR_ALREADY_RUNNING === $e->get_message_error_code() ) {
                    throw $e;
                }
                // The task does not resume past this throw, so the finished tables of
                // this run and the open fragment would otherwise sit in the backup
                // directory holding database contents until stale-file handling.
                foreach ( $cursor['files'] as $file ) {
                    if ( file_exists( $file ) ) {
                        unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- partial dump cleanup.
                    }
                }
                if ( file_exists( $tmp ) ) {
                    unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- partial dump cleanup.
                }
                throw $e;
            }
            $worked = true;

            if ( null !== $heartbeat ) {
                call_user_func( $heartbeat );
            }
            if ( $finished_rows ) {
                if ( ! @rename( $tmp, $final ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged -- atomic finalize; failure returns a registered error.
                    $free = $this->free_disk_space( dirname( $final ) );
                    $code = false !== $free && $free < 4096 ? MainWP_Backup_Error::ERR_DISK_FULL : MainWP_Backup_Error::ERR_DB_RENAME_FAILED;
                    throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( $code ) ), null, $code ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped registry message.
                }
                $cursor['files'][] = $final;
                ++$cursor['index'];
                $cursor['started'] = false;
                $cursor['pk_col']  = null;
                $cursor['last_id'] = null;
                $cursor['offset']  = 0;
                if ( null !== $heartbeat ) {
                    call_user_func( $heartbeat );
                }
            }
        }

        return array(
            'done'        => true,
            'cursor'      => $cursor,
            'files'       => $cursor['files'],
            'tier'        => 2,
            'consistency' => 'paginated',
        );
    }

    /**
     * Dump a specific list of tables using the resumable tier-2 slice loop.
     *
     * Used by the restore engine's foreign-key rollback: before an in-place
     * replay overwrites the live tables, a copy of exactly the tables the
     * replay will touch is dumped aside so a failed replay can be undone. The
     * cursor is seeded from the provided list (the same shape the whole-DB seed
     * in dump_tier2_slice() builds) so every chunking, generated-column, and
     * pagination guard downstream is reused unchanged.
     *
     * @param string[]  $tables          Full table names to dump (live names).
     * @param string    $filepath_prefix Output path prefix for the per-table files.
     * @param array     $cursor          Resume cursor (empty array on first call).
     * @param float|int $time_budget     Seconds the slice may run before yielding.
     *
     * @return array { done: bool, cursor: array, files: string[], tier: int,
     *                 consistency: string }
     */
    public function dump_tables_tier2_slice( $tables, $filepath_prefix, $cursor, $time_budget = 45 ) {
        if ( ! is_array( $cursor ) || empty( $cursor ) ) {
            $cursor = array(
                'tables'  => array_values( (array) $tables ),
                'index'   => 0,
                'started' => false,
                'pk_col'  => null,
                'last_id' => null,
                'offset'  => 0,
                'files'   => array(),
                'first'   => true,
            );
        }
        $previous                 = $this->enforce_disk_floor;
        $this->enforce_disk_floor = false;
        try {
            return $this->dump_tier2_slice( $filepath_prefix, $cursor, $time_budget );
        } finally {
            $this->enforce_disk_floor = $previous;
        }
    }

    /**
     * Tier-2: write the table header (guard, banner, DROP, CREATE) and detect
     * the pagination strategy.
     *
     * @param string        $table     Table name.
     * @param string        $tmp       Temp file path.
     * @param array         $cursor    Cursor (modified in place).
     * @param callable|null $heartbeat Optional liveness callback.
     *
     * @throws MainWP_Exception On schema or write failure.
     * @throws \Throwable After closing failed header output.
     */
    protected function tier2_start_table( $table, $tmp, &$cursor, $heartbeat = null ) {
        global $wpdb;
        //phpcs:disable WordPress.DB, WordPress.WP.AlternativeFunctions -- paginated dump on the shared handle, stream writes.
        $fh = fopen( $tmp, 'w' );
        MainWP_Helper::protect_backup_path( $tmp );
        if ( false === $fh ) {
            throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_PERMISSIONS ) ), null, MainWP_Backup_Error::ERR_PERMISSIONS ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message is escaped, code is a registry class constant.
        }
        try {
            $create = $wpdb->get_row( 'SHOW CREATE TABLE ' . $this->quote_ident( $table ), ARRAY_N );
            if ( '' !== $wpdb->last_error || ! is_array( $create ) || ! isset( $create[1] ) || ! is_string( $create[1] ) || ! preg_match( '/^\s*CREATE\s+TABLE\b/i', $create[1] ) ) {
                throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_DB_QUERY_FAILED ) ), null, MainWP_Backup_Error::ERR_DB_QUERY_FAILED );
            }
            $this->write_checked( $fh, '<?php exit(); ?>', $heartbeat );
            if ( ! empty( $cursor['first'] ) ) {
                $meta = array(
                    'has_foreign_keys'  => null, // Unknown without the snapshot inventory; restore uses the grep probe.
                    'consistency'       => 'paginated',
                    'non_innodb_tables' => array(),
                    'tier'              => 2,
                );
                $this->write_checked( $fh, "\n" . $this->build_banner( $meta ), $heartbeat );
            }
            $this->write_checked( $fh, "\n\nDROP TABLE IF EXISTS " . $this->quote_ident( $table ) . ';', $heartbeat );
            $this->write_checked( $fh, "\n" . $create[1] . ";\n", $heartbeat );
        } catch ( \Throwable $e ) {
            fclose( $fh );
            if ( ! ( $e instanceof MainWP_Exception ) || MainWP_Backup_Error::ERR_ALREADY_RUNNING !== $e->get_message_error_code() ) {
                wp_delete_file( $tmp );
            }
            throw $e;
        }
        fclose( $fh );
        $cursor['first'] = false;

        // Pagination strategy: a single non-generated integer PK gets deterministic
        // PK-seek; anything else falls back to LIMIT/OFFSET. A generated PK is left
        // out of the dumped values, so it could never advance the seek cursor.
        $pk_columns = array();
        $keys       = $wpdb->get_results( 'SHOW KEYS FROM ' . $this->quote_ident( $table ) . " WHERE Key_name = 'PRIMARY'", ARRAY_A ); // phpcs:ignore -- schema read.
        foreach ( (array) $keys as $key_row ) {
            $pk_columns[ (int) $key_row['Seq_in_index'] ] = $key_row['Column_name'];
        }
        ksort( $pk_columns );
        $cursor['order_cols'] = ! empty( $pk_columns ) ? array_values( $pk_columns ) : $wpdb->get_col( 'SHOW COLUMNS FROM ' . $this->quote_ident( $table ), 0 );
        $cursor['pk_col']     = null;
        if ( 1 === count( $pk_columns ) ) {
            $pk_col   = reset( $pk_columns );
            $col_info = $wpdb->get_row( $wpdb->prepare( 'SHOW COLUMNS FROM ' . $this->quote_ident( $table ) . ' WHERE Field = %s', $pk_col ), ARRAY_A ); // phpcs:ignore -- schema read.
            if ( $col_info && preg_match( '/int|bigint|mediumint|smallint|tinyint/i', $col_info['Type'] ) && ! self::is_generated_column( $col_info['Extra'] ) ) {
                $cursor['pk_col'] = $pk_col;
            }
        }
        $cursor['last_id'] = null;
        $cursor['offset']  = 0;
        // Persist the estimate once per table so resumed slices use the same LIMIT.
        $errors         = $wpdb->suppress_errors( true );
        $avg_row_length = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT AVG_ROW_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) );
        $wpdb->suppress_errors( $errors );
        $cursor['chunk_rows'] = $avg_row_length > 0
            ? max( self::TIER2_MIN_CHUNK_ROWS, min( self::TIER2_CHUNK_ROWS, (int) floor( self::TIER2_TARGET_CHUNK_BYTES / max( $avg_row_length, 1 ) ) ) )
            : self::TIER2_CHUNK_ROWS;
        $cursor['started']    = true;
        //phpcs:enable WordPress.DB, WordPress.WP.AlternativeFunctions
    }

    /**
     * Tier-2: dump one chunk of rows. Returns true when the table is done.
     *
     * @param string        $table     Table name.
     * @param string        $tmp       Temp file path (appended to).
     * @param array         $cursor    Cursor (modified in place).
     * @param callable|null $heartbeat Optional liveness callback.
     *
     * @return bool True when no more rows remain for this table.
     *
     * @throws MainWP_Exception On read/write failure.
     */
    protected function tier2_dump_chunk( $table, $tmp, &$cursor, $heartbeat = null ) { //phpcs:ignore -- NOSONAR - chunked dump loop.
        global $wpdb;
        //phpcs:disable WordPress.DB, WordPress.WP.AlternativeFunctions -- paginated dump on the shared handle, stream writes.
        $columns = $this->get_column_map(
            function ( $sql ) use ( $wpdb ) {
                return (array) $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore -- schema read.
            },
            $table
        );

        $select_list = array();
        $insert_cols = array();
        foreach ( $columns as $col ) {
            if ( $col['generated'] ) {
                continue;
            }
            $insert_cols[] = $this->quote_ident( $col['name'] );
            if ( in_array( $col['type'], static::$geometry_types, true ) ) {
                $select_list[] = 'ST_AsWKB(' . $this->quote_ident( $col['name'] ) . ') AS ' . $this->quote_ident( $col['name'] );
            } else {
                $select_list[] = $this->quote_ident( $col['name'] );
            }
        }
        $value_columns = array_values(
            array_filter(
                $columns,
                function ( $col ) {
                    return ! $col['generated'];
                }
            )
        );

        $chunk_rows = isset( $cursor['chunk_rows'] )
            ? max( self::TIER2_MIN_CHUNK_ROWS, min( self::TIER2_CHUNK_ROWS, (int) $cursor['chunk_rows'] ) )
            : self::TIER2_CHUNK_ROWS;
        if ( null !== $cursor['pk_col'] ) {
            $sql = sprintf(
                'SELECT %s FROM %s%s ORDER BY %s ASC LIMIT %d',
                // nosemgrep: AIK_tainted-sql-string -- Select expressions contain backtick-quoted schema identifiers and the fixed ST_AsWKB function.
                implode( ', ', $select_list ),
                // nosemgrep: AIK_tainted-sql-string -- Table and primary-key identifiers are backtick-quoted, and cursor values are integers.
                $this->quote_ident( $table ),
                null === $cursor['last_id'] ? '' : ' WHERE ' . $this->quote_ident( $cursor['pk_col'] ) . ' > ' . (int) $cursor['last_id'],
                $this->quote_ident( $cursor['pk_col'] ),
                $chunk_rows
            );
        } else {
            $sql = sprintf(
                'SELECT %s FROM %s ORDER BY %s LIMIT %d OFFSET %d',
                implode( ', ', $select_list ),
                // nosec: identifier from SHOW FULL TABLES or the task's table list, backtick-quoted by quote_ident(); MySQL cannot bind identifiers.
                $this->quote_ident( $table ),
                implode( ', ', array_map( array( $this, 'quote_ident' ), $cursor['order_cols'] ) ),
                $chunk_rows,
                (int) $cursor['offset']
            );
        }

        $rows     = $wpdb->get_results( $sql, ARRAY_N ); // phpcs:ignore -- chunked dump read.
        $db_error = $wpdb->last_error;
        if ( '' !== $db_error || ! is_array( $rows ) ) {
            throw new MainWP_Exception(
                esc_html( MainWP_Backup_Error::scrub_db_credentials( "Reading table `$table` failed: " . $db_error ) ),
                null,
                MainWP_Backup_Error::ERR_DB_QUERY_FAILED // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message escaped and scrubbed, code is a registry class constant.
            );
        }
        if ( empty( $rows ) ) {
            return true;
        }

        $fh = fopen( $tmp, 'a' );
        MainWP_Helper::protect_backup_path( $tmp );
        if ( false === $fh ) {
            throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_PERMISSIONS ) ), null, MainWP_Backup_Error::ERR_PERMISSIONS ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message is escaped, code is a registry class constant.
        }

        $escaper = function ( $value ) use ( $wpdb ) {
            if ( $wpdb->dbh instanceof \mysqli ) {
                return $wpdb->dbh->real_escape_string( $value );
            }
            // File output never passes through wpdb::query() to remove its percent token.
            return str_replace( $wpdb->placeholder_escape(), '%', esc_sql( $value ) );
        };

        $pk_index = null;
        if ( null !== $cursor['pk_col'] ) {
            foreach ( $value_columns as $index => $col ) {
                if ( $col['name'] === $cursor['pk_col'] ) {
                    $pk_index = $index;
                    break;
                }
            }
        }

        $insert_prefix = 'INSERT INTO ' . $this->quote_ident( $table ) . ' (' . implode( ', ', $insert_cols ) . ') VALUES ';
        $batch         = '';
        $row_count     = 0;
        foreach ( $rows as $row ) {
            ++$row_count;
            if ( null !== $pk_index ) {
                $cursor['last_id'] = (int) $row[ $pk_index ];
            }
            $values = array();
            foreach ( $row as $index => $value ) {
                $values[] = $this->format_value( $value, $value_columns[ $index ], $escaper );
            }
            $tuple = '(' . implode( ', ', $values ) . ')';
            if ( '' === $batch ) {
                $batch = $insert_prefix . $tuple;
            } else {
                $batch .= ', ' . $tuple;
            }
            if ( strlen( $batch ) >= self::BATCH_BYTES ) {
                $this->write_checked( $fh, "\n" . $batch . ';', $heartbeat );
                $batch = '';
            }
        }
        if ( '' !== $batch ) {
            $this->write_checked( $fh, "\n" . $batch . ';', $heartbeat );
        }
        fflush( $fh );
        fclose( $fh );

        $cursor['offset'] += $row_count;
        set_time_limit( 600 ); // phpcs:ignore -- per-chunk timer reset so long dumps survive max_execution_time.
        //phpcs:enable WordPress.DB, WordPress.WP.AlternativeFunctions

        return $row_count < $chunk_rows;
    }

    /**
     * Build the restorer banner: URL-rewrite context plus backup metadata.
     *
     * @param array $meta Backup metadata (consistency, has_foreign_keys, tier).
     *
     * @return string Comment banner.
     */
    protected function build_banner( $meta ) {
        $upload = wp_upload_dir();
        $info   = array(
            'site_url'     => site_url(),
            'home_url'     => home_url(),
            'content_url'  => content_url(),
            'upload_url'   => isset( $upload['baseurl'] ) ? $upload['baseurl'] : '',
            'table_prefix' => $GLOBALS['wpdb']->prefix,
        );
        return '-- mainwp-backup-info: ' . wp_json_encode( $info ) . "\n-- mainwp-backup-meta: " . wp_json_encode( $meta );
    }

    /**
     * Escape embedded backticks because schema names are not necessarily plain identifiers.
     *
     * @param string $name Schema identifier.
     *
     * @return string Quoted identifier.
     */
    private function quote_ident( $name ) {
        return '`' . str_replace( '`', '``', (string) $name ) . '`';
    }

    /**
     * DEFAULT_GENERATED marks a default expression, so its values must still be dumped.
     *
     * @param string $extra Column EXTRA metadata.
     *
     * @return bool
     */
    private static function is_generated_column( $extra ) {
        return 1 === preg_match( '/\bGENERATED\b/i', (string) $extra );
    }

    /**
     * Read the column map for a table: name, data type, bit width, generated flag.
     *
     * @param callable $query_runner Callable executing SQL and returning assoc rows.
     * @param string   $table        Table name.
     *
     * @return array<int,array{name:string,type:string,bit_width:int,generated:bool}>
     *
     * @throws MainWP_Exception On a table name containing NUL.
     */
    protected function get_column_map( $query_runner, $table ) {
        // NUL cannot appear in a MySQL identifier; backslashes and quotes can, so the name is bound below.
        if ( false !== strpos( $table, "\0" ) ) {
            throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_DB_QUERY_FAILED ) ), null, MainWP_Backup_Error::ERR_DB_QUERY_FAILED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped registry message.
        }

        global $wpdb;
        $rows    = call_user_func(
            $query_runner,
            $wpdb->prepare( 'SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s ORDER BY ORDINAL_POSITION', $table )
        );
        $columns = array();
        foreach ( (array) $rows as $row ) {
            $row       = array_change_key_case( (array) $row, CASE_UPPER );
            $bit_width = 0;
            if ( 'bit' === strtolower( $row['DATA_TYPE'] ) && preg_match( '/bit\((\d+)\)/i', $row['COLUMN_TYPE'], $m ) ) {
                $bit_width = (int) $m[1];
            }
            $columns[] = array(
                'name'      => $row['COLUMN_NAME'],
                'type'      => strtolower( $row['DATA_TYPE'] ),
                'bit_width' => $bit_width,
                'generated' => self::is_generated_column( $row['EXTRA'] ),
            );
        }
        return $columns;
    }

    /**
     * Format one value for an INSERT statement by its column type.
     *
     * @param string|null $value   Raw value from the result set.
     * @param array       $column  Column descriptor from get_column_map().
     * @param callable    $escaper String escaper bound to the active connection.
     *
     * @return string SQL literal.
     */
    protected function format_value( $value, $column, $escaper ) { //phpcs:ignore -- NOSONAR - one branch per column type class.
        if ( null === $value ) {
            return 'NULL';
        }

        $type = $column['type'];

        if ( in_array( $type, static::$binary_types, true ) ) {
            return '' === $value ? "''" : '0x' . bin2hex( $value );
        }

        if ( 'bit' === $type ) {
            // mysqlnd returns BIT values as decimal strings; older libmysql
            // builds return raw bytes. Handle both, emit a binary literal
            // zero-padded to the column's declared width.
            if ( ctype_digit( (string) $value ) ) {
                $int = (int) $value;
            } else {
                $int = 0;
                $len = strlen( $value );
                for ( $i = 0; $i < $len; $i++ ) {
                    $int = ( $int << 8 ) | ord( $value[ $i ] );
                }
            }
            $width = $column['bit_width'] > 0 ? $column['bit_width'] : max( 1, strlen( decbin( $int ) ) );
            return "b'" . str_pad( decbin( $int ), $width, '0', STR_PAD_LEFT ) . "'";
        }

        if ( in_array( $type, static::$geometry_types, true ) ) {
            return '' === $value ? 'NULL' : 'ST_GeomFromWKB(0x' . bin2hex( $value ) . ')';
        }

        if ( in_array( $type, static::$numeric_types, true ) ) {
            return is_numeric( $value ) ? (string) $value : "'" . call_user_func( $escaper, $value ) . "'";
        }

        if ( in_array( $type, static::$datetime_types, true ) ) {
            // Verbatim quoted - zero/invalid dates restore under the
            // restorer's permissive sql_mode.
            return "'" . call_user_func( $escaper, $value ) . "'";
        }

        return "'" . call_user_func( $escaper, $value ) . "'";
    }

    /**
     * Reject failed or short writes, distinguishing confirmed disk exhaustion.
     *
     * @param resource      $fh        Open file handle.
     * @param string        $data      Data to write.
     * @param callable|null $heartbeat Optional liveness callback.
     *
     * @throws MainWP_Exception When the write fails or is short.
     */
    protected function write_checked( $fh, $data, $heartbeat = null ) {
        if ( null !== $heartbeat ) {
            call_user_func( $heartbeat );
        }
        if ( $this->enforce_disk_floor && ! $this->disk_floor_checked ) {
            $meta = stream_get_meta_data( $fh );
            MainWP_Backup::assert_disk_space_during_backup( dirname( $meta['uri'] ) );
            $this->disk_floor_checked = true;
        }
        $length = strlen( $data );
        $offset = 0;
        while ( $offset < $length ) {
            // A large SQL value must not postpone the next check by its entire length.
            $chunk_bytes = min( $length - $offset, 16 * 1024 * 1024 - $this->disk_floor_bytes );
            $written     = @fwrite( $fh, substr( $data, $offset, $chunk_bytes ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.PHP.NoSilencedErrors.Discouraged -- failure returns a registered error.
            if ( false === $written || $written < $chunk_bytes ) {
                $meta = stream_get_meta_data( $fh );
                $free = $this->free_disk_space( dirname( $meta['uri'] ) );
                $code = false !== $free && $free < max( 4096, $chunk_bytes ) ? MainWP_Backup_Error::ERR_DISK_FULL : MainWP_Backup_Error::ERR_DB_WRITE_FAILED;
                throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( $code ) ), null, $code ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped registry message.
            }
            $offset                 += $written;
            $this->disk_floor_bytes += $written;
            if ( $this->disk_floor_bytes >= 16 * 1024 * 1024 ) {
                $this->disk_floor_bytes = 0;
                if ( $this->enforce_disk_floor ) {
                    $meta = stream_get_meta_data( $fh );
                    MainWP_Backup::assert_disk_space_during_backup( dirname( $meta['uri'] ) );
                }
            }
            $this->heartbeat_bytes += $written;
            while ( null !== $heartbeat && $this->heartbeat_bytes >= 5 * 1024 * 1024 ) {
                call_user_func( $heartbeat );
                $this->heartbeat_bytes -= 5 * 1024 * 1024;
            }
        }
    }

    /**
     * Read available bytes on the target filesystem, or false when unavailable.
     *
     * @param string $directory Target directory.
     * @return float|false Free bytes.
     */
    protected function free_disk_space( $directory ) {
        if ( ! function_exists( 'disk_free_space' ) ) {
            return false;
        }
        return @disk_free_space( $directory ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unavailable reading cannot confirm disk exhaustion.
    }
}
