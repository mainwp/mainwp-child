<?php
/**
 * MainWP Backup Error registry and message sanitization.
 *
 * Standardized backup error codes, user-friendly messages, and helpers that
 * keep sensitive server details (absolute paths, SQL, DB credentials) out of
 * responses sent to the MainWP Dashboard. Full context belongs in server-side
 * logs only.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Backup_Error
 *
 * @package MainWP\Child
 */
class MainWP_Backup_Error { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    const ERR_DISK_FULL          = 'mainwp_backup_disk_full';
    const ERR_MEMORY_LIMIT       = 'mainwp_backup_memory_limit';
    const ERR_PERMISSIONS        = 'mainwp_backup_permissions';
    const ERR_DIR_NOT_WRITABLE   = 'mainwp_backup_dir_not_writable';
    const ERR_MISSING_EXTENSION  = 'mainwp_backup_missing_extension';
    const ERR_DB_CONNECTION_LOST = 'mainwp_backup_db_connection_lost';
    const ERR_DB_QUERY_FAILED    = 'mainwp_backup_db_query_failed';
    const ERR_DB_DUMP_EMPTY      = 'mainwp_backup_db_dump_empty';
    const ERR_FILE_CORRUPT       = 'mainwp_backup_file_corrupt';
    const ERR_FILE_TOO_SMALL     = 'mainwp_backup_file_too_small';
    const ERR_ZIP_FAILED         = 'mainwp_backup_zip_failed';
    const ERR_TAR_WRITE_FAILED   = 'mainwp_backup_tar_write_failed';
    const ERR_FILE_SKIPPED       = 'mainwp_backup_file_skipped';
    const ERR_TOO_MANY_SKIPPED   = 'mainwp_backup_too_many_skipped';
    const ERR_STALLED            = 'mainwp_backup_stalled';
    const ERR_ALREADY_RUNNING    = 'mainwp_backup_already_running';
    const ERR_DL_FILE_NOT_FOUND  = 'mainwp_backup_dl_file_not_found';
    const ERR_DL_FILE_CHANGED    = 'mainwp_backup_dl_file_changed';
    const ERR_DL_TOKEN_INVALID   = 'mainwp_backup_dl_token_invalid';

    const ERR_INCREMENTAL_BASE_MISSING = 'mainwp_backup_incremental_base_missing';
    const ERR_MANIFEST_TOO_LARGE       = 'mainwp_backup_manifest_too_large';

    const ERR_MANIFEST_CHUNK_WRONG_PHASE = 'mainwp_backup_manifest_chunk_wrong_phase';
    const ERR_MANIFEST_CHUNK_CORRUPT     = 'mainwp_backup_manifest_chunk_corrupt';

    const ERR_RESTORE_DB_REPLAY   = 'mainwp_restore_db_replay';
    const ERR_RESTORE_SWAP_FAILED = 'mainwp_restore_swap_failed';
    const ERR_RESTORE_EXTRACT     = 'mainwp_restore_extract';
    const ERR_RESTORE_MULTISITE   = 'mainwp_restore_multisite';
    const ERR_RESTORE_WRONG_PHASE = 'mainwp_restore_wrong_phase';
    const ERR_RESTORE_CANCELLED   = 'mainwp_restore_cancelled';
    const ERR_CHUNK_GAP           = 'mainwp_restore_chunk_gap';
    const ERR_CHUNK_HASH          = 'mainwp_restore_chunk_hash';
    const ERR_ARTIFACT_UNKNOWN    = 'mainwp_restore_artifact_unknown';
    const ERR_ARTIFACT_CORRUPT    = 'mainwp_restore_artifact_corrupt';
    const ERR_CLOUD_PULL_FAILED   = 'mainwp_cloud_pull_failed';

    /**
     * Get all registered error codes.
     *
     * @return string[] Registered error codes.
     */
    public static function get_registered_codes() {
        return array_keys( static::get_messages() );
    }

    /**
     * Get the user-friendly message for an error code.
     *
     * Messages follow the structure: what failed, why it failed, how to fix it.
     *
     * @param string $code Error code.
     *
     * @return string User-friendly message.
     */
    public static function message( $code ) {
        $messages = static::get_messages();
        if ( isset( $messages[ $code ] ) ) {
            return $messages[ $code ];
        }
        return esc_html__( 'The backup encountered an unexpected problem and did not complete. Check the child site error log for details and try again.', 'mainwp-child' );
    }

    /**
     * Strip sensitive server details from a user-facing message.
     *
     * Removes the absolute site path, SQL statement fragments, and table name
     * references. Full context should be logged server-side before sanitizing.
     *
     * @param string $message Raw message.
     *
     * @return string Sanitized message.
     */
    public static function sanitize_message( $message ) {
        if ( ! is_string( $message ) || '' === $message ) {
            return '';
        }

        // Strip the absolute site root - relative paths stay readable.
        $message = str_replace( ABSPATH, '', $message );

        // Strip SQL statement fragments. Compound keyword forms only, so plain
        // English like "check for updates" is never mangled.
        $sql_patterns = array(
            '/SELECT\s+.{0,500}?\s+FROM\s+\S+(\s+WHERE\s+[^.;]*)?/is',
            '/INSERT\s+INTO\s+[^.;]*/is',
            '/UPDATE\s+\S+\s+SET\s+[^.;]*/is',
            '/DELETE\s+FROM\s+[^.;]*/is',
            '/DROP\s+TABLE(\s+IF\s+EXISTS)?\s+[^.;]*/is',
            '/CREATE\s+TABLE(\s+IF\s+NOT\s+EXISTS)?\s+[^.;]*/is',
            '/ALTER\s+TABLE\s+[^.;]*/is',
            '/TRUNCATE\s+TABLE\s+[^.;]*/is',
        );
        $message      = preg_replace( $sql_patterns, '[SQL removed]', $message );

        // Redact backticked identifiers and MySQL "Table '...'" references.
        $message = preg_replace( '/`[^`]+`/', '[redacted]', $message );
        $message = preg_replace( "/(Table\s+')[^']+(')/i", '$1[redacted]$2', $message );

        return $message;
    }

    /**
     * Redact database credentials from an error message.
     *
     * Replaces the literal DB_HOST / DB_USER / DB_PASSWORD / DB_NAME values
     * (including any socket path inside DB_HOST) and the user 'x'@'y' pattern
     * mysqli emits. Apply at every catch site before a DB error message leaves
     * the child site.
     *
     * @param string $message Raw message.
     *
     * @return string Scrubbed message.
     */
    public static function scrub_db_credentials( $message ) {
        if ( ! is_string( $message ) || '' === $message ) {
            return '';
        }

        // The mysqli "user 'x'@'y'" pattern - redact regardless of configured values.
        $message = preg_replace( "/user\s+'[^']*'@'[^']*'/i", "user '[redacted]'@'[redacted]'", $message );

        $values = array();
        foreach ( array( 'DB_PASSWORD', 'DB_HOST', 'DB_USER', 'DB_NAME' ) as $constant_name ) {
            if ( ! defined( $constant_name ) ) {
                continue;
            }
            $value = constant( $constant_name );
            if ( ! is_string( $value ) || '' === $value ) {
                continue;
            }
            $values[] = $value;
            // DB_HOST may embed a port or socket path after a colon - redact the parts too.
            if ( 'DB_HOST' === $constant_name && false !== strpos( $value, ':' ) ) {
                foreach ( explode( ':', $value ) as $part ) {
                    if ( strlen( $part ) > 3 ) {
                        $values[] = $part;
                    }
                }
            }
        }

        // Longest first so substrings of other values do not leave fragments behind.
        usort(
            $values,
            function ( $a, $b ) {
                return strlen( $b ) - strlen( $a );
            }
        );

        foreach ( $values as $value ) {
            $message = str_replace( $value, '[redacted]', $message );
        }

        return $message;
    }

    /**
     * The error code => user-friendly message map.
     *
     * @return array<string,string> Messages keyed by error code.
     */
    private static function get_messages() {
        return array(
            self::ERR_DISK_FULL                  => esc_html__( 'Could not write backup data. The server may be out of disk space. Free up disk space on the child site and try again.', 'mainwp-child' ),
            self::ERR_MEMORY_LIMIT               => esc_html__( 'The backup is approaching the PHP memory limit. Increase the child site PHP memory limit or exclude large folders from the backup.', 'mainwp-child' ),
            self::ERR_PERMISSIONS                => esc_html__( 'Could not create a file or directory for the backup. Check that the web server has write permission on the MainWP backup directory under wp-content/uploads.', 'mainwp-child' ),
            self::ERR_DIR_NOT_WRITABLE           => esc_html__( 'The backup directory is not writable. Check the directory permissions on the MainWP backup directory under wp-content/uploads.', 'mainwp-child' ),
            self::ERR_MISSING_EXTENSION          => esc_html__( 'A required PHP extension is not available on the child site. Ask your host to enable the ZipArchive or mysqli PHP extension.', 'mainwp-child' ),
            self::ERR_DB_CONNECTION_LOST         => esc_html__( 'The database connection was lost during the backup. The database server may be overloaded. Try running the backup again during low-traffic hours.', 'mainwp-child' ),
            self::ERR_DB_QUERY_FAILED            => esc_html__( 'Could not read table data for the backup. The database connection may have been interrupted. Check the child site error log and try running the backup again.', 'mainwp-child' ),
            self::ERR_DB_DUMP_EMPTY              => esc_html__( 'The database backup did not return any data. The database connection may have been lost mid-dump. Try running the backup again.', 'mainwp-child' ),
            self::ERR_FILE_CORRUPT               => esc_html__( 'The backup archive appears to be damaged. This can happen if the server ran out of disk space during the backup or the process was interrupted. Try running the backup again.', 'mainwp-child' ),
            self::ERR_FILE_TOO_SMALL             => esc_html__( 'The backup file is smaller than expected and is likely incomplete. The backup process may have been interrupted. Try running the backup again.', 'mainwp-child' ),
            self::ERR_ZIP_FAILED                 => esc_html__( 'Could not create the ZIP archive. The ZipArchive PHP extension may not be available or the backup directory is not writable. Check the child site PHP configuration and directory permissions.', 'mainwp-child' ),
            self::ERR_TAR_WRITE_FAILED           => esc_html__( 'Could not write to the backup archive. The server may be out of disk space or the backup directory permissions may have changed. Free up disk space or check the permissions on the MainWP backup directory under wp-content/uploads.', 'mainwp-child' ),
            self::ERR_FILE_SKIPPED               => esc_html__( 'A file could not be added to the backup archive. Check the file permissions; the file was skipped and the backup continued.', 'mainwp-child' ),
            self::ERR_TOO_MANY_SKIPPED           => esc_html__( 'Too many files could not be added to the backup. Check disk space and file permissions on the child site, then try again.', 'mainwp-child' ),
            self::ERR_STALLED                    => esc_html__( 'The backup stopped making progress. The process may have been killed by the host. Check the child site error log and try running the backup again.', 'mainwp-child' ),
            self::ERR_ALREADY_RUNNING            => esc_html__( 'Another backup process is already running on this site. Wait for it to finish or for the stale process to be detected, then try again.', 'mainwp-child' ),
            self::ERR_DL_FILE_NOT_FOUND          => esc_html__( 'The requested backup file was not found on the child site. It may have been removed by a cleanup. Run a new backup and try the download again.', 'mainwp-child' ),
            self::ERR_DL_FILE_CHANGED            => esc_html__( 'The backup file changed on the child site after the download was authorized. Request a new download to get a fresh authorization for the current file.', 'mainwp-child' ),
            self::ERR_DL_TOKEN_INVALID           => esc_html__( 'The download authorization is invalid, expired, or was already used. Request a new download from the Dashboard to get a fresh authorization.', 'mainwp-child' ),

            self::ERR_INCREMENTAL_BASE_MISSING   => esc_html__( 'The file list from the previous backup is missing or unusable, so changed files cannot be identified. The Dashboard will run a full backup instead to start a new chain.', 'mainwp-child' ),
            self::ERR_MANIFEST_TOO_LARGE         => esc_html__( 'The file list from the previous backup is too large to receive. The Dashboard will run a full backup instead.', 'mainwp-child' ),

            self::ERR_MANIFEST_CHUNK_WRONG_PHASE => esc_html__( 'The file-list piece arrived outside of an active staging session on this site. The Dashboard will restart the file-list transfer from the beginning.', 'mainwp-child' ),
            self::ERR_MANIFEST_CHUNK_CORRUPT     => esc_html__( 'The transferred file list does not match its expected checksum and was discarded. The Dashboard will transfer it again or run a full backup instead.', 'mainwp-child' ),

            self::ERR_RESTORE_DB_REPLAY          => esc_html__( 'The database could not be replayed from the backup. The live database was not changed. Check the child site error log for the failing table and try the restore again.', 'mainwp-child' ),
            self::ERR_RESTORE_SWAP_FAILED        => esc_html__( 'The restored tables could not be promoted to live. The previous tables are still in place. Check the child site error log and try the restore again.', 'mainwp-child' ),
            self::ERR_RESTORE_EXTRACT            => esc_html__( 'The backup archive could not be extracted safely. Extraction stopped before any unsafe path was written. Check the child site error log for the rejected entry.', 'mainwp-child' ),
            self::ERR_RESTORE_MULTISITE          => esc_html__( 'Restore is not available for multisite installations in this version.', 'mainwp-child' ),
            self::ERR_RESTORE_WRONG_PHASE        => esc_html__( 'The restore task is not accepting artifact data in its current phase. Check the task status and retry the transfer if it is still receiving.', 'mainwp-child' ),
            self::ERR_RESTORE_CANCELLED          => esc_html__( 'The restore was cancelled from the dashboard. Any tables already replaced were kept aside for rollback.', 'mainwp-child' ),
            self::ERR_CHUNK_GAP                  => esc_html__( 'The artifact data arrived out of order. The Dashboard will resend from the last received byte.', 'mainwp-child' ),
            self::ERR_CHUNK_HASH                 => esc_html__( 'A piece of the artifact failed its integrity check during transfer and was not written. The Dashboard will resend it.', 'mainwp-child' ),
            self::ERR_ARTIFACT_UNKNOWN           => esc_html__( 'The transferred file is not part of this restore. Only artifacts named when the restore was created are accepted.', 'mainwp-child' ),
            self::ERR_ARTIFACT_CORRUPT           => esc_html__( 'The transferred artifact does not match its expected checksum and was discarded. The Dashboard will transfer it again.', 'mainwp-child' ),
            self::ERR_CLOUD_PULL_FAILED          => esc_html__( 'This site could not download the backup from cloud storage directly. The Dashboard will retry using the standard transfer method.', 'mainwp-child' ),
        );
    }
}
