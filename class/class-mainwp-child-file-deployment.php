<?php
/**
 * MainWP File Uploader verified Child deployment protocol.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag -- Private protocol helpers use closed validators at their call sites.
// phpcs:disable WordPress.WP.AlternativeFunctions -- Atomic filesystem effects require exclusive create, fsync-style flush, and rename semantics.
// phpcs:disable Universal.NamingConventions.NoReservedKeywordParameterNames -- Parent denotes a filesystem parent directory.

/**
 * Read-pure destination preflight plus durable deploy/status/rollback effects.
 */
class MainWP_Child_File_Deployment {

    /** Maximum accepted object bytes. */
    const MAX_BYTES = 52428800;

    /** Receipt and backup retention. */
    const RECEIPT_TTL = 86400;

    /** Dispatch one authenticated preflight request. */
    public function handle_preflight() {
        MainWP_Helper::write( $this->preflight_v2( $this->request_from_post() ) );
    }

    /** Dispatch one authenticated deploy request. */
    public function handle_deploy() {
        MainWP_Helper::write( $this->deploy_v2( $this->request_from_post() ) );
    }

    /** Dispatch one authenticated state request. */
    public function handle_state() {
        MainWP_Helper::write( $this->deployment_state_v2( $this->request_from_post() ) );
    }

    /** Dispatch one authenticated rollback request. */
    public function handle_rollback() {
        MainWP_Helper::write( $this->rollback_v2( $this->request_from_post() ) );
    }

    /** Return one exact destination snapshot without creating files or directories. */
    public function preflight_v2( $request ) {
        if ( ! $this->valid_envelope( $request, 'preflight' ) || ! $this->valid_preflight_payload( $request['payload'] ) ) {
            return $this->error( 'preflight', 'invalid_request' );
        }
        $payload     = $request['payload'];
        $destination = $this->normalize_destination( $payload['destination_class'], $payload['relative_destination'] );
        if ( false === $destination ) {
            return $this->error( 'preflight', 'destination_forbidden' );
        }
        $snapshot = $this->target_snapshot( $payload['destination_class'], $destination );
        if ( ! $this->valid_target_snapshot( $snapshot ) ) {
            return $this->error( 'preflight', 'storage_unavailable' );
        }

        return array(
            'protocol'             => '2',
            'operation'            => 'preflight',
            'ok'                   => true,
            'request_ref'          => $payload['request_ref'],
            'relative_destination' => $destination,
            'destination_class'    => $payload['destination_class'],
            'target_exists'        => $snapshot['exists'],
            'prior_bytes'          => $snapshot['bytes'],
            'prior_sha256'         => $snapshot['sha256'],
            'prior_mode'           => $snapshot['mode'],
            'writable'             => $snapshot['writable'],
            'rollback_available'   => $snapshot['rollback_available'],
            'state_revision'       => $this->state_revision( $payload['destination_class'], $destination, $snapshot ),
        );
    }

    /** Execute or replay one exact deployment. */
    public function deploy_v2( $request ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity -- Explicit reserve/download/write/readback transaction.
        if ( ! $this->valid_envelope( $request, 'deploy' ) || ! $this->valid_deploy_payload( $request['payload'] ) ) {
            return $this->error( 'deploy', 'invalid_request' );
        }
        $payload     = $request['payload'];
        $destination = $this->normalize_destination( $payload['destination_class'], $payload['relative_destination'] );
        if ( false === $destination || $destination !== $payload['relative_destination'] ) {
            return $this->error( 'deploy', 'destination_forbidden' );
        }
        if ( ! $this->gateway_allowed( $payload['gateway_url'] ) ) {
            return $this->error( 'deploy', 'gateway_rejected' );
        }
        $lock = $this->acquire_destination_lock( $payload['destination_class'], $destination );
        if ( false === $lock ) {
            return $this->error( 'deploy', 'lock_busy' );
        }
        try {
            $effect_hash = $this->deploy_effect_hash( $payload );
            $existing    = $this->load_receipt( $payload['request_ref'] );
            if ( false === $existing ) {
                return $this->error( 'deploy', 'storage_unavailable' );
            }
            if ( is_array( $existing ) ) {
                return $this->replay_receipt( $existing, $effect_hash, 'deploy' );
            }
            // Only a genuinely new effect is refused: a request the store already knows replays
            // above, so a pre-ban deployment stays readable and rollbackable. The uploads lane is
            // web-served, so server-executed or server-config content deployed there is refused
            // before any write.
            if ( ! $this->uploads_target_allowed( $payload['destination_class'], $destination ) ) {
                return $this->error( 'deploy', 'destination_forbidden' );
            }
            // Only a genuinely new effect sweeps the store, so a pruned file can never be one
            // this request still has to answer for.
            $this->prune_expired_storage();

            $before = $this->target_snapshot( $payload['destination_class'], $destination );
            if ( ! $this->valid_target_snapshot( $before ) || ! hash_equals( $this->state_revision( $payload['destination_class'], $destination, $before ), $payload['state_revision'] ) ) {
                return $this->error( 'deploy', 'stale_revision' );
            }
            if ( ! $before['writable'] || ! $before['rollback_available'] ) {
                return $this->error( 'deploy', 'destination_forbidden' );
            }

            $dispatching = $this->dispatching_receipt( $payload, $destination, $before, $effect_hash );
            if ( ! $this->create_receipt( $payload['request_ref'], $dispatching ) ) {
                $existing = $this->load_receipt( $payload['request_ref'] );
                return is_array( $existing ) ? $this->replay_receipt( $existing, $effect_hash, 'deploy' ) : $this->error( 'deploy', 'storage_unavailable' );
            }

            $bytes = $this->download_gateway_bytes( $payload['gateway_url'], $payload['gateway_token'], $payload['expected_bytes'] );
            if ( ! is_string( $bytes ) || strlen( $bytes ) !== $payload['expected_bytes'] || ! hash_equals( $payload['expected_sha256'], hash( 'sha256', is_string( $bytes ) ? $bytes : '' ) ) ) {
                return $this->settle_failure( $dispatching, 'deploy', 'digest_mismatch' );
            }

            $fresh = $this->target_snapshot( $payload['destination_class'], $destination );
            if ( ! $this->valid_target_snapshot( $fresh ) || $fresh !== $before ) {
                return $this->settle_failure( $dispatching, 'deploy', 'stale_revision' );
            }

            $write = $this->apply_deployment( $payload['destination_class'], $destination, $bytes, $before );
            $after = $this->target_snapshot( $payload['destination_class'], $destination );
            if ( ! is_array( $write ) || ! $this->exact_keys( $write, array( 'backup_ref' ) ) || ( null !== $write['backup_ref'] && ! $this->safe_private_ref( $write['backup_ref'] ) ) || ! $this->valid_target_snapshot( $after ) || ! $after['exists'] || $payload['expected_bytes'] !== $after['bytes'] || ! hash_equals( $payload['expected_sha256'], $after['sha256'] ) ) {
                return $this->settle_failure( $dispatching, 'deploy', 'outcome_unknown', 'unknown' );
            }

            $settled_base                    = $dispatching;
            $settled_base['backup_ref']      = $write['backup_ref'];
            $settled_base['deployed_sha256'] = $after['sha256'];
            $settled_base['deployed_bytes']  = $after['bytes'];
            $result                          = $this->result( 'deploy', $payload['request_ref'], 'completed', $after['bytes'], $after['sha256'], true, null );
            return $this->settle_result( $dispatching, $result, $settled_base );
        } finally {
            $this->release_destination_lock( $lock );
        }
    }

    /** Return one stored deployment result without dispatching. */
    public function deployment_state_v2( $request ) {
        if ( ! $this->valid_envelope( $request, 'status' ) || ! is_array( $request['payload'] ) || ! $this->exact_keys( $request['payload'], array( 'request_ref' ) ) || ! $this->uuid_ref( $request['payload']['request_ref'] ) ) {
            return $this->error( 'status', 'invalid_request' );
        }
        $receipt = $this->load_receipt( $request['payload']['request_ref'] );
        if ( false === $receipt ) {
            return $this->error( 'status', 'storage_unavailable' );
        }
        if ( ! is_array( $receipt ) ) {
            return $this->error( 'status', 'not_found' );
        }
        if ( $this->receipt_expired( $receipt ) ) {
            return $this->error( 'status', 'receipt_expired' );
        }
        return 'dispatching' === $receipt['state'] ? $this->unknown_result( $receipt ) : $receipt['result'];
    }

    /** Restore the exact retained pre-deployment state. */
    public function rollback_v2( $request ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity -- Explicit deployment binding and rollback readback.
        if ( ! $this->valid_envelope( $request, 'rollback' ) || ! $this->valid_rollback_payload( $request['payload'] ) ) {
            return $this->error( 'rollback', 'invalid_request' );
        }
        $payload    = $request['payload'];
        $deployment = $this->load_receipt( $payload['deployment_ref'] );
        if ( false === $deployment ) {
            return $this->error( 'rollback', 'storage_unavailable' );
        }
        if ( ! is_array( $deployment ) || ! $this->valid_receipt( $deployment ) || 'deploy' !== $deployment['kind'] || 'settled' !== $deployment['state'] || true !== $deployment['result']['ok'] ) {
            return $this->error( 'rollback', 'not_found' );
        }
        if ( $this->receipt_expired( $deployment ) ) {
            return $this->error( 'rollback', 'receipt_expired' );
        }
        $lock = $this->acquire_destination_lock( $deployment['destination_class'], $deployment['relative_destination'] );
        if ( false === $lock ) {
            return $this->error( 'rollback', 'lock_busy' );
        }
        $store = false;
        try {
            // Locks are per destination, so a deployment elsewhere can sweep the whole store while
            // this one runs. That sweep decides what a backup is still bound to from a file list it
            // took earlier, so a marker written after the list was taken does not protect the backup
            // this restore needs. Holding the store shared keeps any sweep out of the restore.
            $store = $this->acquire_store_lock( false );
            if ( false === $store ) {
                return $this->error( 'rollback', 'lock_busy' );
            }

            $effect_hash = $this->rollback_effect_hash( $payload, $deployment );
            $existing    = $this->load_receipt( $payload['request_ref'] );
            if ( false === $existing ) {
                return $this->error( 'rollback', 'storage_unavailable' );
            }
            if ( is_array( $existing ) ) {
                return $this->replay_receipt( $existing, $effect_hash, 'rollback' );
            }

            $current = $this->target_snapshot( $deployment['destination_class'], $deployment['relative_destination'] );
            if ( ! $this->valid_target_snapshot( $current ) || ! $current['exists'] || ! hash_equals( $payload['if_current_sha256'], $current['sha256'] ) || ! hash_equals( $deployment['deployed_sha256'], $current['sha256'] ) ) {
                return $this->error( 'rollback', 'stale_revision' );
            }

            $dispatching = $this->rollback_dispatching_receipt( $payload, $deployment, $effect_hash );
            if ( ! $this->create_receipt( $payload['request_ref'], $dispatching ) ) {
                $existing = $this->load_receipt( $payload['request_ref'] );
                return is_array( $existing ) ? $this->replay_receipt( $existing, $effect_hash, 'rollback' ) : $this->error( 'rollback', 'storage_unavailable' );
            }

            // A sweep can still have landed between reading the deployment and holding the store,
            // and it drops a settled deployment past retention together with the backup nothing
            // binds any more. Re-read it now that this rollback is reserved: if the sweep got
            // there first, nothing has been written and saying so is honest.
            $bound = $this->load_receipt( $payload['deployment_ref'] );
            if ( ! is_array( $bound ) || $bound !== $deployment ) {
                return $this->settle_failure( $dispatching, 'rollback', 'not_found' );
            }

            if ( true !== $this->apply_rollback( $deployment ) ) {
                return $this->settle_failure( $dispatching, 'rollback', 'outcome_unknown', 'unknown' );
            }
            $after = $this->target_snapshot( $deployment['destination_class'], $deployment['relative_destination'] );
            if ( ! $this->valid_target_snapshot( $after ) || ( $deployment['prior_exists'] && ( ! $after['exists'] || ! hash_equals( $deployment['prior_sha256'], $after['sha256'] ) ) ) || ( ! $deployment['prior_exists'] && $after['exists'] ) ) {
                return $this->settle_failure( $dispatching, 'rollback', 'outcome_unknown', 'unknown' );
            }
            $result = $this->result( 'rollback', $payload['request_ref'], 'rolled_back', $after['bytes'], $after['sha256'], false, null );
            return $this->settle_result( $dispatching, $result );
        } finally {
            $this->release_store_lock( $store );
            $this->release_destination_lock( $lock );
        }
    }

    /** Build a dispatch marker for tests and durable production storage. */
    protected function dispatching_receipt_for_test( $payload ) {
        $destination = $this->normalize_destination( $payload['destination_class'], $payload['relative_destination'] );
        $before      = $this->target_snapshot( $payload['destination_class'], $destination );
        return $this->dispatching_receipt( $payload, $destination, $before, $this->deploy_effect_hash( $payload ) );
    }

    /** Read one exact target snapshot. */
    protected function target_snapshot( $destination_class, $relative_destination ) {
        $path = $this->target_path( $destination_class, $relative_destination );
        if ( false === $path ) {
            return false;
        }
        if ( ! file_exists( $path ) ) {
            $ancestor = dirname( $path );
            while ( ! is_dir( $ancestor ) && dirname( $ancestor ) !== $ancestor ) {
                if ( is_link( $ancestor ) ) {
                    return false;
                }
                $ancestor = dirname( $ancestor );
            }
            $writable = is_dir( $ancestor ) && ! is_link( $ancestor ) && is_writable( $ancestor );
            return array(
                'exists'             => false,
                'bytes'              => null,
                'sha256'             => null,
                'mode'               => null,
                'writable'           => $writable,
                // Undoing a create is an unlink in that same directory, so it needs nothing else.
                'rollback_available' => $writable,
            );
        }
        $stat = lstat( $path );
        if ( ! is_array( $stat ) || is_link( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
            return false;
        }
        $bytes  = filesize( $path );
        $digest = hash_file( 'sha256', $path );
        $mode   = fileperms( $path );
        if ( false === $bytes || false === $digest || false === $mode || self::MAX_BYTES < $bytes ) {
            return false;
        }
        return array(
            'exists'             => true,
            'bytes'              => (int) $bytes,
            'sha256'             => $digest,
            'mode'               => $mode & 0777,
            'writable'           => is_writable( $path ) && is_writable( dirname( $path ) ),
            // Restoring a replaced file needs a retained private copy and a directory to
            // rename it back into; without both there is nothing to promise the Dashboard.
            'rollback_available' => is_writable( dirname( $path ) ) && $this->backup_storage_available(),
        );
    }

    /** Verify the gateway origin against the authenticated parent connection. */
    protected function gateway_allowed( $url ) {
        if ( ! is_string( $url ) || '' === $url || 2048 < strlen( $url ) ) {
            return false;
        }
        $expected = class_exists( MainWP_Child_Keys_Manager::class ) ? MainWP_Child_Keys_Manager::get_encrypted_option( 'mainwp_child_server' ) : false;
        if ( ! is_string( $expected ) || '' === $expected ) {
            return false;
        }
        $actual_parts   = wp_parse_url( $url );
        $expected_parts = wp_parse_url( $expected );
        if ( ! is_array( $actual_parts ) || ! is_array( $expected_parts ) || ! isset( $actual_parts['scheme'], $actual_parts['host'], $expected_parts['scheme'], $expected_parts['host'] ) || isset( $actual_parts['user'], $actual_parts['pass'], $actual_parts['fragment'] ) ) {
            return false;
        }
        $actual_scheme   = strtolower( $actual_parts['scheme'] );
        $expected_scheme = strtolower( $expected_parts['scheme'] );
        $local_http      = 'http' === $actual_scheme && 'http' === $expected_scheme && apply_filters( 'mainwp_child_file_deployment_allow_local_http', false, $url );
        if ( ( 'https' !== $actual_scheme || 'https' !== $expected_scheme ) && ! $local_http ) {
            return false;
        }
        $actual_port   = isset( $actual_parts['port'] ) ? (int) $actual_parts['port'] : ( 'https' === $actual_scheme ? 443 : 80 );
        $expected_port = isset( $expected_parts['port'] ) ? (int) $expected_parts['port'] : ( 'https' === $expected_scheme ? 443 : 80 );
        return strtolower( $actual_parts['host'] ) === strtolower( $expected_parts['host'] ) && $actual_port === $expected_port;
    }

    /** Fetch one bounded no-redirect object from the Dashboard gateway. */
    protected function download_gateway_bytes( $url, $token, $expected_bytes ) {
        $response = wp_safe_remote_get(
            $url,
            array(
                'timeout'             => 120,
                'redirection'         => 0,
                'sslverify'           => true,
                'limit_response_size' => min( self::MAX_BYTES + 1, $expected_bytes + 1 ),
                'headers'             => array( 'Authorization' => 'Bearer ' . $token ),
            )
        );
        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            return false;
        }
        $body = wp_remote_retrieve_body( $response );
        return is_string( $body ) && strlen( $body ) <= self::MAX_BYTES ? $body : false;
    }

    /** Atomically deploy bytes after preserving the exact prior target. */
    protected function apply_deployment( $destination_class, $relative_destination, $bytes, $before ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity -- Filesystem transaction with compensation.
        $path = $this->target_path( $destination_class, $relative_destination );
        if ( false === $path || ! $this->ensure_safe_parent( dirname( $path ) ) ) {
            return false;
        }
        $backup_ref = null;
        if ( $before['exists'] ) {
            $root = $this->storage_root( true );
            if ( false === $root ) {
                return false;
            }
            $backup_ref = 'backup-' . wp_generate_password( 32, false, false );
            $backup     = $root . '/' . $backup_ref;
            if ( ! copy( $path, $backup ) || ! chmod( $backup, 0600 ) || ! hash_equals( $before['sha256'], hash_file( 'sha256', $backup ) ) ) {
                return false;
            }
        }
        $temp = dirname( $path ) . '/.mainwp-deploy-' . wp_generate_password( 32, false, false );
        $file = @fopen( $temp, 'x+b' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Exclusive create outcome is checked.
        if ( false === $file ) {
            return false;
        }
        $written = fwrite( $file, $bytes );
        $flushed = fflush( $file );
        fclose( $file );
        if ( strlen( $bytes ) !== $written || ! $flushed || ! chmod( $temp, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 ) || ! rename( $temp, $path ) ) {
            @unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Request-local cleanup.
            return false;
        }
        return array( 'backup_ref' => $backup_ref );
    }

    /** Restore the exact retained prior target state. */
    protected function apply_rollback( $receipt ) {
        $path = $this->target_path( $receipt['destination_class'], $receipt['relative_destination'] );
        if ( false === $path ) {
            return false;
        }
        if ( ! $receipt['prior_exists'] ) {
            return is_file( $path ) && ! is_link( $path ) && unlink( $path ) && ! file_exists( $path );
        }
        $root = $this->storage_root( false );
        if ( false === $root || ! $this->safe_private_ref( $receipt['backup_ref'] ) ) {
            return false;
        }
        $backup = $root . '/' . $receipt['backup_ref'];
        if ( ! is_file( $backup ) || is_link( $backup ) || ! hash_equals( $receipt['prior_sha256'], hash_file( 'sha256', $backup ) ) ) {
            return false;
        }
        $temp = dirname( $path ) . '/.mainwp-rollback-' . wp_generate_password( 32, false, false );
        return copy( $backup, $temp ) && chmod( $temp, $receipt['prior_mode'] ) && rename( $temp, $path );
    }

    /** Read one private receipt. */
    protected function load_receipt( $request_ref ) {
        $root = $this->storage_root( false );
        if ( false === $root ) {
            // A store that was never created genuinely holds no receipts. One that exists but
            // cannot be used is a read failure, and calling that not_found erases a settled deploy.
            return $this->storage_root_absent() ? null : false;
        }
        $path = $root . '/receipt-' . hash( 'sha256', $request_ref ) . '.json';
        if ( ! file_exists( $path ) ) {
            return null;
        }
        if ( is_link( $path ) || ! is_file( $path ) || ! is_readable( $path ) || self::MAX_BYTES < filesize( $path ) ) {
            return false;
        }
        $raw     = file_get_contents( $path );
        $receipt = is_string( $raw ) ? json_decode( $raw, true ) : null;
        return $this->valid_receipt( $receipt ) ? $receipt : false;
    }

    /** Drop receipts past retention and the backups no live receipt still binds. */
    protected function prune_expired_storage() {
        $root = $this->storage_root( false );
        if ( false === $root ) {
            return;
        }
        // Housekeeping never runs beside a restore. A rollback in flight holds the store shared,
        // and this sweep would otherwise judge its backup against a file list taken before that
        // rollback reserved anything. Skipping costs nothing: the next deployment sweeps instead.
        $store = $this->acquire_store_lock( true );
        if ( false === $store ) {
            return;
        }
        try {
            $this->sweep_expired_storage( $root );
        } finally {
            $this->release_store_lock( $store );
        }
    }

    /** Sweep one exact store while it is held exclusively. */
    private function sweep_expired_storage( $root ) {
        $now    = time();
        $bound  = array();
        $stored = glob( $root . '/receipt-*.json' );
        foreach ( is_array( $stored ) ? $stored : array() as $path ) {
            if ( ! is_file( $path ) || is_link( $path ) ) {
                continue;
            }
            $raw     = file_get_contents( $path );
            $receipt = is_string( $raw ) ? json_decode( $raw, true ) : null;
            if ( ! is_array( $receipt ) || ! isset( $receipt['state'], $receipt['expires_at'] ) || ! is_int( $receipt['expires_at'] ) ) {
                continue;
            }
            // An unresolved dispatch marker is kept whatever its age: dropping it is exactly what
            // would let a resent request run the same write a second time.
            if ( 'settled' === $receipt['state'] && $receipt['expires_at'] <= $now ) {
                wp_delete_file( $path );
                continue;
            }
            if ( isset( $receipt['backup_ref'] ) && is_string( $receipt['backup_ref'] ) ) {
                $bound[ $receipt['backup_ref'] ] = true;
            }
        }
        $backups = glob( $root . '/backup-*' );
        foreach ( is_array( $backups ) ? $backups : array() as $path ) {
            $mtime = is_file( $path ) && ! is_link( $path ) ? filemtime( $path ) : false;
            // The receipt is always created before its backup, so a file younger than the
            // retention window may still belong to an effect that is mid-flight right now.
            if ( false !== $mtime && ! isset( $bound[ basename( $path ) ] ) && $mtime + self::RECEIPT_TTL <= $now ) {
                wp_delete_file( $path );
            }
        }
    }

    /** Exclusively reserve one effect. */
    protected function create_receipt( $request_ref, $receipt ) {
        if ( ! $this->valid_receipt( $receipt ) ) {
            return false;
        }
        $root = $this->storage_root( true );
        if ( false === $root ) {
            return false;
        }
        $path = $root . '/receipt-' . hash( 'sha256', $request_ref ) . '.json';
        $file = @fopen( $path, 'x+b' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Exclusive create is required.
        if ( false === $file ) {
            return false;
        }
        $raw     = wp_json_encode( $receipt, JSON_UNESCAPED_SLASHES );
        $written = is_string( $raw ) ? fwrite( $file, $raw ) : false;
        $flushed = fflush( $file );
        fclose( $file );
        chmod( $path, 0600 );
        return is_string( $raw ) && strlen( $raw ) === $written && $flushed && $receipt === $this->load_receipt( $request_ref );
    }

    /** Replace one exact dispatch marker with a terminal result. */
    protected function settle_receipt( $request_ref, $expected, $receipt ) {
        if ( ! $this->valid_receipt( $receipt ) || $expected !== $this->load_receipt( $request_ref ) ) {
            return false;
        }
        $root = $this->storage_root( false );
        if ( false === $root ) {
            return false;
        }
        $path = $root . '/receipt-' . hash( 'sha256', $request_ref ) . '.json';
        $temp = $path . '.tmp-' . wp_generate_password( 16, false, false );
        $raw  = wp_json_encode( $receipt, JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $raw ) || strlen( $raw ) !== file_put_contents( $temp, $raw, LOCK_EX ) || ! chmod( $temp, 0600 ) || ! rename( $temp, $path ) ) {
            @unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Request-local cleanup.
            return false;
        }
        return $receipt === $this->load_receipt( $request_ref );
    }

    /** Acquire one exact destination lane without waiting. */
    protected function acquire_destination_lock( $destination_class, $relative_destination ) {
        $root = $this->storage_root( true );
        if ( false === $root ) {
            return false;
        }
        $path = $root . '/target-' . hash( 'sha256', $destination_class . "\n" . $relative_destination ) . '.lock';
        $file = @fopen( $path, 'c+b' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Lock acquisition outcome is checked.
        if ( false === $file || ! flock( $file, LOCK_EX | LOCK_NB ) ) {
            if ( is_resource( $file ) ) {
                fclose( $file );
            }
            return false;
        }
        return $file;
    }

    /** Release one exact destination lane. */
    protected function release_destination_lock( $lock ) {
        if ( is_resource( $lock ) ) {
            flock( $lock, LOCK_UN );
            fclose( $lock );
        }
    }

    /** Hold the whole store without waiting: exclusively to sweep it, shared to restore from it. */
    protected function acquire_store_lock( $exclusive ) {
        $root = $this->storage_root( false );
        if ( false === $root ) {
            return false;
        }
        $file = @fopen( $root . '/store.lock', 'c+b' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Lock acquisition outcome is checked.
        if ( false === $file || ! flock( $file, ( $exclusive ? LOCK_EX : LOCK_SH ) | LOCK_NB ) ) {
            if ( is_resource( $file ) ) {
                fclose( $file );
            }
            return false;
        }
        return $file;
    }

    /** Release the whole store. */
    protected function release_store_lock( $lock ) {
        if ( is_resource( $lock ) ) {
            flock( $lock, LOCK_UN );
            fclose( $lock );
        }
    }

    /** Read the closed JSON envelope from authenticated POST state. */
    private function request_from_post() {
        // phpcs:disable WordPress.Security.NonceVerification -- MainWP request authentication occurs before callable dispatch.
        $raw = isset( $_POST['request'] ) && is_string( $_POST['request'] ) ? wp_unslash( $_POST['request'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The JSON envelope is decoded then validated field by field.
        // phpcs:enable WordPress.Security.NonceVerification
        if ( '' === $raw || 16384 < strlen( $raw ) ) {
            return null;
        }
        $request = json_decode( $raw, true );
        return is_array( $request ) ? $request : null;
    }

    /** Validate the versioned outer envelope. */
    private function valid_envelope( $request, $operation ) {
        return is_array( $request ) && $this->exact_keys( $request, array( 'protocol', 'operation', 'payload' ) ) && '2' === $request['protocol'] && $operation === $request['operation'] && is_array( $request['payload'] );
    }

    /** Validate one preflight payload. */
    private function valid_preflight_payload( $payload ) {
        return is_array( $payload ) && $this->exact_keys( $payload, array( 'request_ref', 'destination_class', 'relative_destination' ) ) && $this->uuid_ref( $payload['request_ref'] ) && $this->destination_class( $payload['destination_class'] ) && is_string( $payload['relative_destination'] );
    }

    /** Validate one deploy payload. */
    private function valid_deploy_payload( $payload ) {
        return is_array( $payload )
            && $this->exact_keys( $payload, array( 'request_ref', 'object_ref', 'destination_class', 'relative_destination', 'expected_bytes', 'expected_sha256', 'gateway_url', 'gateway_token', 'state_revision', 'expires_at' ) )
            && $this->uuid_ref( $payload['request_ref'] )
            && $this->uuid_ref( $payload['object_ref'] )
            && $this->destination_class( $payload['destination_class'] )
            && is_string( $payload['relative_destination'] )
            && is_int( $payload['expected_bytes'] ) && 0 < $payload['expected_bytes'] && self::MAX_BYTES >= $payload['expected_bytes']
            && $this->hash_ref( $payload['expected_sha256'] )
            && is_string( $payload['gateway_url'] )
            && is_string( $payload['gateway_token'] ) && 16 <= strlen( $payload['gateway_token'] ) && 4096 >= strlen( $payload['gateway_token'] ) && ! preg_match( '/[\x00-\x1F\x7F]/', $payload['gateway_token'] )
            && $this->hash_ref( $payload['state_revision'] )
            && is_int( $payload['expires_at'] ) && time() <= $payload['expires_at'] && time() + 300 >= $payload['expires_at'];
    }

    /** Validate one rollback payload. */
    private function valid_rollback_payload( $payload ) {
        return is_array( $payload ) && $this->exact_keys( $payload, array( 'request_ref', 'deployment_ref', 'if_current_sha256', 'expires_at' ) ) && $this->uuid_ref( $payload['request_ref'] ) && $this->uuid_ref( $payload['deployment_ref'] ) && $this->hash_ref( $payload['if_current_sha256'] ) && is_int( $payload['expires_at'] ) && time() <= $payload['expires_at'] && time() + 300 >= $payload['expires_at'];
    }

    /** Normalize and constrain one relative destination. */
    private function normalize_destination( $destination_class, $relative_destination ) {
        // The backslash is checked outside the character class on purpose: every segment rule below
        // splits on '/' alone, so on Windows, where '\' is also a separator, a segment like
        // '..\..\..\wp-config.php' would read as one innocent name and walk out of the destination.
        if ( ! $this->destination_class( $destination_class ) || ! is_string( $relative_destination ) || '' === $relative_destination || 1024 < strlen( $relative_destination ) || 1 === preg_match( '/[\x00-\x1F\x7F%:]/', $relative_destination ) || false !== strpos( $relative_destination, '\\' ) || '/' === $relative_destination[0] || 32 < substr_count( $relative_destination, '/' ) + 1 ) {
            return false;
        }
        if ( class_exists( '\Normalizer' ) && \Normalizer::normalize( $relative_destination, \Normalizer::FORM_C ) !== $relative_destination ) {
            return false;
        }
        if ( ! class_exists( '\Normalizer' ) && 1 === preg_match( '/[^\x20-\x7E]/', $relative_destination ) ) {
            return false;
        }
        $parts = explode( '/', $relative_destination );
        if ( in_array( '', $parts, true ) || in_array( '.', $parts, true ) || in_array( '..', $parts, true ) ) {
            return false;
        }
        if ( '.phpfile.txt' === substr( $relative_destination, -12 ) ) {
            $relative_destination = substr( $relative_destination, 0, -12 ) . '.php';
        }
        $prefixes = array(
            'uploads'    => 'wp-content/uploads/',
            'languages'  => 'wp-content/languages/',
            'plugins'    => 'wp-content/plugins/',
            'themes'     => 'wp-content/themes/',
            'mu_plugins' => 'wp-content/mu-plugins/',
        );
        return isset( $prefixes[ $destination_class ] ) && 0 === strpos( $relative_destination, $prefixes[ $destination_class ] ) && strlen( $relative_destination ) > strlen( $prefixes[ $destination_class ] ) ? $relative_destination : false;
    }

    /**
     * Refuse server-executed or server-config content in the web-served uploads lane.
     *
     * Only the uploads lane is guarded: the languages lane legitimately receives WP 6.5+
     * performant-translation PHP (`wp-content/languages/**\/*.l10n.php`), and the code lanes
     * (plugins/themes/mu_plugins) deploy PHP by design under their own Dashboard capability gate.
     *
     * The guard matches the common WP-hostable server-executable page classes, not an exhaustive
     * list: the PHP family (as a pattern, because LiteSpeed/cPanel map versioned handler ids like
     * `php81`/`php85` as suffixes), server-side includes, IIS ASP/ASP.NET (WordPress does run on
     * IIS behind PHP-FastCGI), and the server-config drop-ins. Deliberately excluded: JSP and
     * ColdFusion (no WordPress-hostable stack executes them), CGI (`.pl`/`.cgi`/`.py` need
     * `ExecCGI`, rare on an uploads dir, and are legitimate download payloads), and `.cer` (a
     * certificate extension far more often than an IIS handler). The match is by any dot-token,
     * not just the final extension, because a misconfigured `AddHandler`/`AddOutputFilter` selects
     * a handler from any matching token (`shell.php.jpg`), and a trailing dot or space is an
     * IIS/Windows filesystem alias for the bare name. This is defense-in-depth: the File Uploader
     * path is full-admin gated Dashboard-side, so this is not the last line against a determined
     * caller.
     *
     * @param string $destination_class Normalized destination class.
     * @param string $normalized        Normalized relative destination (already `.phpfile.txt`-decoded).
     * @return bool True when the target may be written.
     */
    private function uploads_target_allowed( $destination_class, $normalized ) {
        if ( 'uploads' !== $destination_class ) {
            return true;
        }
        $basename = strtolower( basename( $normalized ) );
        if ( '' === $basename || '.' === substr( $basename, -1 ) || ' ' === substr( $basename, -1 ) ) {
            return false;
        }
        if ( in_array( $basename, array( '.htaccess', '.user.ini', 'web.config' ), true ) ) {
            return false;
        }
        $handlers = array(
            'phtml',
            'phtm',
            'pht',
            'phar',
            'phps',
            'shtml',
            'shtm',
            'stm',
            'asp',
            'aspx',
            'asa',
            'asax',
            'ascx',
            'ashx',
            'asmx',
        );
        foreach ( explode( '.', $basename ) as $token ) {
            if ( in_array( $token, $handlers, true ) || 1 === preg_match( '/^php[0-9]*$/', $token ) ) {
                return false;
            }
        }
        return true;
    }

    /** Return a contained absolute target path. */
    private function target_path( $destination_class, $relative_destination ) {
        $normalized = $this->normalize_destination( $destination_class, $relative_destination );
        if ( false === $normalized ) {
            return false;
        }
        $base_map = array(
            'uploads'    => WP_CONTENT_DIR . '/uploads',
            'languages'  => WP_CONTENT_DIR . '/languages',
            'plugins'    => WP_PLUGIN_DIR,
            'themes'     => WP_CONTENT_DIR . '/themes',
            'mu_plugins' => defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins',
        );
        $prefix   = 'wp-content/' . ( 'mu_plugins' === $destination_class ? 'mu-plugins' : $destination_class ) . '/';
        $suffix   = substr( $normalized, strlen( $prefix ) );
        $path     = rtrim( $base_map[ $destination_class ], '/\\' ) . '/' . $suffix;
        if ( ! $this->safe_parent_chain( $base_map[ $destination_class ], dirname( $path ) ) ) {
            return false;
        }
        return $path;
    }

    /** Verify that no existing path component is a symlink. */
    private function safe_parent_chain( $base, $parent ) {
        $base = rtrim( $base, '/\\' );
        if ( 0 !== strpos( $parent . '/', $base . '/' ) || is_link( $base ) ) {
            return false;
        }
        $relative = ltrim( substr( $parent, strlen( $base ) ), '/\\' );
        $current  = $base;
        // A component that does not exist yet is not a containment problem: apply_deployment()
        // creates it through ensure_safe_parent(), and normalize_destination() has already banned
        // '..', '.', empty segments and backslashes.
        foreach ( '' === $relative ? array() : explode( '/', $relative ) as $part ) {
            $current .= '/' . $part;
            if ( file_exists( $current ) && ( is_link( $current ) || ! is_dir( $current ) ) ) {
                return false;
            }
        }
        return true;
    }

    /** Create safe missing parents and recheck containment. */
    private function ensure_safe_parent( $parent ) {
        if ( ! is_dir( $parent ) && ! wp_mkdir_p( $parent ) ) {
            return false;
        }
        chmod( $parent, defined( 'FS_CHMOD_DIR' ) ? FS_CHMOD_DIR : 0755 );
        return is_dir( $parent ) && ! is_link( $parent );
    }

    /** Build the HMAC state revision. */
    private function state_revision( $destination_class, $relative_destination, $snapshot ) {
        return hash_hmac( 'sha256', wp_json_encode( array( $destination_class, $relative_destination, $snapshot ), JSON_UNESCAPED_SLASHES ), wp_salt( 'auth' ) );
    }

    /** Build the non-secret deployment digest. */
    private function deploy_effect_hash( $payload ) {
        return hash( 'sha256', wp_json_encode( array( $payload['request_ref'], $payload['object_ref'], $payload['destination_class'], $payload['relative_destination'], $payload['expected_bytes'], $payload['expected_sha256'], hash( 'sha256', $payload['gateway_url'] ), hash( 'sha256', $payload['gateway_token'] ), $payload['state_revision'], $payload['expires_at'] ), JSON_UNESCAPED_SLASHES ) );
    }

    /** Build one deployment dispatch marker. */
    private function dispatching_receipt( $payload, $destination, $before, $effect_hash ) {
        return array(
            'effect_hash'          => $effect_hash,
            'kind'                 => 'deploy',
            'request_ref'          => $payload['request_ref'],
            'deployment_ref'       => null,
            'destination_class'    => $payload['destination_class'],
            'relative_destination' => $destination,
            'prior_exists'         => $before['exists'],
            'prior_bytes'          => $before['bytes'],
            'prior_sha256'         => $before['sha256'],
            'prior_mode'           => $before['mode'],
            'backup_ref'           => null,
            'deployed_bytes'       => null,
            'deployed_sha256'      => null,
            'state'                => 'dispatching',
            'result'               => null,
            'updated_at'           => time(),
            'expires_at'           => time() + self::RECEIPT_TTL,
        );
    }

    /** Build one rollback effect digest. */
    private function rollback_effect_hash( $payload, $deployment ) {
        return hash( 'sha256', wp_json_encode( array( $payload, $deployment['effect_hash'] ), JSON_UNESCAPED_SLASHES ) );
    }

    /** Build one rollback dispatch marker. */
    private function rollback_dispatching_receipt( $payload, $deployment, $effect_hash ) {
        $receipt                   = $deployment;
        $receipt['effect_hash']    = $effect_hash;
        $receipt['kind']           = 'rollback';
        $receipt['request_ref']    = $payload['request_ref'];
        $receipt['deployment_ref'] = $payload['deployment_ref'];
        $receipt['state']          = 'dispatching';
        $receipt['result']         = null;
        $receipt['updated_at']     = time();
        $receipt['expires_at']     = time() + self::RECEIPT_TTL;
        return $receipt;
    }

    /** Replay matching settled truth or preserve ambiguous dispatch. */
    private function replay_receipt( $receipt, $effect_hash, $operation ) {
        if ( ! $this->valid_receipt( $receipt ) ) {
            return $this->error( $operation, 'storage_unavailable' );
        }
        if ( $this->receipt_expired( $receipt ) ) {
            return $this->error( $operation, 'receipt_expired' );
        }
        if ( ! hash_equals( $receipt['effect_hash'], $effect_hash ) ) {
            return $this->error( $operation, 'request_conflict' );
        }
        return 'dispatching' === $receipt['state'] ? $this->unknown_result( $receipt ) : $receipt['result'];
    }

    /** Persist one failure. */
    private function settle_failure( $dispatching, $operation, $code, $status = 'failed' ) {
        $result = $this->result( $operation, $dispatching['request_ref'], $status, null, null, false, $code );
        return $this->settle_result( $dispatching, $result );
    }

    /** Persist one terminal result with exact-read reconciliation. */
    private function settle_result( $dispatching, $result, $settled_base = null ) {
        $settled               = is_array( $settled_base ) ? $settled_base : $dispatching;
        $settled['state']      = 'settled';
        $settled['result']     = $result;
        $settled['updated_at'] = time();
        if ( $this->settle_receipt( $dispatching['request_ref'], $dispatching, $settled ) ) {
            return $result;
        }
        $stored = $this->load_receipt( $dispatching['request_ref'] );
        return is_array( $stored ) && $stored === $settled ? $result : $this->unknown_result( $dispatching );
    }

    /** Build one public operation result. */
    private function result( $operation, $request_ref, $status, $bytes, $sha256, $rollback_available, $code ) {
        return array(
            'protocol'           => '2',
            'operation'          => $operation,
            'ok'                 => in_array( $status, array( 'completed', 'rolled_back' ), true ),
            'request_ref'        => $request_ref,
            'status'             => $status,
            'bytes'              => $bytes,
            'sha256'             => $sha256,
            'rollback_available' => $rollback_available,
            'code'               => $code,
        );
    }

    /** Return the only truthful result for a reserved uncertain effect. */
    private function unknown_result( $receipt ) {
        return $this->result( $receipt['kind'], $receipt['request_ref'], 'unknown', $receipt['deployed_bytes'], $receipt['deployed_sha256'], 'deploy' === $receipt['kind'], 'outcome_unknown' );
    }

    /** Report whether one stored result has passed its retention deadline. */
    private function receipt_expired( $receipt ) {
        // A dispatch marker never expires into silence: its effect is still unresolved, so the
        // only truthful answer stays outcome_unknown no matter how old the marker is.
        return is_array( $receipt ) && isset( $receipt['state'], $receipt['expires_at'] ) && 'settled' === $receipt['state'] && is_int( $receipt['expires_at'] ) && $receipt['expires_at'] <= time();
    }

    /** Validate a stored private receipt. */
    private function valid_receipt( $receipt ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity -- Closed relational storage validation.
        $keys = array( 'effect_hash', 'kind', 'request_ref', 'deployment_ref', 'destination_class', 'relative_destination', 'prior_exists', 'prior_bytes', 'prior_sha256', 'prior_mode', 'backup_ref', 'deployed_bytes', 'deployed_sha256', 'state', 'result', 'updated_at', 'expires_at' );
        if ( ! is_array( $receipt ) || ! $this->exact_keys( $receipt, $keys ) || ! $this->hash_ref( $receipt['effect_hash'] ) || ! in_array( $receipt['kind'], array( 'deploy', 'rollback' ), true ) || ! $this->uuid_ref( $receipt['request_ref'] ) || ( null !== $receipt['deployment_ref'] && ! $this->uuid_ref( $receipt['deployment_ref'] ) ) || ! $this->destination_class( $receipt['destination_class'] ) || false === $this->normalize_destination( $receipt['destination_class'], $receipt['relative_destination'] ) || ! is_bool( $receipt['prior_exists'] ) || ( null !== $receipt['prior_bytes'] && ( ! is_int( $receipt['prior_bytes'] ) || 0 > $receipt['prior_bytes'] || self::MAX_BYTES < $receipt['prior_bytes'] ) ) || ( null !== $receipt['prior_sha256'] && ! $this->hash_ref( $receipt['prior_sha256'] ) ) || ( null !== $receipt['prior_mode'] && ( ! is_int( $receipt['prior_mode'] ) || 0 > $receipt['prior_mode'] || 0777 < $receipt['prior_mode'] ) ) || ( null !== $receipt['backup_ref'] && ! $this->safe_private_ref( $receipt['backup_ref'] ) ) || ( null !== $receipt['deployed_bytes'] && ( ! is_int( $receipt['deployed_bytes'] ) || 0 > $receipt['deployed_bytes'] || self::MAX_BYTES < $receipt['deployed_bytes'] ) ) || ( null !== $receipt['deployed_sha256'] && ! $this->hash_ref( $receipt['deployed_sha256'] ) ) || ! in_array( $receipt['state'], array( 'dispatching', 'settled' ), true ) || ! is_int( $receipt['updated_at'] ) || 0 >= $receipt['updated_at'] || ! is_int( $receipt['expires_at'] ) || $receipt['expires_at'] < $receipt['updated_at'] ) {
            return false;
        }
        if ( ( null !== $receipt['prior_bytes'] && null !== $receipt['prior_sha256'] && null !== $receipt['prior_mode'] ) !== $receipt['prior_exists'] ) {
            return false;
        }
        return 'dispatching' === $receipt['state'] ? null === $receipt['result'] : $this->valid_result( $receipt['result'], $receipt['request_ref'], $receipt['kind'] );
    }

    /** Validate one public operation result. */
    private function valid_result( $result, $request_ref, $kind ) {
        if ( ! is_array( $result ) || ! $this->exact_keys( $result, array( 'protocol', 'operation', 'ok', 'request_ref', 'status', 'bytes', 'sha256', 'rollback_available', 'code' ) ) || '2' !== $result['protocol'] || $kind !== $result['operation'] || ! is_bool( $result['ok'] ) || $request_ref !== $result['request_ref'] || ! in_array( $result['status'], array( 'completed', 'failed', 'unknown', 'rolled_back' ), true ) || ( null !== $result['bytes'] && ( ! is_int( $result['bytes'] ) || 0 > $result['bytes'] || self::MAX_BYTES < $result['bytes'] ) ) || ( null !== $result['sha256'] && ! $this->hash_ref( $result['sha256'] ) ) || ! is_bool( $result['rollback_available'] ) || ( null !== $result['code'] && ( ! is_string( $result['code'] ) || 1 !== preg_match( '/^[a-z0-9_]{1,64}$/D', $result['code'] ) ) ) ) {
            return false;
        }
        return in_array( $result['status'], array( 'completed', 'rolled_back' ), true ) === $result['ok'] && ( $result['ok'] ? null === $result['code'] : null !== $result['code'] );
    }

    /** Validate one target snapshot. */
    private function valid_target_snapshot( $snapshot ) {
        if ( ! is_array( $snapshot ) || ! $this->exact_keys( $snapshot, array( 'exists', 'bytes', 'sha256', 'mode', 'writable', 'rollback_available' ) ) || ! is_bool( $snapshot['exists'] ) || ! is_bool( $snapshot['writable'] ) || ! is_bool( $snapshot['rollback_available'] ) ) {
            return false;
        }
        if ( ! $snapshot['exists'] ) {
            return null === $snapshot['bytes'] && null === $snapshot['sha256'] && null === $snapshot['mode'];
        }
        return is_int( $snapshot['bytes'] ) && 0 <= $snapshot['bytes'] && self::MAX_BYTES >= $snapshot['bytes'] && $this->hash_ref( $snapshot['sha256'] ) && is_int( $snapshot['mode'] ) && 0 <= $snapshot['mode'] && 0777 >= $snapshot['mode'];
    }

    /** Return the protected storage root path without touching the filesystem. */
    private function storage_root_path() {
        if ( ! defined( 'ABSPATH' ) ) {
            return false;
        }
        $wordpress_root = rtrim( wp_normalize_path( ABSPATH ), '/' );
        $private_base   = dirname( $wordpress_root ) . '/.mainwp-child-private';
        $document_root  = isset( $_SERVER['DOCUMENT_ROOT'] ) && is_string( $_SERVER['DOCUMENT_ROOT'] ) ? realpath( sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) ) ) : false;
        if ( is_string( $document_root ) && 0 === strpos( wp_normalize_path( $private_base ) . '/', rtrim( wp_normalize_path( $document_root ), '/' ) . '/' ) ) {
            return false;
        }
        return $private_base . '/file-deployments-' . substr( hash( 'sha256', $wordpress_root ), 0, 16 );
    }

    /** Report whether the protected storage root has never been created. */
    protected function storage_root_absent() {
        $root = $this->storage_root_path();
        return false !== $root && ! file_exists( $root ) && ! is_link( $root );
    }

    /** Report whether a pre-deployment copy of the target could actually be retained. */
    protected function backup_storage_available() {
        $root = $this->storage_root_path();
        if ( false === $root ) {
            return false;
        }
        if ( file_exists( $root ) || is_link( $root ) ) {
            return is_dir( $root ) && ! is_link( $root ) && is_writable( $root );
        }
        $ancestor = dirname( $root );
        while ( ! file_exists( $ancestor ) && dirname( $ancestor ) !== $ancestor ) {
            $ancestor = dirname( $ancestor );
        }
        return is_dir( $ancestor ) && ! is_link( $ancestor ) && is_writable( $ancestor );
    }

    /** Return/create the protected storage root. */
    protected function storage_root( $create ) {
        $root = $this->storage_root_path();
        if ( false === $root ) {
            return false;
        }
        if ( ! is_dir( $root ) && ( ! $create || ! wp_mkdir_p( $root ) ) ) {
            return false;
        }
        if ( is_link( $root ) || ( is_dir( $root ) && ! chmod( $root, 0750 ) ) ) {
            return false;
        }
        if ( $create ) {
            $deny = "Deny from all\n";
            if ( strlen( $deny ) !== file_put_contents( $root . '/.htaccess', $deny, LOCK_EX ) || 0 !== file_put_contents( $root . '/index.php', '', LOCK_EX ) ) {
                return false;
            }
        }
        return $root;
    }

    /** Validate destination enum. */
    private function destination_class( $value ) {
        return is_string( $value ) && in_array( $value, array( 'uploads', 'languages', 'plugins', 'themes', 'mu_plugins' ), true );
    }

    /** Validate UUID reference. */
    private function uuid_ref( $value ) {
        return is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value );
    }

    /** Validate SHA-256 digest. */
    private function hash_ref( $value ) {
        return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $value );
    }

    /** Validate one private basename reference. */
    private function safe_private_ref( $value ) {
        return is_string( $value ) && 1 === preg_match( '/^[A-Za-z0-9_-]{1,96}$/D', $value );
    }

    /** Check exact associative keys independent of input order. */
    private function exact_keys( $value, $keys ) {
        if ( ! is_array( $value ) ) {
            return false;
        }
        $actual = array_keys( $value );
        sort( $actual );
        sort( $keys );
        return $actual === $keys;
    }

    /** Build one stable closed error. */
    private function error( $operation, $code ) {
        return array(
            'protocol'  => '2',
            'operation' => $operation,
            'ok'        => false,
            'code'      => $code,
        );
    }
}
