<?php
/**
 * MainWP Virusdie verified Child artifact protocol.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag -- Closed protocol validators document the private helper contracts.
// phpcs:disable WordPress.WP.AlternativeFunctions -- Exclusive file creation and hard-link publication provide the required no-overwrite semantics.

/**
 * Install or remove one exact hash-bound Virusdie sync file.
 */
class MainWP_Child_Virusdie {

    /** Maximum signed sync-file bytes. */
    const MAX_ARTIFACT_BYTES = 262144;

    /** Receipt retention. */
    const RECEIPT_TTL = 86400;

    /** Receipt option key prefix. */
    const RECEIPT_PREFIX = 'mainwp_child_virusdie_v1_';

    /** Index of the receipt rows this class wrote, deliberately outside RECEIPT_PREFIX so it can never name itself. */
    const RECEIPT_INDEX_OPTION = 'mainwp_child_virusdie_receipt_index';

    /** Receipt keys the index tracks at once. */
    const RECEIPT_INDEX_CAP = 200;

    /** Indexed rows one mutation may reclaim. */
    const RECEIPT_SWEEP_LIMIT = 5;

    /** Dispatch one decoded v1 request. */
    public function request_v1( $request ) {
        $operation = is_array( $request ) && isset( $request['operation'] ) && is_string( $request['operation'] ) ? $request['operation'] : 'unknown';
        if ( ! is_array( $request ) || ! $this->exact_keys( $request, array( 'protocol', 'operation', 'payload' ) ) || '1' !== $request['protocol'] || ! is_array( $request['payload'] ) ) {
            return $this->error( 'unknown', 'invalid_request' );
        }
        if ( 'capabilities' === $operation ) {
            return array() === $request['payload'] ? $this->capabilities() : $this->error( $operation, 'invalid_request' );
        }
        if ( 'status' === $operation ) {
            return $this->status( $request['payload'] );
        }
        if ( 'install' === $operation ) {
            return $this->install( $request );
        }
        if ( 'remove' === $operation ) {
            return $this->remove( $request );
        }
        return $this->error( $operation, 'unsupported_operation' );
    }

    /** Build a dispatch marker for the response-loss fixture. */
    protected function dispatching_receipt_for_test( $request ) {
        return $this->dispatching_receipt( $request['operation'], $request['payload'], $this->effect_hash( $request['operation'], $request['payload'] ) );
    }

    /** Read one exact target snapshot. */
    protected function target_snapshot( $basename ) {
        $path = $this->target_path( $basename );
        if ( false === $path ) {
            return false;
        }
        if ( ! file_exists( $path ) ) {
            return array(
                'exists' => false,
                'bytes'  => null,
                'sha256' => null,
            );
        }
        $stat = lstat( $path );
        if ( ! is_array( $stat ) || is_link( $path ) || ! is_file( $path ) || ! is_readable( $path ) || 1 !== (int) $stat['nlink'] ) {
            return false;
        }
        $bytes  = filesize( $path );
        $digest = hash_file( 'sha256', $path );
        if ( false === $bytes || false === $digest || self::MAX_ARTIFACT_BYTES < $bytes ) {
            return false;
        }
        return array(
            'exists' => true,
            'bytes'  => (int) $bytes,
            'sha256' => $digest,
        );
    }

    /** Verify the private gateway uses the authenticated Dashboard origin. */
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
        $local_http      = 'http' === $actual_scheme && 'http' === $expected_scheme && apply_filters( 'mainwp_child_virusdie_allow_local_http', false, $url );
        if ( ( 'https' !== $actual_scheme || 'https' !== $expected_scheme ) && ! $local_http ) {
            return false;
        }
        $actual_port   = isset( $actual_parts['port'] ) ? (int) $actual_parts['port'] : ( 'https' === $actual_scheme ? 443 : 80 );
        $expected_port = isset( $expected_parts['port'] ) ? (int) $expected_parts['port'] : ( 'https' === $expected_scheme ? 443 : 80 );
        return strtolower( $actual_parts['host'] ) === strtolower( $expected_parts['host'] ) && $actual_port === $expected_port;
    }

    /** Fetch one bounded no-redirect artifact. */
    protected function download_artifact( $url, $token, $expected_bytes ) {
        $response = wp_safe_remote_get(
            $url,
            array(
                'timeout'             => 60,
                'redirection'         => 0,
                'sslverify'           => true,
                'limit_response_size' => min( self::MAX_ARTIFACT_BYTES + 1, $expected_bytes + 1 ),
                'headers'             => array( 'Authorization' => 'Bearer ' . $token ),
            )
        );
        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            return false;
        }
        $body = wp_remote_retrieve_body( $response );
        return is_string( $body ) && self::MAX_ARTIFACT_BYTES >= strlen( $body ) ? $body : false;
    }

    /** Publish a verified artifact without overwriting an existing target. */
    protected function install_artifact( $basename, $bytes ) {
        $target = $this->target_path( $basename );
        if ( false === $target || file_exists( $target ) || is_link( $target ) ) {
            return false;
        }
        // The staged copy sits in the web root, and a fatal before either unlink below leaves an
        // orphan nothing ever cleans up. An extensionless file is served verbatim by common server
        // configurations, while a .php one is executed and discloses nothing.
        $temp = ABSPATH . '.mainwp-virusdie-' . wp_generate_password( 32, false, false ) . '.php';
        $file = @fopen( $temp, 'x+b' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Exclusive creation is mandatory.
        if ( false === $file ) {
            return false;
        }
        $written = $this->write_all( $file, $bytes );
        $flushed = fflush( $file );
        fclose( $file );
        if ( ! $written || ! $flushed || ! chmod( $temp, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 ) || ! hash_equals( hash( 'sha256', $bytes ), hash_file( 'sha256', $temp ) ) || ! link( $temp, $target ) ) {
            @unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Request-local cleanup.
            return false;
        }
        $removed = unlink( $temp );
        $stat    = lstat( $target );
        return $removed && is_array( $stat ) && 1 === (int) $stat['nlink'];
    }

    /** Remove one already hash-verified target. */
    protected function remove_artifact( $basename ) {
        $target = $this->target_path( $basename );
        if ( false === $target || ! is_file( $target ) || is_link( $target ) ) {
            return false;
        }
        $stat = lstat( $target );
        return is_array( $stat ) && 1 === (int) $stat['nlink'] && unlink( $target ) && ! file_exists( $target );
    }

    /** Read one private receipt option. */
    protected function load_receipt( $request_ref ) {
        $value = get_option( $this->receipt_key( $request_ref ), null );
        if ( null === $value ) {
            return null;
        }
        return $this->valid_receipt( $value ) ? $value : false;
    }

    /** Report whether a stored receipt is past its retention window. */
    private function receipt_expired( $receipt ) {
        return is_array( $receipt ) && isset( $receipt['expires_at'] ) && is_int( $receipt['expires_at'] ) && $receipt['expires_at'] <= time();
    }

    /** Drop one receipt a mutation has proven past retention. */
    protected function delete_receipt( $request_ref ) {
        return delete_option( $this->receipt_key( $request_ref ) );
    }

    /** Take over one request reference whose receipt has expired, so nothing stale is replayed. */
    private function evict_expired_receipt( $request_ref, $existing ) {
        if ( ! is_array( $existing ) || ! $this->receipt_expired( $existing ) ) {
            return $existing;
        }
        $this->delete_receipt( $request_ref );
        return null;
    }

    /** Reserve a request exactly once. */
    protected function create_receipt( $request_ref, $receipt ) {
        if ( ! $this->valid_receipt( $receipt ) ) {
            return false;
        }
        $this->sweep_retained_receipts();
        if ( ! add_option( $this->receipt_key( $request_ref ), $receipt, '', false ) || $receipt !== $this->load_receipt( $request_ref ) ) {
            return false;
        }
        $this->record_retained_receipt( $this->receipt_key( $request_ref ), $receipt['expires_at'] );
        return true;
    }

    /**
     * Reclaim a few rows nobody came back for.
     *
     * A request reference is one-shot, so the eviction on the mutation path above only ever reaches
     * the reference the current request names; every other row sits there until the site is torn
     * down. Finding them means either a prefix scan of wp_options, which is unindexed and would run
     * on the hot mutation path, or an index of the keys this class wrote.
     *
     * This may not change the request's answer, and it runs before the reservation exists so that a
     * fatal in here leaves nothing reserved: a request that died holding a marker it can never
     * settle would poison the same index for every request after it. The sweep deletes only rows
     * this class's own predicate has already judged forgettable - the same judgement the eviction
     * above makes, on rows whose retention ran out without a Dashboard ever asking again.
     */
    private function sweep_retained_receipts() {
        $index = new MainWP_Child_Receipt_Index( self::RECEIPT_PREFIX );
        $index->sweep(
            self::RECEIPT_INDEX_OPTION,
            self::RECEIPT_SWEEP_LIMIT,
            function ( $value ) {
                return $this->valid_receipt( $value ) && $this->receipt_expired( $value );
            }
        );
    }

    /**
     * Record the row this request just wrote, so a later mutation can find it.
     *
     * Recording happens after the write because only then is the key known to name a row. The index
     * is advisory and its failure is ignored: a mutation that has already reserved its effect is
     * never refused over bookkeeping.
     *
     * @param string $option_key Receipt key this request wrote.
     * @param int    $expires_at Retention moment stored on it.
     */
    private function record_retained_receipt( $option_key, $expires_at ) {
        $index = new MainWP_Child_Receipt_Index( self::RECEIPT_PREFIX );
        $index->add( self::RECEIPT_INDEX_OPTION, $option_key, $expires_at, self::RECEIPT_INDEX_CAP );
    }

    /** Replace an exact dispatch marker with terminal truth. */
    protected function settle_receipt( $request_ref, $expected, $receipt ) {
        if ( ! $this->valid_receipt( $receipt ) || $expected !== $this->load_receipt( $request_ref ) ) {
            return false;
        }
        update_option( $this->receipt_key( $request_ref ), $receipt, false );
        return $receipt === $this->load_receipt( $request_ref );
    }

    /** Install one verified artifact. */
    private function install( $request ) {
        $payload = $request['payload'];
        if ( ! $this->valid_install_payload( $payload ) ) {
            return $this->error( 'install', 'invalid_request' );
        }
        if ( ! $this->gateway_allowed( $payload['gateway_url'] ) ) {
            return $this->error( 'install', 'gateway_rejected' );
        }
        $effect_hash = $this->effect_hash( 'install', $payload );
        $existing    = $this->load_receipt( $payload['request_ref'] );
        if ( false === $existing ) {
            return $this->error( 'install', 'storage_unavailable' );
        }
        // Only this mutation path evicts: status is a read and must never write.
        $existing = $this->evict_expired_receipt( $payload['request_ref'], $existing );
        if ( is_array( $existing ) ) {
            return $this->replay( $existing, $effect_hash );
        }
        $before = $this->target_snapshot( $payload['basename'] );
        if ( ! $this->valid_snapshot( $before ) ) {
            return $this->error( 'install', 'storage_unavailable' );
        }
        if ( $before['exists'] ) {
            return $this->error( 'install', 'target_exists' );
        }
        $dispatching = $this->dispatching_receipt( 'install', $payload, $effect_hash );
        if ( ! $this->create_receipt( $payload['request_ref'], $dispatching ) ) {
            $existing = $this->load_receipt( $payload['request_ref'] );
            return is_array( $existing ) ? $this->replay( $existing, $effect_hash ) : $this->error( 'install', 'storage_unavailable' );
        }
        $bytes = $this->download_artifact( $payload['gateway_url'], $payload['gateway_token'], $payload['expected_bytes'] );
        if ( ! is_string( $bytes ) || strlen( $bytes ) !== $payload['expected_bytes'] || ! hash_equals( $payload['expected_sha256'], hash( 'sha256', is_string( $bytes ) ? $bytes : '' ) ) ) {
            return $this->settle_failure( $dispatching, 'digest_mismatch', $this->target_snapshot( $payload['basename'] ) );
        }
        $fresh = $this->target_snapshot( $payload['basename'] );
        if ( ! $this->valid_snapshot( $fresh ) || $fresh !== $before ) {
            return $this->settle_failure( $dispatching, 'stale_target', $fresh );
        }
        if ( true !== $this->install_artifact( $payload['basename'], $bytes ) ) {
            return $this->settle_failure( $dispatching, 'outcome_unknown', $this->target_snapshot( $payload['basename'] ), 'unknown' );
        }
        $after = $this->target_snapshot( $payload['basename'] );
        if ( ! $this->valid_snapshot( $after ) || ! $after['exists'] || $payload['expected_bytes'] !== $after['bytes'] || ! hash_equals( $payload['expected_sha256'], $after['sha256'] ) ) {
            return $this->settle_failure( $dispatching, 'outcome_unknown', $after, 'unknown' );
        }
        return $this->settle_result( $dispatching, $this->result( 'install', $payload['request_ref'], 'completed', true, $after['bytes'], $after['sha256'], null ) );
    }

    /** Remove one exact installed artifact. */
    private function remove( $request ) {
        $payload = $request['payload'];
        if ( ! $this->valid_remove_payload( $payload ) ) {
            return $this->error( 'remove', 'invalid_request' );
        }
        $effect_hash = $this->effect_hash( 'remove', $payload );
        $existing    = $this->load_receipt( $payload['request_ref'] );
        if ( false === $existing ) {
            return $this->error( 'remove', 'storage_unavailable' );
        }
        // Only this mutation path evicts: status is a read and must never write.
        $existing = $this->evict_expired_receipt( $payload['request_ref'], $existing );
        if ( is_array( $existing ) ) {
            return $this->replay( $existing, $effect_hash );
        }
        $before = $this->target_snapshot( $payload['basename'] );
        if ( ! $this->valid_snapshot( $before ) ) {
            return $this->error( 'remove', 'storage_unavailable' );
        }
        if ( ! $before['exists'] || ! hash_equals( $payload['expected_sha256'], $before['sha256'] ) ) {
            return $this->error( 'remove', 'stale_target' );
        }
        $dispatching = $this->dispatching_receipt( 'remove', $payload, $effect_hash );
        if ( ! $this->create_receipt( $payload['request_ref'], $dispatching ) ) {
            $existing = $this->load_receipt( $payload['request_ref'] );
            return is_array( $existing ) ? $this->replay( $existing, $effect_hash ) : $this->error( 'remove', 'storage_unavailable' );
        }
        $fresh = $this->target_snapshot( $payload['basename'] );
        if ( ! $this->valid_snapshot( $fresh ) || $fresh !== $before ) {
            return $this->settle_failure( $dispatching, 'stale_target', $fresh );
        }
        if ( true !== $this->remove_artifact( $payload['basename'] ) ) {
            return $this->settle_failure( $dispatching, 'outcome_unknown', $this->target_snapshot( $payload['basename'] ), 'unknown' );
        }
        $after = $this->target_snapshot( $payload['basename'] );
        if ( ! $this->valid_snapshot( $after ) || $after['exists'] ) {
            return $this->settle_failure( $dispatching, 'outcome_unknown', $after, 'unknown' );
        }
        return $this->settle_result( $dispatching, $this->result( 'remove', $payload['request_ref'], 'completed', false, null, null, null ) );
    }

    /** Read one durable operation state. */
    private function status( $payload ) {
        if ( ! is_array( $payload ) || ! $this->exact_keys( $payload, array( 'request_ref' ) ) || ! $this->uuid_ref( $payload['request_ref'] ) ) {
            return $this->error( 'status', 'invalid_request' );
        }
        $receipt = $this->load_receipt( $payload['request_ref'] );
        if ( false === $receipt ) {
            return $this->error( 'status', 'storage_unavailable' );
        }
        if ( ! is_array( $receipt ) || $this->receipt_expired( $receipt ) ) {
            return $this->error( 'status', 'not_found' );
        }
        return 'dispatching' === $receipt['state'] ? $this->unknown_result( $receipt ) : $receipt['result'];
    }

    /** Advertise the executable protocol. */
    private function capabilities() {
        return array(
            'protocol'           => '1',
            'operation'          => 'capabilities',
            'ok'                 => true,
            'operations'         => array( 'install', 'status', 'remove' ),
            'max_artifact_bytes' => self::MAX_ARTIFACT_BYTES,
        );
    }

    /** Build a secret-free effect digest. */
    private function effect_hash( $operation, $payload ) {
        $bound = $payload;
        if ( isset( $bound['gateway_url'] ) ) {
            $bound['gateway_url'] = hash( 'sha256', $bound['gateway_url'] );
        }
        if ( isset( $bound['gateway_token'] ) ) {
            $bound['gateway_token'] = hash( 'sha256', $bound['gateway_token'] );
        }
        return hash( 'sha256', wp_json_encode( array( $operation, $bound ), JSON_UNESCAPED_SLASHES ) );
    }

    /** Build one dispatch marker without private URL or token data. */
    private function dispatching_receipt( $operation, $payload, $effect_hash ) {
        return array(
            'effect_hash'     => $effect_hash,
            'operation'       => $operation,
            'request_ref'     => $payload['request_ref'],
            'basename'        => $payload['basename'],
            'expected_sha256' => $payload['expected_sha256'],
            'site_generation' => $payload['site_generation'],
            'state'           => 'dispatching',
            'result'          => null,
            'updated_at'      => time(),
            'expires_at'      => time() + self::RECEIPT_TTL,
        );
    }

    /** Replay the exact request or reject a changed effect. */
    private function replay( $receipt, $effect_hash ) {
        if ( ! $this->valid_receipt( $receipt ) ) {
            return $this->error( 'unknown', 'storage_unavailable' );
        }
        if ( ! hash_equals( $receipt['effect_hash'], $effect_hash ) ) {
            return $this->error( $receipt['operation'], 'request_conflict' );
        }
        return 'dispatching' === $receipt['state'] ? $this->unknown_result( $receipt ) : $receipt['result'];
    }

    /** Persist one failure carrying the observed target state. */
    private function settle_failure( $dispatching, $code, $snapshot, $status = 'failed' ) {
        return $this->settle_result( $dispatching, $this->result( $dispatching['operation'], $dispatching['request_ref'], $status, $this->observed_installed( $snapshot ), null, null, $code ) );
    }

    /** Report what the target actually is; null when it cannot be read. */
    private function observed_installed( $snapshot ) {
        return $this->valid_snapshot( $snapshot ) ? $snapshot['exists'] : null;
    }

    /** Persist one terminal result with exact-read reconciliation. */
    private function settle_result( $dispatching, $result ) {
        $settled               = $dispatching;
        $settled['state']      = 'settled';
        $settled['result']     = $result;
        $settled['updated_at'] = time();
        if ( $this->settle_receipt( $dispatching['request_ref'], $dispatching, $settled ) ) {
            return $result;
        }
        $stored = $this->load_receipt( $dispatching['request_ref'] );
        return is_array( $stored ) && $stored === $settled ? $result : $this->unknown_result( $dispatching );
    }

    /** Build one closed terminal result. */
    private function result( $operation, $request_ref, $status, $installed, $bytes, $sha256, $code ) {
        return array(
            'protocol'    => '1',
            'operation'   => $operation,
            'ok'          => 'completed' === $status,
            'request_ref' => $request_ref,
            'status'      => $status,
            'installed'   => $installed,
            'bytes'       => $bytes,
            'sha256'      => $sha256,
            'code'        => $code,
        );
    }

    /** Return truthful unknown state for a reserved effect. */
    private function unknown_result( $receipt ) {
        return $this->result( $receipt['operation'], $receipt['request_ref'], 'unknown', $this->observed_installed( $this->target_snapshot( $receipt['basename'] ) ), null, null, 'outcome_unknown' );
    }

    /** Validate install input. */
    private function valid_install_payload( $payload ) {
        return is_array( $payload )
            && $this->exact_keys( $payload, array( 'request_ref', 'basename', 'expected_bytes', 'expected_sha256', 'gateway_url', 'gateway_token', 'site_generation', 'expires_at' ) )
            && $this->uuid_ref( $payload['request_ref'] )
            && $this->valid_basename( $payload['basename'] )
            && is_int( $payload['expected_bytes'] ) && 0 < $payload['expected_bytes'] && self::MAX_ARTIFACT_BYTES >= $payload['expected_bytes']
            && $this->hash_ref( $payload['expected_sha256'] )
            && is_string( $payload['gateway_url'] )
            && is_string( $payload['gateway_token'] ) && 16 <= strlen( $payload['gateway_token'] ) && 4096 >= strlen( $payload['gateway_token'] ) && ! preg_match( '/[\x00-\x1F\x7F]/', $payload['gateway_token'] )
            && $this->hash_ref( $payload['site_generation'] )
            && is_int( $payload['expires_at'] ) && time() <= $payload['expires_at'] && time() + 300 >= $payload['expires_at'];
    }

    /** Validate removal input. */
    private function valid_remove_payload( $payload ) {
        return is_array( $payload )
            && $this->exact_keys( $payload, array( 'request_ref', 'basename', 'expected_sha256', 'site_generation' ) )
            && $this->uuid_ref( $payload['request_ref'] )
            && $this->valid_basename( $payload['basename'] )
            && $this->hash_ref( $payload['expected_sha256'] )
            && $this->hash_ref( $payload['site_generation'] );
    }

    /** Validate a target snapshot. */
    private function valid_snapshot( $snapshot ) {
        return is_array( $snapshot )
            && $this->exact_keys( $snapshot, array( 'exists', 'bytes', 'sha256' ) )
            && is_bool( $snapshot['exists'] )
            && ( $snapshot['exists']
                ? is_int( $snapshot['bytes'] ) && 0 <= $snapshot['bytes'] && self::MAX_ARTIFACT_BYTES >= $snapshot['bytes'] && $this->hash_ref( $snapshot['sha256'] )
                : null === $snapshot['bytes'] && null === $snapshot['sha256'] );
    }

    /** Validate one stored receipt. */
    private function valid_receipt( $receipt ) {
        if ( ! is_array( $receipt ) || ! $this->exact_keys( $receipt, array( 'effect_hash', 'operation', 'request_ref', 'basename', 'expected_sha256', 'site_generation', 'state', 'result', 'updated_at', 'expires_at' ) ) || ! $this->hash_ref( $receipt['effect_hash'] ) || ! in_array( $receipt['operation'], array( 'install', 'remove' ), true ) || ! $this->uuid_ref( $receipt['request_ref'] ) || ! $this->valid_basename( $receipt['basename'] ) || ! $this->hash_ref( $receipt['expected_sha256'] ) || ! $this->hash_ref( $receipt['site_generation'] ) || ! in_array( $receipt['state'], array( 'dispatching', 'settled' ), true ) || ! is_int( $receipt['updated_at'] ) || 0 >= $receipt['updated_at'] || ! is_int( $receipt['expires_at'] ) || $receipt['updated_at'] > $receipt['expires_at'] ) {
            return false;
        }
        return 'dispatching' === $receipt['state'] ? null === $receipt['result'] : $this->valid_result( $receipt['result'], $receipt['operation'], $receipt['request_ref'] );
    }

    /** Validate one stored public result. */
    private function valid_result( $result, $operation, $request_ref ) {
        if ( ! is_array( $result ) || ! $this->exact_keys( $result, array( 'protocol', 'operation', 'ok', 'request_ref', 'status', 'installed', 'bytes', 'sha256', 'code' ) ) || '1' !== $result['protocol'] || $operation !== $result['operation'] || ! is_bool( $result['ok'] ) || $request_ref !== $result['request_ref'] || ! in_array( $result['status'], array( 'completed', 'failed', 'unknown' ), true ) || ( 'completed' === $result['status'] ? ! is_bool( $result['installed'] ) : ( null !== $result['installed'] && ! is_bool( $result['installed'] ) ) ) || ( null !== $result['bytes'] && ( ! is_int( $result['bytes'] ) || 0 > $result['bytes'] || self::MAX_ARTIFACT_BYTES < $result['bytes'] ) ) || ( null !== $result['sha256'] && ! $this->hash_ref( $result['sha256'] ) ) || ( null !== $result['code'] && ( ! is_string( $result['code'] ) || 1 !== preg_match( '/^[a-z0-9_]{1,64}$/D', $result['code'] ) ) ) ) {
            return false;
        }
        return ( 'completed' === $result['status'] ) === $result['ok'] && ( $result['ok'] ? null === $result['code'] : null !== $result['code'] );
    }

    /** Build an option-safe receipt key. */
    private function receipt_key( $request_ref ) {
        return self::RECEIPT_PREFIX . hash( 'sha256', $request_ref );
    }

    /** Build the exact target path. */
    private function target_path( $basename ) {
        return $this->valid_basename( $basename ) && defined( 'ABSPATH' ) && is_dir( ABSPATH ) && ! is_link( ABSPATH ) ? trailingslashit( ABSPATH ) . $basename : false;
    }

    /** Validate the provider-manifest basename without accepting paths. */
    private function valid_basename( $value ) {
        return is_string( $value ) && 1 === preg_match( '/^[A-Za-z0-9_-]{1,96}\.php$/D', $value ) && basename( $value ) === $value && 0 === validate_file( $value );
    }

    /** Write all bytes to an exclusive file handle. */
    private function write_all( $file, $bytes ) {
        $offset = 0;
        $length = strlen( $bytes );
        while ( $offset < $length ) {
            $written = fwrite( $file, substr( $bytes, $offset ) );
            if ( false === $written || 0 === $written ) {
                return false;
            }
            $offset += $written;
        }
        return true;
    }

    /** Validate UUID reference. */
    private function uuid_ref( $value ) {
        return is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value );
    }

    /** Validate SHA-256 reference. */
    private function hash_ref( $value ) {
        return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $value );
    }

    /** Check exact associative keys independent of order. */
    private function exact_keys( $value, $keys ) {
        if ( ! is_array( $value ) ) {
            return false;
        }
        $actual = array_keys( $value );
        sort( $actual );
        sort( $keys );
        return $actual === $keys;
    }

    /** Build a stable closed error. */
    private function error( $operation, $code ) {
        return array(
            'protocol'  => '1',
            'operation' => 64 >= strlen( $operation ) ? $operation : 'unknown',
            'ok'        => false,
            'code'      => $code,
        );
    }
}
