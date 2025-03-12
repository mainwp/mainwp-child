<?php
/**
 * MainWP Child Stats.
 *
 * Gather the child site data to send to the MainWP Dashboard.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

//phpcs:disable Generic.Metrics.CyclomaticComplexity -- Required to achieve desired results, pull request solutions appreciated.

/**
 * Class MainWP_Child_Stats
 *
 * Gather the child site data to send to the MainWP Dashboard.
 */
class MainWP_Child_Stats { //phpcs:ignore -- NOSONAR - multi methods.

    /**
     * Public static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    protected static $instance = null;

    /**
     * Class used to represent anonymous functions.
     *
     * @var null
     */
    private $filterFunction = null;

    /**
     * Class used to represent anonymous functions.
     *
     * @var array
     */
    private $sync_data_list = null;

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
     * MainWP_Child_Stats constructor.
     *
     * Run any time class is called.
     */
    public function __construct() {

            /**
             * Checks if 'last_checked'.
             *
             * @param $a Object to check.
             * @return object|bool $a Return object or FALSE on failure.
             */
            $this->filterFunction = function ( $a ) {
                if ( empty( $a ) ) {
                    return false;
                }
                if ( is_object( $a ) && property_exists( $a, 'last_checked' ) && ! property_exists( $a, 'checked' ) ) {
                    return false;
                }
                return $a;
            };
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
     * Show stats without login. only allowed while no account is added yet.
     *
     * @param array $information Child Site Stats.
     *
     * @uses \MainWP\Child\MainWP_Child::$version
     * @uses \MainWP\Child\MainWP_Helper::is_wp_engine()
     * @uses \MainWP\Child\MainWP_Helper::write()
     */
    public function get_site_stats_no_auth( $information = array() ) {
        if ( get_option( 'mainwp_child_pubkey' ) ) {
            $hint = '<br/>' . esc_html__( 'Hint: Go to the child site, deactivate and reactivate the MainWP Child plugin and try again.', 'mainwp-child' );
            MainWP_Helper::instance()->error( esc_html__( 'This site already contains a link. Please deactivate and reactivate the MainWP plugin.', 'mainwp-child' ) . $hint );
        }

        /**
         * The installed version of WordPress.
         *
         * @global string $wp_version The installed version of WordPress.
         *
         * @uses \MainWP\Child\MainWP_Child::$version
         * @uses \MainWP\Child\MainWP_Helper::write()
         */
        global $wp_version;

        $information['version']   = MainWP_Child::$version;
        $information['wpversion'] = $wp_version;
        $information['wpe']       = MainWP_Helper::is_wp_engine() ? 1 : 0;
        $information['wphost']    = MainWP_Helper::get_wp_host();
        MainWP_Helper::write( $information );
    }

    /**
     * Check if ManageWP is installed.
     *
     * @param array $values Active plugins.
     * @return array $values Active plugins array with managewp/init.php appended.
     */
    public function default_option_active_plugins( $values ) {
        if ( ! is_array( $values ) ) {
            $values = array();
        }
        if ( ! in_array( 'managewp/init.php', $values ) ) {
            $values[] = 'managewp/init.php';
        }

        return $values;
    }

    /**
     * Get Child Site Stats.
     *
     * @param array $information Holder for return array.
     * @param bool  $exit_done Whether or not to exit the method. Default: true.
     *
     * @return array $information Child Site Stats.
     *
     * @uses \MainWP\Child\MainWP_Child_Stats::update_external_settings()
     * @uses \MainWP\Child\MainWP_Child_Stats::stats_get_info()
     * @uses \MainWP\Child\MainWP_Child_Stats::stats_wp_update()
     * @uses \MainWP\Child\MainWP_Child_Stats::stats_plugin_update()
     * @uses \MainWP\Child\MainWP_Child_Stats::stats_theme_update()
     * @uses \MainWP\Child\MainWP_Child_Stats::stats_translation_updates()
     * @uses \MainWP\Child\MainWP_Child_Stats::get_recent_number()
     * @uses \MainWP\Child\MainWP_Child_Stats::scan_dir()
     * @uses \MainWP\Child\MainWP_Child_Stats::stats_get_categories()
     * @uses \MainWP\Child\MainWP_Child_Stats::stats_get_total_size()
     * @uses \MainWP\Child\MainWP_Child_Stats::get_all_plugins_int()
     * @uses \MainWP\Child\MainWP_Child_Stats::get_all_themes_int()
     * @uses \MainWP\Child\MainWP_Child_Stats::get_health_check_site_status()
     * @uses \MainWP\Child\MainWP_Child_Stats::stats_others_data()
     * @uses \MainWP\Child\MainWP_Child_Stats::check_premium_updates()
     * @uses \MainWP\Child\MainWP_Child_Branding::save_branding_options()
     * @uses \MainWP\Child\MainWP_Child_Plugins_Check::may_outdate_number_change()
     * @uses \MainWP\Child\MainWP_Child_Comments::get_recent_comments()
     * @uses \MainWP\Child\MainWP_Child_Posts::get_recent_posts()
     * @uses \MainWP\Child\MainWP_Child_DB::get_size()
     * @uses \MainWP\Child\MainWP_Child_Users:::get_all_users_int()
     * @uses \MainWP\Child\MainWP_Child_Plugins_Check::get_plugins_outdate_info()
     * @uses \MainWP\Child\MainWP_Child_Themes_Check::get_themes_outdate_info()
     * @uses \MainWP\Child\MainWP_Security::get_stats_security()
     * @uses \MainWP\Child\MainWP_Connect::instance()::get_max_history()
     * @uses \MainWP\Child\MainWP_Utility::get_lasttime_backup()
     * @uses \MainWP\Child\MainWP_Utility::validate_mainwp_dir()
     * @uses \MainWP\Child\MainWP_Helper::update_option()
     * @uses \MainWP\Child\MainWP_Helper::set_limit()
     * @uses \MainWP\Child\MainWP_Helper::log_debug()
     * @uses \MainWP\Child\MainWP_Helper::write()
     * @uses \MainWP\Child\MainWP_Child_Branding::save_branding_options()
     * @uses \MainWP\Child\MainWP_Child_Comments::get_recent_comments()
     * @uses \MainWP\Child\MainWP_Child_DB::get_size()
     * @uses \MainWP\Child\MainWP_Child_Misc::get_security_stats()
     * @uses \MainWP\Child\MainWP_Child_Plugins_Check::may_outdate_number_change()
     * @uses \MainWP\Child\MainWP_Child_Plugins_Check::get_plugins_outdate_info()
     * @uses \MainWP\Child\MainWP_Child_Posts::get_all_posts()
     * @uses \MainWP\Child\MainWP_Child_Themes_Check::get_themes_outdate_info()
     * @uses \MainWP\Child\MainWP_Child_Users::get_all_users_int()
     * @uses \MainWP\Child\MainWP_Connect::get_max_history()
     */
    public function get_site_stats( $information = array(), $exit_done = true ) { //phpcs:ignore -- NOSONAR - complex.

        if ( $exit_done ) {
            $this->update_external_settings();
        }
        // phpcs:disable WordPress.Security.NonceVerification
        MainWP_Child_Branding::instance()->save_branding_options( 'branding_disconnected', '' );
        if ( isset( $_POST['server'] ) ) {
            $current_url = MainWP_Child_Keys_Manager::get_encrypted_option( 'mainwp_child_server' );
            if ( $current_url !== $_POST['server'] ) {
                MainWP_Child_Keys_Manager::update_encrypted_option( 'mainwp_child_server', ! empty( $_POST['server'] ) ? sanitize_text_field( wp_unslash( $_POST['server'] ) ) : '' ); //phpcs:ignore WordPress.Security.NonceVerification -- NOSONAR - ok.
            }
        }

        MainWP_Child_Plugins_Check::may_outdate_number_change();

        if ( isset( $_POST['child_actions_saved_days_number'] ) ) {
            $days_number = intval( $_POST['child_actions_saved_days_number'] );
            MainWP_Helper::update_option( 'mainwp_child_actions_saved_number_of_days', $days_number );
        }

        $others_sync = null;

        if ( $this->is_sync_data( 'othersData' ) && isset( $_POST['othersData'] ) ) {
            $others_sync = isset( $_POST['othersData'] ) ? json_decode( stripslashes( wp_unslash( $_POST['othersData'] ) ), true ) : array(); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            if ( ! is_array( $others_sync ) ) {
                $others_sync = array();
            }
            $this->stats_before_sync_data( $others_sync );
        }

        $this->stats_get_info( $information );

        include_once ABSPATH . '/wp-admin/includes/update.php'; // NOSONAR -- WP compatible.

        $timeout = 3 * 60 * 60;
        MainWP_Helper::set_limit( $timeout );

        if ( $this->is_sync_data( 'wp_updates' ) ) {
            // Check for new versions.
            $information['wp_updates'] = $this->stats_wp_update();
        }

        add_filter( 'default_option_active_plugins', array( &$this, 'default_option_active_plugins' ) );
        add_filter( 'option_active_plugins', array( &$this, 'default_option_active_plugins' ) );

        $premiumPlugins = array();
        $premiumThemes  = array();

        // First check for new premium updates.
        $this->check_premium_updates( $information, $premiumPlugins, $premiumThemes );

        remove_filter( 'default_option_active_plugins', array( &$this, 'default_option_active_plugins' ) );
        remove_filter( 'option_active_plugins', array( &$this, 'default_option_active_plugins' ) );

        if ( $this->is_sync_data( 'plugin_updates' ) ) {
            $information['plugin_updates'] = $this->stats_plugin_update( $premiumPlugins );
        }

        if ( $this->is_sync_data( 'theme_updates' ) ) {
            $information['theme_updates'] = $this->stats_theme_update( $premiumThemes );
        }

        if ( $this->is_sync_data( 'translation_updates' ) ) {
            $information['translation_updates'] = $this->stats_translation_updates();
        }

        if ( $this->is_sync_data( 'recent_comments' ) ) {
            $information['recent_comments'] = MainWP_Child_Comments::get_instance()->get_recent_comments( array( 'approve', 'hold' ), 5 );
        }

        if ( $this->is_sync_data( 'recent_posts' ) || $this->is_sync_data( 'recent_pages' ) ) {
            $recent_number               = $this->get_recent_number();
            $information['recent_posts'] = MainWP_Child_Posts::get_instance()->get_recent_posts( array( 'publish', 'draft', 'pending', 'trash', 'future' ), $recent_number );
            $information['recent_pages'] = MainWP_Child_Posts::get_instance()->get_recent_posts( array( 'publish', 'draft', 'pending', 'trash', 'future' ), $recent_number, 'page' );
        }

        if ( $this->is_sync_data( 'securityStats' ) ) {
            $information['securityIssues'] = MainWP_Security::get_stats_security();
        }

        if ( $this->is_sync_data( 'securityStats' ) ) {
            $information['securityStats'] = MainWP_Child_Misc::get_instance()->get_security_stats( true );
        }

        if ( $this->is_sync_data( 'directories' ) ) {
                // Directory listings!
            $information['directories'] = isset( $_POST['scan_dir'] ) && ! empty( $_POST['scan_dir'] ) ? $this->scan_dir( ABSPATH, 3 ) : '';
        }

        if ( $this->is_sync_data( 'categories' ) ) {
            $information['categories']      = $this->stats_get_categories( false ); // to compatible.
            $information['categories_list'] = $this->stats_get_categories();
        }

        if ( $this->is_sync_data( 'totalsize' ) ) {
            $totalsize = $this->stats_get_total_size();
            if ( ! empty( $totalsize ) ) {
                $information['totalsize'] = $totalsize;
            }
        }

        if ( $this->is_sync_data( 'dbsize' ) ) {
            $information['dbsize'] = MainWP_Child_DB::get_size();
        }

        $max_his                = MainWP_Connect::instance()->get_max_history();
        $auths                  = get_option( 'mainwp_child_auth' );
        $information['extauth'] = ( is_array( $auths ) && isset( $auths[ $max_his ] ) ? $auths[ $max_his ] : null );

        if ( $this->is_sync_data( 'plugins' ) ) {
            $information['plugins'] = $this->get_all_plugins_int( false );
        }

        if ( $this->is_sync_data( 'themes' ) ) {
            $information['themes'] = $this->get_all_themes_int( false );
        }

        if ( isset( $_POST['optimize'] ) && ( 1 === (int) $_POST['optimize'] ) && $this->is_sync_data( 'users' ) ) {
            $information['users'] = MainWP_Child_Users::get_instance()->get_all_users_int( 500 );
        }

        if ( $this->is_sync_data( 'primaryLasttimeBackup' ) && ! empty( $_POST['primaryBackup'] ) ) {
            $primary_bk = ! empty( $_POST['primaryBackup'] ) ? sanitize_text_field( wp_unslash( $_POST['primaryBackup'] ) ) : '';
            $last_time  = MainWP_Utility::get_lasttime_backup( $primary_bk );
            if ( false !== $last_time ) {
                $information['primaryLasttimeBackup'] = $last_time; // to fix overwrite other last time primary backup.
            }
        }

        if ( $this->is_sync_data( 'last_post_gmt' ) ) {
            $last_post = wp_get_recent_posts( array( 'numberposts' => absint( '1' ) ) );
            if ( isset( $last_post[0] ) ) {
                $last_post = $last_post[0];
            }
            if ( isset( $last_post ) && isset( $last_post['post_modified_gmt'] ) ) {
                $information['last_post_gmt'] = strtotime( $last_post['post_modified_gmt'] );
            }
        }
        $information['mainwpdir'] = ( MainWP_Utility::validate_mainwp_dir() ? 1 : - 1 );
        $information['uniqueId']  = MainWP_Helper::get_site_unique_id();

        if ( $this->is_sync_data( 'plugins_outdate_info' ) ) {
            $information['plugins_outdate_info'] = MainWP_Child_Plugins_Check::instance()->get_plugins_outdate_info();
        }

        if ( $this->is_sync_data( 'themes_outdate_info' ) ) {
            $information['themes_outdate_info'] = MainWP_Child_Themes_Check::instance()->get_themes_outdate_info();
        }

        if ( $this->is_sync_data( 'health_site_status' ) ) {
            $information['health_site_status'] = $this->get_health_check_site_status();
        }

        if ( $this->is_sync_data( 'child_site_actions_data' ) ) {
            $information['child_site_actions_data'] = MainWP_Child_Actions::get_actions_data();
        }

        if ( isset( $_POST['user'] ) ) {
            $user = get_user_by( 'login', sanitize_text_field( wp_unslash( $_POST['user'] ) ) );
            if ( $user && property_exists( $user, 'ID' ) && $user->ID ) {
                $information['admin_nicename']  = $user->data->user_nicename;
                $information['admin_useremail'] = $user->data->user_email;
            }
        }

        try {
            do_action( 'mainwp_child_site_stats' );
        } catch ( MainWP_Exception $e ) {
            MainWP_Helper::log_debug( $e->getMessage() );
        }

        if ( $this->is_sync_data( 'othersData' ) && isset( $_POST['othersData'] ) ) {
            $this->stats_others_data( $information );
        }

        if ( isset( $_POST['pingnonce'] ) ) {
            $nonce   = sanitize_text_field( wp_unslash( $_POST['pingnonce'] ) );
            $current = get_option( 'mainwp_child_pingnonce' );
            if ( 0 !== strcmp( $nonce, $current ) ) {
                MainWP_Helper::update_option( 'mainwp_child_pingnonce', $nonce );
            }
        }

        // still generate if regverify the connect user disabled pw auth.
        if ( ! empty( $_POST['sync_regverify'] ) ) {
            $information['regverify_info'] = MainWP_Connect::instance()->validate_register( false, 'generate' );
        }

        if ( $exit_done ) {
            MainWP_Helper::write( $information );
        }
        // phpcs:enable WordPress.WP.AlternativeFunctions
        return $information;
    }


    /**
     * Method is_sync_data().
     *
     * @param string $item Sync item.
     *
     * @return bool Sync or not.
     */
    public function is_sync_data( $item ) {

        if ( null !== $this->sync_data_list ) {
            $this->sync_data_list = $this->get_data_list_to_sync();
        }

        if ( ! is_array( $this->sync_data_list ) ) {
            $this->sync_data_list = array();
        }

        return ! isset( $this->sync_data_list[ $item ] ) || ( isset( $this->sync_data_list[ $item ] ) && 1 === (int) $this->sync_data_list[ $item ] );
    }

    /**
     * Method get_data_list_to_sync().
     *
     * @return array Data list to sync.
     */
    public function get_data_list_to_sync() {

        $sync_data_settings = get_option( 'mainwp_child_settings_sync_data' );

        if ( isset( $_POST['syncdata'] ) ) {

            $update_list = wp_unslash( $_POST['syncdata'] );
            $update      = false;

            if ( $update_list !== $sync_data_settings ) {
                $sync_data_settings = $update_list;
                $update             = true;
            }

            $sync_list = ! empty( $sync_data_settings ) ? json_decode( $sync_data_settings, true ) : array();

            if ( ! is_array( $sync_list ) ) {
                $sync_list = array();
            }

            if ( $update ) {
                MainWP_Helper::update_option( 'mainwp_child_settings_sync_data', wp_json_encode( $sync_list ) );
            }

            return $sync_list;
        }

        if ( false === $sync_data_settings ) {
            return array();
        }

        $sync_list = ! empty( $sync_data_settings ) ? json_decode( $sync_data_settings, true ) : array();

        if ( ! is_array( $sync_list ) ) {
            $sync_list = array();
        }
        return $sync_list;
    }

    /**
     * Process before sync data.
     *
     * @param array $others_data Others sync data.
     */
    private function stats_before_sync_data( $others_data ) {
        if ( isset( $others_data['users_number'] ) ) {
            $users_number = get_option( 'mainwp_child_sync_users_number', 0 );
            if ( (int) $users_number !== (int) $others_data['users_number'] ) {
                MainWP_Helper::update_option( 'mainwp_child_sync_users_number', intval( $others_data['users_number'] ) );
            }
        }
    }

    /**
     * Get other stats data.
     *
     * @param array $information Child Site Stats array.
     *
     * @uses \MainWP\Child\MainWP_Helper::update_option()
     * @uses \MainWP\Child\MainWP_Helper::log_debug()
     */
    private function stats_others_data( &$information ) {
        // phpcs:disable WordPress.Security.NonceVerification
        $othersData = isset( $_POST['othersData'] ) ? json_decode( stripslashes( wp_unslash( $_POST['othersData'] ) ), true ) : array(); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( ! is_array( $othersData ) ) {
            $othersData = array();
        }
        // phpcs:enable WordPress.WP.AlternativeFunctions
        try {
            $information = apply_filters_deprecated( 'mainwp-site-sync-others-data', array( $information, $othersData ), '4.0.7.1', 'mainwp_site_sync_others_data' ); // NOSONAR - no IP.
            $information = apply_filters( 'mainwp_site_sync_others_data', $information, $othersData );
        } catch ( MainWP_Exception $e ) {
            MainWP_Helper::log_debug( $e->getMessage() );
        }
    }

    /**
     * Translation update stats.
     *
     * @return array $results Returned results.
     */
    private function stats_translation_updates() { //phpcs:ignore -- NOSONAR - complex.
        $results = array();

        $translation_updates = wp_get_translation_updates();
        if ( ! empty( $translation_updates ) ) {
            foreach ( $translation_updates as $translation_update ) {
                $new_translation_update = array(
                    'type'     => $translation_update->type,
                    'slug'     => $translation_update->slug,
                    'language' => $translation_update->language,
                    'version'  => $translation_update->version,
                );
                if ( 'plugin' === $translation_update->type ) {
                    $all_plugins = get_plugins();
                    foreach ( $all_plugins as $file => $plugin ) {
                        $path = dirname( $file );
                        if ( $path === $translation_update->slug ) {
                            $new_translation_update['name'] = $plugin['Name'];
                            break;
                        }
                    }
                } elseif ( 'theme' === $translation_update->type ) {
                    $theme                          = wp_get_theme( $translation_update->slug );
                    $new_translation_update['name'] = $theme->name;
                } elseif ( ( 'core' === $translation_update->type ) && ( 'default' === $translation_update->slug ) ) {
                    $new_translation_update['name'] = 'WordPress core';
                }

                $results[] = $new_translation_update;
            }
        }
        return $results;
    }

    /**
     * Premium theme update stats.
     *
     * @param array $premiumThemes Array of premium themes.
     *
     * @return array $results Array of premium theme slugs.
     *
     * @uses MainWP_Child_Updates::get_instance()::upgrade_get_theme_updates()
     * @uses \MainWP\Child\MainWP_Child_Updates::upgrade_get_theme_updates()
     */
    private function stats_theme_update( $premiumThemes ) { //phpcs:ignore -- NOSONAR - complex.

        $results = array();

        if ( null !== $this->filterFunction ) {
            add_filter( 'pre_site_transient_update_themes', $this->filterFunction, 99 );
        }

        wp_update_themes();
        include_once ABSPATH . '/wp-admin/includes/theme.php'; // NOSONAR -- WP compatible.
        $theme_updates = MainWP_Child_Updates::get_instance()->upgrade_get_theme_updates();
        if ( is_array( $theme_updates ) ) {
            foreach ( $theme_updates as $slug => $theme_update ) {
                $name = ( is_array( $theme_update ) ? $theme_update['Name'] : $theme_update->Name );
                if ( in_array( $name, $premiumThemes ) ) {
                    continue;
                }
                $results[ $slug ] = $theme_update;
            }
        }
        if ( null !== $this->filterFunction ) {
            remove_filter( 'pre_site_transient_update_themes', $this->filterFunction, 99 );
        }

        // Fixes premium themes update.
        $cached_themes_update = get_site_transient( 'mainwp_update_themes_cached' );
        if ( is_array( $cached_themes_update ) && ( count( $cached_themes_update ) > 0 ) ) {

            foreach ( $cached_themes_update as $slug => $theme_update ) {
                $name = ( is_array( $theme_update ) ? $theme_update['Name'] : $theme_update->Name );
                if ( in_array( $name, $premiumThemes ) ) {
                    continue;
                }
                if ( isset( $results[ $slug ] ) ) {
                    continue;
                }
                $results[ $slug ] = $theme_update;
            }
        }

        return $results;
    }

    /**
     * Get Server Info stats & append to end of Child Site stats.
     *
     * @param array $information Child Site Stats.
     *
     * @uses MainWP_Child::$version
     * @uses MainWP_Child_Server_Information::get_php_memory_limit()
     * @uses MainWP_Child_Server_Information::get_my_sql_version()
     * @uses MainWP_Helper::is_wp_engine()
     * @uses MainWP_Helper::update_option()
     * @uses phpversion()
     * @uses \MainWP\Child\MainWP_Child::$version
     * @uses \MainWP\Child\MainWP_Child_Server_Information::get_php_memory_limit()
     * @uses \MainWP\Child\MainWP_Child_Server_Information::get_my_sql_version()
     */
    private function stats_get_info( &$information ) {

        /**
         * The installed version of WordPress.
         *
         * @global string $wp_version The installed version of WordPress.
         *
         * @uses \MainWP\Child\MainWP_Child::$version
         * @uses \MainWP\Child\MainWP_Helper::is_wp_engine()
         * @uses \MainWP\Child\MainWP_Helper::is_ssl_enabled()
         * @uses \MainWP\Child\MainWP_Helper::update_option()
         */
        global $wp_version;

        $information['version']   = MainWP_Child::$version;
        $information['wpversion'] = $wp_version;
        $information['siteurl']   = get_option( 'siteurl' );
        $information['wpe']       = MainWP_Helper::is_wp_engine() ? 1 : 0;
        $information['wphost']    = MainWP_Helper::get_wp_host();

        $theme_name               = wp_get_theme()->get( 'Name' );
        $information['site_info'] = array(
            'wpversion'             => $wp_version,
            'debug_mode'            => ( defined( 'WP_DEBUG' ) && true === WP_DEBUG ) ? true : false,
            'phpversion'            => phpversion(),
            'child_version'         => MainWP_Child::$version,
            'memory_limit'          => MainWP_Child_Server_Information::get_php_memory_limit(),
            'mysql_version'         => MainWP_Child_Server_Information::get_my_sql_version(),
            'db_size'               => MainWP_Child_Server_Information_Base::get_db_size(),
            'themeactivated'        => $theme_name,
            'ip'                    => isset( $_SERVER['SERVER_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_ADDR'] ) ) : '',
            'child_curl_version'    => MainWP_Child_Server_Information_Base::get_curl_version(),
            'child_openssl_version' => MainWP_Child_Server_Information_Base::get_curl_ssl_version(),
            'site_lang'             => get_locale(),
            'site_public'           => (int) get_option( 'blog_public', 0 ),
        );
    }

    /**
     * Get WordPress update stats.
     *
     * @return string|bool|null Return TRUE if the relationship is the one specified by the operator <=,
     *  FALSE otherwise, null by default.
     */
    public function stats_wp_update() {

        /**
         * The installed version of WordPress.
         *
         * @global string $wp_version The installed version of WordPress.
         */
        global $wp_version;

        $result = null;

        // Check for new versions.
        if ( null !== $this->filterFunction ) {
            add_filter( 'pre_site_transient_update_core', $this->filterFunction, 99 );
        }
        if ( null !== $this->filterFunction ) {
            add_filter( 'pre_transient_update_core', $this->filterFunction, 99 );
        }

        MainWP_System::wp_mainwp_version_check();

        $core_updates = get_core_updates();

        if ( is_array( $core_updates ) && count( $core_updates ) > 0 ) {
            foreach ( $core_updates as $core_update ) {
                if ( 'latest' === $core_update->response ) {
                    break;
                }
                if ( 'upgrade' === $core_update->response && version_compare( $wp_version, $core_update->current, '<=' ) ) {
                    $result = $core_update->current;
                }
            }
        }

        if ( null !== $this->filterFunction ) {
            remove_filter( 'pre_site_transient_update_core', $this->filterFunction, 99 );
        }

        if ( null !== $this->filterFunction ) {
            remove_filter( 'pre_transient_update_core', $this->filterFunction, 99 );
        }

        return $result;
    }

    /**
     * Check for premium updates.
     *
     * @param array $information Child Site stats.
     * @param array $premiumPlugins Active premium plugins.
     * @param array $premiumThemes Active premium themes.
     *
     * @uses \MainWP\Child\MainWP_Helper::update_option()
     */
    private function check_premium_updates( &$information, &$premiumPlugins, &$premiumThemes ) { //phpcs:ignore -- NOSONAR - complex.

        // First check for new premium updates.
        $update_check = apply_filters( 'mwp_premium_update_check', array() );
        if ( ! empty( $update_check ) ) {
            foreach ( $update_check as $updateFeedback ) {
                if ( is_array( $updateFeedback['callback'] ) && isset( $updateFeedback['callback'][0] ) && isset( $updateFeedback['callback'][1] ) ) {
                    call_user_func( array( $updateFeedback['callback'][0], $updateFeedback['callback'][1] ) );
                } elseif ( is_string( $updateFeedback['callback'] ) ) {
                    call_user_func( $updateFeedback['callback'] );
                }
            }
        }

        $informationPremiumUpdates = apply_filters( 'mwp_premium_update_notification', array() );
        $premiumPlugins            = array();
        $premiumThemes             = array();

        if ( is_array( $informationPremiumUpdates ) ) {
            $premiumUpdates                  = array();
            $informationPremiumUpdatesLength = count( $informationPremiumUpdates );
            for ( $i = 0; $i < $informationPremiumUpdatesLength; $i++ ) {
                if ( ! isset( $informationPremiumUpdates[ $i ]['new_version'] ) ) {
                    continue;
                }
                $slug = ( isset( $informationPremiumUpdates[ $i ]['slug'] ) ? $informationPremiumUpdates[ $i ]['slug'] : $informationPremiumUpdates[ $i ]['Name'] );

                if ( 'plugin' === $informationPremiumUpdates[ $i ]['type'] ) {
                    $premiumPlugins[] = $slug;
                } elseif ( 'theme' === $informationPremiumUpdates[ $i ]['type'] ) {
                    $premiumThemes[] = $slug;
                }

                $new_version = $informationPremiumUpdates[ $i ]['new_version'];

                unset( $informationPremiumUpdates[ $i ]['old_version'] );
                unset( $informationPremiumUpdates[ $i ]['new_version'] );

                if ( ! isset( $information['premium_updates'] ) ) {
                    $information['premium_updates'] = array();
                }

                $information['premium_updates'][ $slug ]           = $informationPremiumUpdates[ $i ];
                $information['premium_updates'][ $slug ]['update'] = (object) array(
                    'new_version' => $new_version,
                    'premium'     => true,
                    'slug'        => $slug,
                );

                if ( ! in_array( $slug, $premiumUpdates ) ) {
                    $premiumUpdates[] = $slug;
                }
            }
            MainWP_Helper::update_option( 'mainwp_premium_updates', $premiumUpdates );
        }
    }

    /**
     * Premium plugin update stats.
     *
     * @param array $premiumPlugins Active premium plugins.
     * @return array $results Array of premium plugin slugs.
     */
    private function stats_plugin_update( $premiumPlugins ) { //phpcs:ignore -- NOSONAR - complex.

        $results = array();

        if ( null !== $this->filterFunction ) {
            add_filter( 'pre_site_transient_update_plugins', $this->filterFunction, 99 );
        }

        // to fix conflict.
        MainWP_Utility::remove_filters_by_hook_name( 'update_plugins_oxygenbuilder.com', 10 );

        /**
         * Retrieve the name of the current filter or action.
         *
         * @global array $wp_current_filter Current filter.
         */
        global $wp_current_filter;

        $wp_current_filter[] = 'load-plugins.php'; // phpcs:ignore -- Required to achieve desired results, pull request solutions appreciated.

        wp_update_plugins();
        include_once ABSPATH . '/wp-admin/includes/plugin.php'; // NOSONAR -- WP compatible.

        $plugin_updates = get_plugin_updates();
        if ( is_array( $plugin_updates ) ) {

            foreach ( $plugin_updates as $slug => $plugin_update ) {
                if ( in_array( $plugin_update->Name, $premiumPlugins ) ) {
                    continue;
                }

                // Fixes incorrect info.
                if ( ! property_exists( $plugin_update, 'update' ) || ! property_exists( $plugin_update->update, 'new_version' ) || empty( $plugin_update->update->new_version ) ) {
                    continue;
                }
                $plugin_update->active = is_plugin_active( $slug ) ? 1 : 0;
                $results[ $slug ]      = $plugin_update;
            }
        }

        if ( null !== $this->filterFunction ) {
            remove_filter( 'pre_site_transient_update_plugins', $this->filterFunction, 99 );
        }

        // Fixes premium plugins update.
        $cached_plugins_update = get_site_transient( 'mainwp_update_plugins_cached' );
        if ( is_array( $cached_plugins_update ) && ( count( $cached_plugins_update ) > 0 ) ) {
            foreach ( $cached_plugins_update as $slug => $plugin_update ) {

                // Fixes incorrect info.
                if ( ! property_exists( $plugin_update, 'new_version' ) || empty( $plugin_update->new_version ) ) { // may do not need to check this?
                    // Fixes some premiums update info.
                    if ( property_exists( $plugin_update, 'update' ) ) {
                        if ( ! property_exists( $plugin_update->update, 'new_version' ) || empty( $plugin_update->update->new_version ) ) {
                            continue;
                        }
                    } else {
                        continue;
                    }
                }

                if ( ! isset( $results[ $slug ] ) ) {
                    $plugin_update->active = is_plugin_active( $slug ) ? 1 : 0;
                    $results[ $slug ]      = $plugin_update;
                }
            }
        }

        return $results;
    }

    /**
     * Check if found plugins updates.
     *
     * @return bool found plugins updates or not.
     */
    public function found_plugins_updates() {
        $premiums = array();
        $updates  = $this->stats_plugin_update( $premiums );
        return ! empty( $premiums ) || ! empty( $updates ) ? true : false;
    }


    /**
     * Check if found themes updates.
     *
     * @return bool found themes updates or not.
     */
    public function found_themes_updates() {
        $premiums = array();
        $updates  = $this->stats_theme_update( $premiums );
        return ! empty( $premiums ) || ! empty( $updates ) ? true : false;
    }

    /**
     * Check if found inactive plugins.
     *
     * @return bool found inactive plugins or not.
     */
    public function found_inactive_plugins() {
        return $this->get_all_plugins_int( false, '', '', false, true ) ? true : false;
    }

    /**
     * Check if found inactive themes.
     *
     * @return bool found inactive themes or not.
     */
    public function is_good_themes() {
        $founds = $this->get_all_themes_int( false, '', '', false, true );

        $wp_themes = array(
            'twentytwelve',
            'twentythirteen',
            'twentyfourteen',
            'twentyfifteen',
            'twentysixteen',
            'twentyseventeen',
            'twentynineteen',
            'twentytwenty',
            'twentytwentyone',
            'twentytwentytwo',
            'twentytwentythree',
            'twentytwentyfour',
            'twentytwentyfive',
        );

        $is_bad = true;

        if ( ! empty( $founds ) ) {
            if ( 1 === count( $founds ) ) {
                if ( $founds[0]['parent'] || in_array( $founds[0]['slug'], $wp_themes ) ) {
                    $is_bad = false;
                }
            } elseif ( 2 === count( $founds ) ) {
                $total_parent = $founds[0]['parent'] + $founds[1]['parent'];
                if ( 1 === $total_parent && ( in_array( $founds[0]['slug'], $wp_themes ) || in_array( $founds[1]['slug'], $wp_themes ) ) ) {
                    $is_bad = false;
                }
            }
        } else {
            $is_bad = false;
        }

        return $is_bad ? 0 : 1; // bad is not good else good.
    }


    /**
     * Ger category stats.
     *
     * @param bool $full_data to support compatible data.
     *
     * @return array $categories Available Child Site Categories.
     */
    private function stats_get_categories( $full_data = true ) {
         // phpcs:disable WordPress.Security.NonceVerification
        $number = isset( $_POST['categories_number'] ) ? intval( $_POST['categories_number'] ) : 300;
         // phpcs:ignore WordPress.Security.NonceVerification
        if ( 300 >= $number ) {
            $number = 300;
        }
        $cats = get_categories(
            array(
                'hide_empty'   => 0,
                'hierarchical' => true,
                'number'       => $number,
            )
        );

        $categories = array();

        foreach ( $cats as $cat ) {
            if ( $full_data ) {
                $categories[] = array(
                    'term_id'  => $cat->term_id,
                    'name'     => $cat->name,
                    'slug'     => $cat->slug,
                    'taxonomy' => $cat->taxonomy,
                    'parent'   => $cat->parent,
                );
            } else {
                $categories[] = $cat->name;
            }
        }
        return $categories;
    }

    /**
     * Get total size of Child Site installation.
     *
     * @uses MainWP_Child_Stats::get_total_file_size()
     *
     * @return float|int|null $total Total file size or 0 or null.
     */
    private function stats_get_total_size() {
        $total = null;

        $get_file_size        = apply_filters_deprecated( 'mainwp-child-get-total-size', array( true ), '4.0.7.1', 'mainwp_child_get_total_size' ); // NOSONAR - no IP.
        $get_file_size        = apply_filters( 'mainwp_child_get_total_size', $get_file_size );
        $forced_get_file_size = apply_filters( 'mainwp_child_forced_get_total_size', false );

        if ( $forced_get_file_size || ( $get_file_size && isset( $_POST['cloneSites'] ) && ( '0' !== $_POST['cloneSites'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            $max_exe = ini_get( 'max_execution_time' );
            if ( $forced_get_file_size || $max_exe > 20 ) {
                $total = $this->get_total_file_size();
            }
        }

        return $total;
    }

    /**
     * Get recent number.
     *
     * @return int $recent_number Recent number.
     */
    private function get_recent_number() {
         // phpcs:disable WordPress.Security.NonceVerification
        if ( isset( $_POST ) && isset( $_POST['recent_number'] ) ) {
            $recent_number = intval( wp_unslash( $_POST['recent_number'] ) );
            if ( (int) get_option( 'mainwp_child_recent_number', 5 ) !== $recent_number ) {
                update_option( 'mainwp_child_recent_number', $recent_number );
            }
        } else {
            $recent_number = get_option( 'mainwp_child_recent_number', 5 );
        }

        if ( $recent_number <= 0 || $recent_number > 30 ) {
            $recent_number = 5;
        }
         // phpcs:enable WordPress.WP.AlternativeFunctions
        return $recent_number;
    }


    /**
     * Update options: mainwp_child_clone_sites, mainwp_child_siteid, mainwp_child_pluginDir.
     *
     * @uses \MainWP\Child\MainWP_Helper::update_option()
     */
    public function update_external_settings() { //phpcs:ignore -- NOSONAR - complex.
        // phpcs:disable WordPress.Security.NonceVerification
        if ( isset( $_POST['cloneSites'] ) ) {
            if ( '0' !== $_POST['cloneSites'] ) {
                $arr = isset( $_POST['cloneSites'] ) ? json_decode( urldecode( wp_unslash( $_POST['cloneSites'] ) ), 1 ) : ''; //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                MainWP_Helper::update_option( 'mainwp_child_clone_sites', ( ! is_array( $arr ) ? array() : $arr ) );
            } else {
                MainWP_Helper::update_option( 'mainwp_child_clone_sites', '0' );
            }
        }

        if ( isset( $_POST['siteId'] ) ) {
            MainWP_Helper::update_option( 'mainwp_child_siteid', intval( wp_unslash( $_POST['siteId'] ) ) );
        }

        if ( isset( $_POST['pluginDir'] ) ) {
            if ( get_option( 'mainwp_child_pluginDir' ) !== $_POST['pluginDir'] ) {
                MainWP_Helper::update_option( 'mainwp_child_pluginDir', ( ! empty( $_POST['pluginDir'] ) ? wp_unslash( $_POST['pluginDir'] ) : '' ), 'yes' ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            }
        } elseif ( false !== get_option( 'mainwp_child_pluginDir' ) ) {
            MainWP_Helper::update_option( 'mainwp_child_pluginDir', false, 'yes' );
        }
        // phpcs:enable WordPress.WP.AlternativeFunctions
    }

    /**
     * Get total size of wp_content directory.
     *
     * @param string $directory WordPress content directory.
     * @return float|int Return $size or 0.
     *
     * @uses \MainWP\Child\MainWP_Helper::funct_exists()
     * @uses \MainWP\Child\MainWP_Helper::get_mainwp_dir()
     * @uses \MainWP\Child\MainWP_Helper::ctype_digit()
     */
    public function get_total_file_size( $directory = WP_CONTENT_DIR ) { //phpcs:ignore -- NOSONAR - complex.
        try {
            if ( MainWP_Helper::funct_exists( 'popen' ) ) {
                $uploadDir   = MainWP_Helper::get_mainwp_dir();
                $uploadDir   = $uploadDir[0];
                $popenHandle = popen( 'du -s ' . $directory . ' --exclude "' . str_replace( ABSPATH, '', $uploadDir ) . '"', 'r' ); // phpcs:ignore -- run if enabled.
                if ( 'resource' === gettype( $popenHandle ) ) {
                    $size = fread( $popenHandle, 1024 ); //phpcs:ignore -- custom read file.
                    pclose( $popenHandle );
                    $size = substr( $size, 0, strpos( $size, "\t" ) );
                    if ( $size && MainWP_Helper::ctype_digit( $size ) ) {
                        return $size / 1024;
                    }
                }
            }

            if ( MainWP_Helper::funct_exists( 'shell_exec' ) ) {
                $uploadDir = MainWP_Helper::get_mainwp_dir();
                $uploadDir = $uploadDir[0];
                $size      = shell_exec( 'du -s ' . $directory . ' --exclude "' . str_replace( ABSPATH, '', $uploadDir ) . '"' ); // phpcs:ignore -- run if enabled.
                if ( null !== $size ) {
                    $size = substr( $size, 0, strpos( $size, "\t" ) );
                    if ( $size && MainWP_Helper::ctype_digit( $size ) ) {
                        return $size / 1024;
                    }
                }
            }
            if ( class_exists( '\COM' ) ) {
                $obj = new \COM( 'scripting.filesystemobject' );

                if ( is_object( $obj ) ) {
                    $ref = $obj->getfolder( $directory );

                    $size = $ref->size;

                    $obj = null;
                    if ( MainWP_Helper::ctype_digit( $size ) ) {
                        return $size / 1024;
                    }
                }
            }
            // to fix for window host, performance not good?
            if ( class_exists( '\RecursiveIteratorIterator' ) ) {
                $size = 0;
                foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $directory ) ) as $file ) {
                    try {
                        $size += $file->getSize();
                    } catch ( \Exception $e ) {
                        // prevent error some hosts.
                    }
                }
                if ( $size && MainWP_Helper::ctype_digit( $size ) ) {
                    return $size / 1024 / 1024;
                }
            }
            return 0;
        } catch ( MainWP_Exception $e ) {
            return 0;
        }
    }

    /**
     * Scan directory.
     *
     * @param string $pDir Directory to scan.
     * @param string $pLvl How deep to scan.
     *
     * @uses MainWP_Child_Stats::scan_dir()
     * @uses MainWP_Child_Stats::int_scan_dir()
     *
     * @return array|null $output|$files
     */
    public function scan_dir( $pDir, $pLvl ) { //phpcs:ignore -- NOSONAR - complex.
        $output = array();
        if ( file_exists( $pDir ) && is_dir( $pDir ) ) {
            if ( 'logs' === basename( $pDir ) ) {
                return empty( $output ) ? null : $output;
            }
            if ( 0 === $pLvl ) {
                return empty( $output ) ? null : $output;
            }
            $files = $this->int_scan_dir( $pDir );
            if ( $files ) {
                foreach ( $files as $file ) {
                    if ( ( '.' === $file ) || ( '..' === $file ) ) {
                        continue;
                    }
                    $newDir = $pDir . $file . DIRECTORY_SEPARATOR;
                    if ( is_dir( $newDir ) ) {
                        $output[ $file ] = $this->scan_dir( $newDir, $pLvl - 1, false );
                    }
                }

                unset( $files );
                $files = null;
            }
        }

        return empty( $output ) ? null : $output;
    }

    /**
     * Initiate directory scan.
     *
     * @param string $dir Directory to scan.
     *
     * @return array|bool $out|FALSE Returns the entry name on success or FALSE on failure.
     */
    public function int_scan_dir( $dir ) {
        $dh = opendir( $dir );
        if ( is_dir( $dir ) && $dh ) {
            $cnt  = 0;
            $out  = array();
            $file = readdir( $dh );
            while ( false !== $file ) {
                $newDir = $dir . $file . DIRECTORY_SEPARATOR;
                if ( ! is_dir( $newDir ) ) {
                    $file = readdir( $dh );
                    continue;
                }

                $out[] = $file;
                $file  = readdir( $dh );

                if ( $cnt++ > 10 ) {
                    break;
                }
            }
            closedir( $dh );

            return $out;
        }

        return false;
    }

    /**
     * Get all themes.
     *
     * @uses \MainWP\Child\MainWP_Child_Stats::get_all_themes_int()
     * @uses \MainWP\Child\MainWP_Helper::write()
     */
    public function get_all_themes() {
        // phpcs:disable WordPress.Security.NonceVerification
        $keyword     = isset( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : '';
        $status      = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';
        $filter      = isset( $_POST['filter'] ) ? sanitize_text_field( wp_unslash( $_POST['filter'] ) ) : true;
        $un_criteria = isset( $_POST['not_criteria'] ) && ! empty( $_POST['not_criteria'] ) ? true : false;
        $not_int     = isset( $_POST['not_installed'] ) && ! empty( $_POST['not_installed'] ) ? true : false;
        // phpcs:enable WordPress.WP.AlternativeFunctions

        $rslt         = array();
        $rslt['data'] = $this->get_all_themes_int( $filter, $keyword, $status, $un_criteria );
        if ( $not_int && empty( $rslt['data'] ) ) {
            $rslt['installed_themes'] = $this->get_all_themes_int( false ); // to list installed themes.
        }
        MainWP_Helper::write( $rslt );
    }

    /**
     * Initiate get all themes.
     *
     * @param string  $filter Sites filter field.
     * @param string  $keyword Keyword Search field.
     * @param string  $status Active or Inactive filed.
     * @param boolean $get_un_criteria Get criteria or un-criteria items.
     * @param boolean $check_inactive_only To check inactive themes only.
     *
     * @return array|bool $rslt Returned themes results.
     */
    public function get_all_themes_int( $filter, $keyword = '', $status = '', $get_un_criteria = false, $check_inactive_only = false ) { //phpcs:ignore -- NOSONAR - complex.
        $rslt   = array();
        $themes = wp_get_themes();

        $found_inactive = array();

        if ( is_array( $themes ) ) {
            $theme_name  = wp_get_theme()->get( 'Name' );
            $parent_name = '';
            $parent      = wp_get_theme()->parent();
            if ( $parent ) {
                $parent_name = $parent->get( 'Name' );
            }
            foreach ( $themes as $theme ) {
                $out                  = array();
                $out['name']          = $theme->get( 'Name' );
                $out['title']         = $theme->display( 'Name', true, false );
                $out['description']   = $theme->display( 'Description', true, false );
                $out['version']       = $theme->display( 'Version', true, false );
                $out['active']        = ( $theme->get( 'Name' ) === $theme_name ) ? 1 : 0;
                $out['slug']          = $theme->get_stylesheet();
                $out['parent_active'] = ( $parent_name === $out['name'] ) ? 1 : 0;

                if ( $parent_name === $out['name'] ) {
                    $out['parent_active'] = 1;
                    $out['child_theme']   = $theme_name;
                } else {
                    $out['parent_active'] = 0;
                }

                if ( $parent && $out['name'] === $theme_name ) {
                    $out['child_active'] = 1; // actived child theme.
                }

                $rslt[] = $out;

                if ( empty( $out['active'] ) ) {
                    $found_inactive[] = array(
                        'name'   => $out['name'],
                        'slug'   => $out['slug'],
                        'parent' => $out['parent_active'],
                    );
                }
            }
        }

        if ( $check_inactive_only ) {
            return $found_inactive;
        }

        $multi_kws = explode( ',', $keyword );
        $multi_kws = array_filter( array_map( 'trim', $multi_kws ) );

        $results = array();

        foreach ( $rslt as $out ) {

            $get_it = false;

            if ( ! $filter ) {
                if ( '' === $keyword || ( ! $get_un_criteria && $this->multi_find_keywords( $out['title'], $multi_kws ) ) || ( $get_un_criteria && ! $this->multi_find_keywords( $out['title'], $multi_kws ) ) ) {
                    $get_it = true;
                }
            } else {
                $act_status = 'active' === $status ? 1 : 0;
                if ( $act_status === (int) $out['active'] && ( '' === $keyword || ( ! $get_un_criteria && $this->multi_find_keywords( $out['title'], $multi_kws ) ) || ( $get_un_criteria && ! $this->multi_find_keywords( $out['title'], $multi_kws ) ) ) ) {
                    $get_it = true;
                }
            }

            if ( ! $get_it ) {
                continue;
            }
            $results[] = $out;
        }

        return $results;
    }

    /**
     * Get all Plugins.
     *
     * @uses \MainWP\Child\MainWP_Child_Stats::get_all_plugins_int()
     * @uses \MainWP\Child\MainWP_Helper::write()
     */
    public function get_all_plugins() {
        // phpcs:disable WordPress.Security.NonceVerification
        $keyword     = isset( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : '';
        $status      = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';
        $filter      = isset( $_POST['filter'] ) ? sanitize_text_field( wp_unslash( $_POST['filter'] ) ) : true;
        $un_criteria = isset( $_POST['not_criteria'] ) && ! empty( $_POST['not_criteria'] ) ? true : false;
        $not_int     = isset( $_POST['not_installed'] ) && ! empty( $_POST['not_installed'] ) ? true : false;
        // phpcs:enable WordPress.WP.AlternativeFunctions

        $rslt         = array();
        $rslt['data'] = $this->get_all_plugins_int( $filter, $keyword, $status, $un_criteria );
        if ( $not_int && empty( $rslt['data'] ) ) {
            $rslt['installed_plugins'] = $this->get_all_plugins_int( false ); // to list installed plugins.
        }
        MainWP_Helper::write( $rslt );
    }

    /**
     * Initiate get all plugins.
     *
     * @param string  $filter Sites filter field.
     * @param string  $keyword Keyword Search field.
     * @param string  $status Active or Inactive filed.
     * @param boolean $get_un_criteria Get criteria or un-criteria items.
     * @param boolean $check_inactive_only To check inactive plugins only.
     *
     * @return array|bool $rslt Returned results.
     */
    public function get_all_plugins_int( $filter, $keyword = '', $status = '', $get_un_criteria = false, $check_inactive_only = false ) { //phpcs:ignore -- NOSONAR - complex.
        if ( ! function_exists( 'get_plugins' ) ) {
            include_once ABSPATH . 'wp-admin/includes/plugin.php'; // NOSONAR -- WP compatible.
        }

        /**
         * MainWP Child instance.
         *
         * @global object
         */
        global $mainWPChild;

        $rslt           = array();
        $plugins        = get_plugins();
        $found_inactive = false;
        if ( is_array( $plugins ) ) {
            foreach ( $plugins as $pluginslug => $plugin ) {
                $out                = array();
                $out['mainwp']      = ( $pluginslug === $mainWPChild->plugin_slug ? 'T' : 'F' );
                $out['name']        = $plugin['Name'];
                $out['slug']        = $pluginslug;
                $out['description'] = $plugin['Description'];
                $out['version']     = $plugin['Version'];
                $out['active']      = is_plugin_active( $pluginslug ) ? 1 : 0;
                $rslt[]             = $out;

                if ( 0 === $out['active'] ) {
                    $found_inactive = true;
                }
            }
        }

        if ( $check_inactive_only ) {
            return $found_inactive;
        }

        $muplugins = get_mu_plugins();
        if ( is_array( $muplugins ) ) {
            foreach ( $muplugins as $pluginslug => $plugin ) {
                $out                = array();
                $out['mainwp']      = ( $pluginslug === $mainWPChild->plugin_slug ? 'T' : 'F' );
                $out['name']        = $plugin['Name'];
                $out['slug']        = $pluginslug;
                $out['description'] = $plugin['Description'];
                $out['version']     = $plugin['Version'];
                $out['active']      = 1;
                $out['mu']          = 1;
                $rslt[]             = $out;
            }
        }

        $multi_kws = explode( ',', $keyword );
        $multi_kws = array_filter( array_map( 'trim', $multi_kws ) );

        $results = array();

        foreach ( $rslt as $out ) {

            $get_it = false;

            if ( ! $filter ) {
                if ( '' === $keyword || ( ! $get_un_criteria && $this->multi_find_keywords( $out['name'], $multi_kws ) ) || ( $get_un_criteria && ! $this->multi_find_keywords( $out['name'], $multi_kws ) ) ) {
                    $get_it = true;
                }
            } else {
                $act_status = 'active' === $status ? 1 : 0;
                if ( $act_status === (int) $out['active'] && ( '' === $keyword || ( ! $get_un_criteria && $this->multi_find_keywords( $out['name'], $multi_kws ) ) || ( $get_un_criteria && ! $this->multi_find_keywords( $out['name'], $multi_kws ) ) ) ) {
                    $get_it = true;
                }
            }

            if ( ! $get_it ) {
                continue;
            }

            $results[] = $out;

        }

        return $results;
    }

    /**
     * Find for multi keywords.
     *
     * @param string $name_str string find on.
     * @param array  $words Array string input.
     * @return bool True|False.
     */
    public function multi_find_keywords( $name_str, $words = array() ) {
        if ( ! is_array( $words ) ) {
            return false;
        }
        foreach ( $words as $word ) {
            if ( stristr( $name_str, $word ) ) {
                return true;

            }
        }
        return false;
    }

    /**
     * Get WP Site Health issues.
     *
     * @return array $issue_counts Returned issues.
     */
    public function get_health_check_site_status() {
        $get_issues   = get_transient( 'health-check-site-status-result' );
        $issue_counts = array();
        if ( false !== $get_issues ) {
            $issue_counts = json_decode( $get_issues, true );
        }
        if ( ! is_array( $issue_counts ) || ! $issue_counts ) {
            $issue_counts = array(
                'good'        => 0,
                'recommended' => 0,
                'critical'    => 0,
            );
        }
        return $issue_counts;
    }
}
