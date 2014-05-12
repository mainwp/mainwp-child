<?php

class MainWPChildBranding
{
    public static $instance = null;
    protected $child_plugin_dir;
    protected $settings = null;

    static function Instance()
    {
        if (MainWPChildBranding::$instance == null)
        {
            MainWPChildBranding::$instance = new MainWPChildBranding();
        }
        return MainWPChildBranding::$instance;
    }

    public function __construct()
    {
        $this->child_plugin_dir = dirname(dirname(__FILE__));        
        add_action('mainwp_child_deactivation', array($this, 'child_deactivation'));
        
        $label = get_option("mainwp_branding_button_contact_label");
        if (!empty($label)) {
            $label = stripslashes($label);
        } else 
            $label = "Contact Support";
        
        $this->settings['contact_support_label'] = $label;
        $this->settings['extra_settings'] = get_option('mainwp_branding_extra_settings');
    }

    public static function admin_init()
    {
    }

    public function child_deactivation()
    {
        $dell_all = array('mainwp_branding_disable_change',
            'mainwp_branding_child_hide',
            'mainwp_branding_show_support',
            'mainwp_branding_support_email',
            'mainwp_branding_support_message',
            'mainwp_branding_remove_restore',
            'mainwp_branding_remove_setting',
            'mainwp_branding_remove_wp_tools',
            'mainwp_branding_remove_wp_setting',
            'mainwp_branding_remove_permalink',
            //'mainwp_branding_plugin_header', // don't remove header
            'mainwp_branding_button_contact_label',
            'mainwp_branding_send_email_message',
            'mainwp_branding_message_return_sender',
            'mainwp_branding_submit_button_title', 
            'mainwp_branding_extra_settings',
            'mainwp_branding_ext_enabled',
            );
        foreach ($dell_all as $opt)
        {
            delete_option($opt);
        }
    }


    public function action()
    {
        $information = array();
        switch ($_POST['action'])
        {
            case 'update_branding':
                $information = $this->update_branding();
                break;
        }
        MainWPHelper::write($information);
    }

    public function update_branding()
    {
        $information = array();
        $settings = unserialize(base64_decode($_POST['settings']));
        if (!is_array($settings))
            return $information;
        $current_extra_setting = $this->settings['extra_settings'];
        MainWPHelper::update_option('mainwp_branding_ext_enabled', "Y");
        $header = array('name' => $settings['child_plugin_name'],
            'description' => $settings['child_plugin_desc'],
            'author' => $settings['child_plugin_author'],
            'authoruri' => $settings['child_plugin_author_uri'],
            'pluginuri' => $settings['child_plugin_uri']);
        
        MainWPHelper::update_option('mainwp_branding_plugin_header', $header);
        MainWPHelper::update_option('mainwp_branding_support_email', $settings['child_support_email']);
        MainWPHelper::update_option('mainwp_branding_support_message', $settings['child_support_message']);
        MainWPHelper::update_option('mainwp_branding_remove_restore', $settings['child_remove_restore']);
        MainWPHelper::update_option('mainwp_branding_remove_setting', $settings['child_remove_setting']);
        MainWPHelper::update_option('mainwp_branding_remove_wp_tools', $settings['child_remove_wp_tools']);
        MainWPHelper::update_option('mainwp_branding_remove_wp_setting', $settings['child_remove_wp_setting']);
        MainWPHelper::update_option('mainwp_branding_remove_permalink', $settings['child_remove_permalink']);
        MainWPHelper::update_option('mainwp_branding_button_contact_label', $settings['child_button_contact_label']);
        MainWPHelper::update_option('mainwp_branding_send_email_message', $settings['child_send_email_message']);
        MainWPHelper::update_option('mainwp_branding_message_return_sender', $settings['child_message_return_sender']);
        MainWPHelper::update_option('mainwp_branding_submit_button_title', $settings['child_submit_button_title']);
         if (isset($settings['child_disable_wp_branding']) && ($settings['child_disable_wp_branding'] === "Y" || $settings['child_disable_wp_branding'] === "N"))
             MainWPHelper::update_option('mainwp_branding_disable_wp_branding', $settings['child_disable_wp_branding']);
       
        $extra_setting = array('show_button_in' => $settings['child_show_support_button_in'],                                                            
                                'global_footer' => $settings['child_global_footer'],
                                'dashboard_footer' => $settings['child_dashboard_footer'],
                                'remove_widget_welcome' => $settings['child_remove_widget_welcome'],
                                'remove_widget_glance' => $settings['child_remove_widget_glance'],
                                'remove_widget_activity' => $settings['child_remove_widget_activity'],
                                'remove_widget_quick' => $settings['child_remove_widget_quick'],
                                'remove_widget_news' => $settings['child_remove_widget_news'],
                                'site_generator' => $settings['child_site_generator'],
                                'generator_link' => $settings['child_generator_link'],
                                'admin_css' => $settings['child_admin_css'],
                                'login_css' => $settings['child_login_css'],
                                'texts_replace' => $settings['child_texts_replace']                                
                            );
        
        if (isset($settings['child_login_image_url'])) {
            if (empty($settings['child_login_image_url'])) {
                $extra_setting['login_image'] = array();
            } else {
                try
                {
                    $upload = $this->uploadImage($settings['child_login_image_url']); //Upload image to WP
                    if ($upload != null)
                    {                    
                        $extra_setting['login_image'] = array("path" => $upload["path"], "url" => $upload["url"]);                    
                        if (isset($current_extra_setting['login_image']['path'])) {
                            $old_file = $current_extra_setting['login_image']['path'];
                            if (!empty($old_file) && file_exists($old_file))
                                @unlink ($old_file);
                        }
                    }
                }
                catch (Exception $e)
                {
                    $information['error']['login_image'] = $e->getMessage();
                }    
            }
        } else if (isset($current_extra_setting['login_image'])){
            $extra_setting['login_image'] = $current_extra_setting['login_image'];
        }
        
        if (isset($settings['child_favico_image_url'])) {
            if (empty($settings['child_favico_image_url'])) {
                $extra_setting['favico_image'] = array();
            } else {
                try
                {
                    $upload = $this->uploadImage($settings['child_favico_image_url']); //Upload image to WP
                    if ($upload != null)
                    {                    
                        $extra_setting['favico_image'] = array("path" => $upload["path"], "url" => $upload["url"]);                    
                        if (isset($current_extra_setting['favico_image']['path'])) {
                            $old_file = $current_extra_setting['favico_image']['path'];
                            if (!empty($old_file) && file_exists($old_file))
                                @unlink ($old_file);
                        }
                    }
                }
                catch (Exception $e)
                {
                    $information['error']['favico_image'] = $e->getMessage();
                }    
            }
        } else if (isset($current_extra_setting['favico_image'])){
            $extra_setting['favico_image'] = $current_extra_setting['favico_image'];
        }
        
        
        MainWPHelper::update_option('mainwp_branding_extra_settings', $extra_setting);
        
        if ($settings['child_plugin_hide'])
        {
            MainWPHelper::update_option('mainwp_branding_child_hide', 'T');
        }
        else
        {
            MainWPHelper::update_option('mainwp_branding_child_hide', '');
        }

        if ($settings['child_show_support_button'] && !empty($settings['child_support_email']))
        {
            MainWPHelper::update_option('mainwp_branding_show_support', 'T');
        }
        else
        {
            MainWPHelper::update_option('mainwp_branding_show_support', '');
        }

        if ($settings['child_disable_change'])
        {
            MainWPHelper::update_option('mainwp_branding_disable_change', 'T');
        }
        else
        {
            MainWPHelper::update_option('mainwp_branding_disable_change', '');
        }
        $information['result'] = 'SUCCESS';
        return $information;
    }

    static function uploadImage($img_url)
    {
        include_once(ABSPATH . 'wp-admin/includes/file.php'); //Contains download_url
        //Download $img_url
        $temporary_file = download_url($img_url);

        if (is_wp_error($temporary_file))
        {
            throw new Exception('Error: ' . $temporary_file->get_error_message());
        }
        else
        {
            $upload_dir = wp_upload_dir();
            $local_img_path = $upload_dir['path'] . DIRECTORY_SEPARATOR . basename($img_url); //Local name
            $local_img_path = dirname( $local_img_path ) . '/' . wp_unique_filename( dirname( $local_img_path ), basename( $local_img_path ) );
            $local_img_url = $upload_dir['url'] . '/' . basename($local_img_path);
            $moved = @rename($temporary_file, $local_img_path);
            if ($moved)
            {                
                return array('path' => $local_img_path, 'url' => $local_img_url);
            }
        }
        if (file_exists($temporary_file))
        {
            unlink($temporary_file);
        }
        return null;
    }
    

    public function branding_init()
    {   
        // enable branding in case child plugin is deactive
        add_filter('all_plugins', array($this, 'branding_child_plugin')); 
        
        if (get_option('mainwp_branding_ext_enabled') !== "Y")
            return;
        
        add_filter('map_meta_cap', array($this, 'branding_map_meta_cap'), 10, 5);                           
        $extra_setting = $this->settings['extra_settings'];
        if (!is_array($extra_setting)) 
            $extra_setting = array();       
        if (get_option('mainwp_branding_show_support') == 'T')
        {          
            $title = $this->settings['contact_support_label'];            
            if (isset($extra_setting['show_button_in']) && ($extra_setting['show_button_in'] == 2 || $extra_setting['show_button_in'] == 3)) {                                    
                $title = $this->settings['contact_support_label'];                  
                add_menu_page($title, $title, 'read', 'ContactSupport2', array($this, 'contact_support'), "", '2.0001');               
            } 
            
            if (isset($extra_setting['show_button_in']) && ($extra_setting['show_button_in'] == 1 || $extra_setting['show_button_in'] == 3)){                                
                add_submenu_page( null, $title, $this->settings['contact_support_label'] , 'read', "ContactSupport", array($this, "contact_support") ); 
                add_action('admin_bar_menu', array($this, 'add_support_button_in_top_admin_bar'), 100);                                        
            }             
        }  
        add_filter('update_footer', array(&$this, 'update_footer'), 15);                
        if(get_option('mainwp_branding_disable_wp_branding') !== "Y") {            
            add_filter('wp_footer', array(&$this, 'branding_global_footer'), 15);    
            add_action('wp_dashboard_setup', array(&$this, 'custom_dashboard_widgets'), 999);
            // branding site generator
            $types = array('html', 'xhtml', 'atom', 'rss2', 'rdf', 'comment', 'export');
            foreach ($types as $type)
              add_filter('get_the_generator_'.$type, array(&$this, 'custom_the_generator'));                  
            add_action('admin_head', array(&$this, 'custom_admin_css'));
            add_action( 'login_enqueue_scripts', array(&$this, 'custom_login_css'));
            add_filter( 'gettext', array(&$this, 'custom_gettext'), 99, 3);     
            add_action('login_head', array(&$this, 'custom_login_logo'));
            add_action( 'wp_head', array( &$this, 'custom_favicon_frontend' ) );
            if (isset($extra_setting['dashboard_footer']) && !empty($extra_setting['dashboard_footer'])) {
                remove_filter( 'update_footer', 'core_update_footer' );
                add_filter('update_footer', array(&$this, 'update_admin_footer'), 14);
            }
        }   
    }
    
    function update_admin_footer() {
        $extra_setting = $this->settings['extra_settings'];
        if (isset($extra_setting['dashboard_footer']) && !empty($extra_setting['dashboard_footer'])) {
            echo nl2br(stripslashes($extra_setting['dashboard_footer']));
        }
    }
    
    function custom_favicon_frontend() {
        $extra_setting = $this->settings['extra_settings'];        
        if (isset($extra_setting["favico_image"]["url"]) && !empty($extra_setting["favico_image"]["url"])) {
            $favico = $extra_setting["favico_image"]["url"];            
            echo '<link rel="shortcut icon" href="'.  esc_url( $favico )  .'"/>'."\n";
        }
    }
    
    function custom_login_logo() {
        $extra_setting = $this->settings['extra_settings'];
        if (isset($extra_setting["login_image"]["url"]) && !empty($extra_setting["login_image"]["url"])) {
            $login_logo = $extra_setting["login_image"]["url"];            
            echo '<style type="text/css">
                    h1 a { background-image: url(\'' . esc_url($login_logo) . '\') !important; height:70px !important; width:310px !important; background-size: auto auto !important; }
                </style>';
        }
    }

    function custom_gettext($translations, $text, $domain = 'default' ) {
        $extra_setting = $this->settings['extra_settings'];
        $texts_replace = $extra_setting['texts_replace'];               
        if (is_array($texts_replace) && count($texts_replace) > 0) {
            foreach($texts_replace as $text => $replace) {
                if (!empty($text)) {                
                    $translations = str_replace($text, $replace, $translations); 
                }
            }
        } 
        return $translations;
    }
    
    function custom_admin_css() {
        $extra_setting = $this->settings['extra_settings'];
        if (is_array($extra_setting) && isset($extra_setting['admin_css']) && !empty($extra_setting['admin_css'])) {
            echo '<style>' . $extra_setting['admin_css'] . '</style>';
        }      
    }
    
    function custom_login_css() {
        $extra_setting = $this->settings['extra_settings'];
        if (is_array($extra_setting) && isset($extra_setting['login_css']) && !empty($extra_setting['login_css'])) {            
            echo '<style>' . $extra_setting['login_css'] . '</style>';
        }      
    }

    function custom_the_generator($generator, $type = "") {  
        $extra_setting = $this->settings['extra_settings'];
        if (isset($extra_setting['site_generator'])) {               
            if (!empty($extra_setting['site_generator'])) {                  
                switch ($type):
                    case "html":                     
                        $generator = '<meta name="generator" content="' . $extra_setting['site_generator'] . '">'; 
                        break;
                    case "xhtml":
                        $generator = '<meta name="generator" content="' . $extra_setting['site_generator'] . '" />'; 
                        break;
                    case "atom":
                        if (!empty($extra_setting['generator_link'])) {
                            $generator = '<generator uri="' . $extra_setting['generator_link'] . '" >' . $extra_setting['site_generator'] .'</generator>';
                        }                    
                        break;
                    case "rss2":
                        if (!empty($extra_setting['generator_link'])) {
                            $generator = '<generator>' . $extra_setting['generator_link'] . '</generator>';
                        }
                        break;
                    case "rdf":
                        if (!empty($extra_setting['generator_link'])) {
                            $generator = '<admin:generatorAgent rdf:resource="' . $extra_setting['generator_link'] .  '" />';
                        }
                        break;
                    case "comment":                    
                        $generator = '<!-- generator="' . $extra_setting['site_generator'] . '" -->';                    
                        break;
                    case "export":
                        $generator = '<!-- generator="' . $extra_setting['site_generator'] . '" created="'. date('Y-m-d H:i') . '" -->';
                        break;        
                    default:
                        $generator = '<meta name="generator" content="' . $extra_setting['site_generator'] . '">'; 
                        break;                    
                endswitch;                
                return $generator;
            } 
        }             
        return $generator;
    }
    
    function custom_dashboard_widgets() {
        global $wp_meta_boxes;        
        $extra_setting = $this->settings['extra_settings'];            
        if (isset($extra_setting['remove_widget_welcome']) && $extra_setting['remove_widget_welcome']) {
            remove_action( 'welcome_panel', 'wp_welcome_panel' );    
        }
        if (isset($extra_setting['remove_widget_glance']) && $extra_setting['remove_widget_glance']) {
            unset($wp_meta_boxes['dashboard']['normal']['core']['dashboard_right_now']);                
        }            
        if (isset($extra_setting['remove_widget_activity']) && $extra_setting['remove_widget_activity']) {
            unset($wp_meta_boxes['dashboard']['normal']['core']['dashboard_activity']);                
        }
        if (isset($extra_setting['remove_widget_quick']) && $extra_setting['remove_widget_quick']) {                
            unset($wp_meta_boxes['dashboard']['side']['core']['dashboard_quick_press']);
        }
        if (isset($extra_setting['remove_widget_news']) && $extra_setting['remove_widget_news']) {                
            unset($wp_meta_boxes['dashboard']['side']['core']['dashboard_primary']);                
        }
    }

    public function branding_global_footer() {
        $extra_setting = $this->settings['extra_settings'];           
        if (isset($extra_setting['global_footer']) && !empty($extra_setting['global_footer'])) {
            echo nl2br(stripslashes($extra_setting['global_footer']));
        }
    }
            
    public function send_support_mail()
    {
        $email = get_option('mainwp_branding_support_email');
        $content = nl2br(stripslashes($_POST['mainwp_branding_contact_message_content']));        
        if (!empty($_POST['mainwp_branding_contact_message_content']) && !empty($email))
        {
            $mail = '<p>Support Email from: <a href="' . site_url() . '">' . site_url() . '</a></p>';
            $mail .= '<p>Sent from WordPress page: ' . (!empty($_POST['mainwp_branding_send_from_page']) ? '<a href="' . $_POST['mainwp_branding_send_from_page'] . '">' . $_POST['mainwp_branding_send_from_page'] . '</a></p>' : "");
            $mail .= '<p>Admin email: ' . get_option('admin_email') . ' </p>';
            $mail .= '<p>Support Text:</p>';            
            $mail .= '<p>' . $content . '</p>';
            if (wp_mail($email, 'MainWP - Support Contact', $mail, array('From: "' . get_option('admin_email') . '" <' . get_option('admin_email') . '>', 'content-type: text/html'))) ;
                return true;
        }
        return false;
    }

    function contact_support()
    {       
    ?>
    <style>  
        .mainwp_info-box-yellow {
            margin: 5px 0 15px;
            padding: .6em;
            background: #ffffe0;
            border: 1px solid #e6db55;
            border-radius: 3px;
            -moz-border-radius: 3px;
            -webkit-border-radius: 3px;
            clear: both;
        }        
    </style>
    <?php 
        if (isset($_POST['submit'])) {                  
            $from_page = $_POST['mainwp_branding_send_from_page']; 
            $back_link = get_option('mainwp_branding_message_return_sender');
            $back_link = !empty($back_link) ? $back_link : "Go Back";
            $back_link = !empty($from_page) ? '<a href="' .  $from_page . '" title="' . $back_link . '">' . $back_link . '</a>' : '';          
            
           if ($this->send_support_mail()) {
                $send_email_message = get_option("mainwp_branding_send_email_message");
                if (!empty($send_email_message)) {                
                    $send_email_message = stripslashes($send_email_message);
                } else 
                    $send_email_message = "Your Message was successfully submitted.";
           } else {
               $send_email_message = __("Error: send mail failed.");
           }
           ?><div class="mainwp_info-box-yellow"><?php echo $send_email_message . "&nbsp;&nbsp" . $back_link; ?></div><?php                
        } else {    
            $from_page = ""; 
            if (isset($_GET['from_page'])) {
                $from_page = urldecode($_GET['from_page']); 
            } else {
                $protocol = isset($_SERVER['HTTPS']) && strcasecmp($_SERVER['HTTPS'], 'off') ? 'https://' : 'http://';
                $fullurl = $protocol .$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];     
                $from_page = urldecode($fullurl);
            }
            
            $support_message = get_option('mainwp_branding_support_message');
            $support_message = nl2br(stripslashes($support_message));
            ?>
            <form action="" method="post">
                    <div style="width: 99%;">        
                        <h2><?php echo $this->settings['contact_support_label']; ?></h2>
                        <div style="height: auto; margin-bottom: 10px; text-align: left">                                                          
                            <p><?php echo $support_message; ?></p>   
                            <div style="max-width: 650px;">
                                <?php             
                                remove_editor_styles(); // stop custom theme styling interfering with the editor
                                wp_editor( "", 'mainwp_branding_contact_message_content', array(
                                                'textarea_name' => 'mainwp_branding_contact_message_content',
                                                'textarea_rows' => 10,                                    
                                                'teeny' => true,
                                                'wpautop' => true,
                                                'media_buttons' => false,
                                        )
                                );  
                                ?>   
                            </div>
                        </div>
                        <br />
                        <?php
                            $button_title = get_option("mainwp_branding_submit_button_title");
                            $button_title = !empty($button_title) ? $button_title : __("Submit");
                        ?>
                        <input id="mainwp-branding-contact-support-submit" type="submit" name="submit" value="<?php echo $button_title; ?>"
                               class="button-primary button" style="float: left"/>        
                    </div>    
                    <input type="hidden"  name="mainwp_branding_send_from_page" value="<?php echo $from_page;?>" />    
            </form>
    <?php } 
    }

    /**
     * @param WP_Admin_Bar $wp_admin_bar
     */
    public function add_support_button_in_top_admin_bar($wp_admin_bar)
    {
        if (isset($_GET['from_page']))
            $href = admin_url('admin.php?page=ContactSupport&from_page=' . urlencode ($_GET['from_page']));
        else {                         
            $protocol = isset($_SERVER['HTTPS']) && strcasecmp($_SERVER['HTTPS'], 'off') ? 'https://' : 'http://';
            $fullurl = $protocol .$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI']; 
            $href = admin_url('admin.php?page=ContactSupport&from_page=' . urlencode($fullurl));
        }
        $args = array(
            'id' => 999,
            'title' => $this->settings['contact_support_label'],
            'parent' => 'top-secondary',
            'href' => $href,
            'meta' => array('class' => 'mainwp_branding_support_top_bar_button', 'title' => $this->settings['contact_support_label'])
        );
        
        $wp_admin_bar->add_node($args);
    }
    
    public static function is_branding() {
        // hide
        if (get_option('mainwp_branding_child_hide') == 'T')
            return true;
        // branding
        $header = get_option('mainwp_branding_plugin_header');
        if (is_array($header) && !empty($header['name']))    
            return true;
        return false;
    }
    
    function update_footer($text){        
        if (stripos($_SERVER['REQUEST_URI'], 'update-core.php') !== false && self::is_branding())
        {
            ?>
           <script>
                jQuery(document).ready(function(){
                    jQuery('input[type="checkbox"][value="mainwp-child/mainwp-child.php"]').closest('tr').remove();
                });        
            </script>
           <?php
        }

        return $text;
    }

    public function branding_map_meta_cap($caps, $cap, $user_id, $args)
    {
        if (get_option('mainwp_branding_disable_change') == 'T')
        {
            // disable: edit, update, install, active themes and plugins
            if (strpos($cap, 'plugins') !== false || strpos($cap, 'themes') !== false || $cap == 'edit_theme_options')
            {
                //echo $cap."======<br />";
                $caps[0] = 'do_not_allow';
            }
        }
        return $caps;
    }

    public function branding_child_plugin($plugins)
    {
        if (get_option('mainwp_branding_child_hide') == 'T')
        {
            foreach ($plugins as $key => $value)
            {
                $plugin_slug = basename($key, '.php');
                if ($plugin_slug == 'mainwp-child')
                    unset($plugins[$key]);
            }
            return $plugins;
        }

        $header = get_option('mainwp_branding_plugin_header');
        if (is_array($header) && !empty($header['name']))
            return $this->update_child_header($plugins, $header);
        else
            return $plugins;
    }
    
    public function update_child_header($plugins, $header)
    {
        $plugin_key = "";
        foreach ($plugins as $key => $value)
        {
            $plugin_slug = basename($key, '.php');
            if ($plugin_slug == 'mainwp-child')
            {
                $plugin_key = $key;
                $plugin_data = $value;
            }
        }

        if (!empty($plugin_key))
        {
            $plugin_data['Name'] = stripslashes($header['name']);
            $plugin_data['Description'] = stripslashes($header['description']);            
            $plugin_data['Author'] = stripslashes($header['author']);
            $plugin_data['AuthorURI'] = stripslashes($header['authoruri']);
            if (!empty($header['pluginuri']))
                $plugin_data['PluginURI'] = stripslashes($header['pluginuri']);
            $plugins[$plugin_key] = $plugin_data;
        }
        return $plugins;
    }
}

