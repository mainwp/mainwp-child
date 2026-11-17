<?php
/**
 * Cron observers preserve provider arguments.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\Changes\Changes_Handle_WP_System;

/**
 * Exercises stored cron events through WordPress dispatch.
 */
class Test_Changes_Logs_Cron_Named_Args extends WP_UnitTestCase {

    /**
     * Observers accept both orphaned and active provider events.
     *
     * @dataProvider cron_arguments
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     * @param array $args Stored arguments.
     */
    public function test_cron_observer_preserves_provider_arguments( $args ) {
        if ( ! defined( 'DOING_CRON' ) ) {
            define( 'DOING_CRON', true );
        }
        $hook     = 'hmbkp_schedule_mwp2177_hook';
        $received = null;
        try {
            $this->assertTrue( wp_schedule_event( time() - 5, 'daily', $hook, $args ) );
            Changes_Handle_WP_System::change_set_executed_cron( false );
            Changes_Handle_WP_System::change_attach_cron_actions();
            $this->assertNotFalse( has_action( $hook ) );
            do_action_ref_array( $hook, $args );
            add_action(
                $hook,
                function ( $id ) use ( &$received ) {
                    $received = $id;
                }
            );
            do_action_ref_array( $hook, $args );
            $this->assertSame( 'mwp2177', $received );
            $this->assertSame( $args, wp_get_scheduled_event( $hook, $args )->args );
        } finally {
            remove_all_actions( $hook );
            wp_clear_scheduled_hook( $hook, $args );
            Changes_Handle_WP_System::change_set_executed_cron( false );
        }
    }

    /**
     * Supplies cron argument shapes.
     *
     * @return array Cron arguments.
     */
    public function cron_arguments() {
        return array(
            'named'      => array( array( 'id' => 'mwp2177' ) ),
            'positional' => array( array( 'mwp2177' ) ),
        );
    }
}
