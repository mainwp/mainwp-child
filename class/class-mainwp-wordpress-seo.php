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

namespace MainWP\Child;

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
	 * Public variable to hold the import settings error message.
	 *
	 * @var mixed Default null
	 */
	public $import_error = '';

	/**
	 * Method instance()
	 *
	 * Create a public static instance.
	 *
	 * @return mixed Class instance.
	 */
	public static function instance() {
		if ( null === static::$instance ) {
			static::$instance = new self();
		}

		return static::$instance;
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
		$this->import_error = __( 'Settings could not be imported.', 'mainwp-child' );
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
		$information = array();
		$mwp_action  = MainWP_System::instance()->validate_params( 'mwp_action' );
		if ( ! empty( $mwp_action ) ) {
			try {
				switch ( $mwp_action ) {
					case 'import_settings':
						$information = $this->import_settings();
						break;
					case 'save_settings':
						$information = $this->save_settings();
						break;
					default:
						break;
				}
			} catch ( MainWP_Exception $e ) {
				$information = array( 'error' => $e->getMessage() );
			}
		}
		MainWP_Helper::write( $information );
	}

	/**
	 * Method save_settings()
	 *
	 * Save the plugin settings.
	 *
	 * @uses \WPSEO_Options::get()
	 * @uses \WPSEO_Options::set()
	 * @uses \WPSEO_Options::get_options()
	 * @uses \WPSEO_Utils::clear_cache()
	 *
	 * @return array Action result.
	 */
	public function save_settings() {  // phpcs:ignore -- NOSONAR 
        // phpcs:disable WordPress.Security.NonceVerification.Missing
		$options = isset( $_POST['settings'] ) ? json_decode( base64_decode( wp_unslash( $_POST['settings'] ) ), true ) : '';  //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
		if ( ! is_array( $options ) || empty( $options ) ) {
			return array( 'error' => 'INVALID_OPTIONS' );
		}
		// Save blogdescriptionb.
		if ( isset( $options['wpseo_titles']['blogdescription'] ) ) {
			if ( ! empty( $options['wpseo_titles']['blogdescription'] ) ) {
				update_option( 'blogdescription', $options['wpseo_titles']['blogdescription'] );
			}
			unset( $options['wpseo_titles']['blogdescription'] );
		}

		// List of keys that require special handling.
		$special_keys = array(
			'other_social_urls',
			'custom_taxonomy_slugs',
			'import_cursors',
			'workouts_data',
			'importing_completed',
			'wincher_tokens',
			'least_readability_ignore_list',
			'least_seo_score_ignore_list',
			'most_linked_ignore_list',
			'least_linked_ignore_list',
			'indexables_page_reading_list',
			'last_known_public_post_types',
			'last_known_public_taxonomies',
			'last_known_no_unindexed',
			'new_post_types',
			'new_taxonomies',
		);

		// Save wpSeo config value.
		foreach ( $options as $k_option => $option ) {
			$option_values = \WPSEO_Options::get_options( array( $k_option ) );
			if ( empty( $option_values ) ) {
				continue; // Ignore if not valid.
			}

			foreach ( $option as $k_item => $item ) {
				if ( ! isset( $option_values[ $k_item ] ) ) {
					continue; // Ignore if this key is not present in the option value.
				}
				$value = $item;
				if ( in_array( $k_item, $special_keys, true ) ) {
					$value = ! empty( $item ) ? array_values( array_unique( array_filter( $item ) ) ) : array();
				}
				if ( 'og_default_image' === $k_item ) {
					$image = $this->wpseo_upload_image( $value );
					if ( ! empty( $image ) ) {
						$value = $image['id'];
						// Save option.
						\WPSEO_Options::set( 'og_default_image', $image['url'], $k_option );
						\WPSEO_Options::set( 'og_default_image_id', $image['id'], $k_option );
					}
				} else {
					\WPSEO_Options::set( $k_item, $value, $k_option ); // Save option.
				}
			}
		}
		// Clear cache so the changes are obvious.
		\WPSEO_Utils::clear_cache();
		return array( 'result' => 'SUCCESS' );
        // phpcs:disable WordPress.Security.NonceVerification.Missing
	}
	/**
	 * Import the Yoast SEO plugin settings.
	 *
	 * @uses MainWP_Helper::write() Write response data to be sent to the MainWP Dashboard.
	 *
	 * @used-by MainWP_WordPress_SEO::action() Fire off certain Yoast SEP plugin actions.
	 *
	 * @throws MainWP_Exception Error message.
	 */
    public function import_settings() { //phpcs:ignore -- NOSONAR - complex.
        // phpcs:disable WordPress.Security.NonceVerification
		if ( isset( $_POST['file_url'] ) ) {
			$file_url       = ! empty( $_POST['file_url'] ) ? sanitize_text_field( base64_decode( wp_unslash( $_POST['file_url'] ) ) ) : ''; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- base64_encode required for backwards compatibility.
			$temporary_file = '';

			try {
				include_once ABSPATH . 'wp-admin/includes/file.php'; // NOSONAR -- WP compatible.
				add_filter( 'http_request_args', array( MainWP_Helper::get_class_name(), 'reject_unsafe_urls' ), 99, 2 );
				$temporary_file = download_url( $file_url );
				remove_filter( 'http_request_args', array( MainWP_Helper::get_class_name(), 'reject_unsafe_urls' ), 99, 2 );
				if ( is_wp_error( $temporary_file ) ) {
					throw new MainWP_Exception( 'Error: ' . $temporary_file->get_error_message() );
				} elseif ( $this->import_seo_settings( $temporary_file ) ) {
						$information['success'] = true;
				} else {
					throw new MainWP_Exception( esc_html( $this->import_error ) );
				}
			} catch ( MainWP_Exception $e ) {
				$information['error'] = $e->getMessage();
			}

			if ( file_exists( $temporary_file ) ) {
				wp_delete_file( $temporary_file );
			}
		} elseif ( isset( $_POST['settings'] ) ) {
			try {
				$settings = ! empty( $_POST['settings'] ) ? base64_decode( wp_unslash( $_POST['settings'] ) ) : ''; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- base64_encode required for backwards compatibility.
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
					$information['error'] = 'INVALID_OPTIONS';
					throw new MainWP_Exception( esc_html( $this->import_error ) );
				}
			} catch ( MainWP_Exception $e ) {
				$information['error'] = $e->getMessage();
			}
		}
        // phpcs:enable
		MainWP_Helper::write( $information );
	}

	/**
	 * Import SEO settings.
	 *
	 * @param string $file settings.ini file to import.
	 *
	 * @throws MainWP_Exception Error message.
	 *
	 * @return bool Return true on success, false on failure.
	 */
    public function import_seo_settings( $file ) { //phpcs:ignore -- NOSONAR - complex.
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

			$error_import = true;

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
						$error_import = false;
					}
					wp_delete_file( $filename );
					wp_delete_file( $p_path );
				}
			}

			wp_delete_file( $file );
			unset( $unzipped );

			if ( $error_import ) {
				throw new MainWP_Exception( esc_html( $this->import_error ) );
			} else {
				return true;
			}
		} else {
			throw new MainWP_Exception( esc_html( $this->import_error ) . ' ' . esc_html__( 'Upload failed.', 'mainwp-child' ) );
		}
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

	/**
	 * Method wpseo_upload_image()
	 *
	 * Upload custom image from MainWP Dashboard.
	 *
	 * @param string $img_url Contains image URL.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::get_class_name()
	 * @uses \MainWP_Utility::instance()->check_image_file_name()
	 * @uses \MainWP_Helper::move()
	 *
	 * @return array An array containing the image information such as path and URL.
	 * @throws MainWP_Exception Error message.
	 */
	public function wpseo_upload_image( $img_url ) {
		include_once ABSPATH . 'wp-admin/includes/file.php'; // NOSONAR -- WP compatible.
		include_once ABSPATH . 'wp-admin/includes/image.php'; // NOSONAR -- To process metadata for images.

		// Check if the image from the URL has been downloaded.
		$attachment_id = $this->get_attachment_id_by_source_url( $img_url );
		if ( $attachment_id ) {
			// If exists, returns file information.
			return array(
				'id'       => $attachment_id,
				'path'     => get_attached_file( $attachment_id ),
				'url'      => wp_get_attachment_url( $attachment_id ),
				'metadata' => wp_get_attachment_metadata( $attachment_id ),
			);
		}

		add_filter( 'http_request_args', array( MainWP_Helper::get_class_name(), 'reject_unsafe_urls' ), 99, 2 );
		$temporary_file = download_url( $img_url );
		remove_filter( 'http_request_args', array( MainWP_Helper::get_class_name(), 'reject_unsafe_urls' ), 99, 2 );

		if ( is_wp_error( $temporary_file ) ) {
			throw new MainWP_Exception( esc_html( $temporary_file->get_error_message() ) );
		} else {
			$upload_dir     = wp_upload_dir();
			$local_img_path = $upload_dir['path'] . DIRECTORY_SEPARATOR . basename( $img_url );
			$local_img_path = dirname( $local_img_path ) . '/' . wp_unique_filename( dirname( $local_img_path ), basename( $local_img_path ) );
			$local_img_url  = $upload_dir['url'] . '/' . basename( $local_img_path );

			if ( MainWP_Utility::instance()->check_image_file_name( $local_img_path ) ) {
				$moved = MainWP_Helper::move( $temporary_file, $local_img_path );
				if ( $moved ) {
					// Create attachment posts in the database.
					$attachment = array(
						'guid'           => $local_img_url,
						'post_mime_type' => mime_content_type( $local_img_path ),
						'post_title'     => preg_replace( '/\.[^.]+$/', '', basename( $local_img_path ) ),
						'post_content'   => '',
						'post_status'    => 'inherit',
						'meta_input'     => array(
							'_source_url' => $img_url, // Save the original URL as metadata.
						),
					);

					// Attach attachment to the database.
					$attachment_id = wp_insert_attachment( $attachment, $local_img_path );

					// Create metadata for attachments.
					if ( ! is_wp_error( $attachment_id ) ) {
						$attachment_metadata = wp_generate_attachment_metadata( $attachment_id, $local_img_path );
						wp_update_attachment_metadata( $attachment_id, $attachment_metadata );

						return array(
							'id'       => $attachment_id,
							'path'     => $local_img_path,
							'url'      => $local_img_url,
							'metadata' => $attachment_metadata,
						);
					}
				}
			}
		}
		if ( file_exists( $temporary_file ) ) {
			wp_delete_file( $temporary_file );
		}

		return null;
	}

	/**
	 * Method get_attachment_id_by_source_url()
	 * Get attachment ID from source URL.
	 *
	 * @param string $source_url Original URL of the image.
	 * @return int|null ID of the attachment or null if it does not exist.
	 */
	private function get_attachment_id_by_source_url( $source_url ) {
		global $wpdb;

		// Query attachment posts based on '_source_url' metadata.
		$attachment_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_source_url' AND meta_value = %s",
				$source_url
			)
		);

		return $attachment_id ? (int) $attachment_id : null;
	}
}
