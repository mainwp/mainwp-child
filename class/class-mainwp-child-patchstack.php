<?php
/**
 * MainWP Patchstack
 *
 * MainWP Patchstack extension handler.
 * Extension URL: https://mainwp.com/extension/patchstack/
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable PSR1.Classes.ClassDeclaration, WordPress.WP.AlternativeFunctions, Squiz.Commenting.FunctionComment.MissingParamTag -- required to achieve desired results, pull request solutions appreciated.

/**
 * Class MainWP_Child_Patchstack
 *
 * MainWP Patchstack extension handler
 */
class MainWP_Child_Patchstack { //phpcs:ignore -- NOSONAR - multi methods.

    /** Durable visibility receipt option. */
    const ABILITIES_V2_RECEIPTS_OPTION = 'mainwp_child_patchstack_v2_receipts';

    /** Durable protection-operation receipt option. */
    const ABILITIES_V2_PROTECTION_RECEIPTS_OPTION = 'mainwp_child_patchstack_v2_protection_receipts';

    /** Atomic visibility mutation lock. */
    const ABILITIES_V2_LOCK_OPTION = 'mainwp_child_patchstack_v2_lock';

    /**
     * Current visibility lock owner.
     *
     * @var string
     */
    private $abilities_v2_lock_owner = '';

    /**
     * Public static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    public static $instance = null;

    /**
     * Patchstack install status. True if the plugin is installed on the child site.
     *
     * @var bool If Patchstack plugin installed, return true, if not, return false.
     */
    protected $is_plugin_installed = false;

    /**
     * The plugin slug.
     *
     * @var string slug string.
     */
    protected $the_plugin_slug = 'patchstack/patchstack.php';

    /**
     * API URL of Patchstack to communicate with.
     *
     * @var   string API URL.
     */
    protected $api_url = 'https://api.patchstack.com/monitor';

    /**
     * Option name to hide the Patchstack Insights plugin.
     *
     * @var string Option name.
     */
    protected $option_hide_name = 'mainwp_patchstack_hide_plugin';

    /**
     * Whitelist of allowed sanitization functions.
     *
     * @var array $allowed_callbacks allowed sanitization functions.
     */
    protected static $allowed_callbacks = array(
        'sanitize_text_field',
        'sanitize_textarea_field',
        'sanitize_email',
        'sanitize_url',
        'intval',
        'absint',
        'wp_kses_post',
    );

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
     * MainWP_Child_Patchstack constructor.
     *
     * Run any time class is called.
     */
    public function __construct() {
        $this->is_plugin_installed = is_plugin_active( $this->the_plugin_slug );
    }

    /**
     * Method init()
     *
     * Initiate action hooks.
     *
     * @return void
     */
    public function init() {
        add_filter( 'mainwp_site_sync_others_data', array( $this, 'sync_others_data' ), 10, 2 );
        if ( get_option( $this->option_hide_name ) === 'hide' ) {
            add_filter( 'all_plugins', array( $this, 'all_plugins' ) );
            add_action( 'admin_menu', array( $this, 'remove_menu' ) );
        }
    }

    /**
     * Method actions()
     *
     * Fire off certain Patchstack Insights plugin actions.
     *
     * @uses \MainWP\Child\MainWP_Child_Patchstack::save_settings() Save the plugin settings.
     * @uses \MainWP\Child\MainWP_Child_Patchstack::set_showhide() Hide or unhide the Patchstack Insights plugin.
     * @uses \MainWP\Child\MainWP_Child_Patchstack::install_plugin() Get the Patchstack Insights plugin data and store it in the sync request.
     * @uses \MainWP\Child\MainWP_Helper::write()
     */
    public function action() {
        $information = array();
        $mwp_action  = MainWP_System::instance()->validate_params( 'action' );
        if ( ! empty( $mwp_action ) ) {
            switch ( $mwp_action ) {
                case 'patchstack_capabilities_v2':
                    $information = $this->abilities_v2_action();
                    break;
                case 'install_plugin':
                    $information = $this->install_plugin();
                    break;
                case 'sync_data':
                    $information = $this->sync_data();
                    break;
                case 'show_hide':
                    $information = $this->set_showhide();
                    break;
                case 'save_settings':
                default:
                    $information = $this->save_settings();
                    break;
            }
        }

        MainWP_Helper::write( $information );
    }

    /**
     * Handle the closed Patchstack abilities protocol envelope.
     *
     * @return array Protocol response.
     */
    private function abilities_v2_action() {
        if ( ! isset( $_POST['request'] ) || ! is_string( $_POST['request'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authenticated MainWP request.
            return $this->abilities_v2_error( 'unknown', 'invalid_request' );
        }

        $raw = wp_unslash( $_POST['request'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Closed JSON is validated below.
        if ( '' === $raw || 16384 < strlen( $raw ) ) {
            return $this->abilities_v2_error( 'unknown', 'invalid_request' );
        }

        return $this->abilities_v2( json_decode( $raw, true ) );
    }

    /**
     * Negotiate the Patchstack abilities protocol.
     *
     * Capabilities are derived from what this Child can actually carry out, so an
     * operation this build cannot run is refused by name instead of advertised.
     *
     * @param mixed $request Decoded request envelope.
     * @return array Protocol response.
     */
    public function abilities_v2( $request ) {
        if ( ! is_array( $request ) || ! $this->abilities_v2_exact_keys( $request, array( 'protocol', 'operation', 'payload' ) ) || '2' !== $request['protocol'] || ! is_string( $request['operation'] ) || ! is_array( $request['payload'] ) ) {
            return $this->abilities_v2_error( 'unknown', 'invalid_request' );
        }

        $operation = $request['operation'];
        $mutations = array( 'patchstack_protection_execute_v2', 'patchstack_visibility_replace_v2' );
        $supported = $this->abilities_v2_supported_operations();
        if ( 'capabilities' === $operation ) {
            // Negotiation is supported; a payload on it is a malformed request, not an unknown operation.
            if ( array() !== $request['payload'] ) {
                return $this->abilities_v2_error( $operation, 'invalid_request' );
            }

            return array(
                'protocol'           => '2',
                'operation'          => 'capabilities',
                'ok'                 => true,
                'operations'         => $supported,
                'mutation_supported' => array() !== array_intersect( $mutations, $supported ),
            );
        }

        // Anything this Child cannot carry out is refused by name, before any payload is judged.
        if ( ! in_array( $operation, $supported, true ) ) {
            return $this->abilities_v2_error( $operation, 'unsupported_operation' );
        }

        if ( 'patchstack_protection_preview_v2' === $operation ) {
            return $this->abilities_v2_protection_preview( $request['payload'] );
        }
        if ( 'patchstack_protection_status_v2' === $operation ) {
            return $this->abilities_v2_protection_status( $request['payload'] );
        }
        if ( 'patchstack_protection_execute_v2' === $operation ) {
            if ( ! $this->abilities_v2_begin_mutation() ) {
                return $this->abilities_v2_error( $operation, 'lock_busy' );
            }
            $result   = $this->abilities_v2_protection_execute( $request['payload'] );
            $released = $this->abilities_v2_end_mutation();
            return $released ? $result : $this->abilities_v2_error( $operation, 'outcome_unknown' );
        }
        if ( 'patchstack_visibility_replace_v2' === $operation ) {
            if ( ! $this->abilities_v2_begin_mutation() ) {
                return $this->abilities_v2_error( $operation, 'lock_busy' );
            }
            $result   = $this->abilities_v2_visibility_replace( $request['payload'] );
            $released = $this->abilities_v2_end_mutation();
            return $released ? $result : $this->abilities_v2_error( $operation, 'outcome_unknown' );
        }

        return $this->abilities_v2_error( $operation, 'unsupported_operation' );
    }

    /**
     * List the operations this Child can actually carry out.
     *
     * Protection execution needs a publisher-signed package plus a licensing
     * adapter, and Patchstack exposes neither to the Child, so it is neither
     * advertised nor dispatched. A build that wires
     * abilities_v2_verified_package_manifest() and abilities_v2_apply_protection()
     * extends this list.
     *
     * @return array Executable operation names.
     */
    protected function abilities_v2_supported_operations() {
        return array( 'patchstack_protection_preview_v2', 'patchstack_protection_status_v2', 'patchstack_visibility_replace_v2' );
    }

    /**
     * Return local protection and package-verification readiness.
     *
     * @param array $payload Closed preview payload.
     * @return array Protocol response.
     */
    private function abilities_v2_protection_preview( $payload ) {
        $operation = 'patchstack_protection_preview_v2';
        if ( ! $this->abilities_v2_valid_common_payload( $payload ) ) {
            return $this->abilities_v2_error( $operation, 'invalid_request' );
        }
        $state      = $this->abilities_v2_plugin_state();
        $visibility = $this->abilities_v2_visibility();
        if ( ! in_array( $state, array( 'absent', 'installed', 'active', 'protected', 'unknown' ), true ) || ! in_array( $visibility, array( 'shown', 'hidden' ), true ) ) {
            return $this->abilities_v2_error( $operation, 'invalid_snapshot' );
        }
        $planned_action = array(
            'absent'    => 'install',
            'installed' => 'license',
            'active'    => 'license',
            'protected' => 'none',
            'unknown'   => 'repair',
        );
        $package_state  = $this->abilities_v2_package_state( $state );
        return array(
            'protocol'       => '2',
            'operation'      => $operation,
            'ok'             => true,
            'operation_ref'  => $payload['operation_ref'],
            'current_state'  => $state,
            'planned_action' => $planned_action[ $state ],
            'package_state'  => $package_state,
            'visibility'     => $visibility,
            'state_revision' => $this->abilities_v2_state_revision( $payload, $state, $visibility, $package_state ),
            'observed_at'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
        );
    }

    /** Execute one preview-bound protection convergence attempt. */
    private function abilities_v2_protection_execute( $payload ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity -- Explicit durable reserve/effect/readback/rollback flow.
        $operation = 'patchstack_protection_execute_v2';
        if ( ! $this->abilities_v2_valid_protection_payload( $payload ) ) {
            return $this->abilities_v2_error( $operation, 'invalid_request' );
        }

        $effect_hash = $this->abilities_v2_protection_effect_hash( $payload );
        $receipts    = $this->abilities_v2_read_protection_receipts();
        if ( false === $receipts ) {
            return $this->abilities_v2_error( $operation, 'storage_unavailable' );
        }
        if ( isset( $receipts[ $payload['operation_ref'] ] ) ) {
            return $this->abilities_v2_replay_protection_receipt( $receipts[ $payload['operation_ref'] ], $effect_hash, $operation );
        }

        $state         = $this->abilities_v2_plugin_state();
        $visibility    = $this->abilities_v2_visibility();
        $package_state = $this->abilities_v2_package_state( $state );
        if ( ! in_array( $state, array( 'absent', 'installed', 'active', 'protected', 'unknown' ), true ) || ! in_array( $visibility, array( 'shown', 'hidden' ), true ) ) {
            return $this->abilities_v2_error( $operation, 'invalid_snapshot' );
        }
        $common   = array_intersect_key( $payload, array_flip( array( 'operation_ref', 'expected_plugin_slug', 'provider_binding_digest', 'expires_at' ) ) );
        $revision = $this->abilities_v2_state_revision( $common, $state, $visibility, $package_state );
        if ( ! hash_equals( $revision, $payload['if_match'] ) ) {
            return $this->abilities_v2_error( $operation, 'stale_revision' );
        }
        // 'not_needed' means the plugin is already on disk, so there is no package to verify before licensing it.
        if ( 'protected' !== $state && 'verification_unavailable' === $package_state ) {
            return $this->abilities_v2_error( $operation, 'package_verification_unavailable' );
        }

        $receipts = array_filter(
            $receipts,
            static function ( $receipt ) {
                return 'dispatching' === $receipt['state'] || $receipt['updated_at'] >= time() - 90 * DAY_IN_SECONDS;
            }
        );
        if ( 100 <= count( $receipts ) ) {
            return $this->abilities_v2_error( $operation, 'storage_unavailable' );
        }

        $dispatching                           = array(
            'effect_hash'    => $effect_hash,
            'binding_digest' => $payload['provider_binding_digest'],
            'operation_ref'  => $payload['operation_ref'],
            'prior_state'    => $state,
            'state'          => 'dispatching',
            'result'         => null,
            'updated_at'     => time(),
        );
        $receipts[ $payload['operation_ref'] ] = $dispatching;
        if ( ! $this->abilities_v2_write_protection_receipts( $receipts ) ) {
            return $this->abilities_v2_error( $operation, 'storage_unavailable' );
        }

        if ( 'protected' === $state ) {
            $result = $this->abilities_v2_protection_result( $payload['operation_ref'], 'completed', 'protected', false, null );
            return $this->abilities_v2_settle_protection_receipt( $receipts, $payload['operation_ref'], $dispatching, $result );
        }

        $manifest = null;
        if ( 'not_needed' !== $package_state ) {
            $manifest = $this->abilities_v2_verified_package_manifest();
            if ( ! $this->abilities_v2_valid_manifest( $manifest ) ) {
                $result = $this->abilities_v2_protection_result( $payload['operation_ref'], 'failed', $state, false, 'package_verification_unavailable' );
                return $this->abilities_v2_settle_protection_receipt( $receipts, $payload['operation_ref'], $dispatching, $result );
            }
        }

        $applied = $this->abilities_v2_apply_protection( $payload, $state, $manifest );
        $current = $this->abilities_v2_plugin_state();
        if ( is_array( $applied ) && $this->abilities_v2_exact_keys( $applied, array( 'changed' ) ) && is_bool( $applied['changed'] ) && 'protected' === $current ) {
            $result = $this->abilities_v2_protection_result( $payload['operation_ref'], 'completed', $current, $applied['changed'], null );
            return $this->abilities_v2_settle_protection_receipt( $receipts, $payload['operation_ref'], $dispatching, $result );
        }

        $rolled_back = $this->abilities_v2_rollback_protection( $state );
        $restored    = $this->abilities_v2_plugin_state();
        $code        = true === $rolled_back && $state === $restored ? 'write_failed' : 'outcome_unknown';
        $result      = $this->abilities_v2_protection_result( $payload['operation_ref'], 'write_failed' === $code ? 'failed' : 'unknown', $restored, false, $code );
        return $this->abilities_v2_settle_protection_receipt( $receipts, $payload['operation_ref'], $dispatching, $result );
    }

    /** Return one durable protection result without dispatching. */
    private function abilities_v2_protection_status( $payload ) {
        $operation = 'patchstack_protection_status_v2';
        if ( ! $this->abilities_v2_valid_common_payload( $payload ) ) {
            return $this->abilities_v2_error( $operation, 'invalid_request' );
        }
        $receipts = $this->abilities_v2_read_protection_receipts();
        if ( false === $receipts ) {
            return $this->abilities_v2_error( $operation, 'storage_unavailable' );
        }
        if ( ! isset( $receipts[ $payload['operation_ref'] ] ) ) {
            return $this->abilities_v2_error( $operation, 'operation_not_found' );
        }
        $receipt = $receipts[ $payload['operation_ref'] ];
        if ( ! hash_equals( $receipt['binding_digest'], $payload['provider_binding_digest'] ) ) {
            return $this->abilities_v2_error( $operation, 'operation_not_found' );
        }
        $result              = 'dispatching' === $receipt['state'] ? $this->abilities_v2_protection_result( $payload['operation_ref'], 'unknown', $receipt['prior_state'], false, 'outcome_unknown' ) : $receipt['result'];
        $result['operation'] = $operation;
        return $result;
    }

    /**
     * Replace and verify the local Patchstack visibility preference.
     *
     * @param array $payload Closed visibility payload.
     * @return array Protocol response.
     */
    private function abilities_v2_visibility_replace( $payload ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity -- Explicit receipt/write/readback flow.
        $operation = 'patchstack_visibility_replace_v2';
        if ( ! is_array( $payload ) || ! $this->abilities_v2_exact_keys( $payload, array( 'operation_ref', 'expected_plugin_slug', 'provider_binding_digest', 'expires_at', 'desired_state', 'if_match' ) ) || ! in_array( isset( $payload['desired_state'] ) ? $payload['desired_state'] : null, array( 'shown', 'hidden' ), true ) || ! isset( $payload['if_match'] ) || ! $this->abilities_v2_hash( $payload['if_match'] ) ) {
            return $this->abilities_v2_error( $operation, 'invalid_request' );
        }
        $common = array_intersect_key( $payload, array_flip( array( 'operation_ref', 'expected_plugin_slug', 'provider_binding_digest', 'expires_at' ) ) );
        if ( ! $this->abilities_v2_valid_common_payload( $common ) ) {
            return $this->abilities_v2_error( $operation, 'invalid_request' );
        }
        $effect_hash = hash( 'sha256', wp_json_encode( $payload ) );
        $receipts    = $this->abilities_v2_read_receipts();
        if ( false === $receipts ) {
            return $this->abilities_v2_error( $operation, 'storage_unavailable' );
        }
        if ( isset( $receipts[ $payload['operation_ref'] ] ) ) {
            $receipt = $receipts[ $payload['operation_ref'] ];
            if ( ! hash_equals( $receipt['effect_hash'], $effect_hash ) ) {
                return $this->abilities_v2_error( $operation, 'request_conflict' );
            }
            return 'completed' === $receipt['state'] ? $receipt['response'] : $this->abilities_v2_error( $operation, 'outcome_unknown' );
        }

        $receipts = array_filter(
            $receipts,
            static function ( $receipt ) {
                return 'pending' === $receipt['state'] || $receipt['updated_at'] >= time() - 90 * DAY_IN_SECONDS;
            }
        );
        if ( 100 <= count( $receipts ) ) {
            return $this->abilities_v2_error( $operation, 'storage_unavailable' );
        }

        $state      = $this->abilities_v2_plugin_state();
        $visibility = $this->abilities_v2_visibility();
        if ( ! in_array( $state, array( 'absent', 'installed', 'active', 'protected', 'unknown' ), true ) || ! in_array( $visibility, array( 'shown', 'hidden' ), true ) ) {
            return $this->abilities_v2_error( $operation, 'invalid_snapshot' );
        }
        // The preview that issued if_match binds the real package state, so the default would never match on a site without Patchstack.
        $package_state = $this->abilities_v2_package_state( $state );
        $revision      = $this->abilities_v2_state_revision( $common, $state, $visibility, $package_state );
        if ( ! hash_equals( $revision, $payload['if_match'] ) ) {
            return $this->abilities_v2_error( $operation, 'stale_revision' );
        }

        $receipts[ $payload['operation_ref'] ] = array(
            'effect_hash' => $effect_hash,
            'state'       => 'pending',
            'response'    => null,
            'updated_at'  => time(),
        );
        if ( ! $this->abilities_v2_write_receipts( $receipts ) ) {
            return $this->abilities_v2_error( $operation, 'storage_unavailable' );
        }

        $changed = $visibility !== $payload['desired_state'];
        if ( $changed ) {
            $write = $this->abilities_v2_write_visibility( $payload['desired_state'] );
            if ( ! is_array( $write ) || ! $this->abilities_v2_exact_keys( $write, array( 'prior', 'current' ) ) || $visibility !== $write['prior'] || $payload['desired_state'] !== $write['current'] ) {
                $this->abilities_v2_restore_visibility( $visibility );
                $response = $this->abilities_v2_error( $operation, 'write_failed' );
                return $this->abilities_v2_complete_receipt( $receipts, $payload['operation_ref'], $effect_hash, $response );
            }
        }

        $current = $this->abilities_v2_visibility();
        if ( $payload['desired_state'] !== $current ) {
            $this->abilities_v2_restore_visibility( $visibility );
            $response = $this->abilities_v2_error( $operation, 'write_failed' );
            return $this->abilities_v2_complete_receipt( $receipts, $payload['operation_ref'], $effect_hash, $response );
        }
        $response = array(
            'protocol'       => '2',
            'operation'      => $operation,
            'ok'             => true,
            'operation_ref'  => $payload['operation_ref'],
            'visibility'     => $current,
            'changed'        => $changed,
            'state_revision' => $this->abilities_v2_state_revision( $common, $state, $current, $package_state ),
        );
        return $this->abilities_v2_complete_receipt( $receipts, $payload['operation_ref'], $effect_hash, $response );
    }

    /** Return the local installed/active protection state. */
    protected function abilities_v2_plugin_state() {
        $installed = defined( 'WP_PLUGIN_DIR' ) && file_exists( WP_PLUGIN_DIR . '/' . $this->the_plugin_slug );
        if ( ! $installed ) {
            return 'absent';
        }
        return function_exists( 'is_plugin_active' ) && is_plugin_active( $this->the_plugin_slug ) ? 'active' : 'installed';
    }

    /** Return the exact local visibility preference. */
    protected function abilities_v2_visibility() {
        $value = get_site_option( $this->option_hide_name, false );
        if ( 'hide' === $value ) {
            return 'hidden';
        }
        return false === $value || '' === $value ? 'shown' : false;
    }

    /**
     * Write and read back one visibility preference.
     *
     * @param string $visibility Desired visibility.
     * @return array Readback snapshot.
     */
    protected function abilities_v2_write_visibility( $visibility ) {
        $prior = $this->abilities_v2_visibility();
        update_site_option( $this->option_hide_name, 'hidden' === $visibility ? 'hide' : '' );
        return array(
            'prior'   => $prior,
            'current' => $this->abilities_v2_visibility(),
        );
    }

    /**
     * Restore and verify one visibility preference.
     *
     * @param string $visibility Prior visibility.
     * @return bool Whether restoration was verified.
     */
    protected function abilities_v2_restore_visibility( $visibility ) {
        update_site_option( $this->option_hide_name, 'hidden' === $visibility ? 'hide' : '' );
        return $visibility === $this->abilities_v2_visibility();
    }

    /**
     * Return a publisher-verified package manifest.
     *
     * Patchstack currently exposes no committed signing trust root to the Child.
     * Keep installation unavailable until that publisher artifact is supplied.
     *
     * @return array|null Verified manifest, or null when unavailable.
     */
    protected function abilities_v2_verified_package_manifest() {
        return null;
    }

    /**
     * Apply one already verified package/license effect.
     *
     * The production implementation remains unavailable until the publisher
     * supplies the signed artifact and a direct supported licensing contract.
     *
     * @param array      $payload  Closed execution payload.
     * @param string     $before   Prior plugin state.
     * @param array|null $manifest Verified package manifest, or null when the plugin is already installed.
     * @return array|false Apply result.
     */
    protected function abilities_v2_apply_protection( $payload, $before, $manifest ) {
        unset( $payload, $before, $manifest );
        return false;
    }

    /**
     * Restore the exact prior plugin state after a failed convergence attempt.
     *
     * @param string $before Prior plugin state.
     * @return bool Whether rollback was exactly verified.
     */
    protected function abilities_v2_rollback_protection( $before ) {
        unset( $before );
        return false;
    }

    /** Read and validate bounded protection receipts. */
    protected function abilities_v2_read_protection_receipts() {
        $receipts = get_site_option( self::ABILITIES_V2_PROTECTION_RECEIPTS_OPTION, array() );
        if ( ! is_array( $receipts ) || 100 < count( $receipts ) ) {
            return false;
        }
        foreach ( $receipts as $operation_ref => $receipt ) {
            if ( ! $this->abilities_v2_valid_uuid( $operation_ref ) || ! $this->abilities_v2_valid_protection_receipt( $receipt, $operation_ref ) ) {
                return false;
            }
        }
        return $receipts;
    }

    /** Write and exactly read back bounded protection receipts. */
    protected function abilities_v2_write_protection_receipts( $receipts ) {
        if ( ! is_array( $receipts ) || 100 < count( $receipts ) ) {
            return false;
        }
        foreach ( $receipts as $operation_ref => $receipt ) {
            if ( ! $this->abilities_v2_valid_uuid( $operation_ref ) || ! $this->abilities_v2_valid_protection_receipt( $receipt, $operation_ref ) ) {
                return false;
            }
        }
        update_site_option( self::ABILITIES_V2_PROTECTION_RECEIPTS_OPTION, $receipts );
        return get_site_option( self::ABILITIES_V2_PROTECTION_RECEIPTS_OPTION, null ) === $receipts;
    }

    /** Read and validate bounded visibility receipts. */
    protected function abilities_v2_read_receipts() {
        $receipts = get_site_option( self::ABILITIES_V2_RECEIPTS_OPTION, array() );
        if ( ! is_array( $receipts ) || 100 < count( $receipts ) ) {
            return false;
        }
        foreach ( $receipts as $operation_ref => $receipt ) {
            if ( ! $this->abilities_v2_valid_uuid( $operation_ref ) || ! $this->abilities_v2_valid_receipt( $receipt ) ) {
                return false;
            }
        }
        return $receipts;
    }

    /**
     * Write and exactly read back bounded visibility receipts.
     *
     * @param array $receipts Receipt map.
     * @return bool Whether persistence was verified.
     */
    protected function abilities_v2_write_receipts( $receipts ) {
        if ( ! is_array( $receipts ) || 100 < count( $receipts ) ) {
            return false;
        }
        update_site_option( self::ABILITIES_V2_RECEIPTS_OPTION, $receipts );
        return get_site_option( self::ABILITIES_V2_RECEIPTS_OPTION, null ) === $receipts;
    }

    /** Acquire the local visibility mutation lock. */
    protected function abilities_v2_begin_mutation() {
        $now      = time();
        $existing = get_site_option( self::ABILITIES_V2_LOCK_OPTION, null );
        if ( is_array( $existing ) && isset( $existing['expires_at'] ) && is_int( $existing['expires_at'] ) && $existing['expires_at'] >= $now ) {
            return false;
        }
        if ( null !== $existing && ( ! delete_site_option( self::ABILITIES_V2_LOCK_OPTION ) || null !== get_site_option( self::ABILITIES_V2_LOCK_OPTION, null ) ) ) {
            return false;
        }
        $owner = wp_generate_uuid4();
        $lock  = array(
            'owner'      => $owner,
            'expires_at' => $now + 60,
        );
        if ( ! add_site_option( self::ABILITIES_V2_LOCK_OPTION, $lock ) || get_site_option( self::ABILITIES_V2_LOCK_OPTION, null ) !== $lock ) {
            return false;
        }
        $this->abilities_v2_lock_owner = $owner;
        return true;
    }

    /** Release and verify the local visibility mutation lock. */
    protected function abilities_v2_end_mutation() {
        $lock = get_site_option( self::ABILITIES_V2_LOCK_OPTION, null );
        if ( '' === $this->abilities_v2_lock_owner || ! is_array( $lock ) || ! isset( $lock['owner'] ) || ! is_string( $lock['owner'] ) || ! hash_equals( $this->abilities_v2_lock_owner, $lock['owner'] ) ) {
            $this->abilities_v2_lock_owner = '';
            return false;
        }
        delete_site_option( self::ABILITIES_V2_LOCK_OPTION );
        $this->abilities_v2_lock_owner = '';
        return null === get_site_option( self::ABILITIES_V2_LOCK_OPTION, null );
    }

    /**
     * Complete and persist one visibility receipt.
     *
     * @param array  $receipts     Receipt map.
     * @param string $operation_ref Operation reference.
     * @param string $effect_hash   Canonical effect digest.
     * @param array  $response      Closed response.
     * @return array Persisted response or stable error.
     */
    private function abilities_v2_complete_receipt( $receipts, $operation_ref, $effect_hash, $response ) {
        $receipts[ $operation_ref ] = array(
            'effect_hash' => $effect_hash,
            'state'       => 'completed',
            'response'    => $response,
            'updated_at'  => time(),
        );
        return $this->abilities_v2_write_receipts( $receipts ) ? $response : $this->abilities_v2_error( 'patchstack_visibility_replace_v2', 'outcome_unknown' );
    }

    /**
     * Validate the shared preview/mutation binding.
     *
     * @param array $payload Closed payload.
     * @return bool Whether the payload is valid.
     */
    private function abilities_v2_valid_common_payload( $payload ) {
        return is_array( $payload )
            && $this->abilities_v2_exact_keys( $payload, array( 'operation_ref', 'expected_plugin_slug', 'provider_binding_digest', 'expires_at' ) )
            && $this->abilities_v2_valid_uuid( $payload['operation_ref'] )
            && 'patchstack/patchstack.php' === $payload['expected_plugin_slug']
            && $this->abilities_v2_hash( $payload['provider_binding_digest'] )
            && is_int( $payload['expires_at'] )
            && time() <= $payload['expires_at']
            && time() + 600 >= $payload['expires_at'];
    }

    /**
     * Return the bound local state revision.
     *
     * @param array  $payload       Bound payload.
     * @param string $state         Plugin state.
     * @param string $visibility    Visibility state.
     * @param string $package_state Signed-package readiness.
     * @return string State digest.
     */
    private function abilities_v2_state_revision( $payload, $state, $visibility, $package_state = 'not_needed' ) {
        return hash( 'sha256', wp_json_encode( array( $payload['expected_plugin_slug'], $payload['provider_binding_digest'], $state, $visibility, $package_state ) ) );
    }

    /** Return signed-package readiness without exposing manifest material. */
    private function abilities_v2_package_state( $state ) {
        if ( in_array( $state, array( 'installed', 'active', 'protected' ), true ) ) {
            return 'not_needed';
        }
        return $this->abilities_v2_valid_manifest( $this->abilities_v2_verified_package_manifest() ) ? 'verified_available' : 'verification_unavailable';
    }

    /** Validate one redacted publisher-verified package manifest. */
    private function abilities_v2_valid_manifest( $manifest ) {
        return is_array( $manifest )
            && $this->abilities_v2_exact_keys( $manifest, array( 'version', 'package_sha256', 'manifest_digest' ) )
            && is_string( $manifest['version'] )
            && 1 === preg_match( '/^[0-9A-Za-z][0-9A-Za-z.+_-]{0,63}$/D', $manifest['version'] )
            && $this->abilities_v2_hash( $manifest['package_sha256'] )
            && $this->abilities_v2_hash( $manifest['manifest_digest'] );
    }

    /** Validate one exact protection execute payload. */
    private function abilities_v2_valid_protection_payload( $payload ) {
        if ( ! is_array( $payload ) || ! $this->abilities_v2_exact_keys( $payload, array( 'operation_ref', 'expected_plugin_slug', 'provider_binding_digest', 'expires_at', 'if_match', 'license_token' ) ) || ! $this->abilities_v2_hash( $payload['if_match'] ) || ! is_string( $payload['license_token'] ) || '' === $payload['license_token'] || 4096 < strlen( $payload['license_token'] ) || preg_match( '/[\x00-\x1F\x7F]/', $payload['license_token'] ) ) {
            return false;
        }
        $common = array_intersect_key( $payload, array_flip( array( 'operation_ref', 'expected_plugin_slug', 'provider_binding_digest', 'expires_at' ) ) );
        return $this->abilities_v2_valid_common_payload( $common );
    }

    /** Derive the protection effect digest without retaining the license token. */
    protected function abilities_v2_protection_effect_hash( $payload ) {
        return hash(
            'sha256',
            wp_json_encode(
                array(
                    'operation_ref'           => $payload['operation_ref'],
                    'expected_plugin_slug'    => $payload['expected_plugin_slug'],
                    'provider_binding_digest' => $payload['provider_binding_digest'],
                    'expires_at'              => $payload['expires_at'],
                    'if_match'                => $payload['if_match'],
                    'license_token_digest'    => hash( 'sha256', $payload['license_token'] ),
                )
            )
        );
    }

    /** Replay one exact protection receipt. */
    private function abilities_v2_replay_protection_receipt( $receipt, $effect_hash, $operation ) {
        if ( ! $this->abilities_v2_valid_protection_receipt( $receipt ) ) {
            return $this->abilities_v2_error( $operation, 'storage_unavailable' );
        }
        if ( ! hash_equals( $receipt['effect_hash'], $effect_hash ) ) {
            return $this->abilities_v2_error( $operation, 'request_conflict' );
        }
        return 'dispatching' === $receipt['state'] ? $this->abilities_v2_protection_result_from_receipt( $receipt, $operation ) : $receipt['result'];
    }

    /** Replace one dispatch marker with a closed terminal result. */
    private function abilities_v2_settle_protection_receipt( $receipts, $operation_ref, $dispatching, $result ) {
        $settled                    = $dispatching;
        $settled['state']           = 'settled';
        $settled['result']          = $result;
        $settled['updated_at']      = time();
        $receipts[ $operation_ref ] = $settled;
        if ( $this->abilities_v2_write_protection_receipts( $receipts ) ) {
            return $result;
        }
        $stored = $this->abilities_v2_read_protection_receipts();
        return is_array( $stored ) && isset( $stored[ $operation_ref ] ) && $settled === $stored[ $operation_ref ] ? $result : $this->abilities_v2_protection_result_from_receipt( $dispatching, 'patchstack_protection_execute_v2' );
    }

    /** Build one closed public protection result. */
    private function abilities_v2_protection_result( $operation_ref, $status, $current_state, $changed, $code ) {
        return array(
            'protocol'      => '2',
            'operation'     => 'patchstack_protection_execute_v2',
            'ok'            => 'completed' === $status,
            'operation_ref' => $operation_ref,
            'status'        => $status,
            'current_state' => $current_state,
            'changed'       => $changed,
            'code'          => $code,
        );
    }

    /** Return the only truthful result for an interrupted reserved effect. */
    private function abilities_v2_protection_result_from_receipt( $receipt, $operation ) {
        $result              = $this->abilities_v2_protection_result( $receipt['operation_ref'], 'unknown', $receipt['prior_state'], false, 'outcome_unknown' );
        $result['operation'] = $operation;
        return $result;
    }

    /** Validate one closed durable protection receipt. */
    private function abilities_v2_valid_protection_receipt( $receipt, $operation_ref = null ) {
        if ( ! is_array( $receipt ) || ! $this->abilities_v2_exact_keys( $receipt, array( 'effect_hash', 'binding_digest', 'operation_ref', 'prior_state', 'state', 'result', 'updated_at' ) ) || ! $this->abilities_v2_hash( $receipt['effect_hash'] ) || ! $this->abilities_v2_hash( $receipt['binding_digest'] ) || ! $this->abilities_v2_valid_uuid( $receipt['operation_ref'] ) || ! in_array( $receipt['prior_state'], array( 'absent', 'installed', 'active', 'protected', 'unknown' ), true ) || ! in_array( $receipt['state'], array( 'dispatching', 'settled' ), true ) || ! is_int( $receipt['updated_at'] ) || 0 >= $receipt['updated_at'] ) {
            return false;
        }
        if ( 'dispatching' === $receipt['state'] ) {
            return null === $receipt['result'];
        }
        if ( ! $this->abilities_v2_valid_protection_result( $receipt['result'] ) ) {
            return false;
        }
        return ( null === $operation_ref || hash_equals( $operation_ref, $receipt['operation_ref'] ) ) && hash_equals( $receipt['operation_ref'], $receipt['result']['operation_ref'] );
    }

    /** Validate one closed public protection result. */
    private function abilities_v2_valid_protection_result( $result ) {
        if ( ! is_array( $result ) || ! $this->abilities_v2_exact_keys( $result, array( 'protocol', 'operation', 'ok', 'operation_ref', 'status', 'current_state', 'changed', 'code' ) ) || '2' !== $result['protocol'] || 'patchstack_protection_execute_v2' !== $result['operation'] || ! is_bool( $result['ok'] ) || ! $this->abilities_v2_valid_uuid( $result['operation_ref'] ) || ! in_array( $result['status'], array( 'completed', 'failed', 'unknown' ), true ) || ! in_array( $result['current_state'], array( 'absent', 'installed', 'active', 'protected', 'unknown' ), true ) || ! is_bool( $result['changed'] ) || ( null !== $result['code'] && ! in_array( $result['code'], array( 'package_verification_unavailable', 'write_failed', 'outcome_unknown' ), true ) ) ) {
            return false;
        }
        return ( 'completed' === $result['status'] ) === $result['ok'] && ( $result['ok'] ? null === $result['code'] : null !== $result['code'] );
    }

    /**
     * Validate one durable receipt.
     *
     * @param mixed $receipt Receipt value.
     * @return bool Whether the receipt is valid.
     */
    private function abilities_v2_valid_receipt( $receipt ) {
        return is_array( $receipt )
            && $this->abilities_v2_exact_keys( $receipt, array( 'effect_hash', 'state', 'response', 'updated_at' ) )
            && $this->abilities_v2_hash( $receipt['effect_hash'] )
            && in_array( $receipt['state'], array( 'pending', 'completed' ), true )
            && ( ( 'pending' === $receipt['state'] && null === $receipt['response'] ) || ( 'completed' === $receipt['state'] && $this->abilities_v2_valid_visibility_response( $receipt['response'] ) ) )
            && is_int( $receipt['updated_at'] )
            && 0 < $receipt['updated_at'];
    }

    /**
     * Validate one closed visibility receipt response.
     *
     * @param mixed $response Response value.
     * @return bool Whether the response is valid.
     */
    private function abilities_v2_valid_visibility_response( $response ) {
        if ( ! is_array( $response ) || ! isset( $response['ok'] ) || ! is_bool( $response['ok'] ) || '2' !== ( isset( $response['protocol'] ) ? $response['protocol'] : null ) || 'patchstack_visibility_replace_v2' !== ( isset( $response['operation'] ) ? $response['operation'] : null ) ) {
            return false;
        }
        if ( false === $response['ok'] ) {
            return $this->abilities_v2_exact_keys( $response, array( 'protocol', 'operation', 'ok', 'code' ) )
                && is_string( $response['code'] )
                && in_array( $response['code'], array( 'write_failed', 'outcome_unknown' ), true );
        }
        return $this->abilities_v2_exact_keys( $response, array( 'protocol', 'operation', 'ok', 'operation_ref', 'visibility', 'changed', 'state_revision' ) )
            && $this->abilities_v2_valid_uuid( $response['operation_ref'] )
            && in_array( $response['visibility'], array( 'shown', 'hidden' ), true )
            && is_bool( $response['changed'] )
            && $this->abilities_v2_hash( $response['state_revision'] );
    }

    /**
     * Validate a UUID operation reference.
     *
     * @param mixed $value Candidate value.
     * @return bool Whether the value is valid.
     */
    private function abilities_v2_valid_uuid( $value ) {
        return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $value );
    }

    /**
     * Validate a SHA-256 digest.
     *
     * @param mixed $value Candidate value.
     * @return bool Whether the value is valid.
     */
    private function abilities_v2_hash( $value ) {
        return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $value );
    }

    /**
     * Check that an array contains exactly the expected keys.
     *
     * @param array $value Value to inspect.
     * @param array $keys  Expected keys.
     * @return bool
     */
    private function abilities_v2_exact_keys( $value, $keys ) {
        if ( ! is_array( $value ) ) {
            return false;
        }

        $actual = array_keys( $value );
        sort( $actual );
        sort( $keys );
        return $actual === $keys;
    }

    /**
     * Build a closed Patchstack abilities error response.
     *
     * @param string $operation Operation name.
     * @param string $code      Stable error code.
     * @return array
     */
    private function abilities_v2_error( $operation, $code ) {
        return array(
            'protocol'  => '2',
            'operation' => is_string( $operation ) && '' !== $operation ? $operation : 'unknown',
            'ok'        => false,
            'code'      => $code,
        );
    }

    /**
     * Hide BackWPup Plugin from the WordPress Installed plugin list.
     *
     * @param array $plugins Installed plugins.
     * @return array $plugins Installed plugins without BackWPup Plugin on the list.
     */
    public function all_plugins( $plugins ) {
        foreach ( $plugins as $key => $value ) {
            $plugin_slug = basename( $key, '.php' );
            if ( 'patchstack' === $plugin_slug ) {
                unset( $plugins[ $key ] );
            }
        }

        return $plugins;
    }
    /**
     * Remove Patchstack Plugin from the WordPress Admin.
     */
    public function remove_menu() {
        // Remove patchstack from the admin menu.
        remove_menu_page( 'options-general.php?page=patchstack' );
        global $submenu;

        // Remove the WordPress Admin SubMenu.
        if ( isset( $submenu['patchstack'] ) ) {
            unset( $submenu['patchstack'] );
        }

        if ( isset( $submenu['options-general.php'] ) ) {
            foreach ( $submenu['options-general.php'] as $index => $item ) {
                if ( 'patchstack' === $item[2] ) {
                    unset( $submenu['options-general.php'][ $index ] );
                    break;
                }
            }
        }

        $pos = isset( $_SERVER['REQUEST_URI'] ) ? stripos( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), 'options-general.php?page=patchstack' ) : false;
        if ( false !== $pos ) {
            wp_safe_redirect( get_option( 'siteurl' ) . '/wp-admin/index.php' );
            exit();
        }
    }

    /**
     * Method sync_others_data()
     *
     * Sync the Patchstack plugin settings.
     *
     * @param  array $information Array containing the sync information.
     * @param  array $data        Array containing the Patchstack plugin data to be synced.
     *
     * @return array $information Array containing the sync information.
     */
    public function sync_others_data( $information, $data = array() ) {
        if ( isset( $data['sync_patchstack_data'] ) && ( 'yes' === $data['sync_patchstack_data'] ) ) {
            try {
                $data['plugin_hide_status']          = get_site_option( $this->option_hide_name, false );
                $information['sync_patchstack_data'] = $data;
            } catch ( MainWP_Exception $e ) {
                // ok!
            }
        }
        return $information;
    }

    /**
     * Method install_plugin()
     *
     * Get the Patchstack Insights plugin data and store it in the sync request.
     *
     * @return array $information Array containing the sync information.
     */
    /**
     * Install (overwrite if exists) and activate the Patchstack plugin.
     *
     * @return array|\WP_Error
     */
    private function install_plugin() {  // phpcs:ignore -- NOSONAR -- complexity
        // Read settings.
        $raw_settings = $this->sanitized_post( 'settings' );
        $settings     = json_decode( $raw_settings, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return new \WP_Error( 'json_decode_error', 'Invalid JSON in settings: ' . json_last_error_msg() );
        }

        if ( empty( $settings['ps_id'] ) || empty( $settings['token'] ) ) {
            return new \WP_Error( 'bad_params', 'Missing ps_id or token.' );
        }

        // Call endpoint returns FILE (ZIP), not JSON.
        $url    = '/download/wordpress/' . intval( $settings['ps_id'] );
        $binary = $this->send_request( $url, $settings['token'], 'GET', array(), false );

        if ( is_wp_error( $binary ) ) {
            return $binary;
        }
        if ( is_array( $binary ) ) {
            // API should return file; if it returns JSON then it is a business error.
            return new \WP_Error( 'api_error', 'Unexpected JSON for download endpoint.', $binary );
        }
        if ( ! is_string( $binary ) || '' === $binary ) {
            return new \WP_Error( 'empty_file', 'Empty plugin file.' );
        }

        require_once ABSPATH . 'wp-admin/includes/plugin.php';  // phpcs:ignore -- NOSONAR
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';  // phpcs:ignore -- NOSONAR
        require_once ABSPATH . 'wp-admin/includes/file.php';  // phpcs:ignore -- NOSONAR
        require_once ABSPATH . 'wp-admin/includes/misc.php';  // phpcs:ignore -- NOSONAR

        // Write ZIP to temporary file.
        $tmp = wp_tempnam( 'patchstack.zip' );
        if ( ! $tmp ) {
            return new \WP_Error( 'tmp_fail', 'Failed to create temp file.' );
        }

        $bytes = @file_put_contents( $tmp, $binary, LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        if ( false === $bytes || 0 === $bytes ) {
            @unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            return new \WP_Error( 'write_fail', 'Failed to write plugin ZIP to temp file.' );
        }

        // Check slug.
        if ( empty( $this->the_plugin_slug ) || strpos( $this->the_plugin_slug, '/' ) === false ) {
            return new \WP_Error( 'bad_slug', 'Invalid plugin slug (expected "patchstack/patchstack.php").' );
        }

        // Deactivate if active.
        $was_active = function_exists( 'is_plugin_active' ) ? is_plugin_active( $this->the_plugin_slug ) : false;
        if ( $was_active ) {
            deactivate_plugins( $this->the_plugin_slug, true );
            if ( function_exists( 'is_plugin_active' ) && is_plugin_active( $this->the_plugin_slug ) ) {
                return new \WP_Error( 'deactivate_failed', 'Failed to deactivate existing plugin.' );
            }
        }

        // Delete plugin if exists (file or folder).
        $plugin_dir = WP_PLUGIN_DIR . '/' . dirname( $this->the_plugin_slug );
        if ( file_exists( WP_PLUGIN_DIR . '/' . $this->the_plugin_slug ) || is_dir( $plugin_dir ) ) {
            // delete_plugins() will try to deactivate if needed and remove files.
            $deleted = delete_plugins( array( $this->the_plugin_slug ) );
            if ( is_wp_error( $deleted ) ) {
                return new \WP_Error(
                    'delete_failed',
                    'Failed to delete existing plugin folder.',
                    array( 'error' => $deleted->get_error_message() )
                );
            }
            // In some envs a residual folder may remain; hard-delete with WP_Filesystem.
            if ( is_dir( $plugin_dir ) ) {
                global $wp_filesystem;
                if ( ! $wp_filesystem ) {
                    WP_Filesystem();
                }
                if ( $wp_filesystem && $wp_filesystem->is_dir( $plugin_dir ) ) {
                    $wp_filesystem->delete( $plugin_dir, true );
                }
                if ( is_dir( $plugin_dir ) ) {
                    return new \WP_Error( 'delete_residual_failed', 'Plugin directory still exists after deletion.' );
                }
            }
        }

        // Install/overwrite with Plugin_Upgrader (WordPress core standard).
        $skin     = new \Automatic_Upgrader_Skin();
        $upgrader = new \Plugin_Upgrader( $skin );

        $options_filter = static function ( $options ) {
            $options['clear_destination']           = true;
            $options['abort_if_destination_exists'] = false;
            return $options;
        };
        add_filter( 'upgrader_package_options', $options_filter );

        try {
            $installed = $upgrader->install( $tmp );
        } finally {
            remove_filter( 'upgrader_package_options', $options_filter );
            @unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }

        if ( is_wp_error( $installed ) ) {
            return $installed;
        }
        if ( ! $installed ) {
            return new \WP_Error( 'install_failed', 'Plugin installation failed.' );
        }

        // Check plugin file.
        $plugin_file = '';
        if ( is_array( $upgrader->result ?? null ) ) {
            if ( ! empty( $upgrader->result['plugin'] ) ) {
                $plugin_file = $upgrader->result['plugin'];
            } elseif ( ! empty( $upgrader->result['destination'] ) ) {
                $dest_dir = trailingslashit( $upgrader->result['destination'] );
                $all      = get_plugins();
                foreach ( $all as $rel => $headers ) {
                    if ( strpos( WP_PLUGIN_DIR . '/' . $rel, $dest_dir ) === 0 ) {
                        $plugin_file = $rel;
                        break;
                    }
                }
            }
        }

        if ( ! $plugin_file && ! empty( $this->the_plugin_slug ) && file_exists( WP_PLUGIN_DIR . '/' . $this->the_plugin_slug ) ) {
            $plugin_file = $this->the_plugin_slug;
        }

        if ( ! $plugin_file ) {
            return new \WP_Error( 'main_file_missing', 'Installed but plugin main file not found.' );
        }

        return array(
            'success'     => 1,
            'is_active'   => is_plugin_active( $this->the_plugin_slug ),
            'plugin_file' => $this->the_plugin_slug,
            'message'     => $message,
        );
    }

    /**
     * Method set_showhide()
     *
     * Hide or unhide the Patchstack Insights plugin.
     *
     * @return array $information Array containing the sync information.
     */
    private function set_showhide() {
        $raw  = $this->sanitized_post( 'show_hide' );
        $hide = ( 'hide' === sanitize_text_field( $raw ) ) ? 'hide' : '';
        update_site_option( $this->option_hide_name, $hide );

        return array( 'success' => 1 );
    }

    /**
     * Summary of sync_data
     *
     * @return array{data: mixed, success: int|array{error: string}}`
     */
    private function sync_data() {
        $oauth = $this->sanitized_post( 'oauth' );
        if ( empty( $oauth ) ) {
            return array( 'error' => 'Missing oauth data.' );
        }
        $response = wp_remote_post(
            admin_url( 'admin-ajax.php' ),
            array(
                'timeout'   => 30,
                'sslverify' => false,
                'body'      => array(
                    'action' => 'patchstack_activate_license',
                    'key'    => $oauth,
                ),
            )
        );

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );
        return array(
            'success' => 1,
            'data'    => $data,
        );
    }

    /**
     * Method save_settings()
     *
     * Save the Patchstack Insights plugin settings.
     *
     * @return array $information Array containing the sync information.
     */
    private function save_settings() {
        return array( 'success' => 1 );
    }

    /**
     * Send an HTTP request to the API and return JSON (array) or raw string (binary).
     *
     * @param string            $url         API endpoint (e.g. '/site/plugin/resync/123').
     * @param string            $token       API token.
     * @param string            $method      HTTP method. Default 'GET'.
     * @param array|string|null $data        Data for non-GET methods (auto JSON-encoded if array).
     * @param bool              $expect_json Expect JSON response (true) or raw/binary (false).
     *
     * @return array|string|\WP_Error JSON array, raw string, or WP_Error on failure.
     */
    private function send_request( $url, $token, $method = 'GET', $data = array(), $expect_json = true ) {  // phpcs:ignore -- NOSONAR
        if ( empty( $token ) ) {
            return new \WP_Error( 'no_token', 'Missing API token.' );
        }

        $method = strtoupper( $method );
        $args   = array(
            'method'      => $method,
            'timeout'     => 90,
            'redirection' => 5,
            'blocking'    => true,
            'headers'     => array(
                'UserToken' => $token,
                'Accept'    => $expect_json ? 'application/json' : '*/*', // NOSONAR.
            ),
        );

        // Only send body when not GET/HEAD.
        if ( ! empty( $data ) && ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
            if ( is_array( $data ) ) {
                $args['headers']['Content-Type'] = 'application/json';
                $args['body']                    = wp_json_encode( $data );
            } else {
                $args['body'] = (string) $data;
            }
        }

        $response = wp_remote_request( trailingslashit( $this->api_url ) . ltrim( $url, '/' ), $args );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code  = (int) wp_remote_retrieve_response_code( $response );
        $body  = wp_remote_retrieve_body( $response );
        $ctype = (string) wp_remote_retrieve_header( $response, 'content-type' );

        if ( 200 !== $code ) {
            // If the server returns an error JSON → try to parse it to get specific information.
            $message = "HTTP $code";
            if ( stripos( $ctype, 'application/json' ) !== false ) {
                $json = json_decode( $body, true );
                if ( is_array( $json ) ) {
                    $message = $json['error'] ?? $json['message'] ?? $message;
                }
            }
            return new \WP_Error(
                'http_error',
                $message,
                array(
                    'status' => $code,
                    'body'   => $body,
                    'ctype'  => $ctype,
                )
            );
        }

        if ( $expect_json || stripos( $ctype, 'application/json' ) !== false ) {
            if ( '' === $body ) {
                return array();
            }
            $decoded = json_decode( $body, true );
            if ( json_last_error() !== JSON_ERROR_NONE ) {
                return new \WP_Error(
                    'json_decode',
                    'Invalid JSON: ' . json_last_error_msg(),
                    array(
                        'body' => $body,
                    )
                );
            }
            return $decoded;
        }

        return $body;
    }

    /**
     * Method sanitized_post()
     *
     * Sanitized post field.
     *
     * @param string $key key to get from POST.
     * @param string $callback cleaning method.
     * @param mixed  $default_value Default return value.
     *
     * @return mixed data value.
     */
    private static function sanitized_post( $key, $callback = 'sanitize_text_field', $default_value = '' ) {
        if ( ! in_array( $callback, self::$allowed_callbacks, true ) ) {
            $callback = 'sanitize_text_field';
        }

        return isset( $_POST[ $key ] ) ? $callback( wp_unslash( $_POST[ $key ] ) ) : $default_value; // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    }
}
