<?php
/**
 * MainWP UpdraftPlus
 *
 * MainWP UpdraftPlus Extension handler.
 * Extension URL: https://mainwp.com/extension/updraftplus/
 *
 * @package MainWP\Child
 *
 * Credits
 *
 * Plugin-Name: UpdraftPlus - Backup/Restore
 * Plugin URI: https://updraftplus.com
 * Author: UpdraftPlus.Com, DavidAnderson
 * Author URI: https://updraftplus.com
 * License: GPLv3 or later
 *
 * The code is used for the MainWP UpdraftPlus Extension
 * Extension URL: https://mainwp.com/extension/updraftplus/
 */

// phpcs:disable -- Third party credit.

namespace MainWP\Child;

/**
 * Class MainWP_Child_Updraft_Plus_Backups
 */
class MainWP_Child_Updraft_Plus_Backups {

    /**
     * Public static variable to hold the single instance of MainWP_Child_Updraft_Plus_Backups.
     *
     * @var mixed Default null
     */
    public static $instance = null;

    /** @var bool Whether or not UpdraftPlus WordPress plugin is installed. Default: false.*/
    public $is_plugin_installed = false;

    /**
     * Create a public static instance of MainWP_Child_Updraft_Plus_Backups.
     *
     * @return MainWP_Child_Updraft_Plus_Backups|null
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * MainWP_Child_Updraft_Plus_Backups constructor.
     *
     * Run any time class is called.
     */
    public function __construct() {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        if ( is_plugin_active( 'updraftplus/updraftplus.php' ) && defined( 'UPDRAFTPLUS_DIR' ) ) {
            $this->is_plugin_installed = true;
        }

        if ( ! $this->is_plugin_installed ) {
            return;
        }

        add_filter( 'mainwp_site_sync_others_data', array( $this, 'sync_others_data' ), 10, 2 );
        add_filter( 'updraftplus_save_last_backup', array( __CLASS__, 'hook_updraft_plus_save_last_backup' ) );
    }

    /**
     * Hook UpdraftPlus save last backup.
     *
     * @param array $last_backup Backup array.
     *
     * @return array $last_backup Return response array.
     *
     * @uses \MainWP\Child\MainWP_Utility::update_lasttime_backup()
     */
    public static function hook_updraft_plus_save_last_backup( $last_backup ) {
        if ( ! is_array( $last_backup ) ) {
            return $last_backup;
        }

        if ( isset( $last_backup['backup_time'] ) ) {
            $backup_time = $last_backup['backup_time'];
            if ( $last_backup['success'] ) {
                MainWP_Utility::update_lasttime_backup( 'updraftplus', $backup_time );
            }
        }
        return $last_backup;
    }

    /**
     * Sync other data from $data[] and merge with $information[]
     *
     * @param array $information Returned response array for MainWP BackWPup Extension actions.
     * @param array $data Other data to sync to $information array.
     *
     * @return array $information Returned information array with both sets of data.
     *
     * @uses MainWP_Child_Updraft_Plus_Backups::is_plugin_installed()
     * @uses MainWP_Child_Updraft_Plus_Backups::is_plugin_installed()
     * @uses \Exception()
     */
    public function sync_others_data( $information, $data = array() ) {
        try {
            if ( isset( $data['syncUpdraftData'] ) ) {
                $info = $data['syncUpdraftData'];
                if ( $this->is_plugin_installed ) {
                    $with_hist = true;
                    if ( version_compare( $info, '1.7', '>=' ) ) {
                        $with_hist = false;
                    }
                    $information['syncUpdraftData'] = $this->get_sync_data( $with_hist );
                }
            }
            if ( isset( $data['sync_Updraftvault_quota_text'] ) && $data['sync_Updraftvault_quota_text'] ) {
                if ( $this->is_plugin_installed ) {
                    $information['sync_Updraftvault_quota_text'] = $this->connected_html();
                }
            }
        } catch ( \Exception $e ) {
            // ok.
        }

        return $information;
    }

    /**
     * MainWP UpdraftPlus Extension actions.
     *
     * @uses \MainWP\Child\MainWP_Child_Updraft_Plus_Backups::is_plugin_installed()
     * @uses \MainWP\Child\MainWP_Child_Updraft_Plus_Backups::required_files()
     * @uses \MainWP\Child\MainWP_Child_Updraft_Plus_Backups::set_showhide()
     * @uses \MainWP\Child\MainWP_Child_Updraft_Plus_Backups::save_settings()
     * @uses \MainWP\Child\MainWP_Child_Updraft_Plus_Backups::addons_connect()
     * @uses \MainWP\Child\MainWP_Child_Updraft_Plus_Backups::backup_now()
     * @uses \MainWP\Child\MainWP_Child_Updraft_Plus_Backups::activejobs_list()
     * @uses \MainWP\Child\MainWP_Child_Updraft_Plus_Backups::diskspaceused()
     * @uses \MainWP\Child\MainWP_Child_Updraft_Plus_Backups::activejobs_list()
     * @uses \MainWP\Child\MainWP_Child_Updraft_Plus_Backups::last_backup_html()
     * @uses \MainWP\Child\MainWP_Child_Updraft_Plus_Backups::get_updraft_data()
     * @uses \MainWP\Child\MainWP_Child_Updraft_Plus_Backups::next_scheduled_backups()
     * @uses \MainWP\Child\MainWP_Child_Updraft_Plus_Backups::force_scheduled_resumption()
     * @uses \MainWP\Child\MainWP_Child_Updraft_Plus_Backups::fetch_updraft_log()
     * @uses \MainWP\Child\MainWP_Child_Updraft_Plus_Backups::activejobs_delete()
     * @uses \MainWP\Child\MainWP_Child_Updraft_Plus_Backups::historystatus()
     * @uses \MainWP\Child\MainWP_Child_Updraft_Plus_Backups::deleteset()
     * @uses \MainWP\Child\MainWP_Child_Updraft_Plus_Backups::updraft_download_backup()
     * @uses \MainWP\Child\MainWP_Child_Updraft_Plus_Backups::restore_alldownloaded()
     * @uses \MainWP\Child\MainWP_Child_Updraft_Plus_Backups::extradb_testconnection()
     * @uses \MainWP\Child\MainWP_Child_Updraft_Plus_Backups::delete_old_dirs_go()
     * @uses \MainWP\Child\MainWP_Child_Updraft_Plus_Backups::do_vault_connect()
     * @uses \MainWP\Child\MainWP_Child_Updraft_Plus_Backups::vault_disconnect()
     * @uses \MainWP\Child\MainWP_Helper::write()
     * @uses UpdraftPlus()
     * @uses \Exception()
     */
    public function action() {
        $information = array();
        if ( ! $this->is_plugin_installed ) {
            $information['error'] = 'NO_UPDRAFTPLUS';
            MainWP_Helper::write( $information );
        }

        $this->required_files();

        /** @global object $updraftplus UpdraftPlus class instance. */
        global $updraftplus;

        if ( empty( $updraftplus ) && class_exists( '\UpdraftPlus' ) ) {
            $updraftplus = new \UpdraftPlus();
        }
        if ( empty( $updraftplus ) ) {
            $information['error'] = 'Error empty updraftplus';
            MainWP_Helper::write( $information );
        }

        if ( isset( $_POST['mwp_action'] ) ) {
            $mwp_action = ! empty( $_POST['mwp_action'] ) ? sanitize_text_field( wp_unslash( $_POST['mwp_action'] ) ) : '';
            try {
                switch ( $mwp_action ) {
                    case 'set_showhide':
                        $information = $this->set_showhide();
                        break;
                    case 'save_settings':
                        $information = $this->save_settings();
                        break;
                    case 'addons_connect':
                        $information = $this->addons_connect();
                        break;
                    case 'backup_now':
                        $information = $this->backup_now();
                        break;
                    case 'activejobs_list':
                        $information = $this->activejobs_list();
                        break;
                    case 'diskspaceused':
                        $information = $this->diskspaceused();
                        break;
                    case 'last_backup_html':
                        $information = $this->last_backup_html();
                        break;
                    case 'reload_data':
                        $information = $this->get_updraft_data();
                        break;
                    case 'next_scheduled_backups':
                        $information = $this->next_scheduled_backups();
                        break;
                    case 'forcescheduledresumption':
                        $information = $this->force_scheduled_resumption();
                        break;
                    case 'fetch_updraft_log':
                        $information = $this->fetch_updraft_log();
                        break;
                    case 'activejobs_delete':
                        $information = $this->activejobs_delete();
                        break;
                    case 'historystatus':
                        $information = $this->historystatus();
                        break;
                    case 'deleteset':
                        $information = $this->deleteset();
                        break;
                    case 'updraft_download_backup':
                        $information = $this->updraft_download_backup();
                        break;
                    case 'restore_alldownloaded':
                        $information = $this->restore_alldownloaded();
                        break;
                    case 'extradbtestconnection':
                        $information = $this->extradb_testconnection();
                        break;
                    case 'delete_old_dirs':
                        $information = $this->delete_old_dirs_go();
                        break;
                    case 'vault_connect':
                        $information = $this->do_vault_connect();
                        break;
                    case 'vault_disconnect':
                        $information = $this->vault_disconnect();
                        break;
                }
            } catch ( \Exception $e ) {
                $information = array( 'error' => $e->getMessage() );
            }
        }
        MainWP_Helper::write( $information );
    }

    /**
     * Set show or hide UpdraftPlus Plugin from Admin & plugins list.
     *
     * @return array $information Return results.
     *
     * @uses \MainWP\Child\MainWP_Helper::update_option()
     */
    public function set_showhide() {
        $hide = isset( $_POST['showhide'] ) && ( 'hide' === $_POST['showhide'] ) ? 'hide' : '';
        MainWP_Helper::update_option( 'mainwp_updraftplus_hide_plugin', $hide );
        $information['result'] = 'SUCCESS';

        return $information;
    }

    /**
     * Get settings keys.
     *
     * @return array Array of settings keys.
     */
    private function get_settings_keys() {
        return array(
            'updraft_autobackup_default',
            'updraftplus_dismissedautobackup',
            'updraftplus_dismissedexpiry',
            'updraft_interval',
            'updraft_interval_increments',
            'updraft_interval_database',
            'updraft_retain',
            'updraft_retain_db',
            'updraft_encryptionphrase',
            'updraft_dir',
            'updraft_email',
            'updraft_delete_local',
            'updraft_include_plugins',
            'updraft_include_themes',
            'updraft_include_uploads',
            'updraft_include_others',
            'updraft_include_wpcore',
            'updraft_include_wpcore_exclude',
            'updraft_include_more',
            'updraft_include_blogs',
            'updraft_include_mu-plugins',
            'updraft_include_others_exclude',
            'updraft_include_uploads_exclude',
            'updraft_starttime_files',
            'updraft_starttime_db',
            'updraft_startday_db',
            'updraft_startday_files',
            'updraft_googledrive',
            'updraft_s3',
            'updraft_s3generic',
            'updraft_dreamhost',
            'updraft_disable_ping',
            'updraft_openstack',
            'updraft_bitcasa',
            'updraft_ssl_useservercerts',
            'updraft_ssl_disableverify',
            'updraft_report_warningsonly',
            'updraft_report_wholebackup',
            'updraft_report_dbbackup',
            'updraft_auto_updates',
            'updraft_log_syslog',
            'updraft_extradatabases',
            'updraft_split_every',
            'updraft_ssl_nossl',
            'updraft_backupdb_nonwp',
            'updraft_extradbs',
            'updraft_include_more_path',
            'updraft_dropbox',
            'updraft_ftp',
            'updraft_copycom',
            'updraft_sftp_settings',
            'updraft_webdav_settings',
            'updraft_dreamobjects',
            'updraft_googlecloud',
            'updraft_retain_extrarules',
            'updraft_backblaze',
        );
    }

    /**
     * Connect to UpdraftPlus Vault.
     *
     * @return array $response Return response.
     * @throws Exception Error message.
     *
     * @uses UpdraftPlus_Options::get_updraft_option()
     * @uses MainWP_Child_Updraft_Plus_Backups::connected_html()
     */
    private function do_vault_connect() {
        $vault_settings = \UpdraftPlus_Options::get_updraft_option( 'updraft_updraftvault' );
        if ( is_array( $vault_settings ) && ! empty( $vault_settings['token'] ) && ! empty( $vault_settings['email'] ) ) {
            return array(
                'connected' => true,
                'html'      => $this->connected_html(),
            );
        }

        $connect = $this->vault_connect( $_REQUEST['email'], $_REQUEST['passwd'] );
        if ( true === $connect ) {
            $response = array(
                'connected' => true,
                'html'      => $this->connected_html(),
            );
        } else {
            $response = array(
                'e' => esc_html__( 'An unknown error occurred when trying to connect to UpdraftPlus.Com', 'updraftplus' ),
            );
            if ( is_wp_error( $connect ) ) {
                $response['e']    = $connect->get_error_message();
                $response['code'] = $connect->get_error_code();
                $response['data'] = serialize( $connect->get_error_data() ); // phpcs:ignore -- third party credit.
            }
        }
        return $response;
    }


    /**
     * UpdraftPlus Vault connection html.
     *
     * @return string $ret Returns connected to UpdraftPlus Vault message html.
     * @throws Exception|\Exception Error message.
     *
     * @uses \MainWP\Child\MainWP_Helper::instance()->check_classes_exists()
     * @uses \MainWP\Child\MainWP_Helper::MainWP_Helper::instance()->check_methods()
     * @uses UpdraftPlus_Options::get_updraft_option()
     */
    private function connected_html() {
        MainWP_Helper::instance()->check_classes_exists( '\UpdraftPlus_Options' );
        MainWP_Helper::instance()->check_methods( '\UpdraftPlus_Options', 'get_updraft_option' );

        $vault_settings = \UpdraftPlus_Options::get_updraft_option( 'updraft_updraftvault' );
        if ( ! is_array( $vault_settings ) || empty( $vault_settings['token'] ) || empty( $vault_settings['email'] ) ) {
            return '';
        }

        $ret  = '';
        $ret .= '<p style="padding-top: 0px; margin-top: 0px;">';
        $ret .= esc_html__( 'This site is <strong>connected</strong> to UpdraftPlus Vault.', 'updraftplus' ) . ' ' . esc_html__( "Well done - there's nothing more needed to set up.", 'updraftplus' ) . '</p><p><strong>' . esc_html__( 'Vault owner', 'updraftplus' ) . ':</strong> ' . htmlspecialchars( $vault_settings['email'] );

        $ret .= '<br><strong>' . esc_html__( 'Quota:', 'updraftplus' ) . '</strong> ';
        if ( ! isset( $vault_settings['quota'] ) || ! is_numeric( $vault_settings['quota'] ) || ( $vault_settings['quota'] < 0 ) ) {
            $ret .= esc_html__( 'Unknown', 'updraftplus' );
        } else {
            $quota_via_transient = get_transient( 'updraftvault_quota_text' );
            if ( is_string( $quota_via_transient ) && $quota_via_transient ) {
                $ret .= $quota_via_transient;
            }
        }
        $ret .= '</p>';
        $ret .= '<p><button id="updraftvault_disconnect" class="button-primary" style="font-size:18px;">' . esc_html__( 'Disconnect', 'updraftplus' ) . '</button></p>';

        return $ret;
    }

    /**
     * Connect to UpdraftPlus Vault.
     *
     * @param string $email    Vault account user name.
     * @param strign $password Vault account password.
     *
     * @return bool|WP_Error $return Returns either true (in which case the Vault token will be stored), or false|\WP_Error.
     *
     * @uses UpdraftPlus_Options::get_updraft_option()
     * @uses UpdraftPlus_Options::update_updraft_option()
     * @uses \WP_Error()
     */
    private function vault_connect($email, $password ) {

        /** @global object $updraftplus UpdraftPlus instance. */
        global $updraftplus;

        $vault_mothership = 'https://vault.updraftplus.com/plugin-info/';

        // Use SSL to prevent snooping.
        $result = wp_remote_post(
            $vault_mothership . '/?udm_action=vault_connect',
            array(
                'timeout' => 20,
                'body'    => array(
                    'e'   => $email,
                    'p'   => base64_encode( $password ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
                    'sid' => $updraftplus->siteid(),
                    'su'  => base64_encode( home_url() ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
                ),
            )
        );

        if ( is_wp_error( $result ) || ( false === $result ) ) {
            return $result;
        }

        $response = json_decode( $result['body'], true );

        if ( ! is_array( $response ) || ! isset( $response['mothership'] ) || ! isset( $response['loggedin'] ) ) {
            if ( preg_match( '/has banned your IP address \(([\.:0-9a-f]+)\)/', $result['body'], $matches ) ) {
                return new \WP_Error( 'banned_ip', sprintf( esc_html__( "UpdraftPlus.com has responded with 'Access Denied'.", 'updraftplus' ) . '<br>' . esc_html__( "It appears that your web server's IP Address (%s) is blocked.", 'updraftplus' ) . ' ' . esc_html__( 'This most likely means that you share a webserver with a hacked website that has been used in previous attacks.', 'updraftplus' ) . '<br> <a href="https://updraftplus.com/unblock-ip-address/" target="_blank">' . esc_html__( 'To remove the block, please go here.', 'updraftplus' ) . '</a> ', $matches[1] ) );
            } else {
                return new \WP_Error( 'unknown_response', sprintf( esc_html__( 'UpdraftPlus.Com returned a response which we could not understand (data: %s)', 'updraftplus' ), $result['body'] ) );
            }
        }

        $return = false;
        switch ( $response['loggedin'] ) {
            case 'connected':
                if ( ! empty( $response['token'] ) ) {
                    // Store it.
                    $vault_settings = \UpdraftPlus_Options::get_updraft_option( 'updraft_updraftvault' );
                    if ( ! is_array( $vault_settings ) ) {
                        $vault_settings = array();
                    }
                    $vault_settings['email'] = $email;
                    $vault_settings['token'] = (string) $response['token'];
                    $vault_settings['quota'] = -1;
                    unset( $vault_settings['last_config'] );
                    if ( isset( $response['quota'] ) ) {
                        $vault_settings['quota'] = $response['quota'];
                    }
                    \UpdraftPlus_Options::update_updraft_option( 'updraft_updraftvault', $vault_settings );
                } elseif ( isset( $response['quota'] ) && ! $response['quota'] ) {
                    return new \WP_Error( 'no_quota', esc_html__( 'You do not currently have any UpdraftPlus Vault quota', 'updraftplus' ) );
                } else {
                    return new \WP_Error( 'unknown_response', esc_html__( 'UpdraftPlus.Com returned a response, but we could not understand it', 'updraftplus' ) );
                }
                break;
            case 'authfailed':
                if ( ! empty( $response['authproblem'] ) ) {
                    if ( 'invalidpassword' == $response['authproblem'] ) {
                        $authfail_error = new \WP_Error( 'authfailed', esc_html__( 'Your email address was valid, but your password was not recognised by UpdraftPlus.Com.', 'updraftplus' ) . ' <a href="https://updraftplus.com/my-account/lost-password/">' . esc_html__( 'If you have forgotten your password, then go here to change your password on updraftplus.com.', 'updraftplus' ) . '</a>' );
                        return $authfail_error;
                    } elseif ( 'invaliduser' == $response['authproblem'] ) {
                        return new \WP_Error( 'authfailed', esc_html__( 'You entered an email address that was not recognised by UpdraftPlus.Com', 'updraftplus' ) );
                    }
                }

                $return = new \WP_Error( 'authfailed', esc_html__( 'Your email address and password were not recognised by UpdraftPlus.Com', 'updraftplus' ) );
                break;

            default:
                $return = new \WP_Error( 'unknown_response', esc_html__( 'UpdraftPlus.Com returned a response, but we could not understand it', 'updraftplus' ) );
                break;
        }

        return $return;
    }

    /**
     * Disconnect from UpdraftPlus Vault.
     * This method also gets called directly, so don't add code that assumes that it's definitely an AJAX situation.
     *
     * @throws Exception Error message.
     *
     * @uses \UpdraftPlus_Options::get_updraft_option()
     * @uses \UpdraftPlus_Options::update_updraft_option()
     * @uses \MainWP\Child\MainWP_Utility::close_connection()
     */
    public function vault_disconnect() {
        $vault_settings = \UpdraftPlus_Options::get_updraft_option( 'updraft_updraftvault' );
        \UpdraftPlus_Options::update_updraft_option( 'updraft_updraftvault', array() );

        /** @global object $updraftplus UpdraftPlus instance. */
        global $updraftplus;

        $vault_mothership = 'https://vault.updraftplus.com/plugin-info/';

        delete_transient( 'udvault_last_config' );
        delete_transient( 'updraftvault_quota_text' );

        MainWP_Utility::close_connection(
            array(
                'disconnected' => 1,
                'html'         => $this->connected_html(),
            )
        );

        // If $_POST['reset_hash'] is set, then we were alerted by updraftplus.com - no need to notify back.
        if ( is_array( $vault_settings ) && isset( $vault_settings['email'] ) && empty( $_POST['reset_hash'] ) ) {
            $post_body = array(
                'e'   => (string) $vault_settings['email'],
                'sid' => $updraftplus->siteid(),
                'su'  => base64_encode( home_url() ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
            );

            if ( ! empty( $vault_settings['token'] ) ) {
                $post_body['token'] = (string) $vault_settings['token'];
            }

            // Use SSL to prevent snooping.
            wp_remote_post(
                $vault_mothership . '/?udm_action=vault_disconnect',
                array(
                    'timeout' => 20,
                    'body'    => $post_body,
                )
            );
        }
    }

    /**
     * Require needed UpdraftPlus Files.
     */
    public function required_files() {
        if ( defined( 'UPDRAFTPLUS_DIR' ) ) {
            if ( ! class_exists( '\UpdraftPlus' ) && file_exists( UPDRAFTPLUS_DIR . '/class-updraftplus.php' ) ) {
                require_once UPDRAFTPLUS_DIR . '/class-updraftplus.php';
            }

            if ( ! class_exists( '\UpdraftPlus_Options' ) && file_exists( UPDRAFTPLUS_DIR . '/options.php' ) ) {
                require_once UPDRAFTPLUS_DIR . '/options.php';
            }
        }
    }

    /**
     * Save UpdraftPlus settings.
     *
     * @return array $out Return response array.
     *
     * @uses MainWP_Child_Updraft_Plus_Backups::get_settings_keys()
     * @uses MainWP_Child_Updraft_Plus_Backups::replace_tokens()
     * @uses UpdraftPlus_Options::get_updraft_option()
     * @uses UpdraftPlus_Options::update_updraft_option()
     * @uses $updraftplus::schedule_backup()
     * @uses $updraftplus::schedule_backup_database()
     */
    public function save_settings() {
        $settings = isset( $_POST['settings'] ) ? json_decode( base64_decode( wp_unslash( $_POST['settings'] ) ), true ) : ''; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..

        $keys_filter = $this->get_settings_keys();

        $updated = false;
        if ( is_array( $settings ) ) {
            if ( class_exists( '\UpdraftPlus_Options' ) ) {
                foreach ( $keys_filter as $key ) {
                    if ( 'updraft_googledrive' === $key || 'updraft_googlecloud' === $key || 'updraft_onedrive' === $key ) {
                        continue;
                    }
                    if ( isset( $settings[ $key ] ) ) {
                        $settings_key = null;
                        if ( 'updraft_dropbox' === $key && is_array( $settings[ $key ] ) ) {
                            $opts = \UpdraftPlus_Options::get_updraft_option( 'updraft_dropbox' );
                            if ( ! is_array( $opts ) ) {
                                $opts = array();
                            }
                            if ( is_array( $opts ) && isset( $opts['settings'] ) ) {
                                $settings_key = key( $opts['settings'] );
                                if ( isset( $settings['is_general'] ) && ! empty( $settings['is_general'] ) ) {
                                    $opts['settings'][ $settings_key ]['folder'] = $this->replace_tokens( $settings[ $key ]['folder'] );
                                } else {
                                    $opts['settings'][ $settings_key ]['folder'] = $this->replace_tokens( $settings[ $key ]['folder'] );
                                }
                            } else {
                                if ( isset( $settings['is_general'] ) && ! empty( $settings['is_general'] ) ) {
                                    $opts['folder'] = $this->replace_tokens( $settings[ $key ]['folder'] );
                                } else {
                                    $opts['folder'] = $this->replace_tokens( $settings[ $key ]['folder'] );
                                }
                            }
                            \UpdraftPlus_Options::update_updraft_option( $key, $opts );
                        } elseif ( 'updraft_email' === $key ) {
                            $value = $settings[ $key ];
                            if ( ! is_array( $value ) ) {
                                if ( ! empty( $value ) ) {
                                    $value = htmlspecialchars( get_bloginfo( 'admin_email' ) );
                                }
                            }
                            \UpdraftPlus_Options::update_updraft_option( $key, $value );
                        } elseif ( 'updraft_s3' === $key ) {
                            $opts = \UpdraftPlus_Options::get_updraft_option( 'updraft_s3' );
                            if ( ! is_array( $opts ) ) {
                                $opts = array();
                            }
                            if ( is_array( $opts ) && isset( $opts['settings'] ) ) {
                                $settings_key                                   = key( $opts['settings'] );
                                $opts['settings'][ $settings_key ]['accesskey'] = $settings[ $key ]['accesskey'];
                                $opts['settings'][ $settings_key ]['secretkey'] = $settings[ $key ]['secretkey'];
                                $opts['settings'][ $settings_key ]['path']      = $this->replace_tokens( $settings[ $key ]['path'] );
                                if ( ! empty( $opts['settings'][ $settings_key ]['path'] ) && '/' == substr( $opts['settings'][ $settings_key ]['path'], 0, 1 ) ) {
                                    $opts['settings'][ $settings_key ]['path'] = substr( $opts['settings'][ $settings_key ]['path'], 1 );
                                }
                                if ( isset( $settings[ $key ]['rrs'] ) ) { // premium settings.
                                    $opts['settings'][ $settings_key ]['rrs']                    = $settings[ $key ]['rrs'];
                                    $opts['settings'][ $settings_key ]['server_side_encryption'] = $settings[ $key ]['server_side_encryption'];
                                }
                            } else {
                                $opts['accesskey'] = $settings[ $key ]['accesskey'];
                                $opts['secretkey'] = $settings[ $key ]['secretkey'];
                                $opts['path']      = $this->replace_tokens( $settings[ $key ]['path'] );
                                if ( ! empty( $opts['path'] ) && '/' == substr( $opts['path'], 0, 1 ) ) {
                                    $opts['path'] = substr( $opts['path'], 1 );
                                }
                                if ( isset( $settings[ $key ]['rrs'] ) ) { // premium settings.
                                    $opts['rrs']                    = $settings[ $key ]['rrs'];
                                    $opts['server_side_encryption'] = $settings[ $key ]['server_side_encryption'];
                                }
                            }

                            \UpdraftPlus_Options::update_updraft_option( $key, $opts );
                        } elseif ( 'updraft_s3generic' === $key ) {
                            $opts = \UpdraftPlus_Options::get_updraft_option( 'updraft_s3generic' );
                            if ( ! is_array( $opts ) ) {
                                $opts = array();
                            }
                            if ( is_array( $opts ) && isset( $opts['settings'] ) ) {
                                $settings_key                                   = key( $opts['settings'] );
                                $opts['settings'][ $settings_key ]['endpoint']  = $settings[ $key ]['endpoint'];
                                $opts['settings'][ $settings_key ]['accesskey'] = $settings[ $key ]['accesskey'];
                                $opts['settings'][ $settings_key ]['secretkey'] = $settings[ $key ]['secretkey'];
                                $opts['settings'][ $settings_key ]['path']      = $this->replace_tokens( $settings[ $key ]['path'] );
                            } else {
                                $opts['endpoint']  = $settings[ $key ]['endpoint'];
                                $opts['accesskey'] = $settings[ $key ]['accesskey'];
                                $opts['secretkey'] = $settings[ $key ]['secretkey'];
                                $opts['path']      = $this->replace_tokens( $settings[ $key ]['path'] );
                            }

                            \UpdraftPlus_Options::update_updraft_option( $key, $opts );
                        } elseif ( 'updraft_dreamobjects' === $key ) {
                            $opts = \UpdraftPlus_Options::get_updraft_option( 'updraft_dreamobjects' );
                            if ( ! is_array( $opts ) ) {
                                $opts = array();
                            }
                            if ( is_array( $opts ) && isset( $opts['settings'] ) ) {
                                $settings_key                                  = key( $opts['settings'] );
                                $opts['settings'][ $settings_key ]['path']     = $this->replace_tokens( $settings[ $key ]['path'] );
                                $opts['settings'][ $settings_key ]['endpoint'] = $settings[ $key ]['endpoint'];
                            } else {
                                $opts['path']     = $this->replace_tokens( $settings[ $key ]['path'] );
                                $opts['endpoint'] = $settings[ $key ]['endpoint'];
                            }
                            \UpdraftPlus_Options::update_updraft_option( $key, $opts );
                        } elseif ( 'updraft_ftp' === $key ) {
                            $opts = \UpdraftPlus_Options::get_updraft_option( 'updraft_ftp' );
                            if ( ! is_array( $opts ) ) {
                                $opts = array();
                            }
                            if ( is_array( $opts ) && isset( $opts['settings'] ) ) {
                                $settings_key = key( $opts['settings'] );
                                if ( isset( $settings[ $key ]['path'] ) ) {
                                    $opts['settings'][ $settings_key ]['host']    = $settings[ $key ]['host'];
                                    $opts['settings'][ $settings_key ]['user']    = $settings[ $key ]['user'];
                                    $opts['settings'][ $settings_key ]['pass']    = $settings[ $key ]['pass'];
                                    $opts['settings'][ $settings_key ]['path']    = $this->replace_tokens( $settings[ $key ]['path'] );
                                    $opts['settings'][ $settings_key ]['passive'] = isset( $settings[ $key ]['passive'] ) ? $settings[ $key ]['passive'] : 0;
                                }
                            } else {
                                if ( isset( $settings[ $key ]['path'] ) ) {
                                    $opts['host']    = $settings[ $key ]['host'];
                                    $opts['user']    = $settings[ $key ]['user'];
                                    $opts['pass']    = $settings[ $key ]['pass'];
                                    $opts['path']    = $this->replace_tokens( $settings[ $key ]['path'] );
                                    $opts['passive'] = isset( $settings[ $key ]['passive'] ) ? $settings[ $key ]['passive'] : 0;
                                }
                            }

                            \UpdraftPlus_Options::update_updraft_option( $key, $opts );
                        } elseif ( 'updraft_sftp_settings' === $key ) {
                            $opts = \UpdraftPlus_Options::get_updraft_option( 'updraft_sftp' );
                            if ( ! is_array( $opts ) ) {
                                $opts = array();
                            }
                            if ( is_array( $opts ) && isset( $opts['settings'] ) ) {
                                $settings_key = key( $opts['settings'] );
                                if ( isset( $settings[ $key ]['path'] ) ) {
                                    $opts['settings'][ $settings_key ]['host'] = $settings[ $key ]['host'];
                                    $opts['settings'][ $settings_key ]['port'] = $settings[ $key ]['port'];
                                    $opts['settings'][ $settings_key ]['user'] = $settings[ $key ]['user'];
                                    $opts['settings'][ $settings_key ]['pass'] = $settings[ $key ]['pass'];
                                    $opts['settings'][ $settings_key ]['key']  = $settings[ $key ]['key'];
                                    $opts['settings'][ $settings_key ]['path'] = $this->replace_tokens( $settings[ $key ]['path'] );
                                    $opts['settings'][ $settings_key ]['scp']  = isset( $settings[ $key ]['scp'] ) ? $settings[ $key ]['scp'] : 0;
                                }
                            } else {
                                if ( isset( $settings[ $key ]['path'] ) ) {
                                    $opts['host'] = $settings[ $key ]['host'];
                                    $opts['port'] = $settings[ $key ]['port'];
                                    $opts['user'] = $settings[ $key ]['user'];
                                    $opts['pass'] = $settings[ $key ]['pass'];
                                    $opts['key']  = $settings[ $key ]['key'];
                                    $opts['path'] = $this->replace_tokens( $settings[ $key ]['path'] );
                                    $opts['scp']  = isset( $settings[ $key ]['scp'] ) ? $settings[ $key ]['scp'] : 0;
                                }
                            }
                            \UpdraftPlus_Options::update_updraft_option( 'updraft_sftp', $opts );
                        } elseif ( 'updraft_webdav_settings' == $key && is_array( $settings[ $key ] ) ) {
                            $opts = \UpdraftPlus_Options::get_updraft_option( 'updraft_webdav' );
                            if ( ! is_array( $opts ) ) {
                                $opts = array();
                            }

                            if ( is_array( $opts ) && isset( $opts['settings'] ) ) {
                                $settings_key                             = key( $opts['settings'] );
                                $opts['settings'][ $settings_key ]['path'] = $this->replace_tokens( $settings[ $key ]['path'] );
                                \UpdraftPlus_Options::update_updraft_option( 'updraft_webdav', $opts );
                            }
                        } elseif ( 'updraft_backblaze' === $key ) {
                            $opts = \UpdraftPlus_Options::get_updraft_option( 'updraft_backblaze' );
                            if ( ! is_array( $opts ) ) {
                                $opts = array();
                            }
                            if ( is_array( $opts ) && isset( $opts['settings'] ) && is_array( $settings[ $key ] ) && isset( $settings[ $key ]['account_id'] ) ) {
                                $settings_key                                    = key( $opts['settings'] );
                                $opts['settings'][ $settings_key ]['account_id'] = $settings[ $key ]['account_id'];
                                $opts['settings'][ $settings_key ]['key']        = $settings[ $key ]['key'];

                                if ( isset( $settings[ $key ]['single_bucket_key_id'] ) && ! empty( $settings[ $key ]['single_bucket_key_id'] ) ){
                                    $single_bucket_key_id = trim( $settings[ $key ]['single_bucket_key_id'] );
                                    if ( '[empty]' == $single_bucket_key_id ) {
                                        $opts['settings'][ $settings_key ]['single_bucket_key_id'] = '';    
                                    } elseif ( ! empty( $single_bucket_key_id ) ) {
                                        $opts['settings'][ $settings_key ]['single_bucket_key_id'] = $single_bucket_key_id;
                                    }                                    
                                }

                                $bname = $this->replace_tokens( $settings[ $key ]['bucket_name'] );
                                $bpath = $this->replace_tokens( $settings[ $key ]['backup_path'] );
                                $bname = str_replace( '.', '-', $bname );
                                $bpath = str_replace( '.', '-', $bpath );
                                $bname = str_replace( '_', '', $bname ); // to fix uncommon character issues.
                                $bpath = str_replace( '_', '', $bpath );
                                $opts['settings'][ $settings_key ]['bucket_name'] = $bname;
                                $opts['settings'][ $settings_key ]['backup_path'] = $bpath;
                                \UpdraftPlus_Options::update_updraft_option( $key, $opts );
                            }
                        } else {
                            \UpdraftPlus_Options::update_updraft_option( $key, $settings[ $key ] );
                        }
                        $updated = true;
                    }
                }

                if ( ! isset( $settings['do_not_save_remote_settings'] ) || empty( $settings['do_not_save_remote_settings'] ) ) {
                    \UpdraftPlus_Options::update_updraft_option( 'updraft_service', $settings['updraft_service'] );
                }

	            /** @global object $updraftplus UpdraftPlus instance. */
                global $updraftplus;

                if ( isset( $settings['updraft_interval'] ) ) {
                    // fix for premium version.
                    $_POST['updraft_interval']        = $settings['updraft_interval'];
                    $_POST['updraft_startday_files']  = $settings['updraft_startday_files'];
                    $_POST['updraft_starttime_files'] = $settings['updraft_starttime_files'];
                    $updraftplus->schedule_backup( $settings['updraft_interval'] );
                }
                if ( isset( $settings['updraft_interval_database'] ) ) {
                    // fix for premium version.
                    $_POST['updraft_interval_database'] = $settings['updraft_interval_database'];
                    $_POST['updraft_startday_db']       = $settings['updraft_startday_db'];
                    $_POST['updraft_starttime_db']      = $settings['updraft_starttime_db'];
                    $updraftplus->schedule_backup_database( $settings['updraft_interval_database'] );
                }
            }
        }

        $out = array();
        if ( $updated ) {
            $out['result'] = 'success';
        } else {
            $out['result'] = 'noupdate';
        }

        return $out;
    }

    /**
     * Replace %sitename% and %siteurl% tokens.
     *
     * @param string $str String to search & replace.
     * @return string|string[] $str Altered string.
     */
    public function replace_tokens( $str = '' ) {
        if ( stripos( $str, '%sitename%' ) !== false ) {
            $replace_token = get_bloginfo( 'name' );
            $replace_token = sanitize_file_name( $replace_token );
            $replace_token = strtolower( $replace_token );
            $str           = str_ireplace( '%sitename%', $replace_token, $str );
        }

        if ( stripos( $str, '%siteurl%' ) !== false ) {
            $replace_token = get_bloginfo( 'url' );
            $replace_token = preg_replace( '/^https?:\/\//i', '', $replace_token );
            $replace_token = sanitize_file_name( $replace_token );
            $str           = str_ireplace( '%siteurl%', $replace_token, $str );
        }
        return $str;
    }

    /**
     * Connect UpdraftPlus Premium addons.
     *
     * @return array|string[] $out return response array. Success or nopremium.
     *
     * @uses MainWP_Child_Updraft_Plus_Backups::update_wpmu_options()
     */
    public function addons_connect() {
        if ( ! defined( 'UDADDONS2_SLUG' ) ) {
            if ( is_file( UPDRAFTPLUS_DIR . '/udaddons/updraftplus-addons.php' ) ) {
                require_once UPDRAFTPLUS_DIR . '/udaddons/updraftplus-addons.php';
            }
            if ( ! defined( 'UDADDONS2_SLUG' ) ) {
                return array( 'error' => 'NO_PREMIUM' );
            }
        }

        $addons_options = isset( $_POST['addons_options'] ) ? json_decode( base64_decode( wp_unslash( $_POST['addons_options'] ) ), true ) : array(); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
        if ( ! is_array( $addons_options ) ) {
            $addons_options = array();
        }

        $updated = $this->update_wpmu_options( $addons_options );

        $out = array();
        if ( $updated ) {
            $out['result'] = 'success';
        }

        return $out;
    }

    /**
     * Update WPMU Options.
     *
     * @param array $value Values to save.
     * @return bool|void Return TRUE on success or VOID on failure.
     *
     * @uses UpdraftPlus_Options::user_can_manage()
     * @uses MainWP_Child_Updraft_Plus_Backups::addons2_get_option()
     * @uses MainWP_Child_Updraft_Plus_Backups::options_validate()
     * @uses MainWP_Child_Updraft_Plus_Backups::addons2_update_option()
     */
    public function update_wpmu_options( $value ) {

        if ( ! \UpdraftPlus_Options::user_can_manage() ) {
            return;
        }
        $options = $this->addons2_get_option( UDADDONS2_SLUG . '_options' );
        if ( ! is_array( $options ) ) {
            $options = array();
        }

        $options['email']    = isset( $value['email'] ) ? $value['email'] : '';
        $options['password'] = isset( $value['password'] ) ? $value['password'] : '';

        $options = $this->options_validate( $options );
        $this->addons2_update_option( UDADDONS2_SLUG . '_options', $options );

        return true;
    }

    /**
     * Get site option (2)
     *
     * Funnelling through here,
     *  a) allows for future flexibility and,
     *  b) allows us to migrate elegantly from the previous non-MU-friendly setup.
     *
     * @param string $option Site option to get.
     * @return mixed $val Returned site option.
     */
    public function addons2_get_option( $option ) {
        $val = get_site_option( $option );
        // On multisite, migrate options into the site options.
        if ( false === $val && is_multisite() ) {
            $blog_id = get_current_blog_id();
            if ( $blog_id > 1 ) {
                $val = get_option( $option );
                if ( false !== $val ) {
                    delete_option( $option );
                    update_site_option( $option, $val );

                    return $val;
                }
            }
            // $val is still false.
            switch_to_blog( 1 );
            $val = get_option( $option );
            if ( false !== $val ) {
                delete_option( $option );
                update_site_option( $option, $val );
            }
            restore_current_blog();
        }

        return $val;
    }

    /**
     * Update site option (2)
     *
     * @param string $option Site option to update.
     * @param string $val Value New option value.
     * @return bool False if value was not updated. True if value was updated.
     */
    public function addons2_update_option( $option, $val ) {
        return update_site_option( $option, $val );
    }

    /**
     * When the options are re-saved, clear any previous cache of the connection status.
     *
     * @param string $input Option to validate.
     * @return mixed $input The validated option.
     */
    public function options_validate( $input ) {
        $ehash = substr( md5( $input['email'] ), 0, 23 );
        delete_site_transient( 'udaddons_connect_' . $ehash );

        return $input;
    }


    /**
     * Extra DB test connection.
     *
     * @return array Return response array.
     *
     * @uses UpdraftPlus_WPDB_OtherDB_Test()
     *
     * Credits
     * Plugin: UpdraftPlus - Backup/Restore
     * PluginURI: http://updraftplus.com
     * Description: Backup and restore: take backups locally, or backup to Amazon S3, Dropbox, Google Drive, Rackspace, (S)FTP, WebDAV & email, on automatic schedules.
     * Author: UpdraftPlus.Com, DavidAnderson
     * Version: 1.9.60
     * Donate link: http://david.dw-perspective.org.uk/donate
     * License: GPLv3 or later
     * Text Domain: updraftplus
     * Domain Path: /languages
     * Author URI: http://updraftplus.com
     */
    public function extradb_testconnection() {

        if ( ! class_exists( '\UpdraftPlus_WPDB_OtherDB_Test' ) ) {
            if ( file_exists( UPDRAFTPLUS_DIR . '/addons/moredatabase.php' ) ) {
                require_once UPDRAFTPLUS_DIR . '/addons/moredatabase.php';
            }
        }

        if ( ! class_exists( '\UpdraftPlus_WPDB_OtherDB_Test' ) ) {
            return array(
                'r' => isset( $_POST['row'] ) ? wp_unslash( $_POST['row'] ) : '',
                'm' => 'Error: Require premium UpdraftPlus plugin.',
            );
        }

        if ( empty( $_POST['user_db'] ) ) {
            return array(
                'r' => isset( $_POST['row'] ) ? wp_unslash( $_POST['row'] ) : '',
                'm' => '<p>' . sprintf( esc_html__( 'Failure: No %s was given.', 'updraftplus' ) . '</p>', esc_html__( 'user', 'updraftplus' ) ),
            );
        }

        if ( empty( $_POST['host'] ) ) {
            return array(
                'r' => isset( $_POST['row'] ) ? wp_unslash( $_POST['row'] ) : '',
                'm' => '<p>' . sprintf( esc_html__( 'Failure: No %s was given.', 'updraftplus' ) . '</p>', esc_html__( 'host', 'updraftplus' ) ),
            );
        }

        if ( empty( $_POST['name'] ) ) {
            return array(
                'r' => isset( $_POST['row'] ) ? wp_unslash( $_POST['row'] ) : '',
                'm' => '<p>' . sprintf( esc_html__( 'Failure: No %s was given.', 'updraftplus' ) . '</p>', esc_html__( 'database name', 'updraftplus' ) ),
            );
        }

        /** @global object $updraftplus_admin UpdraftPlus Admin array. */
        global $updraftplus_admin;

        $updraftplus_admin->logged = array();

        $ret    = '';
        $failed = false;

        $wpdb_obj = new \UpdraftPlus_WPDB_OtherDB_Test( $_POST['user_db'], $_POST['pass'], $_POST['name'], $_POST['host'] );
        if ( ! empty( $wpdb_obj->error ) ) {
            $failed = true;
            $ret   .= '<p>';
            $dbinfo['user'] . '@' . $dbinfo['host'] . '/' . $dbinfo['name'] . ' : ' . esc_html__( 'database connection attempt failed', 'updraftplus' ) . '</p>';
            if ( is_wp_error( $wpdb_obj->error ) || is_string( $wpdb_obj->error ) ) {
                $ret .= '<ul style="list-style: disc inside;">';
                if ( is_wp_error( $wpdb_obj->error ) ) {
                    $codes = $wpdb_obj->error->get_error_codes();
                    if ( is_array( $codes ) ) {
                        foreach ( $codes as $code ) {
                            if ( 'db_connect_fail' === $code ) {
                                $ret .= '<li>' . esc_html__( 'Connection failed: check your access details, that the database server is up, and that the network connection is not firewalled.', 'updraftplus' ) . '</li>';
                            } else {
                                $err  = $wpdb_obj->error->get_error_message( $code );
                                $ret .= '<li>' . $err . '</li>';
                            }
                        }
                    }
                } else {
                    $ret .= '<li>' . $wpdb_obj->error . '</li>';
                }
                $ret .= '</ul>';
            }
        }

        $ret_info = '';
        if ( ! $failed ) {
            $all_tables = $wpdb_obj->get_results( 'SHOW TABLES', ARRAY_N );
            $all_tables = array_map( array( $this, 'cb_get_name_base_type' ), $all_tables );

            if ( empty( $_POST['prefix'] ) ) {
                $ret_info .= sprintf( esc_html__( '%s table(s) found.', 'updraftplus' ), count( $all_tables ) );
            } else {
                $our_prefix = 0;
                foreach ( $all_tables as $table ) {
                    if ( 0 === strpos( $table, $_POST['prefix'] ) ) {
                        $our_prefix ++;
                    }
                }
                $ret_info .= sprintf( esc_html__( '%1$s total table(s) found; %2$s with the indicated prefix.', 'updraftplus' ), count( $all_tables ), $our_prefix );
            }
        }

        $ret_after = '';

        if ( count( $updraftplus_admin->logged ) > 0 ) {
            $ret_after .= '<p>' . esc_html__( 'Messages:', 'updraftplus' );
            $ret_after .= '<ul style="list-style: disc inside;">';

            foreach ( array_unique( $updraftplus_admin->logged ) as $code => $err ) {
                if ( 'db_connect_fail' === $code ) {
                    $failed = true;
                }
                $ret_after .= "<li><strong>$code:</strong> $err</li>";
            }
            $ret_after .= '</ul></p>';
        }

        if ( ! $failed ) {
            $ret = '<p>' . esc_html__( 'Connection succeeded.', 'updraftplus' ) . ' ' . $ret_info . '</p>' . $ret;
        } else {
            $ret = '<p>' . esc_html__( 'Connection failed.', 'updraftplus' ) . '</p>' . $ret;
        }

        restore_error_handler();

        return array(
            'r' => isset( $_POST['row'] ) ? wp_unslash( $_POST['row'] ) : '',
            'm' => $ret . $ret_after,
        );
    }

    /**
     * CB get name base type.
     *
     * @param $a
     * @return mixed
     */
    private function cb_get_name_base_type( $a ) {
        return $a[0];
    }

    /**
     * Backup now.
     *
     * @uses MainWP_Child_Updraft_Plus_Backups::close_browser_connection()
     */
    public function backup_now() {

        /** @global object $updraftplus UpdraftPlus object.  */
        global $updraftplus;

        $backupnow_nocloud = ( empty( $_REQUEST['backupnow_nocloud'] ) ) ? false : true;
        $event = ( ! empty( $_REQUEST['backupnow_nofiles'] ) ) ? 'updraft_backupnow_backup_database' : ( ( ! empty( $_REQUEST['backupnow_nodb'] ) ) ? 'updraft_backupnow_backup' : 'updraft_backupnow_backup_all' );

        // The call to backup_time_nonce() allows us to know the nonce in advance, and return it.
        $nonce = $updraftplus->backup_time_nonce();

        $msg = array(
            'nonce' => $nonce,
            'm'     => '<strong>' . esc_html__( 'Start backup', 'updraftplus' ) . ':</strong> ' . htmlspecialchars( esc_html__( 'OK. You should soon see activity in the "Last log message" field below.', 'updraftplus' ) ),
        );

        $this->close_browser_connection( $msg );

        $options = array(
            'nocloud'   => $backupnow_nocloud,
            'use_nonce' => $nonce,
        );
        if ( ! empty( $_REQUEST['onlythisfileentity'] ) && is_string( $_REQUEST['onlythisfileentity'] ) ) {
            // Something to see in the 'last log' field when it first appears, before the backup actually starts.
            $updraftplus->log( esc_html__( 'Start backup', 'updraftplus' ) );
            $options['restrict_files_to_override'] = isset( $_REQUEST['onlythisfileentity'] ) ? explode( ',', $_REQUEST['onlythisfileentity'] ) : array();
        }

        do_action( $event, apply_filters( 'updraft_backupnow_options', $options, array() ) );

        // Control returns when the backup finished; However, the browser connection should have been closed before.
        die;
    }

    /**
     * Active jobs list.
     *
     * @return array Return the active jobs list.
     */
    public function activejobs_list() {

        /** @global object $updraftplus UpdraftPlus object.  */
        global $updraftplus;

		$download_status = array();
		if ( ! empty( $_REQUEST['downloaders'] ) ) {
            foreach ( explode( ':', $_REQUEST['downloaders'] ) as $downloader ) {
                // prefix, timestamp, entity, index.
                if ( preg_match( '/^([^,]+),(\d+),([-a-z]+|db[0-9]+),(\d+)$/', $downloader, $matches ) ) {
                    $updraftplus->nonce = $matches[2];
                    $status             = $this->download_status( $matches[2], $matches[3], $matches[4] );
                    if ( is_array( $status ) ) {
                        $status['base']      = $matches[1];
                        $status['timestamp'] = $matches[2];
                        $status['what']      = $matches[3];
                        $status['findex']    = ( empty( $matches[4] ) ) ? '0' : $matches[4];
                        $download_status[]   = $status;
                    }
                }
            }
        }

		if ( ! empty( $_REQUEST['oneshot'] ) ) {
            $job_id      = get_site_option( 'updraft_oneshotnonce', false );
            $active_jobs = ( false === $job_id ) ? '' : $this->print_active_job( $job_id, true );
        } elseif ( ! empty( $_REQUEST['thisjobonly'] ) ) {
            $active_jobs = $this->print_active_jobs( $_REQUEST['thisjobonly'] );
        } else {
            $active_jobs = $this->print_active_jobs();
        }

		$logupdate_array = array();
		if ( ! empty( $_REQUEST['log_fetch'] ) ) {
            if ( isset( $_REQUEST['log_nonce'] ) ) {
                $log_nonce       = isset( $_REQUEST['log_nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['log_nonce'] ) ) : '';
                $log_pointer     = isset( $_REQUEST['log_pointer'] ) ? absint( $_REQUEST['log_pointer'] ) : 0;
                $logupdate_array = $this->fetch_log( $log_nonce, $log_pointer );
            }
        }

		return array(
            'l'  => htmlspecialchars( \UpdraftPlus_Options::get_updraft_option( 'updraft_lastmessage', '(' . esc_html__( 'Nothing yet logged', 'updraftplus' ) . ')' ) ),
            'j'  => $active_jobs,
            'ds' => $download_status,
            'u'  => $logupdate_array,
        );
	}


    /**
     * Last backup html.
     *
     * @return array Return last backup log text & backup time.
     *
     * @uses UpdraftPlus_Options::get_updraft_option()
     * @uses $updraftplus::backups_dir_location()
     */
    private function last_backup_html() {

        /** @global object $updraftplus UpdraftPlus object.  */
        global $updraftplus;

        $updraft_last_backup = \UpdraftPlus_Options::get_updraft_option( 'updraft_last_backup' );
        $backup_time         = 0;
        if ( $updraft_last_backup ) {

            // Convert to GMT, then to blog time.
            $backup_time = (int) $updraft_last_backup['backup_time'];
            $print_time  = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $backup_time ), 'D, F j, Y H:i' );

            if ( empty( $updraft_last_backup['backup_time_incremental'] ) ) {
                $last_backup_text = '<span style="color:' . ( ( $updraft_last_backup['success'] ) ? 'green' : 'black' ) . ';">' . $print_time . '</span>';
            } else {
                $inc_time         = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $updraft_last_backup['backup_time_incremental'] ), 'D, F j, Y H:i' );
                $last_backup_text = '<span style="color:' . ( ( $updraft_last_backup['success'] ) ? 'green' : 'black' ) . ";\">$inc_time</span> (" . sprintf( esc_html__( 'incremental backup; base backup: %s', 'updraftplus' ), $print_time ) . ')';
            }

            $last_backup_text .= '<br>';

            // Show errors + warnings.
            if ( is_array( $updraft_last_backup['errors'] ) ) {
                foreach ( $updraft_last_backup['errors'] as $err ) {
                    $level             = ( is_array( $err ) ) ? $err['level'] : 'error';
                    $message           = ( is_array( $err ) ) ? $err['message'] : $err;
                    $last_backup_text .= ( 'warning' === $level ) ? '<span style="color:orange;">' : '<span style="color:red;">';
                    if ( 'warning' === $level ) {
                        $message = sprintf( esc_html__( 'Warning: %s', 'updraftplus' ), make_clickable( htmlspecialchars( $message ) ) );
                    } else {
                        $message = htmlspecialchars( $message );
                    }
                    $last_backup_text .= $message;
                    $last_backup_text .= '</span>';
                    $last_backup_text .= '<br>';
                }
            }

            // Link log.
            if ( ! empty( $updraft_last_backup['backup_nonce'] ) ) {
                $updraft_dir = $updraftplus->backups_dir_location();

                $potential_log_file = $updraft_dir . '/log.' . $updraft_last_backup['backup_nonce'] . '.txt';
                if ( is_readable( $potential_log_file ) ) {
                    $last_backup_text .= "<a href=\"#\" class=\"updraft-log-link\" onclick=\"event.preventDefault(); mainwp_updraft_popuplog('" . $updraft_last_backup['backup_nonce'] . "', this);\">" . esc_html__( 'Download log file', 'updraftplus' ) . '</a>';
                }
            }
        } else {
            $last_backup_text = '<span style="color:blue;">' . esc_html__( 'No backup has been completed.', 'updraftplus' ) . '</span>';
        }

        return array(
            'b'            => $last_backup_text,
            'lasttime_gmt' => $backup_time,
        );
    }

    /**
     * Get UpdraftPlus data.
     *
     * @param bool $with_hist Whether or not to build history.
     *
     * @uses UpdraftPlus()
     * @uses UpdraftPlus_Options::get_updraft_option()
     * @uses UpdraftPlus_Filesystem_Functions::really_is_writable()
     * @uses \MainWP\Child\MainWP_Helper::instance()->check_methods()
     * @uses \MainWP\Child\MainWP_Helper::MainWP_Helper::instance()->check_methods()
     * @uses MainWP_Child_Updraft_Plus_Backups::build_historystatus()
     * @uses MainWP_Child_Updraft_Plus_Backups::last_backup_html()
     */
    private function get_updraft_data( $with_hist = true ) {

        /** @global object $updraftplus UpdraftPlus object.  */
        global $updraftplus;

        if ( empty( $updraftplus ) && class_exists( '\UpdraftPlus' ) ) {
            $updraftplus = new \UpdraftPlus();
        }

        if ( empty( $updraftplus ) ) {
            return false;
        }

        // UNIX timestamp.
        $next_scheduled_backup              = wp_next_scheduled( 'updraft_backup' );
        $next_scheduled_backup_gmt          = 0;
        $next_scheduled_backup_database_gmt = 0;
        if ( $next_scheduled_backup ) {
            // Convert to GMT.
            $next_scheduled_backup_gmt = gmdate( 'Y-m-d H:i:s', $next_scheduled_backup );
            // Convert to blog time zone.
            $next_scheduled_backup = get_date_from_gmt( $next_scheduled_backup_gmt, 'D, F j, Y H:i' );
        } else {
            $next_scheduled_backup = 'Nothing currently scheduled';
        }

        MainWP_Helper::instance()->check_classes_exists( array( '\UpdraftPlus_Options', '\UpdraftPlus_Filesystem_Functions' ) );
        MainWP_Helper::instance()->check_methods( '\UpdraftPlus_Options', 'get_updraft_option' );
        MainWP_Helper::instance()->check_methods( '\UpdraftPlus_Filesystem_Functions', 'really_is_writable' );
        MainWP_Helper::instance()->check_methods( $updraftplus, array( 'backups_dir_location' ) );

        $next_scheduled_backup_database = wp_next_scheduled( 'updraft_backup_database' );
        if ( \UpdraftPlus_Options::get_updraft_option( 'updraft_interval_database', \UpdraftPlus_Options::get_updraft_option( 'updraft_interval' ) ) === \UpdraftPlus_Options::get_updraft_option( 'updraft_interval' ) ) {
            $next_scheduled_backup_database = ( 'Nothing currently scheduled' === $next_scheduled_backup ) ? $next_scheduled_backup : esc_html__( 'At the same time as the files backup', 'updraftplus' );
        } else {
            if ( $next_scheduled_backup_database ) {
                // Convert to GMT.
                $next_scheduled_backup_database_gmt = gmdate( 'Y-m-d H:i:s', $next_scheduled_backup_database );
                // Convert to blog time zone.
                $next_scheduled_backup_database = get_date_from_gmt( $next_scheduled_backup_database_gmt, 'D, F j, Y H:i' );
            } else {
                $next_scheduled_backup_database = esc_html__( 'Nothing currently scheduled', 'updraftplus' );
            }
        }

        $updraft_dir     = $updraftplus->backups_dir_location();
        $backup_disabled = ( \UpdraftPlus_Filesystem_Functions::really_is_writable( $updraft_dir ) ) ? 0 : 1;

        $current_timegmt = time();
        $current_time    = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $current_timegmt ), 'D, F j, Y H:i' );

        $out = array(
            'updraft_backup_disabled'    => $backup_disabled,
            'nextsched_files_gmt'        => $next_scheduled_backup_gmt,
            'nextsched_database_gmt'     => $next_scheduled_backup_gmt,
            'nextsched_current_timegmt'  => $current_timegmt,
            'nextsched_current_timezone' => $current_time,
        );

        if ( $next_scheduled_backup_gmt ) {
            $out['nextsched_files_timezone'] = $next_scheduled_backup;
        }

        if ( $next_scheduled_backup_gmt ) {
            $out['nextsched_database_timezone'] = $next_scheduled_backup_database;
        }

        $bh = $this->build_historystatus();

        // Fixed performance issue.
        if ( $with_hist ) {
            $out['updraft_historystatus'] = $bh['h'];
        }

        $out['updraft_count_backups'] = $bh['c'];

        $last_backup                       = $this->last_backup_html();
        $out['updraft_lastbackup_html']    = $last_backup['b'];
        $out['updraft_lastbackup_gmttime'] = $last_backup['lasttime_gmt'];

        return $out;
    }

    /**
     * Get next scheduled backup.
     *
     * @return array $out Return Next scheduled backup data.
     * @throws Exception|\Exception Error message.
     *
     * @uses UpdraftPlus_Options::get_updraft_option()
     * @uses \MainWP\Child\MainWP_Helper::instance()->check_classes_exists()
     * @uses \MainWP\Child\MainWP_Helper::instance()->check_methods()
     */
    private function next_scheduled_backups() {

        /** @global object $updraftplus UpdraftPlus object.  */
        global $updraftplus;

        // UNIX timestamp.
        $next_scheduled_backup              = wp_next_scheduled( 'updraft_backup' );
        $next_scheduled_backup_gmt          = 0;
        $next_scheduled_backup_database_gmt = 0;

        if ( $next_scheduled_backup ) {
            // Convert to GMT.
            $next_scheduled_backup_gmt = gmdate( 'Y-m-d H:i:s', $next_scheduled_backup );
            // Convert to blog time zone.
            $next_scheduled_backup = get_date_from_gmt( $next_scheduled_backup_gmt, 'D, F j, Y H:i' );
        } else {
            $next_scheduled_backup = esc_html__( 'Nothing currently scheduled', 'updraftplus' );
            $files_not_scheduled   = true;
        }

        $next_scheduled_backup_database = wp_next_scheduled( 'updraft_backup_database' );
        if ( \UpdraftPlus_Options::get_updraft_option( 'updraft_interval_database', \UpdraftPlus_Options::get_updraft_option( 'updraft_interval' ) ) == \UpdraftPlus_Options::get_updraft_option( 'updraft_interval' ) ) {
            if ( isset( $files_not_scheduled ) ) {
                $next_scheduled_backup_database = $next_scheduled_backup;
                $database_not_scheduled         = true;
            } else {
                $next_scheduled_backup_database           = esc_html__( 'At the same time as the files backup', 'updraftplus' );
                $next_scheduled_backup_database_same_time = true;
            }
        } else {
            if ( $next_scheduled_backup_database ) {
                // Convert to GMT.
                $next_scheduled_backup_database_gmt = gmdate( 'Y-m-d H:i:s', $next_scheduled_backup_database );
                // Convert to blog time zone.
                $next_scheduled_backup_database = get_date_from_gmt( $next_scheduled_backup_database_gmt, 'D, F j, Y H:i' );
            } else {
                $next_scheduled_backup_database = esc_html__( 'Nothing currently scheduled', 'updraftplus' );
                $database_not_scheduled         = true;
            }
        }

        $current_timegmt = time();
        $current_time    = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $current_timegmt ), 'D, F j, Y H:i' );

        $html = '<table class="ui single line table">
			<thead>
				<tr>
					<th>' . esc_html__( 'Files', 'mainwp-updraftplus-extension' ) . '</th>
					<th>' . esc_html__( 'Database', 'mainwp-updraftplus-extension' ) . '</th>
					<th>' . esc_html__( 'Time now', 'mainwp-updraftplus-extension' ) . '</th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td>' . $next_scheduled_backup . '</td>
					<td>' . $next_scheduled_backup_database . '</td>
					<td>' . $current_time . '</td>
				</tr>
			</tbody>
		</table>';

        MainWP_Helper::instance()->check_classes_exists( array( '\UpdraftPlus_Filesystem_Functions' ) );
        MainWP_Helper::instance()->check_methods( '\UpdraftPlus_Filesystem_Functions', 'really_is_writable' );

        $updraft_dir     = $updraftplus->backups_dir_location();
        $backup_disabled = ( \UpdraftPlus_Filesystem_Functions::really_is_writable( $updraft_dir ) ) ? 0 : 1;

        $out = array(
            'n'                          => $html,
            'updraft_backup_disabled'    => $backup_disabled,
            'nextsched_files_gmt'        => $next_scheduled_backup_gmt,
            'nextsched_database_gmt'     => $next_scheduled_backup_database_gmt,
            'nextsched_current_timegmt'  => $current_timegmt,
            'nextsched_current_timezone' => $current_time,
        );

        if ( $next_scheduled_backup_gmt ) {
            $out['nextsched_files_timezone'] = $next_scheduled_backup;
        }

        if ( $next_scheduled_backup_database_gmt ) {
            $out['nextsched_database_timezone'] = $next_scheduled_backup_database;
        }

        return $out;
    }

    /**
     * Delete backup set.
     *
     * @return array Return results array.
     * @throws Exception
     *
     * @uses $updraftplus::get_backup_history()
     * @uses $updraftplus::backup_time_nonce()
     * @uses $updraftplus::jobdata_set()
     * @uses $updraftplus::logfile_open()
     * @uses $updraftplus::backups_dir_location()
     * @uses $updraftplus::get_backupable_file_entities()
     * @uses UpdraftPlus_Options::get_updraft_option()
     * @uses UpdraftPlus_Options::update_updraft_option()
     * @uses UpdraftPlus_Backup_History::get_history()
     * @uses MainWP_Child_Updraft_Plus_Backups::build_historystatus()
     */
    private function deleteset() {

        /** @global object $updraftplus UpdraftPlus object.  */
        global $updraftplus;

        if ( method_exists( $updraftplus, 'get_backup_history' ) ) {
            $backups = $updraftplus->get_backup_history();
        } elseif ( class_exists( '\UpdraftPlus_Backup_History' ) ) {
            $backups = \UpdraftPlus_Backup_History::get_history();
        }
        $timestamp = isset( $_POST['backup_timestamp'] ) ? sanitize_text_field( wp_unslash( $_POST['backup_timestamp'] ) ) : '';
        if ( ! isset( $backups[ $timestamp ] ) ) {
            $bh = $this->build_historystatus();

            return array(
                'result'                => 'error',
                'message'               => esc_html__( 'Backup set not found', 'updraftplus' ),
                'updraft_historystatus' => $bh['h'],
                'updraft_count_backups' => $bh['c'],
            );
        }

        // You need a nonce before you can set job data. And we certainly don't yet have one.
        $updraftplus->backup_time_nonce();
        // Set the job type before logging, as there can be different logging destinations.
        $updraftplus->jobdata_set( 'job_type', 'delete' );
        $updraftplus->jobdata_set( 'job_time_ms', $updraftplus->job_time_ms );

        if ( \UpdraftPlus_Options::get_updraft_option( 'updraft_debug_mode' ) ) {
            $updraftplus->logfile_open( $updraftplus->nonce );
            set_error_handler( array( $updraftplus, 'php_error' ), E_ALL & ~E_STRICT ); // phpcs:ignore -- third party credits.
        }

        $updraft_dir         = $updraftplus->backups_dir_location();
        $backupable_entities = $updraftplus->get_backupable_file_entities( true, true );

        $nonce = isset( $backups[ $timestamp ]['nonce'] ) ? $backups[ $timestamp ]['nonce'] : '';

        $delete_from_service = array();

        if ( isset( $_POST['delete_remote'] ) && 1 == $_POST['delete_remote'] ) {
            // Locate backup set.
            if ( isset( $backups[ $timestamp ]['service'] ) ) {
                $services = is_string( $backups[ $timestamp ]['service'] ) ? array( $backups[ $timestamp ]['service'] ) : $backups[ $timestamp ]['service'];
                if ( is_array( $services ) ) {
                    foreach ( $services as $service ) {
                        if ( 'none' != $service ) {
                            $delete_from_service[] = $service;
                        }
                    }
                }
            }
        }

        $files_to_delete = array();
        foreach ( $backupable_entities as $key => $ent ) {
            if ( isset( $backups[ $timestamp ][ $key ] ) ) {
                $files_to_delete[ $key ] = $backups[ $timestamp ][ $key ];
            }
        }
        // Delete DB.
        if ( isset( $backups[ $timestamp ]['db'] ) ) {
            $files_to_delete['db'] = $backups[ $timestamp ]['db'];
        }

        // Also delete the log.
        if ( $nonce && ! \UpdraftPlus_Options::get_updraft_option( 'updraft_debug_mode' ) ) {
            $files_to_delete['log'] = "log.$nonce.txt";
        }

        unset( $backups[ $timestamp ] );
        \UpdraftPlus_Options::update_updraft_option( 'updraft_backup_history', $backups );

        $message = '';

        $local_deleted  = 0;
        $remote_deleted = 0;
        add_action( 'http_request_args', array( $updraftplus, 'modify_http_options' ) );
        foreach ( $files_to_delete as $key => $files ) {
            // Local deletion.
            if ( is_string( $files ) ) {
                $files = array( $files );
            }
            foreach ( $files as $file ) {
                if ( is_file( $updraft_dir . '/' . $file ) ) {
                    if ( unlink( $updraft_dir . '/' . $file ) ) {
                        $local_deleted ++;
                    }
                }
            }
            if ( 'log' != $key && count( $delete_from_service ) > 0 ) {
                foreach ( $delete_from_service as $service ) {
                    if ( 'email' == $service ) {
                        continue;
                    }
                    if ( file_exists( UPDRAFTPLUS_DIR . "/methods/$service.php" ) ) {
                        require_once UPDRAFTPLUS_DIR . "/methods/$service.php";
                    }
                    $objname = '\UpdraftPlus_BackupModule_' . $service;
                    $deleted = - 1;
                    if ( class_exists( $objname ) ) {
                        $remote_obj = new $objname();
                        $deleted    = $remote_obj->delete( $files );
                    }

                    if ( -1 !== $deleted && false !== $deleted ) {
                        $remote_deleted = $remote_deleted + count( $files );
                    }
                }
            }
        }
        remove_action( 'http_request_args', array( $updraftplus, 'modify_http_options' ) );
        $message .= esc_html__( 'The backup set has been removed.', 'updraftplus' ) . "\n";
        $message .= sprintf( esc_html__( 'Local archives deleted: %d', 'updraftplus' ), $local_deleted ) . "\n";
        $message .= sprintf( esc_html__( 'Remote archives deleted: %d', 'updraftplus' ), $remote_deleted ) . "\n";

        $updraftplus->log( 'Local archives deleted: ' . $local_deleted );
        $updraftplus->log( 'Remote archives deleted: ' . $remote_deleted );

        if ( \UpdraftPlus_Options::get_updraft_option( 'updraft_debug_mode' ) ) {
            restore_error_handler();
        }

        $bh = $this->build_historystatus();

        return array(
            'result'                => 'success',
            'message'               => $message,
            'updraft_historystatus' => $bh['h'],
            'updraft_count_backups' => $bh['c'],
        );
    }

    /**
     * Build backup history status.
     *
     * @return array Return response array.
     * @throws Exception Error message.
     *
     * @uses \MainWP\Child\MainWP_Helper::instance()->check_classes_exists()
     * @uses \MainWP\Child\MainWP_Helper::instance()->check_methods()
     * @uses \MainWP\Child\MainWP_Child_Updraft_Plus_Backups::existing_backup_table()
     * @uses \UpdraftPlus_Backup_History::get_history()
     */
    public function build_historystatus() {

        MainWP_Helper::instance()->check_classes_exists( '\UpdraftPlus_Backup_History' );
        MainWP_Helper::instance()->check_methods( '\UpdraftPlus_Backup_History', 'get_history' );

        $backup_history = \UpdraftPlus_Backup_History::get_history();

        $output = $this->existing_backup_table( $backup_history );

        if ( ! empty( $messages ) && is_array( $messages ) ) {
            $noutput = '<div style="margin-left: 100px; margin-top: 10px;"><ul style="list-style: disc inside;">';
            foreach ( $messages as $msg ) {
                $noutput .= '<li>' . ( ( $msg['desc'] ) ? $msg['desc'] . ': ' : '' ) . '<em>' . $msg['message'] . '</em></li>';
            }
            $noutput .= '</ul></div>';
            $output   = $noutput . $output;
        }

        return array(
            'h' => $output,
            'c' => count( $backup_history ),
        );
    }

    /**
     * Display History Status.
     *
     * @param null $remotescan Remote Scan $_POST data. Default: null
     * @param null $rescan     Rescan $_POST data. Default: null
     *
     * @return array Return history status data.
     * @throws Exception
     *
     * @uses MainWP_Child_Updraft_Plus_Backups::rebuild_backup_history()
     * @uses MainWP_Child_Updraft_Plus_Backups::existing_backup_table()
     * @uses UpdraftPlus_Backup_History::get_history()
     */
    public function historystatus( $remotescan = null, $rescan = null ) {

        /** @global object $updraftplus UpdraftPlus object.  */
        global $updraftplus;

        $remotescan = ( null !== $remotescan ) ? $remotescan : $_POST['remotescan'];
        $rescan     = ( null !== $rescan ) ? $rescan : $_POST['rescan'];

        if ( $rescan ) {
            $messages = $this->rebuild_backup_history( $remotescan );
        }

        $backup_history = \UpdraftPlus_Backup_History::get_history();
        $output         = $this->existing_backup_table( $backup_history );

        if ( ! empty( $messages ) && is_array( $messages ) ) {
            $noutput = '<div style="margin-left: 100px; margin-top: 10px;"><ul style="list-style: disc inside;">';
            foreach ( $messages as $msg ) {
                $noutput .= '<li>' . ( ( $msg['desc'] ) ? $msg['desc'] . ': ' : '' ) . '<em>' . $msg['message'] . '</em></li>';
            }
            $noutput .= '</ul></div>';
            $output   = $noutput . $output;
        }

        return array(
            'n' => sprintf( esc_html__( 'Existing Backups', 'updraftplus' ) . ' (%d)', count( $backup_history ) ),
            't' => $output,
            'c' => count( $backup_history ),
            'm' => $updraftplus->detect_safe_mode(),
        );
    }


    /**
     * UpdraftPlus Download Backup.
     *
     * @return array|string|string[] Return response array.
     *
     * @uses $updraftplus::get_backupable_file_entities()
     * @uses $updraftplus::backup_time_nonce()
     * @uses $updraftplus::jobdata_set()
     * @uses $updraftplus::get_backup_history()
     * @uses $updraftplus::backups_dir_location()
     * @uses $updraftplus::spool_file()
     * @uses $updraftplus::log()
     * @uses $updraftplus::logfile_open()
     * @uses $updraftplus::logfile_name()
     * @uses $updraftplus::logfile_handle()
     * @uses UpdraftPlus_Options::get_updraft_option()
     * @uses UpdraftPlus_Backup_History::get_history()
     * @uses MainWP_Child_Updraft_Plus_Backups::close_browser_connection()
     * @uses MainWP_Child_Updraft_Plus_Backups::download_file()
     */
    public function updraft_download_backup() {

        set_time_limit( 900 );

        /** @global object $updraftplus UpdraftPlus object.  */
        global $updraftplus;

        if ( ! isset( $_REQUEST['timestamp'] ) || ! is_numeric( $_REQUEST['timestamp'] ) || ! isset( $_REQUEST['type'] ) ) {
            exit;
        }

        $findex = ( isset( $_REQUEST['findex'] ) ) ? $_REQUEST['findex'] : 0;
        if ( empty( $findex ) ) {
            $findex = 0;
        }

        $backupable_entities = $updraftplus->get_backupable_file_entities( true );
        $type_match          = false;
        foreach ( $backupable_entities as $type => $info ) {
            if ( $_REQUEST['type'] == $type ) {
                $type_match = true;
            }
        }

        if ( ! $type_match && 'db' != substr( $_REQUEST['type'], 0, 2 ) ) {
            exit;
        }

        // Get the information on what is wanted.
        $type      = isset( $_REQUEST['type'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['type'] ) ) : '';
        $timestamp = isset( $_REQUEST['timestamp'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['timestamp'] ) ) : '';

        // You need a nonce before you can set job data. And we certainly don't yet have one.
        $updraftplus->backup_time_nonce( $timestamp );

        $debug_mode = \UpdraftPlus_Options::get_updraft_option( 'updraft_debug_mode' );

        // Set the job type before logging, as there can be different logging destinations.
        $updraftplus->jobdata_set( 'job_type', 'download' );
        $updraftplus->jobdata_set( 'job_time_ms', $updraftplus->job_time_ms );

        // Retrieve the information from our backup history.
        if ( method_exists( $updraftplus, 'get_backup_history' ) ) {
            $backup_history = $updraftplus->get_backup_history();
        } elseif ( class_exists( '\UpdraftPlus_Backup_History' ) ) {
            $backup_history = \UpdraftPlus_Backup_History::get_history();
        }

        // Base name.
        $file = $backup_history[ $timestamp ][ $type ];

        // Deal with multi-archive sets.
        if ( is_array( $file ) ) {
            $file = $file[ $findex ];
        }

        // Where it should end up being downloaded to.
        $fullpath = $updraftplus->backups_dir_location() . '/' . $file;

        if ( isset( $_POST['stage'] ) && '2' == $_POST['stage'] ) {
            $updraftplus->spool_file( $type, $fullpath );

            return array();
        }

        if ( isset( $_POST['stage'] ) && 'delete' == $_POST['stage'] ) {
            unlink( $fullpath );
            $updraftplus->log( 'The file has been deleted' );

            return 'deleted';
        }

        // Note that log() assumes that the data is in _POST, not _GET.
        if ( $debug_mode ) {
            $updraftplus->logfile_open( $updraftplus->nonce );
        }

        set_error_handler( array( $updraftplus, 'php_error' ), E_ALL & ~E_STRICT ); // phpcs:ignore -- third party credits.

        $updraftplus->log( "Requested to obtain file: timestamp=$timestamp, type=$type, index=$findex" );

        $itext      = ( empty( $findex ) ) ? '' : $findex;
        $known_size = isset( $backup_history[ $timestamp ][ $type . $itext . '-size' ] ) ? $backup_history[ $timestamp ][ $type . $itext . '-size' ] : 0;

        $services = ( isset( $backup_history[ $timestamp ]['service'] ) ) ? $backup_history[ $timestamp ]['service'] : false;
        if ( is_string( $services ) ) {
            $services = array( $services );
        }

        $updraftplus->jobdata_set( 'service', $services );

        // Fetch it from the cloud, if we have not already got it.
        $needs_downloading = false;

        if ( ! file_exists( $fullpath ) ) {
            // if the file doesn't exist and they're using one of the cloud options, fetch it down from the cloud.
            $needs_downloading = true;
            $updraftplus->log( 'File does not yet exist locally - needs downloading' );
        } elseif ( $known_size > 0 && filesize( $fullpath ) < $known_size ) {
            $updraftplus->log( 'The file was found locally (' . filesize( $fullpath ) . ") but did not match the size in the backup history ( $known_size ) - will resume downloading" );
            $needs_downloading = true;
        } elseif ( $known_size > 0 ) {
            $updraftplus->log( 'The file was found locally and matched the recorded size from the backup history (' . round( $known_size / 1024, 1 ) . ' Kb)' );
        } else {
            $updraftplus->log( 'No file size was found recorded in the backup history. We will assume the local one is complete.' );
            $known_size = filesize( $fullpath );
        }

        // The AJAX responder that updates on progress wants to see this.
        $updraftplus->jobdata_set( 'dlfile_' . $timestamp . '_' . $type . '_' . $findex, "downloading:$known_size:$fullpath" );

        if ( $needs_downloading ) {
            $this->close_browser_connection();
            $is_downloaded = false;
            add_action( 'http_request_args', array( $updraftplus, 'modify_http_options' ) );
            foreach ( $services as $service ) {
                if ( $is_downloaded ) {
                    continue;
                }
                $download = $this->download_file( $file, $service );
                if ( is_readable( $fullpath ) && false !== $download ) {
                    clearstatcache();
                    $updraftplus->log( 'Remote fetch was successful (file size: ' . round( filesize( $fullpath ) / 1024, 1 ) . ' Kb)' );
                    $is_downloaded = true;
                } else {
                    clearstatcache();
                    if ( 0 === filesize( $fullpath ) ) {
                        unlink( $fullpath );
                    }
                    $updraftplus->log( 'Remote fetch failed' );
                }
            }
            remove_action( 'http_request_args', array( $updraftplus, 'modify_http_options' ) );
        }

        // Now, spool the thing to the browser.
        if ( is_file( $fullpath ) && is_readable( $fullpath ) ) {

            // That message is then picked up by the AJAX listener.
            $updraftplus->jobdata_set( 'dlfile_' . $timestamp . '_' . $type . '_' . $findex, 'downloaded:' . filesize( $fullpath ) . ":$fullpath" );

        } else {
            $updraftplus->jobdata_set( 'dlfile_' . $timestamp . '_' . $type . '_' . $findex, 'failed' );
            $updraftplus->jobdata_set( 'dlerrors_' . $timestamp . '_' . $type . '_' . $findex, $updraftplus->errors );
            $updraftplus->log( 'Remote fetch failed. File ' . $fullpath . ' did not exist or was unreadable. If you delete local backups then remote retrieval may have failed.' );
        }

        restore_error_handler();

        fclose( $updraftplus->logfile_handle );
        if ( ! $debug_mode ) {
            unlink( $updraftplus->logfile_name );
        }

        return array( 'result' => 'OK' );
    }

    /**
     * Usage requirements: Pass only a single service, as a string, into this function.
     *
     * @param string $file File to download.
     * @param string $service UpdraftPlus service method.
     *
     * @return bool Return file or FALSE on failure.
     *
     * @uses $updraftplus::log()
     * @uses $remote_obj::download()
     */
    private function download_file( $file, $service ) {

        /** @global object $updraftplus UpdraftPlus object.  */
        global $updraftplus;

        set_time_limit( 900 );

        $updraftplus->log( "Requested file from remote service: $service: $file" );

        $method_include = UPDRAFTPLUS_DIR . '/methods/' . $service . '.php';
        if ( file_exists( $method_include ) ) {
            require_once $method_include;
        }

        $objname = "UpdraftPlus_BackupModule_${service}";
        if ( method_exists( $objname, 'download' ) ) {
            $remote_obj = new $objname();

            return $remote_obj->download( $file );
        } else {
            $updraftplus->log( "Automatic backup restoration is not available with the method: $service." );
            $updraftplus->log( "$file: " . sprintf( esc_html__( "The backup archive for this file could not be found. The remote storage method in use (%s) does not allow us to retrieve files. To perform any restoration using UpdraftPlus, you will need to obtain a copy of this file and place it inside UpdraftPlus's working folder", 'updraftplus' ), $service ) . ' (' . $this->prune_updraft_dir_prefix( $updraftplus->backups_dir_location() ) . ')', 'error' );

            return false;
        }
    }

    /**
     * Prune Updraft directory prefix
     *
     * This options filter removes ABSPATH off the front of $updraft_dir, if it is given absolutely and contained within it.
     *
     * @param $updraft_dir Directory to prune.
     * @return false|string Return Pruned $updraft_dir or FALSE on failure.
     */
    public function prune_updraft_dir_prefix( $updraft_dir ) {
        if ( '/' == substr( $updraft_dir, 0, 1 ) || '\\' === substr( $updraft_dir, 0, 1 ) || preg_match( '/^[a-zA-Z]:/', $updraft_dir ) ) {
            $wcd = trailingslashit( WP_CONTENT_DIR );
            if ( strpos( $updraft_dir, $wcd ) === 0 ) {
                $updraft_dir = substr( $updraft_dir, strlen( $wcd ) );
            }
        }

        return $updraft_dir;
    }

    /**
     * Restore all downloaded backups from history.
     *
     * @return array Return response array.
     *
     * @uses UpdraftPlus_Backup_History::get_history()
     * @uses $updraftplus::get_backup_history()
     * @uses $updraftplus::backups_dir_location()
     * @uses $updraftplus::analyse_db_file(
     * @uses $updraftplus::get_backupable_file_entities(
     * @uses MainWP_Child_Updraft_Plus_Backups::analyse_db_file_old()
     */
    public function restore_alldownloaded() { // phpcs:ignore -- third party credit.

        /** @global object $updraftplus UpdraftPlus object. */
        global $updraftplus;

        if ( method_exists( $updraftplus, 'get_backup_history' ) ) {
            $backups = $updraftplus->get_backup_history();
        } elseif ( class_exists( '\UpdraftPlus_Backup_History' ) ) {
            $backups = \UpdraftPlus_Backup_History::get_history();
        }

        $updraft_dir = $updraftplus->backups_dir_location();

        $timestamp = (int) $_POST['timestamp'];
        if ( ! isset( $backups[ $timestamp ] ) ) {
            return array(
                'm' => '',
                'w' => '',
                'e' => esc_html__( 'No such backup set exists', 'updraftplus' ),
            );

        }

        $mess = array();
        parse_str( $_POST['restoreopts'], $res );
        if ( isset( $res['updraft_restore'] ) ) {
            set_error_handler( array( $this, 'get_php_errors' ), E_ALL & ~E_STRICT ); // phpcs:ignore -- third party credits.

            $elements = array_flip( $res['updraft_restore'] );

            $warn = array();
            $err  = array();

            set_time_limit( 900 );
            $max_execution_time = (int) ini_get( 'max_execution_time' );

            if ( $max_execution_time > 0 && $max_execution_time < 61 ) {
                $warn[] = sprintf( esc_html__( 'The PHP setup on this webserver allows only %s seconds for PHP to run, and does not allow this limit to be raised. If you have a lot of data to import, and if the restore operation times out, then you will need to ask your web hosting company for ways to raise this limit (or attempt the restoration piece-by-piece).', 'updraftplus' ), $max_execution_time );
            }

            if ( isset( $backups[ $timestamp ]['native'] ) && false === $backups[ $timestamp ]['native'] ) {
                $warn[] = esc_html__( 'This backup set was not known by UpdraftPlus to be created by the current WordPress installation, but was found in remote storage.', 'updraftplus' ) . ' ' . esc_html__( 'You should make sure that this really is a backup set intended for use on this website, before you restore (rather than a backup set of an unrelated website that was using the same storage location).', 'updraftplus' );
            }

            if ( isset( $elements['db'] ) ) {
                // Analyse the header of the database file + display results.
                if ( class_exists( '\UpdraftPlus_Encryption' ) ) {
                    list ( $mess2, $warn2, $err2, $info ) = $updraftplus->analyse_db_file( $timestamp, $res );
                } else {
                    list ( $mess2, $warn2, $err2, $info ) = $this->analyse_db_file_old( $timestamp, $res );
                }
                $mess = array_merge( $mess, $mess2 );
                $warn = array_merge( $warn, $warn2 );
                $err  = array_merge( $err, $err2 );
                foreach ( $backups[ $timestamp ] as $bid => $bval ) {
                    if ( 'db' !== $bid && 'db' === substr( $bid, 0, 2 ) && '-size' !== substr( $bid, - 5, 5 ) ) {
                        $warn[] = esc_html__( 'Only the WordPress database can be restored; you will need to deal with the external database manually.', 'updraftplus' );
                        break;
                    }
                }
            }

            $backupable_entities      = $updraftplus->get_backupable_file_entities( true, true );
            $backupable_plus_db       = $backupable_entities;
            $backupable_plus_db['db'] = array(
                'path'        => 'path-unused',
                'description' => esc_html__( 'Database', 'updraftplus' ),
            );

            if ( ! empty( $backups[ $timestamp ]['meta_foreign'] ) ) {
                $foreign_known = apply_filters( 'updraftplus_accept_archivename', array() );
                if ( ! is_array( $foreign_known ) || empty( $foreign_known[ $backups[ $timestamp ]['meta_foreign'] ] ) ) {
                    $err[] = sprintf( esc_html__( 'Backup created by unknown source (%s) - cannot be restored.', 'updraftplus' ), $backups[ $timestamp ]['meta_foreign'] );
                } else {
                    // For some reason, on PHP 5.5 passing by reference in a single array stopped working with apply_filters_ref_array (though not with do_action_ref_array).
                    $backupable_plus_db = apply_filters_ref_array(
                        'updraftplus_importforeign_backupable_plus_db',
                        array(
                            $backupable_plus_db,
                            array(
                                $foreign_known[ $backups[ $timestamp ]['meta_foreign'] ],
                                &$mess,
                                &$warn,
                                &$err,
                            ),
                        )
                    );
                }
            }

            foreach ( $backupable_plus_db as $type => $info ) {
                if ( ! isset( $elements[ $type ] ) ) {
                    continue;
                }
                $whatwegot = $backups[ $timestamp ][ $type ];
                if ( is_string( $whatwegot ) ) {
                    $whatwegot = array( $whatwegot );
                }
                $expected_index = 0;
                $missing        = '';
                ksort( $whatwegot );
                $outof = false;
                foreach ( $whatwegot as $index => $file ) {
                    if ( preg_match( '/\d+of(\d+)\.zip/', $file, $omatch ) ) {
                        $outof = max( $matches[1], 1 );
                    }
                    if ( $index !== $expected_index ) {
                        $missing .= ( '' === $missing ) ? ( 1 + $expected_index ) : ',' . ( 1 + $expected_index );
                    }
                    if ( ! file_exists( $updraft_dir . '/' . $file ) ) {
                        $err[] = sprintf( esc_html__( 'File not found (you need to upload it): %s', 'updraftplus' ), $updraft_dir . '/' . $file );
                    } elseif ( 0 === filesize( $updraft_dir . '/' . $file ) ) {
                        $err[] = sprintf( esc_html__( 'File was found, but is zero-sized (you need to re-upload it): %s', 'updraftplus' ), $file );
                    } else {
                        $itext = ( 0 === $index ) ? '' : $index;
                        if ( ! empty( $backups[ $timestamp ][ $type . $itext . '-size' ] ) && filesize( $updraft_dir . '/' . $file ) !== $backups[ $timestamp ][ $type . $itext . '-size' ] ) {
                            if ( empty( $warn['doublecompressfixed'] ) ) {
                                $warn[] = sprintf( esc_html__( 'File (%1$s) was found, but has a different size (%2$s) from what was expected (%3$s) - it may be corrupt.', 'updraftplus' ), $file, filesize( $updraft_dir . '/' . $file ), $backups[ $timestamp ][ $type . $itext . '-size' ] );
                            }
                        }
                        do_action_ref_array(
                            "updraftplus_checkzip_$type",
                            array(
                                $updraft_dir . '/' . $file,
                                &$mess,
                                &$warn,
                                &$err,
                            )
                        );
                    }
                    $expected_index ++;
                }
                do_action_ref_array( "updraftplus_checkzip_end_$type", array( &$mess, &$warn, &$err ) );
                // Detect missing archives where they are missing from the end of the set.
                if ( $outof > 0 && $expected_index < $outof ) {
                    for ( $j = $expected_index; $j < $outof; $j ++ ) {
                        $missing .= ( '' === $missing ) ? ( 1 + $j ) : ',' . ( 1 + $j );
                    }
                }
                if ( '' !== $missing ) {
                    $warn[] = sprintf( esc_html__( 'This multi-archive backup set appears to have the following archives missing: %s', 'updraftplus' ), $missing . ' (' . $info['description'] . ')' );
                }
            }

            if ( 0 === count( $err ) && 0 === count( $warn ) ) {
                $mess_first = esc_html__( 'The backup archive files have been successfully processed. Now press Restore again to proceed.', 'updraftplus' );
            } elseif ( 0 === count( $err ) ) {
                $mess_first = esc_html__( 'The backup archive files have been processed, but with some warnings. If all is well, then now press Restore again to proceed. Otherwise, cancel and correct any problems first.', 'updraftplus' );
            } else {
                $mess_first = esc_html__( 'The backup archive files have been processed, but with some errors. You will need to cancel and correct any problems before retrying.', 'updraftplus' );
            }

            if ( count( $this->logged ) > 0 ) {
                foreach ( $this->logged as $lwarn ) {
                    $warn[] = $lwarn;
                }
            }
            restore_error_handler();

            return array(
                'm' => '<p>' . $mess_first . '</p>' . implode( '<br>', $mess ),
                'w' => implode( '<br>', $warn ),
                'e' => implode( '<br>', $err ),
            );
        }
    }

    /**
     * Option filter template.
     *
     * @param string $val Filter to get.
     * @return mixed Return template.
     *
     * @uses $updraftplus::option_filter_get()
     */
    public function option_filter_template( $val ) {

        /** @global object $updraftplus UpdraftPlus object. */
        global $updraftplus;

        return $updraftplus->option_filter_get( 'template' );
    }

    /**
     * Option filter stylesheet.
     *
     * @param string $val Filter to get.
     * @return mixed Return template.
     *
     * @uses $updraftplus::option_filter_get()
     */
    public function option_filter_stylesheet( $val ) {

        /** @global object $updraftplus UpdraftPlus object. */
        global $updraftplus;

        return $updraftplus->option_filter_get( 'stylesheet' );
    }

    /**
     * Option filter template root.
     *
     * @param string $val Filter to get.
     * @return mixed Return template.
     *
     * @uses $updraftplus::option_filter_get()
     */
    public function option_filter_template_root( $val ) {

        /** @global object $updraftplus UpdraftPlus object. */
        global $updraftplus;

        return $updraftplus->option_filter_get( 'template_root' );
    }

    /**
     * Option filter stylesheet root.
     *
     * @param string $val Filter to get.
     * @return mixed Return template.
     *
     * @uses $updraftplus::option_filter_get()
     */
    public function option_filter_stylesheet_root($val ) {

        /** @global object $updraftplus UpdraftPlus object. */
        global $updraftplus;

        return $updraftplus->option_filter_get( 'stylesheet_root' );
    }


    /**
     * Display delete old directories form.
     */
    private function print_delete_old_dirs_form() {
        echo '<a href="#" class="button-primary" onclick="event.preventDefault(); mainwp_updraft_delete_old_dirs();">' . esc_html__( 'Delete Old Directories', 'updraftplus' ) . '</a>';
    }

    /**
     * Delete old directories.
     *
     * @param bool $show_return Whether or not to show removed directories in the output. Defualt: true.
     *
     * @return array Return response array.
     */
    private function delete_old_dirs_go( $show_return = true ) {
        ob_start();

        echo ( $show_return ) ? '<h1>UpdraftPlus - ' . esc_html__( 'Remove old directories', 'updraftplus' ) . '</h1>' : '<h2>' . esc_html__( 'Remove old directories', 'updraftplus' ) . '</h2>';
        $deleted = 0;
        if ( $this->delete_old_dirs() ) {
            echo '<p>' . esc_html__( 'Old directories successfully removed.', 'updraftplus' ) . '</p>';
            echo '<p>' . esc_html__( 'Now press Restore again to proceed.', 'updraftplus' ) . '</p><br/>';
            $deleted = 1;
        } else {
            echo '<p>', esc_html__( 'Old directory removal failed for some reason. You may want to do this manually.', 'updraftplus' ) . '</p><br/>';
        }

        $output = ob_get_clean();

        return array(
            'o' => $output,
            'd' => $deleted,
        );
    }

    /**
     * Delete the old directories that are created when a backup is restored.
     *
     * @return bool Return $ret, $ret3, $ret4. TRUE|FALSE.
     */
    private function delete_old_dirs() {

        /**
         * @global object $wp_filesystem WordPress filesystem.
         * @global object $updraftplus UpdraftPlus object.
         */
        global $wp_filesystem, $updraftplus;

        $credentials = request_filesystem_credentials( wp_nonce_url( \UpdraftPlus_Options::admin_page_url() . '?page=updraftplus&action=updraft_delete_old_dirs', 'updraftplus-credentialtest-nonce' ) );
        WP_Filesystem( $credentials );
        if ( $wp_filesystem->errors->get_error_code() ) {
            foreach ( $wp_filesystem->errors->get_error_messages() as $message ) {
                show_message( $message );
            }
            exit;
        }

        $ret = $this->delete_old_dirs_dir( $wp_filesystem->wp_content_dir() );

        $updraft_dir = $updraftplus->backups_dir_location();
        if ( $updraft_dir ) {
            $ret4 = ( $updraft_dir ) ? $this->delete_old_dirs_dir( $updraft_dir, false ) : true;
        } else {
            $ret4 = true;
        }

        $plugs = untrailingslashit( $wp_filesystem->wp_plugins_dir() );
        if ( $wp_filesystem->is_dir( $plugs . '-old' ) ) {
            print '<strong>' . esc_html__( 'Delete', 'updraftplus' ) . ': </strong>plugins-old: ';
            if ( ! $wp_filesystem->delete( $plugs . '-old', true ) ) {
                $ret3 = false;
                print '<strong>' . esc_html__( 'Failed', 'updraftplus' ) . '</strong><br>';
            } else {
                $ret3 = true;
                print '<strong>' . esc_html__( 'OK', 'updraftplus' ) . '</strong><br>';
            }
        } else {
            $ret3 = true;
        }

        return $ret && $ret3 && $ret4;
    }

    /**
     * Delete the directories within a directory.
     *
     * @param string $dir Directory to scan.
     * @param bool $wpfs Whether or not to use Wordpress filesystem to list directories, Default: true.
     * @return bool|string $ret Return FALSE & echo 'Failed' on failure or echo 'OK' on success.
     */
    private function delete_old_dirs_dir( $dir, $wpfs = true ) {

        $dir = trailingslashit( $dir );

        /**
         * @global object $wp_filesystem WordPress filesystem.
         * @global object $updraftplus UpdraftPlus object.
         */
        global $wp_filesystem, $updraftplus;

        if ( $wpfs ) {
            $list = $wp_filesystem->dirlist( $dir );
        } else {
            $list = scandir( $dir );
        }
        if ( ! is_array( $list ) ) {
            return false;
        }

        $ret = true;
        foreach ( $list as $item ) {
            $name = ( is_array( $item ) ) ? $item['name'] : $item;
            if ( '-old' == substr( $name, - 4, 4 ) ) {
                // recursively delete.
                print '<strong>' . esc_html__( 'Delete', 'updraftplus' ) . ': </strong>' . htmlspecialchars( $name ) . ': ';

                if ( $wpfs ) {
                    if ( ! $wp_filesystem->delete( $dir . $name, true ) ) {
                        $ret = false;
                        echo '<strong>' . esc_html__( 'Failed', 'updraftplus' ) . '</strong><br>';
                    } else {
                        echo '<strong>' . esc_html__( 'OK', 'updraftplus' ) . '</strong><br>';
                    }
                } else {
                    if ( $updraftplus->remove_local_directory( $dir . $name ) ) {
                        echo '<strong>' . esc_html__( 'OK', 'updraftplus' ) . '</strong><br>';
                    } else {
                        $ret = false;
                        echo '<strong>' . esc_html__( 'Failed', 'updraftplus' ) . '</strong><br>';
                    }
                }
            }
        }

        return $ret;
    }


    /**
     * Show admin warning.
     *
     * @param string $message Warning message to display.
     * @param string $class UpdraftPlus message CSS class.
     */
    public function show_admin_warning( $message, $class = 'updated' ) {
        echo '<div class="updraftmessage ' . $class . '">' . "<p>$message</p></div>";
    }

    /**
     * Analyse old database file.
     *
     * @param string $timestamp   File timestamp.
     * @param string $res         UpdraftPlus response.
     * @param bool   $db_file     Whether or not a DB file was found. Default: false.
     * @param bool   $header_only Whether or not file only has headers. Default: false.
     *
     * @return array[] Return array( $mess, $warn, $err, $info ).
     *
     * @uses $updraftplus::backups_dir_location()
     * @uses $updraftplus::get_max_packet_size()
     * @uses $updraftplus::get_backup_history()
     * @uses $updraftplus::is_db_encrypted()
     * @uses $updraftplus::decrypt()
     * @uses UpdraftPlus_Options::get_updraft_option()
     * @uses UpdraftPlus_Backup_History::get_history()
     * @uses MainWP_Child_Updraft_Plus_Backups::gzopen_for_read()
     */
    private function analyse_db_file_old( $timestamp, $res, $db_file = false, $header_only = false ) { // phpcs:ignore -- third party credit.

        $mess = array();
        $warn = array();
        $err  = array();
        $info = array();

        /**
         * @global object $wp_filesystem WordPress filesystem.
         * @global object $updraftplus UpdraftPlus object.
         */
        global $updraftplus, $wp_version;

        include ABSPATH . WPINC . '/version.php';

        $updraft_dir = $updraftplus->backups_dir_location();

        if ( false === $db_file ) {
            // This attempts to raise the maximum packet size. This can't be done within the session, only globally. Therefore, it has to be done before the session starts; in our case, during the pre-analysis.
            $updraftplus->get_max_packet_size();

            if ( method_exists( $updraftplus, 'get_backup_history' ) ) {
                $backup = $updraftplus->get_backup_history( $timestamp );
            } elseif ( class_exists( '\UpdraftPlus_Backup_History' ) ) {
                $backup = \UpdraftPlus_Backup_History::get_history( $timestamp );
            }

            if ( ! isset( $backup['nonce'] ) || ! isset( $backup['db'] ) ) {
                return array( $mess, $warn, $err, $info );
            }

            $db_file = ( is_string( $backup['db'] ) ) ? $updraft_dir . '/' . $backup['db'] : $updraft_dir . '/' . $backup['db'][0];
        }

        if ( ! is_readable( $db_file ) ) {
            return array( $mess, $warn, $err, $info );
        }

        // Encrypted - decrypt it.
        if ( $updraftplus->is_db_encrypted( $db_file ) ) {

            $encryption = empty( $res['updraft_encryptionphrase'] ) ? \UpdraftPlus_Options::get_updraft_option( 'updraft_encryptionphrase' ) : $res['updraft_encryptionphrase'];

            if ( ! $encryption ) {
                if ( class_exists( '\UpdraftPlus_Addon_MoreDatabase' ) ) {
                    $err[] = sprintf( esc_html__( 'Error: %s', 'updraftplus' ), esc_html__( 'Decryption failed. The database file is encrypted, but you have no encryption key entered.', 'updraftplus' ) );
                } else {
                    $err[] = sprintf( esc_html__( 'Error: %s', 'updraftplus' ), esc_html__( 'Decryption failed. The database file is encrypted.', 'updraftplus' ) );
                }

                return array( $mess, $warn, $err, $info );
            }

            $ciphertext = $updraftplus->decrypt( $db_file, $encryption );

            if ( $ciphertext ) {
                $new_db_file = $updraft_dir . '/' . basename( $db_file, '.crypt' );
                if ( ! file_put_contents( $new_db_file, $ciphertext ) ) {
                    $err[] = esc_html__( 'Failed to write out the decrypted database to the filesystem.', 'updraftplus' );

                    return array( $mess, $warn, $err, $info );
                }
                $db_file = $new_db_file;
            } else {
                $err[] = esc_html__( 'Decryption failed. The most likely cause is that you used the wrong key.', 'updraftplus' );

                return array( $mess, $warn, $err, $info );
            }
        }

        // Even the empty schema when gzipped comes to 1565 bytes; a blank WP 3.6 install at 5158. But we go low, in case someone wants to share single tables.
        if ( filesize( $db_file ) < 1000 ) {
            $err[] = sprintf( esc_html__( 'The database is too small to be a valid WordPress database (size: %s Kb).', 'updraftplus' ), round( filesize( $db_file ) / 1024, 1 ) );

            return array( $mess, $warn, $err, $info );
        }

        $is_plain = ( '.gz' === substr( $db_file, - 3, 3 ) ) ? false : true;

        $dbhandle = ( $is_plain ) ? fopen( $db_file, 'r' ) : $this->gzopen_for_read( $db_file, $warn, $err );
        if ( ! is_resource( $dbhandle ) ) {
            $err[] = esc_html__( 'Failed to open database file.', 'updraftplus' );

            return array( $mess, $warn, $err, $info );
        }

        // Analyse the file, print the results.

        $line               = 0;
        $old_siteurl        = '';
        $old_home           = '';
        $old_table_prefix   = '';
        $old_siteinfo       = array();
        $gathering_siteinfo = true;
        $old_wp_version     = '';
        $old_php_version    = '';

        $tables_found = array();

        $wanted_tables = array(
            'terms',
            'term_taxonomy',
            'term_relationships',
            'commentmeta',
            'comments',
            'links',
            'options',
            'postmeta',
            'posts',
            'users',
            'usermeta',
        );

        $migration_warning = false;

        // Don't set too high - we want a timely response returned to the browser.
        set_time_limit( 90 );

        $count_wanted_tables = count( $wanted_tables );

        while ( ( ( $is_plain && ! feof( $dbhandle ) ) || ( ! $is_plain && ! gzeof( $dbhandle ) ) ) && ( $line < 100 || ( ! $header_only && $count_wanted_tables > 0 ) ) ) {
            $line ++;
            // Up to 1Mb.
            $buffer = ( $is_plain ) ? rtrim( fgets( $dbhandle, 1048576 ) ) : rtrim( gzgets( $dbhandle, 1048576 ) );
            // Comments are what we are interested in.
            if ( '#' === substr( $buffer, 0, 1 ) ) {
                if ( '' === $old_siteurl && preg_match( '/^\# Backup of: (http(.*))$/', $buffer, $matches ) ) {
                    $old_siteurl = untrailingslashit( $matches[1] );
                    $mess[]      = esc_html__( 'Backup of:', 'updraftplus' ) . ' ' . htmlspecialchars( $old_siteurl ) . ( ( ! empty( $old_wp_version ) ) ? ' ' . sprintf( esc_html__( '(version: %s)', 'updraftplus' ), $old_wp_version ) : '' );
                    // Check for should-be migration.
                    if ( ! $migration_warning && untrailingslashit( site_url() ) !== $old_siteurl ) {
                        $migration_warning = true;
                        $powarn            = apply_filters( 'updraftplus_dbscan_urlchange', sprintf( esc_html__( 'Warning: %s', 'updraftplus' ), '<a href="http://updraftplus.com/shop/migrator/">' . esc_html__( 'This backup set is from a different site - this is not a restoration, but a migration. You need the Migrator add-on in order to make this work.', 'updraftplus' ) . '</a>' ), $old_siteurl, $res );
                        if ( ! empty( $powarn ) ) {
                            $warn[] = $powarn;
                        }
                    }
                } elseif ( '' === $old_home && preg_match( '/^\# Home URL: (http(.*))$/', $buffer, $matches ) ) {
                    $old_home = untrailingslashit( $matches[1] );
                    // Check for should-be migration.
                    if ( ! $migration_warning && home_url() !== $old_home ) {
                        $migration_warning = true;
                        $powarn            = apply_filters( 'updraftplus_dbscan_urlchange', sprintf( esc_html__( 'Warning: %s', 'updraftplus' ), '<a href="http://updraftplus.com/shop/migrator/">' . esc_html__( 'This backup set is from a different site - this is not a restoration, but a migration. You need the Migrator add-on in order to make this work.', 'updraftplus' ) . '</a>' ), $old_home, $res );
                        if ( ! empty( $powarn ) ) {
                            $warn[] = $powarn;
                        }
                    }
                } elseif ( '' === $old_wp_version && preg_match( '/^\# WordPress Version: ([0-9]+(\.[0-9]+)+)(-[-a-z0-9]+,)?(.*)$/', $buffer, $matches ) ) {
                    $old_wp_version = $matches[1];
                    if ( ! empty( $matches[3] ) ) {
                        $old_wp_version .= substr( $matches[3], 0, strlen( $matches[3] ) - 1 );
                    }
                    if ( version_compare( $old_wp_version, $wp_version, '>' ) ) {
                        $warn[] = sprintf( esc_html__( 'You are importing from a newer version of WordPress (%1$s) into an older one (%2$s). There are no guarantees that WordPress can handle this.', 'updraftplus' ), $old_wp_version, $wp_version );
                    }
                    if ( preg_match( '/running on PHP ([0-9]+\.[0-9]+)(\s|\.)/', $matches[4], $nmatches ) && preg_match( '/^([0-9]+\.[0-9]+)(\s|\.)/', PHP_VERSION, $cmatches ) ) {
                        $old_php_version     = $nmatches[1];
                        $current_php_version = $cmatches[1];
                        if ( version_compare( $old_php_version, $current_php_version, '>' ) ) {
                            $warn[] = sprintf( esc_html__( 'The site in this backup was running on a webserver with version %1$s of %2$s. ', 'updraftplus' ), $old_php_version, 'PHP' ) . ' ' . sprintf( esc_html__( 'This is significantly newer than the server which you are now restoring onto (version %s).', 'updraftplus' ), PHP_VERSION ) . ' ' . sprintf( esc_html__( 'You should only proceed if you cannot update the current server and are confident (or willing to risk) that your plugins/themes/etc. are compatible with the older %s version.', 'updraftplus' ), 'PHP' ) . ' ' . sprintf( esc_html__( 'Any support requests to do with %s should be raised with your web hosting company.', 'updraftplus' ), 'PHP' );
                        }
                    }
                } elseif ( '' === $old_table_prefix && ( preg_match( '/^\# Table prefix: (\S+)$/', $buffer, $matches ) || preg_match( '/^-- Table prefix: (\S+)$/i', $buffer, $matches ) ) ) {
                    $old_table_prefix = $matches[1];
                } elseif ( empty( $info['label'] ) && preg_match( '/^\# Label: (.*)$/', $buffer, $matches ) ) {
                    $info['label'] = $matches[1];
                    $mess[]        = esc_html__( 'Backup label:', 'updraftplus' ) . ' ' . htmlspecialchars( $info['label'] );
                } elseif ( $gathering_siteinfo && preg_match( '/^\# Site info: (\S+)$/', $buffer, $matches ) ) {
                    if ( 'end' === $matches[1] ) {
                        $gathering_siteinfo = false;
                        // Sanity checks.
                        if ( isset( $old_siteinfo['multisite'] ) && ! $old_siteinfo['multisite'] && is_multisite() ) {
                            // Just need to check that you're crazy.
                            if ( ! defined( 'UPDRAFTPLUS_EXPERIMENTAL_IMPORTINTOMULTISITE' ) || true !== UPDRAFTPLUS_EXPERIMENTAL_IMPORTINTOMULTISITE ) {
                                $err[] = sprintf( esc_html__( 'Error: %s', 'updraftplus' ), esc_html__( 'You are running on WordPress multisite - but your backup is not of a multisite site.', 'updraftplus' ) );

                                return array( $mess, $warn, $err, $info );
                            }
                            // Got the needed code?
                            if ( ! class_exists( '\UpdraftPlusAddOn_MultiSite' ) || ! class_exists( '\UpdraftPlus_Addons_Migrator' ) ) {
                                $err[] = sprintf( esc_html__( 'Error: %s', 'updraftplus' ), esc_html__( 'To import an ordinary WordPress site into a multisite installation requires both the multisite and migrator add-ons.', 'updraftplus' ) );

                                return array( $mess, $warn, $err, $info );
                            }
                        } elseif ( isset( $old_siteinfo['multisite'] ) && $old_siteinfo['multisite'] && ! is_multisite() ) {
                            $warn[] = esc_html__( 'Warning:', 'updraftplus' ) . ' ' . esc_html__( 'Your backup is of a WordPress multisite install; but this site is not. Only the first site of the network will be accessible.', 'updraftplus' ) . ' <a href="http://codex.wordpress.org/Create_A_Network">' . esc_html__( 'If you want to restore a multisite backup, you should first set up your WordPress installation as a multisite.', 'updraftplus' ) . '</a>';
                        }
                    } elseif ( preg_match( '/^([^=]+)=(.*)$/', $matches[1], $kvmatches ) ) {
                        $key = $kvmatches[1];
                        $val = $kvmatches[2];
                        if ( 'multisite' === $key && $val ) {
                            $mess[] = '<strong>' . esc_html__( 'Site information:', 'updraftplus' ) . '</strong> is a WordPress Network';
                        }
                        $old_siteinfo[ $key ] = $val;
                    }
                }
            } elseif ( preg_match( '/^\s*create table \`?([^\`\(]*)\`?\s*\(/i', $buffer, $matches ) ) {
                $table          = $matches[1];
                $tables_found[] = $table;
                if ( $old_table_prefix ) {
                    // Remove prefix.
                    $table = $updraftplus->str_replace_once( $old_table_prefix, '', $table );
                    if ( in_array( $table, $wanted_tables ) ) {
                        $wanted_tables = array_diff( $wanted_tables, array( $table ) );
                    }
                }
            }
        }

        if ( $is_plain ) {
            fclose( $dbhandle );
        } else {
            gzclose( $dbhandle );
        }

        $missing_tables = array();
        if ( $old_table_prefix ) {
            if ( ! $header_only ) {
                foreach ( $wanted_tables as $table ) {
                    if ( ! in_array( $old_table_prefix . $table, $tables_found ) ) {
                        $missing_tables[] = $table;
                    }
                }
                if ( count( $missing_tables ) > 0 ) {
                    $warn[] = sprintf( esc_html__( 'This database backup is missing core WordPress tables: %s', 'updraftplus' ), implode( ', ', $missing_tables ) );
                }
            }
        } else {
            if ( empty( $backup['meta_foreign'] ) ) {
                $warn[] = esc_html__( 'UpdraftPlus was unable to find the table prefix when scanning the database backup.', 'updraftplus' );
            }
        }

        return array( $mess, $warn, $err, $info );
    }


    /**
     * Analyse database file.
     *
     * @param string $timestamp   File timestamp.
     * @param string $res         UpdraftPlus response.
     * @param bool   $db_file     Whether or not a DB file was found. Default: false.
     * @param bool   $header_only Whether or not file only has headers. Default: false.
     *
     * @return array[] Return array( $mess, $warn, $err, $info ).
     *
     * @uses MainWP_Child_Updraft_Plus_Backups::gzopen_for_read()
     * @uses $wpdb::db_version()
     * @uses $updraftplus::get_wordpress_version()
     * @uses $updraftplus::backups_dir_location()
     * @uses $updraftplus::get_max_packet_size()
     * @uses $updraftplus::decrypt()
     * @uses $updraftplus::get_similar_collate_related_to_charset()
     * @uses $updraftplus::mod_rewrite_unavailable()
     * @uses $updraftplus::get_similar_collate_based_on_ocuurence_count()
     * @uses UpdraftPlus_Backup_History::get_history()
     * @uses UpdraftPlus_Encryption::is_file_encrypted(
     * @uses UpdraftPlus_Options::get_updraft_option()
     * @uses UpdraftPlus_Manipulation_Functions::normalise_url()
     * @uses UpdraftPlus_Manipulation_Functions::str_replace_once()
     * @uses UpdraftPlus_Manipulation_Functions::get_matching_str_from_array_elems()
     */
    public function analyse_db_file($timestamp, $res, $db_file = false, $header_only = false ) { // phpcs:ignore -- third party credit.
        global $updraftplus;

        $mess       = array();
        $warn       = array();
        $err        = array();
        $info       = array();

        $wp_version = $updraftplus->get_wordpress_version();

        /** @global object $wpdb wpdb. */
        global $wpdb;

        $updraft_dir = $updraftplus->backups_dir_location();

        if ( false === $db_file ) {
            // This attempts to raise the maximum packet size. This can't be done within the session, only globally. Therefore, it has to be done before the session starts; in our case, during the pre-analysis.
            $updraftplus->get_max_packet_size();

            $backup = \UpdraftPlus_Backup_History::get_history( $timestamp );
            if ( ! isset( $backup['nonce'] ) || ! isset( $backup['db'] ) ) {
                return array( $mess, $warn, $err, $info );
            }

            $db_file = ( is_string( $backup['db'] ) ) ? $updraft_dir . '/' . $backup['db'] : $updraft_dir . '/' . $backup['db'][0];
        }

        if ( ! is_readable( $db_file ) ) {
            return array( $mess, $warn, $err, $info );
        }

        // Encrypted - decrypt it.
        if ( \UpdraftPlus_Encryption::is_file_encrypted( $db_file ) ) {

            $encryption = empty( $res['updraft_encryptionphrase'] ) ? \UpdraftPlus_Options::get_updraft_option( 'updraft_encryptionphrase' ) : $res['updraft_encryptionphrase'];

            if ( ! $encryption ) {
                if ( class_exists( '\UpdraftPlus_Addon_MoreDatabase' ) ) {
                    $err[] = sprintf( esc_html__( 'Error: %s', 'updraftplus' ), esc_html__( 'Decryption failed. The database file is encrypted, but you have no encryption key entered.', 'updraftplus' ) );
                } else {
                    $err[] = sprintf( esc_html__( 'Error: %s', 'updraftplus' ), esc_html__( 'Decryption failed. The database file is encrypted.', 'updraftplus' ) );
                }
                return array( $mess, $warn, $err, $info );
            }

            $decrypted_file = \UpdraftPlus_Encryption::decrypt( $db_file, $encryption );

            if ( is_array( $decrypted_file ) ) {
                $db_file = $decrypted_file['fullpath'];
            } else {
                $err[] = esc_html__( 'Decryption failed. The most likely cause is that you used the wrong key.', 'updraftplus' );
                return array( $mess, $warn, $err, $info );
            }
        }

        // Even the empty schema when gzipped comes to 1565 bytes; a blank WP 3.6 install at 5158. But we go low, in case someone wants to share single tables.
        if ( filesize( $db_file ) < 1000 ) {
            $err[] = sprintf( esc_html__( 'The database is too small to be a valid WordPress database (size: %s Kb).', 'updraftplus' ), round( filesize( $db_file ) / 1024, 1 ) );
            return array( $mess, $warn, $err, $info );
        }

        $is_plain = ( '.gz' == substr( $db_file, -3, 3 ) ) ? false : true;

        $dbhandle = ( $is_plain ) ? fopen( $db_file, 'r' ) : $this->gzopen_for_read( $db_file, $warn, $err );
        if ( ! is_resource( $dbhandle ) ) {
            $err[] = esc_html__( 'Failed to open database file.', 'updraftplus' );
            return array( $mess, $warn, $err, $info );
        }

        $info['timestamp'] = $timestamp;

        // Analyse the file, print the results.

        $line               = 0;
        $old_siteurl        = '';
        $old_home           = '';
        $old_table_prefix   = '';
        $old_siteinfo       = array();
        $gathering_siteinfo = true;
        $old_wp_version     = '';
        $old_php_version    = '';

        $tables_found      = array();
        $db_charsets_found = array();

        $wanted_tables = array( 'terms', 'term_taxonomy', 'term_relationships', 'commentmeta', 'comments', 'links', 'options', 'postmeta', 'posts', 'users', 'usermeta' );

        $migration_warning = false;
        $processing_create = false;
        $db_version        = $wpdb->db_version();

        $default_dbscan_timeout = ( filesize( $db_file ) < 31457280 ) ? 120 : 240;
        $dbscan_timeout         = ( defined( 'UPDRAFTPLUS_DBSCAN_TIMEOUT' ) && is_numeric( UPDRAFTPLUS_DBSCAN_TIMEOUT ) ) ? UPDRAFTPLUS_DBSCAN_TIMEOUT : $default_dbscan_timeout;
        set_time_limit( $dbscan_timeout );

        // We limit the time that we spend scanning the file for character sets.
        $db_charset_collate_scan_timeout                         = ( defined( 'UPDRAFTPLUS_DB_CHARSET_COLLATE_SCAN_TIMEOUT' ) && is_numeric( UPDRAFTPLUS_DB_CHARSET_COLLATE_SCAN_TIMEOUT ) ) ? UPDRAFTPLUS_DB_CHARSET_COLLATE_SCAN_TIMEOUT : 10;
        $charset_scan_start_time                                 = microtime( true );
        $db_supported_character_sets_res                         = $GLOBALS['wpdb']->get_results( 'SHOW CHARACTER SET', OBJECT_K );
        $db_supported_character_sets                             = ( null !== $db_supported_character_sets_res ) ? $db_supported_character_sets_res : array();
        $db_charsets_found                                       = array();
        $db_supported_collations_res                             = $GLOBALS['wpdb']->get_results( 'SHOW COLLATION', OBJECT_K );
        $db_supported_collations                                 = ( null !== $db_supported_collations_res ) ? $db_supported_collations_res : array();
        $db_charsets_found                                       = array();
        $db_collates_found                                       = array();
        $db_supported_charset_related_to_unsupported_collation   = false;
        $db_supported_charsets_related_to_unsupported_collations = array();
        $count_wanted_tables                                     = count( $wanted_tables );
        while ( ( ( $is_plain && ! feof( $dbhandle ) ) || ( ! $is_plain && ! gzeof( $dbhandle ) ) ) && ( $line < 100 || ( ! $header_only && $count_wanted_tables > 0 ) || ( ( microtime( true ) - $charset_scan_start_time ) < $db_charset_collate_scan_timeout && ! empty( $db_supported_character_sets ) ) ) ) {
            $line++;
            // Up to 1MB.
            $buffer = ( $is_plain ) ? rtrim( fgets( $dbhandle, 1048576 ) ) : rtrim( gzgets( $dbhandle, 1048576 ) );
            // Comments are what we are interested in.
            if ( substr( $buffer, 0, 1 ) == '#' ) {
                $processing_create = false;
                if ( '' == $old_siteurl && preg_match( '/^\# Backup of: (http(.*))$/', $buffer, $matches ) ) {
                    $old_siteurl = untrailingslashit( $matches[1] );
                    $mess[]      = esc_html__( 'Backup of:', 'updraftplus' ) . ' ' . htmlspecialchars( $old_siteurl ) . ( ( ! empty( $old_wp_version ) ) ? ' ' . sprintf( esc_html__( '(version: %s)', 'updraftplus' ), $old_wp_version ) : '' );
                    // Check for should-be migration.
                    if ( untrailingslashit( site_url() ) != $old_siteurl ) {
                        if ( ! $migration_warning ) {
                            $migration_warning = true;
                            $info['migration'] = true;
                            if ( \UpdraftPlus_Manipulation_Functions::normalise_url( $old_siteurl ) == \UpdraftPlus_Manipulation_Functions::normalise_url( site_url() ) ) {
                                // Same site migration with only http/https difference.
                                $info['same_url']      = false;
                                $old_siteurl_parsed    = wp_parse_url( $old_siteurl );
                                $actual_siteurl_parsed = wp_parse_url( site_url() );
                                if ( ( 0 === stripos( $old_siteurl_parsed['host'], 'www.' ) && 0 !== stripos( $actual_siteurl_parsed['host'], 'www.' ) ) || ( stripos( $old_siteurl_parsed['host'], 'www.' ) !== 0 && stripos( $actual_siteurl_parsed['host'], 'www.' ) === 0 ) ) {
                                    $powarn = sprintf( esc_html__( 'The website address in the backup set (%1$s) is slightly different from that of the site now (%2$s). This is not expected to be a problem for restoring the site, as long as visits to the former address still reach the site.', 'updraftplus' ), $old_siteurl, site_url() ) . ' ';
                                } else {
                                    $powarn = '';
                                }
                                if ( ( 'https' == $old_siteurl_parsed['scheme'] && 'http' == $actual_siteurl_parsed['scheme'] ) || ( 'http' == $old_siteurl_parsed['scheme'] && 'https' == $actual_siteurl_parsed['scheme'] ) ) {
                                    $powarn .= sprintf( esc_html__( 'This backup set is of this site, but at the time of the backup you were using %1$s, whereas the site now uses %2$s.', 'updraftplus' ), $old_siteurl_parsed['scheme'], $actual_siteurl_parsed['scheme'] );
                                    if ( 'https' == $old_siteurl_parsed['scheme'] ) {
                                        $powarn .= ' ' . apply_filters( 'updraftplus_https_to_http_additional_warning', sprintf( esc_html__( 'This restoration will work if you still have an SSL certificate (i.e. can use https) to access the site. Otherwise, you will want to use %s to search/replace the site address so that the site can be visited without https.', 'updraftplus' ), '<a href="https://updraftplus.com/shop/migrator/">' . esc_html__( 'the migrator add-on', 'updraftplus' ) . '</a>' ) );
                                    } else {
                                        $powarn .= ' ' . apply_filters( 'updraftplus_http_to_https_additional_warning', sprintf( esc_html__( 'As long as your web hosting allows http (i.e. non-SSL access) or will forward requests to https (which is almost always the case), this is no problem. If that is not yet set up, then you should set it up, or use %s so that the non-https links are automatically replaced.', 'updraftplus' ), apply_filters( 'updraftplus_migrator_addon_link', '<a href="https://updraftplus.com/shop/migrator/">' . esc_html__( 'the migrator add-on', 'updraftplus' ) . '</a>' ) ) );
                                    }
                                } else {
                                    $powarn .= apply_filters( 'updraftplus_dbscan_urlchange_www_append_warning', '' );
                                }
                                $warn[] = $powarn;
                            } else {
                                // For completely different site migration.
                                $info['same_url'] = false;
                                $warn[]           = apply_filters( 'updraftplus_dbscan_urlchange', '<a href="https://updraftplus.com/shop/migrator/">' . esc_html__( 'This backup set is from a different site - this is not a restoration, but a migration. You need the Migrator add-on in order to make this work.', 'updraftplus' ) . '</a>', $old_siteurl, $res );
                            }
                            if ( ! class_exists( '\UpdraftPlus_Addons_Migrator' ) ) {
                                $warn[] .= '<strong><a href="' . apply_filters( 'updraftplus_com_link', 'https://updraftplus.com/faqs/tell-me-more-about-the-search-and-replace-site-location-in-the-database-option/' ) . '">' . esc_html__( 'You can search and replace your database (for migrating a website to a new location/URL) with the Migrator add-on - follow this link for more information', 'updraftplus' ) . '</a></strong>';
                            }
                        }

                        if ( $updraftplus->mod_rewrite_unavailable( false ) ) {
                            $warn[] = sprintf( esc_html__( 'You are using the %1$s webserver, but do not seem to have the %2$s module loaded.', 'updraftplus' ), 'Apache', 'mod_rewrite' ) . ' ' . sprintf( esc_html__( 'You should enable %1$s to make any pretty permalinks (e.g. %2$s) work', 'updraftplus' ), 'mod_rewrite', 'http://example.com/my-page/' );
                        }
                    } else {
                        // For exactly same URL site restoration.
                        $info['same_url'] = true;
                    }
                } elseif ( '' == $old_home && preg_match( '/^\# Home URL: (http(.*))$/', $buffer, $matches ) ) {
                    $old_home = untrailingslashit( $matches[1] );
                    // Check for should-be migration.
                    if ( ! $migration_warning && home_url() != $old_home ) {
                        $migration_warning = true;
                        $powarn            = apply_filters( 'updraftplus_dbscan_urlchange', '<a href="https://updraftplus.com/shop/migrator/">' . esc_html__( 'This backup set is from a different site - this is not a restoration, but a migration. You need the Migrator add-on in order to make this work.', 'updraftplus' ) . '</a>', $old_home, $res );
                        if ( ! empty( $powarn ) ) {
                            $warn[] = $powarn;
                        }
                    }
                } elseif ( ! isset( $info['created_by_version'] ) && preg_match( '/^\# Created by UpdraftPlus version ([\d\.]+)/', $buffer, $matches ) ) {
                    $info['created_by_version'] = trim( $matches[1] );
                } elseif ( '' == $old_wp_version && preg_match( '/^\# WordPress Version: ([0-9]+(\.[0-9]+)+)(-[-a-z0-9]+,)?(.*)$/', $buffer, $matches ) ) {
                    $old_wp_version = $matches[1];
                    if ( ! empty( $matches[3] ) ) {
                        $old_wp_version .= substr( $matches[3], 0, strlen( $matches[3] ) - 1 );
                    }
                    if ( version_compare( $old_wp_version, $wp_version, '>' ) ) {
                        $warn[] = sprintf( esc_html__( 'You are importing from a newer version of WordPress (%1$s) into an older one (%2$s). There are no guarantees that WordPress can handle this.', 'updraftplus' ), $old_wp_version, $wp_version );
                    }
                    if ( preg_match( '/running on PHP ([0-9]+\.[0-9]+)(\s|\.)/', $matches[4], $nmatches ) && preg_match( '/^([0-9]+\.[0-9]+)(\s|\.)/', PHP_VERSION, $cmatches ) ) {
                        $old_php_version     = $nmatches[1];
                        $current_php_version = $cmatches[1];
                        if ( version_compare( $old_php_version, $current_php_version, '>' ) ) {
                            $warn[] = sprintf( esc_html__( 'The site in this backup was running on a webserver with version %1$s of %2$s. ', 'updraftplus' ), $old_php_version, 'PHP' ) . ' ' . sprintf( esc_html__( 'This is significantly newer than the server which you are now restoring onto (version %s).', 'updraftplus' ), PHP_VERSION ) . ' ' . sprintf( esc_html__( 'You should only proceed if you cannot update the current server and are confident (or willing to risk) that your plugins/themes/etc. are compatible with the older %s version.', 'updraftplus' ), 'PHP' ) . ' ' . sprintf( esc_html__( 'Any support requests to do with %s should be raised with your web hosting company.', 'updraftplus' ), 'PHP' );
                        }
                    }
                } elseif ( '' == $old_table_prefix && ( preg_match( '/^\# Table prefix: (\S+)$/', $buffer, $matches ) || preg_match( '/^-- Table prefix: (\S+)$/i', $buffer, $matches ) ) ) {
                    $old_table_prefix = $matches[1];
                } elseif ( empty( $info['label'] ) && preg_match( '/^\# Label: (.*)$/', $buffer, $matches ) ) {
                    $info['label'] = $matches[1];
                    $mess[]        = esc_html__( 'Backup label:', 'updraftplus' ) . ' ' . htmlspecialchars( $info['label'] );
                } elseif ( $gathering_siteinfo && preg_match( '/^\# Site info: (\S+)$/', $buffer, $matches ) ) {
                    if ( 'end' == $matches[1] ) {
                        $gathering_siteinfo = false;
                        // Sanity checks.
                        if ( isset( $old_siteinfo['multisite'] ) && ! $old_siteinfo['multisite'] && is_multisite() ) {
                            $warn[] = esc_html__( 'You are running on WordPress multisite - but your backup is not of a multisite site.', 'updraftplus' ) . ' ' . esc_html__( 'It will be imported as a new site.', 'updraftplus' ) . ' <a href="https://updraftplus.com/information-on-importing-a-single-site-wordpress-backup-into-a-wordpress-network-i-e-multisite/">' . esc_html__( 'Please read this link for important information on this process.', 'updraftplus' ) . '</a>';
                            if ( ! class_exists( '\UpdraftPlusAddOn_MultiSite' ) || ! class_exists( '\UpdraftPlus_Addons_Migrator' ) ) {
                                $err[] = sprintf( esc_html__( 'Error: %s', 'updraftplus' ), sprintf( esc_html__( 'To import an ordinary WordPress site into a multisite installation requires %s.', 'updraftplus' ), 'UpdraftPlus Premium' ) );
                                return array( $mess, $warn, $err, $info );
                            }
                        } elseif ( isset( $old_siteinfo['multisite'] ) && $old_siteinfo['multisite'] && ! is_multisite() ) {
                            $warn[] = esc_html__( 'Warning:', 'updraftplus' ) . ' ' . esc_html__( 'Your backup is of a WordPress multisite install; but this site is not. Only the first site of the network will be accessible.', 'updraftplus' ) . ' <a href="https://codex.wordpress.org/Create_A_Network">' . esc_html__( 'If you want to restore a multisite backup, you should first set up your WordPress installation as a multisite.', 'updraftplus' ) . '</a>';
                        }
                    } elseif ( preg_match( '/^([^=]+)=(.*)$/', $matches[1], $kvmatches ) ) {
                        $key = $kvmatches[1];
                        $val = $kvmatches[2];
                        if ( 'multisite' == $key ) {
                            $info['multisite'] = $val ? true : false;
                            if ( $val ) {
                                $mess[] = '<strong>' . esc_html__( 'Site information:', 'updraftplus' ) . '</strong> backup is of a WordPress Network';
                            }
                        }
                        $old_siteinfo[ $key ] = $val;
                    }
                } elseif ( preg_match( '/^\# Skipped tables: (.*)$/', $buffer, $matches ) ) {
                    $skipped_tables = explode( ',', $matches[1] );
                }
            } elseif ( preg_match( '/^\s*create table \`?([^\`\(]*)\`?\s*\(/i', $buffer, $matches ) ) {
                $table          = $matches[1];
                $tables_found[] = $table;
                if ( $old_table_prefix ) {
                    // Remove prefix.
                    $table = \UpdraftPlus_Manipulation_Functions::str_replace_once( $old_table_prefix, '', $table );
                    if ( in_array( $table, $wanted_tables ) ) {
                        $wanted_tables = array_diff( $wanted_tables, array( $table ) );
                    }
                }
                if ( ';' != substr( $buffer, -1, 1 ) ) {
                    $processing_create                                     = true;
                    $db_supported_charset_related_to_unsupported_collation = true;
                }
            } elseif ( $processing_create ) {
                if ( ! empty( $db_supported_collations ) ) {
                    if ( preg_match( '/ COLLATE=([^\s;]+)/i', $buffer, $collate_match ) ) {
                        $db_collates_found[] = $collate_match[1];
                        if ( ! isset( $db_supported_collations[ $collate_match[1] ] ) ) {
                            $db_supported_charset_related_to_unsupported_collation = true;
                        }
                    }
                    if ( preg_match( '/ COLLATE ([a-zA-Z0-9._-]+),/i', $buffer, $collate_match ) ) {
                        $db_collates_found[] = $collate_match[1];
                        if ( ! isset( $db_supported_collations[ $collate_match[1] ] ) ) {
                            $db_supported_charset_related_to_unsupported_collation = true;
                        }
                    }
                    if ( preg_match( '/ COLLATE ([a-zA-Z0-9._-]+) /i', $buffer, $collate_match ) ) {
                        $db_collates_found[] = $collate_match[1];
                        if ( ! isset( $db_supported_collations[ $collate_match[1] ] ) ) {
                            $db_supported_charset_related_to_unsupported_collation = true;
                        }
                    }
                }
                if ( ! empty( $db_supported_character_sets ) ) {
                    if ( preg_match( '/ CHARSET=([^\s;]+)/i', $buffer, $charset_match ) ) {
                        $db_charsets_found[] = $charset_match[1];
                        if ( $db_supported_charset_related_to_unsupported_collation && ! in_array( $charset_match[1], $db_supported_charsets_related_to_unsupported_collations ) ) {
                            $db_supported_charsets_related_to_unsupported_collations[] = $charset_match[1];
                        }
                    }
                }
                if ( ';' == substr( $buffer, -1, 1 ) ) {
                    $processing_create                                     = false;
                    $db_supported_charset_related_to_unsupported_collation = false;
                }
                static $mysql_version_warned = false;
                if ( ! $mysql_version_warned && version_compare( $db_version, '5.2.0', '<' ) && preg_match( '/(CHARSET|COLLATE)[= ]utf8mb4/', $buffer ) ) {
                    $mysql_version_warned = true;
                    $err[]                = sprintf( esc_html__( 'Error: %s', 'updraftplus' ), sprintf( esc_html__( 'The database backup uses MySQL features not available in the old MySQL version (%s) that this site is running on.', 'updraftplus' ), $db_version ) . ' ' . esc_html__( 'You must upgrade MySQL to be able to use this database.', 'updraftplus' ) );
                }
            }
        }
        if ( $is_plain ) {
            fclose( $dbhandle );
        } else {
            gzclose( $dbhandle );
        }
        if ( ! empty( $db_supported_character_sets ) ) {
            $db_charsets_found_unique = array_unique( $db_charsets_found );
            $db_unsupported_charset   = array();
            $db_charset_forbidden     = false;
            foreach ( $db_charsets_found_unique as $db_charset ) {
                if ( ! isset( $db_supported_character_sets[ $db_charset ] ) ) {
                    $db_unsupported_charset[] = $db_charset;
                    $db_charset_forbidden     = true;
                }
            }
            if ( $db_charset_forbidden ) {
                $db_unsupported_charset_unique = array_unique( $db_unsupported_charset );
                $warn[]                        = sprintf( _n( "The database server that this WordPress site is running on doesn't support the character set (%s) which you are trying to import.", "The database server that this WordPress site is running on doesn't support the character sets (%s) which you are trying to import.", count( $db_unsupported_charset_unique ), 'updraftplus' ), implode( ', ', $db_unsupported_charset_unique ) ) . ' ' . esc_html__( 'You can choose another suitable character set instead and continue with the restoration at your own risk.', 'updraftplus' ) . ' <a target="_blank" href="https://updraftplus.com/faqs/implications-changing-tables-character-set/">' . esc_html__( 'Go here for more information.', 'updraftplus' ) . '</a> <a target="_blank" href="https://updraftplus.com/faqs/implications-changing-tables-character-set/">' . esc_html__( 'Go here for more information.', 'updraftplus' ) . '</a>';
                $db_supported_character_sets   = array_keys( $db_supported_character_sets );
                $similar_type_charset          = \UpdraftPlus_Manipulation_Functions::get_matching_str_from_array_elems( $db_unsupported_charset_unique, $db_supported_character_sets, true );
                if ( empty( $similar_type_charset ) ) {
                    $row                  = $GLOBALS['wpdb']->get_row( 'show variables like "character_set_database"' );
                    $similar_type_charset = ( null !== $row ) ? $row->Value : '';
                }
                if ( empty( $similar_type_charset ) && ! empty( $db_supported_character_sets[0] ) ) {
                    $similar_type_charset = $db_supported_character_sets[0];
                }
                $charset_select_html  = '<label>' . esc_html__( 'Your chosen character set to use instead:', 'updraftplus' ) . '</label> ';
                $charset_select_html .= '<select name="updraft_restorer_charset" id="updraft_restorer_charset">';
                if ( is_array( $db_supported_character_sets ) ) {
                    foreach ( $db_supported_character_sets as $character_set ) {
                        $charset_select_html .= '<option value="' . esc_attr( $character_set ) . '" ' . selected( $character_set, $similar_type_charset, false ) . '>' . esc_html( $character_set ) . '</option>';
                    }
                }
                $charset_select_html .= '</select>';
                if ( empty( $info['addui'] ) ) {
                    $info['addui'] = '';
                }
                $info['addui'] .= $charset_select_html;
            }
        }
        if ( ! empty( $db_supported_collations ) ) {
            $db_collates_found_unique = array_unique( $db_collates_found );
            $db_unsupported_collate   = array();
            $db_collate_forbidden     = false;
            foreach ( $db_collates_found_unique as $db_collate ) {
                if ( ! isset( $db_supported_collations[ $db_collate ] ) ) {
                    $db_unsupported_collate[] = $db_collate;
                    $db_collate_forbidden     = true;
                }
            }
            if ( $db_collate_forbidden ) {
                $db_unsupported_collate_unique = array_unique( $db_unsupported_collate );
                $warn[]                        = sprintf( _n( "The database server that this WordPress site is running on doesn't support the collation (%s) used in the database which you are trying to import.", "The database server that this WordPress site is running on doesn't support multiple collations (%s) used in the database which you are trying to import.", count( $db_unsupported_collate_unique ), 'updraftplus' ), implode( ', ', $db_unsupported_collate_unique ) ) . ' ' . esc_html__( 'You can choose another suitable collation instead and continue with the restoration (at your own risk).', 'updraftplus' );
                $similar_type_collate          = '';
                if ( $db_charset_forbidden && ! empty( $similar_type_charset ) ) {
                    $similar_type_collate = $updraftplus->get_similar_collate_related_to_charset( $db_supported_collations, $db_unsupported_collate_unique, $similar_type_charset );
                }
                if ( empty( $similar_type_collate ) && ! empty( $db_supported_charsets_related_to_unsupported_collations ) ) {
                    $db_supported_collations_related_to_charset = array();
                    foreach ( $db_supported_collations as $db_supported_collation => $db_supported_collations_info_obj ) {
                        if ( isset( $db_supported_collations_info_obj->Charset ) && in_array( $db_supported_collations_info_obj->Charset, $db_supported_charsets_related_to_unsupported_collations ) ) {
                            $db_supported_collations_related_to_charset[] = $db_supported_collation;
                        }
                    }
                    if ( ! empty( $db_supported_collations_related_to_charset ) ) {
                        $similar_type_collate = \UpdraftPlus_Manipulation_Functions::get_matching_str_from_array_elems( $db_unsupported_collate_unique, $db_supported_collations_related_to_charset, false );
                    }
                }
                if ( empty( $similar_type_collate ) ) {
                    $similar_type_collate = $updraftplus->get_similar_collate_based_on_ocuurence_count( $db_collates_found, $db_supported_collations, $db_supported_charsets_related_to_unsupported_collations );
                }
                if ( empty( $similar_type_collate ) ) {
                    $similar_type_collate = \UpdraftPlus_Manipulation_Functions::get_matching_str_from_array_elems( $db_unsupported_collate_unique, array_keys( $db_supported_collations ), false );
                }

                $collate_select_html  = '<label>' . esc_html__( 'Your chosen replacement collation', 'updraftplus' ) . ':</label>';
                $collate_select_html .= '<select name="updraft_restorer_collate" id="updraft_restorer_collate">';
                foreach ( $db_supported_collations as $collate => $collate_info_obj ) {
                    $option_other_attr = array();
                    if ( $db_charset_forbidden && isset( $collate_info_obj->Charset ) ) {
                        $option_other_attr[] = 'data-charset=' . esc_attr( $collate_info_obj->Charset );
                        if ( $similar_type_charset != $collate_info_obj->Charset ) {
                            $option_other_attr[] = 'style="display:none;"';
                        }
                    }
                    $collate_select_html .= '<option value="' . esc_attr( $collate ) . '" ' . selected( $collate, $similar_type_collate, $echo = false ) . ' ' . implode( ' ', $option_other_attr ) . '>' . esc_html( $collate ) . '</option>';
                }
                $collate_select_html .= '</select>';

                $info['addui'] = empty( $info['addui'] ) ? $collate_select_html : $info['addui'] . '<br>' . $collate_select_html;

                if ( $db_charset_forbidden ) {
                    $collate_change_on_charset_selection_data = array(
                        'db_supported_collations'       => $db_supported_collations,
                        'db_unsupported_collate_unique' => $db_unsupported_collate_unique,
                        'db_collates_found'             => $db_collates_found,
                    );
                    $info['addui'] .= '<input type="hidden" name="collate_change_on_charset_selection_data" id="collate_change_on_charset_selection_data" value="' . esc_attr( wp_json_encode( $collate_change_on_charset_selection_data ) ) . '">';
                }
            }
        }

        if ( ! isset( $skipped_tables ) ) {
            $skipped_tables = array();
        }
        $missing_tables = array();
        if ( $old_table_prefix ) {
            if ( ! $header_only ) {
                foreach ( $wanted_tables as $table ) {
                    if ( ! in_array( $old_table_prefix . $table, $tables_found ) ) {
                        $missing_tables[] = $table;
                    }
                }

                foreach ( $missing_tables as $key => $value ) {
                    if ( in_array( $old_table_prefix . $value, $skipped_tables ) ) {
                        unset( $missing_tables[ $key ] );
                    }
                }

                if ( count( $missing_tables ) > 0 ) {
                    $warn[] = sprintf( esc_html__( 'This database backup is missing core WordPress tables: %s', 'updraftplus' ), implode( ', ', $missing_tables ) );
                }
                if ( count( $skipped_tables ) > 0 ) {
                    $warn[] = sprintf( esc_html__( 'This database backup has the following WordPress tables excluded: %s', 'updraftplus' ), implode( ', ', $skipped_tables ) );
                }
            }
        } else {
            if ( empty( $backup['meta_foreign'] ) ) {
                $warn[] = esc_html__( 'UpdraftPlus was unable to find the table prefix when scanning the database backup.', 'updraftplus' );
            }
        }

        return array( $mess, $warn, $err, $info );
    }

    /**
     * Gzip open.
     *
     * @param string $file File to open.
     * @param string $warn Warning message.
     * @param string $err  Error message.
     *
     * @return bool|false|resource Return FALSE on failure or $what_to_return the unziped file.
     */
    private function gzopen_for_read( $file, &$warn, &$err ) {
        if ( ! function_exists( 'gzopen' ) || ! function_exists( 'gzread' ) ) {
            $missing = '';
            if ( ! function_exists( 'gzopen' ) ) {
                $missing .= 'gzopen';
            }
            if ( ! function_exists( 'gzread' ) ) {
                $missing .= ( $missing ) ? ', gzread' : 'gzread';
            }
            $err[] = sprintf( esc_html__( "Your web server's PHP installation has these functions disabled: %s.", 'updraftplus' ), $missing ) . ' ' . sprintf( esc_html__( 'Your hosting company must enable these functions before %s can work.', 'updraftplus' ), esc_html__( 'restoration', 'updraftplus' ) );

            return false;
        }

        $dbhandle = gzopen( $file, 'r' );

        if ( false === $dbhandle ) {
            return false;
        }

        if ( ! function_exists( 'gzseek' ) ) {
            return $dbhandle;
        }

        $bytes = gzread( $dbhandle, 3 );

        if ( false === $bytes ) {
            return false;
        }

        // Check if double-gzipped.
        if ( 'H4sI' !== base64_encode( $bytes ) ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
            if ( 0 === gzseek( $dbhandle, 0 ) ) {
                return $dbhandle;
            } else {
                gzclose( $dbhandle );

                return gzopen( $file, 'r' );
            }
        }

        // If double-gzipped.
        $what_to_return = false;
        $mess           = esc_html__( 'The database file appears to have been compressed twice - probably the website you downloaded it from had a mis-configured webserver.', 'updraftplus' );
        $messkey        = 'doublecompress';
        $err_msg        = '';
        $fnew           = fopen( $file . '.tmp', 'w' );
        if ( false === ( $fnew ) || ! is_resource( $fnew ) ) {

            gzclose( $dbhandle );
            $err_msg = esc_html__( 'The attempt to undo the double-compression failed.', 'updraftplus' );

        } else {

            fwrite( $fnew, $bytes );
            $emptimes = 0;
            while ( ! gzeof( $dbhandle ) ) {
                $bytes = gzread( $dbhandle, 131072 );
                if ( empty( $bytes ) ) {
                    global $updraftplus;
                    $emptimes ++;
                    $updraftplus->log( "Got empty gzread ( $emptimes times )" );
                    if ( $emptimes > 2 ) {
                        break;
                    }
                } else {
                    fwrite( $fnew, $bytes );
                }
            }

            gzclose( $dbhandle );
            fclose( $fnew );
            // On some systems (all Windows?) you can't rename a gz file whilst it's gzopened.
            if ( ! rename( $file . '.tmp', $file ) ) {
                $err_msg = esc_html__( 'The attempt to undo the double-compression failed.', 'updraftplus' );
            } else {
                $mess          .= ' ' . esc_html__( 'The attempt to undo the double-compression succeeded.', 'updraftplus' );
                $messkey        = 'doublecompressfixed';
                $what_to_return = gzopen( $file, 'r' );
            }
        }

        $warn[ $messkey ] = $mess;
        if ( ! empty( $err_msg ) ) {
            $err[] = $err_msg;
        }

        return $what_to_return;
    }

    /**
     * Build existing backups table.
     *
     * @param bool $backup_history Whether or not there is existing backups. Default: false.
     *
     * @return string Return $ret backups table html.
     * @throws Exception Error message.
     *
     * @uses UpdraftPlus_Backup_History::get_history()
     * @uses UpdraftPlus_Encryption::is_file_encrypted()
     * @uses $updraftplus::backups_dir_location()
     * @uses $updraftplus::get_backupable_file_entities()
     * @uses $updraftplus::jobdata_getarray()
     * @uses $updraftplus::is_db_encrypted()
     * @uses \MainWP\Child\MainWP_Child_Updraft_Plus_Backups::::delete_button()
     * @uses \MainWP\Child\MainWP_Child_Updraft_Plus_Backups::date_label()
     * @uses \MainWP\Child\MainWP_Child_Updraft_Plus_Backups::download_db_button()
     * @uses \MainWP\Child\MainWP_Child_Updraft_Plus_Backups::download_buttons()
     * @uses \MainWP\Child\MainWP_Child_Updraft_Plus_Backups::restore_button()
     * @uses \MainWP\Child\MainWP_Child_Updraft_Plus_Backups::log_button()
     * @uses \MainWP\Child\MainWP_Helper::instance()->check_methods()
     */
    private function existing_backup_table( $backup_history = false ) { // phpcs:ignore -- third party credit.

        /** @global object $updraftplus UpdraftPlus object. */
        global $updraftplus;

        if ( false === $backup_history ) {
            $backup_history = \UpdraftPlus_Backup_History::get_history();
        }

        if ( empty( $backup_history ) ) {
            return '<div class="ui yellow message">' . esc_html__( 'You have not yet made any backups.', 'updraftplus' ) . '</div>';
        }

        MainWP_Helper::instance()->check_methods( $updraftplus, array( 'backups_dir_location', 'get_backupable_file_entities' ) );

        $updraft_dir         = $updraftplus->backups_dir_location();
        $backupable_entities = $updraftplus->get_backupable_file_entities( true, true );

        $accept = apply_filters( 'updraftplus_accept_archivename', array() );
        if ( ! is_array( $accept ) ) {
            $accept = array();
        }

        $ret         = '<table class="ui stackable single line table">';
        $nonce_field = wp_nonce_field( 'updraftplus_download', '_wpnonce', true, false );

        $ret .= '<thead>
							<tr>
								<th>' . esc_html__( 'Backup date', 'updraftplus' ) . '</th>
								<th>' . esc_html__( 'Backup data (click to download)', 'updraftplus' ) . '</th>
								<th>' . esc_html__( 'Actions', 'updraftplus' ) . '</th>
							</tr>
						</thead>
						<tbody>';

        krsort( $backup_history );
        foreach ( $backup_history as $key => $backup ) {

            $pretty_date = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) $key ), 'M d, Y G:i' );

            $esc_pretty_date = esc_attr( $pretty_date );
            $entities        = '';

            $non = $backup['nonce'];

            $rawbackup = '';

            $jobdata = $updraftplus->jobdata_getarray( $non );

            $delete_button = $this->delete_button( $key, $non, $backup );

            $date_label = $this->date_label( $pretty_date, $key, $backup, $jobdata, $non );

            $service_title = '';
            if ( ! isset( $backup['service'] ) ) {
                $backup['service'] = array();
            }
            if ( ! is_array( $backup['service'] ) ) {
                $backup['service'] = array( $backup['service'] );
            }
            foreach ( $backup['service'] as $service ) {
                $emptyCheck = ( 'none' === $service || '' === $service || ( is_array( $service ) && ( empty( $service ) || array( 'none' ) === $service || array( '' ) === $service ) ) );
                if ( ! empty( $emptyCheck ) ) {
                    $remote_storage = ( 'remotesend' === $service ) ? esc_html__( 'remote site', 'updraftplus' ) : $updraftplus->backup_methods[ $service ];
                    $service_title  = '<br>' . esc_attr( sprintf( esc_html__( 'Remote storage: %s', 'updraftplus' ), $remote_storage ) );
                }
            }

            $ret .= <<<ENDHERE
		        <tr id="updraft_existing_backups_row_$key">
			    <td class="updraft_existingbackup_date" data-rawbackup="$rawbackup">
				    $date_label . $service_title
			    </td>
ENDHERE;

            $ret .= '<td>';
            if ( empty( $backup['meta_foreign'] ) || ! empty( $accept[ $backup['meta_foreign'] ]['separatedb'] ) ) {

                if ( isset( $backup['db'] ) ) {
                    $entities .= '/db=0/';

                    // Set a flag according to whether or not $backup['db'] ends in .crypt, then pick this up in the display of the decrypt field.
                    $db = is_array( $backup['db'] ) ? $backup['db'][0] : $backup['db'];
                    if ( class_exists( '\UpdraftPlus_Encryption' ) ) {
                        if ( method_exists( 'UpdraftPlus_Encryption', 'is_file_encrypted' ) ) {
                            if ( \UpdraftPlus_Encryption::is_file_encrypted( $db ) ) {
                                $entities .= '/dbcrypted=1/';
                            }
                        }
                    } elseif ( method_exists( $updraftplus, 'is_db_encrypted' ) && $updraftplus->is_db_encrypted( $db ) ) {
                        $entities .= '/dbcrypted=1/';
                    }

                    $ret .= $this->download_db_button( 'db', $key, $esc_pretty_date, $nonce_field, $backup, $accept );
                }

                // External databases.
                foreach ( $backup as $bkey => $binfo ) {
                    if ( 'db' === $bkey || 'db' !== substr( $bkey, 0, 2 ) || '-size' === substr( $bkey, - 5, 5 ) ) {
                        continue;
                    }
                    $ret .= $this->download_db_button( $bkey, $key, $esc_pretty_date, $nonce_field, $backup );
                }
            } else {
                // Foreign without separate db.
                $entities = '/db=0/meta_foreign=1/';
            }

            if ( ! empty( $backup['meta_foreign'] ) && ! empty( $accept[ $backup['meta_foreign'] ] ) && ! empty( $accept[ $backup['meta_foreign'] ]['separatedb'] ) ) {
                $entities .= '/meta_foreign=2/';
            }

            $download_buttons = $this->download_buttons( $backup, $key, $accept, $entities, $esc_pretty_date, $nonce_field );

            $ret .= $download_buttons;
            $ret .= '</td>';
            $ret .= '<td>';
            $ret .= $this->restore_button( $backup, $key, $pretty_date, $entities );
            $ret .= $delete_button;
            if ( empty( $backup['meta_foreign'] ) ) {
                $ret .= $this->log_button( $backup );
            }
            $ret .= '</td>';
            $ret .= '</tr>';
        }

        $ret .= '</tbody></table>';

        return $ret;
    }

    /**
     * Restore button.
     *
     * @param string $backup Type of backup.
     * @param string $key Timestamp.
     * @param string $pretty_date Backup date.
     * @param string $entities Backup entities.
     * @return string $ret Restore button html.
     */
    private function restore_button( $backup, $key, $pretty_date, $entities ) {
        $ret = <<<ENDHERE
		<div style="float:left; clear:none; margin-right: 6px;">
			<form method="post" action="">
				<input type="hidden" name="backup_timestamp" value="$key">
				<input type="hidden" name="action" value="mainwp_updraft_restore" />
ENDHERE;
        if ( $entities ) {
            $show_data = $pretty_date;
            if ( isset( $backup['native'] ) && false === $backup['native'] ) {
                $show_data .= ' ' . esc_html__( '(backup set imported from remote storage)', 'updraftplus' );
            }
            $ret .= '<button title="' . esc_html__( 'Go to Restore', 'updraftplus' ) . '" type="button" class="ui green button mwp-updraftplus-restore-btn" >' . esc_html__( 'Restore', 'updraftplus' ) . '</button>';
        }
        $ret .= "</form></div>\n";

        return $ret;
    }

    /**
     * Delete button.
     *
     * @param string $key    Timestamp.
     * @param string $nonce  Security nonce.
     * @param string $backup Backup to delete.
     *
     * @return string Delete button html.
     */
    private function delete_button( $key, $nonce, $backup ) {
        $sval = ( ( isset( $backup['service'] ) && 'email' !== $backup['service'] && 'none' !== $backup['service'] ) ) ? '1' : '0';

        return '<a style="float:left;margin-right:6px"  class="ui green basic button" href="#" onclick="event.preventDefault();' . "mainwp_updraft_delete( '$key', '$nonce', $sval, this );" . '" title="' . esc_attr( esc_html__( 'Delete this backup set', 'updraftplus' ) ) . '">' . esc_html__( 'Delete', 'updraftplus' ) . '</a>';
    }

    /**
     * Date label.
     *
     * @param string $pretty_date Pretty date.
     * @param string $key         Timestamp.
     * @param string $backup      Type of backup.
     * @param array  $jobdata     Job data.
     * @param string $nonce       Security nonce.
     *
     * @return string $ret Return date label html.
     */
    private function date_label($pretty_date, $key, $backup, $jobdata, $nonce ) {
        $ret = apply_filters( 'updraftplus_showbackup_date', $pretty_date, $backup, $jobdata, (int) $key, false );
        if ( is_array( $jobdata ) && ! empty( $jobdata['resume_interval'] ) && ( empty( $jobdata['jobstatus'] ) || 'finished' !== $jobdata['jobstatus'] ) ) {
            $ret .= apply_filters( 'updraftplus_msg_unfinishedbackup', '<br><span title="' . esc_attr( esc_html__( 'If you are seeing more backups than you expect, then it is probably because the deletion of old backup sets does not happen until a fresh backup completes.', 'updraftplus' ) ) . '">' . esc_html__( '(Not finished)', 'updraftplus' ) . '</span>', $jobdata, $nonce );
        }

        return $ret;
    }

    /**
     * Download database button.
     *
     * @param string $bkey            Backup key.
     * @param string $key             Timestamp.
     * @param string $esc_pretty_date Escaped pretty date.
     * @param string $nonce_field     Nonce filed.
     * @param array  $backup          Backup data
     * @param array  $accept          Destination array.
     *
     * @return string $ret Download DB button html.
     */
    private function download_db_button( $bkey, $key, $esc_pretty_date, $nonce_field, $backup, $accept = array() ) {

        if ( ! empty( $backup['meta_foreign'] ) && isset( $accept[ $backup['meta_foreign'] ] ) ) {
            $desc_source = $accept[ $backup['meta_foreign'] ]['desc'];
        } else {
            $desc_source = esc_html__( 'unknown source', 'updraftplus' );
        }

        $ret = '';

        if ( 'db' === $bkey ) {
            $dbt = empty( $backup['meta_foreign'] ) ? esc_attr( esc_html__( 'Database', 'updraftplus' ) ) : esc_attr( sprintf( esc_html__( 'Database (created by %s)', 'updraftplus' ), $desc_source ) );
        } else {
            $dbt = esc_html__( 'External database', 'updraftplus' ) . ' (' . substr( $bkey, 2 ) . ')';
        }

        $ret .= <<<ENDHERE
							<div style="float:left; clear: none;">
								<form id="uddownloadform_${bkey}_${key}_0" action="admin-ajax.php" onsubmit="return mainwp_updraft_downloader( 'uddlstatus_', $key, '$bkey', '#mwp_ud_downloadstatus', '0', '$esc_pretty_date', true, this )" method="post">
									$nonce_field
									<input type="hidden" name="action" value="mainwp_updraft_download_backup" />
									<input type="hidden" name="type" value="$bkey" />
									<input type="hidden" name="timestamp" value="$key" />
									<input type="submit" class="mwp-updraft-backupentitybutton ui button" value="$dbt" />
								</form>
							</div>
ENDHERE;

        return $ret;
    }

    /**
     * Download buttons.
     *
     * @param array  $backup           Backup data array.
     * @param string $key              Backup key.
     * @param array  $accept           Destination array.
     * @param array  $entities         File entities.
     * @param string $esc_pretty_date  Escaped pretty date.
     * @param string $nonce_field      Nonce field.
     *
     * @return string $ret Download button html.
     *
     * @uses $updraftplus::get_backupable_file_entities()
     * @uses MainWP_Child_Updraft_Plus_Backups::download_button()
     */
    private function download_buttons( $backup, $key, $accept, &$entities, $esc_pretty_date, $nonce_field ) { // phpcs:ignore -- third party credit.

        /** @global object $updraftplus UpdraftPlus object. */
        global $updraftplus;
        $ret = '';

        // Go through each of the file entities.
        $backupable_entities = $updraftplus->get_backupable_file_entities( true, true );

        $first_entity = true;

        foreach ( $backupable_entities as $type => $info ) {
            if ( ! empty( $backup['meta_foreign'] ) && 'wpcore' !== $type ) {
                continue;
            }

            $ide = '';
            if ( 'wpcore' === $type ) {
                $wpcore_restore_descrip = $info['description'];
            }
            if ( empty( $backup['meta_foreign'] ) ) {
                $sdescrip = preg_replace( '/ \(.*\)$/', '', $info['description'] );
                if ( strlen( $sdescrip ) > 20 && isset( $info['shortdescription'] ) ) {
                    $sdescrip = $info['shortdescription'];
                }
            } else {
                $info['description'] = 'WordPress';

                if ( isset( $accept[ $backup['meta_foreign'] ] ) ) {
                    $desc_source = $accept[ $backup['meta_foreign'] ]['desc'];
                    $ide        .= sprintf( esc_html__( 'Backup created by: %s.', 'updraftplus' ), $accept[ $backup['meta_foreign'] ]['desc'] ) . ' ';
                } else {
                    $desc_source = esc_html__( 'unknown source', 'updraftplus' );
                    $ide        .= esc_html__( 'Backup created by unknown source (%s) - cannot be restored.', 'updraftplus' ) . ' ';
                }

                $sdescrip = ( empty( $accept[ $backup['meta_foreign'] ]['separatedb'] ) ) ? sprintf( esc_html__( 'Files and database WordPress backup (created by %s)', 'updraftplus' ), $desc_source ) : sprintf( esc_html__( 'Files backup (created by %s)', 'updraftplus' ), $desc_source );
                if ( 'wpcore' === $type ) {
                    $wpcore_restore_descrip = $sdescrip;
                }
            }

            $fix_perfomance = 0;

            if ( isset( $backup[ $type ] ) ) {
                if ( ! is_array( $backup[ $type ] ) ) {
                    $backup[ $type ] = array( $backup[ $type ] );
                }
                $howmanyinset   = count( $backup[ $type ] );
                $expected_index = 0;
                $index_missing  = false;
                $set_contents   = '';
                $entities      .= "/$type=";
                $whatfiles      = $backup[ $type ];
                ksort( $whatfiles );
                foreach ( $whatfiles as $findex => $bfile ) {
                    $set_contents .= ( '' === $set_contents ) ? $findex : ",$findex";
                    if ( $findex !== $expected_index ) {
                        $index_missing = true;
                    }
                    $expected_index ++;
                }
                $entities .= $set_contents . '/';
                if ( ! empty( $backup['meta_foreign'] ) ) {
                    $entities .= '/plugins=0//themes=0//uploads=0//others=0/';
                }
                $first_printed = true;
                foreach ( $whatfiles as $findex => $bfile ) {
                    $ide     .= esc_html__( 'Press here to download', 'updraftplus' ) . ' ' . strtolower( $info['description'] );
                    $pdescrip = ( $findex > 0 ) ? $sdescrip . ' (' . ( $findex + 1 ) . ')' : $sdescrip;
                    if ( ! $first_printed ) {
                        $ret .= '<div style="display:none;">';
                    }
                    if ( count( $backup[ $type ] ) > 0 ) {
                        $ide .= ' ' . sprintf( esc_html__( '(%d archive(s) in set).', 'updraftplus' ), $howmanyinset );
                    }
                    if ( $index_missing ) {
                        $ide .= ' ' . esc_html__( 'You appear to be missing one or more archives from this multi-archive set.', 'updraftplus' );
                    }

                    if ( $first_entity ) {
                        $first_entity = false;
                    }

                    $ret .= $this->download_button( $type, $key, $findex, $info, $nonce_field, $ide, $pdescrip, $esc_pretty_date, $set_contents );

                    if ( ! $first_printed ) {
                        $ret .= '</div>';
                    } else {
                        $first_printed = false;
                    }

                    $fix_perfomance++;
                    if ( $fix_perfomance > 50 ) { // to fix perfomance issue of response when too much backup files!
                        break;
                    }
                }
            }
        }

        return $ret;
    }


    /**
     * Single download button.
     *
     * @param string $type            Backup type.
     * @param string $key             Backup key.
     * @param string $findex          File index.
     * @param string $info            Backup info.
     * @param string $nonce_field     Security nonce field.
     * @param string $ide             Submit title.
     * @param string $pdescrip        Description.
     * @param string $esc_pretty_date Escaped pretty date.
     * @param string $set_contents    mainwp_updraft_downloader value.
     *
     * @return string Download button html.
     */
    private function download_button($type, $key, $findex, $info, $nonce_field, $ide, $pdescrip, $esc_pretty_date, $set_contents ) {
        $ret = <<<ENDHERE
							<div style="float: left; clear: none;">
								<form id="uddownloadform_${type}_${key}_${findex}" action="admin-ajax.php" onsubmit="return mainwp_updraft_downloader( 'uddlstatus_', '$key', '$type', '#mwp_ud_downloadstatus', '$set_contents', '$esc_pretty_date', true, this )" method="post">
									$nonce_field
									<input type="hidden" name="action" value="mainwp_updraft_download_backup" />
									<input type="hidden" name="type" value="$type" />
									<input type="hidden" name="timestamp" value="$key" />
									<input type="hidden" name="findex" value="$findex" />
									<input type="submit" class="mwp-updraft-backupentitybutton ui button" title="$ide" value="$pdescrip" />
								</form>
							</div>
ENDHERE;

        return $ret;
    }

    /**
     * Log button.
     *
     * @param array $backup Backup data array.
     *
     * @return string $ret Log button html.
     *
     * @uses $updraftplus::backups_dir_location()
     * @uses UpdraftPlus_Options::admin_page()
     */
    private function log_button( $backup ) {

        /** @global object $updraftplus UpdraftPlus object. */
        global $updraftplus;

        $updraft_dir = $updraftplus->backups_dir_location();
        $ret         = '';
        if ( isset( $backup['nonce'] ) && preg_match( '/^[0-9a-f]{12}$/', $backup['nonce'] ) && is_readable( $updraft_dir . '/log.' . $backup['nonce'] . '.txt' ) ) {
            $nval = $backup['nonce'];
            $lt   = esc_attr( esc_html__( 'View Log', 'updraftplus' ) );
            $url  = \UpdraftPlus_Options::admin_page();
            $ret .= <<<ENDHERE
							<div style="float:left; clear:none;" class="mwp-updraft-viewlogdiv">
								<form action="$url" method="get">
									<input type="hidden" name="action" value="downloadlog" />
									<input type="hidden" name="page" value="updraftplus" />
									<input type="hidden" name="updraftplus_backup_nonce" value="$nval" />
									<input type="submit" value="$lt" class="updraft-log-link ui button" onclick="event.preventDefault(); mainwp_updraft_popuplog( '$nval', this );" />
								</form>
							</div>
ENDHERE;

            return $ret;
        }
    }

    /**
     * Rebuild backup history.
     *
     * @param bool $remotescan TRUE|FALSE Remote scan.
     *
     * @return array|null $messages Return backup history or Null.
     *
     * @uses $updraftplus::rebuild_backup_history()
     * @uses $updraftplus_admin::rebuild_backup_history()
     */
    private function rebuild_backup_history( $remotescan = false ) {

        /**
         * @global object $updraftplus_admin UpdraftPlus Admin object.
         * @global object $updraftplus UpdraftPlus object.
         */
        global $updraftplus_admin, $updraftplus;
		if (empty($updraftplus_admin)) include_once(UPDRAFTPLUS_DIR.'/admin.php');

        $messages = null;

        if ( method_exists( $updraftplus, 'rebuild_backup_history' ) ) {
            $messages = $updraftplus->rebuild_backup_history( $remotescan );
        } elseif ( method_exists( $updraftplus_admin, 'rebuild_backup_history' ) ) {
            $messages = $updraftplus_admin->rebuild_backup_history( $remotescan );
        }

        return $messages;
    }

    /**
     * Force a scheduled resumption.
     *
     * @return array|bool[] Return FALSE & Empty array on failure.
     *
     * @uses MainWP_Child_Updraft_Plus_Backups::close_browser_connection()
     * @uses $updraftplus::jobdata_set_from_array()
     * @uses $updraftplus::backup_resume()
     */
    private function force_scheduled_resumption() {

        /** @global object $updraftplus UpdraftPlus object. */
        global $updraftplus;

        /**
         * Casting $resumption to int is absolutely necessary, as the WP cron system uses a hashed serialisation
         *  of the parameters for identifying jobs. Different type => different hash => does not match.
         */
        $resumption = isset( $_REQUEST['resumption'] ) ? (int) $_REQUEST['resumption'] : 0;
        $job_id     = isset( $_REQUEST['job_id'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['job_id'] ) ) : 0;
        $get_cron   = $this->get_cron( $job_id );
        if ( ! is_array( $get_cron ) ) {
            return array( 'r' => false );
        } else {
            $updraftplus->log( "Forcing resumption: job id=$job_id, resumption=$resumption" );
            $time = $get_cron[0];
            wp_clear_scheduled_hook( 'updraft_backup_resume', array( $resumption, $job_id ) );
            $this->close_browser_connection( array( 'r' => true ) );
            $updraftplus->jobdata_set_from_array( $get_cron[1] );
            $updraftplus->backup_resume( $resumption, $job_id );
        }

        return array();
    }

    /**
     * Check disk space used.
     *
     * @return array $out Return Disk Space Used or ERROR
     *
     * @uses MainWP_Child_Updraft_Plus_Backups::recursive_directory_size()
     * @uses $updraftplus::backups_dir_location()
     * @uses $updraftplus::get_backupable_file_entities()
     * @uses $updraftplus::get_exclude()
     */
    public function diskspaceused() {

        /** @global object $updraftplus UpdraftPlus object. */
        global $updraftplus;

        $out = array();
        if ( 'updraft' === $_POST['entity'] ) {
            $out['diskspaceused'] = $this->recursive_directory_size( $updraftplus->backups_dir_location() );
        } else {
            $backupable_entities = $updraftplus->get_backupable_file_entities( true, false );
            if ( ! empty( $backupable_entities[ $_POST['entity'] ] ) ) {
                $basedir              = $backupable_entities[ $_POST['entity'] ];
                $dirs                 = apply_filters( 'updraftplus_dirlist_' . sanitize_text_field( wp_unslash( $_POST['entity'] ) ), $basedir );
                $out['diskspaceused'] = $this->recursive_directory_size( $dirs, $updraftplus->get_exclude( $_POST['entity'] ), $basedir );
            } else {
                $out['error'] = 'Error';
            }
        }

        return $out;
    }

    /**
     * Recursively get directory sizes.
     *   If $basedirs is passed as an array, then $directorieses must be too.
     *
     * @param array  $directorieses Directories to size.
     * @param array  $exclude       Directories to exclude.
     * @param string $basedirs      Base directories.
     *
     * @return string Return total size.
     *
     * @uses MainWP_Child_Updraft_Plus_Backups::recursive_directory_size_raw()
     */
    private function recursive_directory_size( $directorieses, $exclude = array(), $basedirs = '' ) {

        $size = 0;

        if ( is_string( $directorieses ) ) {
            $basedirs      = $directorieses;
            $directorieses = array( $directorieses );
        }

        if ( is_string( $basedirs ) ) {
            $basedirs = array( $basedirs );
        }

        foreach ( $directorieses as $ind => $directories ) {
            if ( ! is_array( $directories ) ) {
                $directories = array( $directories );
            }

            $basedir = empty( $basedirs[ $ind ] ) ? $basedirs[0] : $basedirs[ $ind ];

            foreach ( $directories as $dir ) {
                if ( is_file( $dir ) ) {
                    $size += filesize( $dir );
                } else {
                    $suffix = ( '' !== $basedir ) ? ( ( 0 === strpos( $dir, $basedir . '/' ) ) ? substr( $dir, 1 + strlen( $basedir ) ) : '' ) : '';
                    $size  += $this->recursive_directory_size_raw( $basedir, $exclude, $suffix );
                }
            }
        }

        if ( $size > 1073741824 ) {
            return round( $size / 1073741824, 1 ) . ' Gb';
        } elseif ( $size > 1048576 ) {
            return round( $size / 1048576, 1 ) . ' Mb';
        } elseif ( $size > 1024 ) {
            return round( $size / 1024, 1 ) . ' Kb';
        } else {
            return round( $size, 1 ) . ' b';
        }
    }

    /**
     * Recursivly get raw directory sizes.
     *
     * @param string $prefix_directory Directory prefix.
     * @param array $exclude           Directories to exclude.
     * @param string $suffix_directory Directory suffix.
     *
     * @return false|int Return $size Raw Directory size or FALSE on failure.
     *
     * @uses MainWP_Child_Updraft_Plus_Backups::recursive_directory_size_raw()
     */
    private function recursive_directory_size_raw( $prefix_directory, &$exclude = array(), $suffix_directory = '' ) {

        $directory = $prefix_directory . ( '' === $suffix_directory ? '' : '/' . $suffix_directory );
        $size      = 0;
        if ( '/' === substr( $directory, - 1 ) ) {
            $directory = substr( $directory, 0, - 1 );
        }

        if ( ! file_exists( $directory ) || ! is_dir( $directory ) || ! is_readable( $directory ) ) {
            return - 1;
        }
        if ( file_exists( $directory . '/.donotbackup' ) ) {
            return 0;
        }

        $handle = opendir( $directory );
        if ( $handle ) {
            while ( ( $file = readdir( $handle ) ) !== false ) {
                if ( '.' !== $file && '..' !== $file ) {
                    $spath = ( '' === $suffix_directory ) ? $file : $suffix_directory . '/' . $file;
                    $fkey  = array_search( $spath, $exclude );
                    if ( false !== $fkey ) {
                        unset( $exclude[ $fkey ] );
                        continue;
                    }
                    $path = $directory . '/' . $file;
                    if ( is_file( $path ) ) {
                        $size += filesize( $path );
                    } elseif ( is_dir( $path ) ) {
                        $handlesize = $this->recursive_directory_size_raw( $prefix_directory, $exclude, $suffix_directory . ( '' === $suffix_directory ? '' : '/' ) . $file );
                        if ( $handlesize >= 0 ) {
                            $size += $handlesize;
                        }
                    }
                }
            }
            closedir( $handle );
        }

        return $size;
    }

    /**
     * Get cron job.
     *
     * @param bool $job_id Cron job ID.
     *
     * @return array|bool Return cron job time and data or FALSE on failure.
     *
     * @uses $updraftplus::jobdata_getarray()
     */
    private function get_cron( $job_id = false ) {

        $cron = get_option( 'cron' );
        if ( ! is_array( $cron ) ) {
            $cron = array();
        }
        if ( false === $job_id ) {
            return $cron;
        }

        foreach ( $cron as $time => $job ) {
            if ( isset( $job['updraft_backup_resume'] ) ) {
                foreach ( $job['updraft_backup_resume'] as $hook => $info ) {
                    if ( isset( $info['args'][1] ) && $job_id === $info['args'][1] ) {

                        /** @global object $updraftplus UpdraftPlus object. */
                        global $updraftplus;

                        $jobdata = $updraftplus->jobdata_getarray( $job_id );

                        return ( ! is_array( $jobdata ) ) ? false : array( $time, $jobdata );
                    }
                }
            }
        }
    }

    /**
     * Display active jobs.
     *  A value for $this_job_only also causes something to always be returned
     *  (to allow detection of the job having started on the front-end).
     *
     * @param bool $this_job_only
     *
     * @return string $ret Return active jobs html.
     *
     * @uses MainWP_Child_Updraft_Plus_Backups::get_cron()
     * @uses MainWP_Child_Updraft_Plus_Backups::print_active_job()
     */
    private function print_active_jobs( $this_job_only = false ) {
        $cron = $this->get_cron();
        $ret  = '';

        foreach ( $cron as $time => $job ) {
            if ( isset( $job['updraft_backup_resume'] ) ) {
                foreach ( $job['updraft_backup_resume'] as $hook => $info ) {
                    if ( isset( $info['args'][1] ) ) {
                        $job_id = $info['args'][1];
                        if ( false === $this_job_only || $job_id === $this_job_only ) {
                            $ret .= $this->print_active_job( $job_id, false, $time, $info['args'][0] );
                        }
                    }
                }
            }
        }

        // A value for $this_job_only implies that output is required.
        if ( false !== $this_job_only && ! $ret ) {
            $ret = $this->print_active_job( $this_job_only );
            if ( '' === $ret ) {
                // The presence of the exact ID matters to the front-end - indicates that the backup job has at least begun.
                $ret = '<div style="min-width: 480px; min-height: 48px; text-align:center;margin-top: 4px; clear:left; float:left; padding: 8px; border: 1px solid;" id="updraft-jobid-' . $this_job_only . '" class="updraft_finished"><em>' . esc_html__( 'The backup apparently succeeded and is now complete', 'updraftplus' ) . '</em></div>';
            }
        }

        return $ret;
    }

    /**
     * Display active job.
     *
     * @param string $job_id        Job ID.
     * @param bool $is_oneshot      Whether or not this is a one time backup.
     * @param bool $time            Backup time.
     * @param bool $next_resumption Next backups resumption.
     *
     * @return string Active job html.
     *
     * @uses $updraftplus::jobdata_getarray()
     * @uses $updraftplus::get_backupable_file_entities()
     */
    private function print_active_job( $job_id, $is_oneshot = false, $time = false, $next_resumption = false ) { // phpcs:ignore -- third party credit.

        $ret = '';

        /** @global object $updraftplus UpdraftPlus object. */
        global $updraftplus;
        $jobdata = $updraftplus->jobdata_getarray( $job_id );

        if ( false === apply_filters( 'updraftplus_print_active_job_continue', true, $is_oneshot, $next_resumption, $jobdata ) ) {
            return '';
        }

        if ( ! isset( $jobdata['backup_time'] ) ) {
            return '';
        }

        $backupable_entities = $updraftplus->get_backupable_file_entities( true, true );

        $began_at = ( isset( $jobdata['backup_time'] ) ) ? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) $jobdata['backup_time'] ), 'D, F j, Y H:i' ) : '?';

        $jobstatus = empty( $jobdata['jobstatus'] ) ? 'unknown' : $jobdata['jobstatus'];
        $stage     = 0;
        switch ( $jobstatus ) {
            case 'begun':
                $curstage = esc_html__( 'Backup begun', 'updraftplus' );
                break;
            case 'filescreating':
                $stage    = 1;
                $curstage = esc_html__( 'Creating file backup zips', 'updraftplus' );
                if ( ! empty( $jobdata['filecreating_substatus'] ) && isset( $backupable_entities[ $jobdata['filecreating_substatus']['e'] ]['description'] ) ) {
                    $sdescrip = preg_replace( '/ \(.*\)$/', '', $backupable_entities[ $jobdata['filecreating_substatus']['e'] ]['description'] );
                    if ( strlen( $sdescrip ) > 20 && isset( $jobdata['filecreating_substatus']['e'] ) && is_array( $jobdata['filecreating_substatus']['e'] ) && isset( $backupable_entities[ $jobdata['filecreating_substatus']['e'] ]['shortdescription'] ) ) {
                        $sdescrip = $backupable_entities[ $jobdata['filecreating_substatus']['e'] ]['shortdescription'];
                    }
                    $curstage .= ' (' . $sdescrip . ')';
                    if ( isset( $jobdata['filecreating_substatus']['i'] ) && isset( $jobdata['filecreating_substatus']['t'] ) ) {
                        $stage = min( 2, 1 + ( $jobdata['filecreating_substatus']['i'] / max( $jobdata['filecreating_substatus']['t'], 1 ) ) );
                    }
                }
                break;
            case 'filescreated':
                $stage    = 2;
                $curstage = esc_html__( 'Created file backup zips', 'updraftplus' );
                break;
            case 'clouduploading':
                $stage    = 4;
                $curstage = esc_html__( 'Uploading files to remote storage', 'updraftplus' );
                if ( isset( $jobdata['uploading_substatus']['t'] ) && isset( $jobdata['uploading_substatus']['i'] ) ) {
                    $t         = max( (int) $jobdata['uploading_substatus']['t'], 1 );
                    $i         = min( $jobdata['uploading_substatus']['i'] / $t, 1 );
                    $p         = min( $jobdata['uploading_substatus']['p'], 1 );
                    $pd        = $i + $p / $t;
                    $stage     = 4 + $pd;
                    $curstage .= ' ' . sprintf( esc_html__( '(%1$s%%, file %2$s of %3$s)', 'updraftplus' ), floor( 100 * $pd ), $jobdata['uploading_substatus']['i'] + 1, $t );
                }
                break;
            case 'pruning':
                $stage    = 5;
                $curstage = esc_html__( 'Pruning old backup sets', 'updraftplus' );
                break;
            case 'resumingforerrors':
                $stage    = - 1;
                $curstage = esc_html__( 'Waiting until scheduled time to retry because of errors', 'updraftplus' );
                break;
            case 'finished':
                $stage    = 6;
                $curstage = esc_html__( 'Backup finished', 'updraftplus' );
                break;
            default:
                // Database creation and encryption occupies the space from 2 to 4. Databases are created then encrypted, then the next databae is created/encrypted, etc.
                if ( 'dbcreated' === substr( $jobstatus, 0, 9 ) ) {
                    $jobstatus = 'dbcreated';
                    $whichdb   = substr( $jobstatus, 9 );
                    if ( ! is_numeric( $whichdb ) ) {
                        $whichdb = 0;
                    }
                    $howmanydbs = max( ( empty( $jobdata['backup_database'] ) || ! is_array( $jobdata['backup_database'] ) ) ? 1 : count( $jobdata['backup_database'] ), 1 );
                    $perdbspace = 2 / $howmanydbs;

                    $stage = min( 4, 2 + ( $whichdb + 2 ) * $perdbspace );

                    $curstage = esc_html__( 'Created database backup', 'updraftplus' );

                } elseif ( 'dbcreating' === substr( $jobstatus, 0, 10 ) ) {
                    $whichdb = substr( $jobstatus, 10 );
                    if ( ! is_numeric( $whichdb ) ) {
                        $whichdb = 0;
                    }
                    $howmanydbs = ( empty( $jobdata['backup_database'] ) || ! is_array( $jobdata['backup_database'] ) ) ? 1 : count( $jobdata['backup_database'] );
                    $perdbspace = 2 / $howmanydbs;
                    $jobstatus  = 'dbcreating';

                    $stage = min( 4, 2 + $whichdb * $perdbspace );

                    $curstage = esc_html__( 'Creating database backup', 'updraftplus' );
                    if ( ! empty( $jobdata['dbcreating_substatus']['t'] ) ) {
                        $curstage .= ' (' . sprintf( esc_html__( 'table: %s', 'updraftplus' ), $jobdata['dbcreating_substatus']['t'] ) . ')';
                        if ( ! empty( $jobdata['dbcreating_substatus']['i'] ) && ! empty( $jobdata['dbcreating_substatus']['a'] ) ) {
                            $substage = max( 0.001, ( $jobdata['dbcreating_substatus']['i'] / max( $jobdata['dbcreating_substatus']['a'], 1 ) ) );
                            $stage   += $substage * $perdbspace * 0.5;
                        }
                    }
                } elseif ( 'dbencrypting' === substr( $jobstatus, 0, 12 ) ) {
                    $whichdb = substr( $jobstatus, 12 );
                    if ( ! is_numeric( $whichdb ) ) {
                        $whichdb = 0;
                    }
                    $howmanydbs = ( empty( $jobdata['backup_database'] ) || ! is_array( $jobdata['backup_database'] ) ) ? 1 : count( $jobdata['backup_database'] );
                    $perdbspace = 2 / $howmanydbs;
                    $stage      = min( 4, 2 + $whichdb * $perdbspace + $perdbspace * 0.5 );
                    $jobstatus  = 'dbencrypting';
                    $curstage   = esc_html__( 'Encrypting database', 'updraftplus' );
                } elseif ( 'dbencrypted' === substr( $jobstatus, 0, 11 ) ) {
                    $whichdb = substr( $jobstatus, 11 );
                    if ( ! is_numeric( $whichdb ) ) {
                        $whichdb = 0;
                    }
                    $howmanydbs = ( empty( $jobdata['backup_database'] ) || ! is_array( $jobdata['backup_database'] ) ) ? 1 : count( $jobdata['backup_database'] );
                    $jobstatus  = 'dbencrypted';
                    $perdbspace = 2 / $howmanydbs;
                    $stage      = min( 4, 2 + $whichdb * $perdbspace + $perdbspace );
                    $curstage   = esc_html__( 'Encrypted database', 'updraftplus' );
                } else {
                    $curstage = esc_html__( 'Unknown', 'updraftplus' );
                }
        }

        $runs_started     = ( empty( $jobdata['runs_started'] ) ) ? array() : $jobdata['runs_started'];
        $time_passed      = ( empty( $jobdata['run_times'] ) ) ? array() : $jobdata['run_times'];
        $last_checkin_ago = - 1;
        if ( is_array( $time_passed ) ) {
            foreach ( $time_passed as $run => $passed ) {
                if ( isset( $runs_started[ $run ] ) ) {
                    $time_ago = microtime( true ) - ( $runs_started[ $run ] + $time_passed[ $run ] );
                    if ( $time_ago < $last_checkin_ago || -1 === $last_checkin_ago ) {
                        $last_checkin_ago = $time_ago;
                    }
                }
            }
        }

        $next_res_after    = (int) $time - time();
        $next_res_txt      = ( $is_oneshot ) ? '' : ' - ' . sprintf( esc_html__( 'next resumption: %1$d (after %2$ss)', 'updraftplus' ), $next_resumption, $next_res_after ) . ' ';
        $last_activity_txt = ( $last_checkin_ago >= 0 ) ? ' - ' . sprintf( esc_html__( 'last activity: %ss ago', 'updraftplus' ), floor( $last_checkin_ago ) ) . ' ' : '';

        if ( ( $last_checkin_ago < 50 && $next_res_after > 30 ) || $is_oneshot ) {
            $show_inline_info = $last_activity_txt;
            $title_info       = $next_res_txt;
        } else {
            $show_inline_info = $next_res_txt;
            $title_info       = $last_activity_txt;
        }

        // Existence of the 'updraft-jobid-(id)' id is checked for in other places, so do not modify this.
        $ret .= '<div style="min-width: 480px; margin-top: 4px; clear:left; float:left; padding: 8px; border: 1px solid;" id="updraft-jobid-' . $job_id . '"><span class="updraft_jobtimings" data-jobid="' . $job_id . '" data-lastactivity="' . (int) $last_checkin_ago . '" data-nextresumption="' . $next_resumption . '" data-nextresumptionafter="' . $next_res_after . '" style="font-weight:bold;" title="' . esc_attr( sprintf( esc_html__( 'Job ID: %s', 'updraftplus' ), $job_id ) ) . $title_info . '">' . $began_at . '</span> ';

        $ret .= $show_inline_info;
        $ret .= '- <a href="#" class="updraft-log-link" onclick="event.preventDefault(); mainwp_updraft_popuplog(\'' . $job_id . '\', this);">' . esc_html__( 'show log', 'updraftplus' ) . '</a>';

        if ( ! $is_oneshot ) {
            $ret .= ' - <a title="' . esc_attr( esc_html__( 'Note: the progress bar below is based on stages, NOT time. Do not stop the backup simply because it seems to have remained in the same place for a while - that is normal.', 'updraftplus' ) ) . '" href="javascript:mainwp_updraft_activejobs_delete(\'' . $job_id . '\')">' . esc_html__( 'delete schedule', 'updraftplus' ) . '</a>';
        }

        if ( ! empty( $jobdata['warnings'] ) && is_array( $jobdata['warnings'] ) ) {
            $ret .= '<ul style="list-style: disc inside;">';
            foreach ( $jobdata['warnings'] as $warning ) {
                $ret .= '<li>' . sprintf( esc_html__( 'Warning: %s', 'updraftplus' ), make_clickable( htmlspecialchars( $warning ) ) ) . '</li>';
            }
            $ret .= '</ul>';
        }

        $ret .= '<div style="border-radius: 4px; margin-top: 8px; z-index:0; padding-top: 4px;border: 1px solid #aaa; width: 100%; height: 22px; position: relative; text-align: center; font-style: italic;">';
        $ret .= htmlspecialchars( $curstage );
        $ret .= '<div style="z-index:-1; position: absolute; left: 0px; top: 0px; text-align: center; background-color: #f6a828; height: 100%; width:' . ( ( $stage > 0 ) ? ( ceil( ( 100 / 6 ) * $stage ) ) : '0' ) . '%"></div>';
        $ret .= '</div></div>';

        $ret .= '</div>';

        return $ret;
    }

    /**
     * Fetch Updraft Log.
     *
     * @return array|string[] Return log content, nonce & pointer. ERROR on failure.
     *
     * @uses MainWP_Child_Updraft_Plus_Backups::fetch_log()
     */
    private function fetch_updraft_log() {
        $backup_nonce = isset( $_POST['backup_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['backup_nonce'] ) ) : '';

        return $this->fetch_log( $backup_nonce );
    }

    /**
     * Delete active jobs.
     *
     * @return array|string[] Return Y Job has been deleted or N Job not found.
     */
    private function activejobs_delete() {
        $jobid = isset( $_POST['jobid'] ) ? sanitize_text_field( wp_unslash( $_POST['jobid'] ) ) : '';
        if ( empty( $jobid ) ) {
            return array( 'error' => 'Error: empty job id.' );
        }

        $cron     = get_option( 'cron' );
        $found_it = 0;
        foreach ( $cron as $time => $job ) {
            if ( isset( $job['updraft_backup_resume'] ) ) {
                foreach ( $job['updraft_backup_resume'] as $hook => $info ) {
                    if ( isset( $info['args'][1] ) && $info['args'][1] === $jobid ) {
                        $args = $cron[ $time ]['updraft_backup_resume'][ $hook ]['args'];
                        wp_unschedule_event( $time, 'updraft_backup_resume', $args );
                        if ( ! $found_it ) {
                            return array(
                                'ok' => 'Y',
                                'm'  => esc_html__( 'Job deleted', 'updraftplus' ),
                            );
                        }
                        $found_it = 1;
                    }
                }
            }
        }

        if ( ! $found_it ) {
            return array(
                'ok' => 'N',
                'm'  => esc_html__( 'Could not find that job - perhaps it has already finished?', 'updraftplus' ),
            );
        }

        return array();
    }

    /**
     * Fetch log.
     *
     * @param string $backup_nonce    Backup nonce.
     * @param int    $log_pointer     Log pointer.
     *
     * @return array|string[] Return log content, nonce & pointer. ERROR on failure.
     *
     * @uses $updraftplus::last_modified_log()
     * @uses $updraftplus::backups_dir_location()
     */
    public function fetch_log( $backup_nonce, $log_pointer = 0 ) {

        /** @global object $updraftplus UpdraftPlus object. */
        global $updraftplus;

        if ( empty( $backup_nonce ) ) {
            list( $mod_time, $log_file, $nonce ) = $updraftplus->last_modified_log();
        } else {
            $nonce = $backup_nonce;
        }

        if ( ! preg_match( '/^[0-9a-f]+$/', $nonce ) ) {
            return array( 'error' => 'Security check' );
        }

        $log_content = '';
        $new_pointer = $log_pointer;

        if ( ! empty( $nonce ) ) {
            $updraft_dir = $updraftplus->backups_dir_location();

            $potential_log_file = $updraft_dir . '/log.' . $nonce . '.txt';

            if ( is_readable( $potential_log_file ) ) {

                $templog_array = array();
                $log_file      = fopen( $potential_log_file, 'r' );
                if ( $log_pointer > 0 ) {
                    fseek( $log_file, $log_pointer );
                }

                while ( ( $buffer = fgets( $log_file, 4096 ) ) !== false ) {
                    $templog_array[] = $buffer;
                }
                if ( ! feof( $log_file ) ) {
                    $templog_array[] = esc_html__( 'Error: unexpected file read fail', 'updraftplus' );
                }

                $new_pointer = ftell( $log_file );
                $log_content = implode( '', $templog_array );

            } else {
                $log_content .= esc_html__( 'The log file could not be read.', 'updraftplus' );
            }
        } else {
            $log_content .= esc_html__( 'The log file could not be read.', 'updraftplus' );
        }

        $ret_array = array(
            'html'    => $log_content,
            'nonce'   => $nonce,
            'pointer' => $new_pointer,
        );

        return $ret_array;
    }

    /**
     * Download status.
     *
     * @param string $timestamp Job timestamp
     * @param string $type      Job type.
     * @param string $findex    File Index.
     *
     * @return string[] Return $response array.
     *
     * @uses $updraftplus::jobdata_get()
     */
    private function download_status( $timestamp, $type, $findex ) {

        /** @global object $updraftplus UpdraftPlus object. */
        global $updraftplus;

        $response = array( 'm' => $updraftplus->jobdata_get( 'dlmessage_' . $timestamp . '_' . $type . '_' . $findex ) . '<br>' );
        $file     = $updraftplus->jobdata_get( 'dlfile_' . $timestamp . '_' . $type . '_' . $findex );
        if ( $file ) {
            if ( 'failed' === $file ) {
                $response['e'] = esc_html__( 'Download failed', 'updraftplus' ) . '<br>';
                $errs          = $updraftplus->jobdata_get( 'dlerrors_' . $timestamp . '_' . $type . '_' . $findex );
                if ( is_array( $errs ) && ! empty( $errs ) ) {
                    $response['e'] .= '<ul style="list-style: disc inside;">';
                    foreach ( $errs as $err ) {
                        if ( is_array( $err ) ) {
                            $response['e'] .= '<li>' . htmlspecialchars( $err['message'] ) . '</li>';
                        } else {
                            $response['e'] .= '<li>' . htmlspecialchars( $err ) . '</li>';
                        }
                    }
                    $response['e'] .= '</ul>';
                }
            } elseif ( preg_match( '/^downloaded:(\d+):(.*)$/', $file, $matches ) && file_exists( $matches[2] ) ) {
                $response['p'] = 100;
                $response['f'] = $matches[2];
                $response['s'] = (int) $matches[1];
                $response['t'] = (int) $matches[1];
                $response['m'] = esc_html__( 'File ready.', 'updraftplus' );
            } elseif ( preg_match( '/^downloading:(\d+):(.*)$/', $file, $matches ) && file_exists( $matches[2] ) ) {
                // Convert to bytes.
                $response['f'] = $matches[2];
                $total_size    = (int) max( $matches[1], 1 );
                $cur_size      = filesize( $matches[2] );
                $response['s'] = $cur_size;
                $file_age      = time() - filemtime( $matches[2] );
                if ( $file_age > 20 ) {
                    $response['a'] = time() - filemtime( $matches[2] );
                }
                $response['t']  = $total_size;
                $response['m'] .= esc_html__( 'Download in progress', 'updraftplus' ) . ' (' . round( $cur_size / 1024 ) . ' / ' . round( ( $total_size / 1024 ) ) . ' Kb)';
                $response['p']  = round( 100 * $cur_size / $total_size );
            } else {
                $response['m'] .= esc_html__( 'No local copy present.', 'updraftplus' );
                $response['p']  = 0;
                $response['s']  = 0;
                $response['t']  = 1;
            }
        }

        return $response;
    }

    /**
     * Close browser connection.
     *
     * @param string $txt Return Base64 Encoded output.
     */    
	public function close_browser_connection($txt = '') {
        $output = wp_json_encode( $txt );
        $txt = '<mainwp>' . base64_encode( $output ) . '</mainwp>'; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..

		// Close browser connection so that it can resume AJAX polling
		header('Content-Length: '.(empty($txt) ? '0' : 4+strlen($txt)));
		header('Connection: close');
		header('Content-Encoding: none');
		if (session_id()) session_write_close();
		echo "\r\n\r\n";
		echo $txt;
		// These two added - 19-Feb-15 - started being required on local dev machine, for unknown reason (probably some plugin that started an output buffer).
		$ob_level = ob_get_level();
		while ($ob_level > 0) {
			ob_end_flush();
			$ob_level--;
		}
		flush();
		if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
	}

    /**
     * Initiate UpdraftPlus.
     *
     * @uses MainWP_Child_Updraft_Plus_Backups::is_plugin_installed()
     */
    public function updraftplus_init() {
        if ( ! $this->is_plugin_installed ) {
            return;
        }

        if ( get_option( 'mainwp_updraftplus_hide_plugin' ) === 'hide' ) {
            add_filter( 'all_plugins', array( $this, 'all_plugins' ) );
            add_action( 'admin_menu', array( $this, 'remove_menu' ) );
            add_filter( 'site_transient_update_plugins', array( &$this, 'remove_update_nag' ) );
            add_filter( 'mainwp_child_hide_update_notice', array( &$this, 'hide_update_notice' ) );
            add_action( 'wp_before_admin_bar_render', array( $this, 'wp_before_admin_bar_render' ), 99 );
            add_action( 'admin_init', array( $this, 'remove_notices' ) );
        }
    }

    /**
     * Remove admin notices.
     *
     * @uses MainWP_Child_Updraft_Plus_Backups::remove_filters_for_anonymous_class()
     */
    public function remove_notices() {
        $remove_hooks['all_admin_notices'] = array(
            'UpdraftPlus'                          => array(
                'show_admin_warning_unreadablelog'  => 10,
                'show_admin_warning_nolog'          => 10,
                'show_admin_warning_unreadablefile' => 10,
            ),
            'UpdraftPlus_BackupModule_dropbox'     => array(
                'show_authed_admin_warning' => 10,
            ),
            'UpdraftPlus_BackupModule_googledrive' => array(
                'show_authed_admin_success' => 10,
            ),
        );

        foreach ( $remove_hooks as $hook_name => $hooks ) {
            foreach ( $hooks as $class_name => $methods ) {
                foreach ( $methods as $method => $priority ) {
                    self::remove_filters_for_anonymous_class( $hook_name, $class_name, $method, $priority );
                }
            }
        }
    }

    /**
     * Allows removal of method for a hook when the class doesn't have a variable but you know the class name.
     *
     * @param string $hook_name   Hook name.
     * @param string $class_name  Class name.
     * @param string $method_name Method name.
     * @param int    $priority    Priority.
     *
     * @return bool Return FALSE on failure.
     */
    public static function remove_filters_for_anonymous_class( $hook_name = '', $class_name = '', $method_name = '', $priority = 0 ) {

        /** @global object $wp_filter Wordpress filter object. */
        global $wp_filter;

        // Take only filters on right hook name and priority.
        if ( ! isset( $wp_filter[ $hook_name ] ) || ! isset( $wp_filter[ $hook_name ][ $priority ] ) || ! is_array( $wp_filter[ $hook_name ][ $priority ] ) ) {
            return false;
        }

        // Loop on filters registered.
        foreach ( (array) $wp_filter[ $hook_name ][ $priority ] as $unique_id => $filter_array ) {
            // Test if filter is an array ! (always for class/method).
            if ( isset( $filter_array['function'] ) && is_array( $filter_array['function'] ) ) {
                // Test if object is a class, class and method is equal to param !
                if ( is_object( $filter_array['function'][0] ) && get_class( $filter_array['function'][0] ) && get_class( $filter_array['function'][0] ) === $class_name && $filter_array['function'][1] === $method_name ) {
                    unset( $wp_filter[ $hook_name ][ $priority ][ $unique_id ] );
                }
            }
        }

        return false;
    }

    /**
     * Render before admin bar.
     *
     * @uses $wp_admin_bar::get_nodes()
     * @uses $wp_admin_bar::remove_node()
     */
    public function wp_before_admin_bar_render() {

        /** @global object $wp_admin_bar WordPress Admin Bar object. */
        global $wp_admin_bar;

        $nodes = $wp_admin_bar->get_nodes();
        if ( is_array( $nodes ) ) {
            foreach ( $nodes as $node ) {
                if ( is_array( $nodes ) ) {
                    foreach ( $nodes as $node ) {
                        if ( 'updraft_admin_node' === $node->parent || ( 'updraft_admin_node' === $node->id ) ) {
                            $wp_admin_bar->remove_node( $node->id );
                        }
                    }
                }
            }
        }
    }

    /**
     * Hide UpdraftPlus notices.
     *
     * @param string $slugs Plugin slugs.
     *
     * @return string $slugs Plugin slugs.
     */
    public function hide_update_notice( $slugs ) {
        $slugs[] = 'updraftplus/updraftplus.php';
        return $slugs;
    }

    /**
     * Remove UpdraftPlus update nag.
     *
     * @param string $value String to remove.
     *
     * @return mixed $value Return response.
     *
     * @uses \MainWP\Child\MainWP_Helper::is_updates_screen()
     */
    public function remove_update_nag( $value ) {
        if ( isset( $_POST['mainwpsignature'] ) ) {
            return $value;
        }
        if ( ! MainWP_Helper::is_updates_screen() ) {
            return $value;
        }

        if ( isset( $value->response['updraftplus/updraftplus.php'] ) ) {
            unset( $value->response['updraftplus/updraftplus.php'] );
        }

        return $value;
    }

    /**
     * Get sync data.
     *
     * @param bool $with_hist Whether or not to include history.
     *
     * @uses MainWP_Child_Updraft_Plus_Backups::required_files()
     * @uses MainWP_Child_Updraft_Plus_Backups::get_updraft_data()
     */
    public function get_sync_data( $with_hist = false ) {
        $this->required_files();
        return $this->get_updraft_data( $with_hist );
    }

    /**
     * Remove UpdraftPlus from plugins page.
     *
     * @param array $plugins All plugins array.
     *
     * @return array $plugins All plugins array with UpdraftPlus removed.
     */
    public function all_plugins( $plugins ) {
        foreach ( $plugins as $key => $value ) {
            $plugin_slug = basename( $key, '.php' );
            if ( 'updraftplus' === $plugin_slug ) {
                unset( $plugins[ $key ] );
            }
        }

        return $plugins;
    }

    /**
     * Remove UpdraftPlus from WP Admin menu.
     */
    public function remove_menu() {

        /** @global object $submenu WordPress submenu object. */
        global $submenu;

        if ( isset( $submenu['options-general.php'] ) ) {
            foreach ( $submenu['options-general.php'] as $index => $item ) {
                if ( 'updraftplus' === $item[2] ) {
                    unset( $submenu['options-general.php'][ $index ] );
                    break;
                }
            }
        }

        $pos = isset( $_SERVER['REQUEST_URI'] ) ? stripos( wp_unslash( $_SERVER['REQUEST_URI'] ), 'options-general.php?page=updraftplus' ) : false;
        if ( false !== $pos ) {
            wp_safe_redirect( get_option( 'siteurl' ) . '/wp-admin/index.php' );
            exit();
        }
    }
}
