<?php
/**
 * MainWP Ithemes Security
 *
 * MainWP iThemes Security Extension handler.
 * Extension URL: https://mainwp.com/extension/ithemes-security/
 *
 * @package MainWP\Child
 *
 * Credits
 *
 * Plugin-Name: iThemes Security
 * Plugin URI: https://ithemes.com/security
 * Author: iThemes
 * Author URI: https://ithemes.com
 * License: GPLv2
 *
 * The code is used for the MainWP iThemes Security Extension
 * Extension URL: https://mainwp.com/extension/ithemes-security/
 */

namespace MainWP\Child;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable -- third party credit code.

/**
 * Class MainWP_Child_IThemes_Security
 */
class MainWP_Child_IThemes_Security { //phpcs:ignore -- NOSONAR - multi methods.

    /**
     * Public static variable to hold the single instance of MainWP_Child_IThemes_Security.
     *
     * @var null
     */
    public static $instance = null;

    /**
     * @var bool Whether or not iThemes Plugin is installed or not. Default: false.
     */
    public $is_plugin_installed = false;

    /**
     * Seconds a stored timestamp may lead the current time by and still be usable.
     *
     * Receipts come out of a WordPress option, so their timestamps are untrusted input. A small
     * allowance keeps a site whose clock drifted or moved backwards from calling its own recent
     * receipts corrupt, while anything further ahead is an impossible moment this store never wrote.
     *
     * @var int
     */
    private const ABILITIES_V2_CLOCK_SKEW = 300;

    /**
     * Create a public static instance of MainWP_Child_IThemes_Security.
     *
     * @return MainWP_Child_IThemes_Security|null
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * MainWP_Child_IThemes_Security constructor.
     *
     * Run any time class is called.
     *
     * @uses MainWP_Child_IThemes_Security::is_plugin_installed()
     */
    public function __construct() {
        require_once ABSPATH . 'wp-admin/includes/plugin.php'; // NOSONAR - WP compatible.
        if ( is_plugin_active( 'better-wp-security/better-wp-security.php' ) || is_plugin_active( 'ithemes-security-pro/ithemes-security-pro.php' ) ) {
            $this->is_plugin_installed = true;
        }

        if ( ! $this->is_plugin_installed ) {
            return;
        }

        add_filter( 'mainwp_site_sync_others_data', array( $this, 'sync_others_data' ), 10, 2 );
    }

    /**
     * Sync other data from $data[] and merge with $information[]
     *
     * @param array $information Returned response array for MainWP BackWPup Extension actions.
     * @param array $data Other data to sync to $information array.
     * @return array $information Returned information array with both sets of data.
     *
     * @throws Exception Catch Error.
     */
    public function sync_others_data( $information, $data = array() ) { //phpcs:ignore -- NOSONAR - complex ok.
        if ( is_array( $data ) && isset( $data['ithemeExtActivated'] ) && ( 'yes' === $data['ithemeExtActivated'] ) ) {
            try {
                $information['syncIThemeData'] = array(
                    'users_and_roles' => $this->get_available_admin_users_and_roles(),
                );

                global $itsec_lockout;

                if ( $itsec_lockout ) {
                    $lockout_query = array(
                        'limit'   => 100,
                        'current' => true,
                        'order'   => 'DESC',
                        'orderby' => 'lockout_start',
                    );
                    $lockouts      = $itsec_lockout->get_lockouts( 'all', $lockout_query );
                    if ( $lockouts ) {
                        $information['syncIThemeData']['lockout_count'] = count( $lockouts );
                    }
                }

                $request = new \WP_REST_Request( 'GET', '/ithemes-security/v1/site-scanner/scans' );

                $range1 = \ITSEC_Core::get_current_time_gmt();
                $range0 = strtotime( '-30 days', $range1 );

                $request->set_query_params(
                    array(
                        'after'  => \ITSEC_Lib::to_rest_date( $range0 ),
                        'before' => \ITSEC_Lib::to_rest_date( $range1 ),
                    )
                );

                $response = rest_do_request( $request );
                $scans    = rest_get_server()->response_to_data( $response, true );

                if ( is_array( $scans ) && count( $scans ) > 0 ) {
                    $scan = current( $scans );
                    if ( is_array( $scan ) && isset( $scan['time'] ) ) { // to fix error: "you cannot view site scans".
                        $information['syncIThemeData']['scan_info'] = array(
                            'time'        => $scan['time'],
                            'description' => $scan['description'],
                            'status'      => $scan['status'],
                        );
                    }
                }

                if ( class_exists( '\iThemesSecurity\Ban_Users\Database_Repository' ) ) {
                    try {
                        $repository                                  = \ITSEC_Modules::get_container()->get( \iThemesSecurity\Ban_Users\Database_Repository::class );
                        $information['syncIThemeData']['count_bans'] = $repository->count_bans( new \iThemesSecurity\Ban_Hosts\Filters() );
                    } catch ( \Exception $ex ) { // NOSONAR - to catch 3r Exception.
                        $information['syncIThemeData']['count_bans'] = 0;
                    }
                }

                $information['syncIThemeData']['lockouts_host']     = $this->get_lockouts( 'host', true );
                $information['syncIThemeData']['lockouts_user']     = $this->get_lockouts( 'user', true );
                $information['syncIThemeData']['lockouts_username'] = $this->get_lockouts( 'username', true );

            } catch ( MainWP_Exception $e ) {
                error_log( $e->getMessage() ); // phpcs:ignore -- debug mode only.
            }
        }
        return $information;
    }

    /**
     * MainWP iThemes Security Extension actions.
     *
     * @uses \MainWP\Child\MainWP_Child_IThemes_Security::set_showhide()
     * @uses \MainWP\Child\MainWP_Child_IThemes_Security::save_settings()
     * @uses \MainWP\Child\MainWP_Child_IThemes_Security::whitelist_release()
     * @uses \MainWP\Child\MainWP_Child_IThemes_Security::backup_db()
     * @uses \MainWP\Child\MainWP_Child_IThemes_Security::admin_user()
     * @uses \MainWP\Child\MainWP_Child_IThemes_Security::change_database_prefix()
     * @uses \MainWP\Child\MainWP_Child_IThemes_Security::reset_api_key()
     * @uses \MainWP\Child\MainWP_Child_IThemes_Security::malware_scan()
     * @uses \MainWP\Child\MainWP_Child_IThemes_Security::>purge_logs()
     * @uses \MainWP\Child\MainWP_Child_IThemes_Security::file_change()
     * @uses \MainWP\Child\MainWP_Child_IThemes_Security::release_lockout()
     * @uses \MainWP\Child\MainWP_Child_IThemes_Security::update_module_status()
     * @uses \MainWP\Child\MainWP_Child_IThemes_Security::wordpress_salts()
     * @uses \MainWP\Child\MainWP_Child_IThemes_Security::file_permissions()
     * @uses \MainWP\Child\MainWP_Child_IThemes_Security::reload_backup_exclude()
     * @uses \MainWP\Child\MainWP_Child_IThemes_Security::security_site()
     * @uses \MainWP\Child\MainWP_Child_IThemes_Security::activate_network_brute_force()
     * @uses \MainWP\Child\MainWP_Helper::write()
     */
    public function action() {
        $information = array();

        $mwp_action = MainWP_System::instance()->validate_params( 'mwp_action' );

        $abilities_v2_response = $this->abilities_v2_action_response( $mwp_action );
        if ( null !== $abilities_v2_response ) {
            MainWP_Helper::write( $abilities_v2_response );
            return;
        }

        if ( ! class_exists( '\ITSEC_Core' ) || ! class_exists( '\ITSEC_Modules' ) ) {
            $information['error'] = 'NO_ITHEME';
            MainWP_Helper::write( $information );
            return;
        }

        /**
         * Itsec modules path.
         *
         * @global string $mainwp_itsec_modules_path MainWP itsec modules path.
         */
        global $mainwp_itsec_modules_path;

        $mainwp_itsec_modules_path = \ITSEC_Core::get_core_dir() . '/modules/';

        if ( ! empty( $mwp_action ) ) {
            switch ( $mwp_action ) {
                case 'set_showhide':
                    $information = $this->set_showhide();
                    break;
                case 'save_settings':
                    $information = $this->save_settings();
                    break;
                case 'whitelist_release':
                    $information = $this->whitelist_release();
                    break;
                case 'backup_db':
                    $information = $this->backup_db();
                    break;
                case 'admin_user':
                    $information = $this->admin_user();
                    break;
                case 'database_prefix':
                    $information = $this->change_database_prefix();
                    break;
                case 'reset_api_key':
                    $information = $this->reset_api_key();
                    break;
                case 'malware_scan':
                    $information = $this->malware_scan();
                    break;
                case 'clear_all_logs':
                    $information = $this->purge_logs();
                    break;
                case 'file_change':
                    $information = $this->file_change();
                    break;
                case 'release_lockout':
                    $information = $this->release_lockout();
                    break;
                case 'module_status':
                    $information = $this->update_module_status();
                    break;
                case 'wordpress_salts':
                    $information = $this->wordpress_salts();
                    break;
                case 'file_permissions':
                    $information = $this->file_permissions();
                    break;
                case 'reload_backup_exclude':
                    $information = $this->reload_backup_exclude();
                    break;
                case 'security_site':
                    $information = $this->security_site();
                    break;
                case 'activate_network_brute_force':
                    $information = $this->activate_network_brute_force();
                    break;
                default:
                    break;
            }
        }
        MainWP_Helper::write( $information );
    }

    /**
     * Answer an abilities_v2 request ahead of the v1 provider gate.
     *
     * This has to run before the NO_ITHEME bail: on a site without Solid the v2
     * protocol still owes the Dashboard its own closed envelope (capabilities,
     * plugin_unavailable), and the v1 bail would take the whole protocol off the
     * air instead.
     *
     * @param string $mwp_action Validated action name.
     * @return array<string,mixed>|null Closed protocol response, or null for a v1 request.
     */
    protected function abilities_v2_action_response( $mwp_action ) {
        if ( 'abilities_v2' !== $mwp_action ) {
            return null;
        }

        /**
         * Itsec modules path.
         *
         * @global string $mainwp_itsec_modules_path MainWP itsec modules path.
         */
        global $mainwp_itsec_modules_path;

        // Mutations reach v1 helpers that read this global, and action() now assigns it downstream of this branch.
        if ( is_callable( array( '\ITSEC_Core', 'get_core_dir' ) ) ) {
            $mainwp_itsec_modules_path = \ITSEC_Core::get_core_dir() . '/modules/';
        }

        // phpcs:disable WordPress.Security.NonceVerification
        $raw_request = isset( $_POST['request'] ) && is_string( $_POST['request'] ) ? wp_unslash( $_POST['request'] ) : '';
        // phpcs:enable
        // release_lockouts accepts 100 lockout refs, and 100 sha256 hexes plus the envelope is about
        // 6.9 KB, so the old 4 KB bound rejected a payload the validator calls legal.
        $request = 16384 >= strlen( $raw_request ) ? json_decode( $raw_request, true ) : null;
        return $this->abilities_v2( $request );
    }

    /**
     * Execute the additive Solid Security abilities protocol.
     *
     * @param mixed $request Decoded request object.
     * @return array<string,mixed> Closed protocol response.
     */
    public function abilities_v2( $request ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh -- Closed operation table is intentionally linear.
        $operation = is_array( $request ) && isset( $request['operation'] ) && is_string( $request['operation'] ) ? $request['operation'] : 'unknown';
        $reads     = array( 'ability_solid_file_permissions_v2', 'ability_solid_summary_v2', 'ability_solid_whitelist_v2', 'ability_solid_lockouts_v2' );
        $mutations = array( 'ability_solid_release_lockouts_v2', 'ability_solid_replace_whitelist_v2', 'ability_solid_file_scan_v2', 'ability_solid_backup_v2', 'ability_solid_malware_scan_v2', 'ability_solid_clear_logs_v2' );
        $root_keys = in_array( $operation, $mutations, true ) ? array( 'protocol', 'operation', 'request_ref', 'payload' ) : array( 'protocol', 'operation', 'payload' );
        if ( ! is_array( $request ) || ! $this->abilities_v2_exact_keys( $request, $root_keys ) || '2' !== $request['protocol'] || ! is_array( $request['payload'] ) ) {
            return $this->abilities_v2_error( 'unknown', 'invalid_request' );
        }

        if ( 'capabilities' === $operation ) {
            // Negotiation is supported; a payload on it is a malformed request, not an unknown operation.
            if ( array() !== $request['payload'] ) {
                return $this->abilities_v2_error( $operation, 'invalid_request' );
            }

            return array(
                'protocol'           => '2',
                'operation'          => 'capabilities',
                'ok'                 => true,
                'operations'         => array_merge( $reads, $mutations ),
                'mutation_supported' => $this->abilities_v2_provider_supports_mutation(),
            );
        }

        if ( 'ability_solid_file_permissions_v2' === $operation ) {
            if ( array() !== $request['payload'] ) {
                return $this->abilities_v2_error( $operation, 'invalid_request' );
            }

            if ( ! $this->abilities_v2_provider_available() ) {
                return $this->abilities_v2_error( $operation, 'plugin_unavailable' );
            }

            return $this->abilities_v2_file_permissions();
        }

        if ( 'ability_solid_summary_v2' === $operation ) {
            if ( array() !== $request['payload'] ) {
                return $this->abilities_v2_error( $operation, 'invalid_request' );
            }

            if ( ! $this->abilities_v2_provider_available() ) {
                return $this->abilities_v2_error( $operation, 'plugin_unavailable' );
            }

            return $this->abilities_v2_summary();
        }

        if ( 'ability_solid_whitelist_v2' === $operation ) {
            if ( array() !== $request['payload'] ) {
                return $this->abilities_v2_error( $operation, 'invalid_request' );
            }

            if ( ! $this->abilities_v2_provider_available() ) {
                return $this->abilities_v2_error( $operation, 'plugin_unavailable' );
            }

            return $this->abilities_v2_whitelist();
        }

        if ( ! in_array( $operation, array_merge( $reads, $mutations ), true ) || ! $this->abilities_v2_valid_provider_payload( $operation, $request['payload'] ) ) {
            return $this->abilities_v2_error( $operation, in_array( $operation, array_merge( $reads, $mutations ), true ) ? 'invalid_request' : 'unsupported_operation' );
        }
        if ( in_array( $operation, $mutations, true ) && ! $this->abilities_v2_valid_request_ref( $request['request_ref'] ) ) {
            return $this->abilities_v2_error( $operation, 'invalid_request' );
        }

        if ( in_array( $operation, $mutations, true ) ) {
            return $this->abilities_v2_execute_mutation( $operation, strtolower( $request['request_ref'] ), $request['payload'] );
        }

        // Only reads reach here, and each one answers from Solid-owned storage that does not exist without Solid.
        if ( ! $this->abilities_v2_provider_available() ) {
            return $this->abilities_v2_error( $operation, 'plugin_unavailable' );
        }

        try {
            $result = $this->abilities_v2_provider_operation( $operation, $request['payload'] );
        } catch ( \Throwable $throwable ) {
            return $this->abilities_v2_error( $operation, 'plugin_unavailable' );
        }
        if ( is_wp_error( $result ) ) {
            return $this->abilities_v2_provider_error( $operation, $result );
        }
        if ( ! $this->abilities_v2_valid_provider_result( $operation, $result ) ) {
            return $this->abilities_v2_error( $operation, 'provider_schema_invalid' );
        }
        return array_merge(
            array(
                'protocol'  => '2',
                'operation' => $operation,
                'ok'        => true,
            ),
            $result
        );
    }

    /**
     * Execute one serialized mutation with exact replay and checked receipt storage.
     *
     * @param string $operation   Closed operation name.
     * @param string $request_ref Canonical replay reference.
     * @param array  $payload     Validated operation payload.
     * @return array Closed protocol response.
     */
    private function abilities_v2_execute_mutation( $operation, $request_ref, $payload ) {
        if ( ! $this->abilities_v2_provider_supports_mutation() ) {
            return $this->abilities_v2_error( $operation, 'plugin_unavailable' );
        }
        if ( ! $this->abilities_v2_begin_mutation() ) {
            return $this->abilities_v2_error( $operation, 'lock_busy' );
        }

        $response = $this->abilities_v2_error( $operation, 'outcome_unknown' );
        try {
            $preview     = in_array( $operation, array( 'ability_solid_replace_whitelist_v2', 'ability_solid_clear_logs_v2' ), true ) && true === $payload['dry_run'];
            $receipts    = get_option( 'mainwp_solid_abilities_v2_receipts', array() );
            $effect_hash = hash( 'sha256', wp_json_encode( array( $operation, $payload ) ) );
            if ( ! is_array( $receipts ) ) {
                $response = $this->abilities_v2_error( $operation, 'storage_unavailable' );
            } elseif ( ! $preview && isset( $receipts[ $request_ref ] ) ) {
                $receipt = $receipts[ $request_ref ];
                if ( ! is_array( $receipt ) || ! $this->abilities_v2_exact_keys( $receipt, array( 'effect_hash', 'created_at', 'response' ) ) || ! $this->abilities_v2_valid_hash( $receipt['effect_hash'] ) || ! $this->abilities_v2_trusted_timestamp( $receipt['created_at'] ) || ! $this->abilities_v2_valid_receipt_response( $operation, $request_ref, $receipt['response'] ) ) {
                    $response = $this->abilities_v2_error( $operation, 'storage_unavailable' );
                } elseif ( 'ability_solid_file_scan_v2' === $operation && $this->abilities_v2_receipt_awaits_completion( $receipt ) ) {
                    $result = $this->abilities_v2_poll_file_scan();
                    if ( is_wp_error( $result ) ) {
                        $response = $this->abilities_v2_provider_error( $operation, $result );
                    } elseif ( ! $this->abilities_v2_valid_provider_result( $operation, $result ) ) {
                        $response = $this->abilities_v2_error( $operation, 'provider_schema_invalid' );
                    } else {
                        $response                             = array_merge(
                            array(
                                'protocol'    => '2',
                                'operation'   => $operation,
                                'ok'          => true,
                                'request_ref' => $request_ref,
                            ),
                            $result
                        );
                        $receipts[ $request_ref ]['response'] = $response;
                        if ( ! update_option( 'mainwp_solid_abilities_v2_receipts', $receipts, false ) && get_option( 'mainwp_solid_abilities_v2_receipts', array() ) !== $receipts ) {
                            $response = $this->abilities_v2_error( $operation, 'outcome_unknown' );
                        }
                    }
                } else {
                    $response = hash_equals( $receipt['effect_hash'], $effect_hash ) ? $receipt['response'] : $this->abilities_v2_error( $operation, 'request_conflict' );
                }
            } else {
                // Room is made before the effect runs, never after: a full store has to refuse the
                // mutation rather than dispatch it and then find nowhere to record what it did.
                $room = $preview ? $receipts : $this->abilities_v2_receipt_room( $receipts );
                if ( false === $room ) {
                    $response = $this->abilities_v2_error( $operation, 'storage_unavailable' );
                } else {
                    $receipts = $room;
                    try {
                        $result = $this->abilities_v2_provider_operation( $operation, $payload );
                    } catch ( \Throwable $throwable ) {
                        $result = new \WP_Error( 'outcome_unknown' );
                    }
                    if ( is_wp_error( $result ) ) {
                        $response = $this->abilities_v2_provider_error( $operation, $result );
                    } elseif ( ! $this->abilities_v2_valid_provider_result( $operation, $result ) ) {
                        $response = $this->abilities_v2_error( $operation, 'provider_schema_invalid' );
                    } else {
                        $response = array_merge(
                            array(
                                'protocol'    => '2',
                                'operation'   => $operation,
                                'ok'          => true,
                                'request_ref' => $request_ref,
                            ),
                            $result
                        );
                        if ( ! $preview ) {
                            $receipts[ $request_ref ] = array(
                                'effect_hash' => $effect_hash,
                                'created_at'  => time(),
                                'response'    => $response,
                            );
                            if ( ! update_option( 'mainwp_solid_abilities_v2_receipts', $receipts, false ) && get_option( 'mainwp_solid_abilities_v2_receipts', array() ) !== $receipts ) {
                                $response = $this->abilities_v2_error( $operation, 'outcome_unknown' );
                            }
                        }
                    }
                }
            }
        } finally {
            if ( ! $this->abilities_v2_end_mutation() ) {
                $response = $this->abilities_v2_error( $operation, 'outcome_unknown' );
            }
        }
        return $response;
    }

    /**
     * Free one receipt slot without ever dropping a receipt a retry could still land on.
     *
     * A receipt is the only thing between a repeated Dashboard request and a second dispatch of
     * the same mutation, so among readable receipts age is the one safe reason to drop one: past
     * the retry horizon the Dashboard has stopped asking. An accepted file scan is exempt from
     * that at any age, because its receipt is also the handle the Dashboard polls for the result.
     * Entries that are not readable receipts at all are dropped first at any age. When nothing can
     * be freed, the caller refuses the mutation instead of forcing room, which is why this must run
     * before the effect and not after it.
     *
     * @param array $receipts Stored receipts.
     * @return array|false Receipts with a free slot, or false when none can be freed.
     */
    private function abilities_v2_receipt_room( $receipts ) {
        $limit = 100;
        if ( $limit > count( $receipts ) ) {
            return $receipts;
        }

        $horizon    = time() - DAY_IN_SECONDS;
        $candidates = array();
        foreach ( $receipts as $ref => $receipt ) {
            // Structure decides first. A receipt the Child can no longer read answers
            // storage_unavailable on replay anyway, so protecting it protects nothing, while a
            // hand-edited entry that merely looks like an open scan would otherwise hold the store
            // shut for good. Corrupt entries go first, ahead of anything genuinely aged out.
            if ( ! $this->abilities_v2_valid_stored_receipt( $ref, $receipt ) ) {
                $candidates[ $ref ] = 0;
                continue;
            }
            if ( $this->abilities_v2_receipt_awaits_completion( $receipt ) ) {
                continue;
            }
            if ( $receipt['created_at'] < $horizon ) {
                $candidates[ $ref ] = $receipt['created_at'];
            }
        }

        asort( $candidates, SORT_NUMERIC );
        foreach ( array_keys( $candidates ) as $ref ) {
            if ( $limit > count( $receipts ) ) {
                break;
            }
            unset( $receipts[ $ref ] );
        }

        return $limit > count( $receipts ) ? $receipts : false;
    }

    /**
     * Whether one stored entry is a receipt at all, before anything is decided from it.
     *
     * The store is a WordPress option, so every entry is untrusted input. This asks only what the
     * entry is, not what it says: the key has to be the reference the receipt is filed under, and
     * the recorded response has to be a response the Child would have written for that reference.
     *
     * @param mixed $ref     Stored key.
     * @param mixed $receipt Stored receipt.
     * @return bool
     */
    private function abilities_v2_valid_stored_receipt( $ref, $receipt ) {
        if ( ! $this->abilities_v2_valid_request_ref( $ref ) || ! is_array( $receipt ) || ! $this->abilities_v2_exact_keys( $receipt, array( 'effect_hash', 'created_at', 'response' ) ) ) {
            return false;
        }
        if ( ! $this->abilities_v2_valid_hash( $receipt['effect_hash'] ) || ! $this->abilities_v2_trusted_timestamp( $receipt['created_at'] ) || ! is_array( $receipt['response'] ) || ! isset( $receipt['response']['operation'] ) || ! is_string( $receipt['response']['operation'] ) ) {
            return false;
        }
        return $this->abilities_v2_valid_receipt_response( $receipt['response']['operation'], strtolower( $ref ), $receipt['response'] );
    }

    /**
     * Whether a stored timestamp is a moment this store could actually have written.
     *
     * A value that is not an integer, not positive, or further in the future than clock skew
     * explains is not a younger timestamp; it is an unusable one, so nothing may read age from it.
     * An entry carrying one is therefore not a receipt worth protecting either, however much of the
     * rest of it looks like an open scan.
     *
     * @param mixed $value Stored timestamp.
     * @return bool
     */
    private function abilities_v2_trusted_timestamp( $value ) {
        return is_int( $value ) && 0 < $value && time() + self::ABILITIES_V2_CLOCK_SKEW >= $value;
    }

    /**
     * Whether a receipt records an accepted operation whose outcome is still open.
     *
     * @param mixed $receipt Stored receipt.
     * @return bool
     */
    private function abilities_v2_receipt_awaits_completion( $receipt ) {
        if ( ! is_array( $receipt ) || ! isset( $receipt['response'] ) || ! is_array( $receipt['response'] ) ) {
            return false;
        }

        $response = $receipt['response'];
        return isset( $response['accepted'], $response['completed'], $response['outcome'] ) && true === $response['accepted'] && false === $response['completed'] && 'accepted' === $response['outcome'];
    }

    /**
     * Map a provider error to the closed protocol vocabulary.
     *
     * @param string    $operation Closed operation name.
     * @param \WP_Error $error     Provider error.
     * @return array Closed protocol error.
     */
    private function abilities_v2_provider_error( $operation, $error ) {
        $code = $error->get_error_code();
        $safe = array( 'plugin_unavailable', 'unsupported_version', 'invalid_stored_state', 'target_not_found', 'stale_revision', 'state_conflict', 'lock_busy', 'write_failed', 'outcome_unknown', 'storage_unavailable' );
        return $this->abilities_v2_error( $operation, in_array( $code, $safe, true ) ? $code : 'plugin_unavailable' );
    }

    /** Acquire the Child-wide Solid mutation lock. */
    private function abilities_v2_begin_mutation() {
        global $wpdb;
        if ( ! is_object( $wpdb ) || ! is_callable( array( $wpdb, 'prepare' ) ) || ! is_callable( array( $wpdb, 'get_var' ) ) ) {
            return false;
        }
        $name   = 'mainwp_solid_v2_' . substr( hash( 'sha256', home_url( '/' ) ), 0, 32 );
        $result = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Exact named lock is the serialization primitive.
        return 1 === (int) $result;
    }

    /** Release and verify the Child-wide Solid mutation lock. */
    private function abilities_v2_end_mutation() {
        global $wpdb;
        if ( ! is_object( $wpdb ) || ! is_callable( array( $wpdb, 'prepare' ) ) || ! is_callable( array( $wpdb, 'get_var' ) ) ) {
            return false;
        }
        $name   = 'mainwp_solid_v2_' . substr( hash( 'sha256', home_url( '/' ) ), 0, 32 );
        $result = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Exact named lock release must be checked.
        return 1 === (int) $result;
    }

    /**
     * Validate a stored mutation receipt response.
     *
     * @param string $operation   Operation name.
     * @param string $request_ref Request reference.
     * @param mixed  $response    Stored response.
     * @return bool
     */
    private function abilities_v2_valid_receipt_response( $operation, $request_ref, $response ) {
        if ( ! is_array( $response ) || ! isset( $response['protocol'], $response['operation'], $response['ok'], $response['request_ref'] ) || '2' !== $response['protocol'] || ! is_string( $response['operation'] ) || ! hash_equals( $operation, $response['operation'] ) || true !== $response['ok'] || ! is_string( $response['request_ref'] ) || ! hash_equals( $request_ref, strtolower( $response['request_ref'] ) ) ) {
            return false;
        }
        $result = $response;
        unset( $result['protocol'], $result['operation'], $result['ok'], $result['request_ref'] );
        return $this->abilities_v2_valid_provider_result( $operation, $result );
    }

    /**
     * Return bounded coarse Solid posture without identities or findings.
     *
     * @return array<string,mixed> Closed summary response.
     */
    private function abilities_v2_summary() { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh -- version-gated read projection.
        if ( ! class_exists( '\ITSEC_Core' ) ) {
            return $this->abilities_v2_error( 'ability_solid_summary_v2', 'plugin_unavailable' );
        }

        $complete      = true;
        $lockout_count = null;
        $banned_count  = null;
        $latest_scan   = array(
            'status'       => 'unknown',
            'completed_at' => null,
        );

        global $itsec_lockout;
        if ( is_object( $itsec_lockout ) && is_callable( array( $itsec_lockout, 'get_lockouts' ) ) ) {
            try {
                $lockouts = $itsec_lockout->get_lockouts(
                    'all',
                    array(
                        'limit'   => 1001,
                        'current' => true,
                        'order'   => 'DESC',
                        'orderby' => 'lockout_start',
                    )
                );
                if ( is_array( $lockouts ) && count( $lockouts ) <= 1000 ) {
                    $lockout_count = count( $lockouts );
                } else {
                    $complete = false;
                }
            } catch ( \Throwable $throwable ) { // NOSONAR - third-party version boundary.
                $complete = false;
            }
        } else {
            $complete = false;
        }

        if ( class_exists( '\iThemesSecurity\Ban_Users\Database_Repository' ) && class_exists( '\iThemesSecurity\Ban_Hosts\Filters' ) && is_callable( array( '\ITSEC_Modules', 'get_container' ) ) ) {
            try {
                $repository = \ITSEC_Modules::get_container()->get( \iThemesSecurity\Ban_Users\Database_Repository::class );
                $count      = $repository->count_bans( new \iThemesSecurity\Ban_Hosts\Filters() );
                if ( is_int( $count ) && $count >= 0 && $count <= 100000 ) {
                    $banned_count = $count;
                } else {
                    $complete = false;
                }
            } catch ( \Throwable $throwable ) { // NOSONAR - third-party version boundary.
                $complete = false;
            }
        } else {
            $complete = false;
        }

        if ( class_exists( '\WP_REST_Request' ) ) {
            try {
                $request = new \WP_REST_Request( 'GET', '/ithemes-security/v1/site-scanner/scans' );
                $now     = \ITSEC_Core::get_current_time_gmt();
                $request->set_query_params(
                    array(
                        'after'  => \ITSEC_Lib::to_rest_date( strtotime( '-30 days', $now ) ),
                        'before' => \ITSEC_Lib::to_rest_date( $now ),
                    )
                );
                $response = rest_do_request( $request );
                if ( ! is_wp_error( $response ) ) {
                    $scans = rest_get_server()->response_to_data( $response, true );
                    if ( is_array( $scans ) && array() === $scans ) {
                        $latest_scan = array(
                            'status'       => 'never_run',
                            'completed_at' => null,
                        );
                    } elseif ( is_array( $scans ) && isset( $scans[0] ) && is_array( $scans[0] ) ) {
                        $latest_scan = $this->abilities_v2_scan_projection( $scans[0], $complete );
                    } else {
                        $complete = false;
                    }
                } else {
                    $complete = false;
                }
            } catch ( \Throwable $throwable ) { // NOSONAR - third-party REST boundary.
                $complete = false;
            }
        } else {
            $complete = false;
        }

        return $this->abilities_v2_summary_response( $lockout_count, $banned_count, $latest_scan, $complete, gmdate( 'Y-m-d\TH:i:s\Z' ) );
    }

    /**
     * Normalize one latest-scan row to a closed coarse state.
     *
     * @param array $scan     Provider-owned scan row.
     * @param bool  $complete Whether the whole summary remains complete.
     * @return array<string,string|null> Coarse scan state.
     */
    private function abilities_v2_scan_projection( $scan, &$complete ) {
        if ( ! isset( $scan['status'], $scan['time'] ) || ! is_string( $scan['status'] ) || ( ! is_string( $scan['time'] ) && ! is_int( $scan['time'] ) ) ) {
            $complete = false;
            return array(
                'status'       => 'unknown',
                'completed_at' => null,
            );
        }

        $timestamp = is_int( $scan['time'] ) ? $scan['time'] : strtotime( $scan['time'] );
        $status    = strtolower( $scan['status'] );
        if ( false === $timestamp || $timestamp < 1 ) {
            $complete = false;
            return array(
                'status'       => 'unknown',
                'completed_at' => null,
            );
        }
        if ( 'clean' === $status ) {
            $coarse = 'clean';
        } elseif ( in_array( $status, array( 'failed', 'error' ), true ) ) {
            $coarse = 'failed';
        } elseif ( in_array( $status, array( 'issues', 'issues_found', 'warning' ), true ) ) {
            $coarse = 'issues_found';
        } else {
            $complete = false;
            $coarse   = 'unknown';
        }

        return array(
            'status'       => $coarse,
            'completed_at' => gmdate( 'Y-m-d\TH:i:s\Z', $timestamp ),
        );
    }

    /**
     * Build the closed summary envelope and source generation.
     *
     * @param int|null $lockout_count Active lockout count.
     * @param int|null $banned_count  Active ban count.
     * @param array    $latest_scan   Coarse latest scan state.
     * @param bool     $complete      Whether every source was complete.
     * @param string   $observed_at   Observation timestamp.
     * @return array<string,mixed> Closed summary response.
     */
    private function abilities_v2_summary_response( $lockout_count, $banned_count, $latest_scan, $complete, $observed_at ) {
        $state = array(
            'complete'             => (bool) $complete,
            'active_lockout_count' => $lockout_count,
            'banned_count'         => $banned_count,
            'latest_scan'          => $latest_scan,
            'observed_at'          => $observed_at,
        );

        return array(
            'protocol'             => '2',
            'operation'            => 'ability_solid_summary_v2',
            'ok'                   => true,
            'complete'             => $state['complete'],
            'active_lockout_count' => $state['active_lockout_count'],
            'banned_count'         => $state['banned_count'],
            'latest_scan'          => $state['latest_scan'],
            'observed_at'          => $state['observed_at'],
            'source_generation'    => hash_hmac( 'sha256', 'mainwp-solid-summary-v1|' . wp_json_encode( $state ), wp_salt( 'auth' ) ),
        );
    }

    /**
     * Return permission posture for fixed WordPress targets without paths.
     *
     * @return array<string,mixed> Closed permission response.
     */
    private function abilities_v2_file_permissions() {
        $upload     = wp_get_upload_dir();
        $upload_dir = is_array( $upload ) && isset( $upload['basedir'] ) && is_string( $upload['basedir'] ) ? $upload['basedir'] : null;
        $wp_config  = ABSPATH . 'wp-config.php';
        if ( ! file_exists( $wp_config ) && ! is_link( $wp_config ) ) {
            $parent_config = dirname( rtrim( ABSPATH, '/\\' ) ) . '/wp-config.php';
            $wp_config     = file_exists( $parent_config ) || is_link( $parent_config ) ? $parent_config : $wp_config;
        }
        $server_config = ABSPATH . '.htaccess';
        $web_config    = ABSPATH . 'web.config';
        if ( ! file_exists( $server_config ) && ! is_link( $server_config ) && ( file_exists( $web_config ) || is_link( $web_config ) ) ) {
            $server_config = $web_config;
        }

        $definitions = array(
            array( 'wordpress_root', ABSPATH, '0755' ),
            array( 'wp_includes', ABSPATH . WPINC, '0755' ),
            array( 'wp_admin', ABSPATH . 'wp-admin', '0755' ),
            array( 'wp_admin_js', ABSPATH . 'wp-admin/js', '0755' ),
            array( 'wp_content', WP_CONTENT_DIR, '0755' ),
            array( 'themes', get_theme_root(), '0755' ),
            array( 'plugins', WP_PLUGIN_DIR, '0755' ),
            array( 'uploads', $upload_dir, '0755' ),
            array( 'wp_config', $wp_config, '0444' ),
            array( 'server_config', $server_config, '0444' ),
        );
        $targets     = array();

        foreach ( $definitions as $definition ) {
            $targets[] = $this->abilities_v2_permission_target( $definition[0], $definition[1], $definition[2] );
        }

        return array(
            'protocol'    => '2',
            'operation'   => 'ability_solid_file_permissions_v2',
            'ok'          => true,
            'targets'     => $targets,
            'observed_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
        );
    }

    /**
     * Project one fixed permission target without returning its path.
     *
     * @param string      $target        Logical target name.
     * @param string|null $path          Server-owned fixed path.
     * @param string      $expected_mode Expected four-digit mode.
     * @return array<string,string|null>
     */
    private function abilities_v2_permission_target( $target, $path, $expected_mode ) {
        $actual_mode = null;
        $status      = 'missing';

        if ( is_string( $path ) && '' !== $path && is_link( $path ) ) {
            $status = 'unreadable';
        } elseif ( is_string( $path ) && '' !== $path && file_exists( $path ) ) {
            if ( ! is_readable( $path ) ) {
                $status = 'unreadable';
            } else {
                $permissions = fileperms( $path );
                if ( false === $permissions ) {
                    $status = 'unreadable';
                } else {
                    $actual_mode = sprintf( '%04o', $permissions & 0777 );
                    $status      = $expected_mode === $actual_mode ? 'ok' : 'warning';
                }
            }
        }

        return array(
            'target'        => $target,
            'expected_mode' => $expected_mode,
            'actual_mode'   => $actual_mode,
            'status'        => $status,
        );
    }

    /**
     * Return temporary-whitelist state without exposing the stored address.
     *
     * Expired state is projected as inactive without deleting the legacy
     * option, keeping this protocol operation read-only.
     *
     * @return array<string,mixed> Closed whitelist response.
     */
    private function abilities_v2_whitelist() {
        $operation = 'ability_solid_whitelist_v2';
        $stored    = get_site_option( 'itsec_temp_whitelist_ip', false );
        if ( false === $stored ) {
            return $this->abilities_v2_whitelist_response( false, null, null, null );
        }
        if ( ! is_array( $stored ) || ! $this->abilities_v2_exact_keys( $stored, array( 'ip', 'exp' ) ) || ! is_string( $stored['ip'] ) || ! is_int( $stored['exp'] ) || $stored['exp'] < 1 ) {
            return $this->abilities_v2_error( $operation, 'invalid_stored_state' );
        }

        $packed = inet_pton( $stored['ip'] );
        if ( false === $packed ) {
            return $this->abilities_v2_error( $operation, 'invalid_stored_state' );
        }

        if ( $stored['exp'] <= time() ) {
            return $this->abilities_v2_whitelist_response( false, null, null, null );
        }

        $family = 4 === strlen( $packed ) ? 'ipv4' : 'ipv6';

        return $this->abilities_v2_whitelist_response( true, $stored['exp'], $family, bin2hex( $packed ) );
    }

    /**
     * Build a privacy-safe temporary-whitelist response and revision.
     *
     * @param bool        $active           Whether the stored entry is active.
     * @param int|null    $expires_at       Expiry timestamp.
     * @param string|null $family           Coarse address family.
     * @param string|null $address_material Canonical address material for HMAC only.
     * @return array<string,mixed> Closed whitelist response.
     */
    private function abilities_v2_whitelist_response( $active, $expires_at, $family, $address_material ) {
        $state                              = array(
            'active'         => $active,
            'expires_at'     => null === $expires_at ? null : gmdate( 'Y-m-d\TH:i:s\Z', $expires_at ),
            'address_family' => $family,
        );
        $revision_state                     = $state;
        $revision_state['address_material'] = $address_material;

        return array(
            'protocol'       => '2',
            'operation'      => 'ability_solid_whitelist_v2',
            'ok'             => true,
            'active'         => $state['active'],
            'expires_at'     => $state['expires_at'],
            'address_family' => $state['address_family'],
            'revision'       => hash_hmac( 'sha256', 'mainwp-solid-whitelist-v1|' . wp_json_encode( $revision_state ), wp_salt( 'auth' ) ),
        );
    }

    /**
     * Check whether Solid Security is active on this site.
     *
     * @return bool
     */
    protected function abilities_v2_provider_available() {
        return class_exists( '\ITSEC_Core' );
    }

    /**
     * Check whether the installed Solid version has a typed mutation adapter.
     *
     * Mutation support currently adds nothing beyond the provider being
     * present, but the two seams answer different questions and reads gate on
     * availability alone.
     *
     * @return bool
     */
    protected function abilities_v2_provider_supports_mutation() {
        return $this->abilities_v2_provider_available();
    }

    /**
     * Execute one version-specific Solid operation.
     *
     * Supported free/Pro versions override this seam only after their result
     * can satisfy the closed validators below.
     *
     * @param string $operation Operation name.
     * @param array  $payload   Closed payload.
     * @return array|WP_Error
     */
    protected function abilities_v2_provider_operation( $operation, $payload ) {
        if ( 'ability_solid_lockouts_v2' === $operation ) {
            return $this->abilities_v2_provider_lockouts( $payload );
        }
        if ( 'ability_solid_release_lockouts_v2' === $operation ) {
            return $this->abilities_v2_provider_release_lockouts( $payload );
        }
        if ( 'ability_solid_replace_whitelist_v2' === $operation ) {
            return $this->abilities_v2_provider_replace_whitelist( $payload );
        }
        if ( 'ability_solid_file_scan_v2' === $operation ) {
            return $this->abilities_v2_provider_file_scan();
        }
        if ( 'ability_solid_backup_v2' === $operation ) {
            return $this->abilities_v2_provider_backup();
        }
        if ( 'ability_solid_malware_scan_v2' === $operation ) {
            return $this->abilities_v2_provider_malware_scan();
        }
        if ( 'ability_solid_clear_logs_v2' === $operation ) {
            return $this->abilities_v2_provider_clear_logs( $payload['dry_run'] );
        }
        return new \WP_Error( 'unsupported_version' );
    }

    /**
     * Return one bounded opaque page of current lockouts.
     *
     * @param array $payload Validated cursor and page limit.
     * @return array|\WP_Error Closed lockout page or error.
     */
    private function abilities_v2_provider_lockouts( $payload ) {
        $state = $this->abilities_v2_current_lockouts();
        if ( is_wp_error( $state ) ) {
            return $state;
        }

        $start = 0;
        if ( null !== $payload['after_lockout_ref'] ) {
            $found = false;
            foreach ( $state['rows'] as $index => $row ) {
                if ( hash_equals( $payload['after_lockout_ref'], $row['lockout_ref'] ) ) {
                    $start = $index + 1;
                    $found = true;
                    break;
                }
            }
            if ( ! $found ) {
                return new \WP_Error( 'target_not_found' );
            }
        }

        $page      = array_slice( $state['rows'], $start, $payload['limit'] );
        $truncated = $start + count( $page ) < count( $state['rows'] );
        $public    = array();
        foreach ( $page as $row ) {
            $public[] = array(
                'lockout_ref' => $row['lockout_ref'],
                'kind'        => $row['kind'],
                'expires_at'  => $row['expires_at'],
            );
        }

        return array(
            'lockouts'               => $public,
            'next_after_lockout_ref' => $truncated && array() !== $public ? $public[ count( $public ) - 1 ]['lockout_ref'] : null,
            'truncated'              => $truncated,
            'revision'               => $state['revision'],
        );
    }

    /**
     * Release exact current lockouts selected by opaque reference.
     *
     * @param array $payload Validated references and revision.
     * @return array|\WP_Error Closed mutation result or error.
     */
    private function abilities_v2_provider_release_lockouts( $payload ) {
        $state = $this->abilities_v2_current_lockouts();
        if ( is_wp_error( $state ) ) {
            return $state;
        }
        if ( ! hash_equals( $payload['if_match'], $state['revision'] ) ) {
            return new \WP_Error( 'stale_revision' );
        }

        $current = array();
        foreach ( $state['rows'] as $row ) {
            $current[ $row['lockout_ref'] ] = $row;
        }
        foreach ( $payload['lockout_refs'] as $lockout_ref ) {
            if ( ! isset( $current[ $lockout_ref ] ) ) {
                return new \WP_Error( 'target_not_found' );
            }
        }

        global $wpdb;
        $released = 0;
        $absent   = 0;
        $failed   = 0;
        foreach ( $payload['lockout_refs'] as $lockout_ref ) {
            $wpdb->last_error = '';
            $changed          = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Exact extension table CAS.
                $wpdb->base_prefix . 'itsec_lockouts',
                array( 'lockout_active' => 0 ),
                array(
                    'lockout_id'     => $current[ $lockout_ref ]['database_id'],
                    'lockout_active' => 1,
                ),
                array( '%d' ),
                array( '%d', '%d' )
            );
            if ( '' !== $wpdb->last_error || false === $changed ) {
                ++$failed;
            } elseif ( 1 === (int) $changed ) {
                ++$released;
            } else {
                $wpdb->last_error = '';
                $active           = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Exact CAS readback.
                    $wpdb->prepare(
                        'SELECT lockout_active FROM `' . $wpdb->base_prefix . 'itsec_lockouts` WHERE lockout_id=%d LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- wpdb base prefix is trusted.
                        $current[ $lockout_ref ]['database_id']
                    )
                );
                if ( '' !== $wpdb->last_error ) {
                    ++$failed;
                } elseif ( null === $active || 0 === (int) $active ) {
                    ++$absent;
                } else {
                    ++$failed;
                }
            }
        }
        if ( 0 < $released && class_exists( '\ITSEC_Lib' ) && is_callable( array( '\ITSEC_Lib', 'clear_caches' ) ) ) {
            \ITSEC_Lib::clear_caches();
        }
        $after = $this->abilities_v2_current_lockouts();
        if ( is_wp_error( $after ) ) {
            return new \WP_Error( 'outcome_unknown' );
        }
        return array(
            'requested_count'      => count( $payload['lockout_refs'] ),
            'releasable_count'     => $released + $failed,
            'released_count'       => $released,
            'already_absent_count' => $absent,
            'failed_count'         => $failed,
            'revision'             => $after['revision'],
        );
    }

    /** Read the complete bounded current lockout set with private row IDs. */
    private function abilities_v2_current_lockouts() {
        global $wpdb;
        if ( ! is_object( $wpdb ) || ! isset( $wpdb->base_prefix ) || ! is_string( $wpdb->base_prefix ) || ! is_callable( array( $wpdb, 'get_results' ) ) || ! is_callable( array( $wpdb, 'prepare' ) ) ) {
            return new \WP_Error( 'storage_unavailable' );
        }
        $wpdb->last_error = '';
        $rows             = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Bounded exact extension table read.
            $wpdb->prepare(
                'SELECT lockout_id,lockout_host,lockout_user,lockout_username,lockout_expire_gmt FROM `' . $wpdb->base_prefix . 'itsec_lockouts` WHERE lockout_active=1 AND lockout_expire_gmt>%s ORDER BY lockout_id ASC LIMIT 1001', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- wpdb base prefix is trusted.
                gmdate( 'Y-m-d H:i:s' )
            ),
            ARRAY_A
        );
        if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
            return new \WP_Error( 'storage_unavailable' );
        }
        if ( 1000 < count( $rows ) ) {
            return new \WP_Error( 'state_conflict' );
        }

        $normalized = array();
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) || ! $this->abilities_v2_exact_keys( $row, array( 'lockout_id', 'lockout_host', 'lockout_user', 'lockout_username', 'lockout_expire_gmt' ) ) ) {
                return new \WP_Error( 'invalid_stored_state' );
            }
            $database_id = $this->abilities_v2_positive_integer( $row['lockout_id'] );
            $user_id     = $this->abilities_v2_nonnegative_integer( null === $row['lockout_user'] ? 0 : $row['lockout_user'] );
            $host        = null === $row['lockout_host'] ? '' : $row['lockout_host'];
            $username    = null === $row['lockout_username'] ? '' : $row['lockout_username'];
            if ( null === $database_id || null === $user_id || ! is_string( $host ) || ! is_string( $username ) || ! is_string( $row['lockout_expire_gmt'] ) || 19 !== strlen( $row['lockout_expire_gmt'] ) ) {
                return new \WP_Error( 'invalid_stored_state' );
            }
            $expires = strtotime( $row['lockout_expire_gmt'] . ' UTC' );
            if ( false === $expires || gmdate( 'Y-m-d H:i:s', $expires ) !== $row['lockout_expire_gmt'] ) {
                return new \WP_Error( 'invalid_stored_state' );
            }
            $signals = array();
            if ( '' !== $host ) {
                $signals[] = 'host';
            }
            if ( 0 < $user_id ) {
                $signals[] = 'user';
            }
            if ( '' !== $username ) {
                $signals[] = 'username';
            }
            $kind         = 1 === count( $signals ) ? $signals[0] : 'multiple';
            $ref          = hash_hmac( 'sha256', implode( '|', array( 'mainwp-solid-lockout-v1', home_url( '/' ), $database_id, $kind, $row['lockout_expire_gmt'] ) ), wp_salt( 'auth' ) );
            $normalized[] = array(
                'database_id' => $database_id,
                'lockout_ref' => $ref,
                'kind'        => $kind,
                'expires_at'  => gmdate( 'Y-m-d\TH:i:s\Z', $expires ),
            );
        }
        usort(
            $normalized,
            static function ( $left, $right ) {
                return strcmp( $left['lockout_ref'], $right['lockout_ref'] );
            }
        );
        $public = array();
        foreach ( $normalized as $row ) {
            $public[] = array( $row['lockout_ref'], $row['kind'], $row['expires_at'] );
        }
        return array(
            'rows'     => $normalized,
            'revision' => hash_hmac( 'sha256', 'mainwp-solid-lockouts-v1|' . wp_json_encode( $public ), wp_salt( 'auth' ) ),
        );
    }

    /**
     * Replace or remove the temporary whitelist and prove exact readback.
     *
     * @param array $payload Validated desired state, revision, and preview flag.
     * @return array|\WP_Error Closed mutation result or error.
     */
    private function abilities_v2_provider_replace_whitelist( $payload ) {
        $before = $this->abilities_v2_whitelist();
        if ( ! is_array( $before ) || ! isset( $before['ok'] ) || true !== $before['ok'] ) {
            return new \WP_Error( isset( $before['code'] ) && is_string( $before['code'] ) ? $before['code'] : 'invalid_stored_state' );
        }
        if ( ! hash_equals( $payload['if_match'], $before['revision'] ) ) {
            return new \WP_Error( 'stale_revision' );
        }

        $desired = false;
        if ( null !== $payload['ip_address'] ) {
            $packed = inet_pton( $payload['ip_address'] );
            if ( false === $packed || inet_ntop( $packed ) !== strtolower( $payload['ip_address'] ) ) {
                return new \WP_Error( 'invalid_stored_state' );
            }
            $desired = array(
                'ip'  => strtolower( $payload['ip_address'] ),
                'exp' => time() + $payload['ttl_seconds'],
            );
        }
        $stored  = get_site_option( 'itsec_temp_whitelist_ip', false );
        $changed = $stored !== $desired;
        if ( $payload['dry_run'] ) {
            if ( false === $desired ) {
                $after = $this->abilities_v2_whitelist_response( false, null, null, null );
            } else {
                $packed = inet_pton( $desired['ip'] );
                $after  = $this->abilities_v2_whitelist_response( true, $desired['exp'], 4 === strlen( $packed ) ? 'ipv4' : 'ipv6', bin2hex( $packed ) ); // phpcs:ignore WordPress.PHP.YodaConditions.NotYoda -- Constant is deliberately first.
            }
            return array(
                'changed'        => $changed,
                'active'         => $after['active'],
                'expires_at'     => $after['expires_at'],
                'address_family' => $after['address_family'],
                'revision'       => $after['revision'],
            );
        }
        if ( $changed ) {
            if ( false === $desired ) {
                delete_site_option( 'itsec_temp_whitelist_ip' );
            } else {
                update_site_option( 'itsec_temp_whitelist_ip', $desired );
            }
            if ( get_site_option( 'itsec_temp_whitelist_ip', false ) !== $desired ) {
                return new \WP_Error( 'outcome_unknown' );
            }
        }
        $after = $this->abilities_v2_whitelist();
        if ( ! is_array( $after ) || ! isset( $after['ok'] ) || true !== $after['ok'] ) {
            return new \WP_Error( 'outcome_unknown' );
        }
        return array(
            'changed'        => $changed,
            'active'         => $after['active'],
            'expires_at'     => $after['expires_at'],
            'address_family' => $after['address_family'],
            'revision'       => $after['revision'],
        );
    }

    /** Request a file-change scan and retain no provider findings. */
    private function abilities_v2_provider_file_scan() {
        $result = $this->file_change();
        $ok     = is_array( $result ) && isset( $result['result'] ) && 'success' === $result['result'];
        return $this->abilities_v2_coarse_operation_result( 'file_scan', $ok, false, $ok ? 'accepted' : 'failed' );
    }

    /**
     * Load the Solid File Change module the way the v1 file_change() path does.
     *
     * Solid leaves this module unloaded on a normal request, so a poll arrives
     * with both classes absent and would report plugin_unavailable forever on a
     * site that is in fact running Solid.
     */
    private function abilities_v2_load_file_change_module() {
        /**
         * Itsec modules path.
         *
         * @global string $mainwp_itsec_modules_path MainWP itsec modules path.
         */
        global $mainwp_itsec_modules_path;

        if ( ! is_string( $mainwp_itsec_modules_path ) || '' === $mainwp_itsec_modules_path ) {
            return;
        }

        $files = array(
            '\ITSEC_File_Change_Scanner' => $mainwp_itsec_modules_path . 'file-change/scanner.php',
            '\ITSEC_File_Change'         => $mainwp_itsec_modules_path . 'file-change/class-itsec-file-change.php',
        );
        foreach ( $files as $class => $file ) {
            // Unlike v1, this path also runs where Solid is absent or has moved the module, so a missing file must not fatal.
            if ( ! class_exists( $class ) && file_exists( $file ) ) {
                require_once $file; // NOSONAR - WP compatible.
            }
        }
    }

    /** Poll the active File Change scan without submitting a second scan request. */
    private function abilities_v2_poll_file_scan() {
        $this->abilities_v2_load_file_change_module();

        if ( ! class_exists( '\ITSEC_File_Change_Scanner' ) || ! is_callable( array( '\ITSEC_File_Change_Scanner', 'get_status' ) ) ) {
            return new \WP_Error( 'plugin_unavailable' );
        }

        try {
            if ( is_callable( array( '\ITSEC_File_Change_Scanner', 'is_running' ) ) && \ITSEC_File_Change_Scanner::is_running() ) {
                return $this->abilities_v2_coarse_operation_result( 'file_scan', true, false, 'accepted' );
            }
            $status = \ITSEC_File_Change_Scanner::get_status();
        } catch ( \Throwable $throwable ) { // NOSONAR - third-party version boundary.
            return new \WP_Error( 'outcome_unknown' );
        }

        if ( ! is_array( $status ) || empty( $status['complete'] ) || ! class_exists( '\ITSEC_File_Change' ) || ! is_callable( array( '\ITSEC_File_Change', 'get_latest_changes' ) ) ) {
            return $this->abilities_v2_coarse_operation_result( 'file_scan', true, false, 'accepted' );
        }

        try {
            $changes = \ITSEC_File_Change::get_latest_changes();
        } catch ( \Throwable $throwable ) { // NOSONAR - third-party version boundary.
            return new \WP_Error( 'outcome_unknown' );
        }
        if ( ! is_array( $changes ) ) {
            return new \WP_Error( 'outcome_unknown' );
        }
        foreach ( $changes as $change_group ) {
            if ( ! is_array( $change_group ) ) {
                return new \WP_Error( 'outcome_unknown' );
            }
            if ( array() !== $change_group ) {
                return $this->abilities_v2_coarse_operation_result( 'file_scan', true, true, 'changes_found' );
            }
        }
        return $this->abilities_v2_coarse_operation_result( 'file_scan', true, true, 'clean' );
    }

    /** Run the configured database backup and retain no artifact details. */
    private function abilities_v2_provider_backup() {
        $result = $this->backup_db();
        $ok     = is_array( $result ) && isset( $result['result'] ) && 'success' === $result['result'];
        return $this->abilities_v2_coarse_operation_result( 'database_backup', $ok, true, $ok ? 'backup_created' : 'failed' );
    }

    /** Run the malware scan and reduce the result to the latest coarse state. */
    private function abilities_v2_provider_malware_scan() {
        $before = $this->abilities_v2_summary();
        if ( ! is_array( $before ) || empty( $before['ok'] ) || empty( $before['complete'] ) || ! isset( $before['latest_scan']['completed_at'] ) || ( null !== $before['latest_scan']['completed_at'] && ! $this->abilities_v2_valid_date( $before['latest_scan']['completed_at'] ) ) ) {
            return $this->abilities_v2_coarse_operation_result( 'malware_scan', false, true, 'outcome_unknown' );
        }
        $before_timestamp = null === $before['latest_scan']['completed_at'] ? null : strtotime( $before['latest_scan']['completed_at'] );
        $result           = $this->malware_scan();
        if ( ! is_array( $result ) || isset( $result['error'] ) ) {
            return $this->abilities_v2_coarse_operation_result( 'malware_scan', false, true, 'failed' );
        }
        $summary = $this->abilities_v2_summary();
        if ( ! is_array( $summary ) || empty( $summary['ok'] ) || empty( $summary['complete'] ) || ! isset( $summary['latest_scan']['status'], $summary['latest_scan']['completed_at'] ) || null === $summary['latest_scan']['completed_at'] || ! $this->abilities_v2_valid_date( $summary['latest_scan']['completed_at'] ) ) {
            return $this->abilities_v2_coarse_operation_result( 'malware_scan', true, true, 'outcome_unknown' );
        }
        $after_timestamp = strtotime( $summary['latest_scan']['completed_at'] );
        if ( false === $after_timestamp || ( null !== $before_timestamp && $after_timestamp <= $before_timestamp ) ) {
            return $this->abilities_v2_coarse_operation_result( 'malware_scan', true, true, 'outcome_unknown' );
        }
        $outcome = $summary['latest_scan']['status'];
        if ( ! in_array( $outcome, array( 'clean', 'issues_found', 'failed' ), true ) ) {
            $outcome = 'outcome_unknown';
        }
        return $this->abilities_v2_coarse_operation_result( 'malware_scan', true, true, $outcome );
    }

    /**
     * Count or delete the exact Solid log table with checked postconditions.
     *
     * @param bool $dry_run Whether to return counts without deletion.
     * @return array|\WP_Error Closed deletion result or error.
     */
    private function abilities_v2_provider_clear_logs( $dry_run ) {
        global $wpdb;
        if ( ! is_object( $wpdb ) || ! isset( $wpdb->base_prefix ) || ! is_string( $wpdb->base_prefix ) || ! is_callable( array( $wpdb, 'get_var' ) ) || ! is_callable( array( $wpdb, 'prepare' ) ) || ! is_callable( array( $wpdb, 'query' ) ) ) {
            return new \WP_Error( 'storage_unavailable' );
        }
        $table            = $wpdb->base_prefix . 'itsec_log';
        $wpdb->last_error = '';
        $before_raw       = $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $table . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Exact discovered extension table.
        $before           = $this->abilities_v2_nonnegative_integer( $before_raw );
        if ( '' !== $wpdb->last_error ) {
            return new \WP_Error( 'plugin_unavailable' );
        }
        if ( null === $before ) {
            return new \WP_Error( 'storage_unavailable' );
        }
        if ( $dry_run ) {
            return array(
                'rows_before'  => $before,
                'rows_deleted' => 0,
                'rows_after'   => $before,
                'changed'      => false,
                'generation'   => hash_hmac( 'sha256', 'mainwp-solid-clear-logs-v1|' . $before, wp_salt( 'auth' ) ),
            );
        }
        $wpdb->last_error = '';
        $deleted          = $wpdb->query( 'DELETE FROM `' . $table . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Exact discovered extension table.
        if ( '' !== $wpdb->last_error || false === $deleted ) {
            return new \WP_Error( 'outcome_unknown' );
        }
        $wpdb->last_error = '';
        $after_raw        = $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $table . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Exact discovered extension table.
        $after            = $this->abilities_v2_nonnegative_integer( $after_raw );
        if ( '' !== $wpdb->last_error || null === $after || 0 !== $after || $before - $after !== (int) $deleted ) {
            return new \WP_Error( 'outcome_unknown' );
        }
        return array(
            'rows_before'  => $before,
            'rows_deleted' => $before,
            'rows_after'   => 0,
            'changed'      => 0 < $before,
            'generation'   => hash_hmac( 'sha256', 'mainwp-solid-clear-logs-v1|0', wp_salt( 'auth' ) ),
        );
    }

    /**
     * Build a closed coarse result for one provider operation.
     *
     * @param string $type      Operation type.
     * @param bool   $accepted  Whether the provider accepted it.
     * @param bool   $completed Whether the provider completed it.
     * @param string $outcome   Closed coarse outcome.
     * @return array Closed result.
     */
    private function abilities_v2_coarse_operation_result( $type, $accepted, $completed, $outcome ) {
        return array(
            'accepted'   => (bool) $accepted,
            'completed'  => (bool) $completed,
            'outcome'    => $outcome,
            'generation' => hash_hmac( 'sha256', implode( '|', array( 'mainwp-solid-operation-v1', $type, $accepted ? '1' : '0', $completed ? '1' : '0', $outcome, time() ) ), wp_salt( 'auth' ) ),
        );
    }

    /**
     * Normalize a positive database integer without accepting padded values.
     *
     * @param mixed $value Candidate value.
     * @return int|null Normalized value.
     */
    private function abilities_v2_positive_integer( $value ) {
        if ( is_int( $value ) ) {
            return 0 < $value ? $value : null;
        }
        return is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $value ) && (string) (int) $value === $value ? (int) $value : null;
    }

    /**
     * Normalize a nonnegative database integer without accepting padded values.
     *
     * @param mixed $value Candidate value.
     * @return int|null Normalized value.
     */
    private function abilities_v2_nonnegative_integer( $value ) {
        if ( is_int( $value ) ) {
            return 0 <= $value ? $value : null;
        }
        return is_string( $value ) && 1 === preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) && (string) (int) $value === $value ? (int) $value : null;
    }

    /**
     * Validate one provider payload.
     *
     * @param string $operation Operation name.
     * @param array  $payload   Operation payload.
     * @return bool
     */
    private function abilities_v2_valid_provider_payload( $operation, $payload ) {
        if ( 'ability_solid_lockouts_v2' === $operation ) {
            return $this->abilities_v2_exact_keys( $payload, array( 'limit', 'after_lockout_ref' ) ) && is_int( $payload['limit'] ) && 1 <= $payload['limit'] && 100 >= $payload['limit'] && ( null === $payload['after_lockout_ref'] || $this->abilities_v2_valid_hash( $payload['after_lockout_ref'] ) );
        }
        if ( 'ability_solid_release_lockouts_v2' === $operation ) {
            if ( ! $this->abilities_v2_exact_keys( $payload, array( 'lockout_refs', 'if_match' ) ) || ! is_array( $payload['lockout_refs'] ) || 1 > count( $payload['lockout_refs'] ) || 100 < count( $payload['lockout_refs'] ) || count( $payload['lockout_refs'] ) !== count( array_unique( $payload['lockout_refs'] ) ) || ! $this->abilities_v2_valid_hash( $payload['if_match'] ) ) {
                return false;
            }
            foreach ( $payload['lockout_refs'] as $lockout_ref ) {
                if ( ! $this->abilities_v2_valid_hash( $lockout_ref ) ) {
                    return false;
                }
            }
            return true;
        }
        if ( 'ability_solid_replace_whitelist_v2' === $operation ) {
            if ( ! $this->abilities_v2_exact_keys( $payload, array( 'ip_address', 'ttl_seconds', 'if_match', 'dry_run' ) ) || ! $this->abilities_v2_valid_hash( $payload['if_match'] ) || ! is_bool( $payload['dry_run'] ) ) {
                return false;
            }
            if ( null === $payload['ip_address'] ) {
                return null === $payload['ttl_seconds'];
            }
            return is_string( $payload['ip_address'] ) && 45 >= strlen( $payload['ip_address'] ) && false !== inet_pton( $payload['ip_address'] ) && is_int( $payload['ttl_seconds'] ) && 300 <= $payload['ttl_seconds'] && 86400 >= $payload['ttl_seconds'];
        }
        if ( 'ability_solid_clear_logs_v2' === $operation ) {
            return $this->abilities_v2_exact_keys( $payload, array( 'dry_run' ) ) && is_bool( $payload['dry_run'] );
        }
        return in_array( $operation, array( 'ability_solid_file_scan_v2', 'ability_solid_backup_v2', 'ability_solid_malware_scan_v2' ), true ) && array() === $payload;
    }

    /**
     * Validate one provider result.
     *
     * @param string $operation Operation name.
     * @param mixed  $result    Provider result.
     * @return bool
     */
    private function abilities_v2_valid_provider_result( $operation, $result ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh -- Closed result matrix.
        if ( ! is_array( $result ) ) {
            return false;
        }
        if ( 'ability_solid_lockouts_v2' === $operation ) {
            if ( ! $this->abilities_v2_exact_keys( $result, array( 'lockouts', 'next_after_lockout_ref', 'truncated', 'revision' ) ) || ! is_array( $result['lockouts'] ) || 100 < count( $result['lockouts'] ) || ( null !== $result['next_after_lockout_ref'] && ! $this->abilities_v2_valid_hash( $result['next_after_lockout_ref'] ) ) || ! is_bool( $result['truncated'] ) || ! $this->abilities_v2_valid_hash( $result['revision'] ) ) {
                return false;
            }
            foreach ( $result['lockouts'] as $lockout ) {
                if ( ! is_array( $lockout ) || ! $this->abilities_v2_exact_keys( $lockout, array( 'lockout_ref', 'kind', 'expires_at' ) ) || ! $this->abilities_v2_valid_hash( $lockout['lockout_ref'] ) || ! in_array( $lockout['kind'], array( 'host', 'user', 'username', 'multiple' ), true ) || ! $this->abilities_v2_valid_date( $lockout['expires_at'] ) ) {
                    return false;
                }
            }
            return true;
        }
        if ( 'ability_solid_release_lockouts_v2' === $operation ) {
            return $this->abilities_v2_exact_keys( $result, array( 'requested_count', 'releasable_count', 'released_count', 'already_absent_count', 'failed_count', 'revision' ) ) && $this->abilities_v2_count( $result['requested_count'], 1, 100 ) && $this->abilities_v2_count( $result['releasable_count'], 0, 100 ) && $this->abilities_v2_count( $result['released_count'], 0, 100 ) && $this->abilities_v2_count( $result['already_absent_count'], 0, 100 ) && $this->abilities_v2_count( $result['failed_count'], 0, 100 ) && $result['requested_count'] === $result['released_count'] + $result['already_absent_count'] + $result['failed_count'] && $this->abilities_v2_valid_hash( $result['revision'] );
        }
        if ( 'ability_solid_replace_whitelist_v2' === $operation ) {
            return $this->abilities_v2_exact_keys( $result, array( 'changed', 'active', 'expires_at', 'address_family', 'revision' ) ) && is_bool( $result['changed'] ) && is_bool( $result['active'] ) && ( null === $result['expires_at'] || $this->abilities_v2_valid_date( $result['expires_at'] ) ) && in_array( $result['address_family'], array( 'ipv4', 'ipv6', null ), true ) && $this->abilities_v2_valid_hash( $result['revision'] ) && ( $result['active'] ? null !== $result['expires_at'] && null !== $result['address_family'] : null === $result['expires_at'] && null === $result['address_family'] );
        }
        if ( in_array( $operation, array( 'ability_solid_file_scan_v2', 'ability_solid_malware_scan_v2' ), true ) ) {
            return $this->abilities_v2_exact_keys( $result, array( 'accepted', 'completed', 'outcome', 'generation' ) ) && is_bool( $result['accepted'] ) && is_bool( $result['completed'] ) && in_array( $result['outcome'], array( 'accepted', 'clean', 'changes_found', 'issues_found', 'failed', 'outcome_unknown' ), true ) && $this->abilities_v2_valid_hash( $result['generation'] );
        }
        if ( 'ability_solid_backup_v2' === $operation ) {
            return $this->abilities_v2_exact_keys( $result, array( 'accepted', 'completed', 'outcome', 'generation' ) ) && is_bool( $result['accepted'] ) && is_bool( $result['completed'] ) && in_array( $result['outcome'], array( 'accepted', 'backup_created', 'failed', 'outcome_unknown' ), true ) && $this->abilities_v2_valid_hash( $result['generation'] );
        }
        return 'ability_solid_clear_logs_v2' === $operation && $this->abilities_v2_exact_keys( $result, array( 'rows_before', 'rows_deleted', 'rows_after', 'changed', 'generation' ) ) && $this->abilities_v2_count( $result['rows_before'], 0, PHP_INT_MAX ) && $this->abilities_v2_count( $result['rows_deleted'], 0, PHP_INT_MAX ) && $this->abilities_v2_count( $result['rows_after'], 0, PHP_INT_MAX ) && $result['rows_deleted'] <= $result['rows_before'] && $result['rows_after'] === $result['rows_before'] - $result['rows_deleted'] && is_bool( $result['changed'] ) && ( 0 < $result['rows_deleted'] ) === $result['changed'] && $this->abilities_v2_valid_hash( $result['generation'] );
    }

    /**
     * Validate an inclusive integer count.
     *
     * @param mixed $value   Candidate value.
     * @param int   $minimum Minimum value.
     * @param int   $maximum Maximum value.
     * @return bool
     */
    private function abilities_v2_count( $value, $minimum, $maximum ) {
        return is_int( $value ) && $minimum <= $value && $maximum >= $value;
    }

    /**
     * Validate a SHA-256 reference.
     *
     * @param mixed $value Candidate value.
     * @return bool
     */
    private function abilities_v2_valid_hash( $value ) {
        return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $value );
    }

    /**
     * Validate a UUID request reference.
     *
     * @param mixed $value Candidate value.
     * @return bool
     */
    private function abilities_v2_valid_request_ref( $value ) {
        return is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di', $value );
    }

    /**
     * Validate a UTC date-time.
     *
     * @param mixed $value Candidate value.
     * @return bool
     */
    private function abilities_v2_valid_date( $value ) {
        if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value ) ) {
            return false;
        }
        $date   = \DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone( 'UTC' ) );
        $errors = \DateTimeImmutable::getLastErrors();
        return false !== $date && ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) && $date->format( 'Y-m-d\TH:i:s\Z' ) === $value;
    }

    /**
     * Check an exact associative-key set.
     *
     * @param array $value Input object.
     * @param array $keys  Expected keys.
     * @return bool
     */
    private function abilities_v2_exact_keys( $value, $keys ) {
        $actual = array_keys( $value );
        sort( $actual, SORT_STRING );
        sort( $keys, SORT_STRING );

        return $actual === $keys;
    }

    /**
     * Build a closed abilities protocol error.
     *
     * @param string $operation Protocol operation.
     * @param string $code      Stable error code.
     * @return array<string,string|bool>
     */
    private function abilities_v2_error( $operation, $code ) {
        return array(
            'protocol'  => '2',
            'operation' => $operation,
            'ok'        => false,
            'code'      => $code,
        );
    }


    /**
     * Set show or hide UpdraftPlus Plugin from Admin & plugins list.
     *
     * @return array $information Return results.
     *
     * @uses \MainWP\Child\MainWP_Helper::update_option()
     */
    public function set_showhide() {
        $hide = MainWP_System::instance()->validate_params( 'showhide' );
        MainWP_Helper::update_option( 'mainwp_ithemes_hide_plugin', $hide );
        $information['result'] = 'success';

        return $information;
    }

    /**
     * Initiate iThemes settings.
     *
     * @uses MainWP_Child_IThemes_Security::is_plugin_installed()
     */
    public function ithemes_init() {
        if ( ! $this->is_plugin_installed ) {
            return;
        }

        if ( 'hide' === get_option( 'mainwp_ithemes_hide_plugin' ) ) {
            add_filter( 'all_plugins', array( $this, 'all_plugins' ) );
            add_action( 'admin_menu', array( $this, 'remove_menu' ) );
            add_action( 'admin_init', array( $this, 'admin_init' ) );
            add_action( 'admin_head', array( &$this, 'custom_admin_css' ) );
            if ( isset( $_GET['page'] ) && ( in_array(
                $_GET['page'],
                array(
                    'itsec',
                    'itsec-dashboard',
                    'itsec-site-scan',
                    'itsec-firewall',
                    'itsec-vulnerabilities',
                    'itsec-user-security',
                    'itsec-tools',
                    'itsec-logs',
                    'itsec-go-pro',
                )
            ) || 'itsec-security-check' === $_GET['page'] ) ) {
                wp_safe_redirect( get_option( 'siteurl' ) . '/wp-admin/index.php' );
                exit();
            }
        }
    }

    /**
     * iThemes Security Admin initiation.
     */
    public function admin_init() {
        remove_meta_box( 'itsec-dashboard-widget', 'dashboard', 'normal' );
    }

    /**
     * Remove iThemes Security from plugins page.
     *
     * @param array $plugins All plugins array.
     *
     * @return array $plugins All plugins array with iThemes Security removed.
     */
    public function all_plugins( $plugins ) {
        foreach ( $plugins as $key => $value ) {
            $plugin_slug = basename( $key, '.php' );
            if ( 'better-wp-security' === $plugin_slug || 'ithemes-security-pro' === $plugin_slug ) {
                unset( $plugins[ $key ] );
            }
        }

        return $plugins;
    }

    /**
     *  Remove iThemes Security plugin from WP Admin menu.
     */
    public function remove_menu() {
        $remove_pages = array(
            'itsec', // compatible.
            'itsec-dashboard',

        );
        $remove_subpages = array(
            'itsec-site-scan',
            'itsec-firewall',
            'itsec-vulnerabilities',
            'itsec-user-security',
            'itsec-tools',
            'itsec-logs',
            'itsec-go-pro',
            'itsec', // compatible.
        );
        foreach ( $remove_pages as $slug ) {
            remove_menu_page( $slug );
        }
        foreach ( $remove_subpages as $slug ) {
            remove_submenu_page( 'itsec-dashboard', $slug );
        }
    }

    /**
     * Custom admin CSS.
     */
    public function custom_admin_css() {
        ?>
        <style type="text/css">
            #wp-admin-bar-itsec_admin_bar_menu{
                display: none !important;
            }
        </style>
        <?php
    }

    /**
     * Save UpdraftPlus settings.
     *
     * @return array[] $return Return Error message or Success Message.
     *
     * @uses \ITSEC_Lib::get_server()
     * @uses \ITSEC_Lib::get_ssl_support_probability()
     * @uses \ITSEC_Lib_Config_File::get_server_config()
     * @uses \ITSEC_Lib_Config_File::get_wp_config()
     * @uses \ITSEC_Modules::get_default()
     * @uses \ITSEC_Modules::get_setting()
     * @uses MainWP_Child_IThemes_Security::get_lockouts()
     * @uses MainWP_Child_IThemes_Security::validate_directory()
     * @uses MainWP_Child_IThemes_Security::activate_api_key()
     * @uses MainWP_Child_IThemes_Security::get_excludable_tables()
     * @uses MainWP_Child_IThemes_Security::get_available_admin_users_and_roles()
     */
    public function save_settings() { //phpcs:ignore -- NOSONAR - complex.

        if ( ! class_exists( '\ITSEC_Lib' ) ) {
            require_once \ITSEC_Core::get_core_dir() . '/core/class-itsec-lib.php'; // NOSONAR - WP compatible.
        }

        $_itsec_modules = array(
            'global',
            'away-mode',
            'backup',
            'hide-backend',
            'ipcheck',
            'ban-users',
            'brute-force',
            'file-change',
            '404-detection',
            'network-brute-force',
            'ssl',
            'password-requirements',
            'system-tweaks',
            'wordpress-tweaks',
            'multisite-tweaks',
            'notification-center',
            'two-factor',
            'firewall',
        );

        $require_permalinks = false;
        $updated            = false;
        $errors             = array();
        $nbf_settings       = array();

        // phpcs:disable WordPress.Security.NonceVerification
        $update_settings = isset( $_POST['settings'] ) ? json_decode( base64_decode( wp_unslash( $_POST['settings'] ) ), true ) : ''; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions  -- base64_encode function is used for http encode compatible..

        $exclude = array(
            'use_individual_location',
            'use_individual_exclude',
        );

        if ( ! is_array( $update_settings ) ) {
            $update_settings = array();
        }

        if ( $this->apply_default_solid_security_configuration( $_itsec_modules ) ) {
            $updated = true;
        }

        foreach ( $update_settings as $module => $settings ) {
            $do_not_save      = false;
            $current_settings = \ITSEC_Modules::get_settings( $module );
            if ( in_array( $module, $_itsec_modules ) ) {
                if ( 'wordpress-salts' === $module ) {
                    $settings['last_generated'] = \ITSEC_Modules::get_setting( $module, 'last_generated' );
                } elseif ( 'global' === $module ) {
                    $keep_olds = array( 'did_upgrade', 'log_info', 'show_new_dashboard_notice', 'show_security_check', 'nginx_file', 'manage_group' );
                    foreach ( $keep_olds as $key ) {
                        $settings[ $key ] = \ITSEC_Modules::get_setting( $module, $key );
                    }

                    if ( ! isset( $settings['log_location'] ) || empty( $settings['log_location'] ) ) {
                        $settings['log_location'] = \ITSEC_Modules::get_setting( $module, 'log_location' );
                    } else {
                        $result = $this->validate_directory( 'log_location', $settings['log_location'] );
                        if ( true !== $result ) {
                            $errors[]                 = $result;
                            $settings['log_location'] = \ITSEC_Modules::get_setting( $module, 'log_location' );
                        }
                    }
                } elseif ( 'backup' === $module ) {
                    if ( ! isset( $settings['location'] ) || empty( $settings['location'] ) ) {
                        $settings['location'] = \ITSEC_Modules::get_setting( $module, 'location' );
                    } else {
                        $result = $this->validate_directory( 'location', $settings['location'] );
                        if ( true !== $result ) {
                            $errors[]             = $result;
                            $settings['location'] = \ITSEC_Modules::get_setting( $module, 'location' );
                        }
                    }
                    if ( ! isset( $settings['exclude'] ) ) {
                        $settings['exclude'] = \ITSEC_Modules::get_setting( $module, 'exclude' );
                    }
                } elseif ( 'hide-backend' === $module ) {
                    if ( isset( $settings['enabled'] ) && ! empty( $settings['enabled'] ) ) {
                        $permalink_structure = get_option( 'permalink_structure', false );
                        if ( empty( $permalink_structure ) && ! is_multisite() ) {
                            $errors[]           = esc_html__( 'You must change <strong>WordPress permalinks</strong> to a setting other than "Plain" in order to use "Hide Backend" feature.', 'mainwp-child' );
                            $require_permalinks = true;
                            $do_not_save        = true;
                        }
                    }
                } elseif ( 'network-brute-force' === $module ) {

                    if ( isset( $settings['email'] ) ) {
                        $result = $this->activate_api_key( $settings );
                        if ( false === $result ) {
                            $nbf_settings = $settings;
                            $errors[]     = 'Error: Active iThemes Network Brute Force Protection Api Key';
                        } else {
                            $nbf_settings = $result;
                        }
                    } else {
                        $previous_settings = \ITSEC_Modules::get_settings( $module );
                        if ( isset( $settings['enable_ban'] ) ) {
                            $previous_settings['enable_ban'] = $settings['enable_ban'];
                            $nbf_settings                    = $previous_settings;
                        } else {
                            $do_not_save  = true;
                            $nbf_settings = $previous_settings;
                        }
                    }
                    $settings = $nbf_settings;
                } elseif ( 'notification-center' === $module ) {
                    if ( isset( $settings['notifications'] ) ) {
                        $update_fields = array( 'schedule', 'enabled', 'subject', 'message' );
                        if ( isset( $_POST['is_individual'] ) && $_POST['is_individual'] ) {
                            $update_fields = array_merge( $update_fields, array( 'user_list', 'email_list' ) );
                        }
                        foreach ( $settings['notifications'] as $key => $val ) {
                            foreach ( $update_fields as $field ) {
                                if ( isset( $val[ $field ] ) ) {
                                    $current_settings['notifications'][ $key ][ $field ] = $val[ $field ];
                                }
                            }
                        }
                        $updated = true;
                        \ITSEC_Modules::set_settings( $module, $current_settings );
                    }
                    continue;
                } elseif ( 'file-change' === $module && isset( $settings['show_warning'] ) ) {
                    unset( $settings['show_warning'] );
                }

                // Unset use_individual_location.
                foreach ( $exclude as $key ) {
                    if ( isset( $settings[ $key ] ) ) {
                        unset( $settings[ $key ] );
                    }
                }

                if ( ! $do_not_save ) {
                    foreach ( $settings as $key => $val ) {
                        $current_settings[ $key ] = $val;
                    }
                    if ( 'two-factor' === $module ) {
                        $active = \ITSEC_Modules::is_active( 'two-factor' );
                        if ( ! $active ) {
                            \ITSEC_Modules::activate( 'two-factor' );
                        }
                    }
                    \ITSEC_Modules::set_settings( $module, $current_settings );
                    $updated = true;
                }
            }
        }
        // phpcs:enable WordPress.Security.NonceVerification

        if ( isset( $update_settings['itsec_active_modules'] ) ) {
            $current_val = get_site_option( 'itsec_active_modules', array() );
            foreach ( $update_settings['itsec_active_modules'] as $mod => $val ) {
                $current_val[ $mod ] = $val;
            }
            update_site_option( 'itsec_active_modules', $current_val );
        }

        require_once \ITSEC_Core::get_core_dir() . '/lib/class-itsec-lib-config-file.php'; // NOSONAR - WP compatible.

        $values = array(
            'permalink_structure'  => get_option( 'permalink_structure' ),
            'is_multisite'         => is_multisite() ? 1 : 0,
            'users_can_register'   => get_site_option( 'users_can_register' ) ? 1 : 0,
            'server_nginx'         => ( \ITSEC_Lib::get_server() === 'nginx' ) ? 1 : 0,
            'has_ssl'              => \ITSEC_Lib::get_ssl_support_probability(),
            'jquery_version'       => \ITSEC_Modules::get_setting( 'wordpress-tweaks', 'jquery_version' ),
            'server_rules'         => \ITSEC_Lib_Config_File::get_server_config(),
            'config_rules'         => \ITSEC_Lib_Config_File::get_wp_config(),
            'default_log_location' => \ITSEC_Modules::get_default( 'global', 'log_location' ),
            'default_location'     => \ITSEC_Modules::get_default( 'backup', 'location' ),
            'excludable_tables'    => $this->get_excludable_tables(),
            'users_and_roles'      => $this->get_available_admin_users_and_roles(),
        );

        $return = array(
            'site_status' => $values,
        );

        if ( $require_permalinks ) {
            $return['require_permalinks'] = 1;
        }

        $return['nbf_settings'] = $nbf_settings;

        if ( ! empty( $errors ) ) {
            $return['extra_message'] = $errors;
        }

        if ( $updated ) {
            $return['result'] = 'success';
        } else {
            $return['error'] = esc_html__( 'Not Updated', 'mainwp-child' );
        }

        if ( class_exists( '\ITSEC_Modules' ) && ! \ITSEC_Modules::get_setting( 'global', 'onboard_complete' ) ) {
            \ITSEC_Modules::set_setting( 'global', 'onboard_complete', true );
            $return['result'] = 'success';
            unset( $return['error'] );
        }

        return $return;
    }

    /**
     * Seed Solid Security defaults and mark onboarding as complete.
     *
     * @param string[] $modules Modules to initialize.
     *
     * @return bool True when changes were applied.
     */
    private function apply_default_solid_security_configuration( $modules ) {  // phpcs:ignore -- NOSONAR
        if ( ! class_exists( '\ITSEC_Modules' ) ) {
            return false;
        }

        $did_update       = false;
        $defaults_applied = (bool) get_site_option( 'mainwp_child_itsec_defaults_applied', false );

        if ( ! $defaults_applied ) {
            foreach ( (array) $modules as $module ) {
                $defaults = \ITSEC_Modules::get_defaults( $module );
                if ( empty( $defaults ) ) {
                    continue;
                }

                $result = \ITSEC_Modules::set_settings( $module, $defaults );
                if ( is_wp_error( $result ) ) {
                    continue;
                }

                $did_update = true;
            }

            $active_modules = \ITSEC_Modules::get_active_modules();
            if ( ! empty( $active_modules ) ) {
                $stored_active = get_site_option( 'itsec_active_modules', array() );
                if ( ! is_array( $stored_active ) ) {
                    $stored_active = array();
                }

                $changed = false;
                foreach ( $active_modules as $module ) {
                    if ( empty( $stored_active[ $module ] ) ) {
                        $stored_active[ $module ] = true;
                        $changed                  = true;
                    }
                }

                if ( $changed ) {
                    update_site_option( 'itsec_active_modules', $stored_active );
                    $did_update = true;
                }
            }

            update_site_option( 'mainwp_child_itsec_defaults_applied', time() );
        }

        if ( ! \ITSEC_Modules::get_setting( 'global', 'onboard_complete' ) ) {
            \ITSEC_Modules::set_setting( 'global', 'onboard_complete', true );
            $did_update = true;
        }

        return $did_update;
    }

    /**
     * Activate network brute force.
     *
     * @return array $information Results array.
     *
     * @uses \ITSEC_Modules::get_settings()
     * @uses \ITSEC_Modules::activate()
     */
    public static function activate_network_brute_force() {
        $data        = isset( $_POST['data'] ) ? json_decode( base64_decode( wp_unslash( $_POST['data'] ) ), true ) : array(); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
        $information = array();
        if ( is_array( $data ) ) {
            $settings                  = \ITSEC_Modules::get_settings( 'network-brute-force' );
            $settings['email']         = $data['email'];
            $settings['updates_optin'] = $data['updates_optin'];
            $settings['api_nag']       = false;
            $results                   = \ITSEC_Modules::set_settings( 'network-brute-force', $settings );
            if ( is_wp_error( $results ) ) {
                $information['error'] = 'Error: Active iThemes Network Brute Force Protection Api Key';
            } elseif ( $results['saved'] ) {
                \ITSEC_Modules::activate( 'network-brute-force' );
                $nbf_settings = \ITSEC_Modules::get_settings( 'network-brute-force' );
            }
        }
        if ( null !== $nbf_settings ) {
            $information['nbf_settings'] = $nbf_settings;
            $information['result']       = 'success';
        }
        return $information;
    }

    /**
     * Validate directory.
     *
     * @param string $name Input name.
     * @param string $folder Folder.
     *
     * @return bool|string Return TRUE on success or Error message on failure.
     *
     * @uses \ITSEC_Lib_Directory::is_dir()
     * @uses \ITSEC_Lib_Directory::create()
     * @uses \ITSEC_Lib_Directory::is_writable()
     * @uses \ITSEC_Lib_Directory::add_file_listing_protection()
     */
    private function validate_directory( $name, $folder ) {
        require_once \ITSEC_Core::get_core_dir() . 'lib/class-itsec-lib-directory.php'; // NOSONAR - WP compatible.
        $error = null;
        if ( ! \ITSEC_Lib_Directory::is_dir( $folder ) ) {
            $result = \ITSEC_Lib_Directory::create( $folder );

            if ( is_wp_error( $result ) ) {
                $error = sprintf( _x( 'The directory supplied in %1$s cannot be used as a valid directory. %2$s', '%1$s is the input name. %2$s is the error message.', 'mainwp-child' ), $name, $result->get_error_message() );
            }
        }

        if ( empty( $error ) && ! \ITSEC_Lib_Directory::is_writable( $folder ) ) {
            $error = sprintf( esc_html__( 'The directory supplied in %1$s is not writable. Please select a directory that can be written to.', 'mainwp-child' ), $name );
        }

        if ( empty( $error ) ) {
            \ITSEC_Lib_Directory::add_file_listing_protection( $folder );
            return true;
        } else {
            return $error;
        }
    }

    /**
     * Activate api key.
     *
     * @param array $settings Setting array.
     *
     * @return array|bool Return $settings array or FALSE on failure.
     *
     * @uses \ITSEC_Network_Brute_Force_Utilities::get_api_key()
     * @uses \ITSEC_Network_Brute_Force_Utilities::activate_api_key()
     * @uses \ITSEC_Response::reload_module()
     */
    private function activate_api_key( $settings ) {

        /**
         * MainWP itsec modules path.
         *
         * @global string $mainwp_itsec_modules_path MainWP itsec modules path.
         * */
        global $mainwp_itsec_modules_path;

        if ( file_exists( $mainwp_itsec_modules_path . 'network-brute-force/utilities.php' ) ) {
            require_once $mainwp_itsec_modules_path . 'network-brute-force/utilities.php'; // NOSONAR - WP compatible.
        } elseif ( file_exists( $mainwp_itsec_modules_path . 'ipcheck/utilities.php' ) ) {
            require_once $mainwp_itsec_modules_path . 'ipcheck/utilities.php'; // NOSONAR - WP compatible.
        }

        $key = \ITSEC_Network_Brute_Force_Utilities::get_api_key( $settings['email'], $settings['updates_optin'] );
        if ( is_wp_error( $key ) ) {
            return false;
        } else {
            $secret = \ITSEC_Network_Brute_Force_Utilities::activate_api_key( $key );

            if ( is_wp_error( $secret ) ) {
                return false;
            } else {
                $settings['api_key']    = $key;
                $settings['api_secret'] = $secret;

                $settings['api_nag'] = false;

                \ITSEC_Response::reload_module( 'network-brute-force' );
            }
        }
        unset( $settings['email'] );
        return $settings;
    }

    /**
     * Backup status.
     *
     * @return int $status 1, 2, 3 or 4
     *  (1) Is not a multisite installation, backupbuddy_api exists & Scheduled backups are >=1
     *  (2) Is not multisite and backupbuddy_api exists
     *  (3) Has backup = true & schedualed backup = true
     *  (4) Has backup = true.
     *
     * @uses \backupbuddy_api::getSchedules()
     * @uses MainWP_Child_IThemes_Security::has_backup()
     * @uses MainWP_Child_IThemes_Security::scheduled_backup()
     * @uses MainWP_Child_IThemes_Security::has_backup()
     */
    public function backup_status() {
        $status = 0;
        if ( ! is_multisite() && class_exists( '\backupbuddy_api' ) && count( \backupbuddy_api::getSchedules() ) >= 1 ) {
            $status = 1;
        } elseif ( ! is_multisite() && class_exists( '\backupbuddy_api' ) ) {
            $status = 2;
        } elseif ( $this->has_backup() === true && $this->scheduled_backup() === true ) {
            $status = 3;
        } elseif ( $this->has_backup() === true ) {
            $status = 4;
        }

        return $status;
    }

    /**
     * Check if backup exists.
     *
     * @return bool TRUE|FALSE
     */
    public function has_backup() {
        $has_backup = false;

        return apply_filters( 'itsec_has_external_backup', $has_backup );
    }

    /**
     * Check if there is a shedualed backup.
     *
     * @return bool TRUE|FALSE.
     */
    public function scheduled_backup() {
        $sceduled_backup = false;

        return apply_filters( 'itsec_scheduled_external_backup', $sceduled_backup );
    }

    /**
     * Whitelist Dashboard IP address.
     *
     * @return array|string[] Response array.
     */
    public function whitelist() {

        /**
         * Itsec globals.
         *
         * @global array $itsec_globals itsec globals.
         * */
        global $itsec_globals;

        $ip       = isset( $_POST['ip'] ) ? sanitize_text_field( wp_unslash( $_POST['ip'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        $add_temp = false;
        $temp_ip  = get_site_option( 'itsec_temp_whitelist_ip' );
        if ( false !== $temp_ip ) {
            if ( ( $temp_ip['exp'] < $itsec_globals['current_time'] ) || ( $temp_ip['exp'] !== $ip ) ) {
                delete_site_option( 'itsec_temp_whitelist_ip' );
                $add_temp = true;
            }
        } else {
            $add_temp = true;
        }

        if ( false === $add_temp ) {
            return array( 'error' => 'Not Updated' );
        } else {
            $response = array(
                'ip'  => $ip,
                'exp' => $itsec_globals['current_time'] + 86400,
            );
            add_site_option( 'itsec_temp_whitelist_ip', $response );
            $response['exp_diff'] = human_time_diff( $itsec_globals['current_time'], $response['exp'] );
            $response['message1'] = esc_html__( 'Your IP Address', 'mainwp-child' );
            $response['message2'] = esc_html__( 'is whitelisted for', 'mainwp-child' );

            return $response;
        }
    }

    /**
     * Whitelist release.
     *
     * @return string Return 'Success'.
     */
    public function whitelist_release() {
        delete_site_option( 'itsec_temp_whitelist_ip' );

        return 'success';
    }

    /**
     * Backup Database.
     *
     * @return array $return Return results array.
     *
     * @uses \ITSEC_Backup()
     * @uses \ITSEC_Backup::run()
     * @uses \ITSEC_Backup::do_backup()
     * @uses \ITSEC_Response::get_error_strings()
     */
    public function backup_db() {

        /**
         * MainWP itsec modules path.
         *
         * @global string $mainwp_itsec_modules_path MainWP itsec modules path.
         * @global object $itsec_backup              ITsec backup class.
         */
        global $itsec_backup, $mainwp_itsec_modules_path;

        if ( ! isset( $itsec_backup ) ) {
            require_once $mainwp_itsec_modules_path . 'backup/class-itsec-backup.php'; // NOSONAR - WP compatible.
            $itsec_backup = new \ITSEC_Backup();
            $itsec_backup->run();
        }

        $return = array();

        $str_error = '';
        $result    = $itsec_backup->do_backup( true );

        if ( is_wp_error( $result ) ) {
            $errors = \ITSEC_Response::get_error_strings( $result );

            foreach ( $errors as $error ) {
                $str_error .= $error . '<br />';
            }
        } elseif ( is_string( $result ) ) {
            $return['result']  = 'success';
            $return['message'] = $result;
        } else {
            $str_error = sprintf( esc_html__( 'The backup request returned an unexpected response. It returned a response of type <code>%1$s</code>.', 'mainwp-child' ), gettype( $result ) );
        }

        if ( ! empty( $str_error ) ) {
            $return['error'] = $str_error;
        }

        return $return;
    }


    /**
     * Update WordPress Salts.
     *
     * @return array $return Return results array.
     *
     * @uses \ITSEC_WordPress_Salts_Utilities::generate_new_salts()
     * @uses \ITSEC_Response::get_error_strings()
     * @uses \ITSEC_Core::get_current_time_gmt()
     * @uses \ITSEC_Modules::set_setting()
     */
    private function wordpress_salts() {

        /**
         * MainWP itsec modules path.
         *
         * @global string $mainwp_itsec_modules_path MainWP itsec modules path.
         * */
        global $mainwp_itsec_modules_path;

        if ( ! class_exists( '\ITSEC_WordPress_Salts_Utilities' ) ) {
            require_once $mainwp_itsec_modules_path . 'salts/utilities.php'; // NOSONAR - WP compatible.
        }
        $result    = \ITSEC_WordPress_Salts_Utilities::generate_new_salts();
        $str_error = '';
        if ( is_wp_error( $result ) ) {
            $errors = \ITSEC_Response::get_error_strings( $result );

            foreach ( $errors as $error ) {
                $str_error .= $error . '<br />';
            }
        } else {
            $return['result']  = 'success';
            $return['message'] = esc_html__( 'The WordPress salts were successfully regenerated.', 'mainwp-child' );
            $last_generated    = \ITSEC_Core::get_current_time_gmt();
            \ITSEC_Modules::set_setting( 'wordpress-salts', 'last_generated', $last_generated );
        }
        if ( ! empty( $str_error ) ) {
            $return['error'] = $str_error;
        }
        return $return;
    }

    /**
     * Update file permissions.
     *
     * @return array Return results table html.
     *
     * @uses \ITSEC_Core::get_wp_upload_dir()
     * @uses \ITSEC_Lib_Config_File::get_wp_config_file_path()
     * @uses \ITSEC_Lib_Config_File::get_server_config_file_path()
     */
    private function file_permissions() {

            require_once \ITSEC_Core::get_core_dir() . '/lib/class-itsec-lib-config-file.php'; // NOSONAR - WP compatible.

            $wp_upload_dir = \ITSEC_Core::get_wp_upload_dir();

            $path_data = array(
                array(
                    ABSPATH,
                    0755,
                ),
                array(
                    ABSPATH . WPINC,
                    0755,
                ),
                array(
                    ABSPATH . 'wp-admin',
                    0755,
                ),
                array(
                    ABSPATH . 'wp-admin/js',
                    0755,
                ),
                array(
                    WP_CONTENT_DIR,
                    0755,
                ),
                array(
                    get_theme_root(),
                    0755,
                ),
                array(
                    WP_PLUGIN_DIR,
                    0755,
                ),
                array(
                    $wp_upload_dir['basedir'],
                    0755,
                ),
                array(
                    \ITSEC_Lib_Config_File::get_wp_config_file_path(),
                    0444,
                ),
                array(
                    \ITSEC_Lib_Config_File::get_server_config_file_path(),
                    0444,
                ),
            );

            $rows = array();

            foreach ( $path_data as $path ) {
                $row = array();

                list( $path, $suggested_permissions ) = $path;

                $display_path = preg_replace( '/^' . preg_quote( ABSPATH, '/' ) . '/', '', $path );
                $display_path = ltrim( $display_path, '/' );

                if ( empty( $display_path ) ) {
                    $display_path = '/';
                }

                $row[] = $display_path;
                $row[] = sprintf( '%o', $suggested_permissions );

                $permissions = fileperms( $path ) & 0777;
                $row[]       = sprintf( '%o', $permissions );

                if ( ! $permissions || $permissions != $suggested_permissions ) { //phpcs:ignore -- compatible.
                    $row[] = esc_html__( 'WARNING', 'mainwp-child' );
                    $row[] = '<div style="background-color: #FEFF7F; border: 1px solid #E2E2E2;">&nbsp;&nbsp;&nbsp;</div>';
                } else {
                    $row[] = esc_html__( 'OK', 'mainwp-child' );
                    $row[] = '<div style="background-color: #22EE5B; border: 1px solid #E2E2E2;">&nbsp;&nbsp;&nbsp;</div>';
                }

                $rows[] = $row;
            }

            $class = 'entry-row';
            ob_start();
            ?>
        <p><input type="button" id="itsec-file-permissions-reload_file_permissions" name="file-permissions[reload_file_permissions]" class="button-primary itsec-reload-module" value="<?php esc_attr_e( 'Reload File Permissions Details', 'mainwp-child' ); ?>"></p>
        <table class="widefat">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e( 'Relative Path', 'mainwp-child' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Suggestion', 'mainwp-child' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Value', 'mainwp-child' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Result', 'mainwp-child' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Status', 'mainwp-child' ); ?></th>
                </tr>
            </thead>
            <tfoot>
                <tr>
                    <th scope="col"><?php esc_html_e( 'Relative Path', 'mainwp-child' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Suggestion', 'mainwp-child' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Value', 'mainwp-child' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Result', 'mainwp-child' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Status', 'mainwp-child' ); ?></th>
                </tr>
            </tfoot>
            <tbody>
                <?php foreach ( $rows as $row ) : //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <tr class="<?php echo $class; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>">
                        <?php foreach ( $row as $column ) : ?>
                            <td><?php echo $column; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <?php $class = ( 'entry-row' === $class ) ? 'entry-row alternate' : 'entry-row'; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
        <br />
        <?php
        $html = ob_get_clean();
        return array( 'html' => $html );
    }

    /**
     * Run File Change scanner.
     *
     * @return array $return results array.
     *
     * @uses \ITSEC_File_Change_Scanner::run_scan()
     */
    public function file_change() {

        /**
         * Itsec modules path.
         *
         * @global string $mainwp_itsec_modules_path MainWP itsec modules path.
         * */
        global $mainwp_itsec_modules_path;

        if ( ! class_exists( '\ITSEC_File_Change_Scanner' ) ) {
            require_once $mainwp_itsec_modules_path . 'file-change/scanner.php'; // NOSONAR - WP compatible.
        }

        $results = \ITSEC_File_Change_Scanner::schedule_start();

        if ( is_wp_error( $results ) ) {
            $error                = $results->get_error_message();
            $return['result']     = 'failed';
            $return['scan_error'] = $error;
        } else {
            $return['result']      = 'success';
            $return['scan_result'] = $results;
        }
        return $return;
    }

    /**
     * Update admin user.
     *
     * @return array Return Success or Fail.
     *
     * @uses \ITSEC_Lib::user_id_exists()
     * @uses MainWP_Child_IThemes_Security::change_admin_user()
     */
    public function admin_user() { //phpcs:ignore -- NOSONAR - complex.

        $settings = isset( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        if ( ! is_array( $settings ) ) {
            $settings = array();
        }

        $new_username = isset( $settings['new_username'] ) ? $settings['new_username'] : '';
        $change_id    = isset( $settings['change_id'] ) && $settings['change_id'] ? true : false;

        if ( ! class_exists( '\ITSEC_Lib' ) ) {

            /**
             * ITsec globals.
             *
             * @global object $itsec_globals ITsec globals.
             * */
            global $itsec_globals;

            require_once \ITSEC_Core::get_core_dir() . '/core/class-itsec-lib.php'; // NOSONAR - WP compatible.
        }

        $username_exists = username_exists( 'admin' );
        $user_id_exists  = \ITSEC_Lib::user_id_exists( 1 );
        $msg             = '';
        if ( strlen( $new_username ) >= 1 ) {

            /**
             * Current user global variable.
             *
             * @global string $current_user Current user global variable.
             * */
            global $current_user;

            if ( ! $username_exists ) {
                $msg = esc_html__( 'Admin user already changes.', 'mainwp-child' );
            } elseif ( 'admin' === $current_user->user_login ) {
                $return['result'] = 'CHILD_ADMIN';
                return $return;
            }
        }

        if ( true === $change_id && ! $user_id_exists ) {
            if ( ! empty( $msg ) ) {
                $msg .= '<br/>';
            }
            $msg .= esc_html__( 'Admin user ID already changes.', 'mainwp-child' );
        }

        $admin_success = true;
        $return        = array();

        if ( strlen( $new_username ) >= 1 && $username_exists ) {
            $admin_success = $this->change_admin_user( $new_username, $change_id );
        } elseif ( true === $change_id && $user_id_exists ) {
            $admin_success = $this->change_admin_user( null, $change_id );
        }

        $return['message'] = $msg;
        if ( false === $admin_success ) {
            $return['result'] = 'fail';
        } else {
            $return['result'] = 'success';
        }
        return $return;
    }

    /**
     * Change admin user.
     *
     * @param string $username Username to update to. Default: null.
     * @param bool   $id User Id found. Default: false.
     * @return bool Return TRUE on success and FALSE on failure.
     *
     * @uses \ITSEC_Core::get_itsec_files()
     * @uses \ITSEC_Core::get_itsec_files::release_file_lock()
     */
    private function change_admin_user( $username = null, $id = false ) { //phpcs:ignore -- NOSONAR - 3rd compatible multi return.
        //phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
        /**
         * WordPress Database.
         *
         * @global object $wpdb WordPress Database
         * */
        global $wpdb;

        $itsec_files = \ITSEC_Core::get_itsec_files();
        $new_user    = sanitize_text_field( $username );
        $user_object = get_user_by( 'id', '1' );

        if ( null !== $username && validate_username( $new_user ) && false === username_exists( $new_user ) ) {
            if ( true === $id ) {
                $user_login = $new_user;
            } else {
                $wpdb->query( 'UPDATE `' . $wpdb->users . "` SET user_login = '" . esc_sql( $new_user ) . "' WHERE user_login='admin';" );
                if ( is_multisite() ) {
                    $oldAdmins = $wpdb->get_var( 'SELECT meta_value FROM `' . $wpdb->sitemeta . "` WHERE meta_key = 'site_admins'" );
                    $newAdmins = str_replace( '5:"admin"', strlen( $new_user ) . ':"' . esc_sql( $new_user ) . '"', $oldAdmins );
                    $wpdb->query( $wpdb->prepare( 'UPDATE `' . $wpdb->sitemeta . "` SET meta_value = %s WHERE meta_key = 'site_admins'", $newAdmins ) );
                }
                wp_clear_auth_cookie();
                $itsec_files->release_file_lock( 'admin_user' );
                return true;
            }
        } elseif ( null !== $username ) {
            $itsec_files->release_file_lock( 'admin_user' );
            return false;
        } else {
            $user_login = $user_object->user_login;
        }
        if ( true === $id ) {
            $wpdb->query( 'DELETE FROM `' . $wpdb->users . '` WHERE ID = 1;' );
            $wpdb->insert(
                $wpdb->users,
                array(
                    'user_login'          => $user_login,
                    'user_pass'           => $user_object->user_pass,
                    'user_nicename'       => $user_object->user_nicename,
                    'user_email'          => $user_object->user_email,
                    'user_url'            => $user_object->user_url,
                    'user_registered'     => $user_object->user_registered,
                    'user_activation_key' => $user_object->user_activation_key,
                    'user_status'         => $user_object->user_status,
                    'display_name'        => $user_object->display_name,
                )
            );
            if ( is_multisite() && null !== $username && validate_username( $new_user ) ) {
                $oldAdmins = $wpdb->get_var( 'SELECT meta_value FROM `' . $wpdb->sitemeta . "` WHERE meta_key = 'site_admins'" );
                $newAdmins = str_replace( '5:"admin"', strlen( $new_user ) . ':"' . esc_sql( $new_user ) . '"', $oldAdmins );
                $wpdb->query( 'UPDATE `' . $wpdb->sitemeta . "` SET meta_value = '" . esc_sql( $newAdmins ) . "' WHERE meta_key = 'site_admins'" );
            }
            $new_user = $wpdb->insert_id;
            $wpdb->query( $wpdb->prepare( 'UPDATE `' . $wpdb->posts . '` SET post_author = %s WHERE post_author = 1;', $new_user ) );
            $wpdb->query( $wpdb->prepare( 'UPDATE `' . $wpdb->usermeta . '` SET user_id = %s WHERE user_id = 1;', $new_user ) );
            $wpdb->query( $wpdb->prepare( 'UPDATE `' . $wpdb->comments . '` SET user_id = %s WHERE user_id = 1;', $new_user ) );
            $wpdb->query( $wpdb->prepare( 'UPDATE `' . $wpdb->links . '` SET link_owner = %s WHERE link_owner = 1;', $new_user ) );
            wp_clear_auth_cookie();
            $itsec_files->release_file_lock( 'admin_user' );
            return true;
        }
        //phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
        return false;
    }

    /**
     * Build WP_config rules.
     *
     * @param array $rules_array Config rules array.
     * @param null  $input New directory input.
     *
     * @return array Return $rules_array.
     */
    public function build_wpconfig_rules( $rules_array, $input = null ) {
        if ( null === $input ) {
            return $rules_array;
        }

        $new_dir = trailingslashit( ABSPATH ) . $input;

        $rules[] = array(
            'type'        => 'add',
            'search_text' => '//Do not delete these. Doing so WILL break your site.',
            'rule'        => '//Do not delete these. Doing so WILL break your site.',
        );

        $rules[] = array(
            'type'        => 'add',
            'search_text' => 'WP_CONTENT_URL',
            'rule'        => "define( 'WP_CONTENT_URL', '" . trailingslashit( get_option( 'siteurl' ) ) . $input . "' );",
        );

        $rules[] = array(
            'type'        => 'add',
            'search_text' => 'WP_CONTENT_DIR',
            'rule'        => "define( 'WP_CONTENT_DIR', '" . $new_dir . "' );",
        );

        $rules_array[] = array(
            'type'  => 'wpconfig',
            'name'  => 'Content Directory',
            'rules' => $rules,
        );

        return $rules_array;
    }


    /**
     * Change database prefix.
     *
     * @return array $return Return response array.
     *
     * @uses \ITSEC_Database_Prefix_Utility::change_database_prefix()
     * @uses \ITSEC_Response::get_error_strings()
     * @uses \ITSEC_Response::reload_module()
     */
    public function change_database_prefix() {

        /**
         * ITsec modules path.
         *
         * @global string $mainwp_itsec_modules_path MainWP itsec modules path.
         * */
        global $mainwp_itsec_modules_path;

        require_once $mainwp_itsec_modules_path . 'database-prefix/utility.php'; // NOSONAR - WP compatible.
        $str_error = '';
        $return    = array();

        if ( isset( $_POST['change_prefix'] ) && 'yes' === $_POST['change_prefix'] ) { // phpcs:ignore WordPress.Security.NonceVerification
            $result = \ITSEC_Database_Prefix_Utility::change_database_prefix();
            $return = $result['errors'];
            if ( is_array( $result['errors'] ) ) {
                foreach ( $result['errors'] as $error ) {
                    $arr_errors = \ITSEC_Response::get_error_strings( $error );
                    foreach ( $arr_errors as $er ) {
                        $str_error .= $er . '<br />';
                    }
                }
            }

            \ITSEC_Response::reload_module( 'database-prefix' );

            if ( false === $result['new_prefix'] ) {
                $return['error'] = $str_error;
            } else {
                $return['result']  = 'success';
                $return['message'] = sprintf( esc_html__( 'The database table prefix was successfully changed to <code>%1$s</code>.', 'mainwp-child' ), $result['new_prefix'] );

            }
        }
        return $return;
    }

    /**
     * Update API key.
     *
     * @return array $return Return response array. Success or nochange.
     */
    public function api_key() {
        $settings = get_site_option( 'itsec_ipcheck' );
        if ( ! is_array( $settings ) ) {
            $settings = array();
        }
        $settings['reset'] = true;
        $return            = array();
        if ( update_site_option( 'itsec_ipcheck', $settings ) ) {
            $return['result'] = 'success';
        } else {
            $return['result'] = 'nochange';
        }

        return $return;
    }

    /**
     * Reset api key.
     *
     * @return array $information Return response array.
     *
     * @uses \ITSEC_Modules::get_defaults()
     * @uses \ITSEC_Modules::set_defaults()
     * @uses \ITSEC_Response::set_response()
     * @uses \ITSEC_Response::add_errors()
     * @uses \ITSEC_Response::add_messages()
     */
    public function reset_api_key() {

        $defaults = \ITSEC_Modules::get_defaults( 'network-brute-force' );
        $results  = \ITSEC_Modules::set_settings( 'network-brute-force', $defaults );

        \ITSEC_Response::set_response( $results['saved'] );
        \ITSEC_Response::add_errors( $results['errors'] );
        \ITSEC_Response::add_messages( $results['messages'] );

        $information = array();
        if ( $results['saved'] ) {
            $information['result']       = 'success';
            $information['nbf_settings'] = \ITSEC_Modules::get_settings( 'network-brute-force' );
        } elseif ( empty( $results['errors'] ) ) {
            $information['error_reset_api'] = 1;
        }
        return $information;
    }

    /**
     * Malware scan.
     *
     * @return array $response Return response array.
     *
     * @uses \ITSEC_Core::current_user_can_manage()
     * @uses \ITSEC_Malware_Scanner::scan()
     * @uses \ITSEC_Malware_Scan_Results_Template::get_html()
     */
    public function malware_scan() {

        /**
         * Itsec modules path.
         *
         * @global string $mainwp_itsec_modules_path MainWP itsec modules path.
         * */
        global $mainwp_itsec_modules_path;

        if ( ! class_exists( '\ITSEC_Malware_Scanner' ) ) {
            require_once $mainwp_itsec_modules_path . 'malware/class-itsec-malware-scanner.php'; // NOSONAR - WP compatible.
            require_once $mainwp_itsec_modules_path . 'malware/class-itsec-malware-scan-results-template.php'; // NOSONAR - WP compatible.
        }

        $response = array();
        if ( ! \ITSEC_Core::current_user_can_manage() ) {
            $response['error'] = 'The currently logged in user does not have sufficient permissions to run this scan.';
        } else {
            $results          = \ITSEC_Malware_Scanner::scan();
            $response['html'] = \ITSEC_Malware_Scan_Results_Template::get_html( $results, true );
        }

        return $response;
    }

    /**
     * Get malware scan results.
     *
     * @return array $response Return response array.
     *
     * @uses \ITSEC_Malware_Scanner::scan()
     * @uses \ITSEC_Malware_Scan_Results_Template::get_html()
     */
    public function malware_get_scan_results() {

        /**
         * Itsec modules path.
         *
         * @global string $mainwp_itsec_modules_path MainWP itsec modules path.
         * */
        global $mainwp_itsec_modules_path;

        if ( ! class_exists( '\ITSEC_Malware_Scanner' ) ) {
            require_once $mainwp_itsec_modules_path . 'malware/class-itsec-malware-scanner.php'; // NOSONAR - WP compatible.
            require_once $mainwp_itsec_modules_path . 'malware/class-itsec-malware-scan-results-template.php'; // NOSONAR - WP compatible.
        }
        $response         = array();
        $results          = \ITSEC_Malware_Scanner::scan();
        $response['html'] = \ITSEC_Malware_Scan_Results_Template::get_html( $results, true );
        return $response;
    }

    /**
     * Purge logs.
     *
     * @return string[] Return response array.
     */
    public function purge_logs() {

        /**
         * Database object.
         *
         * @global object $wpdb WordPress Database object.
         * */
        global $wpdb;

        $wpdb->query( 'DELETE FROM `' . $wpdb->base_prefix . 'itsec_log`;' );

        return array( 'result' => 'success' );
    }


    /**
     * Get lockouts.
     *
     * @param string $type Type of lockout: Host, user, username, Default: all.
     * @param bool   $current TRUE if current. Default: FALSE.
     *
     * @return array $output Return response array.
     *
     * @uses MainWP_Child_IThemes_Security::get_lockouts_int()
     */
    public function get_lockouts( $type = 'all', $current = false ) {

        /**
         * Database object.
         *
         * @global object $wpdb WordPress Database object.
         * @global object $itsec_globals itsec globals.
         */
        global $wpdb, $itsec_globals;

        if ( 'all' !== $type || true === $current ) {
            $where = ' WHERE ';
        } else {
            $where = '';
        }

        switch ( $type ) {

            case 'host':
                $type_statement = "`lockout_host` IS NOT NULL && `lockout_host` != ''";
                break;
            case 'user':
                $type_statement = '`lockout_user` != 0';
                break;
            case 'username':
                $type_statement = "`lockout_username` IS NOT NULL && `lockout_username` != ''";
                break;
            default:
                $type_statement = '';
                break;

        }

        if ( true === $current ) {

            if ( '' !== $type_statement ) {
                $and = ' AND ';
            } else {
                $and = '';
            }

            $active = $and . " `lockout_active`=1 AND `lockout_expire_gmt` > '" . gmdate( 'Y-m-d H:i:s', $itsec_globals['current_time_gmt'] ) . "'";

        } else {

            $active = '';

        }
        $results = $wpdb->get_results( 'SELECT * FROM `' . $wpdb->base_prefix . 'itsec_lockouts`' . $where . $type_statement . $active . ';', ARRAY_A ); // phpcs:ignore -- safe query. $output  = array();

        return $this->get_lockouts_int( $results, $type );
    }

    /**
     * Initiate get lockouts.
     *
     * @param array  $results Results from MainWP_Child_IThemes_Security::get_lockouts().
     * @param string $type Type of lockout: Host, user, username, Default: all.
     *
     * @return array $output Return response array.
     */
    private function get_lockouts_int( $results, $type ) {

        if ( is_array( $results ) && count( $results ) > 0 ) {
            switch ( $type ) {
                case 'host':
                    foreach ( $results as $val ) {
                        $output[] = array(
                            'lockout_id'         => $val['lockout_id'],
                            'lockout_host'       => $val['lockout_host'],
                            'lockout_expire_gmt' => $val['lockout_expire_gmt'],
                        );
                    }
                    break;
                case 'user':
                    foreach ( $results as $val ) {
                        $output[] = array(
                            'lockout_id'         => $val['lockout_id'],
                            'lockout_user'       => $val['lockout_user'],
                            'lockout_expire_gmt' => $val['lockout_expire_gmt'],
                        );
                    }
                    break;
                case 'username':
                    foreach ( $results as $val ) {
                        $output[] = array(
                            'lockout_id'         => $val['lockout_id'],
                            'lockout_username'   => $val['lockout_username'],
                            'lockout_expire_gmt' => $val['lockout_expire_gmt'],
                        );
                    }
                    break;
                default:
                    break;
            }
        }

        return $output;
    }

    /**
     * Release lockout.
     *
     * @return string[] Return results array.
     *
     * @uses \ITSEC_Lib::clear_caches()
     */
    public function release_lockout() {

        /**
         * WordPress Database.
         *
         * @global object $wpdb WordPress Database.
         * */
        global $wpdb;

        if ( ! class_exists( '\ITSEC_Lib' ) ) {
            require_once \ITSEC_Core::get_core_dir() . '/core/class-itsec-lib.php'; // NOSONAR - WP compatible.
        }

        $lockout_ids = isset( $_POST['lockout_ids'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['lockout_ids'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification
        if ( ! is_array( $lockout_ids ) ) {
            $lockout_ids = array();
        }

        $type    = 'updated';
        $message = esc_html__( 'The selected lockouts have been cleared.', 'mainwp-child' );

        foreach ( $lockout_ids as $value ) {
            $wpdb->update(
                $wpdb->base_prefix . 'itsec_lockouts',
                array(
                    'lockout_active' => 0,
                ),
                array(
                    'lockout_id' => intval( $value ),
                )
            );
        }

        \ITSEC_Lib::clear_caches();

        if ( ! is_multisite() ) {
            if ( ! function_exists( 'add_settings_error' ) ) {
                require_once ABSPATH . '/wp-admin/includes/template.php'; // NOSONAR - WP compatible.
            }

            add_settings_error( 'itsec', esc_attr( 'settings_updated' ), $message, $type );
        }

        return array(
            'result' => 'success',
        );
    }

    /**
     * Update module status.
     *
     * @return string[] Return response array.
     */
    public function update_module_status() {

        $active_modules = isset( $_POST['active_modules'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['active_modules'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification

        if ( ! is_array( $active_modules ) ) {
            $active_modules = array();
        }

        $current_val = get_site_option( 'itsec_active_modules', array() );
        foreach ( $active_modules as $mod => $val ) {
            $current_val[ $mod ] = $val;
        }

        update_site_option( 'itsec_active_modules', $current_val );
        return array( 'result' => 'success' );
    }

    /**
     * Reload excluded backups table.
     *
     * @return array Return response array.
     *
     * @uses \ITSEC_Modules::get_setting()
     * @uses MainWP_Child_IThemes_Security::get_excludable_tables()
     */
    private function reload_backup_exclude() {
        return array(
            'exclude'           => \ITSEC_Modules::get_setting( 'backup', 'exclude' ),
            'excludable_tables' => $this->get_excludable_tables(),
            'result'            => 'success',
        );
    }

    /**
     * Get excludable backups table.
     *
     * @return array $excludes Return response array.
     *
     * @uses \ITSEC_Modules::get_setting()
     */
    private function get_excludable_tables() {

        /**
         * WordPress Database.
         *
         * @global object $wpdb WordPress Database.
         * */
        global $wpdb;

        $all_sites      = \ITSEC_Modules::get_setting( 'backup', 'all_sites' );
        $ignored_tables = array(
            'commentmeta',
            'comments',
            'links',
            'options',
            'postmeta',
            'posts',
            'term_relationships',
            'term_taxonomy',
            'terms',
            'usermeta',
            'users',
        );

        if ( $all_sites ) {
            $query = 'SHOW TABLES';
        } else {
            $query = $wpdb->prepare( 'SHOW TABLES LIKE %s', "{$wpdb->base_prefix}%" );
        }

        $tables   = $wpdb->get_results( $query, ARRAY_N ); // phpcs:ignore -- safe query.
        $excludes = array();

        foreach ( $tables as $table ) {
            $short_table = substr( $table[0], strlen( $wpdb->prefix ) );

            if ( in_array( $short_table, $ignored_tables ) ) {
                continue;
            }

            $excludes[ $short_table ] = $table[0];
        }

        return $excludes;
    }

    /**
     * Get security check results.
     *
     * @return array Return response array.
     *
     * @uses \ITSEC_Security_Check_Scanner::get_results()
     * @uses \ITSEC_Security_Check_Feedback_Renderer::render(
     */
    private function security_site() {

        /**
         * MainWP itsec modules path.
         *
         * @global string $mainwp_itsec_modules_path MainWP itsec modules path.
         * */
        global $mainwp_itsec_modules_path;

        require_once $mainwp_itsec_modules_path . 'security-check/scanner.php'; // NOSONAR - WP compatible.
        require_once $mainwp_itsec_modules_path . 'security-check/feedback-renderer.php'; // NOSONAR - WP compatible.
        $results = \ITSEC_Security_Check_Scanner::get_results();
        ob_start();
        \ITSEC_Security_Check_Feedback_Renderer::render( $results );
        $response = ob_get_clean();
        return array(
            'result'   => 'success',
            'response' => $response,
        );
    }

    /**
     * Get available admin users and roles.
     *
     * @return array[] Return response array.phpdoc
     *
     * @uses \WP_Roles()
     */
    public function get_available_admin_users_and_roles() {
        if ( is_callable( 'wp_roles' ) ) {
            $roles = wp_roles();
        } else {
            $roles = new \WP_Roles();
        }

        $available_roles = array();
        $available_users = array();

        foreach ( $roles->roles as $role => $details ) {
            if ( isset( $details['capabilities']['manage_options'] ) && ( true === $details['capabilities']['manage_options'] ) ) {
                $available_roles[ "role:$role" ] = translate_user_role( $details['name'] );

                $users = get_users( array( 'role' => $role ) );

                foreach ( $users as $user ) {
                    /* translators: 1: user display name, 2: user login */
                    $available_users[ $user->ID ] = sprintf( esc_html__( '%1$s (%2$s)', 'mainwp-child' ), $user->display_name, $user->user_login );
                }
            }
        }

        natcasesort( $available_users );

        return array(
            'users' => $available_users,
            'roles' => $available_roles,
        );
    }
}
