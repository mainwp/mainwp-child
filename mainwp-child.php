<?php
/*
  Plugin Name: MainWP Child
  Plugin URI: http://mainwp.com/
  Description: Child Plugin for MainWP. The plugin is used so the installed blog can be securely managed remotely by your network. Plugin documentation and options can be found here http://docs.mainwp.com
  Author: MainWP
  Author URI: http://mainwp.com
  Version: 2.0.19
 */
if ((isset($_REQUEST['heatmap']) && $_REQUEST['heatmap'] == '1') || (isset($_REQUEST['mainwpsignature']) && (!empty($_REQUEST['mainwpsignature'])))) {
    header('X-Frame-Options: ALLOWALL');
}
//header('X-Frame-Options: GOFORIT');
include_once(ABSPATH . 'wp-includes' . DIRECTORY_SEPARATOR . 'version.php'); //Version information from wordpress

$classDir = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . str_replace(basename(__FILE__), '', plugin_basename(__FILE__)) . 'class' . DIRECTORY_SEPARATOR;
function mainwp_child_autoload($class_name) {
    $class_file = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . str_replace(basename(__FILE__), '', plugin_basename(__FILE__)) . 'class' . DIRECTORY_SEPARATOR . $class_name . '.class.php';
    if (file_exists($class_file)) {
        require_once($class_file);
    }
}
if (function_exists('spl_autoload_register'))
{
    spl_autoload_register('mainwp_child_autoload');
}
else
{
    function __autoload($class_name) {
        mainwp_child_autoload($class_name);
    }
}

$mainWPChild = new MainWPChild(WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . plugin_basename(__FILE__));
register_activation_hook(__FILE__, array($mainWPChild, 'activation'));
register_deactivation_hook(__FILE__, array($mainWPChild, 'deactivation'));
?>