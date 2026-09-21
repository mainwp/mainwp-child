<?php
/**
 * Favorites package protocol-v2 tests.
 *
 * @package MainWP_Child
 */

namespace MainWP\Child;

use ReflectionClass;
use WP_UnitTestCase;

class Test_MainWP_Child_Favorites_V2 extends WP_UnitTestCase {

	public function test_package_state_is_exact_closed_and_redacted() {
		$subject = new Testable_MainWP_Child_Favorites_V2(
			array( 'forms/forms.php' => array( 'Version' => '2.1.0', 'Name' => 'Private name' ) ),
			array( 'forms/forms.php' ),
			array()
		);
		$result  = $subject->package_state_v2( $this->state_request( 'plugin', 'forms/forms.php' ) );

		$this->assertSame( array( 'protocol', 'operation', 'ok', 'request_ref', 'type', 'slug', 'installed', 'version', 'active', 'state_generation' ), array_keys( $result ) );
		$this->assertTrue( $result['installed'] );
		$this->assertSame( '2.1.0', $result['version'] );
		$this->assertTrue( $result['active'] );
		$this->assertStringNotContainsString( 'Private name', wp_json_encode( $result ) );
	}

	public function test_package_state_reports_absence_and_rejects_ambiguous_slugs() {
		$subject = new Testable_MainWP_Child_Favorites_V2( array(), array(), array() );
		$result  = $subject->package_state_v2( $this->state_request( 'theme', 'twentytwentyfive' ) );
		$this->assertFalse( $result['installed'] );
		$this->assertNull( $result['version'] );
		$this->assertNull( $result['active'] );

		$request         = $this->state_request( 'plugin', 'forms/forms.php' );
		$request['slug'] = '../forms.php';
		$this->assertSame( 'invalid_request', $subject->package_state_v2( $request )['code'] );
		$this->assertFalse( $subject->package_state_v2( $this->state_request( 'plugin', 'hello.php' ) )['installed'] );
	}

	public function test_install_callable_advertises_verified_mutation_and_status() {
		$subject = new Testable_MainWP_Child_Favorites_V2( array(), array(), array() );
		$result  = $subject->install_verified_v2(
			array(
				'protocol'  => '2',
				'operation' => 'capabilities',
				'payload'   => array(),
			)
		);

		$this->assertSame( array( 'protocol', 'operation', 'ok', 'operations', 'mutation_supported' ), array_keys( $result ) );
		$this->assertSame( array( 'install', 'status' ), $result['operations'] );
		$this->assertTrue( $result['mutation_supported'] );
	}

	public function test_verified_install_binds_digest_slug_version_and_final_activation() {
		$subject = new Testable_MainWP_Child_Favorites_V2( array(), array(), array() );
		$request = $this->install_request();
		$result  = $subject->install_verified_v2( $request );

		$this->assertSame( array( 'protocol', 'operation', 'ok', 'request_ref', 'status', 'installed', 'type', 'slug', 'version', 'active', 'code' ), array_keys( $result ) );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'completed', $result['status'] );
		$this->assertSame( 1, $subject->download_count );
		$this->assertSame( 1, $subject->install_count );
		$this->assertSame( 1, $subject->cache_refresh_count );
		$this->assertSame( 1, $subject->cleanup_count );
		$this->assertStringNotContainsString( 'dashboard.example', wp_json_encode( $subject->receipts ) );

		$this->assertSame( $result, $subject->install_verified_v2( $request ) );
		$this->assertSame( 1, $subject->download_count );
		$this->assertSame( 1, $subject->install_count );

		$changed = $request;
		$changed['payload']['activate'] = false;
		$this->assertSame( 'request_conflict', $subject->install_verified_v2( $changed )['code'] );
	}

	public function test_verified_install_rejects_bad_digest_before_dispatch() {
		$subject                              = new Testable_MainWP_Child_Favorites_V2( array(), array(), array() );
		$subject->downloaded_package_digest   = str_repeat( 'b', 64 );
		$result                               = $subject->install_verified_v2( $this->install_request() );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( 'digest_mismatch', $result['code'] );
		$this->assertSame( 0, $subject->install_count );
		$this->assertSame( 1, $subject->cleanup_count );
	}

	public function test_dispatching_receipt_never_blindly_retries_and_status_is_read_only() {
		$subject = new Testable_MainWP_Child_Favorites_V2( array(), array(), array() );
		$request = $this->install_request();
		$subject->seed_dispatching( $request );

		$result = $subject->install_verified_v2( $request );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'unknown', $result['status'] );
		$this->assertSame( 'outcome_unknown', $result['code'] );
		$this->assertSame( 0, $subject->download_count );
		$this->assertSame( 0, $subject->install_count );

		$status = $subject->install_verified_v2(
			array(
				'protocol'  => '2',
				'operation' => 'status',
				'payload'   => array( 'request_ref' => $request['payload']['request_ref'] ),
			)
		);
		$this->assertSame( 'unknown', $status['status'] );
		$this->assertSame( 0, $subject->download_count );
	}

	public function test_expired_receipt_is_not_found_and_the_status_read_never_deletes_it() {
		$subject                   = new Testable_MainWP_Child_Favorites_V2( array(), array(), array() );
		$subject->durable_receipts = true;
		$ref                       = '123e4567-e89b-42d3-a456-426614173022';
		$receipt                   = $this->expired_receipt( $ref );
		$this->assertTrue( $subject->seed_durable_receipt( $ref, $receipt ) );

		$status = $subject->install_verified_v2(
			array(
				'protocol'  => '2',
				'operation' => 'status',
				'payload'   => array( 'request_ref' => $ref ),
			)
		);

		$this->assertFalse( $status['ok'] );
		$this->assertSame( 'not_found', $status['code'] );
		$this->assertSame( $receipt, get_option( 'mainwp_child_favorites_receipt_' . hash( 'sha256', $ref ), false ) );

		delete_option( 'mainwp_child_favorites_receipt_' . hash( 'sha256', $ref ) );
	}

	public function test_install_evicts_an_expired_receipt_instead_of_replaying_it() {
		$subject                   = new Testable_MainWP_Child_Favorites_V2( array(), array(), array() );
		$subject->durable_receipts = true;
		$request                   = $this->install_request();
		$ref                       = $request['payload']['request_ref'];
		$this->assertTrue( $subject->seed_durable_receipt( $ref, $this->expired_receipt( $ref ) ) );

		$result = $subject->install_verified_v2( $request );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'completed', $result['status'] );
		$this->assertSame( 1, $subject->download_count );
		$this->assertSame( 1, $subject->install_count );
		$stored = get_option( 'mainwp_child_favorites_receipt_' . hash( 'sha256', $ref ), false );
		$this->assertSame( 'settled', $stored['state'] );
		$this->assertSame( $result, $stored['result'] );

		delete_option( 'mainwp_child_favorites_receipt_' . hash( 'sha256', $ref ) );
	}

	public function test_aged_dispatching_receipt_is_unknown_and_never_installs_again() {
		$subject                   = new Testable_MainWP_Child_Favorites_V2( array(), array(), array() );
		$subject->durable_receipts = true;
		$request                   = $this->install_request();
		$ref                       = $request['payload']['request_ref'];
		$receipt                   = $subject->dispatching_receipt_for_test( $request );
		$receipt['updated_at']     = time() - 8 * DAY_IN_SECONDS;
		$receipt['expires_at']     = time() - DAY_IN_SECONDS;
		$this->assertTrue( $subject->seed_durable_receipt( $ref, $receipt ) );

		$result = $subject->install_verified_v2( $request );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'unknown', $result['status'] );
		$this->assertSame( 'outcome_unknown', $result['code'] );
		$this->assertSame( 0, $subject->download_count );
		$this->assertSame( 0, $subject->install_count );
		$this->assertSame( $receipt, get_option( 'mainwp_child_favorites_receipt_' . hash( 'sha256', $ref ), false ) );

		$status = $subject->install_verified_v2(
			array(
				'protocol'  => '2',
				'operation' => 'status',
				'payload'   => array( 'request_ref' => $ref ),
			)
		);
		$this->assertSame( 'unknown', $status['status'] );
		$this->assertSame( 'outcome_unknown', $status['code'] );

		delete_option( 'mainwp_child_favorites_receipt_' . hash( 'sha256', $ref ) );
	}

	public function test_an_expired_receipt_that_did_not_clear_keeps_defending_the_replay() {
		$request = $this->install_request();
		$ref     = $request['payload']['request_ref'];
		$option  = 'mainwp_child_favorites_receipt_' . hash( 'sha256', $ref );

		$stuck                      = $this->installed_subject();
		$stuck->receipt_delete_mode = 'no_op';
		$receipt                    = $this->settled_expired_receipt( $ref, $stuck->effect_hash_for_test( $request['payload'] ) );
		$this->assertTrue( $stuck->seed_durable_receipt( $ref, $receipt ) );

		$replayed = $stuck->install_verified_v2( $request );
		$this->assertTrue( $replayed['ok'] );
		$this->assertSame( 'completed', $replayed['status'] );
		$this->assertSame( 0, $stuck->download_count );
		$this->assertSame( 0, $stuck->install_count );
		$this->assertSame( $receipt, get_option( $option, false ) );

		$broken                      = $this->installed_subject();
		$broken->receipt_delete_mode = 'corrupt';
		$unreadable                  = $broken->install_verified_v2( $request );
		$this->assertFalse( $unreadable['ok'] );
		$this->assertSame( 'storage_unavailable', $unreadable['code'] );
		$this->assertSame( 0, $broken->install_count );

		delete_option( $option );
	}

	public function test_a_second_install_is_refused_while_the_install_lane_is_held() {
		$subject = new Testable_MainWP_Child_Favorites_V2( array(), array(), array() );
		$this->assertTrue( add_option( 'mainwp_child_favorites_install_lock', $this->lane_row( '123e4567-e89b-42d3-a456-426614173030', time() + 300 ), '', false ) );

		$result = $subject->install_verified_v2( $this->install_request() );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'lock_busy', $result['code'] );
		$this->assertSame( 0, $subject->download_count );
		$this->assertSame( 0, $subject->install_count );
		$this->assertSame( array(), $subject->receipts );

		delete_option( 'mainwp_child_favorites_install_lock' );
	}

	/**
	 * The lane is only a lane if the database decides who holds it. This seeds a live lane row and
	 * then puts the options cache in the state a SQL-written row really can leave it in, where
	 * get_option() reports the option missing. An acquirer that decides from that read takes the
	 * lane and overwrites the holder; one that decides from an insert against the unique option
	 * name cannot, whatever the read says.
	 */
	public function test_the_install_lane_is_decided_by_the_row_not_by_a_cached_read() {
		global $wpdb;

		$held = $this->lane_row( '123e4567-e89b-42d3-a456-426614173032', time() + 300 );
		$this->assertSame( 1, $wpdb->query( $wpdb->prepare( "INSERT INTO $wpdb->options ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )", 'mainwp_child_favorites_install_lock', $held ) ) );
		wp_cache_delete( 'mainwp_child_favorites_install_lock', 'options' );
		wp_cache_set( 'notoptions', array( 'mainwp_child_favorites_install_lock' => true ), 'options' );
		$this->assertFalse( get_option( 'mainwp_child_favorites_install_lock', false ) );

		$subject = new Testable_MainWP_Child_Favorites_V2( array(), array(), array() );
		$result  = $subject->install_verified_v2( $this->install_request() );

		$this->assertSame( 'lock_busy', $result['code'] );
		$this->assertSame( 0, $subject->download_count );
		$this->assertSame( 0, $subject->install_count );
		$this->assertSame( $held, $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM $wpdb->options WHERE option_name = %s", 'mainwp_child_favorites_install_lock' ) ) );
		// The refused acquire also left the cache agreeing with the row instead of still lying.
		$this->assertSame( $held, get_option( 'mainwp_child_favorites_install_lock', false ) );

		delete_option( 'mainwp_child_favorites_install_lock' );
	}

	/**
	 * A lane row is not a receipt. A receipt carries evidence about an effect that may already have
	 * happened, so this file refuses to forget one; a lane row only asserts that some request held
	 * the lane, and a row nobody can parse does not even assert that. Refusing on its behalf would
	 * brick installs permanently with no operator escape.
	 */
	public function test_an_unparseable_lane_row_is_taken_over_instead_of_refusing_forever() {
		$this->assertTrue( add_option( 'mainwp_child_favorites_install_lock', 'not-a-lane-row', '', false ) );

		$subject = new Testable_MainWP_Child_Favorites_V2( array(), array(), array() );
		$result  = $subject->install_verified_v2( $this->install_request() );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'completed', $result['status'] );
		$this->assertSame( 1, $subject->install_count );
		// Taken over, not ignored: the unreadable row was gone and this request's own lane stood in
		// its place while the package was fetched.
		$this->assertIsArray( $subject->lock_during_download );
		$this->assertArrayHasKey( 'owner', $subject->lock_during_download );
		$this->assertFalse( get_option( 'mainwp_child_favorites_install_lock', false ) );
	}

	/**
	 * An empty lane row exists, it just asserts nothing, and get_var() reports it as null exactly
	 * like an absent row. An acquirer deciding from that read refuses forever against a row no
	 * operator knows to look for. Seeded autoloaded because that is how a row this code did not
	 * write reaches the alloptions bucket, where the value it held would go on shadowing the
	 * replacement lane after the matched delete.
	 */
	public function test_an_empty_lane_row_is_taken_over_instead_of_refusing_forever() {
		$this->assertTrue( add_option( 'mainwp_child_favorites_install_lock', '', '', true ) );

		$subject = new Testable_MainWP_Child_Favorites_V2( array(), array(), array() );
		$result  = $subject->install_verified_v2( $this->install_request() );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'completed', $result['status'] );
		$this->assertSame( 1, $subject->install_count );
		// Taken over, not ignored: this request's own well-formed row stood in the empty one's place
		// while the package was fetched, and reads saw it rather than the stale autoloaded value.
		$this->assertIsArray( $subject->lock_during_download );
		$this->assertArrayHasKey( 'owner', $subject->lock_during_download );
		$this->assertFalse( get_option( 'mainwp_child_favorites_install_lock', false ) );
	}

	public function test_a_failed_install_releases_the_lane_for_the_next_request() {
		$subject                            = new Testable_MainWP_Child_Favorites_V2( array(), array(), array() );
		$subject->downloaded_package_digest = str_repeat( 'b', 64 );
		// Caches "no such option" first, so the lane read taken mid-install has to survive the
		// notoptions bucket rather than be served by it.
		$this->assertFalse( get_option( 'mainwp_child_favorites_install_lock', false ) );

		$failed = $subject->install_verified_v2( $this->install_request() );

		$this->assertSame( 'digest_mismatch', $failed['code'] );
		// Releasing only means something if the lane was actually held while the package was fetched.
		$this->assertIsArray( $subject->lock_during_download );
		$this->assertFalse( get_option( 'mainwp_child_favorites_install_lock', false ) );

		$subject->downloaded_package_digest = str_repeat( 'a', 64 );
		$retry                              = $this->install_request();
		$retry['payload']['request_ref']    = '123e4567-e89b-42d3-a456-426614173031';

		$second = $subject->install_verified_v2( $retry );

		$this->assertTrue( $second['ok'] );
		$this->assertSame( 'completed', $second['status'] );
		$this->assertSame( 1, $subject->install_count );
	}

	public function test_a_settled_install_result_survives_a_lost_lane_release() {
		$subject = new Lock_Release_Loss_MainWP_Child_Favorites_V2( array(), array(), array() );
		$request = $this->install_request();

		$result = $subject->install_verified_v2( $request );

		$this->assertIsArray( $subject->lock_during_download );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'completed', $result['status'] );
		$this->assertNull( $result['code'] );
		$this->assertSame( 1, $subject->install_count );
		$this->assertSame( $result, $subject->receipts[ $request['payload']['request_ref'] ]['result'] );
	}

	public function test_single_file_plugin_archive_must_stay_at_the_archive_root() {
		if ( ! class_exists( '\ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive is unavailable.' );
		}
		$probe = new Production_MainWP_Child_Favorites_Package_Probe();

		$valid = wp_tempnam( 'mainwp-favorites-single.zip' );
		$zip   = new \ZipArchive();
		$this->assertTrue( $zip->open( $valid, \ZipArchive::OVERWRITE ) );
		$zip->addFromString( 'hello.php', "<?php\n/*\nPlugin Name: Hello\nVersion: 1.2.3\n*/\n" );
		$zip->close();
		$this->assertTrue( $probe->inspect( $valid, 'plugin', 'hello.php', '1.2.3' ) );
		unlink( $valid );

		$hostile = wp_tempnam( 'mainwp-favorites-escape.zip' );
		$zip     = new \ZipArchive();
		$this->assertTrue( $zip->open( $hostile, \ZipArchive::OVERWRITE ) );
		$zip->addFromString( 'hello.php', "<?php\n/*\nPlugin Name: Hello\nVersion: 1.2.3\n*/\n" );
		$zip->addFromString( 'other-plugin/evil.php', '<?php // payload' );
		$zip->close();
		$this->assertFalse( $probe->inspect( $hostile, 'plugin', 'hello.php', '1.2.3' ) );
		unlink( $hostile );

		$nested = wp_tempnam( 'mainwp-favorites-nested.zip' );
		$zip    = new \ZipArchive();
		$this->assertTrue( $zip->open( $nested, \ZipArchive::OVERWRITE ) );
		$zip->addFromString( 'forms/forms.php', "<?php\n/*\nPlugin Name: Forms\nVersion: 2.1.0\n*/\n" );
		$zip->addFromString( 'forms/readme.txt', 'fixture' );
		$zip->close();
		$this->assertTrue( $probe->inspect( $nested, 'plugin', 'forms/forms.php', '2.1.0' ) );
		unlink( $nested );
	}

	public function test_uuid_alias_and_callable_map_are_closed() {
		$subject = new Testable_MainWP_Child_Favorites_V2( array(), array(), array() );
		$request = $this->state_request( 'plugin', 'forms/forms.php' );
		$request['request_id'] = $request['request_ref'];
		unset( $request['request_ref'] );
		$this->assertSame( 'invalid_request', $subject->package_state_v2( $request )['code'] );

		$reflection = new ReflectionClass( MainWP_Child_Callable::class );
		$property   = $reflection->getProperty( 'callableFunctions' );
		$property->setAccessible( true );
		$callables = $property->getValue( MainWP_Child_Callable::get_instance() );
		$this->assertSame( 'favorites_package_state_v2', $callables['favorites_package_state_v2'] );
		$this->assertSame( 'favorites_install_verified_v2', $callables['favorites_install_verified_v2'] );
	}

	/**
	 * WP_Upgrader::run() does not initialise the instance for you. Without init() the string table
	 * it reports errors from is empty and the skin has no upgrader to read it through, so this runs
	 * the real upgrader far enough to look at it and halts before any download or unpack.
	 */
	public function test_real_upgrader_is_initialised_before_run() {
		$captured = null;
		$halt     = static function ( $reply, $package, $upgrader ) use ( &$captured ) {
			unset( $reply, $package );
			$captured = $upgrader;
			return new \WP_Error( 'mainwp_favorites_test_halt', 'Halted before download.' );
		};
		add_filter( 'upgrader_pre_download', $halt, 10, 3 );

		$package = wp_tempnam( 'mainwp-favorites-upgrader.zip' );
		$level   = ob_get_level();
		ob_start();
		try {
			( new Upgrader_Probe_MainWP_Child_Favorites_V2() )->run_dispatch_install(
				$package,
				array( 'type' => 'plugin', 'slug' => 'forms/forms.php', 'overwrite' => false, 'activate' => false )
			);
		} finally {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
			remove_filter( 'upgrader_pre_download', $halt, 10 );
			unlink( $package );
		}

		if ( ! $captured instanceof \WP_Upgrader ) {
			$this->markTestSkipped( 'WP_Filesystem did not connect, so run() never reached the download stage.' );
		}
		$this->assertNotEmpty( $captured->strings );
		$this->assertSame( $captured, $captured->skin->upgrader );
	}

	/**
	 * A moved theme root announces itself through the theme_root filter, which is all that
	 * separates get_theme_root() from the WP_CONTENT_DIR constant; registering a second theme
	 * directory alone never moves core's no-argument return. Core's Theme_Upgrader::install()
	 * honors the filter, so a theme unpacked anywhere else is invisible to WordPress while the
	 * install still reports success. The destination is read off core's own package-options
	 * filter, before any download, so nothing between dispatch_install() and the upgrader is
	 * stubbed.
	 */
	public function test_theme_install_destination_honors_a_moved_theme_root() {
		global $wp_theme_directories;

		$alt_root     = WP_CONTENT_DIR . '/mainwp-favorites-alt-themes';
		$created_root = false;
		if ( ! is_dir( $alt_root ) ) {
			mkdir( $alt_root );
			$created_root = true;
		}
		register_theme_directory( $alt_root );
		$move_root = static function () use ( $alt_root ) {
			return $alt_root;
		};
		add_filter( 'theme_root', $move_root );

		$destination = null;
		$capture     = static function ( $options ) use ( &$destination ) {
			$destination = isset( $options['destination'] ) ? $options['destination'] : null;
			return $options;
		};
		add_filter( 'upgrader_package_options', $capture );
		$halt = static function () {
			return new \WP_Error( 'mainwp_favorites_test_halt', 'Halted before download.' );
		};
		add_filter( 'upgrader_pre_download', $halt );

		$package = wp_tempnam( 'mainwp-favorites-theme-root.zip' );
		$level   = ob_get_level();
		ob_start();
		try {
			( new Upgrader_Probe_MainWP_Child_Favorites_V2() )->run_dispatch_install(
				$package,
				array( 'type' => 'theme', 'slug' => 'twentyalt', 'overwrite' => false, 'activate' => false )
			);
		} finally {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
			remove_filter( 'upgrader_pre_download', $halt );
			remove_filter( 'upgrader_package_options', $capture );
			remove_filter( 'theme_root', $move_root );
			if ( is_array( $wp_theme_directories ) ) {
				$wp_theme_directories = array_values( array_diff( $wp_theme_directories, array( untrailingslashit( $alt_root ) ) ) );
			}
			unlink( $package );
			if ( $created_root && is_dir( $alt_root ) ) {
				rmdir( $alt_root );
			}
		}

		$this->assertSame( $alt_root, $destination );
	}

	/**
	 * A request reference is one-shot, so nothing ever comes back to the row it created and the
	 * eviction on the install path only reaches the reference the current request names. Every
	 * other expired row is left behind, and the index is what a later install finds them by.
	 */
	public function test_an_install_reclaims_a_leaked_receipt_row_from_an_earlier_reference() {
		$subject                   = new Testable_MainWP_Child_Favorites_V2( array(), array(), array() );
		$subject->durable_receipts = true;
		$leaked                    = '123e4567-e89b-42d3-a456-426614173040';
		$leaked_key                = 'mainwp_child_favorites_receipt_' . hash( 'sha256', $leaked );
		$key                       = 'mainwp_child_favorites_receipt_' . hash( 'sha256', '123e4567-e89b-42d3-a456-426614173021' );
		$this->assertTrue( add_option( $leaked_key, $this->expired_receipt( $leaked ), '', false ) );
		$this->assertTrue( update_option( 'mainwp_child_favorites_install_index', array( array( 'key' => $leaked_key, 'expires_at' => time() - DAY_IN_SECONDS ) ), false ) );

		$result = $subject->install_verified_v2( $this->install_request() );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'completed', $result['status'] );
		$this->assertNull( $this->cold_option( $leaked_key ) );
		// The reclaimed entry left with its row, and this install's own row took its place.
		$this->assertSame( array( $key ), wp_list_pluck( get_option( 'mainwp_child_favorites_install_index', array() ), 'key' ) );

		delete_option( $key );
		delete_option( 'mainwp_child_favorites_install_index' );
	}

	/**
	 * A row nothing can parse is exactly the row a resent request still needs standing in its way:
	 * deleting one would let the same reference install the package a second time.
	 */
	public function test_a_malformed_indexed_receipt_row_is_kept_rather_than_reclaimed() {
		$subject                   = new Testable_MainWP_Child_Favorites_V2( array(), array(), array() );
		$subject->durable_receipts = true;
		$malformed                 = 'mainwp_child_favorites_receipt_' . hash( 'sha256', '123e4567-e89b-42d3-a456-426614173041' );
		$this->assertTrue( add_option( $malformed, array( 'effect_hash' => 'unreadable' ), '', false ) );
		update_option( 'mainwp_child_favorites_install_index', array( array( 'key' => $malformed, 'expires_at' => time() - DAY_IN_SECONDS ) ), false );

		$this->assertTrue( $subject->install_verified_v2( $this->install_request() )['ok'] );

		$this->assertSame( array( 'effect_hash' => 'unreadable' ), $this->cold_option( $malformed ) );
		$this->assertContains( $malformed, wp_list_pluck( get_option( 'mainwp_child_favorites_install_index', array() ), 'key' ) );

		delete_option( $malformed );
		delete_option( 'mainwp_child_favorites_receipt_' . hash( 'sha256', '123e4567-e89b-42d3-a456-426614173021' ) );
		delete_option( 'mainwp_child_favorites_install_index' );
	}

	/**
	 * A dispatch marker never expires into silence however old it is: its effect was never resolved,
	 * so the sweep reads the same refusal out of the retention predicate that the install path does.
	 */
	public function test_an_indexed_dispatch_marker_is_kept_however_old_it_is() {
		$subject                   = new Testable_MainWP_Child_Favorites_V2( array(), array(), array() );
		$subject->durable_receipts = true;
		$aged                      = '123e4567-e89b-42d3-a456-426614173042';
		$aged_key                  = 'mainwp_child_favorites_receipt_' . hash( 'sha256', $aged );
		$marker                    = $this->expired_receipt( $aged );
		$marker['state']           = 'dispatching';
		$marker['result']          = null;
		$this->assertTrue( add_option( $aged_key, $marker, '', false ) );
		update_option( 'mainwp_child_favorites_install_index', array( array( 'key' => $aged_key, 'expires_at' => time() - DAY_IN_SECONDS ) ), false );

		$this->assertTrue( $subject->install_verified_v2( $this->install_request() )['ok'] );

		$this->assertSame( $marker, $this->cold_option( $aged_key ) );
		$this->assertContains( $aged_key, wp_list_pluck( get_option( 'mainwp_child_favorites_install_index', array() ), 'key' ) );

		delete_option( $aged_key );
		delete_option( 'mainwp_child_favorites_receipt_' . hash( 'sha256', '123e4567-e89b-42d3-a456-426614173021' ) );
		delete_option( 'mainwp_child_favorites_install_index' );
	}

	/**
	 * Read one option past the request-local cache.
	 *
	 * update_option() primes that cache, so a row asserted straight after a write reads back from
	 * memory whether or not the store ever kept it.
	 */
	private function cold_option( $key ) {
		wp_cache_delete( $key, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		return get_option( $key, null );
	}

	private function installed_subject() {
		$subject = new Testable_MainWP_Child_Favorites_V2(
			array( 'forms/forms.php' => array( 'Version' => '2.1.0' ) ),
			array( 'forms/forms.php' ),
			array()
		);
		$subject->durable_receipts = true;
		return $subject;
	}

	private function settled_expired_receipt( $ref, $effect_hash ) {
		return array(
			'effect_hash'      => $effect_hash,
			'state'            => 'settled',
			'request_ref'      => $ref,
			'type'             => 'plugin',
			'slug'             => 'forms/forms.php',
			'expected_version' => '2.1.0',
			'activate'         => true,
			'installed'        => false,
			'previous_version' => null,
			'previous_active'  => null,
			'result'           => array(
				'protocol'    => '2',
				'operation'   => 'install',
				'ok'          => true,
				'request_ref' => $ref,
				'status'      => 'completed',
				'installed'   => true,
				'type'        => 'plugin',
				'slug'        => 'forms/forms.php',
				'version'     => '2.1.0',
				'active'      => true,
				'code'        => null,
			),
			'updated_at'       => time() - 8 * DAY_IN_SECONDS,
			'expires_at'       => time() - DAY_IN_SECONDS,
		);
	}

	private function expired_receipt( $ref ) {
		return array(
			'effect_hash'      => str_repeat( 'c', 64 ),
			'state'            => 'settled',
			'request_ref'      => $ref,
			'type'             => 'plugin',
			'slug'             => 'forms/forms.php',
			'expected_version' => '2.1.0',
			'activate'         => true,
			'installed'        => false,
			'previous_version' => null,
			'previous_active'  => null,
			'result'           => array(
				'protocol'    => '2',
				'operation'   => 'install',
				'ok'          => false,
				'request_ref' => $ref,
				'status'      => 'failed',
				'installed'   => false,
				'type'        => 'plugin',
				'slug'        => 'forms/forms.php',
				'version'     => null,
				'active'      => null,
				'code'        => 'download_failed',
			),
			'updated_at'       => time() - 8 * DAY_IN_SECONDS,
			'expires_at'       => time() - DAY_IN_SECONDS,
		);
	}

	private function lane_row( $owner, $expires_at ) {
		return wp_json_encode(
			array(
				'owner'      => $owner,
				'expires_at' => $expires_at,
			)
		);
	}

	private function state_request( $type, $slug ) {
		return array(
			'protocol'    => '2',
			'operation'   => 'package_state',
			'request_ref' => '123e4567-e89b-42d3-a456-426614173020',
			'type'        => $type,
			'slug'        => $slug,
		);
	}

	private function install_request() {
		return array(
			'protocol'  => '2',
			'operation' => 'install',
			'payload'   => array(
				'request_ref'     => '123e4567-e89b-42d3-a456-426614173021',
				'type'            => 'plugin',
				'slug'            => 'forms/forms.php',
				'expected_version' => '2.1.0',
				'expected_sha256' => str_repeat( 'a', 64 ),
				'download_url'    => 'https://example.com/private/favorite',
				'overwrite'       => true,
				'activate'        => true,
				'state_generation' => hash( 'sha256', wp_json_encode( array( 'type' => 'plugin', 'slug' => 'forms/forms.php', 'installed' => false, 'version' => null, 'active' => null ) ) ),
			),
		);
	}
}

class Testable_MainWP_Child_Favorites_V2 extends MainWP_Child_Favorites {

	private $test_plugins;

	private $test_active_plugins;

	private $test_themes;

	public $download_count = 0;

	public $install_count = 0;

	public $cleanup_count = 0;

	public $cache_refresh_count = 0;

	public $downloaded_package_digest;

	/** The install lane as it stood while the package was being fetched. */
	public $lock_during_download = false;

	public $receipts = array();

	public $durable_receipts = false;

	/** One of 'normal', 'no_op' (delete silently fails) or 'corrupt' (delete leaves an unreadable receipt). */
	public $receipt_delete_mode = 'normal';

	public function __construct( $plugins, $active_plugins, $themes ) {
		$this->test_plugins        = $plugins;
		$this->test_active_plugins = $active_plugins;
		$this->test_themes         = $themes;
		$this->downloaded_package_digest = str_repeat( 'a', 64 );
	}

	protected function package_runtime() {
		return array(
			'plugins'        => $this->test_plugins,
			'active_plugins' => $this->test_active_plugins,
			'themes'         => $this->test_themes,
		);
	}

	protected function download_package( $url ) {
		unset( $url );
		++$this->download_count;
		$held                       = get_option( 'mainwp_child_favorites_install_lock', '' );
		$this->lock_during_download = is_string( $held ) && '' !== $held ? json_decode( $held, true ) : false;
		return '/private/tmp/favorites-fixture.zip';
	}

	protected function package_digest( $path ) {
		unset( $path );
		return $this->downloaded_package_digest;
	}

	protected function inspect_package( $path, $type, $slug, $version ) {
		unset( $path, $type, $slug, $version );
		return true;
	}

	protected function dispatch_install( $path, $payload ) {
		unset( $path );
		++$this->install_count;
		$this->test_plugins[ $payload['slug'] ] = array( 'Version' => $payload['expected_version'] );
		if ( $payload['activate'] ) {
			$this->test_active_plugins[] = $payload['slug'];
		}
		return true;
	}

	protected function refresh_package_cache( $type ) {
		unset( $type );
		++$this->cache_refresh_count;
	}

	protected function cleanup_package( $path ) {
		unset( $path );
		++$this->cleanup_count;
	}

	protected function load_install_receipt( $request_ref ) {
		if ( $this->durable_receipts ) {
			return parent::load_install_receipt( $request_ref );
		}
		return isset( $this->receipts[ $request_ref ] ) ? $this->receipts[ $request_ref ] : null;
	}

	protected function create_install_receipt( $request_ref, $receipt ) {
		if ( $this->durable_receipts ) {
			return parent::create_install_receipt( $request_ref, $receipt );
		}
		if ( isset( $this->receipts[ $request_ref ] ) ) {
			return false;
		}
		$this->receipts[ $request_ref ] = $receipt;
		return true;
	}

	protected function settle_install_receipt( $request_ref, $expected, $receipt ) {
		if ( $this->durable_receipts ) {
			return parent::settle_install_receipt( $request_ref, $expected, $receipt );
		}
		if ( ! isset( $this->receipts[ $request_ref ] ) || $expected !== $this->receipts[ $request_ref ] ) {
			return false;
		}
		$this->receipts[ $request_ref ] = $receipt;
		return true;
	}

	protected function delete_install_receipt( $request_ref ) {
		if ( 'no_op' === $this->receipt_delete_mode ) {
			return false;
		}
		if ( 'corrupt' === $this->receipt_delete_mode ) {
			update_option( 'mainwp_child_favorites_receipt_' . hash( 'sha256', $request_ref ), array( 'effect_hash' => 'unreadable' ), false );
			return false;
		}
		if ( $this->durable_receipts ) {
			return parent::delete_install_receipt( $request_ref );
		}
		unset( $this->receipts[ $request_ref ] );
		return true;
	}

	public function seed_durable_receipt( $request_ref, $receipt ) {
		return parent::create_install_receipt( $request_ref, $receipt );
	}

	public function seed_dispatching( $request ) {
		$this->receipts[ $request['payload']['request_ref'] ] = $this->dispatching_receipt_for_test( $request );
	}

	public function dispatching_receipt_for_test( $request ) {
		return array(
			'effect_hash'      => $this->effect_hash_for_test( $request['payload'] ),
			'state'            => 'dispatching',
			'request_ref'      => $request['payload']['request_ref'],
			'type'             => $request['payload']['type'],
			'slug'             => $request['payload']['slug'],
			'expected_version' => $request['payload']['expected_version'],
			'activate'         => $request['payload']['activate'],
			'installed'        => false,
			'previous_version' => null,
			'previous_active'  => null,
			'result'           => null,
			'updated_at'       => time(),
			'expires_at'       => time() + DAY_IN_SECONDS,
		);
	}

	public function effect_hash_for_test( $payload ) {
		return $this->install_effect_hash( $payload );
	}
}

/** Frees the install lane but loses the acknowledgement, the way a failing option store would. */
class Lock_Release_Loss_MainWP_Child_Favorites_V2 extends Testable_MainWP_Child_Favorites_V2 {

	protected function end_install_lock() {
		parent::end_install_lock();
		return false;
	}
}

/** Exposes the production archive validator with no stubbed seams. */
class Production_MainWP_Child_Favorites_Package_Probe extends MainWP_Child_Favorites {

	public function inspect( $path, $type, $slug, $version ) {
		return $this->inspect_package( $path, $type, $slug, $version );
	}
}

/** Exposes the production WordPress installer dispatch with no stubbed seams. */
class Upgrader_Probe_MainWP_Child_Favorites_V2 extends MainWP_Child_Favorites {

	public function run_dispatch_install( $path, $payload ) {
		return $this->dispatch_install( $path, $payload );
	}
}
