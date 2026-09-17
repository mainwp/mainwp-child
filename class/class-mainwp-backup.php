<?php
/**
 * MainWP Backup
 *
 * This file handles all Child Site backup functions.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


//phpcs:disable WordPress.WP.AlternativeFunctions, Generic.Metrics.CyclomaticComplexity -- Current complexity is the only way to achieve desired results, pull request solutions appreciated.

/**
 * Class MainWP_Backup
 *
 * Handle all Child Site backup functions.
 */
class MainWP_Backup {

    /**
     * Shared marker for legacy dumps that native tasks could overwrite.
     *
     * @var string
     */
    const LEGACY_RUNNING_TRANSIENT = 'mainwp_legacy_backup_running';

    /**
     * Whether to exclude zip archives.
     *
     * @var bool true|false
     */
    protected $excludeZip;

    /**
     * Container for zip file.
     *
     * @var resource ZIP file
     */
    protected $zip;

    /**
     * Backup files count.
     *
     * @var int Files count.
     */
    protected $zipArchiveFileCount;

    /**
     * Directory the current backup archive is being written to.
     *
     * Used by the during-backup disk space floor.
     *
     * @var string Backup directory path.
     */
    protected $backup_dir_in_use = '';

    /**
     * Backup archive file size.
     *
     * @var float File size.
     */
    protected $zipArchiveSizeCount;

    /**
     * Backup archive file name.
     *
     * @var string File name.
     */
    protected $zipArchiveFileName;

    /**
     * Backup file descriptors.
     *
     * @var int File descriptors.
     */
    protected $file_descriptors;

    /**
     * Whether to load file before zip.
     *
     * @var bool true|false
     */
    protected $loadFilesBeforeZip;

    /**
     * Hold the current timeout length.
     *
     * @var int Timeout length.
     */
    protected $timeout;

    /**
     * Last time a backup has been run.
     *
     * @var string Last backup run.
     */
    protected $lastRun;

    /**
     * Garbage collection count.
     *
     * @var int Garbage collection count.
     */
    protected $gcCnt = 0;

    /**
     * Archive test content.
     *
     * @var bool Test backup content.
     */
    protected $testContent;

    /**
     * Backup process archiver.
     *
     * @var object Backup process archiver.
     */
    protected $archiver = null;

    /**
     * Dump files already produced by an earlier phase, so full-backup paths
     * archive them instead of dumping the database a second time.
     *
     * @var array|null Prepared dump file paths, null when unset.
     */
    protected $prepared_db_files = null;

    /**
     * Slice deadline forwarded to the archiver for bounded continuation
     * slices, null for legacy unbounded runs.
     *
     * @var int|null Unix timestamp.
     */
    protected $slice_deadline = null;

    /**
     * Progress callback forwarded to the archiver.
     *
     * @var callable|null Callback.
     */
    protected $progress_callback = null;

    /**
     * Provide dump files an earlier phase already produced.
     *
     * @param array|null $files Dump file paths, or null to clear.
     */
    public function set_prepared_db_files( $files ) {
        $this->prepared_db_files = $files;
    }

    /**
     * Set the slice deadline forwarded to the archiver.
     *
     * @param int|null $deadline Unix timestamp, or null for unbounded.
     */
    public function set_slice_deadline( $deadline ) {
        $this->slice_deadline = $deadline;
    }

    /**
     * Set the progress callback forwarded to the archiver.
     *
     * @param callable|null $callback Callback, or null to clear.
     */
    public function set_progress_callback( $callback ) {
        $this->progress_callback = $callback;
    }

    /**
     * The dump files for a full backup: the prepared set when an earlier
     * phase already dumped, otherwise a fresh dump.
     *
     * @param string $filepath_prefix Dump file prefix.
     * @param object $archiver        Optional archiver for pid-file keepalive.
     *
     * @return array Dump file paths.
     */
    public function get_or_create_db_dump_files( $filepath_prefix, &$archiver = null ) {
        if ( is_array( $this->prepared_db_files ) ) {
            return $this->prepared_db_files;
        }
        return $this->create_backup_db( $filepath_prefix, false, $archiver );
    }

    /**
     * Whether a failed backup's partial archive should be removed.
     *
     * A budget interruption is not a failure - the partial archive is the
     * resume point for the next slice and must survive.
     *
     * @param \Exception $e The failure.
     *
     * @return bool True to delete the partial archive.
     */
    protected function should_discard_partial_on( $e ) {
        return ! ( $e instanceof MainWP_Backup_Budget_Exception );
    }

    /**
     * Protected static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    protected static $instance = null;

    /**
     * Create a public static instance.
     *
     * @return mixed Class instance.
     */
    public static function get() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Create full backup.
     *
     * @param array  $excludes           Files to exclude from the backup.
     * @param string $filePrefix         Backup archive file prefix.
     * @param bool   $addConfig          Add config file to backup.
     * @param bool   $includeCoreFiles   Include WordPress core files.
     * @param int    $file_descriptors   Number of backup archive file descriptors.
     * @param bool   $fileSuffix         Backup archive file suffix.
     * @param bool   $excludezip         Exclude zip files from the backup.
     * @param bool   $excludenonwp       Exclude non-WordPress directories in site root.
     * @param bool   $loadFilesBeforeZip Load files before zip.
     * @param string $ext                Backup file extension.
     * @param bool   $pid                PID true|false.
     * @param bool   $append             Append to backup file name.
     *
     * @return array|bool Action results on success, false on failure.
     *
     * @throws \Exception Engine failures propagate; the partial archive is
     *                    removed unless the failure is a budget interruption.
     *
     * @used-by MainWP_Backup::backup_full()
     *
     * @uses \MainWP\Child\Tar_Archiver()
     * @uses \MainWP\Child\MainWP_Helper::get_mainwp_dir()
     * @uses \MainWP\Child\MainWP_Helper::instance()->error()
     * @uses \MainWP\Child\MainWP_Helper::set_limit()
     */
    public function create_full_backup(
        $excludes,
        $filePrefix = '',
        $addConfig = false,
        $includeCoreFiles = false,
        $file_descriptors = 0,
        $fileSuffix = false,
        $excludezip = false,
        $excludenonwp = false,
        $loadFilesBeforeZip = true,
        $ext = 'zip',
        $pid = false,
        $append = false
    ) {

        $this->file_descriptors   = $file_descriptors;
        $this->loadFilesBeforeZip = $loadFilesBeforeZip;

        $dirs                       = MainWP_Helper::get_mainwp_dir( 'backup' );
        $backupdir                  = $dirs[0];
        $this->backup_dir_in_use    = $backupdir;
        $this->allowed_backup_bases = null;
        static::reset_skipped_paths();
        if ( ! defined( 'PCLZIP_TEMPORARY_DIR' ) ) {

            /**
             * Defines PCLZIP temporary directory.
             *
             * @const ( string ) Temporary backup directory.
             * @source https://code-reference.mainwp.com/classes/MainWP.Child.MainWP_Backup.html
             */
            define( 'PCLZIP_TEMPORARY_DIR', $backupdir );
        }

        if ( false !== $pid ) {
            $pid = trailingslashit( $backupdir ) . 'backup-' . $pid . '.pid';
        }

        // Verify if another backup is running, if so, return an error.
        if ( self::is_pid_lock_fresh( $backupdir, $pid ) ) {
            MainWP_Helper::instance()->error( esc_html__( 'Another backup process is running. Please, try again later.', 'mainwp-child' ), MainWP_Backup_Error::ERR_ALREADY_RUNNING );
        }

        $timestamp = time();
        if ( '' !== $filePrefix ) {
            $filePrefix .= '-';
        }

        if ( 'zip' === $ext ) {
            $this->archiver = null;
            $ext            = '.zip';
        } else {
            $this->archiver = new Tar_Archiver( $this, $ext, $pid );
            $ext            = $this->archiver->get_extension();
            if ( null !== $this->slice_deadline ) {
                $this->archiver->set_budget_deadline( $this->slice_deadline );
            }
            if ( null !== $this->progress_callback ) {
                $this->archiver->set_progress_callback( $this->progress_callback );
            }
        }

        if ( ( false !== $fileSuffix ) && ! empty( $fileSuffix ) ) {
            // Append already contains extension!
            $file = $fileSuffix . ( true === $append ? '' : $ext );
        } else {
            $file = 'backup-' . $filePrefix . $timestamp . $ext;
        }
        $filepath = $backupdir . $file;
        $fileurl  = $file;

        if ( ! $addConfig ) {
            if ( ! in_array( str_replace( ABSPATH, '', WP_CONTENT_DIR ), $excludes, true ) && ! in_array( 'wp-admin', $excludes, true ) && ! in_array( WPINC, $excludes, true ) ) {
                $addConfig        = true;
                $includeCoreFiles = true;
            }
        }

        $this->timeout = $this->get_timeout_from_request();
        $mem           = '512M';
        MainWP_Helper::set_limit( $this->timeout, $mem );

        try {
            if ( null !== $this->archiver ) {
                $success = $this->archiver->create_full_backup( $filepath, $excludes, $addConfig, $includeCoreFiles, $excludezip, $excludenonwp, $append );
            } elseif ( $this->check_zip_support() ) {
                $success = $this->create_zip_full_backup( $filepath, $excludes, $addConfig, $includeCoreFiles, $excludezip, $excludenonwp );
            } else {
                $success = $this->create_zip_pcl_full_backup2( $filepath, $excludes, $addConfig, $includeCoreFiles, $excludezip, $excludenonwp );
            }
        } catch ( \Exception $e ) {
            // Never leave a partial archive behind - a half-written file
            // looks like a backup and restores as corruption. A budget
            // interruption is the one exception: that partial archive is the
            // resume point for the next slice.
            if ( $this->should_discard_partial_on( $e ) && file_exists( $filepath ) ) {
                unlink( $filepath ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- cleanup of partial archive.
            }
            throw $e;
        }

        return ( $success ) ? array(
            'timestamp' => $timestamp,
            'file'      => $fileurl,
            'filesize'  => filesize( $filepath ), // NOSONAR .
        ) : false;
    }

    /**
     * Whether a legacy pid lock is currently held: a `*.pid` file in the
     * backup directory fresher than the window create_full_backup() treats
     * as a live process.
     *
     * Extracted from create_full_backup()'s inline glob so a caller with no
     * pid of its own (the task spine, before it creates a task) can check
     * the same lock. The task spine's get_active_tasks() and this pid lock
     * are two independent single-backup invariants that do not see each
     * other on their own.
     *
     * @param string      $backupdir       Backup directory (glob'd for `*.pid`).
     * @param string|bool $except_pid_path The caller's own pid file path (skipped), or false for none.
     *
     * @return bool True when a fresh pid lock is held by another process.
     */
    public static function is_pid_lock_fresh( $backupdir, $except_pid_path = false ) {
        $files = glob( trailingslashit( $backupdir ) . '*.pid' );
        if ( ! is_array( $files ) ) {
            return false;
        }
        foreach ( $files as $file ) {
            if ( basename( $file ) === basename( $except_pid_path ) ) {
                continue;
            }
            if ( ( time() - filemtime( $file ) ) < 160 ) {
                return true;
            }
        }
        return false;
    }

    /**
     * The final exclude list for a file backup: the requested paths plus the
     * standard MainWP exclusions, optionally expanded with the known
     * third-party backup and cache directories.
     *
     * @param array $excludes      Requested exclude paths (relative to ABSPATH).
     * @param bool  $excludebackup Exclude known backup-plugin directories.
     * @param bool  $excludecache  Exclude known cache directories.
     *
     * @return array Sanitized exclude paths.
     */
    public function assemble_exclude_list( $excludes, $excludebackup, $excludecache ) {
        $excludes   = is_array( $excludes ) ? $excludes : array();
        $excludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/uploads/mainwp';
        $uploadDir  = MainWP_Helper::get_mainwp_dir();
        $excludes[] = str_replace( ABSPATH, '', $uploadDir[0] );
        $excludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/object-cache.php';

        $newExcludes = array();
        foreach ( $excludes as $exclude ) {
            $newExcludes[] = sanitize_text_field( rtrim( $exclude, '/' ) );
        }

        if ( $excludebackup ) {
            $newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/uploads/backupbuddy_backups';
            $newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/uploads/backupbuddy_temp';
            $newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/uploads/pb_backupbuddy';
            $newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/managewp';
            $newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/infinitewp';
            $newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/backups';
            $newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/uploads/backwpup*';
            $newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/plugins/wp-complete-backup/storage';
            $newExcludes[] = '/administrator/backups';
        }

        if ( $excludecache ) {
            $newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/w3tc-cache';
            $newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/w3tc';
            $newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/cache/config';
            $newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/cache/minify';
            $newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/cache/page_enhanced';
            $newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/cache/tmp';
            $newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/cache/supercache';
            $newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/cache/quick-cache';
            $newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/hyper-cache/cache';
            $newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/cache/all';
            $newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/cache/wp-rocket';
        }

        return $newExcludes;
    }

    /**
     * Check whether the file is an archive or not & create a json_encoded, serialized, base64_encoded string.
     *
     * @uses \MainWP\Child\MainWP_Helper::write() Write response data to be sent to the MainWP Dashboard.
     * @uses \MainWP\Child\MainWP_Helper::get_mainwp_dir() Get the MainWP directory.
     * @uses \MainWP\Child\MainWP_Clone::is_archive()
     */
    public function backup_poll() {
        // phpcs:disable WordPress.Security.NonceVerification
        $fileNameUID = isset( $_POST['fileNameUID'] ) ? sanitize_text_field( wp_unslash( $_POST['fileNameUID'] ) ) : '';
        $fileName    = isset( $_POST['fileName'] ) ? sanitize_text_field( wp_unslash( $_POST['fileName'] ) ) : '';
        $type        = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification
        if ( 'full' === $type ) {
            if ( '' !== $fileName ) {
                $backupFile = $fileName;
            } else {
                $backupFile = 'backup-' . $fileNameUID . '-';
            }

            $dirs      = MainWP_Helper::get_mainwp_dir( 'backup' );
            $backupdir = $dirs[0];
            $result    = glob( $backupdir . $backupFile . '*' ); // NOSONAR .

            // Check if archive, set $archiveFile = $file & break.
            $archiveFile = false;
            foreach ( $result as $file ) {
                if ( MainWP_Clone::is_archive( $file, $backupFile, '(.*)' ) ) {
                    $archiveFile = $file;
                    break;
                }
            }

            // When not an archive.
            if ( false === $archiveFile ) {
                MainWP_Helper::write( array() );
            }

            // When archive found.
            MainWP_Helper::write( array( 'size' => filesize( $archiveFile ) ) );
        } else {
            // When not an archive.
            $backupFile = 'dbBackup-' . $fileNameUID . '-*.sql.php';
            $dirs       = MainWP_Helper::get_mainwp_dir( 'backup' );
            $backupdir  = $dirs[0];
            $result     = glob( $backupdir . $backupFile . '*' ); // NOSONAR .

            if ( 0 === count( $result ) ) {
                MainWP_Helper::write( array() );
            }

            $size = 0;
            foreach ( $result as $f ) {
                $size += filesize( $f );
            }
            MainWP_Helper::write( array( 'size' => $size ) );
            exit();
        }
    }

    /**
     * Check if backup already exists or is in the process of backing up.
     *
     * @uses MainWP_Helper::write() Write response data to be sent to the MainWP Dashboard.
     * @uses MainWP_Helper::get_mainwp_dir() Get the MainWP directory.
     * @uses MainWP_Helper::get_wp_filesystem() Get the WordPress filesystem.
     *
     * @uses WP_Filesystem_Base::is_file() Checks if resource is a file.
     * @see https://developer.wordpress.org/reference/classes/wp_filesystem_base/is_file/
     *
     * @uses WP_Filesystem_Base::mtime() Gets the file modification time.
     * @see https://developer.wordpress.org/reference/classes/wp_filesystem_base/mtime/
     *
     * @uses WP_Filesystem_Base::get_contents() Reads entire file into a string.
     * @see https://developer.wordpress.org/reference/classes/wp_filesystem_base/get_contents/
     */
    public function backup_checkpid() {
        // phpcs:disable WordPress.Security.NonceVerification
        $pid = isset( $_POST['pid'] ) ? sanitize_text_field( wp_unslash( $_POST['pid'] ) ) : 0;
        // phpcs:enable WordPress.Security.NonceVerification
        $dirs      = MainWP_Helper::get_mainwp_dir( 'backup' );
        $backupdir = $dirs[0];

        $information = array();

        /**
         * WordPress files system object.
         *
         * @global object
         */
        global $wp_filesystem;

        MainWP_Helper::get_wp_filesystem();

        $pidFile  = trailingslashit( $backupdir ) . 'backup-' . $pid . '.pid';
        $doneFile = trailingslashit( $backupdir ) . 'backup-' . $pid . '.done';
        if ( $wp_filesystem->is_file( $pidFile ) ) {
            $time = $wp_filesystem->mtime( $pidFile );

            $file                  = $wp_filesystem->get_contents( $pidFile );
            $information['file']   = basename( $file );
            $information['status'] = $this->get_checkpid_status( $time, $backupdir );
        } elseif ( $wp_filesystem->is_file( $doneFile ) ) {
            $file                  = $wp_filesystem->get_contents( $doneFile );
            $information['status'] = 'done';
            $information['file']   = basename( $file );
            $information['size']   = filesize( $file );
        } else {
            $information['status'] = 'invalid';
        }

        MainWP_Helper::write( $information );
    }

    /**
     * Get the timeout in seconds for a named preset.
     *
     * Unknown or missing presets fall back to the legacy 20-hour value so
     * older Dashboards that never send a preset see unchanged behavior.
     *
     * @param string $preset Preset name: shared_hosting, standard, large_site, unlimited.
     *
     * @return int Timeout in seconds.
     */
    public static function get_timeout_for_preset( $preset ) {
        $presets = array(
            'shared_hosting' => 30 * 60,
            'standard'       => 2 * 60 * 60,
            'large_site'     => 8 * 60 * 60,
            'unlimited'      => 20 * 60 * 60,
        );
        return isset( $presets[ $preset ] ) ? $presets[ $preset ] : $presets['unlimited'];
    }

    /**
     * Resolve the backup timeout from the Dashboard request.
     *
     * @return int Timeout in seconds.
     */
    public function get_timeout_from_request() {
        // phpcs:disable WordPress.Security.NonceVerification -- authenticated MainWP request.
        $preset = isset( $_POST['timeout_preset'] ) ? sanitize_text_field( wp_unslash( $_POST['timeout_preset'] ) ) : 'unlimited';
        // phpcs:enable WordPress.Security.NonceVerification
        return static::get_timeout_for_preset( $preset );
    }

    /**
     * Decide whether a running backup is busy or stalled from its last
     * progress timestamp.
     *
     * Uses a plain time delta - the previous clock-face minute arithmetic
     * produced wrong (often negative) elapsed times across hour boundaries.
     * While a database dump is in progress (a .sql tmp file exists in the
     * backup directory) the threshold is relaxed, because a single large
     * table can legitimately take longer than the default between progress
     * updates. Note: file_exists() does not expand globs - glob() is
     * required or the relaxed threshold would never activate.
     *
     * @param int      $pid_mtime  Last modification time of the pid file.
     * @param string   $backupdir  Backup directory to inspect for a DB phase.
     * @param int|null $now        Current timestamp override (tests), null for time().
     *
     * @return string 'busy' or 'stalled'.
     */
    public function get_checkpid_status( $pid_mtime, $backupdir, $now = null ) {
        if ( null === $now ) {
            $now = time();
        }
        $seconds_since_progress = $now - (int) $pid_mtime;

        $stall_threshold = 80;
        $sql_tmp         = glob( trailingslashit( $backupdir ) . '*.sql*.tmp' );
        if ( ! empty( $sql_tmp ) ) {
            $stall_threshold = 300; // DB dump phase - allow longer between progress updates.
        }

        return ( $seconds_since_progress < $stall_threshold ) ? 'busy' : 'stalled';
    }

    /**
     * Refuse a legacy Run Now while a task-spine backup is active.
     *
     * The task spine's get_active_tasks() and this engine's pid-file lock
     * (is_pid_lock_fresh()) are two independent single-backup invariants
     * that do not see each other: a legacy Run Now could otherwise start
     * while a spine task is mid-slice, and the spine's next slice would then
     * die on the pid lock instead of reporting a coded refusal up front.
     * Expired orphans are reclaimed first, so a stale non-terminal row left
     * by a crashed slice never blocks a legitimate legacy request forever.
     * Gated on the spine table actually being installed - a site that has
     * never run a native backup has nothing to check.
     *
     * @throws MainWP_Exception When a spine task is active.
     */
    public function assert_no_active_spine_task() {
        if ( MainWP_Child_Backup_Task::DB_VERSION !== get_option( MainWP_Child_Backup_Task::DB_VERSION_OPTION ) ) {
            return;
        }

        $spine = MainWP_Child_Backup_Task::instance();
        $spine->reclaim_expired_tasks();

        if ( ! empty( $spine->get_active_tasks() ) ) {
            throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_ALREADY_RUNNING ) ), null, MainWP_Backup_Error::ERR_ALREADY_RUNNING ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        }
    }

    /**
     * Protect legacy dump fragments until the archive has consumed them.
     */
    public static function set_legacy_backup_running() {
        // A crashed request expires at the legacy engine's three-hour deadness threshold.
        set_transient( self::LEGACY_RUNNING_TRANSIENT, true, 10800 );
    }

    /**
     * Release the fragments for a later native dump.
     */
    public static function clear_legacy_backup_running() {
        delete_transient( self::LEGACY_RUNNING_TRANSIENT );
    }

    /**
     * Perform a backup.
     *
     * @param bool $write Whether or not to execute MainWP_Helper::write(), Default: true.
     *
     * @return array Array of information on the backup containing the type of backup performed, full, or DB & whether or not it was successful.
     *
     * @uses \MainWP\Child\MainWP_Helper::set_limit() Set PHP Memory Limit and PHP Max Execution time values.
     * @uses \MainWP\Child\MainWP_Helper::end_session() End session and flush the output buffer.
     * @uses \MainWP\Child\MainWP_Helper::get_wp_filesystem() Get the WordPress filesystem.
     * @uses \MainWP\Child\MainWP_Helper::ends_with() Check if the String 1 ends with the String 2.
     * @uses \MainWP\Child\MainWP_Helper::write() Write response data to be sent to the MainWP Dashboard.
     * @uses \MainWP\Child\MainWP_Backup::backup_full() Perform a full backup.
     * @uses \MainWP\Child\MainWP_Helper::set_limit()
     * @uses \MainWP\Child\MainWP_Helper::end_session()
     * @uses \MainWP\Child\MainWP_Helper::get_mainwp_dir()
     * @uses \MainWP\Child\MainWP_Helper::get_wp_filesystem()
     * @uses \MainWP\Child\MainWP_Helper::ends_with()
     * @uses \MainWP\Child\MainWP_Helper::write()
     */
    public function backup( $write = true ) {

        try {
            $this->assert_no_active_spine_task();
            $this->check_backup_readiness();
        } catch ( MainWP_Exception $e ) {
            MainWP_Helper::instance()->error( $e->getMessage(), $e->get_message_error_code() );
        }

        // phpcs:disable WordPress.Security.NonceVerification -- authenticated Dashboard dispatch.
        $type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification
        if ( 'full' === $type ) {
            self::set_legacy_backup_running();
        }

        try {
            try {
                $timeout = $this->get_timeout_from_request();
                MainWP_Helper::set_limit( $timeout );

                MainWP_Helper::end_session();

                // Cleanup pid files!
                $dirs      = MainWP_Helper::get_mainwp_dir( 'backup' );
                $backupdir = trailingslashit( $dirs[0] );

                /**
                 * WordPress files system object.
                 *
                 * @global object
                 */
                global $wp_filesystem;

                MainWP_Helper::get_wp_filesystem();

                $files = glob( $backupdir . '*' );
                foreach ( $files as $file ) {
                    if ( MainWP_Helper::ends_with( $file, '/index.php' ) | MainWP_Helper::ends_with( $file, '/.htaccess' ) ) {
                        continue;
                    }

                    if ( ( time() - filemtime( $file ) ) > ( 60 * 60 * 3 ) ) {
                        wp_delete_file( $file );
                    }
                }

                // phpcs:disable WordPress.Security.NonceVerification
                $fileName = isset( $_POST['fileUID'] ) ? sanitize_text_field( wp_unslash( $_POST['fileUID'] ) ) : '';

                if ( 'full' === $type || 'db' === $type || 'files' === $type ) {
                    $this->assert_disk_space_for_backup( $backupdir, 'db' !== $type );
                }

                if ( 'full' === $type || 'files' === $type ) {

                    if ( 'files' === $type ) {
                        // A files-only backup embeds no database dump: an
                        // empty prepared set short-circuits the fresh dump
                        // the archiver would otherwise run. The artifact
                        // rides the full slot like every file archive.
                        $this->set_prepared_db_files( array() );
                    }

                    $res = $this->backup_full( $fileName );

                    if ( 'files' === $type ) {
                        $this->set_prepared_db_files( null );
                    }

                    if ( ! $res ) {
                        $information['full'] = false;
                    } else {
                        $information['full'] = $res['file'];
                        $information['size'] = $res['filesize'];
                    }
                    $information['db'] = false;
                } elseif ( 'db' === $type ) {
                    $ext = isset( $_POST['ext'] ) ? sanitize_text_field( wp_unslash( $_POST['ext'] ) ) : 'zip';
                    $res = $this->backup_db( $fileName, $ext );
                    if ( ! $res ) {
                        $information['db'] = false;
                    } else {
                        $information['db']   = $res['file'];
                        $information['size'] = $res['filesize'];
                    }
                    $information['full'] = false;
                } else {
                    $information['full'] = false;
                    $information['db']   = false;
                }
            } finally {
                // The response helpers exit, so release before the error handler or response write.
                if ( 'full' === $type ) {
                    self::clear_legacy_backup_running();
                }
            }
        } catch ( MainWP_Exception $e ) {
            MainWP_Helper::instance()->error( $e->getMessage(), $e->get_message_error_code() );
        }

        if ( $write ) {
            MainWP_Helper::write( $information );
        }
        // phpcs:enable WordPress.Security.NonceVerification
        return $information;
    }

    /**
     * Perform a full backup.
     *
     * @param string $fileName Backup archive file name.
     *
     * @return array|bool Returns an array containing the Backup location & file size. Return FALSE on failure.
     *
     * @uses \MainWP\Child\MainWP_Backup::create_full_backup() Create full backup.
     * @uses \MainWP\Child\MainWP_Helper::get_mainwp_dir()
     */
    public function backup_full( $fileName ) { //phpcs:ignore -- complex method.
        // phpcs:disable WordPress.Security.NonceVerification
        $excludes = ( isset( $_POST['exclude'] ) ? explode( ',', wp_unslash( $_POST['exclude'] ) ) : array() ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in assemble_exclude_list().

        if ( function_exists( 'posix_uname' ) ) {
            $uname = posix_uname();
            if ( is_array( $uname ) && isset( $uname['nodename'] ) ) {
                if ( stristr( $uname['nodename'], 'hostgator' ) ) {
                    if ( ! isset( $_POST['file_descriptors'] ) || '0' === $_POST['file_descriptors'] || $_POST['file_descriptors'] > 1000 ) {
                        $_POST['file_descriptors'] = 1000;
                    }
                    $_POST['file_descriptors_auto'] = 0;
                    $_POST['loadFilesBeforeZip']    = false;
                }
            }
        }

        $file_descriptors      = ( isset( $_POST['file_descriptors'] ) ? intval( wp_unslash( $_POST['file_descriptors'] ) ) : 0 );
        $file_descriptors_auto = ( isset( $_POST['file_descriptors_auto'] ) ? intval( wp_unslash( $_POST['file_descriptors_auto'] ) ) : 0 );
        if ( 1 === (int) $file_descriptors_auto ) {
            if ( function_exists( 'posix_getrlimit' ) ) {
                $result = posix_getrlimit();
                if ( isset( $result['soft openfiles'] ) ) {
                    $file_descriptors = $result['soft openfiles'];
                }
            }
        }

        $loadFilesBeforeZip = ( isset( $_POST['loadFilesBeforeZip'] ) ? sanitize_text_field( wp_unslash( $_POST['loadFilesBeforeZip'] ) ) : true );

        $excludebackup = ( isset( $_POST['excludebackup'] ) && '1' === $_POST['excludebackup'] );
        $excludecache  = ( isset( $_POST['excludecache'] ) && '1' === $_POST['excludecache'] );
        $excludezip    = ( isset( $_POST['excludezip'] ) && '1' === $_POST['excludezip'] );
        $excludenonwp  = ( isset( $_POST['excludenonwp'] ) && '1' === $_POST['excludenonwp'] );

        $newExcludes = $this->assemble_exclude_list( $excludes, $excludebackup, $excludecache );

        $file = false;
        if ( isset( $_POST['f'] ) ) {
            $file = ! empty( $_POST['f'] ) ? MainWP_Helper::sanitize_filename( wp_unslash( $_POST['f'] ) ) : false; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via MainWP_Helper::sanitize_filename().
        } elseif ( isset( $_POST['file'] ) ) {
            $file = ! empty( $_POST['file'] ) ? MainWP_Helper::sanitize_filename( wp_unslash( $_POST['file'] ) ) : false; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via MainWP_Helper::sanitize_filename().
        }

        $ext = isset( $_POST['ext'] ) ? sanitize_text_field( wp_unslash( $_POST['ext'] ) ) : 'zip';
        $pid = isset( $_POST['pid'] ) ? sanitize_text_field( wp_unslash( $_POST['pid'] ) ) : false;

        $append = ( isset( $_POST['append'] ) && ( '1' === $_POST['append'] ) );
        // phpcs:enable WordPress.Security.NonceVerification
        return $this->create_full_backup( $newExcludes, $fileName, true, true, $file_descriptors, $file, $excludezip, $excludenonwp, $loadFilesBeforeZip, $ext, $pid, $append );
    }

    /**
     * Backup site database.
     *
     * @param string $fileName Backup arhive file name.
     * @param string $ext      Backup achive extension.
     *
     * @uses \MainWP\Child\MainWP_Helper::update_option() Update database option by option name.
     * @uses \MainWP\Child\MainWP_Helper::get_mainwp_dir() Get the MainWP directory.
     * @uses \MainWP\Child\MainWP_Backup::create_backup_db() Create database backup.
     *
     * @return array|bool $success Returns an array containing the Backup location & file size. Return FALSE on failure.
     */
    public function backup_db( $fileName = '', $ext = 'zip' ) {
        self::set_legacy_backup_running();
        try {
            $dirs      = MainWP_Helper::get_mainwp_dir( 'backup' );
            $dir       = $dirs[0];
            $timestamp = time();

            if ( '' !== $fileName ) {
                $fileName .= '-';
            }

            $filepath_prefix = $dir . 'dbBackup-' . $fileName . $timestamp;

            $dh = opendir( $dir );

            if ( $dh ) {
                while ( ( $file = readdir( $dh ) ) !== false ) {
                    if ( '.' !== $file && '..' !== $file && ( preg_match( '/dbBackup-(.*).sql(\.zip|\.tar|\.tar\.gz|\.tar\.bz2|\.tmp)?$/', $file ) ) ) {
                        wp_delete_file( $dir . $file );
                    }
                    if ( '.' !== $file && '..' !== $file && ( preg_match( '/dbBackup-(.*).sql.php(\.zip|\.tar|\.tar\.gz|\.tar\.bz2|\.tmp)?$/', $file ) ) ) {
                        wp_delete_file( $dir . $file );
                    }
                }
                closedir( $dh );
            }

            // $ext !== false, the fuction will return in format: array( 'filepath' => $filepath );.
            $result = $this->create_backup_db( $filepath_prefix, $ext );

            MainWP_Helper::update_option( 'mainwp_child_last_db_backup_size', filesize( $result['filepath'] ) ); // NOSONAR .

            return ( ! $result ) ? false : array(
                'timestamp' => $timestamp,
                'file'      => basename( $result['filepath'] ),
                'filesize'  => filesize( $result['filepath'] ), // NOSONAR .
            );
        } finally {
            self::clear_legacy_backup_running();
        }
    }

    /**
     * Create the ZIP file.
     *
     * @param array  $files Files to zip.
     * @param string $archive Type of archive to create.
     *
     * @return bool Return FALSE on failure, TRUE on success.
     *
     * @uses \MainWP\Child\MainWP_Helper::set_limit() Set PHP memory limit.
     */
    public function zip_file( $files, $archive ) {
        $this->timeout = $this->get_timeout_from_request();
        $mem           = '512M';
        MainWP_Helper::set_limit( $this->timeout, $mem );

        if ( ! is_array( $files ) ) {
            $files = array( $files );
        }

        if ( null !== $this->archiver ) {
            $success = $this->archiver->zip_file( $files, $archive );
        } elseif ( $this->check_zip_support() ) {
            $success = $this->m_zip_file( $files, $archive );
        } else {
            $success = $this->m_zip_file_pcl( $files, $archive );
        }

        return $success;
    }

    /**
     * Create m_zip_file.
     *
     * @param array  $files   Files to zip.
     * @param string $archive Type of archive to create.
     *
     * @return bool Return false on failure.
     */
    public function m_zip_file( $files, $archive ) {
        $this->zip                 = new \ZipArchive();
        $this->zipArchiveFileCount = 0;
        $this->zipArchiveSizeCount = 0;

        $zipRes = $this->zip->open( $archive, \ZipArchive::CREATE );
        if ( $zipRes ) {
            foreach ( $files as $file ) {
                $this->add_file_to_zipp( $file, basename( $file ) );
            }

            $this->close_zip_archive();
            return true;
        }

        return false;
    }

    /**
     * Use pclzip to add files to the zip archive.
     *
     * @param array  $files   Files to zip.
     * @param string $archive Type of archive to create.
     *
     * @return array $rslt Return array of results.
     */
    public function m_zip_file_pcl( $files, $archive ) {
        // Zip this backup folder.
        require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
        $this->zip = new \PclZip( $archive );

        $error = false;
        foreach ( $files as $file ) {
            $rslt = $this->zip->add( $file, PCLZIP_OPT_REMOVE_PATH, dirname( $file ) );
            if ( empty( $rslt ) ) {
                $error = true;
            }
        }

        return ! $error;
    }

    /**
     * Check for default PHP zip support.
     *
     * @return bool Returns true if class_name is a defined class, false otherwise.
     */
    public function check_zip_support() {
        return class_exists( '\ZipArchive' );
    }

    /**
     * Paths skipped during the current backup traversal.
     *
     * @var string[] Skipped paths.
     */
    protected static $skipped_paths = array();

    /**
     * Number of files that failed to be added to the archive in this run.
     *
     * @var int Failed add count.
     */
    protected $failed_add_count = 0;

    /**
     * Abort threshold for failed file adds - past this the archive is
     * considered too incomplete to be a trustworthy backup.
     */
    const MAX_FAILED_ADDS = 10;

    /**
     * Transient key caching the wp-content inventory (total file bytes and
     * file count) so a size estimate does not re-walk the tree on every call
     * within a backup run.
     */
    const CONTENT_INVENTORY_TRANSIENT = 'mainwp_backup_content_inventory';

    /**
     * Allowed containment bases for the current backup traversal, null for
     * the defaults (ABSPATH and WP_CONTENT_DIR).
     *
     * @var array|null Base directories.
     */
    protected $allowed_backup_bases = null;

    /**
     * Decide whether a path is safe to include in a backup.
     *
     * Symlinks are never followed - they can leak files outside the site root
     * (other site roots on shared hosting, /etc) and circular links recurse
     * forever. Every included path must also realpath-resolve inside an
     * allowed base; the compare appends a trailing slash on both sides so a
     * sibling directory sharing the base as a string prefix
     * (/base-evil vs /base) cannot pass.
     *
     * @param string     $path  Path to validate.
     * @param array|null $bases Allowed base directories, null for ABSPATH + WP_CONTENT_DIR.
     *
     * @return bool True when the path may be included.
     */
    public static function is_safe_backup_path( $path, $bases = null ) {
        $path = (string) $path;
        if ( '' === $path || is_link( rtrim( $path, '/' ) ) ) {
            return false;
        }
        $real = realpath( $path );
        if ( false === $real ) {
            return false;
        }
        if ( null === $bases ) {
            $bases = array( ABSPATH, WP_CONTENT_DIR );
        }
        foreach ( $bases as $base ) {
            $real_base = realpath( $base );
            if ( false === $real_base ) {
                continue;
            }
            $real_base = rtrim( $real_base, '/' );
            if ( 0 === strpos( $real . '/', $real_base . '/' ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Record a path skipped by the traversal guards, for the backup report.
     *
     * @param string $path Skipped path.
     */
    public static function record_skipped_path( $path ) {
        if ( count( static::$skipped_paths ) < 100 ) {
            static::$skipped_paths[] = (string) $path;
        }
        /**
         * Fires when the backup traversal skips a symlink or unsafe path.
         *
         * @param string $path The skipped path.
         */
        do_action( 'mainwp_child_backup_skipped_path', $path );
    }

    /**
     * Get the paths skipped during the current backup traversal.
     *
     * @return string[] Skipped paths.
     */
    public static function get_skipped_paths() {
        return static::$skipped_paths;
    }

    /**
     * Reset the skipped-paths record (start of a backup run).
     */
    public static function reset_skipped_paths() {
        static::$skipped_paths = array();
    }

    /**
     * Record a file that could not be added to the archive; abort the backup
     * once too many adds have failed the archive would otherwise report success while incomplete.
     *
     * A handful of unreadable files is survivable and reported; past the
     * threshold the archive is too incomplete to pass off as a backup.
     *
     * @param string $path File that failed to be added.
     *
     * @throws MainWP_Exception When the failed-add threshold is exceeded.
     */
    public function record_failed_add( $path ) {
        ++$this->failed_add_count;
        static::record_skipped_path( $path );
        if ( $this->failed_add_count > self::MAX_FAILED_ADDS ) {
            throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_TOO_MANY_SKIPPED ) ), null, MainWP_Backup_Error::ERR_TOO_MANY_SKIPPED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message is escaped, code is a registry class constant.
        }
    }

    /**
     * Get the number of failed file adds in the current run.
     *
     * @return int Failed add count.
     */
    public function get_failed_add_count() {
        return $this->failed_add_count;
    }

    /**
     * Reset the failed-add counter (start of a backup run).
     */
    public function reset_failed_adds() {
        $this->failed_add_count = 0;
    }

    /**
     * Close the ZIP archive and verify the close succeeded.
     *
     * ZipArchive::close() is where the archive is actually written - ignoring
     * its result reports success over a corrupt or missing archive.
     *
     * @throws MainWP_Exception When the archive could not be finalized.
     */
    public function close_zip_archive() {
        $result = false;
        if ( $this->zip instanceof \ZipArchive ) {
            $result = @$this->zip->close(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- failure is converted to a coded exception below.
        }
        if ( true !== $result ) {
            throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_ZIP_FAILED ) ), null, MainWP_Backup_Error::ERR_ZIP_FAILED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message is escaped, code is a registry class constant.
        }
    }

    /**
     * Estimate the size of a backup in bytes.
     *
     * Database size comes from SHOW TABLE STATUS; site files from a recursive
     * directory walk of wp-content. Both are estimates - the result is used
     * with a safety margin, never as an exact requirement.
     *
     * @param bool $include_files Include the wp-content directory size (full backups).
     *
     * @return int Estimated backup size in bytes.
     */
    public function estimate_backup_size( $include_files = true ) {
        global $wpdb;
        $size   = 0;
        $tables = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- size inventory, no caching applicable.
        if ( is_array( $tables ) ) {
            foreach ( $tables as $table ) {
                $size += (int) ( isset( $table['Data_length'] ) ? $table['Data_length'] : 0 );
                $size += (int) ( isset( $table['Index_length'] ) ? $table['Index_length'] : 0 );
            }
        }
        if ( $include_files ) {
            $inventory = static::get_content_inventory();
            $size     += $inventory['bytes'];
        }
        return $size;
    }

    /**
     * Inventory of WP_CONTENT_DIR - total regular-file bytes and file count.
     *
     * The recursive stat-walk is the costly part of a size estimate, so the
     * result is cached briefly. The preflight readiness report and the pre-
     * backup disk-space check both read this inventory instead of walking
     * wp-content twice in the same backup run.
     *
     * @return array Inventory with 'bytes' (int) and 'count' (int) keys.
     */
    protected static function get_content_inventory() {
        $cached = get_transient( static::CONTENT_INVENTORY_TRANSIENT );
        if ( is_array( $cached ) && isset( $cached['bytes'], $cached['count'] ) ) {
            return $cached;
        }
        $inventory = static::get_directory_inventory( WP_CONTENT_DIR );
        set_transient( static::CONTENT_INVENTORY_TRANSIENT, $inventory, HOUR_IN_SECONDS );
        return $inventory;
    }

    /**
     * Sum the size and count of all regular files under a directory, skipping
     * symlinks.
     *
     * @param string $dir Directory to measure.
     *
     * @return array Inventory with 'bytes' (int) and 'count' (int) keys.
     */
    private static function get_directory_inventory( $dir ) {
        $bytes = 0;
        $count = 0;
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
                \RecursiveIteratorIterator::LEAVES_ONLY,
                \RecursiveIteratorIterator::CATCH_GET_CHILD
            );
            foreach ( $iterator as $file ) {
                if ( $file->isFile() && ! $file->isLink() ) {
                    $bytes += (int) $file->getSize();
                    ++$count;
                }
            }
        } catch ( \Exception $e ) {
            // Unreadable subtree - the walk is best-effort for an estimate.
            unset( $e );
        }
        return array(
            'bytes' => $bytes,
            'count' => $count,
        );
    }

    /**
     * Require enough free disk space for an estimated backup before starting.
     *
     * Advisory hosts (disk_free_space disabled or failing) pass - the during-
     * backup floor in assert_disk_space_during_backup() still protects them.
     *
     * @param string $backup_dir        Directory the backup will be written to.
     * @param bool   $include_files     Whether site files are part of the backup.
     * @param float  $safety_multiplier Free-space margin over the estimate.
     *
     * @throws MainWP_Exception When free space is below the safety margin.
     */
    public function assert_disk_space_for_backup( $backup_dir, $include_files = true, $safety_multiplier = 1.5 ) {
        if ( ! function_exists( 'disk_free_space' ) ) {
            return;
        }
        $free = @disk_free_space( $backup_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- advisory probe, host may disable it.
        if ( false === $free ) {
            return;
        }
        $required = $this->estimate_backup_size( $include_files ) * $safety_multiplier;
        if ( $free < $required ) {
            throw new MainWP_Exception(
                sprintf(
                    /* translators: 1: required disk space, 2: available disk space */
                    esc_html__( 'Insufficient disk space for the backup. Estimated need %1$s, available %2$s. Free up disk space on the child site and try again.', 'mainwp-child' ),
                    esc_html( size_format( $required ) ),
                    esc_html( size_format( $free ) )
                ),
                null,
                MainWP_Backup_Error::ERR_DISK_FULL // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message parts are escaped, code is a registry class constant.
            );
        }
    }

    /**
     * Hard low-disk floor checked during long archive writes.
     *
     * @param string   $backup_dir Directory the archive is being written to.
     * @param int|null $min_free   Minimum free bytes, defaults to 100MB.
     *
     * @throws MainWP_Exception When free space drops below the floor.
     */
    public static function assert_disk_space_during_backup( $backup_dir, $min_free = null ) {
        if ( null === $min_free ) {
            $min_free = 100 * 1024 * 1024;
        }
        if ( ! function_exists( 'disk_free_space' ) ) {
            return;
        }
        $free = @disk_free_space( $backup_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- advisory probe, host may disable it.
        if ( false === $free ) {
            return;
        }
        if ( $free < $min_free ) {
            throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_DISK_FULL ) ), null, MainWP_Backup_Error::ERR_DISK_FULL ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message is escaped, code is a registry class constant.
        }
    }

    /**
     * Validate the child server environment before a backup starts.
     *
     * Returns an advisory readiness report for the Dashboard; throws only on
     * conditions that make any backup impossible (unwritable backup directory,
     * dead database connection). The report includes the backup directory
     * secret so the Dashboard can track the randomized directory.
     *
     * @return array Readiness report sent to the Dashboard.
     * @throws MainWP_Exception When critical requirements are missing.
     */
    public function check_backup_readiness() {
        $report = array(
            'disk_free'          => false,
            'dir_writable'       => false,
            'db_connected'       => false,
            'memory_limit'       => ini_get( 'memory_limit' ),
            'max_exec_time'      => ini_get( 'max_execution_time' ),
            'zip_support'        => $this->check_zip_support(),
            'gz_support'         => function_exists( 'gzopen' ),
            'bz2_support'        => function_exists( 'bzopen' ),
            'time_limit_ok'      => ! in_array( 'set_time_limit', array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) ), true ),
            'backup_dir_hash'    => MainWP_Helper::get_backup_dir_hash(),
            'task_spine'         => 1,
            'download_token'     => 1,
            'incremental'        => 1,
            'manifest_chunk'     => 1,
            'restore_files'      => 1,

            // Restores can pull artifacts straight from cloud storage
            // via presigned URLs instead of the staged chunk transfer.
            'cloud_restore_pull' => 1,

            // The install's multisite state, and whether this child can
            // restore a whole network point in time. The Dashboard
            // stamps both so it can gate restore on multisite children
            // running an older child plugin.
            'is_multisite'       => is_multisite() ? 1 : 0,
            'restore_multisite'  => is_multisite() ? 1 : 0,
        );

        // 1. Backup directory writable (randomized directory, created on demand).
        $dirs       = MainWP_Helper::get_mainwp_dir( 'backup', false );
        $backup_dir = $dirs[0];
        if ( ! is_dir( $backup_dir ) || ! is_writable( $backup_dir ) ) {
            throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_DIR_NOT_WRITABLE ) ), null, MainWP_Backup_Error::ERR_DIR_NOT_WRITABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message is escaped, code is a registry class constant.
        }
        $report['dir_writable'] = true;

        // 2. Disk space - advisory only; the Dashboard decides the threshold.
        // disk_free_space() may be disabled or inaccurate on network mounts.
        if ( function_exists( 'disk_free_space' ) ) {
            $free = @disk_free_space( $backup_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- advisory probe, host may disable it.
            if ( false !== $free ) {
                $report['disk_free'] = $free;
                if ( $free < 50 * 1024 * 1024 ) {
                    $report['disk_warning'] = sprintf(
                        /* translators: %s: available disk space */
                        esc_html__( 'Low disk space: %s available.', 'mainwp-child' ),
                        size_format( $free )
                    );
                }
            }
        }

        // 3. Database connection alive.
        global $wpdb;
        if ( method_exists( $wpdb, 'check_connection' ) ) {
            $report['db_connected'] = $wpdb->check_connection( false );
        } else {
            $report['db_connected'] = (bool) $wpdb->get_var( 'SELECT 1' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- connectivity probe, no caching applicable.
        }
        if ( ! $report['db_connected'] ) {
            throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_DB_CONNECTION_LOST ) ), null, MainWP_Backup_Error::ERR_DB_CONNECTION_LOST ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message is escaped, code is a registry class constant.
        }

        // 4. Size estimate and wp-content file count, so the Dashboard can
        // guard against ZIP archiving on large sites: ZIP restarts from zero on
        // each slice timeout, while tar.gz appends and resumes. The wp-content
        // walk is cached so reporting the estimate does not double the IO before
        // the backup runs.
        $report['estimated_bytes'] = $this->estimate_backup_size( true );
        $inventory                 = static::get_content_inventory();
        $report['file_count']      = $inventory['count'];

        return $report;
    }

    /**
     * Create full backup using default PHP zip library.
     *
     * @param string $filepath           File path to create.
     * @param array  $excludes           Files to exclude from the backup.
     * @param bool   $addConfig          Add config file to backup.
     * @param bool   $includeCoreFiles   Include WordPress core files.
     * @param bool   $excludezip         Exclude zip files from the backup.
     * @param bool   $excludenonwp       Exclude non-WordPress directories in site root.
     *
     * @uses \MainWP\Child\MainWP_Helper::starts_with() Check if the String 1 starts with the String 2.
     * @uses \MainWP\Child\MainWP_Helper::in_excludes() Check if the value is in the excludes list.
     * @uses \MainWP\Child\MainWP_Backup::include_core_files() Include core files in backup.
     * @uses \MainWP\Child\MainWP_Backup::add_config() Add config file to backup.
     *
     * @return bool Return true on success & false on failure.
     */
    public function create_zip_full_backup(
        $filepath,
        $excludes,
        $addConfig,
        $includeCoreFiles,
        $excludezip,
        $excludenonwp
    ) {

        $this->excludeZip          = $excludezip;
        $this->zip                 = new \ZipArchive();
        $this->zipArchiveFileCount = 0;
        $this->zipArchiveSizeCount = 0;
        $this->zipArchiveFileName  = $filepath;
        $zipRes                    = $this->zip->open( $filepath, \ZipArchive::CREATE );
        if ( $zipRes ) {
            $nodes = glob( ABSPATH . '*' );
            if ( ! $includeCoreFiles ) {
                $this->include_core_files( $nodes );
            }

            $db_files = $this->get_or_create_db_dump_files( dirname( $filepath ) . DIRECTORY_SEPARATOR . 'dbBackup' );
            foreach ( $db_files as $db_file ) {
                $this->add_file_to_zipp( $db_file, basename( WP_CONTENT_DIR ) . '/' . basename( $db_file ) );
            }

            if ( file_exists( ABSPATH . '.htaccess' ) ) {
                $this->add_file_to_zipp( ABSPATH . '.htaccess', 'mainwp-htaccess' );
            }

            foreach ( $nodes as $node ) {
                if ( ! static::is_safe_backup_path( $node, $this->allowed_backup_bases ) ) {
                    static::record_skipped_path( $node );
                    continue;
                }

                if ( $excludenonwp && is_dir( $node ) ) {
                    if ( ! MainWP_Helper::starts_with( $node, WP_CONTENT_DIR ) && ! MainWP_Helper::starts_with( $node, ABSPATH . 'wp-admin' ) && ! MainWP_Helper::starts_with( $node, ABSPATH . WPINC ) ) {
                        continue;
                    }
                }

                if ( ! MainWP_Helper::excluded_from_backup( $excludes, str_replace( ABSPATH, '', $node ) ) ) {
                    if ( is_dir( $node ) ) {
                        $this->zip_add_dir( $node, $excludes );
                    } elseif ( is_file( $node ) ) {
                        $this->add_file_to_zipp( $node, str_replace( ABSPATH, '', $node ) );
                    }
                }
            }

            if ( $addConfig ) {
                $this->add_config();
            }

            $this->close_zip_archive();
            foreach ( $db_files as $db_file ) {
                wp_delete_file( $db_file ); // NOSONAR .
            }

            return true;
        }

        return false;
    }

    /**
     * Exclude certain core files from the backup.
     *
     * @param array $nodes Default nodes.
     *
     * @uses \MainWP\Child\MainWP_Helper::starts_with() Check if the String 1 starts with the String 2.
     */
    private function include_core_files( &$nodes ) {
        $coreFiles = array(
            'favicon.ico',
            'index.php',
            'license.txt',
            'readme.html',
            'wp-activate.php',
            'wp-app.php',
            'wp-blog-header.php',
            'wp-comments-post.php',
            'wp-config.php',
            'wp-config-sample.php',
            'wp-cron.php',
            'wp-links-opml.php',
            'wp-load.php',
            'wp-login.php',
            'wp-mail.php',
            'wp-pass.php',
            'wp-register.php',
            'wp-settings.php',
            'wp-signup.php',
            'wp-trackback.php',
            'xmlrpc.php',
        );
        foreach ( $nodes as $key => $node ) {
            if ( MainWP_Helper::starts_with( $node, ABSPATH . WPINC ) ) {
                unset( $nodes[ $key ] );
            } elseif ( MainWP_Helper::starts_with( $node, ABSPATH . basename( admin_url( '' ) ) ) ) {
                unset( $nodes[ $key ] );
            } else {
                foreach ( $coreFiles as $coreFile ) {
                    if ( ABSPATH . $coreFile === $node ) {
                        unset( $nodes[ $key ] );
                    }
                }
            }
        }
        unset( $coreFiles );
    }

    /**
     * Add config file to backup.
     *
     * @uses wp_json_encode() Encode a variable into JSON, with some sanity checks.
     * @see https://developer.wordpress.org/reference/functions/wp_json_encode/
     *
     * @uses get_option() Get option by name.
     * @see https://developer.wordpress.org/reference/functions/get_option/
     */
    public function add_config() {

        /**
         * WordPress Database instance.
         *
         * @global object $wpdb
         */
        global $wpdb;

        $plugins = array();
        $dir     = WP_CONTENT_DIR . '/plugins/';
        $fh      = opendir( $dir );
        while ( $entry = readdir( $fh ) ) {
            if ( ! is_dir( $dir . $entry ) ) {
                continue;
            }
            if ( ( '.' === $entry ) || ( '..' === $entry ) ) {
                continue;
            }
            $plugins[] = $entry;
        }
        closedir( $fh );

        $themes = array();
        $dir    = WP_CONTENT_DIR . '/themes/';
        $fh     = opendir( $dir );
        while ( $entry = readdir( $fh ) ) {
            if ( ! is_dir( $dir . $entry ) ) {
                continue;
            }
            if ( ( '.' === $entry ) || ( '..' === $entry ) ) {
                continue;
            }
            $themes[] = $entry;
        }
        closedir( $fh );
        $string = wp_json_encode(
            array(
                'siteurl' => get_option( 'siteurl' ),
                'home'    => get_option( 'home' ),
                'abspath' => ABSPATH,
                'prefix'  => $wpdb->prefix,
                'lang'    => defined( 'WPLANG' ) ? WPLANG : '',
                'plugins' => $plugins,
                'themes'  => $themes,
            )
        );
        $this->add_file_from_string_to_zip( 'clone/config.txt', $string );
    }

    /**
     * Copy directory.
     *
     * @param array  $nodes        Default nodes.
     * @param array  $excludes     Files & directories to exclude.
     * @param string $backupfolder Backup folder.
     * @param bool   $excludenonwp Whether or not to exclude any wp core files.
     *
     * @throws MainWP_Exception When disk space runs out during the copy.
     *
     * @uses \MainWP\Child\MainWP_Helper::starts_with() Check if the String 1 starts with the String 2.
     * @uses \MainWP\Child\MainWP_Helper::in_excludes() Check if the value is in the excludes list.
     * @uses \MainWP\Child\MainWP_Helper::ends_with() Check if the String 1 ends with the String 2.
     */
    public function copy_dir( $nodes, $excludes, $backupfolder, $excludenonwp ) {
        if ( ! is_array( $nodes ) ) {
            return;
        }

        foreach ( $nodes as $node ) {
            if ( ! static::is_safe_backup_path( $node, $this->allowed_backup_bases ) ) {
                static::record_skipped_path( $node );
                continue;
            }

            if ( $excludenonwp && is_dir( $node ) ) {
                if ( ! MainWP_Helper::starts_with( $node, WP_CONTENT_DIR ) && ! MainWP_Helper::starts_with( $node, ABSPATH . 'wp-admin' ) && ! MainWP_Helper::starts_with( $node, ABSPATH . WPINC ) ) {
                    continue;
                }
            }

            if ( ! MainWP_Helper::excluded_from_backup( $excludes, str_replace( ABSPATH, '', $node ) ) ) {
                if ( is_dir( $node ) ) {
                    $target_dir = str_replace( ABSPATH, $backupfolder, $node );
                    if ( ! file_exists( $target_dir ) && ! @mkdir( $target_dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- failure converted to a coded exception below.
                        throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_PERMISSIONS ) ), null, MainWP_Backup_Error::ERR_PERMISSIONS ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message is escaped, code is a registry class constant.
                    }

                    $newnodes = glob( $node . DIRECTORY_SEPARATOR . '*' );
                    $this->copy_dir( $newnodes, $excludes, $backupfolder, $excludenonwp, false );
                    unset( $newnodes );
                } elseif ( is_file( $node ) ) {
                    if ( $this->excludeZip && MainWP_Helper::ends_with( $node, '.zip' ) ) {
                        continue;
                    }

                    if ( ! @copy( $node, str_replace( ABSPATH, $backupfolder, $node ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- failure recorded; backup aborts past the failed-add threshold.
                        $this->record_failed_add( $node );
                    }
                }
            }
        }
    }

    /**
     * Create PCL zip full backup 2.
     *
     * @param string $filepath         Path to file.
     * @param array  $excludes         Files & directories to exclude.
     * @param bool   $addConfig        Add config file to backup.
     * @param bool   $includeCoreFiles Whether to include core files.
     * @param bool   $excludezip       Whether to exclude zip archives.
     * @param bool   $excludenonwp     Whether or not to exclude any wp core files.
     *
     * @return bool Return true on success.
     *
     * @uses \MainWP\Child\MainWP_Helper::delete_dir() Delete wanted directory.
     * @uses \MainWP\Child\MainWP_Helper::starts_with() Check if the String 1 starts with the String 2.
     * @uses \MainWP\Child\MainWP_Backup::create_backup_db() Create database backup.
     * @uses \MainWP\Child\MainWP_Backup::add_file_from_string_to_pcl_zip() Add file from a string to pclzip file.
     *
     * @uses wp_json_encode() Encode a variable into JSON, with some sanity checks.
     * @see https://developer.wordpress.org/reference/functions/wp_json_encode/
     *
     * @uses get_option() Get option by name.
     * @see https://developer.wordpress.org/reference/functions/get_option/
     */
    public function create_zip_pcl_full_backup2( $filepath, $excludes, $addConfig, $includeCoreFiles, $excludezip, $excludenonwp ) {
        // Create backup folder.
        $backupFolder = dirname( $filepath ) . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR;

        mkdir( $backupFolder ); // phpcs:ignore -- required to achieve desired results. Pull requests appreciated.

        // Create DB backup.
        $db_files = $this->get_or_create_db_dump_files( $backupFolder . 'dbBackup' );

        // Copy installation to backup folder.
        $nodes = glob( ABSPATH . '*' );
        if ( ! $includeCoreFiles ) {
            $coreFiles = array(
                'favicon.ico',
                'index.php',
                'license.txt',
                'readme.html',
                'wp-activate.php',
                'wp-app.php',
                'wp-blog-header.php',
                'wp-comments-post.php',
                'wp-config.php',
                'wp-config-sample.php',
                'wp-cron.php',
                'wp-links-opml.php',
                'wp-load.php',
                'wp-login.php',
                'wp-mail.php',
                'wp-pass.php',
                'wp-register.php',
                'wp-settings.php',
                'wp-signup.php',
                'wp-trackback.php',
                'xmlrpc.php',
            );
            foreach ( $nodes as $key => $node ) {
                if ( MainWP_Helper::starts_with( $node, ABSPATH . WPINC ) ) {
                    unset( $nodes[ $key ] );
                } elseif ( MainWP_Helper::starts_with( $node, ABSPATH . basename( admin_url( '' ) ) ) ) {
                    unset( $nodes[ $key ] );
                } else {
                    foreach ( $coreFiles as $coreFile ) {
                        if ( ABSPATH . $coreFile === $node ) {
                            unset( $nodes[ $key ] );
                        }
                    }
                }
            }
            unset( $coreFiles );
        }
        $this->copy_dir( $nodes, $excludes, $backupFolder, $excludenonwp, true );

        foreach ( $db_files as $db_file ) {
            copy( $db_file, $backupFolder . basename( WP_CONTENT_DIR ) . '/' . basename( $db_file ) ); // phpcs:ignore -- required to achieve desired results. Pull requests appreciated // NOSONAR .
            wp_delete_file( $db_file ); // phpcs:ignore -- required to achieve desired results. Pull requests appreciated // NOSONAR.
        }

        unset( $nodes );

        // Zip this backup folder.
        require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
        $this->zip = new \PclZip( $filepath );
        $this->zip->create( $backupFolder, PCLZIP_OPT_REMOVE_PATH, $backupFolder );
        if ( $addConfig ) {

            /**
             * WordPress Database instance.
             *
             * @global object $wpdb
             */
            global $wpdb;

            $string = wp_json_encode(
                array(
                    'siteurl' => get_option( 'siteurl' ),
                    'home'    => get_option( 'home' ),
                    'abspath' => ABSPATH,
                    'prefix'  => $wpdb->prefix,
                    'lang'    => WPLANG,
                )
            );

            $this->add_file_from_string_to_pcl_zip( 'clone/config.txt', $string, $filepath );
        }
        // Remove backup folder.
        MainWP_Helper::delete_dir( $backupFolder );

        return true;
    }

    /**
     * Recursively add directory to default PHP zip library.
     *
     * Symlinks and paths resolving outside the allowed bases are skipped and
     * recorded.
     *
     * @param string     $path     Path to directory.
     * @param array      $excludes Files or directories to exclude.
     * @param array|null $bases    Allowed containment bases, null for the defaults.
     *
     * @uses \MainWP\Child\MainWP_Helper::in_excludes() Check if the value is in the excludes list.
     */
    public function zip_add_dir( $path, $excludes, $bases = null ) {

        if ( null !== $bases ) {
            $this->allowed_backup_bases = $bases;
        }

        if ( ! static::is_safe_backup_path( $path, $this->allowed_backup_bases ) ) {
            static::record_skipped_path( $path );
            return;
        }

        if ( $this->zip instanceof \ZipArchive ) {
            $this->zip->addEmptyDir( str_replace( ABSPATH, '', $path ) );
        } else {
            $this->zip->add_empty_dir( str_replace( ABSPATH, '', $path ) );
        }

        if ( file_exists( rtrim( $path, '/' ) . '/.htaccess' ) ) {
            $this->add_file_to_zipp( rtrim( $path, '/' ) . '/.htaccess', rtrim( str_replace( ABSPATH, '', $path ), '/' ) . '/mainwp-htaccess' );
        }

        $iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $path ), \RecursiveIteratorIterator::SELF_FIRST );

        foreach ( $iterator as $path ) {
            $name = $path->__toString();
            if ( ( '.' === basename( $name ) ) || ( '..' === basename( $name ) ) ) {
                continue;
            }

            if ( $path->isLink() || ! static::is_safe_backup_path( $name, $this->allowed_backup_bases ) ) {
                static::record_skipped_path( $name );
                continue;
            }

            if ( ! MainWP_Helper::excluded_from_backup( $excludes, str_replace( ABSPATH, '', $name ) ) ) {
                if ( $path->isDir() ) {
                    $this->zip_add_dir( $name, $excludes );
                } else {
                    $this->add_file_to_zipp( $name, str_replace( ABSPATH, '', $name ) );
                }
            }
            $name = null;
            unset( $name );
        }

        $iterator = null;
        unset( $iterator );
    }

    /**
     * Add file from a string to zip file.
     *
     * @param resource $file   File to add to zip.
     * @param string   $str String to add.
     *
     * @return bool true|false.
     */
    public function add_file_from_string_to_zip( $file, $str ) {
        return $this->zip->addFromString( $file, $str );
    }

    /**
     * Add file from a string to pclzip file.
     *
     * @param resource $file     File to add to zip.
     * @param string   $str   String to add.
     * @param string   $filepath Path to file.
     *
     * @return bool true|false.
     */
    public function add_file_from_string_to_pcl_zip( $file, $str, $filepath ) {
        $file        = preg_replace( '/(?:\.|\/)*(.*)/', '$1', $file );
        $localpath   = dirname( $file );
        $tmpfilename = dirname( $filepath ) . '/' . basename( $file );
        if ( false !== MainWP_Helper::file_put_contents( $tmpfilename, $str ) ) { //phpcs:ignore WordPress.WP.AlternativeFunctions
            $this->zip->delete( PCLZIP_OPT_BY_NAME, $file );
            $add = $this->zip->add(
                $tmpfilename,
                PCLZIP_OPT_REMOVE_PATH,
                dirname( $filepath ),
                PCLZIP_OPT_ADD_PATH,
                $localpath
            );
            wp_delete_file( $tmpfilename ); // NOSONAR .
            if ( ! empty( $add ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add file to zip.
     *
     * @param string $path         Path to zip file.
     * @param string $zipEntryName File to add to zip.
     *
     * @return bool True|false.
     *
     * @throws MainWP_Exception When the zip archive cannot be reopened mid-run.
     *
     * @uses \MainWP\Child\MainWP_Helper::ends_with()
     */
    public function add_file_to_zipp( $path, $zipEntryName ) {
        if ( time() - $this->lastRun > 20 ) {
            set_time_limit( (int) $this->timeout ); // phpcs:ignore -- required to achieve desired results. Pull requests appreciated.
            $this->lastRun = time();
        }

        if ( $this->excludeZip && MainWP_Helper::ends_with( $path, '.zip' ) ) {
            return false;
        }

        if ( ! static::is_safe_backup_path( $path, $this->allowed_backup_bases ) ) {
            static::record_skipped_path( $path );
            return false;
        }

        $this->zipArchiveSizeCount += filesize( $path );
        ++$this->gcCnt;

        // Hard low-disk floor every 100 files - a full disk mid-archive
        // corrupts the backup silently otherwise.
        if ( 0 === $this->zipArchiveFileCount % 100 && ! empty( $this->backup_dir_in_use ) ) {
            static::assert_disk_space_during_backup( $this->backup_dir_in_use );
        }

        // Always stream files into the archive - the old loadFilesBeforeZip
        // branch loaded files under 5MB fully into memory.
        ++$this->zipArchiveFileCount;
        if ( $this->zip instanceof \ZipArchive ) {
            $added = $this->zip->addFile( $path, $zipEntryName );
        } else {
            $added = $this->zip->add_file( $path, $zipEntryName );
        }

        if ( false === $added ) {
            $this->record_failed_add( $path );
        }

        if ( $this->gcCnt > 20 ) {
            if ( function_exists( 'gc_enable' ) ) {
                gc_enable();
            }
            if ( function_exists( 'gc_collect_cycles' ) ) {
                gc_collect_cycles();
            }
            $this->gcCnt = 0;
        }

        if ( ( ( $this->file_descriptors > 0 ) && ( $this->zipArchiveFileCount > $this->file_descriptors ) ) ) {
            // The periodic close/reopen flushes file descriptors; an
            // unchecked failure here used to corrupt the archive silently.
            $this->close_zip_archive();
            $this->zip = null;
            unset( $this->zip );
            if ( function_exists( 'gc_enable' ) ) {
                gc_enable();
            }
            if ( function_exists( 'gc_collect_cycles' ) ) {
                gc_collect_cycles();
            }
            $this->zip = new \ZipArchive();
            if ( true !== $this->zip->open( $this->zipArchiveFileName ) ) {
                throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_ZIP_FAILED ) ), null, MainWP_Backup_Error::ERR_ZIP_FAILED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message is escaped, code is a registry class constant.
            }
            $this->zipArchiveFileCount = 0;
            $this->zipArchiveSizeCount = 0;
        }

        return $added;
    }

    /**
     * Create DB backup.
     *
     * @param string $filepath_prefix File path prefix.
     * @param bool   $archiveExt      Archive extension.
     * @param object $archiver        Archiver.
     *
     * @return array|string[] Action response.
     *
     * @throws MainWP_Exception On dump failure (partial files cleaned up).
     *
     * @uses \MainWP\Child\MainWP_Helper::set_limit() Set PHP memory limit.
     * @uses \MainWP\Child\MainWP_Backup_Db_Dumper::check_dedicated_connection() Second-connection preflight.
     * @uses \MainWP\Child\MainWP_Backup_Db_Dumper::dump_to_files() Consistent-snapshot dump.
     * @uses \MainWP\Child\MainWP_Backup_Db_Dumper::dump_tier2_slice() Paginated fallback dump.
     * @uses \MainWP\Child\Tar_Archiver()
     */
    public function create_backup_db( $filepath_prefix, $archiveExt = false, &$archiver = null ) {

        $timeout = $this->get_timeout_from_request();
        $mem     = '512M';
        MainWP_Helper::set_limit( $timeout, $mem );

        //phpcs:disable WordPress.WP.AlternativeFunctions
        $dumper = MainWP_Backup_Db_Dumper::instance();

        // Dump with a dedicated connection and a consistent snapshot when the
        // host allows a second connection; otherwise fall back to the
        // paginated dump on the shared handle (not point-in-time).
        $preflight = $dumper->check_dedicated_connection();
        if ( $preflight['ok'] ) {
            $db_files = $dumper->dump_to_files( $filepath_prefix );
            $db_files = $db_files['files'];
        } else {
            $cursor = null;
            do {
                if ( null !== $archiver ) {
                    $archiver->update_pid_file();
                }
                $slice  = $dumper->dump_tier2_slice( $filepath_prefix, $cursor, 45 );
                $cursor = $slice['cursor'];
            } while ( ! $slice['done'] );
            $db_files = $slice['files'];
        }

        if ( null !== $archiver ) {
            $archiver->update_pid_file();
        }

        fclose( fopen( $filepath_prefix . '.sql', 'w' ) ); // NOSONAR .
        $db_files[] = $filepath_prefix . '.sql';
        //phpcs:enable WordPress.WP.AlternativeFunctions

        if ( false !== $archiveExt ) {
            return array( 'filepath' => $this->archive_db_files( $db_files, $filepath_prefix, $archiveExt ) );
        }

        return $db_files;
    }

    /**
     * Archive a dump file set into the single db backup deliverable and
     * clean up the source files on success.
     *
     * @param array  $db_files        Dump file paths (including the completion marker).
     * @param string $filepath_prefix Dump file prefix.
     * @param string $archiveExt      Archive extension.
     *
     * @return string The artifact path (verify with file_exists - on archive
     *                failure the file is absent, matching legacy behavior).
     */
    public function archive_db_files( $db_files, $filepath_prefix, $archiveExt ) {
        $archivefilePath = $filepath_prefix . '.sql.' . $archiveExt;

        if ( 'zip' === $archiveExt ) {
            $this->archiver = null;
        } else {
            $this->archiver = new Tar_Archiver( $this, $archiveExt );
        }

        if ( $this->zip_file( $db_files, $archivefilePath ) && file_exists( $archivefilePath ) ) {
            foreach ( $db_files as $db_file ) {
                wp_delete_file( $db_file );
            }
        }

        return $archivefilePath;
    }
}
