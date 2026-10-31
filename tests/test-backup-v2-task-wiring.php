<?php
/**
 * Task capture stays off until negotiated activation.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Child_Backup_Task_Runner;
use MainWP\Child\MainWP_Child_Backup_Layout;
use MainWP\Child\MainWP_Child_Backup_Layout_List;
use MainWP\Child\MainWP_Child_Backup_Layout_Codec;
use MainWP\Child\MainWP_Backup_Error;
use MainWP\Child\MainWP_Exception;

class Backup_V2_Task_Probe extends MainWP_Child_Backup_Task_Runner {
    public $enabled = true;
    public $tar_available = true;
    public $part_bytes = null;
    public $before_pack = null;
    public $roots;
    public $saved;
    public $checkpoints = array();
    public $done = false;
    protected function v2_capture_enabled() { return $this->enabled; }
    protected function v2_tar_available() { return $this->tar_available; }
    protected function v2_capture_roots() { return $this->roots; }
    protected function site_root() { return $this->roots['site']; }
    protected function backup_dir() { return $this->scratch; }
    public $scratch;
    protected function dump_heartbeat() { return static function () {}; }
    protected function make_v2_part_writer( $backup_dir, $token, $bases ) {
        if ( is_callable( $this->before_pack ) ) {
            call_user_func( $this->before_pack );
            $this->before_pack = null;
        }
        if ( null === $this->part_bytes ) {
            return parent::make_v2_part_writer( $backup_dir, $token, $bases );
        }
        return new \MainWP\Child\MainWP_Child_Backup_Incremental_Zip_Writer( $backup_dir, 'files', null, $this->part_bytes, $token, $bases );
    }
    protected function checkpoint_sub_state( $task, $version, $state, $dir, $yield ) {
        $this->saved = $state;
        $this->checkpoints[] = array( 'state' => $state, 'files' => array_map( 'basename', glob( $dir . '/*.mwp2*' ) ) );
        return array( 'version' => $version + 1, 'yield' => $yield, 'abandon' => false );
    }
    protected function advance_phase( $task, $version, $state, $dir ) {
        $this->saved = $state;
        $this->done = true;
        return array( 'version' => $version + 1, 'yield' => false, 'abandon' => false );
    }
    public function finish_terminal_phase( $task, $state ) {
        return parent::advance_phase( $task, (int) $task->version, $state, $this->scratch );
    }
    public function slice( $task, $state ) {
        return $this->run_archiving_files( $task, 1, $state, time() + 30, $this->scratch );
    }
}

class Backup_V2_Default_Task_Probe extends Backup_V2_Task_Probe {
    protected function v2_capture_enabled() {
        $runner = new MainWP_Child_Backup_Task_Runner();
        return parent::v2_capture_enabled() && method_exists( $runner, 'allows_v2_capture' ) && $runner->allows_v2_capture();
    }
    protected function run_file_archiver( $params, $file, $append, $deadline, $db_files, $skip_db = false ) {
        file_put_contents( $this->scratch . '/' . $file, 'legacy output' );
        return array( 'file' => $file, 'filesize' => 13 );
    }
}

class BackupV2TaskWiringTest extends WP_UnitTestCase {
    private $dir;
    private $runner;

    public function set_up() {
        parent::set_up();
        global $wpdb;
        $spine = \MainWP\Child\MainWP_Child_Backup_Task::instance();
        $table = $spine->get_table_name();
        // WordPress test tables are temporary and do not appear in SHOW TABLES.
        $query = $wpdb->prepare( 'SELECT 1 FROM %i LIMIT 0', $table );
        $suppress = $wpdb->suppress_errors( true );
        $exists = $wpdb->query( $query );
        $wpdb->suppress_errors( $suppress );
        if ( false === $exists ) {
            delete_option( \MainWP\Child\MainWP_Child_Backup_Task::DB_VERSION_OPTION );
        }
        $spine->install();
        $this->assertNotFalse( $wpdb->query( $query ), 'The task table must exist for real artifact ownership checks.' );
        $this->dir = sys_get_temp_dir() . '/mwp-v2-task-' . uniqid();
        mkdir( $this->dir );
        foreach ( array( 'core', 'content', 'uploads', 'plugins', 'scratch-one', 'scratch-two' ) as $name ) {
            mkdir( $this->dir . '/' . $name );
        }
        file_put_contents( $this->dir . '/wp-config.php', 'fake recovery config' );
        file_put_contents( $this->dir . '/content/a.txt', 'content-one' );
        file_put_contents( $this->dir . '/uploads/a.txt', 'uploads-one' );
        file_put_contents( $this->dir . '/plugins/a.php', 'plugin-one' );
        $this->runner = new Backup_V2_Task_Probe();
        $this->runner->roots = array( 'site' => $this->dir . '/core', 'content' => $this->dir . '/content', 'uploads' => $this->dir . '/uploads', 'plugins' => $this->dir . '/plugins' );
        $this->runner->scratch = $this->dir . '/scratch-one';
    }

    public function tear_down() {
        $this->remove_tree( $this->dir );
        parent::tear_down();
    }

    private function remove_tree( $path ) {
        if ( is_dir( $path ) && ! is_link( $path ) ) {
            foreach ( scandir( $path ) as $name ) {
                if ( '.' !== $name && '..' !== $name ) { $this->remove_tree( $path . '/' . $name ); }
            }
            rmdir( $path );
        } elseif ( file_exists( $path ) || is_link( $path ) ) { unlink( $path ); }
    }

    private function task( $type = 'files' ) {
        return (object) array( 'task_id' => 'fixture' . $type, 'type' => $type, 'phase' => 'archiving_files' );
    }

    private function complete( $task, $state = array( 'params' => array() ) ) {
        $this->assertTrue( method_exists( $this->runner, 'allows_v2_capture' ), 'Root-aware task capture is unavailable.' );
        $this->runner->done = false;
        $calls = 0;
        do {
            $this->runner->slice( $task, $state );
            $state = json_decode( wp_json_encode( $this->runner->saved ), true );
            $this->assertLessThan( 20, ++$calls, 'Task capture did not complete.' );
        } while ( ! $this->runner->done );
        $this->assertGreaterThan( 1, $calls, 'The initial binding checkpoint must precede file writes.' );
        return $state;
    }

    public function test_default_off_ignores_request_format_and_keeps_v1_output() {
        $runner = new Backup_V2_Default_Task_Probe();
        $runner->roots = $this->runner->roots;
        $runner->scratch = $this->runner->scratch;
        $production = new MainWP_Child_Backup_Task_Runner();
        $this->assertFalse( method_exists( $production, 'allows_v2_capture' ) && $production->allows_v2_capture() );
        $runner->slice( $this->task(), array( 'params' => array( 'backup_format' => 2, 'expected_layout_id' => str_repeat( 'a', 64 ), 'ext' => 'zip' ) ) );
        $this->assertTrue( $runner->done );
        $this->assertArrayNotHasKey( 'backup_format', $runner->saved );
        $this->assertSame( 'legacy output', file_get_contents( $runner->scratch . '/' . $runner->saved['artifact']['file'] ) );
        $this->assertSame( array(), glob( $runner->scratch . '/*.mwp2*' ) );
    }

    public function test_enabled_full_capture_resumes_and_round_trips_external_roots_and_recovery_config() {
        $state = $this->complete( $this->task() );
        $this->assertSame( 2, $state['backup_format'] );
        $parts = array_values( array_filter( $state['artifacts'], static function ( $a ) { return 'full' === $a['role'] || 'part' === $a['role']; } ) );
        $this->assertCount( 1, $parts );
        mkdir( $this->dir . '/read' );
        $cursor = array();
        do {
            $read = ( new MainWP_Child_Backup_Layout_Codec() )->read_slice( $this->runner->scratch . '/' . $parts[0]['file'], $this->dir . '/read', $state['layout_descriptor_sha256'], $cursor, microtime( true ) + 10 );
            $cursor = $read['cursor'];
        } while ( ! $read['done'] );
        foreach ( array( 'content/a.txt' => 'content-one', 'uploads/a.txt' => 'uploads-one', 'plugins/a.php' => 'plugin-one', 'config/wp-config.php' => 'fake recovery config' ) as $name => $bytes ) {
            $this->assertSame( $bytes, file_get_contents( $this->dir . '/read/extracted/roots/' . $name ) );
        }
        $this->assertFileDoesNotExist( $this->dir . '/read/extracted/roots/site' );
    }

    public function test_incremental_uses_same_descriptor_in_a_new_scratch_and_qualified_tombstones() {
        $full = $this->complete( $this->task() );
        $parent = file_get_contents( $this->runner->scratch . '/files.list.staged' );
        unlink( $this->dir . '/uploads/a.txt' );
        file_put_contents( $this->dir . '/content/new.txt', 'new content' );
        $this->runner->scratch = $this->dir . '/scratch-two';
        $incremental = $this->complete( $this->task( 'incremental' ), array( 'params' => array( 'parent_manifest_b64' => base64_encode( $parent ) ) ) );
        $this->assertSame( $full['layout_descriptor_sha256'], $incremental['layout_descriptor_sha256'] );
        $lists = new MainWP_Child_Backup_Layout_List();
        $this->assertSame( array( 'roots/uploads/a.txt' => true ), $lists->read( $this->runner->scratch . '/tombstones.list', $full['layout_descriptor_sha256'], true ) );
        $this->assertArrayHasKey( 'roots/content/new.txt', $lists->read( $this->runner->scratch . '/changes.list', $full['layout_descriptor_sha256'] ) );
        $this->assertSame( array( 'part', 'manifest', 'tombstones' ), array_column( $incremental['artifacts'], 'role' ) );
    }

    public function test_pack_omissions_are_qualified_and_protect_parent_tombstones() {
        $full = $this->complete( $this->task() );
        $lists = new MainWP_Child_Backup_Layout_List();
        $rows = $lists->read( $this->runner->scratch . '/files.list.staged', $full['layout_descriptor_sha256'] );
        unset( $rows['roots/content/a.txt'] );
        $rows['roots/content/a.txt/old-child'] = array( 1, 1 );
        $lists->write( $this->dir . '/parent.list', $full['layout_descriptor_sha256'], $rows );
        file_put_contents( $this->dir . '/uploads/a.txt', 'changed upload bytes' );
        $this->runner->scratch = $this->dir . '/scratch-two';
        $this->runner->before_pack = function () {
            unlink( $this->dir . '/content/a.txt' );
            unlink( $this->dir . '/uploads/a.txt' );
            symlink( $this->dir . '/plugins/a.php', $this->dir . '/uploads/a.txt' );
        };
        $state = $this->complete( $this->task( 'incremental' ), array( 'params' => array( 'parent_manifest_b64' => base64_encode( file_get_contents( $this->dir . '/parent.list' ) ) ) ) );
        $this->assertSame( array( 'roots/content/a.txt' => 'unstatable', 'roots/uploads/a.txt' => 'link' ), $state['v2_pack_omitted'] );
        $this->assertSame( array( 'roots/content/a.txt', 'roots/uploads/a.txt' ), $state['v2_walk']['unarchived'] );
        $this->assertSame( 2, $state['_skip_report']['skipped_count'] );
        $this->assertSame( array(), $lists->read( $this->runner->scratch . '/tombstones.list', $full['layout_descriptor_sha256'], true ) );
        $manifest = array_values( array_filter( $state['artifacts'], static function ( $a ) { return 'manifest' === $a['role']; } ) )[0];
        $this->assertSame( $state['v2_walk']['unarchived'], $manifest['walk']['unarchived'] );
    }

    public function test_intra_page_rotation_keeps_closed_parts_owned_during_the_next_part() {
        $this->assertTrue( method_exists( $this->runner, 'allows_v2_capture' ), 'Root-aware task capture is unavailable.' );
        $this->runner->part_bytes = 10;
        $task = $this->task();
        $state = array( 'params' => array() );
        for ( $attempt = 0; $attempt < 20; ++$attempt ) {
            $this->runner->slice( $task, $state );
            $state = $this->runner->saved;
            if ( false !== strpos( $state['archive_file'] ?? '', '.part002.mwp2' ) && is_file( $this->runner->scratch . '/' . $state['archive_file'] . '.tar' ) ) {
                break;
            }
        }
        $this->assertLessThan( 20, $attempt, 'The second part of the first page was not reached.' );
        $this->assertSame( 0, $state['v2_batch'] ?? 0 );
        $this->assertArrayNotHasKey( 'v2_completed_parts', $state, 'The page must still be unfinished.' );
        $this->assertCount( 1, $state['completed_parts'] );
        $closed = $state['completed_parts'][0]['file'];
        $this->assertFileExists( $this->runner->scratch . '/' . $closed );
        $spine = \MainWP\Child\MainWP_Child_Backup_Task::instance();
        $tracked = $spine->task_file_names( $task->task_id, $state, $this->runner->scratch );
        $this->assertContains( $closed, $tracked );
        $this->assertContains( $state['archive_file'], $tracked );
        $this->assertContains( $state['archive_file'] . '.tar', $tracked );
        $this->runner->cleanup_failed_task_artifacts( $task->task_id, $state, $this->runner->scratch );
        $this->assertSame( array(), glob( $this->runner->scratch . '/*.mwp2*' ) );
    }

    public function test_mid_pack_files_are_named_before_writes_and_cleaned_on_failure() {
        for ( $index = 0; $index < 101; ++$index ) {
            file_put_contents( $this->dir . '/content/cancel-' . $index . '.txt', 'cancel fixture' );
        }
        $this->assertTrue( method_exists( $this->runner, 'allows_v2_capture' ), 'Root-aware task capture is unavailable.' );
        $task = $this->task();
        $state = array( 'params' => array() );
        for ( $attempt = 0; $attempt < 20; ++$attempt ) {
            $this->runner->slice( $task, $state );
            $state = $this->runner->saved;
            if ( ! empty( $state['completed_parts'] ) && ! empty( $state['archive_file'] ) && is_file( $this->runner->scratch . '/' . $state['archive_file'] . '.tar' ) ) {
                break;
            }
        }
        $this->assertLessThan( 20, $attempt, 'The second partial batch was not reached.' );
        $this->assertNotEmpty( $state['completed_parts'] );
        $spine = \MainWP\Child\MainWP_Child_Backup_Task::instance();
        $tracked = $spine->task_file_names( $task->task_id, $state, $this->runner->scratch );
        $this->assertContains( $state['archive_file'], $tracked );
        $this->assertContains( $state['archive_file'] . '.tar', $tracked );
        $this->assertContains( $state['completed_parts'][0]['file'], $tracked );
        $prewrite = false;
        foreach ( $this->runner->checkpoints as $checkpoint ) {
            if ( ( $checkpoint['state']['archive_file'] ?? null ) === $state['archive_file'] && ! in_array( $state['archive_file'] . '.tar', $checkpoint['files'], true ) ) {
                $names = $spine->task_file_names( $task->task_id, $checkpoint['state'], $this->runner->scratch );
                $prewrite = in_array( $state['archive_file'], $names, true ) && in_array( $state['archive_file'] . '.tar', $names, true );
                break;
            }
        }
        $this->assertTrue( $prewrite, 'Archive names must be durable before the first write.' );
        $this->runner->cleanup_failed_task_artifacts( $task->task_id, $state, $this->runner->scratch );
        $this->assertSame( array(), glob( $this->runner->scratch . '/*.mwp2*' ) );
    }

    public function test_qualified_omission_report_reaches_real_terminal_completion() {
        symlink( $this->dir . '/content/a.txt', $this->dir . '/content/link.txt' );
        $state = $this->complete( $this->task() );
        $this->assertSame( 1, $state['_skip_report']['skipped_count'] );
        $this->assertSame( array( 'roots/content/link.txt' ), $state['_skip_report']['skipped_paths'] );
        $this->assertSame( array( 'link' => 1 ), $state['_skip_report']['skipped_by_reason'] );
        $this->assertSame( $state['v2_walk']['skipped_paths'], $state['_skip_report']['skipped_paths'] );
        $spine = \MainWP\Child\MainWP_Child_Backup_Task::instance();
        $task_id = $spine->create_task( 'files', $state );
        $this->assertIsString( $task_id );
        $task = $spine->get_task( $task_id );
        $version = $spine->checkpoint( $task_id, (int) $task->version, array( 'phase' => 'submitting' ), false, $this->runner->scratch );
        $this->assertNotFalse( $version );
        $task = $spine->get_task( $task_id );
        $result = $this->runner->finish_terminal_phase( $task, $state );
        $this->assertFalse( $result['abandon'] );
        $completed = $spine->get_task( $task_id );
        $this->assertSame( 'completed', $completed->phase );
        $saved = $spine->read_sub_state( $completed, $this->runner->scratch );
        $this->assertSame( 'partial', $saved['content_status'] );
        $this->assertSame( array( 'roots/content/link.txt' ), $saved['_skip_report']['skipped_paths'] );
        $manifest = array_values( array_filter( $saved['artifacts'], static function ( $artifact ) { return 'manifest' === $artifact['role']; } ) )[0];
        $this->assertSame( array( 'roots/content/link.txt' ), $manifest['walk']['skipped_paths'] );
    }

    public function test_pack_pages_resume_without_replacing_earlier_parts() {
        for ( $index = 0; $index < 101; ++$index ) {
            file_put_contents( $this->dir . '/content/page-' . $index . '.txt', 'page content' );
        }
        $state = $this->complete( $this->task() );
        $this->assertSame( 2, $state['v2_batch'] );
        $this->assertSame( array( 100, 5 ), array_column( $state['v2_completed_parts'], 'entries' ) );
        $this->assertCount( 2, array_unique( array_column( $state['v2_completed_parts'], 'file' ) ) );
        foreach ( $state['v2_completed_parts'] as $part ) {
            $this->assertFileExists( $this->runner->scratch . '/' . $part['file'] );
            $this->assertSame( $state['layout_descriptor_sha256'], $part['layout_descriptor_sha256'] );
        }
        $this->assertSame( array(), glob( $this->runner->scratch . '/mwp-page-*' ) );
    }

    public function test_assembled_storage_root_outside_capture_is_a_known_exclusion() {
        $this->assertTrue( method_exists( $this->runner, 'allows_v2_capture' ), 'Root-aware task capture is unavailable.' );
        mkdir( $this->dir . '/storage' );
        $filter = function ( $upload ) {
            $upload['basedir'] = $this->dir . '/storage';
            return $upload;
        };
        add_filter( 'mainwp_child_get_wp_upload_dir', $filter );
        try {
            $state = $this->complete( $this->task(), array( 'params' => array( 'excludes' => array( $this->dir . '/storage/mainwp' ) ) ) );
            $this->assertContains( 'roots/uploads/mainwp', $state['v2_walk']['excludes'] );
        } finally {
            remove_filter( 'mainwp_child_get_wp_upload_dir', $filter );
        }
    }

    public function test_pack_omission_status_paths_keep_the_existing_cap() {
        $this->assertTrue( method_exists( $this->runner, 'allows_v2_capture' ), 'Root-aware task capture is unavailable.' );
        $paths = array();
        for ( $index = 0; $index < 101; ++$index ) {
            $path = $this->dir . '/content/vanishing-' . $index . '.txt';
            file_put_contents( $path, 'temporary content' );
            $paths[] = $path;
        }
        $this->runner->before_pack = static function () use ( $paths ) {
            foreach ( $paths as $path ) {
                unlink( $path );
            }
        };
        $state = $this->complete( $this->task() );
        $this->assertSame( 101, $state['v2_walk']['skipped_count'] );
        $this->assertCount( 100, $state['v2_walk']['skipped_paths'] );
        $this->assertCount( 101, $state['v2_walk']['unarchived'] );
        $this->assertCount( 100, $state['_skip_report']['skipped_paths'] );
    }

    public function test_resume_refuses_changed_root_before_more_writes() {
        $this->assertTrue( method_exists( $this->runner, 'allows_v2_capture' ), 'Root-aware task capture is unavailable.' );
        $this->runner->slice( $this->task(), array( 'params' => array() ) );
        $state = $this->runner->saved;
        mkdir( $this->dir . '/replacement' );
        $this->runner->roots['content'] = $this->dir . '/replacement';
        try {
            $this->runner->slice( $this->task(), $state );
            $this->fail( 'Task rebound a captured root.' );
        } catch ( MainWP_Exception $error ) {
            $this->assertSame( MainWP_Backup_Error::ERR_LAYOUT_CHANGED, $error->get_message_error_code() );
        }
        $this->assertSame( array(), glob( $this->runner->scratch . '/*.mwp2*' ) );
    }

    public function test_tar_disabled_refuses_file_capture_before_writes() {
        $this->assertTrue( method_exists( $this->runner, 'allows_v2_capture' ), 'Root-aware task capture is unavailable.' );
        $this->runner->tar_available = false;
        $this->expectException( MainWP_Exception::class );
        $this->runner->slice( $this->task(), array( 'params' => array() ) );
    }
}
