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
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Method validate_params()
	 *
	 * Handle to valid request params.
	 *
	 * @param string $name Field name.
	 * @param mixed  $default Default value.
	 *
	 * @return mixed value.
	 */
	public function validate_params( $name = '', $default = '' ) {
		$value = $default;
		// phpcs:disable WordPress.Security.NonceVerification
		if ( ! empty( $name ) ) {
			if ( 'showhide' === $name ) {
				$value = isset( $_POST['showhide'] ) && 'hide' === $_POST['showhide'] ? 'hide' : $default;
			} elseif ( 'mwp_action' === $name ) {
				$value = isset( $_POST['mwp_action'] ) ? sanitize_text_field( wp_unslash( $_POST['mwp_action'] ) ) : $default;
			} elseif ( 'action' === $name ) {
				$value = isset( $_POST['action'] ) ? sanitize_text_field( wp_unslash( $_POST['action'] ) ) : $default;
			} elseif ( 'nonce' === $name ) {
				$value = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : $default;
			} elseif ( isset( $_POST[ $name ] ) ) {
				if ( is_string( $_POST[ $name ] ) ) {
					$value = sanitize_text_field( wp_unslash( $_POST[ $name ] ) );
				} else {
					$value = wp_unslash( $_POST[ $name ] );
				}
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification
		return $value;
	}

}
