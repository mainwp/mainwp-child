<?php
/**
 * MainWP Child Staging.
 *
 * MainWP Staging Extension handler.
 *
 * @link https://mainwp.com/extension/staging/
 *
 * @package MainWP\Child
 *
 * Credits
 *
 * Plugin-Name: WP Staging
 * Plugin URI: https://wordpress.org/plugins/wp-staging
 * Author: WP-Staging
 * Author URI: https://wp-staging.com
 * Contributors: ReneHermi, ilgityildirim
 *
 * The code is used for the MainWP Staging Extension
 * Extension URL: https://mainwp.com/extension/staging/
 */

namespace MainWP\Child;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable PSR1.Classes.ClassDeclaration, WordPress.WP.AlternativeFunctions -- Required to achieve desired results. Pull requests appreciated.

/**
 * Class MainWP_Child_Staging
 *
 * MainWP Staging Extension handler.
 */
class MainWP_Child_Staging { //phpcs:ignore -- NOSONAR - multi methods.

    /**
     * How many clone-mutation receipts the option holds.
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
     * Option holding the clone-mutation receipts.
     *
     * Two stores now share the same receipt helpers, so which one a call writes to is an
     * argument rather than a literal repeated at each site: a mistyped literal would write one
     * store's evidence into the other and be silent about it.
     *
     * @var string
     */
    const ABILITIES_V2_OPERATION_RECEIPTS_OPTION = 'mainwp_staging_abilities_v2_operation_receipts';

    /**
     * Option holding the settings-replacement receipts.
     *
     * @var string
     */
    const ABILITIES_V2_SETTINGS_RECEIPTS_OPTION = 'mainwp_staging_abilities_v2_receipts';

    /**
     * Public static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    public static $instance = null;

    /**
     * Public variable to hold the information if the WP Staging plugin is installed on the child site.
     *
     * @var bool If WP Staging intalled, return true, if not, return false.
     */
    public $is_plugin_installed = false;

    /**
     * Public variable to hold the plugin slug.
     *
     * @var string slug string.
     */
    public $the_plugin_slug = 'wp-staging/wp-staging.php';

    /**
     * Public variable to hold the plugin slug.
     *
     * @var string slug string.
     */
    public $the_plugin_slug_pro = 'wp-staging-pro/wp-staging-pro.php';

    /**
     * Public variable to hold the information if the WP Staging plugin is installed on the child site.
     *
     * @var string version string.
     */
    public $plugin_version = false;

    /**
     * Public assets variable.
     *
     * @var object assets.
     */
    public $assets = null;

    /**
     * Public plugin file variable.
     *
     * @var string plugin file path.
     */
    public static $plugin_file = '';

    /**
     * Create a public static instance of MainWP_Child_Staging.
     *
     * @return MainWP_Child_Staging|null
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * MainWP_Child_Staging constructor.
     *
     * Run any time class is called.
     */
    public function __construct() {
        require_once ABSPATH . 'wp-admin/includes/plugin.php'; // NOSONAR - WP compatible.

        if ( is_plugin_active( $this->the_plugin_slug ) ) {
            $this->is_plugin_installed = true;
            static::$plugin_file       = trailingslashit( WP_PLUGIN_DIR ) . $this->the_plugin_slug;
        } elseif ( is_plugin_active( $this->the_plugin_slug_pro ) ) {
            $this->is_plugin_installed = true;
            static::$plugin_file       = trailingslashit( WP_PLUGIN_DIR ) . $this->the_plugin_slug_pro;
        }

        if ( ! $this->is_plugin_installed ) {
            return;
        }

        add_filter( 'mainwp_site_sync_others_data', array( $this, 'sync_others_data' ), 10, 2 );
    }


    /**
     * Initiate actions & filters.
     */
    public function init() {
        if ( 'Y' !== get_option( 'mainwp_wp_staging_ext_enabled' ) ) {
            return;
        }

        if ( ! $this->is_plugin_installed ) {
            return;
        }

        if ( 'hide' === get_option( 'mainwp_wp_staging_hide_plugin' ) ) {
            add_filter( 'all_plugins', array( $this, 'all_plugins' ) );
            add_action( 'admin_menu', array( $this, 'remove_menu' ) );
            add_filter( 'site_transient_update_plugins', array( &$this, 'remove_update_nag' ) );
            add_filter( 'mainwp_child_hide_update_notice', array( &$this, 'hide_update_notice' ) );
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
     * @uses MainWP_Child_Staging::get_sync_data()
     *
     * @return array $information An array of available clones.
     */
    public function sync_others_data( $information, $data = array() ) {
        if ( isset( $data['syncWPStaging'] ) && $data['syncWPStaging'] ) {
            try {
                $information['syncWPStaging'] = $this->get_sync_data();
            } catch ( MainWP_Exception $e ) {
                // ok!
            }
        }
        return $information;
    }

    /**
     * Fires off MainWP_Child_Staging::get_overview().
     *
     * @uses MainWP_Child_Staging::get_overview()
     * @return array An array of available clones.
     */
    public function get_sync_data() {
        $legacy = $this->get_overview();
        $v2     = $this->abilities_v2(
            array(
                'protocol'  => '2',
                'operation' => 'inventory',
                'payload'   => array(),
            )
        );
        if ( is_array( $v2 ) && ! empty( $v2['ok'] ) ) {
            $legacy['abilitiesV2Inventory'] = $v2;
        }
        return $legacy;
    }

    /**
     * Fires of certain WP Staging plugin actions.
     *
     * @uses \WPStaging\WPStaging::getInstance()
     * @uses \MainWP\Child\MainWP_Helper::update_option()
     * @uses \MainWP\Child\MainWP_Helper::write()
     * @uses \MainWP\Child\MainWP_Child_Staging::set_showhide()
     * @uses \MainWP\Child\MainWP_Child_Staging::save_settings()
     * @uses \MainWP\Child\MainWP_Child_Staging::get_overview()
     * @uses \MainWP\Child\MainWP_Child_Staging::get_scan()
     * @uses \MainWP\Child\MainWP_Child_Staging::ajax_check_free_space()
     * @uses \MainWP\Child\MainWP_Child_Staging::ajax_check_clone_name()
     * @uses \MainWP\Child\MainWP_Child_Staging::ajax_start_clone()
     * @uses \MainWP\Child\MainWP_Child_Staging::ajax_clone_database()
     * @uses \MainWP\Child\MainWP_Child_Staging::ajax_prepare_directories()
     * @uses \MainWP\Child\MainWP_Child_Staging::ajax_replace_data()
     * @uses \MainWP\Child\MainWP_Child_Staging::ajax_finish()
     * @uses \MainWP\Child\MainWP_Child_Staging::ajax_delete_confirmation()
     * @uses \MainWP\Child\MainWP_Child_Staging::ajax_delete_clone()
     * @uses \MainWP\Child\MainWP_Child_Staging::ajax_cancel_clone()
     * @uses \MainWP\Child\MainWP_Child_Staging::ajax_update_process()
     * @uses \MainWP\Child\MainWP_Child_Staging::ajax_cancel_update()
     * @uses \MainWP\Child\MainWP_Child_Staging::MainWP_Helper::write()
     */
    public function action() { // phpcs:ignore -- NOSONAR - ignore complex method notice.
        $mwp_action = MainWP_System::instance()->validate_params( 'mwp_action' );

        // A protocol-2 request has to come back in protocol 2 even when WP Staging is missing or
        // fails to load. The legacy error blobs below end the request, and the Dashboard cannot
        // tell them apart from an old Child or a broken transport; the v2 envelope reports an
        // absent provider by dropping the operations it cannot back.
        $is_abilities_v2 = 'abilities_v2' === $mwp_action;

        if ( ! $this->is_plugin_installed ) {
            if ( $is_abilities_v2 ) {
                MainWP_Helper::write( $this->abilities_v2_action() );
            }
            MainWP_Helper::write( array( 'error' => esc_html__( 'Please install WP Staging plugin on child website', 'mainwp-child' ) ) );
        }

        $loaded_success = true;

        if ( ! defined( 'WPSTG_PLUGIN_DIR' ) ) {
            include_once static::$plugin_file; // requires.
            global $pluginFilePath; // requires.
            $plugin_dir = dirname( static::$plugin_file );
            $files      = array(
                $plugin_dir . '/runtimeRequirements.php',
                $plugin_dir . '/bootstrap.php',
            );
            foreach ( $files as $file ) {
                if ( file_exists( $file ) ) {
                    require_once $file;
                } else {
                    $loaded_success = false;
                }
            }
        }

        if ( false === $loaded_success || ! defined( 'WPSTG_PLUGIN_DIR' ) ) {
            if ( $is_abilities_v2 ) {
                MainWP_Helper::write( $this->abilities_v2_action() );
            }
            MainWP_Helper::instance()->error( esc_html__( 'WP Staging failed to load correctly on the child website.', 'mainwp-child' ), 'STAG_ERROR_NOT_LOADED' );
        }

        if ( ! class_exists( '\WPStaging\WPStaging' ) && ! class_exists( '\WPStaging\Core\WPStaging' ) ) {
            if ( file_exists( WPSTG_PLUGIN_DIR . 'app/Core/WPStaging.php' ) ) {
                require_once WPSTG_PLUGIN_DIR . 'app/Core/WPStaging.php'; // NOSONAR - WP compatible.
            } elseif ( file_exists( WPSTG_PLUGIN_DIR . 'Core/WPStaging.php' ) ) {
                require_once WPSTG_PLUGIN_DIR . 'Core/WPStaging.php'; // NOSONAR - WP compatible.
            }
        }

        if ( class_exists( '\WPStaging\Core\WPStaging' ) ) {
            $this->plugin_version = '2.8';
            \WPStaging\Core\WPStaging::getInstance();
        } elseif ( class_exists( '\WPStaging\WPStaging' ) ) {
            $this->plugin_version = '2.7';
            \WPStaging\WPStaging::getInstance();
        }

        $information = array();

        if ( 'Y' !== get_option( 'mainwp_wp_staging_ext_enabled' ) ) {
            MainWP_Helper::update_option( 'mainwp_wp_staging_ext_enabled', 'Y', 'yes' );
        }

        if ( ! empty( $mwp_action ) ) {
            switch ( $mwp_action ) {
                case 'set_showhide':
                    $information = $this->set_showhide();
                    break;
                case 'save_settings':
                    $information = $this->save_settings();
                    break;
                case 'get_overview':
                    $information = $this->get_overview();
                    break;
                case 'abilities_v2':
                    $information = $this->abilities_v2_action();
                    break;
                case 'get_scan':
                    $information = $this->get_scan();
                    break;
                case 'check_disk_space':
                    $information = $this->ajax_check_free_space();
                    break;
                case 'check_clone':
                    $information = $this->ajax_check_clone_name();
                    break;
                case 'start_clone':
                    $information = $this->ajax_start_clone();
                    break;
                case 'clone_database':
                    $information = $this->ajax_clone_database();
                    break;
                case 'prepare_directories':
                    $information = $this->ajax_start_files();
                    break;
                case 'copy_files':
                    $information = $this->ajax_start_files();
                    break;
                case 'replace_data':
                    $information = $this->ajax_start_files();
                    break;
                case 'clone_finish':
                    $information = $this->ajax_finish();
                    break;
                case 'delete_confirmation':
                    $information = $this->ajax_delete_confirmation();
                    break;
                case 'delete_clone':
                    $information = $this->ajax_delete_clone();
                    break;
                case 'cancel_clone':
                    $information = $this->ajax_cancel_clone();
                    break;
                case 'staging_update':
                    $information = $this->ajax_update_process();
                    break;
                case 'cancel_update':
                    $information = $this->ajax_cancel_update();
                    break;
                default:
                    break;
            }
        }
        MainWP_Helper::write( $information );
    }

    /**
     * Sets whether or not to hide the WP Staging Plugin.
     *
     * @return array $information Action result.
     *
     * @uses \MainWP\Child\MainWP_Helper::update_option()
     */
    public function set_showhide() {
        $hide = MainWP_System::instance()->validate_params( 'showhide' );
        MainWP_Helper::update_option( 'mainwp_wp_staging_hide_plugin', $hide, 'yes' );
        $information['result'] = 'SUCCESS';
        return $information;
    }

    /**
     * Save WP Staging settings.
     *
     * @return string[] Return 'Success'.
     */
    public function save_settings() {
        $settings = isset( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $filters  = array(
            'queryLimit',
            'fileLimit',
            'batchSize',
            'cpuLoad',
            'delayRequests',
            'disableAdminLogin',
            'querySRLimit',
            'maxFileSize',
            'debugMode',
            'unInstallOnDelete',
            'checkDirectorySize',
            'optimizer',
        );

        $save_fields = array();
        foreach ( $filters as $field ) {
            if ( isset( $settings[ $field ] ) ) {
                $save_fields[ $field ] = $settings[ $field ];
            }
        }
        update_option( 'wpstg_settings', $save_fields );
        return array( 'result' => 'success' );
    }

    /**
     * Get array of available clones.
     *
     * @return array $return Action result.
     */
    public function get_overview() {
        if ( defined( '\WPStaging\Staging\Sites::STAGING_SITES_OPTION' ) ) { // new update.
            $return = array(
                'availableClones' => get_option( \WPStaging\Staging\Sites::STAGING_SITES_OPTION, array() ),
            );
        } elseif ( defined( '\WPStaging\Framework\Staging\Sites::STAGING_SITES_OPTION' ) ) {
            $return = array(
                'availableClones' => get_option( \WPStaging\Framework\Staging\Sites::STAGING_SITES_OPTION, array() ),
            );
        } else {
            $return = array(
                'availableClones' => get_option( 'wpstg_existing_clones_beta', array() ),
            );
        }
        return $return;
    }

    /**
     * Decode the additive Staging abilities-v2 transport request.
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
     * Process one closed Staging abilities-v2 request.
     *
     * Read operations deliberately avoid WP Staging's ambient Scan job. That
     * class mutates provider state and therefore cannot back an Ability read.
     *
     * @param mixed $request Decoded request.
     * @return array Closed protocol response.
     */
    // phpcs:disable Generic.Commenting.DocComment.MissingShort,Squiz.Commenting.FunctionComment,Generic.Formatting.MultipleStatementAlignment,WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound,WordPress.Arrays.MultipleStatementAlignment,WordPress.PHP.YodaConditions.NotYoda -- Closed protocol block follows the legacy file's compact style.
    public function abilities_v2( $request ) {
        $operation = is_array( $request ) && isset( $request['operation'] ) && is_string( $request['operation'] ) ? $request['operation'] : 'unknown';
        $mutations = array( 'replace_settings', 'create_clone', 'update_clone', 'delete_clone', 'cancel_operation', 'reconcile_operation' );
        $root_keys = in_array( $operation, $mutations, true ) ? array( 'protocol', 'operation', 'request_ref', 'payload' ) : array( 'protocol', 'operation', 'payload' );
        if ( ! is_array( $request ) || ! $this->abilities_v2_exact_keys( $request, $root_keys ) || '2' !== $request['protocol'] || ! is_array( $request['payload'] ) ) {
            return $this->abilities_v2_error( $operation );
        }

        $supported = $this->abilities_v2_supported_operations();
        if ( 'capabilities' === $operation ) {
            if ( array() !== $request['payload'] ) {
                return $this->abilities_v2_error( $operation );
            }

            return array(
                'protocol'           => '2',
                'operation'          => 'capabilities',
                'ok'                 => true,
                'operations'         => $supported,
                'mutation_supported' => array() !== array_intersect( $mutations, $supported ),
                'wp_staging_version' => $this->abilities_v2_plugin_version(),
            );
        }

        // Anything this Child cannot carry out is refused by name instead of being advertised and then run anyway.
        if ( ! in_array( $operation, $supported, true ) ) {
            return $this->abilities_v2_error( $operation, 'unsupported_operation' );
        }

        if ( 'replace_settings' === $operation ) {
            return $this->abilities_v2_replace_settings( $request );
        }

        if ( in_array( $operation, array( 'create_clone', 'update_clone', 'delete_clone', 'cancel_operation', 'reconcile_operation' ), true ) ) {
            return $this->abilities_v2_mutation( $operation, $request );
        }

        if ( 'inventory' === $operation ) {
            if ( array() !== $request['payload'] ) {
                return $this->abilities_v2_error( $operation );
            }
            return $this->abilities_v2_inventory();
        }

        if ( 'settings' === $operation ) {
            if ( array() !== $request['payload'] ) {
                return $this->abilities_v2_error( $operation );
            }
            return $this->abilities_v2_settings();
        }

        if ( 'operation_status' === $operation ) {
            if ( ! $this->abilities_v2_exact_keys( $request['payload'], array( 'operation_ref' ) ) || ! $this->abilities_v2_valid_operation_ref( $request['payload']['operation_ref'] ) ) {
                return $this->abilities_v2_error( $operation );
            }
            return $this->abilities_v2_provider_result( $operation, $request['payload'] );
        }

        if ( 'preview' !== $operation || ! $this->abilities_v2_exact_keys( $request['payload'], array( 'kind', 'clone_ref' ) ) ) {
            return $this->abilities_v2_error( $operation );
        }

        $kind      = $request['payload']['kind'];
        $clone_ref = $request['payload']['clone_ref'];
        if ( ! in_array( $kind, array( 'create', 'update' ), true ) || ( 'create' === $kind && null !== $clone_ref ) || ( 'update' === $kind && ! $this->abilities_v2_valid_hash( $clone_ref ) ) ) {
            return $this->abilities_v2_error( $operation );
        }

        $inventory = $this->abilities_v2_inventory_rows();
        if ( false === $inventory ) {
            return $this->abilities_v2_error( $operation, 'provider_schema_invalid' );
        }
        if ( 'update' === $kind && ! isset( $inventory[ $clone_ref ] ) ) {
            return $this->abilities_v2_error( $operation, 'clone_not_found' );
        }

        $preview = $this->abilities_v2_provider_preview( $kind, $clone_ref, $inventory );
        if ( ! is_array( $preview ) || ! $this->abilities_v2_exact_keys( $preview, array( 'table_count', 'file_count', 'estimated_bytes', 'disk_sufficient', 'isolation_ready', 'warnings' ) ) || ! $this->abilities_v2_valid_preview( $preview ) ) {
            return $this->abilities_v2_error( $operation, 'provider_schema_invalid' );
        }

        return array_merge(
            array(
                'protocol'           => '2',
                'operation'          => 'preview',
                'ok'                 => true,
                'kind'               => $kind,
                'clone_ref'          => $clone_ref,
                'inventory_revision' => $this->abilities_v2_inventory_revision( $inventory ),
            ),
            $preview
        );
    }

    /**
     * List the operations this Child can actually carry out.
     *
     * Reading and replacing settings both answer for `wpstg_settings`, an option only WP Staging
     * creates. Without the plugin there is nothing to replace and creating the option would invent
     * provider state, and the read is worse still: it would clamp an absent option into a full set
     * of defaults and hand the Dashboard a revision for settings no site ever had. Clone jobs need
     * that adapter, so they are neither advertised nor dispatched until a build wires it. Reading a
     * job's status needs it just as much: the adapter owns the step records, so without it there is
     * no operation to report on and every read would answer provider_unavailable. Preview needs a
     * side-effect-free provider scanner, and without one every request would reach the seam and
     * come back provider_schema_invalid, so it is refused by name instead of being advertised.
     *
     * @return array Executable operation names.
     */
    protected function abilities_v2_supported_operations() {
        $clone_jobs = array( 'create_clone', 'update_clone', 'delete_clone', 'operation_status', 'cancel_operation', 'reconcile_operation' );
        $jobs_ready = $this->abilities_v2_provider_supports_mutation();
        $supported  = array();
        foreach ( array( 'inventory', 'settings', 'preview', 'replace_settings', 'create_clone', 'update_clone', 'delete_clone', 'operation_status', 'cancel_operation', 'reconcile_operation' ) as $operation ) {
            if ( in_array( $operation, array( 'settings', 'replace_settings' ), true ) && ! $this->is_plugin_installed ) {
                continue;
            }
            if ( 'preview' === $operation && ! $this->abilities_v2_provider_supports_preview() ) {
                continue;
            }
            if ( ! $jobs_ready && in_array( $operation, $clone_jobs, true ) ) {
                continue;
            }
            $supported[] = $operation;
        }
        return $supported;
    }

    /**
     * Execute one receipt-bound typed clone mutation.
     *
     * @param string $operation Operation name.
     * @param array  $request Closed request.
     * @return array
     */
    private function abilities_v2_mutation( $operation, $request ) {
        if ( ! $this->abilities_v2_valid_request_ref( $request['request_ref'] ) || ! $this->abilities_v2_valid_mutation_payload( $operation, $request['payload'] ) ) {
            return $this->abilities_v2_error( $operation );
        }

        // The receipt lookup and the clone job it guards have to be one atomic step, or two
        // concurrent requests both read "no receipt", both reach the provider, and the second
        // write of the option drops the first request's receipt along with its only record.
        $lock = $this->abilities_v2_begin_lock();
        if ( null === $lock ) {
            return $this->abilities_v2_error( $operation, 'storage_unavailable' );
        }
        if ( true !== $lock ) {
            return $this->abilities_v2_error( $operation, 'lock_busy' );
        }
        try {
            return $this->abilities_v2_locked_mutation( $operation, $request );
        } finally {
            $this->abilities_v2_end_lock();
        }
    }

    /**
     * Run one clone mutation at most once, under the held mutation lock.
     *
     * @param string $operation Operation name.
     * @param array  $request Closed request.
     * @return array
     */
    private function abilities_v2_locked_mutation( $operation, $request ) {
        $effect_hash = hash( 'sha256', wp_json_encode( array( $operation, $request['payload'] ) ) );
        $receipts    = get_option( self::ABILITIES_V2_OPERATION_RECEIPTS_OPTION, array() );
        if ( ! is_array( $receipts ) ) {
            return $this->abilities_v2_error( $operation, 'storage_unavailable' );
        }
        // A key holding null is still evidence that something wrote a receipt for this reference,
        // and isset() reads it as absent. Missing it here starts the clone job a second time.
        if ( array_key_exists( $request['request_ref'], $receipts ) ) {
            $receipt = $receipts[ $request['request_ref'] ];
            if ( ! $this->abilities_v2_valid_receipt( $receipt ) ) {
                return $this->abilities_v2_error( $operation, 'storage_unavailable' );
            }
            if ( ! hash_equals( $receipt['effect_hash'], $effect_hash ) ) {
                return $this->abilities_v2_error( $operation, 'request_conflict' );
            }
            if ( 'dispatching' !== $receipt['state'] ) {
                return $receipt['response'];
            }
            // A reservation nobody settled belongs to a request that died between the clone job
            // starting and its outcome reaching the store. That job may still be running, so
            // starting it again would clone twice and the outcome is the only honest answer.
            if ( $this->abilities_v2_reservation_is_live( $receipt['created_at'] ) ) {
                return $this->abilities_v2_error( $operation, 'outcome_unknown' );
            }
            // Past the horizon the reservation proves nothing: no Dashboard retry of it is still
            // expected, and a reference that can only ever answer outcome_unknown is worse than a
            // second clone job. Retire it and let this request dispatch.
            unset( $receipts[ $request['request_ref'] ] );
        }

        // Room has to exist before the provider is touched. A clone job this Child cannot record
        // is one the Dashboard's next retry runs a second time.
        $receipts = $this->abilities_v2_evict_receipts( $receipts, self::ABILITIES_V2_OPERATION_RECEIPTS_OPTION );
        if ( false === $receipts ) {
            return $this->abilities_v2_error( $operation, 'storage_unavailable' );
        }

        // The reservation is durable before the clone job starts, not after it. A request that
        // dies with the job already running - or one whose settling write is refused - has to
        // leave its retry something to land on, or that retry clones a second time.
        $receipts[ $request['request_ref'] ] = array(
            'effect_hash' => $effect_hash,
            'state'       => 'dispatching',
            'response'    => null,
            'created_at'  => time(),
        );
        if ( ! $this->abilities_v2_store_receipts( $receipts, self::ABILITIES_V2_OPERATION_RECEIPTS_OPTION ) ) {
            return $this->abilities_v2_error( $operation, 'storage_unavailable' );
        }

        $result = $this->abilities_v2_provider_result( $operation, $request['payload'] );
        if ( ! empty( $result['ok'] ) ) {
            $result['request_ref'] = $request['request_ref'];
            $result                = array_merge( array_intersect_key( $result, array( 'protocol' => true, 'operation' => true, 'ok' => true ) ), array( 'request_ref' => $request['request_ref'] ), array_diff_key( $result, array( 'protocol' => true, 'operation' => true, 'ok' => true, 'request_ref' => true ) ) );
        }

        // A refusal is settled too. Leaving it unsettled would answer every retry of this
        // reference outcome_unknown for a full day over a provider that never touched anything.
        $receipts[ $request['request_ref'] ]['state']    = 'settled';
        $receipts[ $request['request_ref'] ]['response'] = $result;
        if ( ! $this->abilities_v2_store_receipts( $receipts, self::ABILITIES_V2_OPERATION_RECEIPTS_OPTION ) ) {
            return $this->abilities_v2_error( $operation, 'outcome_unknown' );
        }
        return $result;
    }

    /**
     * Persist the clone-mutation receipt store.
     *
     * A false answer from update_option() means either a refused write or one that changed
     * nothing, so the stored value decides which of the two happened.
     *
     * @param array  $receipts Receipts to store.
     * @param string $option Store to write.
     * @return bool
     */
    private function abilities_v2_store_receipts( $receipts, $option ) {
        return update_option( $option, $receipts, false ) || get_option( $option, array() ) === $receipts;
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
     * it; there the retired reference re-queues a cleanup, here it would start a second clone. A
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
     * Validate one stored clone-mutation receipt.
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
     * @param array  $receipts Current receipts.
     * @param string $option Store the repaired entries belong to.
     * @return array|false Receipts with room for one more, or false when nothing can be given up.
     */
    private function abilities_v2_evict_receipts( $receipts, $option ) {
        if ( self::ABILITIES_V2_MAX_RECEIPTS > count( $receipts ) ) {
            return $receipts;
        }
        $repaired  = false;
        $evictable = array();
        foreach ( $receipts as $reference => $receipt ) {
            if ( ! $this->abilities_v2_valid_request_ref( $reference ) ) {
                // No request can present a reference this store would refuse, so nothing will ever
                // come back for this entry. It is evidence for nobody, and dropping it is what
                // keeps a store of junk keys from holding every later mutation shut. Array keys are
                // not always strings either, so this is also what stops one being compared as one.
                $evictable[ $reference ] = 0;
                continue;
            }
            if ( ! $this->abilities_v2_valid_receipt( $receipt ) ) {
                // An entry nobody can read is still evidence that something wrote a receipt for
                // that reference, so its clone job may already have run. Giving it up to make room
                // for an unrelated request is how a reference loses its only evidence and clones a
                // second time on its next retry. Rebuilt as a tombstone it keeps failing the
                // receipt check, so its own reference still answers storage_unavailable, while
                // gaining a date this store can act on.
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
            // clone mutation from then on. It is re-dated rather than dropped or held: dropping it
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
        // entries would then refuse every clone mutation forever with no way out. Writing it here
        // freezes those stamps and lets the entries cross the horizon on their own.
        if ( $repaired && ! $this->abilities_v2_store_receipts( $receipts, $option ) ) {
            return false;
        }
        return self::ABILITIES_V2_MAX_RECEIPTS > count( $receipts ) ? $receipts : false;
    }

    /**
     * Rebuild one unreadable entry as a dated tombstone under the same reference.
     *
     * Keeping the reference is what stops its retry from starting a second clone job. What the
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
     * Return this installation's named clone-mutation lock.
     *
     * @return string
     */
    protected function abilities_v2_lock_name() {
        return 'mainwp_staging_v2_' . substr( hash( 'sha256', home_url( '/' ) ), 0, 32 );
    }

    /**
     * Acquire the Child-wide clone-mutation lock without waiting.
     *
     * @return bool|null True when acquired, false when another session holds it, null when the lock backend could not answer.
     */
    protected function abilities_v2_begin_lock() {
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
     * Release the Child-wide clone-mutation lock.
     *
     * @return bool
     */
    protected function abilities_v2_end_lock() {
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

    /** @param string $operation Operation. @param array $payload Payload. @return array */
    private function abilities_v2_provider_result( $operation, $payload ) {
        if ( ! $this->abilities_v2_provider_supports_mutation() ) {
            return $this->abilities_v2_error( $operation, 'provider_unavailable' );
        }
        try {
            $result = $this->abilities_v2_provider_operation( $operation, $payload );
        } catch ( \Throwable $throwable ) {
            return $this->abilities_v2_error( $operation, 'outcome_unknown' );
        }
        if ( is_wp_error( $result ) ) {
            $code = $result->get_error_code();
            return $this->abilities_v2_error( $operation, in_array( $code, array( 'provider_unavailable', 'provider_schema_invalid', 'clone_not_found', 'operation_not_found', 'stale_revision', 'state_conflict', 'outcome_unknown', 'storage_unavailable' ), true ) ? $code : 'provider_unavailable' );
        }
        if ( ! $this->abilities_v2_valid_operation_result( $operation, $result ) ) {
            return $this->abilities_v2_error( $operation, 'provider_schema_invalid' );
        }
        return array_merge( array( 'protocol' => '2', 'operation' => $operation, 'ok' => true ), $result );
    }

    /**
     * Provider-specific durable step boundary.
     *
     * Supported versions override this seam only after they can bind every
     * step to immutable path/table tokens. The base integration fails closed.
     *
     * @param string $operation Operation.
     * @param array  $payload Payload.
     * @return array|WP_Error
     */
    protected function abilities_v2_provider_operation( $operation, $payload ) {
        unset( $operation, $payload );
        return new \WP_Error( 'provider_unavailable' );
    }

    /** @param string $operation Operation. @param mixed $payload Payload. @return bool */
    private function abilities_v2_valid_mutation_payload( $operation, $payload ) {
        if ( ! is_array( $payload ) ) {
            return false;
        }
        if ( 'create_clone' === $operation ) {
            return $this->abilities_v2_exact_keys( $payload, array( 'inventory_revision' ) ) && $this->abilities_v2_valid_hash( $payload['inventory_revision'] );
        }
        if ( 'update_clone' === $operation ) {
            return $this->abilities_v2_exact_keys( $payload, array( 'clone_ref', 'inventory_revision' ) ) && $this->abilities_v2_valid_hash( $payload['clone_ref'] ) && $this->abilities_v2_valid_hash( $payload['inventory_revision'] );
        }
        if ( 'delete_clone' === $operation ) {
            return $this->abilities_v2_exact_keys( $payload, array( 'clone_ref', 'if_match' ) ) && $this->abilities_v2_valid_hash( $payload['clone_ref'] ) && $this->abilities_v2_valid_hash( $payload['if_match'] );
        }
        return in_array( $operation, array( 'cancel_operation', 'reconcile_operation' ), true ) && $this->abilities_v2_exact_keys( $payload, array( 'operation_ref', 'if_match' ) ) && $this->abilities_v2_valid_operation_ref( $payload['operation_ref'] ) && $this->abilities_v2_valid_hash( $payload['if_match'] );
    }

    /** @param string $operation Operation. @param mixed $result Result. @return bool */
    private function abilities_v2_valid_operation_result( $operation, $result ) {
        if ( ! is_array( $result ) ) {
            return false;
        }
        if ( in_array( $operation, array( 'create_clone', 'update_clone', 'delete_clone' ), true ) ) {
            return $this->abilities_v2_exact_keys( $result, array( 'operation_ref', 'clone_ref', 'status' ) ) && $this->abilities_v2_valid_operation_ref( $result['operation_ref'] ) && ( null === $result['clone_ref'] || $this->abilities_v2_valid_hash( $result['clone_ref'] ) ) && in_array( $result['status'], array( 'queued', 'running', 'reconciliation_required' ), true );
        }
        if ( 'operation_status' === $operation ) {
            return $this->abilities_v2_exact_keys( $result, array( 'operation_ref', 'kind', 'status', 'progress_percent', 'current_step', 'generation' ) ) && $this->abilities_v2_valid_operation_ref( $result['operation_ref'] ) && in_array( $result['kind'], array( 'create', 'update', 'delete', 'reconcile' ), true ) && in_array( $result['status'], array( 'queued', 'running', 'verifying', 'succeeded', 'failed', 'cancelled', 'unknown', 'reconciliation_required' ), true ) && is_int( $result['progress_percent'] ) && 0 <= $result['progress_percent'] && 100 >= $result['progress_percent'] && ( null === $result['current_step'] || in_array( $result['current_step'], array( 'scan', 'database', 'directories', 'files', 'replace', 'finish', 'delete', 'verify' ), true ) ) && $this->abilities_v2_valid_hash( $result['generation'] );
        }
        return in_array( $operation, array( 'cancel_operation', 'reconcile_operation' ), true ) && $this->abilities_v2_exact_keys( $result, array( 'operation_ref', 'status', 'affected_steps', 'generation' ) ) && $this->abilities_v2_valid_operation_ref( $result['operation_ref'] ) && in_array( $result['status'], array( 'running', 'cancelling', 'cancelled', 'queued', 'reconciliation_required' ), true ) && is_int( $result['affected_steps'] ) && 0 <= $result['affected_steps'] && 100000 >= $result['affected_steps'] && $this->abilities_v2_valid_hash( $result['generation'] );
    }

    /**
     * Return the complete redacted clone inventory.
     *
     * @return array Closed protocol response.
     */
    private function abilities_v2_inventory() {
        $rows = $this->abilities_v2_inventory_rows();
        if ( false === $rows ) {
            return $this->abilities_v2_error( 'inventory', 'provider_schema_invalid' );
        }

        return array(
            'protocol'           => '2',
            'operation'          => 'inventory',
            'ok'                 => true,
            // A list recovered from the pre-registry option is not one WP Staging still
            // maintains, so it cannot be reported as the complete clone set.
            'complete'           => 'provider' === $this->abilities_v2_clone_source(),
            'observed_at'        => gmdate( 'c' ),
            'wp_staging_version' => $this->abilities_v2_plugin_version(),
            'inventory_revision' => $this->abilities_v2_inventory_revision( $rows ),
            'clones'             => array_values( $rows ),
        );
    }

    /**
     * Normalize the provider clone option without exposing path, URL or name.
     *
     * @return array|false Clone rows keyed by opaque reference.
     */
    protected function abilities_v2_inventory_rows() {
        $clones = $this->abilities_v2_provider_clones();
        if ( ! is_array( $clones ) || 10000 < count( $clones ) ) {
            return false;
        }

        $rows = array();
        foreach ( $clones as $clone_key => $clone ) {
            if ( ! is_string( $clone_key ) || '' === $clone_key || 128 < strlen( $clone_key ) || ! is_array( $clone ) ) {
                return false;
            }
            $identity = $this->abilities_v2_clone_identity( $clone_key, $clone );
            if ( false === $identity ) {
                return false;
            }
            $clone_ref = hash_hmac( 'sha256', $identity['binding'], wp_salt( 'auth' ) );
            if ( isset( $rows[ $clone_ref ] ) ) {
                return false;
            }
            $rows[ $clone_ref ] = array(
                'clone_ref'                   => $clone_ref,
                'state'                       => $identity['state'],
                'isolated'                    => $identity['isolated'],
                'search_index_blocked'        => $identity['search_index_blocked'],
                'outbound_side_effects_blocked' => $identity['outbound_side_effects_blocked'],
                'created_at'                  => $identity['created_at'],
                'updated_at'                  => $identity['updated_at'],
                'revision'                    => hash( 'sha256', $identity['binding'] ),
            );
        }
        ksort( $rows );
        return $rows;
    }

    /**
     * Read raw clone metadata from the provider's authoritative option.
     *
     * @return array
     */
    protected function abilities_v2_provider_clones() {
        if ( defined( '\\WPStaging\\Staging\\Sites::STAGING_SITES_OPTION' ) ) {
            return get_option( \WPStaging\Staging\Sites::STAGING_SITES_OPTION, array() );
        }
        if ( defined( '\\WPStaging\\Framework\\Staging\\Sites::STAGING_SITES_OPTION' ) ) {
            return get_option( \WPStaging\Framework\Staging\Sites::STAGING_SITES_OPTION, array() );
        }
        return get_option( 'wpstg_existing_clones_beta', array() );
    }

    /**
     * Name where the clone list came from.
     *
     * Without either registry constant there is no WP Staging to answer for the list;
     * the reader falls back to the pre-registry option, which nothing keeps current.
     *
     * @return string Either 'provider' or 'legacy_option'.
     */
    protected function abilities_v2_clone_source() {
        return defined( '\\WPStaging\\Staging\\Sites::STAGING_SITES_OPTION' ) || defined( '\\WPStaging\\Framework\\Staging\\Sites::STAGING_SITES_OPTION' ) ? 'provider' : 'legacy_option';
    }

    /**
     * Build an internal clone binding and bounded public facts.
     *
     * @param string $clone_key Provider clone key.
     * @param array  $clone_data Provider clone metadata.
     * @return array|false
     */
    private function abilities_v2_clone_identity( $clone_key, $clone_data ) {
        foreach ( array( 'directoryName', 'path', 'url' ) as $required ) {
            if ( ! isset( $clone_data[ $required ] ) || ! is_string( $clone_data[ $required ] ) || '' === $clone_data[ $required ] || 4096 < strlen( $clone_data[ $required ] ) || false !== strpos( $clone_data[ $required ], "\0" ) ) {
                return false;
            }
        }

        $real_root  = realpath( ABSPATH );
        $clone_path = realpath( $clone_data['path'] );
        if ( false === $real_root || false === $clone_path || $clone_path === $real_root || 0 !== strpos( trailingslashit( $clone_path ), trailingslashit( $real_root ) ) ) {
            return false;
        }

        $created_at = $this->abilities_v2_optional_time( isset( $clone_data['createdAt'] ) ? $clone_data['createdAt'] : null );
        $updated_at = $this->abilities_v2_optional_time( isset( $clone_data['updatedAt'] ) ? $clone_data['updatedAt'] : null );
        if ( false === $created_at || false === $updated_at ) {
            return false;
        }

        $binding = wp_json_encode(
            array(
                'clone_key'    => $clone_key,
                'directory'    => $clone_data['directoryName'],
                'path'         => $clone_path,
                'url'          => $clone_data['url'],
                'db_prefix'    => isset( $clone_data['databasePrefix'] ) && is_string( $clone_data['databasePrefix'] ) ? $clone_data['databasePrefix'] : '',
                'wpstg_version' => $this->abilities_v2_plugin_version(),
            )
        );
        if ( ! is_string( $binding ) ) {
            return false;
        }

        return array(
            'binding'                      => $binding,
            'state'                        => $this->abilities_v2_clone_state( $clone_data ),
            // WP Staging records none of these three today. An absent key means the Child
            // never observed the guard, which is not the same claim as observing it off.
            'isolated'                     => $this->abilities_v2_optional_flag( $clone_data, 'isolated' ),
            'search_index_blocked'         => $this->abilities_v2_optional_flag( $clone_data, 'searchIndexBlocked' ),
            'outbound_side_effects_blocked' => $this->abilities_v2_optional_flag( $clone_data, 'outboundSideEffectsBlocked' ),
            'created_at'                   => $created_at,
            'updated_at'                   => $updated_at,
        );
    }

    /** @param array $clone_data Provider clone metadata. @return string */
    private function abilities_v2_clone_state( $clone_data ) {
        $status = isset( $clone_data['status'] ) && is_string( $clone_data['status'] ) ? $clone_data['status'] : '';
        if ( 'finished' === $status ) {
            return 'ready';
        }
        // WP Staging leaves an interrupted clone marked 'unfinished'. Any other value,
        // including a registry entry carrying no status at all, is not evidence of a
        // usable clone, so it reports as unknown instead of ready.
        return 'unfinished' === $status ? 'incomplete' : 'unknown';
    }

    /** @param array $clone_data Provider clone metadata. @param string $key Provider key. @return bool|null */
    private function abilities_v2_optional_flag( $clone_data, $key ) {
        return array_key_exists( $key, $clone_data ) ? ! empty( $clone_data[ $key ] ) : null;
    }

    /**
     * Return bounded operational settings without provider-private fields.
     *
     * @return array Closed protocol response.
     */
    private function abilities_v2_settings() {
        $settings = $this->abilities_v2_provider_settings();
        if ( ! is_array( $settings ) ) {
            return $this->abilities_v2_error( 'settings', 'provider_schema_invalid' );
        }
        $normalized = array(
            'query_limit'      => $this->abilities_v2_bounded_int( $settings, 'queryLimit', 10, 10000, 1000 ),
            'file_limit'       => $this->abilities_v2_file_limit( isset( $settings['fileLimit'] ) ? $settings['fileLimit'] : 500 ),
            'batch_size_mb'    => $this->abilities_v2_bounded_int( $settings, 'batchSize', 1, 100, 10 ),
            'max_file_size_mb' => $this->abilities_v2_bounded_int( $settings, 'maxFileSize', 1, 1024, 50 ),
            'cpu_load'         => isset( $settings['cpuLoad'] ) && in_array( $settings['cpuLoad'], array( 'low', 'medium', 'high' ), true ) ? $settings['cpuLoad'] : 'low',
            'delay_seconds'    => $this->abilities_v2_bounded_int( $settings, 'delayRequests', 0, 60, 1 ),
            'debug_enabled'    => ! empty( $settings['debugMode'] ),
        );
        if ( false === $normalized['file_limit'] ) {
            return $this->abilities_v2_error( 'settings', 'provider_schema_invalid' );
        }

        return array_merge(
            array(
                'protocol'  => '2',
                'operation' => 'settings',
                'ok'        => true,
                'revision'  => hash( 'sha256', wp_json_encode( $normalized ) ),
            ),
            $normalized
        );
    }

    /** @return array */
    protected function abilities_v2_provider_settings() {
        $settings = get_option( 'wpstg_settings', array() );
        return is_array( $settings ) ? $settings : array();
    }

    /**
     * Replace bounded provider settings with request replay and exact readback.
     *
     * @param array $request Closed request.
     * @return array Closed protocol response.
     */
    private function abilities_v2_replace_settings( $request ) {
        if ( ! $this->abilities_v2_valid_request_ref( $request['request_ref'] ) || ! $this->abilities_v2_exact_keys( $request['payload'], array( 'if_match', 'settings' ) ) || ! $this->abilities_v2_valid_hash( $request['payload']['if_match'] ) ) {
            return $this->abilities_v2_error( 'replace_settings' );
        }
        $settings = $this->abilities_v2_validate_public_settings( $request['payload']['settings'] );
        if ( false === $settings ) {
            return $this->abilities_v2_error( 'replace_settings' );
        }

        // The receipt lookup, the revision check and the provider write have to be one atomic
        // step. Without it two concurrent requests both read "no receipt", both write the
        // provider settings, and the second write of the option drops the first request's
        // receipt along with its only record of what it answered. The clone-mutation lock is
        // deliberately the same one: these settings are the batch, load and delay limits a
        // clone job queued alongside this request will run under.
        $lock = $this->abilities_v2_begin_lock();
        if ( null === $lock ) {
            return $this->abilities_v2_error( 'replace_settings', 'storage_unavailable' );
        }
        if ( true !== $lock ) {
            return $this->abilities_v2_error( 'replace_settings', 'lock_busy' );
        }
        try {
            return $this->abilities_v2_locked_replace_settings( $request, $settings );
        } finally {
            $this->abilities_v2_end_lock();
        }
    }

    /**
     * Replace provider settings at most once inside the retry horizon, under the held lock.
     *
     * The if_match revision check already stops the same request applying twice, so what the
     * receipt protects here is the answer rather than the effect: a request that dies between
     * the provider write and its receipt leaves its retry to fail the revision check and report
     * stale_revision, which says another writer moved the revision when in fact this request
     * did. A reservation that is durable before the provider runs turns that into
     * outcome_unknown, which is the only true statement available.
     *
     * @param array $request Closed request.
     * @param array $settings Validated public settings.
     * @return array Closed protocol response.
     */
    private function abilities_v2_locked_replace_settings( $request, $settings ) {
        $effect_hash = hash( 'sha256', wp_json_encode( array( $request['payload']['if_match'], $settings ) ) );
        $receipts    = get_option( self::ABILITIES_V2_SETTINGS_RECEIPTS_OPTION, array() );
        if ( ! is_array( $receipts ) ) {
            return $this->abilities_v2_error( 'replace_settings', 'storage_unavailable' );
        }
        // A key holding null is still evidence that something wrote a receipt for this
        // reference, and isset() reads it as absent.
        if ( array_key_exists( $request['request_ref'], $receipts ) ) {
            $receipt = $receipts[ $request['request_ref'] ];
            if ( ! $this->abilities_v2_valid_receipt( $receipt ) ) {
                return $this->abilities_v2_error( 'replace_settings', 'storage_unavailable' );
            }
            if ( ! hash_equals( $receipt['effect_hash'], $effect_hash ) ) {
                return $this->abilities_v2_error( 'replace_settings', 'request_conflict' );
            }
            if ( 'dispatching' !== $receipt['state'] ) {
                return $receipt['response'];
            }
            // A reservation nobody settled belongs to a request that died with the provider
            // write possibly already applied. Repeating it would decide the revision check
            // against state this request itself moved.
            if ( $this->abilities_v2_reservation_is_live( $receipt['created_at'] ) ) {
                return $this->abilities_v2_error( 'replace_settings', 'outcome_unknown' );
            }
            // Past the horizon nothing is still coming back for it, and a reference that can
            // only ever answer outcome_unknown is worse than letting the revision check decide.
            unset( $receipts[ $request['request_ref'] ] );
        }

        // Room has to exist before the provider is written, or this request's answer is one the
        // store cannot hold and its retry has nothing to land on.
        $receipts = $this->abilities_v2_evict_receipts( $receipts, self::ABILITIES_V2_SETTINGS_RECEIPTS_OPTION );
        if ( false === $receipts ) {
            return $this->abilities_v2_error( 'replace_settings', 'storage_unavailable' );
        }
        $receipts[ $request['request_ref'] ] = array(
            'effect_hash' => $effect_hash,
            'state'       => 'dispatching',
            'response'    => null,
            'created_at'  => time(),
        );
        if ( ! $this->abilities_v2_store_receipts( $receipts, self::ABILITIES_V2_SETTINGS_RECEIPTS_OPTION ) ) {
            return $this->abilities_v2_error( 'replace_settings', 'storage_unavailable' );
        }

        $response = $this->abilities_v2_write_settings( $request, $settings );
        // A refusal this Child decided is settled too: it is the final answer for this
        // reference, and leaving it unsettled would answer every retry outcome_unknown for a
        // day over a provider that was never written. The readback failures below are not
        // settled, because there the provider may well have been written and only the horizon
        // can safely release the reference.
        if ( isset( $response['error_code'] ) && 'outcome_unknown' === $response['error_code'] ) {
            return $response;
        }
        $receipts[ $request['request_ref'] ]['state']    = 'settled';
        $receipts[ $request['request_ref'] ]['response'] = $response;
        if ( ! $this->abilities_v2_store_receipts( $receipts, self::ABILITIES_V2_SETTINGS_RECEIPTS_OPTION ) ) {
            return $this->abilities_v2_error( 'replace_settings', 'outcome_unknown' );
        }
        return $response;
    }

    /**
     * Apply validated settings to the provider and verify the stored result exactly.
     *
     * @param array $request Closed request.
     * @param array $settings Validated public settings.
     * @return array Closed protocol response.
     */
    private function abilities_v2_write_settings( $request, $settings ) {
        $current = $this->abilities_v2_settings();
        if ( empty( $current['ok'] ) || ! isset( $current['revision'] ) || ! hash_equals( $current['revision'], $request['payload']['if_match'] ) ) {
            return $this->abilities_v2_error( 'replace_settings', 'stale_revision' );
        }

        $provider = $this->abilities_v2_provider_settings();
        if ( ! is_array( $provider ) ) {
            return $this->abilities_v2_error( 'replace_settings', 'provider_schema_invalid' );
        }
        $map = array(
            'query_limit'      => 'queryLimit',
            'file_limit'       => 'fileLimit',
            'batch_size_mb'    => 'batchSize',
            'max_file_size_mb' => 'maxFileSize',
            'cpu_load'         => 'cpuLoad',
            'delay_seconds'    => 'delayRequests',
            'debug_enabled'    => 'debugMode',
        );
        foreach ( $map as $public => $private ) {
            $provider[ $private ] = 'debug_enabled' === $public ? ( $settings[ $public ] ? 1 : 0 ) : $settings[ $public ];
        }
        if ( ! $this->abilities_v2_provider_store_settings( $provider ) ) {
            return $this->abilities_v2_error( 'replace_settings', 'storage_unavailable' );
        }
        $stored = $this->abilities_v2_settings();
        if ( empty( $stored['ok'] ) ) {
            return $this->abilities_v2_error( 'replace_settings', 'outcome_unknown' );
        }
        foreach ( $settings as $key => $value ) {
            if ( ! array_key_exists( $key, $stored ) || $stored[ $key ] !== $value ) {
                return $this->abilities_v2_error( 'replace_settings', 'outcome_unknown' );
            }
        }

        return array(
            'protocol'    => '2',
            'operation'   => 'replace_settings',
            'ok'          => true,
            'request_ref' => $request['request_ref'],
            'revision'    => $stored['revision'],
        );
    }

    /** @param array $settings Settings. @return bool */
    protected function abilities_v2_provider_store_settings( $settings ) {
        return update_option( 'wpstg_settings', $settings, false ) || $settings === get_option( 'wpstg_settings', array() );
    }

    /** @param mixed $settings Settings. @return array|false */
    private function abilities_v2_validate_public_settings( $settings ) {
        $keys = array( 'query_limit', 'file_limit', 'batch_size_mb', 'max_file_size_mb', 'cpu_load', 'delay_seconds', 'debug_enabled' );
        if ( ! $this->abilities_v2_exact_keys( $settings, $keys ) ) {
            return false;
        }
        if ( ! is_int( $settings['query_limit'] ) || 10 > $settings['query_limit'] || 10000 < $settings['query_limit'] || false === $this->abilities_v2_file_limit( $settings['file_limit'] ) || ! is_int( $settings['batch_size_mb'] ) || 1 > $settings['batch_size_mb'] || 100 < $settings['batch_size_mb'] || ! is_int( $settings['max_file_size_mb'] ) || 1 > $settings['max_file_size_mb'] || 1024 < $settings['max_file_size_mb'] || ! in_array( $settings['cpu_load'], array( 'low', 'medium', 'high' ), true ) || ! is_int( $settings['delay_seconds'] ) || 0 > $settings['delay_seconds'] || 60 < $settings['delay_seconds'] || ! is_bool( $settings['debug_enabled'] ) ) {
            return false;
        }
        return $settings;
    }

    /**
     * Provider-free preview seam. Production refuses to guess when WP Staging
     * does not expose a side-effect-free scanner.
     *
     * @param string $kind Operation kind.
     * @param string|null $clone_ref Clone reference.
     * @param array $inventory Redacted inventory.
     * @return array|false
     */
    protected function abilities_v2_provider_preview( $kind, $clone_ref, $inventory ) {
        unset( $kind, $clone_ref, $inventory );
        return false;
    }

    /** @return bool Whether a build has wired a real preview scanner behind the seam above. */
    protected function abilities_v2_provider_supports_preview() {
        return false;
    }

    /** @return bool */
    protected function abilities_v2_provider_supports_mutation() {
        return false;
    }

    /** @return string */
    private function abilities_v2_plugin_version() {
        return defined( 'WPSTG_VERSION' ) && is_string( WPSTG_VERSION ) && 64 >= strlen( WPSTG_VERSION ) ? WPSTG_VERSION : ( is_string( $this->plugin_version ) ? $this->plugin_version : '' );
    }

    /** @param array $rows Rows. @return string */
    private function abilities_v2_inventory_revision( $rows ) {
        return hash( 'sha256', wp_json_encode( array_values( $rows ) ) );
    }

    /** @param mixed $value Value. @return string|null|false */
    private function abilities_v2_optional_time( $value ) {
        if ( null === $value || '' === $value ) {
            return null;
        }
        if ( ! is_string( $value ) || 64 < strlen( $value ) || false === strtotime( $value ) ) {
            return false;
        }
        return gmdate( 'c', strtotime( $value ) );
    }

    /** @param array $settings Settings. @param string $key Key. @param int $minimum Minimum. @param int $maximum Maximum. @param int $default_value Default. @return int */
    private function abilities_v2_bounded_int( $settings, $key, $minimum, $maximum, $default_value ) {
        $value = isset( $settings[ $key ] ) && is_numeric( $settings[ $key ] ) ? (int) $settings[ $key ] : $default_value;
        return max( $minimum, min( $maximum, $value ) );
    }

    /** @param mixed $value Value. @return int|false */
    private function abilities_v2_file_limit( $value ) {
        $value = is_numeric( $value ) ? (int) $value : -1;
        return in_array( $value, array( 1, 10, 50, 250, 500, 1000 ), true ) ? $value : false;
    }

    /** @param array $preview Preview. @return bool */
    private function abilities_v2_valid_preview( $preview ) {
        foreach ( array( 'table_count' => 100000, 'file_count' => 100000000, 'estimated_bytes' => 9007199254740991 ) as $key => $maximum ) {
            if ( ! is_int( $preview[ $key ] ) || 0 > $preview[ $key ] || $maximum < $preview[ $key ] ) {
                return false;
            }
        }
        if ( ! is_bool( $preview['disk_sufficient'] ) || ! is_bool( $preview['isolation_ready'] ) || ! is_array( $preview['warnings'] ) || 20 < count( $preview['warnings'] ) ) {
            return false;
        }
        foreach ( $preview['warnings'] as $warning ) {
            if ( ! in_array( $warning, array( 'large_clone', 'limited_disk_margin', 'plugin_version_legacy', 'existing_incomplete_job' ), true ) ) {
                return false;
            }
        }
        return true;
    }

    /** @param mixed $value Value. @return bool */
    private function abilities_v2_valid_hash( $value ) {
        return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $value );
    }

    /** @param mixed $value Value. @return bool */
    private function abilities_v2_valid_request_ref( $value ) {
        return is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value );
    }

    /** @param mixed $value Value. @return bool */
    private function abilities_v2_valid_operation_ref( $value ) {
        return $this->abilities_v2_valid_request_ref( $value );
    }

    /** @param mixed $value Value. @param array $keys Expected keys. @return bool */
    private function abilities_v2_exact_keys( $value, $keys ) {
        if ( ! is_array( $value ) ) {
            return false;
        }
        $actual = array_keys( $value );
        sort( $actual );
        sort( $keys );
        return $actual === $keys;
    }

    /** @param string $operation Operation. @param string $code Stable code. @return array */
    private function abilities_v2_error( $operation, $code = 'invalid_request' ) {
        return array(
            'protocol'   => '2',
            'operation'  => is_string( $operation ) && 64 >= strlen( $operation ) ? $operation : 'unknown',
            'ok'         => false,
            'error_code' => $code,
        );
    }
    // phpcs:enable Generic.Commenting.DocComment.MissingShort,Squiz.Commenting.FunctionComment,Generic.Formatting.MultipleStatementAlignment,WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound,WordPress.Arrays.MultipleStatementAlignment,WordPress.PHP.YodaConditions.NotYoda

    /**
     * Get WP Staging Jobs.
     *
     * @uses WPStaging\Backend\Modules\Jobs\Scan::start()
     * @uses WPStaging\Backend\Modules\Jobs\Scan::getOptions()
     *
     * @return array $return Action result.
     */
    public function get_scan() {
        $scan = new \WPStaging\Backend\Modules\Jobs\Scan();
        $scan->start();

        $options = $scan->getOptions();

        return array(
            'options'          => wp_json_encode( $options ), // phpcs:ignore -- to compatible http encoding.
            'prefix'           => '2.8' === $this->plugin_version ? \WPStaging\Core\WPStaging::getTablePrefix() : \WPStaging\WPStaging::getTablePrefix(),
            'directoryListing' => $scan->directoryListing(),
        );
    }


    /**
     * Check if clone name already exists & it's length.
     *
     * @return array|string[] Action result array[status, message] or return 'success'.
     */
    public function ajax_check_clone_name() {
        $cloneID = isset( $_POST['cloneID'] ) ? sanitize_key( wp_unslash( $_POST['cloneID'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

        if ( defined( '\WPStaging\Staging\Sites::STAGING_SITES_OPTION' ) ) { // new update.
            $clones = get_option( \WPStaging\Staging\Sites::STAGING_SITES_OPTION, array() ); // old option.
        } elseif ( defined( '\WPStaging\Framework\Staging\Sites::STAGING_SITES_OPTION' ) ) {
            $clones = get_option( \WPStaging\Framework\Staging\Sites::STAGING_SITES_OPTION, array() ); // old option.
        } else {
            $clones = get_option( 'wpstg_existing_clones_beta', array() ); // old option.
        }

        if ( array_key_exists( $cloneID, $clones ) ) {
            return array(
                'status'  => 'failed',
                'message' => 'Clone name is already in use, please choose an another clone name',
            );
        }

        return array( 'status' => 'success' );
    }

        /**
         * Start clone via ajax.
         *
         * @uses WPStaging\Backend\Modules\Jobs\Cloning::save()
         *
         * @return false|string|void Return FALSE on failure, ajax response string on success, ELSE returns VOID.
         */
    public function ajax_start_clone() { //phpcs:ignore -- NOSONAR - complex.

        if ( function_exists( '\WPStaging\Core\WPStaging::make' ) ) {
            require_once WPSTG_PLUGIN_DIR . 'Backend/Modules/Jobs/ProcessLock.php'; // NOSONAR - WP compatible.
            // Check first if there is already a process running.
            $processLock = new \WPStaging\Backend\Modules\Jobs\ProcessLock();
            if ( $this->is_running( $processLock ) ) {
                return;
            }

            $cloning = \WPStaging\Core\WPStaging::make( \WPStaging\Backend\Modules\Jobs\Cloning::class );

            if ( ! $cloning->save() ) {
                return;
            }
        } else {
            $this->url = '';
            // phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            // to compatible with new version.
            if ( class_exists( '\WPStaging\Framework\Database\SelectedTables' ) ) {

                if ( isset( $_POST['includedTables'] ) && is_array( $_POST['includedTables'] ) ) {
                    $_POST['includedTables'] = implode( \WPStaging\Framework\Filesystem\Scanning\ScanConst::DIRECTORIES_SEPARATOR, wp_unslash( $_POST['includedTables'] ) );
                }

                if ( isset( $_POST['excludedTables'] ) && is_array( $_POST['excludedTables'] ) ) {
                    $_POST['excludedTables'] = implode( \WPStaging\Framework\Filesystem\Scanning\ScanConst::DIRECTORIES_SEPARATOR, wp_unslash( $_POST['excludedTables'] ) );
                }

                if ( isset( $_POST['selectedTablesWithoutPrefix'] ) && is_array( $_POST['selectedTablesWithoutPrefix'] ) ) {
                    $_POST['selectedTablesWithoutPrefix'] = implode( \WPStaging\Framework\Filesystem\Scanning\ScanConst::DIRECTORIES_SEPARATOR, wp_unslash( $_POST['selectedTablesWithoutPrefix'] ) );
                }

                if ( isset( $_POST['includedDirectories'] ) && is_array( $_POST['includedDirectories'] ) ) {
                    $_POST['includedDirectories'] = implode( \WPStaging\Framework\Filesystem\Scanning\ScanConst::DIRECTORIES_SEPARATOR, wp_unslash( $_POST['includedDirectories'] ) );
                }

                if ( isset( $_POST['excludedDirectories'] ) && is_array( $_POST['excludedDirectories'] ) ) {
                    $_POST['excludedDirectories'] = implode( \WPStaging\Framework\Filesystem\Scanning\ScanConst::DIRECTORIES_SEPARATOR, wp_unslash( $_POST['excludedDirectories'] ) );
                }

                if ( isset( $_POST['extraDirectories'] ) && is_array( $_POST['extraDirectories'] ) ) {
                    $_POST['extraDirectories'] = implode( \WPStaging\Framework\Filesystem\Scanning\ScanConst::DIRECTORIES_SEPARATOR, wp_unslash( $_POST['extraDirectories'] ) );
                }
            }
            // phpcs:enable
            $cloning = new \WPStaging\Backend\Modules\Jobs\Cloning();

            if ( ! $cloning->save() ) {
                return;
            }
        }

        $result = array();

        if ( file_exists( WPSTG_PLUGIN_DIR . 'app/Backend/views/clone/ajax/start.php' ) ) {
            ob_start();
            require_once WPSTG_PLUGIN_DIR . 'app/Backend/views/clone/ajax/start.php'; // NOSONAR - WP compatible.
            $result = ob_get_clean();
        } elseif ( file_exists( WPSTG_PLUGIN_DIR . 'Backend/views/clone/ajax/start.php' ) ) { // new.
            if ( defined( 'WPSTG_VERSION' ) && version_compare( WPSTG_VERSION, '3.0', '>=' ) ) {
                if ( file_exists( WPSTG_PLUGIN_DIR . 'Core/WPStaging.php' ) ) {
                    include_once WPSTG_PLUGIN_DIR . 'Core/WPStaging.php'; // NOSONAR -- WP compatible.
                    $this->assets = \WPStaging\Core\WPStaging::make( \WPStaging\Framework\Assets\Assets::class ); // to fix error since ver 3.1.3.

                    $subDirectory = str_replace( get_home_path(), '', ABSPATH );
                    $urlsHelper   = \WPStaging\Core\WPStaging::make( \WPStaging\Framework\Utils\Urls::class );
                    $url          = $urlsHelper->getHomeUrl() . str_replace( '/', '', $subDirectory );
                    $result       = array(
                        'url'       => $url,
                        'blog_name' => get_bloginfo( 'name' ),
                        'clone'     => $cloning->getOptions()->clone,
                        'img_src'   => $this->assets->getAssetsUrl( 'img/admin_dashboard.png' ),
                        'version3'  => 1,
                    );
                }
            } else {
                // to compatible with version 2.x.
                ob_start();
                $this->assets = new \WPStaging\Framework\Assets\Assets( new \WPStaging\Framework\Security\AccessToken(), new \WPStaging\Core\DTO\Settings() ); // to fix error.
                require_once WPSTG_PLUGIN_DIR . 'Backend/views/clone/ajax/start.php'; // NOSONAR - WP compatible.
                $result = ob_get_clean();
            }
        } elseif ( defined( 'WPSTG_VIEWS_DIR' ) && file_exists( WPSTG_VIEWS_DIR . 'clone/ajax/scan.php' ) ) { // new version >= 3.8.4.
            if ( file_exists( WPSTG_PLUGIN_DIR . 'Core/WPStaging.php' ) ) {
                include_once WPSTG_PLUGIN_DIR . 'Core/WPStaging.php'; // NOSONAR -- WP compatible.
                $this->assets = \WPStaging\Core\WPStaging::make( \WPStaging\Framework\Assets\Assets::class ); // to fix error since ver 3.1.3.

                $subDirectory = str_replace( get_home_path(), '', ABSPATH );
                $urlsHelper   = \WPStaging\Core\WPStaging::make( \WPStaging\Framework\Utils\Urls::class );
                $url          = $urlsHelper->getHomeUrl() . str_replace( '/', '', $subDirectory );
                $result       = array(
                    'url'       => $url,
                    'blog_name' => get_bloginfo( 'name' ),
                    'clone'     => $cloning->getOptions()->clone,
                    'img_src'   => $this->assets->getAssetsUrl( 'img/admin_dashboard.png' ),
                    'version3'  => 1,
                );
            }
        }
        return $result;
    }

    /**
     *
     * Check process lock running.
     *
     * @param mixed $processlock Process lock object.
     *
     * @return bool
     */
    protected function is_running( $processlock ) {
        if ( ! isset( $processlock->options ) || ! isset( $processlock->options->isRunning ) || ! isset( $processlock->options->expiresAt ) ) {
            return false;
        }

        try {
            $now       = new \DateTime();
            $expiresAt = new \DateTime( $processlock->options->expiresAt );
            return ( true === $processlock->options->isRunning ) && ( $now < $expiresAt );
        } catch ( MainWP_Exception $e ) {
            // ok.
        }

        return false;
    }

    /**
     * Clone database via ajax.
     *
     * @uses WPStaging\Backend\Modules\Jobs\Cloning::start()
     *
     * @return mixed Action result.
     */
    public function ajax_clone_database() {
        if ( function_exists( '\WPStaging\Core\WPStaging::make' ) ) {
            return \WPStaging\Core\WPStaging::make( \WPStaging\Backend\Modules\Jobs\Cloning::class )->start(); // new.
        } else {
            $cloning = new \WPStaging\Backend\Modules\Jobs\Cloning();
            return $cloning->start();
        }
    }

    /**
     * Ajax Clone Files.
     *
     * @uses WPStaging\Backend\Modules\Jobs\Cloning::start()
     *
     * @return mixed Action result.
     */
    public function ajax_start_files() {
        $cloning = new \WPStaging\Backend\Modules\Jobs\Cloning();
        return $cloning->start();
    }

    /**
     * Ajax Finish
     *
     * @uses WPStaging\Backend\Modules\Jobs\Cloning::start()
     *
     * @return mixed $return Action result.
     */
    public function ajax_finish() {
        $cloning              = new \WPStaging\Backend\Modules\Jobs\Cloning();
        $this->url            = '';
        $return               = $cloning->start();
        $return->blogInfoName = get_bloginfo( 'name' );

        return $return;
    }

    /**
     * Ajax Delete Confirmation.
     *
     * @uses WPStaging\Backend\Modules\Jobs\Delete::getClone()
     * @uses WPStaging\Backend\Modules\Jobs\Delete::getClone()
     *
     * @return array $result Action result.
     */
    public function ajax_delete_confirmation() {
        $delete = new \WPStaging\Backend\Modules\Jobs\Delete();
        $delete->setData();
        $clone = $delete->getClone();
        return array(
            'clone'        => $clone,
            'deleteTables' => $delete->getTables(),
        );
    }

    /**
     * Ajax Delete clone.
     *
     * @uses WPStaging\Backend\Modules\Jobs\Delete::start()
     *
     * @return mixed Action result.
     */
    public function ajax_delete_clone() {
        $delete = new \WPStaging\Backend\Modules\Jobs\Delete();
        $result = $delete->start();
        if ( null === $result ) {
            $result = wp_json_encode( 'retry' ); // to fix.
        }
        return $result;
    }

    /**
     * Ajax Cancel clone.
     *
     * @uses WPStaging\Backend\Modules\Jobs\Cancel::start()
     */
    public function ajax_cancel_clone() {
        $cancel = new \WPStaging\Backend\Modules\Jobs\Cancel();
        $result = $cancel->start();
        if ( null === $result ) {
            $result = wp_json_encode( 'retry' ); // to fix.
        }
        return $result;
    }

    /**
     * Ajax Cancel Update.
     *
     * @uses WPStaging\Backend\Modules\Jobs\CancelUpdate::start()
     *
     * @return mixed Action result.
     */
    public function ajax_cancel_update() {
        $cancel = new \WPStaging\Backend\Modules\Jobs\CancelUpdate();

        return $cancel->start();
    }

    /**
     * Ajax Update Process.
     *
     * @uses WPStaging\Backend\Modules\Jobs\Updating::save()
     *
     * @return false|string|void Return FALSE on failure, ajax response string on success, ELSE returns VOID.
     */
    public function ajax_update_process() {
        $cloning = new \WPStaging\Backend\Modules\Jobs\Updating();

        if ( ! $cloning->save() ) {
            return '';
        }

        if ( file_exists( WPSTG_PLUGIN_DIR . 'app/Backend/views/clone/ajax/update.php' ) ) {
            ob_start();
            require_once WPSTG_PLUGIN_DIR . 'app/Backend/views/clone/ajax/update.php'; // NOSONAR - WP compatible.
            $result = ob_get_clean();
        } elseif ( file_exists( WPSTG_PLUGIN_DIR . 'Backend/views/clone/ajax/update.php' ) ) {
            if ( defined( 'WPSTG_VERSION' ) && version_compare( WPSTG_VERSION, '3.0', '>=' ) ) {
                $result = array(
                    'clone'    => $cloning->getOptions()->clone,
                    'mainJob'  => $cloning->getOptions()->mainJob,
                    'version3' => 1,
                );
            } else {
                ob_start();
                require_once WPSTG_PLUGIN_DIR . 'Backend/views/clone/ajax/update.php'; // NOSONAR - WP compatible.
                $result = ob_get_clean();
            }
        } elseif ( defined( 'WPSTG_VIEWS_DIR' ) && file_exists( WPSTG_VIEWS_DIR . 'clone/ajax/scan.php' ) ) { // new version >= 3.8.4.
            $result = array(
                'clone'    => $cloning->getOptions()->clone,
                'mainJob'  => $cloning->getOptions()->mainJob,
                'version3' => 1,
            );
        }

        return $result;
    }

    /**
     * Ajax check for free disk space.
     *
     * @uses MainWP_Child_Staging::has_free_disk_space()
     *
     * @return array|null Action result or null
     */
    public function ajax_check_free_space() {
        return $this->has_free_disk_space();
    }

    /**
     * Ajax check for free disk space.
     *
     * @uses MainWP_Child_Staging::format_size()
     * @uses MainWP_Child_Staging::get_directory_size_incl_subdirs()
     *
     * @return array|null Action result or null
     */
    public function has_free_disk_space() {
        if ( ! function_exists( 'disk_free_space' ) ) {
            return null;
        }
        $freeSpace = disk_free_space( ABSPATH );
        if ( false === $freeSpace ) {
            return array(
                'freespace' => false,
                'usedspace' => $this->format_size( $this->get_directory_size_incl_subdirs( ABSPATH ) ),
            );
        }
        return array(
            'freespace' => $this->format_size( $freeSpace ),
            'usedspace' => $this->format_size( $this->get_directory_size_incl_subdirs( ABSPATH ) ),
        );
    }

    /**
     * Get size of directory & subdirectories.
     *
     * @param string $dir Directory to size.
     *
     * @return false|int FALSE on failure, int $size Directory size,
     */
    public function get_directory_size_incl_subdirs( $dir ) {
        $size = 0;
        foreach ( glob( rtrim( $dir, '/' ) . '/*', GLOB_NOSORT ) as $each ) {
            $size += is_file( $each ) ? filesize( $each ) : $this->get_directory_size_incl_subdirs( $each );
        }
        return $size;
    }

    /**
     * Format file size into human readable string.
     *
     * @param string $bytes Original size of file.
     * @param int    $precision Number of digits after the decimal point.
     * @return string Returned Size.
     */
    public function format_size( $bytes, $precision = 2 ) {
        if ( (float) $bytes < 1 ) {
            return '';
        }

        $units = array( 'B', 'KB', 'MB', 'GB', 'TB' );

        $bytes = (float) $bytes;
        $base  = log( $bytes ) / log( 1000 );
        $pow   = pow( 1000, $base - floor( $base ) );

        return round( $pow, $precision ) . ' ' . $units[ (int) floor( $base ) ];
    }


    /**
     * Get list of all plugins except WPStaging.
     *
     * @param array $plugins All installed plugins.
     * @return mixed Returned array of plugins without WPStaging included.
     */
    public function all_plugins( $plugins ) {
        foreach ( $plugins as $key => $value ) {
            $plugin_slug = basename( $key, '.php' );
            if ( 'wp-staging' === $plugin_slug ) {
                unset( $plugins[ $key ] );
            }
        }

        return $plugins;
    }

    /**
     * Remove WPStaging WordPress Menu.
     */
    public function remove_menu() {
        remove_menu_page( 'wpstg_clone' );
        $pos = isset( $_SERVER['REQUEST_URI'] ) ? stripos( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), 'admin.php?page=wpstg_clone' ) : false;
        if ( false !== $pos ) {
            wp_safe_redirect( get_option( 'siteurl' ) . '/wp-admin/index.php' );
            exit();
        }
    }

    /**
     * Hide all admin update notices.
     *
     * @param array $slugs WPStaging plugin slug.
     * @return mixed Returned $slugs.
     */
    public function hide_update_notice( $slugs ) {
        $slugs[] = $this->the_plugin_slug;

        return $slugs;
    }

    /**
     * Remove WPStaging update Nag message.
     *
     * @param array $value WPStaging slug.
     * @return mixed $value Response array.
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

        if ( isset( $value->response[ $this->the_plugin_slug ] ) ) {
            unset( $value->response[ $this->the_plugin_slug ] );
        }

        if ( isset( $value->response[ $this->the_plugin_slug_pro ] ) ) {
            unset( $value->response[ $this->the_plugin_slug_pro ] );
        }

        return $value;
    }
}
