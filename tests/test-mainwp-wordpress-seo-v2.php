<?php
/**
 * WordPress SEO safe protocol-v2 tests.
 *
 * @package MainWP_Child
 */

namespace MainWP\Child;

use WP_UnitTestCase;

class Test_MainWP_WordPress_SEO_V2 extends WP_UnitTestCase {

	public function test_describe_is_closed_and_advertises_the_safe_protocol() {
		$subject = new Testable_MainWP_WordPress_SEO_V2( '25.5', $this->safe_options() );
		$result  = $subject->abilities_v2( 'describe_v2', array( 'contract_version' => '2' ) );

		$this->assertSame( array( 'contract_version', 'operation', 'ok', 'plugin_state', 'version', 'compatibility', 'schema', 'operations', 'mutation_supported' ), array_keys( $result ) );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'supported', $result['compatibility'] );
		$this->assertSame( array( 'read_safe_v1', 'apply_safe_v1', 'rollback_safe_v1' ), $result['operations'] );
		$this->assertTrue( $result['mutation_supported'] );
	}

	public function test_safe_read_normalizes_only_the_allowlisted_subset() {
		$subject = new Testable_MainWP_WordPress_SEO_V2( '25.5', $this->safe_options() );
		$result  = $subject->abilities_v2( 'read_safe_v1', $this->read_request() );

		$this->assertSame( array( 'contract_version', 'operation', 'ok', 'request_ref', 'site_generation', 'version', 'schema', 'settings', 'config_generation' ), array_keys( $result ) );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( array( 'post_types', 'taxonomies', 'archives', 'sitemaps' ), array_keys( $result['settings'] ) );
		$this->assertTrue( $result['settings']['post_types'][0]['index'] );
		$this->assertFalse( $result['settings']['archives']['author_index'] );
		$this->assertSame( hash( 'sha256', wp_json_encode( $result['settings'] ) ), $result['config_generation'] );
		$this->assertStringNotContainsString( 'secret', wp_json_encode( $result ) );
	}

	public function test_unsupported_versions_block_mutations_only_and_unsafe_templates_fail_closed() {
		$unverified = new Testable_MainWP_WordPress_SEO_V2( '26.0', $this->safe_options() );

		$read = $unverified->abilities_v2( 'read_safe_v1', $this->read_request() );
		$this->assertTrue( $read['ok'] );
		$this->assertSame( '26.0', $read['version'] );

		$target = $read['settings'];
		$target['sitemaps']['enabled'] = false;
		$apply = $unverified->abilities_v2( 'apply_safe_v1', $this->mutation_request( 'apply_safe_v1', $read['config_generation'], $target ) );
		$this->assertSame( 'unsupported_version', $apply['code'] );
		$this->assertSame( 0, $unverified->write_count() );

		$describe = $unverified->abilities_v2( 'describe_v2', array( 'contract_version' => '2' ) );
		$this->assertSame( 'unsupported', $describe['compatibility'] );
		$this->assertSame( array( 'read_safe_v1' ), $describe['operations'] );
		$this->assertFalse( $describe['mutation_supported'] );

		$options                                = $this->safe_options();
		$options['wpseo_titles']['title-post'] = '%%title%% %%unknown%%';
		$unsafe                                 = new Testable_MainWP_WordPress_SEO_V2( '25.5', $options );
		$this->assertSame( 'unsafe_configuration', $unsafe->abilities_v2( 'read_safe_v1', $this->read_request() )['code'] );

		$missing = new Testable_MainWP_WordPress_SEO_V2_Missing();
		$this->assertSame( 'provider_unavailable', $missing->abilities_v2( 'read_safe_v1', $this->read_request() )['code'] );
	}

	public function test_apply_holds_the_named_lock_and_releases_it() {
		$subject = new Testable_MainWP_WordPress_SEO_V2( '25.5', $this->safe_options() );
		$read    = $subject->abilities_v2( 'read_safe_v1', $this->read_request() );
		$this->assertNull( $subject->lock_free_during_write );

		$target                        = $read['settings'];
		$target['sitemaps']['enabled'] = false;
		$result                        = $subject->abilities_v2( 'apply_safe_v1', $this->mutation_request( 'apply_safe_v1', $read['config_generation'], $target ) );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( '0', (string) $subject->lock_free_during_write );
		$this->assertSame( '1', (string) $this->lock_state( $subject->lock_name() ) );
	}

	public function test_apply_refuses_honestly_while_another_request_holds_the_lock() {
		$subject = new Testable_MainWP_WordPress_SEO_V2( '25.5', $this->safe_options() );
		$read    = $subject->abilities_v2( 'read_safe_v1', $this->read_request() );
		$target  = $read['settings'];
		$target['sitemaps']['enabled'] = false;

		$holder = $this->hold_lock_elsewhere( $subject->lock_name() );
		$result = $subject->abilities_v2( 'apply_safe_v1', $this->mutation_request( 'apply_safe_v1', $read['config_generation'], $target ) );
		$read_while_locked = $subject->abilities_v2( 'read_safe_v1', $this->read_request() );
		$this->release_lock_elsewhere( $holder, $subject->lock_name() );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'lock_busy', $result['code'] );
		$this->assertSame( 0, $subject->write_count() );
		$this->assertTrue( $read_while_locked['ok'] );
	}

	private function lock_state( $name ) {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', $name ) );
	}

	private function hold_lock_elsewhere( $name ) {
		$other = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$other->suppress_errors( true );
		$this->assertSame( '1', (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s,0)', $name ) ) );
		return $other;
	}

	private function release_lock_elsewhere( $other, $name ) {
		$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		$other->close();
	}

	public function test_release_reads_an_absent_lock_as_released_and_retries_a_failed_release_once() {
		$subject = new Testable_MainWP_WordPress_SEO_V2( '25.5', $this->safe_options() );
		// The lock name reads home_url(), so it is resolved while the real connection is still in place.
		$subject->lock_name();
		$real = $GLOBALS['wpdb'];

		try {
			$absent          = new Testable_Yoast_Release_Wpdb( array( array( '', null ) ) );
			$GLOBALS['wpdb'] = $absent;
			$this->assertTrue( $subject->end_lock() );
			$this->assertCount( 1, $absent->queries );

			$retried         = new Testable_Yoast_Release_Wpdb( array( array( 'MySQL server has gone away', null ), array( '', '1' ) ) );
			$GLOBALS['wpdb'] = $retried;
			$this->assertTrue( $subject->end_lock() );
			$this->assertCount( 2, $retried->queries );

			$failing         = new Testable_Yoast_Release_Wpdb( array( array( 'Lost connection', null ), array( 'Lost connection', null ) ) );
			$GLOBALS['wpdb'] = $failing;
			$this->assertFalse( $subject->end_lock() );
			$this->assertCount( 2, $failing->queries );
		} finally {
			$GLOBALS['wpdb'] = $real;
		}
	}

	public function test_inputs_are_closed_and_uuid_uses_request_ref() {
		$subject = new Testable_MainWP_WordPress_SEO_V2( '25.5', $this->safe_options() );
		$request = $this->read_request();
		$request['extra'] = true;
		$this->assertSame( 'invalid_request', $subject->abilities_v2( 'read_safe_v1', $request )['code'] );

		$request = $this->read_request();
		$request['request_id'] = $request['request_ref'];
		unset( $request['request_ref'] );
		$this->assertSame( 'invalid_request', $subject->abilities_v2( 'read_safe_v1', $request )['code'] );

		$this->assertSame( 'invalid_request', $subject->abilities_v2( 'unknown_operation', $this->read_request() )['code'] );
	}

	public function test_apply_preserves_unowned_values_reads_back_and_replays() {
		$subject = new Testable_MainWP_WordPress_SEO_V2( '25.5', $this->safe_options() );
		$before  = $subject->abilities_v2( 'read_safe_v1', $this->read_request() );
		$target  = $before['settings'];
		$target['post_types'][0]['index']          = false;
		$target['post_types'][0]['title_template'] = '%%title%% %%sep%%';
		$target['sitemaps']['enabled']             = false;
		$request = $this->mutation_request( 'apply_safe_v1', $before['config_generation'], $target );

		$result = $subject->abilities_v2( 'apply_safe_v1', $request );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'applied', $result['state'] );
		$this->assertSame( 2, $result['changed_fields'] );
		$this->assertSame( 'secret', $subject->options()['wpseo_titles']['unowned_private_setting'] );
		$this->assertSame( 'secret', $subject->options()['wpseo']['verification_token'] );
		$this->assertSame( $result, $subject->abilities_v2( 'apply_safe_v1', $request ) );
		$this->assertSame( 2, $subject->write_count() );

		$conflict             = $request;
		$conflict['settings'] = $before['settings'];
		$this->assertSame( 'request_conflict', $subject->abilities_v2( 'apply_safe_v1', $conflict )['code'] );
	}

	public function test_apply_rejects_drift_and_request_id_alias() {
		$subject = new Testable_MainWP_WordPress_SEO_V2( '25.5', $this->safe_options() );
		$read    = $subject->abilities_v2( 'read_safe_v1', $this->read_request() );
		$request = $this->mutation_request( 'apply_safe_v1', str_repeat( 'f', 64 ), $read['settings'] );
		$this->assertSame( 'generation_drift', $subject->abilities_v2( 'apply_safe_v1', $request )['code'] );

		$request = $this->mutation_request( 'apply_safe_v1', $read['config_generation'], $read['settings'] );
		$request['request_id'] = $request['request_ref'];
		unset( $request['request_ref'] );
		$this->assertSame( 'invalid_request', $subject->abilities_v2( 'apply_safe_v1', $request )['code'] );
	}

	public function test_partial_write_failure_restores_exact_original_options() {
		$options = $this->safe_options();
		$subject = new Testable_MainWP_WordPress_SEO_V2( '25.5', $options );
		$read    = $subject->abilities_v2( 'read_safe_v1', $this->read_request() );
		$target  = $read['settings'];
		$target['post_types'][0]['index'] = false;
		$target['sitemaps']['enabled']    = false;
		$subject->fail_next_write( 'wpseo' );

		$result = $subject->abilities_v2( 'apply_safe_v1', $this->mutation_request( 'apply_safe_v1', $read['config_generation'], $target ) );

		$this->assertSame( 'write_failed', $result['code'] );
		$this->assertSame( $options, $subject->options() );
	}

	public function test_rollback_uses_result_generation_and_verifies_target() {
		$subject = new Testable_MainWP_WordPress_SEO_V2( '25.5', $this->safe_options() );
		$before  = $subject->abilities_v2( 'read_safe_v1', $this->read_request() );
		$target  = $before['settings'];
		$target['archives']['author_index'] = true;
		$apply   = $subject->abilities_v2( 'apply_safe_v1', $this->mutation_request( 'apply_safe_v1', $before['config_generation'], $target ) );
		$request = $this->mutation_request( 'rollback_safe_v1', $apply['config_generation'], $before['settings'] );

		$result = $subject->abilities_v2( 'rollback_safe_v1', $request );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'rolled_back', $result['state'] );
		$this->assertSame( $before['config_generation'], $result['config_generation'] );
		$this->assertSame( $before['settings'], $subject->abilities_v2( 'read_safe_v1', $this->read_request() )['settings'] );
	}

	public function test_apply_preserves_the_stored_autoload_setting() {
		global $wpdb;
		$options = $this->safe_options();
		delete_option( 'wpseo_titles' );
		delete_option( 'wpseo' );
		delete_option( 'mainwp_wordpress_seo_v2_receipts' );
		$this->assertTrue( add_option( 'wpseo_titles', $options['wpseo_titles'], '', 'yes' ) );
		$this->assertTrue( add_option( 'wpseo', $options['wpseo'], '', 'yes' ) );
		$autoload_before = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'wpseo_titles' ) );

		$subject = new Testable_MainWP_WordPress_SEO_V2_Durable( '25.5' );
		$read    = $subject->abilities_v2( 'read_safe_v1', $this->read_request() );
		$this->assertTrue( $read['ok'] );
		$target  = $read['settings'];
		$target['post_types'][0]['index'] = false;
		$result  = $subject->abilities_v2( 'apply_safe_v1', $this->mutation_request( 'apply_safe_v1', $read['config_generation'], $target ) );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'applied', $result['state'] );
		$this->assertTrue( get_option( 'wpseo_titles' )['noindex-post'] );
		$this->assertSame( $autoload_before, $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'wpseo_titles' ) ) );

		delete_option( 'wpseo_titles' );
		delete_option( 'wpseo' );
		delete_option( 'mainwp_wordpress_seo_v2_receipts' );
	}

	private function read_request() {
		return array(
			'contract_version' => '2',
			'request_ref'      => '123e4567-e89b-42d3-a456-426614173010',
			'site_generation'  => str_repeat( 'a', 64 ),
		);
	}

	private function safe_options() {
		return array(
			'wpseo_titles' => array(
				'noindex-post'              => false,
				'title-post'                => '%%title%% %%sep%% %%sitename%%',
				'metadesc-post'             => '%%excerpt%%',
				'noindex-page'              => false,
				'title-page'                => '%%title%% %%sep%% %%sitename%%',
				'metadesc-page'             => null,
				'noindex-tax-category'      => false,
				'title-tax-category'        => '%%category%% %%sep%% %%sitename%%',
				'metadesc-tax-category'     => null,
				'noindex-tax-post_tag'      => true,
				'title-tax-post_tag'        => '%%tag%% %%sep%% %%sitename%%',
				'metadesc-tax-post_tag'     => null,
				'noindex-author-wpseo'      => true,
				'noindex-archive-wpseo'     => true,
				'unowned_private_setting'   => 'secret',
			),
			'wpseo'        => array(
				'enable_xml_sitemap' => true,
				'verification_token' => 'secret',
			),
		);
	}

	private function mutation_request( $operation, $expected_generation, $settings ) {
		$request = array(
			'contract_version'   => '2',
			'request_ref'        => '123e4567-e89b-42d3-a456-426614173011',
			'site_generation'    => str_repeat( 'a', 64 ),
			'rollout_generation' => str_repeat( 'b', 64 ),
			'expected_generation'=> $expected_generation,
			'target_generation'  => hash( 'sha256', wp_json_encode( $settings ) ),
			'settings'           => $settings,
		);
		$request[ 'apply_safe_v1' === $operation ? 'template_generation' : 'result_generation' ] = str_repeat( 'c', 64 );
		return $request;
	}
}

class Testable_MainWP_WordPress_SEO_V2 extends MainWP_WordPress_SEO {

	private $test_version;

	private $test_options;

	private $test_receipts = array();

	private $test_write_count = 0;

	private $test_fail_name = null;

	/** @var string|null IS_FREE_LOCK() observed while the option write ran. */
	public $lock_free_during_write = null;

	public function __construct( $version, $options ) {
		$this->test_version = $version;
		$this->test_options = $options;
	}

	protected function abilities_v2_runtime() {
		return array(
			'plugin_state' => 'active',
			'version'      => $this->test_version,
			'options'      => $this->test_options,
		);
	}

	protected function abilities_v2_write_option( $name, $value ) {
		global $wpdb;
		++$this->test_write_count;
		$this->lock_free_during_write = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', $this->abilities_v2_lock_name() ) );
		if ( $name === $this->test_fail_name ) {
			$this->test_fail_name = null;
			return false;
		}
		$this->test_options[ $name ] = $value;
		return true;
	}

	public function lock_name() {
		return $this->abilities_v2_lock_name();
	}

	public function end_lock() {
		return $this->abilities_v2_end_lock();
	}

	protected function abilities_v2_receipt( $operation, $request ) {
		$key = $operation . ':' . $request['request_ref'];
		if ( ! isset( $this->test_receipts[ $key ] ) ) {
			return null;
		}
		$hash = hash( 'sha256', $operation . "\n" . wp_json_encode( $request ) );
		return hash_equals( $this->test_receipts[ $key ]['request_hash'], $hash ) ? $this->test_receipts[ $key ]['response'] : false;
	}

	protected function abilities_v2_store_receipt( $operation, $request, $response ) {
		$this->test_receipts[ $operation . ':' . $request['request_ref'] ] = array(
			'request_hash' => hash( 'sha256', $operation . "\n" . wp_json_encode( $request ) ),
			'response'     => $response,
		);
		return true;
	}

	public function options() {
		return $this->test_options;
	}

	public function write_count() {
		return $this->test_write_count;
	}

	public function fail_next_write( $name ) {
		$this->test_fail_name = $name;
	}
}

/** Answers RELEASE_LOCK() from a script, so each release attempt is observable. */
class Testable_Yoast_Release_Wpdb {

	/** @var array Queries this substitute was asked to run. */
	public $queries = array();

	/** @var string */
	public $last_error = '';

	/** @var array One array( error, result ) per expected release attempt. */
	private $answers;

	public function __construct( $answers ) {
		$this->answers = $answers;
	}

	public function prepare( $query, ...$args ) {
		return $query;
	}

	public function get_var( $query ) {
		$this->queries[]  = $query;
		$answer           = array_shift( $this->answers );
		$this->last_error = $answer[0];
		return $answer[1];
	}
}

/** Reports Yoast as absent so provider guards can be exercised. */
class Testable_MainWP_WordPress_SEO_V2_Missing extends MainWP_WordPress_SEO {

	public function __construct() {
	}

	protected function abilities_v2_runtime() {
		return array(
			'plugin_state' => 'missing',
			'version'      => null,
			'options'      => null,
		);
	}
}

/** Reads and writes the real Yoast options so storage behavior is exercised. */
class Testable_MainWP_WordPress_SEO_V2_Durable extends MainWP_WordPress_SEO {

	private $test_version;

	public function __construct( $version ) {
		$this->test_version = $version;
	}

	protected function abilities_v2_runtime() {
		return array(
			'plugin_state' => 'active',
			'version'      => $this->test_version,
			'options'      => array(
				'wpseo_titles' => get_option( 'wpseo_titles', null ),
				'wpseo'        => get_option( 'wpseo', null ),
			),
		);
	}
}
