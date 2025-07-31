<?php
/**
 * Logger: Plugins and Themes changes.
 *
 * @since      5.5
 * @package    mainwp/child
 */

namespace MainWP\Child\Changes;

// Exit.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Changes_Handle_WP_Plugins_Themes
 */
class Changes_Handle_WP_Plugins_Themes {

    /**
     * Inits hooks
     *
     * @return void
     */
    public static function init_hooks() {
        $has_perm = ( current_user_can( 'install_plugins' ) || current_user_can( 'activate_plugins' ) ||
            current_user_can( 'delete_plugins' ) || current_user_can( 'update_plugins' ) || current_user_can( 'install_themes' ) );
        if ( $has_perm ) {
            \add_action( 'shutdown', array( __CLASS__, 'callback_change_wp_shutdown' ) );
        }
    }

    /**
     * Hook WP shutdown.
     */
    public static function callback_change_wp_shutdown() {

        $post_vars   = filter_input_array( INPUT_POST );
        $get_vars    = filter_input_array( INPUT_GET );
        $script_name = isset( $_SERVER['SCRIPT_NAME'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_NAME'] ) ) : false;

        $action = '';
        if ( isset( $get_vars['action'] ) && '-1' !== $get_vars['action'] ) {
            $action = \sanitize_text_field( \wp_unslash( $get_vars['action'] ) );
        } elseif ( isset( $post_vars['action'] ) && '-1' !== $post_vars['action'] ) {
            $action = \sanitize_text_field( \wp_unslash( $post_vars['action'] ) );
        }

        if ( isset( $get_vars['action2'] ) && '-1' !== $get_vars['action2'] ) {
            $action = \sanitize_text_field( \wp_unslash( $get_vars['action2'] ) );
        } elseif ( isset( $post_vars['action2'] ) && '-1' !== $post_vars['action2'] ) {
            $action = \sanitize_text_field( \wp_unslash( $post_vars['action2'] ) );
        }

        $type = '';
        if ( ! empty( $script_name ) ) {
            $type = basename( $script_name, '.php' );
        }
        $is_plugins = 'plugins' === $type;
        // Activate plugin.
        if ( $is_plugins && in_array( $action, array( 'activate', 'activate-selected' ), true ) && current_user_can( 'activate_plugins' ) ) {
            if ( isset( $get_vars['plugin'] ) ) {
                if ( ! isset( $get_vars['checked'] ) ) {
                    $get_vars['checked'] = array();
                }
                $get_vars['checked'][] = \sanitize_text_field( \wp_unslash( $get_vars['plugin'] ) );
            }

            if ( isset( $post_vars['plugin'] ) ) {
                if ( ! isset( $post_vars['checked'] ) ) {
                    $post_vars['checked'] = array();
                }
                $post_vars['checked'][] = \sanitize_text_field( \wp_unslash( $post_vars['plugin'] ) );
            }

            if ( isset( $get_vars['checked'] ) && ! empty( $get_vars['checked'] ) ) {
                foreach ( $get_vars['checked'] as $plugin_file ) {
                    if ( ! is_wp_error( validate_plugin( $plugin_file ) ) ) {
                        $plugin_file = WP_PLUGIN_DIR . '/' . $plugin_file;
                        $plugin_data = \get_plugin_data( $plugin_file, false, true );
                        $log_data    = array(
                            'pluginfile' => $plugin_file,
                            'plugindata' => (object) array(
                                'name'      => $plugin_data['Name'],
                                'pluginuri' => $plugin_data['PluginURI'],
                                'version'   => $plugin_data['Version'],
                                'author'    => $plugin_data['Author'],
                            ),
                        );
                        Changes_Logs_Logger::log_change( 1461, $log_data );
                    }
                }
            } elseif ( isset( $post_vars['checked'] ) && ! empty( $post_vars['checked'] ) ) {
                foreach ( $post_vars['checked'] as $plugin_file ) {
                    if ( ! is_wp_error( validate_plugin( $plugin_file ) ) ) {
                        $plugin_file = WP_PLUGIN_DIR . '/' . $plugin_file;
                        $plugin_data = get_plugin_data( $plugin_file, false, true );
                        $log_data    = array(
                            'pluginfile' => $plugin_file,
                            'plugindata' => (object) array(
                                'name'      => $plugin_data['Name'],
                                'pluginuri' => $plugin_data['PluginURI'],
                                'version'   => $plugin_data['Version'],
                                'author'    => $plugin_data['Author'],
                            ),
                        );
                        Changes_Logs_Logger::log_change( 1461, $log_data );
                    }
                }
            }
        }
    }
}
