<?php

class MainWPChildPagespeed
{   
    
    public static $instance = null;   
    
    static function Instance() {
        if (MainWPChildPagespeed::$instance == null) {
            MainWPChildPagespeed::$instance = new MainWPChildPagespeed();
        }
        return MainWPChildPagespeed::$instance;
    }  
    
    public function __construct() {
        add_action('mainwp_child_deactivation', array($this, 'child_deactivation'));
    }
    
    public function action() {   
        $information = array();
        if (!defined('GPI_ACTIVE')) {
            $information['error'] = 'NO_GOOGLEPAGESPEED';
            MainWPHelper::write($information);
        }   
        if (isset($_POST['mwp_action'])) {
            switch ($_POST['mwp_action']) {                
                case "save_settings":
                    $information = $this->save_settings();
                    break;
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
   
    public function child_deactivation()
    {
        if ($sched = wp_next_scheduled('mainwp_child_pagespeed_cron_check')) {
            wp_unschedule_event($sched, 'mainwp_child_pagespeed_cron_check');
        }
    }
    
    public function init()
    {          
        if (get_option('mainwp_pagespeed_ext_enabled') !== "Y")
            return;
        
        if (get_option('mainwp_pagespeed_hide_plugin') === "hide")
        {
            add_filter('all_plugins', array($this, 'hide_plugin'));   
            //add_action('admin_menu', array($this, 'hide_menu'), 999);
            add_filter('update_footer', array(&$this, 'update_footer'), 15);   
        }
        $this->init_cron();        
    }        
    
    public function init_cron() {        
        add_action('mainwp_child_pagespeed_cron_check', array('MainWPChildPagespeed', 'pagespeed_cron_check'));            
        if (($sched = wp_next_scheduled('mainwp_child_pagespeed_cron_check')) == false)
        {            
            wp_schedule_event(time(), 'daily', 'mainwp_child_pagespeed_cron_check');                            
        }  
    }    
    
    public static function pagespeed_cron_check() {
        $count = get_option("mainwp_child_pagespeed_count_checking");
        if ($count >= 7) {
            $recheck = true;
            $count = 0;            
        } else {
            $recheck = false;
            $count++;
        }        
        update_option('mainwp_child_pagespeed_count_checking', $count);
        
        $worker_args = array(
            array(), false, $recheck
        );
        wp_schedule_single_event( time(), 'googlepagespeedinsightschecknow', $worker_args );
    }
            
    public function hide_plugin($plugins) {
        foreach ($plugins as $key => $value)
        {
            $plugin_slug = basename($key, '.php');
            if ($plugin_slug == 'google-pagespeed-insights')
                unset($plugins[$key]);
        }
        return $plugins;       
    }
    
//    public function hide_menu() {
//        global $submenu;     
//        if (isset($submenu['tools.php'])) {
//            foreach($submenu['tools.php'] as $key => $menu) {
//                if ($menu[2] == 'google-pagespeed-insights') {
//                    unset($submenu['tools.php'][$key]);
//                    break;
//                }
//            }
//        }
//    }   
    
      function update_footer($text){                
        ?>
           <script>
                jQuery(document).ready(function(){
                    jQuery('#menu-tools a[href="tools.php?page=google-pagespeed-insights"]').closest('li').remove();
                });        
            </script>
        <?php        
        return $text;
    }
    
    
     function set_showhide() {
        MainWPHelper::update_option('mainwp_pagespeed_ext_enabled', "Y", 'yes');
        $hide = isset($_POST['showhide']) && ($_POST['showhide'] === "hide") ? 'hide' : "";
        MainWPHelper::update_option('mainwp_pagespeed_hide_plugin', $hide);        
        $information['result'] = 'SUCCESS';
        return $information;
    }
    
    function save_settings() {
        MainWPHelper::update_option('mainwp_pagespeed_ext_enabled', "Y", 'yes');
        $current_values = get_option('gpagespeedi_options');
        if (is_array($current_values) && $current_values['last_run_finished'] == false)
            return array('result' => 'RUNNING');
        
        $settings = $_POST['settings'];
        $settings = unserialize(base64_decode($settings));
        
        if (is_array($settings)) {            
            
            if (isset($settings['api_key']) && !empty($settings['api_key']))                
                $current_values['google_developer_key'] = $settings['api_key'];
            
            if (isset($settings['response_language']))                
                $current_values['response_language'] = $settings['response_language'];
            
            if (isset($_POST['strategy']))                
                $current_values['strategy'] = $_POST['strategy'];
                        
            if (isset($settings['max_execution_time']))                
                $current_values['max_execution_time'] = $settings['max_execution_time'];
            
            if (isset($settings['delay_time']))                
                $current_values['sleep_time'] = $settings['delay_time'];    
            
            if (isset($settings['log_exception']))                
                $current_values['log_api_errors'] = ($settings['log_exception']) ? true : false;  
            
            if (isset($settings['scan_technical']))                
                $current_values['scan_method'] = $settings['scan_technical'];  
            
            if (isset($settings['report_expiration']))                
                $current_values['recheck_interval'] = $settings['report_expiration'];  
            
            if (isset($settings['check_report'])) {
                if (is_array($settings['check_report'])) {                            
                    $current_values['check_pages'] = in_array('page', $settings['check_report']) ? true : false;      
                    $current_values['check_posts'] = in_array('post', $settings['check_report']) ? true : false;      
                    $current_values['check_categories'] = in_array('category', $settings['check_report']) ? true : false;      
                } else {
                    $current_values['check_pages'] = $current_values['check_posts'] = $current_values['check_categories'] = false;
                }
            }
          
            if (isset($settings['delete_data']) && !empty($settings['delete_data'])) {
                $this->delete_data($settings['delete_data']);
            }
  
            if (update_option( 'gpagespeedi_options', $current_values ))
                $information['result'] = 'SUCCESS';
            else 
                $information['result'] = 'NOTCHANGE';           
        }   
        
        $strategy = $current_values['strategy'];
        
        $result = $this->sync_data($strategy);        
        $information['data'] = $result['data'];
        return $information;
    }
    
    function sync_data($strategy = "") {
        if (empty($strategy))
            $strategy = "both";
        $current_values = get_option('gpagespeedi_options');        
                    
        if (is_array($current_values) && $current_values['last_run_finished'] == false)
            return array('result' => 'RUNNING');
        
        $information = array();        
        $bad_key = ($current_values['bad_api_key'] || empty($current_values['google_developer_key']));
        $data = array('bad_api_key' => $bad_key );   
        
        if ($strategy == "both" || $strategy == "desktop") {
            $result = self::cal_pagespeed_data('desktop');
            $data['desktop_score'] = $result['average_score'];
            $data['desktop_total_pages'] = $result['total_pages'];
            $data['desktop_last_modified'] = $result['last_modified'];
        }
        if ($strategy == "both" || $strategy == "mobile") {
            $result = self::cal_pagespeed_data('mobile');
            $data['mobile_score'] = $result['average_score'];
            $data['mobile_total_pages'] = $result['total_pages'];
            $data['mobile_last_modified'] = $result['last_modified'];
        }
        
        $information['data'] = $data;
        return $information;
    }
    
    static function cal_pagespeed_data($strategy) {
        global $wpdb;        
        if ( !defined('GPI_DIRECTORY') )
            return 0;
        
        if ($strategy !== "desktop" && $strategy !== "mobile")
            return 0;
        
        require_once( GPI_DIRECTORY . '/includes/helper.php' );
        
        $options = get_option('gpagespeedi_options');
        
        $score_column = $strategy . '_score';
        $page_stats_column = $strategy . '_page_stats';        
        require_once( ABSPATH . 'wp-admin/includes/template.php' );
        require_once( GPI_DIRECTORY . '/core/init.php' );      
        $GPI_ListTable = new GPI_List_Table();
        
        $data_typestocheck = $GPI_ListTable->getTypesToCheck('all');
        
        $gpi_page_stats = $wpdb->prefix . 'gpi_page_stats';
        if(!empty($data_typestocheck)) {

            $allpagedata =  $wpdb->get_results(
                                $wpdb->prepare(
                                    "SELECT ID, URL, $score_column, $page_stats_column
                                    FROM $gpi_page_stats
                                    WHERE ($data_typestocheck[0])",
                                    $data_typestocheck[1]
                                ),
                                ARRAY_A 
                            );
        } else {
            $allpagedata = array();
        }

        $reports_typestocheck = $GPI_ListTable->getTypesToCheck('all');
        $gpi_page_reports = $wpdb->prefix . 'gpi_page_reports';

        if(!empty($reports_typestocheck)) {

            $allpagereports =   $wpdb->get_results(
                                    $wpdb->prepare( 
                                        "SELECT     r.rule_key, r.rule_name, r.rule_impact
                                        FROM        $gpi_page_stats d
                                        INNER JOIN  $gpi_page_reports r 
                                                    ON r.page_id = d.ID
                                                    AND r.rule_impact > 0
                                                    AND r.strategy = '$strategy'
                                        WHERE       ($reports_typestocheck[0])",
                                        $reports_typestocheck[1]
                                    ),
                                    ARRAY_A
                                ); 
        } else {
            $allpagereports = array();
        }
        
        $total_pages = count($allpagedata);
        $total_scores = 0;
        $average_score = 0;
        
        if(!empty($total_pages) && !empty($allpagereports)) {

            foreach($allpagedata as $key => $pagedata)
            {
                $total_scores = $total_scores + $pagedata[$score_column];
            }

            $average_score = number_format($total_scores / $total_pages);
        }
        
        // Not Null check for Report List scores
        switch($strategy) {

//            case 'both':
//                $nullcheck = 'desktop_score IS NOT NULL AND mobile_score IS NOT NULL';
//                break;

            case 'mobile':
                $nullcheck = 'mobile_score IS NOT NULL';
                $_select = " max(mobile_last_modified) as last_modified ";
                break;

            case 'desktop':
                $nullcheck = 'desktop_score IS NOT NULL';
                $_select = " max(desktop_last_modified) as last_modified ";
                break;

        }

        // Get our Data
        if(!is_null($reports_typestocheck)) {
            $gpi_page_stats = $wpdb->prefix . 'gpi_page_stats';          
            $data = $wpdb->get_results(
                        $wpdb->prepare(
                            "SELECT $_select
                            FROM $gpi_page_stats
                            WHERE ($reports_typestocheck[0])
                            AND $nullcheck",
                            $reports_typestocheck[1]
                        ),                    
                        ARRAY_A 
                    );
        }                
        return array('last_modified' => is_array($data[0]) && isset($data[0]['last_modified']) ? $data[0]['last_modified'] : 0, 
                    'average_score' => $average_score,
                    'total_pages' => $total_pages);
    }
    
    function delete_data($what) {
        global $wpdb;
        $gpi_page_stats = $wpdb->prefix . 'gpi_page_stats';
        $gpi_page_reports = $wpdb->prefix . 'gpi_page_reports';
        $gpi_page_blacklist = $wpdb->prefix . 'gpi_page_blacklist';

        if($what == 'purge_reports') {
            $wpdb->query("TRUNCATE TABLE $gpi_page_stats");
            $wpdb->query("TRUNCATE TABLE $gpi_page_reports");
        } elseif($what == 'purge_everything') {
            $wpdb->query("TRUNCATE TABLE $gpi_page_stats");
            $wpdb->query("TRUNCATE TABLE $gpi_page_reports");
            $wpdb->query("TRUNCATE TABLE $gpi_page_blacklist");
        }
    }  
}

