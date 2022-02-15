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
     * Check which Cache Plugin is installed and active.
     *
     * @return false|string Return False if no plugin found | Plugin slug.
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
            }
        }

        update_option( 'mainwp_cache_control_cache_solution', $cache_plugin_solution );
    }

    /**
     * Auto purge cache based on which cache plugin is installed & activated.
     *
     * @used-by MainWP_Child_Updates::upgrade_plugin_theme()
     * @used-by MainWP_Child_Updates::upgrade_wp()
     */
    public function auto_purge_cache( $information )
    {
        // Grab detected cache solution.
        $cache_plugin_solution = get_option('mainwp_cache_control_cache_solution', 0);

        // Run the corresponding cache plugin purge method.
        switch ( $cache_plugin_solution ) {
            case "wp-rocket/wp-rocket.php":
                $this->wprocket_auto_cache_purge( $information );
                break;
            case "breeze/breeze.php":
                $this->breeze_auto_cache_purge( $information );
                break;
            default:
                break;
        }
    }

    /**
     * Breeze
     */
    public function breeze_auto_cache_purge( $information ){
        if ( function_exists('breeze_cache_flush') ) {
            breeze_cache_flush();
        }

        // Record results of cache purge.
        $this->record_results( $information );

        return $information;

    }

    /**
     * WP-Rocket auto cache purge.
     *
     * Purge cache after updates.
     *
     * @used-by  MainWP_Child_Updates::upgrade_plugin_theme()
     * @used-by MainWP_Child_Updates::upgrade_wp()
     */
    public function wprocket_auto_cache_purge( $information ){

        // Purge Cache if action is set to "1".
        $action = get_option( 'mainwp_child_auto_purge_cache', false );
        $purge_result = array();

        if ( $action == 1 ) {
            $purge_result = MainWP_Child_WP_Rocket::instance()->purge_cache_all();
        }

        // Save last purge time to database on success.
        if ( $purge_result['result'] === "SUCCESS" ) {
            update_option( 'mainwp_cache_control_last_purged', time() );

            // Store purge result for error log.
            $information['log'] = "WP Rocket => Cache auto cleared on: (" . current_time('mysql') . ")";
        } else {
            // Store purge result for error log.
            $information['log'] = $purge_result;
        }

        // Record results of cache purge to log file if debug mode is enabled.
        if ( MAINWP_DEBUG === 'true' ) {
            $this->record_results($information);
        }

        return $information;
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

        // Save $Information to Log file.
        file_put_contents($upload_dir . "/last_purge_log.txt", json_encode( $information ) );
    }
}

