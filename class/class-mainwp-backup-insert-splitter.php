<?php
/**
 * MainWP Backup Insert Splitter - max_allowed_packet-aware INSERT splitting.
 *
 * The dumper batches multi-row INSERTs up to ~1 MiB, but the restore
 * target (often a shared host) may cap max_allowed_packet far lower and
 * reject an oversized statement with error 1153. This splitter breaks a
 * too-large multi-row INSERT at top-level value-tuple boundaries into
 * several smaller INSERTs, each carrying the original header. Tuple
 * boundaries inside string literals or nested parentheses are never
 * split points. A single tuple that alone exceeds the limit passes
 * through unchanged - corrupting it would be worse than letting the
 * server report the oversize.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Backup_Insert_Splitter
 *
 * @package MainWP\Child
 */
class MainWP_Backup_Insert_Splitter { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Split an INSERT statement to fit under a byte limit.
     *
     * @param string $sql       Complete statement (no trailing semicolon).
     * @param int    $max_bytes Statement byte budget.
     *
     * @return string[] One or more statements, in replay order.
     */
    public function split( $sql, $max_bytes ) {
        if ( strlen( $sql ) <= $max_bytes ) {
            return array( $sql );
        }

        $parsed = $this->parse( $sql );
        if ( null === $parsed ) {
            return array( $sql );
        }

        $header = $parsed['header'];
        $tuples = $parsed['tuples'];

        $parts   = array();
        $current = '';
        foreach ( $tuples as $tuple ) {
            if ( '' === $current ) {
                $current = $header . $tuple;
                continue;
            }
            if ( strlen( $current ) + 1 + strlen( $tuple ) <= $max_bytes ) {
                $current .= ',' . $tuple;
            } else {
                $parts[] = $current;
                $current = $header . $tuple;
            }
        }
        if ( '' !== $current ) {
            $parts[] = $current;
        }

        return $parts;
    }

    /**
     * Parse an INSERT/REPLACE statement into header and value tuples.
     *
     * The header is everything up to (excluding) the first top-level
     * opening parenthesis after the VALUES keyword. Tuples are returned
     * with their surrounding parentheses.
     *
     * @param string $sql Statement text.
     *
     * @return array|null { header: string, tuples: string[] } or null
     *                    when the statement is not a parsable multi-row
     *                    INSERT.
     */
    private function parse( $sql ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity -- flat scanner.
        if ( ! preg_match( '/^\s*(INSERT|REPLACE)\s+INTO\b/i', $sql ) ) {
            return null;
        }

        $values_pos = $this->find_values_keyword( $sql );
        if ( null === $values_pos ) {
            return null;
        }

        $len = strlen( $sql );
        $pos = $values_pos;
        while ( $pos < $len && '(' !== $sql[ $pos ] ) {
            ++$pos;
        }
        if ( $pos >= $len ) {
            return null;
        }

        $header = substr( $sql, 0, $pos );
        $tuples = array();

        while ( $pos < $len ) {
            $end = $this->scan_tuple( $sql, $pos );
            if ( null === $end ) {
                return null;
            }
            $tuples[] = substr( $sql, $pos, $end - $pos );
            $pos      = $end;

            // Skip separator to the next tuple.
            while ( $pos < $len && ( ',' === $sql[ $pos ] || ctype_space( $sql[ $pos ] ) ) ) {
                ++$pos;
            }
            if ( $pos < $len && '(' !== $sql[ $pos ] ) {
                return null;
            }
        }

        if ( empty( $tuples ) ) {
            return null;
        }

        return array(
            'header' => $header,
            'tuples' => $tuples,
        );
    }

    /**
     * Locate the top-level VALUES keyword.
     *
     * Scans outside quotes and parentheses so a column or string named
     * "values" cannot fool it.
     *
     * @param string $sql Statement text.
     *
     * @return int|null Offset just past VALUES, or null.
     */
    private function find_values_keyword( $sql ) {
        $len   = strlen( $sql );
        $depth = 0;
        $pos   = 0;

        while ( $pos < $len ) {
            $char = $sql[ $pos ];
            if ( "'" === $char || '"' === $char || '`' === $char ) {
                $pos = $this->skip_quoted( $sql, $pos, $char );
                continue;
            }
            if ( '(' === $char ) {
                ++$depth;
                ++$pos;
                continue;
            }
            if ( ')' === $char ) {
                --$depth;
                ++$pos;
                continue;
            }
            if ( 0 === $depth && ( 'v' === $char || 'V' === $char ) && preg_match( '/\GVALUES?\b/iA', $sql, $m, 0, $pos ) ) {
                $before = $pos > 0 ? $sql[ $pos - 1 ] : ' ';
                if ( ! ctype_alnum( $before ) && '_' !== $before && '`' !== $before ) {
                    return $pos + strlen( $m[0] );
                }
            }
            ++$pos;
        }

        return null;
    }

    /**
     * Scan one parenthesized value tuple.
     *
     * @param string $sql Statement text.
     * @param int    $pos Offset of the opening parenthesis.
     *
     * @return int|null Offset just past the closing parenthesis, or null
     *                  when the tuple never closes.
     */
    private function scan_tuple( $sql, $pos ) {
        $len   = strlen( $sql );
        $depth = 0;

        while ( $pos < $len ) {
            $char = $sql[ $pos ];
            if ( "'" === $char || '"' === $char || '`' === $char ) {
                $pos = $this->skip_quoted( $sql, $pos, $char );
                continue;
            }
            if ( '(' === $char ) {
                ++$depth;
                ++$pos;
                continue;
            }
            if ( ')' === $char ) {
                --$depth;
                ++$pos;
                if ( 0 === $depth ) {
                    return $pos;
                }
                continue;
            }
            ++$pos;
        }

        return null;
    }

    /**
     * Skip a quoted run starting at the opening quote.
     *
     * Backslash escapes apply inside single and double quotes; doubled
     * quote characters escape themselves in every context.
     *
     * @param string $sql   Statement text.
     * @param int    $pos   Offset of the opening quote.
     * @param string $quote Quote character.
     *
     * @return int Offset just past the closing quote (or end of string).
     */
    private function skip_quoted( $sql, $pos, $quote ) {
        $len = strlen( $sql );
        ++$pos;

        while ( $pos < $len ) {
            $char = $sql[ $pos ];
            if ( '\\' === $char && '`' !== $quote ) {
                $pos += 2;
                continue;
            }
            if ( $quote === $char ) {
                if ( $pos + 1 < $len && $quote === $sql[ $pos + 1 ] ) {
                    $pos += 2;
                    continue;
                }
                return $pos + 1;
            }
            ++$pos;
        }

        return $pos;
    }
}
