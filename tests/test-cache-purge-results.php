<?php
/**
 * Cache purge result honesty tests.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Child_Cache_Purge;
use MainWP\Child\MainWP_Child_Keys_Manager;
use MainWP\Child\MainWP_Exception;

/**
 * Cache purge result contract tests.
 */
class Test_Cache_Purge_Results extends WP_UnitTestCase {

	/**
	 * Accepted basis values.
	 *
	 * @var string[]
	 */
	private $valid_bases = array(
		'provider_confirmed',
		'dispatched_unverified',
		'provider_missing',
		'not_attempted',
		'preflight_failed',
		'attempt_failed',
	);

	/**
	 * Reset cache options between tests.
	 */
	public function setUp(): void {
		parent::setUp();

		delete_option( 'mainwp_cache_control_last_purged' );
		delete_option( 'mainwp_cache_control_log' );
		delete_option( 'mainwp_child_auto_purge_cache' );
		delete_option( 'mainwp_cache_control_cache_solution' );
		delete_option( 'mainwp_child_cloud_flair_enabled' );
		delete_option( 'mainwp_child_cloudflare_use_token' );
		delete_option( 'mainwp_cloudflair_email' );
		delete_option( 'mainwp_child_cloudflair_key' );
		delete_option( 'mainwp_child_cloudflare_token' );
	}

	/**
	 * Assert a result has a supported basis.
	 *
	 * @param array  $result Result array.
	 * @param string $basis  Expected basis.
	 */
	private function assert_basis( $result, $basis ) {
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'result_basis', $result );
		$this->assertContains( $result['result_basis'], $this->valid_bases );
		$this->assertSame( $basis, $result['result_basis'] );
	}

	/**
	 * Read the last recorded result.
	 *
	 * @return array
	 */
	private function recorded_result() {
		$result = json_decode( get_option( 'mainwp_cache_control_log', '' ), true );
		$this->assertIsArray( $result );
		return $result;
	}

	/**
	 * Return a purger that avoids the unrelated public-suffix cache filesystem.
	 *
	 * @return MainWP_Child_Cache_Purge
	 */
	private function cloudflare_purger() {
		return new class() extends MainWP_Child_Cache_Purge {
			public function strip_subdomains( $url ) {
				return $url;
			}
		};
	}

	/**
	 * Pressable cannot report success when its callback is absent.
	 */
	public function test_pressable_missing_callback_is_provider_missing() {
		update_option( 'mainwp_cache_control_last_purged', 123 );

		$result = MainWP_Child_Cache_Purge::instance()->pressable_cache_management_auto_purge_cache();

		$this->assertSame( 'ERROR', $result['action'] );
		$this->assert_basis( $result, 'provider_missing' );
		$this->assertSame( 123, get_option( 'mainwp_cache_control_last_purged' ) );
	}

	/**
	 * Pressable callable dispatch remains a successful unverified dispatch.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_pressable_present_callback_is_dispatched_unverified() {
		function flush_pressable_cache_callback() {}

		$result = MainWP_Child_Cache_Purge::instance()->pressable_cache_management_auto_purge_cache();

		$this->assertSame( 'SUCCESS', $result['action'] );
		$this->assert_basis( $result, 'dispatched_unverified' );
		$this->assertGreaterThan( 0, get_option( 'mainwp_cache_control_last_purged' ) );
	}

	/**
	 * A representative void provider remains an unverified dispatch.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_void_provider_is_dispatched_unverified() {
		$stub = new class() {
			public static function purge_cache_all() {}
		};
		class_alias( get_class( $stub ), 'RunCloud_Hub' );

		$result = MainWP_Child_Cache_Purge::instance()->runcloud_hub_auto_purge_cache();

		$this->assertSame( 'SUCCESS', $result['action'] );
		$this->assert_basis( $result, 'dispatched_unverified' );
	}

	/**
	 * WP Optimize distinguishes a partial dispatch from a wholly missing provider.
	 */
	public function test_wp_optimize_failure_basis_reflects_whether_any_call_dispatched() {
		$partially_dispatched = new class() extends MainWP_Child_Cache_Purge {
			public function wp_optimize_purge_cache() {
				return true;
			}

			public function wp_optimize_purge_minify() {
				return false;
			}

			public function wp_optimize_preload_cache() {
				return false;
			}
		};
		$missing              = new class() extends MainWP_Child_Cache_Purge {
			public function wp_optimize_purge_cache() {
				return false;
			}

			public function wp_optimize_purge_minify() {
				return false;
			}

			public function wp_optimize_preload_cache() {
				return false;
			}
		};

		$partial_result = $partially_dispatched->wp_optimize_auto_purge_cache();
		$missing_result = $missing->wp_optimize_auto_purge_cache();

		$this->assertSame( 'ERROR', $partial_result['action'] );
		$this->assert_basis( $partial_result, 'dispatched_unverified' );
		$this->assertSame( 'ERROR', $missing_result['action'] );
		$this->assert_basis( $missing_result, 'provider_missing' );
	}

	/**
	 * Disabled and plugin-not-found paths are explicit and compatible.
	 */
	public function test_disabled_and_plugin_not_found_results_have_explicit_bases() {
		$purger = $this->cloudflare_purger();

		update_option( 'mainwp_child_auto_purge_cache', 0 );
		$purger->auto_purge_cache();
		$disabled = $this->recorded_result();
		$this->assertSame( 'SUCCESS', $disabled['action'] );
		$this->assert_basis( $disabled, 'not_attempted' );

		update_option( 'mainwp_child_auto_purge_cache', 1 );
		update_option( 'mainwp_cache_control_cache_solution', 'Plugin Not Found' );
		$purger->auto_purge_cache();
		$missing = $this->recorded_result();
		$this->assertSame( 'SUCCESS', $missing['action'] );
		$this->assert_basis( $missing, 'provider_missing' );
	}

	/**
	 * A composed result carries a basis at both the top level and the Cloudflare layer.
	 */
	public function test_composed_result_carries_a_basis_at_both_layers() {
		update_option( 'mainwp_child_auto_purge_cache', 1 );
		update_option( 'mainwp_cache_control_cache_solution', 'Plugin Not Found' );
		update_option( 'mainwp_child_cloud_flair_enabled', '1' );

		MainWP_Child_Cache_Purge::instance()->auto_purge_cache();
		$result = $this->recorded_result();

		$this->assertSame( 'SUCCESS', $result['action'] );
		$this->assert_basis( $result, 'provider_missing' );
		$this->assertArrayHasKey( 'cloudflare', $result );
		$this->assertSame( 'ERROR', $result['cloudflare']['action'] );
		$this->assert_basis( $result['cloudflare'], 'preflight_failed' );
	}

	/**
	 * An unmatched detected solution produces a shaped provider-missing error.
	 */
	public function test_dispatch_default_is_shaped_provider_missing_error() {
		update_option( 'mainwp_child_auto_purge_cache', 1 );
		update_option( 'mainwp_cache_control_cache_solution', 'Unmatched Cache Solution' );

		MainWP_Child_Cache_Purge::instance()->auto_purge_cache();
		$result = $this->recorded_result();

		$this->assertSame( 'ERROR', $result['action'] );
		$this->assert_basis( $result, 'provider_missing' );
		$this->assertNotEmpty( $result['result'] );
	}

	/**
	 * Provider exceptions remain shaped and do not expose raw exception text.
	 */
	public function test_provider_exception_is_shaped_attempt_failure() {
		$purger = new class() extends MainWP_Child_Cache_Purge {
			public function breeze_auto_purge_cache() {
				throw new MainWP_Exception( 'secret provider detail' );
			}
		};

		update_option( 'mainwp_child_auto_purge_cache', 1 );
		update_option( 'mainwp_cache_control_cache_solution', 'Breeze' );
		$purger->auto_purge_cache();
		$result = $this->recorded_result();

		$this->assertSame( 'ERROR', $result['action'] );
		$this->assert_basis( $result, 'attempt_failed' );
		$this->assertStringNotContainsString( 'secret provider detail', wp_json_encode( $result ) );
	}

	/**
	 * Third-party purge code throws whatever it likes. Anything that is not a MainWP exception
	 * used to escape auto_purge_cache() and take the caller down mid-update.
	 */
	public function test_foreign_provider_throwable_is_shaped_attempt_failure() {
		$purger = new class() extends MainWP_Child_Cache_Purge {
			public function breeze_auto_purge_cache() {
				throw new RuntimeException( 'secret provider detail' );
			}
		};

		update_option( 'mainwp_child_auto_purge_cache', 1 );
		update_option( 'mainwp_cache_control_cache_solution', 'Breeze' );
		$purger->auto_purge_cache();
		$result = $this->recorded_result();

		$this->assertSame( 'ERROR', $result['action'] );
		$this->assert_basis( $result, 'attempt_failed' );
		$this->assertStringNotContainsString( 'secret provider detail', wp_json_encode( $result ) );
	}

	/**
	 * Missing Cloudflare credentials fail before HTTP.
	 */
	public function test_cloudflare_missing_credentials_is_preflight_failure_without_http() {
		$calls = 0;
		$spy   = static function () use ( &$calls ) {
			++$calls;
			return new WP_Error( 'unexpected_http' );
		};
		add_filter( 'pre_http_request', $spy, 10, 3 );

		$result = MainWP_Child_Cache_Purge::instance()->cloudflair_auto_purge_cache();

		remove_filter( 'pre_http_request', $spy, 10 );
		$this->assertSame( 0, $calls );
		$this->assertSame( 'ERROR', $result['action'] );
		$this->assert_basis( $result, 'preflight_failed' );
	}

	/**
	 * Undecryptable credentials fail before HTTP in both authentication modes.
	 */
	public function test_cloudflare_undecryptable_credentials_fail_before_http() {
		$calls = 0;
		$spy   = static function () use ( &$calls ) {
			++$calls;
			return new WP_Error( 'unexpected_http' );
		};
		add_filter( 'pre_http_request', $spy, 10, 3 );

		update_option( 'mainwp_cloudflair_email', 'admin@example.test' );
		update_option( 'mainwp_child_cloudflair_key', 'invalid-ciphertext' );
		$key_result = MainWP_Child_Cache_Purge::instance()->cloudflair_auto_purge_cache();

		update_option( 'mainwp_child_cloudflare_use_token', 1 );
		update_option( 'mainwp_child_cloudflare_token', 'invalid-ciphertext' );
		$token_result = MainWP_Child_Cache_Purge::instance()->cloudflair_auto_purge_cache();

		remove_filter( 'pre_http_request', $spy, 10 );
		$this->assertSame( 0, $calls );
		$this->assert_basis( $key_result, 'preflight_failed' );
		$this->assert_basis( $token_result, 'preflight_failed' );
	}

	/**
	 * Cloudflare success and API failure are both confirmed provider outcomes.
	 */
	public function test_cloudflare_confirmed_success_and_bounded_api_failure() {
		$purger = $this->cloudflare_purger();
		update_option( 'mainwp_cloudflair_email', 'admin@example.test' );
		update_option( 'mainwp_child_cloudflair_key', MainWP_Child_Keys_Manager::instance()->encrypt_string( 'api-key' ) );

		$long_error = str_repeat( 'provider-secret-', 80 );
		$responses  = array(
			array( 'body' => wp_json_encode( array( 'result' => array( array( 'id' => 'zone-1' ) ) ) ) ),
			array( 'body' => wp_json_encode( array( 'success' => true ) ) ),
			array( 'body' => wp_json_encode( array( 'result' => array( array( 'id' => 'zone-1' ) ) ) ) ),
			array(
				'body' => wp_json_encode(
					array(
						'success' => false,
						'errors'  => array( $long_error ),
					)
				),
			),
		);
		$stub       = static function () use ( &$responses ) {
			$response = array_shift( $responses );
			return array_merge(
				array(
					'headers'  => array(),
					'response' => array( 'code' => 200 ),
					'cookies'  => array(),
				),
				$response
			);
		};
		add_filter( 'pre_http_request', $stub, 10, 3 );

		$success = $purger->cloudflair_auto_purge_cache();
		$failure = $purger->cloudflair_auto_purge_cache();

		remove_filter( 'pre_http_request', $stub, 10 );
		$this->assertSame( 'SUCCESS', $success['action'] );
		$this->assert_basis( $success, 'provider_confirmed' );
		$this->assertSame( 'ERROR', $failure['action'] );
		$this->assert_basis( $failure, 'provider_confirmed' );
		$message_prefix = 'Cloudflare => There was an issue purging the cache. ';
		$this->assertStringStartsWith( $message_prefix, $failure['result'] );
		$this->assertLessThanOrEqual( 512 + strlen( $message_prefix ), strlen( $failure['result'] ) );
		$this->assertStringNotContainsString( $long_error, $failure['result'] );
	}

	/**
	 * A purge response that is not a readable API response reports no provider outcome.
	 */
	public function test_cloudflare_unreadable_purge_response_is_attempt_failure() {
		$purger = $this->cloudflare_purger();
		update_option( 'mainwp_cloudflair_email', 'admin@example.test' );
		update_option( 'mainwp_child_cloudflair_key', MainWP_Child_Keys_Manager::instance()->encrypt_string( 'api-key' ) );

		$responses = array(
			array(
				'headers'  => array(),
				'body'     => wp_json_encode( array( 'result' => array( array( 'id' => 'zone-1' ) ) ) ),
				'response' => array( 'code' => 200 ),
				'cookies'  => array(),
			),
			array(
				'headers'  => array(),
				'body'     => '<html>Bad Gateway</html>',
				'response' => array( 'code' => 502 ),
				'cookies'  => array(),
			),
		);
		$stub      = static function () use ( &$responses ) {
			return array_shift( $responses );
		};
		add_filter( 'pre_http_request', $stub, 10, 3 );

		$result = $purger->cloudflair_auto_purge_cache();

		remove_filter( 'pre_http_request', $stub, 10 );
		$this->assertSame( 'ERROR', $result['action'] );
		$this->assert_basis( $result, 'attempt_failed' );
		$this->assertStringNotContainsString( 'Bad Gateway', $result['result'] );
	}

	/**
	 * Cloudflare zone lookup and purge transport failures have distinct bases.
	 */
	public function test_cloudflare_preflight_and_attempt_transport_failures_are_distinct() {
		$purger = $this->cloudflare_purger();
		update_option( 'mainwp_cloudflair_email', 'admin@example.test' );
		update_option( 'mainwp_child_cloudflair_key', MainWP_Child_Keys_Manager::instance()->encrypt_string( 'api-key' ) );

		$responses = array(
			new WP_Error( 'zone_transport' ),
			array(
				'headers'  => array(),
				'body'     => wp_json_encode( array( 'result' => array( array( 'id' => 'zone-1' ) ) ) ),
				'response' => array( 'code' => 200 ),
				'cookies'  => array(),
			),
			new WP_Error( 'purge_transport' ),
		);
		$stub      = static function () use ( &$responses ) {
			return array_shift( $responses );
		};
		add_filter( 'pre_http_request', $stub, 10, 3 );

		$preflight = $purger->cloudflair_auto_purge_cache();
		$attempt   = $purger->cloudflair_auto_purge_cache();

		remove_filter( 'pre_http_request', $stub, 10 );
		$this->assert_basis( $preflight, 'preflight_failed' );
		$this->assert_basis( $attempt, 'attempt_failed' );
	}

	/**
	 * A successful zone response without an ID remains a preflight failure.
	 */
	public function test_cloudflare_missing_zone_id_is_preflight_failure() {
		$purger = $this->cloudflare_purger();
		update_option( 'mainwp_cloudflair_email', 'admin@example.test' );
		update_option( 'mainwp_child_cloudflair_key', MainWP_Child_Keys_Manager::instance()->encrypt_string( 'api-key' ) );

		$calls = 0;
		$stub  = static function () use ( &$calls ) {
			++$calls;
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( array( 'result' => array() ) ),
				'response' => array( 'code' => 200 ),
				'cookies'  => array(),
			);
		};
		add_filter( 'pre_http_request', $stub, 10, 3 );

		$result = $purger->cloudflair_auto_purge_cache();

		remove_filter( 'pre_http_request', $stub, 10 );
		$this->assertSame( 1, $calls );
		$this->assert_basis( $result, 'preflight_failed' );
	}

	/**
	 * WP Rocket guard failure is a missing provider, not a confirmed failure.
	 */
	public function test_wp_rocket_missing_functions_is_provider_missing() {
		$result = MainWP_Child_Cache_Purge::instance()->wprocket_auto_cache_purge();

		$this->assertSame( 'ERROR', $result['action'] );
		$this->assert_basis( $result, 'provider_missing' );
	}

	/**
	 * Recorded results preserve the basis through JSON storage.
	 */
	public function test_record_results_round_trip_preserves_basis() {
		$result = MainWP_Child_Cache_Purge::instance()->purge_result( 'Dispatch complete.', 'SUCCESS', 'dispatched_unverified' );
		MainWP_Child_Cache_Purge::instance()->record_results( $result );

		$this->assertSame( $result, $this->recorded_result() );
	}

	/**
	 * Every production result call explicitly supplies a supported basis.
	 */
	public function test_every_purge_result_call_has_an_explicit_supported_basis() {
		$source = file_get_contents( dirname( __DIR__ ) . '/class/class-mainwp-child-cache-purge.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fixed local test source.
		$this->assertIsString( $source );
		$tokens       = token_get_all( $source );
		$calls        = 0;
		$occurrences  = 0;
		$declarations = 0;

		foreach ( $tokens as $index => $token ) {
			if ( ! is_array( $token ) || T_STRING !== $token[0] || 'purge_result' !== $token[1] ) {
				continue;
			}
			++$occurrences;

			$previous = $index - 1;
			while ( $previous >= 0 && is_array( $tokens[ $previous ] ) && T_WHITESPACE === $tokens[ $previous ][0] ) {
				--$previous;
			}
			if ( $previous >= 0 && is_array( $tokens[ $previous ] ) && T_FUNCTION === $tokens[ $previous ][0] ) {
				++$declarations;
				continue;
			}
			if ( $previous < 0 || ! is_array( $tokens[ $previous ] ) || T_OBJECT_OPERATOR !== $tokens[ $previous ][0] ) {
				continue;
			}

			$cursor = $index + 1;
			while ( isset( $tokens[ $cursor ] ) && is_array( $tokens[ $cursor ] ) && T_WHITESPACE === $tokens[ $cursor ][0] ) {
				++$cursor;
			}
			$this->assertSame( '(', $tokens[ $cursor ] );

			$depth       = 0;
			$argument    = 1;
			$third_value = '';
			for ( ; isset( $tokens[ $cursor ] ); ++$cursor ) {
				$current = $tokens[ $cursor ];
				$text    = is_array( $current ) ? $current[1] : $current;
				if ( '(' === $text ) {
					++$depth;
				} elseif ( ')' === $text ) {
					--$depth;
					if ( 0 === $depth ) {
						break;
					}
				} elseif ( ',' === $text && 1 === $depth ) {
					++$argument;
					continue;
				}

				if ( 3 === $argument ) {
					$third_value .= $text;
				}
			}

			$this->assertSame( 3, $argument, 'Every purge_result() call must pass exactly three arguments.' );
			$this->assertMatchesRegularExpression( "/'([^']+)'/", $third_value );
			preg_match( "/'([^']+)'/", $third_value, $matches );
			$this->assertContains( $matches[1], $this->valid_bases );
			++$calls;
		}

		$this->assertSame( 1, $declarations, 'purge_result() must be declared exactly once in the production file.' );
		$this->assertSame(
			$occurrences - $declarations,
			$calls,
			'Every purge_result() occurrence other than its declaration must be scanned as a call site; a differently shaped call (self::, static::, a callable string) would otherwise skip the basis check.'
		);
		$this->assertSame(
			60,
			$calls,
			'The purge_result() call-site count changed. Update this number deliberately after confirming every new call site passes an explicit basis.'
		);
	}
}
