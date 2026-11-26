<?php
/**
 * Exact plugin update contract, using real local ZIPs and the WordPress upgrader.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Child_Exact_Update;
use MainWP\Child\MainWP_Child_Callable;
use MainWP\Child\MainWP_Connect;
use MainWP\Child\MainWP_Exception;

class Test_Exact_Plugin_Update extends WP_UnitTestCase {
	private $slug = 'mainwp-exact-fixture/fixture.php';
	private $zip;
	private $offer;
	private $downloads = 0;
	private $during_download;
	private $translation_filter;
	private $offer_reads    = 0;
	private $change_package = false;
	private $fail_download  = false;
	private $events         = array();
	private static $key;

	public function setUp(): void {
		parent::setUp();
		global $wp_filter;
		$this->translation_filter = $wp_filter['async_update_translation']->callbacks ?? array();
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();
		wp_mkdir_p( WP_PLUGIN_DIR . '/mainwp-exact-fixture' );
		$this->write_installed( '1.0' );
		$this->zip = wp_tempnam( 'mainwp-exact.zip' );
		$this->write_zip( '1.1' );
		$this->offer = (object) array(
			'last_checked' => time(),
			'checked'      => array( $this->slug => '1.0' ),
			'response'     => array(
				$this->slug => (object) array(
					'slug'        => 'mainwp-exact-fixture',
					'plugin'      => $this->slug,
					'new_version' => '1.1',
					'package'     => 'https://example.org/exact.zip',
				),
			),
			'translations' => array(),
		);
		add_filter( 'pre_site_transient_update_plugins', array( $this, 'offer' ) );
		add_filter( 'upgrader_pre_download', array( $this, 'download' ), 10, 4 );
		add_filter( 'pre_http_request', array( $this, 'no_http' ), 10, 3 );
		update_option( 'active_plugins', array( $this->slug ) );
	}

	public function tearDown(): void {
		remove_filter( 'pre_site_transient_update_plugins', array( $this, 'offer' ) );
		remove_filter( 'upgrader_pre_download', array( $this, 'download' ), 10 );
		remove_filter( 'pre_http_request', array( $this, 'no_http' ) );
		global $wp_filesystem;
		$wp_filesystem->delete( WP_PLUGIN_DIR . '/mainwp-exact-fixture', true );
		if ( file_exists( $this->zip ) ) {
			unlink( $this->zip );
		}
		parent::tearDown();
	}

	private function header( $version ) {
		return "<?php\n/*\nPlugin Name: Exact fixture\nVersion: $version\n*/\n";
	}

	private function write_installed( $version ) {
		file_put_contents( WP_PLUGIN_DIR . '/' . $this->slug, $this->header( $version ) );
		clearstatcache();
	}

	private function write_zip( $version, $folder = 'mainwp-exact-fixture' ) {
		$zip = new ZipArchive();
		$zip->open( $this->zip, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString( $folder . '/fixture.php', $this->header( $version ) );
		$zip->close();
	}

	public function offer() {
		++$this->offer_reads;
		// WordPress refresh reads once, the provider once, then the upgrader.
		if ( $this->change_package && $this->offer_reads >= 3 ) {
			$this->offer->response[ $this->slug ]->package = 'https://example.org/changed.zip';
		}
		return $this->offer;
	}

	public function no_http( $reply, $args, $url ) {
		if ( false !== strpos( $url, 'api.wordpress.org/' ) ) {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"offers":[],"themes":{},"plugins":{},"no_update":{},"translations":[]}',
			);
		}
		return new WP_Error( 'test_http_disabled', 'No external network in this fixture.' );
	}

	public function download( $reply, $package, $upgrader, $extra ) {
		++$this->downloads;
		$this->events[] = 'download';
		if ( $this->fail_download ) {
			return new WP_Error( 'fixture_download_failed', 'Fixture download failed.' );
		}
		if ( $this->during_download ) {
			call_user_func( $this->during_download );
		}
		// Core deletes downloaded files. Give each attempt its own copy.
		$copy = wp_tempnam( 'mainwp-exact-download.zip' );
		copy( $this->zip, $copy );
		return $copy;
	}

	private function request( $changes = array() ) {
		return ( new MainWP_Child_Exact_Update() )->update(
			array_merge(
				array(
					'slug'            => $this->slug,
					'current_version' => '1.0',
					'target_version'  => '1.1',
				),
				$changes
			)
		);
	}

	private function assert_unchanged( $version = '1.0' ) {
		$this->assertSame( $version, get_plugin_data( WP_PLUGIN_DIR . '/' . $this->slug, false, false )['Version'] );
		$this->assertContains( $this->slug, get_option( 'active_plugins' ) );
	}

	/** @group ms-required */
	public function test_multisite_refuses_before_download_or_mutation() {
		$this->skipWithoutMultisite();
		$result = $this->request();
		$this->assertWPError( $result );
		$this->assertSame( 'mainwp_exact_multisite', $result->get_error_code() );
		$this->assertSame( 0, $this->downloads );
		$this->assert_unchanged();
	}

	public function test_command_requires_regular_mainwp_authentication() {
		$callable = MainWP_Child_Callable::get_instance();
		$this->assertTrue( $callable->is_callable_function( 'upgradepluginexact' ) );
		$this->assertFalse( $callable->is_callable_function_no_auth( 'upgradepluginexact' ) );
	}

	public function test_callable_preserves_standard_error_fields() {
		$method = new ReflectionMethod( MainWP_Child_Callable::class, 'upgrade_plugin_exact' );
		$lines  = file( $method->getFileName() );
		$source = implode( '', array_slice( $lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1 ) );
		$source = str_replace( 'public function upgrade_plugin_exact()', 'return function()', $source, $declarations );
		$source = preg_replace( '/MainWP_Helper::write\( \$result \);\s*\}\s*\z/', 'return $result; };', $source, 1, $writes );
		$this->assertSame( 1, $declarations );
		$this->assertSame( 1, $writes, 'Only replace the terminal die-writing call, as in the existing callable tests.' );
		$fixture = wp_tempnam( 'mainwp-exact-reply.php' );
		file_put_contents( $fixture, '<?php namespace MainWP\\Child;' . "\n" . $source );
		$previous = $_POST;
		try {
			$_POST    = array();
			$callable = require $fixture;
			$reply    = $callable();
			$this->assertSame( 0, $reply['success'] );
			$this->assertSame( 'mainwp_exact_invalid_request', $reply['error_code'] );
			$this->assertSame( $reply['message'], $reply['error'] );
		} finally {
			$_POST = $previous;
			unlink( $fixture );
		}
	}

	/**
	 * MainWP reaches the command only after auth() accepts the request.
	 *
	 * @dataProvider signed_requests
	 */
	public function test_signature_binds_the_exact_selection( $change, $expected ) {
		$this->skipWithMultisite();
		$selection = array(
			'slug'            => $this->slug,
			'current_version' => '1.0',
			'target_version'  => '1.1',
		);
		$checked   = new ReflectionProperty( MainWP_Connect::class, 'signature_checked' );
		$checked->setAccessible( true );
		$checked->setValue( null, null );
		if ( null === self::$key ) {
			self::$key = openssl_pkey_new( array( 'private_key_bits' => 2048 ) );
		}
		update_option( 'mainwp_child_pubkey', base64_encode( openssl_pkey_get_details( self::$key )['key'] ) );
		$previous = array( $_POST, $_REQUEST );
		try {
			$_POST  = array( 'function' => 'upgradepluginexact', 'user' => 'admin', 'nonce' => wp_generate_uuid4(), 'sign_algo' => OPENSSL_ALGO_SHA256 ) + $selection;
			$signed = array( 'req_id' => wp_generate_uuid4(), 'base_function' => 'upgradepluginexact', 'user' => 'admin', 'nonce' => $_POST['nonce'], 'expires' => time() + 60 ) + $selection;
			list( $action, $field ) = explode( ' ', $change . ' ' );
			if ( 'unsigned' === $action ) {
				unset( $signed[ $field ] );
			}
			$data   = 'legacy' === $action ? null : wp_json_encode( $signed );
			$legacy = 'legacy' === $action ? 'upgradepluginexact' . $_POST['nonce'] : '';
			$this->assertTrue( openssl_sign( $legacy . $data, $signature, self::$key, OPENSSL_ALGO_SHA256 ) );
			if ( 'changed' === $action ) {
				$_POST[ $field ] = 'slug' === $field ? 'mainwp-exact-other/other.php' : '1.0.1';
			} elseif ( 'missing' === $action ) {
				unset( $_POST[ $field ] );
			} elseif ( 'array' === $action ) {
				$_POST[ $field ] = array( $_POST[ $field ] );
			}
			$_REQUEST = $_POST;
			try {
				$accepted = 1 === MainWP_Connect::instance()->auth(
					$legacy ? base64_encode( $signature ) : '',
					'upgradepluginexact',
					$_POST['nonce'],
					array(
						'connect_sign'        => $data,
						'mainwpsignature_adv' => $legacy ? '' : base64_encode( $signature ),
					)
				);
			} catch ( MainWP_Exception $error ) {
				$accepted = false;
			}
			$this->assertSame( $expected, $accepted );
			if ( $accepted ) {
				$this->assertNotWPError( ( new MainWP_Child_Exact_Update() )->update( wp_unslash( $_POST ) ) );
				$this->assert_unchanged( '1.1' );
			}
		} finally {
			list( $_POST, $_REQUEST ) = $previous;
			$checked->setValue( null, null );
		}
	}

	public function signed_requests() {
		$requests = array(
			'signed selection' => array( 'signed', true ),
			'legacy signature' => array( 'legacy', false ),
		);
		foreach ( array( 'slug', 'current_version', 'target_version' ) as $field ) {
			foreach ( array( 'changed', 'missing', 'array', 'unsigned' ) as $action ) {
				$requests[ "$action $field" ] = array( "$action $field", false );
			}
		}
		return $requests;
	}

	/** @dataProvider attempts */
	public function test_attempt_runs_mainwp_lifecycle_and_configured_cache_purge( $fail_download ) {
		$this->skipWithMultisite();
		$this->fail_download = $fail_download;
		$record = function () {
			$this->events[] = array_merge( array( current_filter() ), func_get_args() );
		};
		$purge  = function ( $option ) {
			if ( 'mainwp_cache_control_log' === $option ) {
				$this->events[] = 'purge';
			}
		};
		update_option( 'mainwp_child_auto_purge_cache', 1 );
		delete_option( 'mainwp_cache_control_log' );
		add_action( 'mainwp_child_before_update_plugin_theme', $record, 10, 0 );
		add_action( 'mainwp_child_before_update', $record, 10, 2 );
		add_action( 'mainwp_child_after_update', $record, 10, 3 );
		add_action( 'added_option', $purge );
		try {
			$this->assertSame( $fail_download, is_wp_error( $this->request() ) );
			$this->assertSame( array( 'mainwp_child_before_update_plugin_theme' ), $this->events[0] );
			$this->assertSame( array( 'mainwp_child_before_update', 'plugin', array( $this->slug ) ), $this->events[1] );
			$this->assertSame( 'download', $this->events[2] );
			list( $hook, $type, $results, $plugins ) = $this->events[3];
			$this->assertSame( array( 'mainwp_child_after_update', 'plugin', array( $this->slug ) ), array( $hook, $type, $plugins ) );
			$this->assertSame( array( $this->slug ), array_keys( $results ) );
			$this->assertSame( $fail_download, is_wp_error( $results[ $this->slug ] ) );
			$this->assertSame( array( 'purge' ), array_slice( $this->events, 4 ) );
			$this->assertFalse( get_option( 'auto_updater.lock' ), 'Purge providers must not hold the updater lock.' );
			$log = json_decode( get_option( 'mainwp_cache_control_log' ), true );
			$this->assertSame( 'provider_missing', $log['result_basis'], 'Enabled purging must be attempted, not skipped.' );
		} finally {
			remove_action( 'mainwp_child_before_update_plugin_theme', $record, 10 );
			remove_action( 'mainwp_child_before_update', $record, 10 );
			remove_action( 'mainwp_child_after_update', $record, 10 );
			remove_action( 'added_option', $purge );
		}
	}

	public function attempts() {
		return array(
			'updated'         => array( false ),
			'download failed' => array( true ),
		);
	}

	public function test_refused_update_runs_no_update_hooks_or_cache_purge() {
		$this->skipWithMultisite();
		$this->write_installed( '0.9' );
		$fired = 0;
		$count = static function () use ( &$fired ) {
			++$fired;
		};
		delete_option( 'mainwp_cache_control_log' );
		add_action( 'mainwp_child_before_update', $count );
		add_action( 'mainwp_child_after_update', $count );
		try {
			$this->assertWPError( $this->request() );
			$this->assertSame( 0, $fired );
			$this->assertFalse( get_option( 'mainwp_cache_control_log' ) );
		} finally {
			remove_action( 'mainwp_child_before_update', $count );
			remove_action( 'mainwp_child_after_update', $count );
		}
	}

	/** @dataProvider invalid_requests */
	public function test_invalid_binding_never_downloads( $changes ) {
		$result = $this->request( $changes );
		$this->assertWPError( $result );
		$this->assertSame( 'mainwp_exact_invalid_request', $result->get_error_code() );
		$this->assertSame( 0, $this->downloads );
		$this->assert_unchanged();
	}

	public function invalid_requests() {
		return array(
			array( array( 'slug' => '' ) ),
			array( array( 'slug' => array( 'a/a.php', 'b/b.php' ) ) ),
			array( array( 'slug' => 'a/a.php,b/b.php' ) ),
			array( array( 'slug' => '../fixture.php' ) ),
			array( array( 'slug' => 'a%2Fa.php' ) ),
			array( array( 'current_version' => null ) ),
			array( array( 'current_version' => array( '1.0' ) ) ),
			array( array( 'target_version' => '' ) ),
			array( array( 'target_version' => ' 1.1' ) ),
			array( array( 'target_version' => '1.0' ) ),
			array( array( 'target_version' => '0.9' ) ),
			array( array( 'target_version' => str_repeat( '1', 101 ) ) ),
		);
	}

	public function test_site_file_modification_policy_refuses_before_lock_or_download() {
		$this->skipWithMultisite();
		add_filter( 'file_mod_allowed', '__return_false' );
		try {
			$result = $this->request();
			$this->assertWPError( $result );
			$this->assertSame( 'mainwp_exact_file_mods_disabled', $result->get_error_code() );
			$this->assertSame( 0, $this->downloads );
			$this->assertFalse( get_option( 'auto_updater.lock' ) );
			$this->assert_unchanged();
		} finally {
			remove_filter( 'file_mod_allowed', '__return_false' );
		}
	}

	public function test_stale_installed_version_refuses_before_download() {
		$this->skipWithMultisite();
		$this->write_installed( '0.9' );
		$this->assertWPError( $this->request() );
		$this->assertSame( 0, $this->downloads );
		$this->assert_unchanged( '0.9' );
	}

	public function test_newer_offer_does_not_replace_approved_target() {
		$this->skipWithMultisite();
		$this->offer->response[ $this->slug ]->new_version = '1.2';
		$this->assertWPError( $this->request() );
		$this->assertSame( 0, $this->downloads );
		$this->assert_unchanged();
	}

	public function test_missing_offer_does_not_fall_back_to_install() {
		$this->skipWithMultisite();
		$this->offer->response = array();
		$this->assertWPError( $this->request() );
		$this->assertSame( 0, $this->downloads );
		$this->assert_unchanged();
	}

	public function test_wrong_archive_version_refuses_before_replacement() {
		$this->skipWithMultisite();
		$this->write_zip( '1.2' );
		$this->assertWPError( $this->request() );
		$this->assertSame( 1, $this->downloads );
		$this->assert_unchanged();
	}

	public function test_wrong_archive_directory_refuses_before_replacement() {
		$this->skipWithMultisite();
		$this->write_zip( '1.1', 'different-plugin' );
		$this->assertWPError( $this->request() );
		$this->assert_unchanged();
		$this->assertDirectoryDoesNotExist( WP_PLUGIN_DIR . '/different-plugin' );
	}

	public function test_installed_version_change_during_download_refuses() {
		$this->skipWithMultisite();
		$this->during_download = function () {
			$this->write_installed( '1.2' );
		};
		$this->assertWPError( $this->request() );
		$this->assert_unchanged( '1.2' );
	}

	public function test_busy_lock_is_not_released_by_refused_request() {
		$this->skipWithMultisite();
		$this->assertNotFalse( WP_Upgrader::create_lock( 'auto_updater' ) );
		$lock = get_option( 'auto_updater.lock' );
		$this->assertWPError( $this->request() );
		$this->assertSame( $lock, get_option( 'auto_updater.lock' ) );
		$this->assertSame( 0, $this->downloads );
		WP_Upgrader::release_lock( 'auto_updater' );
	}

	public function test_changed_package_url_refuses_even_with_same_archive_version() {
		$this->skipWithMultisite();
		$this->change_package = true;
		$result               = $this->request();
		$this->assertWPError( $result );
		$this->assertSame( 'mainwp_exact_package_mismatch', $result->get_error_code() );
		$this->assert_unchanged();
	}

	public function test_download_failure_preserves_plugin_and_releases_lock() {
		$this->skipWithMultisite();
		$this->fail_download = true;
		$result              = $this->request();
		$this->assertWPError( $result );
		$this->assertSame( 'fixture_download_failed', $result->get_error_code() );
		$this->assert_unchanged();
		$this->assertFalse( get_option( 'auto_updater.lock' ) );
	}

	public function test_lost_lock_is_not_released_and_prevents_replacement() {
		$this->skipWithMultisite();
		global $wpdb;
		$this->during_download = static function () use ( $wpdb ) {
			// Simulate another process: bypass this request's options cache.
			$wpdb->update( $wpdb->options, array( 'option_value' => 'different-owner' ), array( 'option_name' => 'auto_updater.lock' ) );
		};
		$this->assertWPError( $this->request() );
		$this->assert_unchanged();
		$this->assertSame( 'different-owner', $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'auto_updater.lock' ) ) );
		delete_option( 'auto_updater.lock' );
	}

	public function test_exact_archive_updates_only_selected_plugin_and_cleans_guards() {
		$this->skipWithMultisite();
		wp_mkdir_p( WP_PLUGIN_DIR . '/mainwp-exact-other' );
		$other = WP_PLUGIN_DIR . '/mainwp-exact-other/other.php';
		file_put_contents( $other, $this->header( '9.0' ) );
		try {
			$result = $this->request();
			$this->assertNotWPError( $result );
			$this->assertSame(
				array(
					'success'         => 1,
					'contract'        => 'mainwp.plugin-update-exact.v1',
					'slug'            => $this->slug,
					'current_version' => '1.0',
					'target_version'  => '1.1',
				),
				$result
			);
			$this->assert_unchanged( '1.1' );
			$this->assertSame( '9.0', get_plugin_data( $other, false, false )['Version'] );
			$this->assertFalse( get_option( 'auto_updater.lock' ) );
			$this->assertSame( $this->translation_filter, $GLOBALS['wp_filter']['async_update_translation']->callbacks ?? array() );
		} finally {
			unlink( $other );
			rmdir( dirname( $other ) );
		}
	}
}
