<?php
/**
 * Class BackupDownloadTokenTest
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Backup_Error;
use MainWP\Child\MainWP_Child_Backup_Download_Token;
use MainWP\Child\MainWP_Helper;

/**
 * Tests for the one-time backup download token: error codes, issuance,
 * and validate-and-consume semantics.
 */
class BackupDownloadTokenTest extends WP_UnitTestCase {

	/**
	 * Download token error code constants exist, use the mainwp_backup_ prefix,
	 * and are registered in the code registry.
	 */
	public function test_download_error_codes_are_registered() {
		$codes = array(
			MainWP_Backup_Error::ERR_DL_FILE_NOT_FOUND,
			MainWP_Backup_Error::ERR_DL_FILE_CHANGED,
			MainWP_Backup_Error::ERR_DL_TOKEN_INVALID,
		);
		$registered = MainWP_Backup_Error::get_registered_codes();
		foreach ( $codes as $code ) {
			$this->assertStringStartsWith( 'mainwp_backup_', $code );
			$this->assertContains( $code, $registered, "Code $code must be registered" );
		}
		$this->assertSame( count( $codes ), count( array_unique( $codes ) ), 'Error codes must be unique' );
	}

	/**
	 * Each download error code maps to a specific, non-fallback message.
	 */
	public function test_download_error_messages_are_specific() {
		$fallback = MainWP_Backup_Error::message( 'mainwp_backup_nonexistent_code' );
		$codes    = array(
			MainWP_Backup_Error::ERR_DL_FILE_NOT_FOUND,
			MainWP_Backup_Error::ERR_DL_FILE_CHANGED,
			MainWP_Backup_Error::ERR_DL_TOKEN_INVALID,
		);
		foreach ( $codes as $code ) {
			$message = MainWP_Backup_Error::message( $code );
			$this->assertNotEmpty( $message, "Code $code must map to a message" );
			$this->assertNotSame( $fallback, $message, "Code $code must not fall back to the generic message" );
		}
	}

	/**
	 * Files created in the backup directory during a test.
	 *
	 * @var string[]
	 */
	protected $artifacts = array();

	/**
	 * Remove artifacts created during the test.
	 */
	public function tear_down() {
		foreach ( $this->artifacts as $path ) {
			if ( file_exists( $path ) ) {
				unlink( $path );
			}
		}
		$this->artifacts = array();
		parent::tear_down();
	}

	/**
	 * Create a backup artifact in the randomized backup directory.
	 *
	 * @param string $name    File name.
	 * @param string $content File content.
	 *
	 * @return string Full path.
	 */
	protected function create_artifact( $name, $content = 'artifact-bytes' ) {
		$dirs = MainWP_Helper::get_mainwp_dir( 'backup' );
		$path = $dirs[0] . $name;
		file_put_contents( $path, $content );
		$this->artifacts[] = $path;
		return $path;
	}

	/**
	 * Issue returns the token, hash, size, and expiry shape.
	 */
	public function test_issue_returns_token_hash_size_expires_in() {
		$path   = $this->create_artifact( 'backup-issue-shape.zip', 'shape-bytes' );
		$result = MainWP_Child_Backup_Download_Token::instance()->issue( 'backup-issue-shape.zip' );

		$this->assertArrayHasKey( 'token', $result );
		$this->assertArrayHasKey( 'hash', $result );
		$this->assertArrayHasKey( 'size', $result );
		$this->assertArrayHasKey( 'expires_in', $result );
		$this->assertSame( filesize( $path ), $result['size'] );
		$this->assertSame( HOUR_IN_SECONDS, $result['expires_in'] );
	}

	/**
	 * The token is 32 alphanumeric characters.
	 */
	public function test_issue_token_is_32_chars_alphanumeric() {
		$this->create_artifact( 'backup-token-format.zip' );
		$result = MainWP_Child_Backup_Download_Token::instance()->issue( 'backup-token-format.zip' );

		$this->assertSame( 32, strlen( $result['token'] ) );
		$this->assertTrue( ctype_alnum( $result['token'] ) );
	}

	/**
	 * The hash is the sha256 of the file content.
	 */
	public function test_issue_hash_is_sha256_of_file() {
		$path   = $this->create_artifact( 'backup-hash.zip', 'known-content-for-hash' );
		$result = MainWP_Child_Backup_Download_Token::instance()->issue( 'backup-hash.zip' );

		$this->assertSame( hash_file( 'sha256', $path ), $result['hash'] );
	}

	/**
	 * Issuance stores a transient bound to the file.
	 */
	public function test_issue_stores_transient_with_expected_keys() {
		$path   = $this->create_artifact( 'backup-transient.zip' );
		$result = MainWP_Child_Backup_Download_Token::instance()->issue( 'backup-transient.zip' );

		$data = get_transient( 'mainwp_backup_download_' . $result['token'] );
		$this->assertIsArray( $data );
		foreach ( array( 'basename', 'full_path', 'hash', 'size', 'mtime', 'created' ) as $key ) {
			$this->assertArrayHasKey( $key, $data );
		}
		$this->assertSame( $path, $data['full_path'] );
	}

	/**
	 * Issuing for an unknown file returns an error array, never a token.
	 */
	public function test_issue_unknown_file_returns_error() {
		$result = MainWP_Child_Backup_Download_Token::instance()->issue( 'backup-does-not-exist.zip' );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertSame( MainWP_Backup_Error::ERR_DL_FILE_NOT_FOUND, $result['error_code'] );
		$this->assertArrayNotHasKey( 'token', $result );
	}

	/**
	 * Two issues for the same file produce distinct tokens.
	 */
	public function test_issue_double_call_returns_distinct_tokens() {
		$this->create_artifact( 'backup-distinct.zip' );
		$instance = MainWP_Child_Backup_Download_Token::instance();
		$first    = $instance->issue( 'backup-distinct.zip' );
		$second   = $instance->issue( 'backup-distinct.zip' );

		$this->assertNotSame( $first['token'], $second['token'] );
	}

	/**
	 * A valid token resolves to the file path.
	 */
	public function test_validate_and_consume_happy_path_returns_full_path() {
		$path     = $this->create_artifact( 'backup-consume.zip' );
		$instance = MainWP_Child_Backup_Download_Token::instance();
		$issued   = $instance->issue( 'backup-consume.zip' );

		$result = $instance->validate_and_consume( $issued['token'], 'backup-consume.zip' );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( $path, $result['full_path'] );
	}

	/**
	 * Consumption removes the token transient.
	 */
	public function test_validate_and_consume_deletes_transient() {
		$this->create_artifact( 'backup-consume-del.zip' );
		$instance = MainWP_Child_Backup_Download_Token::instance();
		$issued   = $instance->issue( 'backup-consume-del.zip' );

		$instance->validate_and_consume( $issued['token'], 'backup-consume-del.zip' );

		$this->assertFalse( get_transient( 'mainwp_backup_download_' . $issued['token'] ) );
	}

	/**
	 * A second consume of the same token fails.
	 */
	public function test_validate_and_consume_double_consume_fails() {
		$this->create_artifact( 'backup-double.zip' );
		$instance = MainWP_Child_Backup_Download_Token::instance();
		$issued   = $instance->issue( 'backup-double.zip' );

		$first  = $instance->validate_and_consume( $issued['token'], 'backup-double.zip' );
		$second = $instance->validate_and_consume( $issued['token'], 'backup-double.zip' );

		$this->assertTrue( $first['ok'] );
		$this->assertFalse( $second['ok'] );
		$this->assertSame( MainWP_Backup_Error::ERR_DL_TOKEN_INVALID, $second['code'] );
	}

	/**
	 * An expired (gone) token fails.
	 */
	public function test_validate_and_consume_expired_token_fails() {
		$this->create_artifact( 'backup-expired.zip' );
		$instance = MainWP_Child_Backup_Download_Token::instance();
		$issued   = $instance->issue( 'backup-expired.zip' );
		delete_transient( 'mainwp_backup_download_' . $issued['token'] );

		$result = $instance->validate_and_consume( $issued['token'], 'backup-expired.zip' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( MainWP_Backup_Error::ERR_DL_TOKEN_INVALID, $result['code'] );
	}

	/**
	 * A malformed token fails the format guard without touching transients.
	 */
	public function test_validate_and_consume_malformed_token_fails() {
		$instance = MainWP_Child_Backup_Download_Token::instance();

		foreach ( array( '', 'short', str_repeat( 'a', 31 ), str_repeat( 'a', 33 ), str_repeat( 'a', 31 ) . '!', null, 12345 ) as $bad ) {
			$result = $instance->validate_and_consume( $bad, 'backup-any.zip' );
			$this->assertFalse( $result['ok'] );
			$this->assertSame( MainWP_Backup_Error::ERR_DL_TOKEN_INVALID, $result['code'] );
		}
	}

	/**
	 * A token bound to one file cannot download another.
	 */
	public function test_validate_and_consume_wrong_basename_fails() {
		$this->create_artifact( 'backup-bound.zip' );
		$this->create_artifact( 'backup-other.zip' );
		$instance = MainWP_Child_Backup_Download_Token::instance();
		$issued   = $instance->issue( 'backup-bound.zip' );

		$result = $instance->validate_and_consume( $issued['token'], 'backup-other.zip' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( MainWP_Backup_Error::ERR_DL_TOKEN_INVALID, $result['code'] );
	}

	/**
	 * A file whose mtime changed after issuance is rejected.
	 */
	public function test_validate_and_consume_mtime_changed_fails() {
		$path     = $this->create_artifact( 'backup-mtime.zip' );
		$instance = MainWP_Child_Backup_Download_Token::instance();
		$issued   = $instance->issue( 'backup-mtime.zip' );
		touch( $path, time() - 100 );
		clearstatcache();

		$result = $instance->validate_and_consume( $issued['token'], 'backup-mtime.zip' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( MainWP_Backup_Error::ERR_DL_FILE_CHANGED, $result['code'] );
	}

	/**
	 * A file whose size changed after issuance is rejected even with the
	 * original mtime restored.
	 */
	public function test_validate_and_consume_size_changed_fails() {
		$path     = $this->create_artifact( 'backup-size.zip', 'original' );
		$instance = MainWP_Child_Backup_Download_Token::instance();
		$issued   = $instance->issue( 'backup-size.zip' );
		$mtime    = filemtime( $path );
		file_put_contents( $path, 'original-plus-more' );
		touch( $path, $mtime );
		clearstatcache();

		$result = $instance->validate_and_consume( $issued['token'], 'backup-size.zip' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( MainWP_Backup_Error::ERR_DL_FILE_CHANGED, $result['code'] );
	}

	/**
	 * A file deleted after issuance is rejected.
	 */
	public function test_validate_and_consume_file_deleted_fails() {
		$path     = $this->create_artifact( 'backup-deleted.zip' );
		$instance = MainWP_Child_Backup_Download_Token::instance();
		$issued   = $instance->issue( 'backup-deleted.zip' );
		unlink( $path );

		$result = $instance->validate_and_consume( $issued['token'], 'backup-deleted.zip' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( MainWP_Backup_Error::ERR_DL_FILE_NOT_FOUND, $result['code'] );
	}

	/**
	 * Issuing fires the audit action with the token_issued event.
	 */
	public function test_issue_fires_audit_action() {
		$this->create_artifact( 'backup-audit.zip' );
		$captured = array();
		add_action(
			'mainwp_child_backup_download_audit',
			function ( $entry ) use ( &$captured ) {
				$captured[] = $entry;
			}
		);

		MainWP_Child_Backup_Download_Token::instance()->issue( 'backup-audit.zip' );

		$this->assertCount( 1, $captured );
		$this->assertSame( 'token_issued', $captured[0]['event'] );
		$this->assertSame( 'backup-audit.zip', $captured[0]['file'] );
	}
}
