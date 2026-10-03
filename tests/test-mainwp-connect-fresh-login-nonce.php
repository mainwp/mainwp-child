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
	 * Both redirect placeholders mint a nonce for the session just issued.
	 *
	 * Reverting either open_location_redirect() call site to wp_create_nonce()
	 * leaves the nonce on the empty session, so verification against the new
	 * cookie fails.
	 */
	public function test_open_location_redirect_nonces_match_the_new_session() {
		$user = $this->factory()->user->create_and_get(
			array(
				'role' => 'administrator',
			)
		);
		$this->assertTrue( $this->connect->login( $user->user_login ) );

		$issued = $this->fresh_logged_in_cookie();
		$this->assertNotSame( '', $issued );
		$this->assertArrayNotHasKey( $this->logged_in_cookie, $_COOKIE );

		$download_nonce = $this->redirect_query_value(
			'admin-ajax.php?action=updraft_download_backup&stage=2&type=db&_mwpNoneName=_wpnonce&_mwpNoneValue=updraftplus_download',
			'_wpnonce'
		);
		$ajax_nonce     = $this->redirect_query_value(
			'?_wfsf=viewActivityLog&nonce=child_temp_nonce',
			'nonce'
		);

		$this->assertArrayNotHasKey( $this->logged_in_cookie, $_COOKIE );
		$_COOKIE[ $this->logged_in_cookie ] = $issued;

		$this->assertSame( 1, wp_verify_nonce( $download_nonce, 'updraftplus_download' ) );
		$this->assertSame( 1, wp_verify_nonce( $ajax_nonce, 'wp-ajax' ) );

		unset( $_COOKIE[ $this->logged_in_cookie ] );
		$this->assertFalse( wp_verify_nonce( $download_nonce, 'updraftplus_download' ) );
		$this->assertFalse( wp_verify_nonce( $ajax_nonce, 'wp-ajax' ) );
	}

	/**
	 * A thrown wp_create_nonce() still restores the logged-in cookie.
	 */
	public function test_logged_in_cookie_is_restored_when_nonce_creation_throws() {
		$user = $this->factory()->user->create_and_get(
			array(
				'role' => 'administrator',
			)
		);
		$this->assertTrue( $this->connect->login( $user->user_login ) );
		unset( $_COOKIE[ $this->logged_in_cookie ] );

		$throw = static function () {
			throw new RuntimeException( 'nonce failed' );
		};
		add_filter( 'nonce_life', $throw );

		try {
			try {
				$this->create_nonce( 'updraftplus_download' );
				$this->fail( 'Expected nonce creation to throw.' );
			} catch ( RuntimeException $ex ) {
				$this->assertSame( 'nonce failed', $ex->getMessage() );
			}
			$this->assertArrayNotHasKey( $this->logged_in_cookie, $_COOKIE );

			$stale                              = "stale-session\x00cookie|value";
			$_COOKIE[ $this->logged_in_cookie ] = $stale;
			try {
				$this->create_nonce( 'wp-ajax' );
				$this->fail( 'Expected nonce creation to throw.' );
			} catch ( RuntimeException $ex ) {
				$this->assertSame( 'nonce failed', $ex->getMessage() );
			}
			$this->assertSame( $stale, $_COOKIE[ $this->logged_in_cookie ] );
		} finally {
			remove_filter( 'nonce_life', $throw );
		}
	}

	/**
	 * An early second login must not mint nonces for the first captured session.
	 */
	public function test_early_second_login_does_not_reuse_the_first_captured_session() {
		$user = $this->factory()->user->create_and_get(
			array(
				'role' => 'administrator',
			)
		);
		$this->assertTrue( $this->connect->login( $user->user_login ) );

		$first = $this->fresh_logged_in_cookie();
		$this->assertNotSame( '', $first );
		$listeners = $this->logged_in_cookie_listener_count();

		$expiration = time() + DAY_IN_SECONDS;
		$token      = \WP_Session_Tokens::get_instance( $user->ID )->create( $expiration );
		$auth_name  = is_ssl() ? SECURE_AUTH_COOKIE : AUTH_COOKIE;
		$auth_scheme = is_ssl() ? 'secure_auth' : 'auth';
		$current    = wp_generate_auth_cookie( $user->ID, $expiration, 'logged_in', $token );

		// login() returns early only when the auth cookie validates, not the logged-in cookie.
		$_COOKIE[ $auth_name ]              = wp_generate_auth_cookie( $user->ID, $expiration, $auth_scheme, $token );
		$_COOKIE[ $this->logged_in_cookie ] = $current;
		$this->assertNotSame( $first, $current );
		$this->assertTrue( $this->connect->login( $user->user_login ) );
		$this->assertSame( '', $this->fresh_logged_in_cookie() );
		$this->assertSame( $listeners, $this->logged_in_cookie_listener_count() );
		$this->assertSame( $current, $_COOKIE[ $this->logged_in_cookie ] );

		$nonce = $this->create_nonce( 'updraftplus_download' );

		$this->assertSame( $current, $_COOKIE[ $this->logged_in_cookie ] );
		$this->assertSame( 1, wp_verify_nonce( $nonce, 'updraftplus_download' ) );

		$_COOKIE[ $this->logged_in_cookie ] = $first;
		$this->assertFalse( wp_verify_nonce( $nonce, 'updraftplus_download' ) );
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

	/**
	 * Run open_location_redirect() and return one query value from the redirect.
	 *
	 * The wp_redirect filter throws so the method's exit() is not reached.
	 *
	 * @param string $open_location Location passed to the redirect.
	 * @param string $query_key     Query argument to return.
	 * @return string
	 */
	private function redirect_query_value( $open_location, $query_key ) {
		$location = '';
		$throw    = static function ( $redirect_location ) {
			throw new RuntimeException( $redirect_location );
		};

		add_filter( 'wp_redirect', $throw, 0 );
		try {
			$this->open_location_redirect( $open_location );
			$this->fail( 'The one-click redirect did not run.' );
		} catch ( RuntimeException $ex ) {
			$location = $ex->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $throw, 0 );
		}

		$query = wp_parse_url( $location, PHP_URL_QUERY );
		parse_str( (string) $query, $args );
		$this->assertArrayHasKey( $query_key, $args );
		$this->assertNotSame( '', $args[ $query_key ] );
		$this->assertStringNotContainsString( 'child_temp_nonce', (string) $args[ $query_key ] );

		return $args[ $query_key ];
	}

	/**
	 * Invoke the private one-click redirect.
	 *
	 * @param string $open_location Location passed to the redirect.
	 * @return void
	 */
	private function open_location_redirect( $open_location ) {
		$method = new ReflectionMethod( MainWP_Connect::class, 'open_location_redirect' );
		$method->setAccessible( true );
		$method->invoke( $this->connect, $open_location );
	}

	/**
	 * Count set_logged_in_cookie callbacks currently registered.
	 *
	 * @return int
	 */
	private function logged_in_cookie_listener_count() {
		global $wp_filter;

		if ( empty( $wp_filter['set_logged_in_cookie'] ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $wp_filter['set_logged_in_cookie']->callbacks as $group ) {
			$count += count( $group );
		}

		return $count;
	}
}
