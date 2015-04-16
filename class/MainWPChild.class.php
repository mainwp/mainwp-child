<?php

@ini_set('display_errors', false);
@error_reporting(0);

define('MAINWP_CHILD_NR_OF_COMMENTS', 50);
define('MAINWP_CHILD_NR_OF_PAGES', 50);

include_once(ABSPATH . '/wp-admin/includes/file.php');
include_once(ABSPATH . '/wp-admin/includes/plugin.php');

class MainWPChild
{
    private $version = '2.0.12';
    private $update_version = '1.0';

    private $callableFunctions = array(
        'stats' => 'getSiteStats',
        'upgrade' => 'upgradeWP',
        'newpost' => 'newPost',
        'deactivate' => 'deactivate',
        'newuser' => 'newUser',
        'newadminpassword' => 'newAdminPassword',
        'installplugintheme' => 'installPluginTheme',
        'upgradeplugintheme' => 'upgradePluginTheme',
        'backup' => 'backup',
        'backup_checkpid' => 'backup_checkpid',
        'cloneinfo' => 'cloneinfo',
        'security' => 'getSecurityStats',
        'securityFix' => 'doSecurityFix',
        'securityUnFix' => 'doSecurityUnFix',
        'post_action' => 'post_action',
        'get_all_posts' => 'get_all_posts',
        'comment_action' => 'comment_action',
        'comment_bulk_action' => 'comment_bulk_action',
        'get_all_comments' => 'get_all_comments',
        'get_all_themes' => 'get_all_themes',
        'theme_action' => 'theme_action',
        'get_all_plugins' => 'get_all_plugins',
        'plugin_action' => 'plugin_action',
        'get_all_pages' => 'get_all_pages',
        'get_all_users' => 'get_all_users',
        'user_action' => 'user_action',
        'search_users' => 'search_users',
        'get_terms' => 'get_terms',
        'set_terms' => 'set_terms',
        'insert_comment' => 'insert_comment',
        'get_post_meta' => 'get_post_meta',
        'get_total_ezine_post' => 'get_total_ezine_post',
        'get_next_time_to_post' => 'get_next_time_to_post',
        'cancel_scheduled_post' => 'cancel_scheduled_post',
        'serverInformation' => 'serverInformation',
        'maintenance_site' => 'maintenance_site',
        'keyword_links_action' => 'keyword_links_action',
        'branding_child_plugin' => 'branding_child_plugin',
        'code_snippet' => 'code_snippet',
        'uploader_action' => 'uploader_action',
        'wordpress_seo' => 'wordpress_seo',
        'client_report' => 'client_report',
        'createBackupPoll' => 'backupPoll',
        'page_speed' => 'page_speed',
        'woo_com_status' => 'woo_com_status',
        'heatmaps' => 'heatmaps',
        'links_checker' => 'links_checker',
        'wordfence' => 'wordfence',
        'delete_backup' => 'delete_backup',
        'update_values' => 'update_values',
        'ithemes' => 'ithemes',        
        'updraftplus' => 'updraftplus'        
    );

    private $FTP_ERROR = 'Failed, please add FTP details for automatic upgrades.';

    private $callableFunctionsNoAuth = array(
        'stats' => 'getSiteStatsNoAuth'
    );

    private $posts_where_suffix;
    private $comments_and_clauses;
    private $plugin_slug;
    private $plugin_dir;
    private $slug;
    private $maxHistory = 5;

    private $filterFunction = null;
    private $branding = "MainWP";
    private $branding_robust = "MainWP";

    public function __construct($plugin_file)
    {			
        $this->update();

        $this->filterFunction = create_function( '$a', 'if ($a == null) { return false; } return $a;' );
        $this->plugin_dir = dirname($plugin_file);
        $this->plugin_slug = plugin_basename($plugin_file);
        list ($t1, $t2) = explode('/', $this->plugin_slug);
        $this->slug = str_replace('.php', '', $t2);
       
        $this->posts_where_suffix = '';
        $this->comments_and_clauses = '';
        add_action('template_redirect', array($this, 'template_redirect'));
        add_action('init', array(&$this, 'parse_init'));
        add_action('admin_menu', array(&$this, 'admin_menu'));
        add_action('admin_init', array(&$this, 'admin_init'));
        add_action('init', array(&$this, 'localization'));        
        $this->checkOtherAuth();
		
        MainWPClone::init();
        MainWPChildServerInformation::init();  
        MainWPClientReport::init();
        $this->run_saved_snippets();

        if (!get_option('mainwp_child_pubkey'))
            MainWPHelper::update_option('mainwp_child_branding_disconnected', 'yes');

        $branding_robust = true;
        $cancelled_branding = (get_option('mainwp_child_branding_disconnected') === 'yes') && !get_option('mainwp_branding_preserve_branding');

        if ($branding_robust && !$cancelled_branding) {
            $branding_header = get_option('mainwp_branding_plugin_header');
            if (is_array($branding_header) && isset($branding_header['name']) && !empty($branding_header['name'])) {
                $this->branding_robust = stripslashes($branding_header["name"]);
            }
        }
        add_action( 'admin_notices', array(&$this, 'admin_notice'));
        add_filter('plugin_row_meta', array(&$this, 'plugin_row_meta'), 10, 2);
    }

    function update()
    {
        $update_version = get_option('mainwp_child_update_version');

        if ($update_version == $this->update_version) return;

        if ($update_version === false)
        {
            $options = array('mainwp_child_legacy', 'mainwp_child_auth', 'mainwp_child_uniqueId', 'mainwp_child_onetime_htaccess', 'mainwp_child_htaccess_set', 'mainwp_child_fix_htaccess', 'mainwp_child_pubkey', 'mainwp_child_server', 'mainwp_child_nonce', 'mainwp_child_nossl', 'mainwp_child_nossl_key', 'mainwp_child_remove_wp_version', 'mainwp_child_remove_rsd', 'mainwp_child_remove_wlw', 'mainwp_child_remove_core_updates', 'mainwp_child_remove_plugin_updates', 'mainwp_child_remove_theme_updates', 'mainwp_child_remove_php_reporting', 'mainwp_child_remove_scripts_version', 'mainwp_child_remove_styles_version', 'mainwp_child_remove_readme', 'heatMapEnabled', 'mainwp_child_clone_sites', 'mainwp_child_pluginDir', 'mainwp_premium_updates', 'mainwp_child_activated_once', 'mainwp_maintenance_opt_alert_404', 'mainwp_maintenance_opt_alert_404_email', 'mainwp_ext_code_snippets', 'mainwp_ext_snippets_enabled', 'mainwp_temp_clone_plugins', 'mainwp_temp_clone_themes', 'mainwp_child_click_data', 'mainwp_child_clone_from_server_last_folder', 'mainwp_child_clone_permalink', 'mainwp_child_restore_permalink', 'mainwp_keyword_links_htaccess_set', 'mainwp_kwl_options', 'mainwp_kwl_keyword_links', 'mainwp_kwl_click_statistic_data', 'mainwp_kwl_statistic_data_', 'mainwp_kwl_enable_statistic', 'mainwpKeywordLinks', 'mainwp_branding_ext_enabled', 'mainwp_branding_plugin_header', 'mainwp_branding_support_email', 'mainwp_branding_support_message', 'mainwp_branding_remove_restore', 'mainwp_branding_remove_setting', 'mainwp_branding_remove_wp_tools', 'mainwp_branding_remove_wp_setting', 'mainwp_branding_remove_permalink', 'mainwp_branding_button_contact_label', 'mainwp_branding_send_email_message', 'mainwp_branding_message_return_sender', 'mainwp_branding_submit_button_title', 'mainwp_branding_disable_wp_branding', 'mainwp_branding_extra_settings', 'mainwp_branding_child_hide', 'mainwp_branding_show_support', 'mainwp_branding_disable_change');
            foreach ($options as $option)
            {
                MainWPHelper::fix_option($option);
            }
        }

        MainWPHelper::update_option('mainwp_child_update_version', $this->update_version);
    }

    public function admin_notice()
    {
        //Admin Notice...
        if (is_plugin_active('mainwp-child/mainwp-child.php')) {
            if (!get_option('mainwp_child_pubkey'))
            {
                $child_name = ($this->branding_robust === "MainWP") ? "MainWP Child" : $this->branding_robust;
                $msg = '<div class="postbox" style="padding-left: 1em; padding-right: 1em; margin-top: 4em;"><p style="text-align: center; background: #dd3d36; color: #fff; font-size: 22px; font-weight: bold;">Attention!</p>' .
                      '<p style="font-size: 16px;">Please add this site to your ' . $this->branding_robust . ' Dashboard <b>NOW</b> or deactivate the ' . $child_name . ' plugin until you are ready to do so to avoid unexpected security issues.</p>' ;                
                if (!MainWPChildBranding::is_branding()) 
                    $msg .= '<p>You can also turn on the Unique Security ID option in <a href="admin.php?page=mainwp_child_tab">' . $this->branding_robust . ' settings</a> if you would like extra security and additional time to add this site to your Dashboard.   Find out more in this Help Doc <a href="http://docs.mainwp.com/how-do-i-use-the-child-unique-security-id/" target="_blank">How do I use the Child Unique Security ID?</a></p>';                      
                $msg .= '</div>';
                echo $msg;      
            }
        }

        MainWPChildServerInformation::showWarnings();
    }

    public function localization()
    {
        load_plugin_textdomain('mainwp-child', false, dirname(dirname(plugin_basename(__FILE__))) . '/languages/');
    }

    function checkOtherAuth()
    {
        $auths = get_option('mainwp_child_auth');

        if (!$auths)
        {
            $auths = array();
        }

        if (!isset($auths['last']) || $auths['last'] < mktime(0, 0, 0, date("m"), date("d"), date("Y")))
        {
            //Generate code for today..
            for ($i = 0; $i < $this->maxHistory; $i++)
            {
                if (!isset($auths[$i + 1])) continue;

                $auths[$i] = $auths[$i + 1];
            }
            $newI = $this->maxHistory + 1;
            while (isset($auths[$newI])) unset($auths[$newI++]);
            $auths[$this->maxHistory] = md5(MainWPHelper::randString(14));
            $auths['last'] = time();
            MainWPHelper::update_option('mainwp_child_auth', $auths);
        }
    }

    function isValidAuth($key)
    {
        $auths = get_option('mainwp_child_auth');
        if (!$auths) return false;
        for ($i = 0; $i <= $this->maxHistory; $i++)
        {
            if (isset($auths[$i]) && ($auths[$i] == $key)) return true;
        }

        return false;
    }
    
    function template_redirect(){
        if (get_option('mainwp_maintenance_opt_alert_404') == 1) {
            $this->maintenance_alert_404();
        }
    }


    public function plugin_row_meta($plugin_meta, $plugin_file)
    {
        if ($this->plugin_slug != $plugin_file) return $plugin_meta;
        return apply_filters("mainwp_child_plugin_row_meta", $plugin_meta, $plugin_file, $this->plugin_slug);
    }

    function admin_menu()
    {
        $cancelled_branding = (get_option('mainwp_child_branding_disconnected') === 'yes') && !get_option('mainwp_branding_preserve_branding');

        if (get_option('mainwp_branding_remove_wp_tools') && !$cancelled_branding) {
            remove_menu_page( 'tools.php' );                            
            $pos = stripos($_SERVER['REQUEST_URI'], 'tools.php') ||
                    stripos($_SERVER['REQUEST_URI'], 'import.php') ||
                    stripos($_SERVER['REQUEST_URI'], 'export.php');
            if ($pos !== false)
                wp_redirect(get_option('siteurl') . '/wp-admin/index.php');  
        }
        // if preserve branding do not remove menus
        if (get_option('mainwp_branding_remove_wp_setting') && !$cancelled_branding) {
            remove_menu_page( 'options-general.php' );              
            $pos = stripos($_SERVER['REQUEST_URI'], 'options-general.php') || 
                    stripos($_SERVER['REQUEST_URI'], 'options-writing.php') || 
                    stripos($_SERVER['REQUEST_URI'], 'options-reading.php') || 
                    stripos($_SERVER['REQUEST_URI'], 'options-discussion.php') || 
                    stripos($_SERVER['REQUEST_URI'], 'options-media.php') || 
                    stripos($_SERVER['REQUEST_URI'], 'options-permalink.php');
            if ($pos !== false) {
                wp_redirect(get_option('siteurl') . '/wp-admin/index.php'); 
                exit();
            }
        }

        if (get_option('mainwp_branding_remove_permalink') && !$cancelled_branding) {
            remove_submenu_page('options-general.php', 'options-permalink.php');  
            $pos = stripos($_SERVER['REQUEST_URI'], 'options-permalink.php');            
            if ($pos !== false) {
                wp_redirect(get_option('siteurl') . '/wp-admin/index.php');             
                exit();
            }
        }
        
        $remove_all_child_menu = false;        
        if (get_option('mainwp_branding_remove_setting') && get_option('mainwp_branding_remove_restore')  && get_option('mainwp_branding_remove_server_info')) {
            $remove_all_child_menu = true;
        }

        $restorePage = add_submenu_page('import.php', $this->branding . ' Restore', $this->branding . ' Restore', 'read', 'mainwp-child-restore', array('MainWPClone', 'renderRestore'));
        add_action('admin_print_scripts-'.$restorePage, array('MainWPClone', 'print_scripts'));
        
        
        $sitesToClone = get_option('mainwp_child_clone_sites');
        $mainwp_child_menu_slug = "mainwp_child_tab";
        
        if (!$cancelled_branding) {
            if (get_option('mainwp_branding_remove_setting')) {
                if (get_option('mainwp_branding_remove_server_info')) {
                    if ($sitesToClone != '0')
                    {
                        $mainwp_child_menu_slug = "MainWPClone";
                    } 
                    else
                    {
                        $mainwp_child_menu_slug = "MainWPRestore";
                    }
                        
                } else {
                    $mainwp_child_menu_slug = "MainWPChildServerInformation";
                }                                
            }
        }
        // if preserve branding do not hide menus
        // hide menu
        if ((!$remove_all_child_menu && get_option('mainwp_branding_child_hide') !== 'T') || $cancelled_branding) {
            $branding_header = get_option('mainwp_branding_plugin_header');

            if ((is_array($branding_header) && !empty($branding_header['name'])) && !$cancelled_branding) {
                $this->branding = $child_menu_name = stripslashes($branding_header['name']);
                $child_menu_icon = "";
            } else {
                $child_menu_name = "MainWP Child";
                $child_menu_icon = 'data:image/png+xml;base64,' . base64_encode(file_get_contents($this->plugin_dir . '/images/mainwpicon.png'));
            }
            
            add_menu_page($child_menu_name, $child_menu_name, 'read', $mainwp_child_menu_slug, false, $child_menu_icon, '80.00001');

            if (!get_option('mainwp_branding_remove_setting') || $cancelled_branding)
            {
                add_submenu_page('mainwp_child_tab', 'MainWPSettings',  __($this->branding . ' Settings','mainwp-child') , 'manage_options', 'mainwp_child_tab', array(&$this, 'settings'));
            }

            if (!get_option('mainwp_branding_remove_server_info') || $cancelled_branding)
            {
                add_submenu_page($mainwp_child_menu_slug, 'MainWPSettings',  __($this->branding . ' Server Information','mainwp-child') , 'manage_options', 'MainWPChildServerInformation', array('MainWPChildServerInformation', 'renderPage'));
            }
            
            if (!get_option('mainwp_branding_remove_restore') || $cancelled_branding)
            {                
                if ($sitesToClone != '0')
                {
                    MainWPClone::init_menu($this->branding, $mainwp_child_menu_slug);
                }
                else
                {
                    MainWPClone::init_restore_menu($this->branding, $mainwp_child_menu_slug);
                }
            }
        }
    }

    function admin_init(){
           MainWPChildBranding::admin_init();
    }

    function settings()
    {
        if (isset($_POST['submit']))
        {
            if (isset($_POST['requireUniqueSecurityId']))
            {
                MainWPHelper::update_option('mainwp_child_uniqueId', MainWPHelper::randString(8));                
            }
            else
            {
                MainWPHelper::update_option('mainwp_child_uniqueId', '');                
            }
        }
        ?>
        <div class="wrap">
    <div id="icon-options-general" class="icon32"><br></div><h2><?php _e($this->branding . ' Settings','mainwp-child'); ?></h2>
    <div class="postbox" style="margin-top: 6em;">
        <h3 class="hndle" style="margin: 0 !important; padding: .5em 1em;"><span><?php _e('Connection Settings','mainwp-child'); ?></span></h3>
        <div class="inside">
        <form method="post" action="">
        <div class="howto"><?php _e('The Unique Security ID adds additional protection between the Child plugin and your Main Dashboard. The Unique Security ID will need to match when being added to the Main Dashboard. This is additional security and should not be needed in most situations.','mainwp-child'); ?></div>
        <div style="margin: 1em 0 4em 0;">
        <input name="requireUniqueSecurityId" type="checkbox" id="requireUniqueSecurityId" <?php if (get_option('mainwp_child_uniqueId') != '')
                    {
                        echo 'checked';
                    } ?> /> <label for="requireUniqueSecurityId" style="font-size: 15px;"><?php _e('Require Unique Security ID','mainwp-child'); ?></label>
        </div>
        <div>
            <?php if (get_option('mainwp_child_uniqueId') != '')
                {
                    echo '<span style="border: 1px dashed #e5e5e5; background: #fafafa; font-size: 24px; padding: 1em 2em;">'.__('Your Unique Security ID is:','mainwp-child') . ' <span style="font-weight: bold; color: #7fb100;">' . get_option('mainwp_child_uniqueId') . '</span></span>';
                } ?>
        </div>
        <p class="submit" style="margin-top: 4em;">
            <input type="submit" name="submit" id="submit" class="button button-primary" value="<?php _e('Save Changes','mainwp-child'); ?>">
        </p>
    </form>
    </div>
    </div>
    </div>
    <?php
    }

    function mod_rewrite_rules($pRules)
    {

        $home_root = parse_url(home_url());
        if (isset($home_root['path']))
            $home_root = trailingslashit($home_root['path']);
        else
            $home_root = '/';

        $rules = "<IfModule mod_rewrite.c>\n";
        $rules .= "RewriteEngine On\n";
        $rules .= "RewriteBase $home_root\n";

        //add in the rules that don't redirect to WP's index.php (and thus shouldn't be handled by WP at all)
        foreach ($pRules as $match => $query)
        {
            // Apache 1.3 does not support the reluctant (non-greedy) modifier.
            $match = str_replace('.+?', '.+', $match);

            $rules .= 'RewriteRule ^' . $match . ' ' . $home_root . $query . " [QSA,L]\n";
        }

        $rules .= "</IfModule>\n";

        return $rules;
    }

    function update_htaccess($hard = false)
    {
        if (defined('DOING_CRON') && DOING_CRON) return;

        if ((get_option('mainwp_child_pluginDir') == 'hidden') && ($hard || (get_option('mainwp_child_htaccess_set') != 'yes')))
        {
            include_once(ABSPATH . '/wp-admin/includes/misc.php');

            $snPluginDir = basename($this->plugin_dir);

            $rules = null;
            if ((get_option('heatMapsIndividualOverrideSetting') != '1' && get_option('heatMapEnabled') !== '0') || 
                (get_option('heatMapsIndividualOverrideSetting') == '1' && get_option('heatMapsIndividualDisable') != '1')
                )
            {
                //Heatmap enabled
                //Make the plugin invisible, except heatmap
                $rules = $this->mod_rewrite_rules(array('wp-content/plugins/' . $snPluginDir . '/([^js\/]*)$' => 'wp-content/plugins/THIS_PLUGIN_DOES_NOT_EXIST'));
            }
            else
            {
                //Make the plugin invisible
                $rules = $this->mod_rewrite_rules(array('wp-content/plugins/' . $snPluginDir . '/(.*)$' => 'wp-content/plugins/THIS_PLUGIN_DOES_NOT_EXIST'));
            }

            $home_path = ABSPATH;
            $htaccess_file = $home_path . '.htaccess';
            if (function_exists('save_mod_rewrite_rules'))
            {
                $rules = explode("\n", $rules);

//                $ch = @fopen($htaccess_file,'w');
//                if (@flock($ch, LOCK_EX))
//                {
                insert_with_markers($htaccess_file, 'MainWP', $rules);
//                }
//                @flock($ch, LOCK_UN);
//                @fclose($ch);

                if (get_option('mainwp_child_onetime_htaccess') === false)
                {
                    MainWPHelper::update_option('mainwp_child_onetime_htaccess', true);
                }
            }
            MainWPHelper::update_option('mainwp_child_htaccess_set', 'yes');
        }
        else if ($hard)
        {
            include_once(ABSPATH . '/wp-admin/includes/misc.php');

            $home_path = ABSPATH;
            $htaccess_file = $home_path . '.htaccess';
            if (function_exists('save_mod_rewrite_rules'))
            {
                $rules = explode("\n", '');

//                $ch = @fopen($htaccess_file,'w');
//                if (@flock($ch, LOCK_EX))
//                {
                insert_with_markers($htaccess_file, 'MainWP', $rules);
//                }
//                @flock($ch, LOCK_UN);
//                @fclose($ch);

                if (get_option('mainwp_child_onetime_htaccess') === false)
                {
                    MainWPHelper::update_option('mainwp_child_onetime_htaccess', true);
                }
            }
        }
    }

    function parse_init()
    {
        if (isset($_REQUEST['cloneFunc']))
        {
            if (!isset($_REQUEST['key'])) return;
            if (!isset($_REQUEST['f']) || ($_REQUEST['f'] == '')) return;
            if (!$this->isValidAuth($_REQUEST['key'])) return;

            if ($_REQUEST['cloneFunc'] == 'dl')
            {
                $this->uploadFile($_REQUEST['f']);
                exit;
            }
            else if ($_POST['cloneFunc'] == 'deleteCloneBackup')
            {
                $dirs = MainWPHelper::getMainWPDir('backup');
                $backupdir = $dirs[0];
                $result = glob($backupdir . $_POST['f']);
                if (count($result) == 0) return;

                @unlink($result[0]);
                MainWPHelper::write(array('result' => 'ok'));
            }
            else if ($_POST['cloneFunc'] == 'createCloneBackupPoll')
            {
                $dirs = MainWPHelper::getMainWPDir('backup');
                $backupdir = $dirs[0];
                $result = glob($backupdir . 'backup-'.$_POST['f'].'-*');
                $archiveFile = false;
                foreach ($result as $file)
                {
                    if (MainWPHelper::isArchive($file, 'backup-'.$_POST['f'].'-'))
                    {
                        $archiveFile = $file;
                        break;
                    }
                }
                if ($archiveFile === false) return;

                MainWPHelper::write(array('size' => filesize($archiveFile)));
            }
            else if ($_POST['cloneFunc'] == 'createCloneBackup')
            {
                MainWPHelper::endSession();
                if (file_exists(WP_CONTENT_DIR . '/dbBackup.sql')) @unlink(WP_CONTENT_DIR . '/dbBackup.sql');
                if (file_exists(ABSPATH . 'clone/config.txt')) @unlink(ABSPATH . 'clone/config.txt');
                if (MainWPHelper::is_dir_empty(ABSPATH . 'clone')) @rmdir(ABSPATH . 'clone');

                $wpversion = $_POST['wpversion'];
                global $wp_version;
                $includeCoreFiles = ($wpversion != $wp_version);
                $excludes = (isset($_POST['exclude']) ? explode(',', $_POST['exclude']) : array());
                $excludes[] = str_replace(ABSPATH, '', WP_CONTENT_DIR) . '/uploads/mainwp';
                $uploadDir = MainWPHelper::getMainWPDir();
                $uploadDir = $uploadDir[0];
                $excludes[] = str_replace(ABSPATH, '', $uploadDir);
                $excludes[] = str_replace(ABSPATH, '', WP_CONTENT_DIR) . '/object-cache.php';
                if (!ini_get('safe_mode')) set_time_limit(6000);

                $newExcludes = array();
                foreach ($excludes as $exclude)
                {
                    $newExcludes[] = rtrim($exclude, '/');
                }

                $method = (!isset($_POST['zipmethod']) ? 'tar.gz' : $_POST['zipmethod']);
                if ($method == 'tar.gz' && !function_exists('gzopen')) $method = 'zip';

                $res = MainWPBackup::get()->createFullBackup($newExcludes, (isset($_POST['f']) ? $_POST['f'] : $_POST['file']), true, $includeCoreFiles, 0, false, false, false, false, $method);
                if (!$res)
                {
                    $information['backup'] = false;
                }
                else
                {
                    $information['backup'] = $res['file'];
                    $information['size'] = $res['filesize'];
                }
                
                //todo: RS: Remove this when the .18 is out
                $plugins = array();
                $dir = WP_CONTENT_DIR . '/plugins/';
                $fh = @opendir($dir);
                while ($entry = @readdir($fh))
                {
                    if (!is_dir($dir . $entry)) continue;
                    if (($entry == '.') || ($entry == '..')) continue;
                    $plugins[] = $entry;
                }
                @closedir($fh);
                $information['plugins'] = $plugins;

                $themes = array();
                $dir = WP_CONTENT_DIR . '/themes/';
                $fh = @opendir($dir);
                while ($entry = @readdir($fh))
                {
                    if (!is_dir($dir . $entry)) continue;
                    if (($entry == '.') || ($entry == '..')) continue;
                    $themes[] = $entry;
                }
                @closedir($fh);
                $information['themes'] = $themes;

                MainWPHelper::write($information);
            }
        }

        global $wp_rewrite;
        $snPluginDir = basename($this->plugin_dir);
        if (isset($wp_rewrite->non_wp_rules['wp-content/plugins/' . $snPluginDir . '/([^js\/]*)$']))
        {
            unset($wp_rewrite->non_wp_rules['wp-content/plugins/' . $snPluginDir . '/([^js\/]*)$']);
        }

        if (isset($wp_rewrite->non_wp_rules['wp-content/plugins/' . $snPluginDir . '/(.*)$']))
        {
            unset($wp_rewrite->non_wp_rules['wp-content/plugins/' . $snPluginDir . '/(.*)$']);
        }

        if (get_option('mainwp_child_fix_htaccess') === false)
        {
            include_once(ABSPATH . '/wp-admin/includes/misc.php');

            $wp_rewrite->flush_rules();
            MainWPHelper::update_option('mainwp_child_fix_htaccess', 'yes');
        }

        $this->update_htaccess();

        global $current_user; //wp variable

        //Login the user
        if (isset($_REQUEST['login_required']) && ($_REQUEST['login_required'] == 1) && isset($_REQUEST['user']))
        {
            $username = rawurldecode($_REQUEST['user']);
            if (is_user_logged_in())
            {
                global $current_user;
                if ($current_user->wp_user_level != 10 && (!isset($current_user->user_level) || $current_user->user_level != 10) && !current_user_can('level_10'))
                {
                    do_action('wp_logout');
                }
            }

            $signature = rawurldecode(isset($_REQUEST['mainwpsignature']) ? $_REQUEST['mainwpsignature'] : '');
            $file = '';
            if (isset($_REQUEST['f']))
            {
                $file = $_REQUEST['f'];
            }
            else if (isset($_REQUEST['file']))
            {
                $file = $_REQUEST['file'];
            }
            else if (isset($_REQUEST['fdl']))
            {
                $file = $_REQUEST['fdl'];
            }

            $auth = $this->auth($signature, rawurldecode((isset($_REQUEST['where']) ? $_REQUEST['where'] : $file)), isset($_REQUEST['nonce']) ? $_REQUEST['nonce'] : '', isset($_REQUEST['nossl']) ? $_REQUEST['nossl'] : 0);
            if (!$auth) return;

            if (!is_user_logged_in() || $username != $current_user->user_login)
            {
                if (!$this->login($username))
                {
                    return;
                }

                global $current_user;
                if ($current_user->wp_user_level != 10 && (!isset($current_user->user_level) || $current_user->user_level != 10) && !current_user_can('level_10'))
                {
                    do_action('wp_logout');
                    return;
                }
            }

            if (isset($_REQUEST['fdl']))
            {
                if (stristr($_REQUEST['fdl'], '..'))
                {
                    return;
                }

                $this->uploadFile($_REQUEST['fdl'], isset($_REQUEST['foffset']) ? $_REQUEST['foffset'] : 0);
                exit;
            }

            $where = isset($_REQUEST['where']) ? $_REQUEST['where'] : '';
            if (isset($_POST['f']) || isset($_POST['file']))
            {
                $file = '';
                if (isset($_POST['f']))
                {
                    $file = $_POST['f'];
                }
                else if (isset($_POST['file']))
                {
                    $file = $_POST['file'];
                }

                $where = 'admin.php?page=mainwp-child-restore';
                if (session_id() == '') session_start();
                $_SESSION['file'] = $file;
                $_SESSION['size'] = $_POST['size'];
            }
            
            $open_location = isset($_REQUEST['open_location']) ? $_REQUEST['open_location'] : '';  
            if (!empty($open_location)) {
                $open_location = base64_decode ($open_location);
                $_vars = MainWPHelper::parse_query($open_location);
                $_path = parse_url($open_location, PHP_URL_PATH);

                if (isset($_vars['_mwpNoneName']) && isset($_vars['_mwpNoneValue'])) {
                    $_vars[$_vars['_mwpNoneName']] = wp_create_nonce($_vars['_mwpNoneValue']);
                    unset($_vars['_mwpNoneName']);
                    unset($_vars['_mwpNoneValue']);
                    $open_location = "/wp-admin/" . $_path . "?" . http_build_query($_vars);
                } else {
                    if (strpos($open_location, "nonce=child_temp_nonce") !== false)
                        $open_location = str_replace ("nonce=child_temp_nonce", "nonce=" . wp_create_nonce('wp-ajax'), $open_location);
                }
                wp_redirect(site_url() . $open_location);
                exit();
            }
            
            add_filter('the_content', array(MainWPKeywordLinks::Instance(), 'filter_content'), 100, 2);            
            wp_redirect(admin_url($where));
            exit();
        }


        remove_action('admin_init', 'send_frame_options_header');
        remove_action('login_init', 'send_frame_options_header');

        // Call Heatmap
        if (get_option('heatMapExtensionLoaded') == 'yes') {
            if ((get_option('heatMapsIndividualOverrideSetting') != '1' && get_option('heatMapEnabled') !== '0') ||
                (get_option('heatMapsIndividualOverrideSetting') == '1' && get_option('heatMapsIndividualDisable') != '1')
                )
                 new MainWPHeatmapTracker();
        }
        /**
         * Security
         */
        MainWPSecurity::fixAll();
		
        if (isset($_GET['mainwptest']))
        {
//            error_reporting(E_ALL);
//            ini_set('display_errors', TRUE);
//            ini_set('display_startup_errors', TRUE);
//            echo '<pre>';
//            $start = microtime(true);

//            phpinfo();
//            $_POST['type'] = 'full';
//            $_POST['ext'] = 'tar.gz';
//            $_POST['pid'] = time();
//            print_r($this->backup(false));

//            $stop = microtime(true);
//            die(($stop - $start) . 's</pre>');
        }

        //Register does not require auth, so we register here..
        if (isset($_POST['function']) && $_POST['function'] == 'register')
        {
            $this->registerSite();
        }

        $auth = $this->auth(isset($_POST['mainwpsignature']) ? $_POST['mainwpsignature'] : '', isset($_POST['function']) ? $_POST['function'] : '', isset($_POST['nonce']) ? $_POST['nonce'] : '', isset($_POST['nossl']) ? $_POST['nossl'] : 0);

        if (!$auth && isset($_POST['mainwpsignature']))
        {
            MainWPHelper::error(__('Authentication failed. Reinstall MainWP plugin please','mainwp-child'));
        }

        //Check if the user exists & is an administrator
        if (isset($_POST['function']) && isset($_POST['user']))
        {
            $user = get_user_by('login', $_POST['user']);
            if (!$user)
            {
                MainWPHelper::error(__('No such user','mainwp-child'));
            }

            if ($user->wp_user_level != 10 && (!isset($user->user_level) || $user->user_level != 10) && !current_user_can('level_10'))
            {
                MainWPHelper::error(__('User is not an administrator','mainwp-child'));
            }

            $this->login($_REQUEST['user']);
        }

        if (isset($_POST['function']) && $_POST['function'] == 'visitPermalink')
        {
            if ($auth)
            {
                if ($this->login($_POST['user'], true))
                {
                    return;
                }
                else
                {
                    exit();
                }
            }
        }
		
        //Redirect to the admin part if needed
        if ($auth && isset($_POST['admin']) && $_POST['admin'] == 1)
        {
            wp_redirect(get_option('siteurl') . '/wp-admin/');
            die();
        }

        new MainWPChildIThemesSecurity();

        MainWPChildUpdraftplusBackups::Instance()->updraftplus_init();                    
      
        //Call the function required
        if (isset($_POST['function']) && isset($this->callableFunctions[$_POST['function']]))
        {
            call_user_func(array($this, ($auth ? $this->callableFunctions[$_POST['function']]
                    : $this->callableFunctionsNoAuth[$_POST['function']])));
        }
        if (get_option('mainwpKeywordLinks') == 1) {
            new MainWPKeywordLinks();
            if (!is_admin()) {
                add_filter('the_content', array(MainWPKeywordLinks::Instance(), 'filter_content'), 100);
            }
            MainWPKeywordLinks::Instance()->update_htaccess(); // if needed
            MainWPKeywordLinks::Instance()->redirect_cloak();
        }
        else if (get_option('mainwp_keyword_links_htaccess_set') == 'yes')
        {
            MainWPKeywordLinks::clear_htaccess(); // force clear
        }
		
        // Branding extension
        MainWPChildBranding::Instance()->branding_init();
        MainWPClientReport::Instance()->creport_init();
        MainWPChildPagespeed::Instance()->init();        
        MainWPChildLinksChecker::Instance()->init();
        MainWPChildWordfence::Instance()->wordfence_init();        
        MainWPChildIThemesSecurity::Instance()->ithemes_init();
    }

    function default_option_active_plugins($default)
    {
        if (!is_array($default)) $default = array();
        if (!in_array('managewp/init.php', $default)) $default[] = 'managewp/init.php';

        return $default;
    }

    function auth($signature, $func, $nonce, $pNossl)
    {
        if (!isset($signature) || !isset($func) || (!get_option('mainwp_child_pubkey') && !get_option('mainwp_child_nossl_key')))
        {
            $auth = false;
        }
        else
        {
            $nossl = get_option('mainwp_child_nossl');
            $serverNoSsl = (isset($pNossl) && $pNossl == 1);

            if (($nossl == 1) || $serverNoSsl)
            {
                $auth = (md5($func . $nonce . get_option('mainwp_child_nossl_key')) == base64_decode ($signature));
            }
            else
            {                
                $auth = openssl_verify($func . $nonce, base64_decode ($signature), base64_decode (get_option('mainwp_child_pubkey')));
            }
        }

        return $auth;
    }

    //Login..
    function login($username, $doAction = false)
    {
        global $current_user;

        //Logout if required
        if (isset($current_user->user_login))
        {
            if ($current_user->user_login == $username)
            {
                wp_set_auth_cookie($current_user->ID);

                return true;
            }

            do_action('wp_logout');
        }

        $user = get_user_by('login', $username);
        if ($user)
        { //If user exists, login
//            wp_set_current_user($user->ID, $user->user_login);
//            wp_set_auth_cookie($user->ID);

            wp_set_current_user($user->ID);
            wp_set_auth_cookie($user->ID);

            if ($doAction) do_action('wp_login', $user->user_login);
            return (is_user_logged_in() && $current_user->user_login == $username);
        }
        return false;
    }

    function noSSLFilterFunction($r, $url)
    {
        $r['sslverify'] = false;

        return $r;
    }

    public function http_request_reject_unsafe_urls($r, $url)
    {
        $r['reject_unsafe_urls'] = false;

        return $r;
    }

    /**
     * Functions to support core functionality
     */
    function installPluginTheme()
    {
        $wp_filesystem = $this->getWPFilesystem();

        if (!isset($_POST['type']) || !isset($_POST['url']) || ($_POST['type'] != 'plugin' && $_POST['type'] != 'theme') || $_POST['url'] == '')
        {
            MainWPHelper::error(__('Bad request.','mainwp-child'));
        }
//        if (file_exists(ABSPATH . '/wp-admin/includes/deprecated.php')) include_once(ABSPATH . '/wp-admin/includes/deprecated.php');
        if (file_exists(ABSPATH . '/wp-admin/includes/screen.php')) include_once(ABSPATH . '/wp-admin/includes/screen.php');
        include_once(ABSPATH . '/wp-admin/includes/template.php');
        include_once(ABSPATH . '/wp-admin/includes/misc.php');
        include_once(ABSPATH . '/wp-admin/includes/class-wp-upgrader.php');
        include_once(ABSPATH . '/wp-admin/includes/plugin.php');

        $urlgot = json_decode(stripslashes($_POST['url']));

        $urls = array();
        if (!is_array($urlgot))
        {
            $urls[] = $urlgot;
        }
        else
        {
            $urls = $urlgot;
        }

        $result = array();
        foreach ($urls as $url)
        {
            $installer = new WP_Upgrader();
            //@see wp-admin/includes/class-wp-upgrader.php
            if (isset($_POST['sslVerify']) && $_POST['sslVerify'] == 0)
            {
                add_filter( 'http_request_args', array(&$this, 'noSSLFilterFunction'), 99, 2);
            }
            add_filter('http_request_args', array(&$this, 'http_request_reject_unsafe_urls'), 99, 2);

            $result = $installer->run(array(
                'package' => $url,
                'destination' => ($_POST['type'] == 'plugin' ? WP_PLUGIN_DIR
                        : WP_CONTENT_DIR . '/themes'),
                'clear_destination' => (isset($_POST['overwrite']) && $_POST['overwrite'] == true), //overwrite files?
                'clear_working' => true,
                'hook_extra' => array()
            ));

            remove_filter( 'http_request_args', array(&$this, 'http_request_reject_unsafe_urls') , 99, 2);
            if (isset($_POST['sslVerify']) && $_POST['sslVerify'] == 0)
            {
                remove_filter( 'http_request_args', array(&$this, 'noSSLFilterFunction') , 99);
            }
            if (is_wp_error($result))
            {
                $error = $result->get_error_codes();
                if (is_array($error))
                {
                    MainWPHelper::error(implode(', ', $error));
                }
                else
                {
                    MainWPHelper::error($error);
                }
            }

            if ($_POST['type'] == 'plugin' && isset($_POST['activatePlugin']) && $_POST['activatePlugin'] == 'yes')
            {
                $path = $result['destination'];
                $rslt = null;
                wp_cache_set('plugins', array(), 'plugins');
                foreach ($result['source_files'] as $srcFile)
                {
                    if (is_dir($path . $srcFile)) continue;

                    $thePlugin = get_plugin_data($path . $srcFile);
                    if ($thePlugin != null && $thePlugin != '' && $thePlugin['Name'] != '')
                    {
                        activate_plugin($path . $srcFile, '', false, true);
                    }
                }
            }
        }
        $information['installation'] = 'SUCCESS';
        $information['destination_name'] = $result['destination_name'];
        MainWPHelper::write($information);
    }

    //This will upgrade WP
    function upgradeWP()
    {
        global $wp_version;
        $wp_filesystem = $this->getWPFilesystem();

        $information = array();

        include_once(ABSPATH . '/wp-admin/includes/update.php');
        include_once(ABSPATH . '/wp-admin/includes/class-wp-upgrader.php');
//        if (file_exists(ABSPATH . '/wp-admin/includes/deprecated.php')) include_once(ABSPATH . '/wp-admin/includes/deprecated.php');
        if (file_exists(ABSPATH . '/wp-admin/includes/screen.php')) include_once(ABSPATH . '/wp-admin/includes/screen.php');
        if (file_exists(ABSPATH . '/wp-admin/includes/template.php')) include_once(ABSPATH . '/wp-admin/includes/template.php');
        include_once(ABSPATH . '/wp-admin/includes/file.php');
        include_once(ABSPATH . '/wp-admin/includes/misc.php');


        if ($this->filterFunction != null) add_filter( 'pre_site_transient_update_core', $this->filterFunction, 99 );
        if ($this->filterFunction != null) add_filter( 'pre_transient_update_core', $this->filterFunction, 99 );

        //Check for new versions
        @wp_version_check();

        $core_updates = get_core_updates();
        if (count($core_updates) > 0)
        {
            foreach ($core_updates as $core_update)
            {
                if ($core_update->response == 'latest')
                {
                    $information['upgrade'] = 'SUCCESS';
                }
                else if ($core_update->response == 'upgrade' && $core_update->locale == get_locale() && version_compare($wp_version, $core_update->current, '<='))
                {
                    //Upgrade!
                    $upgrade = false;
                    if (class_exists('Core_Upgrader'))
                    {
                        $core = new Core_Upgrader();
                        $upgrade = $core->upgrade($core_update);
                    }
                    //If this does not work - add code from /wp-admin/includes/class-wp-upgrader.php in the newer versions
                    //So users can upgrade older versions too.
                    //3rd option: 'wp_update_core'

                    if (!is_wp_error($upgrade))
                    {
                        $information['upgrade'] = 'SUCCESS';
                    }
                    else
                    {
                        $information['upgrade'] = 'WPERROR';
                    }
                    break;
                }
            }

            if (!isset($information['upgrade']))
            {
                foreach ($core_updates as $core_update)
                {
                     if ($core_update->response == 'upgrade' && version_compare($wp_version, $core_update->current, '<='))
                    {
                        //Upgrade!
                        $upgrade = false;
                        if (class_exists('Core_Upgrader'))
                        {
                            $core = new Core_Upgrader();
                            $upgrade = $core->upgrade($core_update);
                        }
                        //If this does not work - add code from /wp-admin/includes/class-wp-upgrader.php in the newer versions
                        //So users can upgrade older versions too.
                        //3rd option: 'wp_update_core'

                        if (!is_wp_error($upgrade))
                        {
                            $information['upgrade'] = 'SUCCESS';
                        }
                        else
                        {
                            $information['upgrade'] = 'WPERROR';
                        }
                        break;
                    }
                }
            }
        }
        else
        {
            $information['upgrade'] = 'NORESPONSE';
        }
        if ($this->filterFunction != null) remove_filter( 'pre_site_transient_update_core', $this->filterFunction, 99 );
        if ($this->filterFunction != null) remove_filter( 'pre_transient_update_core', $this->filterFunction, 99 );

        MainWPHelper::write($information);
    }

    /**
     * Expects $_POST['type'] == plugin/theme
     * $_POST['list'] == 'theme1,theme2' or 'plugin1,plugin2'
     */
    function upgradePluginTheme()
    {
        //Prevent disable/re-enable at upgrade
        define('DOING_CRON', true);

        include_once(ABSPATH . '/wp-admin/includes/class-wp-upgrader.php');
//        if (file_exists(ABSPATH . '/wp-admin/includes/deprecated.php')) include_once(ABSPATH . '/wp-admin/includes/deprecated.php');
        if (file_exists(ABSPATH . '/wp-admin/includes/screen.php')) include_once(ABSPATH . '/wp-admin/includes/screen.php');
        if (file_exists(ABSPATH . '/wp-admin/includes/template.php')) include_once(ABSPATH . '/wp-admin/includes/template.php');
        if (file_exists(ABSPATH . '/wp-admin/includes/misc.php')) include_once(ABSPATH . '/wp-admin/includes/misc.php');
        include_once(ABSPATH . '/wp-admin/includes/file.php');
        include_once(ABSPATH . '/wp-admin/includes/plugin.php');
        $information = array();
        $information['upgrades'] = array();
        $mwp_premium_updates_todo = array();
        $mwp_premium_updates_todo_slugs = array();
        if (isset($_POST['type']) && $_POST['type'] == 'plugin')
        {
            include_once(ABSPATH . '/wp-admin/includes/update.php');
            if ($this->filterFunction != null) add_filter( 'pre_site_transient_update_plugins', $this->filterFunction , 99);

            @wp_update_plugins();
            $information['plugin_updates'] = get_plugin_updates();

            $plugins = explode(',', urldecode($_POST['list']));
            $premiumPlugins = array();
            $premiumUpdates = get_option('mainwp_premium_updates');
            if (is_array($premiumUpdates))
            {
                $newPlugins = array();
                foreach ($plugins as $plugin)
                {
                    if (in_array($plugin, $premiumUpdates))
                    {
                        $premiumPlugins[] = $plugin;
                    }
                    else
                    {
                        $newPlugins[] = $plugin;
                    }
                }
                $plugins = $newPlugins;
            }
            if (count($plugins) > 0)
            {
                //@see wp-admin/update.php
                $upgrader = new Plugin_Upgrader(new Bulk_Plugin_Upgrader_Skin(compact('nonce', 'url')));
                $result = $upgrader->bulk_upgrade($plugins);
                if (!empty($result))
                {
                    foreach ($result as $plugin => $info)
                    {
                        if (empty($info))
                        {
                            $information['upgrades'][$plugin] = false;
                        }
                        else
                        {
                            $information['upgrades'][$plugin] = true;
                        }
                    }
                }
                else
                {
                    MainWPHelper::error(__('Bad request','mainwp-child'));
                }
            }
            if (count($premiumPlugins) > 0)
            {
                $mwp_premium_updates = apply_filters('mwp_premium_perform_update', array());
                if (is_array($mwp_premium_updates) && is_array($premiumPlugins))
                {
                    foreach ($premiumPlugins as $premiumPlugin)
                    {
                        foreach ($mwp_premium_updates as $key => $update)
                        {
                            $slug = (isset($update['slug']) ? $update['slug'] : $update['Name']);
                            if (strcmp($slug, $premiumPlugin) == 0)
                            {
                                $mwp_premium_updates_todo[$key] = $update;
                                $mwp_premium_updates_todo_slugs[] = $premiumPlugin;
                            }
                        }
                    }
                }
                unset($mwp_premium_updates);
                $premiumUpgrader = new Plugin_Upgrader(new Bulk_Plugin_Upgrader_Skin(compact('nonce', 'url')));
            }

            if (count($plugins) <= 0 && count($premiumPlugins) <= 0)
            {
                MainWPHelper::error(__('Bad request','mainwp-child'));
            }

            if ($this->filterFunction != null) remove_filter( 'pre_site_transient_update_plugins', $this->filterFunction , 99);
        }
        else if (isset($_POST['type']) && $_POST['type'] == 'theme')
        {
            include_once(ABSPATH . '/wp-admin/includes/update.php');
            if ($this->filterFunction != null) add_filter( 'pre_site_transient_update_themes', $this->filterFunction , 99);
            @wp_update_themes();
            include_once(ABSPATH . '/wp-admin/includes/theme.php');
            $information['theme_updates'] = $this->upgrade_get_theme_updates();
            $themes = explode(',', $_POST['list']);
            $premiumThemes = array();
            $premiumUpdates = get_option('mainwp_premium_updates');
            if (is_array($premiumUpdates))
            {
                $newThemes = array();
                foreach ($themes as $theme)
                {
                    if (in_array($theme, $premiumUpdates))
                    {
                        $premiumThemes[] = $theme;
                    }
                    else
                    {
                        $newThemes[] = $theme;
                    }
                }
                $themes = $newThemes;
            }

            if (count($themes) > 0)
            {
                //@see wp-admin/update.php
                $upgrader = new Theme_Upgrader(new Bulk_Theme_Upgrader_Skin(compact('nonce', 'url')));
                $result = $upgrader->bulk_upgrade($themes);
                if (!empty($result))
                {
                    foreach ($result as $theme => $info)
                    {
                        if (empty($info))
                        {
                            $information['upgrades'][$theme] = false;
                        }
                        else
                        {
                            $information['upgrades'][$theme] = true;
                        }
                    }
                }
                else
                {
                    MainWPHelper::error(__('Bad request','mainwp-child'));
                }
            }
            if (count($premiumThemes) > 0)
            {
                $mwp_premium_updates = apply_filters('mwp_premium_perform_update', array());
                $mwp_premium_updates_todo = array();
                $mwp_premium_updates_todo_slugs = array();
                if (is_array($premiumThemes) && is_array($mwp_premium_updates))
                {
                    foreach ($premiumThemes as $premiumTheme)
                    {
                        foreach ($mwp_premium_updates as $key => $update)
                        {
                            $slug = (isset($update['slug']) ? $update['slug'] : $update['Name']);
                            if (strcmp($slug, $premiumTheme) == 0)
                            {
                                $mwp_premium_updates_todo[$key] = $update;
                                $mwp_premium_updates_todo_slugs[] = $slug;
                            }
                        }
                    }
                }
                unset($mwp_premium_updates);

                $premiumUpgrader = new Theme_Upgrader(new Bulk_Theme_Upgrader_Skin(compact('nonce', 'url')));
            }
            if (count($themes) <= 0 && count($premiumThemes) <= 0)
            {
                MainWPHelper::error(__('Bad request','mainwp-child'));
            }

            if ($this->filterFunction != null) remove_filter( 'pre_site_transient_update_themes', $this->filterFunction , 99);
        }
        else
        {
            MainWPHelper::error(__('Bad request','mainwp-child'));
        }

        if (count($mwp_premium_updates_todo) > 0)
        {
            //Upgrade via WP
            //@see wp-admin/update.php
            $result = $premiumUpgrader->bulk_upgrade($mwp_premium_updates_todo_slugs);
            if (!empty($result))
            {
                foreach ($result as $plugin => $info)
                {
                    if (!empty($info))
                    {
                        $information['upgrades'][$plugin] = true;

                        foreach ($mwp_premium_updates_todo as $key => $update)
                        {
                            $slug = (isset($update['slug']) ? $update['slug'] : $update['Name']);
                            if (strcmp($slug, $plugin) == 0)
                            {
                                //unset($mwp_premium_updates_todo[$key]);
                            }
                        }
                    }
                }
            }

            //Upgrade via callback
            foreach ($mwp_premium_updates_todo as $update)
            {
                $slug = (isset($update['slug']) ? $update['slug'] : $update['Name']);

                if (isset($update['url']))
                {
                    $installer = new WP_Upgrader();
                    //@see wp-admin/includes/class-wp-upgrader.php
                    $result = $installer->run(array(
                        'package' => $update['url'],
                        'destination' => ($update['type'] == 'plugin' ? WP_PLUGIN_DIR : WP_CONTENT_DIR . '/themes'),
                        'clear_destination' => true,
                        'clear_working' => true,
                        'hook_extra' => array()
                    ));
                    $information['upgrades'][$slug] = (!is_wp_error($result) && !empty($result));
                }
                else if (isset($update['callback']))
                {
                    if (is_array($update['callback']) && isset($update['callback'][0]) && isset($update['callback'][1]))
                    {
                        $update_result = @call_user_func(array($update['callback'][0], $update['callback'][1] ));
                        $information['upgrades'][$slug] = $update_result && true;
                    }
                    else if (is_string($update['callback']))
                    {
                        $update_result = @call_user_func($update['callback']);
                        $information['upgrades'][$slug] = $update_result && true;
                    }
                    else
                    {
                        $information['upgrades'][$slug] = false;
                    }
                }
                else
                {
                    $information['upgrades'][$slug] = false;
                }
            }
        }
        $information['sync'] = $this->getSiteStats(array(), false);
        MainWPHelper::write($information);
    }

    //This will register the current wp - thus generating the public key etc..
    function registerSite()
    {
        global $current_user;

        $information = array();
        //Check if the user is valid & login
        if (!isset($_POST['user']) || !isset($_POST['pubkey']))
        {
            MainWPHelper::error(__('Invalid request','mainwp-child'));
        }

        MainWPHelper::update_option('mainwp_child_branding_disconnected', 'yes');

        //Already added - can't readd. Deactivate plugin..
        if (get_option('mainwp_child_pubkey'))
        {
            MainWPHelper::error(__('Public key already set, reset the MainWP plugin on your site and try again.','mainwp-child'));
        }        
        
        if (get_option('mainwp_child_uniqueId') != '')
        {
            if (!isset($_POST['uniqueId']) || ($_POST['uniqueId'] == ''))
            {
                MainWPHelper::error(__('This Child Site is set to require a Unique Security ID - Please Enter It before connection can be established.','mainwp-child'));
            }
            else if (get_option('mainwp_child_uniqueId') != $_POST['uniqueId'])
            {
                MainWPHelper::error(__('The Unique Security ID you have entered does not match Child Security ID - Please Correct It before connection can be established.','mainwp-child'));
            }
        }

        //Login
        if (isset($_POST['user']))
        {
            if (!$this->login($_POST['user']))
            {
                MainWPHelper::error(__('No such user','mainwp-child'));
            }
            if ($current_user->wp_user_level != 10 && (!isset($current_user->user_level) || $current_user->user_level != 10) && !current_user_can('level_10'))
            {
                MainWPHelper::error(__('User is not an administrator','mainwp-child'));
            }
        }

        MainWPHelper::update_option('mainwp_child_pubkey', base64_encode($_POST['pubkey'])); //Save the public key
        MainWPHelper::update_option('mainwp_child_server', $_POST['server']); //Save the public key
        MainWPHelper::update_option('mainwp_child_nonce', 0); //Save the nonce

        MainWPHelper::update_option('mainwp_child_nossl', ($_POST['pubkey'] == '-1' || !function_exists('openssl_verify') ? 1 : 0));
        $information['nossl'] = ($_POST['pubkey'] == '-1' || !function_exists('openssl_verify') ? 1 : 0);
        $nossl_key = uniqid('', true);
        MainWPHelper::update_option('mainwp_child_nossl_key', $nossl_key);
        $information['nosslkey'] = $nossl_key;
        MainWPHelper::update_option('mainwp_child_branding_disconnected', '');

        $information['register'] = 'OK';        
        $information['uniqueId'] = get_option('mainwp_child_uniqueId', '');
        $information['user'] = $_POST['user'];
        
        $this->getSiteStats($information);
    }

    function newPost()
    {
        //Read form data
        $new_post = unserialize(base64_decode ($_POST['new_post']));
        $post_custom = unserialize(base64_decode ($_POST['post_custom']));
        $post_category = rawurldecode(isset($_POST['post_category']) ? base64_decode ($_POST['post_category']) : null);
        $post_tags = rawurldecode(isset($new_post['post_tags']) ? $new_post['post_tags'] : null);
        $post_featured_image = base64_decode ($_POST['post_featured_image']);
        $upload_dir = unserialize(base64_decode ($_POST['mainwp_upload_dir']));
        $new_post['_ezin_post_category'] = unserialize(base64_decode ($_POST['_ezin_post_category']));

        $res = MainWPHelper::createPost($new_post, $post_custom, $post_category, $post_featured_image, $upload_dir, $post_tags);
        $created = $res['success'];
        if ($created != true)
        {
            MainWPHelper::error($created);
        }

        $information['added'] = true;
        $information['added_id'] = $res['added_id'];
        $information['link'] = $res['link'];

        MainWPHelper::write($information);
    }

    function post_action()
    {
        //Read form data
        $action = $_POST['action'];
        $postId = $_POST['id'];

        if ($action == 'publish')
        {
            wp_publish_post($postId);
        }
        else if ($action == 'update')
        {
            $postData = $_POST['post_data'];
            $my_post = is_array($postData) ? $postData : array();
            wp_update_post($my_post);
        }
        else if ($action == 'unpublish')
        {
            $my_post = array();
            $my_post['ID'] = $postId;
            $my_post['post_status'] = 'draft';
            wp_update_post($my_post);
        }
        else if ($action == 'trash')
        {
            add_action('trash_post', array('MainWPChildLinksChecker','hook_post_deleted'));
            wp_trash_post($postId);
        }
        else if ($action == 'delete')
        {
            add_action('delete_post', array('MainWPChildLinksChecker','hook_post_deleted'));
            wp_delete_post($postId, true);
        }
        else if ($action == 'restore')
        {
            wp_untrash_post($postId);
        }
        else if ($action == 'update_meta')
        {
            $values = unserialize(base64_decode ($_POST['values']));
            $meta_key = $values['meta_key'];
            $meta_value = $values['meta_value'];
            $check_prev = $values['check_prev'];

            foreach ($meta_key as $i => $key)
            {
                if (intval($check_prev[$i]) == 1)
                    update_post_meta($postId, $key, get_post_meta($postId, $key, true) ? get_post_meta($postId, $key, true) : $meta_value[$i]);
                else
                    update_post_meta($postId, $key, $meta_value[$i]);
            }
        }
        else
        {
            $information['status'] = 'FAIL';
        }

        if (!isset($information['status'])) $information['status'] = 'SUCCESS';
        $information['my_post'] = $my_post;
        MainWPHelper::write($information);
    }

    function user_action()
    {
        //Read form data
        $action = $_POST['action'];
        $extra = $_POST['extra'];
        $userId = $_POST['id'];
        $user_pass = $_POST['user_pass'];
        
        global $current_user;
        $reassign = (isset($current_user) && isset($current_user->ID)) ? $current_user->ID : 0;
        
        if ($action == 'delete')
        {
            include_once(ABSPATH . '/wp-admin/includes/user.php');
            wp_delete_user($userId, $reassign);
        }
        else if ($action == 'changeRole')
        {
            $my_user = array();
            $my_user['ID'] = $userId;
            $my_user['role'] = $extra;
            wp_update_user($my_user);
        }
        else if ($action == 'update_password')
        {
            $my_user = array();
            $my_user['ID'] = $userId;
            $my_user['user_pass'] = $user_pass;
            wp_update_user($my_user);
        }
        else
        {
            $information['status'] = 'FAIL';
        }

        if (!isset($information['status'])) $information['status'] = 'SUCCESS';
        MainWPHelper::write($information);
    }

    //todo: backwards compatible: wp_set_comment_status ?
    function comment_action()
    {
        //Read form data
        $action = $_POST['action'];
        $commentId = $_POST['id'];

        if ($action == 'approve')
        {
            wp_set_comment_status($commentId, 'approve');
        }
        else if ($action == 'unapprove')
        {
            wp_set_comment_status($commentId, 'hold');
        }
        else if ($action == 'spam')
        {
            wp_spam_comment($commentId);
        }
        else if ($action == 'unspam')
        {
            wp_unspam_comment($commentId);
        }
        else if ($action == 'trash')
        {
            add_action('trashed_comment', array('MainWPChildLinksChecker', 'hook_trashed_comment'), 10, 1);
            wp_trash_comment($commentId);
        }
        else if ($action == 'restore')
        {
            wp_untrash_comment($commentId);
        }
        else if ($action == 'delete')
        {
            wp_delete_comment($commentId, true);
        }
        else
        {
            $information['status'] = 'FAIL';
        }

        if (!isset($information['status'])) $information['status'] = 'SUCCESS';
        MainWPHelper::write($information);
    }

    //todo: backwards compatible: wp_set_comment_status ?
    function comment_bulk_action()
    {
        //Read form data
        $action = $_POST['action'];
        $commentIds = explode(',', $_POST['ids']);
        $information['success'] = 0;
        foreach ($commentIds as $commentId)
        {
            if ($commentId)
            {
                $information['success']++;
                if ($action == 'approve')
                {
                    wp_set_comment_status($commentId, 'approve');
                }
                else if ($action == 'unapprove')
                {
                    wp_set_comment_status($commentId, 'hold');
                }
                else if ($action == 'spam')
                {
                    wp_spam_comment($commentId);
                }
                else if ($action == 'unspam')
                {
                    wp_unspam_comment($commentId);
                }
                else if ($action == 'trash')
                {
                    wp_trash_comment($commentId);
                }
                else if ($action == 'restore')
                {
                    wp_untrash_comment($commentId);
                }
                else if ($action == 'delete')
                {
                    wp_delete_comment($commentId, true);
                }
                else
                {
                    $information['success']--;
                }


            }
        }
        MainWPHelper::write($information);
    }


    function newAdminPassword()
    {
        //Read form data
        $new_password = unserialize(base64_decode ($_POST['new_password']));
        $user = get_user_by('login', $_POST['user']);
        require_once(ABSPATH . WPINC . '/registration.php');

        $id = wp_update_user(array('ID' => $user->ID, 'user_pass' => $new_password['user_pass']));
        if ($id != $user->ID)
        {
            if (is_wp_error($id))
            {
                MainWPHelper::error($id->get_error_message());
            }
            else
            {
                MainWPHelper::error(__('Could not change the admin password.','mainwp-child'));
            }
        }

        $information['added'] = true;
        MainWPHelper::write($information);
    }

    function newUser()
    {
        //Read form data
        $new_user = unserialize(base64_decode ($_POST['new_user']));
        $send_password = $_POST['send_password'];

        $new_user_id = wp_insert_user($new_user);

        if (is_wp_error($new_user_id))
        {
            MainWPHelper::error($new_user_id->get_error_message());
        }
        if ($new_user_id == 0)
        {
            MainWPHelper::error(__('Undefined error','mainwp-child'));
        }

        if ($send_password)
        {
            $user = new WP_User($new_user_id);

            $user_login = stripslashes($user->user_login);
            $user_email = stripslashes($user->user_email);

            // The blogname option is escaped with esc_html on the way into the database in sanitize_option
            // we want to reverse this for the plain text arena of emails.
            $blogname = wp_specialchars_decode(get_option('blogname'), ENT_QUOTES);

            $message = sprintf(__('Username: %s'), $user_login) . "\r\n";
            $message .= sprintf(__('Password: %s'), $new_user['user_pass']) . "\r\n";
            $message .= wp_login_url() . "\r\n";

            wp_mail($user_email, sprintf(__('[%s] Your username and password'), $blogname), $message);
        }
        $information['added'] = true;
        MainWPHelper::write($information);
    }

    function cloneinfo()
    {
        global $table_prefix;
        $information['dbCharset'] = DB_CHARSET;
        $information['dbCollate'] = DB_COLLATE;
        $information['table_prefix'] = $table_prefix;
        $information['site_url'] = get_option('site_url');
        $information['home'] = get_option('home');

        MainWPHelper::write($information);
    }

    function backupPoll()
    {
        $fileNameUID = (isset($_POST['fileNameUID']) ? $_POST['fileNameUID'] : '');
        $fileName = (isset($_POST['fileName']) ? $_POST['fileName'] : '');

        if ($_POST['type'] == 'full')
        {
            if ($fileName != '')
            {
                $backupFile = $fileName;
            }
            else
            {
                $backupFile = 'backup-' . $fileNameUID . '-';
            }


            $dirs = MainWPHelper::getMainWPDir('backup');
            $backupdir = $dirs[0];
            $result = glob($backupdir . $backupFile . '*');
            $archiveFile = false;
            foreach ($result as $file)
            {
                if (MainWPHelper::isArchive($file, $backupFile, '(.*)'))
                {
                    $archiveFile = $file;
                    break;
                }
            }
            if ($archiveFile === false) MainWPHelper::write(array());

            MainWPHelper::write(array('size' => filesize($archiveFile)));
        }
        else
        {
            $backupFile = 'dbBackup-' . $fileNameUID . '-*.sql';

            $dirs = MainWPHelper::getMainWPDir('backup');
            $backupdir = $dirs[0];
            $result = glob($backupdir . $backupFile . '*');
            if (count($result) == 0) MainWPHelper::write(array());

            MainWPHelper::write(array('size' => filesize($result[0])));
            exit();
        }
    }

    function backup_checkpid()
    {
        $pid = $_POST['pid'];

        $dirs = MainWPHelper::getMainWPDir('backup');
        $backupdir = $dirs[0];

        $information = array();

        /** @var $wp_filesystem WP_Filesystem_Base */
        global $wp_filesystem;

        MainWPHelper::getWPFilesystem();

        $pidFile = trailingslashit($backupdir) . 'backup-' . $pid . '.pid';
        $doneFile = trailingslashit($backupdir) . 'backup-' . $pid . '.done';
        if ($wp_filesystem->is_file($pidFile))
        {
            $time = $wp_filesystem->mtime($pidFile);

            $minutes = date('i', time());
            $seconds = date('s', time());

            $file_minutes = date('i', $time);
            $file_seconds = date('s', $time);

            $minuteDiff = $minutes - $file_minutes;
            if ($minuteDiff == 59) $minuteDiff = 1;
            $secondsdiff = ($minuteDiff * 60) + $seconds - $file_seconds;

            $file = $wp_filesystem->get_contents($pidFile);
            $information['file'] = basename($file);
            if ($secondsdiff < 80)
            {
                $information['status'] = 'busy';
            }
            else
            {
                $information['status'] = 'stalled';
            }
        }
        else if ($wp_filesystem->is_file($doneFile))
        {
            $file = $wp_filesystem->get_contents($doneFile);
            $information['status'] = 'done';
            $information['file'] = basename($file);
            $information['size'] = @filesize($file);
        }
        else
        {
            $information['status'] = 'invalid';
        }

        MainWPHelper::write($information);
    }

    function backup($pWrite = true)
    {
        $timeout = 20 * 60 * 60; //20minutes
        @set_time_limit($timeout);
        @ini_set('max_execution_time', $timeout);
        MainWPHelper::endSession();


        //Cleanup pid files!
        $dirs = MainWPHelper::getMainWPDir('backup');
        $backupdir = trailingslashit($dirs[0]);


        /** @var $wp_filesystem WP_Filesystem_Base */
        global $wp_filesystem;

        MainWPHelper::getWPFilesystem();

        $files = glob($backupdir . '*');
        //Find old files (changes > 3 hr)
        foreach ($files as $file)
        {
            if (MainWPHelper::endsWith($file, '/index.php') | MainWPHelper::endsWith($file, '/.htaccess')) continue;

            if ((time() - filemtime($file)) > (60 * 60 * 3))
            {
                @unlink($file);
            }
        }

        $fileName = (isset($_POST['fileUID']) ? $_POST['fileUID'] : '');
        if ($_POST['type'] == 'full')
        {
            $excludes = (isset($_POST['exclude']) ? explode(',', $_POST['exclude']) : array());
            $excludes[] = str_replace(ABSPATH, '', WP_CONTENT_DIR) . '/uploads/mainwp';
            $uploadDir = MainWPHelper::getMainWPDir();
            $uploadDir = $uploadDir[0];
            $excludes[] = str_replace(ABSPATH, '', $uploadDir);
            $excludes[] = str_replace(ABSPATH, '', WP_CONTENT_DIR) . '/object-cache.php';

            if (function_exists('posix_uname'))
            {
                $uname = @posix_uname();
                if (is_array($uname) && isset($uname['nodename']))
                {
                    if (stristr($uname['nodename'], 'hostgator'))
                    {
                        if (!isset($_POST['file_descriptors']) || $_POST['file_descriptors'] == 0 || $_POST['file_descriptors'] > 1000)
                        {
                            $_POST['file_descriptors'] = 1000;
                        }
                        $_POST['file_descriptors_auto'] = 0;
                        $_POST['loadFilesBeforeZip'] = false;
                    }
                }
            }

            $file_descriptors = (isset($_POST['file_descriptors']) ? $_POST['file_descriptors'] : 0);
            $file_descriptors_auto = (isset($_POST['file_descriptors_auto']) ? $_POST['file_descriptors_auto'] : 0);
            if ($file_descriptors_auto == 1)
            {
                if (function_exists('posix_getrlimit'))
                {
                    $result = @posix_getrlimit();
                    if (isset($result['soft openfiles'])) $file_descriptors = $result['soft openfiles'];
                }
            }

            $loadFilesBeforeZip = (isset($_POST['loadFilesBeforeZip']) ? $_POST['loadFilesBeforeZip'] : true);

            $newExcludes = array();
            foreach ($excludes as $exclude)
            {
                $newExcludes[] = rtrim($exclude, '/');
            }

            $excludebackup = (isset($_POST['excludebackup']) && $_POST['excludebackup'] == 1);
            $excludecache = (isset($_POST['excludecache']) && $_POST['excludecache'] == 1);
            $excludezip = (isset($_POST['excludezip']) && $_POST['excludezip'] == 1);
            $excludenonwp = (isset($_POST['excludenonwp']) && $_POST['excludenonwp'] == 1);

            if ($excludebackup)
            {
                //Backup buddy
                $newExcludes[] = str_replace(ABSPATH, '', WP_CONTENT_DIR) . '/uploads/backupbuddy_backups';
                $newExcludes[] = str_replace(ABSPATH, '', WP_CONTENT_DIR) . '/uploads/backupbuddy_temp';
                $newExcludes[] = str_replace(ABSPATH, '', WP_CONTENT_DIR) . '/uploads/pb_backupbuddy';

                //ManageWP
                $newExcludes[] = str_replace(ABSPATH, '', WP_CONTENT_DIR) . '/managewp';

                //InfiniteWP
                $newExcludes[] = str_replace(ABSPATH, '', WP_CONTENT_DIR) . '/infinitewp';

                //WordPress Backup to Dropbox
                $newExcludes[] = str_replace(ABSPATH, '', WP_CONTENT_DIR) . '/backups';

                //BackUpWordpress
                $newExcludes[] = str_replace(ABSPATH, '', WP_CONTENT_DIR) . '/backups';

                //BackWPUp
                $newExcludes[] = str_replace(ABSPATH, '', WP_CONTENT_DIR) . '/uploads/backwpup*';

                //WP Complete Backup
                $newExcludes[] = str_replace(ABSPATH, '', WP_CONTENT_DIR) . '/plugins/wp-complete-backup/storage';

                //WordPress EZ Backup
                //This one may be hard to do since they add random text at the end for example, feel free to skip if you need to
                ///backup_randomkyfkj where kyfkj is random

                //Online Backup for WordPress
                $newExcludes[] = str_replace(ABSPATH, '', WP_CONTENT_DIR) . '/backups';

                //XCloner
                $newExcludes[] = '/administrator/backups';
            }

            if ($excludecache)
            {
                //W3 Total Cache
                $newExcludes[] = str_replace(ABSPATH, '', WP_CONTENT_DIR) . '/w3tc-cache';
                $newExcludes[] = str_replace(ABSPATH, '', WP_CONTENT_DIR) . '/w3tc';
                $newExcludes[] = str_replace(ABSPATH, '', WP_CONTENT_DIR) . '/cache/config';
                $newExcludes[] = str_replace(ABSPATH, '', WP_CONTENT_DIR) . '/cache/minify';
                $newExcludes[] = str_replace(ABSPATH, '', WP_CONTENT_DIR) . '/cache/page_enhanced';
                $newExcludes[] = str_replace(ABSPATH, '', WP_CONTENT_DIR) . '/cache/tmp';

                //WP Super Cache
                $newExcludes[] = str_replace(ABSPATH, '', WP_CONTENT_DIR) . '/cache/supercache';

                //Quick Cache
                $newExcludes[] = str_replace(ABSPATH, '', WP_CONTENT_DIR) . '/cache/quick-cache';

                //Hyper Cache
                $newExcludes[] = str_replace(ABSPATH, '', WP_CONTENT_DIR) . '/hyper-cache/cache';

                //WP Fastest Cache
                $newExcludes[] = str_replace(ABSPATH, '', WP_CONTENT_DIR) . '/cache/all';

                //WP-Rocket
                $newExcludes[] = str_replace(ABSPATH, '', WP_CONTENT_DIR) . '/cache/wp-rocket';
            }

            $file = false;
            if (isset($_POST['f']))
            {
                $file = $_POST['f'];
            }
            else if (isset($_POST['file']))
            {
                $file = $_POST['file'];
            }

            $ext = 'zip';
            if (isset($_POST['ext']))
            {
                $ext = $_POST['ext'];
            }

            $pid = false;
            if (isset($_POST['pid']))
            {
                $pid = $_POST['pid'];
            }

            $append = (isset($_POST['append']) && ($_POST['append'] == '1'));

            $res = MainWPBackup::get()->createFullBackup($newExcludes, $fileName, true, true, $file_descriptors, $file, $excludezip, $excludenonwp, $loadFilesBeforeZip, $ext, $pid, $append);
            if (!$res)
            {
                $information['full'] = false;
            }
            else
            {
                $information['full'] = $res['file'];
                $information['size'] = $res['filesize'];
            }
            $information['db'] = false;
        }
        else if ($_POST['type'] == 'db')
        {
            $ext = 'zip';
            if (isset($_POST['ext']))
            {
                $ext = $_POST['ext'];
            }

            $res = $this->backupDB($fileName, $ext);
            if (!$res)
            {
                $information['db'] = false;
            }
            else
            {
                $information['db'] = $res['file'];
                $information['size'] = $res['filesize'];
            }
            $information['full'] = false;
        }
        else
        {
            $information['full'] = false;
            $information['db'] = false;
        }

        if ($pWrite) MainWPHelper::write($information);

        return $information;
    }

    protected function backupDB($fileName = '', $ext = 'zip')
    {
        $dirs = MainWPHelper::getMainWPDir('backup');
        $dir = $dirs[0];
        $timestamp = time();
        if ($fileName != '') $fileName .= '-';
        $filepath = $dir . 'dbBackup-' . $fileName . $timestamp . '.sql';

        if ($dh = opendir($dir))
        {
            while (($file = readdir($dh)) !== false)
            {
                if ($file != '.' && $file != '..' && (preg_match('/dbBackup-(.*).sql(\.zip|\.tar|\.tar\.gz|\.tar\.bz2)?$/', $file)))
                {
                    @unlink($dir . $file);
                }
            }
            closedir($dh);
        }

        if (file_exists($filepath))
        {
            @unlink($filepath);
        }

        $result = MainWPBackup::get()->createBackupDB($filepath, $ext);

        MainWPHelper::update_option('mainwp_child_last_db_backup_size', filesize($result['filepath']));

        return ($result === false) ? false : array(
            'timestamp' => $timestamp,
            'file' => basename($result['filepath']),
            'filesize' => filesize($result['filepath'])
        );
    }

    function doSecurityFix()
    {
        $sync = false;
        if ($_POST['feature'] == 'all')
        {
            //fix all
            $sync = true;
        }

        $information = array();
        if ($_POST['feature'] == 'all' || $_POST['feature'] == 'listing')
        {
            MainWPSecurity::prevent_listing();
            $information['listing'] = (!MainWPSecurity::prevent_listing_ok() ? 'N' : 'Y');
        }

        if ($_POST['feature'] == 'all' || $_POST['feature'] == 'wp_version')
        {
            MainWPHelper::update_option('mainwp_child_remove_wp_version', 'T');
            MainWPSecurity::remove_wp_version();
            $information['wp_version'] = (!MainWPSecurity::remove_wp_version_ok() ? 'N' : 'Y');
        }

        if ($_POST['feature'] == 'all' || $_POST['feature'] == 'rsd')
        {
            MainWPHelper::update_option('mainwp_child_remove_rsd', 'T');
            MainWPSecurity::remove_rsd();
            $information['rsd'] = (!MainWPSecurity::remove_rsd_ok() ? 'N' : 'Y');
        }

        if ($_POST['feature'] == 'all' || $_POST['feature'] == 'wlw')
        {
            MainWPHelper::update_option('mainwp_child_remove_wlw', 'T');
            MainWPSecurity::remove_wlw();
            $information['wlw'] = (!MainWPSecurity::remove_wlw_ok() ? 'N' : 'Y');
        }

//        if ($_POST['feature'] == 'all' || $_POST['feature'] == 'core_updates')
//        {
//            update_option('mainwp_child_remove_core_updates', 'T');
//            MainWPSecurity::remove_core_update();
//            $information['core_updates'] = (!MainWPSecurity::remove_core_update_ok() ? 'N' : 'Y');
//        }

//        if ($_POST['feature'] == 'all' || $_POST['feature'] == 'plugin_updates')
//        {
//            update_option('mainwp_child_remove_plugin_updates', 'T');
//            MainWPSecurity::remove_plugin_update();
//            $information['plugin_updates'] = (!MainWPSecurity::remove_plugin_update_ok() ? 'N' : 'Y');
//        }

//        if ($_POST['feature'] == 'all' || $_POST['feature'] == 'theme_updates')
//        {
//            update_option('mainwp_child_remove_theme_updates', 'T');
//            MainWPSecurity::remove_theme_update();
//            $information['theme_updates'] = (!MainWPSecurity::remove_theme_update_ok() ? 'N' : 'Y');
//        }

//        if ($_POST['feature'] == 'all' || $_POST['feature'] == 'file_perms')
//        {
//            MainWPSecurity::fix_file_permissions();
//            $information['file_perms'] = (!MainWPSecurity::fix_file_permissions_ok() ? 'N' : 'Y');
//            if ($information['file_perms'] == 'N')
//            {
//                $information['file_perms'] = 'Could not change all the file permissions';
//            }
//        }

        if ($_POST['feature'] == 'all' || $_POST['feature'] == 'db_reporting')
        {
            MainWPSecurity::remove_database_reporting();
            $information['db_reporting'] = (!MainWPSecurity::remove_database_reporting_ok() ? 'N' : 'Y');
        }

        if ($_POST['feature'] == 'all' || $_POST['feature'] == 'php_reporting')
        {
            MainWPHelper::update_option('mainwp_child_remove_php_reporting', 'T');
            MainWPSecurity::remove_php_reporting();
            $information['php_reporting'] = (!MainWPSecurity::remove_php_reporting_ok() ? 'N' : 'Y');
        }

        if ($_POST['feature'] == 'all' || $_POST['feature'] == 'versions')
        {
            MainWPHelper::update_option('mainwp_child_remove_scripts_version', 'T');
            MainWPHelper::update_option('mainwp_child_remove_styles_version', 'T');
            MainWPSecurity::remove_scripts_version();
            MainWPSecurity::remove_styles_version();
            $information['versions'] = (!MainWPSecurity::remove_scripts_version_ok() || !MainWPSecurity::remove_styles_version_ok()
                    ? 'N' : 'Y');
        }

        if ($_POST['feature'] == 'all' || $_POST['feature'] == 'admin')
        {
            $information['admin'] = (!MainWPSecurity::admin_user_ok() ? 'N' : 'Y');
        }

        if ($_POST['feature'] == 'all' || $_POST['feature'] == 'readme')
        {
            MainWPHelper::update_option('mainwp_child_remove_readme', 'T');
            MainWPSecurity::remove_readme();
            $information['readme'] = (MainWPSecurity::remove_readme_ok() ? 'Y' : 'N');
        }

        if ($sync)
        {
            $information['sync'] = $this->getSiteStats(array(), false);
        }
        MainWPHelper::write($information);
    }

    function doSecurityUnFix()
    {
        $information = array();

        $sync = false;
        if ($_POST['feature'] == 'all')
        {
            $sync = true;
        }

        if ($_POST['feature'] == 'all' || $_POST['feature'] == 'wp_version')
        {
            MainWPHelper::update_option('mainwp_child_remove_wp_version', 'F');
            $information['wp_version'] = 'N';
        }

        if ($_POST['feature'] == 'all' || $_POST['feature'] == 'rsd')
        {
            MainWPHelper::update_option('mainwp_child_remove_rsd', 'F');
            $information['rsd'] = 'N';
        }

        if ($_POST['feature'] == 'all' || $_POST['feature'] == 'wlw')
        {
            MainWPHelper::update_option('mainwp_child_remove_wlw', 'F');
            $information['wlw'] = 'N';
        }

        if ($_POST['feature'] == 'all' || $_POST['feature'] == 'php_reporting')
        {
            MainWPHelper::update_option('mainwp_child_remove_php_reporting', 'F');
            $information['php_reporting'] = 'N';
        }

        if ($_POST['feature'] == 'all' || $_POST['feature'] == 'versions')
        {
            MainWPHelper::update_option('mainwp_child_remove_scripts_version', 'F');
            MainWPHelper::update_option('mainwp_child_remove_styles_version', 'F');
            $information['versions'] = 'N';
        }

        if ($_POST['feature'] == 'all' || $_POST['feature'] == 'readme')
        {
            MainWPHelper::update_option('mainwp_child_remove_readme', 'F');
            $information['readme'] = MainWPSecurity::remove_readme_ok();
        }

        if ($sync)
        {
            $information['sync'] = $this->getSiteStats(array(), false);
        }

        MainWPHelper::write($information);
    }

    function getSecurityStats()
    {
        $information = array();

        $information['listing'] = (!MainWPSecurity::prevent_listing_ok() ? 'N' : 'Y');
        $information['wp_version'] = (!MainWPSecurity::remove_wp_version_ok() ? 'N' : 'Y');
        $information['rsd'] = (!MainWPSecurity::remove_rsd_ok() ? 'N' : 'Y');
        $information['wlw'] = (!MainWPSecurity::remove_wlw_ok() ? 'N' : 'Y');
//        $information['core_updates'] = (!MainWPSecurity::remove_core_update_ok() ? 'N' : 'Y');
//        $information['plugin_updates'] = (!MainWPSecurity::remove_plugin_update_ok() ? 'N' : 'Y');
//        $information['theme_updates'] = (!MainWPSecurity::remove_theme_update_ok() ? 'N' : 'Y');
//        $information['file_perms'] = (!MainWPSecurity::fix_file_permissions_ok() ? 'N' : 'Y');
        $information['db_reporting'] = (!MainWPSecurity::remove_database_reporting_ok() ? 'N' : 'Y');
        $information['php_reporting'] = (!MainWPSecurity::remove_php_reporting_ok() ? 'N' : 'Y');
        $information['versions'] = (!MainWPSecurity::remove_scripts_version_ok() || !MainWPSecurity::remove_styles_version_ok()
                ? 'N' : 'Y');
        $information['admin'] = (!MainWPSecurity::admin_user_ok() ? 'N' : 'Y');
        $information['readme'] = (MainWPSecurity::remove_readme_ok() ? 'Y' : 'N');

        MainWPHelper::write($information);
    }

    function updateExternalSettings()
    {
        $update_htaccess = false;

        if (get_option('mainwp_child_onetime_htaccess') === false)
        {
            $update_htaccess = true;
        }

        if (isset($_POST['heatMap']))
        {
            if ($_POST['heatMap'] == '1')
            {
                if (get_option('heatMapEnabled') != '1') $update_htaccess = true;
                MainWPHelper::update_option('heatMapEnabled', '1');
                MainWPHelper::update_option('heatMapExtensionLoaded', 'yes');
            }
            else
            {
                if (get_option('heatMapEnabled') != '0') $update_htaccess = true;
                MainWPHelper::update_option('heatMapEnabled', '0');
            }
        }

        if (isset($_POST['cloneSites']))
        {
            if ($_POST['cloneSites'] != '0')
            {
                $arr = @json_decode(urldecode($_POST['cloneSites']), 1);
                MainWPHelper::update_option('mainwp_child_clone_sites', (!is_array($arr) ? array() : $arr));
            }
            else
            {
                MainWPHelper::update_option('mainwp_child_clone_sites', '0');
            }
        }

        if (isset($_POST['pluginDir']))
        {
            if (get_option('mainwp_child_pluginDir') != $_POST['pluginDir'])
            {
                MainWPHelper::update_option('mainwp_child_pluginDir', $_POST['pluginDir']);
                $update_htaccess = true;
            }
        }
        else if (get_option('mainwp_child_pluginDir') != false)
        {
            delete_option('mainwp_child_pluginDir');
            $update_htaccess = true;
        }

        if ($update_htaccess)
        {
            $this->update_htaccess(true);
        }
    }

    //Show stats
    function getSiteStats($information = array(), $exit = true)
    {
        global $wp_version;

        if ($exit) $this->updateExternalSettings();

        MainWPHelper::update_option('mainwp_child_branding_disconnected', '');

        $information['version'] = $this->version;
        $information['wpversion'] = $wp_version;
        $information['siteurl'] = get_option('siteurl');
        $information['nossl'] = (get_option('mainwp_child_nossl') == 1 ? 1 : 0);

        include_once(ABSPATH . '/wp-admin/includes/update.php');

        //Check for new versions
        if ($this->filterFunction != null) add_filter( 'pre_site_transient_update_core', $this->filterFunction, 99 );
        if ($this->filterFunction != null) add_filter( 'pre_transient_update_core', $this->filterFunction, 99 );
        @wp_version_check();
        $core_updates = get_core_updates();
        if (count($core_updates) > 0)
        {
            foreach ($core_updates as $core_update)
            {
                if ($core_update->response == 'latest')
                {
                    break;
                }
                if ($core_update->response == 'upgrade' && version_compare($wp_version, $core_update->current, '<='))
                {
                    $information['wp_updates'] = $core_update->current;
                }
            }
        }
        if (!isset($information['wp_updates']))
        {
            $information['wp_updates'] = null;
        }
        if ($this->filterFunction != null) remove_filter( 'pre_site_transient_update_core', $this->filterFunction, 99 );
        if ($this->filterFunction != null) remove_filter( 'pre_transient_update_core', $this->filterFunction, 99 );

        add_filter('default_option_active_plugins', array(&$this, 'default_option_active_plugins'));
        add_filter('option_active_plugins', array(&$this, 'default_option_active_plugins'));

        //First check for new premium updates
        $update_check = apply_filters('mwp_premium_update_check', array());
        if (!empty($update_check))
        {
            foreach ($update_check as $updateFeedback)
            {
                if (is_array($updateFeedback['callback']) && isset($updateFeedback['callback'][0]) && isset($updateFeedback['callback'][1]))
                {
                    @call_user_func(array($updateFeedback['callback'][0], $updateFeedback['callback'][1]));
                }
                else if (is_string($updateFeedback['callback']))
                {
                    @call_user_func($updateFeedback['callback']);
                }
            }
        }

        $informationPremiumUpdates = apply_filters('mwp_premium_update_notification', array());
        $premiumPlugins = array();
        $premiumThemes = array();
        if (is_array($informationPremiumUpdates))
        {
            $premiumUpdates = array();
            $information['premium_updates'] = array();
            for ($i = 0; $i < count($informationPremiumUpdates); $i++)
            {
                if (!isset($informationPremiumUpdates[$i]['new_version']))
                {
                    continue;
                }
                $slug = (isset($informationPremiumUpdates[$i]['slug']) ? $informationPremiumUpdates[$i]['slug'] : $informationPremiumUpdates[$i]['Name']);

                if ($informationPremiumUpdates[$i]['type'] == 'plugin')
                {
                    $premiumPlugins[] = $slug;
                }
                else if ($informationPremiumUpdates[$i]['type'] == 'theme')
                {
                    $premiumThemes[] = $slug;
                }

                $new_version = $informationPremiumUpdates[$i]['new_version'];

                unset($informationPremiumUpdates[$i]['old_version']);
                unset($informationPremiumUpdates[$i]['new_version']);

                $information['premium_updates'][$slug] = $informationPremiumUpdates[$i];
                $information['premium_updates'][$slug]['update'] = (object)array('new_version' => $new_version, 'premium' => true, 'slug' => $slug);
                if (!in_array($slug, $premiumUpdates)) $premiumUpdates[] = $slug;
            }
            MainWPHelper::update_option('mainwp_premium_updates', $premiumUpdates);
        }

        remove_filter('default_option_active_plugins', array(&$this, 'default_option_active_plugins'));
        remove_filter('option_active_plugins', array(&$this, 'default_option_active_plugins'));

        if ($this->filterFunction != null) add_filter( 'pre_site_transient_update_plugins', $this->filterFunction , 99);
        @wp_update_plugins();
        include_once(ABSPATH . '/wp-admin/includes/plugin.php');
        $plugin_updates = get_plugin_updates();
        if (is_array($plugin_updates))
        {
            $information['plugin_updates'] = array();

            foreach ($plugin_updates as $slug => $plugin_update)
            {
                if (in_array($plugin_update->Name, $premiumPlugins)) continue;

                $information['plugin_updates'][$slug] = $plugin_update;
            }
        }
        if ($this->filterFunction != null) remove_filter( 'pre_site_transient_update_plugins', $this->filterFunction , 99);
        if ($this->filterFunction != null) add_filter( 'pre_site_transient_update_themes', $this->filterFunction, 99);
        @wp_update_themes();
        include_once(ABSPATH . '/wp-admin/includes/theme.php');
        $theme_updates = $this->upgrade_get_theme_updates();
        if (is_array($theme_updates))
        {
            $information['theme_updates'] = array();

            foreach ($theme_updates as $slug => $theme_update)
            {
                $name = (is_array($theme_update) ? $theme_update['Name'] : $theme_update->Name);
                if (in_array($name, $premiumThemes)) continue;

                $information['theme_updates'][$slug] = $theme_update;
            }
        }
        if ($this->filterFunction != null) remove_filter( 'pre_site_transient_update_themes', $this->filterFunction, 99);
        $information['recent_comments'] = $this->get_recent_comments(array('approve', 'hold'), 5);
        $information['recent_posts'] = $this->get_recent_posts(array('publish', 'draft', 'pending', 'trash'), 5);
        $information['recent_pages'] = $this->get_recent_posts(array('publish', 'draft', 'pending', 'trash'), 5, 'page');

        $securityIssuess = 0;
        if (!MainWPSecurity::prevent_listing_ok()) $securityIssuess++;
        if (!MainWPSecurity::remove_wp_version_ok()) $securityIssuess++;
        if (!MainWPSecurity::remove_rsd_ok()) $securityIssuess++;
        if (!MainWPSecurity::remove_wlw_ok()) $securityIssuess++;
//        if (!MainWPSecurity::remove_core_update_ok()) $securityIssuess++;
//        if (!MainWPSecurity::remove_plugin_update_ok()) $securityIssuess++;
//        if (!MainWPSecurity::remove_theme_update_ok()) $securityIssuess++;
//        if (!MainWPSecurity::fix_file_permissions_ok()) $securityIssuess++;
        if (!MainWPSecurity::remove_database_reporting_ok()) $securityIssuess++;
        if (!MainWPSecurity::remove_php_reporting_ok()) $securityIssuess++;
        if (!MainWPSecurity::remove_scripts_version_ok() || !MainWPSecurity::remove_styles_version_ok()) $securityIssuess++;
        if (!MainWPSecurity::admin_user_ok()) $securityIssuess++;
        if (!MainWPSecurity::remove_readme_ok()) $securityIssuess++;

        $information['securityIssues'] = $securityIssuess;

        //Directory listings!
        $information['directories'] = $this->scanDir(ABSPATH, 3);
        $cats = get_categories(array('hide_empty' => 0, 'name' => 'select_name', 'hierarchical' => true));
        $categories = array();
        foreach ($cats as $cat)
        {
            $categories[] = $cat->name;
        }
        $information['categories'] = $categories;
        $information['totalsize'] = $this->getTotalFileSize();
        $information['dbsize'] = MainWPChildDB::get_size();

        $auths = get_option('mainwp_child_auth');
        $information['extauth'] = ($auths && isset($auths[$this->maxHistory]) ? $auths[$this->maxHistory] : null);

        $plugins = $this->get_all_plugins_int(false);
        $themes = $this->get_all_themes_int(false);
		$information['plugins'] = $plugins;            
		$information['themes'] = $themes;
		
        if (isset($_POST['optimize']) && ($_POST['optimize'] == 1))
        {   
            $information['users'] = $this->get_all_users_int();
        }

        if (isset($_POST['pluginConflicts']) && ($_POST['pluginConflicts'] != false))
        {
            $pluginConflicts = json_decode(stripslashes($_POST['pluginConflicts']), true);
            $conflicts = array();
            if (count($pluginConflicts) > 0)
            {
                if ($plugins == false) $plugins = $this->get_all_plugins_int(false);
                if (is_array($plugins) && is_array($pluginConflicts))
                {
                    foreach ($plugins as $plugin)
                    {
                        foreach ($pluginConflicts as $pluginConflict)
                        {
                           if (($plugin['active'] == 1) && (($plugin['name'] == $pluginConflict) || ($plugin['slug'] == $pluginConflict)))
                           {
                               $conflicts[] = $plugin['name'];
                           }
                        }
                    }
                }
            }
            if (count($conflicts) > 0) $information['pluginConflicts'] = $conflicts;
        }

        if (isset($_POST['themeConflicts']) && ($_POST['themeConflicts'] != false))
        {
            $themeConflicts = json_decode(stripslashes($_POST['themeConflicts']), true);
            $conflicts = array();
            if (is_array($themeConflicts) && count($themeConflicts) > 0)
            {
                $theme = wp_get_theme()->get('Name');
                foreach ($themeConflicts as $themeConflict)
                {
                   if ($theme == $themeConflict)
                   {
                       $conflicts[] = $theme;
                   }
                }
            }
            if (count($conflicts) > 0) $information['themeConflicts'] = $conflicts;
        }
		
        if (isset($_POST['othersData']))
        {
            $othersData = json_decode(stripslashes($_POST['othersData']), true);
            if (!is_array($othersData))
                $othersData = array();

            do_action("mainwp-site-sync-others-data", $othersData);

            if (isset($othersData['syncUpdraftData']) && $othersData['syncUpdraftData']) {
                if (MainWPChildUpdraftplusBackups::isActivatedUpdraftplus()) {
                    $information['syncUpdraftData'] =   MainWPChildUpdraftplusBackups::Instance()->syncData();
                }
            }
        }

        $information['faviIcon'] = $this->get_favicon();

        $last_post = wp_get_recent_posts(array( 'numberposts' => absint('1')));
        if (isset($last_post[0])) $last_post = $last_post[0];
        if (isset($last_post)) $information['last_post_gmt'] = strtotime($last_post['post_modified_gmt']);
        $information['mainwpdir'] = (MainWPHelper::validateMainWPDir() ? 1 : -1);
        $information['uniqueId'] = get_option('mainwp_child_uniqueId', '');
        if ($exit) MainWPHelper::write($information);

        return $information;
    }

    function get_favicon() {
        $url = site_url();
        $request = wp_remote_get( $url, array('timeout' => 50));
        $favi = "";
        if (is_array($request) && isset($request['body'])) {
            $preg_str = '/(<link\s+(?:[^\>]*)(?:rel="(?:shortcut\s+)?icon"\s*)(?:[^>]*)?href="([^"]+)"(?:[^>]*)?>)/is';
            if (preg_match($preg_str, $request['body'], $matches))
            {
                $favi = $matches[2];
            } else if (file_exists(ABSPATH . 'favicon.ico')) {
                $favi = 'favicon.ico';
            }
        }
        return $favi;
    }

    function scanDir($pDir, $pLvl)
    {
        $output = array();
        if (file_exists($pDir) && is_dir($pDir))
        {
            if (basename($pDir) == 'logs') return empty($output) ? null : $output;
            if ($pLvl == 0) return empty($output) ? null : $output;

            if ($files = @scandir($pDir))
            {
                foreach ($files as $file)
                {
                    if (($file == '.') || ($file == '..')) continue;
                    $newDir = $pDir . $file . DIRECTORY_SEPARATOR;
                    if (@is_dir($newDir))
                    {
                        $output[$file] = $this->scanDir($newDir, $pLvl - 1, false);
                    }
                }

                unset($files);
                $files = null;
            }
        }
        return empty($output) ? null : $output;
    }

    function upgrade_get_theme_updates()
    {
        $themeUpdates = get_theme_updates();
        $newThemeUpdates = array();
        if (is_array($themeUpdates))
        {
            foreach ($themeUpdates as $slug => $themeUpdate)
            {
                $newThemeUpdate = array();
                $newThemeUpdate['update'] = $themeUpdate->update;
                $newThemeUpdate['Name'] = MainWPHelper::search($themeUpdate, 'Name');
                $newThemeUpdate['Version'] = MainWPHelper::search($themeUpdate, 'Version');
                $newThemeUpdates[$slug] = $newThemeUpdate;
            }
        }

        return $newThemeUpdates;
    }

    function get_recent_posts($pAllowedStatuses, $pCount, $type = 'post', $extra = null)
    {
        $allPosts = array();
        if ($pAllowedStatuses != null)
        {
            foreach ($pAllowedStatuses as $status)
            {
                $this->get_recent_posts_int($status, $pCount, $type, $allPosts, $extra);
            }
        }
        else
        {
            $this->get_recent_posts_int('any', $pCount, $type, $allPosts, $extra);
        }
        return $allPosts;
    }

    function get_recent_posts_int($status, $pCount, $type = 'post', &$allPosts, $extra = null)
    {        
        $args = array('post_status' => $status,
            'suppress_filters' => false,
            'post_type' => $type);
        
        $tokens = array();
        if (is_array($extra) && isset($extra['tokens'])) {
            $tokens = $extra['tokens']; 
            if ($extra['extract_post_type'] == 1)
                $args['post_type'] = 'post';
            else if ($extra['extract_post_type'] == 2)
                $args['post_type'] = 'page';
            else if ($extra['extract_post_type'] == 3)
                $args['post_type'] = array('post', 'page');
        }            
        $tokens = array_flip($tokens);        
        
        if ($pCount != 0) $args['numberposts'] = $pCount;
        
        
        $posts = get_posts($args);
        if (is_array($posts))
        {
            foreach ($posts as $post)
            {
                $outPost = array();
                $outPost['id'] = $post->ID;
                $outPost['status'] = $post->post_status;
                $outPost['title'] = $post->post_title;
                $outPost['content'] = $post->post_content;
                $outPost['comment_count'] = $post->comment_count;
                $outPost['dts'] = strtotime($post->post_modified_gmt);
                $usr = get_user_by('id', $post->post_author);
                $outPost['author'] = !empty($usr) ? $usr->user_nicename : 'removed';
                $categoryObjects = get_the_category($post->ID);
                $categories = "";
                foreach ($categoryObjects as $cat)
                {
                    if ($categories != "") $categories .= ", ";
                    $categories .= $cat->name;
                }
                $outPost['categories'] = $categories;
                
                $tagObjects = get_the_tags($post->ID);
                $tags = "";
                if (is_array($tagObjects))
                {
                    foreach ($tagObjects as $tag)
                    {
                        if ($tags != "") $tags .= ", ";
                        $tags .= $tag->name;
                    }
                }
                $outPost['tags'] = $tags; 
                
                if (is_array($tokens)) {
                    if (isset($tokens["[post.url]"]))
                        $outPost["[post.url]"] = get_permalink( $post->ID );                       
                    if (isset($tokens["[post.website.url]"]))
                        $outPost["[post.website.url]"] = get_site_url(); 
                    if (isset($tokens["[post.website.name]"]))
                        $outPost["[post.website.name]"] = get_bloginfo('name');                    
                }
                $allPosts[] = $outPost;
            }
        }
    }

    function posts_where($where)
    {
        if ($this->posts_where_suffix) $where .= ' ' . $this->posts_where_suffix;
        return $where;
    }

    function get_all_posts()
    {
        $this->get_all_posts_by_type('post');
    }

    function get_terms()
    {
        $taxonomy = base64_decode ($_POST['taxonomy']);
        $rslt = get_terms(taxonomy_exists($taxonomy) ? $taxonomy : 'category', 'hide_empty=0');
        MainWPHelper::write($rslt);
    }

    function set_terms()
    {
        $id = base64_decode ($_POST['id']);
        $terms = base64_decode ($_POST['terms']);
        $taxonomy = base64_decode ($_POST['taxonomy']);

        if (trim($terms) != '')
        {
            $terms = explode(',', $terms);
            if (count($terms) > 0)
            {
                wp_set_object_terms($id, array_map('intval', $terms), taxonomy_exists($taxonomy) ? $taxonomy : 'category');
            }
        }
    }

    function insert_comment()
    {
        $postId = $_POST['id'];
        $comments = unserialize(base64_decode ($_POST['comments']));
        $ids = array();
        foreach ($comments as $comment)
        {
            $ids[] = wp_insert_comment(array(
                'comment_post_ID' => $postId,
                'comment_author' => $comment['author'],
                'comment_content' => $comment['content'],
                'comment_date' => $comment['date']
            ));
        }
        MainWPHelper::write($ids);
    }

    function get_post_meta()
    {
        /** @var $wpdb wpdb */
        global $wpdb;
        $postId = $_POST['id'];
        $keys = base64_decode (unserialize($_POST['keys']));
        $meta_value = $_POST['value'];

        $where = '';
        if (!empty($postId))
            $where .= " AND `post_id` = $postId ";
        if (!empty($keys))
        {
            $str_keys = '\'' . implode('\',\'', $keys) . '\'';
            $where .= " AND `meta_key` IN = $str_keys ";
        }
        if (!empty($meta_value))
            $where .= " AND `meta_value` = $meta_value ";


        $results = $wpdb->get_results(sprintf("SELECT * FROM %s WHERE 1 = 1 $where ", $wpdb->postmeta));
        MainWPHelper::write($results);
    }

    function get_total_ezine_post()
    {
        /** @var $wpdb wpdb */
        global $wpdb;
        $start_date = base64_decode ($_POST['start_date']);
        $end_date = base64_decode ($_POST['end_date']);
        $keyword_meta = base64_decode ($_POST['keyword_meta']);
        $where = " WHERE ";
        if (!empty($start_date) && !empty($end_date))
            $where .= "  p.post_date>='$start_date' AND p.post_date<='$end_date' AND ";
        else if (!empty($start_date) && empty($end_date))
        {
            $where .= "  p.post_date='$start_date' AND ";
        }
        $where .= " ( p.post_status='publish' OR p.post_status='future' OR p.post_status='draft' ) 
                                AND  (pm.meta_key='_ezine_keyword' AND pm.meta_value='$keyword_meta')";
        $total = $wpdb->get_var("SELECT COUNT(*)
								 FROM $wpdb->posts p JOIN $wpdb->postmeta pm ON p.ID=pm.post_id
								 $where  ");
        MainWPHelper::write($total);
    }

	function cancel_scheduled_post() {
		global $wpdb;
		$postId = $_POST['post_id'];
		$cancel_all = $_POST['cancel_all'];
		$result = false;
		$information = array();
		if ($postId > 0) {
			if (get_post_meta($postId, '_is_auto_generate_content', true) == 'yes') {
				$post = $wpdb->get_row('SELECT * FROM ' . $wpdb->posts .
										' WHERE ID = ' . $postId .
										' AND post_status = \'future\'');
				if ($post)
					$result = wp_trash_post($postId);
				else
					$result = true;
			}
			if ($result !== false)
				$information['status'] = 'SUCCESS';
		} else if ($cancel_all == true) {
				$post_type = $_POST['post_type'];
				$where = " WHERE p.post_status='future' AND p.post_type = '" . $post_type . "' AND  pm.meta_key = '_is_auto_generate_content' AND pm.meta_value = 'yes' ";
				$posts = $wpdb->get_results("SELECT p.ID FROM $wpdb->posts p JOIN $wpdb->postmeta pm ON p.ID=pm.post_id $where ");
				$count = 0;
				if (is_array($posts)) {
					foreach($posts as $post) {
						if ($post) {
							if (false !== wp_trash_post($post->ID)) {
								$count++;

							}
						}
					}
				} else {
					$posts = array();
				}

				$information['status'] = "SUCCESS";
				$information['count'] = $count;
		}

		MainWPHelper::write($information);
	}

    function get_next_time_to_post()
    {
      $post_type = $_POST['post_type'];
	  if ($post_type != 'post' && $post_type != 'page') {
		MainWPHelper::write(array('error' => 'Data error.'));
		return;
	  }
	  $information = array();
      try
		{
				global $wpdb;
				$ct = current_time('mysql');
				 $next_post = $wpdb->get_row("
					SELECT *
					FROM " . $wpdb->posts . " p JOIN " . $wpdb->postmeta . " pm ON p.ID=pm.post_id
					WHERE
						pm.meta_key='_is_auto_generate_content' AND
						pm.meta_value='yes' AND
						p.post_status='future' AND
						p.post_type= '" . $post_type. "' AND
						p.post_date > NOW()
					ORDER BY p.post_date
					LIMIT 1");

				if (!$next_post)
				{
					$information['error'] =  "Thera are not auto scheduled post";
				}
				else
				{
					$timestamp = strtotime($next_post->post_date);
					$timestamp_gmt = $timestamp - get_option('gmt_offset') * 60 * 60;
					$information['next_post_date_timestamp_gmt'] =  $timestamp_gmt;
					$information['next_post_id'] =  $next_post->ID;
				}

			MainWPHelper::write($information);
		}
		catch (Exception $e)
		{
			$information['error'] = $e->getMessage();
			MainWPHelper::write($information);
		}
    }

    // function get_next_time_of_post_to_post()
    // {
        // /** @var $wpdb wpdb */
        // global $wpdb;
		// try
		// {
			// $ct = current_time('mysql');
			// $next_post = $wpdb->get_row("
				// SELECT *
				// FROM $wpdb->posts p JOIN $wpdb->postmeta pm ON p.ID=pm.post_id
				// WHERE
					// pm.meta_key='_ezine_keyword' AND
					// p.post_status='future' AND
					// p.post_type='post' AND
					// p.post_date>'$ct'
				// ORDER BY p.post_date
				// LIMIT 1");

			// if (!$next_post)
			// {
				// $information['error'] =  "Can not get next schedule post";
			// }
			// else
			// {
				// $information['next_post_date'] =  $next_post->post_date;
				// $information['next_post_id'] =  $next_post->ID;

				// $next_posts = $wpdb->get_results("
				// SELECT DISTINCT  `ID`
					// FROM $wpdb->posts p
					// JOIN $wpdb->postmeta pm ON p.ID = pm.post_id
					// WHERE pm.meta_key =  '_ezine_keyword'
					// AND p.post_status =  'future'
					// AND p.post_date > NOW( )
					// ORDER BY p.post_date
				// ");

				// if (!$next_posts)
					// $information['error'] =  "Can not get all next schedule post";
				// else
					// $information['next_posts'] =  $next_posts;

			// }

			// MainWPHelper::write($information);
		// }
		// catch (Exception $e)
		// {
			// $information['error'] = $e->getMessage();
			// MainWPHelper::write($information);
		// }
    // }

    // function get_next_time_of_page_to_post()
    // {
        // /** @var $wpdb wpdb */
        // global $wpdb;
		// try
		// {

			// $ct = current_time('mysql');
			// $next_post = $wpdb->get_row("
				// SELECT *
				// FROM $wpdb->posts p JOIN $wpdb->postmeta pm ON p.ID=pm.post_id
				// WHERE
					// pm.meta_key='_ezine_keyword' AND
					// p.post_status='future' AND
					// p.post_type='page' AND
					// p.post_date>'$ct'
				// ORDER BY p.post_date
				// LIMIT 1");

			// if (!$next_post)
			// {
				// $information['error'] =  "Can not get next schedule post";
			// }
			// else
			// {

				// $information['next_post_date'] =  $next_post->post_date;
				// $information['next_post_id'] =  $next_post->ID;

				 // $next_posts = $wpdb->get_results("
					// SELECT DISTINCT  `ID`
						// FROM $wpdb->posts p
						// JOIN $wpdb->postmeta pm ON p.ID = pm.post_id
						// WHERE pm.meta_key =  '_ezine_keyword'
						// AND p.post_status =  'future'
						// AND p.post_date > NOW( )
						// ORDER BY p.post_date
					// ");

				// if (!$next_posts)
					// $information['error'] =  "Can not get all next schedule post";
				// else
					// $information['next_posts'] =  $next_posts;

			// }

			// MainWPHelper::write($information);
		// }
		// catch (Exception $e)
		// {
			// $information['error'] = $e->getMessage();
			// MainWPHelper::write($information);
		// }

    // }

    function get_all_pages()
    {
        $this->get_all_posts_by_type('page');
    }

    function get_all_pages_int()
    {
        $rslt = $this->get_recent_posts(null, -1, 'page');
        return $rslt;
    }

    function get_all_posts_by_type($type)
    {
        global $wpdb;

        add_filter('posts_where', array(&$this, 'posts_where'));

        if (isset($_POST['postId']))
        {
            $this->posts_where_suffix .= " AND $wpdb->posts.ID = " . $_POST['postId'];
        }
        else if (isset($_POST['userId']))
        {
            $this->posts_where_suffix .= " AND $wpdb->posts.post_author = " . $_POST['userId'];
        }
        else
        {
            if (isset($_POST['keyword']))
            {
                $this->posts_where_suffix .= " AND ($wpdb->posts.post_content LIKE '%" . $_POST['keyword'] . "%' OR $wpdb->posts.post_title LIKE '%" . $_POST['keyword'] . "%' )";
            }
            if (isset($_POST['dtsstart']) && $_POST['dtsstart'] != '')
            {
                $this->posts_where_suffix .= " AND $wpdb->posts.post_modified > '" . $_POST['dtsstart'] . "'";
            }
            if (isset($_POST['dtsstop']) && $_POST['dtsstop'] != '')
            {
                $this->posts_where_suffix .= " AND $wpdb->posts.post_modified < '" . $_POST['dtsstop'] . "'";
            }
        }

        $maxPages = MAINWP_CHILD_NR_OF_PAGES;
        if (isset($_POST['maxRecords']))
        {
            $maxPages = $_POST['maxRecords'];
        }
        if ($maxPages == 0)
        {
            $maxPages = 99999;
        }
        
        $extra = array();
        if (isset($_POST['extract_tokens'])) {
            $extra['tokens'] = unserialize(base64_decode ($_POST['extract_tokens']));
            $extra['extract_post_type'] = $_POST['extract_post_type'];
        }
        
        $rslt = $this->get_recent_posts(explode(',', $_POST['status']), $maxPages, $type, $extra);
        $this->posts_where_suffix = '';

        MainWPHelper::write($rslt);
    }

    function comments_clauses($clauses)
    {
        if ($this->comments_and_clauses) $clauses['where'] .= ' ' . $this->comments_and_clauses;
        return $clauses;
    }

    function get_all_comments()
    {
        global $wpdb;

        add_filter('comments_clauses', array(&$this, 'comments_clauses'));

        if (isset($_POST['postId']))
        {
            $this->comments_and_clauses .= " AND $wpdb->comments.comment_post_ID = " . $_POST['postId'];
        }
        else
        {
            if (isset($_POST['keyword']))
            {
                $this->comments_and_clauses .= " AND $wpdb->comments.comment_content LIKE '%" . $_POST['keyword'] . "%'";
            }
            if (isset($_POST['dtsstart']) && $_POST['dtsstart'] != '')
            {
                $this->comments_and_clauses .= " AND $wpdb->comments.comment_date > '" . $_POST['dtsstart'] . "'";
            }
            if (isset($_POST['dtsstop']) && $_POST['dtsstop'] != '')
            {
                $this->comments_and_clauses .= " AND $wpdb->comments.comment_date < '" . $_POST['dtsstop'] . "'";
            }
        }

        $maxComments = MAINWP_CHILD_NR_OF_COMMENTS;
        if (isset($_POST['maxRecords']))
        {
            $maxComments = $_POST['maxRecords'];
        }

        if ($maxComments == 0)
        {
            $maxComments = 99999;
        }

        $rslt = $this->get_recent_comments(explode(',', $_POST['status']), $maxComments);
        $this->comments_and_clauses = '';

        MainWPHelper::write($rslt);
    }

    function get_recent_comments($pAllowedStatuses, $pCount)
    {
        if (!function_exists('get_comment_author_url')) include_once(WPINC . '/comment-template.php');
        $allComments = array();

        foreach ($pAllowedStatuses as $status)
        {
            $params = array('status' => $status);
            if ($pCount != 0) $params['number'] = $pCount;
            $comments = get_comments($params);
            if (is_array($comments))
            {
                foreach ($comments as $comment)
                {
                    $post = get_post($comment->comment_post_ID);
                    $outComment = array();
                    $outComment['id'] = $comment->comment_ID;
                    $outComment['status'] = wp_get_comment_status($comment->comment_ID);
                    $outComment['author'] = $comment->comment_author;
                    $outComment['author_url'] = get_comment_author_url($comment->comment_ID);
                    $outComment['author_ip'] = get_comment_author_IP($comment->comment_ID);
                    $outComment['author_email'] = $email = apply_filters( 'comment_email', $comment->comment_author_email );
                    if ((!empty($outComment['author_email'])) && ($outComment['author_email'] != '@')) {
                        $outComment['author_email'] = '<a href="mailto:'.$outComment['author_email'].'">'.$outComment['author_email'].'</a>';
                    }
                    $outComment['postId'] = $comment->comment_post_ID;
                    $outComment['postName'] = $post->post_title;
                    $outComment['comment_count'] = $post->comment_count;
                    $outComment['content'] = $comment->comment_content;
                    $outComment['dts'] = strtotime($comment->comment_date_gmt);
                    $allComments[] = $outComment;
                }
            }
        }
        return $allComments;
    }

    function theme_action()
    {
        //Read form data
        $action = $_POST['action'];
        $theme = $_POST['theme'];

        if ($action == 'activate')
        {
            include_once(ABSPATH . '/wp-admin/includes/theme.php');
            $theTheme = get_theme($theme);
            if ($theTheme != null && $theTheme != '') switch_theme($theTheme['Template'], $theTheme['Stylesheet']);
        }
        else if ($action == 'delete')
        {
            include_once(ABSPATH . '/wp-admin/includes/theme.php');
//            if (file_exists(ABSPATH . '/wp-admin/includes/deprecated.php')) include_once(ABSPATH . '/wp-admin/includes/deprecated.php');
            if (file_exists(ABSPATH . '/wp-admin/includes/screen.php')) include_once(ABSPATH . '/wp-admin/includes/screen.php');
            include_once(ABSPATH . '/wp-admin/includes/file.php');
            include_once(ABSPATH . '/wp-admin/includes/template.php');
            include_once(ABSPATH . '/wp-admin/includes/misc.php');
            include_once(ABSPATH . '/wp-admin/includes/class-wp-upgrader.php');
            include_once(ABSPATH . '/wp-admin/includes/class-wp-filesystem-base.php');
            include_once(ABSPATH . '/wp-admin/includes/class-wp-filesystem-direct.php');

            $wp_filesystem = $this->getWPFilesystem();
            if (empty($wp_filesystem)) $wp_filesystem = new WP_Filesystem_Direct(null);
            $themeUpgrader = new Theme_Upgrader();

            $theme_name = wp_get_theme()->get('Name');
            $themes = explode('||', $theme);

            foreach ($themes as $idx => $themeToDelete)
            {
                if ($themeToDelete != $theme_name)
                {
                    $theTheme = get_theme($themeToDelete);
                    if ($theTheme != null && $theTheme != '')
                    {
                        $tmp['theme'] = $theTheme['Template'];
                        $themeUpgrader->delete_old_theme(null, null, null, $tmp);
                    }
                }
            }
        }
        else
        {
            $information['status'] = 'FAIL';
        }

        if (!isset($information['status'])) $information['status'] = 'SUCCESS';
        $information['sync'] = $this->getSiteStats(array(), false);
        MainWPHelper::write($information);
    }

    function get_all_themes()
    {
        $keyword = $_POST['keyword'];
        $status = $_POST['status'];
        $filter = isset($_POST['filter']) ? $_POST['filter'] : true;
        $rslt = $this->get_all_themes_int($filter, $keyword, $status);

        MainWPHelper::write($rslt);
    }

    function get_all_themes_int($filter, $keyword = '', $status = '')
    {
        $rslt = array();
        $themes = wp_get_themes();

        if (is_array($themes))
        {
            $theme_name = wp_get_theme()->get('Name');

            /** @var $theme WP_Theme */
            foreach ($themes as $theme)
            {
                $out = array();
                $out['name'] = $theme->get('Name');
                $out['title'] = $theme->display('Name', true, false);
                $out['description'] = $theme->display('Description', true, false);
                $out['version'] = $theme->display('Version', true, false);
                $out['active'] = ($theme->get('Name') == $theme_name) ? 1 : 0;
                $out['slug'] = $theme->get_stylesheet();
                if (!$filter)
                {
                    if ($keyword == '' || stristr($out['title'], $keyword)) $rslt[] = $out;
                }
                else if ($out['active'] == (($status == 'active') ? 1 : 0))
                {
                    if ($keyword == '' || stristr($out['title'], $keyword)) $rslt[] = $out;
                }
            }
        }

        return $rslt;
    }

    function plugin_action()
    {
        //Read form data
        $action = $_POST['action'];
        $plugins = explode('||', $_POST['plugin']);

        if ($action == 'activate')
        {
            include_once(ABSPATH . '/wp-admin/includes/plugin.php');

            foreach ($plugins as $idx => $plugin)
            {
                if ($plugin != $this->plugin_slug)
                {
                    $thePlugin = get_plugin_data($plugin);
                    if ($thePlugin != null && $thePlugin != '') activate_plugin($plugin);
                }
            }
        }
        else if ($action == 'deactivate')
        {
            include_once(ABSPATH . '/wp-admin/includes/plugin.php');

            foreach ($plugins as $idx => $plugin)
            {
                if ($plugin != $this->plugin_slug)
                {
                    $thePlugin = get_plugin_data($plugin);
                    if ($thePlugin != null && $thePlugin != '') deactivate_plugins($plugin);
                }
            }
        }
        else if ($action == 'delete')
        {
            include_once(ABSPATH . '/wp-admin/includes/plugin.php');
//            if (file_exists(ABSPATH . '/wp-admin/includes/deprecated.php')) include_once(ABSPATH . '/wp-admin/includes/deprecated.php');
            if (file_exists(ABSPATH . '/wp-admin/includes/screen.php')) include_once(ABSPATH . '/wp-admin/includes/screen.php');
            include_once(ABSPATH . '/wp-admin/includes/file.php');
            include_once(ABSPATH . '/wp-admin/includes/template.php');
            include_once(ABSPATH . '/wp-admin/includes/misc.php');
            include_once(ABSPATH . '/wp-admin/includes/class-wp-upgrader.php');
            include_once(ABSPATH . '/wp-admin/includes/class-wp-filesystem-base.php');
            include_once(ABSPATH . '/wp-admin/includes/class-wp-filesystem-direct.php');

            $wp_filesystem = $this->getWPFilesystem();
            if ($wp_filesystem == null) $wp_filesystem = new WP_Filesystem_Direct(null);
            $pluginUpgrader = new Plugin_Upgrader();

            foreach ($plugins as $idx => $plugin)
            {
                if ($plugin != $this->plugin_slug)
                {
                    $thePlugin = get_plugin_data($plugin);
                    if ($thePlugin != null && $thePlugin != '')
                    {
                        $tmp['plugin'] = $plugin;
                        $pluginUpgrader->delete_old_plugin(null, null, null, $tmp);
                    }
                }
            }
        }
        else
        {
            $information['status'] = 'FAIL';
        }

        if (!isset($information['status'])) $information['status'] = 'SUCCESS';
        $information['sync'] = $this->getSiteStats(array(), false);
        MainWPHelper::write($information);
    }

    function get_all_plugins()
    {
        $keyword = $_POST['keyword'];
        $status = $_POST['status'];
        $filter = isset($_POST['filter']) ? $_POST['filter'] : true;
        $rslt = $this->get_all_plugins_int($filter, $keyword, $status);

        MainWPHelper::write($rslt);
    }

    function get_all_plugins_int($filter, $keyword = '', $status = '')
    {
        if (!function_exists('get_plugins'))
        {
            include_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }
        $rslt = array();
        $plugins = get_plugins();
        if (is_array($plugins))
        {
            $active_plugins = get_option('active_plugins');

            foreach ($plugins as $pluginslug => $plugin)
            {
                if ($pluginslug == $this->plugin_slug) continue;

                $out = array();
                $out['name'] = $plugin['Name'];
                $out['slug'] = $pluginslug;
                $out['description'] = $plugin['Description'];
                $out['version'] = $plugin['Version'];
                $out['active'] = (is_array($active_plugins) && in_array($pluginslug, $active_plugins)) ? 1 : 0;				                
                if (!$filter)
                {
                    if ($keyword == '' || stristr($out['name'], $keyword)) $rslt[] = $out;
                }
                else if ($out['active'] == (($status == 'active') ? 1 : 0))
                {
                    if ($keyword == '' || stristr($out['name'], $keyword)) $rslt[] = $out;
                }
            }
        }

        return $rslt;
    }

    function get_all_users()
    {
        $roles = explode(',', $_POST['role']);
        $allusers = array();
        if (is_array($roles))
        {
            foreach ($roles as $role)
            {
                $new_users = get_users('role=' . $role);
                //            $allusers[$role] = array();
                foreach ($new_users as $new_user)
                {
                    $usr = array();
                    $usr['id'] = $new_user->ID;
                    $usr['login'] = $new_user->user_login;
                    $usr['nicename'] = $new_user->user_nicename;
                    $usr['email'] = $new_user->user_email;
                    $usr['registered'] = $new_user->user_registered;
                    $usr['status'] = $new_user->user_status;
                    $usr['display_name'] = $new_user->display_name;
                    $usr['role'] = $role;
                    $usr['post_count'] = count_user_posts($new_user->ID);
                    $usr['avatar'] = get_avatar($new_user->ID, 32);
                    $allusers[] = $usr;
                }
            }
        }

        MainWPHelper::write($allusers);
    }

    function get_all_users_int()
    {
        $allusers = array();

        $new_users = get_users();
        if (is_array($new_users))
        {
            foreach ($new_users as $new_user)
            {
                $usr = array();
                $usr['id'] = $new_user->ID;
                $usr['login'] = $new_user->user_login;
                $usr['nicename'] = $new_user->user_nicename;
                $usr['email'] = $new_user->user_email;
                $usr['registered'] = $new_user->user_registered;
                $usr['status'] = $new_user->user_status;
                $usr['display_name'] = $new_user->display_name;
                $userdata = get_userdata($new_user->ID);
                $user_roles = $userdata->roles;
                $user_role = array_shift($user_roles);
                $usr['role'] = $user_role;
                $usr['post_count'] = count_user_posts($new_user->ID);
                $allusers[] = $usr;
            }
        }

        return $allusers;
    }


    function search_users()
    {
        $columns = explode(',', $_POST['search_columns']);
        $allusers = array();
        $exclude = array();

        foreach ($columns as $col)
        {
            if (empty($col))
                continue;

            $user_query = new WP_User_Query(array('search' => $_POST['search'],
                'fields' => 'all_with_meta',
                'search_columns' => array($col),
                'query_orderby' => array($col),
                'exclude' => $exclude));
            if (!empty($user_query->results))
            {
                foreach ($user_query->results as $new_user)
                {
                    $exclude[] = $new_user->ID;
                    $usr = array();
                    $usr['id'] = $new_user->ID;
                    $usr['login'] = $new_user->user_login;
                    $usr['nicename'] = $new_user->user_nicename;
                    $usr['email'] = $new_user->user_email;
                    $usr['registered'] = $new_user->user_registered;
                    $usr['status'] = $new_user->user_status;
                    $usr['display_name'] = $new_user->display_name;
                    $userdata = get_userdata($new_user->ID);
                    $user_roles = $userdata->roles;
                    $user_role = array_shift($user_roles);
                    $usr['role'] = $user_role;
                    $usr['post_count'] = count_user_posts($new_user->ID);
                    $usr['avatar'] = get_avatar($new_user->ID, 32);
                    $allusers[] = $usr;
                }
            }
        }

        MainWPHelper::write($allusers);
    }

//Show stats without login - only allowed while no account is added yet
    function getSiteStatsNoAuth($information = array())
    {
        if (get_option('mainwp_child_pubkey'))
        {
            MainWPHelper::error(__('This site already contains a link - please disable and enable the MainWP plugin.','mainwp-child'));
        }

        global $wp_version;
        $information['version'] = $this->version;
        $information['wpversion'] = $wp_version;
        MainWPHelper::write($information);
    }

    //Deactivating the plugin
    function deactivate()
    {
        include_once(ABSPATH . 'wp-admin/includes/plugin.php');
        deactivate_plugins($this->plugin_slug, true);
        $information = array();
        if (is_plugin_active($this->plugin_slug))
        {
            MainWPHelper::error('Plugin still active');
        }
        $information['deactivated'] = true;
        MainWPHelper::write($information);
    }

    function activation()
    {
            $to_delete = array('mainwp_child_pubkey', 'mainwp_child_nonce', 'mainwp_child_nossl', 'mainwp_child_nossl_key', 'mainwp_child_uniqueId');
            foreach ($to_delete as $delete)
            {
                if (get_option($delete))
                {
                    delete_option($delete);
                }
            }

        MainWPHelper::update_option('mainwp_child_activated_once', true);
        
        // delete bad data if existed
        $to_delete = array('mainwp_ext_snippets_enabled', 'mainwp_ext_code_snippets');                
        foreach ($to_delete as $delete)
        {  
            delete_option($delete);           
        }
    }

    function deactivation()
    {
        $to_delete = array('mainwp_child_pubkey', 'mainwp_child_nonce', 'mainwp_child_nossl', 'mainwp_child_nossl_key', 'mainwp_child_remove_styles_version', 'mainwp_child_remove_scripts_version', 'mainwp_child_remove_php_reporting', 'mainwp_child_remove_theme_updates', 'mainwp_child_remove_plugin_updates', 'mainwp_child_remove_core_updates', 'mainwp_child_remove_wlw', 'mainwp_child_remove_rsd', 'mainwp_child_remove_wp_version', 'mainwp_child_server');
        $to_delete[] = 'mainwp_ext_snippets_enabled';
        $to_delete[] = 'mainwp_ext_code_snippets';        
        
        foreach ($to_delete as $delete)
        {
            if (get_option($delete))
            {
                delete_option($delete);
            }
        }
        do_action('mainwp_child_deactivation');
    }

    function getWPFilesystem()
    {
        global $wp_filesystem;

        if (empty($wp_filesystem))
        {
            ob_start();
//            if (file_exists(ABSPATH . '/wp-admin/includes/deprecated.php')) include_once(ABSPATH . '/wp-admin/includes/deprecated.php');
            if (file_exists(ABSPATH . '/wp-admin/includes/screen.php')) include_once(ABSPATH . '/wp-admin/includes/screen.php');
            if (file_exists(ABSPATH . '/wp-admin/includes/template.php')) include_once(ABSPATH . '/wp-admin/includes/template.php');
            $creds = request_filesystem_credentials('test', '', false, false, $extra_fields = null);
            ob_end_clean();
            if (empty($creds))
            {
                define('FS_METHOD', 'direct');
            }
            WP_Filesystem($creds);
        }

        if (empty($wp_filesystem))
        {
            MainWPHelper::error($this->FTP_ERROR);
        }
        else if (is_wp_error($wp_filesystem->errors))
        {
            $errorCodes = $wp_filesystem->errors->get_error_codes();
            if (!empty($errorCodes))
            {
                MainWPHelper::error(__('Wordpress Filesystem error: ','mainwp-child') . $wp_filesystem->errors->get_error_message());
            }
        }

        return $wp_filesystem;
    }

    function getTotalFileSize($directory = WP_CONTENT_DIR)
    {
        try
        {
        if (MainWPHelper::function_exists('popen'))
        {
            $uploadDir = MainWPHelper::getMainWPDir();
            $uploadDir = $uploadDir[0];
            $popenHandle = @popen('du -s ' . $directory . ' --exclude "' . str_replace(ABSPATH, '', $uploadDir) . '"', 'r');
            if (gettype($popenHandle) == 'resource')
            {
                $size = @fread($popenHandle, 1024);
                @pclose($popenHandle);
                $size = substr($size, 0, strpos($size, "\t"));
                if (ctype_digit($size))
                {
                    return $size / 1024;
                }
            }
        }
        if (MainWPHelper::function_exists('shell_exec'))
        {
            $uploadDir = MainWPHelper::getMainWPDir();
            $uploadDir = $uploadDir[0];
            $size = @shell_exec('du -s ' . $directory . ' --exclude "' . str_replace(ABSPATH, '', $uploadDir) . '"', 'r');
            if ($size != NULL)
            {
                $size = substr($size, 0, strpos($size, "\t"));
                if (ctype_digit($size))
                {
                    return $size / 1024;
                }
            }
        }
        if (class_exists('COM'))
        {
            $obj = new COM('scripting.filesystemobject');

            if (is_object($obj))
            {
                $ref = $obj->getfolder($directory);

                $size = $ref->size;

                $obj = null;
                if (ctype_digit($size))
                {
                    return $size / 1024;
                }
            }
        }

        function dirsize($dir)
        {
            $dirs = array($dir);
            $size = 0;
            while (isset ($dirs[0]))
            {
                $path = array_shift($dirs);
                if (stristr($path, WP_CONTENT_DIR . '/uploads/mainwp')) continue;
                $uploadDir = MainWPHelper::getMainWPDir();
                $uploadDir = $uploadDir[0];
                if (stristr($path, $uploadDir)) continue;
                $res = @glob($path . '/*');
                if (is_array($res))
                {
                    foreach ($res AS $next)
                    {
                        if (is_dir($next))
                        {
                            $dirs[] = $next;
                        }
                        else
                        {
                            $fs = filesize($next);
                            $size += $fs;
                        }
                    }
                }
            }
            return $size / 1024 / 1024;
        }

        return dirsize($directory);
    }
        catch (Exception $e)
        {
            return 0;
        }
    }

    function serverInformation()
    {
        @ob_start();
        MainWPChildServerInformation::render();
        $output['information'] = @ob_get_contents();
        @ob_end_clean();
        @ob_start();
        MainWPChildServerInformation::renderCron();
        $output['cron'] = @ob_get_contents();
        @ob_end_clean();
        @ob_start();
        MainWPChildServerInformation::renderErrorLogPage();
        $output['error'] = @ob_get_contents();
        @ob_end_clean();
        @ob_start();
        MainWPChildServerInformation::renderWPConfig();
        $output['wpconfig'] = @ob_get_contents();
        @ob_end_clean();
        @ob_start();
        MainWPChildServerInformation::renderhtaccess();
        $output['htaccess'] = @ob_get_contents();
        @ob_end_clean();

        MainWPHelper::write($output);
    }

    function maintenance_site()
    {
        global $wpdb;
        $information = array();
        if (isset($_POST['action'])) {
            if ($_POST['action'] === 'save_settings') {

                if (isset($_POST['enable_alert']) && $_POST['enable_alert'] == 1)
                {
                    MainWPHelper::update_option('mainwp_maintenance_opt_alert_404', 1);
                } else {
                    delete_option('mainwp_maintenance_opt_alert_404');
                }

                if (isset($_POST['email']) && !empty($_POST['email']))
                {
                    MainWPHelper::update_option('mainwp_maintenance_opt_alert_404_email', $_POST['email']);
                } else {
                    delete_option('mainwp_maintenance_opt_alert_404_email');
                }
                $information['result'] = 'SUCCESS';
                MainWPHelper::write($information);
                return;
            } else if ($_POST['action'] === 'clear_settings') {
                delete_option('mainwp_maintenance_opt_alert_404');
                delete_option('mainwp_maintenance_opt_alert_404_email');
                $information['result'] = 'SUCCESS';
                MainWPHelper::write($information);
            }
            MainWPHelper::write($information);
        }

        $maint_options = $_POST['options'];
        $max_revisions = isset($_POST['revisions']) ? intval($_POST['revisions']) : 0;
        
        if (!is_array($maint_options))
        {
            $information['status'] = 'FAIL';
            $maint_options = array();
        }
         
        if (empty($max_revisions)) {
            $sql_clean = "DELETE FROM $wpdb->posts WHERE post_type = 'revision'";
            $wpdb->query($sql_clean);
        } else {
            $results = MainWPHelper::getRevisions($max_revisions);
            $count_deleted = MainWPHelper::deleteRevisions($results, $max_revisions);
        }
        
        if (in_array('autodraft', $maint_options))
        {
            $sql_clean = "DELETE FROM $wpdb->posts WHERE post_status = 'auto-draft'";
            $wpdb->query($sql_clean);
        }

        if (in_array('trashpost', $maint_options))
        {
            $sql_clean = "DELETE FROM $wpdb->posts WHERE post_status = 'trash'";
            $wpdb->query($sql_clean);
        }

        if (in_array('spam', $maint_options))
        {
            $sql_clean = "DELETE FROM $wpdb->comments WHERE comment_approved = 'spam'";
            $wpdb->query($sql_clean);
        }

        if (in_array('pending', $maint_options))
        {
            $sql_clean = "DELETE FROM $wpdb->comments WHERE comment_approved = '0'";
            $wpdb->query($sql_clean);
        }

        if (in_array('trashcomment', $maint_options))
        {
            $sql_clean = "DELETE FROM $wpdb->comments WHERE comment_approved = 'trash'";
            $wpdb->query($sql_clean);
        }

        if (in_array('tags', $maint_options))
        {
            $post_tags = get_terms('post_tag', array('hide_empty' => false));
            if (is_array($post_tags))
            {
                foreach ($post_tags as $tag)
                {
                    if ($tag->count == 0)
                    {
                        wp_delete_term($tag->term_id, 'post_tag');
                    }
                }
            }
        }

        if (in_array('categories', $maint_options))
        {
            $post_cats = get_terms('category', array('hide_empty' => false));
            if (is_array($post_cats))
            {
                foreach ($post_cats as $cat)
                {
                    if ($cat->count == 0)
                    {
                        wp_delete_term($cat->term_id, 'category');
                    }
                }
            }
        }

        if (in_array('optimize', $maint_options))
        {
            $this->maintenance_optimize();
        }        
        if (!isset($information['status'])) $information['status'] = 'SUCCESS';
        MainWPHelper::write($information);
    }

    function maintenance_optimize()
    {      
        global $wpdb, $table_prefix;          
        $sql = 'SHOW TABLE STATUS FROM `' . DB_NAME . '`';
        $result = @MainWPChildDB::_query($sql, $wpdb->dbh);        
        if (@MainWPChildDB::num_rows($result) && @MainWPChildDB::is_result($result))
        {       
            while ($row = MainWPChildDB::fetch_array($result))
            {  
                if (strpos($row['Name'], $table_prefix) !== false) {                                       
                    $sql = 'OPTIMIZE TABLE ' . $row['Name'];
                    MainWPChildDB::_query($sql, $wpdb->dbh);
                }
            }
        }
    }

    function maintenance_alert_404()
    {
        if (!is_404()) {
            return;
        }
        $email = get_option('mainwp_maintenance_opt_alert_404_email');

        if(empty($email) || !preg_match("/^[a-zA-Z0-9_.+-]+@[a-zA-Z0-9-]+\.[a-zA-Z0-9-.]+$/is", $email))
          return;

        // set status
        header("HTTP/1.1 404 Not Found");
        header("Status: 404 Not Found");

        // site info
        $blog  = get_bloginfo('name');
        $site  = get_bloginfo('url') . '/';
        $from_email  = get_bloginfo('admin_email');
        
        // referrer
        if (isset($_SERVER['HTTP_REFERER'])) {
                $referer = MainWPHelper::clean($_SERVER['HTTP_REFERER']);
        } else {
                $referer = "undefined";
        }
        $protocol = isset($_SERVER['HTTPS']) && strcasecmp($_SERVER['HTTPS'], 'off') ? 'https://' : 'http://';
        // request URI
        if (isset($_SERVER['REQUEST_URI']) && isset($_SERVER["HTTP_HOST"])) {
                $request = MainWPHelper::clean($protocol . $_SERVER["HTTP_HOST"] . $_SERVER["REQUEST_URI"]);
        } else {
                $request = "undefined";
        }
        // query string
        if (isset($_SERVER['QUERY_STRING'])) {
                $string = MainWPHelper::clean($_SERVER['QUERY_STRING']);
        } else {
                $string = "undefined";
        }
        // IP address
        if (isset($_SERVER['REMOTE_ADDR'])) {
                $address = MainWPHelper::clean($_SERVER['REMOTE_ADDR']);
        } else {
                $address = "undefined";
        }
        // user agent
        if (isset($_SERVER['HTTP_USER_AGENT'])) {
                $agent = MainWPHelper::clean($_SERVER['HTTP_USER_AGENT']);
        } else {
                $agent = "undefined";
        }
        // identity
        if (isset($_SERVER['REMOTE_IDENT'])) {
                $remote = MainWPHelper::clean($_SERVER['REMOTE_IDENT']);
        } else {
                $remote = "undefined";
        }
        // log time
        $time = MainWPHelper::clean(date("F jS Y, h:ia", time()));

        $mail = "<div>" . "TIME: "            . $time    . "</div>" . 
                "<div>" . "*404: "            . $request . "</div>" . 
                "<div>" . "SITE: "            . $site    . "</div>" .                 
                "<div>" . "REFERRER: "        . $referer . "</div>" . 
                "<div>" . "QUERY STRING: "    . $string  . "</div>" . 
                "<div>" . "REMOTE ADDRESS: "  . $address . "</div>" . 
                "<div>" . "REMOTE IDENTITY: " . $remote  . "</div>" . 
                "<div>" . "USER AGENT: "      . $agent   . "</div>"; 
        $mail = '<div>404 alert</div>
                <div></div>' . $mail;              
        wp_mail($email, 'MainWP - 404 Alert: ' . $blog , MainWPHelper::formatEmail($email, $mail), array('From: "'.$from_email.'" <'.$from_email.'>', 'content-type: text/html'));

    }
    
    public function keyword_links_action() {
        MainWPKeywordLinks::Instance()->action();
    }
	
    public function branding_child_plugin() {		
        MainWPChildBranding::Instance()->action();
    }
    
    public function code_snippet() {    
        $action = $_POST['action'];
        $information = array('status' => 'FAIL');  
        if ($action === 'run_snippet' || $action === 'save_snippet') {
            if (!isset($_POST['code'])) 
                 MainWPHelper::write($information);
        }
        $code = stripslashes($_POST['code']);  
        if ($action === 'run_snippet') {            
            $return = $this->execute_snippet($code);
            if (is_array($return) && isset($return['result']) && $return['result'] === 'SUCCESS')
                $information['status'] = 'SUCCESS';                
            $information['result'] = isset($return['output']) ? $return['output'] : "";
        } else if ($action === 'save_snippet') {
            $type = $_POST['type'];
            $slug = $_POST['slug'];
            $snippets = get_option('mainwp_ext_code_snippets');
           
            if (!is_array($snippets))
                $snippets = array();
            
            if ($type === 'C') {// save into wp-config file
                if (false !== $this->snippetUpdateWPConfig("save", $slug, $code))
                    $information['status'] = 'SUCCESS';     
            } else {
                $snippets[$slug] = $code;
                if (MainWPHelper::update_option('mainwp_ext_code_snippets', $snippets)) {
                    $information['status'] = 'SUCCESS';  
                }
            }
            MainWPHelper::update_option('mainwp_ext_snippets_enabled', true);
        } else if ($action === 'delete_snippet') {
            $type = $_POST['type'];
            $slug = $_POST['slug'];
            $snippets = get_option('mainwp_ext_code_snippets');  
            
            if (!is_array($snippets))
                $snippets = array();
            if ($type === "C") {// delete in wp-config file
                if (false !== $this->snippetUpdateWPConfig("delete", $slug))
                    $information['status'] = 'SUCCESS';  
            } else {                
                if(isset($snippets[$slug])) {                    
                    unset($snippets[$slug]);           
                    if (MainWPHelper::update_option('mainwp_ext_code_snippets', $snippets)) {
                        $information['status'] = 'SUCCESS';  
                    }
                }
                else 
                    $information['status'] = 'SUCCESS';             
            }
        }
        MainWPHelper::write($information); 
    }
    
    public function snippetUpdateWPConfig($action, $slug, $code = "")
    {
        $wpConfig = file_get_contents(ABSPATH . 'wp-config.php');        
        if ($action === "delete") {
            $wpConfig = preg_replace('/' . PHP_EOL .'{1,2}\/\*\*\*snippet_' . $slug. '\*\*\*\/(.*)\/\*\*\*end_' . $slug . '\*\*\*\/' . PHP_EOL . '/is', '', $wpConfig);
        } else if ($action === "save") {
            $wpConfig = preg_replace('/(\$table_prefix *= *[\'"][^\'|^"]*[\'"] *;)/is', '${1}' . PHP_EOL . PHP_EOL . '/***snippet_' . $slug. '***/' . PHP_EOL . $code . PHP_EOL . '/***end_' . $slug . '***/' . PHP_EOL, $wpConfig);
        }     
        file_put_contents(ABSPATH . 'wp-config.php', $wpConfig);
    }
    
    function run_saved_snippets() { 
        $action = null;        
        if (isset($_POST['action']))
            $action = $_POST['action'];
        
        if ($action === "run_snippet" || $action === "save_snippet" || $action === "delete_snippet")
                return; // do not run saved snippets if in do action snippet     
        
        if (get_option('mainwp_ext_snippets_enabled')) {
            $snippets = get_option('mainwp_ext_code_snippets');              
            if (is_array($snippets) && count($snippets) > 0) {
                foreach($snippets as $code) {
                    $this->execute_snippet($code);
                }
            }
        }
    }
    function execute_snippet($code) {
        ob_start();
        $result = eval ($code);
        $output = ob_get_contents();  
        ob_end_clean();
        $return = array('output' => $output);
        if ($result !== false)
            $return['result'] = 'SUCCESS';
        return $return;
    }
    
    function uploader_action() {
        $file_url = base64_decode ($_POST['url']);
        $path = $_POST['path'];
        $filename = $_POST['filename'];
        $information = array();
        
        if (empty($file_url) || empty($path)) {
            MainWPHelper::write($information); 
            return;
        }

        if (strpos($path, "wp-content") === 0) {
            $path = basename(WP_CONTENT_DIR) . substr($path, 10);
        } else if (strpos($path, "wp-includes") === 0) {
            $path = WPINC . substr($path, 11);
        }

        if ($path === '/') {
            $dir = ABSPATH;
        } else {
            $path = str_replace(' ', '-', $path);
            $path = str_replace('.', '-', $path);            
            $dir = ABSPATH . $path;
        }
        
        if (!file_exists($dir)) {
            if (FALSE === @mkdir($dir, 0777, true)) {
                $information['error'] = 'ERRORCREATEDIR';
                MainWPHelper::write($information); 
                return;
            }                    
        }
        
        try
        {
            $upload = MainWPHelper::uploadFile($file_url, $dir, $filename);
            if ($upload != null)
            {                    
                $information['success'] = true;
            }
        }
        catch (Exception $e)
        {
            $information['error'] = $e->getMessage();            
        } 
        MainWPHelper::write($information);
    }    
    
    function wordpress_seo() {        
        MainWPWordpressSEO::Instance()->action();                
    }
    
    function client_report() {        
        MainWPClientReport::Instance()->action();                
    }
    
    function page_speed() {        
        MainWPChildPagespeed::Instance()->action();                
    }
      
    function woo_com_status() {        
        MainWPChildWooCommerceStatus::Instance()->action();                
    }
    
    function heatmaps() {         
        $need_update = true;        
        if (isset($_POST['heatMapsOverride']))
        {
            $override = $_POST['heatMapsOverride'] ? '1' : '0';
            $disable = $_POST['heatMapsDisable'] ? '1' : '0';
            if ($override == get_option('heatMapsIndividualOverrideSetting') && $disable == get_option('heatMapsIndividualDisable')) {
                $need_update = false;  
            } 
            if ($need_update) { 
                MainWPHelper::update_option('heatMapsIndividualOverrideSetting', $override);             
                MainWPHelper::update_option('heatMapsIndividualDisable', $disable);            
                $this->update_htaccess(true);
            }
            MainWPHelper::write(array('result' => 'success'));
        }             
        MainWPHelper::write(array('result' => 'fail'));         
    }
    
    function links_checker() {        
        MainWPChildLinksChecker::Instance()->action();                
    }
    
    function wordfence() {        
        MainWPChildWordfence::Instance()->action();                
    }

    function ithemes() {
        MainWPChildIThemesSecurity::Instance()->action();
    }


    function updraftplus() {
        MainWPChildUpdraftplusBackups::Instance()->action();
    }

    function delete_backup()
    {
        $dirs = MainWPHelper::getMainWPDir('backup');
        $backupdir = $dirs[0];

        $file = $_REQUEST['del'];

        if (@file_exists($backupdir . $file))
        {
            @unlink($backupdir . $file);
        }

        MainWPHelper::write(array('result' => 'ok'));
    }
    
    function update_values()
    {
        $uniId = isset($_POST['uniqueId']) ? $_POST['uniqueId'] : "";
        MainWPHelper::update_option('mainwp_child_uniqueId', $uniId);  
        MainWPHelper::write(array('result' => 'ok'));
    }    
    
    function uploadFile($file, $offset = 0)
    {
        $dirs = MainWPHelper::getMainWPDir('backup');
        $backupdir = $dirs[0];

        header('Content-Description: File Transfer');

        header('Content-Description: File Transfer');
        if (MainWPHelper::endsWith($file, '.tar.gz'))
        {
            header('Content-Type: application/x-gzip');
            header("Content-Encoding: gzip'");
        }
        else
        {
            header('Content-Type: application/octet-stream');
        }
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($backupdir . $file));
        while (@ob_end_flush());
        $this->readfile_chunked($backupdir . $file, $offset);
    }

    function readfile_chunked($filename, $offset)
    {
        $chunksize = 1024; // how many bytes per chunk
        $handle = @fopen($filename, 'rb');
        if ($handle === false) return false;

        @fseek($handle, $offset);

        while (!@feof($handle))
        {
            $buffer = @fread($handle, $chunksize);
            echo $buffer;
            @ob_flush();
            @flush();
            $buffer = null;
        }
        return @fclose($handle);
    }
}
?>