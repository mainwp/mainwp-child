<?php
/**
 * Class ChildStatsSyncDataTest
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Child_Stats;

/**
 * Preserve Dashboard JSON values when reading and storing the sync list.
 */
class ChildStatsSyncDataTest extends WP_UnitTestCase {

	/**
	 * POST values to restore.
	 *
	 * @var array
	 */
	protected $post_backup;

	/**
	 * Start with no posted or stored sync list.
	 */
	public function set_up() {
		parent::set_up();
		$this->post_backup = $_POST;
		unset( $_POST['syncdata'] );
		delete_option( 'mainwp_child_settings_sync_data' );
	}

	/**
	 * Restore POST and remove the stored sync list.
	 */
	public function tear_down() {
		$_POST = $this->post_backup;
		delete_option( 'mainwp_child_settings_sync_data' );
		parent::tear_down();
	}

	/**
	 * JSON values retain tags, newlines and percent-encoded sequences.
	 */
	public function test_sync_data_preserves_json_values_and_stores_encoded_array() {
		$expected = array(
			'tag'     => '<b>Plugin updates</b>',
			'newline' => "first line\nsecond line",
			'percent' => 'keep%20this%3Csequence',
		);
		$_POST['syncdata'] = wp_slash( wp_json_encode( $expected ) );

		$this->assertSame( $expected, MainWP_Child_Stats::get_instance()->get_data_list_to_sync() );
		$this->assertSame( wp_json_encode( $expected ), get_option( 'mainwp_child_settings_sync_data' ) );
	}

	/**
	 * Invalid JSON clears a previously stored list to an encoded empty array.
	 */
	public function test_sync_data_invalid_json_returns_and_stores_empty_array() {
		update_option( 'mainwp_child_settings_sync_data', wp_json_encode( array( 'plugins' ) ) );
		$_POST['syncdata'] = wp_slash( 'not valid JSON' );

		$this->assertSame( array(), MainWP_Child_Stats::get_instance()->get_data_list_to_sync() );
		$this->assertSame( '[]', get_option( 'mainwp_child_settings_sync_data' ) );
	}

	/**
	 * An omitted syncdata field uses the previously stored list.
	 */
	public function test_sync_data_uses_stored_list_when_post_field_is_absent() {
		$expected = array( 'plugins', 'themes' );
		update_option( 'mainwp_child_settings_sync_data', wp_json_encode( $expected ) );

		$this->assertArrayNotHasKey( 'syncdata', $_POST );
		$this->assertSame( $expected, MainWP_Child_Stats::get_instance()->get_data_list_to_sync() );
		$this->assertSame( wp_json_encode( $expected ), get_option( 'mainwp_child_settings_sync_data' ) );
	}

	/**
	 * An array-valued syncdata post reaches json_decode() as a string, so the
	 * sync request does not fatal on PHP 8.
	 */
	public function test_sync_data_array_post_value_returns_empty_array() {
		update_option( 'mainwp_child_settings_sync_data', wp_json_encode( array( 'plugins' ) ) );
		$_POST['syncdata'] = array( 'plugins' => '1' );

		$this->assertSame( array(), MainWP_Child_Stats::get_instance()->get_data_list_to_sync() );
		$this->assertSame( '[]', get_option( 'mainwp_child_settings_sync_data' ) );
	}
}
