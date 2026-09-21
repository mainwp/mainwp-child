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
		delete_option( 'mainwp_mutex_unrelated' );
		parent::tear_down();
	}

	public function test_two_acquirers_exactly_one_wins() {
		$first  = MainWP_Child_Backup_Mutex::acquire( 'legacy', 'first', 300 );
		$second = MainWP_Child_Backup_Mutex::acquire( 'native', 'second', 3600 );
		$this->assertTrue( $first );
		$this->assertIsArray( $second );
		$this->assertSame( 'first', $second['owner'] );
	}

	public function test_insert_failure_returns_null_even_with_a_holder() {
		global $wpdb;
		$this->assertTrue( MainWP_Child_Backup_Mutex::acquire( 'legacy', 'holder', 300 ) );
		$filter = static function ( $query ) {
			if ( 0 === strpos( $query, 'INSERT IGNORE INTO' ) && false !== strpos( $query, MainWP_Child_Backup_Mutex::OPTION_NAME ) ) {
				return 'SELECT mainwp_mutex_forced_failure';
			}
			return $query;
		};
		$suppress = $wpdb->suppress_errors();
		add_filter( 'query', $filter );
		try {
			$this->assertNull( MainWP_Child_Backup_Mutex::acquire( 'native', 'new', 300 ) );
			$this->assertSame( 'holder', MainWP_Child_Backup_Mutex::holder()['owner'] );
		} finally {
			remove_filter( 'query', $filter );
			$wpdb->suppress_errors( $suppress );
		}
	}

	public function test_takeover_write_failure_returns_null() {
		global $wpdb;
		$this->assertTrue( MainWP_Child_Backup_Mutex::acquire( 'legacy', 'expired', -1 ) );
		$filter = static function ( $query ) use ( $wpdb ) {
			if ( 0 === strpos( $query, "UPDATE $wpdb->options SET option_value" ) && false !== strpos( $query, MainWP_Child_Backup_Mutex::OPTION_NAME ) ) {
				return 'SELECT mainwp_mutex_forced_failure';
			}
			return $query;
		};
		$suppress = $wpdb->suppress_errors();
		add_filter( 'query', $filter );
		try {
			$this->assertNull( MainWP_Child_Backup_Mutex::acquire( 'native', 'new', 300 ) );
			$this->assertSame( 'expired', MainWP_Child_Backup_Mutex::holder()['owner'] );
		} finally {
			remove_filter( 'query', $filter );
			$wpdb->suppress_errors( $suppress );
		}
	}

	public function test_vanished_holder_is_acquired_on_immediate_retry() {
		global $wpdb;
		$this->assertTrue( MainWP_Child_Backup_Mutex::acquire( 'legacy', 'vanishing', 300 ) );
		$deleted = false;
		$inserts = 0;
		$filter = static function ( $query ) use ( $wpdb, &$deleted, &$inserts ) {
			if ( false !== strpos( $query, MainWP_Child_Backup_Mutex::OPTION_NAME ) ) {
				if ( 0 === strpos( $query, 'INSERT IGNORE INTO' ) ) {
					++$inserts;
				}
				if ( ! $deleted && 0 === strpos( $query, "SELECT option_value FROM $wpdb->options" ) ) {
					$deleted = true;
					$wpdb->delete( $wpdb->options, array( 'option_name' => MainWP_Child_Backup_Mutex::OPTION_NAME ) );
				}
			}
			return $query;
		};
		add_filter( 'query', $filter );
		try {
			$this->assertTrue( MainWP_Child_Backup_Mutex::acquire( 'native', 'new', 300 ) );
			$this->assertTrue( $deleted );
			$this->assertSame( 2, $inserts );
			$this->assertSame( 'new', MainWP_Child_Backup_Mutex::holder()['owner'] );
		} finally {
			remove_filter( 'query', $filter );
		}
	}

	public function test_unreadable_holder_after_one_retry_returns_false() {
		$inserts = 0;
		$filter = static function ( $query ) use ( &$inserts ) {
			if ( 0 === strpos( $query, 'INSERT IGNORE INTO' ) && false !== strpos( $query, MainWP_Child_Backup_Mutex::OPTION_NAME ) ) {
				++$inserts;
				return 'SELECT 1 WHERE 1 = 0';
			}
			return $query;
		};
		$this->assertNull( MainWP_Child_Backup_Mutex::holder() );
		add_filter( 'query', $filter );
		try {
			$this->assertFalse( MainWP_Child_Backup_Mutex::acquire( 'native', 'new', 300 ) );
			$this->assertSame( 2, $inserts );
		} finally {
			remove_filter( 'query', $filter );
		}
	}

	public function test_unparseable_option_is_taken_over() {
		add_option( MainWP_Child_Backup_Mutex::OPTION_NAME, 'broken|value', '', false );
		try {
			$this->assertTrue( MainWP_Child_Backup_Mutex::acquire( 'native', 'new', 300 ) );
			$this->assertSame( 'new', MainWP_Child_Backup_Mutex::holder()['owner'] );
		} finally {
			delete_option( MainWP_Child_Backup_Mutex::OPTION_NAME );
		}
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

	public function test_takeover_loser_preserves_interleaved_winner() {
		global $wpdb;
		$this->assertTrue( MainWP_Child_Backup_Mutex::acquire( 'legacy', 'expired', -1 ) );
		$interleaved = false;
		$filter = function ( $query ) use ( $wpdb, &$interleaved ) {
			if ( ! $interleaved && 0 === strpos( $query, "UPDATE $wpdb->options SET option_value" ) && false !== strpos( $query, MainWP_Child_Backup_Mutex::OPTION_NAME ) ) {
				$interleaved = true;
				$this->assertTrue( MainWP_Child_Backup_Mutex::acquire( 'native', 'winner-b', 3600 ) );
			}
			return $query;
		};
		add_filter( 'query', $filter );
		try {
			$result = MainWP_Child_Backup_Mutex::acquire( 'legacy', 'loser-a', 300 );
			$this->assertTrue( $interleaved );
			$this->assertIsArray( $result );
			$this->assertSame( 'winner-b', $result['owner'] );
			$this->assertSame( $result, MainWP_Child_Backup_Mutex::holder() );
		} finally {
			remove_filter( 'query', $filter );
		}
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
