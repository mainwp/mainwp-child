<?php
/**
 * UpdraftPlus backup time compatibility tests.
 *
 * @package MainWP_Child
 */

namespace WP_MainWP_Stream;

/** Published Reports logs successful backups without writing the backup time. */
class MainWP_Child_Report_Helper {
    /** @var bool Simulate a Reports build that also saves the time. */
    public static $writes_time = false;

    /**
     * Handle the provider filter as Reports does.
     *
     * @param mixed $backup Provider result.
     * @return mixed Unchanged result.
     */
    public static function hook_updraftplus_save_last_backup( $backup ) {
        if ( is_array( $backup ) && isset( $backup['backup_time'] ) && ! empty( $backup['success'] ) ) {
            do_action( 'updraftplus_backup', '', 'Backup finished', 'Finished', 'database', $backup['backup_time'] );
            if ( self::$writes_time ) {
                update_option( 'mainwp_lasttime_backup_updraftplus', $backup['backup_time'] );
            }
        }
        return $backup;
    }
}

/** Exercise constructor registration and the real WordPress filter dispatcher. */
class Test_Updraftplus_Backup_Time extends \WP_UnitTestCase {
    /** @var array Saved hooks. */
    private $saved_hooks;

    /** Set up an active UpdraftPlus installation. */
    public function set_up(): void {
        parent::set_up();
        global $wp_filter;
        $this->saved_hooks = $wp_filter;
        unset( $wp_filter['updraftplus_save_last_backup'], $wp_filter['updraftplus_backup'] );
        if ( ! defined( 'UPDRAFTPLUS_DIR' ) ) {
            define( 'UPDRAFTPLUS_DIR', __DIR__ . '/fixtures/updraftplus' );
        }
        update_option( 'active_plugins', array( 'updraftplus/updraftplus.php' ) );
        update_option( 'mainwp_lasttime_backup_updraftplus', 100 );
        MainWP_Child_Report_Helper::$writes_time = false;
    }

    /** Restore hook state after each case. */
    public function tear_down(): void {
        global $wp_filter;
        $wp_filter = $this->saved_hooks;
        MainWP_Child_Report_Helper::$writes_time = false;
        parent::tear_down();
    }

    /**
     * Backups update the cursor independently of Reports activity logging.
     *
     * @dataProvider backup_cases
     * @param string $reports Reports mode.
     * @param bool   $reports_first Register Reports first.
     * @param mixed  $input Provider input.
     * @param int    $expected Expected timestamp.
     * @param int    $activity Expected activity count.
     */
    public function test_backup_time( $reports, $reports_first, $input, $expected, $activity ) {
        $callback = array( MainWP_Child_Report_Helper::class, 'hook_updraftplus_save_last_backup' );
        MainWP_Child_Report_Helper::$writes_time = 'future' === $reports;
        if ( 'none' !== $reports && $reports_first ) {
            add_filter( 'updraftplus_save_last_backup', $callback );
        }
        $subject = new \MainWP\Child\MainWP_Child_Updraft_Plus_Backups();
        $this->assertTrue( $subject->is_plugin_installed );
        if ( 'none' !== $reports && ! $reports_first ) {
            add_filter( 'updraftplus_save_last_backup', $callback );
        }
        $before = did_action( 'updraftplus_backup' );
        $this->assertSame( $input, apply_filters( 'updraftplus_save_last_backup', $input ) );
        $this->assertSame( $activity, did_action( 'updraftplus_backup' ) - $before, 'Reports activity count' );
        $this->assertSame( $expected, get_option( 'mainwp_lasttime_backup_updraftplus' ), 'Stored backup time' );
        $this->assertSame( $expected, \MainWP\Child\MainWP_Utility::get_lasttime_backup( 'updraftplus' ), 'Sync backup time' );
    }

    /**
     * Provider results and Reports registration orders.
     *
     * @return array Test cases.
     */
    public static function backup_cases() {
        $cases = array();
        foreach ( array( 'none', 'published', 'future' ) as $reports ) {
            foreach ( array( true, false ) as $first ) {
                foreach ( array( 'success' => array( 200, 1 ), 'failed' => array( 200, 0 ), 'older' => array( 50, 1 ) ) as $name => $values ) {
                    // The future helper's own unconditional write is outside Child's monotonic contract.
                    if ( 'future' === $reports && 'older' === $name ) {
                        continue;
                    }
                    $input = array( 'backup_time' => $values[0], 'success' => $values[1], 'backup_array' => array( 'db' => 'x' ) );
                    $cases[ $reports . '-' . ( $first ? 'first' : 'last' ) . '-' . $name ] = array( $reports, $first, $input, $values[1] && $values[0] > 100 ? 200 : 100, 'none' !== $reports && $values[1] ? 1 : 0 );
                }
                foreach ( array( null, 'invalid', array( 'success' => 1 ) ) as $key => $input ) {
                    $cases[ $reports . '-' . ( $first ? 'first' : 'last' ) . '-invalid-' . $key ] = array( $reports, $first, $input, 100, 0 );
                }
            }
        }
        return $cases;
    }
}
