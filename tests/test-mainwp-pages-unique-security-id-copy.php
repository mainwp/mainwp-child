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
}
