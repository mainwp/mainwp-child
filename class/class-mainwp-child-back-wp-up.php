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

namespace MainWP\Child;

/**
 * Class MainWP_Child_Back_WP_Up
 */
class MainWP_Child_Back_WP_Up { //phpcs:ignore -- NOSONAR - multi methods.

    /**
     * Whether or not BackWPup plugin is installed
     *
     * @var bool  Default: false
     */
    public $is_backwpup_installed = false;

    /**
     * Whether or not BackWPup is Pro version
     *
     * @var bool Default: false.
     */
    public $is_backwpup_pro = false;

    /**
     * Plugin slug translation string
     *
     * @var string plugin translate.
     */
    public $plugin_translate = 'mainwp-backwpup-extension';

    /**
     * Holds the Public static instance of MainWP_Child_Back_WP_Up
     *
     * @var null
     */
    public static $instance = null;

    /**
     * Software version
     *
     * @var string
     */
    protected $software_version = '0.1';

    /**
     * MainWP BackWPup Extension actions
     *
     * @var array Returned response array.
     */
    public static $information = array();

    /**
     * Exclusions array
     *
     * @var array
     */
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
     * Setting data.
     *
     * @var array
     */
    protected $setting_data = array();

    /**
     * Create a public static instance of MainWP_Child_Back_WP_Up.
     *
     * @return MainWP_Child_Back_WP_Up|null
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }

        return static::$instance;
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
     * @uses MainWP_Exception
     */
    public function __construct() {

        require_once ABSPATH . 'wp-admin/includes/plugin.php'; // NOSONAR - WP compatible.

        try {

            if ( is_plugin_active( 'backwpup-pro/backwpup.php' ) && file_exists( plugin_dir_path( __FILE__ ) . '../../backwpup-pro/backwpup.php' ) ) {
                $file_path1 = plugin_dir_path( __FILE__ ) . '../../backwpup-pro/backwpup.php';
                $file_path2 = plugin_dir_path( __FILE__ ) . '../../backwpup-pro/inc/Pro/class-pro.php';

                if ( ! file_exists( $file_path2 ) ) {
                    $file_path2 = plugin_dir_path( __FILE__ ) . '../../backwpup-pro/inc/pro/class-pro.php';
                }

                MainWP_Helper::check_files_exists( array( $file_path1, $file_path2 ) );
                require_once $file_path1; // NOSONAR - WP compatible.
                require_once $file_path2; // NOSONAR - WP compatible.
                $this->is_backwpup_installed = true;
                $this->is_backwpup_pro       = true;
            } elseif ( is_plugin_active( 'backwpup/backwpup.php' ) && file_exists( plugin_dir_path( __FILE__ ) . '../../backwpup/backwpup.php' ) ) {
                $file_path = plugin_dir_path( __FILE__ ) . '../../backwpup/backwpup.php';
                MainWP_Helper::check_files_exists( array( $file_path ) );
                require_once $file_path; // NOSONAR - WP compatible.
                $this->is_backwpup_installed = true;
            }

            if ( $this->is_backwpup_installed ) {
                MainWP_Helper::instance()->check_classes_exists( '\BackWPup' );

                // Check the backwpup version and use the appropriate method.
                $this->init_backwpup_instance();

                add_action( 'admin_init', array( $this, 'init_download_backup' ) );
                add_filter( 'mainwp_site_sync_others_data', array( $this, 'sync_others_data' ), 10, 2 );
            }
        } catch ( MainWP_Exception $e ) {
            $this->is_backwpup_installed = false;
        }
    }

    /**
     * Starting an instance backwpup based on the version
     */
    private function init_backwpup_instance() {
        // Check to see if the backwpup version has a get_instance method.
        if ( method_exists( '\BackWPup', 'get_instance' ) ) {
            try {
                // The old version uses get_instance.
                \BackWPup::get_instance();
            } catch ( \Exception $e ) {
                // Processing exceptions if any.
            }
        } else {  // phpcs:ignore -- NOSONAR
            // The new version does not use get_instance.
            // Check if the backwpup has been initialized.
            if ( class_exists( '\BackWPup' ) && ! defined( 'BACKWPUP_INITIALIZED' ) ) {
                define( 'BACKWPUP_INITIALIZED', true );
            }
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
        $info  = static::$information;

        if ( isset( $error['type'] ) && E_ERROR === $error['type'] && isset( $error['message'] ) ) {
            MainWP_Helper::write( array( 'error' => 'MainWP_Child fatal error : ' . $error['message'] . ' Line: ' . $error['line'] . ' File: ' . $error['file'] ) );
        } elseif ( ! empty( $info ) ) {
            MainWP_Helper::write( static::$information );
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
        $action      = ! empty( $_POST['action'] ) ? sanitize_text_field( wp_unslash( $_POST['action'] ) ) : '';  // phpcs:ignore -- NOSONAR

        if ( empty( $action ) ) {
            $information = array( 'error' => esc_html__( 'Missing action.', 'mainwp-child' ) );
        } else {
            $mwp_action = MainWP_System::instance()->validate_params( 'action' );
            switch ( $mwp_action ) {
                case 'backwpup_update_settings':
                    $information = $this->update_settings();
                    break;
                case 'insert_or_update_jobs':
                    $information = $this->insert_or_update_jobs();
                    break;
                case 'insert_or_update_jobs_global':
                    $information = $this->insert_or_update_jobs_global();
                    break;
                case 'get_child_tables':
                    $information = $this->get_child_tables();
                    break;
                case 'get_job_files':
                    $information = $this->get_job_files();
                    break;
                case 'backwpup_destination_email_check_email':
                    $information = $this->destination_email_check_email();
                    break;
                case 'backup_now':
                    $information = $this->backup_now();
                    break;
                case 'ajax_working':
                    $information = $this->ajax_working();
                    break;
                case 'backup_abort':
                    $information = $this->backup_abort();
                    break;
                case 'backwpup_tables':
                    $information = $this->tables();
                    break;
                case 'view_log':
                    $information = $this->view_log();
                    break;
                case 'delete_log':
                    $information = $this->delete_log();
                    break;
                case 'delete_job':
                    $information = $this->delete_job();
                    break;
                case 'delete_backup':
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
                case 'show_hide':
                    $information = $this->show_hide();
                    break;
                case 'save_settings':
                    $information = $this->save_settings();
                    break;
                case 'job_info':
                    $information = $this->job_info();
                    break;
                default:
                    $information = array( 'error' => esc_html__( 'Wrong action.', 'mainwp-child' ) );
            }
        }

        static::$information = $information;
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
     * @uses MainWP_Exception
     */
    public function do_reports_log( $ext = '' ) { // phpcs:ignore -- NOSONAR - complex.
        if ( 'backwpup' !== $ext || ! $this->is_backwpup_installed ) {
            return;
        }

        try {

            MainWP_Helper::instance()->check_classes_exists( array( '\BackWPup_File', '\BackWPup_Job' ) );
            MainWP_Helper::instance()->check_methods( '\BackWPup_File', array( 'get_absolute_path' ) );
            MainWP_Helper::instance()->check_methods( '\BackWPup_Job', array( 'read_logheader' ) );
            $lasttime_logged = MainWP_Utility::get_lasttime_backup( 'backwpup' );
            $log_folder      = get_site_option( 'backwpup_cfg_logfolder' );
            $log_folder      = \BackWPup_File::get_absolute_path( $log_folder );
            $log_folder      = untrailingslashit( $log_folder );

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
        } catch ( MainWP_Exception $ex ) {
            // ok!
        }
    }

    /**
     * Sync other data from $data[] and merge with $information[]
     *
     * @param array $information Returned response array for MainWP BackWPup Extension actions.
     * @param array $data Other data to sync to $information array.
     *
     * @uses \MainWP\Child\MainWP_Utility::get_lasttime_backup()
     * @uses \BackWPup_Option::get_job_ids()
     * @uses \BackWPup_Option::get_job()
     *
     * @return array $information Returned information array with both sets of data.
     */
    public function sync_others_data( $information, $data = array() ) {  // phpcs:ignore -- NOSONAR - complex.
        if ( isset( $data['syncBackwpupData'] ) && $data['syncBackwpupData'] ) {
            try {
                $global_jobs_ids = $this->get_all_global_backwpup_job_ids();
                $jobs_ids        = \BackWPup_Option::get_job_ids();
                $jobs            = array();
                if ( ! empty( $jobs_ids ) && is_array( $jobs_ids ) ) {
                    foreach ( $jobs_ids as $key => $job_id ) {
                        // Skip temp job.
                        $temp_job = \BackWPup_Option::get( $job_id, 'tempjob', false );
                        if ( true === $temp_job ) {
                            continue;
                        }

                        $jobs[ $key ]['settings']  = \BackWPup_Option::get_job( $job_id, false );
                        $jobs[ $key ]['job_id']    = $job_id;
                        $jobs[ $key ]['is_global'] = in_array( $job_id, $global_jobs_ids ) ? 1 : 0;
                    }
                }

                $lastbackup                      = MainWP_Utility::get_lasttime_backup( 'backwpup' );
                $information['syncBackwpupData'] = array(
                    'lastbackup' => $lastbackup,
                    'jobs'       => ! empty( $jobs ) ? array_values( $jobs ) : array(),
                );
            } catch ( MainWP_Exception $e ) {
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
     * @throws MainWP_Exception Error Message.
     */
    public function get_destinations_list() { // phpcs:ignore -- NOSONAR - complex.
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

                $dest_class = (object) \BackWPup::get_destination( $dest );
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
        global $submenu;

        // Remove the WordPress Admin SubMenu.
        if ( isset( $submenu['backwpup'] ) ) {
            unset( $submenu['backwpup'] );
        }

        // Remove the WordPress Admin Page.
        remove_menu_page( 'backwpup' );

        // Delete submenu.
        remove_submenu_page( 'backwpup', 'docs' );
        remove_submenu_page( 'backwpup', 'buypro' );
        remove_submenu_page( 'backwpup', 'backwpupsupport' );

        // Create a WP Safe Redirect for the page URL.
        $pos = isset( $_SERVER['REQUEST_URI'] ) ? stripos( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), 'admin.php?page=backwpup' ) : false;
        if ( false !== $pos ) {
            wp_safe_redirect( get_option( 'siteurl' ) . '/wp-admin/index.php' );
            exit();
        }
    }

    /**
     * Show/Hide BackWPup Plugin.
     *
     * @return array Return 1 on HIDE, Empty string on SHOW.
     */
    protected function show_hide() {

        $raw = isset( $_POST['show_hide'] ) ? sanitize_text_field( wp_unslash( $_POST['show_hide'] ) ) : '';  // phpcs:ignore -- NOSONAR
        $hide = ( 'hide' === sanitize_text_field( $raw ) ) ? 'hide' : '';

        update_site_option( 'mainwp_backwpup_hide_plugin', $hide );

        return array( 'success' => 1 );
    }

    /**
     * Build the MainWP BackWPup Page Settings.
     *
     * @return array $output Returned information array.
     */
    protected function information() { //phpcs:ignore -- NOSONAR - complex.
        global $wpdb;

        // Copied from BackWPup_Page_Settings.
        ob_start();
        echo '<table class="wp-list-table widefat fixed" style="border-spacing:0;width: 85%;margin-left:auto;;margin-right:auto;">';
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
            printf( esc_html__( 'Temp folder %s doesn\'t exist.', 'mainwp-child' ), esc_html( \BackWPup::get_plugin_data( 'TEMP' ) ) );
        } elseif ( ! is_writable( \BackWPup::get_plugin_data( 'TEMP' ) ) ) { //phpcs:ignore -- NOSONAR - ok.
            printf( esc_html__( 'Temporary folder %s is not writable.', 'mainwp-child' ), esc_html( \BackWPup::get_plugin_data( 'TEMP' ) ) );
        } else {
            echo esc_html( \BackWPup::get_plugin_data( 'TEMP' ) );
        }
        echo '</td></tr>';

        echo '<tr><td>' . esc_html__( 'Log folder:', 'mainwp-child' ) . '</td><td>';

        $log_folder = \BackWPup_File::get_absolute_path( get_site_option( 'backwpup_cfg_logfolder' ) );

        if ( ! is_dir( $log_folder ) ) {
            printf( esc_html__( 'Logs folder %s not exist.', 'mainwp-child' ), esc_html( $log_folder ) );
        } elseif ( ! is_writable( $log_folder ) ) { //phpcs:ignore -- NOSONAR - ok.
            printf( esc_html__( 'Log folder %s is not writable.', 'mainwp-child' ), esc_html( $log_folder ) );
        } else {
            echo esc_html( $log_folder );
        }
        echo '</td></tr>';
        echo '<tr title=""><td>' . esc_html__( 'Server', 'mainwp-child' ) . '</td><td>' . ( isset( $_SERVER['SERVER_SOFTWARE'] ) ? esc_html( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) ) : '' ) . '</td></tr>';
        echo '<tr title=""><td>' . esc_html__( 'Operating System', 'mainwp-child' ) . '</td><td>' . esc_html( PHP_OS ) . '</td></tr>';
        echo '<tr title=""><td>' . esc_html__( 'PHP SAPI', 'mainwp-child' ) . '</td><td>' . esc_html( PHP_SAPI ) . '</td></tr>';
        echo '<tr title=""><td>' . esc_html__( 'Current PHP user', 'mainwp-child' ) . '</td><td>' . esc_html( get_current_user() ) . '</td></tr>';
        $text = version_compare( phpversion(), '5.3.0' ) < 0 && (bool) ini_get( 'safe_mode' ) ? esc_html__( 'On', 'mainwp-child' ) : esc_html__( 'Off', 'mainwp-child' );
        echo '<tr title=""><td>' . esc_html__( 'Safe Mode', 'mainwp-child' ) . '</td><td>' . $text . '</td></tr>'; //phpcs:ignore -- NOSONAR - escaped.
        echo '<tr title="&gt;=30"><td>' . esc_html__( 'Maximum execution time', 'mainwp-child' ) . '</td><td>' . esc_html( ini_get( 'max_execution_time' ) ) . ' ' . esc_html__( 'seconds', 'mainwp-child' ) . '</td></tr>';
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
            echo '<tr title="FS_CHMOD_DIR"><td>' . esc_html__( 'CHMOD Dir', 'mainwp-child' ) . '</td><td>' . esc_html( FS_CHMOD_DIR ) . '</td></tr>';
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
        if ( ! isset( $_POST['settings']['logfile'] ) || ! is_array( $_POST['settings']['logfile'] ) ) {  // phpcs:ignore -- NOSONAR
            return array( 'error' => esc_html__( 'Missing logfile.', 'mainwp-child' ) );
        }

        $result = array();

        $dir = get_site_option( 'backwpup_cfg_logfolder' );
        $dir = \BackWPup_File::get_absolute_path( $dir );

        foreach ( $_POST['settings']['logfile'] as $logfile ) {  // phpcs:ignore -- NOSONAR
            $logfile = basename( $logfile );
            // phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_is_writeable -- NOSONAR
            if ( ! is_writeable( $dir ) ) {
                $result = array( 'error' => esc_html__( 'Directory not writable:', 'mainwp-child' ) . $dir );
            }
            if ( ! is_file( $dir . $logfile ) ) {
                $result = array( 'error' => esc_html__( 'Not file:', 'mainwp-child' ) . $dir . $logfile );
            }

            if ( $result ) {
                return $result;
            }
            // phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_is_writeable -- NOSONAR
            wp_delete_file( $dir . $logfile );

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
        $job_id = isset( $_POST['job_id'] ) ? (int) sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : 0; // phpcs:ignore -- NOSONAR
        if ( 0 === $job_id ) {
            return array( 'error' => esc_html__( 'Missing job_id.', 'mainwp-child' ) );
        }

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
    protected function delete_backup() { //phpcs:ignore -- NOSONAR - multi return.
        if ( ! isset( $_POST['settings']['backupfile'] ) ) { // phpcs:ignore -- NOSONAR
            return array( 'error' => esc_html__( 'Missing backupfile.', 'mainwp-child' ) );
        }

        if ( ! isset( $_POST['settings']['dest'] ) ) { // phpcs:ignore -- NOSONAR
            return array( 'error' => esc_html__( 'Missing dest.', 'mainwp-child' ) );
        }

        $backupfile = isset( $_POST['settings']['backupfile'] ) ? wp_unslash( $_POST['settings']['backupfile'] ) : ''; // phpcs:ignore -- NOSONAR
        $dest = isset( $_POST['settings']['dest'] ) ? sanitize_text_field( wp_unslash( $_POST['settings']['dest'] ) ) : ''; // phpcs:ignore -- NOSONAR

        list( $dest_id, $dest_name ) = explode( '_', $dest );
        unset( $dest_id );

        $dest_class = (object) \BackWPup::get_destination( $dest_name );

        if ( is_null( $dest_class ) ) {
            return array( 'error' => esc_html__( 'Invalid dest class.', 'mainwp-child' ) );
        }

        $files = $dest_class->file_get_list( $dest );

        foreach ( $files as $file ) {
            if ( is_array( $file ) && $file['file'] === $backupfile ) {
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
        if ( ! isset( $_POST['settings']['logfile'] ) ) {  // phpcs:ignore -- NOSONAR
            return array( 'error' => esc_html__( 'Missing logfile.', 'mainwp-child' ) );
        }

        $log_folder = get_site_option( 'backwpup_cfg_logfolder' );
        $log_folder = \BackWPup_File::get_absolute_path( $log_folder );
        $log_file = $log_folder . basename( wp_unslash( $_POST['settings']['logfile'] ) );  // phpcs:ignore -- NOSONAR

        if ( ! is_readable( $log_file ) && ! is_readable( $log_file . '.gz' ) && ! is_readable( $log_file . '.bz2' ) ) {
            $output = esc_html__( 'Log file doesn\'t exists', 'mainwp-child' );
        } else {
            if ( ! file_exists( $log_file ) && file_exists( $log_file . '.gz' ) ) {
                $log_file = $log_file . '.gz';
            }

            if ( ! file_exists( $log_file ) && file_exists( $log_file . '.bz2' ) ) {
                $log_file = $log_file . '.bz2';
            }

            if ( '.gz' === substr( $log_file, -3 ) ) {
                $output = file_get_contents( 'compress.zlib://' . $log_file, false ); //phpcs:ignore WordPress.WP.AlternativeFunctions
            } else {
                $output = file_get_contents( $log_file, false ); //phpcs:ignore WordPress.WP.AlternativeFunctions
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
    protected function tables() { // phpcs:ignore -- NOSONAR - complex.
        if ( ! isset( $_POST['settings']['type'] ) ) { // phpcs:ignore -- NOSONAR
            return array( 'error' => esc_html__( 'Missing type.', 'mainwp-child' ) );
        }

        if ( ! isset( $_POST['settings']['website_id'] ) ) { // phpcs:ignore -- NOSONAR
            return array( 'error' => esc_html__( 'Missing website id.', 'mainwp-child' ) );
        }

        $type = isset( $_POST['settings']['type'] ) ? sanitize_text_field( wp_unslash( $_POST['settings']['type'] ) ) : ''; // phpcs:ignore -- NOSONAR
        $website_id = isset( $_POST['settings']['website_id'] ) ? sanitize_text_field( wp_unslash( $_POST['settings']['website_id'] ) ) : ''; // phpcs:ignore -- NOSONAR
        $this->wp_list_table_dependency();  // phpcs:ignore -- NOSONAR

        $array      = array();
        $is_global = isset( $_POST['settings']['is_global'] ) ? intval( wp_unslash( $_POST['settings']['is_global'] ) ) : 0;  // phpcs:ignore -- NOSONAR
        $global_ids = $this->get_all_global_backwpup_job_ids();

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
                $logs          = array_filter(
                    $output->items,
                    function ( $log ) use ( $global_ids, $is_global ) {
                        $temp_job = (bool) \BackWPup_Option::get( $log['jobid'], 'tempjob', false );
                        if ( $temp_job ) {
                            return false;
                        }
                        if ( 0 === $is_global ) {
                            return in_array( intval( $log['jobid'] ), $global_ids, true );
                        }
                        return ! empty( $log['jobid'] );
                    }
                );
                $output->items = $logs ?? array();
                break;

            case 'backups':
                update_user_option( get_current_user_id(), 'backwpupbackups_per_page', 99999999 );
                $output        = new \BackWPup_Page_Backups();
                $output->items = array();

                $jobids = \BackWPup_Option::get_job_ids();
                if ( ! empty( $jobids ) ) {
                    foreach ( $jobids as $jobid ) {
                        if ( \BackWPup_Option::get( $jobid, 'backuptype' ) === 'sync' ) {
                            continue;
                        }
                        $dests = \BackWPup_Option::get( $jobid, 'destinations' );
                        foreach ( $dests as $dest ) {
                            $dest_class = (object) \BackWPup::get_destination( $dest );
                            if ( is_null( $dest_class ) ) {
                                continue;
                            }
                            $items = $dest_class->file_get_list( $jobid . '_' . $dest );
                            if ( ! empty( $items ) ) {
                                foreach ( $items as $item ) {
                                    $temp_single_item              = $item;
                                    $temp_single_item['dest']      = $jobid . '_' . $dest;
                                    $temp_single_item['timeloc']   = sprintf( esc_html__( '%1$s at %2$s', 'mainwp-child' ), date_i18n( get_option( 'date_format' ), $temp_single_item['time'], true ), date_i18n( get_option( 'time_format' ), $temp_single_item['time'], true ) );
                                    $temp_single_item['timestamp'] = $item['time'];
                                    $output->items[]               = $temp_single_item;
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
            default:
                break;
        }

        if ( is_array( $output->items ) ) {
            if ( 'jobs' === $type ) {
                foreach ( $output->items as $key => $val ) {
                    $temp_array                 = array();
                    $temp_array['id']           = $val;
                    $temp_array['name']         = \BackWPup_Option::get( $val, 'name' );
                    $temp_array['type']         = \BackWPup_Option::get( $val, 'type' );
                    $temp_array['destinations'] = \BackWPup_Option::get( $val, 'destinations' );

                    if ( $this->is_backwpup_pro ) {
                        $temp_array['export'] = str_replace( '&amp;', '&', wp_nonce_url( network_admin_url( 'admin.php' ) . '?page=backwpupjobs&action=export&jobs[]=' . $val, 'bulk-jobs' ) );
                    }

                    if ( \BackWPup_Option::get( $val, 'activetype' ) === 'wpcron' ) {
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
            } elseif ( 'backups' === $type ) {
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
                    if ( preg_match( '/.*&jobid=([^&]+)&.*/is', $temp_array['downloadurl'], $matches ) && ! empty( $matches[1] ) && is_numeric( $matches[1] ) ) {
                        $temp_array['downloadurl_id'] .= '&download_click_id=' . $matches[1];
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
        $page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';  // phpcs:ignore -- NOSONAR
        if ( ! empty( $page ) || 'backwpupbackups' !== $page || ! isset( $page ) || empty( $page ) ) {
            return;
        }
        ?>
        <script type="text/javascript">
            var dlClicked = false;
            var download_click_id = <?php echo intval( $_GET['download_click_id'] );  // phpcs:ignore -- NOSONAR ?>;
            document.addEventListener("DOMContentLoaded", function (event) {
                if (dlClicked === false) {
                    var downloadLink = document.querySelector(`a.backup-download-link[data-jobid="${download_click_id}"`);
                    if (typeof (downloadLink) !== 'undefined' && downloadLink !== null) {
                        downloadLink.click();
                        dlClicked = true;
                    }
                    if (dlClicked === false) { // for new version.
                        setTimeout(
                            function () {
                                downloadLink = document.querySelector(`button.js-backwpup-download-backup[data-jobid="${download_click_id}"`);
                                if (typeof (downloadLink) !== 'undefined' && downloadLink !== null) {
                                    downloadLink.click();
                                }
                            },
                            2000
                        );
                        dlClicked = true;
                    }
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
        if ( ! isset( $_GET['type'] ) || empty( $_GET['type'] ) || ! isset( $_GET['_wpnonce'] ) || empty( $_GET['_wpnonce'] ) ) {  // phpcs:ignore -- NOSONAR
            die( '-1' );
        }

        if ( ! current_user_can( 'backwpup_backups_download' ) ) { //phpcs:ignore - ok.
            die( '-2' );
        }

        if ( ! $this->verify_nonce_without_session( $_GET['_wpnonce'], 'mainwp_download_backup' ) ) {  // phpcs:ignore -- NOSONAR
            die( '-3' );
        }

        $dest = strtoupper( str_replace( 'download', '', $_GET['type'] ) );  // phpcs:ignore -- NOSONAR
        if ( ! empty( $dest ) && strstr( $_GET['type'], 'download' ) ) {  // phpcs:ignore -- NOSONAR
            $dest_class = (object) \BackWPup::get_destination( $dest );
            if ( is_null( $dest_class ) ) {
                die( '-4' );
            }

            $dest_class->file_download( (int) $_GET['jobid'], $_GET['file'] );  // phpcs:ignore -- NOSONAR
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
    protected function create_nonce_without_session( $action = -1 ) {
        $user = wp_get_current_user();
        $uid  = (int) $user->ID;
        if ( ! $uid ) {
            $uid = apply_filters( 'nonce_user_logged_out', $uid, $action );
        }

        $i = wp_nonce_tick();

        return substr( wp_hash( $i . '|' . $action . '|' . $uid, 'nonce' ), -12, 10 );
    }

    /**
     * Verify nonce without session.
     *
     * @param string $nonce Nonce to verify.
     * @param int    $action Action to perform.
     *
     * @return bool|int FALSE on failure. 1 or 2 on success.
     */
    protected function verify_nonce_without_session( $nonce, $action = -1 ) { //phpcs:ignore -- NOSONAR - multi return 3rd compatible.
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

        $result = false;

        $expected = substr( wp_hash( $i . '|' . $action . '|' . $uid, 'nonce' ), -12, 10 );
        if ( hash_equals( $expected, $nonce ) ) {
            $result = 1;
        }

        $expected = substr( wp_hash( ( $i - 1 ) . '|' . $action . '|' . $uid, 'nonce' ), -12, 10 );
        if ( hash_equals( $expected, $nonce ) ) {
            $result = 2;
        }

        return $result;
    }

    /**
     * MainWP BackWPup WP Die ajax handler.
     *
     * @return string Error message.
     */
    public static function mainwp_backwpup_wp_die_ajax_handler() {
        return '__return_true';
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
        // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( ! isset( $_POST['settings'] ) || ! is_array( $_POST['settings'] )
            || ! isset( $_POST['settings']['logfile'] ) || ! isset( $_POST['settings']['logpos'] )
            || ! isset( $_POST['settings']['job_id'] )
        ) {
            return array( 'error' => esc_html__( 'Missing logfile or logpos.', 'mainwp-child' ) );
        }

        // Get job log file.
        $job_id      = $_POST['settings']['job_id'] ?? 0;
        $job_logfile = \BackWPup_Option::get( $job_id, 'logfile' );
        $logfile     = basename( $job_logfile );

        $_GET['logfile']      = ! empty( $_POST['settings']['logfile'] ) ? wp_unslash( $_POST['settings']['logfile'] ) : $logfile;
        $_GET['logpos']       = ! empty( $_POST['settings']['logpos'] ) && 0 !== intval( $_POST['settings']['logpos'] ) ? wp_unslash( $_POST['settings']['logpos'] ) : '';
        $_REQUEST['_wpnonce'] = wp_create_nonce( 'backwpupworking_ajax_nonce' );

        $this->wp_list_table_dependency();
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

        add_filter( 'wp_die_ajax_handler', array( __CLASS__, 'mainwp_backwpup_wp_die_ajax_handler' ) );
        remove_filter( 'wp_die_ajax_handler', '_ajax_wp_die_handler' );

        ob_start();
        \BackWPup_Page_Jobs::ajax_working();
        $output = ob_get_contents();
        ob_end_clean();
        // Get last backup time.
        $lastbackup = MainWP_Utility::get_lasttime_backup( 'backwpup' );
        // phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        return array(
            'success'    => 1,
            'response'   => $output,
            'lastbackup' => $lastbackup,
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
    protected function backup_now() { //phpcs:ignore -- NOSONAR - multi return 3rd compatible.

        if ( ! isset( $_POST['settings']['job_id'] ) ) {  // phpcs:ignore -- NOSONAR
            return array( 'error' => esc_html__( 'Missing job_id', 'mainwp-child' ) );  // NOSONAR.
        }

        // Simulate http://wp/wp-admin/admin.php?jobid=1&page=backwpupjobs&action=runnow.
        $_GET['jobid'] = isset( $_POST['settings']['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['settings']['job_id'] ) ) : '';  // phpcs:ignore -- NOSONAR

        $_REQUEST['action']   = 'runnow';
        $_REQUEST['_wpnonce'] = wp_create_nonce( 'backwpup_job_run-runnowlink' );

        update_site_option( 'backwpup_messages', array() );

        $this->wp_list_table_dependency();

        ob_start();
        \BackWPup_Page_Jobs::load();
        ob_end_clean();

        $output = $this->check_backwpup_messages();

        // Disable onboarding.
        $this->disable_onboarding();

        if ( isset( $output['error'] ) ) {
            return array( 'error' => '\BackWPup_Page_Jobs::load fail: ' . $output['error'] );
        } else {
            $job_object = \BackWPup_Job::get_working_data();
            $lastbackup = MainWP_Utility::get_lasttime_backup( 'backwpup' );
            if ( is_object( $job_object ) ) {
                return array(
                    'success'    => 1,
                    'response'   => $output['message'],
                    'logfile'    => basename( $job_object->logfile ),
                    'lastbackup' => $lastbackup,
                );
            } else {
                return array(
                    'success'    => 1,
                    'response'   => $output['message'],
                    'lastbackup' => $lastbackup,
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
                'success' => 1,
                'message' => $output['message'],
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
            require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php'; // NOSONAR - WP compatible.
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
     * @uses MainWP_Exception
     *
     * @return array|MainWP_Exception Return response array.
     */
    protected function destination_email_check_email() { // phpcs:ignore -- NOSONAR - complex.
        $settings = isset( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array();  // phpcs:ignore -- NOSONAR
        $message       = '';
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
                    if ( file_exists( ABSPATH . WPINC . '/PHPMailer/PHPMailer.php' ) ) {
                        require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php'; // NOSONAR - WP compatible.
                        require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php'; // NOSONAR - WP compatible.
                        require_once ABSPATH . WPINC . '/PHPMailer/Exception.php'; // NOSONAR - WP compatible.
                        $phpmailer = new \PHPMailer\PHPMailer\PHPMailer( true ); // phpcs:ignore -- to custom init PHP mailer
                    } elseif ( file_exists( ABSPATH . WPINC . '/class-phpmailer.php' ) ) {
                        require_once ABSPATH . WPINC . '/class-phpmailer.php'; // NOSONAR - WP compatible.
                        require_once ABSPATH . WPINC . '/class-smtp.php'; // NOSONAR - WP compatible.
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
                require_once \BackWPup::get_plugin_data( 'plugindir' ) . '/vendor/SwiftMailer/swift_required.php'; // NOSONAR - WP compatible.
            }

            if ( function_exists( 'mb_internal_encoding' ) && ( (int) ini_get( 'mbstring.func_overload' ) ) & 2 ) {
                $mbEncoding = mb_internal_encoding();
                mb_internal_encoding( 'ASCII' );
            }

            try {
                // Create the Transport.
                if ( 'smtp' === $emailmethod ) {
                    $transport = \Swift_SmtpTransport::newInstance( $emailhost, $emailhostport );
                    $transport->setUsername( $emailuser );
                    $transport->setPassword( $emailpass );
                    if ( 'ssl' === $emailsecure ) {
                        $transport->setEncryption( 'ssl' );
                    }
                    if ( 'tls' === $emailsecure ) {
                        $transport->setEncryption( 'tls' );
                    }
                } elseif ( 'sendmail' === $emailmethod ) {
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
            } catch ( MainWP_Exception $e ) {
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
    protected function get_job_files() { // phpcs:ignore -- NOSONAR - complex.
        /**
         * Taken from BackWPup_JobType_File::get_exclude_dirs.
         *
         * @param string $folder Folders to exclude.
         * @return array Return folders list.
         */
        function mainwp_backwpup_get_exclude_dirs( $folder ) {
            $folder            = trailingslashit( str_replace( '\\', '/', realpath( $folder ) ) );
            $exclude_dir_array = array();

            if ( false !== strpos( trailingslashit( str_replace( '\\', '/', realpath( ABSPATH ) ) ), $folder ) && trailingslashit( str_replace( '\\', '/', realpath( ABSPATH ) ) ) !== $folder ) {
                $exclude_dir_array[] = trailingslashit( str_replace( '\\', '/', realpath( ABSPATH ) ) );
            }
            if ( false !== strpos( trailingslashit( str_replace( '\\', '/', realpath( WP_CONTENT_DIR ) ) ), $folder ) && trailingslashit( str_replace( '\\', '/', realpath( WP_CONTENT_DIR ) ) ) !== $folder ) {
                $exclude_dir_array[] = trailingslashit( str_replace( '\\', '/', realpath( WP_CONTENT_DIR ) ) );
            }
            if ( false !== strpos( trailingslashit( str_replace( '\\', '/', realpath( WP_PLUGIN_DIR ) ) ), $folder ) && trailingslashit( str_replace( '\\', '/', realpath( WP_PLUGIN_DIR ) ) ) !== $folder ) {
                $exclude_dir_array[] = trailingslashit( str_replace( '\\', '/', realpath( WP_PLUGIN_DIR ) ) );
            }
            if ( false !== strpos( trailingslashit( str_replace( '\\', '/', realpath( get_theme_root() ) ) ), $folder ) && trailingslashit( str_replace( '\\', '/', realpath( get_theme_root() ) ) ) !== $folder ) {
                $exclude_dir_array[] = trailingslashit( str_replace( '\\', '/', realpath( get_theme_root() ) ) );
            }
            if ( false !== strpos( trailingslashit( str_replace( '\\', '/', realpath( \BackWPup_File::get_upload_dir() ) ) ), $folder ) && trailingslashit( str_replace( '\\', '/', realpath( \BackWPup_File::get_upload_dir() ) ) ) !== $folder ) {
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
    protected function get_child_tables() { // phpcs:ignore -- NOSONAR - complex.
        global $wpdb;

        $return   = array();
        $settings = isset( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array();  // phpcs:ignore -- NOSONAR

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
     * @uses BackWPup_Job::enable_job()
     * @return array Response array containing job_id, changes & message array.
     */
    protected function insert_or_update_jobs_global() { // phpcs:ignore -- NOSONAR - complex.
        $post_settings = isset( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : '';  // phpcs:ignore -- NOSONAR
        $settings      = ! empty( $post_settings ) ? json_decode( $post_settings ) : null;
        $is_global = isset( $_POST['is_global'] ) ? intval( wp_unslash( $_POST['is_global'] ) ) : 0;  // phpcs:ignore -- NOSONAR

        if ( ! is_object( $settings ) ) {
            return array( 'error' => esc_html__( 'Missing array settings', 'mainwp-child' ) );  // NOSONAR.
        }

        if ( ! isset( $settings->job_id ) ) {
            return array( 'error' => esc_html__( 'Missing job_id', 'mainwp-child' ) );
        }

        $jobids     = \BackWPup_Option::get_job_ids();
        $new_job_id = intval( $settings->job_id );
        if ( ! in_array( $new_job_id, $jobids, true ) ) {
            $new_job_id = \BackWPup_Option::next_job_id();
        }

        $changes_array = array();
        $message_array = array();
        $tabs_mapping  = $this->get_tabs_mapping();

        foreach ( $settings->value as $key => $val ) {
            if ( null === $val || '' === $val || ( is_array( $val ) && count( $val ) === 0 ) ) {
                continue;
            }

            $temp_array = array(
                'tab'    => '',
                'value'  => array( $key => $val ),
                'job_id' => $new_job_id ?? $settings->job_id,
            );

            foreach ( $tabs_mapping as $tab => $keys ) {
                if ( in_array( $key, $keys, true ) || strstr( (string) $key, 'dest-' ) || strstr( (string) $key, 'jobtype-' ) ) {
                    $temp_array['tab'] = $tab;
                    break;
                }
            }

            $_POST['settings'] = wp_json_encode( $temp_array );
            $return            = $this->insert_or_update_jobs();

            if ( is_null( $new_job_id ) && isset( $return['job_id'] ) ) {
                $new_job_id = $return['job_id'];
            }

            if ( isset( $return['error_message'] ) ) {
                $message_array[ $return['error_message'] ] = 1;
            }

            if ( isset( $return['changes'] ) ) {
                $changes_array = array_merge( $changes_array, $return['changes'] );
            }

            if ( isset( $return['message'] ) ) {
                foreach ( $return['message'] as $message ) {
                    $message_array[ $message ] = 1;
                }
            }
        }

        // Set empty fields to empty string.
        $empty_fields = array( 's3base_multipart', 's3base_pathstylebucket' );
        if ( isset( $settings->value ) && is_object( $settings->value ) ) {
            $props      = get_object_vars( $settings->value );
            $candidates = array_intersect_key( $props, array_flip( $empty_fields ) );

            foreach ( $candidates as $key => $val ) {
                if ( '0' === $val ) {
                    \BackWPup_Option::update( (int) $new_job_id, $key, '' );
                }
            }
        }

        // Auto Enable the job.
        \BackWPup_Job::enable_job( $new_job_id );

        // Disable onboarding.
        $this->disable_onboarding();

        // Save Gloal Job ID.
        if ( 0 === $is_global ) {
            $this->save_global_job_id( $new_job_id );
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
     * @param mixed $post_data Post data to save.
     * @param mixed $id Post ID.
     */
    public function edit_form_post_save( $post_data, $id ) { // phpcs:ignore -- NOSONAR - complex.
        // Parse and save files to exclude.
        if ( isset( $post_data->fileexclude ) ) {
            $exclude_input   = str_replace( array( "\r\n", "\r" ), ',', $post_data->fileexclude );
            $exclude_input   = sanitize_text_field( stripslashes( $exclude_input ) );
            $to_exclude      = array_filter( array_map( 'trim', explode( ',', $exclude_input ) ) );
            $to_exclude_path = array_map( 'wp_normalize_path', $to_exclude );
            sort( $to_exclude_path );
            \BackWPup_Option::update( $id, 'fileexclude', implode( ',', $to_exclude_path ) );
        }

        // Parse and save folders to include.
        if ( isset( $post_data->dirinclude ) ) {
            $include_input   = str_replace( array( "\r\n", "\r" ), ',', $post_data->dirinclude );
            $to_include      = array_filter( array_map( 'trim', explode( ',', $include_input ) ) );
            $to_include_path = array();

            foreach ( $to_include as $value ) {
                $normalized = trailingslashit( wp_normalize_path( $value ) );
                $realpath   = ( $normalized && '/' !== $normalized ) ? realpath( $normalized ) : false;

                if ( $realpath ) {
                    $to_include_path[] = filter_var( $realpath, FILTER_SANITIZE_URL );
                }
            }

            sort( $to_include_path );
            \BackWPup_Option::update( $id, 'dirinclude', implode( ',', $to_include_path ) );
        }

        // Parse and save boolean fields only if they're present in $post_data.
        $boolean_fields = array(
            'backupexcludethumbs',
            'backupspecialfiles',
            'backuproot',
            'backupabsfolderup',
            'backupcontent',
            'backupplugins',
            'backupthemes',
            'backupuploads',
        );

        foreach ( $boolean_fields as $key ) {
            if ( property_exists( $post_data, $key ) ) {
                \BackWPup_Option::update( $id, $key, (bool) $post_data->$key );
            }
        }

        // Parse and save directory exclusions if present.
        $exclude_dir_fields = array(
            'backuprootexcludedirs',
            'backuppluginsexcludedirs',
            'backupcontentexcludedirs',
            'backupthemesexcludedirs',
            'backupuploadsexcludedirs',
        );

        foreach ( $exclude_dir_fields as $key ) {
            if ( property_exists( $post_data, $key ) && is_array( $post_data->$key ) ) {
                $sanitized = array_map( 'esc_url_raw', $post_data->$key );
                \BackWPup_Option::update( $id, $key, $sanitized );
            }
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
     * @uses BackWPup_Destination_Ftp::edit_form_post_save()
     *
     * @return array Response array containing job_id, changes & message array.
     */
    protected function insert_or_update_jobs() { // phpcs:ignore -- NOSONAR - complex.

        $settings = isset( $_POST['settings'] ) ? json_decode( $_POST['settings'] ) : '';  // phpcs:ignore -- NOSONAR

        if ( ! is_object( $settings ) || ! isset( $settings->value ) ) {
            return array( 'error' => esc_html__( 'Missing array settings', 'mainwp-child' ) );
        }

        if ( ! isset( $settings->job_id ) ) {
            return array( 'error' => esc_html__( 'Missing job_id', 'mainwp-child' ) );
        }

        if ( ! class_exists( '\BackWPup' ) ) {
            return array( 'error' => esc_html__( 'Install BackWPup on child website', 'mainwp-child' ) );
        }

        $jobids = \BackWPup_Option::get_job_ids();
        $job_id = intval( $settings->job_id );
        if ( ! in_array( $job_id, $jobids, true ) ) {
            $job_id = \BackWPup_Option::next_job_id();
        }

        update_site_option( 'backwpup_messages', array() );
        $setting_value = $settings->value;

        if ( isset( $setting_value->backupdir ) ) {
            $raw_dir = trim( sanitize_text_field( $setting_value->backupdir ) );

            if ( strpos( $raw_dir, '/' ) === false && strpos( $raw_dir, '\\' ) === false ) {
                $setting_value->backupdir = 'wp-content/uploads/' . $raw_dir;
            } else {
                $setting_value->backupdir = $raw_dir;
            }
        }

        // this assign not work with filter_input - INPUT_POST.
        foreach ( $setting_value as $key => $val ) {
            $_POST[ $key ] = $val;
        }

        // Map of tab to handler.
        $jobtype_handlers = array(
            'jobtype-FILE'     => array( $this, 'edit_form_post_save' ),
            'jobtype-DBDUMP'   => new \BackWPup_JobType_DBDump(),
            'jobtype-WPEXP'    => new \BackWPup_JobType_WPEXP(),
            'jobtype-WPPLUGIN' => new \BackWPup_JobType_WPPlugin(),
            'dest-FOLDER'      => new \BackWPup_Destination_Folder(),
            'dest-EMAIL'       => new \BackWPup_Destination_Email(),
            'dest-FTP'         => new \BackWPup_Destination_Ftp(),
            'dest-DROPBOX'     => new \BackWPup_Destination_Dropbox(),
            'dest-S3'          => new \BackWPup_Destination_S3(),
            'dest-SUGARSYNC'   => new \BackWPup_Destination_SugarSync(),
            'dest-RSC'         => new \BackWPup_Destination_RSC(),
        );

        if ( class_exists( '\BackWPup_Pro' ) ) {
            $jobtype_handlers = array_merge(
                $jobtype_handlers,
                array(
                    'dest-GLACIER'  => new \BackWPup_Pro_Destination_Glacier(),
                    'dest-GDRIVE'   => new \MainWP\Child\MainWP_Fake_GDrive(),
                    'dest-HIDRIVE'  => new \MainWP\Child\MainWP_Fake_HiDrive(),
                    'dest-ONEDRIVE' => new \MainWP\Child\MainWP_Fake_OneDrive(),
                )
            );
        }

        $job_type_dirs = array(
            'dest-DROPBOX'   => 'dropboxdir',
            'dest-FTP'       => 'ftpdir',
            'dest-S3'        => 's3dir',
            'dest-MSAZURE'   => 'msazuredir',
            'dest-SUGARSYNC' => 'sugardir',
            'dest-RSC'       => 'rscdir',
            'dest-GDRIVE'    => 'gdrivedir',
            'dest-HIDRIVE'   => 'hidrive_destination_folder',
            'dest-ONEDRIVE'  => 'onedrivedir',
        );

        // Special handling for jobtype-DBDUMP.
        if ( 'jobtype-DBDUMP' === $settings->tab && ! isset( $_POST['settings']['value']['tabledb'] ) ) {  // phpcs:ignore -- NOSONAR
            global $wpdb;
            $tables_temp = array();
            $tables = $wpdb->get_results( 'SHOW FULL TABLES FROM `' . DB_NAME . '`', ARRAY_N ); // phpcs:ignore -- safe query.
            foreach ( $tables as $table ) {
                $tables_temp[] = $table[0];
            }

            $_POST['tabledb'] = $tables_temp;
        }

        if ( isset( $jobtype_handlers[ $settings->tab ] ) ) {
            $handler = $jobtype_handlers[ $settings->tab ];
            if ( is_array( $handler ) && is_callable( $handler ) ) {
                call_user_func( $handler, $setting_value, $job_id );
            } else {
                // If you have initialized.
                $instance = is_object( $handler ) ? $handler : new $handler();
                // Merge setting_value with option_defaults if existed.
                if ( method_exists( $instance, 'option_defaults' ) ) {
                    $defaults = $instance->option_defaults();
                    $this->merge_setting_data( $job_id, $defaults, $setting_value );
                    // Update $_POST to make sure the processing form continues to use the correct data.
                    foreach ( $this->setting_data[ $job_id ] as $key => $val ) {
                        $_POST[ $key ] = $val;
                    }
                }

                // Special handling for Dir.
                if ( isset( $job_type_dirs[ $settings->tab ] ) && isset( $settings->value->{$job_type_dirs[ $settings->tab ]} ) ) {
                    $dir_val = $job_type_dirs[ $settings->tab ];
                    $this->change_value_do_not_update( $settings->value->$dir_val, $job_id, $dir_val );
                }

                if ( method_exists( $instance, 'edit_form_post_save' ) ) {
                    $instance->edit_form_post_save( $job_id );
                }
            }
        } elseif ( in_array( $settings->tab, array( 'job', 'tab' ) ) ) {
            \BackWPup_Page_Editjob::save_post_form( $settings->tab, $job_id );
        } else {
            foreach ( $setting_value as $key => $val ) {
                \BackWPup_Option::update( $job_id, $key, $val );
            }
        }

        $return = $this->check_backwpup_messages();

        if ( isset( $return['error'] ) ) {
            return array(
                'success'       => 1,
                'error_message' => esc_html__( 'Cannot save jobs: ' . $return['error'], 'mainwp-child' ),
            );
        }

        $changes_array = array();
        foreach ( $setting_value as $key => $val ) {
            $temp_value = \BackWPup_Option::get( $job_id, $key );

            // Compare if both are string.
            if ( is_string( $temp_value ) && is_string( $val ) ) {
                if ( isset( $this->exclusions[ $settings->tab ] ) ) {
                    if ( ! in_array( $key, $this->exclusions[ $settings->tab ] ) && strcmp( $temp_value, $val ) !== 0 ) {
                        $changes_array[ $key ] = $temp_value;
                    }
                } elseif ( strcmp( $temp_value, $val ) !== 0 ) {
                    $changes_array[ $key ] = $temp_value;
                }
            } elseif ( $temp_value !== $val ) {
                $changes_array[ $key ] = $temp_value;
            }
        }

        // Disable onboarding.
        $this->disable_onboarding();
        // Auto Enable the job.
        \BackWPup_Job::enable_job( $job_id );

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
    protected function update_settings() { //phpcs:ignore -- NOSONAR - multi return 3rd compatible.
        $settings = isset( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array();  // phpcs:ignore -- NOSONAR

        if ( ! is_array( $settings ) || ! isset( $settings['value'] ) ) {
            return array( 'error' => esc_html__( 'Missing array settings', 'mainwp-child' ) );
        }

        if ( ! class_exists( '\BackWPup' ) ) {
            return array( 'error' => esc_html__( 'Install BackWPup on child website', 'mainwp-child' ) );
        }

        if ( isset( $settings['value']['is_premium'] ) && 1 === (int) $settings['value']['is_premium'] && false === $this->is_backwpup_pro ) {
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
            if ( ! in_array( $key, $exclusions ) && strcmp( $temp_value, $val ) !== 0 ) {
                $changes_array[ $key ] = $temp_value;
            }
        }

        // Disable onboarding.
        $this->disable_onboarding();

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

    /**
     * Save settings.
     *
     * @uses BackWPup_Encryption::encrypt()
     * @uses BackWPup_File::normalize_path()
     * @uses BackWPup_Path_Fixer::slashify()
     * @uses BackWPup_Option::update()
     * @uses BackWPup_Pro_Settings_APIKeys::save_form()
     *
     * @return array Response array success, error[].
     */
    protected function save_settings() {  // phpcs:ignore -- NOSONAR
        $raw = isset( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : '';  // phpcs:ignore -- NOSONAR
        $decoded = base64_decode( $raw, true ); //phpcs:ignore -- NOSONAR
        if ( false === $decoded ) {
            return array( 'error' => 'MALFORMED_BASE64' );
        }
        $options = json_decode( $decoded, true );
        if ( ! is_array( $options ) ) {
            return array( 'error' => 'INVALID_JSON' );
        }

        // Helper: Only update when changing the value.
        $update_site_option_if_changed = static function (string $name, $new_value) {  // phpcs:ignore -- NOSONAR
            $current = get_site_option( $name, null );
            if ( is_array( $current ) || is_array( $new_value ) ) {
                if ( $current !== $new_value ) {
                    update_site_option( $name, $new_value );
                    return true;
                }
                return false;
            }
            if ( $current !== $new_value ) {
                update_site_option( $name, $new_value );
                return true;
            }
            return false;
        };

        // Helper: Filter the email list and return the comma string.
        $sanitize_email_list = static function ( $value ) {
            $list = is_array( $value ) ? $value : explode( ',', (string) $value );
            $list = array_filter(
                array_map(
                    function ( $email ) {
                        $sanitized = sanitize_email( trim( (string) $email ) );
                        return is_email( $sanitized ) ? $sanitized : null;
                    },
                    $list
                )
            );
            return $list ? implode( ',', $list ) : '';
        };

        $auth_option_name = 'backwpup_cfg_authentication';
        $auth             = get_site_option( $auth_option_name, array() );

        $has_auth_key = false;
        foreach ( array( 'authentication_method', 'authentication_basic_user', 'authentication_basic_password', 'authentication_query_arg', 'authentication_user_id' ) as $k ) {
            if ( array_key_exists( $k, $options ) ) {
                $has_auth_key = true;
                break;
            }
        }

        if ( $has_auth_key ) {
            if ( array_key_exists( 'authentication_method', $options ) ) {
                $auth['method'] = $options['authentication_method'];
            }
            if ( array_key_exists( 'authentication_basic_user', $options ) ) {
                $auth['basic_user'] = (string) $options['authentication_basic_user'];
            }
            if ( array_key_exists( 'authentication_basic_password', $options ) ) {
                $auth['basic_password'] = \BackWPup_Encryption::encrypt( (string) $options['authentication_basic_password'] );
            }
            if ( array_key_exists( 'authentication_query_arg', $options ) ) {
                $auth['query_arg'] = (string) $options['authentication_query_arg'];
            }
            if ( array_key_exists( 'authentication_user_id', $options ) ) {
                $auth['user_id'] = absint( $options['authentication_user_id'] );
            }

            $update_site_option_if_changed( $auth_option_name, $auth );
            unset(
                $options['authentication_method'],
                $options['authentication_basic_user'],
                $options['authentication_basic_password'],
                $options['authentication_query_arg'],
                $options['authentication_user_id']
            );
        }

        $int_keys                = array( 'jobstepretry', 'jobmaxexecutiontime', 'jobwaittimems', 'maxlogs' );
        $license_api_changed     = false;
        $license_product_changed = false;

        foreach ( $options as $key_option => $val_option ) {
            $option_name   = 'backwpup_cfg_' . $key_option;
            $should_update = true;
            $option        = null;

            switch ( $key_option ) {
                case 'mailaddresssenderlog':
                    $option = sanitize_email( (string) $val_option );
                    break;
                case 'jobrunauthkey':
                    $option = preg_replace( '/[^a-zA-Z0-9]/', '', trim( (string) $val_option ) );
                    break;
                case 'logfolder':
                    $option = trailingslashit(
                        \BackWPup_File::normalize_path( \BackWPup_Path_Fixer::slashify( sanitize_text_field( $val_option ) ) )
                    );
                    break;
                case 'mailaddresslog':
                    $option = $sanitize_email_list( $val_option );
                    break;
                case 'archiveformat':
                    $update_site_option_if_changed( 'backwpup_archiveformat', $val_option );
                    $should_update = false;
                    break;
                case 'license_instance_key':
                case 'license_api_key':
                case 'license_product_id':
                    if ( class_exists( '\BackWPup_Pro' ) ) {
                        $current = get_site_option( $key_option, '' );

                        if ( 'license_api_key' === $key_option ) {
                            $license_api_changed = trim( (string) $current ) !== trim( (string) $val_option );
                        } elseif ( 'license_product_id' === $key_option ) {
                            $license_product_changed = trim( (string) $current ) !== trim( (string) $val_option );
                        }

                        $update_site_option_if_changed( $key_option, $val_option );
                    }
                    $should_update = false;
                    break;
                case 'archiveencryption':
                    if ( class_exists( '\BackWPup_Pro' ) ) {
                        $jobs           = \BackWPup_Option::get_job_ids();
                        $use_encryption = (bool) $val_option;
                        foreach ( $jobs as $job_id ) {
                            \BackWPup_Option::update( $job_id, 'archiveencryption', $use_encryption );
                        }
                    }
                    $should_update = false;
                    break;
                default:
                    if ( in_array( $key_option, $int_keys, true ) ) {
                        $option = absint( $val_option );
                    } elseif ( is_scalar( $val_option ) ) {
                        $option = sanitize_text_field( (string) $val_option );
                    } else {
                        // Preserve complex structures untouched.
                        $option = $val_option;
                    }
                    break;
            }

            if ( $should_update ) {
                $update_site_option_if_changed( $option_name, $option );
            }
        }

        $message = array(
            'success' => 1,
            'message' => 'SUCCESS',
        );

        if ( class_exists( '\BackWPup_Pro' ) ) {
            $pro_settings = \BackWPup_Pro_Settings_APIKeys::get_instance();
            $pro_settings->save_form();
            // Only activate if license_api_key or license_product_id.
            $should_activate = ( $license_api_changed || $license_product_changed );
            $license_status  = get_site_option( 'license_status', 'inactive' );

            if ( $should_activate || 'inactive' === $license_status ) {
                $instance_key = get_site_option( 'license_instance_key', '' );
                $api_key      = get_site_option( 'license_api_key', '' );
                $product_id   = get_site_option( 'license_product_id', '' );

                if ( ! empty( $instance_key ) && ! empty( $api_key ) && ! empty( $product_id ) ) {
                    $response           = $this->license_activate( $instance_key, $api_key, $product_id );
                    $message['license'] = $response;
                }
            }
        }

        // Disable onboarding.
        delete_site_transient( 'backwpup_cookies' );
        $this->disable_onboarding();

        return $message;
    }

    /**
     * License Activate.
     *
     * @param string $instance_key Instance Key.
     * @param string $api_key API Key.
     * @param string $product_id Product ID.
     *
     * @return array Response array.
     */
    protected function license_activate( $instance_key, $api_key, $product_id ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
        $plugin_data    = array(
            'version'    => \BackWPup::get_plugin_data( 'version' ),
            'pluginName' => 'backwpup-pro/backwpup.php',
            'slug'       => 'backwpup',
        );
        $activate       = new \Inpsyde\BackWPup\Pro\License\Api\LicenseActivation( $plugin_data );
        $license_status = get_site_option( 'license_status', 'inactive' );
        $license        = new \Inpsyde\BackWPup\Pro\License\License( $product_id, $api_key, $instance_key, $license_status );

        $response = $activate->activate( $license );

        if ( isset( $response['activated'] ) ) {
            $message  = true === $response['activated'] ? _x(
                'Activated',
                'License',
                'backwpup'
            ) . ' | ' : '';
            $message .= $response['message'] ?? '';
            \BackWPup_Admin::message( $message );
        }

        if ( isset( $response['error'] ) ) {
            \BackWPup_Admin::message( $response['error'], true );
        }
        return $response;
    }

    /**
     * Method job_info
     * Get Job Info.
     *
     * @uses BackWPup_Option::get_job()
     * @uses BackWPup_Option::get()
     */
    public function job_info() {  // phpcs:ignore -- NOSONAR - complex.
        if ( ! isset( $_POST['settings']['type'] ) ) {  // phpcs:ignore -- NOSONAR
            return array( 'error' => esc_html__( 'Missing type.', 'mainwp-child' ) );
        }

        if ( ! isset( $_POST['settings']['website_id'] ) ) {  // phpcs:ignore -- NOSONAR
            return array( 'error' => esc_html__( 'Missing website id.', 'mainwp-child' ) );
        }

        if ( ! isset( $_POST['settings']['id'] ) ) {  // phpcs:ignore -- NOSONAR
            return array( 'error' => esc_html__( 'Missing job id.', 'mainwp-child' ) );
        }

        $type = isset( $_POST['settings']['type'] ) ? sanitize_text_field( wp_unslash( $_POST['settings']['type'] ) ) : '';  // phpcs:ignore -- NOSONAR
        $id = isset( $_POST['settings']['id'] ) ? sanitize_text_field( wp_unslash( $_POST['settings']['id'] ) ) : '';  // phpcs:ignore -- NOSONAR
        $results = array();
        switch ( $type ) {
            case 'jobs':
                $results = \BackWPup_Option::get_job( $id );
                if ( \BackWPup_Option::get( $id, 'activetype' ) === 'wpcron' ) {
                    $nextrun = wp_next_scheduled( 'backwpup_cron', array( 'arg' => $id ) );
                    if ( $nextrun + ( get_option( 'gmt_offset' ) * 3600 ) ) {
                        $results['nextrun'] = sprintf( esc_html__( '%1$s at %2$s by WP-Cron', 'mainwp-child' ), date_i18n( get_option( 'date_format' ), $nextrun, true ), date_i18n( get_option( 'time_format' ), $nextrun, true ) );
                    } else {
                        $results['nextrun'] = esc_html__( 'Not scheduled!', 'mainwp-child' );
                    }
                } else {
                    $results['nextrun'] = esc_html__( 'Inactive', 'mainwp-child' );
                }
                if ( \BackWPup_Option::get( $id, 'lastrun' ) ) {
                    $lastrun            = \BackWPup_Option::get( $id, 'lastrun' );
                    $results['lastrun'] = sprintf( esc_html__( '%1$s at %2$s', 'mainwp-child' ), date_i18n( get_option( 'date_format' ), $lastrun, true ), date_i18n( get_option( 'time_format' ), $lastrun, true ) );
                    if ( \BackWPup_Option::get( $id, 'lastruntime' ) ) {
                        $results['lastrun'] .= ' ' . sprintf( esc_html__( 'Runtime: %d seconds', 'mainwp-child' ), \BackWPup_Option::get( $id, 'lastruntime' ) );
                    }
                } else {
                    $results['lastrun'] = esc_html__( 'not yet', 'mainwp-child' );
                }
                break;
            case 'check_jobs':
                $ids = \BackWPup_Option::get_job_ids();
                if ( in_array( intval( $id ), $ids, true ) ) {
                    $results = \BackWPup_Option::get_job( $id );

                }
                break;
            default:
                break;
        }

        return array(
            'success' => 1,
            'result'  => $results,
        );
    }

    /**
     * Method get_tabs_mapping()
     * Tab Field key
     *
     * @return array
     */
    protected function get_tabs_mapping() {
        $tabs = array(
            'cron'             => array( 'activetype', 'cronselect', 'cronminutes', 'cronhours', 'cronmday', 'cronmon', 'cronwday', 'cronbtype' ),
            'job'              => array( 'backuptype', 'type', 'destinations', 'name', 'mailaddresslog', 'mailaddresssenderlog', 'mailerroronly', 'archiveformat', 'archiveencryption', 'archivename' ),
            'jobtype-FILE'     => array( 'dirinclude', 'backupexcludethumbs', 'backupspecialfiles', 'backuproot', 'backuprootexcludedirs', 'backupcontent', 'backupcontentexcludedirs', 'backupplugins', 'backupthemes', 'backupthemesexcludedirs', 'backuppluginsexcludedirs', 'backupuploads', 'backupuploadsexcludedirs' ),
            'jobtype-DBDUMP'   => array( 'tabledb', 'dbdumpexclude', 'dbdumpfile', 'dbdumptype', 'dbdumpfilecompression' ),
            'jobtype-WPEXP'    => array( 'wpexportcontent', 'wpexportfilecompression', 'wpexportfile' ),
            'jobtype-WPPLUGIN' => array( 'pluginlistfilecompression', 'pluginlistfile' ),
            'dest-FOLDER'      => array( 'maxbackups', 'backupdir', 'backupsyncnodelete' ),
            'dest-FTP'         => array( 'ftphost', 'ftphostport', 'ftptimeout', 'ftpuser', 'ftppass', 'ftpdir', 'ftpmaxbackups', 'ftppasv', 'ftpssl' ),
            'dest-EMAIL'       => array( 'emailaddress', 'emailefilesize', 'emailsndemail', 'emailsndemailname', 'emailmethod', 'emailsendmail', 'emailhost', 'emailhostport', 'emailsecure', 'emailuser', 'emailpass' ),
            'dest-DROPBOX'     => array( 'sandbox_code', 'dropbbox_code', 'dropboxdir', 'delete_auth', 'dropboxsyncnodelete', 'dropboxmaxbackups' ),
            'dest-S3'          => array( 's3base_url', 's3base_multipart', 's3base_pathstylebucket', 's3base_version', 's3base_signature', 's3accesskey', 's3secretkey', 's3bucket', 's3region', 's3ssencrypt', 's3storageclass', 's3dir', 's3maxbackups', 's3syncnodelete' ),
            'dest-MSAZURE'     => array( 'msazureaccname', 'msazurecontainer', 'msazuredir', 'msazuredir', 'msazuresyncnodelete', 'newmsazurecontainer' ),
            'dest-SUGARSYNC'   => array( 'sugarrefreshtoken', 'sugarroot', 'sugardir', 'sugarmaxbackups' ),
            'dest-RSC'         => array( 'rscusername', 'rscapikey', 'rsccontainer', 'rscregion', 'rscdir', 'rscmaxbackups', 'rscsyncnodelete' ),

        );

        if ( class_exists( '\BackWPup_Pro' ) ) {
            $tabs = array_merge(
                $tabs,
                array(
                    'dest-GLACIER'  => array( 'glacieraccesskey', 'glaciersecretkey', 'glaciervault', 'glacierregion', 'glaciermaxbackups' ),
                    'dest-GDRIVE'   => array( 'gdriverefreshtoken', 'gdrivemaxbackups', 'gdrivesyncnodelete', 'gdriveusetrash', 'gdrivedir' ),
                    'dest-HIDRIVE'  => array( 'hidrive_max_backups', 'hidrive_sync_no_delete', 'hidrive_destination_folder' ),
                    'dest-ONEDRIVE' => array( 'onedriverefreshtoken', 'onedrivemaxbackups', 'onedrivesyncnodelete', 'onedriveusetrash', 'onedrivedir' ),
                )
            );
        }

        return $tabs;
    }

    /**
     * Method disable_onboarding()
     *
     * Disable BackWPup onboarding.
     */
    public function disable_onboarding() {
        $is_onboarding = get_site_option( 'backwpup_onboarding', false );
        if ( $is_onboarding ) {
            update_site_option( 'backwpup_onboarding', false );
        }
    }

    /**
     * Method change_value_do_not_update()
     *
     * @param string $filed_value Field value.
     * @param int    $job_id Job ID.
     * @param string $column_name Column name.
     *
     * @uses  \BackWPup_Option::get()
     */
    protected function change_value_do_not_update( $filed_value, $job_id, $column_name ) {
        $val = wp_unslash( $filed_value );
        if ( '%do-not-update%' === $val || '%do-not-update%/' === $val ) {
            $_POST[ $column_name ] = \BackWPup_Option::get( $job_id, $column_name );
        }
    }

    /**
     * Method save_global_job_id()
     *
     * @param mixed $job_id Job ID.
     * @return void
     */
    protected function save_global_job_id( $job_id ): void {
        $global_job = get_site_option( 'backwpup_global_job_id', array() );
        if ( ! isset( $global_job[ $job_id ] ) ) {
            $global_job[ $job_id ] = intval( $job_id );
            update_site_option( 'backwpup_global_job_id', $global_job );
        }
    }

    /**
     * Method get_all_global_backwpup_job_ids()
     * Take the list of all global jobs.
     *
     * @return array List of all global jobs.
     */
    protected function get_all_global_backwpup_job_ids() {
        $backup_files_id    = (int) get_site_option( 'backwpup_backup_files_job_id' );
        $backup_database_id = (int) get_site_option( 'backwpup_backup_database_job_id' );

        $global_job     = get_site_option( 'backwpup_global_job_id', array() );
        $global_job_ids = is_array( $global_job ) ? array_values( $global_job ) : array();

        // Returns the only ID array, eliminates Null.
        return array_unique(
            array_filter(
                array_merge(
                    array( $backup_files_id, $backup_database_id ),
                    $global_job_ids
                )
            )
        );
    }

    /**
     * Method merge_setting_data()
     *
     * @param int   $job_id Job ID.
     * @param array $defaults Default values.
     * @param array $setting_value Setting values.
     *
     * @return array Merged setting data.
     */
    protected function merge_setting_data( $job_id, array $defaults, $setting_value ) {
        if ( ! isset( $this->setting_data[ $job_id ] ) ) {
            $this->setting_data[ $job_id ] = array();
        }

        $incoming = is_array( $setting_value )
            ? $setting_value
            : get_object_vars( $setting_value );

        foreach ( $defaults as $key => $default_val ) {
            if ( ! array_key_exists( $key, $this->setting_data[ $job_id ] ) ) {
                $this->setting_data[ $job_id ][ $key ] = $default_val;
            }
        }

        foreach ( $incoming as $key => $val ) {
            $this->setting_data[ $job_id ][ $key ] = $val;
        }

        return $this->setting_data[ $job_id ];
    }
}

if ( ! class_exists( '\MainWP\Child\MainWP_Fake_Wp_Screen' ) ) {
    /**
     * Class MainWP_Fake_Wp_Screen
     *
     * @used-by MainWP_Child_Back_WP_Up::wp_list_table_dependency()
     */
    class MainWP_Fake_Wp_Screen {  // phpcs:ignore -- NOSONAR
        /**
         * Summary of action
         *
         * @var string
         */
        public $action;
        /**
         * Summary of base
         *
         * @var string
         */
        public $base;
        /**
         * Summary of id
         *
         * @var int
         */
        public $id;
    }
}

if ( ! class_exists( '\MainWP\Child\MainWP_Fake_OneDrive' ) ) {
    /**
     * Class MainWP_Fake_OneDrive
     *
     * @used-by MainWP_Child_Back_WP_Up::wp_list_table_dependency()
     */
    class MainWP_Fake_OneDrive { // phpcs:ignore -- NOSONAR
        /**
         * Medthod edit_form_post_save()
         *
         * @param mixed $jobid Job ID.
         * @uses \BackWPup_Option::update()
         *
         * @return void
         */
        public function edit_form_post_save( $jobid ): void {
            $data   = filter_var_array(
                $_POST, // phpcs:ignore -- NOSONAR
                array(
                    'onedrivesyncnodelete' => FILTER_VALIDATE_BOOLEAN,
                    'onedriveusetrash'     => FILTER_VALIDATE_BOOLEAN,
                    'onedrivemaxbackups'   => FILTER_SANITIZE_NUMBER_INT,
                    'onedrivedir'          => FILTER_SANITIZE_URL,
                )
            );
            $jobids = (array) $jobid;
            foreach ( $jobids as $jobid ) {
                \BackWPup_Option::update(
                    $jobid,
                    'onedrivesyncnodelete',
                    (bool) $data['onedrivesyncnodelete']
                );
                    \BackWPup_Option::update( $jobid, 'onedriveusetrash', (bool) $data['onedriveusetrash'] );
                    \BackWPup_Option::update(
                        $jobid,
                        'onedrivemaxbackups',
                        abs( (int) $data['onedrivemaxbackups'] )
                    );

                if ( ! $data['onedrivedir'] ) {
                    return;
                }

                $gdrivedir = wp_normalize_path( $data['onedrivedir'] );

                if ( substr( $gdrivedir, 0, 1 ) !== '/' ) {
                    $gdrivedir = '/' . $data['onedrivedir'];
                }

                \BackWPup_Option::update( $jobid, 'onedrivedir', $gdrivedir );
            }
        }
    }
}

if ( ! class_exists( '\MainWP\Child\MainWP_Fake_HiDrive' ) ) {
    /**
     * Class MainWP_Fake_HiDrive
     *
     * @used-by MainWP_Child_Back_WP_Up::wp_list_table_dependency()
     */
    class MainWP_Fake_HiDrive { // phpcs:ignore -- NOSONAR
        /**
         * Medthod edit_form_post_save()
         *
         * @param mixed $jobid Job ID.
         * @uses \BackWPup_Option::update()
         *
         * @return void
         */
        public function edit_form_post_save( $jobid ): void {
            $data   = filter_var_array(
                $_POST, // phpcs:ignore -- NOSONAR
                array(
                    'hidrive_sync_no_delete'     => FILTER_VALIDATE_BOOLEAN,
                    'hidrive_max_backups'        => FILTER_SANITIZE_NUMBER_INT,
                    'hidrive_destination_folder' => FILTER_SANITIZE_URL,
                )
            );
            $jobids = (array) $jobid;
            foreach ( $jobids as $jobid ) {
                \BackWPup_Option::update(
                    $jobid,
                    'hidrive_sync_no_delete',
                    (bool) $data['hidrive_sync_no_delete']
                );
                \BackWPup_Option::update(
                    $jobid,
                    'hidrive_max_backups',
                    abs( (int) $data['hidrive_max_backups'] )
                );

                if ( ! $data['hidrive_destination_folder'] ) {
                    return;
                }

                $hi_drive_dir = wp_normalize_path( $data['hidrive_destination_folder'] );

                if ( substr( $hi_drive_dir, 0, 1 ) !== '/' ) {
                    $hi_drive_dir = '/' . $data['hidrive_destination_folder'];
                }

                \BackWPup_Option::update( $jobid, 'hidrive_destination_folder', $hi_drive_dir );
            }
        }
    }
}

if ( ! class_exists( '\MainWP\Child\MainWP_Fake_GDrive' ) ) {
    /**
     * Class MainWP_Fake_GDrive
     *
     * @used-by MainWP_Child_Back_WP_Up::wp_list_table_dependency()
     */
    class MainWP_Fake_GDrive { // phpcs:ignore -- NOSONAR
        /**
         * Medthod edit_form_post_save()
         *
         * @param mixed $jobid Job ID.
         * @uses \BackWPup_Option::update()
         *
         * @return void
         */
        public function edit_form_post_save( $jobid ): void {
            $data   = filter_var_array(
                $_POST, // phpcs:ignore -- NOSONAR
                array(
                    'gdrivesyncnodelete' => FILTER_VALIDATE_BOOLEAN,
                    'gdriveusetrash'     => FILTER_VALIDATE_BOOLEAN,
                    'gdrivemaxbackups'   => FILTER_SANITIZE_NUMBER_INT,
                    'gdrivedir'          => FILTER_SANITIZE_URL,
                )
            );
            $jobids = (array) $jobid;
            foreach ( $jobids as $jobid ) {
                \BackWPup_Option::update( $jobid, 'gdrivesyncnodelete', (bool) $data['gdrivesyncnodelete'] );
                \BackWPup_Option::update( $jobid, 'gdriveusetrash', (bool) $data['gdriveusetrash'] );
                \BackWPup_Option::update( $jobid, 'gdrivemaxbackups', abs( (int) $data['gdrivemaxbackups'] ) );
            }

            if ( ! $data['gdrivedir'] ) {
                return;
            }

            $gdrivedir = wp_normalize_path( $data['gdrivedir'] );

            if ( substr( $gdrivedir, 0, 1 ) !== '/' ) {
                $gdrivedir = '/' . $gdrivedir;
            }

            foreach ( $jobids as $jobid ) {
                \BackWPup_Option::update( $jobid, 'gdrivedir', $gdrivedir );
            }
        }
    }
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- fake class
