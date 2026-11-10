<?php
/**
 * Unfix reports the effective error display settings.
 *
 * @package MainWP_Child
 */

namespace MainWP\Child;

class Test_Child_Security_Unfix extends \WP_UnitTestCase {
    public function test_unfix_reports_effective_settings() {
        foreach ( array( 'off', 'on', 'late_display', 'late_startup', 'late_db' ) as $mode ) {
            foreach ( array( 'php_reporting', 'db_reporting' ) as $feature ) {
                $script = <<<'PHP'
namespace MainWP\Child {
    class MainWP_Helper {
        public static function update_option( $name, $value, $autoload ) {
            $GLOBALS['options'][ $name ] = $value;
        }
        public static function write( $value ) {
            echo json_encode( array( $value, $GLOBALS['options'] ) );
            exit;
        }
    }
}
namespace {
    class Test_WPDB {
        public $show_errors;
        public function hide_errors() { $this->show_errors = false; }
        public function suppress_errors() {}
    }
    function sanitize_text_field( $value ) { return $value; }
    function wp_unslash( $value ) { return $value; }
    function get_option( $name, $default ) { return $GLOBALS['options'][ $name ] ?? $default; }
    define( 'ABSPATH', '/' );
    $mode = $argv[1];
    $enabled = 'on' === $mode;
    $feature = $argv[2];
    ini_set( 'display_errors', $enabled ? '1' : '0' );
    ini_set( 'display_startup_errors', $enabled ? '1' : '0' );
    $GLOBALS['wpdb'] = new Test_WPDB();
    $GLOBALS['wpdb']->show_errors = $enabled;
    $GLOBALS['options'] = array( 'mainwp_security' => array( $feature => true ) );
    $_POST['feature'] = $feature;
    require $argv[3] . '/class/class-mainwp-security.php';
    require $argv[3] . '/class/class-mainwp-child-misc.php';
    \MainWP\Child\MainWP_Security::save_php_reporting_state();
    ini_set( 'display_errors', '0' );
    if ( 'late_display' === $mode ) { ini_set( 'display_errors', '1' ); }
    if ( 'late_startup' === $mode ) { ini_set( 'display_startup_errors', '1' ); }
    if ( 'late_db' === $mode ) { $GLOBALS['wpdb']->show_errors = true; }
    \MainWP\Child\MainWP_Security::fix_all();
    (new \MainWP\Child\MainWP_Child_Misc())->do_security_un_fix();
}
PHP;
                $command = escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $script ) . ' ' .
                    escapeshellarg( $mode ) . ' ' . escapeshellarg( $feature ) . ' ' .
                    escapeshellarg( dirname( __DIR__ ) );
                $output = array();
                exec( $command, $output, $status );
                $this->assertSame( 0, $status, implode( "\n", $output ) );
                $result = json_decode( implode( "\n", $output ), true );
                $this->assertIsArray( $result );
                $enabled = 'on' === $mode || ( 'db_reporting' === $feature ? 'late_db' === $mode : in_array( $mode, array( 'late_display', 'late_startup' ), true ) );
                $this->assertSame( $enabled ? 'N' : 'Y_UNABLE', $result[0][ $feature ], $mode . ': ' . $feature );
                $this->assertFalse( $result[1]['mainwp_security'][ $feature ] );
            }
        }
    }
}
