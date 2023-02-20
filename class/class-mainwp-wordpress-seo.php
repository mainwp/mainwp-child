<?php
/**
 * MainWP WordPress SEO
 *
 * MainWP WordPress SEO Extension handler.
 *
 * @link https://mainwp.com/extension/wordpress-seo/
 *
 * @package MainWP\Child
 *
 * Credits
 *
 * Plugin-Name: Yoast SEO
 * Plugin URI: https://yoast.com/wordpress/plugins/seo/#utm_source=wpadmin&utm_medium=plugin&utm_campaign=wpseoplugin
 * Author: Team Yoast
 * Author URI: https://yoast.com/
 * Licence: GPL v3
 */

namespace MainWP\Dashboard;

// phpcs:disable PSR1.Classes.ClassDeclaration, WordPress.WP.AlternativeFunctions -- Required to achieve desired results. Pull requests appreciated.

/**
 * Class MainWP_WordPress_SEO
 *
 * MainWP WordPress SEO Extension handler.
 */
class MainWP_WordPress_SEO {

	/**
	 * Public static variable to hold the single instance of the class.
	 *
	 * @var mixed Default null
	 */
	public static $instance = null;

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
	 * MainWP_WordPress_SEO constructor.
	 *
	 * Run any time class is called.
	 */
	public function __construct() {

		/**
		 * Object, providing access to the WordPress database.
		 *
		 * @global object $wpdb WordPress Database instance.
		 */
		global $wpdb;

		add_action( 'mainwp_child_deactivation', array( $this, 'child_deactivation' ) );
	}

	/**
	 * Empty options upon MainWP Child plugin deactivation.
	 */
	public function child_deactivation() {
		$dell_all = array();
		foreach ( $dell_all as $opt ) {
			delete_option( $opt );
		}
	}

	/**
	 * Fire off certain Yoast SEP plugin actions.
	 *
	 * @uses MainWP_Helper::write() Write response data to be sent to the MainWP Dashboard.
	 * @uses MainWP_WordPress_SEO::import_settings() Import the Yoast SEO plugin settings.
	 */
	public function action() {
		if ( ! class_exists( '\WPSEO_Admin' ) ) {
			$information['error'] = 'NO_WPSEO';
			MainWP_Helper::write( $information );
		}
		$result     = array();
		$mwp_action = ! empty( $_POST['action'] ) ? sanitize_text_field( wp_unslash( $_POST['action'] ) ) : '';
		switch ( $mwp_action ) {
			case 'import_settings':
				$information = $this->import_settings();
				break;
		}
		MainWP_Helper::write( $information );
	}

	/**
	 * Import the Yoast SEO plugin settings.
	 *
	 * @uses MainWP_Helper::write() Write response data to be sent to the MainWP Dashboard.
	 *
	 * @used-by MainWP_WordPress_SEO::action() Fire off certain Yoast SEP plugin actions.
	 *
	 * @throws \Exception Error message.
	 */
	public function import_settings() {
		if ( isset( $_POST['file_url'] ) ) {
			$file_url       = ! empty( $_POST['file_url'] ) ? base64_decode( wp_unslash( $_POST['file_url'] ) ) : ''; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode required for backwards compatibility.
			$temporary_file = '';

			try {
				include_once ABSPATH . 'wp-admin/includes/file.php';
				add_filter( 'http_request_args', array( MainWP_Helper::get_class_name(), 'reject_unsafe_urls' ), 99, 2 );
				$temporary_file = download_url( $file_url );
				remove_filter( 'http_request_args', array( MainWP_Helper::get_class_name(), 'reject_unsafe_urls' ), 99, 2 );
				if ( is_wp_error( $temporary_file ) ) {
					throw new \Exception( 'Error: ' . $temporary_file->get_error_message() );
				} else {
					if ( $this->import_seo_settings( $temporary_file ) ) {
						$information['success'] = true;
					} else {
						throw new \Exception( esc_html__( 'Settings could not be imported.', 'mainwp-child' ) );
					}
				}
			} catch ( \Exception $e ) {
				$information['error'] = $e->getMessage();
			}

			if ( file_exists( $temporary_file ) ) {
				unlink( $temporary_file );
			}
		} elseif ( isset( $_POST['settings'] ) ) {
			try {
				$settings = ! empty( $_POST['settings'] ) ? base64_decode( wp_unslash( $_POST['settings'] ) ) : ''; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode required for backwards compatibility.
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
						$option_instance = \WPSEO_Options::get_option_instance( $name );
						if ( is_object( $option_instance ) && method_exists( $option_instance, 'import' ) ) {
							$optgroup = $option_instance->import( $optgroup, $old_wpseo_version, $options );
						}
					}
					$information['success'] = true;

				} else {
					throw new \Exception( esc_html__( 'Settings could not be imported:', 'mainwp-child' ) );
				}
			} catch ( \Exception $e ) {
				$information['error'] = $e->getMessage();
			}
		}

		MainWP_Helper::write( $information );
	}

	/**
	 * Import SEO settings.
	 *
	 * @param string $file settings.ini file to import.
	 *
	 * @throws \Exception Error message.
	 *
	 * @return bool Return true on success, false on failure.
	 */
	public function import_seo_settings( $file ) {
		if ( ! empty( $file ) ) {
			$upload_dir = wp_upload_dir();

			if ( ! defined( 'DIRECTORY_SEPARATOR' ) ) {

				/**
				 * Defines reusable directory separator.
				 *
				 * @const ( string ) Directory separator.
				 * @source https://code-reference.mainwp.com/classes/MainWP.Dashboard.MainWP_WordPress_SEO.html
				 */
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
							$option_instance = \WPSEO_Options::get_option_instance( $name );
							if ( is_object( $option_instance ) && method_exists( $option_instance, 'import' ) ) {
								$optgroup = $option_instance->import( $optgroup, $old_wpseo_version, $options );
							}
						}

						return true;
					} else {
						throw new \Exception( esc_html__( 'Settings could not be imported:', 'mainwp-child' ) );
					}
					unset( $options, $name, $optgroup );
				} else {
					throw new \Exception( esc_html__( 'Settings could not be imported:', 'mainwp-child' ) );
				}
				unlink( $filename );
				unlink( $p_path );
			} else {
				throw new \Exception( esc_html__( 'Settings could not be imported:', 'mainwp-child' ) . ' ' . sprintf( esc_html__( 'Unzipping failed with error "%s".', 'mainwp-child' ), $unzipped->get_error_message() ) );
			}
			unset( $zip, $unzipped );
			unlink( $file );
		} else {
			throw new \Exception( esc_html__( 'Settings could not be imported:', 'mainwp-child' ) . ' ' . esc_html__( 'Upload failed.', 'mainwp-child' ) );
		}

		return false;
	}

	/**
	 * Parse the column score.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return string SEO Score.
	 */
	public function parse_column_score( $post_id ) {
		if ( '1' === \WPSEO_Meta::get_value( 'meta-robots-noindex', $post_id ) ) {
			$rank  = new \WPSEO_Rank( \WPSEO_Rank::NO_INDEX );
			$title = esc_html__( 'Post is set to noindex.', 'mainwp-child' );
			\WPSEO_Meta::set_value( 'linkdex', 0, $post_id );
		} elseif ( '' === \WPSEO_Meta::get_value( 'focuskw', $post_id ) ) {
			$rank  = new \WPSEO_Rank( \WPSEO_Rank::NO_FOCUS );
			$title = esc_html__( 'Focus keyword not set.', 'mainwp-child' );
		} else {
			$score = (int) \WPSEO_Meta::get_value( 'linkdex', $post_id );
			$rank  = \WPSEO_Rank::from_numeric_score( $score );
			$title = $rank->get_label();
		}

		return $this->render_score_indicator( $rank, $title );
	}

	/**
	 * Parse readability score.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return string Redability score.
	 */
	public function parse_column_score_readability( $post_id ) {
		$score = (int) \WPSEO_Meta::get_value( 'content_score', $post_id );
		$rank  = \WPSEO_Rank::from_numeric_score( $score );

		return $this->render_score_indicator( $rank );
	}

	/**
	 * Render score rank.
	 *
	 * @param string $rank SEO Rank Score.
	 * @param string $title Rank title.
	 *
	 * @return string Return SEO Score html.
	 */
	private function render_score_indicator( $rank, $title = '' ) {
		if ( empty( $title ) ) {
			$title = $rank->get_label();
		}

		return '<div aria-hidden="true" title="' . esc_attr( $title ) . '" class="wpseo-score-icon ' . esc_attr( $rank->get_css_class() ) . '"></div><span class="screen-reader-text">' . $title . '</span>';
	}

}
