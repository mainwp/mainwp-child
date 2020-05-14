<?php

namespace MainWP\Child;

// phpcs:disable
if ( defined( 'MAINWP_CHILD_DEBUG' ) && MAINWP_CHILD_DEBUG === true ) {
	error_reporting( E_ALL );
	ini_set( 'display_errors', true );
	ini_set( 'display_startup_errors', true );
} else {
	if ( isset( $_REQUEST['mainwpsignature'] ) ) {
		ini_set( 'display_errors', false );
		error_reporting( 0 );
	}
}
// phpcs:enable


require_once ABSPATH . '/wp-admin/includes/file.php';
require_once ABSPATH . '/wp-admin/includes/plugin.php';

class MainWP_Child {

	public static $version  = '4.0.7.1';
	private $update_version = '1.5';

	public $plugin_slug;
	private $plugin_dir;
	private $maxHistory = 5;

	public static $brandingTitle = null;

	public static $subPages;
	public static $subPagesLoaded = false;

	public function __construct( $plugin_file ) {
		$this->update();
		$this->load_all_options();

		$this->plugin_dir  = dirname( $plugin_file );
		$this->plugin_slug = plugin_basename( $plugin_file );

		add_action( 'template_redirect', array( $this, 'template_redirect' ) );
		add_action( 'init', array( &$this, 'check_login' ), 1 );
		add_action( 'init', array( &$this, 'parse_init' ), 9999 );
		add_action( 'admin_menu', array( &$this, 'admin_menu' ) );
		add_action( 'admin_init', array( &$this, 'admin_init' ) );
		add_action( 'admin_head', array( &$this, 'admin_head' ) );
		add_action( 'init', array( &$this, 'localization' ), 33 );
		add_action( 'pre_current_active_plugins', array( MainWP_Child_Updates::get_instance(), 'detect_premium_themesplugins_updates' ) ); // to support detect premium plugins update.
		add_action( 'core_upgrade_preamble', array( MainWP_Child_Updates::get_instance(), 'detect_premium_themesplugins_updates' ) ); // to support detect premium themes.

		if ( is_admin() ) {
			MainWP_Helper::update_option( 'mainwp_child_plugin_version', self::$version, 'yes' );
		}

		MainWP_Connect::instance()->check_other_auth();

		MainWP_Clone::get()->init();
		MainWP_Child_Server_Information::init();
		MainWP_Client_Report::instance()->init();
		MainWP_Child_Plugins_Check::instance();
		MainWP_Child_Themes_Check::instance();
		MainWP_Utility::instance()->run_saved_snippets();

		if ( ! get_option( 'mainwp_child_pubkey' ) ) {
			MainWP_Child_Branding::instance()->save_branding_options( 'branding_disconnected', 'yes' );
		}

		add_action( 'admin_notices', array( &$this, 'admin_notice' ) );
		add_filter( 'plugin_row_meta', array( &$this, 'plugin_row_meta' ), 10, 2 );

		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			if ( isset( $_GET['mainwp_child_run'] ) && ! empty( $_GET['mainwp_child_run'] ) ) {
				add_action( 'init', array( MainWP_Utility::get_class_name(), 'cron_active' ), PHP_INT_MAX );
			}
		}
	}

	public function load_all_options() {
		global $wpdb;

		if ( ! defined( 'WP_INSTALLING' ) || ! is_multisite() ) {
			$alloptions = wp_cache_get( 'alloptions', 'options' );
		} else {
			$alloptions = false;
		}

		if ( ! defined( 'WP_INSTALLING' ) || ! is_multisite() ) {
			$notoptions = wp_cache_get( 'notoptions', 'options' );
		} else {
			$notoptions = false;
		}

		if ( ! isset( $alloptions['mainwp_db_version'] ) ) {
			$suppress = $wpdb->suppress_errors();
			$options  = array(
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
				'mainwp_branding_button_contact_label',
				'mainwp_branding_extra_settings',
				'mainwp_branding_child_hide',
				'mainwp_branding_ext_enabled',
				'mainwp_pagespeed_ext_enabled',
				'mainwp_linkschecker_ext_enabled',
				'mainwp_child_branding_settings',
				'mainwp_child_plugintheme_days_outdate',
			);
			$query    = "SELECT option_name, option_value FROM $wpdb->options WHERE option_name in (";
			foreach ( $options as $option ) {
				$query .= "'" . $option . "', ";
			}
			$query  = substr( $query, 0, strlen( $query ) - 2 );
			$query .= ")"; // phpcs:ignore

			$alloptions_db = $wpdb->get_results( $query ); // phpcs:ignore -- safe query
			$wpdb->suppress_errors( $suppress );
			if ( ! is_array( $alloptions ) ) {
				$alloptions = array();
			}
			if ( is_array( $alloptions_db ) ) {
				foreach ( (array) $alloptions_db as $o ) {
					$alloptions[ $o->option_name ] = $o->option_value;
					unset( $options[ array_search( $o->option_name, $options ) ] );
				}
				foreach ( $options as $option ) {
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


	public function update() {
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
			);
			foreach ( $options as $option ) {
				MainWP_Helper::fix_option( $option );
			}
		} elseif ( ( '1.0' === $update_version ) || ( '1.1' === $update_version ) ) {
			$options = array(
				'mainwp_child_pubkey',
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
				'mainwp_pagespeed_ext_enabled',
				'mainwp_linkschecker_ext_enabled',
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

		if ( ! empty( $update_version ) && version_compare( $update_version, '1.4', '<=' ) ) {
			if ( ! is_array( get_option( 'mainwp_child_branding_settings' ) ) ) {
				$brandingOptions = array(
					'hide'                     => 'mainwp_branding_child_hide',
					'extra_settings'           => 'mainwp_branding_extra_settings',
					'preserve_branding'        => 'mainwp_branding_preserve_branding',
					'branding_header'          => 'mainwp_branding_plugin_header',
					'support_email'            => 'mainwp_branding_support_email',
					'support_message'          => 'mainwp_branding_support_message',
					'remove_restore'           => 'mainwp_branding_remove_restore',
					'remove_setting'           => 'mainwp_branding_remove_setting',
					'remove_server_info'       => 'mainwp_branding_remove_server_info',
					'remove_connection_detail' => 'mainwp_branding_remove_connection_detail',
					'remove_wp_tools'          => 'mainwp_branding_remove_wp_tools',
					'remove_wp_setting'        => 'mainwp_branding_remove_wp_setting',
					'remove_permalink'         => 'mainwp_branding_remove_permalink',
					'contact_label'            => 'mainwp_branding_button_contact_label',
					'email_message'            => 'mainwp_branding_send_email_message',
					'message_return_sender'    => 'mainwp_branding_message_return_sender',
					'submit_button_title'      => 'mainwp_branding_submit_button_title',
					'disable_wp_branding'      => 'mainwp_branding_disable_wp_branding',
					'show_support'             => 'mainwp_branding_show_support',
					'disable_change'           => 'mainwp_branding_disable_change',
					'disable_switching_theme'  => 'mainwp_branding_disable_switching_theme',
					'branding_ext_enabled'     => 'mainwp_branding_ext_enabled',
				);

				$convertBranding = array();
				foreach ( $brandingOptions as $option => $old ) {
					$value                      = get_option( $old );
					$convertBranding[ $option ] = $value;
				}
				MainWP_Helper::update_option( 'mainwp_child_branding_settings', $convertBranding );
			}
		}

		MainWP_Helper::update_option( 'mainwp_child_update_version', $this->update_version, 'yes' );
	}


	public function admin_notice() {
		// Admin Notice...
		if ( ! get_option( 'mainwp_child_pubkey' ) && MainWP_Helper::is_admin() && is_admin() ) {
			$branding_opts  = MainWP_Child_Branding::instance()->get_branding_options();
			$child_name     = ( '' === $branding_opts['branding_preserve_title'] ) ? 'MainWP Child' : $branding_opts['branding_preserve_title'];
			$dashboard_name = ( '' === $branding_opts['branding_preserve_title'] ) ? 'MainWP Dashboard' : $branding_opts['branding_preserve_title'] . ' Dashboard';

			$msg  = '<div class="wrap"><div class="postbox" style="margin-top: 4em;"><p style="background: #a00; color: #fff; font-size: 22px; font-weight: bold; margin: 0; padding: .3em;">';
			$msg .= __( 'Attention!', 'mainwp-child' );
			$msg .= '</p><div style="padding-left: 1em; padding-right: 1em;"><p style="font-size: 16px;">';
			$msg .= __( 'Please add this site to your ', 'mainwp-child' ) . $dashboard_name . ' ' . __( '<b>NOW</b> or deactivate the ', 'mainwp-child' ) . $child_name . __( ' plugin until you are ready to connect this site to your Dashboard in order to avoid unexpected security issues.', 'mainwp-child' );
			$msg .= '</p>';
			$msg .= '<p style="font-size: 16px;">';
			$msg .= __( 'If you are not sure how to add this site to your Dashboard, <a href="https://mainwp.com/help/docs/set-up-the-mainwp-plugin/add-site-to-your-dashboard/" target="_blank">please review these instructions</a>.', 'mainwp-child' );
			$msg .= '</p>';
			if ( ! MainWP_Child_Branding::instance()->is_branding() ) {
				$msg .= '<p>';
				$msg .= __( 'You can also turn on the unique security ID option in <a href="admin.php?page=mainwp_child_tab">', 'mainwp-child' ) . $child_name . __( ' settings</a> if you would like extra security and additional time to add this site to your Dashboard. <br/>Find out more in this help document <a href="https://mainwp.com/help/docs/set-up-the-mainwp-plugin/set-unique-security-id/" target="_blank">How do I use the child unique security ID?</a>', 'mainwp-child' );
				$msg .= '</p>';
			}
			$msg .= '</div></div></div>';
			echo wp_kses_post( $msg );
		}
		MainWP_Child_Server_Information::show_warnings();
	}

	public function localization() {
		load_plugin_textdomain( 'mainwp-child', false, dirname( dirname( plugin_basename( __FILE__ ) ) ) . '/languages/' );
	}

	public function template_redirect() {
		MainWP_Utility::instance()->maintenance_alert();
	}

	public function plugin_row_meta( $plugin_meta, $plugin_file ) {
		if ( $this->plugin_slug !== $plugin_file ) {
			return $plugin_meta;
		}

		return apply_filters( 'mainwp_child_plugin_row_meta', $plugin_meta, $plugin_file, $this->plugin_slug );
	}

	public function admin_menu() {
		$branding_opts      = MainWP_Child_Branding::instance()->get_branding_options();
		$is_hide            = isset( $branding_opts['hide'] ) ? $branding_opts['hide'] : '';
		$cancelled_branding = $branding_opts['cancelled_branding'];

		if ( isset( $branding_opts['remove_wp_tools'] ) && $branding_opts['remove_wp_tools'] && ! $cancelled_branding ) {
			remove_menu_page( 'tools.php' );
			$pos = stripos( $_SERVER['REQUEST_URI'], 'tools.php' ) || stripos( $_SERVER['REQUEST_URI'], 'import.php' ) || stripos( $_SERVER['REQUEST_URI'], 'export.php' );
			if ( false !== $pos ) {
				wp_safe_redirect( get_option( 'siteurl' ) . '/wp-admin/index.php' );
			}
		}
		// if preserve branding and do not remove menus.
		if ( isset( $branding_opts['remove_wp_setting'] ) && $branding_opts['remove_wp_setting'] && ! $cancelled_branding ) {
			remove_menu_page( 'options-general.php' );
			$pos = stripos( $_SERVER['REQUEST_URI'], 'options-general.php' ) || stripos( $_SERVER['REQUEST_URI'], 'options-writing.php' ) || stripos( $_SERVER['REQUEST_URI'], 'options-reading.php' ) || stripos( $_SERVER['REQUEST_URI'], 'options-discussion.php' ) || stripos( $_SERVER['REQUEST_URI'], 'options-media.php' ) || stripos( $_SERVER['REQUEST_URI'], 'options-permalink.php' );
			if ( false !== $pos ) {
				wp_safe_redirect( get_option( 'siteurl' ) . '/wp-admin/index.php' );
				exit();
			}
		}

		if ( isset( $branding_opts['remove_permalink'] ) && $branding_opts['remove_permalink'] && ! $cancelled_branding ) {
			remove_submenu_page( 'options-general.php', 'options-permalink.php' );
			$pos = stripos( $_SERVER['REQUEST_URI'], 'options-permalink.php' );
			if ( false !== $pos ) {
				wp_safe_redirect( get_option( 'siteurl' ) . '/wp-admin/index.php' );
				exit();
			}
		}

		$remove_all_child_menu = false;
		if ( isset( $branding_opts['remove_setting'] ) && isset( $branding_opts['remove_restore'] ) && isset( $branding_opts['remove_server_info'] ) && $branding_opts['remove_setting'] && $branding_opts['remove_restore'] && $branding_opts['remove_server_info'] ) {
			$remove_all_child_menu = true;
		}

		// if preserve branding and do not hide menus.
		if ( ( ! $remove_all_child_menu && 'T' !== $is_hide ) || $cancelled_branding ) {
			$branding_header = isset( $branding_opts['branding_header'] ) ? $branding_opts['branding_header'] : array();
			if ( ( is_array( $branding_header ) && ! empty( $branding_header['name'] ) ) && ! $cancelled_branding ) {
				self::$brandingTitle = stripslashes( $branding_header['name'] );
				$child_menu_title    = stripslashes( $branding_header['name'] );
				$child_page_title    = $child_menu_title . ' Settings';
			} else {
				$child_menu_title = 'MainWP Child';
				$child_page_title = 'MainWPSettings';
			}

			$settingsPage = add_submenu_page( 'options-general.php', $child_menu_title, $child_menu_title, 'manage_options', 'mainwp_child_tab', array( &$this, 'render_pages' ) );

			add_action( 'admin_print_scripts-' . $settingsPage, array( MainWP_Clone::get_class_name(), 'print_scripts' ) );
			$subpageargs = array(
				'child_slug'  => 'options-general.php',
				'branding'    => ( null === self::$brandingTitle ) ? 'MainWP' : self::$brandingTitle,
				'parent_menu' => $settingsPage,
			);

			do_action_deprecated( 'mainwp-child-subpages', array( $subpageargs ), '4.0.7.1', 'mainwp_child_subpages' );
			do_action( 'mainwp_child_subpages', $subpageargs );

			$sub_pages = array();

			$all_subpages = apply_filters_deprecated( 'mainwp-child-init-subpages', array( array() ), '4.0.7.1', 'mainwp_child_init_subpages' );
			$all_subpages = apply_filters( 'mainwp_child_init_subpages', $all_subpages );

			if ( ! is_array( $all_subpages ) ) {
				$all_subpages = array();
			}

			if ( ! self::$subPagesLoaded ) {
				foreach ( $all_subpages as $page ) {
					$slug = isset( $page['slug'] ) ? $page['slug'] : '';
					if ( empty( $slug ) ) {
						continue;
					}
					$subpage          = array();
					$subpage['slug']  = $slug;
					$subpage['title'] = $page['title'];
					$subpage['page']  = 'mainwp-' . str_replace( ' ', '-', strtolower( str_replace( '-', ' ', $slug ) ) );
					if ( isset( $page['callback'] ) ) {
						$subpage['callback'] = $page['callback'];
						$created_page        = add_submenu_page( 'options-general.php', $subpage['title'], '<div class="mainwp-hidden">' . $subpage['title'] . '</div>', 'manage_options', $subpage['page'], $subpage['callback'] );
						if ( isset( $page['load_callback'] ) ) {
							$subpage['load_callback'] = $page['load_callback'];
							add_action( 'load-' . $created_page, $subpage['load_callback'] );
						}
					}
					$sub_pages[] = $subpage;
				}
				self::$subPages       = $sub_pages;
				self::$subPagesLoaded = true;
			}
			add_action( 'mainwp-child-pageheader', array( __CLASS__, 'render_header' ) );
			add_action( 'mainwp-child-pagefooter', array( __CLASS__, 'render_footer' ) );

			global $submenu;
			if ( isset( $submenu['options-general.php'] ) ) {
				foreach ( $submenu['options-general.php'] as $index => $item ) {
					if ( 'mainwp-reports-page' === $item[2] || 'mainwp-reports-settings' === $item[2] ) {
						unset( $submenu['options-general.php'][ $index ] );
					}
				}
			}
		}
	}

	public function render_pages( $shownPage ) {
		$shownPage = '';
		if ( isset( $_GET['tab'] ) ) {
			$shownPage = $_GET['tab'];
		}
		$branding_opts = MainWP_Child_Branding::instance()->get_branding_options();

		$hide_settings          = isset( $branding_opts['remove_setting'] ) && $branding_opts['remove_setting'] ? true : false;
		$hide_restore           = isset( $branding_opts['remove_restore'] ) && $branding_opts['remove_restore'] ? true : false;
		$hide_server_info       = isset( $branding_opts['remove_server_info'] ) && $branding_opts['remove_server_info'] ? true : false;
		$hide_connection_detail = isset( $branding_opts['remove_connection_detail'] ) && $branding_opts['remove_connection_detail'] ? true : false;

		$hide_style = 'style="display:none"';

		if ( '' == $shownPage ) {
			if ( ! $hide_settings ) {
					$shownPage = 'settings';
			} elseif ( ! $hide_restore ) {
				$shownPage = 'restore-clone';
			} elseif ( ! $hide_server_info ) {
				$shownPage = 'server-info';
			} elseif ( ! $hide_connection_detail ) {
				$shownPage = 'connection-detail';
			}
		}

		if ( ! $hide_restore ) {
			if ( '' === session_id() ) {
				session_start();
			}
		}

		self::render_header( $shownPage, false );
		?>
		<?php if ( ! $hide_settings ) { ?>
			<div class="mainwp-child-setting-tab settings" <?php echo ( 'settings' !== $shownPage ) ? $hide_style : ''; ?>>
				<?php $this->render_settings(); ?>
			</div>
		<?php } ?>

		<?php if ( ! $hide_restore ) { ?>
			<div class="mainwp-child-setting-tab restore-clone" <?php echo ( 'restore-clone' !== $shownPage ) ? $hide_style : ''; ?>>
				<?php
				if ( isset( $_SESSION['file'] ) ) {
					MainWP_Clone::render_restore();
				} else {
					$sitesToClone = get_option( 'mainwp_child_clone_sites' );
					if ( 0 !== (int) $sitesToClone ) {
						MainWP_Clone::render();
					} else {
						MainWP_Clone::render_normal_restore();
					}
				}
				?>
			</div>
		<?php } ?>

		<?php if ( ! $hide_server_info ) { ?>
			<div class="mainwp-child-setting-tab server-info" <?php echo ( 'server-info' !== $shownPage ) ? $hide_style : ''; ?>>
				<?php MainWP_Child_Server_Information::render_page(); ?>
			</div>
		<?php } ?>

				<?php if ( ! $hide_connection_detail ) { ?>
			<div class="mainwp-child-setting-tab connection-detail" <?php echo ( 'connection-detail' !== $shownPage ) ? $hide_style : ''; ?>>
							<?php MainWP_Child_Server_Information::render_connection_details(); ?>
			</div>
		<?php } ?>



		<?php
		self::render_footer();
	}

	public static function render_header( $shownPage, $subpage = true ) {
		if ( isset( $_GET['tab'] ) ) {
			$shownPage = $_GET['tab'];
		}

		if ( empty( $shownPage ) ) {
			$shownPage = 'settings';
		}

		$branding_opts = MainWP_Child_Branding::instance()->get_branding_options();

		$hide_settings          = isset( $branding_opts['remove_setting'] ) && $branding_opts['remove_setting'] ? true : false;
		$hide_restore           = isset( $branding_opts['remove_restore'] ) && $branding_opts['remove_restore'] ? true : false;
		$hide_server_info       = isset( $branding_opts['remove_server_info'] ) && $branding_opts['remove_server_info'] ? true : false;
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
		<h2><i class="fa fa-file"></i> <?php echo ( null === self::$brandingTitle ? 'MainWP Child' : self::$brandingTitle ); ?></h2>
		<div style="clear: both;"></div><br/>
		<div class="mainwp-tabs" id="mainwp-tabs">
			<?php if ( ! $hide_settings ) { ?>
				<a class="nav-tab pos-nav-tab
				<?php
				if ( 'settings' === $shownPage ) {
					echo 'nav-tab-active'; }
				?>
" tab-slug="settings" href="<?php echo $subpage ? 'options-general.php?page=mainwp_child_tab&tab=settings' : '#'; ?>" style="margin-left: 0 !important;"><?php _e( 'Settings', 'mainwp-child' ); ?></a>
			<?php } ?>
			<?php if ( ! $hide_restore ) { ?>
				<a class="nav-tab pos-nav-tab
				<?php
				if ( 'restore-clone' === $shownPage ) {
					echo 'nav-tab-active'; }
				?>
" tab-slug="restore-clone" href="<?php echo $subpage ? 'options-general.php?page=mainwp_child_tab&tab=restore-clone' : '#'; ?>"><?php echo ( 0 !== (int) $sitesToClone ) ? __( 'Restore / Clone', 'mainwp-child' ) : __( 'Restore', 'mainwp-child' ); ?></a>
			<?php } ?>
			<?php if ( ! $hide_server_info ) { ?>
				<a class="nav-tab pos-nav-tab
				<?php
				if ( 'server-info' === $shownPage ) {
					echo 'nav-tab-active'; }
				?>
" tab-slug="server-info" href="<?php echo $subpage ? 'options-general.php?page=mainwp_child_tab&tab=server-info' : '#'; ?>"><?php _e( 'Server information', 'mainwp-child' ); ?></a>
			<?php } ?>
						<?php if ( ! $hide_connection_detail ) { ?>
				<a class="nav-tab pos-nav-tab
							<?php
							if ( 'connection-detail' === $shownPage ) {
								echo 'nav-tab-active'; }
							?>
" tab-slug="connection-detail" href="<?php echo $subpage ? 'options-general.php?page=mainwp_child_tab&tab=connection-detail' : '#'; ?>"><?php _e( 'Connection Details', 'mainwp-child' ); ?></a>
			<?php } ?>
			<?php
			if ( isset( self::$subPages ) && is_array( self::$subPages ) ) {
				foreach ( self::$subPages as $subPage ) {
					?>
					<a class="nav-tab pos-nav-tab
					<?php
					if ( $shownPage == $subPage['slug'] ) {
						echo 'nav-tab-active'; }
					?>
" tab-slug="<?php echo esc_attr( $subPage['slug'] ); ?>" href="options-general.php?page=<?php echo rawurlencode( $subPage['page'] ); ?>"><?php echo esc_html( $subPage['title'] ); ?></a>
					<?php
				}
			}
			?>
			<div style="clear:both;"></div>
		</div>
		<div style="clear:both;"></div>
		<script type="text/javascript">
			jQuery( document ).ready( function () {
				$hideMenu = jQuery( '#menu-settings li a .mainwp-hidden' );
				$hideMenu.each( function() {
					jQuery( this ).closest( 'li' ).hide();
				} );

				var $tabs = jQuery( '.mainwp-tabs' );

				$tabs.on( 'click', 'a', function () {
					if ( jQuery( this ).attr( 'href' ) !=='#' )
						return true;

					jQuery( '.mainwp-tabs > a' ).removeClass( 'nav-tab-active' );
					jQuery( this ).addClass( 'nav-tab-active' );
					jQuery( '.mainwp-child-setting-tab' ).hide();
					var _tab = jQuery( this ).attr( 'tab-slug' );
					jQuery( '.mainwp-child-setting-tab.' + _tab ).show();
					return false;
				} );
			} );
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

	public function admin_init() {
		if ( MainWP_Helper::is_admin() && is_admin() ) {
			MainWP_Clone::get()->init_ajax();
		}
	}

	public function admin_head() {
		if ( isset( $_GET['page'] ) && 'mainwp_child_tab' == $_GET['page'] ) {
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
	public function render_settings() {
		if ( isset( $_POST['submit'] ) && isset( $_POST['nonce'] ) && wp_verify_nonce( $_POST['nonce'], 'child-settings' ) ) {
			if ( isset( $_POST['requireUniqueSecurityId'] ) ) {
				MainWP_Helper::update_option( 'mainwp_child_uniqueId', MainWP_Helper::rand_string( 8 ) );
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
						<input name="requireUniqueSecurityId" type="checkbox" id="requireUniqueSecurityId"
						<?php
						if ( '' != get_option( 'mainwp_child_uniqueId' ) ) {
							echo 'checked'; }
						?>
						/>
						<label for="requireUniqueSecurityId" style="font-size: 15px;"><?php esc_html_e( 'Require unique security ID', 'mainwp-child' ); ?></label>
					</div>
					<div>
						<?php
						if ( '' != get_option( 'mainwp_child_uniqueId' ) ) {
							echo '<span style="border: 1px dashed #e5e5e5; background: #fafafa; font-size: 24px; padding: 1em 2em;">' . esc_html__( 'Your unique security ID is:', 'mainwp-child' ) . ' <span style="font-weight: bold; color: #7fb100;">' . esc_html( get_option( 'mainwp_child_uniqueId' ) ) . '</span></span>';
						}
						?>
					</div>
					<p class="submit" style="margin-top: 4em;">
						<input type="submit" name="submit" id="submit" class="button button-primary button-hero" value="<?php esc_attr_e( 'Save changes', 'mainwp-child' ); ?>">
					</p>
					<input type="hidden" name="nonce" value="<?php echo wp_create_nonce( 'child-settings' ); ?>">
				</form>
			</div>
		</div>

		<?php
	}

	public function get_max_history() {
		return $this->maxHistory;
	}

	public function parse_init() {

		if ( isset( $_REQUEST['cloneFunc'] ) ) {

			// if not valid result then return.
			$valid_clone = MainWP_Clone_Install::get()->request_clone_funct();
			// not valid clone.
			if ( ! $valid_clone ) {
				return;
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
			include_once ABSPATH . '/wp-admin/includes/misc.php';

			$wp_rewrite->flush_rules();
			MainWP_Helper::update_option( 'mainwp_child_fix_htaccess', 'yes', 'yes' );
		}

		// if login required.
		if ( isset( $_REQUEST['login_required'] ) && ( '1' === $_REQUEST['login_required'] ) && isset( $_REQUEST['user'] ) ) {
			$valid_login_required = MainWP_Connect::instance()->parse_login_required();
			// return parse init if login required are not valid.
			if ( ! $valid_login_required ) {
				return;
			}
		}

		/**
		 * Security
		 */
		MainWP_Security::fix_all();
		MainWP_Debug::process( $this );

		// Register does not require auth, so we register here.
		if ( isset( $_POST['function'] ) && 'register' === $_POST['function'] ) {
			define( 'DOING_CRON', true );
			MainWP_Utility::fix_for_custom_themes();
			MainWP_Connect::instance()->register_site(); // register the site.
		}

		// if auth connect are not valid then exit or return.
		if ( ! MainWP_Connect::instance()->parse_init_auth() ) {
			return;
		}

		$auth = MainWP_Connect::instance()->auth( isset( $_POST['mainwpsignature'] ) ? $_POST['mainwpsignature'] : '', isset( $_POST['function'] ) ? $_POST['function'] : '', isset( $_POST['nonce'] ) ? $_POST['nonce'] : '', isset( $_POST['nossl'] ) ? $_POST['nossl'] : 0 );

		if ( ! $auth && isset( $_POST['mainwpsignature'] ) ) {
			MainWP_Helper::error( __( 'Authentication failed! Please deactivate & re-activate the MainWP Child plugin on this site and try again.', 'mainwp-child' ) );
		}

		if ( ! $auth && isset( $_POST['function'] ) ) {
			$func             = $_POST['function'];
			$callable         = MainWP_Child_Callable::get_instance()->is_callable_function( $func );
			$callable_no_auth = MainWP_Child_Callable::get_instance()->is_callable_function_no_auth( $func );

			if ( $callable && ! $callable_no_auth ) {
				MainWP_Helper::error( __( 'Authentication failed! Please deactivate & re-activate the MainWP Child plugin on this site and try again.', 'mainwp-child' ) );
			}
		}

		if ( $auth ) {
			$auth_user = false;
			// Check if the user exists & is an administrator.
			if ( isset( $_POST['function'] ) && isset( $_POST['user'] ) ) {

				$user = null;
				if ( isset( $_POST['alt_user'] ) && ! empty( $_POST['alt_user'] ) ) {
					if ( MainWP_Connect::instance()->check_login_as( $_POST['alt_user'] ) ) {
						$auth_user = $_POST['alt_user'];
						$user      = get_user_by( 'login', $auth_user );
					}
				}

				// if alternative admin not existed.
				if ( ! $user ) {
					// check connected admin existed.
					$user      = get_user_by( 'login', $_POST['user'] );
					$auth_user = $_POST['user'];
				}

				if ( ! $user ) {
					MainWP_Helper::error( __( 'Unexising administrator username. Please verify that it is an existing administrator.', 'mainwp-child' ) );
				}

				if ( 10 != $user->wp_user_level && ( ! isset( $user->user_level ) || 10 != $user->user_level ) && ! $user->has_cap( 'level_10' ) ) {
					MainWP_Helper::error( __( 'Invalid user. Please verify that the user has administrator privileges.', 'mainwp-child' ) );
				}

				MainWP_Connect::instance()->login( $auth_user );
			}

			if ( isset( $_POST['function'] ) && 'visitPermalink' === $_POST['function'] ) {

				if ( empty( $auth_user ) ) {
					$auth_user = $_POST['user'];
				}

				if ( MainWP_Connect::instance()->login( $auth_user, true ) ) {
					return;
				} else {
					exit();
				}
			}

			// Redirect to the admin side if needed.
			if ( isset( $_POST['admin'] ) && '1' === $_POST['admin'] ) {
				wp_safe_redirect( get_option( 'siteurl' ) . '/wp-admin/' );
				die();
			}
		}

		// Init extensions.
		MainWP_Clone::get()->init();
		MainWP_Child_Server_Information::init();
		MainWP_Client_Report::instance()->init();
		MainWP_Child_Plugins_Check::instance();
		MainWP_Child_Themes_Check::instance();
		MainWP_Utility::instance()->run_saved_snippets();

		global $_wp_submenu_nopriv;
		if ( null === $_wp_submenu_nopriv ) {
			$_wp_submenu_nopriv = array(); // phpcs:ignore -- to fix warning.
		}

		MainWP_Child_Callable::get_instance()->init_call_functions( $auth );

		MainWP_Keyword_Links::instance()->parse_init_keyword_links();
	}

	public function check_login() {
		MainWP_Connect::instance()->check_login();
	}

	private function parse_init_extensions() {
		// Handle fatal errors for those init if needed.
		MainWP_Child_Branding::instance()->branding_init();
		MainWP_Client_Report::instance()->creport_init();
		\MainWP_Child_IThemes_Security::instance()->ithemes_init();
		\MainWP_Child_Updraft_Plus_Backups::instance()->updraftplus_init();
		\MainWP_Child_Back_Up_WordPress::instance()->init();
		\MainWP_Child_WP_Rocket::instance()->init();
		\MainWP_Child_Back_WP_Up::instance()->init();
		\MainWP_Child_Back_Up_Buddy::instance();
		\MainWP_Child_Wordfence::instance()->wordfence_init();
		\MainWP_Child_Timecapsule::instance()->init();
		\MainWP_Child_Staging::instance()->init();
		\MainWP_Child_Pagespeed::instance()->init();
		\MainWP_Child_Links_Checker::instance()->init();
		\MainWP_Child_WPvivid_BackupRestore::instance()->init();
	}


	/*
	 * hook to deactivation child plugin action
	 */
	public function deactivation( $deact = true ) {

		$mu_plugin_enabled = apply_filters( 'mainwp_child_mu_plugin_enabled', false );
		if ( $mu_plugin_enabled ) {
			return;
		}

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

		if ( $deact ) {
			do_action( 'mainwp_child_deactivation' );
		}
	}

	/*
	 * hook to activation child plugin action
	 */
	public function activation() {
		$mu_plugin_enabled = apply_filters( 'mainwp_child_mu_plugin_enabled', false );
		if ( $mu_plugin_enabled ) {
			return;
		}

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

		// delete bad data if existed.
		$to_delete = array( 'mainwp_ext_snippets_enabled', 'mainwp_ext_code_snippets' );
		foreach ( $to_delete as $delete ) {
			delete_option( $delete );
		}
	}

}
