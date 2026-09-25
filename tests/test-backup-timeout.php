<?php
/**
 * Class BackupTimeoutTest
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Backup;

/**
 * Tests for configurable backup timeout presets.
 */
class BackupTimeoutTest extends WP_UnitTestCase {

	/**
	 * Clear the request between tests.
	 */
	public function tear_down() {
		unset( $_POST['timeout_preset'] );
		parent::tear_down();
	}

	/**
	 * Each preset maps to its documented duration.
	 */
	public function test_preset_durations() {
		$this->assertSame( 30 * 60, MainWP_Backup::get_timeout_for_preset( 'shared_hosting' ) );
		$this->assertSame( 2 * 60 * 60, MainWP_Backup::get_timeout_for_preset( 'standard' ) );
		$this->assertSame( 8 * 60 * 60, MainWP_Backup::get_timeout_for_preset( 'large_site' ) );
		$this->assertSame( 20 * 60 * 60, MainWP_Backup::get_timeout_for_preset( 'unlimited' ) );
	}

	/**
	 * Unknown presets fall back to the legacy 20-hour timeout.
	 */
	public function test_unknown_preset_falls_back_to_legacy() {
		$this->assertSame( 20 * 60 * 60, MainWP_Backup::get_timeout_for_preset( 'bogus' ) );
		$this->assertSame( 20 * 60 * 60, MainWP_Backup::get_timeout_for_preset( '' ) );
	}

	/**
	 * The request value selects the preset; its absence keeps legacy behavior
	 * so older Dashboards see no change.
	 */
	public function test_request_selects_preset_with_legacy_default() {
		unset( $_POST['timeout_preset'] );
		$this->assertSame( 20 * 60 * 60, MainWP_Backup::get()->get_timeout_from_request() );

		$_POST['timeout_preset'] = 'shared_hosting';
		$this->assertSame( 30 * 60, MainWP_Backup::get()->get_timeout_from_request() );

		$_POST['timeout_preset'] = 'large_site';
		$this->assertSame( 8 * 60 * 60, MainWP_Backup::get()->get_timeout_from_request() );
	}
}
