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
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * MainWP_Child_Plugins_Check constructor.
	 *
	 * Run any time class is called.
	 */
	public function __construct() {
		if ( get_option( 'mainwp_child_plugintheme_days_outdate' ) ) {
			$this->schedule_watchdog();

			add_action( $this->cron_name_batching, array( $this, 'run_check' ) );
			add_action( $this->cron_name_daily, array( $this, 'run_check' ) );
			add_action( $this->cron_name_watcher, array( $this, 'perform_watchdog' ) );
			add_filter( 'plugins_api_args', array( $this, 'modify_plugin_api_search_query' ), 10, 2 );
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
			require_once ABSPATH . '/wp-admin/includes/plugin.php';
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

		return $plugins_outdate;
	}

	/**
	 * Update Days out of date option.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Themes_Check::cleanup_deactivation()
	 * @uses \MainWP\Child\MainWP_Helper::update_option()
	 */
	public static function may_outdate_number_change() {
		if ( isset( $_POST['numberdaysOutdatePluginTheme'] ) ) {
			$days_outdate = get_option( 'mainwp_child_plugintheme_days_outdate', 365 );
			if ( $days_outdate != $_POST['numberdaysOutdatePluginTheme'] ) {
				$days_outdate = intval( $_POST['numberdaysOutdatePluginTheme'] );
				MainWP_Helper::update_option( 'mainwp_child_plugintheme_days_outdate', $days_outdate );
				self::instance()->cleanup_deactivation( false );
				MainWP_Child_Themes_Check::instance()->cleanup_deactivation( false );
			}
		}
	}

	/**
	 * Run plugin update check.
	 *
	 * @throws \Exception Error message on failure.
	 */
	public function run_check() {
		if ( ! function_exists( '\get_plugins' ) ) {
			require_once ABSPATH . '/wp-admin/includes/plugin.php';
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

		// Loop through each known plugin.
		foreach ( $plugins_to_scan as $slug => $v ) {
			if ( in_array( $slug, $avoid_plugins ) ) {
				continue;
			}
			// Try to get the raw information for this plugin.
			$body = $this->try_get_response_body( $slug, false );

			// We couldn't get any information, skip this plugin.
			if ( false === $body ) {
				continue;
			}

			// Deserialize the response.
			$obj = maybe_unserialize( $body );

			$now = new \DateTime();

			// Sanity check that deserialization worked and that our property exists.
			if ( false !== $obj && is_object( $obj ) && property_exists( $obj, 'last_updated' ) ) {
				if ( version_compare( $v['Version'], $obj->version, '>' ) ) {
					continue;
				}
				$last_updated             = strtotime( $obj->last_updated );
				$plugin_last_updated_date = new \DateTime( '@' . $last_updated );

				$diff_in_days = $now->diff( $plugin_last_updated_date )->format( '%a' );

				if ( $diff_in_days < $tolerance_in_days ) {
					continue;
				}
				$v['last_updated']  = $last_updated;
				$responses[ $slug ] = $v;
			}
		}

		// Store the master response for usage in the plugin table.
		set_transient( $this->tran_name_plugin_timestamps, $responses, 2 * DAY_IN_SECONDS );

		if ( 0 === count( $all_plugins ) ) {
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
	 * @return bool|string true|false The body of the response. Empty string if no body or incorrect parameter given.
	 */
	private function try_get_response_body( $plugin, $second_pass ) {

		// Get the WordPress current version to be polite in the API call.
		include ABSPATH . WPINC . '/version.php';

		/**
		 * The installed version of WordPress.
		 *
		 * @global string $wp_version The installed version of WordPress.
		 */
		global $wp_version;

		// General options to be passed to wp_remote_get.
		$options = array(
			'timeout'    => 60 * 60,
			'user-agent' => 'WordPress/' . $wp_version . '; ' . get_bloginfo( 'url' ),
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

		// If we don't have an error and we received a valid response code.
		if ( ! is_wp_error( $raw_response ) && 200 === (int) wp_remote_retrieve_response_code( $raw_response ) ) {
			// Get the actual body.
			$body = wp_remote_retrieve_body( $raw_response );

			// Make sure that it isn't empty and also not an empty serialized object.
			if ( '' !== $body && 'N;' !== $body ) {
				return $body;
			}
		}

		// The above valid!
		// If we previously tried an SSL version try without SSL.
		// Code below same as above block.
		if ( $ssl ) {
			$raw_response = wp_remote_get( $http_url . $plugin, $options );
			if ( ! is_wp_error( $raw_response ) && 200 === (int) wp_remote_retrieve_response_code( $raw_response ) ) {
				$body = wp_remote_retrieve_body( $raw_response );
				if ( '' !== $body && 'N;' !== $body ) {
					return $body;
				}
			}
		}

		// The above failed!
		// If we're on a second pass already then there's nothing left to do but bail.
		if ( true === $second_pass ) {
			return false;
		}

		// We're still on the first pass, try to get just the name of the directory of the plugin.
		$parts = explode( '/', $plugin );

		// Sanity check that we have two parts, a directory and a file name.
		if ( 2 === count( $parts ) ) {
			// Try this entire function using just the directory name.
			return $this->try_get_response_body( $parts[0], true );
		}

		// Everything above failed, bail!
		return false;
	}
}
