<?php
/**
 * MainWP Branding
 *
 * MainWP Branding extension handler.
 * Extension URL: https://mainwp.com/extension/branding/
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

// phpcs:disable Generic.Metrics.CyclomaticComplexity -- to custom read/write files, complex functions/features.

/**
 * Class MainWP_Child_Branding
 *
 * MainWP Branding extension handler.
 */
class MainWP_Child_Branding {

	/**
	 * Public static variable to hold the single instance of the class.
	 *
	 * @var mixed Default null
	 */
	public static $instance = null;

	/**
	 * Public variable to hold the MainWP Child plugin directory information.
	 *
	 * @var string Default null
	 */
	public $child_plugin_dir;

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
	 * MainWP_Child_Branding constructor.
	 *
	 * Run any time class is called.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->child_plugin_dir = dirname( dirname( __FILE__ ) );
		add_action( 'mainwp_child_deactivation', array( $this, 'child_deactivation' ) );
		add_filter( 'mainwp_child_plugin_row_meta', array( $this, 'plugin_row_meta' ), 10, 3 );
		$this->child_branding_options = $this->init_options();
	}

	/**
	 * Method init_options()
	 *
	 * Initiate the branding extension options.
	 *
	 * @return array Array containing branding options.
	 */
	public function init_options() {

		$opts = get_option( 'mainwp_child_branding_settings' );

		if ( ! is_array( $opts ) ) {
			$opts = array();
		}

		if ( ! isset( $opts['contact_label'] ) || empty( $opts['contact_label'] ) ) {
			$opts['contact_label'] = esc_html__( 'Contact Support', 'mainwp-child' );
		}

		$disconnected       = isset( $opts['branding_disconnected'] ) ? $opts['branding_disconnected'] : '';
		$preserve_branding  = isset( $opts['preserve_branding'] ) ? $opts['preserve_branding'] : '';
		$cancelled_branding = ( 'yes' === $disconnected ) && ! $preserve_branding;

		$opts['cancelled_branding']      = $cancelled_branding;
		$opts['branding_preserve_title'] = '';

		if ( ! $cancelled_branding ) {
			if ( isset( $opts['branding_header'] ) ) {
				$branding_header = $opts['branding_header'];
				if ( is_array( $branding_header ) && isset( $branding_header['name'] ) && ! empty( $branding_header['name'] ) ) {
					$opts['branding_preserve_title'] = stripslashes( $branding_header['name'] );
				}
			}
		}

		/**
		 * Filter 'mainwp_child_branding_init_options'
		 *
		 * Set custom branding setting through the filter.
		 *
		 * @since 4.0
		 */
		$opts = apply_filters( 'mainwp_child_branding_init_options', $opts );
		return $opts;
	}

	/**
	 * Method get_extra_options()
	 *
	 * Get extra branding settings.
	 *
	 * @return array Array containing the extra branding settings.
	 */
	public function get_extra_options() {
		$extra = array();
		if ( is_array( $this->child_branding_options ) && isset( $this->child_branding_options['extra_settings'] ) ) {
			$extra = $this->child_branding_options['extra_settings'];
			if ( ! is_array( $extra ) ) {
				$extra = array();
			}
		}

		return $extra;
	}

	/**
	 * Method plugin_row_meta()
	 *
	 * Handle plugin meta information when custom branding is applied.
	 *
	 * @param array  $plugin_meta       An array of the plugin's metadata, including the version, author, author URI, and plugin URI.
	 * @param string $plugin_file       Path to the plugin file relative to the plugins directory.
	 * @param string $child_plugin_slug MainWP Child plugin slug.
	 *
	 * @return array An array of the plugin's metadata, including the version, author, author URI, and plugin URI.
	 */
	public function plugin_row_meta( $plugin_meta, $plugin_file, $child_plugin_slug ) {
		if ( $child_plugin_slug !== $plugin_file ) {
			return $plugin_meta;
		}

		if ( ! $this->is_branding() ) {
			return $plugin_meta;
		}
		// hide View details links!
		$meta_total = count( $plugin_meta );
		for ( $i = 0; $i < $meta_total; $i++ ) {
			$str_meta = $plugin_meta[ $i ];
			if ( strpos( $str_meta, 'plugin-install.php?tab=plugin-information' ) ) {
				unset( $plugin_meta[ $i ] );
				break;
			}
		}

		return $plugin_meta;
	}

	/**
	 * Method child_deactivation()
	 *
	 * Empty custom branding options upon MainWP Child plugin deactivation.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::update_option()
	 */
	public function child_deactivation() {
		$brandingOptions_empty = array(
			'hide',
			'disable_change',
			'disable_switching_theme',
			'show_support',
			'support_email',
			'support_message',
			'remove_restore',
			'remove_setting',
			'remove_server_info',
			'remove_wp_tools',
			'remove_wp_setting',
			'remove_permalink',
			'contact_label',
			'email_message',
			'message_return_sender',
			'submit_button_title',
			'extra_settings',
			'branding_ext_enabled',
		);

		foreach ( $brandingOptions_empty as $opt ) {
			if ( isset( $this->child_branding_options[ $opt ] ) ) {
				$this->child_branding_options[ $opt ] = '';
			}
		}
		MainWP_Helper::update_option( 'mainwp_child_branding_settings', $this->child_branding_options );
	}

	/**
	 * Method action()
	 *
	 * Fire off certain branding actions.
	 *
	 * @uses MainWP_Child_Branding::update_branding() Update custom branding settings.
	 * @uses \MainWP\Child\MainWP_Helper::write()
	 */
	public function action() {
		$information = array();
		$mwp_action  = ! empty( $_POST['action'] ) ? sanitize_text_field( wp_unslash( $_POST['action'] ) ) : '';
		switch ( $mwp_action ) {
			case 'update_branding':
				$information = $this->update_branding();
				break;
		}
		MainWP_Helper::write( $information );
	}

	/**
	 * Method update_branding()
	 *
	 * Update custom branding settings.
	 *
	 * @return array Action result.
	 *
	 * @used-by \MainWP\Child\MainWP_Child_Branding::action() Fire off certain Google Pagespeed Insights plugin actions.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::update_option()
	 */
	public function update_branding() {
		$information = array();
		$settings    = isset( $_POST['settings'] ) ? json_decode( base64_decode( wp_unslash( $_POST['settings'] ) ), true ) : ''; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Required for bacwards compatibility.
		if ( ! is_array( $settings ) ) {
			return $information;
		}

		$current_settings      = $this->child_branding_options;
		$current_extra_setting = $this->child_branding_options['extra_settings'];

		$current_settings['branding_ext_enabled'] = 'Y';

		$header                              = array(
			'name'        => $settings['child_plugin_name'],
			'description' => $settings['child_plugin_desc'],
			'author'      => $settings['child_plugin_author'],
			'authoruri'   => $settings['child_plugin_author_uri'],
			'pluginuri'   => isset( $settings['child_plugin_uri'] ) ? $settings['child_plugin_uri'] : '',
		);
		$current_settings['branding_header'] = $header;

		$current_settings['preserve_branding']        = $settings['child_preserve_branding'];
		$current_settings['support_email']            = $settings['child_support_email'];
		$current_settings['support_message']          = $settings['child_support_message'];
		$current_settings['remove_restore']           = $settings['child_remove_restore'];
		$current_settings['remove_setting']           = $settings['child_remove_setting'];
		$current_settings['remove_server_info']       = $settings['child_remove_server_info'];
		$current_settings['remove_connection_detail'] = isset( $settings['child_remove_connection_detail'] ) ? $settings['child_remove_connection_detail'] : 0;
		$current_settings['remove_wp_tools']          = $settings['child_remove_wp_tools'];
		$current_settings['remove_wp_setting']        = $settings['child_remove_wp_setting'];
		$current_settings['remove_permalink']         = $settings['child_remove_permalink'];
		$current_settings['contact_label']            = $settings['child_button_contact_label'];
		$current_settings['email_message']            = $settings['child_send_email_message'];
		$current_settings['message_return_sender']    = $settings['child_message_return_sender'];
		$current_settings['submit_button_title']      = $settings['child_submit_button_title'];
		$current_settings['hide']                     = $settings['child_plugin_hide'] ? 'T' : '';
		$current_settings['show_support']             = ( $settings['child_show_support_button'] && ! empty( $settings['child_support_email'] ) ) ? 'T' : '';
		$current_settings['disable_change']           = $settings['child_disable_change'] ? 'T' : '';
		$current_settings['disable_switching_theme']  = $settings['child_disable_switching_theme'] ? 'T' : '';
		if ( isset( $settings['child_disable_wp_branding'] ) && ( 'Y' === $settings['child_disable_wp_branding'] || 'N' === $settings['child_disable_wp_branding'] ) ) {
			$current_settings['disable_wp_branding'] = $settings['child_disable_wp_branding'];
		}
		$current_settings['extra_settings'] = $this->get_extra_settings( $current_extra_setting, $settings, $information );
		MainWP_Helper::update_option( 'mainwp_child_branding_settings', $current_settings );

		$information['result'] = 'SUCCESS';

		return $information;
	}

	/**
	 * Method get_extra_settings()
	 *
	 * Get extra branding settings.
	 *
	 * @param array $current_extra_setting An array containing the current extra branding settings.
	 * @param array $settings              An array containing the branding settings.
	 * @param array $information           An array containing the synchronization information.
	 *
	 * @used-by MainWP_Child_Branding::update_branding() Update custom branding settings.
	 *
	 * @return array An array of branding extra settings
	 */
	public function get_extra_settings( $current_extra_setting, $settings, &$information ) {

		$extra_setting = array(
			'show_button_in'                  => $settings['child_show_support_button_in'],
			'global_footer'                   => $settings['child_global_footer'],
			'dashboard_footer'                => $settings['child_dashboard_footer'],
			'remove_widget_welcome'           => $settings['child_remove_widget_welcome'],
			'remove_widget_glance'            => $settings['child_remove_widget_glance'],
			'remove_widget_activity'          => $settings['child_remove_widget_activity'],
			'remove_widget_quick'             => $settings['child_remove_widget_quick'],
			'remove_widget_news'              => $settings['child_remove_widget_news'],
			'login_image_link'                => $settings['child_login_image_link'],
			'login_image_title'               => $settings['child_login_image_title'],
			'site_generator'                  => $settings['child_site_generator'],
			'generator_link'                  => $settings['child_generator_link'],
			'admin_css'                       => $settings['child_admin_css'],
			'login_css'                       => $settings['child_login_css'],
			'texts_replace'                   => $settings['child_texts_replace'],
			'hide_nag'                        => $settings['child_hide_nag'],
			'hide_screen_opts'                => $settings['child_hide_screen_opts'],
			'hide_help_box'                   => $settings['child_hide_help_box'],
			'hide_metabox_post_excerpt'       => $settings['child_hide_metabox_post_excerpt'],
			'hide_metabox_post_slug'          => $settings['child_hide_metabox_post_slug'],
			'hide_metabox_post_tags'          => $settings['child_hide_metabox_post_tags'],
			'hide_metabox_post_author'        => $settings['child_hide_metabox_post_author'],
			'hide_metabox_post_comments'      => $settings['child_hide_metabox_post_comments'],
			'hide_metabox_post_revisions'     => $settings['child_hide_metabox_post_revisions'],
			'hide_metabox_post_discussion'    => $settings['child_hide_metabox_post_discussion'],
			'hide_metabox_post_categories'    => $settings['child_hide_metabox_post_categories'],
			'hide_metabox_post_custom_fields' => $settings['child_hide_metabox_post_custom_fields'],
			'hide_metabox_post_trackbacks'    => $settings['child_hide_metabox_post_trackbacks'],
			'hide_metabox_page_custom_fields' => $settings['child_hide_metabox_page_custom_fields'],
			'hide_metabox_page_author'        => $settings['child_hide_metabox_page_author'],
			'hide_metabox_page_discussion'    => $settings['child_hide_metabox_page_discussion'],
			'hide_metabox_page_revisions'     => $settings['child_hide_metabox_page_revisions'],
			'hide_metabox_page_attributes'    => $settings['child_hide_metabox_page_attributes'],
			'hide_metabox_page_slug'          => $settings['child_hide_metabox_page_slug'],
		);

		if ( isset( $settings['child_login_image_url'] ) ) {
			if ( empty( $settings['child_login_image_url'] ) ) {
				$extra_setting['login_image'] = array();
			} else {
				try {
					$upload = $this->branding_upload_image( $settings['child_login_image_url'] );
					if ( null !== $upload ) {
						$extra_setting['login_image'] = array(
							'path' => $upload['path'],
							'url'  => $upload['url'],
						);
						if ( isset( $current_extra_setting['login_image']['path'] ) ) {
							$old_file = $current_extra_setting['login_image']['path'];
							if ( ! empty( $old_file ) && file_exists( $old_file ) ) {
								unlink( $old_file );
							}
						}
					}
				} catch ( \Exception $e ) {
					$information['error']['login_image'] = $e->getMessage();
				}
			}
		} elseif ( isset( $current_extra_setting['login_image'] ) ) {
			$extra_setting['login_image'] = $current_extra_setting['login_image'];
		}

		if ( isset( $settings['child_favico_image_url'] ) ) {
			if ( empty( $settings['child_favico_image_url'] ) ) {
				$extra_setting['favico_image'] = array();
			} else {
				try {
					$upload = $this->branding_upload_image( $settings['child_favico_image_url'] );
					if ( null !== $upload ) {
						$extra_setting['favico_image'] = array(
							'path' => $upload['path'],
							'url'  => $upload['url'],
						);
						if ( isset( $current_extra_setting['favico_image']['path'] ) ) {
							$old_file = $current_extra_setting['favico_image']['path'];
							if ( ! empty( $old_file ) && file_exists( $old_file ) ) {
								unlink( $old_file );
							}
						}
					}
				} catch ( \Exception $e ) {
					$information['error']['favico_image'] = $e->getMessage();
				}
			}
		} elseif ( isset( $current_extra_setting['favico_image'] ) ) {
			$extra_setting['favico_image'] = $current_extra_setting['favico_image'];
		}
		return $extra_setting;
	}

	/**
	 * Method branding_upload_image()
	 *
	 * Upload custom image from MainWP Dashboard.
	 *
	 * @param string $img_url Contains image URL.
	 *
	 * @return array An array containing the image information such as path and URL.
	 * @throws \Exception Error message.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::get_class_name()
	 */
	public function branding_upload_image( $img_url ) {
		include_once ABSPATH . 'wp-admin/includes/file.php';

		add_filter( 'http_request_args', array( MainWP_Helper::get_class_name(), 'reject_unsafe_urls' ), 99, 2 );
		$temporary_file = download_url( $img_url );
		remove_filter( 'http_request_args', array( MainWP_Helper::get_class_name(), 'reject_unsafe_urls' ), 99, 2 );

		if ( is_wp_error( $temporary_file ) ) {
			throw new \Exception( $temporary_file->get_error_message() );
		} else {
			$upload_dir     = wp_upload_dir();
			$local_img_path = $upload_dir['path'] . DIRECTORY_SEPARATOR . basename( $img_url );
			$local_img_path = dirname( $local_img_path ) . '/' . wp_unique_filename( dirname( $local_img_path ), basename( $local_img_path ) );
			$local_img_url  = $upload_dir['url'] . '/' . basename( $local_img_path );

			if ( MainWP_Utility::instance()->check_image_file_name( $local_img_path ) ) {
				$moved = rename( $temporary_file, $local_img_path );
				if ( $moved ) {
					return array(
						'path' => $local_img_path,
						'url'  => $local_img_url,
					);
				}
			}
		}
		if ( file_exists( $temporary_file ) ) {
			unlink( $temporary_file );
		}

		return null;
	}

	/**
	 * Method branding_init()
	 *
	 * Initiate custom branding features.
	 *
	 * @return void
	 *
	 * @uses \MainWP\Child\MainWP_Child_Branding_Render::instance()
	 * @uses \MainWP\Child\MainWP_Child_Branding_Render::get_class_name()
	 */
	public function branding_init() {

		$extra_setting = $this->get_extra_options();

		if ( is_admin() ) {
			add_action( 'in_admin_footer', array( MainWP_Child_Branding_Render::instance(), 'in_admin_footer' ) );
		} elseif ( is_user_logged_in() ) {
			add_action( 'wp_after_admin_bar_render', array( MainWP_Child_Branding_Render::instance(), 'after_admin_bar_render' ) );
		}

		$opts = $this->child_branding_options;

		$cancelled_branding = $opts['cancelled_branding'];

		if ( $cancelled_branding ) {
			return;
		}

		// enable branding in case child plugin deactive and re-activated.
		add_filter( 'all_plugins', array( $this, 'modify_plugin_header' ) );

		if ( $this->is_branding() ) {
			add_filter( 'site_transient_update_plugins', array( &$this, 'remove_update_nag' ) );
			add_filter( 'mainwp_child_hide_update_notice', array( &$this, 'hide_update_notice' ) );
		}

		if ( ! isset( $opts['branding_ext_enabled'] ) || 'Y' !== $opts['branding_ext_enabled'] ) {
			return;
		}

		add_filter( 'map_meta_cap', array( $this, 'branding_map_meta_cap' ), 10, 5 );

		if ( 'T' === $opts['disable_change'] && ! isset( $_POST['mainwpsignature'] ) ) {

			// Disable the WordPress plugin update notifications.
			remove_action( 'load-update-core.php', 'wp_update_plugins' );
			add_filter( 'pre_site_transient_update_plugins', '__return_null' );

			// Disable the WordPress theme update notifications.
			remove_action( 'load-update-core.php', 'wp_update_themes' );
			add_filter(
				'pre_site_transient_update_themes',
				function( $a ) {
					return null;
				}
			);

			/**
			 * Disable the WordPress core update notifications.
			 *
			 * @uses MainWP_Child_Branding_Render::get_class_name()
			 */
			function remove_core_updates() {
				add_action(
					'init',
					function( $a ) {
							remove_action( 'wp_version_check', 'wp_version_check' );
					},
					2
				);
				add_filter( 'pre_option_update_core', '__return_null' );
				add_filter( 'pre_site_transient_update_core', '__return_null' );
			} add_action( 'after_setup_theme', 'remove_core_updates' );

			add_action( 'admin_head', array( MainWP_Child_Branding_Render::get_class_name(), 'admin_head_hide_elements' ), 15 );
			add_action( 'admin_menu', array( $this, 'branding_redirect' ), 9 );
		}

		add_action( 'admin_menu', array( &$this, 'admin_menu' ) );

		if ( ! isset( $opts['disable_wp_branding'] ) || 'Y' !== $opts['disable_wp_branding'] ) {
			add_filter( 'wp_footer', array( &$this, 'branding_global_footer' ), 15 );
			add_action( 'wp_dashboard_setup', array( &$this, 'custom_dashboard_widgets' ), 999 );
			// branding site generator.
			$types = array( 'html', 'xhtml', 'atom', 'rss2', 'rdf', 'comment', 'export' );
			foreach ( $types as $type ) {
				add_filter( 'get_the_generator_' . $type, array( &$this, 'custom_generator' ), 999, 2 );
			}
			add_action( 'admin_head', array( &$this, 'custom_admin_css' ) );
			add_action( 'login_enqueue_scripts', array( &$this, 'custom_login_css' ) );
			add_filter( 'gettext', array( &$this, 'custom_gettext' ), 99, 3 );
			add_action( 'login_head', array( &$this, 'custom_login_logo' ) );
			add_filter( 'login_headerurl', array( &$this, 'custom_login_headerurl' ) );
			add_filter( 'login_headertext', array( &$this, 'custom_login_headertitle' ) );
			add_action( 'wp_head', array( &$this, 'custom_favicon_frontend' ) );
			if ( isset( $extra_setting['dashboard_footer'] ) && ! empty( $extra_setting['dashboard_footer'] ) ) {
				add_filter( 'update_footer', array( &$this, 'core_update_footer' ), 14 );
				add_filter( 'admin_footer_text', array( &$this, 'admin_footer_text' ), 14 );
			}

			if ( isset( $extra_setting['hide_nag'] ) && ! empty( $extra_setting['hide_nag'] ) ) {
				add_action( 'admin_init', array( $this, 'admin_init' ) );
			}

			add_action( 'admin_menu', array( &$this, 'remove_default_post_metaboxes' ) );
			add_action( 'admin_menu', array( &$this, 'remove_default_page_metaboxes' ) );
		}
	}

	/**
	 * Method admin_init()
	 *
	 * Remove remove the update nag.
	 */
	public function admin_init() {
		remove_action( 'admin_notices', 'update_nag', 3 );
	}

	/**
	 * Method admin_menu()
	 *
	 * Add the support form page admin menu item.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Branding_Render::instance()
	 */
	public function admin_menu() {

		/**
		 * Filter 'mainwp_branding_role_cap_enable_contact_form'
		 *
		 * Manage the support form visibility. Set false to hide the support form page.
		 *
		 * @since 4.0
		 */
		$enable_contact = apply_filters( 'mainwp_branding_role_cap_enable_contact_form', false );

		if ( ! $enable_contact && ! current_user_can( 'administrator' ) ) {
			return false;
		}

		$extra_setting = $this->get_extra_options();
		if ( empty( $extra_setting ) ) {
			return false;
		}
		$opts = $this->child_branding_options;

		if ( 'T' === $opts['show_support'] ) {
			$title = $opts['contact_label'];
			if ( isset( $extra_setting['show_button_in'] ) && ( 2 === (int) $extra_setting['show_button_in'] || 3 === (int) $extra_setting['show_button_in'] ) ) {
				$title = $opts['contact_label'];
				add_menu_page(
					$title,
					$title,
					'read',
					'ContactSupport2',
					array(
						MainWP_Child_Branding_Render::instance(),
						'contact_support',
					),
					'',
					'2.0001'
				);
			}

			if ( isset( $extra_setting['show_button_in'] ) && ( 1 === $extra_setting['show_button_in'] || 3 === $extra_setting['show_button_in'] ) ) {
				add_submenu_page(
					null,
					$title,
					$opts['contact_label'],
					'read',
					'ContactSupport',
					array(
						MainWP_Child_Branding_Render::instance(),
						'contact_support',
					)
				);
				add_action( 'admin_bar_menu', array( $this, 'add_support_button_in_top_admin_bar' ), 100 );
			}
		}
	}

	/**
	 * Method remove_default_post_metaboxes()
	 *
	 * Hide new post screen unwanted metaboxes.
	 */
	public function remove_default_post_metaboxes() {
		$extra_setting = $this->get_extra_options();

		add_filter( 'manage_posts_columns', array( &$this, 'custom_post_columns' ) );
		add_filter( 'manage_edit-post_tag_columns', array( &$this, 'manage_my_category_columns' ) );
		add_filter( 'manage_edit-category_columns', array( &$this, 'manage_my_category_columns' ) );

		if ( isset( $extra_setting['hide_metabox_post_custom_fields'] ) && $extra_setting['hide_metabox_post_custom_fields'] ) {
			remove_meta_box( 'postcustom', 'post', 'normal' );
		}
		if ( isset( $extra_setting['hide_metabox_post_excerpt'] ) && $extra_setting['hide_metabox_post_excerpt'] ) {
			remove_meta_box( 'postexcerpt', 'post', 'normal' );
		}
		if ( isset( $extra_setting['hide_metabox_post_discussion'] ) && $extra_setting['hide_metabox_post_discussion'] ) {
			remove_meta_box( 'commentstatusdiv', 'post', 'normal' );
		}
		if ( isset( $extra_setting['hide_metabox_post_trackbacks'] ) && $extra_setting['hide_metabox_post_trackbacks'] ) {
			remove_meta_box( 'trackbacksdiv', 'post', 'normal' );
		}
		if ( isset( $extra_setting['hide_metabox_post_slug'] ) && $extra_setting['hide_metabox_post_slug'] ) {
			remove_meta_box( 'slugdiv', 'post', 'normal' );
		}
		if ( isset( $extra_setting['hide_metabox_post_author'] ) && $extra_setting['hide_metabox_post_author'] ) {
			remove_meta_box( 'authordiv', 'post', 'normal' );
		}
		if ( isset( $extra_setting['hide_metabox_post_revisions'] ) && $extra_setting['hide_metabox_post_revisions'] ) {
			remove_meta_box( 'revisionsdiv', 'post', 'normal' );
		}
		if ( isset( $extra_setting['hide_metabox_post_tags'] ) && $extra_setting['hide_metabox_post_tags'] ) {
			remove_meta_box( 'tagsdiv-post_tag', 'post', 'normal' );
		}
		if ( isset( $extra_setting['hide_metabox_post_categories'] ) && $extra_setting['hide_metabox_post_categories'] ) {
			remove_meta_box( 'categorydiv', 'post', 'normal' );
		}
		if ( isset( $extra_setting['hide_metabox_post_comments'] ) && $extra_setting['hide_metabox_post_comments'] ) {
			remove_meta_box( 'commentsdiv', 'post', 'normal' );
		}
	}

	/**
	 * Method custom_post_columns()
	 *
	 * Hide unwanted posts table columns.
	 *
	 * @param array $defaults An array containing default Manage Posts columns.
	 *
	 * @return array $defaults An updated array containing default Manage Posts columns.
	 */
	public function custom_post_columns( $defaults ) {
		$extra_setting = $this->get_extra_options();

		if ( isset( $extra_setting['hide_metabox_post_comments'] ) && $extra_setting['hide_metabox_post_comments'] ) {
			unset( $defaults['comments'] );
		}
		if ( isset( $extra_setting['hide_metabox_post_author'] ) && $extra_setting['hide_metabox_post_author'] ) {
			unset( $defaults['author'] );
		}
		if ( isset( $extra_setting['hide_metabox_post_categories'] ) && $extra_setting['hide_metabox_post_categories'] ) {
			unset( $defaults['categories'] );
		}

		return $defaults;
	}

	/**
	 * Method manage_my_category_columns()
	 *
	 * Hide the post slug metabox.
	 *
	 * @param array $defaults An array containing default Manage Posts columns.
	 *
	 * @return array $defaults An updated array containing default Manage Posts columns.
	 */
	public function manage_my_category_columns( $defaults ) {
		$extra_setting = $this->get_extra_options();

		if ( isset( $extra_setting['hide_metabox_post_slug'] ) && $extra_setting['hide_metabox_post_slug'] ) {
			unset( $defaults['slug'] );
		}

		return $defaults;
	}

	/**
	 * Method remove_default_page_metaboxes()
	 *
	 * Hide new post screen unwanted metaboxes.
	 */
	public function remove_default_page_metaboxes() {
		$extra_setting = $this->get_extra_options();

		add_filter( 'manage_pages_columns', array( &$this, 'custom_pages_columns' ) );

		if ( isset( $extra_setting['hide_metabox_page_custom_fields'] ) && $extra_setting['hide_metabox_page_custom_fields'] ) {
			remove_meta_box( 'postcustom', 'page', 'normal' );
		}
		if ( isset( $extra_setting['hide_metabox_page_author'] ) && $extra_setting['hide_metabox_page_author'] ) {
			remove_meta_box( 'authordiv', 'page', 'normal' );
		}
		if ( isset( $extra_setting['hide_metabox_page_discussion'] ) && $extra_setting['hide_metabox_page_discussion'] ) {
			remove_meta_box( 'commentstatusdiv', 'page', 'normal' );
		}
		if ( isset( $extra_setting['hide_metabox_page_slug'] ) && $extra_setting['hide_metabox_page_slug'] ) {
			remove_meta_box( 'slugdiv', 'page', 'normal' );
		}
		if ( isset( $extra_setting['hide_metabox_page_revisions'] ) && $extra_setting['hide_metabox_page_revisions'] ) {
			remove_meta_box( 'revisionsdiv', 'page', 'normal' );
		}
		if ( isset( $extra_setting['hide_metabox_page_attributes'] ) && $extra_setting['hide_metabox_page_attributes'] ) {
			remove_meta_box( 'pageparentdiv', 'page', 'normal' );
		}
		if ( isset( $extra_setting['hide_metabox_page_comments'] ) && $extra_setting['hide_metabox_page_comments'] ) {
			remove_meta_box( 'commentsdiv', 'page', 'normal' );
		}
	}

	/**
	 * Method custom_pages_columns()
	 *
	 * Hide unwanted pages table columns.
	 *
	 * @param array $defaults An array containing default Manage Pages columns.
	 *
	 * @return array $defaults An updated array containing default Manage Pages columns.
	 */
	public function custom_pages_columns( $defaults ) {
		$extra_setting = $this->get_extra_options();

		if ( isset( $extra_setting['hide_metabox_page_comments'] ) && $extra_setting['hide_metabox_page_comments'] ) {
			unset( $defaults['comments'] );
		}
		if ( isset( $extra_setting['hide_metabox_page_author'] ) && $extra_setting['hide_metabox_page_author'] ) {
			unset( $defaults['author'] );
		}

		return $defaults;
	}

	/**
	 * Method branding_redirect()
	 *
	 * Prevent updates by redirecting access from the Updates and Plugins page.
	 */
	public function branding_redirect() {
		$pos1 = isset( $_SERVER['REQUEST_URI'] ) ? stripos( wp_unslash( $_SERVER['REQUEST_URI'] ), 'update-core.php' ) : false;
		$pos2 = isset( $_SERVER['REQUEST_URI'] ) ? stripos( wp_unslash( $_SERVER['REQUEST_URI'] ), 'plugins.php' ) : false;
		if ( false !== $pos1 || false !== $pos2 ) {
			wp_safe_redirect( get_option( 'siteurl' ) . '/wp-admin/index.php' );
			exit();
		}
	}

	/**
	 * Method core_update_footer()
	 *
	 * Remove the footer text containing the WP Core version info.
	 */
	public function core_update_footer() {
		echo '';
	}

	/**
	 * Method core_update_footer()
	 *
	 * Set custom admin footer text.
	 */
	public function admin_footer_text() {
		$extra_setting = $this->get_extra_options();
		if ( isset( $extra_setting['dashboard_footer'] ) && ! empty( $extra_setting['dashboard_footer'] ) ) {
			echo wp_kses_post( nl2br( stripslashes( $extra_setting['dashboard_footer'] ) ) );
		}
	}

	/**
	 * Method custom_favicon_frontend()
	 *
	 * Set custom site favicon.
	 */
	public function custom_favicon_frontend() {
		$extra_setting = $this->get_extra_options();
		if ( isset( $extra_setting['favico_image']['url'] ) && ! empty( $extra_setting['favico_image']['url'] ) ) {
			$favico = $extra_setting['favico_image']['url'];
			echo '<link rel="shortcut icon" href="' . esc_url( $favico ) . '"/>' . "\n";
		}
	}

	/**
	 * Method custom_login_logo()
	 *
	 * Set custom site login page logo.
	 */
	public function custom_login_logo() {
		$extra_setting = $this->get_extra_options();
		if ( isset( $extra_setting['login_image']['url'] ) && ! empty( $extra_setting['login_image']['url'] ) ) {
			$login_logo = $extra_setting['login_image']['url'];
			echo '<style type="text/css">#login h1 a { background-image: url(\'' . esc_url( $login_logo ) . '\') !important; height:70px !important; width:310px !important; background-size: auto auto !important; }</style>';
		}
	}

	/**
	 * Method custom_login_headerurl()
	 *
	 * Set custom site login logo link.
	 *
	 * @param string $value Contains the image link information.
	 *
	 * @return string $value Contains the image link updated information.
	 */
	public function custom_login_headerurl( $value ) {
		$extra_setting = $this->get_extra_options();
		if ( isset( $extra_setting['login_image_link'] ) && ! empty( $extra_setting['login_image_link'] ) ) {
			return $extra_setting['login_image_link'];
		}

		return $value;
	}

	/**
	 * Method custom_login_headertitle()
	 *
	 * Set custom site login logo title.
	 *
	 * @param string $value Contains the image title information.
	 *
	 * @return string $value Contains the image title updated information.
	 */
	public function custom_login_headertitle( $value ) {
		$extra_setting = $this->get_extra_options();
		if ( isset( $extra_setting['login_image_title'] ) && ! empty( $extra_setting['login_image_title'] ) ) {
			return $extra_setting['login_image_title'];
		}

		return $value;
	}

	/**
	 * Method custom_gettext()
	 *
	 * Replace language domains.
	 *
	 * @param array  $translations An array containing the list of available translations.
	 * @param string $text         Contains the text to replace.
	 * @param string $domain       Contains the language domain.
	 *
	 * @return array $translations An array containing the list of available translations.
	 */
	public function custom_gettext( $translations, $text, $domain = 'default' ) {
		$extra_setting = $this->get_extra_options();
		$texts_replace = $extra_setting['texts_replace'];
		if ( is_array( $texts_replace ) && count( $texts_replace ) > 0 ) {
			foreach ( $texts_replace as $text => $replace ) {
				if ( ! empty( $text ) ) {
					$translations = str_replace( $text, $replace, $translations );
				}
			}
		}

		return $translations;
	}

	/**
	 * Method custom_admin_css()
	 *
	 * Set custom WP Admin area CSS.
	 */
	public function custom_admin_css() {
		$header_css    = '';
		$extra_setting = $this->get_extra_options();

		if ( isset( $extra_setting['admin_css'] ) && ! empty( $extra_setting['admin_css'] ) ) {
			$header_css .= $extra_setting['admin_css'];
		}

		if ( isset( $extra_setting['hide_screen_opts'] ) && ! empty( $extra_setting['hide_screen_opts'] ) ) {
			$header_css .= ' #screen-options-link-wrap { display: none; }';
		}

		if ( isset( $extra_setting['hide_help_box'] ) && ! empty( $extra_setting['hide_help_box'] ) ) {
			$header_css .= ' #contextual-help-link-wrap { display: none; }';
			$header_css .= ' #contextual-help-link { display: none; }';
		}

		if ( ! empty( $header_css ) ) {
			echo '<style>' . self::parse_css( $header_css ) . '</style>';
		}
	}

	/**
	 * Method custom_login_css()
	 *
	 * Set custom Login page CSS.
	 */
	public function custom_login_css() {
		$extra_setting = $this->get_extra_options();
		if ( isset( $extra_setting['login_css'] ) && ! empty( $extra_setting['login_css'] ) ) {
			echo '<style>' . self::parse_css( $extra_setting['login_css'] ) . '</style>';
		}
	}

	/**
	 * Method parse_css()
	 *
	 * Parses CSS into an array.
	 *
	 * @param string $css Contains the CSS code that needs to be parsed.
	 *
	 * @return mixed Rebuilt CSS.
	 *
	 * Copyright (C) 2009 Peter Kröner, CSSPARSER.
	 */
	public static function parse_css( $css ) {

		// Remove CSS-Comments.
		$css = preg_replace( '/\/\*.*?\*\//ms', '', $css );
		// Remove HTML-Comments.
		$css = preg_replace( '/([^\'"]+?)(\<!--|--\>)([^\'"]+?)/ms', '$1$3', $css );
		// Extract @media-blocks into $blocks.
		preg_match_all( '/@.+?\}[^\}]*?\}/ms', $css, $blocks );
		// Append the rest to $blocks.
		array_push( $blocks[0], preg_replace( '/@.+?\}[^\}]*?\}/ms', '', $css ) );
		$ordered      = array();
		$count_blocks = count( $blocks[0] );
		for ( $i = 0; $i < $count_blocks; $i++ ) {
			// If @media-block, strip declaration and parenthesis.
			if ( '@media' === substr( $blocks[0][ $i ], 0, 6 ) ) {
				$ordered_key   = preg_replace( '/^(@media[^\{]+)\{.*\}$/ms', '$1', $blocks[0][ $i ] );
				$ordered_value = preg_replace( '/^@media[^\{]+\{(.*)\}$/ms', '$1', $blocks[0][ $i ] );
			} elseif ( '@' === substr( $blocks[0][ $i ], 0, 1 ) ) {
				$ordered_key   = $blocks[0][ $i ];
				$ordered_value = $blocks[0][ $i ];
			} else {
				$ordered_key   = 'main';
				$ordered_value = $blocks[0][ $i ];
			}
			// Split by parenthesis, ignoring those inside content-quotes.
			$ordered[ $ordered_key ] = preg_split( '/([^\'"\{\}]*?[\'"].*?(?<!\\\)[\'"][^\'"\{\}]*?)[\{\}]|([^\'"\{\}]*?)[\{\}]/', trim( $ordered_value, " \r\n\t" ), -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE );
		}
		return self::parse_css_rebuild( $ordered );
	}

	/**
	 * Method parse_css_rebuild()
	 *
	 * Rebuild parsed CSS.
	 *
	 * @param string $ordered Contains the parsed CSS code that needs to be rebuit.
	 *
	 * @return mixed CSS output.
	 *
	 * Copyright (C) 2009 Peter Kröner, CSSPARSER.
	 */
	public static function parse_css_rebuild( $ordered ) {
		// Beginning to rebuild new slim CSS-Array.
		foreach ( $ordered as $key => $val ) {
			$new       = array();
			$count_val = count( $val );
			for ( $i = 0; $i < $count_val; $i++ ) {
				// Split selectors and rules and split properties and values.
				$selector = trim( $val[ $i ], " \r\n\t" );

				if ( ! empty( $selector ) ) {
					if ( ! isset( $new[ $selector ] ) ) {
						$new[ $selector ] = array();
					}

					$rules = explode( ';', $val[ ++$i ] );

					// to fix css like this: 'data:image/svg+xml;charset=US-ASCII'.
					$tmp_rules = array();
					$j         = 0;
					$cou       = count( $rules );
					while ( $j < $cou ) {
						$rule = $rules[ $j ];
						$pos  = strpos( $rule, 'data:image/svg+xml' );
						if ( 0 < $pos ) {
							$len  = strlen( $rule );
							$len1 = strlen( 'data:image/svg+xml' );
							$len2 = $pos + $len1;
							if ( $len == $len2 ) {
								$rule = $rule . ';' . $rules[ $j + 1 ];
								$j++;
							}
						}
						$j++;
						$tmp_rules[] = $rule;
					}
					$rules = $tmp_rules;

					foreach ( $rules as $rule ) {
						$rule = trim( $rule, " \r\n\t" );
						if ( ! empty( $rule ) ) {
							$rule     = array_reverse( explode( ':', $rule ) );
							$property = trim( array_pop( $rule ), " \r\n\t" );
							$value    = implode( ':', array_reverse( $rule ) );

							if ( ! isset( $new[ $selector ][ $property ] ) || ! preg_match( '/!important/', $new[ $selector ][ $property ] ) ) {
								$new[ $selector ][ $property ] = $value;
							} elseif ( preg_match( '/!important/', $new[ $selector ][ $property ] ) && preg_match( '/!important/', $value ) ) {
								$new[ $selector ][ $property ] = $value;
							}
						}
					}
				}
			}
			$ordered[ $key ] = $new;
		}
		$parsed = $ordered;

		$output = '';
		foreach ( $parsed as $media => $content ) {
			if ( '@media' === substr( $media, 0, 6 ) ) {
				$output .= $media . " {\n";
				$prefix  = "\t";
			} else {
				$prefix = '';
			}

			foreach ( $content as $selector => $rules ) {
				$output .= $prefix . $selector . " {\n";
				foreach ( $rules as $property => $value ) {
					$output .= $prefix . "\t" . $property . ': ' . $value;
					$output .= ";\n";
				}
				$output .= $prefix . "}\n\n";
			}
			if ( '@media' === substr( $media, 0, 6 ) ) {
				$output .= "}\n\n";
			}
		}
		return $output;
	}

	/**
	 * Method custom_generator()
	 *
	 * Set custom generator meta tag.
	 *
	 * @param string $generator Contains the generator information.
	 * @param string $type      Contains the generator type information.
	 *
	 * @return string Contains the updated generator information.
	 */
	public function custom_generator( $generator, $type = '' ) {
		$extra_setting = $this->get_extra_options();
		if ( isset( $extra_setting['site_generator'] ) ) {
			if ( ! empty( $extra_setting['site_generator'] ) ) {
				switch ( $type ) :
					case 'html':
						$generator = '<meta name="generator" content="' . $extra_setting['site_generator'] . '">';
						break;
					case 'xhtml':
						$generator = '<meta name="generator" content="' . $extra_setting['site_generator'] . '" />';
						break;
					case 'atom':
						if ( ! empty( $extra_setting['generator_link'] ) ) {
							$generator = '<generator uri="' . $extra_setting['generator_link'] . '" >' . $extra_setting['site_generator'] . '</generator>';
						}
						break;
					case 'rss2':
						if ( ! empty( $extra_setting['generator_link'] ) ) {
							$generator = '<generator>' . $extra_setting['generator_link'] . '</generator>';
						}
						break;
					case 'rdf':
						if ( ! empty( $extra_setting['generator_link'] ) ) {
							$generator = '<admin:generatorAgent rdf:resource="' . $extra_setting['generator_link'] . '" />';
						}
						break;
					case 'comment':
						$generator = '<!-- generator="' . $extra_setting['site_generator'] . '" -->';
						break;
					case 'export':
						$generator = '<!-- generator="' . $extra_setting['site_generator'] . '" created="' . date( 'Y-m-d H:i' ) . '" -->'; //phpcs:ignore -- current local time.
						break;
				endswitch;

				return $generator;
			}
		}

		return $generator;
	}

	/**
	 * Method custom_dashboard_widgets()
	 *
	 * Hide unwanted WordPress Dashboard page widgets.
	 */
	public function custom_dashboard_widgets() {

		/**
		 * Public variable to hold the Metaboxes array.
		 *
		 * @var array Metaboxes.
		 */
		global $wp_meta_boxes;

		$extra_setting = $this->get_extra_options();
		if ( isset( $extra_setting['remove_widget_welcome'] ) && $extra_setting['remove_widget_welcome'] ) {
			remove_action( 'welcome_panel', 'wp_welcome_panel' );
		}
		if ( isset( $extra_setting['remove_widget_glance'] ) && $extra_setting['remove_widget_glance'] ) {
			unset( $wp_meta_boxes['dashboard']['normal']['core']['dashboard_right_now'] );
		}
		if ( isset( $extra_setting['remove_widget_activity'] ) && $extra_setting['remove_widget_activity'] ) {
			unset( $wp_meta_boxes['dashboard']['normal']['core']['dashboard_activity'] );
		}
		if ( isset( $extra_setting['remove_widget_quick'] ) && $extra_setting['remove_widget_quick'] ) {
			unset( $wp_meta_boxes['dashboard']['side']['core']['dashboard_quick_press'] );
		}
		if ( isset( $extra_setting['remove_widget_news'] ) && $extra_setting['remove_widget_news'] ) {
			unset( $wp_meta_boxes['dashboard']['side']['core']['dashboard_primary'] );
		}
	}

	/**
	 * Method branding_global_footer()
	 *
	 * Set custom footer text.
	 */
	public function branding_global_footer() {
		$extra_setting = $this->get_extra_options();
		if ( isset( $extra_setting['global_footer'] ) && ! empty( $extra_setting['global_footer'] ) ) {
			echo wp_kses_post( nl2br( stripslashes( $extra_setting['global_footer'] ) ) );
		}
	}

	/**
	 * Method add_support_button_in_top_admin_bar()
	 *
	 * Add or remove the admin bar Support button node.
	 *
	 * @param object $wp_admin_bar An object containing the WP Admin bar information.
	 *
	 * @return bool If conditions not met, return false.
	 */
	public function add_support_button_in_top_admin_bar( $wp_admin_bar ) {

		/**
		 * Filter 'mainwp_branding_role_cap_enable_contact_form'
		 *
		 * Manage the support form visibility. Set false to hide the support form page.
		 *
		 * @since 4.0
		 */
		$enable_contact = apply_filters( 'mainwp_branding_role_cap_enable_contact_form', false );

		if ( ! $enable_contact && ! current_user_can( 'administrator' ) ) {
			return false;
		}

		if ( isset( $_GET['from_page'] ) ) {
			$href = admin_url( 'admin.php?page=ContactSupport&from_page=' . ( ! empty( $_GET['from_page'] ) ? rawurlencode( esc_url( wp_unslash( $_GET['from_page'] ) ) ) : '' ) );
		} else {
			$protocol = isset( $_SERVER['HTTPS'] ) && strcasecmp( sanitize_text_field( wp_unslash( $_SERVER['HTTPS'] ) ), 'off' ) ? 'https://' : 'http://';
			$fullurl  = isset( $_SERVER['HTTP_HOST'] ) && isset( $_SERVER['REQUEST_URI'] ) ? $protocol . sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) . wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
			$href     = admin_url( 'admin.php?page=ContactSupport&from_page=' . rawurlencode( esc_url( $fullurl ) ) );
		}
		$args = array(
			'id'     => 999,
			'title'  => $this->child_branding_options['contact_label'],
			'parent' => 'top-secondary',
			'href'   => $href,
			'meta'   => array(
				'class' => 'mainwp_branding_support_top_bar_button',
				'title' => $this->child_branding_options['contact_label'],
			),
		);

		$wp_admin_bar->add_node( $args );
	}

	/**
	 * Method is_branding()
	 *
	 * Check if the custom branding is enabled.
	 *
	 * @return bool If branding enabled, return true, if not, return false.
	 */
	public function is_branding() {
		$opts = $this->child_branding_options;

		if ( ! isset( $opts['branding_ext_enabled'] ) || 'Y' !== $opts['branding_ext_enabled'] ) {
			return false;
		}

		$is_hide            = isset( $opts['hide'] ) ? $opts['hide'] : '';
		$cancelled_branding = $opts['cancelled_branding'];
		$branding_header    = isset( $opts['branding_header'] ) ? $opts['branding_header'] : '';

		if ( $cancelled_branding ) {
			return false;
		}

		if ( 'T' === $is_hide ) {
			return true;
		}

		if ( is_array( $branding_header ) && ! empty( $branding_header['name'] ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Method get_branding_title()
	 *
	 * Get custom title for the MainWP Child plugin.
	 *
	 * @return mixed If branding enabled, return custom title.
	 */
	public function get_branding_title() {
		if ( $this->is_branding() ) {
			$branding_header = $this->child_branding_options['branding_header'];
			return $branding_header['name'];
		}
		return '';
	}

	/**
	 * Method get_branding_options()
	 *
	 * Get branding options.
	 *
	 * @return array An array containing the branding options.
	 */
	public function get_branding_options() {
		return $this->child_branding_options;
	}

	/**
	 * Method save_branding_options()
	 *
	 * Save branding options.
	 *
	 * @param string $name Contains the option name.
	 * @param string $val  Contains the option value.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::update_option() Update database option.
	 */
	public function save_branding_options( $name, $val ) {
		$this->child_branding_options[ $name ] = $val;
		MainWP_Helper::update_option( 'mainwp_child_branding_settings', $this->child_branding_options );
	}

	/**
	 * Method branding_map_meta_cap()
	 *
	 * Set cutom capabilities to disable theme switching.
	 *
	 * @param array  $caps    An array of capabiilities.
	 * @param string $cap     Contains the capability.
	 * @param int    $user_id Current user ID.
	 * @param array  $args    An array of arguments to process.
	 *
	 * @return array $caps An array of updated capabiilities.
	 */
	public function branding_map_meta_cap( $caps, $cap, $user_id, $args ) {
		if ( isset( $this->child_branding_options['disable_switching_theme'] ) && 'T' === $this->child_branding_options['disable_switching_theme'] ) {
			if ( 'switch_themes' === $cap ) {
				$caps[0] = 'do_not_allow';
			}
		}
		return $caps;
	}

	/**
	 * Method modify_plugin_header()
	 *
	 * Modify plugin header to show custom plugin info.
	 *
	 * @param array $plugins An array of installed plugins information.
	 *
	 * @return array $plugins Updated array of installed plugins information.
	 */
	public function modify_plugin_header( $plugins ) {
		$opts = $this->child_branding_options;
		if ( is_array( $opts ) ) {
			$is_hide            = isset( $opts['hide'] ) ? $opts['hide'] : '';
			$cancelled_branding = $opts['cancelled_branding'];
			$branding_header    = isset( $opts['branding_header'] ) ? $opts['branding_header'] : '';

			if ( $cancelled_branding ) {
				return $plugins;
			}

			if ( 'T' === $is_hide ) {
				foreach ( $plugins as $key => $value ) {
					$plugin_slug = basename( $key, '.php' );
					if ( 'mainwp-child' === $plugin_slug ) {
						unset( $plugins[ $key ] );
					}
				}
				return $plugins;
			}

			if ( is_array( $branding_header ) && ! empty( $branding_header['name'] ) ) {
				return $this->update_plugin_header( $plugins, $branding_header );
			} else {
				return $plugins;
			}
		}

		return $plugins;
	}

	/**
	 * Method hide_update_notice()
	 *
	 * Hide the MainWP Child update notice if custom branding is applied.
	 *
	 * @param array $slugs An array of slugs of all installed plugins.
	 *
	 * @return array $slugs Updated array of slugs of all installed plugins.
	 */
	public function hide_update_notice( $slugs ) {
		$slugs[] = 'mainwp-child/mainwp-child.php';
		return $slugs;
	}

	/**
	 * Method remove_update_nag()
	 *
	 * Hide the MainWP Child update notification on the Updates page.
	 *
	 * @param object $value Object containing the updates info.
	 *
	 * @return object $value Updated object containing the updates info.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::is_updates_screen()
	 */
	public function remove_update_nag( $value ) {
		if ( isset( $_POST['mainwpsignature'] ) ) {
			return $value;
		}

		if ( ! MainWP_Helper::is_updates_screen() ) {
			return $value;
		}

		if ( isset( $value->response['mainwp-child/mainwp-child.php'] ) ) {
			unset( $value->response['mainwp-child/mainwp-child.php'] );
		}
		return $value;
	}


	/**
	 * Method update_plugin_header()
	 *
	 * Update plugin header to show custom plugin info.
	 *
	 * @param array $plugins An array of installed plugins information.
	 * @param array $header  An array containig plugin information.
	 *
	 * @return array $plugins Updated array of installed plugins information.
	 */
	public function update_plugin_header( $plugins, $header ) {
		$plugin_key = '';
		foreach ( $plugins as $key => $value ) {
			$plugin_slug = basename( $key, '.php' );
			if ( 'mainwp-child' === $plugin_slug ) {
				$plugin_key  = $key;
				$plugin_data = $value;
			}
		}

		if ( ! empty( $plugin_key ) ) {
			$plugin_data['Name']        = stripslashes( $header['name'] );
			$plugin_data['Description'] = stripslashes( $header['description'] );
			$plugin_data['Author']      = stripslashes( $header['author'] );
			$plugin_data['AuthorURI']   = stripslashes( $header['authoruri'] );
			if ( ! empty( $header['pluginuri'] ) ) {
				$plugin_data['PluginURI'] = stripslashes( $header['pluginuri'] );
			}
			$plugins[ $plugin_key ] = $plugin_data;
		}

		return $plugins;
	}
}

