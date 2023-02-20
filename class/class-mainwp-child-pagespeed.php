<?php
/**
 * MainWP Page Speed
 *
 * MainWP Page Speed extension handler.
 * Extension URL: https://mainwp.com/extension/page-speed/
 *
 * @package MainWP\Child
 *
 * Credits
 *
 * Plugin-Name: Google Pagespeed Insights
 * Plugin-URI: http://mattkeys.me
 * Author: Matt Keys
 * Author URI: http://mattkeys.me
 * License: GPLv2 or later
 */

namespace MainWP\Child;

// phpcs:disable PSR1.Classes.ClassDeclaration, WordPress.WP.AlternativeFunctions --  to use external code, third party credit.

/**
 * Class MainWP_Child_Pagespeed
 *
 * MainWP Page Speed extension handler.
 */
class MainWP_Child_Pagespeed {

	/**
	 * Public static variable to hold the single instance of the class.
	 *
	 * @var mixed Default null
	 */
	public static $instance = null;

	/**
	 * Public variable to hold the infomration if the Google Pagespeed Insights plugin is installed on the child site.
	 *
	 * @var bool If Google Pagespeed Insights intalled, return true, if not, return false.
	 */
	public $is_plugin_installed = false;

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
	 * MainWP_Child_Pagespeed constructor.
	 *
	 * Run any time class is called.
	 *
	 * @return void
	 */
	public function __construct() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		if ( is_plugin_active( 'google-pagespeed-insights/google-pagespeed-insights.php' ) ) {
			$this->is_plugin_installed = true;
		}

		if ( ! $this->is_plugin_installed ) {
			return;
		}

		add_filter( 'mainwp_site_sync_others_data', array( $this, 'sync_others_data' ), 10, 2 );

		add_action( 'mainwp_child_deactivation', array( $this, 'child_deactivation' ) );
	}

	/**
	 * Method sync_others_data()
	 *
	 * Sync the Google Pagespeed Insights plugin data.
	 *
	 * @param  array $information Array containing the sync information.
	 * @param  array $data        Array containing the Google Pagespeed Insights plugin data to be synced.
	 *
	 * @return array $information Array containing the sync information.
	 */
	public function sync_others_data( $information, $data = array() ) {
		if ( isset( $data['syncPageSpeedData'] ) && $data['syncPageSpeedData'] ) {
			try {
				$information['syncPageSpeedData'] = $this->get_sync_data();
			} catch ( \Exception $e ) {
				// ok!
			}
		}
		return $information;
	}

	/**
	 * Method child_deactivation()
	 *
	 * Unschedule scheduled events on MainWP Child plugin deactivation.
	 */
	public function child_deactivation() {
		$sched = wp_next_scheduled( 'mainwp_child_pagespeed_cron_check' );
		if ( $sched ) {
			wp_unschedule_event( $sched, 'mainwp_child_pagespeed_cron_check' );
		}
	}

	/**
	 * Method init()
	 *
	 * Initiate action hooks.
	 *
	 * @return void
	 */
	public function init() {
		if ( ! $this->is_plugin_installed ) {
			return;
		}

		if ( 'hide' === get_option( 'mainwp_pagespeed_hide_plugin' ) ) {
			add_filter( 'all_plugins', array( $this, 'hide_plugin' ) );
			add_action( 'admin_menu', array( $this, 'hide_menu' ), 999 );
		}

		$this->init_cron();
	}

	/**
	 * Method hide_plugin()
	 *
	 * Remove the Google Pagespeed Insights plugin from the list of all plugins when the plugin is hidden.
	 *
	 * @param array $plugins Array containing all installed plugins.
	 *
	 * @return array $plugins Array containing all installed plugins without the Google Pagespeed Insights plugin.
	 */
	public function hide_plugin( $plugins ) {
		foreach ( $plugins as $key => $value ) {
			$plugin_slug = basename( $key, '.php' );
			if ( 'google-pagespeed-insights' === $plugin_slug ) {
				unset( $plugins[ $key ] );
			}
		}

		return $plugins;
	}

	/**
	 * Method hide_menu()
	 *
	 * Remove the Google Pagespeed Insights menu item when the plugin is hidden.
	 */
	public function hide_menu() {

		/**
		 * WordPress submenu array.
		 *
		 * @global object
		 */
		global $submenu;

		if ( isset( $submenu['tools.php'] ) ) {
			foreach ( $submenu['tools.php'] as $key => $menu ) {
				if ( 'google-pagespeed-insights' == $menu[2] ) {
					unset( $submenu['tools.php'][ $key ] );
					break;
				}
			}
		}
	}

	/**
	 * Method init_cron()
	 *
	 * Schedule daily Page Speed checks.
	 */
	public function init_cron() {
		add_action( 'mainwp_child_pagespeed_cron_check', array( __CLASS__, 'pagespeed_cron_check' ) );
		$sched = wp_next_scheduled( 'mainwp_child_pagespeed_cron_check' );
		if ( false === $sched ) {
			wp_schedule_event( time(), 'daily', 'mainwp_child_pagespeed_cron_check' );
		}
	}

	/**
	 * Method pagespeed_cron_check()
	 *
	 * Schedule single Page Speed check event.
	 */
	public static function pagespeed_cron_check() {
		$count = get_option( 'mainwp_child_pagespeed_count_checking' );
		if ( $count >= 7 ) {
			$recheck = true;
			$count   = 0;
		} else {
			$recheck = false;
			$count ++;
		}
		update_option( 'mainwp_child_pagespeed_count_checking', $count );

		$worker_args = array(
			array(),
			false,
			$recheck,
		);
		wp_schedule_single_event( time(), 'googlepagespeedinsightschecknow', $worker_args );
	}

	/**
	 * Method actions()
	 *
	 * Fire off certain Google Pagespeed Insights plugin actions.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Pagespeed::save_settings() Save the plugin settings.
	 * @uses \MainWP\Child\MainWP_Child_Pagespeed::set_showhide() Hide or unhide the Google Pagespeed Insights plugin.
	 * @uses \MainWP\Child\MainWP_Child_Pagespeed::get_sync_data() Get the Google Pagespeed Insights plugin data and store it in the sync request.
	 * @uses \MainWP\Child\MainWP_Child_Pagespeed::check_pages() Check pages page speed.
	 * @uses \MainWP\Child\MainWP_Helper::write()
	 */
	public function action() {
		$information = array();
		if ( ! defined( 'GPI_DIRECTORY' ) ) {
			$information['error'] = 'Please install Google Pagespeed Insights plugin on child website';
			MainWP_Helper::write( $information );
		}
		if ( isset( $_POST['mwp_action'] ) ) {
			$mwp_action = ! empty( $_POST['mwp_action'] ) ? sanitize_text_field( wp_unslash( $_POST['mwp_action'] ) ) : '';
			switch ( $mwp_action ) {
				case 'save_settings':
					$information = $this->save_settings();
					break;
				case 'set_showhide':
					$information = $this->set_showhide();
					break;
				case 'sync_data':
					$information = $this->get_sync_data();
					break;
				case 'check_pages':
					$information = $this->check_pages();
					break;
			}
		}
		MainWP_Helper::write( $information );
	}

	/**
	 * Method save_settings()
	 *
	 * Save the plugin settings.
	 *
	 * @uses MainWP_Child_Pagespeed::delete_data() Delete reports or all plugin data.
	 *
	 * @used-by MainWP_Child_Pagespeed::actions() Fire off certain Google Pagespeed Insights plugin actions.
	 *
	 * @return array Action result.
	 */
	public function save_settings() { // phpcs:ignore -- Current complexity is the only way to achieve desired results, pull request solutions appreciated.
		$current_values = get_option( 'gpagespeedi_options' );
		$checkstatus    = apply_filters( 'gpi_check_status', false );
		if ( $checkstatus ) {
			return array( 'result' => 'RUNNING' );
		}
		$information = array();

		$settings = isset( $_POST['settings'] ) ? json_decode( base64_decode( wp_unslash( $_POST['settings'] ) ), true ) : array(); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode required for backwards compatibility.

		if ( is_array( $settings ) ) {

			if ( isset( $settings['api_key'] ) && ! empty( $settings['api_key'] ) ) {
				$current_values['google_developer_key'] = $settings['api_key'];
			}

			if ( isset( $settings['response_language'] ) ) {
				$current_values['response_language'] = $settings['response_language'];
			}

			$current_values['strategy'] = isset( $_POST['strategy'] ) ? sanitize_text_field( wp_unslash( $_POST['strategy'] ) ) : '';

			if ( isset( $settings['store_screenshots'] ) ) {
				$current_values['store_screenshots'] = $settings['store_screenshots'];
			}
			if ( isset( $settings['use_schedule'] ) ) {
				$current_values['use_schedule'] = $settings['use_schedule'];
			}

			if ( isset( $settings['max_execution_time'] ) ) {
				$current_values['max_execution_time'] = $settings['max_execution_time'];
			}

			if ( isset( $settings['max_run_time'] ) ) {
				$current_values['max_run_time'] = $settings['max_run_time'];
			}

			if ( isset( $settings['heartbeat'] ) ) {
				$current_values['heartbeat'] = $settings['heartbeat'];
			}

			if ( isset( $settings['delay_time'] ) ) {
				$current_values['sleep_time'] = $settings['delay_time'];
			}

			if ( isset( $settings['log_exception'] ) ) {
				$current_values['log_api_errors'] = ( $settings['log_exception'] ) ? true : false;
			}

			if ( isset( $settings['report_expiration'] ) ) {
				$current_values['recheck_interval'] = $settings['report_expiration'];
			}

			if ( isset( $settings['check_report'] ) ) {
				if ( is_array( $settings['check_report'] ) ) {
					$current_values['check_pages']       = in_array( 'page', $settings['check_report'] ) ? true : false;
					$current_values['check_posts']       = in_array( 'post', $settings['check_report'] ) ? true : false;
					$current_values['check_categories']  = in_array( 'category', $settings['check_report'] ) ? true : false;
					$current_values['check_custom_urls'] = in_array( 'custom_urls', $settings['check_report'] ) ? true : false;
				} else {
					$current_values['check_pages']       = false;
					$current_values['check_posts']       = false;
					$current_values['check_categories']  = false;
					$current_values['check_custom_urls'] = false;
				}
			}

			if ( isset( $settings['delete_data'] ) && ! empty( $settings['delete_data'] ) ) {
				$this->delete_data( $settings['delete_data'] );
			}

			if ( update_option( 'gpagespeedi_options', $current_values ) ) {
				$information['result'] = 'SUCCESS';
			} else {
				$information['result'] = 'NOTCHANGE';
			}
		}

		$strategy = $current_values['strategy'];

		$result = $this->get_sync_data( $strategy );

		$information['data'] = $result['data'];

		return $information;
	}

	/**
	 * Method delete_data()
	 *
	 * Delete reports or all plugin data.
	 *
	 * @used-by MainWP_Child_Pagespeed::save_settings() Save the plugin settings.
	 *
	 * @param string $what Contains information about what to delete, just reports or everything.
	 */
	public function delete_data( $what ) {

		/**
		 * WordPress Database instance.
		 *
		 * @global object $wpdb
		 */
		global $wpdb;

		$gpi_page_stats     = $wpdb->prefix . 'gpi_page_stats';
		$gpi_page_reports   = $wpdb->prefix . 'gpi_page_reports';
		$gpi_page_blacklist = $wpdb->prefix . 'gpi_page_blacklist';
		// phpcs:disable -- safe queries. Required to achieve desired results, pull request solutions appreciated.
		if ( 'purge_reports' === $what ) {
			$wpdb->query( "TRUNCATE TABLE $gpi_page_stats" );
			$wpdb->query( "TRUNCATE TABLE $gpi_page_reports" );
		} elseif ( 'purge_everything' === $what ) {
			$wpdb->query( "TRUNCATE TABLE $gpi_page_stats" );
			$wpdb->query( "TRUNCATE TABLE $gpi_page_reports" );
			$wpdb->query( "TRUNCATE TABLE $gpi_page_blacklist" );
		}
		// phpcs:enable
	}

	/**
	 * Method set_showhide()
	 *
	 * Hide or unhide the Google Pagespeed Insights plugin.
	 *
	 * @return array Action result.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::update_option()
	 *
	 * @used-by MainWP_Child_Pagespeed::actions() Fire off certain Google Pagespeed Insights plugin actions.
	 */
	public function set_showhide() {
		$hide = isset( $_POST['showhide'] ) && ( 'hide' === $_POST['showhide'] ) ? 'hide' : '';
		MainWP_Helper::update_option( 'mainwp_pagespeed_hide_plugin', $hide );
		$information['result'] = 'SUCCESS';

		return $information;
	}

	/**
	 * Method check_pages()
	 *
	 * Initiate the check pages page speed.
	 *
	 * @uses MainWP_Child_Pagespeed::do_check_pages() If needed, force recheck proces, if not just check.
	 *
	 * @used-by MainWP_Child_Pagespeed::actions() Fire off certain Google Pagespeed Insights plugin actions.
	 *
	 * @return array Action result.
	 */
	public function check_pages() {
		if ( isset( $_POST['force_recheck'] ) && ! empty( $_POST['force_recheck'] ) ) {
			$recheck = true;
		} else {
			$recheck = false;
		}
		$information = $this->do_check_pages( $recheck );
		if ( isset( $information['checked_pages'] ) && $information['checked_pages'] ) {
			$information['result'] = 'SUCCESS';
		}
		return $information;
	}

	/**
	 * Method do_check_pages()
	 *
	 * Check or force re-check pages page speed.
	 *
	 * @param bool $forceRecheck If true, force recheck process, if false, just regular check.
	 *
	 * @return array Action result.
	 */
	public function do_check_pages( $forceRecheck = false ) {
		$information = array();
		if ( defined( 'GPI_DIRECTORY' ) ) {
			$checkstatus = apply_filters( 'gpi_check_status', false );
			if ( $checkstatus ) {
				$information['error'] = esc_html__( 'The API is busy checking other pages, please try again later.', 'gpagespeedi' );
			} else {
				do_action( 'run_gpi', $forceRecheck );
				$information['checked_pages'] = 1;
			}
		}
		return $information;
	}

	/**
	 * Method get_sync_data()
	 *
	 * Get the Google Pagespeed Insights plugin data and store it in the sync request.
	 *
	 * @param string $strategy Contains the selected strategy (desktop, mobile or both).
	 *
	 * @uses MainWP_Child_Pagespeed::cal_pagespeed_data() Calculate page speed scores.
	 *
	 * @used-by MainWP_Child_Pagespeed::actions() Fire off certain Google Pagespeed Insights plugin actions.
	 *
	 * @return array Action result.
	 */
	public function get_sync_data( $strategy = '' ) {
		if ( empty( $strategy ) ) {
			$strategy = 'both';
		}

		$current_values = get_option( 'gpagespeedi_options' );
		$checkstatus    = apply_filters( 'gpi_check_status', false );
		if ( $checkstatus ) {
			return array( 'result' => 'RUNNING' );
		}

		$information = array();
		$bad_key     = ( $current_values['bad_api_key'] || empty( $current_values['google_developer_key'] ) );
		$data        = array( 'bad_api_key' => $bad_key );

		if ( 'both' === $strategy || 'desktop' === $strategy ) {
			$result = self::cal_pagespeed_data( 'desktop' );
			if ( ! empty( $result ) && is_array( $result ) ) {
				$data['desktop_score']         = $result['average_score'];
				$data['desktop_total_pages']   = $result['total_pages'];
				$data['desktop_last_modified'] = $result['last_modified'];
			}
		}
		if ( 'both' === $strategy || 'mobile' === $strategy ) {
			$result = self::cal_pagespeed_data( 'mobile' );
			if ( ! empty( $result ) && is_array( $result ) ) {
				$data['mobile_score']         = $result['average_score'];
				$data['mobile_total_pages']   = $result['total_pages'];
				$data['mobile_last_modified'] = $result['last_modified'];
			}
		}

		$information['data'] = $data;

		return $information;
	}

	/**
	 * Method cal_pagespeed_data()
	 *
	 * Calculate page speed scores.
	 *
	 * @param string $strategy Contains the selected strategy (desktop, mobile or both).
	 *
	 * @used-by MainWP_Child_Pagespeed::get_sync_data() Get the Google Pagespeed Insights plugin data and store it in the sync request.
	 *
	 * @return array Array containing data including last modified timespamp, average score and number of pages.
	 */
	public static function cal_pagespeed_data( $strategy ) {

		/**
		 * WordPress Database instance.
		 *
		 * @global object $wpdb
		 */
		global $wpdb;

		if ( ! defined( 'GPI_DIRECTORY' ) ) {
			return false;
		}

		if ( 'desktop' !== $strategy && 'mobile' !== $strategy ) {
			return false;
		}

		$score_column = $strategy . '_score';

		$data_typestocheck = self::get_filter_options( 'all' );

		$gpi_page_stats = $wpdb->prefix . 'gpi_page_stats';
		if ( ! empty( $data_typestocheck ) ) {

			$allpagedata = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, URL, $score_column FROM $gpi_page_stats WHERE ( $data_typestocheck[0] )", // phpcs:ignore -- safe query.
					$data_typestocheck[1]
				),
				ARRAY_A
			);
		} else {
			$allpagedata = array();
		}

		$reports_typestocheck = self::get_filter_options( 'all' );
		$gpi_page_reports     = $wpdb->prefix . 'gpi_page_reports';

		if ( ! empty( $reports_typestocheck ) ) {

			$allpagereports = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT     r.rule_key, r.rule_name FROM $gpi_page_stats d INNER JOIN $gpi_page_reports r ON r.page_id = d.ID AND r.strategy = '$strategy' WHERE ( $reports_typestocheck[0] )", // phpcs:ignore -- safe query.
					$reports_typestocheck[1]
				),
				ARRAY_A
			);
		} else {
			$allpagereports = array();
		}

		$total_pages   = count( $allpagedata );
		$total_scores  = 0;
		$average_score = 0;

		if ( ! empty( $total_pages ) && ! empty( $allpagereports ) ) {

			foreach ( $allpagedata as $key => $pagedata ) {
				$total_scores = $total_scores + $pagedata[ $score_column ];
			}

			$average_score = number_format( $total_scores / $total_pages );
		}

		switch ( $strategy ) {
			case 'mobile':
				$nullcheck = 'mobile_score IS NOT NULL';
				$_select   = ' max(mobile_last_modified) as last_modified ';
				break;
			case 'desktop':
				$nullcheck = 'desktop_score IS NOT NULL';
				$_select   = ' max(desktop_last_modified) as last_modified ';
				break;
		}

		if ( ! is_null( $reports_typestocheck ) ) {
			$gpi_page_stats = $wpdb->prefix . 'gpi_page_stats';
			$data           = $wpdb->get_results( $wpdb->prepare( "SELECT $_select FROM $gpi_page_stats WHERE ( $reports_typestocheck[0] ) AND $nullcheck", $reports_typestocheck[1] ), ARRAY_A ); // phpcs:ignore -- safe query.
		}

		return array(
			'last_modified' => is_array( $data[0] ) && isset( $data[0]['last_modified'] ) ? $data[0]['last_modified'] : 0,
			'average_score' => $average_score,
			'total_pages'   => $total_pages,
		);
	}

	/**
	 * Method get_filter_options()
	 *
	 * @param  string $restrict_type Contains the restricted types.
	 *
	 * @return array Array containing the list of item types to check (posts, pages, categories,...).
	 */
	public static function get_filter_options( $restrict_type = 'all' ) { // phpcs:ignore -- Current complexity is the only way to achieve desired results, pull request solutions appreciated.

		$types        = array();
		$gpi_options  = get_option( 'gpagespeedi_options' );
		$typestocheck = array();

		if ( $gpi_options['check_pages'] ) {
			if ( 'all' == $restrict_type || 'ignored' == $restrict_type || 'pages' == $restrict_type ) {
				$typestocheck[] = 'type = %s';
				$types[1][]     = 'page';
			}
		}

		if ( $gpi_options['check_posts'] ) {
			if ( 'all' == $restrict_type || 'ignored' == $restrict_type || 'posts' == $restrict_type ) {
				$typestocheck[] = 'type = %s';
				$types[1][]     = 'post';
			}
		}

		if ( $gpi_options['check_categories'] ) {
			if ( 'all' == $restrict_type || 'ignored' == $restrict_type || 'categories' == $restrict_type ) {
				$typestocheck[] = 'type = %s';
				$types[1][]     = 'category';
			}
		}
		if ( $gpi_options['cpt_whitelist'] ) {
			if ( 'all' == $restrict_type || 'ignored' == $restrict_type || stristr( $restrict_type, 'gpi_custom_posts' ) ) {

				$cpt_whitelist_arr = false;
				if ( ! empty( $gpi_options['cpt_whitelist'] ) ) {
					$cpt_whitelist_arr = unserialize( $gpi_options['cpt_whitelist'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- unserialize usage required to achieve desired results, pull request solutions appreciated.
				}
				$args              = array(
					'public'   => true,
					'_builtin' => false,
				);
				$custom_post_types = get_post_types( $args, 'names', 'and' );
				if ( 'gpi_custom_posts' != $restrict_type && 'all' != $restrict_type && 'ignored' != $restrict_type ) {
					$restrict_type = str_replace( 'gpi_custom_posts-', '', $restrict_type );
					foreach ( $custom_post_types as $post_type ) {
						if ( $cpt_whitelist_arr && in_array( $post_type, $cpt_whitelist_arr ) ) {
							if ( $post_type == $restrict_type ) {
								$typestocheck[] = 'type = %s';
								$types[1][]     = $custom_post_types[ $post_type ];
							}
						}
					}
				} else {
					foreach ( $custom_post_types as $post_type ) {
						if ( $cpt_whitelist_arr && in_array( $post_type, $cpt_whitelist_arr ) ) {
							$typestocheck[] = 'type = %s';
							$types[1][]     = $custom_post_types[ $post_type ];
						}
					}
				}
			}
		}

		if ( $gpi_options['check_custom_urls'] ) {

			/**
			 * WordPress Database instance.
			 *
			 * @global object $wpdb
			 */
			global $wpdb;

			$custom_url_types = $wpdb->get_col( 'SELECT DISTINCT type FROM ' . $wpdb->prefix . 'gpi_custom_urls ' );
			if ( ! empty( $custom_url_types ) ) {
				foreach ( $custom_url_types as $custom_url_type ) {
					$typestocheck[] = 'type = %s';
					$types[1][]     = $custom_url_type;
				}
			}
		}

		if ( ! empty( $typestocheck ) ) {
			$types[0] = '';
			foreach ( $typestocheck as $type ) {
				if ( ! is_array( $type ) ) {
					$types[0] .= $type . ' OR ';
				} else {
					foreach ( $type as $custom_post_type ) {
						$types[0]  .= 'type = %s OR ';
						$types[1][] = $custom_post_type;
					}
				}
			}
			$types[0] = rtrim( $types[0], ' OR ' );
			return $types;
		}
		return null;
	}

}
