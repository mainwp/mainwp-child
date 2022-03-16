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

    }

    /**
     * Check which supported plugin is installed,
     * Set wp_option 'mainwp_cache_control_cache_solution' to active plugin,
     * and set public variable 'is_plugin_installed' to TRUE.
     *
     * If a supported plugin is not installed check to see if CloudFlair solution is enabled.
     */
    public function check_cache_solution(){
        $cache_plugin_solution = '';

        $supported_cache_plugins = array(
            'wp-rocket/wp-rocket.php',
            'breeze/breeze.php',
            'litespeed-cache/litespeed-cache.php',
            'sg-cachepress/sg-cachepress.php'
        );

        // Check if a supported cache plugin is active then check if CloudFlair is active.
        foreach( $supported_cache_plugins as $plugin ) {
            if ( is_plugin_active( $plugin ) ) {
                $cache_plugin_solution = $plugin;
                $this->is_plugin_installed = true;
            } else if ( !get_option( 'mainwp_child_cloud_flair_enabled' ) == '0' ) {
                $cache_plugin_solution  = 'Cloudflare';
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


        //** Grab data synced from MainWP Dashboard & update options. **//
        if ( is_array( $data ) ) {
            try {

                // Update mainwp_child_auto_purge_cache option value with either yes|no.
                update_option( 'mainwp_child_auto_purge_cache', ( $data['auto_purge_cache'] ? 1 : 0 ) );

                // Update mainwp_child_cloud_flair_enabled options value.
                update_option( 'mainwp_child_cloud_flair_enabled', ( $data['cloud_flair_enabled'] ? 1 : 0 ) );

                // Update Cloudflair API Credentials option values.
                update_option( 'mainwp_cloudflair_email', ( $data['mainwp_cloudflair_email'] ) );
                update_option( 'mainwp_cloudflair_key', ( $data['mainwp_cloudflair_key'] ) );

            } catch ( \Exception $e ) {
                error_log( $e->getMessage() ); // phpcs:ignore -- debug mode only.
            }
        }

        //** Send data to MainWP Dashboard. **//

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

        // Grab detected cache solution..
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
                case "litespeed-cache/litespeed-cache.php":
                    $information = $this->litespeed_auto_purge_cache();
                    break;
                case "sg-cachepress/sg-cachepress.php":
                    $information = $this->sitegrounds_optimizer_auto_purge_cache();
                    break;
                case "Cloudflare":
                    $information = $this->cloudflair_auto_purge_cache();
                    break;
                default:
                    break;
            }
        } catch ( \Exception $e ) {
            $information = array( 'error' => $e->getMessage() );
        }

        // Record results of cache purge to log file if debug mode is enabled.
        if ( MAINWP_DEBUG === 'true' ) {
            error_log( $information );
            error_log( print_r( $information, true ) );
            $this->record_results( $information );
        }
    }
    /**
     * Purge SiteGrounds Optimiser Cache after Updates.
     */
    public function sitegrounds_optimizer_auto_purge_cache(){
        if ( function_exists( 'sg_cachepress_purge_everything') ){

            // Purge all SG CachePress cache.
            sg_cachepress_purge_everything();

            // record results.
            update_option('mainwp_cache_control_last_purged', time());
            return array('result' => "SG CachePress => Cache auto cleared on: (" . current_time('mysql') . ")" );
        } else {
            return array('error' => 'Please make sure a supported plugin is installed on the Child Site.');
        }
    }

    /**
     * Purge Cloudflair Cache after updates.
     */
    public function cloudflair_auto_purge_cache() {

        // Credentials for Cloudflare.
        $cust_email = get_option( 'mainwp_cloudflair_email' );
        $cust_xauth =  get_option( 'mainwp_cloudflair_key' );
        $cust_domain = trim( str_replace( array( 'http://', 'https://', 'www.' ), '', get_option( 'siteurl' ) ), '/' );

        if($cust_email == "" || $cust_xauth == "" || $cust_domain == "") return;

        //Get the Zone-ID from Cloudflare since they don't provide that in the Backend
        $ch_query = curl_init();
        curl_setopt($ch_query, CURLOPT_URL, "https://api.cloudflare.com/client/v4/zones?name=".$cust_domain."&status=active&page=1&per_page=5&order=status&direction=desc&match=all");
        curl_setopt($ch_query, CURLOPT_RETURNTRANSFER, 1);
        $qheaders = array(
            'X-Auth-Email: '.$cust_email.'',
            'X-Auth-Key: '.$cust_xauth.'',
            'Content-Type: application/json'
        );
        curl_setopt($ch_query, CURLOPT_HTTPHEADER, $qheaders);
        $qresult = json_decode(curl_exec($ch_query),true);
        curl_close($ch_query);

        $cust_zone = $qresult['result'][0]['id'];

        //Purge the entire cache via API
        $ch_purge = curl_init();
        curl_setopt($ch_purge, CURLOPT_URL, "https://api.cloudflare.com/client/v4/zones/".$cust_zone."/purge_cache");
        curl_setopt($ch_purge, CURLOPT_CUSTOMREQUEST, "DELETE");
        curl_setopt($ch_purge, CURLOPT_RETURNTRANSFER, 1);
        $headers = [
            'X-Auth-Email: '.$cust_email,
            'X-Auth-Key: '.$cust_xauth,
            'Content-Type: application/json'
        ];
        $data = json_encode(array("purge_everything" => true));
        curl_setopt($ch_purge, CURLOPT_POST, true);
        curl_setopt($ch_purge, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch_purge, CURLOPT_HTTPHEADER, $headers);

        $result = json_decode(curl_exec($ch_purge),true);
        curl_close($ch_purge);

        // Save last purge time to database on success.
        if ( $result['success']==1 ) {
            update_option( 'mainwp_cache_control_last_purged', time() );
            return array( 'result' => "Cloudflare => Cache auto cleared on: (" . current_time('mysql') . ")" );
        } else {
            return array( 'error' => 'There was an issue purging your cache.' . json_encode( $result ) );
        }
    }

    /**
     * Purge LiteSpeed Cache after updates.
     */
    public function litespeed_auto_purge_cache() {
        if ( class_exists ( 'Purge' ) ) {

            // Purge all cache.
            \Purge::_purge_all();
            //do_action( 'litespeed_purge_all' );

            // record results.
            update_option('mainwp_cache_control_last_purged', time());
            return array('result' => "Litespeed => Cache auto cleared on: (" . current_time('mysql') . ")" );
        } else {
            return array('error' => 'Please make sure a supported plugin is installed on the Child Site.');
        }
    }

    /**
     * Purge Breeze cache.
     */
    public function breeze_auto_purge_cache(){

        if ( class_exists( 'Breeze_Admin' ) ) {

            // Clears varnish cache.
            $admin = new \Breeze_Admin();
            $admin->breeze_clear_varnish();

            // For local static files: Clears files within /cache/breeze-minification/ folder.
            $size_cache = \Breeze_Configuration::breeze_clean_cache();

            // Delete minified files.
            \Breeze_MinificationCache::clear_minification();

            // Clear normal cache.
            \Breeze_PurgeCache::breeze_cache_flush();

            // record results.
            update_option('mainwp_cache_control_last_purged', time());
            return array('result' => "Breeze => Cache auto cleared on: (" . current_time('mysql') . ") And " . $size_cache . " local files removed. ");
        } else {
            return array('error' => 'Please make sure a supported plugin is installed on the Child Site.');
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
     * @howto define('MAINWP_DEBUG', true); within wp-config.php.
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

