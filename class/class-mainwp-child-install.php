<?php
/**
 * MainWP Child Install
 *
 * This file handles Plugins and Themes Activate, Deactivate and Delete process.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

/**
 * Class MainWP_Child_Install
 *
 * Handles Plugins and Themes Activate, Deactivate and Delete process.
 */
class MainWP_Child_Install {

	/**
	 * Public static variable to hold the single instance of MainWP_Child_Install.
	 *
	 * @var mixed Default null
	 */
	protected static $instance = null;

	/**
	 * Get class name.
	 *
	 * @return string __CLASS__ Class name.
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * MainWP_Child_Install constructor
	 *
	 * Run any time class is called.
	 */
	public function __construct() {
	}

	/**
	 * Create a public static instance of MainWP_Child_Install.
	 *
	 * @return MainWP_Child_Install|mixed|null
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Method plugin_action()
	 *
	 * Plugin Activate, Deactivate & Delete actions.
	 *
	 * @uses get_plugin_data() Parses the plugin contents to retrieve plugin’s metadata.
	 * @see https://developer.wordpress.org/reference/functions/get_plugin_data/
	 *
	 * @uses activate_plugin() Attempts activation of plugin in a “sandbox” and redirects on success.
	 * @see https://developer.wordpress.org/reference/functions/activate_plugin/
	 *
	 * @uses deactivate_plugin() Deactivate a single plugin or multiple plugins.
	 * @see https://developer.wordpress.org/reference/functions/deactivate_plugin/
	 *
	 * @uses \MainWP\Child\MainWP_Child_Install::delete_plugins() Delete a plugin from the Child Site.
	 * @uses \MainWP\Child\MainWP_Child_Stats::get_site_stats()
	 * @uses \MainWP\Child\MainWP_Helper::write()
	 */
	public function plugin_action() {

		/**
		 * MainWP Child instance.
		 *
		 * @global object
		 */
		global $mainWPChild;

		$action  = ! empty( $_POST['action'] ) ? sanitize_text_field( wp_unslash( $_POST['action'] ) ) : '';
		$plugins = isset( $_POST['plugin'] ) ? explode( '||', wp_unslash( $_POST['plugin'] ) ) : '';

		if ( 'activate' === $action ) {
			include_once ABSPATH . '/wp-admin/includes/plugin.php';

			foreach ( $plugins as $idx => $plugin ) {
				if ( $plugin !== $mainWPChild->plugin_slug ) {
					$thePlugin = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin );
					if ( null !== $thePlugin && '' !== $thePlugin ) {
						if ( 'quotes-collection/quotes-collection.php' == $plugin ) {
							activate_plugin( $plugin, '', false, true );
						} else {
							activate_plugin( $plugin );
						}
					}
				}
			}
		} elseif ( 'deactivate' === $action ) {
			include_once ABSPATH . '/wp-admin/includes/plugin.php';

			foreach ( $plugins as $idx => $plugin ) {
				if ( $plugin !== $mainWPChild->plugin_slug ) {
					$thePlugin = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin );
					if ( null !== $thePlugin && '' !== $thePlugin ) {
						deactivate_plugins( $plugin );
					}
				}
			}
		} elseif ( 'delete' === $action ) {
			$this->delete_plugins( $plugins );
		} else {
			$information['status'] = 'FAIL';
		}

		if ( ! isset( $information['status'] ) ) {
			$information['status'] = 'SUCCESS';
		}
		$information['sync'] = MainWP_Child_Stats::get_instance()->get_site_stats( array(), false );
		MainWP_Helper::write( $information );
	}

	/**
	 * Method delete_plugins()
	 *
	 * Delete a plugin from the Child Site.
	 *
	 * @param array $plugins An array of plugins to delete.
	 *
	 * @uses get_plugin_data() Parses the plugin contents to retrieve plugin’s metadata.
	 * @see https://developer.wordpress.org/reference/functions/get_plugin_data/
	 *
	 * @uses deactivate_plugin() Deactivate a single plugin or multiple plugins.
	 * @see https://developer.wordpress.org/reference/functions/deactivate_plugin/
	 *
	 * @uses is_plugin_active() Determines whether a plugin is active.
	 * @see https://developer.wordpress.org/reference/functions/is_plugin_active/
	 *
	 * @uses \MainWP\Child\MainWP_Helper::check_wp_filesystem()
	 *
	 * @used-by \MainWP\Child\MainWP_Child_Install::plugin_action() Plugin Activate, Deactivate & Delete actions.
	 */
	private function delete_plugins( $plugins ) {

		/**
		 * MainWP Child instance.
		 *
		 * @global object
		 */
		global $mainWPChild;

		include_once ABSPATH . '/wp-admin/includes/plugin.php';
		if ( file_exists( ABSPATH . '/wp-admin/includes/screen.php' ) ) {
			include_once ABSPATH . '/wp-admin/includes/screen.php';
		}
		include_once ABSPATH . '/wp-admin/includes/file.php';
		include_once ABSPATH . '/wp-admin/includes/template.php';
		include_once ABSPATH . '/wp-admin/includes/misc.php';
		include_once ABSPATH . '/wp-admin/includes/class-wp-upgrader.php';
		include_once ABSPATH . '/wp-admin/includes/class-wp-filesystem-base.php';
		include_once ABSPATH . '/wp-admin/includes/class-wp-filesystem-direct.php';

		MainWP_Helper::check_wp_filesystem();

		$pluginUpgrader = new \Plugin_Upgrader();

		$all_plugins = get_plugins();
		foreach ( $plugins as $idx => $plugin ) {
			if ( $plugin !== $mainWPChild->plugin_slug ) {
				if ( isset( $all_plugins[ $plugin ] ) ) {
					if ( is_plugin_active( $plugin ) ) {
						$thePlugin = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin );
						if ( null !== $thePlugin && '' !== $thePlugin ) {
								deactivate_plugins( $plugin );
						}
					}
					$tmp['plugin'] = $plugin;
					if ( true === $pluginUpgrader->delete_old_plugin( null, null, null, $tmp ) ) {
						$args = array(
							'action' => 'delete',
							'Name'   => $all_plugins[ $plugin ]['Name'],
						);
						do_action( 'mainwp_child_plugin_action', $args );
					}
				}
			}
		}
	}

	/**
	 * Method theme_action()
	 *
	 * Theme Activate, Deactivate & Delete actions.
	 *
	 * @uses wp_get_theme() Gets a WP_Theme object for a theme.
	 * @see https://developer.wordpress.org/reference/functions/wp_get_theme/
	 *
	 * @uses switch_theme() Switches the theme.
	 * @see https://developer.wordpress.org/reference/functions/switch_theme/
	 *
	 * @uses \MainWP\Child\MainWP_Child_Stats::get_site_stats()
	 * @uses \MainWP\Child\MainWP_Helper::check_wp_filesystem()
	 * @uses \MainWP\Child\MainWP_Helper::write()
	 */
	public function theme_action() { // phpcs:ignore -- Current complexity is the only way to achieve desired results, pull request solutions appreciated.

		$action = ! empty( $_POST['action'] ) ? sanitize_text_field( wp_unslash( $_POST['action'] ) ) : '';
		$theme  = isset( $_POST['theme'] ) ? wp_unslash( $_POST['theme'] ) : '';

		if ( 'activate' === $action ) {
			include_once ABSPATH . '/wp-admin/includes/theme.php';
			$theTheme = wp_get_theme( $theme );
			if ( null !== $theTheme && '' !== $theTheme ) {
				switch_theme( $theTheme['Template'], $theTheme['Stylesheet'] );
			}
		} elseif ( 'delete' === $action ) {
			include_once ABSPATH . '/wp-admin/includes/theme.php';
			if ( file_exists( ABSPATH . '/wp-admin/includes/screen.php' ) ) {
				include_once ABSPATH . '/wp-admin/includes/screen.php';
			}
			include_once ABSPATH . '/wp-admin/includes/file.php';
			include_once ABSPATH . '/wp-admin/includes/template.php';
			include_once ABSPATH . '/wp-admin/includes/misc.php';
			include_once ABSPATH . '/wp-admin/includes/class-wp-upgrader.php';
			include_once ABSPATH . '/wp-admin/includes/class-wp-filesystem-base.php';
			include_once ABSPATH . '/wp-admin/includes/class-wp-filesystem-direct.php';

			MainWP_Helper::check_wp_filesystem();

			$themeUpgrader = new \Theme_Upgrader();

			$theme_name  = wp_get_theme()->get_stylesheet();
			$parent      = wp_get_theme()->parent();
			$parent_name = $parent ? $parent->get_stylesheet() : '';

			$themes = explode( '||', $theme );

			$themes = array_filter( $themes );

			if ( count( $themes ) == 1 ) {
				$themeToDelete = current( $themes );
				if ( $themeToDelete == $theme_name ) {
					$information['error']['is_activated_theme'] = $themeToDelete;
					MainWP_Helper::write( $information );
					return;
				} elseif ( $themeToDelete == $parent_name ) {
					$information['error']['is_activated_parent'] = $themeToDelete;
					MainWP_Helper::write( $information );
					return;
				}
			}

			foreach ( $themes as $idx => $themeToDelete ) {
				if ( $themeToDelete == $theme_name ) {
					$information['error']['is_activated_theme'] = $themeToDelete;
				} elseif ( $themeToDelete == $parent_name ) {
					$information['error']['is_activated_parent'] = $themeToDelete;
				}
				if ( $themeToDelete !== $theme_name && $themeToDelete !== $parent_name ) {
					$theTheme = wp_get_theme( $themeToDelete );
					if ( null !== $theTheme && '' !== $theTheme ) {
						$tmp['theme'] = $theTheme->stylesheet; // to fix delete parent theme issue.
						if ( true === $themeUpgrader->delete_old_theme( null, null, null, $tmp ) ) {
							$args = array(
								'action' => 'delete',
								'Name'   => $theTheme['Name'],
							);
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

		$information['sync'] = MainWP_Child_Stats::get_instance()->get_site_stats( array(), false );
		MainWP_Helper::write( $information );
	}

	/**
	 * Method install_plugin_theme()
	 *
	 * Plugin & Theme Installation functions.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Install::require_files() Include necessary files.
	 * @uses \MainWP\Child\MainWP_Child_Install::after_installed() After plugin or theme has been installed.
	 * @uses \MainWP\Child\MainWP_Child_Install::no_ssl_filter_function() Hook to set ssl verify value.
	 * @uses \MainWP\Child\MainWP_Child_Install::try_second_install() Alternative installation method.
	 * @uses \MainWP\Child\MainWP_Helper::check_wp_filesystem()
	 * @uses \MainWP\Child\MainWP_Helper::instance()->error()
	 * @uses \MainWP\Child\MainWP_Helper::get_class_name()
	 * @uses \MainWP\Child\MainWP_Helper::write()
	 */
	public function install_plugin_theme() {

		MainWP_Helper::check_wp_filesystem();

		if ( ! isset( $_POST['type'] ) || ! isset( $_POST['url'] ) || ( 'plugin' !== $_POST['type'] && 'theme' !== $_POST['type'] ) || '' === $_POST['url'] ) {
			MainWP_Helper::instance()->error( esc_html__( 'Plugin or theme not specified, or missing required data. Please reload the page and try again.', 'mainwp-child' ) );
		}

		$this->require_files();

		$urlgot = isset( $_POST['url'] ) ? json_decode( stripslashes( wp_unslash( $_POST['url'] ) ) ) : '';

		$urls = array();
		if ( ! is_array( $urlgot ) ) {
			$urls[] = $urlgot;
		} else {
			$urls = $urlgot;
		}

		$install_results = array();
		$result          = array();
		foreach ( $urls as $url ) {
			$installer  = new \WP_Upgrader();
			$ssl_verify = true;
			// @see wp-admin/includes/class-wp-upgrader.php
			if ( isset( $_POST['sslVerify'] ) && '0' === $_POST['sslVerify'] ) {
				add_filter( 'http_request_args', array( self::get_class_name(), 'no_ssl_filter_function' ), 99, 2 );
				$ssl_verify = false;
			}
			add_filter( 'http_request_args', array( MainWP_Helper::get_class_name(), 'reject_unsafe_urls' ), 99, 2 );

			$result = $installer->run(
				array(
					'package'           => $url,
					'destination'       => ( isset( $_POST['type'] ) && 'plugin' === $_POST['type'] ? WP_PLUGIN_DIR : WP_CONTENT_DIR . '/themes' ),
					'clear_destination' => ( isset( $_POST['overwrite'] ) && sanitize_text_field( wp_unslash( $_POST['overwrite'] ) ) ),
					'clear_working'     => true,
					'hook_extra'        => array(),
				)
			);

			if ( is_wp_error( $result ) ) {
				if ( true == $ssl_verify && strpos( $url, 'https://' ) === 0 ) {
					$ssl_verify = false;
					$result     = $this->try_second_install( $url, $installer );
				}
			}

			remove_filter( 'http_request_args', array( MainWP_Helper::get_class_name(), 'reject_unsafe_urls' ), 99, 2 );
			if ( false == $ssl_verify ) {
				remove_filter( 'http_request_args', array( self::get_class_name(), 'no_ssl_filter_function' ), 99 );
			}
			$this->after_installed( $result );
			$basename                     = basename( rawurldecode( $url ) );
			$install_results[ $basename ] = is_array( $result ) && isset( $result['destination_name'] ) ? true : false;
		}

		$information['installation']     = 'SUCCESS';
		$information['destination_name'] = $result['destination_name'];
		$information['install_results']  = $install_results;
		MainWP_Helper::write( $information );
	}

	/**
	 * Method no_ssl_filter_function()
	 *
	 * Hook to set ssl verify value.
	 *
	 * @param array  $r Request's array values.
	 * @param string $url URL request.
	 *
	 * @used-by install_plugin_theme() Plugin & Theme Installation functions.
	 *
	 * @return array $r Request's array values.
	 */
	public static function no_ssl_filter_function( $r, $url ) {
		$r['sslverify'] = false;
		return $r;
	}

	/**
	 * Method require_files()
	 *
	 * Include necessary files.
	 *
	 * @used-by install_plugin_theme() Plugin & Theme Installation functions.
	 */
	private function require_files() {
		if ( file_exists( ABSPATH . '/wp-admin/includes/screen.php' ) ) {
			include_once ABSPATH . '/wp-admin/includes/screen.php';
		}
		include_once ABSPATH . '/wp-admin/includes/template.php';
		include_once ABSPATH . '/wp-admin/includes/misc.php';
		include_once ABSPATH . '/wp-admin/includes/class-wp-upgrader.php';
		include_once ABSPATH . '/wp-admin/includes/plugin.php';
	}

	/**
	 * Method after_installed()
	 *
	 * After plugin or theme has been installed.
	 *
	 * @param array $result Results array from self::install_plugin_theme().
	 *
	 * @uses wp_cache_set() Saves the data to the cache.
	 * @see https://developer.wordpress.org/reference/functions/wp_cache_set/
	 *
	 * @uses activate_plugin() Attempts activation of plugin in a “sandbox” and redirects on success.
	 * @see https://developer.wordpress.org/reference/functions/activate_plugin/
	 *
	 * @uses get_plugin_data() Parses the plugin contents to retrieve plugin’s metadata.
	 * @see https://developer.wordpress.org/reference/functions/get_plugin_data/
	 *
	 * @used-by install_plugin_theme() Plugin & Theme Installation functions.
	 */
	private function after_installed( $result ) {
		$args = array(
			'success' => 1,
			'action'  => 'install',
		);
		if ( isset( $_POST['type'] ) && 'plugin' === $_POST['type'] ) {
			$path     = $result['destination'];
			$fileName = '';
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

	/**
	 * Method try_second_install()
	 *
	 * Alternative installation method.
	 *
	 * @return object $result Return error messages or TRUE.
	 *
	 * @param string $url       Package URL.
	 * @param object $installer Instance of \WP_Upgrader.
	 *
	 * @uses is_wp_error() Check whether variable is a WordPress Error.
	 * @see https://developer.wordpress.org/reference/functions/is_wp_error/
	 *
	 * @uses \MainWP\Child\MainWP_Helper::instance()->error()
	 *
	 * @used-by install_plugin_theme() Plugin & Theme Installation functions.
	 */
	private function try_second_install( $url, $installer ) {
		$result = $installer->run(
			array(
				'package'           => $url,
				'destination'       => ( isset( $_POST['type'] ) && 'plugin' === $_POST['type'] ? WP_PLUGIN_DIR : WP_CONTENT_DIR . '/themes' ),
				'clear_destination' => ( isset( $_POST['overwrite'] ) && sanitize_text_field( wp_unslash( $_POST['overwrite'] ) ) ),
				'clear_working'     => true,
				'hook_extra'        => array(),
			)
		);
		if ( is_wp_error( $result ) ) {
			$err_code = $result->get_error_code();
			if ( $result->get_error_data() && is_string( $result->get_error_data() ) ) {
				$error = $result->get_error_data();
				MainWP_Helper::instance()->error( $error, $err_code );
			} else {
				MainWP_Helper::instance()->error( implode( ', ', $error ), $err_code );
			}
		}
		return $result;
	}
}

