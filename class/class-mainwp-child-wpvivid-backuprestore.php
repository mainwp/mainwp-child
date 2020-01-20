<?php

class MainWP_Child_WPvivid_BackupRestore
{
    public static $instance = null;
    public $is_plugin_installed = false;
    public $public_intetface;
    static function Instance()
    {
        if ( null === MainWP_Child_WPvivid_BackupRestore::$instance )
        {
            MainWP_Child_WPvivid_BackupRestore::$instance = new MainWP_Child_WPvivid_BackupRestore();
        }

        return MainWP_Child_WPvivid_BackupRestore::$instance;
    }

    public function __construct()
    {
        require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
        if ( is_plugin_active( 'wpvivid-backuprestore/wpvivid-backuprestore.php' ) && defined('WPVIVID_PLUGIN_DIR'))
        {
            $this->is_plugin_installed = true;
        }

        if (!$this->is_plugin_installed)
            return;

        add_filter( 'mainwp-site-sync-others-data', array( $this, 'syncOthersData' ), 10, 2 );
        $this->public_intetface = new WPvivid_Public_Interface();
    }

    public function init()
    {

    }

    function syncOthersData( $information, $data = array() )
    {
        try{

            if ( isset( $data['syncWPvividData'] ))
            {
                $information['syncWPvividData']=1;
                $data = WPvivid_Setting::get_sync_data();
                $information['syncWPvividSettingData']=$data['setting'];
                $information['syncWPvividRemoteData']=$data['remote'];
                $information['syncWPvividScheduleData']=$data['schedule'];
                $information['syncWPvividSetting'] = $data;
            }

        } catch(Exception $e) {

        }

        return $information;
    }

    public function action()
    {
        $information = array();
        if ( ! $this->is_plugin_installed )
        {
            $information['error'] = 'NO_WPVIVIDBACKUP';
            MainWP_Helper::write( $information );
        }

        if ( isset( $_POST['mwp_action'] ) )
        {
            try {
                switch ($_POST['mwp_action']) {
                    case 'prepare_backup':
                        $information = $this->prepare_backup();
                        break;
                    case 'backup_now':
                        $information = $this->backup_now();
                        break;
                    case 'get_status':
                        $information = $this->get_status();
                        break;
                    case 'get_backup_schedule':
                        $information = $this->get_backup_schedule();
                        break;
                    case 'get_backup_list':
                        $information = $this->get_backup_list();
                        break;
                    case 'get_default_remote':
                        $information = $this->get_default_remote();
                        break;
                    case 'delete_backup':
                        $information = $this->delete_backup();
                        break;
                    case 'delete_backup_array':
                        $information = $this->delete_backup_array();
                        break;
                    case 'set_security_lock':
                        $information = $this->set_security_lock();
                        break;
                    case 'view_log':
                        $information = $this->view_log();
                        break;
                    case 'read_last_backup_log':
                        $information = $this->read_last_backup_log();
                        break;
                    case 'view_backup_task_log':
                        $information = $this->view_backup_task_log();
                        break;
                    case 'backup_cancel':
                        $information = $this->backup_cancel();
                        break;
                    case 'init_download_page':
                        $information = $this->init_download_page();
                        break;
                    case 'prepare_download_backup':
                        $information = $this->prepare_download_backup();
                        break;
                    case 'get_download_task':
                        $information = $this->get_download_task();
                        break;
                    case 'download_backup':
                        $information = $this->download_backup();
                        break;
                    case 'set_general_setting':
                        $information = $this->set_general_setting();
                        break;
                    case 'set_schedule':
                        $information = $this->set_schedule();
                        break;
                    case 'set_remote':
                        $information = $this->set_remote();
                        break;
                    default:
                        $information = $this->post_mainwp_data($_POST);
                        break;
                }
            } catch (Exception $e) {
                $information = array('error' => $e->getMessage());
            }

            MainWP_Helper::write($information);
        }
    }

    public function post_mainwp_data($data){
        global $wpvivid_plugin;

        $ret =$wpvivid_plugin->wpvivid_handle_mainwp_action($data);
        return $ret;
    }

    public function prepare_backup()
    {
        global $wpvivid_plugin;
        $wpvivid_plugin->ajax_check_security();
        $ret = $this->public_intetface->prepare_backup($_POST['backup']);
        return $ret;
            }

    public function backup_now()
    {
        global $wpvivid_plugin;
        $wpvivid_plugin->ajax_check_security();
        $ret = $this->public_intetface->backup_now($_POST['task_id']);
        return $ret;
    }

    public function get_status()
    {
        global $wpvivid_plugin;
        $wpvivid_plugin->ajax_check_security();
        $ret = $this->public_intetface->get_status();
        return $ret;
    }

    public function get_backup_schedule()
    {
        global $wpvivid_plugin;
        $wpvivid_plugin->ajax_check_security();
        $ret = $this->public_intetface->get_backup_schedule();
        return $ret;
        }

    public function get_backup_list()
    {
        global $wpvivid_plugin;
        $wpvivid_plugin->ajax_check_security();
        $ret = $this->public_intetface->get_backup_list();
        return $ret;
            }

    public function get_default_remote()
            {
        global $wpvivid_plugin;
        $wpvivid_plugin->ajax_check_security();
        $ret = $this->public_intetface->get_default_remote();
            return $ret;
        }

    public function delete_backup()
    {
        global $wpvivid_plugin;
        $wpvivid_plugin->ajax_check_security();
        $ret = $this->public_intetface->delete_backup($_POST['backup_id'], $_POST['force']);
        return $ret;
        }

    public function delete_backup_array()
    {
        global $wpvivid_plugin;
        $wpvivid_plugin->ajax_check_security();
        $ret = $this->public_intetface->delete_backup_array($_POST['backup_id']);
        return $ret;
        }

    public function set_security_lock()
    {
        global $wpvivid_plugin;
        $wpvivid_plugin->ajax_check_security();
        $ret = $this->public_intetface->set_security_lock($_POST['backup_id'], $_POST['lock']);
        return $ret;
        }

    public function view_log()
    {
        global $wpvivid_plugin;
        $wpvivid_plugin->ajax_check_security();
        $ret = $this->public_intetface->view_log($_POST['id']);
        return $ret;
        }

    public function read_last_backup_log()
    {
        global $wpvivid_plugin;
        $wpvivid_plugin->ajax_check_security();
        $ret = $this->public_intetface->read_last_backup_log($_POST['log_file_name']);
        return $ret;
        }

    public function view_backup_task_log()
    {
        global $wpvivid_plugin;
        $wpvivid_plugin->ajax_check_security();
        $ret = $this->public_intetface->view_backup_task_log($_POST['id']);
        return $ret;
        }

    public function backup_cancel()
    {
        global $wpvivid_plugin;
        $wpvivid_plugin->ajax_check_security();
        $ret = $this->public_intetface->backup_cancel($_POST['task_id']);
        return $ret;
        }

    public function init_download_page()
    {
        global $wpvivid_plugin;
        $wpvivid_plugin->ajax_check_security();
        $ret = $this->public_intetface->init_download_page($_POST['backup_id']);
        return $ret;
        }

    public function prepare_download_backup()
    {
        global $wpvivid_plugin;
        $wpvivid_plugin->ajax_check_security();
        $ret = $this->public_intetface->prepare_download_backup($_POST['backup_id'], $_POST['file_name']);
        return $ret;
        }

    public function get_download_task()
    {
        global $wpvivid_plugin;
        $wpvivid_plugin->ajax_check_security();
        $ret = $this->public_intetface->get_download_task($_POST['backup_id']);
                return $ret;
            }

    public function download_backup()
    {
        global $wpvivid_plugin;
        $wpvivid_plugin->ajax_check_security();
        $ret = $this->public_intetface->download_backup($_POST['backup_id'], $_POST['file_name']);
        return $ret;
        }

    public function set_general_setting()
    {
        global $wpvivid_plugin;
        $wpvivid_plugin->ajax_check_security();
        $ret = $this->public_intetface->set_general_setting($_POST['setting']);
        return $ret;
                }

    public function set_schedule()
    {
        global $wpvivid_plugin;
        $wpvivid_plugin->ajax_check_security();
        $ret = $this->public_intetface->set_schedule($_POST['schedule']);
        return $ret;
                }

    public function set_remote()
    {
        global $wpvivid_plugin;
        $wpvivid_plugin->ajax_check_security();
        $ret = $this->public_intetface->set_remote($_POST['remote']);
        return $ret;
	}
}
