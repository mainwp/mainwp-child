<?php
/** MainWP Child WPVivid Backup & Restore
 *
 * This file handles all of the WPvivid Backup & Restore actions.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

// phpcs:disable PSR1.Classes.ClassDeclaration, WordPress.WP.AlternativeFunctions --  to use external code, third party credit.

/**
 * Class MainWP_Child_WPvivid_BackupRestore.
 */
class MainWP_Child_WPvivid_BackupRestore { //phpcs:ignore -- NOSONAR - multi methods.

    /**
     * Holds the Public static instance of MainWP_Child_WPvivid_BackupRestore.
     *
     * @static
     * @var null default null.
     */
    public static $instance = null;

    /**
     * Whether WPvivid Plugin is installed or not.
     *
     * @var bool default false.
     */
    public $is_plugin_installed = false;

    /**
     * Whether WPvivid Pro is installed or not.
     *
     * @var bool default false.
     */
    public $is_pro_plugin_installed = false;

    /**
     * Interface variable.
     *
     * @var object WPvivid_Public_Interface
     */
    public $public_intetface;

    /**
     * Create a public static instance of MainWP_Child_WPvivid_BackupRestore.
     *
     * @return MainWP_Child_WPvivid_BackupRestore|null
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }

        return static::$instance;
    }

    /**
     * MainWP_Child_WPvivid_BackupRestore constructor.
     *
     * Run any time class is called.
     */
    public function __construct() {
        require_once ABSPATH . 'wp-admin/includes/plugin.php'; // NOSONAR - WP compatible.
        if ( is_plugin_active( 'wpvivid-backuprestore/wpvivid-backuprestore.php' ) && defined( 'WPVIVID_PLUGIN_DIR' ) ) {
            $this->is_plugin_installed = true;
        }

        if ( is_plugin_active( 'wpvivid-backup-pro/wpvivid-backup-pro.php' ) && defined( 'WPVIVID_BACKUP_PRO_PLUGIN_DIR' ) ) {
            $this->is_pro_plugin_installed = true;
        }

        if ( ! $this->is_plugin_installed && ! $this->is_pro_plugin_installed ) {
            return;
        }

        add_filter( 'mainwp_site_sync_others_data', array( $this, 'sync_others_data' ), 10, 2 );
        if ( $this->is_plugin_installed ) {
            $this->public_intetface = new \WPvivid_Public_Interface();
        }
    }

    /**
     * MainWP_Child_WPvivid_BackupRestore initiator.
     */
    public function init() {
    }

    /**
     * Sync other data from $data[] and merge with $information[]
     *
     * @param array $information Stores the returned information.
     * @param array $data Other data to sync.
     *
     * @return array $information Returned information array with both sets of data.
     * @throws MainWP_Exception Error message.
     *
     * @uses WPvivid_Setting::get_sync_data()
     */
    public function sync_others_data( $information, $data = array() ) {
        try {

            if ( isset( $data['syncWPvividData'] ) ) {
                $information['syncWPvividData'] = 1;
                $information                    = apply_filters( 'wpvivid_get_mainwp_sync_data', $information );
            }
        } catch ( MainWP_Exception $e ) {
            // ok.
        }

        return $information;
    }

    /**
     * Perform specific WPvivid actions.
     *
     * @uses \MainWP\Child\MainWP_Child_WPvivid_BackupRestore::prepare_backup()
     * @uses \MainWP\Child\MainWP_Child_WPvivid_BackupRestore::backup_now()
     * @uses \MainWP\Child\MainWP_Child_WPvivid_BackupRestore::get_status()
     * @uses \MainWP\Child\MainWP_Child_WPvivid_BackupRestore::get_backup_schedule()
     * @uses \MainWP\Child\MainWP_Child_WPvivid_BackupRestore::get_backup_list();
     * @uses \MainWP\Child\MainWP_Child_WPvivid_BackupRestore::get_default_remote();
     * @uses \MainWP\Child\MainWP_Child_WPvivid_BackupRestore::delete_backup();
     * @uses \MainWP\Child\MainWP_Child_WPvivid_BackupRestore::delete_backup_array();
     * @uses \MainWP\Child\MainWP_Child_WPvivid_BackupRestore::set_security_lock();
     * @uses \MainWP\Child\MainWP_Child_WPvivid_BackupRestore::view_log();
     * @uses \MainWP\Child\MainWP_Child_WPvivid_BackupRestore::read_last_backup_log();
     * @uses \MainWP\Child\MainWP_Child_WPvivid_BackupRestore::view_backup_task_log();
     * @uses \MainWP\Child\MainWP_Child_WPvivid_BackupRestore::backup_cancel();
     * @uses \MainWP\Child\MainWP_Child_WPvivid_BackupRestore::init_download_page();
     * @uses \MainWP\Child\MainWP_Child_WPvivid_BackupRestore::prepare_download_backup();
     * @uses \MainWP\Child\MainWP_Child_WPvivid_BackupRestore::get_download_task();
     * @uses \MainWP\Child\MainWP_Child_WPvivid_BackupRestore::download_backup();
     * @uses \MainWP\Child\MainWP_Child_WPvivid_BackupRestore::set_general_setting();
     * @uses \MainWP\Child\MainWP_Child_WPvivid_BackupRestore::set_schedule();
     * @uses \MainWP\Child\MainWP_Child_WPvivid_BackupRestore::set_remote();
     * @uses \MainWP\Child\MainWP_Child_WPvivid_BackupRestore::post_mainwp_data($_POST);
     * @uses \MainWP\Child\MainWP_Helper::write()
     */
    public function action() {
        $information = array();
        if ( ! $this->is_plugin_installed && ! $this->is_pro_plugin_installed ) {
            $information['error'] = 'NO_WPVIVIDBACKUP';
            MainWP_Helper::write( $information );
        }

        $mwp_action = MainWP_System::instance()->validate_params( 'mwp_action' );
        if ( ! empty( $mwp_action ) ) {
            try {
                switch ( $mwp_action ) {
                    case 'prepare_backup':
                        $information = $this->prepare_backup();
                        break;
                    case 'backup_now':
                        $information = $this->backup_now();
                        break;
                    case 'get_status':
                        $information = $this->get_status();
                        break;
                    case 'get_backup_schedule':
                        $information = $this->get_backup_schedule();
                        break;
                    case 'get_backup_list':
                        $information = $this->get_backup_list();
                        break;
                    case 'get_default_remote':
                        $information = $this->get_default_remote();
                        break;
                    case 'delete_backup':
                        $information = $this->delete_backup();
                        break;
                    case 'delete_backup_array':
                        $information = $this->delete_backup_array();
                        break;
                    case 'set_security_lock':
                        $information = $this->set_security_lock();
                        break;
                    case 'view_log':
                        $information = $this->view_log();
                        break;
                    case 'read_last_backup_log':
                        $information = $this->read_last_backup_log();
                        break;
                    case 'view_backup_task_log':
                        $information = $this->view_backup_task_log();
                        break;
                    case 'backup_cancel':
                        $information = $this->backup_cancel();
                        break;
                    case 'init_download_page':
                        $information = $this->init_download_page();
                        break;
                    case 'prepare_download_backup':
                        $information = $this->prepare_download_backup();
                        break;
                    case 'get_download_task':
                        $information = $this->get_download_task();
                        break;
                    case 'download_backup':
                        $information = $this->download_backup();
                        break;
                    case 'set_general_setting':
                        $information = $this->set_general_setting();
                        break;
                    case 'set_schedule':
                        $information = $this->set_schedule();
                        break;
                    case 'set_remote':
                        $information = $this->set_remote();
                        break;
                    default:
                        $information = $this->post_mainwp_data( $_POST ); //phpcs:ignore WordPress.Security.NonceVerification.Missing --- verified.
                        break;
                }
            } catch ( MainWP_Exception $e ) {
                $information = array( 'error' => $e->getMessage() );
            }

            MainWP_Helper::write( $information );
        }
    }

    /**
     * Post MainWP data.
     *
     * @param string $data Data to post.
     *
     * @return mixed $ret Returned response.
     */
    public function post_mainwp_data( $data ) {
        if ( $this->is_plugin_installed ) {
            global $wpvivid_plugin;
            return $wpvivid_plugin->wpvivid_handle_mainwp_action( $data );
        } elseif ( $this->is_pro_plugin_installed ) {
            $ret['result'] = 'failed';
            $ret['error']  = 'Unknown function';
            return apply_filters( 'wpvivid_handle_mainwp_action', $ret, $data );
        } else {
            $ret['result'] = 'failed';
            $ret['error']  = 'WPvivid Plugin not installed';
            return $ret;
        }
    }

    /**
     * Prepare backup.
     *
     * @uses MainWP_Child_WPvivid_BackupRestore::$public_intetface::prepare_backup()
     *
     * @return mixed $ret Returned response.
     */
    public function prepare_backup() {

        global $wpvivid_plugin;

        $wpvivid_plugin->ajax_check_security();
        return isset( $_POST['backup'] ) ? $this->public_intetface->prepare_backup( sanitize_text_field( wp_unslash( $_POST['backup'] ) ) ) : false; //phpcs:ignore WordPress.Security.NonceVerification.Missing --- verified.
    }

    /**
     * Backup now.
     *
     * @uses MainWP_Child_WPvivid_BackupRestore::$public_intetface::backup_now()
     *
     * @return mixed $ret Returned response.
     */
    public function backup_now() {

        global $wpvivid_plugin;

        $wpvivid_plugin->ajax_check_security();
        return isset( $_POST['task_id'] ) ? $this->public_intetface->backup_now( sanitize_text_field( wp_unslash( $_POST['task_id'] ) ) ) : false; //phpcs:ignore WordPress.Security.NonceVerification.Missing --- verified.
    }

    /**
     * Get status.
     *
     * @uses MainWP_Child_WPvivid_BackupRestore::$public_intetface::get_status()
     *
     * @return mixed $ret Returned response.
     */
    public function get_status() {

        global $wpvivid_plugin;

        $wpvivid_plugin->ajax_check_security();
        return $this->public_intetface->get_status();
    }

    /**
     * Get backup schedule.
     *
     * @uses MainWP_Child_WPvivid_BackupRestore::$public_intetface::get_backup_schedule()
     *
     * @return mixed $ret Returned response.
     */
    public function get_backup_schedule() {

        global $wpvivid_plugin;

        $wpvivid_plugin->ajax_check_security();
        return $this->public_intetface->get_backup_schedule();
    }

    /**
     * Get backup list.
     *
     * @uses MainWP_Child_WPvivid_BackupRestore::$public_intetface::get_backup_list()
     *
     * @return mixed $ret Returned response.
     */
    public function get_backup_list() {

        global $wpvivid_plugin;

        $wpvivid_plugin->ajax_check_security();
        return $this->public_intetface->get_backup_list();
    }

    /**
     * Get default remote destination.
     *
     * @uses MainWP_Child_WPvivid_BackupRestore::$public_intetface::get_default_remote()
     *
     * @return mixed $ret Returned response.
     */
    public function get_default_remote() {

        global $wpvivid_plugin;

        $wpvivid_plugin->ajax_check_security();
        return $this->public_intetface->get_default_remote();
    }

    /**
     * Delete backup.
     *
     * @uses MainWP_Child_WPvivid_BackupRestore::$public_intetface::delete_backup()
     *
     * @return mixed $ret Returned response.
     */
    public function delete_backup() {

        global $wpvivid_plugin;

        $wpvivid_plugin->ajax_check_security();
        return isset( $_POST['backup_id'] ) && isset( $_POST['force'] ) ? $this->public_intetface->delete_backup( sanitize_text_field( wp_unslash( $_POST['backup_id'] ) ), sanitize_text_field( wp_unslash( $_POST['force'] ) ) ) : false; //phpcs:ignore WordPress.Security.NonceVerification.Missing --- verified.
    }

    /**
     * Delete backup array.
     *
     * @uses MainWP_Child_WPvivid_BackupRestore::$public_intetface::delete_backup_array()
     *
     * @return mixed $ret Returned response.
     */
    public function delete_backup_array() {

        global $wpvivid_plugin;

        $wpvivid_plugin->ajax_check_security();
        return isset( $_POST['backup_id'] ) ? $this->public_intetface->delete_backup_array( sanitize_text_field( wp_unslash( $_POST['backup_id'] ) ) ) : false; //phpcs:ignore WordPress.Security.NonceVerification.Missing --- verified.
    }

    /**
     * Set security lock.
     *
     * @uses MainWP_Child_WPvivid_BackupRestore::$public_intetface::set_security_lock()
     *
     * @return mixed $ret Returned response.
     */
    public function set_security_lock() {

        global $wpvivid_plugin;

        $wpvivid_plugin->ajax_check_security();
        return isset( $_POST['backup_id'] ) && isset( $_POST['lock'] ) ? $this->public_intetface->set_security_lock( sanitize_text_field( wp_unslash( $_POST['backup_id'] ) ), sanitize_text_field( wp_unslash( $_POST['lock'] ) ) ) : false; //phpcs:ignore WordPress.Security.NonceVerification.Missing --- verified.
    }

    /**
     * View log file.
     *
     * @uses MainWP_Child_WPvivid_BackupRestore::$public_intetface::view_log()
     *
     * @return mixed $ret Returned response.
     */
    public function view_log() {

        global $wpvivid_plugin;

        $wpvivid_plugin->ajax_check_security();
        return isset( $_POST['id'] ) ? $this->public_intetface->view_log( sanitize_text_field( wp_unslash( $_POST['id'] ) ) ) : false; //phpcs:ignore WordPress.Security.NonceVerification.Missing --- verified.
    }

    /**
     * Read the last backup log entry.
     *
     * @uses MainWP_Child_WPvivid_BackupRestore::$public_intetface::read_last_backup_log()
     *
     * @return mixed $ret Returned response.
     */
    public function read_last_backup_log() {

        global $wpvivid_plugin;

        $wpvivid_plugin->ajax_check_security();
        return isset( $_POST['log_file_name'] ) ? $this->public_intetface->read_last_backup_log( sanitize_text_field( wp_unslash( $_POST['log_file_name'] ) ) ) : false; //phpcs:ignore WordPress.Security.NonceVerification.Missing --- verified.
    }

    /**
     * View backup task log.
     *
     * @uses MainWP_Child_WPvivid_BackupRestore::$public_intetface::view_backup_task_log()
     *
     * @return mixed $ret Returned response.
     */
    public function view_backup_task_log() {

        global $wpvivid_plugin;

        $wpvivid_plugin->ajax_check_security();
        return isset( $_POST['id'] ) ? $this->public_intetface->view_backup_task_log( sanitize_text_field( wp_unslash( $_POST['id'] ) ) ) : false; //phpcs:ignore WordPress.Security.NonceVerification.Missing --- verified.
    }

    /**
     * Cancel backup schedule.
     *
     * @uses MainWP_Child_WPvivid_BackupRestore::$public_intetface::backup_cancel()
     *
     * @return mixed $ret Returned response.
     */
    public function backup_cancel() {

        global $wpvivid_plugin;

        $wpvivid_plugin->ajax_check_security();
        return isset( $_POST['task_id'] ) ? $this->public_intetface->backup_cancel( sanitize_text_field( wp_unslash( $_POST['task_id'] ) ) ) : false; //phpcs:ignore WordPress.Security.NonceVerification.Missing --- verified.
    }

    /**
     * Initiate download page.
     *
     * @uses MainWP_Child_WPvivid_BackupRestore::$public_intetface::init_download_page()
     *
     * @return mixed $ret Returned response.
     */
    public function init_download_page() {

        global $wpvivid_plugin;

        $wpvivid_plugin->ajax_check_security();
        return isset( $_POST['backup_id'] ) ? $this->public_intetface->init_download_page( sanitize_text_field( wp_unslash( $_POST['backup_id'] ) ) ) : false; //phpcs:ignore WordPress.Security.NonceVerification.Missing --- verified.
    }

    /**
     * Prepare backup download.
     *
     * @uses MainWP_Child_WPvivid_BackupRestore::$public_intetface::prepare_download_backup()
     *
     * @return mixed $ret Returned response.
     */
    public function prepare_download_backup() {

        global $wpvivid_plugin;

        $wpvivid_plugin->ajax_check_security();
        return isset( $_POST['backup_id'] ) && isset( $_POST['file_name'] ) ? $this->public_intetface->prepare_download_backup( sanitize_text_field( wp_unslash( $_POST['backup_id'] ) ), sanitize_text_field( wp_unslash( $_POST['file_name'] ) ) ) : false; //phpcs:ignore WordPress.Security.NonceVerification.Missing --- verified.
    }

    /**
     * Get download task.
     *
     * @uses MainWP_Child_WPvivid_BackupRestore::$public_intetface::get_download_task()
     *
     * @return mixed $ret Returned response.
     */
    public function get_download_task() {

        global $wpvivid_plugin;

        $wpvivid_plugin->ajax_check_security();
        return isset( $_POST['backup_id'] ) ? $this->public_intetface->get_download_task( sanitize_text_field( wp_unslash( $_POST['backup_id'] ) ) ) : false; //phpcs:ignore WordPress.Security.NonceVerification.Missing --- verified.
    }

    /**
     * Download Backup.
     *
     * @uses MainWP_Child_WPvivid_BackupRestore::$public_intetface::download_backup()
     *
     * @return mixed $ret Returned response.
     */
    public function download_backup() {

        global $wpvivid_plugin;

        $wpvivid_plugin->ajax_check_security();
        return isset( $_POST['backup_id'] ) && isset( $_POST['file_name'] ) ? $this->public_intetface->download_backup( sanitize_text_field( wp_unslash( $_POST['backup_id'] ) ), sanitize_text_field( wp_unslash( $_POST['file_name'] ) ) ) : false; //phpcs:ignore WordPress.Security.NonceVerification.Missing --- verified.
    }

    /**
     * Set general settings.
     *
     * @uses MainWP_Child_WPvivid_BackupRestore::$public_intetface::set_general_settings()
     *
     * @return mixed $ret Returned response.
     */
    public function set_general_setting() {

        global $wpvivid_plugin;

        $wpvivid_plugin->ajax_check_security();
        return isset( $_POST['setting'] ) ? $this->public_intetface->set_general_setting( wp_unslash( $_POST['setting'] ) ) : false;  //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing --- verified.
    }

    /**
     * Set backup schedule.
     *
     * @uses MainWP_Child_WPvivid_BackupRestore::$public_intetface::set_schedule()
     *
     * @return mixed $ret Returned response.
     */
    public function set_schedule() {

        global $wpvivid_plugin;

        $wpvivid_plugin->ajax_check_security();
        return isset( $_POST['schedule'] ) ? $this->public_intetface->set_schedule( sanitize_text_field( wp_unslash( $_POST['schedule'] ) ) ) : false;  //phpcs:ignore WordPress.Security.NonceVerification.Missing --- verified.
    }

    /**
     * Set remote destination.
     *
     * @uses MainWP_Child_WPvivid_BackupRestore::$public_intetface::set_remote()
     *
     * @return mixed $ret Returned response.
     */
    public function set_remote() {

        global $wpvivid_plugin;

        $wpvivid_plugin->ajax_check_security();
        return isset( $_POST['remote'] ) ? $this->public_intetface->set_remote( sanitize_text_field( wp_unslash( $_POST['remote'] ) ) ) : false;  //phpcs:ignore WordPress.Security.NonceVerification.Missing --- verified.
    }
}
