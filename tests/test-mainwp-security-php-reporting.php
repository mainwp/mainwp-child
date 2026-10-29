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
     * Error reporting mask present before each test.
     *
     * @var int
     */
    private $original_error_reporting = 0;

    /**
     * Saves both directives and the error mask, and clears the stored hardening options.
     */
    public function setUp(): void {
        parent::setUp();
        foreach ( array( 'display_errors', 'display_startup_errors' ) as $directive ) {
            $this->original_ini[ $directive ] = ini_get( $directive );
        }
        $this->original_error_reporting = error_reporting(); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting,WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting -- reads the mask so tearDown() can restore it.
        $this->reset_saved_display_errors();
        update_option( 'mainwp_security', array() );
    }

    /**
     * Restores both directives, the error mask and the saved value so other tests are unaffected.
     */
    public function tearDown(): void {
        $this->restore_ini();
        error_reporting( $this->original_error_reporting ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting,WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting -- restores the mask saved in setUp().
        $this->reset_saved_display_errors();
        update_option( 'mainwp_security', array() );
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
     * A signed request on a site showing errors must be flagged, even though the
     * request itself has display_errors off.
     */
    public function test_signed_request_with_display_errors_on_is_flagged() {
        $this->set_ini( '0', '0' );
        $baseline_issues = MainWP_Security::get_stats_security();

        $this->start_signed_request( '1', '0' );

        $this->assertFalse( MainWP_Security::remove_php_reporting_ok() );
        $stats = MainWP_Child_Misc::get_instance()->get_security_stats( true );
        $this->assertSame( 'N', $stats['php_reporting'] );
        $this->assertSame( $baseline_issues + 1, MainWP_Security::get_stats_security() );
    }

    /**
     * A signed request on a site with both directives off stays Good.
     */
    public function test_signed_request_with_both_off_is_good() {
        $this->set_ini( '0', '0' );
        $baseline_issues = MainWP_Security::get_stats_security();

        $this->start_signed_request( '0', '0' );

        $this->assertTrue( MainWP_Security::remove_php_reporting_ok() );
        $stats = MainWP_Child_Misc::get_instance()->get_security_stats( true );
        $this->assertSame( 'Y_UNABLE', $stats['php_reporting'] );
        $this->assertSame( $baseline_issues, MainWP_Security::get_stats_security() );
    }

    /**
     * Either directive turned on later in the request still counts as on.
     */
    public function test_directive_turned_on_after_save_is_flagged() {
        $this->start_signed_request( '0', '0' );
        ini_set( 'display_startup_errors', '1' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- simulates a snippet that runs after the save.
        $this->assertFalse( MainWP_Security::remove_php_reporting_ok() );

        $this->start_signed_request( '0', '0' );
        ini_set( 'display_errors', '1' ); // phpcs:ignore WordPress.PHP.IniSet.display_errors_Disallowed -- simulates a snippet that runs after the save.
        $this->assertFalse( MainWP_Security::remove_php_reporting_ok() );
    }

    /**
     * Disable in a signed request, in do_security_fix() order, reports Y.
     */
    public function test_disable_in_signed_request_reports_y() {
        $this->start_signed_request( '1', '0' );

        MainWP_Security::remove_php_reporting( true );
        $this->assertTrue( MainWP_Security::remove_php_reporting_ok() );

        update_option( 'mainwp_security', array( 'php_reporting' => true ) );
        $stats = MainWP_Child_Misc::get_instance()->get_security_stats( true );
        $this->assertSame( 'Y', $stats['php_reporting'] );
    }

    /**
     * After Disable, later signed requests report Y; after Re-enable, the next one reports N.
     */
    public function test_later_signed_requests_after_disable_and_reenable() {
        update_option( 'mainwp_security', array( 'php_reporting' => true ) );
        $this->start_signed_request( '1', '0' );

        $this->assertTrue( MainWP_Security::remove_php_reporting_ok() );
        $stats = MainWP_Child_Misc::get_instance()->get_security_stats( true );
        $this->assertSame( 'Y', $stats['php_reporting'] );

        update_option( 'mainwp_security', array( 'php_reporting' => false ) );
        $this->start_signed_request( '1', '0' );

        $this->assertFalse( MainWP_Security::remove_php_reporting_ok() );
        $stats = MainWP_Child_Misc::get_instance()->get_security_stats( true );
        $this->assertSame( 'N', $stats['php_reporting'] );
    }

    /**
     * Starts a new signed request: the site's values, the save and override from the
     * top of class-mainwp-child.php, then fix_all() as parse_init() runs it.
     *
     * @param string $display_errors         Site value for display_errors.
     * @param string $display_startup_errors Site value for display_startup_errors.
     */
    private function start_signed_request( $display_errors, $display_startup_errors ) {
        $this->reset_saved_display_errors();
        $this->set_ini( $display_errors, $display_startup_errors );
        MainWP_Security::save_php_reporting_state();
        ini_set( 'display_errors', false ); // phpcs:ignore WordPress.PHP.IniSet.display_errors_Disallowed -- the signed-request override under test.
        MainWP_Security::fix_all();
    }

    /**
     * Clears the display_errors value saved by MainWP_Security.
     */
    private function reset_saved_display_errors() {
        $property = new \ReflectionProperty( MainWP_Security::class, 'saved_display_errors' );
        $property->setAccessible( true );
        $property->setValue( null, null );
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
