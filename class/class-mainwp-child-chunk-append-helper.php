<?php
/**
 * MainWP Child Chunk Append Helper - shared verify-append primitives.
 *
 * The two chunk receivers (restore artifacts into a task's scratch dir,
 * staged parent manifests into the backup dir) speak the same wire
 * protocol: every chunk arrives with its own sha256, appends at the
 * confirmed offset, a zero-byte first chunk still creates the file, and
 * the final chunk verifies the whole file. The primitives live here;
 * each receiver keeps its own gate ordering, session anchoring, size
 * ceiling, and cleanup, because those differ on purpose.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Child_Chunk_Append_Helper
 *
 * @package MainWP\Child
 */
class MainWP_Child_Chunk_Append_Helper { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Whether a chunk's bytes match the hash it arrived with.
     *
     * An empty hash never validates - the check cannot be skipped from
     * the wire.
     *
     * @param string $raw          Decoded chunk bytes.
     * @param string $chunk_sha256 Expected sha256 of the chunk bytes.
     *
     * @return bool True when the hash matches.
     */
    public static function valid_chunk_hash( $raw, $chunk_sha256 ) {
        return '' !== $chunk_sha256 && hash_equals( $chunk_sha256, hash( 'sha256', $raw ) );
    }

    /**
     * Append chunk bytes to the staging file.
     *
     * A zero-byte chunk at offset zero still creates the file, so the
     * final hash check and the apply phase see it (an empty tombstones
     * list, an empty manifest).
     *
     * @param string $path   Staging file path (never derived from wire input).
     * @param string $raw    Decoded chunk bytes.
     * @param int    $offset Byte offset of this chunk.
     *
     * @return string|null Error code on a failed write, null on success.
     */
    public static function append_chunk( $path, $raw, $offset ) {
        if ( '' !== $raw ) {
            $fh = fopen( $path, 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- streaming append to the staging file.
            if ( false === $fh || strlen( $raw ) !== fwrite( $fh, $raw ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- streaming append.
                if ( false !== $fh ) {
                    fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- paired with fopen above.
                }
                return MainWP_Backup_Error::ERR_DISK_FULL;
            }
            fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- paired with fopen above.
            return null;
        }

        if ( ! file_exists( $path ) && 0 === $offset ) {
            file_put_contents( $path, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- creating the empty staging file.
        }

        return null;
    }

    /**
     * Whether the completed file matches its whole-file hash.
     *
     * An empty expected hash never passes. The caller owns the cleanup
     * on mismatch - the receivers discard different things (a bare
     * file, a session plus its file).
     *
     * @param string $path     Staging file path.
     * @param string $expected Expected sha256 of the whole file.
     *
     * @return bool True when the file matches.
     */
    public static function verify_final( $path, $expected ) {
        if ( '' === (string) $expected ) {
            return false;
        }
        $actual = hash_file( 'sha256', $path );
        return is_string( $actual ) && hash_equals( (string) $expected, $actual );
    }
}
