<?php

class MainWPClientReport
{   
    public static $instance = null;   
        
    static function Instance() {
        if (MainWPClientReport::$instance == null) {
            MainWPClientReport::$instance = new MainWPClientReport();
        }
        return MainWPClientReport::$instance;
    }    
    
    
    public function __construct() {
        global $wpdb;
        add_action('mainwp_child_deactivation', array($this, 'child_deactivation'));
        
    }
        
    public static function  init() {                
        add_filter('wp_stream_connectors', array('MainWPClientReport', 'init_stream_connectors'), 10, 1);   
    }
    
    public function child_deactivation()
    {
       
    }
    
    public static function init_stream_connectors($classes) {
        $connectors = array(
            'Backups',
            'Sucuri',                  
        );      
        
        foreach ( $connectors as $connector ) {                
                $class     = "MainWPStreamConnector$connector";
                $classes[] = $class;
        }          
        return $classes;
    }
    
    public function action() {   
        $information = array();
        if (!function_exists('wp_stream_query')) {
            $information['error'] = 'NO_STREAM';
            MainWPHelper::write($information);
        }   
        if (isset($_POST['mwp_action'])) {
            switch ($_POST['mwp_action']) {
                case "save_sucuri_stream":
                    $information = $this->save_sucuri_stream();
                break; 
                case "save_backup_stream":
                    $information = $this->save_backup_stream();
                break;
                case "get_stream":
                    $information = $this->get_stream();
                break; 
                case "set_showhide":
                    $information = $this->set_showhide();
                break;
            }        
        }
        MainWPHelper::write($information);
    }  
    
    public function save_sucuri_stream() {        
        do_action("mainwp_sucuri_scan", $_POST['result'], $_POST['scan_status']);
        return true;
    }    
    
    public function save_backup_stream() {
        do_action("mainwp_backup", $_POST['destination'] , $_POST['message'], $_POST['size'], $_POST['status'], $_POST['type']);
        return true;
    }
    
    public function save_ga_stream() {
        do_action("mainwp_ga");
        return true;
    }    
    
    public function get_stream() {        
        // Filters
        $allowed_params = array(
                'connector',
                'context',
                'action',
                'author',
                'author_role',
                'object_id',
                'search',
                'date',
                'date_from',
                'date_to',
                'record__in',
                'blog_id',
                'ip',
        );
        
        $sections = isset($_POST['sections']) ? unserialize(base64_decode($_POST['sections'])) : array();
        if (!is_array($sections))
            $sections = array();
        //return $sections;
        
        $other_tokens = isset($_POST['other_tokens']) ? unserialize(base64_decode($_POST['other_tokens'])) : array();
        if (!is_array($other_tokens))
            $other_tokens = array();
        //return $other_tokens;
        
        unset($_POST['sections']);
        unset($_POST['other_tokens']);
        
        $args = array();  
        foreach ( $allowed_params as $param ) {                                            
                $paramval = wp_stream_filter_input( INPUT_POST, $param );                
                if ( $paramval || '0' === $paramval ) {
                        $args[ $param ] = $paramval;
                }
        }
        
        foreach ( $args as $arg => $val ) { 
            if (!in_array($arg, $allowed_params)) {
                unset($args[$arg]);
            }                
        }        
        if (isset($args['date_from']))
            $args['date_from'] = date("Y-m-d H:i:s", $args['date_from']);
        
        if (isset($args['date_to']))
            $args['date_to'] = date("Y-m-d H:i:s", $args['date_to']);
        
        $args['records_per_page'] = -1;
        
        $records = wp_stream_query( $args );
        if (!is_array($records)) 
            $records = array();
        //return $records;
        //$other_tokens_data = $this->get_other_tokens_data($records, $other_tokens);
         
        if (isset($other_tokens['header']) && is_array($other_tokens['header'])) {
             $other_tokens_data['header'] = $this->get_other_tokens_data($records, $other_tokens['header']);
        }
        
        if (isset($other_tokens['body']) && is_array($other_tokens['body'])) {
             $other_tokens_data['body'] = $this->get_other_tokens_data($records, $other_tokens['body']);
        }
        
        if (isset($other_tokens['footer']) && is_array($other_tokens['footer'])) {
             $other_tokens_data['footer'] = $this->get_other_tokens_data($records, $other_tokens['footer']);
        }
         
        $sections_data = array();    
        
        if (isset($sections['header']) && is_array($sections['header'])) {
            foreach($sections['header'] as $sec => $tokens) {
                $sections_data['header'][$sec] = $this->get_section_loop_data($records, $tokens, $sec);
            }
        }
        if (isset($sections['body']) && is_array($sections['body'])) {
            foreach($sections['body'] as $sec => $tokens) {
                $sections_data['body'][$sec] = $this->get_section_loop_data($records, $tokens, $sec);
            }
        }
        if (isset($sections['footer']) && is_array($sections['footer'])) {
            foreach($sections['footer'] as $sec => $tokens) {
                $sections_data['footer'][$sec] = $this->get_section_loop_data($records, $tokens, $sec);
            }
        }
            
        $information = array('other_tokens_data' => $other_tokens_data,
                             'sections_data' => $sections_data );            
        
        return $information;
    }
    
    function get_other_tokens_data($records, $tokens) {
        $convert_context_name = array(
            "comment" => "comments",
            "plugin" => "plugins",
            "profile" => "profiles",
            "session" => "sessions",
            "setting" => "settings",
            "setting" => "settings",
            "theme" => "themes",
            "posts" => "post",
            "pages" => "page",
            "user" => "users",
            "widget" => "widgets",
            "menu" => "menus",
            "backups" => "mainwp_backups",
            "backup" => "mainwp_backups", 
            "sucuri" => "mainwp_sucuri",
        );
               
        $convert_action_name = array(
            "restored" => "untrashed",
            "spam" => "spammed",
            "backups" => "mainwp_backup",
            "backup" => "mainwp_backup"
        );
        
        $allowed_data = array(                             
            'count'          
        );
        
        $token_values = array();
        
        if (!is_array($tokens))
            $tokens = array();
        
        foreach ($tokens as $token) {
               $str_tmp = str_replace(array('[', ']'), "", $token);
               $array_tmp = explode(".", $str_tmp);  

               if (is_array($array_tmp)) {
                   $context = $action = $data = "";
                   if (count($array_tmp) == 2) {
                       list($context, $data) = $array_tmp;  
                   } else if (count($array_tmp) == 3) {
                       list($context, $action, $data) = $array_tmp;                        
                   }       

                    $context = isset($convert_context_name[$context]) ? $convert_context_name[$context] : $context;
                    if (isset($convert_action_name[$action])) {
                        $action = $convert_action_name[$action];
                    }

                    switch ($data) {                      
                       case "count": 
                           $count = 0;
                           foreach ($records as $record) {    
                                if ($context == "themes" && $action == "edited") {
                                    if ($record->action !== "updated" || $record->connector !== "editor")
                                        continue;                                    
                                } else if ($context == "users" && $action == "updated") {
                                    if ($record->context !== "profiles" || $record->connector !== "users")
                                        continue;                                    
                                } else if ($context == "mainwp_backups") {
                                    if ($record->context !== "mainwp_backups") {
                                        continue;
                                    }
                                } else if ($context == "mainwp_sucuri") {
                                    if ($record->context !== "mainwp_sucuri") {
                                        continue;
                                    }
                                } else { 
                                    if ($action != $record->action)
                                        continue;

                                    if ($context == "comments" && $record->context != "page" && $record->context != "post")
                                        continue;
                                    else if ($context == "media" && $record->connector != "media")
                                        continue;
                                    else if ($context == "widgets" && $record->connector != "widgets")
                                        continue; 
                                    else if ($context == "menus" && $record->connector != "menus")
                                        continue; 

                                    if ($context !== "comments" && $context !== "media" && 
                                        $context !== "widgets" && $context !== "menus" &&
                                        $record->context != $context)
                                        continue;
                                }
                                
                                $count++;
                           }     
                           $token_values[$token] = $count;                         
                           break;                
                   }            
               } 
        }            
        return $token_values;        
    }
    
    function get_section_loop_data($records, $tokens, $section) {
        
        $convert_context_name = array(
            "comment" => "comments",
            "plugin" => "plugins",
            "profile" => "profiles",
            "session" => "sessions",
            "setting" => "settings",            
            "theme" => "themes",            
            "posts" => "post",
            "pages" => "page",
            "widget" => "widgets",
            "menu" => "menus",
            "backups" => "mainwp_backups",
            "backup" => "mainwp_backups",
            "sucuri" => "mainwp_sucuri",
        );
        
        $convert_action_name = array(
            "restored" => "untrashed",
            "spam" => "spammed",
            "backup" => "mainwp_backup"                      
        );
        
        $some_allowed_data = array(            
            'name',
            'title',
            'oldversion',
            'currentversion',
            'date',            
            'count',
            'author',
            'old.version',
            'current.version'
        );
        
        $context = $action = "";        
        $str_tmp = str_replace(array('[', ']'), "", $section);
        $array_tmp = explode(".", $str_tmp);        
        if (is_array($array_tmp)) {
            if (count($array_tmp) == 2)
                list($str1, $context) = $array_tmp;
            else if (count($array_tmp) == 3)
                list($str1, $context, $action) = $array_tmp;
        }
        
        $context = isset($convert_context_name[$context]) ? $convert_context_name[$context] : $context;
        $action = isset($convert_action_name[$action]) ? $convert_action_name[$action] : $action;
            
        $loops = array();
        $loop_count = 0;
        
        foreach ($records as $record) {     
            $theme_edited = $users_updated = false;            
            if ($context == "themes" && $action == "edited") {
                if ($record->action !== "updated" || $record->connector !== "editor")
                    continue;
                else 
                    $theme_edited = true;                    
            } else if ($context == "users" && $action == "updated") {
                if ($record->context !== "profiles" || $record->connector !== "users")
                    continue;
                else 
                    $users_updated = true; 
            } else if ($context == "mainwp_backups") {
                if ($record->context !== "mainwp_backups") {
                    continue;
                }
            } else if ($context == "mainwp_sucuri") {
                if ($record->context !== "mainwp_sucuri") {
                    continue;
                }
            } else {            
                if ($action !== $record->action)
                    continue;        

                if ($context === "comments" && $record->context !== "page" && $record->context !== "post")
                    continue;
                else if ($context === "media" && $record->connector !== "media")
                    continue;
                else if ($context === "widgets" && $record->connector !== "widgets")
                    continue;      
                else if ($context === "menus" && $record->connector !== "menus")
                    continue;
//                else if ($context === "themes" && $record->connector !== "themes")
//                    continue;                            
                
                if ($context !== "comments" && $context !== "media" && 
                    $context !== "widgets" && $context !== "menus" &&                     
                    $record->context !== $context)
                    continue;   
            }
            
            $token_values = array();
            
            foreach ($tokens as $token) {
                $data = "";
                $token_name = str_replace(array('[', ']'), "", $token);
                $array_tmp = explode(".", $token_name);                                         

                if ($token_name == "user.name") {
                    $data = "display_name";
                } else {
                    if (count($array_tmp) == 1) {
                        list($data) = $array_tmp;  
                    } else if (count($array_tmp) == 2) {
                        list($str1, $data) = $array_tmp;                        
                    } else if (count($array_tmp) == 3) {
                        list($str1, $str2, $data) = $array_tmp;                        
                    } 
                    
                    if ($data == "version") {
                        if ($str2 == "old")
                            $data = "old_version";
                        else if ($str2 == "current")
                            $data = "new_version";                            
                    }                
                }
                
                if ($data == "role") 
                    $data = "roles";    
                                
                switch ($data) {
                    case "date":
                        $token_values[$token] = MainWPHelper::formatTimestamp(strtotime($record->created));                            
                        break;
                    case "area":                        
                        $data = "sidebar_name";  
                        $token_values[$token] = $this->get_stream_meta_data($record->ID, $data);                      
                        break;
                    case "name":   
                    case "version":  
                    case "old_version":
                    case "new_version":                        
                    case "display_name":                            
                    case "roles":            
                        if ($data == "name") {
                            if ($theme_edited)
                                $data = "theme_name";
                            else if ($users_updated) {
                                $data = "display_name";
                            }
                        }
                        if ($data == "roles" && $users_updated) {
                            $user_info = get_userdata($record->object_id);
                            if ( !( is_object( $user_info ) && is_a( $user_info, 'WP_User' ) ) ) {                                
                                $roles = "";
                            } else {
                                $roles = implode(", ", $user_info->roles); 
                            }                                
                            $token_values[$token] = $roles;                                                                              
                        } else {                            
                            $token_values[$token] = $this->get_stream_meta_data($record->ID, $data);
                        }
                        break;
                    case "title":  
                        if ($context == "page" || $context == "post" || $context == "comments")
                            $data = "post_title";      
                        else if ($record->connector == "menus") {
                            $data = "name";      
                        }
                        $token_values[$token] = $this->get_stream_meta_data($record->ID, $data);                                                                                 
                        break;
                    case "author":   
                        $data = "author_meta";
                        $token_values[$token] = $this->get_stream_meta_data($record->ID, $data);                                                                                 
                        break; 
                    case "status":   // sucuri cases                         
                    case "webtrust":                       
                        if ($context == "mainwp_sucuri") {                           
                            $token_values[$token] = $this->get_stream_meta_data($record->ID, $data);                                                                                 
                        } else 
                            $token_values[$token] = $token; 
                        break;
                    case "destination":   // backup cases                         
                    case "type":   
                        if ($context == "mainwp_backups") {                           
                            $token_values[$token] = $this->get_stream_meta_data($record->ID, $data);                                                                                 
                        } else 
                            $token_values[$token] = $token; 
                        break;                    
                    default:   
                        $token_values[$token] = $token;                                                                                 
                        break;
                }                                
            
            } // foreach $tokens
            
            if (!empty($token_values)) {
                $loops[$loop_count] = $token_values;
                $loop_count++;
            }
        } // foreach $records
        return $loops;
    }
    
    function get_stream_meta_data($record_id, $data) {                 
        
        $meta_key = $data;
        
        global $wpdb;
        
        if (class_exists('WP_Stream_Install'))
            $prefix = WP_Stream_Install::$table_prefix;
        else
            $prefix = $wpdb->prefix;
        
	$sql    = "SELECT meta_value FROM {$prefix}stream_meta WHERE record_id = " . $record_id . " AND meta_key = '" . $meta_key . "'";
	$meta   = $wpdb->get_row( $sql );        
        
        $value = "";
        if (!empty($meta)) {
            $value = $meta->meta_value;
            if ($meta_key == "author_meta") {
                $value = unserialize($value);
                $value = $value['display_name'];
            }            
        }
        
        return $value;            
    }
    
    function set_showhide() {
        MainWPHelper::update_option('mainwp_creport_ext_branding_enabled', "Y");        
        $hide = isset($_POST['showhide']) && ($_POST['showhide'] === "hide") ? 'hide' : "";
        MainWPHelper::update_option('mainwp_creport_branding_stream_hide', $hide);        
        $information['result'] = 'SUCCESS';
        return $information;
    }
    
    public function creport_init()
    {  
        if (get_option('mainwp_creport_ext_branding_enabled') !== "Y")
            return;  
        
        if (get_option('mainwp_creport_branding_stream_hide') === "hide")
        {
            add_filter('all_plugins', array($this, 'creport_branding_plugin'));   
            add_action( 'admin_menu', array($this, 'creport_remove_menu'));
        }
    }    
    
    
    public function creport_branding_plugin($plugins) {
        foreach ($plugins as $key => $value)
        {
            $plugin_slug = basename($key, '.php');
            if ($plugin_slug == 'stream')
                unset($plugins[$key]);
        }
        return $plugins;       
    }
    
    public function creport_remove_menu() {
        remove_menu_page('wp_stream');  
    }    
}

