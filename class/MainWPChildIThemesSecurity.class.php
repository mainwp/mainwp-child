<?php

class MainWPChildIThemesSecurity
{   
    public static $instance = null;   
    
    static function Instance() {
        if (MainWPChildIThemesSecurity::$instance == null) {
            MainWPChildIThemesSecurity::$instance = new MainWPChildIThemesSecurity();
        }
        return MainWPChildIThemesSecurity::$instance;
    }    
    
    public function __construct() {
        add_action('mainwp_child_deactivation', array($this, 'deactivation'));
        add_action('mainwp-site-sync-others-data', array($this, 'syncOthersData'));
    }
    
    public function deactivation()
    {
        
    }    
    
    function syncOthersData($data) {
        if (is_array($data) && isset($data['ithemeExtActivated']) && ($data['ithemeExtActivated'] == 'yes')) {
            MainWPHelper::update_option('mainwp_ithemes_ext_activated', 'Y');                 
        } else {
            MainWPHelper::update_option('mainwp_ithemes_ext_activated', '');                 
        }
    }
    
    public function action() {   
        $information = array();
        if (!class_exists('ITSEC_Core')) {
            $information['error'] = 'NO_ITHEME_SECURITY';
            MainWPHelper::write($information);
        }   
        if (isset($_POST['mwp_action'])) {
            switch ($_POST['mwp_action']) {                               
                case "set_showhide":
                    $information = $this->set_showhide();
                break;                                                                              
                case "save_settings":
                    $information = $this->save_settings();
                break;  
                case "whitelist":
                    $information = $this->whitelist();
                break;      
                case "whitelist_release":
                    $information = $this->whitelist_release();
                break;      
                case "backup_db":
                    $information = $this->backup_db();
                break;      
                case "admin_user":
                    $information = $this->admin_user();
                break;      
//                case "content_dir":
//                    $information = $this->process_directory();
//                break; 
                case "database_prefix":
                    $information = $this->process_database_prefix();
                break; 
                case "api_key":
                    $information = $this->api_key();
                break;
                case "reset_api_key":
                    $information = $this->reset_api_key();
                break;
                case "malware_scan":
                    $information = $this->malware_scan();
                break; 
                case "malware_get_scan_results":
                    $information = $this->malware_get_scan_results();
                break; 
                case "clear_all_logs":
                    $information = $this->purge_logs();
                break; 
                case "file_check":
                    $information = $this->file_check();
                break; 
                case "release_lockout":
                    $information = $this->release_lockout();
                break;             
            }        
        }
        MainWPHelper::write($information);
    }
   
    function set_showhide() {
        MainWPHelper::update_option('mainwp_ithemes_ext_enabled', "Y");        
        $hide = isset($_POST['showhide']) && ($_POST['showhide'] === "hide") ? 'hide' : "";
        MainWPHelper::update_option('mainwp_ithemes_hide_plugin', $hide);        
        $information['result'] = 'SUCCESS';
        return $information;
    }
    public function ithemes_init()
    {  
        if (get_option('mainwp_ithemes_ext_enabled') !== "Y")
            return;
        
        if (get_option('mainwp_ithemes_hide_plugin') === "hide")
        {
            add_filter('all_plugins', array($this, 'all_plugins'));   
            add_action( 'admin_menu', array($this, 'remove_menu'));
        }        
    }
    
    public function all_plugins($plugins) {
        foreach ($plugins as $key => $value)
        {
            $plugin_slug = basename($key, '.php');
            if ($plugin_slug == 'better-wp-security')
                unset($plugins[$key]);
        }
        return $plugins;       
    }
    
    public function remove_menu() {
        remove_menu_page('itsec');  
    }  
  
    function save_settings() {
        global $itsec_globals;
        
        if ( ! class_exists( 'ITSEC_Lib' ) ) {
            require( trailingslashit( $itsec_globals['plugin_dir'] ) . 'core/class-itsec-lib.php' );
        }

        MainWPHelper::update_option('mainwp_ithemes_ext_enabled', "Y");   
        $settings = unserialize(base64_decode($_POST['settings']));           
        $updated = false;
        $rewrites_changed = false;
        if (isset($settings['itsec_global'])) {            
            if (update_site_option('itsec_global', $settings['itsec_global'])) {                
                if (isset( $settings['itsec_global']['write_files'] ) && $settings['itsec_global']['write_files'] === true) {
                    add_site_option( 'itsec_rewrites_changed', true );
                    $rewrites_changed = true;
                }                
                $updated = true;    
            }
        }
        
        if (isset($settings['itsec_away_mode'])) {
            if (update_site_option('itsec_away_mode', $settings['itsec_away_mode']))
                $updated = true;    
        }
        
        if (isset($settings['itsec_backup'])) {            
            $backup = get_site_option( 'itsec_backup' );
            if ( $backup !== false && isset( $backup['last_run'] ) ) {
                    $settings['itsec_backup']['last_run'] = $backup['last_run'];
            } else {
                    unset($settings['itsec_backup']['last_run']);
            }
            if (update_site_option('itsec_backup', $settings['itsec_backup']))
                $updated = true;    
        }
        
        if (isset($settings['itsec_ban_users'])) {
            $old_settings = get_site_option('itsec_ban_users');            
            if (update_site_option('itsec_ban_users', $settings['itsec_ban_users'])) {
                $input = $settings['itsec_ban_users'];
                if ( 
                        $input['host_list'] !== $old_settings['host_list'] ||
                        $input['enabled'] !== $old_settings['enabled'] ||
                        $input['default'] !== $old_settings['default'] ||
                        $input['agent_list'] !== $old_settings['agent_list']
                    ) {                    
                    if (!$rewrites_changed)
                        add_site_option( 'itsec_rewrites_changed', true );                    
                }
                $updated = true;    
            }
        }
        
        if (isset($settings['itsec_brute_force'])) {
            if (update_site_option('itsec_brute_force', $settings['itsec_brute_force']))
                $updated = true;    
        }
        if (isset($settings['itsec_file_change'])) {            
            $file_change = get_site_option( 'itsec_file_change' );           
            
            if ( $file_change !== false && isset( $file_change['last_run'] ) ) {
                    $settings['itsec_file_change']['last_run'] = $file_change['last_run'];
            } else {
                    unset($settings['itsec_file_change']['last_run']);
            }
            
            if ( $file_change !== false && isset( $file_change['last_chunk'] ) ) {
                    $settings['itsec_file_change']['last_chunk'] = $file_change['last_chunk'];
            } else {
                    unset($settings['itsec_file_change']['last_chunk']);
            }            
            
            if (update_site_option('itsec_file_change', $settings['itsec_file_change']))
                $updated = true;    
        }
        if (isset($settings['itsec_four_oh_four'])) {
            if (update_site_option('itsec_four_oh_four', $settings['itsec_four_oh_four']))
                $updated = true;    
        }
        
        if (isset($settings['itsec_hide_backend'])) {
            $old_settings = get_site_option('itsec_hide_backend');
            if (update_site_option('itsec_hide_backend', $settings['itsec_hide_backend'])) {
                $input = $settings['itsec_hide_backend'];
                if (
                                $input['slug'] !== $old_settings['slug'] ||
                                $input['register'] !== $old_settings['register'] ||
                                $input['enabled'] !== $old_settings['enabled']
                    ) {
                    if (!$rewrites_changed)
                        add_site_option( 'itsec_rewrites_changed', true );

                }
                
                if ( $input['slug'] != $old_settings['slug'] && $input['enabled'] === true ) {
                    add_site_option( 'itsec_hide_backend_new_slug', $input['slug'] );                   
                }
                
                $updated = true;    
            }
        }
        
        if (isset($settings['itsec_ipcheck'])) {
            if (update_site_option('itsec_ipcheck', $settings['itsec_ipcheck']))
                $updated = true;    
        }
        
        if (isset($settings['itsec_malware'])) {
            if (update_site_option('itsec_malware', $settings['itsec_malware']))
                $updated = true;    
        }  
        
        if (isset($settings['itsec_ssl'])) {
            if (update_site_option('itsec_ssl', $settings['itsec_ssl']))
                $updated = true;    
        }   
        
        if (isset($settings['itsec_strong_passwords'])) {
            if (update_site_option('itsec_strong_passwords', $settings['itsec_strong_passwords']))
                $updated = true;    
        }
        if (isset($settings['itsec_tweaks'])) {
            $old_settings = get_site_option('itsec_tweaks');
                
            $is_safe     = ITSEC_Lib::safe_jquery_version() === true;                
            $raw_version = get_site_option( 'itsec_jquery_version' ); 
            
            if ( $is_safe !== true && $raw_version !== false ) {
                $enable_set_safe_jquery = true;
            }
            
            if (!$enable_set_safe_jquery) {
                $settings['itsec_tweaks']['safe_jquery'] = 0;
            }
                
            if (update_site_option('itsec_tweaks', $settings['itsec_tweaks'])) {
                if (  $input['protect_files'] !== $old_settings['protect_files'] ||
                      $input['directory_browsing'] !== $old_settings['directory_browsing'] ||
                      $input['request_methods'] !== $old_settings['request_methods'] ||
                      $input['suspicious_query_strings'] !== $old_settings['suspicious_query_strings'] ||
                      $input['non_english_characters'] !== $old_settings['non_english_characters'] ||
                      $input['comment_spam'] !== $old_settings['comment_spam'] ||
                      $input['disable_xmlrpc'] !== $old_settings['disable_xmlrpc'] ||
                      $input['uploads_php'] !== $old_settings['uploads_php']
                    )
                {
                    if (!$rewrites_changed)
			add_site_option( 'itsec_rewrites_changed', true );
		}                
                $updated = true;    
            }
        } 
  
        $site_status = array(
            'username_admin_exists' => username_exists( 'admin' ) ? 1 : 0,
            'user_id1_exists' => ITSEC_Lib::user_id_exists( 1 ) ? 1 : 0,
            'backup' => $this->backup_status(),
            'permalink_structure' => get_option( 'permalink_structure' ),
            'is_multisite' => is_multisite() ? 1 : 0,
            'users_can_register' => get_site_option( 'users_can_register' ) ? 1 : 0,
            'force_ssl_login' => (defined( 'FORCE_SSL_LOGIN' ) && FORCE_SSL_LOGIN === true) ? 1 : 0,
            'force_ssl_admin' => (defined( 'FORCE_SSL_ADMIN' ) && FORCE_SSL_ADMIN === true) ? 1 : 0,
            'server_nginx' => (ITSEC_Lib::get_server() == 'nginx') ? 1 : 0,
            'lockouts_host' => $this->get_lockouts( 'host', true ),
            'lockouts_user' => $this->get_lockouts( 'user', true ),
            'lockouts_username' => $this->get_lockouts( 'username', true )
            );
        
        $out = array();                      
        if ($updated)
            $out['result'] = 'success';
        else
            $out['result'] = 'noupdate';
        
        $out['site_status'] = $site_status;
        
        return $out;
    }
    
    function backup_status() {        
        $status = 0;       
        if ( ! is_multisite() && class_exists( 'backupbuddy_api' ) && sizeof( backupbuddy_api::getSchedules() ) >= 1 ) {
            $status = 1;
        } elseif ( ! is_multisite() && class_exists( 'backupbuddy_api' ) ) {
            $status = 2;
        } elseif ( $this->has_backup() === true && $this->scheduled_backup() === true ) {
            $status = 3;
        } elseif ( $this->has_backup() === true ) {
            $status = 4;
        } 
        return $status;
    }
    
    public function has_backup() {
        $has_backup = false;
        return apply_filters( 'itsec_has_external_backup', $has_backup );
    }
    
    public function scheduled_backup() {
        $sceduled_backup = false;
        return apply_filters( 'itsec_scheduled_external_backup', $sceduled_backup );
    }

    public function whitelist() {   
        
        global $itsec_globals;
        $ip = $_POST['ip']; 
        $add_temp   = false;        
        $temp_ip    = get_site_option( 'itsec_temp_whitelist_ip' );
        if ( $temp_ip !== false ) {
                if ( ($temp_ip['exp'] < $itsec_globals['current_time']) || ($temp_ip['exp'] != $ip)) {
                        delete_site_option( 'itsec_temp_whitelist_ip' );
                        $add_temp = true;
                }
        } else {
                $add_temp = true;
        }

        if ( $add_temp === false ) {
                return array('error' => 'Not Updated');
        } else {               
                $response = array(
                        'ip'  => $ip,
                        'exp' => $itsec_globals['current_time'] + 86400,
                );
                add_site_option( 'itsec_temp_whitelist_ip', $response );
                $response['exp_diff']      = human_time_diff( $itsec_globals['current_time'], $response['exp'] );
                $response['message1'] = __( 'Your IP Address', 'it-l10n-better-wp-security' );
                $response['message2'] = __( 'is whitelisted for', 'it-l10n-better-wp-security' );
                return $response;
        }

    }
    
    function whitelist_release() {
        delete_site_option( 'itsec_temp_whitelist_ip' );
        return 'success';
    } 
    
    function backup_db() {
        global $itsec_globals;
        if ( ! class_exists( 'ITSEC_Backup' ) ) {
            require( trailingslashit( $itsec_globals['plugin_dir'] ) . 'modules/free/backup/class-itsec-backup.php' );
        }
        $module = new ITSEC_Backup();  
        $out = array();
        if ($module->do_backup( false ))
            $out['result'] = 'success';
        else
            $out['result'] = 'fail';
        return $out;
    }     
    
    function admin_user() {
        //Process admin user        
        $username    = isset( $_POST['admin_username'] ) ? trim( sanitize_text_field( $_POST['admin_username'] ) ) : null;
        $change_id_1 = ( isset( $_POST['admin_userid'] ) && intval( $_POST['admin_userid'] == 1 ) ? true : false );
        
        //load utility functions
        if ( ! class_exists( 'ITSEC_Lib' ) ) {
            global $itsec_globals;
            require( trailingslashit( $itsec_globals['plugin_dir'] ) . 'core/class-itsec-lib.php' );
        }
        
        $username_exists = username_exists( 'admin' );
        $user_id_exists = ITSEC_Lib::user_id_exists( 1 );
        $msg = "";
        if ( strlen( $username ) >= 1 && !$username_exists) {           
            $msg = __("Admin user already changes.", "mainwp-child");
        } 
        
        if ( $change_id_1 === true && !$user_id_exists) {     
            if (!empty($msg)) 
                $msg .= "<br/>";
            $msg .= __("Admin user ID already changes.", "mainwp-child");
        }
        
        if ($change_id_1) {
            $user = get_user_by('login', $_POST['user']);    
            if ($user->ID == 1) {
                $out['result'] = 'CHILD_ADMIN'; 
                return $out;
            }
        }        
        
        $admin_success = true;
        $out = array();

        if ( strlen( $username ) >= 1 && $username_exists) {                               
            $admin_success = $this->change_admin_user( $username, $change_id_1 );
        } elseif ( $change_id_1 === true && $user_id_exists) {                
            $admin_success = $this->change_admin_user( null, $change_id_1 );
        }
        
        $out['message'] = $msg;
        if ( $admin_success === false ) {
            $out['result'] = 'fail';            
        } else {
            $out['result'] = 'success';
        }
        return $out;
    }
    
    private function change_admin_user( $username = null, $id = false ) {

        global $itsec_globals, $itsec_files, $wpdb;   
       
        if ( $itsec_files->get_file_lock( 'admin_user' ) ) { //make sure it isn't already running

                //sanitize the username
                $new_user = sanitize_text_field( $username );

                //Get the full user object
                $user_object = get_user_by( 'id', '1' );

                if ( $username !== null && validate_username( $new_user ) && username_exists( $new_user ) === null ) { //there is a valid username to change

                        if ( $id === true ) { //we're changing the id too so we'll set the username

                                $user_login = $new_user;

                        } else { // we're only changing the username

                                //query main user table
                                $wpdb->query( "UPDATE `" . $wpdb->users . "` SET user_login = '" . esc_sql( $new_user ) . "' WHERE user_login='admin';" );

                                if ( is_multisite() ) { //process sitemeta if we're in a multi-site situation

                                        $oldAdmins = $wpdb->get_var( "SELECT meta_value FROM `" . $wpdb->sitemeta . "` WHERE meta_key = 'site_admins'" );
                                        $newAdmins = str_replace( '5:"admin"', strlen( $new_user ) . ':"' . esc_sql( $new_user ) . '"', $oldAdmins );
                                        $wpdb->query( "UPDATE `" . $wpdb->sitemeta . "` SET meta_value = '" . esc_sql( $newAdmins ) . "' WHERE meta_key = 'site_admins'" );

                                }

                                wp_clear_auth_cookie();
                                $itsec_files->release_file_lock( 'admin_user' );

                                return true;

                        }

                } elseif ( $username !== null ) { //username didn't validate

                        $itsec_files->release_file_lock( 'admin_user' );

                        return false;

                } else { //only changing the id

                        $user_login = $user_object->user_login;

                }

                if ( $id === true ) { //change the user id

                        $wpdb->query( "DELETE FROM `" . $wpdb->users . "` WHERE ID = 1;" );

                        $wpdb->insert( $wpdb->users, array(
                                'user_login'          => $user_login, 'user_pass' => $user_object->user_pass,
                                'user_nicename'       => $user_object->user_nicename, 'user_email' => $user_object->user_email,
                                'user_url'            => $user_object->user_url, 'user_registered' => $user_object->user_registered,
                                'user_activation_key' => $user_object->user_activation_key,
                                'user_status'         => $user_object->user_status, 'display_name' => $user_object->display_name
                        ) );

                        if ( is_multisite() && $username !== null && validate_username( $new_user ) ) { //process sitemeta if we're in a multi-site situation

                                $oldAdmins = $wpdb->get_var( "SELECT meta_value FROM `" . $wpdb->sitemeta . "` WHERE meta_key = 'site_admins'" );
                                $newAdmins = str_replace( '5:"admin"', strlen( $new_user ) . ':"' . esc_sql( $new_user ) . '"', $oldAdmins );
                                $wpdb->query( "UPDATE `" . $wpdb->sitemeta . "` SET meta_value = '" . esc_sql( $newAdmins ) . "' WHERE meta_key = 'site_admins'" );

                        }

                        $new_user = $wpdb->insert_id;

                        $wpdb->query( "UPDATE `" . $wpdb->posts . "` SET post_author = '" . $new_user . "' WHERE post_author = 1;" );
                        $wpdb->query( "UPDATE `" . $wpdb->usermeta . "` SET user_id = '" . $new_user . "' WHERE user_id = 1;" );
                        $wpdb->query( "UPDATE `" . $wpdb->comments . "` SET user_id = '" . $new_user . "' WHERE user_id = 1;" );
                        $wpdb->query( "UPDATE `" . $wpdb->links . "` SET link_owner = '" . $new_user . "' WHERE link_owner = 1;" );

                        wp_clear_auth_cookie();
                        $itsec_files->release_file_lock( 'admin_user' );

                        return true;

                }

        }

        return false;

    }
    
//    public function process_directory() {
//        global $itsec_files, $itsec_globals;        
//        //suppress error messages due to timing
//        error_reporting( 0 );
//        @ini_set( 'display_errors', 0 );
//        $out = array();  
//        $msg = "";          
//        if ( strpos( WP_CONTENT_DIR, 'wp-content' ) === false && strpos( WP_CONTENT_URL, 'wp-content' ) === false ) {
//            $dir_name = substr( WP_CONTENT_DIR, strrpos( WP_CONTENT_DIR, '/' ) + 1 );
//            $msg .= __( 'Congratulations! You have already renamed your "wp-content" directory.', 'it-l10n-better-wp-security' );
//            $msg .= __( 'Your current content directory is: ', 'it-l10n-better-wp-security' );
//            $msg .= '<strong>' . $dir_name . '</strong>';
//            $out['message'] = $msg;
//            return $out;
//        }        
//       
//        if ( !isset( $itsec_globals['settings']['write_files'] ) || $itsec_globals['settings']['write_files'] !== true ) {
//            $out['result'] = 'fail';
//            $msg = sprintf(
//                            '%s %s %s',
//                            __( 'You must allow this plugin to write to the wp-config.php file on the', 'it-l10n-better-wp-security' ),
//                            __( 'Settings', 'it-l10n-better-wp-security' ),
//                            __( 'page to use this feature.', 'it-l10n-better-wp-security' )
//                    );
//            $out['message'] = $msg;
//            return $out;
//        }
//               
//        
//        $dir_name      = sanitize_file_name( $_POST['name'] );
//        $old_directory = '';
//        $new_directory = '';
//        if ( strlen( $dir_name ) <= 2 ) { //make sure the directory name is at least 2 characters
//                $type    = 'error';
//                $message = __( 'Please choose a directory name that is greater than 2 characters in length.', 'it-l10n-better-wp-security' );
//        } elseif ( $dir_name === 'wp-content' ) {
//                $type    = 'error';
//                $message = __( 'You have not chosen a new name for wp-content. Nothing was saved.', 'it-l10n-better-wp-security' );
//        } else { //process the name change
//
//                $rules = $this->build_wpconfig_rules( array(), $dir_name );
//
//                $itsec_files->set_wpconfig( $rules );
//                $configs = $itsec_files->save_wpconfig();                
//                
//                if ( is_array( $configs ) ) {
//
//                        if ( $configs['success'] === false ) {
//                                $type    = 'error';
//                                $message = $configs['text'];
//                        }
//
//                        $old_directory = WP_CONTENT_DIR;
//                        $new_directory = trailingslashit( ABSPATH ) . $dir_name;
//
//                        $renamed = rename( $old_directory, $new_directory );
//
//                        if ( ! $renamed ) {
//
//                                $type    = 'error';
//                                $message = __( 'Unable to rename the wp-content folder. Operation cancelled.', 'it-l10n-better-wp-security' );
//
//                        }
//
//                } else {
//
//                        add_site_option( 'itsec_manual_update', true );
//
//                }
//
//        }
//
//      
//        $backup = get_site_option( 'itsec_backup' );
//
//        if ( $backup !== false && isset( $backup['location'] ) ) {
//
//                $backup['location'] = str_replace( $old_directory, $new_directory, $backup['location'] );
//                update_site_option( 'itsec_backup', $backup );
//
//        }
//
//        $global = get_site_option( 'itsec_global' );
//
//        if ( $global !== false && ( isset( $global['log_location'] ) || isset( $global['nginx_file'] ) ) ) {
//
//                if ( isset( $global['log_location'] ) ) {
//                        $global['log_location'] = str_replace( $old_directory, $new_directory, $global['log_location'] );
//                }
//
//                if ( isset( $global['nginx_file'] ) ) {
//                        $global['nginx_file'] = str_replace( $old_directory, $new_directory, $global['nginx_file'] );
//                }
//                update_site_option( 'itsec_global', $global );
//        }
//          
//        if ( isset( $type ) ) {
//            $out['result'] = 'fail';
//            $out['error'] = $message;
//        } else {
//            $out['result'] = 'success';
//        }
//        
//        return $out;        
//    }

    public function build_wpconfig_rules( $rules_array, $input = null ) {
        //Get the rules from the database if input wasn't sent
        if ( $input === null ) {
                return $rules_array;
        }

        $new_dir = trailingslashit( ABSPATH ) . $input;

        $rules[] = array(
                'type' => 'add', 'search_text' => '//Do not delete these. Doing so WILL break your site.',
                'rule' => "//Do not delete these. Doing so WILL break your site.",
        );

        $rules[] = array(
                'type' => 'add', 'search_text' => 'WP_CONTENT_URL',
                'rule' => "define( 'WP_CONTENT_URL', '" . trailingslashit( get_option( 'siteurl' ) ) . $input . "' );",
        );

        $rules[] = array(
                'type' => 'add', 'search_text' => 'WP_CONTENT_DIR',
                'rule' => "define( 'WP_CONTENT_DIR', '" . $new_dir . "' );",
        );

        $rules_array[] = array( 'type' => 'wpconfig', 'name' => 'Content Directory', 'rules' => $rules, );

        return $rules_array;

    }
        
    public function process_database_prefix() {
            global $wpdb, $itsec_files, $itsec_globals;

            //suppress error messages due to timing
            error_reporting( 0 );
            @ini_set( 'display_errors', 0 );

            $out = array();
           if ( !isset( $itsec_globals['settings']['write_files'] ) || $itsec_globals['settings']['write_files'] !== true ) {
                $out['result'] = 'fail';                   
                $msg = sprintf(
                                '%s %s %s',
                                __( 'You must allow this plugin to write to the wp-config.php file on the', 'it-l10n-better-wp-security' ),
                                __( 'Settings', 'it-l10n-better-wp-security' ),
                                __( 'page to use this feature.', 'it-l10n-better-wp-security' )
                        );
                $out['message'] = $msg;
                return $out;
            }

            $check_prefix = true; //Assume the first prefix we generate is unique

            //generate a new table prefix that doesn't conflict with any other in use in the database
            while ( $check_prefix ) {

                    $avail = 'abcdefghijklmnopqrstuvwxyz0123456789';

                    //first character should be alpha
                    $new_prefix = $avail[ mt_rand( 0, 25 ) ];

                    //length of new prefix
                    $prelength = mt_rand( 4, 9 );

                    //generate remaning characters
                    for ( $i = 0; $i < $prelength; $i ++ ) {
                            $new_prefix .= $avail[ mt_rand( 0, 35 ) ];
                    }

                    //complete with underscore
                    $new_prefix .= '_';

                    $new_prefix = esc_sql( $new_prefix ); //just be safe

                    $check_prefix = $wpdb->get_results( 'SHOW TABLES LIKE "' . $new_prefix . '%";', ARRAY_N ); //if there are no tables with that prefix in the database set checkPrefix to false

            }

            //assume this will work
            $type    = 'success';
            $message = __( 'Settings Updated', 'it-l10n-better-wp-security' );

            $tables = $wpdb->get_results( 'SHOW TABLES LIKE "' . $wpdb->base_prefix . '%"', ARRAY_N ); //retrieve a list of all tables in the DB

            //Rename each table
            foreach ( $tables as $table ) {

                    $table = substr( $table[0], strlen( $wpdb->base_prefix ), strlen( $table[0] ) ); //Get the table name without the old prefix

                    //rename the table and generate an error if there is a problem
                    if ( $wpdb->query( 'RENAME TABLE `' . $wpdb->base_prefix . $table . '` TO `' . $new_prefix . $table . '`;' ) === false ) {

                            $type    = 'error';
                            $message = sprintf( '%s %s%s. %s', __( 'Error: Could not rename table', 'it-l10n-better-wp-security' ), $wpdb->base_prefix, $table, __( 'You may have to rename the table manually.', 'it-l10n-better-wp-security' ) );

                            //add_settings_error( 'itsec', esc_attr( 'settings_updated' ), $message, $type );

                    }

            }

            if ( is_multisite() ) { //multisite requires us to rename each blogs' options

                    $blogs = $wpdb->get_col( "SELECT blog_id FROM `" . $new_prefix . "blogs` WHERE public = '1' AND archived = '0' AND mature = '0' AND spam = '0' ORDER BY blog_id DESC" ); //get list of blog id's

                    if ( is_array( $blogs ) ) { //make sure there are other blogs to update

                            //update each blog's user_roles option
                            foreach ( $blogs as $blog ) {

                                    $wpdb->query( 'UPDATE `' . $new_prefix . $blog . '_options` SET option_name = "' . $new_prefix . $blog . '_user_roles" WHERE option_name = "' . $wpdb->base_prefix . $blog . '_user_roles" LIMIT 1;' );

                            }

                    }

            }

            $upOpts = $wpdb->query( 'UPDATE `' . $new_prefix . 'options` SET option_name = "' . $new_prefix . 'user_roles" WHERE option_name = "' . $wpdb->base_prefix . 'user_roles" LIMIT 1;' ); //update options table and set flag to false if there's an error

            if ( $upOpts === false ) { //set an error

                    $type    = 'error';
                    $message = __( 'Could not update prefix references in options table.', 'it-l10n-better-wp-security' );;

                    //add_settings_error( 'itsec', esc_attr( 'settings_updated' ), $message, $type );

            }

            $rows = $wpdb->get_results( 'SELECT * FROM `' . $new_prefix . 'usermeta`' ); //get all rows in usermeta

            //update all prefixes in usermeta
            foreach ( $rows as $row ) {

                    if ( substr( $row->meta_key, 0, strlen( $wpdb->base_prefix ) ) == $wpdb->base_prefix ) {

                            $pos = $new_prefix . substr( $row->meta_key, strlen( $wpdb->base_prefix ), strlen( $row->meta_key ) );

                            $result = $wpdb->query( 'UPDATE `' . $new_prefix . 'usermeta` SET meta_key="' . $pos . '" WHERE meta_key= "' . $row->meta_key . '" LIMIT 1;' );

                            if ( $result == false ) {

                                    $type    = 'error';
                                    $message = __( 'Could not update prefix references in usermeta table.', 'it-l10n-better-wp-security' );

                                    //add_settings_error( 'itsec', esc_attr( 'settings_updated' ), $message, $type );

                            }

                    }

            }

            $rules[] = array(
                    'type'  => 'wpconfig',
                    'name'  => 'Database Prefix',
                    'rules' => array(
                            array(
                                    'type'        => 'replace',
                                    'search_text' => 'table_prefix',
                                    'rule'        => "\$table_prefix = '" . $new_prefix . "';",
                            ),
                    ),
            );

            $itsec_files->set_wpconfig( $rules );
            $configs = $itsec_files->save_wpconfig();   

            if ( is_array( $configs ) ) {

                    if ( $configs['success'] === false ) {

                            $type    = 'error';
                            $message = $configs['text'];

                            //add_settings_error( 'itsec', esc_attr( 'settings_updated' ), $message, $type );

                    }

            } else {

                    add_site_option( 'itsec_manual_update', true );

            }

            if ( isset( $type ) &&  $type == 'error') {
                $out['result'] = 'fail';
                $out['error'] = $message;
            } else {
                $out['result'] = 'success';
                $out['message'] = $message;
            }

            return $out;         
	}

    public function api_key() {
        $settings = get_site_option('itsec_ipcheck');
        if (!is_array($settings)) {
            $settings = array();
        }       
        $settings['reset'] = true;
        $out = array();
        if (update_site_option( 'itsec_ipcheck', $settings ))
            $out['result'] = 'success';
        else
            $out['result'] = 'nochange';
        return $out;
    }
    
    public function reset_api_key() {
        $settings = get_site_option('itsec_ipcheck');
        if (!is_array($settings)) {
            $settings = array();
        }        
        unset( $settings['api_key'] );
        unset( $settings['api_s'] );
        unset( $settings['email'] );
        unset( $settings['reset'] );
        
        $out = array();
        if (update_site_option( 'itsec_ipcheck', $settings ))
            $out['result'] = 'success';
        else
            $out['result'] = 'nochange';
        return $out;
    }
    
    public function malware_scan() {
        global $itsec_globals;
        if ( ! class_exists( 'ITSEC_Malware' ) ) {
            require( trailingslashit( $itsec_globals['plugin_dir'] ) . 'modules/free/malware/class-itsec-malware.php' );
        }  
        $module = new ITSEC_Malware();
        $module->run();
        $response = $module->one_time_scan();
        return $response;
    }
    
    
    public function malware_get_scan_results() {    
        global $itsec_globals;
        if ( ! class_exists( 'ITSEC_Malware' ) ) {
            require( trailingslashit( $itsec_globals['plugin_dir'] ) . 'modules/free/malware/class-itsec-malware.php' );
        }  
        $module = new ITSEC_Malware();
        $module->run();
        $response = $module->scan_report();       
        return $response;
    } 
    
    public function purge_logs() {
        global $wpdb;
        $wpdb->query( "DELETE FROM `" . $wpdb->base_prefix . "itsec_log`;" );        
        return array('result' => 'success');
    }
    
    public function file_check() {        
        global $itsec_globals;
        if ( ! class_exists( 'ITSEC_File_Change' ) ) {
            require( trailingslashit( $itsec_globals['plugin_dir'] ) . 'modules/free/file-change/class-itsec-file-change.php' );
        }         
        
        $module = new ITSEC_File_Change();
        $module->run();     
                
        $file_change = get_site_option( 'itsec_file_change' );
        if ( $file_change !== false && isset( $file_change['last_run'] ) ) {
                $last_run = $file_change['last_run'];
        } else {
                $last_run = 0;
        }
            
        return array(   'result' => (int)$module->execute_file_check( false ),
                        'last_run' => $last_run
                    );
    }        
        
    public function get_lockouts( $type = 'all', $current = false ) {

            global $wpdb, $itsec_globals;

            if ( $type !== 'all' || $current === true ) {
                    $where = " WHERE ";
            } else {
                    $where = '';
            }

            switch ( $type ) {

                    case 'host':
                            $type_statement = "`lockout_host` IS NOT NULL && `lockout_host` != ''";
                            break;
                    case 'user':
                            $type_statement = "`lockout_user` != 0";
                            break;
                    case 'username':
                            $type_statement = "`lockout_username` IS NOT NULL && `lockout_username` != ''";
                            break;
                    default:
                            $type_statement = '';
                            break;

            }

            if ( $current === true ) {

                    if ( $type_statement !== '' ) {
                            $and = ' AND ';
                    } else {
                            $and = '';
                    }

                    $active = $and . " `lockout_active`=1 AND `lockout_expire_gmt` > '" . date( 'Y-m-d H:i:s', $itsec_globals['current_time_gmt'] ) . "'";

            } else {

                    $active = '';

            }

            $results = $wpdb->get_results( "SELECT * FROM `" . $wpdb->base_prefix . "itsec_lockouts`" . $where . $type_statement . $active . ";", ARRAY_A );            
            $output = array();
            if (is_array($results) && count($results) > 0) {
                switch ( $type ) {
                    case 'host':
                            foreach ($results as $val) {
                                $output[] = array(
                                    'lockout_id' => $val['lockout_id'],
                                    'lockout_host' => $val['lockout_host'],
                                    'lockout_expire_gmt' => $val['lockout_expire_gmt']
                                );
                            }
                            break;
                    case 'user':
                            foreach ($results as $val) {
                                $output[] = array(
                                    'lockout_id' => $val['lockout_id'],
                                    'lockout_user' => $val['lockout_user'],
                                    'lockout_expire_gmt' => $val['lockout_expire_gmt']
                                );
                            }
                            break;
                    case 'username':
                            foreach ($results as $val) {
                                $output[] = array(
                                    'lockout_id' => $val['lockout_id'],
                                    'lockout_username' => $val['lockout_username'],
                                    'lockout_expire_gmt' => $val['lockout_expire_gmt']
                                );
                            }
                            break;
                    default:
                            break;
                }
            }
            return $output;
	}
      
    public function release_lockout() {            
        global $wpdb, $itsec_globals;

        if ( ! class_exists( 'ITSEC_Lib' ) ) {
            require( trailingslashit( $itsec_globals['plugin_dir'] ) . 'core/class-itsec-lib.php' );
        }  
        
        $lockout_ids = $_POST['lockout_ids'];
        if (!is_array($lockout_ids))
            $lockout_ids = array();
        
        $type    = 'updated';
        $message = __( 'The selected lockouts have been cleared.', 'it-l10n-better-wp-security' );
        
        foreach ( $lockout_ids as $value ) {
                $wpdb->update(
                        $wpdb->base_prefix . 'itsec_lockouts',
                        array(
                                'lockout_active' => 0,
                        ),
                        array(
                                'lockout_id' => intval( $value ),
                        )
                );
        }

        ITSEC_Lib::clear_caches();

        if ( is_multisite() ) {

        } else {
            if (!function_exists('add_settings_error'))
                require_once (ABSPATH . '/wp-admin/includes/template.php');
            
            add_settings_error( 'itsec', esc_attr( 'settings_updated' ), $message, $type );
        }
        
        $site_status = array(
            'username_admin_exists' => username_exists( 'admin' ) ? 1 : 0,
            'user_id1_exists' => ITSEC_Lib::user_id_exists( 1 ) ? 1 : 0,
            'backup' => $this->backup_status(),
            'permalink_structure' => get_option( 'permalink_structure' ),
            'is_multisite' => is_multisite() ? 1 : 0,
            'users_can_register' => get_site_option( 'users_can_register' ) ? 1 : 0,
            'force_ssl_login' => (defined( 'FORCE_SSL_LOGIN' ) && FORCE_SSL_LOGIN === true) ? 1 : 0,
            'force_ssl_admin' => (defined( 'FORCE_SSL_ADMIN' ) && FORCE_SSL_ADMIN === true) ? 1 : 0,
            'server_nginx' => (ITSEC_Lib::get_server() == 'nginx') ? 1 : 0,
            'lockouts_host' => $this->get_lockouts( 'host', true ),
            'lockouts_user' => $this->get_lockouts( 'user', true ),
            'lockouts_username' => $this->get_lockouts( 'username', true )
        );
        
        return array('result' => 'success',
                    'site_status' => $site_status                    
                );
    }
        
}

