<?php
/**
 * MainWP Child AAM.
 *
 * MainWP AAM Extension handler.
 *
 * @link https://aamportal.com/integration/mainwp
 *
 * @package MainWP\Child
 *
 * Credits
 *
 * Plugin-Name: Advanced Access Manager
 * Plugin URI: https://wordpress.org/plugins/advanced-access-manager/
 * Author: Vasyltech
 * Author URI: https://vasyltech.com/
 *
 * The code is used for the MainWP AAM Extension
 * Extension URL: https://aamportal.com/integration/mainwp
 */

namespace MainWP\Child;

use AAM_Service_SecurityAudit;

// phpcs:disable PSR1.Classes.ClassDeclaration, WordPress.WP.AlternativeFunctions -- Required to achieve desired results. Pull requests appreciated.

/**
 * Class MainWP_Child_Aam
 *
 * MainWP Staging Extension handler.
 */
class MainWP_Child_Aam {

    /**
     * Public static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    public static $instance = null;

    /**
     * Public variable to hold the information if the WP Staging plugin is installed on the child site.
     *
     * @var bool If WP Staging installed, return true, if not, return false.
     */
    public $is_plugin_installed = false;

    /**
     * Public variable to hold the information if the WP Staging plugin is installed on the child site.
     *
     * @var string version string.
     */
    public $plugin_version = false;

    /**
     * Create a public static instance of MainWP_Child_Aam.
     *
     * @return MainWP_Child_Aam
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Initialize hooks
     *
     * @return void
     * @access public
     */
    public function init() {
        add_filter( 'mainwp_site_sync_others_data', array( $this, 'sync_others_data' ), 10, 2 );
    }

    /**
     * MainWP_Child_Aam constructor.
     *
     * Run any time class is called.
     */
    public function __construct() {
        require_once ABSPATH . 'wp-admin/includes/plugin.php'; // NOSONAR - WP compatible.

        if ( is_plugin_active( 'advanced-access-manager/aam.php' ) && defined( 'AAM_KEY' ) ) {
            $this->is_plugin_installed = true;
        }
    }

    /**
     * Sync others data.
     *
     * Get an array of available clones of this Child Sites.
     *
     * @param array $information Holder for available clones.
     * @param array $data Array of existing clones.
     *
     * @return array $information An array of available clones.
     */
    public function sync_others_data( $information, $data = array() ) {
        if ( ! empty( $data['aam'] ) && class_exists( '\AAM_Service_SecurityAudit' ) ) {
            try {
                $aam_info = array();

                // Get list of data point we would like to fetch.
                foreach ( $data['aam'] as $data_point ) {
                    $method = 'fetch_' . $data_point;

                    if ( method_exists( $this, $method ) ) {
                        $aam_info[ $data_point ] = $this->{$method}();
                    }
                }

                $information['aam'] = $aam_info;
            } catch ( MainWP_Exception $e ) {
                // error!
            }
        }

        return $information;
    }

    /**
     * Get security audit score
     *
     * @return int|null
     * @access protected
     */
    protected function fetch_security_score() {
        return get_option( AAM_Service_SecurityAudit::DB_SCOPE_OPTION, null );
    }

    /**
     * Get report summary
     *
     * @return array
     * @access protected
     */
    protected function fetch_issues_summary() {
        $result = array();
        $report = get_option( AAM_Service_SecurityAudit::DB_OPTION, array() );

        if ( is_array( $report ) ) {
            $result = array(
                'error'    => 0,
                'notice'   => 0,
                'warning'  => 0,
                'critical' => 0,
            );

            foreach ( $report as $group ) {
                foreach ( $group['issues'] as $issue ) {
                    ++$result[ $issue['type'] ];
                }
            }
        }

        return $result;
    }
}
