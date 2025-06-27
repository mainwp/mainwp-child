<?php
/**
 * MainWP HTML Regression
 *
 * MainWP HTML Regression extension handler.
 * Extension URL: https://mainwp.com/extension/html-regression/
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

// phpcs:disable PSR1.Classes.ClassDeclaration, WordPress.WP.AlternativeFunctions -- required to achieve desired results, pull request solutions appreciated.

/**
 * Class MainWP_Child_HTML_Regression
 *
 * MainWP HTML Regression extension handler.
 */
class MainWP_Child_HTML_Regression { //phpcs:ignore -- NOSONAR - multi methods.

    /**
     * Public static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    public static $instance = null;

    /**
     * Public variable to hold the information if the HTML Regression plugin is installed on the child site.
     *
     * @var bool If HTML Regression installed, return true, if not, return false.
     */
    public $is_plugin_installed = true;

    /**
     * Public variable to hold the information about the language domain.
     *
     * @var string 'mainwp-child' languge domain.
     */
    public $plugin_translate = 'mainwp-child';

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
     * MainWP_Child_HTML_Regression constructor.
     *
     * Run any time class is called.
     */
    public function __construct() {
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
        add_action( 'admin_enqueue_scripts', array( $this, 'track_admin_assets' ), 999 );
        add_filter( 'mainwp_site_sync_others_data', array( $this, 'sync_others_data' ), 10, 2 );
    }

    /**
     * Method sync_others_data()
     *
     * Sync the HTML Regression plugin settings.
     *
     * @param  array $information Array containing the sync information.
     * @param  array $data        Array containing the HTML Regression plugin data to be synced.
     *
     * @uses MainWP_Child_HTML_Regression::get_active_assets()
     * @uses MainWP_Child_HTML_Regression::get_frontend_assets_only()
     * @uses MainWP_Child_HTML_Regression::get_theme_css_js_files()
     *
     * @return array $information Array containing the sync information.
     */
    public function sync_others_data( $information, $data = array() ) {

        if ( isset( $data['sync_html_regression_data'] ) && ( 'yes' === $data['sync_html_regression_data'] ) ) {
            try {
                $data                                     = array(
                    'files'   => $this->get_active_assets(),
                    'plugins' => $this->get_frontend_assets_only(),
                    'themes'  => $this->get_theme_css_js_files(),
                );
                $information['sync_html_regression_data'] = $data;
            } catch ( MainWP_Exception $e ) {
                // ok!
            }
        }
        return $information;
    }

    /**
     * Method get_active_assets()
     *
     * Get plugin and theme files
     *
     * @uses MainWP_Child_HTML_Regression::get_css_js_files()
     * @uses MainWP_Child_HTML_Regression::get_theme_css_js_files()
     *
     * @return array List Folder files CSS, JS of Plugins and Theme.
     */
    public function get_active_assets() {
        $result = array(
            'plugins' => array(),
            'theme'   => array(),
        );

        // Get a list of active plugins.
        $active_plugins = get_option( 'active_plugins', array() );
        $plugin_dir     = WP_PLUGIN_DIR;

        foreach ( $active_plugins as $plugin ) {
            $plugin_path                             = $plugin_dir . '/' . dirname( $plugin );
            $result['plugins'][ dirname( $plugin ) ] = $this->get_css_js_files( $plugin_path );
        }

        // Get a list of CSS and JS files of the currently selected theme.
        $result['theme'] = $this->get_theme_css_js_files();

        return $result;
    }

    /**
     * Method get_css_js_files()
     *
     * Get css and js files from folder.
     *
     * @param string $directory directory path.
     *
     * @uses RecursiveDirectoryIterator
     * @uses RecursiveIteratorIterator
     * @return array List of CSS and JS files.
     */
    public function get_css_js_files( $directory ) {
        $files = array();

        // Check if directory exists.
        if ( ! is_dir( $directory ) ) {
            return $files;
        }

        // Browse all files in the directory.
        $iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $directory ) );

        foreach ( $iterator as $file ) {
            // Get the file extension.
            $extension = pathinfo( $file, PATHINFO_EXTENSION );

            // Check if it is a .css or .js file.
            if ( in_array( $extension, array( 'css', 'js' ), true ) ) {
                // Save file path.
                $files[] = str_replace( ABSPATH, '', $file->getPathname() );
            }
        }
        // Rest value files.
        return array_values( $files );
    }

    /**
     * Method get_frontend_assets_only()
     *
     *  Get a list of enqueued CSS and JS files and only get them from the frontend
     *
     * @uses MainWP_Child_HTML_Regression::is_asset_in_admin()
     * @uses MainWP_Child_HTML_Regression::get_plugin_name_from_path()
     *
     * @return array List of CSS and JS files.
     */
    public function get_frontend_assets_only() {
        global $wp_styles, $wp_scripts;

        $plugin_assets = array();

        // Helper function to process assets.
        $process_assets = function ( $assets, $type ) use ( &$plugin_assets ) {
            foreach ( $assets as $handle => $asset ) {
                if ( isset( $asset->src ) && strpos( $asset->src, 'wp-content/plugins/' ) !== false && ! $this->is_asset_in_admin( $handle, $type ) ) {
                    $plugin_info = $this->get_plugin_name_from_path( $asset->src );
                    if ( empty( $plugin_info ) ) {
                        continue;
                    }
                    $plugin_assets[ $plugin_info['path'] ]['name']          = $plugin_info['name'];
                    $plugin_assets[ $plugin_info['path'] ][ $type . 's' ][] = array(
                        'handle' => $handle,
                        'src'    => $asset->src,
                    );
                }
            }
        };

        // Process styles.
        if ( isset( $wp_styles->registered ) && is_array( $wp_styles->registered ) ) {
            $process_assets( $wp_styles->registered, 'style' );
        }

        // Process scripts.
        if ( isset( $wp_scripts->registered ) && is_array( $wp_scripts->registered ) ) {
            $process_assets( $wp_scripts->registered, 'script' );
        }

        return $plugin_assets;
    }

    /**
     * Method is_asset_in_admin()
     *
     * Check if the CSS/JS file is loaded in admin or not.
     *
     * @param string $handle Asset name (CSS/JS).
     * @param string $type 'style' or 'script' to identify the asset type.
     * @return bool True if the asset is loaded in admin, false otherwise.
     */
    public function is_asset_in_admin( $handle, $type ) {
        // Variable that checks whether the asset appears in admin or not.
        $is_in_admin  = false;
        $admin_assets = get_option( 'html-regression-track-admin-assets', array() );
        if ( empty( $admin_assets ) ) {
            do_action( 'admin_enqueue_scripts' );
            $admin_assets = get_option( 'html-regression-track-admin-assets' );
        }

        if ( 'style' === $type ) {
            // Check if the style is in the admin's enqueue list.
            if ( in_array( $handle, $admin_assets['styles'], true ) ) {
                $is_in_admin = true;
            }
        } elseif ( 'script' === $type ) {
            // Check if the script is in the admin's enqueue list.
            if ( in_array( $handle, $admin_assets['scripts'], true ) ) {
                $is_in_admin = true;
            }
        }

        // If the asset is in admin, return true, otherwise false.
        return $is_in_admin;
    }

    /**
     * Method track_admin_assets()
     *
     * Track admin assets files.
     *
     * @return void update option tracking admin assets plugins.
     */
    public function track_admin_assets() {
        global $wp_styles, $wp_scripts;
        $admin_assets = array(
            'styles'  => array(),
            'scripts' => array(),
        );

        // save list styles.
        if ( isset( $wp_styles->queue ) && is_array( $wp_styles->queue ) ) {
            $admin_assets['styles'] = $wp_styles->queue;
        }

        // Save the list of scripts.
        if ( isset( $wp_scripts->queue ) && is_array( $wp_scripts->queue ) ) {
            $admin_assets['scripts'] = $wp_scripts->queue;
        }

        update_option( 'html-regression-track-admin-assets', $admin_assets, true );
    }

    /**
     * Method get_plugin_name_from_path()
     *
     * Get plugin name from file path.
     *
     * @param string $path CSS/JS file path.
     * @return mixed Name and path of plugin.
     */
    private function get_plugin_name_from_path( $path ) {
        // Find the location of 'wp-content/plugins/' in the path.
        $plugin_path = strpos( $path, 'wp-content/plugins/' );
        if ( false !== $plugin_path ) {
            // Trim the string to get the plugin name.
            $relative_path = substr( $path, $plugin_path + strlen( 'wp-content/plugins/' ) );
            $parts         = explode( '/', $relative_path );
            if ( ! empty( $parts[0] ) ) {
                // Get a list of all installed plugins.
                if ( ! function_exists( 'get_plugins' ) ) {
                    require_once ABSPATH . 'wp-admin/includes/plugin.php'; // NOSONAR - ok.
                }
                $all_plugins = get_plugins();

                // Browse the plugin list to find the plugin name.
                foreach ( $all_plugins as $plugin_file => $plugin_data ) {
                    if ( strpos( $plugin_file, $parts[0] ) === 0 ) {
                        return array(
                            'name' => $plugin_data['Name'],
                            'path' => 'wp-content/plugins/' . $parts[0],
                        ); // Returns the exact plugin name.
                    }
                }
            }
        }

        return null; // Returns the default name if unknown.
    }

    /**
     * Method get_theme_css_js_files()
     *
     * Get theme css js files
     *
     * @uses MainWP_Child_HTML_Regression::get_css_js_files()
     * @return array theme css and js files.
     */
    public function get_theme_css_js_files() {
        $theme     = wp_get_theme();
        $theme_dir = get_template_directory();
        return array( $theme->get( 'Name' ) => $this->get_css_js_files( $theme_dir ) );
    }
}
