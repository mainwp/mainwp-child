<?php
/**
 * MainWP Child Backup Buddy
 *
 * The code is used for the MainWP Buddy Extension.
 *
 * Credits
 *
 * Plugin-Name: BackupBuddy
 * Plugin URI: http://ithemes.com/purchase/backupbuddy/
 * Author: iThemes
 * Author URI: http://ithemes.com/
 * iThemes Package: backupbuddy
 *
 * The code is used for the MainWP Buddy Extension
 * Extension URL: https://mainwp.com/extension/mainwpbuddy/
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable -- third party credit.


/**
 * Class MainWP_Child_Back_Up_Buddy
 */
class MainWP_Child_Back_Up_Buddy { //phpcs:ignore -- NOSONAR - multi methods.

    /**
     * How far ahead of this site's clock a stored or provider timestamp may sit and still be read
     * as a real observation time.
     *
     * Neither source is written by a clock the Child owns: BackupBuddy stamps its own records, an
     * NTP correction can land mid-run, and the ledger option travels with a database that may have
     * been dumped on another machine. A few minutes ahead is ordinary skew. Past that the value is
     * not a later observation of anything, and reading it as one is what lets a record that never
     * changes look freshly observed on every probe.
     */
    const ABILITIES_V2_CLOCK_SKEW = 300;

    /**
     * Public static variable to hold the single instance of MainWP_Child_Back_Up_Buddy.
     * @var null
     */
    public static $instance  = null;

    /** @var string $plugin_translate Plugin translation string. */
    public $plugin_translate = 'mainwp-child';

    /** @var bool $is_backupbuddy_installed Whether or not BackupBuddy is installed. Default: False. */
    public $is_backupbuddy_installed = false;

    /** @var string $path_core_file path core file. */
    public $path_core_file = '/classes/core.php';

      /** @var string $backupbuddy_core_class core class name. */
      public $backupbuddy_core_class = '\backupbuddy_core';

    /**
     * Operation the current v2 request is being answered for. Errors are built deep inside the
     * helper chain, so the dispatcher records the operation here instead of threading it through
     * every call.
     *
     * @var string
     */
    private $abilities_v2_operation = 'unknown';

    /**
     * Create a public static instance of MainWP_Child_Back_Up_Buddy.
     *
     * @return MainWP_Child_Back_Up_Buddy|null
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * MainWP_Child_Back_Up_Buddy constructor.
     *
     * Run any time class is called.
     */
    public function __construct() {
        // To fix bug run dashboard on local machine.
        if ( class_exists( '\pb_backupbuddy' ) ) {
            $this->is_backupbuddy_installed = true;
        }

        if ( ! $this->is_backupbuddy_installed ) {
            return;
        }

        add_filter( 'mainwp_site_sync_others_data', array( $this, 'sync_others_data' ), 10, 2 );

        add_action( 'wp_ajax_mainwp_backupbuddy_download_archive', array( $this, 'download_archive' ) );
        add_action( 'mainwp_child_site_stats', array( $this, 'do_site_stats' ) );

        if ( get_option( 'mainwp_backupbuddy_hide_plugin' ) === 'hide' ) {
            add_filter( 'all_plugins', array( $this, 'all_plugins' ) );
            add_action( 'admin_menu', array( $this, 'admin_menu' ) );
            add_filter( 'site_transient_update_plugins', array( &$this, 'remove_update_nag' ) );
            add_filter( 'mainwp_child_hide_update_notice', array( &$this, 'hide_update_notice' ) );
        }
    }

    /**
     * Hide Backupbuddy update notice.
     *
     * @param string $slugs plugin slug.
     * @return string $slugs Return slugs array.
     */
    public function hide_update_notice( $slugs ) {
        $slugs[] = 'backupbuddy/backupbuddy.php';
        return $slugs;
    }

    /**
     * Remove update nag.
     *
     * @param array $value Plugin slug array.
     * @return array $value Plugin slug array.
     *
     * @uses \MainWP\Child\MainWP_Helper::is_updates_screen()
     */
    public function remove_update_nag( $value ) {
        if ( MainWP_Helper::is_dashboard_request() ) {
            return $value;
        }

        if ( ! MainWP_Helper::is_updates_screen() ) {
            return $value;
        }

        if ( isset( $value->response['backupbuddy/backupbuddy.php'] ) ) {
            unset( $value->response['backupbuddy/backupbuddy.php'] );
        }

        return $value;
    }


    /**
     * Remove Backup buddy from plugins list.
     *
     * @param array $plugins All plugins array.
     * @return array $plugins All plugins array with backupbuddy removed.
     */
    public function all_plugins( $plugins ) {
        foreach ( $plugins as $key => $value ) {
            $plugin_slug = basename( $key, '.php' );
            if ( 'backupbuddy' === $plugin_slug ) {
                unset( $plugins[ $key ] );
            }
        }

        return $plugins;
    }

    /**
     * Remove backupbuddy from admin menu.
     */
    public function admin_menu() {

        /**
         * Submenu array.
         *
         * @global object
         */
        global $submenu;

        remove_menu_page( 'pb_backupbuddy_backup' );

        if ( isset( $_SERVER['REQUEST_URI'] ) && false !== stripos( wp_unslash( $_SERVER['REQUEST_URI'] ), 'admin.php?page=pb_backupbuddy_' ) ) {
            wp_safe_redirect( get_option( 'siteurl' ) . '/wp-admin/index.php' );
            exit();
        }
    }


    /**
     * Backupbuddy Client Reports log.
     *
     * @uses MainWP_Child_Back_Up_Buddy::do_reports_log()
     */
    public function do_site_stats() {
        if ( has_action( 'mainwp_child_reports_log' ) ) {
            do_action( 'mainwp_child_reports_log', 'backupbuddy' );
        } else {
            $this->do_reports_log( 'backupbuddy' );
        }
    }

    /**
     * Create BackupBuddy Client Reports log.
     *
     * @param string $ext Extension to create log for.
     *
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::is_backupbuddy_installed()
     * @uses \MainWP\Child\MainWP_Helper::instance()->check_methods()
     * @uses \MainWP\Child\MainWP_Helper::instance()->check_properties()
     * @uses \MainWP\Child\MainWP_Helper::instance()->check_methods()
     * @uses \MainWP\Child\MainWP_Helper::instance()->check_classes_exists()
     * @uses \MainWP\Child\MainWP_Helper::instance()->check_methods()
     * @uses \pb_backupbuddy_fileoptions()
     * @uses \pb_backupbuddy_fileoptions::is_ok()
     * @uses \pb_backupbuddy::$format::prettify()
     * @uses \backupbuddy_live_periodic::get_stats()
     * @uses MainWP_Exception
     */
    public function do_reports_log( $ext = '' ) { // phpcs:ignore -- NOSONAR - complex.
        if ( 'backupbuddy' !== $ext ) {
            return;
        }

        if ( ! $this->is_backupbuddy_installed ) {
            return;
        }

        try {

            MainWP_Helper::instance()->check_methods( '\pb_backupbuddy', array( 'plugin_path' ) );
            if ( ! class_exists( $this->backupbuddy_core_class ) && file_exists( \pb_backupbuddy::plugin_path() . $this->path_core_file ) ) {
                require_once \pb_backupbuddy::plugin_path() . $this->path_core_file; // NOSONAR - WP compatible.
            }

            if ( file_exists( \pb_backupbuddy::plugin_path() . '/classes/fileoptions.php' ) ) {
                require_once \pb_backupbuddy::plugin_path() . '/classes/fileoptions.php'; // NOSONAR - WP compatible.
            }

            MainWP_Helper::instance()->check_classes_exists( array( $this->backupbuddy_core_class, '\pb_backupbuddy_fileoptions' ) );
            MainWP_Helper::instance()->check_methods( $this->backupbuddy_core_class, 'getLogDirectory' );

            $pretty_type = array(
                'full'  => 'Full',
                'db'    => 'Database',
                'files' => 'Files',
            );

            $recentBackups_list = glob( \backupbuddy_core::getLogDirectory() . 'fileoptions/*.txt' );
            $lasttime_logged    = (int) MainWP_Utility::get_lasttime_backup( 'backupbuddy' );
            $new_lasttime       = $lasttime_logged;
            $can_advance_cursor = true;

            foreach ( $recentBackups_list as $backup_fileoptions ) {

                $backup = new \pb_backupbuddy_fileoptions( $backup_fileoptions, true );
                $result = $backup->is_ok();
                if ( method_exists( $backup, 'is_ok' ) && true !== $result ) {
                    continue;
                }

                $backup = &$backup->options;

                if ( ! isset( $backup['serial'] ) || ( '' === $backup['serial'] ) ) {
                    continue;
                }

                $check_finished = false;
                if ( ( $backup['finish_time'] >= $backup['start_time'] ) && ( 0 !== (int) $backup['start_time'] ) ) {
                    $check_finished = true;
                }

                if ( ! $check_finished ) {
                    continue;
                }

                $backupType = '';
                if ( isset( $backup['profile'] ) && isset( $backup['profile']['type'] ) ) {
                    $chk_pro = false;
                    if ( true === MainWP_Helper::instance()->check_properties( '\pb_backupbuddy', 'format', true ) ) {
                        $chk_pro = true;
                    }
                    if ( $chk_pro && true === MainWP_Helper::instance()->check_methods( \pb_backupbuddy::$format, array( 'prettify' ), true ) ) {
                        $backupType = \pb_backupbuddy::$format->prettify( $backup['profile']['type'], $pretty_type );
                    }
                } elseif ( true === MainWP_Helper::instance()->check_methods( $this->backupbuddy_core_class, array( 'pretty_backup_type', 'getBackupTypeFromFile' ), true ) ) {
                    $backupType = \backupbuddy_core::pretty_backup_type( \backupbuddy_core::getBackupTypeFromFile( $backup['archive_file'] ) );
                }

                if ( '' === $backupType ) {
                    $backupType = 'Unknown';
                }

                $finish_time = $backup['finish_time'];
                $message     = 'BackupBuddy ' . $backupType . ' finished';
                if ( ! empty( $finish_time ) ) {
                    $fingerprint = MainWP_Utility::backup_fingerprint( 'backupbuddy', basename( $backup_fileoptions ) );
                    do_action( 'mainwp_reports_backupbuddy_backup', $message, $backupType, $finish_time, $fingerprint );
                    if ( MainWP_Utility::backup_fingerprint_logged( $fingerprint ) ) {
                        $new_lasttime = max( $new_lasttime, (int) $finish_time );
                    } else {
                        $can_advance_cursor = false;
                    }
                }
            }

            if ( file_exists( \pb_backupbuddy::plugin_path() . '/destinations/live/live_periodic.php' ) ) {
                require_once \pb_backupbuddy::plugin_path() . '/destinations/live/live_periodic.php'; // NOSONAR - WP compatible.

                MainWP_Helper::instance()->check_classes_exists( array( '\backupbuddy_live_periodic' ) );
                MainWP_Helper::instance()->check_methods( '\backupbuddy_live_periodic', 'get_stats' ); // NOSONAR - WP compatible.

                $state = \backupbuddy_live_periodic::get_stats();

                $chk_last_remote = false;
                if ( is_array( $state ) && isset( $state['stats'] ) && is_array( $state['stats'] ) && isset( $state['stats']['last_remote_snapshot'] ) && isset( $state['stats']['last_remote_snapshot_response'] ) ) {
                    $chk_last_remote = true;
                }

                if( $chk_last_remote ){
                    $resp = $state['stats']['last_remote_snapshot_response'];
                    if ( isset( $resp['success'] ) && $resp['success'] ) {
                        $finish_time = $state['stats']['last_remote_snapshot'];
                        $backupType  = 'Live Backup to cloud';
                        $message     = 'BackupBuddy ' . $backupType . ' finished';
                        if ( ! empty( $finish_time ) ) {
                            $fingerprint = MainWP_Utility::backup_fingerprint( 'backupbuddy', 'live', $finish_time );
                            do_action( 'mainwp_reports_backupbuddy_backup', $message, $backupType, $finish_time, $fingerprint );
                            if ( MainWP_Utility::backup_fingerprint_logged( $fingerprint ) ) {
                                $new_lasttime = max( $new_lasttime, (int) $finish_time );
                            } else {
                                $can_advance_cursor = false;
                            }
                        }
                    }
                }
            }

            if ( $can_advance_cursor && $new_lasttime > $lasttime_logged ) {
                MainWP_Utility::update_lasttime_backup( 'backupbuddy', $new_lasttime );
            }
        } catch ( MainWP_Exception $e ) {
            // ok!
        }
    }

    /**
     * MainWP Child BackupBuddy actions.
     *
     * @uses \MainWP\Child\MainWP_Helper::write()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::is_backupbuddy_installed()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::set_showhide()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::save_settings()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::reset_defaults()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::get_notifications()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::schedules_list()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::run_scheduled_backup()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::save_scheduled_backup()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::delete_scheduled_backup()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::save_profile()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::delete_profile()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::delete_backup()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::backup_list()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::save_note()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::get_hash()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::zip_viewer()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::exclude_tree()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::restore_file_view()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::restore_file_restore()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::view_log()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::view_detail()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::reset_integrity()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::download_archive()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::create_backup()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::start_backup()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::backup_status()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::stop_backup()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::remote_save()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::remote_delete()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::remote_send()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::remote_list()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::get_main_log()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::settings_other()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::malware_scan()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::live_setup()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::live_save_settings()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::live_action_disconnect()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::live_action()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::download_troubleshooting()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::get_live_backups()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::copy_file_to_local()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::delete_file_backup()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::get_live_stats()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::load_products_license()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::save_license_settings()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::activate_package()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::deactivate_package()
     */
    public function action() { //phpcs:ignore -- NOSONAR - multi lines.
        $information = array();
        $mwp_action  = MainWP_System::instance()->validate_params( 'mwp_action' );
        if ( 'abilities_v2' === $mwp_action ) {
            $request = isset( $_POST['request'] ) ? wp_unslash( $_POST['request'] ) : array();
            if ( is_string( $request ) ) {
                $request = json_decode( $request, true );
            }
            MainWP_Helper::write( $this->abilities_v2( $request ) );
            return;
        }
        if ( ! $this->is_backupbuddy_installed ) {
            MainWP_Helper::write( array( 'error' => esc_html__( 'Please install the BackupBuddy plugin on the child site.', $this->plugin_translate ) ) );
        }
        if ( ! class_exists( $this->backupbuddy_core_class ) ) {
            require_once \pb_backupbuddy::plugin_path() . $this->path_core_file; // NOSONAR - WP compatible.
        }

        if ( ! isset( \pb_backupbuddy::$options ) ) {
            \pb_backupbuddy::load();
        }

        if ( ! empty( $mwp_action ) ) {
            switch ( $mwp_action ) { // NOSONAR - multi case.
                case 'set_showhide':
                    $information = $this->set_showhide();
                    break;
                case 'save_settings':
                    $information = $this->save_settings();
                    break;
                case 'reset_defaults':
                    $information = $this->reset_defaults();
                    break;
                case 'get_notifications':
                    $information = $this->get_notifications();
                    break;
                case 'schedules_list':
                    $information = $this->schedules_list();
                    break;
                case 'run_scheduled_backup':
                    $information = $this->run_scheduled_backup();
                    break;
                case 'save_scheduled_backup':
                    $information = $this->save_scheduled_backup();
                    break;
                case 'delete_scheduled_backup':
                    $information = $this->delete_scheduled_backup();
                    break;
                case 'save_profile':
                    $information = $this->save_profile();
                    break;
                case 'delete_profile':
                    $information = $this->delete_profile();
                    break;
                case 'delete_backup':
                    $information = $this->delete_backup();
                    break;
                case 'backup_list':
                    $information = $this->backup_list();
                    break;
                case 'save_note':
                    $information = $this->save_note();
                    break;
                case 'get_hash':
                    $information = $this->get_hash();
                    break;
                case 'zip_viewer':
                    $information = $this->zip_viewer();
                    break;
                case 'exclude_tree':
                    $information = $this->exclude_tree();
                    break;
                case 'restore_file_view':
                    $information = $this->restore_file_view();
                    break;
                case 'restore_file_restore':
                    $information = $this->restore_file_restore();
                    break;
                case 'view_log':
                    $information = $this->view_log();
                    break;
                case 'view_detail':
                    $information = $this->view_detail();
                    break;
                case 'reset_integrity':
                    $information = $this->reset_integrity();
                    break;
                case 'download_archive':
                    $this->download_archive();
                    break;
                case 'create_backup':
                    $information = $this->create_backup();
                    break;
                case 'start_backup':
                    $information = $this->start_backup();
                    break;
                case 'backup_status':
                    $information = $this->backup_status();
                    break;
                case 'stop_backup':
                    $information = $this->stop_backup();
                    break;
                case 'remote_save':
                    $information = $this->remote_save();
                    break;
                case 'remote_delete':
                    $information = $this->remote_delete();
                    break;
                case 'remote_send':
                    $information = $this->remote_send();
                    break;
                case 'remote_list':
                    $information = $this->remote_list();
                    break;
                case 'get_main_log':
                    $information = $this->get_main_log();
                    break;
                case 'settings_other':
                    $information = $this->settings_other();
                    break;
                case 'malware_scan':
                    $information = $this->malware_scan();
                    break;
                case 'live_setup':
                    $information = $this->live_setup();
                    break;
                case 'live_save_settings':
                    $information = $this->live_save_settings();
                    break;
                case 'live_action_disconnect':
                    $information = $this->live_action_disconnect();
                    break;
                case 'live_action':
                    $information = $this->live_action();
                    break;
                case 'download_troubleshooting':
                    $information = $this->download_troubleshooting();
                    break;
                case 'get_live_backups':
                    $information = $this->get_live_backups();
                    break;
                case 'copy_file_to_local':
                    $information = $this->copy_file_to_local();
                    break;
                case 'delete_file_backup':
                    $information = $this->delete_file_backup();
                    break;
                case 'get_live_stats':
                    $information = $this->get_live_stats();
                    break;
                case 'load_products_license':
                    $information = $this->load_products_license();
                    break;
                case 'save_license_settings':
                    $information = $this->save_license_settings();
                    break;
                case 'activate_package':
                    $information = $this->activate_package();
                    break;
                case 'deactivate_package':
                    $information = $this->deactivate_package();
                    break;
                default:
                    break;
            }
        }
        MainWP_Helper::write( $information );
    }

    /**
     * Execute the closed BackupBuddy abilities v2 protocol.
     *
     * @param array $request Typed request.
     * @return array
     */
    public function abilities_v2( $request ) { // phpcs:ignore -- Closed dispatcher is intentionally explicit.
        $allowed = array(
            'capabilities'      => array( 'operation' ),
            'list_profiles'     => array( 'operation', 'page', 'per_page' ),
            'list_schedules'    => array( 'operation', 'page', 'per_page' ),
            'list_destinations' => array( 'operation', 'page', 'per_page' ),
            'list_archives'     => array( 'operation', 'page', 'per_page', 'type' ),
            'preview_run'       => array( 'operation', 'target_kind', 'target_ref' ),
            'start_backup'      => array( 'operation', 'profile_ref', 'preview_token', 'request_ref' ),
            'run_schedule'      => array( 'operation', 'schedule_ref', 'preview_token', 'request_ref' ),
            'get_operation'     => array( 'operation', 'operation_ref' ),
            'cancel_operation'  => array( 'operation', 'operation_ref' ),
            'delete_archive'    => array( 'operation', 'archive_ref', 'expected_size_bytes', 'expected_modified_at', 'request_ref' ),
            'start_transfer'    => array( 'operation', 'archive_ref', 'destination_ref', 'request_ref', 'delete_local_after' ),
        );
        // An unrecognised operation is never echoed back, so a malformed request cannot reflect its own input.
        $this->abilities_v2_operation = is_array( $request ) && isset( $request['operation'] ) && is_string( $request['operation'] ) && isset( $allowed[ $request['operation'] ] ) ? $request['operation'] : 'unknown';
        if ( 'unknown' === $this->abilities_v2_operation ) {
            return $this->abilities_v2_error( 'invalid_request' );
        }

        $operation    = $this->abilities_v2_operation;
        $request_keys = array_keys( $request );
        $allowed_keys = $allowed[ $operation ];
        if ( array() !== array_values( array_diff( $request_keys, $allowed_keys ) ) ) {
            return $this->abilities_v2_error( 'invalid_request' );
        }

        if ( 'capabilities' === $operation ) {
            return array(
                'protocol'   => '2',
                'operations' => array_keys( $allowed ),
            );
        }

        // The payload is judged on its own terms before the provider gate, so a malformed request is
        // invalid_request everywhere instead of backupbuddy_unavailable on sites without the plugin.
        if ( ! $this->abilities_v2_valid_payload( $operation, $request ) ) {
            return $this->abilities_v2_error( 'invalid_request' );
        }
        if ( ! $this->is_backupbuddy_installed ) {
            return $this->abilities_v2_error( 'backupbuddy_unavailable' );
        }
        $this->abilities_v2_load_provider();

        $page     = isset( $request['page'] ) ? $request['page'] : 1;
        $per_page = isset( $request['per_page'] ) ? $request['per_page'] : 25;

        switch ( $operation ) {
            case 'list_profiles':
                return $this->abilities_v2_list_profiles( $page, $per_page );
            case 'list_schedules':
                return $this->abilities_v2_list_schedules( $page, $per_page );
            case 'list_destinations':
                return $this->abilities_v2_list_destinations( $page, $per_page );
            case 'list_archives':
                return $this->abilities_v2_list_archives( $page, $per_page, isset( $request['type'] ) ? $request['type'] : 'all' );
            case 'preview_run':
                return $this->abilities_v2_preview( $request['target_kind'], $request['target_ref'], $this->abilities_v2_now() );
            case 'start_backup':
            case 'run_schedule':
                return $this->abilities_v2_start_operation( $operation, $request );
            case 'get_operation':
                return $this->abilities_v2_get_operation( $request['operation_ref'] );
            case 'cancel_operation':
                return $this->abilities_v2_cancel_operation( $request['operation_ref'] );
            case 'delete_archive':
                return $this->abilities_v2_delete_archive( $request );
            case 'start_transfer':
                return $this->abilities_v2_start_transfer( $request );
        }
        return $this->abilities_v2_error( 'invalid_request' );
    }

    /**
     * Validate one operation's typed payload in full.
     *
     * @param string $operation Operation.
     * @param array  $request   Typed request.
     * @return bool
     */
    private function abilities_v2_valid_payload( $operation, $request ) { // phpcs:ignore -- Closed per-operation payload table is intentionally explicit.
        if ( 0 === strpos( $operation, 'list_' ) ) {
            $page     = isset( $request['page'] ) ? $request['page'] : 1;
            $per_page = isset( $request['per_page'] ) ? $request['per_page'] : 25;
            if ( ! is_int( $page ) || $page < 1 || ! is_int( $per_page ) || $per_page < 1 || $per_page > 100 ) {
                return false;
            }
        }

        switch ( $operation ) {
            case 'list_archives':
                return in_array( isset( $request['type'] ) ? $request['type'] : 'all', array( 'all', 'full', 'database', 'files' ), true );
            case 'preview_run':
                return isset( $request['target_kind'], $request['target_ref'] ) && in_array( $request['target_kind'], array( 'profile', 'schedule' ), true ) && $this->abilities_v2_valid_ref( $request['target_ref'] );
            case 'start_backup':
            case 'run_schedule':
                $target_key = 'start_backup' === $operation ? 'profile_ref' : 'schedule_ref';
                return isset( $request[ $target_key ], $request['preview_token'], $request['request_ref'] ) && $this->abilities_v2_valid_ref( $request[ $target_key ] ) && $this->abilities_v2_valid_ref( $request['preview_token'] ) && $this->abilities_v2_valid_request_ref( $request['request_ref'] );
            case 'get_operation':
            case 'cancel_operation':
                return isset( $request['operation_ref'] ) && $this->abilities_v2_valid_ref( $request['operation_ref'] );
            case 'delete_archive':
                return isset( $request['archive_ref'], $request['expected_size_bytes'], $request['expected_modified_at'], $request['request_ref'] ) && $this->abilities_v2_valid_ref( $request['archive_ref'] ) && is_int( $request['expected_size_bytes'] ) && $request['expected_size_bytes'] >= 0 && is_int( $request['expected_modified_at'] ) && $request['expected_modified_at'] >= 0 && $this->abilities_v2_valid_request_ref( $request['request_ref'] );
            case 'start_transfer':
                return isset( $request['archive_ref'], $request['destination_ref'], $request['request_ref'], $request['delete_local_after'] ) && false === $request['delete_local_after'] && $this->abilities_v2_valid_ref( $request['archive_ref'] ) && $this->abilities_v2_valid_ref( $request['destination_ref'] ) && $this->abilities_v2_valid_request_ref( $request['request_ref'] );
        }
        return true;
    }

    /**
     * The v2 dispatcher never reaches the legacy action() branch that loads BackupBuddy, so every
     * v2 read would otherwise see an unset \pb_backupbuddy::$options and a missing core class and
     * report an empty site. Each step is guarded so a site without BackupBuddy cannot fatal here.
     *
     * @return void
     */
    protected function abilities_v2_load_provider() {
        if ( ! class_exists( '\\pb_backupbuddy' ) || ! method_exists( '\\pb_backupbuddy', 'plugin_path' ) ) {
            return;
        }
        if ( ! class_exists( $this->backupbuddy_core_class ) ) {
            $core_file = \pb_backupbuddy::plugin_path() . $this->path_core_file;
            if ( file_exists( $core_file ) ) {
                require_once $core_file; // NOSONAR - WP compatible.
            }
        }
        if ( ! isset( \pb_backupbuddy::$options ) && method_exists( '\\pb_backupbuddy', 'load' ) ) {
            \pb_backupbuddy::load();
        }
    }

    /** @return int */
    protected function abilities_v2_now() {
        return time();
    }

    /**
     * A timestamp out of stored state or out of a BackupBuddy record, only when it can be believed.
     *
     * Both are untrusted input. The option can be edited, restored, or carried over from another
     * host, and a provider record is written by whichever build of BackupBuddy is installed. A value
     * that is not a positive number, or that sits further ahead than ABILITIES_V2_CLOCK_SKEW allows,
     * states nothing about when anything happened, so callers get null and must treat the record as
     * one they hold no advancing evidence for.
     *
     * @param mixed $value Timestamp as read.
     * @return int|null
     */
    private function abilities_v2_trusted_time( $value ) {
        if ( ! is_numeric( $value ) || (int) $value <= 0 ) {
            return null;
        }
        $seconds = (int) $value;
        return $seconds > $this->abilities_v2_now() + self::ABILITIES_V2_CLOCK_SKEW ? null : $seconds;
    }

    /**
     * Whether the ledger holds no believable observation of a record since $horizon.
     *
     * An updated_at that cannot be believed is not a recent observation, so the record counts as
     * unobserved rather than as permanently fresh. Reading it the other way would keep such a
     * record out of both the staleness horizon and the age-out pass at once, and a hundred of them
     * would refuse every new operation forever.
     *
     * @param array $record  Stored operation record.
     * @param int   $horizon Oldest observation time that still counts as recent.
     * @return bool
     */
    private function abilities_v2_unobserved_since( $record, $horizon ) {
        $observed = $this->abilities_v2_trusted_time( isset( $record['updated_at'] ) ? $record['updated_at'] : null );
        return null === $observed || $observed < $horizon;
    }

    /** @return string */
    protected function abilities_v2_secret() {
        return hash_hmac( 'sha256', 'mainwp-backupbuddy-abilities-v1', wp_salt( 'auth' ) );
    }

    /** @return array */
    protected function abilities_v2_read_options() {
        return isset( \pb_backupbuddy::$options ) && is_array( \pb_backupbuddy::$options ) ? \pb_backupbuddy::$options : array();
    }

    /** @return mixed */
    protected function abilities_v2_read_records() {
        $records = get_option( 'mainwp_backupbuddy_ability_operations_v1', array() );
        return $records;
    }

    /**
     * @param array $records Records.
     * @return bool
     */
    protected function abilities_v2_write_records( $records ) {
        return MainWP_Helper::update_option( 'mainwp_backupbuddy_ability_operations_v1', $records );
    }

    /**
     * @param string $code Stable code.
     * @return array
     */
    private function abilities_v2_error( $code ) {
        $codes = array(
            'invalid_request',
            'not_found',
            'request_conflict',
            'preview_stale',
            'operation_limit_reached',
            'effect_failed',
            'storage_failed',
            'lock_busy',
            'too_large',
            'outcome_unknown',
            'backupbuddy_unavailable',
        );
        return array(
            'protocol'  => '2',
            'operation' => $this->abilities_v2_operation,
            'ok'        => false,
            'code'      => in_array( $code, $codes, true ) ? $code : 'effect_failed',
        );
    }

    /**
     * Recognise a v2 error envelope. Helpers return either their result or this envelope, and the
     * reserved top-level 'error' key is not part of the v2 shape any more.
     *
     * @param mixed $value Helper result.
     * @return bool
     */
    private function abilities_v2_is_error( $value ) {
        return is_array( $value ) && isset( $value['ok'] ) && false === $value['ok'];
    }

    /**
     * Report a failed lock release without rewriting the outcome it accompanies.
     *
     * The effect is already committed and the record already written by the time the lock is
     * released, so a failed release cannot make any of that untrue. The lock expires on its own
     * 120s TTL; the caller is told about the failure through an advisory warning instead of being
     * handed a storage_failed that did not happen.
     *
     * @param array $result   Result produced under the lock.
     * @param bool  $released Whether the lock release succeeded.
     * @return array
     */
    private function abilities_v2_with_lock_release( $result, $released ) {
        if ( $released || ! is_array( $result ) ) {
            return $result;
        }
        $warnings                  = isset( $result['warning_codes'] ) && is_array( $result['warning_codes'] ) ? $result['warning_codes'] : array();
        $warnings[]                = 'lock_release_failed';
        $result['warning_codes']   = array_values( array_unique( $warnings ) );
        return $result;
    }

    /**
     * @param mixed $value Value.
     * @return bool
     */
    private function abilities_v2_valid_ref( $value ) {
        return is_string( $value ) && strlen( $value ) >= 24 && strlen( $value ) <= 512 && 1 === preg_match( '/^[A-Za-z0-9._~-]+$/D', $value );
    }

    /**
     * @param mixed $value Value.
     * @return bool
     */
    private function abilities_v2_valid_request_ref( $value ) {
        return is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value );
    }

    /**
     * @param string $type        Type prefix.
     * @param string $internal_id Internal identifier.
     * @return string
     */
    private function abilities_v2_ref( $type, $internal_id ) {
        return $type . '.v1.' . hash_hmac( 'sha256', $type . "\0" . $internal_id, $this->abilities_v2_secret() );
    }

    /**
     * @param string $value Value.
     * @param int    $max   Maximum length.
     * @return string
     */
    private function abilities_v2_text( $value, $max ) {
        if ( ! is_string( $value ) || preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
            return '';
        }
        return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
    }

    /**
     * Acquire the single per-site BackupBuddy effect lock.
     *
     * @return string|false
     */
    protected function abilities_v2_acquire_effect_lock() {
        $key      = 'mainwp_backupbuddy_ability_effect_lock_v1';
        $now      = $this->abilities_v2_now();
        $owner    = wp_generate_uuid4();
        $existing = get_option( $key, false );
        $usable   = is_array( $existing ) && isset( $existing['expires_at'] ) && is_int( $existing['expires_at'] );
        if ( false !== $existing && ( ! $usable || $existing['expires_at'] <= $now ) ) {
            delete_option( $key );
        }
        $value = array( 'owner' => $owner, 'expires_at' => $now + 120 );
        if ( ! add_option( $key, $value, '', 'no' ) ) {
            return false;
        }
        return $value === get_option( $key, false ) ? $owner : false;
    }

    /**
     * Release the single per-site BackupBuddy effect lock.
     *
     * @param string $owner Lock owner.
     * @return bool
     */
    protected function abilities_v2_release_effect_lock( $owner ) {
        $key     = 'mainwp_backupbuddy_ability_effect_lock_v1';
        $current = get_option( $key, false );
        if ( ! is_array( $current ) || ! isset( $current['owner'] ) || ! is_string( $current['owner'] ) || ! hash_equals( $current['owner'], $owner ) ) {
            return false;
        }
        delete_option( $key );
        return false === get_option( $key, false );
    }

    /**
     * @param array  $items    Items.
     * @param int    $page     Page.
     * @param int    $per_page Per page.
     * @param string $key      Item key.
     * @return array
     */
    private function abilities_v2_page( $items, $page, $per_page, $key ) {
        return array(
            'page'     => $page,
            'per_page' => $per_page,
            'total'    => count( $items ),
            $key       => array_values( array_slice( $items, ( $page - 1 ) * $per_page, $per_page ) ),
        );
    }

    /**
     * @param int $page Page.
     * @param int $per_page Per page.
     * @return array
     */
    private function abilities_v2_list_profiles( $page, $per_page ) {
        $options  = $this->abilities_v2_read_options();
        $profiles = isset( $options['profiles'] ) && is_array( $options['profiles'] ) ? $options['profiles'] : array();
        $items    = array();
        foreach ( $profiles as $id => $profile ) {
            if ( ! is_array( $profile ) || ! isset( $profile['type'] ) || ! is_string( $profile['type'] ) ) {
                continue;
            }
            $type = $this->abilities_v2_profile_type( $profile['type'] );
            $items[] = array(
                'profile_ref' => $this->abilities_v2_ref( 'prf', (string) $id ),
                'title'       => $this->abilities_v2_text( isset( $profile['title'] ) ? $profile['title'] : ucfirst( $type ), 200 ),
                'type'        => $type,
                'built_in'    => in_array( (string) $id, array( '0', '1', '2', '3' ), true ),
            );
        }
        usort( $items, static function ( $left, $right ) { return strcmp( $left['profile_ref'], $right['profile_ref'] ); } );
        return $this->abilities_v2_page( $items, $page, $per_page, 'profiles' );
    }

    /**
     * @param string $type Product type.
     * @return string
     */
    private function abilities_v2_profile_type( $type ) {
        $map = array( 'full' => 'full', 'db' => 'database', 'database' => 'database', 'files' => 'files', 'themes' => 'themes', 'plugins' => 'plugins', 'media' => 'media' );
        return isset( $map[ $type ] ) ? $map[ $type ] : 'custom';
    }

    /**
     * @param int $schedule_id Schedule ID.
     * @return int|null
     */
    protected function abilities_v2_schedule_next_run( $schedule_id ) {
        $next = wp_next_scheduled( 'backupbuddy_cron', array( 'run_scheduled_backup', array( (int) $schedule_id ) ) );
        return false === $next ? null : (int) $next;
    }

    /**
     * @param int $page Page.
     * @param int $per_page Per page.
     * @return array
     */
    private function abilities_v2_list_schedules( $page, $per_page ) {
        $options   = $this->abilities_v2_read_options();
        $schedules = isset( $options['schedules'] ) && is_array( $options['schedules'] ) ? $options['schedules'] : array();
        $items     = array();
        foreach ( $schedules as $id => $schedule ) {
            if ( ! is_array( $schedule ) ) {
                continue;
            }
            $profile_id = isset( $schedule['profile'] ) ? $schedule['profile'] : ( isset( $schedule['profile_id'] ) ? $schedule['profile_id'] : null );
            if ( null === $profile_id ) {
                continue;
            }
            $supported = ! isset( $schedule['delete_after'] ) || false === $schedule['delete_after'] || 0 === $schedule['delete_after'] || '0' === $schedule['delete_after'];
            $items[]   = array(
                'schedule_ref' => $this->abilities_v2_ref( 'sch', (string) $id ),
                'title'        => $this->abilities_v2_text( isset( $schedule['title'] ) ? $schedule['title'] : 'Schedule', 200 ),
                'profile_ref'  => $this->abilities_v2_ref( 'prf', (string) $profile_id ),
                'interval'     => $this->abilities_v2_text( isset( $schedule['interval'] ) ? $schedule['interval'] : '', 100 ),
                'next_run_at'  => $this->abilities_v2_schedule_next_run( $id ),
                'last_run_at'  => isset( $schedule['last_run'] ) && is_numeric( $schedule['last_run'] ) ? (int) $schedule['last_run'] : null,
                'supported'    => $supported,
            );
        }
        usort( $items, static function ( $left, $right ) { return strcmp( $left['schedule_ref'], $right['schedule_ref'] ); } );
        return $this->abilities_v2_page( $items, $page, $per_page, 'schedules' );
    }

    /**
     * @param int $page Page.
     * @param int $per_page Per page.
     * @return array
     */
    private function abilities_v2_list_destinations( $page, $per_page ) {
        $options      = $this->abilities_v2_read_options();
        $destinations = isset( $options['remote_destinations'] ) && is_array( $options['remote_destinations'] ) ? $options['remote_destinations'] : array();
        $items        = array();
        foreach ( $destinations as $id => $destination ) {
            if ( ! is_array( $destination ) ) {
                continue;
            }
            $type = isset( $destination['type'] ) && is_string( $destination['type'] ) ? strtolower( $destination['type'] ) : '';
            if ( '' === $type || ! preg_match( '/^[a-z0-9_-]{1,100}$/D', $type ) ) {
                $type = 'unknown';
            }
            $items[] = array(
                'destination_ref' => $this->abilities_v2_ref( 'dst', (string) $id ),
                'type'            => $type,
                'label'           => $this->abilities_v2_text( isset( $destination['title'] ) ? $destination['title'] : ( isset( $destination['name'] ) ? $destination['name'] : ucfirst( $type ) ), 200 ),
                'configured'      => $this->abilities_v2_destination_configured( $type, $destination ),
            );
        }
        usort( $items, static function ( $left, $right ) { return strcmp( $left['destination_ref'], $right['destination_ref'] ); } );
        return $this->abilities_v2_page( $items, $page, $per_page, 'destinations' );
    }

    /**
     * @param string $type Destination type.
     * @param array  $destination Internal destination settings.
     * @return bool
     */
    private function abilities_v2_destination_configured( $type, $destination ) {
        $required = array(
            's3'        => array( 'accesskey', 'secretkey', 'bucket' ),
            's32'       => array( 'accesskey', 'secretkey', 'bucket' ),
            's33'       => array( 'accesskey', 'secretkey', 'bucket' ),
            'ftp'       => array( 'address', 'username', 'password', 'path' ),
            'sftp'      => array( 'address', 'username', 'password', 'path' ),
            'rackspace' => array( 'username', 'api_key', 'container' ),
            'gdrive'    => array( 'client_id', 'client_secret', 'tokens' ),
            'local'     => array( 'path' ),
            'site'      => array( 'api_key' ),
            'dropbox2'  => array( 'access_token' ),
            'email'     => array( 'address' ),
            'stash2'    => array( 'itxapi_username', 'itxapi_token' ),
            'stash3'    => array( 'itxapi_username', 'itxapi_token' ),
        );
        if ( ! isset( $required[ $type ] ) || isset( $destination['disabled'] ) && '1' === (string) $destination['disabled'] ) {
            return false;
        }
        foreach ( $required[ $type ] as $key ) {
            if ( ! isset( $destination[ $key ] ) || ! is_string( $destination[ $key ] ) || '' === trim( $destination[ $key ] ) ) {
                return false;
            }
        }
        return true;
    }

    /** @return array */
    protected function abilities_v2_archive_candidates() {
        if ( ! class_exists( $this->backupbuddy_core_class ) || ! method_exists( $this->backupbuddy_core_class, 'getBackupDirectory' ) ) {
            return array();
        }
        $directory = realpath( \backupbuddy_core::getBackupDirectory() );
        if ( false === $directory || ! is_dir( $directory ) ) {
            return array();
        }
        $directory = rtrim( str_replace( '\\', '/', $directory ), '/' ) . '/';
        $files     = glob( $directory . '*.zip' );
        if ( ! is_array( $files ) ) {
            return array();
        }
        if ( count( $files ) > 10000 ) {
            return array( '__error' => 'too_large' );
        }
        $archives = array();
        foreach ( $files as $file ) {
            if ( is_link( $file ) || ! is_file( $file ) ) {
                continue;
            }
            $resolved = realpath( $file );
            if ( false === $resolved || 0 !== strpos( str_replace( '\\', '/', $resolved ), $directory ) ) {
                continue;
            }
            $basename = basename( $resolved );
            if ( false !== strpos( $basename, '/' ) || false !== strpos( $basename, '\\' ) || '.zip' !== strtolower( substr( $basename, -4 ) ) ) {
                continue;
            }
            $lower = strtolower( $basename );
            $type  = false !== strpos( $lower, '-db-' ) ? 'database' : ( false !== strpos( $lower, '-files-' ) ? 'files' : ( false !== strpos( $lower, '-full-' ) ? 'full' : 'unknown' ) );
            $archives[] = array(
                'internal_id' => $basename,
                'type'        => $type,
                'size_bytes'  => (int) filesize( $resolved ),
                'modified_at' => (int) filemtime( $resolved ),
                'status'      => filesize( $resolved ) > 0 ? 'complete' : 'incomplete',
                'path'        => $resolved,
            );
        }
        return $archives;
    }

    /**
     * @param int    $page Page.
     * @param int    $per_page Per page.
     * @param string $type Type.
     * @return array
     */
    private function abilities_v2_list_archives( $page, $per_page, $type ) {
        $archives = $this->abilities_v2_archives();
        if ( $this->abilities_v2_is_error( $archives ) ) {
            return $archives;
        }
        $items    = array();
        foreach ( $archives as $archive ) {
            if ( 'all' !== $type && $type !== $archive['type'] ) {
                continue;
            }
            $items[] = array(
                'archive_ref' => $this->abilities_v2_ref( 'arc', $archive['internal_id'] ),
                'type'        => $archive['type'],
                'size_bytes'  => $archive['size_bytes'],
                'modified_at' => $archive['modified_at'],
                'status'      => $archive['status'],
            );
        }
        return $this->abilities_v2_page( $items, $page, $per_page, 'archives' );
    }

    /** @return array */
    private function abilities_v2_archives() {
        $archives = $this->abilities_v2_archive_candidates();
        if ( is_array( $archives ) && isset( $archives['__error'] ) ) {
            return $this->abilities_v2_error( 'too_large' );
        }
        if ( ! is_array( $archives ) ) {
            return $this->abilities_v2_error( 'storage_failed' );
        }
        $valid    = array();
        foreach ( $archives as $archive ) {
            if ( ! is_array( $archive ) || ! isset( $archive['internal_id'], $archive['type'], $archive['size_bytes'], $archive['modified_at'], $archive['status'], $archive['path'] ) || ! is_string( $archive['internal_id'] ) || ! is_string( $archive['path'] ) || ! in_array( $archive['type'], array( 'full', 'database', 'files', 'unknown' ), true ) || ! is_int( $archive['size_bytes'] ) || $archive['size_bytes'] < 0 || ! is_int( $archive['modified_at'] ) || $archive['modified_at'] < 0 || ! in_array( $archive['status'], array( 'complete', 'incomplete' ), true ) ) {
                return $this->abilities_v2_error( 'storage_failed' );
            }
            $valid[] = $archive;
        }
        usort(
            $valid,
            static function ( $left, $right ) {
                if ( $left['modified_at'] === $right['modified_at'] ) {
                    return strcmp( $left['internal_id'], $right['internal_id'] );
                }
                return $left['modified_at'] > $right['modified_at'] ? -1 : 1;
            }
        );
        return $valid;
    }

    /**
     * @param string $kind Target kind.
     * @param string $ref Target ref.
     * @param int    $issued_at Issued timestamp.
     * @return array
     */
    private function abilities_v2_preview( $kind, $ref, $issued_at ) {
        $target = $this->abilities_v2_resolve_target( $kind, $ref );
        if ( ! is_array( $target ) ) {
            return $this->abilities_v2_error( 'not_found' );
        }
        if ( 'schedule' === $kind && ! empty( $target['too_large'] ) ) {
            return $this->abilities_v2_error( 'too_large' );
        }
        if ( 'schedule' === $kind && empty( $target['supported'] ) ) {
            return $this->abilities_v2_error( 'request_conflict' );
        }
        $profile = 'profile' === $kind ? $target : $target['profile'];
        $victims = $this->abilities_v2_retention_victims( $profile['type'] );
        if ( $this->abilities_v2_is_error( $victims ) ) {
            return $victims;
        }
        $remote  = 'schedule' === $kind ? $target['remote_ids'] : array();
        $snapshot = array(
            'kind'       => $kind,
            'target'     => $target['internal_id'],
            'profile'    => $profile['internal_id'],
            'type'       => $profile['type'],
            'victims'    => array_map(
                static function ( $item ) {
                    return array(
                        'internal_id' => $item['internal_id'],
                        'size_bytes'  => $item['size_bytes'],
                        'modified_at' => $item['modified_at'],
                    );
                },
                $victims
            ),
            'remote_ids' => array_values( $remote ),
            'delete'     => 'schedule' === $kind ? $target['delete_after'] : false,
        );
        $digest = hash_hmac( 'sha256', (string) $issued_at . "\0" . wp_json_encode( $snapshot ), $this->abilities_v2_secret() );
        return array(
            'target_kind'                   => $kind,
            'target_ref'                    => $ref,
            'backup_type'                   => $profile['type'],
            'retention_delete_archive_refs' => array_map( function ( $item ) { return $this->abilities_v2_ref( 'arc', $item['internal_id'] ); }, $victims ),
            'remote_destination_refs'        => array_map( function ( $id ) { return $this->abilities_v2_ref( 'dst', (string) $id ); }, $remote ),
            'delete_local_after_transfer'    => (bool) $snapshot['delete'],
            'preview_token'                  => 'pre.v1.' . $issued_at . '.' . $digest,
            'expires_at'                     => $issued_at + 600,
        );
    }

    /**
     * @param string $kind Target kind.
     * @param string $ref Ref.
     * @return array|null
     */
    private function abilities_v2_resolve_target( $kind, $ref ) {
        $options = $this->abilities_v2_read_options();
        if ( 'profile' === $kind ) {
            $profiles = isset( $options['profiles'] ) && is_array( $options['profiles'] ) ? $options['profiles'] : array();
            foreach ( $profiles as $id => $profile ) {
                if ( hash_equals( $this->abilities_v2_ref( 'prf', (string) $id ), $ref ) && is_array( $profile ) && isset( $profile['type'] ) ) {
                    return array( 'internal_id' => (string) $id, 'type' => $this->abilities_v2_profile_type( $profile['type'] ), 'raw' => $profile );
                }
            }
            return null;
        }
        $schedules = isset( $options['schedules'] ) && is_array( $options['schedules'] ) ? $options['schedules'] : array();
        foreach ( $schedules as $id => $schedule ) {
            if ( ! hash_equals( $this->abilities_v2_ref( 'sch', (string) $id ), $ref ) || ! is_array( $schedule ) ) {
                continue;
            }
            $profile_id = isset( $schedule['profile'] ) ? $schedule['profile'] : ( isset( $schedule['profile_id'] ) ? $schedule['profile_id'] : null );
            if ( null === $profile_id || ! isset( $options['profiles'][ $profile_id ] ) || ! is_array( $options['profiles'][ $profile_id ] ) ) {
                return null;
            }
            $delete_after = ! empty( $schedule['delete_after'] );
            $remote_ids   = isset( $schedule['remote_destinations'] ) && is_array( $schedule['remote_destinations'] ) ? array_values( $schedule['remote_destinations'] ) : array();
            $too_large    = count( $remote_ids ) > 10;
            $destinations = isset( $options['remote_destinations'] ) && is_array( $options['remote_destinations'] ) ? $options['remote_destinations'] : array();
            $remote_valid = true;
            foreach ( $remote_ids as $remote_id ) {
                if ( ! ( is_int( $remote_id ) || is_string( $remote_id ) ) || ! isset( $destinations[ $remote_id ] ) || ! is_array( $destinations[ $remote_id ] ) || ! isset( $destinations[ $remote_id ]['type'] ) || ! is_string( $destinations[ $remote_id ]['type'] ) || ! $this->abilities_v2_destination_configured( strtolower( $destinations[ $remote_id ]['type'] ), $destinations[ $remote_id ] ) ) {
                    $remote_valid = false;
                    break;
                }
            }
            return array(
                'internal_id' => (string) $id,
                'profile'     => array( 'internal_id' => (string) $profile_id, 'type' => $this->abilities_v2_profile_type( $options['profiles'][ $profile_id ]['type'] ), 'raw' => $options['profiles'][ $profile_id ] ),
                'remote_ids'  => $too_large ? array() : $remote_ids,
                'delete_after'=> $delete_after,
                'supported'   => ! $delete_after && $remote_valid,
                'too_large'   => $too_large,
            );
        }
        return null;
    }

    /**
     * @param string $type Profile type.
     * @return array
     */
    private function abilities_v2_retention_victims( $type ) {
        unset( $type );
        $options   = $this->abilities_v2_read_options();
        $archives  = $this->abilities_v2_archives();
        if ( $this->abilities_v2_is_error( $archives ) ) {
            return $archives;
        }
        $remaining = array();
        $victims   = array();
        $total_mb  = 0.0;
        foreach ( $archives as $archive ) {
            $remaining[ $archive['internal_id'] ] = $archive;
            $total_mb += $archive['size_bytes'] / 1048576;
        }
        $mark = static function ( $archive ) use ( &$remaining, &$victims ) {
            $victims[ $archive['internal_id'] ] = $archive;
            unset( $remaining[ $archive['internal_id'] ] );
        };

        $age_limit = isset( $options['archive_limit_age'] ) && is_numeric( $options['archive_limit_age'] ) ? (int) $options['archive_limit_age'] : 0;
        if ( $age_limit > 0 ) {
            foreach ( array_values( $remaining ) as $archive ) {
                $age_days = (int) ( ( $this->abilities_v2_now() - $archive['modified_at'] ) / DAY_IN_SECONDS );
                if ( $age_days > $age_limit ) {
                    $mark( $archive );
                }
            }
        }

        foreach ( array( 'full' => 'archive_limit_full', 'database' => 'archive_limit_db', 'files' => 'archive_limit_files' ) as $archive_type => $key ) {
            $limit = isset( $options[ $key ] ) && is_numeric( $options[ $key ] ) ? (int) $options[ $key ] : 0;
            if ( $limit < 1 ) {
                continue;
            }
            $seen = 0;
            foreach ( array_values( $remaining ) as $archive ) {
                if ( $archive_type === $archive['type'] && ++$seen > $limit ) {
                    $mark( $archive );
                }
            }
        }

        $global_limit = isset( $options['archive_limit'] ) && is_numeric( $options['archive_limit'] ) ? (int) $options['archive_limit'] : 0;
        if ( $global_limit > 0 ) {
            $seen = 0;
            foreach ( array_values( $remaining ) as $archive ) {
                if ( ++$seen > $global_limit ) {
                    $mark( $archive );
                }
            }
        }

        $size_limit     = isset( $options['archive_limit_size'] ) && is_numeric( $options['archive_limit_size'] ) ? (float) $options['archive_limit_size'] : 0.0;
        $big_size_limit = isset( $options['archive_limit_size_big'] ) && is_numeric( $options['archive_limit_size_big'] ) ? (float) $options['archive_limit_size_big'] : 0.0;
        $effective_size = 0.0 === $size_limit ? $big_size_limit : ( 0.0 === $big_size_limit ? $size_limit : min( $size_limit, $big_size_limit ) );
        if ( $effective_size > 0.0 && $total_mb > $effective_size ) {
            foreach ( array_reverse( array_values( $remaining ) ) as $archive ) {
                if ( $total_mb <= $effective_size ) {
                    break;
                }
                $total_mb -= $archive['size_bytes'] / 1048576;
                $mark( $archive );
            }
        }

        $victims = array_values( $victims );
        usort( $victims, static function ( $left, $right ) { return $left['modified_at'] <=> $right['modified_at']; } );
        return count( $victims ) > 100 ? $this->abilities_v2_error( 'too_large' ) : $victims;
    }

    /**
     * @param string $operation Operation.
     * @param array  $request Request, already validated by abilities_v2_valid_payload().
     * @return array
     */
    private function abilities_v2_start_operation( $operation, $request ) {
        $target_key = 'start_backup' === $operation ? 'profile_ref' : 'schedule_ref';
        $kind       = 'start_backup' === $operation ? 'profile' : 'schedule';
        $owner      = $this->abilities_v2_acquire_effect_lock();
        if ( false === $owner ) {
            return $this->abilities_v2_error( 'lock_busy' );
        }
        $result = null;
        try {
            $replay = $this->abilities_v2_replay( $request['request_ref'], $request );
            if ( null !== $replay ) {
                $result = $replay;
            } else {
                $preview = $this->abilities_v2_verify_preview( $kind, $request[ $target_key ], $request['preview_token'] );
                // Verifying the token resolves the target once, but BackupBuddy's own code runs in
                // between - the archive scan behind the token calls into it, and it reloads
                // pb_backupbuddy::$options from the database - so the profile or schedule can be
                // gone by the time this resolve runs. An unresolved target is null, and reading a
                // profile out of it would hand a null profile to the backup effect and report the
                // dispatch as queued.
                $target = $this->abilities_v2_resolve_target( $kind, $request[ $target_key ] );
                if ( ! is_array( $preview ) || $this->abilities_v2_is_error( $preview ) ) {
                    $result = $this->abilities_v2_error( 'preview_stale' );
                } elseif ( ! is_array( $target ) ) {
                    $result = $this->abilities_v2_error( 'not_found' );
                } else {
                    $profile = 'profile' === $kind ? $target : $target['profile'];
                    $steps   = 'schedule' === $kind ? array( 'remote_destinations' => $target['remote_ids'], 'delete_after' => false ) : array();
                    $record  = $this->abilities_v2_new_operation( $request['request_ref'], $this->abilities_v2_request_hash( $request ), 'profile' === $kind ? 'backup' : 'scheduled_backup', null, null );
                    $records = $this->abilities_v2_prepare_records();
                    if ( $this->abilities_v2_is_error( $records ) ) {
                        $result = $records;
                    } else {
                        $records['operations'][ $record['operation_ref'] ] = $record;
                        if ( ! $this->abilities_v2_write_records( $records ) ) {
                            $result = $this->abilities_v2_error( 'storage_failed' );
                        } elseif ( ! $this->abilities_v2_start_backup_effect( $profile['raw'], $record['serial'], $steps ) ) {
                            $record['state']      = 'failed';
                            $record['updated_at'] = $this->abilities_v2_now();
                            $records['operations'][ $record['operation_ref'] ] = $record;
                            $result = $this->abilities_v2_write_records( $records ) ? $this->abilities_v2_error( 'effect_failed' ) : $this->abilities_v2_error( 'outcome_unknown' );
                        } else {
                            $result = array( 'operation' => $this->abilities_v2_public_operation( $record ) );
                        }
                    }
                }
            }
        } finally {
            $released = $this->abilities_v2_release_effect_lock( $owner );
        }
        return $this->abilities_v2_with_lock_release( $result, $released );
    }

    /**
     * @param string $kind Kind.
     * @param string $ref Ref.
     * @param string $token Token.
     * @return array|null
     */
    private function abilities_v2_verify_preview( $kind, $ref, $token ) {
        if ( ! preg_match( '/^pre\.v1\.([0-9]{1,12})\.([a-f0-9]{64})$/D', $token, $matches ) ) {
            return null;
        }
        $issued = (int) $matches[1];
        if ( $issued > $this->abilities_v2_now() || $issued + 600 < $this->abilities_v2_now() ) {
            return null;
        }
        $preview = $this->abilities_v2_preview( $kind, $ref, $issued );
        return ! $this->abilities_v2_is_error( $preview ) && hash_equals( $preview['preview_token'], $token ) ? $preview : null;
    }

    /**
     * @param array $request Request.
     * @return string
     */
    private function abilities_v2_request_hash( $request ) {
        ksort( $request );
        return hash( 'sha256', wp_json_encode( $request ) );
    }

    /**
     * @param string $request_ref Request reference.
     * @param array  $request Request.
     * @return array|null
     */
    private function abilities_v2_replay( $request_ref, $request ) {
        $records = $this->abilities_v2_normalize_records( $this->abilities_v2_read_records() );
        if ( $this->abilities_v2_is_error( $records ) ) {
            return $records;
        }
        $hash    = $this->abilities_v2_request_hash( $request );
        foreach ( $records['operations'] as $record ) {
            if ( isset( $record['request_ref'] ) && hash_equals( $record['request_ref'], $request_ref ) ) {
                return isset( $record['request_hash'] ) && hash_equals( $record['request_hash'], $hash ) ? array( 'operation' => $this->abilities_v2_public_operation( $record ) ) : $this->abilities_v2_error( 'request_conflict' );
            }
        }
        foreach ( $records['receipts'] as $receipt ) {
            if ( isset( $receipt['request_ref'] ) && hash_equals( $receipt['request_ref'], $request_ref ) ) {
                if ( ! isset( $receipt['request_hash'] ) || ! hash_equals( $receipt['request_hash'], $hash ) ) {
                    return $this->abilities_v2_error( 'request_conflict' );
                }
                return 'completed' === $receipt['state'] ? $receipt['response'] : $this->abilities_v2_error( 'outcome_unknown' );
            }
        }
        return null;
    }

    /**
     * Read the ledger, settle what has gone quiet, and drop what has aged out.
     *
     * Removing an operation is not bookkeeping. The record is also the replay proof for its
     * request_ref, so once it is gone the Dashboard retrying that request dispatches the effect a
     * second time. Reads probe BackupBuddy for liveness but never write the ledger, so a stored
     * timestamp can be a week behind an operation that is still running. A record nothing has ever
     * reported an outcome for therefore gets one probe before this pass destroys it, and only on the
     * records the pass would actually remove, so an ordinary mutation never pays to probe the whole
     * ledger. Writing the pass back is the only place a refreshed observation becomes durable.
     *
     * The probe has to find advancing evidence for a record to survive: a run BackupBuddy stopped
     * stepping keeps its old timestamp, settles, and ages out exactly as it did before. A timestamp
     * abilities_v2_trusted_time() refuses, in the ledger or in BackupBuddy's own record, counts as no
     * evidence rather than as fresh evidence, so a frozen future stamp cannot rescue the same zombie
     * on every pass.
     *
     * @return array
     */
    private function abilities_v2_prepare_records() {
        $records = $this->abilities_v2_normalize_records( $this->abilities_v2_read_records() );
        if ( $this->abilities_v2_is_error( $records ) ) {
            return $records;
        }
        $cutoff  = $this->abilities_v2_now() - 604800;
        foreach ( $records['operations'] as $key => $record ) {
            if ( $this->abilities_v2_prune_needs_evidence( $record, $cutoff ) ) {
                $probed = $this->abilities_v2_probe_operation( $record );
                if ( $this->abilities_v2_valid_operation_record( $probed ) ) {
                    $record = $probed;
                }
            }
            $record = $this->abilities_v2_settle_stale_operation( $record );
            $records['operations'][ $key ] = $record;
            if ( in_array( isset( $record['state'] ) ? $record['state'] : '', array( 'succeeded', 'failed', 'cancelled', 'unknown' ), true ) && $this->abilities_v2_unobserved_since( $record, $cutoff ) ) {
                unset( $records['operations'][ $key ] );
            }
        }
        foreach ( $records['receipts'] as $key => $receipt ) {
            $created = $this->abilities_v2_trusted_time( isset( $receipt['created_at'] ) ? $receipt['created_at'] : null );
            if ( null === $created || $created < $cutoff ) {
                unset( $records['receipts'][ $key ] );
            }
        }
        if ( count( $records['operations'] ) + count( $records['receipts'] ) >= 100 ) {
            return $this->abilities_v2_error( 'operation_limit_reached' );
        }
        return $records;
    }

    /**
     * Whether the age-out pass is about to destroy a record it holds no outcome for.
     *
     * queued, running and cancel_requested all mean nothing has reported an ending, and 'unknown' is
     * what the staleness horizon writes when nothing has reported at all, so none of the four is an
     * observed outcome. Only records already past the cutoff are worth a probe, because they are the
     * only ones this pass can remove.
     *
     * @param mixed $record Stored operation record.
     * @param int   $cutoff Age-out cutoff.
     * @return bool
     */
    private function abilities_v2_prune_needs_evidence( $record, $cutoff ) {
        return is_array( $record ) && isset( $record['state'], $record['updated_at'] ) && $this->abilities_v2_unobserved_since( $record, $cutoff ) && in_array( $record['state'], array( 'queued', 'running', 'cancel_requested', 'unknown' ), true );
    }

    /**
     * Settle an operation nothing has reported on for a full day.
     *
     * The Child only ever learns about an effect through abilities_v2_probe_operation(), so a
     * record still sitting in queued/running/cancel_requested a day after its last observation has
     * no evidence behind it: the effect lock it started under expired after 120s and BackupBuddy's
     * own step cron gives up long before a day is out. Such a record settles to 'unknown' instead
     * of claiming to still be in flight, and then ages out on the same 7-day prune as any other
     * settled record. Without that, a crashed run holds its ledger slot forever and 100 of them
     * make every new operation fail with operation_limit_reached.
     *
     * updated_at is left alone on purpose: it records the last time the Child actually observed
     * the operation, and settling it is not an observation. Probing is, so
     * abilities_v2_probe_operation() moves updated_at forward whenever BackupBuddy's own record
     * shows the run is still going. That is what keeps this horizon from overruling live evidence:
     * a record the provider just reported as active is never stale here.
     *
     * @param mixed $record Stored operation record.
     * @return mixed
     */
    private function abilities_v2_settle_stale_operation( $record ) {
        if ( ! is_array( $record ) || ! isset( $record['state'], $record['updated_at'] ) || ! is_int( $record['updated_at'] ) ) {
            return $record;
        }
        if ( in_array( $record['state'], array( 'queued', 'running', 'cancel_requested' ), true ) && $this->abilities_v2_unobserved_since( $record, $this->abilities_v2_now() - DAY_IN_SECONDS ) ) {
            $record['state'] = 'unknown';
        }
        return $record;
    }

    /**
     * @param array $records Records.
     * @return array
     */
    private function abilities_v2_normalize_records( $records ) {
        if ( array() === $records ) {
            return array( 'operations' => array(), 'receipts' => array() );
        }
        if ( ! is_array( $records ) || array( 'operations', 'receipts' ) !== array_keys( $records ) || ! is_array( $records['operations'] ) || ! is_array( $records['receipts'] ) || count( $records['operations'] ) + count( $records['receipts'] ) > 100 ) {
            return $this->abilities_v2_error( 'storage_failed' );
        }
        foreach ( $records['operations'] as $key => $record ) {
            if ( ! is_string( $key ) || ! $this->abilities_v2_valid_operation_record( $record ) || ! hash_equals( $record['operation_ref'], $key ) ) {
                return $this->abilities_v2_error( 'storage_failed' );
            }
        }
        foreach ( $records['receipts'] as $key => $receipt ) {
            if ( ! is_string( $key ) || ! $this->abilities_v2_valid_receipt( $receipt ) || ! hash_equals( $receipt['request_ref'], $key ) ) {
                return $this->abilities_v2_error( 'storage_failed' );
            }
        }
        return $records;
    }

    /**
     * @param mixed $record Stored operation.
     * @return bool
     */
    private function abilities_v2_valid_operation_record( $record ) {
        $keys = array( 'operation_ref', 'request_ref', 'request_hash', 'kind', 'state', 'created_at', 'updated_at', 'progress', 'archive_ref', 'destination_ref', 'warnings', 'serial' );
        if ( ! is_array( $record ) || $keys !== array_keys( $record ) || ! $this->abilities_v2_valid_ref( $record['operation_ref'] ) || ! $this->abilities_v2_valid_request_ref( $record['request_ref'] ) || ! is_string( $record['request_hash'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $record['request_hash'] ) || ! in_array( $record['kind'], array( 'backup', 'scheduled_backup', 'transfer' ), true ) || ! in_array( $record['state'], array( 'queued', 'running', 'cancel_requested', 'succeeded', 'failed', 'cancelled', 'unknown' ), true ) || ! is_int( $record['created_at'] ) || $record['created_at'] < 0 || ! is_int( $record['updated_at'] ) || $record['updated_at'] < $record['created_at'] || ! is_int( $record['progress'] ) || $record['progress'] < 0 || $record['progress'] > 100 || ! is_string( $record['serial'] ) || 1 !== preg_match( '/^[a-f0-9]{10}$/D', $record['serial'] ) ) {
            return false;
        }
        foreach ( array( 'archive_ref', 'destination_ref' ) as $key ) {
            if ( null !== $record[ $key ] && ! $this->abilities_v2_valid_ref( $record[ $key ] ) ) {
                return false;
            }
        }
        if ( ! is_array( $record['warnings'] ) || count( $record['warnings'] ) > 10 || count( $record['warnings'] ) !== count( array_unique( $record['warnings'] ) ) ) {
            return false;
        }
        foreach ( $record['warnings'] as $warning ) {
            if ( ! in_array( $warning, array( 'retention_deleted_archives', 'remote_transfer_pending', 'cancel_not_immediate' ), true ) ) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param mixed $receipt Stored receipt.
     * @return bool
     */
    private function abilities_v2_valid_receipt( $receipt ) {
        $keys = array( 'request_ref', 'request_hash', 'created_at', 'state', 'response' );
        if ( ! is_array( $receipt ) || $keys !== array_keys( $receipt ) || ! $this->abilities_v2_valid_request_ref( $receipt['request_ref'] ) || ! is_string( $receipt['request_hash'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $receipt['request_hash'] ) || ! is_int( $receipt['created_at'] ) || $receipt['created_at'] < 0 || ! in_array( $receipt['state'], array( 'pending', 'completed' ), true ) ) {
            return false;
        }
        if ( 'pending' === $receipt['state'] ) {
            return null === $receipt['response'];
        }
        $response_keys = array( 'archive_ref', 'deleted', 'already_absent', 'auxiliary_records_removed' );
        return is_array( $receipt['response'] ) && $response_keys === array_keys( $receipt['response'] ) && $this->abilities_v2_valid_ref( $receipt['response']['archive_ref'] ) && true === $receipt['response']['deleted'] && false === $receipt['response']['already_absent'] && is_int( $receipt['response']['auxiliary_records_removed'] ) && $receipt['response']['auxiliary_records_removed'] >= 0 && $receipt['response']['auxiliary_records_removed'] <= 10;
    }

    /**
     * @param string      $request_ref Request reference.
     * @param string      $hash Hash.
     * @param string      $kind Kind.
     * @param string|null $archive_ref Archive ref.
     * @param string|null $destination_ref Destination ref.
     * @return array
     */
    private function abilities_v2_new_operation( $request_ref, $hash, $kind, $archive_ref, $destination_ref ) {
        $now = $this->abilities_v2_now();
        return array(
            'operation_ref'  => $this->abilities_v2_ref( 'op', $request_ref ),
            'request_ref'    => $request_ref,
            'request_hash'   => $hash,
            'kind'           => $kind,
            'state'          => 'queued',
            'created_at'     => $now,
            'updated_at'     => $now,
            'progress'       => 0,
            'archive_ref'    => $archive_ref,
            'destination_ref'=> $destination_ref,
            'warnings'       => array(),
            'serial'         => substr( hash_hmac( 'sha256', 'serial' . "\0" . $request_ref, $this->abilities_v2_secret() ), 0, 10 ),
        );
    }

    /**
     * @param array $record Record.
     * @return array
     */
    private function abilities_v2_public_operation( $record ) {
        return array(
            'operation_ref'  => $record['operation_ref'],
            'kind'           => $record['kind'],
            'state'          => $record['state'],
            'created_at'     => (int) $record['created_at'],
            'updated_at'     => (int) $record['updated_at'],
            'progress_percent'=> isset( $record['progress'] ) ? $record['progress'] : null,
            'archive_ref'    => isset( $record['archive_ref'] ) ? $record['archive_ref'] : null,
            'destination_ref'=> isset( $record['destination_ref'] ) ? $record['destination_ref'] : null,
            'warning_codes'  => isset( $record['warnings'] ) && is_array( $record['warnings'] ) ? array_values( $record['warnings'] ) : array(),
        );
    }

    /**
     * @param string $operation_ref Operation reference, already validated by abilities_v2_valid_payload().
     * @return array
     */
    private function abilities_v2_get_operation( $operation_ref ) {
        $records = $this->abilities_v2_normalize_records( $this->abilities_v2_read_records() );
        if ( $this->abilities_v2_is_error( $records ) ) {
            return $records;
        }
        if ( ! isset( $records['operations'][ $operation_ref ] ) || ! is_array( $records['operations'][ $operation_ref ] ) ) {
            return $this->abilities_v2_error( 'not_found' );
        }
        // Settled here for the response only; this read never writes the ledger back.
        $record = $this->abilities_v2_settle_stale_operation( $this->abilities_v2_probe_operation( $records['operations'][ $operation_ref ] ) );
        if ( ! $this->abilities_v2_valid_operation_record( $record ) ) {
            return $this->abilities_v2_error( 'storage_failed' );
        }
        return array( 'operation' => $this->abilities_v2_public_operation( $record ) );
    }

    /**
     * @param string $operation_ref Operation reference, already validated by abilities_v2_valid_payload().
     * @return array
     */
    private function abilities_v2_cancel_operation( $operation_ref ) {
        $owner = $this->abilities_v2_acquire_effect_lock();
        if ( false === $owner ) {
            return $this->abilities_v2_error( 'lock_busy' );
        }
        $result = null;
        try {
            $records = $this->abilities_v2_normalize_records( $this->abilities_v2_read_records() );
            if ( $this->abilities_v2_is_error( $records ) ) {
                $result = $records;
            } elseif ( ! isset( $records['operations'][ $operation_ref ] ) ) {
                $result = $this->abilities_v2_error( 'not_found' );
            } else {
                $record = $this->abilities_v2_settle_stale_operation( $this->abilities_v2_probe_operation( $records['operations'][ $operation_ref ] ) );
                if ( ! $this->abilities_v2_valid_operation_record( $record ) ) {
                    $result = $this->abilities_v2_error( 'storage_failed' );
                } elseif ( in_array( $record['state'], array( 'succeeded', 'failed', 'cancelled', 'cancel_requested', 'unknown' ), true ) ) {
                    $result = array( 'operation' => $this->abilities_v2_public_operation( $record ) );
                } elseif ( ! $this->abilities_v2_set_stop_signal( $record['serial'] ) ) {
                    $result = $this->abilities_v2_error( 'effect_failed' );
                } else {
                    $record['state']      = 'cancel_requested';
                    $record['updated_at'] = $this->abilities_v2_now();
                    $record['warnings']   = array( 'cancel_not_immediate' );
                    $records['operations'][ $operation_ref ] = $record;
                    $result = $this->abilities_v2_write_records( $records ) ? array( 'operation' => $this->abilities_v2_public_operation( $record ) ) : $this->abilities_v2_error( 'storage_failed' );
                }
            }
        } finally {
            $released = $this->abilities_v2_release_effect_lock( $owner );
        }
        return $this->abilities_v2_with_lock_release( $result, $released );
    }

    /**
     * @param array $request Request, already validated by abilities_v2_valid_payload().
     * @return array
     */
    private function abilities_v2_delete_archive( $request ) {
        $owner = $this->abilities_v2_acquire_effect_lock();
        if ( false === $owner ) {
            return $this->abilities_v2_error( 'lock_busy' );
        }
        $result = null;
        try {
            $replay = $this->abilities_v2_replay( $request['request_ref'], $request );
            if ( null !== $replay ) {
                $result = $replay;
            } else {
                $archive = $this->abilities_v2_resolve_archive( $request['archive_ref'] );
                if ( $this->abilities_v2_is_error( $archive ) ) {
                    $result = $archive;
                } elseif ( ! is_array( $archive ) ) {
                    $result = $this->abilities_v2_error( 'not_found' );
                } elseif ( $archive['size_bytes'] !== $request['expected_size_bytes'] || $archive['modified_at'] !== $request['expected_modified_at'] ) {
                    $result = $this->abilities_v2_error( 'request_conflict' );
                } else {
                    $records = $this->abilities_v2_prepare_records();
                    if ( $this->abilities_v2_is_error( $records ) ) {
                        $result = $records;
                    } else {
                        $receipt = array(
                            'request_ref'  => $request['request_ref'],
                            'request_hash' => $this->abilities_v2_request_hash( $request ),
                            'created_at'   => $this->abilities_v2_now(),
                            'state'        => 'pending',
                            'response'     => null,
                        );
                        $records['receipts'][ $request['request_ref'] ] = $receipt;
                        if ( ! $this->abilities_v2_write_records( $records ) ) {
                            $result = $this->abilities_v2_error( 'storage_failed' );
                        } else {
                            $effect = $this->abilities_v2_delete_archive_effect( $archive );
                            if ( ! is_array( $effect ) || empty( $effect['deleted'] ) || is_array( $this->abilities_v2_resolve_archive( $request['archive_ref'] ) ) ) {
                                $result = $this->abilities_v2_error( 'outcome_unknown' );
                            } else {
                                $response = array(
                                    'archive_ref'               => $request['archive_ref'],
                                    'deleted'                   => true,
                                    'already_absent'            => false,
                                    'auxiliary_records_removed' => isset( $effect['auxiliary_records_removed'] ) ? min( 10, max( 0, (int) $effect['auxiliary_records_removed'] ) ) : 0,
                                );
                                $receipt['state']    = 'completed';
                                $receipt['response'] = $response;
                                $records['receipts'][ $request['request_ref'] ] = $receipt;
                                $result = $this->abilities_v2_write_records( $records ) ? $response : $this->abilities_v2_error( 'outcome_unknown' );
                            }
                        }
                    }
                }
            }
        } finally {
            $released = $this->abilities_v2_release_effect_lock( $owner );
        }
        return $this->abilities_v2_with_lock_release( $result, $released );
    }

    /**
     * @param array $request Request, already validated by abilities_v2_valid_payload().
     * @return array
     */
    private function abilities_v2_start_transfer( $request ) {
        $owner = $this->abilities_v2_acquire_effect_lock();
        if ( false === $owner ) {
            return $this->abilities_v2_error( 'lock_busy' );
        }
        $result = null;
        try {
            $replay = $this->abilities_v2_replay( $request['request_ref'], $request );
            if ( null !== $replay ) {
                $result = $replay;
            } else {
                $archive     = $this->abilities_v2_resolve_archive( $request['archive_ref'] );
                $destination = $this->abilities_v2_resolve_destination( $request['destination_ref'] );
                if ( $this->abilities_v2_is_error( $archive ) ) {
                    $result = $archive;
                } elseif ( ! is_array( $archive ) || null === $destination ) {
                    $result = $this->abilities_v2_error( 'not_found' );
                } else {
                    $record  = $this->abilities_v2_new_operation( $request['request_ref'], $this->abilities_v2_request_hash( $request ), 'transfer', $request['archive_ref'], $request['destination_ref'] );
                    $records = $this->abilities_v2_prepare_records();
                    if ( $this->abilities_v2_is_error( $records ) ) {
                        $result = $records;
                    } else {
                        $records['operations'][ $record['operation_ref'] ] = $record;
                        if ( ! $this->abilities_v2_write_records( $records ) ) {
                            $result = $this->abilities_v2_error( 'storage_failed' );
                        } elseif ( ! $this->abilities_v2_start_transfer_effect( $archive, $destination, $record['serial'] ) ) {
                            $record['state']      = 'failed';
                            $record['updated_at'] = $this->abilities_v2_now();
                            $records['operations'][ $record['operation_ref'] ] = $record;
                            $result = $this->abilities_v2_write_records( $records ) ? $this->abilities_v2_error( 'effect_failed' ) : $this->abilities_v2_error( 'outcome_unknown' );
                        } else {
                            $result = array( 'operation' => $this->abilities_v2_public_operation( $record ) );
                        }
                    }
                }
            }
        } finally {
            $released = $this->abilities_v2_release_effect_lock( $owner );
        }
        return $this->abilities_v2_with_lock_release( $result, $released );
    }

    /**
     * @param string $ref Archive ref.
     * @return array|null
     */
    private function abilities_v2_resolve_archive( $ref ) {
        $archives = $this->abilities_v2_archives();
        if ( $this->abilities_v2_is_error( $archives ) ) {
            return $archives;
        }
        foreach ( $archives as $archive ) {
            if ( hash_equals( $this->abilities_v2_ref( 'arc', $archive['internal_id'] ), $ref ) ) {
                return $archive;
            }
        }
        return null;
    }

    /**
     * @param string $ref Destination ref.
     * @return string|null
     */
    private function abilities_v2_resolve_destination( $ref ) {
        $options = $this->abilities_v2_read_options();
        $items   = isset( $options['remote_destinations'] ) && is_array( $options['remote_destinations'] ) ? $options['remote_destinations'] : array();
        foreach ( $items as $id => $destination ) {
            if ( is_array( $destination ) && isset( $destination['type'] ) && is_string( $destination['type'] ) && $this->abilities_v2_destination_configured( strtolower( $destination['type'] ), $destination ) && hash_equals( $this->abilities_v2_ref( 'dst', (string) $id ), $ref ) ) {
                return (string) $id;
            }
        }
        return null;
    }

    /**
     * @param array  $profile Profile.
     * @param string $serial Serial.
     * @param array  $steps Steps.
     * @return bool
     */
    protected function abilities_v2_start_backup_effect( $profile, $serial, $steps ) {
        if ( ! class_exists( '\\pb_backupbuddy_backup' ) ) {
            // require_once on a path that is not there is an uncatchable compile error, so it would
            // take the request down past the caller's failure handling and past the finally that
            // releases the effect lock: the record stays queued and every later effect answers
            // lock_busy until the lock's 120s TTL runs out.
            if ( ! method_exists( '\\pb_backupbuddy', 'plugin_path' ) ) {
                return false;
            }
            $backup_class_file = \pb_backupbuddy::plugin_path() . '/classes/backup.php';
            if ( ! file_exists( $backup_class_file ) ) {
                return false;
            }
            require_once $backup_class_file; // NOSONAR - WP compatible.
            if ( ! class_exists( '\\pb_backupbuddy_backup' ) ) {
                // A file that loaded without defining the class leaves the same uncatchable ending
                // as a missing one: instantiating an undefined class is an Error nothing here
                // catches, so the caller's effect_failed path would be skipped just the same.
                return false;
            }
        }
        $backup = new \pb_backupbuddy_backup();
        return method_exists( $backup, 'start_backup_process' ) && true === $backup->start_backup_process( $profile, 'manual', array(), $steps, '', $serial, array(), '', '' );
    }

    /**
     * @param array $record Record.
     * @return array
     */
    protected function abilities_v2_probe_operation( $record ) {
        // Not every BackupBuddy build carries getLogDirectory(), which is why every other caller in
        // this file tests for it first. Without it there is no record to read, so the record is
        // returned as it stands: no new evidence rather than a clean bill of health. Nothing here
        // moves updated_at, so abilities_v2_settle_stale_operation() still ages the record out.
        if ( ! class_exists( $this->backupbuddy_core_class ) || ! method_exists( $this->backupbuddy_core_class, 'getLogDirectory' ) ) {
            return $record;
        }
        $prefix = 'transfer' === $record['kind'] ? 'send-mainwp-ability-' : '';
        $file   = \backupbuddy_core::getLogDirectory() . 'fileoptions/' . $prefix . $record['serial'] . '.txt';
        if ( ! file_exists( $file ) || ! class_exists( '\\pb_backupbuddy_fileoptions' ) ) {
            return $record;
        }
        $options = new \pb_backupbuddy_fileoptions( $file, true );
        if ( ! method_exists( $options, 'is_ok' ) || true !== $options->is_ok() || ! isset( $options->options ) || ! is_array( $options->options ) ) {
            return $record;
        }
        $data = $options->options;
        if ( 'transfer' === $record['kind'] ) {
            if ( isset( $data['status'] ) && 'success' === $data['status'] && ! empty( $data['finish_time'] ) ) {
                $record['state']    = 'succeeded';
                $record['progress'] = 100;
            } elseif ( isset( $data['status'] ) && in_array( $data['status'], array( 'failure', 'timeout', 'aborted' ), true ) ) {
                $record['state'] = 'failed';
            } elseif ( isset( $data['status'] ) && 'running' === $data['status'] ) {
                $record['state'] = 'running';
                // 'running' is written once when the send is dispatched and nothing clears it if the
                // worker dies, so the status on its own says nothing about liveness: dating the record
                // by it would keep a dead send fresh on every read and it could never age out. What
                // does advance is the send record itself, which BackupBuddy rewrites as the transfer
                // progresses, so that is the observation time - the same discipline the backup branch
                // takes from updated_time below.
                $activity = $this->abilities_v2_send_activity_time( $data, $file );
                if ( null !== $activity ) {
                    $record['updated_at'] = max( $record['updated_at'], min( $this->abilities_v2_now(), $activity ) );
                }
            }
            return $record;
        }
        if ( ! empty( $data['finish_time'] ) && ! empty( $data['archive_file'] ) && file_exists( $data['archive_file'] ) ) {
            $record['state']       = 'succeeded';
            $record['progress']    = 100;
            $record['archive_ref'] = $this->abilities_v2_ref( 'arc', basename( $data['archive_file'] ) );
        } elseif ( ! empty( $data['error'] ) ) {
            $record['state'] = 'failed';
        } else {
            if ( in_array( $record['state'], array( 'queued', 'unknown' ), true ) ) {
                $record['state'] = 'running';
            }
            // A backup fileoptions file with no finish and no error says nothing about liveness on
            // its own: a crashed run leaves exactly that behind. BackupBuddy stamps updated_time on
            // every step and judges its own timeouts by it (see get_backup_status()), so it is the
            // one signal that separates a long backup from an abandoned one. Adopting it as the
            // observation time keeps a genuine long run out of the staleness settle below while an
            // abandoned run still ages out of the ledger. A stamp the trust check rejects is not a
            // step the Child can date, so it buys the record nothing: the min() below is only there
            // to absorb the few minutes of skew a stamp is allowed to be ahead by, and clamping an
            // arbitrary future value would report the current time on every probe of a record that
            // stopped changing days ago.
            $activity = $this->abilities_v2_trusted_time( isset( $data['updated_time'] ) ? $data['updated_time'] : null );
            if ( null !== $activity ) {
                $record['updated_at'] = max( $record['updated_at'], min( $this->abilities_v2_now(), $activity ) );
            }
        }
        return $record;
    }

    /**
     * Last time BackupBuddy itself touched a remote send record.
     *
     * A send stamps update_time as it works through the file. Where a build does not carry that key,
     * or stamps something the trust check refuses, the record is still rewritten on every chunk, so
     * the file's own modification time states the same fact, and a send that died stops moving
     * either way. Both readings go through the trust check: a stamp ahead of this clock is not a
     * later observation of anything, and a record nothing rewrites stops advancing its own mtime
     * too, so neither reading can keep a dead send looking alive.
     *
     * @param array  $data Send record contents.
     * @param string $file Send record path.
     * @return int|null
     */
    private function abilities_v2_send_activity_time( $data, $file ) {
        if ( isset( $data['update_time'] ) ) {
            $stamped = $this->abilities_v2_trusted_time( $data['update_time'] );
            if ( null !== $stamped ) {
                return $stamped;
            }
        }
        $modified = filemtime( $file );
        return false === $modified ? null : $this->abilities_v2_trusted_time( $modified );
    }

    /**
     * @param string $serial Serial.
     * @return bool
     */
    protected function abilities_v2_set_stop_signal( $serial ) {
        set_transient( 'pb_backupbuddy_stop_backup-' . $serial, true, DAY_IN_SECONDS );
        return true === get_transient( 'pb_backupbuddy_stop_backup-' . $serial );
    }

    /**
     * @param array $archive Archive.
     * @return array
     */
    protected function abilities_v2_delete_archive_effect( $archive ) {
        if ( ! isset( $archive['path'] ) || ! is_string( $archive['path'] ) || ! file_exists( $archive['path'] ) || is_link( $archive['path'] ) ) {
            return array( 'deleted' => false, 'auxiliary_records_removed' => 0 );
        }
        // wp_delete_file() only gained a return value in WP 6.7 and the wp_delete_file filter can move
        // the delete elsewhere, so on the 6.2+ range we support absence on readback is the only truth.
        wp_delete_file( $archive['path'] );
        clearstatcache( true, $archive['path'] );
        if ( file_exists( $archive['path'] ) ) {
            return array( 'deleted' => false, 'auxiliary_records_removed' => 0 );
        }
        $removed = 0;
        if ( class_exists( $this->backupbuddy_core_class ) && method_exists( $this->backupbuddy_core_class, 'get_serial_from_file' ) && method_exists( $this->backupbuddy_core_class, 'getLogDirectory' ) ) {
            $serial = \backupbuddy_core::get_serial_from_file( $archive['internal_id'] );
            foreach ( array( \backupbuddy_core::getLogDirectory() . 'fileoptions/' . $serial . '.txt', \backupbuddy_core::getLogDirectory() . 'fileoptions/' . $serial . '.txt.lock' ) as $auxiliary ) {
                if ( ! file_exists( $auxiliary ) ) {
                    continue;
                }
                wp_delete_file( $auxiliary );
                clearstatcache( true, $auxiliary );
                if ( ! file_exists( $auxiliary ) ) {
                    ++$removed;
                }
            }
        }
        return array( 'deleted' => true, 'auxiliary_records_removed' => $removed );
    }

    /**
     * @param array  $archive Archive.
     * @param string $destination Destination ID.
     * @param string $serial Serial.
     * @return bool
     */
    protected function abilities_v2_start_transfer_effect( $archive, $destination, $serial ) {
        if ( ! class_exists( $this->backupbuddy_core_class ) || ! method_exists( $this->backupbuddy_core_class, 'schedule_single_event' ) ) {
            return false;
        }
        return false !== \backupbuddy_core::schedule_single_event( $this->abilities_v2_now(), 'remote_send', array( $destination, $archive['path'], 'mainwp-ability-' . $serial, false, false ) );
    }

    /**
     * Set show or hide BackupBuddy Plugin from Admin & plugins list.
     *
     * @return array $information Return results.
     *
     * @uses \MainWP\Child\MainWP_Helper::update_option()
     */
    public function set_showhide() {
        $hide = MainWP_System::instance()->validate_params( 'showhide' );
        MainWP_Helper::update_option( 'mainwp_backupbuddy_hide_plugin', $hide );
        $information['result'] = 'SUCCESS';
        return $information;
    }

    /**
     * Save BackupBuddy settings.
     *
     * @return array $out Return response array.
     *
     * @uses \pb_backupbuddy::$options()
     * @uses \backupbuddy_core::_getBackupDirectoryDefault()
     */
    public function save_settings() { // phpcs:ignore -- NOSONAR - complex.

        $type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';

        if ( 'general_settings' !== $type && 'advanced_settings' !== $type && 'all' !== $type ) {
            return array( 'error' => esc_html__( 'Invalid data. Please check and try again.' ) );
        }

        $filter_advanced_settings = array(
            'backup_reminders',
            'archive_name_format',
            'archive_name_profile',
            'lock_archives_directory',
            'include_importbuddy',
            'default_backup_tab',
            'disable_localization',
            'limit_single_cron_per_pass',
            'use_internal_cron',
            'cron_request_timeout_override',
            'remote_send_timeout_retries',
            'hide_live',
            'hide_dashboard_widget',
            'set_greedy_execution_time',
            'archive_limit_size_big',
            'max_execution_time',
            'log_level',
            'save_backup_sum_log',
            'max_site_log_size',
            'max_send_stats_days',
            'max_send_stats_count',
            'max_notifications_age_days',
            'backup_mode',
            'delete_archives_pre_backup',
            'disable_https_local_ssl_verify',
            'prevent_flush',
            'save_comment_meta',
            'integrity_check',
            'backup_cron_rescheduling',
            'skip_spawn_cron_call',
            'backup_cron_passed_force_time',
            'php_runtime_test_minimum_interval',
            'php_memory_test_minimum_interval',
            'database_method_strategy',
            'skip_database_dump',
            'breakout_tables',
            'force_single_db_file',
            'phpmysqldump_maxrows',
            'ignore_command_length_check',
            'compression',
            'zip_method_strategy',
            'alternative_zip_2',
            'zip_build_strategy',
            'zip_step_period',
            'zip_burst_gap',
            'zip_min_burst_content',
            'zip_max_burst_content',
            'disable_zipmethod_caching',
            'ignore_zip_warnings',
            'ignore_zip_symlinks',
        );

        $filter_general_settings = array(
            'importbuddy_pass_hash',
            'importbuddy_pass_length',
            'backup_directory',
            'role_access',
            'archive_limit_age',
            'archive_limit_full',
            'archive_limit',
            'archive_limit_size',
            'archive_limit_db',
            'archive_limit_files',
            'title_multisite',
            'multisite_export',
            'backup_nonwp_tables',
            'mysqldump_additional_includes',
            'mysqldump_additional_excludes',
            'excludes',
            'email_notify_scheduled_start',
            'email_notify_scheduled_start_subject',
            'email_notify_scheduled_start_body',
            'email_notify_scheduled_complete',
            'email_notify_scheduled_complete_subject',
            'email_notify_scheduled_complete_body',
            'email_notify_send_finish',
            'email_notify_send_finish_subject',
            'email_notify_send_finish_body',
            'no_new_backups_error_days',
            'email_notify_error',
            'email_notify_error_subject',
            'email_notify_error_body',
            'email_return',
        );

        $filter_profile0_values = array(
            'mysqldump_additional_includes',
            'mysqldump_additional_excludes',
            'excludes',
            'integrity_check',
            'skip_database_dump',
            'backup_nonwp_tables',
        );

        $settings = json_decode( base64_decode( wp_unslash( $_POST['options'] ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..

        $save_settings = array();

        if ( is_array( $settings ) ) {
            if ( 'all' === $type || 'general_settings' === $type ) {
                foreach ( $filter_general_settings as $field ) {
                    if ( isset( $settings[ $field ] ) ) {
                        $save_settings[ $field ] = $settings[ $field ];
                    }
                }
            }

            if ( 'all' === $type || 'advanced_settings' === $type ) {
                foreach ( $filter_advanced_settings as $field ) {
                    if ( isset( $settings[ $field ] ) ) {
                        $save_settings[ $field ] = $settings[ $field ];
                    }
                }
            }
        }

        if ( ! empty( $save_settings ) ) {
            $newOptions = \pb_backupbuddy::$options;

            foreach ( $newOptions as $key => $val ) {
                if ( isset( $save_settings[ $key ] ) ) {
                    $newOptions[ $key ] = $save_settings[ $key ];
                }
            }

            if ( isset( $newOptions['profiles'] ) && isset( $newOptions['profiles'][0] ) ) {
                foreach ( $filter_profile0_values as $field ) {
                    if ( isset( $settings[ $field ] ) ) {
                        $newOptions['profiles'][0][ $field ] = $settings[ $field ];
                    }
                }
            }

            if ( 'general_settings' === $type || 'all' === $type ) {
                $newOptions['importbuddy_pass_hash_confirm'] = '';
            }

            /** @global object $wpdb WordPres Database object. */
            global $wpdb;

            $option     = 'pb_' . \pb_backupbuddy::settings( 'slug' );
            $newOptions = sanitize_option( $option, $newOptions );
            $newOptions = maybe_serialize( $newOptions ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- third party credit.

            add_site_option( $option, $newOptions, '', 'no' ); // 'No' prevents autoload if we wont always need the data loaded.
            $wpdb->update( $wpdb->options, array( 'option_value' => $newOptions ), array( 'option_name' => $option ) );

            $information['backupDirectoryDefault'] = \backupbuddy_core::_getBackupDirectoryDefault();
            $information['result']                 = 'SUCCESS';
        }

        return $information;
    }

    /**
     * Reset BackupBuddy defaults.
     *
     * @return array $information Return success message & result.
     *
     * @uses \pb_backupbuddy::$options
     * @uses \pb_backupbuddy::settings()
     * @uses \pb_backupbuddy::save()
     * @uses \backupbuddy_core::verify_directories()
     * @uses \backupbuddy_core::addNotification()
     */
    public function reset_defaults() {

        // Keep log serial.
        $old_log_serial = \pb_backupbuddy::$options['log_serial'];

        $keepDestNote            = '';
        $remote_destinations     = \pb_backupbuddy::$options['remote_destinations'];
        \pb_backupbuddy::$options = \pb_backupbuddy::settings( 'default_options' );
        if ( '1' === $_POST['keep_destinations'] ) {
            \pb_backupbuddy::$options['remote_destinations'] = $remote_destinations;
            $keepDestNote                                   = ' ' . esc_html__( 'Remote destination settings were not reset.', 'mainwp-child' );
        }

        // Replace log serial.
        \pb_backupbuddy::$options['log_serial'] = $old_log_serial;

        \pb_backupbuddy::save();
        $skipTempGeneration = true;
        \backupbuddy_core::verify_directories( $skipTempGeneration ); // Re-verify directories such as backup dir, temp, etc.
        $resetNote = esc_html__( 'Plugin settings have been reset to defaults.', 'mainwp-child' );
        \backupbuddy_core::addNotification( 'settings_reset', 'Plugin settings reset', $resetNote . $keepDestNote );

        $information['message'] = $resetNote . $keepDestNote;
        $information['result']  = 'SUCCESS';
        return $information;
    }

    /**
     * Get BackupBuddy core success notifications.
     *
     * @return array Return BackupBuddy SUCCESS notifications.
     *
     * @uses \backupbuddy_core::getNotifications()
     */
    public function get_notifications() {
        return array(
            'result'        => 'SUCCESS',
            'notifications' => \backupbuddy_core::getNotifications(),
        );
    }

    /**
     * Get schedules run time.
     *
     * @return array Return $schedules_run_time.
     *
     * @uses \pb_backupbuddy::$options
     * @uses \pb_backupbuddy::$format::date()
     * @uses \pb_backupbuddy::$format::localize_time()
     */
    public function get_schedules_run_time() {
        $schedules_run_time = array();
        foreach ( \pb_backupbuddy::$options['schedules'] as $schedule_id => $schedule ) {
            // Determine first run.
            $first_run = \pb_backupbuddy::$format->date( \pb_backupbuddy::$format->localize_time( $schedule['first_run'] ) );
            // Determine last run.
            if ( isset( $schedule['last_run'] ) ) { // backward compatibility before last run tracking added. Pre v2.2.11. Eventually remove this.
                if ( empty( $schedule['last_run'] ) ) {
                    $last_run = '<i>' . esc_html__( 'Never', 'mainwp-child' ) . '</i>';
                } else {
                    $last_run = \pb_backupbuddy::$format->date( \pb_backupbuddy::$format->localize_time( $schedule['last_run'] ) );
                }
            } else { // backward compatibility for before last run tracking was added.
                $last_run = '<i> ' . esc_html__( 'Unknown', 'mainwp-child' ) . '</i>';
            }

            // Determine next run.
            $next_run = wp_next_scheduled( 'backupbuddy_cron', array( 'run_scheduled_backup', array( (int) $schedule_id ) ) );
            if ( false === $next_run ) {
                $next_run = '<font color=red>Error: Cron event not found</font>';
            } else {
                $next_run = \pb_backupbuddy::$format->date( \pb_backupbuddy::$format->localize_time( $next_run ) );
            }

            $run_time = 'First run: ' . $first_run . '<br>' .
                        'Last run: ' . $last_run . '<br>' .
                        'Next run: ' . $next_run;

            $schedules_run_time[ $schedule_id ] = $run_time;

        }

        return $schedules_run_time;
    }

    /**
     * List schedules.
     *
     * @return array $information Return results array.
     *
     * @uses \pb_backupbuddy::$options
     * @uses MainWP_Child_Back_Up_Buddy::get_schedules_run_time()
     */
    public function schedules_list() {
        $information                        = array();
        $information['schedules']           = \pb_backupbuddy::$options['schedules'];
        $information['next_schedule_index'] = \pb_backupbuddy::$options['next_schedule_index'];
        $information['schedules_run_time']  = $this->get_schedules_run_time();

        // to fix missing destination notice.
        if ( isset( \pb_backupbuddy::$options['remote_destinations'] ) ) {
            $information['remote_destinations'] = \pb_backupbuddy::$options['remote_destinations'];
        }

        $information['result'] = 'SUCCESS';
        return $information;
    }


    /**
     * Run scheduled backup.
     *
     * @return array $information Return results array.
     *
     * @uses \pb_backupbuddy::$options
     * @uses \pb_backupbuddy::alert()
     * @uses \pb_backupbuddy_cron::_run_scheduled_backup()
     */
    public function run_scheduled_backup() {
        if ( ! is_main_site() ) { // Only run for main site or standalone. Multisite subsites do not allow schedules.
            return array( 'error' => esc_html__( 'Only run for main site or standalone. Multisite subsites do not allow schedules', 'mainwp-child' ) );
        }

        $schedule_id = (int) $_POST['schedule_id'];

        if ( ! isset( \pb_backupbuddy::$options['schedules'][ $schedule_id ] ) || ! is_array( \pb_backupbuddy::$options['schedules'][ $schedule_id ] ) ) {
            return array( 'error' => esc_html__( 'Error: not found the backup schedule or invalid data', 'mainwp-child' ) );
        }

        \pb_backupbuddy::alert( 'Manually running scheduled backup "' . \pb_backupbuddy::$options['schedules'][ $schedule_id ]['title'] . '" in the background.<br>' . esc_html__( 'Note: If there is no site activity there may be delays between steps in the backup. Access the site or use a 3rd party service, such as a free pinging service, to generate site activity.', 'mainwp-child' ) );
        \pb_backupbuddy_cron::_run_scheduled_backup( $schedule_id );

        $information['result'] = 'SUCCESS';

        return $information;
    }


    /**
     * Save scheduled backup.
     *
     * @return array|string[] $information Return results array.
     *
     * @uses \pb_backupbuddy::$options
     * @uses \backupbuddy_core::schedule_event()
     * @uses \backupbuddy_core::unschedule_event()
     * @uses \pb_backupbuddy::save()
     * @uses MainWP_Child_Back_Up_Buddy::get_schedules_run_time()
     */
    public function save_scheduled_backup() {
        $schedule_id = intval( $_POST['schedule_id'] );
        $schedule    = json_decode( base64_decode( wp_unslash( $_POST['data'] ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..

        if ( ! is_array( $schedule ) ) {
            return array( 'error' => esc_html__( 'Invalid schedule data', 'mainwp-child' ) );
        }
        $information = array();

        if ( ! isset( \pb_backupbuddy::$options['schedules'][ $schedule_id ] ) ) {
            \pb_backupbuddy::$options['next_schedule_index']++; // This change will be saved in savesettings function below.
            \pb_backupbuddy::$options['schedules'][ $schedule_id ] = $schedule;
            $result = \backupbuddy_core::schedule_event( $schedule['first_run'], $schedule['interval'], 'run_scheduled_backup', array( $schedule_id ) );
            if ( false === $result ) {
                return array( 'error' => 'Error scheduling event with WordPress. Your schedule may not work properly. Please try again. Error #3488439b. Check your BackupBuddy error log for details.' );
            }
        } else {
            $first_run           = $schedule['first_run'];
            $next_scheduled_time = wp_next_scheduled( 'backupbuddy_cron', array( 'run_scheduled_backup', array( (int) $schedule_id ) ) );
            \backupbuddy_core::unschedule_event( $next_scheduled_time, 'backupbuddy_cron', array( 'run_scheduled_backup', array( (int) $schedule_id ) ) );
            \backupbuddy_core::schedule_event( $first_run, $schedule['interval'], 'run_scheduled_backup', array( (int) $schedule_id ) ); // Add new schedule.
            \pb_backupbuddy::$options['schedules'][ $schedule_id ] = $schedule;
        }
        \pb_backupbuddy::save();
        $information['result']              = 'SUCCESS';
        $information['schedules']           = \pb_backupbuddy::$options['schedules'];
        $information['next_schedule_index'] = \pb_backupbuddy::$options['next_schedule_index'];
        $information['schedules_run_time']  = $this->get_schedules_run_time();
        return $information;
    }


    /**
     * Save profile.
     *
     * @return array $information Return response array.
     *
     * @uses \pb_backupbuddy::$options
     * @uses \pb_backupbuddy::save()
     */
    public function save_profile() {
        $profile_id = isset( $_POST['profile_id'] ) ? sanitize_text_field( wp_unslash( $_POST['profile_id'] ) ) : 0;
        $profile    = json_decode( base64_decode( wp_unslash( $_POST['data'] ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..

        if ( ! is_array( $profile ) ) {
            return array( 'error' => esc_html__( 'Invalid profile data', 'mainwp-child' ) );
        }

        \pb_backupbuddy::$options['profiles'][ $profile_id ] = $profile;
        \pb_backupbuddy::save();

        $information['result'] = 'SUCCESS';
        return $information;
    }


    /**
     * Delete profile.
     *
     * @return array $information Return results array.
     *
     * @uses \pb_backupbuddy::$options
     * @uses \pb_backupbuddy::save()
     */
    public function delete_profile() {
        $profile_id = isset( $_POST['profile_id'] ) ? sanitize_text_field( wp_unslash( $_POST['profile_id'] ) ) : 0;

        if ( isset( \pb_backupbuddy::$options['profiles'][ $profile_id ] ) ) {
            unset( \pb_backupbuddy::$options['profiles'][ $profile_id ] );
        }

        \pb_backupbuddy::save();
        $information['result'] = 'SUCCESS';
        return $information;
    }

    /**
     * Delete backup.
     *
     * @param string $type Type of backup.
     * @param bool $subsite_mode Whether or not the backup is for a subsite. Default: false.
     *
     * @return array $information Return results array.
     *
     * @uses \backupbuddy_core::getBackupDirectory()
     * @uses \backupbuddy_core::get_serial_from_file()
     * @uses \backupbuddy_core::getLogDirectory()
     * @uses \backupbuddy_core::getLogDirectory::re::get_serial_from_file()
     * @uses \pb_backupbuddy::save()
     */
    public function delete_backup( $type = 'default', $subsite_mode = false ) { // phpcs:ignore -- NOSONAR - complex.
        $item_ids    = isset( $_POST['item_ids'] ) ? explode( ',', wp_unslash( $_POST['item_ids'] ) ) : array();
        $information = array();
        if ( is_array( $item_ids ) && count( $item_ids ) > 0 ) {
            $needs_save    = false;
            $deleted_files = array();
            foreach ( $item_ids as $item ) {
                if ( file_exists( \backupbuddy_core::getBackupDirectory() . $item ) ) {
                    // wp_delete_file() only gained a return value in WP 6.7 and we support 6.2+, so
                    // absence on readback is the only truth available here.
                    wp_delete_file( \backupbuddy_core::getBackupDirectory() . $item ); // NOSONAR .
                    clearstatcache( true, \backupbuddy_core::getBackupDirectory() . $item );
                    if ( ! file_exists( \backupbuddy_core::getBackupDirectory() . $item ) ) {
                        $deleted_files[] = $item;

                        $backup_files = glob( \backupbuddy_core::getBackupDirectory() . '*.zip' );
                        if ( ! is_array( $backup_files ) ) {
                            $backup_files = array();
                        }
                        if ( count( $backup_files ) > 5 ) { // Keep a minimum number of backups in array for stats.
                            $this_serial      = \backupbuddy_core::get_serial_from_file( $item );
                            $fileoptions_file = \backupbuddy_core::getLogDirectory() . 'fileoptions/' . $this_serial . '.txt';
                            if ( file_exists( $fileoptions_file ) ) {
                                wp_delete_file( $fileoptions_file ); // NOSONAR - safe.
                            }
                            if ( file_exists( $fileoptions_file . '.lock' ) ) {
                                wp_delete_file( $fileoptions_file . '.lock' );
                            }
                            $needs_save = true;
                        }
                    }
                }
            }
            if ( true === $needs_save ) {
                \pb_backupbuddy::save();
            }

            $information['result'] = 'SUCCESS';

        }
        return $information;
    }

    /**
     * Sync other data from $data[] and merge with $information[]
     *
     * @param array $information Returned response array for MainWP BackWPup Extension actions.
     * @param array $data Other data to sync to $information array.
     *
     * @return array $information Returned information array with both sets of data.
     */
    public function sync_others_data( $information, $data = array() ) {
        if ( isset( $data['syncBackupBuddy'] ) && $data['syncBackupBuddy'] ) {
            try {
                $information['syncBackupBuddy'] = $this->get_sync_data();
            } catch ( MainWP_Exception $e ) {
                // ok!
            }
        }
        return $information;
    }

    /**
     * Get sync data.
     *
     * @uses \MainWP\Child\MainWP_Helper::instance()->check_classes_exists()
     * @uses \MainWP\Child\MainWP_Helper::instance()->check_methods()
     * @uses \pb_backupbuddy::plugin_path()
     * @uses \backupbuddy_core::get_plugins_root()
     * @uses \backupbuddy_core::get_themes_root()
     * @uses \backupbuddy_core::get_media_root()
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::pb_additional_tables()
     * @uses \MainWP\Child\MainWP_Utility::update_lasttime_backup()
     */
    public function get_sync_data() {
        try {
            if ( ! class_exists( $this->backupbuddy_core_class ) ) {
                MainWP_Helper::instance()->check_classes_exists( '\pb_backupbuddy' );
                MainWP_Helper::instance()->check_methods( '\pb_backupbuddy', array( 'plugin_path' ) );
                $plugin_path = \pb_backupbuddy::plugin_path();
                if ( file_exists( $plugin_path . $this->path_core_file ) ) {
                    require_once $plugin_path . $this->path_core_file; // NOSONAR - WP compatible.
                }
            }

            MainWP_Helper::instance()->check_classes_exists( array( $this->backupbuddy_core_class, '\backupbuddy_api' ) );
            MainWP_Helper::instance()->check_methods( $this->backupbuddy_core_class, array( 'get_plugins_root', 'get_themes_root', 'get_media_root' ) );
            MainWP_Helper::instance()->check_methods( '\backupbuddy_api', array( 'getOverview' ) );

            $data                      = array();
            $data['plugins_root']      = \backupbuddy_core::get_plugins_root();
            $data['themes_root']       = \backupbuddy_core::get_themes_root();
            $data['media_root']        = \backupbuddy_core::get_media_root();
            $data['additional_tables'] = $this->pb_additional_tables();
            $data['abspath']           = ABSPATH;

            $getOverview                  = \backupbuddy_api::getOverview();
            $data['editsSinceLastBackup'] = $getOverview['editsSinceLastBackup'];

            if ( isset( $getOverview['lastBackupStats']['finish'] ) ) {
                $finish_time             = $getOverview['lastBackupStats']['finish'];
                $time                    = $this->localize_time( $finish_time );
                $data['lastBackupStats'] = date( 'M j - g:i A', $time ); // phpcs:ignore -- local time.
                $data['lasttime_backup'] = $finish_time;
            } else {
                $data['lastBackupStats'] = 'Unknown';
            }

            return $data;
        } catch ( MainWP_Exception $e ) {
            // not exit here!
        }

        return false;
    }

    /**
     * Localize time.
     *
     * @param float $timestamp Time to localize.
     *
     * @return float|int Return localized timestamp.
     */
    public function localize_time($timestamp ) {
        if ( function_exists( 'get_option' ) ) {
            $gmt_offset = get_option( 'gmt_offset' );
        } else {
            $gmt_offset = 0;
        }
        return $timestamp + ( $gmt_offset * 3600 );
    }

    /**
     * Backup list.
     *
     * @return array $information Return results array.
     *
     * @uses \pb_backupbuddy::plugin_path()
     * @uses MainWP_Child_Back_Up_Buddy::get_backup_list()
     * @uses MainWP_Child_Back_Up_Buddy::get_recent_backup_list()
     * @uses \backupbuddy_core::getBackupDirectory()
     */
    public function backup_list() {
        require_once \pb_backupbuddy::plugin_path() . '/destinations/bootstrap.php'; // NOSONAR - WP compatible.
        $information                                  = array();
        $information['backup_list']                   = $this->get_backup_list();
        $information['recent_backup_list']            = $this->get_recent_backup_list();
        $backup_directory                             = \backupbuddy_core::getBackupDirectory();
        $backup_directory                             = str_replace( '\\', '/', $backup_directory );
        $backup_directory                             = rtrim( $backup_directory, '/\\' ) . '/';
        $information['backupDirectoryWithinSiteRoot'] = ( false !== stristr( $backup_directory, ABSPATH ) ) ? 'yes' : 'no';
        $information['result']                        = 'SUCCESS';
        return $information;
    }

    /**
     * Save note.
     *
     * @return array $information Return results array.
     *
     * @uses \pb_backupbuddy::plugin_path()
     * @uses \pb_backupbuddy::$classes
     * @uses \pb_backupbuddy::$classes::get_comment()
     * @uses \pb_backupbuddy::status()
     * @uses \pb_backupbuddy_fileoptions()
     * @uses \pb_backupbuddy_fileoptions::is_ok()
     * @uses \pb_backupbuddy_fileoptions::save()
     * @uses \pb_backupbuddy::$classes::set_comment()
     * @uses \backupbuddy_core::getBackupDirectory()
     * @uses \backupbuddy_core::normalize_comment_data()
     * @uses \backupbuddy_core::get_serial_from_file()
     * @uses \backupbuddy_core::getLogDirectory()
     */
    public function save_note() {
        if ( ! isset( \pb_backupbuddy::$classes['zipbuddy'] ) ) {
            require_once \pb_backupbuddy::plugin_path() . '/lib/zipbuddy/zipbuddy.php'; // NOSONAR - WP compatible.
            \pb_backupbuddy::$classes['zipbuddy'] = new \pluginbuddy_zipbuddy( \backupbuddy_core::getBackupDirectory() );
        }
        $backup_file = isset( $_POST['backup_file'] ) ? wp_unslash( $_POST['backup_file'] ) : '';
        $note        = isset( $_POST['backup_file'] ) ? wp_unslash( $_POST['note'] ) : '';
        $note        = preg_replace( '/[[:space:]]+/', ' ', $note );
        $note        = preg_replace( '/[^[:print:]]/', '', $note );
        $note        = substr( $note, 0, 200 );

        // Returns true on success, else the error message.
        $old_comment     = \pb_backupbuddy::$classes['zipbuddy']->get_comment( $backup_file );
        $comment         = \backupbuddy_core::normalize_comment_data( $old_comment );
        $comment['note'] = $note;

        $comment_result = \pb_backupbuddy::$classes['zipbuddy']->set_comment( $backup_file, $comment );

        $information = array();
        if ( true === $comment_result ) {
            $information['result'] = 'SUCCESS';
        }

        // Even if we cannot save the note into the archive file, store it in internal settings.
        $serial = \backupbuddy_core::get_serial_from_file( $backup_file );

        require_once \pb_backupbuddy::plugin_path() . '/classes/fileoptions.php'; // NOSONAR - WP compatible.
        \pb_backupbuddy::status( 'details', 'Fileoptions instance #24.' );
        $backup_options = new \pb_backupbuddy_fileoptions( \backupbuddy_core::getLogDirectory() . 'fileoptions/' . $serial . '.txt' );
        $result         = $backup_options->is_ok();
        if ( true === $result ) {
            $backup_options->options['integrity']['comment'] = $note;
            $backup_options->save();
        }
        return $information;
    }

    /**
     * Get hash.
     *
     * @return array|string[] Return results array.
     *
     * @uses \backupbuddy_core::getBackupDirectory()
     */
    public function get_hash() {
        $callback_data = isset( $_POST['callback_data'] ) ? wp_unslash( $_POST['callback_data'] ) : '';
        $file          = \backupbuddy_core::getBackupDirectory() . $callback_data;
        if ( file_exists( $file ) ) {
            return array(
                'result' => 'SUCCESS',
                'hash'   => md5_file( $file ),
            );
        } else {
            return array( 'error' => 'Not found the file' );
        }
    }

    /**
     * Zip viewer.
     *
     * @return array|string[] Return results array.
     *
     * @uses \pb_backupbuddy::plugin_path()
     * @uses \backupbuddy_core::getLogDirectory()
     * @uses \pb_backupbuddy::status()
     * @uses \pb_backupbuddy_fileoptions()
     * @uses \pb_backupbuddy_fileoptions::is_ok()
     * @uses \pb_backupbuddy_fileoptions::save()
     * @uses \pb_backupbuddy::$classes
     * @uses \pb_backupbuddy::$classes::get_file_list()
     * @uses \pluginbuddy_zipbuddy()
     */
    public function zip_viewer() { // phpcs:ignore -- NOSONAR - complex.

        // How long to cache the specific backup file tree information for (seconds).
        $max_cache_time = 86400;

        // This is the root directory we want the listing for.
        $root     = isset( $_POST['dir'] ) ? wp_unslash( $_POST['dir'] ) : '';
        $root_len = strlen( $root );

        // This will identify the backup zip file we want to list.
        $serial = isset( $_POST['serial'] ) ? sanitize_text_field( wp_unslash( $_POST['serial'] ) ) : '';
        $alerts = array();
        // The fileoptions file that contains the file tree information.
        require_once \pb_backupbuddy::plugin_path() . '/classes/fileoptions.php'; // NOSONAR - WP compatible.
        $fileoptions_file = \backupbuddy_core::getLogDirectory() . 'fileoptions/' . $serial . '-filetree.txt';

        $chk_valid = false;
        // Purge cache if too old.
        if ( file_exists( $fileoptions_file ) && ( ( time() - filemtime( $fileoptions_file ) ) > $max_cache_time ) ) { // NOSONAR .
            $chk_valid = true;
        }

        if ( $chk_valid ) {
            // wp_delete_file() only gained a return value in WP 6.7 and we support 6.2+, so the
            // alert has to rest on the file still being there, not on the call's return.
            wp_delete_file( $fileoptions_file ); // NOSONAR - safe.
            clearstatcache( true, $fileoptions_file );
            if ( file_exists( $fileoptions_file ) ) {
                $alerts[] = 'Error #456765545. Unable to wipe cached fileoptions file `' . $fileoptions_file . '`.';
            }
        }

        \pb_backupbuddy::status( 'details', 'Fileoptions instance #28.' );
        $fileoptions = new \pb_backupbuddy_fileoptions( $fileoptions_file );
        $zip_viewer  = isset( $_POST['zip_viewer'] ) ? wp_unslash( $_POST['zip_viewer'] ) : '';
        // Either we are getting cached file tree information or we need to create afresh.
        $result = $fileoptions->is_ok();
        if ( true !== $result ) {
            // Get file listing.
            require_once \pb_backupbuddy::plugin_path() . '/lib/zipbuddy/zipbuddy.php'; // NOSONAR - WP compatible.
            \pb_backupbuddy::$classes['zipbuddy'] = new \pluginbuddy_zipbuddy( ABSPATH, array(), 'unzip' );
            $files                               = \pb_backupbuddy::$classes['zipbuddy']->get_file_list( \backupbuddy_core::getBackupDirectory() . str_replace( '\\/', '', $zip_viewer ) );
            $fileoptions->options                = $files;
            $fileoptions->save();
        } else {
            $files = &$fileoptions->options;
        }

        if ( ! is_array( $files ) ) {
            return array( 'error' => 'Error #548484.  Unable to retrieve file listing from backup file `' . htmlentities( $zip_viewer ) . '`.' );
        }
        $subdirs = array();

        foreach ( $files as $key => $file ) {
            if ( strlen( $file[0] ) < $root_len ) {
                unset( $files[ $key ] );
                continue;
            }

            if ( substr( $file[0], 0, $root_len ) !== $root ) {
                unset( $files[ $key ] );
                continue;
            }

            if ( 0 === strcmp( $file[0], $root ) ) {
                unset( $files[ $key ] );
                continue;
            }
            $unrooted_file = substr( $file[0], $root_len );
            $pos           = strpos( $unrooted_file, '/' );
            if ( false !== $pos ) {
                $subdir = substr( $unrooted_file, 0, ( $pos + 1 ) );
                if ( ! in_array( $subdir, $subdirs ) ) {
                    $subdirs[]        = $subdir;
                    $files[ $key ][0] = $subdir;
                } else {
                    unset( $files[ $key ] );
                }
            } else {
                $files[ $key ][0] = $unrooted_file;
            }
        }

        return array(
            'result'  => 'SUCCESS',
            'files'   => $files,
            'message' => implode( '<br/>', $alerts ),
        );
    }

    /**
     * Exclude tree.
     *
     * @return array|string[] Return excluded files & directories list html or ERROR message.
     */
    public function exclude_tree() { // phpcs:ignore -- NOSONAR - complex.
        $root = substr( ABSPATH, 0, strlen( ABSPATH ) - 1 ) . '/' . ( isset( $_POST['dir'] ) ? ltrim( urldecode( $_POST['dir'] ), '/\\' ) : '' );
        if ( file_exists( $root ) ) {
            $files = scandir( $root );

            natcasesort( $files );

            // Sort with directories first.
            $sorted_files       = array(); // Temporary holder for sorting files.
            $sorted_directories = array(); // Temporary holder for sorting directories.
            foreach ( $files as $file ) {
                if ( ( '.' === $file ) || ( '..' === $file ) ) {
                    continue;
                }
                if ( is_file( str_replace( '//', '/', $root . $file ) ) ) {
                    array_push( $sorted_files, $file );
                } else {
                    array_unshift( $sorted_directories, $file );
                }
            }
            $files = array_merge( array_reverse( $sorted_directories ), $sorted_files );
            unset( $sorted_files );
            unset( $sorted_directories );
            unset( $file );

            ob_start();

            if ( count( $files ) > 0 ) { // Files found.
                echo '<ul class="jqueryFileTree" style="display: none;">';
                foreach ( $files as $file ) {
                    if ( file_exists( str_replace( '//', '/', $root . $file ) ) ) {
                        if ( is_dir( str_replace( '//', '/', $root . $file ) ) ) { // Directory.
                            echo '<li class="directory collapsed">';
                            $return  = '';
                            $return .= '<div class="pb_backupbuddy_treeselect_control">';
                            $return .= '<img src="' . \pb_backupbuddy::plugin_url() . '/images/redminus.png" style="vertical-align: -3px;" title="Add to exclusions..." class="pb_backupbuddy_filetree_exclude">';
                            $return .= '</div>';
                            echo '<a href="#" rel="' . htmlentities( str_replace( ABSPATH, '', $root ) . $file ) . '/" title="Toggle expand...">' . htmlentities( $file ) . $return . '</a>';
                            echo '</li>';
                        } else { // File.
                            echo '<li class="file collapsed">';
                            $return  = '';
                            $return .= '<div class="pb_backupbuddy_treeselect_control">';
                            $return .= '<img src="' . \pb_backupbuddy::plugin_url() . '/images/redminus.png" style="vertical-align: -3px;" title="Add to exclusions..." class="pb_backupbuddy_filetree_exclude">';
                            $return .= '</div>';
                            echo '<a href="#" rel="' . htmlentities( str_replace( ABSPATH, '', $root ) . $file ) . '">' . htmlentities( $file ) . $return . '</a>';
                            echo '</li>';
                        }
                    }
                }
                echo '</ul>';
            } else {
                echo '<ul class="jqueryFileTree" style="display: none;">';
                echo '<li><a href="#" rel="' . htmlentities( \pb_backupbuddy::_POST( 'dir' ) . 'NONE' ) . '"><i>Empty Directory ...</i></a></li>';
                echo '</ul>';
            }

            $html = ob_get_clean();
            return array( 'result' => $html );
        } else {
            return array( 'error' => 'Error #1127555. Unable to read child site root.' );
        }
    }

    /**
     * Additional tables.
     *
     * @param bool $display_size Display size. Default: false.
     *
     * @return string Additional table html.
     * @throws MainWP_Exception|MainWP_Exception Error message.
     *
     * @uses \MainWP\Child\MainWP_Helper::instance()->check_classes_exists()
     * @uses \MainWP\Child\MainWP_Helper::instance()->check_methods()
     * @uses \MainWP\Child\MainWP_Helper::instance()->check_properties()
     * @uses \MainWP\Child\MainWP_Helper::instance()->check_methods()
     * @uses $wpdb::get_results()
     * @uses \pb_backupbuddy::$format::file_size()
     * @uses \pb_backupbuddy::plugin_url()
     */
    public function pb_additional_tables( $display_size = false ) { // phpcs:ignore -- NOSONAR - complex.

        MainWP_Helper::instance()->check_classes_exists( '\pb_backupbuddy' );
        MainWP_Helper::instance()->check_methods( '\pb_backupbuddy', 'plugin_url' );
        MainWP_Helper::instance()->check_properties( '\pb_backupbuddy', 'format' );
        MainWP_Helper::instance()->check_methods( \pb_backupbuddy::$format, 'file_size' );

        $return      = '';
        $size_string = '';

        /** @global object $wpdb WordPress Database. */
        global $wpdb;

        if ( true === $display_size ) {
            $results = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A );
        } else {
            $results = $wpdb->get_results( 'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()', ARRAY_A );
        }
        foreach ( $results as $result ) {

            if ( true === $display_size ) {
                // Fix up row count and average row length for InnoDB engine which returns inaccurate (and changing) values for these.
                if ( 'InnoDB' === $result['Engine'] ) {
                    $rowCount = $wpdb->get_var( "SELECT COUNT(1) as rowCount FROM `{$result[ 'Name' ]}`", ARRAY_A ); // phpcs:ignore -- safe query.
                    if ( false !== $rowCount ) {
                        $result['Rows'] = $rowCount;
                        if ( 0 < $result['Rows'] ) {
                            $result['Avg_row_length'] = ( $result['Data_length'] / $result['Rows'] );
                        }
                    }
                    unset( $rowCount );
                }

                // Table size.
                $size_string = ' (' . \pb_backupbuddy::$format->file_size( ( $result['Data_length'] + $result['Index_length'] ) ) . ') ';

            } // end if display size enabled.

            $return .= '<li class="file ext_sql collapsed">';
            $return .= '<a rel="/" alt="' . $result['table_name'] . '">' . $result['table_name'] . $size_string;
            $return .= '<div class="pb_backupbuddy_treeselect_control">';
            $return .= '<img src="' . \pb_backupbuddy::plugin_url() . '/images/redminus.png" style="vertical-align: -3px;" title="Add to exclusions..." class="pb_backupbuddy_table_addexclude"> <img src="' . \pb_backupbuddy::plugin_url() . '/images/greenplus.png" style="vertical-align: -3px;" title="Add to inclusions..." class="pb_backupbuddy_table_addinclude">';
            $return .= '</div>';
            $return .= '</a>';
            $return .= '</li>';
        }

        return '<div class="jQueryOuterTree" style="position: absolute; height: 160px;"><ul class="jqueryFileTree">' . $return . '</ul></div>';
    }

    /**
     * Restore file view.
     *
     * @return array|string[] Return results array. ERROR message on failure.
     *
     * @uses \backupbuddy_core::get_serial_from_file()
     * @uses \pb_backupbuddy::plugin_path()
     * @uses \pluginbuddy_zipbuddy()
     * @uses \pluginbuddy_zipbuddy::extract()
     * @uses \backupbuddy_core::getBackupDirectory()
     * @uses \pb_backupbuddy::status()
     * @uses \pb_backupbuddy::anti_directory_browsing()
     * @uses \pb_backupbuddy::$filesystem::unlink_recursive()
     */
    public function restore_file_view() {

        $archive_file = isset( $_POST['archive'] ) ? wp_unslash( $_POST['archive'] ) : ''; // archive to extract from.
        $file         = isset( $_POST['file'] ) ? wp_unslash( $_POST['file'] ) : ''; // file to extract.
        $serial       = \backupbuddy_core::get_serial_from_file( $archive_file ); // serial of archive.
        $temp_file    = uniqid(); // temp filename to extract into.

        require_once \pb_backupbuddy::plugin_path() . '/lib/zipbuddy/zipbuddy.php'; // NOSONAR - WP compatible.
        $zipbuddy = new \pluginbuddy_zipbuddy( \backupbuddy_core::getBackupDirectory() );

        // Calculate temp directory & lock it down.
        $temp_dir    = get_temp_dir();
        $destination = $temp_dir . 'backupbuddy-' . $serial;
        if ( ( ! file_exists( $destination ) ) && ( false === MainWP_Helper::mkdir( $destination ) ) ) {
            $error = 'Error #458485945b: Unable to create temporary location.';
            \pb_backupbuddy::status( 'error', $error );
            return array( 'error' => $error );
        }

        // If temp directory is within webroot then lock it down.
        $temp_dir = str_replace( '\\', '/', $temp_dir ); // Normalize for Windows.
        $temp_dir = rtrim( $temp_dir, '/\\' ) . '/'; // Enforce single trailing slash.
        if ( false !== stristr( $temp_dir, ABSPATH ) ) { // Temp dir is within webroot.
            \pb_backupbuddy::anti_directory_browsing( $destination );
        }
        unset( $temp_dir );

        $message = 'Extracting "' . $file . '" from archive "' . $archive_file . '" into temporary file "' . $destination . '". ';
        \pb_backupbuddy::status( 'details', $message );

        $file_content = '';

        $extractions    = array( $file => $temp_file );
        $extract_result = $zipbuddy->extract( \backupbuddy_core::getBackupDirectory() . $archive_file, $destination, $extractions );
        if ( false === $extract_result ) { // failed.
            $error = 'Error #584984458. Unable to extract.';
            \pb_backupbuddy::status( 'error', $error );
            return array( 'error' => $error );
        } else { // success.
            $file_content = file_get_contents( $destination . '/' . $temp_file ); //phpcs:ignore WordPress.WP.AlternativeFunctions
        }

        // Try to cleanup.
        if ( file_exists( $destination ) ) {
            if ( false === \pb_backupbuddy::$filesystem->unlink_recursive( $destination ) ) {
                \pb_backupbuddy::status( 'details', 'Unable to delete temporary holding directory `' . $destination . '`.' );
            } else {
                \pb_backupbuddy::status( 'details', 'Cleaned up temporary files.' );
            }
        }

        return array(
            'result'       => 'SUCCESS',
            'file_content' => $file_content,
        );
    }


    /**
     * Backupbuddy Restore files.
     *
     * @return array Return results array.
     *
     * @uses \pb_backupbuddy::set_status_serial()
     * @uses \pb_backupbuddy::status()
     * @uses \pb_backupbuddy::plugin_path()
     * @uses \backupbuddy_restore_files::restore()
     * @uses \backupbuddy_core::getBackupDirectory()
     * @uses \pb_backupbuddy::flush()
     */
    public function restore_file_restore() {

        $files        = isset( $_POST['files'] ) ? wp_unslash( $_POST['files'] ) : ''; // file to extract.
        $archive_file = isset( $_POST['archive'] ) ? wp_unslash( $_POST['archive'] ) : ''; // archive to extract from.

        $files_array = explode( ',', $files );
        $files       = array();
        foreach ( $files_array as $file ) {
            if ( substr( $file, -1 ) === '/' ) { // If directory then add wildcard.
                $file = $file . '*';
            }
            $files[ $file ] = $file;
        }
        unset( $files_array );

        /** @global object $pb_backupbuddy_js_status BackupBuddy js status. */
        global $pb_backupbuddy_js_status;

        $pb_backupbuddy_js_status = true;

        \pb_backupbuddy::set_status_serial( 'restore' );

        $wp_ver = MainWP_Child_Server_Information_Base::get_wordpress_version();

        \pb_backupbuddy::status( 'details', 'BackupBuddy v' . \pb_backupbuddy::settings( 'version' ) . ' using WordPress v' . $wp_ver . ' on ' . PHP_OS . '.' );

        require_once \pb_backupbuddy::plugin_path() . '/classes/_restoreFiles.php'; // NOSONAR - WP compatible.

        ob_start();
        \backupbuddy_restore_files::restore( \backupbuddy_core::getBackupDirectory() . $archive_file, $files, ABSPATH );
        $restore_result = ob_get_clean();
        \pb_backupbuddy::flush();
        return array( 'restore_result' => $restore_result );
    }

    /**
     * Get backup list.
     *
     * @param string $type Type of backup.
     * @param bool $subsite_mode Whether or not the backup is for a subsite. Default: false.
     *
     * @return array Return $sorted_backups array.
     *
     * @uses \backupbuddy_core::getBackupDirectory()
     * @uses \backupbuddy_core::backup_prefix()
     * @uses \backupbuddy_core::get_serial_from_file()
     * @uses \backupbuddy_core::getLogDirectory()
     * @uses \backupbuddy_core::backup_integrity_check()
     * @uses \backupbuddy_core::pretty_backup_type()
     * @uses \backupbuddy_core::getBackupTypeFromFile()
     * @uses \backupbuddy_core::pretty_backup_type()
     * @uses \backupbuddy_core::getBackupTypeFromFile()
     * @uses \pb_backupbuddy::plugin_path()
     * @uses \pb_backupbuddy::status()
     * @uses \pb_backupbuddy_fileoptions()
     * @uses \pb_backupbuddy_fileoptions()
     * @uses \pb_backupbuddy::$format::file_size()
     * @uses \pb_backupbuddy::$format::time_ago()
     * @uses \pb_backupbuddy::$format::prettify()
     * @uses \pb_backupbuddy::$format::date()
     * @uses \pb_backupbuddy::$format::localize_time()
     * @uses \MainWP\Child\MainWP_Utility::create_nonce_without_session()
     */
    public function get_backup_list( $type = 'default', $subsite_mode = false ) { // phpcs:ignore -- NOSONAR - complex.
        $backups           = array();
        $backup_sort_dates = array();

        $files = glob( \backupbuddy_core::getBackupDirectory() . 'backup*.zip' );
        if ( ! is_array( $files ) ) {
            $files = array();
        }

        $files2 = glob( \backupbuddy_core::getBackupDirectory() . 'snapshot*.zip' );
        if ( ! is_array( $files2 ) ) {
            $files2 = array();
        }

        $files = array_merge( $files, $files2 );

        if ( is_array( $files ) && ! empty( $files ) ) { // For robustness. Without open_basedir the glob() function returns an empty array for no match. With open_basedir in effect the glob() function returns a boolean false for no match.

            $backup_prefix = \backupbuddy_core::backup_prefix(); // To checking that this user can see this backup.
            foreach ( $files as $file_id => $file ) {

                if ( ( true === $subsite_mode ) && is_multisite() && ! strstr( $file, $backup_prefix ) ) {
                    unset( $files[ $file_id ] ); // Remove this backup from the list. This user does not have access to it.
                    continue; // Skip processing to next file.
                }

                $serial = \backupbuddy_core::get_serial_from_file( $file );

                $options = array();
                if ( file_exists( \backupbuddy_core::getLogDirectory() . 'fileoptions/' . $serial . '.txt' ) ) {
                    require_once \pb_backupbuddy::plugin_path() . '/classes/fileoptions.php'; // NOSONAR - WP compatible.
                    \pb_backupbuddy::status( 'details', 'Fileoptions instance #33.' );
                    $backup_options = new \pb_backupbuddy_fileoptions( \backupbuddy_core::getLogDirectory() . 'fileoptions/' . $serial . '.txt', false, false, true ); // Will create file to hold integrity data if nothing exists.
                } else {
                    $backup_options = '';
                }
                $backup_integrity = \backupbuddy_core::backup_integrity_check( $file, $backup_options, $options );

                // Backup status.
                $pretty_status = array(
                    true    => '<span class="pb_label pb_label-success">Good</span>', // v4.0+ Good.
                    'pass'  => '<span class="pb_label pb_label-success">Good</span>', // Pre-v4.0 Good.
                    false   => '<span class="pb_label pb_label-important">Bad</span>',  // v4.0+ Bad.
                    'fail'  => '<span class="pb_label pb_label-important">Bad</span>',  // Pre-v4.0 Bad.
                );

                // Backup type.
                $pretty_type = array(
                    'full'      => 'Full',
                    'db'        => 'Database',
                    'files'     => 'Files',
                    'themes'    => 'Themes',
                    'plugins'   => 'Plugins',
                );

                // Defaults.
                $detected_type = '';
                $file_size     = '';
                $modified      = '';
                $modified_time = 0;
                $integrity     = '';

                $main_string = 'Warn#284.';
                if ( is_array( $backup_integrity ) ) { // Data intact... put it all together.
                    // Calculate time ago.
                    $time_ago = '';
                    if ( isset( $backup_integrity['modified'] ) ) {
                        $time_ago = \pb_backupbuddy::$format->time_ago( $backup_integrity['modified'] ) . ' ago';
                    }

                    $detected_type = \pb_backupbuddy::$format->prettify( $backup_integrity['detected_type'], $pretty_type );
                    if ( '' === $detected_type ) {
                        $detected_type = \backupbuddy_core::pretty_backup_type( \backupbuddy_core::getBackupTypeFromFile( $file ) );
                        if ( '' === $detected_type ) {
                            $detected_type = '<span class="description">Unknown</span>';
                        }
                    } else {
                        if ( isset( $backup_options->options['profile'] ) ) {
                            $detected_type = '
                            <div>
                                <span style="color: #AAA; float: left;">' . $detected_type . '</span>
                                <span style="display: inline-block; float: left; height: 15px; border-right: 1px solid #EBEBEB; margin-left: 6px; margin-right: 6px;"></span>
                                ' . htmlentities( $backup_options->options['profile']['title'] ) . '
                            </div>
                            ';
                        }
                    }

                    $file_size     = \pb_backupbuddy::$format->file_size( $backup_integrity['size'] );
                    $modified      = \pb_backupbuddy::$format->date( \pb_backupbuddy::$format->localize_time( $backup_integrity['modified'] ), 'l, F j, Y - g:i:s a' );
                    $modified_time = $backup_integrity['modified'];
                    if ( isset( $backup_integrity['status'] ) ) { // Pre-v4.0.
                        $status = $backup_integrity['status'];
                    } else { // v4.0+.
                        $status = $backup_integrity['is_ok'];
                    }

                    // Calculate main row string.
                    if ( 'default' === $type ) { // Default backup listing.
                        $download_url = '/wp-admin/admin-ajax.php?action=mainwp_backupbuddy_download_archive&backupbuddy_backup=' . basename( $file ) . '&_wpnonce=' . MainWP_Utility::create_nonce_without_session( 'mainwp_download_backup' );
                        $main_string  = '<a href="#" download-url="' . $download_url . '"class="backupbuddyFileTitle mwp_bb_download_backup_lnk" title="' . basename( $file ) . '">' . $modified . ' (' . $time_ago . ')</a>';
                    } elseif ( 'migrate' === $type ) { // Migration backup listing.
                        $main_string = '<a class="pb_backupbuddy_hoveraction_migrate backupbuddyFileTitle" rel="' . basename( $file ) . '" href="' . \pb_backupbuddy::page_url() . '&migrate=' . basename( $file ) . '&value=' . basename( $file ) . '" title="' . basename( $file ) . '">' . $modified . ' (' . $time_ago . ')</a>';
                    } else {
                        $main_string = '{Unknown type.}';
                    }
                    // Add comment to main row string if applicable.
                    if ( isset( $backup_integrity['comment'] ) && ( false !== $backup_integrity['comment'] ) && ( '' !== $backup_integrity['comment'] ) ) {
                        $main_string .= '<br><span class="description">Note: <span class="pb_backupbuddy_notetext">' . htmlentities( $backup_integrity['comment'] ) . '</span></span>';
                    }

                    $integrity = \pb_backupbuddy::$format->prettify( $status, $pretty_status ) . ' ';
                    if ( isset( $backup_integrity['scan_notes'] ) && count( (array) $backup_integrity['scan_notes'] ) > 0 ) {
                        foreach ( (array) $backup_integrity['scan_notes'] as $scan_note ) {
                            $integrity .= $scan_note . ' ';
                        }
                    }
                    $integrity .= '<a href="#" serial="' . $serial . '" class="mwp_bb_reset_integrity_lnk" file-name="' . basename( $file ) . '" title="Rescan integrity. Last checked ' . \pb_backupbuddy::$format->date( $backup_integrity['scan_time'] ) . '."> <i class="fa fa-refresh" aria-hidden="true"></i></a>';
                    $integrity .= '<div class="row-actions"><a title="' . esc_html__( 'Backup Status', 'mainwp-child' ) . '" href="#" serial="' . $serial . '" class="mainwp_bb_view_details_lnk thickbox">' . esc_html__( 'View Details', 'mainwp-child' ) . '</a></div>';

                    $sumLogFile = \backupbuddy_core::getLogDirectory() . 'status-' . $serial . '_' . \pb_backupbuddy::$options['log_serial'] . '.txt';
                    if ( file_exists( $sumLogFile ) ) {
                        $integrity .= '<div class="row-actions"><a title="' . esc_html__( 'View Backup Log', 'mainwp-child' ) . '" href="#" serial="' . $serial . '" class="mainwp_bb_view_log_lnk thickbox">' . esc_html__( 'View Log', 'mainwp-child' ) . '</a></div>';
                    }
                }

                // No integrity check for themes or plugins types.
                $raw_type = \backupbuddy_core::getBackupTypeFromFile( $file );
                if ( ( 'themes' === $raw_type ) || ( 'plugins' === $raw_type ) ) {
                    $integrity = 'n/a';
                }

                $backups[ basename( $file ) ] = array(
                    array( basename( $file ), $main_string . '<br><span class="description" style="color: #AAA; display: inline-block; margin-top: 5px;">' . basename( $file ) . '</span>' ),
                    $detected_type,
                    $file_size,
                    $integrity,
                );

                $backup_sort_dates[ basename( $file ) ] = $modified_time;

            }
        }

        // Sort backup by date.
        arsort( $backup_sort_dates );
        // Re-arrange backups based on sort dates.
        $sorted_backups = array();
        foreach ( $backup_sort_dates as $backup_file => $backup_sort_date ) {
            $sorted_backups[ $backup_file ] = $backups[ $backup_file ];
            unset( $backups[ $backup_file ] );
        }
        unset( $backups );
        return $sorted_backups;
    }

    /**
     * Get recent backup list.
     *
     * @return array $recentBackups Return recent backups array.
     *
     * @uses \backupbuddy_core::getLogDirectory()
     * @uses \backupbuddy_core::pretty_backup_type()
     * @uses \backupbuddy_core::getBackupTypeFromFile()
     * @uses \backupbuddy_core::getBackupTypeFromFile()
     * @uses \pb_backupbuddy::plugin_path()
     * @uses \pb_backupbuddy::status()
     * @uses \pb_backupbuddy_fileoptions()
     * @uses \pb_backupbuddy_fileoptions::is_ok()
     * @uses \pb_backupbuddy::$format::date()
     * @uses \pb_backupbuddy::$format::localize_time()
     * @uses \pb_backupbuddy::$format::prettify()
     * @uses \pb_backupbuddy::$format::file_size()
     */
    public function get_recent_backup_list() { // phpcs:ignore -- NOSONAR - complex.
        $recentBackups_list = glob( \backupbuddy_core::getLogDirectory() . 'fileoptions/*.txt' );
        if ( ! is_array( $recentBackups_list ) ) {
            $recentBackups_list = array();
        }

        $recentBackups = array();
        if ( count( $recentBackups_list ) > 0 ) {

            // Backup type.
            $pretty_type = array(
                'full'  => 'Full',
                'db'    => 'Database',
                'files' => 'Files',
            );

            foreach ( $recentBackups_list as $backup_fileoptions ) {

                require_once \pb_backupbuddy::plugin_path() . '/classes/fileoptions.php'; // NOSONAR - WP compatible.
                \pb_backupbuddy::status( 'details', 'Fileoptions instance #1.' );
                $backup = new \pb_backupbuddy_fileoptions( $backup_fileoptions, true );
                $result = $backup->is_ok();
                if ( true !== $result ) {
                    \pb_backupbuddy::status( 'error', esc_html__( 'Unable to access fileoptions data file.', 'mainwp-child' ) . ' Error: ' . $result );
                    continue;
                }
                $backup = &$backup->options;

                if ( ! isset( $backup['serial'] ) || ( '' === $backup['serial'] ) ) {
                    continue;
                }
                if ( ( $backup['finish_time'] >= $backup['start_time'] ) && ( 0 !== (int) $backup['start_time'] ) ) {
                    $status = '<span class="pb_label pb_label-success">Completed</span>';
                } elseif ( -1 === (int)$backup['finish_time'] ) {
                    $status = '<span class="pb_label pb_label-warning">Cancelled</span>';
                } elseif ( false === $backup['finish_time'] ) {
                    $status = '<span class="pb_label pb_label-error">Failed (timeout?)</span>';
                } elseif ( ( time() - $backup['updated_time'] ) > \backupbuddy_constants::TIME_BEFORE_CONSIDERED_TIMEOUT ) {
                    $status = '<span class="pb_label pb_label-error">Failed (likely timeout)</span>';
                } else {
                    $status = '<span class="pb_label pb_label-warning">In progress or timed out</span>';
                }
                $status .= '<br>';

                // Technical details link.
                $status .= '<div class="row-actions">';
                $status .= '<a title="' . esc_html__( 'Backup Process Technical Details', 'mainwp-child' ) . '" href="#" serial="' . $backup['serial'] . '" class="mainwp_bb_view_details_lnk thickbox">View Details</a>';

                $sumLogFile = \backupbuddy_core::getLogDirectory() . 'status-' . $backup['serial'] . '_' . \pb_backupbuddy::$options['log_serial'] . '.txt';
                if ( file_exists( $sumLogFile ) ) {
                    $status .= '<div class="row-actions"><a title="' . esc_html__( 'View Backup Log', 'mainwp-child' ) . '" href="#" serial="' . $backup['serial'] . '"  class="mainwp_bb_view_log_lnk thickbox">' . esc_html__( 'View Log', 'mainwp-child' ) . '</a></div>';
                }

                $status .= '</div>';

                $backupTitle = '<span class="backupbuddyFileTitle" style="color: #000;" title="' . basename( $backup['archive_file'] ) . '">' . \pb_backupbuddy::$format->date( \pb_backupbuddy::$format->localize_time( $backup['start_time'] ), 'l, F j, Y - g:i:s a' ) . ' (' . \pb_backupbuddy::$format->time_ago( $backup['start_time'] ) . ' ago)</span><br><span class="description">' . basename( $backup['archive_file'] ) . '</span>';

                if ( isset( $backup['profile'] ) && isset( $backup['profile']['type'] ) ) {
                    $backupType = '<div><span style="color: #AAA; float: left;">' . \pb_backupbuddy::$format->prettify( $backup['profile']['type'], $pretty_type ) . '</span><span style="display: inline-block; float: left; height: 15px; border-right: 1px solid #EBEBEB; margin-left: 6px; margin-right: 6px;"></span>' . $backup['profile']['title'] . '</div>';
                } else {
                    $backupType = \backupbuddy_core::pretty_backup_type( \backupbuddy_core::getBackupTypeFromFile( $backup['archive_file'] ) );
                    if ( '' === $backupType ) {
                        $backupType = '<span class="description">Unknown</span>';
                    }
                }

                if ( isset( $backup['archive_size'] ) && ( $backup['archive_size'] > 0 ) ) {
                    $archive_size = \pb_backupbuddy::$format->file_size( $backup['archive_size'] );
                } else {
                    $archive_size = 'n/a';
                }

                // No integrity check for themes or plugins types.
                $raw_type = \backupbuddy_core::getBackupTypeFromFile( $backup['archive_file'] );
                if ( ( 'themes' === $raw_type ) || ( 'plugins' === $raw_type ) ) {
                    $status = 'n/a';
                }

                // Append to list.
                $recentBackups[ $backup['serial'] ] = array(
                    array( basename( $backup['archive_file'] ), $backupTitle ),
                    $backupType,
                    $archive_size,
                    ucfirst( $backup['trigger'] ),
                    $status,
                    'start_timestamp' => $backup['start_time'], // Used by array sorter later to put backups in proper order.
                );

            }

            /**
             * Backupbuddy Sort.
             *
             * @param array $arr Array to sort.
             * @param string $key Sort key.
             *
             */
            function pb_backupbuddy_aasort( &$arr, $key ) {
                $sorter = array();
                $ret    = array();
                reset( $arr );
                foreach ( $arr as $ii => $va ) {
                    $sorter[ $ii ] = $va[ $key ];
                }
                asort( $sorter );
                foreach ( $sorter as $ii => $va ) {
                    $ret[ $ii ] = $arr[ $ii ];
                }
                $arr = $ret;
            }

            pb_backupbuddy_aasort( $recentBackups, 'start_timestamp' ); // Sort by multidimensional array with key start_timestamp.
            $recentBackups = array_reverse( $recentBackups ); // Reverse array order to show newest first.
        }

        return $recentBackups;
    }

    /**
     * Delete sheduled backup.
     *
     * @return array $information Return results array.
     *
     * @uses \pb_backupbuddy::$options
     * @uses \pb_backupbuddy::save()
     */
    public function delete_scheduled_backup() {
        $schedule_ids = isset( $_POST['schedule_ids'] ) ? wp_unslash( $_POST['schedule_ids'] ) : '';
        $schedule_ids = explode( ',', $schedule_ids );

        if ( empty( $schedule_ids ) ) {
            return array( 'error' => esc_html__( 'Empty schedule ids', 'mainwp-child' ) );
        }
        foreach ( $schedule_ids as $sch_id ) {
            if ( isset( \pb_backupbuddy::$options['schedules'][ $sch_id ] ) ) {
                unset( \pb_backupbuddy::$options['schedules'][ $sch_id ] );
            }
        }
        \pb_backupbuddy::save();
        $information['result'] = 'SUCCESS';
        return $information;
    }

    /**
     * View log.
     *
     * @return array|string[] Return log html.
     *
     * @uses \backupbuddy_core::getLogDirectory()
     * @uses \pb_backupbuddy::$format::date()
     * @uses \pb_backupbuddy::$format::time_ago()
     * @uses \pb_backupbuddy::flush()
     */
    public function view_log() {
        $serial = isset( $_POST['serial'] ) ? sanitize_text_field( wp_unslash( $_POST['serial'] ) ) : '';
        // The serial becomes part of the log path below; restrict it to BackupBuddy's serial alphabet so it cannot traverse out of the log directory.
        $serial  = preg_replace( '/[^a-zA-Z0-9_-]/', '', $serial );
        $logFile = \backupbuddy_core::getLogDirectory() . 'status-' . $serial . '_sum_' . \pb_backupbuddy::$options['log_serial'] . '.txt';

        if ( ! file_exists( $logFile ) ) {
            return array( 'error' => 'Error #858733: Log file `' . $logFile . '` not found or access denied.' );
        }

        $lines = file_get_contents( $logFile ); //phpcs:ignore WordPress.WP.AlternativeFunctions -- // NOSONAR .
        $lines = explode( "\n", $lines );
        ob_start();
        ?>

        <textarea readonly="readonly" id="backupbuddy_messages" wrap="off" style="width: 100%; min-height: 400px; height: 500px; height: 80%; background: #FFF;">
        <?php
        foreach ( (array) $lines as $rawline ) {
            $line = json_decode( $rawline, true );
            if ( is_array( $line ) ) {
                $u = '';
                if ( isset( $line['u'] ) ) {
                    $u = '.' . $line['u'];
                }
                echo esc_html( \pb_backupbuddy::$format->date( $line['time'], 'G:i:s' ) . $u ) . "\t\t";
                echo esc_html( $line['run'] ) . "sec\t";
                echo esc_html( $line['mem'] ) . "MB\t";
                echo esc_html( $line['event'] ) . "\t";
                echo esc_html( $line['data'] ) . "\n";
            } else {
                echo esc_html( $rawline ) . "\n";
            }
        }
        ?>
            </textarea><br><br>
        <small>Log file: <?php echo esc_html( $logFile ); ?></small>
        <br>
        <?php
        echo '<small>Last modified: ' . \pb_backupbuddy::$format->date( filemtime( $logFile ) ) . ' (' . \pb_backupbuddy::$format->time_ago( filemtime( $logFile ) ) . ' ago)'; // NOSONAR .
        ?>
        <br><br>
        <?php
        $html = ob_get_clean();
        \pb_backupbuddy::flush();
        return array(
            'result'   => 'SUCCESS',
            'html_log' => $html,
        );
    }


    /**
     * Pretty results.
     *
     * @param bool $value Results TRUE|FALSE.
     *
     * @return string Return Pass or Fail message.
     */
    function pb_pretty_results( $value ) { // NOSONAR - 3rd compatible.
        if ( true === $value ) {
            return '<span class="pb_label pb_label-success">Pass</span>';
        } else {
            return '<span class="pb_label pb_label-important">Fail</span>';
        }
    }


    /**
     * View details.
     *
     * @return array|string[] Return results array & echo details.
     *
     * @uses \backupbuddy_core::getLogDirectory()
     * @uses \backupbuddy_core::getZipMeta()
     * @uses \backupbuddy_core::getBackupDirectory()
     * @uses \pb_backupbuddy_fileoptions()
     * @uses \pb_backupbuddy_fileoptions::is_ok()
     * @uses \pb_backupbuddy::$format::date()
     * @uses \pb_backupbuddy::$format::localize_time()
     * @uses \pb_backupbuddy::$ui::list_table()
     * @uses \pb_backupbuddy::load()
     * @uses \pb_backupbuddy::plugin_path()
     * @uses \pb_backupbuddy::status()
     * @uses \pb_backupbuddy::flush()
     */
    public function view_detail() { // phpcs:ignore -- NOSONAR - complex.

        $serial = isset( $_POST['serial'] ) ? sanitize_text_field( wp_unslash( $_POST['serial'] ) ) : '';
        $serial = str_replace( '/\\', '', $serial );
        \pb_backupbuddy::load();

        require_once \pb_backupbuddy::plugin_path() . '/classes/fileoptions.php'; // NOSONAR - WP compatible.
        \pb_backupbuddy::status( 'details', 'Fileoptions instance #27.' );
        $optionsFile    = \backupbuddy_core::getLogDirectory() . 'fileoptions/' . $serial . '.txt';
        $backup_options = new \pb_backupbuddy_fileoptions( $optionsFile, true );
        $result         = $backup_options->is_ok();
        if ( true !== $result ) {
            return array( 'error' => esc_html__( 'Unable to access fileoptions data file.', 'mainwp-child' ) . ' Error: ' . $result );
        }
        ob_start();
        $integrity = $backup_options->options['integrity'];

        $start_time  = 'Unknown';
        $finish_time = 'Unknown';
        if ( isset( $backup_options->options['start_time'] ) ) {
            $start_time = \pb_backupbuddy::$format->date( \pb_backupbuddy::$format->localize_time( $backup_options->options['start_time'] ) ) . ' <span class="description">(' . \pb_backupbuddy::$format->time_ago( $backup_options->options['start_time'] ) . ' ago)</span>';
            if ( $backup_options->options['finish_time'] > 0 ) {
                $finish_time = \pb_backupbuddy::$format->date( \pb_backupbuddy::$format->localize_time( $backup_options->options['finish_time'] ) ) . ' <span class="description">(' . \pb_backupbuddy::$format->time_ago( $backup_options->options['finish_time'] ) . ' ago)</span>';
            } else { // unfinished.
                $finish_time = '<i>Unfinished</i>';
            }
        }

        // ***** BEGIN TESTS AND RESULTS.
        if ( isset( $integrity['status_details'] ) ) {
            echo '<h3>Integrity Technical Details</h3>';
            echo '<textarea style="width: 100%; height: 175px;" wrap="off">';
            foreach ( $integrity as $item_name => $item_value ) {
                $item_value = str_replace( '<br />', '<br>', $item_value );
                $item_value = str_replace( '<br><br>', '<br>', $item_value );
                $item_value = str_replace( '<br>', "\n     ", $item_value );
                echo $item_name . ' => ' . $item_value . "\n";
            }
            echo '</textarea><br><br><b>Note:</b> It is normal to see several "file not found" entries as BackupBuddy checks for expected files in multiple locations, expecting to only find each file once in one of those locations.';
        } else {

            echo '<br>';

            if ( isset( $integrity['status_details'] ) ) { // PRE-v4.0 Tests.


                // The tests & their status.
                $tests   = array();
                $tests[] = array( 'BackupBackup data file exists', $this->pb_pretty_results( $integrity['status_details']['found_dat'] ) );
                $tests[] = array( 'Database SQL file exists', $this->pb_pretty_results( $integrity['status_details']['found_sql'] ) );
                if ( 'full' === $integrity['detected_type'] ) {
                    $tests[] = array( 'WordPress wp-config.php exists (full/files backups only)', $this->pb_pretty_results( $integrity['status_details']['found_wpconfig'] ) );
                } elseif ( 'files' === $integrity['detected_type'] ) {
                    $tests[] = array( 'WordPress wp-config.php exists (full/files backups only)', $this->pb_pretty_results( $integrity['status_details']['found_wpconfig'] ) );
                } else { // DB only.
                    $tests[] = array( 'WordPress wp-config.php exists (full/files backups only)', '<span class="pb_label pb_label-success">N/A</span>' );
                }
            } else { // 4.0+ Tests.
                $tests = array();
                if ( isset( $integrity['tests'] ) ) {
                    foreach ( (array) $integrity['tests'] as $test ) {
                        if ( true === $test['pass'] ) {
                            $status_text = '<span class="pb_label pb_label-success">Pass</span>';
                        } else {
                            $status_text = '<span class="pb_label pb_label-important">Fail</span>';
                        }
                        $tests[] = array( $test['test'], $status_text );
                    }
                }
            }

            $columns = array(
                esc_html__( 'Integrity Test', 'mainwp-child' ),
                esc_html__( 'Status', 'mainwp-child' ),
            );

            \pb_backupbuddy::$ui->list_table(
                $tests,
                array(
                    'columns'       => $columns,
                    'css'           => 'width: 100%; min-width: 200px;',
                )
            );

        }
        echo '<br><br>';
        // ***** END TESTS AND RESULTS.

        // Output meta info table (if any).
        $metaInfo = array();
        $metaInfo = \backupbuddy_core::getZipMeta( \backupbuddy_core::getBackupDirectory() . $integrity['file'] );
        if ( isset( $integrity['file'] ) && ( false === $metaInfo ) ) {
            echo '<i>No meta data found in zip comment. Skipping meta information display.</i>';
        } else {
            \pb_backupbuddy::$ui->list_table(
                $metaInfo,
                array(
                    'columns'       => array( 'Backup Details', 'Value' ),
                    'css'           => 'width: 100%; min-width: 200px;',
                )
            );
        }
        echo '<br><br>';

        // ***** BEGIN STEPS.
        $steps   = array();
        $steps[] = array( 'Start Time', $start_time, '' );
        if ( isset( $backup_options->options['steps'] ) ) {
            foreach ( $backup_options->options['steps'] as $step ) {
                if ( isset( $step['finish_time'] ) && ( 0 !== (int) $step['finish_time'] ) ) {

                    // Step name.
                    if ( 'backup_create_database_dump' === $step['function'] ) {
                        if ( count( $step['args'][0] ) === 1 ) {
                            $step_name = 'Database dump (breakout: ' . $step['args'][0][0] . ')';
                        } else {
                            $step_name = 'Database dump';
                        }
                    } elseif ( 'backup_zip_files' === $step['function'] ) {
                        if ( isset( $backup_options->options['steps']['backup_zip_files'] ) ) {
                            $zip_time = $backup_options->options['steps']['backup_zip_files'];
                        } else {
                            $zip_time = 0;
                        }

                        // Calculate write speed in MB/sec for this backup.
                        if ( '0' === $zip_time ) { // Took approx 0 seconds to backup so report this speed.
                            $write_speed = '> ' . \pb_backupbuddy::$format->file_size( $backup_options->options['integrity']['size'] );
                        } else {
                            if ( 0 === $zip_time ) {
                                $write_speed = '';
                            } else {
                                $write_speed = \pb_backupbuddy::$format->file_size( $backup_options->options['integrity']['size'] / $zip_time ) . '/sec';
                            }
                        }
                        $step_name = 'Zip archive creation (Write speed: ' . $write_speed . ')';
                    } elseif ( 'post_backup' === $step['function'] ) {
                        $step_name = 'Post-backup cleanup';
                    } elseif ( 'integrity_check' === $step['function'] ) {
                        $step_name = 'Integrity Check';
                    } else {
                        $step_name = $step['function'];
                    }

                    // Step time taken.
                    $seconds = (int) ( $step['finish_time'] - $step['start_time'] );
                    if ( $seconds < 1 ) {
                        $step_time = '< 1 second';
                    } else {
                        $step_time = $seconds . ' seconds';
                    }

                    // Compile details for this step into array.
                    $steps[] = array(
                        $step_name,
                        $step_time,
                        $step['attempts'],
                    );

                }
            }
        } else {
            $step_times[] = 'unknown';
        }

        // Total overall time from initiation to end.
        if ( isset( $backup_options->options['finish_time'] ) && isset( $backup_options->options['start_time'] ) && ( 0 !== (int) $backup_options->options['finish_time'] ) && ( 0 !== (int) $backup_options->options['start_time'] ) ) {
            $seconds = ( $backup_options->options['finish_time'] - $backup_options->options['start_time'] );
            if ( $seconds < 1 ) {
                $total_time = '< 1 second';
            } else {
                $total_time = $seconds . ' seconds';
            }
        } else {
            $total_time = '<i>Unknown</i>';
        }
        $steps[] = array( 'Finish Time', $finish_time, '' );
        $steps[] = array(
            '<b>Total Overall Time</b>',
            $total_time,
            '',
        );

        $columns = array(
            esc_html__( 'Backup Steps', 'mainwp-child' ),
            esc_html__( 'Time', 'mainwp-child' ),
            esc_html__( 'Attempts', 'mainwp-child' ),
        );

        if ( empty( $steps ) ) {
            esc_html_e( 'No step statistics were found for this backup.', 'mainwp-child' );
        } else {
            \pb_backupbuddy::$ui->list_table(
                $steps,
                array(
                    'columns'       => $columns,
                    'css'           => 'width: 100%; min-width: 200px;',
                )
            );
        }
        echo '<br><br>';
        // ***** END STEPS.

        if ( isset( $backup_options->options['trigger'] ) ) {
            $trigger = $backup_options->options['trigger'];
        } else {
            $trigger = 'Unknown trigger';
        }
        if ( isset( $integrity['scan_time'] ) ) {
            $scanned = \pb_backupbuddy::$format->date( \pb_backupbuddy::$format->localize_time( $integrity['scan_time'] ) );
            echo ucfirst( $trigger ) . " backup {$integrity['file']} last scanned {$scanned}.";
        }
        echo '<br><br><br>';

        echo '<a class="button secondary-button" onclick="jQuery(\'#pb_backupbuddy_advanced_debug\').slideToggle();">Display Advanced Debugging</a>';
        echo '<div id="pb_backupbuddy_advanced_debug" style="display: none;">From options file: `' . $optionsFile . '`.<br>';
        echo '<textarea style="width: 100%; height: 400px;" wrap="on">';
        echo print_r( $backup_options->options, true ); // phpcs:ignore -- debug feature.
        echo '</textarea><br><br>';
        echo '</div><br><br>';

        $html = ob_get_clean();
        \pb_backupbuddy::flush();
        return array(
            'result'      => 'SUCCESS',
            'html_detail' => $html,
        );
    }

    /**
     * Reset Integrity.
     *
     * @return array $information Return results array.
     */
    public function reset_integrity() {
        $_GET['reset_integrity']    = isset( $_POST['reset_integrity'] ) ? sanitize_text_field( wp_unslash( $_POST['reset_integrity'] ) ) : '';
        $information['backup_list'] = $this->get_backup_list();
        $information['result']      = 'SUCCESS';
        return $information;
    }

    /**
     * Download backup archive.
     *
     * @uses \MainWP\Child\MainWP_Utility::verify_nonce_without_session()
     * @uses \pb_backupbuddy::_GET()
     * @uses \pb_backupbuddy::$option
     * @uses \backupbuddy_core::verifyAjaxAccess()
     * @uses \backupbuddy_core::backup_prefix()
     * @uses \backupbuddy_core::getBackupDirectory()
     */
    public function download_archive() { // NOSONAR - WP compatible.

        if ( ! isset( $_GET['_wpnonce'] ) || empty( $_GET['_wpnonce'] ) ) {
            die( '-1' );
        }

        if ( ! MainWP_Utility::verify_nonce_without_session( $_GET['_wpnonce'], 'mainwp_download_backup' ) ) {
            die( '-2' );
        }

        \backupbuddy_core::verifyAjaxAccess();

        if ( is_multisite() && ! current_user_can( 'manage_network' ) && ! strstr( \pb_backupbuddy::_GET( 'backupbuddy_backup' ), \backupbuddy_core::backup_prefix() ) ) {
            die( 'Access Denied. You may only download backups specific to your Multisite Subsite. Only Network Admins may download backups for another subsite in the network.' );
        }

        if ( ! file_exists( \backupbuddy_core::getBackupDirectory() . \pb_backupbuddy::_GET( 'backupbuddy_backup' ) ) ) { // Does not exist.
            die( 'Error #548957857584784332. The requested backup file does not exist. It may have already been deleted.' );
        }

        $abspath    = str_replace( '\\', '/', ABSPATH );
        $backup_dir = str_replace( '\\', '/', \backupbuddy_core::getBackupDirectory() );

        if ( false === stristr( $backup_dir, $abspath ) ) {
            die( 'Error #5432532. You cannot download backups stored outside of the WordPress web root. Please use FTP or other means.' );
        }

        $sitepath     = str_replace( $abspath, '', $backup_dir );
        $download_url = rtrim( site_url(), '/\\' ) . '/' . trim( $sitepath, '/\\' ) . '/' . \pb_backupbuddy::_GET( 'backupbuddy_backup' );

        if ( '1' === \pb_backupbuddy::$options['lock_archives_directory'] ) {

            if ( file_exists( \backupbuddy_core::getBackupDirectory() . '.htaccess' ) ) {
                // wp_delete_file() only gained a return value in WP 6.7 and we support 6.2+; a
                // download served through a still-present .htaccess would fail anyway, so the
                // readback is what decides.
                wp_delete_file( \backupbuddy_core::getBackupDirectory() . '.htaccess' );
                clearstatcache( true, \backupbuddy_core::getBackupDirectory() . '.htaccess' );
                if ( file_exists( \backupbuddy_core::getBackupDirectory() . '.htaccess' ) ) {
                    die( 'Error #844594. Unable to temporarily remove .htaccess security protection on archives directory to allow downloading. Please verify permissions of the BackupBuddy archives directory or manually download via FTP.' );
                }
            }

            header( 'Location: ' . $download_url );
            ob_clean();
            flush();
            sleep( 8 );

            $htaccess_creation_status = MainWP_Helper::file_put_contents( \backupbuddy_core::getBackupDirectory() . '.htaccess', 'deny from all' ); //phpcs:ignore WordPress.WP.AlternativeFunctions
            if ( false === $htaccess_creation_status ) {
                die( 'Error #344894545. Security Warning! Unable to create security file (.htaccess) in backups archive directory. This file prevents unauthorized downloading of backups should someone be able to guess the backup location and filenames. This is unlikely but for best security should be in place. Please verify permissions on the backups directory.' );
            }
        } else {
            header( 'Location: ' . $download_url );
        }
        die();
    }

    /**
     * Create backup.
     *
     * @return array|string[] Return SUCCESS message or ERROR message.
     *
     * @uses \pb_backupbuddy::$options
     * @uses \pb_backupbuddy_backup()
     * @uses \pb_backupbuddy::plugin_path()
     * @uses \pb_backupbuddy::random_string()
     * @uses \pb_backupbuddy_backup::start_backup_process()
     */
    public function create_backup() {
        $requested_profile = isset( $_POST['profile_id'] ) ? sanitize_text_field( wp_unslash( $_POST['profile_id'] ) ) : 0;

        if ( ! isset( \pb_backupbuddy::$options['profiles'][ $requested_profile ] ) ) {
            return array( 'error' => 'Invalid Profile. Not found.' );
        }

        require_once \pb_backupbuddy::plugin_path() . '/classes/backup.php'; // NOSONAR - WP compatible.
        $newBackup = new \pb_backupbuddy_backup();

        $profile_array   = \pb_backupbuddy::$options['profiles'][ $requested_profile ];
        $serial_override = \pb_backupbuddy::random_string( 10 );

        if ( true !== $newBackup->start_backup_process( $profile_array, 'manual', array(), isset( $_POST['post_backup_steps'] ) && is_array( $_POST['post_backup_steps'] ) ? wp_unslash( $_POST['post_backup_steps'] ) : array(), '', $serial_override, array(), '', '' ) ) {
            return array( 'error' => esc_html__( 'Fatal Error #4344443: Backup failure. Please see any errors listed in the Status Log for details.', 'mainwp-child' ) );
        }
        return array( 'result' => 'SUCCESS' );
    }

    /**
     * Start backup.
     *
     * @return array|int[]|string[] Return results array containing either OK->1 OR ERROR message.
     *
     * @uses \pb_backupbuddy::plugin_path()
     * @uses \pb_backupbuddy_backup()
     * @uses \pb_backupbuddy_backup::start_backup_process()
     */
    public function start_backup() {
        require_once \pb_backupbuddy::plugin_path() . '/classes/backup.php'; // NOSONAR - WP compatible.
        $newBackup = new \pb_backupbuddy_backup();
        $data      = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : '';
        if ( is_array( $data ) && isset( $data['serial_override'] ) ) {
            if ( $newBackup->start_backup_process(
                $data['profile_array'],
                $data['trigger'],
                array(),
                isset( $data['post_backup_steps'] ) && is_array( $data['post_backup_steps'] ) ? $data['post_backup_steps'] : array(),
                '',
                $data['serial_override'],
                isset( $data['export_plugins'] ) ? $data['export_plugins'] : '',
                $data['direction'],
                isset( $data['deployDestination'] ) ? $data['deployDestination'] : ''
            ) !== true ) {
                return array( 'error' => esc_html__( 'Fatal Error #4344443: Backup failure. Please see any errors listed in the Status Log for details.', 'mainwp-child' ) );
            }
        } else {
            return array( 'error' => 'Invalid backup request.' );
        }

        return array( 'ok' => 1 );
    }

    /**
     * Backup status.
     *
     * @return array|string[] Return results array or ERROR message on failure.
     *
     * @uses \backupbuddy_api::getBackupStatus()
     *
     */
    public function backup_status() {
        $data   = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : '';
        $result = '';
        if ( is_array( $data ) && isset( $data['serial'] ) ) {
            ob_start();
            \backupbuddy_api::getBackupStatus( $data['serial'], $data['specialAction'], $data['initwaitretrycount'], $data['sqlFile'], true );
            $result = ob_get_clean();
        } else {
            return array( 'error' => 'Invalid backup request.' );
        }
        return array(
            'ok'     => 1,
            'result' => $result,
        );
    }

    /**
     * Stop Backup.
     *
     * @return int[] Return 1 on success.
     */
    public function stop_backup() {
        $serial = isset( $_POST['serial'] ) ? wp_unslash( $_POST['serial'] ) : '';
        set_transient( 'pb_backupbuddy_stop_backup-' . $serial, true, ( 60 * 60 * 24 ) );
        return array( 'ok' => 1 );
    }

    /**
     * Remove Save.
     *
     * @return int[]|string[] Return 1 on success or ERROR message on failure.
     *
     * @uses \pb_backupbuddy::$options
     * @uses \pb_backupbuddy::save()
     */
    public function remote_save() { // NOSONAR - WP compatible.
        $data           = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : false;
        $destination_id = isset( $_POST['destination_id'] ) ? sanitize_text_field( wp_unslash( $_POST['destination_id'] ) ) : 0;

        if ( is_array( $data ) && isset( $data['do_not_override'] ) ) {

            if ( !empty( $data['do_not_override'] ) && ( 's32' === $data['type'] || 's33' === $data['type'] ) ) {
                $not_override = array(
                    'accesskey',
                    'secretkey',
                    'bucket',
                    'region',
                );
                foreach ( $not_override as $opt ) {
                    if ( isset( $data[ $opt ] ) ) {
                        unset( $data[ $opt ] );
                    }
                }
            }

            unset( $data['do_not_override'] );
        }

        if ( is_array( $data ) ) {
            if ( isset( \pb_backupbuddy::$options['remote_destinations'][ $destination_id ] ) ) {
                \pb_backupbuddy::$options['remote_destinations'][ $destination_id ] = array_merge( \pb_backupbuddy::$options['remote_destinations'][ $destination_id ], $data );
            } else {
                $data['token'] = \pb_backupbuddy::$options['dropboxtemptoken'];
                \pb_backupbuddy::$options['remote_destinations'][ $destination_id ] = $data;
            }
            \pb_backupbuddy::save();
            return array( 'ok' => 1 );
        } else {
            return array( 'error' => 'Invalid request.' );
        }
    }

    /**
     * Remove backup list.
     *
     * @return array $information Return results array.
     *
     * @uses \pb_backupbuddy::$options
     */
    public function remote_list() {
        $information = array();
        if ( isset( \pb_backupbuddy::$options['remote_destinations'] ) ) {
            $information['remote_destinations'] = \pb_backupbuddy::$options['remote_destinations'];
        }
        $information['result'] = 'SUCCESS';
        return $information;
    }

    /**
     * Remote backup delete.
     *
     * @return array|int[]|string[] Returm OK on success or ERROR message on failure.
     *
     * @uses \pb_backupbuddy::plugin_path()
     * @uses \pb_backupbuddy_destinations::delete_destination()
     */
    public function remote_delete() {
        $destination_id = isset( $_POST['destination_id'] ) ? sanitize_text_field( wp_unslash( $_POST['destination_id'] ) ) : null;
        if ( null !== $destination_id ) {
            require_once \pb_backupbuddy::plugin_path() . '/destinations/bootstrap.php'; // NOSONAR - WP compatible.
            $delete_response = \pb_backupbuddy_destinations::delete_destination( $destination_id, true );

            if ( true !== $delete_response ) {
                return array( 'error' => $delete_response );
            } else {
                return array( 'ok' => 1 );
            }
        } else {
            return array( 'error' => 'Invalid request.' );
        }
    }

    /**
     * Remove send.
     *
     * @return array|int[]|string[] Return 1 on sucess or ERROR message on failure.
     *
     * @uses \backupbuddy_core::getBackupDirectory()
     * @uses \backupbuddy_core::status()
     * @uses \backupbuddy_core::schedule_single_event()
     * @uses \pb_backupbuddy::$options
     * @uses \pb_backupbuddy::plugin_path()
     * @uses \pb_backupbuddy_destinations::get_info()
     * @uses \pb_backupbuddy_destination_stash::get_quota()
     * @uses \pb_backupbuddy::$format::file_size()
     */
    public function remote_send() { // NOSONAR - WP compatible.

        $destination_id = isset( $_POST['destination_id'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['destination_id'] ) ) : null;
        $file           = isset( $_POST['file'] ) ? wp_unslash( $_POST['file'] ) : null;
        $trigger        = isset( $_POST['trigger'] ) ? wp_unslash( $_POST['trigger'] ) : 'manual';

        if ( 'importbuddy.php' !== $file ) {
            $backup_file = \backupbuddy_core::getBackupDirectory() . $file;
            if ( ! file_exists( $backup_file ) ) { // Error if file to send did not exist!
                $error_message = 'Unable to find file `' . $backup_file . '` to send. File does not appear to exist. You can try again in a moment or turn on full error logging and try again to log for support.';
                \pb_backupbuddy::status( 'error', $error_message );
                return array( 'error' => $error_message );
            }
            if ( is_dir( $backup_file ) ) { // Error if a directory is trying to be sent.
                $error_message = 'You are attempting to send a directory, `' . $backup_file . '`. Try again and verify there were no javascript errors.';
                \pb_backupbuddy::status( 'error', $error_message );
                return array( 'error' => $error_message );
            }
        } else {
            $backup_file = '';
        }

        if ( isset( $_POST['send_importbuddy'] ) && '1' === $_POST['send_importbuddy'] ) {
            $send_importbuddy = true;
            \pb_backupbuddy::status( 'details', 'Cron send to be scheduled with importbuddy sending.' );
        } else {
            $send_importbuddy = false;
            \pb_backupbuddy::status( 'details', 'Cron send to be scheduled WITHOUT importbuddy sending.' );
        }

        if ( isset( $_POST['delete_after'] ) && '1' === $_POST['delete_after'] ) {
            $delete_after = true;
            \pb_backupbuddy::status( 'details', 'Remote send set to delete after successful send.' );
        } else {
            $delete_after = false;
            \pb_backupbuddy::status( 'details', 'Remote send NOT set to delete after successful send.' );
        }

        if ( ! isset( \pb_backupbuddy::$options['remote_destinations'][ $destination_id ] ) ) {
            return array( 'error' => 'Error #833383: Invalid destination ID `' . htmlentities( $destination_id ) . '`.' );
        }

        // For Stash we will check the quota prior to initiating send.
        if ( 'stash' === \pb_backupbuddy::$options['remote_destinations'][ $destination_id ]['type'] ) {
            // Pass off to destination handler.
            require_once \pb_backupbuddy::plugin_path() . '/destinations/bootstrap.php'; // NOSONAR - WP compatible.
            \pb_backupbuddy_destinations::get_info( 'stash' ); // Used to kick the Stash destination into life.
            $stash_quota = \pb_backupbuddy_destination_stash::get_quota( \pb_backupbuddy::$options['remote_destinations'][ $destination_id ], true );

            if ( isset( $stash_quota['error'] ) ) {
                return array( 'error' => ' Error accessing Stash account. Send aborted. Details: `' . implode( ' - ', $stash_quota['error'] ) . '`.' );
            }

            if ( '' !== $backup_file ) {
                $backup_file_size = filesize( $backup_file ); // NOSONAR .
            } else {
                $backup_file_size = 50000;
            }
            if ( ( $backup_file_size + $stash_quota['quota_used'] ) > $stash_quota['quota_total'] ) {
                ob_start();
                echo "You do not have enough Stash storage space to send this file. Please upgrade your Stash storage or delete files to make space.\n\n";
                echo 'Attempting to send file of size ' . \pb_backupbuddy::$format->file_size( $backup_file_size ) . ' but you only have ' . $stash_quota['quota_available_nice'] . ' available. ';
                echo 'Currently using ' . $stash_quota['quota_used_nice'] . ' of ' . $stash_quota['quota_total_nice'] . ' (' . $stash_quota['quota_used_percent'] . '%).';
                $error = ob_get_clean();
                return array( 'error' => $error );
            }
        }

        \pb_backupbuddy::status( 'details', 'Scheduling cron to send to this remote destination...' );

        $schedule_result = \backupbuddy_core::schedule_single_event( time(), 'remote_send', array( $destination_id, $backup_file, $trigger, $send_importbuddy, $delete_after ) );
        if ( false === $schedule_result ) {
            $error = 'Error scheduling file transfer. Please check your BackupBuddy error log for details. A plugin may have prevented scheduling or the database rejected it.';
            \pb_backupbuddy::status( 'error', $error );
            return array( 'error' => $error );
        } else {
            \pb_backupbuddy::status( 'details', 'Cron to send to remote destination scheduled.' );
        }
        if ( '1' !== \pb_backupbuddy::$options['skip_spawn_cron_call'] ) {
            update_option( '_transient_doing_cron', 0 ); // Prevent cron-blocking for next item.
            spawn_cron( time() + 150 ); // Adds > 60 seconds to get around once per minute cron running limit.
        }
        return array( 'ok' => 1 );
    }

    /**
     * Get main log.
     *
     * @return array Return result array or ERROR message on failure.
     *
     * @uses \backupbuddy_core::getLogDirectory()
     * @uses \pb_backupbuddy::$options
     */
    public function get_main_log() {
        $log_file = \backupbuddy_core::getLogDirectory() . 'log-' . \pb_backupbuddy::$options['log_serial'] . '.txt';
        ob_start();
        if ( file_exists( $log_file ) ) {
            readfile( $log_file );
        } else {
            echo esc_html__( 'Nothing has been logged.', 'mainwp-child' );
        }
        $result = ob_get_clean();
        return array( 'result' => $result );
    }

    /**
     * Other settings.
     *
     * @return string[] Return results array.
     *
     * @uses \pb_backupbuddy::plugin_path()
     * @uses \backupbuddy_housekeeping::run_periodic()
     * @uses \backupbuddy_core::getTempDirectory()
     * @uses \backupbuddy_core::getLogDirectory()
     * @uses \backupbuddy_core::getLogDirectory()
     * @uses \pb_backupbuddy::$filesystem->unlink_recursive()
     * @uses \pb_backupbuddy::anti_directory_browsing()
     * @uses \pb_backupbuddy::save()
     * @uses \pb_backupbuddy_fileoptions()
     * @uses \pb_backupbuddy_fileoptions::is_ok()
     */
    public function settings_other() { // NOSONAR - WP compatible.

        $other_action = isset( $_POST['other_action'] ) ? wp_unslash( $_POST['other_action'] ) : '';

        $message = '';
        $error   = '';

        if ( 'cleanup_now' === $other_action ) {
            $message = 'Performing cleanup procedures now trimming old files and data.';
            require_once \pb_backupbuddy::plugin_path() . '/classes/housekeeping.php'; // NOSONAR - WP compatible.
            \backupbuddy_housekeeping::run_periodic( 0 ); // 0 cleans up everything even if not very old.

        } elseif ( 'delete_tempfiles_now' === $other_action ) {
            $tempDir = \backupbuddy_core::getTempDirectory();
            $logDir  = \backupbuddy_core::getLogDirectory();
            $message = 'Deleting all files contained within `' . $tempDir . '` and `' . $logDir . '`.';
            \pb_backupbuddy::$filesystem->unlink_recursive( $tempDir );
            \pb_backupbuddy::$filesystem->unlink_recursive( $logDir );
            \pb_backupbuddy::anti_directory_browsing( $logDir, false ); // Put log dir back in place.
        } elseif ( 'reset_log' === $other_action ) {
            $log_file = \backupbuddy_core::getLogDirectory() . 'log-' . \pb_backupbuddy::$options['log_serial'] . '.txt';
            if ( file_exists( $log_file ) ) {
                wp_delete_file( $log_file );
            }
            if ( file_exists( $log_file ) ) { // Didnt unlink.
                $error = 'Unable to clear log file. Please verify permissions on file `' . $log_file . '`.';
            } else { // Unlinked.
                $message = 'Cleared log file.';
            }
        } elseif ( 'reset_disalerts' === $other_action ) {
            \pb_backupbuddy::$options['disalerts'] = array();
            \pb_backupbuddy::save();
            $message = 'Dismissed alerts have been reset. They may now be visible again.';

        } elseif ( 'cancel_running_backups' === $other_action ) {
            require_once \pb_backupbuddy::plugin_path() . '/classes/fileoptions.php'; // NOSONAR - WP compatible.

            $fileoptions_directory = \backupbuddy_core::getLogDirectory() . 'fileoptions/';
            $files                 = glob( $fileoptions_directory . '*.txt' );
            if ( ! is_array( $files ) ) {
                $files = array();
            }
            $cancelCount = 0;
            for ( $x = 0; $x <= 3; $x++ ) { // Try this a few times since there may be race conditions on an open file.
                foreach ( $files as $file ) {
                    \pb_backupbuddy::status( 'details', 'Fileoptions instance #383.' );

                    $backup_options = new \pb_backupbuddy_fileoptions( $file, false );
                    $result         = $backup_options->is_ok();
                    if ( true !== $result ) {
                        \pb_backupbuddy::status( 'error', 'Error retrieving fileoptions file `' . $file . '`. Err 335353266.' );
                    } else {
                        if ( empty( $backup_options->options['finish_time'] ) || ( ( false !== $backup_options->options['finish_time'] ) && ( '-1' !== $backup_options->options['finish_time'] ) ) ) {
                            $backup_options->options['finish_time'] = -1; // Force marked as cancelled by user.
                            $backup_options->save();
                            $cancelCount++;
                        }
                    }
                }
                sleep( 1 );
            }

            $message = 'Marked all timed out or running backups & transfers as officially cancelled (`' . $cancelCount . '` total found).';
        }

        return array(
            '_error'   => $error,
            '_message' => $message,
        );
    }

    /**
     * Malware scan.
     *
     * @return array $result Return result array.
     *
     * @uses \backupbuddy_core::schedule_single_event()
     * @uses \backupbuddy_core::addNotification()
     * @uses \pb_backupbuddy::$ui::start_metabox()
     * @uses \pb_backupbuddy::alert()
     */
    public function malware_scan() { // NOSONAR - WP compatible.

        \backupbuddy_core::schedule_single_event( time(), 'housekeeping', array() );
        update_option( '_transient_doing_cron', 0 );
        spawn_cron( time() + 150 );

        ob_start();

        if ( ! defined( 'pluginbuddy_importbuddy' ) ) {
            $url = home_url();
        } else {
            $url = str_replace( $_SERVER['QUERY_STRING'], '', $_SERVER['REQUEST_URI'] );
            $url = str_replace( basename( $url ), '', $url );
            $url = 'http://' . $_SERVER['HTTP_HOST'] . $url;
        }
        ?>
        <style type="text/css">
            .inside label {
                display: block;
                vertical-align: top;
                width: 140px;
                font-weight: bold;
            }
        </style>


        <?php
        \pb_backupbuddy::$ui->start_metabox( esc_html__( 'Malware Scan URL', 'mainwp-child' ), true, 'width: 100%;' );

        ?>

        <?php echo $url; ?>

        <?php

        $continue_1 = true;
        if ( 'http://localhost' === $url ) {
            esc_html_e( 'ERROR: You are currently running your site locally. Your site must be internet accessible to scan.', 'mainwp-child' );
            $continue_1 = false;
        }

        if ( true === $continue_1 ) {

            if ( ! empty( $_POST['refresh'] ) ) {
                delete_transient( 'pb_backupbuddy_malwarescan' );
            }

            if ( ! defined( 'pluginbuddy_importbuddy' ) ) {
                $scan = get_transient( 'pb_backupbuddy_malwarescan' );
            } else {
                $scan = false;
            }

            if ( false === $scan ) {
                flush();

                $scan = wp_remote_get(
                    'http://sitecheck.sucuri.net/scanner/?scan=' . rawurlencode( $url ) . '&serialized&clear=true',
                    array(
                        'method'      => 'GET',
                        'timeout'     => 45,
                        'redirection' => 5,
                        'httpversion' => '1.0',
                        'blocking'    => true,
                        'headers'     => array(),
                        'body'        => null,
                        'cookies'     => array(),
                    )
                );

                if ( is_wp_error( $scan ) ) {
                    \pb_backupbuddy::alert( esc_html__( 'ERROR #24452. Unable to load Malware Scan results. Details:', 'mainwp-child' ) . ' ' . $scan->get_error_message(), true );
                    $scan = 'N;';
                } else {
                    $scan = $scan['body'];
                    set_transient( 'pb_backupbuddy_malwarescan', $scan, 60 * 60 * 1 ); // 1 hour cache.
                }
            }

            $continue_2 = true;
            if ( substr( $scan, 0, 2 ) === 'N;' ) {
                echo esc_html__( 'An error was encountered attempting to scan this site.', 'mainwp-child' ), '<br />';
                echo esc_html__( 'An internet connection is required and this site must be accessible on the public internet.', 'mainwp-child' );
                echo '<br>';
                $scan       = array();
                $continue_2 = false;
            } else {
                $scan = maybe_unserialize( $scan ); // safe third party scan result.
            }
        }
        \pb_backupbuddy::$ui->end_metabox();

        if ( true === $continue_2 ) {

            /**
             * Turn array into html li.
             *
             * @param array $arr Array of data to convert.
             *
             * @return string Return html list.
             */
            function lined_array( $arr ) {
                if ( is_array( $arr ) ) {
                    foreach ( $arr as $array_key => $array_item ) {
                        if ( is_array( $array_item ) ) {
                            $arr[ $array_key ] = lined_array( $array_item );
                        }
                    }
                    $return = '';
                    foreach ( $arr as $array_item ) {
                        $return .= $array_item . '<br />';
                    }
                    return $return;
                } else {
                    if ( empty( $arr ) ) {
                        return '<i>' . esc_html__( 'none', 'mainwp-child' ) . '</i><br />';
                    } else {
                        return $arr . '<br />';
                    }
                }
            }

            if ( ! empty( $scan['MALWARE'] ) && ( 'E' !== $scan['MALWARE'] ) ) {
                echo '<table><tr><td><i class="fa fa-exclamation-circle fa-5x" style="color: red"></i></td><td><h1>', esc_html__( 'Warning: Possible Malware Detected!', 'mainwp-child' ), '</h1>', esc_html__( 'See details below.', 'mainwp-child' ), '</td></tr></table>';
            }
            ?>
            <div class="postbox-container" style="width: 100%; min-width: 750px;">
                <div class="metabox-holder">
                    <div class="meta-box-sortables">

                        <div id="breadcrumbslike" class="postbox">
                            <div class="handlediv" title="<?php esc_html_e( 'Click to toggle', 'mainwp-child' ); ?>"><br /></div>
                            <h3 class="hndle"><span><?php esc_html_e( 'Malware Detection', 'mainwp-child' ); ?></span></h3>
                            <div class="inside">
                                <label><?php esc_html_e( 'Malware', 'mainwp-child' ); ?></label>
                                <?php
                                if ( ! empty( $scan['MALWARE']['WARN'] ) ) { // Malware found.
                                    echo lined_array( $scan['MALWARE']['WARN'] );
                                    \backupbuddy_core::addNotification( 'malware_found', 'Malware detected on `' . $url . '`.', 'A malware scan was run on the site and detected malware.', array(), true );
                                } else { // No malware found.
                                    echo '<i>', esc_html__( 'none', 'mainwp-child' ), '</i><br />';
                                    \backupbuddy_core::addNotification( 'malware_not_found', 'No malware detected on `' . $url . '`.', 'A malware scan was run on the site and did not detect malware.' );
                                }
                                ?>
                                <br />
                            </div>
                        </div>

                        <div id="breadcrumbslike" class="postbox">
                            <div class="handlediv" title="<?php esc_html_e( 'Click to toggle', 'mainwp-child' ); ?>"><br /></div>
                            <h3 class="hndle"><span><?php esc_html_e( 'Web server details', 'mainwp-child' ); ?></span></h3>
                            <div class="inside">
                                <label><?php esc_html_e( 'Site', 'mainwp-child' ); ?></label>
                                <?php
                                if ( ! empty( $scan['SCAN']['SITE'] ) ) {
                                    echo lined_array( $scan['SCAN']['SITE'] );
                                } else {
                                    echo '<i>', esc_html__( 'none', 'mainwp-child' ), '</i><br />';
                                }
                                ?>
                                <br />
                                <label><?php esc_html_e( 'Hostname', 'mainwp-child' ); ?></label>
                                <?php
                                if ( ! empty( $scan['SCAN']['DOMAIN'] ) ) {
                                    echo lined_array( $scan['SCAN']['DOMAIN'] );
                                } else {
                                    echo '<i>', esc_html__( 'none', 'mainwp-child' ), '</i><br />';
                                }
                                ?>
                                <br />
                                <label><?php esc_html_e( 'IP Address', 'mainwp-child' ); ?></label>
                                <?php
                                if ( ! empty( $scan['SCAN']['IP'] ) ) {
                                    echo lined_array( $scan['SCAN']['IP'] );
                                } else {
                                    echo '<i>', esc_html__( 'none', 'mainwp-child' ), '</i><br />';
                                }
                                ?>
                                <br />
                                <label><?php esc_html_e( 'System details', 'mainwp-child' ); ?></label>
                                <?php
                                if ( ! empty( $scan['SYSTEM']['NOTICE'] ) ) {
                                    echo lined_array( $scan['SYSTEM']['NOTICE'] );
                                } else {
                                    echo '<i>', esc_html__( 'none', 'mainwp-child' ), '</i><br />';
                                }
                                ?>
                                <br />
                                <label><?php esc_html_e( 'Information', 'mainwp-child' ); ?></label>
                                <?php
                                if ( ! empty( $scan['SYSTEM']['INFO'] ) ) {
                                    echo lined_array( $scan['SYSTEM']['INFO'] );
                                } else {
                                    echo '<i>', esc_html__( 'none', 'mainwp-child' ), '</i><br />';
                                }
                                ?>
                                <br />
                            </div>
                        </div>
                        <div id="breadcrumbslike" class="postbox">
                            <div class="handlediv" title="Click to toggle"><br /></div>
                            <h3 class="hndle"><span><?php esc_html_e( 'Web application', 'mainwp-child' ); ?></span></h3>
                            <div class="inside">
                                <label><?php esc_html_e( 'Details', 'mainwp-child' ); ?></label>
                                <?php
                                if ( ! empty( $scan['WEBAPP']['INFO'] ) ) {
                                    echo lined_array( $scan['WEBAPP']['INFO'] );
                                } else {
                                    echo '<i>', esc_html__( 'none', 'mainwp-child' ), '</i><br />';
                                }
                                ?>
                                <br />
                                <label><?php esc_html_e( 'Versions', 'mainwp-child' ); ?></label>
                                <?php
                                if ( ! empty( $scan['WEBAPP']['VERSION'] ) ) {
                                    echo lined_array( $scan['WEBAPP']['VERSION'] );
                                } else {
                                    echo '<i>',esc_html__( 'none', 'mainwp-child' ), '</i><br />';
                                }
                                ?>
                                <br />
                                <label><?php esc_html_e( 'Notices', 'mainwp-child' ); ?></label>
                                <?php
                                if ( ! empty( $scan['WEBAPP']['NOTICE'] ) ) {
                                    echo lined_array( $scan['WEBAPP']['NOTICE'] );
                                } else {
                                    echo '<i>', esc_html__( 'none', 'mainwp-child' ), '</i><br />';
                                }
                                ?>
                                <br />
                                <label><?php esc_html_e( 'Errors', 'mainwp-child' ); ?></label>
                                <?php
                                if ( ! empty( $scan['WEBAPP']['ERROR'] ) ) {
                                    echo lined_array( $scan['WEBAPP']['ERROR'] );
                                } else {
                                    echo '<i>',esc_html__( 'none', 'mainwp-child' ), '</i><br />';
                                }
                                ?>
                                <br />
                                <label><?php esc_html_e( 'Warnings', 'mainwp-child' ); ?></label>
                                <?php
                                if ( ! empty( $scan['WEBAPP']['WARN'] ) ) {
                                    echo lined_array( $scan['WEBAPP']['WARN'] );
                                } else {
                                    echo '<i>', esc_html__( 'none', 'mainwp-child' ), '</i><br />';
                                }
                                ?>
                                <br />
                            </div>
                        </div>

                        <div id="breadcrumbslike" class="postbox">
                            <div class="handlediv" title="<?php esc_html_e( 'Click to toggle', 'mainwp-child' ); ?>"><br /></div>
                            <h3 class="hndle"><span><?php esc_html_e( 'Links', 'mainwp-child' ); ?></span></h3>
                            <div class="inside">
                                <?php
                                if ( ! empty( $scan['LINKS']['URL'] ) ) {
                                    echo lined_array( $scan['LINKS']['URL'] );
                                } else {
                                    echo '<i>', esc_html__( 'none', 'mainwp-child' ), '</i><br />';
                                }
                                ?>
                            </div>
                        </div>

                        <div id="breadcrumbslike" class="postbox">
                            <div class="handlediv" title="<?php esc_html_e( 'Click to toggle', 'mainwp-child' ); ?>"><br /></div>
                            <h3 class="hndle"><span><?php esc_html_e( 'Local Javascript', 'mainwp-child' ); ?></span></h3>
                            <div class="inside">
                                <?php
                                if ( ! empty( $scan['LINKS']['JSLOCAL'] ) ) {
                                    echo lined_array( $scan['LINKS']['JSLOCAL'] );
                                } else {
                                    echo '<i>', esc_html__( 'none', 'mainwp-child' ),'</i><br />';
                                }
                                ?>
                            </div>
                        </div>

                        <div id="breadcrumbslike" class="postbox">
                            <div class="handlediv" title="<?php esc_html_e( 'Click to toggle', 'mainwp-child' ); ?>"><br /></div>
                            <h3 class="hndle"><span><?php esc_html_e( 'External Javascript', 'mainwp-child' ); ?></span></h3>
                            <div class="inside">
                                <?php
                                if ( ! empty( $scan['LINKS']['JSEXTERNAL'] ) ) {
                                    echo lined_array( $scan['LINKS']['JSEXTERNAL'] );
                                } else {
                                    echo '<i>', esc_html__( 'none', 'mainwp-child' ), '</i><br />';
                                }
                                ?>
                            </div>
                        </div>

                        <div id="breadcrumbslike" class="postbox">
                            <div class="handlediv" title="<?php esc_attr_e( 'Click to toggle', 'mainwp-child' ); ?>"><br /></div>
                            <h3 class="hndle"><span><?php esc_html_e( 'Iframes Included', 'mainwp-child' ); ?></span></h3>
                            <div class="inside">
                                <?php
                                if ( ! empty( $scan['LINKS']['IFRAME'] ) ) {
                                    echo lined_array( $scan['LINKS']['IFRAME'] );
                                } else {
                                    echo '<i>', esc_html__( 'none', 'mainwp-child' ), '</i><br />';
                                }
                                ?>
                            </div>
                        </div>

                        <div id="breadcrumbslike" class="postbox">
                            <div class="handlediv" title="<?php esc_attr_e( 'Click to toggle', 'mainwp-child' ); ?>"><br /></div>
                            <h3 class="hndle"><span><?php esc_html_e( ' Blacklisting Status', 'mainwp-child' ); ?></span></h3>
                            <div class="inside">
                                <?php
                                if ( ! empty( $scan['BLACKLIST']['INFO'] ) ) {
                                    echo lined_array( $scan['BLACKLIST']['INFO'] );
                                } else {
                                    echo '<i>', esc_html__( 'none', 'mainwp-child' ), '</i><br />';
                                }
                                ?>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
            <?php

        }
        $result = ob_get_clean();

        return array( 'result' => $result );
    }


    /**
     * Live setup.
     *
     * @return array|array[]
     *
     * @uses iThemes_Credentials::get_password_hash()
     * @uses ITXAPI_Helper2::get_access_token()
     * @uses \pb_backupbuddy::plugin_path()
     * @uses \pb_backupbuddy::save()
     * @uses \pb_backupbuddy::status()
     * @uses \pb_backupbuddy_destination_stash2::stashAPI()
     * @uses \pb_backupbuddy::$options
     * @uses \pb_backupbuddy_destination_live::$default_settings
     * @uses \backupbuddy_live::send_trim_settings()
     * @uses \backupbuddy_core::schedule_single_event()
     */
    public function live_setup() { // NOSONAR - WP compatible.

        $errors = array();

        $archive_types = array(
            'db'      => esc_html__( 'Database Backup', 'mainwp-child' ),
            'full'    => esc_html__( 'Full Backup', 'mainwp-child' ),
            'plugins' => esc_html__( 'Plugins Backup', 'mainwp-child' ),
            'themes'  => esc_html__( 'Themes Backup', 'mainwp-child' ),
        );

        $archive_periods = array(
            'daily',
            'weekly',
            'monthly',
            'yearly',
        );

        if ( ( '' === $_POST['live_username'] ) || ( '' === $_POST['live_password'] ) ) { // A field is blank.
            $errors[] = 'You must enter your iThemes username & password to log in to BackupBuddy Stash Live.';
        } else { // Username and password provided.

            require_once \pb_backupbuddy::plugin_path() . '/destinations/stash2/class.itx_helper2.php'; // NOSONAR - WP compatible.
            require_once \pb_backupbuddy::plugin_path() . '/destinations/stash2/init.php'; // NOSONAR - WP compatible.
            require_once \pb_backupbuddy::plugin_path() . '/destinations/live/init.php'; // NOSONAR - WP compatible.

            // Use MainWP_Child_Server_Information_Base to get WordPress version.
            $wp_ver = \MainWP\Child\MainWP_Child_Server_Information_Base::get_wordpress_version();

            $itxapi_username = strtolower( $_POST['live_username'] );
            $password_hash   = \iThemes_Credentials::get_password_hash( $itxapi_username, $_POST['live_password'] );
            $access_token    = \ITXAPI_Helper2::get_access_token( $itxapi_username, $password_hash, site_url(), $wp_ver );

            $settings = array(
                'itxapi_username' => $itxapi_username,
                'itxapi_password' => $access_token,
            );
            $response = \pb_backupbuddy_destination_stash2::stashAPI( $settings, 'connect' );

            if ( ! is_array( $response ) ) { // Error message.
                $errors[] = print_r( $response, true ); // phpcs:ignore -- debug feature.
            } else {
                if ( isset( $response['error'] ) ) {
                    $errors[] = $response['error']['message'];
                } else {
                    if ( isset( $response['token'] ) ) {
                        $itxapi_token = $response['token'];
                    } else {
                        $errors[] = 'Error #2308832: Unexpected server response. Token missing. Check your BackupBuddy Stash Live login and try again. Detailed response: `' . print_r( $response, true ) . '`.'; // phpcs:ignore -- debug feature.
                    }
                }
            }

            // If we have the token then create the Live destination.
            if ( isset( $itxapi_token ) ) {
                if ( count( \pb_backupbuddy::$options['remote_destinations'] ) > 0 ) {
                    $nextDestKey = max( array_keys( \pb_backupbuddy::$options['remote_destinations'] ) ) + 1;
                } else { // no destinations yet. first index.
                    $nextDestKey = 0;
                }

                \pb_backupbuddy::$options['remote_destinations'][ $nextDestKey ]                    = \pb_backupbuddy_destination_live::$default_settings;
                \pb_backupbuddy::$options['remote_destinations'][ $nextDestKey ]['itxapi_username'] = isset( $_POST['live_username'] ) ? wp_unslash( $_POST['live_username'] ) : '';
                \pb_backupbuddy::$options['remote_destinations'][ $nextDestKey ]['itxapi_token']    = $itxapi_token;
                \pb_backupbuddy::$options['remote_destinations'][ $nextDestKey ]['title']           = 'My BackupBuddy Stash Live';

                // Notification email.
                \pb_backupbuddy::$options['remote_destinations'][ $nextDestKey ]['email'] = isset( $_POST['email']  ) ? sanitize_text_field( wp_unslash( $_POST['email'] ) ) : '';

                // Archive limits.
                foreach ( $archive_types as $archive_type => $archive_type_name ) {
                    foreach ( $archive_periods as $archive_period ) {
                        $settings_name = 'limit_' . $archive_type . '_' . $archive_period;
                        \pb_backupbuddy::$options['remote_destinations'][ $nextDestKey ][ $settings_name ] = isset( $_POST['live_settings'][ $settings_name ] ) ? wp_unslash( $_POST['live_settings'][ $settings_name ] ) : '';
                    }
                }

                if ( '1' === $_POST['send_snapshot_notification'] ) {
                    \pb_backupbuddy::$options['remote_destinations'][ $nextDestKey ]['send_snapshot_notification'] = isset( $_POST['send_snapshot_notification'] ) ? wp_unslash( $_POST['send_snapshot_notification'] ) : '';
                } else {
                    \pb_backupbuddy::$options['remote_destinations'][ $nextDestKey ]['send_snapshot_notification'] = '0';
                }

                \pb_backupbuddy::save();
                $destination_id = $nextDestKey;

                // Send new settings for archive limiting to Stash API.
                \backupbuddy_live::send_trim_settings();

                // Set first run of BackupBuddy Stash Live so it begins immediately.
                $cronArgs        = array();
                $schedule_result = \backupbuddy_core::schedule_single_event( time(), 'live_periodic', $cronArgs );
                if ( true === $schedule_result ) {
                    \pb_backupbuddy::status( 'details', 'Next Live Periodic chunk step cron event scheduled.' );
                } else {
                    \pb_backupbuddy::status( 'error', 'Next Live Periodic chunk step cron event FAILED to be scheduled.' );
                }
                if ( '1' !== \pb_backupbuddy::$options['skip_spawn_cron_call'] ) {
                    \pb_backupbuddy::status( 'details', 'Spawning cron now.' );
                    update_option( '_transient_doing_cron', 0 ); // Prevent cron-blocking for next item.
                    spawn_cron( time() + 150 ); // Adds > 60 seconds to get around once per minute cron running limit.
                }
            }
        } // end if user and pass set.

        if ( empty( $errors ) ) {
            \pb_backupbuddy::save();
            $data = \pb_backupbuddy::$options['remote_destinations'][ $destination_id ];
            return array(
                'destination_id' => $destination_id,
                'data'           => $data,
            );
        } else {
            return array( 'errors' => $errors );
        }
    }

    /**
     * Live save settings.
     *
     * @return int[]|string[] Return 1 on success or ERROR message on failure.
     *
     * @uses \pb_backupbuddy::plugin_path()
     * @uses \pb_backupbuddy::$options
     * @uses \pb_backupbuddy::save()
     * @uses \backupbuddy_live::send_trim_settings()
     * @uses \backupbuddy_live::getLiveID()
     * @uses \backupbuddy_live::send_trim_settings()
     */
    public function live_save_settings() {
        $data               = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : array();
        $new_destination_id = isset( $_POST['destination_id'] ) ? sanitize_text_field( wp_unslash( $_POST['destination_id'] ) ) : '';

        require_once \pb_backupbuddy::plugin_path() . '/destinations/bootstrap.php'; // NOSONAR - WP compatible.
        require_once \pb_backupbuddy::plugin_path() . '/destinations/live/live.php'; // NOSONAR - WP compatible.
        require_once \pb_backupbuddy::plugin_path() . '/destinations/live/live_periodic.php'; // NOSONAR - WP compatible.

        $destination_id       = \backupbuddy_live::getLiveID();
        $destination_settings = isset( \pb_backupbuddy::$options['remote_destinations'][ $destination_id ] ) ? \pb_backupbuddy::$options['remote_destinations'][ $destination_id ] : array();

        $check_current = ! empty( $destination_settings ) ? true : false;

        $error = '';
        if ( $new_destination_id && is_array( $data ) ) {
            $itxapi_username                         = isset( $destination_settings['itxapi_username'] ) ? $destination_settings['itxapi_username'] : '';
            $itxapi_token                            = isset( $destination_settings['itxapi_token'] ) ? $destination_settings['itxapi_token'] : '';
            $destination_settings                    = array_merge( $destination_settings, $data );
            $destination_settings['itxapi_username'] = $itxapi_username;
            $destination_settings['itxapi_token']    = $itxapi_token;
            \pb_backupbuddy::$options['remote_destinations'][ $new_destination_id ] = $destination_settings;

            if ( $check_current && $destination_id !== $new_destination_id ) {
                unset( \pb_backupbuddy::$options['remote_destinations'][ $destination_id ] );
            }

            \pb_backupbuddy::save();
            set_transient( 'backupbuddy_live_jump', array( 'daily_init', array() ), 60 * 60 * 48 ); // Tells Live process to restart from the beginning (if mid-process) so new settigns apply.

            \backupbuddy_live::send_trim_settings();
            return array( 'ok' => 1 );
        } else {
            $error = 'Invalid data. Please check and try again.';
        }
        return array( 'error' => $error );
    }

    /**
     * Live action disconnect.
     *
     * @return array $return Return 1 on success or ERROR message on failure.
     *
     * @uses \pb_backupbuddy::$options
     * @uses \pb_backupbuddy::save()
     * @uses \pb_backupbuddy::plugin_path()
     */
    public function live_action_disconnect() {
        $error             = '';
        $liveDestinationID = isset( $_POST['destination_id'] ) ? sanitize_text_field( wp_unslash( $_POST['destination_id'] ) ) : '';

        $return = array();
        if ( $liveDestinationID ) {
            if ( isset( \pb_backupbuddy::$options['remote_destinations'][ $liveDestinationID ] ) ) {
                // Clear destination settings.
                unset( \pb_backupbuddy::$options['remote_destinations'][ $liveDestinationID ] );
                \pb_backupbuddy::save();
                // Clear cached Live credentials.
                require_once \pb_backupbuddy::plugin_path() . '/destinations/live/init.php'; // NOSONAR - WP compatible.
                delete_transient( \pb_backupbuddy_destination_live::LIVE_ACTION_TRANSIENT_NAME );
            } else {
                $error = 'Error: destination not found.';
            }
            $return['ok'] = 1;
        } else {
            $error = 'Error: Empty destination id.';
        }

        if ( ! empty( $error ) ) {
            $return['error'] = $error;
        }

        return $return;
    }

    /**
     * Live action.
     *
     * @return array Return results array.
     *
     * @uses \pb_backupbuddy::plugin_path()
     * @uses \pb_backupbuddy::$options
     * @uses \pb_backupbuddy::_GET()
     * @uses \backupbuddy_live_periodic::get_stats()
     * @uses \backupbuddy_live::getLiveID()
     * @uses \backupbuddy_live_periodic::get_destination_settings()
     * @uses \backupbuddy_core::getLogDirectory()
     * @uses \backupbuddy_api::runLiveSnapshot()
     * @uses \backupbuddy_api::setLiveStatus()
     */
    public function live_action() {
        $action  = isset( $_POST['live_action'] ) ? sanitize_text_field( wp_unslash( $_POST['live_action'] ) ) : '';
        $error   = '';
        $message = '';

        require_once \pb_backupbuddy::plugin_path() . '/destinations/live/live_periodic.php'; // NOSONAR - WP compatible.
        \backupbuddy_live_periodic::get_stats();

        if ( 'clear_log' === $action ) {
            $sumLogFile = \backupbuddy_core::getLogDirectory() . 'status-live_periodic_' . \pb_backupbuddy::$options['log_serial'] . '.txt';
            wp_delete_file( $sumLogFile );
            if ( file_exists( $sumLogFile ) ) {
                $error = 'Error #893489322: Unable to clear log file `' . $sumLogFile . '`. Check permissions or manually delete.';
            } else {
                $message = 'Log file cleared.';
            }
        } elseif ( 'create_snapshot' === $action ) { // < 100% backed up _OR_ ( we are on a step other than daily_init and the last_activity is more recent than the php runtime ).
            if ( true === \backupbuddy_api::runLiveSnapshot() ) {
                $message = '<h3>' . esc_html__( 'Verifying everything is up to date before Snapshot', 'mainwp-child' ) . '</h3><p class="description" style="max-width: 700px; display: inline-block;">' . esc_html__( 'Please wait while we verify your backup is completely up to date before we create the Snapshot. This may take a few minutes...', 'mainwp-child' ) . '</p>';
                require_once \pb_backupbuddy::plugin_path() . '/destinations/live/_manual_snapshot.php'; // NOSONAR - WP compatible.
            }
        } elseif ( 'pause_periodic' === $action ) {
            $pause_continuous = '';
            $pause_periodic   = true;
            \backupbuddy_api::setLiveStatus( $pause_continuous, $pause_periodic );
            $message     = esc_html__( 'Live File Backup paused. It may take a moment for current processes to finish.', 'mainwp-child' );
            include_once \pb_backupbuddy::plugin_path() . '/destinations/live/_stats.php'; // NOSONAR -- WP compatible.
        } elseif ( 'resume_periodic' === $action ) {
            $launchNowText = ' ' . esc_html__( 'Unpaused but not running now.', 'mainwp-child' );
            $start_run     = false;
            if ( '1' !== \pb_backupbuddy::_GET( 'skip_run_live_now' ) ) {
                $launchNowText = '';
                $start_run     = true;
            }
            $pause_continuous = '';
            $pause_periodic   = false;
            \backupbuddy_api::setLiveStatus( $pause_continuous, $pause_periodic, $start_run );
            $message = esc_html__( 'Live File Backup has resumed.', 'mainwp-child' ) . $launchNowText;
            include_once \pb_backupbuddy::plugin_path() . '/destinations/live/_stats.php'; // NOSONAR -- WP compatible.
        } elseif ( 'pause_continuous' === $action ) {
            $pause_continuous = true;
            $pause_periodic   = '';
            \backupbuddy_api::setLiveStatus( $pause_continuous, $pause_periodic );
            include_once \pb_backupbuddy::plugin_path() . '/destinations/live/_stats.php'; // NOSONAR -- WP compatible. Recalculate stats.
            $message = esc_html__( 'Live Database Backup paused.', 'mainwp-child' );
        } elseif ( 'resume_continuous' === $action ) {
            $pause_continuous = false;
            $pause_periodic   = '';
            \backupbuddy_api::setLiveStatus( $pause_continuous, $pause_periodic );
            include_once \pb_backupbuddy::plugin_path() . '/destinations/live/_stats.php'; // NOSONAR -- WP compatible. Recalculate stats.
            $message = esc_html__( 'Live Database Backup resumed.', 'mainwp-child' );
        } else {
            $error = 'Error #1000. Invalid request.';
        }

        return array(
            'ok'       => 1,
            '_error'   => $error,
            '_message' => $message,
        );
    }


    /**
     * Download troubleshooting.
     *
     * @return array Return results array.
     *
     * @uses \pb_backupbuddy::plugin_path()
     * @uses \backupbuddy_live_troubleshooting::run()
     * @uses \backupbuddy_live_troubleshooting::get_raw_results()
     * @uses \backupbuddy_core::backup_prefix()
     */
    public function download_troubleshooting() {
        require_once \pb_backupbuddy::plugin_path() . '/destinations/live/_troubleshooting.php'; // NOSONAR - WP compatible.
        \backupbuddy_live_troubleshooting::run();
        $output        = "**File best viewed with wordwrap OFF**\n\n" . print_r( backupbuddy_live_troubleshooting::get_raw_results(), true ); // phpcs:ignore -- debug feature.
        $backup_prefix = \backupbuddy_core::backup_prefix();
        return array(
            'output'        => $output,
            'backup_prefix' => $backup_prefix,
        );
    }

    /**
     * Get live backups.
     *
     * @return array[]|string[] Return backup list.
     *
     * @uses \pb_backupbuddy::plugin_path()
     * @uses \pb_backupbuddy::$options
     * @uses \pb_backupbuddy_destination_stash2::_formatSettings()
     * @uses \pb_backupbuddy_destination_stash2::listFiles()
     * @uses \pb_backupbuddy::$format::date()
     * @uses \pb_backupbuddy::$format::localize_time()
     * @uses \pb_backupbuddy::$format::time_ago()
     * @uses \pb_backupbuddy::$format::file_size()
     * @uses \backupbuddy_core::startsWith()
     * @uses \backupbuddy_core::getBackupTypeFromFile()
     * @uses \backupbuddy_core::pretty_backup_type()
     * @uses \backupbuddy_core::pretty_backup_type()
     */
    public function get_live_backups() {
        $destination_id = isset( $_POST['destination_id'] ) ? sanitize_text_field( wp_unslash( $_POST['destination_id'] ) ) : '';
        // Load required files.
        require_once \pb_backupbuddy::plugin_path() . '/destinations/s32/init.php'; // NOSONAR - WP compatible.

        if ( ! isset( \pb_backupbuddy::$options['remote_destinations'][ $destination_id ] ) ) {
            return array( 'error' => 'Error #9828332: Destination not found with id `' . htmlentities( $destination_id ) . '`.' );
        }
        require_once \pb_backupbuddy::plugin_path() . '/destinations/stash2/init.php'; // NOSONAR - WP compatible.
        $settings = &\pb_backupbuddy::$options['remote_destinations'][ $destination_id ];
        $settings = \pb_backupbuddy_destination_stash2::_formatSettings( $settings );

        $destination = \pb_backupbuddy::$options['remote_destinations'][ $destination_id ];

        if ( 'live' === $destination['type'] ) {
            $remotePath = 'snapshot-';
            $site_only  = true;
        } else {
            // Get list of files for this site.
            $remotePath = 'backup-';
            $site_only  = true;
        }

        $files = \pb_backupbuddy_destination_stash2::listFiles( $settings, '', $site_only ); // 2nd param was $remotePath.
        if ( ! is_array( $files ) ) {
            return array( 'error' => 'Error #892329c: ' . $files );
        }

        $backup_list_temp = array();
        foreach ( (array) $files as $file ) {

            if ( ( '' !== $remotePath ) && ( ! \backupbuddy_core::startsWith( basename( $file['filename'] ), $remotePath ) ) ) { // Only show backups for this site unless set to show all.
                continue;
            }

            $last_modified = $file['uploaded_timestamp'];
            $size          = (float) $file['size'];
            $backup_type   = \backupbuddy_core::getBackupTypeFromFile( $file['filename'], false, true );

            // Generate array of table rows.
            while ( isset( $backup_list_temp[ $last_modified ] ) ) { // Avoid collisions.
                $last_modified += 0.1;
            }

            if ( 'live' === $destination['type'] ) {
                $backup_list_temp[ $last_modified ] = array(
                    array( base64_encode( $file['url'] ), '<span class="backupbuddy-stash-file-list-title">' . \pb_backupbuddy::$format->date( \pb_backupbuddy::$format->localize_time( $last_modified ) ) . ' <span class="description">(' . \pb_backupbuddy::$format->time_ago( $last_modified ) . ' ago)</span></span><br><span title="' . $file['filename'] . '">' . basename( $file['filename'] ) . '</span>' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
                    \pb_backupbuddy::$format->date( \pb_backupbuddy::$format->localize_time( $last_modified ) ) . '<br /><span class="description">(' . \pb_backupbuddy::$format->time_ago( $last_modified ) . ' ago)</span>',
                    \pb_backupbuddy::$format->file_size( $size ),
                    \backupbuddy_core::pretty_backup_type( $backup_type ),
                );
            } else {
                $backup_list_temp[ $last_modified ] = array(
                    array( base64_encode( $file['url'] ), '<span title="' . $file['filename'] . '">' . basename( $file['filename'] ) . '</span>' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- to compatible http encoding.
                    \pb_backupbuddy::$format->date( \pb_backupbuddy::$format->localize_time( $last_modified ) ) . '<br /><span class="description">(' . \pb_backupbuddy::$format->time_ago( $last_modified ) . ' ago)</span>',
                    \pb_backupbuddy::$format->file_size( $size ),
                    \backupbuddy_core::pretty_backup_type( $backup_type ),
                );
            }
        }

        krsort( $backup_list_temp );
        $backup_list = array();
        foreach ( $backup_list_temp as $backup_item ) {
            $backup_list[ $backup_item[0][0] ] = $backup_item;
        }
        unset( $backup_list_temp );

        return array( 'backup_list' => $backup_list );
    }

    /**
     * Copy file to local.
     *
     * @return int[]|string[] Return 1 on success and ERROR message on failure.
     *
     * @uses \pb_backupbuddy::plugin_path()
     * @uses \pb_backupbuddy::$options
     * @uses \pb_backupbuddy::status()
     * @uses \pb_backupbuddy_destination_stash2::_formatSettings()
     * @uses \backupbuddy_core::schedule_single_event()
     *
     */
    public function copy_file_to_local() {

        $file           = isset( $_POST['cpy_file'] ) ? base64_decode( wp_unslash( $_POST['cpy_file'] ) ) : ''; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
        $destination_id = isset( $_POST['destination_id'] ) ? sanitize_text_field( wp_unslash( $_POST['destination_id'] ) ) : '';

        // Load required files.
        require_once \pb_backupbuddy::plugin_path() . '/destinations/s32/init.php'; // NOSONAR - WP compatible.
        if ( ! isset( \pb_backupbuddy::$options['remote_destinations'][ $destination_id ] ) ) {
            return array( 'error' => 'Error #9828332: Destination not found with id `' . htmlentities( $destination_id ) . '`.' );
        }

        $settings = &\pb_backupbuddy::$options['remote_destinations'][ $destination_id ];
        $settings = \pb_backupbuddy_destination_stash2::_formatSettings( $settings );

        \pb_backupbuddy::status( 'details', 'Scheduling Cron for creating Stash copy.' );
        \backupbuddy_core::schedule_single_event( time(), 'process_remote_copy', array( 'stash2', $file, $settings ) );
        if ( '1' !== \pb_backupbuddy::$options['skip_spawn_cron_call'] ) {
            update_option( '_transient_doing_cron', 0 ); // Prevent cron-blocking for next item.
            spawn_cron( time() + 150 ); // Adds > 60 seconds to get around once per minute cron running limit.
        }
        return array( 'ok' => 1 );
    }

    /**
     * Delete backup file.
     *
     * @return int[]|string[] Return 1 on success and ERROR message on failure.
     *
     * @uses \pb_backupbuddy::plugin_path()
     * @uses \pb_backupbuddy::$options
     * @uses \pb_backupbuddy_destination_stash2::_formatSettings()
     * @uses \pb_backupbuddy_destination_stash2::strrpos_count()
     * @uses \pb_backupbuddy_destination_stash2::deleteFiles()
     */
    public function delete_file_backup() {
        // Handle deletion.
        $files          = isset( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : array();
        $destination_id = isset( $_POST['destination_id'] ) ? sanitize_text_field( wp_unslash( $_POST['destination_id'] ) ) : '';

        // Load required files.
        require_once \pb_backupbuddy::plugin_path() . '/destinations/s32/init.php'; // NOSONAR - WP compatible.
        if ( ! isset( \pb_backupbuddy::$options['remote_destinations'][ $destination_id ] ) ) {
            return array( 'error' => 'Error #9828332: Destination not found with id `' . htmlentities( $destination_id ) . '`.' );
        }

        $settings = &\pb_backupbuddy::$options['remote_destinations'][ $destination_id ];
        $settings = \pb_backupbuddy_destination_stash2::_formatSettings( $settings );

        $deleteFiles = array();
        foreach ( (array) $files as $file ) {
            $file = base64_decode( $file ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..

            $startPos = \pb_backupbuddy_destination_stash2::strrpos_count( $file, '/', 2 ) + 1; // next to last slash.
            $file     = substr( $file, $startPos );
            if ( false !== strstr( $file, '?' ) ) {
                $file = substr( $file, 0, strpos( $file, '?' ) );
            }
            $deleteFiles[] = $file;
        }
        $response = \pb_backupbuddy_destination_stash2::deleteFiles( $settings, $deleteFiles );

        if ( true === $response ) {
            $msg = 'Deleted ' . implode( ', ', $deleteFiles ) . '.';
        } else {
            $msg = 'Failed to delete one or more files. Details: `' . $response . '`.';
        }

        return array(
            'ok'  => 1,
            'msg' => $msg,
        );
    }

    /**
     * Get live stats.
     *
     * @return array|string[] Return json encoded results or ERROR message on failure.
     *
     * @uses \backupbuddy_api::getLiveStats()
     * @uses \backupbuddy_core::detectLikelyHighestExecutionTime()
     * @uses \backupbuddy_core::schedule_single_event()
     * @uses \backupbuddy_live::getLiveID();
     * @uses \pb_backupbuddy::plugin_path()
     * @uses \pb_backupbuddy::$options
     * @uses \pb_backupbuddy::status()
     */
    public function get_live_stats() { // NOSONAR - WP compatible.

        // Check if running PHP 5.3+.
        $php_minimum = 5.3;
        if ( version_compare( PHP_VERSION, $php_minimum, '<' ) ) { // Server's PHP is insufficient.
            return array( 'error' => '-1' );
        }

        $stats = \backupbuddy_api::getLiveStats();

        if ( false === $stats ) { // Live is disconnected.
            return array( 'error' => '-1' );
        }

        // If there is more to do and too long of time has passed since activity then try to jumpstart the process at the beginning.
        if ( ( ( 0 === (int) $stats['files_total'] ) || ( $stats['files_sent'] < $stats['files_total'] ) ) && ( 'wait_on_transfers' !== $stats['current_function'] ) ) { // ( Files to send not yet calculated OR more remain to send ) AND not on the wait_on_transfers step.
            $time_since_last_activity = microtime( true ) - $stats['last_periodic_activity'];

            if ( $time_since_last_activity >= 30 ) { // More than 30 seconds since last activity.

                // Detect max PHP execution time. If TESTED value is higher than PHP value then go with that since we want to err on not overlapping processes here.
                $detected_execution = \backupbuddy_core::detectLikelyHighestExecutionTime();

                if ( $time_since_last_activity > ( $detected_execution + \backupbuddy_constants::TIMED_OUT_PROCESS_RESUME_WIGGLE_ROOM ) ) { // Enough time has passed to assume timed out.

                    require_once \pb_backupbuddy::plugin_path() . '/destinations/live/live.php'; // NOSONAR - WP compatible.
                    $liveID = \backupbuddy_live::getLiveID();
                    if ( false === $liveID ) {
                        die( '-1' );
                    }
                    if ( '1' !== \pb_backupbuddy::$options['remote_destinations'][ $liveID ]['pause_periodic'] ) { // Only proceed if NOT paused.

                        \pb_backupbuddy::status( 'warning', 'BackupBuddy Stash Live process appears timed out while user it viewing Live page. Forcing run now.' );

                        $cronArgs        = array();
                        $schedule_result = \backupbuddy_core::schedule_single_event( time(), 'live_periodic', $cronArgs );
                        if ( true === $schedule_result ) {
                            \pb_backupbuddy::status( 'details', 'Next Live Periodic chunk step cron event scheduled.' );
                        } else {
                            \pb_backupbuddy::status( 'error', 'Next Live Periodic chunk step cron event FAILED to be scheduled.' );
                        }
                        if ( '1' !== \pb_backupbuddy::$options['skip_spawn_cron_call'] ) {
                            \pb_backupbuddy::status( 'details', 'Spawning cron now.' );
                            update_option( '_transient_doing_cron', 0 ); // Prevent cron-blocking for next item.
                            spawn_cron( time() + 150 ); // Adds > 60 seconds to get around once per minute cron running limit.
                        }
                    }
                }
            }
        }

        return array( 'result' => wp_json_encode( $stats ) );
    }

    /**
     * Save license settings.
     *
     * @return bool|int[] Return 1 on success and FALSE on failure.
     */
    public function save_license_settings() {
        $settings = isset( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : false;
        if ( is_array( $settings ) && isset( $GLOBALS['ithemes-updater-settings'] ) ) {
            $GLOBALS['ithemes-updater-settings']->update_options( $settings );
            return array( 'ok' => 1 );
        }
        return false;
    }

    /**
     * Load product license.
     *
     * @return array Return results array.
     *
     * @uses Ithemes_Updater_Packages::get_full_details()
     * @uses Ithemes_Updater_Functions::get_package_name()
     */
    public function load_products_license() {
        $packages      = array();
        $packages_name = array();
        if ( isset( $GLOBALS['ithemes_updater_path'] ) ) {

            require_once $GLOBALS['ithemes_updater_path'] . '/functions.php'; // NOSONAR - WP compatible.
            require_once $GLOBALS['ithemes_updater_path'] . '/api.php'; // NOSONAR - WP compatible.
            require_once $GLOBALS['ithemes_updater_path'] . '/keys.php'; // NOSONAR - WP compatible.

            require_once $GLOBALS['ithemes_updater_path'] . '/packages.php'; // NOSONAR - WP compatible.

            $details  = \Ithemes_Updater_Packages::get_full_details();
            $packages = isset( $details['packages'] ) ? $details['packages'] : array();
            if ( is_array( $packages ) ) {
                foreach ( $packages as $path => $data ) {
                    $packages_name[ $path ] = \Ithemes_Updater_Functions::get_package_name( $data['package'] );
                }
            }
        }
        return array(
            'ok'            => 1,
            'packages'      => $packages,
            'packages_name' => $packages_name,
        );
    }

    /**
     * Activate package.
     *
     * @return array $return Return response array.
     *
     * @uses Ithemes_Updater_API::activate_package()
     * @uses Ithemes_Updater_Functions::get_package_name()
     * @uses MainWP_Child_Back_Up_Buddy::get_error_explanation()
     */
    public function activate_package() { // NOSONAR - WP compatible.

        $username = isset( $_POST['username'] ) ? sanitize_text_field( wp_unslash( $_POST['username'] ) ) : '';
        $password = isset( $_POST['password'] ) ? wp_unslash( $_POST['password'] ) : '';
        $packages = isset( $_POST['packages'] ) ? wp_unslash( $_POST['packages'] ) : '';

        $return = array( 'ok' => 1 );
        if ( isset( $GLOBALS['ithemes_updater_path'] ) ) {

            require_once $GLOBALS['ithemes_updater_path'] . '/functions.php'; // NOSONAR - WP compatible.
            require_once $GLOBALS['ithemes_updater_path'] . '/api.php'; // NOSONAR - WP compatible.
            require_once $GLOBALS['ithemes_updater_path'] . '/keys.php'; // NOSONAR - WP compatible.

            require_once $GLOBALS['ithemes_updater_path'] . '/packages.php'; // NOSONAR - WP compatible.

            $response = \Ithemes_Updater_API::activate_package( $username, $password, $packages );

            if ( is_wp_error( $response ) ) {
                $errors[]         = $this->get_error_explanation( $response );
                $return['errors'] = $errors;
                return $return;
            }

            if ( empty( $response['packages'] ) ) {
                $errors[]         = esc_html__( 'An unknown server error occurred. Please try to license your products again at another time.', 'mainwp-child' );
                $return['errors'] = $errors;
                return $return;
            }

            uksort( $response['packages'], 'strnatcasecmp' );

            $success = array();
            $warn    = array();
            $fail    = array();

            foreach ( $response['packages'] as $package => $data ) {
                if ( preg_match( '/ \|\|\| \d+$/', $package ) ) {
                    continue;
                }

                $name = \Ithemes_Updater_Functions::get_package_name( $package );

                if ( ! empty( $data['key'] ) ) {
                    $success[] = $name;
                } elseif ( ! empty( $data['status'] ) && ( 'expired' === $data['status'] ) ) {
                    $warn[ $name ] = esc_html__( 'Your product subscription has expired', 'mainwp-child' );
                } else {
                    $fail[ $name ] = $data['error']['message'];
                }
            }

            if ( ! empty( $success ) ) {
                $messages[]         = wp_sprintf( esc_html__( 'Successfully licensed %l.', 'mainwp-child' ), $success );
                $return['messages'] = $messages;
            }

            if ( ! empty( $fail ) ) {
                foreach ( $fail as $name => $reason ) {
                    $errors[] = sprintf( esc_html__( 'Unable to license %1$s. Reason: %2$s', 'mainwp-child' ), $name, $reason );
                }
                $return['errors'] = $errors;
            }

            if ( ! empty( $warn ) ) {
                foreach ( $warn as $name => $reason ) {
                    $soft_errors[] = sprintf( esc_html__( 'Unable to license %1$s. Reason: %2$s', 'mainwp-child' ), $name, $reason );
                }
                $return['soft_errors'] = $soft_errors;
            }
        }
        return $return;
    }

    /**
     * Deactivate package.
     *
     * @param array $data Data array.
     *
     * @return array return$ Return response array.
     *
     * @uses Ithemes_Updater_API::deactivate_package()
     * @uses Ithemes_Updater_Functions::get_package_name()
     * @uses MainWP_Child_Back_Up_Buddy::get_error_explanation()
     */
    public function deactivate_package( $data ) { // NOSONAR - WP compatible.

        $username = isset( $_POST['username'] ) ? sanitize_text_field( wp_unslash( $_POST['username'] ) ) : '';
        $password = isset( $_POST['password'] ) ? wp_unslash( $_POST['password'] ) : '';
        $packages = isset( $_POST['packages'] ) ? wp_unslash( $_POST['packages'] ) : '';

        $return = array( 'ok' => 1 );

        if ( isset( $GLOBALS['ithemes_updater_path'] ) ) {

            require_once $GLOBALS['ithemes_updater_path'] . '/functions.php'; // NOSONAR - WP compatible.
            require_once $GLOBALS['ithemes_updater_path'] . '/api.php'; // NOSONAR - WP compatible.
            require_once $GLOBALS['ithemes_updater_path'] . '/keys.php'; // NOSONAR - WP compatible.

            require_once $GLOBALS['ithemes_updater_path'] . '/packages.php'; // NOSONAR - WP compatible.

            $response = \Ithemes_Updater_API::deactivate_package( $username, $password, $packages );

            if ( is_wp_error( $response ) ) {
                $errors[]         = $this->get_error_explanation( $response );
                $return['errors'] = $errors;
                return $return;
            }

            if ( empty( $response['packages'] ) ) {
                $errors[]         = esc_html__( 'An unknown server error occurred. Please try to remove licenses from your products again at another time.', 'it-l10n-mainwp-backupbuddy' );
                $return['errors'] = $errors;
                return $return;
            }

            uksort( $response['packages'], 'strnatcasecmp' );

            $success = array();
            $fail    = array();

            foreach ( $response['packages'] as $package => $data ) {
                if ( preg_match( '/ \|\|\| \d+$/', $package ) ) {
                    continue;
                }

                $name = \Ithemes_Updater_Functions::get_package_name( $package );

                if ( isset( $data['status'] ) && ( 'inactive' === $data['status'] ) ) {
                    $success[] = $name;
                } elseif ( isset( $data['error'] ) && isset( $data['error']['message'] ) ) {
                    $fail[ $name ] = $data['error']['message'];
                } else {
                    $fail[ $name ] = esc_html__( 'Unknown server error.', 'it-l10n-mainwp-backupbuddy' );
                }
            }

            if ( ! empty( $success ) ) {
                $messages[]         = wp_sprintf( _n( 'Successfully removed license from %l.', 'Successfully removed licenses from %l.', count( $success ), 'it-l10n-mainwp-backupbuddy' ), $success );
                $return['messages'] = $messages;
            }

            if ( ! empty( $fail ) ) {
                foreach ( $fail as $name => $reason ) {
                    $errors[] = sprintf( esc_html__( 'Unable to remove license from %1$s. Reason: %2$s', 'it-l10n-mainwp-backupbuddy' ), $name, $reason );
                }
                $return['errors'] = $errors;

            }
        }
        return $return;
    }

    /**
     * Get error code.
     *
     * @param $error Returned error to get code for: Bad_Login, Username_Unknown, Username_Invalid, Package_Unknown,
     *  Too_Many_Sites, Generate_Failed.
     * @param string $package Ithemes package.
     *
     * @return string $message Return ERROR message to display.
     *
     * @uses Ithemes_Updater_Functions::get_package_name()
     */
    private function get_error_explanation( $error, $package = '' ) {
        $code         = $error->get_error_code();
        $package_name = \Ithemes_Updater_Functions::get_package_name( $package );
        $message      = '';

        switch ( $code ) {
            case 'ITXAPI_Updater_Bad_Login':
                $message = esc_html__( 'Incorrect password. Please make sure that you are supplying your iThemes membership username and password details.', 'it-l10n-mainwp-backupbuddy' );
                break;
            case 'ITXAPI_Updater_Username_Unknown':
            case 'ITXAPI_Updater_Username_Invalid':
                $message = esc_html__( 'Invalid username. Please make sure that you are supplying your iThemes membership username and password details.', 'it-l10n-mainwp-backupbuddy' );
                break;
            case 'ITXAPI_Product_Package_Unknown':
                $message = sprintf( esc_html__( 'The licensing server reports that the %1$s (%2$s) product is unknown. Please contact support for assistance.', 'it-l10n-mainwp-backupbuddy' ), $package_name, $package );
                break;
            case 'ITXAPI_Updater_Too_Many_Sites':
                $message = sprintf( esc_html__( '%1$s could not be licensed since the membership account is out of available licenses for this product. You can unlicense the product on other sites or upgrade your membership to one with a higher number of licenses in order to increase the amount of available licenses.', 'it-l10n-mainwp-backupbuddy' ), $package_name );
                break;
            case 'ITXAPI_License_Key_Generate_Failed':
                $message = sprintf( esc_html__( '%1$s could not be licensed due to an internal error. Please try to license %2$s again at a later time. If this problem continues, please contact iThemes support.', 'it-l10n-mainwp-backupbuddy' ), $package_name );
                break;
            default:
                break;
        }

        if ( empty( $message ) ) {
            if ( ! empty( $package ) ) {
                $message = sprintf( esc_html__( 'An unknown error relating to the %1$s product occurred. Please contact iThemes support. Error details: %2$s', 'it-l10n-mainwp-backupbuddy' ), $package_name, $error->get_error_message() . " ($code)" );
            } else {
                $message = sprintf( esc_html__( 'An unknown error occurred. Please contact iThemes support. Error details: %s', 'it-l10n-mainwp-backupbuddy' ), $error->get_error_message() . " ($code)" );
            }
        }

        return $message;
    }



}
