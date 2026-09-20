<?php
/**
 * Backup engine ownership tests.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Child_Backup_Mutex;

class BackupMutexTest extends WP_UnitTestCase {

	public function tear_down() {
		$holder = MainWP_Child_Backup_Mutex::holder();
		if ( null !== $holder ) {
			MainWP_Child_Backup_Mutex::release( $holder['owner'] );
		}
		parent::tear_down();
	}

	public function test_two_acquirers_exactly_one_wins() {
		$first  = MainWP_Child_Backup_Mutex::acquire( 'legacy', 'first', 300 );
		$second = MainWP_Child_Backup_Mutex::acquire( 'native', 'second', 3600 );
		$this->assertTrue( $first );
		$this->assertIsArray( $second );
		$this->assertSame( 'first', $second['owner'] );
	}

	public function test_only_holder_can_refresh_including_in_the_same_second() {
		$this->assertTrue( MainWP_Child_Backup_Mutex::acquire( 'native', 'holder', 300 ) );
		$before = MainWP_Child_Backup_Mutex::holder();
		$this->assertTrue( MainWP_Child_Backup_Mutex::refresh( 'holder', 300 ) );
		$this->assertGreaterThan( $before['expires_at'], MainWP_Child_Backup_Mutex::holder()['expires_at'] );
		$this->assertFalse( MainWP_Child_Backup_Mutex::refresh( 'stranger', 300 ) );
	}

	public function test_expired_holder_loses_ownership_after_takeover() {
		$this->assertTrue( MainWP_Child_Backup_Mutex::acquire( 'legacy', 'old', -1 ) );
		$this->assertTrue( MainWP_Child_Backup_Mutex::acquire( 'native', 'new', 3600 ) );
		$this->assertFalse( MainWP_Child_Backup_Mutex::refresh( 'old', 300 ) );
		$this->assertFalse( MainWP_Child_Backup_Mutex::release( 'old' ) );
		$this->assertSame( 'new', MainWP_Child_Backup_Mutex::holder()['owner'] );
	}

	public function test_release_deletes_only_the_owner_row() {
		add_option( 'mainwp_mutex_unrelated', 'legacy|holder|1', '', false );
		$this->assertTrue( MainWP_Child_Backup_Mutex::acquire( 'legacy', 'holder', 300 ) );
		$this->assertTrue( MainWP_Child_Backup_Mutex::release( 'holder' ) );
		$this->assertNull( MainWP_Child_Backup_Mutex::holder() );
		$this->assertSame( 'legacy|holder|1', get_option( 'mainwp_mutex_unrelated' ) );
	}

	public function test_release_by_stranger_leaves_the_row() {
		$this->assertTrue( MainWP_Child_Backup_Mutex::acquire( 'legacy', 'holder', 300 ) );
		foreach ( array( 'stranger', 'hold%', 'hol_er', 'HOLDER', '' ) as $stranger ) {
			$this->assertFalse( MainWP_Child_Backup_Mutex::release( $stranger ), $stranger );
			$this->assertSame( 'holder', MainWP_Child_Backup_Mutex::holder()['owner'] );
		}
	}

	public function test_holder_reports_engine_owner_and_bypasses_stale_cache() {
		$this->assertNull( MainWP_Child_Backup_Mutex::holder() );
		$this->assertTrue( MainWP_Child_Backup_Mutex::acquire( 'native', 'task-123', 3600 ) );
		wp_cache_set( MainWP_Child_Backup_Mutex::OPTION_NAME, 'legacy|stale|1', 'options' );
		$holder = MainWP_Child_Backup_Mutex::holder();
		$this->assertSame( 'native', $holder['engine'] );
		$this->assertSame( 'task-123', $holder['owner'] );
		$this->assertGreaterThanOrEqual( time(), $holder['expires_at'] );
		$this->assertTrue( MainWP_Child_Backup_Mutex::refresh( 'task-123', 3600 ) );
		$this->assertSame( 'native|task-123|' . MainWP_Child_Backup_Mutex::holder()['expires_at'], get_option( MainWP_Child_Backup_Mutex::OPTION_NAME ) );
	}
}
