<?php
/**
 * MainWP Child Woocomerce Status
 *
 * MainWP WooCommerce Status Extension handler.
 *
 * @link https://mainwp.com/extension/woocommerce-status/
 *
 * @package MainWP\Child
 *
 * Credits
 *
 * Plugin-Name: WooCommerce
 * Plugin URI: https://woocommerce.com/
 * Author: Automattic
 * Author URI: https://woocommerce.com
 */

namespace MainWP\Child;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable PSR1.Classes.ClassDeclaration, WordPress.WP.AlternativeFunctions -- Required to achieve desired results, pull request solutions appreciated.

/**
 * Class MainWP_Child_WooCommerce_Status
 *
 * MainWP WooCommerce Status Extension handler.
 */
class MainWP_Child_WooCommerce_Status {

    /**
     * Public static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    public static $instance = null;

    /**
     * Inventory counts captured once per request.
     *
     * The generation hash and the page body must describe the same inventory read;
     * a second read inside one request would let a page contradict its own generation.
     *
     * @var array<string,mixed>|false|null
     */
    private $abilities_v2_inventory_memo = null;

    /**
     * Largest number of database-update receipts the store keeps.
     *
     * @var int
     */
    private const ABILITIES_V2_MAX_DB_RECEIPTS = 100;

    /**
     * Method instance()
     *
     * Create a public static instance.
     *
     * @return mixed Class instance.
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }

        return static::$instance;
    }

    /**
     * MainWP_Child_WooCommerce_Status constructor.
     *
     * Run any time class is called.
     */
    public function __construct() {
        add_action( 'mainwp_child_deactivation', array( $this, 'child_deactivation' ) );
    }

    /**
     * MainWP Child Plugin deactivation hooks.
     */
    public function child_deactivation() {
    }

    /**
     * MainWP Child Woocommerce actions: sync_data, report_data, update_wc_db.
     *
     * @uses \MainWP\Child\MainWP_Helper::write()
     */
    public function action() {
        $information = array();
        $mwp_action  = MainWP_System::instance()->validate_params( 'mwp_action' );

        if ( 'abilities_v2' === $mwp_action ) {
            // phpcs:disable WordPress.Security.NonceVerification
            $raw_request = isset( $_POST['request'] ) && is_string( $_POST['request'] ) ? wp_unslash( $_POST['request'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Closed JSON validation follows.
            // phpcs:enable
            $request = 2097152 >= strlen( $raw_request ) ? json_decode( $raw_request, true ) : null;
            MainWP_Helper::write( $this->abilities_v2( $request ) );
            return;
        }

        if ( ! class_exists( '\WooCommerce' ) || ! defined( 'WC_VERSION' ) ) {
            $information['error'] = 'NO_WOOCOMMERCE';
            MainWP_Helper::write( $information );
            return;
        }

        $is_ver220 = $this->is_version_220();
        if ( ! empty( $mwp_action ) ) {
            switch ( $mwp_action ) {
                case 'sync_data':
                    $include_weekly_sales = '1' === MainWP_System::instance()->validate_params( 'include_last_7_days_sales', '0' );
                    $information          = ! $is_ver220 ? $this->sync_data( $include_weekly_sales ) : $this->sync_data_two( $include_weekly_sales );
                    break;
                case 'report_data':
                    $information = ! $is_ver220 ? $this->report_data() : $this->report_data_two();
                    break;
                case 'update_wc_db':
                    $information = $this->update_wc_db();
                    break;
                default:
                    break;
            }
        }
        MainWP_Helper::write( $information );
    }

    /**
     * Execute one closed WooCommerce Status protocol-v2 request.
     *
     * @param mixed $request Decoded request object.
     * @return array<string,mixed> Closed response.
     */
    public function abilities_v2( $request ) {
        $operation = is_array( $request ) && isset( $request['operation'] ) && is_string( $request['operation'] ) ? $request['operation'] : 'unknown';
        if ( ! is_array( $request ) || ! $this->abilities_v2_exact_keys( $request, array( 'protocol', 'operation', 'payload' ) ) || '2' !== $request['protocol'] || ! is_array( $request['payload'] ) ) {
            return $this->abilities_v2_error( 'unknown', 'invalid_request' );
        }

        if ( 'capabilities' === $operation ) {
            if ( array() !== $request['payload'] ) {
                return $this->abilities_v2_error( $operation, 'invalid_request' );
            }
            return array(
                'protocol'           => '2',
                'operation'          => 'capabilities',
                'ok'                 => true,
                'operations'         => array( 'status_v2_prepare', 'status_v2_page', 'db_update_v2_prepare', 'db_update_v2_start', 'db_update_v2_status' ),
                'mutation_supported' => true,
            );
        }

        if ( ! in_array( $operation, array( 'status_v2_prepare', 'status_v2_page', 'db_update_v2_prepare', 'db_update_v2_start', 'db_update_v2_status' ), true ) ) {
            return $this->abilities_v2_error( $operation, 'invalid_request' );
        }

        $runtime = $this->abilities_v2_runtime();
        if ( false === $runtime ) {
            return $this->abilities_v2_error( $operation, 'woocommerce_unavailable' );
        }
        if ( ! $this->abilities_v2_valid_runtime( $runtime ) ) {
            return $this->abilities_v2_error( $operation, 'runtime_invalid' );
        }

        if ( 'status_v2_page' === $operation ) {
            return $this->abilities_v2_status_page( $request['payload'], $runtime );
        }
        if ( 'db_update_v2_prepare' === $operation ) {
            return $this->abilities_v2_db_prepare( $request['payload'], $runtime );
        }
        if ( 'db_update_v2_start' === $operation ) {
            return $this->abilities_v2_db_start( $request['payload'], $runtime );
        }
        if ( 'db_update_v2_status' === $operation ) {
            return $this->abilities_v2_db_status( $request['payload'], $runtime );
        }
        if ( ! $this->abilities_v2_valid_prepare_request( $request['payload'] ) ) {
            return $this->abilities_v2_error( $operation, 'invalid_request' );
        }

        $source_generation = $this->abilities_v2_source_generation( $request['payload'] );
        if ( false === $source_generation ) {
            return $this->abilities_v2_error( $operation, 'partial_result' );
        }

        return array(
            'protocol'               => '2',
            'operation'              => 'status_v2_prepare',
            'ok'                     => true,
            'request_ref'            => $request['payload']['request_ref'],
            'site_fingerprint'       => $request['payload']['site_fingerprint'],
            'start_at'               => $request['payload']['start_at'],
            'end_at'                 => $request['payload']['end_at'],
            'top_limit'              => $request['payload']['top_limit'],
            'wc_version'             => $runtime['wc_version'],
            'storage_mode'           => $runtime['storage_mode'],
            'store_timezone'         => $runtime['store_timezone'],
            'current_db_version'     => $runtime['current_db_version'],
            'target_db_version'      => $runtime['target_db_version'],
            'database_update_needed' => $runtime['database_update_needed'],
            'accounting_profile'     => 'net-order-total-v1',
            'page_size_max'          => 250,
            'order_limit'            => 100000,
            'preparation_generation' => hash( 'sha256', wp_json_encode( array( $request['payload'], $runtime, $source_generation ) ) ),
        );
    }

    /**
     * Execute one bounded immutable status page.
     *
     * @param array $payload Request payload.
     * @param array $runtime Runtime identity.
     * @return array
     */
    private function abilities_v2_status_page( $payload, $runtime ) {
        $keys = array( 'request_ref', 'site_fingerprint', 'start_at', 'end_at', 'top_limit', 'preparation_generation', 'cursor', 'page_size' );
        if ( ! $this->abilities_v2_exact_keys( $payload, $keys ) || ! $this->abilities_v2_valid_prepare_request( array_intersect_key( $payload, array_flip( array( 'request_ref', 'site_fingerprint', 'start_at', 'end_at', 'top_limit' ) ) ) ) || ! $this->abilities_v2_digest( $payload['preparation_generation'] ) || ( null !== $payload['cursor'] && ( ! is_int( $payload['cursor'] ) || 0 > $payload['cursor'] || 100000 <= $payload['cursor'] ) ) || ! is_int( $payload['page_size'] ) || 1 > $payload['page_size'] || 250 < $payload['page_size'] ) {
            return $this->abilities_v2_error( 'status_v2_page', 'invalid_request' );
        }
        $prepare_payload   = array_intersect_key( $payload, array_flip( array( 'request_ref', 'site_fingerprint', 'start_at', 'end_at', 'top_limit' ) ) );
        $source_generation = $this->abilities_v2_source_generation( $prepare_payload );
        if ( false === $source_generation ) {
            return $this->abilities_v2_error( 'status_v2_page', 'partial_result' );
        }
        $expected = hash( 'sha256', wp_json_encode( array( $prepare_payload, $runtime, $source_generation ) ) );
        if ( ! hash_equals( $expected, $payload['preparation_generation'] ) ) {
            return $this->abilities_v2_error( 'status_v2_page', 'preparation_drift' );
        }

        $page = $this->abilities_v2_order_page( $payload, $runtime );
        if ( ! is_array( $page ) || ! $this->abilities_v2_valid_page_data( $page, $payload ) ) {
            return $this->abilities_v2_error( 'status_v2_page', 'partial_result' );
        }
        $response                  = array_merge(
            array(
                'protocol'               => '2',
                'operation'              => 'status_v2_page',
                'ok'                     => true,
                'request_ref'            => $payload['request_ref'],
                'site_fingerprint'       => $payload['site_fingerprint'],
                'preparation_generation' => $payload['preparation_generation'],
                'cursor'                 => $payload['cursor'],
            ),
            $page
        );
        $response['response_hash'] = hash( 'sha256', wp_json_encode( $response ) );
        $encoded                   = wp_json_encode( $response );
        if ( ! is_string( $encoded ) || 2097152 < strlen( $encoded ) ) {
            return $this->abilities_v2_error( 'status_v2_page', 'response_too_large' );
        }
        return $response;
    }

    /**
     * Prepare one generation-bound database update.
     *
     * @param array $payload Request payload.
     * @param array $runtime Runtime identity.
     * @return array
     */
    private function abilities_v2_db_prepare( $payload, $runtime ) {
        if ( ! $this->abilities_v2_valid_identity_request( $payload ) ) {
            return $this->abilities_v2_error( 'db_update_v2_prepare', 'invalid_request' );
        }
        $readiness = $this->abilities_v2_db_readiness( $runtime );
        if ( ! is_array( $readiness ) ) {
            return $this->abilities_v2_error( 'db_update_v2_prepare', 'readiness_unavailable' );
        }
        $binding = array(
            'current_version'        => $runtime['current_db_version'],
            'target_version'         => $runtime['target_db_version'],
            'pending_callback_count' => count( $readiness['callback_hashes'] ),
            'callback_hashes'        => $readiness['callback_hashes'],
            'conflict_hashes'        => $readiness['conflict_hashes'],
            'state'                  => $readiness['state'],
        );
        return array(
            'protocol'               => '2',
            'operation'              => 'db_update_v2_prepare',
            'ok'                     => true,
            'request_ref'            => $payload['request_ref'],
            'site_fingerprint'       => $payload['site_fingerprint'],
            'current_version'        => $binding['current_version'],
            'target_version'         => $binding['target_version'],
            'pending_callback_count' => $binding['pending_callback_count'],
            'callback_hashes'        => $binding['callback_hashes'],
            'conflict_hashes'        => $binding['conflict_hashes'],
            'state'                  => $binding['state'],
            'readiness_generation'   => hash( 'sha256', wp_json_encode( $binding ) ),
        );
    }

    /**
     * Start one exact database update once and persist its receipt.
     *
     * @param array $payload Request payload.
     * @param array $runtime Runtime identity.
     * @return array
     */
    private function abilities_v2_db_start( $payload, $runtime ) {
        $keys = array( 'request_ref', 'site_fingerprint', 'current_version', 'target_version', 'readiness_generation' );
        if ( ! $this->abilities_v2_exact_keys( $payload, $keys ) || ! $this->abilities_v2_valid_identity_request( array_intersect_key( $payload, array_flip( array( 'request_ref', 'site_fingerprint' ) ) ) ) || ! $this->abilities_v2_string( $payload['current_version'], 100 ) || ! $this->abilities_v2_string( $payload['target_version'], 100 ) || ! $this->abilities_v2_digest( $payload['readiness_generation'] ) ) {
            return $this->abilities_v2_error( 'db_update_v2_start', 'invalid_request' );
        }
        $prior = $this->abilities_v2_db_receipt( $payload['request_ref'] );
        if ( is_array( $prior ) ) {
            if ( ! $this->abilities_v2_valid_db_receipt( $prior, $payload ) ) {
                return $this->abilities_v2_error( 'db_update_v2_start', 'storage_unavailable' );
            }
            return hash_equals( $prior['request_hash'], hash( 'sha256', wp_json_encode( $payload ) ) ) ? $prior['response'] : $this->abilities_v2_error( 'db_update_v2_start', 'request_conflict' );
        }
        if ( false === $prior ) {
            return $this->abilities_v2_error( 'db_update_v2_start', 'storage_unavailable' );
        }
        $fresh = $this->abilities_v2_db_prepare( array_intersect_key( $payload, array_flip( array( 'request_ref', 'site_fingerprint' ) ) ), $runtime );
        if ( true !== $fresh['ok'] || ! hash_equals( $payload['readiness_generation'], $fresh['readiness_generation'] ) || $payload['current_version'] !== $fresh['current_version'] || $payload['target_version'] !== $fresh['target_version'] ) {
            return $this->abilities_v2_error( 'db_update_v2_start', 'readiness_drift' );
        }
        if ( 'conflict' === $fresh['state'] ) {
            return $this->abilities_v2_error( 'db_update_v2_start', 'update_conflict' );
        }
        if ( ! $this->abilities_v2_acquire_db_lease( $payload['request_ref'] ) && ( ! $this->abilities_v2_release_terminal_db_lease( $runtime ) || ! $this->abilities_v2_acquire_db_lease( $payload['request_ref'] ) ) ) {
            return $this->abilities_v2_error( 'db_update_v2_start', 'lease_conflict' );
        }
        $started = 'current' === $fresh['state'] ? 0 : $this->abilities_v2_start_db_update();
        if ( false === $started ) {
            $this->abilities_v2_release_db_lease( $payload['request_ref'] );
            return $this->abilities_v2_error( 'db_update_v2_start', 'update_failed' );
        }
        $state = 'current' === $fresh['state'] ? 'completed' : ( 0 < $started ? 'requested' : 'reconciliation_required' );
        if ( 'completed' === $state ) {
            // Nothing was queued, so the lease guards no work. Release it here, on the
            // mutation path, instead of from the status read.
            $this->abilities_v2_release_db_lease( $payload['request_ref'] );
        }
        $response = array(
            'protocol'         => '2',
            'operation'        => 'db_update_v2_start',
            'ok'               => true,
            'request_ref'      => $payload['request_ref'],
            'site_fingerprint' => $payload['site_fingerprint'],
            'current_version'  => $runtime['current_db_version'],
            'target_version'   => $runtime['target_db_version'],
            'queued_callbacks' => $started,
            'state'            => $state,
        );
        if ( ! $this->abilities_v2_store_db_receipt( $payload['request_ref'], $payload, $response ) ) {
            return $this->abilities_v2_error( 'db_update_v2_start', 'outcome_unknown' );
        }
        return $response;
    }

    /**
     * Read truthful database-update status without advancing work.
     *
     * @param array $payload Request payload.
     * @param array $runtime Runtime identity.
     * @return array
     */
    private function abilities_v2_db_status( $payload, $runtime ) {
        if ( ! $this->abilities_v2_valid_identity_request( $payload ) ) {
            return $this->abilities_v2_error( 'db_update_v2_status', 'invalid_request' );
        }
        $receipt = $this->abilities_v2_db_receipt( $payload['request_ref'] );
        if ( ! is_array( $receipt ) || ! $this->abilities_v2_valid_db_receipt( $receipt, null ) || ! hash_equals( $receipt['response']['site_fingerprint'], $payload['site_fingerprint'] ) ) {
            return $this->abilities_v2_error( 'db_update_v2_status', null === $receipt ? 'not_found' : 'storage_unavailable' );
        }
        $readiness = $this->abilities_v2_db_readiness( $runtime );
        if ( ! is_array( $readiness ) ) {
            return $this->abilities_v2_error( 'db_update_v2_status', 'readiness_unavailable' );
        }
        // WooCommerce's background updater only stamps woocommerce_db_version once the queue
        // drains, so version parity plus an empty callback set is the evidence of completion.
        if ( $runtime['current_db_version'] === $runtime['target_db_version'] && array() === $readiness['callback_hashes'] ) {
            $state = 'completed';
        } elseif ( array() !== $readiness['conflict_hashes'] ) {
            $state = 'reconciliation_required';
        } elseif ( array() !== $readiness['callback_hashes'] ) {
            $state = 'running';
        } else {
            $state = 'unknown';
        }
        return array(
            'protocol'               => '2',
            'operation'              => 'db_update_v2_status',
            'ok'                     => true,
            'request_ref'            => $payload['request_ref'],
            'site_fingerprint'       => $payload['site_fingerprint'],
            'current_version'        => $runtime['current_db_version'],
            'target_version'         => $runtime['target_db_version'],
            'pending_callback_count' => count( $readiness['callback_hashes'] ),
            'state'                  => $state,
            'observed_at'            => gmdate( 'Y-m-d\TH:i:s\Z' ),
        );
    }

    /**
     * Read non-secret WooCommerce runtime identity without querying orders.
     *
     * @return array<string,mixed>|false
     */
    protected function abilities_v2_runtime() {
        if ( ! class_exists( '\WooCommerce' ) || ! defined( 'WC_VERSION' ) ) {
            return false;
        }

        $timezone = wp_timezone_string();
        if ( ! is_string( $timezone ) || ! in_array( $timezone, timezone_identifiers_list(), true ) ) {
            return false;
        }

        $current_db = get_option( 'woocommerce_db_version', '' );
        $target_db  = WC_VERSION;
        if ( ! is_string( $current_db ) || ! is_string( $target_db ) || '' === $current_db || '' === $target_db ) {
            return false;
        }

        $storage_mode = 'legacy';
        if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && method_exists( '\Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled' ) ) {
            $storage_mode = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'hpos' : 'legacy';
        }

        return array(
            'wc_version'             => WC_VERSION,
            'storage_mode'           => $storage_mode,
            'store_timezone'         => $timezone,
            'current_db_version'     => $current_db,
            'target_db_version'      => $target_db,
            'database_update_needed' => version_compare( $current_db, $target_db, '<' ),
        );
    }

    /**
     * Query and aggregate one WooCommerce CRUD page.
     *
     * @param array $payload Request payload.
     * @param array $runtime Runtime identity.
     * @return array|false
     */
    protected function abilities_v2_order_page( $payload, $runtime ) { // phpcs:ignore -- Closed bounded provider adapter.
        $offset = null === $payload['cursor'] ? 0 : $payload['cursor'];
        $start  = $this->abilities_v2_utc_timestamp( $payload['start_at'] );
        $end    = $this->abilities_v2_utc_timestamp( $payload['end_at'] );
        $result = $this->abilities_v2_query_orders(
            array(
                'status'       => array( 'wc-completed', 'wc-processing', 'wc-on-hold' ),
                'date_created' => $start . '...' . ( $end - 1 ),
                'limit'        => $payload['page_size'],
                'offset'       => $offset,
                'orderby'      => 'ID',
                'order'        => 'ASC',
                'paginate'     => true,
                'return'       => 'objects',
            )
        );
        if ( ! is_object( $result ) || ! isset( $result->orders, $result->total ) || ! is_array( $result->orders ) || ! is_int( $result->total ) || 0 > $result->total || 100000 < $result->total || count( $result->orders ) > $payload['page_size'] ) {
            return false;
        }

        $currency_totals = array();
        $sellers         = array();
        $string_bytes    = 0;
        foreach ( $result->orders as $order ) {
            if ( ! is_object( $order ) || ! method_exists( $order, 'get_currency' ) || ! method_exists( $order, 'get_total' ) || ! method_exists( $order, 'get_total_refunded' ) || ! method_exists( $order, 'get_items' ) ) {
                return false;
            }
            $currency = strtoupper( (string) $order->get_currency() );
            $decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
            if ( 1 !== preg_match( '/^[A-Z]{3}$/D', $currency ) || ! is_int( $decimals ) || 0 > $decimals || 8 < $decimals ) {
                return false;
            }
            $total    = $this->abilities_v2_decimal_to_minor( (string) $order->get_total(), $decimals );
            $refunded = $this->abilities_v2_decimal_to_minor( (string) $order->get_total_refunded(), $decimals );
            if ( false === $total || false === $refunded ) {
                return false;
            }
            if ( ! isset( $currency_totals[ $currency ] ) ) {
                if ( 20 <= count( $currency_totals ) ) {
                    return false;
                }
                $currency_totals[ $currency ] = array(
                    'currency'        => $currency,
                    'minor_unit'      => $decimals,
                    'net_sales_minor' => '0',
                );
            } elseif ( $currency_totals[ $currency ]['minor_unit'] !== $decimals ) {
                return false;
            }
            $net = $this->abilities_v2_integer_subtract( $total, $refunded );
            $currency_totals[ $currency ]['net_sales_minor'] = $this->abilities_v2_integer_add( $currency_totals[ $currency ]['net_sales_minor'], $net );

            foreach ( $order->get_items( 'line_item' ) as $item_key => $item ) {
                if ( ! is_object( $item ) || ! method_exists( $item, 'get_product_id' ) || ! method_exists( $item, 'get_name' ) || ! method_exists( $item, 'get_quantity' ) ) {
                    return false;
                }
                $product_id = (int) $item->get_product_id();
                $name       = (string) $item->get_name();
                $quantity   = $this->abilities_v2_canonical_integer( $item->get_quantity(), 20 );
                $refund     = method_exists( $order, 'get_qty_refunded_for_item' ) ? $this->abilities_v2_canonical_integer( $order->get_qty_refunded_for_item( $item_key ), 20 ) : '0';
                if ( 1 > $product_id || false === $quantity || false === $refund || 500 < strlen( $name ) || wp_check_invalid_utf8( $name, true ) !== $name || 1 === preg_match( '/[\x00-\x1F\x7F]/', $name ) ) {
                    return false;
                }
                $string_bytes += strlen( $name );
                if ( 65536 < $string_bytes ) {
                    return false;
                }
                if ( ! isset( $sellers[ $product_id ] ) ) {
                    $sellers[ $product_id ] = array(
                        'product_id'   => $product_id,
                        'name'         => $name,
                        'net_quantity' => '0',
                    );
                } elseif ( $sellers[ $product_id ]['name'] !== $name ) {
                    return false;
                }
                $sellers[ $product_id ]['net_quantity'] = $this->abilities_v2_integer_add( $sellers[ $product_id ]['net_quantity'], $this->abilities_v2_integer_add( $quantity, $refund ) );
            }
        }

        ksort( $currency_totals, SORT_STRING );
        uasort(
            $sellers,
            function ( $left, $right ) {
                $compare = $this->abilities_v2_integer_compare( $right['net_quantity'], $left['net_quantity'] );
                return 0 !== $compare ? $compare : $left['product_id'] <=> $right['product_id'];
            }
        );
        $inventory = $this->abilities_v2_inventory_snapshot();
        if ( ! is_array( $inventory ) ) {
            return false;
        }
        $returned = count( $result->orders );
        $complete = $offset + $returned >= $result->total;
        return array(
            'next_cursor'           => $complete ? null : $offset + $returned,
            'complete'              => $complete,
            'page_size'             => $payload['page_size'],
            'order_count'           => $returned,
            'total_orders'          => $result->total,
            'currency_totals'       => array_values( $currency_totals ),
            'top_sellers'           => array_slice( array_values( $sellers ), 0, $payload['top_limit'] ),
            'processing_orders'     => $inventory['processing_orders'],
            'on_hold_orders'        => $inventory['on_hold_orders'],
            'low_stock'             => $inventory['low_stock'],
            'out_of_stock'          => $inventory['out_of_stock'],
            'inventory_observed_at' => $inventory['inventory_observed_at'],
            'generated_at'          => gmdate( 'Y-m-d\TH:i:s\Z' ),
            'source'                => 'woocommerce_crud',
            'storage_mode'          => $runtime['storage_mode'],
            'accounting_profile'    => 'net-order-total-v1',
        );
    }

    /**
     * Bind every page to a stable order-set and inventory generation without exposing order data.
     *
     * @param array $payload Closed report identity.
     * @return string|false
     */
    protected function abilities_v2_source_generation( $payload ) {
        $inventory = $this->abilities_v2_inventory_snapshot();
        if ( ! is_array( $inventory ) ) {
            return false;
        }
        $start  = $this->abilities_v2_utc_timestamp( $payload['start_at'] );
        $end    = $this->abilities_v2_utc_timestamp( $payload['end_at'] );
        $result = $this->abilities_v2_query_orders(
            array(
                'status'       => array( 'wc-completed', 'wc-processing', 'wc-on-hold' ),
                'date_created' => $start . '...' . ( $end - 1 ),
                'limit'        => 1,
                'orderby'      => 'modified',
                'order'        => 'DESC',
                'paginate'     => true,
                'return'       => 'objects',
            )
        );
        if ( ! is_object( $result ) || ! isset( $result->orders, $result->total ) || ! is_array( $result->orders ) || ! is_int( $result->total ) || 0 > $result->total || 100000 < $result->total || 1 < count( $result->orders ) ) {
            return false;
        }
        $latest        = empty( $result->orders ) ? null : reset( $result->orders );
        $order_binding = array( 'empty', 0, 0 );
        if ( null !== $latest ) {
            if ( ! is_object( $latest ) || ! method_exists( $latest, 'get_id' ) || ! method_exists( $latest, 'get_date_modified' ) ) {
                return false;
            }
            $modified = $latest->get_date_modified();
            $order_id = (int) $latest->get_id();
            if ( 1 > $order_id || ! is_object( $modified ) || ! method_exists( $modified, 'getTimestamp' ) ) {
                return false;
            }
            $order_binding = array( $result->total, $order_id, (int) $modified->getTimestamp() );
        }

        return hash(
            'sha256',
            wp_json_encode(
                array(
                    $order_binding,
                    // The inventory counts ride in the generation so a stock or order-state
                    // change between pages surfaces as preparation_drift instead of two pages
                    // of one generation quietly disagreeing.
                    array( $inventory['processing_orders'], $inventory['on_hold_orders'], $inventory['low_stock'], $inventory['out_of_stock'] ),
                )
            )
        );
    }

    /**
     * Run one bounded WooCommerce order query.
     *
     * @param array $args Query arguments.
     * @return object|false
     */
    protected function abilities_v2_query_orders( $args ) {
        if ( ! function_exists( 'wc_get_orders' ) ) {
            return false;
        }
        return wc_get_orders( $args );
    }

    /** Read the request's single inventory capture. */
    private function abilities_v2_inventory_snapshot() {
        if ( null === $this->abilities_v2_inventory_memo ) {
            $this->abilities_v2_inventory_memo = $this->abilities_v2_inventory_counts();
        }
        return $this->abilities_v2_inventory_memo;
    }

    /** Read separately timestamped order and stock counts. */
    protected function abilities_v2_inventory_counts() {
        if ( ! function_exists( 'wc_get_products' ) ) {
            return false;
        }
        $processing = $this->abilities_v2_query_orders(
            array(
                'status'   => 'wc-processing',
                'limit'    => 1,
                'paginate' => true,
                'return'   => 'ids',
            )
        );
        $on_hold    = $this->abilities_v2_query_orders(
            array(
                'status'   => 'wc-on-hold',
                'limit'    => 1,
                'paginate' => true,
                'return'   => 'ids',
            )
        );
        $out        = wc_get_products(
            array(
                'stock_status' => 'outofstock',
                'limit'        => 1,
                'paginate'     => true,
                'return'       => 'ids',
            )
        );
        foreach ( array( $processing, $on_hold, $out ) as $counted ) {
            if ( ! is_object( $counted ) || ! isset( $counted->total ) || ! is_int( $counted->total ) || 0 > $counted->total || 100000 < $counted->total ) {
                return false;
            }
        }
        $low = $this->abilities_v2_low_stock_count();
        if ( false === $low ) {
            return false;
        }
        return array(
            'processing_orders'     => $processing->total,
            'on_hold_orders'        => $on_hold->total,
            'low_stock'             => $low,
            'out_of_stock'          => $out->total,
            'inventory_observed_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
        );
    }

    /**
     * Count stock-managed products sitting at or below the store's low-stock threshold.
     *
     * Mirrors the v1 sync figure: WooCommerce has no low-stock query var, and its
     * 'onbackorder' stock status counts a different thing entirely - stores that never
     * enable backorders have none, which is how v2 came to report zero.
     *
     * @return int|false
     */
    protected function abilities_v2_low_stock_count() {
        global $wpdb;

        $low     = absint( max( get_option( 'woocommerce_notify_low_stock_amount' ), 1 ) );
        $no      = absint( max( get_option( 'woocommerce_notify_no_stock_amount' ), 0 ) );
        $counted = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded count with no cacheable identity.
            $wpdb->prepare(
                "SELECT COUNT( DISTINCT posts.ID )
                FROM {$wpdb->posts} AS posts
                INNER JOIN {$wpdb->postmeta} AS stock ON posts.ID = stock.post_id AND stock.meta_key = '_stock'
                LEFT JOIN {$wpdb->postmeta} AS managed ON posts.ID = managed.post_id AND managed.meta_key = '_manage_stock'
                WHERE posts.post_type IN ( 'product', 'product_variation' )
                AND posts.post_status = 'publish'
                AND stock.meta_value != ''
                AND CAST( stock.meta_value AS SIGNED ) <= %d
                AND CAST( stock.meta_value AS SIGNED ) > %d
                AND ( managed.meta_value = 'yes' OR posts.post_type = 'product_variation' )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only table names are interpolated.
                $low,
                $no
            )
        );

        // Same ceiling the page validator enforces on these counts: a tighter one here would make
        // every status request on a store between the two bounds fail instead of report.
        return null === $counted || 100000 < (int) $counted ? false : (int) $counted;
    }

    /**
     * Validate one internal page before it becomes a response.
     *
     * @param array $page    Page data.
     * @param array $payload Request payload.
     * @return bool
     */
    private function abilities_v2_valid_page_data( $page, $payload ) {
        $keys = array( 'next_cursor', 'complete', 'page_size', 'order_count', 'total_orders', 'currency_totals', 'top_sellers', 'processing_orders', 'on_hold_orders', 'low_stock', 'out_of_stock', 'inventory_observed_at', 'generated_at', 'source', 'storage_mode', 'accounting_profile' );
        if ( ! $this->abilities_v2_exact_keys( $page, $keys ) || ! is_bool( $page['complete'] ) || $page['page_size'] !== $payload['page_size'] || ! is_int( $page['order_count'] ) || 0 > $page['order_count'] || $payload['page_size'] < $page['order_count'] || ! is_int( $page['total_orders'] ) || 0 > $page['total_orders'] || 100000 < $page['total_orders'] || ( $page['complete'] && null !== $page['next_cursor'] ) || ( ! $page['complete'] && ( ! is_int( $page['next_cursor'] ) || 1 > $page['next_cursor'] || 100000 < $page['next_cursor'] ) ) || ! is_array( $page['currency_totals'] ) || 20 < count( $page['currency_totals'] ) || ! is_array( $page['top_sellers'] ) || $payload['top_limit'] < count( $page['top_sellers'] ) || 'woocommerce_crud' !== $page['source'] || 'net-order-total-v1' !== $page['accounting_profile'] || ! in_array( $page['storage_mode'], array( 'hpos', 'legacy' ), true ) ) {
            return false;
        }
        foreach ( array( 'processing_orders', 'on_hold_orders', 'low_stock', 'out_of_stock' ) as $field ) {
            if ( ! is_int( $page[ $field ] ) || 0 > $page[ $field ] || 100000 < $page[ $field ] ) {
                return false;
            }
        }
        foreach ( $page['currency_totals'] as $total ) {
            if ( ! $this->abilities_v2_exact_keys( $total, array( 'currency', 'minor_unit', 'net_sales_minor' ) ) || ! is_string( $total['currency'] ) || 1 !== preg_match( '/^[A-Z]{3}$/D', $total['currency'] ) || ! is_int( $total['minor_unit'] ) || 0 > $total['minor_unit'] || 8 < $total['minor_unit'] || false === $this->abilities_v2_canonical_integer( $total['net_sales_minor'], 40 ) ) {
                return false;
            }
        }
        foreach ( $page['top_sellers'] as $seller ) {
            if ( ! $this->abilities_v2_exact_keys( $seller, array( 'product_id', 'name', 'net_quantity' ) ) || ! is_int( $seller['product_id'] ) || 1 > $seller['product_id'] || ! is_string( $seller['name'] ) || 500 < strlen( $seller['name'] ) || false === $this->abilities_v2_canonical_integer( $seller['net_quantity'], 20 ) ) {
                return false;
            }
        }
        return is_string( $page['inventory_observed_at'] ) && false !== $this->abilities_v2_utc_timestamp( $page['inventory_observed_at'] ) && is_string( $page['generated_at'] ) && false !== $this->abilities_v2_utc_timestamp( $page['generated_at'] );
    }

    /**
     * Build redacted update readiness from WooCommerce callbacks and conflicts.
     *
     * @param array $runtime Runtime identity.
     * @return array|false
     */
    protected function abilities_v2_db_readiness( $runtime ) {
        if ( ! class_exists( '\WC_Install' ) || ! method_exists( '\WC_Install', 'get_db_update_callbacks' ) ) {
            return false;
        }
        $callbacks = array();
        foreach ( \WC_Install::get_db_update_callbacks() as $version => $names ) {
            if ( version_compare( $runtime['current_db_version'], (string) $version, '<' ) ) {
                foreach ( $names as $name ) {
                    if ( ! is_string( $name ) || '' === $name || 512 < strlen( $name ) || 10000 <= count( $callbacks ) ) {
                        return false;
                    }
                    $callbacks[] = hash( 'sha256', $name );
                }
            }
        }
        $conflicts = apply_filters( 'mainwp_child_woocommerce_db_update_conflicts', array() );
        if ( ! is_array( $conflicts ) || 20 < count( $conflicts ) ) {
            return false;
        }
        foreach ( $conflicts as &$conflict ) {
            if ( ! is_string( $conflict ) || '' === $conflict || 128 < strlen( $conflict ) ) {
                return false;
            }
            $conflict = hash( 'sha256', $conflict );
        }
        unset( $conflict );
        sort( $callbacks, SORT_STRING );
        sort( $conflicts, SORT_STRING );
        $state = array() !== $conflicts ? 'conflict' : ( $runtime['current_db_version'] === $runtime['target_db_version'] && array() === $callbacks ? 'current' : 'ready' );
        return array(
            'callback_hashes' => $callbacks,
            'conflict_hashes' => $conflicts,
            'state'           => $state,
        );
    }

    /** Queue the exact outstanding WooCommerce callbacks. */
    protected function abilities_v2_start_db_update() {
        if ( ! class_exists( '\WC_Install' ) || ! function_exists( 'WC' ) ) {
            return false;
        }
        include_once WC()->plugin_path() . '/includes/class-wc-background-updater.php'; // NOSONAR -- WooCommerce-owned updater.
        if ( ! class_exists( '\WC_Background_Updater' ) ) {
            return false;
        }
        $updater = new \WC_Background_Updater();
        $current = get_option( 'woocommerce_db_version', '' );
        $count   = 0;
        foreach ( \WC_Install::get_db_update_callbacks() as $version => $callbacks ) {
            if ( version_compare( $current, (string) $version, '<' ) ) {
                foreach ( $callbacks as $callback ) {
                    $updater->push_to_queue( $callback );
                    ++$count;
                }
            }
        }
        if ( 0 < $count ) {
            $updater->save()->dispatch();
        }
        return $count;
    }

    /**
     * Acquire one bounded database-update lease.
     *
     * @param string $request_ref Request reference.
     * @return bool
     */
    protected function abilities_v2_acquire_db_lease( $request_ref ) {
        $lease = get_option( 'mainwp_wc_status_db_update_v2_lease', null );
        $now   = time();
        if ( is_array( $lease ) && isset( $lease['request_ref'], $lease['expires_at'] ) && is_string( $lease['request_ref'] ) && is_int( $lease['expires_at'] ) && $lease['expires_at'] >= $now ) {
            return hash_equals( $lease['request_ref'], $request_ref );
        }
        if ( null !== $lease && false === delete_option( 'mainwp_wc_status_db_update_v2_lease' ) ) {
            return false;
        }
        $next = array(
            'request_ref' => $request_ref,
            'expires_at'  => $now + 3600,
        );
        return add_option( 'mainwp_wc_status_db_update_v2_lease', $next, '', false ) && get_option( 'mainwp_wc_status_db_update_v2_lease', null ) === $next;
    }

    /**
     * Release a live lease whose update is provably finished.
     *
     * A queued update holds its lease for an hour, and the status read must not write, so a
     * finished update would otherwise block the next real one for the rest of that hour.
     * WooCommerce stamps woocommerce_db_version only once the background queue drains, so the
     * site having reached the held lease's target version is proof that its work is over.
     * Anything short of that - no receipt, an unreadable one, one that belongs to a different
     * request, or a version still behind - is work the lease still guards, and the caller keeps
     * getting lease_conflict.
     *
     * @param array $runtime Runtime identity.
     * @return bool Whether a terminal lease was released.
     */
    protected function abilities_v2_release_terminal_db_lease( $runtime ) {
        $lease = get_option( 'mainwp_wc_status_db_update_v2_lease', null );
        if ( ! is_array( $lease ) || ! isset( $lease['request_ref'] ) || ! is_string( $lease['request_ref'] ) ) {
            return false;
        }
        $receipt = $this->abilities_v2_db_receipt( $lease['request_ref'] );
        // The stored option is untrusted, so the entry filed under the holder's key is only proof
        // about the holder if it says so itself. A receipt carrying someone else's request is
        // evidence about someone else's update, and cannot retire this lease.
        if ( ! is_array( $receipt ) || ! $this->abilities_v2_valid_db_receipt( $receipt, null ) || ! hash_equals( $lease['request_ref'], $receipt['response']['request_ref'] ) || ! version_compare( $runtime['current_db_version'], $receipt['response']['target_version'], '>=' ) ) {
            return false;
        }
        return $this->abilities_v2_release_db_lease( $lease['request_ref'] );
    }

    /**
     * Release only the caller's database-update lease.
     *
     * @param string $request_ref Request reference.
     * @return bool
     */
    protected function abilities_v2_release_db_lease( $request_ref ) {
        $lease = get_option( 'mainwp_wc_status_db_update_v2_lease', null );
        return ! is_array( $lease ) || ! isset( $lease['request_ref'] ) || ! is_string( $lease['request_ref'] ) || ! hash_equals( $lease['request_ref'], $request_ref ) || delete_option( 'mainwp_wc_status_db_update_v2_lease' );
    }

    /**
     * Read a database-update receipt.
     *
     * @param string $request_ref Request reference.
     * @return array|null|false
     */
    protected function abilities_v2_db_receipt( $request_ref ) {
        $receipts = get_option( 'mainwp_wc_status_db_update_v2_receipts', array() );
        return is_array( $receipts ) ? ( isset( $receipts[ $request_ref ] ) ? $receipts[ $request_ref ] : null ) : false;
    }

    /**
     * Persist a bounded database-update receipt with exact readback.
     *
     * @param string $request_ref Request reference.
     * @param array  $payload     Request payload.
     * @param array  $response    Response.
     * @return bool
     */
    protected function abilities_v2_store_db_receipt( $request_ref, $payload, $response ) {
        $receipts = get_option( 'mainwp_wc_status_db_update_v2_receipts', array() );
        if ( ! is_array( $receipts ) ) {
            return false;
        }
        // Room is made before the new receipt is written. Insertion-order trimming could drop a
        // receipt db_update_v2_status or the terminal-lease release still needs, so a store that
        // cannot free a slot refuses the write instead.
        $receipts = $this->abilities_v2_evict_db_receipts( $receipts );
        if ( false === $receipts ) {
            return false;
        }
        $receipts[ $request_ref ] = array(
            'request_hash' => hash( 'sha256', wp_json_encode( $payload ) ),
            'response'     => $response,
            'requested_at' => time(),
        );
        // An unchanged-value write returns false from update_option, so the readback is the
        // authority on whether the store now holds exactly these receipts.
        update_option( 'mainwp_wc_status_db_update_v2_receipts', $receipts, false );
        $readback = get_option( 'mainwp_wc_status_db_update_v2_receipts', null );
        return $readback === $receipts;
    }

    /**
     * Free one receipt slot without discarding an outcome a retry could still ask for.
     *
     * A malformed entry still gates db_update_v2_start with storage_unavailable, so it is evidence
     * a request_ref was used and cannot be dropped on an age it does not carry. Only entries whose
     * own requested_at proves them past the retry horizon are given up, oldest first, and a key no
     * request could ever present is dropped outright. A store that still cannot free a slot returns
     * false so the caller refuses the write rather than evict a live receipt.
     *
     * @param array $receipts Stored receipts.
     * @return array|false Receipts with room for one more, or false when no slot can be freed.
     */
    private function abilities_v2_evict_db_receipts( $receipts ) {
        if ( self::ABILITIES_V2_MAX_DB_RECEIPTS > count( $receipts ) ) {
            return $receipts;
        }
        $horizon   = time() - ( DAY_IN_SECONDS + 60 );
        $evictable = array();
        foreach ( $receipts as $reference => $receipt ) {
            if ( ! $this->abilities_v2_uuid( $reference ) ) {
                // No request can present a reference this handler would accept, so nothing will ever
                // come back for this entry. Array keys are not always strings either, so this is also
                // what stops a non-string key being read as a live reference.
                $evictable[ $reference ] = 0;
                continue;
            }
            $requested_at = is_array( $receipt ) && isset( $receipt['requested_at'] ) && is_int( $receipt['requested_at'] ) ? $receipt['requested_at'] : null;
            if ( null !== $requested_at && 0 < $requested_at && $requested_at < $horizon ) {
                $evictable[ $reference ] = $requested_at;
            }
        }
        asort( $evictable, SORT_NUMERIC );
        foreach ( array_keys( $evictable ) as $reference ) {
            if ( self::ABILITIES_V2_MAX_DB_RECEIPTS > count( $receipts ) ) {
                break;
            }
            unset( $receipts[ $reference ] );
        }
        return self::ABILITIES_V2_MAX_DB_RECEIPTS > count( $receipts ) ? $receipts : false;
    }

    /**
     * Validate one exact database-update receipt before use.
     *
     * @param array      $receipt Receipt.
     * @param array|null $payload Current request payload, if available.
     * @return bool
     */
    private function abilities_v2_valid_db_receipt( $receipt, $payload ) {
        if ( ! $this->abilities_v2_exact_keys( $receipt, array( 'request_hash', 'response', 'requested_at' ) ) || ! $this->abilities_v2_digest( $receipt['request_hash'] ) || ! is_int( $receipt['requested_at'] ) || 0 >= $receipt['requested_at'] || ! is_array( $receipt['response'] ) || ! $this->abilities_v2_exact_keys( $receipt['response'], array( 'protocol', 'operation', 'ok', 'request_ref', 'site_fingerprint', 'current_version', 'target_version', 'queued_callbacks', 'state' ) ) ) {
            return false;
        }
        $response = $receipt['response'];
        return '2' === $response['protocol']
            && 'db_update_v2_start' === $response['operation']
            && true === $response['ok']
            && $this->abilities_v2_uuid( $response['request_ref'] )
            && $this->abilities_v2_digest( $response['site_fingerprint'] )
            && $this->abilities_v2_string( $response['current_version'], 100 )
            && $this->abilities_v2_string( $response['target_version'], 100 )
            && is_int( $response['queued_callbacks'] )
            && 0 <= $response['queued_callbacks']
            && 10000 >= $response['queued_callbacks']
            && in_array( $response['state'], array( 'requested', 'completed', 'reconciliation_required' ), true )
            && ( null === $payload || ( $payload['request_ref'] === $response['request_ref'] && $payload['site_fingerprint'] === $response['site_fingerprint'] ) );
    }

    /**
     * Validate one request/site identity pair.
     *
     * @param array $payload Request payload.
     * @return bool
     */
    private function abilities_v2_valid_identity_request( $payload ) {
        return $this->abilities_v2_exact_keys( $payload, array( 'request_ref', 'site_fingerprint' ) ) && $this->abilities_v2_uuid( $payload['request_ref'] ) && $this->abilities_v2_digest( $payload['site_fingerprint'] );
    }

    /**
     * Validate a canonical UUID.
     *
     * @param mixed $value Value.
     * @return bool
     */
    private function abilities_v2_uuid( $value ) {
        return is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value );
    }

    /**
     * Validate a lowercase SHA-256 digest.
     *
     * @param mixed $value Value.
     * @return bool
     */
    private function abilities_v2_digest( $value ) {
        return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $value );
    }

    /**
     * Parse a decimal into an exact signed minor-unit string.
     *
     * @param mixed $value    Decimal value.
     * @param int   $decimals Minor-unit precision.
     * @return string|false
     */
    private function abilities_v2_decimal_to_minor( $value, $decimals ) {
        if ( ! is_string( $value ) || ! is_int( $decimals ) || 0 > $decimals || 8 < $decimals || 1 !== preg_match( '/^(-?)(0|[1-9][0-9]{0,30})(?:\.([0-9]{1,16}))?$/D', $value, $matches ) ) {
            return false;
        }
        $fraction = isset( $matches[3] ) ? $matches[3] : '';
        if ( $decimals < strlen( $fraction ) && 0 !== (int) substr( $fraction, $decimals ) ) {
            return false;
        }
        $digits = ltrim( $matches[2] . str_pad( substr( $fraction, 0, $decimals ), $decimals, '0' ), '0' );
        $digits = '' === $digits ? '0' : $digits;
        return '-' === $matches[1] && '0' !== $digits ? '-' . $digits : $digits;
    }

    /**
     * Normalize a signed integer scalar.
     *
     * @param mixed $value  Value.
     * @param int   $length Maximum digits.
     * @return string|false
     */
    private function abilities_v2_canonical_integer( $value, $length ) {
        if ( is_int( $value ) ) {
            $value = (string) $value;
        }
        return is_string( $value ) && 1 === preg_match( '/^-?(?:0|[1-9][0-9]{0,' . ( $length - 1 ) . '})$/D', $value ) ? $value : false;
    }

    /**
     * Add two canonical signed integer strings.
     *
     * @param string $left  Left operand.
     * @param string $right Right operand.
     * @return string
     */
    private function abilities_v2_integer_add( $left, $right ) {
        $left_negative  = '-' === substr( $left, 0, 1 );
        $right_negative = '-' === substr( $right, 0, 1 );
        $left_digits    = $left_negative ? substr( $left, 1 ) : $left;
        $right_digits   = $right_negative ? substr( $right, 1 ) : $right;
        if ( $left_negative === $right_negative ) {
            $sum = $this->abilities_v2_unsigned_add( $left_digits, $right_digits );
            return $left_negative && '0' !== $sum ? '-' . $sum : $sum;
        }
        $compare = $this->abilities_v2_unsigned_compare( $left_digits, $right_digits );
        if ( 0 === $compare ) {
            return '0';
        }
        $difference = 0 < $compare ? $this->abilities_v2_unsigned_subtract( $left_digits, $right_digits ) : $this->abilities_v2_unsigned_subtract( $right_digits, $left_digits );
        $negative   = 0 < $compare ? $left_negative : $right_negative;
        return $negative ? '-' . $difference : $difference;
    }

    /**
     * Subtract two canonical signed integer strings.
     *
     * @param string $left  Left operand.
     * @param string $right Right operand.
     * @return string
     */
    private function abilities_v2_integer_subtract( $left, $right ) {
        $right_negative = '-' === substr( $right, 0, 1 );
        return $this->abilities_v2_integer_add( $left, $right_negative ? substr( $right, 1 ) : ( '0' === $right ? '0' : '-' . $right ) );
    }

    /**
     * Compare signed integer strings.
     *
     * @param string $left  Left operand.
     * @param string $right Right operand.
     * @return int
     */
    private function abilities_v2_integer_compare( $left, $right ) {
        if ( $right === $left ) {
            return 0;
        }
        $left_negative  = '-' === substr( $left, 0, 1 );
        $right_negative = '-' === substr( $right, 0, 1 );
        if ( $left_negative !== $right_negative ) {
            return $left_negative ? -1 : 1;
        }
        $compare = $this->abilities_v2_unsigned_compare( ltrim( $left, '-' ), ltrim( $right, '-' ) );
        return $left_negative ? -$compare : $compare;
    }

    /**
     * Add unsigned digit strings.
     *
     * @param string $left  Left operand.
     * @param string $right Right operand.
     * @return string
     */
    private function abilities_v2_unsigned_add( $left, $right ) {
        $carry        = 0;
        $sum          = '';
        $left_length  = strlen( $left );
        $right_length = strlen( $right );
        $max_length   = max( $left_length, $right_length );
        for ( $i = 1; $i <= $max_length; ++$i ) {
            $digit = ( $i <= $left_length ? (int) $left[ $left_length - $i ] : 0 ) + ( $i <= $right_length ? (int) $right[ $right_length - $i ] : 0 ) + $carry;
            $sum   = (string) ( $digit % 10 ) . $sum;
            $carry = intdiv( $digit, 10 );
        }
        return ( 0 < $carry ? (string) $carry : '' ) . $sum;
    }

    /**
     * Compare unsigned canonical digit strings.
     *
     * @param string $left  Left operand.
     * @param string $right Right operand.
     * @return int
     */
    private function abilities_v2_unsigned_compare( $left, $right ) {
        return strlen( $left ) === strlen( $right ) ? strcmp( $left, $right ) : ( strlen( $left ) < strlen( $right ) ? -1 : 1 );
    }

    /**
     * Subtract the smaller unsigned digit string from the larger.
     *
     * @param string $larger  Larger operand.
     * @param string $smaller Smaller operand.
     * @return string
     */
    private function abilities_v2_unsigned_subtract( $larger, $smaller ) {
        $borrow         = 0;
        $result         = '';
        $larger_length  = strlen( $larger );
        $smaller_length = strlen( $smaller );
        for ( $i = 1; $i <= $larger_length; ++$i ) {
            $digit = (int) $larger[ $larger_length - $i ] - $borrow - ( $i <= $smaller_length ? (int) $smaller[ $smaller_length - $i ] : 0 );
            if ( 0 > $digit ) {
                $digit += 10;
                $borrow = 1;
            } else {
                $borrow = 0;
            }
            $result = (string) $digit . $result;
        }
        $result = ltrim( $result, '0' );
        return '' === $result ? '0' : $result;
    }

    /**
     * Validate a prepare request.
     *
     * @param array $payload Request payload.
     * @return bool
     */
    private function abilities_v2_valid_prepare_request( $payload ) {
        if ( ! $this->abilities_v2_exact_keys( $payload, array( 'request_ref', 'site_fingerprint', 'start_at', 'end_at', 'top_limit' ) ) || ! is_string( $payload['request_ref'] ) || 1 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $payload['request_ref'] ) || ! is_string( $payload['site_fingerprint'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $payload['site_fingerprint'] ) || ! is_int( $payload['top_limit'] ) || 1 > $payload['top_limit'] || 20 < $payload['top_limit'] ) {
            return false;
        }

        $start = $this->abilities_v2_utc_timestamp( $payload['start_at'] );
        $end   = $this->abilities_v2_utc_timestamp( $payload['end_at'] );

        return false !== $start && false !== $end && $start < $end && 366 * DAY_IN_SECONDS >= $end - $start;
    }

    /**
     * Parse one exact RFC3339 UTC timestamp.
     *
     * @param mixed $value Candidate timestamp.
     * @return int|false
     */
    private function abilities_v2_utc_timestamp( $value ) {
        if ( ! is_string( $value ) || 1 !== preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/D', $value ) ) {
            return false;
        }
        $date   = \DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone( 'UTC' ) );
        $errors = \DateTimeImmutable::getLastErrors();
        if ( false === $date || ( false !== $errors && ( 0 !== $errors['warning_count'] || 0 !== $errors['error_count'] ) ) || $date->format( 'Y-m-d\TH:i:s\Z' ) !== $value ) {
            return false;
        }

        return $date->getTimestamp();
    }

    /**
     * Validate a closed runtime identity.
     *
     * @param array $runtime Runtime identity.
     * @return bool
     */
    private function abilities_v2_valid_runtime( $runtime ) {
        return is_array( $runtime )
            && $this->abilities_v2_exact_keys( $runtime, array( 'wc_version', 'storage_mode', 'store_timezone', 'current_db_version', 'target_db_version', 'database_update_needed' ) )
            && $this->abilities_v2_string( $runtime['wc_version'], 100 )
            && in_array( $runtime['storage_mode'], array( 'hpos', 'legacy' ), true )
            && is_string( $runtime['store_timezone'] )
            && in_array( $runtime['store_timezone'], timezone_identifiers_list(), true )
            && $this->abilities_v2_string( $runtime['current_db_version'], 100 )
            && $this->abilities_v2_string( $runtime['target_db_version'], 100 )
            && is_bool( $runtime['database_update_needed'] );
    }

    /**
     * Validate a bounded non-control string.
     *
     * @param mixed $value  Candidate value.
     * @param int   $length Maximum byte length.
     * @return bool
     */
    private function abilities_v2_string( $value, $length ) {
        return is_string( $value ) && '' !== $value && $length >= strlen( $value ) && 0 === preg_match( '/[\x00-\x1F\x7F]/', $value );
    }

    /**
     * Check an exact associative-key set.
     *
     * @param array $value Input object.
     * @param array $keys  Expected keys.
     * @return bool
     */
    private function abilities_v2_exact_keys( $value, $keys ) {
        if ( ! is_array( $value ) ) {
            return false;
        }
        $actual = array_keys( $value );
        sort( $actual, SORT_STRING );
        sort( $keys, SORT_STRING );

        return $actual === $keys;
    }

    /**
     * Build a closed protocol error.
     *
     * @param string $operation Protocol operation.
     * @param string $code      Stable error code.
     * @return array<string,mixed>
     */
    private function abilities_v2_error( $operation, $code ) {
        return array(
            'protocol'  => '2',
            'operation' => $operation,
            'ok'        => false,
            'code'      => $code,
        );
    }

    /**
     * Compare woocommerce versions.
     *
     * By default, version_compare returns -1 if the first version is lower than the second,
     *  0 if they are equal, and 1 if the second is lower.
     *  When using the optional operator argument, the function will return true if the relationship is
     *  the one specified by the operator, false otherwise.
     *
     * @return bool|int Comparison response.
     */
    public function is_version_220() {
        return version_compare( WC()->version, '2.2.0', '>=' );
    }

    /**
     * Sync Woocommerce data.
     *
     * @param bool $include_last_7_days_sales Whether to include sales from the last seven days.
     *
     * @return array $information Woocommerce data grabed.
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     * @SuppressWarnings(PHPMD.LongVariable)
     */
    public function sync_data( $include_last_7_days_sales = false ) {

        /**
         * Object, providing access to the WordPress database.
         *
         * @global object $wpdb WordPress Database instance.
         */
        global $wpdb;

        $file = WP_PLUGIN_DIR . '/woocommerce/includes/admin/reports/class-wc-admin-report.php';
        if ( file_exists( $file ) ) {
            include_once $file; // NOSONAR -- WP compatible.
        } else {
            return false;
        }

        // Define allowed WooCommerce order statuses.
        $allowed_statuses = array( 'completed', 'processing', 'on-hold', 'refunded', 'cancelled', 'failed', 'pending' );

        // Apply the filter and validate against whitelist.
        $filtered_statuses = apply_filters( 'woocommerce_reports_order_statuses', array( 'completed', 'processing', 'on-hold' ) );
        $safe_statuses     = array_intersect( $filtered_statuses, $allowed_statuses );

        // Ensure at least one status exists, otherwise use default.
        if ( empty( $safe_statuses ) ) {
            $safe_statuses = array( 'completed' );
        }

        // Build dynamic placeholders for IN clause.
        $placeholders = implode( ',', array_fill( 0, count( $safe_statuses ), '%s' ) );

        // Prepare dates.
        $month_start = date( 'Y-m-01' ); // phpcs:ignore -- local time.
        $month_end   = date( 'Y-m-d H:i:s' ); // phpcs:ignore -- local time.

        // Generate cache key.
        $cache_key = 'wc_sales_' . md5( implode( '_', $safe_statuses ) . '_' . $month_start . '_' . $month_end ); //phpcs:ignore --NOSONAR --safe for key.

        // Try to get cached value.
        $sales = wp_cache_get( $cache_key, 'mainwp_woocommerce' );

        // If cache miss, execute the query and cache result.
        if ( false === $sales ) {
            $sales = $wpdb->get_var( //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- IN() list is array_fill('%s') placeholders; every value goes through prepare().
                $wpdb->prepare(
                    "SELECT SUM( postmeta.meta_value ) FROM {$wpdb->posts} as posts
                    LEFT JOIN {$wpdb->term_relationships} AS rel ON posts.ID=rel.object_ID
                    LEFT JOIN {$wpdb->term_taxonomy} AS tax USING( term_taxonomy_id )
                    LEFT JOIN {$wpdb->terms} AS term USING( term_id )
                    LEFT JOIN {$wpdb->postmeta} AS postmeta ON posts.ID = postmeta.post_id
                    WHERE posts.post_type = 'shop_order'
                    AND posts.post_status = 'publish'
                    AND tax.taxonomy = 'shop_order_status'
                    AND term.slug IN ( {$placeholders} )
                    AND postmeta.meta_key = '_order_total'
                    AND posts.post_date >= %s
                    AND posts.post_date <= %s",
                    array_merge( $safe_statuses, array( $month_start, $month_end ) )
                )
            );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

            wp_cache_set( $cache_key, $sales, 'mainwp_woocommerce', HOUR_IN_SECONDS );
        }

        // Generate cache key for top seller.
        $cache_key_top = 'wc_top_seller_' . md5( implode( '_', $safe_statuses ) . '_' . $month_start . '_' . $month_end ); //phpcs:ignore --NOSONAR --safe for key.

        // Try to get cached value.
        $top_seller = wp_cache_get( $cache_key_top, 'mainwp_woocommerce' );

        // If cache miss, execute the query and cache result.
        if ( false === $top_seller ) {
            $top_seller = $wpdb->get_row( //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- IN() list is array_fill('%s') placeholders; every value goes through prepare().
                $wpdb->prepare(
                    "SELECT SUM( order_item_meta.meta_value ) as qty, order_item_meta_2.meta_value as product_id
                    FROM {$wpdb->posts} as posts
                    LEFT JOIN {$wpdb->term_relationships} AS rel ON posts.ID=rel.object_ID
                    LEFT JOIN {$wpdb->term_taxonomy} AS tax USING( term_taxonomy_id )
                    LEFT JOIN {$wpdb->terms} AS term USING( term_id )
                    LEFT JOIN {$wpdb->prefix}woocommerce_order_items AS order_items ON posts.ID = order_id
                    LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS order_item_meta ON order_items.order_item_id = order_item_meta.order_item_id
                    LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS order_item_meta_2 ON order_items.order_item_id = order_item_meta_2.order_item_id
                    WHERE posts.post_type = 'shop_order'
                    AND posts.post_status = 'publish'
                    AND tax.taxonomy = 'shop_order_status'
                    AND term.slug IN ( {$placeholders} )
                    AND order_item_meta.meta_key = '_qty'
                    AND order_item_meta_2.meta_key = '_product_id'
                    AND posts.post_date >= %s
                    AND posts.post_date <= %s
                    GROUP BY product_id
                    ORDER BY qty DESC
                    LIMIT 1",
                    array_merge( $safe_statuses, array( $month_start, $month_end ) )
                )
            );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

            wp_cache_set( $cache_key_top, $top_seller, 'mainwp_woocommerce', HOUR_IN_SECONDS );
        }

        if ( ! empty( $top_seller ) ) {
            $top_seller->name = get_the_title( $top_seller->product_id );
        }

        // Counts.
        $on_hold_count    = get_term_by( 'slug', 'on-hold', 'shop_order_status' )->count;
        $processing_count = get_term_by( 'slug', 'processing', 'shop_order_status' )->count;

        // Get products using a query.
        $stock   = absint( max( get_option( 'woocommerce_notify_low_stock_amount' ), 1 ) );
        $nostock = absint( max( get_option( 'woocommerce_notify_no_stock_amount' ), 0 ) );

        $query_from = "FROM {$wpdb->posts} as posts INNER JOIN {$wpdb->postmeta} AS postmeta ON posts.ID = postmeta.post_id INNER JOIN {$wpdb->postmeta} AS postmeta2 ON posts.ID = postmeta2.post_id WHERE 1=1 AND posts.post_type IN ('product', 'product_variation') AND posts.post_status = 'publish' AND ( postmeta.meta_key = '_stock' AND CAST(postmeta.meta_value AS SIGNED) <= '{$stock}' AND CAST(postmeta.meta_value AS SIGNED) > '{$nostock}' AND postmeta.meta_value != '' ) AND ( ( postmeta2.meta_key = '_manage_stock' AND postmeta2.meta_value = 'yes' ) OR ( posts.post_type = 'product_variation' ) )";

        $lowinstock_count = absint( $wpdb->get_var( "SELECT COUNT( DISTINCT posts.ID ) {$query_from};" ) ); //phpcs:ignore -- safe query.

        $query_from = "FROM {$wpdb->posts} as posts INNER JOIN {$wpdb->postmeta} AS postmeta ON posts.ID = postmeta.post_id INNER JOIN {$wpdb->postmeta} AS postmeta2 ON posts.ID = postmeta2.post_id WHERE 1=1 AND posts.post_type IN ('product', 'product_variation') AND posts.post_status = 'publish' AND ( postmeta.meta_key = '_stock' AND CAST(postmeta.meta_value AS SIGNED) <= '{$nostock}' AND postmeta.meta_value != '' ) AND ( ( postmeta2.meta_key = '_manage_stock' AND postmeta2.meta_value = 'yes' ) OR ( posts.post_type = 'product_variation' ) )";

        $outofstock_count = absint( $wpdb->get_var( "SELECT COUNT( DISTINCT posts.ID ) {$query_from};" ) );  //phpcs:ignore -- safe query.

        $data = array(
            'sales'          => $sales,
            'formated_sales' => wc_price( $sales ),
            'top_seller'     => $top_seller,
            'onhold'         => $on_hold_count,
            'awaiting'       => $processing_count,
            'stock'          => $stock,
            'nostock'        => $nostock,
            'lowstock'       => $lowinstock_count,
            'outstock'       => $outofstock_count,
        );

        if ( $include_last_7_days_sales ) {
            $data['sales_report_mode'] = 'legacy';
            $data                      = $this->add_last_7_days_sales( $data, false );
        }

        $data = apply_filters( 'mainwp_child_woocom_sync_data', $data );

        $information['data'] = $data;

        return $information;
    }

    /**
     * Woocommerce report data.
     *
     * @return array $information Woocommerce data grabed.
     */
    public function report_data() {

        /**
         * Object, providing access to the WordPress database.
         *
         * @global object $wpdb WordPress Database instance.
         */
        global $wpdb;

        $file = WP_PLUGIN_DIR . '/woocommerce/includes/admin/reports/class-wc-admin-report.php';
        if ( file_exists( $file ) ) {
            include_once $file; // NOSONAR -- WP compatible.
        } else {
            return false;
        }
        // phpcs:disable WordPress.Security.NonceVerification
        $start_date = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
        $end_date   = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';

        $start_date = date( 'Y-m-d H:i:s', $start_date ); // phpcs:ignore -- local time.
        $end_date   = date( 'Y-m-d H:i:s', $end_date ); // phpcs:ignore -- local time.
        // phpcs:enable

        // Define allowed WooCommerce order statuses.
        $allowed_statuses = array( 'completed', 'processing', 'on-hold', 'refunded', 'cancelled', 'failed', 'pending' );

        // Apply the filter and validate against whitelist.
        $filtered_statuses = apply_filters( 'woocommerce_reports_order_statuses', array( 'completed', 'processing', 'on-hold' ) );
        $safe_statuses     = array_intersect( $filtered_statuses, $allowed_statuses );

        // Ensure at least one status exists, otherwise use default.
        if ( empty( $safe_statuses ) ) {
            $safe_statuses = array( 'completed' );
        }

        // Build dynamic placeholders for IN clause.
        $placeholders = implode( ',', array_fill( 0, count( $safe_statuses ), '%s' ) );

        // Generate cache key.
        $cache_key = 'wc_sales_' . md5( implode( '_', $safe_statuses ) . '_' . $start_date . '_' . $end_date ); //phpcs:ignore --NOSONAR --safe for key.

        // Try to get cached value.
        $sales = wp_cache_get( $cache_key, 'mainwp_woocommerce' );

        // If cache miss, execute the query and cache result.
        if ( false === $sales ) {
            $sales = $wpdb->get_var( //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- IN() list is array_fill('%s') placeholders; every value goes through prepare().
                $wpdb->prepare(
                    "SELECT SUM( postmeta.meta_value ) FROM {$wpdb->posts} as posts
                    LEFT JOIN {$wpdb->term_relationships} AS rel ON posts.ID=rel.object_ID
                    LEFT JOIN {$wpdb->term_taxonomy} AS tax USING( term_taxonomy_id )
                    LEFT JOIN {$wpdb->terms} AS term USING( term_id )
                    LEFT JOIN {$wpdb->postmeta} AS postmeta ON posts.ID = postmeta.post_id
                    WHERE posts.post_type = 'shop_order'
                    AND posts.post_status = 'publish'
                    AND tax.taxonomy = 'shop_order_status'
                    AND term.slug IN ( {$placeholders} )
                    AND postmeta.meta_key = '_order_total'
                    AND posts.post_date >= %s
                    AND posts.post_date <= %s",
                    array_merge( $safe_statuses, array( $start_date, $end_date ) )
                )
            );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

            wp_cache_set( $cache_key, $sales, 'mainwp_woocommerce', HOUR_IN_SECONDS );
        }

        // Generate cache key for top seller.
        $cache_key_top = 'wc_top_seller_' . md5( implode( '_', $safe_statuses ) . '_' . $start_date . '_' . $end_date ); //phpcs:ignore --NOSONAR --safe for key.

        // Try to get cached value.
        $top_seller = wp_cache_get( $cache_key_top, 'mainwp_woocommerce' );

        // If cache miss, execute the query and cache result.
        if ( false === $top_seller ) {
            $top_seller = $wpdb->get_row( //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- IN() list is array_fill('%s') placeholders; every value goes through prepare().
                $wpdb->prepare(
                    "SELECT SUM( order_item_meta.meta_value ) as qty, order_item_meta_2.meta_value as product_id
                    FROM {$wpdb->posts} as posts
                    LEFT JOIN {$wpdb->term_relationships} AS rel ON posts.ID=rel.object_ID
                    LEFT JOIN {$wpdb->term_taxonomy} AS tax USING( term_taxonomy_id )
                    LEFT JOIN {$wpdb->terms} AS term USING( term_id )
                    LEFT JOIN {$wpdb->prefix}woocommerce_order_items AS order_items ON posts.ID = order_id
                    LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS order_item_meta ON order_items.order_item_id = order_item_meta.order_item_id
                    LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS order_item_meta_2 ON order_items.order_item_id = order_item_meta_2.order_item_id
                    WHERE posts.post_type = 'shop_order'
                    AND posts.post_status = 'publish'
                    AND tax.taxonomy = 'shop_order_status'
                    AND term.slug IN ( {$placeholders} )
                    AND order_item_meta.meta_key = '_qty'
                    AND order_item_meta_2.meta_key = '_product_id'
                    AND posts.post_date >= %s
                    AND posts.post_date <= %s
                    GROUP BY product_id
                    ORDER BY qty DESC
                    LIMIT 1",
                    array_merge( $safe_statuses, array( $start_date, $end_date ) )
                )
            );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

            wp_cache_set( $cache_key_top, $top_seller, 'mainwp_woocommerce', HOUR_IN_SECONDS );
        }

        if ( ! empty( $top_seller ) ) {
            $top_seller->name = get_the_title( $top_seller->product_id );
        }

        // Counts.
        $on_hold_count    = get_term_by( 'slug', 'on-hold', 'shop_order_status' )->count;
        $processing_count = get_term_by( 'slug', 'processing', 'shop_order_status' )->count;

        // Get products using a query.
        $stock   = absint( max( get_option( 'woocommerce_notify_low_stock_amount' ), 1 ) );
        $nostock = absint( max( get_option( 'woocommerce_notify_no_stock_amount' ), 0 ) );

        $query_from = "FROM {$wpdb->posts} as posts INNER JOIN {$wpdb->postmeta} AS postmeta ON posts.ID = postmeta.post_id INNER JOIN {$wpdb->postmeta} AS postmeta2 ON posts.ID = postmeta2.post_id WHERE 1=1 AND posts.post_type IN ('product', 'product_variation') AND posts.post_status = 'publish' AND ( postmeta.meta_key = '_stock' AND CAST(postmeta.meta_value AS SIGNED) <= '{$stock}' AND CAST(postmeta.meta_value AS SIGNED) > '{$nostock}' AND postmeta.meta_value != '' ) AND ( ( postmeta2.meta_key = '_manage_stock' AND postmeta2.meta_value = 'yes' ) OR ( posts.post_type = 'product_variation' ) )";

        $lowinstock_count = absint( $wpdb->get_var( "SELECT COUNT( DISTINCT posts.ID ) {$query_from};" ) ); //phpcs:ignore -- safe query.

        $query_from = "FROM {$wpdb->posts} as posts INNER JOIN {$wpdb->postmeta} AS postmeta ON posts.ID = postmeta.post_id INNER JOIN {$wpdb->postmeta} AS postmeta2 ON posts.ID = postmeta2.post_id WHERE 1=1 AND posts.post_type IN ('product', 'product_variation') AND posts.post_status = 'publish' AND ( postmeta.meta_key = '_stock' AND CAST(postmeta.meta_value AS SIGNED) <= '{$nostock}' AND postmeta.meta_value != '' ) AND ( ( postmeta2.meta_key = '_manage_stock' AND postmeta2.meta_value = 'yes' ) OR ( posts.post_type = 'product_variation' ) )";

        $outofstock_count = absint( $wpdb->get_var( "SELECT COUNT( DISTINCT posts.ID ) {$query_from};" ) ); //phpcs:ignore -- safe query.

        $data = array(
            'sales'          => $sales,
            'formated_sales' => wc_price( $sales ),
            'top_seller'     => $top_seller,
            'onhold'         => $on_hold_count,
            'awaiting'       => $processing_count,
            'stock'          => $stock,
            'nostock'        => $nostock,
            'lowstock'       => $lowinstock_count,
            'outstock'       => $outofstock_count,
        );

        $data = apply_filters( 'mainwp_child_woocom_report_data', $data );

        $information['data'] = $data;

        return $information;
    }

    /**
     * Sync Woocommerce data for current month.
     *
     * @param bool $include_last_7_days_sales Whether to include sales from the last seven days.
     *
     * @SuppressWarnings(PHPMD.LongVariable)
     */
    public function sync_data_two( $include_last_7_days_sales = false ) {
        $start_date = date( 'Y-m-01 00:00:00', time() ); // phpcs:ignore -- local time.
        $end_date   = date( 'Y-m-d H:i:s', time() ); // phpcs:ignore -- local time.

        $start_date = strtotime( $start_date );
        $end_date   = strtotime( $end_date );

        $information = $this->get_woocom_data( $start_date, $end_date, $include_last_7_days_sales );

        if ( $include_last_7_days_sales && is_array( $information ) && isset( $information['data'] ) && is_array( $information['data'] ) ) {
            $information['data'] = $this->add_last_7_days_sales( $information['data'] );
        }

        return $information;
    }

    /**
     * Add sales from the last seven days to WooCommerce status data.
     *
     * @param array $data WooCommerce status data.
     * @param bool  $is_ver220 Whether WooCommerce 2.2 or newer is active.
     *
     * @return array WooCommerce status data.
     */
    private function add_last_7_days_sales( $data, $is_ver220 = true ) {
        $end_date   = current_datetime();
        $start_date = $end_date->modify( '-6 days' )->setTime( 0, 0, 0 );

        $report_mode = isset( $data['sales_report_mode'] ) && 'analytics' === $data['sales_report_mode'] ? 'analytics' : 'legacy';

        if ( $is_ver220 && 'analytics' === $report_mode ) {
            $last_7_days_sales = $this->get_analytics_total_sales( $start_date, $end_date );
        } elseif ( $is_ver220 ) {
            $last_7_days_sales = $this->get_last_7_days_sales( $start_date, $end_date );
        } else {
            $last_7_days_sales = $this->get_pre_220_total_sales( $start_date->format( 'Y-m-d H:i:s' ), $end_date->format( 'Y-m-d H:i:s' ) );
        }

        $last_7_days_sales                  = (float) $last_7_days_sales;
        $data['sales_last_7_days']          = $last_7_days_sales;
        $data['formated_sales_last_7_days'] = wc_price( $last_7_days_sales );
        $data['sales_last_7_days_start']    = $start_date->format( 'Y-m-d' );
        $data['sales_last_7_days_end']      = $end_date->format( 'Y-m-d' );

        return $data;
    }

    /**
     * Get sales from the last seven days using the WooCommerce sales report.
     *
     * @param \DateTimeInterface $start_date Start of the seven-day range in the WordPress timezone.
     * @param \DateTimeInterface $end_date Current time in the WordPress timezone.
     *
     * @return float Sales from the last seven days.
     */
    private function get_last_7_days_sales( $start_date, $end_date ) {
        return $this->get_total_sales( $start_date->getTimestamp(), $end_date->getTimestamp(), true );
    }

    /**
     * Get Analytics sales for a date range in the WordPress timezone.
     *
     * @param \DateTimeInterface $start_date Start of the range in the WordPress timezone.
     * @param \DateTimeInterface $end_date End of the range in the WordPress timezone.
     *
     * @return float Sales total.
     */
    private function get_analytics_total_sales( $start_date, $end_date ) {
        $args = array(
            'before'   => $end_date->format( 'Y-m-d H:i:s' ),
            'after'    => $start_date->format( 'Y-m-d H:i:s' ),
            'fields'   => array( 'total_sales' ),
            'per_page' => 1000,
        );

        $report       = new \Automattic\WooCommerce\Admin\API\Reports\Revenue\Query( $args );
        $revenue_data = $report->get_data();

        return is_object( $revenue_data ) && ! empty( $revenue_data->totals->total_sales ) ? (float) $revenue_data->totals->total_sales : 0;
    }

    /**
     * Get sales for WooCommerce versions older than 2.2.
     *
     * @param string $start_date Start date in the WordPress timezone.
     * @param string $end_date End date in the WordPress timezone.
     *
     * @return float Sales total.
     */
    private function get_pre_220_total_sales( $start_date, $end_date ) {
        global $wpdb;

        $allowed_statuses  = array( 'completed', 'processing', 'on-hold', 'refunded', 'cancelled', 'failed', 'pending' );
        $filtered_statuses = apply_filters( 'woocommerce_reports_order_statuses', array( 'completed', 'processing', 'on-hold' ) );
        $safe_statuses     = array_intersect( $filtered_statuses, $allowed_statuses );

        if ( empty( $safe_statuses ) ) {
            $safe_statuses = array( 'completed' );
        }

        $placeholders = implode( ',', array_fill( 0, count( $safe_statuses ), '%s' ) );
        $cache_key    = 'wc_sales_' . md5( implode( '_', $safe_statuses ) . '_' . $start_date . '_' . $end_date ); //phpcs:ignore -- NOSONAR -- safe for key.
        $sales        = wp_cache_get( $cache_key, 'mainwp_woocommerce' );

        if ( false === $sales ) {
            $sales = $wpdb->get_var( //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- IN() list is array_fill('%s') placeholders; every value goes through prepare().
                $wpdb->prepare(
                    "SELECT SUM( postmeta.meta_value ) FROM {$wpdb->posts} as posts
                    LEFT JOIN {$wpdb->term_relationships} AS rel ON posts.ID=rel.object_ID
                    LEFT JOIN {$wpdb->term_taxonomy} AS tax USING( term_taxonomy_id )
                    LEFT JOIN {$wpdb->terms} AS term USING( term_id )
                    LEFT JOIN {$wpdb->postmeta} AS postmeta ON posts.ID = postmeta.post_id
                    WHERE posts.post_type = 'shop_order'
                    AND posts.post_status = 'publish'
                    AND tax.taxonomy = 'shop_order_status'
                    AND term.slug IN ( {$placeholders} )
                    AND postmeta.meta_key = '_order_total'
                    AND posts.post_date >= %s
                    AND posts.post_date <= %s",
                    array_merge( $safe_statuses, array( $start_date, $end_date ) )
                )
            );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

            wp_cache_set( $cache_key, $sales, 'mainwp_woocommerce', HOUR_IN_SECONDS );
        }

        return (float) $sales;
    }

    /**
     * Sync Woocomerce data for specific date range.
     */
    public function report_data_two() {
        // phpcs:disable WordPress.Security.NonceVerification
        $start_date = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
        $end_date   = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';
        // phpcs:enable

        return $this->get_woocom_data( $start_date, $end_date );
    }

    /**
     * Check if woocomerce DB needs to be updated.
     *
     * @return bool true|false.
     */
    public function check_db_update() {
        if ( version_compare( get_option( 'woocommerce_db_version' ), WC_VERSION, '<' ) ) {
            return true;
        }
        return false;
    }

    /**
     * Get top seller.
     *
     * @param string $start_date Start Date.
     * @param string $end_date End Date.
     *
     * @return array $information Woocommerce data grabed.
     */
    public function get_top_seller( $start_date, $end_date ) { //phpcs:ignore -- NOSONAR - ignore complex.

        $top_seller = false;

        $page       = 0;
        $total_page = 1;
        $top_count  = 0;

        while ( $page < $total_page ) {
            ++$page;
            $args = array(
                'before'   => $end_date,
                'after'    => $start_date,
                'page'     => $page,
                'per_page' => 1000,
            );

            $product_data = false;

            $compat_ver_after_93 = false;
            if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '9.3.0', '>=' ) ) {
                $compat_ver_after_93 = true;
            }

            if ( $compat_ver_after_93 ) {
                if ( class_exists( '\Automattic\WooCommerce\Admin\API\Reports\Products\DataStore' ) ) {
                    $data_store   = new \Automattic\WooCommerce\Admin\API\Reports\Products\DataStore();
                    $product_data = $data_store->get_data( $args );
                }
            } elseif ( class_exists( '\Automattic\WooCommerce\Admin\API\Reports\Products\Query' ) ) {
                    $report       = new \Automattic\WooCommerce\Admin\API\Reports\Products\Query( $args );
                    $product_data = $report->get_data();
            }

            $products = array();

            if ( is_object( $product_data ) ) {
                $products = ! empty( $product_data->data ) ? $product_data->data : array();
                if ( ! is_array( $products ) ) {
                    $products = array();
                }
                foreach ( $products as $prod_sel ) {
                    if ( is_array( $prod_sel ) && isset( $prod_sel['items_sold'] ) && $prod_sel['items_sold'] > $top_count ) {
                        $top_seller = $prod_sel;
                        $top_count  = $prod_sel['items_sold'];
                    }
                }
                if ( ! empty( $product_data->pages ) && $product_data->pages > $total_page ) {
                    $total_page = $product_data->pages;
                }
            } else {
                break;
            }
        }

        $top_data = array();
        if ( ! empty( $top_seller ) ) {
            $top_data         = array(
                'product_id' => $top_seller['product_id'],
                'qty'        => $top_seller['items_sold'],
            );
            $product          = wc_get_product( $top_seller['product_id'] );
            $top_data['name'] = ! empty( $product ) ? $product->get_name() : 'N/A';
        }
        return $top_data;
    }

    /**
     * Get Woocommerce 8 reports.
     *
     * @param string $start_date Start Date.
     * @param string $end_date End Date.
     * @param bool   $include_report_mode Whether to include the sales report mode.
     *
     * @return array $information Woocommerce data grabed.
     */
    public function get_woocom_reports( $start_date, $end_date, $include_report_mode = false ) {

        if ( class_exists( '\Automattic\WooCommerce\Admin\Features\Features' ) && \Automattic\WooCommerce\Admin\Features\Features::is_enabled( 'analytics' ) ) {
            return $this->get_woocom_analytics( $start_date, $end_date, $include_report_mode );
        }

        $on_hold_count = 0;
        if ( function_exists( 'wc_orders_count' ) ) {
            $status_counts = array_map( 'wc_orders_count', array( 'on-hold' ) );
            $on_hold_count = array_sum( $status_counts );
        }

        $processing_count = 0;
        if ( function_exists( 'wc_processing_order_count' ) ) {
            $processing_count = wc_processing_order_count();
        }

        $total_sales = $this->get_total_sales( $start_date, $end_date );

        $top_seller = $this->get_top_sellers_report( $start_date, $end_date );

        $report     = new \Automattic\WooCommerce\Admin\API\Reports\Stock\Stats\Query();
        $stock_data = $report->get_data();

        $data = array(
            'sales'          => $total_sales,
            'formated_sales' => wc_price( $total_sales ),
            'top_seller'     => ! empty( $top_seller ) ? (object) $top_seller : false,
            'onhold'         => $on_hold_count,
            'awaiting'       => $processing_count,
            'lowstock'       => is_array( $stock_data ) && isset( $stock_data['lowstock'] ) ? intval( $stock_data['lowstock'] ) : 0,
            'outstock'       => is_array( $stock_data ) && isset( $stock_data['outofstock'] ) ? intval( $stock_data['outofstock'] ) : 0,
        );

        if ( $include_report_mode ) {
            $data['sales_report_mode'] = 'legacy';
        }

        return $data;
    }

    /**
     * Get Woocommerce data.
     *
     * @param string $start_date Start Date.
     * @param string $end_date End Date.
     * @param bool   $include_report_mode Whether to include the sales report mode.
     *
     * @return array $information Woocommerce data grabed.
     */
    public function get_woocom_data( $start_date, $end_date, $include_report_mode = false ) {

        if ( class_exists( '\Automattic\WooCommerce\Admin\API\Reports\Orders\Stats\Query' ) ) {
            $data = $this->get_woocom_reports( $start_date, $end_date, $include_report_mode );
        } else {
            $data = $this->get_woocom_reports_old( $start_date, $end_date, $include_report_mode );
        }

        $information['data']           = $data;
        $information['need_db_update'] = $this->check_db_update();
        return $information;
    }


    /**
     * Get Woocommerce reports old.
     *
     * @param string $start_date Start Date.
     * @param string $end_date End Date.
     * @param bool   $include_report_mode Whether to include the sales report mode.
     *
     * @return array $information Woocommerce data grabed.
     */
    public function get_woocom_reports_old( $start_date, $end_date, $include_report_mode = false ) {

        /**
         * Object, providing access to the WordPress database.
         *
         * @global object $wpdb WordPress Database instance.
         */
        global $wpdb;

        $file = WP_PLUGIN_DIR . '/woocommerce/includes/admin/reports/class-wc-admin-report.php';
        if ( file_exists( $file ) ) {
            include_once $file; // NOSONAR -- WP compatible.
        } else {
            return false;
        }

        $start_date = date( 'Y-m-d H:i:s', $start_date ); // phpcs:ignore -- local time. Required to achieve desired results, pull request solutions appreciated.
        $end_date   = date( 'Y-m-d H:i:s', $end_date ); // phpcs:ignore -- local time. Required to achieve desired results, pull request solutions appreciated.

        // Sales.
        $query           = array();
        $query['fields'] = "SELECT SUM( postmeta.meta_value ) FROM {$wpdb->posts} as posts";
        $query['join']   = "INNER JOIN {$wpdb->postmeta} AS postmeta ON posts.ID = postmeta.post_id ";
        $query['where']  = "WHERE posts.post_type IN ( '" . implode( "','", wc_get_order_types( 'reports' ) ) . "' ) ";
        $query['where'] .= "AND posts.post_status IN ( 'wc-" . implode( "','wc-", apply_filters( 'woocommerce_reports_order_statuses', array( 'completed', 'processing', 'on-hold' ) ) ) . "' ) ";
        $query['where'] .= "AND postmeta.meta_key = '_order_total' ";
        $query['where'] .= 'AND posts.post_date >=  STR_TO_DATE(' . $wpdb->prepare( '%s', $start_date ) . ", '%Y-%m-%d %H:%i:%s' ) ";
        $query['where'] .= 'AND posts.post_date <=  STR_TO_DATE(' . $wpdb->prepare( '%s', $end_date ) . ", '%Y-%m-%d %H:%i:%s' ) ";

        $sales = $wpdb->get_var( implode( ' ', apply_filters( 'woocommerce_dashboard_status_widget_sales_query', $query ) ) ); // phpcs:ignore -- safe query.

        // Get top seller.
        $query            = array();
        $query['fields']  = "SELECT SUM( order_item_meta.meta_value ) as qty, order_item_meta_2.meta_value as product_id FROM {$wpdb->posts} as posts";
        $query['join']    = "INNER JOIN {$wpdb->prefix}woocommerce_order_items AS order_items ON posts.ID = order_id ";
        $query['join']   .= "INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS order_item_meta ON order_items.order_item_id = order_item_meta.order_item_id ";
        $query['join']   .= "INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS order_item_meta_2 ON order_items.order_item_id = order_item_meta_2.order_item_id ";
        $query['where']   = "WHERE posts.post_type IN ( '" . implode( "','", wc_get_order_types( 'order-count' ) ) . "' ) ";
        $query['where']  .= "AND posts.post_status IN ( 'wc-" . implode( "','wc-", apply_filters( 'woocommerce_reports_order_statuses', array( 'completed', 'processing', 'on-hold' ) ) ) . "' ) ";
        $query['where']  .= "AND order_item_meta.meta_key = '_qty' ";
        $query['where']  .= "AND order_item_meta_2.meta_key = '_product_id' ";
        $query['where']  .= 'AND posts.post_date >= STR_TO_DATE(' . $wpdb->prepare( '%s', $start_date ) . ", '%Y-%m-%d %H:%i:%s') ";
        $query['where']  .= 'AND posts.post_date <= STR_TO_DATE(' . $wpdb->prepare( '%s', $end_date ) . ", '%Y-%m-%d %H:%i:%s')  ";
        $query['groupby'] = 'GROUP BY product_id';
        $query['orderby'] = 'ORDER BY qty DESC';
        $query['limits']  = 'LIMIT 1';

        $top_seller = $wpdb->get_row( implode( ' ', $query ) ); // phpcs:ignore -- safe query.

        if ( ! empty( $top_seller ) ) {
            $top_seller->name = get_the_title( $top_seller->product_id );
        }

        // Counts.
        $on_hold_count    = 0;
        $processing_count = 0;

        foreach ( wc_get_order_types( 'order-count' ) as $type ) {
            $counts            = (array) wp_count_posts( $type );
            $on_hold_count    += isset( $counts['wc-on-hold'] ) ? $counts['wc-on-hold'] : 0;
            $processing_count += isset( $counts['wc-processing'] ) ? $counts['wc-processing'] : 0;
        }

        // Get products using a query.
        $stock   = absint( max( get_option( 'woocommerce_notify_low_stock_amount' ), 1 ) );
        $nostock = absint( max( get_option( 'woocommerce_notify_no_stock_amount' ), 0 ) );

        $query_from = "FROM {$wpdb->posts} as posts INNER JOIN {$wpdb->postmeta} AS postmeta ON posts.ID = postmeta.post_id INNER JOIN {$wpdb->postmeta} AS postmeta2 ON posts.ID = postmeta2.post_id WHERE 1=1 AND posts.post_type IN ('product', 'product_variation') AND posts.post_status = 'publish' AND ( postmeta.meta_key = '_stock' AND CAST(postmeta.meta_value AS SIGNED) <= '{$stock}' AND CAST(postmeta.meta_value AS SIGNED) > '{$nostock}' AND postmeta.meta_value != '' ) AND ( ( postmeta2.meta_key = '_manage_stock' AND postmeta2.meta_value = 'yes' ) OR ( posts.post_type = 'product_variation' ) )";

        $lowinstock_count = absint( $wpdb->get_var( "SELECT COUNT( DISTINCT posts.ID ) {$query_from};" ) ); //phpcs:ignore -- safe query.

        $query_from = "FROM {$wpdb->posts} as posts INNER JOIN {$wpdb->postmeta} AS postmeta ON posts.ID = postmeta.post_id INNER JOIN {$wpdb->postmeta} AS postmeta2 ON posts.ID = postmeta2.post_id WHERE 1=1 AND posts.post_type IN ('product', 'product_variation') AND posts.post_status = 'publish' AND ( postmeta.meta_key = '_stock' AND CAST(postmeta.meta_value AS SIGNED) <= '{$nostock}' AND postmeta.meta_value != '' ) AND ( ( postmeta2.meta_key = '_manage_stock' AND postmeta2.meta_value = 'yes' ) OR ( posts.post_type = 'product_variation' ) ) ";

        $outofstock_count = absint( $wpdb->get_var( "SELECT COUNT( DISTINCT posts.ID ) {$query_from};" ) ); //phpcs:ignore -- safe query.

        $data = array(
            'sales'          => $sales,
            'formated_sales' => wc_price( $sales ),
            'top_seller'     => $top_seller,
            'onhold'         => $on_hold_count,
            'awaiting'       => $processing_count,
            'stock'          => $stock,
            'nostock'        => $nostock,
            'lowstock'       => $lowinstock_count,
            'outstock'       => $outofstock_count,
        );

        if ( $include_report_mode ) {
            $data['sales_report_mode'] = 'legacy';
        }

        $data = apply_filters( 'mainwp_child_woocom_get_data', $data );
        return $data;
    }

    /**
     * Update Woocommerce Database.
     *
     * @return string[] Success.
     */
    private static function update_wc_db() {
        include_once WC()->plugin_path() . '/includes/class-wc-background-updater.php'; // NOSONAR -- WP compatible.
        $background_updater = new \WC_Background_Updater();

        $current_db_version = get_option( 'woocommerce_db_version' );
        $logger             = wc_get_logger();
        $update_queued      = false;

        foreach ( \WC_Install::get_db_update_callbacks() as $version => $update_callbacks ) {
            if ( version_compare( $current_db_version, $version, '<' ) ) {
                foreach ( $update_callbacks as $update_callback ) {
                    $logger->info(
                        sprintf( 'Queuing %s - %s', $version, $update_callback ),
                        array( 'source' => 'wc_db_updates' )
                    );
                    $background_updater->push_to_queue( $update_callback );
                    $update_queued = true;
                }
            }
        }

        if ( $update_queued ) {
            $background_updater->save()->dispatch();
        }

        return array( 'result' => 'success' );
    }

    /**
     * Get top seller.
     *
     * @param string $start_date Start Date.
     * @param string $end_date End Date.
     *
     * @return array $information Woocommerce data grabed.
     */
    public function get_top_sellers_report( $start_date, $end_date ) {

        include_once WC()->plugin_path() . '/includes/admin/reports/class-wc-admin-report.php'; // NOSONAR -- WP compatible.

        $report = new \WC_Admin_Report();

        $_GET['start_date'] = gmdate( 'Y-m-d H:i:s', $start_date );
        $_GET['end_date']   = gmdate( 'Y-m-d H:i:s', $end_date );

        $report->calculate_current_range( 'custom' );

        $top_sellers = $report->get_order_report_data(
            array(
                'data'         => array(
                    '_product_id' => array(
                        'type'            => 'order_item_meta',
                        'order_item_type' => 'line_item',
                        'function'        => '',
                        'name'            => 'product_id',
                    ),
                    '_qty'        => array(
                        'type'            => 'order_item_meta',
                        'order_item_type' => 'line_item',
                        'function'        => 'SUM',
                        'name'            => 'order_item_qty',
                    ),
                ),
                'order_by'     => 'order_item_qty DESC',
                'group_by'     => 'product_id',
                'limit'        => 1000,
                'query_type'   => 'get_results',
                'filter_range' => true,
            )
        );

        $top_product = false;

        $top_count = 0;
        foreach ( $top_sellers as $top_seller ) {
            if ( is_object( $top_seller ) && isset( $top_seller->order_item_qty ) && $top_seller->order_item_qty > $top_count ) {
                $top_product = $top_seller;
                $top_count   = $top_seller->order_item_qty;
            }
        }

        $top_data = array();
        if ( ! empty( $top_product ) ) {
            $top_data         = array(
                'product_id' => $top_product->product_id,
                'qty'        => $top_product->order_item_qty,
            );
            $product          = wc_get_product( $top_product->product_id );
            $top_data['name'] = ! empty( $product ) ? $product->get_name() : 'N/A';
        }
        return $top_data;
    }

    /**
     * Get total sales.
     *
     * @param string $start_date Start Date.
     * @param string $end_date End Date.
     * @param bool   $use_wordpress_timezone Whether to format the range in the WordPress timezone.
     *
     * @return int $total_sales Total sales.
     *
     * @SuppressWarnings(PHPMD.LongVariable)
     * @SuppressWarnings(PHPMD.Superglobals)
     */
    public function get_total_sales( $start_date, $end_date, $use_wordpress_timezone = false ) {

        include_once WC()->plugin_path() . '/includes/admin/reports/class-wc-admin-report.php'; // NOSONAR -- WP compatible.
        include_once WC()->plugin_path() . '/includes/admin/reports/class-wc-report-sales-by-date.php'; // NOSONAR -- WP compatible.

        $total_sales = 0;

        if ( $use_wordpress_timezone ) {
            $_GET['start_date'] = wp_date( 'Y-m-d H:i:s', $start_date );
            $_GET['end_date']   = wp_date( 'Y-m-d H:i:s', $end_date );
        } else {
            $_GET['start_date'] = gmdate( 'Y-m-d H:i:s', $start_date );
            $_GET['end_date']   = gmdate( 'Y-m-d H:i:s', $end_date );
        }

        $report = new \WC_Report_Sales_By_Date();
        $report->calculate_current_range( 'custom' );
        $report_data = $report->get_report_data();
        if ( is_object( $report_data ) && ! empty( $report_data->total_sales ) ) {
            $total_sales = $report_data->total_sales;
        }

        return $total_sales;
    }


    /**
     * Get Woocommerce 8 analytics.
     *
     * @param string $start_date Start Date.
     * @param string $end_date End Date.
     * @param bool   $include_report_mode Whether to include the sales report mode.
     *
     * @return array $information Woocommerce data grabed.
     */
    public function get_woocom_analytics( $start_date, $end_date, $include_report_mode = false ) {
        $on_hold_count = 0;
        if ( function_exists( 'wc_orders_count' ) ) {
            $status_counts = array_map( 'wc_orders_count', array( 'on-hold' ) );
            $on_hold_count = array_sum( $status_counts );
        }

        $processing_count = 0;
        if ( function_exists( 'wc_processing_order_count' ) ) {
            $processing_count = wc_processing_order_count();
        }

        $sales_data  = $this->get_sales_data( $start_date, $end_date );
        $total_sales = $sales_data['total_sales'];
        $top_seller  = $sales_data['top_seller'];

        $report     = new \Automattic\WooCommerce\Admin\API\Reports\Stock\Stats\Query();
        $stock_data = $report->get_data();

        $data = array(
            'sales'          => $total_sales,
            'formated_sales' => wc_price( $total_sales ),
            'top_seller'     => ! empty( $top_seller ) ? (object) $top_seller : false,
            'onhold'         => $on_hold_count,
            'awaiting'       => $processing_count,
            'lowstock'       => is_array( $stock_data ) && isset( $stock_data['lowstock'] ) ? intval( $stock_data['lowstock'] ) : 0,
            'outstock'       => is_array( $stock_data ) && isset( $stock_data['outofstock'] ) ? intval( $stock_data['outofstock'] ) : 0,
        );

        if ( $include_report_mode ) {
            $data['sales_report_mode'] = 'analytics';
        }

        return $data;
    }

    /**
     * Get sales data.
     *
     * @param string $start_date Start Date.
     * @param string $end_date End Date.
     *
     * @return array Sales data.
     */
    public function get_sales_data( $start_date, $end_date ) {

        $start_date = gmdate( 'Y-m-d H:i:s', $start_date ); // phpcs:ignore
        $end_date   = gmdate( 'Y-m-d H:i:s', $end_date ); // phpcs:ignore

        $args          = array(
            'before'    => $end_date,
            'after'     => $start_date,
            'status_is' => array( 'on-hold', 'processing' ),
            'per_page'  => 1000,
        );
        $report        = new \Automattic\WooCommerce\Admin\API\Reports\Orders\Stats\Query( $args );
        $order_data    = $report->get_data();
        $on_hold_count = is_object( $order_data ) && ! empty( $order_data->totals->orders_count ) ? $order_data->totals->orders_count : 0;

        $args         = array(
            'before'   => $end_date,
            'after'    => $start_date,
            'fields'   => array( 'total_sales' ),
            'per_page' => 1000,
        );
        $report       = new \Automattic\WooCommerce\Admin\API\Reports\Revenue\Query( $args );
        $revenue_data = $report->get_data();
        $total_sales  = is_object( $revenue_data ) && ! empty( $revenue_data->totals->total_sales ) ? $revenue_data->totals->total_sales : 0;

        $top_seller = $this->get_top_seller( $start_date, $end_date );

        return array(
            'top_seller'    => $top_seller,
            'on_hold_count' => $on_hold_count,
            'total_sales'   => $total_sales,
        );
    }
}
