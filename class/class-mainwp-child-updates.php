<?php

namespace MainWP\Child;

class MainWP_Child_Updates {

	protected static $instance = null;

	private $filterFunction = null;


	/**
	 * Method get_class_name()
	 *
	 * Get Class Name.
	 *
	 * @return object
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	public function __construct() {
			$this->filterFunction = function( $a ) {
				if ( null == $a ) {
					return false; }
				if ( is_object( $a ) && property_exists( $a, 'last_checked' ) && ! property_exists( $a, 'checked' ) ) {
					return false;
				}
				return $a;
			};
	}

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function upgrade_plugin_theme() {
		// Prevent disable/re-enable at upgrade.
		if ( ! defined( 'DOING_CRON' ) ) {
			define( 'DOING_CRON', true );
		}

		MainWP_Helper::get_wp_filesystem();

		include_once ABSPATH . '/wp-admin/includes/class-wp-upgrader.php';

		if ( file_exists( ABSPATH . '/wp-admin/includes/screen.php' ) ) {
			include_once ABSPATH . '/wp-admin/includes/screen.php';
		}
		if ( file_exists( ABSPATH . '/wp-admin/includes/template.php' ) ) {
			include_once ABSPATH . '/wp-admin/includes/template.php';
		}
		if ( file_exists( ABSPATH . '/wp-admin/includes/misc.php' ) ) {
			include_once ABSPATH . '/wp-admin/includes/misc.php';
		}
		include_once ABSPATH . '/wp-admin/includes/file.php';
		include_once ABSPATH . '/wp-admin/includes/plugin.php';
		include_once ABSPATH . '/wp-admin/includes/plugin-install.php';

		$information                    = array();
		$information['upgrades']        = array();
		$mwp_premium_updates_todo       = array();
		$mwp_premium_updates_todo_slugs = array();

		if ( isset( $_POST['type'] ) && 'plugin' === $_POST['type'] ) {
			$this->upgrade_plugin( $information, $mwp_premium_updates_todo, $mwp_premium_updates_todo_slugs );
		} elseif ( isset( $_POST['type'] ) && 'theme' === $_POST['type'] ) {
			$this->upgrade_theme( $information, $mwp_premium_updates_todo, $mwp_premium_updates_todo_slugs );
		} else {
			MainWP_Helper::error( __( 'Invalid request!', 'mainwp-child' ) );
		}

		if ( count( $mwp_premium_updates_todo ) > 0 ) {
			// Upgrade via WP.
			// @see wp-admin/update.php.
			$result = $premiumUpgrader->bulk_upgrade( $mwp_premium_updates_todo_slugs );
			if ( ! empty( $result ) ) {
				foreach ( $result as $plugin => $info ) {
					if ( ! empty( $info ) ) {
						$information['upgrades'][ $plugin ] = true;

						foreach ( $mwp_premium_updates_todo as $key => $update ) {
							$slug = ( isset( $update['slug'] ) ? $update['slug'] : $update['Name'] );
						}
					}
				}
			}

			// Upgrade via callback.
			foreach ( $mwp_premium_updates_todo as $update ) {
				$slug = ( isset( $update['slug'] ) ? $update['slug'] : $update['Name'] );

				if ( isset( $update['url'] ) ) {
					$installer                        = new WP_Upgrader();
					$result                           = $installer->run(
						array(
							'package'           => $update['url'],
							'destination'       => ( 'plugin' === $update['type'] ? WP_PLUGIN_DIR : WP_CONTENT_DIR . '/themes' ),
							'clear_destination' => true,
							'clear_working'     => true,
							'hook_extra'        => array(),
						)
					);
					$information['upgrades'][ $slug ] = ( ! is_wp_error( $result ) && ! empty( $result ) );
				} elseif ( isset( $update['callback'] ) ) {
					if ( is_array( $update['callback'] ) && isset( $update['callback'][0] ) && isset( $update['callback'][1] ) ) {
						$update_result                    = call_user_func(
							array(
								$update['callback'][0],
								$update['callback'][1],
							)
						);
						$information['upgrades'][ $slug ] = $update_result && true;
					} elseif ( is_string( $update['callback'] ) ) {
						$update_result                    = call_user_func( $update['callback'] );
						$information['upgrades'][ $slug ] = $update_result && true;
					} else {
						$information['upgrades'][ $slug ] = false;
					}
				} else {
					$information['upgrades'][ $slug ] = false;
				}
			}
		}

		$information['sync'] = MainWP_Child_Stats::get_instance()->get_site_stats( array(), false );
		mainwp_child_helper()->write( $information );
	}

	private function upgrade_plugin( &$information, &$mwp_premium_updates_todo, &$mwp_premium_updates_todo_slugs ) {

			include_once ABSPATH . '/wp-admin/includes/update.php';
		if ( null !== $this->filterFunction ) {
			add_filter( 'pre_site_transient_update_plugins', $this->filterFunction, 99 );
		}

			$plugins = explode( ',', urldecode( $_POST['list'] ) );

		if ( in_array( 'backupbuddy/backupbuddy.php', $plugins ) ) {
			if ( isset( $GLOBALS['ithemes_updater_path'] ) ) {
				if ( ! class_exists( 'Ithemes_Updater_Settings' ) ) {
					require $GLOBALS['ithemes_updater_path'] . '/settings.php';
				}
				if ( class_exists( 'Ithemes_Updater_Settings' ) ) {
					$ithemes_updater = new Ithemes_Updater_Settings();
					$ithemes_updater->update();
				}
			}
		}

			// to fix: smart-manager-for-wp-e-commerce update.
		if ( in_array( 'smart-manager-for-wp-e-commerce/smart-manager.php', $plugins ) ) {
			if ( file_exists( plugin_dir_path( __FILE__ ) . '../../smart-manager-for-wp-e-commerce/pro/upgrade.php' ) && file_exists( plugin_dir_path( __FILE__ ) . '../../smart-manager-for-wp-e-commerce/smart-manager.php' ) ) {
				include_once plugin_dir_path( __FILE__ ) . '../../smart-manager-for-wp-e-commerce/smart-manager.php';
				include_once plugin_dir_path( __FILE__ ) . '../../smart-manager-for-wp-e-commerce/pro/upgrade.php';
			}
		}

			global $wp_current_filter;
			$wp_current_filter[] = 'load-plugins.php'; // phpcs:ignore -- to custom plugin installation.
			wp_update_plugins();

			// trick to prevent some premium plugins re-create update info.
			remove_all_filters( 'pre_set_site_transient_update_plugins' );

			// support cached premium plugins update info, hooking in the bulk_upgrade().
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
			$failed = true;
			// to fix update of Yithemes premiums plugins that hooked to upgrader_pre_download.
			$url   = 'update.php?action=update-selected&amp;plugins=' . rawurlencode( implode( ',', $plugins ) );
			$nonce = 'bulk-update-plugins';

			$upgrader = new Plugin_Upgrader( new Bulk_Plugin_Upgrader_Skin( compact( 'nonce', 'url' ) ) );
			$result   = $upgrader->bulk_upgrade( $plugins );

			if ( ! empty( $result ) ) {
				foreach ( $result as $plugin => $info ) {
					if ( empty( $info ) ) {

						$information['upgrades'][ $plugin ] = false;
						// try to fix if that is premiums update.
						$api = apply_filters( 'plugins_api', false, 'plugin_information', array( 'slug' => $plugin ) );

						if ( ! is_wp_error( $api ) && ! empty( $api ) ) {
							if ( isset( $api->download_link ) ) {
								$res = $upgrader->install( $api->download_link );
								if ( ! is_wp_error( $res ) && ! ( is_null( $res ) ) ) {
									$information['upgrades'][ $plugin ] = true;
								}
							}
						}
					} else {
						$information['upgrades'][ $plugin ] = true;
					}
				}
				$failed = false;
			}

			if ( $failed ) {
				MainWP_Helper::error( __( 'Invalid request!', 'mainwp-child' ) );
			}
		}

			remove_filter( 'pre_site_transient_update_plugins', array( $this, 'set_cached_update_plugins' ), 10 );
			delete_site_transient( 'mainwp_update_plugins_cached' ); // fix cached update info.

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
	}

	private function upgrade_theme( &$information, &$mwp_premium_updates_todo, &$mwp_premium_updates_todo_slugs ) {

			$last_update = get_site_transient( 'update_themes' );

			include_once ABSPATH . '/wp-admin/includes/update.php';
		if ( null !== $this->filterFunction ) {
			add_filter( 'pre_site_transient_update_themes', $this->filterFunction, 99 );
		}

			wp_update_themes();
			include_once ABSPATH . '/wp-admin/includes/theme.php';

			// to support cached premium themes update info, hooking in the bulk_upgrade().
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
			$addFilterToFixUpdate_optimizePressTheme = false;
			if ( in_array( 'optimizePressTheme', $themes ) ) {
				$addFilterToFixUpdate_optimizePressTheme = true;
				add_filter( 'site_transient_update_themes', array( $this, 'hook_fix_optimize_press_theme_update' ), 99 );
			}

			if ( null !== $this->filterFunction ) {
				remove_filter( 'pre_site_transient_update_plugins', $this->filterFunction, 99 );
			}

			$last_update2 = get_site_transient( 'update_themes' );
			set_site_transient( 'update_themes', $last_update );

			$failed   = true;
			$upgrader = new Theme_Upgrader( new Bulk_Theme_Upgrader_Skin( compact( 'nonce', 'url' ) ) );
			$result   = $upgrader->bulk_upgrade( $themes );
			if ( ! empty( $result ) ) {
				foreach ( $result as $theme => $info ) {
					if ( empty( $info ) ) {
						$information['upgrades'][ $theme ] = false;
					} else {
						$information['upgrades'][ $theme ] = true;
					}
				}
				$failed = false;
			}

			if ( $failed ) {
				MainWP_Helper::error( __( 'Invalid request!', 'mainwp-child' ) );
			}

			if ( null !== $this->filterFunction ) {
				add_filter( 'pre_site_transient_update_themes', $this->filterFunction, 99 );
			}

			set_site_transient( 'update_themes', $last_update2 );

			if ( $addFilterToFixUpdate_optimizePressTheme ) {
				remove_filter(
					'site_transient_update_themes',
					array(
						$this,
						'hook_fix_optimize_press_theme_update',
					),
					99
				);
			}
		}

			remove_filter( 'pre_site_transient_update_themes', array( $this, 'set_cached_update_themes' ), 10 );
			delete_site_transient( 'mainwp_update_themes_cached' ); // fix cached update info.

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
	}

	public function upgrade_get_theme_updates() {
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

	public function hook_fix_optimize_press_theme_update( $transient ) {
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


	public function set_cached_update_plugins( $false = false, $_transient_data = null ) {

		if ( ! is_object( $_transient_data ) ) {
			$_transient_data = new stdClass();
		}

		$pre                = false;
		$cached_update_info = get_site_transient( 'mainwp_update_plugins_cached' );
		if ( is_array( $cached_update_info ) && count( $cached_update_info ) > 0 ) {
			foreach ( $cached_update_info as $slug => $info ) {
				if ( ! isset( $_transient_data->response[ $slug ] ) && isset( $info->update ) ) {
					$_transient_data->response[ $slug ] = $info->update;
					$pre                                = true;
				}
			}
		}

		if ( false == $pre ) {
			return $false;
		}

		return $_transient_data;
	}


	public function set_cached_update_themes( $false = false, $_transient_data = null ) {

		if ( ! is_object( $_transient_data ) ) {
			$_transient_data = new stdClass();
		}

		$pre                = false;
		$cached_update_info = get_site_transient( 'mainwp_update_themes_cached' );
		if ( is_array( $cached_update_info ) && count( $cached_update_info ) > 0 ) {
			foreach ( $cached_update_info as $slug => $info ) {
				if ( ! isset( $_transient_data->response[ $slug ] ) && isset( $info->update ) ) {
					$_transient_data->response[ $slug ] = $info->update;
					$pre                                = true;
				}
			}
		}

		if ( false == $pre ) {
			return $false;
		}

		return $_transient_data;
	}

	public function detect_premium_themesplugins_updates() {

		if ( isset( $_GET['_detect_plugins_updates'] ) && 'yes' == $_GET['_detect_plugins_updates'] ) {
			// to fix some premium plugins update notification.
			$current = get_site_transient( 'update_plugins' );
			set_site_transient( 'update_plugins', $current );

			add_filter( 'pre_site_transient_update_plugins', $this->filterFunction, 99 );
			$plugins = get_plugin_updates();
			remove_filter( 'pre_site_transient_update_plugins', $this->filterFunction, 99 );

			set_site_transient( 'mainwp_update_plugins_cached', $plugins, DAY_IN_SECONDS );
		}

		if ( isset( $_GET['_detect_themes_updates'] ) && 'yes' == $_GET['_detect_themes_updates'] ) {
			add_filter( 'pre_site_transient_update_themes', $this->filterFunction, 99 );
			$themes = get_theme_updates();
			remove_filter( 'pre_site_transient_update_themes', $this->filterFunction, 99 );

			set_site_transient( 'mainwp_update_themes_cached', $themes, DAY_IN_SECONDS );
		}

		$type = isset( $_GET['_request_update_premiums_type'] ) ? $_GET['_request_update_premiums_type'] : '';

		if ( 'plugin' == $type || 'theme' == $type ) {
			$list = isset( $_GET['list'] ) ? $_GET['list'] : '';

			if ( ! empty( $list ) ) {
				$_POST['type'] = $type;
				$_POST['list'] = $list;

				$function = 'upgradeplugintheme'; // to call function upgrade_plugin_theme().
				if ( MainWP_Child_Callable::get_instance()->is_callable_function( $function ) ) {
					MainWP_Child_Callable::get_instance()->call_function( $function );
				}
			}
		}
	}


	/**
	 * Functions to support core functionality
	 */
	public function install_plugin_theme() {

		MainWP_Helper::check_wp_filesystem();

		if ( ! isset( $_POST['type'] ) || ! isset( $_POST['url'] ) || ( 'plugin' !== $_POST['type'] && 'theme' !== $_POST['type'] ) || '' === $_POST['url'] ) {
			MainWP_Helper::error( __( 'Invalid request!', 'mainwp-child' ) );
		}
		if ( file_exists( ABSPATH . '/wp-admin/includes/screen.php' ) ) {
			include_once ABSPATH . '/wp-admin/includes/screen.php';
		}
		include_once ABSPATH . '/wp-admin/includes/template.php';
		include_once ABSPATH . '/wp-admin/includes/misc.php';
		include_once ABSPATH . '/wp-admin/includes/class-wp-upgrader.php';
		include_once ABSPATH . '/wp-admin/includes/plugin.php';

		$urlgot = json_decode( stripslashes( $_POST['url'] ) );

		$urls = array();
		if ( ! is_array( $urlgot ) ) {
			$urls[] = $urlgot;
		} else {
			$urls = $urlgot;
		}

		$result = array();
		foreach ( $urls as $url ) {
			$installer  = new WP_Upgrader();
			$ssl_verify = true;
			// @see wp-admin/includes/class-wp-upgrader.php
			if ( isset( $_POST['sslVerify'] ) && '0' === $_POST['sslVerify'] ) {
				add_filter( 'http_request_args', array( MainWP_Helper::get_class_name(), 'no_ssl_filter_function' ), 99, 2 );
				$ssl_verify = false;
			}
			add_filter( 'http_request_args', array( MainWP_Helper::get_class_name(), 'reject_unsafe_urls' ), 99, 2 );

			$result = $installer->run(
				array(
					'package'           => $url,
					'destination'       => ( 'plugin' === $_POST['type'] ? WP_PLUGIN_DIR : WP_CONTENT_DIR . '/themes' ),
					'clear_destination' => ( isset( $_POST['overwrite'] ) && $_POST['overwrite'] ),
					'clear_working'     => true,
					'hook_extra'        => array(),
				)
			);

			if ( is_wp_error( $result ) ) {
				if ( true == $ssl_verify && strpos( $url, 'https://' ) === 0 ) {
					add_filter( 'http_request_args', array( MainWP_Helper::get_class_name(), 'no_ssl_filter_function' ), 99, 2 );
					$ssl_verify = false;
					$result     = $installer->run(
						array(
							'package'           => $url,
							'destination'       => ( 'plugin' === $_POST['type'] ? WP_PLUGIN_DIR : WP_CONTENT_DIR . '/themes' ),
							'clear_destination' => ( isset( $_POST['overwrite'] ) && $_POST['overwrite'] ),
							'clear_working'     => true,
							'hook_extra'        => array(),
						)
					);
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

			remove_filter( 'http_request_args', array( MainWP_Helper::get_class_name(), 'reject_unsafe_urls' ), 99, 2 );
			if ( false == $ssl_verify ) {
				remove_filter( 'http_request_args', array( MainWP_Helper::get_class_name(), 'no_ssl_filter_function' ), 99 );
			}

			$args = array(
				'success' => 1,
				'action'  => 'install',
			);
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
					do_action_deprecated( 'mainwp_child_installPluginTheme', array( $args ), '4.0.7.1', 'mainwp_child_install_plugin_theme' );
					do_action( 'mainwp_child_install_plugin_theme', $args );

					if ( isset( $_POST['activatePlugin'] ) && 'yes' === $_POST['activatePlugin'] ) {
						// to fix activate issue.
						if ( 'quotes-collection/quotes-collection.php' == $args['slug'] ) {
							activate_plugin( $path . $fileName, '', false, true );
						} else {
							activate_plugin( $path . $fileName, '' );
						}
					}
				}
			} else {
				$args['type'] = 'theme';
				$args['slug'] = $result['destination_name'];
				do_action_deprecated( 'mainwp_child_installPluginTheme', array( $args ), '4.0.7.1', 'mainwp_child_install_plugin_theme' );
				do_action( 'mainwp_child_install_plugin_theme', $args );
			}
		}
		$information['installation']     = 'SUCCESS';
		$information['destination_name'] = $result['destination_name'];
		mainwp_child_helper()->write( $information );
	}



	// This will upgrade WP!
	public function upgrade_wp() {
		global $wp_version;
		MainWP_Helper::get_wp_filesystem();

		$information = array();

		include_once ABSPATH . '/wp-admin/includes/update.php';
		include_once ABSPATH . '/wp-admin/includes/class-wp-upgrader.php';

		if ( file_exists( ABSPATH . '/wp-admin/includes/screen.php' ) ) {
			include_once ABSPATH . '/wp-admin/includes/screen.php';
		}
		if ( file_exists( ABSPATH . '/wp-admin/includes/template.php' ) ) {
			include_once ABSPATH . '/wp-admin/includes/template.php';
		}
		include_once ABSPATH . '/wp-admin/includes/file.php';
		include_once ABSPATH . '/wp-admin/includes/misc.php';

		if ( null !== $this->filterFunction ) {
			add_filter( 'pre_site_transient_update_core', $this->filterFunction, 99 );
		}
		if ( null !== $this->filterFunction ) {
			add_filter( 'pre_transient_update_core', $this->filterFunction, 99 );
		}

		// Check for new versions.
		wp_version_check();

		$core_updates = get_core_updates();
		if ( is_array( $core_updates ) && count( $core_updates ) > 0 ) {
			foreach ( $core_updates as $core_update ) {
				if ( 'latest' === $core_update->response ) {
					$information['upgrade'] = 'SUCCESS';
				} elseif ( 'upgrade' === $core_update->response && get_locale() === $core_update->locale && version_compare( $wp_version, $core_update->current, '<=' ) ) {
					// Upgrade!
					$upgrade = false;
					if ( class_exists( 'Core_Upgrader' ) ) {
						$core    = new Core_Upgrader();
						$upgrade = $core->upgrade( $core_update );
					}
					// If this does not work - add code from /wp-admin/includes/class-wp-upgrader.php in the newer versions.
					// So users can upgrade older versions too.
					// 3rd option: 'wp_update_core'.

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
						// Upgrade!
						$upgrade = false;
						if ( class_exists( 'Core_Upgrader' ) ) {
							$core    = new Core_Upgrader();
							$upgrade = $core->upgrade( $core_update );
						}
						// If this does not work - add code from /wp-admin/includes/class-wp-upgrader.php in the newer versions
						// So users can upgrade older versions too.
						// 3rd option: 'wp_update_core'.
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

		mainwp_child_helper()->write( $information );
	}

	public function upgrade_translation() {
		// Prevent disable/re-enable at upgrade.
		define( 'DOING_CRON', true );

		MainWP_Helper::get_wp_filesystem();
		include_once ABSPATH . '/wp-admin/includes/class-wp-upgrader.php';
		if ( file_exists( ABSPATH . '/wp-admin/includes/screen.php' ) ) {
			include_once ABSPATH . '/wp-admin/includes/screen.php';
		}
		if ( file_exists( ABSPATH . '/wp-admin/includes/template.php' ) ) {
			include_once ABSPATH . '/wp-admin/includes/template.php';
		}
		if ( file_exists( ABSPATH . '/wp-admin/includes/misc.php' ) ) {
			include_once ABSPATH . '/wp-admin/includes/misc.php';
		}
		include_once ABSPATH . '/wp-admin/includes/file.php';

		include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		wp_version_check();
		wp_update_themes();
		wp_update_plugins();

		$upgrader             = new Language_Pack_Upgrader( new Language_Pack_Upgrader_Skin( compact( 'url', 'nonce', 'title', 'context' ) ) );
		$translations         = explode( ',', urldecode( $_POST['list'] ) );
		$all_language_updates = wp_get_translation_updates();

		$language_updates = array();
		foreach ( $all_language_updates as $current_language_update ) {
			if ( in_array( $current_language_update->slug, $translations ) ) {
				$language_updates[] = $current_language_update;
			}
		}

		$result = count( $language_updates ) == 0 ? false : $upgrader->bulk_upgrade( $language_updates );
		if ( ! empty( $result ) ) {
			$count_result = count( $result );
			for ( $i = 0; $i < $count_result; $i++ ) {
				if ( empty( $result[ $i ] ) || is_wp_error( $result[ $i ] ) ) {
					$information['upgrades'][ $language_updates[ $i ]->slug ] = false;
				} else {
					$information['upgrades'][ $language_updates[ $i ]->slug ] = true;
				}
			}
		} else {
			$information['upgrades'] = array(); // to fix error message when translations updated.
		}
		$information['sync'] = MainWP_Child_Stats::get_instance()->get_site_stats( array(), false );
		mainwp_child_helper()->write( $information );
	}

}
