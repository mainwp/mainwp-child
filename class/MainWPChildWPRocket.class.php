<?php

class MainWPChildWPRocket
{   
    public static $instance = null;   
    
    public static function Instance() {        
        if (MainWPChildWPRocket::$instance == null) {
            MainWPChildWPRocket::$instance = new MainWPChildWPRocket();
        }
        return MainWPChildWPRocket::$instance;
    }    
    
    public function __construct() {                
        
    }
      
    public function init()
    {  
        if (get_option('mainwp_wprocket_ext_enabled') !== "Y")
            return;
        
        if (get_option('mainwp_wprocket_hide_plugin') === "hide")
        {
            add_filter('all_plugins', array($this, 'all_plugins'));   
            add_action('admin_menu', array($this, 'remove_menu'));            
            add_filter('site_transient_update_plugins', array(&$this, 'remove_update_nag')); 
            add_action('wp_before_admin_bar_render', array($this, 'wp_before_admin_bar_render'), 99);
            add_action( 'admin_init', array( $this, 'remove_notices' ));
        }        
    }
 
    function remove_notices() {
        $remove_hooks['admin_notices'] = array(
            'rocket_bad_deactivations' => 10,
            'rocket_warning_plugin_modification' => 10,
            'rocket_plugins_to_deactivate' => 10,
            'rocket_warning_using_permalinks' => 10,
            'rocket_warning_wp_config_permissions' => 10,
            'rocket_warning_advanced_cache_permissions' => 10,
            'rocket_warning_advanced_cache_not_ours' => 10,
            'rocket_warning_htaccess_permissions' => 10,
            'rocket_warning_config_dir_permissions' => 10,
            'rocket_warning_cache_dir_permissions' => 10,
            'rocket_warning_minify_cache_dir_permissions' => 10,
            'rocket_thank_you_license' => 10,
            'rocket_need_api_key' => 10,            
        );
        foreach($remove_hooks as $hook_name => $hooks) {
            foreach($hooks as $method => $priority) {                
                MainWPHelper::remove_filters_with_method_name($hook_name, $method, $priority);
            }
        }  
    }
    
    
    public function wp_before_admin_bar_render() {
        global $wp_admin_bar;
        $nodes = $wp_admin_bar->get_nodes();
        if (is_array($nodes)) {
            foreach($nodes as $node) {
                if ($node->parent == "wp-rocket" || ($node->id = "wp-rocket")) {
                    $wp_admin_bar->remove_node($node->id);
                }   
            }
        }        
    }
    
    function remove_update_nag($value) {
        if (isset($value->response['wp-rocket/wp-rocket.php']))
            unset($value->response['wp-rocket/wp-rocket.php']);        
        return $value;
    }
    
    public static function isActivated() {
        if (!defined('WP_ROCKET_VERSION') || !defined('WP_ROCKET_SLUG')) 
            return false;
        return true;
    }
   
    public function remove_menu() {
        global $submenu;                    
        if (isset($submenu['options-general.php'])) {
            foreach($submenu['options-general.php'] as $index => $item) {
                if ($item[2] == 'wprocket') {
                    unset($submenu['options-general.php'][$index]);
                    break;
                }
            }
        }
        $pos = stripos($_SERVER['REQUEST_URI'], 'options-general.php?page=wprocket');        
        if ($pos !== false) {
            wp_redirect(get_option('siteurl') . '/wp-admin/index.php'); 
            exit();
        }
    } 
    
    public function all_plugins($plugins) {
        foreach ($plugins as $key => $value)
        {
            $plugin_slug = basename($key, '.php');
            if ($plugin_slug == 'wp-rocket')
                unset($plugins[$key]);
        }
        return $plugins;       
    }
        
    public function action() {        
        $information = array();          
        if (!self::isActivated()) {
            $information['error'] = 'NO_WPROCKET';
            MainWPHelper::write($information);
        }   
        if (isset($_POST['mwp_action'])) {            
            MainWPHelper::update_option('mainwp_wprocket_ext_enabled', "Y"); 
            switch ($_POST['mwp_action']) {                               
                case "set_showhide":
                    $information = $this->set_showhide();
                break;               
                case "purge_cloudflare":
                    $information = $this->purge_cloudflare();
                break;
                case "purge_all":
                    $information = $this->purge_cache_all();
                break;
                case "preload_cache":
                    $information = $this->preload_cache();
                break;                
                case "save_settings":
                    $information = $this->save_settings();
                break;
            }        
        }
        MainWPHelper::write($information);
    }
  
    function set_showhide() {
        $hide = isset($_POST['showhide']) && ($_POST['showhide'] === "hide") ? 'hide' : "";
        MainWPHelper::update_option('mainwp_wprocket_hide_plugin', $hide);        
        $information['result'] = 'SUCCESS';
        return $information;
    }
    
    function purge_cloudflare() {              
        if (function_exists('rocket_purge_cloudflare')) {
            // Purge CloudFlare
            rocket_purge_cloudflare();
            return array('result' => 'SUCCESS');
        } else 
            return array('error' => 'function_not_exist');        
    }
    
    function purge_cache_all() {              
        if (function_exists('rocket_clean_domain') || function_exists('rocket_clean_minify')  || function_exists('create_rocket_uniqid')) {            
            // Remove all cache files
            rocket_clean_domain( );

            // Remove all minify cache files
            rocket_clean_minify();

            // Generate a new random key for minify cache file
            $options = get_option( WP_ROCKET_SLUG );
            $options['minify_css_key'] = create_rocket_uniqid();
            $options['minify_js_key'] = create_rocket_uniqid();
            remove_all_filters( 'update_option_' . WP_ROCKET_SLUG );
            update_option( WP_ROCKET_SLUG, $options );
            rocket_dismiss_box( 'rocket_warning_plugin_modification' );
            return array('result' => 'SUCCESS');
        } else 
            return array('error' => 'function_not_exist');     
    }
        
    function preload_cache() {              
        if (function_exists('run_rocket_bot')) {                        
            run_rocket_bot( 'cache-preload', '' );
            return array('result' => 'SUCCESS');
        } else 
            return array('error' => 'function_not_exist');   
    }  
   
    function save_settings() {           
        $options = unserialize(base64_decode($_POST['settings']));
        if (!is_array($options) || empty($options))
            return array('error' => 'INVALID_OPTIONS');
        
        $old_values = get_option( WP_ROCKET_SLUG );
        
        $defaults_fields = $this->get_rocket_default_options();        
        foreach($old_values as $field => $value) {
            if (!isset($defaults_fields[$field])) { // keep other options
                $options[$field] = $value;
            } 
        }
        
        remove_all_filters( 'update_option_' . WP_ROCKET_SLUG );
        update_option( WP_ROCKET_SLUG, $options );
        return array('result' => 'SUCCESS');
    }     
    
    
    function get_rocket_default_options()
    {
            return array(
    //                'secret_cache_key'         => $secret_cache_key,
                    'cache_mobile'             => 0,
                    'cache_logged_user'        => 0,
                    'cache_ssl'                => 0,
                    'cache_reject_uri'         => array(),
                    'cache_reject_cookies'     => array(),
                    'cache_reject_ua'          => array(),
                    'cache_query_strings'      => array(),
                    'cache_purge_pages'        => array(),
                    'purge_cron_interval'      => 24,
                    'purge_cron_unit'          => 'HOUR_IN_SECONDS',
                    'exclude_css'              => array(),
                    'exclude_js'               => array(),
                    'deferred_js_files'        => array(),
                    'deferred_js_wait'         => array(),
                    'lazyload'          	   => 0,
                    'lazyload_iframes'         => 0,
                    'minify_css'               => 0,
    //                'minify_css_key'           => $minify_css_key,
                    'minify_css_combine_all'   => 0,
                    'minify_js'                => 0,
    //                'minify_js_key'            => $minify_js_key,
                    'minify_js_in_footer'      => array(),
                    'minify_js_combine_all'    => 0,
                    'minify_google_fonts'      => 0,
                    'minify_html'              => 0,
                    'minify_html_inline_css'   => 0,
                    'minify_html_inline_js'    => 0,
                    'dns_prefetch'             => 0,
                    'cdn'                      => 0,
                    'cdn_cnames'               => array(),
                    'cdn_zone'                 => array(),
                    'cdn_ssl'                  => 0,
                    'cdn_reject_files'         => array(),
                    'do_cloudflare'		   	   => 0,
                    'cloudflare_email'		   => '',
                    'cloudflare_api_key'	   => '',
                    'cloudflare_domain'	   	   => '',
                    'cloudflare_devmode'	   => 0,
                    'cloudflare_auto_settings' => 0,
                    'cloudflare_old_settings'  => 0,
                    'do_beta'                  => 0,
            );	
    }


    
}

