<?php
/**
 * Shared matching for paths a snapshot could not archive.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Deletion coverage is conservative across filesystem case aliases. */
class MainWP_Child_Backup_Path_Cover {

    /**
     * Prepare a lookup once for a potentially site-sized deletion pass.
     *
     * @param string[] $paths Validated relative paths.
     * @return array Normalized, case-folded prefix set.
     */
    public static function prefix_set( $paths ) {
        $prefixes = array();
        foreach ( $paths as $path ) {
            $path = self::normalize( $path );
            if ( '' !== $path ) {
                $prefixes[ $path ] = true;
            }
        }
        return $prefixes;
    }

    /**
     * Match ancestors in O(depth), independent of the number of prefixes.
     *
     * @param string $path Relative candidate.
     * @param array  $prefixes Prepared prefix set.
     * @return bool Whether deletion would cross unreadable coverage.
     */
    public static function contains( $path, $prefixes ) {
        $path = self::normalize( $path );
        while ( '' !== $path ) {
            if ( isset( $prefixes[ $path ] ) ) {
                return true;
            }
            $slash = strrpos( $path, '/' );
            if ( false === $slash ) {
                break;
            }
            $path = substr( $path, 0, $slash );
        }
        return false;
    }

    /**
     * Keep prefixes and candidates comparable across path aliases.
     *
     * @param string $path Relative path.
     * @return string Normalized comparison key.
     */
    private static function normalize( $path ) {
        return strtolower( implode( '/', array_diff( explode( '/', wp_normalize_path( $path ) ), array( '', '.' ) ) ) );
    }
}
