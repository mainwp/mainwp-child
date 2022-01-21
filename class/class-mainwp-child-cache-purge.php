<?php
/**
 * MainWP Child Site Cache Purge
 *
 * Manages clearing the selected Cache.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

//Exit if access directly.
if ( ! defined( 'WP_CLI' ) ) {
    return;
}

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
     * MainWP_Child_Updates constructor.
     *
     * Run any time class is called.
     */
    public function __construct() {

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

    public function init() {
        add_filter( 'mainwp_site_sync_others_data', array( $this, 'sync_others_data' ), 10, 2 );

        //add_action( 'mainwp_child_after_update', array( $this, 'theme', 'auto_purge_cache'  ) );
        //add_action( 'mainwp_child_after_update', array( $this, 'plugin',  'auto_purge_cache' ) );
    }

    /**
     * Method sync_others_data()
     *
     * Sync others data settings.
     *
     * @param array $information Array containing the sync information.
     * @param array $data        Array containing the plugin data to be synced.
     *
     * @return array $information Array containing the sync information.
     */
    function sync_others_data( $information, $data = array() ) {
        if ( is_array( $data ) && isset( $data['auto_purge_cache'] ) ) {
            try {

                // Grab Auto Purge Data.
                update_option( 'mainwp_child_auto_purge_cache', ( $data['auto_purge_cache'] ? 1 : 0 ) );

            } catch ( \Exception $e ) {
                error_log( $e->getMessage() ); // phpcs:ignore -- debug mode only.
            }
        }
        return $information;
    }


    /**
     * WP-Rocket auto cache purge.
     *
     * Purge cache after updates.
     * @params $information.
     */
    public function auto_purge_cache( $information ){
        self::instance()->wprocket_auto_cache_purge( $information );
    }



    public function action( $cache_plugin ) {
        if ( $cache_plugin === 'wprocket' ){
            $this->mainwp_wprocket_cli_cache_purge();
        }
    }

    /**
     * Method mainwp_wprocket_cache_purge()
     *
     * Purge ALL WPRocket Cache by executing the WP-CLI command:
     *      wp rocket clean --confirm
     *
     * @return array Results array. 0|false
     */
    public function mainwp_wprocket_cli_cache_purge() {

//        tmp error reporting.
//        error_reporting(E_ALL | E_STRICT);
//        ini_set('display_errors', '1');


        //Redirect stdERR so we see warnings/errors
        $suffix = ' 2>&1';

        if (!defined('STDIN')) define('STDIN', fopen('php://stdin', 'r'));
        if (!defined('STDOUT')) define('STDOUT', fopen('php://stdout', 'w'));
        if (!defined('STDERR')) define('STDERR', fopen('php://stdout', 'w'));

        // grab Plugin directory.
        $wp_cli_phar = MAINWP_CHILD_PLUGIN_DIR . '/bin/wp-cli.phar';
        $output = array();
        $php_executable = PHP_BINDIR . '/php';
        $php_ini_path = php_ini_loaded_file();

        $executable = $php_executable . ' -c ' . $php_ini_path . ' -d error_reporting="E_ALL & ~E_NOTICE" -d memory_limit="2048M" -d max_execution_time=43200 ' . $wp_cli_phar . ' ';

        $command = 'rocket clean --confirm';
        $full_command = $executable . $command . $suffix;

        exec($full_command, $output, $return_var);

        return $purge_results = [
            'status' => $return_var,
            'output' => $output
        ];
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

        // Setup timezone and upload directory for logs.
        date_default_timezone_set(wp_timezone());
        $upload_dir = wp_get_upload_dir();
        $upload_dir = $upload_dir['basedir'];

        // Purge Cache if action is set to "1".
        $purge_result = array();
        $action = get_option( 'mainwp_child_auto_purge_cache', false );
        if ( $action == 1 ) {
            $purge_result = MainWP_Child_WP_Rocket::instance()->purge_cache_all();
        }

        // Create log file. Save in /Upload dir.
        // return SUCCESS or function_not_exist.
        if ( $purge_result['result'] === "SUCCESS" ){
            $information['cache_purge_action_result'] = "WP Rocket => Cache auto cleared on: (" . current_time('mysql') . ")";
            file_put_contents($upload_dir . "/last_purge_log.txt", $information['cache_purge_action_result']);
        } else {
            //$information['cache_purge_action_result'] = "WP Rocket => Failed to auto clear cache. (" . current_time('mysql') . ")";
            file_put_contents($upload_dir . "/last_purge_log.txt", json_encode($purge_result));
        }

        return $information;
    }
}

