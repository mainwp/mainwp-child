<?php
/**
 * MainWP Child Backup Download Token.
 *
 * One-time authorization tokens for backup artifact downloads. The Dashboard
 * requests a token over the authenticated callable surface before fetching a
 * file; the download request then carries the token, which is bound to one
 * file and consumed on first use. This closes the replay window of the signed
 * download URL while leaving the token-less legacy path available for older
 * Dashboards.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Child_Backup_Download_Token
 *
 * @package MainWP\Child
 */
class MainWP_Child_Backup_Download_Token { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    const TRANSIENT_PREFIX = 'mainwp_backup_download_';
    const TOKEN_LENGTH     = 32;
    const TTL              = HOUR_IN_SECONDS;

    /**
     * Option holding the token-required ratchet. Set to 1 after the
     * first successful token-authorized download and never cleared by
     * the plugin: the connected dashboard proved it sends tokens, so
     * the tokenless legacy path has no caller left.
     */
    const OPTION_REQUIRE_TOKEN = 'mainwp_require_download_token';

    /**
     * Public static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    protected static $instance = null;

    /**
     * Method instance()
     *
     * Create a public static instance.
     *
     * @return mixed Class instance.
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new static();
        }
        return static::$instance;
    }

    /**
     * Issue a one-time download token for a backup artifact.
     *
     * Hashes the file once at issuance; the Dashboard verifies the sha256 of
     * the received bytes against this hash after the transfer.
     *
     * @param string $basename Backup file name (no path).
     *
     * @return array Token payload (token, hash, size, expires_in) or error array (error, error_code).
     */
    public function issue( $basename ) {
        $full_path = MainWP_Helper::locate_backup_file( basename( (string) $basename ) );
        if ( false === $full_path ) {
            return array(
                'error'      => MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_DL_FILE_NOT_FOUND ),
                'error_code' => MainWP_Backup_Error::ERR_DL_FILE_NOT_FOUND,
            );
        }

        $hash = hash_file( 'sha256', $full_path );
        if ( $this->recorded_hash_mismatch( basename( $full_path ), $hash ) ) {
            return array(
                'error'      => MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_DL_FILE_HASH_MISMATCH ),
                'error_code' => MainWP_Backup_Error::ERR_DL_FILE_HASH_MISMATCH,
            );
        }
        $size  = (int) filesize( $full_path );
        $mtime = (int) filemtime( $full_path );
        $token = wp_generate_password( self::TOKEN_LENGTH, false );

        set_transient(
            self::TRANSIENT_PREFIX . $token,
            array(
                'basename'  => basename( $full_path ),
                'full_path' => $full_path,
                'hash'      => $hash,
                'size'      => $size,
                'mtime'     => $mtime,
                'created'   => time(),
            ),
            self::TTL
        );

        $this->audit( 'token_issued', basename( $full_path ), $size, '' );
        MainWP_Helper::log_debug( 'backup_download_token: issued for ' . basename( $full_path ) );

        return array(
            'token'      => $token,
            'hash'       => $hash,
            'size'       => $size,
            'expires_in' => self::TTL,
        );
    }

    /**
     * Shared chain files are rewritten by later runs; only run-owned archives bind to a finished hash.
     *
     * @param string       $basename Requested file.
     * @param string|false $hash Current digest.
     * @return bool Whether recorded evidence exists but none matches.
     */
    private function recorded_hash_mismatch( $basename, $hash ) {
        if ( MainWP_Child_Backup_Task::is_chain_file( $basename ) ) {
            return false;
        }
        global $wpdb;
        $spine    = MainWP_Child_Backup_Task::instance();
        $recorded = false;
        $like     = '%' . $wpdb->esc_like( substr( wp_json_encode( $basename ), 1, -1 ) ) . '%';
        // Legacy downloads also work before the task table exists.
        $suppress = $wpdb->suppress_errors( true );
        try {
            $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- completed cursors are the recorded artifact evidence.
                $wpdb->prepare(
                    'SELECT task_id, sub_state FROM ' . $spine->get_table_name() . " WHERE phase = 'completed' AND type IN ('full','files','db','incremental') AND sub_state LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- trusted table helper; the filename pattern is prepared.
                    $like
                )
            );
        } finally {
            $wpdb->suppress_errors( $suppress );
        }
        foreach ( (array) $rows as $row ) {
            $sub = json_decode( (string) $row->sub_state, true );
            if ( ! empty( $sub['_sidecar'] ) ) {
                $entries = array();
                if ( isset( $sub['artifact_sha256'][ $basename ] ) ) {
                    $entries[] = array(
                        'file'   => $basename,
                        'sha256' => $sub['artifact_sha256'][ $basename ],
                    );
                }
            } else {
                $entries = $sub['artifacts'] ?? array();
                if ( isset( $sub['artifact'] ) ) {
                    $entries[] = $sub['artifact'];
                }
            }
            foreach ( $entries as $entry ) {
                if ( ! isset( $entry['file'], $entry['sha256'] ) || isset( $entry['hash_skipped'] )
                    || basename( (string) $entry['file'] ) !== $basename || ! is_string( $entry['sha256'] )
                    || ! preg_match( '/^[a-f0-9]{64}$/D', $entry['sha256'] ) ) {
                    continue;
                }
                $recorded = true;
                if ( is_string( $hash ) && hash_equals( $entry['sha256'], $hash ) ) {
                    return false;
                }
            }
        }
        return $recorded;
    }

    /**
     * Validate a download token and consume it.
     *
     * Deleting the transient is the consumption gate and happens after the
     * binding checks pass: a request rejected for a wrong name or a missing
     * or changed file must leave the token valid so the Dashboard can retry
     * with a corrected request. Only the caller whose delete actually
     * removes the row may continue, so two concurrent requests with the
     * same token still cannot both pass. This is exactly-once only with
     * DB-backed transients (the underlying SQL DELETE is atomic and
     * delete_transient() returns false when the row is already gone). Under
     * a persistent object cache, some Redis/Memcached backends return true
     * when deleting a missing key, so treat the gate as best-effort there -
     * the download is still authenticated, file-bound, and time-limited.
     *
     * @param mixed  $token              Token from the download request.
     * @param string $requested_basename File name the request asked for.
     *
     * @return array Result: array('ok' => true, 'full_path' => string) or array('ok' => false, 'code' => string).
     */
    public function validate_and_consume( $token, $requested_basename ) {
        // Format guard - also prevents transient-key injection.
        if ( ! is_string( $token ) || self::TOKEN_LENGTH !== strlen( $token ) || ! ctype_alnum( $token ) ) {
            return array(
                'ok'   => false,
                'code' => MainWP_Backup_Error::ERR_DL_TOKEN_INVALID,
            );
        }

        $data = get_transient( self::TRANSIENT_PREFIX . $token );
        if ( false === $data || ! is_array( $data ) ) {
            return array(
                'ok'   => false,
                'code' => MainWP_Backup_Error::ERR_DL_TOKEN_INVALID,
            );
        }

        if ( basename( $data['full_path'] ) !== basename( (string) $requested_basename ) ) {
            return array(
                'ok'   => false,
                'code' => MainWP_Backup_Error::ERR_DL_TOKEN_INVALID,
            );
        }

        if ( ! file_exists( $data['full_path'] ) ) {
            return array(
                'ok'   => false,
                'code' => MainWP_Backup_Error::ERR_DL_FILE_NOT_FOUND,
            );
        }

        clearstatcache( true, $data['full_path'] );
        if ( (int) filemtime( $data['full_path'] ) !== (int) $data['mtime'] || (int) filesize( $data['full_path'] ) !== (int) $data['size'] ) {
            return array(
                'ok'   => false,
                'code' => MainWP_Backup_Error::ERR_DL_FILE_CHANGED,
            );
        }

        // Only consume the token once the request is fully validated; a refused
        // download (wrong name, missing or changed file) leaves it valid for a
        // retry. The delete's return value still arbitrates concurrent consumers.
        if ( ! delete_transient( self::TRANSIENT_PREFIX . $token ) ) {
            // Already consumed by a concurrent request.
            return array(
                'ok'   => false,
                'code' => MainWP_Backup_Error::ERR_DL_TOKEN_INVALID,
            );
        }

        return array(
            'ok'        => true,
            'full_path' => $data['full_path'],
        );
    }

    /**
     * Authorize a download request carrying a token.
     *
     * Wraps validate_and_consume() with the audit events and the HTTP status
     * mapping so the download endpoint stays a thin dispatcher.
     *
     * @param mixed  $token              Token from the download request.
     * @param string $requested_basename File name the request asked for.
     *
     * @return array array('ok' => true, 'full_path' => string) or array('ok' => false, 'status' => int).
     */
    public function authorize_download( $token, $requested_basename ) {
        $result = $this->validate_and_consume( $token, $requested_basename );

        if ( empty( $result['ok'] ) ) {
            $this->audit( 'download_rejected', basename( (string) $requested_basename ), 0, $result['code'] );
            MainWP_Helper::log_debug( 'backup_download_token: rejected (' . $result['code'] . ') for ' . basename( (string) $requested_basename ) );
            return array(
                'ok'     => false,
                'status' => $this->rejection_status( $result['code'] ),
            );
        }

        $size = (int) filesize( $result['full_path'] );
        $this->audit( 'download_ok', basename( $result['full_path'] ), $size, '' );
        MainWP_Helper::log_debug( 'backup_download_token: streaming ' . basename( $result['full_path'] ) );

        // The ratchet: this download just proved the connected dashboard
        // sends tokens, so tokenless backup downloads are refused from
        // here on. A dashboard migrated to a new install obtains tokens
        // the same way - the requirement binds to the token mechanism,
        // never to a particular dashboard.
        update_option( self::OPTION_REQUIRE_TOKEN, 1, false );

        return $result;
    }

    /**
     * Whether tokenless backup downloads are refused on this site.
     *
     * @return bool True once the ratchet has clicked.
     */
    public function is_token_required() {
        return 1 === (int) get_option( self::OPTION_REQUIRE_TOKEN, 0 );
    }

    /**
     * Map a rejection code to the HTTP status for the download response.
     *
     * @param string $code Error code from validate_and_consume().
     *
     * @return int 401 for token problems (invalid, expired, consumed, wrong file), 403 for file problems (missing, changed).
     */
    public function rejection_status( $code ) {
        return MainWP_Backup_Error::ERR_DL_TOKEN_INVALID === $code ? 401 : 403;
    }

    /**
     * Fire the download audit action.
     *
     * @param string $event  Event name: token_issued, download_ok, or download_rejected.
     * @param string $file   File basename.
     * @param int    $size   File size in bytes (0 when unknown).
     * @param string $reason Rejection reason code, empty on success events.
     */
    public function audit( $event, $file, $size, $reason ) {
        /**
         * Fires for every backup download authorization event.
         *
         * @param array $entry Audit entry: event, file, size, ip, time, reason.
         */
        do_action(
            'mainwp_child_backup_download_audit',
            array(
                'event'  => $event,
                'file'   => $file,
                'size'   => (int) $size,
                'ip'     => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
                'time'   => time(),
                'reason' => $reason,
            )
        );
    }
}
