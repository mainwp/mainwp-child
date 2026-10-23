<?php
/**
 * Version-bound updates for one directory-based plugin on this child site.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * A separate command ensures older children cannot ignore version constraints.
 */
class MainWP_Child_Exact_Update {
    /**
     * Update one plugin, refusing changed versions before replacing its files.
     *
     * Uses the normal WordPress update offer, with no premium or install fallback.
     * Local files supplied by trusted pre-download hooks still pass the archive
     * version check. Manual updaters and external file writers must be excluded
     * by the operator; WordPress's auto-updater lock does not serialize them.
     *
     * @param array $request Exact slug, current_version and target_version.
     * @return array|\WP_Error Confirmed selection or an update failure.
     */
    public function update( $request ) {
        foreach ( array( 'slug', 'current_version', 'target_version' ) as $key ) {
            if ( ! isset( $request[ $key ] ) || ! is_string( $request[ $key ] ) ) {
                return new \WP_Error( 'mainwp_exact_invalid_request', 'One plugin and both exact versions are required.' );
            }
        }
        $slug    = $request['slug'];
        $current = $request['current_version'];
        $target  = $request['target_version'];
        if ( ! preg_match( '~^[a-zA-Z0-9_-][a-zA-Z0-9._-]*/[a-zA-Z0-9_-][a-zA-Z0-9._-]*\.php$~D', $slug ) ||
            ! preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9.+_-]{0,99}$/D', $current ) ||
            ! preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9.+_-]{0,99}$/D', $target ) ||
            ! version_compare( $target, $current, '>' ) ) {
            return new \WP_Error( 'mainwp_exact_invalid_request', 'One plugin and a strictly newer exact target version are required.' );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/update.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        if ( 'direct' !== get_filesystem_method() || ! WP_Filesystem() ) {
            return new \WP_Error( 'mainwp_exact_filesystem', 'Exact updates require direct filesystem access.' );
        }
        if ( ! \WP_Upgrader::create_lock( 'auto_updater' ) ) {
            return new \WP_Error( 'mainwp_exact_busy', 'Another automatic or exact update is running.' );
        }
        $lock         = self::read_lock();
        $file         = WP_PLUGIN_DIR . '/' . $slug;
        $guard        = null;
        $pin          = null;
        $level        = ob_get_level();
        $translations = static function () {
            return false;
        };
        try {
            if ( null === $lock ) {
                return new \WP_Error( 'mainwp_exact_busy', 'Update lock could not be confirmed.' );
            }
            if ( ! is_file( $file ) || is_link( $file ) || is_link( dirname( $file ) ) ||
                get_plugin_data( $file, false, false )['Version'] !== $current ) {
                return new \WP_Error( 'mainwp_exact_installed_version_mismatch', 'Installed plugin version changed or is unavailable.' );
            }
            wp_clean_plugins_cache( true );
            wp_update_plugins();
            $updates = get_site_transient( 'update_plugins' );
            $offer   = isset( $updates->response[ $slug ] ) ? $updates->response[ $slug ] : null;
            if ( ! is_object( $offer ) || ! isset( $offer->new_version, $offer->package ) ||
                $target !== $offer->new_version || ! is_string( $offer->package ) ||
                'https' !== wp_parse_url( $offer->package, PHP_URL_SCHEME ) ) {
                return new \WP_Error( 'mainwp_exact_target_version_mismatch', 'The exact target update package is unavailable.' );
            }
            $package  = $offer->package;
            $upgrader = new \Plugin_Upgrader( new \WP_Ajax_Upgrader_Skin() );
            $pin      = static function ( $reply, $url, $instance, $extra ) use ( $upgrader, $slug, $package ) {
                if ( $instance !== $upgrader || is_wp_error( $reply ) ) {
                    return $reply;
                }
                if ( $url !== $package || ( $extra['plugin'] ?? null ) !== $slug ) {
                    return new \WP_Error( 'mainwp_exact_package_mismatch', 'Update package selection changed.' );
                }
                return $reply;
            };
            $guard    = static function ( $source, $remote_source, $instance, $extra ) use ( $upgrader, $slug, $current, $target, $file, $lock ) {
                if ( $instance !== $upgrader || is_wp_error( $source ) ) {
                    return $source;
                }
                clearstatcache();
                if ( self::read_lock() !== $lock || ! is_file( $file ) ||
                    get_plugin_data( $file, false, false )['Version'] !== $current ) {
                    return new \WP_Error( 'mainwp_exact_installed_version_mismatch', 'Installed plugin version or update lock changed.' );
                }
                $main = trailingslashit( $source ) . basename( $slug );
                if ( ( $extra['plugin'] ?? null ) !== $slug || basename( untrailingslashit( $source ) ) !== dirname( $slug ) || ! is_file( $main ) ) {
                    return new \WP_Error( 'mainwp_exact_package_mismatch', 'Archive does not contain the selected plugin directory and file.' );
                }
                $data = get_plugin_data( $main, false, false );
                if ( empty( $data['Name'] ) || $target !== $data['Version'] ) {
                    return new \WP_Error( 'mainwp_exact_package_mismatch', 'Archive plugin version does not match the exact target.' );
                }
                return $source;
            };
            add_filter( 'upgrader_pre_download', $pin, PHP_INT_MAX, 4 );
            add_filter( 'upgrader_source_selection', $guard, PHP_INT_MAX, 4 );
            add_filter( 'async_update_translation', $translations, PHP_INT_MAX );
            ob_start();
            $results = $upgrader->bulk_upgrade( array( $slug ) );
            $result  = is_array( $results ) && isset( $results[ $slug ] ) ? $results[ $slug ] : null;
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            clearstatcache();
            if ( ! $result || ! is_file( $file ) || get_plugin_data( $file, false, false )['Version'] !== $target ) {
                return new \WP_Error( 'mainwp_exact_failed', 'Exact plugin update was not confirmed.' );
            }
            return array(
                'success'         => 1,
                'contract'        => 'mainwp.plugin-update-exact.v1',
                'slug'            => $slug,
                'current_version' => $current,
                'target_version'  => $target,
            );
        } finally {
            if ( null !== $pin ) {
                remove_filter( 'upgrader_pre_download', $pin, PHP_INT_MAX );
            }
            if ( null !== $guard ) {
                remove_filter( 'upgrader_source_selection', $guard, PHP_INT_MAX );
            }
            remove_filter( 'async_update_translation', $translations, PHP_INT_MAX );
            while ( ob_get_level() > $level ) {
                ob_end_clean();
            }
            if ( null !== $lock && self::read_lock() === $lock ) {
                \WP_Upgrader::release_lock( 'auto_updater' );
            }
        }
    }

    /**
     * Read the lock afresh; get_option() may hide another process's takeover.
     *
     * @return string|null Stored lock timestamp, or null when absent/unreadable.
     */
    private static function read_lock() {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Ownership checks must bypass the request's options cache.
        return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'auto_updater.lock' ) );
    }
}
