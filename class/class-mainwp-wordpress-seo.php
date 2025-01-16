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
     * Public variable to hold the information if the WP Seo plugin is installed on the child site.
     *
     * @var bool If WP Seo installed, return true, if not, return false.
     */
    public $is_plugin_installed = false;

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

        if ( is_plugin_active( 'wordpress-seo/wp-seo.php' ) ) {
            $this->is_plugin_installed = true;
        }

        /**
         * Object, providing access to the WordPress database.
         *
         * @global object $wpdb WordPress Database instance.
         */
        global $wpdb;
        $this->import_error = __( 'Settings could not be imported.', 'mainwp-child' );
        $this->init();
    }

    /**
     * Method init()
     *
     * Initiate action hooks.
     *
     * @return void
     */
    public function init() {
        if ( ! $this->is_plugin_installed ) {
            return;
        }

        if ( 'hide' === get_option( 'mainwp_wpseo_hide_plugin' ) ) {
            add_filter( 'all_plugins', array( $this, 'all_plugins' ) );
            add_action( 'admin_menu', array( $this, 'remove_menu' ) );
            add_action( 'wp_before_admin_bar_render', array( $this, 'wp_before_admin_bar_render' ), 99 );
            add_action( 'add_meta_boxes', array( $this, 'remove_metaboxes' ), 20 );
            add_action( 'wp_dashboard_setup', array( $this, 'remove_dashboard_widgets' ), 20 );
            add_filter( 'site_transient_update_plugins', array( &$this, 'remove_update_nag' ) );
            add_filter( 'mainwp_child_hide_update_notice', array( &$this, 'hide_update_notice' ) );
            add_action( 'admin_init', array( $this, 'remove_notices' ) );
        }
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
        $mwp_action  = MainWP_System::instance()->validate_params( 'mwp_action' );
        if ( ! empty( $mwp_action ) ) {
            try {
                switch ( $mwp_action ) {
                    case 'import_settings':
                        $information = $this->import_settings();
                        break;
                    case 'save_settings':
                        $information = $this->save_settings();
                        break;
                    case 'set_showhide':
                        $information = $this->set_showhide();
                        break;
                    default:
                        break;
                }
            } catch ( MainWP_Exception $e ) {
                $information = array( 'error' => $e->getMessage() );
            }
        }
        MainWP_Helper::write( $information );
    }

    /**
     * Method save_settings()
     *
     * Save the plugin settings.
     *
     * @uses \WPSEO_Options::get()
     * @uses \WPSEO_Options::set()
     * @uses \WPSEO_Options::get_options()
     * @uses \WPSEO_Utils::clear_cache()
     * @uses \WPSEO_Sitemaps_Cache::clear()
     * @uses \WPSEO_Options::ensure_options_exist()
     *
     * @return array Action result.
     */
    public function save_settings() {  // phpcs:ignore -- NOSONAR 
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $options = isset( $_POST['settings'] ) ? json_decode( base64_decode( wp_unslash( $_POST['settings'] ) ), true ) : '';  //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible.
        if ( ! is_array( $options ) || empty( $options ) ) {
            return array( 'error' => 'INVALID_OPTIONS' );
        }

        // Save blogdescription.
        if ( isset( $options['wpseo_titles']['blogdescription'] ) ) {
            update_option( 'blogdescription', $options['wpseo_titles']['blogdescription'] );
            unset( $options['wpseo_titles']['blogdescription'] );
        }

        // List of keys that require special handling.
        $special_keys = array(
            'other_social_urls',
            'custom_taxonomy_slugs',
            'import_cursors',
            'workouts_data',
            'importing_completed',
            'wincher_tokens',
            'least_readability_ignore_list',
            'least_seo_score_ignore_list',
            'most_linked_ignore_list',
            'least_linked_ignore_list',
            'indexables_page_reading_list',
            'last_known_public_post_types',
            'last_known_public_taxonomies',
            'last_known_no_unindexed',
            'new_post_types',
            'new_taxonomies',
        );

        // List of keys that bBoolean fields.
        $custom_value_lists = array(
            'noindex-post',
            'noindex-page',
            'noindex-tax-category',
            'stripcategorybase',
            'noindex-tax-post_tag',
            'noindex-author-noposts-wpseo',
            'noindex-author-wpseo',
            'disable-author',
            'disable-date',
            'noindex-archive-wpseo',
            'noindex-tax-post_format',
            'disable-post_format',
            'disable-attachment',
            'noindex-attachment',
        );

        // Save wpSeo config value.
        foreach ( $options as $k_option => $option ) {
            $option_values = \WPSEO_Options::get_options( array( $k_option ) );
            if ( empty( $option_values ) ) {
                continue; // Ignore if not valid.
            }

            foreach ( $option as $k_item => $item ) {
                // Add new value true or false for list of index.
                if ( in_array( $k_item, $custom_value_lists, true ) ) {
                    $item = '' === $item ? true : false;
                }

                if ( ! isset( $option_values[ $k_item ] ) ) {
                    continue; // Ignore if this key is not present in the option value.
                }

                if ( in_array( $k_item, $special_keys, true ) ) {
                    $item = ! empty( $item ) ? array_values( array_unique( array_filter( $item ) ) ) : array();
                }

                switch ( $k_item ) {
                    case 'og_default_image':
                    case 'company_logo':
                    case 'person_logo':
                    case 'open_graph_frontpage_image':
                    case 'og_frontpage_image':
                        $image     = $this->wpseo_upload_image( $item );
                        $image_url = ! empty( $image ) ? $image['url'] : '';
                        $image_id  = ! empty( $image ) ? $image['id'] : '';
                        \WPSEO_Options::set( $k_item, $image_url, $k_option );
                        \WPSEO_Options::set( $k_item . '_id', $image_id, $k_option );
                        break;
                    case 'breadcrumbs-404crumb':
                    case 'breadcrumbs-archiveprefix':
                    case 'breadcrumbs-home':
                    case 'breadcrumbs-prefix':
                    case 'breadcrumbs-searchprefix':
                    case 'breadcrumbs-sep':
                        \WPSEO_Options::set( $k_item, html_entity_decode( $item ), $k_option );
                        break;
                    default:
                        \WPSEO_Options::set( $k_item, $item, $k_option );
                        break;
                }
            }
        }

        \WPSEO_Utils::clear_cache(); // Clear cache so the changes are obvious.
        \WPSEO_Sitemaps_Cache::clear(); // Flush the sitemap cache.
        \WPSEO_Options::ensure_options_exist(); // Make sure all our options always exist - issue #1245.

        return array( 'result' => 'SUCCESS' );
        // phpcs:enable WordPress.Security.NonceVerification.Missing
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
                    $information['error'] = 'INVALID_OPTIONS';
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

    /**
     * Method wpseo_upload_image()
     *
     * Upload custom image from MainWP Dashboard.
     *
     * @param string $img_url Contains image URL.
     *
     * @uses \MainWP\Child\MainWP_Helper::get_class_name()
     * @uses \MainWP_Utility::instance()->check_image_file_name()
     * @uses \MainWP_Helper::move()
     *
     * @users-by MainWP_WordPress_SEO::save_settings() - download file and save to option.
     *
     * @return array An array containing the image information such as path and URL.
     * @throws MainWP_Exception Error message.
     */
    public function wpseo_upload_image( $img_url ) {
        if ( empty( $img_url ) ) {
            return null;
        }
        include_once ABSPATH . 'wp-admin/includes/file.php'; // NOSONAR -- WP compatible.
        include_once ABSPATH . 'wp-admin/includes/image.php'; // NOSONAR -- To process metadata for images.

        // Check if the image from the URL has been downloaded.
        $attachment_id = $this->get_attachment_id_by_source_url( $img_url );
        if ( $attachment_id ) {
            // If exists, returns file information.
            return array(
                'id'       => $attachment_id,
                'path'     => get_attached_file( $attachment_id ),
                'url'      => wp_get_attachment_url( $attachment_id ),
                'metadata' => wp_get_attachment_metadata( $attachment_id ),
            );
        }

        add_filter( 'http_request_args', array( MainWP_Helper::get_class_name(), 'reject_unsafe_urls' ), 99, 2 );
        $temporary_file = download_url( $img_url );
        remove_filter( 'http_request_args', array( MainWP_Helper::get_class_name(), 'reject_unsafe_urls' ), 99, 2 );

        if ( is_wp_error( $temporary_file ) ) {
            throw new MainWP_Exception( esc_html( $temporary_file->get_error_message() ) );
        } else {
            $upload_dir     = wp_upload_dir();
            $local_img_path = $upload_dir['path'] . DIRECTORY_SEPARATOR . basename( $img_url );
            $local_img_path = dirname( $local_img_path ) . '/' . wp_unique_filename( dirname( $local_img_path ), basename( $local_img_path ) );
            $local_img_url  = $upload_dir['url'] . '/' . basename( $local_img_path );

            if ( MainWP_Utility::instance()->check_image_file_name( $local_img_path ) ) {
                $moved = MainWP_Helper::move( $temporary_file, $local_img_path );
                if ( $moved ) {
                    // Create attachment posts in the database.
                    $attachment = array(
                        'guid'           => $local_img_url,
                        'post_mime_type' => mime_content_type( $local_img_path ),
                        'post_title'     => preg_replace( '/\.[^.]+$/', '', basename( $local_img_path ) ),
                        'post_content'   => '',
                        'post_status'    => 'inherit',
                        'meta_input'     => array(
                            '_source_url' => $img_url, // Save the original URL as metadata.
                        ),
                    );

                    // Attach attachment to the database.
                    $attachment_id = wp_insert_attachment( $attachment, $local_img_path );

                    // Create metadata for attachments.
                    if ( ! is_wp_error( $attachment_id ) ) {
                        $attachment_metadata = wp_generate_attachment_metadata( $attachment_id, $local_img_path );
                        wp_update_attachment_metadata( $attachment_id, $attachment_metadata );

                        return array(
                            'id'       => $attachment_id,
                            'path'     => $local_img_path,
                            'url'      => $local_img_url,
                            'metadata' => $attachment_metadata,
                        );
                    }
                }
            }
        }
        if ( file_exists( $temporary_file ) ) {
            wp_delete_file( $temporary_file );
        }

        return null;
    }

    /**
     * Method get_attachment_id_by_source_url()
     * Get attachment ID from source URL.
     *
     * @param string $source_url Original URL of the image.
     * @return int|null ID of the attachment or null if it does not exist.
     */
    private function get_attachment_id_by_source_url( $source_url ) {
        global $wpdb;

        // Query attachment posts based on '_source_url' metadata.
        $attachment_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_source_url' AND meta_value = %s",
                $source_url
            )
        );

        return $attachment_id ? (int) $attachment_id : null;
    }

    /**
     * Method set_showhide()
     *
     * Hide or unhide the WP Seo plugin.
     *
     * @return array Action result.
     *
     * @used-by \MainWP\Child\MainWP_WordPress_SEO::actions() Fire off certain WP Seo plugin actions.
     * @uses    \MainWP\Child\MainWP_Helper::update_option()
     */
    public function set_showhide() {
        $hide = MainWP_System::instance()->validate_params( 'show_hide' );
        MainWP_Helper::update_option( 'mainwp_wpseo_hide_plugin', $hide );
        $information['result'] = 'SUCCESS';

        return $information;
    }

    /**
     * Method all_plugins()
     *
     * Remove the WP SEO plugin from the list of all plugins when the plugin is hidden.
     *
     * @param array $plugins Array containing all installed plugins.
     *
     * @return array $plugins Array containing all installed plugins without the WP SEO.
     */
    public function all_plugins( $plugins ) {
        foreach ( $plugins as $key => $value ) {
            $plugin_slug = basename( $key, '.php' );
            if ( 'wp-seo' === $plugin_slug ) {
                unset( $plugins[ $key ] );
            }
        }

        return $plugins;
    }

    /**
     * Method remove_menu()
     *
     * Remove the WP Seo menu item when the plugin is hidden.
     */
    public function remove_menu() {
        // Hide "Yoast SEO".
        remove_menu_page( 'wpseo_dashboard' );
        // Hide "Yoast SEO → General".
        remove_submenu_page( 'wpseo_dashboard', 'wpseo_dashboard' );
        // Hide "Yoast SEO → Settings".
        remove_submenu_page( 'wpseo_dashboard', 'wpseo_page_settings' );
        // Hide "Yoast SEO → Integrations".
        remove_submenu_page( 'wpseo_dashboard', 'wpseo_integrations' );
        // Hide "Yoast SEO → Tools".
        remove_submenu_page( 'wpseo_dashboard', 'wpseo_tools' );
        // Hide "Yoast SEO → Academy".
        remove_submenu_page( 'wpseo_dashboard', 'wpseo_page_academy' );
        // Hide "Yoast SEO → Upgrades".
        remove_submenu_page( 'wpseo_dashboard', 'wpseo_licenses' );
        // Hide "Yoast SEO → Workouts".
        remove_submenu_page( 'wpseo_dashboard', 'wpseo_workouts' );
        // Hide "Yoast SEO → Redirects".
        remove_submenu_page( 'wpseo_dashboard', 'wpseo_redirects' );
        // Hide "Yoast SEO → Support".
        remove_submenu_page( 'wpseo_dashboard', 'wpseo_page_support' );

        $pos = isset( $_SERVER['REQUEST_URI'] ) ? stripos( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), 'admin.php?page=wpseo_dashboard' ) : false;
        if ( false !== $pos ) {
            wp_safe_redirect( get_option( 'siteurl' ) . '/wp-admin/index.php' );
            exit();
        }
    }

    /**
     * Method wp_before_admin_bar_render()
     *
     * Remove the WP Seo admin bar node when the plugin is hidden.
     */
    public function wp_before_admin_bar_render() {

        /**
         * WordPress admin bar array.
         *
         * @global array $wp_admin_bar WordPress admin bar array.
         */
        global $wp_admin_bar;

        $nodes = $wp_admin_bar->get_nodes();
        if ( is_array( $nodes ) ) {
            foreach ( $nodes as $id => $node ) {
                if ( strpos( $id, 'wpseo' ) === 0 ) {
                    $wp_admin_bar->remove_node( $id );
                }
            }
        }
    }

    /**
     * Method remove_metaboxes()
     *
     * Remove the WP Seo metabox when the plugin is hidden.
     */
    public function remove_metaboxes() {
        $screen = get_current_screen();
        if ( ! $screen ) {
            return;
        }

        // Hide the "Yoast SEO" meta box.
        remove_meta_box( 'wpseo_meta', $screen->id, 'normal' );
    }

    /**
     * Method remove_dashboard_widgets()
     *
     * Remove the WP Seo dashboard widgets when the plugin is hidden.
     */
    public function remove_dashboard_widgets() {
        $screen = get_current_screen();
        if ( ! $screen ) {
            return;
        }

        // Remove the "Yoast SEO Posts Overview" widget.
        remove_meta_box( 'wpseo-dashboard-overview', 'dashboard', 'normal' );
        // Remove the "Yoast SEO / Wincher: Top Keyphrases" widget.
        remove_meta_box( 'wpseo-wincher-dashboard-overview', 'dashboard', 'normal' );
    }

    /**
     * Method remove_update_nag()
     *
     * Remove the WP Seo plugin update notice when the plugin is hidden.
     *
     * @param object $value Object containing update information.
     *
     * @return object $value Object containing update information.
     *
     * @uses \MainWP\Child\MainWP_Helper::is_updates_screen()
     */
    public function remove_update_nag( $value ) {
        if ( MainWP_Helper::is_dashboard_request() ) {
            return $value;
        }

        if ( ! MainWP_Helper::is_updates_screen() ) {
            return $value;
        }

        if ( isset( $value->response['wordpress-seo/wp-seo.php'] ) ) {
            unset( $value->response['wordpress-seo/wp-seo.php'] );
        }

        return $value;
    }

    /**
     * Method hide_update_notice()
     *
     * Remove the WP Seo plugin update notice when the plugin is hidden.
     *
     * @param array $slugs Array containing installed plugins slugs.
     *
     * @return array $slugs Array containing installed plugins slugs.
     */
    public function hide_update_notice( $slugs ) {
        $slugs[] = 'wordpress-seo/wp-seo.php';
        return $slugs;
    }

    /**
     * Method remove_notices()
     *
     * Remove admin notices thrown by the WP Seo plugin when the plugin is hidden.
     *
     * @uses MainWP_Child_WP_SEO::remove_filters_with_method_name() Remove filters with method name.
     */
    public function remove_notices() {
        $remove_hooks['admin_notices'] = array(
            'yoast_wpseo_missing_spl_notice'               => 10,
            'yoast_wpseo_missing_autoload_notice'          => 10,
            'permalink_settings_notice'                    => 10,
            'premium_deactivated_notice'                   => 10,
            'display_notifications'                        => 10,
            'first_time_configuration_notice'              => 10,
            'throw_no_owned_addons_warning'                => 10,
            'notify_not_installed'                         => 10,
            'maybe_show_search_engines_discouraged_notice' => 10,
            'renderMessage'                                => 10,
            'render_migration_error'                       => 10,
        );
        foreach ( $remove_hooks as $hook_name => $hooks ) {
            foreach ( $hooks as $method => $priority ) {
                static::remove_filters_with_method_name( $hook_name, $method, $priority );
            }
        }
    }

    /**
     * Method remove_filters_with_method_name()
     *
     * Remove filters with method name.
     *
     * @param string $hook_name   Contains the hook name.
     * @param string $method_name Contains the method name.
     * @param int    $priority    Contains the priority value.
     *
     * @used-by MainWP_Child_WP_SEO::remove_notices() Remove admin notices thrown by the WP SEO plugin when the plugin is hidden.
     *
     * @return bool Return false if filtr is not set.
     */
    public static function remove_filters_with_method_name( $hook_name = '', $method_name = '', $priority = 0 ) {

        /**
         * WordPress filter array.
         *
         * @global array $wp_filter WordPress filter array.
         */
        global $wp_filter;

        // Take only filters on right hook name and priority.
        if ( ! isset( $wp_filter[ $hook_name ][ $priority ] ) || ! is_array( $wp_filter[ $hook_name ][ $priority ] ) ) {
            return false;
        }
        // Loop on filters registered.
        foreach ( (array) $wp_filter[ $hook_name ][ $priority ] as $unique_id => $filter_array ) {
            // Test if filter is an array ! (always for class/method).
            if ( isset( $filter_array['function'] ) && is_array( $filter_array['function'] ) && is_object( $filter_array['function'][0] ) && get_class( $filter_array['function'][0] ) && $filter_array['function'][1] === $method_name ) {
                // Test for WordPress >= 4.7 WP_Hook class.
                if ( is_a( $wp_filter[ $hook_name ], 'WP_Hook' ) ) {
                    unset( $wp_filter[ $hook_name ]->callbacks[ $priority ][ $unique_id ] );
                } else {
                    unset( $wp_filter[ $hook_name ][ $priority ][ $unique_id ] );
                }
            }
        }
        return false;
    }
}
