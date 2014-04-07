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
            'mainwp_branding_plugin_header');
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

        $header = array('name' => $settings['child_plugin_name'],
            'description' => $settings['child_plugin_desc'],
            'author' => $settings['child_plugin_author'],
            'authoruri' => $settings['child_plugin_author_uri'],
            'pluginuri' => $settings['child_plugin_uri']);
        
        update_option('mainwp_branding_plugin_header', $header);
        update_option('mainwp_branding_support_email', $settings['child_support_email']);
        update_option('mainwp_branding_support_message', $settings['child_support_message']);
        update_option('mainwp_branding_remove_restore', $settings['child_remove_restore']);
        update_option('mainwp_branding_remove_setting', $settings['child_remove_setting']);
        update_option('mainwp_branding_remove_wp_tools', $settings['child_remove_wp_tools']);
        update_option('mainwp_branding_remove_wp_setting', $settings['child_remove_wp_setting']);
        update_option('mainwp_branding_button_contact_label', $settings['child_button_contact_label']);
        update_option('mainwp_branding_send_email_message', $settings['child_send_email_message']);
        update_option('mainwp_branding_message_return_sender', $settings['child_message_return_sender']);
        update_option('mainwp_branding_submit_button_title', $settings['child_submit_button_title']);

        if ($settings['child_plugin_hide'])
        {
            update_option('mainwp_branding_child_hide', 'T');
        }
        else
        {
            update_option('mainwp_branding_child_hide', '');
        }

        if ($settings['child_show_support_button'] && !empty($settings['child_support_email']))
        {
            update_option('mainwp_branding_show_support', 'T');
        }
        else
        {
            update_option('mainwp_branding_show_support', '');
        }

        if ($settings['child_disable_change'])
        {
            update_option('mainwp_branding_disable_change', 'T');
        }
        else
        {
            update_option('mainwp_branding_disable_change', '');
        }
        $information['result'] = 'SUCCESS';
        return $information;
    }


    public function branding_init()
    {
        add_filter('map_meta_cap', array($this, 'branding_map_meta_cap'), 10, 5);        
        add_filter('all_plugins', array($this, 'branding_child_plugin'));                
        if (get_option('mainwp_branding_show_support') == 'T')
        {
            add_submenu_page( null, $this->settings['contact_support_label'], $this->settings['contact_support_label'] , 'read', "ContactSupport", array($this, "contact_support") ); 
            add_action('admin_bar_menu', array($this, 'add_support_button'), 100);            
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
            $from_page = urldecode($_GET['from_page']); 
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
    public function add_support_button($wp_admin_bar)
    {
        if (isset($_GET['from_page']))
            $href = admin_url('admin.php?page=ContactSupport&from_page=' . urlencode ($_GET['from_page']));
        else {                         
            $protocol = isset($_SERVER['HTTPS']) && strcasecmp($_SERVER['HTTPS'], 'off') ? 'https://' : 'http://';
            $fullurl = $protocol .$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI']; 
            $href = admin_url('admin.php?page=ContactSupport&from_page=' . urlencode($fullurl));
        }
        $args = array(
            'id' => false,
            'title' => $this->settings['contact_support_label'],
            'parent' => 'top-secondary',
            'href' => $href,
            'meta' => array('class' => 'mainwp_branding_support_top_bar_button', 'title' => $this->settings['contact_support_label'])
        );
        
        $wp_admin_bar->add_node($args);
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

