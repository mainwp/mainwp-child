<?php
/**
 * MainWP Security
 *
 * @package MainWP/Child
 */

namespace MainWP\Child;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Security
 *
 * Detect security issues
 */
class MainWP_Security { //phpcs:ignore -- NOSONAR - multi methods.

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
     * @uses MainWP_Security::remove_php_reporting() Disable the PHP error reporting.
     */
    public static function fix_all() {
        static::remove_php_reporting();
        if ( static::get_security_option( 'db_reporting' ) ) {
            static::remove_database_reporting();
        }
    }

    /**
     * Private static variable to hold the directory listing information.
     *
     * @var mixed Default null
     */
    private static $listingDirectories = null;

    /**
     * Private static variable to hold the site's display_errors value from before a
     * signed Dashboard request turned it off.
     *
     * @var string|false|null Default null when no value has been saved.
     */
    private static $saved_display_errors = null;

    /**
     * Method save_php_reporting_state()
     *
     * Save the site's display_errors value before a signed Dashboard request turns
     * it off, so the hardening check reports the site's own setting. Only the first
     * call in a request saves a value.
     */
    public static function save_php_reporting_state() {
        if ( null === self::$saved_display_errors ) {
            self::$saved_display_errors = ini_get( 'display_errors' );
        }
    }

    /**
     * Method init_listing_directories()
     *
     * Get directories array to prevent listing.
     */
    private static function init_listing_directories() {
        if ( null === static::$listingDirectories ) {
            $wp_upload_dir              = wp_upload_dir();
            static::$listingDirectories = array(
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
        static::init_listing_directories();

        /**
         * Global variable containing the instance of the (auto-)configured filesystem object after the filesystem "factory" has been run.
         *
         * @global object $wp_filesystem Filesystem object.
         */
        global $wp_filesystem;

        MainWP_Helper::get_wp_filesystem();

        foreach ( static::$listingDirectories as $directory ) {
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
     * Method remove_php_reporting()
     *
     * Disable the PHP error reporting.
     *
     * @param bool $force Force action if true, don't force if false.
     *
     * @used-by MainWP_Security::fix_all() Fire off functions to fix detected security issues.
     */
    public static function remove_php_reporting( $force = false ) {
        if ( $force || static::get_security_option( 'php_reporting' ) ) {
            error_reporting( 0 ); //phpcs:ignore -- required to achieve desired results, pull request solutions appreciated.
            ini_set( 'display_errors', 'off' ); //phpcs:ignore -- required to achieve desired results, pull request solutions appreciated.
            ini_set( 'display_startup_errors', 0 ); //phpcs:ignore -- required to achieve desired results, pull request solutions appreciated.
            // The child now keeps error display off on every request, so the live values are the site's setting.
            self::$saved_display_errors = null;
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
        if ( static::get_security_option( 'scripts_version' ) ) {
            if ( strpos( $src, '?ver=' ) ) {
                $src = remove_query_arg( 'ver', $src );
            }
            return $src;
        }
        return $src;
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
        if ( static::get_security_option( 'styles_version' ) ) {
            if ( strpos( $src, '?ver=' ) ) {
                $src = remove_query_arg( 'ver', $src );
            }
            return $src;
        }
        return $src;
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

        return false === $wpdb->show_errors;
    }

    /**
     * Method remove_php_reporting_ok()
     *
     * Check if the PHP error reporting has been disabled.
     *
     * On a signed Dashboard request display_errors is already off for that request, so
     * the value saved by save_php_reporting_state() also has to be off.
     *
     * @used-by MainWP_Security::get_stats_security() Calculate total number of detected secutiry issues.
     *
     * @return bool true|false If the PHP error reporting has been disabled, return true, if not, return false.
     */
    public static function remove_php_reporting_ok() {
        if ( null !== self::$saved_display_errors && ! self::ini_value_is_off( self::$saved_display_errors ) ) {
            return false;
        }
        return self::ini_value_is_off( ini_get( 'display_errors' ) ) && self::ini_value_is_off( ini_get( 'display_startup_errors' ) );
    }

    /**
     * Method ini_value_is_off()
     *
     * Check whether an ini value is one of PHP's recognised off values.
     * Anything else, including "stderr", counts as on.
     *
     * @param string|false $value Value returned by ini_get().
     *
     * @return bool true if the value is off, false otherwise.
     */
    private static function ini_value_is_off( $value ) {
        if ( false === $value || '' === $value ) {
            return true;
        }
        return in_array( strtolower( trim( (string) $value ) ), array( '0', 'off', 'no', 'false', 'none' ), true );
    }

    /**
     * Method get_stats_security()
     *
     * Calculate total number of detected secutiry issues.
     *
     * @uses MainWP_Security::remove_database_reporting_ok() Check if the database error reporting has been disabled.
     * @uses MainWP_Security::remove_php_reporting_ok() Check if the PHP error reporting has been disabled.
     *
     * @return int $total_issues Total number of detected security issues.
     */
    public static function get_stats_security() {
        $total_issues = 0;
        if ( ! static::remove_database_reporting_ok() ) {
            ++$total_issues;
        }
        if ( ! static::remove_php_reporting_ok() ) {
            ++$total_issues;
        }
        if ( ! static::wpcore_updated_ok() ) {
            ++$total_issues;
        }

        if ( ! static::phpversion_ok() ) {
            ++$total_issues;
        }

        if ( ! static::sslprotocol_ok() ) {
            ++$total_issues;
        }

        if ( ! static::debug_disabled_ok() ) {
            ++$total_issues;
        }

        if ( ! static::outdated_plugins_ok() ) {
            ++$total_issues;
        }

        if ( ! static::inactive_plugins_ok() ) {
            ++$total_issues;
        }

        if ( ! static::outdated_themes_ok() ) {
            ++$total_issues;
        }
        if ( ! static::inactive_themes_ok() ) {
            ++$total_issues;
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
        $security = get_option( 'mainwp_security', array() );
        if ( false === $security ) {
            // to fix issue of cached loading variable.
            MainWP_Helper::update_option( 'mainwp_security', array(), 'yes' );
        }
        return is_array( $security ) && isset( $security[ $option ] ) && ( true === $security[ $option ] );
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
        $security = get_option( 'mainwp_security', array() );
        if ( ! is_array( $security ) ) {
            $security = array();
        }
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
        include_once ABSPATH . '/wp-admin/includes/update.php'; // NOSONAR -- WP compatible.
        $ok           = true;
        $core_updates = get_core_updates();
        if ( is_array( $core_updates ) ) {
            foreach ( $core_updates as $update ) {
                if ( 'upgrade' === $update->response ) {
                    $ok = false;
                }
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
        require_once ABSPATH . WPINC . '/version.php'; // NOSONAR - WP compatible.
        $required_php_version = '8.0';
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
        return ! defined( 'WP_DEBUG' ) || ! WP_DEBUG;
    }


    /**
     * Method outdated_plugins_ok()
     *
     * Check WP Config and check if debugging is disabled.
     */
    public static function outdated_plugins_ok() {
        return MainWP_Child_Stats::get_instance()->found_plugins_updates() ? false : true;
    }

    /**
     * Method inactive_plugins_ok()
     *
     * Check WP Config and check if debugging is disabled.
     */
    public static function inactive_plugins_ok() {
        return MainWP_Child_Stats::get_instance()->found_inactive_plugins() ? false : true;
    }


    /**
     * Method outdated_themes_ok()
     *
     * Check WP Config and check if debugging is disabled.
     */
    public static function outdated_themes_ok() {
        return MainWP_Child_Stats::get_instance()->found_themes_updates() ? false : true;
    }

    /**
     * Method inactive_themes_ok()
     *
     * Check WP Config and check if debugging is disabled.
     */
    public static function inactive_themes_ok() {
        return MainWP_Child_Stats::get_instance()->is_good_themes() ? true : false;
    }
}
