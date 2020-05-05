<?php
/**
 * Credits
 *
 * Plugin Name: Yoast SEO
 * Plugin URI: https://yoast.com/wordpress/plugins/seo/#utm_source=wpadmin&utm_medium=plugin&utm_campaign=wpseoplugin
 * Author: Team Yoast
 * Author URI: https://yoast.com/
 * Licence: GPL v3
 *
 * The code is used for the MainWP WordPress SEO Extension
 * Extension URL: https://mainwp.com/extension/wordpress-seo/
 */

namespace MainWP\Child;

class MainWP_Wordpress_SEO {
	public static $instance = null;

	public static function Instance() {
		if ( null === self::$instance ) {
			self::$instance = new MainWP_Wordpress_SEO();
		}

		return self::$instance;
	}

	public function __construct() {
		global $wpdb;
		add_action( 'mainwp_child_deactivation', array( $this, 'child_deactivation' ) );
	}

	public function child_deactivation() {
		$dell_all = array();
		foreach ( $dell_all as $opt ) {
			delete_option( $opt );
		}
	}

	public function action() {
		if ( ! class_exists( 'WPSEO_Admin' ) ) {
			$information['error'] = 'NO_WPSEO';
			MainWP_Helper::write( $information );
		}
		$result = array();
		switch ( $_POST['action'] ) {
			case 'import_settings':
				$information = $this->import_settings();
				break;
		}
		MainWP_Helper::write( $information );
	}

	public function import_settings() {
		if ( isset( $_POST['file_url'] ) ) {
			$file_url       = base64_decode( $_POST['file_url'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for benign reasons.
			$temporary_file = '';
			global $mainWPChild;
			try {
				include_once ABSPATH . 'wp-admin/includes/file.php';
				add_filter( 'http_request_args', array( $mainWPChild, 'http_request_reject_unsafe_urls' ), 99, 2 );
				$temporary_file = download_url( $file_url );
				remove_filter( 'http_request_args', array( $mainWPChild, 'http_request_reject_unsafe_urls' ), 99, 2 );
				if ( is_wp_error( $temporary_file ) ) {
					throw new Exception( 'Error: ' . $temporary_file->get_error_message() );
				} else {
					if ( $this->import_seo_settings( $temporary_file ) ) {
						$information['success'] = true;
					} else {
						throw new Exception( __( 'Settings could not be imported.', 'mainwp-child' ) );
					}
				}
			} catch ( Exception $e ) {
				$information['error'] = $e->getMessage();
			}

			if ( file_exists( $temporary_file ) ) {
				unlink( $temporary_file );
			}
		} elseif ( isset( $_POST['settings'] ) ) {
			try {
				$settings = base64_decode( $_POST['settings'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for benign reasons.
				$options  = parse_ini_string( $settings, true, INI_SCANNER_RAW );
				if ( is_array( $options ) && array() !== $options ) {

					$old_wpseo_version = null;
					if ( isset( $options['wpseo']['version'] ) && '' !== $options['wpseo']['version'] ) {
						$old_wpseo_version = $options['wpseo']['version'];
					}
					foreach ( $options as $name => $optgroup ) {
						if ( 'wpseo_taxonomy_meta' === $name ) {
							$optgroup = json_decode( urldecode( $optgroup['wpseo_taxonomy_meta'] ), true );
						}
						$option_instance = WPSEO_Options::get_option_instance( $name );
						if ( is_object( $option_instance ) && method_exists( $option_instance, 'import' ) ) {
							$optgroup = $option_instance->import( $optgroup, $old_wpseo_version, $options );
						}
					}
					$information['success'] = true;

				} else {
					throw new Exception( __( 'Settings could not be imported:', 'mainwp-child' ) );
				}
			} catch ( Exception $e ) {
				$information['error'] = $e->getMessage();
			}
		}

		MainWP_Helper::write( $information );
	}

	public function import_seo_settings( $file ) {
		if ( ! empty( $file ) ) {
			$upload_dir = wp_upload_dir();

			if ( ! defined( 'DIRECTORY_SEPARATOR' ) ) {
				define( 'DIRECTORY_SEPARATOR', '/' );
			}
			$p_path = $upload_dir['basedir'] . DIRECTORY_SEPARATOR . 'wpseo-import' . DIRECTORY_SEPARATOR;

			if ( ! isset( $GLOBALS['wp_filesystem'] ) || ! is_object( $GLOBALS['wp_filesystem'] ) ) {
				WP_Filesystem();
			}

			$unzipped = unzip_file( $file, $p_path );
			if ( ! is_wp_error( $unzipped ) ) {
				$filename = $p_path . 'settings.ini';
				if ( is_file( $filename ) && is_readable( $filename ) ) {
					$options = parse_ini_file( $filename, true );

					if ( is_array( $options ) && array() !== $options ) {
						$old_wpseo_version = null;
						if ( isset( $options['wpseo']['version'] ) && '' !== $options['wpseo']['version'] ) {
							$old_wpseo_version = $options['wpseo']['version'];
						}
						foreach ( $options as $name => $optgroup ) {
							if ( 'wpseo_taxonomy_meta' === $name ) {
								$optgroup = json_decode( urldecode( $optgroup['wpseo_taxonomy_meta'] ), true );
							}
							$option_instance = WPSEO_Options::get_option_instance( $name );
							if ( is_object( $option_instance ) && method_exists( $option_instance, 'import' ) ) {
								$optgroup = $option_instance->import( $optgroup, $old_wpseo_version, $options );
							}
						}

						return true;
					} else {
						throw new Exception( __( 'Settings could not be imported:', 'mainwp-child' ) );
					}
					unset( $options, $name, $optgroup );
				} else {
					throw new Exception( __( 'Settings could not be imported:', 'mainwp-child' ) );
				}
				unlink( $filename );
				unlink( $p_path );
			} else {
				throw new Exception( __( 'Settings could not be imported:', 'mainwp-child' ) . ' ' . sprintf( __( 'Unzipping failed with error "%s".', 'mainwp-child' ), $unzipped->get_error_message() ) );
			}
			unset( $zip, $unzipped );
			unlink( $file );
		} else {
			throw new Exception( __( 'Settings could not be imported:', 'mainwp-child' ) . ' ' . __( 'Upload failed.', 'mainwp-child' ) );
		}

		return false;
	}

	public function parse_column_score( $post_id ) {
		if ( '1' === WPSEO_Meta::get_value( 'meta-robots-noindex', $post_id ) ) {
			$rank  = new WPSEO_Rank( WPSEO_Rank::NO_INDEX );
			$title = __( 'Post is set to noindex.', 'mainwp-child' );
			WPSEO_Meta::set_value( 'linkdex', 0, $post_id );
		} elseif ( '' === WPSEO_Meta::get_value( 'focuskw', $post_id ) ) {
			$rank  = new WPSEO_Rank( WPSEO_Rank::NO_FOCUS );
			$title = __( 'Focus keyword not set.', 'mainwp-child' );
		} else {
			$score = (int) WPSEO_Meta::get_value( 'linkdex', $post_id );
			$rank  = WPSEO_Rank::from_numeric_score( $score );
			$title = $rank->get_label();
		}

		return $this->render_score_indicator( $rank, $title );
	}

	public function parse_column_score_readability( $post_id ) {
		$score = (int) WPSEO_Meta::get_value( 'content_score', $post_id );
		$rank  = WPSEO_Rank::from_numeric_score( $score );

		return $this->render_score_indicator( $rank );
	}

	private function render_score_indicator( $rank, $title = '' ) {
		if ( empty( $title ) ) {
			$title = $rank->get_label();
		}

		return '<div aria-hidden="true" title="' . esc_attr( $title ) . '" class="wpseo-score-icon ' . esc_attr( $rank->get_css_class() ) . '"></div><span class="screen-reader-text">' . $title . '</span>';
	}

}
