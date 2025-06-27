<?php
/**
 * MainWP WP SEOPress
 *
 * MainWP WP SEOPress Extension handler.
 *
 * @link https://mainwp.com/extension/wp-seopress/
 *
 * @package MainWP\Child
 *
 * Credits
 *
 * Plugin-Name: SEOPress
 * Plugin URI: https://seopress.org?utm_source=wpadmin&utm_medium=plugin&utm_campaign=wpseopressplugin
 * Author: SEOPress
 * Author URI: https://seopress.com/
 * Licence: GPL v3
 */

namespace MainWP\Child;

// phpcs:disable PSR1.Classes.ClassDeclaration, WordPress.WP.AlternativeFunctions -- Required to achieve desired results. Pull requests appreciated.

/**
 * Class MainWP_Child_WP_Seopress
 *
 * MainWP WP Seopress Extension handler.
 */
class MainWP_Child_WP_Seopress {

    /**
     * Public static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    public static $instance = null;

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
     * Fire off certain SEOPRESS plugin actions.
     *
     * @uses MainWP_Helper::write() Write response data to be sent to the MainWP Dashboard.
     */
    public function action() {
        if ( ! in_array( 'wp-seopress/seopress.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ), true ) ) {
            $information['error'] = 'NO_SEOPRESS';
            MainWP_Helper::write( $information );
        }

        $mwp_action = MainWP_System::instance()->validate_params( 'action' );
        if ( ! empty( $mwp_action ) && method_exists( $this, $mwp_action ) ) {
            $information = $this->{$mwp_action}();
            MainWP_Helper::write( $information );
        }
    }

    /**
     * Check if Pro Version is active
     *
     * @return boolean
     */
    protected function is_seopress_pro_version_active() {
        return in_array( 'wp-seopress-pro/seopress-pro.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ), true );
    }

    /**
     * Import the SEOPRESS plugin settings.
     *
     * @uses MainWP_Helper::write() Write response data to be sent to the MainWP Dashboard.
     *
     * @used-by MainWP_Child_WP_Seopress::action() Fire off certain SEOPRESS plugin actions.
     *
     * @throws MainWP_Exception Error message.
     */
    public function export_settings() {
        if ( ! function_exists( 'seopress_return_settings' ) ) {
            $information['error'] = esc_html__( 'Settings could not be exported. Missing function `seopress_return_settings`', 'mainwp-child' );
            return $information;
        }

        $settings = seopress_return_settings();

        $information['settings'] = $settings;
        $information['message']  = esc_html__( 'Export completed', 'mainwp-child' );

        return $information;
    }

    /**
     * Import the SEOPRESS plugin settings.
     *
     * @used-by MainWP_Child_WP_Seopress::action() Fire off certain SEOPRESS plugin actions.
     */
    public function import_settings() {
        // phpcs:disable WordPress.Security.NonceVerification
        if ( isset( $_POST['settings'] ) ) {
            if ( ! function_exists( 'seopress_do_import_settings' ) ) {
                $information['error'] = esc_html__( 'Settings could not be imported. Missing function `seopress_do_import_settings`', 'mainwp-child' );
                return $information;
            }

            $settings = json_decode( stripslashes( wp_unslash( $_POST['settings'] ) ), true );  //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

            seopress_do_import_settings( $settings );

            $information['message'] = esc_html__( 'Import completed', 'mainwp-child' );

            return $information;
        }
        // phpcs:enable
    }

    /**
     * Sync settings
     *
     * @return array
     */
    public function sync_settings() { // phpcs:ignore -- NOSONAR - multi return.
        if ( ! function_exists( 'seopress_mainwp_save_settings' ) ) {
            $information['error'] = esc_html__( 'Settings could not be saved. Missing function `seopress_mainwp_save_settings`', 'mainwp-child' );
            return $information;
        }
        // phpcs:disable WordPress.Security.NonceVerification
        if ( isset( $_POST['settings'] ) ) {
            $settings = wp_unslash( $_POST['settings'] ) ?? array();  //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $option   = isset( $_POST['option'] ) ? sanitize_text_field( wp_unslash( $_POST['option'] ) ) : '';
            // phpcs:enable
            if ( empty( $option ) ) {
                $information['error'] = esc_html__( 'Settings could not be saved. Missing option name.', 'mainwp-child' );
                return $information;
            }

            if ( 'seopress_pro_option_name' === $option && ! $this->is_seopress_pro_version_active() ) {
                $information['error'] = esc_html__( 'SEOPress Pro plugin is not active on child site.', 'mainwp-child' );
                return $information;
            }

            if ( ! empty( $settings ) ) {
                $settings = $this->sanitize_options( $settings );
            }

            seopress_mainwp_save_settings( $settings, $option );

            $information['message'] = esc_html__( 'Save successful', 'mainwp-child' );

            return $information;
        }
    }

    /**
     * Save pro licence
     *
     * @used-by MainWP_Child_WP_Seopress::action() Fire off certain SEOPRESS plugin actions.
     *
     * @return array
     */
    public function save_pro_licence() {
        if ( ! $this->is_seopress_pro_version_active() ) {
            $information['error'] = esc_html__( 'SEOPress Pro plugin is not active on child site.', 'mainwp-child' );
            return $information;
        }

        if ( ! function_exists( 'seopress_save_pro_licence' ) ) {
            $information['error'] = esc_html__( 'Settings could not be saved. Missing function `seopress_save_pro_licence`', 'mainwp-child' );
            return $information;
        }

        // phpcs:disable WordPress.Security.NonceVerification
        $licence = isset( $_POST['licence'] ) ? wp_unslash( $_POST['licence'] ) : array();  //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        // phpcs:enable

        $licence = $this->sanitize_options( $licence );

        $response = seopress_save_pro_licence( $licence );

        if ( ! is_wp_error( $response ) ) {
            $information['message'] = esc_html__( 'Save successful', 'mainwp-child' );
        } else {
            $information['error'] = $response->get_error_message();
        }

        return $information;
    }

    /**
     * Reset pro licence
     *
     * @used-by MainWP_Child_WP_Seopress::action() Fire off certain SEOPRESS plugin actions.
     *
     * @return array
     */
    public function reset_pro_licence() {
        if ( ! $this->is_seopress_pro_version_active() ) {
            $information['error'] = esc_html__( 'SEOPress Pro plugin is not active on child site.', 'mainwp-child' );
            return $information;
        }

        if ( ! function_exists( 'seopress_reset_pro_licence' ) ) {
            $information['error'] = esc_html__( 'Licence could not be reset. Missing function `seopress_reset_pro_licence`', 'mainwp-child' );
            return $information;
        }

        seopress_reset_pro_licence( $licence );

        $information['message'] = esc_html__( 'Reset successful', 'mainwp-child' );

        return $information;
    }

    /**
     * Flush rewrite rules
     *
     * @used-by MainWP_Child_WP_Seopress::action() Fire off certain SEOPRESS plugin actions.
     *
     * @return array
     */
    public function flush_rewrite_rules() {
        if ( ! function_exists( 'seopress_flush_rewrite_rules' ) ) {
            $information['error'] = esc_html__( 'Action could not be executed. Missing function `seopress_flush_rewrite_rules`', 'mainwp-child' );
            return $information;
        }

        seopress_flush_rewrite_rules();

        $information['message'] = esc_html__( 'Save successful', 'mainwp-child' );

        return $information;
    }

    /**
     * Sanitize the fields before saving
     *
     * @param mixed<string|array> $option The option to be sanitized.
     *
     * @return  array
     */
    private function sanitize_options( $option ) {
        if ( is_array( $option ) ) {
            foreach ( $option as $field => $value ) {
                if ( is_numeric( $value ) ) {
                    $option[ $field ] = $value;
                } elseif ( is_array( $value ) ) {
                        $option[ $field ] = $this->sanitize_options( $value );
                } elseif ( 'seopress_robots_file' === $field || 'seopress_instant_indexing_google_api_key' === $field ) {
                        $option[ $field ] = wp_kses_post( wp_unslash( $value ) );
                } else {
                    $option[ $field ] = wp_unslash( $value );
                }
            }
        }

        return $option;
    }
}
