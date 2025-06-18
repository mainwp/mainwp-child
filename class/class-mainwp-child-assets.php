<?php
/**
 * MainWP Child Assets
 *
 * This file handles optimized loading of CSS and JavaScript assets.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

/**
 * Class MainWP_Child_Assets
 *
 * Handles optimized loading of CSS and JavaScript assets.
 */
class MainWP_Child_Assets {

    /**
     * Public static instance variable.
     *
     * @var null Holds the Public static instance of MainWP_Child_Assets.
     */
    protected static $instance = null;

    /**
     * MainWP admin pages list.
     *
     * @var array Holds the list of pages where MainWP Child assets should be loaded.
     */
    private $mainwp_pages = array(
        'settings_page_mainwp_child_tab',
        'toplevel_page_mainwp_child',
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
     * MainWP_Child_Assets constructor.
     *
     * Run any time class is called.
     */
    public function __construct() {
    }

    /**
     * Method init()
     *
     * Initiate hooks for asset loading optimization.
     */
    public function init() {
        // Register the admin_enqueue_scripts hook with a high priority to run early.
        add_action( 'admin_enqueue_scripts', array( $this, 'register_assets' ), 5 );

        // Conditionally enqueue assets only on MainWP pages.
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ), 10 );
    }

    /**
     * Method register_assets()
     *
     * Register all assets but don't enqueue them yet.
     * This allows us to only enqueue them when needed.
     */
    public function register_assets() {
        // Register jQuery UI styles only if needed.
        global $wp_scripts;
        $jquery_ui_core = $wp_scripts->query( 'jquery-ui-core' );
        if ( ! $jquery_ui_core ) {
            return;
        }

        $version = $jquery_ui_core->ver;

        // Use version 1.10 stylesheet if detected.
        if ( $this->is_jquery_ui_version_110( $version ) ) {
            wp_register_style(
                'jquery-ui-style',
                plugins_url( 'css/1.10.4/jquery-ui.min.css', __DIR__ ),
                array(),
                '1.10',
                'all'
            );
            return;
        }

        // Default to version 1.11 stylesheet.
        wp_register_style(
            'jquery-ui-style',
            plugins_url( 'css/1.11.1/jquery-ui.min.css', __DIR__ ),
            array(),
            '1.11',
            'all'
        );
    }

    /**
     * Method enqueue_assets()
     *
     * Conditionally enqueue assets only on MainWP pages.
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue_assets( $hook ) {
        // Only load assets on MainWP Child pages.
        if ( $this->is_mainwp_page( $hook ) ) {
            wp_enqueue_script( 'jquery-ui-tooltip' );
            wp_enqueue_script( 'jquery-ui-autocomplete' );
            wp_enqueue_script( 'jquery-ui-progressbar' );
            wp_enqueue_script( 'jquery-ui-dialog' );
            wp_enqueue_style( 'jquery-ui-style' );
        }
    }

    /**
     * Method is_jquery_ui_version_110()
     *
     * Check if jQuery UI version starts with 1.10.
     *
     * @param string $version jQuery UI version string.
     * @return bool True if version starts with 1.10, false otherwise.
     */
    private function is_jquery_ui_version_110( $version ) {
        return 0 === strpos( $version, '1.10' );
    }

    /**
     * Method is_mainwp_page()
     *
     * Check if current page is a MainWP Child page.
     *
     * @param string $hook Current admin page hook.
     * @return bool True if current page is a MainWP Child page, false otherwise.
     */
    private function is_mainwp_page( $hook ) {
        // Check if the current hook is in our list of MainWP pages.
        if ( in_array( $hook, $this->mainwp_pages, true ) ) {
            return true;
        }

        // Check if we're on a page with mainwp in the query string.
        // This is a read-only check for admin page detection, not processing user input for database operations.
        // Therefore, nonce verification is not required here.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = filter_input( INPUT_GET, 'page', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
        if ( ! empty( $page ) && (
            false !== strpos( strtolower( $page ), 'mainwp' )
        ) ) {
            return true;
        }

        return false;
    }
}
