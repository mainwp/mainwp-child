<?php
/**
 * MainWP Termageddon page protocol.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Content-bound Termageddon page read and deletion protocol.
 *
 * The Child stores no Termageddon provenance of its own, so a page is bound to the
 * Dashboard's record by the exact post ID, the expected type and status, and a
 * SHA-256 of the live post content matching the recorded content generation.
 */
class MainWP_Child_Termageddon {

    /** Durable deletion receipt option. */
    const RECEIPTS_OPTION = 'mainwp_child_termageddon_v2_receipts';

    /** Maximum retained deletion receipts. */
    const MAX_RECEIPTS = 100;

    /**
     * Handle the authenticated get callable.
     */
    public function handle_get() {
        MainWP_Helper::write( $this->get_page_v2( $this->request_from_post() ) );
    }

    /**
     * Handle the authenticated delete callable.
     */
    public function handle_delete() {
        MainWP_Helper::write( $this->delete_page_v2( $this->request_from_post() ) );
    }

    /**
     * Read one exact content-bound page without returning content or URLs.
     *
     * @param mixed $request Request payload.
     * @return array
     */
    public function get_page_v2( $request ) {
        if ( ! $this->valid_page_request( $request ) ) {
            return $this->error( 'get', 'invalid_request' );
        }

        return $this->observe_page( $request );
    }

    /**
     * Preview or delete one exact content-bound page.
     *
     * @param mixed $request Request payload.
     * @return array
     */
    public function delete_page_v2( $request ) { // phpcs:ignore -- NOSONAR - explicit receipt/readback flow.
        if ( ! $this->valid_delete_request( $request ) ) {
            return $this->error( 'delete', 'invalid_request' );
        }

        $digest   = $this->delete_digest( $request );
        $receipts = $this->read_receipts();
        if ( false === $receipts ) {
            return $this->error( 'delete', 'storage_unavailable' );
        }

        if ( isset( $receipts[ $request['request_ref'] ] ) ) {
            $receipt = $receipts[ $request['request_ref'] ];
            if ( ! $this->valid_receipt( $receipt ) || ! hash_equals( $receipt['digest'], $digest ) ) {
                return $this->error( 'delete', 'request_conflict' );
            }
            if ( is_array( $receipt['result'] ) ) {
                return $receipt['result'];
            }
            if ( null === get_post( $request['post_id'] ) ) {
                return $this->finish_deleted_receipt( $request, $digest, $receipts );
            }
        }

        $observation = $this->observe_page( $request );
        if ( ! $observation['ok'] || 'current' !== $observation['state'] ) {
            if ( 'not_found' === $observation['state'] ) {
                return $this->delete_result( $request['request_ref'], 'not_found', false );
            }
            return $this->error( 'delete', 'target_drift' );
        }

        if ( $request['dry_run'] ) {
            return $this->delete_result( $request['request_ref'], 'ready', true );
        }

        $receipts[ $request['request_ref'] ] = array(
            'digest'     => $digest,
            'result'     => null,
            'updated_at' => time(),
        );
        if ( ! $this->write_receipts( $receipts ) ) {
            return $this->error( 'delete', 'storage_unavailable' );
        }

        $deleted = wp_delete_post( $request['post_id'], true );
        if ( ! $deleted && null !== get_post( $request['post_id'] ) ) {
            return $this->error( 'delete', 'child_delete_failed' );
        }
        if ( null !== get_post( $request['post_id'] ) ) {
            return $this->error( 'delete', 'readback_failed' );
        }

        return $this->finish_deleted_receipt( $request, $digest, $receipts );
    }

    /**
     * Observe the exact page binding.
     *
     * @param array $request Valid request.
     * @return array
     */
    private function observe_page( $request ) {
        $post = get_post( $request['post_id'] );
        if ( ! $post ) {
            return $this->get_result( false, 'not_found', null, null, null, null );
        }

        if ( $post->post_type !== $request['expected_post_type'] ) {
            return $this->get_result( true, 'type_mismatch', null, null, null, null );
        }
        if ( $post->post_status !== $request['expected_status'] ) {
            return $this->get_result( true, 'status_mismatch', null, null, null, null );
        }

        // The only Child-side proof that this post is still the page the Dashboard recorded.
        $content_hash = hash( 'sha256', (string) $post->post_content );
        if ( ! hash_equals( $request['content_generation'], $content_hash ) ) {
            return $this->get_result( true, 'content_drift', $post->post_type, $post->post_status, $request['content_generation'], $content_hash );
        }

        return $this->get_result( true, 'current', $post->post_type, $post->post_status, $request['content_generation'], $content_hash );
    }

    /**
     * Validate the common request shape.
     *
     * The page_ref, page_type and site_generation fields are Dashboard-side identity the
     * Child cannot verify against anything local; they are shape-checked and folded into
     * the delete digest so a replay under a different identity is rejected as a conflict.
     *
     * @param mixed $request Request payload.
     * @return bool
     */
    private function valid_page_request( $request ) {
        if ( ! is_array( $request ) || ! $this->exact_keys( $request, array( 'contract_version', 'post_id', 'page_ref', 'page_type', 'site_generation', 'content_generation', 'expected_post_type', 'expected_status' ) ) ) {
            return false;
        }

        return '2' === $request['contract_version']
            && is_int( $request['post_id'] )
            && 0 < $request['post_id']
            && $this->hash_value( $request['page_ref'] )
            && in_array( $request['page_type'], array( 'privacy', 'terms', 'disclaimer', 'cookie-consent' ), true )
            && $this->hash_value( $request['site_generation'] )
            && $this->hash_value( $request['content_generation'] )
            && 'page' === $request['expected_post_type']
            && in_array( $request['expected_status'], array( 'publish', 'private', 'draft', 'pending', 'future' ), true );
    }

    /**
     * Validate the deletion request shape.
     *
     * @param mixed $request Request payload.
     * @return bool
     */
    private function valid_delete_request( $request ) {
        if ( ! is_array( $request ) || ! $this->exact_keys( $request, array( 'contract_version', 'post_id', 'page_ref', 'page_type', 'site_generation', 'content_generation', 'expected_post_type', 'expected_status', 'request_ref', 'dry_run' ) ) ) {
            return false;
        }

        $page_request = $request;
        unset( $page_request['request_ref'], $page_request['dry_run'] );

        return $this->valid_page_request( $page_request )
            && is_string( $request['request_ref'] )
            && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $request['request_ref'] )
            && is_bool( $request['dry_run'] );
    }

    /**
     * Read a bounded JSON request from the authenticated callable.
     *
     * @return array|null
     */
    private function request_from_post() {
        // phpcs:disable WordPress.Security.NonceVerification -- MainWP signature authentication occurs before callable dispatch.
        if ( ! isset( $_POST['request'] ) || ! is_string( $_POST['request'] ) ) {
            return null;
        }
        $raw = wp_unslash( $_POST['request'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Strict JSON schema validation follows.
        // phpcs:enable WordPress.Security.NonceVerification
        if ( 16384 < strlen( $raw ) ) {
            return null;
        }
        $request = json_decode( $raw, true );
        return is_array( $request ) ? $request : null;
    }

    /**
     * Complete a durable deletion receipt.
     *
     * @param array  $request  Request.
     * @param string $digest   Request digest.
     * @param array  $receipts Receipts.
     * @return array
     */
    private function finish_deleted_receipt( $request, $digest, $receipts ) {
        $result = $this->delete_result( $request['request_ref'], 'deleted', false );

        $receipts[ $request['request_ref'] ] = array(
            'digest'     => $digest,
            'result'     => $result,
            'updated_at' => time(),
        );
        if ( ! $this->write_receipts( $receipts ) ) {
            return $this->error( 'delete', 'reconciliation_required' );
        }
        return $result;
    }

    /**
     * Read and validate receipts.
     *
     * @return array|false
     */
    private function read_receipts() {
        $receipts = get_option( self::RECEIPTS_OPTION, array() );
        if ( ! is_array( $receipts ) || self::MAX_RECEIPTS < count( $receipts ) ) {
            return false;
        }
        foreach ( $receipts as $request_ref => $receipt ) {
            if ( ! is_string( $request_ref ) || ! $this->valid_receipt( $receipt ) ) {
                return false;
            }
        }
        return $receipts;
    }

    /**
     * Write and verify bounded receipts.
     *
     * @param array $receipts Receipts.
     * @return bool
     */
    private function write_receipts( $receipts ) {
        if ( self::MAX_RECEIPTS < count( $receipts ) ) {
            uasort(
                $receipts,
                static function ( $left, $right ) {
                    return $left['updated_at'] <=> $right['updated_at'];
                }
            );
            $receipts = array_slice( $receipts, -self::MAX_RECEIPTS, null, true );
        }
        update_option( self::RECEIPTS_OPTION, $receipts, false );
        return get_option( self::RECEIPTS_OPTION, null ) === $receipts;
    }

    /**
     * Validate one stored receipt.
     *
     * @param mixed $receipt Receipt.
     * @return bool
     */
    private function valid_receipt( $receipt ) {
        return is_array( $receipt )
            && $this->exact_keys( $receipt, array( 'digest', 'result', 'updated_at' ) )
            && $this->hash_value( $receipt['digest'] )
            && ( null === $receipt['result'] || $this->valid_delete_result( $receipt['result'] ) )
            && is_int( $receipt['updated_at'] )
            && 0 < $receipt['updated_at'];
    }

    /**
     * Validate a terminal deletion result.
     *
     * @param mixed $result Result.
     * @return bool
     */
    private function valid_delete_result( $result ) {
        return is_array( $result )
            && $this->exact_keys( $result, array( 'contract_version', 'operation', 'ok', 'request_ref', 'state', 'exists_after' ) )
            && '2' === $result['contract_version']
            && 'delete' === $result['operation']
            && true === $result['ok']
            && is_string( $result['request_ref'] )
            && 'deleted' === $result['state']
            && false === $result['exists_after'];
    }

    /**
     * Build an intent digest that is stable between preview and execution.
     *
     * @param array $request Request.
     * @return string
     */
    private function delete_digest( $request ) {
        unset( $request['dry_run'] );
        ksort( $request );
        return hash( 'sha256', wp_json_encode( $request ) );
    }

    /**
     * Build a redacted get result.
     *
     * @param bool        $found              Whether the post exists.
     * @param string      $state              Observed state.
     * @param string|null $post_type          Verified post type.
     * @param string|null $post_status        Verified post status.
     * @param string|null $content_generation Expected content generation.
     * @param string|null $content_hash       Observed content hash.
     * @return array
     */
    private function get_result( $found, $state, $post_type, $post_status, $content_generation, $content_hash ) {
        return array(
            'contract_version'   => '2',
            'operation'          => 'get',
            'ok'                 => true,
            'found'              => $found,
            'state'              => $state,
            'post_type'          => $post_type,
            'post_status'        => $post_status,
            'content_generation' => $content_generation,
            'content_hash'       => $content_hash,
        );
    }

    /**
     * Build a deletion result.
     *
     * @param string $request_ref Request reference.
     * @param string $state       Result state.
     * @param bool   $exists_after Whether the post exists after the operation.
     * @return array
     */
    private function delete_result( $request_ref, $state, $exists_after ) {
        return array(
            'contract_version' => '2',
            'operation'        => 'delete',
            'ok'               => true,
            'request_ref'      => $request_ref,
            'state'            => $state,
            'exists_after'     => $exists_after,
        );
    }

    /**
     * Build a stable error.
     *
     * @param string $operation Operation name.
     * @param string $code      Stable error code.
     * @return array
     */
    private function error( $operation, $code ) {
        return array(
            'contract_version' => '2',
            'operation'        => $operation,
            'ok'               => false,
            'code'             => $code,
        );
    }

    /**
     * Validate a SHA-256 hex value.
     *
     * @param mixed $value Value.
     * @return bool
     */
    private function hash_value( $value ) {
        return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $value );
    }

    /**
     * Validate an exact key set without requiring JSON object order.
     *
     * @param mixed $value Value.
     * @param array $keys  Keys.
     * @return bool
     */
    private function exact_keys( $value, $keys ) {
        if ( ! is_array( $value ) || count( $value ) !== count( $keys ) ) {
            return false;
        }
        foreach ( $keys as $key ) {
            if ( ! array_key_exists( $key, $value ) ) {
                return false;
            }
        }
        return true;
    }
}
