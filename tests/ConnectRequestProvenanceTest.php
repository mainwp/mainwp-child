<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- PHPUnit test filename.
/**
 * Request provenance and legacy authentication regressions.
 *
 * @package Mainwp_Child
 */

// phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput, WordPress.PHP.DiscouragedPHPFunctions -- Controlled request and RSA fixtures.
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test case and output-only seams.

use MainWP\Child\MainWP_Connect;
use MainWP\Child\MainWP_Exception;
use MainWP\Child\MainWP_Helper;
use MainWP\Child\MainWP_Child_Stats;
use MainWP\Child\MainWP_Child_Branding;
use MainWP\Child\MainWP_Child_Keys_Manager;
use MainWP\Child\Changes\Changes_Logs_Logger;
use MainWP\Child\Changes\Changes_Logs_DB_Log;
use MainWP\Child\Changes\Changes_Handle_WP_System;
use MainWP\Child\SystemMonitor\MainWP_Child_System_Monitor;

/** Output-only seam: retain the helper's real error handling. */
class MWP1995_Output_Helper extends MainWP_Helper {
	/**
	 * Stop before the transport terminates PHPUnit.
	 *
	 * @param mixed $value Response.
	 * @throws RuntimeException Instead of terminating the test process.
	 */
	public static function write( $value ) {
		throw new RuntimeException( isset( $value['error_code'] ) ? esc_html( $value['error_code'] ) : 'response' );
	}
}

/** Output-only seam after registration has stored the connection. */
class MWP1995_Output_Stats extends MainWP_Child_Stats {
	/**
	 * Return the registration result without collecting unrelated stats.
	 *
	 * @param array $information Response.
	 * @param bool  $exit_done Transport flag.
	 * @param array $params Stats parameters.
	 * @return array Response.
	 */
	public function get_site_stats( $information = array(), $exit_done = true, $params = array() ) {
		return $information;
	}
}

/** Read installed fixture state as the next WordPress request would. */
class MWP1995_Installed_Logs_DB extends Changes_Logs_DB_Log {
	/**
	 * Read the installed fixture version without the previous request cache.
	 *
	 * @return string Installed database version.
	 */
	public function get_db_version() {
		return get_option( 'mainwp_child_changes_logs_db_version' );
	}
}

/** Real WordPress options, users, sessions and Changes Logs storage. */
class ConnectRequestProvenanceTest extends WP_UnitTestCase {
	/**
	 * Fixture RSA key.
	 *
	 * @var mixed
	 */
	private static $key;
	/**
	 * Request globals.
	 *
	 * @var array
	 */
	private $saved_globals;
	/**
	 * Original helper.
	 *
	 * @var mixed
	 */
	private $saved_helper;
	/**
	 * Original stats.
	 *
	 * @var mixed
	 */
	private $saved_stats;
	/**
	 * Original logger database singleton.
	 *
	 * @var mixed
	 */
	private $saved_logs_db;
	/**
	 * Original delayed log queue.
	 *
	 * @var array
	 */
	private $saved_delayed_logs;
	/**
	 * Original branding cache.
	 *
	 * @var array|null
	 */
	private $saved_branding_options;

	/** Prepare a fresh simulated request. */
	public function set_up() {
		parent::set_up();
		$this->saved_globals = array( $_POST, $_GET, $_REQUEST, $_COOKIE );
		$this->saved_helper  = MainWP_Helper::$instance;
		$queue               = new ReflectionProperty( Changes_Logs_Logger::class, 'delay_logs_items' );
		$queue->setAccessible( true );
		$this->saved_delayed_logs = $queue->getValue();
		$queue->setValue( null, array() );
		$this->saved_branding_options = MainWP_Child_Branding::instance()->child_branding_options;
		$property                     = new ReflectionProperty( MainWP_Child_Stats::class, 'instance' );
		$property->setAccessible( true );
		$this->saved_stats = $property->getValue();
		$property          = new ReflectionProperty( Changes_Logs_DB_Log::class, 'instance' );
		$property->setAccessible( true );
		$this->saved_logs_db = $property->getValue();
		$property->setValue( null, new MWP1995_Installed_Logs_DB() );
		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();
		$_COOKIE  = array();
		wp_set_current_user( 0 );
		$this->reset_request();
		if ( null === self::$key ) {
			self::$key = openssl_pkey_new( array( 'private_key_bits' => 2048 ) );
		}
		$details = openssl_pkey_get_details( self::$key );
		update_option( 'mainwp_child_pubkey', base64_encode( $details['key'] ) );
		delete_option( 'mainwp_child_openssl_sign_algo' );
	}

	/** Restore singleton and request state. */
	public function tear_down() {
		$this->reset_request();
		$queue = new ReflectionProperty( Changes_Logs_Logger::class, 'delay_logs_items' );
		$queue->setAccessible( true );
		$queue->setValue( null, $this->saved_delayed_logs );
		MainWP_Helper::$instance                                  = $this->saved_helper;
		MainWP_Child_Branding::instance()->child_branding_options = $this->saved_branding_options;
		$property = new ReflectionProperty( MainWP_Child_Stats::class, 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, $this->saved_stats );
		$property = new ReflectionProperty( Changes_Logs_DB_Log::class, 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, $this->saved_logs_db );
		list( $_POST, $_GET, $_REQUEST, $_COOKIE ) = $this->saved_globals;
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/** Reset only the per-request cache between actual auth calls. */
	private function reset_request() {
		$property = new ReflectionProperty( MainWP_Connect::class, 'signature_checked' );
		$property->setAccessible( true );
		$property->setValue( null, null );
	}

	/**
	 * Sign a fresh legacy request with fixture credentials.
	 *
	 * @param string $command Command.
	 * @return string Wire signature.
	 */
	private function signature( $command = 'stats' ) {
		$_REQUEST['nonce'] = wp_generate_uuid4();
		openssl_sign( $command . $_REQUEST['nonce'], $bytes, self::$key );
		return base64_encode( $bytes );
	}

	/**
	 * Execute authentication, including replay checks.
	 *
	 * @param string $signature Wire signature.
	 * @return bool Accepted.
	 */
	private function authenticate( $signature ) {
		$this->reset_request();
		$_REQUEST['mainwpsignature'] = rawurlencode( $signature );
		try {
			return 1 === MainWP_Connect::instance()->auth( $signature, 'stats', $_REQUEST['nonce'] );
		} catch ( MainWP_Exception $exception ) {
			return false;
		}
	}

	/** Canonical first use and its one retry remain supported. */
	public function test_canonical_first_use_retry_and_replay() {
		$signature = $this->signature();
		$this->assertTrue( $this->authenticate( $signature ) );
		$this->assertTrue( $this->authenticate( $signature ) );
		$this->assertFalse( $this->authenticate( $signature ) );
	}

	/** Cookie shadowing cannot give a verified signature another retry. */
	public function test_cookie_shadow_cannot_reset_legacy_replay() {
		$signature                = $this->signature();
		$_POST['mainwpsignature'] = rawurlencode( $signature );
		foreach ( array( true, true, false ) as $accepted ) {
			$this->reset_request();
			$_COOKIE['mainwpsignature']  = base64_encode( wp_generate_uuid4() );
			$_REQUEST['mainwpsignature'] = $_COOKIE['mainwpsignature'];
			try {
				$result = 1 === MainWP_Connect::instance()->auth( rawurldecode( wp_unslash( $_POST['mainwpsignature'] ) ), 'stats', $_REQUEST['nonce'] );
			} catch ( MainWP_Exception $exception ) {
				$result = false;
			}
			$this->assertSame( $accepted, $result );
		}
	}

	/** Historical text blocks follow the argument even when REQUEST differs. */
	public function test_shadow_cannot_hide_historical_argument_block() {
		$signature = chunk_split( $this->signature(), 44, "\n" );
		add_option( 'mainwp_child_blocked_request_id_' . hash( 'sha256', $signature ), time() );
		$_REQUEST['mainwpsignature'] = base64_encode( 'fixture-cookie-shadow' );
		$this->expectException( MainWP_Exception::class );
		MainWP_Connect::instance()->auth( $signature, 'stats', $_REQUEST['nonce'] );
	}

	/** Equivalent accepted whitespace must consume the same retry. */
	public function test_whitespace_spellings_share_replay_identity() {
		$signature = $this->signature();
		$this->assertTrue( $this->authenticate( $signature ) );
		$this->assertTrue( $this->authenticate( chunk_split( $signature, 40, "\r\n" ) ) );
		$this->assertFalse( $this->authenticate( " \t" . $signature ) );
	}

	/**
	 * Malformed encodings must not reach cryptographic authentication.
	 *
	 * @dataProvider malformed_spellings
	 * @param string $suffix Invalid base64 suffix.
	 */
	public function test_malformed_signature_is_refused_on_first_use( $suffix ) {
		$this->assertFalse( $this->authenticate( $this->signature() . $suffix ) );
	}

	/**
	 * Provides test cases.
	 *
	 * @return array Invalid characters ignored by non-strict decoding.
	 */
	public static function malformed_spellings() {
		return array(
			'punctuation'  => array( '!' ),
			'url alphabet' => array( '_' ),
		);
	}

	/**
	 * Existing text-hashed blocks must survive alternate spellings.
	 *
	 * @dataProvider old_block_states
	 * @param string $state Stored spelling.
	 */
	public function test_old_blocked_state_cannot_be_reopened( $state ) {
		$signature  = $this->signature();
		$submitted  = chunk_split( $signature, 44, "\n" );
		$text       = 'canonical' === $state ? $signature : $submitted;
		$hash       = hash( 'sha256', 'bytes' === $state ? base64_decode( $signature, true ) : $text );
		$blocked_at = time() - 100;
		add_option( 'mainwp_child_blocked_request_id_' . $hash, $blocked_at );
		$this->assertFalse( $this->authenticate( $submitted ) );
		$this->assertFalse( $this->authenticate( $signature ) );
		$this->assertSame( $blocked_at, (int) get_option( 'mainwp_child_blocked_request_id_' . $hash ) );
	}

	/**
	 * Provides test cases.
	 *
	 * @return array Retained historical spellings.
	 */
	public static function old_block_states() {
		return array( array( 'canonical' ), array( 'submitted' ), array( 'bytes' ) );
	}

	/**
	 * Carry old first-use time forward rather than restarting its window.
	 *
	 * @dataProvider old_first_use_states
	 * @param string $state Stored spelling.
	 * @param int    $age Age in seconds.
	 */
	public function test_old_first_use_keeps_original_retry_window( $state, $age ) {
		$signature = $this->signature();
		$submitted = chunk_split( $signature, 48, "\n" );
		$text      = 'canonical' === $state ? $signature : $submitted;
		$first_use = time() - $age;
		$hash      = hash( 'sha256', 'bytes' === $state ? base64_decode( $signature, true ) : $text );
		add_option( 'mainwp_child_request_id_' . $hash, $first_use );
		$this->assertSame( $age <= 20, $this->authenticate( $submitted ) );
		$this->assertFalse( $this->authenticate( $signature ) );
		$bytes_id = hash( 'sha256', base64_decode( $signature, true ) );
		$this->assertSame( $first_use, (int) get_option( 'mainwp_child_request_id_' . $bytes_id ) );
	}

	/**
	 * Provides test cases.
	 *
	 * @return array Old canonical and submitted first-use records.
	 */
	public static function old_first_use_states() {
		return array( array( 'canonical', 5 ), array( 'canonical', 30 ), array( 'submitted', 5 ), array( 'submitted', 30 ), array( 'bytes', 5 ), array( 'bytes', 30 ) );
	}

	/**
	 * An advanced request blocks the legacy argument despite cookie shadowing.
	 *
	 * @dataProvider paired_legacy_requests
	 * @param bool $shadow Whether REQUEST contains another signature.
	 */
	public function test_advanced_request_blocks_equivalent_legacy_signature( $shadow ) {
		$legacy               = $this->signature();
		$admin                = self::factory()->user->create_and_get( array( 'role' => 'administrator' ) );
		$_REQUEST['user']     = $admin->user_login;
		$_REQUEST['function'] = 'stats';
		$data                 = wp_json_encode(
			array(
				'req_id'        => wp_generate_uuid4(),
				'base_function' => 'stats',
				'user'          => $admin->user_login,
				'nonce'         => $_REQUEST['nonce'],
				'expires'       => time() + 60,
			)
		);
		openssl_sign( $data, $bytes, self::$key );
		$_COOKIE['mainwpsignature']  = base64_encode( str_repeat( 'fixture-shadow', 8 ) );
		$_REQUEST['mainwpsignature'] = $shadow ? $_COOKIE['mainwpsignature'] : rawurlencode( $legacy );
		$this->assertSame(
			1,
			MainWP_Connect::instance()->auth(
				$legacy,
				'stats',
				$_REQUEST['nonce'],
				array(
					'connect_sign'        => $data,
					'mainwpsignature_adv' => base64_encode( $bytes ),
				)
			)
		);
		$this->assertFalse( $this->authenticate( chunk_split( $legacy, 40, "\n" ) ) );
	}

	/**
	 * Provides paired legacy request sources.
	 *
	 * @return array Normal and cookie-shadowed requests.
	 */
	public static function paired_legacy_requests() {
		return array( array( false ), array( true ) );
	}

	/**
	 * Spoofed visitor/subscriber flags must not suppress real log inserts.
	 *
	 * @dataProvider untrusted_logging_requests
	 * @param string $role WordPress role or visitor.
	 * @param bool   $premium Premium flag.
	 */
	public function test_untrusted_flags_do_not_suppress_logging( $role, $premium ) {
		if ( 'visitor' !== $role ) {
			wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
		}
		$_POST = $premium ? array( 'perform_premium_action' => 'premium_update' ) : array(
			'mainwpsignature' => 'fake',
			'function'        => 'stats',
		);
		$this->assertSame( 1, $this->record_action() );
	}

	/**
	 * Provides test cases.
	 *
	 * @return array Untrusted actor and flag combinations.
	 */
	public static function untrusted_logging_requests() {
		return array( array( 'visitor', false ), array( 'subscriber', false ), array( 'visitor', true ), array( 'subscriber', true ) );
	}

	/**
	 * Count rows produced by the real logger.
	 *
	 * @return int Number of inserted events.
	 */
	private function record_action() {
		global $wpdb;
		Changes_Logs_DB_Log::instance()->install();
		$table  = Changes_Logs_DB_Log::table_name( 'changes_logs' );
		$before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Observe real logger inserts.
		Changes_Logs_Logger::log_change( 1600, array( 'username' => 'fixture-user' ) );
		Changes_Logs_Logger::change_process_delay_logs_items();
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ) - $before; // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Observe real logger inserts.
	}

	/**
	 * Missing monitor events wait for the real authentication result before logging.
	 *
	 * @dataProvider monitor_request_authentication
	 * @param bool $valid Whether the request has a valid signature.
	 */
	public function test_missing_monitor_event_waits_for_authentication( $valid ) {
		global $wpdb;
		Changes_Logs_DB_Log::instance()->install();
		MainWP_Child_System_Monitor::init();
		wp_clear_scheduled_hook( MainWP_Child_System_Monitor::CRON_HOOK );
		Changes_Handle_WP_System::init_hooks();
		$table     = Changes_Logs_DB_Log::table_name( 'changes_logs' );
		$before    = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Observe real scheduled-event logging.
		$signature = $this->signature();
		$_POST     = array(
			'mainwpsignature' => $valid ? rawurlencode( $signature ) : 'fake',
			'function'        => 'stats',
		);
		$this->assertFalse( MainWP_Connect::is_request_authenticated() );
		MainWP_Child_System_Monitor::init_schedule_cron();
		$this->assertNotFalse( wp_next_scheduled( MainWP_Child_System_Monitor::CRON_HOOK ) );
		$this->assertSame( $before, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- No insert before authentication resolves.
		$this->assertSame( $valid, $this->authenticate( $valid ? $signature : 'fake' ) );
		Changes_Logs_Logger::change_process_delay_logs_items();
		$this->assertSame( $before + ( $valid ? 0 : 1 ), (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Only unverified requests retain their event.
	}

	/**
	 * Provides valid Dashboard and forged visitor requests.
	 *
	 * @return array Authentication outcomes.
	 */
	public static function monitor_request_authentication() {
		return array( array( true ), array( false ) );
	}

	/** Signed POSTs retain logging suppression; signed GETs retain logging. */
	public function test_signed_dashboard_logging_preserves_post_shape() {
		$signature = $this->signature();
		$this->assertTrue( $this->authenticate( $signature ) );
		$_POST = array(
			'mainwpsignature' => rawurlencode( $signature ),
			'function'        => 'stats',
		);
		$this->assertTrue( mainwp_child_is_dashboard_request() );
		$this->assertSame( 0, $this->record_action() );
		$_POST = array();
		$this->assertFalse( mainwp_child_is_dashboard_request() );
		$this->assertSame( 1, $this->record_action() );
	}

	/** Premium suppression requires its flag and a trusted request. */
	public function test_premium_admin_and_signed_paths_remain_suppressed() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_POST = array( 'perform_premium_action' => 'premium_update' );
		$this->assertTrue( mainwp_child_is_dashboard_premium_update_request() );
		$this->assertSame( 0, $this->record_action() );
		unset( $_POST['perform_premium_action'] );
		$this->assertFalse( mainwp_child_is_dashboard_premium_update_request() );
		wp_set_current_user( 0 );
		$this->assertTrue( $this->authenticate( $this->signature() ) );
		$_POST = array( 'perform_premium_action' => 'premium_update' );
		$this->assertTrue( mainwp_child_is_dashboard_premium_update_request() );
		$this->assertSame( 0, $this->record_action() );
	}

	/** An unsigned registration cannot change connected branding. */
	public function test_unsigned_registration_preserves_branding() {
		MainWP_Helper::$instance = new MWP1995_Output_Helper();
		update_option(
			'mainwp_child_branding_settings',
			array(
				'branding_disconnected' => '',
				'name'                  => 'Fixture agency',
			)
		);
		$before = get_option( 'mainwp_child_branding_settings' );
		$_POST  = array(
			'user'   => 'nonexistent-fixture-user',
			'pubkey' => 'x',
		);
		try {
			MainWP_Connect::instance()->register_site();
			$this->fail( 'Registration must reject missing credentials.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'REG_ERROR2', $exception->getMessage() );
		}
		$this->assertSame( $before, get_option( 'mainwp_child_branding_settings' ) );
	}

	/**
	 * Only verified administrator credentials can unhide a rejected reconnect.
	 *
	 * @dataProvider rejected_reconnect_credentials
	 * @param string $role Posted user's role.
	 * @param string $credential Credential supplied.
	 * @param bool   $changed_admin Whether the connected login differs.
	 * @param bool   $recover Whether branding should be disconnected.
	 */
	public function test_rejected_reconnect_branding_credentials( $role, $credential, $changed_admin, $recover ) {
		$user = self::factory()->user->create_and_get(
			array(
				'role'      => $role,
				'user_pass' => 'fixture-password',
			)
		);
		update_option( 'mainwp_child_connected_admin', $changed_admin ? 'previous-fixture-admin' : $user->user_login );
		MainWP_Child_Keys_Manager::update_encrypted_option( 'mainwp_child_server', 'https://old-dashboard.example.test' );
		MainWP_Helper::$instance = new MWP1995_Output_Helper();
		update_option(
			'mainwp_child_branding_settings',
			array(
				'branding_disconnected' => '',
				'hide'                  => 'T',
				'name'                  => 'Fixture agency',
			)
		);
		$branding                         = MainWP_Child_Branding::instance();
		$branding->child_branding_options = $branding->init_options();
		$branding->save_branding_options( 'branding_disconnected', '' );
		$before = get_option( 'mainwp_child_branding_settings' );
		$_POST  = array(
			'user'   => $user->user_login,
			'pubkey' => 'fixture-replacement-key',
			'server' => $changed_admin ? 'https://old-dashboard.example.test' : 'https://new-dashboard.example.test',
		);
		if ( 'password' === $credential || 'wrong-password' === $credential ) {
			$_POST['userpwd'] = 'password' === $credential ? 'fixture-password' : 'wrong-password';
		} elseif ( 'array-password' === $credential || 'array-password-regverify' === $credential ) {
			$_POST['userpwd'] = array( 'fixture' );
			if ( 'array-password-regverify' === $credential ) {
				$_POST['regverify'] = MainWP_Connect::instance()->validate_register( false, 'generate', $user->user_login );
			}
		} elseif ( 'array-regverify' === $credential ) {
			$_POST['regverify'] = array( 'fixture' );
		} elseif ( 'regverify' === $credential ) {
			$_POST['regverify'] = MainWP_Connect::instance()->validate_register( false, 'generate', $user->user_login );
		} elseif ( 'wrong-regverify' === $credential ) {
			$_POST['regverify'] = 'invalid-fixture-registration';
		}
		try {
			MainWP_Connect::instance()->register_site();
			$this->fail( 'Changed connection must retain REG_ERROR2.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'REG_ERROR2', $exception->getMessage() );
		}
		$expected = $before;
		if ( $recover ) {
			$expected['branding_disconnected'] = 'yes';
		}
		$this->assertSame( $expected, get_option( 'mainwp_child_branding_settings' ) );
		$this->assertSame( 0, get_current_user_id() );
	}

	/**
	 * Provides rejected reconnect credential combinations.
	 *
	 * @return array Credential, role and changed-connection cases.
	 */
	public static function rejected_reconnect_credentials() {
		return array(
			'array password'              => array( 'administrator', 'array-password', false, false ),
			'array registration'          => array( 'administrator', 'array-regverify', false, false ),
			'array password registration' => array( 'administrator', 'array-password-regverify', false, false ),
			'missing admin credential'    => array( 'administrator', 'missing', false, false ),
			'wrong admin password'        => array( 'administrator', 'wrong-password', false, false ),
			'wrong admin registration'    => array( 'administrator', 'wrong-regverify', false, false ),
			'subscriber password'         => array( 'subscriber', 'password', false, false ),
			'subscriber registration'     => array( 'subscriber', 'regverify', false, false ),
			'changed server password'     => array( 'administrator', 'password', false, true ),
			'changed server registration' => array( 'administrator', 'regverify', false, true ),
			'changed admin password'      => array( 'administrator', 'password', true, true ),
			'changed admin registration'  => array( 'administrator', 'regverify', true, true ),
		);
	}

	/** A reconnect still validates the real administrator password and stores its key. */
	public function test_genuine_reconnect_preserves_credential_gates() {
		$admin = self::factory()->user->create_and_get(
			array(
				'role'      => 'administrator',
				'user_pass' => 'fixture-password',
			)
		);
		update_option( 'mainwp_child_connected_admin', $admin->user_login );
		MainWP_Child_Keys_Manager::update_encrypted_option( 'mainwp_child_server', 'https://dashboard.example.test' );
		MainWP_Helper::$instance = new MWP1995_Output_Helper();
		$property                = new ReflectionProperty( MainWP_Child_Stats::class, 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, new MWP1995_Output_Stats() );
		$_POST = array(
			'user'    => $admin->user_login,
			'pubkey'  => 'fixture-replacement-key',
			'server'  => 'https://dashboard.example.test',
			'userpwd' => 'wrong-password',
		);
		try {
			MainWP_Connect::instance()->register_site();
			$this->fail( 'A wrong password must fail.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'REG_ERROR7', $exception->getMessage() );
		}
		$_POST['userpwd'] = 'fixture-password';
		MainWP_Connect::instance()->register_site();
		$this->assertTrue( base64_encode( 'fixture-replacement-key' ) === get_option( 'mainwp_child_pubkey' ) );
		$this->assertSame( $admin->ID, get_current_user_id() );
	}

	/**
	 * Real subscriber tokens survive refused login links and are destroyed on signed login.
	 *
	 * @dataProvider login_requests
	 * @param string $kind Request authentication.
	 */
	public function test_login_required_session_boundary( $kind ) {
		$subscriber                  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$admin                       = self::factory()->user->create_and_get( array( 'role' => 'administrator' ) );
		$manager                     = WP_Session_Tokens::get_instance( $subscriber );
		$token                       = $manager->create( time() + 3600 );
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $subscriber, time() + 3600, 'logged_in', $token );
		wp_set_current_user( $subscriber );
		$this->assertTrue( $manager->verify( $token ) );
		$signature                   = $this->signature( 'admin.php' );
		$_REQUEST                   += array(
			'user'  => $admin->user_login,
			'where' => 'admin.php',
		);
		$_REQUEST['mainwpsignature'] = 'signed' === $kind ? rawurlencode( $signature ) : '';
		if ( 'blocked' === $kind ) {
			add_option( 'mainwp_child_blocked_request_id_' . hash( 'sha256', $signature ), time() );
			$_REQUEST['mainwpsignature'] = rawurlencode( $signature );
		}
		$stop_redirect = static function () {
			throw new RuntimeException( 'fixture redirect' );
		};
		add_filter( 'wp_redirect', $stop_redirect );
		try {
			$result = MainWP_Connect::instance()->parse_login_required();
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'fixture redirect', $exception->getMessage() );
			$result = true;
		} finally {
			remove_filter( 'wp_redirect', $stop_redirect );
		}
		$this->assertSame( 'signed' === $kind, $result );
		$this->assertSame( 'signed' !== $kind, $manager->verify( $token ) );
		$this->assertSame( 'signed' === $kind ? $admin->ID : $subscriber, get_current_user_id() );
	}

	/**
	 * Provides test cases.
	 *
	 * @return array Successful, false and exception authentication paths.
	 */
	public static function login_requests() {
		return array( array( 'unsigned' ), array( 'blocked' ), array( 'signed' ) );
	}
}
