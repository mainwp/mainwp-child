<?php

namespace MainWP\Child;

class MainWP_Child_Install {
	
	protected static $instance = null;
	
	
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
			
	}
	
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
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

}
