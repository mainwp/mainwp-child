<?php
/**
 * MainWP Child Password Policy
 *
 * Manages password policy settings received from the Dashboard.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Child_Password_Policy
 *
 * Handles password policy configuration and enforcement.
 */
class MainWP_Child_Password_Policy {

    /**
     * Public static instance.
     *
     * @var null Default null.
     */
    public static $instance = null;

    /**
     * Get Instance
     *
     * Creates public static instance.
     *
     * @return MainWP_Child_Password_Policy
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    public function __construct() {
        $this->init_options();
        add_action( 'admin_notices', array( $this, 'render_admin_notice' ) );
    }

    /**
     * Initialize default options if not set.
     */
    private function init_options() {
        if ( false === get_option( 'mainwp_pw_max_age_days', false ) ) {
            MainWP_Helper::update_option( 'mainwp_pw_max_age_days', 0 );
        }
        if ( false === get_option( 'mainwp_pw_due_soon_days', false ) ) {
            MainWP_Helper::update_option( 'mainwp_pw_due_soon_days', 7 );
        }
        if ( false === get_option( 'mainwp_pw_due_soon_message', false ) ) {
            MainWP_Helper::update_option( 'mainwp_pw_due_soon_message', '' );
        }
        if ( false === get_option( 'mainwp_pw_overdue_message', false ) ) {
            MainWP_Helper::update_option( 'mainwp_pw_overdue_message', '' );
        }
        if ( false === get_option( 'mainwp_pw_show_notices_to', false ) ) {
            MainWP_Helper::update_option( 'mainwp_pw_show_notices_to', 'edit_posts' );
        }
    }

    /**
     * Main action dispatcher.
     *
     * @return array Response array.
     */
    public function action() {
        $action = isset( $_POST['action'] ) ? sanitize_text_field( wp_unslash( $_POST['action'] ) ) : '';

        if ( 'update_password_policy' === $action ) {
            return $this->update_password_policy();
        }

        return array( 'error' => 'Invalid action' );
    }

    /**
     * Update password policy settings from Dashboard sync process.
     *
     * Similar to MainWP_Child_Plugins_Check::may_outdate_number_change(),
     * this method receives password policy settings during site sync.
     *
     * @return void
     */
	public function sync_password_policy_settings() { //phpcs:ignore --NOSONAR -complex.
        if ( ! isset( $_POST['passwordPolicySettings'] ) ) {
            return;
        }

        $settings_json = wp_unslash( $_POST['passwordPolicySettings'] );
        $settings      = json_decode( $settings_json, true );

        if ( ! is_array( $settings ) ) {
            return;
        }

        $max_age_days     = isset( $settings['max_age_days'] ) ? intval( $settings['max_age_days'] ) : 0;
        $due_soon_message = isset( $settings['due_soon_message'] ) ? sanitize_textarea_field( $settings['due_soon_message'] ) : '';
        $overdue_message  = isset( $settings['overdue_message'] ) ? sanitize_textarea_field( $settings['overdue_message'] ) : '';
        $show_notices_to  = isset( $settings['show_notices_to'] ) ? sanitize_text_field( $settings['show_notices_to'] ) : 'edit_posts';

        if ( ! in_array( $show_notices_to, array( 'edit_posts', 'all_users' ), true ) ) {
            $show_notices_to = 'edit_posts';
        }

        $current_max_age = get_option( 'mainwp_pw_max_age_days', 0 );

        if ( intval( $current_max_age ) !== $max_age_days ) {
            MainWP_Helper::update_option( 'mainwp_pw_max_age_days', $max_age_days );

            if ( 0 === intval( $current_max_age ) && $max_age_days > 0 ) {
                $current_enrollment = get_option( 'mainwp_pw_policy_enabled_at', null );
                if ( empty( $current_enrollment ) ) {
                    MainWP_Helper::update_option( 'mainwp_pw_policy_enabled_at', time() );
                }
            }
        }

        if ( get_option( 'mainwp_pw_due_soon_message', '' ) !== $due_soon_message ) {
            MainWP_Helper::update_option( 'mainwp_pw_due_soon_message', $due_soon_message );
        }

        if ( get_option( 'mainwp_pw_overdue_message', '' ) !== $overdue_message ) {
            MainWP_Helper::update_option( 'mainwp_pw_overdue_message', $overdue_message );
        }

        if ( get_option( 'mainwp_pw_show_notices_to', 'edit_posts' ) !== $show_notices_to ) {
            MainWP_Helper::update_option( 'mainwp_pw_show_notices_to', $show_notices_to );
        }
    }

    /**
     * Update password policy settings.
     *
     * @return array Response array with result or error.
     */
    private function update_password_policy() {
        if ( ! isset( $_POST['max_age_days'] ) ) {
            return array( 'error' => 'Missing required parameter: max_age_days' );
        }

        $max_age_days   = intval( $_POST['max_age_days'] );
        $allowed_values = array( 0, 30, 60, 90, 120, 180, 360 );

        if ( ! in_array( $max_age_days, $allowed_values, true ) ) {
            return array( 'error' => 'Invalid policy window. Allowed values: 0, 30, 60, 90, 120, 180, 360' );
        }

        $due_soon_days = isset( $_POST['due_soon_days'] ) ? intval( $_POST['due_soon_days'] ) : 7;

        if ( $due_soon_days < 0 || $due_soon_days > 30 ) {
            return array( 'error' => 'Invalid due_soon_days value. Must be between 0 and 30' );
        }

        $due_soon_message = isset( $_POST['due_soon_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['due_soon_message'] ) ) : '';
        $overdue_message  = isset( $_POST['overdue_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['overdue_message'] ) ) : '';
        $show_notices_to  = isset( $_POST['show_notices_to'] ) ? sanitize_text_field( wp_unslash( $_POST['show_notices_to'] ) ) : 'edit_posts';

        if ( ! in_array( $show_notices_to, array( 'edit_posts', 'all_users' ), true ) ) {
            $show_notices_to = 'edit_posts';
        }

        $current_max_age    = get_option( 'mainwp_pw_max_age_days', 0 );
        $current_enrollment = get_option( 'mainwp_pw_policy_enabled_at', null );

        if ( 0 === intval( $current_max_age ) && $max_age_days > 0 ) {
            if ( empty( $current_enrollment ) ) {
                $enrollment_time = time();
                MainWP_Helper::update_option( 'mainwp_pw_policy_enabled_at', $enrollment_time );
            } else {
                $enrollment_time = $current_enrollment;
            }
        } else {
            $enrollment_time = $current_enrollment;
        }

        MainWP_Helper::update_option( 'mainwp_pw_max_age_days', $max_age_days );
        MainWP_Helper::update_option( 'mainwp_pw_due_soon_days', $due_soon_days );
        MainWP_Helper::update_option( 'mainwp_pw_due_soon_message', $due_soon_message );
        MainWP_Helper::update_option( 'mainwp_pw_overdue_message', $overdue_message );
        MainWP_Helper::update_option( 'mainwp_pw_show_notices_to', $show_notices_to );

        return array(
            'result'            => 'SUCCESS',
            'max_age_days'      => $max_age_days,
            'due_soon_days'     => $due_soon_days,
            'due_soon_message'  => $due_soon_message,
            'overdue_message'   => $overdue_message,
            'show_notices_to'   => $show_notices_to,
            'policy_enabled_at' => $enrollment_time,
        );
    }

    /**
     * Get current policy options.
     *
     * @return array Current policy settings.
     */
    public function get_policy_options() {
        return array(
            'max_age_days'      => get_option( 'mainwp_pw_max_age_days', 0 ),
            'policy_enabled_at' => get_option( 'mainwp_pw_policy_enabled_at', null ),
            'due_soon_days'     => get_option( 'mainwp_pw_due_soon_days', 7 ),
            'due_soon_message'  => get_option( 'mainwp_pw_due_soon_message', '' ),
            'overdue_message'   => get_option( 'mainwp_pw_overdue_message', '' ),
            'show_notices_to'   => get_option( 'mainwp_pw_show_notices_to', 'edit_posts' ),
        );
    }

    /**
     * Get user password status.
     *
     * @param int $user_id User ID to check.
     * @return array Status information array.
     */
    public function get_user_password_status( $user_id ) {
        $max_age_days      = get_option( 'mainwp_pw_max_age_days', 0 );
        $policy_enabled_at = get_option( 'mainwp_pw_policy_enabled_at', null );
        $due_soon_days     = get_option( 'mainwp_pw_due_soon_days', 7 );

        if ( 0 === intval( $max_age_days ) ) {
            return array(
                'status'              => 'DISABLED',
                'last_change'         => null,
                'basis_time'          => null,
                'due_time'            => null,
                'has_recorded_change' => false,
            );
        }

        $last_change = get_user_meta( $user_id, 'mainwp_last_password_change', true );

        if ( ! empty( $last_change ) ) {
            $basis_time          = intval( $last_change );
            $has_recorded_change = true;
        } else {
            $basis_time          = ! empty( $policy_enabled_at ) ? intval( $policy_enabled_at ) : time();
            $has_recorded_change = false;
        }

        $due_time      = $basis_time + ( $max_age_days * DAY_IN_SECONDS );
        $due_soon_time = $due_time - ( $due_soon_days * DAY_IN_SECONDS );
        $current_time  = time();

        if ( $current_time >= $due_time ) {
            $status = 'OVERDUE';
        } elseif ( $current_time >= $due_soon_time ) {
            $status = 'DUE';
        } else {
            $status = 'OK';
        }

        return array(
            'status'              => $status,
            'last_change'         => ! empty( $last_change ) ? intval( $last_change ) : null,
            'basis_time'          => $basis_time,
            'due_time'            => $due_time,
            'has_recorded_change' => $has_recorded_change,
            'policy_enabled_at'   => $policy_enabled_at,
        );
    }

    /**
     * Check if notice should be shown for user.
     *
     * @param int $user_id User ID to check.
     * @return bool True if notice should be shown.
     */
    public function should_show_notice( $user_id ) {
        $status_data = $this->get_user_password_status( $user_id );
        return in_array( $status_data['status'], array( 'DUE', 'OVERDUE' ), true );
    }

    /**
     * Render admin notice for password policy.
     */
    public function render_admin_notice() {
        if ( ! is_user_logged_in() ) {
            return;
        }

        $user_id = get_current_user_id();
        if ( ! $user_id || 0 === $user_id ) {
            return;
        }

        $show_notices_to = get_option( 'mainwp_pw_show_notices_to', 'edit_posts' );

        if ( 'edit_posts' === $show_notices_to && ! current_user_can( 'edit_posts' ) ) {
            return;
        }

        if ( ! $this->should_show_notice( $user_id ) ) {
            return;
        }

        $status_data  = $this->get_user_password_status( $user_id );
        $message      = $this->get_notice_message( $status_data );
        $notice_class = 'OVERDUE' === $status_data['status'] ? 'notice-error' : 'notice-warning';

        ?>
        <div class="notice <?php echo esc_attr( $notice_class ); ?> is-dismissible">
            <p><?php echo wp_kses_post( $message ); ?></p>
        </div>
        <?php
    }

    /**
     * Render frontend notice for password policy.
     */
    public function render_frontend_notice() { //phpcs:ignore --NOSONAR -ok.
        if ( ! is_user_logged_in() ) {
            return;
        }

        $user_id = get_current_user_id();
        if ( ! $user_id || 0 === $user_id ) {
            return;
        }

        $show_notices_to = get_option( 'mainwp_pw_show_notices_to', 'edit_posts' );

        if ( 'edit_posts' === $show_notices_to ) {
            return;
        }

        if ( current_user_can( 'edit_posts' ) ) {
            return;
        }

        // Check policy status inline.
        $max_age_days = get_option( 'mainwp_pw_max_age_days', 0 );
        if ( 0 === intval( $max_age_days ) ) {
            return;
        }

        $policy_enabled_at = get_option( 'mainwp_pw_policy_enabled_at', null );
        $due_soon_days     = get_option( 'mainwp_pw_due_soon_days', 7 );
        $last_change       = get_user_meta( $user_id, 'mainwp_last_password_change', true );

        if ( empty( $last_change ) ) {
            $basis_time = intval( $last_change );
        } elseif ( ! empty( $policy_enabled_at ) ) {
            $basis_time = intval( $policy_enabled_at );
        } else {
            $basis_time = time();
        }

        $due_time = $basis_time + ( $max_age_days * DAY_IN_SECONDS );
        $now      = time();

        if ( $now >= $due_time ) {
            $status = 'OVERDUE';
        } elseif ( $now >= $due_time - ( $due_soon_days * DAY_IN_SECONDS ) ) {
            $status = 'DUE';
        } else {
            return;
        }

        // Build message.
        $profile_url = admin_url( 'profile.php' );
        $link        = '<a href="' . esc_url( $profile_url ) . '">' . esc_html__( 'Update your password', 'mainwp-child' ) . '</a>';

        if ( 'OVERDUE' === $status ) {
            $custom  = get_option( 'mainwp_pw_overdue_message', '' );
            $default = __( 'Your password change is overdue. Please update your password now. This is required by your site\'s password policy.', 'mainwp-child' );
            $message = ( ! empty( $custom ) ? $custom : $default ) . ' ' . $link;
        } else {
            $custom  = get_option( 'mainwp_pw_due_soon_message', '' );
            $default = __( 'Your password is due to be changed soon. Please update it as soon as possible. This helps keep your account secure.', 'mainwp-child' );
            $message = ( ! empty( $custom ) ? $custom : $default ) . ' ' . $link;
        }

        if ( 'OVERDUE' === $status ) {
            $bg_color     = '#f8d7da';
            $border_color = '#dc3545';
            $text_color   = '#721c24';
        } else {
            $bg_color     = '#fff3cd';
            $border_color = '#ffc107';
            $text_color   = '#856404';
        }
        ?>
        <div id="mainwp-password-policy-notice" style="position:fixed;bottom:0;left:0;right:0;background:<?php echo esc_attr( $bg_color ); ?>;border-top:1px solid <?php echo esc_attr( $border_color ); ?>;padding:10px;text-align:center;z-index:9999;box-shadow:0 -2px 5px rgba(0,0,0,0.1);">
            <p style="margin:0;color:<?php echo esc_attr( $text_color ); ?>;"><?php echo wp_kses_post( $message ); ?></p>
            <button onclick="this.parentElement.remove();" style="position:absolute;top:50%;right:15px;transform:translateY(-50%);background:none;border:none;font-size:18px;cursor:pointer;color:<?php echo esc_attr( $text_color ); ?>;padding:0;line-height:1;" aria-label="<?php esc_attr_e( 'Dismiss notice', 'mainwp-child' ); ?>">&times;</button>
        </div>
        <?php
    }

    /**
     * Get notice message based on status data.
     *
     * @param array $status_data Status data array.
     * @return string Notice message.
     */
    public function get_notice_message( $status_data ) {
        $status                  = $status_data['status'];
        $custom_due_soon_message = get_option( 'mainwp_pw_due_soon_message', '' );
        $custom_overdue_message  = get_option( 'mainwp_pw_overdue_message', '' );

        $profile_url = admin_url( 'profile.php' );
        $link_text   = '<a href="' . esc_url( $profile_url ) . '">Update your password</a>';

        if ( 'DUE' === $status ) {
            if ( ! empty( $custom_due_soon_message ) ) {
                $message = $custom_due_soon_message . ' ' . $link_text;
            } else {
                $message = 'Your password is due to be changed soon. Please update it as soon as possible. This helps keep your account secure. ' . $link_text;
            }
        } elseif ( 'OVERDUE' === $status ) {
            if ( ! empty( $custom_overdue_message ) ) {
                $message = $custom_overdue_message . ' ' . $link_text;
            } else {
                $message = 'Your password change is overdue. Please update your password now. This is required by your site\'s password policy. ' . $link_text;
            }
        } else {
            $message = '';
        }

        return $message;
    }

    /**
     * Format date using WordPress date format.
     *
     * @param int|null $timestamp Unix timestamp.
     * @return string Formatted date or empty string.
     */
    public function format_date( $timestamp ) {
        if ( empty( $timestamp ) ) {
            return '';
        }
        return date_i18n( get_option( 'date_format' ), intval( $timestamp ) );
    }
}
