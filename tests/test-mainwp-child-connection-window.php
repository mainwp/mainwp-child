<?php
/**
 * Connection window and onboarding notice regressions.
 *
 * @package MainWP_Child
 */

namespace MainWP\Child;

use WP_UnitTestCase;

class Test_MainWP_Child_Connection_Window extends WP_UnitTestCase {

    private $branding;
    private $active_plugins;
    private $request;

    public function set_up(): void {
        parent::set_up();
        $this->branding       = MainWP_Child_Branding::instance()->child_branding_options;
        $this->active_plugins = get_option( 'active_plugins' );
        update_option( 'active_plugins', array( plugin_basename( MAINWP_CHILD_FILE ) ) );
        $this->request        = $_GET;
        $_GET                 = array();
        MainWP_Child_Branding::instance()->child_branding_options = array( 'branding_preserve_title' => '' );
        wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
        set_current_screen( 'dashboard' );
        foreach ( array( 'mainwp_child_pubkey', 'mainwp_child_lasttime_not_connected', 'mainwp_child_connection_window_expired', 'mainwp_child_ttl_active_unconnected_site' ) as $name ) {
            delete_option( $name );
        }
    }

    public function tear_down(): void {
        MainWP_Child_Branding::instance()->child_branding_options = $this->branding;
        update_option( 'active_plugins', $this->active_plugins );
        $_GET = $this->request;
        wp_set_current_user( 0 );
        set_current_screen( 'front' );
        parent::tear_down();
    }

    public static function window_states() {
        return array(
            'disabled'             => array( 0, null, 'disabled', null ),
            'negative'             => array( -1, 10, 'running', 0 ),
            'negative-not-started' => array( -1, null, 'not_started', 0 ),
            'not-started'          => array( 7, null, 'not_started', 420 ),
            'running'              => array( 7, 90, 'running', 330 ),
            'expired'              => array( 7, 421, 'running', 0 ),
        );
    }

    /** @dataProvider window_states */
    public function test_helper_states_are_read_only( $ttl, $age, $state, $remaining ) {
        update_option( 'mainwp_child_ttl_active_unconnected_site', $ttl );
        $started = null === $age ? false : time() - $age;
        if ( false !== $started ) {
            update_option( 'mainwp_child_lasttime_not_connected', $started );
        }
        $writes = array();
        $record = function ( $name ) use ( &$writes ) {
            $writes[] = $name;
        };
        foreach ( array( 'added_option', 'updated_option', 'deleted_option' ) as $hook ) {
            add_action( $hook, $record );
        }
        try {
            $window = MainWP_Child::get_connection_window();
        } finally {
            foreach ( array( 'added_option', 'updated_option', 'deleted_option' ) as $hook ) {
                remove_action( $hook, $record );
            }
        }
        $this->assertSame( $ttl, $window['minutes'] );
        $this->assertSame( $state, $window['state'] );
        if ( 'running' === $state && $remaining > 0 ) {
            $this->assertGreaterThanOrEqual( $remaining - 2, $window['remaining'] );
            $this->assertLessThanOrEqual( $remaining, $window['remaining'] );
        } else {
            $this->assertSame( $remaining, $window['remaining'] );
        }
        $this->assertSame( array(), $writes, 'Reading a window must not mutate options.' );
        $this->assertSame( $started, get_option( 'mainwp_child_lasttime_not_connected' ) );
    }

    public function test_helper_uses_default_twenty_minute_window() {
        $this->assertSame( array( 'minutes' => 20, 'remaining' => 1200, 'state' => 'not_started' ), MainWP_Child::get_connection_window() );
    }

    public function test_notice_contains_copy_rows_and_live_countdown() {
        update_option( 'mainwp_child_ttl_active_unconnected_site', 7 );
        $output = $this->notice();
        $this->assertStringContainsString( 'Next: open your MainWP Dashboard and add this site.', $output );
        $this->assertStringContainsString( 'Site URL', $output );
        $this->assertStringContainsString( 'Administrator username', $output );
        $this->assertStringContainsString( 'value="' . esc_attr( site_url() ) . '"', $output );
        $this->assertStringContainsString( 'value="' . esc_attr( wp_get_current_user()->user_login ) . '"', $output );
        $this->assertSame( 2, substr_count( $output, '>Copy</button>' ) );
        $this->assertSame( 2, substr_count( $output, 'data-copied="Copied"' ) );
        $this->assertStringContainsString( 'data-remaining="420"', $output );
        $this->assertStringContainsString( esc_html( "For security, MainWP Child switches itself off in 7 minutes if it isn't connected." ), $output );
        $this->assertStringContainsString( 'navigator.clipboard.writeText', $output );
        $this->assertStringContainsString( 'input.select()', $output );
        $this->assertStringContainsString( "document.execCommand('copy')", $output );
        $this->assertStringContainsString( 'window.setInterval', $output );
        $this->assertStringContainsString( 'Date.now()', $output );
        $this->assertStringContainsString( 'docs.mainwp.com/getting-started/get-started-with-mainwp#add-a-site-to-your-dashboard', $output );
        $this->assertStringContainsString( 'admin.php?page=mainwp_child_tab', $output );
        $this->assertFalse( get_option( 'mainwp_child_lasttime_not_connected' ) );
    }

    public function test_notice_handles_singular_and_less_than_a_minute() {
        update_option( 'mainwp_child_ttl_active_unconnected_site', 1 );
        $this->assertStringContainsString( esc_html( "For security, MainWP Child switches itself off in 1 minute if it isn't connected." ), $this->notice() );
        update_option( 'mainwp_child_lasttime_not_connected', time() - 10 );
        $this->assertStringContainsString( '>' . esc_html( "For security, MainWP Child switches itself off in less than a minute if it isn't connected." ) . '</p>', $this->notice() );
    }

    public function test_disabled_timeout_has_no_countdown() {
        update_option( 'mainwp_child_ttl_active_unconnected_site', 0 );
        $this->assertStringNotContainsString( 'data-remaining=', $this->notice() );
    }

    public function test_reactivation_explanation_uses_old_and_current_durations_once() {
        update_option( 'mainwp_child_connection_window_expired', array( 'time' => time() - 60, 'ttl' => 3 ) );
        update_option( 'mainwp_child_ttl_active_unconnected_site', 9 );
        $this->child()->activation();
        $output = $this->notice();
        $this->assertStringContainsString( 'MainWP Child switched itself off because no MainWP Dashboard connected to it within 3 minutes. This protects sites where the plugin is left active but unused. It is active again, with a 9-minute limit.', $output );
        $this->assertFalse( get_option( 'mainwp_child_connection_window_expired' ) );
        $this->assertStringNotContainsString( 'switched itself off because', $this->notice() );
    }

    public function test_reactivation_explanation_uses_singular_minute() {
        update_option( 'mainwp_child_connection_window_expired', array( 'time' => time() - 60, 'ttl' => 1 ) );
        update_option( 'mainwp_child_ttl_active_unconnected_site', 1 );
        $this->child()->activation();
        $this->assertStringContainsString( 'within 1 minute. This protects sites where the plugin is left active but unused. It is active again, with a 1-minute limit.', $this->notice() );
    }

    public function test_reactivation_with_timeout_disabled_ends_after_active_again() {
        update_option( 'mainwp_child_connection_window_expired', array( 'time' => time() - 60, 'ttl' => 3 ) );
        update_option( 'mainwp_child_ttl_active_unconnected_site', 0 );
        $output = $this->notice();
        $this->assertStringContainsString( 'within 3 minutes. This protects sites where the plugin is left active but unused. It is active again.</p>', $output );
        $this->assertStringNotContainsString( 'you have 0 minutes', $output );
        $this->assertStringNotContainsString( 'data-remaining=', $output );
        $this->assertFalse( get_option( 'mainwp_child_connection_window_expired' ) );
    }

    public function test_notice_respects_branding_and_escapes_values() {
        MainWP_Child_Branding::instance()->child_branding_options = array(
            'branding_preserve_title' => 'Agency <script>alert(1)</script>',
            'branding_ext_enabled'    => 'Y',
            'hide'                    => 'T',
        );
        $output = $this->notice();
        $this->assertStringContainsString( 'Next: open your Agency &lt;script&gt;alert(1)&lt;/script&gt; Dashboard and add this site.', $output );
        $this->assertStringContainsString( 'For security, Agency &lt;script&gt;alert(1)&lt;/script&gt; switches itself off', $output );
        $this->assertStringNotContainsString( '<script>alert(1)</script>', $output );
        $this->assertStringNotContainsString( 'MainWP Dashboard', $output );
        $this->assertStringNotContainsString( 'MainWP Child', $output );
    }

    public function test_hidden_notice_does_not_consume_expiry_flag() {
        $flag = array( 'time' => time(), 'ttl' => 20 );
        update_option( 'mainwp_child_connection_window_expired', $flag );
        update_option( 'mainwp_child_pubkey', 'connected' );
        $this->assertSame( '', $this->notice() );
        $this->assertSame( $flag, get_option( 'mainwp_child_connection_window_expired' ) );
        delete_option( 'mainwp_child_pubkey' );
        wp_set_current_user( 0 );
        $this->assertSame( '', $this->notice() );
        $this->assertSame( $flag, get_option( 'mainwp_child_connection_window_expired' ) );
    }

    public function test_expiry_flag_is_written_before_deactivation() {
        $admin_id = get_current_user_id();
        wp_set_current_user( 0 );
        $child              = $this->child();
        $child->plugin_slug = plugin_basename( MAINWP_CHILD_FILE );
        update_option( 'active_plugins', array( $child->plugin_slug ) );
        update_option( 'mainwp_child_ttl_active_unconnected_site', 3 );
        update_option( 'mainwp_child_lasttime_not_connected', time() - 181 );
        $observed = null;
        $capture  = function ( $value ) use ( &$observed ) {
            $observed = array( get_option( 'mainwp_child_connection_window_expired' ), get_option( 'mainwp_child_lasttime_not_connected' ) );
            return $value;
        };
        add_filter( 'pre_update_option_active_plugins', $capture );
        $before = time();
        try {
            $child->admin_init();
        } finally {
            remove_filter( 'pre_update_option_active_plugins', $capture );
        }
        $this->assertIsArray( $observed, 'The plugin must actually be deactivated.' );
        $this->assertSame( 3, $observed[0]['ttl'] );
        $this->assertGreaterThanOrEqual( $before, $observed[0]['time'] );
        $this->assertLessThanOrEqual( time(), $observed[0]['time'] );
        $this->assertFalse( $observed[1] );
        $this->assertNotContains( $child->plugin_slug, get_option( 'active_plugins' ) );
        wp_set_current_user( $admin_id );
        $this->assertSame( '', $this->notice(), 'The deactivating request must not claim Child is active again.' );
        $this->assertSame( $observed[0], get_option( 'mainwp_child_connection_window_expired' ), 'The flag must survive until reactivation.' );
    }

    public function test_expiry_without_active_plugin_keeps_notice_visible() {
        $admin_id = get_current_user_id();
        wp_set_current_user( 0 );
        $child              = $this->child();
        $child->plugin_slug = plugin_basename( MAINWP_CHILD_FILE );
        update_option( 'active_plugins', array() );
        update_option( 'mainwp_child_ttl_active_unconnected_site', 3 );
        update_option( 'mainwp_child_lasttime_not_connected', time() - 181 );
        $child->admin_init();
        $this->assertFalse( get_option( 'mainwp_child_connection_window_expired' ) );
        wp_set_current_user( $admin_id );
        $output = $this->notice();
        $this->assertStringContainsString( 'Site URL', $output );
        $this->assertStringNotContainsString( 'switches itself off', $output );
    }

    public function test_dashboard_disconnect_of_older_connection_starts_fresh_window() {
        wp_set_current_user( 0 );
        $child              = $this->child();
        $child->plugin_slug = plugin_basename( MAINWP_CHILD_FILE );
        update_option( 'mainwp_child_pubkey', 'connected' );
        update_option( 'mainwp_child_lasttime_not_connected', time() - 2 * DAY_IN_SECONDS );
        update_option( 'mainwp_child_ttl_active_unconnected_site', 20 );
        $child->delete_connection_data();
        $this->assertFalse( get_option( 'mainwp_child_pubkey' ) );
        $this->assertFalse( get_option( 'mainwp_child_lasttime_not_connected' ) );
        $child->admin_init();
        $this->assertContains( $child->plugin_slug, get_option( 'active_plugins' ) );
        $this->assertFalse( get_option( 'mainwp_child_connection_window_expired' ) );
        $this->assertGreaterThanOrEqual( 1199, MainWP_Child::get_connection_window()['remaining'] );
    }

    public function test_negative_ttl_keeps_existing_deactivation_behavior() {
        wp_set_current_user( 0 );
        update_option( 'mainwp_child_ttl_active_unconnected_site', -1 );
        $child              = $this->child();
        $child->plugin_slug = 'mainwp-child/mainwp-child.php';
        update_option( 'active_plugins', array( $child->plugin_slug ) );
        $child->admin_init();
        $this->assertNotEmpty( get_option( 'mainwp_child_lasttime_not_connected' ) );
        $this->assertFalse( get_option( 'mainwp_child_connection_window_expired' ) );
        $child->admin_init();
        $this->assertSame( -1, get_option( 'mainwp_child_connection_window_expired' )['ttl'] );
        $this->assertNotContains( $child->plugin_slug, get_option( 'active_plugins' ) );
    }

    public function test_activation_preserves_expiry_flag_and_restarts_window() {
        $flag = array( 'time' => time() - 60, 'ttl' => 3 );
        update_option( 'mainwp_child_connection_window_expired', $flag );
        update_option( 'mainwp_child_lasttime_not_connected', time() - 600 );
        update_option( 'mainwp_child_ttl_active_unconnected_site', 9 );
        $this->child()->activation();
        $this->assertSame( $flag, get_option( 'mainwp_child_connection_window_expired' ) );
        $this->assertFalse( get_option( 'mainwp_child_lasttime_not_connected' ) );
        $this->assertSame( 540, MainWP_Child::get_connection_window()['remaining'] );
    }

    /** @dataProvider window_states */
    public function test_unconnected_stats_expose_window_fields( $ttl, $age, $state, $remaining ) {
        update_option( 'mainwp_child_ttl_active_unconnected_site', $ttl );
        if ( null !== $age ) {
            update_option( 'mainwp_child_lasttime_not_connected', time() - $age );
        }
        $window = MainWP_Child::get_connection_window();
        $result = $this->stats_response( $window, false );
        $this->assertSame( $ttl, $result['response']['connection_window_minutes'] );
        $this->assertSame( 1, $result['response']['support_advanced_sign'] );
        if ( 'disabled' === $state ) {
            $this->assertArrayNotHasKey( 'connection_window_remaining', $result['response'] );
        } else {
            $this->assertSame( $window['remaining'], $result['response']['connection_window_remaining'] );
        }
        $this->assertSame( array(), $result['writes'], 'Unauthenticated stats must not write options.' );
        $this->assertSame( null === $age ? false : get_option( 'mainwp_child_lasttime_not_connected' ), $result['started'] );
    }

    public function test_connected_stats_preserve_existing_error_response() {
        $result = $this->stats_response( MainWP_Child::get_connection_window(), true );
        $this->assertSame( array( 'error' ), array_keys( $result['response'] ) );
        $this->assertStringContainsString( 'This site already contains a link.', $result['response']['error'] );
        $this->assertSame( array(), $result['writes'] );
        $this->assertSame( 0, $result['window_reads'], 'Connected stats must not even compute the window.' );
    }

    public function test_successful_registration_clears_timestamp() {
        $result = $this->registration_response( true );
        $this->assertSame( 'OK', $result['response']['register'] );
        $this->assertArrayNotHasKey( 'mainwp_child_lasttime_not_connected', $result['options'] );
        $this->assertArrayNotHasKey( 'mainwp_child_connection_window_expired', $result['options'] );
        $this->assertSame( base64_encode( 'public-key-fixture' ), $result['options']['mainwp_child_pubkey'] );
    }

    public function test_failed_registration_preserves_timestamp() {
        $result = $this->registration_response( false );
        $this->assertArrayHasKey( 'error', $result['response'] );
        $this->assertSame( 123, $result['options']['mainwp_child_lasttime_not_connected'] );
        $this->assertSame( array( 'time' => 123, 'ttl' => 3 ), $result['options']['mainwp_child_connection_window_expired'] );
        $this->assertArrayNotHasKey( 'mainwp_child_pubkey', $result['options'] );
    }

    private function child() {
        return ( new \ReflectionClass( MainWP_Child::class ) )->newInstanceWithoutConstructor();
    }

    private function notice() {
        ob_start();
        MainWP_Pages::get_instance()->admin_notice();
        return ob_get_clean();
    }

    /** Terminal response writers run in a child process so their exit cannot end PHPUnit. */
    private function stats_response( $window, $connected ) {
        $script = <<<'PHP'
namespace MainWP\Child;
class MainWP_Child {
    public static $version = 'fixture';
    public static function get_connection_window() {
        ++$GLOBALS['window_reads'];
        return $GLOBALS['window'];
    }
}
class MainWP_Helper {
    public static function instance() { return new self(); }
    public function error( $message ) { self::write( array( 'error' => $message ) ); }
    public static function is_wp_engine() { return false; }
    public static function get_wp_host() { return 'fixture'; }
    public static function write( $response ) {
        echo json_encode( array( 'response' => $response, 'writes' => $GLOBALS['writes'], 'started' => $GLOBALS['started'], 'window_reads' => $GLOBALS['window_reads'] ) );
        exit;
    }
}
class MainWP_Child_Server_Information_Base {
    public static function get_wordpress_version() { return '6.2'; }
}
function get_option( $name, $default = false ) {
    if ( 'mainwp_child_pubkey' === $name ) { return $GLOBALS['connected']; }
    if ( 'mainwp_child_lasttime_not_connected' === $name ) { return $GLOBALS['started']; }
    return $default;
}
function update_option( $name, $value ) { $GLOBALS['writes'][] = $name; }
function add_option( $name, $value ) { $GLOBALS['writes'][] = $name; }
function delete_option( $name ) { $GLOBALS['writes'][] = $name; }
function esc_html__( $text, $domain ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
PHP;
        $script .= "\ndefine( 'ABSPATH', '/' );\n";
        $script .= '$GLOBALS["window"] = ' . var_export( $window, true ) . ';';
        $script .= '$GLOBALS["connected"] = ' . var_export( $connected, true ) . ';';
        $script .= '$GLOBALS["started"] = ' . var_export( get_option( 'mainwp_child_lasttime_not_connected' ), true ) . ';';
        $script .= '$GLOBALS["writes"] = array(); $GLOBALS["window_reads"] = 0;';
        $script .= 'require ' . var_export( dirname( __DIR__ ) . '/class/class-mainwp-child-stats.php', true ) . ';';
        $script .= '(new MainWP_Child_Stats())->get_site_stats_no_auth();';
        return $this->run_response_process( $script );
    }

    private function registration_response( $login_succeeds ) {
        $script = <<<'PHP'
namespace MainWP\Child;
class MainWP_Helper {
    public static function instance() { return new self(); }
    public function error( $message, $code = '' ) { self::write( array( 'error' => $message ) ); }
    public static function get_site_unique_id() { return ''; }
    public static function is_ssl_enabled() { return true; }
    public static function is_admin( $user ) { return true; }
    public static function update_option( $name, $value, $autoload = 'no' ) { $GLOBALS['options'][$name] = $value; }
    public static function write( $response ) {
        echo json_encode( array( 'response' => $response, 'options' => $GLOBALS['options'] ) );
        exit;
    }
}
class MainWP_Child_Server_Information_Base {
    public static function get_curl_support() { return true; }
}
class MainWP_Child_Keys_Manager {
    public static function update_encrypted_option( $name, $value ) { $GLOBALS['options'][$name] = $value; }
}
class MainWP_Child_Stats {
    public static function get_instance() { return new self(); }
    public function get_site_stats( $information ) { MainWP_Helper::write( $information ); }
}
function get_option( $name, $default = false ) { return $GLOBALS['options'][$name] ?? $default; }
function delete_option( $name ) { unset( $GLOBALS['options'][$name] ); }
function wp_unslash( $value ) { return $value; }
function sanitize_text_field( $value ) { return $value; }
function esc_html__( $text, $domain ) { return $text; }
function get_user_by( $field, $value ) { return $GLOBALS['current_user']; }
PHP;
        $script .= "\ndefine( 'ABSPATH', '/' );\n";
        $script .= 'require ' . var_export( dirname( __DIR__ ) . '/class/class-mainwp-connect.php', true ) . ';';
        $script .= <<<'PHP'
class Connection_Window_Register_Fixture extends MainWP_Connect {
    public function is_verified_register( $user_name ) { return true; }
    public function login( $username, $doAction = false ) { return $GLOBALS['login_succeeds']; }
}
$GLOBALS['options'] = array( 'mainwp_child_lasttime_not_connected' => 123, 'mainwp_child_connection_window_expired' => array( 'time' => 123, 'ttl' => 3 ) );
$GLOBALS['current_user'] = (object) array( 'ID' => 1, 'user_login' => 'admin' );
$_POST = array( 'user' => 'admin', 'pubkey' => 'public-key-fixture' );
PHP;
        $script .= '$GLOBALS["login_succeeds"] = ' . var_export( $login_succeeds, true ) . ';';
        $script .= '(new Connection_Window_Register_Fixture())->register_site();';
        return $this->run_response_process( $script );
    }

    private function run_response_process( $script ) {
        // nosemgrep: AIK_exec-use -- test harness runs PHP_BINARY on a fixture script built from literals, array form, no shell, no user input.
        $process = proc_open( array( PHP_BINARY, '-r', $script ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
        $this->assertIsResource( $process );
        $output = stream_get_contents( $pipes[1] );
        $errors = stream_get_contents( $pipes[2] );
        fclose( $pipes[1] );
        fclose( $pipes[2] );
        $this->assertSame( 0, proc_close( $process ), $errors );
        $this->assertSame( '', $errors );
        $decoded = json_decode( $output, true );
        $this->assertIsArray( $decoded, $output );
        return $decoded;
    }
}
