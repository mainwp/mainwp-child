<?php
/**
 * Password reminder status on the front end.
 *
 * @package MainWP_Child
 */

namespace MainWP\Child;

class Test_Password_Policy_Frontend extends \WP_UnitTestCase {
    /**
     * @dataProvider reminder_cases
     */
    public function test_frontend_status( $change_days, $policy_days, $max_age, $role, $audience, $expected ) {
        $user_id = self::factory()->user->create( array( 'role' => $role ) );
        wp_set_current_user( $user_id );
        delete_user_meta( $user_id, 'mainwp_last_password_change' );
        if ( null !== $change_days ) {
            update_user_meta( $user_id, 'mainwp_last_password_change', time() - $change_days * DAY_IN_SECONDS );
        }
        update_option( 'mainwp_pw_max_age_days', $max_age );
        update_option( 'mainwp_pw_due_soon_days', 7 );
        update_option( 'mainwp_pw_policy_enabled_at', null === $policy_days ? 0 : time() - $policy_days * DAY_IN_SECONDS );
        update_option( 'mainwp_pw_show_notices_to', $audience );
        $policy = MainWP_Child_Password_Policy::instance();
        $status = $policy->get_user_password_status( $user_id );
        $this->assertSame( $expected, $status['status'] );
        ob_start();
        $policy->render_frontend_notice();
        $html = ob_get_clean();
        $eligible = 'subscriber' === $role && 'all_users' === $audience;
        if ( ! $eligible || ! in_array( $expected, array( 'DUE', 'OVERDUE' ), true ) ) {
            $this->assertSame( '', trim( $html ), 'No banner is expected for this status and audience.' );
        } else {
            $this->assertStringContainsString( 'id="mainwp-password-policy-notice"', $html );
            $this->assertStringContainsString( 'OVERDUE' === $status['status'] ? '#f8d7da' : '#fff3cd', $html );
        }
    }

    public function reminder_cases() {
        return array(
            'new policy' => array( null, 1, 30, 'subscriber', 'all_users', 'OK' ),
            'missing policy start' => array( null, null, 30, 'subscriber', 'all_users', 'OK' ),
            'policy due' => array( null, 25, 30, 'subscriber', 'all_users', 'DUE' ),
            'policy overdue' => array( null, 40, 30, 'subscriber', 'all_users', 'OVERDUE' ),
            'recent change' => array( 1, 60, 30, 'subscriber', 'all_users', 'OK' ),
            'change due' => array( 25, 1, 30, 'subscriber', 'all_users', 'DUE' ),
            'change overdue' => array( 40, 1, 30, 'subscriber', 'all_users', 'OVERDUE' ),
            'disabled' => array( 40, 60, 0, 'subscriber', 'all_users', 'DISABLED' ),
            'editor' => array( 40, 60, 30, 'editor', 'all_users', 'OVERDUE' ),
            'restricted audience' => array( 40, 60, 30, 'subscriber', 'edit_posts', 'OVERDUE' ),
        );
    }
}
