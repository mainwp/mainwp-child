<?php
/**
 * MainWP Child Install
 *
 * This file handles Plugins and Themes Activate, Deactivate and Delete process.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

/**
 * Class MainWP_Child_Install
 *
 * Handles Plugins and Themes Activate, Deactivate and Delete process.
 */
class MainWP_Child_Install {

    /**
     * Public static variable to hold the single instance of MainWP_Child_Install.
     *
     * @var mixed Default null
     */
    protected static $instance = null;

    /**
     * Get class name.
     *
     * @return string __CLASS__ Class name.
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * MainWP_Child_Install constructor
     *
     * Run any time class is called.
     */
    public function __construct() {
    }

    /**
     * Create a public static instance of MainWP_Child_Install.
     *
     * @return MainWP_Child_Install|mixed|null
     */
    public static function get_instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }

        return static::$instance;
    }

    /**
     * Method plugin_action()
     *
     * Plugin Activate, Deactivate & Delete actions.
     *
     * @uses get_plugin_data() Parses the plugin contents to retrieve plugin’s metadata.
     * @see https://developer.wordpress.org/reference/functions/get_plugin_data/
     *
     * @uses activate_plugin() Attempts activation of plugin in a “sandbox” and redirects on success.
     * @see https://developer.wordpress.org/reference/functions/activate_plugin/
     *
     * @uses deactivate_plugin() Deactivate a single plugin or multiple plugins.
     * @see https://developer.wordpress.org/reference/functions/deactivate_plugin/
     *
     * @uses \MainWP\Child\MainWP_Child_Install::delete_plugins() Delete a plugin from the Child Site.
     * @uses \MainWP\Child\MainWP_Child_Stats::get_site_stats()
     * @uses \MainWP\Child\MainWP_Helper::write()
     */
    public function plugin_action() { //phpcs:ignore -- NOSONAR - complex.

        /**
         * MainWP Child instance.
         *
         * @global object
         */
        global $mainWPChild;
        // phpcs:disable WordPress.Security.NonceVerification
        $action  = MainWP_System::instance()->validate_params( 'action' );
        $plugins = isset( $_POST['plugin'] ) ? explode( '||', sanitize_text_field( wp_unslash( $_POST['plugin'] ) ) ) : '';

        $action_items = array();

        include_once ABSPATH . '/wp-admin/includes/plugin.php'; // NOSONAR -- WP compatible.
        include_once ABSPATH . 'wp-admin/includes/file.php'; // NOSONAR -- WP compatible get_home_path().
        include_once ABSPATH . 'wp-admin/includes/misc.php'; // NOSONAR -- WP compatible extract_from_markers().

        if ( 'activate' === $action ) {
            foreach ( $plugins as $plugin ) {
                if ( $plugin !== $mainWPChild->plugin_slug ) {
                    $thePlugin = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin );
                    if ( null !== $thePlugin && '' !== $thePlugin ) {
                        if ( 'quotes-collection/quotes-collection.php' === $plugin ) {
                            activate_plugin( $plugin, '', false, true );
                        } else {
                            activate_plugin( $plugin );
                        }
                        $action_items[ $plugin ] = array(
                            'name'    => $thePlugin['Name'],
                            'version' => $thePlugin['Version'],
                            'slug'    => $plugin,
                        );
                    }
                }
            }
        } elseif ( 'deactivate' === $action ) {
            include_once ABSPATH . '/wp-admin/includes/plugin.php'; // NOSONAR -- WP compatible.

            foreach ( $plugins as $plugin ) {
                if ( $plugin !== $mainWPChild->plugin_slug ) {
                    $thePlugin = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin );
                    if ( null !== $thePlugin && '' !== $thePlugin ) {
                        deactivate_plugins( $plugin );
                        $action_items[ $plugin ] = array(
                            'name'    => $thePlugin['Name'],
                            'version' => $thePlugin['Version'],
                            'slug'    => $plugin,
                        );
                    }
                }
            }
        } elseif ( 'delete' === $action ) {
            $this->delete_plugins( $plugins, $output );
            if ( ! empty( $output ) ) {
                $action_items = $output;
            }
        } elseif ( 'changelog_info' === $action ) {
            include_once ABSPATH . '/wp-admin/includes/plugin-install.php'; // NOSONAR -- WP compatible.
            $_slug                 = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';
            $api                   = plugins_api(
                'plugin_information',
                array(
                    'slug' => $_slug,
                )
            );
            $information['update'] = $api;
        } else {
            $information['status'] = 'FAIL';
        }
        // phpcs:enable

        if ( ! isset( $information['status'] ) ) {
            $information['status'] = 'SUCCESS';
        }
        if ( 'changelog_info' !== $action ) {
            $information['sync'] = MainWP_Child_Stats::get_instance()->get_site_stats( array(), false );
        }
        $information['other_data'] = array(
            'plugin_action_data' => $action_items,
        );
        MainWP_Helper::write( $information );
    }

    /**
     * Method delete_plugins()
     *
     * Delete a plugin from the Child Site.
     *
     * @param array $plugins An array of plugins to delete.
     * @param array $output An array output data.
     *
     * @uses get_plugin_data() Parses the plugin contents to retrieve plugin’s metadata.
     * @see https://developer.wordpress.org/reference/functions/get_plugin_data/
     *
     * @uses deactivate_plugin() Deactivate a single plugin or multiple plugins.
     * @see https://developer.wordpress.org/reference/functions/deactivate_plugin/
     *
     * @uses is_plugin_active() Determines whether a plugin is active.
     * @see https://developer.wordpress.org/reference/functions/is_plugin_active/
     *
     * @uses \MainWP\Child\MainWP_Helper::check_wp_filesystem()
     *
     * @used-by \MainWP\Child\MainWP_Child_Install::plugin_action() Plugin Activate, Deactivate & Delete actions.
     */
    private function delete_plugins( $plugins, &$output = array() ) { //phpcs:ignore -- NOSONAR - complex.

        /**
         * MainWP Child instance.
         *
         * @global object
         */
        global $mainWPChild;

        include_once ABSPATH . '/wp-admin/includes/plugin.php'; // NOSONAR -- WP compatible.
        if ( file_exists( ABSPATH . '/wp-admin/includes/screen.php' ) ) {
            include_once ABSPATH . '/wp-admin/includes/screen.php'; // NOSONAR -- WP compatible.
        }
        include_once ABSPATH . '/wp-admin/includes/file.php'; // NOSONAR -- WP compatible.
        include_once ABSPATH . '/wp-admin/includes/template.php'; // NOSONAR -- WP compatible.
        include_once ABSPATH . '/wp-admin/includes/misc.php'; // NOSONAR -- WP compatible.
        include_once ABSPATH . '/wp-admin/includes/class-wp-upgrader.php'; // NOSONAR -- WP compatible.
        include_once ABSPATH . '/wp-admin/includes/class-wp-filesystem-base.php'; // NOSONAR -- WP compatible.
        include_once ABSPATH . '/wp-admin/includes/class-wp-filesystem-direct.php'; // NOSONAR -- WP compatible.

        MainWP_Helper::check_wp_filesystem();

        $pluginUpgrader = new \Plugin_Upgrader();

        $all_plugins = get_plugins();
        foreach ( $plugins as $plugin ) {
            if ( $plugin !== $mainWPChild->plugin_slug && isset( $all_plugins[ $plugin ] ) ) {
                $old_plugin = $all_plugins[ $plugin ];
                if ( is_plugin_active( $plugin ) ) {
                    $thePlugin = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin );
                    if ( null !== $thePlugin && '' !== $thePlugin ) {
                            deactivate_plugins( $plugin );
                    }
                }
                $tmp['plugin'] = $plugin;
                if ( true === $pluginUpgrader->delete_old_plugin( null, null, null, $tmp ) ) {
                    $args = array(
                        'action' => 'delete',
                        'Name'   => $old_plugin['Name'],
                    );
                    do_action( 'mainwp_child_plugin_action', $args );
                    $output[ $plugin ] = array(
                        'name'    => $old_plugin['Name'],
                        'version' => $old_plugin['Version'],
                        'slug'    => $plugin,
                    );
                }
            }
        }
    }

    /**
     * Method theme_action()
     *
     * Theme Activate, Deactivate & Delete actions.
     *
     * @uses wp_get_theme() Gets a WP_Theme object for a theme.
     * @see https://developer.wordpress.org/reference/functions/wp_get_theme/
     *
     * @uses switch_theme() Switches the theme.
     * @see https://developer.wordpress.org/reference/functions/switch_theme/
     *
     * @uses \MainWP\Child\MainWP_Child_Stats::get_site_stats()
     * @uses \MainWP\Child\MainWP_Helper::check_wp_filesystem()
     * @uses \MainWP\Child\MainWP_Helper::write()
     */
    public function theme_action() { // phpcs:ignore -- NOSONAR - Current complexity is the only way to achieve desired results, pull request solutions appreciated.
        // phpcs:disable WordPress.Security.NonceVerification
        $action           = MainWP_System::instance()->validate_params( 'action' );
        $theme            = isset( $_POST['theme'] ) ? sanitize_text_field( wp_unslash( $_POST['theme'] ) ) : '';
        $action_items     = array();
        $deactivate_theme = array();
        // phpcs:enable
        if ( 'activate' === $action ) {
            include_once ABSPATH . '/wp-admin/includes/theme.php'; // NOSONAR -- WP compatible.
            $theTheme = wp_get_theme( $theme );
            if ( null !== $theTheme && '' !== $theTheme ) {

                $current_theme = wp_get_theme()->get( 'Name' );

                $deactivate_theme[ $current_theme ] = array(
                    'name'    => $current_theme,
                    'version' => wp_get_theme()->display( 'Version', true, false ),
                    'slug'    => wp_get_theme()->get_stylesheet(),
                );

                switch_theme( $theTheme['Template'], $theTheme['Stylesheet'] );
                $action_items[ $theme ] = array(
                    'name'    => $theTheme->get( 'Name' ),
                    'version' => $theTheme->display( 'Version', true, false ),
                    'slug'    => $theTheme->get_stylesheet(),
                );
            }
        } elseif ( 'delete' === $action ) {
            include_once ABSPATH . '/wp-admin/includes/theme.php'; // NOSONAR -- WP compatible.
            if ( file_exists( ABSPATH . '/wp-admin/includes/screen.php' ) ) { // NOSONAR -- WP compatible.
                include_once ABSPATH . '/wp-admin/includes/screen.php'; // NOSONAR -- WP compatible.
            }
            include_once ABSPATH . '/wp-admin/includes/file.php'; // NOSONAR -- WP compatible.
            include_once ABSPATH . '/wp-admin/includes/template.php'; // NOSONAR -- WP compatible.
            include_once ABSPATH . '/wp-admin/includes/misc.php'; // NOSONAR -- WP compatible.
            include_once ABSPATH . '/wp-admin/includes/class-wp-upgrader.php'; // NOSONAR -- WP compatible.
            include_once ABSPATH . '/wp-admin/includes/class-wp-filesystem-base.php'; // NOSONAR -- WP compatible.
            include_once ABSPATH . '/wp-admin/includes/class-wp-filesystem-direct.php'; // NOSONAR -- WP compatible.

            MainWP_Helper::check_wp_filesystem();

            $themeUpgrader = new \Theme_Upgrader();

            $theme_name  = wp_get_theme()->get_stylesheet();
            $parent      = wp_get_theme()->parent();
            $parent_name = $parent ? $parent->get_stylesheet() : '';

            $themes = explode( '||', $theme );

            $themes = array_filter( $themes );

            if ( count( $themes ) === 1 ) {
                $themeToDelete = current( $themes );
                if ( $themeToDelete === $theme_name ) {
                    $information['error']['is_activated_theme'] = $themeToDelete;
                    MainWP_Helper::write( $information );
                    return;
                } elseif ( $themeToDelete === $parent_name ) {
                    $information['error']['is_activated_parent'] = $themeToDelete;
                    MainWP_Helper::write( $information );
                    return;
                }
            }

            foreach ( $themes as $themeToDelete ) {
                if ( $themeToDelete === $theme_name ) {
                    $information['error']['is_activated_theme'] = $themeToDelete;
                } elseif ( $themeToDelete === $parent_name ) {
                    $information['error']['is_activated_parent'] = $themeToDelete;
                }
                if ( $themeToDelete !== $theme_name && $themeToDelete !== $parent_name ) {
                    $theTheme = wp_get_theme( $themeToDelete );
                    if ( null !== $theTheme && '' !== $theTheme ) {
                        $tmp['theme'] = $theTheme->stylesheet; // to fix delete parent theme issue.
                        if ( true === $themeUpgrader->delete_old_theme( null, null, null, $tmp ) ) {
                            $args = array(
                                'action' => 'delete',
                                'Name'   => $theTheme['Name'],
                            );
                            do_action( 'mainwp_child_theme_action', $args );

                            $action_items[ $themeToDelete ] = array(
                                'name'    => $theTheme->get( 'Name' ),
                                'version' => $theTheme->display( 'Version', true, false ),
                                'slug'    => $theTheme->get_stylesheet(),
                            );
                        }
                    }
                }
            }
        } else {
            $information['status'] = 'FAIL';
        }

        if ( ! isset( $information['status'] ) ) {
            $information['status'] = 'SUCCESS';
        }

        $information['sync'] = MainWP_Child_Stats::get_instance()->get_site_stats( array(), false );

        $information['other_data'] = array(
            'theme_action_data' => $action_items,
        );

        if ( 'activate' === $action && ! empty( $deactivate_theme ) ) {
            $information['other_data']['theme_deactivate_data'] = $deactivate_theme;
        }

        MainWP_Helper::write( $information );
    }

    /**
     * Method install_plugin_theme()
     *
     * Plugin & Theme Installation functions.
     *
     * @uses \MainWP\Child\MainWP_Child_Install::require_files() Include necessary files.
     * @uses \MainWP\Child\MainWP_Child_Install::after_installed() After plugin or theme has been installed.
     * @uses \MainWP\Child\MainWP_Child_Install::no_ssl_filter_function() Hook to set ssl verify value.
     * @uses \MainWP\Child\MainWP_Child_Install::try_second_install() Alternative installation method.
     * @uses \MainWP\Child\MainWP_Helper::check_wp_filesystem()
     * @uses \MainWP\Child\MainWP_Helper::instance()->error()
     * @uses \MainWP\Child\MainWP_Helper::get_class_name()
     * @uses \MainWP\Child\MainWP_Helper::write()
     */
    public function install_plugin_theme() { //phpcs:ignore -- NOSONAR - complex.

        MainWP_Helper::check_wp_filesystem();
        // phpcs:disable WordPress.Security.NonceVerification
        if ( ! isset( $_POST['type'] ) || ! isset( $_POST['url'] ) || ( 'plugin' !== $_POST['type'] && 'theme' !== $_POST['type'] ) || '' === $_POST['url'] ) {
            MainWP_Helper::instance()->error( esc_html__( 'Plugin or theme not specified, or missing required data. Please reload the page and try again.', 'mainwp-child' ) );
        }

        $type = sanitize_text_field( wp_unslash( $_POST['type'] ) );

        $this->require_files();

        $urlgot = isset( $_POST['url'] ) ? json_decode( stripslashes( wp_unslash( $_POST['url'] ) ) ) : ''; //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        $urls = array();
        if ( ! is_array( $urlgot ) ) {
            $urls[] = $urlgot;
        } else {
            $urls = $urlgot;
        }

        // To fix conflict.
        if ( is_plugin_active( 'git-updater/git-updater.php' ) ) {
            remove_all_filters( 'upgrader_source_selection' );
        }

        $install_results = array();
        $result          = array();
        $install_items   = array();
        foreach ( $urls as $url ) {
            $installer  = new \WP_Upgrader();
            $ssl_verify = true;
            // @see wp-admin/includes/class-wp-upgrader.php
            if ( isset( $_POST['sslVerify'] ) && '0' === $_POST['sslVerify'] ) {
                add_filter( 'http_request_args', array( static::get_class_name(), 'no_ssl_filter_function' ), 99, 2 );
                $ssl_verify = false;
            }
            add_filter( 'http_request_args', array( MainWP_Helper::get_class_name(), 'reject_unsafe_urls' ), 99, 2 );

            $result = $installer->run(
                array(
                    'package'           => $url,
                    'destination'       => ( 'plugin' === $type ? WP_PLUGIN_DIR : WP_CONTENT_DIR . '/themes' ),
                    'clear_destination' => ( isset( $_POST['overwrite'] ) && sanitize_text_field( wp_unslash( $_POST['overwrite'] ) ) ),
                    'clear_working'     => true,
                    'hook_extra'        => array(),
                )
            );

            if ( is_wp_error( $result ) && true === $ssl_verify && strpos( $url, 'https://' ) === 0 ) {
                $ssl_verify = false;
                $result     = $this->try_second_install( $url, $installer );
            }

            remove_filter( 'http_request_args', array( MainWP_Helper::get_class_name(), 'reject_unsafe_urls' ), 99, 2 );
            if ( false === $ssl_verify ) {
                remove_filter( 'http_request_args', array( static::get_class_name(), 'no_ssl_filter_function' ), 99 );
            }
            $this->after_installed( $result, $output );
            $basename                     = basename( rawurldecode( $url ) );
            $install_results[ $basename ] = is_array( $result ) && isset( $result['destination_name'] ) ? true : false;

            if ( is_array( $output ) ) {
                if ( 'plugin' === $type && isset( $output['Name'] ) ) {
                    $install_items[] = array(
                        'name'    => $output['Name'],
                        'version' => $output['Version'],
                        'slug'    => $output['slug'],
                    );
                } elseif ( 'theme' === $type && isset( $output['slug'] ) ) {
                    $install_items[] = array(
                        'name'    => isset( $output['name'] ) ? $output['name'] : $output['slug'],
                        'slug'    => $output['slug'],
                        'version' => isset( $output['version'] ) ? $output['version'] : '',
                    );
                }
            }
        }
        // phpcs:enable

        $information['installation']                = 'SUCCESS';
        $information['destination_name']            = $result['destination_name'];
        $information['install_results']             = $install_results;
        $information['other_data']['install_items'] = $install_items;

        MainWP_Helper::write( $information );
    }

    /**
     * Method no_ssl_filter_function()
     *
     * Hook to set ssl verify value.
     *
     * @param array $r Request's array values.
     *
     * @used-by install_plugin_theme() Plugin & Theme Installation functions.
     *
     * @return array $r Request's array values.
     */
    public static function no_ssl_filter_function( $r ) {
        $r['sslverify'] = false;
        return $r;
    }

    /**
     * Method require_files()
     *
     * Include necessary files.
     *
     * @used-by install_plugin_theme() Plugin & Theme Installation functions.
     */
    private function require_files() {
        if ( file_exists( ABSPATH . '/wp-admin/includes/screen.php' ) ) {
            include_once ABSPATH . '/wp-admin/includes/screen.php'; // NOSONAR -- WP compatible.
        }
        include_once ABSPATH . '/wp-admin/includes/template.php'; // NOSONAR -- WP compatible.
        include_once ABSPATH . '/wp-admin/includes/misc.php'; // NOSONAR -- WP compatible.
        include_once ABSPATH . '/wp-admin/includes/class-wp-upgrader.php'; // NOSONAR -- WP compatible.
        include_once ABSPATH . '/wp-admin/includes/plugin.php'; // NOSONAR -- WP compatible.
    }

    /**
     * Method after_installed()
     *
     * After plugin or theme has been installed.
     *
     * @param array $result Results array from static::install_plugin_theme().
     * @param array $output Results output array.
     *
     * @uses wp_cache_set() Saves the data to the cache.
     * @see https://developer.wordpress.org/reference/functions/wp_cache_set/
     *
     * @uses activate_plugin() Attempts activation of plugin in a “sandbox” and redirects on success.
     * @see https://developer.wordpress.org/reference/functions/activate_plugin/
     *
     * @uses get_plugin_data() Parses the plugin contents to retrieve plugin’s metadata.
     * @see https://developer.wordpress.org/reference/functions/get_plugin_data/
     *
     * @used-by install_plugin_theme() Plugin & Theme Installation functions.
     */
    private function after_installed( $result, &$output ) { //phpcs:ignore -- NOSONAR - complex.
        $args = array(
            'success' => 1,
            'action'  => 'install',
        );
        // phpcs:disable WordPress.Security.NonceVerification
        if ( isset( $_POST['type'] ) && 'plugin' === $_POST['type'] ) {
            $path     = $result['destination'];
            $fileName = '';
            wp_cache_set( 'plugins', array(), 'plugins' );
            foreach ( $result['source_files'] as $srcFile ) {
                if ( is_dir( $path . $srcFile ) ) {
                    continue;
                }
                $thePlugin = get_plugin_data( $path . $srcFile );
                if ( null !== $thePlugin && '' !== $thePlugin && '' !== $thePlugin['Name'] && 'readme.txt' !== $srcFile && 'README.md' !== $srcFile ) { // to fix: skip readme.txt.
                    $args['type']    = 'plugin';
                    $args['Name']    = $thePlugin['Name'];
                    $args['Version'] = $thePlugin['Version'];
                    $args['slug']    = $result['destination_name'] . '/' . $srcFile;
                    $fileName        = $srcFile;
                    break;
                }
            }

            if ( ! empty( $fileName ) ) {
                do_action_deprecated( 'mainwp_child_installPluginTheme', array( $args ), '4.0.7.1', 'mainwp_child_install_plugin_theme' ); // NOSONAR - no IP.
                do_action( 'mainwp_child_install_plugin_theme', $args );

                if ( isset( $_POST['activatePlugin'] ) && 'yes' === $_POST['activatePlugin'] ) {
                    // to fix activate issue.
                    if ( 'quotes-collection/quotes-collection.php' === $args['slug'] ) {
                        activate_plugin( $path . $fileName, '', false, true );
                    } else {
                        activate_plugin( $path . $fileName, '' );
                    }
                }
            }
        } else {
            $args['type'] = 'theme';
            $slug         = $result['destination_name'];
            $args['slug'] = $slug;
            if ( ! empty( $slug ) ) {
                wp_clean_themes_cache();
                $theme = wp_get_theme( $slug );
                if ( $theme ) {
                    $args['name']    = $theme->name;
                    $args['version'] = $theme->version;
                }
            }
            do_action_deprecated( 'mainwp_child_installPluginTheme', array( $args ), '4.0.7.1', 'mainwp_child_install_plugin_theme' ); // NOSONAR - no IP.
            do_action( 'mainwp_child_install_plugin_theme', $args );
        }
        $output = $args;
        // phpcs:enable
    }

    /**
     * Method try_second_install()
     *
     * Alternative installation method.
     *
     * @return object $result Return error messages or TRUE.
     *
     * @param string $url       Package URL.
     * @param object $installer Instance of \WP_Upgrader.
     *
     * @uses is_wp_error() Check whether variable is a WordPress Error.
     * @see https://developer.wordpress.org/reference/functions/is_wp_error/
     *
     * @uses \MainWP\Child\MainWP_Helper::instance()->error()
     *
     * @used-by install_plugin_theme() Plugin & Theme Installation functions.
     */
    private function try_second_install( $url, $installer ) {
        // phpcs:disable WordPress.Security.NonceVerification
        $result = $installer->run(
            array(
                'package'           => $url,
                'destination'       => ( isset( $_POST['type'] ) && 'plugin' === $_POST['type'] ? WP_PLUGIN_DIR : WP_CONTENT_DIR . '/themes' ),
                'clear_destination' => ( isset( $_POST['overwrite'] ) && sanitize_text_field( wp_unslash( $_POST['overwrite'] ) ) ),
                'clear_working'     => true,
                'hook_extra'        => array(),
            )
        );
        // phpcs:enable
        if ( is_wp_error( $result ) ) {
            $err_code = $result->get_error_code();
            if ( $result->get_error_data() && is_string( $result->get_error_data() ) ) {
                $error = $result->get_error_data();
                MainWP_Helper::instance()->error( $error, $err_code );
            } else {
                MainWP_Helper::instance()->error( '', $err_code );
            }
        }
        return $result;
    }
}
