<?php
/**
 * MainWP Child BackWP UP
 *
 * This file handles specific action for the MainWP BackWPup Extension.
 *
 * Credits
 *
 * Plugin-Name: BackWPup
 * Plugin-URI: http://backwpup.com
 * Author: Inpsyde GmbH
 * Author URI: http://inpsyde.com
 * License: GPLv3
 * License URI: http://www.gnu.org/licenses/gpl-3.0
 *
 * The code is used for the MainWP BackWPup Extension
 * Extension URL: https://mainwp.com/extension/backwpup/
 *
 * @package MainWP\Child
 */

// phpcs:disable -- third party credit.

namespace MainWP\Child;

/**
 * Class MainWP_Child_Back_WP_Up
 */
class MainWP_Child_Back_WP_Up {

    /**
     * @var bool Whether or not BackWPup plugin is installed. Default: false.
     */
    public $is_backwpup_installed = false;

    /**
     * @var bool Whether or not BackWPup is Pro version. Default: false.
     */
    public $is_backwpup_pro = false;

    /**
     * @var string Plugin slug translation string.
     */
    public $plugin_translate = 'mainwp-backwpup-extension';

    /**
     * @static
     * @var null Holds the Public static instance of MainWP_Child_Back_WP_Up
     */
    public static $instance = null;

    /** @var string Software version. */
    protected $software_version = '0.1';

    /** @var array Returned response array for MainWP BackWPup Extension actions. */
    public static $information = array();

    /** @var string[][] backwpup_cfg variables. */
    protected $exclusions = array(
        'cron'           => array(
            'cronminutes',
            'cronhours',
            'cronmday',
            'cronmon',
            'cronwday',
            'moncronminutes',
            'moncronhours',
            'moncronmday',
            'weekcronminutes',
            'weekcronhours',
            'weekcronwday',
            'daycronminutes',
            'daycronhours',
            'hourcronminutes',
            'cronbtype',
        ),
        'dest-EMAIL'     => array( 'emailpass' ),
        'dest-DBDUMP'    => array( 'dbdumpspecialsetalltables' ),
        'dest-FTP'       => array( 'ftppass' ),
        'dest-S3'        => array( 's3secretkey' ),
        'dest-MSAZURE'   => array( 'msazurekey' ),
        'dest-SUGARSYNC' => array( 'sugaremail', 'sugarpass', 'sugarrefreshtoken' ),
        'dest-GDRIVE'    => array( 'gdriverefreshtoken' ),
        'dest-RSC'       => array( 'rscapikey' ),
        'dest-GLACIER'   => array( 'glaciersecretkey' ),
    );

    /**
     * Create a public static instance of MainWP_Child_Back_WP_Up.
     *
     * @return MainWP_Child_Back_WP_Up|null
     */
    public static function instance() {
        if ( null == self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * MainWP_Child_Back_WP_Up constructor.
     *
     * Run any time the class is called.
     *
     * @uses \MainWP\Child\MainWP_Helper::check_files_exists()
     * @uses \MainWP\Child\MainWP_Helper::instance()->check_classes_exists()
     * @uses \MainWP\Child\MainWP_Helper::instance()->check_methods()
     * @uses \BackWPup::get_instance()
     * @uses \Exception
     */
    public function __construct() {

        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        try {

            if ( is_plugin_active( 'backwpup-pro/backwpup.php' ) && file_exists( plugin_dir_path( __FILE__ ) . '../../backwpup-pro/backwpup.php' ) ) {
                $file_path1 = plugin_dir_path( __FILE__ ) . '../../backwpup-pro/backwpup.php';
                $file_path2 = plugin_dir_path( __FILE__ ) . '../../backwpup-pro/inc/Pro/class-pro.php';

                if ( ! file_exists( $file_path2 ) ) {
                    $file_path2 = plugin_dir_path( __FILE__ ) . '../../backwpup-pro/inc/pro/class-pro.php';
                }

                MainWP_Helper::check_files_exists( array( $file_path1, $file_path2 ) );
                require_once $file_path1;
                require_once $file_path2;
                $this->is_backwpup_installed = true;
                $this->is_backwpup_pro       = true;
            } elseif ( is_plugin_active( 'backwpup/backwpup.php' ) && file_exists( plugin_dir_path( __FILE__ ) . '../../backwpup/backwpup.php' ) ) {
                $file_path = plugin_dir_path( __FILE__ ) . '../../backwpup/backwpup.php';
                MainWP_Helper::check_files_exists( array( $file_path ) );
                require_once $file_path;
                $this->is_backwpup_installed = true;
            }

            if ( $this->is_backwpup_installed ) {
                MainWP_Helper::instance()->check_classes_exists( '\BackWPup' );
                MainWP_Helper::instance()->check_methods( 'get_instance' );
                \BackWPup::get_instance();

                add_action( 'admin_init', array( $this, 'init_download_backup' ) );
                add_filter( 'mainwp_site_sync_others_data', array( $this, 'sync_others_data' ), 10, 2 );
            }
        } catch ( \Exception $e ) {
            $this->is_backwpup_installed = false;
        }
    }

    /**
     * MainWP BackWPup fatal error handler.
     *
     * @uses \MainWP\Child\MainWP_Child_Back_WP_Up::$information
     * @uses \MainWP\Child\MainWP_Helper::write()
     */
    public static function mainwp_backwpup_handle_fatal_error() {

        $error = error_get_last();
        $info  = self::$information;

        if ( isset( $error['type'] ) && E_ERROR === $error['type'] && isset( $error['message'] ) ) {
            MainWP_Helper::write( array( 'error' => 'MainWP_Child fatal error : ' . $error['message'] . ' Line: ' . $error['line'] . ' File: ' . $error['file'] ) );
        } elseif ( ! empty( $info ) ) {
            MainWP_Helper::write( self::$information );
        } else {
            MainWP_Helper::write( array( 'error' => 'Missing information array inside fatal_error' ) );
        }
    }

    /**
     * MainWP BackWPup Extension actions.
     *
     * @uses \MainWP\Child\MainWP_Child_Back_WP_Up::update_settings()
     * @uses \MainWP\Child\MainWP_Child_Back_WP_Up::insert_or_update_jobs()
     * @uses \MainWP\Child\MainWP_Child_Back_WP_Up::insert_or_update_jobs_global()
     * @uses \MainWP\Child\MainWP_Child_Back_WP_Up::get_child_tables()
     * @uses \MainWP\Child\MainWP_Child_Back_WP_Up::get_job_files()
     * @uses \MainWP\Child\MainWP_Child_Back_WP_Up::destination_email_check_email()
     * @uses \MainWP\Child\MainWP_Child_Back_WP_Up::backup_now()
     * @uses \MainWP\Child\MainWP_Child_Back_WP_Up::ajax_working()
     * @uses \MainWP\Child\MainWP_Child_Back_WP_Up::backup_abort()
     * @uses \MainWP\Child\MainWP_Child_Back_WP_Up::tables()
     * @uses \MainWP\Child\MainWP_Child_Back_WP_Up::view_log()
     * @uses \MainWP\Child\MainWP_Child_Back_WP_Up::delete_log()
     * @uses \MainWP\Child\MainWP_Child_Back_WP_Up::delete_job()
     * @uses \MainWP\Child\MainWP_Child_Back_WP_Up::delete_backup()
     * @uses \MainWP\Child\MainWP_Child_Back_WP_Up::wizard_system_scan()
     * @uses \MainWP\Child\MainWP_Child_Back_WP_Up::is_backwpup_pro()
     * @uses \MainWP\Child\MainWP_Child_Back_WP_Up::show_hide()
     * @uses \MainWP\Child\MainWP_Child_Back_WP_Up::$information
     * @uses \MainWP\Child\MainWP_Child_Back_WP_Up::mainwp_backwpup_handle_fatal_error()
     * @uses \MainWP\Child\MainWP_Helper::write()
     */
    public function action() {
        if ( ! $this->is_backwpup_installed ) {
            MainWP_Helper::write( array( 'error' => esc_html__( 'Please install BackWPup plugin on child website', 'mainwp-child' ) ) );
            return;
        }
        register_shutdown_function( '\MainWP\Child\MainWP_Child_Back_WP_Up::mainwp_backwpup_handle_fatal_error' );

        $information = array();

        if ( ! isset( $_POST['action'] ) ) {
            $information = array( 'error' => esc_html__( 'Missing action.', 'mainwp-child' ) );
        } else {
            $mwp_action = ! empty( $_POST['action'] ) ? sanitize_text_field( wp_unslash( $_POST['action'] ) ) : '';
            switch ( $mwp_action ) {
                case 'backwpup_update_settings':
                    $information = $this->update_settings();
                    break;

                case 'backwpup_insert_or_update_jobs':
                    $information = $this->insert_or_update_jobs();
                    break;

                case 'backwpup_insert_or_update_jobs_global':
                    $information = $this->insert_or_update_jobs_global();
                    break;

                case 'backwpup_get_child_tables':
                    $information = $this->get_child_tables();
                    break;

                case 'backwpup_get_job_files':
                    $information = $this->get_job_files();
                    break;

                case 'backwpup_destination_email_check_email':
                    $information = $this->destination_email_check_email();
                    break;

                case 'backwpup_backup_now':
                    $information = $this->backup_now();
                    break;

                case 'backwpup_ajax_working':
                    $information = $this->ajax_working();
                    break;

                case 'backwpup_backup_abort':
                    $information = $this->backup_abort();
                    break;

                case 'backwpup_tables':
                    $information = $this->tables();
                    break;

                case 'backwpup_view_log':
                    $information = $this->view_log();
                    break;

                case 'backwpup_delete_log':
                    $information = $this->delete_log();
                    break;

                case 'backwpup_delete_job':
                    $information = $this->delete_job();
                    break;

                case 'backwpup_delete_backup':
                    $information = $this->delete_backup();
                    break;

                case 'backwpup_information':
                    $information = $this->information();
                    break;

                case 'backwpup_wizard_system_scan':
                    $information = $this->wizard_system_scan();
                    break;

                case 'backwpup_is_pro':
                    $information = array( 'is_pro' => $this->is_backwpup_pro );
                    break;

                case 'backwpup_show_hide':
                    $information = $this->show_hide();
                    break;

                default:
                    $information = array( 'error' => esc_html__( 'Wrong action.', 'mainwp-child' ) );
            }
        }

        self::$information = $information;
        exit();
    }

    /**
     * MainWP BackWPup Extension initiation.
     */
    public function init() {

        if ( ! $this->is_backwpup_installed ) {
            return;
        }

        add_action( 'mainwp_child_site_stats', array( $this, 'do_site_stats' ) );

        if ( get_option( 'mainwp_backwpup_hide_plugin' ) === 'hide' ) {
            add_filter( 'all_plugins', array( $this, 'all_plugins' ) );
            add_action( 'admin_menu', array( $this, 'remove_menu' ) );
        }
    }

    /**
     * Record BackWPup MainWP Child Reports log.
     *
     * @uses MainWP_Child_Back_WP_Up::do_reports_log()
     */
    public function do_site_stats() {
        if ( has_action( 'mainwp_child_reports_log' ) ) {
            do_action( 'mainwp_child_reports_log', 'backwpup' );
        } else {
            $this->do_reports_log( 'backwpup' );
        }
    }

    /**
     * Create BackWPup MainWP Client Reports log.
     *
     * @param string $ext Extension to create log for.
     *
     * @uses \MainWP\Child\MainWP_Child_Back_WP_Up::is_backwpup_installed()
     * @uses \MainWP\Child\MainWP_Helper::instance()->check_classes_exists()
     * @uses \MainWP\Child\MainWP_Helper::instance()->check_methods()
     * @uses \MainWP\Child\MainWP_Utility::get_lasttime_backup()
     * @uses \MainWP\Child\MainWP_Utility::update_lasttime_backup()
     * @uses \BackWPup_File::get_absolute_path()
     * @uses \BackWPup_Job::read_logheader()
     * @uses \Exception
     */
    public function do_reports_log($ext = '' ) {
        if ( 'backwpup' !== $ext ) {
            return;
        }
        if ( ! $this->is_backwpup_installed ) {
            return;
        }

        try {

            MainWP_Helper::instance()->check_classes_exists( array( '\BackWPup_File', '\BackWPup_Job' ) );
            MainWP_Helper::instance()->check_methods( '\BackWPup_File', array( 'get_absolute_path' ) );
            MainWP_Helper::instance()->check_methods( '\BackWPup_Job', array( 'read_logheader' ) );
            $lasttime_logged = MainWP_Utility::get_lasttime_backup( 'backwpup' );
            $log_folder = get_site_option( 'backwpup_cfg_logfolder' );
            $log_folder = \BackWPup_File::get_absolute_path( $log_folder );
            $log_folder = untrailingslashit( $log_folder );

            $logfiles = array();
            $dir      = opendir( $log_folder );
            if ( is_readable( $log_folder ) && $dir ) {
                while ( ( $file = readdir( $dir ) ) !== false ) {
                    $log_file = $log_folder . '/' . $file;
                    if ( is_file( $log_file ) && is_readable( $log_file ) && false !== strpos( $file, 'backwpup_log_' ) && false !== strpos( $file, '.html' ) ) {
                        $logfiles[] = $file;
                    }
                }
                closedir( $dir );
            }

            $log_items = array();
            foreach ( $logfiles as $mtime => $logfile ) {
                $meta = \BackWPup_Job::read_logheader( $log_folder . '/' . $logfile );
                if ( ! isset( $meta['logtime'] ) || $meta['logtime'] < $lasttime_logged ) {
                    continue;
                }

                if ( isset( $meta['errors'] ) && ! empty( $meta['errors'] ) ) {
                    continue;
                }

                $log_items[ $mtime ]         = $meta;
                $log_items[ $mtime ]['file'] = $logfile;
            }

            if ( ! empty( $log_items ) ) {
                $job_types = array(
                    'DBDUMP'   => esc_html__( 'Database backup', 'mainwp-child' ),
                    'FILE'     => esc_html__( 'File backup', 'mainwp-child' ),
                    'WPEXP'    => esc_html__( 'WordPress XML export', 'mainwp-child' ),
                    'WPPLUGIN' => esc_html__( 'Installed plugins list', 'mainwp-child' ),
                    'DBCHECK'  => esc_html__( 'Check database tables', 'mainwp-child' ),
                );

                $new_lasttime_logged = $lasttime_logged;

                foreach ( $log_items as $log ) {
                    $backup_time = $log['logtime'];
                    if ( $backup_time < $lasttime_logged ) {
                        continue;
                    }
                    $job_job_types = explode( '+', $log['type'] );
                    $backup_type   = '';
                    foreach ( $job_job_types as $typeid ) {
                        if ( isset( $job_types[ $typeid ] ) ) {
                            $backup_type .= ' + ' . $job_types[ $typeid ];
                        }
                    }

                    if ( empty( $backup_type ) ) {
                        continue;
                    } else {
                        $backup_type = ltrim( $backup_type, ' + ' );
                    }
                    $message = 'BackWPup backup finished (' . $backup_type . ')';
                    do_action( 'mainwp_reports_backwpup_backup', $message, $backup_type, $backup_time );

                    if ( $new_lasttime_logged < $backup_time ) {
                        $new_lasttime_logged = $backup_time;
                    }
                }

                if ( $new_lasttime_logged > $lasttime_logged ) {
                    MainWP_Utility::update_lasttime_backup( 'backwpup', $new_lasttime_logged ); // to support backup before update feature.
                }
            }
        } catch ( \Exception $ex ) {
            // ok!
        }
    }

    /**
     * Sync other data from $data[] and merge with $information[]
     *
     * @param array $information Returned response array for MainWP BackWPup Extension actions.
     * @param array $data Other data to sync to $information array.
     *
     * @return array $information Returned information array with both sets of data.
     *
     * @uses \MainWP\Child\MainWP_Utility::get_lasttime_backup()
     */
    public function sync_others_data($information, $data = array() ) {
        if ( isset( $data['syncBackwpupData'] ) && $data['syncBackwpupData'] ) {
            try {
                $lastbackup                      = MainWP_Utility::get_lasttime_backup( 'backwpup' );
                $information['syncBackwpupData'] = array(
                    'lastbackup' => $lastbackup,
                );
            } catch ( \Exception $e ) {
                // ok!
            }
        }
        return $information;
    }

    /**
     * Get backup destinations list.
     *
     * @uses \MainWP\Child\MainWP_Helper::instance()->check_classes_exists()
     * @uses \MainWP\Child\MainWP_Helper::instance()->check_methods()
     * @uses \BackWPup_Option::get_job_ids()
     * @uses \BackWPup::get_registered_destinations()
     * @uses \BackWPup_Option::get()
     * @uses \BackWPup::get_destination()
     * @uses \BackWPup::get_destination::file_get_list()
     *
     * @return array $jobdest Backup destination list.
     * @throws Exception Error Message.
     */
    public function get_destinations_list() {
        MainWP_Helper::instance()->check_classes_exists( array( '\BackWPup', '\BackWPup_Option' ) );
        MainWP_Helper::instance()->check_methods( '\BackWPup', array( 'get_registered_destinations', 'get_destination' ) );
        MainWP_Helper::instance()->check_methods( '\BackWPup_Option', array( 'get_job_ids', 'get' ) );

        $jobdest      = array();
        $jobids       = \BackWPup_Option::get_job_ids();
        $destinations = \BackWPup::get_registered_destinations();
        foreach ( $jobids as $jobid ) {
            if ( \BackWPup_Option::get( $jobid, 'backuptype' ) === 'sync' ) {
                continue;
            }
            $dests = \BackWPup_Option::get( $jobid, 'destinations' );
            foreach ( $dests as $dest ) {
                if ( ! $destinations[ $dest ]['class'] ) {
                    continue;
                }

                $dest_class = \BackWPup::get_destination( $dest );
                if ( $dest_class && method_exists( $dest_class, 'file_get_list' ) ) {
                    $can_do_dest = $dest_class->file_get_list( $jobid . '_' . $dest );
                    if ( ! empty( $can_do_dest ) ) {
                        $jobdest[] = $jobid . '_' . $dest;
                    }
                }
            }
        }

        return $jobdest;
    }

    /**
     * Hide BackWPup Plugin from the WordPress Installed plugin list.
     *
     * @param array $plugins Installed plugins.
     * @return array $plugins Installed plugins without BackWPup Plugin on the list.
     */
    public function all_plugins( $plugins ) {
        foreach ( $plugins as $key => $value ) {
            $plugin_slug = basename( $key, '.php' );
            if ( 'backwpup' === $plugin_slug ) {
                unset( $plugins[ $key ] );
            }
        }

        return $plugins;
    }

    /**
     * Remove BackWPup Plugin from the WordPress Admin.
     */
    public function remove_menu() {

        /** @global object $submenu WordPress Submenu array.  */
        global $submenu;

        // Remove the WordPress Admin SubMenu.
        if ( isset( $submenu['backwpup'] ) ) {
            unset( $submenu['backwpup'] );
        }

        // Remove the WordPress Admin Page.
        remove_menu_page( 'backwpup' );

        // Create a WP Safe Redirect for the page URL.
        $pos = isset( $_SERVER['REQUEST_URI'] ) ? stripos( wp_unslash( $_SERVER['REQUEST_URI'] ), 'admin.php?page=backwpup' ) : false;
        if ( false !== $pos ) {
            wp_safe_redirect( get_option( 'siteurl' ) . '/wp-admin/index.php' );
            exit();
        }
    }

    /**
     * Show/Hide BackWPup Plugin.
     *
     * @return int[]|string Return 1 on HIDE, Empty string on SHOW.
     *
     * @uses MainWP_Helper::instance()::update_option()
     */
    protected function show_hide() {

        $hide = isset( $_POST['show_hide'] ) && ( '1' === $_POST['show_hide'] ) ? 'hide' : '';

        MainWP_Helper::instance()->update_option( 'mainwp_backwpup_hide_plugin', $hide, 'yes' );

        return array( 'success' => 1 );
    }

    /**
     * Build the MainWP BackWPup Page Settings.
     * @return array $output Returned information array.
     */
    protected function information() {

        /** @global $wpdb wpdb */
        global $wpdb;

        // Copied from BackWPup_Page_Settings.
        ob_start();
        echo '<table class="wp-list-table widefat fixed" cellspacing="0" style="width: 85%;margin-left:auto;;margin-right:auto;">';
        echo '<thead><tr><th width="35%">' . esc_html__( 'Setting', 'mainwp-child' ) . '</th><th>' . esc_html__( 'Value', 'mainwp-child' ) . '</th></tr></thead>';
        echo '<tfoot><tr><th>' . esc_html__( 'Setting', 'mainwp-child' ) . '</th><th>' . esc_html__( 'Value', 'mainwp-child' ) . '</th></tr></tfoot>';
        echo '<tr title="&gt;=3.2"><td>' . esc_html__( 'WordPress version', 'mainwp-child' ) . '</td><td>' . esc_html( \BackWPup::get_plugin_data( 'wp_version' ) ) . '</td></tr>';
        if ( ! class_exists( '\BackWPup_Pro', false ) ) {
            echo '<tr title=""><td>' . esc_html__( 'BackWPup version', 'mainwp-child' ) . '</td><td>' . esc_html( \BackWPup::get_plugin_data( 'Version' ) ) . ' <a href="' . esc_url( translate( \BackWPup::get_plugin_data( 'pluginuri' ), 'backwpup' ) ) . '">' . esc_html__( 'Get pro.', 'mainwp-child' ) . '</a></td></tr>';
        } else {
            echo '<tr title=""><td>' . esc_html__( 'BackWPup Pro version', 'mainwp-child' ) . '</td><td>' . esc_html( \BackWPup::get_plugin_data( 'Version' ) ) . '</td></tr>';
        }

        echo '<tr title="&gt;=5.3.3"><td>' . esc_html__( 'PHP version', 'mainwp-child' ) . '</td><td>' . esc_html( PHP_VERSION ) . '</td></tr>';
        echo '<tr title="&gt;=5.0.7"><td>' . esc_html__( 'MySQL version', 'mainwp-child' ) . '</td><td>' . esc_html( $wpdb->get_var( 'SELECT VERSION() AS version' ) ) . '</td></tr>';
        if ( function_exists( 'curl_version' ) ) {
            $curlversion = curl_version();
            echo '<tr title=""><td>' . esc_html__( 'cURL version', 'mainwp-child' ) . '</td><td>' . esc_html( $curlversion['version'] ) . '</td></tr>';
            echo '<tr title=""><td>' . esc_html__( 'cURL SSL version', 'mainwp-child' ) . '</td><td>' . esc_html( $curlversion['ssl_version'] ) . '</td></tr>';
        } else {
            echo '<tr title=""><td>' . esc_html__( 'cURL version', 'mainwp-child' ) . '</td><td>' . esc_html__( 'unavailable', 'mainwp-child' ) . '</td></tr>';
        }
        echo '<tr title=""><td>' . esc_html__( 'WP-Cron url:', 'mainwp-child' ) . '</td><td>' . esc_html( site_url( 'wp-cron.php' ) ) . '</td></tr>';

        echo '<tr><td>' . esc_html__( 'Server self connect:', 'mainwp-child' ) . '</td><td>';
        $raw_response = \BackWPup_Job::get_jobrun_url( 'test' );
        $test_result  = '';
        if ( is_wp_error( $raw_response ) ) {
            $test_result .= sprintf( esc_html__( 'The HTTP response test get an error "%s"', 'mainwp-child' ), esc_html( $raw_response->get_error_message() ) );
        } elseif ( 200 !== (int) wp_remote_retrieve_response_code( $raw_response ) && 204 !== (int) wp_remote_retrieve_response_code( $raw_response ) ) {
            $test_result .= sprintf( esc_html__( 'The HTTP response test get a false http status (%s)', 'mainwp-child' ), esc_html( wp_remote_retrieve_response_code( $raw_response ) ) );
        }
        $headers = wp_remote_retrieve_headers( $raw_response );
        if ( isset( $headers['x-backwpup-ver'] ) && \BackWPup::get_plugin_data( 'version' ) !== $headers['x-backwpup-ver'] ) {
            $test_result .= sprintf( esc_html__( 'The BackWPup HTTP response header returns a false value: "%s"', 'mainwp-child' ), esc_html( $headers['x-backwpup-ver'] ) );
        }

        if ( empty( $test_result ) ) {
            esc_html_e( 'Response Test O.K.', 'mainwp-child' );
        } else {
            echo esc_html( $test_result );
        }
        echo '</td></tr>';

        echo '<tr><td>' . esc_html__( 'Temp folder:', 'mainwp-child' ) . '</td><td>';
        if ( ! is_dir( \BackWPup::get_plugin_data( 'TEMP' ) ) ) {
            echo sprintf( esc_html__( 'Temp folder %s doesn\'t exist.', 'mainwp-child' ), esc_html( \BackWPup::get_plugin_data( 'TEMP' ) ) );
        } elseif ( ! is_writable( \BackWPup::get_plugin_data( 'TEMP' ) ) ) {
            echo sprintf( esc_html__( 'Temporary folder %s is not writable.', 'mainwp-child' ), esc_html( \BackWPup::get_plugin_data( 'TEMP' ) ) );
        } else {
            echo esc_html( \BackWPup::get_plugin_data( 'TEMP' ) );
        }
        echo '</td></tr>';

        echo '<tr><td>' . esc_html__( 'Log folder:', 'mainwp-child' ) . '</td><td>';

        $log_folder = \BackWPup_File::get_absolute_path( get_site_option( 'backwpup_cfg_logfolder' ) );

        if ( ! is_dir( $log_folder ) ) {
            echo sprintf( esc_html__( 'Logs folder %s not exist.', 'mainwp-child' ), esc_html( $log_folder ) );
        } elseif ( ! is_writable( $log_folder ) ) {
            echo sprintf( esc_html__( 'Log folder %s is not writable.', 'mainwp-child' ), esc_html( $log_folder ) );
        } else {
            echo esc_html( $log_folder );
        }
        echo '</td></tr>';
        echo '<tr title=""><td>' . esc_html__( 'Server', 'mainwp-child' ) . '</td><td>' . ( isset( $_SERVER['SERVER_SOFTWARE'] ) ? esc_html( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) ) : '' ) . '</td></tr>';
        echo '<tr title=""><td>' . esc_html__( 'Operating System', 'mainwp-child' ) . '</td><td>' . esc_html( PHP_OS ) . '</td></tr>';
        echo '<tr title=""><td>' . esc_html__( 'PHP SAPI', 'mainwp-child' ) . '</td><td>' . esc_html( PHP_SAPI ) . '</td></tr>';
        echo '<tr title=""><td>' . esc_html__( 'Current PHP user', 'mainwp-child' ) . '</td><td>' . esc_html( get_current_user() ) . '</td></tr>';
        $text = version_compare( phpversion(), '5.3.0' ) < 0 && (bool) ini_get( 'safe_mode' ) ? esc_html__( 'On', 'mainwp-child' ) : esc_html__( 'Off', 'mainwp-child' );
        echo '<tr title=""><td>' . esc_html__( 'Safe Mode', 'mainwp-child' ) . '</td><td>' . $text . '</td></tr>';
        echo '<tr title="&gt;=30"><td>' . esc_html__( 'Maximum execution time', 'mainwp-child' ) . '</td><td>' . ini_get( 'max_execution_time' ) . ' ' . esc_html__( 'seconds', 'mainwp-child' ) . '</td></tr>';
        if ( defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON ) {
            echo '<tr title="ALTERNATE_WP_CRON"><td>' . esc_html__( 'Alternative WP Cron', 'mainwp-child' ) . '</td><td>' . esc_html__( 'On', 'mainwp-child' ) . '</td></tr>';
        } else {
            echo '<tr title="ALTERNATE_WP_CRON"><td>' . esc_html__( 'Alternative WP Cron', 'mainwp-child' ) . '</td><td>' . esc_html__( 'Off', 'mainwp-child' ) . '</td></tr>';
        }
        if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
            echo '<tr title="DISABLE_WP_CRON"><td>' . esc_html__( 'Disabled WP Cron', 'mainwp-child' ) . '</td><td>' . esc_html__( 'On', 'mainwp-child' ) . '</td></tr>';
        } else {
            echo '<tr title="DISABLE_WP_CRON"><td>' . esc_html__( 'Disabled WP Cron', 'mainwp-child' ) . '</td><td>' . esc_html__( 'Off', 'mainwp-child' ) . '</td></tr>';
        }
        if ( defined( 'FS_CHMOD_DIR' ) ) {
            echo '<tr title="FS_CHMOD_DIR"><td>' . esc_html__( 'CHMOD Dir', 'mainwp-child' ) . '</td><td>' . FS_CHMOD_DIR . '</td></tr>';
        } else {
            echo '<tr title="FS_CHMOD_DIR"><td>' . esc_html__( 'CHMOD Dir', 'mainwp-child' ) . '</td><td>0755</td></tr>';
        }

        $now = localtime( time(), true );
        echo '<tr title=""><td>' . esc_html__( 'Server Time', 'mainwp-child' ) . '</td><td>' . esc_html( $now['tm_hour'] ) . ':' . esc_html( $now['tm_min'] ) . '</td></tr>';
        echo '<tr title=""><td>' . esc_html__( 'Blog Time', 'mainwp-child' ) . '</td><td>' . esc_html( date_i18n( 'H:i' ) ) . '</td></tr>';
        echo '<tr title=""><td>' . esc_html__( 'Blog Timezone', 'mainwp-child' ) . '</td><td>' . esc_html( get_option( 'timezone_string' ) ) . '</td></tr>';
        echo '<tr title=""><td>' . esc_html__( 'Blog Time offset', 'mainwp-child' ) . '</td><td>' . sprintf( esc_html__( '%s hours', 'mainwp-child' ), esc_html( get_option( 'gmt_offset' ) ) ) . '</td></tr>';
        echo '<tr title="WPLANG"><td>' . esc_html__( 'Blog language', 'mainwp-child' ) . '</td><td>' . esc_html( get_bloginfo( 'language' ) ) . '</td></tr>';
        echo '<tr title="utf8"><td>' . esc_html__( 'MySQL Client encoding', 'mainwp-child' ) . '</td><td>';
        echo defined( 'DB_CHARSET' ) ? esc_html( DB_CHARSET ) : '';
        echo '</td></tr>';
        echo '<tr title="URF-8"><td>' . esc_html__( 'Blog charset', 'mainwp-child' ) . '</td><td>' . esc_html( get_bloginfo( 'charset' ) ) . '</td></tr>';
        echo '<tr title="&gt;=128M"><td>' . esc_html__( 'PHP Memory limit', 'mainwp-child' ) . '</td><td>' . esc_html( ini_get( 'memory_limit' ) ) . '</td></tr>';
        echo '<tr title="WP_MEMORY_LIMIT"><td>' . esc_html__( 'WP memory limit', 'mainwp-child' ) . '</td><td>' . esc_html( WP_MEMORY_LIMIT ) . '</td></tr>';
        echo '<tr title="WP_MAX_MEMORY_LIMIT"><td>' . esc_html__( 'WP maximum memory limit', 'mainwp-child' ) . '</td><td>' . esc_html( WP_MAX_MEMORY_LIMIT ) . '</td></tr>';
        echo '<tr title=""><td>' . esc_html__( 'Memory in use', 'mainwp-child' ) . '</td><td>' . esc_html( size_format( memory_get_usage( true ), 2 ) ) . '</td></tr>';

        $disabled = ini_get( 'disable_functions' );
        if ( ! empty( $disabled ) ) {
            $disabledarry = explode( ',', $disabled );
            echo '<tr title=""><td>' . esc_html__( 'Disabled PHP Functions:', 'mainwp-child' ) . '</td><td>';
            echo esc_html( implode( ', ', $disabledarry ) );
            echo '</td></tr>';
        }

        echo '<tr title=""><td>' . esc_html__( 'Loaded PHP Extensions:', 'mainwp-child' ) . '</td><td>';
        $extensions = get_loaded_extensions();
        sort( $extensions );
        echo esc_html( implode( ', ', $extensions ) );
        echo '</td></tr>';
        echo '</table>';

        $output = ob_get_contents();

        ob_end_clean();

        return array(
            'success'  => 1,
            'response' => $output,
        );
    }

    /**
     * Delete BackWPup Log.
     *
     * @uses \BackWPup_File::get_absolute_path()
     *
     * @return int[]|string[] On success return success[1] & error[] message on failure.
     */
    protected function delete_log() {
        if ( ! isset( $_POST['settings']['logfile'] ) || ! is_array( $_POST['settings']['logfile'] ) ) {
            return array( 'error' => esc_html__( 'Missing logfile.', 'mainwp-child' ) );
        }

        $dir = get_site_option( 'backwpup_cfg_logfolder' );
        $dir = \BackWPup_File::get_absolute_path( $dir );

        foreach ( $_POST['settings']['logfile'] as $logfile ) {
            $logfile = basename( $logfile );

            if ( ! is_writeable( $dir ) ) {
                return array( 'error' => esc_html__( 'Directory not writable:', 'mainwp-child' ) . $dir );
            }
            if ( ! is_file( $dir . $logfile ) ) {
                return array( 'error' => esc_html__( 'Not file:', 'mainwp-child' ) . $dir . $logfile );
            }

            unlink( $dir . $logfile );

        }

        return array( 'success' => 1 );
    }

    /**
     * Delete backup job.
     *
     * @uses \BackWPup_Option::delete_job()
     *
     * @return array|int[]|string[] On success return success[1] & error['message'] on failure.
     */
    protected function delete_job() {
        if ( ! isset( $_POST['job_id'] ) ) {
            return array( 'error' => esc_html__( 'Missing job_id.', 'mainwp-child' ) );
        }

        $job_id = (int) $_POST['job_id'];

        wp_clear_scheduled_hook( 'backwpup_cron', array( 'id' => $job_id ) );
        if ( ! \BackWPup_Option::delete_job( $job_id ) ) {
            return array( 'error' => esc_html__( 'Cannot delete job', 'mainwp-child' ) );
        }

        return array( 'success' => 1 );
    }

    /**
     * Delete backup.
     *
     * @uses \BackWPup::get_destination()
     * @uses \BackWPup::get_destination::file_get_list()
     * @uses \BackWPup::get_destination::file_delete()
     *
     * @return array|int[]|string[] On success return success[1] response['DELETED'] & error['message'] on failure.
     */
    protected function delete_backup() {
        if ( ! isset( $_POST['settings']['backupfile'] ) ) {
            return array( 'error' => esc_html__( 'Missing backupfile.', 'mainwp-child' ) );
        }

        if ( ! isset( $_POST['settings']['dest'] ) ) {
            return array( 'error' => esc_html__( 'Missing dest.', 'mainwp-child' ) );
        }

        $backupfile = isset( $_POST['settings']['backupfile'] ) ? wp_unslash( $_POST['settings']['backupfile'] ) : '';
        $dest       = isset( $_POST['settings']['dest'] ) ? sanitize_text_field( wp_unslash( $_POST['settings']['dest'] ) ) : '';

        list( $dest_id, $dest_name ) = explode( '_', $dest );

        $dest_class = \BackWPup::get_destination( $dest_name );

        if ( is_null( $dest_class ) ) {
            return array( 'error' => esc_html__( 'Invalid dest class.', 'mainwp-child' ) );
        }

        $files = $dest_class->file_get_list( $dest );

        foreach ( $files as $file ) {
            if ( is_array( $file ) && $file['file'] == $backupfile ) {
                $dest_class->file_delete( $dest, $backupfile );

                return array(
                    'success'  => 1,
                    'response' => 'DELETED',
                );
            }
        }

        return array(
            'success'  => 1,
            'response' => 'Not found',
        );
    }

    /**
     * View BackWPup log.
     *
     * @return array|int[]|string[] On success return $output[] & error['message'] on failure.
     */
    protected function view_log() {
        if ( ! isset( $_POST['settings']['logfile'] ) ) {
            return array( 'error' => esc_html__( 'Missing logfile.', 'mainwp-child' ) );
        }

        $log_folder = get_site_option( 'backwpup_cfg_logfolder' );
        $log_folder = \BackWPup_File::get_absolute_path( $log_folder );
        $log_file   = $log_folder . basename( $_POST['settings']['logfile'] );

        if ( ! is_readable( $log_file ) && ! is_readable( $log_file . '.gz' ) && ! is_readable( $log_file . '.bz2' ) ) {
            $output = esc_html__( 'Log file doesn\'t exists', 'mainwp-child' );
        } else {
            if ( ! file_exists( $log_file ) && file_exists( $log_file . '.gz' ) ) {
                $log_file = $log_file . '.gz';
            }

            if ( ! file_exists( $log_file ) && file_exists( $log_file . '.bz2' ) ) {
                $log_file = $log_file . '.bz2';
            }

            if ( '.gz' == substr( $log_file, - 3 ) ) {
                $output = file_get_contents( 'compress.zlib://' . $log_file, false );
            } else {
                $output = file_get_contents( $log_file, false );
            }
        }

        return array(
            'success'  => 1,
            'response' => $output,
        );
    }

    /**
     * Build Tables.
     *
     * @uses MainWP_Child_Back_WP_Up::wp_list_table_dependency()
     * @uses \BackWPup_File::get_absolute_path()
     * @uses \BackWPup_Page_Logs()
     * @uses \BackWPup_Page_Backups()
     * @uses \BackWPup_Option::get_job_ids()
     * @uses \BackWPup_Option::get()
     * @uses \BackWPup::get_destination()
     * @uses \BackWPup::get_destination::file_get_list()
     * @uses \BackWPup_Option::get()
     *
     * @return array Return table array or error['message'] on failure.
     */
    protected function tables() {
        if ( ! isset( $_POST['settings']['type'] ) ) {
            return array( 'error' => esc_html__( 'Missing type.', 'mainwp-child' ) );
        }

        if ( ! isset( $_POST['settings']['website_id'] ) ) {
            return array( 'error' => esc_html__( 'Missing website id.', 'mainwp-child' ) );
        }

        $type       = isset( $_POST['settings']['type'] ) ? sanitize_text_field( wp_unslash( $_POST['settings']['type'] ) ) : '';
        $website_id = isset( $_POST['settings']['website_id'] ) ? sanitize_text_field( wp_unslash( $_POST['settings']['website_id'] ) ) : '';

        $this->wp_list_table_dependency();

        $array = array();

        switch ( $type ) {
            case 'logs':
                $log_folder = get_site_option( 'backwpup_cfg_logfolder' );
                $log_folder = \BackWPup_File::get_absolute_path( $log_folder );
                $log_folder = untrailingslashit( $log_folder );

                if ( ! is_dir( $log_folder ) ) {
                    return array(
                        'success'  => 1,
                        'response' => $array,
                    );
                }
                update_user_option( get_current_user_id(), 'backwpuplogs_per_page', 99999999 );
                $output = new \BackWPup_Page_Logs();
                $output->prepare_items();
                break;

            case 'backups':
                update_user_option( get_current_user_id(), 'backwpupbackups_per_page', 99999999 );
                $output        = new \BackWPup_Page_Backups();
                $output->items = array();

                $jobids = \BackWPup_Option::get_job_ids();

                if ( ! empty( $jobids ) ) {
                    foreach ( $jobids as $jobid ) {
                        if ( \BackWPup_Option::get( $jobid, 'backuptype' ) == 'sync' ) {
                            continue;
                        }

                        $dests = \BackWPup_Option::get( $jobid, 'destinations' );
                        foreach ( $dests as $dest ) {
                            $dest_class = \BackWPup::get_destination( $dest );
                            if ( is_null( $dest_class ) ) {
                                continue;
                            }
                            $items = $dest_class->file_get_list( $jobid . '_' . $dest );
                            if ( ! empty( $items ) ) {
                                foreach ( $items as $item ) {
                                    $temp_single_item            = $item;
                                    $temp_single_item['dest']    = $jobid . '_' . $dest;
                                    $temp_single_item['timeloc'] = sprintf( esc_html__( '%1$s at %2$s', 'mainwp-child' ), date_i18n( get_option( 'date_format' ), $temp_single_item['time'], true ), date_i18n( get_option( 'time_format' ), $temp_single_item['time'], true ) );
                                    $output->items[]             = $temp_single_item;
                                }
                            }
                        }
                    }
                }

                break;

            case 'jobs':
                $output = new \BackWPup_Page_Jobs();
                $output->prepare_items();
                break;
        }

        if ( is_array( $output->items ) ) {
            if ( 'jobs' == $type ) {
                foreach ( $output->items as $key => $val ) {
                    $temp_array                 = array();
                    $temp_array['id']           = $val;
                    $temp_array['name']         = \BackWPup_Option::get( $val, 'name' );
                    $temp_array['type']         = \BackWPup_Option::get( $val, 'type' );
                    $temp_array['destinations'] = \BackWPup_Option::get( $val, 'destinations' );

                    if ( $this->is_backwpup_pro ) {
                        $temp_array['export'] = str_replace( '&amp;', '&', wp_nonce_url( network_admin_url( 'admin.php' ) . '?page=backwpupjobs&action=export&jobs[]=' . $val, 'bulk-jobs' ) );
                    }

                    if ( \BackWPup_Option::get( $val, 'activetype' ) == 'wpcron' ) {
                        $nextrun = wp_next_scheduled( 'backwpup_cron', array( 'id' => $val ) );
                        if ( $nextrun + ( get_option( 'gmt_offset' ) * 3600 ) ) {
                            $temp_array['nextrun'] = sprintf( esc_html__( '%1$s at %2$s by WP-Cron', 'mainwp-child' ), date_i18n( get_option( 'date_format' ), $nextrun, true ), date_i18n( get_option( 'time_format' ), $nextrun, true ) );
                        } else {
                            $temp_array['nextrun'] = esc_html__( 'Not scheduled!', 'mainwp-child' );
                        }
                    } else {
                        $temp_array['nextrun'] = esc_html__( 'Inactive', 'mainwp-child' );
                    }
                    if ( \BackWPup_Option::get( $val, 'lastrun' ) ) {
                        $lastrun               = \BackWPup_Option::get( $val, 'lastrun' );
                        $temp_array['lastrun'] = sprintf( esc_html__( '%1$s at %2$s', 'mainwp-child' ), date_i18n( get_option( 'date_format' ), $lastrun, true ), date_i18n( get_option( 'time_format' ), $lastrun, true ) );
                        if ( \BackWPup_Option::get( $val, 'lastruntime' ) ) {
                            $temp_array['lastrun'] .= ' ' . sprintf( esc_html__( 'Runtime: %d seconds', 'mainwp-child' ), \BackWPup_Option::get( $val, 'lastruntime' ) );
                        }
                    } else {
                        $temp_array['lastrun'] = esc_html__( 'not yet', 'mainwp-child' );
                    }

                    $temp_array['website_id'] = $website_id;
                    $array[]                  = $temp_array;
                }
            } elseif ( 'backups' == $type ) {
                $without_dupes = array();
                foreach ( $output->items as $key ) {
                    $temp_array                = $key;
                    $temp_array['downloadurl'] = str_replace(
                        array(
                            '&amp;',
                            network_admin_url( 'admin.php' ) . '?page=backwpupbackups&action=',
                        ),
                        array(
                            '&',
                            admin_url( 'admin-ajax.php' ) . '?action=mainwp_backwpup_download_backup&type=',
                        ),
                        $temp_array['downloadurl'] . '&_wpnonce=' . $this->create_nonce_without_session( 'mainwp_download_backup' )
                    );

                    $temp_array['downloadurl_id'] = '/wp-admin/admin.php?page=backwpupbackups';
                    if ( preg_match( '/.*&jobid=([^&]+)&.*/is', $temp_array['downloadurl'], $matches ) ) {
                        if ( ! empty( $matches[1] ) && is_numeric( $matches[1] ) ) {
                            $temp_array['downloadurl_id'] .= '&download_click_id=' . $matches[1];
                        }
                    }

                    $temp_array['website_id'] = $website_id;

                    if ( ! isset( $without_dupes[ $temp_array['file'] ] ) ) {
                        $array[]                              = $temp_array;
                        $without_dupes[ $temp_array['file'] ] = 1;
                    }
                }
            } else {
                foreach ( $output->items as $key => $val ) {
                    $array[] = $val;
                }
            }
        }

        return array(
            'success'  => 1,
            'response' => $array,
        );
    }

    /**
     * Initiate download link.
     */
    public function init_download_backup() {
        if ( ! isset( $_GET['page'] ) || 'backwpupbackups' !== $_GET['page'] || ! isset( $_GET['download_click_id'] ) || empty( $_GET['download_click_id'] ) ) {
            return;
        }
        ?>
        <script type="text/javascript">
            document.addEventListener("DOMContentLoaded", function(event) {
                var downloadLink = document.querySelector( 'a.backup-download-link[data-jobid="<?php echo intval( $_GET['download_click_id'] ); ?>"' );
                if (typeof(downloadLink) !== 'undefined' && downloadLink !== null) {
                    downloadLink.click();
                }
            });
        </script>
        <?php
    }

    /**
     * Download backup.
     *
     * @uses MainWP_Child_Back_WP_Up::verify_nonce_without_session()
     * @uses \BackWPup::get_destination()
     * @uses \BackWPup::get_destination::file_download()
     */
    public function download_backup() {
        if ( ! isset( $_GET['type'] ) || empty( $_GET['type'] ) || ! isset( $_GET['_wpnonce'] ) || empty( $_GET['_wpnonce'] ) ) {
            die( '-1' );
        }

        if ( ! current_user_can( 'backwpup_backups_download' ) ) {
            die( '-2' );
        }

        if ( ! $this->verify_nonce_without_session( $_GET['_wpnonce'], 'mainwp_download_backup' ) ) {
            die( '-3' );
        }

        $dest = strtoupper( str_replace( 'download', '', $_GET['type'] ) );
        if ( ! empty( $dest ) && strstr( $_GET['type'], 'download' ) ) {
            $dest_class = \BackWPup::get_destination( $dest );
            if ( is_null( $dest_class ) ) {
                die( '-4' );
            }

            $dest_class->file_download( (int) $_GET['jobid'], $_GET['file'] );
        } else {
            die( '-5' );
        }

        die();
    }

    /**
     * Create security nounce without session.
     *
     * @param int $action Action performing.
     *
     * @return string|false Return nonce or FALSE on failure.
     */
    protected function create_nonce_without_session( $action = - 1 ) {
        $user = wp_get_current_user();
        $uid  = (int) $user->ID;
        if ( ! $uid ) {
            $uid = apply_filters( 'nonce_user_logged_out', $uid, $action );
        }

        $i = wp_nonce_tick();

        return substr( wp_hash( $i . '|' . $action . '|' . $uid, 'nonce' ), - 12, 10 );
    }

    /**
     * Verify nonce without session.
     *
     * @param string $nonce Nonce to verify.
     * @param int $action Action to perform.
     *
     * @return bool|int FALSE on failure. 1 or 2 on success.
     */
    protected function verify_nonce_without_session($nonce, $action = - 1 ) {
        $nonce = (string) $nonce;
        $user  = wp_get_current_user();
        $uid   = (int) $user->ID;
        if ( ! $uid ) {
            $uid = apply_filters( 'nonce_user_logged_out', $uid, $action );
        }

        if ( empty( $nonce ) ) {
            return false;
        }

        $i = wp_nonce_tick();

        $expected = substr( wp_hash( $i . '|' . $action . '|' . $uid, 'nonce' ), - 12, 10 );
        if ( hash_equals( $expected, $nonce ) ) {
            return 1;
        }

        $expected = substr( wp_hash( ( $i - 1 ) . '|' . $action . '|' . $uid, 'nonce' ), - 12, 10 );
        if ( hash_equals( $expected, $nonce ) ) {
            return 2;
        }

        return false;
    }

    /**
     * BackWPup Ajax Working.
     *
     * @uses MainWP_Child_Back_WP_Up::wp_list_table_dependency()
     * @uses \BackWPup_Page_Jobs::ajax_working()
     *
     * @return array Return success array[ success, response ]
     */
    protected function ajax_working() {

        if ( ! isset( $_POST['settings'] ) || ! is_array( $_POST['settings'] ) || ! isset( $_POST['settings']['logfile'] ) || ! isset( $_POST['settings']['logpos'] ) ) {
            return array( 'error' => esc_html__( 'Missing logfile or logpos.', 'mainwp-child' ) );
        }

        $_GET['logfile']      = isset( $_POST['settings']['logfile'] ) ? wp_unslash( $_POST['settings']['logfile'] ) : '';
        $_GET['logpos']       = isset( $_POST['settings']['logpos'] ) ? wp_unslash( $_POST['settings']['logpos'] ) : '';
        $_REQUEST['_wpnonce'] = wp_create_nonce( 'backwpupworking_ajax_nonce' );

        $this->wp_list_table_dependency();

        /**
         * MainWP BackWPup WP Die ajax handler.
         *
         * @param string $message Error message container.
         * @return string Error message.
         */
        function mainwp_backwpup_wp_die_ajax_handler( $message ) {
            return 'mainwp_backwpup_wp_die_ajax_handler';
        }

        // We do this in order to not die when using wp_die.
        if ( ! defined( 'DOING_AJAX' ) ) {

	        /**
	         * Checks whether ajax job is in progress.
	         *
	         * @const ( bool ) Default: true
	         * @source https://code-reference.mainwp.com/classes/MainWP.Child.MainWP_Child_Back_WP_Up.html
	         */
            define( 'DOING_AJAX', true );
        }

        add_filter( 'wp_die_ajax_handler', 'mainwp_backwpup_wp_die_ajax_handler' );
        remove_filter( 'wp_die_ajax_handler', '_ajax_wp_die_handler' );

        ob_start();
        \BackWPup_Page_Jobs::ajax_working();

        $output = ob_get_contents();

        ob_end_clean();

        return array(
            'success'  => 1,
            'response' => $output,
        );
    }

    /**
     * Backup now.
     *
     * @uses MainWP_Child_Back_WP_Up::wp_list_table_dependency()
     * @uses MainWP_Child_Back_WP_Up::check_backwpup_messages()
     * @uses \BackWPup_Page_Jobs::load()
     * @uses \BackWPup_Job::get_working_data()
     *
     * @return array Response array[ success, response, logfile ] or array[ error ]
     */
    protected function backup_now() {

        if ( ! isset( $_POST['settings']['job_id'] ) ) {
            return array( 'error' => esc_html__( 'Missing job_id', 'mainwp-child' ) );
        }

        // Simulate http://wp/wp-admin/admin.php?jobid=1&page=backwpupjobs&action=runnow.
        $_GET['jobid'] = isset( $_POST['settings']['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['settings']['job_id'] ) ) : '';

        $_REQUEST['action']   = 'runnow';
        $_REQUEST['_wpnonce'] = wp_create_nonce( 'backwpup_job_run-runnowlink' );

        update_site_option( 'backwpup_messages', array() );

        $this->wp_list_table_dependency();

        ob_start();
        \BackWPup_Page_Jobs::load();
        ob_end_clean();

        $output = $this->check_backwpup_messages();

        if ( isset( $output['error'] ) ) {
            return array( 'error' => '\BackWPup_Page_Jobs::load fail: ' . $output['error'] );
        } else {
            $job_object = \BackWPup_Job::get_working_data();
            if ( is_object( $job_object ) ) {
                return array(
                    'success'  => 1,
                    'response' => $output['message'],
                    'logfile'  => basename( $job_object->logfile ),
                );
            } else {
                return array(
                    'success'  => 1,
                    'response' => $output['message'],
                );
            }
        }
    }

    /**
     * Abort backup.
     *
     * @uses MainWP_Child_Back_WP_Up::wp_list_table_dependency()
     * @uses MainWP_Child_Back_WP_Up::check_backwpup_messages()
     * @uses \BackWPup_Page_Jobs::load()
     *
     * @return array|string[] Return array or error[message] on failure.
     */
    protected function backup_abort() {
        $_REQUEST['action']   = 'abort';
        $_REQUEST['_wpnonce'] = wp_create_nonce( 'abort-job' );

        update_site_option( 'backwpup_messages', array() );

        $this->wp_list_table_dependency();

        ob_start();
        \BackWPup_Page_Jobs::load();
        ob_end_clean();

        $output = $this->check_backwpup_messages();

        if ( isset( $output['error'] ) ) {
            return array( 'error' => 'Cannot abort: ' . $output['error'] );
        } else {
            return array(
                'success'  => 1,
                'response' => $output['message'],
            );
        }
    }

    /**
     * WordPress list table dependency.
     *
     * @uses MainWP_Child_Back_WP_Up::MainWP_Fake_Wp_Screen()
     */
    protected function wp_list_table_dependency() {
        mainwp_child_backwpup_wp_list_table_dependency();
        if ( ! class_exists( '\WP_List_Table' ) ) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
        }
    }

    /**
     * Wizard system scan.
     *
     * @uses \BackWPup_Pro_Wizard_SystemTest()
     *
     * @return array|string[] Return array or error[message] on failure.
     */
    protected function wizard_system_scan() {
        if ( class_exists( '\BackWPup_Pro_Wizard_SystemTest' ) ) {
            ob_start();

            $system_test = new \BackWPup_Pro_Wizard_SystemTest();
            $system_test->execute( null );

            $output = ob_get_contents();

            ob_end_clean();

            return array(
                'success'  => 1,
                'response' => $output,
            );
        } else {
            return array( 'error' => 'Missing BackWPup_Pro_Wizard_SystemTest' );
        }
    }

    /**
     * Check destination email.
     *
     * @uses PHPMailer()
     * @uses \BackWPup::get_plugin_data()
     * @uses \Swift_SmtpTransport::newInstance()
     * @uses \Swift_SendmailTransport::newInstance()
     * @uses \Swift_MailTransport::newInstance()
     * @uses \Swift_Mailer::newInstance()
     * @uses \Swift_Message::newInstance()
     * @uses \Exception
     *
     * @return array|\Exception Return response array.
     */
    protected function destination_email_check_email() {
        $settings = isset( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array();

        $message = '';

        $emailmethod   = ( isset( $settings['emailmethod'] ) ? $settings['emailmethod'] : '' );
        $emailsendmail = ( isset( $settings['emailsendmail'] ) ? $settings['emailsendmail'] : '' );
        $emailhost     = ( isset( $settings['emailhost'] ) ? $settings['emailhost'] : '' );
        $emailhostport = ( isset( $settings['emailhostport'] ) ? $settings['emailhostport'] : '' );
        $emailsecure   = ( isset( $settings['emailsecure'] ) ? $settings['emailsecure'] : '' );
        $emailuser     = ( isset( $settings['emailuser'] ) ? $settings['emailuser'] : '' );
        $emailpass     = ( isset( $settings['emailpass'] ) ? $settings['emailpass'] : '' );

        if ( ! isset( $settings['emailaddress'] ) || strlen( $settings['emailaddress'] ) < 2 ) {
            $message = esc_html__( 'Missing email address.', 'mainwp-child' );
        } else {
            if ( $emailmethod ) {

	            /**
	             * PHP mailer instance.
                 *
                 * @global object
	             */
                global $phpmailer;

                if ( ! is_object( $phpmailer ) || ! $phpmailer instanceof PHPMailer ) {                    
                    if ( file_exists( ABSPATH . WPINC . '/PHPMailer/PHPMailer.php' )) {
                        require ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
                        require ABSPATH . WPINC . '/PHPMailer/SMTP.php';
                        require ABSPATH . WPINC . '/PHPMailer/Exception.php';
                        $phpmailer = new \PHPMailer\PHPMailer\PHPMailer( true ); // phpcs:ignore -- to custom init PHP mailer                        
                    } elseif ( file_exists( ABSPATH . WPINC . '/class-phpmailer.php' ) ) {
                        require_once ABSPATH . WPINC . '/class-phpmailer.php';
                        require_once ABSPATH . WPINC . '/class-smtp.php';
                        $phpmailer = new \PHPMailer( true ); // phpcs:ignore -- to custom init PHP mailer
                    }
                }
                if ( is_object( $phpmailer ) ) {
                    do_action_ref_array( 'phpmailer_init', array( &$phpmailer ) );
                    $emailmethod   = $phpmailer->Mailer;
                    $emailsendmail = $phpmailer->Sendmail;
                    $emailhost     = $phpmailer->Host;
                    $emailhostport = $phpmailer->Port;
                    $emailsecure   = $phpmailer->SMTPSecure;
                    $emailuser     = $phpmailer->Username;
                    $emailpass     = $phpmailer->Password;
                }
            }

            if ( ! class_exists( '\Swift', false ) ) {
                require \BackWPup::get_plugin_data( 'plugindir' ) . '/vendor/SwiftMailer/swift_required.php';
            }

            if ( function_exists( 'mb_internal_encoding' ) && ( (int) ini_get( 'mbstring.func_overload' ) ) & 2 ) {
                $mbEncoding = mb_internal_encoding();
                mb_internal_encoding( 'ASCII' );
            }

            try {
                // Create the Transport.
                if ( 'smtp' == $emailmethod ) {
                    $transport = \Swift_SmtpTransport::newInstance( $emailhost, $emailhostport );
                    $transport->setUsername( $emailuser );
                    $transport->setPassword( $emailpass );
                    if ( 'ssl' == $emailsecure ) {
                        $transport->setEncryption( 'ssl' );
                    }
                    if ( 'tls' == $emailsecure ) {
                        $transport->setEncryption( 'tls' );
                    }
                } elseif ( 'sendmail' == $emailmethod ) {
                    $transport = \Swift_SendmailTransport::newInstance( $emailsendmail );
                } else {
                    $transport = \Swift_MailTransport::newInstance();
                }
                $emailer = \Swift_Mailer::newInstance( $transport );

                $message = \Swift_Message::newInstance( esc_html__( 'BackWPup archive sending TEST Message', 'mainwp-child' ) );
                $message->setFrom( array( ( isset( $settings['emailsndemail'] ) ? $settings['emailsndemail'] : 'from@example.com' ) => isset( $settings['emailsndemailname'] ) ? $settings['emailsndemailname'] : '' ) );
                $message->setTo( array( $settings['emailaddress'] ) );
                $message->setBody( esc_html__( 'If this message reaches your inbox, sending backup archives via email should work for you.', 'mainwp-child' ) );

                $result = $emailer->send( $message );
            } catch ( \Exception $e ) {
                $message = 'Swift Mailer: ' . $e->getMessage();
            }

            if ( isset( $mbEncoding ) ) {
                mb_internal_encoding( $mbEncoding );
            }

            if ( ! isset( $result ) || ! $result ) {
                $message = esc_html__( 'Error while sending email!', 'mainwp-child' );
            } else {
                $message = esc_html__( 'Email sent.', 'mainwp-child' );
            }
        }

        return array(
            'success' => 1,
            'message' => $message,
        );
    }

    /**
     * Get job files.
     *
     * @uses BackWPup_File::get_upload_dir()
     * @uses BackWPup_File::get_folder_size()
     * @return array Response array containing folder locations and size.
     */
    protected function get_job_files() {
        /**
         * Taken from BackWPup_JobType_File::get_exclude_dirs.
         *
         * @param array $folder Folders to exclude.
         * @return array Return folders list.
         */
        function mainwp_backwpup_get_exclude_dirs( $folder ) {
            $folder            = trailingslashit( str_replace( '\\', '/', realpath( $folder ) ) );
            $exclude_dir_array = array();

            if ( false !== strpos( trailingslashit( str_replace( '\\', '/', realpath( ABSPATH ) ) ), $folder ) && trailingslashit( str_replace( '\\', '/', realpath( ABSPATH ) ) ) != $folder ) {
                $exclude_dir_array[] = trailingslashit( str_replace( '\\', '/', realpath( ABSPATH ) ) );
            }
            if ( false !== strpos( trailingslashit( str_replace( '\\', '/', realpath( WP_CONTENT_DIR ) ) ), $folder ) && trailingslashit( str_replace( '\\', '/', realpath( WP_CONTENT_DIR ) ) ) != $folder ) {
                $exclude_dir_array[] = trailingslashit( str_replace( '\\', '/', realpath( WP_CONTENT_DIR ) ) );
            }
            if ( false !== strpos( trailingslashit( str_replace( '\\', '/', realpath( WP_PLUGIN_DIR ) ) ), $folder ) && trailingslashit( str_replace( '\\', '/', realpath( WP_PLUGIN_DIR ) ) ) != $folder ) {
                $exclude_dir_array[] = trailingslashit( str_replace( '\\', '/', realpath( WP_PLUGIN_DIR ) ) );
            }
            if ( false !== strpos( trailingslashit( str_replace( '\\', '/', realpath( get_theme_root() ) ) ), $folder ) && trailingslashit( str_replace( '\\', '/', realpath( get_theme_root() ) ) ) != $folder ) {
                $exclude_dir_array[] = trailingslashit( str_replace( '\\', '/', realpath( get_theme_root() ) ) );
            }
            if ( false !== strpos( trailingslashit( str_replace( '\\', '/', realpath( \BackWPup_File::get_upload_dir() ) ) ), $folder ) && trailingslashit( str_replace( '\\', '/', realpath( \BackWPup_File::get_upload_dir() ) ) ) != $folder ) {
                $exclude_dir_array[] = trailingslashit( str_replace( '\\', '/', realpath( \BackWPup_File::get_upload_dir() ) ) );
            }

            return array_unique( $exclude_dir_array );
        }

        $return = array();

        $folders = array(
            'abs'     => ABSPATH,
            'content' => WP_CONTENT_DIR,
            'plugin'  => WP_PLUGIN_DIR,
            'theme'   => get_theme_root(),
            'upload'  => \BackWPup_File::get_upload_dir(),
        );

        foreach ( $folders as $key => $folder ) {
            $return_temp      = array();
            $main_folder_name = realpath( $folder );

            if ( $main_folder_name ) {
                $main_folder_name = untrailingslashit( str_replace( '\\', '/', $main_folder_name ) );
                $main_folder_size = '(' . size_format( \BackWPup_File::get_folder_size( $main_folder_name, false ), 2 ) . ')';

                $dir = opendir( $main_folder_name );
                if ( $dir ) {
                    while ( false !== ( $file = readdir( $dir ) ) ) {
                        if ( ! in_array( $file, array( '.', '..' ) ) && is_dir( $main_folder_name . '/' . $file ) && ! in_array( trailingslashit( $main_folder_name . '/' . $file ), mainwp_backwpup_get_exclude_dirs( $main_folder_name ) ) ) {
                            $folder_size   = ' (' . size_format( \BackWPup_File::get_folder_size( $main_folder_name . '/' . $file ), 2 ) . ')';
                            $return_temp[] = array(
                                'size' => $folder_size,
                                'name' => $file,
                            );

                        }
                    }

                    closedir( $dir );
                }

                $return[ $key ] = array(
                    'size'    => $main_folder_size,
                    'name'    => $folder,
                    'folders' => $return_temp,
                );
            }
        }

        return array(
            'success' => 1,
            'folders' => $return,
        );
    }

    /**
     * Get Child Site Tables.
     *
     * @uses BackWPup_Option::get()
     *
     * @return array Query response containing the tables.
     */
    protected function get_child_tables() {

        /** @global $wpdb wpdb */
        global $wpdb;

        $return = array();

        $settings = isset( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array();

        if ( ! empty( $settings['dbhost'] ) && ! empty( $settings['dbuser'] ) ) {
            $mysqli = new \mysqli( $settings['dbhost'], $settings['dbuser'], ( isset( $settings['dbpassword'] ) ? $settings['dbpassword'] : '' ) ); // phpcs:ignore -- third party code.

            if ( $mysqli->connect_error ) {
                $return['message'] = $mysqli->connect_error;
            } else {
                if ( ! empty( $settings['dbname'] ) ) {
                    $res = $mysqli->query( 'SHOW FULL TABLES FROM `' . $mysqli->real_escape_string( $settings['dbname'] ) . '`' );
                    if ( $res ) {
                        $tables_temp = array();
                        while ( $table = $res->fetch_array( MYSQLI_NUM ) ) { // phpcs:ignore -- third party code.
                            $tables_temp[] = $table[0];
                        }

                        $res->close();
                        $return['tables'] = $tables_temp;
                    }
                }

                if ( empty( $settings['dbname'] ) || ! empty( $settings['first'] ) ) {
                    $res = $mysqli->query( 'SHOW DATABASES' );
                    if ( $res ) {
                        $databases_temp = array();
                        while ( $db = $res->fetch_array() ) {
                            $databases_temp[] = $db['Database'];
                        }

                        $res->close();
                        $return['databases'] = $databases_temp;
                    }
                }
            }
            $mysqli->close();
        } else {
            $tables_temp = array();

            $tables = $wpdb->get_results( 'SHOW FULL TABLES FROM `' . DB_NAME . '`', ARRAY_N ); // phpcs:ignore -- safe query.
            foreach ( $tables as $table ) {
                $tables_temp[] = $table[0];
            }

            $return['tables'] = $tables_temp;
        }

        if ( isset( $settings['job_id'] ) ) {
            $return['dbdumpexclude'] = \BackWPup_Option::get( $settings['job_id'], 'dbdumpexclude' );
        }
        return array(
            'success' => 1,
            'return'  => $return,
        );
    }

    /**
     * Insert or update global jobs.
     *
     * @uses MainWP_Child_Back_WP_Up::insert_or_update_jobs()
     *
     * @return array Response array containing job_id, changes & message array.
     */
    protected function insert_or_update_jobs_global() {
        $settings = isset( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array();

        if ( ! is_array( $settings ) ) {
            return array( 'error' => esc_html__( 'Missing array settings', 'mainwp-child' ) );
        }

        if ( ! isset( $settings['job_id'] ) ) {
            return array( 'error' => esc_html__( 'Missing job_id', 'mainwp-child' ) );
        }

        if ( $settings['job_id'] > 0 ) {
            $new_job_id = intval( $settings['job_id'] );
        } else {
            $new_job_id = null;
        }

        $changes_array = array();
        $message_array = array();

        foreach ( $settings['value'] as $key => $val ) {
            $temp_array          = array();
            $temp_array['tab']   = $key;
            $temp_array['value'] = $val;
            if ( ! is_null( $new_job_id ) ) {
                $temp_array['job_id'] = $new_job_id;
            } else {
                $temp_array['job_id'] = $settings['job_id'];
            }

            $_POST['settings'] = $temp_array;
            $return            = $this->insert_or_update_jobs();

            if ( is_null( $new_job_id ) ) {
                if ( ! isset( $return['job_id'] ) ) {
                    return array( 'error' => esc_html__( 'Missing new job_id', 'mainwp-child' ) );
                }

                $new_job_id = $return['job_id'];
            }

            // We want to exit gracefully.
            if ( isset( $return['error_message'] ) ) {
                $message_array[ $return['error_message'] ] = 1;
            }

            if ( isset( $return['changes'] ) ) {
                $changes_array = array_merge( $changes_array, $return['changes'] );
            }

            if ( isset( $return['message'] ) ) {
                foreach ( $return['message'] as $message ) {
                    if ( ! isset( $message_array[ $message ] ) ) {
                        $message_array[ $message ] = 1;
                    }
                }
            }
        }

        return array(
            'success' => 1,
            'job_id'  => $new_job_id,
            'changes' => $changes_array,
            'message' => array_keys( $message_array ),
        );
    }

    /**
     * Edit form post save.
     *
     * Parses & saves files, folders, boolean fields excluding any unwanted files or directories.
     *  [Taken from BackWPup_JobType_File::edit_form_post_save with some tweaks].
     *
     * @uses BackWPup_Option::update()
     *
     * @param $post_data Post data to save.
     * @param $id Post ID.
     */
    public function edit_form_post_save($post_data, $id ) {
        // Parse and save files to exclude.
        $exclude_input   = $post_data['fileexclude'];
        $to_exclude_list = $exclude_input ? str_replace( array( "\r\n", "\r" ), ',', $exclude_input ) : array();
        if ( $to_exclude_list ) {
            $to_exclude_list = sanitize_text_field( stripslashes( $to_exclude_list ) );
        }
        $to_exclude        = $to_exclude_list ? explode( ',', $to_exclude_list ) : array();
        $to_exclude_parsed = array();
        foreach ( $to_exclude as $key => $value ) {
            $normalized = wp_normalize_path( trim( $value ) );
            if ( $normalized ) {
                $to_exclude_parsed[ $key ] = $normalized;
            }
        }
        sort( $to_exclude_parsed );
        \BackWPup_Option::update( $id, 'fileexclude', implode( ',', $to_exclude_parsed ) );
        unset( $exclude_input, $to_exclude_list, $to_exclude, $to_exclude_parsed, $normalized );

        // Parse and save folders to include.
        $include_input     = $post_data['dirinclude'];
        $include_list      = $include_input ? str_replace( array( "\r\n", "\r" ), ',', $include_input ) : array();
        $to_include        = $include_list ? explode( ',', $include_list ) : array();
        $to_include_parsed = array();
        foreach ( $to_include as $key => $value ) {
            $normalized = trailingslashit( wp_normalize_path( trim( $value ) ) );
            if ( $normalized ) {
                $normalized = filter_var( $normalized, FILTER_SANITIZE_URL );
            }
            $realpath = $normalized && '/' !== $normalized ? realpath( $normalized ) : false;
            if ( $realpath ) {
                $to_include_parsed[ $key ] = $realpath;
            }
        }
        sort( $to_include_parsed );
        \BackWPup_Option::update( $id, 'dirinclude', implode( ',', $to_include_parsed ) );
        unset( $include_input, $include_list, $to_include, $to_include_parsed, $normalized, $realpath );

        // Parse and save boolean fields.
        $boolean_fields_def = array(
            'backupexcludethumbs' => FILTER_VALIDATE_BOOLEAN,
            'backupspecialfiles'  => FILTER_VALIDATE_BOOLEAN,
            'backuproot'          => FILTER_VALIDATE_BOOLEAN,
            'backupabsfolderup'   => FILTER_VALIDATE_BOOLEAN,
            'backupcontent'       => FILTER_VALIDATE_BOOLEAN,
            'backupplugins'       => FILTER_VALIDATE_BOOLEAN,
            'backupthemes'        => FILTER_VALIDATE_BOOLEAN,
            'backupuploads'       => FILTER_VALIDATE_BOOLEAN,
        );

        foreach ( $boolean_fields_def as $key => $value ) {
            \BackWPup_Option::update( $id, $key, ! empty( $post_data[ $key ] ) );
        }
        // Parse and save directories to exclude.
        $exclude_dirs_def = array(
            'backuprootexcludedirs'    => array(
                'filter' => FILTER_SANITIZE_URL,
                'flags'  => FILTER_FORCE_ARRAY,
            ),
            'backuppluginsexcludedirs' => array(
                'filter' => FILTER_SANITIZE_URL,
                'flags'  => FILTER_FORCE_ARRAY,
            ),
            'backupcontentexcludedirs' => array(
                'filter' => FILTER_SANITIZE_URL,
                'flags'  => FILTER_FORCE_ARRAY,
            ),
            'backupthemesexcludedirs'  => array(
                'filter' => FILTER_SANITIZE_URL,
                'flags'  => FILTER_FORCE_ARRAY,
            ),
            'backupuploadsexcludedirs' => array(
                'filter' => FILTER_SANITIZE_URL,
                'flags'  => FILTER_FORCE_ARRAY,
            ),
        );
        foreach ( $exclude_dirs_def as $key => $filter ) {
            $value = ! empty( $post_data[ $key ] ) && is_array( $post_data[ $key ] ) ? $post_data[ $key ] : array();
            \BackWPup_Option::update( $id, $key, $value );
        }
    }

    /**
     * Insert or update jobs.
     *
     * @uses BackWPup_Option::get_job_ids()
     * @uses BackWPup_Option::get()
     * @uses BackWPup_Option::update()
     * @uses BackWPup_Admin::get_messages()
     * @uses BackWPup_Admin::message()
     * @uses BackWPup_Job::get_jobrun_url()
     * @uses BackWPup_Page_Editjob::save_post_form()
     * @uses MainWP_Child_Back_WP_Up::edit_form_post_save()
     * @uses MainWP_Child_Back_WP_Up::check_backwpup_messages()
     *
     * @return array Response array containing job_id, changes & message array.
     */
    protected function insert_or_update_jobs() {

        $settings = isset( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array();

        if ( ! is_array( $settings ) || ! isset( $settings['value'] ) ) {
            return array( 'error' => esc_html__( 'Missing array settings', 'mainwp-child' ) );
        }

        if ( ! isset( $settings['tab'] ) ) {
            return array( 'error' => esc_html__( 'Missing tab', 'mainwp-child' ) );
        }

        if ( ! isset( $settings['job_id'] ) ) {
            return array( 'error' => esc_html__( 'Missing job_id', 'mainwp-child' ) );
        }

        if ( ! class_exists( '\BackWPup' ) ) {
            return array( 'error' => esc_html__( 'Install BackWPup on child website', 'mainwp-child' ) );
        }

        if ( $settings['job_id'] > 0 ) {
            $job_id = intval( $settings['job_id'] );
        } else {
            // generate jobid if not exists.
            $newjobid = \BackWPup_Option::get_job_ids();
            sort( $newjobid );
            $job_id = end( $newjobid ) + 1;
        }

        update_site_option( 'backwpup_messages', array() );

        if ( isset( $settings['value']['backupdir'] ) && empty( $settings['value']['backupdir'] ) ) {
            $backupdir = \BackWPup_Option::get( (int) $job_id, 'backupdir' );
            if ( ! empty( $backupdir ) ) {
                $settings['value']['backupdir'] = $backupdir;
            }
        }

        foreach ( $settings['value'] as $key => $val ) {
            $_POST[ $key ] = $val;
        }

        if ( 'jobtype-FILE' == $settings['tab'] ) {
            $this->edit_form_post_save( $settings['value'], $job_id );
            $messages = \BackWPup_Admin::get_messages();
            if ( empty( $messages['error'] ) ) {
                $url = \BackWPup_Job::get_jobrun_url( 'runnowlink', $job_id );
                \BackWPup_Admin::message( sprintf( esc_html__( 'Changes for job <i>%s</i> saved.', 'mainwp-child' ), \BackWPup_Option::get( $job_id, 'name' ) ) . ' <a href="' . network_admin_url( 'admin.php' ) . '?page=backwpupjobs">' . esc_html__( 'Jobs overview', 'mainwp-child' ) . '</a> | <a href="' . $url['url'] . '">' . esc_html__( 'Run now', 'mainwp-child' ) . '</a>' );
            }
        } elseif ( 'dest-DROPBOX' == $settings['tab'] ) {
            unset( $settings['value'] );
            \BackWPup_Page_Editjob::save_post_form( $settings['tab'], $job_id );
        } else {
            \BackWPup_Page_Editjob::save_post_form( $settings['tab'], $job_id );
        }

        $return = $this->check_backwpup_messages();

        if ( isset( $return['error'] ) ) {
            return array(
                'success'       => 1,
                'error_message' => esc_html__( 'Cannot save jobs: ' . $return['error'], 'mainwp-child' ),
            );
        }

        if ( isset( $settings['value']['sugarrefreshtoken'] ) ) {
            \BackWPup_Option::update( $job_id, 'sugarrefreshtoken', $settings['value']['sugarrefreshtoken'] );
        }

        if ( isset( $settings['value']['gdriverefreshtoken'] ) ) {
            \BackWPup_Option::update( $job_id, 'gdriverefreshtoken', $settings['value']['gdriverefreshtoken'] );
        }

        if ( isset( $settings['value']['dbdumpspecialsetalltables'] ) && $settings['value']['dbdumpspecialsetalltables'] ) {
            \BackWPup_Option::update( $job_id, 'dbdumpexclude', array() );
        }

        if ( isset( $settings['value']['dropboxtoken'] ) && isset( $settings['value']['dropboxroot'] ) ) {
            \BackWPup_Option::update( $job_id, 'dropboxtoken', $settings['value']['dropboxtoken'] );
            \BackWPup_Option::update( $job_id, 'dropboxroot', $settings['value']['dropboxroot'] );
        }

        $changes_array = array();

        foreach ( $settings['value'] as $key => $val ) {
            $temp_value = \BackWPup_Option::get( $job_id, $key );
            if ( is_string( $temp_value ) ) {
                if ( isset( $this->exclusions[ $settings['tab'] ] ) ) {
                    if ( ! in_array( $key, $this->exclusions[ $settings['tab'] ] ) && strcmp( $temp_value, $val ) != 0 ) {
                        $changes_array[ $key ] = $temp_value;
                    }
                } elseif ( strcmp( $temp_value, $val ) != 0 ) {
                    $changes_array[ $key ] = $temp_value;
                }
            }
        }

        return array(
            'success' => 1,
            'job_id'  => $job_id,
            'changes' => $changes_array,
            'message' => $return['message'],
        );
    }


    /**
     * Update settings.
     *
     * @uses BackWPup_Page_Settings()
     * @uses BackWPup_Page_Settings::save_post_form()
     * @uses BackWPup_Pro_Settings_APIKeys::get_instance()
     * @uses BackWPup_Pro_Settings_APIKeys::save_form()
     * @uses MainWP_Child_Back_WP_Up::check_backwpup_messages()
     *
     * @return array Response array success, changes, message[].
     */
    protected function update_settings() {
        $settings = isset( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array();

        if ( ! is_array( $settings ) || ! isset( $settings['value'] ) ) {
            return array( 'error' => esc_html__( 'Missing array settings', 'mainwp-child' ) );
        }

        if ( ! class_exists( '\BackWPup' ) ) {
            return array( 'error' => esc_html__( 'Install BackWPup on child website', 'mainwp-child' ) );
        }

        if ( isset( $settings['value']['is_premium'] ) && 1 == $settings['value']['is_premium'] && false == $this->is_backwpup_pro ) {
            return array( 'error' => esc_html__( 'You try to use pro version settings in non pro plugin version. Please install pro version on child and try again.', 'mainwp-child' ) );
        }

        foreach ( $settings['value'] as $key => $val ) {
            $_POST[ $key ] = $val;
        }

        update_site_option( 'backwpup_messages', array() );

        $settings_views    = array();
        $settings_updaters = array();

        $backwpup = new \BackWPup_Page_Settings( $settings_views, $settings_updaters );
        $backwpup->save_post_form();

        if ( class_exists( '\BackWPup_Pro' ) ) {
            $pro_settings = \BackWPup_Pro_Settings_APIKeys::get_instance();
            $pro_settings->save_form();

        }
        $return = $this->check_backwpup_messages();

        if ( isset( $return['error'] ) ) {
            return array( 'error' => esc_html__( 'Cannot save settings: ' . $return['error'], 'mainwp-child' ) );
        }

        $exclusions = array(
            'is_premium',
            'dropboxappsecret',
            'dropboxsandboxappsecret',
            'sugarsyncsecret',
            'googleclientsecret',
            'override',
            'httpauthpassword',
        );

        $changes_array = array();

        foreach ( $settings['value'] as $key => $val ) {

            $temp_value = get_site_option( 'backwpup_cfg_' . $key, '' );
            if ( ! in_array( $key, $exclusions ) && strcmp( $temp_value, $val ) != 0 ) {
                $changes_array[ $key ] = $temp_value;
            }
        }

        return array(
            'success' => 1,
            'changes' => $changes_array,
            'message' => $return['message'],
        );
    }

    /**
     * Check BackWPup Message.
     *
     * @return array|string[] Returns an empty array or Error[], Message[].
     */
    protected function check_backwpup_messages() {
        $message = get_site_option( 'backwpup_messages', array() );
        update_site_option( 'backwpup_messages', array() );

        if ( isset( $message['error'] ) ) {
            return array( 'error' => implode( ', ', $message['error'] ) );
        } elseif ( isset( $message['updated'] ) ) {
            return array( 'message' => $message['updated'] );
        } else {
            return array( 'error' => 'Generic error' );
        }
    }
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- fake class
if ( ! class_exists( '\MainWP\Child\MainWP_Fake_Wp_Screen' ) ) {
    /**
     * Class MainWP_Fake_Wp_Screen
     *
     * @used-by MainWP_Child_Back_WP_Up::wp_list_table_dependency()
     */
    class MainWP_Fake_Wp_Screen {
        /** @var string Action. */
        public $action;
        /** @var string Base url. */
        public $base;
        /** @var int ID*/
        public $id;
    }
}
