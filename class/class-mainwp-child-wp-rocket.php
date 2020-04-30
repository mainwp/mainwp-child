<?php

/*
 *
 * Credits
 *
 * Plugin-Name: WP Rocket
 * Plugin URI: https://wp-rocket.me
 * Author: WP Media
 * Author URI: http://wp-media.me
 * Licence: GPLv2 or later
 *
 * The code is used for the MainWP Rocket Extension
 * Extension URL: https://mainwp.com/extension/rocket/
 *
*/

class MainWP_Child_WP_Rocket {
	public static $instance = null;
    public $is_plugin_installed = false;

	public static function Instance() {
		if ( null === MainWP_Child_WP_Rocket::$instance ) {
			MainWP_Child_WP_Rocket::$instance = new MainWP_Child_WP_Rocket();
		}

		return MainWP_Child_WP_Rocket::$instance;
	}

	public function __construct() {
        if ( is_plugin_active( 'wp-rocket/wp-rocket.php' ) ) {
            $this->is_plugin_installed = true;
        }
	}

	public function init() {
//		if ( get_option( 'mainwp_wprocket_ext_enabled' ) !== 'Y' ) {
//			return;
//		}

        if ( ! $this->is_plugin_installed ) {
            return;
        }

        add_filter( 'mainwp-site-sync-others-data', array( $this, 'syncOthersData' ), 10, 2 );

		if ( get_option( 'mainwp_wprocket_hide_plugin' ) === 'hide' ) {
			add_filter( 'all_plugins', array( $this, 'all_plugins' ) );
			add_action( 'admin_menu', array( $this, 'remove_menu' ) );
			add_filter( 'site_transient_update_plugins', array( &$this, 'remove_update_nag' ) );
            add_filter( 'mainwp_child_hide_update_notice', array( &$this, 'hide_update_notice' ) );
			add_action( 'wp_before_admin_bar_render', array( $this, 'wp_before_admin_bar_render' ), 99 );
			add_action( 'admin_init', array( $this, 'remove_notices' ) );
		}
	}

	function get_rocket_default_options() {
		  return array(
                'cache_mobile'             => 1,
				'do_caching_mobile_files'     => 0,
                'cache_logged_user'        => 0,
                'cache_ssl'                => 0,
				'emoji'					  => 0,
                'embeds'                   => 1,
                'control_heartbeat' => 0,
                'heartbeat_site_behavior'     => 'reduce_periodicity',
				'heartbeat_admin_behavior'    => 'reduce_periodicity',
				'heartbeat_editor_behavior'   => 'reduce_periodicity',
				'varnish_auto_purge' => 0,
				'manual_preload' => 0,
				'automatic_preload' => 0,
				'sitemap_preload' => 0,
				'sitemap_preload_url_crawl' => 500000,
				'sitemaps' => array(),
				'database_revisions' => 0,
				'database_auto_drafts' => 0,
				'database_trashed_posts' => 0,
				'database_spam_comments' => 0,
				'database_trashed_comments' => 0,
				'database_expired_transients' => 0,
				'database_all_transients' => 0,
				'database_optimize_tables' => 0,
				'schedule_automatic_cleanup' => 0,
				'automatic_cleanup_frequency' => '',
                'cache_reject_uri'         => array(),
                'cache_reject_cookies'     => array(),
                'cache_reject_ua'          => array(),
                'cache_query_strings'      => array(),
                'cache_purge_pages'        => array(),
                'purge_cron_interval'      => 10,
                'purge_cron_unit'          => 'HOUR_IN_SECONDS',
                'exclude_css'              => array(),
                'exclude_js'               => array(),
                'exclude_inline_js'               => array(),
				'async_css'					=> 0,
            	'defer_all_js'              => 0,
				'defer_all_js_safe'			=> 1,
                'critical_css'              => '',
                'deferred_js_files'        => array(),
                'lazyload'          	   => 0,
                'lazyload_iframes'         => 0,
				'lazyload_youtube'			=>0,
                'minify_css'               => 0,
//                'minify_css_key'           => $minify_css_key,
                'minify_concatenate_css'	  => 0,
                //'minify_css_combine_all'   => 0,
                'minify_css_legacy'			  => 0,
                'minify_js'                => 0,
//                'minify_js_key'            => $minify_js_key,
                'minify_js_in_footer'      => array(),
                'minify_concatenate_js'		  => 0,
                'minify_js_combine_all'    => 0,
                //'minify_js_legacy'			  => 0,
                'minify_google_fonts'      => 0,
                'minify_html'              => 0,
                'remove_query_strings'     => 0,
                'dns_prefetch'             => 0,
                'cdn'                      => 0,
                'cdn_cnames'               => array(),
                'cdn_zone'                 => array(),
                //'cdn_ssl'                  => 0,
                'cdn_reject_files'         => array(),
                'do_cloudflare'		   	   => 0,
                'cloudflare_email'		   => '',
                'cloudflare_api_key'	   => '',
                'cloudflare_domain'	   	   => '',
                //'cloudflare_zone_id'          => '',
                'cloudflare_devmode'	   => 0,
                'cloudflare_protocol_rewrite' => 0,
                'cloudflare_auto_settings' => 0,
                'cloudflare_old_settings'  => 0,
                'do_beta'                  => 0,
			    'analytics_enabled'        => 1,
        );
	}

    // ok
	public function syncOthersData( $information, $data = array() ) {
        if ( isset( $data['syncWPRocketData'] ) && ( 'yes' === $data['syncWPRocketData'] ) ) {
            try{
                $data = array( 'rocket_boxes' => get_user_meta( $GLOBALS['current_user']->ID, 'rocket_boxes', true ));
                $information['syncWPRocketData'] = $data;
            } catch(Exception $e) {
            }
        }
		return $information;
	}

	function remove_notices() {
		$remove_hooks['admin_notices'] = array(
			'rocket_bad_deactivations'                    => 10,
			'rocket_warning_plugin_modification'          => 10,
			'rocket_plugins_to_deactivate'                => 10,
			'rocket_warning_using_permalinks'             => 10,
			'rocket_warning_wp_config_permissions'        => 10,
			'rocket_warning_advanced_cache_permissions'   => 10,
			'rocket_warning_advanced_cache_not_ours'      => 10,
			'rocket_warning_htaccess_permissions'         => 10,
			'rocket_warning_config_dir_permissions'       => 10,
			'rocket_warning_cache_dir_permissions'        => 10,
			'rocket_warning_minify_cache_dir_permissions' => 10,
			'rocket_thank_you_license'                    => 10,
			'rocket_need_api_key'                         => 10,
		);
		foreach ( $remove_hooks as $hook_name => $hooks ) {
			foreach ( $hooks as $method => $priority ) {
				MainWP_Helper::remove_filters_with_method_name( $hook_name, $method, $priority );
			}
		}
	}


	public function wp_before_admin_bar_render() {
		global $wp_admin_bar;
		$nodes = $wp_admin_bar->get_nodes();
		if ( is_array( $nodes ) ) {
			foreach ( $nodes as $node ) {
				if ( 'wp-rocket' === $node->parent || ( $node->id = 'wp-rocket' ) ) {
					$wp_admin_bar->remove_node( $node->id );
				}
			}
		}
	}

    function hide_update_notice( $slugs ) {
        $slugs[] = 'wp-rocket/wp-rocket.php';
        return $slugs;
    }

	function remove_update_nag( $value ) {
		if ( isset( $_POST['mainwpsignature'] ) ) {
			return $value;
		}

        if (! MainWP_Helper::is_screen_with_update()) {
            return $value;
        }

		if ( isset( $value->response['wp-rocket/wp-rocket.php'] ) ) {
			unset( $value->response['wp-rocket/wp-rocket.php'] );
		}

		return $value;
	}

	public function isActivated() {
        if ( ! $this->is_plugin_installed ) {
            return false;
        }
//		if ( ! defined( 'WP_ROCKET_VERSION' ) || ! defined( 'WP_ROCKET_SLUG' ) ) {
//			return false;
//		}

		return true;
	}

	public function remove_menu() {
		global $submenu;
		if ( isset( $submenu['options-general.php'] ) ) {
			foreach ( $submenu['options-general.php'] as $index => $item ) {
				if ( 'wprocket' === $item[2] ) {
					unset( $submenu['options-general.php'][ $index ] );
					break;
				}
			}
		}
		$pos = stripos( $_SERVER['REQUEST_URI'], 'options-general.php?page=wprocket' );
		if ( false !== $pos ) {
			wp_redirect( get_option( 'siteurl' ) . '/wp-admin/index.php' );
			exit();
		}
	}

	public function all_plugins( $plugins ) {
		foreach ( $plugins as $key => $value ) {
			$plugin_slug = basename( $key, '.php' );
			if ( 'wp-rocket' === $plugin_slug ) {
				unset( $plugins[ $key ] );
			}
		}

		return $plugins;
	}

	public function action() {

        if ( ! $this->is_plugin_installed ) {
			MainWP_Helper::write( array( 'error' => __( 'Please install WP Rocket plugin on child website', $this->plugin_translate ) ) );
			return;
		}

		$information = array();


		if ( isset( $_POST['mwp_action'] ) ) {
//			MainWP_Helper::update_option( 'mainwp_wprocket_ext_enabled', 'Y' );
            try {
                switch ( $_POST['mwp_action'] ) {
                    case 'set_showhide':
                        $information = $this->set_showhide();
                        break;
                    case 'purge_cloudflare':
                        $information = $this->purge_cloudflare();
                        break;
                    case 'purge_all':
                        $information = $this->purge_cache_all();
                        break;
                    case 'preload_cache':
                        $information = $this->preload_cache();
                        break;
                    case 'generate_critical_css':
                        $information = $this->generate_critical_css();
                        break;
                    case 'save_settings':
                        $information = $this->save_settings();
                        break;
                    case "load_existing_settings":
                        $information = $this->load_existing_settings();
                        break;
                    case 'optimize_database':
                        $information = $this->optimize_database();
                        break;
                    case 'get_optimize_info':
                        $information = $this->get_optimize_info();
                        break;
                    case 'purge_opcache':
                        $information = $this->do_admin_post_rocket_purge_opcache();
                        break;
                }
            } catch(Exception $e) {
                $information = array( 'error' => $e->getMessage() );
            }
		}
		MainWP_Helper::write( $information );
	}

	function set_showhide() {
		$hide = isset( $_POST['showhide'] ) && ( $_POST['showhide'] === 'hide' ) ? 'hide' : '';
		MainWP_Helper::update_option( 'mainwp_wprocket_hide_plugin', $hide );
		$information['result'] = 'SUCCESS';

		return $information;
	}

    function do_admin_post_rocket_purge_opcache() {
        if ( function_exists( 'opcache_reset' ) ) {
            @opcache_reset();
        } else {
            return array('error' => 'The host do not support the function reset opcache.');
        }
        return array('result' => 'SUCCESS');
    }

	function purge_cloudflare() {
		if ( function_exists( 'rocket_purge_cloudflare' ) ) {
			// Purge CloudFlare
			rocket_purge_cloudflare();

			return array( 'result' => 'SUCCESS' );
		} else {
			return array( 'error' => 'function_not_exist' );
		}
	}

	function purge_cache_all() {
		if ( function_exists( 'rocket_clean_domain' ) || function_exists( 'rocket_clean_minify' ) || function_exists( 'create_rocket_uniqid' ) ) {
			set_transient( 'rocket_clear_cache', 'all', HOUR_IN_SECONDS );
			// Remove all cache files
			rocket_clean_domain();

			// Remove all minify cache files
			rocket_clean_minify();

			// Remove cache busting files.
			if ( function_exists( 'rocket_clean_cache_busting' )) {
				rocket_clean_cache_busting();
			}

            if ( !function_exists( 'rocket_dismiss_boxes' ) && defined('WP_ROCKET_ADMIN_PATH')) {
				require_once WP_ROCKET_ADMIN_PATH . 'admin.php';
			}

			// to fix
			include_once( ABSPATH . '/wp-admin/includes/template.php' );
			
			// Generate a new random key for minify cache file
			$options                   = get_option( WP_ROCKET_SLUG );
			$options['minify_css_key'] = create_rocket_uniqid();
			$options['minify_js_key']  = create_rocket_uniqid();
			remove_all_filters( 'update_option_' . WP_ROCKET_SLUG );
			update_option( WP_ROCKET_SLUG, $options );
			rocket_dismiss_box( 'rocket_warning_plugin_modification' );

			return array( 'result' => 'SUCCESS' );
		} else {
			return array( 'error' => 'function_not_exist' );
		}
	}

	function preload_cache() {
        MainWP_Helper::check_functions( array( 'run_rocket_sitemap_preload', 'run_rocket_bot' ) );
        MainWP_Helper::check_classes_exists('WP_Rocket\Preload\Full_Process');

        $preload_process = new WP_Rocket\Preload\Full_Process();
        MainWP_Helper::check_methods($preload_process, array( 'is_process_running'));

        if ( $preload_process->is_process_running() ) {
            return array( 'result' => 'RUNNING' );
        }

        delete_transient( 'rocket_preload_errors' );
        run_rocket_bot( 'cache-preload', '' );
        run_rocket_sitemap_preload();
        return array( 'result' => 'SUCCESS' );
	}

    function generate_critical_css() {
        MainWP_Helper::check_classes_exists( array( 'WP_Rocket\Subscriber\Optimization\Critical_CSS_Subscriber',
                                                    'WP_Rocket\Optimization\CSS\Critical_CSS',
                                                    'WP_Rocket\Optimization\CSS\Critical_CSS_Generation',
                                                    'WP_Rocket\Admin\Options',
                                                    'WP_Rocket\Admin\Options_Data'
                                                ));

        $critical_css = new WP_Rocket\Optimization\CSS\Critical_CSS( new WP_Rocket\Optimization\CSS\Critical_CSS_Generation() );
        $options_api = new WP_Rocket\Admin\Options( 'wp_rocket_' );
    	$options     = new WP_Rocket\Admin\Options_Data( $options_api->get( 'settings', array() ) );

        $sitemap_preload = new WP_Rocket\Subscriber\Optimization\Critical_CSS_Subscriber( $critical_css, $options );

        MainWP_Helper::check_properties($sitemap_preload, 'critical_css');
        MainWP_Helper::check_methods($sitemap_preload->critical_css, 'process_handler');

        $sitemap_preload->critical_css->process_handler();

        return array( 'result' => 'SUCCESS' );
	}

	function save_settings() {
		$options = maybe_unserialize( base64_decode( $_POST['settings'] ) );
		if ( ! is_array( $options ) || empty( $options ) ) {
			return array( 'error' => 'INVALID_OPTIONS' );
		}

		$old_values = get_option( WP_ROCKET_SLUG );

		$defaults_fields = $this->get_rocket_default_options();
		foreach ( $old_values as $field => $value ) {
			if ( ! isset( $defaults_fields[ $field ] ) ) { // keep other options
				$options[ $field ] = $value;
			}
		}


		update_option( WP_ROCKET_SLUG, $options );

        if (isset($_POST['do_database_optimization']) && !empty($_POST['do_database_optimization'])) {
			$this->optimize_database();
		}

		return array( 'result' => 'SUCCESS' );
	}

	function optimize_database() {

         MainWP_Helper::check_classes_exists( array( 'WP_Rocket\Admin\Database\Optimization',
                                                    'WP_Rocket\Admin\Database\Optimization_Process',
                                                    'WP_Rocket\Admin\Options',
                                                    'WP_Rocket\Admin\Options_Data'
                                                ));

        $process = new WP_Rocket\Admin\Database\Optimization_Process();
        $optimization = new WP_Rocket\Admin\Database\Optimization( $process );
        MainWP_Helper::check_methods( $optimization, array( 'process_handler', 'get_options' ) );

        $options_api = new WP_Rocket\Admin\Options( 'wp_rocket_' );
    	$options     = new WP_Rocket\Admin\Options_Data( $options_api->get( 'settings', array() ) );

        $items = array_filter( array_keys( $optimization->get_options() ), [ $options, 'get' ] );

		if ( !empty( $items ) ) {
            $optimization->process_handler( $items );
		}

        $return['result'] = 'SUCCESS';
		return $return;
	}

	function get_optimize_info() {

        MainWP_Helper::check_classes_exists( array( 'WP_Rocket\Admin\Database\Optimization',
                                                    'WP_Rocket\Admin\Database\Optimization_Process'
                                                ));

        $process = new WP_Rocket\Admin\Database\Optimization_Process();
        $optimization = new WP_Rocket\Admin\Database\Optimization( $process );
        MainWP_Helper::check_methods($optimization, 'count_cleanup_items');

        $information['optimize_info'] = array(
            'total_revisions'         => $optimization->count_cleanup_items( 'database_revisions' ),
            'total_auto_draft'         => $optimization->count_cleanup_items( 'database_auto_drafts' ),
            'total_trashed_posts'      => $optimization->count_cleanup_items( 'database_trashed_posts' ),
            'total_spam_comments'     => $optimization->count_cleanup_items( 'database_spam_comments' ),
            'total_trashed_comments'   => $optimization->count_cleanup_items( 'database_trashed_comments' ),
            'total_expired_transients' => $optimization->count_cleanup_items( 'database_expired_transients' ),
            'total_all_transients'     => $optimization->count_cleanup_items( 'database_all_transients' ),
            'total_optimize_tables'    => $optimization->count_cleanup_items( 'database_optimize_tables' )
        );

        $information['result'] = 'SUCCESS';
		return $information;
	}

	function load_existing_settings() {
		$options = get_option( WP_ROCKET_SLUG );
		return array('result' => 'SUCCESS', 'options' => $options);
	}

}

