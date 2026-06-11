<?php
/**
 * MainWP Backup SQL Prefix Rewriter - table-position-only identifier rewrite.
 *
 * Rewrites the single table-name token immediately following a known
 * DDL/DML keyword (CREATE TABLE, DROP TABLE, INSERT INTO, REPLACE INTO,
 * ALTER TABLE, LOCK TABLES, RENAME TABLE, TRUNCATE) from the dump's
 * source prefix to a per-restore replacement prefix. Values are never
 * rewritten: a serialized option_value carrying the prefix as
 * string-literal bytes must survive untouched, so a blanket str_replace
 * over the statement is exactly the bug this class exists to avoid.
 *
 * The rewriter doubles as the data-loss guard. Statements naming tables
 * outside the source-prefixed set are dropped, as is anything that could
 * reach beyond the restore's own tables: USE, CREATE/DROP DATABASE,
 * GRANT, triggers, routines, views, and any statement kind the dump
 * format does not produce. Session SET statements (bare or in a
 * conditional comment) pass through unchanged.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Backup_Sql_Prefix_Rewriter
 *
 * @package MainWP\Child
 */
class MainWP_Backup_Sql_Prefix_Rewriter { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Prefix the dump's tables carry.
     *
     * @var string
     */
    private $source_prefix;

    /**
     * Prefix rewritten table names receive.
     *
     * @var string
     */
    private $replacement_prefix;

    /**
     * Constructor.
     *
     * @param string $source_prefix      Prefix recorded in the dump banner.
     * @param string $replacement_prefix Per-restore replacement prefix.
     */
    public function __construct( $source_prefix, $replacement_prefix ) {
        $this->source_prefix      = $source_prefix;
        $this->replacement_prefix = $replacement_prefix;
    }

    /**
     * Rewrite one complete statement.
     *
     * @param string $statement Statement without trailing semicolon.
     *
     * @return string|null Rewritten statement, or null when the
     *                     statement must be dropped.
     */
    public function rewrite( $statement ) {
        $offset = $this->skip_leading_trivia( $statement );
        $head   = substr( $statement, $offset );

        // Conditional-comment statements: allow session SET only.
        if ( 0 === strpos( $head, '/*!' ) ) {
            if ( preg_match( '/^\/\*!\d*\s+SET\s/i', $head ) ) {
                return $statement;
            }
            return null;
        }

        // Statements with no table position that are safe to replay.
        if ( preg_match( '/^(SET|BEGIN|COMMIT|START\s+TRANSACTION|UNLOCK\s+TABLES)\b/i', $head ) ) {
            return $statement;
        }

        if ( preg_match( '/^RENAME\s+TABLE\b/i', $head, $m ) ) {
            return $this->rewrite_rename( $statement, $offset + strlen( $m[0] ) );
        }

        $keyword = $this->match_table_keyword( $head );
        if ( null === $keyword ) {
            return null;
        }

        return $this->rewrite_single_table( $statement, $offset + $keyword );
    }

    /**
     * Match a single-table keyword at the head of the statement.
     *
     * @param string $head Statement text from the first meaningful byte.
     *
     * @return int|null Length of the matched keyword text, or null when
     *                  the statement kind is not allowed.
     */
    private function match_table_keyword( $head ) {
        $patterns = array(
            '/^INSERT\s+INTO\b/i',
            '/^REPLACE\s+INTO\b/i',
            '/^CREATE\s+TABLE(\s+IF\s+NOT\s+EXISTS)?\b/i',
            '/^DROP\s+TABLE(\s+IF\s+EXISTS)?\b/i',
            '/^ALTER\s+TABLE\b/i',
            '/^LOCK\s+TABLES\b/i',
            '/^TRUNCATE(\s+TABLE)?\b/i',
        );
        foreach ( $patterns as $pattern ) {
            if ( preg_match( $pattern, $head, $m ) ) {
                return strlen( $m[0] );
            }
        }
        return null;
    }

    /**
     * Rewrite the table token following a single-table keyword.
     *
     * @param string $statement Full statement.
     * @param int    $offset    Byte offset just past the keyword.
     *
     * @return string|null Rewritten statement or null to drop it.
     */
    private function rewrite_single_table( $statement, $offset ) {
        $token = $this->parse_table_token( $statement, $offset );
        if ( null === $token ) {
            return null;
        }

        $rewritten = $this->rewrite_table_name( $token['name'] );
        if ( null === $rewritten ) {
            return null;
        }

        // A LOCK TABLES naming further tables would leave them
        // unrewritten; drop rather than touch a live table.
        $rest = substr( $statement, $token['end'] );
        if ( preg_match( '/^LOCK/i', ltrim( substr( $statement, $this->skip_leading_trivia( $statement ) ) ) ) && preg_match( '/,\s*[`A-Za-z0-9_$]/', $rest ) ) {
            return null;
        }

        return substr( $statement, 0, $token['start'] ) . '`' . $rewritten . '`' . substr( $statement, $token['end'] );
    }

    /**
     * Rewrite every table token in a RENAME TABLE statement.
     *
     * @param string $statement Full statement.
     * @param int    $offset    Byte offset just past RENAME TABLE.
     *
     * @return string|null Rewritten statement or null to drop it.
     */
    private function rewrite_rename( $statement, $offset ) {
        $out = '';
        $pos = 0;

        while ( true ) {
            $from = $this->parse_table_token( $statement, $offset );
            if ( null === $from ) {
                return null;
            }
            if ( ! preg_match( '/\G\s+TO\s+/iA', $statement, $m, 0, $from['end'] ) ) {
                return null;
            }
            $to = $this->parse_table_token( $statement, $from['end'] + strlen( $m[0] ) );
            if ( null === $to ) {
                return null;
            }

            $from_name = $this->rewrite_table_name( $from['name'] );
            $to_name   = $this->rewrite_table_name( $to['name'] );
            if ( null === $from_name || null === $to_name ) {
                return null;
            }

            $out .= substr( $statement, $pos, $from['start'] - $pos ) . '`' . $from_name . '`';
            $out .= substr( $statement, $from['end'], $to['start'] - $from['end'] ) . '`' . $to_name . '`';
            $pos  = $to['end'];

            if ( preg_match( '/\G\s*,/A', $statement, $m, 0, $pos ) ) {
                $offset = $pos + strlen( $m[0] );
                continue;
            }
            break;
        }

        return $out . substr( $statement, $pos );
    }

    /**
     * Apply the prefix swap to one table name.
     *
     * @param string $name Table name without backticks.
     *
     * @return string|null Rewritten name, or null when the name is
     *                     outside the source-prefixed set.
     */
    private function rewrite_table_name( $name ) {
        if ( 0 !== strpos( $name, $this->source_prefix ) ) {
            return null;
        }
        return $this->replacement_prefix . substr( $name, strlen( $this->source_prefix ) );
    }

    /**
     * Parse the table-name token at the given offset.
     *
     * Accepts a backtick-quoted name (doubled backticks unescaped) or a
     * bare identifier. A qualified name (db.table) is rejected: a dump
     * must never reach across databases.
     *
     * @param string $statement Full statement.
     * @param int    $offset    Offset to start at (whitespace allowed).
     *
     * @return array|null { name: string, start: int, end: int } or null.
     */
    private function parse_table_token( $statement, $offset ) {
        $len = strlen( $statement );
        while ( $offset < $len && ctype_space( $statement[ $offset ] ) ) {
            ++$offset;
        }
        if ( $offset >= $len ) {
            return null;
        }

        if ( '`' === $statement[ $offset ] ) {
            if ( ! preg_match( '/\G`((?:[^`]|``)+)`/A', $statement, $m, 0, $offset ) ) {
                return null;
            }
            $name = str_replace( '``', '`', $m[1] );
            $end  = $offset + strlen( $m[0] );
        } elseif ( preg_match( '/\G[A-Za-z0-9_$]+/A', $statement, $m, 0, $offset ) ) {
            $name = $m[0];
            $end  = $offset + strlen( $m[0] );
        } else {
            return null;
        }

        // Qualified name: reject.
        if ( $end < $len && '.' === $statement[ $end ] ) {
            return null;
        }

        return array(
            'name'  => $name,
            'start' => $offset,
            'end'   => $end,
        );
    }

    /**
     * Skip leading whitespace and comments for keyword detection.
     *
     * Conditional comments are NOT skipped - they are statements in
     * their own right and handled by rewrite().
     *
     * @param string $statement Statement text.
     *
     * @return int Offset of the first meaningful byte.
     */
    private function skip_leading_trivia( $statement ) {
        $len = strlen( $statement );
        $pos = 0;

        while ( $pos < $len ) {
            if ( ctype_space( $statement[ $pos ] ) ) {
                ++$pos;
                continue;
            }
            if ( '#' === $statement[ $pos ] ) {
                $nl  = strpos( $statement, "\n", $pos );
                $pos = ( false === $nl ) ? $len : $nl + 1;
                continue;
            }
            if ( '-' === $statement[ $pos ] && $pos + 1 < $len && '-' === $statement[ $pos + 1 ] ) {
                $nl  = strpos( $statement, "\n", $pos );
                $pos = ( false === $nl ) ? $len : $nl + 1;
                continue;
            }
            if ( '/' === $statement[ $pos ] && $pos + 1 < $len && '*' === $statement[ $pos + 1 ] && '!' !== ( isset( $statement[ $pos + 2 ] ) ? $statement[ $pos + 2 ] : '' ) ) {
                $close = strpos( $statement, '*/', $pos + 2 );
                $pos   = ( false === $close ) ? $len : $close + 2;
                continue;
            }
            break;
        }

        return $pos;
    }
}
