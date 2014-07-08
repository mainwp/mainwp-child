<?php

class MainWPChildLinksChecker
{   
    
    public static $instance = null;   
    
    static function Instance() {
        if (MainWPChildLinksChecker::$instance == null) {
            MainWPChildLinksChecker::$instance = new MainWPChildLinksChecker();
        }
        return MainWPChildLinksChecker::$instance;
    }  
    
    public function __construct() {
        
    }
    
    public function action() {   
        $information = array();
        if (!defined('BLC_ACTIVE')) {
            $information['error'] = 'NO_BROKENLINKSCHECKER';
            MainWPHelper::write($information);
        }   
        if (isset($_POST['mwp_action'])) {
            switch ($_POST['mwp_action']) {                
                case "set_showhide":
                    $information = $this->set_showhide();                    
                    break;
                case "sync_data":
                    $information = $this->sync_data();                    
                    break;
            }        
        }
        MainWPHelper::write($information);
    }  
   
    public function init()
    {          
        if (get_option('mainwp_linkschecker_ext_enabled') !== "Y")
            return;
        
        if (get_option('mainwp_linkschecker_hide_plugin') === "hide")
        {
            add_filter('all_plugins', array($this, 'hide_plugin'));               
            add_filter('update_footer', array(&$this, 'update_footer'), 15);   
        }        
    }        
            
    public function hide_plugin($plugins) {
        foreach ($plugins as $key => $value)
        {
            $plugin_slug = basename($key, '.php');
            if ($plugin_slug == 'broken-link-checker')
                unset($plugins[$key]);
        }
        return $plugins;       
    }
 
    function update_footer($text){                
        ?>
           <script>
                jQuery(document).ready(function(){
                    jQuery('#menu-tools a[href="tools.php?page=view-broken-links"]').closest('li').remove();
                    jQuery('#menu-settings a[href="options-general.php?page=link-checker-settings"]').closest('li').remove();
                });        
            </script>
        <?php        
        return $text;
    }
    
    
     function set_showhide() {
        MainWPHelper::update_option('mainwp_linkschecker_ext_enabled', "Y");        
        $hide = isset($_POST['showhide']) && ($_POST['showhide'] === "hide") ? 'hide' : "";
        MainWPHelper::update_option('mainwp_linkschecker_hide_plugin', $hide);        
        $information['result'] = 'SUCCESS';
        return $information;
    }
    
    function sync_data($strategy = "") {  
        $information = array();           
        if (!defined('BLC_ACTIVE')) {
            $information['error'] = 'NO_BROKENLINKSCHECKER';
            MainWPHelper::write($information);
        }                                
        $data = array();
        $data['broken'] = self::get_sync_data('broken');
        $data['redirects'] = self::get_sync_data('redirects');
        $data['dismissed'] = self::get_sync_data('dismissed');
        $data['all'] = self::get_sync_data('all');        
        $information['data'] = $data;
        return $information;
    }
    
    static function get_sync_data($filter) {       
        global $wpdb;
        
        $all_filters = array(
            'broken' => '( broken = 1 )',
            'redirects' => '( redirect_count > 0 )',                
            'dismissed' => '( dismissed = 1 )',                
            'all' => '1'
        );
        
        $where = $all_filters[$filter];
        if (empty($where))
            return 0;
        
        $q = "  SELECT COUNT(*)
                FROM (	SELECT 0
                        FROM 
                        {$wpdb->prefix}blc_links AS links                         
                        WHERE $where
                GROUP BY links.link_id) AS foo";

        return $wpdb->get_var($q);       
    }

}

