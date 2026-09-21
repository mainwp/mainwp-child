<?php
/**
 * Branding abilities v2 protocol tests.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Child_Branding;

/**
 * Deterministic storage and image boundary for Branding v2 tests.
 */
class Test_MainWP_Child_Branding_V2_Fixture extends MainWP_Child_Branding {

	/** @var array */
	public $stored_settings = array();

	/** @var array */
	public $stored_receipts = array();

	/** @var array */
	public $image_results = array();

	/** @var array */
	public $deleted_files = array();

	/** @var bool */
	public $settings_write_succeeds = true;

	/** @var bool */
	public $receipt_write_succeeds = true;

	/** @var string|null IS_FREE_LOCK() observed while the settings write ran. */
	public $lock_free_during_write = null;

	/**
	 * Avoid WordPress hooks and seed deterministic state.
	 *
	 * @param array $settings Current settings.
	 */
	public function __construct( $settings = array() ) {
		$this->stored_settings        = $settings;
		$this->child_branding_options = $settings;
	}

	/** @return array */
	protected function abilities_v2_read_settings() {
		return $this->stored_settings;
	}

	/**
	 * @param array $settings Settings.
	 * @return bool
	 */
	protected function abilities_v2_write_settings( $settings ) {
		global $wpdb;
		$this->lock_free_during_write = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', $this->abilities_v2_lock_name() ) );
		if ( $this->settings_write_succeeds ) {
			$this->stored_settings = $settings;
		}
		return $this->settings_write_succeeds;
	}

	/** @return string */
	public function lock_name() {
		return $this->abilities_v2_lock_name();
	}

	/** @return bool */
	public function end_lock() {
		return $this->abilities_v2_end_lock();
	}

	/** @return array */
	protected function abilities_v2_read_receipts() {
		return $this->stored_receipts;
	}

	/**
	 * @param array $receipts Receipts.
	 * @return bool
	 */
	protected function abilities_v2_write_receipts( $receipts ) {
		if ( $this->receipt_write_succeeds ) {
			$this->stored_receipts = $receipts;
		}
		return $this->receipt_write_succeeds;
	}

	/**
	 * @param string $url Image URL.
	 * @return array|false
	 */
	protected function abilities_v2_stage_image( $url ) {
		return isset( $this->image_results[ $url ] ) ? $this->image_results[ $url ] : false;
	}

	/**
	 * @param string $path File path.
	 * @return bool
	 */
	protected function abilities_v2_delete_file( $path ) {
		$this->deleted_files[] = $path;
		return true;
	}
}

/**
 * Answers the named-lock statements from a script, so every attempt is observable.
 */
class Test_MainWP_Child_Branding_V2_Lock_Wpdb {

	/** @var array Queries this substitute was asked to run. */
	public $queries = array();

	/** @var string */
	public $last_error = '';

	/** @var array One array( error, result ) per expected attempt. */
	private $answers;

	/**
	 * @param array $answers One array( error, result ) per expected attempt.
	 */
	public function __construct( $answers ) {
		$this->answers = $answers;
	}

	/**
	 * @param string $query   Query.
	 * @param mixed  ...$args Placeholder values.
	 * @return string
	 */
	public function prepare( $query, ...$args ) {
		return $query;
	}

	/**
	 * @param string $query Query.
	 * @return string|null
	 */
	public function get_var( $query ) {
		$this->queries[]  = $query;
		$answer           = array_shift( $this->answers );
		$this->last_error = $answer[0];
		return $answer[1];
	}
}

/**
 * Branding v2 contract tests.
 */
class Test_MainWP_Child_Branding_Abilities_V2 extends WP_UnitTestCase {

	/** @var string */
	private $operation_ref = '93a92620-7539-4fb0-a047-0f8f99233e06';

	/**
	 * Invoke the typed protocol handler.
	 *
	 * @param MainWP_Child_Branding $object  Fixture.
	 * @param array                 $request Request.
	 * @return array
	 */
	private function invoke_v2( $object, $request ) {
		$method = new ReflectionMethod( MainWP_Child_Branding::class, 'apply_abilities_v2' );
		$method->setAccessible( true );
		return $method->invoke( $object, $request );
	}

	/**
	 * Return a complete normalized desired settings map.
	 *
	 * @param array $changes Overrides.
	 * @return array
	 */
	private function desired_settings( $changes = array() ) {
		$settings = array(
			'child_plugin_name'                       => 'Acme Site Connector',
			'child_plugin_desc'                       => 'Managed site connector.',
			'child_plugin_author'                     => 'Acme',
			'child_plugin_author_uri'                 => 'https://example.test',
			'child_plugin_uri'                        => 'https://example.test/connector',
			'child_plugin_hide'                       => false,
			'child_show_support_button'               => false,
			'child_support_email'                     => '',
			'child_support_message'                   => '',
			'child_remove_restore'                    => false,
			'child_remove_setting'                    => false,
			'child_remove_server_info'                => false,
			'child_remove_wp_tools'                   => false,
			'child_remove_wp_setting'                 => false,
			'child_remove_permalink'                  => false,
			'child_button_contact_label'              => 'Contact Support',
			'child_send_email_message'                => '',
			'child_message_return_sender'             => '',
			'child_submit_button_title'               => 'Send',
			'child_show_support_button_in'            => 0,
			'child_global_footer'                     => '',
			'child_dashboard_footer'                  => '',
			'child_remove_widget_welcome'             => false,
			'child_remove_widget_glance'              => false,
			'child_remove_widget_activity'            => false,
			'child_remove_widget_quick'               => false,
			'child_remove_widget_news'                => false,
			'child_login_image_link'                  => '',
			'child_login_image_title'                 => '',
			'child_site_generator'                    => '',
			'child_generator_link'                    => '',
			'child_admin_css'                         => '',
			'child_login_css'                         => '',
			'child_texts_replace'                     => array(),
			'child_hide_nag'                          => false,
			'child_hide_screen_opts'                  => false,
			'child_hide_help_box'                     => false,
			'child_hide_metabox_post_excerpt'         => false,
			'child_hide_metabox_post_slug'            => false,
			'child_hide_metabox_post_tags'            => false,
			'child_hide_metabox_post_author'          => false,
			'child_hide_metabox_post_comments'        => false,
			'child_hide_metabox_post_revisions'       => false,
			'child_hide_metabox_post_discussion'      => false,
			'child_hide_metabox_post_categories'      => false,
			'child_hide_metabox_post_custom_fields'   => false,
			'child_hide_metabox_post_trackbacks'      => false,
			'child_hide_metabox_page_custom_fields'   => false,
			'child_hide_metabox_page_author'          => false,
			'child_hide_metabox_page_discussion'      => false,
			'child_hide_metabox_page_revisions'       => false,
			'child_hide_metabox_page_attributes'      => false,
			'child_hide_metabox_page_slug'            => false,
			'child_preserve_branding'                 => false,
			'child_disable_switching_theme'           => false,
			'child_disable_wp_branding'               => 'N',
			'child_login_image_url'                   => '',
			'child_favico_image_url'                  => '',
		);
		return array_replace( $settings, $changes );
	}

	/**
	 * Return a typed request.
	 *
	 * @param array  $settings      Desired settings.
	 * @param string $operation_ref Operation reference.
	 * @return array
	 */
	private function request( $settings, $operation_ref = '' ) {
		return array(
			'protocol'      => '2',
			'operation_ref' => '' === $operation_ref ? $this->operation_ref : $operation_ref,
			'settings'      => $settings,
		);
	}

	/**
	 * Closed root and setting validation rejects malformed input without effects.
	 */
	public function test_request_validation_is_closed_and_bounded() {
		$fixture = new Test_MainWP_Child_Branding_V2_Fixture();
		$result  = $this->invoke_v2( $fixture, array( 'protocol' => '2' ) );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'invalid_request', $result['error']['code'] );

		$reordered = $this->invoke_v2(
			new Test_MainWP_Child_Branding_V2_Fixture(),
			array(
				'settings'      => $this->desired_settings(),
				'operation_ref' => $this->operation_ref,
				'protocol'      => '2',
			)
		);
		$this->assertTrue( $reordered['ok'] );

		$request          = $this->request( $this->desired_settings() );
		$request['extra'] = true;
		$result           = $this->invoke_v2( $fixture, $request );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'invalid_request', $result['error']['code'] );

		$settings          = $this->desired_settings();
		$settings['extra'] = 'private';
		$result            = $this->invoke_v2( $fixture, $this->request( $settings ) );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'invalid_settings', $result['error']['code'] );

		$result = $this->invoke_v2( $fixture, $this->request( $this->desired_settings(), 'not-a-uuid' ) );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'invalid_request', $result['error']['code'] );
		$this->assertSame( array(), $fixture->stored_receipts );
	}

	/**
	 * A setting string that is not valid UTF-8 is rejected without effects.
	 *
	 * Invalid UTF-8 makes wp_json_encode() return false, which would otherwise bypass
	 * the byte cap (strlen(false)) and collapse the desired-hash conflict guard.
	 */
	public function test_apply_rejects_invalid_utf8_settings() {
		$fixture  = new Test_MainWP_Child_Branding_V2_Fixture();
		$settings = $this->desired_settings( array( 'child_plugin_name' => "Acme \xFF Connector" ) );

		$result = $this->invoke_v2( $fixture, $this->request( $settings ) );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'invalid_settings', $result['error']['code'] );
		$this->assertSame( array(), $fixture->stored_receipts );
	}

	/**
	 * A successful apply writes and verifies settings with structured output.
	 */
	public function test_apply_returns_structured_verified_result() {
		$fixture = new Test_MainWP_Child_Branding_V2_Fixture();
		$result  = $this->invoke_v2( $fixture, $this->request( $this->desired_settings() ) );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( array( 'protocol', 'operation_ref', 'ok', 'result' ), array_keys( $result ) );
		$this->assertSame( 'applied', $result['result']['state'] );
		$this->assertTrue( $result['result']['settings_saved'] );
		$this->assertSame( array( 'login' => 'removed', 'favicon' => 'removed' ), $result['result']['images'] );
		$this->assertSame( array(), $result['result']['warnings'] );
		$this->assertSame( 'Y', $fixture->stored_settings['branding_ext_enabled'] );
		$this->assertArrayHasKey( $this->operation_ref, $fixture->stored_receipts );
	}

	/**
	 * Exact receipt replay returns the terminal response and conflicts on another hash.
	 */
	public function test_receipt_replay_and_hash_conflict() {
		$fixture = new Test_MainWP_Child_Branding_V2_Fixture();
		$first   = $this->invoke_v2( $fixture, $this->request( $this->desired_settings() ) );
		$replay  = $this->invoke_v2( $fixture, $this->request( $this->desired_settings() ) );
		$this->assertSame( $first, $replay );

		$changed = $this->desired_settings( array( 'child_plugin_name' => 'Different' ) );
		$result  = $this->invoke_v2( $fixture, $this->request( $changed ) );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'request_conflict', $result['error']['code'] );
	}

	/**
	 * Reapplying the same desired state with a new reference is unchanged.
	 */
	public function test_verified_noop_is_unchanged() {
		$fixture = new Test_MainWP_Child_Branding_V2_Fixture();
		$this->invoke_v2( $fixture, $this->request( $this->desired_settings() ) );
		$result = $this->invoke_v2(
			$fixture,
			$this->request( $this->desired_settings(), 'd6dd6d28-1db0-42f6-9865-c1b60dfdd783' )
		);
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'unchanged', $result['result']['state'] );
	}

	/**
	 * One failed image remains old while other settings and image effects are exact.
	 */
	public function test_image_failure_is_partial_and_preserves_old_asset() {
		$current = array(
			'extra_settings' => array(
				'login_image'               => array( 'path' => '/old/login.png', 'url' => 'https://child.test/login.png' ),
				'favico_image'              => array( 'path' => '/old/favicon.png', 'url' => 'https://child.test/favicon.png' ),
				'abilities_v2_asset_hashes' => array( 'login' => 'old-login', 'favicon' => 'old-favicon' ),
			),
		);
		$fixture = new Test_MainWP_Child_Branding_V2_Fixture( $current );
		$fixture->image_results['https://assets.test/login.png']   = false;
		$fixture->image_results['https://assets.test/favicon.png'] = array( 'path' => '/new/favicon.png', 'url' => 'https://child.test/new-favicon.png' );
		$result = $this->invoke_v2(
			$fixture,
			$this->request(
				$this->desired_settings(
					array(
						'child_login_image_url'  => 'https://assets.test/login.png',
						'child_favico_image_url' => 'https://assets.test/favicon.png',
					)
				)
			)
		);

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'partial', $result['result']['state'] );
		$this->assertSame( array( 'login' => 'failed', 'favicon' => 'applied' ), $result['result']['images'] );
		$this->assertSame( array( 'login_image_failed' ), $result['result']['warnings'] );
		$this->assertSame( '/old/login.png', $fixture->stored_settings['extra_settings']['login_image']['path'] );
		$this->assertSame( array( '/old/favicon.png' ), $fixture->deleted_files );
	}

	/**
	 * Failed option persistence cleans staged files and creates no receipt.
	 */
	public function test_option_failure_rolls_back_staged_files() {
		$fixture = new Test_MainWP_Child_Branding_V2_Fixture();
		$fixture->settings_write_succeeds                  = false;
		$fixture->image_results['https://assets.test/login.png'] = array( 'path' => '/new/login.png', 'url' => 'https://child.test/login.png' );
		$result = $this->invoke_v2(
			$fixture,
			$this->request( $this->desired_settings( array( 'child_login_image_url' => 'https://assets.test/login.png' ) ) )
		);

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'settings_write_failed', $result['error']['code'] );
		$this->assertSame( array( '/new/login.png' ), $fixture->deleted_files );
		$this->assertSame( array(), $fixture->stored_receipts );
	}

	/**
	 * Receipt storage failure never reports a replay-safe success.
	 */
	public function test_receipt_failure_is_not_success() {
		$fixture = new Test_MainWP_Child_Branding_V2_Fixture();
		$fixture->receipt_write_succeeds = false;
		$result = $this->invoke_v2( $fixture, $this->request( $this->desired_settings() ) );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'receipt_write_failed', $result['error']['code'] );
	}

	/**
	 * Error envelopes are closed, bounded, and contain no submitted private content.
	 */
	public function test_errors_are_closed_and_redacted() {
		$fixture  = new Test_MainWP_Child_Branding_V2_Fixture();
		$settings = $this->desired_settings( array( 'child_support_message' => str_repeat( 'PRIVATE', 2000 ) ) );
		$result   = $this->invoke_v2( $fixture, $this->request( $settings ) );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( array( 'protocol', 'operation_ref', 'ok', 'error' ), array_keys( $result ) );
		$this->assertSame( array( 'code', 'message' ), array_keys( $result['error'] ) );
		$this->assertStringNotContainsString( 'PRIVATE', wp_json_encode( $result ) );
		$this->assertLessThanOrEqual( 256, strlen( $result['error']['message'] ) );
	}

	/**
	 * The legacy receiver still persists a posted settings map.
	 */
	public function test_legacy_update_branding_persists_the_posted_settings() {
		delete_option( 'mainwp_child_branding_settings' );
		$fixture  = new Test_MainWP_Child_Branding_V2_Fixture( array( 'extra_settings' => array() ) );
		$settings = $this->desired_settings(
			array(
				'child_plugin_name' => 'Legacy Connector',
				'child_plugin_hide' => true,
			)
		);
		$settings['child_remove_connection_detail'] = 1;
		$_POST['settings']                          = base64_encode( wp_json_encode( $settings ) );

		$result = $fixture->update_branding();
		unset( $_POST['settings'] );

		$this->assertSame( 'SUCCESS', $result['result'] );
		$stored = get_option( 'mainwp_child_branding_settings' );
		$this->assertSame( 'Legacy Connector', $stored['branding_header']['name'] );
		$this->assertSame( 'T', $stored['hide'] );
		$this->assertSame( 1, $stored['remove_connection_detail'] );
		$this->assertSame( 'Y', $stored['branding_ext_enabled'] );
		delete_option( 'mainwp_child_branding_settings' );
	}

	/**
	 * A v2 apply preserves a stored remove_connection_detail the v2 schema does not carry.
	 *
	 * The projection used to force the field to 0 on every apply, silently clearing a site that had
	 * it set to 1. The field is not part of the v2 settings map, so the current stored value is kept.
	 */
	public function test_apply_preserves_stored_remove_connection_detail() {
		$fixture = new Test_MainWP_Child_Branding_V2_Fixture(
			array(
				'remove_connection_detail' => 1,
				'extra_settings'           => array(),
			)
		);

		$result = $this->invoke_v2( $fixture, $this->request( $this->desired_settings() ) );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 1, $fixture->stored_settings['remove_connection_detail'] );
	}

	/**
	 * With no stored value, the field still defaults to 0.
	 */
	public function test_apply_defaults_remove_connection_detail_to_zero_when_absent() {
		$fixture = new Test_MainWP_Child_Branding_V2_Fixture();

		$result = $this->invoke_v2( $fixture, $this->request( $this->desired_settings() ) );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 0, $fixture->stored_settings['remove_connection_detail'] );
	}

	/**
	 * The apply runs inside the named mutation lock and releases it afterwards.
	 */
	public function test_apply_holds_the_named_mutation_lock_and_releases_it() {
		$fixture = new Test_MainWP_Child_Branding_V2_Fixture();
		$this->assertNull( $fixture->lock_free_during_write );

		$result = $this->invoke_v2( $fixture, $this->request( $this->desired_settings() ) );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( '0', (string) $fixture->lock_free_during_write );
		$this->assertSame( '1', (string) $this->lock_state( $fixture->lock_name() ) );
	}

	/**
	 * A lock held by another request is refused honestly, with no settings or receipt effect.
	 */
	public function test_apply_refuses_honestly_while_another_request_holds_the_lock() {
		$fixture = new Test_MainWP_Child_Branding_V2_Fixture();
		$holder  = $this->hold_lock_elsewhere( $fixture->lock_name() );
		$result  = $this->invoke_v2( $fixture, $this->request( $this->desired_settings() ) );
		$this->release_lock_elsewhere( $holder, $fixture->lock_name() );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'lock_busy', $result['error']['code'] );
		$this->assertSame( array(), $fixture->stored_settings );
		$this->assertSame( array(), $fixture->stored_receipts );
		$this->assertNull( $fixture->lock_free_during_write );
	}

	/**
	 * An absent lock is already the state the caller asked for, and one failed release earns a retry.
	 */
	public function test_release_reads_an_absent_lock_as_released_and_retries_a_failed_release_once() {
		$fixture = new Test_MainWP_Child_Branding_V2_Fixture();
		// The lock name reads home_url(), so it is resolved while the real connection is still in place.
		$fixture->lock_name();
		$real = $GLOBALS['wpdb'];

		try {
			$absent          = new Test_MainWP_Child_Branding_V2_Lock_Wpdb( array( array( '', null ) ) );
			$GLOBALS['wpdb'] = $absent;
			$this->assertTrue( $fixture->end_lock() );
			$this->assertCount( 1, $absent->queries );

			$retried         = new Test_MainWP_Child_Branding_V2_Lock_Wpdb( array( array( 'MySQL server has gone away', null ), array( '', '1' ) ) );
			$GLOBALS['wpdb'] = $retried;
			$this->assertTrue( $fixture->end_lock() );
			$this->assertCount( 2, $retried->queries );

			$failing         = new Test_MainWP_Child_Branding_V2_Lock_Wpdb( array( array( 'Lost connection', null ), array( 'Lost connection', null ) ) );
			$GLOBALS['wpdb'] = $failing;
			$this->assertFalse( $fixture->end_lock() );
			$this->assertCount( 2, $failing->queries );
		} finally {
			$GLOBALS['wpdb'] = $real;
		}
	}

	/**
	 * A lock backend that cannot answer is refused as a store failure, not as someone else's lock.
	 */
	public function test_an_unusable_lock_backend_is_refused_apart_from_a_held_lock() {
		$fixture = new Test_MainWP_Child_Branding_V2_Fixture();
		// The lock name reads home_url(), so it is resolved while the real connection is still in place.
		$name    = $fixture->lock_name();
		$request = $this->request( $this->desired_settings() );

		$backends = array(
			'driver error'     => array( array( 'MySQL server has gone away', null ) ),
			'GET_LOCK is NULL' => array( array( '', null ) ),
		);
		foreach ( $backends as $label => $answers ) {
			$real            = $GLOBALS['wpdb'];
			$GLOBALS['wpdb'] = new Test_MainWP_Child_Branding_V2_Lock_Wpdb( $answers );
			try {
				$result = $this->invoke_v2( $fixture, $request );
			} finally {
				$GLOBALS['wpdb'] = $real;
			}

			$this->assertFalse( $result['ok'], $label );
			$this->assertSame( 'storage_unavailable', $result['error']['code'], $label );
		}

		$holder = $this->hold_lock_elsewhere( $name );
		$held   = $this->invoke_v2( $fixture, $request );
		$this->release_lock_elsewhere( $holder, $name );

		$this->assertFalse( $held['ok'] );
		$this->assertSame( 'lock_busy', $held['error']['code'] );
		$this->assertSame( array(), $fixture->stored_settings );
		$this->assertSame( array(), $fixture->stored_receipts );
	}

	/**
	 * Read IS_FREE_LOCK() for one named lock.
	 *
	 * @param string $name Lock name.
	 * @return string|null
	 */
	private function lock_state( $name ) {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', $name ) );
	}

	/**
	 * Hold one named lock on a second database connection.
	 *
	 * @param string $name Lock name.
	 * @return wpdb
	 */
	private function hold_lock_elsewhere( $name ) {
		$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$other->suppress_errors( true );
		$this->assertSame( '1', (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s,0)', $name ) ) );
		return $other;
	}

	/**
	 * Release the lock held on the second connection.
	 *
	 * @param wpdb   $other Second connection.
	 * @param string $name  Lock name.
	 */
	private function release_lock_elsewhere( $other, $name ) {
		$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		$other->close();
	}
}
