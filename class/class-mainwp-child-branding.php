<?php

class MainWP_Child_Branding {
	public static $instance = null;
	protected $child_plugin_dir;
	protected $settings = null;

	static function Instance() {
		if ( null === MainWP_Child_Branding::$instance ) {
			MainWP_Child_Branding::$instance = new MainWP_Child_Branding();
		}

		return MainWP_Child_Branding::$instance;
	}

	public function __construct() {
		$this->child_plugin_dir = dirname( dirname( __FILE__ ) );
		add_action( 'mainwp_child_deactivation', array( $this, 'child_deactivation' ) );
		add_filter( 'mainwp_child_plugin_row_meta', array( $this, 'plugin_row_meta' ), 10, 3 );

		$label = get_option( 'mainwp_branding_button_contact_label' );
		if ( ! empty( $label ) ) {
			$label = stripslashes( $label );
		} else {
			$label = 'Contact Support';
		}

		$this->settings['contact_support_label'] = $label;
		$this->settings['extra_settings']        = get_option( 'mainwp_branding_extra_settings' );
	}


	public function plugin_row_meta( $plugin_meta, $plugin_file, $child_plugin_slug ) {
		if ( $child_plugin_slug !== $plugin_file ) {
			return $plugin_meta;
		}

		if ( ! self::is_branding() ) {
			return $plugin_meta;
		}

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

	public static function admin_init() {
	}

	public function child_deactivation() {
		$dell_all = array(
			'mainwp_branding_disable_change',
			'mainwp_branding_disable_switching_theme',
			'mainwp_branding_child_hide',
			'mainwp_branding_show_support',
			'mainwp_branding_support_email',
			'mainwp_branding_support_message',
			'mainwp_branding_remove_restore',
			'mainwp_branding_remove_setting',
			'mainwp_branding_remove_server_info',
			'mainwp_branding_remove_wp_tools',
			'mainwp_branding_remove_wp_setting',
			'mainwp_branding_remove_permalink',
			//'mainwp_branding_plugin_header', // don't remove header
			'mainwp_branding_button_contact_label',
			'mainwp_branding_send_email_message',
			'mainwp_branding_message_return_sender',
			'mainwp_branding_submit_button_title',
			'mainwp_branding_extra_settings',
			'mainwp_branding_ext_enabled',
		);
		foreach ( $dell_all as $opt ) {
			delete_option( $opt );
		}
	}


	public function action() {
		$information = array();
		switch ( $_POST['action'] ) {
			case 'update_branding':
				$information = $this->update_branding();
				break;
		}
		MainWP_Helper::write( $information );
	}

	public function update_branding() {
		$information = array();
		$settings    = maybe_unserialize( base64_decode( $_POST['settings'] ) );
		if ( ! is_array( $settings ) ) {
			return $information;
		}
		$current_extra_setting = $this->settings['extra_settings'];
		MainWP_Helper::update_option( 'mainwp_branding_ext_enabled', 'Y', 'yes' );
		$header = array(
			'name'        => $settings['child_plugin_name'],
			'description' => $settings['child_plugin_desc'],
			'author'      => $settings['child_plugin_author'],
			'authoruri'   => $settings['child_plugin_author_uri'],
			'pluginuri'   => $settings['child_plugin_uri'],
		);
		MainWP_Helper::update_option( 'mainwp_branding_preserve_branding', $settings['child_preserve_branding'] );
		MainWP_Helper::update_option( 'mainwp_branding_plugin_header', $header, 'yes' );
		MainWP_Helper::update_option( 'mainwp_branding_support_email', $settings['child_support_email'] );
		MainWP_Helper::update_option( 'mainwp_branding_support_message', $settings['child_support_message'] );
		MainWP_Helper::update_option( 'mainwp_branding_remove_restore', $settings['child_remove_restore'] );
		MainWP_Helper::update_option( 'mainwp_branding_remove_setting', $settings['child_remove_setting'] );
		MainWP_Helper::update_option( 'mainwp_branding_remove_server_info', $settings['child_remove_server_info'] );
		MainWP_Helper::update_option( 'mainwp_branding_remove_wp_tools', $settings['child_remove_wp_tools'] );
		MainWP_Helper::update_option( 'mainwp_branding_remove_wp_setting', $settings['child_remove_wp_setting'] );
		MainWP_Helper::update_option( 'mainwp_branding_remove_permalink', $settings['child_remove_permalink'] );
		MainWP_Helper::update_option( 'mainwp_branding_button_contact_label', $settings['child_button_contact_label'], 'yes' );
		MainWP_Helper::update_option( 'mainwp_branding_send_email_message', $settings['child_send_email_message'] );
		MainWP_Helper::update_option( 'mainwp_branding_message_return_sender', $settings['child_message_return_sender'] );
		MainWP_Helper::update_option( 'mainwp_branding_submit_button_title', $settings['child_submit_button_title'] );
		if ( isset( $settings['child_disable_wp_branding'] ) && ( 'Y' === $settings['child_disable_wp_branding'] || 'N' === $settings['child_disable_wp_branding'] ) ) {
			MainWP_Helper::update_option( 'mainwp_branding_disable_wp_branding', $settings['child_disable_wp_branding'] );
		}

		$extra_setting = array(
			'show_button_in'                  => $settings['child_show_support_button_in'],
			'global_footer'                   => $settings['child_global_footer'],
			'dashboard_footer'                => $settings['child_dashboard_footer'],
			'remove_widget_welcome'           => $settings['child_remove_widget_welcome'],
			'remove_widget_glance'            => $settings['child_remove_widget_glance'],
			'remove_widget_activity'          => $settings['child_remove_widget_activity'],
			'remove_widget_quick'             => $settings['child_remove_widget_quick'],
			'remove_widget_news'              => $settings['child_remove_widget_news'],
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
					$upload = $this->uploadImage( $settings['child_login_image_url'] ); //Upload image to WP
					if ( null !== $upload ) {
						$extra_setting['login_image'] = array( 'path' => $upload['path'], 'url' => $upload['url'] );
						if ( isset( $current_extra_setting['login_image']['path'] ) ) {
							$old_file = $current_extra_setting['login_image']['path'];
							if ( ! empty( $old_file ) && file_exists( $old_file ) ) {
								@unlink( $old_file );
							}
						}
					}
				} catch ( Exception $e ) {
					$information['error']['login_image'] = $e->getMessage();
				}
			}
		} else if ( isset( $current_extra_setting['login_image'] ) ) {
			$extra_setting['login_image'] = $current_extra_setting['login_image'];
		}

		if ( isset( $settings['child_favico_image_url'] ) ) {
			if ( empty( $settings['child_favico_image_url'] ) ) {
				$extra_setting['favico_image'] = array();
			} else {
				try {
					$upload = $this->uploadImage( $settings['child_favico_image_url'] ); //Upload image to WP
					if ( null !== $upload ) {
						$extra_setting['favico_image'] = array( 'path' => $upload['path'], 'url' => $upload['url'] );
						if ( isset( $current_extra_setting['favico_image']['path'] ) ) {
							$old_file = $current_extra_setting['favico_image']['path'];
							if ( ! empty( $old_file ) && file_exists( $old_file ) ) {
								@unlink( $old_file );
							}
						}
					}
				} catch ( Exception $e ) {
					$information['error']['favico_image'] = $e->getMessage();
				}
			}
		} else if ( isset( $current_extra_setting['favico_image'] ) ) {
			$extra_setting['favico_image'] = $current_extra_setting['favico_image'];
		}

		MainWP_Helper::update_option( 'mainwp_branding_extra_settings', $extra_setting, 'yes' );

		if ( $settings['child_plugin_hide'] ) {
			MainWP_Helper::update_option( 'mainwp_branding_child_hide', 'T' );
		} else {
			MainWP_Helper::update_option( 'mainwp_branding_child_hide', '' );
		}

		if ( $settings['child_show_support_button'] && ! empty( $settings['child_support_email'] ) ) {
			MainWP_Helper::update_option( 'mainwp_branding_show_support', 'T' );
		} else {
			MainWP_Helper::update_option( 'mainwp_branding_show_support', '' );
		}

		if ( $settings['child_disable_change'] ) {
			MainWP_Helper::update_option( 'mainwp_branding_disable_change', 'T' );
		} else {
			MainWP_Helper::update_option( 'mainwp_branding_disable_change', '' );
		}

		if ( $settings['child_disable_switching_theme'] ) {
			MainWP_Helper::update_option( 'mainwp_branding_disable_switching_theme', 'T' );
		} else {
			MainWP_Helper::update_option( 'mainwp_branding_disable_switching_theme', '' );
		}

		$information['result'] = 'SUCCESS';

		return $information;
	}

	static function uploadImage( $img_url ) {
		include_once( ABSPATH . 'wp-admin/includes/file.php' ); //Contains download_url
		//Download $img_url
		$temporary_file = download_url( $img_url );

		if ( is_wp_error( $temporary_file ) ) {
			throw new Exception( 'Error: ' . $temporary_file->get_error_message() );
		} else {
			$upload_dir     = wp_upload_dir();
			$local_img_path = $upload_dir['path'] . DIRECTORY_SEPARATOR . basename( $img_url ); //Local name
			$local_img_path = dirname( $local_img_path ) . '/' . wp_unique_filename( dirname( $local_img_path ), basename( $local_img_path ) );
			$local_img_url  = $upload_dir['url'] . '/' . basename( $local_img_path );
			$moved          = @rename( $temporary_file, $local_img_path );
			if ( $moved ) {
				return array( 'path' => $local_img_path, 'url' => $local_img_url );
			}
		}
		if ( file_exists( $temporary_file ) ) {
			unlink( $temporary_file );
		}

		return null;
	}


	public function branding_init() {
		$extra_setting = $this->settings['extra_settings'];
		if ( ! is_array( $extra_setting ) ) {
			$extra_setting = array();
		}
		$cancelled_branding = ( get_option( 'mainwp_child_branding_disconnected' ) === 'yes' ) && ! get_option( 'mainwp_branding_preserve_branding' );
		if ( $cancelled_branding ) {
			return;
		}
		// enable branding in case child plugin is deactive
		add_filter( 'all_plugins', array( $this, 'branding_child_plugin' ) );

		if ( get_option( 'mainwp_branding_ext_enabled' ) !== 'Y' ) {
			return;
		}

		add_filter( 'map_meta_cap', array( $this, 'branding_map_meta_cap' ), 10, 5 );

		if ( 'T' === get_option( 'mainwp_branding_show_support' ) ) {
			$title = $this->settings['contact_support_label'];
			if ( isset( $extra_setting['show_button_in'] ) && ( 2 === (int) $extra_setting['show_button_in'] || 3 === (int) $extra_setting['show_button_in'] ) ) {
				$title = $this->settings['contact_support_label'];
				add_menu_page( $title, $title, 'read', 'ContactSupport2', array(
					$this,
					'contact_support',
				), '', '2.0001' );
			}

			if ( isset( $extra_setting['show_button_in'] ) && ( 1 === $extra_setting['show_button_in'] || 3 === $extra_setting['show_button_in'] ) ) {
				add_submenu_page( null, $title, $this->settings['contact_support_label'], 'read', 'ContactSupport', array(
					$this,
					'contact_support',
				) );
				add_action( 'admin_bar_menu', array( $this, 'add_support_button_in_top_admin_bar' ), 100 );
			}
		}
		add_filter( 'update_footer', array( &$this, 'update_footer' ), 15 );
		if ( get_option( 'mainwp_branding_disable_wp_branding' ) !== 'Y' ) {
			add_filter( 'wp_footer', array( &$this, 'branding_global_footer' ), 15 );
			add_action( 'wp_dashboard_setup', array( &$this, 'custom_dashboard_widgets' ), 999 );
			// branding site generator			
			$types = array( 'html', 'xhtml', 'atom', 'rss2', 'rdf', 'comment', 'export' );
			foreach ( $types as $type ) {
				add_filter( 'get_the_generator_' . $type, array( &$this, 'custom_the_generator' ), 999, 2 );
			}
			add_action( 'admin_head', array( &$this, 'custom_admin_css' ) );
			add_action( 'login_enqueue_scripts', array( &$this, 'custom_login_css' ) );
			add_filter( 'gettext', array( &$this, 'custom_gettext' ), 99, 3 );
			add_action( 'login_head', array( &$this, 'custom_login_logo' ) );
			add_action( 'wp_head', array( &$this, 'custom_favicon_frontend' ) );
			if ( isset( $extra_setting['dashboard_footer'] ) && ! empty( $extra_setting['dashboard_footer'] ) ) {
				remove_filter( 'update_footer', 'core_update_footer' );
				add_filter( 'update_footer', array( &$this, 'update_admin_footer' ), 14 );
			}

			if ( isset( $extra_setting['hide_nag'] ) && ! empty( $extra_setting['hide_nag'] ) ) {
				add_action( 'admin_init', create_function( '', 'remove_action( \'admin_notices\', \'update_nag\', 3 );' ) );
			}

			add_action( 'admin_menu', array( &$this, 'remove_default_post_metaboxes' ) );
			add_action( 'admin_menu', array( &$this, 'remove_default_page_metaboxes' ) );
		}
	}

	function remove_default_post_metaboxes() {
		$extra_setting = $this->settings['extra_settings'];
		if ( ! is_array( $extra_setting ) ) {
			$extra_setting = array();
		}

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


	function custom_post_columns( $defaults ) {
		$extra_setting = $this->settings['extra_settings'];
		if ( ! is_array( $extra_setting ) ) {
			$extra_setting = array();
		}

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

	function manage_my_category_columns( $defaults ) {
		$extra_setting = $this->settings['extra_settings'];
		if ( ! is_array( $extra_setting ) ) {
			$extra_setting = array();
		}

		if ( isset( $extra_setting['hide_metabox_post_slug'] ) && $extra_setting['hide_metabox_post_slug'] ) {
			unset( $defaults['slug'] );
		}

		return $defaults;
	}

	function remove_default_page_metaboxes() {
		$extra_setting = $this->settings['extra_settings'];
		if ( ! is_array( $extra_setting ) ) {
			$extra_setting = array();
		}

		add_filter( 'manage_pages_columns', array( &$this, 'custom_pages_columns' ) );

		if ( isset( $extra_setting['hide_metabox_page_custom_fields'] ) && $extra_setting['hide_metabox_page_custom_fields'] ) {  // if (get_option('wlcms_o_page_meta_box_custom'))
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

	function custom_pages_columns( $defaults ) {
		$extra_setting = $this->settings['extra_settings'];
		if ( ! is_array( $extra_setting ) ) {
			$extra_setting = array();
		}

		if ( isset( $extra_setting['hide_metabox_page_comments'] ) && $extra_setting['hide_metabox_page_comments'] ) {
			unset( $defaults['comments'] );
		}
		if ( isset( $extra_setting['hide_metabox_page_author'] ) && $extra_setting['hide_metabox_page_author'] ) {
			unset( $defaults['author'] );
		}

		return $defaults;
	}


	function update_admin_footer() {
		$extra_setting = $this->settings['extra_settings'];
		if ( isset( $extra_setting['dashboard_footer'] ) && ! empty( $extra_setting['dashboard_footer'] ) ) {
			echo wp_kses_post( nl2br( stripslashes( $extra_setting['dashboard_footer'] ) ) );
		}
	}

	function custom_favicon_frontend() {
		$extra_setting = $this->settings['extra_settings'];
		if ( isset( $extra_setting['favico_image']['url'] ) && ! empty( $extra_setting['favico_image']['url'] ) ) {
			$favico = $extra_setting['favico_image']['url'];
			echo '<link rel="shortcut icon" href="' . esc_url( $favico ) . '"/>' . "\n";
		}
	}

	function custom_login_logo() {
		$extra_setting = $this->settings['extra_settings'];
		if ( isset( $extra_setting['login_image']['url'] ) && ! empty( $extra_setting['login_image']['url'] ) ) {
			$login_logo = $extra_setting['login_image']['url'];
			echo '<style type="text/css">
                    #login h1 a { background-image: url(\'' . esc_url( $login_logo ) . '\') !important; height:70px !important; width:310px !important; background-size: auto auto !important; }
                </style>';
		}
	}

	function custom_gettext( $translations, $text, $domain = 'default' ) {
		$extra_setting = $this->settings['extra_settings'];
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

	function custom_admin_css() {
		$header_css    = '';
		$extra_setting = $this->settings['extra_settings'];

		if ( is_array( $extra_setting ) && isset( $extra_setting['admin_css'] ) && ! empty( $extra_setting['admin_css'] ) ) {
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
			echo '<style>' . MainWP_Helper::parse_css( $header_css ) . '</style>';
		}
	}

	function custom_login_css() {
		$extra_setting = $this->settings['extra_settings'];
		if ( is_array( $extra_setting ) && isset( $extra_setting['login_css'] ) && ! empty( $extra_setting['login_css'] ) ) {
			echo '<style>' . MainWP_Helper::parse_css( $extra_setting['login_css'] ) . '</style>';
		}
	}

	function custom_the_generator( $generator, $type = '' ) {
		$extra_setting = $this->settings['extra_settings'];
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
						$generator = '<!-- generator="' . $extra_setting['site_generator'] . '" created="' . date( 'Y-m-d H:i' ) . '" -->';
						break;
				endswitch;

				return $generator;
			}
		}

		return $generator;
	}

	function custom_dashboard_widgets() {
		global $wp_meta_boxes;
		$extra_setting = $this->settings['extra_settings'];
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

	public function branding_global_footer() {
		$extra_setting = $this->settings['extra_settings'];
		if ( isset( $extra_setting['global_footer'] ) && ! empty( $extra_setting['global_footer'] ) ) {
			echo wp_kses_post( nl2br( stripslashes( $extra_setting['global_footer'] ) ) );
		}
	}

	public function send_support_mail() {
		$email   = get_option( 'mainwp_branding_support_email' );
		$sub = wp_kses_post( nl2br( stripslashes( $_POST['mainwp_branding_contact_message_subject'] ) ) );
		$subject = !empty( $sub ) ? $sub : "MainWP - Support Contact";
		$content = wp_kses_post( nl2br( stripslashes( $_POST['mainwp_branding_contact_message_content'] ) ) );
		if ( ! empty( $_POST['mainwp_branding_contact_message_content'] ) && ! empty( $email ) ) {
			global $current_user;
			$headers .= "Content-Type: text/html;charset=utf-8\r\n";
			$headers .= "From: \"" . $current_user->user_email . "\" <" . $current_user->user_email . ">\r\n";
			$mail .= "<p>Support Email from: <a href='" . site_url() . "'>" . site_url() . "</a></p>\r\n\r\n";
			$mail .= "<p>Sent from WordPress page: " . ( ! empty( $_POST["mainwp_branding_send_from_page"] ) ? "<a href='" . esc_url( $_POST["mainwp_branding_send_from_page"] ) . "'>" . esc_url( $_POST["mainwp_branding_send_from_page"] ) . "</a></p>\r\n\r\n" : "" );
			$mail .= "<p>Client Email: " . $current_user->user_email . " </p>\r\n\r\n";
			$mail .= "<p>Support Text:</p>\r\n\r\n";
			$mail .= "<p>" . $content . "</p>\r\n\r\n";

			if ( wp_mail( $email, $subject, $mail, $headers ) ) {
				;
			}

			return true;
		}

		return false;
	}

	function contact_support() {
		if ( current_user_can( 'subscriber' ) ) {
			return false;
		}
		?>
		<style>
			.mainwp_info-box-yellow {
				margin: 5px 0 15px;
				padding: .6em;
				background: #ffffe0;
				border: 1px solid #e6db55;
				border-radius: 3px;
				-moz-border-radius: 3px;
				-webkit-border-radius: 3px;
				clear: both;
			}
		</style>
		<?php
		if ( isset( $_POST['submit'] ) ) {
			if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], '_contactNonce' ) ) {
				return false;
			}
			$from_page = $_POST['mainwp_branding_send_from_page'];
			$back_link = get_option( 'mainwp_branding_message_return_sender' );
			$back_link = ! empty( $back_link ) ? $back_link : 'Go Back';
			$back_link = ! empty( $from_page ) ? '<a href="' . esc_url( $from_page ) . '" title="' . esc_attr( $back_link ) . '">' . esc_html( $back_link ) . '</a>' : '';

			if ( $this->send_support_mail() ) {
				$send_email_message = get_option( 'mainwp_branding_send_email_message' );
				if ( ! empty( $send_email_message ) ) {
					$send_email_message = stripslashes( $send_email_message );
				} else {
					$send_email_message = 'Your Message was successfully submitted.';
				}
			} else {
				$send_email_message = __( 'Error: send mail failed.' );
			}
			?>
			<div
				class="mainwp_info-box-yellow"><?php echo esc_html( $send_email_message ) . "&nbsp;&nbsp" . $back_link; ?></div><?php
		} else {
			$from_page = '';
			if ( isset( $_GET['from_page'] ) ) {
				$from_page = urldecode( $_GET['from_page'] );
			} else {
				$protocol  = isset( $_SERVER['HTTPS'] ) && strcasecmp( $_SERVER['HTTPS'], 'off' ) ? 'https://' : 'http://';
				$fullurl   = $protocol . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
				$from_page = urldecode( $fullurl );
			}

			$support_message = get_option( 'mainwp_branding_support_message' );
			$support_message = nl2br( stripslashes( $support_message ) );
			?>
			<form action="" method="post">
				<div style="width: 99%;">
					<h2><?php echo esc_html( $this->settings['contact_support_label'] ); ?></h2>

					<div style="height: auto; margin-bottom: 10px; text-align: left">
						<p><?php echo wp_kses_post( $support_message ); ?></p>
						<p><label for="mainwp_branding_contact_message_subject"><?php _e('Subject:', 'mainwp-child'); ?></label><br>
							<input type="text" name="mainwp_branding_contact_message_subject" style="width: 650px;"></p>
						<div style="max-width: 650px;">
							<label for="mainwp_branding_contact_message_content"><?php _e('Your Message:', 'mainwp-child'); ?></label><br>
							<?php
							remove_editor_styles(); // stop custom theme styling interfering with the editor
							wp_editor( '', 'mainwp_branding_contact_message_content', array(
									'textarea_name' => 'mainwp_branding_contact_message_content',
									'textarea_rows' => 10,
									'teeny'         => true,
									'wpautop'       => true,
									'media_buttons' => false,
								)
							);
							?>
						</div>
					</div>
					<br/>
					<?php
					$button_title = get_option( 'mainwp_branding_submit_button_title' );
					$button_title = ! empty( $button_title ) ? $button_title : __( 'Submit' );
					?>
					<input id="mainwp-branding-contact-support-submit" type="submit" name="submit"
					       value="<?php echo esc_attr( $button_title ); ?>"
					       class="button-primary button" style="float: left"/>
				</div>
				<input type="hidden" name="mainwp_branding_send_from_page"
				       value="<?php echo esc_url( $from_page ); ?>"/>
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( '_contactNonce' ) ); ?>"/>
			</form>
		<?php }
	}

	/**
	 * @param WP_Admin_Bar $wp_admin_bar
	 */
	public function add_support_button_in_top_admin_bar( $wp_admin_bar ) {
		if ( current_user_can( 'subscriber' ) ) {
			return false;
		}

		if ( isset( $_GET['from_page'] ) ) {
			$href = admin_url( 'admin.php?page=ContactSupport&from_page=' . urlencode( esc_url( $_GET['from_page'] ) ) );
		} else {
			$protocol = isset( $_SERVER['HTTPS'] ) && strcasecmp( $_SERVER['HTTPS'], 'off' ) ? 'https://' : 'http://';
			$fullurl  = $protocol . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
			$href     = admin_url( 'admin.php?page=ContactSupport&from_page=' . urlencode( esc_url( $fullurl ) ) );
		}
		$args = array(
			'id'     => 999,
			'title'  => $this->settings['contact_support_label'],
			'parent' => 'top-secondary',
			'href'   => $href,
			'meta'   => array(
				'class' => 'mainwp_branding_support_top_bar_button',
				'title' => $this->settings['contact_support_label'],
			),
		);

		$wp_admin_bar->add_node( $args );
	}

	public static function is_branding() {
		$cancelled_branding = ( get_option( 'mainwp_child_branding_disconnected' ) === 'yes' ) && ! get_option( 'mainwp_branding_preserve_branding' );
		if ( $cancelled_branding ) {
			return false;
		}

		// hide
		if ( 'T' === get_option( 'mainwp_branding_child_hide' ) ) {
			return true;
		}
		// branding
		$header = get_option( 'mainwp_branding_plugin_header' );
		if ( is_array( $header ) && ! empty( $header['name'] ) ) {
			return true;
		}

		return false;
	}

	public static function get_branding() {
		if ( self::is_branding() ) {
			$header = get_option( 'mainwp_branding_plugin_header' );

			return $header['name'];
		}

		return 'MainWP';
	}

	function check_update_child_plugin() {
		if ( $plugins = current_user_can( 'update_plugins' ) ) {
			$update_plugins = get_site_transient( 'update_plugins' );
			if ( ! empty( $update_plugins->response ) ) {
				$response = $update_plugins->response;
				if ( is_array( $response ) && isset( $response['mainwp-child/mainwp-child.php'] ) ) {
					return true;
				}
			}
		}

		return false;
	}

	function update_footer( $text ) {
		if ( self::is_branding() ) {
			if ( stripos( $_SERVER['REQUEST_URI'], 'update-core.php' ) !== false ) {
				?>
				<script>
					jQuery( document ).ready( function () {
						jQuery( 'input[type="checkbox"][value="mainwp-child/mainwp-child.php"]' ).closest( 'tr' ).remove();
					} );
				</script>
				<?php
			}

			if ( $this->check_update_child_plugin() ) {
				?>
				<script>
					jQuery( document ).ready( function () {
						var menu_update = jQuery( 'span.update-plugins' );
						var menu_count = jQuery( 'span.update-plugins > span.update-count' );
						if ( menu_count ) {
							var count = parseInt( menu_count.html() );
							if ( count > 1 ) {
								jQuery( 'span.update-plugins > span.update-count' ).each( function () {
									jQuery( this ).html( count - 1 );
								} );
								jQuery( 'span.update-plugins > span.plugin-count' ).each( function () {
									jQuery( this ).html( count - 1 );
								} );
								var title = menu_update.attr( 'title' ).replace( count, count - 1 );
								jQuery( 'span.update-plugins' ).each( function () {
									jQuery( this ).attr( 'title', title );
								} );

							} else if ( count == 1 ) {
								jQuery( 'span.update-plugins' ).remove();
							}
						}
					} );
				</script>
				<?php
			}
		}

		return $text;
	}

	public function branding_map_meta_cap( $caps, $cap, $user_id, $args ) {
		if ( 'T' === get_option( 'mainwp_branding_disable_change' ) ) {
			// disable: edit, update, install, active themes and plugins
			if ( false !== strpos( $cap, 'plugins' ) || false !== strpos( $cap, 'themes' ) || 'edit_theme_options' === $cap ) {
				$caps[0] = 'do_not_allow';
			}
		}
		if ( 'T' === get_option( 'mainwp_branding_disable_switching_theme' ) ) {
			// disable: theme switching
			if ( 'switch_themes' === $cap ) {
				$caps[0] = 'do_not_allow';
			}
		}
		return $caps;
	}

	public function branding_child_plugin( $plugins ) {
		if ( 'T' === get_option( 'mainwp_branding_child_hide' ) ) {
			foreach ( $plugins as $key => $value ) {
				$plugin_slug = basename( $key, '.php' );
				if ( 'mainwp-child' === $plugin_slug ) {
					unset( $plugins[ $key ] );
				}
			}

			return $plugins;
		}

		$header = get_option( 'mainwp_branding_plugin_header' );
		if ( is_array( $header ) && ! empty( $header['name'] ) ) {
			return $this->update_child_header( $plugins, $header );
		} else {
			return $plugins;
		}
	}

	public function update_child_header( $plugins, $header ) {
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

