<?php
/**
 * Regression coverage for Changes Logs date formatting and site timezones.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\Changes\Changes_Handle_WP_System;
use MainWP\Child\Changes\Changes_Helper;
use MainWP\Child\Changes\Changes_Logs_DB_Log;
use MainWP\Child\Changes\Changes_Logs_Logger;

/**
 * Avoids the absent DB version cached before the test installation.
 */
class MainWP_Changes_Date_Format_Test_DB extends Changes_Logs_DB_Log {

	/**
	 * Reads the version without the production request cache.
	 *
	 * @return string|false Installed version.
	 */
	public function get_db_version() {
		return get_option( Changes_Helper::CHANGES_LOGS_DB_OPTION_NAME );
	}
}

/**
 * MWP-2184: an empty gmt_offset must not abort the request that logs a change.
 */
class Test_Changes_Logs_Date_Format extends WP_UnitTestCase {

	/**
	 * Date format used by every case, so expected strings are exact.
	 */
	const DATE_FORMAT = 'Y-m-d H:i';

	/**
	 * 2026-01-15 12:00:00 UTC.
	 */
	const WINTER_TS = 1768478400;

	/**
	 * Original DB singleton.
	 *
	 * @var object
	 */
	private $original_db;

	/**
	 * Prepares real log storage and a known date format.
	 */
	public function set_up() {
		parent::set_up();
		$instance = new ReflectionProperty( Changes_Logs_DB_Log::class, 'instance' );
		$instance->setAccessible( true );
		$this->original_db = $instance->getValue();
		$instance->setValue( null, new MainWP_Changes_Date_Format_Test_DB() );
		Changes_Logs_DB_Log::instance()->install();
		$this->clear_logs();

		update_option( 'date_format', self::DATE_FORMAT );
		$this->reset_helper_cache();
	}

	/**
	 * Restores the DB singleton and request-local caches.
	 */
	public function tear_down() {
		Changes_Handle_WP_System::change_set_executed_cron( false );
		$this->reset_logger();
		$this->reset_helper_cache();
		$instance = new ReflectionProperty( Changes_Logs_DB_Log::class, 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, $this->original_db );
		parent::tear_down();
	}

	/**
	 * Clears the helper's per-request option cache.
	 */
	private function reset_helper_cache() {
		$property = new ReflectionProperty( Changes_Helper::class, 'date_format' );
		$property->setAccessible( true );
		$property->setValue( null, null );
	}

	/**
	 * Clears request-local logger caches.
	 */
	private function reset_logger() {
		foreach ( array( 'delay_logs_items', 'logs_type_queue', 'logs_type_handled', 'cached_log_checks', 'wp_users' ) as $name ) {
			$property = new ReflectionProperty( Changes_Logs_Logger::class, $name );
			$property->setAccessible( true );
			$property->setValue( null, array() );
		}
	}

	/**
	 * Removes earlier events from real storage.
	 */
	private function clear_logs() {
		global $wpdb;
		$this->reset_logger();
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Changes_Logs_DB_Log::table_name( 'changes_meta' ) ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Changes_Logs_DB_Log::table_name( 'changes_logs' ) ) );
	}

	/**
	 * Stores the site timezone the way Settings > General does.
	 *
	 * @param string           $timezone_string Named timezone, or '' for a manual offset.
	 * @param string|int|float $gmt_offset      Stored gmt_offset value.
	 */
	private function set_site_timezone( $timezone_string, $gmt_offset ) {
		update_option( 'timezone_string', $timezone_string );
		update_option( 'gmt_offset', $gmt_offset );
		$this->reset_helper_cache();
	}

	/**
	 * Returns the stored timestamp text of the only cron-created log.
	 *
	 * @return string Logged timestamp text.
	 */
	private function logged_cron_timestamp() {
		$logs = Changes_Logs_Logger::get_latest_logs( 5, true );
		$this->assertCount( 1, $logs, 'The cron creation event was not stored.' );
		$log = reset( $logs );
		$this->assertSame( 1925, $log['log_type_id'] );
		return $log['meta_values']['timestamp'];
	}

	/**
	 * An empty offset with no named timezone formats as UTC, as WordPress does.
	 */
	public function test_empty_gmt_offset_formats_as_utc() {
		$this->set_site_timezone( '', '' );
		$this->assertSame( '', get_option( 'gmt_offset' ), 'Fixture must reach the helper as an empty string.' );

		$this->assertSame( '2026-01-15 12:00', Changes_Helper::get_formatted_date( self::WINTER_TS ) );
	}

	/**
	 * Manual offsets keep the output the previous offset arithmetic produced.
	 *
	 * @dataProvider manual_offsets
	 *
	 * @param string|int|float $gmt_offset Stored gmt_offset value.
	 * @param string           $expected   Expected local time.
	 */
	public function test_manual_offsets_are_unchanged( $gmt_offset, $expected ) {
		$this->set_site_timezone( '', $gmt_offset );

		$this->assertSame( $expected, Changes_Helper::get_formatted_date( self::WINTER_TS ) );
		// The pre-fix formula, for the byte-identical check.
		$this->assertSame( $expected, date_i18n( self::DATE_FORMAT, (int) ( self::WINTER_TS + $gmt_offset * HOUR_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
	}

	/**
	 * Manual offset fixtures.
	 *
	 * @return array[]
	 */
	public function manual_offsets() {
		return array(
			'negative string' => array( '-4', '2026-01-15 08:00' ),
			'fractional'      => array( '5.5', '2026-01-15 17:30' ),
			'zero integer'    => array( 0, '2026-01-15 12:00' ),
			'negative float'  => array( -5.0, '2026-01-15 07:00' ),
			'quarter hour'    => array( '5.75', '2026-01-15 17:45' ),
		);
	}

	/**
	 * A named timezone uses the offset in force at each timestamp, across DST.
	 */
	public function test_named_timezone_across_dst_boundaries() {
		$this->set_site_timezone( 'America/New_York', '' );

		$cases = array(
			// Spring forward: 2026-03-08 02:00 EST becomes 03:00 EDT.
			1772953140 => '2026-03-08 01:59', // 06:59 UTC, EST.
			1772953200 => '2026-03-08 03:00', // 07:00 UTC, EDT.
			// Fall back: 2026-11-01 02:00 EDT becomes 01:00 EST.
			1793511000 => '2026-11-01 01:30', // 05:30 UTC, EDT.
			1793514600 => '2026-11-01 01:30', // 06:30 UTC, EST.
			1768478400 => '2026-01-15 07:00', // 12:00 UTC, EST.
			1782907200 => '2026-07-01 08:00', // 12:00 UTC, EDT.
		);

		// One request formats every timestamp, as a cron listing would.
		foreach ( $cases as $timestamp => $expected ) {
			$this->assertSame( $expected, Changes_Helper::get_formatted_date( $timestamp ), "Timestamp $timestamp" );
		}
	}

	/**
	 * Date and time output types read their own options.
	 */
	public function test_date_and_time_types() {
		update_option( 'time_format', 'g:i a' );
		$this->set_site_timezone( '', '' );

		$this->assertSame( '2026-01-15 12:00', Changes_Helper::get_formatted_date( self::WINTER_TS, 'date' ) );
		$this->assertSame( '12:00 pm', Changes_Helper::get_formatted_date( self::WINTER_TS, 'time' ) );
		$this->assertSame( '', Changes_Helper::get_formatted_date( self::WINTER_TS, 'unknown' ) );
	}

	/**
	 * The reported path: scheduling a cron event logs it, with an empty offset.
	 */
	public function test_cron_creation_callback_with_empty_offset_logs_the_event() {
		$this->set_site_timezone( '', '' );
		$event = (object) array(
			'hook'      => 'mainwp_mwp2184_test_event',
			'timestamp' => self::WINTER_TS,
			'schedule'  => false,
			'args'      => array(),
		);

		// What change_pre_schedule_event() records outside DOING_CRON, which an earlier test may have defined.
		Changes_Handle_WP_System::change_set_executed_cron( $event );
		$result = Changes_Handle_WP_System::callback_change_create_new_cron_job( $event );

		$this->assertSame( $event, $result );
		$this->assertSame( '2026-01-15 12:00', $this->logged_cron_timestamp() );
	}

	/**
	 * The real scheduling API, with the Child's hooks attached, completes and logs.
	 */
	public function test_wp_schedule_single_event_with_empty_offset() {
		$this->assertNotFalse( has_filter( 'schedule_event', array( Changes_Handle_WP_System::class, 'callback_change_create_new_cron_job' ) ), 'Changes Logs cron hooks are not attached.' );
		$this->set_site_timezone( '', '' );
		Changes_Handle_WP_System::change_set_executed_cron( (object) array( 'hook' => 'mainwp_mwp2184_test_event' ) );

		$scheduled = wp_schedule_single_event( self::WINTER_TS, 'mainwp_mwp2184_test_event' );
		wp_clear_scheduled_hook( 'mainwp_mwp2184_test_event' );

		$this->assertTrue( $scheduled );
		$this->assertSame( '2026-01-15 12:00', $this->logged_cron_timestamp() );
	}
}
