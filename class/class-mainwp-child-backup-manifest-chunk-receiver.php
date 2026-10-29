<?php
/**
 * MainWP Child Backup Manifest Chunk Receiver - stages an oversized
 * parent manifest delivered in pieces.
 *
 * Incremental backups normally receive the parent manifest inline with
 * the task-create request, capped to what one request can carry. A
 * manifest over that cap arrives here instead: the dashboard streams
 * hash-verified chunks into a staging file keyed by a child-generated
 * token, and the task-create request then references the token. The
 * staged file is verified against its whole-file checksum and consumed
 * exactly once; an orphaned session is swept with the other stale
 * backup-dir markers.
 *
 * The staging path never derives from wire input - the token is
 * generated here and the path comes from the stored session.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Child_Backup_Manifest_Chunk_Receiver
 *
 * @package MainWP\Child
 */
class MainWP_Child_Backup_Manifest_Chunk_Receiver { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Staging session option prefix (suffixed with the token).
     */
    const OPTION_PREFIX = 'mainwp_manifest_staging_';

    /**
     * Safety ceiling for a staged manifest, in raw bytes.
     */
    const MAX_MANIFEST_RAW = 52428800;

    /**
     * Singleton instance.
     *
     * @var MainWP_Child_Backup_Manifest_Chunk_Receiver|null
     */
    protected static $instance = null;

    /**
     * Get the singleton instance.
     *
     * @return MainWP_Child_Backup_Manifest_Chunk_Receiver
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * The backup directory staged manifests live in (test seam).
     *
     * @return string Backup directory.
     */
    protected function backup_dir() {
        $dirs = MainWP_Helper::get_mainwp_dir( 'backup', false );
        // A failed lookup must not turn the protection filenames into relative paths.
        if ( ! is_array( $dirs ) || ! isset( $dirs[0] ) ) {
            return '';
        }
        MainWP_Helper::protect_backup_path( $dirs[0] );
        MainWP_Helper::protect_backup_path( $dirs[0] . 'index.php' );
        MainWP_Helper::protect_backup_path( $dirs[0] . '.htaccess' );
        return $dirs[0];
    }

    /**
     * Receive one manifest chunk into the staging session.
     *
     * An empty token starts a new session: the token is generated here
     * and returned in the ack, so no wire value ever becomes a path
     * component. Offsets follow the artifact-chunk semantics: behind
     * the file is a confirmed duplicate, past it is a gap the sender
     * realigns from, and the final chunk verifies the whole file.
     *
     * @param string $mtoken       Staging token, empty on the first chunk.
     * @param int    $offset       Byte offset of this chunk.
     * @param string $raw          Decoded chunk bytes.
     * @param string $chunk_sha256 Expected sha256 of the chunk bytes.
     * @param string $total_sha256 Expected sha256 of the whole manifest.
     * @param bool   $is_final     Whether this is the last chunk.
     *
     * @return array Ack { result, mtoken, bytes_received } or error
     *               { error, error_code, bytes_received? }.
     */
    public function receive_chunk( $mtoken, $offset, $raw, $chunk_sha256, $total_sha256, $is_final ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity -- ordered integrity gates on the only write path from the wire.
        if ( ! MainWP_Child_Chunk_Append_Helper::valid_chunk_hash( $raw, $chunk_sha256 ) ) {
            return $this->chunk_error( MainWP_Backup_Error::ERR_CHUNK_HASH );
        }

        $started_session = false;

        if ( '' === $mtoken ) {
            if ( 0 !== $offset ) {
                return $this->chunk_error( MainWP_Backup_Error::ERR_MANIFEST_CHUNK_WRONG_PHASE );
            }
            $mtoken  = $this->generate_token();
            $path    = $this->staged_path( $mtoken );
            $session = array(
                'path'         => $path,
                'total_sha256' => (string) $total_sha256,
                'ready'        => false,
            );
            update_option( self::OPTION_PREFIX . $mtoken, $session, false );
            $started_session = true;
        } else {
            $session = $this->session( $mtoken );
            if ( null === $session ) {
                return $this->chunk_error( MainWP_Backup_Error::ERR_MANIFEST_CHUNK_WRONG_PHASE );
            }
            $path = $session['path'];
        }

        $size = file_exists( $path ) ? (int) filesize( $path ) : 0;

        if ( $offset < 0 || $offset > $size ) {
            return array(
                'error'          => MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_CHUNK_GAP ),
                'error_code'     => MainWP_Backup_Error::ERR_CHUNK_GAP,
                'bytes_received' => $size,
            );
        }

        if ( $offset < $size ) {
            // Already-written bytes: a duplicate of a confirmed chunk.
            return $this->chunk_ack( $mtoken, $size );
        }

        if ( $size + strlen( $raw ) > self::MAX_MANIFEST_RAW ) {
            $this->discard_session( $mtoken, $path );
            return $this->chunk_error( MainWP_Backup_Error::ERR_MANIFEST_TOO_LARGE );
        }

        $write_error = MainWP_Child_Chunk_Append_Helper::append_chunk( $path, $raw, $offset );
        if ( null !== $write_error ) {
            if ( $started_session ) {
                // Why: sweep_stale_markers() only clears an option after it removes a matching
                // file, so a session that never got its first byte written would outlive its sweep.
                $this->discard_session( $mtoken, $path );
            }
            return $this->chunk_error( $write_error );
        }
        if ( '' !== $raw ) {
            clearstatcache( true, $path );
            $size = (int) filesize( $path );
        }

        if ( $is_final ) {
            $expected = isset( $session['total_sha256'] ) ? (string) $session['total_sha256'] : '';
            if ( ! MainWP_Child_Chunk_Append_Helper::verify_final( $path, $expected ) ) {
                $this->discard_session( $mtoken, $path );
                return $this->chunk_error( MainWP_Backup_Error::ERR_MANIFEST_CHUNK_CORRUPT );
            }
            $session['ready'] = true;
            update_option( self::OPTION_PREFIX . $mtoken, $session, false );
        }

        return $this->chunk_ack( $mtoken, $size );
    }

    /**
     * Hand a completed staging session to a backup task, exactly once.
     *
     * The staged file is verified against the hash the task-create
     * request carries; the file and the session are removed whether the
     * handover succeeds or the verification discards it.
     *
     * @param string $mtoken          Staging token.
     * @param string $expected_sha256 Whole-manifest sha256 from the task-create request.
     *
     * @return array { b64: string } or { error, error_code }.
     */
    public function consume_staged( $mtoken, $expected_sha256 ) {
        $session = $this->session( $mtoken );
        if ( null === $session || empty( $session['ready'] ) ) {
            return $this->chunk_error( MainWP_Backup_Error::ERR_MANIFEST_CHUNK_WRONG_PHASE );
        }

        $path = $session['path'];
        if ( ! is_readable( $path ) ) {
            $this->discard_session( $mtoken, $path );
            return $this->chunk_error( MainWP_Backup_Error::ERR_MANIFEST_CHUNK_CORRUPT );
        }

        $actual = hash_file( 'sha256', $path );
        if ( '' === (string) $expected_sha256 || ! is_string( $actual ) || ! hash_equals( (string) $expected_sha256, $actual ) ) {
            $this->discard_session( $mtoken, $path );
            return $this->chunk_error( MainWP_Backup_Error::ERR_MANIFEST_CHUNK_CORRUPT );
        }

        $content = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- bounded read of the verified staging file.
        $this->discard_session( $mtoken, $path );

        if ( false === $content ) {
            return $this->chunk_error( MainWP_Backup_Error::ERR_MANIFEST_CHUNK_CORRUPT );
        }

        return array( 'b64' => base64_encode( $content ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the task cursor stores the manifest base64-encoded, matching the inline path.
    }

    /**
     * Load a staging session for a wire-supplied token.
     *
     * The token charset is enforced before it touches an option name,
     * and the path always comes from the stored session.
     *
     * @param string $mtoken Staging token from the wire.
     *
     * @return array|null Session, or null when absent or malformed.
     */
    protected function session( $mtoken ) {
        if ( ! is_string( $mtoken ) || ! preg_match( '/^[a-z0-9]{16}$/', $mtoken ) ) {
            return null;
        }
        $session = get_option( self::OPTION_PREFIX . $mtoken );
        if ( ! is_array( $session ) || empty( $session['path'] ) ) {
            return null;
        }
        return $session;
    }

    /**
     * Generate a new staging token from the platform CSPRNG.
     *
     * @return string Token (16 lowercase hex chars).
     */
    protected function generate_token() {
        return bin2hex( random_bytes( 8 ) );
    }

    /**
     * The staged file path for a token.
     *
     * @param string $mtoken Staging token (child-generated).
     *
     * @return string Absolute path.
     */
    protected function staged_path( $mtoken ) {
        return rtrim( $this->backup_dir(), '/' ) . '/manifest-staged-' . $mtoken . '.list';
    }

    /**
     * Remove a staging session and its file.
     *
     * @param string $mtoken Staging token.
     * @param string $path   Staged file path.
     */
    protected function discard_session( $mtoken, $path ) {
        if ( is_string( $path ) && '' !== $path && file_exists( $path ) ) {
            wp_delete_file( $path );
        }
        delete_option( self::OPTION_PREFIX . $mtoken );
    }

    /**
     * Build an error payload from a registry code.
     *
     * @param string $code Error code.
     *
     * @return array Error payload.
     */
    protected function chunk_error( $code ) {
        return array(
            'error'      => MainWP_Backup_Error::message( $code ),
            'error_code' => $code,
        );
    }

    /**
     * Build a chunk acknowledgment.
     *
     * @param string $mtoken Staging token.
     * @param int    $size   Bytes received so far.
     *
     * @return array Ack payload.
     */
    protected function chunk_ack( $mtoken, $size ) {
        return array(
            'result'         => 'ok',
            'mtoken'         => $mtoken,
            'bytes_received' => $size,
        );
    }
}
