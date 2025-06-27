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
        if ( ! class_exists( '\WPSEO_Admin' ) ) {
            $information['error'] = 'NO_WPSEO';
            MainWP_Helper::write( $information );
        }
        $information = array();
        $mwp_action  = MainWP_System::instance()->validate_params( 'action' );
        if ( 'import_settings' === $mwp_action ) {
            $this->import_settings();
        }
        MainWP_Helper::write( $information );
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
