<?php

class MainWP_Child_Branding {
	public static $instance = null;

	public $child_plugin_dir;
	public $child_branding_options = null;

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
        $this->child_branding_options = $this->init_options();
	}

    function init_options(){

        $opts = get_option( 'mainwp_child_branding_settings' );

        if ( !is_array( $opts ) ) {
            // compatible with old code
            $opts = array();

            $label = get_option( 'mainwp_branding_button_contact_label' );
            if ( ! empty( $label ) ) {
                $label = stripslashes( $label );
            }

            $opts['contact_label'] = $label;
            $opts['extra_settings']        = get_option( 'mainwp_branding_extra_settings' );
            MainWP_Helper::update_option( 'mainwp_child_branding_settings', $opts  );
        }

        if ( !isset($opts['contact_label']) || empty($opts['contact_label']) ) {
            $opts['contact_label'] = 'Contact Support';
        }

        $disconnected = isset( $opts['branding_disconnected'] ) ? $opts['branding_disconnected'] : '';
        $preserve_branding = isset( $opts['preserve_branding'] ) ? $opts['preserve_branding'] : '';
        $cancelled_branding = ( $disconnected === 'yes' ) && ! $preserve_branding;

        $opts['cancelled_branding'] = $cancelled_branding;
        $opts['branding_preserve_title'] = '';

        if ( ! $cancelled_branding ) {
            if (isset($opts['branding_header'])) {
                $branding_header = $opts['branding_header'];
                if ( is_array( $branding_header ) && isset( $branding_header['name'] ) && ! empty( $branding_header['name'] ) ) {
                    $opts['branding_preserve_title'] = stripslashes( $branding_header['name'] );
                }
            }
		}		
		
		$opts = apply_filters( 'mainwp_child_branding_init_options', $opts );		
        return $opts;
    }

    function get_extra_options() {
        $extra = array();
        if (is_array($this->child_branding_options) && isset($this->child_branding_options['extra_settings'])) {
            $extra = $this->child_branding_options['extra_settings'];
            if (!is_array($extra))
                $extra = array();
        }

        return $extra;
    }

	public function plugin_row_meta( $plugin_meta, $plugin_file, $child_plugin_slug ) {
		if ( $child_plugin_slug !== $plugin_file ) {
			return $plugin_meta;
		}

		if ( ! $this->is_branding() ) {
			return $plugin_meta;
		}
		// hide View details links
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

	public function child_deactivation() {
        // will remove later
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
            //'branding_header', // don't remove header
            'contact_label',
            'email_message',
            'message_return_sender',
            'submit_button_title',
            'extra_settings',
            'branding_ext_enabled',
        );

        foreach($brandingOptions_empty as $opt) {
            if (isset($this->child_branding_options[$opt])) {
                $this->child_branding_options[$opt] = '';
            }
        }
        MainWP_Helper::update_option( 'mainwp_child_branding_settings', $this->child_branding_options );

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

        $current_settings = $this->child_branding_options;
        $current_extra_setting = $this->child_branding_options['extra_settings'];

		//MainWP_Helper::update_option( 'mainwp_branding_ext_enabled', 'Y' );
        $current_settings['branding_ext_enabled'] = 'Y';

		$header = array(
			'name'        => $settings['child_plugin_name'],
			'description' => $settings['child_plugin_desc'],
			'author'      => $settings['child_plugin_author'],
			'authoruri'   => $settings['child_plugin_author_uri'],
			'pluginuri'   => isset($settings['child_plugin_uri']) ? $settings['child_plugin_uri'] : '',
		);

		MainWP_Helper::update_option( 'mainwp_branding_preserve_branding', $settings['child_preserve_branding'], 'yes' );  // to compatible with old version of child report plugin
		MainWP_Helper::update_option( 'mainwp_branding_plugin_header', $header, 'yes' ); // to compatible
//		MainWP_Helper::update_option( 'mainwp_branding_support_email', $settings['child_support_email'] );
//		MainWP_Helper::update_option( 'mainwp_branding_support_message', $settings['child_support_message'] );
//		MainWP_Helper::update_option( 'mainwp_branding_remove_restore', $settings['child_remove_restore'] );
//		MainWP_Helper::update_option( 'mainwp_branding_remove_setting', $settings['child_remove_setting'], 'yes' );
//		MainWP_Helper::update_option( 'mainwp_branding_remove_server_info', $settings['child_remove_server_info'] );
//		MainWP_Helper::update_option( 'mainwp_branding_remove_connection_detail', (isset($settings['child_remove_connection_detail']) ? $settings['child_remove_connection_detail'] : 0) );
//		MainWP_Helper::update_option( 'mainwp_branding_remove_wp_tools', $settings['child_remove_wp_tools'], 'yes' );
//		MainWP_Helper::update_option( 'mainwp_branding_remove_wp_setting', $settings['child_remove_wp_setting'], 'yes' );
//		MainWP_Helper::update_option( 'mainwp_branding_remove_permalink', $settings['child_remove_permalink'], 'yes' );
//		MainWP_Helper::update_option( 'mainwp_branding_button_contact_label', $settings['child_button_contact_label'], 'yes' );
//		MainWP_Helper::update_option( 'mainwp_branding_send_email_message', $settings['child_send_email_message'] );
//		MainWP_Helper::update_option( 'mainwp_branding_message_return_sender', $settings['child_message_return_sender'] );
//		MainWP_Helper::update_option( 'mainwp_branding_submit_button_title', $settings['child_submit_button_title'] );
//		if ( isset( $settings['child_disable_wp_branding'] ) && ( 'Y' === $settings['child_disable_wp_branding'] || 'N' === $settings['child_disable_wp_branding'] ) ) {
//			MainWP_Helper::update_option( 'mainwp_branding_disable_wp_branding', $settings['child_disable_wp_branding'] );
//		}

        $current_settings['preserve_branding'] =  $settings['child_preserve_branding'];
        $current_settings['branding_header'] =  $header;
        $current_settings['support_email'] =  $settings['child_support_email'];
        $current_settings['support_message'] =  $settings['child_support_message'];
        $current_settings['remove_restore'] =  $settings['child_remove_restore'];
        $current_settings['remove_setting'] =  $settings['child_remove_setting'];
        $current_settings['remove_server_info'] =  $settings['child_remove_server_info'];
        $current_settings['remove_connection_detail'] =  isset($settings['child_remove_connection_detail']) ? $settings['child_remove_connection_detail'] : 0 ;
        $current_settings['remove_wp_tools'] =  $settings['child_remove_wp_tools'];
        $current_settings['remove_wp_setting'] =  $settings['child_remove_wp_setting'];
        $current_settings['remove_permalink'] =  $settings['child_remove_permalink'];
        $current_settings['contact_label'] =  $settings['child_button_contact_label'];
        $current_settings['email_message'] =  $settings['child_send_email_message'];
        $current_settings['return_sender'] =  $settings['child_message_return_sender'];
        $current_settings['submit_button_title'] =  $settings['child_submit_button_title'];

        if ( isset( $settings['child_disable_wp_branding'] ) && ( 'Y' === $settings['child_disable_wp_branding'] || 'N' === $settings['child_disable_wp_branding'] ) ) {
			$current_settings['disable_wp_branding'] =  $settings['child_disable_wp_branding'];
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
			'login_image_link'              => $settings['child_login_image_link'],
			'login_image_title'              => $settings['child_login_image_title'],
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

		//MainWP_Helper::update_option( 'mainwp_branding_extra_settings', $extra_setting, 'yes' );
        $current_settings['extra_settings'] = $extra_setting;

        // keep it to compatible with old version of child reports plugin
		if ( $settings['child_plugin_hide'] ) {
			MainWP_Helper::update_option( 'mainwp_branding_child_hide', 'T', 'yes' );
		} else {
			MainWP_Helper::update_option( 'mainwp_branding_child_hide', '' );
		}
//
//		if ( $settings['child_show_support_button'] && ! empty( $settings['child_support_email'] ) ) {
//			MainWP_Helper::update_option( 'mainwp_branding_show_support', 'T' );
//		} else {
//			MainWP_Helper::update_option( 'mainwp_branding_show_support', '' );
//		}

//		if ( $settings['child_disable_change'] ) {
//			MainWP_Helper::update_option( 'mainwp_branding_disable_change', 'T' );
//		} else {
//			MainWP_Helper::update_option( 'mainwp_branding_disable_change', '' );
//		}

//		if ( $settings['child_disable_switching_theme'] ) {
//			MainWP_Helper::update_option( 'mainwp_branding_disable_switching_theme', 'T' );
//		} else {
//			MainWP_Helper::update_option( 'mainwp_branding_disable_switching_theme', '' );
//		}

        $current_settings['hide'] = $settings['child_plugin_hide'] ? 'T' : '';
        $current_settings['show_support'] = ( $settings['child_show_support_button'] && !empty($settings['child_support_email']) ) ? 'T' : '';
        $current_settings['disable_change'] = $settings['child_disable_change'] ? 'T' : '';
        $current_settings['disable_switching_theme'] = $settings['child_disable_switching_theme'] ? 'T' : '';

        MainWP_Helper::update_option( 'mainwp_child_branding_settings', $current_settings );

		$information['result'] = 'SUCCESS';

		return $information;
	}

	static function uploadImage( $img_url ) {
		include_once( ABSPATH . 'wp-admin/includes/file.php' ); //Contains download_url
		 global $mainWPChild;
        add_filter( 'http_request_args', array( $mainWPChild, 'http_request_reject_unsafe_urls' ), 99, 2 );
		//Download $img_url
		$temporary_file = download_url( $img_url );
        remove_filter( 'http_request_args', array( $mainWPChild, 'http_request_reject_unsafe_urls' ), 99, 2 );

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

		$extra_setting = $this->get_extra_options();

        // to hide updates notice
        if (is_admin()) {
            // back end
            add_action( 'in_admin_footer', array( $this, 'in_admin_footer' ) );
        } else if (is_user_logged_in()) {
            // front end
            add_action( 'wp_after_admin_bar_render', array( $this, 'after_admin_bar_render' ));
        }
        $opts = $this->child_branding_options;

		$cancelled_branding = $opts['cancelled_branding'];
		if ( $cancelled_branding ) {
			return;
		}

        // enable branding in case child plugin deactive and re-activated
		add_filter( 'all_plugins', array( $this, 'modify_plugin_header' ) );

		if ( $this->is_branding() ) {
			add_filter( 'site_transient_update_plugins', array( &$this, 'remove_update_nag' ) );
            add_filter( 'mainwp_child_hide_update_notice', array( &$this, 'hide_update_notice' ) );
		}

        if ( !isset($opts['branding_ext_enabled']) || $opts['branding_ext_enabled'] !== 'Y' ) {
			return;
		}

		add_filter( 'map_meta_cap', array( $this, 'branding_map_meta_cap' ), 10, 5 );

		if ( 'T' === $opts['disable_change']) {

			// Disable the wordpress plugin update notifications
			remove_action('load-update-core.php', 'wp_update_plugins');
			add_filter('pre_site_transient_update_plugins', '__return_null');

			// Disable the wordpress theme update notifications
			remove_action('load-update-core.php', 'wp_update_themes');
			add_filter('pre_site_transient_update_themes', ( $func = function($a){ return null;} ));

			// Disable the wordpress core update notifications
			add_action('after_setup_theme', 'remove_core_updates');
			function remove_core_updates() {
				add_action('init', ( $func = function($a){ remove_action( 'wp_version_check', 'wp_version_check' );} ), 2);
				add_filter('pre_option_update_core', '__return_null');
				add_filter('pre_site_transient_update_core', '__return_null');
			}

			add_action( 'admin_head', array( &$this, 'admin_head_hide_elements' ), 15 );
			add_action( 'admin_menu', array($this, 'branding_redirect' ), 9);
		}

		// to fix
		add_action( 'admin_menu', array( &$this, 'admin_menu' ) );//
		if ( !isset($opts['disable_wp_branding']) || $opts['disable_wp_branding'] !== 'Y' ) {
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
			add_filter( 'login_headerurl', array( &$this, 'custom_login_headerurl' ) );
			add_filter( 'login_headertext', array( &$this, 'custom_login_headertitle' ) );
			add_action( 'wp_head', array( &$this, 'custom_favicon_frontend' ) );
			if ( isset( $extra_setting['dashboard_footer'] ) && ! empty( $extra_setting['dashboard_footer'] ) ) {
				//remove_filter( 'update_footer', 'core_update_footer' );
				add_filter( 'update_footer', array( &$this, 'core_update_footer' ), 14 );
                add_filter( 'admin_footer_text', array( &$this, 'admin_footer_text' ), 14 );
			}

			if ( isset( $extra_setting['hide_nag'] ) && ! empty( $extra_setting['hide_nag'] ) ) {
				add_action( 'admin_init', array($this, 'admin_init'));
			}

			add_action( 'admin_menu', array( &$this, 'remove_default_post_metaboxes' ) );
			add_action( 'admin_menu', array( &$this, 'remove_default_page_metaboxes' ) );
		}
	}


	public function admin_init() {
		remove_action( 'admin_notices', 'update_nag', 3 );
	}

    // to fix conflict with other plugin
    function admin_menu() {
        $allow_contact = apply_filters('mainwp_branding_role_cap_enable_contact_form', false);
        if ( $allow_contact ) {
            ; // ok
        } else if ( !current_user_can( 'administrator' ) ) {
			return false;
		}

        $extra_setting = $this->get_extra_options();
		if ( empty ( $extra_setting ) ) {
			return false;
		}
        $opts = $this->child_branding_options;

		if ( 'T' === $opts['show_support'] ) {
			$title = $opts['contact_label'];
			if ( isset( $extra_setting['show_button_in'] ) && ( 2 === (int) $extra_setting['show_button_in'] || 3 === (int) $extra_setting['show_button_in'] ) ) {
				$title = $opts['contact_label'];
				add_menu_page( $title, $title, 'read', 'ContactSupport2', array(
					$this,
					'contact_support',
				), '', '2.0001' );
			}

			if ( isset( $extra_setting['show_button_in'] ) && ( 1 === $extra_setting['show_button_in'] || 3 === $extra_setting['show_button_in'] ) ) {
				add_submenu_page( null, $title, $opts['contact_label'], 'read', 'ContactSupport', array(
					$this,
					'contact_support',
				) );
				add_action( 'admin_bar_menu', array( $this, 'add_support_button_in_top_admin_bar' ), 100 );
			}
		}

	}

	function remove_default_post_metaboxes() {
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


	function custom_post_columns( $defaults ) {
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

	function manage_my_category_columns( $defaults ) {
		$extra_setting = $this->get_extra_options();

		if ( isset( $extra_setting['hide_metabox_post_slug'] ) && $extra_setting['hide_metabox_post_slug'] ) {
			unset( $defaults['slug'] );
		}

		return $defaults;
	}

	function remove_default_page_metaboxes() {
		$extra_setting = $this->get_extra_options();

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
		$extra_setting = $this->get_extra_options();

		if ( isset( $extra_setting['hide_metabox_page_comments'] ) && $extra_setting['hide_metabox_page_comments'] ) {
			unset( $defaults['comments'] );
		}
		if ( isset( $extra_setting['hide_metabox_page_author'] ) && $extra_setting['hide_metabox_page_author'] ) {
			unset( $defaults['author'] );
		}

		return $defaults;
	}

	public function branding_redirect() {
		$pos1 = stripos( $_SERVER['REQUEST_URI'], 'update-core.php' );
		$pos2 = stripos( $_SERVER['REQUEST_URI'], 'plugins.php' );
		if ( false !== $pos1 || false !== $pos2 ) {
			wp_redirect( get_option( 'siteurl' ) . '/wp-admin/index.php' );
			exit();
		}
	}

	function admin_head_hide_elements() {
		?><script type="text/javascript">
			document.addEventListener("DOMContentLoaded", function(event) {
				document.getElementById("wp-admin-bar-updates").outerHTML = '';
				document.getElementById("menu-plugins").outerHTML = '';
				var els_core = document.querySelectorAll("a[href='update-core.php']");
				for (var i = 0, l = els_core.length; i < l; i++) {
					var el = els_core[i];
					el.parentElement.innerHTML = '';
				}
			});
		</script><?php
	}

	function core_update_footer() {
		echo ''; // it clear version text
	}

	function admin_footer_text() {
		$extra_setting = $this->get_extra_options();
		if ( isset( $extra_setting['dashboard_footer'] ) && ! empty( $extra_setting['dashboard_footer'] ) ) {
			echo wp_kses_post( nl2br( stripslashes( $extra_setting['dashboard_footer'] ) ) );
		}
	}

	function custom_favicon_frontend() {
		$extra_setting = $this->get_extra_options();
		if ( isset( $extra_setting['favico_image']['url'] ) && ! empty( $extra_setting['favico_image']['url'] ) ) {
			$favico = $extra_setting['favico_image']['url'];
			echo '<link rel="shortcut icon" href="' . esc_url( $favico ) . '"/>' . "\n";
		}
	}

	function custom_login_logo() {
		$extra_setting = $this->get_extra_options();
		if ( isset( $extra_setting['login_image']['url'] ) && ! empty( $extra_setting['login_image']['url'] ) ) {
			$login_logo = $extra_setting['login_image']['url'];
			echo '<style type="text/css">
                    #login h1 a { background-image: url(\'' . esc_url( $login_logo ) . '\') !important; height:70px !important; width:310px !important; background-size: auto auto !important; }
                </style>';
		}
	}

	function custom_login_headerurl( $value ) {

		$extra_setting = $this->get_extra_options();
		if ( isset( $extra_setting['login_image_link'] ) && ! empty( $extra_setting['login_image_link'] ) ) {
			return $extra_setting['login_image_link'];
		}

		return $value;
	}

	function custom_login_headertitle( $value ) {

		$extra_setting = $this->get_extra_options();
		if ( isset( $extra_setting['login_image_title'] ) && ! empty( $extra_setting['login_image_title'] ) ) {
			return $extra_setting['login_image_title'];
		}

		return $value;
	}

	function custom_gettext( $translations, $text, $domain = 'default' ) {
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

	function custom_admin_css() {
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
			echo '<style>' . MainWP_Helper::parse_css( $header_css ) . '</style>';
		}
	}

	function custom_login_css() {
		$extra_setting = $this->get_extra_options();
		if ( isset( $extra_setting['login_css'] ) && ! empty( $extra_setting['login_css'] ) ) {
			echo '<style>' . MainWP_Helper::parse_css( $extra_setting['login_css'] ) . '</style>';
		}
	}

	function custom_the_generator( $generator, $type = '' ) {
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

	public function branding_global_footer() {
		$extra_setting = $this->get_extra_options();
		if ( isset( $extra_setting['global_footer'] ) && ! empty( $extra_setting['global_footer'] ) ) {
			echo wp_kses_post( nl2br( stripslashes( $extra_setting['global_footer'] ) ) );
		}
	}

	public function send_support_mail() {
		$email   = $this->child_branding_options['support_email'];
		$sub = wp_kses_post( nl2br( stripslashes( $_POST['mainwp_branding_contact_message_subject'] ) ) );
        $from = trim($_POST['mainwp_branding_contact_send_from']);
		$subject = !empty( $sub ) ? $sub : "MainWP - Support Contact";
		$content = wp_kses_post( nl2br( stripslashes( $_POST['mainwp_branding_contact_message_content'] ) ) );
        $mail = $headers = '';
		if ( ! empty( $_POST['mainwp_branding_contact_message_content'] ) && ! empty( $email ) ) {
			global $current_user;
			$headers .= "Content-Type: text/html;charset=utf-8\r\n";
            if (!empty($from))
                $headers .= "From: \"" . $from . "\" <" . $from . ">\r\n";
			$mail .= "<p>Support Email from: <a href='" . site_url() . "'>" . site_url() . "</a></p>\r\n\r\n";
			$mail .= "<p>Sent from WordPress page: " . ( ! empty( $_POST["mainwp_branding_send_from_page"] ) ? "<a href='" . esc_url( $_POST["mainwp_branding_send_from_page"] ) . "'>" . esc_url( $_POST["mainwp_branding_send_from_page"] ) . "</a></p>\r\n\r\n" : "" );
			$mail .= "<p>Client Email: " . $current_user->user_email . " </p>\r\n\r\n";
			$mail .= "<p>Support Text:</p>\r\n\r\n";
			$mail .= "<p>" . $content . "</p>\r\n\r\n";

			if ( @wp_mail( $email, $subject, $mail, $headers ) ) {
				;
			}

			return true;
		}

		return false;
	}

	function contact_support() {
        global $current_user;
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
        $opts = $this->child_branding_options;

		if ( isset( $_POST['submit'] ) ) {
			if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], '_contactNonce' ) ) {
				return false;
			}
			$from_page = $_POST['mainwp_branding_send_from_page'];
			$back_link = $opts['message_return_sender'];
			$back_link = ! empty( $back_link ) ? $back_link : 'Go Back';
			$back_link = ! empty( $from_page ) ? '<a href="' . esc_url( $from_page ) . '" title="' . esc_attr( $back_link ) . '">' . esc_html( $back_link ) . '</a>' : '';

			if ( $this->send_support_mail() ) {
				$send_email_message = isset( $opts['send_email_message'] ) ? $opts['send_email_message'] : '';
				if ( ! empty( $send_email_message ) ) {
					$send_email_message = stripslashes( $send_email_message );
				} else {
					$send_email_message = __( 'Message has been submitted successfully.', 'mainwp-child' );
				}
			} else {
				$send_email_message = __( 'Sending email failed!', 'mainwp-child' );
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

			$support_message = $opts['support_message'];
			$support_message = nl2br( stripslashes( $support_message ) );
            $from_email = $current_user ? $current_user->user_email : '';
			?>
			<form action="" method="post">
				<div style="width: 99%;">
					<h2><?php echo esc_html( $opts['contact_label'] ); ?></h2>

					<div style="height: auto; margin-bottom: 10px; text-align: left">
						<p><?php echo wp_kses_post( $support_message ); ?></p>
						<p><label for="mainwp_branding_contact_message_subject"><?php _e('Subject:', 'mainwp-child'); ?></label><br>
							<input type="text" id="mainwp_branding_contact_message_subject" name="mainwp_branding_contact_message_subject" style="width: 650px;"></p>
                        <p><label for="mainwp_branding_contact_send_from"><?php _e('From:', 'mainwp-child'); ?></label><br>
                            <input type="text" id="mainwp_branding_contact_send_from" name="mainwp_branding_contact_send_from" style="width: 650px;" value="<?php echo esc_html( $from_email ); ?>"></p>
						<div style="max-width: 650px;">
							<label for="mainwp_branding_contact_message_content"><?php _e('Your message:', 'mainwp-child'); ?></label><br>
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
					$button_title = $opts['submit_button_title'];
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
        $allow_contact = apply_filters('mainwp_branding_role_cap_enable_contact_form', false);
        if ( $allow_contact ) {
            ; // ok
        } else if ( !current_user_can( 'administrator' ) ) {
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

	public function is_branding() {
        $opts = $this->child_branding_options;

        if (!isset($opts['branding_ext_enabled']) || $opts['branding_ext_enabled'] !== 'Y') {
            return false;
        }

        $is_hide = isset( $opts['hide'] ) ? $opts['hide'] : '';
        $cancelled_branding = $opts['cancelled_branding'];
        $branding_header = isset( $opts['branding_header'] ) ? $opts['branding_header'] : '';

        if ( $cancelled_branding ) {
            return false;
        }
        // hide
        if ( 'T' === $is_hide ) {
            return true;
        }
        if ( is_array( $branding_header ) && !empty( $branding_header['name'] ) ) {
            return true;
        }
        return false;
	}

    public function get_branding_title() {
		if ( $this->is_branding() ) {
            $branding_header = $this->child_branding_options['branding_header'];
			return $branding_header['name'];
		}
		return '';
	}

    public function get_branding_options() {
        return $this->child_branding_options;
    }

    public function save_branding_options( $name, $val ) {
        $this->child_branding_options[$name] = $val;
        MainWP_Helper::update_option( 'mainwp_child_branding_settings', $this->child_branding_options );
    }

    public function after_admin_bar_render() {

        $hide_slugs = apply_filters('mainwp_child_hide_update_notice' , array());

        if (!is_array($hide_slugs))
            $hide_slugs = array();

        if (count($hide_slugs) == 0) {
            return;
        }

        if (!function_exists('get_plugin_updates')) {
            include_once( ABSPATH . '/wp-admin/includes/update.php' );
        }

        $count_hide = 0;

        $updates = get_plugin_updates();
        if (is_array($updates)) {
            foreach($updates as $slug => $data) {
                if (in_array($slug, $hide_slugs)) {
                    $count_hide++;
                }
            }
        }

        if ( $count_hide == 0) {
            return;
        }
        // js for front end
        ?>
        <script type="text/javascript">
            var mainwpCountHide = <?php echo esc_attr($count_hide); ?>;
			document.addEventListener("DOMContentLoaded", function(event) {
                var $adminBarUpdates              = document.querySelector( '#wp-admin-bar-updates .ab-label' ),
                    itemCount;

                if (typeof($adminBarUpdates) !== 'undefined' && $adminBarUpdates !== null) {
                    itemCount = $adminBarUpdates.textContent;
                    itemCount = parseInt(itemCount);

                    itemCount -= mainwpCountHide;
                    if (itemCount < 0)
                        itemCount = 0;

                    $adminBarUpdates.textContent = itemCount;
                }
			});
		</script><?php

    }

    public function in_admin_footer() {

        $hide_slugs = apply_filters('mainwp_child_hide_update_notice' , array());

        if (!is_array($hide_slugs))
            $hide_slugs = array();

        $count_hide = 0;

        $updates = get_plugin_updates();
        if (is_array($updates)) {
            foreach($updates as $slug => $data) {
                if (in_array($slug, $hide_slugs)) {
                    $count_hide++;
                }
            }
        }

        if ( $count_hide == 0) {
            return;
        }

        // to tweaks counting of update notification display
        // js for admin end
        ?>
        <script type="text/javascript">
            var mainwpCountHide = <?php echo esc_attr($count_hide); ?>;
			document.addEventListener("DOMContentLoaded", function(event) {
                if (typeof(pagenow) !== 'undefined' && pagenow == 'plugins') {
                    <?php
                    // hide update notice row
                    if (in_array('mainwp-child/mainwp-child.php', $hide_slugs)) {
                        ?>
                        var el = document.querySelector('tr#mainwp-child-update');
                        if (typeof(el) !== 'undefined' && el !== null) {
                            el.style.display = 'none';
                        }
                        <?php
                    }
                    // hide update notice row
                    if (in_array('mainwp-child-reports/mainwp-child-reports.php', $hide_slugs)) {
                        ?>
                        var el = document.querySelector('tr#mainwp-child-reports-update');
                        if (typeof(el) !== 'undefined' && el !== null) {
                            el.style.display = 'none';
                        }
                        <?php
                    }
                    ?>
                }

                if (mainwpCountHide > 0) {
                    jQuery( document ).ready( function () {

                        var $adminBarUpdates              = jQuery( '#wp-admin-bar-updates' ),
                            $pluginsNavMenuUpdateCount    = jQuery( 'a[href="plugins.php"] .update-plugins' ),
                            itemCount;
                        itemCount = $adminBarUpdates.find( '.ab-label' ).text();
                        itemCount -= mainwpCountHide;
                        if (itemCount < 0)
                            itemCount = 0;

                        itemPCount = $pluginsNavMenuUpdateCount.find( '.plugin-count' ).text();
                        itemPCount -= mainwpCountHide;

                        if (itemPCount < 0)
                            itemPCount = 0;

                        $adminBarUpdates.find( '.ab-label' ).text(itemCount);
                        $pluginsNavMenuUpdateCount.find( '.plugin-count' ).text( itemPCount );

                    });
                }
			});
		</script><?php
    }

	public function branding_map_meta_cap( $caps, $cap, $user_id, $args ) {
		if ( isset($this->child_branding_options['disable_switching_theme']) && 'T' === $this->child_branding_options['disable_switching_theme'] ) {
			// disable: theme switching
			if ( 'switch_themes' === $cap ) {
				$caps[0] = 'do_not_allow';
			}
		}
		return $caps;
	}

	public function modify_plugin_header( $plugins ) {
        $opts = $this->child_branding_options;
        if ( is_array($opts) ) {
            $is_hide = isset( $opts['hide'] ) ? $opts['hide'] : '';
            $cancelled_branding = $opts['cancelled_branding'];
            $branding_header = isset( $opts['branding_header'] ) ? $opts['branding_header'] : '';

            if ( $cancelled_branding ) {
                return $plugins;
            }

            if ( $is_hide === 'T' ) {
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

    function hide_update_notice( $slugs ) {
        $slugs[] = 'mainwp-child/mainwp-child.php';
        return $slugs;
    }


	function remove_update_nag( $value ) {
        if ( isset( $_POST['mainwpsignature'] ) ) {
			return $value;
		}

        if (! MainWP_Helper::is_screen_with_update()) {
            return $value;
        }

		if ( isset( $value->response['mainwp-child/mainwp-child.php'] ) ) {
			unset( $value->response['mainwp-child/mainwp-child.php'] );
		}
		return $value;
	}

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

