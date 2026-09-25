<?php
/**
 * Class BackupTokenEnforcementTest
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Child_Backup_Download_Token;
use MainWP\Child\MainWP_Helper;

/**
 * Tests for the download-token enforcement ratchet: once the connected
 * dashboard proves it sends tokens (a successful token-authorized
 * download), the child permanently refuses tokenless backup downloads.
 */
class BackupTokenEnforcementTest extends WP_UnitTestCase {

	/**
	 * Files created in the backup directory during a test.
	 *
	 * @var string[]
	 */
	protected $artifacts = array();

	/**
	 * Reset the ratchet option and remove artifacts.
	 */
	public function tear_down() {
		delete_option( MainWP_Child_Backup_Download_Token::OPTION_REQUIRE_TOKEN );
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
	 * @param string $name File name.
	 *
	 * @return string Full path.
	 */
	protected function create_artifact( $name ) {
		$dirs = MainWP_Helper::get_mainwp_dir( 'backup' );
		$path = $dirs[0] . $name;
		file_put_contents( $path, 'enforcement-bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
		$this->artifacts[] = $path;
		return $path;
	}

	/**
	 * Tokens are not required on a fresh site - the tokenless legacy
	 * path keeps working until the dashboard proves token support.
	 */
	public function test_token_not_required_by_default() {
		$this->assertFalse( MainWP_Child_Backup_Download_Token::instance()->is_token_required() );
	}

	/**
	 * The stored option drives the answer.
	 */
	public function test_token_required_when_option_set() {
		update_option( MainWP_Child_Backup_Download_Token::OPTION_REQUIRE_TOKEN, 1 );
		$this->assertTrue( MainWP_Child_Backup_Download_Token::instance()->is_token_required() );

		update_option( MainWP_Child_Backup_Download_Token::OPTION_REQUIRE_TOKEN, 0 );
		$this->assertFalse( MainWP_Child_Backup_Download_Token::instance()->is_token_required() );
	}

	/**
	 * The ratchet clicks on the first successful token-authorized
	 * download: only one dashboard can be connected, and it just proved
	 * it sends tokens, so the tokenless path has no caller left.
	 */
	public function test_successful_authorize_sets_token_required() {
		$this->create_artifact( 'backup-ratchet-ok.zip' );
		$instance = MainWP_Child_Backup_Download_Token::instance();
		$issued   = $instance->issue( 'backup-ratchet-ok.zip' );

		$auth = $instance->authorize_download( $issued['token'], 'backup-ratchet-ok.zip' );

		$this->assertTrue( $auth['ok'] );
		$this->assertTrue( $instance->is_token_required() );
	}

	/**
	 * A rejected token must not ratchet - anyone can send a garbage
	 * token, and that proves nothing about the dashboard.
	 */
	public function test_failed_authorize_does_not_set_token_required() {
		$instance = MainWP_Child_Backup_Download_Token::instance();

		$auth = $instance->authorize_download( str_repeat( 'a', 32 ), 'backup-ratchet-no.zip' );

		$this->assertFalse( $auth['ok'] );
		$this->assertFalse( $instance->is_token_required() );
	}
}
