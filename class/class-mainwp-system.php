<?php
/**
 * MainWP System
 *
 * @package MainWP/Child
 */

namespace MainWP\Child;

/**
 * Class MainWP_System
 *
 * MainWP System
 */
class MainWP_System {

    /**
     * Public static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    public static $instance = null;


    /**
     * Method get_class_name()
     *
     * Get class name.
     *
     * @return string __CLASS__ Class name.
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * Method instance()
     *
     * Create a public static instance.
     *
     * @return mixed Class instance.
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Method validate_params()
     *
     * Handle to valid request params.
     *
     * @param string $name Field name.
     * @param mixed  $def_value Default value.
     *
     * @return mixed value.
     */
    public function validate_params( $name = '', $def_value = '' ) { //phpcs:ignore -- NOSONAR - complex.
        $value = $def_value;
        // phpcs:disable WordPress.Security.NonceVerification
        if ( ! empty( $name ) ) {
            if ( 'showhide' === $name ) {
                $value = isset( $_POST['showhide'] ) && 'hide' === $_POST['showhide'] ? 'hide' : $def_value;
            } elseif ( 'mwp_action' === $name ) {
                $value = isset( $_POST['mwp_action'] ) ? sanitize_text_field( wp_unslash( $_POST['mwp_action'] ) ) : $def_value;
            } elseif ( 'action' === $name ) {
                $value = isset( $_POST['action'] ) ? sanitize_text_field( wp_unslash( $_POST['action'] ) ) : $def_value;
            } elseif ( 'nonce' === $name ) {
                $value = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : $def_value;
            } elseif ( isset( $_POST[ $name ] ) ) {
                if ( is_string( $_POST[ $name ] ) ) {
                    $value = sanitize_text_field( wp_unslash( $_POST[ $name ] ) );
                } else {
                    $value = wp_unslash( $_POST[ $name ] ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize in next process.
                }
            }
        }
        // phpcs:enable
        return $value;
    }

    /**
     * Handle wp version check.
     */
    public static function wp_mainwp_version_check() {
        add_filter( 'automatic_updater_disabled', '__return_true' ); // to prevent auto update on this version check.
        remove_action( 'wp_maybe_auto_update', 'wp_maybe_auto_update' );
        wp_version_check();
    }
}
