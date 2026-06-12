<?php
/**
 * Class BackupErrorTest
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Backup_Error;

/**
 * Tests for the backup error code registry and message sanitization helpers.
 */
class BackupErrorTest extends WP_UnitTestCase {

	/**
	 * Error code constants exist and use the mainwp_backup_* prefix.
	 */
	public function test_error_code_constants_exist() {
		$codes = array(
			MainWP_Backup_Error::ERR_DISK_FULL,
			MainWP_Backup_Error::ERR_PERMISSIONS,
			MainWP_Backup_Error::ERR_DIR_NOT_WRITABLE,
			MainWP_Backup_Error::ERR_DB_CONNECTION_LOST,
			MainWP_Backup_Error::ERR_DB_QUERY_FAILED,
			MainWP_Backup_Error::ERR_DB_DUMP_EMPTY,
			MainWP_Backup_Error::ERR_FILE_CORRUPT,
			MainWP_Backup_Error::ERR_ZIP_FAILED,
			MainWP_Backup_Error::ERR_TAR_WRITE_FAILED,
			MainWP_Backup_Error::ERR_FILE_SKIPPED,
			MainWP_Backup_Error::ERR_TOO_MANY_SKIPPED,
			MainWP_Backup_Error::ERR_STALLED,
			MainWP_Backup_Error::ERR_ALREADY_RUNNING,
			MainWP_Backup_Error::ERR_MANIFEST_CHUNK_WRONG_PHASE,
			MainWP_Backup_Error::ERR_MANIFEST_CHUNK_CORRUPT,
		);
		foreach ( $codes as $code ) {
			$this->assertStringStartsWith( 'mainwp_backup_', $code );
		}
		$this->assertSame( count( $codes ), count( array_unique( $codes ) ), 'Error codes must be unique' );
	}

	/**
	 * Every registered code maps to an actionable message (what/why/how, no vague text).
	 */
	public function test_messages_are_actionable() {
		foreach ( MainWP_Backup_Error::get_registered_codes() as $code ) {
			$message = MainWP_Backup_Error::message( $code );
			$this->assertNotEmpty( $message, "Code $code must map to a message" );
			$this->assertStringNotContainsString( 'undefined error', strtolower( $message ) );
			$this->assertStringNotContainsString( 'unknown error', strtolower( $message ) );
			$this->assertStringNotContainsString( ABSPATH, $message, "Message for $code must not contain absolute paths" );
			$this->assertGreaterThan( 40, strlen( $message ), "Message for $code should explain what/why/how" );
		}
	}

	/**
	 * The cloud-pull failure code is registered with an actionable
	 * message that tells the user the dashboard falls back on its own.
	 */
	public function test_cloud_pull_failed_code_registered() {
		$this->assertSame( 'mainwp_cloud_pull_failed', MainWP_Backup_Error::ERR_CLOUD_PULL_FAILED );
		$this->assertContains( MainWP_Backup_Error::ERR_CLOUD_PULL_FAILED, MainWP_Backup_Error::get_registered_codes() );
		$this->assertStringContainsString( 'cloud storage', MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_CLOUD_PULL_FAILED ) );
	}

	/**
	 * Unknown codes fall back to a generic but well-formed message.
	 */
	public function test_message_unknown_code_falls_back() {
		$message = MainWP_Backup_Error::message( 'mainwp_backup_nonexistent_code' );
		$this->assertNotEmpty( $message );
		$this->assertStringNotContainsString( 'undefined error', strtolower( $message ) );
	}

	/**
	 * Absolute server paths are stripped from user-facing messages.
	 */
	public function test_sanitize_message_strips_abspath() {
		$message   = 'Could not open ' . ABSPATH . 'wp-content/uploads/mainwp/backup/file.zip for writing';
		$sanitized = MainWP_Backup_Error::sanitize_message( $message );
		$this->assertStringNotContainsString( ABSPATH, $sanitized );
		$this->assertStringContainsString( 'wp-content/uploads/mainwp/backup/file.zip', $sanitized );
	}

	/**
	 * SQL statement fragments are stripped from user-facing messages.
	 */
	public function test_sanitize_message_strips_sql_fragments() {
		$cases = array(
			"Query failed: SELECT option_value FROM wp_options WHERE option_id = 1",
			'Error in INSERT INTO wp_woocommerce_orders (id) VALUES (1)',
			'Failed running DROP TABLE IF EXISTS wp_custom_crm_leads',
			'Error: CREATE TABLE wp_secret_business_table (id INT)',
		);
		foreach ( $cases as $message ) {
			$sanitized = MainWP_Backup_Error::sanitize_message( $message );
			$this->assertStringNotContainsString( 'wp_options', $sanitized );
			$this->assertStringNotContainsString( 'wp_woocommerce_orders', $sanitized );
			$this->assertStringNotContainsString( 'wp_custom_crm_leads', $sanitized );
			$this->assertStringNotContainsString( 'wp_secret_business_table', $sanitized );
		}
	}

	/**
	 * Backticked identifiers and MySQL "Table '...'" references are redacted.
	 */
	public function test_sanitize_message_strips_table_references() {
		$sanitized = MainWP_Backup_Error::sanitize_message( 'Could not read `wp_secret_table` during backup' );
		$this->assertStringNotContainsString( 'wp_secret_table', $sanitized );

		$sanitized = MainWP_Backup_Error::sanitize_message( "Table 'mydb.wp_secret_table' doesn't exist" );
		$this->assertStringNotContainsString( 'wp_secret_table', $sanitized );
	}

	/**
	 * Plain English messages pass through unchanged.
	 */
	public function test_sanitize_message_preserves_plain_messages() {
		$cases = array(
			'The server may be out of disk space. Free up disk space and try again.',
			'Please update the MainWP Child plugin and check for updates.',
			'Backup did not complete. Try again later.',
		);
		foreach ( $cases as $message ) {
			$this->assertSame( $message, MainWP_Backup_Error::sanitize_message( $message ) );
		}
	}

	/**
	 * DB credentials are scrubbed from error messages.
	 */
	public function test_scrub_db_credentials_redacts_values() {
		$message = sprintf(
			"Access denied for user '%s'@'somehost' (using password: YES) connecting to %s on %s database %s",
			DB_USER,
			DB_HOST,
			DB_HOST,
			DB_NAME
		);
		$scrubbed = MainWP_Backup_Error::scrub_db_credentials( $message );
		$this->assertStringNotContainsString( DB_HOST, $scrubbed );
		$this->assertStringNotContainsString( DB_NAME, $scrubbed );
		$this->assertStringNotContainsString( "user '" . DB_USER . "'", $scrubbed );
		$this->assertStringContainsString( '[redacted]', $scrubbed );
	}

	/**
	 * The DB password value never survives scrubbing.
	 */
	public function test_scrub_db_credentials_redacts_password() {
		if ( '' === DB_PASSWORD ) {
			$this->markTestSkipped( 'DB_PASSWORD is empty in this environment.' );
		}
		$message  = 'mysqli error: auth failed with ' . DB_PASSWORD . ' on connect';
		$scrubbed = MainWP_Backup_Error::scrub_db_credentials( $message );
		$this->assertStringNotContainsString( DB_PASSWORD, $scrubbed );
	}

	/**
	 * The mysqli user 'x'@'y' pattern is redacted even for unknown users.
	 */
	public function test_scrub_db_credentials_redacts_user_at_host_pattern() {
		$scrubbed = MainWP_Backup_Error::scrub_db_credentials( "Access denied for user 'someuser'@'10.0.0.5' (using password: YES)" );
		$this->assertStringNotContainsString( 'someuser', $scrubbed );
		$this->assertStringNotContainsString( '10.0.0.5', $scrubbed );
	}

	/**
	 * Scrubbing handles empty and non-string-safe input.
	 */
	public function test_scrub_db_credentials_handles_empty() {
		$this->assertSame( '', MainWP_Backup_Error::scrub_db_credentials( '' ) );
	}
}
