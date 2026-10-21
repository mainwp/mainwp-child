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

    /** Maximum directory depth for the backup readiness inventory. */
    const MAX_INVENTORY_DEPTH = 64;

    /** Maximum directory depth when archiving or copying backup content. */
    const MAX_DIRECTORY_DEPTH = 64;

    /** Pid admission must allow for the legacy heartbeat interval. */
    const PID_LOCK_FRESH_SECONDS = 160;

    /**
     * Native tasks can report partial content; legacy responses cannot.
     *
     * @var bool
     */
    protected $native_task = false;

    /**
     * Limit partial-directory handling to the native task invocation.
     *
     * @param bool $native Whether the caller publishes partial content status.
     */
    public function set_native_task( $native ) {
        $this->native_task = (bool) $native;
    }

    /**
     * Tar shares the caller's failure policy through its backup instance.
     *
     * @return bool Whether directory skips can complete as partial.
     */
    public function is_native_task() {
        return $this->native_task;
    }

    /**
     * Preserve glob's dotfile policy while detecting unreadable native roots.
     *
     * @param string $dir Absolute directory.
     * @return array|false Glob results, including legacy failure behavior.
     */
    public function glob_backup_nodes( $dir ) {
        $dir   = trailingslashit( $dir );
        $nodes = glob( $dir . '*' );
        // glob without GLOB_ERR also returns an empty array for an unreadable root.
        if ( $this->native_task && ( false === $nodes || ( array() === $nodes && false === @scandir( $dir, SCANDIR_SORT_NONE ) ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- native listing failure is reported through coverage.
            if ( is_dir( $dir ) ) {
                static::record_skipped_path( rtrim( $dir, '/' ), 'unreadable' );
            }
            return array();
        }
        return $nodes;
    }

    /**
     * Nested legacy entry points share the outer request's lock.
     *
     * @var string
     */
    protected $mutex_owner = '';

    /**
     * Retries must find the original run's dump fragments.
     *
     * @var string|null
     */
    protected $db_run_id = null;

    /**
     * ZIP and tar use the same checkpid path.
     *
     * @var string|false
     */
    protected $pid_file = false;

    /**
     * Checkpid expects the archive path as its pid-file contents.
     *
     * @var string
     */
    protected $pid_content = '';

    /**
     * Pid writes and mutex refreshes share the legacy throttle.
     *
     * @var int
     */
    protected $heartbeat_at = 0;

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
     * ZIP traversal and the tar archiver share the same slice deadline.
     *
     * @var int|null Unix timestamp.
     */
    protected $slice_deadline = null;

    /**
     * Preserve the ZIP method signature used by existing subclasses.
     *
     * @var bool
     */
    private $zip_append = false;

    /**
     * An expired slice must still add a node before yielding.
     *
     * @var bool
     */
    private $zip_slice_progress = false;

    /**
     * Lazy ZIP writes can fail after addFile() has accepted an entry.
     *
     * @var string[] First ten entries added since the archive was opened.
     */
    private $zip_pending_entries = array();

    /**
     * Keep diagnostics bounded even when descriptor limits disable intermediate closes.
     *
     * @var int
     */
    private $zip_pending_count = 0;

    /**
     * Lazy entries and the close-time rewrite compete with other sites on shared disks.
     *
     * @var int
     */
    private $zip_pending_bytes = 0;

    /**
     * Keep host probe failures reproducible without a public filter.
     *
     * @var callable|null Disk probe override for isolated tests.
     */
    private static $disk_space_probe = null;

    /**
     * Floors already computed in this request, by directory.
     *
     * @var array<string,int>
     */
    private static $disk_space_floors = array();

    /**
     * PclZip needs the staged tree's size before creating its temporary copy.
     *
     * @var int Bytes copied into the PclZip staging tree.
     */
    private $pcl_staged_bytes = 0;

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
     * @throws \Throwable Engine failures propagate, including the ValueError
     *                     a closed ZipArchive handle raises on PHP 8; the
     *                     partial archive is removed unless the failure is a
     *                     budget interruption.
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
            $this->archiver   = null;
            $this->zip_append = $append;
            $ext              = '.zip';
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

        $this->pid_file     = $pid;
        $this->pid_content  = $filepath;
        $this->heartbeat_at = 0;
        $this->heartbeat();

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
            if ( $success && null === $this->archiver ) {
                $this->complete_pid_file();
            }
        } catch ( \Throwable $e ) {
            // Never leave a partial archive behind - a half-written file
            // looks like a backup and restores as corruption. A budget
            // interruption is the one exception: that partial archive is the
            // resume point for the next slice. Throwable, not Exception:
            // close_zip_archive() rethrows the archive-lease failure
            // unchanged, and a ValueError from a closed ZipArchive handle
            // would otherwise skip this discard and publish the partial.
            if ( $this->should_discard_partial_on( $e ) ) {
                $this->discard_archive_handles();
                if ( file_exists( $filepath ) ) {
                    unlink( $filepath ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- cleanup of partial archive.
                }
            }
            throw $e;
        } finally {
            $this->zip_append = false;
        }

        return ( $success ) ? array(
            'timestamp' => $timestamp,
            'file'      => $fileurl,
            'filesize'  => filesize( $filepath ), // NOSONAR .
        ) : false;
    }

    /**
     * Match tar's completion marker so finished ZIP runs no longer hold the pid gate.
     *
     * @return bool False when the run has no pid file.
     */
    private function complete_pid_file() {
        if ( false === $this->pid_file ) {
            return false;
        }
        global $wp_filesystem;

        $wp_filesystem->move( $this->pid_file, substr( $this->pid_file, 0, -4 ) . '.done' );
        if ( $this->native_task ) {
            MainWP_Helper::protect_backup_path( substr( $this->pid_file, 0, -4 ) . '.done' );
        }
        $this->pid_file = false;

        return true;
    }

    /**
     * Whether a legacy pid lock is currently held: a `*.pid` file in the
     * backup directory without a matching completion marker and fresher than
     * the window create_full_backup() treats as a live process.
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
            if ( file_exists( substr( $file, 0, -4 ) . '.done' ) ) {
                continue;
            }
            if ( ( time() - filemtime( $file ) ) < self::PID_LOCK_FRESH_SECONDS ) {
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
        $excludes[] = str_replace( ABSPATH, '', MAINWP_CHILD_PLUGIN_DIR );

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
        $pid = isset( $_POST['pid'] ) ? MainWP_Helper::sanitize_filename( wp_unslash( $_POST['pid'] ) ) : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via MainWP_Helper::sanitize_filename().
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
     * Keep nested backup_db() calls under the outer request's ownership.
     *
     * @return string Owner token.
     * @throws MainWP_Exception When another engine owns the child.
     */
    protected function acquire_backup_mutex() {
        $token = wp_generate_uuid4();
        $held  = MainWP_Child_Backup_Mutex::acquire( 'legacy', $token, MainWP_Child_Backup_Mutex::LEGACY_TTL );
        if ( null === $held ) {
            throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_MUTEX_WRITE_FAILED ) ), null, MainWP_Backup_Error::ERR_MUTEX_WRITE_FAILED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry message.
        }
        if ( true !== $held ) {
            throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_ALREADY_RUNNING ) ), null, MainWP_Backup_Error::ERR_ALREADY_RUNNING ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry message.
        }
        $this->mutex_owner  = $token;
        $this->db_run_id    = null;
        $this->pid_file     = false;
        $this->heartbeat_at = 0;
        // MainWP_Helper::error() exits, bypassing finally.
        register_shutdown_function( array( MainWP_Child_Backup_Mutex::class, 'release' ), $token );
        return $token;
    }

    /**
     * Preserve the fragment set when Dashboard retries an append request.
     *
     * @param string $dir Dump directory.
     * @return string Per-run dump prefix.
     */
    public function db_dump_prefix( $dir ) {
        if ( null === $this->db_run_id ) {
            // phpcs:disable WordPress.Security.NonceVerification -- authenticated Dashboard dispatch.
            $run_id = ! empty( $_POST['fileUID'] ) ? sanitize_text_field( wp_unslash( $_POST['fileUID'] ) ) : '';
            if ( '' === $run_id && ! empty( $_POST['pid'] ) ) {
                $run_id = sanitize_text_field( wp_unslash( $_POST['pid'] ) );
            }
            // phpcs:enable WordPress.Security.NonceVerification
            $run_id          = sanitize_file_name( $run_id );
            $this->db_run_id = '' !== $run_id ? $run_id : wp_generate_uuid4();
        }
        return trailingslashit( $dir ) . 'dbBackup-' . $this->db_run_id;
    }

    /**
     * Blocking archive calls cannot heartbeat, so their lease covers the request budget.
     *
     * @throws MainWP_Exception When ownership is lost.
     */
    public function extend_archive_mutex_lease() {
        // Capped at the age the child already treats a run's litter as dead: the
        // timeout presets go up to 20 hours, and a run killed without its shutdown
        // hook would hold the mutex for the whole lease.
        $ttl = min( max( MainWP_Child_Backup_Mutex::LEGACY_TTL, $this->get_timeout_from_request() ), 10800 );
        if ( '' !== $this->mutex_owner && ! MainWP_Child_Backup_Mutex::refresh( $this->mutex_owner, $ttl ) ) {
            throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_ALREADY_RUNNING ) ), null, MainWP_Backup_Error::ERR_ALREADY_RUNNING ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry message.
        }
    }

    /**
     * Keep checkpid and mutex ownership live through dumping and archiving.
     *
     * @throws MainWP_Exception When ownership is lost or the pid cannot be written.
     */
    public function heartbeat() {
        if ( time() - $this->heartbeat_at < 40 ) {
            return;
        }
        if ( false !== $this->pid_file ) {
            MainWP_Helper::get_wp_filesystem();
            global $wp_filesystem;
            if ( ! $wp_filesystem->put_contents( $this->pid_file, $this->pid_content ) ) {
                throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_PERMISSIONS ) ), null, MainWP_Backup_Error::ERR_PERMISSIONS ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry message.
            }
            if ( $this->native_task ) {
                MainWP_Helper::protect_backup_path( $this->pid_file );
            }
        }
        if ( '' !== $this->mutex_owner && ! MainWP_Child_Backup_Mutex::refresh( $this->mutex_owner, MainWP_Child_Backup_Mutex::LEGACY_TTL ) ) {
            throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_ALREADY_RUNNING ) ), null, MainWP_Backup_Error::ERR_ALREADY_RUNNING ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry message.
        }
        $this->heartbeat_at = time();
    }

    /**
     * Protect clone dump fragments from concurrent backup cleanup.
     *
     * @param array       $excludes           Paths excluded from the archive.
     * @param string|bool $file               Archive filename prefix.
     * @param bool        $include_core_files Whether to include WordPress core files.
     * @param string      $method             Archive format.
     *
     * @return array|false Archive details, or false on failure.
     * @throws MainWP_Exception When another backup is active or the archive fails.
     */
    public function create_clone_backup( array $excludes, $file, $include_core_files, $method ) {
        $this->assert_no_active_spine_task();
        $token = $this->acquire_backup_mutex();

        try {
            $pid = MainWP_Helper::sanitize_filename( 'clone-' . wp_generate_uuid4() );
            return $this->create_full_backup( $excludes, $file, true, $include_core_files, 0, false, false, false, false, $method, $pid );
        } finally {
            if ( is_string( $this->pid_file ) && '.pid' === substr( $this->pid_file, -4 ) && is_file( $this->pid_file ) ) {
                wp_delete_file( $this->pid_file );
            }
            $this->pid_file = false;
            MainWP_Child_Backup_Mutex::release( $token );
            $this->mutex_owner = '';
        }
    }

    /**
     * Perform a backup.
     *
     * @param bool $write Whether or not to execute MainWP_Helper::write(), Default: true.
     *
     * @return array Array of information on the backup containing the type of backup performed, full, or DB & whether or not it was successful.
     *
     * @throws MainWP_Exception On a refused run when response writing is disabled.
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
            $token = $this->acquire_backup_mutex();
        } catch ( MainWP_Exception $e ) {
            if ( ! $write ) {
                throw $e;
            }
            MainWP_Helper::instance()->error( $e->getMessage(), $e->get_message_error_code() );
        }

        // phpcs:disable WordPress.Security.NonceVerification -- authenticated Dashboard dispatch.
        $type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification

        try {
            $this->check_backup_readiness();
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
                $this->delete_aged_backup_file( $file );
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
        } catch ( MainWP_Exception $e ) {
            MainWP_Helper::instance()->error( $e->getMessage(), $e->get_message_error_code() );
        } finally {
            MainWP_Child_Backup_Mutex::release( $token );
            $this->mutex_owner = '';
        }

        if ( $write ) {
            MainWP_Helper::write( $information );
        }
        // phpcs:enable WordPress.Security.NonceVerification
        return $information;
    }

    /**
     * Pending intents and their lock inode must survive legacy backup cleanup.
     *
     * @param string $file Backup-directory file selected by the legacy sweep.
     */
    protected function delete_aged_backup_file( $file ) {
        if ( MainWP_Helper::ends_with( $file, '/index.php' ) | MainWP_Helper::ends_with( $file, '/.htaccess' ) ) {
            return;
        }
        // Removing the lock would let concurrent writers lock different inodes.
        if ( in_array( basename( $file ), array( 'pending-delete.json', 'pending-delete.lock' ), true ) ) {
            return;
        }
        if ( ( time() - filemtime( $file ) ) > ( 60 * 60 * 3 ) ) {
            wp_delete_file( $file );
        }
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
        $pid = isset( $_POST['pid'] ) ? MainWP_Helper::sanitize_filename( wp_unslash( $_POST['pid'] ) ) : false; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via MainWP_Helper::sanitize_filename().

        // An empty sanitized value cannot identify a run; the string '0' can.
        if ( '' === $pid ) {
            $pid = false;
        }

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
        $owns_mutex = '' === $this->mutex_owner;
        if ( $owns_mutex ) {
            $this->assert_no_active_spine_task();
            $this->acquire_backup_mutex();
        }
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
            if ( $owns_mutex ) {
                MainWP_Child_Backup_Mutex::release( $this->mutex_owner );
                $this->mutex_owner = '';
            }
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
     * @throws MainWP_Exception When a required database dump cannot be added.
     */
    public function m_zip_file( $files, $archive ) {
        $this->zip                 = new \ZipArchive();
        $this->zipArchiveFileCount = 0;
        $this->zipArchiveSizeCount = 0;
        $this->zipArchiveFileName  = $archive;
        $this->zip_pending_entries = array();
        $this->zip_pending_count   = 0;
        clearstatcache( true, $archive );
        $this->zip_pending_bytes = is_file( $archive ) ? (int) filesize( $archive ) : 0;

        $zipRes = $this->zip->open( $archive, \ZipArchive::CREATE );
        if ( $zipRes ) {
            foreach ( $files as $file ) {
                if ( ! $this->add_file_to_zipp( $file, basename( $file ) ) ) {
                    $this->close_zip_archive();
                    throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_ZIP_FAILED ) ), null, MainWP_Backup_Error::ERR_ZIP_FAILED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message is escaped, code is a registry class constant.
                }
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
        $this->extend_archive_mutex_lease();
        foreach ( $files as $file ) {
            $this->heartbeat();
            clearstatcache( true, $archive );
            $staged_bytes = (int) filesize( $file ) + ( is_file( $archive ) ? (int) filesize( $archive ) : 0 );
            static::assert_disk_space_during_backup( dirname( $archive ), null, 2 * $staged_bytes );
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
     * Files omitted from the archive.
     *
     * @var string[] Files omitted from the archive.
     */
    protected $failed_add_paths = array();

    /**
     * Exact failed sources omitted while resuming one archive slice.
     *
     * @var array
     */
    protected $failed_add_skips = array();

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
     * the shared site, content and uploads bases.
     *
     * @var array|null Base directories.
     */
    protected $allowed_backup_bases = null;

    /**
     * Whether content lives outside the tree covered by native file backups.
     *
     * Backups follow a wp-content symlink, so a root that sits under the site
     * by name passes. Restore extraction checks resolved paths, so restores ask
     * for $resolved and a root whose real path leaves the site is refused before
     * any file is replaced.
     *
     * @param string   $site_root     Site directory.
     * @param string[] $content_roots Content and uploads directories.
     * @param bool     $resolved      Judge roots by their resolved path.
     *
     * @return bool True when any non-empty content root is outside the site.
     */
    public static function content_outside_site_root( $site_root, array $content_roots, $resolved = false ) {
        // An unset site root provides no layout to check; test runners may omit it.
        if ( '' === $site_root ) {
            return false;
        }

        $normalized_site = trailingslashit( wp_normalize_path( $site_root ) );
        $real_site       = realpath( $site_root );
        foreach ( $content_roots as $root ) {
            if ( '' === $root ) {
                continue;
            }
            $real_root = realpath( $root );
            $lexical   = 0 === strpos( trailingslashit( wp_normalize_path( $root ) ), $normalized_site );
            // A root that does not exist yet has nothing to resolve; its name decides.
            if ( $lexical && ( ! $resolved || false === $real_root ) ) {
                continue;
            }
            if ( false !== $real_site && false !== $real_root && 0 === strpos( trailingslashit( wp_normalize_path( $real_root ) ), trailingslashit( wp_normalize_path( $real_site ) ) ) ) {
                continue;
            }
            return true;
        }
        return false;
    }

    /**
     * Keep containment consistent when content or uploads live outside ABSPATH.
     *
     * @return string[] Resolved site, content and uploads bases.
     */
    public static function default_backup_bases() {
        $uploads = wp_upload_dir( null, false );
        $bases   = array();
        foreach ( array( ABSPATH, WP_CONTENT_DIR, $uploads['basedir'] ) as $base ) {
            $real = realpath( $base );
            if ( false !== $real && ! in_array( $real, $bases, true ) ) {
                $bases[] = $real;
            }
        }
        return $bases;
    }

    /**
     * Containment follows links; the descent chain prevents ancestor cycles.
     *
     * Trailing slashes keep sibling prefixes from passing containment checks.
     *
     * @param string     $path      Path to validate.
     * @param array|null $bases     Allowed base directories, null for the shared defaults.
     * @param array      $ancestors Directories on the current descent chain.
     *
     * @return bool True when the path may be included.
     */
    public static function is_safe_backup_path( $path, $bases = null, $ancestors = array() ) {
        $path = (string) $path;
        if ( '' === $path ) {
            return false;
        }
        $real = realpath( $path );
        if ( false === $real ) {
            return false;
        }
        if ( is_link( rtrim( $path, '/' ) ) && is_dir( $path ) ) {
            foreach ( $ancestors as $ancestor ) {
                $real_ancestor = realpath( $ancestor );
                if ( false !== $real_ancestor && 0 === strpos( $real_ancestor . '/', rtrim( $real, '/' ) . '/' ) ) {
                    return false;
                }
            }
        }
        if ( null === $bases ) {
            $bases = static::default_backup_bases();
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
     * Whether a path is a symlink to a file, or a dangling one.
     *
     * Hosts such as GridPane link mu-plugins to plugin files. Archiving the
     * target's bytes at the link path turns the link into a copy on restore,
     * so its __DIR__ requires break. Directory links are still followed.
     *
     * @param string $path Absolute path.
     * @return bool
     */
    public static function is_file_link( $path ) {
        return is_link( rtrim( $path, '/' ) ) && ! is_dir( $path );
    }

    /**
     * Record a path skipped by the traversal guards, for the backup report.
     *
     * @param string $path Skipped path.
     * @param string $reason Traversal refusal.
     */
    public static function record_skipped_path( $path, $reason = 'unsafe' ) {
        if ( count( static::$skipped_paths ) < 100 && ( 'unreadable' !== $reason || ! in_array( (string) $path, static::$skipped_paths, true ) ) ) {
            static::$skipped_paths[] = (string) $path;
        }
        /**
         * Fires when the backup traversal skips a symlink or unsafe path.
         *
         * @param string $path The skipped path.
         * @param string $reason Traversal refusal.
         */
        do_action( 'mainwp_child_backup_skipped_path', $path, $reason );
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
     * @param string $path   File that failed to be added.
     * @param string $reason Reason the file could not be added.
     *
     * @throws MainWP_Exception When the failed-add threshold is exceeded.
     */
    public function record_failed_add( $path, $reason = 'unreadable' ) {
        ++$this->failed_add_count;
        $this->failed_add_paths[] = (string) $path;
        static::record_skipped_path( $path, $reason );
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
     * Returns files the manifest must omit from this base.
     *
     * @return string[] Failed file paths.
     */
    public function get_failed_add_paths() {
        return $this->failed_add_paths;
    }

    /**
     * Reset the failed-add counter (start of a backup run).
     */
    public function reset_failed_adds() {
        $this->failed_add_count = 0;
        $this->failed_add_paths = array();
    }

    /**
     * Preserve append alignment even if a failed source becomes readable.
     *
     * @param string[] $paths Absolute paths skipped for this slice.
     */
    public function set_failed_add_skips( $paths ) {
        $this->failed_add_skips = array_fill_keys( $paths, true );
    }

    /**
     * Failed sources must never acquire user-exclude wildcard semantics.
     *
     * @param string $path Absolute source path.
     * @return bool Whether this exact source was skipped in an earlier slice.
     */
    public function is_failed_add_skipped( $path ) {
        return isset( $this->failed_add_skips[ $path ] );
    }

    /**
     * Close the ZIP archive and verify the close succeeded.
     *
     * ZipArchive::close() is where the archive is actually written - ignoring
     * its result reports success over a corrupt or missing archive.
     * Lease failures propagate unchanged, including any other \Throwable.
     *
     * @throws MainWP_Exception When the archive could not be finalized.
     */
    public function close_zip_archive() {
        try {
            try {
                $this->extend_archive_mutex_lease();
            } catch ( \Throwable $lease_error ) {
                if ( $this->zip instanceof \ZipArchive ) {
                    try {
                        // Closing after a failed discard would publish the pending entries. When the
                        // discard fails the finally below still drops the handle, and the destructor's
                        // write runs there, before the lease error reaches the caller that unlinks the archive.
                        if ( true === $this->zip->unchangeAll() ) {
                            $this->zip->close();
                        }
                    } catch ( \Throwable $discard_error ) {
                        // Cleanup must not replace the lease error that tells Dashboard ownership was lost.
                    }
                }
                throw $lease_error;
            }

            $this->assert_zip_disk_space();
            $result = false;
            try {
                if ( $this->zip instanceof \ZipArchive ) {
                    $result = $this->native_task ? MainWP_Helper::protect_backup_path(
                        $this->zipArchiveFileName,
                        function () {
                            return $this->zip->close();
                        }
                    ) : $this->zip->close();
                }
            } catch ( \Throwable $close_error ) {
                $result = false;
            }
            if ( true !== $result ) {
                // libzip cannot identify which lazy source read failed, so report the pending batch.
                $remaining = $this->zip_pending_count - 10;
                $message   = sprintf(
                    'Failed to close ZIP archive "%s". Pending entries: %s%s.',
                    basename( (string) $this->zipArchiveFileName ),
                    wp_json_encode( array_map( 'basename', $this->zip_pending_entries ) ),
                    $remaining > 0 ? sprintf( ' (%d more)', $remaining ) : ''
                );
                MainWP_Helper::log_debug( $message . ' Pending entry paths: ' . wp_json_encode( $this->zip_pending_entries ) );
                throw new MainWP_Exception( esc_html( $message ), null, MainWP_Backup_Error::ERR_ZIP_FAILED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message is escaped, code is a registry class constant.
            }
        } finally {
            $this->zip                 = null;
            $this->zip_pending_entries = array();
            $this->zip_pending_count   = 0;
            $this->zip_pending_bytes   = 0;
        }
    }

    /**
     * Discard before unlinking because ZipArchive's destructor can flush queued entries.
     */
    private function discard_archive_handles() {
        try {
            if ( $this->zip instanceof \ZipArchive ) {
                @$this->zip->unchangeAll(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an already closed handle warns on PHP 7.4.
            }
        } catch ( \Throwable $cleanup_error ) {
            // Preserve the failure that interrupted the archive.
        } finally {
            $this->zip               = null;
            $this->zip_pending_bytes = 0;
        }
        try {
            if ( null !== $this->archiver && $this->archiver->is_open() ) {
                $this->archiver->close();
            }
        } catch ( \Throwable $cleanup_error ) {
            // A close failure must not replace the original error.
        } finally {
            $this->archiver = null;
        }
    }

    /**
     * Account for data libzip defers until close, including its copy of an existing archive.
     *
     * @param int $entry_bytes Bytes about to be queued.
     * @throws MainWP_Exception When the disk floor refuses the write.
     */
    private function assert_zip_disk_space( $entry_bytes = 0 ) {
        try {
            static::assert_disk_space_during_backup( dirname( (string) $this->zipArchiveFileName ), null, $this->zip_pending_bytes + $entry_bytes );
        } catch ( MainWP_Exception $e ) {
            if ( MainWP_Backup_Error::ERR_DISK_FULL === $e->get_message_error_code() ) {
                $this->discard_archive_handles();
            }
            throw $e;
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
     * Count regular files under a directory, following accepted links. Archive
     * exclusion rules are not applied (reserved restore temps are still omitted),
     * deliberately over-counting relative to the archive. Link handling matches
     * the archive: directory links store contents under the link path, so a link
     * and its in-tree target both count.
     *
     * @param string $dir Directory to measure.
     *
     * @return array Inventory with 'bytes' (int) and 'count' (int) keys.
     */
    private static function get_directory_inventory( $dir ) {
        $bytes = 0;
        $count = 0;
        $bases = array_merge( static::default_backup_bases(), array( $dir ) );
        $stack = array(
            array(
                'dir'       => $dir,
                'ancestors' => array(),
            ),
        );
        while ( ! empty( $stack ) ) {
            $frame = array_pop( $stack );
            if ( count( $frame['ancestors'] ) > self::MAX_INVENTORY_DEPTH ) {
                static::record_skipped_path( $frame['dir'], 'inventory_depth' );
                continue;
            }
            $real = realpath( $frame['dir'] );
            if ( false === $real || MainWP_Child_Backup_File_Restorer::is_reserved_temp_name( basename( $frame['dir'] ) ) || MainWP_Child_Backup_File_Restorer::is_reserved_temp_name( basename( $real ) ) ) {
                continue;
            }
            $ancestors   = $frame['ancestors'];
            $ancestors[] = $real;
            try {
                $iterator = new \DirectoryIterator( $frame['dir'] );
                foreach ( $iterator as $file ) {
                    if ( $file->isDot() || MainWP_Child_Backup_File_Restorer::is_reserved_temp_name( $file->getFilename() ) ) {
                        continue;
                    }
                    $path = $file->getPathname();
                    if ( $file->isLink() && ! static::is_safe_backup_path( $path, $bases, $ancestors ) ) {
                        continue;
                    }
                    $real = realpath( $path );
                    if ( false === $real || MainWP_Child_Backup_File_Restorer::is_reserved_temp_name( basename( $real ) ) ) {
                        continue;
                    }
                    if ( $file->isDir() ) {
                        $stack[] = array(
                            'dir'       => $path,
                            'ancestors' => $ancestors,
                        );
                    } elseif ( $file->isFile() ) {
                        $bytes += (int) $file->getSize();
                        ++$count;
                    }
                }
            } catch ( \Exception $e ) {
                // An unreadable subtree must not discard the rest of the estimate.
                unset( $e );
            }
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
     * @param int|null $min_free   Minimum free bytes, or null for the shared-disk floor.
     * @param int      $pending_bytes Data still to be written.
     *
     * @throws MainWP_Exception When free space drops below the floor.
     */
    public static function assert_disk_space_during_backup( $backup_dir, $min_free = null, $pending_bytes = 0 ) {
        $free = self::probe_disk_space( 'disk_free_space', $backup_dir );
        if ( false === $free ) {
            return;
        }
        $needed = ( null === $min_free ? self::get_disk_space_floor( $backup_dir ) : $min_free ) + max( 0, $pending_bytes );
        if ( $free < $needed ) {
            $message = sprintf(
                /* translators: 1: free disk space found on the child site, 2: minimum free disk space the backup needed (safety floor plus data still to be written). */
                esc_html__( 'The backup stopped because the child site ran low on disk space. It had %1$s free and the backup needed at least %2$s. Free up disk space on the child site and try again.', 'mainwp-child' ),
                esc_html( size_format( $free > 0 ? $free : 0, 1 ) ), // size_format() is false for float 0.0, and an int cast would wrap above 2 GB on 32-bit PHP.
                esc_html( size_format( $needed, 1 ) )
            );
            throw new MainWP_Exception( $message, null, MainWP_Backup_Error::ERR_DISK_FULL ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- format and sizes are escaped above.
        }
    }

    /**
     * Reserve headroom for the other sites and processes sharing the disk.
     *
     * @param string $backup_dir Target directory.
     * @return int Minimum free bytes.
     */
    public static function get_disk_space_floor( $backup_dir ) {
        // Capacity does not change mid-run, and the floor is checked per archive entry (one statvfs each, a round trip on network mounts).
        $key = (string) $backup_dir;
        if ( null === self::$disk_space_probe && isset( self::$disk_space_floors[ $key ] ) ) {
            return self::$disk_space_floors[ $key ];
        }
        $total = self::probe_disk_space( 'disk_total_space', $backup_dir );
        if ( false === $total ) {
            return 100 * 1024 * 1024;
        }
        // Clamp before the cast: a tenth of a large disk overflows a 32-bit int and would wrap the floor down.
        $floor = (int) max( 100 * 1024 * 1024, min( 1024 * 1024 * 1024, $total / 10 ) );
        if ( null === self::$disk_space_probe ) {
            self::$disk_space_floors[ $key ] = $floor;
        }
        return $floor;
    }

    /**
     * Disabled host functions and failed reads both leave the floor advisory.
     *
     * @param string $probe_name Disk function to probe.
     * @param string $directory Target directory.
     * @return float|false Bytes, or false when the host cannot report them.
     */
    private static function probe_disk_space( $probe_name, $directory ) {
        if ( null !== self::$disk_space_probe ) {
            return call_user_func( self::$disk_space_probe, $probe_name, $directory );
        }
        if ( ! function_exists( $probe_name ) ) {
            return false;
        }
        return @$probe_name( $directory ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- hosts may disable filesystem probes.
    }

    /**
     * Validate the child server environment before a backup starts.
     *
     * Returns an advisory readiness report for the Dashboard; throws only on
     * conditions that make any backup impossible (unwritable backup directory,
     * dead database connection). The report includes the backup directory
     * secret so the Dashboard can track the randomized directory.
     *
     * @param bool $capabilities_only Return capabilities without directory, disk, database,
     *                                or content-inventory readiness checks.
     * @return array Readiness report sent to the Dashboard.
     * @throws MainWP_Exception When critical requirements are missing.
     */
    public function check_backup_readiness( $capabilities_only = false ) {
        $report = array(
            'disk_free'                  => false,
            'dir_writable'               => false,
            'db_connected'               => false,
            'memory_limit'               => ini_get( 'memory_limit' ),
            'max_exec_time'              => ini_get( 'max_execution_time' ),
            'zip_support'                => $this->check_zip_support(),
            'gz_support'                 => function_exists( 'gzopen' ),
            'bz2_support'                => function_exists( 'bzopen' ),
            'time_limit_ok'              => ! in_array( 'set_time_limit', array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) ), true ),
            'backup_dir_hash'            => MainWP_Helper::get_backup_dir_hash(),
            'task_spine'                 => 1,
            'download_token'             => 1,
            'incremental'                => 1,
            'manifest_chunk'             => 1,
            'manifest_writer_identity'   => 1,
            // Task-store schema 1.2.0 pins each restore_id to its first task,
            // so a replayed create returns that task instead of a duplicate.
            // The Dashboard refuses native restore unless this is advertised.
            'restore_create_idempotency' => 1,
            'restore_files'              => 1,

            // Restores can pull artifacts straight from cloud storage
            // via presigned URLs instead of the staged chunk transfer.
            'cloud_restore_pull'         => 1,

            // The install's multisite state, and whether this child can
            // restore a whole network point in time. The Dashboard
            // stamps both so it can gate restore on multisite children
            // running an older child plugin.
            'is_multisite'               => is_multisite() ? 1 : 0,
            'restore_multisite'          => is_multisite() ? 1 : 0,
        );

        $report['reconcile_unarchived_support'] = true;
        $report['restore_outcome_report']       = true;

        if ( $capabilities_only ) {
            return $report;
        }

        // 1. Backup directory writable (randomized directory, created on demand).
        $dirs       = MainWP_Helper::get_mainwp_dir( 'backup', false );
        $backup_dir = $dirs[0];
        MainWP_Helper::protect_backup_path( $backup_dir );
        MainWP_Helper::protect_backup_path( $backup_dir . 'index.php' );
        MainWP_Helper::protect_backup_path( $backup_dir . '.htaccess' );
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

        // The wp-content walk is cached so reporting the size estimate and
        // file count does not double the IO before the backup runs.
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
     *
     * @throws MainWP_Exception When the ZIP cannot be opened for writing.
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
        $this->zip_slice_progress  = false;
        $this->zip_pending_entries = array();
        $this->zip_pending_count   = 0;
        clearstatcache( true, $filepath );
        $this->zip_pending_bytes = is_file( $filepath ) ? (int) filesize( $filepath ) : 0;
        $zipRes                  = $this->zip->open( $filepath, \ZipArchive::CREATE );
        if ( $this->zip_append && ( true !== $zipRes || \ZipArchive::ER_OK !== $this->zip->status ) ) {
            // A killed close can leave a partial that has no usable resume point.
            $this->zip = null;
            if ( file_exists( $filepath ) && ! unlink( $filepath ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- unusable partial archive cleanup.
                throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_ZIP_FAILED ) ), null, MainWP_Backup_Error::ERR_ZIP_FAILED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message is escaped, code is a registry class constant.
            }
            $this->zip               = new \ZipArchive();
            $zipRes                  = $this->zip->open( $filepath, \ZipArchive::CREATE );
            $this->zip_pending_bytes = 0;
        }
        if ( true === $zipRes && \ZipArchive::ER_OK === $this->zip->status ) {
            $nodes = $this->glob_backup_nodes( ABSPATH );
            if ( file_exists( ABSPATH . '.htaccess' ) ) {
                $nodes[] = ABSPATH . '.htaccess';
            }
            if ( ! $includeCoreFiles ) {
                $this->include_core_files( $nodes );
            }

            $db_files = $this->get_or_create_db_dump_files( $this->db_dump_prefix( dirname( $filepath ) ) );
            // Clone validation requires the fixed empty marker; table fragments retain their run id.
            foreach ( $db_files as $db_file ) {
                if ( ! $this->add_file_to_zipp( $db_file, basename( WP_CONTENT_DIR ) . '/' . ( '.sql' === substr( $db_file, -4 ) ? 'dbBackup.sql' : basename( $db_file ) ) ) ) {
                    $this->close_zip_archive();
                    throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_ZIP_FAILED ) ), null, MainWP_Backup_Error::ERR_ZIP_FAILED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message is escaped, code is a registry class constant.
                }
            }

            foreach ( $nodes as $node ) {
                if ( static::is_file_link( $node ) || ! static::is_safe_backup_path( $node, $this->allowed_backup_bases, array( ABSPATH ) ) ) {
                    // An excluded path was never meant to be in the backup, so it is not a skip.
                    if ( ! static::excluded_by_path_or_target( $excludes, $node ) ) {
                        static::record_skipped_path( $node, static::is_file_link( $node ) ? 'link' : 'unsafe' );
                    }
                    continue;
                }

                if ( $excludenonwp && is_dir( $node ) ) {
                    if ( ! MainWP_Helper::starts_with( $node, WP_CONTENT_DIR ) && ! MainWP_Helper::starts_with( $node, ABSPATH . 'wp-admin' ) && ! MainWP_Helper::starts_with( $node, ABSPATH . WPINC ) ) {
                        continue;
                    }
                }

                if ( ! static::excluded_by_path_or_target( $excludes, $node ) ) {
                    if ( is_dir( $node ) ) {
                        $this->zip_add_dir( $node, $excludes, null, array( ABSPATH ) );
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

        $this->zip = null;
        throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_ZIP_FAILED ) ), null, MainWP_Backup_Error::ERR_ZIP_FAILED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message is escaped, code is a registry class constant.
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
     * @throws \UnexpectedValueException When a legacy backup cannot report incomplete configuration.
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
        $fh      = @opendir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- listing failures follow the native partial-content policy.
        // A missing folder has nothing to list; only an existing one that cannot be read is a skip or a legacy failure.
        if ( false === $fh && is_dir( $dir ) ) {
            if ( ! $this->native_task ) {
                throw new \UnexpectedValueException( 'The backup configuration directory could not be listed.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message contains no path or external input.
            }
            static::record_skipped_path( rtrim( $dir, '/' ), 'unreadable' );
        } elseif ( false !== $fh ) {
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
        }

        $themes = array();
        $dir    = WP_CONTENT_DIR . '/themes/';
        $fh     = @opendir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- listing failures follow the native partial-content policy.
        // A missing folder has nothing to list; only an existing one that cannot be read is a skip or a legacy failure.
        if ( false === $fh && is_dir( $dir ) ) {
            if ( ! $this->native_task ) {
                throw new \UnexpectedValueException( 'The backup configuration directory could not be listed.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message contains no path or external input.
            }
            static::record_skipped_path( rtrim( $dir, '/' ), 'unreadable' );
        } elseif ( false !== $fh ) {
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
        }
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
     * Match exclusions against both the archive path and its resolved target.
     *
     * @param array  $excludes Files and directories to exclude.
     * @param string $name     Filesystem path.
     *
     * @return bool Whether either path is excluded.
     */
    public static function excluded_by_path_or_target( $excludes, $name ) {
        if ( MainWP_Child_Backup_File_Restorer::is_reserved_temp_name( basename( $name ) ) ) {
            return true;
        }
        if ( MainWP_Helper::excluded_from_backup( $excludes, str_replace( ABSPATH, '', $name ) ) ) {
            return true;
        }
        $real = realpath( $name );
        if ( false === $real ) {
            return false;
        }
        if ( MainWP_Child_Backup_File_Restorer::is_reserved_temp_name( basename( $real ) ) ) {
            return true;
        }
        if ( $real !== $name && MainWP_Helper::excluded_from_backup( $excludes, str_replace( ABSPATH, '', $real ) ) ) {
            return true;
        }
        static $abspath_real = null;
        if ( null === $abspath_real ) {
            $abspath_real = realpath( ABSPATH );
        }
        return false !== $abspath_real && trailingslashit( ABSPATH ) !== trailingslashit( $abspath_real ) && MainWP_Helper::excluded_from_backup( $excludes, str_replace( trailingslashit( $abspath_real ), '', $real ) );
    }

    /**
     * Copy directory.
     *
     * @param array      $nodes        Default nodes.
     * @param array      $excludes     Files & directories to exclude.
     * @param string     $backupfolder Backup folder.
     * @param bool       $excludenonwp Whether or not to exclude any wp core files.
     * @param array|bool $ancestors    Descent chain; legacy root flags start at ABSPATH.
     *
     * @throws MainWP_Exception When the copy fails or exceeds the directory depth limit.
     *
     * @uses \MainWP\Child\MainWP_Helper::starts_with() Check if the String 1 starts with the String 2.
     * @uses \MainWP\Child\MainWP_Helper::in_excludes() Check if the value is in the excludes list.
     * @uses \MainWP\Child\MainWP_Helper::ends_with() Check if the String 1 ends with the String 2.
     */
    public function copy_dir( $nodes, $excludes, $backupfolder, $excludenonwp, $ancestors = array() ) {
        if ( ! is_array( $nodes ) ) {
            return;
        }

        if ( ! is_array( $ancestors ) || empty( $ancestors ) ) {
            $ancestors = array( ABSPATH );
        }

        foreach ( $nodes as $node ) {
            if ( $this->is_failed_add_skipped( $node ) ) {
                continue;
            }
            $this->heartbeat();
            if ( static::is_file_link( $node ) || ! static::is_safe_backup_path( $node, $this->allowed_backup_bases, $ancestors ) ) {
                static::record_skipped_path( $node, static::is_file_link( $node ) ? 'link' : 'unsafe' );
                continue;
            }

            if ( $excludenonwp && is_dir( $node ) ) {
                if ( ! MainWP_Helper::starts_with( $node, WP_CONTENT_DIR ) && ! MainWP_Helper::starts_with( $node, ABSPATH . 'wp-admin' ) && ! MainWP_Helper::starts_with( $node, ABSPATH . WPINC ) ) {
                    continue;
                }
            }

            if ( ! static::excluded_by_path_or_target( $excludes, $node ) ) {
                if ( is_dir( $node ) ) {
                    if ( count( $ancestors ) > self::MAX_DIRECTORY_DEPTH + 1 ) {
                        throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_TOO_MANY_SKIPPED ) ), null, MainWP_Backup_Error::ERR_TOO_MANY_SKIPPED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped registry message and constant code.
                    }
                    $target_dir = str_replace( ABSPATH, $backupfolder, $node );
                    if ( ! file_exists( $target_dir ) && ! @mkdir( $target_dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- failure converted to a coded exception below.
                        throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_PERMISSIONS ) ), null, MainWP_Backup_Error::ERR_PERMISSIONS ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message is escaped, code is a registry class constant.
                    }

                    $newnodes = $this->glob_backup_nodes( $node );
                    if ( file_exists( $node . '/.htaccess' ) ) {
                        $newnodes[] = $node . '/.htaccess';
                    }
                    $this->copy_dir( $newnodes, $excludes, $backupfolder, $excludenonwp, array_merge( $ancestors, array( $node ) ) );
                    unset( $newnodes );
                } elseif ( is_file( $node ) ) {
                    if ( $this->excludeZip && MainWP_Helper::ends_with( $node, '.zip' ) ) {
                        continue;
                    }

                    $size = (int) filesize( $node );
                    static::assert_disk_space_during_backup( $backupfolder, null, $size );
                    if ( ! @copy( $node, str_replace( ABSPATH, $backupfolder, $node ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- failure recorded; backup aborts past the failed-add threshold.
                        $this->record_failed_add( $node );
                    } else {
                        $this->pcl_staged_bytes += $size;
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
     * @throws MainWP_Exception When staging fails or the disk floor refuses a write.
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
        $backupFolder           = dirname( $filepath ) . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR;
        $this->pcl_staged_bytes = 0;

        mkdir( $backupFolder ); // phpcs:ignore -- required to achieve desired results. Pull requests appreciated.

        try {
            // Create DB backup.
            $db_files = $this->get_or_create_db_dump_files( $this->db_dump_prefix( $backupFolder ) );

            // Copy installation to backup folder.
            $nodes = $this->glob_backup_nodes( ABSPATH );
            if ( file_exists( ABSPATH . '.htaccess' ) ) {
                $nodes[] = ABSPATH . '.htaccess';
            }
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
                $size = (int) filesize( $db_file );
                static::assert_disk_space_during_backup( $backupFolder, null, $size );
                if ( ! @copy( $db_file, $backupFolder . basename( WP_CONTENT_DIR ) . '/' . ( '.sql' === substr( $db_file, -4 ) ? 'dbBackup.sql' : basename( $db_file ) ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- preserve the source on a failed staging copy.
                    throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_ZIP_FAILED ) ), null, MainWP_Backup_Error::ERR_ZIP_FAILED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped registry message.
                }
                $this->pcl_staged_bytes += $size;
                wp_delete_file( $db_file );
            }

            unset( $nodes );

            // Zip this backup folder.
            require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
            $this->zip = new \PclZip( $filepath );
            $this->extend_archive_mutex_lease();
            // PclZip needs room for both its temporary file and the output.
            static::assert_disk_space_during_backup( dirname( $filepath ), null, 2 * $this->pcl_staged_bytes );
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

                $this->extend_archive_mutex_lease();
                $this->add_file_from_string_to_pcl_zip( 'clone/config.txt', $string, $filepath );
            }
        } finally {
            MainWP_Helper::delete_dir( $backupFolder );
        }

        return true;
    }

    /**
     * Recursively add directory to default PHP zip library.
     *
     * Outside-resolving paths and directory cycles are skipped and recorded.
     *
     * @param string     $path      Path to directory.
     * @param array      $excludes  Files or directories to exclude.
     * @param array|null $bases     Allowed containment bases, null for the defaults.
     * @param array      $ancestors Directories on the current descent chain.
     *
     * @throws MainWP_Backup_Budget_Exception After closing a slice that made progress.
     * @throws \UnexpectedValueException When a legacy directory cannot be listed.
     *
     * @uses \MainWP\Child\MainWP_Helper::in_excludes() Check if the value is in the excludes list.
     */
    public function zip_add_dir( $path, $excludes, $bases = null, $ancestors = array() ) {

        if ( null !== $bases ) {
            $this->allowed_backup_bases = array_merge( static::default_backup_bases(), $bases );
        }

        if ( empty( $ancestors ) ) {
            $this->allowed_backup_bases = array_merge( null === $this->allowed_backup_bases ? static::default_backup_bases() : $this->allowed_backup_bases, array( $path ) );
            $ancestors                  = array( dirname( rtrim( $path, '/' ) ) );
        }
        if ( static::is_file_link( $path ) || ! static::is_safe_backup_path( $path, $this->allowed_backup_bases, $ancestors ) ) {
            static::record_skipped_path( $path, static::is_file_link( $path ) ? 'link' : 'unsafe' );
            return;
        }

        $ancestors[] = $path;

        $this->heartbeat();
        if ( $this->zip instanceof \ZipArchive ) {
            // One added entry per slice keeps create_full_backup()'s append loop convergent.
            if ( null !== $this->slice_deadline && $this->zip_slice_progress && time() >= $this->slice_deadline ) {
                // A failed close must propagate before a budget yield can preserve this slice.
                $this->close_zip_archive();
                throw new MainWP_Backup_Budget_Exception( 'File archiving exceeded its time budget for this slice.', null, MainWP_Backup_Error::ERR_ZIP_FAILED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal control-flow message, code is a registry class constant.
            }
            $entry = rtrim( str_replace( ABSPATH, '', $path ), '/' ) . '/';
            // Existing directories still need traversal to reach their unfinished children.
            if ( ! $this->zip_append || false === $this->zip->locateName( $entry ) ) {
                $this->assert_zip_disk_space();
                if ( $this->zip->addEmptyDir( $entry ) ) {
                    $this->record_pending_zip_entry( $entry );
                    $this->zip_slice_progress = true;
                    if ( null !== $this->slice_deadline && time() >= $this->slice_deadline ) {
                        $this->close_zip_archive();
                        throw new MainWP_Backup_Budget_Exception( 'File archiving exceeded its time budget for this slice.', null, MainWP_Backup_Error::ERR_ZIP_FAILED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal control-flow message, code is a registry class constant.
                    }
                }
            }
        } else {
            $this->zip->add_empty_dir( str_replace( ABSPATH, '', $path ) );
        }

        // Explicit recursion handles descent; a recursive iterator would revisit each subtree.
        $directory = $path;
        try {
            $iterator = new \FilesystemIterator( $path );

            foreach ( $iterator as $path ) {
                $name = $path->__toString();
                if ( ( '.' === basename( $name ) ) || ( '..' === basename( $name ) ) ) {
                    continue;
                }

                $descent_chain = $ancestors;
                if ( static::is_file_link( $name ) || ! static::is_safe_backup_path( $name, $this->allowed_backup_bases, $descent_chain ) ) {
                    // An excluded path was never meant to be in the backup, so it is not a skip.
                    if ( ! static::excluded_by_path_or_target( $excludes, $name ) ) {
                        static::record_skipped_path( $name, static::is_file_link( $name ) ? 'link' : 'unsafe' );
                    }
                    continue;
                }

                if ( ! static::excluded_by_path_or_target( $excludes, $name ) ) {
                    if ( $path->isDir() ) {
                        $this->zip_add_dir( $name, $excludes, null, $descent_chain );
                    } else {
                        $this->add_file_to_zipp( $name, str_replace( ABSPATH, '', $name ) );
                    }
                }
                $name = null;
                unset( $name );
            }
        } catch ( \UnexpectedValueException $e ) {
            if ( ! $this->native_task ) {
                // Legacy callers cannot publish partial content, so their listing failures must still fail the backup.
                throw $e;
            }
            if ( is_dir( $directory ) ) {
                static::record_skipped_path( rtrim( $directory, '/' ), 'unreadable' );
            }
        }
    }

    /**
     * Retain enough entry names to diagnose lazy ZIP read failures without site-sized state.
     *
     * @param string $entry Accepted archive entry name.
     */
    private function record_pending_zip_entry( $entry ) {
        ++$this->zip_pending_count;
        if ( count( $this->zip_pending_entries ) < 10 ) {
            $this->zip_pending_entries[] = $entry;
        }
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
        $this->assert_zip_disk_space( strlen( $str ) );
        $added = $this->zip->addFromString( $file, $str );
        if ( $added ) {
            $this->record_pending_zip_entry( $file );
            $this->zip_pending_bytes += strlen( $str );
        }
        return $added;
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
        static::assert_disk_space_during_backup( dirname( $filepath ), null, strlen( $str ) );
        try {
            if ( false !== MainWP_Helper::file_put_contents( $tmpfilename, $str ) ) { //phpcs:ignore WordPress.WP.AlternativeFunctions
                if ( $this->native_task ) {
                    MainWP_Helper::protect_backup_path( $tmpfilename );
                }
                clearstatcache( true, $filepath );
                $staged_bytes = strlen( $str ) + ( is_file( $filepath ) ? (int) filesize( $filepath ) : 0 );
                static::assert_disk_space_during_backup( dirname( $filepath ), null, 2 * $staged_bytes );
                $this->zip->delete( PCLZIP_OPT_BY_NAME, $file );
                $add = $this->zip->add(
                    $tmpfilename,
                    PCLZIP_OPT_REMOVE_PATH,
                    dirname( $filepath ),
                    PCLZIP_OPT_ADD_PATH,
                    $localpath
                );
                if ( ! empty( $add ) ) {
                    return true;
                }
            }
        } finally {
            wp_delete_file( $tmpfilename );
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
     * @throws MainWP_Backup_Budget_Exception After closing a slice that made progress.
     *
     * @uses \MainWP\Child\MainWP_Helper::ends_with()
     */
    public function add_file_to_zipp( $path, $zipEntryName ) {
        if ( $this->is_failed_add_skipped( $path ) ) {
            return false;
        }
        if ( time() - $this->lastRun > 20 ) {
            set_time_limit( (int) $this->timeout ); // phpcs:ignore -- required to achieve desired results. Pull requests appreciated.
            $this->lastRun = time();
        }

        if ( $this->excludeZip && MainWP_Helper::ends_with( $path, '.zip' ) ) {
            return false;
        }

        // Walk-time metadata may predate a replaced file or link.
        clearstatcache( true, $path );
        if ( static::is_file_link( $path ) || ! static::is_safe_backup_path( $path, $this->allowed_backup_bases ) ) {
            static::record_skipped_path( $path, static::is_file_link( $path ) ? 'link' : 'unsafe' );
            return false;
        }

        $this->heartbeat();
        if ( $this->zip instanceof \ZipArchive ) {
            // One added entry per slice keeps create_full_backup()'s append loop convergent.
            if ( null !== $this->slice_deadline && $this->zip_slice_progress && time() >= $this->slice_deadline ) {
                // A failed close must propagate before a budget yield can preserve this slice.
                $this->close_zip_archive();
                throw new MainWP_Backup_Budget_Exception( 'File archiving exceeded its time budget for this slice.', null, MainWP_Backup_Error::ERR_ZIP_FAILED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal control-flow message, code is a registry class constant.
            }
            if ( $this->zip_append && false !== $this->zip->locateName( $zipEntryName ) ) {
                return true;
            }
        }
        $size                       = (int) filesize( $path );
        $this->zipArchiveSizeCount += $size;
        ++$this->gcCnt;

        // Always stream files into the archive - the old loadFilesBeforeZip
        // branch loaded files under 5MB fully into memory.
        ++$this->zipArchiveFileCount;
        if ( $this->zip instanceof \ZipArchive ) {
            $this->assert_zip_disk_space( $size );
            $added = $this->zip->addFile( $path, $zipEntryName );
        } else {
            $added = $this->zip->add_file( $path, $zipEntryName );
        }

        if ( false === $added ) {
            $this->record_failed_add( $path );
        } elseif ( $this->zip instanceof \ZipArchive ) {
            $this->record_pending_zip_entry( $zipEntryName );
            $this->zip_pending_bytes += $size;
            $this->zip_slice_progress = true;
            if ( null !== $this->slice_deadline && time() >= $this->slice_deadline ) {
                $this->close_zip_archive();
                throw new MainWP_Backup_Budget_Exception( 'File archiving exceeded its time budget for this slice.', null, MainWP_Backup_Error::ERR_ZIP_FAILED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal control-flow message, code is a registry class constant.
            }
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
            clearstatcache( true, $this->zipArchiveFileName );
            $this->zip_pending_bytes   = (int) filesize( $this->zipArchiveFileName );
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
            $db_files = $dumper->dump_to_files( $filepath_prefix, null, array( $this, 'heartbeat' ) );
            $db_files = $db_files['files'];
        } else {
            $cursor = null;
            do {
                if ( null !== $archiver ) {
                    $archiver->update_pid_file();
                }
                $slice  = $dumper->dump_tier2_slice( $filepath_prefix, $cursor, 45, array( $this, 'heartbeat' ) );
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
     *
     * @throws \Throwable After discarding an unusable partial archive.
     */
    public function archive_db_files( $db_files, $filepath_prefix, $archiveExt ) {
        $archivefilePath = $filepath_prefix . '.sql.' . $archiveExt;

        if ( 'zip' === $archiveExt ) {
            $this->archiver = null;
        } else {
            $this->archiver = new Tar_Archiver( $this, $archiveExt );
        }

        try {
            $archived = $this->zip_file( $db_files, $archivefilePath );
        } catch ( \Throwable $e ) {
            $this->discard_archive_handles();
            try {
                if ( file_exists( $archivefilePath ) ) {
                    wp_delete_file( $archivefilePath );
                }
            } catch ( \Throwable $cleanup_error ) {
                // A deletion failure must not replace the original archive error.
            }
            throw $e;
        }

        if ( $archived && file_exists( $archivefilePath ) ) {
            foreach ( $db_files as $db_file ) {
                wp_delete_file( $db_file );
            }
        }

        return $archivefilePath;
    }
}
