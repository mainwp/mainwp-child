<?php
/**
 * MainWP Backup DB Restore Engine - resumable dump replay and atomic swap.
 *
 * Replays per-table .sql.php dump files through the state-machine
 * splitter into tmp-prefixed tables on a dedicated connection, then cuts
 * over with one atomic multi-table RENAME so the live site keeps serving
 * throughout and a failed replay never touches a live table. The session
 * is permissive (ALLOW_INVALID_DATES, NO_AUTO_VALUE_ON_ZERO, FK and
 * unique checks off) so old dumps replay on MySQL 8 strict mode, and
 * INSERTs are split to fit the restore target's max_allowed_packet.
 *
 * Replay is resumable: the cursor carries the file index, the byte
 * offset, and the splitter's exported state, checkpointed by the task
 * runner at every yield. An unclean resume (watchdog stall recovery)
 * restarts the in-flight file from offset zero - each dump file leads
 * with DROP TABLE IF EXISTS, so a rerun is idempotent at the per-table
 * unit the spec defines.
 *
 * Fail isolation: a statement error marks its table FAILED and aborts
 * the replay with nothing swapped. Promoting a half-loaded table into a
 * live name would be silent data loss, so there is no continue-on-error
 * path for real errors.
 *
 * Dumps that declare foreign keys take the in-place path (fk_path):
 * statements replay against the real table names under maintenance mode
 * and there is no swap, because a tmp-prefixed copy would collide with
 * the live table's constraint names.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Backup_Db_Restore_Engine
 *
 * @package MainWP\Child
 */
class MainWP_Backup_Db_Restore_Engine { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Guard header every dump file starts with.
     */
    const GUARD = '<?php exit(); ?>';

    /**
     * Bare table stems excluded from the replay (set per slice from the
     * config). The child's own task spine is the canonical member.
     *
     * @var string[]
     */
    protected $skip_stems = array();

    /**
     * Read size per fread call.
     */
    const CHUNK_BYTES = 262144;

    /**
     * Bytes reserved for protocol overhead under max_allowed_packet.
     */
    const PACKET_OVERHEAD = 1024;

    /**
     * Errno for "MySQL server has gone away".
     */
    const ERRNO_SERVER_GONE = 2006;

    /**
     * Errno for a lost connection during query.
     */
    const ERRNO_SERVER_LOST = 2013;

    /**
     * Reconnect budget per replay statement. Attempts are fast and finish
     * out even near the slice deadline: the cursor advances per chunk, not
     * per statement, so yielding mid-statement would skip the rest of the
     * chunk on resume.
     */
    const MAX_RECONNECT_ATTEMPTS = 2;

    /**
     * Microseconds to back off before a reconnect attempt.
     */
    const RECONNECT_BACKOFF_USEC = 250000;

    /**
     * Replay one bounded slice of the dump.
     *
     * @param array     $cursor   Resume cursor (empty array on first call).
     * @param array     $config   { restore_id, source_prefix, target_prefix,
     *                            files: string[], fk_path: bool }.
     * @param float|int $deadline Absolute epoch the slice must yield by.
     * @param bool      $unclean  True when resuming after a stall; the
     *                            in-flight file restarts from offset zero.
     *
     * @return array { done: bool, cursor: array, error: string|null,
     *                 error_code: string|null }
     */
    public function replay_slice( $cursor, $config, $deadline, $unclean = false ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity -- bounded replay loop with fail isolation.
        $cursor = $this->normalize_cursor( $cursor );
        $files  = $config['files'];

        if ( $unclean && $cursor['offset'] > 0 ) {
            $cursor = $this->restart_current_file( $cursor );
        }

        $conn = $this->connect();
        if ( ! is_object( $conn ) ) {
            return $this->result( false, $cursor, 'Could not open the dedicated restore connection.', MainWP_Backup_Error::ERR_DB_CONNECTION_LOST );
        }

        if ( ! empty( $config['fk_path'] ) ) {
            $this->begin_maintenance();
        }

        $error = $this->apply_session( $conn );
        if ( null !== $error ) {
            $this->close_connection( $conn );
            return $this->result( false, $cursor, $error, MainWP_Backup_Error::ERR_RESTORE_DB_REPLAY );
        }

        $this->skip_stems = isset( $config['skip_stems'] ) ? (array) $config['skip_stems'] : array();
        $packet_budget    = $this->read_packet_budget( $conn );
        $replacement      = ! empty( $config['fk_path'] ) ? $config['target_prefix'] : $this->tmp_prefix( $config['restore_id'] );
        $rewriter         = new MainWP_Backup_Sql_Prefix_Rewriter( $config['source_prefix'], $replacement );
        $insert_split     = new MainWP_Backup_Insert_Splitter();
        $did_work         = false;
        $file_count       = count( $files );

        while ( $cursor['file_index'] < $file_count ) {
            $outcome = $this->replay_file( $conn, $cursor, $files, $rewriter, $insert_split, $replacement, $packet_budget, $deadline, $did_work );
            if ( null !== $outcome ) {
                $this->close_connection( $conn );
                return $outcome;
            }
        }

        $this->close_connection( $conn );
        return $this->result( true, $cursor, null, null );
    }

    /**
     * Replay from the cursor position in the current file.
     *
     * Returns null when the file completed and the cursor advanced to
     * the next file; returns a slice result to propagate (yield or
     * error) otherwise.
     *
     * @param object                            $conn          Connection.
     * @param array                             $cursor        Cursor (by reference).
     * @param string[]                          $files         Dump files.
     * @param MainWP_Backup_Sql_Prefix_Rewriter $rewriter    Prefix rewriter.
     * @param MainWP_Backup_Insert_Splitter     $insert_split  INSERT splitter.
     * @param string                            $replacement   Replacement prefix.
     * @param int                               $packet_budget Statement byte budget.
     * @param float|int                         $deadline      Slice deadline.
     * @param bool                              $did_work      Whether the slice did work yet (by reference).
     *
     * @return array|null Slice result to propagate, or null to continue.
     */
    private function replay_file( &$conn, &$cursor, $files, $rewriter, $insert_split, $replacement, $packet_budget, $deadline, &$did_work ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity -- streaming loop.
        $file = $files[ $cursor['file_index'] ];

        $fh = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- streaming read of a large dump.
        if ( false === $fh ) {
            return $this->result( false, $cursor, 'Could not open dump file: ' . basename( $file ), MainWP_Backup_Error::ERR_RESTORE_DB_REPLAY );
        }

        $splitter = new MainWP_Backup_Sql_Splitter();
        if ( ! empty( $cursor['splitter'] ) ) {
            $splitter->restore_state( $cursor['splitter'] );
        }

        if ( $cursor['offset'] > 0 ) {
            fseek( $fh, $cursor['offset'] );
        }

        while ( true ) {
            if ( $did_work && microtime( true ) >= $deadline ) {
                fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- paired with fopen above.
                $cursor['splitter'] = $splitter->export_state();
                return $this->result( false, $cursor, null, null );
            }

            $chunk = fread( $fh, $this->chunk_bytes() ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- streaming read.
            if ( false === $chunk || '' === $chunk ) {
                break;
            }

            if ( 0 === $cursor['offset'] && 0 === strpos( $chunk, self::GUARD ) ) {
                $chunk = substr( $chunk, strlen( self::GUARD ) );
            }
            $cursor['offset'] = ftell( $fh );

            $statements = $splitter->feed( $chunk );
            foreach ( $statements as $statement ) {
                $error = $this->process_statement( $conn, $statement, $cursor, $rewriter, $insert_split, $replacement, $packet_budget );
                if ( null !== $error ) {
                    fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- paired with fopen above.
                    return $this->result( false, $cursor, $error, MainWP_Backup_Error::ERR_RESTORE_DB_REPLAY );
                }
            }
            $did_work           = true;
            $cursor['splitter'] = $splitter->export_state();
        }

        fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- paired with fopen above.

        $tail = trim( $splitter->flush() );
        if ( '' !== $tail ) {
            return $this->result( false, $cursor, 'Dump file is truncated (unterminated statement): ' . basename( $file ), MainWP_Backup_Error::ERR_RESTORE_DB_REPLAY );
        }

        ++$cursor['file_index'];
        $cursor['offset']   = 0;
        $cursor['splitter'] = null;
        return null;
    }

    /**
     * Rewrite, split, and execute one statement.
     *
     * @param object                            $conn          Connection.
     * @param string                            $statement     Raw statement.
     * @param array                             $cursor        Cursor (by reference).
     * @param MainWP_Backup_Sql_Prefix_Rewriter $rewriter      Prefix rewriter.
     * @param MainWP_Backup_Insert_Splitter     $insert_split  INSERT splitter.
     * @param string                            $replacement   Replacement prefix.
     * @param int                               $packet_budget Statement byte budget.
     *
     * @return string|null Error text, or null on success.
     */
    private function process_statement( &$conn, $statement, &$cursor, $rewriter, $insert_split, $replacement, $packet_budget ) {
        $rewritten = $rewriter->rewrite( $statement );
        if ( null === $rewritten ) {
            ++$cursor['dropped'];
            return null;
        }

        $stem = $this->statement_stem( $rewritten, $replacement );
        if ( null !== $stem && in_array( $stem, $this->skip_stems, true ) ) {
            // Operational tables the running restore itself depends on
            // (the task spine) never replay - swapping them in would
            // clobber the in-flight task state.
            ++$cursor['dropped'];
            return null;
        }
        if ( null !== $stem && ! isset( $cursor['tables'][ $stem ] ) ) {
            $cursor['tables'][ $stem ] = array(
                'status' => 'loading',
                'rows'   => 0,
            );
        }

        $parts = array( $rewritten );
        if ( strlen( $rewritten ) > $packet_budget && preg_match( '/^\s*(INSERT|REPLACE)\s+INTO/i', $rewritten ) ) {
            $parts = $insert_split->split( $rewritten, $packet_budget );
        }

        $is_insert = (bool) preg_match( '/^\s*(INSERT|REPLACE)\s+INTO/i', $rewritten );

        foreach ( $parts as $part ) {
            $affected = $this->execute_replay_statement( $conn, $part );
            if ( is_string( $affected ) ) {
                if ( null !== $stem ) {
                    $cursor['tables'][ $stem ]['status'] = 'failed';
                }
                $table_label = null !== $stem ? $stem : 'unknown';
                return 'Replay failed on table ' . $table_label . ': ' . $affected;
            }
            if ( $is_insert && null !== $stem ) {
                $cursor['tables'][ $stem ]['rows'] += (int) $affected;
            }
        }

        if ( null !== $stem && 'loading' === $cursor['tables'][ $stem ]['status'] ) {
            $cursor['tables'][ $stem ]['status'] = 'ok';
        }

        return null;
    }

    /**
     * Promote successfully loaded tmp tables with one atomic RENAME.
     *
     * @param string[] $stems  Bare table stems (prefix stripped).
     * @param array    $config Replay config.
     *
     * @return array { done: bool, old_tables: string[], rename_sql: string,
     *                 error: string|null, error_code: string|null }
     */
    public function swap_tables( $stems, $config ) {
        $conn = $this->connect();
        if ( ! is_object( $conn ) ) {
            return array(
                'done'       => false,
                'old_tables' => array(),
                'rename_sql' => '',
                'error'      => 'Could not open the dedicated restore connection.',
                'error_code' => MainWP_Backup_Error::ERR_DB_CONNECTION_LOST,
            );
        }

        $live   = $this->list_tables( $conn );
        $packet = $this->read_packet_budget( $conn );
        $sql    = $this->build_rename_sql( $stems, $config['restore_id'], $config['target_prefix'], $live, $packet );

        if ( null === $sql ) {
            $this->close_connection( $conn );
            return array(
                'done'       => false,
                'old_tables' => array(),
                'rename_sql' => '',
                'error'      => 'The table swap statement exceeds max_allowed_packet; the swap cannot run atomically.',
                'error_code' => MainWP_Backup_Error::ERR_RESTORE_SWAP_FAILED,
            );
        }

        $old_tables = array();
        foreach ( $stems as $stem ) {
            if ( in_array( $config['target_prefix'] . $stem, $live, true ) ) {
                $old_tables[] = $this->old_prefix( $config['restore_id'] ) . $stem;
            }
        }

        $outcome = $this->execute_statement( $conn, $sql );
        $this->close_connection( $conn );

        if ( is_string( $outcome ) ) {
            return array(
                'done'       => false,
                'old_tables' => array(),
                'rename_sql' => $sql,
                'error'      => 'The table swap failed: ' . $outcome,
                'error_code' => MainWP_Backup_Error::ERR_RESTORE_SWAP_FAILED,
            );
        }

        return array(
            'done'       => true,
            'old_tables' => $old_tables,
            'rename_sql' => $sql,
            'error'      => null,
            'error_code' => null,
        );
    }

    /**
     * Build the single atomic multi-table RENAME statement.
     *
     * Live tables move aside to old-prefixed names in the same statement
     * that promotes the tmp tables, so no table name is ever missing. A
     * statement that would not fit max_allowed_packet returns null - a
     * split swap is not atomic and a crash between parts would leave a
     * permanently mixed schema.
     *
     * @param string[] $stems         Bare table stems.
     * @param string   $restore_id    Restore id used in the prefixes.
     * @param string   $target_prefix Live table prefix.
     * @param string[] $live_tables   Current live table names.
     * @param int      $max_packet    max_allowed_packet of the target.
     *
     * @return string|null RENAME statement, or null when over budget.
     */
    public function build_rename_sql( $stems, $restore_id, $target_prefix, $live_tables, $max_packet ) {
        $pairs = array();
        foreach ( $stems as $stem ) {
            $target = $target_prefix . $stem;
            if ( in_array( $target, $live_tables, true ) ) {
                $pairs[] = '`' . $target . '` TO `' . $this->old_prefix( $restore_id ) . $stem . '`';
            }
            $pairs[] = '`' . $this->tmp_prefix( $restore_id ) . $stem . '` TO `' . $target . '`';
        }

        $sql = 'RENAME TABLE ' . implode( ', ', $pairs );
        if ( strlen( $sql ) > $max_packet - self::PACKET_OVERHEAD ) {
            return null;
        }
        return $sql;
    }

    /**
     * Compare live row counts against the counts recorded during replay.
     *
     * @param array  $expected      Rows by stem.
     * @param string $target_prefix Live table prefix.
     *
     * @return array { ok: bool, mismatches: array<string,array{expected:int,actual:int}> }
     */
    public function verify_row_counts( $expected, $target_prefix ) {
        $conn = $this->connect();
        if ( ! is_object( $conn ) ) {
            return array(
                'ok'         => false,
                'mismatches' => array(),
            );
        }

        $mismatches = array();
        foreach ( $expected as $stem => $rows ) {
            $result = $this->query_rows( $conn, 'SELECT COUNT(*) AS c FROM `' . $target_prefix . $stem . '`' );
            $actual = ( is_array( $result ) && isset( $result[0]['c'] ) ) ? (int) $result[0]['c'] : -1;
            if ( $actual !== (int) $rows ) {
                $mismatches[ $stem ] = array(
                    'expected' => (int) $rows,
                    'actual'   => $actual,
                );
            }
        }
        $this->close_connection( $conn );

        return array(
            'ok'         => empty( $mismatches ),
            'mismatches' => $mismatches,
        );
    }

    /**
     * Drop the old-prefixed aside tables after verification passed.
     *
     * @param string $restore_id Restore id.
     */
    public function drop_old_tables( $restore_id ) {
        $this->drop_tables_by_prefix( $this->old_prefix( $restore_id ) );
    }

    /**
     * Drop any tmp-prefixed tables a failed restore left behind.
     *
     * @param string $restore_id Restore id.
     */
    public function cleanup_tmp_tables( $restore_id ) {
        $this->drop_tables_by_prefix( $this->tmp_prefix( $restore_id ) );
    }

    /**
     * Public maintenance-mode exit for the runner's phase transitions
     * and terminal failures.
     */
    public function exit_maintenance() {
        $this->end_maintenance();
    }

    /**
     * Read the dump banner from the first table file.
     *
     * @param string $file Dump file path.
     *
     * @return array { info: array|null, meta: array|null }
     */
    public function read_banner( $file ) {
        $head = file_get_contents( $file, false, null, 0, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- bounded local read.
        if ( false === $head ) {
            return array(
                'info' => null,
                'meta' => null,
            );
        }

        return array(
            'info' => $this->parse_banner_line( $head, '-- mainwp-backup-info: ' ),
            'meta' => $this->parse_banner_line( $head, '-- mainwp-backup-meta: ' ),
        );
    }

    /**
     * Probe dump files for FOREIGN KEY clauses.
     *
     * Used when the banner lacks the has_foreign_keys flag (tier-2
     * dumps record null). Scans in chunks with overlap so a clause
     * spanning a read boundary is still found.
     *
     * @param string[] $files Dump file paths.
     *
     * @return bool True when any file declares a foreign key.
     */
    public function probe_fk( $files ) {
        $needle  = 'FOREIGN KEY';
        $overlap = strlen( $needle ) - 1;

        foreach ( $files as $file ) {
            $fh = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- streaming scan.
            if ( false === $fh ) {
                continue;
            }
            $carry = '';
            while ( ! feof( $fh ) ) {
                $chunk = fread( $fh, $this->chunk_bytes() ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- streaming scan.
                if ( false === $chunk || '' === $chunk ) {
                    break;
                }
                if ( false !== stripos( $carry . $chunk, $needle ) ) {
                    fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- paired with fopen above.
                    return true;
                }
                $carry = substr( $chunk, -$overlap );
            }
            fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- paired with fopen above.
        }

        return false;
    }

    /**
     * The tmp table prefix for a restore id.
     *
     * @param string $restore_id Restore id.
     *
     * @return string Prefix.
     */
    public function tmp_prefix( $restore_id ) {
        return 'tmp' . $restore_id . '_';
    }

    /**
     * The aside table prefix for a restore id.
     *
     * @param string $restore_id Restore id.
     *
     * @return string Prefix.
     */
    public function old_prefix( $restore_id ) {
        return 'old' . $restore_id . '_';
    }

    /**
     * Extract one banner JSON line.
     *
     * @param string $head   File head bytes.
     * @param string $marker Line marker.
     *
     * @return array|null Decoded JSON or null.
     */
    private function parse_banner_line( $head, $marker ) {
        $pos = strpos( $head, $marker );
        if ( false === $pos ) {
            return null;
        }
        $start = $pos + strlen( $marker );
        $end   = strpos( $head, "\n", $start );
        $json  = false === $end ? substr( $head, $start ) : substr( $head, $start, $end - $start );
        $data  = json_decode( trim( $json ), true );
        return is_array( $data ) ? $data : null;
    }

    /**
     * Apply the permissive replay session.
     *
     * @param object $conn Connection.
     *
     * @return string|null Error text or null.
     */
    private function apply_session( $conn ) {
        $session = array(
            "SET SESSION sql_mode = 'ALLOW_INVALID_DATES,NO_AUTO_VALUE_ON_ZERO'",
            'SET FOREIGN_KEY_CHECKS=0',
            'SET UNIQUE_CHECKS=0',
        );
        foreach ( $session as $sql ) {
            $outcome = $this->execute_statement( $conn, $sql );
            if ( is_string( $outcome ) ) {
                return 'Could not apply the restore session: ' . $outcome;
            }
        }

        // Best-effort timeout tuning: long replays on managed hosts die to
        // idle/net timeouts mid-slice; a host that refuses these SETs must
        // not abort the replay. Runs through the one-shot path on purpose -
        // apply_session() is re-run by reconnect_mid_replay(), and routing
        // these through the retrying path would nest reconnects.
        $tuning = array(
            'SET SESSION wait_timeout = 28800',
            'SET SESSION net_read_timeout = 120',
            'SET SESSION net_write_timeout = 120',
        );
        foreach ( $tuning as $sql ) {
            $this->execute_statement( $conn, $sql );
        }

        return null;
    }

    /**
     * Read max_allowed_packet minus overhead.
     *
     * @param object $conn Connection.
     *
     * @return int Statement byte budget.
     */
    private function read_packet_budget( $conn ) {
        $rows   = $this->query_rows( $conn, 'SELECT @@max_allowed_packet AS map' );
        $packet = ( is_array( $rows ) && isset( $rows[0]['map'] ) ) ? (int) $rows[0]['map'] : 1048576;
        $budget = $packet - self::PACKET_OVERHEAD;
        return $budget > 4096 ? $budget : 4096;
    }

    /**
     * List current table names.
     *
     * @param object $conn Connection.
     *
     * @return string[] Table names.
     */
    private function list_tables( $conn ) {
        $rows   = $this->query_rows( $conn, 'SHOW TABLES' );
        $tables = array();
        foreach ( (array) $rows as $row ) {
            $values = array_values( (array) $row );
            if ( isset( $values[0] ) ) {
                $tables[] = (string) $values[0];
            }
        }
        return $tables;
    }

    /**
     * Drop every table carrying the given prefix.
     *
     * @param string $prefix Table name prefix.
     */
    private function drop_tables_by_prefix( $prefix ) {
        $conn = $this->connect();
        if ( ! is_object( $conn ) ) {
            return;
        }
        foreach ( $this->list_tables( $conn ) as $table ) {
            if ( 0 === strpos( $table, $prefix ) ) {
                $this->execute_statement( $conn, 'DROP TABLE IF EXISTS `' . $table . '`' );
            }
        }
        $this->close_connection( $conn );
    }

    /**
     * Drop the aside (`old<id>_`) and staging (`tmp<id>_`) tables left behind
     * by a set of prior restores, in a single SHOW TABLES pass.
     *
     * Driven by restore ids the caller read from real task rows, so the
     * prefix match only ever hits this engine's own aside/staging tables -
     * an unrelated table such as `oldarchive_2024` is never matched because
     * its id segment is not in the set. The trailing underscore on each
     * prefix keeps one id from matching another's tables.
     *
     * @param string[] $restore_ids Restore ids whose leftovers to drop.
     */
    public function prune_aside_tables( array $restore_ids ) {
        if ( empty( $restore_ids ) ) {
            return;
        }
        $conn = $this->connect();
        if ( ! is_object( $conn ) ) {
            return;
        }
        $prefixes = array();
        foreach ( array_unique( array_map( 'strval', $restore_ids ) ) as $restore_id ) {
            if ( '' === $restore_id ) {
                continue; // A blank id collapses old<id>_/tmp<id>_ to old_/tmp_, which would match unrelated tables.
            }
            $prefixes[] = $this->old_prefix( $restore_id );
            $prefixes[] = $this->tmp_prefix( $restore_id );
        }
        if ( empty( $prefixes ) ) {
            $this->close_connection( $conn );
            return;
        }
        foreach ( $this->list_tables( $conn ) as $table ) {
            foreach ( $prefixes as $prefix ) {
                if ( 0 === strpos( $table, $prefix ) ) {
                    $this->execute_statement( $conn, 'DROP TABLE IF EXISTS `' . $table . '`' );
                    break;
                }
            }
        }
        $this->close_connection( $conn );
    }

    /**
     * Pull the bare table stem out of a rewritten statement.
     *
     * The rewriter normalizes the table token to backticks, so the
     * first backticked token is the table for every statement kind it
     * lets through.
     *
     * @param string $rewritten   Rewritten statement.
     * @param string $replacement Replacement prefix.
     *
     * @return string|null Bare stem or null for table-less statements.
     */
    private function statement_stem( $rewritten, $replacement ) {
        if ( ! preg_match( '/`((?:[^`]|``)+)`/', $rewritten, $m ) ) {
            return null;
        }
        $name = str_replace( '``', '`', $m[1] );
        if ( 0 !== strpos( $name, $replacement ) ) {
            return null;
        }
        return substr( $name, strlen( $replacement ) );
    }

    /**
     * Reset the in-flight file for an unclean resume.
     *
     * The rerun is idempotent because each dump file leads with
     * DROP TABLE IF EXISTS for its own table.
     *
     * @param array $cursor Cursor.
     *
     * @return array Cursor with the current file restarted.
     */
    private function restart_current_file( $cursor ) {
        $cursor['offset']   = 0;
        $cursor['splitter'] = null;
        return $cursor;
    }

    /**
     * Fill cursor defaults.
     *
     * @param array $cursor Cursor or empty array.
     *
     * @return array Normalized cursor.
     */
    private function normalize_cursor( $cursor ) {
        return array_merge(
            array(
                'file_index' => 0,
                'offset'     => 0,
                'splitter'   => null,
                'tables'     => array(),
                'dropped'    => 0,
            ),
            is_array( $cursor ) ? $cursor : array()
        );
    }

    /**
     * Build a slice result.
     *
     * @param bool        $done       Whether the replay completed.
     * @param array       $cursor     Cursor.
     * @param string|null $error      Error text.
     * @param string|null $error_code Error code.
     *
     * @return array Slice result.
     */
    private function result( $done, $cursor, $error, $error_code ) {
        return array(
            'done'       => $done,
            'cursor'     => $cursor,
            'error'      => $error,
            'error_code' => $error_code,
        );
    }

    /**
     * Open the dedicated mysqli connection (mirrors the dumper).
     *
     * @return \mysqli|object|null Connection or null on failure.
     */
    protected function connect() {
        $params = MainWP_Backup_Db_Dumper::instance()->get_connection_params();

        //phpcs:disable WordPress.DB -- raw mysqli is the point: a dedicated connection independent of $wpdb.
        $mysqli = mysqli_init();
        $mysqli->options( MYSQLI_OPT_CONNECT_TIMEOUT, 10 );
        $connected = @$mysqli->real_connect( // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- failure handled by the caller with a coded error.
            $params['host'],
            $params['user'],
            $params['password'],
            $params['name'],
            null !== $params['port'] ? (int) $params['port'] : 0,
            null !== $params['socket'] ? $params['socket'] : '',
            MainWP_Backup_Db_Dumper::resolve_client_flags() // Match the dump connection so SSL-required hosts restore too.
        );
        if ( ! $connected ) {
            return null;
        }
        $mysqli->set_charset( 'utf8mb4' );
        //phpcs:enable WordPress.DB
        return $mysqli;
    }

    /**
     * Close the dedicated connection.
     *
     * @param object $conn Connection.
     */
    protected function close_connection( $conn ) {
        if ( $conn instanceof \mysqli ) {
            @$conn->close(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort close.
        }
    }

    /**
     * Probe that the dedicated restore connection holds the grants the restore
     * needs: CREATE, RENAME (ALTER), and DROP for the swap, plus INSERT and
     * SELECT for the foreign-key rollback (it refills the live tables from the
     * aside copy with INSERT ... SELECT). Exercises them on a throwaway table so
     * a missing grant fails the restore preflight instead of mid-swap or
     * mid-rollback, after transfer work is already spent.
     *
     * Inconclusive cases never block a restore: a connection that cannot be
     * opened returns true and lets the replay surface the real error with its
     * own code.
     *
     * @return bool False only when a required grant is confirmed missing.
     */
    public function probe_swap_grants() {
        $conn = $this->connect();
        if ( null === $conn ) {
            return true;
        }

        $base = 'mainwp_restore_preflight_' . substr( md5( uniqid( '', true ) ), 0, 12 );
        $a    = $base . '_a';
        $b    = $base . '_b';

        // Best-effort clear of any prior litter, then CREATE -> INSERT -> SELECT
        // -> RENAME -> DROP, exactly the grants the swap, the rollback refill,
        // and the old-table cleanup exercise.
        $this->execute_statement( $conn, 'DROP TABLE IF EXISTS `' . $a . '`, `' . $b . '`' );

        $ok = is_int( $this->execute_statement( $conn, 'CREATE TABLE `' . $a . '` ( id INT )' ) );
        if ( $ok ) {
            $ok = is_int( $this->execute_statement( $conn, 'INSERT INTO `' . $a . '` (id) VALUES (1)' ) );
        }
        if ( $ok ) {
            $ok = is_array( $this->query_rows( $conn, 'SELECT id FROM `' . $a . '`' ) );
        }
        if ( $ok ) {
            $ok = is_int( $this->execute_statement( $conn, 'RENAME TABLE `' . $a . '` TO `' . $b . '`' ) );
        }
        if ( $ok ) {
            $ok = is_int( $this->execute_statement( $conn, 'DROP TABLE IF EXISTS `' . $b . '`' ) );
        }

        // Drop whatever survived, regardless of the outcome above.
        $this->execute_statement( $conn, 'DROP TABLE IF EXISTS `' . $a . '`, `' . $b . '`' );
        $this->close_connection( $conn );

        return $ok;
    }

    /**
     * Read the table name a per-table dump file leads with.
     *
     * Each dump file opens with the PHP guard, an optional banner, then a
     * `DROP TABLE IF EXISTS` / `CREATE TABLE` naming its one table. The
     * foreign-key snapshot needs the table set before the replay runs (the
     * replay cursor only records stems as it touches them), so the name is read
     * from the file head rather than derived from the sanitized file name.
     *
     * @param string $file Dump file path.
     *
     * @return string|null Backticked table name decoded, or null when none is found.
     */
    public function peek_table_name( $file ) {
        $head = file_get_contents( $file, false, null, 0, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- bounded local read.
        if ( false === $head ) {
            return null;
        }
        if ( ! preg_match( '/(?:DROP\s+TABLE(?:\s+IF\s+EXISTS)?|CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?)\s+`((?:[^`]|``)+)`/i', $head, $m ) ) {
            return null;
        }
        return str_replace( '``', '`', $m[1] );
    }

    /**
     * Split the dump's tables into live tables that exist and tables the dump
     * would create fresh, scoped to what the foreign-key rollback must protect.
     *
     * Each dump file names a source-prefixed table; the live equivalent is the
     * target prefix plus the bare stem. Tables on the skip list (the running
     * restore's own spine) and tables whose dump name does not carry the source
     * prefix (the replay would drop those, never touching a live table) are
     * excluded.
     *
     * @param string[] $files         Dump file paths.
     * @param string   $source_prefix Prefix the dump's table names carry.
     * @param string   $target_prefix Live table prefix.
     * @param string[] $skip_stems    Bare stems never replayed.
     *
     * @return array { existing: string[] live table names, new_stems: string[] bare stems }
     */
    public function list_dump_live_tables( $files, $source_prefix, $target_prefix, $skip_stems = array() ) {
        $conn = $this->connect();
        $live = is_object( $conn ) ? $this->list_tables( $conn ) : array();
        if ( is_object( $conn ) ) {
            $this->close_connection( $conn );
        }

        $existing = array();
        $new      = array();
        foreach ( (array) $files as $file ) {
            $name = $this->peek_table_name( $file );
            if ( null === $name || '' === $source_prefix || 0 !== strpos( $name, $source_prefix ) ) {
                continue;
            }
            $stem = substr( $name, strlen( $source_prefix ) );
            if ( in_array( $stem, (array) $skip_stems, true ) ) {
                continue;
            }
            $live_name = $target_prefix . $stem;
            if ( in_array( $live_name, $live, true ) ) {
                $existing[] = $live_name;
            } else {
                $new[] = $stem;
            }
        }

        return array(
            'existing'  => array_values( array_unique( $existing ) ),
            'new_stems' => array_values( array_unique( $new ) ),
        );
    }

    /**
     * Dump one bounded slice of the foreign-key rollback snapshot.
     *
     * Drives the resumable tier-2 dumper over the named live tables, writing
     * aside per-table dump files the rollback replays back on failure. A dumper
     * failure (disk, permissions) returns a coded error rather than throwing, so
     * the snapshot phase can fail cleanly before any live table is touched.
     *
     * @param array     $cursor   Dumper cursor (empty array on first call).
     * @param array     $config   { tables: string[], aside_prefix: string }.
     * @param float|int $deadline Absolute epoch the slice must yield by.
     *
     * @return array { done: bool, cursor: array, files: string[],
     *                 error: string|null, error_code: string|null }
     */
    public function snapshot_tables_slice( $cursor, $config, $deadline ) {
        $budget = (int) ( $deadline - microtime( true ) );
        if ( $budget < 1 ) {
            $budget = 1;
        }

        try {
            $result = MainWP_Backup_Db_Dumper::instance()->dump_tables_tier2_slice( $config['tables'], $config['aside_prefix'], $cursor, $budget );
        } catch ( \Throwable $e ) {
            return array(
                'done'       => false,
                'cursor'     => $cursor,
                'files'      => array(),
                'error'      => $e->getMessage(),
                'error_code' => MainWP_Backup_Error::ERR_RESTORE_SNAPSHOT_FAILED,
            );
        }

        return array(
            'done'       => ! empty( $result['done'] ),
            'cursor'     => $result['cursor'],
            'files'      => isset( $result['files'] ) ? $result['files'] : array(),
            'error'      => null,
            'error_code' => null,
        );
    }

    /**
     * Restore the live tables from the foreign-key rollback snapshot.
     *
     * First (once, idempotently) drops every table the failed replay could have
     * touched under FOREIGN_KEY_CHECKS=0 - both the tables that existed and the
     * ones the replay created from scratch - which frees the schema-global FK
     * constraint names. Then replays the aside dump back through the tested
     * replay engine. The replay config MUST set source and target to the live
     * prefix: the aside files carry live table names, and the prefix rewriter
     * drops any statement whose table does not match the source prefix, so the
     * dump's origin prefix would silently discard every aside statement.
     *
     * @param array     $cursor   Rollback cursor { drop_done: bool, replay: array }.
     * @param array     $config   { drop_tables: string[], aside_files: string[],
     *                            target_prefix: string, restore_id: string,
     *                            skip_stems: string[] }.
     * @param float|int $deadline Absolute epoch the slice must yield by.
     * @param bool      $unclean  True on a stall resume; restarts the in-flight file.
     *
     * @return array { done: bool, cursor: array, error: string|null, error_code: string|null }
     */
    public function rollback_aside_slice( $cursor, $config, $deadline, $unclean = false ) {
        $cursor = is_array( $cursor ) ? $cursor : array();
        if ( empty( $cursor['drop_done'] ) ) {
            $conn = $this->connect();
            if ( ! is_object( $conn ) ) {
                return array(
                    'done'       => false,
                    'cursor'     => $cursor,
                    'error'      => 'Could not open the dedicated restore connection.',
                    'error_code' => MainWP_Backup_Error::ERR_RESTORE_ROLLBACK_FAILED,
                );
            }
            $this->execute_statement( $conn, 'SET FOREIGN_KEY_CHECKS=0' );
            foreach ( (array) $config['drop_tables'] as $table ) {
                $outcome = $this->execute_statement( $conn, 'DROP TABLE IF EXISTS `' . $table . '`' );
                if ( is_string( $outcome ) ) {
                    $this->close_connection( $conn );
                    return array(
                        'done'       => false,
                        'cursor'     => $cursor,
                        'error'      => 'Rollback could not drop the half-restored table ' . $table . ': ' . $outcome,
                        'error_code' => MainWP_Backup_Error::ERR_RESTORE_ROLLBACK_FAILED,
                    );
                }
            }
            $this->close_connection( $conn );
            $cursor['drop_done'] = true;
        }

        $replay_config = array(
            'restore_id'    => $config['restore_id'],
            'source_prefix' => $config['target_prefix'],
            'target_prefix' => $config['target_prefix'],
            'files'         => $config['aside_files'],
            'fk_path'       => true,
            'skip_stems'    => isset( $config['skip_stems'] ) ? $config['skip_stems'] : array(),
        );

        $replay_cursor    = isset( $cursor['replay'] ) ? $cursor['replay'] : array();
        $outcome          = $this->replay_slice( $replay_cursor, $replay_config, $deadline, $unclean );
        $cursor['replay'] = $outcome['cursor'];

        return array(
            'done'       => $outcome['done'],
            'cursor'     => $cursor,
            'error'      => $outcome['error'],
            'error_code' => null !== $outcome['error'] ? MainWP_Backup_Error::ERR_RESTORE_ROLLBACK_FAILED : null,
        );
    }

    /**
     * Execute one statement on the dedicated connection, one shot.
     *
     * Non-replay callers (the atomic swap, aside pruning, grant probing)
     * use this directly: their statements are not safely re-executable
     * after an ambiguous connection loss, so a transient error here is
     * surfaced as-is rather than retried.
     *
     * @param object $conn Connection.
     * @param string $sql  Statement.
     *
     * @return int|string Affected row count, or error text on failure.
     */
    protected function execute_statement( $conn, $sql ) {
        return $this->run_statement( $conn, $sql );
    }

    /**
     * Run one statement on the connection (the raw query seam).
     *
     * @param object $conn Connection.
     * @param string $sql  Statement.
     *
     * @return int|string Affected row count, or error text on failure.
     */
    protected function run_statement( $conn, $sql ) {
        //phpcs:disable WordPress.DB -- raw mysqli replay on the dedicated connection.
        $result = $conn->query( $sql );
        if ( false === $result ) {
            return (string) $conn->error;
        }
        if ( $result instanceof \mysqli_result ) {
            $result->free();
        }
        $affected = (int) $conn->affected_rows;
        //phpcs:enable WordPress.DB
        return $affected >= 0 ? $affected : 0;
    }

    /**
     * Read the connection's last error number.
     *
     * @param object $conn Connection.
     *
     * @return int Error number, 0 when unavailable.
     */
    protected function connection_errno( $conn ) {
        return ( $conn instanceof \mysqli ) ? (int) $conn->errno : 0;
    }

    /**
     * Execute one replay statement with a bounded reconnect-and-retry on
     * transient connection loss.
     *
     * Only the streamed replay uses this: its statements land in the
     * tmp-prefixed (or aside) tables under autocommit, so re-executing a
     * statement whose connection died is safe - InnoDB rolls the in-flight
     * statement back server-side. The swap RENAME and the other one-shot
     * callers must NOT route through here (see execute_statement()).
     *
     * @param object $conn Connection (by reference: replaced on reconnect).
     * @param string $sql  Statement.
     *
     * @return int|string Affected row count, or error text on failure.
     */
    protected function execute_replay_statement( &$conn, $sql ) {
        $attempts = 0;
        while ( true ) {
            $outcome = $this->run_statement( $conn, $sql );
            if ( ! is_string( $outcome ) ) {
                return $outcome;
            }
            $errno = $this->connection_errno( $conn );
            if ( $attempts >= self::MAX_RECONNECT_ATTEMPTS || ! $this->is_transient_errno( $errno ) ) {
                if ( $attempts > 0 ) {
                    return $outcome . ' (after ' . $attempts . ' reconnect ' . ( 1 === $attempts ? 'attempt' : 'attempts' ) . ')';
                }
                return $outcome;
            }
            ++$attempts;
            MainWP_Helper::log_debug( 'restore replay: transient db error (errno ' . $errno . '), reconnect attempt ' . $attempts . '/' . self::MAX_RECONNECT_ATTEMPTS );
            usleep( self::RECONNECT_BACKOFF_USEC );
            if ( ! $this->reconnect_mid_replay( $conn ) ) {
                return $outcome . ' (reconnect attempt ' . $attempts . ' failed)';
            }
        }
    }

    /**
     * Whether the errno signals a retryable connection loss.
     *
     * @param int $errno mysqli error number.
     *
     * @return bool
     */
    private function is_transient_errno( $errno ) {
        return in_array( (int) $errno, array( self::ERRNO_SERVER_GONE, self::ERRNO_SERVER_LOST ), true );
    }

    /**
     * Replace a dead replay connection with a fresh, session-applied one.
     *
     * @param object $conn Connection (by reference).
     *
     * @return bool True when the new connection is ready.
     */
    private function reconnect_mid_replay( &$conn ) {
        $this->close_connection( $conn );
        $fresh = $this->connect();
        if ( ! is_object( $fresh ) ) {
            return false;
        }
        $conn  = $fresh;
        $error = $this->apply_session( $conn );
        if ( null !== $error ) {
            $this->close_connection( $conn );
            return false;
        }
        return true;
    }

    /**
     * Run a query returning rows.
     *
     * @param object $conn Connection.
     * @param string $sql  Query.
     *
     * @return array|null Assoc rows or null on failure.
     */
    protected function query_rows( $conn, $sql ) {
        //phpcs:disable WordPress.DB -- raw mysqli on the dedicated connection.
        $result = $conn->query( $sql );
        if ( false === $result || ! ( $result instanceof \mysqli_result ) ) {
            return null;
        }
        $rows = array();
        while ( $row = $result->fetch_assoc() ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition -- standard mysqli fetch loop.
            $rows[] = $row;
        }
        $result->free();
        //phpcs:enable WordPress.DB
        return $rows;
    }

    /**
     * Bytes per fread.
     *
     * @return int Chunk size.
     */
    protected function chunk_bytes() {
        return self::CHUNK_BYTES;
    }

    /**
     * Enter WordPress core maintenance mode.
     *
     * Public so the runner can hold the site offline across the snapshot,
     * replay, and rollback phases (and the WooCommerce preserve/merge phases),
     * mirroring the public exit_maintenance() entry point. The runner calls
     * this cross-class, so it cannot be protected.
     *
     * Re-entrant: each call refreshes the timestamp so long in-place
     * restores do not fall out of maintenance after ten minutes.
     */
    public function begin_maintenance() {
        $file = ABSPATH . '.maintenance';
        file_put_contents( $file, '<?php $upgrading = ' . time() . '; ?>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- core maintenance marker.
    }

    /**
     * Leave WordPress core maintenance mode.
     */
    protected function end_maintenance() {
        $file = ABSPATH . '.maintenance';
        if ( file_exists( $file ) ) {
            @unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions -- best-effort removal.
        }
    }
}
