<?php
/**
 * Logger: Files
 *
 * Files logger class file.
 *
 * @since 5.5
 *
 * @package MainWP\Child
 */

namespace MainWP\Child\Changes;

// Exit.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Files logger.
 */
class Changes_Handle_WP_Files {

    /**
     * File uploaded.
     *
     * @var boolean
     */
    protected static $is_file_uploaded = false;

    /**
     * Inits the main hooks
     *
     * @return void
     */
    public static function init_hooks() {
        add_action( 'add_attachment', array( __CLASS__, 'callback_change_file_uploaded' ) );
        add_action( 'admin_init', array( __CLASS__, 'callback_change_admin_init' ) );
        add_action( 'delete_attachment', array( __CLASS__, 'callback_change_file_uploaded_deleted' ) );
    }

    /**
     * File uploaded.
     *
     * @param integer $attachment_id - Attachment ID.
     */
    public static function callback_change_file_uploaded( $attachment_id ) {
        // Filter $_POST array.
        $post_vars = filter_input_array( INPUT_POST );

        $action = isset( $post_vars['action'] ) ? \sanitize_text_field( \wp_unslash( $post_vars['action'] ) ) : '';
        if ( 'upload-theme' !== $action && 'upload-plugin' !== $action ) {
            $file     = get_attached_file( $attachment_id );
            $log_data = array(
                'attachmentid'  => $attachment_id,
                'filename'      => basename( $file ),
                'filepath'      => dirname( $file ),
                'attachmenturl' => wp_get_attachment_url( $attachment_id ),
            );
            Changes_Logs_Logger::log_change( 1420, $log_data );
        }

        self::$is_file_uploaded = true;
    }

    /**
     * Deleted file from uploads directory.
     *
     * @param integer $attachment_id - Attachment ID.
     */
    public static function callback_change_file_uploaded_deleted( $attachment_id ) {
        if ( self::$is_file_uploaded ) {
            return;
        }
        $file = get_attached_file( $attachment_id );

        if ( false !== strpos( $file, 'mainwp-child' ) ) {
            return;
        }

        $log_data = array(
            'attachmentid' => $attachment_id,
            'filename'     => basename( $file ),
            'filepath'     => dirname( $file ),
        );
        Changes_Logs_Logger::log_change( 1425, $log_data );
    }

    /**
     * File Changes Event.
     *
     * Detect file changes in plugins/themes using plugin/theme editor.
     */
    public static function callback_change_admin_init() {
        $nonce   = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : false;
        $file    = isset( $_POST['file'] ) ? sanitize_text_field( wp_unslash( $_POST['file'] ) ) : false;
        $action  = isset( $_POST['action'] ) ? sanitize_text_field( wp_unslash( $_POST['action'] ) ) : false;
        $referer = isset( $_POST['_wp_http_referer'] ) ? sanitize_text_field( wp_unslash( $_POST['_wp_http_referer'] ) ) : false;
        $referer = remove_query_arg( array( 'file', 'theme', 'plugin' ), $referer );
        $referer = basename( $referer, '.php' );

        if ( 'edit-theme-plugin-file' === $action ) {
            if ( 'plugin-editor' === $referer && wp_verify_nonce( $nonce, 'edit-plugin_' . $file ) ) {
                $plugin   = isset( $_POST['plugin'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin'] ) ) : false;
                $log_data = array(
                    'file'   => $file,
                    'plugin' => $plugin,
                );

                $plugin_file = '';

                if ( file_exists( $file ) ) { //phpcs:ignore -- ok.
                    $plugin_file = $file;
                } elseif( file_exists( WP_PLUGIN_DIR . '/' . $plugin ) ){ //phpcs:ignore -- ok.
                    $plugin_file = WP_PLUGIN_DIR . '/' . $plugin;
                }

                if ( ! empty( $plugin_file ) ) {
                    $plugin_data = get_plugin_data( $plugin_file );
                    if ( is_array( $plugin_data ) && ! empty( $plugin_data['Name'] ) ) {
                        $log_data['name'] = $plugin_data['Name'];
                    }
                }

                Changes_Logs_Logger::log_change( 1455, $log_data );
            } elseif ( 'theme-editor' === $referer ) {
                $stylesheet = isset( $_POST['theme'] ) ? sanitize_text_field( wp_unslash( $_POST['theme'] ) ) : false;

                if ( ! wp_verify_nonce( $nonce, 'edit-theme_' . $stylesheet . '_' . $file ) ) {
                    return;
                }

                $log_data = array(
                    'file'  => $file,
                    'theme' => trailingslashit( get_theme_root() ) . $stylesheet,
                );
                Changes_Logs_Logger::log_change( 1465, $log_data );
            }
        }
    }
}
