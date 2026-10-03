<?php
/**
 * Nonce minted during one-click login must match the new session.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Connect;

/**
 * Fresh-login nonce session tests.
 */
class Test_MainWP_Connect_Fresh_Login_Nonce extends WP_UnitTestCase {

	/**
	 * Connect instance under test.
	 *
	 * @var MainWP_Connect
	 */
	private $connect;

	/**
	 * Logged-in cookie name.
	 *
	 * @var string
	 */
	private $logged_in_cookie;

	/**
	 * Start each test logged out, with no auth cookies in the request.
	 */
	public function setUp(): void {
		parent::setUp();

		add_filter( 'send_auth_cookies', '__return_false' );

		$this->logged_in_cookie = LOGGED_IN_COOKIE;
		unset( $_COOKIE[ $this->logged_in_cookie ], $_COOKIE[ AUTH_COOKIE ], $_COOKIE[ SECURE_AUTH_COOKIE ] );
		wp_set_current_user( 0 );

		MainWP_Connect::$instance = null;
		$this->connect            = MainWP_Connect::instance();
	}

	/**
	 * Drop the singleton so later tests do not keep this request's cookie.
	 */
	public function tearDown(): void {
		unset( $_COOKIE[ $this->logged_in_cookie ], $_COOKIE[ AUTH_COOKIE ], $_COOKIE[ SECURE_AUTH_COOKIE ] );
		MainWP_Connect::$instance = null;
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * A nonce created after login in this request verifies for the new session.
	 *
	 * wp_set_auth_cookie() does not fill $_COOKIE. The follow-up browser request
	 * sends the new logged-in cookie, so the nonce has to be tied to that token.
	 */
	public function test_nonce_after_login_verifies_for_the_new_session_token() {
		$user   = $this->factory()->user->create_and_get(
			array(
				'role' => 'administrator',
			)
		);
		$logged = $this->connect->login( $user->user_login );

		$this->assertTrue( $logged );
		$this->assertArrayNotHasKey( $this->logged_in_cookie, $_COOKIE );

		$issued = $this->fresh_logged_in_cookie();
		$this->assertNotSame( '', $issued );

		$download_nonce      = $this->create_nonce( 'updraftplus_download' );
		$ajax_nonce          = $this->create_nonce( 'wp-ajax' );
		$empty_session_nonce = wp_create_nonce( 'updraftplus_download' );

		$this->assertArrayNotHasKey( $this->logged_in_cookie, $_COOKIE );

		$_COOKIE[ $this->logged_in_cookie ] = $issued;

		$this->assertSame( 1, wp_verify_nonce( $download_nonce, 'updraftplus_download' ) );
		$this->assertSame( 1, wp_verify_nonce( $ajax_nonce, 'wp-ajax' ) );
		$this->assertFalse( wp_verify_nonce( $empty_session_nonce, 'updraftplus_download' ) );
	}

	/**
	 * An existing logged-in cookie is restored after the nonce is created.
	 */
	public function test_existing_cookie_is_restored_after_nonce_creation() {
		$user = $this->factory()->user->create_and_get(
			array(
				'role' => 'administrator',
			)
		);
		$this->assertTrue( $this->connect->login( $user->user_login ) );

		$previous                           = 'previous-logged-in-cookie';
		$_COOKIE[ $this->logged_in_cookie ] = $previous;
		$nonce                              = $this->create_nonce( 'updraftplus_download' );

		$this->assertSame( $previous, $_COOKIE[ $this->logged_in_cookie ] );

		$_COOKIE[ $this->logged_in_cookie ] = $this->fresh_logged_in_cookie();
		$this->assertSame( 1, wp_verify_nonce( $nonce, 'updraftplus_download' ) );
	}

	/**
	 * When this request did not log the user in, nonce creation leaves the current cookie alone.
	 */
	public function test_nonce_without_fresh_login_keeps_the_current_session() {
		$user = $this->factory()->user->create_and_get(
			array(
				'role' => 'administrator',
			)
		);
		$issued = '';
		add_action(
			'set_logged_in_cookie',
			static function ( $cookie ) use ( &$issued ) {
				$issued = $cookie;
			}
		);
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID );
		$_COOKIE[ $this->logged_in_cookie ] = $issued;

		MainWP_Connect::$instance = null;
		$this->connect            = MainWP_Connect::instance();

		$nonce = $this->create_nonce( 'wp-ajax' );

		$this->assertSame( $issued, $_COOKIE[ $this->logged_in_cookie ] );
		$this->assertSame( 1, wp_verify_nonce( $nonce, 'wp-ajax' ) );
	}

	/**
	 * Read the logged-in cookie captured for this request.
	 *
	 * @return string
	 */
	private function fresh_logged_in_cookie() {
		$property = new ReflectionProperty( MainWP_Connect::class, 'fresh_logged_in_cookie' );
		$property->setAccessible( true );

		return $property->getValue( $this->connect );
	}

	/**
	 * Create a nonce the same way the one-click redirect does.
	 *
	 * @param string $action Nonce action.
	 * @return string
	 */
	private function create_nonce( $action ) {
		$method = new ReflectionMethod( MainWP_Connect::class, 'create_nonce_for_fresh_login' );
		$method->setAccessible( true );

		return $method->invoke( $this->connect, $action );
	}
}
