<?php
/**
 * Class ConnectLoginTest
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Connect;

/**
 * Dashboard-initiated auto-login (SiteOpen): the email-style username
 * fallback in login() and the non-silent parse_login_required() bail.
 *
 * The production shape this guards: a dashboard connected with an
 * email-style admin username signs its SiteOpen jump with that email;
 * the child resolved users only by user_login and swallowed the failure,
 * so the front page rendered with zero signal that the jump failed.
 */
class ConnectLoginTest extends WP_UnitTestCase {

	/**
	 * Superglobals to restore.
	 *
	 * @var array
	 */
	protected $globals_backup;

	/**
	 * Snapshot superglobals.
	 */
	public function set_up() {
		parent::set_up();
		$this->globals_backup = array( $_REQUEST, $_POST, $_GET );
	}

	/**
	 * Restore superglobals and connection options.
	 */
	public function tear_down() {
		list( $_REQUEST, $_POST, $_GET ) = $this->globals_backup;
		wp_set_current_user( 0 );
		delete_option( 'mainwp_child_pubkey' );
		delete_option( 'mainwp_child_openssl_sign_algo' );
		parent::tear_down();
	}

	/**
	 * An administrator whose user_login differs from the email.
	 *
	 * @return WP_User
	 */
	private function make_admin() {
		return self::factory()->user->create_and_get(
			array(
				'role'       => 'administrator',
				'user_login' => 'siteadmin',
				'user_email' => 'contact@example.com',
			)
		);
	}

	/**
	 * Store a fresh RSA pubkey as the connection key and sign the given
	 * function+nonce the way the dashboard does.
	 *
	 * @param string $func  Function/where value the child will verify.
	 * @param string $nonce Request nonce.
	 *
	 * @return string Base64 signature for mainwpsignature.
	 */
	private function sign_request( $func, $nonce ) {
		$keys = openssl_pkey_new(
			array(
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);
		$details = openssl_pkey_get_details( $keys );
		update_option( 'mainwp_child_pubkey', base64_encode( $details['key'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- key storage format the child expects.
		openssl_sign( $func . $nonce, $signature, $keys );
		return base64_encode( $signature ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- wire format.
	}

	/**
	 * login() resolves an email-style username through the email lookup
	 * when no account has it as a literal user_login, exactly as WP core
	 * login does.
	 */
	public function test_login_resolves_email_style_username() {
		$admin = $this->make_admin();

		$this->assertTrue( MainWP_Connect::instance()->login( 'contact@example.com' ) );
		$this->assertSame( $admin->ID, get_current_user_id() );
	}

	/**
	 * An email that matches no account still fails.
	 */
	public function test_login_unknown_email_fails() {
		$this->make_admin();

		$this->assertFalse( MainWP_Connect::instance()->login( 'ghost@example.com' ) );
		$this->assertSame( 0, get_current_user_id() );
	}

	/**
	 * The plain user_login path is unchanged.
	 */
	public function test_login_plain_username_unchanged() {
		$admin = $this->make_admin();

		$this->assertTrue( MainWP_Connect::instance()->login( 'siteadmin' ) );
		$this->assertSame( $admin->ID, get_current_user_id() );
	}

	/**
	 * A signature-valid login_required jump whose user cannot be resolved
	 * must die loudly - the request provably came from the paired
	 * dashboard, and rendering the front page hides the failure.
	 */
	public function test_login_required_bails_loud_when_user_unresolvable() {
		$_REQUEST['user']            = 'ghost@example.com';
		$_REQUEST['where']           = 'admin.php';
		$_REQUEST['nonce']           = '123456';
		$_REQUEST['mainwpsignature'] = $this->sign_request( 'admin.php', '123456' );

		$this->expectException( 'WPDieException' );
		$this->expectExceptionMessageMatches( '/was not found/' );
		MainWP_Connect::instance()->parse_login_required();
	}

	/**
	 * A signature-valid jump that resolves to a non-administrator must
	 * also die loudly after the session is torn down (the site-73 shape).
	 */
	public function test_login_required_bails_loud_for_non_admin_user() {
		self::factory()->user->create(
			array(
				'role'       => 'subscriber',
				'user_login' => 'bogdan',
			)
		);

		$_REQUEST['user']            = 'bogdan';
		$_REQUEST['where']           = 'admin.php';
		$_REQUEST['nonce']           = '123456';
		$_REQUEST['mainwpsignature'] = $this->sign_request( 'admin.php', '123456' );

		try {
			MainWP_Connect::instance()->parse_login_required();
			$this->fail( 'A non-admin login_required jump must not return normally.' );
		} catch ( WPDieException $e ) {
			$this->assertStringContainsString( 'not an administrator', $e->getMessage() );
		}
	}

	/**
	 * An invalid signature keeps the current silent false: an arbitrary
	 * probe must learn nothing it cannot already see.
	 */
	public function test_login_required_stays_silent_on_invalid_signature() {
		$this->make_admin();
		$this->sign_request( 'admin.php', '123456' );

		$_REQUEST['user']            = 'contact@example.com';
		$_REQUEST['where']           = 'admin.php';
		$_REQUEST['nonce']           = '123456';
		$_REQUEST['mainwpsignature'] = base64_encode( 'not-a-signature' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- wire format.

		$this->assertFalse( MainWP_Connect::instance()->parse_login_required() );
	}
}
