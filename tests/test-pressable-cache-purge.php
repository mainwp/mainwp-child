<?php
/**
 * Tests for Pressable Cache Management purges.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Child_Cache_Purge;

if ( ! class_exists( 'Edge_Cache_Plugin' ) ) {
	define( 'MAINWP_PRESSABLE_EDGE_CACHE_TEST_FIXTURE', true );

	/**
	 * Minimal Pressable Edge Cache test fixture.
	 */
	class Edge_Cache_Plugin {

		const EC_ENABLED  = 'enabled';
		const EC_DISABLED = 'disabled';

		/**
		 * Live Edge Cache status returned to MainWP Child.
		 *
		 * @var string
		 */
		public static $status = self::EC_ENABLED;

		/**
		 * Return the fixture instance.
		 *
		 * @return self Fixture instance.
		 */
		public static function get_instance() {
			return new self();
		}

		/**
		 * Return the configured live Edge Cache status.
		 *
		 * @return string Edge Cache status.
		 */
		public function get_ec_status() {
			return self::$status;
		}

		/**
		 * Simulate a successful domain purge.
		 *
		 * @param string $reason Reason for the purge.
		 * @return bool Purge result.
		 */
		public function purge_domain_now( $reason ) {
			return true;
		}
	}
}

/**
 * Test double for controlling Pressable cache-layer results.
 */
class MainWP_Child_Cache_Purge_Pressable_Test_Double extends MainWP_Child_Cache_Purge {

	/**
	 * Object cache flush result.
	 *
	 * @var bool
	 */
	public $object_cache_result = true;

	/**
	 * Batcache flush result.
	 *
	 * @var bool
	 */
	public $batcache_result = true;

	/**
	 * Whether Edge Cache is enabled.
	 *
	 * @var bool
	 */
	public $edge_cache_enabled = true;

	/**
	 * Edge Cache purge result.
	 *
	 * @var bool
	 */
	public $edge_cache_result = true;

	/**
	 * Number of Edge Cache purge attempts.
	 *
	 * @var int
	 */
	public $edge_cache_purge_calls = 0;

	/**
	 * Whether to run the real Edge helper against the fixture.
	 *
	 * @var bool
	 */
	public $use_edge_cache_fixture = false;

	/**
	 * Return the configured object cache result.
	 *
	 * @return bool Object cache result.
	 */
	protected function pressable_flush_object_cache() {
		return $this->object_cache_result;
	}

	/**
	 * Return the configured Batcache result.
	 *
	 * @return bool Batcache result.
	 */
	protected function pressable_flush_batcache() {
		return $this->batcache_result;
	}

	/**
	 * Return the configured Edge Cache status.
	 *
	 * @return bool Edge Cache status.
	 */
	protected function pressable_edge_cache_is_enabled() {
		return $this->edge_cache_enabled;
	}

	/**
	 * Return the configured Edge Cache purge result.
	 *
	 * @return bool Edge Cache purge result.
	 */
	protected function pressable_purge_edge_cache() {
		++$this->edge_cache_purge_calls;
		if ( $this->use_edge_cache_fixture ) {
			return parent::pressable_purge_edge_cache();
		}
		return $this->edge_cache_result;
	}
}

/**
 * Test double that exposes Pressable Edge Cache status detection.
 */
class MainWP_Child_Cache_Purge_Pressable_Status_Test_Double extends MainWP_Child_Cache_Purge {

	/**
	 * Return the detected Edge Cache status.
	 *
	 * @return bool Whether Edge Cache is enabled.
	 */
	public function edge_cache_is_enabled() {
		return $this->pressable_edge_cache_is_enabled();
	}
}

/**
 * Test double for Pressable and Cloudflare result aggregation.
 */
class MainWP_Child_Cache_Purge_Pressable_Cloudflare_Test_Double extends MainWP_Child_Cache_Purge {

	/**
	 * Pressable purge action.
	 *
	 * @var string
	 */
	public $pressable_action = 'ERROR';

	/**
	 * Whether Cloudflare was allowed to update the shared timestamp.
	 *
	 * @var bool|null
	 */
	public $cloudflare_update_last_purged;

	/**
	 * Information passed to the result recorder.
	 *
	 * @var array|null
	 */
	public $recorded_information;

	/**
	 * Return the configured Pressable result.
	 *
	 * @return array Pressable purge result.
	 */
	public function pressable_cache_management_auto_purge_cache() {
		$result_basis = 'SUCCESS' === $this->pressable_action ? 'provider_confirmed' : 'attempt_failed';
		return $this->purge_result( 'Pressable purge result.', $this->pressable_action, $result_basis );
	}

	/**
	 * Return a Cloudflare success and record the timestamp-update instruction.
	 *
	 * @return array Cloudflare purge result.
	 */
	public function cloudflair_auto_purge_cache() {
		$this->cloudflare_update_last_purged = $this->update_cf_timestamp;

		if ( $this->update_cf_timestamp ) {
			update_option( 'mainwp_cache_control_last_purged', 67890 );
		}

		return $this->purge_result( 'Cloudflare purge succeeded.', 'SUCCESS', 'provider_confirmed' );
	}

	/**
	 * Capture the aggregate result.
	 *
	 * @param array $information Purge result information.
	 */
	public function record_results( $information ) {
		$this->recorded_information = $information;
	}
}

/**
 * Test double using the original public Cloudflare override signature.
 */
class MainWP_Child_Cache_Purge_Legacy_Cloudflare_Override_Test_Double extends MainWP_Child_Cache_Purge {

	/**
	 * Return a successful legacy override result.
	 *
	 * @return array Cloudflare purge result.
	 */
	public function cloudflair_auto_purge_cache() {
		return $this->purge_result( 'Legacy Cloudflare override succeeded.', 'SUCCESS', 'provider_confirmed' );
	}
}

/**
 * Pressable Cache Management purge test case.
 */
class Pressable_Cache_Purge_Test extends WP_UnitTestCase {

	/**
	 * Reset cache-control timestamps after each test.
	 */
	public function tear_down() {
		delete_option( 'mainwp_cache_control_last_purged' );
		delete_option( 'flush-obj-cache-time-stamp' );
		delete_option( 'edge-cache-purge-time-stamp' );
		delete_option( 'edge-cache-enabled' );
		delete_option( 'mainwp_cache_control_cache_solution' );
		delete_option( 'mainwp_child_auto_purge_cache' );
		delete_option( 'mainwp_child_cloud_flair_enabled' );
		delete_option( 'mainwp_cache_control_log' );

		if ( defined( 'MAINWP_PRESSABLE_EDGE_CACHE_TEST_FIXTURE' ) ) {
			Edge_Cache_Plugin::$status = Edge_Cache_Plugin::EC_ENABLED;
		}

		parent::tear_down();
	}

	/**
	 * Test that all active layers must succeed before MainWP reports success.
	 */
	public function test_reports_success_after_all_active_layers_are_purged() {
		$purger = new MainWP_Child_Cache_Purge_Pressable_Test_Double();
		$result = $purger->pressable_cache_management_auto_purge_cache();

		$this->assertSame( 'SUCCESS', $result['action'] );
		$this->assertSame( 'provider_confirmed', $result['result_basis'] );
		$this->assertSame( 1, $purger->edge_cache_purge_calls );
		$this->assertNotFalse( get_option( 'mainwp_cache_control_last_purged', false ) );
		$this->assertNotFalse( get_option( 'flush-obj-cache-time-stamp', false ) );
	}

	/**
	 * Test that an object cache failure is reported after other layers are attempted.
	 */
	public function test_reports_object_cache_failure_without_advancing_mainwp_timestamp() {
		update_option( 'mainwp_cache_control_last_purged', 12345 );

		$purger                      = new MainWP_Child_Cache_Purge_Pressable_Test_Double();
		$purger->object_cache_result = false;
		$result                      = $purger->pressable_cache_management_auto_purge_cache();

		$this->assertSame( 'ERROR', $result['action'] );
		$this->assertSame( 'attempt_failed', $result['result_basis'] );
		$this->assertNotFalse( strpos( $result['result'], 'Object Cache' ) );
		$this->assertSame( 1, $purger->edge_cache_purge_calls );
		$this->assertSame( 12345, get_option( 'mainwp_cache_control_last_purged' ) );
		$this->assertFalse( get_option( 'flush-obj-cache-time-stamp', false ) );
	}

	/**
	 * Test that Batcache and Edge Cache failures are both reported.
	 */
	public function test_reports_each_failed_cache_layer() {
		update_option( 'mainwp_cache_control_last_purged', 12345 );

		$purger                    = new MainWP_Child_Cache_Purge_Pressable_Test_Double();
		$purger->batcache_result   = false;
		$purger->edge_cache_result = false;
		$result                    = $purger->pressable_cache_management_auto_purge_cache();

		$this->assertSame( 'ERROR', $result['action'] );
		$this->assertNotFalse( strpos( $result['result'], 'Batcache' ) );
		$this->assertNotFalse( strpos( $result['result'], 'Edge Cache' ) );
		$this->assertSame( 12345, get_option( 'mainwp_cache_control_last_purged' ) );
		$this->assertNotFalse( get_option( 'flush-obj-cache-time-stamp', false ) );
	}

	/**
	 * Test that an object-cache success is recorded when Batcache fails later.
	 */
	public function test_records_object_cache_success_when_batcache_fails() {
		update_option( 'mainwp_cache_control_last_purged', 12345 );

		$hook_calls = 0;
		$callback   = static function () use ( &$hook_calls ) {
			++$hook_calls;
		};
		add_action( 'pcm_after_object_cache_flush', $callback );

		$purger                     = new MainWP_Child_Cache_Purge_Pressable_Test_Double();
		$purger->batcache_result    = false;
		$purger->edge_cache_enabled = false;
		$result                     = $purger->pressable_cache_management_auto_purge_cache();

		remove_action( 'pcm_after_object_cache_flush', $callback );

		$this->assertSame( 'ERROR', $result['action'] );
		$this->assertNotFalse( strpos( $result['result'], 'Batcache' ) );
		$this->assertNotFalse( get_option( 'flush-obj-cache-time-stamp', false ) );
		$this->assertSame( 1, $hook_calls );
		$this->assertSame( 12345, get_option( 'mainwp_cache_control_last_purged' ) );
	}

	/**
	 * Test that either kind of hook failure leaves Edge Cache reachable.
	 */
	public function test_object_hook_failure_does_not_prevent_edge_purge() {
		foreach ( array( new RuntimeException( 'Private callback details' ), new Error( 'Private callback details' ) ) as $failure ) {
			update_option( 'mainwp_cache_control_last_purged', 12345 );
			$callback = static function () use ( $failure ) {
				throw $failure;
			};
			add_action( 'pcm_after_object_cache_flush', $callback );
			$purger = new MainWP_Child_Cache_Purge_Pressable_Test_Double();

			try {
				$result = $purger->pressable_cache_management_auto_purge_cache();
			} finally {
				remove_action( 'pcm_after_object_cache_flush', $callback );
			}

			$this->assertSame( 'ERROR', $result['action'] );
			$this->assertNotFalse( strpos( $result['result'], 'Object Cache post-purge hook' ) );
			$this->assertFalse( strpos( $result['result'], 'Private callback details' ) );
			$this->assertSame( 1, $purger->edge_cache_purge_calls );
			$this->assertNotFalse( get_option( 'flush-obj-cache-time-stamp', false ) );
			$this->assertSame( 12345, get_option( 'mainwp_cache_control_last_purged' ) );

			// A later successful invocation must not retain the earlier hook failure.
			$result = $purger->pressable_cache_management_auto_purge_cache();
			$this->assertSame( 'SUCCESS', $result['action'] );
			$this->assertSame( 2, $purger->edge_cache_purge_calls );
		}
	}

	/**
	 * Test that an Edge notification failure returns an incomplete result.
	 */
	public function test_edge_hook_failure_returns_an_error() {
		if ( ! defined( 'MAINWP_PRESSABLE_EDGE_CACHE_TEST_FIXTURE' ) ) {
			$this->markTestSkipped( 'The Pressable Edge Cache fixture is unavailable because the production class is already loaded.' );
		}

		foreach ( array( new RuntimeException( 'Private callback details' ), new Error( 'Private callback details' ) ) as $failure ) {
			update_option( 'mainwp_cache_control_last_purged', 12345 );
			$callback = static function () use ( $failure ) {
				throw $failure;
			};
			add_action( 'pcm_after_edge_cache_purge', $callback );
			$purger                         = new MainWP_Child_Cache_Purge_Pressable_Test_Double();
			$purger->use_edge_cache_fixture = true;

			try {
				$result = $purger->pressable_cache_management_auto_purge_cache();
			} finally {
				remove_action( 'pcm_after_edge_cache_purge', $callback );
			}

			$this->assertSame( 'ERROR', $result['action'] );
			$this->assertNotFalse( strpos( $result['result'], 'Edge Cache post-purge hook' ) );
			$this->assertNotFalse( get_option( 'edge-cache-purge-time-stamp', false ) );
			$this->assertFalse( strpos( $result['result'], 'Private callback details' ) );
			$this->assertSame( 1, $purger->edge_cache_purge_calls );
			$this->assertSame( 12345, get_option( 'mainwp_cache_control_last_purged' ) );
		}
	}

	/**
	 * Test that hook failures and independent cache failures are all reported.
	 */
	public function test_reports_hook_and_cache_failures_together() {
		update_option( 'mainwp_cache_control_last_purged', 12345 );
		$callback = static function () {
			throw new RuntimeException( 'Callback failed' );
		};
		add_action( 'pcm_after_object_cache_flush', $callback );
		$purger                    = new MainWP_Child_Cache_Purge_Pressable_Test_Double();
		$purger->batcache_result   = false;
		$purger->edge_cache_result = false;

		try {
			$result = $purger->pressable_cache_management_auto_purge_cache();
		} finally {
			remove_action( 'pcm_after_object_cache_flush', $callback );
		}

		$this->assertSame( 'ERROR', $result['action'] );
		$this->assertNotFalse( strpos( $result['result'], 'Batcache' ) );
		$this->assertNotFalse( strpos( $result['result'], 'Object Cache post-purge hook' ) );
		$this->assertNotFalse( strpos( $result['result'], 'Edge Cache' ) );
		$this->assertSame( 1, $purger->edge_cache_purge_calls );
		$this->assertSame( 12345, get_option( 'mainwp_cache_control_last_purged' ) );
	}

	/**
	 * Test that notifications run only for successfully purged active layers.
	 */
	public function test_skips_hooks_for_failed_or_disabled_cache_layers() {
		$callback = static function () {
			throw new RuntimeException( 'This notification must not run' );
		};
		add_action( 'pcm_after_object_cache_flush', $callback );
		add_action( 'pcm_after_edge_cache_purge', $callback );

		try {
			foreach ( array( true, false ) as $edge_enabled ) {
				update_option( 'mainwp_cache_control_last_purged', 12345 );
				$purger                      = new MainWP_Child_Cache_Purge_Pressable_Test_Double();
				$purger->object_cache_result = false;
				$purger->edge_cache_result   = false;
				$purger->edge_cache_enabled  = $edge_enabled;
				$result                      = $purger->pressable_cache_management_auto_purge_cache();

				$this->assertSame( 'ERROR', $result['action'] );
				$this->assertFalse( strpos( $result['result'], 'post-purge hook' ) );
				$this->assertSame( $edge_enabled ? 1 : 0, $purger->edge_cache_purge_calls );
				$this->assertSame( 12345, get_option( 'mainwp_cache_control_last_purged' ) );
			}
		} finally {
			remove_action( 'pcm_after_object_cache_flush', $callback );
			remove_action( 'pcm_after_edge_cache_purge', $callback );
		}
	}

	/**
	 * Test that Cloudflare success cannot advance the timestamp after Pressable fails.
	 */
	public function test_cloudflare_success_does_not_mask_pressable_failure() {
		update_option( 'mainwp_cache_control_last_purged', 12345 );
		update_option( 'mainwp_child_auto_purge_cache', 1 );
		update_option( 'mainwp_cache_control_cache_solution', 'Pressable Cache Management' );
		update_option( 'mainwp_child_cloud_flair_enabled', '1' );

		$purger = new MainWP_Child_Cache_Purge_Pressable_Cloudflare_Test_Double();
		$purger->auto_purge_cache();

		$this->assertFalse( $purger->cloudflare_update_last_purged );
		$this->assertSame( 'ERROR', $purger->recorded_information['action'] );
		$this->assertSame( 'SUCCESS', $purger->recorded_information['cloudflare']['action'] );
		$this->assertSame( 12345, get_option( 'mainwp_cache_control_last_purged' ) );

		$purger->cloudflair_auto_purge_cache();

		$this->assertTrue( $purger->cloudflare_update_last_purged );
		$this->assertSame( 67890, get_option( 'mainwp_cache_control_last_purged' ) );
	}

	/**
	 * Test that Cloudflare can advance the timestamp after Pressable succeeds.
	 */
	public function test_cloudflare_success_updates_timestamp_after_pressable_success() {
		update_option( 'mainwp_cache_control_last_purged', 12345 );
		update_option( 'mainwp_child_auto_purge_cache', 1 );
		update_option( 'mainwp_cache_control_cache_solution', 'Pressable Cache Management' );
		update_option( 'mainwp_child_cloud_flair_enabled', '1' );

		$purger                   = new MainWP_Child_Cache_Purge_Pressable_Cloudflare_Test_Double();
		$purger->pressable_action = 'SUCCESS';
		$purger->auto_purge_cache();

		$this->assertTrue( $purger->cloudflare_update_last_purged );
		$this->assertSame( 'SUCCESS', $purger->recorded_information['action'] );
		$this->assertSame( 'SUCCESS', $purger->recorded_information['cloudflare']['action'] );
		$this->assertSame( 67890, get_option( 'mainwp_cache_control_last_purged' ) );
	}

	/**
	 * Test that existing zero-argument Cloudflare overrides remain compatible.
	 */
	public function test_legacy_cloudflare_override_signature_remains_compatible() {
		$purger = new MainWP_Child_Cache_Purge_Legacy_Cloudflare_Override_Test_Double();
		$result = $purger->cloudflair_auto_purge_cache();

		$this->assertSame( 'SUCCESS', $result['action'] );
	}

	/**
	 * Test that disabled Edge Cache is skipped.
	 */
	public function test_skips_edge_cache_when_disabled() {
		$purger                     = new MainWP_Child_Cache_Purge_Pressable_Test_Double();
		$purger->edge_cache_enabled = false;
		$purger->edge_cache_result  = false;
		$result                     = $purger->pressable_cache_management_auto_purge_cache();

		$this->assertSame( 'SUCCESS', $result['action'] );
		$this->assertSame( 0, $purger->edge_cache_purge_calls );
		$this->assertNotFalse( get_option( 'mainwp_cache_control_last_purged', false ) );
	}

	/**
	 * Test that an authoritative disabled status overrides a stale enabled option.
	 */
	public function test_live_disabled_edge_cache_overrides_stale_enabled_option() {
		if ( ! defined( 'MAINWP_PRESSABLE_EDGE_CACHE_TEST_FIXTURE' ) ) {
			$this->markTestSkipped( 'The Pressable Edge Cache fixture is unavailable because the production class is already loaded.' );
		}

		update_option( 'edge-cache-enabled', 'enabled' );
		Edge_Cache_Plugin::$status = Edge_Cache_Plugin::EC_DISABLED;

		$purger = new MainWP_Child_Cache_Purge_Pressable_Status_Test_Double();

		$this->assertFalse( $purger->edge_cache_is_enabled() );
	}
}
