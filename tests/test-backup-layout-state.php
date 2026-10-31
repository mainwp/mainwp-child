<?php
/**
 * Private versioned state and read-only manifest fixtures.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Child_Backup_Layout;
use MainWP\Child\MainWP_Child_Backup_Layout_List;
use MainWP\Child\MainWP_Child_Backup_Layout_State;
use MainWP\Child\MainWP_Child_Backup_Reconciler;

class BackupLayoutStateTest extends WP_UnitTestCase {
	public function test_walker_state_round_trip_and_digest_binding() {
		$state = array( 'format' => 2, 'root_index' => 1, 'layout_descriptor_sha256' => str_repeat( 'a', 64 ), 'cursor' => array( 'phase' => 'enumerate' ) );
		$bytes = MainWP_Child_Backup_Layout_State::encode( $state );
		$this->assertSame( $state, MainWP_Child_Backup_Layout_State::decode( $bytes, str_repeat( 'a', 64 ) ) );
		$this->expectException( \MainWP\Child\MainWP_Exception::class );
		MainWP_Child_Backup_Layout_State::decode( $bytes, str_repeat( 'b', 64 ) );
	}

	public function test_restore_state_preserves_private_arrays_without_rebinding() {
		$state = array( 'format' => 2, 'layout_descriptor_sha256' => str_repeat( 'a', 64 ), 'destination_roots' => array( 'content' => '/fixture/content', 'uploads' => '/fixture/uploads' ), 'destination_upload_options' => array( 'upload_path' => false, 'upload_url_path' => '' ), 'rollback_manifest' => array( 'files' => array( 'roots/uploads/new.txt' ) ) );
		$this->assertSame( $state, MainWP_Child_Backup_Layout_State::decode( MainWP_Child_Backup_Layout_State::encode( $state ), str_repeat( 'a', 64 ) ) );
	}

	public static function invalid_states() {
		$base = array( 'format' => 2, 'root_index' => 0, 'layout_descriptor_sha256' => str_repeat( 'a', 64 ) );
		return array(
			array( array_merge( $base, array( 'format' => 3 ) ) ),
			array( array_merge( $base, array( 'root_index' => -1 ) ) ),
			array( array_merge( $base, array( 'root_index' => '0' ) ) ),
			array( array_merge( $base, array( 'root_index' => 7 ) ) ),
			array( array_merge( $base, array( 'layout_descriptor_sha256' => 'bad' ) ) ),
			array( array( 'format' => 2, 'layout_descriptor_sha256' => str_repeat( 'a', 64 ), 'destination_roots' => array( 'alien' => '/fixture' ), 'destination_upload_options' => array(), 'rollback_manifest' => array() ) ),
			array( array( 'format' => 2, 'layout_descriptor_sha256' => str_repeat( 'a', 64 ), 'destination_roots' => array( 'uploads' => "/fixture/nu\0l" ), 'destination_upload_options' => array(), 'rollback_manifest' => array() ) ),
			array( array( 'format' => 2, 'layout_descriptor_sha256' => str_repeat( 'a', 64 ), 'destination_roots' => array( 'uploads' => 'relative/path' ), 'destination_upload_options' => array(), 'rollback_manifest' => array() ) ),
			array( array( 'format' => 2, 'layout_descriptor_sha256' => str_repeat( 'a', 64 ), 'destination_roots' => array( 'uploads' => '/fixture/../outside' ), 'destination_upload_options' => array(), 'rollback_manifest' => array() ) ),
			array( array( 'format' => 2, 'layout_descriptor_sha256' => str_repeat( 'a', 64 ) ) ),
			array( array( 'format' => 2, 'layout_descriptor_sha256' => str_repeat( 'a', 64 ), 'destination_roots' => array( 'uploads' => '/fixture' ), 'destination_upload_options' => array() ) ),
			array( array( 'format' => 2, 'layout_descriptor_sha256' => str_repeat( 'a', 64 ), 'destination_roots' => array( 'uploads' => '/fixture' ), 'destination_upload_options' => array( 'alien' => '' ), 'rollback_manifest' => array() ) ),
			array( array( 'format' => 2, 'layout_descriptor_sha256' => str_repeat( 'a', 64 ), 'destination_roots' => array( 'uploads' => '/fixture' ), 'destination_upload_options' => array( 'upload_path' => array() ), 'rollback_manifest' => array() ) ),
			array( array_merge( $base, array( 'cursor' => new stdClass() ) ) ),
			array( array_merge( $base, array( 'cursor' => INF ) ) ),

		);
	}

	/** @dataProvider invalid_states */
	public function test_invalid_state_is_refused( $state ) {
		$this->expectException( \MainWP\Child\MainWP_Exception::class );
		MainWP_Child_Backup_Layout_State::encode( $state );
	}

	public function test_reconciler_reads_explicit_qualified_namespace_only() {
		$path = tempnam( sys_get_temp_dir(), 'mwp-state-' );
		$descriptor = MainWP_Child_Backup_Layout::descriptor( array( array( 'role' => 'plugins', 'coverage' => 'restore' ), array( 'role' => 'uploads', 'coverage' => 'restore' ) ), str_repeat( 'a', 64 ) );
		$rows = array( 'roots/plugins/same' => array( 1, 2 ), 'roots/uploads/same' => array( 3, 4 ) );
		try {
			( new MainWP_Child_Backup_Layout_List() )->write( $path, MainWP_Child_Backup_Layout::digest( $descriptor ), $rows );
			$reconciler = new MainWP_Child_Backup_Reconciler();
			$this->assertSame( $rows, $reconciler->read_layout_manifest( $path, $descriptor ) );
			$this->assertFalse( $reconciler->load_parent_map( $path )['valid'] );
		} finally {
			unlink( $path );
		}
	}
	public function test_corrupt_private_state_json_is_refused() {
		$code = null;
		try {
			MainWP_Child_Backup_Layout_State::decode( '{bad', str_repeat( 'a', 64 ) );
		} catch ( \MainWP\Child\MainWP_Exception $e ) {
			$code = $e->get_message_error_code();
		}
		$this->assertSame( 'mainwp_backup_file_corrupt', $code );
	}

	public function test_reconciler_refuses_an_undeclared_role() {
		$path = tempnam( sys_get_temp_dir(), 'mwp-state-' );
		$descriptor = MainWP_Child_Backup_Layout::descriptor( array( array( 'role' => 'uploads', 'coverage' => 'restore' ) ), str_repeat( 'a', 64 ) );
		$code = null;
		try {
			( new MainWP_Child_Backup_Layout_List() )->write( $path, MainWP_Child_Backup_Layout::digest( $descriptor ), array( 'roots/plugins/same' => array( 1, 2 ) ) );
			try {
				( new MainWP_Child_Backup_Reconciler() )->read_layout_manifest( $path, $descriptor );
			} catch ( \MainWP\Child\MainWP_Exception $e ) {
				$code = $e->get_message_error_code();
			}
			$this->assertSame( 'mainwp_backup_file_corrupt', $code );
		} finally {
			unlink( $path );
		}
	}

}
