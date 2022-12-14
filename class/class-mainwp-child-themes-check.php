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
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * MainWP_Child_Themes_Check constructor.
	 *
	 * Run any time class is called.
	 */
	public function __construct() {

		if ( get_option( 'mainwp_child_plugintheme_days_outdate' ) ) {
			$this->schedule_watchdog();
			add_action( $this->cron_name_batching, array( $this, 'run_check' ) );
			add_action( $this->cron_name_daily, array( $this, 'run_check' ) );
			add_action( $this->cron_name_watcher, array( $this, 'perform_watchdog' ) );
			add_filter( 'themes_api_args', array( $this, 'modify_theme_api_search_query' ), 10, 2 );
			add_action( 'mainwp_child_deactivation', array( $this, 'cleanup_deactivation' ) );
		}
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
	 * @throws \Exception Error message on failure.
	 */
	public function perform_watchdog() {
		if ( ! wp_next_scheduled( $this->cron_name_batching ) ) {
			$last_run = get_option( $this->option_name_last_daily_run );
			if ( false === $last_run || ! is_integer( $last_run ) ) {
				$last_run = false;
			} else {
				$last_run = new \DateTime( '@' . $last_run );
			}

			$now = new \DateTime();

			if ( false === $last_run || (int) $now->diff( $last_run )->format( '%h' ) >= 24 ) {
				$this->cleanup_basic();
				wp_schedule_event( time(), 'daily', $this->cron_name_daily );
				update_option( $this->option_name_last_daily_run, $now->getTimestamp() );
			}
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
			require_once ABSPATH . '/wp-admin/includes/theme.php';
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

		return $themes_outdate;
	}

	/**
	 * Run Check.
	 *
	 * @throws \Exception Error message on failure.
	 */
	public function run_check() {
		if ( ! function_exists( '\wp_get_themes' ) ) {
			require_once ABSPATH . '/wp-admin/includes/theme.php';
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

		foreach ( $themes_to_scan as $slug => $v ) {
			if ( in_array( $slug, $avoid_themes ) ) {
				continue;
			}

			$body = $this->try_get_response_body( $slug, false );

			if ( false === $body ) {
				continue;
			}

			// Deserialize the response.
			$obj = maybe_unserialize( $body ); // phpcs:ignore -- to compatible with third party, it's safe.

			$now = new \DateTime();

			// Sanity check that deserialization worked and that our property exists.
			if ( false !== $obj && is_object( $obj ) && property_exists( $obj, 'last_updated' ) ) {
				$last_updated            = strtotime( $obj->last_updated );
				$theme_last_updated_date = new \DateTime( '@' . $last_updated );

				$diff_in_days = $now->diff( $theme_last_updated_date )->format( '%a' );

				if ( $diff_in_days < $tolerance_in_days ) {
					continue;
				}

				$v['last_updated'] = $last_updated;

				$responses[ $slug ] = $v;
			}
		}

		// Store the master response for usage in the plugin table.
		set_transient( $this->tran_name_theme_timestamps, $responses, 2 * DAY_IN_SECONDS );

		if ( 0 === count( $all_themes ) ) {
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
	 * @return string|bool Return response $body or FALSE on failure.
	 */
	private function try_get_response_body( $theme ) {

		// Get the WordPress current version to be polite in the API call.
		include ABSPATH . WPINC . '/version.php';

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

		if ( ! is_wp_error( $raw_response ) && 200 === (int) wp_remote_retrieve_response_code( $raw_response ) ) {
			// Get the actual body.
			$body = wp_remote_retrieve_body( $raw_response );

			// Make sure that it isn't empty and also not an empty serialized object.
			if ( '' !== $body && 'N;' !== $body ) {
				return $body;
			}
		}

		// The above valid.
		// If we previously tried an SSL version try without SSL.
		// Code below same as above block.
		if ( $ssl ) {
			$raw_response = wp_remote_post( $http_url, $http_args );

			if ( ! is_wp_error( $raw_response ) && 200 === (int) wp_remote_retrieve_response_code( $raw_response ) ) {
				$body = wp_remote_retrieve_body( $raw_response );
				if ( '' !== $body && 'N;' !== $body ) {
					return $body;
				}
			}
		}

		// Everything above failed, bail!
		return false;
	}
}
