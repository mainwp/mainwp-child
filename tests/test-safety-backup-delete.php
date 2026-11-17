<?php
/**
 * Safety archive removal preserves other backup files.
 *
 * @package MainWP_Child
 */
namespace MainWP\Child;

class SafetyBackupDeleteTest extends \WP_UnitTestCase {
    private $dir;
    private $spine;
    private $binding;

    public function set_up() {
        parent::set_up();
        $this->spine = MainWP_Child_Backup_Task::instance();
        $this->spine->install();
        delete_option( MainWP_Child_Backup_Mutex::OPTION_NAME );
        $this->dir = sys_get_temp_dir() . '/safety-delete-' . wp_generate_uuid4() . '/';
        wp_mkdir_p( $this->dir );
        file_put_contents( $this->dir . 'safety.zip', 'archive' );
        foreach ( array( 'files.list', 'files.meta', 'tombstones.list', 'ordinary.zip' ) as $name ) {
            file_put_contents( $this->dir . $name, 'keep' );
        }
        $state = array( 'params' => array( 'safety_restore_id' => '91' ), 'artifact' => array( 'file' => 'safety.zip', 'size' => 7, 'mtime' => filemtime( $this->dir . 'safety.zip' ), 'safety_restore_id' => '91' ) );
        $id = $this->spine->create_task( 'full', $state );
        $row = $this->spine->get_task( $id );
        $this->assertNotFalse( $this->spine->checkpoint( $id, (int) $row->version, array( 'phase' => 'completed' ), true, $this->dir ) );
        $this->binding = array( 'task_id' => $id, 'restore_id' => '91', 'file' => 'safety.zip', 'size' => 7, 'mtime' => filemtime( $this->dir . 'safety.zip' ), 'task_started_at' => $row->started_at );
    }

    public function tear_down() {
        foreach ( glob( $this->dir . '*' ) as $file ) {
            unlink( $file );
        }
        rmdir( $this->dir );
        delete_option( MainWP_Child_Backup_Mutex::OPTION_NAME );
        parent::tear_down();
    }

    public function test_confirmed_delete_preserves_inventory_and_reconciles_lost_response() {
        $answer = $this->spine->safety_archive( $this->binding, true, $this->dir );
        $this->assertSame( 'removed', $answer['state'] );
        $this->assertFileDoesNotExist( $this->dir . 'safety.zip' );
        foreach ( array( 'files.list', 'files.meta', 'tombstones.list', 'ordinary.zip' ) as $name ) {
            $this->assertSame( 'keep', file_get_contents( $this->dir . $name ) );
        }
        $this->assertSame( 'removed', $this->spine->safety_archive( $this->binding, false, $this->dir )['state'] );
    }

    public function test_mismatched_ordinary_and_traversal_are_refused() {
        foreach ( array( 'ordinary.zip', '../safety.zip' ) as $file ) {
            $binding = $this->binding;
            $binding['file'] = $file;
            $this->assertArrayHasKey( 'error', $this->spine->safety_archive( $binding, true, $this->dir ) );
        }
        $binding = $this->binding;
        $binding['restore_id'] = '92';
        $this->assertArrayHasKey( 'error', $this->spine->safety_archive( $binding, true, $this->dir ) );
        $this->assertFileExists( $this->dir . 'safety.zip' );
    }

    public function test_active_restore_refuses_removal() {
        $this->spine->create_task( 'restore', array( 'params' => array( 'restore_id' => 'active' ) ) );
        $reply = $this->spine->safety_archive( $this->binding, true, $this->dir );
        $this->assertArrayHasKey( 'error', $reply );
        $this->assertSame( 'present', $reply['state'] );
        $this->assertSame( $this->binding['task_id'], $reply['task_id'] );
        $this->assertSame( $this->binding['file'], $reply['file'] );
        $this->assertFileExists( $this->dir . 'safety.zip' );
    }

    public function test_legacy_attestation_requires_matching_child_restore_record() {
        $task = $this->spine->get_task( $this->binding['task_id'] );
        $state = $this->spine->read_sub_state( $task, $this->dir );
        unset( $state['params']['safety_restore_id'], $state['artifact']['safety_restore_id'] );
        $this->assertNotFalse( $this->spine->checkpoint( $task->task_id, (int) $task->version, array( 'sub_state' => $state ), false, $this->dir ) );
        $this->assertArrayHasKey( 'error', $this->spine->safety_archive( $this->binding, true, $this->dir ) );
        $restore = $this->spine->create_task( 'restore', array( 'params' => array( 'restore_id' => 'historical-restore' ) ) );
        $row = $this->spine->get_task( $restore );
        $this->assertSame( 'historical-restore', $row->restore_id );
        $this->assertNotFalse( $this->spine->checkpoint( $restore, (int) $row->version, array( 'phase' => 'completed' ), true, $this->dir ) );
        $binding = $this->binding + array( 'legacy_attestation' => true, 'restore_task_id' => $restore, 'restore_job_uuid' => 'wrong-restore' );
        $this->assertArrayHasKey( 'error', $this->spine->safety_archive( $binding, true, $this->dir ) );
        $binding['restore_job_uuid'] = 'historical-restore';
        $binding['task_started_at'] = $task->started_at;
        touch( $this->dir . 'safety.zip', $this->binding['mtime'] - 60 );
        $this->assertArrayHasKey( 'error', $this->spine->safety_archive( $binding, false, $this->dir ) );
        touch( $this->dir . 'safety.zip', $this->binding['mtime'] );
        $this->assertSame( 'removed', $this->spine->safety_archive( $binding, true, $this->dir )['state'] );
        global $wpdb;
        $this->assertSame( 1, $wpdb->delete( $this->spine->get_table_name(), array( 'task_id' => $restore ) ) );
        $this->assertSame( 'removed', $this->spine->safety_archive( $binding, false, $this->dir )['state'] );
        $mismatch = $binding;
        $mismatch['restore_job_uuid'] = 'different-restore';
        $this->assertArrayHasKey( 'error', $this->spine->safety_archive( $mismatch, false, $this->dir ) );
        file_put_contents( $this->dir . 'safety.zip', 'archive' );
        touch( $this->dir . 'safety.zip', $binding['mtime'] );
        $this->assertArrayHasKey( 'error', $this->spine->safety_archive( $binding, true, $this->dir ) );
        $this->assertFileExists( $this->dir . 'safety.zip' );
    }

    public function test_exact_timestamp_and_symlink_are_required() {
        $binding = $this->binding;
        ++$binding['mtime'];
        $this->assertArrayHasKey( 'error', $this->spine->safety_archive( $binding, true, $this->dir ) );
        touch( $this->dir . 'safety.zip', $this->binding['mtime'] + 60 );
        $this->assertArrayHasKey( 'error', $this->spine->safety_archive( $this->binding, false, $this->dir ) );
        unlink( $this->dir . 'safety.zip' );
        symlink( $this->dir . 'ordinary.zip', $this->dir . 'safety.zip' );
        $this->assertArrayHasKey( 'error', $this->spine->safety_archive( $this->binding, true, $this->dir ) );
        $this->assertSame( 'keep', file_get_contents( $this->dir . 'ordinary.zip' ) );
    }

    public function test_failed_rollback_source_is_preserved() {
        $restore = $this->spine->create_task( 'restore', array( 'params' => array( 'restore_id' => 'failed-rollback', 'artifacts' => array( array( 'file' => 'safety.zip' ) ) ) ) );
        $row = $this->spine->get_task( $restore );
        $this->assertNotFalse( $this->spine->checkpoint( $restore, (int) $row->version, array( 'phase' => 'failed' ), true, $this->dir ) );
        $this->assertArrayHasKey( 'error', $this->spine->safety_archive( $this->binding, true, $this->dir ) );
        $this->assertArrayHasKey( 'error', $this->spine->safety_archive( $this->binding, false, $this->dir ) );
        $this->assertFileExists( $this->dir . 'safety.zip' );
    }

    public function test_expired_restore_preserves_its_recovery_artifact_claim() {
        global $wpdb;
        $params = array( 'restore_id' => 'expired-safety-rollback', 'artifacts' => array( array( 'file' => 'safety.zip' ) ), 'pull_manifest' => array( 'ephemeral' => 'test-only' ) );
        $restore = $this->spine->create_task( 'restore', array( 'params' => $params ) );
        $this->assertSame( 1, $wpdb->query( $wpdb->prepare( 'UPDATE %i SET max_wall_clock_at = DATE_SUB(NOW(), INTERVAL 60 SECOND) WHERE task_id = %s', $this->spine->get_table_name(), $restore ) ) );
        $this->assertTrue( $this->spine->enforce_wall_clock_deadline( $restore ) );
        $failed = $this->spine->get_task( $restore );
        $state = $this->spine->read_sub_state( $failed, $this->dir );
        unset( $params['pull_manifest'] );
        $this->assertSame( $params, $state['params'] ?? null );
        $this->assertArrayHasKey( 'error', $this->spine->safety_archive( $this->binding, true, $this->dir ) );
        $this->assertFileExists( $this->dir . 'safety.zip' );
    }

    public function test_failed_restore_with_missing_recovery_metadata_refuses_removal() {
        $restore = $this->spine->create_task( 'restore', array( 'params' => array( 'restore_id' => 'legacy-failed-rollback' ) ) );
        $row = $this->spine->get_task( $restore );
        $this->assertNotFalse( $this->spine->checkpoint( $restore, (int) $row->version, array( 'phase' => 'failed', 'sub_state' => array( 'error' => array( 'code' => 'expired' ) ) ), true, $this->dir ) );
        $this->assertArrayHasKey( 'error', $this->spine->safety_archive( $this->binding, true, $this->dir ) );
        $this->assertFileExists( $this->dir . 'safety.zip' );
    }

    public function test_cancel_tombstone_does_not_claim_a_safety_archive() {
        $id = $this->spine->insert_restore_tombstone( 'cancel-before-start' );
        $this->assertIsString( $id );
        $this->assertTrue( $this->spine->is_restore_tombstone( $this->spine->get_task( $id ), $this->dir ) );
        $this->assertSame( 'removed', $this->spine->safety_archive( $this->binding, true, $this->dir )['state'] );
    }

    public function test_error_only_terminal_checkpoint_preserves_source_claims_without_credentials() {
        foreach ( array( 'ordinary.zip', 'safety.zip' ) as $file ) {
            $params = array( 'restore_id' => 'failed-' . $file, 'artifacts' => array( array( 'file' => $file ) ), 'pull_manifest' => array( 'ephemeral' => 'test-only' ) );
            $id = $this->spine->create_task( 'restore', array( 'params' => $params ) );
            $task = $this->spine->get_task( $id );
            $this->assertNotFalse( $this->spine->checkpoint( $id, (int) $task->version, array( 'phase' => 'failed', 'sub_state' => array( 'error' => array( 'code' => 'slice_failure' ) ) ), true, $this->dir ) );
            $state = $this->spine->read_sub_state( $this->spine->get_task( $id ), $this->dir );
            unset( $params['pull_manifest'] );
            $this->assertSame( $params, $state['params'] ?? null );
            $reply = $this->spine->safety_archive( $this->binding, false, $this->dir );
            if ( 'ordinary.zip' === $file ) {
                $this->assertSame( 'present', $reply['state'] );
            } else {
                $this->assertArrayHasKey( 'error', $reply );
            }
        }
        $this->assertFileExists( $this->dir . 'safety.zip' );
    }

    public function test_cancel_preserves_only_its_own_recovery_claim_without_pull_credentials() {
        foreach ( array( 'ordinary.zip', 'safety.zip' ) as $file ) {
            $params = array( 'restore_id' => 'cancelled-' . $file, 'artifacts' => array( array( 'file' => $file ) ), 'pull_manifest' => array( 'ephemeral' => 'test-only' ) );
            $id = $this->spine->create_task( 'restore', array( 'params' => $params ) );
            $reply = MainWP_Child_Restore_Task_Runner::instance()->cancel_restore_task( $id );
            $this->assertSame( 'ok', $reply['result'] );
            $task = $this->spine->get_task( $id );
            $this->assertSame( 'failed', $task->phase );
            $state = $this->spine->read_sub_state( $task, $this->dir );
            unset( $params['pull_manifest'] );
            $this->assertSame( $params, $state['params'] ?? null );
            $answer = $this->spine->safety_archive( $this->binding, false, $this->dir );
            if ( 'ordinary.zip' === $file ) {
                $this->assertSame( 'present', $answer['state'] );
            } else {
                $this->assertArrayHasKey( 'error', $answer );
            }
        }
        $this->assertFileExists( $this->dir . 'safety.zip' );
    }

    public function test_checkpoint_stamps_the_created_archive_with_safety_purpose_and_time() {
        $state = array( 'params' => array( 'safety_restore_id' => '91' ), 'artifact' => array( 'file' => 'safety.zip', 'size' => 7 ) );
        $serialized = $this->spine->write_sub_state( $this->binding['task_id'], $state, $this->dir, 1 );
        $this->assertIsString( $serialized );
        $recorded = json_decode( $serialized, true );
        $this->assertSame( '91', $recorded['params']['safety_restore_id'] );
        $this->assertSame( '91', $recorded['artifact']['safety_restore_id'] );
        $this->assertSame( filemtime( $this->dir . 'safety.zip' ), $recorded['artifact']['mtime'] );
    }

    public function test_reconciliation_refuses_a_replacement_after_the_recorded_delete() {
        $this->assertSame( 'removed', $this->spine->safety_archive( $this->binding, true, $this->dir )['state'] );
        file_put_contents( $this->dir . 'safety.zip', 'archive' );
        touch( $this->dir . 'safety.zip', $this->binding['mtime'] + 60 );
        $this->assertArrayHasKey( 'error', $this->spine->safety_archive( $this->binding, false, $this->dir ) );
        $this->assertFileExists( $this->dir . 'safety.zip' );
    }

    public function test_removed_receipt_requires_the_complete_saved_identity() {
        $this->assertSame( 'removed', $this->spine->safety_archive( $this->binding, true, $this->dir )['state'] );
        foreach ( array( 'mtime', 'task_started_at' ) as $field ) {
            $changed = $this->binding;
            $changed[ $field ] = 'mtime' === $field ? $changed[ $field ] + 1 : '2000-01-01 00:00:00';
            $this->assertArrayHasKey( 'error', $this->spine->safety_archive( $changed, false, $this->dir ) );
            unset( $changed[ $field ] );
            $this->assertArrayHasKey( 'error', $this->spine->safety_archive( $changed, false, $this->dir ) );
            $changed[ $field ] = null;
            $this->assertArrayHasKey( 'error', $this->spine->safety_archive( $changed, false, $this->dir ) );
        }
        $this->assertSame( 'removed', $this->spine->safety_archive( $this->binding, false, $this->dir )['state'] );
    }

    public function test_safety_metadata_survives_a_failed_sidecar_rename() {
        $task = $this->spine->get_task( $this->binding['task_id'] );
        $state = array( 'params' => array( 'safety_restore_id' => '91' ), 'artifact' => array( 'file' => 'safety.zip', 'size' => 7 ), 'padding' => str_repeat( 'x', 60000 ) );
        $sidecar = $this->dir . 'task_substate-' . $task->task_id . '-v' . $task->version . '.json';
        mkdir( $sidecar );
        $warnings = array();
        set_error_handler( static function ( $level, $message ) use ( &$warnings ) {
            if ( false !== strpos( $message, 'rename(' ) ) {
                $warnings[] = $message;
                return true;
            }
            return false;
        } );
        try {
            $serialized = $this->spine->write_sub_state( $task->task_id, $state, $this->dir, (int) $task->version );
        } finally {
            restore_error_handler();
            rmdir( $sidecar );
        }
        $this->assertNotEmpty( $warnings, 'The fixture must fail the real sidecar rename.' );
        if ( false === $serialized ) {
            $serialized = wp_json_encode( $state );
        }
        $this->assertNotFalse( $this->spine->checkpoint( $task->task_id, (int) $task->version, array( 'phase' => 'completed', 'sub_state' => $serialized ), true, $this->dir ) );
        $saved = $this->spine->read_sub_state( $this->spine->get_task( $task->task_id ), $this->dir );
        $this->assertSame( '91', $saved['artifact']['safety_restore_id'] ?? null );
        $this->assertSame( $this->binding['mtime'], $saved['artifact']['mtime'] ?? null );
        $this->assertSame( 'removed', $this->spine->safety_archive( $this->binding, true, $this->dir )['state'] );
    }
}
