<?php
/**
 * MainWP Child Backup File Restorer - zip-slip-guarded archive extraction.
 *
 * Every archive entry resolves through validate_extract_path() before a
 * single byte is written. Absolute paths, traversal sequences, and
 * entries whose resolved parent directory falls outside the extraction
 * root abort the extraction. The prefix compare appends a trailing
 * slash to both sides: realpath() returns no trailing slash, so a bare
 * prefix check would accept a sibling directory that merely shares the
 * extraction root's name as a prefix.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Child_Backup_File_Restorer
 *
 * @package MainWP\Child
 */
class MainWP_Child_Backup_File_Restorer { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Validate an archive entry's extraction target.
     *
     * @param string $entry_name Relative entry name from the archive.
     * @param string $extract_to Extraction root directory.
     *
     * @return string The validated target path for the entry.
     *
     * @throws \Exception When the entry would resolve outside the
     *                    extraction root.
     */
    public static function validate_extract_path( $entry_name, $extract_to ) {
        // Reject absolute paths - PHP 7.4 compatible.
        if ( strpos( $entry_name, '/' ) === 0 || preg_match( '/^[A-Z]:\\\\/i', $entry_name ) ) {
            throw new \Exception(
                sprintf( 'Archive contains absolute path entry: %s. Extraction aborted.', esc_html( $entry_name ) )
            );
        }

        // Reject path traversal sequences - PHP 7.4 compatible.
        if ( strpos( $entry_name, '..' ) !== false ) {
            throw new \Exception(
                sprintf( 'Archive contains path traversal entry: %s. Extraction aborted.', esc_html( $entry_name ) )
            );
        }

        // Resolve and verify path stays within extraction directory.
        $target_path  = rtrim( $extract_to, '/' ) . '/' . $entry_name;
        $real_extract = realpath( $extract_to );
        $real_target  = realpath( dirname( $target_path ) );

        if ( false === $real_target ) {
            wp_mkdir_p( dirname( $target_path ) );
            $real_target = realpath( dirname( $target_path ) );
        }

        // SECURITY: append '/' to BOTH sides - realpath() has no trailing
        // slash, so a bare prefix compare passes for sibling dirs sharing
        // a prefix ('/path/backup-abc123-evil' vs '/path/backup-abc123').
        // The trailing slash closes that hole.
        if ( false === $real_target || strpos( $real_target . '/', $real_extract . '/' ) !== 0 ) {
            throw new \Exception(
                sprintf( 'Archive entry resolves outside extraction directory: %s. Extraction aborted.', esc_html( $entry_name ) )
            );
        }

        return $target_path;
    }
}
