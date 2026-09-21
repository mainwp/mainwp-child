<?php
/**
 * Database-owned exclusion between the legacy and native backup engines.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Ownership checks bypass the option cache so every worker sees the same lock.
 */
class MainWP_Child_Backup_Mutex {

    const OPTION_NAME = 'mainwp_child_backup_mutex';
    const LEGACY_TTL  = 300;
    const NATIVE_TTL  = 3600;

    /**
     * Acquire an absent or expired row without displacing a live owner.
     *
     * @param string $engine Legacy or native.
     * @param string $owner  Opaque token without a pipe.
     * @param int    $ttl    Lifetime in seconds.
     * @return true|array|false|null True when acquired; an array (engine, owner,
     *                              expires_at) for a live holder; false when no
     *                              holder can be read after one immediate retry;
     *                              null on an INSERT/UPDATE failure or invalid arguments.
     */
    public static function acquire( $engine, $owner, $ttl ) {
        global $wpdb;
        if ( ! in_array( $engine, array( 'legacy', 'native' ), true ) || '' === $owner || false !== strpos( $owner, '|' ) ) {
            return null;
        }
        $value = $engine . '|' . $owner . '|' . ( time() + (int) $ttl );
        for ( $attempt = 0; $attempt < 2; ++$attempt ) {
            $result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- the unique option key arbitrates acquisition.
                $wpdb->prepare( "INSERT IGNORE INTO $wpdb->options (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::OPTION_NAME, $value )
            );
            wp_cache_delete( self::OPTION_NAME, 'options' );
            if ( false === $result ) {
                return null;
            }
            if ( 1 === $result ) {
                return true;
            }
            $old = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- the raw value fences takeover of malformed rows too.
                $wpdb->prepare( "SELECT option_value FROM $wpdb->options WHERE option_name = %s", self::OPTION_NAME )
            );
            if ( null === $old ) {
                // A holder may have released between the insert and this read.
                continue;
            }
            $parts = explode( '|', $old );
            if ( 3 === count( $parts ) && in_array( $parts[0], array( 'legacy', 'native' ), true ) && '' !== $parts[1] && ctype_digit( $parts[2] ) && (int) $parts[2] >= time() ) {
                return array(
                    'engine'     => $parts[0],
                    'owner'      => $parts[1],
                    'expires_at' => (int) $parts[2],
                );
            }
            $result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- a competing takeover must not be overwritten.
                $wpdb->prepare( "UPDATE $wpdb->options SET option_value = %s WHERE option_name = %s AND BINARY option_value = %s", $value, self::OPTION_NAME, $old )
            );
            wp_cache_delete( self::OPTION_NAME, 'options' );
            if ( false === $result ) {
                return null;
            }
            if ( 1 === $result ) {
                return true;
            }
            $holder = self::holder();
            if ( null !== $holder && $holder['expires_at'] >= time() ) {
                return $holder;
            }
        }
        return false;
    }

    /**
     * Bypass cached options so a restore captures the row the dump carried.
     *
     * @return string Raw value, or an empty string when absent.
     */
    public static function raw_value() {
        global $wpdb;
        return (string) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- the object cache may still hold the pre-restore value.
            $wpdb->prepare( "SELECT option_value FROM $wpdb->options WHERE option_name = %s", self::OPTION_NAME )
        );
    }

    /**
     * Remove only the captured dump value, preserving any later owner.
     * Best effort: the restored data is already live when this runs, so a
     * failed delete leaves the stale row for the TTL rather than failing
     * the restore.
     *
     * @param string $expected_value Raw mutex captured before maintenance ended.
     * @return bool Whether no delete was needed or the delete statement ran.
     */
    public static function clear_after_restore( $expected_value ) {
        global $wpdb;
        if ( ! is_string( $expected_value ) || '' === $expected_value ) {
            return true;
        }
        $result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- a later owner must survive restore cleanup.
            $wpdb->prepare( "DELETE FROM $wpdb->options WHERE option_name = %s AND BINARY option_value = %s", self::OPTION_NAME, $expected_value )
        );
        wp_cache_delete( self::OPTION_NAME, 'options' );
        wp_cache_delete( 'alloptions', 'options' );
        return false !== $result;
    }

    /**
     * Extend only the value this owner observed.
     *
     * @param string $owner Owner token.
     * @param int    $ttl   Lifetime in seconds.
     * @return bool False when ownership was lost or the write failed.
     */
    public static function refresh( $owner, $ttl ) {
        global $wpdb;
        $holder = self::holder();
        if ( null === $holder || $owner !== $holder['owner'] ) {
            return false;
        }
        $prefix = $holder['engine'] . '|' . $owner . '|';
        // MySQL reports changed rows; a same-second refresh must still change the value.
        $expiry = max( time() + (int) $ttl, $holder['expires_at'] + 1 );
        $result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- refreshing a replaced value would resurrect a stale writer.
            $wpdb->prepare( "UPDATE $wpdb->options SET option_value = %s WHERE option_name = %s AND BINARY option_value = %s", $prefix . $expiry, self::OPTION_NAME, $prefix . $holder['expires_at'] )
        );
        wp_cache_delete( self::OPTION_NAME, 'options' );
        return 1 === $result;
    }

    /**
     * Compare and delete in one statement, including on shutdown.
     *
     * @param string $owner Owner token.
     * @return bool Whether the owner's row was deleted.
     */
    public static function release( $owner ) {
        global $wpdb;
        if ( '' === $owner || false !== strpos( $owner, '|' ) ) {
            return false;
        }
        $result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- an expired owner's shutdown must not release its successor.
            $wpdb->prepare( "DELETE FROM $wpdb->options WHERE option_name = %s AND BINARY option_value LIKE %s", self::OPTION_NAME, '%|' . $wpdb->esc_like( $owner ) . '|%' )
        );
        wp_cache_delete( self::OPTION_NAME, 'options' );
        return 1 === $result;
    }

    /**
     * Read ownership directly, including an expired holder awaiting takeover.
     *
     * @return array|null Engine, owner and expiry, or null when absent.
     */
    public static function holder() {
        global $wpdb;
        $value = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- the object cache cannot arbitrate ownership.
            $wpdb->prepare( "SELECT option_value FROM $wpdb->options WHERE option_name = %s", self::OPTION_NAME )
        );
        $parts = null === $value ? array() : explode( '|', $value );
        if ( 3 !== count( $parts ) ) {
            return null;
        }
        return array(
            'engine'     => $parts[0],
            'owner'      => $parts[1],
            'expires_at' => (int) $parts[2],
        );
    }
}
