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

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

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
     * Whether the MainWP response has already been written.
     *
     * @var bool
     */
    private static $response_written = false;

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
                add_action( 'backwpup_end_job', array( $this, 'log_successful_backup' ), 10, 3 );
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
     * Scan the completed BackWPup log after every destination has finished.
     *
     * @param array        $job         BackWPup job data.
     * @param string       $backup_file Backup file name.
     * @param BackWPup_Job $backwpup_job BackWPup job instance.
     * @return void
     */
    public function log_successful_backup( $job = array(), $backup_file = '', $backwpup_job = null ) {
        unset( $job, $backup_file, $backwpup_job );
        $this->do_site_stats();
    }

    /**
     * MainWP BackWPup fatal error handler.
     *
     * @uses \MainWP\Child\MainWP_Child_Back_WP_Up::$information
     * @uses \MainWP\Child\MainWP_Helper::write()
     */
    public static function mainwp_backwpup_handle_fatal_error() {
        if ( self::$response_written ) {
            return;
        }

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

        $buffer_level = ob_get_level();
        ob_start();

        $information = array();
        $action      = ! empty( $_POST['action'] ) ? sanitize_text_field( wp_unslash( $_POST['action'] ) ) : '';  // phpcs:ignore -- NOSONAR
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        if ( ! empty( $_POST['referer_name'] ) ) {
            $_REQUEST['_wpnonce'] = wp_create_nonce( sanitize_text_field( wp_unslash( $_POST['referer_name'] ) ) );
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing
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
                case 'abilities_v2':
                    $request     = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The closed typed operation validates every value before use.
                    $information = $this->abilities_v2( $request );
                    break;
                default:
                    $information = array( 'error' => esc_html__( 'Wrong action.', 'mainwp-child' ) );
            }
        }

        if ( ob_get_level() > $buffer_level ) {
            ob_end_clean();
        }

        static::$information = $information;

        self::$response_written = true;
        MainWP_Helper::write( $information );
    }

    /**
     * Handle the additive typed BackWPup protocol.
     *
     * @param array $request Typed request.
     * @return array
     */
    protected function abilities_v2( $request ) {
        $operation = is_array( $request ) && isset( $request['operation'] ) && is_string( $request['operation'] ) ? $request['operation'] : '';
        $payload   = null;
        if ( is_array( $request ) && $this->abilities_v2_has_keys( $request, array( 'operation', 'payload' ) ) && isset( $request['payload'] ) && is_array( $request['payload'] ) ) {
            $payload = $request['payload'];
        } elseif ( is_array( $request ) && $this->abilities_v2_has_keys( $request, array( 'operation', 'payload_json' ) ) && isset( $request['payload_json'] ) && is_string( $request['payload_json'] ) && 65536 >= strlen( $request['payload_json'] ) && '{' === substr( $request['payload_json'], 0, 1 ) ) {
            $decoded = json_decode( $request['payload_json'], true );
            $payload = JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ? $decoded : null;
        }
        if ( null === $payload || 1 !== preg_match( '/^[a-z_]{1,64}$/D', $operation ) ) {
            return $this->abilities_v2_error( $operation, 'invalid_request', __( 'The typed BackWPup request is invalid.', 'mainwp-child' ) );
        }

        if ( 'get_job_schedule' === $operation ) {
            return $this->abilities_v2_get_job_schedule( $operation, $payload );
        }
        if ( 'update_job_schedule' === $operation ) {
            return $this->abilities_v2_update_job_schedule( $operation, $payload );
        }
        if ( 'delete_job' === $operation ) {
            return $this->abilities_v2_delete_job( $operation, $payload );
        }
        if ( 'start_backup' === $operation ) {
            return $this->abilities_v2_start_backup( $operation, $payload );
        }
        if ( 'backup_progress' === $operation ) {
            return $this->abilities_v2_backup_progress( $operation, $payload );
        }
        if ( 'abort_backup' === $operation ) {
            return $this->abilities_v2_abort_backup( $operation, $payload );
        }
        if ( 'list_backups' === $operation ) {
            return $this->abilities_v2_list_backups( $operation, $payload );
        }
        if ( 'redeem_backup_download' === $operation ) {
            return $this->abilities_v2_redeem_backup_download( $operation, $payload );
        }
        if ( 'delete_backup' === $operation ) {
            return $this->abilities_v2_delete_backup( $operation, $payload );
        }
        if ( 'list_logs' === $operation ) {
            return $this->abilities_v2_list_logs( $operation, $payload );
        }
        if ( 'read_log_excerpt' === $operation ) {
            return $this->abilities_v2_read_log_excerpt( $operation, $payload );
        }
        if ( 'delete_log' === $operation ) {
            return $this->abilities_v2_delete_log( $operation, $payload );
        }
        if ( 'diagnostics' === $operation ) {
            return $this->abilities_v2_diagnostics( $operation, $payload );
        }
        if ( 'set_visibility' === $operation ) {
            return $this->abilities_v2_set_visibility( $operation, $payload );
        }

        return $this->abilities_v2_error( $operation, 'unsupported_operation', __( 'The typed BackWPup operation is not supported.', 'mainwp-child' ) );
    }

    /**
     * Set and verify the managed BackWPup menu visibility.
     *
     * @param string $operation Operation name.
     * @param array  $payload   Typed payload.
     * @return array
     */
    private function abilities_v2_set_visibility( $operation, $payload ) {
        if ( ! $this->abilities_v2_has_keys( $payload, array( 'hidden' ) ) || ! is_bool( $payload['hidden'] ) ) {
            return $this->abilities_v2_error( $operation, 'invalid_input', __( 'The visibility request is invalid.', 'mainwp-child' ) );
        }

        $desired = $payload['hidden'] ? 'hide' : '';
        $current = get_site_option( 'mainwp_backwpup_hide_plugin', '' );
        $updated = $current !== $desired;
        if ( $updated && ! update_site_option( 'mainwp_backwpup_hide_plugin', $desired ) ) {
            return $this->abilities_v2_error( $operation, 'operation_failed', __( 'The visibility setting could not be stored.', 'mainwp-child' ) );
        }
        if ( get_site_option( 'mainwp_backwpup_hide_plugin', '' ) !== $desired ) {
            return $this->abilities_v2_error( $operation, 'operation_failed', __( 'The visibility setting could not be verified.', 'mainwp-child' ) );
        }

        return $this->abilities_v2_success(
            $operation,
            array(
                'hidden'  => $payload['hidden'],
                'updated' => $updated,
            )
        );
    }

    /**
     * Return one exact job schedule.
     *
     * @param string $operation Operation name.
     * @param array  $payload   Typed payload.
     * @return array
     */
    private function abilities_v2_get_job_schedule( $operation, $payload ) {
        if ( ! $this->abilities_v2_has_keys( $payload, array( 'job_id' ) ) || ! is_int( $payload['job_id'] ) || 1 > $payload['job_id'] || ! in_array( $payload['job_id'], $this->abilities_v2_get_job_ids(), true ) ) {
            return $this->abilities_v2_error( $operation, 'invalid_input', __( 'The job schedule request is invalid.', 'mainwp-child' ) );
        }

        $schedule = $this->abilities_v2_read_job_schedule( $payload['job_id'] );
        if ( ! is_array( $schedule ) ) {
            return $this->abilities_v2_error( $operation, 'operation_failed', __( 'The stored job schedule is invalid.', 'mainwp-child' ) );
        }

        return $this->abilities_v2_success(
            $operation,
            array(
                'job_id'   => $payload['job_id'],
                'schedule' => $schedule,
            )
        );
    }

    /**
     * Update only the exact schedule fields of one existing job.
     *
     * @param string $operation Operation name.
     * @param array  $payload   Typed payload.
     * @return array
     */
    private function abilities_v2_update_job_schedule( $operation, $payload ) {
        if ( ! $this->abilities_v2_has_keys( $payload, array( 'job_id', 'schedule' ) ) || ! is_int( $payload['job_id'] ) || 1 > $payload['job_id'] || ! is_array( $payload['schedule'] ) || ! in_array( $payload['job_id'], $this->abilities_v2_get_job_ids(), true ) ) {
            return $this->abilities_v2_error( $operation, 'invalid_input', __( 'The job schedule request is invalid.', 'mainwp-child' ) );
        }

        $updates = $this->abilities_v2_schedule_options( $payload['schedule'] );
        if ( ! is_array( $updates ) ) {
            return $this->abilities_v2_error( $operation, 'invalid_input', __( 'The job schedule is invalid.', 'mainwp-child' ) );
        }

        $current = $this->abilities_v2_read_job_schedule( $payload['job_id'] );
        if ( $this->abilities_v2_schedule_matches( $current, $payload['schedule'] ) ) {
            return $this->abilities_v2_success(
                $operation,
                array(
                    'job_id'   => $payload['job_id'],
                    'updated'  => false,
                    'schedule' => $current,
                )
            );
        }

        $snapshot = array();
        foreach ( array_keys( $updates ) as $key ) {
            $snapshot[ $key ] = $this->abilities_v2_get_job_option( $payload['job_id'], $key, null );
        }

        try {
            foreach ( $updates as $key => $value ) {
                $this->abilities_v2_set_job_option( $payload['job_id'], $key, $value );
            }
            $this->abilities_v2_refresh_job_schedule( $payload['job_id'] );
        } catch ( \Throwable $e ) {
            $this->abilities_v2_restore_job_options( $payload['job_id'], $snapshot );
            return $this->abilities_v2_error( $operation, 'operation_failed', __( 'The job schedule could not be stored.', 'mainwp-child' ) );
        }

        $stored = $this->abilities_v2_read_job_schedule( $payload['job_id'] );
        if ( ! $this->abilities_v2_schedule_matches( $stored, $payload['schedule'] ) ) {
            $this->abilities_v2_restore_job_options( $payload['job_id'], $snapshot );
            return $this->abilities_v2_error( $operation, 'operation_failed', __( 'The job schedule could not be verified.', 'mainwp-child' ) );
        }

        return $this->abilities_v2_success(
            $operation,
            array(
                'job_id'   => $payload['job_id'],
                'updated'  => true,
                'schedule' => $stored,
            )
        );
    }

    /**
     * Delete an exact job through the provider boundary.
     *
     * @param string $operation Operation name.
     * @param array  $payload   Typed payload.
     * @return array
     */
    private function abilities_v2_delete_job( $operation, $payload ) {
        if ( ! $this->abilities_v2_has_keys( $payload, array( 'job_id' ) ) || ! is_int( $payload['job_id'] ) || 1 > $payload['job_id'] ) {
            return $this->abilities_v2_error( $operation, 'invalid_input', __( 'The job deletion request is invalid.', 'mainwp-child' ) );
        }

        try {
            $result = $this->abilities_v2_provider_delete_job( $payload['job_id'] );
        } catch ( \Throwable $e ) {
            return $this->abilities_v2_error( $operation, 'operation_failed', __( 'The job deletion could not be completed.', 'mainwp-child' ) );
        }
        if ( is_wp_error( $result ) ) {
            return $this->abilities_v2_from_provider_error( $operation, $result );
        }
        if ( ! in_array( $result, array( 'deleted', 'absent' ), true ) ) {
            return $this->abilities_v2_error( $operation, 'operation_failed', __( 'The job deletion result is invalid.', 'mainwp-child' ) );
        }

        return $this->abilities_v2_success(
            $operation,
            array(
                'job_id'         => $payload['job_id'],
                'deleted'        => 'deleted' === $result,
                'already_absent' => 'absent' === $result,
            )
        );
    }

    /**
     * Start one exact job and replace its logfile with an opaque token.
     *
     * @param string $operation Operation name.
     * @param array  $payload   Typed payload.
     * @return array
     */
    private function abilities_v2_start_backup( $operation, $payload ) {
        if ( ! $this->abilities_v2_has_keys( $payload, array( 'job_id' ) ) || ! is_int( $payload['job_id'] ) || 1 > $payload['job_id'] || ! in_array( $payload['job_id'], $this->abilities_v2_get_job_ids(), true ) ) {
            return $this->abilities_v2_error( $operation, 'invalid_input', __( 'The backup start request is invalid.', 'mainwp-child' ) );
        }

        try {
            $result = $this->abilities_v2_provider_start_backup( $payload['job_id'] );
        } catch ( \Throwable $e ) {
            return $this->abilities_v2_error( $operation, 'outcome_unknown', __( 'The backup start outcome is unknown.', 'mainwp-child' ) );
        }
        if ( is_wp_error( $result ) ) {
            return $this->abilities_v2_from_provider_error( $operation, $result );
        }
        if ( ! $this->abilities_v2_valid_start_result( $result, $payload['job_id'] ) ) {
            return $this->abilities_v2_error( $operation, 'operation_failed', __( 'The backup start result is invalid.', 'mainwp-child' ) );
        }

        $run_token = null;
        if ( $result['accepted'] ) {
            $run_token = $this->abilities_v2_issue_target_token(
                'backup_progress',
                array(
                    'job_id'  => $payload['job_id'],
                    'logfile' => $result['logfile'],
                )
            );
            if ( ! is_string( $run_token ) ) {
                return $this->abilities_v2_error( $operation, 'operation_failed', __( 'The backup run token could not be stored.', 'mainwp-child' ) );
            }
        }

        return $this->abilities_v2_success(
            $operation,
            array(
                'accepted'       => $result['accepted'],
                'job_id'         => $payload['job_id'],
                'run_token'      => $run_token,
                'last_backup_at' => $result['last_backup_at'],
                'message'        => $result['message'],
            )
        );
    }

    /**
     * Poll one opaque backup run.
     *
     * @param string $operation Operation name.
     * @param array  $payload   Typed payload.
     * @return array
     */
    private function abilities_v2_backup_progress( $operation, $payload ) {
        if ( ! $this->abilities_v2_has_keys( $payload, array( 'run_token', 'log_position' ) ) || ! is_string( $payload['run_token'] ) || ! is_int( $payload['log_position'] ) || 0 > $payload['log_position'] ) {
            return $this->abilities_v2_error( $operation, 'invalid_input', __( 'The backup progress request is invalid.', 'mainwp-child' ) );
        }
        $target = $this->abilities_v2_resolve_target_token( $payload['run_token'], 'backup_progress' );
        if ( ! is_array( $target ) ) {
            return $this->abilities_v2_error( $operation, 'target_not_found', __( 'The backup run token is missing or expired.', 'mainwp-child' ) );
        }

        try {
            $result = $this->abilities_v2_provider_backup_progress( $target, $payload['log_position'] );
        } catch ( \Throwable $e ) {
            return $this->abilities_v2_error( $operation, 'operation_failed', __( 'The backup progress could not be read.', 'mainwp-child' ) );
        }
        if ( is_wp_error( $result ) ) {
            return $this->abilities_v2_from_provider_error( $operation, $result );
        }
        if ( ! $this->abilities_v2_valid_progress_result( $result ) ) {
            return $this->abilities_v2_error( $operation, 'operation_failed', __( 'The backup progress result is invalid.', 'mainwp-child' ) );
        }

        return $this->abilities_v2_success( $operation, $result );
    }

    /**
     * Abort the provider's exact current job.
     *
     * @param string $operation Operation name.
     * @param array  $payload   Typed payload.
     * @return array
     */
    private function abilities_v2_abort_backup( $operation, $payload ) {
        if ( ! $this->abilities_v2_has_keys( $payload, array() ) ) {
            return $this->abilities_v2_error( $operation, 'invalid_input', __( 'The backup abort request is invalid.', 'mainwp-child' ) );
        }
        try {
            $result = $this->abilities_v2_provider_abort_backup();
        } catch ( \Throwable $e ) {
            return $this->abilities_v2_error( $operation, 'outcome_unknown', __( 'The backup abort outcome is unknown.', 'mainwp-child' ) );
        }
        if ( is_wp_error( $result ) ) {
            return $this->abilities_v2_from_provider_error( $operation, $result );
        }
        if ( ! is_array( $result ) || ! $this->abilities_v2_has_keys( $result, array( 'abort_requested', 'state', 'message' ) ) || ! is_bool( $result['abort_requested'] ) || ! in_array( $result['state'], array( 'abort_requested', 'not_running', 'unknown' ), true ) || ! $this->abilities_v2_valid_message( $result['message'] ) ) {
            return $this->abilities_v2_error( $operation, 'operation_failed', __( 'The backup abort result is invalid.', 'mainwp-child' ) );
        }

        return $this->abilities_v2_success( $operation, $result );
    }

    /**
     * Return one bounded page of backup metadata and opaque action tokens.
     *
     * @param string $operation Operation name.
     * @param array  $payload   Typed payload.
     * @return array
     */
    private function abilities_v2_list_backups( $operation, $payload ) {
        if ( ! $this->abilities_v2_valid_list_payload( $payload ) ) {
            return $this->abilities_v2_error( $operation, 'invalid_input', __( 'The backup list request is invalid.', 'mainwp-child' ) );
        }
        try {
            $rows = $this->abilities_v2_provider_list_backups( $payload['scope'] );
        } catch ( \Throwable $e ) {
            return $this->abilities_v2_error( $operation, 'operation_failed', __( 'The backup list could not be read.', 'mainwp-child' ) );
        }
        if ( is_wp_error( $rows ) ) {
            return $this->abilities_v2_from_provider_error( $operation, $rows );
        }
        if ( ! is_array( $rows ) || count( $rows ) > 10000 ) {
            return $this->abilities_v2_error( $operation, 'operation_failed', __( 'The backup list result is invalid.', 'mainwp-child' ) );
        }
        foreach ( $rows as $row ) {
            if ( ! $this->abilities_v2_valid_backup_row( $row ) ) {
                return $this->abilities_v2_error( $operation, 'operation_failed', __( 'The backup list result is invalid.', 'mainwp-child' ) );
            }
        }
        usort( $rows, array( $this, 'abilities_v2_compare_backup_rows' ) );
        $page_rows = array_slice( $rows, ( $payload['page'] - 1 ) * $payload['per_page'], $payload['per_page'] );
        $output    = array();
        foreach ( $page_rows as $row ) {
            $download_token = $this->abilities_v2_issue_target_token( 'download_backup', $row['target'] );
            $delete_token   = $this->abilities_v2_issue_target_token( 'delete_backup', $row['target'] );
            if ( ! is_string( $download_token ) || ! is_string( $delete_token ) ) {
                return $this->abilities_v2_error( $operation, 'operation_failed', __( 'The backup action tokens could not be stored.', 'mainwp-child' ) );
            }
            unset( $row['target'] );
            $row['download_token'] = $download_token;
            $row['delete_token']   = $delete_token;
            $output[]              = $row;
        }

        return $this->abilities_v2_success(
            $operation,
            array(
                'page'     => $payload['page'],
                'per_page' => $payload['per_page'],
                'total'    => count( $rows ),
                'backups'  => $output,
            )
        );
    }

    /**
     * Resolve one exact download token into a bounded private archive location.
     *
     * The authenticated Dashboard transport is the only consumer of this
     * response. The Dashboard rebuilds the managed-site content URL from the
     * folder and file name itself; no Ability result exposes either to callers.
     *
     * @param string $operation Operation name.
     * @param array  $payload   Typed payload.
     * @return array
     */
    private function abilities_v2_redeem_backup_download( $operation, $payload ) {
        if ( ! $this->abilities_v2_has_keys( $payload, array( 'download_token' ) ) || ! is_string( $payload['download_token'] ) ) {
            return $this->abilities_v2_error( $operation, 'invalid_input', __( 'The backup download request is invalid.', 'mainwp-child' ) );
        }
        $target = $this->abilities_v2_resolve_target_token( $payload['download_token'], 'download_backup' );
        if ( ! is_array( $target ) ) {
            return $this->abilities_v2_error( $operation, 'target_not_found', __( 'The backup download token is missing or expired.', 'mainwp-child' ) );
        }
        try {
            $result = $this->abilities_v2_provider_redeem_backup_download( $target );
        } catch ( \Throwable $e ) {
            return $this->abilities_v2_error( $operation, 'operation_failed', __( 'The backup download could not be prepared.', 'mainwp-child' ) );
        }
        if ( is_wp_error( $result ) ) {
            return $this->abilities_v2_from_provider_error( $operation, $result );
        }
        if ( ! is_array( $result ) || ! $this->abilities_v2_has_keys( $result, array( 'folder', 'file_name', 'size_bytes' ) ) || ! $this->abilities_v2_valid_download_target( $result['folder'], $result['file_name'] ) || ! $this->abilities_v2_is_bounded_int( $result['size_bytes'], 0, PHP_INT_MAX ) ) {
            return $this->abilities_v2_error( $operation, 'operation_failed', __( 'The backup download result is invalid.', 'mainwp-child' ) );
        }
        return $this->abilities_v2_success( $operation, $result );
    }

    /**
     * Delete one exact token-bound backup.
     *
     * @param string $operation Operation name.
     * @param array  $payload   Typed payload.
     * @return array
     */
    private function abilities_v2_delete_backup( $operation, $payload ) {
        if ( ! $this->abilities_v2_has_keys( $payload, array( 'delete_token' ) ) || ! is_string( $payload['delete_token'] ) ) {
            return $this->abilities_v2_error( $operation, 'invalid_input', __( 'The backup deletion request is invalid.', 'mainwp-child' ) );
        }
        $target = $this->abilities_v2_resolve_target_token( $payload['delete_token'], 'delete_backup' );
        if ( ! is_array( $target ) ) {
            return $this->abilities_v2_error( $operation, 'target_not_found', __( 'The backup deletion token is missing or expired.', 'mainwp-child' ) );
        }
        try {
            $result = $this->abilities_v2_provider_delete_backup( $target );
        } catch ( \Throwable $e ) {
            return $this->abilities_v2_error( $operation, 'outcome_unknown', __( 'The backup deletion outcome is unknown.', 'mainwp-child' ) );
        }
        if ( is_wp_error( $result ) ) {
            return $this->abilities_v2_from_provider_error( $operation, $result );
        }
        if ( ! in_array( $result, array( 'deleted', 'absent' ), true ) ) {
            return $this->abilities_v2_error( $operation, 'operation_failed', __( 'The backup deletion result is invalid.', 'mainwp-child' ) );
        }
        return $this->abilities_v2_success(
            $operation,
            array(
                'deleted'        => 'deleted' === $result,
                'already_absent' => 'absent' === $result,
            )
        );
    }

    /**
     * Return one bounded page of log metadata and opaque action tokens.
     *
     * @param string $operation Operation name.
     * @param array  $payload   Typed payload.
     * @return array
     */
    private function abilities_v2_list_logs( $operation, $payload ) {
        if ( ! $this->abilities_v2_valid_list_payload( $payload ) ) {
            return $this->abilities_v2_error( $operation, 'invalid_input', __( 'The log list request is invalid.', 'mainwp-child' ) );
        }
        try {
            $rows = $this->abilities_v2_provider_list_logs( $payload['scope'] );
        } catch ( \Throwable $e ) {
            return $this->abilities_v2_error( $operation, 'operation_failed', __( 'The log list could not be read.', 'mainwp-child' ) );
        }
        if ( is_wp_error( $rows ) ) {
            return $this->abilities_v2_from_provider_error( $operation, $rows );
        }
        if ( ! is_array( $rows ) || count( $rows ) > 10000 ) {
            return $this->abilities_v2_error( $operation, 'operation_failed', __( 'The log list result is invalid.', 'mainwp-child' ) );
        }
        foreach ( $rows as $row ) {
            if ( ! $this->abilities_v2_valid_log_row( $row ) ) {
                return $this->abilities_v2_error( $operation, 'operation_failed', __( 'The log list result is invalid.', 'mainwp-child' ) );
            }
        }
        usort( $rows, array( $this, 'abilities_v2_compare_log_rows' ) );
        $page_rows = array_slice( $rows, ( $payload['page'] - 1 ) * $payload['per_page'], $payload['per_page'] );
        $output    = array();
        foreach ( $page_rows as $row ) {
            $view_token   = $this->abilities_v2_issue_target_token( 'read_log_excerpt', $row['target'] );
            $delete_token = $this->abilities_v2_issue_target_token( 'delete_log', $row['target'] );
            if ( ! is_string( $view_token ) || ! is_string( $delete_token ) ) {
                return $this->abilities_v2_error( $operation, 'operation_failed', __( 'The log action tokens could not be stored.', 'mainwp-child' ) );
            }
            unset( $row['target'] );
            $row['view_token']   = $view_token;
            $row['delete_token'] = $delete_token;
            $output[]            = $row;
        }

        return $this->abilities_v2_success(
            $operation,
            array(
                'page'     => $payload['page'],
                'per_page' => $payload['per_page'],
                'total'    => count( $rows ),
                'logs'     => $output,
            )
        );
    }

    /**
     * Read one source-bounded log excerpt.
     *
     * @param string $operation Operation name.
     * @param array  $payload   Typed payload.
     * @return array
     */
    private function abilities_v2_read_log_excerpt( $operation, $payload ) {
        if ( ! $this->abilities_v2_has_keys( $payload, array( 'view_token', 'offset', 'max_chars' ) ) || ! is_string( $payload['view_token'] ) || ! is_int( $payload['offset'] ) || 0 > $payload['offset'] || ! is_int( $payload['max_chars'] ) || 100 > $payload['max_chars'] || 20000 < $payload['max_chars'] ) {
            return $this->abilities_v2_error( $operation, 'invalid_input', __( 'The log excerpt request is invalid.', 'mainwp-child' ) );
        }
        $target = $this->abilities_v2_resolve_target_token( $payload['view_token'], 'read_log_excerpt' );
        if ( ! is_array( $target ) ) {
            return $this->abilities_v2_error( $operation, 'target_not_found', __( 'The log view token is missing or expired.', 'mainwp-child' ) );
        }
        try {
            $result = $this->abilities_v2_provider_read_log( $target, $payload['offset'], $payload['max_chars'] );
        } catch ( \Throwable $e ) {
            return $this->abilities_v2_error( $operation, 'operation_failed', __( 'The log excerpt could not be read.', 'mainwp-child' ) );
        }
        if ( is_wp_error( $result ) ) {
            return $this->abilities_v2_from_provider_error( $operation, $result );
        }
        if ( ! $this->abilities_v2_valid_log_excerpt( $result, $payload['offset'], $payload['max_chars'] ) ) {
            return $this->abilities_v2_error( $operation, 'operation_failed', __( 'The log excerpt result is invalid.', 'mainwp-child' ) );
        }
        return $this->abilities_v2_success( $operation, $result );
    }

    /**
     * Delete one exact token-bound log.
     *
     * @param string $operation Operation name.
     * @param array  $payload   Typed payload.
     * @return array
     */
    private function abilities_v2_delete_log( $operation, $payload ) {
        if ( ! $this->abilities_v2_has_keys( $payload, array( 'delete_token' ) ) || ! is_string( $payload['delete_token'] ) ) {
            return $this->abilities_v2_error( $operation, 'invalid_input', __( 'The log deletion request is invalid.', 'mainwp-child' ) );
        }
        $target = $this->abilities_v2_resolve_target_token( $payload['delete_token'], 'delete_log' );
        if ( ! is_array( $target ) ) {
            return $this->abilities_v2_error( $operation, 'target_not_found', __( 'The log deletion token is missing or expired.', 'mainwp-child' ) );
        }
        try {
            $result = $this->abilities_v2_provider_delete_log( $target );
        } catch ( \Throwable $e ) {
            return $this->abilities_v2_error( $operation, 'outcome_unknown', __( 'The log deletion outcome is unknown.', 'mainwp-child' ) );
        }
        if ( is_wp_error( $result ) ) {
            return $this->abilities_v2_from_provider_error( $operation, $result );
        }
        if ( ! in_array( $result, array( 'deleted', 'absent' ), true ) ) {
            return $this->abilities_v2_error( $operation, 'operation_failed', __( 'The log deletion result is invalid.', 'mainwp-child' ) );
        }
        return $this->abilities_v2_success(
            $operation,
            array(
                'deleted'        => 'deleted' === $result,
                'already_absent' => 'absent' === $result,
            )
        );
    }

    /**
     * Return the fixed safe diagnostic projection.
     *
     * @param string $operation Operation name.
     * @param array  $payload   Typed payload.
     * @return array
     */
    private function abilities_v2_diagnostics( $operation, $payload ) {
        if ( ! $this->abilities_v2_has_keys( $payload, array() ) ) {
            return $this->abilities_v2_error( $operation, 'invalid_input', __( 'The diagnostic request is invalid.', 'mainwp-child' ) );
        }
        try {
            $result = $this->abilities_v2_provider_diagnostics();
        } catch ( \Throwable $e ) {
            return $this->abilities_v2_error( $operation, 'operation_failed', __( 'The diagnostic result is unavailable.', 'mainwp-child' ) );
        }
        if ( is_wp_error( $result ) ) {
            return $this->abilities_v2_from_provider_error( $operation, $result );
        }
        if ( ! $this->abilities_v2_valid_diagnostics( $result ) ) {
            return $this->abilities_v2_error( $operation, 'operation_failed', __( 'The diagnostic result is invalid.', 'mainwp-child' ) );
        }
        return $this->abilities_v2_success( $operation, $result );
    }

    /**
     * Validate a list request.
     *
     * @param mixed $payload Payload.
     * @return bool
     */
    private function abilities_v2_valid_list_payload( $payload ) {
        return $this->abilities_v2_has_keys( $payload, array( 'page', 'per_page', 'scope' ) ) && is_int( $payload['page'] ) && 1 <= $payload['page'] && 1000 >= $payload['page'] && is_int( $payload['per_page'] ) && 1 <= $payload['per_page'] && 100 >= $payload['per_page'] && in_array( $payload['scope'], array( 'site', 'global', 'all' ), true );
    }

    /**
     * Validate one internal backup row.
     *
     * @param mixed $row Row.
     * @return bool
     */
    private function abilities_v2_valid_backup_row( $row ) {
        return is_array( $row ) && $this->abilities_v2_has_keys( $row, array( 'job_id', 'destination', 'file_name', 'created_at', 'size_bytes', 'target' ) ) && is_int( $row['job_id'] ) && 1 <= $row['job_id'] && is_string( $row['destination'] ) && '' !== $row['destination'] && 64 >= strlen( $row['destination'] ) && is_string( $row['file_name'] ) && '' !== $row['file_name'] && 255 >= strlen( $row['file_name'] ) && basename( $row['file_name'] ) === $row['file_name'] && is_int( $row['created_at'] ) && 0 <= $row['created_at'] && is_int( $row['size_bytes'] ) && 0 <= $row['size_bytes'] && is_array( $row['target'] ) && ! empty( $row['target'] );
    }

    /**
     * Compare backup rows newest-first.
     *
     * @param array $left  Left row.
     * @param array $right Right row.
     * @return int
     */
    private function abilities_v2_compare_backup_rows( $left, $right ) {
        if ( $left['created_at'] !== $right['created_at'] ) {
            return $right['created_at'] <=> $left['created_at'];
        }
        return strcmp( $left['job_id'] . '|' . $left['destination'] . '|' . $left['file_name'], $right['job_id'] . '|' . $right['destination'] . '|' . $right['file_name'] );
    }

    /**
     * Validate one internal log row.
     *
     * @param mixed $row Row.
     * @return bool
     */
    private function abilities_v2_valid_log_row( $row ) {
        $invalid_types = is_array( $row ) && isset( $row['job_types'] ) && is_array( $row['job_types'] ) ? array_diff( $row['job_types'], array( 'DBDUMP', 'FILE', 'WPEXP', 'WPPLUGIN', 'DBCHECK' ) ) : array( 'invalid' );
        if ( ! is_array( $row ) || ! $this->abilities_v2_has_keys( $row, array( 'job_name', 'started_at', 'size_bytes', 'runtime_seconds', 'errors', 'warnings', 'job_types', 'target' ) ) || ! is_string( $row['job_name'] ) || '' === $row['job_name'] || 255 < strlen( $row['job_name'] ) || ! is_int( $row['started_at'] ) || 0 > $row['started_at'] || ! is_int( $row['size_bytes'] ) || 0 > $row['size_bytes'] || ! is_int( $row['runtime_seconds'] ) || 0 > $row['runtime_seconds'] || ! is_int( $row['errors'] ) || 0 > $row['errors'] || ! is_int( $row['warnings'] ) || 0 > $row['warnings'] || ! is_array( $row['job_types'] ) || count( $row['job_types'] ) > 8 || ! empty( $invalid_types ) || count( $row['job_types'] ) !== count( array_unique( $row['job_types'] ) ) || ! is_array( $row['target'] ) || empty( $row['target'] ) ) {
            return false;
        }
        return true;
    }

    /**
     * Compare log rows newest-first.
     *
     * @param array $left  Left row.
     * @param array $right Right row.
     * @return int
     */
    private function abilities_v2_compare_log_rows( $left, $right ) {
        if ( $left['started_at'] !== $right['started_at'] ) {
            return $right['started_at'] <=> $left['started_at'];
        }
        return strcmp( $left['job_name'], $right['job_name'] );
    }

    /**
     * Validate one log excerpt.
     *
     * @param mixed $result    Result.
     * @param int   $offset    Requested offset.
     * @param int   $max_chars Maximum characters.
     * @return bool
     */
    private function abilities_v2_valid_log_excerpt( $result, $offset, $max_chars ) {
        return is_array( $result ) && $this->abilities_v2_has_keys( $result, array( 'content', 'offset', 'next_offset', 'truncated' ) ) && is_string( $result['content'] ) && strlen( $result['content'] ) <= $max_chars && $offset === $result['offset'] && is_int( $result['next_offset'] ) && $offset <= $result['next_offset'] && is_bool( $result['truncated'] );
    }

    /**
     * Validate the fixed diagnostic projection.
     *
     * @param mixed $result Result.
     * @return bool
     */
    private function abilities_v2_valid_diagnostics( $result ) {
        if ( ! is_array( $result ) || ! $this->abilities_v2_has_keys( $result, array( 'backwpup_edition', 'backwpup_version', 'wordpress_version', 'php_version', 'cron_status', 'temp_status', 'logs_status', 'self_connect', 'issues' ) ) || ! in_array( $result['backwpup_edition'], array( 'free', 'pro' ), true ) || ! $this->abilities_v2_valid_version( $result['backwpup_version'] ) || ! $this->abilities_v2_valid_version( $result['wordpress_version'] ) || ! $this->abilities_v2_valid_version( $result['php_version'] ) || ! in_array( $result['cron_status'], array( 'enabled', 'disabled', 'unknown' ), true ) || ! in_array( $result['temp_status'], array( 'writable', 'not_writable', 'missing', 'unknown' ), true ) || ! in_array( $result['logs_status'], array( 'writable', 'not_writable', 'missing', 'unknown' ), true ) || ! in_array( $result['self_connect'], array( 'ok', 'error', 'unknown' ), true ) || ! is_array( $result['issues'] ) || count( $result['issues'] ) > 20 ) {
            return false;
        }
        foreach ( $result['issues'] as $issue ) {
            if ( ! is_string( $issue ) || '' === $issue || strlen( $issue ) > 255 || sanitize_text_field( $issue ) !== $issue ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Validate a bounded version string.
     *
     * @param mixed $value Value.
     * @return bool
     */
    private function abilities_v2_valid_version( $value ) {
        return is_string( $value ) && '' !== $value && strlen( $value ) <= 32 && sanitize_text_field( $value ) === $value;
    }

    /**
     * Validate a provider start result.
     *
     * @param mixed $result Provider result.
     * @param int   $job_id Expected job ID.
     * @return bool
     */
    private function abilities_v2_valid_start_result( $result, $job_id ) {
        if ( ! is_array( $result ) || ! $this->abilities_v2_has_keys( $result, array( 'accepted', 'job_id', 'logfile', 'last_backup_at', 'message' ) ) || ! is_bool( $result['accepted'] ) || $job_id !== $result['job_id'] || ! is_int( $result['last_backup_at'] ) || 0 > $result['last_backup_at'] || ! $this->abilities_v2_valid_message( $result['message'] ) ) {
            return false;
        }
        if ( ! $result['accepted'] ) {
            return null === $result['logfile'];
        }
        return is_string( $result['logfile'] ) && basename( $result['logfile'] ) === $result['logfile'] && strlen( $result['logfile'] ) <= 255 && 1 === preg_match( '/^[A-Za-z0-9_.-]+$/D', $result['logfile'] );
    }

    /**
     * Validate a structured progress result.
     *
     * The position is the observed one and is deliberately not bound to the requested position:
     * a log can be absent (null) or shorter than the caller expects.
     *
     * @param mixed $result Provider result.
     * @return bool
     */
    private function abilities_v2_valid_progress_result( $result ) {
        if ( ! is_array( $result ) || ! $this->abilities_v2_has_keys( $result, array( 'state', 'progress_percent', 'log_position', 'last_backup_at', 'message' ) ) || ! in_array( $result['state'], array( 'running', 'completed', 'failed', 'unknown' ), true ) || ! ( null === $result['log_position'] || ( is_int( $result['log_position'] ) && 0 <= $result['log_position'] ) ) || ! is_int( $result['last_backup_at'] ) || 0 > $result['last_backup_at'] || ! $this->abilities_v2_valid_message( $result['message'] ) ) {
            return false;
        }
        return null === $result['progress_percent'] || $this->abilities_v2_is_bounded_int( $result['progress_percent'], 0, 100 );
    }

    /**
     * Check a bounded safe message.
     *
     * @param mixed $message Message.
     * @return bool
     */
    private function abilities_v2_valid_message( $message ) {
        return is_string( $message ) && strlen( $message ) <= 1000 && sanitize_text_field( $message ) === $message;
    }

    /**
     * Validate a bounded archive directory and file name for the Dashboard gateway.
     *
     * @param mixed $folder    Candidate destination directory.
     * @param mixed $file_name Candidate archive file name.
     * @return bool
     */
    private function abilities_v2_valid_download_target( $folder, $file_name ) {
        if ( ! is_string( $folder ) || '' === $folder || 4096 < strlen( $folder ) || preg_match( '/[\x00-\x1F\x7F]/', $folder ) ) {
            return false;
        }
        return is_string( $file_name ) && '' !== $file_name && 255 >= strlen( $file_name ) && basename( $file_name ) === $file_name && false === strpos( $file_name, '\\' ) && ! preg_match( '/[\x00-\x1F\x7F]/', $file_name );
    }

    /**
     * Issue an opaque action-bound target token.
     *
     * @param string $action Action binding.
     * @param array  $target Internal target.
     * @return string|null
     */
    private function abilities_v2_issue_target_token( $action, $target ) {
        try {
            $token = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe random token encoding, not obfuscation.
        } catch ( \Throwable $e ) {
            return null;
        }
        $value = array(
            'action'    => $action,
            'target'    => $target,
            'issued_at' => time(),
        );
        $ttl   = 'backup_progress' === $action ? DAY_IN_SECONDS : 5 * MINUTE_IN_SECONDS;
        $key   = 'mainwp_backwpup_v2_' . hash( 'sha256', $token );
        if ( ! set_transient( $key, $value, $ttl ) && get_transient( $key ) !== $value ) {
            return null;
        }
        return $token;
    }

    /**
     * Resolve an opaque target token.
     *
     * @param string $token  Token.
     * @param string $action Action binding.
     * @return array|null
     */
    private function abilities_v2_resolve_target_token( $token, $action ) {
        if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{43}$/D', $token ) ) {
            return null;
        }
        $value = get_transient( 'mainwp_backwpup_v2_' . hash( 'sha256', $token ) );
        $ttl   = 'backup_progress' === $action ? DAY_IN_SECONDS : 5 * MINUTE_IN_SECONDS;
        if ( ! is_array( $value ) || ! $this->abilities_v2_has_keys( $value, array( 'action', 'target', 'issued_at' ) ) || $action !== $value['action'] || ! is_array( $value['target'] ) || ! is_int( $value['issued_at'] ) || $value['issued_at'] > time() || $value['issued_at'] < time() - $ttl ) {
            return null;
        }
        return $value['target'];
    }

    /**
     * Normalize a provider error to the closed protocol.
     *
     * @param string   $operation Operation name.
     * @param WP_Error $error     Provider error.
     * @return array
     */
    private function abilities_v2_from_provider_error( $operation, $error ) {
        $allowed = array( 'already_running', 'not_found', 'operation_failed', 'outcome_unknown' );
        $code    = in_array( $error->get_error_code(), $allowed, true ) ? $error->get_error_code() : 'operation_failed';
        return $this->abilities_v2_error( $operation, $code, __( 'The BackWPup provider operation failed.', 'mainwp-child' ) );
    }

    /**
     * Delete one provider job and verify absence.
     *
     * @param int $job_id Job ID.
     * @return string|WP_Error
     */
    protected function abilities_v2_provider_delete_job( $job_id ) {
        if ( ! in_array( $job_id, $this->abilities_v2_get_job_ids(), true ) ) {
            return 'absent';
        }
        wp_clear_scheduled_hook( 'backwpup_cron', array( $job_id ) );
        wp_clear_scheduled_hook( 'backwpup_cron', array( 'arg' => $job_id ) );
        wp_clear_scheduled_hook( 'backwpup_cron', array( 'id' => $job_id ) );
        if ( ! \BackWPup_Option::delete_job( $job_id ) || in_array( $job_id, $this->abilities_v2_get_job_ids(), true ) ) {
            return new \WP_Error( 'operation_failed' );
        }
        return 'deleted';
    }

    /**
     * Start one provider job through the legacy-compatible primitive.
     *
     * @param int $job_id Job ID.
     * @return array|WP_Error
     */
    protected function abilities_v2_provider_start_backup( $job_id ) {
        if ( class_exists( '\\BackWPup_Job' ) && is_object( \BackWPup_Job::get_working_data() ) ) {
            return new \WP_Error( 'already_running' );
        }
        $old_post          = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Preserve the internal authenticated request while using the existing primitive.
        $_POST['settings'] = array( 'job_id' => $job_id ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Internal typed adapter.
        try {
            $result = $this->backup_now();
        } finally {
            $_POST = $old_post;
        }
        if ( ! is_array( $result ) || isset( $result['error'] ) || 1 !== (int) ( $result['success'] ?? 0 ) ) {
            return new \WP_Error( 'operation_failed' );
        }
        $logfile = isset( $result['logfile'] ) && is_string( $result['logfile'] ) ? basename( $result['logfile'] ) : null;
        return array(
            'accepted'       => null !== $logfile,
            'job_id'         => $job_id,
            'logfile'        => $logfile,
            'last_backup_at' => max( 0, (int) ( $result['lastbackup'] ?? 0 ) ),
            'message'        => null !== $logfile ? __( 'The backup request was accepted.', 'mainwp-child' ) : __( 'The backup request was rejected.', 'mainwp-child' ),
        );
    }

    /**
     * Read structured provider progress without returning log content.
     *
     * @param array $target   Internal run target.
     * @param int   $position Requested position.
     * @return array|WP_Error
     */
    protected function abilities_v2_provider_backup_progress( $target, $position ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- The requested position stays in the adapter contract, but the reported position is the observed one.
        if ( ! $this->abilities_v2_has_keys( $target, array( 'job_id', 'logfile' ) ) || ! is_int( $target['job_id'] ) || ! is_string( $target['logfile'] ) || basename( $target['logfile'] ) !== $target['logfile'] ) {
            return new \WP_Error( 'not_found' );
        }
        $log_file = $this->abilities_v2_resolve_log_file( $target['logfile'] );
        $observed = $this->abilities_v2_observed_log_position( $log_file );
        $job      = $this->abilities_v2_working_job();
        if ( is_object( $job ) ) {
            $job_file = isset( $job->logfile ) ? basename( (string) $job->logfile ) : '';
            if ( $job_file !== $target['logfile'] ) {
                return array(
                    'state'            => 'unknown',
                    'progress_percent' => null,
                    'log_position'     => $observed,
                    'last_backup_at'   => max( 0, (int) MainWP_Utility::get_lasttime_backup( 'backwpup' ) ),
                    'message'          => __( 'The current backup identity changed.', 'mainwp-child' ),
                );
            }
            $percent = isset( $job->step_percent ) ? max( 0, min( 100, (int) $job->step_percent ) ) : null;
            return array(
                'state'            => 'running',
                'progress_percent' => $percent,
                'log_position'     => $observed,
                'last_backup_at'   => max( 0, (int) MainWP_Utility::get_lasttime_backup( 'backwpup' ) ),
                'message'          => __( 'The backup is running.', 'mainwp-child' ),
            );
        }

        if ( ! is_string( $log_file ) ) {
            return array(
                'state'            => 'unknown',
                'progress_percent' => null,
                'log_position'     => $observed,
                'last_backup_at'   => max( 0, (int) MainWP_Utility::get_lasttime_backup( 'backwpup' ) ),
                'message'          => __( 'The backup state is unavailable.', 'mainwp-child' ),
            );
        }
        // A missing job object only means this request cannot see one. The outcome is reported
        // solely from what the log records: BackWPup closes the document and stamps the error
        // count when the job ends, so an unterminated log is an unknown outcome, not a success.
        $errors = $this->abilities_v2_log_error_count( $log_file );
        if ( null === $errors || ! $this->abilities_v2_log_records_completion( $log_file ) ) {
            return array(
                'state'            => 'unknown',
                'progress_percent' => null,
                'log_position'     => $observed,
                'last_backup_at'   => max( 0, (int) MainWP_Utility::get_lasttime_backup( 'backwpup' ) ),
                'message'          => __( 'The backup log does not record a finished run.', 'mainwp-child' ),
            );
        }
        $failed = 0 < $errors;
        return array(
            'state'            => $failed ? 'failed' : 'completed',
            'progress_percent' => 100,
            'log_position'     => $observed,
            'last_backup_at'   => max( 0, (int) MainWP_Utility::get_lasttime_backup( 'backwpup' ) ),
            'message'          => $failed ? __( 'The backup completed with errors.', 'mainwp-child' ) : __( 'The backup completed.', 'mainwp-child' ),
        );
    }

    /**
     * Return the provider's current working job object.
     *
     * @return object|false
     */
    protected function abilities_v2_working_job() {
        return class_exists( '\\BackWPup_Job' ) ? \BackWPup_Job::get_working_data() : false;
    }

    /**
     * Report the observed byte position of one resolved log file.
     *
     * @param string|null $log_file Resolved log file.
     * @return int|null
     */
    private function abilities_v2_observed_log_position( $log_file ) {
        if ( ! is_string( $log_file ) ) {
            return null;
        }
        $size = filesize( $log_file );
        return is_int( $size ) && 0 <= $size ? $size : null;
    }

    /**
     * Read the error count BackWPup stamps into the log header.
     *
     * @param string $log_file Resolved log file.
     * @return int|null Null when the log carries no readable error count.
     */
    private function abilities_v2_log_error_count( $log_file ) {
        $chunk = $this->abilities_v2_read_log_chunk( $log_file, 0, 65536 );
        if ( ! is_array( $chunk ) || 1 !== preg_match( '/<meta\s+name=["\']backwpup_errors["\']\s+content=["\']([0-9]{1,12})["\']/i', $chunk['content'], $matches ) ) {
            return null;
        }
        return (int) $matches[1];
    }

    /**
     * Detect the closing marker BackWPup appends only when a job ends.
     *
     * @param string $log_file Resolved log file.
     * @return bool
     */
    private function abilities_v2_log_records_completion( $log_file ) {
        if ( '.gz' !== substr( $log_file, -3 ) && '.bz2' !== substr( $log_file, -4 ) ) {
            $size  = (int) filesize( $log_file );
            $chunk = $this->abilities_v2_read_log_chunk( $log_file, max( 0, $size - 256 ), 256 );
            return is_array( $chunk ) && false !== stripos( $chunk['content'], '</html>' );
        }
        $tail = $this->abilities_v2_compressed_tail( $log_file, 256 );
        return is_string( $tail ) && false !== stripos( $tail, '</html>' );
    }

    /**
     * Read the trailing bytes of a compressed log in one forward pass.
     *
     * A compressed log has no cheap end offset, so the tail can only be reached by decompressing
     * everything before it. Reading it as successive seeked windows made zlib and bzip2 redo that
     * work from byte zero on every window: reaching the old 256-window ceiling cost over two
     * gigabytes of decompression, driven by an archive on disk the Child has no reason to trust.
     * One handle streams forward once instead, keeps only the trailing bytes, and gives up past
     * 16 MB - the same amount 256 windows of 64 KB already allowed.
     *
     * @param string $file      Gzip or bzip2 log file.
     * @param int    $tail_size Trailing bytes to keep.
     * @return string|null Null when the log cannot be read or outgrows the budget.
     */
    private function abilities_v2_compressed_tail( $file, $tail_size ) {
        $gzip = '.gz' === substr( $file, -3 );
        if ( $gzip ) {
            $handle = gzopen( $file, 'rb' );
            if ( false === $handle ) {
                return null;
            }
        } elseif ( ! function_exists( 'bzopen' ) ) {
            return null;
        } else {
            $handle = bzopen( $file, 'r' );
            if ( ! is_resource( $handle ) ) {
                return null;
            }
        }
        $limit = 16777216;
        $tail  = '';
        $read  = 0;
        $ended = false;
        while ( $read <= $limit ) {
            $part = $gzip ? gzread( $handle, 65536 ) : bzread( $handle, 65536 );
            if ( ! is_string( $part ) ) {
                break;
            }
            if ( '' === $part ) {
                $ended = true;
                break;
            }
            $read += strlen( $part );
            $tail  = substr( $tail . $part, -$tail_size );
        }
        if ( $gzip ) {
            gzclose( $handle );
        } else {
            bzclose( $handle );
        }
        return $ended ? $tail : null;
    }

    /**
     * Abort current provider work.
     *
     * @return array|WP_Error
     */
    protected function abilities_v2_provider_abort_backup() {
        if ( ! class_exists( '\\BackWPup_Job' ) || ! is_object( \BackWPup_Job::get_working_data() ) ) {
            return array(
                'abort_requested' => false,
                'state'           => 'not_running',
                'message'         => __( 'No backup is running.', 'mainwp-child' ),
            );
        }
        $result = $this->backup_abort();
        if ( ! is_array( $result ) || isset( $result['error'] ) || 1 !== (int) ( $result['success'] ?? 0 ) ) {
            return new \WP_Error( 'outcome_unknown' );
        }
        return array(
            'abort_requested' => true,
            'state'           => 'abort_requested',
            'message'         => __( 'The backup abort was requested.', 'mainwp-child' ),
        );
    }

    /**
     * Enumerate bounded normalized backup metadata without using a list table.
     *
     * @param string $scope Job scope.
     * @return array|WP_Error
     */
    protected function abilities_v2_provider_list_backups( $scope ) { // phpcs:ignore -- Provider normalization is deliberately linear.
        $rows       = array();
        $global_ids = array_map( 'intval', $this->get_all_global_backwpup_job_ids() );
        foreach ( $this->abilities_v2_get_job_ids() as $job_id ) {
            $job_id    = (int) $job_id;
            $is_global = in_array( $job_id, $global_ids, true );
            if ( ( 'global' === $scope && ! $is_global ) || ( 'site' === $scope && $is_global ) || 'sync' === $this->abilities_v2_get_job_option( $job_id, 'backuptype', '' ) ) {
                continue;
            }
            $destinations = $this->abilities_v2_get_job_option( $job_id, 'destinations', array() );
            if ( ! is_array( $destinations ) || count( $destinations ) > 20 ) {
                return new \WP_Error( 'operation_failed' );
            }
            foreach ( $destinations as $destination ) {
                if ( ! is_string( $destination ) || 1 !== preg_match( '/^[A-Z0-9_-]{1,64}$/D', $destination ) ) {
                    return new \WP_Error( 'operation_failed' );
                }
                $provider = $this->abilities_v2_get_destination( $destination );
                if ( ! is_object( $provider ) || ! method_exists( $provider, 'file_get_list' ) ) {
                    return new \WP_Error( 'operation_failed' );
                }
                $destination_key = $job_id . '_' . $destination;
                $files           = $provider->file_get_list( $destination_key );
                if ( ! is_array( $files ) ) {
                    return new \WP_Error( 'operation_failed' );
                }
                foreach ( $files as $file ) {
                    if ( ! is_array( $file ) || ! isset( $file['file'], $file['time'] ) || ! is_string( $file['file'] ) || '' === $file['file'] || 4096 < strlen( $file['file'] ) || ! is_numeric( $file['time'] ) ) {
                        return new \WP_Error( 'operation_failed' );
                    }
                    $file_name = isset( $file['filename'] ) && is_string( $file['filename'] ) ? $file['filename'] : basename( $file['file'] );
                    $size      = $file['filesize'] ?? ( $file['size'] ?? 0 );
                    if ( basename( $file_name ) !== $file_name || '' === $file_name || 255 < strlen( $file_name ) || ! is_numeric( $size ) || 0 > (int) $size || 0 > (int) $file['time'] ) {
                        return new \WP_Error( 'operation_failed' );
                    }
                    $rows[] = array(
                        'job_id'      => $job_id,
                        'destination' => $destination,
                        'file_name'   => $file_name,
                        'created_at'  => (int) $file['time'],
                        'size_bytes'  => (int) $size,
                        'target'      => array(
                            'destination_key' => $destination_key,
                            'file'            => $file['file'],
                        ),
                    );
                    if ( count( $rows ) > 10000 ) {
                        return new \WP_Error( 'operation_failed' );
                    }
                }
            }
        }
        return $rows;
    }

    /**
     * Resolve one registered BackWPup destination.
     *
     * @param string $destination Destination identifier.
     * @return object|null
     */
    protected function abilities_v2_get_destination( $destination ) {
        return class_exists( '\\BackWPup' ) ? \BackWPup::get_destination( $destination ) : null;
    }

    /**
     * Delete one exact provider backup and verify its absence.
     *
     * @param array $target Internal target.
     * @return string|WP_Error
     */
    protected function abilities_v2_provider_delete_backup( $target ) {
        if ( ! $this->abilities_v2_has_keys( $target, array( 'destination_key', 'file' ) ) || ! is_string( $target['destination_key'] ) || 1 !== preg_match( '/^[1-9][0-9]*_[A-Z0-9_-]{1,64}$/D', $target['destination_key'] ) || ! is_string( $target['file'] ) || '' === $target['file'] || 4096 < strlen( $target['file'] ) ) {
            return new \WP_Error( 'not_found' );
        }
        list( , $destination ) = explode( '_', $target['destination_key'], 2 );
        $provider              = $this->abilities_v2_get_destination( $destination );
        if ( ! is_object( $provider ) || ! method_exists( $provider, 'file_get_list' ) || ! method_exists( $provider, 'file_delete' ) ) {
            return new \WP_Error( 'operation_failed' );
        }
        $files = $provider->file_get_list( $target['destination_key'] );
        if ( ! is_array( $files ) ) {
            return new \WP_Error( 'operation_failed' );
        }
        $found = false;
        foreach ( $files as $file ) {
            if ( is_array( $file ) && isset( $file['file'] ) && is_string( $file['file'] ) && hash_equals( $target['file'], $file['file'] ) ) {
                $found = true;
                break;
            }
        }
        if ( ! $found ) {
            return 'absent';
        }
        $provider->file_delete( $target['destination_key'], $target['file'] );
        $files = $provider->file_get_list( $target['destination_key'] );
        if ( ! is_array( $files ) ) {
            return new \WP_Error( 'outcome_unknown' );
        }
        foreach ( $files as $file ) {
            if ( is_array( $file ) && isset( $file['file'] ) && is_string( $file['file'] ) && hash_equals( $target['file'], $file['file'] ) ) {
                return new \WP_Error( 'outcome_unknown' );
            }
        }
        return 'deleted';
    }

    /**
     * Resolve the current provider archive location for one exact hidden target.
     *
     * Only the FOLDER destination is redeemable: every remote one answers with a remote
     * location - an FTP host and port, an S3 bucket URL - which the Dashboard cannot turn
     * back into a managed-site content URL, and which the Child should not be handing out
     * on the strength of a consumer's strictness it cannot assume. They fail closed here,
     * before the provider is consulted.
     *
     * FOLDER is necessary but not sufficient: BackWPup's local backup directory is
     * admin-configurable and need not sit under the web root. Binding it there is a paired
     * change with the Dashboard extension, which today is what rejects such a folder.
     *
     * @param array $target Internal target.
     * @return array|WP_Error
     */
    protected function abilities_v2_provider_redeem_backup_download( $target ) {
        if ( ! $this->abilities_v2_has_keys( $target, array( 'destination_key', 'file' ) ) || ! is_string( $target['destination_key'] ) || 1 !== preg_match( '/^[1-9][0-9]*_[A-Z0-9_-]{1,64}$/D', $target['destination_key'] ) || ! is_string( $target['file'] ) || '' === $target['file'] || 4096 < strlen( $target['file'] ) ) {
            return new \WP_Error( 'not_found' );
        }
        list( , $destination ) = explode( '_', $target['destination_key'], 2 );
        if ( 'FOLDER' !== $destination ) {
            return new \WP_Error( 'not_found' );
        }
        $provider = $this->abilities_v2_get_destination( $destination );
        if ( ! is_object( $provider ) || ! method_exists( $provider, 'file_get_list' ) ) {
            return new \WP_Error( 'operation_failed' );
        }
        $files = $provider->file_get_list( $target['destination_key'] );
        if ( ! is_array( $files ) || count( $files ) > 10000 ) {
            return new \WP_Error( 'operation_failed' );
        }
        foreach ( $files as $file ) {
            if ( ! is_array( $file ) || ! isset( $file['file'] ) || ! is_string( $file['file'] ) || ! hash_equals( $target['file'], $file['file'] ) ) {
                continue;
            }
            $size      = isset( $file['filesize'] ) ? $file['filesize'] : ( isset( $file['size'] ) ? $file['size'] : 0 );
            $file_name = isset( $file['filename'] ) && is_string( $file['filename'] ) ? $file['filename'] : basename( $file['file'] );
            if ( ! isset( $file['folder'] ) || ! is_string( $file['folder'] ) || '' === $file['folder'] || ! is_numeric( $size ) || 0 > (int) $size ) {
                return new \WP_Error( 'operation_failed' );
            }
            return array(
                'folder'     => $file['folder'],
                'file_name'  => $file_name,
                'size_bytes' => (int) $size,
            );
        }
        return new \WP_Error( 'not_found' );
    }

    /**
     * Enumerate bounded normalized log metadata without using a list table.
     *
     * @param string $scope Job scope.
     * @return array|WP_Error
     */
    protected function abilities_v2_provider_list_logs( $scope ) {
        $directory = $this->abilities_v2_log_directory();
        $root      = is_string( $directory ) ? realpath( $directory ) : false;
        if ( false === $root || ! is_dir( $root ) || ! is_readable( $root ) ) {
            return array();
        }
        $handle = opendir( $root );
        if ( false === $handle ) {
            return new \WP_Error( 'operation_failed' );
        }
        $rows = array();
        try {
            while ( false !== ( $basename = readdir( $handle ) ) ) {
                if ( 1 !== preg_match( '/^backwpup_log_[A-Za-z0-9_.-]+\.html(?:\.gz|\.bz2)?$/D', $basename ) ) {
                    continue;
                }
                $file = realpath( trailingslashit( $root ) . $basename );
                if ( false === $file || 0 !== strpos( wp_normalize_path( $file ), trailingslashit( wp_normalize_path( $root ) ) ) || ! is_file( $file ) || ! is_readable( $file ) ) {
                    return new \WP_Error( 'operation_failed' );
                }
                $header = $this->abilities_v2_read_log_header( $file );
                if ( ! is_array( $header ) ) {
                    return new \WP_Error( 'operation_failed' );
                }
                if ( ! $this->abilities_v2_log_matches_scope( $header, $scope ) ) {
                    continue;
                }
                $rows[] = array(
                    'job_name'        => $header['job_name'],
                    'started_at'      => $header['started_at'],
                    'size_bytes'      => (int) filesize( $file ),
                    'runtime_seconds' => $header['runtime_seconds'],
                    'errors'          => $header['errors'],
                    'warnings'        => $header['warnings'],
                    'job_types'       => $header['job_types'],
                    'target'          => array(
                        'basename'    => $basename,
                        'size_bytes'  => (int) filesize( $file ),
                        'modified_at' => (int) filemtime( $file ),
                    ),
                );
                if ( count( $rows ) > 10000 ) {
                    return new \WP_Error( 'operation_failed' );
                }
            }
        } finally {
            closedir( $handle );
        }
        return $rows;
    }

    /**
     * Return the configured absolute log directory.
     *
     * @return string|null
     */
    protected function abilities_v2_log_directory() {
        if ( ! class_exists( '\\BackWPup_File' ) ) {
            return null;
        }
        return \BackWPup_File::get_absolute_path( get_site_option( 'backwpup_cfg_logfolder' ) );
    }

    /**
     * Read one bounded, sanitized and redacted log excerpt.
     *
     * @param array $target    Internal target.
     * @param int   $offset    Decompressed byte offset.
     * @param int   $max_chars Maximum output characters.
     * @return array|WP_Error
     */
    protected function abilities_v2_provider_read_log( $target, $offset, $max_chars ) {
        $file = $this->abilities_v2_resolve_log_target( $target );
        if ( ! is_string( $file ) ) {
            return new \WP_Error( 'not_found' );
        }
        $chunk = $this->abilities_v2_read_log_chunk( $file, $offset, $max_chars );
        if ( ! is_array( $chunk ) ) {
            return new \WP_Error( 'operation_failed' );
        }
        $content = $this->abilities_v2_redact_log_content( $chunk['content'] );
        if ( strlen( $content ) > $max_chars ) {
            $content = substr( $content, 0, $max_chars );
        }
        return array(
            'content'     => $content,
            'offset'      => $offset,
            'next_offset' => $chunk['next_offset'],
            'truncated'   => $chunk['truncated'],
        );
    }

    /**
     * Delete one exact contained log and verify absence.
     *
     * @param array $target Internal target.
     * @return string|WP_Error
     */
    protected function abilities_v2_provider_delete_log( $target ) {
        $file = $this->abilities_v2_resolve_log_target( $target );
        if ( ! is_string( $file ) ) {
            // resolve_log_target answers null both for a genuinely missing file and for one whose
            // size or mtime no longer matches the token. A log BackWPup appended to since it was
            // listed still exists, so reporting it absent would claim a deletion that never happened.
            // Only a file that is really gone is absent; a changed one is a conflict the Dashboard
            // must re-list before it can act.
            return $this->abilities_v2_log_target_present( $target ) ? new \WP_Error( 'not_found' ) : 'absent';
        }
        if ( ! is_writable( dirname( $file ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Exact local BackWPup log directory check.
            return new \WP_Error( 'operation_failed' );
        }
        wp_delete_file( $file );
        return file_exists( $file ) ? new \WP_Error( 'outcome_unknown' ) : 'deleted';
    }

    /**
     * Build an allowlisted diagnostic projection.
     *
     * @return array
     */
    protected function abilities_v2_provider_diagnostics() {
        $temp = class_exists( '\\BackWPup' ) ? \BackWPup::get_plugin_data( 'TEMP' ) : null;
        $logs = $this->abilities_v2_log_directory();
        return array(
            'backwpup_edition'  => $this->is_backwpup_pro ? 'pro' : 'free',
            'backwpup_version'  => class_exists( '\\BackWPup' ) ? (string) \BackWPup::get_plugin_data( 'Version' ) : 'unknown',
            'wordpress_version' => (string) get_bloginfo( 'version' ),
            'php_version'       => (string) PHP_VERSION,
            'cron_status'       => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'disabled' : 'enabled',
            'temp_status'       => $this->abilities_v2_directory_status( $temp ),
            'logs_status'       => $this->abilities_v2_directory_status( $logs ),
            'self_connect'      => 'unknown',
            'issues'            => array(),
        );
    }

    /**
     * Classify one directory without exposing its path.
     *
     * @param mixed $directory Directory.
     * @return string
     */
    private function abilities_v2_directory_status( $directory ) {
        if ( ! is_string( $directory ) || '' === $directory || ! is_dir( $directory ) ) {
            return 'missing';
        }
        return is_writable( $directory ) ? 'writable' : 'not_writable'; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Safe status classification only.
    }

    /**
     * Parse a bounded BackWPup log header.
     *
     * @param string $file Log file.
     * @return array|null
     */
    private function abilities_v2_read_log_header( $file ) {
        $chunk = $this->abilities_v2_read_log_chunk( $file, 0, 65536 );
        if ( ! is_array( $chunk ) ) {
            return null;
        }
        $meta = array();
        if ( preg_match_all( '/<meta\s+name=["\']backwpup_([a-z]+)["\']\s+content=["\']([^"\']*)["\']\s*\/?\s*>/i', $chunk['content'], $matches, PREG_SET_ORDER ) ) {
            foreach ( $matches as $match ) {
                $meta[ strtolower( $match[1] ) ] = html_entity_decode( $match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            }
        }
        $job_name = sanitize_text_field( $meta['jobname'] ?? '' );
        $types    = isset( $meta['jobtype'] ) ? array_values( array_filter( explode( '+', strtoupper( $meta['jobtype'] ) ) ) ) : array();
        if ( '' === $job_name || 255 < strlen( $job_name ) || empty( $types ) || ! empty( array_diff( $types, array( 'DBDUMP', 'FILE', 'WPEXP', 'WPPLUGIN', 'DBCHECK' ) ) ) || count( $types ) > 8 || count( $types ) !== count( array_unique( $types ) ) ) {
            return null;
        }
        foreach ( array( 'jobtime', 'jobruntime', 'errors', 'warnings' ) as $key ) {
            if ( ! isset( $meta[ $key ] ) || 1 !== preg_match( '/^[0-9]{1,12}$/D', $meta[ $key ] ) ) {
                return null;
            }
        }
        return array(
            'job_name'        => $job_name,
            'started_at'      => (int) $meta['jobtime'],
            'runtime_seconds' => (int) $meta['jobruntime'],
            'errors'          => (int) $meta['errors'],
            'warnings'        => (int) $meta['warnings'],
            'job_types'       => $types,
            'job_id'          => isset( $meta['jobid'] ) && ctype_digit( $meta['jobid'] ) ? (int) $meta['jobid'] : null,
        );
    }

    /**
     * Check whether log metadata belongs to the requested job scope.
     *
     * @param array  $header Header.
     * @param string $scope  Scope.
     * @return bool
     */
    private function abilities_v2_log_matches_scope( $header, $scope ) {
        if ( 'all' === $scope || null === $header['job_id'] ) {
            return true;
        }
        $is_global = in_array( $header['job_id'], array_map( 'intval', $this->get_all_global_backwpup_job_ids() ), true );
        return 'global' === $scope ? $is_global : ! $is_global;
    }

    /**
     * Resolve and verify an exact token-bound log target.
     *
     * @param array $target Internal target.
     * @return string|null
     */
    private function abilities_v2_resolve_log_target( $target ) {
        if ( ! $this->abilities_v2_has_keys( $target, array( 'basename', 'size_bytes', 'modified_at' ) ) || ! is_string( $target['basename'] ) || 1 !== preg_match( '/^backwpup_log_[A-Za-z0-9_.-]+\.html(?:\.gz|\.bz2)?$/D', $target['basename'] ) || ! is_int( $target['size_bytes'] ) || 0 > $target['size_bytes'] || ! is_int( $target['modified_at'] ) || 0 > $target['modified_at'] ) {
            return null;
        }
        $directory = $this->abilities_v2_log_directory();
        $root      = is_string( $directory ) ? realpath( $directory ) : false;
        $file      = false === $root ? false : realpath( trailingslashit( $root ) . $target['basename'] );
        if ( false === $file || 0 !== strpos( wp_normalize_path( $file ), trailingslashit( wp_normalize_path( $root ) ) ) || ! is_file( $file ) || ! is_readable( $file ) || (int) filesize( $file ) !== $target['size_bytes'] || (int) filemtime( $file ) !== $target['modified_at'] ) {
            return null;
        }
        return $file;
    }

    /**
     * Whether a path-contained log file for this token still exists on disk.
     *
     * Deliberately ignores the token's size and mtime: this answers "is the file gone" so the delete
     * path can tell a genuinely absent log apart from one that merely changed since it was listed.
     *
     * @param array $target Internal target.
     * @return bool
     */
    private function abilities_v2_log_target_present( $target ) {
        if ( ! is_array( $target ) || ! isset( $target['basename'] ) || ! is_string( $target['basename'] ) || 1 !== preg_match( '/^backwpup_log_[A-Za-z0-9_.-]+\.html(?:\.gz|\.bz2)?$/D', $target['basename'] ) ) {
            return false;
        }
        $directory = $this->abilities_v2_log_directory();
        $root      = is_string( $directory ) ? realpath( $directory ) : false;
        $file      = false === $root ? false : realpath( trailingslashit( $root ) . $target['basename'] );
        return false !== $file && 0 === strpos( wp_normalize_path( $file ), trailingslashit( wp_normalize_path( $root ) ) ) && is_file( $file );
    }

    /**
     * Stream a bounded plain, gzip, or bzip2 log chunk.
     *
     * @param string $file   File.
     * @param int    $offset Offset.
     * @param int    $length Length.
     * @return array|null
     */
    private function abilities_v2_read_log_chunk( $file, $offset, $length ) { // phpcs:ignore -- Three bounded stream types share one closed adapter.
        $content = '';
        $eof     = false;
        if ( '.gz' === substr( $file, -3 ) ) {
            $handle = gzopen( $file, 'rb' );
            if ( false === $handle || 0 !== gzseek( $handle, $offset ) ) {
                return null;
            }
            $content = gzread( $handle, $length );
            $eof     = gzeof( $handle );
            gzclose( $handle );
        } elseif ( '.bz2' === substr( $file, -4 ) ) {
            if ( ! function_exists( 'bzopen' ) ) {
                return null;
            }
            $handle = bzopen( $file, 'r' );
            if ( ! is_resource( $handle ) ) {
                return null;
            }
            $discarded = 0;
            while ( $discarded < $offset ) {
                $part = bzread( $handle, min( 8192, $offset - $discarded ) );
                if ( ! is_string( $part ) || '' === $part ) {
                    bzclose( $handle );
                    return null;
                }
                $discarded += strlen( $part );
            }
            $content = bzread( $handle, $length );
            $probe   = bzread( $handle, 1 );
            $eof     = '' === $probe;
            bzclose( $handle );
        } else {
            $handle = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Bounded local stream.
            if ( false === $handle || 0 !== fseek( $handle, $offset ) ) {
                return null;
            }
            $content = fread( $handle, $length ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Bounded local stream.
            $eof     = feof( $handle );
            fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Bounded local stream.
        }
        if ( ! is_string( $content ) ) {
            return null;
        }
        return array(
            'content'     => $content,
            'next_offset' => $offset + strlen( $content ),
            'truncated'   => ! $eof,
        );
    }

    /**
     * Remove unsafe content from one bounded log chunk.
     *
     * @param string $content Log content.
     * @return string
     */
    private function abilities_v2_redact_log_content( $content ) {
        $content = wp_strip_all_tags( $content, true );
        $content = preg_replace( '/\b(password|secret|token|api[_ -]?key)\s*[:=]\s*[^\s]+/i', '$1: [redacted]', $content );
        $content = preg_replace( '#\bhttps?://[^\s]+#i', '[redacted-url]', $content );
        $content = preg_replace( '#(?<![A-Za-z0-9_.-])/(?:[^\s/]+/)+[^\s]*#', '[redacted-path]', $content );
        $content = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $content );
        return is_string( $content ) ? $content : '';
    }

    /**
     * Resolve a contained readable BackWPup logfile.
     *
     * @param string $basename Log basename.
     * @return string|null
     */
    private function abilities_v2_resolve_log_file( $basename ) {
        if ( basename( $basename ) !== $basename || strlen( $basename ) > 255 || 1 !== preg_match( '/^[A-Za-z0-9_.-]+$/D', $basename ) ) {
            return null;
        }
        $configured = $this->abilities_v2_log_directory();
        if ( ! is_string( $configured ) || '' === $configured ) {
            return null;
        }
        $directory = trailingslashit( $configured );
        $root      = realpath( $directory );
        if ( false === $root ) {
            return null;
        }
        foreach ( array( $basename, $basename . '.gz', $basename . '.bz2' ) as $candidate_name ) {
            $candidate = realpath( $directory . $candidate_name );
            if ( false !== $candidate && 0 === strpos( wp_normalize_path( $candidate ), trailingslashit( wp_normalize_path( $root ) ) ) && is_file( $candidate ) && is_readable( $candidate ) ) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Compare a stored schedule against a requested one independent of key order.
     *
     * The requested schedule arrives as decoded JSON, so the Dashboard controls its
     * key order, while abilities_v2_read_job_schedule() always emits a fixed order. A
     * strict compare would read an identical schedule sent in a different key order as
     * a change and roll a successful write back.
     *
     * @param mixed $stored    Schedule read from storage.
     * @param mixed $requested Schedule from the request payload.
     * @return bool
     */
    private function abilities_v2_schedule_matches( $stored, $requested ) {
        if ( ! is_array( $stored ) || ! is_array( $requested ) ) {
            return false;
        }
        ksort( $stored );
        ksort( $requested );
        return $stored === $requested;
    }

    /**
     * Normalize stored schedule fields.
     *
     * @param int $job_id Job ID.
     * @return array|null
     */
    private function abilities_v2_read_job_schedule( $job_id ) {
        $active = $this->abilities_v2_get_job_option( $job_id, 'activetype', '' );
        if ( '' === $active ) {
            return array( 'mode' => 'manual' );
        }
        if ( 'wpcron' !== $active ) {
            return null;
        }

        if ( 'advanced' === $this->abilities_v2_get_job_option( $job_id, 'cronselect', 'basic' ) ) {
            $expression = $this->abilities_v2_get_job_option( $job_id, 'cron', '' );
            return $this->abilities_v2_valid_cron_expression( $expression ) ? array(
                'mode'       => 'advanced',
                'expression' => $expression,
            ) : null;
        }

        $frequency = $this->abilities_v2_get_job_option( $job_id, 'frequency', '' );
        if ( in_array( $frequency, array( 'hourly', 'daily', 'weekly', 'monthly' ), true ) ) {
            return $this->abilities_v2_schedule_from_frequency_cron(
                $frequency,
                $this->abilities_v2_get_job_option( $job_id, 'cron', '' )
            );
        }

        $type = $this->abilities_v2_get_job_option( $job_id, 'cronbtype', '' );
        if ( 'hour' === $type ) {
            $minute = $this->abilities_v2_bounded_int( $this->abilities_v2_get_job_option( $job_id, 'hourcronminutes', null ), 0, 59 );
            return null === $minute ? null : array(
                'mode'   => 'hourly',
                'minute' => $minute,
            );
        }
        if ( 'day' === $type ) {
            $hour   = $this->abilities_v2_bounded_int( $this->abilities_v2_get_job_option( $job_id, 'daycronhours', null ), 0, 23 );
            $minute = $this->abilities_v2_bounded_int( $this->abilities_v2_get_job_option( $job_id, 'daycronminutes', null ), 0, 59 );
            return null === $hour || null === $minute ? null : array(
                'mode'   => 'daily',
                'hour'   => $hour,
                'minute' => $minute,
            );
        }
        if ( 'week' === $type ) {
            $weekday = $this->abilities_v2_bounded_int( $this->abilities_v2_get_job_option( $job_id, 'weekcronwday', null ), 0, 6 );
            $hour    = $this->abilities_v2_bounded_int( $this->abilities_v2_get_job_option( $job_id, 'weekcronhours', null ), 0, 23 );
            $minute  = $this->abilities_v2_bounded_int( $this->abilities_v2_get_job_option( $job_id, 'weekcronminutes', null ), 0, 59 );
            return null === $weekday || null === $hour || null === $minute ? null : array(
                'mode'    => 'weekly',
                'weekday' => $weekday,
                'hour'    => $hour,
                'minute'  => $minute,
            );
        }
        if ( 'mon' === $type ) {
            $day    = $this->abilities_v2_bounded_int( $this->abilities_v2_get_job_option( $job_id, 'moncronmday', null ), 1, 31 );
            $hour   = $this->abilities_v2_bounded_int( $this->abilities_v2_get_job_option( $job_id, 'moncronhours', null ), 0, 23 );
            $minute = $this->abilities_v2_bounded_int( $this->abilities_v2_get_job_option( $job_id, 'moncronminutes', null ), 0, 59 );
            return null === $day || null === $hour || null === $minute ? null : array(
                'mode'   => 'monthly',
                'day'    => $day,
                'hour'   => $hour,
                'minute' => $minute,
            );
        }

        return null;
    }

    /**
     * Project BackWPup 5.x frequency plus cron fields into the typed schedule.
     *
     * @param string $frequency BackWPup frequency.
     * @param mixed  $cron      Stored cron expression.
     * @return array|null
     */
    private function abilities_v2_schedule_from_frequency_cron( $frequency, $cron ) {
        if ( ! is_string( $cron ) || 1 !== preg_match( '/^[0-9* ]+$/D', $cron ) ) {
            return null;
        }
        $parts = preg_split( '/\s+/', trim( $cron ) );
        if ( 5 !== count( $parts ) || '*' !== $parts[3] ) {
            return null;
        }

        $minute = $this->abilities_v2_bounded_int( $parts[0], 0, 59 );
        if ( 'hourly' === $frequency && null !== $minute && '*' === $parts[1] && '*' === $parts[2] && '*' === $parts[4] ) {
            return array(
                'mode'   => 'hourly',
                'minute' => $minute,
            );
        }

        $hour = $this->abilities_v2_bounded_int( $parts[1], 0, 23 );
        if ( 'daily' === $frequency && null !== $minute && null !== $hour && '*' === $parts[2] && '*' === $parts[4] ) {
            return array(
                'mode'   => 'daily',
                'hour'   => $hour,
                'minute' => $minute,
            );
        }

        $weekday = $this->abilities_v2_bounded_int( $parts[4], 0, 6 );
        if ( 'weekly' === $frequency && null !== $minute && null !== $hour && '*' === $parts[2] && null !== $weekday ) {
            return array(
                'mode'    => 'weekly',
                'weekday' => $weekday,
                'hour'    => $hour,
                'minute'  => $minute,
            );
        }

        $day = $this->abilities_v2_bounded_int( $parts[2], 1, 31 );
        if ( 'monthly' === $frequency && null !== $minute && null !== $hour && null !== $day && '*' === $parts[4] ) {
            return array(
                'mode'   => 'monthly',
                'day'    => $day,
                'hour'   => $hour,
                'minute' => $minute,
            );
        }

        return null;
    }

    /**
     * Convert a typed schedule to the existing BackWPup option fields.
     *
     * @param array $schedule Typed schedule.
     * @return array|null
     */
    private function abilities_v2_schedule_options( $schedule ) { // phpcs:ignore -- Closed conversion table is clearer in one method.
        if ( ! isset( $schedule['mode'] ) || ! is_string( $schedule['mode'] ) ) {
            return null;
        }
        if ( 'manual' === $schedule['mode'] && $this->abilities_v2_has_keys( $schedule, array( 'mode' ) ) ) {
            return array(
                'activetype' => '',
                'cronselect' => 'basic',
                'cronbtype'  => '',
                'cron'       => '',
            );
        }
        if ( 'hourly' === $schedule['mode'] && $this->abilities_v2_has_keys( $schedule, array( 'mode', 'minute' ) ) && $this->abilities_v2_is_bounded_int( $schedule['minute'], 0, 59 ) ) {
            return array(
                'activetype'      => 'wpcron',
                'cronselect'      => 'basic',
                'cronbtype'       => 'hour',
                'frequency'       => 'hourly',
                'hourcronminutes' => (string) $schedule['minute'],
                'cron'            => $schedule['minute'] . ' * * * *',
            );
        }
        if ( 'daily' === $schedule['mode'] && $this->abilities_v2_has_keys( $schedule, array( 'mode', 'hour', 'minute' ) ) && $this->abilities_v2_is_bounded_int( $schedule['hour'], 0, 23 ) && $this->abilities_v2_is_bounded_int( $schedule['minute'], 0, 59 ) ) {
            return array(
                'activetype'     => 'wpcron',
                'cronselect'     => 'basic',
                'cronbtype'      => 'day',
                'frequency'      => 'daily',
                'daycronhours'   => (string) $schedule['hour'],
                'daycronminutes' => (string) $schedule['minute'],
                'cron'           => $schedule['minute'] . ' ' . $schedule['hour'] . ' * * *',
            );
        }
        if ( 'weekly' === $schedule['mode'] && $this->abilities_v2_has_keys( $schedule, array( 'mode', 'weekday', 'hour', 'minute' ) ) && $this->abilities_v2_is_bounded_int( $schedule['weekday'], 0, 6 ) && $this->abilities_v2_is_bounded_int( $schedule['hour'], 0, 23 ) && $this->abilities_v2_is_bounded_int( $schedule['minute'], 0, 59 ) ) {
            return array(
                'activetype'      => 'wpcron',
                'cronselect'      => 'basic',
                'cronbtype'       => 'week',
                'frequency'       => 'weekly',
                'weekcronwday'    => (string) $schedule['weekday'],
                'weekcronhours'   => (string) $schedule['hour'],
                'weekcronminutes' => (string) $schedule['minute'],
                'cron'            => $schedule['minute'] . ' ' . $schedule['hour'] . ' * * ' . $schedule['weekday'],
            );
        }
        if ( 'monthly' === $schedule['mode'] && $this->abilities_v2_has_keys( $schedule, array( 'mode', 'day', 'hour', 'minute' ) ) && $this->abilities_v2_is_bounded_int( $schedule['day'], 1, 31 ) && $this->abilities_v2_is_bounded_int( $schedule['hour'], 0, 23 ) && $this->abilities_v2_is_bounded_int( $schedule['minute'], 0, 59 ) ) {
            return array(
                'activetype'     => 'wpcron',
                'cronselect'     => 'basic',
                'cronbtype'      => 'mon',
                'frequency'      => 'monthly',
                'moncronmday'    => (string) $schedule['day'],
                'moncronhours'   => (string) $schedule['hour'],
                'moncronminutes' => (string) $schedule['minute'],
                'cron'           => $schedule['minute'] . ' ' . $schedule['hour'] . ' ' . $schedule['day'] . ' * *',
            );
        }
        if ( 'advanced' === $schedule['mode'] && $this->abilities_v2_has_keys( $schedule, array( 'mode', 'expression' ) ) && $this->abilities_v2_valid_cron_expression( $schedule['expression'] ) ) {
            $parts = preg_split( '/\s+/', trim( $schedule['expression'] ) );
            return array(
                'activetype'  => 'wpcron',
                'cronselect'  => 'advanced',
                'cronbtype'   => '',
                'cronminutes' => explode( ',', $parts[0] ),
                'cronhours'   => explode( ',', $parts[1] ),
                'cronmday'    => explode( ',', $parts[2] ),
                'cronmon'     => explode( ',', $parts[3] ),
                'cronwday'    => explode( ',', $parts[4] ),
                'cron'        => implode( ' ', $parts ),
            );
        }

        return null;
    }

    /**
     * Validate a five-field cron expression with field bounds.
     *
     * @param mixed $expression Cron expression.
     * @return bool
     */
    private function abilities_v2_valid_cron_expression( $expression ) {
        if ( ! is_string( $expression ) || strlen( $expression ) < 9 || strlen( $expression ) > 128 || 1 !== preg_match( '/^[0-9*,\/ -]+$/D', $expression ) ) {
            return false;
        }
        $parts  = preg_split( '/\s+/', trim( $expression ) );
        $bounds = array( array( 0, 59 ), array( 0, 23 ), array( 1, 31 ), array( 1, 12 ), array( 0, 6 ) );
        if ( 5 !== count( $parts ) ) {
            return false;
        }
        foreach ( $parts as $index => $part ) {
            foreach ( explode( ',', $part ) as $piece ) {
                if ( ! $this->abilities_v2_valid_cron_piece( $piece, $bounds[ $index ][0], $bounds[ $index ][1] ) ) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Validate one cron list member.
     *
     * @param string $piece Cron member.
     * @param int    $min   Minimum value.
     * @param int    $max   Maximum value.
     * @return bool
     */
    private function abilities_v2_valid_cron_piece( $piece, $min, $max ) {
        if ( '*' === $piece ) {
            return true;
        }
        $step_parts = explode( '/', $piece );
        if ( count( $step_parts ) > 2 || ( 2 === count( $step_parts ) && ( ! ctype_digit( $step_parts[1] ) || 1 > (int) $step_parts[1] || $max < (int) $step_parts[1] ) ) ) {
            return false;
        }
        if ( '*' === $step_parts[0] ) {
            return 2 === count( $step_parts );
        }
        $range = explode( '-', $step_parts[0] );
        if ( count( $range ) > 2 ) {
            return false;
        }
        foreach ( $range as $value ) {
            if ( ! ctype_digit( $value ) || ! $this->abilities_v2_is_bounded_int( (int) $value, $min, $max ) ) {
                return false;
            }
        }
        return 1 === count( $range ) || (int) $range[0] <= (int) $range[1];
    }

    /**
     * Restore a failed schedule update best-effort.
     *
     * @param int   $job_id   Job ID.
     * @param array $snapshot Original fields.
     */
    private function abilities_v2_restore_job_options( $job_id, $snapshot ) {
        foreach ( $snapshot as $key => $value ) {
            try {
                $this->abilities_v2_set_job_option( $job_id, $key, $value );
            } catch ( \Throwable $e ) {
                return;
            }
        }
        try {
            $this->abilities_v2_refresh_job_schedule( $job_id );
        } catch ( \Throwable $e ) {
            return;
        }
    }

    /**
     * Return BackWPup job IDs.
     *
     * @return int[]
     */
    protected function abilities_v2_get_job_ids() {
        if ( ! class_exists( '\\BackWPup_Option' ) ) {
            return array();
        }
        $ids = \BackWPup_Option::get_job_ids();
        return is_array( $ids ) ? array_values( array_map( 'intval', $ids ) ) : array();
    }

    /**
     * Read a BackWPup job option.
     *
     * @param int    $job_id  Job ID.
     * @param string $key     Option key.
     * @param mixed  $default Default value.
     * @return mixed
     */
    protected function abilities_v2_get_job_option( $job_id, $key, $default = null ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.defaultFound -- Mirrors BackWPup_Option::get().
        return \BackWPup_Option::get( $job_id, $key, $default );
    }

    /**
     * Write a BackWPup job option.
     *
     * @param int    $job_id Job ID.
     * @param string $key    Option key.
     * @param mixed  $value  Value.
     */
    protected function abilities_v2_set_job_option( $job_id, $key, $value ) {
        \BackWPup_Option::update( $job_id, $key, $value );
    }

    /**
     * Refresh BackWPup's schedule after option changes.
     *
     * @param int $job_id Job ID.
     */
    protected function abilities_v2_refresh_job_schedule( $job_id ) {
        if ( class_exists( '\\BackWPup_Job' ) && method_exists( '\\BackWPup_Job', 'enable_job' ) ) {
            \BackWPup_Job::enable_job( $job_id );
        }
    }

    /**
     * Check an exact unordered key set.
     *
     * @param mixed $value    Candidate object.
     * @param array $expected Expected keys.
     * @return bool
     */
    private function abilities_v2_has_keys( $value, $expected ) {
        if ( ! is_array( $value ) ) {
            return false;
        }
        $keys = array_keys( $value );
        sort( $keys, SORT_STRING );
        sort( $expected, SORT_STRING );
        return $keys === $expected;
    }

    /**
     * Convert one canonical decimal value within bounds.
     *
     * @param mixed $value Value.
     * @param int   $min   Minimum.
     * @param int   $max   Maximum.
     * @return int|null
     */
    private function abilities_v2_bounded_int( $value, $min, $max ) {
        if ( is_int( $value ) ) {
            return $this->abilities_v2_is_bounded_int( $value, $min, $max ) ? $value : null;
        }
        if ( ! is_string( $value ) || 1 !== preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) ) {
            return null;
        }
        $int = (int) $value;
        return $this->abilities_v2_is_bounded_int( $int, $min, $max ) && (string) $int === $value ? $int : null;
    }

    /**
     * Check an integer range.
     *
     * @param mixed $value Value.
     * @param int   $min   Minimum.
     * @param int   $max   Maximum.
     * @return bool
     */
    private function abilities_v2_is_bounded_int( $value, $min, $max ) {
        return is_int( $value ) && $min <= $value && $max >= $value;
    }

    /**
     * Build a closed typed protocol success.
     *
     * @param string $operation Operation name.
     * @param array  $data      Closed result data.
     * @return array
     */
    private function abilities_v2_success( $operation, $data ) {
        return array(
            'protocol'  => '2',
            'operation' => $operation,
            'ok'        => true,
            'data'      => $data,
        );
    }

    /**
     * Build a closed typed protocol error.
     *
     * @param string $operation Operation name.
     * @param string $code      Stable error code.
     * @param string $message   Safe message.
     * @return array
     */
    private function abilities_v2_error( $operation, $code, $message ) {
        // The Dashboard reads the reserved top-level `error` key as a scalar string, so the
        // protocol code stays a top-level scalar here like every other v2 bridge.
        return array(
            'protocol'  => '2',
            'operation' => is_string( $operation ) ? substr( sanitize_key( $operation ), 0, 64 ) : '',
            'ok'        => false,
            'code'      => substr( sanitize_key( $code ), 0, 64 ),
            'message'   => substr( sanitize_text_field( $message ), 0, 1000 ),
        );
    }

    /**
     * MainWP BackWPup Extension initiation.
     */
    public function init() {

        if ( ! $this->is_backwpup_installed ) {
            return;
        }

        // Migrate legacy BackWPup cron.
        self::migrate_backwpup_cron_args();

        add_action( 'mainwp_child_site_stats', array( $this, 'do_site_stats' ) );

        if ( get_option( 'mainwp_backwpup_hide_plugin' ) === 'hide' ) {
            add_filter( 'all_plugins', array( $this, 'all_plugins' ) );
            add_action( 'admin_menu', array( $this, 'remove_menu' ) );
        }
    }

    /**
     * Migrate legacy BackWPup cron args to positional arrays to avoid PHP 8 named parameter fatals.
     */
    public static function migrate_backwpup_cron_args(): void {  // phpcs:ignore -- NOSONAR
        if ( ! function_exists( '_get_cron_array' ) ) {
            return;
        }

        $crons = _get_cron_array();
        if ( empty( $crons ) || ! is_array( $crons ) ) {
            return;
        }

        foreach ( $crons as $timestamp => $hooks ) {
            if ( empty( $hooks['backwpup_cron'] ) || ! is_array( $hooks['backwpup_cron'] ) ) {
                continue;
            }

            foreach ( $hooks['backwpup_cron'] as $event ) {
                $args = isset( $event['args'] ) ? $event['args'] : array();
                if ( ! is_array( $args ) || count( $args ) !== 1 ) {
                    continue;
                }

                $keys = array_keys( $args );
                if ( empty( $keys ) || ! is_string( $keys[0] ) ) {
                    continue;
                }

                $key = $keys[0];
                if ( 'arg' !== $key && 'id' !== $key ) {
                    continue;
                }

                $new_args = array( $args[ $key ] );

                if ( function_exists( 'wp_get_scheduled_event' ) ) {
                    $existing = wp_get_scheduled_event( 'backwpup_cron', $new_args, (int) $timestamp );
                    if ( $existing ) {
                        wp_unschedule_event( (int) $timestamp, 'backwpup_cron', $args );
                        continue;
                    }
                }

                wp_unschedule_event( (int) $timestamp, 'backwpup_cron', $args );
                $schedule = isset( $event['schedule'] ) ? $event['schedule'] : false;
                if ( $schedule ) {
                    wp_schedule_event( (int) $timestamp, $schedule, 'backwpup_cron', $new_args );
                } else {
                    wp_schedule_single_event( (int) $timestamp, 'backwpup_cron', $new_args );
                }
            }
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
            $lasttime_logged = (int) MainWP_Utility::get_lasttime_backup( 'backwpup' );
            $scan_from       = max( 0, $lasttime_logged - ( 7 * DAY_IN_SECONDS ) );
            $scan_until      = time();
            $log_folder      = get_site_option( 'backwpup_cfg_logfolder' );
            $log_folder      = \BackWPup_File::get_absolute_path( $log_folder );
            $log_folder      = untrailingslashit( $log_folder );
            $logfiles        = array();
            $dir             = false;
            if ( is_dir( $log_folder ) && is_readable( $log_folder ) ) {
                $dir = opendir( $log_folder );
            }
            if ( $dir ) {
                while ( ( $file = readdir( $dir ) ) !== false ) {
                    $log_file = $log_folder . '/' . $file;
                    if ( is_file( $log_file ) && is_readable( $log_file ) && false !== strpos( $file, 'backwpup_log_' ) && false !== strpos( $file, '.html' ) ) {
                        $logfiles[] = $log_file;
                    }
                }
                closedir( $dir );
            }

            // BackWPup stores logs in uploads/backwpup/{hash}/logs on version.
            $upload_dir   = wp_upload_dir();
            $search_roots = array_unique(
                array_filter(
                    array(
                        $log_folder,
                        trailingslashit( $upload_dir['basedir'] ) . 'backwpup',
                    )
                )
            );

            foreach ( $search_roots as $search_root ) {
                if ( ! is_dir( $search_root ) || ! class_exists( '\RecursiveDirectoryIterator' ) ) {
                    continue;
                }

                try {
                    $iterator = new \RecursiveIteratorIterator(
                        new \RecursiveDirectoryIterator( $search_root, \FilesystemIterator::SKIP_DOTS )
                    );
                    foreach ( $iterator as $nested_file ) {
                        if ( ! $nested_file->isFile() || ! $nested_file->isReadable() ) {
                            continue;
                        }

                        $filename = $nested_file->getFilename();
                        if ( 0 === strpos( $filename, 'backwpup_log_' ) && false !== strpos( $filename, '.html' ) ) {
                            $logfiles[] = $nested_file->getPathname();
                        }
                    }
                } catch ( \UnexpectedValueException $exception ) {
                    // A missing or unreadable nested directory must not stop
                    // other log roots from being scanned.
                    unset( $exception );
                }
            }
            $logfiles           = array_values( array_unique( $logfiles ) );
            $log_items          = array();
            $can_advance_cursor = true;
            foreach ( $logfiles as $mtime => $logfile ) {
                // RecursiveDirectoryIterator hands back native separators, so on Windows an
                // absolute nested log path carries no '/' and used to be prefixed with the log
                // folder, producing a path filemtime() rejects and silently dropping the log.
                $normalized = wp_normalize_path( (string) $logfile );
                $log_path   = false !== strpos( $normalized, '/' ) ? $normalized : $log_folder . '/' . $normalized;
                $file_mtime = filemtime( $log_path );
                if ( false === $file_mtime || $file_mtime < $scan_from || $file_mtime > $scan_until ) {
                    continue;
                }

                $meta = \BackWPup_Job::read_logheader( $log_path );
                if ( ! is_array( $meta ) || ! isset( $meta['logtime'], $meta['type'] ) || '' === (string) $meta['type'] ) {
                    $can_advance_cursor = false;
                    continue;
                }
                if ( $meta['logtime'] < $scan_from || $meta['logtime'] > $scan_until ) {
                    continue;
                }

                if ( isset( $meta['errors'] ) && ! empty( $meta['errors'] ) ) {
                    continue;
                }

                $log_items[ $mtime ]         = $meta;
                $log_items[ $mtime ]['file'] = basename( $logfile );
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
                    $backup_time   = $log['logtime'];
                    $job_job_types = explode( '+', $log['type'] );
                    $backup_type   = '';
                    foreach ( $job_job_types as $typeid ) {
                        if ( isset( $job_types[ $typeid ] ) ) {
                            $backup_type .= ' + ' . $job_types[ $typeid ];
                        }
                    }

                    if ( empty( $backup_type ) ) {
                        $can_advance_cursor = false;
                        continue;
                    } else {
                        $backup_type = ltrim( $backup_type, ' + ' );
                    }
                    $message     = 'BackWPup backup finished (' . $backup_type . ')';
                    $fingerprint = MainWP_Utility::backup_fingerprint(
                        'backwpup',
                        isset( $log['jobid'] ) ? $log['jobid'] : 'unknown',
                        $log['file']
                    );
                    do_action( 'mainwp_reports_backwpup_backup', $message, $backup_type, $backup_time, $fingerprint );

                    if ( ! MainWP_Utility::backup_fingerprint_logged( $fingerprint ) ) {
                        $can_advance_cursor = false;
                        continue;
                    }

                    if ( $new_lasttime_logged < $backup_time ) {
                        $new_lasttime_logged = $backup_time;
                    }
                }

                if ( $can_advance_cursor && $new_lasttime_logged > $lasttime_logged ) {
                    MainWP_Utility::update_lasttime_backup( 'backwpup', $new_lasttime_logged ); // to support backup before update feature.
                }
            }
        } catch ( MainWP_Exception $ex ) {
            // Keep the existing behavior: an invalid BackWPup installation must not interrupt the child request.
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
            echo '<tr title=""><td>' . esc_html__( 'BackWPup version', 'mainwp-child' ) . '</td><td>' . esc_html( \BackWPup::get_plugin_data( 'Version' ) ) . ' <a href="' . esc_url( \BackWPup::get_plugin_data( 'pluginuri' ) ) . '">' . esc_html__( 'Get pro.', 'mainwp-child' ) . '</a></td></tr>';
        } else {
            echo '<tr title=""><td>' . esc_html__( 'BackWPup Pro version', 'mainwp-child' ) . '</td><td>' . esc_html( \BackWPup::get_plugin_data( 'Version' ) ) . '</td></tr>';
        }

        echo '<tr title="&gt;=5.3.3"><td>' . esc_html__( 'PHP version', 'mainwp-child' ) . '</td><td>' . esc_html( PHP_VERSION ) . '</td></tr>';
        $mysql_version = wp_cache_get( 'mainwp_backwpup_mysql_version', 'mainwp_backwpup' );
        if ( false === $mysql_version ) {
            $mysql_version = $wpdb->get_var( 'SELECT VERSION() AS version' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for BackWPup integration; no core API for MySQL version. Query is static with no dynamic parameters and results are cached.
            wp_cache_set( 'mainwp_backwpup_mysql_version', $mysql_version, 'mainwp_backwpup', 3600 );
        }
        echo '<tr title="&gt;=5.0.7"><td>' . esc_html__( 'MySQL version', 'mainwp-child' ) . '</td><td>' . esc_html( $mysql_version ) . '</td></tr>';
        if ( function_exists( 'curl_version' ) ) {
            $curlversion = curl_version();
            echo '<tr title=""><td>' . esc_html__( 'cURL version', 'mainwp-child' ) . '</td><td>' . esc_html( $curlversion['version'] ) . '</td></tr>';
            echo '<tr title=""><td>' . esc_html__( 'cURL SSL version', 'mainwp-child' ) . '</td><td>' . esc_html( $curlversion['ssl_version'] ) . '</td></tr>';
        } else {
            echo '<tr title=""><td>' . esc_html__( 'cURL version', 'mainwp-child' ) . '</td><td>' . esc_html__( 'unavailable', 'mainwp-child' ) . '</td></tr>';
        }
        echo '<tr title=""><td>' . esc_html__( 'WP-Cron url:', 'mainwp-child' ) . '</td><td>' . esc_html( site_url( 'wp-cron.php' ) ) . '</td></tr>';

        echo '<tr><td>' . esc_html__( 'Server self connect:', 'mainwp-child' ) . '</td><td>';
        $test_result = '';
        if ( ! $this->use_legacy_backwpup_handler() ) {
            $raw_response = \BackWPup_Job::get_jobrun_url( 'test' );
            if ( is_wp_error( $raw_response ) ) {
                // translators: %s: error message.
                $test_result .= sprintf( esc_html__( 'The HTTP response test get an error "%s"', 'mainwp-child' ), esc_html( $raw_response->get_error_message() ) );
            } elseif ( 200 !== (int) wp_remote_retrieve_response_code( $raw_response ) && 204 !== (int) wp_remote_retrieve_response_code( $raw_response ) ) {
                // translators: %s: HTTP status code.
                $test_result .= sprintf( esc_html__( 'The HTTP response test get a false http status (%s)', 'mainwp-child' ), esc_html( wp_remote_retrieve_response_code( $raw_response ) ) );
            }
            $headers = wp_remote_retrieve_headers( $raw_response );
            if ( isset( $headers['x-backwpup-ver'] ) && \BackWPup::get_plugin_data( 'version' ) !== $headers['x-backwpup-ver'] ) {
                // translators: %s: header value.
                $test_result .= sprintf( esc_html__( 'The BackWPup HTTP response header returns a false value: "%s"', 'mainwp-child' ), esc_html( $headers['x-backwpup-ver'] ) );
            }
        } else {
            $test_result = esc_html__( 'Unavailable in this BackWPup version.', 'mainwp-child' );
        }

        if ( empty( $test_result ) ) {
            esc_html_e( 'Response Test O.K.', 'mainwp-child' );
        } else {
            echo esc_html( $test_result );
        }
        echo '</td></tr>';

        echo '<tr><td>' . esc_html__( 'Temp folder:', 'mainwp-child' ) . '</td><td>';
        if ( ! is_dir( \BackWPup::get_plugin_data( 'TEMP' ) ) ) {
            // translators: %s: folder path.
            printf( esc_html__( 'Temp folder %s doesn\'t exist.', 'mainwp-child' ), esc_html( \BackWPup::get_plugin_data( 'TEMP' ) ) );
        } elseif ( ! is_writable( \BackWPup::get_plugin_data( 'TEMP' ) ) ) { //phpcs:ignore -- NOSONAR - ok.
            // translators: %s: folder path.
            printf( esc_html__( 'Temporary folder %s is not writable.', 'mainwp-child' ), esc_html( \BackWPup::get_plugin_data( 'TEMP' ) ) );
        } else {
            echo esc_html( \BackWPup::get_plugin_data( 'TEMP' ) );
        }
        echo '</td></tr>';

        echo '<tr><td>' . esc_html__( 'Log folder:', 'mainwp-child' ) . '</td><td>';

        $log_folder = \BackWPup_File::get_absolute_path( get_site_option( 'backwpup_cfg_logfolder' ) );

        if ( ! is_dir( $log_folder ) ) {
            // translators: %s: folder path.
            printf( esc_html__( 'Logs folder %s not exist.', 'mainwp-child' ), esc_html( $log_folder ) );
        } elseif ( ! is_writable( $log_folder ) ) { //phpcs:ignore -- NOSONAR - ok.
            // translators: %s: folder path.
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
        // translators: %s: number of hours (GMT offset).
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

        wp_clear_scheduled_hook( 'backwpup_cron', array( $job_id ) );
        wp_clear_scheduled_hook( 'backwpup_cron', array( 'arg' => $job_id ) );
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

        $array       = array();
        $is_global   = isset( $_POST['settings']['is_global'] ) ? intval( wp_unslash( $_POST['settings']['is_global'] ) ) : 0;  // phpcs:ignore -- NOSONAR
        $global_ids  = $this->get_all_global_backwpup_job_ids();
        $default_ids = $this->get_default_backwpup_job_ids();

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
                    function ( $log ) use ( $global_ids, $default_ids, $is_global ) {
                        $job_id   = intval( $log['jobid'] );
                        $temp_job = (bool) \BackWPup_Option::get( $job_id, 'tempjob', false );
                        if ( $temp_job && ! in_array( $job_id, $default_ids, true ) ) {
                            return false;
                        }
                        if ( 0 === $is_global ) {
                            return in_array( $job_id, $global_ids, true ) || in_array( $job_id, $default_ids, true );
                        }
                        return ! empty( $job_id );
                    }
                );
                $output->items = $logs ?? array();
                break;

            case 'backups':
                update_user_option( get_current_user_id(), 'backwpupbackups_per_page', 99999999 );
                $output        = new \BackWPup_Page_Backups();
                $output->items = array();

                $jobids            = \BackWPup_Option::get_job_ids();
                $requested_job_ids = array();
                $has_job_filter    = isset( $_POST['settings']['job_ids'] ) && is_array( $_POST['settings']['job_ids'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

                if ( $has_job_filter ) {
                    $requested_job_ids = array_map( 'intval', wp_unslash( $_POST['settings']['job_ids'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing
                    $requested_job_ids = array_values( array_filter( array_unique( $requested_job_ids ) ) );
                }

                if ( $has_job_filter && empty( $requested_job_ids ) ) {
                    $jobids = array();
                } elseif ( ! empty( $requested_job_ids ) && is_array( $jobids ) ) {
                    $jobids = array_values( array_intersect( array_map( 'intval', $jobids ), $requested_job_ids ) );
                }

                if ( ! empty( $jobids ) ) {
                    foreach ( $jobids as $jobid ) {
                        if ( \BackWPup_Option::get( $jobid, 'backuptype' ) === 'sync' ) {
                            continue;
                        }
                        $dests = \BackWPup_Option::get( $jobid, 'destinations' );
                        if ( empty( $dests ) || ! is_array( $dests ) ) {
                            continue;
                        }
                        foreach ( $dests as $dest ) {
                            $dest_class = \BackWPup::get_destination( $dest );
                            if ( empty( $dest_class ) || ! is_object( $dest_class ) || ! method_exists( $dest_class, 'file_get_list' ) ) {
                                continue;
                            }
                            try {
                                $items = $dest_class->file_get_list( $jobid . '_' . $dest );
                            } catch ( \Throwable $e ) {
                                continue;
                            }
                            if ( ! empty( $items ) ) {
                                foreach ( $items as $item ) {
                                    if ( ! is_array( $item ) || ! isset( $item['time'] ) ) {
                                        continue;
                                    }
                                    $temp_single_item         = $item;
                                    $temp_single_item['dest'] = $jobid . '_' . $dest;
                                    // translators: 1: date, 2: time.
                                    // Use the child site's timezone explicitly so Dashboard and BackWPup show the same time.
                                    $backup_timezone               = wp_timezone();
                                    $temp_single_item['timeloc']   = sprintf( esc_html__( '%1$s at %2$s', 'mainwp-child' ), wp_date( get_option( 'date_format' ), $temp_single_item['time'], $backup_timezone ), wp_date( get_option( 'time_format' ), $temp_single_item['time'], $backup_timezone ) );
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

        if ( isset( $output->items ) && is_array( $output->items ) ) {
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
                        $nextrun = wp_next_scheduled( 'backwpup_cron', array( $val ) );
                        if ( $nextrun + ( get_option( 'gmt_offset' ) * 3600 ) ) {
                            // translators: 1: date, 2: time.
                            $temp_array['nextrun'] = sprintf( esc_html__( '%1$s at %2$s by WP-Cron', 'mainwp-child' ), date_i18n( get_option( 'date_format' ), $nextrun, true ), date_i18n( get_option( 'time_format' ), $nextrun, true ) );
                        } else {
                            $temp_array['nextrun'] = esc_html__( 'Not scheduled!', 'mainwp-child' );
                        }
                    } else {
                        $temp_array['nextrun'] = esc_html__( 'Inactive', 'mainwp-child' );
                    }
                    if ( \BackWPup_Option::get( $val, 'lastrun' ) ) {
                        $lastrun = \BackWPup_Option::get( $val, 'lastrun' );
                        // translators: 1: date, 2: time.
                        $temp_array['lastrun'] = sprintf( esc_html__( '%1$s at %2$s', 'mainwp-child' ), date_i18n( get_option( 'date_format' ), $lastrun, true ), date_i18n( get_option( 'time_format' ), $lastrun, true ) );
                        if ( \BackWPup_Option::get( $val, 'lastruntime' ) ) {
                            // translators: %d: number of seconds.
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

                    $dedupe_key_parts = array(
                        isset( $temp_array['file'] ) ? $temp_array['file'] : '',
                        isset( $temp_array['filesize'] ) ? $temp_array['filesize'] : '',
                        isset( $temp_array['timestamp'] ) ? $temp_array['timestamp'] : '',
                    );
                    $dedupe_key       = md5( implode( '|', array_map( 'strval', $dedupe_key_parts ) ) );  // phpcs:ignore -- NOSONAR

                    if ( ! isset( $without_dupes[ $dedupe_key ] ) ) {
                        $array[]                      = $temp_array;
                        $without_dupes[ $dedupe_key ] = 1;
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
        if ( empty( $page ) || 'backwpupbackups' !== $page ) {
            return;
        }
        ?>
        <script type="text/javascript">
            var dlClicked = false;
            var download_click_id = <?php echo intval( $_GET['download_click_id'] );  // phpcs:ignore -- NOSONAR ?>;
            document.addEventListener("DOMContentLoaded", function (event) {
                if (dlClicked === false) {
                    var downloadLink = document.querySelector(`a.backup-download-link[data-jobid="${download_click_id}"]`);
                    if (typeof (downloadLink) !== 'undefined' && downloadLink !== null) {
                        downloadLink.click();
                        dlClicked = true;
                    }
                    if (dlClicked === false) { // for new version.
                        setTimeout(
                            function () {
                                downloadLink = document.querySelector(`button.js-backwpup-download-backup[data-jobid="${download_click_id}"]`);
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

        if ( ! current_user_can( 'backwpup_backups_download' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- custom capability registered by the BackWPup plugin.
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
     * Determine whether the installed BackWPup supports the modern job API.
     *
     * Older releases use the Page Jobs handlers and read values from $_GET.
     * Newer releases expose get_jobrun_url() and use filter_input() internally.
     *
     * @return bool True when the legacy Page Jobs flow is required.
     */
    protected function use_legacy_backwpup_handler() {
        return ! method_exists( '\BackWPup_Job', 'get_jobrun_url' );
    }

    /**
     * BackWPup Ajax Working.
     *
     * @uses MainWP_Child_Back_WP_Up::wp_list_table_dependency()
     * @uses \BackWPup_Option::get()
     * @uses MainWP_Utility::get_lasttime_backup()
     *
     * @return array Return success array[ success, response ]
     */
    protected function ajax_working() {
        // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $settings = $_POST['settings'] ?? array();
        if ( ! is_array( $settings ) || ! isset( $settings['logfile'], $settings['logpos'], $settings['job_id'] ) ) {
            return array(
                'error' => esc_html__( 'Missing logfile, logpos or job_id.', 'mainwp-child' ),
            );
        }

        // Get job log file.
        $job_id      = absint( $settings['job_id'] );
        $job_logfile = \BackWPup_Option::get( $job_id, 'logfile' );
        $logfile     = basename( $job_logfile );

        $this->wp_list_table_dependency();

        $logfile = ! empty( $settings['logfile'] ) ? basename( wp_unslash( $settings['logfile'] ) ) : $logfile;
        $logpos  = ! empty( $settings['logpos'] ) ? absint( wp_unslash( $settings['logpos'] ) ) : 0;

        $output = $this->use_legacy_backwpup_handler() ? $this->ajax_working_legacy( $logfile, $logpos ) : $this->get_backwpup_working_response( $logfile, $logpos );

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
     * Backup working using the legacy BackWPup handler.
     *
     * @param string $logfile Log file basename.
     * @param int    $logpos  Current log position.
     * @uses \BackWPup_Page_Jobs::ajax_working()
     *
     * @return string Buffered response from BackWPup.
     */
    private function ajax_working_legacy( $logfile, $logpos ) {
        $_GET['logfile'] = $logfile;
        $_GET['logpos']  = $logpos;

        $ajax_nonce              = wp_create_nonce( 'backwpupworking_ajax_nonce' );
        $_REQUEST['_ajax_nonce'] = $ajax_nonce;
        $_REQUEST['_wpnonce']    = $ajax_nonce;

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

        $buffer_level = ob_get_level();
        ob_start();
        try {
            \BackWPup_Page_Jobs::ajax_working();
            return ob_get_clean();
        } catch ( \Throwable $e ) {
            while ( ob_get_level() > $buffer_level ) {
                ob_end_clean();
            }

            return wp_json_encode(
                array(
                    'error' => $e->getMessage(),
                )
            );
        }
    }

    /**
     * Build the BackWPup working response without making a loopback HTTP request.
     *
     * @param string $logfile Log file basename.
     * @param int    $logpos  Current log position.
     *
     * @uses \BackWPup_File::get_absolute_path()
     * @uses \WPMedia\BackWPup\Log\LogFacade()
     *
     * @return string JSON-encoded working response.
     */
    protected function get_backwpup_working_response( $logfile, $logpos ) {
        $log_folder = untrailingslashit(
            \BackWPup_File::get_absolute_path(
                get_site_option( 'backwpup_cfg_logfolder' )
            )
        );

        $logfile = $log_folder . '/' . basename( $logfile );
        if ( file_exists( $logfile . '.gz' ) ) {
            $logfile .= '.gz';
        }

        if ( ! is_readable( $logfile ) ) {
            return wp_json_encode( 0 );
        }

        $working_data = $this->get_backwpup_working_data( $logfile );
        $filesystem   = backwpup_wpfilesystem();

        $log_data = '.gz' === substr( $logfile, -3 ) ? $filesystem->get_contents( 'compress.zlib://' . $logfile ) : $filesystem->get_contents( $logfile );
        $log_data = substr( (string) $log_data, $logpos );

        preg_match( '/<body[^>]*>/si', $log_data, $match );

        $startpos = ! empty( $match[0] ) ? strpos( $log_data, $match[0] ) + strlen( $match[0] ) : 0;
        $endpos   = stripos( $log_data, '</body>' );

        if ( false === $endpos ) {
            $endpos = strlen( $log_data );
        }

        // Extract the log text between the <body> and </body> tags.
        $log_text = substr( $log_data, $startpos, $endpos - $startpos );

        if ( method_exists( '\WPMedia\BackWPup\Log\LogFacade', 'render_html' ) ) {
            $log_text = ( new \WPMedia\BackWPup\Log\LogFacade() )->render_html( $log_text );
        }

        $data = array(
            'log_pos'          => strlen( $log_data ) + $logpos,
            'log_text'         => $log_text,
            'warning_count'    => $working_data['warnings'],
            'error_count'      => $working_data['errors'],
            'running_time'     => $working_data['runtime'],
            'step_percent'     => $working_data['step_percent'],
            'on_step'          => $working_data['onstep'],
            'last_msg'         => $working_data['lastmsg'],
            'last_error_msg'   => $working_data['lasterrormsg'],
            'sub_step_percent' => $working_data['substep_percent'],
            'restart_url'      => '',
            'job_done'         => $working_data['done'],
            'step_done'        => $working_data['step_done'],
            'step_todo'        => $working_data['step_todo'],
            'substeps_todo'    => $working_data['substeps_todo'],
            'substeps_done'    => $working_data['substeps_done'],
            'job_id'           => $working_data['job_id'],
        );

        return wp_json_encode( $data );
    }

    /**
     * Get the current BackWPup working data.
     *
     * Falls back to the log header when no active job is available.
     *
     * @param string $logfile Log file path.
     * @uses \BackWPup_Job::get_working_data()
     * @uses \BackWPup_Job::read_logheader()
     *
     * @return array Working job data.
     */
    private function get_backwpup_working_data( $logfile ) {
        $job_object = \BackWPup_Job::get_working_data();

        if ( ! is_object( $job_object ) ) {

            $logheader = \BackWPup_Job::read_logheader( $logfile );
            $logheader = is_array( $logheader ) ? $logheader : array();
            $lastmsg   = sprintf(
                // translators: %s: number of seconds.
                esc_html__( 'Backup created in %s seconds.', 'mainwp-child' ),
                isset( $logheader['runtime'] ) ? $logheader['runtime'] : 0
            );

            return array(
                'done'            => 1,
                'warnings'        => isset( $logheader['warnings'] ) ? $logheader['warnings'] : 0,
                'errors'          => isset( $logheader['errors'] ) ? $logheader['errors'] : 0,
                'runtime'         => isset( $logheader['runtime'] ) ? $logheader['runtime'] : 0,
                'step_percent'    => 100,
                'substep_percent' => 100,
                'step_done'       => 100,
                'step_todo'       => 100,
                'substeps_todo'   => 100,
                'substeps_done'   => 100,
                'onstep'          => esc_html__( 'Job completed', 'mainwp-child' ),
                'lastmsg'         => $lastmsg,
                'lasterrormsg'    => '',
                'job_id'          => 0,
            );
        }

        $job_id = isset( $job_object->job['jobid'] ) ? $job_object->job['jobid'] : 0;

        return array(
            'done'            => 0,
            'warnings'        => isset( $job_object->warnings ) ? $job_object->warnings : 0,
            'errors'          => isset( $job_object->errors ) ? $job_object->errors : 0,
            'runtime'         => isset( $job_object->start_time ) ? current_time( 'timestamp' ) - $job_object->start_time : 0, // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
            'step_percent'    => isset( $job_object->step_percent ) ? $job_object->step_percent : 0,
            'substep_percent' => isset( $job_object->substep_percent ) ? $job_object->substep_percent : 0,
            'step_done'       => isset( $job_object->steps_done ) ? count( $job_object->steps_done ) : 0,
            'step_todo'       => isset( $job_object->steps_todo ) ? count( $job_object->steps_todo ) : 0,
            'substeps_todo'   => isset( $job_object->substeps_todo ) ? $job_object->substeps_todo : 0,
            'substeps_done'   => isset( $job_object->substeps_done ) ? $job_object->substeps_done : 0,
            'onstep'          => isset( $job_object->steps_data[ $job_object->step_working ]['NAME'] ) ? $job_object->steps_data[ $job_object->step_working ]['NAME'] : '',
            'lastmsg'         => isset( $job_object->lastmsg ) ? $job_object->lastmsg : '',
            'lasterrormsg'    => isset( $job_object->lasterrormsg ) ? $job_object->lasterrormsg : '',
            'job_id'          => $job_id,
        );
    }

    /**
     * Backup now.
     *
     * @uses \BackWPup_Option::get()
     * @uses \BackWPup_Job::get_jobrun_url()
     * @uses \BackWPup_Job::get_working_data()
     *
     * @return array Response array[ success, response, logfile ] or array[ error ]
     */
    protected function backup_now() { //phpcs:ignore -- NOSONAR - multi return 3rd compatible.

        if ( ! isset( $_POST['settings']['job_id'] ) ) {  // phpcs:ignore -- NOSONAR
            return array( 'error' => esc_html__( 'Missing job_id', 'mainwp-child' ) );  // NOSONAR.
        }

        $job_id = absint( wp_unslash( $_POST['settings']['job_id'] ) );  // phpcs:ignore WordPress.Security.NonceVerification.Missing -- NOSONAR.
        if ( ! $job_id ) {
            return array( 'error' => esc_html__( 'Invalid job_id', 'mainwp-child' ) );
        }

        if ( ! current_user_can( 'backwpup_jobs_start' ) ) {
            return array( 'error' => esc_html__( 'You do not have permission to start BackWPup jobs.', 'mainwp-child' ) );
        }

        update_site_option( 'backwpup_messages', array() );

        $this->wp_list_table_dependency();

        if ( $this->use_legacy_backwpup_handler() ) {
            return $this->backup_now_legacy( $job_id );
        }

        // BackWPup_Page_Jobs::load() reads jobid via filter_input(INPUT_GET),
        // which cannot see values assigned to $_GET after request startup.
        // Start the job through the BackWPup API instead.
        $old_log_file = \BackWPup_Option::get( $job_id, 'logfile', null, false );

        // Start the job now.
        $run_response = \BackWPup_Job::get_jobrun_url( 'runnow', $job_id );
        // Check for auth header or object form wp_remote_get() or wp_remote_post() error.
        if ( is_wp_error( $run_response ) ) {
            return array( 'error' => $run_response->get_error_message() );
        }

        $new_log_file = \BackWPup_Option::get( $job_id, 'logfile', null, false );
        $attempts     = 0;
        while ( $old_log_file === $new_log_file && $attempts < 40 ) {
            usleep( 250000 );
            $new_log_file = \BackWPup_Option::get( $job_id, 'logfile', null, false );
            ++$attempts;
        }

        // Disable onboarding.
        $this->disable_onboarding();

        if ( $old_log_file === $new_log_file ) {
            return array( 'error' => esc_html__( 'BackWPup did not start the job.', 'mainwp-child' ) );
        } else {
            $job_object = \BackWPup_Job::get_working_data();
            $lastbackup = MainWP_Utility::get_lasttime_backup( 'backwpup' );
            if ( is_object( $job_object ) ) {
                return array(
                    'success'    => 1,
                    'response'   => esc_html__( 'Job started.', 'mainwp-child' ),
                    'logfile'    => basename( $job_object->logfile ),
                    'lastbackup' => $lastbackup,
                );
            } else {
                return array(
                    'success'    => 1,
                    'response'   => esc_html__( 'Job started.', 'mainwp-child' ),
                    'lastbackup' => $lastbackup,
                );
            }
        }
    }

    /**
     * Backup now using the legacy BackWPup handler.
     *
     * @param int $job_id job id.
     * @uses \BackWPup_Page_Jobs::load()
     * @uses \BackWPup_Job::get_working_data()
     * @uses MainWP_Utility::get_lasttime_backup()
     *
     * @return array Return success or error response.
     */
    private function backup_now_legacy( $job_id ) {
        $_GET['jobid']        = $job_id;
        $_REQUEST['action']   = 'runnow';
        $_REQUEST['_wpnonce'] = wp_create_nonce( 'backwpup_job_run-runnowlink' );

        ob_start();
        \BackWPup_Page_Jobs::load();
        ob_end_clean();

        $output = $this->check_backwpup_messages();
        $this->disable_onboarding();

        if ( isset( $output['error'] ) ) {
            return array( 'error' => '\\BackWPup_Page_Jobs::load fail: ' . $output['error'] );
        }

        $job_object = \BackWPup_Job::get_working_data();
        $lastbackup = MainWP_Utility::get_lasttime_backup( 'backwpup' );
        return array(
            'success'    => 1,
            'response'   => $output['message'],
            'logfile'    => is_object( $job_object ) ? basename( $job_object->logfile ) : '',
            'lastbackup' => $lastbackup,
        );
    }

    /**
     * Abort backup.
     *
     * @uses \BackWPup::get_plugin_data()
     * @uses \BackWPup_Job::user_abort()
     *
     * @return array<string, mixed> Return success data or error[message] on failure.
     */
    protected function backup_abort() {  // phpcs:ignore -- NOSONAR - complex.
        $running_file = \BackWPup::get_plugin_data( 'running_file' );
        if ( empty( $running_file ) || ! file_exists( $running_file ) ) {
            return array( 'error' => esc_html__( 'No BackWPup backup is currently running.', 'mainwp-child' ) );
        }

        if ( $this->use_legacy_backwpup_handler() ) {
            return $this->backup_abort_legacy();
        }

        try {
            \BackWPup_Job::user_abort();
        } catch ( \Throwable $e ) {
            return array( 'error' => 'Cannot abort: ' . $e->getMessage() );
        }

        return array(
            'success' => 1,
            'message' => esc_html__( 'Job will be terminated.', 'mainwp-child' ),
        );
    }

    /**
     * Abort backup using the legacy BackWPup handler.
     *
     * @uses \BackWPup_Page_Jobs::load()
     *
     * @return array Return success or error response.
     */
    private function backup_abort_legacy() {
        $_REQUEST['action']   = 'abort';
        $_REQUEST['_wpnonce'] = wp_create_nonce( 'abort-job' );

        update_site_option( 'backwpup_messages', array() );
        $this->wp_list_table_dependency();

        ob_start();
        \BackWPup_Page_Jobs::load();
        ob_end_clean();

        $output = $this->check_backwpup_messages();

        if ( isset( $output['error'] ) ) {
            return array(
                'error' => 'Cannot abort: ' . $output['error'],
            );
        }

        return array(
            'success' => 1,
            'message' => $output['message'],
        );
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
                // translators: %s: error message.
                'error_message' => sprintf( esc_html__( 'Cannot save jobs: %s', 'mainwp-child' ), $return['error'] ),
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
            // translators: %s: error message.
            return array( 'error' => sprintf( esc_html__( 'Cannot save settings: %s', 'mainwp-child' ), $return['error'] ) );
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
                'mainwp-child'
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
                    $nextrun = wp_next_scheduled( 'backwpup_cron', array( $id ) );
                    if ( $nextrun + ( get_option( 'gmt_offset' ) * 3600 ) ) {
                        // translators: 1: date, 2: time.
                        $results['nextrun'] = sprintf( esc_html__( '%1$s at %2$s by WP-Cron', 'mainwp-child' ), date_i18n( get_option( 'date_format' ), $nextrun, true ), date_i18n( get_option( 'time_format' ), $nextrun, true ) );
                    } else {
                        $results['nextrun'] = esc_html__( 'Not scheduled!', 'mainwp-child' );
                    }
                } else {
                    $results['nextrun'] = esc_html__( 'Inactive', 'mainwp-child' );
                }
                if ( \BackWPup_Option::get( $id, 'lastrun' ) ) {
                    $lastrun = \BackWPup_Option::get( $id, 'lastrun' );
                    // translators: 1: date, 2: time.
                    $results['lastrun'] = sprintf( esc_html__( '%1$s at %2$s', 'mainwp-child' ), date_i18n( get_option( 'date_format' ), $lastrun, true ), date_i18n( get_option( 'time_format' ), $lastrun, true ) );
                    if ( \BackWPup_Option::get( $id, 'lastruntime' ) ) {
                        // translators: %d: number of seconds.
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
     * Method get_default_backwpup_job_ids()
     * Take the list of BackWPup default job IDs.
     *
     * @return array List of default BackWPup job IDs.
     */
    protected function get_default_backwpup_job_ids() {
        return array_unique(
            array_filter(
                array_map(
                    'intval',
                    array(
                        get_site_option( 'backwpup_first_backup_job_id' ),
                        get_site_option( 'backwpup_backup_files_job_id' ),
                        get_site_option( 'backwpup_backup_database_job_id' ),
                    )
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
