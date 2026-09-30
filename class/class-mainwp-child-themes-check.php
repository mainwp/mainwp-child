<?php
/**
 * MainWP Abandoned Themes Check
 *
 * This file checks if themes have been abandoned.
 *
 * @package MainWP\Child
 *
 * Credits
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
 * Class MainWP_Child_Themes_Check
 *
 * Check if themes have been abandoned.
 */
class MainWP_Child_Themes_Check {

    /**
     * Cron: Theme health check watcher.
     *
     * @var string
     */
    private $cron_name_watcher = 'mainwp_child_cron_theme_health_check_watcher';

    /**
     * Cron: Theme health check daily.
     *
     * @var string
     */
    private $cron_name_daily = 'mainwp_child_cron_theme_health_check_daily';

    /**
     * Cron: Theme health check batching.
     *
     * @var string
     */
    private $cron_name_batching = 'mainwp_child_cron_theme_health_check_batching';

    /**
     * Transient: Theme timestamps.
     *
     * @var string
     */
    private $tran_name_theme_timestamps = 'mainwp_child_tran_name_theme_timestamps';

    /**
     * Transient: Themes to batch.
     *
     * @var string
     */
    private $tran_name_themes_to_batch = 'mainwp_child_tran_name_themes_to_batch';

    /**
     * Transient: Themes whose WordPress.org request returned no last_updated.
     *
     * @var string
     */
    private $tran_name_themes_no_date = 'mainwp_child_tran_name_themes_no_date';

    /**
     * Transient: Theme last daily run.
     *
     * @var string
     */
    private $option_name_last_daily_run = 'mainwp_child_theme_last_daily_run';

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
     * MainWP_Child_Themes_Check constructor.
     *
     * Run any time class is called.
     */
    public function __construct() {
        add_action( $this->cron_name_batching, array( $this, 'run_check' ) );
        add_action( $this->cron_name_daily, array( $this, 'run_check' ) );
        add_action( $this->cron_name_watcher, array( $this, 'perform_watchdog' ) );
        add_filter( 'themes_api_args', array( $this, 'modify_theme_api_search_query' ), 10, 2 );
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
        delete_transient( $this->tran_name_themes_to_batch );
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
            delete_transient( $this->tran_name_theme_timestamps );
            delete_transient( $this->tran_name_themes_no_date );
        }
    }

    /**
     * Modify theme api search query.
     *
     * @param object $args Query arguments.
     * @param string $action Actions to perform.
     * @return \stdClass Return instance of \stdClass.
     */
    public function modify_theme_api_search_query( $args, $action ) {
        if ( isset( $action ) && 'query_themes' === $action ) {
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

        // If batching data exists but batching event is missing,
        // restore only the missing single event.
        if ( false !== get_transient( $this->tran_name_themes_to_batch ) ) {

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
            delete_transient( $this->tran_name_themes_to_batch );

            $timestamp = time() + MINUTE_IN_SECONDS;

            $daily_scheduled = wp_next_scheduled( $this->cron_name_daily );

            if ( ! $daily_scheduled ) {
                wp_schedule_event(
                    $timestamp,
                    'daily',
                    $this->cron_name_daily
                );
            }

            update_option(
                $this->option_name_last_daily_run,
                $timestamp
            );
        }
    }

    /**
     * Schedule a global watching cron just in case both other crons get killed.
     */
    public function schedule_watchdog() {
        if ( ! wp_next_scheduled( $this->cron_name_watcher ) ) {
            wp_schedule_event( time(), 'hourly', $this->cron_name_watcher );
        }
    }

    /**
     * Get how long themes have been outdated.
     *
     * @return array $themes_outdate Array of themes & how long they have been outdated.
     */
    public function get_themes_outdate_info() {
        $themes_outdate = get_transient( $this->tran_name_theme_timestamps );
        if ( ! is_array( $themes_outdate ) ) {
            $themes_outdate = array();
        }
        if ( ! function_exists( '\wp_get_themes' ) ) {
            require_once ABSPATH . '/wp-admin/includes/theme.php'; // NOSONAR - WP compatible.
        }
        $themes = \wp_get_themes();
        $update = false;
        foreach ( $themes_outdate as $slug => $v ) {
            if ( ! isset( $themes[ $slug ] ) ) {
                unset( $themes_outdate[ $slug ] );
                $update = true;
            }
        }
        if ( $update ) {
            set_transient( $this->tran_name_theme_timestamps, $themes_outdate, 2 * DAY_IN_SECONDS );
        }

        if ( 1 === (int) get_option( 'mainwp_child_abandoned_check_by_local_date', 0 ) ) {
            $themes_outdate = $this->fill_undated_from_file_mtime( $themes_outdate, $themes );
        }

        return $themes_outdate;
    }

    /**
     * Fill abandoned rows for themes WordPress.org could not date, using style.css mtime.
     *
     * @param array $themes_outdate WordPress.org abandoned rows keyed by stylesheet.
     * @param array $themes         Installed themes from wp_get_themes().
     *
     * @return array Abandoned rows, with undated items filled from file mtime.
     */
    private function fill_undated_from_file_mtime( $themes_outdate, $themes ) {
        $no_date = get_transient( $this->tran_name_themes_no_date );
        if ( ! is_array( $no_date ) || empty( $no_date ) ) {
            return $themes_outdate;
        }

        $tolerance_in_days = (int) get_option( 'mainwp_child_plugintheme_days_outdate', 365 );
        $now               = time();

        foreach ( $no_date as $slug => $flag ) {
            if ( ! isset( $themes[ $slug ] ) || isset( $themes_outdate[ $slug ] ) ) {
                continue;
            }

            $theme = $themes[ $slug ];
            $file  = $theme->get_stylesheet_directory() . '/style.css';
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

            $themes_outdate[ $slug ] = array(
                'Name'          => $theme->get( 'Name' ),
                'Version'       => $theme->display( 'Version', true, false ),
                'last_updated'  => $mtime,
                'file_modified' => $mtime,
                'detection'     => 'file_mtime',
            );
        }

        return $themes_outdate;
    }

    /**
     * Enable or disable the background abandoned-theme checks for sync.
     *
     * @param bool $enabled Whether sync currently requests abandoned theme data.
     */
    public function sync_background_state( $enabled ) {
        if ( $enabled ) {
            $this->schedule_watchdog();
            return;
        }

        $this->cleanup_deactivation( false );
    }

    /**
     * Queue a background abandoned-theme check when sync needs data and the cache is incomplete.
     */
    public function maybe_queue_check() {
        $has_cached_results = false !== get_transient( $this->tran_name_theme_timestamps );
        $has_pending_batch  = false !== get_transient( $this->tran_name_themes_to_batch );

        if ( $has_cached_results && ! $has_pending_batch ) {
            return;
        }

        if ( $has_pending_batch || wp_next_scheduled( $this->cron_name_batching ) ) {
            return;
        }

        wp_schedule_single_event( time(), $this->cron_name_batching );
    }

    /**
     * Run Check.
     *
     * @throws MainWP_Exception Error message on failure.
     */
    public function run_check() { //phpcs:ignore -- NOSONAR - complex.
        if ( ! function_exists( '\wp_get_themes' ) ) {
            require_once ABSPATH . '/wp-admin/includes/theme.php'; // NOSONAR - WP compatible.
        }

        // Get our previous results.
        $responses = get_transient( $this->tran_name_theme_timestamps );

        if ( false === $responses || ! is_array( $responses ) ) {
            $responses = array();
        }

        $all_themes = get_transient( $this->tran_name_themes_to_batch );
        // If there wasn't a previous cache.
        if ( false === $all_themes || ! is_array( $all_themes ) ) {
            $all_themes = array();
            $themes     = \wp_get_themes();
            if ( is_array( $themes ) ) {
                foreach ( $themes as $theme ) {
                    $slug                = $theme->get_stylesheet();
                    $all_themes[ $slug ] = array(
                        'Name'    => $theme->get( 'Name' ),
                        'Version' => $theme->display( 'Version', true, false ),
                    );

                }
            }
            $responses = array();
        }

        $avoid_themes      = array( 'superstore' );
        $themes_to_scan    = array_splice( $all_themes, 0, apply_filters( 'mainwp_child_theme_health_check_max_themes_to_batch', 10 ) );
        $tolerance_in_days = get_option( 'mainwp_child_plugintheme_days_outdate', 365 );

        $no_date = get_transient( $this->tran_name_themes_no_date );
        if ( ! is_array( $no_date ) ) {
            $no_date = array();
        }

        foreach ( $themes_to_scan as $slug => $v ) {
            if ( in_array( $slug, $avoid_themes ) ) {
                continue;
            }

            $body = $this->try_get_response_body( $slug );

            // We couldn't reach WordPress.org, skip this theme.
            if ( false === $body ) {
                continue;
            }

            // Deserialize the response.
            $obj = '' === $body ? false : maybe_unserialize( $body ); // phpcs:ignore -- to compatible with third party, it's safe.

            // WordPress.org answered but could not date this theme (premium or unpublished).
            if ( ! is_object( $obj ) || ! property_exists( $obj, 'last_updated' ) ) {
                $no_date[ $slug ] = 1;
                continue;
            }

            unset( $no_date[ $slug ] );

            $now                     = new \DateTime();
            $last_updated            = strtotime( $obj->last_updated );
            $theme_last_updated_date = new \DateTime( '@' . $last_updated );

            $diff_in_days = $now->diff( $theme_last_updated_date )->format( '%a' );

            if ( $diff_in_days < $tolerance_in_days ) {
                continue;
            }

            $v['last_updated'] = $last_updated;

            $responses[ $slug ] = $v;
        }

        // Store the master response for usage in the plugin table.
        set_transient( $this->tran_name_theme_timestamps, $responses, 2 * DAY_IN_SECONDS );
        set_transient( $this->tran_name_themes_no_date, $no_date, 2 * DAY_IN_SECONDS );

        if ( empty( $all_themes ) ) {
            delete_transient( $this->tran_name_themes_to_batch );
        } else {
            set_transient( $this->tran_name_themes_to_batch, $all_themes, 2 * DAY_IN_SECONDS );
            wp_schedule_single_event( time(), $this->cron_name_batching );

        }
    }


    /**
     * Try to get response body.
     *
     * @param string $theme Theme slug.
     * @return string|bool Return response $body. Empty string when WordPress.org answered without theme data (not found, empty or N;). FALSE when no request got an answer.
     */
    private function try_get_response_body( $theme ) {
        $answered = false;

        // Get the WordPress current version to be polite in the API call.
        include_once ABSPATH . WPINC . '/version.php'; // NOSONAR - WP compatible.

        $url      = 'http://api.wordpress.org/themes/info/1.0/';
        $http_url = 'http://api.wordpress.org/themes/info/1.0/';
        $ssl      = wp_http_supports( array( 'ssl' ) );

        if ( $ssl ) {
            $url = set_url_scheme( $url, 'https' );
        }

        $args = array(
            'slug'   => $theme,
            'fields' => array(
                'sections' => false,
                'tags'     => false,
            ),
        );
        $args = (object) $args;

        $http_args = array(
            'body' => array(
                'action'  => 'theme_information',
                'request' => serialize( $args ), // phpcs:ignore -- third party compatible.
            ),
        );

        $raw_response = wp_remote_post( $url, $http_args );

        $body = $this->read_response_body( $raw_response, $answered );
        if ( false !== $body ) {
            return $body;
        }

        // The above valid.
        // If we previously tried an SSL version try without SSL.
        if ( $ssl ) {
            $raw_response = wp_remote_post( $http_url, $http_args );
            $body         = $this->read_response_body( $raw_response, $answered );
            if ( false !== $body ) {
                return $body;
            }
        }

        // Everything above failed, bail!
        return $answered ? '' : false;
    }

    /**
     * Read a theme info response body.
     *
     * A 404 or an empty 200 means WordPress.org has no data for the slug, which differs from a failed request.
     *
     * @param array|\WP_Error $raw_response Response from wp_remote_post().
     * @param bool            $answered     Set to true when WordPress.org answered without theme data.
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
