<?php
/**
 * Backup deletion must preserve files outside the legacy archive contract.
 *
 * @package MainWP_Child
 */

namespace MainWP\Child;

use WP_UnitTestCase;

/**
 * An isolated upload root keeps refusal tests away from site files.
 */
class Test_MainWP_Child_Delete_Backup_Filename extends WP_UnitTestCase {

    /**
     * Disposable uploads remain inside this worktree.
     *
     * @var string
     */
    private $fixture_root;

    /**
     * Explicit paths preserve unusual basenames during cleanup.
     *
     * @var array
     */
    private $fixture_files = array();

    private $task_ids = array();

    /**
     * The real helper supplies the directory boundary under test.
     *
     * @var string
     */
    private $backupdir;

    /** @var string Pre-hash archives must remain reachable through the fallback. */
    private $legacy_backupdir;

    /**
     * Other tests must retain their request state.
     *
     * @var array
     */
    private $original_request;

    /**
     * Capturing the reply keeps PHPUnit alive across the terminal write.
     *
     * @var \Closure
     */
    private $delete_backup;

    /**
     * Each case needs fresh protected files, including after a failing deletion.
     */
    public function set_up() {
        parent::set_up();
        MainWP_Child_Backup_Task::instance()->install();
        $this->original_request = $GLOBALS['_REQUEST'];
        $this->fixture_files    = array();
        $this->task_ids         = array();
        $this->fixture_root     = __DIR__ . '/delete-backup-fixtures-' . wp_generate_uuid4();
        $this->assertTrue( wp_mkdir_p( $this->fixture_root ) );
        add_filter( 'upload_dir', array( $this, 'isolate_upload_dir' ) );
        add_filter( 'mainwp_child_get_wp_upload_dir', array( $this, 'isolate_upload_dir' ) );
        $dirs                   = MainWP_Helper::get_mainwp_dir( 'backup' );
        $this->backupdir        = $dirs[0];
        $this->legacy_backupdir = $this->fixture_root . '/mainwp/backup/';
        $this->assertTrue( wp_mkdir_p( $this->legacy_backupdir ) );
        $this->delete_backup    = $this->capture_callable_reply();
    }

    /**
     * Failed assertions must leave neither fixtures nor request state behind.
     */
    public function tear_down() {
        global $wpdb, $wp_filesystem;

        foreach ( $this->task_ids as $id ) {
            $wpdb->delete( MainWP_Child_Backup_Task::instance()->get_table_name(), array( 'task_id' => $id ) );
        }
        remove_filter( 'mainwp_child_get_wp_upload_dir', array( $this, 'isolate_upload_dir' ) );
        remove_filter( 'upload_dir', array( $this, 'isolate_upload_dir' ) );
        $_REQUEST = $this->original_request;
        wp_clear_scheduled_hook( 'mainwp_child_backup_pending_delete' );
        delete_option( MainWP_Child_Backup_Mutex::OPTION_NAME );
        foreach ( $this->fixture_files as $path ) {
            wp_delete_file( $path );
        }
        if ( $this->fixture_root && $wp_filesystem->exists( $this->fixture_root ) ) {
            $this->assertTrue( $wp_filesystem->delete( $this->fixture_root, true ) );
        }
        parent::tear_down();
    }

    /**
     * The existing helper filter avoids touching real uploads.
     *
     * @param array $uploads WordPress upload paths.
     * @return array
     */
    public function isolate_upload_dir( $uploads ) {
        $uploads['basedir'] = $this->fixture_root;
        $uploads['path']    = $this->fixture_root;
        return $uploads;
    }

    /**
     * Traversal must not reach even a harmless file next to the backup directory.
     *
     * @dataProvider backup_directory_provider
     * @param bool   $legacy Whether to exercise the pre-hash directory.
     */
    public function test_traversal_preserves_outside_fixture( $legacy ) {
        $backupdir = $legacy ? $this->legacy_backupdir : $this->backupdir;
        $outside   = dirname( $backupdir ) . '/outside.zip';
        $this->create_fixture( $outside );

        $this->assert_ok_reply( '../outside.zip' );
        $this->assertFileExists( $outside, 'Traversal deleted the outside fixture.' );
    }

    /**
     * Concatenating an absolute path can still delete a matching nested path.
     *
     * @dataProvider backup_directory_provider
     * @param bool   $legacy Whether to exercise the pre-hash directory.
     */
    public function test_absolute_path_is_refused( $legacy ) {
        $backupdir = $legacy ? $this->legacy_backupdir : $this->backupdir;
        $outside   = dirname( $backupdir ) . '/absolute.zip';
        $nested    = $backupdir . $outside;
        $this->create_fixture( $outside );
        $this->create_fixture( $nested );

        $this->assert_ok_reply( $outside );
        $this->assertFileExists( $outside );
        $this->assertFileExists( $nested, 'An absolute name deleted the concatenated fixture.' );
    }

    /**
     * Resolving links must enforce the directory boundary, including its separator.
     *
     * @dataProvider outside_symlink_provider
     * @param string $relative_path Outside target relative to the upload root.
     * @param bool   $legacy Whether to exercise the pre-hash directory.
     */
    public function test_outside_symlink_is_refused( $relative_path, $legacy ) {
        $backupdir = $legacy ? $this->legacy_backupdir : $this->backupdir;
        if ( ! function_exists( 'symlink' ) ) {
            $this->markTestSkipped( 'symlink() is unavailable; outside-link containment cannot be exercised.' );
        }
        $outside = $this->fixture_root . '/' . $relative_path;
        $link    = $backupdir . 'linked.zip';
        $this->create_fixture( $outside );
        $this->assertTrue( symlink( $outside, $link ) );
        $this->fixture_files[] = $link;

        $this->assert_ok_reply( 'linked.zip' );
        $this->assertFileExists( $outside );
        $this->assertTrue( is_link( $link ), 'An outside symlink must remain untouched.' );
    }

    /**
     * A shared prefix is insufficient evidence of containment.
     *
     * @return array
     */
    public static function outside_symlink_provider() {
        return self::in_both_directories( array(
            'outside directory'  => array( 'mainwp/outside.zip' ),
            'shared path prefix' => array( 'mainwp/backup-sibling/outside.zip' ),
        ) );
    }

    /**
     * A link inside the directory resolves to a different archive than the one requested.
     *
     * @dataProvider backup_directory_provider
     * @param bool   $legacy Whether to exercise the pre-hash directory.
     */
    public function test_inside_symlink_is_refused( $legacy ) {
        $backupdir = $legacy ? $this->legacy_backupdir : $this->backupdir;
        if ( ! function_exists( 'symlink' ) ) {
            $this->markTestSkipped( 'symlink() is unavailable; inside-link handling cannot be exercised.' );
        }
        $target = $backupdir . 'original.zip';
        $link   = $backupdir . 'linked.zip';
        $this->create_fixture( $target );
        $this->assertTrue( symlink( $target, $link ) );
        $this->fixture_files[] = $link;

        $this->assert_ok_reply( 'linked.zip' );
        $this->assertFileExists( $target, 'A link deleted the archive it points to.' );
        $this->assertTrue( is_link( $link ), 'An archive symlink must remain untouched.' );
    }

    /**
     * A missing archive must preserve neighbouring files and the legacy reply.
     *
     * @dataProvider backup_directory_provider
     * @param bool   $legacy Whether to exercise the pre-hash directory.
     */
    public function test_missing_file_returns_ok_without_deletion( $legacy ) {
        $backupdir = $legacy ? $this->legacy_backupdir : $this->backupdir;
        $sentinel = $backupdir . 'sentinel.zip';
        $this->create_fixture( $sentinel );

        $this->assert_ok_reply( 'missing.zip' );
        $this->assertFileDoesNotExist( $backupdir . 'missing.zip' );
        $this->assertFileExists( $sentinel );
    }

    /**
     * Control files and unsafe names must survive even when they exist.
     *
     * @dataProvider refused_filename_provider
     * @param string $name Existing file that is not a deletable backup name.
     * @param bool   $legacy Whether to exercise the pre-hash directory.
     */
    public function test_refused_filename_preserves_fixture( $name, $legacy ) {
        $backupdir = $legacy ? $this->legacy_backupdir : $this->backupdir;
        $path      = $backupdir . $name;
        $this->create_fixture( $path );

        $this->assert_ok_reply( $name );
        $this->assertFileExists( $path, 'A refused name deleted its fixture: ' . $name );
    }

    /**
     * Refusing non-archives protects the directory's control and temporary files.
     *
     * @return array
     */
    public static function refused_filename_provider() {
        return self::in_both_directories( array(
            'index'           => array( 'index.php' ),
            'access rules'    => array( '.htaccess' ),
            'pid marker'      => array( 'backup-123.pid' ),
            'archive pid'     => array( 'backup-x.zip.pid' ),
            'done marker'     => array( 'backup-123.done' ),
            'temporary sql'   => array( 'dbBackup-K7Q2M9XA-1760000000.sql' ),
            'sql look-alike'  => array( 'dbBackup-x.sql' ),
            'php sql'         => array( 'x.sql.php' ),
            'temporary table' => array( 'dbBackup-K7Q2M9XA-1760000000-wp_posts.sql.php' ),
            'unanchored zip'  => array( 'x.zip.php' ),
            'double dots'     => array( 'backup..zip' ),
            'slash'           => array( 'nested/backup.zip' ),
            'backslash'       => array( 'nested\\backup.zip' ),
        ) );
    }

    /**
     * Older Dashboards must retain every archive name the Child returns.
     *
     * @dataProvider legacy_filename_provider
     * @param string $name Archive basename returned by the legacy backup flow.
     * @param bool   $legacy Whether to exercise the pre-hash directory.
     */
    public function test_legacy_archive_name_is_deleted( $name, $legacy ) {
        $backupdir = $legacy ? $this->legacy_backupdir : $this->backupdir;
        $path      = $backupdir . $name;
        $this->create_fixture( $path );

        $this->assert_ok_reply( $name );
        $this->assertFileDoesNotExist( $path, 'A legacy archive name must remain deletable: ' . $name );
    }

    /**
     * Custom names prevent a restrictive prefix rule from breaking older Dashboards.
     *
     * @return array
     */
    public static function legacy_filename_provider() {
        return self::in_both_directories( array(
            'full zip'        => array( 'backup-K7Q2M9XA-1760000000.zip' ),
            'full tar'        => array( 'backup-K7Q2M9XA-1760000000.tar' ),
            'full gzip'       => array( 'backup-K7Q2M9XA-1760000000.tar.gz' ),
            'full bzip2'      => array( 'backup-K7Q2M9XA-1760000000.tar.bz2' ),
            'empty uid'       => array( 'backup-1760000000.tar.gz' ),
            'custom name'     => array( 'example.com-blog full (10-12-2026) 14h05m09s.tar.gz' ),
            'custom unicode'  => array( 'café_ブログ ~,;:[full] (2026).zip' ),
            'leading dot'     => array( '.custom-backup.tar' ),
            'database zip'    => array( 'dbBackup-K7Q2M9XA-1760000000.sql.zip' ),
            'database gzip'   => array( 'dbBackup-K7Q2M9XA-1760000000.sql.tar.gz' ),
            'database tar'    => array( 'dbBackup-K7Q2M9XA-1760000000.sql.tar' ),
            'database bzip2'  => array( 'dbBackup-K7Q2M9XA-1760000000.sql.tar.bz2' ),
            'database no uid' => array( 'dbBackup-1760000000.sql.zip' ),
        ) );
    }

    /**
     * Malformed requests must not be coerced into an existing archive name.
     *
     * @dataProvider invalid_request_provider
     * @param mixed $name Request value that cannot identify an archive.
     * @param bool   $legacy Whether to exercise the pre-hash directory.
     */
    public function test_invalid_request_preserves_archive( $name, $legacy ) {
        $backupdir = $legacy ? $this->legacy_backupdir : $this->backupdir;
        $path      = $backupdir . 'sentinel.zip';
        $this->create_fixture( $path );

        $this->assert_ok_reply( $name );
        $this->assertFileExists( $path );
    }

    /**
     * Missing string content cannot establish a safe archive name.
     *
     * @return array
     */
    public static function invalid_request_provider() {
        return self::in_both_directories( array(
            'empty' => array( '' ),
            'nul'   => array( "sentinel\0.zip" ),
            'array' => array( array( 'sentinel.zip' ) ),
        ) );
    }

    /**
     * An archive suffix alone must never authorize deleting a directory.
     *
     * @dataProvider backup_directory_provider
     * @param bool   $legacy Whether to exercise the pre-hash directory.
     */
    public function test_archive_directory_is_refused( $legacy ) {
        $backupdir = $legacy ? $this->legacy_backupdir : $this->backupdir;
        $directory = $backupdir . 'directory.zip';
        $this->assertTrue( wp_mkdir_p( $directory ) );
        $attempts = array();
        $record   = static function ( $path ) use ( &$attempts ) {
            $attempts[] = $path;
            return '';
        };
        add_filter( 'wp_delete_file', $record );
        try {
            $this->assert_ok_reply( 'directory.zip' );
        } finally {
            remove_filter( 'wp_delete_file', $record );
        }
        $this->assertSame( array(), $attempts, 'A directory must never reach the deletion API.' );
        $this->assertDirectoryExists( $directory );
    }

    /** @dataProvider backup_directory_provider */
    public function test_raw_archive_name_is_not_sanitized( $legacy ) {
        $backupdir = $legacy ? $this->legacy_backupdir : $this->backupdir;
        $raw       = $backupdir . 'custom%20backup.zip';
        $sanitized = $backupdir . 'custombackup.zip';
        $this->create_fixture( $raw );
        $this->create_fixture( $sanitized );

        $this->assert_ok_reply( 'custom%20backup.zip' );
        $this->assertFileDoesNotExist( $raw );
        $this->assertFileExists( $sanitized, 'Sanitization must not select a different archive.' );
    }

    public function test_hashed_archive_takes_precedence_over_fallback() {
        $hashed = $this->backupdir . 'duplicate.zip';
        $legacy = $this->legacy_backupdir . 'duplicate.zip';
        $this->create_fixture( $hashed );
        $this->create_fixture( $legacy );

        $this->assert_ok_reply( 'duplicate.zip' );
        $this->assertFileDoesNotExist( $hashed );
        $this->assertFileExists( $legacy );
    }

    public function test_refused_hashed_symlink_does_not_delete_fallback() {
        if ( ! function_exists( 'symlink' ) ) {
            $this->markTestSkipped( 'symlink() is unavailable.' );
        }
        $outside = $this->fixture_root . '/outside.zip';
        $hashed  = $this->backupdir . 'duplicate.zip';
        $legacy  = $this->legacy_backupdir . 'duplicate.zip';
        $this->create_fixture( $outside );
        $this->create_fixture( $legacy );
        $this->assertTrue( symlink( $outside, $hashed ) );
        $this->fixture_files[] = $hashed;

        $this->assert_ok_reply( 'duplicate.zip' );
        $this->assertFileExists( $outside );
        $this->assertTrue( is_link( $hashed ) );
        $this->assertFileExists( $legacy, 'Refusing the selected name must not delete a different copy.' );
    }

    public function test_symlinked_fallback_directory_preserves_target() {
        if ( ! function_exists( 'symlink' ) ) {
            $this->markTestSkipped( 'symlink() is unavailable.' );
        }
        $outside = $this->fixture_root . '/outside/linked.zip';
        $link    = rtrim( $this->legacy_backupdir, '/' );
        $this->create_fixture( $outside );
        $this->assertTrue( rmdir( $link ) );
        $this->assertTrue( symlink( dirname( $outside ), $link ) );
        $this->fixture_files[] = $link;

        $this->assert_ok_reply( 'linked.zip' );
        $this->assertFileExists( $outside );
        $this->assertTrue( is_link( $link ) );
    }

    /** @dataProvider backup_directory_provider */
    public function test_native_task_archive_is_deleted( $legacy ) {
        $backupdir = $legacy ? $this->legacy_backupdir : $this->backupdir;
        $runner    = new class() extends MainWP_Child_Backup_Task_Runner {
            public function archive_name( $backup_dir ) {
                $result = $this->run_archiving_files(
                    (object) array( 'type' => 'full' ),
                    0,
                    array( 'params' => array( 'file' => 'collection', 'ext' => 'zip' ) ),
                    time() + 60,
                    $backup_dir
                );
                return $result['archive_file'];
            }

            // Capture the real generated name before the checkpoint starts database or archive work.
            protected function checkpoint_sub_state( $task, $version, $sub_state, $backup_dir, $then_yield ) {
                return array( 'abandon' => true, 'archive_file' => $sub_state['archive_file'] );
            }
        };
        $name = $runner->archive_name( $backupdir );
        $path = $backupdir . $name;
        $this->create_fixture( $path );

        $this->assert_ok_reply( $name );
        $this->assertFileDoesNotExist( $path, 'Dashboard collection must still remove native task archives.' );
    }

    public function test_completed_native_owner_archive_is_deleted_after_download() {
        $spine = MainWP_Child_Backup_Task::instance();
        $spine->install();
        $name = 'backup-beta6-downloaded.tar.gz';
        $path = $this->backupdir . $name;
        $this->create_fixture( $path );
        $task = $spine->create_task( 'full', array() );
        $this->task_ids[] = $task;
        $this->assertSame( 1, $spine->checkpoint( $task, 0, array(
            'phase' => MainWP_Child_Backup_Task::PHASE_COMPLETED,
            'sub_state' => array( 'artifact' => array( 'file' => $name, 'size' => filesize( $path ) ) ),
        ) ) );

        $this->assert_ok_reply( $name );
        $this->assertFileDoesNotExist( $path );
        $this->assertNotNull( $spine->get_task( $task ), 'Download cleanup keeps the completed owner row.' );
    }

    /** @dataProvider backup_directory_provider */
    public function test_download_cleanup_compatibility_for_tombstones_and_legacy_archives( $legacy ) {
        $dir = $legacy ? $this->legacy_backupdir : $this->backupdir;
        foreach ( array( 'tombstones.list', 'backup-legacy1791262128.tar.gz', 'custom-backup.zip' ) as $name ) {
            $path = $dir . $name;
            $this->create_fixture( $path );
            $this->assert_ok_reply( $name );
            $this->assertFileDoesNotExist( $path );
        }
    }

    /** @dataProvider backup_directory_provider */
    public function test_native_incremental_parts_are_deleted( $legacy ) {
        $backupdir = $legacy ? $this->legacy_backupdir : $this->backupdir;
        foreach ( array( '', '7c5b37d48f' ) as $token ) {
            $name = MainWP_Child_Backup_Incremental_Zip_Writer::part_basename( 'plugins', 1, $token );
            $path = $backupdir . $name;
            $this->create_fixture( $path );

            $this->assert_ok_reply( $name );
            $this->assertFileDoesNotExist( $path, 'Dashboard collection must remove incremental ZIP parts.' );
        }
    }

    /** @dataProvider backup_directory_provider */
    public function test_native_tombstones_artifact_is_deleted_without_accepting_look_alikes( $legacy ) {
        $backupdir = $legacy ? $this->legacy_backupdir : $this->backupdir;
        $name      = MainWP_Child_Backup_Incremental_Diff::TOMBSTONES_LIST;
        $path      = $backupdir . $name;
        $this->create_fixture( $path );
        $this->assert_ok_reply( $name );
        $this->assertFileDoesNotExist( $path, 'Dashboard collection also removes the tombstone artifact.' );

        foreach ( array( 'tombstones.list.php', 'tombstones.list.bak', 'other.list', 'nested/tombstones.list', 'files.list', 'changes.list', 'paths.cache', 'task_substate-x.json' ) as $refused ) {
            $look_alike = $backupdir . $refused;
            $this->create_fixture( $look_alike );
            $this->assert_ok_reply( $refused );
            $this->assertFileExists( $look_alike, 'The tombstone exception must not accept: ' . $refused );
        }
    }

    /** @dataProvider restore_claim_provider */
    public function test_live_restore_source_refuses_download_delete( $adopt_only ) {
        $name = 'backup-rollback-source.zip';
        $this->create_fixture( $this->backupdir . $name );
        $this->task_ids[] = MainWP_Child_Backup_Task::instance()->create_task( 'restore', array(
            'params' => array( 'artifacts' => array( array( 'file' => $name, 'adopt_only' => $adopt_only ) ) ),
        ) );

        $this->assert_busy_reply( $name );
        $this->assertFileExists( $this->backupdir . $name );
        $this->assertFileDoesNotExist( $this->backupdir . 'pending-delete.json' );
    }

    public static function restore_claim_provider() {
        return array( 'source' => array( false ), 'rollback safety source' => array( true ) );
    }

    /** @dataProvider live_backup_claim_provider */
    public function test_live_backup_name_or_prefix_refuses_download_delete( $name, $state ) {
        $this->create_fixture( $this->backupdir . $name );
        $this->task_ids[] = MainWP_Child_Backup_Task::instance()->create_task( 'full', $state );
        $this->assert_busy_reply( $name );
        $this->assertFileExists( $this->backupdir . $name );
        $this->assertFileDoesNotExist( $this->backupdir . 'pending-delete.json' );
    }

    public static function live_backup_claim_provider() {
        return array(
            'archive' => array( 'backup-live.zip', array( 'archive_file' => 'backup-live.zip' ) ),
            'dump prefix' => array( 'dbBackup-live-001.sql.zip', array( 'db_prefix' => 'dbBackup-live' ) ),
            'part' => array( 'plugins.token.part001.zip', array( 'artifacts' => array( array( 'file' => 'plugins.token.part001.zip' ) ) ) ),
        );
    }

    public function test_delete_wins_mutex_before_restore_create() {
        global $wpdb;
        $name = 'backup-delete-wins.zip';
        $this->create_fixture( $this->backupdir . $name );
        $attempt = null;
        $filter = function ( $query ) use ( &$filter, &$attempt ) {
            if ( false !== strpos( $query, "WHERE phase NOT IN ('completed','failed')" ) ) {
                remove_filter( 'query', $filter );
                $attempt = MainWP_Child_Backup_Mutex::acquire( 'native', 'restore-create-interleaved', 60 );
            }
            return $query;
        };
        add_filter( 'query', $filter );
        try {
            $this->assert_ok_reply( $name );
        } finally {
            remove_filter( 'query', $filter );
        }
        $this->assertIsArray( $attempt );
        $this->assertSame( 0, strpos( $attempt['owner'], 'delete-' ) );
        $this->assertFileDoesNotExist( $this->backupdir . $name );
        $this->assertTrue( MainWP_Child_Backup_Mutex::acquire( 'native', 'restore-create-after-delete', 60 ) );
        MainWP_Child_Backup_Mutex::release( 'restore-create-after-delete' );
    }

    /** @dataProvider lost_delete_mutex_provider */
    public function test_download_delete_losing_mutex_records_pending( $expired ) {
        global $wpdb;
        $name = 'backup-mutex-lost.zip';
        $this->create_fixture( $this->backupdir . $name );
        $filter = function ( $query ) use ( &$filter, $wpdb, $expired ) {
            if ( false !== strpos( $query, "WHERE phase NOT IN ('completed','failed')" ) ) {
                remove_filter( 'query', $filter );
                $holder = MainWP_Child_Backup_Mutex::holder();
                $value = $expired ? 'native|' . $holder['owner'] . '|' . ( time() - 1 ) : 'native|restore-create-thief|' . ( time() + 600 );
                $wpdb->update( $wpdb->options, array( 'option_value' => $value ), array( 'option_name' => MainWP_Child_Backup_Mutex::OPTION_NAME ) );
                wp_cache_delete( MainWP_Child_Backup_Mutex::OPTION_NAME, 'options' );
            }
            return $query;
        };
        add_filter( 'query', $filter );
        try {
            $this->assert_busy_reply( $name );
        } finally {
            remove_filter( 'query', $filter );
            delete_option( MainWP_Child_Backup_Mutex::OPTION_NAME );
        }
        $this->assertFileExists( $this->backupdir . $name );
        $pending = json_decode( file_get_contents( $this->backupdir . 'pending-delete.json' ), true );
        $this->assertSame( $name, $pending[0]['name'] );
        $this->assertSame( filesize( $this->backupdir . $name ), $pending[0]['size'] );
        $this->assertSame( filemtime( $this->backupdir . $name ), $pending[0]['mtime'] );
    }

    public static function lost_delete_mutex_provider() {
        return array( 'stolen' => array( false ), 'expired' => array( true ) );
    }

    public function test_unreadable_live_cursor_refuses_download_delete() {
        global $wpdb;
        $name = 'backup-unknown-claim.zip';
        $this->create_fixture( $this->backupdir . $name );
        $spine = MainWP_Child_Backup_Task::instance();
        $task = $spine->create_task( 'restore', array() );
        $this->task_ids[] = $task;
        $wpdb->update( $spine->get_table_name(), array( 'sub_state' => '{broken' ), array( 'task_id' => $task ) );
        $this->assert_busy_reply( $name );
        $this->assertFileExists( $this->backupdir . $name );
        $this->assertFileDoesNotExist( $this->backupdir . 'pending-delete.json' );
    }

    public function test_invalid_or_missing_name_never_takes_mutex_or_records_pending() {
        $attempts = 0;
        $filter = function ( $query ) use ( &$attempts ) {
            if ( false !== strpos( $query, MainWP_Child_Backup_Mutex::OPTION_NAME ) ) {
                ++$attempts;
            }
            return $query;
        };
        add_filter( 'query', $filter );
        try {
            foreach ( array( '../bad.zip', '/etc/bad.zip', "bad.zip\0", 'wp-config.php', array( 'x.zip' ), 'missing.zip' ) as $name ) {
                $this->assert_ok_reply( $name );
            }
        } finally {
            remove_filter( 'query', $filter );
        }
        $this->assertSame( 0, $attempts );
        $this->assertFileDoesNotExist( $this->backupdir . 'pending-delete.json' );
        $this->assertFileDoesNotExist( $this->backupdir . 'pending-delete.lock' );
    }

    private function assert_busy_reply( $name ) {
        $_REQUEST['del'] = wp_slash( $name );
        $this->assertSame( array(
            'error' => MainWP_Backup_Error::sanitize_message( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_ALREADY_RUNNING ) ),
            'error_code' => MainWP_Backup_Error::ERR_ALREADY_RUNNING,
        ), ( $this->delete_backup )() );
        clearstatcache();
    }

    public static function backup_directory_provider() {
        return array( 'hashed' => array( false ), 'fallback' => array( true ) );
    }

    private static function in_both_directories( $cases ) {
        $result = array();
        foreach ( $cases as $label => $args ) {
            $result[ $label . ' (hashed)' ]   = array_merge( $args, array( false ) );
            $result[ $label . ' (fallback)' ] = array_merge( $args, array( true ) );
        }
        return $result;
    }

    /**
     * Only disposable files may be used as deletion targets.
     *
     * @param string $path Harmless fixture path within the isolated upload root.
     */
    private function create_fixture( $path ) {
        global $wp_filesystem;

        $this->assertTrue( wp_mkdir_p( dirname( $path ) ) );
        $this->assertTrue( $wp_filesystem->put_contents( $path, 'Harmless backup deletion fixture.' ) );
        $this->fixture_files[] = $path;
        $this->assertFileExists( $path );
    }

    /**
     * Both deletion and refusal must preserve the Dashboard reply contract.
     *
     * @param mixed $name Requested basename.
     */
    private function assert_ok_reply( $name ) {
        $_REQUEST['del'] = wp_slash( $name );
        $this->assertSame( array( 'result' => 'ok' ), ( $this->delete_backup )() );
        clearstatcache();
    }

    /**
     * Only the terminal write becomes a return because write() dies inside PHPUnit.
     * Binding to the real callable preserves access to its private validation helpers.
     *
     * @return \Closure
     */
    private function capture_callable_reply() {
        global $wp_filesystem;

        $method = new \ReflectionMethod( MainWP_Child_Callable::class, 'delete_backup' );
        $lines  = explode( "\n", $wp_filesystem->get_contents( $method->getFileName() ) );
        $source = implode( "\n", array_slice( $lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1 ) );
        $source = str_replace( 'public function delete_backup()', 'return function()', $source, $declarations );
        $source = preg_replace( '/MainWP_Helper::write\( (.*) \);\s*\}\s*\z/', 'return $1;' . "\n};", $source, 1, $writes );
        $this->assertSame( 1, $declarations );
        $this->assertSame( 1, $writes, 'Capture only the final response; keep the deletion body intact.' );
        $fixture = $this->fixture_root . '/callable-reply.php';
        $this->assertTrue( $wp_filesystem->put_contents( $fixture, '<?php namespace MainWP\\Child;' . "\n" . $source ) );
        $callable = require $fixture;
        return $callable->bindTo( MainWP_Child_Callable::get_instance(), MainWP_Child_Callable::class );
    }
}
