<?php
/**
 * MainWP Backup SQL Splitter - state-machine statement boundary detection.
 *
 * Consumes a SQL dump in arbitrary-sized chunks and yields complete
 * statements. A semicolon terminates a statement only when the scanner
 * is outside every parser context: single-quoted strings, double-quoted
 * strings, backtick-quoted identifiers, block comments, line comments
 * (dash-dash and hash), and 0x hex literal runs. A naive explode on
 * semicolons breaks on multi-line INSERTs whose string values contain
 * semicolons, so the dump replay feeds every byte through this scanner
 * instead.
 *
 * Chunk boundaries may fall anywhere, including between a backslash and
 * the character it escapes or in the middle of a comment opener. The
 * scanner never consumes a byte whose meaning depends on lookahead it
 * does not have yet; it stops there and resumes when more input arrives.
 * The full scanner state can be exported and restored so a resumable
 * replay can continue mid-statement after a process restart.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Backup_Sql_Splitter
 *
 * @package MainWP\Child
 */
class MainWP_Backup_Sql_Splitter { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Outside any quoted or commented context.
     */
    const CTX_NONE = 'none';

    /**
     * Inside a single-quoted string.
     */
    const CTX_SINGLE = 'single';

    /**
     * Inside a double-quoted string.
     */
    const CTX_DOUBLE = 'double';

    /**
     * Inside a backtick-quoted identifier.
     */
    const CTX_BACKTICK = 'backtick';

    /**
     * Inside a block comment.
     */
    const CTX_BLOCK_COMMENT = 'block_comment';

    /**
     * Inside a line comment (dash-dash or hash).
     */
    const CTX_LINE_COMMENT = 'line_comment';

    /**
     * Inside a 0x hex literal run.
     */
    const CTX_HEX = 'hex';

    /**
     * Unconsumed bytes: the current incomplete statement plus any
     * not-yet-scanned input.
     *
     * @var string
     */
    private $buffer = '';

    /**
     * Scan position within the buffer.
     *
     * @var int
     */
    private $pos = 0;

    /**
     * Current scanner context.
     *
     * @var string
     */
    private $context = self::CTX_NONE;

    /**
     * Feed a chunk of SQL and collect any statements it completes.
     *
     * @param string $chunk Raw SQL bytes.
     *
     * @return string[] Complete statements (semicolon stripped), in order.
     */
    public function feed( $chunk ) {
        $this->buffer .= $chunk;
        return $this->scan();
    }

    /**
     * Return the unterminated tail and reset the scanner.
     *
     * @return string Remaining buffered bytes.
     */
    public function flush() {
        $tail          = $this->buffer;
        $this->buffer  = '';
        $this->pos     = 0;
        $this->context = self::CTX_NONE;
        return $tail;
    }

    /**
     * Export scanner state for persistence in a resume cursor.
     *
     * @return array State array.
     */
    public function export_state() {
        return array(
            'buffer'  => $this->buffer,
            'pos'     => $this->pos,
            'context' => $this->context,
        );
    }

    /**
     * Restore scanner state exported by export_state().
     *
     * @param array $state State array.
     */
    public function restore_state( $state ) {
        $this->buffer  = isset( $state['buffer'] ) ? (string) $state['buffer'] : '';
        $this->pos     = isset( $state['pos'] ) ? (int) $state['pos'] : 0;
        $this->context = isset( $state['context'] ) ? (string) $state['context'] : self::CTX_NONE;
    }

    /**
     * Scan the buffer from the saved position, emitting statements at
     * top-level semicolons.
     *
     * @return string[] Completed statements.
     */
    private function scan() {
        $statements = array();
        $len        = strlen( $this->buffer );

        while ( $this->pos < $len ) {
            $advance = $this->step( $len, $statements );
            if ( false === $advance ) {
                // Not enough lookahead to decide; wait for more input.
                break;
            }
            $len = strlen( $this->buffer );
        }

        return $statements;
    }

    /**
     * Process the byte at the current position.
     *
     * Mutates position, context, and (on a statement boundary) the
     * buffer itself. Returns false when the byte needs lookahead that
     * is not buffered yet, leaving the position untouched.
     *
     * @param int   $len         Current buffer length.
     * @param array $statements  Statement accumulator (by reference).
     *
     * @return bool True to continue scanning, false to wait for input.
     */
    private function step( $len, &$statements ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity -- flat scanner dispatch.
        $char = $this->buffer[ $this->pos ];

        switch ( $this->context ) {
            case self::CTX_SINGLE:
                return $this->step_in_string( $len, "'", true );
            case self::CTX_DOUBLE:
                return $this->step_in_string( $len, '"', true );
            case self::CTX_BACKTICK:
                return $this->step_in_string( $len, '`', false );
            case self::CTX_BLOCK_COMMENT:
                if ( '*' === $char ) {
                    if ( $this->pos + 1 >= $len ) {
                        return false;
                    }
                    if ( '/' === $this->buffer[ $this->pos + 1 ] ) {
                        $this->context = self::CTX_NONE;
                        $this->pos    += 2;
                        return true;
                    }
                }
                ++$this->pos;
                return true;
            case self::CTX_LINE_COMMENT:
                if ( "\n" === $char ) {
                    $this->context = self::CTX_NONE;
                }
                ++$this->pos;
                return true;
            case self::CTX_HEX:
                if ( ctype_xdigit( $char ) ) {
                    ++$this->pos;
                    return true;
                }
                // First non-hex byte ends the run; reprocess it outside.
                $this->context = self::CTX_NONE;
                return true;
            default:
                return $this->step_outside( $len, $char, $statements );
        }
    }

    /**
     * Process a byte while outside every context.
     *
     * @param int    $len        Buffer length.
     * @param string $char       Current byte.
     * @param array  $statements Statement accumulator (by reference).
     *
     * @return bool True to continue, false to wait for lookahead.
     */
    private function step_outside( $len, $char, &$statements ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity -- flat scanner dispatch.
        switch ( $char ) {
            case ';':
                $statement = trim( substr( $this->buffer, 0, $this->pos ) );
                if ( '' !== $statement ) {
                    $statements[] = $statement;
                }
                $this->buffer = substr( $this->buffer, $this->pos + 1 );
                $this->pos    = 0;
                return true;
            case "'":
                $this->context = self::CTX_SINGLE;
                ++$this->pos;
                return true;
            case '"':
                $this->context = self::CTX_DOUBLE;
                ++$this->pos;
                return true;
            case '`':
                $this->context = self::CTX_BACKTICK;
                ++$this->pos;
                return true;
            case '#':
                $this->context = self::CTX_LINE_COMMENT;
                ++$this->pos;
                return true;
            case '/':
                if ( $this->pos + 1 >= $len ) {
                    return false;
                }
                if ( '*' === $this->buffer[ $this->pos + 1 ] ) {
                    $this->context = self::CTX_BLOCK_COMMENT;
                    $this->pos    += 2;
                    return true;
                }
                ++$this->pos;
                return true;
            case '-':
                return $this->step_dash( $len );
            case '0':
                return $this->step_zero( $len );
            default:
                ++$this->pos;
                return true;
        }
    }

    /**
     * Decide whether a dash opens a line comment.
     *
     * MySQL requires dash-dash followed by whitespace (or end of line)
     * to open a comment; a bare dash is just a token byte.
     *
     * @param int $len Buffer length.
     *
     * @return bool True to continue, false to wait for lookahead.
     */
    private function step_dash( $len ) {
        if ( $this->pos + 1 >= $len ) {
            return false;
        }
        if ( '-' !== $this->buffer[ $this->pos + 1 ] ) {
            ++$this->pos;
            return true;
        }
        if ( $this->pos + 2 >= $len ) {
            return false;
        }
        $third = $this->buffer[ $this->pos + 2 ];
        if ( ' ' === $third || "\t" === $third || "\r" === $third || "\n" === $third ) {
            $this->context = self::CTX_LINE_COMMENT;
            $this->pos    += 2;
            return true;
        }
        $this->pos += 2;
        return true;
    }

    /**
     * Decide whether a zero opens a 0x hex literal run.
     *
     * The zero must not be part of a preceding identifier or number
     * (the byte before it must not be a word character).
     *
     * @param int $len Buffer length.
     *
     * @return bool True to continue, false to wait for lookahead.
     */
    private function step_zero( $len ) {
        if ( $this->pos + 2 >= $len ) {
            return false;
        }
        $prev_ok = true;
        if ( $this->pos > 0 ) {
            $prev    = $this->buffer[ $this->pos - 1 ];
            $prev_ok = ! ( ctype_alnum( $prev ) || '_' === $prev || '$' === $prev );
        }
        if ( $prev_ok && 'x' === strtolower( $this->buffer[ $this->pos + 1 ] ) && ctype_xdigit( $this->buffer[ $this->pos + 2 ] ) ) {
            $this->context = self::CTX_HEX;
            $this->pos    += 3;
            return true;
        }
        ++$this->pos;
        return true;
    }

    /**
     * Process a byte inside a quoted string or identifier.
     *
     * Doubled quote characters escape themselves in every context;
     * backslash escapes apply inside single- and double-quoted strings
     * but not inside backtick identifiers.
     *
     * @param int    $len              Buffer length.
     * @param string $quote            The closing quote character.
     * @param bool   $backslash_escapes Whether backslash escapes apply.
     *
     * @return bool True to continue, false to wait for lookahead.
     */
    private function step_in_string( $len, $quote, $backslash_escapes ) {
        $char = $this->buffer[ $this->pos ];

        if ( $backslash_escapes && '\\' === $char ) {
            if ( $this->pos + 1 >= $len ) {
                return false;
            }
            $this->pos += 2;
            return true;
        }

        if ( $quote === $char ) {
            if ( $this->pos + 1 >= $len ) {
                return false;
            }
            if ( $quote === $this->buffer[ $this->pos + 1 ] ) {
                // Doubled quote: escaped, still inside.
                $this->pos += 2;
                return true;
            }
            $this->context = self::CTX_NONE;
            ++$this->pos;
            return true;
        }

        ++$this->pos;
        return true;
    }
}
