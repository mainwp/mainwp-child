<?php
/**
 * MainWP Backup URL Rewriter - paginated serialization-aware replacement.
 *
 * Rewrites URL (and, on cross-prefix restores, table-prefix) strings in
 * the swapped live tables, page by page, with a cursor the task runner
 * checkpoints so the rewrite resumes mid-table. Serialized values are
 * unserialized, replaced member by member, and re-serialized so string
 * length prefixes stay correct - a naive str_replace corrupts every
 * serialized option whose replacement changes the URL length.
 *
 * Unserialization is restricted (no classes): a serialized object comes
 * back as an incomplete-class stub whose properties are left untouched
 * and which re-serializes byte-identical, so hostile or plugin-defined
 * objects can neither execute wakeup gadgets nor be corrupted. The
 * trade-off is that URLs inside serialized object properties are not
 * rewritten; WordPress core data is arrays and strings throughout.
 *
 * The posts guid column is excluded by configuration - GUIDs must not
 * change on restore.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Backup_Url_Rewriter
 *
 * @package MainWP\Child
 */
class MainWP_Backup_Url_Rewriter { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Rows per page.
     */
    const PAGE_SIZE = 5000;

    /**
     * Count of row writes that failed during this run.
     *
     * @var int
     */
    private $failed_updates = 0;

    /**
     * Deleted stale rename targets, by table, for pagination and count verification.
     *
     * @var array
     */
    private $renamed_over = array();

    /**
     * The last write error seen during this run.
     *
     * @var string
     */
    private $last_error = '';

    /**
     * Rewrite one bounded slice.
     *
     * @param array     $cursor   Resume cursor (empty array on first call).
     * @param array     $config   { tables: string[], pairs: array<int,array{from:string,to:string}>,
     *                            table_pairs?: array<string,array>, prefix_pair?: array{from:string,to:string},
     *                            prefix_anchored?: array<string,string[]>,
     *                            exclude: array<string,string[]>, preserve_options?: array<string,string[]>,
     *                            preserve_option_prefixes?: array<string,string[]>, rename_option_names?: array<string,array<string,string>>,
     *                            rename_columns?: array<string,array<string,array<string,string>>> }.
     *                            Exact renames are keyed by table, column and original value.
     *                            They override pair exclusions; primary keys remain untouched.
     * @param float|int $deadline Absolute epoch the slice must yield by.
     *
     * @return array { done: bool, cursor: array, renamed_over: array<string,int>,
     *                 failed_updates: int, last_error: string }
     */
    public function rewrite_slice( $cursor, $config, $deadline ) {
        $cursor = array_merge(
            array(
                'table_index' => 0,
                'offset'      => 0,
            ),
            is_array( $cursor ) ? $cursor : array()
        );

        $tables      = $config['tables'];
        $did_work    = false;
        $table_count = count( $tables );

        while ( $cursor['table_index'] < $table_count ) {
            if ( $did_work && microtime( true ) >= $deadline ) {
                return array(
                    'done'           => false,
                    'cursor'         => $cursor,
                    'renamed_over'   => $this->renamed_over,
                    'failed_updates' => $this->failed_updates,
                    'last_error'     => $this->last_error,
                );
            }

            $table                    = $tables[ $cursor['table_index'] ];
            $exclude                  = isset( $config['exclude'][ $table ] ) ? $config['exclude'][ $table ] : array();
            $preserve_options         = isset( $config['preserve_options'][ $table ] ) ? $config['preserve_options'][ $table ] : array();
            $preserve_option_prefixes = isset( $config['preserve_option_prefixes'][ $table ] ) ? $config['preserve_option_prefixes'][ $table ] : array();
            $rename_columns           = isset( $config['rename_columns'][ $table ] ) ? $config['rename_columns'][ $table ] : array();
            if ( isset( $config['rename_option_names'][ $table ] ) && 'options' === substr( $table, -7 ) ) {
                $rename_columns['option_name'] = $config['rename_option_names'][ $table ];
            }
            $pairs           = $config['table_pairs'][ $table ] ?? $config['pairs'];
            $prefix_anchored = $config['prefix_anchored'][ $table ] ?? array();
            $prefix_pair     = $config['prefix_pair'] ?? array();
            $consumed        = $this->rewrite_page( $table, $cursor['offset'], $pairs, $exclude, $preserve_options, $preserve_option_prefixes, $rename_columns, $prefix_anchored, $prefix_pair );
            $did_work        = true;

            if ( $consumed < $this->page_size() ) {
                ++$cursor['table_index'];
                $cursor['offset'] = 0;
            } else {
                $cursor['offset'] += $consumed;
            }
        }

        return array(
            'done'           => true,
            'cursor'         => $cursor,
            'renamed_over'   => $this->renamed_over,
            'failed_updates' => $this->failed_updates,
            'last_error'     => $this->last_error,
        );
    }

    /**
     * Rewrite one page of one table.
     *
     * @param string $table            Table name.
     * @param int    $offset           Row offset, adjusted for deleted rename targets.
     * @param array  $pairs            Replacement pairs.
     * @param array  $exclude          Columns excluded from pair replacement.
     * @param array  $preserve_options Option names whose rows must remain unchanged.
     * @param array  $preserve_option_prefixes Option-name prefixes whose rows must remain unchanged.
     * @param array  $rename_columns Exact replacements by column for prefix-derived lookup keys.
     * @param array  $prefix_anchored Columns whose leading prefix can change.
     * @param array  $prefix_pair Source and target prefixes.
     *
     * @throws MainWP_Exception When the page boundary cannot be read after a deletion.
     *
     * @return int Rows fetched (less than a full page means the table
     *             is done).
     */
    protected function rewrite_page( $table, &$offset, $pairs, $exclude, $preserve_options = array(), $preserve_option_prefixes = array(), $rename_columns = array(), $prefix_anchored = array(), $prefix_pair = array() ) {
        global $wpdb;

        $pk_columns = $this->primary_key_columns( $table );
        $order_sql  = empty( $pk_columns ) ? '' : ' ORDER BY ' . implode( ', ', array_map( array( $this, 'quote_ident' ), $pk_columns ) );

        //phpcs:disable WordPress.DB -- direct paging reads over restored tables; names come from the restore config, not user input.
        $rows = $wpdb->get_results(
            'SELECT * FROM ' . $this->quote_ident( $table ) . $order_sql . ' LIMIT ' . (int) $offset . ', ' . (int) $this->page_size(),
            ARRAY_A
        );
        //phpcs:enable WordPress.DB

        if ( empty( $rows ) ) {
            return 0;
        }

        $renamed_before = $this->renamed_over[ $table ] ?? 0;
        foreach ( $rows as $row ) {
            if ( isset( $row['option_name'] ) && in_array( $row['option_name'], $preserve_options, true ) ) {
                continue;
            }
            if ( isset( $row['option_name'] ) ) {
                foreach ( $preserve_option_prefixes as $prefix ) {
                    if ( 0 === strpos( $row['option_name'], $prefix ) ) {
                        continue 2;
                    }
                }
            }
            $this->rewrite_row( $table, $row, $pairs, $exclude, $pk_columns, $rename_columns, $prefix_anchored, $prefix_pair );
        }

        if ( ! empty( $pk_columns ) && ( $this->renamed_over[ $table ] ?? 0 ) > $renamed_before ) {
            // A deleted target can precede this page or sit in it; keep the next offset at its original end key.
            $last         = end( $rows );
            $columns      = implode( ', ', array_map( array( $this, 'quote_ident' ), $pk_columns ) );
            $placeholders = implode( ', ', array_fill( 0, count( $pk_columns ), '%s' ) );
            $values       = array();
            foreach ( $pk_columns as $column ) {
                $values[] = $last[ $column ];
            }
            $sql = 'SELECT COUNT(*) FROM ' . $this->quote_ident( $table ) . ' WHERE (' . $columns . ') <= (';
            $end = $wpdb->get_var( $wpdb->prepare( str_replace( '%', '%%', $sql ) . $placeholders . ')', $values ) ); // phpcs:ignore WordPress.DB -- count the surviving rows through the page boundary.
            if ( null === $end ) {
                throw new MainWP_Exception( 'Could not resume rewriting after a role-key collision.' );
            }
            $offset = (int) $end - count( $rows );
        }

        return count( $rows );
    }

    /**
     * Rewrite one row in place.
     *
     * @param string $table      Table name.
     * @param array  $row        Row values.
     * @param array  $pairs      Replacement pairs.
     * @param array  $exclude    Columns excluded from pair replacement.
     * @param array  $pk_columns Primary key column names.
     * @param array  $rename_columns Exact replacements by column for prefix-derived lookup keys.
     * @param array  $prefix_anchored Columns whose leading prefix can change.
     * @param array  $prefix_pair Source and target prefixes.
     */
    protected function rewrite_row( $table, $row, $pairs, $exclude, $pk_columns, $rename_columns = array(), $prefix_anchored = array(), $prefix_pair = array() ) {
        global $wpdb;

        $updates = array();
        foreach ( $row as $column => $value ) {
            if ( ! is_string( $value ) || '' === $value ) {
                continue;
            }
            if ( in_array( $column, $pk_columns, true ) ) {
                continue;
            }
            // Lookup keys must only follow the prefix when WordPress derives their names from it.
            if ( isset( $rename_columns[ $column ][ $value ] ) ) {
                $new = $rename_columns[ $column ][ $value ];
                if ( $new !== $value && 'option_name' === $column && 'options' === substr( $table, -7 ) ) {
                    // The source roles take precedence over a stale target left by an earlier restore.
                    $deleted = $wpdb->delete( $table, array( 'option_name' => $new ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- remove only the exact rename target.
                    if ( false === $deleted ) {
                        ++$this->failed_updates;
                        $this->last_error = 'A stale role-key rename target could not be removed.';
                        return;
                    }
                    if ( $deleted > 0 ) {
                        $this->renamed_over[ $table ] = ( $this->renamed_over[ $table ] ?? 0 ) + $deleted;
                    }
                }
            } elseif ( in_array( $column, $prefix_anchored, true ) ) {
                $from = $prefix_pair['from'] ?? '';
                $new  = '' !== $from && 0 === strpos( $value, $from ) ? $prefix_pair['to'] . substr( $value, strlen( $from ) ) : $value;
            } elseif ( in_array( $column, $exclude, true ) || ( 'option_name' === $column && 'options' === substr( $table, -7 ) ) ) {
                continue;
            } else {
                $new = $this->replace_value( $value, $pairs );
            }
            if ( $new !== $value ) {
                $updates[ $column ] = $new;
            }
        }

        if ( empty( $updates ) ) {
            return;
        }

        if ( ! empty( $pk_columns ) ) {
            $where = array();
            foreach ( $pk_columns as $pk ) {
                $where[ $pk ] = $row[ $pk ];
            }
        } else {
            // No primary key: match on every original non-null value.
            $where = array_filter(
                $row,
                function ( $value ) {
                    return null !== $value;
                }
            );
        }

        if ( false === $this->persist_update( $table, $updates, $where ) ) {
            ++$this->failed_updates;
            $error            = (string) $wpdb->last_error;
            $this->last_error = '' !== $error ? $error : 'A restored row could not be URL-rewritten.';
        }
    }

    /**
     * Write rewritten columns back to a restored row. Isolated so the
     * failure path is testable without forcing a real SQL error.
     *
     * @param string $table   Table name.
     * @param array  $updates Columns to set.
     * @param array  $where   Match clause.
     *
     * @return int|false Rows affected, or false on error.
     */
    protected function persist_update( $table, $updates, $where ) {
        global $wpdb;
        return $wpdb->update( $table, $updates, $where ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- writing rewritten values back to a restored table outside $wpdb's own prefix map.
    }

    /**
     * Apply every pair to one value, serialization-aware.
     *
     * @param string $value Original value.
     * @param array  $pairs Replacement pairs.
     *
     * @return string Replaced value.
     */
    public function replace_value( $value, $pairs ) {
        $result = $value;
        foreach ( $pairs as $pair ) {
            $result = $this->recursive_replace( $pair['from'], $pair['to'], $result );
        }
        return is_string( $result ) ? $result : $value;
    }

    /**
     * Replace inside a possibly-serialized value.
     *
     * Mirrors the proven clone-install replacer, with restricted
     * unserialization: objects become incomplete-class stubs that are
     * skipped for replacement and re-serialize byte-identical.
     *
     * @param string $from       Needle.
     * @param string $to         Replacement.
     * @param mixed  $data       Value (string, array, object on recursion).
     * @param bool   $serialised Whether to re-serialize the result.
     * @param int    $depth      Current nesting depth.
     *
     * @return mixed Replaced value.
     */
    public function recursive_replace( $from, $to, $data, $serialised = false, $depth = 0 ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity -- type dispatch mirrors the legacy replacer.
        try {
            if ( $depth > 32 ) {
                return $serialised ? serialize( $data ) : $data; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- preserve values beyond the safe traversal depth.
            }
            if ( is_string( $data ) && is_serialized( $data ) && ! is_serialized_string( $data ) ) {
                $unserialized = unserialize( $data, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- restricted unserialize, no classes instantiated.
                if ( false !== $unserialized || 'b:0;' === $data ) {
                    return $this->recursive_replace( $from, $to, $unserialized, true, $depth + 1 );
                }
                return $data;
            }

            if ( is_array( $data ) ) {
                $output = array();
                foreach ( $data as $key => $value ) {
                    $output[ $key ] = $this->recursive_replace( $from, $to, $value, false, $depth + 1 );
                }
                $data = $output;
            } elseif ( is_object( $data ) ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement -- incomplete-class stubs re-serialize byte-identical; properties deliberately untouched.
                return $serialised ? serialize( $data ) : $data; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- re-serializing an untouched stub.
            } elseif ( is_serialized_string( $data ) ) {
                $inner = unserialize( $data, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- restricted unserialize of a plain string.
                if ( false !== $inner ) {
                    $data = serialize( str_replace( $from, $to, $inner ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- re-serializing a plain string.
                }
            } elseif ( is_string( $data ) ) {
                $data = str_replace( $from, $to, $data );
            }

            if ( $serialised ) {
                return serialize( $data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- re-serializing the replaced structure.
            }
        } catch ( \Throwable $error ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement -- a value that cannot round-trip is returned unmodified.
            return $data;
        }

        return $data;
    }

    /**
     * Quote a table name for use in an identifier position.
     *
     * The names reach here from the restore config, which carries what the
     * replayed dump named; doubling keeps a backtick inside one from closing
     * the identifier.
     *
     * @param string $name Bare table name.
     *
     * @return string Backticked name with inner backticks doubled.
     */
    protected function quote_ident( $name ) {
        return '`' . str_replace( '`', '``', (string) $name ) . '`';
    }

    /**
     * Primary key column names for a table.
     *
     * @param string $table Table name.
     *
     * @return string[] Column names (empty when no primary key).
     */
    protected function primary_key_columns( $table ) {
        global $wpdb;

        //phpcs:disable WordPress.DB -- schema introspection on a restored table; the name comes from the restore config.
        $rows = $wpdb->get_results( 'SHOW KEYS FROM ' . $this->quote_ident( $table ) . " WHERE Key_name = 'PRIMARY'", ARRAY_A );
        //phpcs:enable WordPress.DB

        $columns = array();
        foreach ( (array) $rows as $row ) {
            if ( isset( $row['Column_name'] ) ) {
                $columns[] = $row['Column_name'];
            }
        }
        return $columns;
    }

    /**
     * Rows per page (test seam).
     *
     * @return int Page size.
     */
    protected function page_size() {
        return self::PAGE_SIZE;
    }
}
