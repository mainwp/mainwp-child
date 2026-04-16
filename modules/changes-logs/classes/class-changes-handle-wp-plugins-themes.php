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
     * Store Themes.
     *
     * @var array
     */
    private static $current_themes = null;

    /**
     * Store Plugins.
     *
     * @var array
     *
     * @since 6.0.7
     */
    private static $current_plugins = null;

    /**
     * Inits hooks
     *
     * @return void
     */
    public static function init_hooks() {
        \add_action( 'shutdown', array( __CLASS__, 'callback_change_wp_shutdown' ) );
        \add_action( 'upgrader_pre_install', array( __CLASS__, 'init_before' ) );
        // Fires after the upgrades done.
        \add_action( 'upgrader_process_complete', array( __CLASS__, 'callback_change_upgrader_complete' ), 10, 2 );
    }

    /**
     * Hook WP shutdown.
     */
    public static function callback_change_wp_shutdown() { //phpcs:ignore --NOSONAR -complex.

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
                foreach ( $get_vars['checked'] as $plugin ) {
                    if ( ! is_wp_error( validate_plugin( $plugin ) ) ) {
                        $plugin_file = WP_PLUGIN_DIR . '/' . $plugin;
                        $plugin_data = \get_plugin_data( $plugin_file, false, true );
                        $log_data    = array(
                            'slug'       => $plugin,
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
                foreach ( $post_vars['checked'] as $plugin ) {
                    if ( ! is_wp_error( validate_plugin( $plugin ) ) ) {
                        $plugin_file = WP_PLUGIN_DIR . '/' . $plugin;
                        $plugin_data = get_plugin_data( $plugin_file, false, true );
                        $log_data    = array(
                            'slug'       => $plugin,
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

    /**
     * Triggered when a user accesses the admin area.
     */
    public static function init_before() {

        if ( ! function_exists( '\get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php'; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.FileIncludeFound -- NOSONAR - ok.
        }

        self::get_current_themes();
        self::get_current_plugins();
    }


    /**
     * Get current themes BEFORE themes changes.
     *
     * @return array
     */
    private static function get_current_themes(): array {
        if ( null === self::$current_themes ) {
            self::$current_themes = \wp_get_themes();
        }

        return self::$current_themes;
    }

    /**
     * Get current plugins before plugins changes.
     *
     * @return array
     */
    private static function get_current_plugins(): array {
        if ( null === self::$current_plugins ) {
            self::$current_plugins = \get_plugins();
        }

        return self::$current_plugins;
    }


    /**
     * Fires when plugins, themes are updated or installed
     *
     * @param Mixed $upgrader_instance Plugin_Upgrader instance, or Theme_Upgrader, Core_Upgrade instance.
     * @param array $opt_data Array of item update data.
     *
     * @return void
     */
    public static function callback_change_upgrader_complete( $upgrader_instance, $opt_data ): void {

        if ( empty( $upgrader_instance ) || empty( $opt_data ) ) {
            return;
        }

        // Ignore if changes installed events triggered before.
        if ( Changes_Logs_Logger::is_handled_logs_type( 1965 ) || Changes_Logs_Logger::is_handled_logs_type( 1975 ) ) {
            return;
        }

        if ( empty( $opt_data['type'] ) || empty( $opt_data['action'] ) ) {
            return;
        }

        if ( isset( $opt_data['type'] ) ) {
            if ( 'plugin' === $opt_data['type'] ) {
                self::change_plugin_install( $upgrader_instance, $opt_data );
                self::change_plugin_update( $upgrader_instance, $opt_data );
                self::change_bulk_plugin_update( $upgrader_instance, $opt_data );
            } elseif ( 'theme' === $opt_data['type'] ) {
                self::change_theme_install( $upgrader_instance, $opt_data );
                self::change_theme_upgrade( $upgrader_instance, $opt_data );
            }
        }
    }

    /**
     * Handle event when theme is installed
     *
     * @param mixed $upgrader_instance Plugin_Upgrader instance or Theme_Upgrader, Core_Upgrade instance.
     * @param array $opt_data Array of bulk item update data.
     *
     * @return void
     */
    public static function change_theme_install( $upgrader_instance, $opt_data ): void {

        // Must be type 'theme' and action 'install'.
        if ( 'theme' !== $opt_data['type'] || 'install' !== $opt_data['action'] ) {
            return;
        }

        if ( empty( $upgrader_instance->new_theme_data ) ) {
            return;
        }

        // Install theme.
        $destination_name = $upgrader_instance->result['destination_name'] ?? '';

        if ( empty( $destination_name ) ) {
            return;
        }

        $theme = \wp_get_theme( $destination_name );

        if ( ! $theme->exists() ) {
            return;
        }

        $new_theme_data = $upgrader_instance->new_theme_data;

        Changes_Logs_Logger::log_change(
            1975,
            array(
                'theme' => (object) array(
                    'name'        => $new_theme_data['Name'],
                    'themeuri'    => $theme->ThemeURI, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
                    'description' => $theme->get( 'Description' ),
                    'author'      => $new_theme_data['Author'],
                    'version'     => $new_theme_data['Version'],
                ),
            )
        );
    }

    /**
     * Handle event when theme is upgraded
     *
     * @param mixed $upgrader_instance Plugin_Upgrader instance or Theme_Upgrader, Core_Upgrade instance.
     * @param array $opt_data Array of update data.
     *
     * @return void
     */
    public static function change_theme_upgrade( $upgrader_instance, $opt_data ): void { //phpcs:ignore --NOSONAR -complex.

        // Must be 'theme' and 'update'.
        if ( 'theme' !== $opt_data['type'] || 'update' !== $opt_data['action'] ) {
            return;
        }

        if ( isset( $opt_data['bulk'] ) && $opt_data['bulk'] && isset( $opt_data['themes'] ) ) {
            $arr_themes = (array) $opt_data['themes'];
        } else {
            $arr_themes = array(
                $opt_data['theme'],
            );
        }

        $auto_updated = \is_object( $upgrader_instance ) && \property_exists( $upgrader_instance->skin, 'skin' ) && \is_a( $upgrader_instance->skin, 'Automatic_Upgrader_Skin' ) ? 1 : 0;

        $old_themes = self::get_current_themes();

        if ( ! is_array( $old_themes ) ) {
            $old_themes = array();
        }
        foreach ( $arr_themes as $one_updated_theme ) {
            $theme = \wp_get_theme( $one_updated_theme );

            if ( ! is_a( $theme, 'WP_Theme' ) ) {
                continue;
            }

            $old_theme = isset( $old_themes[ $one_updated_theme ] ) ? $old_themes[ $one_updated_theme ] : false;

            $old_version = '';
            if ( ! empty( $old_theme ) && $old_theme instanceof \WP_Theme ) {
                $old_version = $old_theme->get( 'Version' );
            }

            $theme_name    = $theme->get( 'Name' );
            $theme_version = $theme->get( 'Version' );

            if ( ! $theme_name || ! $theme_version || $theme_version === $old_version ) {
                continue;
            }

            Changes_Logs_Logger::log_change(
                1980,
                array(
                    'theme'        => (object) array(
                        'name'        => $theme_name,
                        'themeuri'    => $theme->ThemeURI, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
                        'description' => $theme->Description, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
                        'author'      => $theme->Author, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
                        'version'     => $theme_version,
                    ),
                    'auto_updated' => $auto_updated,
                    'oldversion'   => $old_version,
                )
            );
        }
    }

    /**
     * Handle event when plugin is installed
     *
     * @param mixed $plugin_upgrader_instance Plugin_Upgrader instance or Theme_Upgrader, Core_Upgrade instance.
     * @param array $opt_data Array update data.
     *
     * @return void
     */
    public static function change_plugin_install( $plugin_upgrader_instance, $opt_data ): void {

        // return if not plugin install.
        if ( ! isset( $opt_data['action'] ) || 'install' !== $opt_data['action'] || $plugin_upgrader_instance->bulk ) {
            return;
        }

        $upgrader_skin_options = isset( $plugin_upgrader_instance->skin->options ) && is_array( $plugin_upgrader_instance->skin->options ) ? $plugin_upgrader_instance->skin->options : array();
        $upgrader_skin_result  = isset( $plugin_upgrader_instance->skin->result ) && is_array( $plugin_upgrader_instance->skin->result ) ? $plugin_upgrader_instance->skin->result : array();
        $new_plugin_data       = $plugin_upgrader_instance->new_plugin_data ?? array();
        $plugin_slug           = $upgrader_skin_result['destination_name'] ?? '';

        $plugin = $plugin_upgrader_instance->plugin_info();

        $context = array(
            'plugin_slug'         => $plugin_slug,
            'plugin_name'         => $new_plugin_data['Name'] ?? '',
            'plugin_title'        => $new_plugin_data['Title'] ?? '',
            'plugin_url'          => $new_plugin_data['PluginURI'] ?? '',
            'plugin_version'      => $new_plugin_data['Version'] ?? '',
            'plugin_author'       => $new_plugin_data['Author'] ?? '',
            'plugin_requires_wp'  => $new_plugin_data['RequiresWP'] ?? '',
            'plugin_requires_php' => $new_plugin_data['RequiresPHP'] ?? '',
            'plugin_network'      => ( $new_plugin_data['Network'] ?? false ) ? \esc_html__( 'True', 'mainwp' ) : \esc_html__( 'False', 'mainwp' ),
        );

        if ( isset( $new_plugin_data['UpdateURI'] ) ) {
            $context['plugin_update_uri'] = $new_plugin_data['UpdateURI'];
        }

        $install_source = 'web';
        if ( isset( $upgrader_skin_options['type'] ) ) {
            $install_source = \strtolower( (string) $upgrader_skin_options['type'] );
        }

        $context['plugin_install_source'] = $install_source;

        // If uploaded plugin store name of ZIP.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( 'upload' === $install_source && isset( $_FILES['pluginzip']['name'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $plugin_upload_name            = sanitize_text_field( $_FILES['pluginzip']['name'] );
            $context['plugin_upload_name'] = $plugin_upload_name;
        }

        if ( ! is_a( $plugin_upgrader_instance->skin->result, 'WP_Error' ) ) {

            // Check if the plugin is already installed and we are here because of an update via upload.
            $current_plugins = self::get_current_plugins();

            if ( isset( $current_plugins[ $plugin ] ) ) {
                return;
            }

            Changes_Logs_Logger::log_change(
                1965,
                array(
                    'plugindata' => (object) array(
                        'name'      => $context['plugin_name'],
                        'pluginuri' => $context['plugin_url'],
                        'version'   => $context['plugin_version'],
                        'author'    => $context['plugin_author'],
                        'network'   => $context['plugin_network'],
                        'slug'      => $context['plugin_slug'],
                        'title'     => $context['plugin_title'],
                    ),
                )
            );
        }
    }

    /**
     * Logs event when single plugin is updated
     *
     * @param mixed $plugin_upgrader_instance Plugin_Upgrader instance or Theme_Upgrader, Core_Upgrade instance.
     * @param array $opt_data Array of bulk item update data.
     *
     * @return void
     */
    public static function change_plugin_update( $plugin_upgrader_instance, $opt_data ): void {
        if ( ! isset( $opt_data['action'] ) || 'update' !== $opt_data['action'] || $plugin_upgrader_instance->bulk ) {
            return;
        }

        // No plugin info in instance, so get it ourself.
        $plugin_data = array();
        if ( file_exists( \WP_PLUGIN_DIR . '/' . $opt_data['plugin'] ) ) {
            $plugin_data = \get_plugin_data( \WP_PLUGIN_DIR . '/' . $opt_data['plugin'], true, false );
        }

        $plugin_slug = dirname( $opt_data['plugin'] );

        $context = array(
            'plugin_slug'        => $plugin_slug,
            'plugin_name'        => $plugin_data['Name'] ?? '',
            'plugin_title'       => $plugin_data['Title'] ?? '',
            'plugin_description' => $plugin_data['Description'] ?? '',
            'plugin_author'      => $plugin_data['Author'] ?? '',
            'plugin_version'     => $plugin_data['Version'] ?? '',
            'plugin_url'         => $plugin_data['PluginURI'] ?? '',
            'plugin_network'     => ( $plugin_data['Network'] ?? false ) ? \esc_html__( 'True', 'mainwp' ) : \esc_html__( 'False', 'mainwp' ),
        );

        // If it is set.
        if ( isset( $plugin_data['UpdateURI'] ) ) {
            $context['plugin_update_uri'] = $plugin_data['UpdateURI'];
        }

        if ( ! \is_wp_error( \validate_plugin( $opt_data['plugin'] ) ) ) {
            $current_plugins = self::get_current_plugins();
            $auto_updated    = \is_object( $plugin_upgrader_instance ) && \property_exists( $plugin_upgrader_instance->skin, 'skin' ) && \is_a( $plugin_upgrader_instance->skin, 'Automatic_Upgrader_Skin' ) ? 1 : 0;

            $current_version = ( isset( $current_plugins[ $opt_data['plugin'] ] ) ) ? $current_plugins[ $opt_data['plugin'] ]['Version'] : false;

            if ( $current_version !== $context['plugin_version'] ) {
                Changes_Logs_Logger::log_change(
                    1970,
                    array(
                        'plugindata'   => (object) array(
                            'name'      => $context['plugin_name'],
                            'pluginuri' => $context['plugin_url'],
                            'version'   => $context['plugin_version'],
                            'author'    => $context['plugin_author'],
                            'network'   => $context['plugin_network'],
                            'slug'      => $context['plugin_slug'],
                            'title'     => $context['plugin_title'],
                        ),
                        'oldversion'   => $current_version,
                        'auto_updated' => $auto_updated,
                    )
                );
            }
        }
    }

    /**
     * Capture event when single plugin is updated
     *
     * @param mixed $plugin_upgrader_instance Plugin_Upgrader instance or Theme_Upgrader, Core_Upgrade instance.
     * @param array $opt_data                 Array of bulk item update data.
     *
     * @return void
     */
    public static function change_bulk_plugin_update( $plugin_upgrader_instance, $opt_data ): void {

        if ( ! isset( $opt_data['bulk'] ) || ! $opt_data['bulk'] || ! isset( $opt_data['action'] ) || 'update' !== $opt_data['action'] ) {
            return;
        }

        $plugins_updated = isset( $opt_data['plugins'] ) ? (array) $opt_data['plugins'] : array();

        foreach ( $plugins_updated as $plugin ) {
            $opt_data['plugin'] = $plugin;

            $plugin_data = array();
            if ( file_exists( \WP_PLUGIN_DIR . '/' . $opt_data['plugin'] ) ) {
                $plugin_data = \get_plugin_data( \WP_PLUGIN_DIR . '/' . $opt_data['plugin'], true, false );
            }

            $plugin_slug = dirname( $opt_data['plugin'] );

            $context = array(
                'plugin_slug'        => $plugin_slug,
                'plugin_name'        => $plugin_data['Name'] ?? '',
                'plugin_title'       => $plugin_data['Title'] ?? '',
                'plugin_description' => $plugin_data['Description'] ?? '',
                'plugin_author'      => $plugin_data['Author'] ?? '',
                'plugin_version'     => $plugin_data['Version'] ?? '',
                'plugin_url'         => $plugin_data['PluginURI'] ?? '',
                'plugin_network'     => ( $plugin_data['Network'] ?? false ) ? \esc_html__( 'True', 'mainwp' ) : \esc_html__( 'False', 'mainwp' ),
            );

            if ( isset( $plugin_data['UpdateURI'] ) ) {
                $context['plugin_update_uri'] = $plugin_data['UpdateURI'];
            }

            if ( ! \is_wp_error( \validate_plugin( $opt_data['plugin'], ) ) ) {
                $current_plugins = self::get_current_plugins();

                $auto_updated = \is_object( $plugin_upgrader_instance ) && \property_exists( $plugin_upgrader_instance->skin, 'skin' ) && \is_a( $plugin_upgrader_instance->skin, 'Automatic_Upgrader_Skin' ) ? 1 : 0;

                $current_version = ( isset( $current_plugins[ $opt_data['plugin'] ] ) ) ? $current_plugins[ $opt_data['plugin'] ]['Version'] : false;

                if ( $current_version !== $context['plugin_version'] ) {
                    Changes_Logs_Logger::log_change(
                        1970,
                        array(
                            'plugindata'   => (object) array(
                                'name'      => $context['plugin_name'],
                                'pluginuri' => $context['plugin_url'],
                                'version'   => $context['plugin_version'],
                                'author'    => $context['plugin_author'],
                                'network'   => $context['plugin_network'],
                                'slug'      => $context['plugin_slug'],
                                'title'     => $context['plugin_title'],
                            ),
                            'oldversion'   => $current_version,
                            'auto_updated' => $auto_updated,
                        )
                    );
                }
            }
        }
    }
}
