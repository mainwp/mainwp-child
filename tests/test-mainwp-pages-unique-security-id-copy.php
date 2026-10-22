<?php
/**
 * Unique Security ID settings copy and save behavior.
 *
 * @package MainWP_Child
 */

namespace MainWP\Child;

use WP_UnitTestCase;

/**
 * Confirms Settings copy matches generate / preserve / regenerate behavior.
 */
class Test_MainWP_Pages_Unique_Security_Id_Copy extends WP_UnitTestCase {

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Set up an administrator and a clean Unique Security ID option.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->user_id );
		delete_option( 'mainwp_child_uniqueId' );
		delete_user_option( $this->user_id, 'mainwp_child_user_enable_passwd_auth_connect' );
		// Returning false prevents redirect headers and request termination while testing saved settings.
		add_filter( 'wp_redirect', '__return_false' );
	}

	/**
	 * Reset request state and Unique Security ID option.
	 */
	public function tear_down(): void {
		unset( $_POST['submit'], $_POST['nonce'], $_POST['requireUniqueSecurityId'], $_POST['mainwp_child_user_enable_pwd_auth_connect'], $_POST['mainwp_child_active_time_for_unconnected_site'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		delete_option( 'mainwp_child_uniqueId' );
		delete_user_option( $this->user_id, 'mainwp_child_user_enable_passwd_auth_connect' );
		parent::tear_down();
	}

	/**
	 * Settings copy explains create, regenerate, and password vs ID.
	 */
	public function test_settings_copy_explains_create_regenerate_and_auth_methods() {
		ob_start();
		MainWP_Pages::get_instance()->render_settings();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'The Unique Security ID is generated automatically when you enable this option and save settings.', $output );
		$this->assertStringContainsString( 'Saving settings again keeps the same ID.', $output );
		$this->assertStringContainsString( 'To generate a new Unique Security ID, turn this option off and save settings, then turn it back on and save again.', $output );
		$this->assertStringContainsString( 'If Password Authentication is disabled, enable it first so Unique Security ID can be turned off.', $output );
		$this->assertStringContainsString( 'your MainWP Dashboard may connect with an administrator password only, this Unique Security ID only, or both.', $output );
		$this->assertStringNotContainsString( 'Your unique security ID is:', $output );
	}

	/**
	 * Displayed ID copy states that saving does not mint a new value.
	 */
	public function test_displayed_id_copy_says_save_does_not_regenerate() {
		update_option( 'mainwp_child_uniqueId', 'testid123456' );

		ob_start();
		MainWP_Pages::get_instance()->render_settings();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Your unique security ID is:', $output );
		$this->assertStringContainsString( 'testid123456', $output );
		$this->assertStringContainsString( 'Saving settings does not generate a new ID.', $output );
	}

	/**
	 * Enabling Unique Security ID and saving generates an ID once; later saves keep it.
	 */
	public function test_saving_required_unique_id_generates_once_and_preserves() {
		$this->post_settings(
			array(
				'requireUniqueSecurityId'                   => 'on',
				'mainwp_child_user_enable_pwd_auth_connect' => '1',
			)
		);

		MainWP_Pages::get_instance()->admin_init();
		$first = get_option( 'mainwp_child_uniqueId' );
		$this->assertNotEmpty( $first );
		$this->assertSame( 12, strlen( $first ) );

		MainWP_Pages::get_instance()->admin_init();
		$this->assertSame( $first, get_option( 'mainwp_child_uniqueId' ) );
	}

	/**
	 * Turning Unique Security ID off then on generates a new ID.
	 */
	public function test_toggling_unique_id_off_and_on_generates_a_new_id() {
		update_option( 'mainwp_child_uniqueId', 'olduniqueid1' );

		$this->post_settings(
			array(
				'mainwp_child_user_enable_pwd_auth_connect' => '1',
			)
		);
		MainWP_Pages::get_instance()->admin_init();
		$this->assertSame( '', get_option( 'mainwp_child_uniqueId' ) );

		$this->post_settings(
			array(
				'requireUniqueSecurityId'                   => 'on',
				'mainwp_child_user_enable_pwd_auth_connect' => '1',
			)
		);
		MainWP_Pages::get_instance()->admin_init();

		$regenerated = get_option( 'mainwp_child_uniqueId' );
		$this->assertNotEmpty( $regenerated );
		$this->assertNotSame( 'olduniqueid1', $regenerated );
	}

	/**
	 * Password Authentication off still generates an ID when Unique Security ID is not posted.
	 */
	public function test_passwordless_save_generates_unique_id_without_checkbox() {
		$this->post_settings( array() );

		MainWP_Pages::get_instance()->admin_init();
		$this->assertNotEmpty( get_option( 'mainwp_child_uniqueId' ) );
	}

	/**
	 * Populate a valid Settings POST payload.
	 *
	 * @param array $extra Extra POST fields.
	 */
	private function post_settings( $extra ) {
		$_POST = array_merge(
			array(
				'submit' => '1',
				'nonce'  => wp_create_nonce( 'child-settings' ),
			),
			$extra
		);
	}
	public static function data_successful_redirects() {
		return array(
			'disconnect'     => array( 'disconnect', 'true' ),
			'settings'       => array( 'settings', 'true' ),
			'tools'          => array( 'tools', 'true' ),
			'tools-canceled' => array( 'tools', 'false' ),
		);
	}

	/** @dataProvider data_successful_redirects */
	public function test_successful_redirect_ends_request( $case, $redirected ) {
		$code = <<<'CODE'
namespace MainWP\Child;
class MainWP_Helper {
    public static function get_site_unique_id() { return 'id'; }
    public static function update_option( $key, $value ) {}
}
class MainWP_Child_Branding {
    public static function instance() { return new self(); }
    public function get_branding_options() { return array( 'remove_wp_tools' => true, 'cancelled_branding' => false, 'remove_setting' => true, 'remove_restore' => true, 'remove_server_info' => true ); }
}
function wp_safe_redirect( $url ) { echo 'redirect'; return REDIRECTED; }
function wp_verify_nonce( $nonce, $action ) { return true; }
function sanitize_text_field( $value ) { return $value; }
function wp_unslash( $value ) { return $value; }
function delete_option( $key ) {}
function update_user_option( $id, $key, $value ) {}
function get_current_user_id() { return 1; }
function remove_menu_page( $page ) {}
function esc_url_raw( $url ) { return $url; }
function get_option( $key ) { return 'https://example.org'; }
CODE;
		$code .= "\ndefine( 'REDIRECTED', " . $redirected . " );\ndefine( 'ABSPATH', '/' );\nrequire " . var_export( dirname( __DIR__ ) . '/class/class-mainwp-pages.php', true ) . ";\n";
		$code .= '$pages = (new \\ReflectionClass( MainWP_Pages::class ))->newInstanceWithoutConstructor();' . "\n";
		if ( 'disconnect' === $case ) {
			$code .= '$mainWPChild = new class { public function delete_connection_data( $reset ) {} }; $_POST = array( "nonce-disconnect" => "valid" ); $pages->admin_init();';
		} elseif ( 'settings' === $case ) {
			$code .= '$_POST = array( "submit" => 1, "nonce" => "valid", "mainwp_child_user_enable_pwd_auth_connect" => 1 ); $pages->admin_init();';
		} else {
			$code .= '$_SERVER["REQUEST_URI"] = "/wp-admin/tools.php"; $pages->admin_menu();';
		}
		$code .= 'echo "continued";';
		$process = proc_open( array( PHP_BINARY, '-r', $code ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$this->assertIsResource( $process );
		$output = stream_get_contents( $pipes[1] );
		$errors = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$this->assertSame( 0, proc_close( $process ), $errors );
		$this->assertSame( 'redirect', $output );
	}

}
