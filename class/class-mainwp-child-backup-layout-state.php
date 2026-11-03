<?php
/**
 * Private v2 cursor envelopes, with no task creation or root discovery.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Existing v1 cursor serializers do not call this explicit v2 adapter. */
class MainWP_Child_Backup_Layout_State {
    /**
     * Serialize private walker or restore state without changing its bindings.
     *
     * @param array $state Versioned private state.
     * @return string JSON state.
     */
    public static function encode( $state ) {
        self::validate( $state );
        $bytes = wp_json_encode( $state, JSON_UNESCAPED_SLASHES );
        if ( false === $bytes ) {
            MainWP_Child_Backup_Layout::refuse();
        }
        return $bytes;
    }

    /**
     * Load an envelope only under its original descriptor digest.
     *
     * @param string $bytes Private state JSON.
     * @param string $digest Expected descriptor SHA-256.
     * @return array Validated state; destination roots remain pinned strings.
     */
    public static function decode( $bytes, $digest ) {
        if ( ! is_string( $bytes ) ) {
            MainWP_Child_Backup_Layout::refuse();
        }
        $state = json_decode( $bytes, true );
        if ( JSON_ERROR_NONE !== json_last_error() ) {
            MainWP_Child_Backup_Layout::refuse();
        }
        self::validate( $state );
        if ( ! MainWP_Child_Backup_Layout::is_digest( $digest ) || ! hash_equals( $digest, $state['layout_descriptor_sha256'] ) ) {
            MainWP_Child_Backup_Layout::refuse( MainWP_Backup_Error::ERR_LAYOUT_CHANGED );
        }
        return $state;
    }

    /**
     * Validate the envelope; rollback row semantics belong to the restore engine.
     *
     * @param array $state Versioned state containing JSON values.
     */
    private static function validate( $state ) {
        if ( ! is_array( $state ) || 2 !== ( $state['format'] ?? null ) ) {
            MainWP_Child_Backup_Layout::refuse( MainWP_Backup_Error::ERR_UNSUPPORTED_BACKUP_FORMAT );
        }
        if ( ! MainWP_Child_Backup_Layout::is_digest( $state['layout_descriptor_sha256'] ?? null ) ) {
            MainWP_Child_Backup_Layout::refuse();
        }
        $walker = array_key_exists( 'root_index', $state );
        if ( $walker && ( ! is_int( $state['root_index'] ) || $state['root_index'] < 0 || $state['root_index'] > count( MainWP_Child_Backup_Layout::ROLES ) ) ) {
            MainWP_Child_Backup_Layout::refuse();
        }
        $restore = array_key_exists( 'destination_roots', $state ) || array_key_exists( 'destination_upload_options', $state ) || array_key_exists( 'rollback_manifest', $state );
        if ( ! $walker && ! $restore ) {
            MainWP_Child_Backup_Layout::refuse();
        }
        if ( $restore ) {
            foreach ( array( 'destination_roots', 'destination_upload_options', 'rollback_manifest' ) as $key ) {
                if ( ! isset( $state[ $key ] ) || ! is_array( $state[ $key ] ) ) {
                    MainWP_Child_Backup_Layout::refuse();
                }
            }
            foreach ( $state['destination_roots'] as $role => $path ) {
                if ( ! in_array( $role, MainWP_Child_Backup_Layout::ROLES, true ) || ! is_string( $path ) || '' === $path || false !== strpos( $path, "\0" ) || ( '/' !== $path[0] && ! preg_match( '/^[a-z]:[\\\\\/]/i', $path ) ) || in_array( '..', preg_split( '#[\\\\/]#', $path ), true ) ) {
                    MainWP_Child_Backup_Layout::refuse();
                }
            }
            foreach ( $state['destination_upload_options'] as $option => $value ) {
                if ( ! in_array( $option, array( 'upload_path', 'upload_url_path' ), true ) || ( ! is_string( $value ) && false !== $value ) ) {
                    MainWP_Child_Backup_Layout::refuse();
                }
            }
        }
        self::json_values( $state );
    }

    /**
     * Keep objects and resources out of private JSON state.
     *
     * @param mixed $value State value.
     */
    private static function json_values( $value ) {
        if ( is_array( $value ) ) {
            foreach ( $value as $nested ) {
                self::json_values( $nested );
            }
        } elseif ( ! is_null( $value ) && ! is_scalar( $value ) ) {
            MainWP_Child_Backup_Layout::refuse();
        }
    }
}
