<?php
/**
 * Regression coverage for reading recent change logs with their metadata.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\Changes\Changes_Handle_WP_Database;
use MainWP\Child\Changes\Changes_Helper;
use MainWP\Child\Changes\Changes_Logs_DB_Log;
use MainWP\Child\Changes\Changes_Logs_Logger;

/**
 * Avoids the absent DB version cached before the test installation.
 */
class MainWP_Changes_Latest_Logs_Test_DB extends Changes_Logs_DB_Log {

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
 * Exercises get_latest_logs() over rows written by the real logger.
 */
class Test_Changes_Logs_Latest_Logs extends WP_UnitTestCase {

	/**
	 * A User-Agent shaped like a serialized object.
	 */
	const SERIALIZED_USER_AGENT = 'O:8:"stdClass":0:{}';

	/**
	 * Original DB singleton.
	 *
	 * @var object
	 */
	private $original_db;

	/**
	 * Original User-Agent header, or null when it was unset.
	 *
	 * @var string|null
	 */
	private $original_user_agent;

	/**
	 * Prepares real storage and isolates request-local logger state.
	 */
	public function set_up() {
		parent::set_up();
		$this->original_user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : null;
		$instance                  = new ReflectionProperty( Changes_Logs_DB_Log::class, 'instance' );
		$instance->setAccessible( true );
		$this->original_db = $instance->getValue();
		$instance->setValue( null, new MainWP_Changes_Latest_Logs_Test_DB() );
		Changes_Logs_DB_Log::instance()->install();
		$this->clear_logs();
	}

	/**
	 * Restores the DB singleton and the User-Agent header.
	 */
	public function tear_down() {
		$this->reset_logger();
		$instance = new ReflectionProperty( Changes_Logs_DB_Log::class, 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, $this->original_db );
		if ( null === $this->original_user_agent ) {
			unset( $_SERVER['HTTP_USER_AGENT'] );
		} else {
			$_SERVER['HTTP_USER_AGENT'] = $this->original_user_agent;
		}
		parent::tear_down();
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
	 * Logs a plugin activation the way the plugins handler does.
	 *
	 * @param string $user_agent User-Agent header for the request.
	 */
	private function log_plugin_activation( $user_agent ) {
		$_SERVER['HTTP_USER_AGENT'] = $user_agent;
		Changes_Logs_Logger::log_change(
			1461,
			array(
				'slug'       => 'example-plugin/example-plugin.php',
				'plugindata' => (object) array(
					'name'      => 'Example Plugin',
					'pluginuri' => 'https://example.com/',
					'version'   => '1.2.3',
					'author'    => 'Example Author',
				),
			)
		);
		$this->assertSame( 1, $this->stored_log_count(), 'The fixture event was not stored.' );
	}

	/**
	 * Counts stored log rows.
	 *
	 * @return int Row count.
	 */
	private function stored_log_count() {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Changes_Logs_DB_Log::table_name( 'changes_logs' ) ) );
	}

	/**
	 * Real rows come back with their columns and metadata.
	 */
	public function test_latest_logs_with_meta_returns_stored_rows() {
		$this->log_plugin_activation( 'Mozilla/5.0 (X11; Linux x86_64)' );

		$logs = Changes_Logs_Logger::get_latest_logs( 5, true );

		$this->assertCount( 1, $logs );
		$log = reset( $logs );
		$this->assertSame( 1461, $log['log_type_id'] );
		$this->assertSame( 'Mozilla/5.0 (X11; Linux x86_64)', $log['user_agent'] );
		$this->assertSame( 'example-plugin/example-plugin.php', $log['meta_values']['slug'] );
		$this->assertInstanceOf( stdClass::class, $log['meta_values']['plugindata'] );
		$this->assertSame( 'Example Plugin', $log['meta_values']['plugindata']->name );
		$this->assertSame( 'Mozilla/5.0 (X11; Linux x86_64)', $log['meta_values']['useragent'] );
		$this->assertArrayNotHasKey( '', $log['meta_values'] );
	}

	/**
	 * A log without metadata rows still comes back, with no empty meta key.
	 */
	public function test_latest_logs_with_meta_keeps_logs_without_meta_rows() {
		Changes_Logs_DB_Log::instance()->save_log(
			array(
				'log_type_id' => 1461,
				'user_agent'  => 'Mozilla/5.0',
				'context'     => 'plugin',
			)
		);

		$logs = Changes_Logs_Logger::get_latest_logs( 5, true );

		$this->assertCount( 1, $logs );
		$log = reset( $logs );
		$this->assertSame( 1461, $log['log_type_id'] );
		$this->assertArrayNotHasKey( '', $log['meta_values'] );
	}

	/**
	 * The plugin-name fallback finds the recently activated plugin.
	 */
	public function test_recent_activated_plugin_fallback_returns_plugin_name() {
		$this->log_plugin_activation( 'Mozilla/5.0 (X11; Linux x86_64)' );

		$method = new ReflectionMethod( Changes_Handle_WP_Database::class, 'change_check_recent_activated_plugin' );
		$method->setAccessible( true );

		$this->assertSame( 'Example Plugin', $method->invoke( null ) );
	}

	/**
	 * A visitor-supplied User-Agent is never unserialized.
	 */
	public function test_serialized_looking_user_agent_stays_a_string() {
		$this->log_plugin_activation( self::SERIALIZED_USER_AGENT );

		$logs = Changes_Logs_Logger::get_latest_logs( 5, true );

		$this->assertCount( 1, $logs );
		$log = reset( $logs );
		$this->assertSame( self::SERIALIZED_USER_AGENT, $log['user_agent'] );
		$this->assertSame( self::SERIALIZED_USER_AGENT, $log['meta_values']['useragent'] );
		$this->assertInstanceOf( stdClass::class, $log['meta_values']['plugindata'] );
	}
}
