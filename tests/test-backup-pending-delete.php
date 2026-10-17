<?php
/**
 * Download cleanup survives busy workers and a restored cron option.
 *
 * @package MainWP_Child
 */

namespace MainWP\Child;

use WP_UnitTestCase;

class Test_Backup_Pending_Delete extends WP_UnitTestCase {

    private $root;
    private $dir;
    private $spine;
    private $tasks = array();

    public function set_up() {
        parent::set_up();
        $this->spine = MainWP_Child_Backup_Task::instance();
        $this->spine->install();
        $this->spine->register_pending_delete_hook();
        $this->tasks = array();
        delete_option( MainWP_Child_Backup_Mutex::OPTION_NAME );
        wp_clear_scheduled_hook( MainWP_Child_Backup_Task::PENDING_DELETE_HOOK );
        $this->root = sys_get_temp_dir() . '/mainwp-pending-' . wp_generate_uuid4();
        wp_mkdir_p( $this->root );
        add_filter( 'upload_dir', array( $this, 'uploads' ) );
        add_filter( 'mainwp_child_get_wp_upload_dir', array( $this, 'uploads' ) );
        $dirs = MainWP_Helper::get_mainwp_dir( 'backup' );
        $this->dir = $dirs[0];
    }

    public function tear_down() {
        global $wpdb, $wp_filesystem;
        foreach ( $this->tasks as $id ) {
            $wpdb->delete( $this->spine->get_table_name(), array( 'task_id' => $id ) );
        }
        remove_filter( 'upload_dir', array( $this, 'uploads' ) );
        remove_filter( 'mainwp_child_get_wp_upload_dir', array( $this, 'uploads' ) );
        wp_clear_scheduled_hook( MainWP_Child_Backup_Task::PENDING_DELETE_HOOK );
        delete_option( MainWP_Child_Backup_Mutex::OPTION_NAME );
        $wp_filesystem->delete( $this->root, true );
        parent::tear_down();
    }

    public function uploads( $uploads ) {
        $uploads['basedir'] = $this->root;
        $uploads['path'] = $this->root;
        return $uploads;
    }

    private function fixture( $name, $data = 'archive' ) {
        $path = $this->dir . $name;
        $this->assertNotFalse( file_put_contents( $path, $data ) );
        clearstatcache( true, $path );
        return $path;
    }

    private function task( $state, $type = 'restore' ) {
        $id = $this->spine->create_task( $type, $state );
        $this->assertIsString( $id );
        $this->tasks[] = $id;
        return $id;
    }

    private function terminal( $id ) {
        $row = $this->spine->get_task( $id );
        $this->assertNotFalse( $this->spine->checkpoint( $id, (int) $row->version, array( 'phase' => MainWP_Child_Backup_Task::PHASE_COMPLETED ), true, $this->dir ) );
    }

    private function entries() {
        if ( ! file_exists( $this->dir . 'pending-delete.json' ) ) {
            return array();
        }
        $entries = json_decode( file_get_contents( $this->dir . 'pending-delete.json' ), true );
        $this->assertIsArray( $entries );
        return $entries;
    }

    private function raw_entries( $entries ) {
        file_put_contents( $this->dir . 'pending-delete.json', wp_json_encode( $entries ) );
    }

    private function entry( $name, $added = null ) {
        return array( 'name' => $name, 'size' => filesize( $this->dir . $name ), 'mtime' => filemtime( $this->dir . $name ), 'added' => null === $added ? time() : $added );
    }

    private function busy_delete( $name ) {
        $this->assertSame( array(
            'error' => MainWP_Backup_Error::sanitize_message( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_ALREADY_RUNNING ) ),
            'error_code' => MainWP_Backup_Error::ERR_ALREADY_RUNNING,
        ), MainWP_Child_Callable::get_instance()->delete_backup_reply( $name ) );
    }

    /** @dataProvider busy_mutex_provider */
    public function test_busy_mutex_records_identity_and_schedules_cron( $engine ) {
        global $wpdb;
        $name = 'backup-busy.zip';
        $path = $this->fixture( $name );
        $filter = static function ( $query ) {
            return 0 === strpos( $query, 'INSERT IGNORE INTO' ) && false !== strpos( $query, MainWP_Child_Backup_Mutex::OPTION_NAME ) ? 'SELECT mainwp_pending_forced_failure' : $query;
        };
        $suppress = $wpdb->suppress_errors( true );
        if ( 'sql-failure' === $engine ) {
            add_filter( 'query', $filter );
        } else {
            $this->assertTrue( MainWP_Child_Backup_Mutex::acquire( $engine, 'restore-create-holder', 300 ) );
        }
        try {
            $this->busy_delete( $name );
        } finally {
            remove_filter( 'query', $filter );
            $wpdb->suppress_errors( $suppress );
            MainWP_Child_Backup_Mutex::release( 'restore-create-holder' );
        }
        $entries = $this->entries();
        $this->assertCount( 1, $entries );
        $this->assertSame( $name, $entries[0]['name'] );
        $this->assertSame( filesize( $path ), $entries[0]['size'] );
        $this->assertSame( filemtime( $path ), $entries[0]['mtime'] );
        $this->assertGreaterThanOrEqual( time() - 5, $entries[0]['added'] );
        $when = wp_next_scheduled( MainWP_Child_Backup_Task::PENDING_DELETE_HOOK );
        $this->assertGreaterThanOrEqual( time() + 15 * MINUTE_IN_SECONDS - 5, $when );
        $this->assertLessThanOrEqual( time() + 15 * MINUTE_IN_SECONDS, $when );
        $this->assertFileExists( $path );
    }

    public static function busy_mutex_provider() {
        return array( 'native owner' => array( 'native' ), 'legacy engine' => array( 'legacy' ), 'SQL returns null' => array( 'sql-failure' ) );
    }

    public function test_restore_create_interleaving_keeps_pending_until_terminal() {
        $name = 'backup-about-to-adopt.zip';
        $path = $this->fixture( $name );
        $this->assertTrue( MainWP_Child_Backup_Mutex::acquire( 'native', 'restore-create-held', 60 ) );
        $this->busy_delete( $name );
        $id = $this->task( array( 'params' => array( 'artifacts' => array( array( 'file' => $name, 'adopt_only' => true ) ) ) ) );
        MainWP_Child_Backup_Mutex::release( 'restore-create-held' );
        $this->spine->retry_pending_deletes( $this->dir );
        $this->assertFileExists( $path );
        $this->assertCount( 1, $this->entries() );
        $this->terminal( $id );
        $this->spine->retry_pending_deletes( $this->dir );
        $this->assertFileDoesNotExist( $path );
        $this->assertFileDoesNotExist( $this->dir . 'pending-delete.json' );
    }

    public function test_beta6_ad_hoc_completed_backup_is_deleted_by_reclaim() {
        $name = 'backup-beta6-update-gate.zip';
        $path = $this->fixture( $name );
        $id = $this->task( array( 'artifact' => array( 'file' => $name, 'size' => filesize( $path ) ) ), 'full' );
        $this->terminal( $id );
        $this->assertTrue( MainWP_Child_Backup_Mutex::acquire( 'native', 'other-worker', 60 ) );
        $this->busy_delete( $name );
        MainWP_Child_Backup_Mutex::release( 'other-worker' );
        $this->spine->reclaim_expired_tasks( $this->dir );
        $this->assertFileDoesNotExist( $path );
        $this->assertNotNull( $this->spine->get_task( $id ) );
        $this->assertSame( array(), $this->entries() );
    }

    private function fire_cron() {
        $hook = MainWP_Child_Backup_Task::PENDING_DELETE_HOOK;
        $this->assertNotFalse( has_action( $hook, array( $this->spine, 'run_pending_deletes' ) ), 'init_full must register the retry for cron requests.' );
        $when = wp_next_scheduled( $hook );
        if ( false !== $when ) {
            wp_unschedule_event( $when, $hook );
        }
        do_action( $hook );
    }

    public function test_cron_alone_drains_busy_delete_and_stops_scheduling() {
        $name = 'backup-cron-only.zip';
        $path = $this->fixture( $name );
        $this->assertTrue( MainWP_Child_Backup_Mutex::acquire( 'legacy', 'legacy-worker', 60 ) );
        $this->busy_delete( $name );
        MainWP_Child_Backup_Mutex::release( 'legacy-worker' );
        $this->fire_cron();
        $this->assertFileDoesNotExist( $path );
        $this->assertFileDoesNotExist( $this->dir . 'pending-delete.json' );
        $this->assertFalse( wp_next_scheduled( MainWP_Child_Backup_Task::PENDING_DELETE_HOOK ) );
    }

    public function test_cron_busy_or_live_claim_keeps_one_retry() {
        $name = 'backup-cron-claimed.zip';
        $path = $this->fixture( $name );
        $this->spine->record_pending_delete( $name, $this->dir );
        $this->assertTrue( MainWP_Child_Backup_Mutex::acquire( 'native', 'other-worker', 60 ) );
        $this->fire_cron();
        $this->assertNotFalse( wp_next_scheduled( MainWP_Child_Backup_Task::PENDING_DELETE_HOOK ) );
        MainWP_Child_Backup_Mutex::release( 'other-worker' );
        $id = $this->task( array( 'params' => array( 'artifacts' => array( array( 'file' => $name ) ) ) ) );
        $this->fire_cron();
        $this->assertFileExists( $path );
        $this->assertCount( 1, $this->entries() );
        $events = array_filter( _get_cron_array(), static function ( $hooks ) { return isset( $hooks[ MainWP_Child_Backup_Task::PENDING_DELETE_HOOK ] ); } );
        $this->assertCount( 1, $events );
        $this->terminal( $id );
        $this->fire_cron();
        $this->assertFileDoesNotExist( $path );
        $this->assertFalse( wp_next_scheduled( MainWP_Child_Backup_Task::PENDING_DELETE_HOOK ) );
    }

    private function authenticated_request() {
        $post = $_POST;
        $probe = new class() extends MainWP_Child_Callable {
            public $called = false;
            public $scheduled_at_dispatch = false;

            public function call_function( $func ) {
                $this->called = 'delete_backup' === $func;
                $this->scheduled_at_dispatch = wp_next_scheduled( MainWP_Child_Backup_Task::PENDING_DELETE_HOOK );
            }
        };
        $_POST['function'] = 'delete_backup';
        try {
            $probe->init_call_functions( true );
            $this->assertTrue( $probe->called );
            return $probe->scheduled_at_dispatch;
        } finally {
            $_POST = $post;
        }
    }

    public function test_authenticated_request_rearms_after_database_restore_loses_cron() {
        $name = 'backup-restored-cron.zip';
        $path = $this->fixture( $name );
        $this->assertTrue( MainWP_Child_Backup_Mutex::acquire( 'native', 'restore-create-held', 60 ) );
        $this->busy_delete( $name );
        $id = $this->task( array( 'params' => array( 'artifacts' => array( array( 'file' => $name, 'adopt_only' => true ) ) ) ) );
        MainWP_Child_Backup_Mutex::release( 'restore-create-held' );
        wp_clear_scheduled_hook( MainWP_Child_Backup_Task::PENDING_DELETE_HOOK );
        $this->assertFileExists( $this->dir . 'pending-delete.json' );
        $this->terminal( $id );
        $this->assertNotFalse( $this->authenticated_request(), 'Rearm must happen before dispatch.' );
        $this->assertNotFalse( wp_next_scheduled( MainWP_Child_Backup_Task::PENDING_DELETE_HOOK ) );
        $this->fire_cron();
        $this->assertFileDoesNotExist( $path );
        $this->assertSame( array(), $this->entries() );
    }

    /** @dataProvider replaced_identity_provider */
    public function test_reused_name_drops_intent_without_deleting_new_file( $field ) {
        $name = 'backup-reused.zip';
        $path = $this->fixture( $name );
        $this->spine->record_pending_delete( $name, $this->dir );
        if ( 'size' === $field ) {
            file_put_contents( $path, 'a different longer archive' );
        } else {
            touch( $path, filemtime( $path ) + 60 );
        }
        clearstatcache( true, $path );
        $this->spine->retry_pending_deletes( $this->dir );
        $this->assertFileExists( $path );
        $this->assertSame( array(), $this->entries() );
    }

    public static function replaced_identity_provider() {
        return array( 'size' => array( 'size' ), 'mtime' => array( 'mtime' ) );
    }

    public function test_missing_file_after_crash_drops_entry() {
        $name = 'backup-crash.zip';
        $path = $this->fixture( $name );
        $this->spine->record_pending_delete( $name, $this->dir );
        unlink( $path );
        $this->spine->retry_pending_deletes( $this->dir );
        $this->assertFileDoesNotExist( $this->dir . 'pending-delete.json' );
        $this->assertFalse( wp_next_scheduled( MainWP_Child_Backup_Task::PENDING_DELETE_HOOK ) );
    }

    /** @dataProvider corrupt_list_provider */
    public function test_corrupt_or_hostile_list_cannot_delete_other_files( $json ) {
        $protected = $this->fixture( 'wp-config.php', 'protected' );
        $outside = $this->root . '/outside.zip';
        file_put_contents( $outside, 'outside' );
        file_put_contents( $this->dir . 'pending-delete.json', $json );
        $this->spine->retry_pending_deletes( $this->dir );
        $this->assertFileExists( $protected );
        $this->assertSame( 'outside', file_get_contents( $outside ) );
        $this->assertSame( array(), $this->entries() );
    }

    public static function corrupt_list_provider() {
        $cases = array( 'invalid JSON' => array( '{' ), 'non-array' => array( '"x"' ), 'object' => array( '{}' ) );
        foreach ( array( '../outside.zip', '/etc/x.zip', 'wp-config.php', "x.zip\0" ) as $i => $name ) {
            $cases[ 'hostile-' . $i ] = array( json_encode( array( array( 'name' => $name, 'size' => 7, 'mtime' => 1, 'added' => time() ) ) ) );
        }
        $cases['non-numeric'] = array( json_encode( array( array( 'name' => 'x.zip', 'size' => array(), 'mtime' => 'bad', 'added' => time() ) ) ) );
        return $cases;
    }

    /** @dataProvider pending_capacity_provider */
    public function test_huge_list_is_bounded_and_201st_intent_drops_oldest( $count ) {
        $entries = array();
        for ( $i = 0; $i < $count; ++$i ) {
            $name = 'backup-limit-' . $i . '.zip';
            $this->fixture( $name );
            $entries[] = $this->entry( $name, time() - 1000 + $i );
        }
        $this->raw_entries( $entries );
        $new = 'backup-newest.zip';
        $this->fixture( $new );
        $this->spine->record_pending_delete( $new, $this->dir );
        $saved = $this->entries();
        $this->assertCount( 200, $saved );
        $this->assertSame( 'backup-limit-' . ( $count - 199 ) . '.zip', $saved[0]['name'] );
        $this->assertSame( $new, $saved[199]['name'] );
        $this->assertFileExists( $this->dir . 'backup-limit-0.zip' );
    }

    public static function pending_capacity_provider() {
        return array( '201st name' => array( 200 ), 'oversized input' => array( 250 ) );
    }

    public function test_rerecord_replaces_identity_without_duplicate() {
        $name = 'backup-refresh.zip';
        $path = $this->fixture( $name );
        $this->raw_entries( array( $this->entry( $name, time() - 60 ) ) );
        file_put_contents( $path, 'new longer data' );
        clearstatcache( true, $path );
        $this->spine->record_pending_delete( $name, $this->dir );
        $entries = $this->entries();
        $this->assertCount( 1, $entries );
        $this->assertSame( filesize( $path ), $entries[0]['size'] );
        $this->assertGreaterThanOrEqual( time() - 5, $entries[0]['added'] );
    }

    public function test_entry_older_than_30_days_expires_without_delete() {
        $name = 'backup-expired-intent.zip';
        $path = $this->fixture( $name );
        $this->raw_entries( array( $this->entry( $name, time() - 30 * DAY_IN_SECONDS - 1 ) ) );
        $this->spine->retry_pending_deletes( $this->dir );
        $this->assertFileExists( $path );
        $this->assertSame( array(), $this->entries() );
    }

    public function test_retry_stops_when_mutex_is_stolen_mid_pass() {
        global $wpdb;
        $names = array( 'backup-first.zip', 'backup-second.zip', 'backup-third.zip' );
        foreach ( $names as $name ) {
            $this->fixture( $name );
            $this->spine->record_pending_delete( $name, $this->dir );
        }
        $this->raw_entries( array( $this->entry( $names[0], time() - 3 ), $this->entry( $names[1], time() - 2 ), $this->entry( $names[2], time() - 1 ) ) );
        $deleted = 0;
        $filter = function ( $query ) use ( &$filter, &$deleted, $wpdb ) {
            if ( false !== strpos( $query, "WHERE phase NOT IN ('completed','failed')" ) && ++$deleted === 2 ) {
                remove_filter( 'query', $filter );
                $wpdb->update( $wpdb->options, array( 'option_value' => 'native|thief|' . ( time() + 600 ) ), array( 'option_name' => MainWP_Child_Backup_Mutex::OPTION_NAME ) );
                wp_cache_delete( MainWP_Child_Backup_Mutex::OPTION_NAME, 'options' );
            }
            return $query;
        };
        add_filter( 'query', $filter );
        try {
            $this->spine->retry_pending_deletes( $this->dir );
        } finally {
            remove_filter( 'query', $filter );
        }
        $this->assertFileDoesNotExist( $this->dir . $names[0] );
        $this->assertFileExists( $this->dir . $names[1] );
        $this->assertFileExists( $this->dir . $names[2] );
        $this->assertSame( array_slice( $names, 1 ), array_column( $this->entries(), 'name' ) );
        $this->assertSame( 'thief', MainWP_Child_Backup_Mutex::holder()['owner'] );
    }

    public function test_retry_lock_serializes_waiting_writer_without_losing_entry() {
        $first = 'backup-locked.zip';
        $later = 'backup-writer.zip';
        $kept = 'backup-protected.zip';
        $this->fixture( $first );
        $this->fixture( $later );
        $this->fixture( $kept );
        $this->task( array( 'params' => array( 'artifacts' => array( array( 'file' => $kept ) ) ) ) );
        $this->spine->record_pending_delete( $kept, $this->dir );
        $this->spine->record_pending_delete( $first, $this->dir );
        $blocked = false;
        $filter = function ( $query ) use ( &$blocked, &$filter ) {
            if ( false !== strpos( $query, "WHERE phase NOT IN ('completed','failed')" ) ) {
                remove_filter( 'query', $filter );
                $handle = fopen( $this->dir . 'pending-delete.lock', 'c' );
                $acquired = flock( $handle, LOCK_EX | LOCK_NB );
                $blocked = ! $acquired;
                if ( $acquired ) {
                    flock( $handle, LOCK_UN );
                }
                fclose( $handle );
            }
            return $query;
        };
        add_filter( 'query', $filter );
        try {
            $this->spine->retry_pending_deletes( $this->dir );
        } finally {
            remove_filter( 'query', $filter );
        }
        $this->assertTrue( $blocked, 'A busy-path writer must wait on the separate lock inode.' );
        $this->assertFileDoesNotExist( $this->dir . $first );
        $this->assertTrue( MainWP_Child_Backup_Mutex::acquire( 'native', 'busy-writer', 60 ) );
        $this->busy_delete( $later );
        MainWP_Child_Backup_Mutex::release( 'busy-writer' );
        $this->assertEqualsCanonicalizing( array( $kept, $later ), array_column( $this->entries(), 'name' ) );
        $this->assertSame( array(), glob( $this->dir . 'pending-delete.json.*.tmp' ) );
        $handle = fopen( $this->dir . 'pending-delete.lock', 'c' );
        $this->assertTrue( flock( $handle, LOCK_EX | LOCK_NB ) );
        flock( $handle, LOCK_UN );
        fclose( $handle );
    }

    public function test_failed_unlink_keeps_pending_until_a_later_retry() {
        $name = 'backup-unlink-retry.zip';
        $path = $this->fixture( $name );
        $this->spine->record_pending_delete( $name, $this->dir );
        // Compare basenames: the deleter passes a resolved path (macOS /var is /private/var).
        $block = static function ( $file ) use ( $path ) {
            return basename( $path ) === basename( $file ) ? '' : $file;
        };
        add_filter( 'wp_delete_file', $block );
        try {
            $this->spine->retry_pending_deletes( $this->dir );
        } finally {
            remove_filter( 'wp_delete_file', $block );
        }
        $this->assertFileExists( $path );
        $this->assertCount( 1, $this->entries() );
        $this->spine->retry_pending_deletes( $this->dir );
        $this->assertFileDoesNotExist( $path );
        $this->assertSame( array(), $this->entries() );
    }

    /** @dataProvider absent_pending_provider */
    public function test_request_without_pending_file_or_hash_has_no_storage_side_effects( $remove_hash ) {
        global $wpdb, $wp_filesystem;
        if ( $remove_hash ) {
            delete_option( 'mainwp_backup_dir_hash' );
        }
        $wp_filesystem->delete( $this->root, true );
        $options = $wpdb->get_results( "SELECT option_name, option_value FROM $wpdb->options ORDER BY option_name", ARRAY_A );
        $this->assertFalse( $this->authenticated_request() );
        $this->assertSame( $options, $wpdb->get_results( "SELECT option_name, option_value FROM $wpdb->options ORDER BY option_name", ARRAY_A ) );
        $this->assertDirectoryDoesNotExist( $this->root );
        $this->assertFalse( wp_next_scheduled( MainWP_Child_Backup_Task::PENDING_DELETE_HOOK ) );
    }

    public static function absent_pending_provider() {
        return array( 'no file' => array( false ), 'no hash' => array( true ) );
    }
}
