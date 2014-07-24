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
        if (!defined('BLC_ACTIVE')  || !function_exists('blc_init')) {
            $information['error'] = 'NO_BROKENLINKSCHECKER';
            MainWPHelper::write($information);
        }             
        blc_init();        
        if (isset($_POST['mwp_action'])) {
            switch ($_POST['mwp_action']) {                
                case "set_showhide":
                    $information = $this->set_showhide();                    
                    break;
                case "sync_data":
                    $information = $this->sync_data();                    
                    break;
                case "edit_link":
                    $information = $this->edit_link();                    
                    break; 
                case "unlink":
                    $information = $this->unlink();                    
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
        $data = array();
        $data['broken'] = self::sync_counting_data('broken');
        $data['redirects'] = self::sync_counting_data('redirects');
        $data['dismissed'] = self::sync_counting_data('dismissed');
        $data['all'] = self::sync_counting_data('all');  
        $data['link_data'] = self::sync_link_data();          
        $information['data'] = $data;
        return $information;
    }
    
    static function sync_counting_data($filter) {       
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
        
        return blc_get_links(array('count_only' => true, 'where_expr' => $where));
    }
    
    static function sync_link_data() {        
        $links = blc_get_links(array('load_instances' => true));
        $get_fields = array(
            'link_id',
            'url',
            'being_checked',
            'last_check',
            'last_check_attempt',
            'check_count',
            'http_code',
            'request_duration',
            'timeout',
            'redirect_count',
            'final_url',
            'broken', 
            'first_failure',
            'last_success',
            'may_recheck',
            'false_positive',
            //'result_hash',
            'dismissed', 
            'status_text',
            'status_code',
            'log',
        );
        $return = "";
        $site_id = $_POST['site_id'];
        $blc_option = get_option('wsblc_options');
        if (is_array($links)) {
            foreach($links as $link) {
                $lnk = new stdClass();
                foreach($get_fields as $field) {
                    $lnk->$field = $link->$field;
                }
                
                if (!empty($link->post_date) ) {
                    $lnk->post_date = $link->post_date;   
                } 
                
                $days_broken = 0;
                if ( $link->broken ){
                        //Add a highlight to broken links that appear to be permanently broken
                        $days_broken = intval( (time() - $link->first_failure) / (3600*24) );
                        if ( $days_broken >= $blc_option['failure_duration_threshold'] ){
                                $lnk->permanently_broken = 1;
                                if ( $blc_option['highlight_permanent_failures'] ){
                                    $lnk->permanently_broken_highlight = 1;
                                }
                        }
                }
                $lnk->days_broken = $days_broken;
                if ( !empty($link->_instances) ){			
                    $instance = reset($link->_instances); 
                    $lnk->link_text = $instance->ui_get_link_text();                    
                    $lnk->count_instance = count($link->_instances);                    
                    $container = $instance->get_container(); /** @var blcContainer $container */
                    $lnk->container = $container;
                    
                    if ( !empty($container) && ($container instanceof blcAnyPostContainer) ) {                        
                        $lnk->container_type = $container->container_type;
                        $lnk->container_id = $container->container_id;
                    }
                    
                    $can_edit_text = false;
                    $can_edit_url = false;
                    $editable_link_texts = $non_editable_link_texts = array();
                    $instances = $link->_instances;
                    foreach($instances as $instance) {
                            if ( $instance->is_link_text_editable() ) {
                                    $can_edit_text = true;
                                    $editable_link_texts[$instance->link_text] = true;
                            } else {
                                    $non_editable_link_texts[$instance->link_text] = true;
                            }

                            if ( $instance->is_url_editable() ) {
                                    $can_edit_url = true;
                            }
                    }

                    $link_texts = $can_edit_text ? $editable_link_texts : $non_editable_link_texts;
                    $data_link_text = '';
                    if ( count($link_texts) === 1 ) {
                            //All instances have the same text - use it.
                            $link_text = key($link_texts);
                            $data_link_text = esc_attr($link_text);
                    }
                    $lnk->data_link_text =  $data_link_text;
                    $lnk->can_edit_url =  $can_edit_url;
                    $lnk->can_edit_text =  $can_edit_text;                    
		} else {
                    $lnk->link_text = "";
                    $lnk->count_instance = 0;
                }                
                $lnk->site_id = $site_id; 
                                
                $return[] = $lnk;            
            }
        } else 
            return "";
        
        return $return;
  
    }  
    
    function edit_link() {
        $information = array();     
        if (!current_user_can('edit_others_posts')){
             $information['error'] = 'NOTALLOW';
             return $information;             
        }
        //Load the link
        $link = new blcLink( intval($_POST['link_id']) );        
        if ( !$link->valid() ){
            $information['error'] = 'NOTFOUNDLINK'; // Oops, I can't find the link
            return $information;
        }

        //Validate the new URL.
        $new_url = stripslashes($_POST['new_url']);
        $parsed = @parse_url($new_url);
        if ( !$parsed ){
            $information['error'] = 'URLINVALID'; // Oops, the new URL is invalid!
            return $information;
        }

        $new_text = (isset($_POST['new_text']) && is_string($_POST['new_text'])) ? stripslashes($_POST['new_text']) : null;
        if ( $new_text === '' ) {
                $new_text = null;
        }
        if ( !empty($new_text) && !current_user_can('unfiltered_html') ) {
                $new_text = stripslashes(wp_filter_post_kses(addslashes($new_text))); //wp_filter_post_kses expects slashed data.
        }

        $rez = $link->edit($new_url, $new_text);
        if ( $rez === false ){
            $information['error'] = __('An unexpected error occurred!');
            return $information;
        } else {
                $new_link = $rez['new_link']; /** @var blcLink $new_link */
                $new_status = $new_link->analyse_status();
                $ui_link_text = null;
                if ( isset($new_text) ) {
                        $instances = $new_link->get_instances();
                        if ( !empty($instances) ) {
                                $first_instance = reset($instances);
                                $ui_link_text = $first_instance->ui_get_link_text();
                        }
                }

                $response = array(
                        'new_link_id' => $rez['new_link_id'],
                        'cnt_okay' => $rez['cnt_okay'],
                        'cnt_error' => $rez['cnt_error'],

                        'status_text' => $new_status['text'],
                        'status_code' => $new_status['code'],
                        'http_code'   => empty($new_link->http_code) ? '' : $new_link->http_code,

                        'url' => $new_link->url,
                        'link_text' => isset($new_text) ? $new_text : null,
                        'ui_link_text' => isset($new_text) ? $ui_link_text : null,

                        'errors' => array(),
                );
                //url, status text, status code, link text, editable link text


                foreach($rez['errors'] as $error){ /** @var $error WP_Error */
                        array_push( $response['errors'], implode(', ', $error->get_error_messages()) );
                }
                return $response;
        }
    }
    
    function unlink(){
        $information = array();
        if (!current_user_can('edit_others_posts')){
             $information['error'] = 'NOTALLOW';
             return $information;             
        }

        if ( isset($_POST['link_id']) ){
                //Load the link
                $link = new blcLink( intval($_POST['link_id']) );

                if ( !$link->valid() ){
                    $information['error'] = 'NOTFOUNDLINK'; // Oops, I can't find the link
                    return $information;
                }

                //Try and unlink it
                $rez = $link->unlink();

                if ( $rez === false ){
                    $information['error'] = 'UNDEFINEDERROR'; // An unexpected error occured!
                    return $information;
                } else {
                        $response = array(
                                'cnt_okay' => $rez['cnt_okay'],
                                'cnt_error' => $rez['cnt_error'],
                                'errors' => array(),
                        );
                        foreach($rez['errors'] as $error){ /** @var WP_Error $error */
                                array_push( $response['errors'], implode(', ', $error->get_error_messages()) );
                        }
                        return $response;
                }

        } else {
            $information['error'] = __("Error : link_id not specified"); 
            return $information;                
        }
    }

        
}

