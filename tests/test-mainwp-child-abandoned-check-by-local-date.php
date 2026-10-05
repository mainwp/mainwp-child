<?php
/**
 * Abandoned check by local file date tests.
 *
 * @package MainWP_Child
 */

namespace MainWP\Child;

use WP_Error;
use WP_UnitTestCase;

class Test_MainWP_Child_Abandoned_Check_By_Local_Date extends WP_UnitTestCase {

	const LOCAL_DATE_OPTION = 'mainwp_child_abandoned_check_by_local_date';

	const DAYS_OPTION = 'mainwp_child_plugintheme_days_outdate';

	const TRANSIENTS = array(
		'plugin' => array(
			'rows'     => 'mainwp_child_tran_name_plugin_timestamps',
			'no_date'  => 'mainwp_child_tran_name_plugins_no_date',
			'to_batch' => 'mainwp_child_tran_name_plugins_to_batch',
		),
		'theme'  => array(
			'rows'     => 'mainwp_child_tran_name_theme_timestamps',
			'no_date'  => 'mainwp_child_tran_name_themes_no_date',
			'to_batch' => 'mainwp_child_tran_name_themes_to_batch',
		),
	);

	/**
	 * Fixture directories created in the shared plugins and themes folders.
	 *
	 * @var string[]
	 */
	private $fixture_dirs = array();

	public function setUp(): void {
		parent::setUp();

		delete_option( self::LOCAL_DATE_OPTION );
		delete_option( self::DAYS_OPTION );
		$this->clear_package_caches();
	}

	public function tearDown(): void {
		unset( $_POST['abandonedCheckByLocalDate'] );

		foreach ( $this->fixture_dirs as $dir ) {
			$this->remove_dir( $dir );
		}
		$this->fixture_dirs = array();
		$this->clear_package_caches();

		parent::tearDown();
	}

	public function kinds() {
		return array(
			'plugin' => array( 'plugin' ),
			'theme'  => array( 'theme' ),
		);
	}

	public function wordpress_org_answers_without_a_date() {
		$cases = array();
		foreach ( array( 'plugin', 'theme' ) as $kind ) {
			$cases[ "$kind not found" ]      = array( $kind, 404, '' );
			$cases[ "$kind empty body" ]     = array( $kind, 200, '' );
			$cases[ "$kind null body" ]      = array( $kind, 200, 'N;' );
			$cases[ "$kind body lacks date" ] = array( $kind, 200, serialize( (object) array( 'name' => 'Premium' ) ) ); // phpcs:ignore -- matches the WordPress.org 1.0 API format.
		}
		return $cases;
	}

	public function wordpress_org_failures() {
		$cases = array();
		foreach ( array( 'plugin', 'theme' ) as $kind ) {
			$cases[ "$kind request error" ] = array( $kind, 'error' );
			$cases[ "$kind server error" ]  = array( $kind, 503 );
		}
		return $cases;
	}

	/**
	 * @dataProvider kinds
	 */
	public function test_local_date_check_is_off_by_default( $kind ) {
		$slug = $this->install( $kind, 'off-by-default', $this->days_ago( 400 ) );
		$this->set_no_date( $kind, array( $slug ) );

		$this->assertSame( 0, (int) get_option( self::LOCAL_DATE_OPTION, 0 ) );
		$this->assertArrayNotHasKey( $slug, $this->outdate_info( $kind ) );
	}

	/**
	 * @dataProvider kinds
	 */
	public function test_local_date_check_reports_undated_item_with_old_main_file( $kind ) {
		$this->enable_local_date_check();
		$mtime = $this->days_ago( 400 );
		$slug  = $this->install( $kind, 'old', $mtime );
		$this->set_no_date( $kind, array( $slug ) );

		$info = $this->outdate_info( $kind );

		$this->assertArrayHasKey( $slug, $info );
		$this->assertSame( $this->expected_row( $kind, 'old', $mtime ), $info[ $slug ] );
	}

	/**
	 * @dataProvider kinds
	 */
	public function test_local_date_check_skips_recently_modified_main_file( $kind ) {
		$this->enable_local_date_check();
		$slug = $this->install( $kind, 'recent', $this->days_ago( 30 ) );
		$this->set_no_date( $kind, array( $slug ) );

		$this->assertArrayNotHasKey( $slug, $this->outdate_info( $kind ) );
	}

	/**
	 * @dataProvider kinds
	 */
	public function test_local_date_check_uses_the_configured_day_threshold( $kind ) {
		$this->enable_local_date_check();
		update_option( self::DAYS_OPTION, 30 );
		$at_threshold    = $this->install( $kind, 'at-threshold', $this->days_ago( 30 ) );
		$below_threshold = $this->install( $kind, 'below-threshold', $this->days_ago( 29 ) );
		$this->set_no_date( $kind, array( $at_threshold, $below_threshold ) );

		$info = $this->outdate_info( $kind );

		$this->assertArrayHasKey( $at_threshold, $info );
		$this->assertArrayNotHasKey( $below_threshold, $info );
	}

	/**
	 * @dataProvider kinds
	 */
	public function test_local_date_check_never_replaces_a_wordpress_org_row( $kind ) {
		$this->enable_local_date_check();
		$slug = $this->install( $kind, 'dated', $this->days_ago( 400 ) );
		$row  = array(
			'Name'         => 'Dated by WordPress.org',
			'Version'      => '9.9.9',
			'last_updated' => $this->days_ago( 800 ),
		);
		set_transient( self::TRANSIENTS[ $kind ]['rows'], array( $slug => $row ), 2 * DAY_IN_SECONDS );
		$this->set_no_date( $kind, array( $slug ) );

		$this->assertSame( array( $slug => $row ), $this->outdate_info( $kind ) );
	}

	/**
	 * @dataProvider kinds
	 */
	public function test_local_date_check_only_considers_items_wordpress_org_could_not_date( $kind ) {
		$this->enable_local_date_check();
		$dated   = $this->install( $kind, 'listed', $this->days_ago( 400 ) );
		$undated = $this->install( $kind, 'not-listed', $this->days_ago( 400 ) );
		$row     = array(
			'Name'         => 'Dated by WordPress.org',
			'Version'      => '1.0.0',
			'last_updated' => $this->days_ago( 500 ),
		);
		set_transient( self::TRANSIENTS[ $kind ]['rows'], array( $dated => $row ), 2 * DAY_IN_SECONDS );

		$this->assertSame( array( $dated => $row ), $this->outdate_info( $kind ), 'Without an undated list the WordPress.org rows pass through untouched.' );
		$this->assertArrayNotHasKey( $undated, $this->outdate_info( $kind ) );
	}

	/**
	 * @dataProvider kinds
	 */
	public function test_local_date_check_ignores_undated_slugs_that_are_no_longer_installed( $kind ) {
		$this->enable_local_date_check();
		$missing = 'plugin' === $kind ? 'mainwp-local-date-gone/mainwp-local-date-gone.php' : 'mainwp-local-date-gone';
		$this->set_no_date( $kind, array( $missing ) );

		$this->assertArrayNotHasKey( $missing, $this->outdate_info( $kind ) );
	}

	public function test_dashboard_sync_saves_the_local_date_flag() {
		$_POST['abandonedCheckByLocalDate'] = '1';
		MainWP_Child_Plugins_Check::may_outdate_number_change();
		$this->assertSame( 1, (int) get_option( self::LOCAL_DATE_OPTION ) );

		unset( $_POST['abandonedCheckByLocalDate'] );
		MainWP_Child_Plugins_Check::may_outdate_number_change();
		$this->assertSame( 1, (int) get_option( self::LOCAL_DATE_OPTION ), 'A dashboard that does not send the flag leaves the saved value alone.' );

		$_POST['abandonedCheckByLocalDate'] = '0';
		MainWP_Child_Plugins_Check::may_outdate_number_change();
		$this->assertSame( 0, (int) get_option( self::LOCAL_DATE_OPTION ) );
	}

	/**
	 * @dataProvider wordpress_org_answers_without_a_date
	 */
	public function test_run_check_lists_items_wordpress_org_cannot_date( $kind, $code, $body ) {
		$slug = $this->install( $kind, 'premium', $this->days_ago( 400 ) );
		$this->queue_batch( $kind, $slug );
		$this->stub_wordpress_org( $this->http_response( $code, $body ) );

		$this->checker( $kind )->run_check();

		$this->assertSame( array( $slug => 1 ), get_transient( self::TRANSIENTS[ $kind ]['no_date'] ) );
		$this->assertSame( array(), get_transient( self::TRANSIENTS[ $kind ]['rows'] ) );

		$this->enable_local_date_check();
		$info = $this->outdate_info( $kind );
		$this->assertArrayHasKey( $slug, $info );
		$this->assertSame( 'file_mtime', $info[ $slug ]['detection'] );
	}

	/**
	 * @dataProvider wordpress_org_failures
	 */
	public function test_run_check_does_not_list_items_when_wordpress_org_is_unreachable( $kind, $failure ) {
		$slug = $this->install( $kind, 'unreachable', $this->days_ago( 400 ) );
		$this->queue_batch( $kind, $slug );
		$this->set_no_date( $kind, array( 'previously-undated' ) );
		$this->stub_wordpress_org( 'error' === $failure ? new WP_Error( 'http_request_failed', 'Operation timed out' ) : $this->http_response( $failure, '' ) );

		$this->checker( $kind )->run_check();

		$this->assertSame( array( 'previously-undated' => 1 ), get_transient( self::TRANSIENTS[ $kind ]['no_date'] ) );
	}

	/**
	 * @dataProvider kinds
	 */
	public function test_run_check_drops_items_from_the_undated_list_once_wordpress_org_dates_them( $kind ) {
		$slug = $this->install( $kind, 'now-dated', $this->days_ago( 400 ) );
		$this->queue_batch( $kind, $slug );
		$this->set_no_date( $kind, array( $slug ) );
		$info = (object) array(
			'version'      => '1.2.3',
			'last_updated' => gmdate( 'Y-m-d H:i:s', $this->days_ago( 10 ) ),
		);
		$this->stub_wordpress_org( $this->http_response( 200, serialize( $info ) ) ); // phpcs:ignore -- matches the WordPress.org 1.0 API format.

		$this->checker( $kind )->run_check();

		$this->assertSame( array(), get_transient( self::TRANSIENTS[ $kind ]['no_date'] ) );
		$this->enable_local_date_check();
		$this->assertArrayNotHasKey( $slug, $this->outdate_info( $kind ), 'A recent WordPress.org date wins over an old file date.' );
	}

	/**
	 * @dataProvider kinds
	 */
	public function test_undated_list_survives_sync_disabling_checks_but_not_deactivation( $kind ) {
		$this->set_no_date( $kind, array( 'some-slug' ) );

		$this->checker( $kind )->sync_background_state( false );
		$this->assertSame( array( 'some-slug' => 1 ), get_transient( self::TRANSIENTS[ $kind ]['no_date'] ) );

		$this->checker( $kind )->cleanup_deactivation();
		$this->assertFalse( get_transient( self::TRANSIENTS[ $kind ]['no_date'] ) );
	}

	/**
	 * @param string $kind plugin or theme.
	 *
	 * @return MainWP_Child_Plugins_Check|MainWP_Child_Themes_Check
	 */
	private function checker( $kind ) {
		return 'plugin' === $kind ? MainWP_Child_Plugins_Check::instance() : MainWP_Child_Themes_Check::instance();
	}

	private function outdate_info( $kind ) {
		return 'plugin' === $kind ? $this->checker( $kind )->get_plugins_outdate_info() : $this->checker( $kind )->get_themes_outdate_info();
	}

	private function enable_local_date_check() {
		update_option( self::LOCAL_DATE_OPTION, 1 );
	}

	private function days_ago( $days ) {
		return time() - ( $days * DAY_IN_SECONDS );
	}

	private function set_no_date( $kind, $slugs ) {
		set_transient( self::TRANSIENTS[ $kind ]['no_date'], array_fill_keys( $slugs, 1 ), 2 * DAY_IN_SECONDS );
	}

	private function queue_batch( $kind, $slug ) {
		$row = array(
			'Name'    => 'Queued',
			'Version' => '1.2.3',
		);
		if ( 'plugin' === $kind ) {
			$row['PluginURI'] = '';
		}
		set_transient( self::TRANSIENTS[ $kind ]['to_batch'], array( $slug => $row ), 2 * DAY_IN_SECONDS );
	}

	/**
	 * Answer every WordPress.org request with $response and refuse any other host.
	 *
	 * @param array|WP_Error $response Response returned from pre_http_request.
	 */
	private function stub_wordpress_org( $response ) {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( $response ) {
				$this->assertSame( 'api.wordpress.org', wp_parse_url( $url, PHP_URL_HOST ) );
				return $response;
			},
			10,
			3
		);
	}

	private function http_response( $code, $body ) {
		return array(
			'headers'  => array(),
			'body'     => $body,
			'response' => array(
				'code'    => $code,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Create a plugin or theme whose main file was last modified at $mtime.
	 *
	 * @return string The slug the checker keys its rows by.
	 */
	private function install( $kind, $name, $mtime ) {
		$dir_name = 'mainwp-local-date-' . $name;

		if ( 'plugin' === $kind ) {
			$dir   = WP_PLUGIN_DIR . '/' . $dir_name;
			$slug  = $dir_name . '/' . $dir_name . '.php';
			$file  = WP_PLUGIN_DIR . '/' . $slug;
			$files = array( $file => "<?php\n/**\n * Plugin Name: MainWP Local Date {$name}\n * Plugin URI: https://example.com/{$dir_name}\n * Version: 1.2.3\n */\n" );
		} else {
			$dir   = get_theme_root() . '/' . $dir_name;
			$slug  = $dir_name;
			$file  = $dir . '/style.css';
			$files = array(
				$file              => "/*\nTheme Name: MainWP Local Date {$name}\nVersion: 1.2.3\n*/\n",
				$dir . '/index.php' => "<?php\n",
			);
		}

		wp_mkdir_p( $dir );
		$this->fixture_dirs[] = $dir;
		foreach ( $files as $path => $contents ) {
			file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		touch( $file, $mtime );
		$this->clear_package_caches();

		return $slug;
	}

	private function expected_row( $kind, $name, $mtime ) {
		$row = array( 'Name' => 'MainWP Local Date ' . $name );
		if ( 'plugin' === $kind ) {
			$row['PluginURI'] = 'https://example.com/mainwp-local-date-' . $name;
		}

		return $row + array(
			'Version'       => '1.2.3',
			'last_updated'  => $mtime,
			'file_modified' => $mtime,
			'detection'     => 'file_mtime',
		);
	}

	private function clear_package_caches() {
		clearstatcache();
		wp_cache_delete( 'plugins', 'plugins' );
		wp_clean_themes_cache();
	}

	private function remove_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( array_diff( scandir( $dir ), array( '.', '..' ) ) as $entry ) {
			$path = $dir . '/' . $entry;
			is_dir( $path ) ? $this->remove_dir( $path ) : unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
}
