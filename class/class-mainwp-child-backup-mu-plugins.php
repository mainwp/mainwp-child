<?php
/**
 * Keep live host files that have no bytes in the leaf snapshot.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Extraction, tombstones and reconciliation must agree on the host directory.
 */
class MainWP_Child_Backup_Mu_Plugins extends MainWP_Child_Backup_Incremental_Diff {

    /**
     * Resolve the configured directory without accepting a sibling or following links.
     *
     * @param string      $root Site root.
     * @param string|null $directory Configured directory; null uses WordPress.
     * @return string|null Relative directory, or null outside the live tree.
     */
    public static function relative_directory( $root, $directory = null ) {
        $directory = null === $directory ? ( defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins' ) : $directory;
        $root      = self::normalize_directory( $root );
        $directory = self::normalize_directory( $directory );
        if ( $directory !== $root && 0 !== strpos( $directory, trailingslashit( $root ) ) ) {
            return null;
        }
        $parent = $directory;
        while ( $parent !== $root ) {
            if ( is_link( $parent ) ) {
                return null;
            }
            $parent = wp_normalize_path( dirname( $parent ) );
        }
        return ltrim( substr( $directory, strlen( $root ) ), '/' );
    }

    /**
     * Dot segments must be resolved before testing containment.
     *
     * @param string $path Absolute directory.
     * @return string Normalized directory.
     */
    private static function normalize_directory( $path ) {
        $path  = wp_normalize_path( $path );
        $parts = array();
        foreach ( explode( '/', $path ) as $part ) {
            if ( '..' === $part ) {
                array_pop( $parts );
            } elseif ( '' !== $part && '.' !== $part ) {
                $parts[] = $part;
            }
        }
        return ( '/' === substr( $path, 0, 1 ) ? '/' : '' ) . implode( '/', $parts );
    }

    /**
     * Archive aliases must match the same live relpath without accepting traversal.
     *
     * @param string $path Archive or live relpath.
     * @return string|null Canonical relpath, or null when unsafe.
     */
    private static function path_key( $path ) {
        $path = wp_normalize_path( $path );
        if ( '' === $path || '/' === $path[0] || preg_match( '/^[a-z]:|[\x00-\x1f\x7f]/i', $path ) || in_array( '..', explode( '/', $path ), true ) ) {
            return null;
        }
        return implode( '/', array_diff( explode( '/', $path ), array( '', '.' ) ) );
    }

    /**
     * The slash boundary excludes directories such as mu-plugins-old.
     *
     * @param string      $path Site-relative candidate.
     * @param string|null $directory Relative mu-plugins directory.
     * @return bool Whether the candidate belongs to the directory.
     */
    public static function contains( $path, $directory ) {
        $path = self::path_key( $path );
        return null !== $directory && null !== $path && ( '' === $directory || $path === $directory || 0 === strpos( $path, $directory . '/' ) );
    }

    /**
     * Whole-directory fallback still permits leaf snapshot bytes to be restored.
     *
     * @param string $path Archive or tombstone relpath.
     * @param array  $state Persisted preservation policy.
     * @return bool Whether changing the path is forbidden.
     */
    public static function preserves( $path, $state ) {
        $key = self::path_key( $path );
        if ( ! isset( $state['directory'] ) || ! self::contains( $path, $state['directory'] ) ) {
            return false;
        }
        return 'whole_dir' === $state['mode'] ? ! isset( $state['leaf_paths'][ $key ] ) : isset( $state['paths'][ $key ] );
    }

    /**
     * Capture before any archive can replace the live bytes being protected.
     *
     * @param string      $root Live site root.
     * @param string      $directory Configured mu-plugins directory.
     * @param string|null $manifest Verified leaf manifest; null when unavailable.
     * @param bool        $started An older restore already entered extraction.
     * @param int         $limit Maximum number of live absent paths to persist.
     * @throws \RuntimeException When a manifest or live directory cannot be read.
     * @return array Persistable preservation policy.
     */
    public function capture( $root, $directory, $manifest, $started, $limit ) {
        $relative = self::relative_directory( $root, $directory );
        $state    = array(
            'directory' => null,
            'mode'      => 'set',
            'paths'     => array(),
        );
        if ( null === $relative || null === $manifest ) {
            return $state;
        }
        $handle = fopen( $manifest, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- verified local sidecar.
        if ( false === $handle ) {
            throw new \RuntimeException( 'Could not read the leaf manifest.' );
        }
        $leaf = array();
        try {
            while ( false !== ( $line = fgets( $handle ) ) ) {
                $row = $this->parse_list_line( rtrim( $line, "\r\n" ) );
                if ( null === $row ) {
                    // Reconcile reports malformed manifests through its existing failure path.
                    return $state;
                }
                if ( self::contains( $row[0], $relative ) ) {
                    $leaf[ $row[0] ] = true;
                }
            }
            if ( ! feof( $handle ) ) {
                throw new \RuntimeException( 'Could not read the leaf manifest.' );
            }
        } finally {
            fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- local sidecar.
        }
        $state['directory'] = $relative;
        if ( ! $started && is_dir( $directory ) ) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS ),
                    static function ( $file ) {
                        return ! $file->isLink();
                    }
                )
            );
            foreach ( $iterator as $file ) {
                $path = ltrim( $relative . '/' . substr( wp_normalize_path( $file->getPathname() ), strlen( trailingslashit( wp_normalize_path( $directory ) ) ) ), '/' );
                if ( $file->isFile() && ! isset( $leaf[ $path ] ) ) {
                    $state['paths'][ $path ] = true;
                    if ( count( $state['paths'] ) > $limit ) {
                        break;
                    }
                }
            }
        }
        if ( $started || count( $state['paths'] ) > $limit ) {
            unset( $state['paths'] );
            $state['mode']       = 'whole_dir';
            $state['reason']     = $started ? 'mu_plugins_set_missing' : 'mu_plugins_cap';
            $state['leaf_paths'] = $leaf;
        }
        return $state;
    }
}
