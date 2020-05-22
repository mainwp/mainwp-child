<?php

namespace MainWP\Child;

//phpcs:ignore Generic.Metrics.CyclomaticComplexity -- complex functions/features.

class MainWP_Child_Stats {

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

	/**
	 *
	 * Show stats without login - only allowed while no account is added yet.
	 */
	public function get_site_stats_no_auth( $information = array() ) {
		if ( get_option( 'mainwp_child_pubkey' ) ) {
			$hint = '<br/>' . __( 'Hint: Go to the child site, deactivate and reactivate the MainWP Child plugin and try again.', 'mainwp-child' );
			MainWP_Helper::error( __( 'This site already contains a link. Please deactivate and reactivate the MainWP plugin.', 'mainwp-child' ) . $hint );
		}

		global $wp_version;
		$information['version']   = MainWP_Child::$version;
		$information['wpversion'] = $wp_version;
		$information['wpe']       = MainWP_Helper::is_wp_engine() ? 1 : 0;
		MainWP_Helper::write( $information );
	}


	public function default_option_active_plugins( $default ) {
		if ( ! is_array( $default ) ) {
			$default = array();
		}
		if ( ! in_array( 'managewp/init.php', $default ) ) {
			$default[] = 'managewp/init.php';
		}

		return $default;
	}

	// Show stats.
	public function get_site_stats( $information = array(), $exit = true ) {

		if ( $exit ) {
			$this->update_external_settings();
		}

		MainWP_Child_Branding::instance()->save_branding_options( 'branding_disconnected', '' );
		if ( isset( $_POST['server'] ) ) {
			MainWP_Helper::update_option( 'mainwp_child_server', $_POST['server'] );
		}

		MainWP_Child_Plugins_Check::may_outdate_number_change();

		$this->stats_get_info( $information );

		include_once ABSPATH . '/wp-admin/includes/update.php';

		$timeout = 3 * 60 * 60;
		MainWP_Helper::set_limit( $timeout );

		// Check for new versions.
		$information['wp_updates'] = $this->stats_wp_update();

		add_filter( 'default_option_active_plugins', array( &$this, 'default_option_active_plugins' ) );
		add_filter( 'option_active_plugins', array( &$this, 'default_option_active_plugins' ) );

		$premiumPlugins = array();
		$premiumThemes  = array();

		// First check for new premium updates.
		$this->check_premium_updates( $information, $premiumPlugins, $premiumThemes );

		remove_filter( 'default_option_active_plugins', array( &$this, 'default_option_active_plugins' ) );
		remove_filter( 'option_active_plugins', array( &$this, 'default_option_active_plugins' ) );

		$information['plugin_updates'] = $this->stats_plugin_update( $premiumPlugins );

		$information['theme_updates'] = $this->stats_theme_update( $premiumThemes );

		$information['translation_updates'] = $this->stats_translation_updates();

		$information['recent_comments'] = MainWP_Child_Comments::get_instance()->get_recent_comments( array( 'approve', 'hold' ), 5 );

		$recent_number = $this->get_recent_number();

		$information['recent_posts']   = MainWP_Child_Posts::get_instance()->get_recent_posts( array( 'publish', 'draft', 'pending', 'trash', 'future' ), $recent_number );
		$information['recent_pages']   = MainWP_Child_Posts::get_instance()->get_recent_posts( array( 'publish', 'draft', 'pending', 'trash', 'future' ), $recent_number, 'page' );
		$information['securityIssues'] = MainWP_Security::get_stats_security();

		// Directory listings!
		$information['directories'] = $this->scan_dir( ABSPATH, 3 );
		$information['categories']  = $this->stats_get_categories();

		$totalsize = $this->stats_get_total_size();
		if ( ! empty( $totalsize ) ) {
			$information['totalsize'] = $totalsize;
		}

		$information['dbsize'] = MainWP_Child_DB::get_size();

		$max_his                = MainWP_Connect::instance()->get_max_history();
		$auths                  = get_option( 'mainwp_child_auth' );
		$information['extauth'] = ( $auths && isset( $auths[ $max_his ] ) ? $auths[ $max_his ] : null );

		$information['plugins'] = $this->get_all_plugins_int( false );
		$information['themes']  = $this->get_all_themes_int( false );

		if ( isset( $_POST['optimize'] ) && ( '1' === $_POST['optimize'] ) ) {
			$information['users'] = MainWP_Child_Users::get_instance()->get_all_users_int( 500 );
		}

		if ( isset( $_POST['primaryBackup'] ) && ! empty( $_POST['primaryBackup'] ) ) {
			$primary_bk                           = $_POST['primaryBackup'];
			$information['primaryLasttimeBackup'] = MainWP_Utility::get_lasttime_backup( $primary_bk );
		}

		$last_post = wp_get_recent_posts( array( 'numberposts' => absint( '1' ) ) );
		if ( isset( $last_post[0] ) ) {
			$last_post = $last_post[0];
		}
		if ( isset( $last_post ) && isset( $last_post['post_modified_gmt'] ) ) {
			$information['last_post_gmt'] = strtotime( $last_post['post_modified_gmt'] );
		}
		$information['mainwpdir']            = ( MainWP_Utility::validate_mainwp_dir() ? 1 : - 1 );
		$information['uniqueId']             = get_option( 'mainwp_child_uniqueId', '' );
		$information['plugins_outdate_info'] = MainWP_Child_Plugins_Check::instance()->get_plugins_outdate_info();
		$information['themes_outdate_info']  = MainWP_Child_Themes_Check::instance()->get_themes_outdate_info();

		if ( isset( $_POST['user'] ) ) {
			$user = get_user_by( 'login', $_POST['user'] );
			if ( $user && property_exists( $user, 'ID' ) && $user->ID ) {
				$information['admin_nicename']  = $user->data->user_nicename;
				$information['admin_useremail'] = $user->data->user_email;
			}
		}

		try {
			do_action( 'mainwp_child_site_stats' );
		} catch ( \Exception $e ) {
			MainWP_Helper::log_debug( $e->getMessage() );
		}

		if ( isset( $_POST['othersData'] ) ) {
			$this->stats_others_data( $information );
		}

		if ( $exit ) {
			MainWP_Helper::write( $information );
		}

		return $information;
	}

	private function stats_others_data( &$information ) {

		$othersData = json_decode( stripslashes( $_POST['othersData'] ), true );
		if ( ! is_array( $othersData ) ) {
			$othersData = array();
		}

		if ( isset( $othersData['wpvulndbToken'] ) ) {
			$wpvulndb_token = get_option( 'mainwp_child_wpvulndb_token', '' );
			if ( $wpvulndb_token != $othersData['wpvulndbToken'] ) {
				MainWP_Helper::update_option( 'mainwp_child_wpvulndb_token', $othersData['wpvulndbToken'] );
			}
		}

		try {
			$information = apply_filters_deprecated( 'mainwp-site-sync-others-data', array( $information, $othersData ), '4.0.7.1', 'mainwp_site_sync_others_data' );
			$information = apply_filters( 'mainwp_site_sync_others_data', $information, $othersData );

		} catch ( \Exception $e ) {
			MainWP_Helper::log_debug( $e->getMessage() );
		}
	}

	private function stats_translation_updates() {
		$results = array();

		$translation_updates = wp_get_translation_updates();
		if ( ! empty( $translation_updates ) ) {
			foreach ( $translation_updates as $translation_update ) {
				$new_translation_update = array(
					'type'     => $translation_update->type,
					'slug'     => $translation_update->slug,
					'language' => $translation_update->language,
					'version'  => $translation_update->version,
				);
				if ( 'plugin' === $translation_update->type ) {
					$all_plugins = get_plugins();
					foreach ( $all_plugins as $file => $plugin ) {
						$path = dirname( $file );
						if ( $path == $translation_update->slug ) {
							$new_translation_update['name'] = $plugin['Name'];
							break;
						}
					}
				} elseif ( 'theme' === $translation_update->type ) {
					$theme                          = wp_get_theme( $translation_update->slug );
					$new_translation_update['name'] = $theme->name;
				} elseif ( ( 'core' === $translation_update->type ) && ( 'default' === $translation_update->slug ) ) {
					$new_translation_update['name'] = 'WordPress core';
				}

				$results[] = $new_translation_update;
			}
		}
		return $results;
	}

	private function stats_theme_update( $premiumThemes ) {

		$results = array();

		if ( null !== $this->filterFunction ) {
			add_filter( 'pre_site_transient_update_themes', $this->filterFunction, 99 );
		}

		wp_update_themes();
		include_once ABSPATH . '/wp-admin/includes/theme.php';
		$theme_updates = MainWP_Child_Updates::get_instance()->upgrade_get_theme_updates();
		if ( is_array( $theme_updates ) ) {
			foreach ( $theme_updates as $slug => $theme_update ) {
				$name = ( is_array( $theme_update ) ? $theme_update['Name'] : $theme_update->Name );
				if ( in_array( $name, $premiumThemes ) ) {
					continue;
				}
				$results[ $slug ] = $theme_update;
			}
		}
		if ( null !== $this->filterFunction ) {
			remove_filter( 'pre_site_transient_update_themes', $this->filterFunction, 99 );
		}

		// to fix premium themes update.
		$cached_themes_update = get_site_transient( 'mainwp_update_themes_cached' );
		if ( is_array( $cached_themes_update ) && ( count( $cached_themes_update ) > 0 ) ) {

			foreach ( $cached_themes_update as $slug => $theme_update ) {
				$name = ( is_array( $theme_update ) ? $theme_update['Name'] : $theme_update->Name );
				if ( in_array( $name, $premiumThemes ) ) {
					continue;
				}
				if ( isset( $results[ $slug ] ) ) {
					continue;
				}
				$results[ $slug ] = $theme_update;
			}
		}

		return $results;
	}

	private function stats_get_info( &$information ) {

		global $wp_version;

		$information['version']   = MainWP_Child::$version;
		$information['wpversion'] = $wp_version;
		$information['siteurl']   = get_option( 'siteurl' );
		$information['wpe']       = MainWP_Helper::is_wp_engine() ? 1 : 0;
		$theme_name               = wp_get_theme()->get( 'Name' );
		$information['site_info'] = array(
			'wpversion'      => $wp_version,
			'debug_mode'     => ( defined( 'WP_DEBUG' ) && true === WP_DEBUG ) ? true : false,
			'phpversion'     => phpversion(),
			'child_version'  => MainWP_Child::$version,
			'memory_limit'   => MainWP_Child_Server_Information::get_php_memory_limit(),
			'mysql_version'  => MainWP_Child_Server_Information::get_my_sql_version(),
			'themeactivated' => $theme_name,
			'ip'             => $_SERVER['SERVER_ADDR'],
		);

		// Try to switch to SSL if SSL is enabled in between!
		$pubkey = get_option( 'mainwp_child_pubkey' );
		$nossl  = get_option( 'mainwp_child_nossl' );
		if ( 1 == $nossl ) {
			if ( isset( $pubkey ) && MainWP_Helper::is_ssl_enabled() ) {
				MainWP_Helper::update_option( 'mainwp_child_nossl', 0, 'yes' );
				$nossl = 0;
			}
		}
		$information['nossl'] = ( 1 == $nossl ? 1 : 0 );
	}

	private function stats_wp_update() {
		global $wp_version;
		$result = null;
		// Check for new versions.
		if ( null !== $this->filterFunction ) {
			add_filter( 'pre_site_transient_update_core', $this->filterFunction, 99 );
		}
		if ( null !== $this->filterFunction ) {
			add_filter( 'pre_transient_update_core', $this->filterFunction, 99 );
		}
		wp_version_check();
		$core_updates = get_core_updates();
		if ( is_array( $core_updates ) && count( $core_updates ) > 0 ) {
			foreach ( $core_updates as $core_update ) {
				if ( 'latest' === $core_update->response ) {
					break;
				}
				if ( 'upgrade' === $core_update->response && version_compare( $wp_version, $core_update->current, '<=' ) ) {
					$result = $core_update->current;
				}
			}
		}

		if ( null !== $this->filterFunction ) {
			remove_filter( 'pre_site_transient_update_core', $this->filterFunction, 99 );
		}
		if ( null !== $this->filterFunction ) {
			remove_filter( 'pre_transient_update_core', $this->filterFunction, 99 );
		}
	}

	private function check_premium_updates( &$information, &$premiumPlugins, &$premiumThemes ) {
		// First check for new premium updates.
		$update_check = apply_filters( 'mwp_premium_update_check', array() );
		if ( ! empty( $update_check ) ) {
			foreach ( $update_check as $updateFeedback ) {
				if ( is_array( $updateFeedback['callback'] ) && isset( $updateFeedback['callback'][0] ) && isset( $updateFeedback['callback'][1] ) ) {
					call_user_func( array( $updateFeedback['callback'][0], $updateFeedback['callback'][1] ) );
				} elseif ( is_string( $updateFeedback['callback'] ) ) {
					call_user_func( $updateFeedback['callback'] );
				}
			}
		}

		$informationPremiumUpdates = apply_filters( 'mwp_premium_update_notification', array() );
		$premiumPlugins            = array();
		$premiumThemes             = array();
		if ( is_array( $informationPremiumUpdates ) ) {
			$premiumUpdates                  = array();
			$informationPremiumUpdatesLength = count( $informationPremiumUpdates );
			for ( $i = 0; $i < $informationPremiumUpdatesLength; $i ++ ) {
				if ( ! isset( $informationPremiumUpdates[ $i ]['new_version'] ) ) {
					continue;
				}
				$slug = ( isset( $informationPremiumUpdates[ $i ]['slug'] ) ? $informationPremiumUpdates[ $i ]['slug'] : $informationPremiumUpdates[ $i ]['Name'] );

				if ( 'plugin' === $informationPremiumUpdates[ $i ]['type'] ) {
					$premiumPlugins[] = $slug;
				} elseif ( 'theme' === $informationPremiumUpdates[ $i ]['type'] ) {
					$premiumThemes[] = $slug;
				}

				$new_version = $informationPremiumUpdates[ $i ]['new_version'];

				unset( $informationPremiumUpdates[ $i ]['old_version'] );
				unset( $informationPremiumUpdates[ $i ]['new_version'] );

				if ( ! isset( $information['premium_updates'] ) ) {
					$information['premium_updates'] = array();
				}

				$information['premium_updates'][ $slug ]           = $informationPremiumUpdates[ $i ];
				$information['premium_updates'][ $slug ]['update'] = (object) array(
					'new_version' => $new_version,
					'premium'     => true,
					'slug'        => $slug,
				);
				if ( ! in_array( $slug, $premiumUpdates ) ) {
					$premiumUpdates[] = $slug;
				}
			}
			MainWP_Helper::update_option( 'mainwp_premium_updates', $premiumUpdates );
		}
	}

	private function stats_plugin_update( $premiumPlugins ) {

		$results = array();

		if ( null !== $this->filterFunction ) {
			add_filter( 'pre_site_transient_update_plugins', $this->filterFunction, 99 );
		}

		global $wp_current_filter;
		$wp_current_filter[] = 'load-plugins.php'; // phpcs:ignore -- to custom plugin installation.

		wp_update_plugins();
		include_once ABSPATH . '/wp-admin/includes/plugin.php';

		$plugin_updates = get_plugin_updates();
		if ( is_array( $plugin_updates ) ) {

			foreach ( $plugin_updates as $slug => $plugin_update ) {
				if ( in_array( $plugin_update->Name, $premiumPlugins ) ) {
					continue;
				}

				// to fix incorrect info.
				if ( ! property_exists( $plugin_update, 'update' ) || ! property_exists( $plugin_update->update, 'new_version' ) || empty( $plugin_update->update->new_version ) ) {
					continue;
				}

				$results[ $slug ] = $plugin_update;
			}
		}

		if ( null !== $this->filterFunction ) {
			remove_filter( 'pre_site_transient_update_plugins', $this->filterFunction, 99 );
		}

		// to fix premium plugs update.
		$cached_plugins_update = get_site_transient( 'mainwp_update_plugins_cached' );
		if ( is_array( $cached_plugins_update ) && ( count( $cached_plugins_update ) > 0 ) ) {
			foreach ( $cached_plugins_update as $slug => $plugin_update ) {

				// to fix incorrect info.
				if ( ! property_exists( $plugin_update, 'new_version' ) || empty( $plugin_update->new_version ) ) { // may do not need to check this?
					// to fix for some premiums update info.
					if ( property_exists( $plugin_update, 'update' ) ) {
						if ( ! property_exists( $plugin_update->update, 'new_version' ) || empty( $plugin_update->update->new_version ) ) {
							continue;
						}
					} else {
						continue;
					}
				}

				if ( ! isset( $results[ $slug ] ) ) {
					$results[ $slug ] = $plugin_update;
				}
			}
		}

		return $results;
	}

	private function stats_get_categories() {

		$cats       = get_categories(
			array(
				'hide_empty'   => 0,
				'hierarchical' => true,
				'number'       => 300,
			)
		);
		$categories = array();
		foreach ( $cats as $cat ) {
			$categories[] = $cat->name;
		}

		return $categories;
	}

	private function stats_get_total_size() {
		$total = null;

		$get_file_size = apply_filters_deprecated( 'mainwp-child-get-total-size', array( true ), '4.0.7.1', 'mainwp_child_get_total_size' );
		$get_file_size = apply_filters( 'mainwp_child_get_total_size', $get_file_size );

		if ( $get_file_size && isset( $_POST['cloneSites'] ) && ( '0' !== $_POST['cloneSites'] ) ) {
			$max_exe = ini_get( 'max_execution_time' );
			if ( $max_exe > 20 ) {
				$total = $this->get_total_file_size();
			}
		}

		return $total;
	}

	private function get_recent_number() {

		$recent_number = 5;

		if ( isset( $_POST ) && isset( $_POST['recent_number'] ) ) {
			$recent_number = $_POST['recent_number'];
			if ( get_option( 'mainwp_child_recent_number', 5 ) != $recent_number ) {
				update_option( 'mainwp_child_recent_number', $recent_number );
			}
		} else {
			$recent_number = get_option( 'mainwp_child_recent_number', 5 );
		}

		if ( $recent_number <= 0 || $recent_number > 30 ) {
			$recent_number = 5;
		}

		return $recent_number;
	}


	public function update_external_settings() {
		if ( isset( $_POST['cloneSites'] ) ) {
			if ( '0' !== $_POST['cloneSites'] ) {
				$arr = json_decode( urldecode( $_POST['cloneSites'] ), 1 );
				MainWP_Helper::update_option( 'mainwp_child_clone_sites', ( ! is_array( $arr ) ? array() : $arr ) );
			} else {
				MainWP_Helper::update_option( 'mainwp_child_clone_sites', '0' );
			}
		}

		if ( isset( $_POST['siteId'] ) ) {
			MainWP_Helper::update_option( 'mainwp_child_siteid', intval( $_POST['siteId'] ) );
		}

		if ( isset( $_POST['pluginDir'] ) ) {
			if ( get_option( 'mainwp_child_pluginDir' ) !== $_POST['pluginDir'] ) {
				MainWP_Helper::update_option( 'mainwp_child_pluginDir', $_POST['pluginDir'], 'yes' );
			}
		} elseif ( false !== get_option( 'mainwp_child_pluginDir' ) ) {
			MainWP_Helper::update_option( 'mainwp_child_pluginDir', false, 'yes' );
		}
	}

	public function get_total_file_size( $directory = WP_CONTENT_DIR ) {
		try {
			if ( MainWP_Helper::funct_exists( 'popen' ) ) {
				$uploadDir   = MainWP_Helper::get_mainwp_dir();
				$uploadDir   = $uploadDir[0];
				$popenHandle = popen( 'du -s ' . $directory . ' --exclude "' . str_replace( ABSPATH, '', $uploadDir ) . '"', 'r' ); // phpcs:ignore -- run if enabled.
				if ( 'resource' === gettype( $popenHandle ) ) {
					$size = fread( $popenHandle, 1024 );
					pclose( $popenHandle );
					$size = substr( $size, 0, strpos( $size, "\t" ) );
					if ( $size && MainWP_Helper::ctype_digit( $size ) ) {
						return $size / 1024;
					}
				}
			}

			if ( MainWP_Helper::funct_exists( 'shell_exec' ) ) {
				$uploadDir = MainWP_Helper::get_mainwp_dir();
				$uploadDir = $uploadDir[0];
				$size      = shell_exec( 'du -s ' . $directory . ' --exclude "' . str_replace( ABSPATH, '', $uploadDir ) . '"' ); // phpcs:ignore -- run if enabled.
				if ( null !== $size ) {
					$size = substr( $size, 0, strpos( $size, "\t" ) );
					if ( $size && MainWP_Helper::ctype_digit( $size ) ) {
						return $size / 1024;
					}
				}
			}
			if ( class_exists( 'COM' ) ) {
				$obj = new COM( 'scripting.filesystemobject' );

				if ( is_object( $obj ) ) {
					$ref = $obj->getfolder( $directory );

					$size = $ref->size;

					$obj = null;
					if ( MainWP_Helper::ctype_digit( $size ) ) {
						return $size / 1024;
					}
				}
			}
			// to fix for window host, performance not good?
			if ( class_exists( 'RecursiveIteratorIterator' ) ) {
				$size = 0;
				foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $directory ) ) as $file ) {
					$size += $file->getSize();
				}
				if ( $size && MainWP_Helper::ctype_digit( $size ) ) {
					return $size / 1024 / 1024;
				}
			}
			return 0;
		} catch ( \Exception $e ) {
			return 0;
		}
	}

	public function scan_dir( $pDir, $pLvl ) {
		$output = array();
		if ( file_exists( $pDir ) && is_dir( $pDir ) ) {
			if ( 'logs' === basename( $pDir ) ) {
				return empty( $output ) ? null : $output;
			}
			if ( 0 === $pLvl ) {
				return empty( $output ) ? null : $output;
			}
			$files = $this->int_scan_dir( $pDir );
			if ( $files ) {
				foreach ( $files as $file ) {
					if ( ( '.' === $file ) || ( '..' === $file ) ) {
						continue;
					}
					$newDir = $pDir . $file . DIRECTORY_SEPARATOR;
					if ( is_dir( $newDir ) ) {
						$output[ $file ] = $this->scan_dir( $newDir, $pLvl - 1, false );
					}
				}

				unset( $files );
				$files = null;
			}
		}

		return empty( $output ) ? null : $output;
	}

	public function int_scan_dir( $dir ) {
		$dh = opendir( $dir );
		if ( is_dir( $dir ) && $dh ) {
			$cnt  = 0;
			$out  = array();
			$file = readdir( $dh );
			while ( false !== $file ) {
				$newDir = $dir . $file . DIRECTORY_SEPARATOR;
				if ( ! is_dir( $newDir ) ) {
					$file = readdir( $dh );
					continue;
				}

				$out[] = $file;
				$file  = readdir( $dh );

				if ( $cnt ++ > 10 ) {
					break;
				}
			}
			closedir( $dh );

			return $out;
		}

		return false;
	}

	public function get_all_themes() {
		$keyword = $_POST['keyword'];
		$status  = $_POST['status'];
		$filter  = isset( $_POST['filter'] ) ? $_POST['filter'] : true;
		$rslt    = $this->get_all_themes_int( $filter, $keyword, $status );

		MainWP_Helper::write( $rslt );
	}

	public function get_all_themes_int( $filter, $keyword = '', $status = '' ) {
		$rslt   = array();
		$themes = wp_get_themes();

		if ( is_array( $themes ) ) {
			$theme_name = wp_get_theme()->get( 'Name' );

			/** @var $theme WP_Theme */
			foreach ( $themes as $theme ) {
				$out                = array();
				$out['name']        = $theme->get( 'Name' );
				$out['title']       = $theme->display( 'Name', true, false );
				$out['description'] = $theme->display( 'Description', true, false );
				$out['version']     = $theme->display( 'Version', true, false );
				$out['active']      = ( $theme->get( 'Name' ) === $theme_name ) ? 1 : 0;
				$out['slug']        = $theme->get_stylesheet();
				if ( ! $filter ) {
					if ( '' == $keyword || stristr( $out['title'], $keyword ) ) {
						$rslt[] = $out;
					}
				} elseif ( ( ( 'active' === $status ) ? 1 : 0 ) === $out['active'] ) {
					if ( '' == $keyword || stristr( $out['title'], $keyword ) ) {
						$rslt[] = $out;
					}
				}
			}
		}

		return $rslt;
	}


	public function get_all_plugins() {
		$keyword = $_POST['keyword'];
		$status  = $_POST['status'];
		$filter  = isset( $_POST['filter'] ) ? $_POST['filter'] : true;
		$rslt    = $this->get_all_plugins_int( $filter, $keyword, $status );

		MainWP_Helper::write( $rslt );
	}

	public function get_all_plugins_int( $filter, $keyword = '', $status = '' ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		global $mainWPChild;
		$rslt    = array();
		$plugins = get_plugins();
		if ( is_array( $plugins ) ) {
			$active_plugins = get_option( 'active_plugins' );

			foreach ( $plugins as $pluginslug => $plugin ) {
				$out                = array();
				$out['mainwp']      = ( $pluginslug == $mainWPChild->plugin_slug ? 'T' : 'F' );
				$out['name']        = $plugin['Name'];
				$out['slug']        = $pluginslug;
				$out['description'] = $plugin['Description'];
				$out['version']     = $plugin['Version'];
				$out['active']      = is_plugin_active( $pluginslug ) ? 1 : 0;
				if ( ! $filter ) {
					if ( '' == $keyword || stristr( $out['name'], $keyword ) ) {
						$rslt[] = $out;
					}
				} elseif ( ( ( 'active' == $status ) ? 1 : 0 ) == $out['active'] ) {
					if ( '' == $keyword || stristr( $out['name'], $keyword ) ) {
						$rslt[] = $out;
					}
				}
			}
		}

		$muplugins = get_mu_plugins();
		if ( is_array( $muplugins ) ) {
			foreach ( $muplugins as $pluginslug => $plugin ) {
				$out                = array();
				$out['mainwp']      = ( $pluginslug == $mainWPChild->plugin_slug ? 'T' : 'F' );
				$out['name']        = $plugin['Name'];
				$out['slug']        = $pluginslug;
				$out['description'] = $plugin['Description'];
				$out['version']     = $plugin['Version'];
				$out['active']      = 1;
				$out['mu']          = 1;
				if ( ! $filter ) {
					if ( '' == $keyword || stristr( $out['name'], $keyword ) ) {
						$rslt[] = $out;
					}
				} elseif ( ( ( 'active' == $status ) ? 1 : 0 ) == $out['active'] ) {
					if ( '' == $keyword || stristr( $out['name'], $keyword ) ) {
						$rslt[] = $out;
					}
				}
			}
		}

		return $rslt;
	}


}
