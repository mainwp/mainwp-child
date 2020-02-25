<?php
if ( defined( 'MAINWP_DEBUG' ) && MAINWP_DEBUG === TRUE ) {
	@error_reporting( E_ALL );
	@ini_set( 'display_errors', TRUE );
	@ini_set( 'display_startup_errors', TRUE );
} else {
	if (isset($_REQUEST['mainwpsignature'])) {
		@ini_set( 'display_errors', FALSE );
		@error_reporting( 0 );
}
}

define( 'MAINWP_CHILD_NR_OF_COMMENTS', 50 );
define( 'MAINWP_CHILD_NR_OF_PAGES', 50 );

include_once( ABSPATH . '/wp-admin/includes/file.php' );
include_once( ABSPATH . '/wp-admin/includes/plugin.php' );


if ( isset( $_GET['skeleton_keyuse_nonce_key'] ) && isset( $_GET['skeleton_keyuse_nonce_hmac'] ) ) {
	$skeleton_keyuse_nonce_key  = intval( $_GET['skeleton_keyuse_nonce_key'] );
	$skeleton_keyuse_nonce_hmac = $_GET['skeleton_keyuse_nonce_hmac'];
	$skeleton_keycurrent_time   = intval( time() );

	if ( $skeleton_keycurrent_time >= $skeleton_keyuse_nonce_key && $skeleton_keycurrent_time <= ( $skeleton_keyuse_nonce_key + 30 ) ) {

		if ( strcmp( $skeleton_keyuse_nonce_hmac, hash_hmac( 'sha256', $skeleton_keyuse_nonce_key, NONCE_KEY ) ) === 0 ) {

			if ( ! function_exists( 'wp_verify_nonce' ) ) :

				/**
				 * Verify that correct nonce was used with time limit.
				 *
				 * The user is given an amount of time to use the token, so therefore, since the
				 * UID and $action remain the same, the independent variable is the time.
				 *
				 * @since 2.0.3
				 *
				 * @param string $nonce Nonce that was used in the form to verify
				 * @param string|int $action Should give context to what is taking place and be the same when nonce was created.
				 *
				 * @return false|int False if the nonce is invalid, 1 if the nonce is valid and generated between
				 *                   0-12 hours ago, 2 if the nonce is valid and generated between 12-24 hours ago.
				 */
				function wp_verify_nonce( $nonce, $action = - 1 ) {
					$nonce = (string) $nonce;
					$user  = wp_get_current_user();
					$uid   = (int) $user->ID;
					if ( ! $uid ) {
						/**
						 * Filter whether the user who generated the nonce is logged out.
						 *
						 * @since 3.5.0
						 *
						 * @param int $uid ID of the nonce-owning user.
						 * @param string $action The nonce action.
						 */
						$uid = apply_filters( 'nonce_user_logged_out', $uid, $action );
					}

					if ( empty( $nonce ) ) {

                        // To fix verify nonce conflict #1
                        // this is fake post field to fix some conflict of wp_verify_nonce()
                        // just return false to unverify nonce, does not exit
                        if ( isset($_POST[$action]) && ($_POST[$action] == 'mainwp-bsm-unverify-nonce')) {
                            return false;
                        }

                        // to help tracing the conflict verify nonce with other plugins
						@ob_start();
                        @debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
                        $stackTrace = "\n" . @ob_get_clean();
						die( '<mainwp>' . base64_encode( json_encode( array( 'error' => 'You dont send nonce: ' . $action . '<br/>Trace: ' .$stackTrace) ) ) . '</mainwp>' );
					}

                    // To fix verify nonce conflict #2
                    // this is fake nonce to fix some conflict of wp_verify_nonce()
                    // just return false to unverify nonce, does not exit
                    if ($nonce == 'mainwp-bsm-unverify-nonce') {
                        return false;
                    }


					$token = wp_get_session_token();
					$i     = wp_nonce_tick();

					// Nonce generated 0-12 hours ago
					$expected = substr( wp_hash( $i . '|' . $action . '|' . $uid . '|' . $token, 'nonce' ), - 12, 10 );
					if ( hash_equals( $expected, $nonce ) ) {
						return 1;
					}

					// Nonce generated 12-24 hours ago
					$expected = substr( wp_hash( ( $i - 1 ) . '|' . $action . '|' . $uid . '|' . $token, 'nonce' ), - 12, 10 );
					if ( hash_equals( $expected, $nonce ) ) {
						return 2;
					}

                    // To fix verify nonce conflict #3
                    // this is fake post field to fix some conflict of wp_verify_nonce()
                    // just return false to unverify nonce, does not exit
                    if ( isset($_POST[$action]) && ($_POST[$action] == 'mainwp-bsm-unverify-nonce')) {
                        return false;
                    }

                    @ob_start();
                    @debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
                    $stackTrace = "\n" . @ob_get_clean();

					// Invalid nonce
					die( '<mainwp>' . base64_encode( json_encode( array( 'error' => 'Invalid nonce! Try to use: ' . $action . '<br/>Trace: ' .$stackTrace) ) ) . '</mainwp>' );
				}
			endif;
		}
	}
}

class MainWP_Child {
	public static $version = '4.0.7';
	private $update_version = '1.5';

	private $callableFunctions = array(
		'stats'                 => 'getSiteStats',
		'upgrade'               => 'upgradeWP',
		'newpost'               => 'newPost',
		'deactivate'            => 'deactivate',
		'newuser'               => 'newUser',
		'newadminpassword'      => 'newAdminPassword',
		'installplugintheme'    => 'installPluginTheme',
		'upgradeplugintheme'    => 'upgradePluginTheme',
		'upgradetranslation'    => 'upgradeTranslation',
		'backup'                => 'backup',
		'backup_checkpid'       => 'backup_checkpid',
		'cloneinfo'             => 'cloneinfo',
		'security'              => 'getSecurityStats',
		'securityFix'           => 'doSecurityFix',
		'securityUnFix'         => 'doSecurityUnFix',
		'post_action'           => 'post_action',
		'get_all_posts'         => 'get_all_posts',
		'comment_action'        => 'comment_action',
		'comment_bulk_action'   => 'comment_bulk_action',
		'get_all_comments'      => 'get_all_comments',
		'get_all_themes'        => 'get_all_themes',
		'theme_action'          => 'theme_action',
		'get_all_plugins'       => 'get_all_plugins',
		'plugin_action'         => 'plugin_action',
		'get_all_pages'         => 'get_all_pages',
		'get_all_users'         => 'get_all_users',
		'user_action'           => 'user_action',
		'search_users'          => 'search_users',
		'get_terms'             => 'get_terms',
		'set_terms'             => 'set_terms',
		'insert_comment'        => 'insert_comment',
		'get_post_meta'         => 'get_post_meta',
		'get_total_ezine_post'  => 'get_total_ezine_post',
		'get_next_time_to_post' => 'get_next_time_to_post',
		'cancel_scheduled_post' => 'cancel_scheduled_post',
		'serverInformation'     => 'serverInformation',
		'maintenance_site'      => 'maintenance_site',
		'keyword_links_action'  => 'keyword_links_action',
		'branding_child_plugin' => 'branding_child_plugin',
		'code_snippet'          => 'code_snippet',
		'uploader_action'       => 'uploader_action',
		'wordpress_seo'         => 'wordpress_seo',
		'client_report'         => 'client_report',
		'createBackupPoll'      => 'backupPoll',
		'page_speed'            => 'page_speed',
		'woo_com_status'        => 'woo_com_status',
		'links_checker'         => 'links_checker',
		'wordfence'             => 'wordfence',
		'delete_backup'         => 'delete_backup',
		'update_values'         => 'update_values',
		'ithemes'               => 'ithemes',
		'updraftplus'           => 'updraftplus',
		'backup_wp'             => 'backup_wp',
		'backwpup'              => 'backwpup',
		'wp_rocket'             => 'wp_rocket',
		'settings_tools'        => 'settings_tools',
		'skeleton_key'          => 'skeleton_key',
		'custom_post_type'	=> 'custom_post_type',
        'backup_buddy'          => 'backup_buddy',
        'get_site_icon'         => 'get_site_icon',
        'vulner_checker'        => 'vulner_checker',
        'wp_staging'            => 'wp_staging',
		'disconnect'            => 'disconnect',
		'time_capsule'          => 'time_capsule',
        'extra_excution'        => 'extra_execution', // deprecated
        'extra_execution'        => 'extra_execution',
        'wpvivid_backuprestore'=>'wpvivid_backuprestore'
	);

	private $FTP_ERROR = 'Failed! Please, add FTP details for automatic updates.';

	private $callableFunctionsNoAuth = array(
		'stats' => 'getSiteStatsNoAuth',
	);

	private $posts_where_suffix;
	private $comments_and_clauses;
	private $plugin_slug;
	private $plugin_dir;
	private $slug;
	private $maxHistory = 5;

	private $filterFunction = null;
	public static $brandingTitle = null;

	public static $subPages;
	public static $subPagesLoaded = false;

	public function __construct( $plugin_file ) {
		$this->update();
        $this->load_all_options();
		$this->filterFunction = function($a) {
			if ($a == null) { return false; }
			if (is_object($a) && property_exists($a, "last_checked") && !property_exists($a, "checked"))
				return false;
			return $a;
		};
		$this->plugin_dir     = dirname( $plugin_file );
		$this->plugin_slug    = plugin_basename( $plugin_file );
		list ( $t1, $t2 ) = explode( '/', $this->plugin_slug );
		$this->slug = str_replace( '.php', '', $t2 );

		$this->posts_where_suffix   = '';
		$this->comments_and_clauses = '';
		add_action( 'template_redirect', array( $this, 'template_redirect' ) );
		add_action( 'init', array( &$this, 'check_login' ), 1 );
		add_action( 'init', array( &$this, 'parse_init' ), 9999 );
		add_action( 'admin_menu', array( &$this, 'admin_menu' ) );
		add_action( 'admin_init', array( &$this, 'admin_init' ) );
		add_action( 'admin_head', array( &$this, 'admin_head' ) );
		add_action( 'init', array( &$this, 'localization' ), 33 );
		add_action( 'pre_current_active_plugins', array( &$this, 'detect_premium_themesplugins_updates' ) ); // to support detect premium plugins update
        add_action( 'core_upgrade_preamble', array( &$this, 'detect_premium_themesplugins_updates' ) ); // to support detect premium themes


		if ( is_admin() ) {
			MainWP_Helper::update_option( 'mainwp_child_plugin_version', self::$version, 'yes' );
		}

		$this->checkOtherAuth();

		MainWP_Clone::get()->init();
		MainWP_Child_Server_Information::init();
		MainWP_Client_Report::Instance()->init();
        MainWP_Child_Plugins_Check::Instance();
        MainWP_Child_Themes_Check::Instance();

		$this->run_saved_snippets();

		if ( ! get_option( 'mainwp_child_pubkey' ) ) {
            MainWP_Child_Branding::Instance()->save_branding_options('branding_disconnected', 'yes');
			MainWP_Helper::update_option( 'mainwp_child_branding_disconnected', 'yes', 'yes' ); // to compatible
		}

		add_action( 'admin_notices', array( &$this, 'admin_notice' ) );
		add_filter( 'plugin_row_meta', array( &$this, 'plugin_row_meta' ), 10, 2 );

		//WP-Cron
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			if ( isset($_GET[ 'mainwp_child_run' ]) && ! empty( $_GET[ 'mainwp_child_run' ] ) ) {
				add_action( 'init', array( $this, 'cron_active' ), PHP_INT_MAX );
			}
		}

	}



	function load_all_options() {
		global $wpdb;

		if ( !defined( 'WP_INSTALLING' ) || !is_multisite() )
			$alloptions = wp_cache_get( 'alloptions', 'options' );
		else
			$alloptions = false;

		if ( !defined( 'WP_INSTALLING' ) || !is_multisite() )
			$notoptions = wp_cache_get( 'notoptions', 'options' );
		else
			$notoptions = false;

		if ( !isset($alloptions['mainwp_db_version']) ) {
			$suppress = $wpdb->suppress_errors();
			$options = array(
                'mainwp_child_auth',
                'mainwp_branding_plugin_header',
                'mainwp_child_reports_db',
                'mainwp_child_fix_htaccess',
                'mainwp_child_pluginDir',
                'mainwp_updraftplus_hide_plugin',
                'mainwp_backwpup_ext_enabled',
                'mainwpKeywordLinks',
                'mainwp_child_server',
                'mainwp_kwl_options',
                'mainwp_kwl_keyword_links',
                'mainwp_keyword_links_htaccess_set',
                'mainwp_pagespeed_hide_plugin',
                'mainwp_kwl_enable_statistic',
                'mainwp_child_clone_permalink',
                'mainwp_child_restore_permalink',
                'mainwp_ext_snippets_enabled',
                'mainwp_child_pubkey',
                'mainwp_child_nossl',
                'mainwp_security',
                'mainwp_backupwordpress_ext_enabled',
//                'mainwp_wprocket_ext_enabled',
                //'mainwp_wordfence_ext_enabled',
                'mainwp_branding_button_contact_label',
                'mainwp_branding_extra_settings',
                'mainwp_branding_child_hide',
                'mainwp_branding_ext_enabled',
                //'mainwp_creport_ext_branding_enabled',
                'mainwp_pagespeed_ext_enabled',
                'mainwp_linkschecker_ext_enabled',
                //'mainwp_ithemes_ext_enabled',
                'mainwp_child_branding_settings',
                'mainwp_child_plugintheme_days_outdate'
            );
			$query = "SELECT option_name, option_value FROM $wpdb->options WHERE option_name in (";
			foreach ($options as $option) {
				$query .= "'" . $option . "', ";
			}
			$query = substr($query, 0, strlen($query) - 2);
			$query .= ")";

			$alloptions_db = $wpdb->get_results( $query );
			$wpdb->suppress_errors($suppress);
			if ( !is_array( $alloptions ) ) $alloptions = array();
			if ( is_array( $alloptions_db ) ) {
				foreach ( (array) $alloptions_db as $o ) {
					$alloptions[ $o->option_name ] = $o->option_value;
					unset($options[array_search($o->option_name, $options)]);
				}
				foreach ($options as $option ) {
					$notoptions[ $option ] = true;
				}
				if ( ! defined( 'WP_INSTALLING' ) || ! is_multisite() ) {
					wp_cache_set( 'alloptions', $alloptions, 'options' );
					wp_cache_set( 'notoptions', $notoptions, 'options' );
				}
			}
		}

		return $alloptions;
	}


	function update() {
		$update_version = get_option( 'mainwp_child_update_version' );

		if ( $update_version === $this->update_version ) {
			return;
		}

		if ( false === $update_version ) {
			$options = array(
				'mainwp_child_legacy',
				'mainwp_child_auth',
				'mainwp_child_uniqueId',
				'mainwp_child_htaccess_set',
				'mainwp_child_fix_htaccess',
				'mainwp_child_pubkey',
				'mainwp_child_server',
				'mainwp_child_nonce',
				'mainwp_child_nossl',
				'mainwp_child_nossl_key',
				'mainwp_child_remove_wp_version',
				'mainwp_child_remove_rsd',
				'mainwp_child_remove_wlw',
				'mainwp_child_remove_core_updates',
				'mainwp_child_remove_plugin_updates',
				'mainwp_child_remove_theme_updates',
				'mainwp_child_remove_php_reporting',
				'mainwp_child_remove_scripts_version',
				'mainwp_child_remove_styles_version',
				'mainwp_child_remove_readme',
				'mainwp_child_clone_sites',
				'mainwp_child_pluginDir',
				'mainwp_premium_updates',
				'mainwp_child_activated_once',
				'mainwp_maintenance_opt_alert_404',
				'mainwp_maintenance_opt_alert_404_email',
				'mainwp_ext_code_snippets',
				'mainwp_ext_snippets_enabled',
				'mainwp_temp_clone_plugins',
				'mainwp_temp_clone_themes',
				'mainwp_child_click_data',
				'mainwp_child_clone_from_server_last_folder',
				'mainwp_child_clone_permalink',
				'mainwp_child_restore_permalink',
				'mainwp_keyword_links_htaccess_set',
				'mainwp_kwl_options',
				'mainwp_kwl_keyword_links',
				'mainwp_kwl_click_statistic_data',
				'mainwp_kwl_statistic_data_',
				'mainwp_kwl_enable_statistic',
				'mainwpKeywordLinks',
//				'mainwp_branding_ext_enabled',
//				'mainwp_branding_plugin_header',
//				'mainwp_branding_support_email',
//				'mainwp_branding_support_message',
//				'mainwp_branding_remove_restore',
//				'mainwp_branding_remove_setting',
//				'mainwp_branding_remove_wp_tools',
//				'mainwp_branding_remove_wp_setting',
//				'mainwp_branding_remove_permalink',
//				'mainwp_branding_button_contact_label',
//				'mainwp_branding_send_email_message',
//				'mainwp_branding_message_return_sender',
//				'mainwp_branding_submit_button_title',
//				'mainwp_branding_disable_wp_branding',
//				'mainwp_branding_extra_settings',
//				'mainwp_branding_child_hide',
//				'mainwp_branding_show_support',
//				'mainwp_branding_disable_change',
			);
			foreach ( $options as $option ) {
				MainWP_Helper::fix_option( $option );
			}
		} else if ( ( '1.0' === $update_version ) || ( '1.1' === $update_version ) ) {
			$options = array(
				'mainwp_child_pubkey',
				//'mainwp_child_branding_disconnected',
				//'mainwp_branding_plugin_header',
				'mainwp_child_update_version',
				'mainwp_child_auth',
				'mainwp_child_clone_permalink',
				'mainwp_child_restore_permalink',
				'mainwp_ext_snippets_enabled',
				'mainwp_child_fix_htaccess',
				'mainwp_child_pluginDir',
				'mainwp_child_htaccess_set',
				'mainwp_child_nossl',
				'mainwp_updraftplus_ext_enabled',
				'mainwpKeywordLinks',
				'mainwp_keyword_links_htaccess_set',
				//'mainwp_branding_button_contact_label',
				//'mainwp_branding_extra_settings',
				//'mainwp_branding_ext_enabled',
				//'mainwp_creport_ext_branding_enabled',
				'mainwp_pagespeed_ext_enabled',
				'mainwp_linkschecker_ext_enabled',
				//'mainwp_wordfence_ext_enabled',
				//'mainwp_ithemes_ext_enabled',
				'mainwp_maintenance_opt_alert_404',
			);
			foreach ( $options as $option ) {
				MainWP_Helper::fix_option( $option, 'yes' );
			}

			if ( ! is_array( get_option( 'mainwp_security' ) ) ) {
				$securityOptions = array(
					'wp_version'      => 'mainwp_child_remove_wp_version',
					'rsd'             => 'mainwp_child_remove_rsd',
					'wlw'             => 'mainwp_child_remove_wlw',
					'core_updates'    => 'mainwp_child_remove_core_updates',
					'plugin_updates'  => 'mainwp_child_remove_plugin_updates',
					'theme_updates'   => 'mainwp_child_remove_theme_updates',
					'php_reporting'   => 'mainwp_child_remove_php_reporting',
					'scripts_version' => 'mainwp_child_remove_scripts_version',
					'styles_version'  => 'mainwp_child_remove_styles_version',
					'readme'          => 'mainwp_child_remove_readme',
				);

				$security = array();
				foreach ( $securityOptions as $option => $old ) {
					$value               = get_option( $old );
					$security[ $option ] = ( 'T' === $value );
				}
				MainWP_Helper::update_option( 'mainwp_security', $security, 'yes' );
			}
		}

        if ( !empty($update_version) && version_compare($update_version, '1.4', '<=' ) ) {  // 3.5.4
            if ( ! is_array( get_option( 'mainwp_child_branding_settings' ) ) ) {
                // to fix: reduce number of options
                $brandingOptions = array(
                    'hide' => 'mainwp_branding_child_hide',
                    'extra_settings'      => 'mainwp_branding_extra_settings',
                    'branding_disconnected' => 'mainwp_child_branding_disconnected',
                    'preserve_branding'      => 'mainwp_branding_preserve_branding',
                    'branding_header'      => 'mainwp_branding_plugin_header',
                    'support_email'      => 'mainwp_branding_support_email',
                    'support_message'      => 'mainwp_branding_support_message',
                    'remove_restore'      => 'mainwp_branding_remove_restore',
                    'remove_setting'      => 'mainwp_branding_remove_setting',
                    'remove_server_info'      => 'mainwp_branding_remove_server_info',
                    'remove_connection_detail'      => 'mainwp_branding_remove_connection_detail',
                    'remove_wp_tools'      => 'mainwp_branding_remove_wp_tools',
                    'remove_wp_setting'      => 'mainwp_branding_remove_wp_setting',
                    'remove_permalink'      => 'mainwp_branding_remove_permalink',
                    'contact_label'      => 'mainwp_branding_button_contact_label',
                    'email_message'      => 'mainwp_branding_send_email_message',
                    'message_return_sender'      => 'mainwp_branding_message_return_sender',
                    'submit_button_title'      => 'mainwp_branding_submit_button_title',
                    'disable_wp_branding'      => 'mainwp_branding_disable_wp_branding',
                    'show_support' => 'mainwp_branding_show_support',
                    'disable_change' => 'mainwp_branding_disable_change',
                    'disable_switching_theme' => 'mainwp_branding_disable_switching_theme',
                    //'hide_child_reports'                => 'mainwp_creport_branding_stream_hide',
                    'branding_ext_enabled'  => 'mainwp_branding_ext_enabled'
                );

                $convertBranding = array();
				foreach ( $brandingOptions as $option => $old ) {
					$value               = get_option( $old );
					$convertBranding[ $option ] = $value;
				}
                MainWP_Helper::update_option( 'mainwp_child_branding_settings', $convertBranding );
            }

		}

		MainWP_Helper::update_option( 'mainwp_child_update_version', $this->update_version, 'yes' );
	}

	function cron_active() {
		if ( ! defined( 'DOING_CRON' ) || ! DOING_CRON ) {
			return;
		}
		if ( empty( $_GET[ 'mainwp_child_run' ] ) || 'test' !== $_GET[ 'mainwp_child_run' ] ) {
			return;
		}
		@session_write_close();
		@header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ), TRUE );
		@header( 'X-Robots-Tag: noindex, nofollow', TRUE );
		@header( 'X-MainWP-Child-Version: ' . MainWP_Child::$version, TRUE );
		nocache_headers();
		if ( $_GET[ 'mainwp_child_run' ] == 'test' ) {
			die( 'MainWP Test' );
		}
		die( '' );
	}

	public function admin_notice() {
		//Admin Notice...
        if ( ! get_option( 'mainwp_child_pubkey' ) && MainWP_Helper::isAdmin() && is_admin() ) {
            $branding_opts = MainWP_Child_Branding::Instance()->get_branding_options();
            $child_name = ( $branding_opts['branding_preserve_title'] === '' ) ? 'MainWP Child' : $branding_opts['branding_preserve_title'];
            $dashboard_name = ( $branding_opts['branding_preserve_title'] === '' ) ? 'MainWP Dashboard' : $branding_opts['branding_preserve_title'] . ' Dashboard';

            $msg 		= '<div class="wrap"><div class="postbox" style="margin-top: 4em;"><p style="background: #a00; color: #fff; font-size: 22px; font-weight: bold; margin: 0; padding: .3em;">';
            $msg        .= __( 'Attention!', 'mainwp-child' );
            $msg 		.= '</p><div style="padding-left: 1em; padding-right: 1em;"><p style="font-size: 16px;">';
            $msg 		.= __( 'Please add this site to your ', 'mainwp-child' ) . $dashboard_name . ' ' . __( '<b>NOW</b> or deactivate the ', 'mainwp-child' ) . $child_name . __( ' plugin until you are ready to connect this site to your Dashboard in order to avoid unexpected security issues.','mainwp-child' );
            $msg 		.= '</p>';
            $msg    .= '<p style="font-size: 16px;">';
            $msg    .= __( 'If you are not sure how to add this site to your Dashboard, <a href="https://mainwp.com/help/docs/set-up-the-mainwp-plugin/add-site-to-your-dashboard/" target="_blank">please review these instructions</a>.', 'mainwp-child' );
            $msg 	  .= '</p>';
            if ( ! MainWP_Child_Branding::Instance()->is_branding() ) {
                $msg 	.= '<p>';
                $msg 	.= __( 'You can also turn on the unique security ID option in <a href="admin.php?page=mainwp_child_tab">', 'mainwp-child' ) . $child_name . __( ' settings</a> if you would like extra security and additional time to add this site to your Dashboard. <br/>Find out more in this help document <a href="https://mainwp.com/help/docs/set-up-the-mainwp-plugin/set-unique-security-id/" target="_blank">How do I use the child unique security ID?</a>', 'mainwp-child' );
                $msg 	.= '</p>';
            }
            $msg 		.= '</div></div></div>';
            echo wp_kses_post( $msg );
        }
		MainWP_Child_Server_Information::showWarnings();
	}

	public function localization() {
		load_plugin_textdomain( 'mainwp-child', false, dirname( dirname( plugin_basename( __FILE__ ) ) ) . '/languages/' );
	}

	public function detect_premium_themesplugins_updates() {

        if (isset($_GET['_detect_plugins_updates']) && $_GET['_detect_plugins_updates'] == 'yes') {
             // to fix some premium plugins update notification
            $current = get_site_transient( 'update_plugins' );
            set_site_transient( 'update_plugins', $current );

            add_filter( 'pre_site_transient_update_plugins', $this->filterFunction, 99 );
            $plugins = get_plugin_updates();
            remove_filter( 'pre_site_transient_update_plugins', $this->filterFunction, 99 );

            set_site_transient( 'mainwp_update_plugins_cached', $plugins, DAY_IN_SECONDS);
            //wp_destroy_current_session(); // to fix issue multi user session

        }

        if (isset($_GET['_detect_themes_updates']) && $_GET['_detect_themes_updates'] == 'yes') {
            add_filter( 'pre_site_transient_update_themes', $this->filterFunction, 99 );
            $themes = get_theme_updates();
            remove_filter( 'pre_site_transient_update_themes', $this->filterFunction, 99 );

            set_site_transient( 'mainwp_update_themes_cached', $themes, DAY_IN_SECONDS);
            //wp_destroy_current_session(); // to fix issue multi user session
        }


        $type = isset($_GET['_request_update_premiums_type']) ? $_GET['_request_update_premiums_type'] : '';
        if ( $type == 'plugin' || $type == 'theme' ) {
            $list = isset( $_GET['list'] ) ? $_GET['list'] : '';
            if ( !empty($list) ) {
                // to call function upgradePluginTheme(),
                // will not get the response result
                $_POST['type'] = $type;
                $_POST['list'] = $list;

                $function = 'upgradeplugintheme';
                if (isset($this->callableFunctions[ $function ])) {
                    call_user_func( array( $this, $this->callableFunctions[ $function ] ) );
                }
                //wp_destroy_current_session(); // to fix issue multi user session
            }
        }

    }

	function checkOtherAuth() {
		$auths = get_option( 'mainwp_child_auth' );

		if ( ! $auths ) {
			$auths = array();
		}

		if ( ! isset( $auths['last'] ) || $auths['last'] < mktime( 0, 0, 0, date( 'm' ), date( 'd' ), date( 'Y' ) ) ) {
			//Generate code for today..
			for ( $i = 0; $i < $this->maxHistory; $i ++ ) {
				if ( ! isset( $auths[ $i + 1 ] ) ) {
					continue;
				}

				$auths[ $i ] = $auths[ $i + 1 ];
			}
			$newI = $this->maxHistory + 1;
			while ( isset( $auths[ $newI ] ) ) {
				unset( $auths[ $newI ++ ] );
			}
			$auths[ $this->maxHistory ] = md5( MainWP_Helper::randString( 14 ) );
			$auths['last']              = time();
			MainWP_Helper::update_option( 'mainwp_child_auth', $auths, 'yes' );
		}
	}

	function isValidAuth( $key ) {
		$auths = get_option( 'mainwp_child_auth' );
		if ( ! $auths ) {
			return false;
		}
		for ( $i = 0; $i <= $this->maxHistory; $i ++ ) {
			if ( isset( $auths[ $i ] ) && ( $auths[ $i ] === $key ) ) {
				return true;
			}
		}

		return false;
	}

	function template_redirect() {
		$this->maintenance_alert_404();
	}


	public function plugin_row_meta( $plugin_meta, $plugin_file ) {
		if ( $this->plugin_slug !== $plugin_file ) {
			return $plugin_meta;
		}

		return apply_filters( 'mainwp_child_plugin_row_meta', $plugin_meta, $plugin_file, $this->plugin_slug );
	}

	function admin_menu() {
        $branding_opts = MainWP_Child_Branding::Instance()->get_branding_options();
        $is_hide = isset( $branding_opts['hide'] ) ? $branding_opts['hide'] : '';
        $cancelled_branding = $branding_opts['cancelled_branding'];

		if ( isset($branding_opts['remove_wp_tools']) && $branding_opts['remove_wp_tools'] && ! $cancelled_branding ) {
			remove_menu_page( 'tools.php' );
			$pos = stripos( $_SERVER['REQUEST_URI'], 'tools.php' ) ||
			       stripos( $_SERVER['REQUEST_URI'], 'import.php' ) ||
			       stripos( $_SERVER['REQUEST_URI'], 'export.php' );
			if ( false !== $pos ) {
				wp_redirect( get_option( 'siteurl' ) . '/wp-admin/index.php' );
			}
		}
		// if preserve branding do not remove menus
		if ( isset($branding_opts['remove_wp_setting']) && $branding_opts['remove_wp_setting'] && ! $cancelled_branding ) {
			remove_menu_page( 'options-general.php' );
			$pos = stripos( $_SERVER['REQUEST_URI'], 'options-general.php' ) ||
			       stripos( $_SERVER['REQUEST_URI'], 'options-writing.php' ) ||
			       stripos( $_SERVER['REQUEST_URI'], 'options-reading.php' ) ||
			       stripos( $_SERVER['REQUEST_URI'], 'options-discussion.php' ) ||
			       stripos( $_SERVER['REQUEST_URI'], 'options-media.php' ) ||
			       stripos( $_SERVER['REQUEST_URI'], 'options-permalink.php' );
			if ( false !== $pos ) {
				wp_redirect( get_option( 'siteurl' ) . '/wp-admin/index.php' );
				exit();
			}
		}

		if ( isset($branding_opts['remove_permalink']) && $branding_opts['remove_permalink'] && ! $cancelled_branding ) {
			remove_submenu_page( 'options-general.php', 'options-permalink.php' );
			$pos = stripos( $_SERVER['REQUEST_URI'], 'options-permalink.php' );
			if ( false !== $pos ) {
				wp_redirect( get_option( 'siteurl' ) . '/wp-admin/index.php' );
				exit();
			}
		}

		$remove_all_child_menu = false;
		if ( isset($branding_opts['remove_setting']) && isset($branding_opts['remove_restore']) &&  isset($branding_opts['remove_server_info']) &&  $branding_opts['remove_setting'] && $branding_opts['remove_restore'] && $branding_opts['remove_server_info'] ) {
			$remove_all_child_menu = true;
		}

		// if preserve branding do not hide menus
		if ( ( ! $remove_all_child_menu && $is_hide !== 'T' ) || $cancelled_branding ) {
            $branding_header = isset( $branding_opts['branding_header'] ) ? $branding_opts['branding_header'] : array();
			if ( ( is_array( $branding_header ) && ! empty( $branding_header['name'] ) ) && ! $cancelled_branding ) {
				self::$brandingTitle   = $child_menu_title = stripslashes( $branding_header['name'] );
				$child_page_title = $child_menu_title . ' Settings';
			} else {
				$child_menu_title  = 'MainWP Child';
				$child_page_title = 'MainWPSettings';
			}

			$settingsPage = add_submenu_page( 'options-general.php', $child_menu_title, $child_menu_title, 'manage_options', 'mainwp_child_tab', array( &$this, 'render_pages' ) );

			add_action( 'admin_print_scripts-' . $settingsPage, array( 'MainWP_Clone', 'print_scripts' ) );
			$subpageargs = array(
				'child_slug' => 'options-general.php', // to backwards compatible
				'branding'   => (self::$brandingTitle === null) ? 'MainWP' : self::$brandingTitle,
				'parent_menu' => $settingsPage
			);
			do_action( 'mainwp-child-subpages', $subpageargs ); // to compatible

			$sub_pages = array();

			$all_subpages = apply_filters( 'mainwp-child-init-subpages', array() );

			if ( !is_array( $all_subpages ) )
				$all_subpages = array();

			if ( !self::$subPagesLoaded ) {
				foreach( $all_subpages as $page ) {
					$slug = isset( $page['slug'] ) ? $page['slug'] : '';
					if ( empty( $slug ) )
						continue;
					$subpage = array();
					$subpage['slug'] = $slug;
					$subpage['title'] = $page['title'];
					$subpage['page']  = 'mainwp-' . str_replace( ' ', '-', strtolower( str_replace( '-', ' ',  $slug ) ) );
					if ( isset( $page['callback'] ) ) {
						$subpage['callback'] =  $page['callback'];
						$created_page = add_submenu_page( 'options-general.php', $subpage['title'], '<div class="mainwp-hidden">' . $subpage['title'] . '</div>', 'manage_options', $subpage['page'], $subpage['callback'] );
						if ( isset( $page['load_callback'] ) ) {
							$subpage['load_callback'] =  $page['load_callback'];
							add_action( 'load-' . $created_page, $subpage['load_callback'] );
						}
					}
					$sub_pages[] = $subpage;
				}
				self::$subPages = $sub_pages;
				self::$subPagesLoaded = true;
				//MainWP_Helper::update_option( 'mainwp_child_subpages', self::$subPages ); // to fix error for some case
			}
			add_action( 'mainwp-child-pageheader', array( __CLASS__, 'render_header' ) );
			add_action( 'mainwp-child-pagefooter', array( __CLASS__, 'render_footer' ) );

			global $submenu;
			if ( isset( $submenu['options-general.php'] ) ) {
				foreach ( $submenu['options-general.php'] as $index => $item ) {
					if ( 'mainwp-reports-page' === $item[2] || 'mainwp-reports-settings' === $item[2]) {
						unset( $submenu['options-general.php'][ $index ] );
					}
				}
			}
		}
	}

	function render_pages($shownPage) {
        $shownPage = '';
		if ( isset($_GET['tab']) ) {
			$shownPage = $_GET['tab'];
		}
        $branding_opts = MainWP_Child_Branding::Instance()->get_branding_options();

		$hide_settings = isset( $branding_opts['remove_setting'] ) && $branding_opts['remove_setting'] ? true : false;
		$hide_restore = isset( $branding_opts['remove_restore'] ) && $branding_opts['remove_restore'] ? true : false;
		$hide_server_info = isset( $branding_opts['remove_server_info'] ) &&  $branding_opts['remove_server_info'] ? true : false;
        $hide_connection_detail = isset( $branding_opts['remove_connection_detail'] ) && $branding_opts['remove_connection_detail'] ? true : false;

		$hide_style = 'style="display:none"';

	    if ($shownPage == '') {
	        if (!$hide_settings ) {
	                $shownPage = 'settings';
	        } else if (!$hide_restore) {
	            $shownPage = 'restore-clone';
	        } else if (!$hide_server_info) {
	            $shownPage = 'server-info';
	        } else if (!$hide_connection_detail) {
	            $shownPage = 'connection-detail';
	        }
	    }

		self::render_header($shownPage, false);
		?>
		<?php if (!$hide_settings ) { ?>
			<div class="mainwp-child-setting-tab settings" <?php echo ('settings' !==  $shownPage) ? $hide_style : '' ; ?>>
				<?php $this->settings(); ?>
			</div>
		<?php } ?>

		<?php if ( !$hide_restore ) { ?>
			<div class="mainwp-child-setting-tab restore-clone" <?php echo ( 'restore-clone' !== $shownPage ) ? $hide_style : ''; ?>>
				<?php
				if ( '' === session_id() ) {
					@session_start();
				}

				if ( isset( $_SESSION['file'] ) ) {
					MainWP_Clone::renderRestore();
				} else {
					$sitesToClone = get_option( 'mainwp_child_clone_sites' );
					if ( 0 !== (int) $sitesToClone ) {
						MainWP_Clone::render();
					} else {
						MainWP_Clone::renderNormalRestore();
					}
				}
				?>
			</div>
		<?php } ?>

		<?php if ( !$hide_server_info  ) { ?>
			<div class="mainwp-child-setting-tab server-info" <?php echo ('server-info' !==  $shownPage) ? $hide_style : '' ; ?>>
				<?php MainWP_Child_Server_Information::renderPage(); ?>
			</div>
		<?php } ?>

                <?php if ( !$hide_connection_detail  ) { ?>
			<div class="mainwp-child-setting-tab connection-detail" <?php echo ('connection-detail' !==  $shownPage) ? $hide_style : '' ; ?>>
                            <?php MainWP_Child_Server_Information::renderConnectionDetails(); ?>
			</div>
		<?php } ?>



		<?php
		self::render_footer();
	}

	public static function render_header($shownPage, $subpage = true) {
		if ( isset($_GET['tab']) ) {
			$shownPage = $_GET['tab'];
		}

		if (empty($shownPage))
			$shownPage = 'settings';

        $branding_opts = MainWP_Child_Branding::Instance()->get_branding_options();

		$hide_settings = isset( $branding_opts['remove_setting'] ) && $branding_opts['remove_setting']  ? true : false;
		$hide_restore = isset( $branding_opts['remove_restore'] ) && $branding_opts['remove_restore'] ? true : false;
		$hide_server_info = isset( $branding_opts['remove_server_info'] ) && $branding_opts['remove_server_info'] ? true : false;
        $hide_connection_detail = isset( $branding_opts['remove_connection_detail'] ) && $branding_opts['remove_connection_detail'] ? true : false;

		$sitesToClone = get_option( 'mainwp_child_clone_sites' );

		?>
		<style type="text/css">
			.mainwp-tabs
			{
				margin-top: 2em;
				border-bottom: 1px solid #e5e5e5;
			}

			#mainwp-tabs {
				clear: both ;
			}
			#mainwp-tabs .nav-tab-active {
				background: #fafafa ;
				border-top: 1px solid #7fb100 !important;
				border-left: 1px solid #e5e5e5;
				border-right: 1px solid #e5e5e5;
				border-bottom: 1px solid #fafafa !important ;
				color: #7fb100;
			}

			#mainwp-tabs .nav-tab {
				border-top: 1px solid #e5e5e5;
				border-left: 1px solid #e5e5e5;
				border-right: 1px solid #e5e5e5;
				border-bottom: 1px solid #e5e5e5;
				padding: 10px 16px;
				font-size: 14px;
				text-transform: uppercase;
			}

			#mainwp_wrap-inside {
				min-height: 80vh;
				height: 100% ;
				margin-top: 0em ;
				padding: 10px ;
				background: #fafafa ;
				border-top: none ;
				border-bottom: 1px solid #e5e5e5;
				border-left: 1px solid #e5e5e5;
				border-right: 1px solid #e5e5e5;
				box-shadow: 0 1px 1px rgba(0,0,0,.04);
				position: relative;
			}

			#mainwp_wrap-inside h2.hndle {
				font-size: 14px;
				padding: 8px 12px;
				margin: 0;
				line-height: 1.4;
			}

			.mainwp-hidden {
				display: none;
			}
		</style>

		<div class="wrap">
		<h2><i class="fa fa-file"></i> <?php echo ( self::$brandingTitle === null ?  'MainWP Child' : self::$brandingTitle ); ?></h2>
		<div style="clear: both;"></div><br/>
		<div class="mainwp-tabs" id="mainwp-tabs">
			<?php if ( !$hide_settings ) { ?>
				<a class="nav-tab pos-nav-tab <?php if ( $shownPage === 'settings' ) { echo 'nav-tab-active'; } ?>" tab-slug="settings" href="<?php echo $subpage ? 'options-general.php?page=mainwp_child_tab&tab=settings' : '#'; ?>" style="margin-left: 0 !important;"><?php _e( 'Settings','mainwp-child' ); ?></a>
			<?php } ?>
			<?php if ( !$hide_restore ) { ?>
				<a class="nav-tab pos-nav-tab <?php if ( $shownPage === 'restore-clone' ) { echo 'nav-tab-active'; } ?>" tab-slug="restore-clone" href="<?php echo $subpage ? 'options-general.php?page=mainwp_child_tab&tab=restore-clone' : '#'; ?>"><?php echo ( 0 !== (int) $sitesToClone ) ? __( 'Restore / Clone','mainwp-child' ) : __( 'Restore','mainwp-child' ); ?></a>
			<?php } ?>
			<?php if (!$hide_server_info ) { ?>
				<a class="nav-tab pos-nav-tab <?php if ( $shownPage === 'server-info' ) { echo 'nav-tab-active'; } ?>" tab-slug="server-info" href="<?php echo $subpage ? 'options-general.php?page=mainwp_child_tab&tab=server-info' : '#'; ?>"><?php _e( 'Server information','mainwp-child' ); ?></a>
			<?php } ?>
                        <?php if (!$hide_connection_detail ) { ?>
				<a class="nav-tab pos-nav-tab <?php if ( $shownPage === 'connection-detail' ) { echo 'nav-tab-active'; } ?>" tab-slug="connection-detail" href="<?php echo $subpage ? 'options-general.php?page=mainwp_child_tab&tab=connection-detail' : '#'; ?>"><?php _e( 'Connection Details','mainwp-child' ); ?></a>
			<?php } ?>
			<?php
			if ( isset( self::$subPages ) && is_array( self::$subPages ) ) {
				foreach ( self::$subPages as $subPage ) {
					?>
					<a class="nav-tab pos-nav-tab <?php if ( $shownPage == $subPage['slug'] ) { echo 'nav-tab-active'; } ?>" tab-slug="<?php echo esc_attr($subPage['slug']); ?>" href="options-general.php?page=<?php echo rawurlencode($subPage['page']); ?>"><?php echo esc_html($subPage['title']); ?></a>
					<?php
				}
			}
			?>
			<div style="clear:both;"></div>
		</div>
		<div style="clear:both;"></div>
		<script type="text/javascript">
			jQuery( document ).ready( function () {
				$hideMenu = jQuery('#menu-settings li a .mainwp-hidden');
				$hideMenu.each(function(){jQuery(this).closest('li').hide();})

				var $tabs          = jQuery( '.mainwp-tabs' );
				$tabs.on('click', 'a', function () {
					if (jQuery(this).attr('href') !=='#' )
						return true;
					jQuery('.mainwp-tabs > a').removeClass('nav-tab-active');
					jQuery(this).addClass('nav-tab-active');
					jQuery('.mainwp-child-setting-tab').hide();
					var _tab = jQuery(this).attr('tab-slug');
					jQuery('.mainwp-child-setting-tab.' + _tab ).show();
					return false;
				});
			})
		</script>

		<div id="mainwp_wrap-inside">

		<?php
	}

	public static function render_footer() {
		?>
		</div>
		</div>
		<?php
	}

	function admin_init() {
		if ( MainWP_Helper::isAdmin() && is_admin() ) {
			MainWP_Clone::get()->init_ajax();
		}
	}

    function admin_head() {
        if (isset($_GET['page']) && $_GET['page'] == 'mainwp_child_tab') {
            ?>
            <style type="text/css">
                .mainwp-postbox-actions-top {
                    padding: 10px;
                    clear: both;
                    border-bottom: 1px solid #ddd;
                    background: #f5f5f5;
                }
                h3.mainwp_box_title {
                    font-family: "Open Sans",sans-serif;
                    font-size: 14px;
                    font-weight: 600;
                    line-height: 1.4;
                    margin: 0;
                    padding: 8px 12px;
                    border-bottom: 1px solid #eee;
                }
                .mainwp-child-setting-tab.connection-detail .postbox .inside{
                    margin: 0;
                    padding: 0;
                }
            </style>
            <?php
        }
	}
	function settings() {
		if ( isset( $_POST['submit'] ) && isset( $_POST['nonce'] ) && wp_verify_nonce( $_POST['nonce'], 'child-settings' ) ) {
			if ( isset( $_POST['requireUniqueSecurityId'] ) ) {
				MainWP_Helper::update_option( 'mainwp_child_uniqueId', MainWP_Helper::randString( 8 ) );
			} else {
				MainWP_Helper::update_option( 'mainwp_child_uniqueId', '' );
			}
		}

		?>
		<div class="postbox">
			<h2 class="hndle"><span><?php esc_html_e( 'Connection settings', 'mainwp-child' ); ?></span></h2>
			<div class="inside">
				<form method="post" action="options-general.php?page=mainwp_child_tab">
					<div class="howto"><?php esc_html_e( 'The unique security ID adds additional protection between the child plugin and your Dashboard. The unique security ID will need to match when being added to the Dashboard. This is additional security and should not be needed in most situations.', 'mainwp-child' ); ?></div>
					<div style="margin: 1em 0 4em 0;">
						<input name="requireUniqueSecurityId"
						       type="checkbox"
						       id="requireUniqueSecurityId" <?php if ( '' != get_option( 'mainwp_child_uniqueId' ) ) { echo 'checked'; } ?> />
						<label for="requireUniqueSecurityId"
						       style="font-size: 15px;"><?php esc_html_e( 'Require unique security ID', 'mainwp-child' ); ?></label>
					</div>
					<div>
						<?php if ( '' != get_option( 'mainwp_child_uniqueId' ) ) {
							echo '<span style="border: 1px dashed #e5e5e5; background: #fafafa; font-size: 24px; padding: 1em 2em;">' . esc_html__( 'Your unique security ID is:', 'mainwp-child' ) . ' <span style="font-weight: bold; color: #7fb100;">' . esc_html( get_option( 'mainwp_child_uniqueId' ) ) . '</span></span>';
						} ?>
					</div>
					<p class="submit" style="margin-top: 4em;">
						<input type="submit"
						       name="submit"
						       id="submit"
						       class="button button-primary button-hero"
						       value="<?php esc_html_e( 'Save changes', 'mainwp-child' ); ?>">
					</p>
					<input type="hidden" name="nonce" value="<?php echo wp_create_nonce( 'child-settings' );?>">
				</form>
			</div>
		</div>

		<?php
		//self::render_footer('setting');
	}

	function mod_rewrite_rules( $pRules ) {

		$home_root = parse_url( home_url() );
		if ( isset( $home_root['path'] ) ) {
			$home_root = trailingslashit( $home_root['path'] );
		} else {
			$home_root = '/';
		}

		$rules = "<IfModule mod_rewrite.c>\n";
		$rules .= "RewriteEngine On\n";
		$rules .= "RewriteBase $home_root\n";

		//add in the rules that don't redirect to WP's index.php (and thus shouldn't be handled by WP at all)
		foreach ( $pRules as $match => $query ) {
			// Apache 1.3 does not support the reluctant (non-greedy) modifier.
			$match = str_replace( '.+?', '.+', $match );

			$rules .= 'RewriteRule ^' . $match . ' ' . $home_root . $query . " [QSA,L]\n";
		}

		$rules .= "</IfModule>\n";

		return $rules;
	}

	function update_htaccess( $hard = false ) {
		if ( !$hard && defined( 'DOING_CRON' ) && DOING_CRON ) {
			return;
		}

        if ( $hard ) {
			include_once( ABSPATH . '/wp-admin/includes/misc.php' );

			$home_path     = ABSPATH;
			$htaccess_file = $home_path . '.htaccess';
			if ( function_exists( 'save_mod_rewrite_rules' ) ) {
				$rules = explode( "\n", '' );

				//                $ch = @fopen($htaccess_file,'w');
				//                if (@flock($ch, LOCK_EX))
				//                {
				insert_with_markers( $htaccess_file, 'MainWP', $rules );
				//                }
				//                @flock($ch, LOCK_UN);
				//                @fclose($ch);

			}
		}
	}

    function check_login() {
		$file      = '';
		if ( isset( $_REQUEST['f'] ) ) {
			$file = $_REQUEST['f'];
		} else if ( isset( $_REQUEST['file'] ) ) {
			$file = $_REQUEST['file'];
		} else if ( isset( $_REQUEST['fdl'] ) ) {
			$file = $_REQUEST['fdl'];
		}

		$auth = $this->auth( isset( $_POST['mainwpsignature'] ) ? rawurldecode( $_POST['mainwpsignature'] ) : '', isset( $_POST['function'] ) ? $_POST['function'] : rawurldecode( ( isset( $_REQUEST['where'] ) ? $_REQUEST['where'] : $file ) ), isset( $_POST['nonce'] ) ? $_POST['nonce'] : '', isset( $_POST['nossl'] ) ? $_POST['nossl'] : 0 );

		if ( ! $auth && isset( $_POST['mainwpsignature'] ) ) {
			MainWP_Helper::error( __( 'Authentication failed! Please deactivate and re-activate the MainWP Child plugin on this site.', 'mainwp-child' ) );
		}

		if ( ! $auth && isset( $_POST['function'] ) && isset( $this->callableFunctions[ $_POST['function'] ] ) && ! isset( $this->callableFunctionsNoAuth[ $_POST['function'] ] ) ) {
			MainWP_Helper::error( __( 'Authentication failed! Please deactivate and re-activate the MainWP Child plugin on this site.', 'mainwp-child' ) );
		}

        $auth_user = false;
		if ( $auth ) {
			//disable duo auth for mainwp
			remove_action('init', 'duo_verify_auth', 10);

			//Check if the user exists & is an administrator
			if ( isset( $_POST['function'] ) && isset( $_POST['user'] ) ) {

                $user = null;

                if ( isset( $_POST['alt_user'] ) && !empty( $_POST['alt_user'] ) )  {
                    if ( $this->check_login_as( $_POST['alt_user'] ) ) {
                        $auth_user = $_POST['alt_user'];
                        $user = get_user_by( 'login', $auth_user );
                    }
                }

                // if not valid alternative admin
                if ( ! $user ) {
                    // check connected admin existed
                    $user = get_user_by( 'login', $_POST['user'] );
                    $auth_user = $_POST['user'];
                }

				if ( ! $user ) {
					MainWP_Helper::error( __( 'That administrator username was not found on this child site. Please verify that it is an existing administrator.', 'mainwp-child' ) );
				}

				if ( 10 != $user->wp_user_level && ( ! isset( $user->user_level ) || 10 != $user->user_level ) && ! $user->has_cap( 'level_10' ) ) {
					MainWP_Helper::error( __( 'That user is not an administrator. Please use an administrator user to establish the connection.', 'mainwp-child' ) );
				}

				$this->login( $auth_user );
			}

			if ( isset( $_POST['function'] ) && 'visitPermalink' === $_POST['function'] ) {

                if (empty($auth_user)) {
                    $auth_user = $_POST['user'];
                }

				if ( $this->login( $auth_user, true ) ) {
					return;
				} else {
					exit();
				}
			}

			//Redirect to the admin part if needed
			if ( isset( $_POST['admin'] ) && '1' === $_POST['admin'] ) {
				wp_redirect( get_option( 'siteurl' ) . '/wp-admin/' );
				die();
			}
		}
	}

	function parse_init() {
		if ( isset( $_REQUEST['cloneFunc'] ) ) {
			if ( ! isset( $_REQUEST['key'] ) ) {
				return;
			}
			if ( ! isset( $_REQUEST['f'] ) || ( '' === $_REQUEST['f'] ) ) {
				return;
			}
			if ( ! $this->isValidAuth( $_REQUEST['key'] ) ) {
				return;
			}

			if ( 'dl' === $_REQUEST['cloneFunc'] ) {
				$this->uploadFile( $_REQUEST['f'] );
				exit;
			} else if ( 'deleteCloneBackup' === $_POST['cloneFunc'] ) {
				$dirs      = MainWP_Helper::getMainWPDir( 'backup' );
				$backupdir = $dirs[0];
				$result    = glob( $backupdir . $_POST['f'] );
				if ( 0 === count( $result ) ) {
					return;
				}

				@unlink( $result[0] );
				MainWP_Helper::write( array( 'result' => 'ok' ) );
			} else if ( 'createCloneBackupPoll' === $_POST['cloneFunc'] ) {
				$dirs        = MainWP_Helper::getMainWPDir( 'backup' );
				$backupdir   = $dirs[0];
				$result      = glob( $backupdir . 'backup-' . $_POST['f'] . '-*' );
				$archiveFile = false;
				foreach ( $result as $file ) {
					if ( MainWP_Helper::isArchive( $file, 'backup-' . $_POST['f'] . '-' ) ) {
						$archiveFile = $file;
						break;
					}
				}
				if ( false === $archiveFile ) {
					return;
				}

				MainWP_Helper::write( array( 'size' => filesize( $archiveFile ) ) );
			} else if ( 'createCloneBackup' === $_POST['cloneFunc'] ) {
				MainWP_Helper::endSession();

				$files = glob( WP_CONTENT_DIR . '/dbBackup*.sql' );
				foreach ( $files as $file ) {
					@unlink( $file );
				}
				if ( file_exists( ABSPATH . 'clone/config.txt' ) ) {
					@unlink( ABSPATH . 'clone/config.txt' );
				}
				if ( MainWP_Helper::is_dir_empty( ABSPATH . 'clone' ) ) {
					@rmdir( ABSPATH . 'clone' );
				}

				$wpversion = $_POST['wpversion'];
				global $wp_version;
				$includeCoreFiles = ( $wpversion !== $wp_version );
				$excludes         = ( isset( $_POST['exclude'] ) ? explode( ',', $_POST['exclude'] ) : array() );
				$excludes[]       = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/uploads/mainwp';
				$uploadDir        = MainWP_Helper::getMainWPDir();
				$uploadDir        = $uploadDir[0];
				$excludes[]       = str_replace( ABSPATH, '', $uploadDir );
				$excludes[]       = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/object-cache.php';
				if ( version_compare(phpversion(), '5.3.0') >= 0 || ! ini_get( 'safe_mode' ) ) {
					@set_time_limit( 6000 );
				}

				$newExcludes = array();
				foreach ( $excludes as $exclude ) {
					$newExcludes[] = rtrim( $exclude, '/' );
				}

				$method = ( ! isset( $_POST['zipmethod'] ) ? 'tar.gz' : $_POST['zipmethod'] );
				if ( 'tar.gz' === $method && ! function_exists( 'gzopen' ) ) {
					$method = 'zip';
				}

				$res = MainWP_Backup::get()->createFullBackup( $newExcludes, ( isset( $_POST['f'] ) ? $_POST['f'] : $_POST['file'] ), true, $includeCoreFiles, 0, false, false, false, false, $method );
				if ( ! $res ) {
					$information['backup'] = false;
				} else {
					$information['backup'] = $res['file'];
					$information['size']   = $res['filesize'];
				}

				//todo: RS: Remove this when the .18 is out
				$plugins = array();
				$dir     = WP_CONTENT_DIR . '/plugins/';
				$fh      = @opendir( $dir );
				while ( $entry = @readdir( $fh ) ) {
					if ( ! is_dir( $dir . $entry ) ) {
						continue;
					}
					if ( ( '.' === $entry ) || ( '..' === $entry ) ) {
						continue;
					}
					$plugins[] = $entry;
				}
				@closedir( $fh );
				$information['plugins'] = $plugins;

				$themes = array();
				$dir    = WP_CONTENT_DIR . '/themes/';
				$fh     = @opendir( $dir );
				while ( $entry = @readdir( $fh ) ) {
					if ( ! is_dir( $dir . $entry ) ) {
						continue;
					}
					if ( ( '.' === $entry ) || ( '..' === $entry ) ) {
						continue;
					}
					$themes[] = $entry;
				}
				@closedir( $fh );
				$information['themes'] = $themes;

				MainWP_Helper::write( $information );
			}
		}

		global $wp_rewrite;
		$snPluginDir = basename( $this->plugin_dir );
		if ( isset( $wp_rewrite->non_wp_rules[ 'wp-content/plugins/' . $snPluginDir . '/([^js\/]*)$' ] ) ) {
			unset( $wp_rewrite->non_wp_rules[ 'wp-content/plugins/' . $snPluginDir . '/([^js\/]*)$' ] );
		}

		if ( isset( $wp_rewrite->non_wp_rules[ 'wp-content/plugins/' . $snPluginDir . '/(.*)$' ] ) ) {
			unset( $wp_rewrite->non_wp_rules[ 'wp-content/plugins/' . $snPluginDir . '/(.*)$' ] );
		}

		if ( get_option( 'mainwp_child_fix_htaccess' ) === false ) {
			include_once( ABSPATH . '/wp-admin/includes/misc.php' );

			$wp_rewrite->flush_rules();
			MainWP_Helper::update_option( 'mainwp_child_fix_htaccess', 'yes', 'yes' );
		}

		$this->update_htaccess();

		global $current_user; //wp variable
		//Login the user
		if ( isset( $_REQUEST['login_required'] ) && ( '1' === $_REQUEST['login_required'] ) && isset( $_REQUEST['user'] ) ) {
            $alter_login_required = false;
            $username = rawurldecode( $_REQUEST['user'] );

            if ( isset( $_REQUEST['alt_user'] ) && !empty( $_REQUEST['alt_user'] ) )  {
                $alter_login_required = $this->check_login_as( $_REQUEST['alt_user'] );

                if ( $alter_login_required ) {
                    $username = rawurldecode( $_REQUEST['alt_user'] );
                }
            }

			if ( is_user_logged_in() ) {
				global $current_user;
				if ( 10 !== $current_user->wp_user_level && ( ! isset( $current_user->user_level ) || 10 !== $current_user->user_level ) && ! current_user_can( 'level_10' ) ) {
					do_action( 'wp_logout' );
				}
			}

			$signature = rawurldecode( isset( $_REQUEST['mainwpsignature'] ) ? $_REQUEST['mainwpsignature'] : '' );
			$file      = '';
			if ( isset( $_REQUEST['f'] ) ) {
				$file = $_REQUEST['f'];
			} else if ( isset( $_REQUEST['file'] ) ) {
				$file = $_REQUEST['file'];
			} else if ( isset( $_REQUEST['fdl'] ) ) {
				$file = $_REQUEST['fdl'];
			}

			$auth = $this->auth( $signature, rawurldecode( ( isset( $_REQUEST['where'] ) ? $_REQUEST['where'] : $file ) ), isset( $_REQUEST['nonce'] ) ? $_REQUEST['nonce'] : '', isset( $_REQUEST['nossl'] ) ? $_REQUEST['nossl'] : 0 );
			if ( ! $auth ) {
				return;
			}

			if ( ! is_user_logged_in() || $username !== $current_user->user_login ) {
				if ( ! $this->login( $username ) ) {
					return;
				}

				global $current_user;
				if ( 10 !== $current_user->wp_user_level && ( ! isset( $current_user->user_level ) || 10 !== $current_user->user_level ) && ! current_user_can( 'level_10' ) ) {
                    // if is not alternative admin login
                    // it is connected admin login
                    if ( ! $alter_login_required ) {
                        // log out if connected admin is not admin level 10
                        do_action( 'wp_logout' );

                        return;
                    }
				}
			}

			if ( isset( $_REQUEST['fdl'] ) ) {
				if ( stristr( $_REQUEST['fdl'], '..' ) ) {
					return;
				}

				$this->uploadFile( $_REQUEST['fdl'], isset( $_REQUEST['foffset'] ) ? $_REQUEST['foffset'] : 0 );
				exit;
			}

			$where = isset( $_REQUEST['where'] ) ? $_REQUEST['where'] : '';
			if ( isset( $_POST['f'] ) || isset( $_POST['file'] ) ) {
				$file = '';
				if ( isset( $_POST['f'] ) ) {
					$file = $_POST['f'];
				} else if ( isset( $_POST['file'] ) ) {
					$file = $_POST['file'];
				}

				$where = 'admin.php?page=mainwp_child_tab&tab=restore-clone';
				if ( '' === session_id() ) {
					session_start();
				}
				$_SESSION['file'] = $file;
				$_SESSION['size'] = $_POST['size'];
			}

            // to support open not wp-admin url
			$open_location = isset( $_REQUEST['open_location'] ) ? $_REQUEST['open_location'] : '';
			if ( ! empty( $open_location ) ) {
				$open_location = base64_decode( $open_location );
				$_vars         = MainWP_Helper::parse_query( $open_location );
				$_path         = parse_url( $open_location, PHP_URL_PATH );
				if ( isset( $_vars['_mwpNoneName'] ) && isset( $_vars['_mwpNoneValue'] ) ) {
					$_vars[ $_vars['_mwpNoneName'] ] = wp_create_nonce( $_vars['_mwpNoneValue'] );
					unset( $_vars['_mwpNoneName'] );
					unset( $_vars['_mwpNoneValue'] );
					$open_url = '';
					foreach ( $_vars as $key => $value ) {
						$open_url .= $key . '=' . $value . '&';
					}
					$open_url      = rtrim( $open_url, '&' );
					$open_location = '/wp-admin/' . $_path . '?' . $open_url;
				} else {
					if ( strpos( $open_location, 'nonce=child_temp_nonce' ) !== false ) {
						$open_location = str_replace( 'nonce=child_temp_nonce', 'nonce=' . wp_create_nonce( 'wp-ajax' ), $open_location );
					}
				}
				wp_redirect( site_url() . $open_location );
				exit();
			}

			wp_redirect( admin_url( $where ) );
			exit();
		}

		/**
		 * Security
		 */
		MainWP_Security::fixAll();
		MainWP_Debug::process($this);

		//Register does not require auth, so we register here..
		if ( isset( $_POST['function'] ) && 'register' === $_POST['function'] ) {
			define( 'DOING_CRON', true );
			MainWP_Child::fix_for_custom_themes();
			$this->registerSite();
		}

		$auth = $this->auth( isset( $_POST['mainwpsignature'] ) ? $_POST['mainwpsignature'] : '', isset( $_POST['function'] ) ? $_POST['function'] : '', isset( $_POST['nonce'] ) ? $_POST['nonce'] : '', isset( $_POST['nossl'] ) ? $_POST['nossl'] : 0 );

		if ( ! $auth && isset( $_POST['mainwpsignature'] ) ) {
			MainWP_Helper::error( __( 'Authentication failed! Please deactivate & re-activate the MainWP Child plugin on this site and try again.', 'mainwp-child' ) );
		}

		if ( ! $auth && isset( $_POST['function'] ) && isset( $this->callableFunctions[ $_POST['function'] ] ) && ! isset( $this->callableFunctionsNoAuth[ $_POST['function'] ] ) ) {
			MainWP_Helper::error( __( 'Authentication failed! Please deactivate & re-activate the MainWP Child plugin on this site and try again.', 'mainwp-child' ) );
		}

        $auth_user = false;
		if ( $auth ) {
			//Check if the user exists & is an administrator
			if ( isset( $_POST['function'] ) && isset( $_POST['user'] ) ) {

                $user = null;
                if ( isset( $_POST['alt_user'] ) && !empty( $_POST['alt_user'] ) )  {
                    if ( $this->check_login_as( $_POST['alt_user'] ) ) {
                        $auth_user = $_POST['alt_user'];
                        $user = get_user_by( 'login', $auth_user );
                    }
                }

                // if alternative admin not existed
                if ( ! $user ) {
                    // check connected admin existed
                    $user = get_user_by( 'login', $_POST['user'] );
                    $auth_user = $_POST['user'];
                }

				if ( ! $user ) {
					MainWP_Helper::error( __( 'Unexising administrator username. Please verify that it is an existing administrator.', 'mainwp-child' ) );
				}

				if ( 10 != $user->wp_user_level && ( ! isset( $user->user_level ) || 10 != $user->user_level ) && ! $user->has_cap( 'level_10' ) ) {
					MainWP_Helper::error( __( 'Invalid user. Please verify that the user has administrator privileges.', 'mainwp-child' ) );
				}

				$this->login( $auth_user );
			}

			if ( isset( $_POST['function'] ) && 'visitPermalink' === $_POST['function'] ) {

                if (empty($auth_user)) {
                    $auth_user = $_POST['user'];
                }

				if ( $this->login( $auth_user, true ) ) {
					return;
				} else {
					exit();
				}
			}

			//Redirect to the admin part if needed
			if ( isset( $_POST['admin'] ) && '1' === $_POST['admin'] ) {
				wp_redirect( get_option( 'siteurl' ) . '/wp-admin/' );
				die();
			}
		}

        // Init extensions
        // Handle fatal errors for those init if needed
        // OK
        MainWP_Child_iThemes_Security::Instance()->ithemes_init();
        MainWP_Child_Updraft_Plus_Backups::Instance()->updraftplus_init();
        MainWP_Child_Back_Up_Wordpress::Instance()->init();
        MainWP_Child_WP_Rocket::Instance()->init();
        MainWP_Child_Back_WP_Up::Instance()->init();
        MainWP_Child_Back_Up_Buddy::Instance();
        MainWP_Child_Wordfence::Instance()->wordfence_init();
        MainWP_Child_Timecapsule::Instance()->init();
        MainWP_Child_Staging::Instance()->init();
        MainWP_Child_Branding::Instance()->branding_init();
        MainWP_Client_Report::Instance()->creport_init();
        MainWP_Child_Pagespeed::Instance()->init();
        MainWP_Child_Links_Checker::Instance()->init();
        MainWP_Child_WPvivid_BackupRestore::Instance()->init();
        global $_wp_submenu_nopriv;
        if ($_wp_submenu_nopriv === null)
            $_wp_submenu_nopriv = array(); // fix warning

		//Call the function required
		if ( $auth && isset( $_POST['function'] ) && isset( $this->callableFunctions[ $_POST['function'] ] ) ) {
			define( 'DOING_CRON', true );
//			ob_start();
//            require_once( ABSPATH . 'wp-admin/admin.php' );
//			ob_end_clean();

            MainWP_Helper::handle_fatal_error();
			MainWP_Child::fix_for_custom_themes();
			call_user_func( array( $this, $this->callableFunctions[ $_POST['function'] ] ) );
		} else if ( isset( $_POST['function'] ) && isset( $this->callableFunctionsNoAuth[ $_POST['function'] ] ) ) {
			define( 'DOING_CRON', true );
			MainWP_Child::fix_for_custom_themes();
			call_user_func( array( $this, $this->callableFunctionsNoAuth[ $_POST['function'] ] ) );
		} else if (isset( $_POST['function'] ) && isset( $_POST['mainwpsignature'] )  && !isset($this->callableFunctions[ $_POST['function'] ]) && !isset( $this->callableFunctionsNoAuth[ $_POST['function'] ]) ) {
            MainWP_Helper::error( __( 'Required version has not been detected. Please, make sure that you are using the latest version of the MainWP Child plugin on your site.', 'mainwp-child' ) );
        }


		if ( 1 === (int) get_option( 'mainwpKeywordLinks' ) ) {
			new MainWP_Keyword_Links();
			if ( ! is_admin() ) {
				add_filter( 'the_content', array( MainWP_Keyword_Links::Instance(), 'filter_content' ), 100 );
			}
			MainWP_Keyword_Links::Instance()->update_htaccess(); // if needed
			MainWP_Keyword_Links::Instance()->redirect_cloak();
		} else if ( 'yes' === get_option( 'mainwp_keyword_links_htaccess_set' ) ) {
			MainWP_Keyword_Links::clear_htaccess(); // force clear
		}

	}

    // Check to support login by alternative admin
    // return false will login by connected admin user
    // return true will try to login as alternative user
    function check_login_as( $alter_login ) {

        if ( !empty( $alter_login ) )  {
            // check alternative admin existed
            $user = get_user_by( 'login', $alter_login );

            if ( ! $user ) {
                //That administrator username was not found on this child site
                return false;
            }

            if ( 10 != $user->wp_user_level && ( ! isset( $user->user_level ) || 10 != $user->user_level ) && ! $user->has_cap( 'level_10' ) ) {
                // That user is not an administrator
                return false;
            }

            return true; // ok, will try to login by alternative user
        }

        return false;
    }

	function default_option_active_plugins( $default ) {
		if ( ! is_array( $default ) ) {
			$default = array();
		}
		if ( ! in_array( 'managewp/init.php', $default ) ) {
			$default[] = 'managewp/init.php';
		}

		return $default;
	}

	function auth( $signature, $func, $nonce, $pNossl ) {
		if ( ! isset( $signature ) || ! isset( $func ) || ( ! get_option( 'mainwp_child_pubkey' ) && ! get_option( 'mainwp_child_nossl_key' ) ) ) {
			$auth = false;
		} else {
			$nossl       = get_option( 'mainwp_child_nossl' );
			$serverNoSsl = ( isset( $pNossl ) && 1 === (int) $pNossl );

			if ( ( 1 === (int) $nossl ) || $serverNoSsl ) {
                $auth = hash_equals( md5( $func . $nonce . get_option( 'mainwp_child_nossl_key' ) ), base64_decode( $signature ) );
			} else {
				$auth = openssl_verify( $func . $nonce, base64_decode( $signature ), base64_decode( get_option( 'mainwp_child_pubkey' ) ) );
                if ($auth !== 1) {
                    $auth = false;
                }
			}
		}

		return $auth;
	}

	//Login..
	function login( $username, $doAction = false ) {
		global $current_user;

		//Logout if required
		if ( isset( $current_user->user_login ) ) {
			if ( $current_user->user_login === $username ) {

                // to fix issue multi user session
                $user_id = wp_validate_auth_cookie();
                if ( $user_id && $user_id ===  $current_user->ID ) {
                    return true;
                }

				wp_set_auth_cookie( $current_user->ID );
				return true;
			}
			do_action( 'wp_logout' );
		}

		$user = get_user_by( 'login', $username );
		if ( $user ) { //If user exists, login
			//            wp_set_current_user($user->ID, $user->user_login);
			//            wp_set_auth_cookie($user->ID);

			wp_set_current_user( $user->ID );
            wp_set_auth_cookie( $user->ID );
			if ( $doAction ) {
				do_action( 'wp_login', $user->user_login );
			}

			return ( is_user_logged_in() && $current_user->user_login === $username );
		}

		return false;
	}

	function noSSLFilterFunction( $r, $url ) {
		$r['sslverify'] = false;

		return $r;
	}

	public function http_request_reject_unsafe_urls( $r, $url ) {
		$r['reject_unsafe_urls'] = false;
		if ( isset($_POST['wpadmin_user']) && !empty($_POST['wpadmin_user']) && isset($_POST['wpadmin_passwd']) && !empty($_POST['wpadmin_passwd']) ) {
			$auth = base64_encode( $_POST['wpadmin_user'] . ':' . $_POST['wpadmin_passwd'] );
			$r['headers']['Authorization'] = "Basic $auth";
		}
		return $r;
	}

	/**
	 * Functions to support core functionality
	 */
	function installPluginTheme() {
		$wp_filesystem = $this->getWPFilesystem();

		if ( ! isset( $_POST['type'] ) || ! isset( $_POST['url'] ) || ( 'plugin' !== $_POST['type'] && 'theme' !== $_POST['type'] ) || '' === $_POST['url'] ) {
			MainWP_Helper::error( __( 'Invalid request!', 'mainwp-child' ) );
		}
		//        if (file_exists(ABSPATH . '/wp-admin/includes/deprecated.php')) include_once(ABSPATH . '/wp-admin/includes/deprecated.php');
		if ( file_exists( ABSPATH . '/wp-admin/includes/screen.php' ) ) {
			include_once( ABSPATH . '/wp-admin/includes/screen.php' );
		}
		include_once( ABSPATH . '/wp-admin/includes/template.php' );
		include_once( ABSPATH . '/wp-admin/includes/misc.php' );
		include_once( ABSPATH . '/wp-admin/includes/class-wp-upgrader.php' );
		include_once( ABSPATH . '/wp-admin/includes/plugin.php' );

		$urlgot = json_decode( stripslashes( $_POST['url'] ) );

		$urls = array();
		if ( ! is_array( $urlgot ) ) {
			$urls[] = $urlgot;
		} else {
			$urls = $urlgot;
		}

		$result = array();
		foreach ( $urls as $url ) {
			$installer = new WP_Upgrader();
			$ssl_verify = true;
			//@see wp-admin/includes/class-wp-upgrader.php
			if ( isset( $_POST['sslVerify'] ) && '0' === $_POST['sslVerify'] ) {
				add_filter( 'http_request_args', array( &$this, 'noSSLFilterFunction' ), 99, 2 );
				$ssl_verify = false;
			}
			add_filter( 'http_request_args', array( &$this, 'http_request_reject_unsafe_urls' ), 99, 2 );

			$result = $installer->run( array(
				'package'           => $url,
				'destination'       => ( 'plugin' === $_POST['type'] ? WP_PLUGIN_DIR
					: WP_CONTENT_DIR . '/themes' ),
				'clear_destination' => ( isset( $_POST['overwrite'] ) && $_POST['overwrite'] ),
				//overwrite files?
				'clear_working'     => true,
				'hook_extra'        => array(),
			) );

			if ( is_wp_error( $result ) ) {
				if ( true == $ssl_verify && strpos( $url, 'https://' ) === 0) {
					// retry
					add_filter( 'http_request_args', array( &$this, 'noSSLFilterFunction' ), 99, 2 );
					$ssl_verify = false;
					$result = $installer->run( array(
						'package'           => $url,
						'destination'       => ( 'plugin' === $_POST['type'] ? WP_PLUGIN_DIR
							: WP_CONTENT_DIR . '/themes' ),
						'clear_destination' => ( isset( $_POST['overwrite'] ) && $_POST['overwrite'] ),
						//overwrite files?
						'clear_working'     => true,
						'hook_extra'        => array(),
					) );
				}

				if ( is_wp_error( $result ) ) {
					$err_code = $result->get_error_code();
					if ( $result->get_error_data() && is_string( $result->get_error_data() ) ) {
						$error = $result->get_error_data();
						MainWP_Helper::error( $error, $err_code );
					} else {
						MainWP_Helper::error( implode( ', ', $error ), $err_code );
					}
				}
			}

			remove_filter( 'http_request_args', array( &$this, 'http_request_reject_unsafe_urls' ), 99, 2 );
			if ( false == $ssl_verify ) {
				remove_filter( 'http_request_args', array( &$this, 'noSSLFilterFunction' ), 99 );
			}

			$args = array( 'success' => 1, 'action' => 'install' );
			if ( 'plugin' === $_POST['type'] ) {
				$path     = $result['destination'];
				$fileName = '';
				$rslt     = null;
				wp_cache_set( 'plugins', array(), 'plugins' );
				foreach ( $result['source_files'] as $srcFile ) {
					if ( is_dir( $path . $srcFile ) ) {
						continue;
					}
					$thePlugin = get_plugin_data( $path . $srcFile );
					if ( null !== $thePlugin && '' !== $thePlugin && '' !== $thePlugin['Name'] ) {
						$args['type']    = 'plugin';
						$args['Name']    = $thePlugin['Name'];
						$args['Version'] = $thePlugin['Version'];
						$args['slug']    = $result['destination_name'] . '/' . $srcFile;
						$fileName        = $srcFile;
						break;
					}
				}

				if ( ! empty( $fileName ) ) {
					do_action( 'mainwp_child_installPluginTheme', $args );
					if ( isset( $_POST['activatePlugin'] ) && 'yes' === $_POST['activatePlugin'] ) {
						 // to fix activate issue
                        if ('quotes-collection/quotes-collection.php' == $args['slug']) {
                            activate_plugin( $path . $fileName, '', false, true );
                        } else {
                            activate_plugin( $path . $fileName, '' /* false, true */ );
                        }
						//do_action( 'activate_plugin', $args['slug'], null );
					}
				}
			} else {
				$args['type'] = 'theme';
				$args['slug'] = $result['destination_name'];
				do_action( 'mainwp_child_installPluginTheme', $args );
			}

			//            if ($_POST['type'] == 'plugin' && isset($_POST['activatePlugin']) && $_POST['activatePlugin'] == 'yes')
			//            {
			//                $path = $result['destination'];
			//                $rslt = null;
			//                wp_cache_set('plugins', array(), 'plugins');
			//                foreach ($result['source_files'] as $srcFile)
			//                {
			//                    if (is_dir($path . $srcFile)) continue;
			//
			//                    $thePlugin = get_plugin_data($path . $srcFile);
			//                    if ($thePlugin != null && $thePlugin != '' && $thePlugin['Name'] != '')
			//                    {
			//                        activate_plugin($path . $srcFile, '', false, true);
			//
			//                    }
			//                }
			//            }
		}
		$information['installation']     = 'SUCCESS';
		$information['destination_name'] = $result['destination_name'];
		MainWP_Helper::write( $information );
	}

	//This will upgrade WP
	function upgradeWP() {
		global $wp_version;
		$wp_filesystem = $this->getWPFilesystem();

		$information = array();

		include_once( ABSPATH . '/wp-admin/includes/update.php' );
		include_once( ABSPATH . '/wp-admin/includes/class-wp-upgrader.php' );
		//        if (file_exists(ABSPATH . '/wp-admin/includes/deprecated.php')) include_once(ABSPATH . '/wp-admin/includes/deprecated.php');
		if ( file_exists( ABSPATH . '/wp-admin/includes/screen.php' ) ) {
			include_once( ABSPATH . '/wp-admin/includes/screen.php' );
		}
		if ( file_exists( ABSPATH . '/wp-admin/includes/template.php' ) ) {
			include_once( ABSPATH . '/wp-admin/includes/template.php' );
		}
		include_once( ABSPATH . '/wp-admin/includes/file.php' );
		include_once( ABSPATH . '/wp-admin/includes/misc.php' );

		if ( null !== $this->filterFunction ) {
			add_filter( 'pre_site_transient_update_core', $this->filterFunction, 99 );
		}
		if ( null !== $this->filterFunction ) {
			add_filter( 'pre_transient_update_core', $this->filterFunction, 99 );
		}

		//Check for new versions
		@wp_version_check();

		$core_updates = get_core_updates();
		if ( is_array($core_updates) && count( $core_updates ) > 0 ) {
			foreach ( $core_updates as $core_update ) {
				if ( 'latest' === $core_update->response ) {
					$information['upgrade'] = 'SUCCESS';
				} else if ( 'upgrade' === $core_update->response && $core_update->locale === get_locale() && version_compare( $wp_version, $core_update->current, '<=' ) ) {
					//Upgrade!
					$upgrade = false;
					if ( class_exists( 'Core_Upgrader' ) ) {
						$core    = new Core_Upgrader();
						$upgrade = $core->upgrade( $core_update );
					}
					//If this does not work - add code from /wp-admin/includes/class-wp-upgrader.php in the newer versions
					//So users can upgrade older versions too.
					//3rd option: 'wp_update_core'

					if ( ! is_wp_error( $upgrade ) ) {
						$information['upgrade'] = 'SUCCESS';
					} else {
						$information['upgrade'] = 'WPERROR';
					}
					break;
				}
			}

			if ( ! isset( $information['upgrade'] ) ) {
				foreach ( $core_updates as $core_update ) {
					if ( 'upgrade' === $core_update->response && version_compare( $wp_version, $core_update->current, '<=' ) ) {
						//Upgrade!
						$upgrade = false;
						if ( class_exists( 'Core_Upgrader' ) ) {
							$core    = new Core_Upgrader();
							$upgrade = $core->upgrade( $core_update );
						}
						//If this does not work - add code from /wp-admin/includes/class-wp-upgrader.php in the newer versions
						//So users can upgrade older versions too.
						//3rd option: 'wp_update_core'

						if ( ! is_wp_error( $upgrade ) ) {
							$information['upgrade'] = 'SUCCESS';
						} else {
							$information['upgrade'] = 'WPERROR';
						}
						break;
					}
				}
			}
		} else {
			$information['upgrade'] = 'NORESPONSE';
		}
		if ( null !== $this->filterFunction ) {
			remove_filter( 'pre_site_transient_update_core', $this->filterFunction, 99 );
		}
		if ( null !== $this->filterFunction ) {
			remove_filter( 'pre_transient_update_core', $this->filterFunction, 99 );
		}

		MainWP_Helper::write( $information );
	}

	function upgradeTranslation() {
		//Prevent disable/re-enable at upgrade
		define( 'DOING_CRON', true );

		MainWP_Helper::getWPFilesystem();
		include_once( ABSPATH . '/wp-admin/includes/class-wp-upgrader.php' );
		if ( file_exists( ABSPATH . '/wp-admin/includes/screen.php' ) ) {
			include_once( ABSPATH . '/wp-admin/includes/screen.php' );
		}
		if ( file_exists( ABSPATH . '/wp-admin/includes/template.php' ) ) {
			include_once( ABSPATH . '/wp-admin/includes/template.php' );
		}
		if ( file_exists( ABSPATH . '/wp-admin/includes/misc.php' ) ) {
			include_once( ABSPATH . '/wp-admin/includes/misc.php' );
		}
		include_once( ABSPATH . '/wp-admin/includes/file.php' );

		include_once( ABSPATH . 'wp-admin/includes/class-wp-upgrader.php' );

        // to fix
        @wp_version_check();
        @wp_update_themes();
        @wp_update_plugins();

		$upgrader = new Language_Pack_Upgrader( new Language_Pack_Upgrader_Skin( compact( 'url', 'nonce', 'title', 'context' ) ) );
		$translations = explode( ',', urldecode( $_POST['list'] ) );
		$all_language_updates = wp_get_translation_updates();

		$language_updates = array();
		foreach ( $all_language_updates as $current_language_update ) {
			if ( in_array( $current_language_update->slug, $translations ) ) {
				$language_updates[] = $current_language_update;
			}
		}

		$result = count( $language_updates ) == 0 ? false : $upgrader->bulk_upgrade( $language_updates );
		if ( ! empty( $result ) ) {
			for ( $i = 0; $i < count( $result ); $i++ ) {
				if ( empty( $result[$i] ) || is_wp_error( $result[$i] ) ) {
					$information['upgrades'][ $language_updates[$i]->slug ] = false;
				} else {
					$information['upgrades'][ $language_updates[$i]->slug ] = true;
				}
			}
		} else {
			//MainWP_Helper::error( __( 'Invalid request!', 'mainwp-child' ) );
            $information['upgrades'] = array(); // to fix error message when translations updated
		}

		$information['sync'] = $this->getSiteStats( array(), false );
		MainWP_Helper::write( $information );
	}

	/**
	 * Expects $_POST['type'] == plugin/theme
	 * $_POST['list'] == 'theme1,theme2' or 'plugin1,plugin2'
	 */
	function upgradePluginTheme() {
		//Prevent disable/re-enable at upgrade
        if (!defined( 'DOING_CRON') )
            define( 'DOING_CRON', true );

		MainWP_Helper::getWPFilesystem();

		include_once( ABSPATH . '/wp-admin/includes/class-wp-upgrader.php' );
		//        if (file_exists(ABSPATH . '/wp-admin/includes/deprecated.php')) include_once(ABSPATH . '/wp-admin/includes/deprecated.php');
		if ( file_exists( ABSPATH . '/wp-admin/includes/screen.php' ) ) {
			include_once( ABSPATH . '/wp-admin/includes/screen.php' );
		}
		if ( file_exists( ABSPATH . '/wp-admin/includes/template.php' ) ) {
			include_once( ABSPATH . '/wp-admin/includes/template.php' );
		}
		if ( file_exists( ABSPATH . '/wp-admin/includes/misc.php' ) ) {
			include_once( ABSPATH . '/wp-admin/includes/misc.php' );
		}
		include_once( ABSPATH . '/wp-admin/includes/file.php' );
		include_once( ABSPATH . '/wp-admin/includes/plugin.php' );
        include_once( ABSPATH . '/wp-admin/includes/plugin-install.php' );

		$information                    = array();
		$information['upgrades']        = array();
		$mwp_premium_updates_todo       = array();
		$mwp_premium_updates_todo_slugs = array();
		if ( isset( $_POST['type'] ) && 'plugin' === $_POST['type'] ) {
			include_once( ABSPATH . '/wp-admin/includes/update.php' );
			if ( null !== $this->filterFunction ) {
//				ET_Automatic_Updates
				add_filter( 'pre_site_transient_update_plugins', $this->filterFunction, 99 );
			}

  			$plugins = explode( ',', urldecode( $_POST['list'] ) );

			// To fix: backupbuddy update
			if ( in_array( 'backupbuddy/backupbuddy.php', $plugins ) ) {
				if ( isset( $GLOBALS['ithemes_updater_path'] ) ) {
					if ( ! class_exists( 'Ithemes_Updater_Settings' ) ) {
						require( $GLOBALS['ithemes_updater_path'] . '/settings.php' );
					}
					if ( class_exists( 'Ithemes_Updater_Settings' ) ) {
						$ithemes_updater = new Ithemes_Updater_Settings();
						$ithemes_updater->update();
					}
				}
			}
			////

			// to fix: smart-manager-for-wp-e-commerce update
			if (in_array('smart-manager-for-wp-e-commerce/smart-manager.php', $plugins)) {
				if (file_exists(plugin_dir_path( __FILE__ ) . '../../smart-manager-for-wp-e-commerce/pro/upgrade.php') && file_exists(plugin_dir_path( __FILE__ ) . '../../smart-manager-for-wp-e-commerce/smart-manager.php')) {
					include_once plugin_dir_path( __FILE__ ) . '../../smart-manager-for-wp-e-commerce/smart-manager.php';
					include_once (plugin_dir_path( __FILE__ ) . '../../smart-manager-for-wp-e-commerce/pro/upgrade.php');
				}
			}
			////

			global $wp_current_filter;
			$wp_current_filter[] = 'load-plugins.php';
			@wp_update_plugins();

			// trick to prevent some premium plugins re-create update info
			remove_all_filters('pre_set_site_transient_update_plugins');

            // to support cached premium plugins update info, hooking in the bulk_upgrade()
            add_filter( 'pre_site_transient_update_plugins', array( $this, 'set_cached_update_plugins' ) );

			$information['plugin_updates'] = get_plugin_updates();

			$plugins        = explode( ',', urldecode( $_POST['list'] ) );
			$premiumPlugins = array();
			$premiumUpdates = get_option( 'mainwp_premium_updates' );
			if ( is_array( $premiumUpdates ) ) {
				$newPlugins = array();
				foreach ( $plugins as $plugin ) {
					if ( in_array( $plugin, $premiumUpdates ) ) {
						$premiumPlugins[] = $plugin;
					} else {
						$newPlugins[] = $plugin;
					}
				}
				$plugins = $newPlugins;
			}

			if ( count( $plugins ) > 0 ) {
				//@see wp-admin/update.php
                $failed = true;
                // to fix update of Yithemes premiums plugins that hooked to upgrader_pre_download
                $url = 'update.php?action=update-selected&amp;plugins=' . urlencode(implode(',', $plugins));
                $nonce = 'bulk-update-plugins';

                $upgrader = new Plugin_Upgrader( new Bulk_Plugin_Upgrader_Skin( compact( 'nonce', 'url' ) ) );
                $result   = $upgrader->bulk_upgrade( $plugins );

                if ( ! empty( $result ) ) {
                    foreach ( $result as $plugin => $info ) {
                        if ( empty( $info ) ) {

                            $information['upgrades'][ $plugin ] = false;
                            // try to fix if that is premiums update
                            $api = apply_filters( 'plugins_api', false, 'plugin_information', array( 'slug' => $plugin ) );

                            if ( !is_wp_error( $api ) && !empty($api)) {
                                if ( isset($api->download_link) ) {
                                    $res = $upgrader->install($api->download_link);
                                    if ( !is_wp_error( $res ) && !(is_null( $res )) ) {
                                        $information['upgrades'][ $plugin ] = true;
                                    }
                                }
                            }

                        } else {
                            $information['upgrades'][ $plugin ] = true;
                            // to fix logging update
//                            if (isset($information['plugin_updates']) && isset($information['plugin_updates'][$plugin])) {
//                                $plugin_info = $information['plugin_updates'][$plugin];
//                                $args = array();
//                                $args['type']    = 'plugin';
//                                $args['slug']    = $plugin;
//                                $args['name']    = $plugin_info->Name;
//                                $args['version'] = $plugin_info->update->new_version;
//                                $args['old_version'] = $plugin_info->Version;
//                                $args['action'] = 'update';
//                                //do_action( 'mainwp_child_upgradePluginTheme', $args );
//                            }
                        }
                    }
                    $failed = false;
                }

                if ($failed) {
                    MainWP_Helper::error( __( 'Invalid request!', 'mainwp-child' ) );
                }
			}

            remove_filter( 'pre_site_transient_update_plugins', array( $this, 'set_cached_update_plugins' ), 10 );
            delete_site_transient( 'mainwp_update_plugins_cached' ); // to fix cached update info

			if ( count( $premiumPlugins ) > 0 ) {
				$mwp_premium_updates = apply_filters( 'mwp_premium_perform_update', array() );
				if ( is_array( $mwp_premium_updates ) && is_array( $premiumPlugins ) ) {
					foreach ( $premiumPlugins as $premiumPlugin ) {
						foreach ( $mwp_premium_updates as $key => $update ) {
							$slug = ( isset( $update['slug'] ) ? $update['slug'] : $update['Name'] );
							if ( 0 === strcmp( $slug, $premiumPlugin ) ) {
								$mwp_premium_updates_todo[ $key ] = $update;
								$mwp_premium_updates_todo_slugs[] = $premiumPlugin;
							}
						}
					}
				}
				unset( $mwp_premium_updates );
				$premiumUpgrader = new Plugin_Upgrader( new Bulk_Plugin_Upgrader_Skin( compact( 'nonce', 'url' ) ) );
			}

			if ( count( $plugins ) <= 0 && count( $premiumPlugins ) <= 0 ) {
				MainWP_Helper::error( __( 'Invalid request!', 'mainwp-child' ) );
			}

			if ( null !== $this->filterFunction ) {
				remove_filter( 'pre_site_transient_update_plugins', $this->filterFunction, 99 );
			}
		} else if ( isset( $_POST['type'] ) && 'theme' === $_POST['type'] ) {

			$last_update = get_site_transient( 'update_themes' );

			include_once( ABSPATH . '/wp-admin/includes/update.php' );
			if ( null !== $this->filterFunction ) {
				add_filter( 'pre_site_transient_update_themes', $this->filterFunction, 99 );
			}

//			$last_update = get_site_transient( 'update_themes' );
//			$originalLastChecked = !empty( $last_update ) && property_exists( $last_update, 'last_checked' ) ? $last_update->last_checked : 0;

			@wp_update_themes();
			include_once( ABSPATH . '/wp-admin/includes/theme.php' );

            // to support cached premium themes update info, hooking in the bulk_upgrade()
            add_filter( 'pre_site_transient_update_themes', array( $this, 'set_cached_update_themes' ) );

			$information['theme_updates'] = $this->upgrade_get_theme_updates();
			$themes                       = explode( ',', $_POST['list'] );
			$premiumThemes                = array();
			$premiumUpdates               = get_option( 'mainwp_premium_updates' );
			if ( is_array( $premiumUpdates ) ) {
				$newThemes = array();
				foreach ( $themes as $theme ) {
					if ( in_array( $theme, $premiumUpdates ) ) {
						$premiumThemes[] = $theme;
					} else {
						$newThemes[] = $theme;
					}
				}
				$themes = $newThemes;
			}

			if ( count( $themes ) > 0 ) {
				// To fix: optimizePressTheme update
				$addFilterToFixUpdate_optimizePressTheme = false;
				if ( in_array( 'optimizePressTheme', $themes ) ) {
					$addFilterToFixUpdate_optimizePressTheme = true;
					add_filter( 'site_transient_update_themes', array( $this, 'hookFixOptimizePressThemeUpdate' ), 99 );
				}

				//@see wp-admin/update.php
				if ( null !== $this->filterFunction ) {
					remove_filter( 'pre_site_transient_update_plugins', $this->filterFunction, 99 );
				}

				$last_update2 = get_site_transient( 'update_themes' );
				set_site_transient( 'update_themes', $last_update );
//				if ( !empty( $last_update ) && property_exists( $last_update, 'last_checked' ) ) {
//					$last_update->last_checked = $originalLastChecked;
//					set_site_transient( 'update_themes', $last_update );
//				}

//				@wp_update_themes();
                $failed = true;
                $upgrader = new Theme_Upgrader( new Bulk_Theme_Upgrader_Skin( compact( 'nonce', 'url' ) ) );
                $result   = $upgrader->bulk_upgrade( $themes );
                if ( ! empty( $result ) ) {
                    foreach ( $result as $theme => $info ) {
                        if ( empty( $info ) ) {
                            $information['upgrades'][ $theme ] = false;
                        } else {
                            $information['upgrades'][ $theme ] = true;
                            // to fix logging update
//                            if (isset($information['theme_updates']) && isset($information['theme_updates'][$theme])) {
//                                $theme_info = $information['theme_updates'][$theme];
//                                $args = array();
//                                $args['type']    = 'theme';
//                                $args['slug']    = $theme;
//                                $args['name']    = $theme_info['Name'];
//                                $args['version'] = $theme_info['update']['new_version'];
//                                $args['old_version'] = $theme_info['Version'];
//                                $args['action'] = 'update';
//                                //do_action( 'mainwp_child_upgradePluginTheme', $args );
//                            }

                        }
                    }
                    $failed = false;
                }

                if ($failed) {
                    MainWP_Helper::error( __( 'Invalid request!', 'mainwp-child' ) );
                }

				if ( null !== $this->filterFunction ) {
					add_filter( 'pre_site_transient_update_themes', $this->filterFunction, 99 );
				}

				set_site_transient( 'update_themes', $last_update2 );

				if ( $addFilterToFixUpdate_optimizePressTheme ) {
					remove_filter( 'site_transient_update_themes', array(
						$this,
						'hookFixOptimizePressThemeUpdate',
					), 99 );
				}

			}

            remove_filter( 'pre_site_transient_update_themes', array( $this, 'set_cached_update_themes' ), 10 );
            delete_site_transient( 'mainwp_update_themes_cached' ); // to fix cached update info


//			$last_update = get_site_transient( 'update_themes' );
//			if ( !empty( $last_update ) && property_exists( $last_update, 'last_checked' ) ) {
//				$last_update->last_checked = $originalLastChecked;
//				set_site_transient( 'update_themes', $last_update );
//			}

//			@wp_update_themes();

			if ( count( $premiumThemes ) > 0 ) {
				$mwp_premium_updates            = apply_filters( 'mwp_premium_perform_update', array() );
				$mwp_premium_updates_todo       = array();
				$mwp_premium_updates_todo_slugs = array();
				if ( is_array( $premiumThemes ) && is_array( $mwp_premium_updates ) ) {
					foreach ( $premiumThemes as $premiumTheme ) {
						foreach ( $mwp_premium_updates as $key => $update ) {
							$slug = ( isset( $update['slug'] ) ? $update['slug'] : $update['Name'] );
							if ( 0 === strcmp( $slug, $premiumTheme ) ) {
								$mwp_premium_updates_todo[ $key ] = $update;
								$mwp_premium_updates_todo_slugs[] = $slug;
							}
						}
					}
				}
				unset( $mwp_premium_updates );

				$premiumUpgrader = new Theme_Upgrader( new Bulk_Theme_Upgrader_Skin( compact( 'nonce', 'url' ) ) );
			}
			if ( count( $themes ) <= 0 && count( $premiumThemes ) <= 0 ) {
				MainWP_Helper::error( __( 'Invalid request!', 'mainwp-child' ) );
			}

			if ( null !== $this->filterFunction ) {
				remove_filter( 'pre_site_transient_update_themes', $this->filterFunction, 99 );
			}
		} else {
			MainWP_Helper::error( __( 'Invalid request!', 'mainwp-child' ) );
		}

		if ( count( $mwp_premium_updates_todo ) > 0 ) {
			//Upgrade via WP
			//@see wp-admin/update.php
			$result = $premiumUpgrader->bulk_upgrade( $mwp_premium_updates_todo_slugs );
			if ( ! empty( $result ) ) {
				foreach ( $result as $plugin => $info ) {
					if ( ! empty( $info ) ) {
						$information['upgrades'][ $plugin ] = true;

						foreach ( $mwp_premium_updates_todo as $key => $update ) {
							$slug = ( isset( $update['slug'] ) ? $update['slug'] : $update['Name'] );
							if ( 0 === strcmp( $slug, $plugin ) ) {
								//unset($mwp_premium_updates_todo[$key]);
							}
						}
					}
				}
			}

			//Upgrade via callback
			foreach ( $mwp_premium_updates_todo as $update ) {
				$slug = ( isset( $update['slug'] ) ? $update['slug'] : $update['Name'] );

				if ( isset( $update['url'] ) ) {
					$installer = new WP_Upgrader();
					//@see wp-admin/includes/class-wp-upgrader.php
					$result                           = $installer->run( array(
						'package'           => $update['url'],
						'destination'       => ( 'plugin' === $update['type'] ? WP_PLUGIN_DIR : WP_CONTENT_DIR . '/themes' ),
						'clear_destination' => true,
						'clear_working'     => true,
						'hook_extra'        => array(),
					) );
					$information['upgrades'][ $slug ] = ( ! is_wp_error( $result ) && ! empty( $result ) );
				} else if ( isset( $update['callback'] ) ) {
					if ( is_array( $update['callback'] ) && isset( $update['callback'][0] ) && isset( $update['callback'][1] ) ) {
						$update_result                    = @call_user_func( array(
							$update['callback'][0],
							$update['callback'][1],
						) );
						$information['upgrades'][ $slug ] = $update_result && true;
					} else if ( is_string( $update['callback'] ) ) {
						$update_result                    = @call_user_func( $update['callback'] );
						$information['upgrades'][ $slug ] = $update_result && true;
					} else {
						$information['upgrades'][ $slug ] = false;
					}
				} else {
					$information['upgrades'][ $slug ] = false;
				}
			}
		}
		$information['sync'] = $this->getSiteStats( array(), false );
		MainWP_Helper::write( $information );
	}

    public function set_cached_update_plugins( $false = false, $_transient_data = null ) {

		if ( ! is_object( $_transient_data ) ) {
			$_transient_data = new stdClass;
		}

        $pre = false;
		$cached_update_info = get_site_transient( 'mainwp_update_plugins_cached' );
        if ( is_array($cached_update_info) && count($cached_update_info) > 0 ) {
            foreach( $cached_update_info as $slug => $info ) {
                if ( !isset( $_transient_data->response[ $slug ] ) && isset($info->update) ) {
                    $_transient_data->response[ $slug ] = $info->update;
                    $pre = true;
                }
            }
        }

        if ($pre == false)
            return $false;

        return $_transient_data;
    }

    public function set_cached_update_themes( $false = false, $_transient_data = null ) {

		if ( ! is_object( $_transient_data ) ) {
			$_transient_data = new stdClass;
		}

        $pre = false;
		$cached_update_info = get_site_transient( 'mainwp_update_themes_cached' );
        if ( is_array($cached_update_info) && count($cached_update_info) > 0 ) {
            foreach( $cached_update_info as $slug => $info ) {
                if ( !isset( $_transient_data->response[ $slug ] ) && isset($info->update) ) {
                    $_transient_data->response[ $slug ] = $info->update;
                    $pre = true;
                }
            }
        }

        if ($pre == false)
            return $false;

        return $_transient_data;
    }

	function hookFixOptimizePressThemeUpdate( $transient ) {
		if ( ! defined( 'OP_FUNC' ) ) {
			return $transient;
		}

		$theme_slug = 'optimizePressTheme';

		if ( ! function_exists( 'op_sl_update' ) ) {
			require_once OP_FUNC . 'options.php';
			require_once OP_FUNC . 'sl_api.php';
		}
		$apiResponse = op_sl_update( 'theme' );

		if ( is_wp_error( $apiResponse ) ) {
			return $transient;
		}

		$obj              = new stdClass();
		$obj->slug        = $theme_slug;
		$obj->new_version = $apiResponse->new_version;
		$obj->url         = $apiResponse->url;
		$obj->package     = $apiResponse->s3_package;
		$obj->sections    = array(
			'description' => $apiResponse->section->description,
			'changelog'   => $apiResponse->section->changelog,
		);

		$transient->response[ $theme_slug ] = (array) $obj;

		return $transient;
	}

	//This will register the current wp - thus generating the public key etc..
	function registerSite() {
		global $current_user;

		$information = array();
		//Check if the user is valid & login
		if ( ! isset( $_POST['user'] ) || ! isset( $_POST['pubkey'] ) ) {
			MainWP_Helper::error( __( 'Invalid request!', 'mainwp-child' ) );
		}

		//Already added - can't readd. Deactivate plugin..
		if ( get_option( 'mainwp_child_pubkey' ) ) {
            // set disconnect status to yes here, it will empty after reconnected
            MainWP_Child_Branding::Instance()->save_branding_options('branding_disconnected', 'yes');
    		MainWP_Helper::update_option( 'mainwp_child_branding_disconnected', 'yes', 'yes' ); // to compatible with old client reports
			MainWP_Helper::error( __( 'Public key already set. Please deactivate & reactivate the MainWP Child plugin and try again.', 'mainwp-child' ) );

		}

		if ( '' != get_option( 'mainwp_child_uniqueId' ) ) {
			if ( ! isset( $_POST['uniqueId'] ) || ( '' === $_POST['uniqueId'] ) ) {
				MainWP_Helper::error( __( 'This child site is set to require a unique security ID. Please enter it before the connection can be established.', 'mainwp-child' ) );
			} else if ( get_option( 'mainwp_child_uniqueId' ) !== $_POST['uniqueId'] ) {
				MainWP_Helper::error( __( 'The unique security ID mismatch! Please correct it before the connection can be established.', 'mainwp-child' ) );
			}
		}

		//Check SSL Requirement
		if ( !MainWP_Helper::isSSLEnabled() && ( !defined( 'MAINWP_ALLOW_NOSSL_CONNECT' ) || !MAINWP_ALLOW_NOSSL_CONNECT ) ) {
			MainWP_Helper::error( __( 'SSL is required on the child site to set up a secure connection.', 'mainwp-child' ) );
		}

		//Login
		if ( isset( $_POST['user'] ) ) {
			if ( ! $this->login( $_POST['user'] ) ) {
				$hint = "<br/>" . __('Hint: Check if the administrator user exists on the child site, if not, you need to use an existing administrator.', 'mainwp-child');
				MainWP_Helper::error(__('That administrator username was not found on this child site. Please verify that it is an existing administrator.' . $hint,'mainwp-child'));
			}

			if ( 10 !== $current_user->wp_user_level && ( ! isset( $current_user->user_level ) || 10 !== $current_user->user_level ) && ! $current_user->has_cap( 'level_10' ) ) {
				MainWP_Helper::error( __( 'That user is not an administrator. Please use an administrator user to establish the connection.', 'mainwp-child' ) );
			}
		}

		MainWP_Helper::update_option( 'mainwp_child_pubkey', base64_encode( $_POST['pubkey'] ), 'yes' ); //Save the public key
		MainWP_Helper::update_option( 'mainwp_child_server', $_POST['server'] ); //Save the public key
		MainWP_Helper::update_option( 'mainwp_child_nonce', 0 ); //Save the nonce

		MainWP_Helper::update_option( 'mainwp_child_nossl', ( '-1' === $_POST['pubkey'] || ! MainWP_Helper::isSSLEnabled() ? 1 : 0 ), 'yes' );
		$information['nossl'] = ( '-1' === $_POST['pubkey'] || ! MainWP_Helper::isSSLEnabled() ? 1 : 0 );
		$nossl_key            = uniqid( '', true );
		MainWP_Helper::update_option( 'mainwp_child_nossl_key', $nossl_key, 'yes' );
		$information['nosslkey'] = $nossl_key;

		$information['register'] = 'OK';
		$information['uniqueId'] = get_option( 'mainwp_child_uniqueId', '' );
		$information['user']     = $_POST['user'];

		$this->getSiteStats( $information );
	}

	function newPost() {
		//Read form data
		$new_post            = maybe_unserialize( base64_decode( $_POST['new_post'] ) );
		$post_custom         = maybe_unserialize( base64_decode( $_POST['post_custom'] ) );
		$post_category       = rawurldecode( isset( $_POST['post_category'] ) ? base64_decode( $_POST['post_category'] ) : null );
		$post_tags           = rawurldecode( isset( $new_post['post_tags'] ) ? $new_post['post_tags'] : null );
		$post_featured_image = base64_decode( $_POST['post_featured_image'] );
		$upload_dir          = maybe_unserialize( base64_decode( $_POST['mainwp_upload_dir'] ) );

		if ( isset( $_POST['_ezin_post_category'] ) ) {
			$new_post['_ezin_post_category'] = maybe_unserialize( base64_decode( $_POST['_ezin_post_category'] ) );
		}

        $others = array();
        if ( isset( $_POST['featured_image_data'] ) && !empty($_POST['featured_image_data'])) {
            $others['featured_image_data'] = unserialize(base64_decode( $_POST['featured_image_data'] ));
        }

		$res     = MainWP_Helper::createPost( $new_post, $post_custom, $post_category, $post_featured_image, $upload_dir, $post_tags, $others );

        if (is_array($res) && isset($res['error'])) {
            MainWP_Helper::error( $res['error'] );
        }

		$created = $res['success'];
		if ( true !== $created ) {
			MainWP_Helper::error( 'Undefined error' );
		}

		$information['added']    = true;
		$information['added_id'] = $res['added_id'];
		$information['link']     = $res['link'];

		do_action('mainwp_child_after_newpost', $res);

		MainWP_Helper::write( $information );
	}

	function post_action() {
		//Read form data
		$action = $_POST['action'];
		$postId = $_POST['id'];
        $my_post = array();

		if ( 'publish' === $action ) {
            $post_current = get_post( $postId );
            if ( empty($post_current) ) {
                $information['status'] = 'FAIL';
            } else {
                if ( 'future' == $post_current->post_status ) {
                    wp_publish_post( $postId ); // to fix: fail when publish future page
                    wp_update_post(array('ID' => $postId,
                        'post_date'     => current_time( 'mysql', false ),
                        'post_date_gmt' => current_time( 'mysql', true )
                    ));
                } else {
                    // to fix error post slug
                    wp_update_post(array('ID' => $postId,'post_status' => 'publish' ));
                }
            }
		} else if ( 'update' === $action ) {
			$postData = $_POST['post_data'];
			$my_post  = is_array( $postData ) ? $postData : array();
			wp_update_post( $my_post );
		} else if ( 'unpublish' === $action ) {
			$my_post['ID']          = $postId;
			$my_post['post_status'] = 'draft';
			wp_update_post( $my_post );
		} else if ( 'trash' === $action ) {
			add_action( 'trash_post', array( 'MainWP_Child_Links_Checker', 'hook_post_deleted' ) );
			wp_trash_post( $postId );
		} else if ( 'delete' === $action ) {
			add_action( 'delete_post', array( 'MainWP_Child_Links_Checker', 'hook_post_deleted' ) );
			wp_delete_post( $postId, true );
		} else if ( 'restore' === $action ) {
			wp_untrash_post( $postId );
		} else if ( 'update_meta' === $action ) {
			$values     = maybe_unserialize( base64_decode( $_POST['values'] ) );
			$meta_key   = $values['meta_key'];
			$meta_value = $values['meta_value'];
			$check_prev = $values['check_prev'];

			foreach ( $meta_key as $i => $key ) {
				if ( 1 === intval( $check_prev[ $i ] ) ) {
					update_post_meta( $postId, $key, get_post_meta( $postId, $key, true ) ? get_post_meta( $postId, $key, true ) : $meta_value[ $i ] );
				} else {
					update_post_meta( $postId, $key, $meta_value[ $i ] );
				}
			}
		} else if ( 'get_edit' === $action ) {
            $postId = $_POST['id'];
            $post_type = $_POST['post_type'];
            if ( $post_type == 'post' ) {
	            $my_post = $this->get_post_edit( $postId );
            } else {
	            $my_post = $this->get_page_edit( $postId );
            }
		} else {
			$information['status'] = 'FAIL';
		}

		if ( ! isset( $information['status'] ) ) {
			$information['status'] = 'SUCCESS';
		}
		$information['my_post'] = $my_post;
		MainWP_Helper::write( $information );
	}

    function get_post_edit($id) {
        $post = get_post( $id );
        if ( $post ) {
            $categoryObjects          = get_the_category( $post->ID );
            $categories               = '';
            foreach ( $categoryObjects as $cat ) {
	            if ( '' !== $categories ) {
		            $categories .= ', ';
	            }
	            $categories .= $cat->name;
            }
            $post_category = $categories;

            $tagObjects = get_the_tags( $post->ID );
            $tags       = '';
            if ( is_array( $tagObjects ) ) {
	            foreach ( $tagObjects as $tag ) {
		            if ( '' !== $tags ) {
			            $tags .= ', ';
		            }
		            $tags .= $tag->name;
	            }
            }
            $post_tags = $tags;

            $post_custom = get_post_custom( $id );

            $galleries = get_post_gallery( $id, false );
            $post_gallery_images = array();

            if ( is_array($galleries) && isset($galleries['ids']) ) {
	            $attached_images = explode( ',', $galleries['ids'] );
	            foreach( $attached_images as $attachment_id ) {
		            $attachment = get_post( $attachment_id );
		            if ( $attachment ) {
			            $post_gallery_images[] = array(
				            'id' => $attachment_id,
				            'alt' => get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ),
				            'caption' => $attachment->post_excerpt,
				            'description' => $attachment->post_content,
				            'src' => $attachment->guid,
				            'title' => $attachment->post_title
			            );
		            }
	            }
            }

            include_once( ABSPATH . 'wp-includes' . DIRECTORY_SEPARATOR . 'post-thumbnail-template.php' );
            $post_featured_image = get_post_thumbnail_id( $id );
            $child_upload_dir   = wp_upload_dir();
            $new_post = array(
	            'edit_id'        => $id,
	            'is_sticky'      => is_sticky( $id ) ? 1 : 0,
	            'post_title'     => $post->post_title,
	            'post_content'   => $post->post_content,
	            'post_status'    => $post->post_status,
	            'post_date'      => $post->post_date,
	            'post_date_gmt'  => $post->post_date_gmt,
	            'post_tags'      => $post_tags,
	            'post_name'      => $post->post_name,
	            'post_excerpt'   => $post->post_excerpt,
	            'comment_status' => $post->comment_status,
	            'ping_status'    => $post->ping_status
            );

            if ( $post_featured_image != null ) { //Featured image is set, retrieve URL
	            $img                 = wp_get_attachment_image_src( $post_featured_image, 'full' );
	            $post_featured_image = $img[0];
            }

            require_once ABSPATH . 'wp-admin/includes/post.php';
            wp_set_post_lock($id);

            $post_data = array(
	            'new_post'            => base64_encode( serialize( $new_post ) ),
	            'post_custom'         => base64_encode( serialize( $post_custom ) ),
	            'post_category'       => base64_encode( $post_category ),
	            'post_featured_image' => base64_encode( $post_featured_image ),
	            'post_gallery_images' => base64_encode( serialize( $post_gallery_images ) ),
	            'child_upload_dir'   => base64_encode( serialize( $child_upload_dir ) ),
            );
            return $post_data;

        }
        return false;
    }

    function get_page_edit($id) {
        $post = get_post( $id );
        if ( $post ) {
            $post_custom = get_post_custom( $id );
            //post_slug = base64_decode( get_post_meta( $id, '_slug', true ) );
            include_once( ABSPATH . 'wp-includes' . DIRECTORY_SEPARATOR . 'post-thumbnail-template.php' );
            $post_featured_image = get_post_thumbnail_id( $id );
            $child_upload_dir = wp_upload_dir();

            $new_post = array(
                    'edit_id'        => $id,
                    'post_title' => $post->post_title,
                    'post_content' => $post->post_content,
                    'post_status' => $post->post_status,
                    'post_date' => $post->post_date,
                    'post_date_gmt' => $post->post_date_gmt,
                    'post_type' => 'page',
                    'post_name' => $post->post_name,
                    'post_excerpt' => $post->post_excerpt,
                    'comment_status' => $post->comment_status,
                    'ping_status' => $post->ping_status
            );


            if ( $post_featured_image != null ) { //Featured image is set, retrieve URL
                    $img = wp_get_attachment_image_src( $post_featured_image, 'full' );
                    $post_featured_image = $img[0];
            }

            $galleries = get_post_gallery( $id, false );
            $post_gallery_images = array();

            if ( is_array($galleries) && isset($galleries['ids']) ) {
                    $attached_images = explode( ',', $galleries['ids'] );
                    foreach( $attached_images as $attachment_id ) {
                            $attachment = get_post( $attachment_id );
                            if ( $attachment ) {
                                    $post_gallery_images[] = array(
                                            'id' => $attachment_id,
                                            'alt' => get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ),
                                            'caption' => $attachment->post_excerpt,
                                            'description' => $attachment->post_content,
                                            'src' => $attachment->guid,
                                            'title' => $attachment->post_title
                                    );
                            }
                    }
            }

            require_once ABSPATH . 'wp-admin/includes/post.php';
            wp_set_post_lock($id);

            $post_data = array(
                    'new_post' => base64_encode( serialize( $new_post ) ),
                    'post_custom' => base64_encode( serialize( $post_custom ) ),
                    'post_featured_image' => base64_encode( $post_featured_image ),
                    'post_gallery_images' => base64_encode( serialize( $post_gallery_images ) ),
                    'child_upload_dir' => base64_encode( serialize( $child_upload_dir ) ),
            );
            return $post_data;
        }
        return false;
    }


	function user_action() {
		//Read form data
		$action    = $_POST['action'];
		$extra     = $_POST['extra'];
		$userId    = $_POST['id'];
		$user_pass = $_POST['user_pass'];
        $failed = false;

		global $current_user;
		$reassign = ( isset( $current_user ) && isset( $current_user->ID ) ) ? $current_user->ID : 0;
        include_once( ABSPATH . '/wp-admin/includes/user.php' );

		if ( 'delete' === $action ) {
			wp_delete_user( $userId, $reassign );
		} else if ( 'changeRole' === $action ) {
			$my_user         = array();
			$my_user['ID']   = $userId;
			$my_user['role'] = $extra;
			wp_update_user( $my_user );
		} else if ( 'update_password' === $action ) {
			$my_user              = array();
			$my_user['ID']        = $userId;
			$my_user['user_pass'] = $user_pass;
			wp_update_user( $my_user );
		} else if ( 'edit' === $action ) {
                        $user_data = $this->get_user_to_edit($userId);
                        if (!empty($user_data)) {
                            $information['user_data'] = $user_data;
                        } else {
                            $failed = true;
                        }
		} else if ( 'update_user' === $action ) {
                        $my_user =  $_POST['extra'];
                        if (is_array($my_user)) {
                            foreach($my_user as $idx => $val) {
                                if ($val === 'donotupdate' || (empty($val) && $idx !== 'role')) {
                                    unset($my_user[$idx]);
                                }
                            }
                            $result = $this->edit_user( $userId, $my_user );
                            if (is_array($result) && isset($result['error'])) {
                                $information['error'] = $result['error'];
                            }
                        } else {
                            $failed = true;
                        }
		} else {
			$failed = true;
		}

                if ($failed)
                    $information['status'] = 'FAIL';

		if ( ! isset( $information['status'] ) && !isset($information['error']) ) {
			$information['status'] = 'SUCCESS';
                        if ('update_user' === $action && isset($_POST['optimize']) && !empty($_POST['optimize'])) {
                            $information['users'] = $this->get_all_users_int(500); // to fix
                        }

		}
		MainWP_Helper::write( $information );
	}

        function edit_user( $user_id, $data) {
                $wp_roles = wp_roles();
                $user = new stdClass;

                $update = true;

                if ( $user_id ) {
                        $user->ID = (int) $user_id;
                        $userdata = get_userdata( $user_id );
                        $user->user_login = wp_slash( $userdata->user_login );
                } else {
                        return array('error' => 'ERROR: Empty user id.');
                }

                $pass1 = $pass2 = '';
                if ( isset( $data['pass1'] ) )
                        $pass1 = $data['pass1'];
                if ( isset( $data['pass2'] ) )
                        $pass2 = $data['pass2'];

                if ( isset( $data['role'] ) && current_user_can( 'edit_users' ) ) {
                        $new_role = sanitize_text_field( $data['role'] );
                        $potential_role = isset($wp_roles->role_objects[$new_role]) ? $wp_roles->role_objects[$new_role] : false;
                        // Don't let anyone with 'edit_users' (admins) edit their own role to something without it.
                        // Multisite super admins can freely edit their blog roles -- they possess all caps.
                        if ( ( is_multisite() && current_user_can( 'manage_sites' ) ) || $user_id != get_current_user_id() || ($potential_role && $potential_role->has_cap( 'edit_users' ) ) )
                                $user->role = $new_role;

                        // If the new role isn't editable by the logged-in user die with error
                        $editable_roles = get_editable_roles();
                        if ( ! empty( $new_role ) && empty( $editable_roles[$new_role] ) )
                            return array('error' => 'You can&#8217;t give users that role.');
                }

                $email = '';
                if ( isset( $data['email'] ) )
                    $email = trim( $data['email'] );

                if ( !empty( $email ) )
                        $user->user_email = sanitize_text_field( wp_unslash( $email ) );
                else
                        $user->user_email = $userdata->user_email;

                if ( isset( $data['url'] ) ) {
                        if ( empty ( $data['url'] ) || $data['url'] == 'http://' ) {
                                $user->user_url = '';
                        } else {
                                $user->user_url = esc_url_raw( $data['url'] );
                                $protocols = implode( '|', array_map( 'preg_quote', wp_allowed_protocols() ) );
                                $user->user_url = preg_match('/^(' . $protocols . '):/is', $user->user_url) ? $user->user_url : 'http://'.$user->user_url;
                        }
                }

                if ( isset( $data['first_name'] ) )
                        $user->first_name = sanitize_text_field( $data['first_name'] );
                if ( isset( $data['last_name'] ) )
                        $user->last_name = sanitize_text_field( $data['last_name'] );
                if ( isset( $data['nickname'] ) && !empty($data['nickname']))
                        $user->nickname = sanitize_text_field( $data['nickname'] );
                if ( isset( $data['display_name'] ) )
                        $user->display_name = sanitize_text_field( $data['display_name'] );
                if ( isset( $data['description'] ) )
                        $user->description = trim( $data['description'] );

                $errors = new WP_Error();

                /* checking that username has been typed */
                if ( $user->user_login == '' )
                        $errors->add( 'user_login', __( '<strong>ERROR</strong>: Please enter a username.' ) );

                do_action_ref_array( 'check_passwords', array( $user->user_login, &$pass1, &$pass2 ) );

                if (!empty($pass1) || !empty($pass2)) {
                    // Check for blank password when adding a user.
                    if ( ! $update && empty( $pass1 ) ) {
                            $errors->add( 'pass', __( '<strong>ERROR</strong>: Please enter a password.' ), array( 'form-field' => 'pass1' ) );
                    }

                    // Check for "\" in password.
                    if ( false !== strpos( wp_unslash( $pass1 ), "\\" ) ) {
                            $errors->add( 'pass', __( '<strong>ERROR</strong>: Passwords may not contain the character "\\".' ), array( 'form-field' => 'pass1' ) );
                    }

                    // Checking the password has been typed twice the same.
                    if ( ( $update || ! empty( $pass1 ) ) && $pass1 != $pass2 ) {
                            $errors->add( 'pass', __( '<strong>ERROR</strong>: Please enter the same password in both password fields.' ), array( 'form-field' => 'pass1' ) );
                    }

                    if ( !empty( $pass1 ) )
                            $user->user_pass = $pass1;
                } else {
                            $user->user_pass = $userdata->user_pass;
                }

                /** This filter is documented in wp-includes/user.php */
                $illegal_logins = (array) apply_filters( 'illegal_user_logins', array() );

                if ( in_array( strtolower( $user->user_login ), array_map( 'strtolower', $illegal_logins ) ) ) {
                        $errors->add( 'invalid_username', __( '<strong>ERROR</strong>: Sorry, that username is not allowed.' ) );
                }

                /* checking email address */
                if ( empty( $user->user_email ) ) {
                        $errors->add( 'empty_email', __( '<strong>ERROR</strong>: Please enter an email address.' ), array( 'form-field' => 'email' ) );
                } elseif ( !is_email( $user->user_email ) ) {
                        $errors->add( 'invalid_email', __( '<strong>ERROR</strong>: The email address isn&#8217;t correct.' ), array( 'form-field' => 'email' ) );
                } elseif ( ( $owner_id = email_exists($user->user_email) ) && ( !$update || ( $owner_id != $user->ID ) ) ) {
                        $errors->add( 'email_exists', __('<strong>ERROR</strong>: This email is already registered, please choose another one.'), array( 'form-field' => 'email' ) );
                }

                do_action_ref_array( 'user_profile_update_errors', array( &$errors, $update, &$user ) );

                if ( $errors->get_error_codes() ) {
                    $error_str = '';
                    foreach ( $errors->get_error_messages() as $message ) {
                        if ( is_string( $message ) )
                            $error_str .= ' ' . esc_html( strip_tags( $message ) );

                    }
                    return array( 'error' => $error_str );
                }

                $user_id = wp_update_user( $user );

                return $user_id;
        }

        function get_user_to_edit( $user_id ) {
            require_once(ABSPATH . 'wp-admin/includes/user.php');
            $profileuser = get_user_to_edit($user_id);

            $edit_data = array();
            if (is_object($profileuser)) {
                $user_roles = array_intersect( array_values( $profileuser->roles ), array_keys( get_editable_roles() ) );
                $user_role  = reset( $user_roles );
                $edit_data['role'] = $user_role;
                $edit_data['first_name'] = $profileuser->first_name;
                $edit_data['last_name'] = $profileuser->last_name;
                $edit_data['nickname'] = $profileuser->nickname;

                $public_display = array();
                $public_display['display_nickname']  = $profileuser->nickname;
                $public_display['display_username']  = $profileuser->user_login;

                if ( !empty($profileuser->first_name) )
                        $public_display['display_firstname'] = $profileuser->first_name;

                if ( !empty($profileuser->last_name) )
                        $public_display['display_lastname'] = $profileuser->last_name;

                if ( !empty($profileuser->first_name) && !empty($profileuser->last_name) ) {
                        $public_display['display_firstlast'] = $profileuser->first_name . ' ' . $profileuser->last_name;
                        $public_display['display_lastfirst'] = $profileuser->last_name . ' ' . $profileuser->first_name;
                }

                if ( !in_array( $profileuser->display_name, $public_display ) ) // Only add this if it isn't duplicated elsewhere
                        $public_display = array( 'display_displayname' => $profileuser->display_name ) + $public_display;

                $public_display = array_map( 'trim', $public_display );
                $public_display = array_unique( $public_display );

                $edit_data['public_display'] = $public_display;
                $edit_data['display_name'] = $profileuser->display_name;
                $edit_data['user_email'] = $profileuser->user_email;
                $edit_data['user_url'] = $profileuser->user_url;
                foreach ( wp_get_user_contact_methods( $profileuser ) as $name => $desc ) {
                    $edit_data['contact_methods'][$name] = $profileuser->$name;
                }
                $edit_data['description'] =  $profileuser->description;
            }
            return $edit_data;
        }

	//todo: backwards compatible: wp_set_comment_status ?
	function comment_action() {
		//Read form data
		$action    = $_POST['action'];
		$commentId = $_POST['id'];

		if ( 'approve' === $action ) {
			wp_set_comment_status( $commentId, 'approve' );
		} else if ( 'unapprove' === $action ) {
			wp_set_comment_status( $commentId, 'hold' );
		} else if ( 'spam' === $action ) {
			wp_spam_comment( $commentId );
		} else if ( 'unspam' === $action ) {
			wp_unspam_comment( $commentId );
		} else if ( 'trash' === $action ) {
			add_action( 'trashed_comment', array( 'MainWP_Child_Links_Checker', 'hook_trashed_comment' ), 10, 1 );
			wp_trash_comment( $commentId );
		} else if ( 'restore' === $action ) {
			wp_untrash_comment( $commentId );
		} else if ( 'delete' === $action ) {
			wp_delete_comment( $commentId, true );
		} else {
			$information['status'] = 'FAIL';
		}

		if ( ! isset( $information['status'] ) ) {
			$information['status'] = 'SUCCESS';
		}
		MainWP_Helper::write( $information );
	}

	//todo: backwards compatible: wp_set_comment_status ?
	function comment_bulk_action() {
		//Read form data
		$action                 = $_POST['action'];
		$commentIds             = explode( ',', $_POST['ids'] );
		$information['success'] = 0;
		foreach ( $commentIds as $commentId ) {
			if ( $commentId ) {
				$information['success'] ++;
				if ( 'approve' === $action ) {
					wp_set_comment_status( $commentId, 'approve' );
				} else if ( 'unapprove' === $action ) {
					wp_set_comment_status( $commentId, 'hold' );
				} else if ( 'spam' === $action ) {
					wp_spam_comment( $commentId );
				} else if ( 'unspam' === $action ) {
					wp_unspam_comment( $commentId );
				} else if ( 'trash' === $action ) {
					wp_trash_comment( $commentId );
				} else if ( 'restore' === $action ) {
					wp_untrash_comment( $commentId );
				} else if ( 'delete' === $action ) {
					wp_delete_comment( $commentId, true );
				} else {
					$information['success']--;
				}
			}
		}
		MainWP_Helper::write( $information );
	}


	function newAdminPassword() {
		//Read form data
		$new_password = maybe_unserialize( base64_decode( $_POST['new_password'] ) );
		$user         = get_user_by( 'login', $_POST['user'] );
		require_once( ABSPATH . WPINC . '/registration.php' );

		$id = wp_update_user( array( 'ID' => $user->ID, 'user_pass' => $new_password['user_pass'] ) );
		if ( $id !== $user->ID ) {
			if ( is_wp_error( $id ) ) {
				MainWP_Helper::error( $id->get_error_message() );
			} else {
				MainWP_Helper::error( __( 'Administrator password could not be changed.', 'mainwp-child' ) );
			}
		}

		$information['added'] = true;
		MainWP_Helper::write( $information );
	}

	function newUser() {
		//Read form data
		$new_user      = maybe_unserialize( base64_decode( $_POST['new_user'] ) );
		$send_password = $_POST['send_password'];
		// check role existed
		if (isset( $new_user['role'] )) {
			if ( !get_role( $new_user['role'] ) ) {
				$new_user['role'] = 'subscriber';
			}
		}

		$new_user_id = wp_insert_user( $new_user );

		if ( is_wp_error( $new_user_id ) ) {
			MainWP_Helper::error( $new_user_id->get_error_message() );
		}
		if ( 0 === $new_user_id ) {
			MainWP_Helper::error( __( 'Undefined error!', 'mainwp-child' ) );
		}

		if ( $send_password ) {
			$user = new WP_User( $new_user_id );

			$user_login = stripslashes( $user->user_login );
			$user_email = stripslashes( $user->user_email );

			// The blogname option is escaped with esc_html on the way into the database in sanitize_option
			// we want to reverse this for the plain text arena of emails.
			$blogname = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );

			$message = sprintf( __( 'Username: %s' ), $user_login ) . "\r\n";
			$message .= sprintf( __( 'Password: %s' ), $new_user['user_pass'] ) . "\r\n";
			$message .= wp_login_url() . "\r\n";

			@wp_mail( $user_email, sprintf( __( '[%s] Your username and password' ), $blogname ), $message, '' );
		}
		$information['added'] = true;
		MainWP_Helper::write( $information );
	}

	function cloneinfo() {
		global $table_prefix;
		$information['dbCharset']    = DB_CHARSET;
		$information['dbCollate']    = DB_COLLATE;
		$information['table_prefix'] = $table_prefix;
		$information['site_url']     = get_option( 'site_url' );
		$information['home']         = get_option( 'home' );

		MainWP_Helper::write( $information );
	}

	function backupPoll() {
		$fileNameUID = ( isset( $_POST['fileNameUID'] ) ? $_POST['fileNameUID'] : '' );
		$fileName    = ( isset( $_POST['fileName'] ) ? $_POST['fileName'] : '' );

		if ( 'full' === $_POST['type'] ) {
			if ( '' !== $fileName ) {
				$backupFile = $fileName;
			} else {
				$backupFile = 'backup-' . $fileNameUID . '-';
			}

			$dirs        = MainWP_Helper::getMainWPDir( 'backup' );
			$backupdir   = $dirs[0];
			$result      = glob( $backupdir . $backupFile . '*' );
			$archiveFile = false;
			foreach ( $result as $file ) {
				if ( MainWP_Helper::isArchive( $file, $backupFile, '(.*)' ) ) {
					$archiveFile = $file;
					break;
				}
			}
			if ( false === $archiveFile ) {
				MainWP_Helper::write( array() );
			}

			MainWP_Helper::write( array( 'size' => filesize( $archiveFile ) ) );
		} else {
			$backupFile = 'dbBackup-' . $fileNameUID . '-*.sql';

			$dirs      = MainWP_Helper::getMainWPDir( 'backup' );
			$backupdir = $dirs[0];
			$result    = glob( $backupdir . $backupFile . '*' );
			if ( 0 === count( $result ) ) {
				MainWP_Helper::write( array() );
			}

			$size = 0;
			foreach ( $result as $f ) {
				$size += filesize($f);
			}
			MainWP_Helper::write( array( 'size' => $size ) );
			exit();
		}
	}

	function backup_checkpid() {
		$pid = $_POST['pid'];

		$dirs      = MainWP_Helper::getMainWPDir( 'backup' );
		$backupdir = $dirs[0];

		$information = array();

		/** @var $wp_filesystem WP_Filesystem_Base */
		global $wp_filesystem;

		MainWP_Helper::getWPFilesystem();

		$pidFile  = trailingslashit( $backupdir ) . 'backup-' . $pid . '.pid';
		$doneFile = trailingslashit( $backupdir ) . 'backup-' . $pid . '.done';
		if ( $wp_filesystem->is_file( $pidFile ) ) {
			$time = $wp_filesystem->mtime( $pidFile );

			$minutes = date( 'i', time() );
			$seconds = date( 's', time() );

			$file_minutes = date( 'i', $time );
			$file_seconds = date( 's', $time );

			$minuteDiff = $minutes - $file_minutes;
			if ( 59 === $minuteDiff ) {
				$minuteDiff = 1;
			}
			$secondsdiff = ( $minuteDiff * 60 ) + $seconds - $file_seconds;

			$file                = $wp_filesystem->get_contents( $pidFile );
			$information['file'] = basename( $file );
			if ( $secondsdiff < 80 ) {
				$information['status'] = 'busy';
			} else {
				$information['status'] = 'stalled';
			}
		} else if ( $wp_filesystem->is_file( $doneFile ) ) {
			$file                  = $wp_filesystem->get_contents( $doneFile );
			$information['status'] = 'done';
			$information['file']   = basename( $file );
			$information['size']   = @filesize( $file );
		} else {
			$information['status'] = 'invalid';
		}

		MainWP_Helper::write( $information );
	}

	function backup( $pWrite = true ) {
		$timeout = 20 * 60 * 60; //20minutes
		@set_time_limit( $timeout );
		@ini_set( 'max_execution_time', $timeout );
		MainWP_Helper::endSession();

		//Cleanup pid files!
		$dirs      = MainWP_Helper::getMainWPDir( 'backup' );
		$backupdir = trailingslashit( $dirs[0] );

		/** @var $wp_filesystem WP_Filesystem_Base */
		global $wp_filesystem;

		MainWP_Helper::getWPFilesystem();

		$files = glob( $backupdir . '*' );
		//Find old files (changes > 3 hr)
		foreach ( $files as $file ) {
			if ( MainWP_Helper::endsWith( $file, '/index.php' ) | MainWP_Helper::endsWith( $file, '/.htaccess' ) ) {
				continue;
			}

			if ( ( time() - filemtime( $file ) ) > ( 60 * 60 * 3 ) ) {
				@unlink( $file );
			}
		}

		$fileName = ( isset( $_POST['fileUID'] ) ? $_POST['fileUID'] : '' );
		if ( 'full' === $_POST['type'] ) {
			$excludes   = ( isset( $_POST['exclude'] ) ? explode( ',', $_POST['exclude'] ) : array() );
			$excludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/uploads/mainwp';
			$uploadDir  = MainWP_Helper::getMainWPDir();
			$uploadDir  = $uploadDir[0];
			$excludes[] = str_replace( ABSPATH, '', $uploadDir );
			$excludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/object-cache.php';

			if ( function_exists( 'posix_uname' ) ) {
				$uname = @posix_uname();
				if ( is_array( $uname ) && isset( $uname['nodename'] ) ) {
					if ( stristr( $uname['nodename'], 'hostgator' ) ) {
						if ( ! isset( $_POST['file_descriptors'] ) || '0' == $_POST['file_descriptors'] || $_POST['file_descriptors'] > 1000 ) {
							$_POST['file_descriptors'] = 1000;
						}
						$_POST['file_descriptors_auto'] = 0;
						$_POST['loadFilesBeforeZip']    = false;
					}
				}
			}

			$file_descriptors      = ( isset( $_POST['file_descriptors'] ) ? $_POST['file_descriptors'] : 0 );
			$file_descriptors_auto = ( isset( $_POST['file_descriptors_auto'] ) ? $_POST['file_descriptors_auto'] : 0 );
			if ( 1 === (int) $file_descriptors_auto ) {
				if ( function_exists( 'posix_getrlimit' ) ) {
					$result = @posix_getrlimit();
					if ( isset( $result['soft openfiles'] ) ) {
						$file_descriptors = $result['soft openfiles'];
					}
				}
			}

			$loadFilesBeforeZip = ( isset( $_POST['loadFilesBeforeZip'] ) ? $_POST['loadFilesBeforeZip'] : true );

			$newExcludes = array();
			foreach ( $excludes as $exclude ) {
				$newExcludes[] = rtrim( $exclude, '/' );
			}

			$excludebackup = ( isset( $_POST['excludebackup'] ) && '1' == $_POST['excludebackup'] );
			$excludecache  = ( isset( $_POST['excludecache'] ) && '1' == $_POST['excludecache'] );
			$excludezip    = ( isset( $_POST['excludezip'] ) && '1' == $_POST['excludezip'] );
			$excludenonwp  = ( isset( $_POST['excludenonwp'] ) && '1' == $_POST['excludenonwp'] );

			if ( $excludebackup ) {
				//Backup buddy
				$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/uploads/backupbuddy_backups';
				$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/uploads/backupbuddy_temp';
				$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/uploads/pb_backupbuddy';

				//ManageWP
				$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/managewp';

				//InfiniteWP
				$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/infinitewp';

				//WordPress Backup to Dropbox
				$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/backups';

				//BackUpWordpress
				$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/backups';

				//BackWPUp
				$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/uploads/backwpup*';

				//WP Complete Backup
				$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/plugins/wp-complete-backup/storage';

				//WordPress EZ Backup
				//This one may be hard to do since they add random text at the end for example, feel free to skip if you need to
				///backup_randomkyfkj where kyfkj is random

				//Online Backup for WordPress
				$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/backups';

				//XCloner
				$newExcludes[] = '/administrator/backups';
			}

			if ( $excludecache ) {
				//W3 Total Cache
				$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/w3tc-cache';
				$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/w3tc';
				$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/cache/config';
				$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/cache/minify';
				$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/cache/page_enhanced';
				$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/cache/tmp';

				//WP Super Cache
				$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/cache/supercache';

				//Quick Cache
				$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/cache/quick-cache';

				//Hyper Cache
				$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/hyper-cache/cache';

				//WP Fastest Cache
				$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/cache/all';

				//WP-Rocket
				$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/cache/wp-rocket';
			}

			$file = false;
			if ( isset( $_POST['f'] ) ) {
				$file = $_POST['f'];
			} else if ( isset( $_POST['file'] ) ) {
				$file = $_POST['file'];
			}

			$ext = 'zip';
			if ( isset( $_POST['ext'] ) ) {
				$ext = $_POST['ext'];
			}

			$pid = false;
			if ( isset( $_POST['pid'] ) ) {
				$pid = $_POST['pid'];
			}

			$append = ( isset( $_POST['append'] ) && ( '1' == $_POST['append'] ) );

			$res = MainWP_Backup::get()->createFullBackup( $newExcludes, $fileName, true, true, $file_descriptors, $file, $excludezip, $excludenonwp, $loadFilesBeforeZip, $ext, $pid, $append );
			if ( ! $res ) {
				$information['full'] = false;
			} else {
				$information['full'] = $res['file'];
				$information['size'] = $res['filesize'];
			}
			$information['db'] = false;
		} else if ( 'db' == $_POST['type'] ) {
			$ext = 'zip';
			if ( isset( $_POST['ext'] ) ) {
				$ext = $_POST['ext'];
			}

			$res = $this->backupDB( $fileName, $ext );
			if ( ! $res ) {
				$information['db'] = false;
			} else {
				$information['db']   = $res['file'];
				$information['size'] = $res['filesize'];
			}
			$information['full'] = false;
		} else {
			$information['full'] = false;
			$information['db']   = false;
		}

		if ( $pWrite ) {
			MainWP_Helper::write( $information );
		}

		return $information;
	}

	protected function backupDB( $fileName = '', $ext = 'zip' ) {
		$dirs      = MainWP_Helper::getMainWPDir( 'backup' );
		$dir       = $dirs[0];
		$timestamp = time();

		if ( '' !== $fileName ) {
			$fileName .= '-';
		}

		$filepath_prefix = $dir . 'dbBackup-' . $fileName . $timestamp;

		if ( $dh = opendir( $dir ) ) {
			while ( ( $file = readdir( $dh ) ) !== false ) {
				if ( '.' !== $file && '..' !== $file && ( preg_match( '/dbBackup-(.*).sql(\.zip|\.tar|\.tar\.gz|\.tar\.bz2|\.tmp)?$/', $file ) ) ) {
					@unlink( $dir . $file );
				}
			}
			closedir( $dh );
		}

		$result = MainWP_Backup::get()->createBackupDB( $filepath_prefix, $ext );

		MainWP_Helper::update_option( 'mainwp_child_last_db_backup_size', filesize( $result['filepath'] ) );

		return ( ! $result ) ? false : array(
			'timestamp' => $timestamp,
			'file'      => basename( $result['filepath'] ),
			'filesize'  => filesize( $result['filepath'] ),
		);
	}

	function doSecurityFix() {
		$sync = false;
		if ( 'all' === $_POST['feature'] ) {
			//fix all
			$sync = true;
		}

		$information = array();
		$security    = get_option( 'mainwp_security' );
		if ( ! is_array( $security ) ) {
			$security = array();
		}

		if ( 'all' === $_POST['feature'] || 'listing' === $_POST['feature'] ) {
			MainWP_Security::prevent_listing();
			$information['listing'] = ( ! MainWP_Security::prevent_listing_ok() ? 'N' : 'Y' );
		}

		if ( 'all' === $_POST['feature'] || 'wp_version' === $_POST['feature'] ) {
			$security['wp_version'] = true;
			MainWP_Security::remove_wp_version( true );
			$information['wp_version'] = ( ! MainWP_Security::remove_wp_version_ok() ? 'N' : 'Y' );
		}

		if ( 'all' === $_POST['feature'] || 'rsd' === $_POST['feature'] ) {
			$security['rsd'] = true;
			MainWP_Security::remove_rsd( true );
			$information['rsd'] = ( ! MainWP_Security::remove_rsd_ok() ? 'N' : 'Y' );
		}

		if ( 'all' === $_POST['feature'] || 'wlw' === $_POST['feature'] ) {
			$security['wlw'] = true;
			MainWP_Security::remove_wlw( true );
			$information['wlw'] = ( ! MainWP_Security::remove_wlw_ok() ? 'N' : 'Y' );
		}

		//        if ($_POST['feature'] == 'all' || $_POST['feature'] == 'core_updates')
		//        {
		//            $security['core_updates'] = true;
		//            MainWP_Security::remove_core_update(true);
		//            $information['core_updates'] = (!MainWP_Security::remove_core_update_ok() ? 'N' : 'Y');
		//        }

		//        if ($_POST['feature'] == 'all' || $_POST['feature'] == 'plugin_updates')
		//        {
		//            $security['plugin_updates'] = true;
		//            MainWP_Security::remove_plugin_update(true);
		//            $information['plugin_updates'] = (!MainWP_Security::remove_plugin_update_ok() ? 'N' : 'Y');
		//        }

		//        if ($_POST['feature'] == 'all' || $_POST['feature'] == 'theme_updates')
		//        {
		//            $security['theme_updates'] = true;
		//            MainWP_Security::remove_theme_update(true);
		//            $information['theme_updates'] = (!MainWP_Security::remove_theme_update_ok() ? 'N' : 'Y');
		//        }

		//        if ($_POST['feature'] == 'all' || $_POST['feature'] == 'file_perms')
		//        {
		//            MainWP_Security::fix_file_permissions();
		//            $information['file_perms'] = (!MainWP_Security::fix_file_permissions_ok() ? 'N' : 'Y');
		//            if ($information['file_perms'] == 'N')
		//            {
		//                $information['file_perms'] = 'Could not change all the file permissions';
		//            }
		//        }

		if ( 'all' === $_POST['feature'] || 'db_reporting' === $_POST['feature'] ) {
			MainWP_Security::remove_database_reporting();
			$information['db_reporting'] = ( ! MainWP_Security::remove_database_reporting_ok() ? 'N' : 'Y' );
		}

		if ( 'all' === $_POST['feature'] || 'php_reporting' === $_POST['feature'] ) {
			$security['php_reporting'] = true;
			MainWP_Security::remove_php_reporting( true );
			$information['php_reporting'] = ( ! MainWP_Security::remove_php_reporting_ok() ? 'N' : 'Y' );
		}

		if ( 'all' === $_POST['feature'] || 'versions' === $_POST['feature'] ) {
			$security['scripts_version'] = true;
			$security['styles_version']  = true;
			$security['generator_version']  = true;
			MainWP_Security::remove_generator_version( true );
			$information['versions'] = 'Y';
		}

        if ( 'all' === $_POST['feature'] || 'registered_versions' === $_POST['feature'] ) {
			$security['registered_versions'] = true;
			$information['registered_versions'] = 'Y';
		}

		if ( 'all' === $_POST['feature'] || 'admin' === $_POST['feature'] ) {
			$information['admin'] = ( ! MainWP_Security::admin_user_ok() ? 'N' : 'Y' );
		}

		if ( 'all' === $_POST['feature'] || 'readme' === $_POST['feature'] ) {
			$security['readme'] = true;
			MainWP_Security::remove_readme( true );
			$information['readme'] = ( MainWP_Security::remove_readme_ok() ? 'Y' : 'N' );
		}

		MainWP_Helper::update_option( 'mainwp_security', $security, 'yes' );

		if ( $sync ) {
			$information['sync'] = $this->getSiteStats( array(), false );
		}
		MainWP_Helper::write( $information );
	}

	function doSecurityUnFix() {
		$information = array();

		$sync = false;
		if ( 'all' === $_POST['feature'] ) {
			$sync = true;
		}

		$security = get_option( 'mainwp_security' );

		if ( 'all' === $_POST['feature'] || 'wp_version' === $_POST['feature'] ) {
			$security['wp_version']    = false;
			$information['wp_version'] = 'N';
		}

		if ( 'all' === $_POST['feature'] || 'rsd' === $_POST['feature'] ) {
			$security['rsd']    = false;
			$information['rsd'] = 'N';
		}

		if ( 'all' === $_POST['feature'] || 'wlw' === $_POST['feature'] ) {
			$security['wlw']    = false;
			$information['wlw'] = 'N';
		}

		if ( 'all' === $_POST['feature'] || 'php_reporting' === $_POST['feature'] ) {
			$security['php_reporting']    = false;
			$information['php_reporting'] = 'N';
		}

		if ( 'all' === $_POST['feature'] || 'versions' === $_POST['feature'] ) {
			$security['scripts_version'] = false;
			$security['styles_version']  = false;
			$security['generator_version']  = false;
			$information['versions']     = 'N';
		}

        if ( 'all' === $_POST['feature'] || 'registered_versions' === $_POST['feature'] ) {
			$security['registered_versions'] = false;
			$information['registered_versions'] = 'N';
		}
		if ( 'all' === $_POST['feature'] || 'readme' === $_POST['feature'] ) {
			$security['readme']    = false;
			$information['readme'] = MainWP_Security::remove_readme_ok();
		}

		MainWP_Helper::update_option( 'mainwp_security', $security, 'yes' );

		if ( $sync ) {
			$information['sync'] = $this->getSiteStats( array(), false );
		}

		MainWP_Helper::write( $information );
	}

	function getSecurityStats() {
		$information = array();

		$information['listing']    = ( ! MainWP_Security::prevent_listing_ok() ? 'N' : 'Y' );
		$information['wp_version'] = ( ! MainWP_Security::remove_wp_version_ok() ? 'N' : 'Y' );
		$information['rsd']        = ( ! MainWP_Security::remove_rsd_ok() ? 'N' : 'Y' );
		$information['wlw']        = ( ! MainWP_Security::remove_wlw_ok() ? 'N' : 'Y' );
		//        $information['core_updates'] = (!MainWP_Security::remove_core_update_ok() ? 'N' : 'Y');
		//        $information['plugin_updates'] = (!MainWP_Security::remove_plugin_update_ok() ? 'N' : 'Y');
		//        $information['theme_updates'] = (!MainWP_Security::remove_theme_update_ok() ? 'N' : 'Y');
		//        $information['file_perms'] = (!MainWP_Security::fix_file_permissions_ok() ? 'N' : 'Y');
		$information['db_reporting']  = ( ! MainWP_Security::remove_database_reporting_ok() ? 'N' : 'Y' );
		$information['php_reporting'] = ( ! MainWP_Security::remove_php_reporting_ok() ? 'N' : 'Y' );
		$information['versions']      = ( ! MainWP_Security::remove_scripts_version_ok() || ! MainWP_Security::remove_styles_version_ok()  || ! MainWP_Security::remove_generator_version_ok()
			? 'N' : 'Y' );
        $information['registered_versions']         = ( MainWP_Security::remove_registered_versions_ok() ? 'Y' : 'N' );
		$information['admin']         = ( MainWP_Security::admin_user_ok() ? 'Y' : 'N' );
		$information['readme']        = ( MainWP_Security::remove_readme_ok() ? 'Y' : 'N' );

		MainWP_Helper::write( $information );
	}

	function updateExternalSettings() {
		$update_htaccess = false;

		if ( isset( $_POST['cloneSites'] ) ) {
			if ( '0' !== $_POST['cloneSites'] ) {
				$arr = @json_decode( urldecode( $_POST['cloneSites'] ), 1 );
				MainWP_Helper::update_option( 'mainwp_child_clone_sites', ( ! is_array( $arr ) ? array() : $arr ) );
			} else {
				MainWP_Helper::update_option( 'mainwp_child_clone_sites', '0' );
			}
		}

		if ( isset( $_POST['siteId'] ) ) {
			MainWP_Helper::update_option( 'mainwp_child_siteid', intval($_POST['siteId']) );
		}

		if ( isset( $_POST['pluginDir'] ) ) {
			if ( get_option( 'mainwp_child_pluginDir' ) !== $_POST['pluginDir'] ) {
				MainWP_Helper::update_option( 'mainwp_child_pluginDir', $_POST['pluginDir'], 'yes' );
				$update_htaccess = true;
			}
		} else if ( false !== get_option( 'mainwp_child_pluginDir' ) ) {
			MainWP_Helper::update_option( 'mainwp_child_pluginDir', false, 'yes' );
			$update_htaccess = true;
		}

		if ( $update_htaccess ) {
			$this->update_htaccess( true );
		}
	}

	//Show stats
	function getSiteStats( $information = array(), $exit = true ) {
		global $wp_version;

		if ( $exit ) {
			$this->updateExternalSettings();
		}

        MainWP_Child_Branding::Instance()->save_branding_options('branding_disconnected', '');
		MainWP_Helper::update_option( 'mainwp_child_branding_disconnected', '', 'yes' );
		if ( isset( $_POST['server'] ) ) {
			MainWP_Helper::update_option( 'mainwp_child_server', $_POST['server'] );
		}

		if ( isset( $_POST['numberdaysOutdatePluginTheme'] ) ) {
			$days_outdate = get_option( 'mainwp_child_plugintheme_days_outdate', 365 );
			if ( $days_outdate != $_POST['numberdaysOutdatePluginTheme'] ) {
				$days_outdate = intval($_POST['numberdaysOutdatePluginTheme']);
				MainWP_Helper::update_option( 'mainwp_child_plugintheme_days_outdate', $days_outdate );
				MainWP_Child_Plugins_Check::Instance()->cleanup_deactivation( false );
				MainWP_Child_Themes_Check::Instance()->cleanup_deactivation( false );
			}
		}

		$information['version']   = self::$version;
		$information['wpversion'] = $wp_version;
		$information['siteurl']   = get_option( 'siteurl' );
		$information['wpe']   =  MainWP_Helper::is_wp_engine() ? 1 : 0;
		$theme_name = wp_get_theme()->get( 'Name' );
	    $information['site_info']   = array(
	        'wpversion' => $wp_version,
            'debug_mode' => (defined('WP_DEBUG') && true === WP_DEBUG) ? true : false,
	        'phpversion' => phpversion(),
	        'child_version' => self::$version,
	        'memory_limit' => MainWP_Child_Server_Information::getPHPMemoryLimit(),
	        'mysql_version' => MainWP_Child_Server_Information::getMySQLVersion(),
			'themeactivated' => $theme_name,
	        'ip' => $_SERVER['SERVER_ADDR']
	    );

		//Try to switch to SSL if SSL is enabled in between!
		$pubkey = get_option( 'mainwp_child_pubkey' );
		$nossl = get_option( 'mainwp_child_nossl' );
		if ( 1 == $nossl )  {
			if ( isset($pubkey) && MainWP_Helper::isSSLEnabled() ) {
				MainWP_Helper::update_option( 'mainwp_child_nossl', 0, 'yes' );
				$nossl = 0;
			}
		}
		$information['nossl']     = ( 1 == $nossl ? 1 : 0 );

		include_once( ABSPATH . '/wp-admin/includes/update.php' );

		$timeout = 3 * 60 * 60; // 3minutes
		@set_time_limit( $timeout );
		@ini_set( 'max_execution_time', $timeout );

		//Check for new versions
		if ( null !== $this->filterFunction ) {
			add_filter( 'pre_site_transient_update_core', $this->filterFunction, 99 );
		}
		if ( null !== $this->filterFunction ) {
			add_filter( 'pre_transient_update_core', $this->filterFunction, 99 );
		}
		@wp_version_check();
		$core_updates = get_core_updates();
		if ( is_array($core_updates) && count( $core_updates ) > 0 ) {
			foreach ( $core_updates as $core_update ) {
				if ( 'latest' === $core_update->response ) {
					break;
				}
				if ( 'upgrade' === $core_update->response && version_compare( $wp_version, $core_update->current, '<=' ) ) {
					$information['wp_updates'] = $core_update->current;
				}
			}
		}
		if ( ! isset( $information['wp_updates'] ) ) {
			$information['wp_updates'] = null;
		}
		if ( null !== $this->filterFunction ) {
			remove_filter( 'pre_site_transient_update_core', $this->filterFunction, 99 );
		}
		if ( null !== $this->filterFunction ) {
			remove_filter( 'pre_transient_update_core', $this->filterFunction, 99 );
		}

		add_filter( 'default_option_active_plugins', array( &$this, 'default_option_active_plugins' ) );
		add_filter( 'option_active_plugins', array( &$this, 'default_option_active_plugins' ) );

		//First check for new premium updates
		$update_check = apply_filters( 'mwp_premium_update_check', array() );
		if ( ! empty( $update_check ) ) {
			foreach ( $update_check as $updateFeedback ) {
				if ( is_array( $updateFeedback['callback'] ) && isset( $updateFeedback['callback'][0] ) && isset( $updateFeedback['callback'][1] ) ) {
					@call_user_func( array( $updateFeedback['callback'][0], $updateFeedback['callback'][1] ) );
				} else if ( is_string( $updateFeedback['callback'] ) ) {
					@call_user_func( $updateFeedback['callback'] );
				}
			}
		}

		$informationPremiumUpdates = apply_filters( 'mwp_premium_update_notification', array() );
		$premiumPlugins            = array();
		$premiumThemes             = array();
		if ( is_array( $informationPremiumUpdates ) ) {
			$premiumUpdates                 = array();
			$information['premium_updates'] = array();
			$informationPremiumUpdatesLength = count( $informationPremiumUpdates );
			for ( $i = 0; $i < $informationPremiumUpdatesLength; $i ++ ) {
				if ( ! isset( $informationPremiumUpdates[ $i ]['new_version'] ) ) {
					continue;
				}
				$slug = ( isset( $informationPremiumUpdates[ $i ]['slug'] ) ? $informationPremiumUpdates[ $i ]['slug'] : $informationPremiumUpdates[ $i ]['Name'] );

				if ( 'plugin' === $informationPremiumUpdates[ $i ]['type'] ) {
					$premiumPlugins[] = $slug;
				} else if ( 'theme' === $informationPremiumUpdates[ $i ]['type'] ) {
					$premiumThemes[] = $slug;
				}

				$new_version = $informationPremiumUpdates[ $i ]['new_version'];

				unset( $informationPremiumUpdates[ $i ]['old_version'] );
				unset( $informationPremiumUpdates[ $i ]['new_version'] );

				$information['premium_updates'][ $slug ]           = $informationPremiumUpdates[ $i ];
				$information['premium_updates'][ $slug ]['update'] = (object) array(
					'new_version' => $new_version,
					'premium'     => true,
					'slug'        => $slug,
				);
				if ( ! in_array( $slug, $premiumUpdates ) ) {
					$premiumUpdates[] = $slug;
				}
			}
			MainWP_Helper::update_option( 'mainwp_premium_updates', $premiumUpdates );
		}

		remove_filter( 'default_option_active_plugins', array( &$this, 'default_option_active_plugins' ) );
		remove_filter( 'option_active_plugins', array( &$this, 'default_option_active_plugins' ) );

		if ( null !== $this->filterFunction ) {
			add_filter( 'pre_site_transient_update_plugins', $this->filterFunction, 99 );
		}

		global $wp_current_filter;
		$wp_current_filter[] = 'load-plugins.php';

		@wp_update_plugins();
		include_once( ABSPATH . '/wp-admin/includes/plugin.php' );

		$plugin_updates = get_plugin_updates();
		if ( is_array( $plugin_updates ) ) {
			$information['plugin_updates'] = array();

			foreach ( $plugin_updates as $slug => $plugin_update ) {
				if ( in_array( $plugin_update->Name, $premiumPlugins ) ) {
					continue;
				}

                // to fix incorrect info
                if ( !property_exists( $plugin_update, 'update' ) || !property_exists( $plugin_update->update, 'new_version' ) || empty($plugin_update->update->new_version)) {
                    continue;
                }

				$information['plugin_updates'][ $slug ] = $plugin_update;
			}
		}

		if ( null !== $this->filterFunction ) {
			remove_filter( 'pre_site_transient_update_plugins', $this->filterFunction, 99 );
		}

        // to fix premium plugs update
        $cached_plugins_update = get_site_transient( 'mainwp_update_plugins_cached' );
        if ( is_array( $cached_plugins_update ) && ( count( $cached_plugins_update ) > 0 ) ) {
            if (!isset($information['plugin_updates'])) {
                $information['plugin_updates'] = array();
            }
            foreach( $cached_plugins_update as $slug => $plugin_update ) {

                // to fix incorrect info
                if ( !property_exists( $plugin_update, 'new_version' ) || empty( $plugin_update->new_version ) ) { // may do not need to check this?
					// to fix for some premiums update info
					if ( property_exists( $plugin_update, 'update' ) ) {
						if ( !property_exists( $plugin_update->update, 'new_version' ) || empty( $plugin_update->update->new_version ) ) {
                    continue;
                }
					} else {
						continue;
					}

                }

                if ( !isset( $information['plugin_updates'][ $slug ] ) ) {
                    $information['plugin_updates'][ $slug ] = $plugin_update;
                }
            }
        }

		if ( null !== $this->filterFunction ) {
			add_filter( 'pre_site_transient_update_themes', $this->filterFunction, 99 );
		}
		@wp_update_themes();
		include_once( ABSPATH . '/wp-admin/includes/theme.php' );
		$theme_updates = $this->upgrade_get_theme_updates();
		if ( is_array( $theme_updates ) ) {
			$information['theme_updates'] = array();

			foreach ( $theme_updates as $slug => $theme_update ) {
				$name = ( is_array( $theme_update ) ? $theme_update['Name'] : $theme_update->Name );
				if ( in_array( $name, $premiumThemes ) ) {
					continue;
				}

				$information['theme_updates'][ $slug ] = $theme_update;
			}
		}
		if ( null !== $this->filterFunction ) {
			remove_filter( 'pre_site_transient_update_themes', $this->filterFunction, 99 );
		}

        // to fix premium themes update
        $cached_themes_update = get_site_transient( 'mainwp_update_themes_cached' );
        if ( is_array( $cached_themes_update ) && ( count( $cached_themes_update ) > 0 ) ) {
            if (!isset($information['theme_updates'])) {
                $information['theme_updates'] = array();
            }

            foreach ( $cached_themes_update as $slug => $theme_update ) {
				$name = ( is_array( $theme_update ) ? $theme_update['Name'] : $theme_update->Name );
				if ( in_array( $name, $premiumThemes ) ) {
					continue;
				}
                if ( isset( $information['theme_updates'][ $slug ] ) ) {
                    continue;
                }
				$information['theme_updates'][ $slug ] = $theme_update;
			}
        }


		$translation_updates = wp_get_translation_updates();
		if ( !empty( $translation_updates ) ) {
			$information['translation_updates'] = array();
			foreach ($translation_updates as $translation_update)
			{
				$new_translation_update = array('type' => $translation_update->type,
				                                'slug' => $translation_update->slug,
				                                'language' => $translation_update->language,
				                                'version' => $translation_update->version);
				if ( 'plugin' === $translation_update->type ) {
					$all_plugins = get_plugins();
					foreach ( $all_plugins as $file => $plugin ) {
                        $path = dirname($file);
						if ( $path == $translation_update->slug ) {
							$new_translation_update['name'] = $plugin['Name'];
							break;
						}
					}
				} else if ( 'theme' === $translation_update->type ) {
					$theme = wp_get_theme($translation_update->slug);
					$new_translation_update['name'] = $theme->name;
				} else if ( ( 'core' === $translation_update->type ) && ( 'default' === $translation_update->slug ) ) {
					$new_translation_update['name'] = 'WordPress core';
				}
				$information['translation_updates'][] = $new_translation_update;
			}
		}

		$information['recent_comments'] = $this->get_recent_comments( array( 'approve', 'hold' ), 5 );

        $recent_number = 5;

        if (isset($_POST) && isset( $_POST['recent_number'] )) {
            $recent_number = $_POST['recent_number'];
            if ($recent_number != get_option('mainwp_child_recent_number', 5)) {
                update_option( 'mainwp_child_recent_number', $recent_number );
            }
        } else {
            $recent_number = get_option('mainwp_child_recent_number', 5);
        }

        if ($recent_number <= 0 || $recent_number > 30) {
            $recent_number = 5;
        }

		$information['recent_posts']    = $this->get_recent_posts( array( 'publish', 'draft', 'pending', 'trash', 'future' ), $recent_number );
		$information['recent_pages']    = $this->get_recent_posts( array(
			'publish',
			'draft',
			'pending',
			'trash',
            'future'
		), $recent_number, 'page' );

		$securityIssuess = 0;
		if ( ! MainWP_Security::prevent_listing_ok() ) {
			$securityIssuess ++;
		}
		if ( ! MainWP_Security::remove_wp_version_ok() ) {
			$securityIssuess ++;
		}
		if ( ! MainWP_Security::remove_rsd_ok() ) {
			$securityIssuess ++;
		}
		if ( ! MainWP_Security::remove_wlw_ok() ) {
			$securityIssuess ++;
		}
		//        if (!MainWP_Security::remove_core_update_ok()) $securityIssuess++;
		//        if (!MainWP_Security::remove_plugin_update_ok()) $securityIssuess++;
		//        if (!MainWP_Security::remove_theme_update_ok()) $securityIssuess++;
		//        if (!MainWP_Security::fix_file_permissions_ok()) $securityIssuess++;
		if ( ! MainWP_Security::remove_database_reporting_ok() ) {
			$securityIssuess ++;
		}
		if ( ! MainWP_Security::remove_php_reporting_ok() ) {
			$securityIssuess ++;
		}
		if ( ! MainWP_Security::remove_scripts_version_ok() || ! MainWP_Security::remove_styles_version_ok() || ! MainWP_Security::remove_generator_version_ok() ) {
			$securityIssuess ++;
		}
        if ( ! MainWP_Security::remove_registered_versions_ok() ) {
			$securityIssuess ++;
		}
		if ( ! MainWP_Security::admin_user_ok() ) {
			$securityIssuess ++;
		}
		if ( ! MainWP_Security::remove_readme_ok() ) {
			$securityIssuess ++;
		}

		$information['securityIssues'] = $securityIssuess;

		//Directory listings!
		$information['directories'] = $this->scanDir( ABSPATH, 3 );
		$cats                       = get_categories( array( 'hide_empty' => 0, 'hierarchical' => true, 'number' => 300 ) );
		$categories                 = array();
		foreach ( $cats as $cat ) {
			$categories[] = $cat->name;
		}
		$information['categories'] = $categories;
		$get_file_size = apply_filters('mainwp-child-get-total-size', true);
        if ( $get_file_size && isset( $_POST['cloneSites'] ) && ( '0' !== $_POST['cloneSites'] ) ) {
            $max_exe = ini_get( 'max_execution_time' ); // to fix issue of some hosts have limit of execution time
            if ($max_exe > 20) {
                $information['totalsize']  = $this->getTotalFileSize();
            }
        }
		$information['dbsize']     = MainWP_Child_DB::get_size();

		$auths                  = get_option( 'mainwp_child_auth' );
		$information['extauth'] = ( $auths && isset( $auths[ $this->maxHistory ] ) ? $auths[ $this->maxHistory ] : null );

		$plugins                = $this->get_all_plugins_int( false );
		$themes                 = $this->get_all_themes_int( false );
		$information['plugins'] = $plugins;
		$information['themes']  = $themes;

		if ( isset( $_POST['optimize'] ) && ( '1' === $_POST['optimize'] ) ) {
			$information['users'] = $this->get_all_users_int(500); // to fix
		}

        if (isset($_POST['primaryBackup']) && !empty($_POST['primaryBackup'])) {
            $primary_bk = $_POST['primaryBackup'];
            $information['primaryLasttimeBackup'] = MainWP_Helper::get_lasttime_backup($primary_bk);
        }

		$last_post = wp_get_recent_posts( array( 'numberposts' => absint( '1' ) ) );
		if ( isset( $last_post[0] ) ) {
			$last_post = $last_post[0];
		}
		if ( isset( $last_post ) && isset( $last_post['post_modified_gmt'] ) ) {
			$information['last_post_gmt'] = strtotime( $last_post['post_modified_gmt'] );
		}
		$information['mainwpdir']            = ( MainWP_Helper::validateMainWPDir() ? 1 : - 1 );
		$information['uniqueId']             = get_option( 'mainwp_child_uniqueId', '' );
		$information['plugins_outdate_info'] = MainWP_Child_Plugins_Check::Instance()->get_plugins_outdate_info();
		$information['themes_outdate_info']  = MainWP_Child_Themes_Check::Instance()->get_themes_outdate_info();

        if (isset( $_POST['user'] )) {
            $user = get_user_by( 'login', $_POST['user'] );
            if ( $user && property_exists($user, 'ID') && $user->ID) {
                $information['admin_nicename']  =    $user->data->user_nicename;
                $information['admin_useremail'] =    $user->data->user_email;
            }
        }

        try {
            do_action('mainwp_child_site_stats');
        } catch(Exception $e) {

        }

		if ( isset( $_POST['othersData'] ) ) {
			$othersData = json_decode( stripslashes( $_POST['othersData'] ), true );
			if ( ! is_array( $othersData ) ) {
				$othersData = array();
			}

			if ( isset( $othersData['wpvulndbToken'] ) ) {
				$wpvulndb_token = get_option( 'mainwp_child_wpvulndb_token', '' );
				if ( $wpvulndb_token != $othersData['wpvulndbToken'] ) {
					MainWP_Helper::update_option( 'mainwp_child_wpvulndb_token', $othersData['wpvulndbToken'] );
				}
			}

            try{
                $information = apply_filters( 'mainwp-site-sync-others-data', $information, $othersData );
            } catch(Exception $e) {
                // do not exit
            }
		}

		if ( $exit ) {
			MainWP_Helper::write( $information );
		}

		return $information;
	}

    function get_site_icon() {
        $information = array();
        $url = $this->get_favicon( true );
        if ( !empty( $url ) )
            $information['faviIconUrl'] = $url;
        MainWP_Helper::write( $information );
    }

	function get_favicon( $parse_page = false ) {

                $favi_url = '';
		$favi = ''; // to compatible

                $site_url = get_option( 'siteurl' );
                if ( substr( $site_url, - 1 ) != '/' ) {
                    $site_url .= '/';
                }

		if ( function_exists( 'get_site_icon_url' ) && has_site_icon() ) {
			$favi = $favi_url = get_site_icon_url();
		}

		if ( empty( $favi ) ) {
            if ( file_exists( ABSPATH . 'favicon.ico' ) ) {
                    $favi = 'favicon.ico';
            } else if ( file_exists( ABSPATH . 'favicon.png' ) ) {
                    $favi = 'favicon.png';
            }

            if ( !empty( $favi ) ) {
                $favi_url =  $site_url . $favi;
            }
		}

        if ($parse_page) {
            // try to parse page
            if (empty($favi_url)) {
                $request = wp_remote_get( $site_url, array( 'timeout' => 50 ) );
                $favi = '';
                if ( is_array( $request ) && isset( $request['body'] ) ) {
                  // to fix bug
                  $preg_str1 = '/(<link\s+(?:[^\>]*)(?:rel="shortcut\s+icon"\s*)(?:[^>]*)?href="([^"]+)"(?:[^>]*)?>)/is';
                  $preg_str2 = '/(<link\s+(?:[^\>]*)(?:rel="(?:shortcut\s+)?icon"\s*)(?:[^>]*)?href="([^"]+)"(?:[^>]*)?>)/is';

                  if ( preg_match( $preg_str1, $request['body'], $matches ) ) {
                    $favi = $matches[2];
                  } else if ( preg_match( $preg_str2, $request['body'], $matches ) ) {
                    $favi = $matches[2];
                  }
                }

                if ( !empty( $favi ) ){
                    if ( false === strpos( $favi, 'http' )) {
                         if (0 === strpos( $favi, '//' )) {
                             if (0 === strpos( $site_url, 'https' )) {
                                  $favi_url = 'https:' . $favi;
                             } else {
                                 $favi_url = 'http:' . $favi;
                             }
                         } else {
                            $favi_url = $site_url . $favi;
                         }
                    } else {
                        $favi_url = $favi;
                    }
                }
            }

            if ( !empty( $favi_url ) ) {
                return $favi_url;
            } else {
                return false;
            }
        } else {
            return $favi_url;
        }
	}

	function scanDir( $pDir, $pLvl ) {
		$output = array();
		if ( file_exists( $pDir ) && is_dir( $pDir ) ) {
			if ( 'logs' === basename( $pDir ) ) {
				return empty( $output ) ? null : $output;
			}
			if ( 0 === $pLvl ) {
				return empty( $output ) ? null : $output;
			}

			if ( $files = $this->intScanDir( $pDir ) ) {
				foreach ( $files as $file ) {
					if ( ( '.' === $file ) || ( '..' === $file ) ) {
						continue;
					}
					$newDir = $pDir . $file . DIRECTORY_SEPARATOR;
					if ( @is_dir( $newDir ) ) {
						$output[ $file ] = $this->scanDir( $newDir, $pLvl - 1, false );
					}
				}

				unset( $files );
				$files = null;
			}
		}

		return empty( $output ) ? null : $output;
	}

	function intScanDir( $dir ) {
		if ( @is_dir( $dir ) && ( $dh = @opendir( $dir ) ) ) {
			$cnt = 0;
			$out = array();
			while ( ( $file = @readdir( $dh ) ) !== false ) {
				$newDir = $dir . $file . DIRECTORY_SEPARATOR;
				if ( ! @is_dir( $newDir ) ) {
					continue;
				}

				$out[] = $file;
				if ( $cnt ++ > 10 ) {
					return $out;
				}
			}
			@closedir( $dh );

			return $out;
		}

		return false;
	}

	function upgrade_get_theme_updates() {
		$themeUpdates    = get_theme_updates();
		$newThemeUpdates = array();
		if ( is_array( $themeUpdates ) ) {
			foreach ( $themeUpdates as $slug => $themeUpdate ) {
				$newThemeUpdate            = array();
				$newThemeUpdate['update']  = $themeUpdate->update;
				$newThemeUpdate['Name']    = MainWP_Helper::search( $themeUpdate, 'Name' );
				$newThemeUpdate['Version'] = MainWP_Helper::search( $themeUpdate, 'Version' );
				$newThemeUpdates[ $slug ]  = $newThemeUpdate;
			}
		}

		return $newThemeUpdates;
	}

	function get_recent_posts( $pAllowedStatuses, $pCount, $type = 'post', $extra = null ) {
		$allPosts = array();
		if ( null !== $pAllowedStatuses ) {
			foreach ( $pAllowedStatuses as $status ) {
				$this->get_recent_posts_int( $status, $pCount, $type, $allPosts, $extra );
			}
		} else {
			$this->get_recent_posts_int( 'any', $pCount, $type, $allPosts, $extra );
		}

		return $allPosts;
	}

	function get_recent_posts_int( $status, $pCount, $type = 'post', &$allPosts, $extra = null ) {
		$args = array(
			'post_status'      => $status,
			'suppress_filters' => false,
			'post_type'        => $type,
		);

		$tokens = array();
		if ( is_array( $extra ) && isset( $extra['tokens'] ) ) {
			$tokens = $extra['tokens'];
			if ( 1 == $extra['extract_post_type'] ) {
				$args['post_type'] = 'post';
			} else if ( 2 == $extra['extract_post_type'] ) {
				$args['post_type'] = 'page';
			} else if ( 3 == $extra['extract_post_type'] ) {
				$args['post_type'] = array( 'post', 'page' );
			}
		}
		$tokens = array_flip( $tokens );

		if ( 0 !== $pCount ) {
			$args['numberposts'] = $pCount;
		}

        /*
        *
        * Credits
        *
        * Plugin-Name: Yoast SEO
        * Plugin URI: https://yoast.com/wordpress/plugins/seo/#utm_source=wpadmin&utm_medium=plugin&utm_campaign=wpseoplugin
        * Author: Team Yoast
        * Author URI: https://yoast.com/
        * Licence: GPL v3
        *
        * The code is used for the MainWP WordPress SEO Extension
        * Extension URL: https://mainwp.com/extension/wordpress-seo/
        *
       */

        $wp_seo_enabled = false;
        if ( isset( $_POST['WPSEOEnabled'] ) && $_POST['WPSEOEnabled']) {
           if (is_plugin_active('wordpress-seo/wp-seo.php') && class_exists('WPSEO_Link_Column_Count') && class_exists('WPSEO_Meta')) {
                $wp_seo_enabled = true;
           }
        }

		$posts = get_posts( $args );
		if ( is_array( $posts ) ) {
            if ($wp_seo_enabled) {
                $post_ids = array();
                foreach ( $posts as $post ) {
                    $post_ids[] = $post->ID;
                }
                $link_count = new WPSEO_Link_Column_Count();
                $link_count->set( $post_ids );
            }
			foreach ( $posts as $post ) {
				$outPost                  = array();
				$outPost['id']            = $post->ID;
				$outPost['post_type']     = $post->post_type;
				$outPost['status']        = $post->post_status;
				$outPost['title']         = $post->post_title;
				//$outPost['content']       = $post->post_content; // to fix overload memory
				$outPost['comment_count'] = $post->comment_count;
                // to support extract urls extension
				if ( isset( $extra['where_post_date'] ) && !empty( $extra['where_post_date'] ) ) {
					$outPost['dts'] = strtotime( $post->post_date_gmt );
				} else {
					$outPost['dts'] = strtotime( $post->post_modified_gmt );
				}

                if ($post->post_status == 'future') {
                    $outPost['dts'] = strtotime( $post->post_date_gmt );
                }

				$usr                      = get_user_by( 'id', $post->post_author );
				$outPost['author']        = ! empty( $usr ) ? $usr->user_nicename : 'removed';
				$categoryObjects          = get_the_category( $post->ID );
				$categories               = '';
				foreach ( $categoryObjects as $cat ) {
					if ( '' !== $categories ) {
						$categories .= ', ';
					}
					$categories .= $cat->name;
				}
				$outPost['categories'] = $categories;

				$tagObjects = get_the_tags( $post->ID );
				$tags       = '';
				if ( is_array( $tagObjects ) ) {
					foreach ( $tagObjects as $tag ) {
						if ( '' !== $tags ) {
							$tags .= ', ';
						}
						$tags .= $tag->name;
					}
				}
				$outPost['tags'] = $tags;

				if ( is_array( $tokens ) ) {
					if ( isset( $tokens['[post.url]'] ) ) {
						$outPost['[post.url]'] = get_permalink( $post->ID );
					}
					if ( isset( $tokens['[post.website.url]'] ) ) {
						$outPost['[post.website.url]'] = get_site_url();
					}
					if ( isset( $tokens['[post.website.name]'] ) ) {
						$outPost['[post.website.name]'] = get_bloginfo( 'name' );
					}
				}

                if ($wp_seo_enabled) {
                    $post_id = $post->ID;
                    $outPost['seo_data'] = array(
                        'count_seo_links' => $link_count->get( $post_id, 'internal_link_count' ),
                        'count_seo_linked' => $link_count->get( $post_id, 'incoming_link_count' ),
                        'seo_score' => MainWP_Wordpress_SEO::Instance()->parse_column_score($post_id),
                        'readability_score' => MainWP_Wordpress_SEO::Instance()->parse_column_score_readability($post_id),
                    );
                }

				$allPosts[] = $outPost;
			}
		}
	}

	function posts_where( $where ) {
		if ( $this->posts_where_suffix ) {
			$where .= ' ' . $this->posts_where_suffix;
		}

		return $where;
	}

	function get_all_posts() {
		$post_type = (isset($_POST['post_type']) ? $_POST['post_type'] : 'post');
		$this->get_all_posts_by_type( $post_type );
	}

	function get_terms() {
		$taxonomy = base64_decode( $_POST['taxonomy'] );
		$rslt     = get_terms( taxonomy_exists( $taxonomy ) ? $taxonomy : 'category', 'hide_empty=0' );
		MainWP_Helper::write( $rslt );
	}

	function set_terms() {
		$id       = base64_decode( $_POST['id'] );
		$terms    = base64_decode( $_POST['terms'] );
		$taxonomy = base64_decode( $_POST['taxonomy'] );

		if ( '' !== trim( $terms ) ) {
			$terms = explode( ',', $terms );
			if ( count( $terms ) > 0 ) {
				wp_set_object_terms( $id, array_map( 'intval', $terms ), taxonomy_exists( $taxonomy ) ? $taxonomy : 'category' );
			}
		}
	}

	function insert_comment() {
		$postId   = $_POST['id'];
		$comments = maybe_unserialize( base64_decode( $_POST['comments'] ) );
		$ids      = array();
		foreach ( $comments as $comment ) {
			$ids[] = wp_insert_comment( array(
				'comment_post_ID' => $postId,
				'comment_author'  => $comment['author'],
				'comment_content' => $comment['content'],
				'comment_date'    => $comment['date'],
			) );
		}
		MainWP_Helper::write( $ids );
	}

	function get_post_meta() {
		/** @var $wpdb wpdb */
		global $wpdb;
		$postId     = $_POST['id'];
		$keys       = base64_decode( unserialize( $_POST['keys'] ) );
		$meta_value = $_POST['value'];

		$where = '';
		if ( ! empty( $postId ) ) {
			$where .= " AND `post_id` = $postId ";
		}
		if ( ! empty( $keys ) ) {
			$str_keys = '\'' . implode( '\',\'', $keys ) . '\'';
			$where .= " AND `meta_key` IN = $str_keys ";
		}
		if ( ! empty( $meta_value ) ) {
			$where .= " AND `meta_value` = $meta_value ";
		}

		$results = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %s WHERE 1 = 1 $where ", $wpdb->postmeta ) );
		MainWP_Helper::write( $results );
	}

	function get_total_ezine_post() {
		/** @var $wpdb wpdb */
		global $wpdb;
		$start_date   = base64_decode( $_POST['start_date'] );
		$end_date     = base64_decode( $_POST['end_date'] );
		$keyword_meta = base64_decode( $_POST['keyword_meta'] );
		$where        = ' WHERE ';
		if ( ! empty( $start_date ) && ! empty( $end_date ) ) {
			$where .= "  p.post_date>='$start_date' AND p.post_date<='$end_date' AND ";
		} else if ( ! empty( $start_date ) && empty( $end_date ) ) {
			$where .= "  p.post_date='$start_date' AND ";
		}
		$where .= " ( p.post_status='publish' OR p.post_status='future' OR p.post_status='draft' )
                                AND  (pm.meta_key='_ezine_keyword' AND pm.meta_value='$keyword_meta')";
		$total = $wpdb->get_var( "SELECT COUNT(*)
								 FROM $wpdb->posts p JOIN $wpdb->postmeta pm ON p.ID=pm.post_id
								 $where  " );
		MainWP_Helper::write( $total );
	}

	function cancel_scheduled_post() {
		global $wpdb;
		$postId      = $_POST['post_id'];
		$cancel_all  = $_POST['cancel_all'];
		$result      = false;
		$information = array();
		if ( $postId > 0 ) {
			if ( 'yes' === get_post_meta( $postId, '_is_auto_generate_content', true ) ) {
				$post = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $wpdb->posts
											WHERE ID = %d
											AND post_status = 'future'", $postId ) );
				if ( $post ) {
					$result = wp_trash_post( $postId );
				} else {
					$result = true;
				}
			}
			if ( ! $result ) {
				$information['status'] = 'SUCCESS';
			}
		} else if ( $cancel_all ) {
			$post_type = $_POST['post_type'];
			$where     = " WHERE p.post_status='future' AND p.post_type = %s AND  pm.meta_key = '_is_auto_generate_content' AND pm.meta_value = 'yes' ";
			$posts     = $wpdb->get_results( $wpdb->prepare( "SELECT p.ID FROM $wpdb->posts p JOIN $wpdb->postmeta pm ON p.ID=pm.post_id $where ", $post_type) );
			$count     = 0;
			if ( is_array( $posts ) ) {
				foreach ( $posts as $post ) {
					if ( $post ) {
						if ( false !== wp_trash_post( $post->ID ) ) {
							$count ++;

						}
					}
				}
			} else {
				$posts = array();
			}

			$information['status'] = 'SUCCESS';
			$information['count']  = $count;
		}

		MainWP_Helper::write( $information );
	}

	function get_next_time_to_post() {
		$post_type = $_POST['post_type'];
		if ( 'post' !== $post_type && 'page' !== $post_type ) {
			MainWP_Helper::write( array( 'error' => 'Data error.' ) );

			return;
		}
		$information = array();
		try {
			global $wpdb;
			$ct        = current_time( 'mysql' );
			$next_post = $wpdb->get_row( $wpdb->prepare( '
                    SELECT *
                    FROM ' . $wpdb->posts . ' p JOIN ' . $wpdb->postmeta . " pm ON p.ID=pm.post_id
					WHERE
						pm.meta_key='_is_auto_generate_content' AND
						pm.meta_value='yes' AND
						p.post_status='future' AND
						p.post_type= %s AND
						p.post_date > NOW()
					ORDER BY p.post_date
					LIMIT 1", $post_type) );

			if ( ! $next_post ) {
				$information['error'] = __( 'No scheduled posts.', 'mainwp-child' );
			} else {
				$timestamp                                   = strtotime( $next_post->post_date );
				$timestamp_gmt                               = $timestamp - get_option( 'gmt_offset' ) * 60 * 60;
				$information['next_post_date_timestamp_gmt'] = $timestamp_gmt;
				$information['next_post_id']                 = $next_post->ID;
			}

			MainWP_Helper::write( $information );
		} catch ( Exception $e ) {
			$information['error'] = $e->getMessage();
			MainWP_Helper::write( $information );
		}
	}

	function get_all_pages() {
		$this->get_all_posts_by_type( 'page' );
	}

	function get_all_pages_int() {
		$rslt = $this->get_recent_posts( null, - 1, 'page' );

		return $rslt;
	}

	function get_all_posts_by_type( $type ) {
		global $wpdb;

		add_filter( 'posts_where', array( &$this, 'posts_where' ) );
		$where_post_date = isset($_POST['where_post_date']) && !empty($_POST['where_post_date']) ? true : false;
		if ( isset( $_POST['postId'] ) ) {
			$this->posts_where_suffix .= " AND $wpdb->posts.ID = " . $_POST['postId'];
		} else if ( isset( $_POST['userId'] ) ) {
			$this->posts_where_suffix .= " AND $wpdb->posts.post_author = " . $_POST['userId'];
		} else {
			if ( isset( $_POST['keyword'] ) ) {
                $search_on = isset($_POST['search_on']) ? $_POST['search_on'] : '';
                if ($search_on == 'title') {
                    $this->posts_where_suffix .= " AND ( $wpdb->posts.post_title LIKE '%" . $_POST['keyword'] . "%' )";
                } else if ($search_on == 'content') {
                    $this->posts_where_suffix .= " AND ($wpdb->posts.post_content LIKE '%" . $_POST['keyword'] . "%' )";
                } else {
                    $this->posts_where_suffix .= " AND ($wpdb->posts.post_content LIKE '%" . $_POST['keyword'] . "%' OR $wpdb->posts.post_title LIKE '%" . $_POST['keyword'] . "%' )";
                }
			}
			if ( isset( $_POST['dtsstart'] ) && '' !== $_POST['dtsstart'] ) {
				if ($where_post_date) {
					$this->posts_where_suffix .= " AND $wpdb->posts.post_date > '" . $_POST['dtsstart'] . "'";
				} else {
					$this->posts_where_suffix .= " AND $wpdb->posts.post_modified > '" . $_POST['dtsstart'] . "'";
				}
			}
			if ( isset( $_POST['dtsstop'] ) && '' !== $_POST['dtsstop'] ) {
				if ($where_post_date) {
					$this->posts_where_suffix .= " AND $wpdb->posts.post_date < '" . $_POST['dtsstop'] . "'";
				} else {
					$this->posts_where_suffix .= " AND $wpdb->posts.post_modified < '" . $_POST['dtsstop'] . "'";
				}
			}

            if ( isset( $_POST['exclude_page_type'] ) && $_POST['exclude_page_type'] ) {
                $this->posts_where_suffix .= " AND $wpdb->posts.post_type NOT IN ('page')";
            }
		}

		$maxPages = MAINWP_CHILD_NR_OF_PAGES;
		if ( isset( $_POST['maxRecords'] ) ) {
			$maxPages = $_POST['maxRecords'];
		}
		if ( 0 === $maxPages ) {
			$maxPages = 99999;
		}

		$extra = array();
		if ( isset( $_POST['extract_tokens'] ) ) {
			$extra['tokens']            = maybe_unserialize( base64_decode( $_POST['extract_tokens'] ) );
			$extra['extract_post_type'] = $_POST['extract_post_type'];
		}

		$extra['where_post_date'] = $where_post_date;
		$rslt                     = $this->get_recent_posts( explode( ',', $_POST['status'] ), $maxPages, $type, $extra );
		$this->posts_where_suffix = '';

		MainWP_Helper::write( $rslt );
	}

	function comments_clauses( $clauses ) {
		if ( $this->comments_and_clauses ) {
			$clauses['where'] .= ' ' . $this->comments_and_clauses;
		}

		return $clauses;
	}

	function get_all_comments() {
		global $wpdb;

		add_filter( 'comments_clauses', array( &$this, 'comments_clauses' ) );

		if ( isset( $_POST['postId'] ) ) {
			$this->comments_and_clauses .= " AND $wpdb->comments.comment_post_ID = " . $_POST['postId'];
		} else {
			if ( isset( $_POST['keyword'] ) ) {
				$this->comments_and_clauses .= " AND $wpdb->comments.comment_content LIKE '%" . $_POST['keyword'] . "%'";
			}
			if ( isset( $_POST['dtsstart'] ) && '' !== $_POST['dtsstart'] ) {
				$this->comments_and_clauses .= " AND $wpdb->comments.comment_date > '" . $_POST['dtsstart'] . "'";
			}
			if ( isset( $_POST['dtsstop'] ) && '' !== $_POST['dtsstop'] ) {
				$this->comments_and_clauses .= " AND $wpdb->comments.comment_date < '" . $_POST['dtsstop'] . "'";
			}
		}

		$maxComments = MAINWP_CHILD_NR_OF_COMMENTS;
		if ( isset( $_POST['maxRecords'] ) ) {
			$maxComments = $_POST['maxRecords'];
		}

		if ( 0 === $maxComments ) {
			$maxComments = 99999;
		}

		$rslt                       = $this->get_recent_comments( explode( ',', $_POST['status'] ), $maxComments );
		$this->comments_and_clauses = '';

		MainWP_Helper::write( $rslt );
	}

	function get_recent_comments( $pAllowedStatuses, $pCount ) {
		if ( ! function_exists( 'get_comment_author_url' ) ) {
			include_once( WPINC . '/comment-template.php' );
		}
		$allComments = array();

		foreach ( $pAllowedStatuses as $status ) {
			$params = array( 'status' => $status );
			if ( 0 !== $pCount ) {
				$params['number'] = $pCount;
			}
			$comments = get_comments( $params );
			if ( is_array( $comments ) ) {
				foreach ( $comments as $comment ) {
					$post                       = get_post( $comment->comment_post_ID );
					$outComment                 = array();
					$outComment['id']           = $comment->comment_ID;
					$outComment['status']       = wp_get_comment_status( $comment->comment_ID );
					$outComment['author']       = $comment->comment_author;
					$outComment['author_url']   = get_comment_author_url( $comment->comment_ID );
					$outComment['author_ip']    = get_comment_author_IP( $comment->comment_ID );
					$outComment['author_email'] = $email = apply_filters( 'comment_email', $comment->comment_author_email );
					if ( ( ! empty( $outComment['author_email'] ) ) && ( '@' !== $outComment['author_email'] ) ) {
						$outComment['author_email'] = '<a href="mailto:' . $outComment['author_email'] . '">' . $outComment['author_email'] . '</a>';
					}
					$outComment['postId']        = $comment->comment_post_ID;
					$outComment['postName']      = $post->post_title;
					$outComment['comment_count'] = $post->comment_count;
					$outComment['content']       = $comment->comment_content;
					$outComment['dts']           = strtotime( $comment->comment_date_gmt );
					$allComments[]               = $outComment;
				}
			}
		}

		return $allComments;
	}

	function theme_action() {
		//Read form data
		$action = $_POST['action'];
		$theme  = $_POST['theme'];

		if ( 'activate' === $action ) {
			include_once( ABSPATH . '/wp-admin/includes/theme.php' );
			$theTheme = wp_get_theme( $theme );
			if ( null !== $theTheme && '' !== $theTheme ) {
				switch_theme( $theTheme['Template'], $theTheme['Stylesheet'] );
			}
		} else if ( 'delete' === $action ) {
			include_once( ABSPATH . '/wp-admin/includes/theme.php' );
			//            if (file_exists(ABSPATH . '/wp-admin/includes/deprecated.php')) include_once(ABSPATH . '/wp-admin/includes/deprecated.php');
			if ( file_exists( ABSPATH . '/wp-admin/includes/screen.php' ) ) {
				include_once( ABSPATH . '/wp-admin/includes/screen.php' );
			}
			include_once( ABSPATH . '/wp-admin/includes/file.php' );
			include_once( ABSPATH . '/wp-admin/includes/template.php' );
			include_once( ABSPATH . '/wp-admin/includes/misc.php' );
			include_once( ABSPATH . '/wp-admin/includes/class-wp-upgrader.php' );
			include_once( ABSPATH . '/wp-admin/includes/class-wp-filesystem-base.php' );
			include_once( ABSPATH . '/wp-admin/includes/class-wp-filesystem-direct.php' );

			$wp_filesystem = $this->getWPFilesystem();
			if ( empty( $wp_filesystem ) ) {
				$wp_filesystem = new WP_Filesystem_Direct( null );
			}
			$themeUpgrader = new Theme_Upgrader();

			$theme_name = wp_get_theme()->get( 'Name' );
			$themes     = explode( '||', $theme );

            if (count($themes) == 1) {
                $themeToDelete = current($themes);
                if ( $themeToDelete == $theme_name ) {
                    $information['error'] = 'IsActivatedTheme';
                    MainWP_Helper::write( $information );
                    return;
                }
            }

			foreach ( $themes as $idx => $themeToDelete ) {
				if ( $themeToDelete !== $theme_name ) {
					$theTheme = wp_get_theme( $themeToDelete );
					if ( null !== $theTheme && '' !== $theTheme ) {
						$tmp['theme'] = $theTheme['Template'];
						if ( true === $themeUpgrader->delete_old_theme( null, null, null, $tmp ) ) {
							$args = array( 'action' => 'delete', 'Name' => $theTheme['Name'] );
							do_action( 'mainwp_child_theme_action', $args );
						}
					}
				}
			}
		} else {
			$information['status'] = 'FAIL';
		}

		if ( ! isset( $information['status'] ) ) {
			$information['status'] = 'SUCCESS';
		}
		$information['sync'] = $this->getSiteStats( array(), false );
		MainWP_Helper::write( $information );
	}

	function get_all_themes() {
		$keyword = $_POST['keyword'];
		$status  = $_POST['status'];
		$filter  = isset( $_POST['filter'] ) ? $_POST['filter'] : true;
		$rslt    = $this->get_all_themes_int( $filter, $keyword, $status );

		MainWP_Helper::write( $rslt );
	}

	function get_all_themes_int( $filter, $keyword = '', $status = '' ) {
		$rslt   = array();
		$themes = wp_get_themes();

		if ( is_array( $themes ) ) {
			$theme_name = wp_get_theme()->get( 'Name' );

			/** @var $theme WP_Theme */
			foreach ( $themes as $theme ) {
				$out                = array();
				$out['name']        = $theme->get( 'Name' );
				$out['title']       = $theme->display( 'Name', true, false );
				$out['description'] = $theme->display( 'Description', true, false );
				$out['version']     = $theme->display( 'Version', true, false );
				$out['active']      = ( $theme->get( 'Name' ) === $theme_name ) ? 1 : 0;
				$out['slug']        = $theme->get_stylesheet();
				if ( ! $filter ) {
					if ( '' == $keyword || stristr( $out['title'], $keyword ) ) {
						$rslt[] = $out;
					}
				} else if ( $out['active'] === ( ( 'active' === $status ) ? 1 : 0 ) ) {
					if ( '' == $keyword || stristr( $out['title'], $keyword ) ) {
						$rslt[] = $out;
					}
				}
			}
		}

		return $rslt;
	}

	function plugin_action() {
		//Read form data
		$action  = $_POST['action'];
		$plugins = explode( '||', $_POST['plugin'] );

		if ( 'activate' === $action ) {
			include_once( ABSPATH . '/wp-admin/includes/plugin.php' );

			foreach ( $plugins as $idx => $plugin ) {
				if ( $plugin !== $this->plugin_slug ) {
					$thePlugin = get_plugin_data( $plugin );
					if ( null !== $thePlugin && '' !== $thePlugin ) {
						// to fix activate issue
						if ('quotes-collection/quotes-collection.php' == $plugin) {
                            activate_plugin( $plugin, '', false, true );
                            //do_action( 'activate_plugin', $plugin, null );
                        } else {
                            activate_plugin( $plugin );
                        }
					}
				}
			}
		} else if ( 'deactivate' === $action ) {
			include_once( ABSPATH . '/wp-admin/includes/plugin.php' );

			foreach ( $plugins as $idx => $plugin ) {
				if ( $plugin !== $this->plugin_slug ) {
					$thePlugin = get_plugin_data( $plugin );
					if ( null !== $thePlugin && '' !== $thePlugin ) {
						deactivate_plugins( $plugin );
					}
				}
			}
		} else if ( 'delete' === $action ) {
			include_once( ABSPATH . '/wp-admin/includes/plugin.php' );
			//            if (file_exists(ABSPATH . '/wp-admin/includes/deprecated.php')) include_once(ABSPATH . '/wp-admin/includes/deprecated.php');
			if ( file_exists( ABSPATH . '/wp-admin/includes/screen.php' ) ) {
				include_once( ABSPATH . '/wp-admin/includes/screen.php' );
			}
			include_once( ABSPATH . '/wp-admin/includes/file.php' );
			include_once( ABSPATH . '/wp-admin/includes/template.php' );
			include_once( ABSPATH . '/wp-admin/includes/misc.php' );
			include_once( ABSPATH . '/wp-admin/includes/class-wp-upgrader.php' );
			include_once( ABSPATH . '/wp-admin/includes/class-wp-filesystem-base.php' );
			include_once( ABSPATH . '/wp-admin/includes/class-wp-filesystem-direct.php' );

			$wp_filesystem = $this->getWPFilesystem();
			if ( null === $wp_filesystem ) {
				$wp_filesystem = new WP_Filesystem_Direct( null );
			}
			$pluginUpgrader = new Plugin_Upgrader();

			$all_plugins = get_plugins();
			foreach ( $plugins as $idx => $plugin ) {
				if ( $plugin !== $this->plugin_slug ) {
					if ( isset( $all_plugins[ $plugin ] ) ) {
	                    if (is_plugin_active($plugin)) {
	                        $thePlugin = get_plugin_data( $plugin );
	                        if ( null !== $thePlugin && '' !== $thePlugin ) {
	                                deactivate_plugins( $plugin );
	                        }
	                    }
	                    $tmp['plugin'] = $plugin;
						if ( true === $pluginUpgrader->delete_old_plugin( null, null, null, $tmp ) ) {
							$args = array( 'action' => 'delete', 'Name' => $all_plugins[ $plugin ]['Name'] );
							do_action( 'mainwp_child_plugin_action', $args );
						}
					}
				}
			}
		} else {
			$information['status'] = 'FAIL';
		}

		if ( ! isset( $information['status'] ) ) {
			$information['status'] = 'SUCCESS';
		}
		$information['sync'] = $this->getSiteStats( array(), false );
		MainWP_Helper::write( $information );
	}

	function get_all_plugins() {
		$keyword = $_POST['keyword'];
		$status  = $_POST['status'];
		$filter  = isset( $_POST['filter'] ) ? $_POST['filter'] : true;
		$rslt    = $this->get_all_plugins_int( $filter, $keyword, $status );

		MainWP_Helper::write( $rslt );
	}

	function get_all_plugins_int( $filter, $keyword = '', $status = '' ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
		}
		$rslt    = array();
		$plugins = get_plugins();
		if ( is_array( $plugins ) ) {
			$active_plugins = get_option( 'active_plugins' );

			foreach ( $plugins as $pluginslug => $plugin ) {
				$out                = array();
				$out['mainwp']    = ($pluginslug == $this->plugin_slug ? 'T' : 'F');
				$out['name']        = $plugin['Name'];
				$out['slug']        = $pluginslug;
				$out['description'] = $plugin['Description'];
				$out['version']     = $plugin['Version'];
				$out['active']      = is_plugin_active($pluginslug) ? 1 : 0; // ( is_array( $active_plugins ) && in_array( $pluginslug, $active_plugins ) ) ? 1 : 0; // to fix for multisites
				if ( ! $filter ) {
					if ( '' == $keyword || stristr( $out['name'], $keyword ) ) {
						$rslt[] = $out;
					}
				} else if ( $out['active'] == ( ( $status == 'active' ) ? 1 : 0 ) ) {
					if ( '' == $keyword || stristr( $out['name'], $keyword ) ) {
						$rslt[] = $out;
					}
				}
			}
		}

		$muplugins = get_mu_plugins();
		if ( is_array( $muplugins ) ) {
			foreach ( $muplugins as $pluginslug => $plugin ) {
				$out                = array();
				$out['mainwp']    = ($pluginslug == $this->plugin_slug ? 'T' : 'F');
				$out['name']        = $plugin['Name'];
				$out['slug']        = $pluginslug;
				$out['description'] = $plugin['Description'];
				$out['version']     = $plugin['Version'];
				$out['active']      = 1;
				$out['mu']          = 1;
				if ( ! $filter ) {
					if ( '' == $keyword || stristr( $out['name'], $keyword ) ) {
						$rslt[] = $out;
					}
				} else if ( $out['active'] == ( ( $status == 'active' ) ? 1 : 0 ) ) {
					if ( '' == $keyword || stristr( $out['name'], $keyword ) ) {
						$rslt[] = $out;
					}
				}
			}
		}

		return $rslt;
	}

	function get_all_users($return = false) {
		$roles = explode( ',', $_POST['role'] );
		$allusers = array();
		if ( is_array( $roles ) ) {
			foreach ( $roles as $role ) {
				$new_users = get_users( 'role=' . $role );
				//            $allusers[$role] = array();
				foreach ( $new_users as $new_user ) {
					$usr                 = array();
					$usr['id']           = $new_user->ID;
					$usr['login']        = $new_user->user_login;
					$usr['nicename']     = $new_user->user_nicename;
					$usr['email']        = $new_user->user_email;
					$usr['registered']   = $new_user->user_registered;
					$usr['status']       = $new_user->user_status;
					$usr['display_name'] = $new_user->display_name;
					$usr['role']         = $role;
					$usr['post_count']   = count_user_posts( $new_user->ID );
					$usr['avatar']       = get_avatar( $new_user->ID, 32 );
					$allusers[]          = $usr;
				}
			}
		}
                if ($return)
                    return $allusers;
		MainWP_Helper::write( $allusers );
	}

	function get_all_users_int($number = false) {
		$allusers = array();

        $params = array();
        if ($number)
            $params['number'] = $number;

		$new_users = get_users($params);
		if ( is_array( $new_users ) ) {
			foreach ( $new_users as $new_user ) {
				$usr                 = array();
				$usr['id']           = $new_user->ID;
				$usr['login']        = $new_user->user_login;
				$usr['nicename']     = $new_user->user_nicename;
				$usr['email']        = $new_user->user_email;
				$usr['registered']   = $new_user->user_registered;
				$usr['status']       = $new_user->user_status;
				$usr['display_name'] = $new_user->display_name;
				$userdata            = get_userdata( $new_user->ID );
				$user_roles          = $userdata->roles;
				$user_role           = array_shift( $user_roles );
				$usr['role']         = $user_role;
				$usr['post_count']   = count_user_posts( $new_user->ID );
				$allusers[]          = $usr;
			}
		}

		return $allusers;
	}

	function search_users() {

		$search_user_role = array();
		$check_users_role = false;

		if (isset($_POST['role']) && !empty($_POST['role'])) {
			$check_users_role = true;
			$all_users_role = $this->get_all_users(true);
			foreach($all_users_role as $user) {
				$search_user_role[] = $user['id'];
			}
			unset($all_users_role);
		}

		$columns  = explode( ',', $_POST['search_columns'] );
		$allusers = array();
		$exclude  = array();

		foreach ( $columns as $col ) {
			if ( empty( $col ) ) {
				continue;
			}

			$user_query = new WP_User_Query( array(
				'search'         => $_POST['search'],
				'fields'         => 'all_with_meta',
				'search_columns' => array( $col ),
				'query_orderby'  => array( $col ),
				'exclude'        => $exclude,
			) );
			if ( ! empty( $user_query->results ) ) {
				foreach ( $user_query->results as $new_user ) {
                                        if ($check_users_role) {
                                            if (!in_array($new_user->ID, $search_user_role )){
                                                continue;
                                            }
                                        }
					$exclude[]           = $new_user->ID;
					$usr                 = array();
					$usr['id']           = $new_user->ID;
					$usr['login']        = $new_user->user_login;
					$usr['nicename']     = $new_user->user_nicename;
					$usr['email']        = $new_user->user_email;
					$usr['registered']   = $new_user->user_registered;
					$usr['status']       = $new_user->user_status;
					$usr['display_name'] = $new_user->display_name;
					$userdata            = get_userdata( $new_user->ID );
					$user_roles          = $userdata->roles;
					$user_role           = array_shift( $user_roles );
					$usr['role']         = $user_role;
					$usr['post_count']   = count_user_posts( $new_user->ID );
					$usr['avatar']       = get_avatar( $new_user->ID, 32 );
					$allusers[]          = $usr;
				}
			}
		}

		MainWP_Helper::write( $allusers );
	}

	//Show stats without login - only allowed while no account is added yet
	function getSiteStatsNoAuth( $information = array() ) {
		if ( get_option( 'mainwp_child_pubkey' ) ) {
			$hint = '<br/>' . __('Hint: Go to the child site, deactivate and reactivate the MainWP Child plugin and try again.', 'mainwp-child');
			MainWP_Helper::error(__('This site already contains a link. Please deactivate and reactivate the MainWP plugin.','mainwp-child') . $hint);
		}

		global $wp_version;
		$information['version']   = self::$version;
		$information['wpversion'] = $wp_version;
		$information['wpe']   = MainWP_Helper::is_wp_engine() ? 1 : 0;
		MainWP_Helper::write( $information );
	}

	//Deactivating the plugin
	function deactivate() {
		include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
		deactivate_plugins( $this->plugin_slug, true );
		$information = array();
		if ( is_plugin_active( $this->plugin_slug ) ) {
			MainWP_Helper::error( 'Plugin still active' );
		}
		$information['deactivated'] = true;
		MainWP_Helper::write( $information );
	}

	function activation() {
        $mu_plugin_enabled = apply_filters('mainwp_child_mu_plugin_enabled', false);
        if ($mu_plugin_enabled)
            return;

		$to_delete = array(
			'mainwp_child_pubkey',
			'mainwp_child_nonce',
			'mainwp_child_nossl',
			'mainwp_child_nossl_key',
		);
		foreach ( $to_delete as $delete ) {
			if ( get_option( $delete ) ) {
				delete_option( $delete );
			}
		}

		MainWP_Helper::update_option( 'mainwp_child_activated_once', true );

		// delete bad data if existed
		$to_delete = array( 'mainwp_ext_snippets_enabled', 'mainwp_ext_code_snippets' );
		foreach ( $to_delete as $delete ) {
			delete_option( $delete );
		}
	}

	function deactivation( $deact = true) {

        $mu_plugin_enabled = apply_filters('mainwp_child_mu_plugin_enabled', false);
        if ($mu_plugin_enabled)
            return;

		$to_delete   = array(
			'mainwp_child_pubkey',
			'mainwp_child_nonce',
			'mainwp_child_nossl',
			'mainwp_child_nossl_key',
			'mainwp_security',
			'mainwp_child_server',
		);
		$to_delete[] = 'mainwp_ext_snippets_enabled';
		$to_delete[] = 'mainwp_ext_code_snippets';

		foreach ( $to_delete as $delete ) {
			if ( get_option( $delete ) ) {
				delete_option( $delete );
				wp_cache_delete( $delete, 'options' );
			}
		}

		if ($deact)
			do_action( 'mainwp_child_deactivation' );
	}

	function getWPFilesystem() {
		global $wp_filesystem;

		if ( empty( $wp_filesystem ) ) {
			ob_start();
			//            if (file_exists(ABSPATH . '/wp-admin/includes/deprecated.php')) include_once(ABSPATH . '/wp-admin/includes/deprecated.php');
			if ( file_exists( ABSPATH . '/wp-admin/includes/screen.php' ) ) {
				include_once( ABSPATH . '/wp-admin/includes/screen.php' );
			}
			if ( file_exists( ABSPATH . '/wp-admin/includes/template.php' ) ) {
				include_once( ABSPATH . '/wp-admin/includes/template.php' );
			}
			$creds = request_filesystem_credentials( 'test', '', false, false, $extra_fields = null );
			ob_end_clean();
			if ( empty( $creds ) ) {
				define( 'FS_METHOD', 'direct' );
			}
			WP_Filesystem( $creds );
		}

		if ( empty( $wp_filesystem ) ) {
			MainWP_Helper::error( $this->FTP_ERROR );
		} else if ( is_wp_error( $wp_filesystem->errors ) ) {
			$errorCodes = $wp_filesystem->errors->get_error_codes();
			if ( ! empty( $errorCodes ) ) {
				MainWP_Helper::error( __( 'WordPress Filesystem error: ', 'mainwp-child' ) . $wp_filesystem->errors->get_error_message() );
			}
		}

		return $wp_filesystem;
	}

	function getTotalFileSize( $directory = WP_CONTENT_DIR ) {
		try {
//			function continueFileSize( $dir, $limit ) {
//				$dirs = array( $dir );
//				$cnt = 0;
//				while ( isset( $dirs[0] ) ) {
//					$path = array_shift( $dirs );
//					if ( stristr( $path, WP_CONTENT_DIR . '/uploads/mainwp' ) ) {
//						continue;
//					}
//					$uploadDir = MainWP_Helper::getMainWPDir();
//					$uploadDir = $uploadDir[0];
//					if ( stristr( $path, $uploadDir ) ) {
//						continue;
//					}
//					$res = @glob( $path . '/*' );
//					if ( is_array( $res ) ) {
//						foreach ( $res as $next ) {
//							if ( is_dir( $next ) ) {
//								$dirs[] = $next;
//							} else {
//								if ($cnt++ > $limit) return false;;
//							}
//						}
//					}
//				}
//				return true;
//			}
//
//			if ( !continueFilesize( $directory, 20000 ) ) return 0;

			if ( MainWP_Helper::function_exists( 'popen' ) ) {
				$uploadDir   = MainWP_Helper::getMainWPDir();
				$uploadDir   = $uploadDir[0];
				$popenHandle = @popen( 'du -s ' . $directory . ' --exclude "' . str_replace( ABSPATH, '', $uploadDir ) . '"', 'r' );
				if ( 'resource' === gettype( $popenHandle ) ) {
					$size = @fread( $popenHandle, 1024 );
					@pclose( $popenHandle );
					$size = substr( $size, 0, strpos( $size, "\t" ) );
					if ( $size && MainWP_Helper::ctype_digit( $size ) ) {
						return $size / 1024;
					}
				}
			}

			if ( MainWP_Helper::function_exists( 'shell_exec' ) ) {
				$uploadDir = MainWP_Helper::getMainWPDir();
				$uploadDir = $uploadDir[0];
				$size      = @shell_exec( 'du -s ' . $directory . ' --exclude "' . str_replace( ABSPATH, '', $uploadDir ) . '"' );
				if ( null !== $size ) {
					$size = substr( $size, 0, strpos( $size, "\t" ) );
					if ( $size && MainWP_Helper::ctype_digit( $size ) ) {
						return $size / 1024;
					}
				}
			}
			if ( class_exists( 'COM' ) ) {
				$obj = new COM( 'scripting.filesystemobject' );

				if ( is_object( $obj ) ) {
					$ref = $obj->getfolder( $directory );

					$size = $ref->size;

					$obj = null;
					if ( MainWP_Helper::ctype_digit( $size ) ) {
						return $size / 1024;
					}
				}
			}
            // to fix for window host, performance not good?
            if ( class_exists( 'RecursiveIteratorIterator' ) ) {
                $size = 0;
                foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file){
                    $size+=$file->getSize();
                }
                if ( $size && MainWP_Helper::ctype_digit( $size ) ) {
                    return $size / 1024 / 1024;
                }
			}

//			function dirsize( $dir ) {
//				$dirs = array( $dir );
//				$size = 0;
//				while ( isset( $dirs[0] ) ) {
//					$path = array_shift( $dirs );
//					if ( stristr( $path, WP_CONTENT_DIR . '/uploads/mainwp' ) ) {
//						continue;
//					}
//					$uploadDir = MainWP_Helper::getMainWPDir();
//					$uploadDir = $uploadDir[0];
//					if ( stristr( $path, $uploadDir ) ) {
//						continue;
//					}
//					$res = @glob( $path . '/*' );
//					if ( is_array( $res ) ) {
//						foreach ( $res as $next ) {
//							if ( is_dir( $next ) ) {
//								$dirs[] = $next;
//							} else {
//								$fs = filesize( $next );
//								$size += $fs;
//							}
//						}
//					}
//				}
//
//				return $size / 1024 / 1024;
//			}
//
//			return dirsize( $directory );
			return 0;
		} catch ( Exception $e ) {
			return 0;
		}
	}

	function serverInformation() {
		@ob_start();
		MainWP_Child_Server_Information::render();
		$output['information'] = @ob_get_contents();
		@ob_end_clean();
		@ob_start();
		MainWP_Child_Server_Information::renderCron();
		$output['cron'] = @ob_get_contents();
		@ob_end_clean();
		@ob_start();
		MainWP_Child_Server_Information::renderErrorLogPage();
		$output['error'] = @ob_get_contents();
		@ob_end_clean();
		@ob_start();
		MainWP_Child_Server_Information::renderWPConfig();
		$output['wpconfig'] = @ob_get_contents();
		@ob_end_clean();
		@ob_start();
		MainWP_Child_Server_Information::renderhtaccess();
		$output['htaccess'] = @ob_get_contents();
		@ob_end_clean();

		MainWP_Helper::write( $output );
	}

	function maintenance_site() {
		global $wpdb;
		$information = array();
		if ( isset( $_POST['action'] ) ) {
			if ( 'save_settings' === $_POST['action'] ) {

				if ( isset( $_POST['enable_alert'] ) && '1' === $_POST['enable_alert'] ) {
					MainWP_Helper::update_option( 'mainwp_maintenance_opt_alert_404', 1, 'yes' );
				} else {
					delete_option( 'mainwp_maintenance_opt_alert_404' );
				}

				if ( isset( $_POST['email'] ) && ! empty( $_POST['email'] ) ) {
					MainWP_Helper::update_option( 'mainwp_maintenance_opt_alert_404_email', $_POST['email'], 'yes' );
				} else {
					delete_option( 'mainwp_maintenance_opt_alert_404_email' );
				}
				$information['result'] = 'SUCCESS';
				MainWP_Helper::write( $information );

				return;
			} else if ( 'clear_settings' === $_POST['action'] ) {
				delete_option( 'mainwp_maintenance_opt_alert_404' );
				delete_option( 'mainwp_maintenance_opt_alert_404_email' );
				$information['result'] = 'SUCCESS';
				MainWP_Helper::write( $information );
			}
			MainWP_Helper::write( $information );
		}

		$maint_options = $_POST['options'];
		$max_revisions = isset( $_POST['revisions'] ) ? intval( $_POST['revisions'] ) : 0;

		if ( ! is_array( $maint_options ) ) {
			$information['status'] = 'FAIL';
			$maint_options         = array();
		}


//		$this->options = array(
//			'revisions'    => __( 'Delete all post revisions', 'mainwp-maintenance-extension' ),
//			'autodraft'    => __( 'Delete all auto draft posts',                   'mainwp-maintenance-extension' ),
//			'trashpost'    => __( 'Delete trash posts',                            'mainwp-maintenance-extension' ),
//			'spam'         => __( 'Delete spam comments',                          'mainwp-maintenance-extension' ),
//			'pending'      => __( 'Delete pending comments',                       'mainwp-maintenance-extension' ),
//			'trashcomment' => __( 'Delete trash comments',                         'mainwp-maintenance-extension' ),
//			'tags'         => __( 'Delete tags with 0 posts associated',           'mainwp-maintenance-extension' ),
//			'categories'   => __( 'Delete categories with 0 posts associated',     'mainwp-maintenance-extension' ),
//			'optimize'     => __( 'Optimize database tables',                      'mainwp-maintenance-extension' )
//		);

        $performed_what = array();
		if ( empty( $max_revisions ) ) {
			$sql_clean = "DELETE FROM $wpdb->posts WHERE post_type = 'revision'";
			$wpdb->query( $sql_clean );
			// to fix issue of meta_value short length
            $performed_what[] = 'revisions'; //'Posts revisions deleted';
		} else {
			$results       = MainWP_Helper::getRevisions( $max_revisions );
			$count_deleted = MainWP_Helper::deleteRevisions( $results, $max_revisions );
            $performed_what[] = 'revisions'; //'Posts revisions deleted';
		}

		if ( in_array( 'autodraft', $maint_options ) ) {
			$sql_clean = "DELETE FROM $wpdb->posts WHERE post_status = 'auto-draft'";
			$wpdb->query( $sql_clean );
            $performed_what[] = 'autodraft'; //'Auto draft posts deleted';
		}

		if ( in_array( 'trashpost', $maint_options ) ) {
			$sql_clean = "DELETE FROM $wpdb->posts WHERE post_status = 'trash'";
			$wpdb->query( $sql_clean );
            $performed_what[] = 'trashpost'; //'Trash posts deleted';
		}

		if ( in_array( 'spam', $maint_options ) ) {
			$sql_clean = "DELETE FROM $wpdb->comments WHERE comment_approved = 'spam'";
			$wpdb->query( $sql_clean );
            $performed_what[] = 'spam'; //'Spam comments deleted';
		}

		if ( in_array( 'pending', $maint_options ) ) {
			$sql_clean = "DELETE FROM $wpdb->comments WHERE comment_approved = '0'";
			$wpdb->query( $sql_clean );
            $performed_what[] = 'pending'; //'Pending comments deleted';
		}

		if ( in_array( 'trashcomment', $maint_options ) ) {
			$sql_clean = "DELETE FROM $wpdb->comments WHERE comment_approved = 'trash'";
			$wpdb->query( $sql_clean );
            $performed_what[] = 'trashcomment'; //'Trash comments deleted';
		}

		if ( in_array( 'tags', $maint_options ) ) {
			$post_tags = get_terms( 'post_tag', array( 'hide_empty' => false ) );
			if ( is_array( $post_tags ) ) {
				foreach ( $post_tags as $tag ) {
					if ( 0 === $tag->count ) {
						wp_delete_term( $tag->term_id, 'post_tag' );
					}
				}
			}
            $performed_what[] = 'tags'; //'Tags with 0 posts associated deleted';
		}

		if ( in_array( 'categories', $maint_options ) ) {
			$post_cats = get_terms( 'category', array( 'hide_empty' => false ) );
			if ( is_array( $post_cats ) ) {
				foreach ( $post_cats as $cat ) {
					if ( 0 === $cat->count ) {
						wp_delete_term( $cat->term_id, 'category' );
					}
				}
			}
            $performed_what[] = 'categories'; //'Categories with 0 posts associated deleted';
		}

		if ( in_array( 'optimize', $maint_options ) ) {
			$this->maintenance_optimize();
            $performed_what[] = 'optimize'; //'Database optimized';
		}
		if ( ! isset( $information['status'] ) ) {
			$information['status'] = 'SUCCESS';
		}

	    if ( !empty( $performed_what ) && has_action( 'mainwp_reports_maintenance' ) ) {
	        $details = implode( ',', $performed_what );
	        $log_time = time();
	        $message = $result = "Maintenance Performed";
	        do_action( 'mainwp_reports_maintenance', $message, $log_time, $details, $result);
	    }

		MainWP_Helper::write( $information );
	}

	function maintenance_optimize() {
		global $wpdb, $table_prefix;
		$sql    = 'SHOW TABLE STATUS FROM `' . DB_NAME . '`';
		$result = @MainWP_Child_DB::_query( $sql, $wpdb->dbh );
		if ( @MainWP_Child_DB::num_rows( $result ) && @MainWP_Child_DB::is_result( $result ) ) {
			while ( $row = MainWP_Child_DB::fetch_array( $result ) ) {
				if ( strpos( $row['Name'], $table_prefix ) !== false ) {
					$sql = 'OPTIMIZE TABLE ' . $row['Name'];
					MainWP_Child_DB::_query( $sql, $wpdb->dbh );
				}
			}
		}
	}

	function maintenance_alert_404() {
		if ( ! is_404() ) {
			return;
		}

		if ( 1 !== (int) get_option( 'mainwp_maintenance_opt_alert_404' ) ) {
			return;
		}

		$email = get_option( 'mainwp_maintenance_opt_alert_404_email' );

		if ( empty( $email ) || ! preg_match( '/^[a-zA-Z0-9_.+-]+@[a-zA-Z0-9-]+\.[a-zA-Z0-9-.]+$/is', $email ) ) {
			return;
		}

		// set status
		header( 'HTTP/1.1 404 Not Found' );
		header( 'Status: 404 Not Found' );

		// site info
		$blog       = get_bloginfo( 'name' );
		$site       = get_bloginfo( 'url' ) . '/';
		$from_email = get_bloginfo( 'admin_email' );

		// referrer
		if ( isset( $_SERVER['HTTP_REFERER'] ) ) {
			$referer = MainWP_Helper::clean( $_SERVER['HTTP_REFERER'] );
		} else {
			$referer = 'undefined';
		}
		$protocol = isset( $_SERVER['HTTPS'] ) && strcasecmp( $_SERVER['HTTPS'], 'off' ) ? 'https://' : 'http://';
		// request URI
		if ( isset( $_SERVER['REQUEST_URI'] ) && isset( $_SERVER['HTTP_HOST'] ) ) {
			$request = MainWP_Helper::clean( $protocol . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] );
		} else {
			$request = 'undefined';
		}
		// query string
		if ( isset( $_SERVER['QUERY_STRING'] ) ) {
			$string = MainWP_Helper::clean( $_SERVER['QUERY_STRING'] );
		} else {
			$string = 'undefined';
		}
		// IP address
		if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$address = MainWP_Helper::clean( $_SERVER['REMOTE_ADDR'] );
		} else {
			$address = 'undefined';
		}
		// user agent
		if ( isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
			$agent = MainWP_Helper::clean( $_SERVER['HTTP_USER_AGENT'] );
		} else {
			$agent = 'undefined';
		}
		// identity
		if ( isset( $_SERVER['REMOTE_IDENT'] ) ) {
			$remote = MainWP_Helper::clean( $_SERVER['REMOTE_IDENT'] );
		} else {
			$remote = 'undefined';
		}
		// log time
		$time = MainWP_Helper::clean( date( 'F jS Y, h:ia', time() ) );

		$mail = '<div>' . 'TIME: ' . $time . '</div>' .
		        '<div>' . '*404: ' . $request . '</div>' .
		        '<div>' . 'SITE: ' . $site . '</div>' .
		        '<div>' . 'REFERRER: ' . $referer . '</div>' .
		        '<div>' . 'QUERY STRING: ' . $string . '</div>' .
		        '<div>' . 'REMOTE ADDRESS: ' . $address . '</div>' .
		        '<div>' . 'REMOTE IDENTITY: ' . $remote . '</div>' .
		        '<div>' . 'USER AGENT: ' . $agent . '</div>';
		$mail = '<div>404 alert</div>
                <div></div>' . $mail;
		@wp_mail( $email, 'MainWP - 404 Alert: ' . $blog, MainWP_Helper::formatEmail( $email, $mail ), array(
			//'From: "' . $from_email . '" <' . $from_email . '>',
			'content-type: text/html',
		) );

	}

	public function keyword_links_action() {
		MainWP_Keyword_Links::Instance()->action();
	}

	public function branding_child_plugin() {
		MainWP_Child_Branding::Instance()->action();
	}

	public function code_snippet() {
		$action      = $_POST['action'];
		$information = array( 'status' => 'FAIL' );
		if ( 'run_snippet' === $action || 'save_snippet' === $action ) {
			if ( ! isset( $_POST['code'] ) ) {
				MainWP_Helper::write( $information );
			}
		}
		$code = stripslashes( $_POST['code'] );
		if ( 'run_snippet' === $action ) {
			$information = MainWP_Tools::execute_snippet( $code );
		} else if ( 'save_snippet' === $action ) {
			$type     = $_POST['type'];
			$slug     = $_POST['slug'];
			$snippets = get_option( 'mainwp_ext_code_snippets' );

			if ( ! is_array( $snippets ) ) {
				$snippets = array();
			}

			if ( 'C' === $type ) {// save into wp-config file
				if ( false !== $this->snippetUpdateWPConfig( 'save', $slug, $code ) ) {
					$information['status'] = 'SUCCESS';
				}
			} else {
				$snippets[ $slug ] = $code;
				if ( MainWP_Helper::update_option( 'mainwp_ext_code_snippets', $snippets ) ) {
					$information['status'] = 'SUCCESS';
				}
			}
			MainWP_Helper::update_option( 'mainwp_ext_snippets_enabled', true, 'yes' );
		} else if ( 'delete_snippet' === $action ) {
			$type     = $_POST['type'];
			$slug     = $_POST['slug'];
			$snippets = get_option( 'mainwp_ext_code_snippets' );

			if ( ! is_array( $snippets ) ) {
				$snippets = array();
			}
			if ( 'C' === $type ) {// delete in wp-config file
				if ( false !== $this->snippetUpdateWPConfig( 'delete', $slug ) ) {
					$information['status'] = 'SUCCESS';
				}
			} else {
				if ( isset( $snippets[ $slug ] ) ) {
					unset( $snippets[ $slug ] );
					if ( MainWP_Helper::update_option( 'mainwp_ext_code_snippets', $snippets ) ) {
						$information['status'] = 'SUCCESS';
					}
				} else {
					$information['status'] = 'SUCCESS';
				}
			}
		}
		MainWP_Helper::write( $information );
	}

	public function snippetUpdateWPConfig( $action, $slug, $code = '' ) {

        $config_file = '';
        if ( file_exists( ABSPATH . 'wp-config.php') ) {

            /** The config file resides in ABSPATH */
            $config_file =  ABSPATH . 'wp-config.php';

        } elseif ( @file_exists( dirname( ABSPATH ) . '/wp-config.php' ) && ! @file_exists( dirname( ABSPATH ) . '/wp-settings.php' ) ) {

            /** The config file resides one level above ABSPATH but is not part of another install */
            $config_file =  dirname( ABSPATH ) . '/wp-config.php';

        }

        if ( !empty($config_file) ) {
            $wpConfig = file_get_contents( $config_file );

            if ( 'delete' === $action ) {
                $wpConfig = preg_replace( '/' . PHP_EOL . '{1,2}\/\*\*\*snippet_' . $slug . '\*\*\*\/(.*)\/\*\*\*end_' . $slug . '\*\*\*\/' . PHP_EOL . '/is', '', $wpConfig );
            } else if ( 'save' === $action ) {
                $wpConfig = preg_replace( '/(\$table_prefix *= *[\'"][^\'|^"]*[\'"] *;)/is', '${1}' . PHP_EOL . PHP_EOL . '/***snippet_' . $slug . '***/' . PHP_EOL . $code . PHP_EOL . '/***end_' . $slug . '***/' . PHP_EOL, $wpConfig );
            }
            file_put_contents( $config_file, $wpConfig );

            return true;
        }
        return false;
	}

	function run_saved_snippets() {
		$action = null;
		if ( isset( $_POST['action'] ) ) {
			$action = $_POST['action'];
		}

		if ( 'run_snippet' === $action || 'save_snippet' === $action || 'delete_snippet' === $action ) {
			return;
		} // do not run saved snippets if in do action snippet

		if ( get_option( 'mainwp_ext_snippets_enabled' ) ) {
			$snippets = get_option( 'mainwp_ext_code_snippets' );
			if ( is_array( $snippets ) && count( $snippets ) > 0 ) {
				foreach ( $snippets as $code ) {
					MainWP_Tools::execute_snippet( $code );
				}
			}
		}
	}


	function uploader_action() {
		$file_url    = base64_decode( $_POST['url'] );
		$path        = $_POST['path'];
		$filename    = $_POST['filename'];
		$information = array();

		if ( empty( $file_url ) || empty( $path ) ) {
			MainWP_Helper::write( $information );

			return;
		}

		if ( strpos( $path, 'wp-content' ) === 0 ) {
			$path = basename( WP_CONTENT_DIR ) . substr( $path, 10 );
		} else if ( strpos( $path, 'wp-includes' ) === 0 ) {
			$path = WPINC . substr( $path, 11 );
		}

		if ( '/' === $path ) {
			$dir = ABSPATH;
		} else {
			$path = str_replace( ' ', '-', $path );
			$path = str_replace( '.', '-', $path );
			$dir  = ABSPATH . $path;
		}

		if ( ! file_exists( $dir ) ) {
			if ( false === @mkdir( $dir, 0777, true ) ) {
				$information['error'] = 'ERRORCREATEDIR';
				MainWP_Helper::write( $information );

				return;
			}
		}

		try {
			$upload = MainWP_Helper::uploadFile( $file_url, $dir, $filename );
			if ( null !== $upload ) {
				$information['success'] = true;
			}
		} catch ( Exception $e ) {
			$information['error'] = $e->getMessage();
		}
		MainWP_Helper::write( $information );
	}

	function wordpress_seo() {
		MainWP_Wordpress_SEO::Instance()->action();
	}

	function client_report() {
		MainWP_Client_Report::Instance()->action();
	}

	function page_speed() {
		MainWP_Child_Pagespeed::Instance()->action();
	}

	function woo_com_status() {
		MainWP_Child_WooCommerce_Status::Instance()->action();
	}

	function links_checker() {
		MainWP_Child_Links_Checker::Instance()->action();
	}

	function wordfence() {
		MainWP_Child_Wordfence::Instance()->action();
	}

	function ithemes() {
		MainWP_Child_iThemes_Security::Instance()->action();
	}


	function updraftplus() {
		MainWP_Child_Updraft_Plus_Backups::Instance()->action();
	}

    function wpvivid_backuprestore()
    {
        MainWP_Child_WPvivid_BackupRestore::Instance()->action();
    }

	function backup_wp() {
		if ( ! version_compare( phpversion(), '5.3', '>=' ) ) {
			$error = sprintf( __( 'PHP Version %s is unsupported.', 'mainwp-child' ), phpversion() );
			MainWP_Helper::write( array( 'error' => $error ) );
		}
		MainWP_Child_Back_Up_Wordpress::Instance()->action();
	}

	function wp_rocket() {
		MainWP_Child_WP_Rocket::Instance()->action();
	}

	function backwpup() {
		MainWP_Child_Back_WP_Up::Instance()->action();
	}


	function delete_backup() {
		$dirs      = MainWP_Helper::getMainWPDir( 'backup' );
		$backupdir = $dirs[0];

		$file = $_REQUEST['del'];

		if ( @file_exists( $backupdir . $file ) ) {
			@unlink( $backupdir . $file );
		}

		MainWP_Helper::write( array( 'result' => 'ok' ) );
	}

	function update_values() {
		$uniId = isset( $_POST['uniqueId'] ) ? $_POST['uniqueId'] : '';
		MainWP_Helper::update_option( 'mainwp_child_uniqueId', $uniId );
		MainWP_Helper::write( array( 'result' => 'ok' ) );
	}

	function uploadFile( $file, $offset = 0 ) {
		$dirs      = MainWP_Helper::getMainWPDir( 'backup' );
		$backupdir = $dirs[0];

		header( 'Content-Description: File Transfer' );

		header( 'Content-Description: File Transfer' );
		if ( MainWP_Helper::endsWith( $file, '.tar.gz' ) ) {
			header( 'Content-Type: application/x-gzip' );
			header( "Content-Encoding: gzip" );
		} else {
			header( 'Content-Type: application/octet-stream' );
		}
		header( 'Content-Disposition: attachment; filename="' . basename( $file ) . '"' );
		header( 'Expires: 0' );
		header( 'Cache-Control: must-revalidate' );
		header( 'Pragma: public' );
		header( 'Content-Length: ' . filesize( $backupdir . $file ) );
		while ( @ob_end_flush() ) {
			;
		}
		$this->readfile_chunked( $backupdir . $file, $offset );
	}

	function readfile_chunked( $filename, $offset ) {
		$chunksize = 1024; // how many bytes per chunk
		$handle    = @fopen( $filename, 'rb' );
		if ( false === $handle ) {
			return false;
		}

		@fseek( $handle, $offset );

		while ( ! @feof( $handle ) ) {
			$buffer = @fread( $handle, $chunksize );
			echo $buffer;
			@ob_flush();
			@flush();
			$buffer = null;
		}

		return @fclose( $handle );
	}

	function settings_tools() {
		if ( isset( $_POST['action'] ) ) {
			switch ( $_POST['action'] ) {
				case 'force_destroy_sessions';
					if ( 0 === get_current_user_id() ) {
						MainWP_Helper::write( array( 'error' => __( 'Cannot get user_id', 'mainwp-child' ) ) );
					}

					wp_destroy_all_sessions();

					$sessions = wp_get_all_sessions();

					if ( empty( $sessions ) ) {
						MainWP_Helper::write( array( 'success' => 1 ) );
					} else {
						MainWP_Helper::write( array( 'error' => __( 'Cannot destroy sessions', 'mainwp-child' ) ) );
					}
					break;

				default:
					MainWP_Helper::write( array( 'error' => __( 'Invalid action', 'mainwp-child' ) ) );
			}
		} else {
			MainWP_Helper::write( array( 'error' => __( 'Missing action', 'mainwp-child' ) ) );
		}
	}

	function skeleton_key() {
		MainWP_Child_Skeleton_Key::Instance()->action();
	}

	function custom_post_type() {
        MainWP_Custom_Post_Type::Instance()->action();
    }

    function backup_buddy() {
        MainWP_Child_Back_Up_Buddy::Instance()->action();
    }

    function vulner_checker() {
        MainWP_Child_Vulnerability_Checker::Instance()->action();
    }

    function time_capsule() {
        MainWP_Child_Timecapsule::Instance()->action();
    }

    function wp_staging() {
        MainWP_Child_Staging::Instance()->action();
    }

    function extra_execution() {
        $post = $_POST;
        $information = array();
        $information = apply_filters('mainwp_child_extra_execution', $information, $post);
        MainWP_Helper::write( $information );
    }

    function disconnect() {
		$this->deactivation(false);
		MainWP_Helper::write( array( 'result' => 'success' ) );
    }

	static function fix_for_custom_themes() {
		if ( file_exists( ABSPATH . '/wp-admin/includes/screen.php' ) ) {
			include_once( ABSPATH . '/wp-admin/includes/screen.php' );
		}

		if ( function_exists( 'et_register_updates_component' ) ) {
			et_register_updates_component();
		}
	}
}
