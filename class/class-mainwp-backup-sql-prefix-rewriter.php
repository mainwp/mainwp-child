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
 * format does not produce. SET statements never replay: the restore engine
 * sets the session itself, the plugin's dumps carry none, and a dump-supplied
 * sql_mode or client charset would change how the server reads every later
 * string and comment.
 *
 * MySQL runs the body of a `/*!` comment and MariaDB also runs `/*M!`, so a
 * comment the rewriter skipped as inert could carry a statement or schema
 * qualifier it never saw. Comment spans follow one model shared with the
 * Dashboard's test-restore guard: every block comment ends at the first raw
 * close, and a shape that some server could read differently is refused,
 * which fails the restore instead of dropping a statement quietly.
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
     * Why the last statement was refused, or null when it was not.
     *
     * @var string|null
     */
    private $refusal = null;

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
     *                     statement must be dropped or was refused
     *                     (see refusal()).
     */
    public function rewrite( $statement ) {
        $this->refusal = null;

        $exec = $this->scan_comments( $statement );
        if ( null === $exec ) {
            return null;
        }

        $offset = $this->skip_trivia( $statement, 0 );
        $head   = substr( $statement, $offset );

        // Real dumps only carry executable comments as CREATE TABLE options; anywhere else one
        // could add a table or clause the checks below never see.
        if ( ! empty( $exec ) && ! preg_match( '/^CREATE\s+TABLE\b/i', $head ) ) {
            return null;
        }

        // Statements with no table position that are safe to replay.
        if ( preg_match( '/^(BEGIN|COMMIT|START\s+TRANSACTION|UNLOCK\s+TABLES)\b/i', $head ) ) {
            return $statement;
        }

        if ( preg_match( '/^RENAME\s+TABLE\b/i', $head, $m ) ) {
            return $this->rewrite_rename( $statement, $offset + strlen( $m[0] ) );
        }

        $keyword = $this->match_table_keyword( $head );
        if ( null === $keyword ) {
            return null;
        }

        return $this->rewrite_single_table( $statement, $offset + $keyword, $exec );
    }

    /**
     * Why the last rewrite() call refused its statement.
     *
     * A refusal means the dump holds a comment shape no real dump has and servers can read in
     * more than one way, so the restore must stop rather than skip the statement.
     *
     * @return string|null Shape description, or null when the last statement was not refused.
     */
    public function refusal() {
        return $this->refusal;
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
     * @param array  $exec      Executable comment spans from scan_comments().
     *
     * @return string|null Rewritten statement or null to drop it.
     */
    private function rewrite_single_table( $statement, $offset, $exec ) {
        $token = $this->parse_table_token( $statement, $offset );
        if ( null === $token ) {
            return null;
        }

        // An executable comment before the column list could rename or qualify the table on a
        // server that runs it and not on one that skips it, so options after it are the only place left.
        if ( ! empty( $exec ) ) {
            $next = $this->skip_trivia( $statement, $token['end'] );
            if ( $next >= strlen( $statement ) || '(' !== $statement[ $next ] || $exec[0]['start'] < $next ) {
                return null;
            }
        }

        $rewritten = $this->rewrite_table_name( $token['name'] );
        if ( null === $rewritten ) {
            return null;
        }

        // A LOCK TABLES or DROP TABLE naming further tables would leave
        // them unrewritten; drop rather than touch a live table. Only
        // these two statement kinds take a comma-separated table list
        // here (RENAME is handled separately; the rest carry commas only
        // inside column lists or VALUES, which must not trip this guard).
        $rest = substr( $statement, $token['end'] );
        // These ALTER clauses can reach a second table whose name is never rewritten.
        if ( preg_match( '/^ALTER\b/i', substr( $statement, $this->skip_trivia( $statement, 0 ) ) ) && preg_match( '/\b(RENAME|EXCHANGE)\b/i', $rest ) ) {
            return null;
        }

        // Any comma there starts another table, even with a comment between it and the name.
        if ( preg_match( '/^(LOCK|DROP)/i', ltrim( substr( $statement, $this->skip_trivia( $statement, 0 ) ) ) ) && false !== strpos( $rest, ',' ) ) {
            return null;
        }

        return substr( $statement, 0, $token['start'] ) . $this->quote_ident( $rewritten ) . substr( $statement, $token['end'] );
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

            $out .= substr( $statement, $pos, $from['start'] - $pos ) . $this->quote_ident( $from_name );
            $out .= substr( $statement, $from['end'], $to['start'] - $from['end'] ) . $this->quote_ident( $to_name );
            $pos  = $to['end'];

            if ( preg_match( '/\G\s*,/A', $statement, $m, 0, $pos ) ) {
                $offset = $pos + strlen( $m[0] );
                continue;
            }
            break;
        }

        // A pair that follows a comment instead of a bare comma would replay unrewritten.
        if ( $this->skip_trivia( $statement, $pos ) < strlen( $statement ) ) {
            return null;
        }

        return $out . substr( $statement, $pos );
    }

    /**
     * Quote a table name for re-emission in an identifier position.
     *
     * The token parser decodes doubled backticks, so re-emitting the name raw
     * would let an archive-supplied name close the identifier and append its
     * own statement to the replayed dump.
     *
     * @param string $name Bare table name.
     *
     * @return string Backticked name with inner backticks doubled.
     */
    private function quote_ident( $name ) {
        return '`' . str_replace( '`', '``', (string) $name ) . '`';
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

        // Qualified name: reject. MySQL accepts whitespace and comments around the dot.
        $next = $this->skip_trivia( $statement, $end );
        if ( $next < $len && '.' === $statement[ $next ] ) {
            return null;
        }

        return array(
            'name'  => $name,
            'start' => $offset,
            'end'   => $end,
        );
    }

    /**
     * Skip whitespace, line comments and plain block comments.
     *
     * Executable comments and optimizer hints are not skipped: rewrite() handles the first,
     * and scan_comments() has already refused the second.
     *
     * @param string $statement Statement text.
     * @param int    $pos       Offset to start at.
     *
     * @return int Offset of the first byte that is not trivia.
     */
    private function skip_trivia( $statement, $pos ) {
        $len = strlen( $statement );

        while ( $pos < $len ) {
            if ( ctype_space( $statement[ $pos ] ) ) {
                ++$pos;
                continue;
            }
            if ( '#' === $statement[ $pos ] || $this->is_dash_comment( $statement, $pos, $len ) ) {
                $nl  = strpos( $statement, "\n", $pos );
                $pos = ( false === $nl ) ? $len : $nl + 1;
                continue;
            }
            if ( '/' === $statement[ $pos ] && $pos + 1 < $len && '*' === $statement[ $pos + 1 ] && 'plain' === $this->comment_kind( $statement, $pos + 2 ) ) {
                $close = strpos( $statement, '*/', $pos + 2 );
                $pos   = ( false === $close ) ? $len : $close + 2;
                continue;
            }
            break;
        }

        return $pos;
    }

    /**
     * Whether a dash-dash at this offset opens a line comment.
     *
     * MySQL needs whitespace or a control byte after the second dash; scan_comments() refuses
     * the control bytes the dump splitter does not treat the same way.
     *
     * @param string $sql SQL text.
     * @param int    $pos Offset of the first dash.
     * @param int    $len Length of the text to consider.
     *
     * @return bool True when a line comment starts here.
     */
    private function is_dash_comment( $sql, $pos, $len ) {
        if ( $pos + 1 >= $len || '-' !== $sql[ $pos ] || '-' !== $sql[ $pos + 1 ] ) {
            return false;
        }
        return $pos + 2 >= $len || false !== strpos( " \t\r\n", $sql[ $pos + 2 ] );
    }

    /**
     * Classify the block comment whose body starts at the given offset.
     *
     * @param string $sql    SQL text.
     * @param int    $from   Offset just past the opening slash-star.
     * @param int    $marker Set to the marker length for an executable comment (by reference).
     *
     * @return string 'exec', 'hint', or 'plain'.
     */
    private function comment_kind( $sql, $from, &$marker = 0 ) {
        $marker = 0;
        $c      = isset( $sql[ $from ] ) ? $sql[ $from ] : '';
        if ( '!' === $c ) {
            $marker = 1;
            return 'exec';
        }
        // MariaDB only honours an uppercase M; a lowercase one is treated the same to stay on the safe side.
        if ( ( 'M' === $c || 'm' === $c ) && isset( $sql[ $from + 1 ] ) && '!' === $sql[ $from + 1 ] ) {
            $marker = 2;
            return 'exec';
        }
        return '+' === $c ? 'hint' : 'plain';
    }

    /**
     * Find where a quoted string or identifier ends.
     *
     * @param string $sql   SQL text.
     * @param int    $pos   Offset of the opening quote.
     * @param int    $limit Offset the quote must close before.
     *
     * @return int|null Offset just past the closing quote, or null when it does not close in time.
     */
    private function quote_end( $sql, $pos, $limit ) {
        $quote = $sql[ $pos ];
        // The restore session keeps backslash escapes on, so they apply to both string quotes.
        $stops = '`' === $quote ? '`' : $quote . '\\';
        ++$pos;
        while ( $pos < $limit ) {
            $pos += strcspn( $sql, $stops, $pos, $limit - $pos );
            if ( $pos >= $limit ) {
                return null;
            }
            if ( '\\' === $sql[ $pos ] ) {
                $pos += 2;
                continue;
            }
            if ( $pos + 1 < $limit && $quote === $sql[ $pos + 1 ] ) {
                $pos += 2;
                continue;
            }
            return $pos + 1;
        }
        return null;
    }

    /**
     * Find every executable comment and refuse the shapes servers read in more than one way.
     *
     * Sets the refusal reason and returns null on a refused shape. The rules: a block comment
     * ends at the first raw close; version digits after the marker are absent or exactly 5 or
     * 6 and followed by whitespace or the close; an executable body closes every quote, holds
     * no comment opener even inside quotes, and no line comment outside them; optimizer hints,
     * unterminated comments and unterminated quotes are refused.
     *
     * @param string $sql Statement text.
     *
     * @return array|null List of { start, end, body_start, body_end } spans, or null when refused.
     */
    private function scan_comments( $sql ) {
        $len  = strlen( $sql );
        $pos  = 0;
        $exec = array();

        while ( $pos < $len ) {
            $pos += strcspn( $sql, "'\"`#-/", $pos );
            if ( $pos >= $len ) {
                break;
            }
            $c = $sql[ $pos ];

            if ( "'" === $c || '"' === $c || '`' === $c ) {
                $end = $this->quote_end( $sql, $pos, $len );
                if ( null === $end ) {
                    return $this->refuse( 'an unterminated quoted string or identifier' );
                }
                $pos = $end;
                continue;
            }

            if ( '#' === $c || $this->is_dash_comment( $sql, $pos, $len ) ) {
                $nl  = strpos( $sql, "\n", $pos );
                $pos = ( false === $nl ) ? $len : $nl + 1;
                continue;
            }

            if ( '-' === $c ) {
                if ( $pos + 2 < $len && '-' === $sql[ $pos + 1 ] && ( ord( $sql[ $pos + 2 ] ) < 0x20 || 0x7f === ord( $sql[ $pos + 2 ] ) ) ) {
                    return $this->refuse( 'a line comment opened by a control character' );
                }
                ++$pos;
                continue;
            }

            if ( '/' !== $c || $pos + 1 >= $len || '*' !== $sql[ $pos + 1 ] ) {
                ++$pos;
                continue;
            }

            $close = strpos( $sql, '*/', $pos + 2 );
            if ( false === $close ) {
                return $this->refuse( 'an unterminated comment' );
            }
            $kind = $this->comment_kind( $sql, $pos + 2, $marker );
            if ( 'hint' === $kind ) {
                return $this->refuse( 'an optimizer hint comment' );
            }
            if ( 'plain' === $kind ) {
                $pos = $close + 2;
                continue;
            }

            $body_start = $pos + 2 + $marker;
            $digits     = strspn( $sql, '0123456789', $body_start, $close - $body_start );
            if ( $digits > 0 ) {
                if ( 5 !== $digits && 6 !== $digits ) {
                    return $this->refuse( 'an executable comment whose version is not 5 or 6 digits' );
                }
                $body_start += $digits;
                if ( $body_start < $close && ! ctype_space( $sql[ $body_start ] ) ) {
                    return $this->refuse( 'an executable comment whose version runs into its body' );
                }
            }

            $error = $this->exec_body_error( $sql, $body_start, $close );
            if ( null !== $error ) {
                return $this->refuse( $error );
            }

            $exec[] = array(
                'start'      => $pos,
                'end'        => $close + 2,
                'body_start' => $body_start,
                'body_end'   => $close,
            );
            $pos    = $close + 2;
        }

        return $exec;
    }

    /**
     * Check that an executable comment body means the same thing whether a server runs or skips it.
     *
     * @param string $sql   SQL text.
     * @param int    $start Body start offset.
     * @param int    $end   Offset of the closing star-slash.
     *
     * @return string|null Refusal reason, or null when the body is clean.
     */
    private function exec_body_error( $sql, $start, $end ) {
        // A skipped body nests on any raw opener, quoted or not, while a run body does not.
        $opener = strpos( $sql, '/*', $start );
        if ( false !== $opener && $opener < $end ) {
            return 'a comment nested inside an executable comment';
        }

        $pos = $start;
        while ( $pos < $end ) {
            $pos += strcspn( $sql, "'\"`#-", $pos, $end - $pos );
            if ( $pos >= $end ) {
                break;
            }
            $c = $sql[ $pos ];
            if ( "'" === $c || '"' === $c || '`' === $c ) {
                $close = $this->quote_end( $sql, $pos, $end );
                if ( null === $close ) {
                    return 'a quoted string that runs past the end of an executable comment';
                }
                $pos = $close;
                continue;
            }
            if ( '#' === $c || ( $pos + 1 < $end && '-' === $sql[ $pos + 1 ] && ( $pos + 2 >= $end || ord( $sql[ $pos + 2 ] ) <= 0x20 || 0x7f === ord( $sql[ $pos + 2 ] ) ) ) ) {
                return 'a line comment inside an executable comment';
            }
            ++$pos;
        }

        return null;
    }

    /**
     * Record a refusal.
     *
     * @param string $reason Shape description.
     *
     * @return null Always null, so callers can return it directly.
     */
    private function refuse( $reason ) {
        $this->refusal = $reason;
        return null;
    }
}
