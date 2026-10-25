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

	private $recorded_tasks = array();

	public static function unavailable_hash_sidecars() {
		return array( 'missing' => array( 'missing' ), 'invalid' => array( 'invalid' ), 'unreadable' => array( 'unreadable' ), 'rewritten' => array( 'rewritten' ) );
	}

	/** @dataProvider unavailable_hash_sidecars */
	public function test_backup_hash_token_keeps_db_evidence_when_sidecar_is_unavailable( $damage ) {
		$file = 'backup-sidecar-evidence.zip';
		$path = $this->create_artifact( $file, 'original bytes' );
		$id = $this->record_completed_hash( $file, hash_file( 'sha256', $path ) );
		$spine = \MainWP\Child\MainWP_Child_Backup_Task::instance();
		$pointer = json_decode( $spine->get_task( $id )->sub_state, true );
		$this->assertTrue( $pointer['_sidecar'] );
		$dirs = MainWP_Helper::get_mainwp_dir( 'backup' );
		$sidecar = $dirs[0] . '/' . $pointer['file'];
		file_put_contents( $path, 'changed bytes' );
		try {
			if ( 'missing' === $damage ) {
				unlink( $sidecar );
			} elseif ( 'invalid' === $damage ) {
				file_put_contents( $sidecar, '{invalid' );
			} elseif ( 'unreadable' === $damage ) {
				chmod( $sidecar, 0000 );
				clearstatcache( true, $sidecar );
				if ( is_readable( $sidecar ) ) {
					$this->markTestSkipped( 'The test user can read chmod 0000 files.' );
				}
			} else {
				$forged = json_decode( file_get_contents( $sidecar ), true );
				$forged['artifacts'][0]['sha256'] = hash_file( 'sha256', $path );
				file_put_contents( $sidecar, wp_json_encode( $forged ) );
			}
			$result = MainWP_Child_Backup_Download_Token::instance()->issue( $file );
			$this->assertSame( array( 'error', 'error_code' ), array_keys( $result ) );
			$this->assertSame( MainWP_Backup_Error::ERR_DL_FILE_HASH_MISMATCH, $result['error_code'] );
			file_put_contents( $path, 'original bytes' );
			$this->assertArrayHasKey( 'token', MainWP_Child_Backup_Download_Token::instance()->issue( $file ) );
		} finally {
			if ( is_file( $sidecar ) ) {
				chmod( $sidecar, 0600 );
			}
		}
	}

	public function test_backup_hash_token_legacy_pointer_without_digest_map_has_no_evidence() {
		global $wpdb;
		$file = 'backup-old-pointer.zip';
		$path = $this->create_artifact( $file, 'old bytes' );
		$id = $this->record_completed_hash( $file, hash_file( 'sha256', $path ) );
		$spine = \MainWP\Child\MainWP_Child_Backup_Task::instance();
		$pointer = json_decode( $spine->get_task( $id )->sub_state, true );
		unset( $pointer['artifact_sha256'] );
		$this->assertSame( 1, $wpdb->update( $spine->get_table_name(), array( 'sub_state' => wp_json_encode( $pointer ) ), array( 'task_id' => $id ) ) );
		file_put_contents( $path, 'new bytes' );
		$this->assertArrayHasKey( 'token', MainWP_Child_Backup_Download_Token::instance()->issue( $file ) );
	}

	public function test_backup_hash_token_scan_selects_only_json_encoded_basename_matches() {
		global $wpdb;
		$file = 'backup-scan_50%-café.zip';
		$path = $this->create_artifact( $file, 'selected bytes' );
		$digest = hash_file( 'sha256', $path );
		$id = $this->record_completed_hash( $file, $digest );
		for ( $i = 0; $i < 40; ++$i ) {
			$this->record_completed_hash( 'backup-history-' . $i . '.zip', str_repeat( 'b', 64 ), true );
		}
		$this->record_completed_hash( 'backup-scanX50-unrelated-café.zip', str_repeat( 'c', 64 ), true );
		$spine = \MainWP\Child\MainWP_Child_Backup_Task::instance();
		$table = $spine->get_table_name();
		$scans = array();
		$listener = function ( $query ) use ( $table, &$scans ) {
			if ( 0 === strpos( $query, 'SELECT task_id, sub_state FROM ' . $table . ' WHERE phase' ) ) {
				$scans[] = $query;
			}
			return $query;
		};
		add_filter( 'query', $listener );
		try {
			$result = MainWP_Child_Backup_Download_Token::instance()->issue( $file );
		} finally {
			remove_filter( 'query', $listener );
		}
		$this->assertArrayHasKey( 'token', $result );
		$this->assertSame( $digest, $result['hash'] );
		$this->assertCount( 1, $scans );
		$like = '%' . $wpdb->esc_like( substr( wp_json_encode( $file ), 1, -1 ) ) . '%';
		$this->assertStringContainsString( $wpdb->remove_placeholder_escape( $wpdb->prepare( 'AND sub_state LIKE %s', $like ) ), $scans[0] );
		$rows = $wpdb->get_results( $scans[0] );
		$this->assertCount( 1, $rows );
		$this->assertSame( $id, $rows[0]->task_id );
	}

	private function record_completed_hash( $file, $digest, $single = false, $skip = false ) {
		$spine = \MainWP\Child\MainWP_Child_Backup_Task::instance();
		$spine->install();
		$entry = array( 'file' => $file, 'size' => 1 );
		if ( $skip ) {
			$entry['hash_skipped'] = 'too_large';
		} elseif ( null !== $digest ) {
			$entry['sha256'] = $digest;
		}
		$id = $spine->create_task( 'files', $single ? array( 'artifact' => $entry ) : array( 'artifacts' => array( $entry ), 'padding' => str_repeat( 'x', 60000 ) ) );
		$dirs = MainWP_Helper::get_mainwp_dir( 'backup' );
		$sub = $spine->read_sub_state( $spine->get_task( $id ), $dirs[0] );
		$serialized = $spine->write_sub_state( $id, $sub, $dirs[0], 0 );
		$pointer = json_decode( $serialized, true );
		if ( ! empty( $pointer['_sidecar'] ) ) {
			$this->artifacts[] = $dirs[0] . '/' . $pointer['file'];
		}
		$this->assertNotFalse( $spine->checkpoint( $id, 0, array( 'phase' => 'completed', 'sub_state' => $serialized ) ) );
		$this->recorded_tasks[] = $id;
		return $id;
	}

	public function test_backup_hash_token_refuses_changed_archive_and_accepts_any_matching_record() {
		$file = 'backup-recorded-digest.zip';
		$path = $this->create_artifact( $file, 'original' );
		$old = hash_file( 'sha256', $path );
		$this->record_completed_hash( $file, $old );
		$tokens = MainWP_Child_Backup_Download_Token::instance();
		$this->assertArrayHasKey( 'token', $tokens->issue( $file ) );
		file_put_contents( $path, 'modified' );
		$answer = $tokens->issue( $file );
		$this->assertSame( array( 'error', 'error_code' ), array_keys( $answer ) );
		$this->assertSame( MainWP_Backup_Error::ERR_DL_FILE_HASH_MISMATCH, $answer['error_code'] );
		$this->assertSame( 'The backup file on the child site has changed since the backup finished and no longer matches its recorded hash. Run a new backup.', $answer['error'] );
		$this->record_completed_hash( $file, hash_file( 'sha256', $path ), true );
		$this->assertArrayHasKey( 'token', $tokens->issue( $file ) );
	}

	public static function token_evidence_read_failures() {
		return array(
			'query fails on the existing table' => array( false, 'mainwp_backup_dl_recorded_hash_unreadable' ),
			'task table missing (legacy)'        => array( true, null ),
		);
	}

	/**
	 * A failed evidence read must not pass as "no recorded hash"; only a missing table is the legacy case.
	 *
	 * @dataProvider token_evidence_read_failures
	 */
	public function test_backup_hash_token_refuses_when_recorded_evidence_cannot_be_read( $table_missing, $code ) {
		$file = 'backup-evidence-read.zip';
		$path = $this->create_artifact( $file, 'original' );
		$this->record_completed_hash( $file, hash_file( 'sha256', $path ) );
		file_put_contents( $path, 'modified' );
		$table  = \MainWP\Child\MainWP_Child_Backup_Task::instance()->get_table_name();
		$filter = function ( $query ) use ( $table, $table_missing ) {
			if ( false !== strpos( $query, 'sub_state LIKE' ) ) {
				return $table_missing ? str_replace( $table, $table . '_absent', $query ) : str_replace( 'SELECT task_id, sub_state', 'SELECT no_such_column', $query );
			}
			if ( $table_missing && 0 === strpos( $query, 'SELECT 1 FROM ' . $table ) ) {
				return str_replace( $table, $table . '_absent', $query );
			}
			return $query;
		};
		add_filter( 'query', $filter );
		try {
			$answer = MainWP_Child_Backup_Download_Token::instance()->issue( $file );
		} finally {
			remove_filter( 'query', $filter );
		}
		if ( null === $code ) {
			$this->assertArrayHasKey( 'token', $answer );
		} else {
			$this->assertSame( array( 'error', 'error_code' ), array_keys( $answer ) );
			$this->assertSame( $code, $answer['error_code'] );
			$this->assertSame( 'Could not read the backup record to check this file. Try the download again. If it keeps failing, check the child site error log or run a new backup.', $answer['error'] );
		}
	}

	public function test_backup_hash_token_chain_and_skipped_hash_exemptions() {
		$tokens = MainWP_Child_Backup_Download_Token::instance();
		foreach ( array( 'files.list', 'tombstones.list', 'changes.list' ) as $file ) {
			$this->create_artifact( $file, 'new run' );
			$this->record_completed_hash( $file, str_repeat( 'a', 64 ), true );
			$this->assertArrayHasKey( 'token', $tokens->issue( $file ), $file );
		}
		$file = 'backup-skipped-digest.zip';
		$this->create_artifact( $file );
		$this->record_completed_hash( $file, null, true, true );
		$this->assertArrayHasKey( 'token', $tokens->issue( $file ) );
	}

	public function test_backup_guard_token_without_recorded_hash_is_issued() {
		$path = $this->create_artifact( 'backup-legacy-hash.zip', 'legacy bytes' );
		$result = MainWP_Child_Backup_Download_Token::instance()->issue( basename( $path ) );
		$this->assertSame( hash_file( 'sha256', $path ), $result['hash'] );
		$this->assertArrayHasKey( 'token', $result );
	}

	/**
	 * Download token error code constants exist, use the mainwp_backup_ prefix,
	 * and are registered in the code registry.
	 */
	public function test_download_error_codes_are_registered() {
		$this->assertSame( 'mainwp_backup_dl_recorded_hash_unreadable', MainWP_Backup_Error::ERR_DL_RECORDED_HASH_UNREADABLE );
		$codes = array(
			MainWP_Backup_Error::ERR_DL_RECORDED_HASH_UNREADABLE,
			MainWP_Backup_Error::ERR_DL_FILE_HASH_MISMATCH,
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
		$this->assertSame( 'Could not read the backup record to check this file. Try the download again. If it keeps failing, check the child site error log or run a new backup.', MainWP_Backup_Error::message( 'mainwp_backup_dl_recorded_hash_unreadable' ) );
		$fallback = MainWP_Backup_Error::message( 'mainwp_backup_nonexistent_code' );
		$codes    = array(
			MainWP_Backup_Error::ERR_DL_RECORDED_HASH_UNREADABLE,
			MainWP_Backup_Error::ERR_DL_FILE_HASH_MISMATCH,
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
		global $wpdb;
		foreach ( $this->recorded_tasks as $id ) {
			$wpdb->delete( \MainWP\Child\MainWP_Child_Backup_Task::instance()->get_table_name(), array( 'task_id' => $id ) );
		}
		$this->recorded_tasks = array();
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
	 * A wrong basename is rejected without consuming the token, so a
	 * follow-up request with the correct name still succeeds.
	 */
	public function test_validate_and_consume_wrong_basename_keeps_token() {
		$this->create_artifact( 'backup-wrong-name.zip' );
		$instance = MainWP_Child_Backup_Download_Token::instance();
		$issued   = $instance->issue( 'backup-wrong-name.zip' );

		$result = $instance->validate_and_consume( $issued['token'], 'backup-other-name.zip' );

		$this->assertFalse( $result['ok'] );

		$retry = $instance->validate_and_consume( $issued['token'], 'backup-wrong-name.zip' );

		$this->assertTrue( $retry['ok'] );
		$this->assertArrayHasKey( 'full_path', $retry );
	}

	/**
	 * A changed file is rejected without consuming the token, leaving it
	 * available for the Dashboard to retry once the file matches again.
	 */
	public function test_validate_and_consume_changed_file_keeps_token() {
		$path     = $this->create_artifact( 'backup-changed.zip', 'original' );
		$instance = MainWP_Child_Backup_Download_Token::instance();
		$issued   = $instance->issue( 'backup-changed.zip' );
		file_put_contents( $path, 'original-plus-more', FILE_APPEND );
		clearstatcache();

		$result = $instance->validate_and_consume( $issued['token'], 'backup-changed.zip' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( MainWP_Backup_Error::ERR_DL_FILE_CHANGED, $result['code'] );
		$this->assertIsArray( get_transient( 'mainwp_backup_download_' . $issued['token'] ) );
	}

	/**
	 * The backup_download_token action is registered in the callable router
	 * so the Dashboard can request a download authorization.
	 */
	public function test_backup_download_token_callable_is_registered() {
		$callable = \MainWP\Child\MainWP_Child_Callable::get_instance();
		$prop     = new ReflectionProperty( \MainWP\Child\MainWP_Child_Callable::class, 'callableFunctions' );
		$prop->setAccessible( true );
		$map = $prop->getValue( $callable );

		$this->assertArrayHasKey( 'backup_download_token', $map );
		$this->assertTrue( method_exists( $callable, 'backup_download_token' ) );
	}

	/**
	 * Rejection codes map to 401 for token problems and 403 for file problems.
	 */
	public function test_rejection_status_maps_codes() {
		$instance = MainWP_Child_Backup_Download_Token::instance();

		$this->assertSame( 401, $instance->rejection_status( MainWP_Backup_Error::ERR_DL_TOKEN_INVALID ) );
		$this->assertSame( 403, $instance->rejection_status( MainWP_Backup_Error::ERR_DL_FILE_CHANGED ) );
		$this->assertSame( 403, $instance->rejection_status( MainWP_Backup_Error::ERR_DL_FILE_NOT_FOUND ) );
	}

	/**
	 * A successful authorization returns the file path and fires the
	 * download_ok audit event with the file size.
	 */
	public function test_authorize_download_success_fires_audit() {
		$path     = $this->create_artifact( 'backup-authz-ok.zip', 'authz-bytes' );
		$instance = MainWP_Child_Backup_Download_Token::instance();
		$issued   = $instance->issue( 'backup-authz-ok.zip' );

		$captured = array();
		add_action(
			'mainwp_child_backup_download_audit',
			function ( $entry ) use ( &$captured ) {
				$captured[] = $entry;
			}
		);

		$auth = $instance->authorize_download( $issued['token'], 'backup-authz-ok.zip' );

		$this->assertTrue( $auth['ok'] );
		$this->assertSame( $path, $auth['full_path'] );
		$this->assertCount( 1, $captured );
		$this->assertSame( 'download_ok', $captured[0]['event'] );
		$this->assertSame( 'backup-authz-ok.zip', $captured[0]['file'] );
		$this->assertSame( filesize( $path ), $captured[0]['size'] );
	}

	/**
	 * A rejected authorization returns the HTTP status and fires the
	 * download_rejected audit event with the reason code.
	 */
	public function test_authorize_download_rejection_fires_audit_with_status() {
		$instance = MainWP_Child_Backup_Download_Token::instance();

		$captured = array();
		add_action(
			'mainwp_child_backup_download_audit',
			function ( $entry ) use ( &$captured ) {
				$captured[] = $entry;
			}
		);

		$auth = $instance->authorize_download( str_repeat( 'a', 32 ), 'backup-unknown.zip' );

		$this->assertFalse( $auth['ok'] );
		$this->assertSame( 401, $auth['status'] );
		$this->assertCount( 1, $captured );
		$this->assertSame( 'download_rejected', $captured[0]['event'] );
		$this->assertSame( MainWP_Backup_Error::ERR_DL_TOKEN_INVALID, $captured[0]['reason'] );
	}

	/**
	 * A consumed token is rejected on authorization with a 401.
	 */
	public function test_authorize_download_consumed_token_rejected() {
		$this->create_artifact( 'backup-authz-used.zip' );
		$instance = MainWP_Child_Backup_Download_Token::instance();
		$issued   = $instance->issue( 'backup-authz-used.zip' );

		$first  = $instance->authorize_download( $issued['token'], 'backup-authz-used.zip' );
		$second = $instance->authorize_download( $issued['token'], 'backup-authz-used.zip' );

		$this->assertTrue( $first['ok'] );
		$this->assertFalse( $second['ok'] );
		$this->assertSame( 401, $second['status'] );
	}

	/**
	 * Requests without a token never consume an issued token: a malformed
	 * or empty token is rejected before the transient store is touched, so
	 * an old Dashboard downloading over the token-less path cannot burn a
	 * token another Dashboard requested.
	 */
	public function test_tokenless_requests_leave_issued_tokens_intact() {
		$this->create_artifact( 'backup-untouched.zip' );
		$instance = MainWP_Child_Backup_Download_Token::instance();
		$issued   = $instance->issue( 'backup-untouched.zip' );

		$instance->validate_and_consume( '', 'backup-untouched.zip' );
		$instance->validate_and_consume( 'not-a-real-token', 'backup-untouched.zip' );
		$instance->authorize_download( str_repeat( 'x', 10 ), 'backup-untouched.zip' );

		$this->assertIsArray( get_transient( 'mainwp_backup_download_' . $issued['token'] ), 'The issued token must survive unrelated rejected requests.' );

		$result = $instance->validate_and_consume( $issued['token'], 'backup-untouched.zip' );
		$this->assertTrue( $result['ok'] );
	}

	/**
	 * The preflight readiness report advertises download token support so
	 * the Dashboard can detect it.
	 */
	public function test_preflight_advertises_download_token() {
		$report = \MainWP\Child\MainWP_Backup::get()->check_backup_readiness();
		$this->assertSame( 1, $report['download_token'] );
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
