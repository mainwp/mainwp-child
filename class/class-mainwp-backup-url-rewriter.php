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
     *                            exclude: array<string,string[]> }.
     * @param float|int $deadline Absolute epoch the slice must yield by.
     *
     * @return array { done: bool, cursor: array }
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
                    'failed_updates' => $this->failed_updates,
                    'last_error'     => $this->last_error,
                );
            }

            $table    = $tables[ $cursor['table_index'] ];
            $exclude  = isset( $config['exclude'][ $table ] ) ? $config['exclude'][ $table ] : array();
            $consumed = $this->rewrite_page( $table, $cursor['offset'], $config['pairs'], $exclude );
            $did_work = true;

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
            'failed_updates' => $this->failed_updates,
            'last_error'     => $this->last_error,
        );
    }

    /**
     * Rewrite one page of one table.
     *
     * @param string $table   Table name.
     * @param int    $offset  Row offset.
     * @param array  $pairs   Replacement pairs.
     * @param array  $exclude Column names to skip.
     *
     * @return int Rows fetched (less than a full page means the table
     *             is done).
     */
    protected function rewrite_page( $table, $offset, $pairs, $exclude ) {
        global $wpdb;

        $pk_columns = $this->primary_key_columns( $table );
        $order_sql  = empty( $pk_columns ) ? '' : ' ORDER BY `' . implode( '`, `', $pk_columns ) . '`';

        //phpcs:disable WordPress.DB -- direct paging reads over restored tables; names come from the restore config, not user input.
        $rows = $wpdb->get_results(
            'SELECT * FROM `' . $table . '`' . $order_sql . ' LIMIT ' . (int) $offset . ', ' . (int) $this->page_size(),
            ARRAY_A
        );
        //phpcs:enable WordPress.DB

        if ( empty( $rows ) ) {
            return 0;
        }

        foreach ( $rows as $row ) {
            $this->rewrite_row( $table, $row, $pairs, $exclude, $pk_columns );
        }

        return count( $rows );
    }

    /**
     * Rewrite one row in place.
     *
     * @param string $table      Table name.
     * @param array  $row        Row values.
     * @param array  $pairs      Replacement pairs.
     * @param array  $exclude    Column names to skip.
     * @param array  $pk_columns Primary key column names.
     */
    protected function rewrite_row( $table, $row, $pairs, $exclude, $pk_columns ) {
        global $wpdb;

        $updates = array();
        foreach ( $row as $column => $value ) {
            if ( ! is_string( $value ) || '' === $value ) {
                continue;
            }
            if ( in_array( $column, $exclude, true ) || in_array( $column, $pk_columns, true ) ) {
                continue;
            }
            $new = $this->replace_value( $value, $pairs );
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
     *
     * @return mixed Replaced value.
     */
    public function recursive_replace( $from, $to, $data, $serialised = false ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity -- type dispatch mirrors the legacy replacer.
        try {
            if ( is_string( $data ) && is_serialized( $data ) && ! is_serialized_string( $data ) ) {
                $unserialized = unserialize( $data, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- restricted unserialize, no classes instantiated.
                if ( false !== $unserialized || 'b:0;' === $data ) {
                    return $this->recursive_replace( $from, $to, $unserialized, true );
                }
                return $data;
            }

            if ( is_array( $data ) ) {
                $output = array();
                foreach ( $data as $key => $value ) {
                    $output[ $key ] = $this->recursive_replace( $from, $to, $value );
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
     * Primary key column names for a table.
     *
     * @param string $table Table name.
     *
     * @return string[] Column names (empty when no primary key).
     */
    protected function primary_key_columns( $table ) {
        global $wpdb;

        //phpcs:disable WordPress.DB -- schema introspection on a restored table; the name comes from the restore config.
        $rows = $wpdb->get_results( 'SHOW KEYS FROM `' . $table . "` WHERE Key_name = 'PRIMARY'", ARRAY_A );
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
