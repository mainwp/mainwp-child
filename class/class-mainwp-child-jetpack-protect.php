<?php
/**
 * MainWP Child Jetpack Protect.
 *
 * MainWP Jetpack Protect Extension handler.
 *
 * @link https://mainwp.com/extension/jetpack-protect/
 *
 * @package MainWP\Child
 *
 * Credits
 *
 * Plugin-Name: Jetpack Protect
 * Plugin URI: https://wordpress.org/plugins/jetpack-protect/
 * Author: Automattic
 * Author URI: https://jetpack.com/
 *
 * The code is used for the MainWP Jetpack Protect Extension
 * Extension URL: https://mainwp.com/extension/jetpack-protect/
 */

namespace MainWP\Child;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable PSR1.Classes.ClassDeclaration, WordPress.WP.AlternativeFunctions -- Required to achieve desired results. Pull requests appreciated.

/**
 * Class MainWP_Child_Jetpack_Protect
 *
 * MainWP Staging Extension handler.
 */
class MainWP_Child_Jetpack_Protect {

    /**
     * Public static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    public static $instance = null;

    /**
     * Public variable to hold the information if the WP Staging plugin is installed on the child site.
     *
     * @var bool If WP Staging intalled, return true, if not, return false.
     */
    public $is_plugin_installed = false;

    /**
     * Public variable to hold the information if the WP Staging plugin is installed on the child site.
     *
     * @var string version string.
     */
    public $plugin_version = false;

    /**
     * Private variable to hold the Jetpack Connection information.
     *
     * @var string version string.
     */
    private $connection = null;

    /**
     * Create a public static instance of MainWP_Child_Jetpack_Protect.
     *
     * @return MainWP_Child_Jetpack_Protect
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * MainWP_Child_Jetpack_Protect constructor.
     *
     * Run any time class is called.
     */
    public function __construct() {
        require_once ABSPATH . 'wp-admin/includes/plugin.php'; // NOSONAR - WP compatible.
        if ( is_plugin_active( 'jetpack-protect/jetpack-protect.php' ) && defined( 'JETPACK_PROTECT_DIR' ) ) {
            $this->is_plugin_installed = true;
        }

        if ( ! $this->is_plugin_installed ) {
            return;
        }

        add_filter( 'mainwp_site_sync_others_data', array( $this, 'sync_others_data' ), 10, 2 );

        if ( 'hide' === get_option( 'mainwp_child_jetpack_protect_hide_plugin' ) ) {
            add_filter( 'all_plugins', array( $this, 'hook_all_plugins' ) );
            add_action( 'admin_menu', array( $this, 'hook_remove_menu' ) );
            add_action( 'admin_head', array( $this, 'admin_head' ) );
            add_filter( 'site_transient_update_plugins', array( &$this, 'hook_remove_update_nag' ) );
            add_filter( 'mainwp_child_hide_update_notice', array( &$this, 'hook_hide_update_notice' ) );
        }
    }

    /**
     * Load connection manager object.
     *
     * @return object An array of available clones.
     */
    public function load_connection_manager() {
        if ( null === $this->connection ) {
            MainWP_Helper::instance()->check_classes_exists( '\Automattic\Jetpack\Connection\Manager' );
            $this->connection = new \Automattic\Jetpack\Connection\Manager();
        }
        return $this->connection;
    }

    /**
     * Sync others data.
     *
     * Get an array of available clones of this Child Sites.
     *
     * @param array $information Holder for available clones.
     * @param array $data Array of existing clones.
     *
     * @uses MainWP_Child_Jetpack_Protect::get_sync_data()
     *
     * @return array $information An array of available clones.
     */
    public function sync_others_data( $information, $data = array() ) {
        if ( isset( $data['sync_JetpackProtect'] ) && $data['sync_JetpackProtect'] ) {
            try {
                $this->load_connection_manager();
                MainWP_Helper::instance()->check_methods( $this->connection, 'is_connected' );
                $status                                  = $this->get_sync_data();
                $information['sync_JetpackProtect_Data'] = array(
                    'status'    => $status['status'],
                    'connected' => $this->connection->is_connected(),
                );
                $safe_observation                        = $this->abilities_v2_observation_from_raw( $status, $this->abilities_v2_control_state() );
                if ( true === $safe_observation['ok'] ) {
                    $information['sync_JetpackProtect_Data']['ability_v2'] = $safe_observation;
                }

                if ( MainWP_Helper::instance()->check_classes_exists( '\Automattic\Jetpack\My_Jetpack\Products\Scan', true ) ) {
                    $protect_san = new \Automattic\Jetpack\My_Jetpack\Products\Scan();
                    if ( MainWP_Helper::instance()->check_methods( $protect_san, 'is_active', true ) ) {
                        $information['sync_JetpackProtect_Data']['is_active'] = $protect_san::is_active() ? 1 : 0;
                    }
                }
            } catch ( MainWP_Exception $e ) {
                // error!
            } catch ( \Throwable $e ) {
                // error!
            }
        }
        return $information;
    }

    /**
     * Fires off MainWP_Child_Jetpack_Protect::get_overview().
     *
     * @uses MainWP_Child_Jetpack_Protect::get_overview()
     * @return array An array of available clones.
     */
    public function get_sync_data() {
        return $this->get_scan_status();
    }


    /**
     * Fires of certain Jetpack Protect plugin actions.
     */
    public function action() { // phpcs:ignore -- NOSONAR - ignore complex method notice.
        $mwp_action = MainWP_System::instance()->validate_params( 'mwp_action' );
        if ( 'abilities_v2' === $mwp_action ) {
            // phpcs:disable WordPress.Security.NonceVerification
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Strict JSON validation follows.
            $raw_request = isset( $_POST['request'] ) && is_string( $_POST['request'] ) ? wp_unslash( $_POST['request'] ) : '';
            // phpcs:enable
            $request = 4096 >= strlen( $raw_request ) ? json_decode( $raw_request, true ) : null;
            MainWP_Helper::write( $this->abilities_v2( $request ) );
            return;
        }

        if ( ! $this->is_plugin_installed ) {
            MainWP_Helper::write( array( 'error' => __( 'Please install Jetpack Protect plugin on child website', 'mainwp-child' ) ) );
        }

        $information = array();

        if ( ! empty( $mwp_action ) ) {
            try {
                $this->load_connection_manager();
                switch ( $mwp_action ) {
                    case 'set_showhide':
                        $information = $this->set_showhide();
                        break;
                    case 'set_connect_disconnect':
                        $information = $this->set_connect_disconnect();
                        break;
                    default:
                        break;
                }
            } catch ( MainWP_Exception $e ) {
                $information = array( 'error' => $e->getMessage() );
            } catch ( \Throwable $e ) {
                $information = array( 'error' => $e->getMessage() );
            }
        }
        MainWP_Helper::write( $information );
    }

    /**
     * Negotiate the additive Jetpack Protect abilities protocol.
     *
     * The read-only control-state adapter is advertised only after its closed
     * normalization and feature-state tests pass. Mutations remain unavailable.
     *
     * @param mixed $request Decoded request object.
     * @return array<string,mixed> Closed protocol response.
     */
    public function abilities_v2( $request ) {
        $operation = is_array( $request ) && isset( $request['operation'] ) && is_string( $request['operation'] ) ? $request['operation'] : 'unknown';
        if ( ! is_array( $request ) || ! $this->abilities_v2_exact_keys( $request, array( 'protocol', 'operation', 'payload' ) ) || '2' !== $request['protocol'] || ! is_array( $request['payload'] ) ) {
            return $this->abilities_v2_error( 'unknown', 'invalid_request' );
        }

        if ( 'capabilities' === $operation ) {
            // Negotiation is supported; a payload on it is a malformed request, not an unknown operation.
            if ( array() !== $request['payload'] ) {
                return $this->abilities_v2_error( $operation, 'invalid_request' );
            }

            return array(
                'protocol'           => '2',
                'operation'          => 'capabilities',
                'ok'                 => true,
                'operations'         => array( 'ability_protect_observation_v2', 'ability_protect_control_state_v2', 'ability_protect_connection_v2', 'ability_protect_visibility_v2' ),
                'mutation_supported' => true,
            );
        }

        if ( 'ability_protect_observation_v2' === $operation ) {
            if ( array() !== $request['payload'] ) {
                return $this->abilities_v2_error( $operation, 'invalid_request' );
            }

            return $this->abilities_v2_observation();
        }

        if ( 'ability_protect_control_state_v2' === $operation ) {
            if ( array() !== $request['payload'] ) {
                return $this->abilities_v2_error( $operation, 'invalid_request' );
            }

            return $this->abilities_v2_control_state();
        }

        if ( 'ability_protect_visibility_v2' === $operation ) {
            if ( ! $this->abilities_v2_exact_keys( $request['payload'], array( 'desired_state', 'if_match' ) ) || ! is_string( $request['payload']['desired_state'] ) || ! in_array( $request['payload']['desired_state'], array( 'visible', 'hidden' ), true ) || ! is_string( $request['payload']['if_match'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $request['payload']['if_match'] ) ) {
                return $this->abilities_v2_error( $operation, 'invalid_request' );
            }

            return $this->abilities_v2_replace_visibility( $request['payload']['desired_state'], $request['payload']['if_match'] );
        }

        if ( 'ability_protect_connection_v2' === $operation ) {
            if ( ! $this->abilities_v2_exact_keys( $request['payload'], array( 'desired_state', 'if_match' ) ) || ! is_string( $request['payload']['desired_state'] ) || ! in_array( $request['payload']['desired_state'], array( 'connected', 'disconnected' ), true ) || ! is_string( $request['payload']['if_match'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $request['payload']['if_match'] ) ) {
                return $this->abilities_v2_error( $operation, 'invalid_request' );
            }

            return $this->abilities_v2_replace_connection( $request['payload']['desired_state'], $request['payload']['if_match'] );
        }

        return $this->abilities_v2_error( $operation, 'unsupported_operation' );
    }

    /**
     * Return a closed, bounded Jetpack Protect observation.
     *
     * @return array<string,mixed> Closed observation response.
     */
    private function abilities_v2_observation() { // phpcs:ignore Generic.Metrics.CyclomaticComplexity -- Strict provider normalization is kept together.
        $operation = 'ability_protect_observation_v2';
        if ( ! $this->is_plugin_installed ) {
            return $this->abilities_v2_error( $operation, 'plugin_unavailable' );
        }

        $control = $this->abilities_v2_control_state();
        if ( 'active' !== $control['plugin_state'] || 'unknown' === $control['connection'] ) {
            return $this->abilities_v2_error( $operation, 'unsupported_version' );
        }

        try {
            $raw = $this->get_scan_status();
        } catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Provider detail must remain private.
            return $this->abilities_v2_error( $operation, 'remote_unavailable' );
        }

        return $this->abilities_v2_observation_from_raw( $raw, $control );
    }

    /**
     * Normalize one already-fetched provider observation for Ability-safe reuse.
     *
     * @param mixed $raw     Raw provider response.
     * @param array $control Closed current control state.
     * @return array<string,mixed> Closed observation response.
     */
    private function abilities_v2_observation_from_raw( $raw, $control ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity -- Strict provider normalization is kept together.
        $operation = 'ability_protect_observation_v2';

        if ( ! is_array( $raw ) || ! $this->abilities_v2_exact_keys( $raw, array( 'status' ) ) ) {
            return $this->abilities_v2_error( $operation, 'invalid_snapshot' );
        }

        $status = $this->abilities_v2_array( $raw['status'] );
        if ( false === $status ) {
            return $this->abilities_v2_error( $operation, 'invalid_snapshot' );
        }
        if ( isset( $status['error'] ) && true === $status['error'] ) {
            return $this->abilities_v2_error( $operation, 'remote_unavailable' );
        }
        if ( isset( $status['error'] ) && ! is_bool( $status['error'] ) ) {
            return $this->abilities_v2_error( $operation, 'invalid_snapshot' );
        }

        $observed_at = isset( $status['last_checked'] ) ? $this->abilities_v2_timestamp( $status['last_checked'] ) : false;
        if ( false === $observed_at ) {
            return $this->abilities_v2_error( $operation, 'invalid_snapshot' );
        }

        $partial = isset( $status['has_unchecked_items'] ) ? $status['has_unchecked_items'] : false;
        if ( ! is_bool( $partial ) ) {
            return $this->abilities_v2_error( $operation, 'invalid_snapshot' );
        }

        $threats = isset( $status['threats'] ) ? $status['threats'] : null;
        if ( ! is_array( $threats ) || 1000 < count( $threats ) ) {
            return $this->abilities_v2_error( $operation, 'invalid_snapshot' );
        }

        $findings = array();
        $counts   = array(
            'total'    => 0,
            'core'     => 0,
            'plugins'  => 0,
            'themes'   => 0,
            'files'    => 0,
            'database' => 0,
        );
        $seen     = array();
        foreach ( $threats as $threat ) {
            $finding = $this->abilities_v2_finding( $threat );
            if ( false === $finding || isset( $seen[ $finding['source_ref'] ] ) ) {
                return $this->abilities_v2_error( $operation, 'invalid_snapshot' );
            }
            $seen[ $finding['source_ref'] ] = true;
            ++$counts['total'];
            ++$counts[ $this->abilities_v2_count_key( $finding['kind'] ) ];
            $findings[] = $finding;
        }

        if ( ! $this->abilities_v2_declared_count_matches( $status, 'num_threats', $counts['total'] ) ||
            ! $this->abilities_v2_declared_count_matches( $status, 'num_plugins_threats', $counts['plugins'] ) ||
            ! $this->abilities_v2_declared_count_matches( $status, 'num_themes_threats', $counts['themes'] ) ) {
            return $this->abilities_v2_error( $operation, 'invalid_snapshot' );
        }

        usort(
            $findings,
            static function ( $left, $right ) {
                return strcmp( $left['source_ref'], $right['source_ref'] );
            }
        );
        $scan_product_active = $this->abilities_v2_scan_product_active();
        $generation          = hash(
            'sha256',
            wp_json_encode(
                array(
                    'observed_at'         => $observed_at,
                    'completeness'        => $partial ? 'partial' : 'complete',
                    'connected'           => 'connected' === $control['connection'],
                    'scan_product_active' => $scan_product_active,
                    'counts'              => $counts,
                    'findings'            => $findings,
                )
            )
        );

        return array(
            'protocol'            => '2',
            'operation'           => $operation,
            'ok'                  => true,
            'observed_at'         => $observed_at,
            'source_generation'   => $generation,
            'completeness'        => $partial ? 'partial' : 'complete',
            'connected'           => 'connected' === $control['connection'],
            'scan_product_active' => $scan_product_active,
            'counts'              => $counts,
            'findings'            => $findings,
        );
    }

    /**
     * Normalize one provider threat without exposing provider identifiers.
     *
     * @param mixed $value Raw threat.
     * @return array<string,mixed>|false
     */
    private function abilities_v2_finding( $value ) {
        $threat = $this->abilities_v2_array( $value );
        if ( false === $threat || ! isset( $threat['id'], $threat['title'] ) || ! $this->abilities_v2_bounded_text( $threat['id'], 128 ) || ! is_string( $threat['title'] ) || 2048 < strlen( $threat['title'] ) ) {
            return false;
        }

        $title = $this->abilities_v2_normalized_text( $threat['title'], 160 );
        if ( false === $title ) {
            return false;
        }

        $extension     = isset( $threat['extension'] ) ? $this->abilities_v2_array( $threat['extension'] ) : false;
        $has_file      = isset( $threat['filename'] ) && is_string( $threat['filename'] ) && '' !== $threat['filename'];
        $has_database  = isset( $threat['table'] ) && is_string( $threat['table'] ) && '' !== $threat['table'];
        $kind          = '';
        $component     = null;
        $component_key = '';

        if ( false !== $extension ) {
            if ( $has_file || $has_database || ! isset( $extension['type'] ) || ! is_string( $extension['type'] ) ) {
                return false;
            }
            $types = array(
                'core'    => 'core',
                'plugin'  => 'plugin',
                'plugins' => 'plugin',
                'theme'   => 'theme',
                'themes'  => 'theme',
            );
            if ( ! isset( $types[ $extension['type'] ] ) ) {
                return false;
            }
            $kind = $types[ $extension['type'] ];
            if ( isset( $extension['name'] ) && null !== $extension['name'] ) {
                if ( ! is_string( $extension['name'] ) || 1024 < strlen( $extension['name'] ) ) {
                    return false;
                }
                $component = $this->abilities_v2_normalized_text( $extension['name'], 120 );
                if ( false === $component ) {
                    return false;
                }
            }
            foreach ( array( 'slug', 'version' ) as $key ) {
                if ( isset( $extension[ $key ] ) && null !== $extension[ $key ] && ! $this->abilities_v2_bounded_text( $extension[ $key ], 191 ) ) {
                    return false;
                }
            }
            $component_key = hash( 'sha256', wp_json_encode( array( $extension['type'], $extension['slug'] ?? '', $extension['version'] ?? '', $component ) ) );
        } elseif ( $has_file xor $has_database ) {
            $kind          = $has_file ? 'file' : 'database';
            $sensitive_key = $has_file ? $threat['filename'] : $threat['table'];
            if ( ! $this->abilities_v2_bounded_text( $sensitive_key, 2048 ) ) {
                return false;
            }
            $component_key = hash( 'sha256', $sensitive_key );
        } else {
            return false;
        }

        $fixed_in = isset( $threat['fixed_in'] ) ? $threat['fixed_in'] : null;
        if ( null !== $fixed_in && false !== $fixed_in && ! $this->abilities_v2_bounded_text( $fixed_in, 64 ) ) {
            return false;
        }
        $remediation = 'none';
        if ( is_string( $fixed_in ) && '' !== $fixed_in ) {
            if ( 'core' === $kind ) {
                $remediation = 'core_update_available';
            } elseif ( 'plugin' === $kind || 'theme' === $kind ) {
                $remediation = 'component_update_available';
            }
        }

        return array(
            'source_ref'     => hash( 'sha256', 'mainwp-protect-source-v1|' . $threat['id'] . '|' . $kind . '|' . $component_key ),
            'kind'           => $kind,
            'title'          => $title,
            'component_name' => in_array( $kind, array( 'core', 'plugin', 'theme' ), true ) ? $component : null,
            'remediation'    => $remediation,
        );
    }

    /**
     * Normalize an object-like provider value.
     *
     * @param mixed $value Provider value.
     * @return array|false
     */
    private function abilities_v2_array( $value ) {
        if ( is_object( $value ) ) {
            $value = get_object_vars( $value );
        }
        return is_array( $value ) ? $value : false;
    }

    /**
     * Normalize a provider observation timestamp.
     *
     * @param mixed $value Provider value.
     * @return string|false
     */
    private function abilities_v2_timestamp( $value ) {
        if ( ! is_string( $value ) || 40 < strlen( $value ) ) {
            return false;
        }
        $formats = array( 'Y-m-d\TH:i:s\Z', 'Y-m-d\TH:i:sP', 'Y-m-d H:i:s' );
        foreach ( $formats as $format ) {
            $timezone = new \DateTimeZone( 'UTC' );
            $date     = \DateTimeImmutable::createFromFormat( '!' . $format, $value, $timezone );
            $errors   = \DateTimeImmutable::getLastErrors();
            if ( false !== $date && ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) && $value === $date->format( $format ) ) {
                return $date->setTimezone( $timezone )->format( 'Y-m-d\TH:i:s\Z' );
            }
        }
        return false;
    }

    /**
     * Check a bounded nonempty provider string.
     *
     * @param mixed $value Provider value.
     * @param int   $limit Maximum bytes.
     * @return bool
     */
    private function abilities_v2_bounded_text( $value, $limit ) {
        return is_string( $value ) && '' !== $value && strlen( $value ) <= $limit && ! preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value );
    }

    /**
     * Build a bounded plain-text projection.
     *
     * @param string $value Raw text.
     * @param int    $limit Maximum characters.
     * @return string|false
     */
    private function abilities_v2_normalized_text( $value, $limit ) {
        $plain = preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $value ) );
        if ( ! is_string( $plain ) ) {
            return false;
        }
        $plain = trim( $plain );
        if ( '' === $plain ) {
            return false;
        }
        return function_exists( 'mb_substr' ) ? mb_substr( $plain, 0, $limit ) : substr( $plain, 0, $limit );
    }

    /**
     * Verify an optional provider count against normalized rows.
     *
     * @param array  $status Provider status.
     * @param string $key    Count key.
     * @param int    $actual Normalized count.
     * @return bool
     */
    private function abilities_v2_declared_count_matches( $status, $key, $actual ) {
        return ! array_key_exists( $key, $status ) || null === $status[ $key ] || ( is_int( $status[ $key ] ) && $actual === $status[ $key ] );
    }

    /**
     * Map a finding kind to its count key.
     *
     * @param string $kind Finding kind.
     * @return string
     */
    private function abilities_v2_count_key( $kind ) {
        return array(
            'core'     => 'core',
            'plugin'   => 'plugins',
            'theme'    => 'themes',
            'file'     => 'files',
            'database' => 'database',
        )[ $kind ];
    }

    /**
     * Return whether the paid Scan product is active.
     *
     * @return bool
     */
    protected function abilities_v2_scan_product_active() {
        try {
            if ( ! MainWP_Helper::instance()->check_classes_exists( '\Automattic\Jetpack\My_Jetpack\Products\Scan', true ) || ! MainWP_Helper::instance()->check_methods( '\Automattic\Jetpack\My_Jetpack\Products\Scan', 'is_active', true ) ) {
                return false;
            }
            return (bool) \Automattic\Jetpack\My_Jetpack\Products\Scan::is_active();
        } catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Product detail must remain private.
            return false;
        }
    }

    /**
     * Return the current closed Jetpack Protect control state.
     *
     * @return array<string,mixed> Closed control-state response.
     */
    private function abilities_v2_control_state() {
        $plugin_state = 'missing';
        $connection   = 'unknown';
        $visibility   = 'unknown';

        if ( $this->is_plugin_installed ) {
            $plugin_state = 'active';
            try {
                $manager = $this->load_connection_manager();
                if ( ! is_object( $manager ) || ! is_callable( array( $manager, 'is_connected' ) ) ) {
                    $manager = null;
                } else {
                    $connection = $manager->is_connected() ? 'connected' : 'disconnected';
                }
            } catch ( MainWP_Exception $e ) {
                $manager = null;
            } catch ( \Throwable $e ) {
                $manager = null;
            }
            if ( null === $manager ) {
                $plugin_state = 'unsupported';
            }

            $hide_option = get_option( 'mainwp_child_jetpack_protect_hide_plugin', false );
            if ( 'hide' === $hide_option ) {
                $visibility = 'hidden';
            } elseif ( false === $hide_option || '' === $hide_option || 'show' === $hide_option ) {
                $visibility = 'visible';
            }
        }

        $revision = hash( 'sha256', $plugin_state . '|' . $connection . '|' . $visibility );

        return array(
            'protocol'     => '2',
            'operation'    => 'ability_protect_control_state_v2',
            'ok'           => true,
            'plugin_state' => $plugin_state,
            'connection'   => $connection,
            'visibility'   => $visibility,
            'revision'     => $revision,
            'observed_at'  => gmdate( 'Y-m-d\TH:i:s\Z' ),
        );
    }

    /**
     * Replace the exact Protect visibility option with CAS and readback.
     *
     * @param string $desired_state Exact desired state.
     * @param string $if_match      Current control-state revision.
     * @return array<string,mixed> Closed mutation response.
     */
    private function abilities_v2_replace_visibility( $desired_state, $if_match ) {
        $operation = 'ability_protect_visibility_v2';
        $current   = $this->abilities_v2_control_state();
        if ( 'active' !== $current['plugin_state'] || 'unknown' === $current['visibility'] ) {
            return $this->abilities_v2_error( $operation, 'unsupported_version' );
        }
        if ( ! hash_equals( $current['revision'], $if_match ) ) {
            return $this->abilities_v2_error( $operation, 'stale_revision' );
        }
        if ( $desired_state === $current['visibility'] ) {
            $current['operation'] = $operation;
            $current['changed']   = false;
            return $current;
        }

        $option_name = 'mainwp_child_jetpack_protect_hide_plugin';
        $old_value   = get_option( $option_name, false );
        $new_value   = 'hidden' === $desired_state ? 'hide' : 'show';
        $write_ok    = MainWP_Helper::update_option( $option_name, $new_value, 'yes' );
        $stored      = $this->abilities_v2_control_state();
        if ( $desired_state === $stored['visibility'] ) {
            $stored['operation'] = $operation;
            $stored['changed']   = true;
            return $stored;
        }

        if ( false === $old_value ) {
            $restored = delete_option( $option_name ) || false === get_option( $option_name, false );
        } else {
            $restored = MainWP_Helper::update_option( $option_name, $old_value, 'yes' ) || get_option( $option_name, false ) === $old_value;
        }
        if ( ! $restored ) {
            return $this->abilities_v2_error( $operation, 'outcome_unknown' );
        }

        return $this->abilities_v2_error( $operation, $write_ok ? 'contradictory_readback' : 'write_failed' );
    }

    /**
     * Replace the exact Jetpack connection state with CAS and readback.
     *
     * @param string $desired_state Exact desired state.
     * @param string $if_match      Current control-state revision.
     * @return array<string,mixed> Closed mutation response.
     */
    private function abilities_v2_replace_connection( $desired_state, $if_match ) {
        $operation = 'ability_protect_connection_v2';
        $current   = $this->abilities_v2_control_state();
        if ( 'active' !== $current['plugin_state'] || 'unknown' === $current['connection'] ) {
            return $this->abilities_v2_error( $operation, 'unsupported_version' );
        }
        if ( ! hash_equals( $current['revision'], $if_match ) ) {
            return $this->abilities_v2_error( $operation, 'stale_revision' );
        }
        if ( $desired_state === $current['connection'] ) {
            $current['operation'] = $operation;
            $current['changed']   = false;
            return $current;
        }

        try {
            $manager = $this->load_connection_manager();
            if ( ! is_object( $manager ) || ! is_callable( array( $manager, 'is_connected' ) ) ) {
                return $this->abilities_v2_error( $operation, 'unsupported_version' );
            }
            if ( 'connected' === $desired_state ) {
                if ( ! is_callable( array( $manager, 'try_registration' ) ) ) {
                    return $this->abilities_v2_error( $operation, 'unsupported_version' );
                }
                $dispatch = $manager->try_registration();
            } else {
                if ( ! is_callable( array( $manager, 'disconnect_site' ) ) ) {
                    return $this->abilities_v2_error( $operation, 'unsupported_version' );
                }
                $dispatch = $manager->disconnect_site();
            }
        } catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Provider detail must remain private.
            $stored = $this->abilities_v2_control_state();
            if ( $desired_state === $stored['connection'] ) {
                $stored['operation'] = $operation;
                $stored['changed']   = true;
                return $stored;
            }
            return $this->abilities_v2_error( $operation, 'outcome_unknown' );
        }

        $stored = $this->abilities_v2_control_state();
        if ( $desired_state === $stored['connection'] ) {
            $stored['operation'] = $operation;
            $stored['changed']   = true;
            return $stored;
        }
        if ( 'unknown' === $stored['connection'] ) {
            return $this->abilities_v2_error( $operation, 'outcome_unknown' );
        }
        if ( is_wp_error( $dispatch ) ) {
            return $this->abilities_v2_error( $operation, 'write_failed' );
        }

        return $this->abilities_v2_error( $operation, 'contradictory_readback' );
    }

    /**
     * Check an exact associative-key set.
     *
     * @param array $value Input object.
     * @param array $keys  Expected keys.
     * @return bool
     */
    private function abilities_v2_exact_keys( $value, $keys ) {
        $actual = array_keys( $value );
        sort( $actual, SORT_STRING );
        sort( $keys, SORT_STRING );

        return $actual === $keys;
    }

    /**
     * Build a closed abilities protocol error.
     *
     * @param string $operation Protocol operation.
     * @param string $code      Stable error code.
     * @return array<string,string|bool>
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
     * Sets whether or not to hide the Jetpack Protect Plugin.
     *
     * @return array $information Action result.
     *
     * @uses \MainWP\Child\MainWP_Helper::update_option()
     */
    public function set_showhide() {
        $hide = MainWP_System::instance()->validate_params( 'showhide' );
        MainWP_Helper::update_option( 'mainwp_child_jetpack_protect_hide_plugin', $hide, 'yes' );
        $information['result'] = 'SUCCESS';
        return $information;
    }

    /**
     * Set JP connect.
     *
     * @return array $return connect result.
     */
    public function set_connect_disconnect() {
        $status = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

        $result = array();

        if ( 'connect' === $status ) {
            MainWP_Helper::instance()->check_methods( $this->connection, array( 'set_plugin_instance', 'try_registration', 'is_connected' ) );

            MainWP_Helper::instance()->check_classes_exists( array( '\Automattic\Jetpack\Connection\Plugin_Storage', '\Automattic\Jetpack\Connection\Plugin' ) );
            MainWP_Helper::instance()->check_methods( '\Automattic\Jetpack\Connection\Plugin_Storage', 'get_one' );

            $response = $this->connection->try_registration();

            if ( is_wp_error( $response ) ) {
                $result = array( 'error' => $response->get_error_message() );
            } else {
                $result = array(
                    'code'      => 'success',
                    'connected' => $this->connection->is_connected(),
                );
            }

            return $result;

        } elseif ( 'disconnect' === $status ) {
            MainWP_Helper::instance()->check_methods( $this->connection, array( 'is_connected', 'disconnect_site' ) );
            if ( $this->connection->is_connected() ) {
                $this->connection->disconnect_site();
                return array(
                    'code'      => 'success',
                    'connected' => $this->connection->is_connected(),
                );
            }
            $result = array(
                'code'  => 'disconnect_failed',
                'error' => esc_html__( 'Failed to disconnect the site as it appears already disconnected.', 'mainwp-child' ),
            );
        }

        if ( empty( $result ) ) {
            $result = array( 'code' => 'invalid_data' );
        }

        return $result;
    }

    /**
     * Get scan status.
     *
     * @return array $return scan result.
     */
    public function get_scan_status() {
        $version_error = false;
        try {
            MainWP_Helper::instance()->check_classes_exists( '\Automattic\Jetpack\Protect\Status' );
            MainWP_Helper::instance()->check_methods( '\Automattic\Jetpack\Protect\Status', 'get_status' );
            return array(
                'status' => \Automattic\Jetpack\Protect\Status::get_status(),
            );
        } catch ( MainWP_Exception $e ) {
            $version_error = true;
        }

        if ( $version_error ) {
            MainWP_Helper::instance()->check_classes_exists( '\Automattic\Jetpack\Protect_Status\Status' );
            MainWP_Helper::instance()->check_methods( '\Automattic\Jetpack\Protect_Status\Status', 'get_status' );
            return array(
                'status' => \Automattic\Jetpack\Protect_Status\Status::get_status(),
            );
        }
        return array();
    }

    /**
     * Get list of all plugins except WPStaging.
     *
     * @param array $plugins All installed plugins.
     * @return mixed Returned array of plugins without WPStaging included.
     */
    public function hook_all_plugins( $plugins ) {
        foreach ( $plugins as $key => $value ) {
            $plugin_slug = basename( $key, '.php' );
            if ( 'jetpack-protect' === $plugin_slug ) {
                unset( $plugins[ $key ] );
            }
        }

        return $plugins;
    }

    /**
     * Remove the plugin menu.
     */
    public function hook_remove_menu() {
        remove_menu_page( 'jetpack-protect' );
        $pos = isset( $_SERVER['REQUEST_URI'] ) ? stripos( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), 'admin.php?page=jetpack-protect' ) : false;
        if ( false !== $pos ) {
            wp_safe_redirect( get_option( 'siteurl' ) . '/wp-admin/index.php' );
            exit();
        }
    }

    /**
     * Hide plugin menus.
     */
    public function admin_head() {
        ?>
        <style type="text/css">
            #wp-admin-bar-jetpack-protect{
                display: none !important;
            }
            #toplevel_page_jetpack{
                display: none !important;
            }
        </style>
        <?php
    }


    /**
     * Hide all admin update notices.
     *
     * @param array $slugs WPStaging plugin slug.
     * @return mixed Returned $slugs.
     */
    public function hook_hide_update_notice( $slugs ) {
        $slugs[] = 'jetpack-protect/jetpack-protect.php';

        return $slugs;
    }

    /**
     * Remove WPStaging update Nag message.
     *
     * @param array $value WPStaging slug.
     * @return mixed $value Response array.
     *
     * @uses \MainWP\Child\MainWP_Helper::is_updates_screen()
     */
    public function hook_remove_update_nag( $value ) {
        if ( MainWP_Helper::is_dashboard_request() ) {
            return $value;
        }

        if ( ! MainWP_Helper::is_updates_screen() ) {
            return $value;
        }

        if ( isset( $value->response['jetpack-protect/jetpack-protect.php'] ) ) {
            unset( $value->response['jetpack-protect/jetpack-protect.php'] );
        }
        return $value;
    }
}
