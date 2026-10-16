<?php
/**
 * Class BackupTaskSidecarTest
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Child_Backup_Task;

/**
 * Tests for the sub_state sidecar spill.
 *
 * A resume cursor that outgrows the safe inline size (staying well under
 * max_allowed_packet) is written to a sidecar file in the backup directory
 * via temp-file-then-rename, and the column stores only a pointer. Reads
 * resolve the pointer transparently.
 */
class BackupTaskSidecarTest extends WP_UnitTestCase {

	public function test_sidecar_pointer_retains_only_valid_artifact_digests() {
		$spine = MainWP_Child_Backup_Task::instance();
		$id = $spine->create_task( 'full' );
		$sub = $this->oversized_sub_state();
		$full_hash = str_repeat( 'a', 64 );
		$db_hash = str_repeat( 'b', 64 );
		$sub['artifact'] = array( 'file' => '/private/backups/full.zip', 'size' => 100, 'sha256' => $full_hash );
		$sub['artifacts'] = array(
			array( 'file' => 'full.zip', 'size' => 100, 'role' => 'full', 'sha256' => $full_hash ),
			array( 'file' => 'database.zip', 'sha256' => $db_hash ),
			array( 'file' => 'invalid.zip', 'sha256' => str_repeat( 'g', 64 ) ),
			array( 'file' => 'short.zip', 'sha256' => 'abc' ),
			array( 'file' => 'unhashed.zip', 'hash_skipped' => 'too_large' ),
		);
		$serialized = $spine->write_sub_state( $id, $sub, $this->backup_dir, 0 );
		$pointer = json_decode( $serialized, true );
		$this->assertSame( array( '_sidecar', 'file', 'artifact_sha256' ), array_keys( $pointer ) );
		$this->assertTrue( $pointer['_sidecar'] );
		$this->assertSame( array( 'full.zip' => $full_hash, 'database.zip' => $db_hash ), $pointer['artifact_sha256'] );
		$this->assertNotFalse( $spine->checkpoint( $id, 0, array( 'sub_state' => $serialized ) ) );
		$this->assertSame( $sub, $spine->read_sub_state( $spine->get_task( $id ), $this->backup_dir ) );
		unlink( $this->backup_dir . '/' . $pointer['file'] );
		$this->assertNull( $spine->read_sub_state( $spine->get_task( $id ), $this->backup_dir ) );
		$this->assertSame( $pointer['artifact_sha256'], json_decode( $spine->get_task( $id )->sub_state, true )['artifact_sha256'] );
	}

	/**
	 * Scratch dir standing in for the randomized backup dir.
	 *
	 * @var string
	 */
	protected $backup_dir;

	/**
	 * Install the task table and a scratch backup dir.
	 */
	public function set_up() {
		parent::set_up();
		MainWP_Child_Backup_Task::instance()->install();
		$this->backup_dir = sys_get_temp_dir() . '/mainwp-sidecar-test-' . uniqid();
		mkdir( $this->backup_dir );
	}

	/**
	 * Drop spine rows and the scratch dir between tests.
	 */
	public function tear_down() {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . MainWP_Child_Backup_Task::instance()->get_table_name() ); // phpcs:ignore -- test cleanup.
		foreach ( (array) glob( $this->backup_dir . '/*' ) as $file ) {
			unlink( $file );
		}
		if ( is_dir( $this->backup_dir ) ) {
			rmdir( $this->backup_dir );
		}
		parent::tear_down();
	}

	/**
	 * Build a cursor bigger than the inline threshold.
	 *
	 * @return array Oversized sub_state.
	 */
	protected function oversized_sub_state() {
		return array( 'files' => array_fill( 0, 4000, str_repeat( 'x', 20 ) ) );
	}

	/**
	 * A small cursor stays inline in the column - no sidecar file.
	 */
	public function test_small_sub_state_stored_inline() {
		$spine     = MainWP_Child_Backup_Task::instance();
		$task_id   = $spine->create_task( 'full' );
		$sub_state = array( 'cursor' => 42 );

		$version    = (int) $spine->get_task( $task_id )->version;
		$serialized = $spine->write_sub_state( $task_id, $sub_state, $this->backup_dir, $version );
		$this->assertSame( wp_json_encode( $sub_state ), $serialized );
		$this->assertCount( 0, (array) glob( $this->backup_dir . '/*' ), 'No sidecar file for a small cursor' );

		$task = $spine->get_task( $task_id );
		$spine->checkpoint( $task_id, (int) $task->version, array( 'sub_state' => $serialized ) );

		$task = $spine->get_task( $task_id );
		$this->assertSame( $sub_state, $spine->read_sub_state( $task, $this->backup_dir ) );
	}

	/**
	 * An oversized cursor spills to a per-task sidecar file and the column
	 * holds only the pointer.
	 */
	public function test_large_sub_state_spills_to_sidecar() {
		$spine     = MainWP_Child_Backup_Task::instance();
		$task_id   = $spine->create_task( 'full' );
		$sub_state = $this->oversized_sub_state();

		$version    = (int) $spine->get_task( $task_id )->version;
		$serialized = $spine->write_sub_state( $task_id, $sub_state, $this->backup_dir, $version );

		$pointer = json_decode( $serialized, true );
		$this->assertTrue( $pointer['_sidecar'] );
		$this->assertSame( 'task_substate-' . $task_id . '-v' . $version . '.json', $pointer['file'] );
		$this->assertLessThan( 1024, strlen( $serialized ), 'Column value must be a small pointer' );

		$sidecar = $this->backup_dir . '/' . $pointer['file'];
		$this->assertFileExists( $sidecar );
		$this->assertSame( $sub_state, json_decode( (string) file_get_contents( $sidecar ), true ) ); // phpcs:ignore -- test read.
		$this->assertCount( 0, (array) glob( $this->backup_dir . '/*.tmp' ), 'Temp file must be renamed away' );
	}

	/**
	 * Reading a row whose column holds a pointer returns the full cursor.
	 */
	public function test_sidecar_read_is_transparent() {
		$spine     = MainWP_Child_Backup_Task::instance();
		$task_id   = $spine->create_task( 'full' );
		$sub_state = $this->oversized_sub_state();

		$version    = (int) $spine->get_task( $task_id )->version;
		$serialized = $spine->write_sub_state( $task_id, $sub_state, $this->backup_dir, $version );
		$task       = $spine->get_task( $task_id );
		$spine->checkpoint( $task_id, (int) $task->version, array( 'sub_state' => $serialized ) );

		$task = $spine->get_task( $task_id );
		$this->assertSame( $sub_state, $spine->read_sub_state( $task, $this->backup_dir ) );
	}

	/**
	 * A missing sidecar file reads as null so the caller restarts cleanly
	 * instead of operating on a partial cursor.
	 */
	public function test_missing_sidecar_reads_as_null() {
		$spine   = MainWP_Child_Backup_Task::instance();
		$task_id = $spine->create_task( 'full' );

		$version    = (int) $spine->get_task( $task_id )->version;
		$serialized = $spine->write_sub_state( $task_id, $this->oversized_sub_state(), $this->backup_dir, $version );
		$task       = $spine->get_task( $task_id );
		$spine->checkpoint( $task_id, (int) $task->version, array( 'sub_state' => $serialized ) );

		// Remove the sidecar the column pointer references.
		$pointer = json_decode( $serialized, true );
		unlink( $this->backup_dir . '/' . $pointer['file'] );

		$task = $spine->get_task( $task_id );
		$this->assertNull( $spine->read_sub_state( $task, $this->backup_dir ) );
	}

	/**
	 * delete_sidecar removes the spill file; terminal transitions use it so
	 * completed and failed tasks leave no cursor litter behind.
	 */
	public function test_delete_sidecar_removes_spill_file() {
		$spine   = MainWP_Child_Backup_Task::instance();
		$task_id = $spine->create_task( 'full' );

		$version    = (int) $spine->get_task( $task_id )->version;
		$serialized = $spine->write_sub_state( $task_id, $this->oversized_sub_state(), $this->backup_dir, $version );
		$sidecar    = $this->backup_dir . '/' . json_decode( $serialized, true )['file'];
		$this->assertFileExists( $sidecar );

		$spine->delete_sidecar( $task_id, $this->backup_dir );
		$this->assertFileDoesNotExist( $sidecar );

		// Deleting again is a quiet no-op.
		$spine->delete_sidecar( $task_id, $this->backup_dir );
	}

	/**
	 * Superseded sidecars are not pruned in-flight (a pre-CAS delete could drop
	 * a file the row still references), so a spilling backup can leave several
	 * version-stamped sidecars. delete_sidecar sweeps every one at terminal, so
	 * nothing accumulates past the task.
	 */
	public function test_delete_sidecar_sweeps_all_accumulated_versions() {
		$spine   = MainWP_Child_Backup_Task::instance();
		$task_id = $spine->create_task( 'full' );

		foreach ( array( 1, 2, 3 ) as $v ) {
			$spine->write_sub_state( $task_id, $this->oversized_sub_state(), $this->backup_dir, $v );
		}
		$pattern = $this->backup_dir . '/task_substate-' . $task_id . '-v*.json';
		$this->assertCount( 3, (array) glob( $pattern ), 'Three accumulated versions on disk (no in-flight prune)' );

		$spine->delete_sidecar( $task_id, $this->backup_dir );
		$this->assertCount( 0, (array) glob( $pattern ), 'delete_sidecar sweeps every accumulated version at terminal' );
	}

	/**
	 * checkpoint() still accepts an array sub_state - existing callers like
	 * the watchdog do not pre-serialize.
	 */
	public function test_checkpoint_array_sub_state_back_compat() {
		$spine   = MainWP_Child_Backup_Task::instance();
		$task_id = $spine->create_task( 'full' );

		$task = $spine->get_task( $task_id );
		$spine->checkpoint( $task_id, (int) $task->version, array( 'sub_state' => array( 'cursor' => 7 ) ) );

		$task = $spine->get_task( $task_id );
		$this->assertSame( array( 'cursor' => 7 ), $spine->read_sub_state( $task, $this->backup_dir ) );
	}

	/**
	 * A slice that outran its lease must not clobber the cursor body a second
	 * slice already committed. The two slices own distinct versions (lease
	 * theft increments the version), so the sidecar filename is stamped with
	 * the owned version and the stale write lands in a different file. The
	 * winning row keeps pointing at the winner's intact body.
	 */
	public function test_stale_slice_does_not_clobber_winning_cursor() {
		$spine   = MainWP_Child_Backup_Task::instance();
		$task_id = $spine->create_task( 'full' );

		$task = $spine->get_task( $task_id );
		$base = (int) $task->version;

		// The first slice takes the lease (version base -> base+1) but its
		// lease is already expired, standing in for a slice that outran it.
		$this->assertTrue( $spine->acquire_lease( $task_id, 'stale', $base, -5 ) );
		// A second slice steals the expired lease (version base+1 -> base+2).
		$this->assertTrue( $spine->acquire_lease( $task_id, 'winner', $base + 1, 120 ) );

		$stale_version  = $base + 1;
		$winner_version = $base + 2;

		$winner_body        = $this->oversized_sub_state();
		$winner_body['who'] = 'winner';
		$stale_body         = $this->oversized_sub_state();
		$stale_body['who']  = 'stale';

		// The winner (owns base+2) writes its sidecar and wins the CAS.
		$winner_ptr = $spine->write_sub_state( $task_id, $winner_body, $this->backup_dir, $winner_version );
		$this->assertNotFalse( $spine->checkpoint( $task_id, $winner_version, array( 'sub_state' => $winner_ptr ) ) );

		// The zombie stale slice (still owns base+1) writes last, then fails CAS.
		$stale_ptr = $spine->write_sub_state( $task_id, $stale_body, $this->backup_dir, $stale_version );
		$this->assertFalse( $spine->checkpoint( $task_id, $stale_version, array( 'sub_state' => $stale_ptr ) ) );

		// The committed row must still resolve to the winner's cursor body.
		$task     = $spine->get_task( $task_id );
		$resolved = $spine->read_sub_state( $task, $this->backup_dir );
		$this->assertSame( 'winner', $resolved['who'] );
	}
}
