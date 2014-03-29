<?php

class MainWPChildBranding
{
    public static $instance = null;
    protected $child_plugin_dir;

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
        $default_header = get_option('mainwp_branding_default_header', false);
        if (empty($default_header))
        {
            $info = get_plugin_data($this->child_plugin_dir . '/mainwp-child.php');
            if (is_array($info))
            {
                $default_header = array('name' => $info['Name'],
                    'description' => $info['Description'],
                    'authoruri' => $info['AuthorURI'],
                    'author' => $info['Author']);
                update_option('mainwp_branding_default_header', $default_header);
            }
        }
        add_action('mainwp_child_deactivation', array($this, 'child_deactivation'));
    }

    public static function admin_init()
    {
        if (get_option('mainwp_branding_show_support') == 'T')
        {
            wp_enqueue_script('jquery-ui-dialog');
            wp_enqueue_style('jquery-ui-style', '//ajax.googleapis.com/ajax/libs/jqueryui/1.10.3/themes/smoothness/jquery-ui.css');
            add_action('wp_ajax_mainwp-child_branding_send_suppport_mail', array(MainWPChildBranding::Instance(), 'send_support_mail'));
        }
    }

    public function child_deactivation()
    {
        $dell_all = array('mainwp_branding_disable_change',
            'mainwp_branding_child_hide',
            'mainwp_branding_show_support',
            'mainwp_branding_support_email',
            'mainwp_branding_support_message',
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
            'authoruri' => $settings['child_plugin_author_uri']);

        update_option('mainwp_branding_plugin_header', $header);
        update_option('mainwp_branding_support_email', $settings['child_support_email']);
        update_option('mainwp_branding_support_message', $settings['child_support_message']);

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
        add_filter('map_meta_cap', array($this, 'theme_plugin_disable_change'), 10, 5);
        add_filter('all_plugins', array($this, 'branding_child_plugin'));
        add_filter('plugin_action_links', array($this, 'plugin_action_links'), 10, 2);

        if (get_option('mainwp_branding_show_support') == 'T')
        {
            add_action('admin_bar_menu', array($this, 'add_support_button'), 100);
            add_filter('update_footer', array(&$this, 'update_footer'), 15);
        }

    }

    public function send_support_mail()
    {
        $email = get_option('mainwp_branding_support_email');
        if (!empty($_POST['content']) && !empty($email))
        {
            $mail = '<p>This email send from Contact support form at <a href="' . site_url() . '">' . site_url() . '</a></p>';
            $mail .= '<p>Admin email: ' . get_option('admin_email') . ' </p>';
            $mail .= $_POST['content'];
            if (wp_mail($email, 'MainWP - Support Contact', $mail, array('From: "' . get_option('admin_email') . '" <' . get_option('admin_email') . '>', 'content-type: text/html'))) ;
            die('SUCCESS');
        }
        die($email);
    }

    function update_footer()
    {
        ob_start();
        ?>
    <style>
        .ui-dialog {
            padding: .5em;
            width: 600px !important;
            overflow: hidden;
            -webkit-box-shadow: 0px 0px 15px rgba(50, 50, 50, 0.45);
            -moz-box-shadow: 0px 0px 15px rgba(50, 50, 50, 0.45);
            box-shadow: 0px 0px 15px rgba(50, 50, 50, 0.45);
            background: #fff !important;
            z-index: 99999;
        }

        .ui-dialog .ui-dialog-titlebar {
            background: none;
            border: none;
        }

        .ui-dialog .ui-dialog-title {
            font-size: 20px;
            font-family: Helvetica;
            text-transform: uppercase;
            color: #555;
        }

        .ui-dialog h3 {
            font-family: Helvetica;
            text-transform: uppercase;
            color: #888;
            border-radius: 25px;
            -moz-border-radius: 25px;
            -webkit-border-radius: 25px;
        }

        .ui-dialog .ui-dialog-titlebar-close {
            background: none;
            border-radius: 15px;
            -moz- border-radius : 15 px;
            -webkit- border-radius : 15 px;
            color: #fff;
        }

        .ui-dialog .ui-dialog-titlebar-close:hover {
            background: #7fb100;
        }

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

    <div id="mainwp-branding-contact-support-box" title="Contact support" style="display: none; text-align: center">
        <div style="height: 230px; margin-bottom: 10px; text-align: left">
            <div class="mainwp_info-box-yellow" id="mainwp_branding_contact_ajax_message_zone"
                 style="display: none;"></div>
            <p><?php echo get_option('mainwp_branding_support_message'); ?></p>
            <textarea id="mainwp_branding_contact_message_content" name="mainwp_branding_contact_message_content"
                      cols="69" rows="5" class="text"></textarea>
        </div>
        <input id="mainwp-branding-contact-support-submit" type="button" name="submit" value="Submit"
               class="button-primary button"/>
    </div>
    <script>
        jQuery(document).ready(function ()
        {
            jQuery('.mainwp_branding_support_top_bar_button').live('click', function (event)
            {
                mainwp_branding_contact();
                return false;
            });

            jQuery('#mainwp-branding-contact-support-submit').live('click', function (event)
            {
                var messageEl = jQuery('#mainwp_branding_contact_ajax_message_zone');
                messageEl.hide();
                var content = jQuery('#mainwp_branding_contact_message_content').val();

                if (jQuery.trim(content) == '')
                {
                    messageEl.html(__('You content message must not be empty.')).fadeIn();
                    return false;
                }
                jQuery(this).attr('disabled', 'true'); //Disable
                messageEl.html('Mail sending...').show();
                var data = {
                    action:'mainwp-child_branding_send_suppport_mail',
                    content:content
                };
                jQuery.ajax({
                    type:"POST",
                    url:ajaxurl,
                    data:data,
                    success:function (resp)
                    {
                        if (resp == 'SUCCESS')
                        {
                            messageEl.html('Send mail successful.').show();
                        }
                        else
                        {
                            messageEl.css('color', 'red');
                            messageEl.html('Error send mail.').show();
                            jQuery('#mainwp-branding-contact-support-submit').removeAttr('disabled');
                            return;
                        }
                        setTimeout(function ()
                        {
                            jQuery('#mainwp-branding-contact-support-box').dialog('close');
                        }, 1500);
                    }
                });
                return false;
            });

        });

        mainwp_branding_contact = function ()
        {
            jQuery('#mainwp-branding-contact-support-box').dialog({
                resizable:false,
                height:350,
                width:500,
                modal:true,
                close:function (event, ui)
                {
                    jQuery('#mainwp-branding-contact-support-box').dialog('destroy');
                    location.href = location.href;
                }});
        };

    </script>
    <?php
        $newOutput = ob_get_clean();
        return $newOutput;
    }

    /**
     * @param WP_Admin_Bar $wp_admin_bar
     */
    public function add_support_button($wp_admin_bar)
    {
        $args = array(
            'id' => false,
            'title' => 'Contact Support',
            'parent' => 'top-secondary',
            'href' => '#',
            'meta' => array('class' => 'mainwp_branding_support_top_bar_button', 'title' => 'Contact support')
        );
        $wp_admin_bar->add_node($args);
    }


    public function theme_plugin_disable_change($caps, $cap, $user_id, $args)
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

    public function plugin_action_links($links, $file)
    {
        return $links;
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
            $plugin_data['Name'] = $header['name'];
            $plugin_data['Description'] = $header['description'];
            $plugin_data['Author'] = $header['author'];
            $plugin_data['AuthorURI'] = $header['authoruri'];
            $plugins[$plugin_key] = $plugin_data;
        }
        return $plugins;
    }
}

