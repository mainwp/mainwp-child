<?php
/**
 * MainWP Child Misc functions
 *
 * This file is for misc functions that don't really belong anywhere else.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions -- Required to achieve desired results, pull request solutions appreciated.

/**
 * Class MainWP_Child_Misc
 *
 * Misc functions that don't really belong anywhere else.
 */
class MainWP_Child_Misc {

    /**
     * Public static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    protected static $instance = null;

    /**
     * Method get_class_name()
     *
     * Get class name.
     *
     * @return string __CLASS__ Class name.
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * MainWP_Child_Misc constructor.
     *
     * Run any time class is called.
     */
    public function __construct() {
    }

    /**
     * Method get_instance()
     *
     * Create a public static instance.
     *
     * @return mixed Class instance.
     */
    public static function get_instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Method get_site_icon()
     *
     * Fire off the get favicon function and add to sync information.
     *
     * @uses \MainWP\Child\MainWP_Child_Misc::get_favicon() Get the child site favicon.
     * @uses \MainWP\Child\MainWP_Helper::write() Write response data to be sent to the MainWP Dashboard.
     */
    public function get_site_icon() {
        $information = array();
        $url         = $this->get_favicon( true );
        if ( ! empty( $url ) ) {
            $information['faviIconUrl'] = $url;
        }
        MainWP_Helper::write( $information );
    }

    /**
     * Method get_favicon()
     *
     * Get the child site favicon.
     *
     * @param bool $parse_page Whether or not to parse the page. Default: false.
     *
     * @uses MainWP_Child_Misc::try_to_parse_favicon() Try to parse child site URL for favicon.
     * @uses get_site_icon_url() Returns the Site Icon URL.
     * @see https://developer.wordpress.org/reference/functions/get_site_icon_url/
     *
     * @used-by MainWP_Child_Misc::get_site_icon() Fire off the get favicon function and add to sync information.
     *
     * @return string|bool Return favicon URL on success, FALSE on failure.
     */
    public function get_favicon( $parse_page = false ) { //phpcs:ignore -- NOSONAR - complex.

        $favi_url = '';
        $favi     = '';
        $site_url = get_option( 'siteurl' );
        if ( substr( $site_url, - 1 ) !== '/' ) {
            $site_url .= '/';
        }

        if ( function_exists( '\get_site_icon_url' ) && \has_site_icon() ) {
            $favi     = \get_site_icon_url();
            $favi_url = $favi;
        }

        if ( empty( $favi ) ) {
            if ( file_exists( ABSPATH . 'favicon.ico' ) ) {
                $favi = 'favicon.ico';
            } elseif ( file_exists( ABSPATH . 'favicon.png' ) ) {
                $favi = 'favicon.png';
            }

            if ( ! empty( $favi ) ) {
                $favi_url = $site_url . $favi;
            }
        }

        if ( $parse_page ) {
            // try to parse page.
            if ( empty( $favi_url ) ) {
                $favi_url = $this->try_to_parse_favicon( $site_url );
            }

            if ( ! empty( $favi_url ) ) {
                return $favi_url;
            } else {
                return false;
            }
        } else {
            return $favi_url;
        }
    }

    /**
     * Method try_to_parse_favicon()
     *
     * Try to parse child site URL for favicon.
     *
     * @param string $site_url Child site URL.
     *
     * @uses wp_remote_get() Performs an HTTP request using the GET method and returns its response.
     * @see https://developer.wordpress.org/reference/functions/wp_remote_get/
     *
     * @used-by MainWP_Child_Misc::get_favicon() Get the child site favicon.
     *
     * @return string Parsed favicon.
     */
    private function try_to_parse_favicon( $site_url ) { //phpcs:ignore -- NOSONAR - complex.
        $request = wp_remote_get( $site_url, array( 'timeout' => 50 ) );
        $favi    = '';
        if ( is_array( $request ) && isset( $request['body'] ) ) {
            $preg_str1 = '/(<link\s+[^\>]*rel="shortcut\s+icon"\s*[^>]*href="([^"]+)"[^>]*>)/is';
            $preg_str2 = '/(<link\s+[^\>]*rel="(?:shortcut\s+)?icon"\s*[^>]*href="([^"]+)"[^>]*>)/is';

            if ( preg_match( $preg_str1, $request['body'], $matches ) ) {
                $favi = $matches[2];
            } elseif ( preg_match( $preg_str2, $request['body'], $matches2 ) ) {
                $favi = $matches2[2];
            }
        }
        $favi_url = '';
        if ( ! empty( $favi ) ) {
            if ( false === strpos( $favi, 'http' ) ) {
                if ( 0 === strpos( $favi, '//' ) ) {
                    if ( 0 === strpos( $site_url, 'https' ) ) {
                        $favi_url = 'https:' . $favi;
                    } else {
                        $favi_url = 'http:' . $favi;
                    }
                } else {
                    $favi_url = $site_url . $favi;
                }
            } else {
                $favi_url = $favi;
            }
        }
        return $favi_url;
    }

    /**
     * Method get_security_stats()
     *
     * Get security issues information.
     *
     * @param bool $return_results Either return or not.
     *
     * @return array
     *
     * @uses \MainWP\Child\MainWP_Helper::write()
     * @uses \MainWP\Child\MainWP_Security::remove_database_reporting_ok()
     * @uses \MainWP\Child\MainWP_Security::remove_php_reporting_ok()
     */
    public function get_security_stats( $return_results = false ) { // phpcs:ignore -- NOSONAR - required to achieve desired results, pull request solutions appreciated.
        $information = array();

        $information['db_reporting']       = ( ! MainWP_Security::remove_database_reporting_ok() ? 'N' : 'Y' );
        $information['php_reporting']      = ( ! MainWP_Security::remove_php_reporting_ok() ? 'N' : 'Y' );
        $information['wp_uptodate']        = ( MainWP_Security::wpcore_updated_ok() ? 'Y' : 'N' );
        $information['phpversion_matched'] = ( MainWP_Security::phpversion_ok() ? 'Y' : 'N' );
        $information['sslprotocol']        = ( MainWP_Security::sslprotocol_ok() ? 'Y' : 'N' );
        $information['debug_disabled']     = ( MainWP_Security::debug_disabled_ok() ? 'Y' : 'N' );

        $information['sec_outdated_plugins'] = ( MainWP_Security::outdated_plugins_ok() ? 'Y' : 'N' );
        $information['sec_inactive_plugins'] = ( MainWP_Security::inactive_plugins_ok() ? 'Y' : 'N' );
        $information['sec_outdated_themes']  = ( MainWP_Security::outdated_themes_ok() ? 'Y' : 'N' );
        $information['sec_inactive_themes']  = ( MainWP_Security::inactive_themes_ok() ? 'Y' : 'N' );

        if ( 'N' === $information['db_reporting'] && MainWP_Security::get_security_option( 'db_reporting' ) ) {
            $information['db_reporting'] = 'N_UNABLE';
        } elseif ( 'Y' === $information['db_reporting'] && ! MainWP_Security::get_security_option( 'db_reporting' ) ) {
            $information['db_reporting'] = 'Y_UNABLE';
        }

        if ( 'N' === $information['php_reporting'] && MainWP_Security::get_security_option( 'php_reporting' ) ) {
            $information['php_reporting'] = 'N_UNABLE';
        } elseif ( 'Y' === $information['php_reporting'] && ! MainWP_Security::get_security_option( 'php_reporting' ) ) {
            $information['php_reporting'] = 'Y_UNABLE';
        }

        if ( $return_results ) {
            return $information;
        }

        MainWP_Helper::write( $information );
    }

    /**
     * Method do_security_fix()
     *
     * Fix detected security issues and set feedback to sync information.
     *
     * @uses \MainWP\Child\MainWP_Helper::update_option() Update option by name.
     * @uses \MainWP\Child\MainWP_Helper::write() Write response data to be sent to the MainWP Dashboard.
     * @uses \MainWP\Child\MainWP_Security::prevent_listing()
     * @uses \MainWP\Child\MainWP_Security::remove_database_reporting()
     * @uses \MainWP\Child\MainWP_Security::remove_database_reporting_ok()
     * @uses \MainWP\Child\MainWP_Security::remove_php_reporting()
     * @uses \MainWP\Child\MainWP_Security::remove_php_reporting_ok()
     * @uses \MainWP\Child\MainWP_Child_Stats::get_site_stats()
     */
    public function do_security_fix() { // phpcs:ignore -- NOSONAR - Current complexity is the only way to achieve desired results, pull request solutions appreciated.
        $sync = false;
        // phpcs:disable WordPress.Security.NonceVerification
        $feature = isset( $_POST['feature'] ) ? sanitize_text_field( wp_unslash( $_POST['feature'] ) ) : '';

        if ( 'all' === $feature ) {
            $sync = true;
        }

        $skips = isset( $_POST['skip_features'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['skip_features'] ) ) : array();
        if ( ! is_array( $skips ) ) {
            $skips = array();
        }
        // phpcs:enable
        $information = array();
        $security    = get_option( 'mainwp_security', array() );
        if ( ! is_array( $security ) ) {
            $security = array();
        }

        if ( 'all' === $feature ) {
            $security = array();
        }

        if ( 'all' === $feature || 'db_reporting' === $feature ) {
            if ( ! in_array( 'db_reporting', $skips ) ) {
                $security['db_reporting'] = true;
                MainWP_Security::remove_database_reporting();
            }
            $information['db_reporting'] = ( ! MainWP_Security::remove_database_reporting_ok() ? 'N' : 'Y' );
        }

        if ( 'all' === $feature || 'php_reporting' === $feature ) {
            if ( ! in_array( 'php_reporting', $skips ) ) {
                $security['php_reporting'] = true;
                MainWP_Security::remove_php_reporting( true );
            }
            $information['php_reporting'] = ( ! MainWP_Security::remove_php_reporting_ok() ? 'N' : 'Y' );
        }

        MainWP_Helper::update_option( 'mainwp_security', $security, 'yes' );

        if ( $sync ) {
            $information['sync'] = MainWP_Child_Stats::get_instance()->get_site_stats( array(), false );
        }
        MainWP_Helper::write( $information );
    }

    /**
     * Method do_security_un_fix()
     *
     * Unfix fixed child site security issues.
     *
     * @uses \MainWP\Child\MainWP_Helper::update_option() Update option by name.
     * @uses \MainWP\Child\MainWP_Helper::write() Write response data to be sent to the MainWP Dashboard.
     * @uses \MainWP\Child\MainWP_Child_Stats::get_site_stats()
     */
    public function do_security_un_fix() { //phpcs:ignore -- NOSONAR - complex.
        $information = array();

        // phpcs:disable WordPress.Security.NonceVerification
        $feature = isset( $_POST['feature'] ) ? sanitize_text_field( wp_unslash( $_POST['feature'] ) ) : '';
         // phpcs:enable

        $sync = false;
        if ( 'all' === $feature ) {
            $sync = true;
        }

        $security = get_option( 'mainwp_security', array() );

        if ( ! is_array( $security ) ) {
            $security = array();
        }

        if ( 'all' === $feature || 'php_reporting' === $feature ) {
            $security['php_reporting']    = false;
            $information['php_reporting'] = 'N';
        }

        if ( 'all' === $feature || 'db_reporting' === $feature ) {
            $security['db_reporting']    = false;
            $information['db_reporting'] = 'N';
        }

        MainWP_Helper::update_option( 'mainwp_security', $security, 'yes' );

        if ( $sync ) {
            $information['sync'] = MainWP_Child_Stats::get_instance()->get_site_stats( array(), false );
        }

        MainWP_Helper::write( $information );
    }

    /**
     * Method settings_tools()
     *
     * Fire off misc actions and set feedback to the sync information.
     *
     * @uses \MainWP\Child\MainWP_Helper::write() Write response data to be sent to the MainWP Dashboard.
     * @uses wp_destroy_all_sessions() Remove all session tokens for the current user from the database.
     * @see https://developer.wordpress.org/reference/functions/wp_destroy_all_sessions/
     *
     * @uses wp_get_all_sessions() Retrieve a list of sessions for the current user.
     * @see https://developer.wordpress.org/reference/functions/wp_get_all_sessions/
     */
    public function settings_tools() {
        // phpcs:disable WordPress.Security.NonceVerification
        if ( isset( $_POST['action'] ) ) {
            $mwp_action = MainWP_System::instance()->validate_params( 'action' );
            // phpcs:enable
            if ( 'force_destroy_sessions' === $mwp_action ) {
                if ( 0 === get_current_user_id() ) {
                    MainWP_Helper::write( array( 'error' => esc_html__( 'Cannot get user_id', 'mainwp-child' ) ) );
                }

                wp_destroy_all_sessions();

                $sessions = wp_get_all_sessions();

                if ( empty( $sessions ) ) {
                    MainWP_Helper::write( array( 'success' => 1 ) );
                } else {
                    MainWP_Helper::write( array( 'error' => esc_html__( 'Cannot destroy sessions', 'mainwp-child' ) ) );
                }
            } else {
                MainWP_Helper::write( array( 'error' => esc_html__( 'Invalid action', 'mainwp-child' ) ) );
            }
        } else {
            MainWP_Helper::write( array( 'error' => esc_html__( 'Missing action', 'mainwp-child' ) ) );
        }
    }

    /**
     * Method uploader_action()
     *
     * Initiate the file upload action.
     *
     * @return void
     *
     * @uses \MainWP\Child\MainWP_Helper::write() Write response data to be sent to the MainWP Dashboard.
     * @uses \MainWP\Child\MainWP_Child_Misc::uploader_upload_file() Upload file from the MainWP Dashboard.
     */
    public function uploader_action() {
        // phpcs:disable WordPress.Security.NonceVerification
        $file_url    = isset( $_POST['url'] ) ? MainWP_Utility::instance()->maybe_base64_decode( wp_unslash( $_POST['url'] ) ) : ''; //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $path        = isset( $_POST['path'] ) ? sanitize_text_field( wp_unslash( $_POST['path'] ) ) : '';
        $filename    = isset( $_POST['filename'] ) ? sanitize_text_field( wp_unslash( $_POST['filename'] ) ) : '';
        $information = array();
        // phpcs:enable
        if ( empty( $file_url ) || empty( $path ) ) {
            MainWP_Helper::write( $information );

            return;
        }

        if ( strpos( $path, 'wp-content' ) === 0 ) {
            $path = basename( WP_CONTENT_DIR ) . substr( $path, 10 );
        } elseif ( strpos( $path, 'wp-includes' ) === 0 ) {
            $path = WPINC . substr( $path, 11 );
        }

        if ( '/' === $path ) {
            $dir = ABSPATH;
        } else {
            // fix invalid name.
            $path = str_replace( '../', '--/', $path );
            $path = str_replace( './', '-/', $path );
            $dir  = ABSPATH . $path;
        }

        if ( ! file_exists( $dir ) && false === MainWP_Helper::mkdir( $dir, 0777, true ) ) { //phpcs:ignore WordPress.WP.AlternativeFunctions -- NOSONAR -- it works.
            $information['error'] = 'ERRORCREATEDIR';
            MainWP_Helper::write( $information );
            return;
        }

        try {
            $upload = $this->uploader_upload_file( $file_url, $dir, $filename );
            if ( null !== $upload ) {
                $information['success'] = true;
            }
        } catch ( MainWP_Exception $e ) {
            $information['error'] = $e->getMessage();
        }
        MainWP_Helper::write( $information );
    }

    /**
     * Handle the narrow Virusdie signed-installer protocol.
     *
     * The executable mutation remains unavailable until the Dashboard supplies
     * the audited signed-manifest and one-use-token contract.
     *
     * @return void
     */
    public function virusdie_sync_install_v1() {
        // phpcs:disable WordPress.Security.NonceVerification
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON request body, length-capped and strictly validated by json_decode; sanitizing would corrupt it.
        $raw_request = isset( $_POST['request'] ) && is_string( $_POST['request'] ) ? wp_unslash( $_POST['request'] ) : '';
        // phpcs:enable
        $request = 4096 >= strlen( $raw_request ) ? json_decode( $raw_request, true ) : null;

        MainWP_Helper::write( $this->virusdie_sync_install_v1_response( $request ) );
    }

    /**
     * Build one closed Virusdie installer protocol response.
     *
     * @param mixed $request Decoded request object.
     * @return array<string,mixed> Closed response.
     */
    public function virusdie_sync_install_v1_response( $request ) {
        return ( new MainWP_Child_Virusdie() )->request_v1( $request );
    }

    /**
     * Method uploader_upload_file()
     *
     * Upload file from the MainWP Dashboard.
     *
     * @param string $file_url  URL of file to be uploaded.
     * @param string $path      Path to upload to.
     * @param string $file_name Name of file to upload.
     *
     * @uses wp_remote_get() Performs an HTTP request using the GET method and returns its response.
     * @see https://developer.wordpress.org/reference/functions/wp_remote_get/
     *
     * @uses sanitize_file_name() Sanitizes a filename, replacing whitespace with dashes.
     * @see https://developer.wordpress.org/reference/functions/sanitize_file_name/
     *
     * @uses is_wp_error() Check whether variable is a WordPress Error.
     * @see https://developer.wordpress.org/reference/functions/is_wp_error/
     *
     * @uses wp_remote_retrieve_response_code() Retrieve only the response code from the raw response.
     * @see https://developer.wordpress.org/reference/functions/wp_remote_retrieve_response_code/
     *
     * @uses wp_remote_retrieve_response_message() Retrieve only the response message from the raw response.
     * @see https://developer.wordpress.org/reference/functions/wp_remote_retrieve_response_message/
     *
     * @used-by MainWP_Child_Misc::uploader_action() Initiate the file upload action.
     *
     * @throws MainWP_Exception Error message.
     *
     * @return array Full path and file name of uploaded file.
     */
    public function uploader_upload_file( $file_url, $path, $file_name ) {

        $file_name = $this->sanitize_file_name( $file_name );

        $full_file_name = $path . DIRECTORY_SEPARATOR . $file_name;

        $response = wp_remote_get(
            $file_url,
            array(
                'timeout'  => 10 * 60 * 60,
                'stream'   => true,
                'filename' => $full_file_name,
            )
        );

        if ( is_wp_error( $response ) ) {
            wp_delete_file( $full_file_name );
            throw new MainWP_Exception( 'Error: ' . esc_html( $response->get_error_message() ) );
        }

        if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
            wp_delete_file( $full_file_name );
            throw new MainWP_Exception( 'Error 404: ' . esc_html( trim( wp_remote_retrieve_response_message( $response ) ) ) );
        }
        $fix_name = true;
        if ( '.phpfile.txt' === substr( $file_name, - 12 ) ) {
            $new_file_name = substr( $file_name, 0, - 12 ) . '.php';
            $new_file_name = $path . DIRECTORY_SEPARATOR . $new_file_name;
        } elseif ( 0 === strpos( $file_name, 'fix_underscore' ) ) { // to compatible.
            $new_file_name = str_replace( 'fix_underscore', '', $file_name );
            $new_file_name = $path . DIRECTORY_SEPARATOR . $new_file_name;
        } else {
            $fix_name = false;
        }

        if ( $fix_name ) {
            $moved = rename( $full_file_name, $new_file_name ); //phpcs:ignore WordPress.WP.AlternativeFunctions
            if ( $moved ) {
                return array( 'path' => $new_file_name );
            } else {
                wp_delete_file( $full_file_name );
                throw new MainWP_Exception( 'Error: Copy file.' );
            }
        }

        return array( 'path' => $full_file_name );
    }

    /**
     *
     * Sanitizes a filename, replacing whitespace with dashes.
     *
     * Removes special characters that are illegal in filenames on certain
     * operating systems and special characters requiring special escaping
     * to manipulate at the command line. Replaces spaces and consecutive
     * dashes with a single dash. Trims period, dash and underscore from beginning
     * and end of filename. It is not guaranteed that this function will return a
     * filename that is allowed to be uploaded.
     *
     * @since 2.1.0
     * @credit WordPress.
     * @param string $filename The filename to be sanitized.
     * @return string The sanitized filename.
     */
    private function sanitize_file_name( $filename ) {
        $filename_raw = $filename;
        $filename     = remove_accents( $filename );

        $special_chars = array( '?', '[', ']', '/', '\\', '=', '<', '>', ':', ';', ',', "'", '"', '&', '$', '#', '*', '(', ')', '|', '~', '`', '!', '{', '}', '%', '+', '’', '«', '»', '”', '“', chr( 0 ) );

        // Check for support for utf8 in the installed PCRE library once and store the result in a static.
        static $utf8_pcre = null;
        if ( ! isset( $utf8_pcre ) ) {
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            $utf8_pcre = @preg_match( '/^./u', 'a' );
        }

        if ( ! seems_utf8( $filename ) ) { // phpcs:ignore WordPress.WP.DeprecatedFunctions.seems_utf8Found -- wp_is_valid_utf8() requires WP 6.9; the plugin supports 6.2+.
            $_ext     = pathinfo( $filename, PATHINFO_EXTENSION );
            $_name    = pathinfo( $filename, PATHINFO_FILENAME );
            $filename = sanitize_title_with_dashes( $_name ) . '.' . $_ext;
        }

        if ( $utf8_pcre ) {
            $filename = str_replace( "\x{00a0}", ' ', $filename );
        }

        /**
         * Filters the list of characters to remove from a filename.
         *
         * @since 2.8.0
         *
         * @param string[] $special_chars Array of characters to remove.
         * @param string   $filename_raw  The original filename to be sanitized.
         */
        $special_chars = apply_filters( 'sanitize_file_name_chars', $special_chars, $filename_raw );

        $filename = str_replace( $special_chars, '', $filename );
        $filename = str_replace( array( '%20', '+' ), '-', $filename );
        $filename = preg_replace( '/\.{2,}/', '.', $filename );
        $filename = preg_replace( '/[\r\n\t -]+/', '-', $filename );

        /**
         * Filters a sanitized filename string.
         *
         * @since 2.8.0
         *
         * @param string $filename     Sanitized filename.
         * @param string $filename_raw The filename prior to sanitization.
         */
        return apply_filters( 'sanitize_file_name', $filename, $filename_raw );
    }

    /**
     * Method code_snippet()
     *
     * Initiate Code Snippet actions run_snippet, save_snippet and delete_snippet.
     *
     * @uses \MainWP\Child\MainWP_Helper::write() Write response data to be sent to the MainWP Dashboard.
     * @uses \MainWP\Child\MainWP_Utility::execute_snippet() Execute code snippet.
     * @uses \MainWP\Child\MainWP_Child_Misc::snippet_save_snippet() Save code snippet.
     * @uses \MainWP\Child\MainWP_Child_Misc::snippet_delete_snippet() Delete code snippet.
     * @uses get_option() Retrieves an option value based on an option name.
     * @see https://developer.wordpress.org/reference/functions/get_option/
     * @uses \MainWP\Child\MainWP_Utility::execute_snippet()
     */
    public function code_snippet() {
        // phpcs:disable WordPress.Security.NonceVerification
        $action = MainWP_System::instance()->validate_params( 'action' );

        if ( in_array( $action, array( 'run_snippet_v2', 'apply_snippet_v2', 'remove_snippet_v2' ), true ) ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON request body, length-capped and strictly validated by json_decode; sanitizing would corrupt it.
            $raw_request = isset( $_POST['request'] ) && is_string( $_POST['request'] ) ? wp_unslash( $_POST['request'] ) : '';
            // The bound covers the worst-case encoding of a body the Dashboard is allowed to send,
            // not the size of the code inside it: wp_json_encode() with default flags turns every
            // non-ASCII byte into a six-character \uXXXX escape, so the accepted 60000 bytes of
            // code can reach 360000 on the wire, plus the envelope around it.
            $request = 393216 >= strlen( $raw_request ) ? json_decode( $raw_request, true ) : null;
            MainWP_Helper::write( $this->snippet_v2( $action, $request ) );
        }

        $type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
        $slug = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';

        $snippets = get_option( 'mainwp_ext_code_snippets' );

        if ( ! is_array( $snippets ) ) {
            $snippets = array();
        }

        if ( ( 'run_snippet' === $action || 'save_snippet' === $action ) && empty( $_POST['code'] ) ) {
            MainWP_Helper::write( array( 'status' => 'FAIL' ) );
        }

        $code = isset( $_POST['code'] ) ? wp_unslash( $_POST['code'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        // phpcs:enable
        $information = array();
        if ( 'run_snippet' === $action ) {
            $information = MainWP_Utility::execute_snippet( $code );
        } elseif ( 'save_snippet' === $action ) {
            $information = $this->snippet_save_snippet( $slug, $type, $code, $snippets );
        } elseif ( 'delete_snippet' === $action ) {
            $information = $this->snippet_delete_snippet( $slug, $type, $snippets );
        }

        if ( empty( $information ) ) {
            $information = array( 'status' => 'FAIL' );
        }

        MainWP_Helper::write( $information );
    }

    /**
     * Execute one closed Code Snippets protocol-v2 request.
     *
     * @param string $action  Protocol action.
     * @param mixed  $request Decoded request object.
     * @return array<string,mixed> Closed result.
     */
    public function snippet_v2( $action, $request ) {
        if ( ! is_array( $request ) ) {
            return $this->snippet_v2_error( 'invalid_request' );
        }

        $keys = array(
            'run_snippet_v2'    => array( 'protocol_version', 'request_ref', 'slug', 'type', 'code' ),
            'apply_snippet_v2'  => array( 'protocol_version', 'request_ref', 'slug', 'type', 'code' ),
            'remove_snippet_v2' => array( 'protocol_version', 'request_ref', 'slug', 'type' ),
        );
        $ref  = isset( $request['request_ref'] ) && $this->snippet_v2_valid_request_ref( $request['request_ref'] ) ? $request['request_ref'] : null;
        if ( ! isset( $keys[ $action ] ) || ! $this->snippet_v2_exact_keys( $request, $keys[ $action ] ) || 2 !== $request['protocol_version'] || null === $ref || ! $this->snippet_v2_valid_slug( $request['slug'] ) ) {
            return $this->snippet_v2_error( 'invalid_request', $ref );
        }

        $type = $request['type'];
        if ( ! is_string( $type ) || ! in_array( $type, array( 'R', 'S', 'C' ), true ) ) {
            return $this->snippet_v2_error( 'invalid_request', $ref );
        }

        if ( 'run_snippet_v2' === $action ) {
            if ( 'R' !== $type || ! $this->snippet_v2_valid_code( $request['code'] ) ) {
                return $this->snippet_v2_error( 'invalid_request', $ref );
            }
            return $this->snippet_v2_run( $request );
        }

        if ( 'R' === $type || ( 'apply_snippet_v2' === $action && ! $this->snippet_v2_valid_code( $request['code'] ) ) ) {
            return $this->snippet_v2_error( 'invalid_request', $ref );
        }

        $owner = $this->snippet_v2_acquire_lock( $request['slug'] );
        if ( false === $owner ) {
            return $this->snippet_v2_error( 'lock_busy', $ref );
        }

        try {
            $result = 'apply_snippet_v2' === $action
                ? $this->snippet_v2_apply( $request['slug'], $type, $request['code'] )
                : $this->snippet_v2_remove( $request['slug'], $type );
        } finally {
            $released = $this->snippet_v2_release_lock( $request['slug'], $owner );
        }

        // A release that did not take says nothing about whether the write landed, so it rides along
        // as an advisory instead of overwriting a committed outcome with a fabricated failure.
        $warnings = $released ? array() : array( 'lock_release_failed' );
        if ( ! is_array( $result ) ) {
            return $this->snippet_v2_error( 'storage_failed', $ref, $warnings );
        }
        if ( isset( $result['error_code'] ) ) {
            return $this->snippet_v2_error( $result['error_code'], $ref, $warnings );
        }

        return $this->snippet_v2_envelope( true, $ref, null, $result, $warnings );
    }

    /**
     * Build the one response envelope every Code Snippets v2 reply uses.
     *
     * `success` reports whether the Child carried the request through to an outcome it can vouch
     * for, which is what the Dashboard branches on; it is not the snippet's own verdict. A run that
     * executed and threw is a reported outcome, so it stays `true` and `error_code` classifies it.
     *
     * @param bool        $success     Whether an outcome was produced.
     * @param string|null $request_ref Correlation reference, null when the request carried none.
     * @param string|null $error_code  Stable failure class, null when there was none.
     * @param array       $fields      Operation-specific fields.
     * @param array       $warnings    Advisory codes that do not change the outcome.
     * @return array<string,mixed>
     */
    private function snippet_v2_envelope( $success, $request_ref, $error_code, $fields = array(), $warnings = array() ) {
        return array_merge(
            array(
                'success'       => $success,
                'request_ref'   => is_string( $request_ref ) ? $request_ref : null,
                'error_code'    => $error_code,
                'warning_codes' => array_values( array_unique( $warnings ) ),
            ),
            $fields
        );
    }

    /**
     * Build the failure response for a Code Snippets v2 request.
     *
     * @param string      $code        Error code, replaced with storage_failed when unrecognised.
     * @param string|null $request_ref Correlation reference, echoed so a failure can be matched to its request.
     * @param array       $warnings    Advisory codes that do not change the outcome.
     * @return array<string,mixed>
     */
    private function snippet_v2_error( $code, $request_ref = null, $warnings = array() ) {
        $allowed = array( 'invalid_request', 'lock_busy', 'execution_failed', 'storage_failed' );
        return $this->snippet_v2_envelope( false, $request_ref, in_array( $code, $allowed, true ) ? $code : 'storage_failed', array(), $warnings );
    }

    /**
     * Whether a request carries exactly the expected keys, in any order.
     *
     * @param array $value Request to check.
     * @param array $keys  Expected keys.
     * @return bool
     */
    private function snippet_v2_exact_keys( $value, $keys ) {
        $actual = array_keys( $value );
        sort( $actual, SORT_STRING );
        sort( $keys, SORT_STRING );

        return $actual === $keys;
    }

    /**
     * Whether a value is a well-formed request reference UUID.
     *
     * @param mixed $value Value to check.
     * @return bool
     */
    private function snippet_v2_valid_request_ref( $value ) {
        return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $value );
    }

    /**
     * Whether a value is an accepted snippet slug.
     *
     * Protocol v1 put whatever the Dashboard sent straight into the option key and the wp-config
     * marker with no character restriction, so hyphens and underscores are in the field already;
     * refusing them here would leave those snippets installed with no way to remove them. The set
     * stops at alphanumerics plus those two: nothing here can read as a path segment, and none of it
     * can alter the meaning of the `/***snippet_<slug>***\/` marker or of the pattern built from it.
     *
     * @param mixed $value Value to check.
     * @return bool
     */
    private function snippet_v2_valid_slug( $value ) {
        return is_string( $value ) && 1 === preg_match( '/^[A-Za-z0-9_-]{1,32}$/D', $value );
    }

    /**
     * Whether a value is snippet code within the accepted size and encoding.
     *
     * @param mixed $value Value to check.
     * @return bool
     */
    private function snippet_v2_valid_code( $value ) {
        return is_string( $value ) && '' !== $value && 60000 >= strlen( $value ) && wp_check_invalid_utf8( $value ) === $value;
    }

    /**
     * Current Unix timestamp, overridable in tests.
     *
     * @return int
     */
    protected function snippet_v2_now() {
        return time();
    }

    /**
     * Read an option, overridable in tests.
     *
     * @param string $name     Option name.
     * @param mixed  $fallback Value returned when the option is absent.
     * @return mixed
     */
    protected function snippet_v2_get_option( $name, $fallback = false ) {
        return get_option( $name, $fallback );
    }

    /**
     * Write an option, treating an unchanged stored value as success.
     *
     * @param string $name  Option name.
     * @param mixed  $value Value to store.
     * @return bool
     */
    protected function snippet_v2_update_option( $name, $value ) {
        if ( update_option( $name, $value ) ) {
            return true;
        }
        // update_option() reports false for a write that changed nothing, so the fallback decides
        // whether the option already holds what was asked for. It has to compare stored forms, not
        // values: the column is text, so a stored true reads back as '1' and an identity test would
        // call an already-enabled flag a storage failure and roll the whole apply back.
        $stored = get_option( $name, null );
        return null !== $stored && (string) maybe_serialize( $stored ) === (string) maybe_serialize( $value );
    }

    /**
     * Delete an option, treating an already absent option as success.
     *
     * @param string $name Option name.
     * @return bool
     */
    protected function snippet_v2_delete_option( $name ) {
        return delete_option( $name ) || false === get_option( $name, false );
    }

    /**
     * Take the per-slug write lock, clearing an expired one first.
     *
     * @param string $slug Snippet slug.
     * @return string|false Lock owner token, or false when the lock is held.
     */
    protected function snippet_v2_acquire_lock( $slug ) {
        $name  = 'mainwp_cs_v2_lock_' . hash( 'sha256', $slug );
        $now   = $this->snippet_v2_now();
        $owner = wp_generate_uuid4();
        $lock  = $this->snippet_v2_get_option( $name, false );
        if ( is_array( $lock ) && isset( $lock['expires'] ) && is_int( $lock['expires'] ) && $lock['expires'] < $now ) {
            $this->snippet_v2_delete_option( $name );
        }
        if ( ! add_option(
            $name,
            array(
                'owner'   => $owner,
                'expires' => $now + 120,
            ),
            '',
            false
        ) ) {
            return false;
        }
        return $owner;
    }

    /**
     * Release the per-slug write lock held by this owner token.
     *
     * @param string $slug  Snippet slug.
     * @param string $owner Lock owner token.
     * @return bool
     */
    protected function snippet_v2_release_lock( $slug, $owner ) {
        $name = 'mainwp_cs_v2_lock_' . hash( 'sha256', $slug );
        $lock = $this->snippet_v2_get_option( $name, false );
        return is_array( $lock ) && isset( $lock['owner'] ) && is_string( $lock['owner'] ) && hash_equals( $lock['owner'], $owner ) && $this->snippet_v2_delete_option( $name );
    }

    /**
     * Run snippet code once and report the execution outcome.
     *
     * @param array $request Validated request.
     * @return array<string,mixed>
     */
    private function snippet_v2_run( $request ) {
        $execution = $this->snippet_v2_execute_code( $request['code'] );
        if ( ! is_array( $execution ) || ! isset( $execution['status'], $execution['output'], $execution['output_truncated'] ) ) {
            return $this->snippet_v2_error( 'execution_failed', $request['request_ref'] );
        }
        return $this->snippet_v2_envelope(
            true,
            $request['request_ref'],
            'succeeded' === $execution['status'] ? null : 'execution_failed',
            array(
                'status'           => $execution['status'],
                'output'           => $execution['output'],
                'output_truncated' => $execution['output_truncated'],
            )
        );
    }

    /**
     * Evaluate snippet code with buffered, truncated output capture.
     *
     * @param string $code Snippet code.
     * @return array<string,mixed>
     */
    protected function snippet_v2_execute_code( $code ) {
        $level = ob_get_level();
        ob_start();
        try {
            eval( $code ); // phpcs:ignore Squiz.PHP.Eval, Generic.PHP.ForbiddenFunctions.Found -- Executes an already authorized stored Code Snippets definition under the authenticated Child callable.
            $status = 'succeeded';
        } catch ( \Throwable $exception ) {
            $status = 'failed';
        }
        // A snippet may leave buffers of its own open, so unwinding to the entry level is what stops
        // its text escaping past the encoded response later in the request. The outermost level holds
        // the earliest text, so each one read on the way out belongs in front of what came before.
        $produced = '';
        while ( ob_get_level() > $level ) {
            $produced = (string) ob_get_clean() . $produced;
        }
        // Buffered output from a run that threw can carry internals, so none of it ships. It is still
        // recorded above: output_truncated is the only field that can say the Dashboard is not getting
        // what the run printed, and a discard is exactly that.
        $output = 'succeeded' === $status ? $produced : '';
        // JSON cannot carry invalid UTF-8 at all, and without stripping wp_check_invalid_utf8()
        // answers one bad byte by discarding the whole string, so a run would report empty output
        // next to 'succeeded'. Returning the valid text is the truthful half of that choice.
        // Stripping runs before the cap because it can change the byte length, and the cap has to
        // describe what actually ships.
        $output = wp_check_invalid_utf8( $output, true );
        // output_truncated is the only field saying the shipped text is not what the run produced,
        // so it covers output a failed run discarded, bytes the strip removed, and bytes the cap
        // cut. It does not mean "cut at the end"; a scrubbed run can lose bytes from the middle and
        // stay under the cap.
        $truncated = $output !== $produced;
        if ( 65535 < strlen( $output ) ) {
            $output    = $this->snippet_v2_cut_utf8( $output, 65535 );
            $truncated = true;
        }
        return array(
            'status'           => $status,
            'output'           => $output,
            'output_truncated' => $truncated,
        );
    }

    /**
     * Cut UTF-8 text to a byte budget without splitting the character on the boundary.
     *
     * A raw byte cut leaves a partial multi-byte sequence, which every downstream UTF-8 check then
     * reads as a corrupt string.
     *
     * @param string $value Valid UTF-8 text.
     * @param int    $limit Byte budget.
     * @return string
     */
    private function snippet_v2_cut_utf8( $value, $limit ) {
        if ( function_exists( 'mb_strcut' ) ) {
            return mb_strcut( $value, 0, $limit, 'UTF-8' );
        }

        $cut  = substr( $value, 0, $limit );
        $last = strlen( $cut ) - 1;
        // Continuation bytes are 10xxxxxx; walking back over them lands on the lead byte of the
        // character the cut ended inside.
        while ( 0 <= $last && 0x80 === ( ord( $cut[ $last ] ) & 0xC0 ) ) {
            --$last;
        }
        if ( 0 > $last ) {
            return $cut;
        }
        $lead     = ord( $cut[ $last ] );
        $expected = 0xF0 <= $lead ? 4 : ( 0xE0 <= $lead ? 3 : ( 0xC0 <= $lead ? 2 : 1 ) );

        return strlen( $cut ) - $last < $expected ? substr( $cut, 0, $last ) : $cut;
    }

    /**
     * Install snippet code into option storage or wp-config.php.
     *
     * @param string $slug Snippet slug.
     * @param string $type Snippet type, S for option storage.
     * @param string $code Snippet code.
     * @return array<string,mixed>
     */
    private function snippet_v2_apply( $slug, $type, $code ) {
        if ( 'S' === $type ) {
            $before  = $this->snippet_v2_get_option( 'mainwp_ext_code_snippets', array() );
            $enabled = $this->snippet_v2_get_option( 'mainwp_ext_snippets_enabled', false );
            if ( ! is_array( $before ) ) {
                return array( 'error_code' => 'storage_failed' );
            }
            if ( isset( $before[ $slug ] ) && $code === $before[ $slug ] && true === (bool) $enabled ) {
                return array(
                    'result'          => 'unchanged',
                    'installed_state' => 'confirmed',
                );
            }
            $after          = $before;
            $after[ $slug ] = $code;
            if ( ! $this->snippet_v2_update_option( 'mainwp_ext_code_snippets', $after ) || ! $this->snippet_v2_update_option( 'mainwp_ext_snippets_enabled', true ) || $after !== $this->snippet_v2_get_option( 'mainwp_ext_code_snippets', null ) || true !== (bool) $this->snippet_v2_get_option( 'mainwp_ext_snippets_enabled', false ) ) {
                $this->snippet_v2_update_option( 'mainwp_ext_code_snippets', $before );
                $this->snippet_v2_update_option( 'mainwp_ext_snippets_enabled', $enabled );
                return array( 'error_code' => 'storage_failed' );
            }
            return array(
                'result'          => 'changed',
                'installed_state' => 'confirmed',
            );
        }
        return $this->snippet_v2_config_change( 'apply', $slug, $code );
    }

    /**
     * Remove a snippet from option storage or wp-config.php.
     *
     * @param string $slug Snippet slug.
     * @param string $type Snippet type, S for option storage.
     * @return array<string,mixed>
     */
    private function snippet_v2_remove( $slug, $type ) {
        if ( 'S' === $type ) {
            $before = $this->snippet_v2_get_option( 'mainwp_ext_code_snippets', array() );
            if ( ! is_array( $before ) ) {
                return array( 'error_code' => 'storage_failed' );
            }
            if ( ! array_key_exists( $slug, $before ) ) {
                return array(
                    'result'          => 'already_absent',
                    'installed_state' => 'absent',
                );
            }
            $after = $before;
            unset( $after[ $slug ] );
            if ( ! $this->snippet_v2_update_option( 'mainwp_ext_code_snippets', $after ) || $after !== $this->snippet_v2_get_option( 'mainwp_ext_code_snippets', null ) ) {
                $this->snippet_v2_update_option( 'mainwp_ext_code_snippets', $before );
                return array( 'error_code' => 'storage_failed' );
            }
            return array(
                'result'          => 'removed',
                'installed_state' => 'absent',
            );
        }
        return $this->snippet_v2_config_change( 'remove', $slug, '' );
    }

    /**
     * Locate the wp-config.php this install actually loads.
     *
     * @return string|false
     */
    protected function snippet_v2_config_path() {
        if ( file_exists( ABSPATH . 'wp-config.php' ) ) {
            return ABSPATH . 'wp-config.php';
        }
        $parent = dirname( ABSPATH ) . '/wp-config.php';
        return file_exists( $parent ) && ! file_exists( dirname( ABSPATH ) . '/wp-settings.php' ) ? $parent : false;
    }

    /**
     * Rewrite the slug's delimited block in wp-config.php.
     *
     * @param string $operation Either apply or remove.
     * @param string $slug      Snippet slug.
     * @param string $code      Snippet code, empty when removing.
     * @return array<string,mixed>
     */
    private function snippet_v2_config_change( $operation, $slug, $code ) {
        $path = $this->snippet_v2_config_path();
        if ( false === $path ) {
            return array( 'error_code' => 'storage_failed' );
        }
        $original = $this->snippet_v2_read_file( $path );
        if ( ! is_string( $original ) ) {
            return array( 'error_code' => 'storage_failed' );
        }
        $start = '/***snippet_' . $slug . '***/';
        $end   = '/***end_' . $slug . '***/';
        if ( substr_count( $original, $start ) !== substr_count( $original, $end ) || substr_count( $original, $start ) > 1 ) {
            return array( 'error_code' => 'storage_failed' );
        }
        $next = $original;
        if ( 1 === substr_count( $next, $start ) ) {
            $pattern = '/(?:\r?\n){0,2}' . preg_quote( $start, '/' ) . '.*?' . preg_quote( $end, '/' ) . '(?:\r?\n){0,2}/s';
            $next    = preg_replace( $pattern, "\n", $next, 1, $removed );
            if ( ! is_string( $next ) || 1 !== $removed ) {
                return array( 'error_code' => 'storage_failed' );
            }
        } elseif ( 'remove' === $operation ) {
            return array(
                'result'          => 'already_absent',
                'installed_state' => 'absent',
            );
        }
        if ( 'apply' === $operation ) {
            $block = $start . "\n" . $code . "\n" . $end;
            $next  = preg_replace_callback(
                '/(\$table_prefix\s*=\s*[\'\"][^\'\"]*[\'\"]\s*;)/i',
                static function ( $matches ) use ( $block ) {
                    return $matches[1] . "\n\n" . $block;
                },
                $next,
                1,
                $inserted
            );
            if ( ! is_string( $next ) || 1 !== $inserted ) {
                return array( 'error_code' => 'storage_failed' );
            }
        }
        if ( $original === $next ) {
            return array(
                'result'          => 'unchanged',
                'installed_state' => 'confirmed',
            );
        }
        if ( ! $this->snippet_v2_write_file_atomic( $path, $original, $next ) ) {
            return array( 'error_code' => 'storage_failed' );
        }
        return array(
            'result'          => 'apply' === $operation ? 'changed' : 'removed',
            'installed_state' => 'apply' === $operation ? 'confirmed' : 'absent',
        );
    }

    // phpcs:disable WordPress.WP.AlternativeFunctions -- wp-config.php snippet writes need flock, a same-filesystem rename, and byte-exact readback; WP_Filesystem offers none of these.

    /**
     * Read a file whole, overridable in tests.
     *
     * @param string $path File path.
     * @return string|false
     */
    protected function snippet_v2_read_file( $path ) {
        return file_get_contents( $path );
    }

    /**
     * Replace a file's contents only while it still matches the expected bytes.
     *
     * @param string $path     File path.
     * @param string $expected Contents the caller read before editing.
     * @param string $next     Contents to write.
     * @return bool
     */
    protected function snippet_v2_write_file_atomic( $path, $expected, $next ) {
        // flock() binds to an inode and the rename below hands $path a brand new one, so a lock taken
        // on the target itself stops excluding anybody at the exact moment it matters: the next
        // writer opens the orphaned inode, still reads the pre-edit bytes there, and its own
        // compare-and-swap waves through a write that silently drops this one. The lock therefore
        // lives in a file beside the target that no rename ever touches, and it is never deleted,
        // because handing each writer a fresh inode would put the same hole straight back.
        //
        // Ordering: the per-slug option lock from snippet_v2_acquire_lock() is always taken first and
        // this one second, never the other way round. Both are needed - the option lock is per slug
        // and cannot serialize two different slugs editing this one shared file.
        $lock = fopen( $this->snippet_v2_config_lock_path( $path ), 'c' );
        if ( false === $lock || ! flock( $lock, LOCK_EX ) ) {
            false !== $lock && fclose( $lock );
            return false;
        }
        $ok = $this->snippet_v2_replace_config( $path, $expected, $next );
        flock( $lock, LOCK_UN );
        fclose( $lock );
        return $ok;
    }

    /**
     * Path of the lock file guarding writes to a configuration file.
     *
     * The .php suffix keeps an empty, world-readable file in the web root from being served as text.
     *
     * @param string $path Configuration file path.
     * @return string
     */
    protected function snippet_v2_config_lock_path( $path ) {
        return dirname( $path ) . '/.mainwp-snippets-config-lock.php';
    }

    /**
     * Swap a configuration file's contents while it still holds the expected bytes.
     *
     * The caller holds the configuration lock. The live bytes are re-read here rather than taken on
     * trust from the read that produced $next, so an edit that landed in between is caught instead
     * of overwritten.
     *
     * @param string $path     File path.
     * @param string $expected Contents the caller read before editing.
     * @param string $next     Contents to write.
     * @return bool
     */
    private function snippet_v2_replace_config( $path, $expected, $next ) {
        // A configuration file that disappeared under us is a failure to report, not a file to
        // conjure up; the handle this used to open would have created an empty one.
        if ( ! is_file( $path ) ) {
            return false;
        }
        $current = file_get_contents( $path );
        $mode    = fileperms( $path );
        if ( $expected !== $current || false === $mode ) {
            return false;
        }
        $temporary = $this->snippet_v2_stage_file( dirname( $path ), '.mainwp-cs-', $next, $mode & 0777 );
        $renamed   = false !== $temporary && rename( $temporary, $path );
        $ok        = $renamed && file_get_contents( $path ) === $next;
        if ( ! $ok ) {
            if ( false !== $temporary && file_exists( $temporary ) ) {
                unlink( $temporary );
            }
            // Only a rename that already replaced the live file needs the original bytes put back; a
            // rename that failed left wp-config.php untouched, and writing over it there would risk
            // replacing an intact configuration with a partial copy.
            $rollback = $renamed ? $this->snippet_v2_stage_file( dirname( $path ), '.mainwp-cs-rollback-', $expected, $mode & 0777 ) : false;
            if ( false !== $rollback && ! rename( $rollback, $path ) && file_exists( $rollback ) ) {
                unlink( $rollback );
            }
        }
        return $ok && file_get_contents( $path ) === $next;
    }

    /**
     * Write wp-config.php bytes to a staging file beside the target, ready to be renamed over it.
     *
     * Staying in the target directory keeps the rename on one filesystem, so it stays atomic. The
     * staged copy carries a .php suffix because it holds the whole configuration: database
     * credentials and salts. An extensionless file left in the web root is served verbatim by
     * common server configurations, while a .php one is executed and discloses nothing.
     *
     * @param string $directory   Directory holding the configuration file.
     * @param string $prefix      Staging name prefix.
     * @param string $contents    Bytes to stage.
     * @param int    $permissions Permissions to apply to the staged file.
     * @return string|false Staged path, or false when the bytes did not reach disk complete.
     */
    protected function snippet_v2_stage_file( $directory, $prefix, $contents, $permissions ) {
        // tempnam() silently falls back to the system temporary directory when it cannot write to
        // $directory, which would put the configuration outside the site and break atomicity.
        if ( ! is_writable( $directory ) ) {
            return false;
        }
        $temporary = tempnam( $directory, $prefix );
        if ( ! is_string( $temporary ) ) {
            return false;
        }
        $staged = $temporary . '.php';
        if ( realpath( dirname( $temporary ) ) !== realpath( $directory ) || file_exists( $staged ) || ! rename( $temporary, $staged ) ) {
            unlink( $temporary );
            return false;
        }
        $written = file_put_contents( $staged, $contents );
        // A short write (a full disk) must never reach the live configuration, so the byte count is
        // compared exactly rather than trusting the non-false return.
        if ( strlen( $contents ) !== $written || ! chmod( $staged, $permissions ) ) {
            unlink( $staged );
            return false;
        }
        return $staged;
    }

    // phpcs:enable WordPress.WP.AlternativeFunctions

    /**
     * Method snippet_save_snippet()
     *
     * Save code snippet.
     *
     * @param string $slug Snippet slug.
     * @param string $type Type of snippet.
     * @param string $code Snippet code.
     * @param array  $snippets An array containing all snippets.
     *
     * @return array $return Status response.
     *
     * @uses \MainWP\Child\MainWP_Helper::update_option() Update option by name.
     * @uses \MainWP\Child\MainWP_Child_Misc::snippet_update_wp_config() Update the child site wp-config.php file.
     *
     * @used-by \MainWP\Child\MainWP_Child_Misc::code_snippet() Initiate Code Snippet actions run_snippet, save_snippet and delete_snippet.
     */
    private function snippet_save_snippet( $slug, $type, $code, $snippets ) {
        $return = array();
        if ( 'C' === $type ) { // save into wp-config file.
            if ( false !== $this->snippet_update_wp_config( 'save', $slug, $code ) ) {
                $return['status'] = 'SUCCESS';
            }
        } else {
            $snippets[ $slug ] = $code;
            if ( MainWP_Helper::update_option( 'mainwp_ext_code_snippets', $snippets ) ) {
                $return['status'] = 'SUCCESS';
            }
        }
        MainWP_Helper::update_option( 'mainwp_ext_snippets_enabled', true, 'yes' );

        return $return;
    }

    /**
     * Method snippet_delete_snippet()
     *
     * Delete code snippet.
     *
     * @param string $slug Snippet slug.
     * @param string $type Type of snippet.
     * @param array  $snippets An array containing all snippets.
     *
     * @return array $return Status response.
     *
     * @uses \MainWP\Child\MainWP_Helper::update_option() Update option by name.
     * @uses \MainWP\Child\MainWP_Child_Misc::snippet_update_wp_config() Update the child site wp-config.php file.
     *
     * @used-by \MainWP\Child\MainWP_Child_Misc::code_snippet() Initiate Code Snippet actions run_snippet, save_snippet and delete_snippet.
     */
    private function snippet_delete_snippet( $slug, $type, $snippets ) {
        $return = array();
        if ( 'C' === $type ) { // delete in wp-config file.
            if ( false !== $this->snippet_update_wp_config( 'delete', $slug ) ) {
                $return['status'] = 'SUCCESS';
            }
        } elseif ( isset( $snippets[ $slug ] ) ) {
                unset( $snippets[ $slug ] );
            if ( MainWP_Helper::update_option( 'mainwp_ext_code_snippets', $snippets ) ) {
                $return['status'] = 'SUCCESS';
            }
        } else {
            $return['status']   = 'SUCCESS';
            $return['notfound'] = 1;
        }
        return $return;
    }

    /**
     * Method snippet_update_wp_config()
     *
     * Update the child site wp-config.php file.
     *
     * @param string $action Action to perform: Delete, Save.
     * @param string $slug   Snippet slug.
     * @param string $code   Code snippet.
     *
     * @used-by MainWP_Child_Misc::snippet_save_snippet() Save code snippet.
     * @used-by MainWP_Child_Misc::snippet_delete_snippet() Delete code snippet.
     *
     * @return bool If remvoed, return true, if not, return false.
     */
    public function snippet_update_wp_config( $action, $slug, $code = '' ) {

        $config_file = '';
        if ( file_exists( ABSPATH . 'wp-config.php' ) ) {
            // The config file resides in ABSPATH.
            $config_file = ABSPATH . 'wp-config.php';
        } elseif ( file_exists( dirname( ABSPATH ) . '/wp-config.php' ) && ! file_exists( dirname( ABSPATH ) . '/wp-settings.php' ) ) {
            // The config file resides one level above ABSPATH but is not part of another install.
            $config_file = dirname( ABSPATH ) . '/wp-config.php';
        }

        if ( ! empty( $config_file ) ) {
            $wpConfig = file_get_contents( $config_file ); //phpcs:ignore WordPress.WP.AlternativeFunctions

            if ( 'delete' === $action ) {
                $wpConfig = preg_replace( '/' . PHP_EOL . '{1,2}\/\*\*\*snippet_' . $slug . '\*\*\*\/(.*)\/\*\*\*end_' . $slug . '\*\*\*\/' . PHP_EOL . '/is', '', $wpConfig ); // NOSONAR .
            } elseif ( 'save' === $action ) {
                $wpConfig = preg_replace( '/(\$table_prefix *= *[\'"][^\'|^"]*[\'"] *;)/is', '${1}' . PHP_EOL . PHP_EOL . '/***snippet_' . $slug . '***/' . PHP_EOL . $code . PHP_EOL . '/***end_' . $slug . '***/' . PHP_EOL, $wpConfig ); // NOSONAR .
            }
            MainWP_Helper::file_put_contents( $config_file, $wpConfig ); //phpcs:ignore WordPress.WP.AlternativeFunctions
            return true;
        }
        return false;
    }
}
