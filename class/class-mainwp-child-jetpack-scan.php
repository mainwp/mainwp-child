<?php
/**
 * MainWP Child Jetpack Scan.
 *
 * MainWP Jetpack Scan Extension handler.
 *
 * @link https://mainwp.com/extension/jetpack/
 *
 * @package MainWP\Child
 *
 * Credits
 *
 * Plugin-Name: Jetpack Scan
 * Plugin URI: https://wordpress.org/plugins/jetpack/
 * Author: Automattic
 * Author URI: https://jetpack.com/
 *
 * The code is used for the MainWP Jetpack Scan Extension
 * Extension URL: https://mainwp.com/extension/jetpack/
 */

namespace MainWP\Child;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable PSR1.Classes.ClassDeclaration, WordPress.WP.AlternativeFunctions -- Required to achieve desired results. Pull requests appreciated.

/**
 * Class MainWP_Child_Jetpack_Scan
 *
 * MainWP Staging Extension handler.
 */
class MainWP_Child_Jetpack_Scan {

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
     * Public variable to hold the  plugin slug.
     *
     * @var string slug string.
     */
    public $the_plugin_slug = 'jetpack/jetpack.php';

    /**
     * Create a public static instance of MainWP_Child_Jetpack_Scan.
     *
     * @return MainWP_Child_Jetpack_Scan
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * MainWP_Child_Jetpack_Scan constructor.
     *
     * Run any time class is called.
     */
    public function __construct() {
        require_once ABSPATH . 'wp-admin/includes/plugin.php'; // NOSONAR - WP compatible.

        if ( is_plugin_active( 'jetpack-protect/jetpack-protect.php' ) && defined( 'JETPACK_PROTECT_DIR' ) ) {
            $this->is_plugin_installed = true;
        }

        if ( ! $this->is_plugin_installed && is_plugin_active( $this->the_plugin_slug ) && defined( 'JETPACK__PLUGIN_DIR' ) ) {
            $this->is_plugin_installed = true;
        }

        if ( ! $this->is_plugin_installed ) {
            return;
        }

        if ( 'hide' === get_option( 'mainwp_child_jetpack_scan_hide_plugin' ) ) {
            add_action( 'admin_head', array( &$this, 'admin_head' ) );
            add_filter( 'all_plugins', array( $this, 'hook_all_plugins' ) );
            add_action( 'admin_menu', array( $this, 'hook_remove_menu' ), 2000 ); // Jetpack uses 998.
            add_filter( 'site_transient_update_plugins', array( &$this, 'hook_remove_update_nag' ) );
            add_filter( 'mainwp_child_hide_update_notice', array( &$this, 'hook_hide_update_notice' ) );
        }
    }

    /**
     * Fires of certain Jetpack Scan plugin actions.
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
            MainWP_Helper::write( array( 'error' => __( 'Please install Jetpack Protect or Jetpact Scan plugin on child website', 'mainwp-child' ) ) );
        }

        $information = array();

        if ( ! empty( $mwp_action ) ) {
            try {
                if ( 'set_showhide' === $mwp_action ) {
                    $information = $this->set_showhide();
                }
            } catch ( MainWP_Exception $e ) {
                $information = array( 'error' => $e->getMessage() );
            }
        }
        MainWP_Helper::write( $information );
    }

    /**
     * Negotiate the additive Jetpack Scan abilities protocol.
     *
     * Visibility reads and exact desired-state writes are advertised only
     * after their closed normalization and readback tests pass.
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
                'operations'         => array( 'visibility_get', 'visibility_set' ),
                'mutation_supported' => true,
            );
        }

        if ( 'visibility_get' === $operation ) {
            if ( array() !== $request['payload'] ) {
                return $this->abilities_v2_error( $operation, 'invalid_request' );
            }

            return $this->abilities_v2_visibility();
        }

        if ( 'visibility_set' === $operation ) {
            if ( ! $this->abilities_v2_exact_keys( $request['payload'], array( 'desired_state', 'if_match' ) ) || ! is_string( $request['payload']['desired_state'] ) || ! in_array( $request['payload']['desired_state'], array( 'visible', 'hidden' ), true ) || ! is_string( $request['payload']['if_match'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $request['payload']['if_match'] ) ) {
                return $this->abilities_v2_error( $operation, 'invalid_request' );
            }

            return $this->abilities_v2_replace_visibility( $request['payload']['desired_state'], $request['payload']['if_match'] );
        }

        return $this->abilities_v2_error( $operation, 'unsupported_operation' );
    }

    /**
     * Return the closed state of the Jetpack Scan hide-toggle pair.
     *
     * The two options hide different plugin rows: this class removes the 'jetpack' row on the
     * scan option, MainWP_Child_Jetpack_Protect removes 'jetpack-protect' on the protect option,
     * and the legacy set_showhide() flips both as one switch. Visibility reports that switch:
     * hidden only when both toggles hide, visible only when neither does, and unknown for a
     * mixed or malformed pair rather than a definite answer some plugin row contradicts.
     *
     * @return array<string,mixed> Closed visibility response.
     */
    private function abilities_v2_visibility() {
        if ( $this->is_plugin_installed ) {
            $plugin_state = 'active';
        } else {
            $protect_file = WP_PLUGIN_DIR . '/jetpack-protect/jetpack-protect.php';
            $jetpack_file = WP_PLUGIN_DIR . '/' . $this->the_plugin_slug;
            $plugin_state = file_exists( $protect_file ) || file_exists( $jetpack_file ) ? 'inactive' : 'missing';
        }

        $scan_option    = get_option( 'mainwp_child_jetpack_scan_hide_plugin', false );
        $protect_option = get_option( 'mainwp_child_jetpack_protect_hide_plugin', false );
        $visibility     = 'unknown';
        if ( 'active' === $plugin_state ) {
            if ( 'hide' === $scan_option && 'hide' === $protect_option ) {
                $visibility = 'hidden';
            } elseif ( $this->abilities_v2_option_shows( $scan_option ) && $this->abilities_v2_option_shows( $protect_option ) ) {
                $visibility = 'visible';
            }
        }

        return array(
            'protocol'     => '2',
            'operation'    => 'visibility_get',
            'ok'           => true,
            'plugin_state' => $plugin_state,
            'visibility'   => $visibility,
            // Both raw toggles are in the hash, not only the derived visibility: a protect-side
            // write can change one toggle while the derived string stays the same, and a set
            // holding the older revision would silently erase that newer write.
            'revision'     => hash( 'sha256', $plugin_state . '|' . $visibility . '|' . $this->abilities_v2_option_norm( $scan_option ) . '|' . $this->abilities_v2_option_norm( $protect_option ) ),
            'observed_at'  => gmdate( 'Y-m-d\TH:i:s\Z' ),
        );
    }

    /**
     * Replace the exact Jetpack Scan visibility option with CAS and readback.
     *
     * @param string $desired_state Exact desired state.
     * @param string $if_match      Current visibility revision.
     * @return array<string,mixed> Closed mutation response.
     */
    private function abilities_v2_replace_visibility( $desired_state, $if_match ) {
        $current = $this->abilities_v2_visibility();
        // Only an inactive plugin refuses. A mixed or malformed pair still accepts the write:
        // the converging write is the only escape this ability offers from a state the legacy
        // actions can produce, and the revision binds the exact pair being replaced.
        if ( 'active' !== $current['plugin_state'] ) {
            return $this->abilities_v2_error( 'visibility_set', 'unsupported_version' );
        }
        if ( ! hash_equals( $current['revision'], $if_match ) ) {
            return $this->abilities_v2_error( 'visibility_set', 'stale_revision' );
        }
        if ( $desired_state === $current['visibility'] ) {
            $current['operation'] = 'visibility_set';
            $current['changed']   = false;
            return $current;
        }

        // Both options, like the legacy set_showhide(): either one hides the plugin, so writing
        // only the scan option lets a legacy hide keep the plugin hidden after this ability
        // reported it visible.
        $option_names = array( 'mainwp_child_jetpack_scan_hide_plugin', 'mainwp_child_jetpack_protect_hide_plugin' );
        $new_value    = 'hidden' === $desired_state ? 'hide' : 'show';
        $old_values   = array();
        $write_ok     = true;
        foreach ( $option_names as $option_name ) {
            $old_values[ $option_name ] = get_option( $option_name, false );
            $write_ok                   = MainWP_Helper::update_option( $option_name, $new_value, 'yes' ) && $write_ok;
        }
        $stored = $this->abilities_v2_visibility();
        if ( $desired_state === $stored['visibility'] ) {
            $stored['operation'] = 'visibility_set';
            $stored['changed']   = true;
            return $stored;
        }

        $restored = true;
        foreach ( $option_names as $option_name ) {
            $old_value = $old_values[ $option_name ];
            if ( false === $old_value ) {
                $restored = ( delete_option( $option_name ) || false === get_option( $option_name, false ) ) && $restored;
            } else {
                $restored = ( MainWP_Helper::update_option( $option_name, $old_value, 'yes' ) || get_option( $option_name, false ) === $old_value ) && $restored;
            }
        }
        if ( ! $restored ) {
            return $this->abilities_v2_error( 'visibility_set', 'outcome_unknown' );
        }

        return $this->abilities_v2_error( 'visibility_set', $write_ok ? 'contradictory_readback' : 'write_failed' );
    }

    /**
     * Decide whether one hide-option value means the plugin is not being hidden by it.
     *
     * @param mixed $value Raw option value.
     * @return bool
     */
    private function abilities_v2_option_shows( $value ) {
        return false === $value || '' === $value || 'show' === $value;
    }

    /**
     * Normalize one hide-option value to the behavior class the revision binds.
     *
     * @param mixed $value Raw option value.
     * @return string
     */
    private function abilities_v2_option_norm( $value ) {
        if ( 'hide' === $value ) {
            return 'hide';
        }
        return $this->abilities_v2_option_shows( $value ) ? 'show' : 'malformed';
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
     * Sets whether or not to hide the Jetpack Scan Plugin.
     *
     * @return array $information Action result.
     *
     * @uses \MainWP\Child\MainWP_Helper::update_option()
     */
    public function set_showhide() {
        $hide = MainWP_System::instance()->validate_params( 'showhide' );
        MainWP_Helper::update_option( 'mainwp_child_jetpack_scan_hide_plugin', $hide, 'yes' );
        MainWP_Helper::update_option( 'mainwp_child_jetpack_protect_hide_plugin', $hide, 'yes' );
        $information['result'] = 'SUCCESS';
        return $information;
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
            if ( 'jetpack' === $plugin_slug ) {
                unset( $plugins[ $key ] );
            }
        }

        return $plugins;
    }

    /**
     * Remove Jetpack WordPress Menu.
     */
    public function hook_remove_menu() {
        remove_menu_page( 'jetpack' );
        $pos = isset( $_SERVER['REQUEST_URI'] ) ? stripos( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), 'admin.php?page=jetpack' ) : false;
        if ( false !== $pos ) {
            wp_safe_redirect( admin_url( 'index.php' ) );
            exit();
        }
    }


    /**
     * Render admin header.
     */
    public function admin_head() {
        ?>
        <style type="text/css">
            div.jitm-card,
            div.jitm-banner {
                display: none !important;
            }
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
     * @param array $slugs Jetpack plugin slug.
     * @return mixed Returned $slugs.
     */
    public function hook_hide_update_notice( $slugs ) {
        $slugs[] = $this->the_plugin_slug;

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

        if ( isset( $value->response[ $this->the_plugin_slug ] ) ) {
            unset( $value->response[ $this->the_plugin_slug ] );
        }
        return $value;
    }
}
