<?php
/**
 * MainWP Child Site Cache Purge
 *
 * Manages clearing the selected Cache.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

/**
 * Class MainWP_Child_Cache_Purge
 *
 * This class handles purging Child Site cache when requested.
 *
 * @package MainWP\Child
 */
class MainWP_Child_Cache_Purge {

    /**
     * Public variable to state if supported plugin is installed on the child site.
     *
     * @var bool If supported plugin is installed, return true, if not, return false.
     */
    public $is_plugin_installed = false;

    /**
     * Public static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    protected static $instance = null;

    /**
     * Method instance()
     *
     * Create a public static instance.
     *
     * @return mixed Class instance.
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * MainWP_Child_Cache_Purge constructor.
     *
     * Run any time class is called.
     */
    public function __construct() {
        add_filter( 'mainwp_site_sync_others_data', array( $this, 'sync_others_data' ), 10, 2 );
        add_action( 'plugins_loaded', array( $this, 'check_cache_solution'), 10, 2 );
    }

    public function init() {
        // fired off in system but no need to use it?
        if ( ! $this->is_plugin_installed ) {
            return;
        }
    }

    /**
     * Check which Cache Plugin is installed and active.
     * Set 'mainwp_cache_control_cache_solution' option to active plugin,
     * and set public varible 'is_plugin_installed = true' if plugin is active.
     */
    public function check_cache_solution(){

        $cache_plugin_solution = null;

        $supported_cache_plugins = array(
            'wp-rocket/wp-rocket.php',
            'breeze/breeze.php'
        );

        // Check if a supported cache plugin is active.
        foreach( $supported_cache_plugins as $plugin ) {
            if ( is_plugin_active( $plugin ) ) {
                $cache_plugin_solution = $plugin;
                $this->is_plugin_installed = true;
            }
        }

        update_option( 'mainwp_cache_control_cache_solution', $cache_plugin_solution );
    }

    /**
     * Method sync_others_data()
     *
     * Sync data to & from the MainWP Dashboard.
     *
     * @param array $information Array containing the data to be sent to the Dashboard.
     * @param array $data        Array containing the data sent from the Dashboard; to be saved to the Child Site.
     *
     * @return array $information Array containing the data to be sent to the Dashboard.
     */
    public function sync_others_data( $information, $data = array() ) {
        if ( is_array( $data ) && isset( $data['auto_purge_cache'] ) ) {
            try {

                // Update mainwp_child_auto_purge_cache option value with either yes|no.
                update_option( 'mainwp_child_auto_purge_cache', ( $data['auto_purge_cache'] ? 1 : 0 ) );


            } catch ( \Exception $e ) {
                error_log( $e->getMessage() ); // phpcs:ignore -- debug mode only.
            }
        }
        // Send last purged time stamp to MainWP Dashboard.
        $information['mainwp_cache_control_last_purged'] = get_option('mainwp_cache_control_last_purged', 0 );

        // Send active cache solution to MainWP Dashboard.
        $information['mainwp_cache_control_cache_solution'] = get_option('mainwp_cache_control_cache_solution', 0 );

        return $information;
    }

    /**
     * Auto purge cache based on which cache plugin is installed & activated.
     *
     * @used-by MainWP_Child_Updates::upgrade_plugin_theme()
     * @used-by MainWP_Child_Updates::upgrade_wp()
     */
    public function auto_purge_cache()
    {
//        if ( ! $this->is_plugin_installed ) {
//            MainWP_Helper::write( array( 'error' => __( 'Please install WP Rocket plugin on child website', $this->plugin_translate ) ) );
//            return;
//        }

        $information = array();

        // Grab detected cache solution.
        $cache_plugin_solution = get_option('mainwp_cache_control_cache_solution', 0);

        // Run the corresponding cache plugin purge method.
        try {
            switch ( $cache_plugin_solution ) {
                case "wp-rocket/wp-rocket.php":
                    $information = $this->wprocket_auto_cache_purge();
                    break;
                case "breeze/breeze.php":
                    $information = $this->breeze_auto_purge_cache();
                    break;
                default:
                    break;
            }
        } catch ( \Exception $e ) {
            $information = array( 'error' => $e->getMessage() );
        }

        // Record results of cache purge to log file if debug mode is enabled.
        if ( MAINWP_DEBUG === 'true' ) {
            $this->record_results( $information );
        }
    }

    /**
     * Purge Breeze cache.
     */
    public function breeze_auto_purge_cache(){

        if ( class_exists( 'Breeze_Admin' ) ) {

            //Other methods to clear “all cache” or “varnish cache”

            // for all cache
            //do_action( 'breeze_clear_all_cache')<-- this is the hook that I want to fire off. but doesn't

//            Suposed to fire below methods but doesn't seams to actually do anything at all.
//
//            public function breeze_clear_all_cache() {
//                //delete minify
//                Breeze_MinificationCache::clear_minification();
//                //clear normal cache
//                Breeze_PurgeCache::breeze_cache_flush();
//                //clear varnish cache
//                $this->breeze_clear_varnish();
//            }
//
            // for varnish
            //do_action( 'breeze_clear_varnish');

            // Clears varnish cache ~ no idea if this is even working.
            $admin = new \Breeze_Admin();
            $admin->breeze_clear_varnish();

            // For local static files: Clears files within /cache/breeze-minification/ folder.
            $size_cache = \Breeze_Configuration::breeze_clean_cache();

            // Delete minify
            \Breeze_MinificationCache::clear_minification();

            // Clear normal cache.
            \Breeze_PurgeCache::breeze_cache_flush();

            // record results
            update_option('mainwp_cache_control_last_purged', time());
            return array('result' => "Breeze => Cache auto cleared on: (" . current_time('mysql') . ") And " . $size_cache . " local files removed. ");
        } else {
            return array('error' => 'Please make sure Breeze plugin is installed on the Child Site.');
        }
    }

    /**
     * Purge WP-Rocket cache.
     */
    public function wprocket_auto_cache_purge(){

        // Purge Cache if action is set to "1".
        $action = get_option( 'mainwp_child_auto_purge_cache', false );
        $purge_result = array();

        if ( $action == 1 ) {
            $purge_result = MainWP_Child_WP_Rocket::instance()->purge_cache_all();
        }

        // Save last purge time to database on success.
        if ( $purge_result['result'] === "SUCCESS" ) {
            update_option( 'mainwp_cache_control_last_purged', time() );
            return array( 'result' => "WP Rocket => Cache auto cleared on: (" . current_time('mysql') . ")" );
        } else {
            return array( 'error' => 'There was an issue purging your cache.' );
        }
    }

    /**
     * Record last Purge.
     *
     * Create log file & Save in /Upload dir.
     * @howto define('MAINWP_DEBUG', 'true'); within wp-config.php.
     */
    public function record_results( $information ){
        // Setup timezone and upload directory for logs.
        date_default_timezone_set(wp_timezone());
        $upload_dir = wp_get_upload_dir();
        $upload_dir = $upload_dir['basedir'];

        // Additional Cache Plugin & MainWP Child information.
//        $information = array(
//            'result'            => $information['result'],
//            'error'             => $information['error'],
//            'last_cache_purge'  => get_option( 'mainwp_cache_control_last_purged', false ),
//            'cache_solution'    => get_option( 'mainwp_cache_control_cache_solution', false ),
//            'breeze_path'       => BREEZE_PLUGIN_FULL_PATH
//        );

        // Save $information array to Log file.
        file_put_contents($upload_dir . "/last_purge_log.txt", json_encode( $information ) );
    }
}

