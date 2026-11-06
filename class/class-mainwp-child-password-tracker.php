<?php
/**
 * MainWP Child Password Tracker.
 *
 * Lightweight class to track password changes on both admin and frontend.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Child_Password_Tracker
 *
 * Tracks password changes for password policy enforcement.
 * Loaded on both frontend and admin to capture all password change scenarios.
 */
class MainWP_Child_Password_Tracker {

    /**
     * Single instance of the class.
     *
     * @var MainWP_Child_Password_Tracker|null
     */
    protected static $instance = null;

    /**
     * Get singleton instance.
     *
     * @return MainWP_Child_Password_Tracker
     */
    public static function get_instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Constructor.
     *
     * Registers password change tracking hooks.
     */
    public function __construct() {
        $this->init_hooks();
    }

    /**
     * Initialize WordPress action hooks for password change tracking.
     *
     * Registers hooks to track password changes across all scenarios:
     * - Profile updates (user or admin initiated)
     * - Lost password resets
     * - Programmatic password changes via wp_update_user()
     * - Frontend password changes (e.g., WooCommerce My Account)
     *
     * @since 6.0
     */
    public function init_hooks() {
        add_action( 'profile_update', array( $this, 'callback_track_password_change_on_profile_update' ), 10, 2 );
        add_action( 'password_reset', array( $this, 'callback_track_password_change_on_reset' ), 10, 2 );
        add_action( 'after_password_reset', array( $this, 'callback_track_password_change_on_reset' ), 10, 2 );
    }

    /**
     * Track password changes when user profile is updated.
     *
     * Fires on the 'profile_update' action. Compares old and new password hashes
     * to detect actual password changes and updates the last change timestamp.
     *
     * @since 6.0
     *
     * @param int     $user_id       User ID.
     * @param WP_User $old_user_data Old user data object before update.
     */
    public function callback_track_password_change_on_profile_update( $user_id, $old_user_data ) {
        $new_user_data = get_userdata( $user_id );

        if ( false === $new_user_data ) {
            return;
        }

        if ( $old_user_data->user_pass !== $new_user_data->user_pass ) {
            update_user_meta( $user_id, 'mainwp_last_password_change', time() );
        }
    }

    /**
     * Track password changes during lost password reset flow.
     *
     * Fires on the 'password_reset' action. Updates the last password change
     * timestamp when a user resets their password via the lost password flow.
     *
     * @since 6.0
     *
     * @param WP_User $user     User object.
     */
    public function callback_track_password_change_on_reset( $user ) {
        if ( empty( $user ) || ! $user instanceof \WP_User ) {
            return;
        }

        update_user_meta( $user->ID, 'mainwp_last_password_change', time() );
    }
}
