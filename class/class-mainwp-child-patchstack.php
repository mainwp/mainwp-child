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
     * Download, install, and activate the Patchstack plugin; then resync.
     *
     * @return array|\WP_Error
     */
	private function install_plugin() { // phpcs:ignore -- NOSONAR -- complexity
        $raw_settings = $this->sanitized_post( 'settings' );
        $settings     = json_decode( $raw_settings, true );

        if ( empty( $settings['ps_id'] ) || empty( $settings['token'] ) ) {
            return new \WP_Error( 'bad_params', 'Missing ps_id or token.' );
        }

        // Ensure core functions/classes are available.
        if ( ! function_exists( '\get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php'; // phpcs:ignore -- NOSONAR
        }
        if ( ! class_exists( '\Plugin_Upgrader', false ) || ! class_exists( '\Automatic_Upgrader_Skin', false ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php'; // phpcs:ignore -- NOSONAR
        }
        if ( ! function_exists( '\request_filesystem_credentials' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php'; // phpcs:ignore -- NOSONAR
        }

        // Deactivate.
        $plugin_file        = $this->the_plugin_slug;
        $plugin_dir         = WP_PLUGIN_DIR . '/' . dirname( $plugin_file );
        $was_active         = function_exists( 'is_plugin_active' ) ? is_plugin_active( $plugin_file ) : false;
        $was_network_active = function_exists( 'is_plugin_active_for_network' ) && is_multisite()
            ? is_plugin_active_for_network( $plugin_file )
            : false;

        if ( $was_active || $was_network_active ) {
            deactivate_plugins( $plugin_file, true, $was_network_active );
            if ( is_plugin_active( $plugin_file ) || ( $was_network_active && is_plugin_active_for_network( $plugin_file ) ) ) {
                return new \WP_Error( 'deactivate_failed', 'Failed to deactivate existing plugin.' );
            }
        }

        if ( file_exists( WP_PLUGIN_DIR . '/' . $plugin_file ) || is_dir( $plugin_dir ) ) {
            if ( ! function_exists( 'delete_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php'; // phpcs:ignore -- NOSONAR
            }
            $deleted = delete_plugins( array( $plugin_file ) );
            if ( is_wp_error( $deleted ) ) {
                return new \WP_Error( 'delete_failed', 'Failed to delete existing plugin folder.', array( 'error' => $deleted->get_error_message() ) );
            }
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

        // Download ZIP (stream).
        $tmp = wp_tempnam( 'patchstack.zip' );
        if ( ! $tmp ) {
            return new \WP_Error( 'tmp_fail', 'Failed to create temp file.' );
        }

        $downloaded = $this->send_request(
            '/download/wordpress/' . (int) $settings['ps_id'],
            $settings['token'],
            'GET',
            array(),
            false,
            array(
                'timeout'   => 90,
                'stream'    => true,
                'stream_to' => $tmp,
                'headers'   => array( 'Accept' => 'application/zip' ),
            )
        );

        if ( is_wp_error( $downloaded ) ) {
            @unlink( $tmp );  // phpcs:ignore -- NOSONAR
            return $downloaded;
        }

        $ob_started = false;
        if ( ! ob_get_length() ) {
            ob_start();
            $ob_started = true;
        } else {
            ob_start();
            $ob_started = true;
        }

        $prev_display_errors = ini_get( 'display_errors' );
        @ini_set( 'display_errors', '0' ); // phpcs:ignore -- NOSONAR

        $skin_class = class_exists( '\WP_Ajax_Upgrader_Skin', false ) ? '\WP_Ajax_Upgrader_Skin' : '\Automatic_Upgrader_Skin';
        $skin       = new $skin_class();
        $upgrader   = new \Plugin_Upgrader( $skin );

        $options_filter = static function ( $options ) {
            $options['clear_destination']           = true;
            $options['abort_if_destination_exists'] = false;
            return $options;
        };
        add_filter( 'upgrader_package_options', $options_filter );

        $backup_upgrader_hook = $GLOBALS['wp_filter']['upgrader_process_complete'] ?? null;
        remove_all_actions( 'upgrader_process_complete' );

        try {
            $installed = $upgrader->install( $tmp );
        } finally {
            if ( $backup_upgrader_hook instanceof \WP_Hook ) {
                $GLOBALS['wp_filter']['upgrader_process_complete'] = $backup_upgrader_hook;  // phpcs:ignore -- NOSONAR
            } else {
                unset( $GLOBALS['wp_filter']['upgrader_process_complete'] );
            }

            remove_filter( 'upgrader_package_options', $options_filter );
            @unlink( $tmp ); // phpcs:ignore -- NOSONAR

            if ( $ob_started ) {
                while ( ob_get_level() > 0 ) {
                    @ob_end_clean(); // phpcs:ignore -- NOSONAR
                }
            }
            if ( null !== $prev_display_errors ) {
                @ini_set( 'display_errors', $prev_display_errors ); // phpcs:ignore -- NOSONAR
            }
        }

        if ( is_wp_error( $installed ) ) {
            return $installed;
        }
        if ( ! $installed ) {
            return new \WP_Error( 'install_failed', 'Plugin installation failed.' );
        }

        // Activate.
        $activate = activate_plugin( $plugin_file, '', $was_network_active );
        if ( is_wp_error( $activate ) ) {
            return $activate;
        }

        $is_active      = is_plugin_active( $plugin_file );
        $is_network_now = is_multisite() && is_plugin_active_for_network( $plugin_file );

        // Resync.
        $re_sync = $this->send_request(
            '/site/plugin/resync/' . (int) $settings['ps_id'],
            $settings['token'],
            'POST',
            array(),
            true,
            array( 'timeout' => 30 )
        );

        $message = 'Resync triggered.';
        if ( is_wp_error( $re_sync ) ) {
            $message = 'Resync failed: ' . $re_sync->get_error_message();
        } elseif ( is_array( $re_sync ) && isset( $re_sync['success'] ) ) {
            $message = $re_sync['success'] ? ( $re_sync['message'] ?? 'Resync triggered.' ) : ( $re_sync['message'] ?? 'Resync failed.' );
        }

        return array(
            'success'     => 1,
            'activated'   => 1,
            'is_active'   => (int) $is_active,
            'is_network'  => (int) $is_network_now,
            'plugin_file' => $plugin_file,
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
     * Send an HTTP request to the API and return JSON (array) or raw/binary.
     *
     * @param string            $url         Relative or absolute URL (e.g. '/site/plugin/resync/123').
     * @param string            $token       API token.
     * @param string            $method      HTTP method. Default 'GET'.
     * @param array|string|null $data        For non-GET: request body (array => JSON). For GET: appended as query if array.
     * @param bool              $expect_json Expect JSON (true) or raw/binary (false).
     * @param array             $extra       Options:
     *   - timeout (int, default 60)
     *   - redirection (int, default 5)
     *   - sslverify (bool, default true)
     *   - headers (array)             Merge extra headers.
     *   - auth_header (string)        Header name for token. Default 'UserToken'. Use 'Authorization' for Bearer.
     *   - allow_get_query (bool)      Append array $data to query for GET. Default true.
     *   - stream (bool)               Stream to file (requires 'stream_to'). Default false.
     *   - stream_to (string)          Absolute path of target file when streaming.
     *   - max_tries (int)             Retry attempts for transient errors. Default 3.
     *   - backoff_base_ms (int)       First backoff (ms). Default 400.
     *
     * @return array|string|\WP_Error
     */
	private function send_request( $url, $token, $method = 'GET', $data = array(), $expect_json = true, $extra = array() ) { // phpcs:ignore -- NOSONAR
        if ( empty( $token ) ) {
            return new \WP_Error( 'no_token', 'Missing API token.' );
        }

        $method = strtoupper( $method );

        // Absolute URL.
        $base = rtrim( (string) $this->api_url, '/' );
        $abs  = ( 0 === strpos( $url, 'http://' ) || 0 === strpos( $url, 'https://' ) )
            ? $url
            : $base . '/' . ltrim( $url, '/' );

        $timeout         = isset( $extra['timeout'] ) ? (int) $extra['timeout'] : 60;
        $redirection     = isset( $extra['redirection'] ) ? (int) $extra['redirection'] : 5;
        $sslverify       = array_key_exists( 'sslverify', $extra ) ? (bool) $extra['sslverify'] : true;
        $auth_header     = ! empty( $extra['auth_header'] ) ? (string) $extra['auth_header'] : 'UserToken';
        $allow_get_query = array_key_exists( 'allow_get_query', $extra ) ? (bool) $extra['allow_get_query'] : true;

        $default_headers = array(
            $auth_header => 'Authorization' === $auth_header ? 'Bearer ' . $token : $token,
            'Accept'     => $expect_json ? 'application/json' : '*/*', // NOSONAR.
        );
        $headers         = isset( $extra['headers'] ) && is_array( $extra['headers'] )
            ? array_merge( $default_headers, $extra['headers'] )
            : $default_headers;

        // GET query support.
        if ( 'GET' === $method && $allow_get_query && is_array( $data ) && ! empty( $data ) ) {
            $abs  = add_query_arg( $data, $abs );
            $data = null;
        }

        $args = array(
            'method'      => $method,
            'timeout'     => $timeout,
            'redirection' => $redirection,
            'blocking'    => true,
            'headers'     => $headers,
            'sslverify'   => $sslverify,
            'decompress'  => true,
        );

        // Body for non-GET/HEAD.
        if ( ! empty( $data ) && ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
            if ( is_array( $data ) ) {
                $args['headers']['Content-Type'] = 'application/json';
                $args['body']                    = wp_json_encode( $data );
            } else {
                $args['body'] = (string) $data;
            }
        }

        // Stream options (large/binary).
        $stream   = ! empty( $extra['stream'] ) && ! empty( $extra['stream_to'] );
        $filename = $stream ? (string) $extra['stream_to'] : null;
        if ( $stream ) {
            $args['stream']   = true;
            $args['filename'] = $filename;
        }

        // Retry policy.
        $max_tries       = isset( $extra['max_tries'] ) ? max( 1, (int) $extra['max_tries'] ) : 3;
        $backoff_base_ms = isset( $extra['backoff_base_ms'] ) ? max( 100, (int) $extra['backoff_base_ms'] ) : 400;

        $attempt  = 0;
        $response = null;

        while ( $attempt < $max_tries ) {
            ++$attempt;
            $response = wp_remote_request( $abs, $args );

			if ( is_wp_error( $response ) ) {  // phpcs:ignore -- NOSONAR
                // transport error -> retry.
            } else {
                $code = (int) wp_remote_retrieve_response_code( $response );
                // success.
                if ( $code >= 200 && $code < 300 ) {
                    break;
                }
                // transient: 408, 429, 5xx (except 501/505).
                if ( ! in_array( $code, array( 408, 429 ), true ) && ! ( $code >= 500 && $code < 600 && ! in_array( $code, array( 501, 505 ), true ) ) ) {
                    break; // non-retryable.
                }
            }

            if ( $attempt < $max_tries ) {
                $sleep_ms = $backoff_base_ms * $attempt;
                usleep( $sleep_ms * 1000 );
            }
        }

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code        = (int) wp_remote_retrieve_response_code( $response );
        $headers_out = wp_remote_retrieve_headers( $response );
        $ctype       = (string) wp_remote_retrieve_header( $response, 'content-type' );
        $ctype_main  = strtolower( trim( explode( ';', $ctype )[0] ) );

        if ( $code < 200 || $code >= 300 ) {
            $body = $stream ? '' : (string) wp_remote_retrieve_body( $response );
            $msg  = "HTTP $code";
            if ( 'application/json' === $ctype_main && '' !== $body ) {
                $json = json_decode( $body, true );
                if ( is_array( $json ) ) {
                    $msg = $json['error'] ?? $json['message'] ?? $msg;
                }
            }
            return new \WP_Error(
                'http_error',
                $msg,
                array(
                    'status'  => $code,
                    'headers' => $headers_out,
                    'body'    => $stream ? '(streamed)' : substr( $body, 0, 1000 ),
                    'ctype'   => $ctype,
                    'url'     => $abs,
                )
            );
        }

        if ( 204 === $code ) {
            return $expect_json ? array() : '';
        }

        if ( $stream ) {
            if ( ! file_exists( $filename ) || filesize( $filename ) === 0 ) {
                return new \WP_Error(
                    'empty_file',
                    'Streamed file is empty.',
                    array(
                        'url'      => $abs,
                        'filename' => $filename,
                    )
                );
            }
            return $filename; // path to downloaded file.
        }

        $body = (string) wp_remote_retrieve_body( $response );

        if ( $expect_json || 'application/json' === $ctype_main ) {
            if ( '' === $body ) {
                return array();
            }
            $decoded = json_decode( $body, true );
            if ( json_last_error() !== JSON_ERROR_NONE ) {
                return new \WP_Error(
                    'json_decode',
                    'Invalid JSON: ' . json_last_error_msg(),
                    array(
                        'body' => substr( $body, 0, 1000 ),
                        'url'  => $abs,
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
