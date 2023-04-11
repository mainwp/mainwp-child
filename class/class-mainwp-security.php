<?php
/**
 * MainWP Security
 *
 * @package MainWP/Child
 */

namespace MainWP\Child;

/**
 * Class MainWP_Security
 *
 * Detect security issues
 */
class MainWP_Security {

	/**
	 * Method get_class_name()
	 *
	 * Get class name.
	 *
	 * @return string __CLASS__ Class name.
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * Method fix_all()
	 *
	 * Fire off functions to fix detected security issues.
	 *
	 * @uses MainWP_Security::remove_wp_version() Remove the WordPress version.
	 * @uses MainWP_Security::remove_rsd() Remove the Really Simple Discovery meta tag.
	 * @uses MainWP_Security::remove_wlw() Remove the Windows Live Writer meta tag.
	 * @uses MainWP_Security::remove_php_reporting() Disable the PHP error reporting.
	 * @uses MainWP_Security::remove_registered_versions() Remove the registered scripts versions.
	 * @uses MainWP_Security::remove_generator_version() Remove the WordPress Generator meta tag.
	 * @uses MainWP_Security::remove_readme() Remove the readme.html file.
	 * @uses MainWP_Security::remove_script_versions() Remove scripts and stylesheets versions.
	 * @uses MainWP_Security::remove_theme_versions() Remove themes scripts and stylesheets version.
	 */
	public static function fix_all() {
		self::remove_wp_version();
		self::remove_rsd();
		self::remove_wlw();
		self::remove_php_reporting();
		self::remove_registered_versions();
		self::remove_generator_version();
		self::remove_readme();

		add_filter( 'style_loader_src', array( self::get_class_name(), 'remove_script_versions' ), PHP_INT_MAX );
		add_filter( 'style_loader_src', array( self::get_class_name(), 'remove_theme_versions' ), PHP_INT_MAX );
		add_filter( 'script_loader_src', array( self::get_class_name(), 'remove_script_versions' ), PHP_INT_MAX );
		add_filter( 'script_loader_src', array( self::get_class_name(), 'remove_theme_versions' ), PHP_INT_MAX );
	}

	/**
	 * Private static variable to hold the directory listing information.
	 *
	 * @var mixed Default null
	 */
	private static $listingDirectories = null;

	/**
	 * Method init_listing_directories()
	 *
	 * Get directories array to prevent listing.
	 */
	private static function init_listing_directories() {
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

	/**
	 * Method prevent_listing()
	 *
	 * Prevent directory listing by creating the index.php file.
	 *
	 * @used-by \MainWP\Child\MainWP_Security::fix_all() Fire off functions to fix detected security issues.
	 * @uses    \MainWP\Child\MainWP_Helper::get_wp_filesystem()
	 */
	public static function prevent_listing() {
		self::init_listing_directories();

		/**
		 * Global variable containing the instance of the (auto-)configured filesystem object after the filesystem "factory" has been run.
		 *
		 * @global object $wp_filesystem Filesystem object.
		 */
		global $wp_filesystem;

		MainWP_Helper::get_wp_filesystem();

		foreach ( self::$listingDirectories as $directory ) {
			$file = $directory . DIRECTORY_SEPARATOR . 'index.php';
			if ( ! $wp_filesystem->exists( $file ) ) {
				$content  = "<?php \n";
				$content .= "header(\$_SERVER['SERVER_PROTOCOL'] . ' 403 Forbidden' );\n";
				$content .= "die( '403 Forbidden' );\n";
				$wp_filesystem->put_contents( $file, $content );
			}
		}
	}

	/**
	 * Method remove_wp_version()
	 *
	 * Remove the WordPress version (WordPress Generator).
	 *
	 * @param bool $force Force action if true, don't force if false.
	 *
	 * @used-by MainWP_Security::fix_all() Fire off functions to fix detected security issues.
	 */
	public static function remove_wp_version( $force = false ) {
		if ( $force || self::get_security_option( 'wp_version' ) ) {
			remove_action( 'wp_head', 'wp_generator' );
			remove_filter( 'wp_head', 'wp_generator' );
		}
	}

	/**
	 * Method remove_rsd()
	 *
	 * Remove the Really Simple Discovery meta tag.
	 *
	 * @param bool $force Force action if true, don't force if false.
	 *
	 * @used-by MainWP_Security::fix_all() Fire off functions to fix detected security issues.
	 */
	public static function remove_rsd( $force = false ) {
		if ( $force || self::get_security_option( 'rsd' ) ) {
			remove_action( 'wp_head', 'rsd_link' );
		}
	}

	/**
	 * Method remove_wlw()
	 *
	 * Remove the Windows Live Writer meta tag.
	 *
	 * @param bool $force Force action if true, don't force if false.
	 *
	 * @used-by MainWP_Security::fix_all() Fire off functions to fix detected security issues.
	 */
	public static function remove_wlw( $force = false ) {
		if ( $force || self::get_security_option( 'wlw' ) ) {
			remove_action( 'wp_head', 'wlwmanifest_link' );
		}
	}

	/**
	 * Method remove_php_reporting()
	 *
	 * Disable the PHP error reporting.
	 *
	 * @param bool $force Force action if true, don't force if false.
	 *
	 * @used-by MainWP_Security::fix_all() Fire off functions to fix detected security issues.
	 */
	public static function remove_php_reporting( $force = false ) {
		if ( $force || self::get_security_option( 'php_reporting' ) ) {
			error_reporting( 0 ); //phpcs:ignore -- required to achieve desired results, pull request solutions appreciated.
			ini_set( 'display_errors', 'off' ); //phpcs:ignore -- required to achieve desired results, pull request solutions appreciated.
			ini_set( 'display_startup_errors', 0 ); //phpcs:ignore -- required to achieve desired results, pull request solutions appreciated.
		}
	}

	/**
	 * Method remove_database_reporting()
	 *
	 * Disable the database error reporting.
	 *
	 * @used-by MainWP_Security::fix_all() Fire off functions to fix detected security issues.
	 */
	public static function remove_database_reporting() {

		/**
		 * Object, providing access to the WordPress database.
		 *
		 * @global $wpdb WordPress Database instance.
		 */
		global $wpdb;

		$wpdb->hide_errors();
		$wpdb->suppress_errors();
	}

	/**
	 * Method remove_registered_versions()
	 *
	 * Remove the scripts and stylesheets registered versions.
	 *
	 * @used-by MainWP_Security::fix_all() Fire off functions to fix detected security issues.
	 */
	public static function remove_registered_versions() {
		if ( self::get_security_option( 'registered_versions' ) ) {

			/**
			 * Global object used to register styles.
			 *
			 * @var object $wp_styles Global object used to register styles.
			 */
			global $wp_styles;

			if ( $wp_styles instanceof WP_Styles ) {
				foreach ( $wp_styles->registered as $handle => $style ) {
					$wp_styles->registered[ $handle ]->ver = null;
				}
			}

			/**
			 * Global object used to register scripts.
			 *
			 * @var object $wp_scripts Global object used to register scripts.
			 */
			global $wp_scripts;

			if ( $wp_scripts instanceof WP_Scripts ) {
				foreach ( $wp_scripts->registered as $handle => $script ) {
					$wp_scripts->registered[ $handle ]->ver = null;
				}
			}
		}
	}

	/**
	 * Method remove_script_versions()
	 *
	 * Remove scripts versions.
	 *
	 * @param string $src Script or stylesheet location path.
	 *
	 * @used-by MainWP_Security::fix_all() Fire off functions to fix detected security issues.
	 *
	 * @return string $src Script or stylesheet striped location path.
	 */
	public static function remove_script_versions( $src ) {
		if ( self::get_security_option( 'scripts_version' ) ) {
			if ( strpos( $src, '?ver=' ) ) {
				$src = remove_query_arg( 'ver', $src );
			}
			return $src;
		}
		return $src;
	}

	/**
	 * Method remove_generator_version()
	 *
	 * Remove the WordPress Generator version.
	 *
	 * @param bool $force Force action if true, don't force if false.
	 *
	 * @uses MainWP_Security::custom_the_generator() Set custom generator.
	 *
	 * @used-by MainWP_Security::fix_all() Fire off functions to fix detected security issues.
	 */
	public static function remove_generator_version( $force = false ) {
		if ( $force || self::get_security_option( 'generator_version' ) ) {
			$types = array( 'html', 'xhtml', 'atom', 'rss2', 'rdf', 'comment', 'export' );
			foreach ( $types as $type ) {
				add_filter( 'get_the_generator_' . $type, array( self::get_class_name(), 'custom_the_generator' ), 10, 2 );
			}
		}
	}

	/**
	 * Method custom_the_generator()
	 *
	 * Set custom generator.
	 *
	 * @param string $generator Generator to process.
	 * @param array  $type Array containing the generator types.
	 *
	 * @used-by MainWP_Security::remove_generator_version() Remove the WordPress Generator version.
	 *
	 * @return string Return empty string.
	 */
	public static function custom_the_generator( $generator, $type = '' ) {
		return '';
	}

	/**
	 * Method remove_theme_versions()
	 *
	 * Remove themes versions.
	 *
	 * @param string $src Theme stylesheet location path.
	 *
	 * @used-by MainWP_Security::fix_all() Fire off functions to fix detected security issues.
	 *
	 * @return string $src Theme stylesheet striped location path.
	 */
	public static function remove_theme_versions( $src ) {
		if ( self::get_security_option( 'styles_version' ) ) {
			if ( strpos( $src, '?ver=' ) ) {
				$src = remove_query_arg( 'ver', $src );
			}
			return $src;
		}
		return $src;
	}

	/**
	 * Method remove_readme()
	 *
	 * Remove the readme.html file.
	 *
	 * @param bool $force Force action if true, don't force if false.
	 *
	 * @return bool true Return true to skip the process if child site is on WP Engine host.
	 *
	 * @used-by \MainWP\Child\MainWP_Security::fix_all() Fire off functions to fix detected security issues.
	 * @uses \MainWP\Child\MainWP_Helper::is_wp_engine()
	 * @uses \MainWP\Child\MainWP_Helper::get_wp_filesystem()
	 */
	public static function remove_readme( $force = false ) {
		// to prevent remove readme.html file on WP Engine hosting.
		if ( MainWP_Helper::is_wp_engine() ) {
			return true;
		}
		MainWP_Helper::get_wp_filesystem();

		/**
		 * Global variable containing the instance of the (auto-)configured filesystem object after the filesystem "factory" has been run.
		 *
		 * @global object $wp_filesystem Filesystem object.
		 */
		global $wp_filesystem;
		if ( $force || self::get_security_option( 'readme' ) ) {
			if ( $wp_filesystem->connect() ) {
				$abs_path = $wp_filesystem->abspath();
				if ( $wp_filesystem->exists( $abs_path . 'readme.html' ) ) {
					if ( ! unlink( ABSPATH . 'readme.html' ) ) {
						$wp_filesystem->delete( $abs_path . 'readme.html' );
						if ( $wp_filesystem->exists( $abs_path . 'readme.html' ) ) {
							// prevent repeat delete.
							self::update_security_option( 'readme', false );
						}
					}
				}
			}
		}
	}

	/**
	 * Method prevent_listing_ok()
	 *
	 * Check if the directory listing is prevented.
	 *
	 * @return bool true|false If directory listing prevented, return true, if not, return false.
	 *
	 * @used-by \MainWP\Child\MainWP_Security::get_stats_security() Calculate total number of detected secutiry issues.
	 * @uses \MainWP\Child\MainWP_Helper::get_wp_filesystem()
	 */
	public static function prevent_listing_ok() {

		/**
		 * Global variable containing the instance of the (auto-)configured filesystem object after the filesystem "factory" has been run.
		 *
		 * @global object $wp_filesystem Filesystem object.
		 */
		global $wp_filesystem;

		MainWP_Helper::get_wp_filesystem();

		self::init_listing_directories();
		foreach ( self::$listingDirectories as $directory ) {
			$file = $directory . DIRECTORY_SEPARATOR . 'index.php';
			if ( ! $wp_filesystem->exists( $file ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Method remove_wp_version_ok()
	 *
	 * Check if the WordPress version has been removed.
	 *
	 * @used-by MainWP_Security::get_stats_security() Calculate total number of detected secutiry issues.
	 *
	 * @return bool true|false If WordPress version removed, return true, if not return false.
	 */
	public static function remove_wp_version_ok() {
		return ! ( has_action( 'wp_head', 'wp_generator' ) || has_filter( 'wp_head', 'wp_generator' ) );
	}

	/**
	 * Method remove_rsd_ok()
	 *
	 * Check if the Really Simple Discovery meta tag has been removed.
	 *
	 * @used-by MainWP_Security::get_stats_security() Calculate total number of detected secutiry issues.
	 *
	 * @return bool true|false If the Really Simple Discovery meta tag has been removed, return true, if not return false.
	 */
	public static function remove_rsd_ok() {
		return ( ! has_action( 'wp_head', 'rsd_link' ) );
	}

	/**
	 * Method remove_wlw_ok()
	 *
	 * Check if the Windows Live Writer meta tag has been removed.
	 *
	 * @used-by MainWP_Security::get_stats_security() Calculate total number of detected secutiry issues.
	 *
	 * @return bool true|false If the Windows Live Writer meta tag has been removed, return true, if not return false.
	 */
	public static function remove_wlw_ok() {
		return ( ! has_action( 'wp_head', 'wlwmanifest_link' ) );
	}

	/**
	 * Method remove_database_reporting_ok()
	 *
	 * Check if the database error reporting has been disabled.
	 *
	 * @used-by MainWP_Security::get_stats_security() Calculate total number of detected secutiry issues.
	 *
	 * @return bool true|false If the database error reporting has been disabled, return true, if not, return false.
	 */
	public static function remove_database_reporting_ok() {

		/**
		 * Object, providing access to the WordPress database.
		 *
		 * @global $wpdb WordPress Database instance.
		 */
		global $wpdb;

		return ( false === $wpdb->show_errors );
	}

	/**
	 * Method remove_php_reporting_ok()
	 *
	 * Check if the PHP error reporting has been disabled.
	 *
	 * @used-by MainWP_Security::get_stats_security() Calculate total number of detected secutiry issues.
	 *
	 * @return bool true|false If the PHP error reporting has been disabled, return true, if not, return false.
	 */
	public static function remove_php_reporting_ok() {
		$is_ok       = true;
		$display_off = ini_get( 'display_errors' );
		if ( ! empty( $display_off ) ) {
			$display_off = strtolower( $display_off );
			$is_ok       = ( $is_ok || 'off' === $display_off );
		}
		$display_startup_off = ini_get( 'display_startup_errors' );
		if ( ! empty( $display_startup_off ) ) {
			$display_startup_off = strtolower( $display_startup_off );
			$is_ok               = ( $is_ok || 'off' === $display_startup_off );
		}
		return $is_ok;
	}

	/**
	 * Method remove_scripts_version_ok()
	 *
	 * Check if scripts versions are removed.
	 *
	 * @used-by MainWP_Security::get_stats_security() Calculate total number of detected secutiry issues.
	 *
	 * @return bool true|false Return true if versions have been removed, false if not.
	 */
	public static function remove_scripts_version_ok() {
		return self::get_security_option( 'scripts_version' );
	}

	/**
	 * Method remove_styles_version_ok()
	 *
	 * Check if stylesheets versions are removed.
	 *
	 * @used-by MainWP_Security::get_stats_security() Calculate total number of detected secutiry issues.
	 *
	 * @return bool true|false Return true if versions have been removed, false if not.
	 */
	public static function remove_styles_version_ok() {
		return self::get_security_option( 'styles_version' );
	}

	/**
	 * Method remove_generator_version_ok()
	 *
	 * Check if the WordPress Generator version has been removed.
	 *
	 * @used-by MainWP_Security::get_stats_security() Calculate total number of detected secutiry issues.
	 *
	 * @return bool true|false Return true if registered versions have been removed, false if not.
	 */
	public static function remove_generator_version_ok() {
		return self::get_security_option( 'generator_version' );
	}

	/**
	 * Method remove_registered_versions_ok()
	 *
	 * Check if the scripts and stylesheets registered versions are removed.
	 *
	 * @used-by MainWP_Security::get_stats_security() Calculate total number of detected secutiry issues.
	 *
	 * @return bool true|false Return true if registered versions have been removed, false if not.
	 */
	public static function remove_registered_versions_ok() {
		return self::get_security_option( 'registered_versions' );
	}

	/**
	 * Method admin_user_ok()
	 *
	 * Check if any of administrator accounts has 'admin' as username.
	 *
	 * @used-by MainWP_Security::get_stats_security() Calculate total number of detected secutiry issues.
	 *
	 * @return bool true Return true no 'admin' username detected, fale if not.
	 */
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

	/**
	 * Method remove_readme_ok()
	 *
	 * Check if the readme.html file has been removed.
	 *
	 * @used-by MainWP_Security::get_stats_security() Calculate total number of detected secutiry issues.
	 *
	 * @return bool true|false Return true if the readme.html file has been removed, false if not.
	 */
	public static function remove_readme_ok() {
		return ! file_exists( ABSPATH . 'readme.html' );
	}

	/**
	 * Method get_stats_security()
	 *
	 * Calculate total number of detected secutiry issues.
	 *
	 * @uses MainWP_Security::prevent_listing_ok() Check if the directory listing is prevented.
	 * @uses MainWP_Security::remove_wp_version_ok() Check if the WordPress version has been removed.
	 * @uses MainWP_Security::remove_rsd_ok() Check if the Really Simple Discovery meta tag has been removed.
	 * @uses MainWP_Security::remove_wlw_ok() Check if the Windows Live Writer meta tag has been removed.
	 * @uses MainWP_Security::remove_database_reporting_ok() Check if the database error reporting has been disabled.
	 * @uses MainWP_Security::remove_php_reporting_ok() Check if the PHP error reporting has been disabled.
	 * @uses MainWP_Security::remove_scripts_version_ok() Check if scripts versions are removed.
	 * @uses MainWP_Security::remove_styles_version_ok() Check if stylesheets versions are removed.
	 * @uses MainWP_Security::remove_generator_version_ok() Check if the WordPress Generator version has been removed.
	 * @uses MainWP_Security::remove_registered_versions_ok() Check if the scripts and stylesheets registered versions are removed.
	 * @uses MainWP_Security::admin_user_ok() Check if any of administrator accounts has 'admin' as username.
	 * @uses MainWP_Security::remove_readme_ok() Check if the readme.html file has been removed.
	 *
	 * @return int $total_issues Total number of detected security issues.
	 */
	public static function get_stats_security() {
		$total_issues = 0;
		if ( ! self::prevent_listing_ok() ) {
			$total_issues ++;
		}
		if ( ! self::remove_wp_version_ok() ) {
			$total_issues ++;
		}
		if ( ! self::remove_rsd_ok() ) {
			$total_issues ++;
		}
		if ( ! self::remove_wlw_ok() ) {
			$total_issues ++;
		}
		if ( ! self::remove_database_reporting_ok() ) {
			$total_issues ++;
		}
		if ( ! self::remove_php_reporting_ok() ) {
			$total_issues ++;
		}
		if ( ! self::remove_scripts_version_ok() || ! self::remove_styles_version_ok() || ! self::remove_generator_version_ok() ) {
			$total_issues ++;
		}
		if ( ! self::remove_registered_versions_ok() ) {
			$total_issues ++;
		}
		if ( ! self::admin_user_ok() ) {
			$total_issues ++;
		}
		if ( ! self::remove_readme_ok() ) {
			$total_issues ++;
		}

		if ( ! self::wpcore_updated_ok() ) {
			$total_issues ++;
		}

		if ( ! self::phpversion_ok() ) {
			$total_issues ++;
		}

		if ( ! self::sslprotocol_ok() ) {
			$total_issues ++;
		}

		if ( ! self::debug_disabled_ok() ) {
			$total_issues ++;
		}

		return $total_issues;
	}

	/**
	 * Method get_security_option()
	 *
	 * Get security check settings.
	 *
	 * @param string $option Security check option.
	 *
	 * @return bool Security settings.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::update_option()
	 */
	public static function get_security_option( $option ) {
		$security = get_option( 'mainwp_security' );

		return ! empty( $security ) && isset( $security[ $option ] ) && ( true === $security[ $option ] );
	}


	/**
	 * Method update_security_option()
	 *
	 * Update the security issues feature settings.
	 *
	 * @param string $key   Security option key.
	 * @param string $value Security option value.
	 */
	public static function update_security_option( $key, $value ) {
		$security = get_option( 'mainwp_security' );
		if ( ! empty( $key ) ) {
			$security[ $key ] = $value;
		}
		MainWP_Helper::update_option( 'mainwp_security', $security, 'yes' );
	}

	/**
	 * Method wpcore_updated_ok()
	 *
	 * Check WP core updated.
	 */
	public static function wpcore_updated_ok() {
		include_once ABSPATH . '/wp-admin/includes/update.php';
		$ok           = true;
		$core_updates = get_core_updates();
		foreach ( $core_updates as $core => $update ) {
			if ( 'upgrade' === $update->response ) {
				$ok = false;
			}
		}
		return $ok;
	}


	/**
	 * Method phpversion_ok()
	 *
	 * Check PHP version matches the WP requirement.
	 */
	public static function phpversion_ok() {
		require_once ABSPATH . WPINC . '/version.php';
		global $required_php_version;
		return version_compare( phpversion(), $required_php_version, '>=' );
	}

	/**
	 * Method sslprotocol_ok()
	 *
	 * Check SSL protocol is in place.
	 */
	public static function sslprotocol_ok() {
		return is_ssl();
	}


	/**
	 * Method debug_disabled_ok()
	 *
	 * Check WP Config and check if debugging is disabled.
	 */
	public static function debug_disabled_ok() {
		$ok = ! defined( 'WP_DEBUG' ) || ! WP_DEBUG;
		$ok = $ok && ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG );
		return $ok;
	}
}
