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
     * Used by the during-backup disk space floor (1B.13).
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
        $files = glob( $backupdir . '*.pid' );
        foreach ( $files as $file ) {
            if ( basename( $file ) === basename( $pid ) ) {
                continue;
            }

            if ( ( time() - filemtime( $file ) ) < 160 ) {
                MainWP_Helper::instance()->error( esc_html__( 'Another backup process is running. Please, try again later.', 'mainwp-child' ) );
            }
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

        $this->timeout = 20 * 60 * 60;
        $mem           = '512M';
        MainWP_Helper::set_limit( $this->timeout, $mem );

        try {
            if ( null !== $this->archiver ) {
                $success = $this->archiver->create_full_backup( $filepath, $excludes, $addConfig, $includeCoreFiles, $excludezip, $excludenonwp, $append );
            } elseif ( $this->check_zip_support() ) {
                $success = $this->create_zip_full_backup( $filepath, $excludes, $addConfig, $includeCoreFiles, $excludezip, $excludenonwp );
            } elseif ( $this->check_zip_console() ) {
                $success = $this->create_zip_console_full_backup(); // NOSONAR .
            } else {
                $success = $this->create_zip_pcl_full_backup2( $filepath, $excludes, $addConfig, $includeCoreFiles, $excludezip, $excludenonwp );
            }
        } catch ( \Exception $e ) {
            // Never leave a partial archive behind - a half-written file
            // looks like a backup and restores as corruption (1B.6).
            if ( file_exists( $filepath ) ) {
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

            $minutes = date( 'i', time() ); // phpcs:ignore -- required to achieve desired results, pull request solutions appreciated.
            $seconds = date( 's', time() ); // phpcs:ignore -- required to achieve desired results, pull request solutions appreciated.

            $file_minutes = date( 'i', $time ); // phpcs:ignore -- required to achieve desired results, pull request solutions appreciated.
            $file_seconds = date( 's', $time ); // phpcs:ignore -- required to achieve desired results, pull request solutions appreciated.

            $minuteDiff = $minutes - $file_minutes;
            if ( 59 === $minuteDiff ) {
                $minuteDiff = 1;
            }
            $secondsdiff = ( $minuteDiff * 60 ) + $seconds - $file_seconds;

            $file                = $wp_filesystem->get_contents( $pidFile );
            $information['file'] = basename( $file );
            if ( $secondsdiff < 80 ) {
                $information['status'] = 'busy';
            } else {
                $information['status'] = 'stalled';
            }
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
            $this->check_backup_readiness();
        } catch ( MainWP_Exception $e ) {
            MainWP_Helper::instance()->error( $e->getMessage(), $e->get_message_error_code() );
        }

        $timeout = 20 * 60 * 60;
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
        $type     = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';

        try {
            if ( 'full' === $type || 'db' === $type ) {
                $this->assert_disk_space_for_backup( $backupdir, 'full' === $type );
            }

            if ( 'full' === $type ) {

                $res = $this->backup_full( $fileName );

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
        $excludes   = ( isset( $_POST['exclude'] ) ? explode( ',', wp_unslash( $_POST['exclude'] ) ) : array() ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- ok.
        $excludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/uploads/mainwp';
        $uploadDir  = MainWP_Helper::get_mainwp_dir();
        $uploadDir  = $uploadDir[0];
        $excludes[] = str_replace( ABSPATH, '', $uploadDir );
        $excludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/object-cache.php';

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

        $newExcludes = array();
        foreach ( $excludes as $exclude ) {
            $newExcludes[] = sanitize_text_field( rtrim( $exclude, '/' ) );
        }

        $excludebackup = ( isset( $_POST['excludebackup'] ) && '1' === $_POST['excludebackup'] );
        $excludecache  = ( isset( $_POST['excludecache'] ) && '1' === $_POST['excludecache'] );
        $excludezip    = ( isset( $_POST['excludezip'] ) && '1' === $_POST['excludezip'] );
        $excludenonwp  = ( isset( $_POST['excludenonwp'] ) && '1' === $_POST['excludenonwp'] );

        if ( $excludebackup ) {
            $newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/uploads/backupbuddy_backups';
            $newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/uploads/backupbuddy_temp';
            $newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/uploads/pb_backupbuddy';
            $newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/managewp';
            $newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/infinitewp';
            $newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/backups';
            $newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/backups';
            $newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/uploads/backwpup*';
            $newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/plugins/wp-complete-backup/storage';
            $newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/backups';
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
        $this->timeout = 20 * 60 * 60;
        $mem           = '512M';
        MainWP_Helper::set_limit( $this->timeout, $mem );

        if ( ! is_array( $files ) ) {
            $files = array( $files );
        }

        if ( null !== $this->archiver ) {
            $success = $this->archiver->zip_file( $files, $archive );
        } elseif ( $this->check_zip_support() ) {
            $success = $this->m_zip_file( $files, $archive );
        } elseif ( $this->check_zip_console() ) {
            $success = $this->m_zip_file_console( $files, $archive );
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
     * Method m_zip_file_console().
     *
     * @return bool Return false.
     */
    public function m_zip_file_console() {
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
     * Paths skipped during the current backup traversal (1B.16).
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
     * Allowed containment bases for the current backup traversal, null for
     * the defaults (ABSPATH and WP_CONTENT_DIR).
     *
     * @var array|null Base directories.
     */
    protected $allowed_backup_bases = null;

    /**
     * Decide whether a path is safe to include in a backup (1B.16).
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
     * once too many adds have failed (master 1B.19, phase-1e A2/A8).
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
     * Close the ZIP archive and verify the close succeeded (1B.2).
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
     * Estimate the size of a backup in bytes (1B.13).
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
            $size += static::get_directory_size( WP_CONTENT_DIR );
        }
        return $size;
    }

    /**
     * Sum the size of all regular files under a directory, skipping symlinks.
     *
     * @param string $dir Directory to measure.
     *
     * @return int Total size in bytes.
     */
    private static function get_directory_size( $dir ) {
        $size = 0;
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
                \RecursiveIteratorIterator::LEAVES_ONLY,
                \RecursiveIteratorIterator::CATCH_GET_CHILD
            );
            foreach ( $iterator as $file ) {
                if ( $file->isFile() && ! $file->isLink() ) {
                    $size += (int) $file->getSize();
                }
            }
        } catch ( \Exception $e ) {
            // Unreadable subtree - the walk is best-effort for an estimate.
            unset( $e );
        }
        return $size;
    }

    /**
     * Require enough free disk space for an estimated backup before starting (1B.13).
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
                MainWP_Backup_Error::ERR_DISK_FULL
            ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message parts are escaped, code is a registry class constant.
        }
    }

    /**
     * Hard low-disk floor checked during long archive writes (1B.13).
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
     * Validate the child server environment before a backup starts (1D.2).
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
            'disk_free'       => false,
            'dir_writable'    => false,
            'db_connected'    => false,
            'memory_limit'    => ini_get( 'memory_limit' ),
            'max_exec_time'   => ini_get( 'max_execution_time' ),
            'zip_support'     => $this->check_zip_support(),
            'gz_support'      => function_exists( 'gzopen' ),
            'bz2_support'     => function_exists( 'bzopen' ),
            'time_limit_ok'   => ! in_array( 'set_time_limit', array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) ), true ),
            'backup_dir_hash' => MainWP_Helper::get_backup_dir_hash(),
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

        return $report;
    }

    /**
     * Check if we could run zip on console
     *
     * @return bool Return false.
     */
    public function check_zip_console() {
        return false;
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

            $db_files = $this->create_backup_db( dirname( $filepath ) . DIRECTORY_SEPARATOR . 'dbBackup' );
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

                if ( ! MainWP_Helper::in_excludes( $excludes, str_replace( ABSPATH, '', $node ) ) ) {
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

            if ( ! MainWP_Helper::in_excludes( $excludes, str_replace( ABSPATH, '', $node ) ) ) {
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
        $db_files = $this->create_backup_db( $backupFolder . 'dbBackup' );

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
     * recorded (1B.16).
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

            if ( ! MainWP_Helper::in_excludes( $excludes, str_replace( ABSPATH, '', $name ) ) ) {
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
     * @uses \MainWP\Child\MainWP_Helper::ends_with()
     */
    public function add_file_to_zipp( $path, $zipEntryName ) {
        if ( time() - $this->lastRun > 20 ) {
            set_time_limit( $this->timeout ); // phpcs:ignore -- required to achieve desired results. Pull requests appreciated.
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
        // corrupts the backup silently otherwise (1B.13).
        if ( 0 === $this->zipArchiveFileCount % 100 && ! empty( $this->backup_dir_in_use ) ) {
            static::assert_disk_space_during_backup( $this->backup_dir_in_use );
        }

        // Always stream files into the archive - the old loadFilesBeforeZip
        // branch loaded files under 5MB fully into memory (1B.5/1B.12).
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
     * Create full backup via console.
     *
     * @return bool Return false.
     */
    public function create_zip_console_full_backup() {
        return false;
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

        $timeout = 20 * 60 * 60;
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

        $archivefilePath = null;
        if ( false !== $archiveExt ) {
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
        }
        //phpcs:enable WordPress.WP.AlternativeFunctions

        return false !== $archiveExt ? array( 'filepath' => $archivefilePath ) : $db_files;
    }
}
