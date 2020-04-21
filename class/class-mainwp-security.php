<?php

class MainWP_Security {
	public static function fixAll() {
		self::remove_wp_version();
		self::remove_rsd();
		self::remove_wlw();
		self::remove_php_reporting();
		self::remove_registered_versions();
		self::remove_generator_version();
		self::remove_readme();

		add_filter( 'style_loader_src', array( 'MainWP_Security', 'remove_script_versions' ), PHP_INT_MAX );
		add_filter( 'style_loader_src', array( 'MainWP_Security', 'remove_theme_versions' ), PHP_INT_MAX );
		add_filter( 'script_loader_src', array( 'MainWP_Security', 'remove_script_versions' ), PHP_INT_MAX );
		add_filter( 'script_loader_src', array( 'MainWP_Security', 'remove_theme_versions' ), PHP_INT_MAX );
	}

	// Prevent listing wp-content, wp-content/plugins, wp-content/themes, wp-content/uploads.
	private static $listingDirectories = null;

	private static function init_listingDirectories() {
		if ( null === self::$listingDirectories ) {
			$wp_upload_dir            = wp_upload_dir();
			self::$listingDirectories = array(
				WP_CONTENT_DIR,
				WP_PLUGIN_DIR,
				get_theme_root(),
				$wp_upload_dir['basedir'],
			);
		}
	}

	public static function prevent_listing_ok() {
		self::init_listingDirectories();
		foreach ( self::$listingDirectories as $directory ) {
			$file = $directory . DIRECTORY_SEPARATOR . 'index.php';
			if ( ! file_exists( $file ) ) {
				return false;
			}
		}

		return true;
	}

	public static function prevent_listing() {
		self::init_listingDirectories();
		foreach ( self::$listingDirectories as $directory ) {
			$file = $directory . DIRECTORY_SEPARATOR . 'index.php';
			if ( ! file_exists( $file ) ) {
				$h = fopen( $file, 'w' );
				fwrite( $h, '<?php ' . "\n" );
				fwrite( $h, "header(\$_SERVER['SERVER_PROTOCOL'] . ' 403 Forbidden' );" . "\n" );
				fwrite( $h, "die( '403 Forbidden' );" . "\n" );
				fclose( $h );
			}
		}
	}

	public static function get_security_option( $option ) {
		$security = get_option( 'mainwp_security' );

		return ! empty( $security ) && isset( $security[ $option ] ) && ( true === $security[ $option ] );
	}

	// Removed wp-version.
	public static function remove_wp_version_ok() {
		return ! ( has_action( 'wp_head', 'wp_generator' ) || has_filter( 'wp_head', 'wp_generator' ) );
	}

	public static function remove_wp_version( $force = false ) {
		if ( $force || self::get_security_option( 'wp_version' ) ) {
			remove_action( 'wp_head', 'wp_generator' );
			remove_filter( 'wp_head', 'wp_generator' );
		}
	}

	// Removed Really Simple Discovery meta tag.
	public static function remove_rsd_ok() {
		return ( ! has_action( 'wp_head', 'rsd_link' ) );
	}

	public static function remove_rsd( $force = false ) {
		if ( $force || self::get_security_option( 'rsd' ) ) {
			remove_action( 'wp_head', 'rsd_link' );
		}
	}

	// Removed Windows Live Writer meta tag.
	public static function remove_wlw_ok() {
		return ( ! has_action( 'wp_head', 'wlwmanifest_link' ) );
	}

	public static function remove_wlw( $force = false ) {
		if ( $force || self::get_security_option( 'wlw' ) ) {
			remove_action( 'wp_head', 'wlwmanifest_link' );
		}
	}

	// File permissions not secure.
	private static $permission_checks = null;

	private static function init_permission_checks() {
		if ( null === self::$permission_checks ) {
			self::$permission_checks = array(
				WP_CONTENT_DIR . DIRECTORY_SEPARATOR . '../' => '0755',
				WP_CONTENT_DIR . DIRECTORY_SEPARATOR . '../wp-includes' => '0755',
				WP_CONTENT_DIR . DIRECTORY_SEPARATOR . '../.htaccess' => '0644',
				WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'index.php' => '0644',
				WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'js/' => '0755',
				WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'themes' => '0755',
				WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'plugins' => '0755',
				WP_CONTENT_DIR . DIRECTORY_SEPARATOR . '../wp-admin' => '0755',
				WP_CONTENT_DIR => '0755',
			);
		}
	}

	// Database error reporting turned on/off.
	public static function remove_database_reporting_ok() {
		global $wpdb;

		return ( false === $wpdb->show_errors );
	}

	public static function remove_database_reporting() {
		global $wpdb;

		$wpdb->hide_errors();
		$wpdb->suppress_errors();
	}

	// PHP error reporting turned on/off.
	public static function remove_php_reporting_ok() {
		return ! ( ( ( 0 != ini_get( 'display_errors' ) ) && ( 'off' != ini_get( 'display_errors' ) ) ) || ( ( 0 != ini_get( 'display_startup_errors' ) ) && ( 'off' != ini_get( 'display_startup_errors' ) ) ) );
	}

	public static function remove_php_reporting( $force = false ) {
		if ( $force || self::get_security_option( 'php_reporting' ) ) {
			@error_reporting( 0 );
			@ini_set( 'display_errors', 'off' );
			@ini_set( 'display_startup_errors', 0 );
		}
	}

	// Removed version information for scripts/stylesheets.
	public static function remove_scripts_version_ok() {
		return self::get_security_option( 'scripts_version' );
	}

	public static function remove_script_versions( $src ) {
		if ( self::get_security_option( 'scripts_version' ) ) {
			if ( strpos( $src, '?ver=' ) ) {
				$src = remove_query_arg( 'ver', $src );
			}

			return $src;
		}
		return $src;
	}

	public static function remove_registered_versions_ok() {
		return self::get_security_option( 'registered_versions' );
	}

	public static function remove_registered_versions() {
		if ( self::get_security_option( 'registered_versions' ) ) {
			global $wp_styles;
			if ( $wp_styles instanceof WP_Styles ) {
				foreach ( $wp_styles->registered as $handle => $style ) {
					$wp_styles->registered[ $handle ]->ver = null;
				}
			}
			global $wp_scripts;
			if ( $wp_scripts instanceof WP_Scripts ) {
				foreach ( $wp_scripts->registered as $handle => $script ) {
					$wp_scripts->registered[ $handle ]->ver = null;
				}
			}
		}
	}

	public static function remove_generator_version_ok() {
		return self::get_security_option( 'generator_version' );
	}

	public static function remove_generator_version( $force = false ) {
		if ( $force || self::get_security_option( 'generator_version' ) ) {
			$types = array( 'html', 'xhtml', 'atom', 'rss2', 'rdf', 'comment', 'export' );
			foreach ( $types as $type ) {
				add_filter( 'get_the_generator_' . $type, array( 'MainWP_Security', 'custom_the_generator' ), 10, 2 );
			}
		}
	}

	public static function custom_the_generator( $generator, $type = '' ) {
		return '';
	}

	public static function remove_theme_versions( $src ) {
		if ( self::get_security_option( 'styles_version' ) ) {
			if ( strpos( $src, '?ver=' ) ) {
				$src = remove_query_arg( 'ver', $src );
			}

			return $src;
		}
		return $src;
	}

	public static function remove_readme( $force = false ) {

		// to prevent remove readme.html file on WPE hosts.
		if ( MainWP_Helper::is_wp_engine() ) {
			return true;
		}

		if ( $force || self::get_security_option( 'readme' ) ) {
			if ( @file_exists( ABSPATH . 'readme.html' ) ) {
				if ( ! @unlink( ABSPATH . 'readme.html' ) ) {
					MainWP_Helper::getWPFilesystem();
					global $wp_filesystem;
					if ( ! empty( $wp_filesystem ) ) {
						$wp_filesystem->delete( ABSPATH . 'readme.html' );
						if ( @file_exists( ABSPATH . 'readme.html' ) ) {
							// prevent repeat delete.
							self::update_security_option( 'readme', false );
						}
					}
				}
			}
		}
	}

	public static function remove_readme_ok() {
		return ! file_exists( ABSPATH . 'readme.html' );
	}

	public static function remove_styles_version_ok() {
		return self::get_security_option( 'styles_version' );
	}

	// Admin user name is not admin.
	public static function admin_user_ok() {
		$user = get_user_by( 'login', 'admin' );
		if ( ! $user ) {
			return true;
		}

		if ( 10 !== $user->wp_user_level && ( ! isset( $user->user_level ) || 10 !== $user->user_level ) && ! user_can( $user, 'level_10' ) ) {
			return true;
		}

		return false;
	}

	public static function update_security_option( $key, $value ) {
		$security = get_option( 'mainwp_security' );
		if ( ! empty( $key ) ) {
			$security[ $key ] = $value;
		}
		MainWP_Helper::update_option( 'mainwp_security', $security, 'yes' );
	}
}
