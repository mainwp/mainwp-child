<?php
/**
 * Version-bound updates for one directory-based plugin on this child site.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

use WP_Error;
use WP_Upgrader;
use Plugin_Upgrader;
use WP_Ajax_Upgrader_Skin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** A separate command ensures older children cannot ignore version constraints. */
class MainWP_Child_Exact_Update {
    /**
     * Update one plugin with no premium or install fallback.
     *
     * Manual updaters and external writers must be excluded by the operator;
     * WordPress's automatic-updater lock does not serialize them. Trusted local
     * archives from pre-download hooks receive the same version guard.
     *
     * @param array $request Exact slug, current_version and target_version.
     * @return array|\WP_Error Confirmed selection or update failure.
     * @SuppressWarnings(PHPMD.StaticAccess) WordPress exposes updater locks as static APIs.
     */
    public function update( $request ) {
        $invalid = $this->validate_request( $request );
        if ( is_wp_error( $invalid ) ) {
            return $invalid;
        }
        // Only fixed WordPress include paths are used here.
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/update.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        if ( 'direct' !== get_filesystem_method() || ! WP_Filesystem() ) {
            return new WP_Error( 'mainwp_exact_filesystem', 'Exact updates require direct filesystem access.' );
        }
        if ( ! WP_Upgrader::create_lock( 'auto_updater' ) ) {
            return new WP_Error( 'mainwp_exact_busy', 'Another automatic or exact update is running.' );
        }
        $lock = self::read_lock();
        if ( null === $lock ) {
            return new WP_Error( 'mainwp_exact_busy', 'Update lock could not be confirmed.' );
        }
        try {
            if ( ! $this->installed_matches( $request ) ) {
                return new WP_Error( 'mainwp_exact_installed_version_mismatch', 'Installed plugin version changed or is unavailable.' );
            }
            $offer = $this->get_offer( $request );
            if ( is_wp_error( $offer ) ) {
                return $offer;
            }
            return $this->run_update( $request, $offer->package, $lock );
        } finally {
            $this->release_owned_lock( $lock );
        }
    }

    /**
     * Validate the narrow request before loading or changing anything.
     *
     * @param array $request Selection.
     * @return true|\WP_Error Validation result.
     */
    private function validate_request( $request ) {
        foreach ( array( 'slug', 'current_version', 'target_version' ) as $key ) {
            if ( ! isset( $request[ $key ] ) || ! is_string( $request[ $key ] ) ) {
                return new WP_Error( 'mainwp_exact_invalid_request', 'One plugin and both exact versions are required.' );
            }
        }
        if ( ! preg_match( '~^[a-zA-Z0-9_-][a-zA-Z0-9._-]*/[a-zA-Z0-9_-][a-zA-Z0-9._-]*\.php$~D', $request['slug'] ) ||
            ! preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9.+_-]{0,99}$/D', $request['current_version'] ) ||
            ! preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9.+_-]{0,99}$/D', $request['target_version'] ) ||
            ! version_compare( $request['target_version'], $request['current_version'], '>' ) ) {
            return new WP_Error( 'mainwp_exact_invalid_request', 'One plugin and a strictly newer exact target version are required.' );
        }
        if ( is_multisite() ) {
            return new WP_Error( 'mainwp_exact_multisite', 'Exact single-site updates are unavailable on multisite because plugin files are shared.' );
        }
        return true;
    }

    /**
     * Read the selected plugin's actual header, without the plugins cache.
     *
     * @param array $request Selection.
     * @return bool Whether the installed version matches.
     */
    private function installed_matches( $request ) {
        $file = WP_PLUGIN_DIR . '/' . $request['slug'];
        clearstatcache();
        return is_file( $file ) && ! is_link( $file ) && ! is_link( dirname( $file ) ) &&
            get_plugin_data( $file, false, false )['Version'] === $request['current_version'];
    }

    /**
     * Refresh the offer and require the exact target and an HTTPS package URL.
     *
     * @param array $request Selection.
     * @return object|\WP_Error Matching offer or refusal.
     */
    private function get_offer( $request ) {
        wp_clean_plugins_cache( true );
        wp_update_plugins();
        $updates = get_site_transient( 'update_plugins' );
        $offer   = $updates->response[ $request['slug'] ] ?? null;
        if ( ( $offer->new_version ?? null ) !== $request['target_version'] ||
            ! is_string( $offer->package ?? null ) ||
            'https' !== wp_parse_url( $offer->package, PHP_URL_SCHEME ) ) {
            return new WP_Error( 'mainwp_exact_target_version_mismatch', 'The exact target update package is unavailable.' );
        }
        return $offer;
    }

    /**
     * Pin the URL that core actually selects for this updater instance.
     *
     * @param \Plugin_Upgrader $upgrader Updater instance.
     * @param string           $slug Selected plugin.
     * @param string           $package Approved offer URL.
     * @return \Closure Download filter.
     */
    private function package_guard( $upgrader, $slug, $package ) {
        return static function ( $reply, $url, $instance, $extra ) use ( $upgrader, $slug, $package ) {
            if ( $instance !== $upgrader || is_wp_error( $reply ) ) {
                return $reply;
            }
            if ( $url !== $package || ( $extra['plugin'] ?? null ) !== $slug ) {
                return new WP_Error( 'mainwp_exact_package_mismatch', 'Update package selection changed.' );
            }
            return $reply;
        };
    }

    /**
     * Scope the source guard to this updater without shared mutable state.
     *
     * @param \Plugin_Upgrader $upgrader Updater instance.
     * @param array            $request Selection.
     * @param string           $lock Lock timestamp.
     * @return \Closure Source filter.
     */
    private function source_guard( $upgrader, $request, $lock ) {
        return function ( $source, $remote_source, $instance, $extra ) use ( $upgrader, $request, $lock ) {
            unset( $remote_source ); // Required WordPress filter position, intentionally unused.
            if ( $instance !== $upgrader || is_wp_error( $source ) ) {
                return $source;
            }
            return $this->validate_source( $source, $extra, $request, $lock );
        };
    }

    /**
     * Refuse changed versions or archive identity before core replaces files.
     *
     * @param string $source Unpacked directory.
     * @param array  $extra Core updater context.
     * @param array  $request Selection.
     * @param string $lock Lock timestamp.
     * @return string|\WP_Error Validated source or refusal.
     */
    private function validate_source( $source, $extra, $request, $lock ) {
        if ( self::read_lock() !== $lock || ! $this->installed_matches( $request ) ) {
            return new WP_Error( 'mainwp_exact_installed_version_mismatch', 'Installed plugin version or update lock changed.' );
        }
        $main = trailingslashit( $source ) . basename( $request['slug'] );
        if ( ( $extra['plugin'] ?? null ) !== $request['slug'] || basename( untrailingslashit( $source ) ) !== dirname( $request['slug'] ) || ! is_file( $main ) ) {
            return new WP_Error( 'mainwp_exact_package_mismatch', 'Archive does not contain the selected plugin directory and file.' );
        }
        $data = get_plugin_data( $main, false, false );
        if ( empty( $data['Name'] ) || $request['target_version'] !== $data['Version'] ) {
            return new WP_Error( 'mainwp_exact_package_mismatch', 'Archive plugin version does not match the exact target.' );
        }
        return $source;
    }

    /**
     * Run core once with request-local guards, then confirm the actual target.
     *
     * @param array  $request Selection.
     * @param string $package Approved package URL.
     * @param string $lock Lock timestamp.
     * @return array|\WP_Error Confirmed selection or failure.
     */
    private function run_update( $request, $package, $lock ) {
        $upgrader     = new Plugin_Upgrader( new WP_Ajax_Upgrader_Skin() );
        $pin          = $this->package_guard( $upgrader, $request['slug'], $package );
        $guard        = $this->source_guard( $upgrader, $request, $lock );
        $translations = static function () {
            return false;
        };
        $level        = ob_get_level();
        add_filter( 'upgrader_pre_download', $pin, PHP_INT_MAX, 4 );
        add_filter( 'upgrader_source_selection', $guard, PHP_INT_MAX, 4 );
        add_filter( 'async_update_translation', $translations, PHP_INT_MAX );
        try {
            ob_start();
            $results = $upgrader->bulk_upgrade( array( $request['slug'] ) );
            $result  = $results[ $request['slug'] ] ?? null;
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            $file = WP_PLUGIN_DIR . '/' . $request['slug'];
            clearstatcache();
            if ( ! $result || ! is_file( $file ) || get_plugin_data( $file, false, false )['Version'] !== $request['target_version'] ) {
                return new WP_Error( 'mainwp_exact_failed', 'Exact plugin update was not confirmed.' );
            }
            return array(
                'success'         => 1,
                'contract'        => 'mainwp.plugin-update-exact.v1',
                'slug'            => $request['slug'],
                'current_version' => $request['current_version'],
                'target_version'  => $request['target_version'],
            );
        } finally {
            remove_filter( 'upgrader_pre_download', $pin, PHP_INT_MAX );
            remove_filter( 'upgrader_source_selection', $guard, PHP_INT_MAX );
            remove_filter( 'async_update_translation', $translations, PHP_INT_MAX );
            while ( ob_get_level() > $level ) {
                ob_end_clean();
            }
        }
    }

    /**
     * Release only the lock still owned by this request.
     *
     * @param string $lock Acquired timestamp.
     * @SuppressWarnings(PHPMD.StaticAccess) WordPress exposes updater locks as static APIs.
     */
    private function release_owned_lock( $lock ) {
        if ( self::read_lock() === $lock ) {
            WP_Upgrader::release_lock( 'auto_updater' );
        }
    }

    /**
     * Read afresh; get_option() may hide another process's takeover.
     *
     * @return string|null Stored lock timestamp or null when absent/unreadable.
     */
    private static function read_lock() {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Ownership checks must bypass the request's options cache.
        return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'auto_updater.lock' ) );
    }
}
