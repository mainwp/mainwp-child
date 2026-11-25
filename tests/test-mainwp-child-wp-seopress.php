<?php
/**
 * SEOPress settings import and licence reset.
 *
 * SEOPress is not loaded in the test environment: the functions the handler
 * calls are stubbed below and record what they receive.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Child_WP_Seopress;

if ( ! function_exists( 'seopress_do_import_settings' ) ) {
	/**
	 * Records the settings the handler imports.
	 *
	 * @param mixed $settings Decoded settings.
	 */
	function seopress_do_import_settings( $settings ) {
		$GLOBALS['mainwp_test_seopress_imported'][] = $settings;
	}
}

if ( ! function_exists( 'seopress_reset_pro_licence' ) ) {
	/**
	 * Records each licence reset.
	 */
	function seopress_reset_pro_licence() {
		$GLOBALS['mainwp_test_seopress_resets'][] = func_get_args();
	}
}

/**
 * Tests for MainWP_Child_WP_Seopress.
 */
class Test_MainWP_Child_WP_Seopress extends WP_UnitTestCase {

	/**
	 * Settings with the characters JSON escapes: quotes, a backslash, a slash.
	 *
	 * @var array
	 */
	private $settings = array(
		'seopress_google_analytics_option_name' => array(
			'seopress_google_analytics_other_tracking' => '<script data-id="a1">var re = /\d+/;</script>',
		),
		'seopress_titles_option_name'           => array(
			'seopress_titles_sep' => '\\',
		),
	);

	/**
	 * Resets the stubs and the request.
	 */
	public function set_up() {
		parent::set_up();
		$GLOBALS['mainwp_test_seopress_imported'] = array();
		$GLOBALS['mainwp_test_seopress_resets']   = array();
		$this->active_plugins                     = get_option( 'active_plugins' );
	}

	/**
	 * Restores the request and the active plugins.
	 */
	public function tear_down() {
		unset( $_POST['settings'] );
		update_option( 'active_plugins', $this->active_plugins );
		parent::tear_down();
	}

	/**
	 * Active plugins before the test.
	 *
	 * @var mixed
	 */
	private $active_plugins;

	/**
	 * Posts the settings the way WordPress hands them over: slashed.
	 *
	 * @param string $payload Raw request value.
	 */
	private function post_settings( $payload ) {
		$_POST['settings'] = wp_slash( $payload );
	}

	/**
	 * A plain JSON payload is imported unchanged.
	 *
	 * The extra stripslashes() used to remove the backslashes JSON needs, so
	 * any value with a quote made the payload invalid and nothing was saved.
	 */
	public function test_plain_json_is_imported_unchanged() {
		$this->post_settings( wp_json_encode( $this->settings ) );

		$result = MainWP_Child_WP_Seopress::instance()->import_settings();

		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertSame( array( $this->settings ), $GLOBALS['mainwp_test_seopress_imported'] );
	}

	/**
	 * Dashboards that add slashes to compensate keep working.
	 */
	public function test_json_with_added_slashes_is_still_imported() {
		$this->post_settings( addslashes( wp_json_encode( $this->settings ) ) );

		$result = MainWP_Child_WP_Seopress::instance()->import_settings();

		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertSame( array( $this->settings ), $GLOBALS['mainwp_test_seopress_imported'] );
	}

	/**
	 * Invalid JSON is reported instead of "Import completed".
	 */
	public function test_invalid_json_is_reported_and_nothing_is_imported() {
		$this->post_settings( '{"seopress_titles_option_name":' );

		$result = MainWP_Child_WP_Seopress::instance()->import_settings();

		$this->assertArrayHasKey( 'error', $result );
		$this->assertArrayNotHasKey( 'message', $result );
		$this->assertSame( array(), $GLOBALS['mainwp_test_seopress_imported'] );
	}

	/**
	 * Resetting the licence calls SEOPress without an undefined variable.
	 */
	public function test_reset_pro_licence_runs_without_notice() {
		update_option( 'active_plugins', array( 'wp-seopress/seopress.php', 'wp-seopress-pro/seopress-pro.php' ) );

		$result = MainWP_Child_WP_Seopress::instance()->reset_pro_licence();

		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertSame( array( array() ), $GLOBALS['mainwp_test_seopress_resets'] );
	}
}
