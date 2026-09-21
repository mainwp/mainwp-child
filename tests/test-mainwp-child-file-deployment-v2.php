<?php
/**
 * File Uploader deployment protocol-v2 tests.
 *
 * @package MainWP_Child
 */

namespace MainWP\Child;

use WP_UnitTestCase;

class Test_MainWP_Child_File_Deployment_V2 extends WP_UnitTestCase {

	public function test_preflight_is_closed_read_only_and_rejects_hostile_paths() {
		$subject = new Testable_MainWP_Child_File_Deployment();
		$result  = $subject->preflight_v2( $this->preflight_request() );

		$this->assertSame( array( 'protocol', 'operation', 'ok', 'request_ref', 'relative_destination', 'destination_class', 'target_exists', 'prior_bytes', 'prior_sha256', 'prior_mode', 'writable', 'rollback_available', 'state_revision' ), array_keys( $result ) );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'wp-content/uploads/report.txt', $result['relative_destination'] );
		$this->assertFalse( $result['target_exists'] );
		$this->assertTrue( $result['rollback_available'] );

		$production                              = new MainWP_Child_File_Deployment();
		$probe                                   = $this->preflight_request();
		$probe['payload']['relative_destination'] = 'wp-content/uploads/mainwp-preflight-probe/report.txt';
		$this->assertTrue( $production->preflight_v2( $probe )['ok'] );
		$this->assertFileDoesNotExist( WP_CONTENT_DIR . '/uploads/mainwp-preflight-probe' );

		$request = $this->preflight_request();
		$request['payload']['relative_destination'] = 'wp-content/uploads/../wp-config.php';
		$this->assertSame( 'destination_forbidden', $subject->preflight_v2( $request )['code'] );
		$request['payload']['relative_destination'] = 'wp-content/plugins/acme/shell.phpfile.txt';
		$request['payload']['destination_class']     = 'plugins';
		$result                                      = $subject->preflight_v2( $request );
		$this->assertSame( 'wp-content/plugins/acme/shell.php', $result['relative_destination'] );
	}

	public function test_deploy_reserves_once_verifies_digest_and_replays_without_redispatch() {
		$subject = new Testable_MainWP_Child_File_Deployment();
		$bytes   = 'verified fixture bytes';
		$subject->gateway_bytes = $bytes;
		$preflight              = $subject->preflight_v2( $this->preflight_request() );
		$request                = $this->deploy_request( $preflight, $bytes );

		$result = $subject->deploy_v2( $request );
		$this->assertSame( array( 'protocol', 'operation', 'ok', 'request_ref', 'status', 'bytes', 'sha256', 'rollback_available', 'code' ), array_keys( $result ) );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'completed', $result['status'] );
		$this->assertSame( 1, $subject->gateway_reads );
		$this->assertSame( 1, $subject->writes );
		$this->assertSame( 1, $subject->prunes );
		$this->assertSame( $bytes, $subject->target_bytes );
		$this->assertStringNotContainsString( 'one-use-private-token', wp_json_encode( $subject->receipts ) );

		$this->assertSame( $result, $subject->deploy_v2( $request ) );
		$this->assertSame( 1, $subject->gateway_reads );
		$this->assertSame( 1, $subject->writes );
		$this->assertSame( 1, $subject->prunes );

		$status = $subject->deployment_state_v2( $this->status_request( $request['payload']['request_ref'] ) );
		$this->assertSame( $result, $status );
	}

	public function test_dispatching_receipt_is_unknown_and_never_blindly_retries() {
		$subject = new Testable_MainWP_Child_File_Deployment();
		$bytes   = 'verified fixture bytes';
		$subject->gateway_bytes = $bytes;
		$preflight              = $subject->preflight_v2( $this->preflight_request() );
		$request                = $this->deploy_request( $preflight, $bytes );
		$subject->seed_dispatching( $request );

		$result = $subject->deploy_v2( $request );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'unknown', $result['status'] );
		$this->assertSame( 'outcome_unknown', $result['code'] );
		$this->assertSame( 0, $subject->gateway_reads );
		$this->assertSame( 'dispatching', $subject->receipts[ $request['payload']['request_ref'] ]['state'] );
		$this->assertNull( $subject->receipts[ $request['payload']['request_ref'] ]['result'] );

		$status = $subject->deployment_state_v2( $this->status_request( $request['payload']['request_ref'] ) );
		$this->assertSame( 'unknown', $status['status'] );
		$this->assertSame( 0, $subject->gateway_reads );
	}

	/**
	 * The uploads lane is web-served, so a deploy of server-executed or server-config content
	 * there is refused at the write. Every entry below reaches the guard (preflight is not
	 * guarded, and the value survives the deploy-time equality check unchanged) and is refused
	 * before any gateway read, write, or receipt.
	 */
	public function test_uploads_lane_refuses_executable_and_config_targets_at_deploy() {
		foreach ( array(
			'wp-content/uploads/shell.php',
			'wp-content/uploads/shell.PHP',
			'wp-content/uploads/evil.phtml',
			'wp-content/uploads/legacy.pht',
			'wp-content/uploads/app.phar',
			'wp-content/uploads/src.phps',
			'wp-content/uploads/payload.shtml',
			'wp-content/uploads/payload.shtml.jpg',
			'wp-content/uploads/shell.php85',
			'wp-content/uploads/shell.php56',
			'wp-content/uploads/shell.aspx',
			'wp-content/uploads/shell.asp',
			'wp-content/uploads/handler.ashx',
			'wp-content/uploads/shell.php.jpg',
			'wp-content/uploads/shell.php.',
			'wp-content/uploads/report.txt ',
			'wp-content/uploads/.htaccess',
			'wp-content/uploads/.user.ini',
			'wp-content/uploads/web.config',
		) as $target ) {
			$subject                = new Testable_MainWP_Child_File_Deployment();
			$bytes                  = 'verified fixture bytes';
			$subject->gateway_bytes = $bytes;
			$preflight              = $subject->preflight_v2( $this->preflight_request( 'uploads', $target ) );
			$this->assertTrue( $preflight['ok'], $target . ' preflight must not be guarded' );

			$result = $subject->deploy_v2( $this->deploy_request( $preflight, $bytes ) );
			$this->assertFalse( $result['ok'], $target );
			$this->assertSame( 'destination_forbidden', $result['code'], $target );
			$this->assertSame( 0, $subject->gateway_reads, $target );
			$this->assertSame( 0, $subject->writes, $target );
			$this->assertSame( array(), $subject->receipts, $target );
		}
	}

	/** A non-executable document in the uploads lane deploys unaffected by the guard. */
	public function test_uploads_lane_allows_a_benign_document() {
		$subject                = new Testable_MainWP_Child_File_Deployment();
		$bytes                  = 'verified fixture bytes';
		$subject->gateway_bytes = $bytes;
		$preflight              = $subject->preflight_v2( $this->preflight_request( 'uploads', 'wp-content/uploads/quarterly.pdf' ) );

		$result = $subject->deploy_v2( $this->deploy_request( $preflight, $bytes ) );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'completed', $result['status'] );
		$this->assertSame( 1, $subject->writes );
	}

	/**
	 * The languages lane legitimately receives WP 6.5+ performant-translation PHP
	 * (wp-content/languages/**\/*.l10n.php), so the guard is scoped to uploads and a language
	 * pack deploys normally.
	 */
	public function test_languages_lane_allows_performant_translation_php() {
		$subject                = new Testable_MainWP_Child_File_Deployment();
		$bytes                  = 'verified fixture bytes';
		$subject->gateway_bytes = $bytes;
		$preflight              = $subject->preflight_v2( $this->preflight_request( 'languages', 'wp-content/languages/plugins/akismet-es_ES.l10n.php' ) );

		$result = $subject->deploy_v2( $this->deploy_request( $preflight, $bytes ) );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'completed', $result['status'] );
		$this->assertSame( 1, $subject->writes );
	}

	/**
	 * The guard sits on the new-effect path, after an existing receipt has already replayed. A
	 * request the store already knows returns its receipt, not a fresh refusal, even for a name
	 * the guard would otherwise reject.
	 */
	public function test_replay_short_circuits_before_the_uploads_denylist() {
		$subject                = new Testable_MainWP_Child_File_Deployment();
		$bytes                  = 'verified fixture bytes';
		$subject->gateway_bytes = $bytes;
		$preflight              = $subject->preflight_v2( $this->preflight_request( 'uploads', 'wp-content/uploads/legacy.php' ) );
		$request                = $this->deploy_request( $preflight, $bytes );
		$subject->seed_dispatching( $request );

		$result = $subject->deploy_v2( $request );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'outcome_unknown', $result['code'] );
		$this->assertNotSame( 'destination_forbidden', $result['code'] );
		$this->assertSame( 0, $subject->gateway_reads );
	}

	/**
	 * The guard is deploy-only: preflight of an executable uploads target still succeeds, so the
	 * extension's pre-rollback re-preflight of a stored pre-ban .php deployment keeps working.
	 */
	public function test_preflight_does_not_apply_the_uploads_denylist() {
		$subject   = new Testable_MainWP_Child_File_Deployment();
		$preflight = $subject->preflight_v2( $this->preflight_request( 'uploads', 'wp-content/uploads/legacy.php' ) );

		$this->assertTrue( $preflight['ok'] );
		$this->assertSame( 'wp-content/uploads/legacy.php', $preflight['relative_destination'] );
	}

	public function test_destination_lock_contention_is_non_mutating() {
		$subject                 = new Testable_MainWP_Child_File_Deployment();
		$subject->lock_available = false;
		$bytes                   = 'verified fixture bytes';
		$subject->gateway_bytes  = $bytes;
		$preflight               = $subject->preflight_v2( $this->preflight_request() );
		$result                  = $subject->deploy_v2( $this->deploy_request( $preflight, $bytes ) );

		$this->assertSame( 'lock_busy', $result['code'] );
		$this->assertSame( 0, $subject->gateway_reads );
		$this->assertSame( array(), $subject->receipts );
		$this->assertSame( 0, $subject->prunes );
	}

	public function test_digest_failure_preserves_prior_target_and_settles_failed() {
		$subject               = new Testable_MainWP_Child_File_Deployment();
		$subject->target_exists = true;
		$subject->target_bytes  = 'prior bytes';
		$subject->gateway_bytes = 'wrong bytes';
		$preflight              = $subject->preflight_v2( $this->preflight_request() );
		$request                = $this->deploy_request( $preflight, 'expected bytes' );

		$result = $subject->deploy_v2( $request );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( 'digest_mismatch', $result['code'] );
		$this->assertSame( 'prior bytes', $subject->target_bytes );
		$this->assertSame( array(), $subject->backups );
		$settled = $subject->receipts[ $request['payload']['request_ref'] ];
		$this->assertSame( 'settled', $settled['state'] );
		$this->assertNull( $settled['backup_ref'] );
		$this->assertSame( 'failed', $settled['result']['status'] );
	}

	public function test_rollback_restores_prior_bytes_and_refuses_current_drift() {
		$subject               = new Testable_MainWP_Child_File_Deployment();
		$subject->target_exists = true;
		$subject->target_bytes  = 'prior bytes';
		$subject->gateway_bytes = 'deployed bytes';
		$preflight              = $subject->preflight_v2( $this->preflight_request() );
		$deploy                 = $this->deploy_request( $preflight, 'deployed bytes' );
		$this->assertTrue( $subject->deploy_v2( $deploy )['ok'] );

		$rollback = $this->rollback_request( $deploy['payload']['request_ref'], hash( 'sha256', 'deployed bytes' ) );
		$result   = $subject->rollback_v2( $rollback );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'rolled_back', $result['status'] );
		$this->assertSame( 'prior bytes', $subject->target_bytes );

		$drifted               = new Testable_MainWP_Child_File_Deployment();
		$drifted->target_exists = true;
		$drifted->target_bytes  = 'prior bytes';
		$drifted->gateway_bytes = 'deployed bytes';
		$preview                = $drifted->preflight_v2( $this->preflight_request() );
		$deploy                 = $this->deploy_request( $preview, 'deployed bytes' );
		$this->assertTrue( $drifted->deploy_v2( $deploy )['ok'] );
		$drifted->target_bytes = 'external drift';
		$result                = $drifted->rollback_v2( $this->rollback_request( $deploy['payload']['request_ref'], hash( 'sha256', 'deployed bytes' ) ) );
		$this->assertSame( 'stale_revision', $result['code'] );
		$this->assertSame( 'external drift', $drifted->target_bytes );
	}

	public function test_uuid_id_aliases_and_malformed_gateway_are_rejected() {
		$subject = new Testable_MainWP_Child_File_Deployment();
		$request = $this->preflight_request();
		$request['payload']['request_id'] = $request['payload']['request_ref'];
		unset( $request['payload']['request_ref'] );
		$this->assertSame( 'invalid_request', $subject->preflight_v2( $request )['code'] );

		$subject->gateway_bytes = 'bytes';
		$preflight              = $subject->preflight_v2( $this->preflight_request() );
		$deploy                 = $this->deploy_request( $preflight, 'bytes' );
		$deploy['payload']['gateway_url'] = 'https://attacker.example/private';
		$this->assertSame( 'gateway_rejected', $subject->deploy_v2( $deploy )['code'] );
		$this->assertSame( 0, $subject->gateway_reads );
	}

	public function test_unreadable_receipt_storage_is_not_collapsed_into_not_found() {
		$ref     = '123e4567-e89b-42d3-a456-426614174201';
		$missing = new Storage_MainWP_Child_File_Deployment();
		$broken  = new Storage_MainWP_Child_File_Deployment();
		$broken->storage_absent = false;

		$this->assertSame( 'not_found', $missing->deployment_state_v2( $this->status_request( $ref ) )['code'] );
		$this->assertSame( 'storage_unavailable', $broken->deployment_state_v2( $this->status_request( $ref ) )['code'] );
		$this->assertSame( 'not_found', $missing->rollback_v2( $this->rollback_request( $ref, hash( 'sha256', 'current' ) ) )['code'] );
		$this->assertSame( 'storage_unavailable', $broken->rollback_v2( $this->rollback_request( $ref, hash( 'sha256', 'current' ) ) )['code'] );
	}

	public function test_rollback_available_tracks_whether_a_backup_can_be_retained() {
		$path = WP_CONTENT_DIR . '/uploads/mainwp-rollback-capability.txt';
		wp_mkdir_p( dirname( $path ) );
		file_put_contents( $path, 'prior bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- Fixture byte placement.
		$request = $this->preflight_request();
		$request['payload']['relative_destination'] = 'wp-content/uploads/mainwp-rollback-capability.txt';

		$subject                         = new Snapshot_MainWP_Child_File_Deployment();
		$retainable                      = $subject->preflight_v2( $request );
		$subject->backup_storage_working = false;
		$unretainable                    = $subject->preflight_v2( $request );
		wp_delete_file( $path );

		$this->assertTrue( $retainable['target_exists'] );
		$this->assertTrue( $retainable['rollback_available'] );
		$this->assertTrue( $unretainable['target_exists'] );
		$this->assertTrue( $unretainable['writable'] );
		$this->assertFalse( $unretainable['rollback_available'] );
		$this->assertNotSame( $retainable['state_revision'], $unretainable['state_revision'] );
	}

	public function test_expired_receipt_is_never_replayed_as_live() {
		$subject                = new Testable_MainWP_Child_File_Deployment();
		$bytes                  = 'verified fixture bytes';
		$subject->gateway_bytes = $bytes;
		$preflight              = $subject->preflight_v2( $this->preflight_request() );
		$request                = $this->deploy_request( $preflight, $bytes );
		$this->assertTrue( $subject->deploy_v2( $request )['ok'] );

		$ref = $request['payload']['request_ref'];
		$subject->receipts[ $ref ]['updated_at'] = time() - 100;
		$subject->receipts[ $ref ]['expires_at'] = time() - 1;

		$this->assertSame( 'receipt_expired', $subject->deployment_state_v2( $this->status_request( $ref ) )['code'] );
		$this->assertSame( 'receipt_expired', $subject->deploy_v2( $request )['code'] );
		$this->assertSame( 'receipt_expired', $subject->rollback_v2( $this->rollback_request( $ref, hash( 'sha256', $bytes ) ) )['code'] );
		$this->assertSame( 1, $subject->writes );
		$this->assertSame( 1, $subject->gateway_reads );
	}

	/**
	 * Rollback reads its deployment before it can take the destination lock, and a deployment to
	 * any other destination prunes the whole store under a different lock. When that prune lands
	 * in between, the rollback must say so instead of reporting an unknown outcome for a target
	 * it never touched.
	 */
	public function test_rollback_reports_a_pruned_deployment_instead_of_an_unknown_outcome() {
		$destination = 'wp-content/uploads/mainwp-prune-race.txt';
		$subject     = new Store_MainWP_Child_File_Deployment();
		wp_mkdir_p( WP_CONTENT_DIR . '/uploads' );
		file_put_contents( WP_CONTENT_DIR . '/uploads/mainwp-prune-race.txt', 'prior bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- Fixture byte placement.

		$preflight = $this->preflight_request();
		$preflight['payload']['relative_destination'] = $destination;
		$preflight                                    = $subject->preflight_v2( $preflight );
		$this->assertTrue( $preflight['ok'] );

		$subject->gateway_bytes = 'deployed bytes';
		$deploy                 = $this->deploy_request( $preflight, 'deployed bytes' );
		$this->assertTrue( $subject->deploy_v2( $deploy )['ok'] );

		// A deployment to another destination sweeps the store between this rollback reading the
		// deployment and holding anything: the settled deployment is past retention, its backup
		// is old, and no marker binds either.
		$subject->prune_before_reservation = $deploy['payload']['request_ref'];

		$result = $subject->rollback_v2( $this->rollback_request( $deploy['payload']['request_ref'], hash( 'sha256', 'deployed bytes' ) ) );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( 'not_found', $result['code'] );
		$this->assertSame( 'deployed bytes', file_get_contents( WP_CONTENT_DIR . '/uploads/mainwp-prune-race.txt' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- Fixture readback.

		$subject->clean_store();
		wp_delete_file( WP_CONTENT_DIR . '/uploads/mainwp-prune-race.txt' );
	}

	/**
	 * Rechecking the deployment cannot protect the backup on its own: a sweep running for another
	 * destination decides what is still bound from a file list taken before this rollback reserved
	 * anything, so it can drop the backup after the recheck passed and before the restore reads it.
	 * The rollback holds the store shared for exactly that span, so the sweep cannot run at all.
	 */
	public function test_rollback_keeps_its_backup_from_a_sweep_that_lands_after_the_recheck() {
		$target  = WP_CONTENT_DIR . '/uploads/mainwp-sweep-race.txt';
		$subject = new Store_MainWP_Child_File_Deployment();
		wp_mkdir_p( WP_CONTENT_DIR . '/uploads' );
		file_put_contents( $target, 'prior bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- Fixture byte placement.

		$preflight = $this->preflight_request();
		$preflight['payload']['relative_destination'] = 'wp-content/uploads/mainwp-sweep-race.txt';
		$preflight                                    = $subject->preflight_v2( $preflight );
		$this->assertTrue( $preflight['ok'] );

		$subject->gateway_bytes = 'deployed bytes';
		$deploy                 = $this->deploy_request( $preflight, 'deployed bytes' );
		$this->assertTrue( $subject->deploy_v2( $deploy )['ok'] );

		$subject->prune_before_restore = $deploy['payload']['request_ref'];

		$result = $subject->rollback_v2( $this->rollback_request( $deploy['payload']['request_ref'], hash( 'sha256', 'deployed bytes' ) ) );

		$this->assertTrue( $result['ok'], wp_json_encode( $result ) );
		$this->assertSame( 'rolled_back', $result['status'] );
		$this->assertSame( 'prior bytes', file_get_contents( $target ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- Fixture readback.

		$subject->clean_store();
		wp_delete_file( $target );
	}

	/**
	 * A backslash segment clears the prefix check and hides from every '/' segment rule, so it has
	 * to be rejected by name. On Windows it is a path separator and this destination leaves the
	 * uploads tree entirely.
	 */
	public function test_backslash_traversal_is_refused_by_preflight_and_deploy() {
		$subject  = new Testable_MainWP_Child_File_Deployment();
		$hostile  = 'wp-content/uploads/..\\..\\..\\wp-config.php';

		$request = $this->preflight_request();
		$request['payload']['relative_destination'] = $hostile;
		$preflight = $subject->preflight_v2( $request );

		$this->assertFalse( $preflight['ok'] );
		$this->assertSame( 'destination_forbidden', $preflight['code'] );

		$subject->gateway_bytes = 'verified fixture bytes';
		$deploy                 = $this->deploy_request( $subject->preflight_v2( $this->preflight_request() ), 'verified fixture bytes' );
		$deploy['payload']['relative_destination'] = $hostile;

		$result = $subject->deploy_v2( $deploy );
		$this->assertSame( 'destination_forbidden', $result['code'] );
		$this->assertSame( 0, $subject->gateway_reads );
		$this->assertSame( 0, $subject->writes );
		$this->assertSame( array(), $subject->receipts );
	}

	public function test_authenticated_callable_map_exposes_the_four_v2_operations() {
		$callable   = MainWP_Child_Callable::get_instance();
		$reflection = new \ReflectionClass( $callable );
		$property   = $reflection->getProperty( 'callableFunctions' );
		$property->setAccessible( true );
		$map = $property->getValue( $callable );

		$this->assertSame( 'uploader_preflight_v2', $map['uploader_preflight_v2'] );
		$this->assertSame( 'uploader_deploy_v2', $map['uploader_deploy_v2'] );
		$this->assertSame( 'uploader_deployment_state_v2', $map['uploader_deployment_state_v2'] );
		$this->assertSame( 'uploader_rollback_v2', $map['uploader_rollback_v2'] );
	}

	private function preflight_request( $destination_class = 'uploads', $relative_destination = 'wp-content/uploads/report.txt' ) {
		return array(
			'protocol'  => '2',
			'operation' => 'preflight',
			'payload'   => array(
				'request_ref'         => '123e4567-e89b-42d3-a456-426614174200',
				'destination_class'   => $destination_class,
				'relative_destination' => $relative_destination,
			),
		);
	}

	private function deploy_request( $preflight, $bytes ) {
		return array(
			'protocol'  => '2',
			'operation' => 'deploy',
			'payload'   => array(
				'request_ref'         => '123e4567-e89b-42d3-a456-426614174201',
				'object_ref'          => '123e4567-e89b-42d3-a456-426614174202',
				'destination_class'   => $preflight['destination_class'],
				'relative_destination' => $preflight['relative_destination'],
				'expected_bytes'      => strlen( $bytes ),
				'expected_sha256'     => hash( 'sha256', $bytes ),
				'gateway_url'         => 'https://dashboard.example/mainwp-file-object',
				'gateway_token'       => 'one-use-private-token',
				'state_revision'      => $preflight['state_revision'],
				'expires_at'          => time() + 300,
			),
		);
	}

	private function status_request( $request_ref ) {
		return array( 'protocol' => '2', 'operation' => 'status', 'payload' => array( 'request_ref' => $request_ref ) );
	}

	private function rollback_request( $deployment_ref, $digest ) {
		return array(
			'protocol'  => '2',
			'operation' => 'rollback',
			'payload'   => array(
				'request_ref'       => '123e4567-e89b-42d3-a456-426614174203',
				'deployment_ref'    => $deployment_ref,
				'if_current_sha256' => $digest,
				'expires_at'        => time() + 300,
			),
		);
	}
}

class Testable_MainWP_Child_File_Deployment extends MainWP_Child_File_Deployment {

	public $target_exists = false;
	public $target_bytes = '';
	public $target_mode = 0644;
	public $gateway_bytes = '';
	public $gateway_reads = 0;
	public $writes = 0;
	public $receipts = array();
	public $backups = array();
	public $lock_available = true;
	public $prunes = 0;

	protected function prune_expired_storage() {
		++$this->prunes;
	}

	protected function target_snapshot( $destination_class, $relative_destination ) {
		unset( $destination_class, $relative_destination );
		return array(
			'exists' => $this->target_exists,
			'bytes'  => $this->target_exists ? strlen( $this->target_bytes ) : null,
			'sha256' => $this->target_exists ? hash( 'sha256', $this->target_bytes ) : null,
			'mode'   => $this->target_exists ? $this->target_mode : null,
			'writable' => true,
			'rollback_available' => true,
		);
	}

	protected function gateway_allowed( $url ) {
		return 'https://dashboard.example/mainwp-file-object' === $url;
	}

	protected function download_gateway_bytes( $url, $token, $expected_bytes ) {
		unset( $url, $token, $expected_bytes );
		++$this->gateway_reads;
		return $this->gateway_bytes;
	}

	protected function apply_deployment( $destination_class, $relative_destination, $bytes, $before ) {
		unset( $destination_class, $relative_destination );
		++$this->writes;
		if ( $before['exists'] ) {
			$this->backups['fixture-backup'] = $this->target_bytes;
		}
		$this->target_exists = true;
		$this->target_bytes  = $bytes;
		return array( 'backup_ref' => $before['exists'] ? 'fixture-backup' : null );
	}

	protected function apply_rollback( $receipt ) {
		++$this->writes;
		$this->target_exists = $receipt['prior_exists'];
		$this->target_bytes  = $receipt['prior_exists'] && isset( $this->backups[ $receipt['backup_ref'] ] ) ? $this->backups[ $receipt['backup_ref'] ] : '';
		return true;
	}

	protected function load_receipt( $request_ref ) {
		return isset( $this->receipts[ $request_ref ] ) ? $this->receipts[ $request_ref ] : null;
	}

	protected function create_receipt( $request_ref, $receipt ) {
		if ( isset( $this->receipts[ $request_ref ] ) ) {
			return false;
		}
		$this->receipts[ $request_ref ] = $receipt;
		return true;
	}

	protected function settle_receipt( $request_ref, $expected, $receipt ) {
		if ( ! isset( $this->receipts[ $request_ref ] ) || $expected !== $this->receipts[ $request_ref ] ) {
			return false;
		}
		$this->receipts[ $request_ref ] = $receipt;
		return true;
	}

	protected function acquire_destination_lock( $destination_class, $relative_destination ) {
		unset( $destination_class, $relative_destination );
		return $this->lock_available;
	}

	protected function release_destination_lock( $lock ) {
		unset( $lock );
	}

	protected function acquire_store_lock( $exclusive ) {
		unset( $exclusive );
		return true;
	}

	public function seed_dispatching( $request ) {
		$this->receipts[ $request['payload']['request_ref'] ] = $this->dispatching_receipt_for_test( $request['payload'] );
	}
}

/**
 * Drive the production receipt reader against a storage root that cannot be used.
 */
class Storage_MainWP_Child_File_Deployment extends MainWP_Child_File_Deployment {

	public $storage_absent = true;

	protected function storage_root( $create ) {
		unset( $create );
		return false;
	}

	protected function storage_root_absent() {
		return $this->storage_absent;
	}
}

/**
 * Drive the real receipt, backup and pruning code against a disposable private store.
 */
class Store_MainWP_Child_File_Deployment extends MainWP_Child_File_Deployment {

	public $gateway_bytes = '';

	/** @var string|null Deployment a concurrent sweep reaches after this request read it and before it holds the store. */
	public $prune_before_reservation = null;

	/** @var string|null Deployment a concurrent sweep reaches after this rollback rechecked it and before it restores. */
	public $prune_before_restore = null;

	/** @var string */
	private $root;

	public function __construct() {
		$this->root = rtrim( get_temp_dir(), '/' ) . '/mainwp-file-deployment-' . wp_generate_password( 12, false, false );
		wp_mkdir_p( $this->root );
	}

	public function clean_store() {
		foreach ( array_diff( scandir( $this->root ), array( '.', '..' ) ) as $entry ) {
			wp_delete_file( $this->root . '/' . $entry );
		}
		rmdir( $this->root ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- Fixture store teardown.
	}

	protected function storage_root( $create ) {
		unset( $create );
		return $this->root;
	}

	protected function storage_root_absent() {
		return false;
	}

	protected function backup_storage_available() {
		return true;
	}

	protected function gateway_allowed( $url ) {
		return 'https://dashboard.example/mainwp-file-object' === $url;
	}

	protected function download_gateway_bytes( $url, $token, $expected_bytes ) {
		unset( $url, $token, $expected_bytes );
		return $this->gateway_bytes;
	}

	protected function acquire_destination_lock( $destination_class, $relative_destination ) {
		$lock = parent::acquire_destination_lock( $destination_class, $relative_destination );
		if ( false !== $lock && null !== $this->prune_before_reservation ) {
			$this->age_deployment( $this->prune_before_reservation );
			$this->prune_before_reservation = null;
			$this->prune_expired_storage();
		}
		return $lock;
	}

	protected function apply_rollback( $receipt ) {
		if ( null !== $this->prune_before_restore ) {
			$deployment_ref             = $this->prune_before_restore;
			$this->prune_before_restore = null;
			$this->sweep_without_marker( $deployment_ref );
		}
		return parent::apply_rollback( $receipt );
	}

	/**
	 * Sweep the store the way a deployment to another destination does when the file list it works
	 * from was taken before this rollback reserved anything: the live rollback marker is not in
	 * that list, so nothing the sweep sees binds the backup being restored.
	 */
	private function sweep_without_marker( $deployment_ref ) {
		$this->age_deployment( $deployment_ref );
		$unlisted = array();
		foreach ( glob( $this->root . '/receipt-*.json' ) as $path ) {
			$receipt = json_decode( file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- Fixture store read.
			if ( isset( $receipt['kind'], $receipt['state'] ) && 'rollback' === $receipt['kind'] && 'dispatching' === $receipt['state'] ) {
				$unlisted[ $path ] = $path . '.unlisted';
				rename( $path, $path . '.unlisted' );
			}
		}
		$this->prune_expired_storage();
		foreach ( $unlisted as $path => $hidden ) {
			rename( $hidden, $path );
		}
	}

	/** Put one settled deployment and its backup past retention, as the clock would. */
	private function age_deployment( $deployment_ref ) {
		$path    = $this->root . '/receipt-' . hash( 'sha256', $deployment_ref ) . '.json';
		$receipt = json_decode( file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- Fixture store rewrite.
		$backup  = $this->root . '/' . $receipt['backup_ref'];
		$receipt['updated_at'] = time() - 100;
		$receipt['expires_at'] = time() - 1;
		file_put_contents( $path, wp_json_encode( $receipt, JSON_UNESCAPED_SLASHES ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- Fixture store rewrite.
		touch( $backup, time() - MainWP_Child_File_Deployment::RECEIPT_TTL - 1 );
	}
}

/**
 * Drive the production target snapshot against a known backup-storage capability.
 */
class Snapshot_MainWP_Child_File_Deployment extends MainWP_Child_File_Deployment {

	public $backup_storage_working = true;

	protected function backup_storage_available() {
		return $this->backup_storage_working;
	}
}
