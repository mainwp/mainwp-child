<?php
/**
 * MainWP Child Misc functions
 *
 * This file is for misc functions that don't really belong anywhere else.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

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
        $security    = get_option( 'mainwp_security' );
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

        if ( ( 'all' === $feature || 'versions' === $feature ) && ! in_array( 'versions', $skips ) ) {
            $security['scripts_version']   = true;
            $security['styles_version']    = true;
            $security['generator_version'] = true;
            $information['versions']       = 'Y';
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

        $security = get_option( 'mainwp_security' );

        if ( 'all' === $feature || 'php_reporting' === $feature ) {
            $security['php_reporting']    = false;
            $information['php_reporting'] = 'N';
        }

        if ( 'all' === $feature || 'db_reporting' === $feature ) {
            $security['db_reporting']    = false;
            $information['db_reporting'] = 'N';
        }

        if ( 'all' === $feature || 'versions' === $feature ) {
            $security['scripts_version']   = false;
            $security['styles_version']    = false;
            $security['generator_version'] = false;
            $information['versions']       = 'N';
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

        if ( ! file_exists( $dir ) && false === mkdir( $dir, 0777, true ) ) { //phpcs:ignore WordPress.WP.AlternativeFunctions
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

        if ( ! seems_utf8( $filename ) ) {
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
        $type   = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
        $slug   = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';

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
