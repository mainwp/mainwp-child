<?php
/**
 * Site Hardening must flag PHP error display when either directive is on.
 *
 * @package MainWP_Child
 */

namespace MainWP\Child;

use WP_UnitTestCase;

/**
 * Covers MainWP_Security::remove_php_reporting_ok() and its callers.
 */
class Test_MainWP_Security_PHP_Reporting extends WP_UnitTestCase {

    /**
     * Ini values present before each test.
     *
     * @var array
     */
    private $original_ini = array();

    /**
     * Saves both directives and clears the stored hardening options.
     */
    public function setUp(): void {
        parent::setUp();
        foreach ( array( 'display_errors', 'display_startup_errors' ) as $directive ) {
            $this->original_ini[ $directive ] = ini_get( $directive );
        }
        update_option( 'mainwp_security', array() );
    }

    /**
     * Restores both directives so other tests keep their error display.
     */
    public function tearDown(): void {
        $this->restore_ini();
        parent::tearDown();
    }

    /**
     * Each row sets both directives and states whether the check should pass.
     *
     * @return array
     */
    public static function directive_provider() {
        return array(
            'display_errors on only'                   => array( '1', '0', false ),
            'display_startup_errors on only'           => array( '0', '1', false ),
            'both on'                                  => array( '1', '1', false ),
            'both off'                                 => array( '0', '0', true ),
            'textual Off'                              => array( 'Off', 'Off', true ),
            'textual off'                              => array( 'off', 'off', true ),
            'textual no'                               => array( 'no', 'no', true ),
            'textual false'                            => array( 'false', 'false', true ),
            'textual none'                             => array( 'none', 'none', true ),
            'textual 0'                                => array( '0', '0', true ),
            'empty string'                             => array( '', '', true ),
            'padded uppercase OFF'                     => array( ' OFF ', ' OFF ', true ),
            'display_errors On, startup off'           => array( 'On', 'Off', false ),
            'display_errors off, startup On'           => array( 'Off', 'On', false ),
            'display_errors stderr, startup off'       => array( 'stderr', '0', false ),
            'display_errors off, startup stderr'       => array( '0', 'stderr', false ),
            'display_errors unrecognised, startup off' => array( 'foo', '0', false ),
        );
    }

    /**
     * The helper, the stats response and the issue count must agree.
     *
     * @dataProvider directive_provider
     * @param string $display_errors         Value for display_errors.
     * @param string $display_startup_errors Value for display_startup_errors.
     * @param bool   $expected_ok            Whether error display counts as disabled.
     */
    public function test_php_reporting_check( $display_errors, $display_startup_errors, $expected_ok ) {
        try {
            $this->set_ini( '0', '0' );
            $baseline_issues = MainWP_Security::get_stats_security();

            $this->set_ini( $display_errors, $display_startup_errors );

            $this->assertSame( $expected_ok, MainWP_Security::remove_php_reporting_ok() );

            $stats = MainWP_Child_Misc::get_instance()->get_security_stats( true );
            $this->assertSame( $expected_ok ? 'Y_UNABLE' : 'N', $stats['php_reporting'] );

            $expected_issues = $expected_ok ? $baseline_issues : $baseline_issues + 1;
            $this->assertSame( $expected_issues, MainWP_Security::get_stats_security() );
        } finally {
            $this->restore_ini();
        }
    }

    /**
     * Sets both directives and confirms PHP accepted the values.
     *
     * @param string $display_errors         Value for display_errors.
     * @param string $display_startup_errors Value for display_startup_errors.
     */
    private function set_ini( $display_errors, $display_startup_errors ) {
        ini_set( 'display_errors', $display_errors ); // phpcs:ignore WordPress.PHP.IniSet.display_errors_Disallowed -- the check under test reads this directive.
        ini_set( 'display_startup_errors', $display_startup_errors ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- the check under test reads this directive.
        $this->assertSame( $display_errors, ini_get( 'display_errors' ) );
        $this->assertSame( $display_startup_errors, ini_get( 'display_startup_errors' ) );
    }

    /**
     * Puts back the values saved in setUp().
     */
    private function restore_ini() {
        foreach ( $this->original_ini as $directive => $value ) {
            ini_set( $directive, $value ); // phpcs:ignore WordPress.PHP.IniSet -- restores the value saved in setUp().
        }
    }
}
