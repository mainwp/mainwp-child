<?php
/**
 * MainWP Child Site Cache Purge
 *
 * Manages clearing the selected Cache.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

/**
 * Class MainWP_Child_Cache_Purge
 *
 * This class handles purging Child Site cache when requested.
 *
 * @package MainWP\Child
 */
class MainWP_Child_Cache_Purge {

	/**
	 * Public variable to state if supported plugin is installed on the child site.
	 *
	 * @var bool If supported plugin is installed, return true, if not, return false.
	 */
	public $is_plugin_installed = false;

	/**
	 * Public static variable to hold the single instance of the class.
	 *
	 * @var mixed Default null
	 */
	protected static $instance = null;

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
	 * MainWP_Child_Cache_Purge constructor.
	 *
	 * Run any time class is called.
	 *
	 * Add filter to sync data to & from the MainWP Dashboard.
	 * Add action to check for supported cache plugins.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Cache_Purge::sync_others_data()
	 * @uses \MainWP\Child\MainWP_Child_Cache_Purge::check_cache_solution()
	 */
	public function __construct() {
		add_filter( 'mainwp_site_sync_others_data', array( $this, 'sync_others_data' ), 10, 2 );
		add_action( 'plugins_loaded', array( $this, 'check_cache_solution' ), 10, 2 );
	}

	/**
	 * Method sync_others_data()
	 *
	 * Sync data to & from the MainWP Dashboard.
	 *
	 * @param array $information Array containing the data to be sent to the Dashboard.
	 * @param array $data        Array containing the data sent from the Dashboard; to be saved to the Child Site.
	 *
	 * @return array $information Array containing the data to be sent to the Dashboard.
	 */
	public function sync_others_data( $information, $data = array() ) {

		// ** Grab data synced from MainWP Dashboard & update options. **//
		if ( is_array( $data ) ) {
			try {

				// Update mainwp_child_auto_purge_cache option value with either yes|no.
				update_option( 'mainwp_child_auto_purge_cache', ( $data['auto_purge_cache'] ? 1 : 0 ) );

				// Update mainwp_child_cloud_flair_enabled options value.
				update_option( 'mainwp_child_cloud_flair_enabled', ( $data['cloud_flair_enabled'] ? 1 : 0 ) );

				// Update Cloudflare API Credentials option values.
				if ( isset( $data['mainwp_cloudflair_email'] ) ) {
					update_option( 'mainwp_cloudflair_email', ( $data['mainwp_cloudflair_email'] ) );
				}
				if ( isset( $data['mainwp_cloudflair_key'] ) ) {
					update_option( 'mainwp_cloudflair_key', ( $data['mainwp_cloudflair_key'] ) );
				}
			} catch ( \Exception $e ) {
				error_log( $e->getMessage() ); // phpcs:ignore -- debug mode only.
			}
		}

		// ** Send data to MainWP Dashboard. **//

		// Send last purged time stamp to MainWP Dashboard.
		$information['mainwp_cache_control_last_purged'] = get_option( 'mainwp_cache_control_last_purged', 0 );

		// Send active cache solution to MainWP Dashboard.
		$information['mainwp_cache_control_cache_solution'] = get_option( 'mainwp_cache_control_cache_solution', 0 );

		// Send data for Cache Control Logs.
		$information['mainwp_cache_control_logs'] = get_option( 'mainwp_cache_control_log', '' );

		return $information;
	}

	/**
	 * Check which supported plugin is installed,
	 * Set wp_option 'mainwp_cache_control_cache_solution' to active plugin,
	 * and set public variable 'is_plugin_installed' to TRUE.
	 *
	 * If a supported plugin is not installed check to see if CloudFlair solution is enabled.
	 *
	 * @return void
	 */
	public function check_cache_solution() {

		// Default value for cache solution.
		$cache_plugin_solution = 'Plugin Not Found';

		// Grab all mu-plugins & check for Rocket.net mu-plugin. If found, set cache solution to CDN Cache Plugin.
		$mu_plugings_list = get_mu_plugins();
		if ( array_key_exists( 'cdn-cache-management.php', $mu_plugings_list ) ) {
			$cache_plugin_solution = 'CDN Cache Plugin';
		}

		$supported_cache_plugins = array(
			'wp-rocket/wp-rocket.php'                    => 'WP Rocket',
			'breeze/breeze.php'                          => 'Breeze',
			'litespeed-cache/litespeed-cache.php'        => 'LiteSpeed Cache',
			'sg-cachepress/sg-cachepress.php'            => 'SiteGround Optimizer',
			'swift-performance-lite/performance.php'     => 'Swift Performance Lite',
			'swift-performance/performance.php'          => 'Swift Performance',
			'wp-fastest-cache/wpFastestCache.php'        => 'WP Fastest Cache',
			'w3-total-cache/w3-total-cache.php'          => 'W3 Total Cache',
			'hummingbird-performance/wp-hummingbird.php' => 'Hummingbird Performance',
			'cache-enabler/cache-enabler.php'            => 'Cache Enabler',
			'nginx-helper/nginx-helper.php'              => 'Nginx Helper',
			'nitropack/main.php'                         => 'Nitropack',
			'autoptimize/autoptimize.php'                => 'Autoptimize',
			'flying-press/flying-press.php'              => 'FlyingPress',
			'wp-super-cache/wp-cache.php'                => 'WP Super Cache',
			'wp-optimize/wp-optimize.php'                => 'WP Optimize',
			'comet-cache/comet-cache.php'                => 'Comet Cache',
		);

		// Check if a supported cache plugin is active.
		foreach ( $supported_cache_plugins as $plugin => $name ) {
			if ( is_plugin_active( $plugin ) ) {
				$cache_plugin_solution     = $name;
				$this->is_plugin_installed = true;
			}
		}

		// Update wp_option 'mainwp_cache_control_cache_solution' with active plugin or "Plugin Not Found".
		update_option( 'mainwp_cache_control_cache_solution', $cache_plugin_solution );
	}

	/**
	 * Auto purge cache based on which cache plugin is installed & activated.
	 *
	 * @used-by MainWP_Child_Updates::upgrade_plugin_theme()
	 * @used-by MainWP_Child_Updates::upgrade_wp()
	 *
	 * @param string $bulk Bulk update distinguisher.
	 *
	 * @return void
	 */
	public function auto_purge_cache( $bulk = '' ) {  // phpcs:ignore -- Current complexity is the only way to achieve desired results, pull request solutions appreciated.
		// Check if Cache Control is enabled.
		if ( get_option( 'mainwp_child_auto_purge_cache' ) == '1' ) {
			$information = array();

			// Grab detected cache solution..
			$cache_plugin_solution = get_option( 'mainwp_cache_control_cache_solution', 0 );

			// Run the corresponding cache plugin purge method.
			try {
				switch ( $cache_plugin_solution ) {
					case 'WP Rocket':
						$information = $this->wprocket_auto_cache_purge();
						break;
					case 'Breeze':
						$information = $this->breeze_auto_purge_cache();
						break;
					case 'LiteSpeed Cache':
						$information = $this->litespeed_auto_purge_cache();
						break;
					case 'SiteGround Optimizer':
						$information = $this->sitegrounds_optimizer_auto_purge_cache();
						break;
					case 'Swift Performance Lite':
						$information = $this->swift_performance_lite_auto_purge_cache();
						break;
					case 'Swift Performance':
						$information = $this->swift_performance_auto_purge_cache();
						break;
					case 'WP Fastest Cache':
						$information = $this->wp_fastest_cache_auto_purge_cache();
						break;
					case 'W3 Total Cache':
						$information = $this->w3_total_cache_auto_purge_cache();
						break;
					case 'Hummingbird Performance':
						$information = $this->wp_hummingbird_auto_purge_cache();
						break;
					case 'Cache Enabler':
						$information = $this->cache_enabler_auto_purge_cache();
						break;
					case 'Nginx Helper':
						$information = $this->nginx_helper_auto_purge_cache();
						break;
					case 'Nitropack':
						$information = $this->nitropack_auto_purge_cache();
						break;
					case 'Autoptimize':
						$information = $this->autoptimize_auto_purge_cache();
						break;
					case 'FlyingPress':
						$information = $this->flyingpress_auto_purge_cache();
						break;
					case 'WP Super Cache':
						$information = $this->wp_super_cache_auto_purge_cache();
						break;
					case 'WP Optimize':
						$information = $this->wp_optimize_auto_purge_cache();
						break;
					case 'Comet Cache':
						$information = $this->comet_cache_auto_purge_cache();
						break;
					case 'CDN Cache Plugin':
						$information = $this->cdn_cache_plugin_auto_purge_cache();
						break;
					default:
						break;
				}
			} catch ( \Exception $e ) {
				$information = array( 'error' => $e->getMessage() );
			}

			// If no cache plugin is found, set status to disabled but still pass "SUCCESS" action because it did not fail.
			if ( 'Plugin Not Found' == $cache_plugin_solution ) {
				$information = array(
					'status' => 'Disabled',
					'action' => 'SUCCESS',
				);
			}

			// Fire off CloudFlare purge if enabled & not using a CDN Cache Plugin. ( Stops double purging Cloudflare ).
			if ( '1' === get_option( 'mainwp_child_cloud_flair_enabled' ) && 'CDN Cache Plugin' !== $cache_plugin_solution ) {
				$information['cloudflare'] = $this->cloudflair_auto_purge_cache();
			}
		} else {
			// If Cache Control is disabled, set status to disabled but still pass "SUCCESS" action because it did not fail.
			$information = array(
				'status' => 'Disabled',
				'action' => 'SUCCESS',
			);
		}

		// Save to DB.
		$this->record_results( $information );

		// Only fire off if this is a 'bulk' action.
		if ( 'true' === $bulk ) {
			// Return results in JSON format.
			MainWP_Helper::write( $information );
		}
	}

	/**
	 * Build purge results array for return to dashboard.
	 *
	 * @param string $message Result message.
	 * @param string $action Action result.
	 *
	 * @return array Purge results array.
	 */
	public function purge_result( $message, $action ) {
		$result           = array(
			'Last Purged'           => get_option( 'mainwp_cache_control_last_purged', false ),
			'Cache Solution'        => get_option( 'mainwp_cache_control_cache_solution', false ),
			'Cache Control Enabled' => get_option( 'mainwp_child_auto_purge_cache' ),
			'Cloudflair Enabled'    => get_option( 'mainwp_child_cloud_flair_enabled' ),
		);
		$result['result'] = $message;
		if ( 'SUCCESS' === $action ) {
			$result['action'] = 'SUCCESS';
		} else {
			$result['action'] = 'ERROR';
		}
		return $result;
	}

	/**
	 * Purge CDN Cache Plugin cache.
	 *
	 * @return array Purge results array.
	 */
	public function cdn_cache_plugin_auto_purge_cache() {

		$success_message = 'CDN Cache Plugin => Cache auto cleared on: (' . current_time( 'mysql' ) . ')';
		$error_message   = 'CDN Cache Plugin => There was an issue purging your cache.';

		if ( class_exists( 'CDN_Clear_Cache_Api' ) ) {

			\CDN_Clear_Cache_Api::cache_api_call( array(), 'purge_everything' );

			// record results.
			update_option( 'mainwp_cache_control_last_purged', time() );

			return $this->purge_result( $success_message, 'SUCCESS' );

		} else {
			return $this->purge_result( $error_message, 'ERROR' );
		}
	}

	/**
	 * Purge Comet Cache.
	 *
	 * @return array Purge results array.
	 */
	public function comet_cache_auto_purge_cache() {

		$success_message = 'Comet Cache => Cache auto cleared on: (' . current_time( 'mysql' ) . ')';
		$error_message   = 'Comet Cache => There was an issue purging your cache.';

		if ( class_exists( '\comet_cache' ) ) {

			// Clear Cache.
			\comet_cache::clear();

			// record results.
			update_option( 'mainwp_cache_control_last_purged', time() );

			return $this->purge_result( $success_message, 'SUCCESS' );

		} else {
			return $this->purge_result( $error_message, 'ERROR' );
		}
	}

	/**
	 * Purge WP Optimize cache.
	 *
	 * @return array Purge results array.
	 */
	public function wp_optimize_auto_purge_cache() {

		$success_message = 'WP Optimize => Cache auto cleared on: (' . current_time( 'mysql' ) . ')';
		$error_message   = 'WP Optimize => There was an issue purging your cache.';

		// Clear Cache.
		$purge = self::wp_optimize_purge_cache();

		// Preload cache.
		$preload = self::wp_optimize_preload_cache();

		// Check response & return results.
		if ( true === $purge && true === $preload ) {
			update_option( 'mainwp_cache_control_last_purged', time() );

			return $this->purge_result( $success_message, 'SUCCESS' );

		} else {
			return $this->purge_result( $error_message, 'ERROR' );
		}
	}

	/**
	 * Preload WP Optimize cache after purge.
	 *
	 * @return bool True if successful, false if not.
	 */
	public function wp_optimize_preload_cache() {
		if ( class_exists( '\WP_Optimize_Cache_Commands' ) && class_exists( '\WP_Optimize_Page_Cache_Preloader' ) ) {

			// Clear Cache.
			$purge = new \WP_Optimize_Cache_Commands();
			$purge->run_cache_preload();
			return true;
		}
		return false;
	}

	/**
	 * Preload WP Optimize cache after purge.
	 *
	 * @return bool True if successful, false if not.
	 */
	public function wp_optimize_purge_cache() {
		if ( class_exists( '\WP_Optimize_Cache_Commands' ) && class_exists( '\WP_Optimize_Page_Cache_Preloader' ) ) {

			// Clear Cache.
			$purge = new \WP_Optimize_Cache_Commands();
			$purge->purge_page_cache();
			return true;
		}
		return false;
	}

	/**
	 * Purge WP Super Cache.
	 *
	 * @return array Purge results array.
	 */
	public function wp_super_cache_auto_purge_cache() {

		$success_message = 'WP Super Cache => Cache auto cleared on: (' . current_time( 'mysql' ) . ')';
		$error_message   = 'WP Super Cache => There was an issue purging your cache.';

		if ( function_exists( '\wp_cache_clean_cache' ) ) {

			// Clear Cache.
			global $file_prefix;
			\wp_cache_clean_cache( $file_prefix, true );

			// record results.
			update_option( 'mainwp_cache_control_last_purged', time() );

			return $this->purge_result( $success_message, 'SUCCESS' );

		} else {
			return $this->purge_result( $error_message, 'ERROR' );
		}
	}

	/**
	 * Purge FlyingPress cache.
	 *
	 * @return array Purge results array.
	 */
	public function flyingpress_auto_purge_cache() {

		$success_message = 'FlyingPress Cache => Cache auto cleared on: (' . current_time( 'mysql' ) . ')';
		$error_message   = 'FlyingPress Cache => There was an issue purging your cache.';

		if ( class_exists( '\FlyingPress\Purge' ) && class_exists( '\FlyingPress\Preload' ) ) {

			// Clear Cache.
			\FlyingPress\Purge::purge_everything();

			sleep( 3 );
			// Preload Cache.
			\FlyingPress\Preload::preload_cache();

			// record results.
			update_option( 'mainwp_cache_control_last_purged', time() );

			return $this->purge_result( $success_message, 'SUCCESS' );

		} else {
			return $this->purge_result( $error_message, 'ERROR' );
		}
	}

	/**
	 * Purge Autoptimize cache.
	 *
	 * @return array Purge results array.
	 */
	public function autoptimize_auto_purge_cache() {

		$success_message = 'Autoptimize => Cache auto cleared on: (' . current_time( 'mysql' ) . ')';
		$error_message   = 'Autoptimize => There was an issue purging your cache.';

		if ( class_exists( 'autoptimizeCache' ) ) {

			// Clear Cache.
			\autoptimizeCache::clearall();

			// record results.
			update_option( 'mainwp_cache_control_last_purged', time() );

			return $this->purge_result( $success_message, 'SUCCESS' );

		} else {
			return $this->purge_result( $error_message, 'ERROR' );
		}
	}

	/**
	 * Purge Nitropack cache.
	 *
	 * Nitropack_purge($url = NULL, $tag = NULL, $reason = NULL);
	 *
	 * In case you want to do a full purge, you must leave the values
	 * for URL and Tag empty. In case you want to create a targeted purge
	 * you can replace them with the URL or tag of the page.
	 *
	 * @return array Purge results array.
	 */
	public function nitropack_auto_purge_cache() {

		$success_message = 'Nitropack => Cache auto cleared on: (' . current_time( 'mysql' ) . ')';
		$error_message   = 'Nitropack => There was an issue purging your cache.';

		if ( function_exists( 'nitropack_purge' ) ) {

			// Clear Nitropack Cache after update.
			nitropack_purge();

			// record results.
			update_option( 'mainwp_cache_control_last_purged', time() );

			return $this->purge_result( $success_message, 'SUCCESS' );

		} else {
			return $this->purge_result( $error_message, 'ERROR' );
		}
	}

	/**
	 * Purge Nginx Helper cache.
	 *
	 * @return array Purge results array.
	 */
	public function nginx_helper_auto_purge_cache() {

		$success_message = 'Nginx Helper => Cache auto cleared on: (' . current_time( 'mysql' ) . ')';
		$error_message   = 'Nginx Helper => There was an issue purging your cache.';

		if ( class_exists( 'Nginx_Helper' ) ) {

			// Clear Nginx Helper Cache after update.
			do_action( 'rt_nginx_helper_purge_all' );

			// record results.
			update_option( 'mainwp_cache_control_last_purged', time() );

			return $this->purge_result( $success_message, 'SUCCESS' );

		} else {
			return $this->purge_result( $error_message, 'ERROR' );
		}
	}

	/**
	 * Purge WP Hummingbird cache.
	 *
	 * @note needs to have namespace or it will not work.
	 *
	 * @return array Purge results array.
	 */
	public function wp_hummingbird_auto_purge_cache() {

		$success_message = 'Hummingbird => Cache auto cleared on: (' . current_time( 'mysql' ) . ')';
		$error_message   = 'Hummingbird => There was an issue purging your cache.';

		if ( class_exists( 'Hummingbird\Core\Modules\\Page_Cache' ) ) {

			// Clear WP Hummingbird Cache after update.
			do_action( 'wphb_clear_page_cache' );

			// record results.
			update_option( 'mainwp_cache_control_last_purged', time() );

			return $this->purge_result( $success_message, 'SUCCESS' );

		} else {
			return $this->purge_result( $error_message, 'ERROR' );
		}
	}

	/**
	 * Purge Cache Enabler cache.
	 *
	 * @return array Purge results array.
	 */
	public function cache_enabler_auto_purge_cache() {

		$success_message = 'Cache Enabler => Cache auto cleared on: (' . current_time( 'mysql' ) . ')';
		$error_message   = 'Cache Enabler => There was an issue purging your cache.';

		if ( class_exists( 'Cache_Enabler' ) ) {

			// Clear the Cache after update.
			\Cache_Enabler::clear_complete_cache();

			// record results.
			update_option( 'mainwp_cache_control_last_purged', time() );

			return $this->purge_result( $success_message, 'SUCCESS' );

		} else {
			return $this->purge_result( $error_message, 'ERROR' );
		}
	}

	/**
	 * Purge W3 Total Cache.
	 *
	 * @return array Purge results array.
	 */
	public function w3_total_cache_auto_purge_cache() {

		$success_message = 'W3 Total Cache => Cache auto cleared on: (' . current_time( 'mysql' ) . ')';
		$error_message   = 'W3 Total Cache => There was an issue purging your cache.';

		if ( function_exists( 'w3tc_flush_all' ) ) {

			// Purge all W3 total cache.
			w3tc_flush_all();

			// record results.
			update_option( 'mainwp_cache_control_last_purged', time() );

			return $this->purge_result( $success_message, 'SUCCESS' );

		} else {
			return $this->purge_result( $error_message, 'ERROR' );
		}
	}

	/**
	 * Purge WP Fastest Cache.
	 *
	 * @return array Purge results array.
	 */
	public function wp_fastest_cache_auto_purge_cache() {

		$success_message = 'WP Fastest Cache => Cache auto cleared on: (' . current_time( 'mysql' ) . ')';
		$error_message   = 'WP Fastest Cache => There was an issue purging your cache.';

		if ( class_exists( 'WpFastestCache' ) ) {

			// Clear the Cache after update.
			do_action( 'wpfc_clear_all_cache' );

			// record results.
			update_option( 'mainwp_cache_control_last_purged', time() );

			return $this->purge_result( $success_message, 'SUCCESS' );

		} else {
			return $this->purge_result( $error_message, 'ERROR' );
		}
	}

	/**
	 * Purge Swift Performance Lite Cache.
	 *
	 * @return array Purge results array.
	 */
	public function swift_performance_lite_auto_purge_cache() {

		$success_message = 'Swift Performance Lite => Cache auto cleared on: (' . current_time( 'mysql' ) . ')';
		$error_message   = 'Swift Performance Lite => There was an issue purging your cache.';

		if ( class_exists( 'Swift_Performance_Cache' ) ) {

			// Clear All Swift Performance Lite Cache.
			\Swift_Performance_Cache::clear_all_cache();

			// record results.
			update_option( 'mainwp_cache_control_last_purged', time() );

			return $this->purge_result( $success_message, 'SUCCESS' );

		} else {
			return $this->purge_result( $error_message, 'ERROR' );
		}
	}

	/**
	 * Purge Swift Performance Cache.
	 *
	 * @return array Purge results array.
	 */
	public function swift_performance_auto_purge_cache() {

		$success_message = 'Swift Performance => Cache auto cleared on: (' . current_time( 'mysql' ) . ')';
		$error_message   = 'Swift Performance => There was an issue purging your cache.';

		if ( class_exists( 'Swift_Performance_Cache' ) ) {

			// Clear All Swift Performance Cache.
			\Swift_Performance_Cache::clear_all_cache();

			// record results.
			update_option( 'mainwp_cache_control_last_purged', time() );

			return $this->purge_result( $success_message, 'SUCCESS' );

		} else {
			return $this->purge_result( $error_message, 'ERROR' );
		}
	}

	/**
	 * Purge SiteGrounds Optimiser Cache.
	 *
	 * @return array Purge results array.
	 */
	public function sitegrounds_optimizer_auto_purge_cache() {

		$success_message = 'SiteGround Optimizer => Cache auto cleared on: (' . current_time( 'mysql' ) . ')';
		$error_message   = 'SiteGround Optimizer => There was an issue purging your cache.';

		if ( function_exists( 'sg_cachepress_purge_everything' ) ) {

			// Purge all SG CachePress cache.
			sg_cachepress_purge_everything();

			// record results.
			update_option( 'mainwp_cache_control_last_purged', time() );

			return $this->purge_result( $success_message, 'SUCCESS' );
		} else {
			return $this->purge_result( $error_message, 'ERROR' );
		}
	}

	/**
	 * Purge Cloudflair Cache.
	 *
	 * @noinspection PhpIdempotentOperationInspection
	 *
	 * @return array Purge results array.
	 */
	public function cloudflair_auto_purge_cache() {

		// Credentials for Cloudflare.
		$cust_email  = get_option( 'mainwp_cloudflair_email' );
		$cust_xauth  = get_option( 'mainwp_cloudflair_key' );
		$cust_domain = trim( str_replace( array( 'http://', 'https://', 'www.' ), '', get_option( 'siteurl' ) ), '/' );

		if ( '' == $cust_email || '' == $cust_xauth || '' == $cust_domain ) {
			return;
		}

		// Get the Zone-ID from Cloudflare since they don't provide that in the Backend.
		$ch_query = curl_init(); // phpcs:ignore -- use core function.
		curl_setopt( $ch_query, CURLOPT_URL, 'https://api.cloudflare.com/client/v4/zones?name=' . $cust_domain . '&status=active&page=1&per_page=5&order=status&direction=desc&match=all' ); // phpcs:ignore -- use core function.
		curl_setopt( $ch_query, CURLOPT_RETURNTRANSFER, 1 ); // phpcs:ignore -- use core function.
		$qheaders = array(
			'X-Auth-Email: ' . $cust_email . '',
			'X-Auth-Key: ' . $cust_xauth . '',
			'Content-Type: application/json',
		);
		curl_setopt( $ch_query, CURLOPT_HTTPHEADER, $qheaders ); // phpcs:ignore -- use core function.
		$qresult = json_decode( curl_exec( $ch_query ), true ); // phpcs:ignore -- use core function.
		if ( 'resource' === gettype( $ch_query ) ) {
			curl_close( $ch_query ); // phpcs:ignore -- use core function.
		}

		// If the Zone-ID is not found, return status no-id but still return "SUCCESS" action because it did not fail.
		// Explanation: When no Child Site is found on CF account, this will stop execution of this function and return
		// back to auto_purge_cache() function for further processing.
		if ( ! isset( $qresult['result'][0]['id'] ) ) {
			return array(
				'status' => 'no-id',
				'action' => 'SUCCESS',
			);
		}

		$cust_zone = $qresult['result'][0]['id'];

		// Purge the entire cache via API.
		$ch_purge = curl_init(); // phpcs:ignore -- use core function.
		curl_setopt( $ch_purge, CURLOPT_URL, 'https://api.cloudflare.com/client/v4/zones/' . $cust_zone . '/purge_cache' ); // phpcs:ignore -- use core function.
		curl_setopt( $ch_purge, CURLOPT_CUSTOMREQUEST, 'DELETE' ); // phpcs:ignore -- use core function.
		curl_setopt( $ch_purge, CURLOPT_RETURNTRANSFER, 1 ); // phpcs:ignore -- use core function.
		$headers = array(
			'X-Auth-Email: ' . $cust_email,
			'X-Auth-Key: ' . $cust_xauth,
			'Content-Type: application/json',
		);
		$data    = json_encode( array( 'purge_everything' => true ) ); // phpcs:ignore -- ok.
		curl_setopt( $ch_purge, CURLOPT_POST, true ); // phpcs:ignore -- use core function.
		curl_setopt( $ch_purge, CURLOPT_POSTFIELDS, $data ); // phpcs:ignore -- use core function.
		curl_setopt( $ch_purge, CURLOPT_HTTPHEADER, $headers ); // phpcs:ignore -- use core function.

		$result = json_decode( curl_exec( $ch_purge ), true ); // phpcs:ignore -- use core function.
		if ( 'resource' === gettype( $ch_query ) ) {
			curl_close( $ch_purge ); // phpcs:ignore -- use core function.
		}

		$success_message = 'Cloudflair => Cache auto cleared on: (' . current_time( 'mysql' ) . ')';
		$error_message   = 'Cloudflare => There was an issue purging your cache.' . json_encode( $result ); // phpcs:ignore -- ok.

		// Save last purge time to database on success.
		if ( 1 == $result['success'] ) {
			// record results.
			update_option( 'mainwp_cache_control_last_purged', time() );

			// Return success message.
			return $this->purge_result( $success_message, 'SUCCESS' );
		} else {
			// Return error message.
			return $this->purge_result( $error_message, 'ERROR' );
		}
	}

	/**
	 * Purge LiteSpeed Cache.
	 *
	 * @return array Purge results array.
	 */
	public function litespeed_auto_purge_cache() {

		$success_message = 'LiteSpeed => Cache auto cleared on: (' . current_time( 'mysql' ) . ')';
		$error_message   = 'LiteSpeed => There was an issue purging your cache.';

		if ( class_exists( '\LiteSpeed\Purge' ) ) {

			// Purge all LS Cache.
			\LiteSpeed\Purge::purge_all();

			// record results.
			update_option( 'mainwp_cache_control_last_purged', time() );

			// Return success message.
			return $this->purge_result( $success_message, 'SUCCESS' );

		} else {
			// Return error message.
			return $this->purge_result( $error_message, 'ERROR' );
		}
	}

	/**
	 * Purge Breeze cache.
	 *
	 * @return array Purge results array.
	 */
	public function breeze_auto_purge_cache() {

		$success_message = 'Breeze => Cache auto cleared on: (' . current_time( 'mysql' ) . ')';
		$error_message   = 'Breeze => There was an issue purging your cache.';

		if ( class_exists( 'Breeze_Admin' ) ) {

			// Clears varnish cache.
			$admin = new \Breeze_Admin();
			$admin->breeze_clear_varnish();

			// For local static files: Clears files within /cache/breeze-minification/ folder.
			if ( class_exists( '\Breeze_Configuration' ) ) {
				$size_cache = \Breeze_Configuration::breeze_clean_cache();
			} else {
				$size_cache = 0;
			}

			// Delete minified files.
			\Breeze_MinificationCache::clear_minification();

			// Clear normal cache.
			\Breeze_PurgeCache::breeze_cache_flush();

			// record results.
			update_option( 'mainwp_cache_control_last_purged', time() );

			// Return success message.
			return $this->purge_result( $success_message, 'SUCCESS' );

		} else {
			// Return error message.
			return $this->purge_result( $error_message, 'ERROR' );
		}
	}

	/**
	 * Purge WP-Rocket cache.
	 *
	 * @return array Purge results array.
	 */
	public function wprocket_auto_cache_purge() {

		$success_message = 'WP Rocket => Cache auto cleared on: (' . current_time( 'mysql' ) . ')';
		$error_message   = 'WP Rocket => There was an issue purging your cache.';

		// Purge Cache if action is set to "1".
		$action       = get_option( 'mainwp_child_auto_purge_cache', false );
		$purge_result = array();

		if ( 1 == $action ) {
			$purge_result = MainWP_Child_WP_Rocket::instance()->purge_cache_all();
		}

		// Save last purge time to database on success.
		if ( 'SUCCESS' === $purge_result['result'] ) {
			update_option( 'mainwp_cache_control_last_purged', time() );

			// Return success message.
			return $this->purge_result( $success_message, 'SUCCESS' );

		} else {
			// Return error message.
			return $this->purge_result( $error_message, 'ERROR' );
		}
	}

	/**
	 * Record last Purge.
	 *
	 * Create log file & Save in /Upload dir.
	 *
	 * @param array $information Array containing the data to be sent to the Dashboard.
	 */
	public function record_results( $information ) {

		// Setup timezone and upload directory for logs.
		$ti = wp_timezone();
		if ( is_array( $ti ) && isset( $ti['timezone'] ) ) {
			date_default_timezone_set( $ti['timezone'] ); // phpcs:ignore -- use core function.
		}

		// Save Cache Control Log Data.
		update_option( 'mainwp_cache_control_log', wp_json_encode( $information ) );
	}
}
