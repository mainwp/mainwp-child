<?php
/**
 * MainWP WordPress SEO
 *
 * MainWP WordPress SEO Extension handler.
 *
 * @link https://mainwp.com/extension/wordpress-seo/
 *
 * @package MainWP\Child
 *
 * Credits
 *
 * Plugin-Name: Yoast SEO
 * Plugin URI: https://yoast.com/wordpress/plugins/seo/#utm_source=wpadmin&utm_medium=plugin&utm_campaign=wpseoplugin
 * Author: Team Yoast
 * Author URI: https://yoast.com/
 * Licence: GPL v3
 */

namespace MainWP\Child;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable PSR1.Classes.ClassDeclaration, WordPress.WP.AlternativeFunctions -- Required to achieve desired results. Pull requests appreciated.

/**
 * Class MainWP_WordPress_SEO
 *
 * MainWP WordPress SEO Extension handler.
 */
class MainWP_WordPress_SEO {

    /**
     * Public static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    public static $instance = null;

    /**
     * Public variable to hold the import settings error message.
     *
     * @var mixed Default null
     */
    public $import_error = '';

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
     * MainWP_WordPress_SEO constructor.
     *
     * Run any time class is called.
     */
    public function __construct() {

        /**
         * Object, providing access to the WordPress database.
         *
         * @global object $wpdb WordPress Database instance.
         */
        global $wpdb;
        $this->import_error = __( 'Settings could not be imported.', 'mainwp-child' );
    }

    /**
     * Fire off certain Yoast SEP plugin actions.
     *
     * @uses MainWP_Helper::write() Write response data to be sent to the MainWP Dashboard.
     * @uses MainWP_WordPress_SEO::import_settings() Import the Yoast SEO plugin settings.
     */
    public function action() {
        $mwp_action = MainWP_System::instance()->validate_params( 'action' );
        if ( in_array( $mwp_action, array( 'describe_v2', 'read_safe_v1', 'apply_safe_v1', 'rollback_safe_v1' ), true ) ) {
            MainWP_Helper::write( $this->abilities_v2( $mwp_action, $this->abilities_v2_request() ) );
            return;
        }

        if ( ! class_exists( '\WPSEO_Admin' ) ) {
            $information['error'] = 'NO_WPSEO';
            MainWP_Helper::write( $information );
        }
        $information = array();
        if ( 'import_settings' === $mwp_action ) {
            $this->import_settings();
        }
        MainWP_Helper::write( $information );
    }

    /**
     * Execute the closed read-only WordPress SEO protocol.
     *
     * @param string $operation Protocol operation.
     * @param mixed  $request   Request payload.
     * @return array
     */
    public function abilities_v2( $operation, $request ) {
        if ( 'describe_v2' === $operation ) {
            if ( ! is_array( $request ) || ! $this->abilities_v2_exact_keys( $request, array( 'contract_version' ) ) || '2' !== $request['contract_version'] ) {
                return $this->abilities_v2_error( $operation, 'invalid_request' );
            }
            return $this->abilities_v2_describe();
        }

        $is_read     = 'read_safe_v1' === $operation;
        $is_mutation = in_array( $operation, array( 'apply_safe_v1', 'rollback_safe_v1' ), true );
        if ( ( $is_read && ! $this->abilities_v2_valid_read_request( $request ) ) || ( $is_mutation && ! $this->abilities_v2_valid_mutation_request( $operation, $request ) ) || ( ! $is_read && ! $is_mutation ) ) {
            return $this->abilities_v2_error( $operation, 'invalid_request' );
        }

        if ( ! $is_mutation ) {
            return $this->abilities_v2_execute( $operation, $request, false );
        }

        // Serialize the receipt check, the generation-drift check and the write against each other.
        if ( ! $this->abilities_v2_begin_lock() ) {
            return $this->abilities_v2_error( $operation, 'lock_busy' );
        }
        try {
            return $this->abilities_v2_execute( $operation, $request, true );
        } finally {
            // The write and its receipt are already settled by the time this runs, so a release
            // that fails is not a failure of the request: reporting one would assert a mutation
            // outcome that did not happen. end_lock() retries on its own, and the lock dies with
            // the connection on anything but a pooled one.
            $this->abilities_v2_end_lock();
        }
    }

    /**
     * Execute one validated operation, under the mutation lock when it mutates.
     *
     * @param string $operation   Protocol operation.
     * @param array  $request     Validated request.
     * @param bool   $is_mutation Whether the operation mutates.
     * @return array
     */
    private function abilities_v2_execute( $operation, $request, $is_mutation ) {
        $is_read = ! $is_mutation;

        if ( $is_mutation ) {
            $replay = $this->abilities_v2_receipt( $operation, $request );
            if ( is_array( $replay ) ) {
                return $this->abilities_v2_valid_mutation_response( $operation, $request, $replay ) ? $replay : $this->abilities_v2_error( $operation, 'storage_unavailable' );
            }
            if ( false === $replay ) {
                return $this->abilities_v2_error( $operation, 'request_conflict' );
            }
        }

        $runtime = $this->abilities_v2_runtime();
        if ( ! $this->abilities_v2_valid_runtime( $runtime ) ) {
            return $this->abilities_v2_error( $operation, 'provider_schema_invalid' );
        }
        if ( 'active' !== $runtime['plugin_state'] ) {
            return $this->abilities_v2_error( $operation, 'provider_unavailable' );
        }
        // The version pin exists to keep writes off untested option layouts; a read that
        // normalizes cleanly is truthful on any Yoast version.
        if ( $is_mutation && ! $this->abilities_v2_supported_version( $runtime['version'] ) ) {
            return $this->abilities_v2_error( $operation, 'unsupported_version' );
        }

        $settings = $this->abilities_v2_normalize_settings( $runtime['options'] );
        if ( ! is_array( $settings ) ) {
            return $this->abilities_v2_error( $operation, 'unsafe_configuration' );
        }

        if ( $is_read ) {
            return array(
                'contract_version'  => '2',
                'operation'         => 'read_safe_v1',
                'ok'                => true,
                'request_ref'       => $request['request_ref'],
                'site_generation'   => $request['site_generation'],
                'version'           => $runtime['version'],
                'schema'            => 'yoast-safe-v1',
                'settings'          => $settings,
                'config_generation' => $this->abilities_v2_generation( $settings ),
            );
        }

        $current_generation = $this->abilities_v2_generation( $settings );
        if ( ! hash_equals( $request['expected_generation'], $current_generation ) ) {
            return $this->abilities_v2_error( $operation, 'generation_drift' );
        }

        $target = $this->abilities_v2_canonical_settings( $request['settings'] );
        if ( ! is_array( $target ) || ! hash_equals( $request['target_generation'], $this->abilities_v2_generation( $target ) ) ) {
            return $this->abilities_v2_error( $operation, 'invalid_request' );
        }

        $changed_fields = $this->abilities_v2_changed_fields( $settings, $target );
        if ( 0 < $changed_fields ) {
            $applied = $this->abilities_v2_apply_settings( $runtime['options'], $target );
            if ( true !== $applied ) {
                return $this->abilities_v2_error( $operation, $applied );
            }
        }

        $response = array(
            'contract_version'  => '2',
            'operation'         => $operation,
            'ok'                => true,
            'request_ref'       => $request['request_ref'],
            'site_generation'   => $request['site_generation'],
            'version'           => $runtime['version'],
            'schema'            => 'yoast-safe-v1',
            'before_generation' => $current_generation,
            'config_generation' => $request['target_generation'],
            'changed_fields'    => $changed_fields,
            'state'             => 0 === $changed_fields ? 'no_change' : ( 'apply_safe_v1' === $operation ? 'applied' : 'rolled_back' ),
        );

        if ( ! $this->abilities_v2_store_receipt( $operation, $request, $response ) ) {
            if ( 0 < $changed_fields && true !== $this->abilities_v2_apply_settings( $this->abilities_v2_runtime()['options'], $settings ) ) {
                return $this->abilities_v2_error( $operation, 'outcome_unknown' );
            }
            return $this->abilities_v2_error( $operation, 'storage_unavailable' );
        }

        return $response;
    }

    /**
     * Get the local Yoast runtime without invoking provider or mutation code.
     *
     * @return array
     */
    protected function abilities_v2_runtime() {
        if ( ! defined( 'WPSEO_VERSION' ) || ! class_exists( '\WPSEO_Admin' ) ) {
            return array(
                'plugin_state' => 'missing',
                'version'      => null,
                'options'      => null,
            );
        }

        return array(
            'plugin_state' => 'active',
            'version'      => (string) WPSEO_VERSION,
            'options'      => array(
                'wpseo_titles' => get_option( 'wpseo_titles', null ),
                'wpseo'        => get_option( 'wpseo', null ),
            ),
        );
    }

    /**
     * Build the protocol capability description.
     *
     * @return array
     */
    private function abilities_v2_describe() {
        $runtime       = $this->abilities_v2_runtime();
        $valid_runtime = $this->abilities_v2_valid_runtime( $runtime );
        $active        = $valid_runtime && 'active' === $runtime['plugin_state'];
        $supported     = $active && $this->abilities_v2_supported_version( $runtime['version'] );

        return array(
            'contract_version'   => '2',
            'operation'          => 'describe_v2',
            'ok'                 => $valid_runtime,
            'plugin_state'       => $valid_runtime ? $runtime['plugin_state'] : 'unknown',
            'version'            => $active ? $runtime['version'] : null,
            'compatibility'      => $supported ? 'supported' : ( $active ? 'unsupported' : 'unavailable' ),
            'schema'             => $active ? 'yoast-safe-v1' : null,
            'operations'         => $supported ? array( 'read_safe_v1', 'apply_safe_v1', 'rollback_safe_v1' ) : ( $active ? array( 'read_safe_v1' ) : array() ),
            'mutation_supported' => $supported,
        );
    }

    /**
     * Return this installation's named mutation lock.
     *
     * @return string
     */
    protected function abilities_v2_lock_name() {
        return 'mainwp_yoast_v2_' . substr( hash( 'sha256', home_url( '/' ) ), 0, 32 );
    }

    /**
     * Acquire the Child-wide Yoast mutation lock without waiting.
     *
     * @return bool
     */
    protected function abilities_v2_begin_lock() {
        global $wpdb;
        if ( ! is_object( $wpdb ) || ! is_callable( array( $wpdb, 'prepare' ) ) || ! is_callable( array( $wpdb, 'get_var' ) ) ) {
            return false;
        }
        $wpdb->last_error = '';
        $locked           = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $this->abilities_v2_lock_name() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Named lock is the serialization primitive.
        return empty( $wpdb->last_error ) && '1' === (string) $locked;
    }

    /**
     * Release the Child-wide Yoast mutation lock.
     *
     * @return bool
     */
    protected function abilities_v2_end_lock() {
        global $wpdb;
        if ( ! is_object( $wpdb ) || ! is_callable( array( $wpdb, 'prepare' ) ) || ! is_callable( array( $wpdb, 'get_var' ) ) ) {
            return false;
        }
        // A lock this session fails to give back outlives the request on a persistent connection
        // and blocks every later mutation with no way for an operator to clear it, so one failed
        // release earns a second attempt before it is given up on.
        for ( $attempt = 0; $attempt < 2; $attempt++ ) {
            $wpdb->last_error = '';
            $released         = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->abilities_v2_lock_name() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Named lock release must be checked.
            // RELEASE_LOCK() answers NULL when the named lock does not exist, which is the state
            // the caller asked for.
            if ( empty( $wpdb->last_error ) && ( null === $released || '1' === (string) $released ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Validate one exact mutation request.
     *
     * @param string $operation Operation.
     * @param mixed  $request   Request.
     * @return bool
     */
    private function abilities_v2_valid_mutation_request( $operation, $request ) {
        $generation_key = 'apply_safe_v1' === $operation ? 'template_generation' : 'result_generation';
        return is_array( $request )
            && $this->abilities_v2_exact_keys( $request, array( 'contract_version', 'request_ref', 'site_generation', $generation_key, 'rollout_generation', 'expected_generation', 'target_generation', 'settings' ) )
            && '2' === $request['contract_version']
            && $this->abilities_v2_uuid( $request['request_ref'] )
            && $this->abilities_v2_digest( $request['site_generation'] )
            && $this->abilities_v2_digest( $request[ $generation_key ] )
            && $this->abilities_v2_digest( $request['rollout_generation'] )
            && $this->abilities_v2_digest( $request['expected_generation'] )
            && $this->abilities_v2_digest( $request['target_generation'] )
            && is_array( $request['settings'] );
    }

    /**
     * Validate one stored mutation response before replay.
     *
     * @param string $operation Operation.
     * @param array  $request   Request.
     * @param array  $response  Stored response.
     * @return bool
     */
    private function abilities_v2_valid_mutation_response( $operation, $request, $response ) {
        return $this->abilities_v2_exact_keys( $response, array( 'contract_version', 'operation', 'ok', 'request_ref', 'site_generation', 'version', 'schema', 'before_generation', 'config_generation', 'changed_fields', 'state' ) )
            && '2' === $response['contract_version']
            && $operation === $response['operation']
            && true === $response['ok']
            && $request['request_ref'] === $response['request_ref']
            && $request['site_generation'] === $response['site_generation']
            && $this->abilities_v2_string( $response['version'], 100 )
            && 'yoast-safe-v1' === $response['schema']
            && $this->abilities_v2_digest( $response['before_generation'] )
            && $this->abilities_v2_digest( $response['config_generation'] )
            && is_int( $response['changed_fields'] )
            && 0 <= $response['changed_fields']
            && 7 >= $response['changed_fields']
            && in_array( $response['state'], array( 'applied', 'rolled_back', 'no_change' ), true )
            && ( 'no_change' === $response['state'] || ( 'apply_safe_v1' === $operation && 'applied' === $response['state'] ) || ( 'rollback_safe_v1' === $operation && 'rolled_back' === $response['state'] ) );
    }

    /**
     * Validate a bounded non-control string.
     *
     * @param mixed $value  Value.
     * @param int   $length Maximum length.
     * @return bool
     */
    private function abilities_v2_string( $value, $length ) {
        return is_string( $value ) && '' !== $value && $length >= strlen( $value ) && 0 === preg_match( '/[\x00-\x1F\x7F]/', $value );
    }

    /**
     * Validate one UUID value.
     *
     * @param mixed $value Value.
     * @return bool
     */
    private function abilities_v2_uuid( $value ) {
        return is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value );
    }

    /**
     * Validate one SHA-256 digest.
     *
     * @param mixed $value Value.
     * @return bool
     */
    private function abilities_v2_digest( $value ) {
        return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $value );
    }

    /**
     * Return the canonical safe-subset generation.
     *
     * @param array $settings Settings.
     * @return string
     */
    private function abilities_v2_generation( $settings ) {
        return hash( 'sha256', wp_json_encode( $settings ) );
    }

    /**
     * Validate and canonicalize one complete safe settings subset.
     *
     * @param mixed $settings Settings.
     * @return array|null
     */
    private function abilities_v2_canonical_settings( $settings ) {
        if ( ! is_array( $settings ) || ! $this->abilities_v2_exact_keys( $settings, array( 'post_types', 'taxonomies', 'archives', 'sitemaps' ) ) || ! is_array( $settings['post_types'] ) || ! is_array( $settings['taxonomies'] ) || 2 !== count( $settings['post_types'] ) || 2 !== count( $settings['taxonomies'] ) || ! $this->abilities_v2_exact_keys( $settings['archives'], array( 'author_index', 'date_index' ) ) || ! $this->abilities_v2_exact_keys( $settings['sitemaps'], array( 'enabled' ) ) || ! is_bool( $settings['archives']['author_index'] ) || ! is_bool( $settings['archives']['date_index'] ) || ! is_bool( $settings['sitemaps']['enabled'] ) ) {
            return null;
        }

        $canonical = array();
        foreach ( array(
            'post_types' => array( 'post', 'page' ),
            'taxonomies' => array( 'category', 'post_tag' ),
        ) as $group => $slugs ) {
            $records = array();
            foreach ( $settings[ $group ] as $record ) {
                if ( ! is_array( $record ) || ! $this->abilities_v2_exact_keys( $record, array( 'slug', 'index', 'title_template', 'description_template' ) ) || ! in_array( $record['slug'], $slugs, true ) || isset( $records[ $record['slug'] ] ) || ! is_bool( $record['index'] ) ) {
                    return null;
                }
                $title = $this->abilities_v2_template( $record['title_template'], 200 );
                $desc  = $this->abilities_v2_template( $record['description_template'], 500 );
                if ( false === $title || false === $desc ) {
                    return null;
                }
                $records[ $record['slug'] ] = array(
                    'slug'                 => $record['slug'],
                    'index'                => $record['index'],
                    'title_template'       => $title,
                    'description_template' => $desc,
                );
            }
            if ( count( $records ) !== count( $slugs ) ) {
                return null;
            }
            $canonical[ $group ] = array();
            foreach ( $slugs as $slug ) {
                $canonical[ $group ][] = $records[ $slug ];
            }
        }
        $canonical['archives'] = $settings['archives'];
        $canonical['sitemaps'] = $settings['sitemaps'];
        return $canonical;
    }

    /**
     * Count changed leaf fields in the closed subset.
     *
     * @param array $before Current settings.
     * @param array $after  Target settings.
     * @return int
     */
    private function abilities_v2_changed_fields( $before, $after ) {
        $count = 0;
        foreach ( array( 'post_types', 'taxonomies' ) as $group ) {
            foreach ( $before[ $group ] as $index => $record ) {
                $count += $record === $after[ $group ][ $index ] ? 0 : 1;
            }
        }
        $count += $before['archives']['author_index'] === $after['archives']['author_index'] ? 0 : 1;
        $count += $before['archives']['date_index'] === $after['archives']['date_index'] ? 0 : 1;
        $count += $before['sitemaps']['enabled'] === $after['sitemaps']['enabled'] ? 0 : 1;
        return $count;
    }

    /**
     * Apply owned keys and verify the exact normalized readback.
     *
     * @param array $old_options Existing option arrays.
     * @param array $target      Canonical target settings.
     * @return true|string
     */
    private function abilities_v2_apply_settings( $old_options, $target ) {
        $next = $this->abilities_v2_options_for_settings( $old_options, $target );
        if ( ! is_array( $next ) ) {
            return 'unsafe_configuration';
        }

        $written = array();
        foreach ( array( 'wpseo_titles', 'wpseo' ) as $name ) {
            if ( $old_options[ $name ] === $next[ $name ] ) {
                continue;
            }
            if ( ! $this->abilities_v2_write_option( $name, $next[ $name ] ) ) {
                return $this->abilities_v2_restore_options( $old_options, $written ) ? 'write_failed' : 'outcome_unknown';
            }
            $written[] = $name;
        }

        $readback = $this->abilities_v2_runtime();
        $actual   = is_array( $readback ) && isset( $readback['options'] ) ? $this->abilities_v2_normalize_settings( $readback['options'] ) : null;
        if ( ! is_array( $actual ) || $target !== $actual ) {
            return $this->abilities_v2_restore_options( $old_options, $written ) ? 'readback_mismatch' : 'outcome_unknown';
        }
        return true;
    }

    /**
     * Build Yoast option arrays while preserving every unowned key.
     *
     * @param array $old_options Existing options.
     * @param array $target      Target settings.
     * @return array|null
     */
    private function abilities_v2_options_for_settings( $old_options, $target ) {
        if ( ! is_array( $old_options ) || ! isset( $old_options['wpseo_titles'], $old_options['wpseo'] ) || ! is_array( $old_options['wpseo_titles'] ) || ! is_array( $old_options['wpseo'] ) ) {
            return null;
        }
        $next = $old_options;
        foreach ( array(
            'post_types' => '',
            'taxonomies' => 'tax-',
        ) as $group => $prefix ) {
            foreach ( $target[ $group ] as $record ) {
                $slug = $record['slug'];
                $next['wpseo_titles'][ 'noindex-' . $prefix . $slug ]  = ! $record['index'];
                $next['wpseo_titles'][ 'title-' . $prefix . $slug ]    = $record['title_template'];
                $next['wpseo_titles'][ 'metadesc-' . $prefix . $slug ] = $record['description_template'];
            }
        }
        $next['wpseo_titles']['noindex-author-wpseo']  = ! $target['archives']['author_index'];
        $next['wpseo_titles']['noindex-archive-wpseo'] = ! $target['archives']['date_index'];
        $next['wpseo']['enable_xml_sitemap']           = $target['sitemaps']['enabled'];
        return $next;
    }

    /**
     * Restore changed option arrays and verify their exact values.
     *
     * @param array $old_options Existing options.
     * @param array $written     Written option names.
     * @return bool
     */
    private function abilities_v2_restore_options( $old_options, $written ) {
        $ok = true;
        foreach ( array_reverse( $written ) as $name ) {
            $ok = $this->abilities_v2_write_option( $name, $old_options[ $name ] ) && $ok;
        }
        $readback = $this->abilities_v2_runtime();
        return $ok && is_array( $readback ) && isset( $readback['options'] ) && $old_options === $readback['options'];
    }

    /**
     * Write one owned Yoast option.
     *
     * @param string $name  Option name.
     * @param array  $value Option value.
     * @return bool
     */
    protected function abilities_v2_write_option( $name, $value ) {
        // Yoast owns these options' autoload flag. Omitting update_option()'s autoload argument
        // leaves an explicit 'on'/'off' row untouched; since WordPress 6.6 a row WordPress chose
        // for itself ('auto-on', 'auto-off', 'auto') is re-derived from the new value's size.
        // That re-derivation depends only on the value, so a rollback lands on the flag the
        // original value would have had and the pair stays symmetric. Passing the stored raw flag
        // instead would be worse: wp_determine_option_autoload_value() does not accept 'auto-*'
        // and falls through to the same filter anyway. All of it assumes the option exists:
        // update_option() falls through to add_option() for a missing one and creates it
        // autoloaded. Nothing reaches here with one missing, because
        // abilities_v2_normalize_settings() refuses a runtime whose wpseo_titles or wpseo option
        // is absent or not an array.
        return update_option( $name, $value ) || get_option( $name, null ) === $value;
    }

    /**
     * Read a prior receipt, returning null when absent and false on conflict/corruption.
     *
     * @param string $operation Operation.
     * @param array  $request   Request.
     * @return array|null|false
     */
    protected function abilities_v2_receipt( $operation, $request ) {
        $receipts = get_option( 'mainwp_wordpress_seo_v2_receipts', array() );
        $key      = $operation . ':' . $request['request_ref'];
        if ( ! is_array( $receipts ) || ! isset( $receipts[ $key ] ) ) {
            return is_array( $receipts ) ? null : false;
        }
        $receipt = $receipts[ $key ];
        $hash    = $this->abilities_v2_request_hash( $operation, $request );
        return is_array( $receipt ) && $this->abilities_v2_exact_keys( $receipt, array( 'request_hash', 'response' ) ) && is_string( $receipt['request_hash'] ) && hash_equals( $receipt['request_hash'], $hash ) && is_array( $receipt['response'] ) ? $receipt['response'] : false;
    }

    /**
     * Persist a bounded mutation receipt with exact readback.
     *
     * @param string $operation Operation.
     * @param array  $request   Request.
     * @param array  $response  Response.
     * @return bool
     */
    protected function abilities_v2_store_receipt( $operation, $request, $response ) {
        $receipts = get_option( 'mainwp_wordpress_seo_v2_receipts', array() );
        if ( ! is_array( $receipts ) ) {
            return false;
        }
        $key              = $operation . ':' . $request['request_ref'];
        $receipts[ $key ] = array(
            'request_hash' => $this->abilities_v2_request_hash( $operation, $request ),
            'response'     => $response,
        );
        if ( 100 < count( $receipts ) ) {
            $receipts = array_slice( $receipts, -100, null, true );
        }
        $written  = update_option( 'mainwp_wordpress_seo_v2_receipts', $receipts, false );
        $readback = get_option( 'mainwp_wordpress_seo_v2_receipts', null );
        return ( $written || $readback === $receipts ) && $readback === $receipts;
    }

    /**
     * Hash one closed request with its operation.
     *
     * @param string $operation Operation.
     * @param array  $request   Request.
     * @return string
     */
    private function abilities_v2_request_hash( $operation, $request ) {
        return hash( 'sha256', $operation . "\n" . wp_json_encode( $request ) );
    }

    /**
     * Normalize the exact site-neutral subset.
     *
     * @param mixed $options Yoast options.
     * @return array|null
     */
    private function abilities_v2_normalize_settings( $options ) { // phpcs:ignore -- NOSONAR - explicit closed adapter.
        if ( ! is_array( $options ) || ! $this->abilities_v2_exact_keys( $options, array( 'wpseo_titles', 'wpseo' ) ) || ! is_array( $options['wpseo_titles'] ) || ! is_array( $options['wpseo'] ) ) {
            return null;
        }

        $titles     = $options['wpseo_titles'];
        $general    = $options['wpseo'];
        $post_types = array();
        foreach ( array( 'post', 'page' ) as $slug ) {
            $record = $this->abilities_v2_search_record( $titles, $slug, 'post_type' );
            if ( ! is_array( $record ) ) {
                return null;
            }
            $post_types[] = $record;
        }

        $taxonomies = array();
        foreach ( array( 'category', 'post_tag' ) as $slug ) {
            $record = $this->abilities_v2_search_record( $titles, $slug, 'taxonomy' );
            if ( ! is_array( $record ) ) {
                return null;
            }
            $taxonomies[] = $record;
        }

        $author_noindex = $this->abilities_v2_boolean( $titles, 'noindex-author-wpseo' );
        $date_noindex   = $this->abilities_v2_boolean( $titles, 'noindex-archive-wpseo' );
        $sitemaps       = $this->abilities_v2_boolean( $general, 'enable_xml_sitemap' );
        if ( null === $author_noindex || null === $date_noindex || null === $sitemaps ) {
            return null;
        }

        return array(
            'post_types' => $post_types,
            'taxonomies' => $taxonomies,
            'archives'   => array(
                'author_index' => ! $author_noindex,
                'date_index'   => ! $date_noindex,
            ),
            'sitemaps'   => array(
                'enabled' => $sitemaps,
            ),
        );
    }

    /**
     * Normalize one post-type or taxonomy record.
     *
     * @param array  $titles Option array.
     * @param string $slug   Record slug.
     * @param string $kind   Record kind.
     * @return array|null
     */
    private function abilities_v2_search_record( $titles, $slug, $kind ) {
        $prefix      = 'taxonomy' === $kind ? 'tax-' : '';
        $noindex_key = 'noindex-' . $prefix . $slug;
        $title_key   = 'title-' . $prefix . $slug;
        $desc_key    = 'metadesc-' . $prefix . $slug;
        $noindex     = $this->abilities_v2_boolean( $titles, $noindex_key );
        if ( null === $noindex || ! array_key_exists( $title_key, $titles ) || ! array_key_exists( $desc_key, $titles ) ) {
            return null;
        }

        $title = $this->abilities_v2_template( $titles[ $title_key ], 200 );
        $desc  = $this->abilities_v2_template( $titles[ $desc_key ], 500 );
        if ( false === $title || false === $desc ) {
            return null;
        }

        return array(
            'slug'                 => $slug,
            'index'                => ! $noindex,
            'title_template'       => $title,
            'description_template' => $desc,
        );
    }

    /**
     * Validate and normalize a safe template string.
     *
     * @param mixed $value  Value.
     * @param int   $length Maximum length.
     * @return string|null|false
     */
    private function abilities_v2_template( $value, $length ) {
        if ( null === $value || '' === $value ) {
            return null;
        }
        if ( ! is_string( $value ) || $length < strlen( $value ) || wp_check_invalid_utf8( $value, true ) !== $value || 1 === preg_match( '/[\x00-\x1F\x7F]/', $value ) || 1 === preg_match( '#https?://#i', $value ) ) {
            return false;
        }
        if ( preg_match_all( '/%%([a-z_]+)%%/', $value, $matches ) ) {
            foreach ( $matches[1] as $placeholder ) {
                if ( ! in_array( $placeholder, array( 'title', 'sitename', 'sep', 'excerpt', 'category', 'tag' ), true ) ) {
                    return false;
                }
            }
        }
        $without_placeholders = preg_replace( '/%%[a-z_]+%%/', '', $value );
        return false !== $without_placeholders && false === strpos( $without_placeholders, '%%' ) ? $value : false;
    }

    /**
     * Read a strict stored boolean.
     *
     * @param array  $options Options.
     * @param string $key     Key.
     * @return bool|null
     */
    private function abilities_v2_boolean( $options, $key ) {
        if ( ! array_key_exists( $key, $options ) ) {
            return null;
        }
        if ( true === $options[ $key ] || 1 === $options[ $key ] || '1' === $options[ $key ] ) {
            return true;
        }
        if ( false === $options[ $key ] || 0 === $options[ $key ] || '0' === $options[ $key ] ) {
            return false;
        }
        return null;
    }

    /**
     * Validate the read request.
     *
     * @param mixed $request Request.
     * @return bool
     */
    private function abilities_v2_valid_read_request( $request ) {
        return is_array( $request )
            && $this->abilities_v2_exact_keys( $request, array( 'contract_version', 'request_ref', 'site_generation' ) )
            && '2' === $request['contract_version']
            && is_string( $request['request_ref'] )
            && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $request['request_ref'] )
            && is_string( $request['site_generation'] )
            && 1 === preg_match( '/^[a-f0-9]{64}$/D', $request['site_generation'] );
    }

    /**
     * Validate runtime data.
     *
     * @param mixed $runtime Runtime.
     * @return bool
     */
    private function abilities_v2_valid_runtime( $runtime ) {
        return is_array( $runtime )
            && $this->abilities_v2_exact_keys( $runtime, array( 'plugin_state', 'version', 'options' ) )
            && in_array( $runtime['plugin_state'], array( 'active', 'missing' ), true )
            && ( ( 'active' === $runtime['plugin_state'] && is_string( $runtime['version'] ) && is_array( $runtime['options'] ) )
                || ( 'missing' === $runtime['plugin_state'] && null === $runtime['version'] && null === $runtime['options'] ) );
    }

    /**
     * Check the explicitly tested Yoast major-version range.
     *
     * @param mixed $version Version.
     * @return bool
     */
    private function abilities_v2_supported_version( $version ) {
        return is_string( $version )
            && 1 === preg_match( '/^25\.5(?:\.[0-9]+)?$/D', $version );
    }

    /**
     * Read a bounded JSON request from the authenticated callable.
     *
     * @return array|null
     */
    private function abilities_v2_request() {
        // phpcs:disable WordPress.Security.NonceVerification -- MainWP signature authentication occurs before callable dispatch.
        if ( ! isset( $_POST['request'] ) || ! is_string( $_POST['request'] ) ) {
            return null;
        }
        $raw = wp_unslash( $_POST['request'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Strict JSON schema validation follows.
        // phpcs:enable WordPress.Security.NonceVerification
        if ( 262144 < strlen( $raw ) ) {
            return null;
        }
        $request = json_decode( $raw, true );
        return is_array( $request ) ? $request : null;
    }

    /**
     * Build a stable redacted protocol error.
     *
     * @param string $operation Operation.
     * @param string $code      Error code.
     * @return array
     */
    private function abilities_v2_error( $operation, $code ) {
        return array(
            'contract_version' => '2',
            'operation'        => is_string( $operation ) && 64 >= strlen( $operation ) ? $operation : 'unknown',
            'ok'               => false,
            'code'             => $code,
        );
    }

    /**
     * Validate an exact key set without requiring object order.
     *
     * @param mixed $value Value.
     * @param array $keys  Keys.
     * @return bool
     */
    private function abilities_v2_exact_keys( $value, $keys ) {
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

    /**
     * Import the Yoast SEO plugin settings.
     *
     * @uses MainWP_Helper::write() Write response data to be sent to the MainWP Dashboard.
     *
     * @used-by MainWP_WordPress_SEO::action() Fire off certain Yoast SEP plugin actions.
     *
     * @throws MainWP_Exception Error message.
     */
    public function import_settings() { //phpcs:ignore -- NOSONAR - complex.
        // phpcs:disable WordPress.Security.NonceVerification
        if ( isset( $_POST['file_url'] ) ) {
            $file_url       = ! empty( $_POST['file_url'] ) ? sanitize_text_field( base64_decode( wp_unslash( $_POST['file_url'] ) ) ) : ''; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- base64_encode required for backwards compatibility.
            $temporary_file = '';

            try {
                include_once ABSPATH . 'wp-admin/includes/file.php'; // NOSONAR -- WP compatible.
                add_filter( 'http_request_args', array( MainWP_Helper::get_class_name(), 'reject_unsafe_urls' ), 99, 2 );
                $temporary_file = download_url( $file_url );
                remove_filter( 'http_request_args', array( MainWP_Helper::get_class_name(), 'reject_unsafe_urls' ), 99, 2 );
                if ( is_wp_error( $temporary_file ) ) {
                    throw new MainWP_Exception( 'Error: ' . $temporary_file->get_error_message() );
                } elseif ( $this->import_seo_settings( $temporary_file ) ) {
                        $information['success'] = true;
                } else {
                    throw new MainWP_Exception( esc_html( $this->import_error ) );
                }
            } catch ( MainWP_Exception $e ) {
                $information['error'] = $e->getMessage();
            }

            if ( file_exists( $temporary_file ) ) {
                wp_delete_file( $temporary_file );
            }
        } elseif ( isset( $_POST['settings'] ) ) {
            try {
                $settings = ! empty( $_POST['settings'] ) ? base64_decode( wp_unslash( $_POST['settings'] ) ) : ''; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- base64_encode required for backwards compatibility.
                $options  = parse_ini_string( $settings, true, INI_SCANNER_RAW );
                if ( is_array( $options ) && array() !== $options ) {

                    $old_wpseo_version = null;
                    if ( isset( $options['wpseo']['version'] ) && '' !== $options['wpseo']['version'] ) {
                        $old_wpseo_version = $options['wpseo']['version'];
                    }
                    foreach ( $options as $name => $optgroup ) {
                        if ( 'wpseo_taxonomy_meta' === $name ) {
                            $optgroup = json_decode( urldecode( $optgroup['wpseo_taxonomy_meta'] ), true );
                        }
                        $option_instance = \WPSEO_Options::get_option_instance( $name );
                        if ( is_object( $option_instance ) && method_exists( $option_instance, 'import' ) ) {
                            $optgroup = $option_instance->import( $optgroup, $old_wpseo_version, $options );
                        }
                    }
                    $information['success'] = true;

                } else {
                    throw new MainWP_Exception( esc_html( $this->import_error ) );
                }
            } catch ( MainWP_Exception $e ) {
                $information['error'] = $e->getMessage();
            }
        }
        // phpcs:enable
        MainWP_Helper::write( $information );
    }

    /**
     * Import SEO settings.
     *
     * @param string $file settings.ini file to import.
     *
     * @throws MainWP_Exception Error message.
     *
     * @return bool Return true on success, false on failure.
     */
    public function import_seo_settings( $file ) { //phpcs:ignore -- NOSONAR - complex.
        if ( ! empty( $file ) ) {
            $upload_dir = wp_upload_dir();

            if ( ! defined( 'DIRECTORY_SEPARATOR' ) ) {

                /**
                 * Defines reusable directory separator.
                 *
                 * @const ( string ) Directory separator.
                 * @source https://code-reference.mainwp.com/classes/MainWP.Dashboard.MainWP_WordPress_SEO.html
                 */
                define( 'DIRECTORY_SEPARATOR', '/' );
            }
            $p_path = $upload_dir['basedir'] . DIRECTORY_SEPARATOR . 'wpseo-import' . DIRECTORY_SEPARATOR;

            if ( ! isset( $GLOBALS['wp_filesystem'] ) || ! is_object( $GLOBALS['wp_filesystem'] ) ) {
                WP_Filesystem();
            }

            $unzipped = unzip_file( $file, $p_path );

            $error_import = true;

            if ( ! is_wp_error( $unzipped ) ) {
                $filename = $p_path . 'settings.ini';
                if ( is_file( $filename ) && is_readable( $filename ) ) {
                    $options = parse_ini_file( $filename, true );
                    if ( is_array( $options ) && array() !== $options ) {
                        $old_wpseo_version = null;
                        if ( isset( $options['wpseo']['version'] ) && '' !== $options['wpseo']['version'] ) {
                            $old_wpseo_version = $options['wpseo']['version'];
                        }
                        foreach ( $options as $name => $optgroup ) {
                            if ( 'wpseo_taxonomy_meta' === $name ) {
                                $optgroup = json_decode( urldecode( $optgroup['wpseo_taxonomy_meta'] ), true );
                            }
                            $option_instance = \WPSEO_Options::get_option_instance( $name );
                            if ( is_object( $option_instance ) && method_exists( $option_instance, 'import' ) ) {
                                $optgroup = $option_instance->import( $optgroup, $old_wpseo_version, $options );
                            }
                        }
                        $error_import = false;
                    }
                    wp_delete_file( $filename );
                    wp_delete_file( $p_path );
                }
            }

            wp_delete_file( $file );
            unset( $unzipped );

            if ( $error_import ) {
                throw new MainWP_Exception( esc_html( $this->import_error ) );
            } else {
                return true;
            }
        } else {
            throw new MainWP_Exception( esc_html( $this->import_error ) . ' ' . esc_html__( 'Upload failed.', 'mainwp-child' ) );
        }
    }

    /**
     * Parse the column score.
     *
     * @param int $post_id Post ID.
     *
     * @return string SEO Score.
     */
    public function parse_column_score( $post_id ) {
        if ( '1' === \WPSEO_Meta::get_value( 'meta-robots-noindex', $post_id ) ) {
            $rank  = new \WPSEO_Rank( \WPSEO_Rank::NO_INDEX );
            $title = esc_html__( 'Post is set to noindex.', 'mainwp-child' );
            \WPSEO_Meta::set_value( 'linkdex', 0, $post_id );
        } elseif ( '' === \WPSEO_Meta::get_value( 'focuskw', $post_id ) ) {
            $rank  = new \WPSEO_Rank( \WPSEO_Rank::NO_FOCUS );
            $title = esc_html__( 'Focus keyword not set.', 'mainwp-child' );
        } else {
            $score = (int) \WPSEO_Meta::get_value( 'linkdex', $post_id );
            $rank  = \WPSEO_Rank::from_numeric_score( $score );
            $title = $rank->get_label();
        }

        return $this->render_score_indicator( $rank, $title );
    }

    /**
     * Parse readability score.
     *
     * @param int $post_id Post ID.
     *
     * @return string Redability score.
     */
    public function parse_column_score_readability( $post_id ) {
        $score = (int) \WPSEO_Meta::get_value( 'content_score', $post_id );
        $rank  = \WPSEO_Rank::from_numeric_score( $score );

        return $this->render_score_indicator( $rank );
    }

    /**
     * Render score rank.
     *
     * @param string $rank SEO Rank Score.
     * @param string $title Rank title.
     *
     * @return string Return SEO Score html.
     */
    private function render_score_indicator( $rank, $title = '' ) {
        if ( empty( $title ) ) {
            $title = $rank->get_label();
        }

        return '<div aria-hidden="true" title="' . esc_attr( $title ) . '" class="wpseo-score-icon ' . esc_attr( $rank->get_css_class() ) . '"></div><span class="screen-reader-text">' . $title . '</span>';
    }
}
