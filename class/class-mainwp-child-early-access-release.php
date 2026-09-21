<?php
/**
 * MainWP Early Access verified Child release protocol.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag -- Closed validators define private protocol helper contracts.
// phpcs:disable WordPress.WP.AlternativeFunctions -- Atomic directory replacement and verified rollback require native rename/filesystem primitives.

/**
 * Apply one official MainWP Child package with exact readback and restoration.
 */
class MainWP_Child_Early_Access_Release {

    /** Maximum compressed artifact bytes. */
    const MAX_ARTIFACT_BYTES = 52428800;

    /** Maximum aggregate uncompressed ZIP bytes. */
    const MAX_UNCOMPRESSED_BYTES = 209715200;

    /** Maximum ZIP entries. */
    const MAX_ZIP_ENTRIES = 5000;

    /** Receipt/backup retention. */
    const RECEIPT_TTL = 86400;

    /** Receipt option key prefix. */
    const RECEIPT_PREFIX = 'mainwp_child_early_access_v2_';

    /** Index of the receipt rows this class wrote, deliberately outside RECEIPT_PREFIX so it can never name itself. */
    const RECEIPT_INDEX_OPTION = 'mainwp_child_early_access_receipt_index';

    /** Receipt keys the index tracks at once. */
    const RECEIPT_INDEX_CAP = 200;

    /** Indexed rows one transition may reclaim. */
    const RECEIPT_SWEEP_LIMIT = 5;

    /** Handle the authenticated Child callable. */
    public function action() {
        MainWP_Helper::write( $this->release_v2( $this->request_from_post() ) );
    }

    /** Dispatch one decoded v2 request. */
    public function release_v2( $request ) {
        $operation = is_array( $request ) && isset( $request['operation'] ) && is_string( $request['operation'] ) ? $request['operation'] : 'unknown';
        if ( ! is_array( $request ) || ! $this->exact_keys( $request, array( 'protocol', 'operation', 'payload' ) ) || '2' !== $request['protocol'] || ! is_array( $request['payload'] ) ) {
            return $this->error( 'unknown', 'invalid_request' );
        }
        if ( 'capabilities' === $operation ) {
            return array() === $request['payload'] ? $this->capabilities() : $this->error( $operation, 'invalid_request' );
        }
        if ( 'status' === $operation ) {
            return $this->status( $request['payload'] );
        }
        if ( 'apply' === $operation ) {
            return $this->apply( $request );
        }
        return $this->error( $operation, 'unsupported_operation' );
    }

    /** Build a dispatch marker for response-loss fixtures. */
    protected function dispatching_receipt_for_test( $request ) {
        return $this->dispatching_receipt( $request['payload'], $this->effect_hash( $request['payload'] ), $this->current_state() );
    }

    /** Read the exact current Child version and activation intent. */
    protected function current_state() {
        $path = trailingslashit( WP_PLUGIN_DIR ) . 'mainwp-child/mainwp-child.php';
        if ( ! is_file( $path ) || is_link( $path ) || ! is_readable( $path ) ) {
            return array(
                'installed' => false,
                'version'   => '',
                'active'    => false,
            );
        }
        $version = $this->plugin_version_from_file( $path );
        if ( false === $version ) {
            return false;
        }
        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        return array(
            'installed' => true,
            'version'   => $version,
            'active'    => function_exists( 'is_plugin_active' ) && is_plugin_active( 'mainwp-child/mainwp-child.php' ),
        );
    }

    /** Verify the artifact URL uses the authenticated Dashboard origin and fixed route. */
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
        if ( ! is_array( $actual_parts ) || ! is_array( $expected_parts ) || ! isset( $actual_parts['scheme'], $actual_parts['host'], $actual_parts['path'], $expected_parts['scheme'], $expected_parts['host'] ) || isset( $actual_parts['user'], $actual_parts['pass'], $actual_parts['fragment'] ) || false === strpos( $actual_parts['path'], '/mainwp-early-access/v1/artifacts/' ) ) {
            return false;
        }
        $actual_scheme   = strtolower( $actual_parts['scheme'] );
        $expected_scheme = strtolower( $expected_parts['scheme'] );
        $local_http      = 'http' === $actual_scheme && 'http' === $expected_scheme && apply_filters( 'mainwp_child_early_access_allow_local_http', false, $url );
        if ( ( 'https' !== $actual_scheme || 'https' !== $expected_scheme ) && ! $local_http ) {
            return false;
        }
        $actual_port   = isset( $actual_parts['port'] ) ? (int) $actual_parts['port'] : ( 'https' === $actual_scheme ? 443 : 80 );
        $expected_port = isset( $expected_parts['port'] ) ? (int) $expected_parts['port'] : ( 'https' === $expected_scheme ? 443 : 80 );
        return strtolower( $actual_parts['host'] ) === strtolower( $expected_parts['host'] ) && $actual_port === $expected_port;
    }

    /** Stream one bounded no-redirect package into a private temporary file. */
    protected function download_package( $url, $token, $expected_bytes ) {
        $root = $this->storage_root( true );
        if ( false === $root ) {
            return false;
        }
        $path     = $root . '/artifact-' . wp_generate_password( 32, false, false ) . '.zip';
        $response = wp_safe_remote_get(
            $url,
            array(
                'timeout'             => 120,
                'redirection'         => 0,
                'sslverify'           => true,
                'stream'              => true,
                'filename'            => $path,
                'limit_response_size' => min( self::MAX_ARTIFACT_BYTES + 1, $expected_bytes + 1 ),
                'headers'             => array( 'Authorization' => 'Bearer ' . $token ),
            )
        );
        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) || ! is_file( $path ) || is_link( $path ) ) {
            $this->delete_file( $path );
            return false;
        }
        $bytes  = filesize( $path );
        $digest = hash_file( 'sha256', $path );
        if ( false === $bytes || false === $digest || self::MAX_ARTIFACT_BYTES < $bytes || ! chmod( $path, 0600 ) ) {
            $this->delete_file( $path );
            return false;
        }
        return array(
            'path'   => $path,
            'bytes'  => (int) $bytes,
            'sha256' => $digest,
        );
    }

    /** Validate the complete package before any installed-code mutation. */
    protected function validate_package( $package, $target_version ) {
        if ( ! $this->valid_package_descriptor( $package ) || ! class_exists( '\ZipArchive' ) ) {
            return false;
        }
        $zip = new \ZipArchive();
        if ( true !== $zip->open( $package['path'], \ZipArchive::RDONLY ) || 0 >= $zip->numFiles || self::MAX_ZIP_ENTRIES < $zip->numFiles ) {
            return false;
        }
        $total = 0;
        $seen  = array();
        for ( $index = 0; $index < $zip->numFiles; ++$index ) {
            $stat = $zip->statIndex( $index, \ZipArchive::FL_UNCHANGED );
            if ( ! is_array( $stat ) || ! isset( $stat['name'], $stat['size'] ) || ! is_string( $stat['name'] ) || ! is_int( $stat['size'] ) || ! $this->safe_zip_entry( $stat['name'] ) || isset( $seen[ $stat['name'] ] ) ) {
                $zip->close();
                return false;
            }
            $seen[ $stat['name'] ] = true;
            $total                += $stat['size'];
            if ( self::MAX_UNCOMPRESSED_BYTES < $total || $this->zip_entry_is_link( $zip, $index ) ) {
                $zip->close();
                return false;
            }
        }
        $header = $zip->getFromName( 'mainwp-child/mainwp-child.php', 262144 );
        $zip->close();
        if ( ! is_string( $header ) ) {
            return false;
        }
        $version = $this->plugin_version_from_contents( $header );
        return is_string( $version ) && $target_version === $version;
    }

    /** Apply the already validated package and restore the prior tree on settled failure. */
    protected function apply_package( $package, $before, $target_version ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity -- Explicit staged replacement and compensation.
        $root = $this->storage_root( true );
        if ( false === $root || ! $this->valid_package_descriptor( $package ) || ! $this->valid_current_state( $before ) ) {
            return false;
        }
        $stage = $root . '/stage-' . wp_generate_password( 32, false, false );
        if ( ! wp_mkdir_p( $stage ) || is_link( $stage ) || ! chmod( $stage, 0700 ) ) {
            return false;
        }
        $zip = new \ZipArchive();
        if ( true !== $zip->open( $package['path'], \ZipArchive::RDONLY ) || ! $zip->extractTo( $stage ) ) {
            $this->remove_tree( $stage );
            return false;
        }
        $zip->close();
        $staged = $stage . '/mainwp-child';
        $target = trailingslashit( WP_PLUGIN_DIR ) . 'mainwp-child';
        if ( ! is_dir( $staged ) || is_link( $staged ) || ! $this->tree_is_safe( $staged ) || ( file_exists( $target ) && ( ! is_dir( $target ) || is_link( $target ) ) ) ) {
            $this->remove_tree( $stage );
            return false;
        }
        $backup = null;
        if ( $before['installed'] ) {
            $backup = $root . '/backup-' . wp_generate_password( 32, false, false );
            if ( ! rename( $target, $backup ) ) {
                $this->remove_tree( $stage );
                return false;
            }
        }
        $published = rename( $staged, $target );
        $this->remove_tree( $stage );
        if ( ! $published ) {
            $restored = null === $backup || rename( $backup, $target );
            return array(
                'status'     => $restored ? 'restored' : 'unknown',
                'backup_ref' => null === $backup ? null : basename( $backup ),
            );
        }
        $after = $this->current_state();
        if ( ! $this->valid_current_state( $after ) || ! $after['installed'] || $target_version !== $after['version'] || $before['active'] !== $after['active'] ) {
            $this->remove_tree( $target );
            $restored       = null === $backup || rename( $backup, $target );
            $restored_state = $this->current_state();
            $restored       = $restored && $this->valid_current_state( $restored_state ) && $restored_state === $before;
            return array(
                'status'     => $restored ? 'restored' : 'unknown',
                'backup_ref' => null === $backup ? null : basename( $backup ),
            );
        }
        return array(
            'status'     => 'applied',
            'backup_ref' => null === $backup ? null : basename( $backup ),
        );
    }

    /** Reclaim the superseded private backup tree of one verified replacement. */
    protected function remove_backup( $backup_ref ) {
        $root = $this->storage_root( false );
        if ( false === $root || ! $this->safe_ref( $backup_ref ) || 0 !== strpos( $backup_ref, 'backup-' ) ) {
            return false;
        }
        return $this->remove_tree( $root . '/' . $backup_ref );
    }

    /** Delete a downloaded private package. */
    protected function cleanup_package( $package ) {
        return ! is_array( $package ) || ! isset( $package['path'] ) || $this->delete_file( $package['path'] );
    }

    /** Read one private receipt. */
    protected function load_receipt( $request_ref ) {
        $value = get_option( $this->receipt_key( $request_ref ), null );
        if ( null === $value ) {
            return null;
        }
        return $this->valid_receipt( $value ) ? $value : false;
    }

    /**
     * Report whether one settled result has passed retention.
     *
     * Nothing else prunes these options, so the holder of the transition lane is what reclaims one
     * past retention, and the reference becomes free for a new request. A reader outside the lane
     * must not: delete_option matches the option key alone, so a delete decided from a value read
     * earlier lands on whatever a concurrent transition has since written under the same reference.
     * Only an outcome that established what the installed tree went through may be forgotten that
     * way: applied, restored and failed each name a known ending, so a later request under the same
     * reference either converges on the same verified tree or starts from a state that was
     * read back and proven. A dispatch marker and a settled unknown are both exempt - neither
     * ever resolved the effect, so the only truthful answer stays outcome_unknown however old
     * the receipt is, and dropping one would let a retry run the transition twice.
     */
    private function receipt_expired( $receipt ) {
        if ( 'settled' !== $receipt['state'] || $receipt['expires_at'] > time() ) {
            return false;
        }
        return in_array( $receipt['result']['status'], array( 'applied', 'restored', 'failed' ), true );
    }

    /** Reserve one request. */
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
     * A request reference is one-shot, so the reclaim on the apply path only ever reaches the
     * reference the current request names; every other row stays in wp_options until the site is
     * torn down. Finding them means either a prefix scan of that table, unindexed and on the hot
     * transition path, or an index of the keys this class wrote.
     *
     * The only caller reserves under the transition lane, so this runs under it too. It may not
     * change the request's answer, and it runs before the reservation exists so that a fatal in
     * here leaves nothing reserved: a transition that died holding a marker it can never settle
     * would poison the same index for every request after it. The sweep deletes only what
     * receipt_expired() already permits, so a dispatch marker and a settled unknown - the two
     * receipts that never established what the installed tree went through - stay whatever their
     * age.
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
     * Record the row this request just wrote, so a later transition can find it.
     *
     * Recording happens after the write because only then is the key known to name a row. The index
     * is advisory and its failure is ignored: a transition that has already reserved its effect is
     * never refused over bookkeeping.
     *
     * @param string $option_key Receipt key this request wrote.
     * @param int    $expires_at Retention moment stored on it.
     */
    private function record_retained_receipt( $option_key, $expires_at ) {
        $index = new MainWP_Child_Receipt_Index( self::RECEIPT_PREFIX );
        $index->add( self::RECEIPT_INDEX_OPTION, $option_key, $expires_at, self::RECEIPT_INDEX_CAP );
    }

    /** Replace one exact dispatch marker. */
    protected function settle_receipt( $request_ref, $expected, $receipt ) {
        if ( ! $this->valid_receipt( $receipt ) || $expected !== $this->load_receipt( $request_ref ) ) {
            return false;
        }
        update_option( $this->receipt_key( $request_ref ), $receipt, false );
        return $receipt === $this->load_receipt( $request_ref );
    }

    /** Acquire the single Child-code transition lane without waiting. */
    protected function acquire_transition_lock() {
        $root = $this->storage_root( true );
        if ( false === $root ) {
            return false;
        }
        $file = @fopen( $root . '/transition.lock', 'c+b' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Lock acquisition outcome is checked.
        if ( false === $file || ! flock( $file, LOCK_EX | LOCK_NB ) ) {
            if ( is_resource( $file ) ) {
                fclose( $file );
            }
            return false;
        }
        return $file;
    }

    /** Release the Child-code transition lane. */
    protected function release_transition_lock( $lock ) {
        if ( is_resource( $lock ) ) {
            flock( $lock, LOCK_UN );
            fclose( $lock );
        }
    }

    /** Execute or replay one transition. */
    private function apply( $request ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity -- Reserve, fetch, validate, replace, readback, and settle are intentionally explicit.
        $payload = $request['payload'];
        if ( ! $this->valid_apply_payload( $payload ) ) {
            return $this->error( 'apply', 'invalid_request' );
        }
        if ( ! $this->gateway_allowed( $payload['gateway_url'] ) ) {
            return $this->error( 'apply', 'gateway_rejected' );
        }
        $lock = $this->acquire_transition_lock();
        if ( false === $lock ) {
            return $this->error( 'apply', 'lock_busy' );
        }
        try {
            $effect_hash = $this->effect_hash( $payload );
            $existing    = $this->load_receipt( $payload['request_ref'] );
            if ( false === $existing ) {
                return $this->error( 'apply', 'storage_unavailable' );
            }
            if ( is_array( $existing ) && $this->receipt_expired( $existing ) ) {
                // Retention is reclaimed here and not in the reader because delete_option matches
                // the option key alone: only the lane holder knows nothing else has written under
                // this reference since it was read. A row that will not delete has not become
                // absent, so the reference is never treated as free unless it was actually freed.
                if ( ! delete_option( $this->receipt_key( $payload['request_ref'] ) ) ) {
                    return $this->error( 'apply', 'storage_unavailable' );
                }
                $existing = null;
            }
            if ( is_array( $existing ) ) {
                return $this->replay( $existing, $effect_hash );
            }
            $before = $this->current_state();
            if ( ! $this->valid_current_state( $before ) ) {
                return $this->error( 'apply', 'read_failed' );
            }
            $dispatching = $this->dispatching_receipt( $payload, $effect_hash, $before );
            if ( ! $this->create_receipt( $payload['request_ref'], $dispatching ) ) {
                $existing = $this->load_receipt( $payload['request_ref'] );
                return is_array( $existing ) ? $this->replay( $existing, $effect_hash ) : $this->error( 'apply', 'storage_unavailable' );
            }
            $package = $this->download_package( $payload['gateway_url'], $payload['gateway_token'], $payload['expected_bytes'] );
            if ( ! $this->valid_package_descriptor( $package ) || $payload['expected_bytes'] !== $package['bytes'] || ! hash_equals( $payload['expected_sha256'], $package['sha256'] ) ) {
                $this->cleanup_package( $package );
                return $this->settle_failure( $dispatching, 'package_invalid', 'failed', $before );
            }
            if ( ! $this->validate_package( $package, $payload['target_version'] ) ) {
                $this->cleanup_package( $package );
                return $this->settle_failure( $dispatching, 'package_invalid', 'failed', $before );
            }
            $fresh = $this->current_state();
            if ( ! $this->valid_current_state( $fresh ) ) {
                $this->cleanup_package( $package );
                // The installed tree became unreadable while the transition lane was held, so no version can be claimed.
                return $this->settle_failure( $dispatching, 'read_failed', 'unknown' );
            }
            if ( $fresh !== $before ) {
                $this->cleanup_package( $package );
                return $this->settle_failure( $dispatching, 'stale_state', 'failed', $fresh );
            }
            $applied = $this->apply_package( $package, $before, $payload['target_version'] );
            $cleaned = $this->cleanup_package( $package );
            $after   = $this->current_state();
            if ( ! $cleaned || ! is_array( $applied ) || ! $this->exact_keys( $applied, array( 'status', 'backup_ref' ) ) || ! in_array( $applied['status'], array( 'applied', 'restored', 'unknown' ), true ) || ( null !== $applied['backup_ref'] && ! $this->safe_ref( $applied['backup_ref'] ) ) || ! $this->valid_current_state( $after ) ) {
                return $this->settle_failure( $dispatching, 'outcome_unknown', 'unknown', $after );
            }
            if ( 'restored' === $applied['status'] ) {
                return $this->settle_result( $dispatching, $this->result( $payload, 'restored', $before['version'], $after['version'], $after['active'], 'restored', false, 'install_failed_restored' ) );
            }
            if ( 'unknown' === $applied['status'] || ! $after['installed'] || $payload['target_version'] !== $after['version'] || $before['active'] !== $after['active'] ) {
                return $this->settle_failure( $dispatching, 'outcome_unknown', 'unknown', $after );
            }
            // The replacement is verified and no result ever hands the backup reference out, so nothing
            // can still roll back to it. A reclaim that fails leaves the tree behind rather than
            // reporting a transition that did happen as a failure.
            $this->remove_backup( $applied['backup_ref'] );
            return $this->settle_result( $dispatching, $this->result( $payload, 'applied', $before['version'], $after['version'], $after['active'], 'installed', false, null ) );
        } finally {
            $this->release_transition_lock( $lock );
        }
    }

    /** Read one retained result. */
    private function status( $payload ) {
        if ( ! is_array( $payload ) || ! $this->exact_keys( $payload, array( 'request_ref' ) ) || ! $this->uuid_ref( $payload['request_ref'] ) ) {
            return $this->error( 'status', 'invalid_request' );
        }
        $receipt = $this->load_receipt( $payload['request_ref'] );
        if ( false === $receipt ) {
            return $this->error( 'status', 'storage_unavailable' );
        }
        // A result past retention stops being served here, but the reclaim itself belongs to the
        // transition lane, which a status read does not hold.
        if ( ! is_array( $receipt ) || $this->receipt_expired( $receipt ) ) {
            return $this->error( 'status', 'not_found' );
        }
        return 'dispatching' === $receipt['state'] ? $this->unknown_result( $receipt ) : $receipt['result'];
    }

    /** Advertise the implemented protocol. */
    private function capabilities() {
        return array(
            'protocol'           => '2',
            'operation'          => 'capabilities',
            'ok'                 => true,
            'operations'         => array( 'apply', 'status' ),
            'mutation_supported' => true,
            'max_artifact_bytes' => self::MAX_ARTIFACT_BYTES,
        );
    }

    /** Read a bounded JSON request from authenticated POST state. */
    private function request_from_post() {
		// phpcs:disable WordPress.Security.NonceVerification -- MainWP signature authentication occurs before callable dispatch.
        if ( ! isset( $_POST['request'] ) || ! is_string( $_POST['request'] ) ) {
            return null;
        }
        $raw = wp_unslash( $_POST['request'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The decoded envelope is validated field by field.
		// phpcs:enable WordPress.Security.NonceVerification
        if ( 16384 < strlen( $raw ) ) {
            return null;
        }
        $request = json_decode( $raw, true );
        return is_array( $request ) ? $request : null;
    }

    /** Build a secret-free request digest. */
    private function effect_hash( $payload ) {
        $bound                  = $payload;
        $bound['gateway_url']   = hash( 'sha256', $bound['gateway_url'] );
        $bound['gateway_token'] = hash( 'sha256', $bound['gateway_token'] );
        return hash( 'sha256', wp_json_encode( $bound, JSON_UNESCAPED_SLASHES ) );
    }

    /** Build one dispatch marker. */
    private function dispatching_receipt( $payload, $effect_hash, $before ) {
        return array(
            'effect_hash'      => $effect_hash,
            'request_ref'      => $payload['request_ref'],
            'action'           => $payload['action'],
            'target_version'   => $payload['target_version'],
            'expected_sha256'  => $payload['expected_sha256'],
            'previous_version' => $before['version'],
            'previous_active'  => $before['active'],
            'state'            => 'dispatching',
            'result'           => null,
            'updated_at'       => time(),
            'expires_at'       => time() + self::RECEIPT_TTL,
        );
    }

    /** Replay exact truth or preserve ambiguity. */
    private function replay( $receipt, $effect_hash ) {
        if ( ! $this->valid_receipt( $receipt ) ) {
            return $this->error( 'apply', 'storage_unavailable' );
        }
        if ( ! hash_equals( $receipt['effect_hash'], $effect_hash ) ) {
            return $this->error( 'apply', 'request_conflict' );
        }
        return 'dispatching' === $receipt['state'] ? $this->unknown_result( $receipt ) : $receipt['result'];
    }

    /** Persist one settled failure or ambiguity. */
    private function settle_failure( $dispatching, $code, $status = 'failed', $state = null ) {
        // Without a snapshot the installed code is unknown, not absent, so the empty reading only ever ships as an ambiguous outcome.
        $known   = $this->valid_current_state( $state );
        $status  = $known ? $status : 'unknown';
        $current = $known ? $state : array(
            'installed' => false,
            'version'   => '',
            'active'    => $dispatching['previous_active'],
        );
        $result  = $this->result(
            array(
                'request_ref' => $dispatching['request_ref'],
                'action'      => $dispatching['action'],
            ),
            $status,
            $dispatching['previous_version'],
            $current['version'],
            $current['active'],
            'unknown' === $status ? 'unknown' : 'not_attempted',
            'unknown' !== $status,
            $code
        );
        return $this->settle_result( $dispatching, $result );
    }

    /** Persist one terminal result. */
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

    /** Build one bounded transition result. */
    private function result( $payload, $status, $previous_version, $installed_version, $active, $persistence, $retry_safe, $code ) {
        return array(
            'protocol'          => '2',
            'operation'         => 'apply',
            'ok'                => 'applied' === $status,
            'request_ref'       => $payload['request_ref'],
            'status'            => $status,
            'action'            => $payload['action'],
            'previous_version'  => $previous_version,
            'installed_version' => $installed_version,
            'active'            => $active,
            'persistence'       => $persistence,
            'retry_safe'        => $retry_safe,
            'code'              => $code,
        );
    }

    /** Return truthful unknown state for a reserved effect. */
    private function unknown_result( $receipt ) {
        return $this->result(
            array(
                'request_ref' => $receipt['request_ref'],
                'action'      => $receipt['action'],
            ),
            'unknown',
            $receipt['previous_version'],
            '',
            $receipt['previous_active'],
            'unknown',
            false,
            'outcome_unknown'
        );
    }

    /** Validate one apply request. */
    private function valid_apply_payload( $payload ) {
        return is_array( $payload )
            && $this->exact_keys( $payload, array( 'request_ref', 'action', 'gateway_url', 'gateway_token', 'expected_bytes', 'expected_sha256', 'target_version', 'expires_at' ) )
            && $this->uuid_ref( $payload['request_ref'] )
            && in_array( $payload['action'], array( 'upgrade', 'downgrade', 'reinstall' ), true )
            && is_string( $payload['gateway_url'] )
            && is_string( $payload['gateway_token'] ) && 16 <= strlen( $payload['gateway_token'] ) && 4096 >= strlen( $payload['gateway_token'] ) && ! preg_match( '/[\x00-\x1F\x7F]/', $payload['gateway_token'] )
            && is_int( $payload['expected_bytes'] ) && 0 < $payload['expected_bytes'] && self::MAX_ARTIFACT_BYTES >= $payload['expected_bytes']
            && $this->hash_ref( $payload['expected_sha256'] )
            && $this->version_ref( $payload['target_version'] )
            && is_int( $payload['expires_at'] ) && time() <= $payload['expires_at'] && time() + 300 >= $payload['expires_at'];
    }

    /** Validate one package descriptor. */
    private function valid_package_descriptor( $package ) {
        return is_array( $package )
            && $this->exact_keys( $package, array( 'path', 'bytes', 'sha256' ) )
            && is_string( $package['path'] ) && '' !== $package['path'] && 4096 >= strlen( $package['path'] )
            && is_int( $package['bytes'] ) && 0 < $package['bytes'] && self::MAX_ARTIFACT_BYTES >= $package['bytes']
            && $this->hash_ref( $package['sha256'] );
    }

    /** Validate one current-state snapshot. */
    private function valid_current_state( $state ) {
        return is_array( $state )
            && $this->exact_keys( $state, array( 'installed', 'version', 'active' ) )
            && is_bool( $state['installed'] )
            && is_bool( $state['active'] )
            && is_string( $state['version'] )
            && ( $state['installed'] ? $this->version_ref( $state['version'] ) : '' === $state['version'] && false === $state['active'] );
    }

    /** Validate one stored receipt. */
    private function valid_receipt( $receipt ) {
        if ( ! is_array( $receipt ) || ! $this->exact_keys( $receipt, array( 'effect_hash', 'request_ref', 'action', 'target_version', 'expected_sha256', 'previous_version', 'previous_active', 'state', 'result', 'updated_at', 'expires_at' ) ) || ! $this->hash_ref( $receipt['effect_hash'] ) || ! $this->uuid_ref( $receipt['request_ref'] ) || ! in_array( $receipt['action'], array( 'upgrade', 'downgrade', 'reinstall' ), true ) || ! $this->version_ref( $receipt['target_version'] ) || ! $this->hash_ref( $receipt['expected_sha256'] ) || ! is_string( $receipt['previous_version'] ) || ( '' !== $receipt['previous_version'] && ! $this->version_ref( $receipt['previous_version'] ) ) || ! is_bool( $receipt['previous_active'] ) || ! in_array( $receipt['state'], array( 'dispatching', 'settled' ), true ) || ! is_int( $receipt['updated_at'] ) || 0 >= $receipt['updated_at'] || ! is_int( $receipt['expires_at'] ) || $receipt['updated_at'] > $receipt['expires_at'] ) {
            return false;
        }
        return 'dispatching' === $receipt['state'] ? null === $receipt['result'] : $this->valid_result( $receipt['result'], $receipt );
    }

    /** Validate one stored public result. */
    private function valid_result( $result, $receipt ) {
        if ( ! is_array( $result ) || ! $this->exact_keys( $result, array( 'protocol', 'operation', 'ok', 'request_ref', 'status', 'action', 'previous_version', 'installed_version', 'active', 'persistence', 'retry_safe', 'code' ) ) || '2' !== $result['protocol'] || 'apply' !== $result['operation'] || ! is_bool( $result['ok'] ) || $receipt['request_ref'] !== $result['request_ref'] || ! in_array( $result['status'], array( 'applied', 'failed', 'restored', 'unknown' ), true ) || $receipt['action'] !== $result['action'] || ! is_string( $result['previous_version'] ) || ( '' !== $result['previous_version'] && ! $this->version_ref( $result['previous_version'] ) ) || ! is_string( $result['installed_version'] ) || ( '' !== $result['installed_version'] && ! $this->version_ref( $result['installed_version'] ) ) || ! is_bool( $result['active'] ) || ! in_array( $result['persistence'], array( 'installed', 'restored', 'not_attempted', 'unknown' ), true ) || ! is_bool( $result['retry_safe'] ) || ( null !== $result['code'] && ( ! is_string( $result['code'] ) || 1 !== preg_match( '/^[a-z0-9_]{1,64}$/D', $result['code'] ) ) ) ) {
            return false;
        }
        return ( 'applied' === $result['status'] ) === $result['ok'] && ( $result['ok'] ? null === $result['code'] : null !== $result['code'] );
    }

    /** Validate one ZIP entry path. */
    private function safe_zip_entry( $name ) {
        if ( '' === $name || 1024 < strlen( $name ) || '/' === $name[0] || false !== strpos( $name, '\\' ) || false !== strpos( $name, ':' ) || false !== strpos( $name, "\0" ) ) {
            return false;
        }
        $parts = explode( '/', rtrim( $name, '/' ) );
        return 'mainwp-child' === $parts[0] && ! in_array( '', $parts, true ) && ! in_array( '.', $parts, true ) && ! in_array( '..', $parts, true );
    }

    /** Reject symlink entries when the ZIP implementation exposes Unix mode. */
    private function zip_entry_is_link( $zip, $index ) {
        $opsys = 0;
        $attr  = 0;
        if ( ! method_exists( $zip, 'getExternalAttributesIndex' ) || ! $zip->getExternalAttributesIndex( $index, $opsys, $attr ) ) {
            return false;
        }
        return 3 === $opsys && 0120000 === ( ( $attr >> 16 ) & 0170000 );
    }

    /** Parse one bounded plugin version header. */
    private function plugin_version_from_file( $path ) {
        $handle = fopen( $path, 'rb' );
        if ( false === $handle ) {
            return false;
        }
        $contents = fread( $handle, 262144 );
        fclose( $handle );
        return is_string( $contents ) ? $this->plugin_version_from_contents( $contents ) : false;
    }

    /** Parse one exact Version header. */
    private function plugin_version_from_contents( $contents ) {
        if ( ! is_string( $contents ) || 1 !== preg_match( '/^[ \t\/*#@]*Version:\\s*(.+)$/mi', $contents, $match ) ) {
            return false;
        }
        $version = trim( $match[1] );
        return $this->version_ref( $version ) ? $version : false;
    }

    /** Return/create the private release root. */
    protected function storage_root( $create ) {
        if ( ! defined( 'ABSPATH' ) ) {
            return false;
        }
        $wordpress_root = rtrim( wp_normalize_path( ABSPATH ), '/' );
        $private_base   = dirname( $wordpress_root ) . '/.mainwp-child-private';
        $document_root  = isset( $_SERVER['DOCUMENT_ROOT'] ) && is_string( $_SERVER['DOCUMENT_ROOT'] ) ? realpath( sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) ) ) : false;
        if ( is_string( $document_root ) && 0 === strpos( wp_normalize_path( $private_base ) . '/', rtrim( wp_normalize_path( $document_root ), '/' ) . '/' ) ) {
            return false;
        }
        $root = $private_base . '/early-access-' . substr( hash( 'sha256', $wordpress_root ), 0, 16 );
        if ( ! is_dir( $root ) && ( ! $create || ! wp_mkdir_p( $root ) ) ) {
            return false;
        }
        if ( is_link( $root ) || ( is_dir( $root ) && ! chmod( $root, 0700 ) ) ) {
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

    /** Remove one regular private file. */
    private function delete_file( $path ) {
        return ! is_string( $path ) || '' === $path || ! file_exists( $path ) || ( is_file( $path ) && ! is_link( $path ) && unlink( $path ) );
    }

    /** Recursively remove one known staging/backup tree without following links. */
    private function remove_tree( $root ) {
        if ( ! is_string( $root ) || '' === $root || ! file_exists( $root ) ) {
            return true;
        }
        if ( is_link( $root ) || ! is_dir( $root ) ) {
            return false;
        }
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ( $iterator as $item ) {
                $path = $item->getPathname();
                if ( $item->isLink() ) {
                    return false;
                }
                if ( $item->isDir() ? ! rmdir( $path ) : ! unlink( $path ) ) {
                    return false;
                }
            }
        } catch ( \UnexpectedValueException $exception ) {
            // An unreadable directory makes the walk throw part-way through. The tree is then
            // left in place, which is this method's own failure result - a cleanup must not
            // take the transition that already succeeded down with it.
            return false;
        }
        return rmdir( $root );
    }

    /** Verify an extracted tree contains no links or non-regular entries. */
    private function tree_is_safe( $root ) {
        if ( ! is_dir( $root ) || is_link( $root ) ) {
            return false;
        }
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ( $iterator as $item ) {
                if ( $item->isLink() || ( ! $item->isDir() && ! $item->isFile() ) ) {
                    return false;
                }
            }
        } catch ( \UnexpectedValueException $exception ) {
            // A directory the walk cannot read leaves part of the tree uninspected, so nothing here
            // can be called safe.
            return false;
        }
        return true;
    }

    /** Build an option-safe receipt key. */
    private function receipt_key( $request_ref ) {
        return self::RECEIPT_PREFIX . hash( 'sha256', $request_ref );
    }

    /** Validate a safe private basename. */
    private function safe_ref( $value ) {
        return is_string( $value ) && 1 === preg_match( '/^[A-Za-z0-9_-]{1,96}$/D', $value );
    }

    /** Validate one bounded version. */
    private function version_ref( $value ) {
        return is_string( $value ) && 1 === preg_match( '/^[0-9A-Za-z][0-9A-Za-z._+-]{0,63}$/D', $value );
    }

    /** Validate UUID reference. */
    private function uuid_ref( $value ) {
        return is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value );
    }

    /** Validate SHA-256 reference. */
    private function hash_ref( $value ) {
        return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $value );
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

    /** Build one stable error. */
    private function error( $operation, $code ) {
        return array(
            'protocol'  => '2',
            'operation' => 64 >= strlen( $operation ) ? $operation : 'unknown',
            'ok'        => false,
            'code'      => $code,
        );
    }
}
