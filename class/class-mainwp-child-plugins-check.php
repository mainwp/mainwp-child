<?php

/*
 *
 * Credits
 *
 * Plugin-Name: Vendi Abandoned Plugin Check
 * Plugin URI: https://wordpress.org/plugins/vendi-abandoned-plugin-check/
 * Author: Vendi Advertising (Chris Haas)
 * Author URI: https://wp-staging.com
 * License: GPLv2
 *
*/

class MainWP_Child_Plugins_Check {

	public static $instance = null;

	private $cron_name_watcher = 'mainwp_child_cron_plugin_health_check_watcher';

	private $cron_name_daily = 'mainwp_child_cron_plugin_health_check_daily';

	private $cron_name_batching = 'mainwp_child_cron_plugin_health_check_batching';

	private $tran_name_plugin_timestamps = 'mainwp_child_tran_name_plugin_timestamps';

	private $tran_name_plugins_to_batch = 'mainwp_child_tran_name_plugins_to_batch';

	private $option_name_last_daily_run = 'mainwp_child_plugin_last_daily_run';

	public static function Instance() {
		if ( null === MainWP_Child_Plugins_Check::$instance ) {
			MainWP_Child_Plugins_Check::$instance = new MainWP_Child_Plugins_Check();
		}

		return MainWP_Child_Plugins_Check::$instance;
	}

	public function __construct() {
        if ( get_option('mainwp_child_plugintheme_days_outdate') ) {
            $this->schedule_watchdog();

            add_action( $this->cron_name_batching, array( $this, 'run_check' ) );
            add_action( $this->cron_name_daily, array( $this, 'run_check' ) );

            add_action( $this->cron_name_watcher, array( $this, 'perform_watchdog' ) );

            //add_filter( 'plugin_row_meta', array( $this, 'change_plugin_row_meta' ), 10, 4 );

            add_filter( 'plugins_api_args', array( $this, 'modify_plugin_api_search_query' ), 10, 2 );

            add_action( 'mainwp_child_deactivation', array( $this, 'cleanup_deactivation' ) );
        }
	}

	private function cleanup_basic() {
		wp_clear_scheduled_hook( $this->cron_name_daily );
		wp_clear_scheduled_hook( $this->cron_name_batching );
		delete_transient( $this->tran_name_plugins_to_batch );
	}


	public function cleanup_deactivation( $del = true ) {
		$this->cleanup_basic();
		wp_clear_scheduled_hook( $this->cron_name_watcher );
		delete_option( $this->option_name_last_daily_run );
		if ( $del ) {
			delete_transient( $this->tran_name_plugin_timestamps );
		}
	}


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

	public function perform_watchdog() {
		if ( false === wp_next_scheduled( $this->cron_name_daily ) && false === wp_next_scheduled( $this->cron_name_batching ) ) {
			$last_run = get_option( $this->option_name_last_daily_run );

			if ( false === $last_run || ! is_integer( $last_run ) ) {
				$last_run = false;
			} else {
				$last_run = new \DateTime( '@' . $last_run );
			}

			//Get now
			$now = new \DateTime();

			if ( false === $last_run || (int) $now->diff( $last_run )->format( '%h' ) >= 24 ) {
				$this->cleanup_basic();

				wp_schedule_event( time(), 'daily', $this->cron_name_daily );

				update_option( $this->option_name_last_daily_run, $now->getTimestamp() );

			}
		}
	}

	public function schedule_watchdog() {
		//For testing
		//$this->cleanup_deactivation();

		//Schedule a global watching cron just in case both other crons get killed
		if ( ! wp_next_scheduled( $this->cron_name_watcher ) ) {
			wp_schedule_event( time(), 'hourly', $this->cron_name_watcher );
		}

	}

	public function get_plugins_outdate_info() {
		$plugins_outdate = get_transient( $this->tran_name_plugin_timestamps );
		if ( ! is_array( $plugins_outdate ) ) {
			$plugins_outdate = array();
		}
		if ( ! function_exists( 'get_plugins' ) ) {
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
			set_transient( $this->tran_name_plugin_timestamps, $plugins_outdate, DAY_IN_SECONDS );
		}

		return $plugins_outdate;

	}

	// for testing
	public function change_plugin_row_meta( $plugin_meta, $plugin_file, $plugin_data, $status ) {
		//Grab our previously stored array of known last modified dates
		//Requires WP 2.8.0
		$plugin_info = get_transient( $this->tran_name_plugin_timestamps );

		//Sanity check the response
		if( false === $plugin_info || ! is_array( $plugin_info ) && 0 === count( $plugin_info ) )
		{
			return $plugin_meta;
		}

		//See if this specific plugin is in the known list
		if( array_key_exists( $plugin_file, $plugin_info ) )
		{
			//Get now
			$now = new \DateTime();
			$last_updated = $plugin_info[ $plugin_file ]['last_updated'];

			//Last updated is stored as timestamp, get a real date
			$plugin_last_updated_date = new \DateTime( '@' . $last_updated );

			//Compute days between now and plugin last updated
			$diff_in_days = $now->diff( $plugin_last_updated_date )->format( '%a' );

			//Customizable number of days for tolerance
			$tolerance_in_days = get_option( 'mainwp_child_plugintheme_days_outdate', 365 );

			//If we're outside the window for tolerance show a message
			if( $diff_in_days > $tolerance_in_days )
			{
				$plugin_meta[] = sprintf( '<strong style="color: #f00;">This plugin has not been updated by the author in %1$d days!</strong>', $diff_in_days );
			}
			else
			{
				$plugin_meta[] = sprintf( '<span style="color: #090;">This plugin was last updated by the author in %1$d days ago.</span>', $diff_in_days );
			}
		}

		return $plugin_meta;
	}

	public function run_check() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . '/wp-admin/includes/plugin.php';
		}

		//Get our previous results
		$responses = get_transient( $this->tran_name_plugin_timestamps );

		if ( false === $responses || ! is_array( $responses ) ) {
			$responses = array();
		}

		//Get our previous cache of plugins for batching
		$all_plugins = get_transient( $this->tran_name_plugins_to_batch );

		//If there wasn't a previous cache
		if ( false === $all_plugins || ! is_array( $all_plugins ) ) {
			$plugins = get_plugins();
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
		//Grab a small number of plugins to scan
		$plugins_to_scan = array_splice( $all_plugins, 0, apply_filters( 'mainwp_child_plugin_health_check_max_plugins_to_batch', 10 ) );
		$tolerance_in_days = get_option( 'mainwp_child_plugintheme_days_outdate', 365 );

		//Loop through each known plugin
		foreach ( $plugins_to_scan as $slug => $v ) {
			if ( in_array( $slug, $avoid_plugins ) ) {
				continue;
			}
			//Try to get the raw information for this plugin
			$body = $this->try_get_response_body( $slug, false );

			//We couldn't get any information, skip this plugin
			if ( false === $body ) {
				continue;
			}

			//Deserialize the response
			$obj = maybe_unserialize( $body );

			$now = new \DateTime();

			//Sanity check that deserialization worked and that our property exists
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

		if ( ! defined( 'DAY_IN_SECONDS' ) ) {
			define( 'DAY_IN_SECONDS', 24 * 60 * 60 );
		}

		//Store the master response for usage in the plugin table
		set_transient( $this->tran_name_plugin_timestamps, $responses, DAY_IN_SECONDS );

		if ( 0 === count( $all_plugins ) ) {
			delete_transient( $this->tran_name_plugins_to_batch );
		} else {
			set_transient( $this->tran_name_plugins_to_batch, $all_plugins, DAY_IN_SECONDS );
			wp_schedule_single_event( time(), $this->cron_name_batching );
		}
	}

	private function try_get_response_body( $plugin, $second_pass ) {
		//Some of this code is lifted from class-wp-upgrader

		//Get the WordPress current version to be polite in the API call
		include( ABSPATH . WPINC . '/version.php' );

		global $wp_version;

		//General options to be passed to wp_remote_get
		$options = array(
			'timeout'    => 60 * 60, //HOUR_IN_SECONDS
			'user-agent' => 'WordPress/' . $wp_version . '; ' . get_bloginfo( 'url' ),
		);

		//The URL for the endpoint
		$url = $http_url = 'http://api.wordpress.org/plugins/info/1.0/';

		//If we support SSL
		//Requires WP 3.2.0
		if ( $ssl = wp_http_supports( array( 'ssl' ) ) ) {
			//Requires WP 3.4.0
			$url = set_url_scheme( $url, 'https' );
		}

		$plugin_dir = $plugin;
		if ( strpos( $plugin, '/' ) !== false ) {
			$plugin_dir = dirname( $plugin );
		}

		//Try to get the response (usually the SSL version)
		//Requires WP 2.7.0
		$raw_response = wp_remote_get( $url . $plugin_dir, $options );

		//If we don't have an error and we received a valid response code
		//Requires WP 2.7.0
		if ( ! is_wp_error( $raw_response ) && 200 === (int) wp_remote_retrieve_response_code( $raw_response ) ) {
			//Get the actual body
			//Requires WP 2.7.0
			$body = wp_remote_retrieve_body( $raw_response );

			//Make sure that it isn't empty and also not an empty serialized object
			if ( '' !== $body && 'N;' !== $body ) {
				//If valid, return that
				return $body;
			}
		}

		//The above valid
		//If we previously tried an SSL version try without SSL
		//Code below same as above block
		if ( $ssl ) {
			$raw_response = wp_remote_get( $http_url . $plugin, $options );
			if ( ! is_wp_error( $raw_response ) && 200 === (int) wp_remote_retrieve_response_code( $raw_response ) ) {
				$body = wp_remote_retrieve_body( $raw_response );
				if ( '' !== $body && 'N;' !== $body ) {
					return $body;
				}
			}
		}

		//The above failed
		//If we're on a second pass already then there's nothing left to do but bail
		if ( true === $second_pass ) {
			return false;
		}

		//We're still on the first pass, try to get just the name of the directory of the plugin
		$parts = explode( '/', $plugin );

		//Sanity check that we have two parts, a directory and a file name
		if ( 2 === count( $parts ) ) {
			//Try this entire function using just the directory name
			return $this->try_get_response_body( $parts[0], true );
		}

		//Everything above failed, bail
		return false;
	}
}

