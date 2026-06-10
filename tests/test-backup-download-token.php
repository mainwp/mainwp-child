<?php
/**
 * Class BackupDownloadTokenTest
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Backup_Error;

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
}
