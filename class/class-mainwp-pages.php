<?php
/**
 * MainWP Child Plugin Pages
 *
 * Manage the MainWP Child plugin pages.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

/**
 * Class MainWP_Pages
 *
 * Manage the MainWP Child plugin pages.
 */
class MainWP_Pages {

    /**
     * Public static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    protected static $instance = null;

    /**
     * Public static variable containing the sub-pages array.
     *
     * @var array Subpages array.
     */
    public static $subPages;

    /**
     * Public statis variable to determine whether or not MainWP Child Plugin subpages should be loaded. Default: false.
     *
     * @var bool true|false.
     */
    public static $subPagesLoaded = false;

    /**
     * Public statis variable to contain custom branding title.
     *
     * @var string Branding title.
     */
    public static $brandingTitle = null;

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
     * MainWP_Pages constructor.
     *
     * Run any time class is called.
     */
    public function __construct() {
    }

    /**
     * Method get_instance()
     *
     * Create a public static instance.
     *
     * @return mixed Class instance.
     */
    public static function get_instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }

        return static::$instance;
    }

    /**
     * Initiate actions and filters.
     */
    public function init() {
        add_action( 'admin_init', array( &$this, 'admin_init' ) );
        add_action( 'admin_menu', array( &$this, 'admin_menu' ) );
        add_action( 'admin_notices', array( &$this, 'admin_notice' ) );
        add_filter( 'plugin_row_meta', array( &$this, 'plugin_row_meta' ), 10, 2 );
    }


    /**
     * Show disconnected admin notice.
     *
     * Show the Warning notice in case the site is not connected to MainWP Dashboard.
     *
     * @uses \MainWP\Child\MainWP_Child_Branding::get_branding_options()
     * @uses \MainWP\Child\MainWP_Child_Branding::is_branding()
     */
    public function admin_notice() { //phpcs:ignore -- NOSONAR -complexity.
        // Admin Notice...
        if ( ! get_option( 'mainwp_child_pubkey' ) && MainWP_Helper::is_admin() && is_admin() ) {
            $branding_opts  = MainWP_Child_Branding::instance()->get_branding_options();
            $child_name     = ( '' === $branding_opts['branding_preserve_title'] ) ? 'MainWP Child' : $branding_opts['branding_preserve_title'];
            $dashboard_name = ( '' === $branding_opts['branding_preserve_title'] ) ? 'MainWP Dashboard' : $branding_opts['branding_preserve_title'] . ' Dashboard';

            $msg = '<div style="background:#ffffff;padding:20px;margin:20px 20px 20px 2px;border:1px solid #f4f4f4;">';
            if ( ! MainWP_Child_Branding::instance()->is_branding() ) {
                $msg .= '<div style="width:105px;float:left;margin-right:20px">';
                $msg .= '<img alt="MainWP Icon" style="max-width:105px" src="data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0idXRmLTgiPz4NCjwhLS0gR2VuZXJhdG9yOiBBZG9iZSBJbGx1c3RyYXRvciAyNi4zLjEsIFNWRyBFeHBvcnQgUGx1Zy1JbiAuIFNWRyBWZXJzaW9uOiA2LjAwIEJ1aWxkIDApICAtLT4NCjxzdmcgdmVyc2lvbj0iMS4xIiBpZD0iTGF5ZXJfMSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIiB4bWxuczp4bGluaz0iaHR0cDovL3d3dy53My5vcmcvMTk5OS94bGluayIgeD0iMHB4IiB5PSIwcHgiDQoJIHZpZXdCb3g9IjAgMCAxNzAgMTcwIiBzdHlsZT0iZW5hYmxlLWJhY2tncm91bmQ6bmV3IDAgMCAxNzAgMTcwOyIgeG1sOnNwYWNlPSJwcmVzZXJ2ZSI+DQo8c3R5bGUgdHlwZT0idGV4dC9jc3MiPg0KCS5zdDB7ZmlsbDojN0ZCMTAwO30NCgkuc3Qxe2ZpbGw6I0ZGRkZGRjt9DQo8L3N0eWxlPg0KPGc+DQoJPGNpcmNsZSBjbGFzcz0ic3QwIiBjeD0iODUiIGN5PSI4NSIgcj0iNzguNjciLz4NCgk8Zz4NCgkJPGNpcmNsZSBjbGFzcz0ic3QxIiBjeD0iODUiIGN5PSIzNy44IiByPSIxNS43MyIvPg0KCQk8cG9seWdvbiBjbGFzcz0ic3QxIiBwb2ludHM9IjExMS43NSwxMzIuMiA4NSwxNDcuOTQgNTguMjUsMTMyLjIgODUsMjIuMDYgCQkiLz4NCgk8L2c+DQo8L2c+DQo8L3N2Zz4NCg==" />';
                $msg .= '</div>';
            }
            $msg .= '<div style="font-size:1.5em;font-weight:bolder;margin-bottom:16px;">' . esc_html( $child_name ) . esc_html__( ' Plugin is Activated', 'mainwp-child' ) . '</div>';
            $msg .= '<div style="font-size:1.2em;margin-bottom:8px">' . esc_html__( 'This site is now ready for connection. Please proceed with the connection process from your ', 'mainwp-child' ) . esc_html( $dashboard_name ) . ' ' . esc_html__( 'to start managing the site. ', 'mainwp-child' ) . '</div>';
            $msg .= '<div style="font-size:1.2em;margin-bottom:8px">' . sprintf( esc_html__( 'If you need assistance, refer to our %1$sdocumentation%2$s.', 'mainwp-child' ), '<a href="https://kb.mainwp.com/docs/add-site-to-your-dashboard/" target="_blank">', '</a>' ) . '</div>';
            if ( ! MainWP_Child_Branding::instance()->is_branding() ) {
                $msg .= '<div style="font-size:1.2em;">' . esc_html__( 'For additional security options, visit the ', 'mainwp-child' ) . esc_html( $child_name ) . sprintf( esc_html__( ' %1$splugin settings%2$s. ', 'maiwnip-child' ), '<a href="admin.php?page=mainwp_child_tab">', '</a>' ) . '</div>';
                $msg .= '<div style="clear:both"></div>';
            }
            $msg .= '</div>';
            echo $msg; //phpcs:ignore -- NOSONAR - ok
        }

        if ( isset( $_GET['page'] ) && 'mainwp_child_tab' === $_GET['page'] && isset( $_GET['message'] ) ) { //phpcs:ignore -- ok.

            $message = '';

            if ( '1' === wp_unslash( $_GET['message'] ) ) { //phpcs:ignore -- ok.
                $message = __( 'Disconnected the Site from Dashboard.', 'mainwp-child' );
            } elseif ( '2' === wp_unslash( $_GET['message'] ) ) { //phpcs:ignore -- ok.
                $message = __( 'Settings have been saved successfully.', 'mainwp-child' );
            }

            if ( ! empty( $message ) ) {
                ?>
                <div>
                <div class="notice notice-success settings-error is-dismissible">
                    <p><?php echo esc_html( $message ); ?></p><button type="button" class="notice-dismiss">
                    <span class="screen-reader-text"><?php esc_html_e( 'Dismiss this notice.', 'mainwp-child' ); ?></span></button>
                </div>
                <?php
            }
        }
    }

    /**
     * Method admin_init().
     */
    public function admin_init() { //phpcs:ignore -- NOSONAR - complex method.

        if ( isset( $_POST['nonce-disconnect'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce-disconnect'] ) ), 'child-settings-disconnect' ) ) {
            global $mainWPChild;
            $mainWPChild->delete_connection_data( false );
            delete_option( 'mainwp_child_lasttime_not_connected' ); // reset.
            wp_safe_redirect( 'options-general.php?page=mainwp_child_tab&message=1' );
        }

        // phpcs:disable WordPress.Security.NonceVerification
        if ( isset( $_POST['submit'] ) && isset( $_POST['nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'child-settings' ) ) {
            if ( isset( $_POST['requireUniqueSecurityId'] ) ) {
                MainWP_Helper::update_option( 'mainwp_child_uniqueId', MainWP_Helper::rand_string( 12 ) );
            } else {
                MainWP_Helper::update_option( 'mainwp_child_uniqueId', '' );
            }
            MainWP_Helper::update_option( 'mainwp_child_ttl_active_unconnected_site', ! empty( $_POST['mainwp_child_active_time_for_unconnected_site'] ) ? intval( $_POST['mainwp_child_active_time_for_unconnected_site'] ) : 0 );
            update_user_option( get_current_user_id(), 'mainwp_child_user_enable_passwd_auth_connect', ! empty( $_POST['mainwp_child_user_enable_pwd_auth_connect'] ) ? 1 : 0 );
            wp_safe_redirect( 'options-general.php?page=mainwp_child_tab&message=2' );
        }
        // phpcs:enable
    }


    /**
     * Add and remove Admin Menu Items dependant upon Branding settings.
     *
     * @uses \MainWP\Child\MainWP_Child_Branding::get_branding_options()
     */
    public function admin_menu() { //phpcs:ignore -- NOSONAR - complex method.
        $branding_opts      = MainWP_Child_Branding::instance()->get_branding_options();
        $is_hide            = isset( $branding_opts['hide'] ) ? $branding_opts['hide'] : '';
        $cancelled_branding = $branding_opts['cancelled_branding'];
        $uri                = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
        if ( isset( $branding_opts['remove_wp_tools'] ) && $branding_opts['remove_wp_tools'] && ! $cancelled_branding ) {
            remove_menu_page( 'tools.php' );
            $pos = $uri ? stripos( $uri, 'tools.php' ) || stripos( $uri, 'import.php' ) || stripos( $uri, 'export.php' ) : false;
            if ( false !== $pos ) {
                wp_safe_redirect( get_option( 'siteurl' ) . '/wp-admin/index.php' );
            }
        }
        // if preserve branding and do not remove menus.
        if ( isset( $branding_opts['remove_wp_setting'] ) && $branding_opts['remove_wp_setting'] && ! $cancelled_branding ) {
            remove_menu_page( 'options-general.php' );
            $pos = $uri ? ( stripos( $uri, 'options-general.php' ) || stripos( $uri, 'options-writing.php' ) || stripos( $uri, 'options-reading.php' ) || stripos( $uri, 'options-discussion.php' ) || stripos( $uri, 'options-media.php' ) || stripos( $uri, 'options-permalink.php' ) ) : false;
            if ( false !== $pos ) {
                wp_safe_redirect( get_option( 'siteurl' ) . '/wp-admin/index.php' );
                exit();
            }
        }

        if ( isset( $branding_opts['remove_permalink'] ) && $branding_opts['remove_permalink'] && ! $cancelled_branding ) {
            remove_submenu_page( 'options-general.php', 'options-permalink.php' );
            $pos = $uri ? stripos( $uri, 'options-permalink.php' ) : false;
            if ( false !== $pos ) {
                wp_safe_redirect( get_option( 'siteurl' ) . '/wp-admin/index.php' );
                exit();
            }
        }

        $remove_all_child_menu = false;
        if ( isset( $branding_opts['remove_setting'] ) && isset( $branding_opts['remove_restore'] ) && isset( $branding_opts['remove_server_info'] ) && $branding_opts['remove_setting'] && $branding_opts['remove_restore'] && $branding_opts['remove_server_info'] ) {
            $remove_all_child_menu = true;
        }

        // if preserve branding and do not hide menus.
        if ( ( ! $remove_all_child_menu && 'T' !== $is_hide ) || $cancelled_branding ) {

            $branding_header = isset( $branding_opts['branding_header'] ) ? $branding_opts['branding_header'] : array();
            if ( ( is_array( $branding_header ) && ! empty( $branding_header['name'] ) ) && ! $cancelled_branding ) {
                static::$brandingTitle = stripslashes( $branding_header['name'] );
                $child_menu_title      = stripslashes( $branding_header['name'] );
                $child_page_title      = $child_menu_title . ' Settings';
            } else {
                $child_menu_title = 'MainWP Child';
                $child_page_title = 'MainWP Child Settings';
            }
            $this->init_pages( $child_menu_title, $child_page_title );
        }
    }

    /**
     * Initiate MainWP Child Plugin pages.
     *
     * @param string $child_menu_title New MainWP Child Plugin title defined in branding settings.
     * @param string $child_page_title New MainWP Child Plugin page title defined in branding settings.
     *
     * @uses \MainWP\Child\MainWP_Clone_Page::get_class_name()
     */
    private function init_pages( $child_menu_title, $child_page_title ) { //phpcs:ignore -- NOSONAR - complex.

        $settingsPage = add_submenu_page( 'options-general.php', $child_page_title, $child_menu_title, 'manage_options', 'mainwp_child_tab', array( &$this, 'render_pages' ) );

        add_action( 'admin_print_scripts-' . $settingsPage, array( MainWP_Clone_Page::get_class_name(), 'print_scripts' ) );

        $sub_pages = array();

        $all_subpages = apply_filters_deprecated( 'mainwp-child-init-subpages', array( array() ), '4.0.7.1', 'mainwp_child_init_subpages' ); // NOSONAR - no IP.
        $all_subpages = apply_filters( 'mainwp_child_init_subpages', $all_subpages );

        if ( ! is_array( $all_subpages ) ) {
            $all_subpages = array();
        }

        if ( ! static::$subPagesLoaded ) {
            foreach ( $all_subpages as $page ) {
                $slug = isset( $page['slug'] ) ? $page['slug'] : '';
                if ( empty( $slug ) ) {
                    continue;
                }
                $subpage          = array();
                $subpage['slug']  = $slug;
                $subpage['title'] = $page['title'];
                $subpage['page']  = 'mainwp-' . str_replace( ' ', '-', strtolower( str_replace( '-', ' ', $slug ) ) );
                if ( isset( $page['callback'] ) ) {
                    $subpage['callback'] = $page['callback'];
                    $created_page        = add_submenu_page( 'options-general.php', $subpage['title'], '<div class="mainwp-hidden">' . $subpage['title'] . '</div>', 'manage_options', $subpage['page'], $subpage['callback'] );
                    if ( isset( $page['load_callback'] ) ) {
                        $subpage['load_callback'] = $page['load_callback'];
                        add_action( 'load-' . $created_page, $subpage['load_callback'] );
                    }
                }
                $sub_pages[] = $subpage;
            }
            static::$subPages       = $sub_pages;
            static::$subPagesLoaded = true;
        }
        add_action( 'mainwp-child-pageheader', array( __CLASS__, 'render_header' ) );
        add_action( 'mainwp-child-pagefooter', array( __CLASS__, 'render_footer' ) );

        /**
         * WordPress submenu array.
         *
         * @global array $submenu WordPress submenu array.
         */
        global $submenu;

        if ( isset( $submenu['options-general.php'] ) ) {
            foreach ( $submenu['options-general.php'] as $index => $item ) {
                if ( 'mainwp-reports-page' === $item[2] || 'mainwp-reports-settings' === $item[2] ) {
                    unset( $submenu['options-general.php'][ $index ] );
                }
            }
        }
    }

    /**
     * MainWP Child Plugin meta data.
     *
     * @param array  $plugin_meta Plugin meta.
     * @param string $plugin_file Plugin file.
     *
     * @return mixed The filtered value after all hooked functions are applied to it.
     */
    public function plugin_row_meta( $plugin_meta, $plugin_file ) {

        /**
         * MainWP Child instance.
         *
         * @global object
         */
        global $mainWPChild;

        if ( ! $mainWPChild || $mainWPChild->plugin_slug !== $plugin_file ) {
            return $plugin_meta;
        }
        return apply_filters( 'mainwp_child_plugin_row_meta', $plugin_meta, $plugin_file, $mainWPChild->plugin_slug );
    }

    /**
     * Render MainWP Child Plugin pages.
     *
     * @uses \MainWP\Child\MainWP_Child_Branding::get_branding_options()
     * @uses \MainWP\Child\MainWP_Child_Server_Information::render_page()
     * @uses \MainWP\Child\MainWP_Child_Server_Information::render_connection_details()
     * @uses \MainWP\Child\MainWP_Clone_Page::render()
     * @uses \MainWP\Child\MainWP_Clone_Page::render_normal_restore()
     * @uses \MainWP\Child\MainWP_Clone_Page::render_restore()
     */
    public function render_pages() { // phpcs:ignore -- NOSONAR - Current complexity is the only way to achieve desired results, pull request solutions appreciated.
        $shownPage     = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        $branding_opts = MainWP_Child_Branding::instance()->get_branding_options();

        $hide_settings          = isset( $branding_opts['remove_setting'] ) && $branding_opts['remove_setting'] ? true : false;
        $hide_restore           = isset( $branding_opts['remove_restore'] ) && $branding_opts['remove_restore'] ? true : false;
        $hide_server_info       = isset( $branding_opts['remove_server_info'] ) && $branding_opts['remove_server_info'] ? true : false;
        $hide_connection_detail = isset( $branding_opts['remove_connection_detail'] ) && $branding_opts['remove_connection_detail'] ? true : false;

        if ( '' === $shownPage ) {
            if ( ! $hide_settings ) {
                    $shownPage = 'settings';
            } elseif ( ! $hide_restore ) {
                $shownPage = 'restore-clone';
            } elseif ( ! $hide_server_info ) {
                $shownPage = 'server-info';
            } elseif ( ! $hide_connection_detail ) {
                $shownPage = 'connection-detail';
            }
        }

        static::render_header( $shownPage, false, $show_clones );

        if ( is_null( $show_clones ) ) {
            $show_clones = true;
        }

        ?>
        <?php if ( ! $hide_settings ) { ?>
            <div class="mainwp-child-setting-tab settings" <?php echo 'settings' !== $shownPage ? 'style="display:none"' : ''; ?>>
                <?php $this->render_settings(); ?>
            </div>
        <?php } ?>

        <?php
        if ( ! $hide_restore && $show_clones ) {
            $fsmethod = MainWP_Child_Server_Information_Base::get_file_system_method();
            if ( 'direct' === $fsmethod ) { // to fix error some case of file system method is not direct.
                ?>
            <div class="mainwp-child-setting-tab restore-clone" <?php echo 'restore-clone' !== $shownPage ? 'style="display:none"' : ''; ?>>
                <?php
                if ( isset( $_SESSION['file'] ) ) {
                    MainWP_Clone_Page::render_restore();
                } else {
                    $sitesToClone = get_option( 'mainwp_child_clone_sites' );
                    if ( 0 !== (int) $sitesToClone ) {
                        MainWP_Clone_Page::render();
                    } else {
                        MainWP_Clone_Page::render_normal_restore();
                    }
                }
                ?>
            </div>
            <?php } ?>
        <?php } ?>

        <?php if ( ! $hide_server_info ) { ?>
            <div class="mainwp-child-setting-tab server-info" <?php echo 'server-info' !== $shownPage ? 'style="display:none"' : ''; ?>>
                <?php MainWP_Child_Server_Information::render_page(); ?>
            </div>
        <?php } ?>

            <?php if ( ! $hide_connection_detail ) { ?>
            <div class="mainwp-child-setting-tab connection-detail" <?php echo 'connection-detail' !== $shownPage ? 'style="display:none"' : ''; ?>>
                    <?php MainWP_Child_Server_Information::render_connection_details(); ?>
            </div>
        <?php } ?>
        <?php
        static::render_footer();
    }

    /**
     * Render page header.
     *
     * @param string $shownPage Page shown.
     * @param bool   $subpage Whether or not a subpage. Default: true.
     * @param bool   $show_clone_funcs Whether or not to show clone tabs.
     *
     * @uses \MainWP\Child\MainWP_Child_Branding::get_branding_options()
     */
    public static function render_header( $shownPage, $subpage = true, &$show_clone_funcs = true ) { // phpcs:ignore -- NOSONAR - Current complexity is the only way to achieve desired results, pull request solutions appreciated.
        $tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

        if ( ! empty( $tab ) ) {
            $shownPage = $tab;
        }

        if ( empty( $shownPage ) ) {
            $shownPage = 'settings';
        }

        $branding_opts = MainWP_Child_Branding::instance()->get_branding_options();

        $hide_settings          = isset( $branding_opts['remove_setting'] ) && $branding_opts['remove_setting'] ? true : false;
        $hide_restore           = isset( $branding_opts['remove_restore'] ) && $branding_opts['remove_restore'] ? true : false;
        $hide_server_info       = isset( $branding_opts['remove_server_info'] ) && $branding_opts['remove_server_info'] ? true : false;
        $hide_connection_detail = isset( $branding_opts['remove_connection_detail'] ) && $branding_opts['remove_connection_detail'] ? true : false;

        $sitesToClone = get_option( 'mainwp_child_clone_sites' );

        // put here to support hooks to show header.
        $is_connected_admin = false;
        $connected          = ! empty( get_option( 'mainwp_child_pubkey' ) ) ? true : false;
        if ( $connected ) {
            $current_user = wp_get_current_user();
            if ( $current_user ) {
                $is_connected_admin = get_option( 'mainwp_child_connected_admin' ) === $current_user->user_login ? true : false;
            }
        }
        $show_clone_funcs = $connected && $is_connected_admin ? true : false;

        ?>
        <style type="text/css">
            .settings_page_mainwp_child_tab #wpwrap,
            .settings_page_mainwp-reports-settings #wpwrap {

            }

            #mainwp-child-settings-page-content {
                margin: 20px 20px 0 0;
                background: #FFFFFF;
                border: 1px solid #E7EEF6;
            }

            #mainwp-child-settings-page-content p {
                font-size: 15px;
            }
            #mainwp-child-settings-page-content h4 {
                font-size: 1.2em;
            }

            #mainwp-child-settings-page-navigation {
                background: #2D3B44;
            }

            #mainwp-child-settings-page-tabs {
                padding: 20px;
            }

            #mainwp-child-settings-page-navigation .nav-tab {
                background: #2D3B44;
                color: #FFFFFF;
                border: none;
                margin: 0;
                padding: 1em;
            }

            #mainwp-child-settings-page-navigation .nav-tab:hover {
                background: #3a4c58;
            }

            #mainwp-child-settings-page-navigation .nav-tab-active {
                background: #4682b4;
            }

            .mainwp-hidden {
                display: none;
            }

            /* The switch - the box around the slider */
            .mainwp-toggle {
                position: relative;
                display: inline-block;
                width: 49px;
                height: 21px;
                margin-right: 1em;
            }

            /* Hide default HTML checkbox */
            .mainwp-toggle input {
                opacity: 0;
                width: 0;
                height: 0;
            }

            /* The slider */
            .mainwp-slider {
                position: absolute;
                cursor: pointer;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background-color: rgba(0, 0, 0, 0.05);
                -webkit-transition: .4s;
                transition: .4s;
                border-radius: 11px;
            }

            .mainwp-slider:before {
                position: absolute;
                content: "";
                height: 21px;
                width: 21px;
                background: #fff linear-gradient(transparent, rgba(0, 0, 0, 0.05));
                box-shadow: 0 1px 2px 0 rgba(34, 36, 38, 0.15), 0 0 0 1px rgba(34, 36, 38, 0.15) inset;
                -webkit-transition: .4s;
                transition: .4s;
                border-radius: 11px;
            }

            .mainwp-toggle input:checked + .mainwp-slider {
                background: #7fb100;
            }

            .mainwp-toggle input:checked + .mainwp-slider:before {
                -webkit-transform: translateX(28px);
                -ms-transform: translateX(28px);
                transform: translateX(28px);
            }

            .mainwp-button {
                background-color: #7fb100;
                border: none;
                color: #ffffff !important;
                border-radius: 15px;
                padding: 0.78571429em 1.5em 0.78571429em;
                cursor: pointer;
                font-weight: bolder;
                font-size:1em;
            }

            .mainwp-basic-button {
                background-color: #4682b4;
                border: none;
                color: #ffffff !important;
                border-radius: 15px;
                padding: 0.78571429em 1.5em 0.78571429em;
                cursor: pointer;
                font-weight: bolder;
                font-size:1em;
            }
            .mainwp-basic-button:disabled {
                background-color: #4682b4;
                opacity: 0.45;
            }

            .mainwp-number-field {
                margin: 0;
                outline: none;
                -webkit-appearance: none;
                -webkit-tap-highlight-color: rgba(255, 255, 255, 0);
                line-height: 1.21428571em !important;
                padding: 0.67857143em 1em !important;
                font-size: 1em !important;
                background: #fff;
                border: 1px solid rgba(34, 36, 38, 0.15) !important;
                color: rgba(0, 0, 0, 0.87);
                border-radius: 0.28571429rem !important;
            }

        </style>

        <div class="" id="mainwp-child-settings-page">
            <h1><?php echo esc_html( null === static::$brandingTitle ? 'MainWP Child' : static::$brandingTitle ); ?></h1>
            <div class="" id="mainwp-child-settings-page-content">
                <div class="" id="mainwp-child-settings-page-navigation">
                    <?php if ( ! $hide_settings ) : ?>
                        <a class="nav-tab pos-nav-tab <?php echo ( 'settings' === $shownPage ) ? 'nav-tab-active' : ''; ?>" tab-slug="settings" href="<?php echo $subpage ? 'options-general.php?page=mainwp_child_tab&tab=settings' : '#'; ?>"><?php esc_html_e( 'Settings', 'mainwp-child' ); ?></a>
                    <?php endif; ?>
                    <?php if ( ! $hide_restore && $show_clone_funcs ) : ?>
                        <a class="nav-tab pos-nav-tab <?php echo ( 'restore-clone' === $shownPage ) ? 'nav-tab-active' : ''; ?>" tab-slug="restore-clone" href="<?php echo $subpage ? 'options-general.php?page=mainwp_child_tab&tab=restore-clone' : '#'; ?>"><?php echo esc_html__( 0 !== (int) $sitesToClone ? 'Restore / Clone' : 'Restore', 'mainwp-child' ); ?></a>
                    <?php endif; ?>
                    <?php if ( ! $hide_server_info ) : ?>
                        <a class="nav-tab pos-nav-tab <?php echo ( 'server-info' === $shownPage ) ? 'nav-tab-active' : ''; ?>" tab-slug="server-info" href="<?php echo $subpage ? 'options-general.php?page=mainwp_child_tab&tab=server-info' : '#'; ?>"><?php esc_html_e( 'Server Information', 'mainwp-child' ); ?></a>
                    <?php endif; ?>
                    <?php if ( ! $hide_connection_detail ) : ?>
                        <a class="nav-tab pos-nav-tab <?php echo ( 'connection-detail' === $shownPage ) ? 'nav-tab-active' : ''; ?>" tab-slug="connection-detail" href="<?php echo $subpage ? 'options-general.php?page=mainwp_child_tab&tab=connection-detail' : '#'; ?>"><?php esc_html_e( 'Connection Details', 'mainwp-child' ); ?></a>
                    <?php endif; ?>
                    <?php if ( isset( static::$subPages ) && is_array( static::$subPages ) ) : ?>
                        <?php foreach ( static::$subPages as $subPage ) : ?>
                            <a class="nav-tab pos-nav-tab <?php echo ( $shownPage === $subPage['slug'] ) ? 'nav-tab-active' : ''; ?>" tab-slug="<?php echo esc_attr( $subPage['slug'] ); ?>" href="options-general.php?page=<?php echo esc_html( rawurlencode( $subPage['page'] ) ); ?>"><?php echo esc_html( $subPage['title'] ); ?></a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <div style="clear:both"></div>
                </div>
                <div style="clear:both"></div>
                <div class="" id="mainwp-child-settings-page-tabs">

        <script type="text/javascript">
            jQuery( document ).ready( function () {
                $hideMenu = jQuery( '#menu-settings li a .mainwp-hidden' );
                $hideMenu.each( function() {
                    jQuery( this ).closest( 'li' ).hide();
                } );

                var $tabs = jQuery( '#mainwp-child-settings-page-navigation' );

                $tabs.on( 'click', 'a', function () {
                    if ( jQuery( this ).attr( 'href' ) !=='#' )
                        return true;

                    jQuery( '#mainwp-child-settings-page-navigation > a' ).removeClass( 'nav-tab-active' );
                    jQuery( this ).addClass( 'nav-tab-active' );
                    jQuery( '.mainwp-child-setting-tab' ).hide();
                    var _tab = jQuery( this ).attr( 'tab-slug' );
                    jQuery( '.mainwp-child-setting-tab.' + _tab ).show();
                    return false;
                } );
            } );
        </script>

        <?php
    }

    /**
     * Render page footer.
     */
    public static function render_footer() {
        ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render connection settings sub page.
     *
     * @uses \MainWP\Child\MainWP_Helper::update_option()
     */
    public function render_settings() {
        $branding_title = MainWP_Child_Branding::instance()->get_branding_title();
        if ( '' === $branding_title ) {
            $branding_title = 'MainWP';
        } else {
            $branding_title = stripslashes( $branding_title );
        }

        $uniqueId   = MainWP_Helper::get_site_unique_id();
        $time_limit = get_option( 'mainwp_child_ttl_active_unconnected_site', 20 );

        $enable_pwd_auth_connect = get_user_option( 'mainwp_child_user_enable_passwd_auth_connect' );

        if ( false === $enable_pwd_auth_connect ) {
            $enable_pwd_auth_connect = 1;
            update_user_option( get_current_user_id(), 'mainwp_child_user_enable_passwd_auth_connect', 1 );
        }

        ?>
        <h2 style="font-size:1.5em"><?php esc_html_e( 'Connection Security Settings', 'mainwp-child' ); ?></h2>
        <p><?php esc_html_e( 'Configure the plugin to best suit your security and connection needs.', 'mainwp-child' ); ?></p>
        <br/>
        <form method="post" action="options-general.php?page=mainwp_child_tab">
            <header class="section-header">
                <h3><?php esc_html_e( 'Password Authentication - Initial Connection Security', 'mainwp-child' ); ?></h3>
                <hr/>
            </header>
            <p><?php esc_html_e( $branding_title . ' requests that you connect using an admin account and password for the initial setup. Rest assured, your password is never stored by your Dashboard and never sent to ' . $branding_title . '.com. Once this initial connection is complete, your ' . $branding_title . ' Dashboard generates a secure Public and Private key pair (2048 bits) using OpenSSL, allowing future connections without needing your password again. For added security, you can even change this admin password once connected just be sure not to delete the admin account, as this would disrupt the connection.', 'mainwp-child' ); ?></p>
            <h4><strong><?php esc_html_e( 'Dedicated ' . $branding_title . ' Admin Account', 'mainwp-child' ); ?></strong></h4>
            <p><?php esc_html_e( 'For further security, we recommend creating a dedicated admin account specifically for ' . $branding_title . '. This \'' . $branding_title . ' Admin\' account can be used exclusively by ' . $branding_title . ', allowing you to easily track any actions performed by the plugin. To set this up, go to Users to create the account, then return to your Dashboard to connect it.', 'mainwp-child' ); ?></p>
            <h4><strong><?php esc_html_e( 'Disabling Password Security', 'mainwp-child' ); ?></strong></h4>
            <p><?php esc_html_e( 'If you prefer not to use password security, you can disable it by unchecking the box below. Make sure this child site is ready to connect before turning off this feature.', 'mainwp-child' ); ?></p>
            <p>
            <?php
            if ( MainWP_Child_Branding::instance()->is_branding() ) {
                esc_html_e( 'If you have additional questions, please refer to this Knowledge Base article or contact ' . $branding_title . ' Support.', 'mainwp-child' );
            } else {
                printf( esc_html__( 'If you have additional questions, please %srefer to this Knowledge Base article%s or %scontact MainWP Support%s.', 'mainwp-child' ), '<a href="https://kb.mainwp.com/docs/mainwp-connection-security/#password-authentication" target="_blank">', '</a>', '<a href="https://mainwp.com/mainwp-support/" target="_blank">', '</a>' );
            }
            ?>
            </p>
            <table class="form-table">
                <tbody>
                    <tr>
                        <th scope="row" style="width:300px"><?php esc_html_e( 'Require Password Authentication', 'mainwp-child' ); ?></th>
                        <td>
                        <label for="mainwp_child_user_enable_pwd_auth_connect" class="mainwp-toggle">
                            <input type="checkbox" name="mainwp_child_user_enable_pwd_auth_connect" id="mainwp_child_user_enable_pwd_auth_connect" value="1" <?php echo $enable_pwd_auth_connect ? 'checked' : ''; ?> />
                            <span class="mainwp-slider"></span>
                        </label><?php esc_html_e( 'Enable this option to require password authentication on initial site connection.', 'mainwp-child' ); ?>
                        </td>
                    <tr>
                </tbody>
            </table>

            <header class="section-header">
                <h3><?php esc_html_e( 'Unique Security ID', 'mainwp-child' ); ?></h3>
                <hr/>
            </header>
            <p><?php printf( esc_html__( 'Add an extra layer of security for connecting this site to your %s Dashboard.', 'mainwp-child' ), esc_html( stripslashes( $branding_title ) ) ); ?></p>

            <table class="form-table">
                <tbody>
                    <tr>
                        <th scope="row" style="width:300px"><?php esc_html_e( 'Require Unique Security ID', 'mainwp-child' ); ?></th>
                        <td>
                            <label for="requireUniqueSecurityId" class="mainwp-toggle">
                                <input name="requireUniqueSecurityId" type="checkbox" id="requireUniqueSecurityId" <?php echo ( ! empty( $uniqueId ) ) ? 'checked' : ''; ?> />
                                <span class="mainwp-slider"></span>
                            </label><?php esc_html_e( 'Enable this option for an added layer of protection when connecting this site.', 'mainwp-child' ); ?>
                        </td>
                    <tr>
                </tbody>
            </table>

            <div>
            <?php if ( ! empty( $uniqueId ) ) : ?>
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row" style="width:300px"><?php esc_html_e( 'Your unique security ID is:', 'mainwp-child' ); ?></th>
                            <td><?php echo '<code>' . esc_html( get_option( 'mainwp_child_uniqueId' ) ) . '</code>'; ?></td>
                        <tr>
                    </tbody>
                </table>
            <?php endif; ?>
            </div>
            <header class="section-header">
                <h3><?php esc_html_e( 'Connection Timeout', 'mainwp-child' ); ?></h3>
                <hr/>
            </header>
            <p><?php esc_html_e( 'Define how long the plugin will remain active if no connection is established. After this period, the plugin will automatically deactivate for security.', 'mainwp-child' ); ?></p>
            <table class="form-table">
                <tbody>
                    <tr>
                        <th scope="row" style="width:300px"><?php esc_html_e( 'Set Connection Timeout', 'mainwp-child' ); ?></th>
                        <td>
                            <input type="number" name="mainwp_child_active_time_for_unconnected_site" id="mainwp_child_active_time_for_unconnected_site" class="mainwp-number-field" placeholder="" min="0" max="999" step="1" value="<?php echo intval( $time_limit ); ?>">
                            <label for="mainwp_child_active_time_for_unconnected_site"><?php esc_html_e( 'Specify how long the plugin should stay active if a connection isn\'t established. Enter a value in minutes.', 'mainwp-child' ); ?></label>
                        </td>
                    <tr>
                </tbody>
            </table>

            <div>

            </div>
            <p class="submit">
                <input type="submit" name="submit" id="submit" class="mainwp-button" value="<?php esc_attr_e( 'Save Settings', 'mainwp-child' ); ?>">
            </p>
            <input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'child-settings' ) ); ?>">
        </form>
        <br/>
        <header class="section-header">
            <h3><?php esc_html_e( 'Site Connection Management', 'mainwp-child' ); ?></h3>
            <hr/>
        </header>
        <form method="post" onsubmit="return confirm('<?php echo esc_js( __( 'Are you sure you want to Disconnect Site from your ' . $branding_title . ' Dashboard?', 'mainwp-child' ) ); ?>');"  action="options-general.php?page=mainwp_child_tab">
            <p><?php printf( esc_html__( 'Click this button to disconnect this site from your %s Dashboard.', 'mainwp-child' ), esc_html( stripslashes( $branding_title ) ) ); ?></p>
            <p class="submit">
                <input <?php echo empty( get_option( 'mainwp_child_pubkey' ) ) ? ' disabled="disabled" ' : ''; ?> type="submit" name="submit" id="submit" class="mainwp-basic-button" value="<?php esc_attr_e( 'Clear Connection Data', 'mainwp-child' ); ?>">
            </p>
            <input type="hidden" name="nonce-disconnect" value="<?php echo esc_attr( wp_create_nonce( 'child-settings-disconnect' ) ); ?>">
        </form>
        <?php
    }
}
