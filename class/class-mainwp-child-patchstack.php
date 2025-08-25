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

// phpcs:disable PSR1.Classes.ClassDeclaration, WordPress.WP.AlternativeFunctions -- required to achieve desired results, pull request solutions appreciated.

/**
 * Class MainWP_Child_Patchstack
 *
 * MainWP Patchstack extension handler
 */
class MainWP_Child_Patchstack { //phpcs:ignore -- NOSONAR - multi methods.

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
                case 'install_plugin':
                    $information = $this->install_plugin();
                    break;
                case 'set_showhide':
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

        if ( ! is_plugin_active( $plugin_file ) ) {
            $activate = activate_plugin( $plugin_file, '', false, true );
            if ( is_wp_error( $activate ) ) {
                return new \WP_Error( 'activate_failed', 'Activation failed: ' . $activate->get_error_message(), $activate->get_error_data() );
            }
        }

        $is_active = is_plugin_active( $plugin_file );
        // Trigger resync.
        $re_sync = $this->send_request( '/site/plugin/resync/' . $settings['ps_id'], $settings['token'], 'POST' );
        $message = ( is_array( $re_sync ) && isset( $re_sync['success'] ) ) ? $re_sync['success'] : 'Resync triggered.';

        return array(
            'success'     => 1,
            'is_active'   => (int) $is_active,
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
        return array(
            'success'  => 1,
            'response' => array(),
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
        return array(
            'success'  => 1,
            'response' => array(),
        );
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
        if ( ! in_array( $callback, self::$allowed_callbacks, true ) && ! is_callable( $callback ) ) {
            $callback = 'sanitize_text_field';
        }

        return isset( $_POST[ $key ] ) ? $callback( wp_unslash( $_POST[ $key ] ) ) : $default_value; //phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    }
}
