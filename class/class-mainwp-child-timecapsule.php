<?php
/**
 * MainWP Time Capsule
 *
 * MainWP Time Capsule Extension handler.
 * Extension URL: https://mainwp.com/extension/time-capsule/
 *
 * @package MainWP\Child
 *
 * Credits
 *
 * Plugin-Name: WP Time Capsule
 * Plugin-URI: https://wptimecapsule.com
 * Author: Revmakx
 * Author URI: http://www.revmakx.com
 * Licence: GPLv2 or later
 */

namespace MainWP\Child;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable PSR1.Classes.ClassDeclaration, WordPress.WP.AlternativeFunctions, Generic.Metrics.CyclomaticComplexity -- required to achieve desired results, pull request solutions appreciated.

/**
 * Class MainWP_Child_Timecapsule
 *
 * MainWP Time Capsule Extension handler.
 */
class MainWP_Child_Timecapsule { //phpcs:ignore -- NOSONAR - multi methods.

    /**
     * How many mutation receipts the option holds.
     *
     * @var int
     */
    const ABILITIES_V2_MAX_RECEIPTS = 100;

    /**
     * How far ahead of this Child's clock a receipt stamp is still believed.
     *
     * @var int
     */
    const ABILITIES_V2_CLOCK_SKEW = 300;

    /**
     * Public static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    public static $instance = null;

    /**
     * Public variable to hold the infomration if the WP Time Capsule plugin is installed on the child site.
     *
     * @var bool If WP Time Capsule intalled, return true, if not, return false.
     */
    public $is_plugin_installed = false;

    /**
     * Create a public static instance.
     *
     * @return mixed Class instance.
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * MainWP_Child_Timecapsule constructor.
     *
     * Run any time the class is called.
     *
     * @uses is_plugin_active() Determines whether a plugin is active.
     * @see https://developer.wordpress.org/reference/functions/is_plugin_active/
     */
    public function __construct() {
        require_once ABSPATH . 'wp-admin/includes/plugin.php'; // NOSONAR - WP compatible.
        if ( is_plugin_active( 'wp-time-capsule/wp-time-capsule.php' ) && defined( 'WPTC_CLASSES_DIR' ) ) {
            $this->is_plugin_installed = true;
        }

        if ( ! $this->is_plugin_installed ) {
            return;
        }

        add_filter( 'mainwp_site_sync_others_data', array( $this, 'sync_others_data' ), 10, 2 );
    }

    /**
     * Initiate action hooks.
     *
     * @uses get_option() Retrieves an option value based on an option name.
     * @see https://developer.wordpress.org/reference/functions/get_option/
     *
     * @return void
     */
    public function init() {
        if ( ! $this->is_plugin_installed ) {
            return;
        }

        if ( get_option( 'mainwp_time_capsule_ext_enabled' ) !== 'Y' ) {
            return;
        }

        add_action( 'mainwp_child_site_stats', array( $this, 'do_site_stats' ) );

        if ( get_option( 'mainwp_time_capsule_hide_plugin' ) === 'hide' ) {
            add_filter( 'all_plugins', array( $this, 'all_plugins' ) );
            add_action( 'admin_menu', array( $this, 'remove_menu' ) );
            add_filter( 'site_transient_update_plugins', array( &$this, 'remove_update_nag' ) );
            add_filter( 'mainwp_child_hide_update_notice', array( &$this, 'hide_update_notice' ) );
        }
    }

    /**
     * Fire off certain WP Time Capsule plugin actions.
     *
     * @return void
     *
     * @uses \MainWP\Child\MainWP_Child_Timecapsule::set_showhide()
     * @uses \MainWP\Child\MainWP_Child_Timecapsule::get_root_files()
     * @uses \MainWP\Child\MainWP_Child_Timecapsule::get_tables()
     * @uses \MainWP\Child\MainWP_Child_Timecapsule::exclude_file_list()
     * @uses \MainWP\Child\MainWP_Child_Timecapsule::exclude_table_list()
     * @uses \MainWP\Child\MainWP_Child_Timecapsule::include_table_list()
     * @uses \MainWP\Child\MainWP_Child_Timecapsule::include_table_structure_only()
     * @uses \MainWP\Child\MainWP_Child_Timecapsule::include_file_list()
     * @uses \MainWP\Child\MainWP_Child_Timecapsule::get_files_by_key()
     * @uses \MainWP\Child\MainWP_Child_Timecapsule::process_wptc_login()
     * @uses \MainWP\Child\MainWP_Child_Timecapsule::get_installed_plugins()
     * @uses \MainWP\Child\MainWP_Child_Timecapsule::get_installed_themes()
     * @uses \MainWP\Child\MainWP_Child_Timecapsule::is_staging_need_request()
     * @uses \MainWP\Child\MainWP_Child_Timecapsule::get_staging_details_wptc()
     * @uses \MainWP\Child\MainWP_Child_Timecapsule::start_fresh_staging_wptc()
     * @uses \MainWP\Child\MainWP_Child_Timecapsule::get_staging_url_wptc()
     * @uses \MainWP\Child\MainWP_Child_Timecapsule::stop_staging_wptc()
     * @uses \MainWP\Child\MainWP_Child_Timecapsule::continue_staging_wptc()
     * @uses \MainWP\Child\MainWP_Child_Timecapsule::delete_staging_wptc()
     * @uses \MainWP\Child\MainWP_Child_Timecapsule::copy_staging_wptc()
     * @uses \MainWP\Child\MainWP_Child_Timecapsule::get_staging_current_status_key()
     * @uses \MainWP\Child\MainWP_Child_Timecapsule::wptc_sync_purchase()
     * @uses \MainWP\Child\MainWP_Child_Timecapsule::init_restore()
     * @uses \MainWP\Child\MainWP_Child_Timecapsule::save_settings_wptc()
     * @uses \MainWP\Child\MainWP_Child_Timecapsule::analyze_inc_exc()
     * @uses \MainWP\Child\MainWP_Child_Timecapsule::get_enabled_plugins()
     * @uses \MainWP\Child\MainWP_Child_Timecapsule::get_enabled_themes()
     * @uses \MainWP\Child\MainWP_Child_Timecapsule::get_system_info()
     * @uses \MainWP\Child\MainWP_Child_Timecapsule::update_vulns_settings()
     * @uses \MainWP\Child\MainWP_Child_Timecapsule::start_fresh_backup_tc_callback_wptc()
     * @uses \MainWP\Child\MainWP_Child_Timecapsule::save_manual_backup_name_wptc()
     * @uses \MainWP\Child\MainWP_Child_Timecapsule::progress_wptc()
     * @uses \MainWP\Child\MainWP_Child_Timecapsule::stop_fresh_backup_tc_callback_wptc()
     * @uses \MainWP\Child\MainWP_Child_Timecapsule::wptc_cron_status()
     * @uses \MainWP\Child\MainWP_Child_Timecapsule::get_this_backups_html()
     * @uses \MainWP\Child\MainWP_Child_Timecapsule::start_restore_tc_callback_wptc()
     * @uses \MainWP\Child\MainWP_Child_Timecapsule::get_sibling_files_callback_wptc()
     * @uses \MainWP\Child\MainWP_Child_Timecapsule::get_logs_rows()
     * @uses \MainWP\Child\MainWP_Child_Timecapsule::clear_wptc_logs()
     * @uses \MainWP\Child\MainWP_Child_Timecapsule::send_issue_report()
     * @uses \MainWP\Child\MainWP_Child_Timecapsule::lazy_load_activity_log_wptc()
     * @uses \MainWP\Child\MainWP_Helper::write() Write response data to be sent to the MainWP Dashboard.
     */
    public function action() { // phpcs:ignore -- NOSONAR - Current complexity is the only way to achieve desired results, pull request solutions appreciated.
        if ( ! $this->is_plugin_installed ) {
            MainWP_Helper::write( array( 'error' => 'Please install WP Time Capsule plugin on child website' ) );
        }

        try {
            $this->require_files();
        } catch ( MainWP_Exception $e ) {
            $error = $e->getMessage();
            MainWP_Helper::write( array( 'error' => $error ) );
        }

        // to fix.
        if ( isset( $_POST['mwp_action'] ) && ! defined( 'WP_ADMIN' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            define( 'WP_ADMIN', true );
        }

        if ( function_exists( '\wptc_load_files' ) ) {
            \wptc_load_files();
        }
        $information = array();

        $options_helper    = new \Wptc_Options_Helper();
        $options           = \WPTC_Factory::get( 'config' );
        $is_user_logged_in = $options->get_option( 'is_user_logged_in' );
        $privileges_wptc   = $options_helper->get_unserialized_privileges();

        $mwp_action = MainWP_System::instance()->validate_params( 'mwp_action' );
        if ( ! empty( $mwp_action ) ) {
            if ( ( 'save_settings' === $mwp_action || 'get_staging_details_wptc' === $mwp_action || 'progress_wptc' === $mwp_action ) && ( ! $is_user_logged_in || ! $privileges_wptc ) ) {
                MainWP_Helper::write( array( 'error' => 'You are not login to your WP Time Capsule account.' ) );
            }
            switch ( $mwp_action ) { // NOSONAR - multi case.
                case 'set_showhide':
                    $information = $this->set_showhide();
                    break;
                case 'abilities_v2':
                    $information = $this->abilities_v2_action();
                    break;
                case 'get_root_files':
                    $this->get_root_files();
                    break;
                case 'get_tables':
                    $this->get_tables();
                    break;
                case 'exclude_file_list':
                    $this->exclude_file_list();
                    break;
                case 'exclude_table_list':
                    $this->exclude_table_list();
                    break;
                case 'include_table_list':
                    $this->include_table_list();
                    break;
                case 'include_table_structure_only':
                    $this->include_table_structure_only();
                    break;
                case 'include_file_list':
                    $this->include_file_list();
                    break;
                case 'get_files_by_key':
                    $this->get_files_by_key();
                    break;
                case 'wptc_login':
                    $information = $this->process_wptc_login();
                    break;
                case 'get_installed_plugins':
                    $information = $this->get_installed_plugins();
                    break;
                case 'get_installed_themes':
                    $information = $this->get_installed_themes();
                    break;
                case 'is_staging_need_request':
                    $this->is_staging_need_request();
                    break;
                case 'get_staging_details_wptc':
                    $this->get_staging_details_wptc();
                    break;
                case 'start_fresh_staging_wptc':
                    $this->start_fresh_staging_wptc();
                    break;
                case 'get_staging_url_wptc':
                    $this->get_staging_url_wptc();
                    break;
                case 'stop_staging_wptc':
                    $this->stop_staging_wptc();
                    break;
                case 'continue_staging_wptc':
                    $this->continue_staging_wptc();
                    break;
                case 'delete_staging_wptc':
                    $this->delete_staging_wptc();
                    break;
                case 'copy_staging_wptc':
                    $this->copy_staging_wptc();
                    break;
                case 'get_staging_current_status_key':
                    $this->get_staging_current_status_key();
                    break;
                case 'wptc_sync_purchase':
                    $this->wptc_sync_purchase();
                    break;
                case 'init_restore':
                    $this->init_restore();
                    break;
                case 'save_settings':
                    $information = $this->save_settings_wptc();
                    break;
                case 'analyze_inc_exc':
                    $this->analyze_inc_exc();
                    break;
                case 'get_enabled_plugins':
                    $information = $this->get_enabled_plugins();
                    break;
                case 'get_enabled_themes':
                    $information = $this->get_enabled_themes();
                    break;
                case 'get_system_info':
                    $information = $this->get_system_info();
                    break;
                case 'update_vulns_settings':
                    $information = $this->update_vulns_settings();
                    break;
                case 'start_fresh_backup':
                    $information = $this->start_fresh_backup_tc_callback_wptc();
                    break;
                case 'save_manual_backup_name':
                    $this->save_manual_backup_name_wptc();
                    break;
                case 'progress_wptc':
                    $information = $this->progress_wptc();
                    break;
                case 'stop_fresh_backup':
                    $information = $this->stop_fresh_backup_tc_callback_wptc();
                    break;
                case 'wptc_cron_status':
                    $information = $this->wptc_cron_status();
                    break;
                case 'get_this_backups_html':
                    $information = $this->get_this_backups_html();
                    break;
                case 'start_restore_tc_wptc':
                    $this->start_restore_tc_callback_wptc();
                    break;
                case 'get_sibling_files':
                    $this->get_sibling_files_callback_wptc();
                    break;
                case 'get_logs_rows':
                    $information = $this->get_logs_rows();
                    break;
                case 'clear_logs':
                    $information = $this->clear_wptc_logs();
                    break;
                case 'send_issue_report':
                    $this->send_issue_report();
                    break;
                case 'lazy_load_activity_log':
                    $information = $this->lazy_load_activity_log_wptc();
                    break;
                default:
                    break;
            }
        }
        MainWP_Helper::write( $information );
    }

    /**
     * Decode one additive Time Capsule abilities-v2 request.
     *
     * @return array Closed protocol response.
     */
    private function abilities_v2_action() {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Authenticated MainWP Child callable.
        if ( ! isset( $_POST['request'] ) || ! is_string( $_POST['request'] ) ) {
            return $this->abilities_v2_error( 'unknown' );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Closed JSON is validated below.
        $raw = wp_unslash( $_POST['request'] );
        if ( '' === $raw || 65536 < strlen( $raw ) ) {
            return $this->abilities_v2_error( 'unknown' );
        }

        return $this->abilities_v2( json_decode( $raw, true ) );
    }

    /**
     * Process one side-effect-free Time Capsule abilities-v2 read.
     *
     * The legacy progress method can spawn cron and clear provider flags, so
     * it is intentionally not reused by this read boundary.
     *
     * @param mixed $request Decoded request.
     * @return array Closed protocol response.
     */
    // phpcs:disable Generic.Commenting.DocComment.MissingShort,Squiz.Commenting.FunctionComment,Generic.Formatting.MultipleStatementAlignment,WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound,WordPress.Arrays.MultipleStatementAlignment,WordPress.PHP.YodaConditions.NotYoda,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Closed protocol block follows the legacy file's compact style.
    public function abilities_v2( $request ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh -- Closed protocol dispatcher is easier to audit linearly.
        $operation = is_array( $request ) && isset( $request['operation'] ) && is_string( $request['operation'] ) ? $request['operation'] : 'unknown';
        $mutations = array( 'replace_policy', 'start_backup', 'cancel_operation', 'restore_backup', 'start_staging', 'delete_staging' );
        $root_keys = in_array( $operation, $mutations, true ) ? array( 'protocol', 'operation', 'request_ref', 'payload' ) : array( 'protocol', 'operation', 'payload' );
        if ( ! is_array( $request ) || ! $this->abilities_v2_exact_keys( $request, $root_keys ) || '2' !== ( isset( $request['protocol'] ) ? $request['protocol'] : null ) || ! isset( $request['payload'] ) || ! is_array( $request['payload'] ) ) {
            return $this->abilities_v2_error( $operation );
        }

        if ( 'capabilities' === $operation ) {
            if ( array() !== $request['payload'] ) {
                return $this->abilities_v2_error( $operation );
            }
            $supported = $this->abilities_v2_supported_operations();
            return array(
                'protocol'           => '2',
                'operation'          => 'capabilities',
                'ok'                 => true,
                'operations'         => $supported,
                'mutation_supported' => array() !== array_intersect( $mutations, $supported ),
            );
        }
        // Anything this Child cannot execute is refused by name, whether the protocol knows it or not.
        if ( ! in_array( $operation, $this->abilities_v2_supported_operations(), true ) ) {
            return $this->abilities_v2_error( $operation, 'unsupported_operation' );
        }
        if ( ! $this->abilities_v2_valid_payload( $operation, $request['payload'] ) ) {
            return $this->abilities_v2_error( $operation );
        }

        if ( in_array( $operation, array( 'site', 'policy' ), true ) ) {
            $config = $this->abilities_v2_config();
            if ( ! is_object( $config ) || ! method_exists( $config, 'get_option' ) ) {
                return $this->abilities_v2_error( $operation, 'provider_unavailable' );
            }
            return 'site' === $operation ? $this->abilities_v2_site( $config ) : $this->abilities_v2_policy( $config );
        }

        $is_mutation = in_array( $operation, $mutations, true );
        $request_ref = null;
        if ( $is_mutation ) {
            if ( ! $this->abilities_v2_valid_request_ref( $request['request_ref'] ) ) {
                return $this->abilities_v2_error( $operation );
            }
            // The reference is validated case-insensitively, so it has to be folded before it keys a receipt.
            $request_ref = strtolower( $request['request_ref'] );
        }

        if ( ! $is_mutation ) {
            return $this->abilities_v2_execute( $operation, $request['payload'], null, false );
        }

        // The receipt check, the provider effect and the operation-row write have to be one
        // atomic step: without it two concurrent starts both read in_progress=false, both start
        // a backup, and the second read-modify-write of the operations option loses the first row.
        $lock = $this->abilities_v2_begin_mutation_lock();
        if ( null === $lock ) {
            return $this->abilities_v2_error( $operation, 'storage_unavailable' );
        }
        if ( true !== $lock ) {
            return $this->abilities_v2_error( $operation, 'lock_busy' );
        }
        try {
            return $this->abilities_v2_execute( $operation, $request['payload'], $request_ref, true );
        } finally {
            $this->abilities_v2_end_mutation_lock();
        }
    }

    /**
     * Execute one validated operation, under the mutation lock when it mutates.
     *
     * @param string      $operation   Operation name.
     * @param array       $payload     Validated payload.
     * @param string|null $request_ref Folded request reference, or null for reads.
     * @param bool        $is_mutation Whether the operation mutates.
     * @return array Closed protocol response.
     */
    private function abilities_v2_execute( $operation, $payload, $request_ref, $is_mutation ) {
        $effect_hash = hash( 'sha256', wp_json_encode( array( $operation, $payload ) ) );
        $receipts    = array();
        if ( $is_mutation ) {
            $receipts = get_option( 'mainwp_timecapsule_abilities_v2_receipts', array() );
            if ( ! is_array( $receipts ) ) {
                return $this->abilities_v2_error( $operation, 'storage_unavailable' );
            }
            // A key holding null is still evidence that something wrote a receipt for this
            // reference, and isset() reads it as absent. Missing it starts a second backup.
            if ( array_key_exists( $request_ref, $receipts ) ) {
                $receipt = $receipts[ $request_ref ];
                if ( ! $this->abilities_v2_valid_receipt( $receipt ) ) {
                    return $this->abilities_v2_error( $operation, 'storage_unavailable' );
                }
                if ( ! hash_equals( $receipt['effect_hash'], $effect_hash ) ) {
                    return $this->abilities_v2_error( $operation, 'request_conflict' );
                }
                if ( 'dispatching' !== $receipt['state'] ) {
                    return $receipt['response'];
                }
                // A reservation nobody settled belongs to a request that died between the backup
                // starting and its outcome reaching the store. That backup may still be running,
                // so starting it again would run it twice and the outcome is unknown.
                if ( $this->abilities_v2_reservation_is_live( $receipt['created_at'] ) ) {
                    return $this->abilities_v2_error( $operation, 'outcome_unknown' );
                }
                // Past the horizon the reservation proves nothing: no Dashboard retry of it is
                // still expected, and a reference that can only ever answer outcome_unknown is
                // worse than a second backup. Retire it and let this request dispatch.
                unset( $receipts[ $request_ref ] );
            }

            // Room has to exist before the provider is touched. A backup or restore this Child
            // cannot record is one the Dashboard's next retry runs a second time.
            $receipts = $this->abilities_v2_evict_receipts( $receipts );
            if ( false === $receipts ) {
                return $this->abilities_v2_error( $operation, 'storage_unavailable' );
            }

            // The reservation is durable before the provider runs, not after it. A request that
            // dies with the backup already started - or one whose settling write is refused - has
            // to leave its retry something to land on, or that retry starts a second backup.
            $receipts[ $request_ref ] = array(
                'effect_hash' => $effect_hash,
                'state'       => 'dispatching',
                'response'    => null,
                'created_at'  => time(),
            );
            if ( ! $this->abilities_v2_store_receipts( $receipts ) ) {
                return $this->abilities_v2_error( $operation, 'storage_unavailable' );
            }
        }

        $response = $this->abilities_v2_dispatch_result( $operation, $payload, $request_ref, $is_mutation );
        if ( ! $is_mutation ) {
            return $response;
        }

        // A refusal is settled too. Leaving it unsettled would answer every retry of this
        // reference outcome_unknown for a full day over a provider that never touched anything.
        $receipts[ $request_ref ]['state']    = 'settled';
        $receipts[ $request_ref ]['response'] = $response;
        if ( ! $this->abilities_v2_store_receipts( $receipts ) ) {
            return $this->abilities_v2_error( $operation, 'outcome_unknown' );
        }
        return $response;
    }

    /**
     * Call the provider once and close whatever it answers into a protocol response.
     *
     * @param string      $operation   Operation name.
     * @param array       $payload     Validated payload.
     * @param string|null $request_ref Folded request reference, or null for reads.
     * @param bool        $is_mutation Whether the operation mutates.
     * @return array Closed protocol response.
     */
    private function abilities_v2_dispatch_result( $operation, $payload, $request_ref, $is_mutation ) {
        try {
            $result = $this->abilities_v2_provider_call( $operation, $payload );
        } catch ( \Throwable $throwable ) {
            return $this->abilities_v2_error( $operation, $is_mutation ? 'outcome_unknown' : 'provider_unavailable' );
        }
        if ( is_wp_error( $result ) ) {
            $code = $result->get_error_code();
            return $this->abilities_v2_error( $operation, in_array( $code, array( 'provider_unavailable', 'provider_schema_invalid', 'target_not_found', 'state_conflict', 'stale_generation', 'outcome_unknown', 'storage_unavailable' ), true ) ? $code : 'provider_unavailable' );
        }
        if ( ! $this->abilities_v2_valid_result( $operation, $result ) ) {
            return $this->abilities_v2_error( $operation, 'provider_schema_invalid' );
        }
        return array_merge(
            array(
                'protocol'  => '2',
                'operation' => $operation,
                'ok'        => true,
            ),
            $is_mutation ? array( 'request_ref' => $request_ref ) : array(),
            $result
        );
    }

    /**
     * Persist the mutation receipt store.
     *
     * A false answer from update_option() means either a refused write or one that changed
     * nothing, so the stored value decides which of the two happened.
     *
     * @param array $receipts Receipts to store.
     * @return bool
     */
    private function abilities_v2_store_receipts( $receipts ) {
        return update_option( 'mainwp_timecapsule_abilities_v2_receipts', $receipts, false ) || get_option( 'mainwp_timecapsule_abilities_v2_receipts', array() ) === $receipts;
    }

    /**
     * The moment before which no Dashboard retry of a request is still expected.
     *
     * @return int
     */
    private function abilities_v2_retry_horizon() {
        return time() - ( DAY_IN_SECONDS + 60 );
    }

    /**
     * Whether a stored stamp still stands for work a retry could collide with.
     *
     * A stamp this clock cannot date cannot be shown to be past anything, so it holds its entry
     * open rather than releasing it. wp-rocket reads an undatable stamp the other way and retires
     * it; there the retired reference re-queues a cleanup, here it would start a second backup. A
     * host clock that stepped backwards past the skew allowance would otherwise turn every entry
     * in the store into spare capacity at once.
     *
     * @param int $created_at Stored stamp from a validated entry.
     * @return bool
     */
    private function abilities_v2_reservation_is_live( $created_at ) {
        return $created_at > time() + self::ABILITIES_V2_CLOCK_SKEW || $created_at >= $this->abilities_v2_retry_horizon();
    }

    /**
     * Validate one stored mutation receipt.
     *
     * The store is a WordPress option, so every entry is untrusted input. An effect hash that is
     * merely a string cannot be told apart from a hand-written placeholder, so the real shape is
     * pinned here; entries written by an older build carry no state and are read as unreadable.
     *
     * @param mixed $receipt Stored receipt.
     * @return bool
     */
    private function abilities_v2_valid_receipt( $receipt ) {
        if ( ! is_array( $receipt ) || ! $this->abilities_v2_exact_keys( $receipt, array( 'effect_hash', 'state', 'response', 'created_at' ) ) ) {
            return false;
        }
        if ( ! is_string( $receipt['effect_hash'] ) || 1 !== preg_match( '/^[0-9a-f]{64}$/D', $receipt['effect_hash'] ) || ! is_int( $receipt['created_at'] ) ) {
            return false;
        }
        if ( 'dispatching' === $receipt['state'] ) {
            return null === $receipt['response'];
        }
        return 'settled' === $receipt['state'] && is_array( $receipt['response'] );
    }

    /**
     * Free one receipt slot without discarding an outcome a retry could still ask for.
     *
     * The created_at stamp is written once and never restamped, so a full store crosses the
     * horizon on its own and starts accepting writes again without an operator touching anything.
     *
     * @param array $receipts Current receipts.
     * @return array|false Receipts with room for one more, or false when nothing can be given up.
     */
    private function abilities_v2_evict_receipts( $receipts ) {
        if ( self::ABILITIES_V2_MAX_RECEIPTS > count( $receipts ) ) {
            return $receipts;
        }
        $repaired  = false;
        $evictable = array();
        foreach ( $receipts as $reference => $receipt ) {
            if ( ! $this->abilities_v2_valid_request_ref( $reference ) || strtolower( $reference ) !== $reference ) {
                // No request can present a reference this store would refuse, so nothing will ever
                // come back for this entry. It is evidence for nobody, and dropping it is what
                // keeps a store of junk keys from holding every later mutation shut. Array keys are
                // not always strings either, so this is also what stops one being compared as one.
                // A reference is folded to lowercase before it keys a receipt here, so an uppercase
                // key is equally unreachable however well formed it looks: every lookup folds first.
                $evictable[ $reference ] = 0;
                continue;
            }
            if ( ! $this->abilities_v2_valid_receipt( $receipt ) ) {
                // An entry nobody can read is still evidence that something wrote a receipt for
                // that reference, so its backup or restore may already have run. Giving it up to
                // make room for an unrelated request is how a reference loses its only evidence
                // and runs a second time on its next retry. Rebuilt as a tombstone it keeps
                // failing the receipt check, so its own reference still answers
                // storage_unavailable, while gaining a date this store can act on.
                // Rebuilding it and keeping the result only when it differs makes the builder the
                // single definition of a tombstone. A separate "is this already a tombstone"
                // check would be a second definition, and the two drifting apart is how an entry
                // with an impossible stamp gets treated as sound and holds the store shut.
                $rebuilt = $this->abilities_v2_tombstone_record( $receipt );
                if ( $rebuilt !== $receipt ) {
                    $receipts[ $reference ] = $rebuilt;
                    $repaired               = true;
                }
                $receipt = $rebuilt;
            }
            // A stamp ahead of this clock can never be shown to be past the horizon, so an entry
            // carrying one would sit here forever and, once the store is full, refuse every later
            // mutation from then on. It is re-dated rather than dropped or held: dropping it
            // would lose evidence a retry still needs, while re-dating only ever extends the
            // window it is protected for. It happens once, because the write below leaves a stamp
            // this clock can date.
            if ( $receipt['created_at'] > time() + self::ABILITIES_V2_CLOCK_SKEW ) {
                $receipt['created_at']  = time();
                $receipts[ $reference ] = $receipt;
                $repaired               = true;
            }
            // Eviction may only give up an entry it can prove is past the retry horizon, which is
            // the same question a replay asks of a reservation. Asking it once is what stops
            // eviction from dropping an entry a retry would still have been answered from.
            if ( ! $this->abilities_v2_reservation_is_live( $receipt['created_at'] ) ) {
                $evictable[ $reference ] = $receipt['created_at'];
            }
        }
        asort( $evictable, SORT_NUMERIC );
        foreach ( array_keys( $evictable ) as $reference ) {
            if ( self::ABILITIES_V2_MAX_RECEIPTS > count( $receipts ) ) {
                break;
            }
            unset( $receipts[ $reference ] );
        }
        // A tombstone is stamped at first observation, so a repair left unpersisted is stamped
        // again on every request and can never grow old enough to be given up - a store of damaged
        // entries would then refuse every mutation forever with no way out. Writing it here
        // freezes those stamps and lets the entries cross the horizon on their own.
        if ( $repaired && ! $this->abilities_v2_store_receipts( $receipts ) ) {
            return false;
        }
        return self::ABILITIES_V2_MAX_RECEIPTS > count( $receipts ) ? $receipts : false;
    }

    /**
     * Rebuild one unreadable entry as a dated tombstone under the same reference.
     *
     * Keeping the reference is what stops its retry from starting a second backup. What the
     * damaged entry no longer proves is the outcome, so nothing about the effect survives: no
     * effect hash a request could match, and no response to replay. Its own stamp is kept where
     * it still reads, so an entry written by an older build ages out on the date it was written
     * rather than on the date this store first failed to read it. A stamp this clock cannot date
     * is replaced instead: keeping it would leave an entry no clock ever passes, and one of those
     * in a full store refuses every mutation from then on with nothing an operator can do.
     *
     * @param mixed $receipt Unreadable entry.
     * @return array Dated tombstone.
     */
    private function abilities_v2_tombstone_record( $receipt ) {
        $now = time();
        return array(
            'effect_hash' => str_repeat( '0', 64 ),
            'state'       => 'unreadable',
            'response'    => null,
            'created_at'  => is_array( $receipt ) && isset( $receipt['created_at'] ) && is_int( $receipt['created_at'] ) && 0 < $receipt['created_at'] && $now >= $receipt['created_at'] ? $receipt['created_at'] : $now,
        );
    }

    /**
     * List the operations this Child can actually execute.
     *
     * Restore and staging mutations have no Child-side adapter: they are part of
     * the protocol but nothing here can carry them out, so they are neither
     * advertised nor dispatched. A build that wires them extends this list.
     *
     * @return array Executable operation names.
     */
    protected function abilities_v2_supported_operations() {
        return array( 'site', 'policy', 'list_backups', 'operation_status', 'preview_restore', 'list_staging', 'replace_policy', 'start_backup', 'cancel_operation' );
    }

    /**
     * Return this installation's named Time Capsule mutation lock.
     *
     * @return string Lock name.
     */
    protected function abilities_v2_lock_name() {
        return 'mainwp_timecapsule_v2_' . substr( hash( 'sha256', home_url( '/' ) ), 0, 32 );
    }

    /**
     * Acquire the Child-wide Time Capsule mutation lock without waiting.
     *
     * @return bool|null True when acquired, false when another session holds it, null when the lock backend could not answer.
     */
    protected function abilities_v2_begin_mutation_lock() {
        global $wpdb;
        if ( ! is_object( $wpdb ) || ! is_callable( array( $wpdb, 'prepare' ) ) || ! is_callable( array( $wpdb, 'get_var' ) ) ) {
            return null;
        }
        $wpdb->last_error = '';
        $locked           = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $this->abilities_v2_lock_name() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Named lock is the serialization primitive.
        if ( ! empty( $wpdb->last_error ) ) {
            return null;
        }
        if ( '1' === (string) $locked ) {
            return true;
        }
        // Only '0' means someone else holds it. GET_LOCK() answers NULL on an error or an
        // interrupted wait, which says nothing about who holds the lock, so it is not reported
        // as contention the Child never observed.
        return '0' === (string) $locked ? false : null;
    }

    /**
     * Release the Child-wide Time Capsule mutation lock.
     *
     * @return bool Whether the lock was released.
     */
    protected function abilities_v2_end_mutation_lock() {
        global $wpdb;
        if ( ! is_object( $wpdb ) || ! is_callable( array( $wpdb, 'prepare' ) ) || ! is_callable( array( $wpdb, 'get_var' ) ) ) {
            return false;
        }
        // A lock this session fails to give back outlives the request on a persistent connection
        // and blocks every later mutation with no way for an operator to clear it, so one failed
        // release earns a second attempt before it is given up on.
        for ( $attempt = 0; $attempt < 2; $attempt++ ) {
            $wpdb->last_error = '';
            $released         = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->abilities_v2_lock_name() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Named lock release must be checked.
            // RELEASE_LOCK() answers NULL when the named lock does not exist, which is the state
            // the caller asked for.
            if ( empty( $wpdb->last_error ) && ( null === $released || '1' === (string) $released ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Execute one typed provider operation.
     *
     * Mutating provider integrations override this narrow boundary in tests.
     * Production supports the policy and backup primitives that can be invoked
     * without the legacy JSON/die response handlers; unsupported provider
     * versions fail closed during staged rollout.
     *
     * @param string $operation Operation name.
     * @param array  $payload   Closed payload.
     * @return array|WP_Error
     */
    protected function abilities_v2_provider_call( $operation, $payload ) {
        if ( 'replace_policy' === $operation ) {
            return $this->abilities_v2_provider_replace_policy( $payload );
        }
        if ( 'list_backups' === $operation ) {
            return $this->abilities_v2_provider_list_backups( $payload );
        }
        if ( 'start_backup' === $operation ) {
            return $this->abilities_v2_provider_start_backup( $payload );
        }
        if ( 'operation_status' === $operation ) {
            return $this->abilities_v2_provider_operation_status( $payload['operation_ref'] );
        }
        if ( 'cancel_operation' === $operation ) {
            return $this->abilities_v2_provider_cancel_operation( $payload );
        }
        if ( 'preview_restore' === $operation ) {
            return $this->abilities_v2_provider_preview_restore( $payload );
        }
        if ( 'list_staging' === $operation ) {
            return $this->abilities_v2_provider_list_staging( $payload );
        }
        return new \WP_Error( 'provider_unavailable' );
    }

    /** @param array $payload Closed policy payload. @return array|WP_Error */
    private function abilities_v2_provider_replace_policy( $payload ) {
        $config = $this->abilities_v2_config();
        if ( ! is_object( $config ) || ! method_exists( $config, 'get_option' ) || ! method_exists( $config, 'set_option' ) ) {
            return new \WP_Error( 'provider_unavailable' );
        }
        $current = $this->abilities_v2_policy( $config );
        if ( false === $current['ok'] || ! hash_equals( $current['policy_generation'], $payload['if_match'] ) ) {
            return new \WP_Error( false === $current['ok'] ? $current['code'] : 'stale_generation' );
        }

        $old = array(
            'schedule_time_str'            => $config->get_option( 'schedule_time_str' ),
            'revision_limit'               => $config->get_option( 'revision_limit' ),
            'backup_before_update_setting' => $config->get_option( 'backup_before_update_setting' ),
        );
        $new = array(
            'schedule_time_str'            => $payload['schedule_time'],
            'revision_limit'               => (string) $payload['retention_days'],
            'backup_before_update_setting' => $payload['backup_before_update'] ? 'always' : false,
        );
        foreach ( $new as $key => $value ) {
            $config->set_option( $key, $value );
        }
        foreach ( $new as $key => $value ) {
            if ( $config->get_option( $key ) !== $value ) {
                foreach ( $old as $old_key => $old_value ) {
                    $config->set_option( $old_key, $old_value );
                }
                foreach ( $old as $old_key => $old_value ) {
                    if ( $config->get_option( $old_key ) !== $old_value ) {
                        return new \WP_Error( 'outcome_unknown' );
                    }
                }
                return new \WP_Error( 'storage_unavailable' );
            }
        }

        $updated = $this->abilities_v2_policy( $config );
        if ( false === $updated['ok'] ) {
            return new \WP_Error( 'outcome_unknown' );
        }
        return array(
            'policy_generation' => $updated['policy_generation'],
            'scheduled_change'   => $old !== $new,
        );
    }

    /** @param array $payload Closed inventory payload. @return array|WP_Error */
    private function abilities_v2_provider_list_backups( $payload ) {
        $rows = $this->abilities_v2_backup_rows();
        if ( is_wp_error( $rows ) ) {
            return $rows;
        }
        $offset = 0;
        if ( null !== $payload['after_backup_ref'] ) {
            $offset = null;
            foreach ( $rows as $index => $row ) {
                if ( hash_equals( $row['backup_ref'], $payload['after_backup_ref'] ) ) {
                    $offset = $index + 1;
                    break;
                }
            }
            if ( null === $offset ) {
                return new \WP_Error( 'target_not_found' );
            }
        }
        $page      = array_slice( $rows, $offset, $payload['limit'] );
        $truncated = count( $rows ) > $offset + count( $page );
        return array(
            'backups'               => $page,
            'snapshot_generation'   => hash( 'sha256', wp_json_encode( $rows ) ),
            'next_after_backup_ref' => $truncated && ! empty( $page ) ? $page[ count( $page ) - 1 ]['backup_ref'] : null,
            'truncated'             => $truncated,
        );
    }

    /** @param array $payload Closed backup intent. @return array|WP_Error */
    private function abilities_v2_provider_start_backup( $payload ) {
        if ( 'full' !== $payload['scope'] ) {
            return new \WP_Error( 'state_conflict' );
        }
        $config  = $this->abilities_v2_config();
        $current = is_object( $config ) ? $this->abilities_v2_policy( $config ) : $this->abilities_v2_error( 'policy', 'provider_unavailable' );
        if ( false === $current['ok'] || ! hash_equals( $current['policy_generation'], $payload['policy_generation'] ) ) {
            return new \WP_Error( false === $current['ok'] ? $current['code'] : 'stale_generation' );
        }
        if ( true === $this->abilities_v2_boolean( $config->get_option( 'in_progress' ) ) ) {
            return new \WP_Error( 'state_conflict' );
        }

        $started_at    = time();
        $operation_ref = hash( 'sha256', wp_generate_uuid4() . '|' . $started_at . '|' . wp_salt( 'auth' ) );
        $result        = $this->start_fresh_backup_tc_callback_wptc();
        if ( ! is_array( $result ) || array( 'result' => 'success' ) !== $result ) {
            return new \WP_Error( 'outcome_unknown' );
        }
        $in_progress = $this->abilities_v2_boolean( $config->get_option( 'in_progress' ) );
        if ( null === $in_progress ) {
            return new \WP_Error( 'outcome_unknown' );
        }
        $row = array(
            'operation_ref'   => $operation_ref,
            'kind'            => 'backup',
            'state'           => $in_progress ? 'running' : 'reconciliation_required',
            'progress_percent' => 0,
            'started_at'      => gmdate( 'Y-m-d\TH:i:s\Z', $started_at ),
            'finished_at'     => null,
            'result_ref'      => null,
        );
        $row['generation'] = hash( 'sha256', wp_json_encode( $row ) );
        if ( ! $this->abilities_v2_store_operation( $row ) ) {
            return new \WP_Error( 'outcome_unknown' );
        }
        return array( 'operation_ref' => $operation_ref, 'state' => $row['state'], 'scope' => 'full' );
    }

    /** @param string $operation_ref Operation reference. @return array|WP_Error */
    private function abilities_v2_provider_operation_status( $operation_ref ) {
        $operations = get_option( 'mainwp_timecapsule_abilities_v2_operations', array() );
        if ( ! is_array( $operations ) ) {
            return new \WP_Error( 'storage_unavailable' );
        }
        if ( ! isset( $operations[ $operation_ref ] ) || ! $this->abilities_v2_valid_operation_row( $operations[ $operation_ref ] ) ) {
            return new \WP_Error( isset( $operations[ $operation_ref ] ) ? 'storage_unavailable' : 'target_not_found' );
        }
        $row = $operations[ $operation_ref ];
        if ( 'backup' === $row['kind'] && in_array( $row['state'], array( 'running', 'reconciliation_required' ), true ) ) {
            $config      = $this->abilities_v2_config();
            $in_progress = is_object( $config ) ? $this->abilities_v2_boolean( $config->get_option( 'in_progress' ) ) : null;
            if ( null === $in_progress ) {
                return new \WP_Error( 'provider_schema_invalid' );
            }
            if ( ! $in_progress ) {
                // Time Capsule keeps no per-operation outcome: last_backup_time is site-wide, so any
                // parallel backup would satisfy a "finished after we started" comparison. Nothing here
                // can tell this operation's success from another's, and the finish time is unknown.
                // Derived on read only - a read must not write the operations option.
                $row['state']      = 'uncertain';
                $row['result_ref'] = null;
                $row['generation'] = hash( 'sha256', wp_json_encode( array_diff_key( $row, array( 'generation' => true ) ) ) );
            }
        }
        return $row;
    }

    /** @param array $payload Closed cancellation intent. @return array|WP_Error */
    private function abilities_v2_provider_cancel_operation( $payload ) {
        $row = $this->abilities_v2_provider_operation_status( $payload['operation_ref'] );
        if ( is_wp_error( $row ) ) {
            return $row;
        }
        if ( ! hash_equals( $row['generation'], $payload['if_match'] ) ) {
            return new \WP_Error( 'stale_generation' );
        }
        if ( 'backup' !== $row['kind'] || ! in_array( $row['state'], array( 'running', 'reconciliation_required' ), true ) ) {
            return new \WP_Error( 'state_conflict' );
        }
        $result = $this->stop_fresh_backup_tc_callback_wptc();
        $config = $this->abilities_v2_config();
        if ( ! is_array( $result ) || array( 'result' => 'ok' ) !== $result || ! is_object( $config ) || false !== $this->abilities_v2_boolean( $config->get_option( 'in_progress' ) ) ) {
            return new \WP_Error( 'outcome_unknown' );
        }
        $row['state']            = 'cancelled';
        $row['progress_percent'] = 0;
        $row['finished_at']      = gmdate( 'Y-m-d\TH:i:s\Z' );
        $row['generation']       = hash( 'sha256', wp_json_encode( array_diff_key( $row, array( 'generation' => true ) ) ) );
        if ( ! $this->abilities_v2_store_operation( $row ) ) {
            return new \WP_Error( 'outcome_unknown' );
        }
        return array( 'operation_ref' => $row['operation_ref'], 'state' => 'cancelled', 'quiescent' => true );
    }

    /** @param array $payload Closed restore preview. @return array|WP_Error */
    private function abilities_v2_provider_preview_restore( $payload ) {
        $raw = $this->abilities_v2_resolve_backup_ref( $payload['backup_ref'] );
        if ( is_wp_error( $raw ) ) {
            return $raw;
        }
        $generation = hash( 'sha256', $payload['backup_ref'] . '|' . $raw );
        if ( ! hash_equals( $generation, $payload['backup_generation'] ) ) {
            return new \WP_Error( 'stale_generation' );
        }
        // No preview token is minted: restore_backup has no Child-side adapter, so nothing could ever
        // redeem one. Time Capsule exposes no per-backup table manifest, so table_count stays unknown.
        return array(
            'preview_token'     => null,
            'expires_at'        => null,
            'backup_ref'        => $payload['backup_ref'],
            'scope'             => $payload['scope'],
            'file_count'        => $this->abilities_v2_processed_file_count( $raw ),
            'table_count'       => null,
            'overwrite_expected' => true,
            'preflight'         => 'blocked',
        );
    }

    /** @param string $raw_id Provider backup identity. @return int|null Processed files, or null when the provider table cannot answer. */
    private function abilities_v2_processed_file_count( $raw_id ) {
        global $wpdb;

        // The count doubles as the existence check. SHOW TABLES cannot serve here: it never lists
        // temporary tables, so it answers "absent" for a table the very next query reads fine.
        // A query error means the preview cannot answer, which is not the same as counting zero files.
        $suppress         = $wpdb->suppress_errors( true );
        $wpdb->last_error = '';
        $count            = $wpdb->get_var( //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Provider-owned table, count changes with every backup run.
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->base_prefix}wptc_processed_files WHERE backupID = %d",
                (int) $raw_id
            )
        );
        $failed = '' !== $wpdb->last_error;
        $wpdb->suppress_errors( $suppress );

        return $failed || null === $count ? null : (int) $count;
    }

    /** @param array $payload Closed staging inventory request. @return array|WP_Error */
    private function abilities_v2_provider_list_staging( $payload ) {
        if ( ! class_exists( '\\WPTC_Pro_Factory' ) ) {
            return new \WP_Error( 'provider_unavailable' );
        }
        $staging = \WPTC_Pro_Factory::get( 'Wptc_Staging' );
        if ( ! is_object( $staging ) || ! method_exists( $staging, 'get_staging_details' ) ) {
            return new \WP_Error( 'provider_unavailable' );
        }
        $details = $staging->get_staging_details();
        if ( array() === $details ) {
            $rows = array();
        } elseif ( ! is_array( $details ) || ! isset( $details['staging_folder'], $details['db_prefix'], $details['timestamp'] ) || ! is_string( $details['staging_folder'] ) || '' === $details['staging_folder'] || ! is_string( $details['db_prefix'] ) || '' === $details['db_prefix'] || ! $this->abilities_v2_timestamp( $details['timestamp'] ) ) {
            return new \WP_Error( 'provider_schema_invalid' );
        } else {
            $clone_ref = hash_hmac( 'sha256', $details['staging_folder'] . '|' . $details['db_prefix'], wp_salt( 'auth' ) );
            $row       = array(
                'clone_ref'          => $clone_ref,
                'state'              => method_exists( $staging, 'is_any_staging_process_going_on' ) && $staging->is_any_staging_process_going_on() ? 'creating' : 'ready',
                'created_at'         => gmdate( 'Y-m-d\TH:i:s\Z', (int) $details['timestamp'] ),
                'registered_site_id' => null,
                'isolated'           => true,
            );
            $row['generation'] = hash( 'sha256', wp_json_encode( $row ) );
            $rows              = array( $row );
        }
        if ( null !== $payload['after_clone_ref'] ) {
            if ( empty( $rows ) || ! hash_equals( $rows[0]['clone_ref'], $payload['after_clone_ref'] ) ) {
                return new \WP_Error( 'target_not_found' );
            }
            $rows = array();
        }
        return array(
            'clones'               => array_slice( $rows, 0, $payload['limit'] ),
            'snapshot_generation'  => hash( 'sha256', wp_json_encode( $rows ) ),
            'next_after_clone_ref' => null,
            'truncated'            => false,
        );
    }

    /** @return array|WP_Error Complete redacted backup rows. */
    private function abilities_v2_backup_rows() {
        $backups = $this->get_backups( 1 );
        if ( ! is_array( $backups ) || 10000 < count( $backups ) ) {
            return new \WP_Error( 'provider_schema_invalid' );
        }
        $raw_ids = array();
        foreach ( $backups as $backup ) {
            $row = (array) $backup;
            if ( ! $this->abilities_v2_exact_keys( $row, array( 'backupID' ) ) || ! $this->abilities_v2_timestamp( $row['backupID'] ) ) {
                return new \WP_Error( 'provider_schema_invalid' );
            }
            $raw_ids[ (string) (int) $row['backupID'] ] = (int) $row['backupID'];
        }
        rsort( $raw_ids, SORT_NUMERIC );
        $rows = array();
        foreach ( $raw_ids as $raw_id ) {
            $backup_ref = $this->abilities_v2_backup_ref( (string) $raw_id );
            $rows[]     = array(
                'backup_ref' => $backup_ref,
                'created_at' => gmdate( 'Y-m-d\TH:i:s\Z', $raw_id ),
                'state'      => 'verified',
                'scope'      => 'full',
                'size_bytes' => null,
                'generation' => hash( 'sha256', $backup_ref . '|' . $raw_id ),
            );
        }
        return $rows;
    }

    /** @param string $raw_id Provider backup identity. @return string */
    private function abilities_v2_backup_ref( $raw_id ) {
        return hash_hmac( 'sha256', 'timecapsule-backup-v2|' . $raw_id, wp_salt( 'auth' ) );
    }

    /** @param string $backup_ref Opaque backup reference. @return string|WP_Error */
    private function abilities_v2_resolve_backup_ref( $backup_ref ) {
        $backups = $this->get_backups( 1 );
        if ( ! is_array( $backups ) || 10000 < count( $backups ) ) {
            return new \WP_Error( 'provider_schema_invalid' );
        }
        $match = null;
        foreach ( $backups as $backup ) {
            $row = (array) $backup;
            if ( ! $this->abilities_v2_exact_keys( $row, array( 'backupID' ) ) || ! $this->abilities_v2_timestamp( $row['backupID'] ) ) {
                return new \WP_Error( 'provider_schema_invalid' );
            }
            $raw = (string) (int) $row['backupID'];
            if ( hash_equals( $this->abilities_v2_backup_ref( $raw ), $backup_ref ) ) {
                if ( null !== $match ) {
                    return new \WP_Error( 'provider_schema_invalid' );
                }
                $match = $raw;
            }
        }
        return null === $match ? new \WP_Error( 'target_not_found' ) : $match;
    }

    /** @param array $row Closed operation row. @return bool */
    private function abilities_v2_store_operation( $row ) {
        if ( ! $this->abilities_v2_valid_operation_row( $row ) ) {
            return false;
        }
        $operations = get_option( 'mainwp_timecapsule_abilities_v2_operations', array() );
        if ( ! is_array( $operations ) ) {
            return false;
        }
        $operations[ $row['operation_ref'] ] = $row;
        return update_option( 'mainwp_timecapsule_abilities_v2_operations', $operations, false ) || $operations === get_option( 'mainwp_timecapsule_abilities_v2_operations', array() );
    }

    /** @param mixed $row Candidate operation row. @return bool */
    private function abilities_v2_valid_operation_row( $row ) {
        return is_array( $row )
            && $this->abilities_v2_exact_keys( $row, array( 'operation_ref', 'kind', 'state', 'progress_percent', 'started_at', 'finished_at', 'result_ref', 'generation' ) )
            && $this->abilities_v2_valid_hash( $row['operation_ref'] )
            && in_array( $row['kind'], array( 'backup', 'restore', 'staging_create', 'staging_delete' ), true )
            && in_array( $row['state'], array( 'queued', 'running', 'verifying', 'succeeded', 'failed', 'cancelled', 'uncertain', 'reconciliation_required' ), true )
            && is_int( $row['progress_percent'] ) && 0 <= $row['progress_percent'] && 100 >= $row['progress_percent']
            && $this->abilities_v2_valid_date( $row['started_at'] )
            && $this->abilities_v2_valid_date( $row['finished_at'] )
            && ( null === $row['result_ref'] || $this->abilities_v2_valid_hash( $row['result_ref'] ) )
            && $this->abilities_v2_valid_hash( $row['generation'] );
    }

    /** @param string $value Candidate reference. @return bool */
    private function abilities_v2_valid_hash( $value ) {
        return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $value );
    }

    /** @param string $value Candidate request reference. @return bool */
    private function abilities_v2_valid_request_ref( $value ) {
        return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/Di', $value );
    }

    /** @param mixed $value Candidate date. @return bool */
    private function abilities_v2_valid_date( $value ) {
        if ( null === $value ) {
            return true;
        }
        return is_string( $value ) && false !== strtotime( $value ) && gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $value ) ) === $value;
    }

    /**
     * Validate one exact operation payload.
     *
     * @param string $operation Operation name.
     * @param array  $payload   Payload.
     * @return bool
     */
    private function abilities_v2_valid_payload( $operation, $payload ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh -- Closed schema table.
        if ( in_array( $operation, array( 'site', 'policy' ), true ) ) {
            return array() === $payload;
        }
        if ( 'replace_policy' === $operation ) {
            return $this->abilities_v2_exact_keys( $payload, array( 'schedule_time', 'retention_days', 'backup_before_update', 'if_match' ) ) && is_string( $payload['schedule_time'] ) && 1 === preg_match( '/^(1[0-2]|[1-9]):00 [ap]m$/D', $payload['schedule_time'] ) && $this->abilities_v2_integer( $payload['retention_days'], 3, 365 ) && is_bool( $payload['backup_before_update'] ) && $this->abilities_v2_valid_hash( $payload['if_match'] );
        }
        if ( 'list_backups' === $operation ) {
            return $this->abilities_v2_exact_keys( $payload, array( 'limit', 'after_backup_ref' ) ) && is_int( $payload['limit'] ) && 1 <= $payload['limit'] && 100 >= $payload['limit'] && ( null === $payload['after_backup_ref'] || $this->abilities_v2_valid_hash( $payload['after_backup_ref'] ) );
        }
        if ( 'start_backup' === $operation ) {
            return $this->abilities_v2_exact_keys( $payload, array( 'scope', 'label', 'policy_generation' ) ) && in_array( $payload['scope'], array( 'full', 'database', 'files' ), true ) && ( null === $payload['label'] || ( is_string( $payload['label'] ) && 100 >= strlen( $payload['label'] ) ) ) && $this->abilities_v2_valid_hash( $payload['policy_generation'] );
        }
        if ( 'operation_status' === $operation ) {
            return $this->abilities_v2_exact_keys( $payload, array( 'operation_ref' ) ) && $this->abilities_v2_valid_hash( $payload['operation_ref'] );
        }
        if ( 'cancel_operation' === $operation ) {
            return $this->abilities_v2_exact_keys( $payload, array( 'operation_ref', 'if_match' ) ) && $this->abilities_v2_valid_hash( $payload['operation_ref'] ) && $this->abilities_v2_valid_hash( $payload['if_match'] );
        }
        if ( 'preview_restore' === $operation ) {
            return $this->abilities_v2_exact_keys( $payload, array( 'backup_ref', 'backup_generation', 'scope' ) ) && $this->abilities_v2_valid_hash( $payload['backup_ref'] ) && $this->abilities_v2_valid_hash( $payload['backup_generation'] ) && in_array( $payload['scope'], array( 'full', 'database', 'files' ), true );
        }
        if ( 'restore_backup' === $operation ) {
            return $this->abilities_v2_exact_keys( $payload, array( 'backup_ref', 'backup_generation', 'scope', 'preview_token' ) ) && $this->abilities_v2_valid_hash( $payload['backup_ref'] ) && $this->abilities_v2_valid_hash( $payload['backup_generation'] ) && in_array( $payload['scope'], array( 'full', 'database', 'files' ), true ) && is_string( $payload['preview_token'] ) && 1 === preg_match( '/^[A-Za-z0-9_-]{43,128}$/D', $payload['preview_token'] );
        }
        if ( 'list_staging' === $operation ) {
            return $this->abilities_v2_exact_keys( $payload, array( 'limit', 'after_clone_ref' ) ) && is_int( $payload['limit'] ) && 1 <= $payload['limit'] && 50 >= $payload['limit'] && ( null === $payload['after_clone_ref'] || $this->abilities_v2_valid_hash( $payload['after_clone_ref'] ) );
        }
        if ( 'start_staging' === $operation ) {
            return $this->abilities_v2_exact_keys( $payload, array( 'label', 'register_in_mainwp', 'settings_generation' ) ) && is_string( $payload['label'] ) && '' !== $payload['label'] && 100 >= strlen( $payload['label'] ) && is_bool( $payload['register_in_mainwp'] ) && $this->abilities_v2_valid_hash( $payload['settings_generation'] );
        }
        if ( 'delete_staging' === $operation ) {
            return $this->abilities_v2_exact_keys( $payload, array( 'clone_ref', 'clone_generation' ) ) && $this->abilities_v2_valid_hash( $payload['clone_ref'] ) && $this->abilities_v2_valid_hash( $payload['clone_generation'] );
        }
        return false;
    }

    /**
     * Validate one exact typed provider result.
     *
     * @param string $operation Operation name.
     * @param mixed  $result    Provider result.
     * @return bool
     */
    private function abilities_v2_valid_result( $operation, $result ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh -- Closed schema table.
        if ( ! is_array( $result ) ) {
            return false;
        }
        if ( 'replace_policy' === $operation ) {
            return $this->abilities_v2_exact_keys( $result, array( 'policy_generation', 'scheduled_change' ) ) && $this->abilities_v2_valid_hash( $result['policy_generation'] ) && is_bool( $result['scheduled_change'] );
        }
        if ( 'list_backups' === $operation ) {
            if ( ! $this->abilities_v2_exact_keys( $result, array( 'backups', 'snapshot_generation', 'next_after_backup_ref', 'truncated' ) ) || ! is_array( $result['backups'] ) || 100 < count( $result['backups'] ) || ! $this->abilities_v2_valid_hash( $result['snapshot_generation'] ) || ( null !== $result['next_after_backup_ref'] && ! $this->abilities_v2_valid_hash( $result['next_after_backup_ref'] ) ) || ! is_bool( $result['truncated'] ) ) {
                return false;
            }
            foreach ( $result['backups'] as $row ) {
                if ( ! is_array( $row ) || ! $this->abilities_v2_exact_keys( $row, array( 'backup_ref', 'created_at', 'state', 'scope', 'size_bytes', 'generation' ) ) || ! $this->abilities_v2_valid_hash( $row['backup_ref'] ) || ! $this->abilities_v2_valid_date( $row['created_at'] ) || ! in_array( $row['state'], array( 'verified', 'incomplete', 'unavailable' ), true ) || ! in_array( $row['scope'], array( 'full', 'database', 'files' ), true ) || ( null !== $row['size_bytes'] && ( ! is_int( $row['size_bytes'] ) || 0 > $row['size_bytes'] ) ) || ! $this->abilities_v2_valid_hash( $row['generation'] ) ) {
                    return false;
                }
            }
            return true;
        }
        if ( 'start_backup' === $operation ) {
            return $this->abilities_v2_exact_keys( $result, array( 'operation_ref', 'state', 'scope' ) ) && $this->abilities_v2_valid_hash( $result['operation_ref'] ) && in_array( $result['state'], array( 'queued', 'running', 'reconciliation_required' ), true ) && in_array( $result['scope'], array( 'full', 'database', 'files' ), true );
        }
        if ( 'operation_status' === $operation ) {
            return $this->abilities_v2_exact_keys( $result, array( 'operation_ref', 'kind', 'state', 'progress_percent', 'started_at', 'finished_at', 'result_ref', 'generation' ) ) && $this->abilities_v2_valid_hash( $result['operation_ref'] ) && in_array( $result['kind'], array( 'backup', 'restore', 'staging_create', 'staging_delete' ), true ) && in_array( $result['state'], array( 'queued', 'running', 'verifying', 'succeeded', 'failed', 'cancelled', 'uncertain', 'reconciliation_required' ), true ) && is_int( $result['progress_percent'] ) && 0 <= $result['progress_percent'] && 100 >= $result['progress_percent'] && $this->abilities_v2_valid_date( $result['started_at'] ) && $this->abilities_v2_valid_date( $result['finished_at'] ) && ( null === $result['result_ref'] || $this->abilities_v2_valid_hash( $result['result_ref'] ) ) && $this->abilities_v2_valid_hash( $result['generation'] );
        }
        if ( 'cancel_operation' === $operation ) {
            return $this->abilities_v2_exact_keys( $result, array( 'operation_ref', 'state', 'quiescent' ) ) && $this->abilities_v2_valid_hash( $result['operation_ref'] ) && in_array( $result['state'], array( 'running', 'cancelled', 'reconciliation_required' ), true ) && is_bool( $result['quiescent'] );
        }
        if ( 'preview_restore' === $operation ) {
            if ( ! $this->abilities_v2_exact_keys( $result, array( 'preview_token', 'expires_at', 'backup_ref', 'scope', 'file_count', 'table_count', 'overwrite_expected', 'preflight' ) ) ) {
                return false;
            }
            // A null token means no restore was authorized, so it may not carry an expiry; unknown
            // counts are null rather than a fabricated zero.
            $token = null === $result['preview_token'] ? null === $result['expires_at'] : is_string( $result['preview_token'] ) && 1 === preg_match( '/^[A-Za-z0-9_-]{43,128}$/D', $result['preview_token'] ) && null !== $result['expires_at'] && $this->abilities_v2_valid_date( $result['expires_at'] );
            $count = static function ( $value ) {
                return null === $value || ( is_int( $value ) && 0 <= $value );
            };
            return $token && $this->abilities_v2_valid_hash( $result['backup_ref'] ) && in_array( $result['scope'], array( 'full', 'database', 'files' ), true ) && $count( $result['file_count'] ) && $count( $result['table_count'] ) && is_bool( $result['overwrite_expected'] ) && in_array( $result['preflight'], array( 'ready', 'blocked' ), true );
        }
        if ( 'restore_backup' === $operation ) {
            return $this->abilities_v2_exact_keys( $result, array( 'operation_ref', 'backup_ref', 'state' ) ) && $this->abilities_v2_valid_hash( $result['operation_ref'] ) && $this->abilities_v2_valid_hash( $result['backup_ref'] ) && in_array( $result['state'], array( 'queued', 'reconciliation_required' ), true );
        }
        if ( 'list_staging' === $operation ) {
            return $this->abilities_v2_exact_keys( $result, array( 'clones', 'snapshot_generation', 'next_after_clone_ref', 'truncated' ) ) && is_array( $result['clones'] ) && 50 >= count( $result['clones'] ) && $this->abilities_v2_valid_hash( $result['snapshot_generation'] ) && ( null === $result['next_after_clone_ref'] || $this->abilities_v2_valid_hash( $result['next_after_clone_ref'] ) ) && is_bool( $result['truncated'] );
        }
        if ( 'start_staging' === $operation ) {
            return $this->abilities_v2_exact_keys( $result, array( 'operation_ref', 'clone_ref', 'state', 'register_in_mainwp' ) ) && $this->abilities_v2_valid_hash( $result['operation_ref'] ) && $this->abilities_v2_valid_hash( $result['clone_ref'] ) && in_array( $result['state'], array( 'queued', 'running', 'reconciliation_required' ), true ) && is_bool( $result['register_in_mainwp'] );
        }
        if ( 'delete_staging' === $operation ) {
            return $this->abilities_v2_exact_keys( $result, array( 'operation_ref', 'clone_ref', 'registered_site_removal', 'state' ) ) && $this->abilities_v2_valid_hash( $result['operation_ref'] ) && $this->abilities_v2_valid_hash( $result['clone_ref'] ) && is_bool( $result['registered_site_removal'] ) && in_array( $result['state'], array( 'queued', 'reconciliation_required' ), true );
        }
        return false;
    }

    /**
     * Return the current provider configuration reader.
     *
     * @return object|null Provider configuration.
     */
    protected function abilities_v2_config() {
        return class_exists( '\\WPTC_Factory' ) ? \WPTC_Factory::get( 'config' ) : null;
    }

    /**
     * Project one redacted site observation.
     *
     * @param object $config Provider configuration.
     * @return array Closed protocol response.
     */
    private function abilities_v2_site( $config ) {
        $connected   = $this->abilities_v2_boolean( $config->get_option( 'is_user_logged_in' ) );
        $in_progress = $this->abilities_v2_boolean( $config->get_option( 'in_progress' ) );
        $last_time   = $config->get_option( 'last_backup_time' );
        if ( null === $connected || null === $in_progress || ( false !== $last_time && null !== $last_time && ! $this->abilities_v2_timestamp( $last_time ) ) ) {
            return $this->abilities_v2_error( 'site', 'provider_schema_invalid' );
        }

        $last_attempt = false === $last_time || null === $last_time ? null : gmdate( 'Y-m-d\TH:i:s\Z', (int) $last_time );
        $observed_at  = time();
        $binding      = array(
            'plugin_state'          => $this->is_plugin_installed ? 'ready' : 'missing',
            'account_state'         => $connected ? 'connected' : 'disconnected',
            'last_attempt_at'       => $last_attempt,
            'last_verified_at'      => null,
            'active_operation_count' => $in_progress ? 1 : 0,
            'observed_at'           => gmdate( 'Y-m-d\TH:i:s\Z', $observed_at ),
        );

        return array_merge(
            array(
                'protocol'  => '2',
                'operation' => 'site',
                'ok'        => true,
                'complete'  => true,
            ),
            $binding,
            array( 'generation' => hash( 'sha256', wp_json_encode( $binding ) ) )
        );
    }

    /**
     * Project one bounded non-secret policy.
     *
     * @param object $config Provider configuration.
     * @return array Closed protocol response.
     */
    private function abilities_v2_policy( $config ) {
        $schedule  = $config->get_option( 'schedule_time_str' );
        $retention = $config->get_option( 'revision_limit' );
        $before    = $config->get_option( 'backup_before_update_setting' );
        if ( ! is_string( $schedule ) || 1 !== preg_match( '/^(1[0-2]|[1-9]):00 [ap]m$/D', $schedule ) || ! $this->abilities_v2_integer( $retention, 3, 365 ) || ! in_array( $before, array( 'always', 'everytime', true, false ), true ) ) {
            return $this->abilities_v2_error( 'policy', 'provider_schema_invalid' );
        }

        $policy = array(
            'schedule_time'       => $schedule,
            'retention_days'      => (int) $retention,
            'backup_before_update' => 'always' === $before || true === $before,
        );

        return array_merge(
            array(
                'protocol'  => '2',
                'operation' => 'policy',
                'ok'        => true,
                'complete'  => true,
            ),
            $policy,
            array( 'policy_generation' => hash( 'sha256', wp_json_encode( $policy ) ) )
        );
    }

    /**
     * Normalize a provider boolean without accepting arbitrary truthy values.
     *
     * @param mixed $value Provider value.
     * @return bool|null Normalized value or null when malformed.
     */
    private function abilities_v2_boolean( $value ) {
        if ( true === $value || 1 === $value || '1' === $value ) {
            return true;
        }
        if ( false === $value || 0 === $value || '0' === $value || null === $value || '' === $value ) {
            return false;
        }
        return null;
    }

    /**
     * Validate one bounded provider integer.
     *
     * @param mixed $value Provider value.
     * @param int   $minimum Minimum value.
     * @param int   $maximum Maximum value.
     * @return bool Whether the value is valid.
     */
    private function abilities_v2_integer( $value, $minimum, $maximum ) {
        if ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
            $integer = (int) $value;
            if ( (string) $integer !== $value ) {
                return false;
            }
            $value = $integer;
        }
        return is_int( $value ) && $minimum <= $value && $maximum >= $value;
    }

    /**
     * Validate a provider timestamp.
     *
     * @param mixed $value Provider value.
     * @return bool Whether the timestamp is usable.
     */
    private function abilities_v2_timestamp( $value ) {
        return $this->abilities_v2_integer( $value, 1, PHP_INT_MAX );
    }

    /**
     * Compare an exact object key set.
     *
     * @param mixed $value Value to inspect.
     * @param array $keys Expected keys.
     * @return bool Whether the keys match exactly.
     */
    private function abilities_v2_exact_keys( $value, $keys ) {
        if ( ! is_array( $value ) ) {
            return false;
        }
        $actual = array_keys( $value );
        sort( $actual );
        sort( $keys );
        return $actual === $keys;
    }

    /**
     * Return a stable non-reflective protocol error.
     *
     * @param string $operation Requested operation.
     * @param string $code Stable error code.
     * @return array Closed protocol response.
     */
    private function abilities_v2_error( $operation, $code = 'invalid_request' ) {
        return array(
            'protocol'  => '2',
            'operation' => is_string( $operation ) && 1 === preg_match( '/^[a-z_]{1,32}$/D', $operation ) ? $operation : 'unknown',
            'ok'        => false,
            'code'      => $code,
        );
    }
    // phpcs:enable Generic.Commenting.DocComment.MissingShort,Squiz.Commenting.FunctionComment,Generic.Formatting.MultipleStatementAlignment,WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound,WordPress.Arrays.MultipleStatementAlignment,WordPress.PHP.YodaConditions.NotYoda,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

    /**
     * Check if required files exist.
     *
     * @uses \MainWP\Child\MainWP_Helper::check_files_exists() Check if requested files exist.
     */
    public function require_files() {

        // to fix for some case.
        if ( function_exists( '\wptc_load_files' ) ) {
            if ( ! defined( 'WP_ADMIN' ) ) {
                define( 'WP_ADMIN', true );
            }
            \wptc_load_files();
        }

        if ( ! class_exists( '\WPTC_Base_Factory' ) && defined( 'WPTC_PLUGIN_DIR' ) && MainWP_Helper::check_files_exists( WPTC_PLUGIN_DIR . 'Base/Factory.php' ) ) {
            include_once WPTC_PLUGIN_DIR . 'Base/Factory.php'; // NOSONAR -- WP compatible.
        }
        if ( ! class_exists( '\Wptc_Options_Helper' ) && defined( 'WPTC_PLUGIN_DIR' ) && MainWP_Helper::check_files_exists( WPTC_PLUGIN_DIR . 'Views/wptc-options-helper.php' ) ) {
            include_once WPTC_PLUGIN_DIR . 'Views/wptc-options-helper.php'; // NOSONAR -- WP compatible.
        }
    }

    /**
     * Hide or unhide the WP Time Capsule plugin.
     *
     * @return array Action result.
     *
     * @uses \MainWP\Child\MainWP_Helper::update_option() Update database option by option name.
     *
     * @used-by \MainWP\Child\MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
     */
    public function set_showhide() {
        $hide = MainWP_System::instance()->validate_params( 'showhide' );
        MainWP_Helper::update_option( 'mainwp_time_capsule_hide_plugin', $hide, 'yes' );
        $information['result'] = 'SUCCESS';
        return $information;
    }

    /**
     * Sync the WP Time Capsule plugin settings.
     *
     * @param array $information Array containing the sync information.
     * @param array $data        Array containing the WP Time Capsule plugin data to be synced.
     *
     * @return array $information Array containing the sync information.
     *
     * @uses \MainWP\Child\MainWP_Child_Timecapsule::get_sync_data() Get synced WP Time Capsule data.
     * @uses \MainWP\Child\MainWP_Helper::update_option() Update database option by option name.
     * @uses get_option() Retrieves an option value based on an option name.
     * @see https://developer.wordpress.org/reference/functions/get_option/
     */
    public function sync_others_data( $information, $data = array() ) {
        if ( isset( $data['syncWPTimeCapsule'] ) && $data['syncWPTimeCapsule'] ) {
            $information['syncWPTimeCapsule'] = $this->get_sync_data();
            if ( get_option( 'mainwp_time_capsule_ext_enabled' ) !== 'Y' ) {
                MainWP_Helper::update_option( 'mainwp_time_capsule_ext_enabled', 'Y', 'yes' );
            }
        }
        return $information;
    }

    /**
     * Get synced WP Time Capsule data.
     *
     * @return array|bool Return an array containing the synced data, or false on failure.
     *
     * @uses \MainWP\Child\MainWP_Helper::instance()->check_classes_exists() Check if requested class exists.
     * @uses \MainWP\Child\MainWP_Helper::instance()->check_methods() Check if requested method exists.
     *
     * @used-by MainWP_Child_Timecapsule::sync_others_data() Sync the WP Time Capsule plugin settings.
     */
    public function get_sync_data() {
        try {
            $this->require_files();
            MainWP_Helper::instance()->check_classes_exists( array( '\Wptc_Options_Helper', '\WPTC_Base_Factory', '\WPTC_Factory' ) );

            $config = \WPTC_Factory::get( 'config' );
            MainWP_Helper::instance()->check_methods( $config, 'get_option' );

            $main_account_email_var = $config->get_option( 'main_account_email' );
            $last_backup_time       = $config->get_option( 'last_backup_time' );
            $wptc_settings          = \WPTC_Base_Factory::get( 'Wptc_Settings' );

            $options_helper = new \Wptc_Options_Helper();

            MainWP_Helper::instance()->check_methods( $options_helper, array( 'get_plan_interval_from_subs_info', 'get_is_user_logged_in' ) );
            MainWP_Helper::instance()->check_methods( $wptc_settings, array( 'get_connected_cloud_info' ) );

            $all_backups   = $this->get_backups();
            $backups_count = 0;
            if ( is_array( $all_backups ) ) {
                $formatted_backups = array();
                foreach ( $all_backups as $value ) {
                    $value_array                                     = (array) $value;
                    $formatted_backups[ $value_array['backupID'] ][] = $value_array;
                }
                $backups_count = count( $formatted_backups );
            }

            return array(
                'main_account_email' => $main_account_email_var,
                'signed_in_repos'    => $wptc_settings->get_connected_cloud_info(),
                'plan_name'          => $options_helper->get_plan_interval_from_subs_info(),
                'plan_interval'      => $options_helper->get_plan_interval_from_subs_info(),
                'lastbackup_time'    => ! empty( $last_backup_time ) ? $last_backup_time : 0,
                'is_user_logged_in'  => $options_helper->get_is_user_logged_in(),
                'backups_count'      => $backups_count,
            );
        } catch ( MainWP_Exception $e ) {
            // do not exit here!
        }
        return false;
    }

    /**
     * Get WP Time Capsule backups.
     *
     * @param string $last_time Last completed backup timestamp.
     *
     * @uses wpdb::get_results() Retrieve an entire SQL result set from the database (i.e., many rows).
     * @see https://developer.wordpress.org/reference/classes/wpdb/get_results/
     *
     * @uses wpdb::prepare() Prepares a SQL query for safe execution. Uses sprintf()-like syntax.
     * @see https://developer.wordpress.org/reference/classes/wpdb/prepare/
     *
     * @return array Returns array of all completed backups.
     */
    protected function get_backups( $last_time = false ) {
        if ( empty( $last_time ) ) {
            $last_time = strtotime( date( 'Y-m-d', strtotime( date( 'Y-m-01' ) ) ) ); // phpcs:ignore --  required to achieve desired results, pull request solutions appreciated.
        }

        /**
         * Object, providing access to the WordPress database.
         *
         * @global $wpdb WordPress Database instance.
         */
        global $wpdb;

        // Direct query for dynamic backup data; caching would return stale backup progress.
        // wptc_processed_files holds a row per processed file, so group to one row per backup:
        // a single full backup is tens of thousands of rows and callers only ever want backups.
        return $wpdb->get_results( //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(
                "SELECT backupID
                FROM {$wpdb->base_prefix}wptc_processed_files
                WHERE backupID > %s
                GROUP BY backupID ",
                $last_time
            )
        );
    }

    /**
     * Get the WP Time Capsule tables.
     *
     * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
     */
    public function get_tables() {
         // phpcs:disable WordPress.Security.NonceVerification
        $category = isset( $_POST['category'] ) ? wp_unslash( $_POST['category'] ) : ''; //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
         // phpcs:enable WordPress.Security.NonceVerification
        $exclude_class_obj = new \Wptc_ExcludeOption( $category );
        $exclude_class_obj->get_tables();
        die();
    }

    /**
     * Exlude files from the backup process.
     *
     * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
     */
    public function exclude_file_list() {
        // phpcs:disable WordPress.Security.NonceVerification
        if ( ! isset( $_POST['data'] ) ) {
            wptc_die_with_json_encode( array( 'status' => 'no data found' ) );
        }
        $category          = isset( $_POST['category'] ) ? wp_unslash( $_POST['category'] ) : ''; //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $exclude_class_obj = new \Wptc_ExcludeOption( $category );
        $data              = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : ''; //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        // phpcs:enable WordPress.Security.NonceVerification
        $exclude_class_obj->exclude_file_list( $data );
        die();
    }

    /**
     * Get backup process progress.
     *
     * @uses spawn_cron() Sends a request to run cron through HTTP request that doesn’t halt page loading.
     * @see https://developer.wordpress.org/reference/functions/spawn_cron/
     *
     * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
     *
     * @return array Action result.
     */
    public function progress_wptc() {

        $config = \WPTC_Factory::get( 'config' );

        /**
         * Object, providing access to the WordPress database.
         *
         * @global $wpdb WordPress Database instance.
         */
        global $wpdb;

        if ( ! $config->get_option( 'in_progress' ) ) {
            spawn_cron();
        }

        $processed_files = \WPTC_Factory::get( 'processed-files' );

        $return_array                                  = array();
        $return_array['stored_backups']                = $processed_files->get_stored_backups();
        $return_array['backup_progress']               = array();
        $return_array['starting_first_backup']         = $config->get_option( 'starting_first_backup' );
        $return_array['meta_data_backup_process']      = $config->get_option( 'meta_data_backup_process' );
        $return_array['backup_before_update_progress'] = $config->get_option( 'backup_before_update_progress' );
        $return_array['is_staging_running']            = apply_filters( 'is_any_staging_process_going_on', '' );
        $cron_status                                   = $config->get_option( 'wptc_own_cron_status' );

        if ( ! empty( $cron_status ) ) {
            $return_array['wptc_own_cron_status']          = unserialize( $cron_status ); // phpcs:ignore -- safe internal value, third party.
            $return_array['wptc_own_cron_status_notified'] = (int) $config->get_option( 'wptc_own_cron_status_notified' );
        }

        $start_backups_failed_server = $config->get_option( 'start_backups_failed_server' );
        if ( ! empty( $start_backups_failed_server ) ) {
            $return_array['start_backups_failed_server'] = unserialize( $start_backups_failed_server ); // phpcs:ignore -- safe internal value, third party.
            $config->set_option( 'start_backups_failed_server', false );
        }

        $processed_files->get_current_backup_progress( $return_array );

        $return_array['user_came_from_existing_ver'] = (int) $config->get_option( 'user_came_from_existing_ver' );
        $return_array['show_user_php_error']         = $config->get_option( 'show_user_php_error' );
        $return_array['bbu_setting_status']          = apply_filters( 'get_backup_before_update_setting_wptc', '' );
        $return_array['bbu_note_view']               = apply_filters( 'get_bbu_note_view', '' );
        $return_array['staging_status']              = apply_filters( 'staging_status_wptc', '' );

        $processed_files  = \WPTC_Factory::get( 'processed-files' );
        $last_backup_time = $config->get_option( 'last_backup_time' );

        if ( ! empty( $last_backup_time ) ) {
            $user_time = $config->cnvt_UTC_to_usrTime( $last_backup_time );
            $processed_files->modify_schedule_backup_time( $user_time );
            $formatted_date                   = date( 'M d @ g:i a', $user_time ); // phpcs:ignore -- required to achieve desired results, pull request solutions appreciated.
            $return_array['last_backup_time'] = $formatted_date;
        } else {
            $return_array['last_backup_time'] = 'No Backup Taken';
        }

        return array( 'result' => $return_array );
    }

    /**
     * Get the WP Cron status.
     *
     * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
     *
     * @return array Action result.
     */
    public function wptc_cron_status() {
        $config = \WPTC_Factory::get( 'config' );
        wptc_own_cron_status();
        $status      = array();
        $cron_status = $config->get_option( 'wptc_own_cron_status' );
        if ( ! empty( $cron_status ) ) {
            $cron_status = unserialize( $cron_status ); // phpcs:ignore -- safe internal value, third party.

            if ( 'success' === $cron_status['status'] ) {
                $status['status'] = 'success';
            } else {
                $status['status']      = 'failed';
                $status['status_code'] = $cron_status['statusCode'];
                $status['err_msg']     = $cron_status['body'];
                $status['cron_url']    = $cron_status['cron_url'];
                $status['ips']         = $cron_status['ips'];
            }
            return array( 'result' => $status );
        }
        return false;
    }

    /**
     * Get the backups HTML markup.
     *
     * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
     *
     * @return array Action result.
     */
    public function get_this_backups_html() {
        // phpcs:disable WordPress.Security.NonceVerification
        $this_backup_ids    = isset( $_POST['this_backup_ids'] ) ? wp_unslash( $_POST['this_backup_ids'] ) : ''; //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $specific_dir       = isset( $_POST['specific_dir'] ) ? wp_unslash( $_POST['specific_dir'] ) : ''; //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $type               = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
        $treeRecursiveCount = isset( $_POST['treeRecursiveCount'] ) ? sanitize_text_field( wp_unslash( $_POST['treeRecursiveCount'] ) ) : '';
        $processed_files    = \WPTC_Factory::get( 'processed-files' );
        // phpcs:enable WordPress.Security.NonceVerification
        $result = $processed_files->get_this_backups_html( $this_backup_ids, $specific_dir, $type, $treeRecursiveCount );
        return array( 'result' => $result );
    }

    /**
     * Start the restore process.
     *
     * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
     */
    public function start_restore_tc_callback_wptc() {

        if ( apply_filters( 'is_restore_to_staging_wptc', '' ) ) {
            $request = apply_filters( 'get_restore_to_staging_request_wptc', '' );
        } else {
            // phpcs:disable WordPress.Security.NonceVerification
            $request = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : ''; //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            // phpcs:enable WordPress.Security.NonceVerification
        }

        include_once WPTC_CLASSES_DIR . 'class-prepare-restore-bridge.php'; // NOSONAR -- WP compatible.

        new \WPTC_Prepare_Restore_Bridge( $request ); // NOSONAR - 3rd compatible.
    }

    /**
     * Get sibling files.
     *
     * @uses wp_normalize_path() Normalize a filesystem path.
     * @see https://developer.wordpress.org/reference/functions/wp_normalize_path/
     *
     * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
     */
    public function get_sibling_files_callback_wptc() {
        // phpcs:disable WordPress.Security.NonceVerification
        // note that we are getting the ajax function data via $_POST.
        $file_name = isset( $_POST['data']['file_name'] ) ? sanitize_text_field( wp_unslash( $_POST['data']['file_name'] ) ) : '';
        if ( ! empty( $file_name ) ) {
            $file_name = wp_normalize_path( $file_name );
        }
        $backup_id       = isset( $_POST['data']['backup_id'] ) ? sanitize_text_field( wp_unslash( $_POST['data']['backup_id'] ) ) : '';
        $recursive_count = isset( $_POST['data']['recursive_count'] ) ? sanitize_text_field( wp_unslash( $_POST['data']['recursive_count'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification
        $processed_files = \WPTC_Factory::get( 'processed-files' );
        echo $processed_files->get_this_backups_html( $backup_id, $file_name, 'sibling', (int) $recursive_count ); // phpcs:ignore WordPress.Security.EscapeOutput
        die();
    }

    /**
     * Send issue report.
     *
     * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
     */
    public function send_issue_report() {
        \WPTC_Base_Factory::get( 'Wptc_App_Functions' )->send_report();
        die();
    }

    /**
     * Get logs rows.
     *
     * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
     *
     * @return array Action result.
     */
    public function get_logs_rows() {
        $result = $this->prepare_items();
        // phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- transport encoding the legacy WPTC log payload expects, not obfuscation.
        $result['display_rows'] = base64_encode( wp_json_encode( $this->get_display_rows( $result['items'] ) ) );
        // phpcs:enable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
        return $result;
    }

    /**
     * Prepare items for logs.
     *
     * @used-by MainWP_Child_Timecapsule::get_logs_rows() Get logs rows.
     *
     * @return array Action result.
     *
     * @uses \MainWP\Child\MainWP_Child_DB::real_escape_string()
     */
    public function prepare_items() { //phpcs:ignore -- NOSONAR - complex.

        /**
         * Object, providing access to the WordPress database.
         *
         * @global $wpdb WordPress Database instance.
         */
        global $wpdb;
        // phpcs:disable WordPress.Security.NonceVerification
        if ( isset( $_POST['type'] ) ) {
            $type = ! empty( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
            switch ( $type ) {
                case 'backups':
                    $query = 'SELECT * FROM ' . $wpdb->base_prefix . "wptc_activity_log WHERE type LIKE '%backup%' AND show_user = 1 GROUP BY action_id";
                    break;
                case 'restores':
                    $query = 'SELECT * FROM ' . $wpdb->base_prefix . "wptc_activity_log WHERE type LIKE 'restore%' GROUP BY action_id";
                    break;
                case 'staging':
                    $query = 'SELECT * FROM ' . $wpdb->base_prefix . "wptc_activity_log WHERE type LIKE 'staging%' GROUP BY action_id";
                    break;
                case 'backup_and_update':
                    $query = 'SELECT * FROM ' . $wpdb->base_prefix . "wptc_activity_log WHERE type LIKE 'backup_and_update%' GROUP BY action_id";
                    break;
                case 'auto_update':
                    $query = 'SELECT * FROM ' . $wpdb->base_prefix . "wptc_activity_log WHERE type LIKE 'auto_update%' GROUP BY action_id";
                    break;
                case 'others':
                    $query = 'SELECT * FROM ' . $wpdb->base_prefix . "wptc_activity_log WHERE type NOT LIKE 'restore%' AND type NOT LIKE 'backup%' AND show_user = 1";
                    break;
                default:
                    $query = 'SELECT * FROM ' . $wpdb->base_prefix . 'wptc_activity_log GROUP BY action_id UNION SELECT * FROM ' . $wpdb->base_prefix . "wptc_activity_log WHERE action_id='' AND show_user = 1";
                    break;
            }
        } else {
            $query = 'SELECT * FROM ' . $wpdb->base_prefix . 'wptc_activity_log WHERE show_user = 1   GROUP BY action_id ';
        }

        $orderby = ! empty( $_POST['orderby'] ) ? sanitize_sql_orderby( wp_unslash( $_POST['orderby'] ) ) : 'id';
        $order   = ! empty( $_POST['order'] ) ? sanitize_sql_orderby( wp_unslash( $_POST['order'] ) ) : 'DESC';
        if ( ! empty( $orderby ) & ! empty( $order ) ) {
            $query .= ' ORDER BY ' . $orderby . ' ' . $order;
        }

        $totalitems = $wpdb->query( $query ); // phpcs:ignore -- safe query.
        $perpage    = 20;
        $paged      = ! empty( $_POST['paged'] ) ? intval( $_POST['paged'] ) : '';
        if ( empty( $paged ) || ! is_numeric( $paged ) || $paged <= 0 ) {
            $paged = 1;
        }
        if ( ! empty( $paged ) && ! empty( $perpage ) ) {
            $offset = ( $paged - 1 ) * $perpage;
            $query .= ' LIMIT ' . (int) $offset . ',' . (int) $perpage;
        }
        // phpcs:enable WordPress.Security.NonceVerification

        return array(
            'items'      => $wpdb->get_results( $query ), // phpcs:ignore -- safe query required to achieve desired results, pull request solutions appreciated.
            'totalitems' => $totalitems,
            'perpage'    => $perpage,
        );
    }

    /**
     * Lazy load activity log.
     *
     * @uses MainWP_Child_Timecapsule::get_activity_log() Get the WP Time Capsule activity log.
     *
     * @uses wpdb::get_results() Retrieve an entire SQL result set from the database (i.e., many rows).
     * @see https://developer.wordpress.org/reference/classes/wpdb/get_results/
     *
     * @uses wpdb::prepare() Prepares a SQL query for safe execution. Uses sprintf()-like syntax.
     * @see https://developer.wordpress.org/reference/classes/wpdb/prepare/
     *
     * @return array Action result.
     */
    public function lazy_load_activity_log_wptc() {
        // phpcs:disable WordPress.Security.NonceVerification
        if ( ! isset( $_POST['data'] ) ) {
            return false;
        }

        $data = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : array(); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        if ( ! isset( $data['action_id'] ) || ! isset( $data['limit'] ) ) {
            return false;
        }
        // phpcs:enable WordPress.Security.NonceVerification

        /**
         * Object, providing access to the WordPress database.
         *
         * @global $wpdb WordPress Database instance.
         */
        global $wpdb;

        $action_id     = $data['action_id'];
        $from_limit    = $data['limit'];
        $detailed      = '';
        $load_more     = false;
        $current_limit = \WPTC_Factory::get( 'config' )->get_option( 'activity_log_lazy_load_limit' );
        $to_limit      = $from_limit + $current_limit;

        // Direct query for transient activity log data with dynamic pagination; caching not appropriate.
        $sub_records = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->base_prefix . 'wptc_activity_log WHERE action_id = %s AND show_user = 1 ORDER BY id DESC LIMIT %d, %d', $action_id, $from_limit, $current_limit ) ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        $row_count = count( $sub_records );

        if ( $row_count === (int) $current_limit ) {
            $load_more = true;
        }

        $detailed = $this->get_activity_log( $sub_records );

        if ( isset( $load_more ) && $load_more ) {
            $detailed .= '<tr><td></td><td><a style="cursor:pointer; position:relative" class="wptc_activity_log_load_more" action_id="' . esc_attr( $action_id ) . '" limit="' . esc_attr( $to_limit ) . '">Load more</a></td><td></td></tr>';
        }

        return array( 'result' => $detailed );
    }

    /**
     * Display the log rows.
     *
     * @param array $records An array of log records.
     *
     * @used-by MainWP_Child_Timecapsule::get_logs_rows() Get logs rows.
     *
     * @return string Log rows.
     */
    public function get_display_rows( $records ) { //phpcs:ignore -- NOSONAR - complex.

        /**
         * Object, providing access to the WordPress database.
         *
         * @global $wpdb WordPress Database instance.
         */
        global $wpdb;

        // Get the records registered in the prepare_items method.
        if ( ! is_array( $records ) ) {
            return '';
        }

        $limit = \WPTC_Factory::get( 'config' )->get_option( 'activity_log_lazy_load_limit' );
        // Get the columns registered in the get_columns and get_sortable_columns methods.
        if ( count( $records ) > 0 ) {

            foreach ( $records as $key => $rec ) {
                $html = '';

                $more_logs = false;
                $load_more = false;
                if ( ! empty( $rec->action_id ) ) {
                    // Direct query for real-time activity logs; caching would prevent display of current backup progress.
                    $sub_records = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->base_prefix . 'wptc_activity_log WHERE action_id= %s AND show_user = 1 ORDER BY id DESC LIMIT 0, %d', $rec->action_id, $limit ) ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    $row_count   = count( $sub_records );
                    if ( $row_count === (int) $limit ) {
                        $load_more = true;
                    }

                    if ( $row_count > 0 ) {
                        $more_logs = true;
                        $detailed  = '<table>';
                        $detailed .= $this->get_activity_log( $sub_records );
                        if ( isset( $load_more ) && $load_more ) {
                            $detailed .= '<tr><td></td><td><a style="cursor:pointer; position:relative" class="mainwp_wptc_activity_log_load_more" action_id="' . $rec->action_id . '" limit="' . $limit . '">Load more</a></td><td></td></tr>';
                        }
                        $detailed .= '</table>';

                    }
                }
                $html     .= '<tr class="act-tr">';
                $Ldata     = unserialize( $rec->log_data ); // phpcs:ignore -- safe internal value, third party.
                $user_time = \WPTC_Factory::get( 'config' )->cnvt_UTC_to_usrTime( $Ldata['log_time'] );
                \WPTC_Factory::get( 'processed-files' )->modify_schedule_backup_time( $user_time );
                $user_tz_now = date( 'M d, Y @ g:i:s a', $user_time ); // phpcs:ignore -- required to achieve desired results, pull request solutions appreciated.
                $msg         = '';
                if ( false !== strpos( $rec->type, 'backup' ) ) {
                    // Backup process.
                    $msg = 'Backup Process';
                } elseif ( false !== strpos( $rec->type, 'restore' ) ) {
                    // Restore Process.
                    $msg = 'Restore Process';
                } elseif ( false !== strpos( $rec->type, 'staging' ) ) {
                    // Restore Process.
                    $msg = 'Staging Process';
                } else {
                    if ( $row_count < 2 ) {
                        $more_logs = false;
                    }
                    $msg = $Ldata['msg'];
                }
                $html .= '<td class="wptc-act-td">' . $user_tz_now . '</td><td class="wptc-act-td">' . $msg;
                if ( $more_logs ) {
                    $html .= "&nbsp&nbsp&nbsp&nbsp<a class='wptc-show-more' action_id='" . round( $rec->action_id ) . "'>View details</a></td>";
                } else {
                    $html .= '</td>';
                }
                $html .= '<td class="wptc-act-td"><a class="report_issue_wptc" id="' . $rec->id . '" href="#">Send report to plugin developer</a></td>';
                if ( $more_logs ) {

                    $html .= "</tr><tr id='" . round( $rec->action_id ) . "' class='wptc-more-logs'><td colspan=3>" . $detailed . '</td>';
                } else {
                    $html .= '</td>';
                }

                $html .= '</tr>';

                $display_rows[ $key ] = $html;
            }
        }
        return $display_rows;
    }

    /**
     * Get the WP Time Capsule activity log.
     *
     * @param array $sub_records Activity log sub-records.
     *
     * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
     *
     * @return string Activity log HTML.
     */
    public function get_activity_log( $sub_records ) {
        if ( count( $sub_records ) < 1 ) {
            return false;
        }
        $detailed = '';
        $timezone = \WPTC_Factory::get( 'config' )->get_option( 'wptc_timezone' );
        foreach ( $sub_records as $srec ) {
            $Moredata = unserialize( $srec->log_data ); // phpcs:ignore -- safe internal value, third party.
            $user_tmz = new \DateTime( '@' . $Moredata['log_time'], new \DateTimeZone( date_default_timezone_get() ) );
            $user_tmz->setTimeZone( new \DateTimeZone( $timezone ) );
            $user_tmz_now = $user_tmz->format( 'M d @ g:i:s a' );
            $detailed    .= '<tr><td>' . $user_tmz_now . '</td><td>' . $Moredata['msg'] . '</td><td></td></tr>';
        }
        return $detailed;
    }

    /**
     * Clear the WP Time Capsule logs.
     *
     * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
     *
     * @return array Action result.
     */
    public function clear_wptc_logs() {

        /**
         * Object, providing access to the WordPress database.
         *
         * @global $wpdb WordPress Database instance.
         */
        global $wpdb;

        if ( $wpdb->query( 'TRUNCATE TABLE `' . $wpdb->base_prefix . 'wptc_activity_log`' ) ) {
            $result = 'yes';
        } else {
            $result = 'no';
        }
        return array( 'result' => $result );
    }

    /**
     * Stop the WP Time Capsule backup process.
     *
     * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
     *
     * @return array Action result.
     */
    public function stop_fresh_backup_tc_callback_wptc() {
        $deactivated_plugin = null;
        $backup             = new \WPTC_BackupController();
        $backup->stop( $deactivated_plugin );
        return array( 'result' => 'ok' );
    }

    /**
     * Get the site root files.
     *
     * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
     */
    public function get_root_files() {
        // phpcs:disable WordPress.Security.NonceVerification
        $category = isset( $_POST['category'] ) ? wp_unslash( $_POST['category'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        // phpcs:enable WordPress.Security.NonceVerification
        $exclude_class_obj = new \Wptc_ExcludeOption( $category );
        $exclude_class_obj->get_root_files();
        die();
    }

    /**
     * Exclude database tables.
     *
     * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
     */
    public function exclude_table_list() {
        // phpcs:disable WordPress.Security.NonceVerification
        if ( ! isset( $_POST['data'] ) ) {
            wptc_die_with_json_encode( array( 'status' => 'no data found' ) );
        }
        $category          = isset( $_POST['data']['category'] ) ? wp_unslash( $_POST['data']['category'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $exclude_class_obj = new \Wptc_ExcludeOption( $category );
        $data              = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $exclude_class_obj->exclude_table_list( $data );
        // phpcs:enable WordPress.Security.NonceVerification
        die();
    }

    /**
     * Add support for the reporting system.
     *
     * @uses has_action() Check if any action has been registered for a hook.
     * @see https://developer.wordpress.org/reference/functions/has_action/
     *
     * @uses MainWP_Child_Timecapsule::do_reports_log() Add WP Time Capsule data to the reports database table.
     */
    public function do_site_stats() {
        if ( has_action( 'mainwp_child_reports_log' ) ) {
            do_action( 'mainwp_child_reports_log', 'wptimecapsule' );
        } else {
            $this->do_reports_log( 'wptimecapsule' );
        }
    }

    /**
     * Add WP Time Capsule data to the reports database table.
     *
     * @param string $ext Current extension.
     *
     * @uses \MainWP\Child\MainWP_Helper::instance()->check_classes_exists() Check if the requested class exists.
     * @uses \MainWP\Child\MainWP_Helper::instance()->check_methods() Check if the requested method exists.
     * @uses \MainWP\Child\MainWP_Utility::update_lasttime_backup() Get the last backup timestamp.
     * @uses \MainWP\Child\MainWP_Utility::get_lasttime_backup()
     *
     * @used-by \MainWP\Child\MainWP_Child_Timecapsule::do_site_stats() Add support for the reporting system.
     */
    public function do_reports_log( $ext = '' ) {

        if ( 'wptimecapsule' !== $ext ) {
            return;
        }

        if ( ! $this->is_plugin_installed ) {
            return;
        }

        try {
            MainWP_Helper::instance()->check_classes_exists( array( '\WPTC_Factory' ) );

            $config = \WPTC_Factory::get( 'config' );

            MainWP_Helper::instance()->check_methods( $config, 'get_option' );

            $backup_time = $config->get_option( 'last_backup_time' );

            $last_time       = time() - 24 * 7 * 2 * 60 * 60;
            $lasttime_logged = MainWP_Utility::get_lasttime_backup( 'wptimecapsule' );
            if ( empty( $lasttime_logged ) ) {
                $last_time = time() - 24 * 7 * 8 * 60 * 60;
            }

            $all_last_backups = $this->get_backups( $last_time );

            if ( is_array( $all_last_backups ) ) {
                $formatted_backups = array();
                $value_array       = array();
                foreach ( $all_last_backups as $key => $value ) {
                    $value_array                                     = (array) $value;
                    $formatted_backups[ $value_array['backupID'] ][] = $value_array;
                }
                $message     = 'WP Time Capsule backup finished';
                $backup_type = 'WP Time Capsule backup';
                if ( ! empty( $formatted_backups ) ) {
                    $can_advance_cursor = true;
                    foreach ( $formatted_backups as $key => $value ) {
                        $backup_time = $key;
                        $fingerprint = MainWP_Utility::backup_fingerprint( 'wptimecapsule', $key );
                        do_action( 'mainwp_reports_wptimecapsule_backup', $message, $backup_type, $backup_time, $fingerprint );
                        if ( MainWP_Utility::backup_fingerprint_logged( $fingerprint ) ) {
                            continue;
                        }

                        $can_advance_cursor = false;
                    }

                    if ( $can_advance_cursor ) {
                        MainWP_Utility::update_lasttime_backup( 'wptimecapsule', $backup_time );
                    }
                }
            }
        } catch ( MainWP_Exception $e ) {
            // ok.
        }
    }

    /**
     * Include database tables.
     *
     * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
     */
    public function include_table_list() {
        // phpcs:disable WordPress.Security.NonceVerification
        if ( ! isset( $_POST['data'] ) ) {
            wptc_die_with_json_encode( array( 'status' => 'no data found' ) );
        }
        $category          = isset( $_POST['data']['category'] ) ? wp_unslash( $_POST['data']['category'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $exclude_class_obj = new \Wptc_ExcludeOption( $category );
        $data              = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        // phpcs:enable WordPress.Security.NonceVerification
        $exclude_class_obj->include_table_list( $data );
        die();
    }

    /**
     * Include database table structure only.
     *
     * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
     */
    public function include_table_structure_only() {
        // phpcs:disable WordPress.Security.NonceVerification
        if ( ! isset( $_POST['data'] ) ) {
            wptc_die_with_json_encode( array( 'status' => 'no data found' ) );
        }

        $category          = isset( $_POST['data']['category'] ) ? wp_unslash( $_POST['data']['category'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $exclude_class_obj = new \Wptc_ExcludeOption( $category );
        $data              = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        // phpcs:enable WordPress.Security.NonceVerification
        $exclude_class_obj->include_table_structure_only( $data );
        die();
    }

    /**
     * Include files list.
     *
     * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
     */
    public function include_file_list() {
        // phpcs:disable WordPress.Security.NonceVerification
        if ( ! isset( $_POST['data'] ) ) {
            wptc_die_with_json_encode( array( 'status' => 'no data found' ) );
        }
        $category          = isset( $_POST['category'] ) ? wp_unslash( $_POST['category'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $exclude_class_obj = new \Wptc_ExcludeOption( $category );
        $data              = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        // phpcs:enable WordPress.Security.NonceVerification
        $exclude_class_obj->include_file_list( wp_unslash( $data ) );
        die();
    }

    /**
     * Get files by key.
     *
     * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
     */
    public function get_files_by_key() {
        // phpcs:disable WordPress.Security.NonceVerification
        $key      = isset( $_POST['key'] ) ? wp_unslash( $_POST['key'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $category = isset( $_POST['category'] ) ? wp_unslash( $_POST['category'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        // phpcs:enable WordPress.Security.NonceVerification
        $exclude_class_obj = new \Wptc_ExcludeOption( $category );
        $exclude_class_obj->get_files_by_key( $key );
        die();
    }

    /**
     * Process the WP Time Capsule login process.
     *
     * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
     *
     * @return array Action result.
     */
    private function process_wptc_login() { // phpcs:ignore -- NOSONAR - 3rd compatible, multi return.
        $options_helper = new \Wptc_Options_Helper();

        if ( $options_helper->get_is_user_logged_in() ) {
            return array(
                'result'    => 'is_user_logged_in',
                'sync_data' => $this->get_sync_data(),
            );
        }
        // phpcs:disable WordPress.Security.NonceVerification
        $email = isset( $_POST['acc_email'] ) ? sanitize_text_field( wp_unslash( $_POST['acc_email'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $pwd   = isset( $_POST['acc_pwd'] ) ? wp_unslash( $_POST['acc_pwd'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        // phpcs:enable WordPress.Security.NonceVerification
        if ( empty( $email ) || empty( $pwd ) ) {
            return array( 'error' => 'Username and password cannot be empty' );
        }

        $config  = \WPTC_Base_Factory::get( 'Wptc_InitialSetup_Config' );
        $options = \WPTC_Factory::get( 'config' );

        // phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- transport encoding the WPTC service expects, not obfuscation.
        $config->set_option( 'wptc_main_acc_email_temp', base64_encode( $email ) );
        $config->set_option( 'wptc_main_acc_pwd_temp', base64_encode( md5( trim( wp_unslash( $pwd ) ) ) ) ); // NOSONAR - compatible.
        // phpcs:enable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
        $config->set_option( 'wptc_token', false );

        $cust_info = $options->request_service(
            array(
                'email'                 => $email,
                'pwd'                   => trim( wp_unslash( $pwd ) ),
                'return_response'       => true,
                'sub_action'            => false,
                'login_request'         => true,
                'reset_login_if_failed' => true,
            )
        );

        if ( ! empty( $cust_info->error ) ) {
            if ( ! empty( $cust_info->true_err_msg ) ) {
                return array( 'error' => $cust_info->true_err_msg );
            }

            return array( 'error' => $cust_info->error );
        }

        $options->reset_plans();

        $main_account_email = $email;
        $main_account_pwd   = $pwd;

        $options->set_option( 'main_account_email', strtolower( $main_account_email ) );
        $options->set_option( 'main_account_pwd', $this->hash_pwd( $main_account_pwd ) );

        $result = $this->proccess_cust_info( $options, $cust_info );

        if ( is_array( $result ) && ! empty( $result['error'] ) ) {
            return array( 'error' => $result['error'] );
        }

        $is_user_logged_in = $options->get_option( 'is_user_logged_in' );

        if ( ! $is_user_logged_in ) {
            return array( 'error' => 'Login failed.' );
        }
        return array(
            'result'    => 'ok',
            'sync_data' => $this->get_sync_data(),
        );
    }

    /**
     * Hash password.
     *
     * @param string $str String to hash.
     *
     * @return string Hashed password.
     */
    public function hash_pwd( $str ) {
        return md5( $str ); // NOSONAR - 3rd compatible.
    }

    /**
     * Process the sigin response info.
     *
     * @param object $options   Options object.
     * @param object $cust_info Custon info.
     *
     * @return bool Action result.
     */
    public function proccess_cust_info( $options, $cust_info ) {

        if ( $this->process_service_info( $options, $cust_info ) ) {

            if ( empty( $cust_info->success ) ) {
                return false;
            }

            $cust_req_info = $cust_info->success[0];
            $this_d_name   = $cust_req_info->cust_display_name;
            $this_token    = $cust_req_info->wptc_token;

            $options->set_option( 'uuid', $cust_req_info->uuid );
            $options->set_option( 'wptc_token', $this_token );
            $options->set_option( 'main_account_name', $this_d_name );

            do_action( 'update_white_labling_settings_wptc', $cust_req_info );

            if ( isset( $cust_req_info->connected_sites_count ) ) {
                $options->set_option( 'connected_sites_count', $cust_req_info->connected_sites_count );
            } else {
                $options->set_option( 'connected_sites_count', 1 );
            }

            if ( ! empty( $cust_info->logged_in_but_no_plans_yet ) ) {
                $options->do_options_for_no_plans_yet( $cust_info );
                return false;
            }

            $options->process_subs_info_wptc( $cust_req_info );
            $this->process_privilege_wptc( $options, $cust_req_info );
            $this->save_plan_info_limited( $options, $cust_req_info );

            $is_cron_service = $this->check_if_cron_service_exists( $options );
            wptc_log( $is_cron_service, '--------$is_cron_service--------' );

            if ( $is_cron_service ) {
                $options->set_option( 'is_user_logged_in', true );
                return true;
            }
        }
    }


    /**
     * Save the plan info.
     *
     * @param object $options   Options object.
     * @param object $cust_info Custon info.
     *
     * @return bool Action result.
     */
    private function save_plan_info_limited( $options, &$cust_info ) {
        wptc_log( func_get_args(), '--------' . __FUNCTION__ . '--------' );
        if ( empty( $cust_info ) || empty( $cust_info->plan_info_limited ) ) {
            return $options->set_option( 'plan_info_limited', false );
        } else {
            $plans = json_decode( wp_json_encode( $cust_info->plan_info_limited ), true );
            wptc_log( $plans, '----------$plans----------------' );
            return $options->set_option( 'plan_info_limited', serialize( $plans ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Required for backwards compatibility.
        }
    }

    /**
     * Process privilege wptc.
     *
     * @param object $options       Options object.
     * @param object $cust_req_info Custon info.
     *
     * @return bool Returns false on failure.
     */
    private function process_privilege_wptc( $options, $cust_req_info = null ) {

        if ( empty( $cust_req_info->subscription_features ) ) {
            $options->reset_privileges();
            return false;
        }

        $sub_features = (array) $cust_req_info->subscription_features;

        $privileged_feature = array();
        $privileges_args    = array();

        foreach ( $sub_features as $single_sub ) {
            foreach ( $single_sub as $v ) {
                $privileged_feature[ $v->type ][]                    = 'Wptc_' . ucfirst( $v->feature );
                $privileges_args[ 'Wptc_' . ucfirst( $v->feature ) ] = ( ! empty( $v->args ) ) ? $v->args : array();
            }
        }

        // Remove on production!
        array_push( $privileges_args, 'Wptc_Rollback' );
        array_push( $privileged_feature['pro'], 'Wptc_Rollback' );

        $options->set_option( 'privileges_wptc', wp_json_encode( $privileged_feature ) );
        $options->set_option( 'privileges_args', wp_json_encode( $privileges_args ) );
        $revision_limit = new \Wptc_Revision_Limit();
        $revision_limit->update_eligible_revision_limit( $privileges_args );
    }

    /**
     * Process service info.
     *
     * @param object $options   Options object.
     * @param object $cust_info Custon info.
     *
     * @return bool result.
     */
    private function process_service_info( $options, &$cust_info ) {
        if ( empty( $cust_info ) || ! empty( $cust_info->error ) ) {
            $err_msg = $options->process_wptc_error_msg_then_take_action( $cust_info );

            $options->set_option( 'card_added', false );

            if ( 'logged_in_but_no_plans_yet' === $err_msg ) {
                $options->do_options_for_no_plans_yet( $cust_info );

                return true;
            }
            return false;
        } else {
            return true;
        }
    }

    /**
     * Process service info.
     *
     * @param object $options Options object.
     *
     * @return bool Result.
     */
    public function check_if_cron_service_exists( $options ) {
        if ( ( ! $options->get_option( 'wptc_server_connected' ) || ! $options->get_option( 'appID' ) || $options->get_option( 'signup' ) !== 'done' ) && $options->get_option( 'main_account_email' ) ) {
            $this->signup_wptc_server_wptc();
        }
        return true;
    }


    /**
     * Function for wptc cron service signup
     *
     * @return bool result.
     */
    public function signup_wptc_server_wptc() {

        $config = \WPTC_Factory::get( 'config' );

        // phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- transport encoding the WPTC service expects, not obfuscation.
        $email         = trim( $config->get_option( 'main_account_email', true ) );
        $emailhash     = md5( $email ); // NOSONAR - 3rd compatible.
        $email_encoded = base64_encode( $email );

        $pwd         = trim( $config->get_option( 'main_account_pwd', true ) );
        $pwd_encoded = base64_encode( $pwd );
        // phpcs:enable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

        if ( empty( $email ) || empty( $pwd ) ) {
            return false;
        }

        wptc_log( $email, '--------email--------' );

        $name = trim( $config->get_option( 'main_account_name' ) );

        $cron_url = get_wptc_cron_url();

        $app_id = 0;
        if ( $config->get_option( 'appID' ) ) {
            $app_id = $config->get_option( 'appID' );
        }

        $post_arr = array(
            'email'     => $email_encoded,
            'pwd'       => $pwd_encoded,
            'cron_url'  => $cron_url,
            'site_url'  => home_url(),
            'name'      => $name,
            'emailhash' => $emailhash,
            'app_id'    => $app_id,
        );

        $result = do_cron_call_wptc( 'signup', $post_arr );

        $resarr = json_decode( $result );

        wptc_log( $resarr, '--------resarr-node reply--------' );

        if ( ! empty( $resarr ) && 'success' === $resarr->status ) {
            $config->set_option( 'wptc_server_connected', true );
            $config->set_option( 'signup', 'done' );
            $config->set_option( 'appID', $resarr->appID );

            init_auto_backup_settings_wptc( $config );
            return true;
        } else {
            $config->set_option( 'last_service_error', $result );
            $config->set_option( 'appID', false );

            if ( 'production' !== WPTC_ENV ) {
                echo 'Creating Cron service failed';
            }

            return false;
        }
    }

    /**
     * Get the list of installed plugins.
     *
     * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
     *
     * @return array Action result.
     */
    public function get_installed_plugins() {

        $backup_before_auto_update_settings = \WPTC_Pro_Factory::get( 'Wptc_Backup_Before_Auto_Update_Settings' );
        $plugins                            = $backup_before_auto_update_settings->get_installed_plugins();

        if ( $plugins ) {
            return array( 'results' => $plugins );
        }
        return array( 'results' => array() );
    }

    /**
     * Get the list of installed themes.
     *
     * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
     *
     * @return array Action result.
     */
    public function get_installed_themes() {

        $backup_before_auto_update_settings = \WPTC_Pro_Factory::get( 'Wptc_Backup_Before_Auto_Update_Settings' );

        $plugins = $backup_before_auto_update_settings->get_installed_themes();
        if ( $plugins ) {
            return array( 'results' => $plugins );
        }
        return array( 'results' => array() );
    }

    /**
     * Check if staging request needed.
     *
     * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
     */
    public function is_staging_need_request() {
        $staging = \WPTC_Pro_Factory::get( 'Wptc_Staging' );
        $staging->is_staging_need_request();
        die();
    }

    /**
     * Get the staging details.
     *
     * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
     */
    public function get_staging_details_wptc() {
        $staging               = \WPTC_Pro_Factory::get( 'Wptc_Staging' );
        $details               = $staging->get_staging_details();
        $details['is_running'] = $staging->is_any_staging_process_going_on();
        wptc_die_with_json_encode( $details, 1 );
    }

    /**
     * Create a fresh staging site.
     *
     * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
     */
    public function start_fresh_staging_wptc() {
        $staging = \WPTC_Pro_Factory::get( 'Wptc_Staging' );
        // phpcs:disable WordPress.Security.NonceVerification
        if ( empty( $_POST['path'] ) ) {
            wptc_die_with_json_encode(
                array(
                    'status' => 'error',
                    'msg'    => 'path is missing',
                )
            );
        }

        $staging->choose_action( wp_unslash( $_POST['path'] ), 'fresh' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        // phpcs:enable WordPress.Security.NonceVerification
        die();
    }

    /**
     * Get the staging site URL.
     *
     * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
     */
    public function get_staging_url_wptc() {
        $staging = \WPTC_Pro_Factory::get( 'Wptc_Staging' );
        $staging->get_staging_url_wptc();
        die();
    }

    /**
     * Stop the staging site creation process.
     *
     * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
     */
    public function stop_staging_wptc() {
        $staging = \WPTC_Pro_Factory::get( 'Wptc_Staging' );
        $staging->stop_staging_wptc();
        die();
    }

    /**
     * Continue the staging site creation process.
     *
     * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
     */
    public function continue_staging_wptc() {
        $staging = \WPTC_Pro_Factory::get( 'Wptc_Staging' );
        $staging->choose_action();
        die();
    }

    /**
     * Delete the staging site.
     *
     * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
     */
    public function delete_staging_wptc() {
        $staging = \WPTC_Pro_Factory::get( 'Wptc_Staging' );
        $staging->delete_staging_wptc();
        die();
    }

    /**
     * Copy the staging site.
     *
     * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
     */
    public function copy_staging_wptc() {
        $staging = \WPTC_Pro_Factory::get( 'Wptc_Staging' );
        $staging->choose_action( false, 'copy' );
        die();
    }

    /**
     * Get the current staging site status key.
     *
     * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
     */
    public function get_staging_current_status_key() {
        $staging = \WPTC_Pro_Factory::get( 'Wptc_Staging' );
        $staging->get_staging_current_status_key();
        die();
    }

    /**
     * Sync the WP Time Capsule purchase.
     *
     * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
     */
    public function wptc_sync_purchase() {
        $config = \WPTC_Factory::get( 'config' );

        $config->request_service(
            array(
                'email'           => false,
                'pwd'             => false,
                'return_response' => false,
                'sub_action'      => 'sync_all_settings_to_node',
                'login_request'   => true,
            )
        );
        die();
    }

    /**
     * Initiate the restore process.
     *
     * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
     */
    public function init_restore() {
        // phpcs:disable WordPress.Security.NonceVerification
        if ( empty( $_POST ) ) {
            wptc_die_with_json_encode( array( 'error' => 'Backup id is empty !' ) );
        }
        $restore_to_staging = \WPTC_Base_Factory::get( 'Wptc_Restore_To_Staging' );
        $restore_to_staging->init_restore( $_POST );
        // phpcs:enable WordPress.Security.NonceVerification
        die();
    }

    /**
     * Save the WP Time Capsule settings.
     *
     * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
     */
    public function save_settings_wptc() {

        $options_helper = new \Wptc_Options_Helper();

        if ( ! $options_helper->get_is_user_logged_in() ) {
            return array(
                'sync_data' => $this->get_sync_data(),
                'error'     => 'Login to your WP Time Capsule account first',
            );
        }

        // phpcs:disable WordPress.Security.NonceVerification
        $data = isset( $_POST['data'] ) ? json_decode( base64_decode( wp_unslash( $_POST['data'] ) ), true ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.PHP.DiscouragedPHPFunctions -- base64_encode required for the backwards compatibility.

        $tabName    = isset( $_POST['tabname'] ) ? sanitize_text_field( wp_unslash( $_POST['tabname'] ) ) : '';
        $is_general = isset( $_POST['is_general'] ) ? sanitize_text_field( wp_unslash( $_POST['is_general'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification

        $saved  = false;
        $config = \WPTC_Factory::get( 'config' );
        if ( 'backup' === $tabName ) {
            $this->save_settings_backup_tab( $config, $data );
            $saved = true;
        } elseif ( 'backup_auto' === $tabName ) {
            $this->save_settings_backup_auto_tab( $config, $data, $is_general );
            $saved = true;
        } elseif ( 'vulns_update' === $tabName ) {
            $this->save_settings_vulns_update_tab( $config, $data, $is_general );
            $saved = true;
        } elseif ( 'staging_opts' === $tabName ) {
            $this->save_settings_staging_opts_tab( $config, $data, $is_general );
            $saved = true;
        }
        if ( ! $saved ) {
            return array( 'error' => 'Error: Not saved settings' );
        }
        return array( 'result' => 'ok' );
    }

    /**
     * Save the WP Time Capsule settings - backups section.
     *
     * @param object $config Save config class.
     * @param array  $data   Data to save.
     */
    private function save_settings_backup_tab( $config, $data ) {

        $config->set_option( 'user_excluded_extenstions', $data['user_excluded_extenstions'] );
        $config->set_option( 'user_excluded_files_more_than_size_settings', $data['user_excluded_files_more_than_size_settings'] );

        if ( ! empty( $data['backup_slot'] ) ) {
            $config->set_option( 'old_backup_slot', $config->get_option( 'backup_slot' ) );
            $config->set_option( 'backup_slot', $data['backup_slot'] );
        }

        $config->set_option( 'backup_db_query_limit', $data['backup_db_query_limit'] );
        $config->set_option( 'database_encrypt_settings', $data['database_encrypt_settings'] );
        $config->set_option( 'wptc_timezone', $data['wptc_timezone'] );
        $config->set_option( 'schedule_time_str', $data['schedule_time_str'] );

        if ( ! empty( $data['schedule_time_str'] ) && ! empty( $data['wptc_timezone'] ) && function_exists( 'wptc_modify_schedule_backup' ) ) {
            wptc_modify_schedule_backup();
        }

        $notice = apply_filters( 'check_requirements_auto_backup_wptc', '' );

        if ( ! empty( $data['revision_limit'] ) && ! $notice ) {
            $notice = apply_filters( 'save_settings_revision_limit_wptc', $data['revision_limit'] ); // NOSONAR - apply 3rd filter.
        }
    }

    /**
     * Save the WP Time Capsule settings - backups auto section.
     *
     * @param object $config     Save config class.
     * @param array  $data       Data to save.
     * @param bool   $is_general Is general settings check.
     */
    private function save_settings_backup_auto_tab( $config, $data, $is_general ) {
        $config->set_option( 'backup_before_update_setting', $data['backup_before_update_setting'] );
        $current                              = $config->get_option( 'wptc_auto_update_settings' );
        $current = unserialize( $current ); // phpcs:ignore -- safe internal value, third party.
        $new     = unserialize( $data['wptc_auto_update_settings'] ); // phpcs:ignore -- compatible third party // NOSONARR .
        $current['update_settings']['status'] = $new['update_settings']['status'];
        $current['update_settings']['schedule']['enabled']     = $new['update_settings']['schedule']['enabled'];
        $current['update_settings']['schedule']['time']        = $new['update_settings']['schedule']['time'];
        $current['update_settings']['core']['major']['status'] = $new['update_settings']['core']['major']['status'];
        $current['update_settings']['core']['minor']['status'] = $new['update_settings']['core']['minor']['status'];
        $current['update_settings']['themes']['status']        = $new['update_settings']['themes']['status'];
        $current['update_settings']['plugins']['status']       = $new['update_settings']['plugins']['status'];

        if ( ! $is_general ) {
            if ( isset( $new['update_settings']['plugins']['included'] ) ) {
                $current['update_settings']['plugins']['included'] = $new['update_settings']['plugins']['included'];
            } else {
                $current['update_settings']['plugins']['included'] = array();
            }

            if ( isset( $new['update_settings']['themes']['included'] ) ) {
                $current['update_settings']['themes']['included'] = $new['update_settings']['themes']['included'];
            } else {
                $current['update_settings']['themes']['included'] = array();
            }
        }
        $config->set_option( 'wptc_auto_update_settings', serialize( $current ) ); // phpcs:ignore -- safe internal value.
    }

    /**
     * Save the WP Time Capsule settings - vulnerable updates section.
     *
     * @param object $config     Save config class.
     * @param array  $data       Data to save.
     * @param bool   $is_general Is general settings check.
     */
    private function save_settings_vulns_update_tab( $config, $data, $is_general ) {
        $current = $config->get_option( 'vulns_settings' );
        $current = unserialize( $current ); // phpcs:ignore -- safe internal value, third party.
        $new     = unserialize( $data['vulns_settings'] ); // phpcs:ignore -- third party compatible // NOSONARR .

        $current['status']            = $new['status'];
        $current['core']['status']    = $new['core']['status'];
        $current['themes']['status']  = $new['themes']['status'];
        $current['plugins']['status'] = $new['plugins']['status'];

        if ( ! $is_general ) {
            $vulns_plugins_included = ! empty( $new['plugins']['vulns_plugins_included'] ) ? $new['plugins']['vulns_plugins_included'] : array();

            $plugin_include_array = array();

            if ( ! empty( $vulns_plugins_included ) ) {
                $plugin_include_array = explode( ',', $vulns_plugins_included );
                $plugin_include_array = ! empty( $plugin_include_array ) ? $plugin_include_array : array();
            }

            wptc_log( $plugin_include_array, '--------$plugin_include_array--------' );

            $included_plugins = $this->filter_plugins( $plugin_include_array );

            wptc_log( $included_plugins, '--------$included_plugins--------' );

            $current['plugins']['excluded'] = serialize( $included_plugins ); // phpcs:ignore -- safe internal value, third party.

            $vulns_themes_included = ! empty( $new['themes']['vulns_themes_included'] ) ? $new['themes']['vulns_themes_included'] : array();

            $themes_include_array = array();

            if ( ! empty( $vulns_themes_included ) ) {
                $themes_include_array = explode( ',', $vulns_themes_included );
            }

            $included_themes               = $this->filter_themes( $themes_include_array );
            $current['themes']['excluded'] = serialize( $included_themes ); // phpcs:ignore -- safe internal value, third party.
        }
        $config->set_option( 'vulns_settings', serialize( $current ) ); // phpcs:ignore -- safe internal value, third party.
    }

    /**
     * Save the WP Time Capsule settings - staging section.
     *
     * @param object $config     Save config class.
     * @param array  $data       Data to save.
     * @param bool   $is_general Is general settings check.
     */
    private function save_settings_staging_opts_tab( $config, $data, $is_general ) {
        $config->set_option( 'user_excluded_extenstions_staging', $data['user_excluded_extenstions_staging'] );
        $config->set_option( 'internal_staging_db_rows_copy_limit', $data['internal_staging_db_rows_copy_limit'] );
        $config->set_option( 'internal_staging_file_copy_limit', $data['internal_staging_file_copy_limit'] );
        $config->set_option( 'internal_staging_deep_link_limit', $data['internal_staging_deep_link_limit'] );
        $config->set_option( 'internal_staging_enable_admin_login', $data['internal_staging_enable_admin_login'] );
        $config->set_option( 'staging_is_reset_permalink', $data['staging_is_reset_permalink'] );
        if ( ! $is_general ) {
            $config->set_option( 'staging_login_custom_link', $data['staging_login_custom_link'] );
        }
    }

    /**
     * Filter plugins.
     *
     * @param array $included_plugins List of included plugins.
     *
     * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
     *
     * @return array Filtered list of plugins.
     */
    private function filter_plugins( $included_plugins ) {
        $app_functions       = \WPTC_Base_Factory::get( 'Wptc_App_Functions' );
        $specific            = true;
        $attr                = 'slug';
        $plugins_data        = $app_functions->get_all_plugins_data( $specific, $attr );
        $not_included_plugin = array_diff( $plugins_data, $included_plugins );
        wptc_log( $plugins_data, '--------$plugins_data--------' );
        wptc_log( $not_included_plugin, '--------$not_included_plugin--------' );
        return $not_included_plugin;
    }

    /**
     * Filter themes.
     *
     * @param array $included_themes List of included themes.
     *
     * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
     *
     * @return array Filtered list of themes.
     */
    private function filter_themes( $included_themes ) {
        $app_functions      = \WPTC_Base_Factory::get( 'Wptc_App_Functions' );
        $specific           = true;
        $attr               = 'slug';
        $themes_data        = $app_functions->get_all_themes_data( $specific, $attr );
        $not_included_theme = array_diff( $themes_data, $included_themes );
        wptc_log( $themes_data, '--------$themes_data--------' );
        wptc_log( $not_included_theme, '--------$not_included_theme--------' );
        return $not_included_theme;
    }

    /**
     * Analyze database tables.
     *
     * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
     */
    public function analyze_inc_exc() {
        $exclude_opts_obj = \WPTC_Base_Factory::get( 'Wptc_ExcludeOption' );
        $exclude_opts_obj = $exclude_opts_obj->analyze_inc_exc();
        die();
    }

    /**
     * Get enabled plugins.
     *
     * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
     *
     * @return array Action result.
     */
    public function get_enabled_plugins() {
        $vulns_obj = \WPTC_Base_Factory::get( 'Wptc_Vulns' );

        $plugins = $vulns_obj->get_enabled_plugins();
        $plugins = \WPTC_Base_Factory::get( 'Wptc_App_Functions' )->fancytree_format( $plugins, 'plugins' );

        return array( 'results' => $plugins );
    }

    /**
     * Get enabled themes.
     *
     * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
     *
     * @return array Action result.
     */
    public function get_enabled_themes() {
        $vulns_obj = \WPTC_Base_Factory::get( 'Wptc_Vulns' );
        $themes    = $vulns_obj->get_enabled_themes();
        $themes    = \WPTC_Base_Factory::get( 'Wptc_App_Functions' )->fancytree_format( $themes, 'themes' );
        return array( 'results' => $themes );
    }

    /**
     * Get the system info.
     *
     * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
     *
     * @return array Action result.
     */
    public function get_system_info() {

        /**
         * Object, providing access to the WordPress database.
         *
         * @global $wpdb WordPress Database instance.
         */
        global $wpdb;

        $wptc_settings = \WPTC_Base_Factory::get( 'Wptc_Settings' );

        ob_start();

        echo '<table class="wp-list-table widefat fixed" style="border-spacing:0;">';
        echo '<thead><tr><th width="35%">' . esc_html__( 'Setting', 'mainwp-child' ) . '</th><th>' . esc_html__( 'Value', 'mainwp-child' ) . '</th></tr></thead>';
        echo '<tr title="&gt;=3.9.14"><td>' . esc_html__( 'WordPress version', 'mainwp-child' ) . '</td><td>' . esc_html( $wptc_settings->get_plugin_data( 'wp_version' ) ) . '</td></tr>';
        echo '<tr title=""><td>' . esc_html__( 'WP Time Capsule version', 'mainwp-child' ) . '</td><td>' . esc_html( $wptc_settings->get_plugin_data( 'Version' ) ) . '</td></tr>';

        $bit = '';
        if ( PHP_INT_SIZE === 4 ) {
            $bit = ' (32bit)';
        }
        if ( PHP_INT_SIZE === 8 ) {
            $bit = ' (64bit)';
        }

        echo '<tr title="&gt;=5.3.1"><td>' . esc_html__( 'PHP version', 'mainwp-child' ) . '</td><td>' . esc_html( PHP_VERSION . ' ' . $bit ) . '</td></tr>';

        // Retrieve MySQL version with caching (static server metadata).
        $mysql_version = wp_cache_get( 'mainwp_timecapsule_mysql_version', 'mainwp_timecapsule' );
        if ( false === $mysql_version ) {
            $mysql_version = $wpdb->get_var( 'SELECT VERSION() AS version' ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Static server metadata, cached for 1 hour.
            wp_cache_set( 'mainwp_timecapsule_mysql_version', $mysql_version, 'mainwp_timecapsule', 3600 );
        }
        echo '<tr title="&gt;=5.0.15"><td>' . esc_html__( 'MySQL version', 'mainwp-child' ) . '</td><td>' . esc_html( $mysql_version ) . '</td></tr>';

        if ( function_exists( 'curl_version' ) ) {
            $curlversion = curl_version();
            echo '<tr title=""><td>' . esc_html__( 'cURL version', 'mainwp-child' ) . '</td><td>' . esc_html( $curlversion['version'] ) . '</td></tr>';
            echo '<tr title=""><td>' . esc_html__( 'cURL SSL version', 'mainwp-child' ) . '</td><td>' . esc_html( $curlversion['ssl_version'] ) . '</td></tr>';
        } else {
            echo '<tr title=""><td>' . esc_html__( 'cURL version', 'mainwp-child' ) . '</td><td>' . esc_html__( 'unavailable', 'mainwp-child' ) . '</td></tr>';
        }

        echo '</td></tr>';
        echo '<tr title=""><td>' . esc_html__( 'Server', 'mainwp-child' ) . '</td><td>' . ( isset( $_SERVER['SERVER_SOFTWARE'] ) ? esc_html( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) ) : '' ) . '</td></tr>';
        echo '<tr title=""><td>' . esc_html__( 'Operating System', 'mainwp-child' ) . '</td><td>' . esc_html( PHP_OS ) . '</td></tr>';
        echo '<tr title=""><td>' . esc_html__( 'PHP SAPI', 'mainwp-child' ) . '</td><td>' . esc_html( PHP_SAPI ) . '</td></tr>';

        $php_user = esc_html__( 'Function Disabled', 'mainwp-child' );
        if ( function_exists( 'get_current_user' ) ) {
            $php_user = get_current_user();
        }

        echo '<tr title=""><td>' . esc_html__( 'Current PHP user', 'mainwp-child' ) . '</td><td>' . esc_html( $php_user ) . '</td></tr>';
        echo '<tr title="&gt;=30"><td>' . esc_html__( 'Maximum execution time', 'mainwp-child' ) . '</td><td>' . esc_html( ini_get( 'max_execution_time' ) ) . ' ' . esc_html__( 'seconds', 'mainwp-child' ) . '</td></tr>';

        if ( defined( 'FS_CHMOD_DIR' ) ) {
            echo '<tr title="FS_CHMOD_DIR"><td>' . esc_html__( 'CHMOD Dir', 'mainwp-child' ) . '</td><td>' . esc_html( FS_CHMOD_DIR ) . '</td></tr>';
        } else {
            echo '<tr title="FS_CHMOD_DIR"><td>' . esc_html__( 'CHMOD Dir', 'mainwp-child' ) . '</td><td>0755</td></tr>';
        }

        $now = localtime( time(), true );
        echo '<tr title=""><td>' . esc_html__( 'Server Time', 'mainwp-child' ) . '</td><td>' . esc_html( $now['tm_hour'] . ':' . $now['tm_min'] ) . '</td></tr>';
        echo '<tr title=""><td>' . esc_html__( 'Blog Time', 'mainwp-child' ) . '</td><td>' . esc_html( date( 'H:i', current_time( 'timestamp' ) ) ) . '</td></tr>'; // phpcs:ignore -- local time.
        echo '<tr title="WPLANG"><td>' . esc_html__( 'Blog language', 'mainwp-child' ) . '</td><td>' . esc_html( get_bloginfo( 'language' ) ) . '</td></tr>';
        echo '<tr title="utf8"><td>' . esc_html__( 'MySQL Client encoding', 'mainwp-child' ) . '</td><td>';
        echo defined( 'DB_CHARSET' ) ? esc_html( DB_CHARSET ) : '';
        echo '</td></tr>';
        echo '<tr title="UTF-8"><td>' . esc_html__( 'Blog charset', 'mainwp-child' ) . '</td><td>' . esc_html( get_bloginfo( 'charset' ) ) . '</td></tr>';
        echo '<tr title="&gt;=128M"><td>' . esc_html__( 'PHP Memory limit', 'mainwp-child' ) . '</td><td>' . esc_html( ini_get( 'memory_limit' ) ) . '</td></tr>';
        echo '<tr title="WP_MEMORY_LIMIT"><td>' . esc_html__( 'WP memory limit', 'mainwp-child' ) . '</td><td>' . esc_html( WP_MEMORY_LIMIT ) . '</td></tr>';
        echo '<tr title="WP_MAX_MEMORY_LIMIT"><td>' . esc_html__( 'WP maximum memory limit', 'mainwp-child' ) . '</td><td>' . esc_html( WP_MAX_MEMORY_LIMIT ) . '</td></tr>';
        echo '<tr title=""><td>' . esc_html__( 'Memory in use', 'mainwp-child' ) . '</td><td>' . esc_html( size_format( memory_get_usage( true ), 2 ) ) . '</td></tr>';

        // disabled PHP functions.
        $disabled = esc_html( ini_get( 'disable_functions' ) );
        if ( ! empty( $disabled ) ) {
            $disabledarry = explode( ',', $disabled );
            echo '<tr title=""><td>' . esc_html__( 'Disabled PHP Functions:', 'mainwp-child' ) . '</td><td>';
            echo esc_html( implode( ', ', $disabledarry ) );
            echo '</td></tr>';
        }

        // Loaded PHP Extensions.
        echo '<tr title=""><td>' . esc_html__( 'Loaded PHP Extensions:', 'mainwp-child' ) . '</td><td>';
        $extensions = get_loaded_extensions();
        sort( $extensions );
        echo esc_html( implode( ', ', $extensions ) );
        echo '</td></tr>';
        echo '</table>';

        $html = ob_get_clean();
        return array( 'result' => $html );
    }

    /**
     * Update vulnerable updates settings.
     *
     * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
     *
     * @return array Action result.
     */
    public function update_vulns_settings() {

        $vulns_obj = \WPTC_Base_Factory::get( 'Wptc_Vulns' );
        // phpcs:disable WordPress.Security.NonceVerification
        $data = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        // phpcs:enable WordPress.Security.NonceVerification
        $vulns_obj->update_vulns_settings( $data );

        return array( 'success' => 1 );
    }

    /**
     * Start a fresh backup.
     *
     * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
     *
     * @return array Action result.
     */
    public function start_fresh_backup_tc_callback_wptc() {
        $type            = '';
        $args            = null;
        $test_connection = true;
        $ajax_check      = false;
        start_fresh_backup_tc_callback_wptc( $type, $args, $test_connection, $ajax_check );
        return array( 'result' => 'success' );
    }

    /**
     * Save manual backup name.
     *
     * @used-by MainWP_Child_Timecapsule::action() Fire off certain WP Time Capsule plugin actions.
     */
    public function save_manual_backup_name_wptc() {
        // phpcs:disable WordPress.Security.NonceVerification
        $backup_name = isset( $_POST['backup_name'] ) ? sanitize_text_field( wp_unslash( $_POST['backup_name'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification
        $processed_files = \WPTC_Factory::get( 'processed-files' );
        $processed_files->save_manual_backup_name_wptc( $backup_name );
        die();
    }

    /**
     * Remove the WP Time Capsule plugin from the list of all plugins when the plugin is hidden.
     *
     * @param array $plugins Array containing all installed plugins.
     *
     * @return array $plugins Array containing all installed plugins without the WP Time Capsule.
     */
    public function all_plugins( $plugins ) {
        foreach ( $plugins as $key => $value ) {
            $plugin_slug = basename( $key, '.php' );
            if ( 'wp-time-capsule' === $plugin_slug ) {
                unset( $plugins[ $key ] );
            }
        }

        return $plugins;
    }

    /**
     * Remove the WP Time Capsule menu item when the plugin is hidden.
     */
    public function remove_menu() {
        remove_menu_page( 'wp-time-capsule-monitor' );
        $pos = isset( $_SERVER['REQUEST_URI'] ) ? stripos( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), 'admin.php?page=wp-time-capsule-monitor' ) : false;
        if ( false !== $pos ) {
            wp_safe_redirect( get_option( 'siteurl' ) . '/wp-admin/index.php' );
            exit();
        }
    }

    /**
     * Remove the WP Time Capsule plugin update notice when the plugin is hidden.
     *
     * @param array $slugs Array containing installed plugins slugs.
     *
     * @return array $slugs Array containing installed plugins slugs.
     */
    public function hide_update_notice( $slugs ) {
        $slugs[] = 'wp-time-capsule/wp-time-capsule.php';
        return $slugs;
    }

    /**
     * Remove the WP Time Capsule plugin update notice when the plugin is hidden.
     *
     * @param object $value Object containing update information.
     *
     * @return object $value Object containing update information.
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
        if ( isset( $value->response['wp-time-capsule/wp-time-capsule.php'] ) ) {
            unset( $value->response['wp-time-capsule/wp-time-capsule.php'] );
        }

        return $value;
    }
}
