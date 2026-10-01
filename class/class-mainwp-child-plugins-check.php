<?php
/**
 * MainWP Abandoned Plugin Check
 *
 * This file checks if pugins have been abandoned.
 *
 * @package MainWP\Child
 *
 * @Credits
 *
 * Plugin-Name: Vendi Abandoned Plugin Check
 * Plugin URI: https://wordpress.org/plugins/vendi-abandoned-plugin-check/
 * Author: Vendi Advertising (Chris Haas)
 * Author URI: https://wp-staging.com
 * License: GPLv2
 */

namespace MainWP\Child;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Child_Plugins_Check
 *
 * Check if plugins have been abandoned.
 */
class MainWP_Child_Plugins_Check {

    /**
     * Cron: Plugin health check watcher.
     *
     * @var string
     */
    private $cron_name_watcher = 'mainwp_child_cron_plugin_health_check_watcher';

    /**
     * Cron: Plugin health check daily.
     *
     * @var string
     */
    private $cron_name_daily = 'mainwp_child_cron_plugin_health_check_daily';

    /**
     * Cron: Plugin health check batching.
     *
     * @var string
     */
    private $cron_name_batching = 'mainwp_child_cron_plugin_health_check_batching';

    /**
     * Transient: Plugin timestamps.
     *
     * @var string
     */
    private $tran_name_plugin_timestamps = 'mainwp_child_tran_name_plugin_timestamps';

    /**
     * Transient: Plugins to batch.
     *
     * @var string
     */
    private $tran_name_plugins_to_batch = 'mainwp_child_tran_name_plugins_to_batch';

    /**
     * Transient: Plugins whose WordPress.org request returned no last_updated.
     *
     * @var string
     */
    private $tran_name_plugins_no_date = 'mainwp_child_tran_name_plugins_no_date';

    /**
     * Transient: Plugin last daily run.
     *
     * @var string
     */
    private $option_name_last_daily_run = 'mainwp_child_plugin_last_daily_run';

    /**
     * Public static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    public static $instance = null;

    /**
     * Method instance()
     *
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
     * MainWP_Child_Plugins_Check constructor.
     *
     * Run any time class is called.
     */
    public function __construct() {
        add_action( $this->cron_name_batching, array( $this, 'run_check' ) );
        add_action( $this->cron_name_daily, array( $this, 'run_check' ) );
        add_action( $this->cron_name_watcher, array( $this, 'perform_watchdog' ) );
        add_filter( 'plugins_api_args', array( $this, 'modify_plugin_api_search_query' ), 10, 2 );
        add_action( 'mainwp_child_deactivation', array( $this, 'cleanup_deactivation' ) );
    }

    /**
     * Un-schedules all events attached to the hook with the specified arguments.
     * On success an integer indicating number of events un-scheduled (0 indicates no events were registered with the hook and arguments combination),
     * false if un-scheduling one or more events fail.
     */
    private function cleanup_basic() {
        wp_clear_scheduled_hook( $this->cron_name_daily );
        wp_clear_scheduled_hook( $this->cron_name_batching );
        delete_transient( $this->tran_name_plugins_to_batch );
    }

    /**
     * Un-schedules all events attached to the hook with the specified arguments.
     * On success an integer indicating number of events un-scheduled (0 indicates no events were registered with the hook and arguments combination),
     * false if un-scheduling one or more events fail.
     *
     * @param bool $del Whether or not to delete the transient data. Default: true.
     */
    public function cleanup_deactivation( $del = true ) {
        $this->cleanup_basic();
        wp_clear_scheduled_hook( $this->cron_name_watcher );
        delete_option( $this->option_name_last_daily_run );
        if ( $del ) {
            delete_transient( $this->tran_name_plugin_timestamps );
            delete_transient( $this->tran_name_plugins_no_date );
        }
    }

    /**
     * Modify plugin API Search Query.
     *
     * @param object $args Query arguments.
     * @param string $action Action to perform: query_plugins.
     * @return \stdClass $args Modified Search Query.
     */
    public function modify_plugin_api_search_query( $args, $action ) {
        if ( isset( $action ) && 'query_plugins' === $action ) {

            if ( ! is_object( $args ) ) {
                $args = new \stdClass();
            }

            if ( ! property_exists( $args, 'fields' ) ) {
                $args->fields = array();
            }

            $args->fields = array_merge( $args->fields, array( 'last_updated' => true ) );
        }

        return $args;
    }

    /**
     * Schedule watchdog crons.
     *
     * @throws MainWP_Exception Error message on failure.
     */
    public function perform_watchdog() {

        // If batching is already scheduled, system is healthy.
        if ( wp_next_scheduled( $this->cron_name_batching ) ) {
            return;
        }

        // If batching data exists but no batching event scheduled,
        // restore only the missing single event.
        if ( false !== get_transient( $this->tran_name_plugins_to_batch ) ) {

            if ( ! wp_next_scheduled( $this->cron_name_batching ) ) {
                wp_schedule_single_event(
                    time() + MINUTE_IN_SECONDS,
                    $this->cron_name_batching
                );
            }

            return;
        }

        $last_run = get_option( $this->option_name_last_daily_run );

        if ( ! is_numeric( $last_run ) ) {
            $last_run = 0;
        }

        /*
        * Only restore the daily cron if:
        * - it is missing
        * - AND the last run is stale
        */
        if (
            ( time() - (int) $last_run ) >= DAY_IN_SECONDS
        ) {

            // Reset stale batching state before restoring cron.
            delete_transient( $this->tran_name_plugins_to_batch );

            $daily_scheduled = wp_next_scheduled( $this->cron_name_daily );

            if ( ! $daily_scheduled ) {

                wp_schedule_event(
                    time() + MINUTE_IN_SECONDS,
                    'daily',
                    $this->cron_name_daily
                );
            }

            update_option(
                $this->option_name_last_daily_run,
                time()
            );
        }
    }

    /**
     * Schedule a global watchdog cron just in case both other crons get killed.
     */
    public function schedule_watchdog() {
        if ( ! wp_next_scheduled( $this->cron_name_watcher ) ) {
            wp_schedule_event( time(), 'hourly', $this->cron_name_watcher );
        }
    }

    /**
     * Get plugins outdated info.
     *
     * @return array $plugins_outdate Array of outdated plugin info.
     */
    public function get_plugins_outdate_info() {
        $plugins_outdate = get_transient( $this->tran_name_plugin_timestamps );
        if ( ! is_array( $plugins_outdate ) ) {
            $plugins_outdate = array();
        }
        if ( ! function_exists( '\get_plugins' ) ) {
            require_once ABSPATH . '/wp-admin/includes/plugin.php'; // NOSONAR - WP compatible.
        }
        $plugins = get_plugins();
        $update  = false;
        foreach ( $plugins_outdate as $slug => $v ) {
            if ( ! isset( $plugins[ $slug ] ) ) {
                unset( $plugins_outdate[ $slug ] );
                $update = true;
            }
        }
        if ( $update ) {
            set_transient( $this->tran_name_plugin_timestamps, $plugins_outdate, 2 * DAY_IN_SECONDS );
        }

        if ( 1 === (int) get_option( 'mainwp_child_abandoned_check_by_local_date', 0 ) ) {
            $plugins_outdate = $this->fill_undated_from_file_mtime( $plugins_outdate, $plugins );
        }

        return $plugins_outdate;
    }

    /**
     * Fill abandoned rows for plugins WordPress.org could not date, using the main file's mtime.
     *
     * @param array $plugins_outdate WordPress.org abandoned rows keyed by plugin file.
     * @param array $plugins         Installed plugins from get_plugins().
     *
     * @return array Abandoned rows, with undated items filled from file mtime.
     */
    private function fill_undated_from_file_mtime( $plugins_outdate, $plugins ) {
        $no_date = get_transient( $this->tran_name_plugins_no_date );
        if ( ! is_array( $no_date ) || empty( $no_date ) ) {
            return $plugins_outdate;
        }

        $tolerance_in_days = (int) get_option( 'mainwp_child_plugintheme_days_outdate', 365 );
        $now               = time();

        foreach ( $no_date as $slug => $flag ) {
            if ( ! isset( $plugins[ $slug ] ) || isset( $plugins_outdate[ $slug ] ) ) {
                continue;
            }

            $file = WP_PLUGIN_DIR . '/' . $slug;
            if ( ! is_file( $file ) || ! is_readable( $file ) ) {
                continue;
            }

            $mtime = filemtime( $file );
            if ( false === $mtime ) {
                continue;
            }

            if ( floor( ( $now - $mtime ) / DAY_IN_SECONDS ) < $tolerance_in_days ) {
                continue;
            }

            $plugins_outdate[ $slug ] = array(
                'Name'          => $plugins[ $slug ]['Name'],
                'PluginURI'     => $plugins[ $slug ]['PluginURI'],
                'Version'       => $plugins[ $slug ]['Version'],
                'last_updated'  => $mtime,
                'file_modified' => $mtime,
                'detection'     => 'file_mtime',
            );
        }

        return $plugins_outdate;
    }

    /**
     * Enable or disable the background abandoned-plugin checks for sync.
     *
     * @param bool $enabled Whether sync currently requests abandoned plugin data.
     */
    public function sync_background_state( $enabled ) {
        if ( $enabled ) {
            $this->schedule_watchdog();
            return;
        }

        $this->cleanup_deactivation( false );
    }

    /**
     * Queue a background abandoned-plugin check when sync needs data and the cache is incomplete.
     */
    public function maybe_queue_check() {
        $has_cached_results = false !== get_transient( $this->tran_name_plugin_timestamps );
        $has_pending_batch  = false !== get_transient( $this->tran_name_plugins_to_batch );

        if ( $has_cached_results && ! $has_pending_batch ) {
            return;
        }

        if ( $has_pending_batch || wp_next_scheduled( $this->cron_name_batching ) ) {
            return;
        }

        wp_schedule_single_event( time(), $this->cron_name_batching );
    }

    /**
     * Update Days out of date option.
     *
     * @uses \MainWP\Child\MainWP_Child_Themes_Check::cleanup_deactivation()
     * @uses \MainWP\Child\MainWP_Helper::update_option()
     */
    public static function may_outdate_number_change() {
         // phpcs:disable WordPress.Security.NonceVerification
        if ( isset( $_POST['numberdaysOutdatePluginTheme'] ) ) {
            $saved_days_outdate = get_option( 'mainwp_child_plugintheme_days_outdate', false );
            $days_outdate       = intval( $_POST['numberdaysOutdatePluginTheme'] );
            if ( false === $saved_days_outdate || (int) $saved_days_outdate !== $days_outdate ) {
                MainWP_Helper::update_option( 'mainwp_child_plugintheme_days_outdate', $days_outdate );
                static::instance()->cleanup_deactivation();
                MainWP_Child_Themes_Check::instance()->cleanup_deactivation();
            }
        }
        if ( isset( $_POST['abandonedCheckByLocalDate'] ) ) {
            $local_date_check = ! empty( $_POST['abandonedCheckByLocalDate'] ) ? 1 : 0;
            if ( (int) get_option( 'mainwp_child_abandoned_check_by_local_date', 0 ) !== $local_date_check ) {
                MainWP_Helper::update_option( 'mainwp_child_abandoned_check_by_local_date', $local_date_check );
            }
        }
        // phpcs:enable
    }

    /**
     * Run plugin update check.
     *
     * @throws MainWP_Exception Error message on failure.
     */
    public function run_check() { //phpcs:ignore -- NOSONAR - complex.
        if ( ! function_exists( '\get_plugins' ) ) {
            require_once ABSPATH . '/wp-admin/includes/plugin.php'; // NOSONAR - WP compatible.
        }

        // Get our previous results.
        $responses = get_transient( $this->tran_name_plugin_timestamps );

        if ( false === $responses || ! is_array( $responses ) ) {
            $responses = array();
        }

        // Get our previous cache of plugins for batching.
        $all_plugins = get_transient( $this->tran_name_plugins_to_batch );

        // If there wasn't a previous cache.
        if ( false === $all_plugins || ! is_array( $all_plugins ) ) {
            $all_plugins = array();
            $plugins     = get_plugins();
            if ( is_array( $plugins ) ) {
                foreach ( $plugins as $slug => $plugin ) {
                    if ( isset( $plugin['Name'] ) && ! empty( $plugin['Name'] ) ) {
                        $all_plugins[ $slug ] = array(
                            'Name'      => $plugin['Name'],
                            'PluginURI' => $plugin['PluginURI'],
                            'Version'   => $plugin['Version'],
                        );

                    }
                }
            }
            $responses = array();
        }

        $avoid_plugins = array( 'sitepress-multilingual-cms/sitepress.php' );

        // Grab a small number of plugins to scan.
        $plugins_to_scan   = array_splice( $all_plugins, 0, apply_filters( 'mainwp_child_plugin_health_check_max_plugins_to_batch', 10 ) );
        $tolerance_in_days = get_option( 'mainwp_child_plugintheme_days_outdate', 365 );

        $no_date = get_transient( $this->tran_name_plugins_no_date );
        if ( ! is_array( $no_date ) ) {
            $no_date = array();
        }

        // Loop through each known plugin.
        foreach ( $plugins_to_scan as $slug => $v ) {
            if ( in_array( $slug, $avoid_plugins ) ) {
                continue;
            }
            // Try to get the raw information for this plugin.
            $body = $this->try_get_response_body( $slug, false );

            // We couldn't reach WordPress.org, skip this plugin.
            if ( false === $body ) {
                continue;
            }

            // Deserialize the response.
            $obj = '' === $body ? false : maybe_unserialize( $body );

            // WordPress.org answered but could not date this plugin (premium or unpublished).
            if ( ! is_object( $obj ) || ! property_exists( $obj, 'last_updated' ) ) {
                $no_date[ $slug ] = 1;
                continue;
            }

            unset( $no_date[ $slug ] );

            if ( version_compare( $v['Version'], $obj->version, '>' ) ) {
                continue;
            }

            $now                      = new \DateTime();
            $last_updated             = strtotime( $obj->last_updated );
            $plugin_last_updated_date = new \DateTime( '@' . $last_updated );

            $diff_in_days = $now->diff( $plugin_last_updated_date )->format( '%a' );

            if ( $diff_in_days < $tolerance_in_days ) {
                continue;
            }
            $v['last_updated']  = $last_updated;
            $responses[ $slug ] = $v;
        }

        // Store the master response for usage in the plugin table.
        set_transient( $this->tran_name_plugin_timestamps, $responses, 2 * DAY_IN_SECONDS );
        set_transient( $this->tran_name_plugins_no_date, $no_date, 2 * DAY_IN_SECONDS );

        if ( empty( $all_plugins ) ) {
            delete_transient( $this->tran_name_plugins_to_batch );
        } else {
            set_transient( $this->tran_name_plugins_to_batch, $all_plugins, 2 * DAY_IN_SECONDS );
            wp_schedule_single_event( time(), $this->cron_name_batching );
        }
    }

    /**
     * Try to get response body.
     *
     * @param string $plugin Plugin slug.
     * @param bool   $second_pass Second pass check.
     *
     * @return bool|string The body of the response. Empty string when WordPress.org answered without plugin data (not found, empty or N;). False when no request got an answer.
     */
    private function try_get_response_body( $plugin, $second_pass ) { //phpcs:ignore -- NOSONAR - complex.
        $answered = false;

        // Get the WordPress current version to be polite in the API call.
        // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Using static access for centralized version retrieval
        $wp_ver = MainWP_Child_Server_Information_Base::get_wordpress_version();

        // General options to be passed to wp_remote_get.
        $options = array(
            'timeout'    => 60 * 60,
            'user-agent' => 'WordPress/' . $wp_ver . '; ' . get_bloginfo( 'url' ),
        );

        // The URL for the endpoint.
        $url      = 'http://api.wordpress.org/plugins/info/1.0/';
        $http_url = 'http://api.wordpress.org/plugins/info/1.0/';

        $ssl = wp_http_supports( array( 'ssl' ) );
        if ( $ssl ) {
            $url = set_url_scheme( $url, 'https' );
        }

        $plugin_dir = $plugin;
        if ( strpos( $plugin, '/' ) !== false ) {
            $plugin_dir = dirname( $plugin );
        }

        // Try to get the response (usually the SSL version).
        $raw_response = wp_remote_get( $url . $plugin_dir, $options );

        $body = $this->read_response_body( $raw_response, $answered );
        if ( false !== $body ) {
            return $body;
        }

        // The above valid!
        // If we previously tried an SSL version try without SSL.
        if ( $ssl ) {
            $raw_response = wp_remote_get( $http_url . $plugin, $options );
            $body         = $this->read_response_body( $raw_response, $answered );
            if ( false !== $body ) {
                return $body;
            }
        }

        // The above failed!
        // If we're on a second pass already then there's nothing left to do but bail.
        if ( true === $second_pass ) {
            return $answered ? '' : false;
        }

        // We're still on the first pass, try to get just the name of the directory of the plugin.
        $parts = explode( '/', $plugin );

        // Sanity check that we have two parts, a directory and a file name.
        if ( 2 === count( $parts ) ) {
            // Try this entire function using just the directory name.
            $body = $this->try_get_response_body( $parts[0], true );
            if ( false !== $body ) {
                return $body;
            }
        }

        // Everything above failed, bail!
        return $answered ? '' : false;
    }

    /**
     * Read a plugin info response body.
     *
     * A 404 or an empty 200 means WordPress.org has no data for the slug, which differs from a failed request.
     *
     * @param array|\WP_Error $raw_response Response from wp_remote_get().
     * @param bool            $answered     Set to true when WordPress.org answered without plugin data.
     *
     * @return string|false The body, or false when there is no usable body.
     */
    private function read_response_body( $raw_response, &$answered ) {
        if ( is_wp_error( $raw_response ) ) {
            return false;
        }

        $code = (int) wp_remote_retrieve_response_code( $raw_response );
        if ( 404 === $code ) {
            $answered = true;
            return false;
        }

        if ( 200 !== $code ) {
            return false;
        }

        $body = wp_remote_retrieve_body( $raw_response );

        // Make sure that it isn't empty and also not an empty serialized object.
        if ( '' !== $body && 'N;' !== $body ) {
            return $body;
        }

        $answered = true;
        return false;
    }
}
