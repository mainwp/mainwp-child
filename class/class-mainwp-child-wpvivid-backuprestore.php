<?php
/**
 * Created by PhpStorm.
 * User: alienware`x
 * Date: 2019/4/30
 * Time: 10:26
 */

class MainWP_Child_WPvivid_BackupRestore
{
    public static $instance = null;
    public $is_plugin_installed = false;
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
                }
            } catch (Exception $e) {
                $information = array('error' => $e->getMessage());
            }

            MainWP_Helper::write($information);
        }
    }

    public function get_status()
    {
        global $wpvivid_pulgin;
        $wpvivid_pulgin->ajax_check_security();
        $information['result']='success';
        $list_tasks=array();
        $tasks=WPvivid_Setting::get_tasks();
        foreach ($tasks as $task)
        {
            $backup = new WPvivid_Backup_Task($task['id']);
            $list_tasks[$task['id']]=$backup->get_backup_task_info($task['id']);
            if($list_tasks[$task['id']]['task_info']['need_update_last_task']===true){
                $task_msg = WPvivid_taskmanager::get_task($task['id']);
                WPvivid_Setting::update_option('wpvivid_last_msg',$task_msg);
            }
        }
        $information['wpvivid']['task']=$list_tasks;
        $backuplist=WPvivid_Backuplist::get_backuplist();
        $schedule=WPvivid_Schedule::get_schedule();
        $information['wpvivid']['backup_list']=$backuplist;
        $information['wpvivid']['schedule']=$schedule;
        $information['wpvivid']['schedule']['last_message']=WPvivid_Setting::get_last_backup_message('wpvivid_last_msg');
        WPvivid_taskmanager::delete_marked_task();
        return $information;
    }

    public function get_backup_schedule()
    {
        global $wpvivid_pulgin;
        $wpvivid_pulgin->ajax_check_security();
        $schedule=WPvivid_Schedule::get_schedule();
        $information['result']='success';
        $information['wpvivid']['schedule']=$schedule;
        $information['wpvivid']['schedule']['last_message']=WPvivid_Setting::get_last_backup_message('wpvivid_last_msg');
        return $information;
    }

    public function get_backup_list()
    {
        global $wpvivid_pulgin;
        $wpvivid_pulgin->ajax_check_security();
        $backuplist=WPvivid_Backuplist::get_backuplist();
        $information['result']='success';
        $information['wpvivid']['backup_list']=$backuplist;
        return $information;
    }

    public function get_default_remote()
    {
        global $wpvivid_pulgin;
        $wpvivid_pulgin->ajax_check_security();
        $remoteslist=WPvivid_Setting::get_all_remote_options();
        $default_remote_storage='';
        foreach ($remoteslist['remote_selected'] as $value) {
            $default_remote_storage=$value;
        }
        $information['result']='success';
        $information['remote_storage_type']='';
        foreach ($remoteslist as $key=>$value)
        {
            if($key === $default_remote_storage)
            {
                $information['remote_storage_type']=$value['type'];
            }
        }
        return $information;
    }

    public function prepare_backup()
    {
        global $wpvivid_pulgin;
        $wpvivid_pulgin->ajax_check_security();
        if(isset($_POST['backup'])&&!empty($_POST['backup']))
        {
            $backup_options =$_POST['backup'];
            if (is_null($backup_options))
            {
                $information['error']='Invalid parameter param:'.$_POST['backup'];
                return $information;
            }

            $information = $wpvivid_pulgin->check_backup_option($backup_options);
            if($information['result']!='success')
            {
                return $information;
            }

            $ret=$wpvivid_pulgin->pre_backup($backup_options,'Manual');
            if($ret['result']=='success')
            {
                //Check the website data to be backed up
                $ret['check']=$wpvivid_pulgin->check_backup($ret['task_id'],$backup_options['backup_files']);
                if(isset($ret['check']['result']) && $ret['check']['result'] == 'failed')
                {
                    $information['error']=$ret['check']['error'];
                    return $information;
                }
            }
            return $ret;
        }
        else
        {
            $information['error']='Invalid parameter';
            return $information;
        }
    }

    public function backup_now()
    {
        global $wpvivid_pulgin;
        $wpvivid_pulgin->ajax_check_security();
        if (!isset($_POST['task_id'])||empty($_POST['task_id'])||!is_string($_POST['task_id']))
        {
            $information['error']=__('Error occurred while parsing the request data. Please try to run backup again.', 'mainwp-wpvivid-extension');
            return $information;
        }
        $task_id=sanitize_key($_POST['task_id']);
        $information['result']='success';
        $txt = '<mainwp>' . base64_encode( serialize( $information ) ) . '</mainwp>';
        // Close browser connection so that it can resume AJAX polling
        header( 'Content-Length: ' . ( ( ! empty( $txt ) ) ? strlen( $txt ) : '0' ) );
        header( 'Connection: close' );
        header( 'Content-Encoding: none' );
        if ( session_id() ) {
            session_write_close();
        }
        echo $txt;
        // These two added - 19-Feb-15 - started being required on local dev machine, for unknown reason (probably some plugin that started an output buffer).
        if ( ob_get_level() ) {
            ob_end_flush();
        }
        flush();

        //Start backup site
        $wpvivid_pulgin->backup($task_id);
        $information['result']='success';
        return $information;
    }

    public function delete_backup()
    {
        global $wpvivid_pulgin;
        $wpvivid_pulgin->ajax_check_security();
        if(!isset($_POST['backup_id'])||empty($_POST['backup_id'])||!is_string($_POST['backup_id'])) {
            $information['error']='Invalid parameter param: backup_id.';
            return $information;
        }
        if(!isset($_POST['force'])){
            $information['error']='Invalid parameter param: force.';
            return $information;
        }
        if($_POST['force']==0||$_POST['force']==1) {
            $force_del=$_POST['force'];
        }
        else {
            $force_del=0;
        }
        $backup_id=sanitize_key($_POST['backup_id']);
        $information=$wpvivid_pulgin->delete_backup_by_id($backup_id, $force_del);
        $backuplist=WPvivid_Backuplist::get_backuplist();
        $information['wpvivid']['backup_list']=$backuplist;
        return $information;
    }

    public function delete_backup_array()
    {
        global $wpvivid_pulgin;
        $wpvivid_pulgin->ajax_check_security();
        if(!isset($_POST['backup_id'])||empty($_POST['backup_id'])||!is_array($_POST['backup_id'])) {
            $information['error']='Invalid parameter param: backup_id';
            return $information;
        }
        $backup_ids=$_POST['backup_id'];
        $information=array();
        foreach($backup_ids as $backup_id)
        {
            $backup_id=sanitize_key($backup_id);
            $information=$wpvivid_pulgin->delete_backup_by_id($backup_id);
        }
        $backuplist=WPvivid_Backuplist::get_backuplist();
        $information['wpvivid']['backup_list']=$backuplist;
        return $information;
    }

    public function set_security_lock()
    {
        global $wpvivid_pulgin;
        $wpvivid_pulgin->ajax_check_security();
        if(!isset($_POST['backup_id'])||empty($_POST['backup_id'])||!is_string($_POST['backup_id'])){
            $information['error']='Backup id not found';
            return $information;
        }
        if(!isset($_POST['lock'])){
            $information['error']='Invalid parameter param: lock';
            return $information;
        }
        $backup_id=sanitize_key($_POST['backup_id']);
        if($_POST['lock']==0||$_POST['lock']==1)
        {
            $lock=$_POST['lock'];
        }
        else
        {
            $lock=0;
        }
        WPvivid_Backuplist::set_security_lock($backup_id,$lock);
        $backuplist=WPvivid_Backuplist::get_backuplist();
        $information['wpvivid']['backup_list']=$backuplist;
        return $information;
    }

    public function view_log()
    {
        global $wpvivid_pulgin;
        $wpvivid_pulgin->ajax_check_security();
        if (!isset($_POST['id'])||empty($_POST['id'])||!is_string($_POST['id'])){
            $information['error']='Backup id not found';
            return $information;
        }
        $backup_id=sanitize_key($_POST['id']);
        $backup=WPvivid_Backuplist::get_backuplist_by_key($backup_id);
        if(!$backup)
        {
            $information['result']='failed';
            $information['error']=__('Retrieving the backup information failed while showing log. Please try again later.', 'mainwp-wpvivid-extension');
            return $information;
        }

        if(!file_exists( $backup['log']))
        {
            $information['result']='failed';
            $information['error']=__('The log not found.', 'mainwp-wpvivid-extension');
            return $information;
        }

        $file =fopen($backup['log'],'r');

        if(!$file)
        {
            $information['result']='failed';
            $information['error']=__('Unable to open the log file.', 'mainwp-wpvivid-extension');
            return $information;
        }

        $buffer='';
        while(!feof($file))
        {
            $buffer .= fread($file,1024);
        }
        fclose($file);

        $information['result']='success';
        $information['data']=$buffer;
        return $information;
    }

    public function read_last_backup_log()
    {
        global $wpvivid_pulgin;
        $wpvivid_pulgin->ajax_check_security();
        if(!isset($_POST['log_file_name'])||empty($_POST['log_file_name'])||!is_string($_POST['log_file_name']))
        {
            $information['result']='failed';
            $information['error']=__('Reading the log failed. Please try again.', 'mainwp-wpvivid-extension');
            return $information;
        }
        $option=sanitize_text_field($_POST['log_file_name']);
        $log_file_name= $wpvivid_pulgin->wpvivid_log->GetSaveLogFolder().$option.'_log.txt';

        if(!file_exists($log_file_name))
        {
            $information['result']='failed';
            $information['error']=__('The log not found.', 'mainwp-wpvivid-extension');
            return $information;
        }

        $file =fopen($log_file_name,'r');

        if(!$file)
        {
            $information['result']='failed';
            $information['error']=__('Unable to open the log file.', 'mainwp-wpvivid-extension');
            return $information;
        }

        $buffer='';
        while(!feof($file))
        {
            $buffer .= fread($file,1024);
        }
        fclose($file);

        $information['result']='success';
        $information['data']=$buffer;
        return $information;
    }

    public function view_backup_task_log()
    {
        global $wpvivid_pulgin;
        $wpvivid_pulgin->ajax_check_security();
        if (!isset($_POST['id'])||empty($_POST['id'])||!is_string($_POST['id'])){
            $information['error']='Reading the log failed. Please try again.';
            return $information;
        }
        $backup_task_id = sanitize_key($_POST['id']);
        $option=WPvivid_taskmanager::get_task_options($backup_task_id,'log_file_name');
        if(!$option)
        {
            $information['result']='failed';
            $information['error']=__('Retrieving the backup information failed while showing log. Please try again later.', 'mainwp-wpvivid-extension');
            return $information;
        }

        $log_file_name= $wpvivid_pulgin->wpvivid_log->GetSaveLogFolder().$option.'_log.txt';

        if(!file_exists($log_file_name))
        {
            $information['result']='failed';
            $information['error']=__('The log not found.', 'mainwp-wpvivid-extension');
            return $information;
        }

        $file =fopen($log_file_name,'r');

        if(!$file)
        {
            $information['result']='failed';
            $information['error']=__('Unable to open the log file.', 'mainwp-wpvivid-extension');
            return $information;
        }

        $buffer='';
        while(!feof($file))
        {
            $buffer .= fread($file,1024);
        }
        fclose($file);

        $information['result']='success';
        $information['data']=$buffer;
        return $information;
    }

    public function backup_cancel()
    {
        global $wpvivid_pulgin;
        $wpvivid_pulgin->ajax_check_security();
        if (!isset($_POST['task_id'])||empty($_POST['task_id'])||!is_string($_POST['task_id'])){
            $information['error']='Backup id not found';
            return $information;
        }
        $task_id=sanitize_key($_POST['task_id']);
        if(WPvivid_taskmanager::get_task($task_id)!==false)
        {
            $file_name=WPvivid_taskmanager::get_task_options($task_id,'file_prefix');
            $backup_options=WPvivid_taskmanager::get_task_options($task_id,'backup_options');
            $file=WP_CONTENT_DIR.DIRECTORY_SEPARATOR.$backup_options['dir'].DIRECTORY_SEPARATOR.$file_name.'_cancel';
            touch($file);
        }

        $timestamp = wp_next_scheduled(WPVIVID_TASK_MONITOR_EVENT,array($task_id));

        if($timestamp===false)
        {
            $wpvivid_pulgin->add_monitor_event($task_id,10);
        }
        $information['result']='success';
        $information['msg']=__('The backup will be canceled after backing up the current chunk ends.', 'mainwp-wpvivid-extension');
        return $information;
    }

    public function init_download($backup_id){
        global $wpvivid_pulgin;
        $backup=WPvivid_Backuplist::get_backuplist_by_key($backup_id);
        if($backup===false)
        {
            $information['error']='Backup id not found';
            return $information;
        }
        if(!isset($backup['backup']['files']) && !isset($backup['backup']['ismerge'])){
            $information['error']='Backup id not found';
            return $information;
        }
        else{
            if(isset($backup['backup']['files'])){
                $files=$backup['backup']['files'];
            }
            if(isset($backup['backup']['ismerge'])) {
                if ($backup['backup']['ismerge'] == 1) {
                    if(isset($backup['backup']['data']['meta']['files'])){
                        $files=$backup['backup']['data']['meta']['files'];
                    }
                }
            }
            foreach ($files as $file) {
                $need_download=false;
                $path=WP_CONTENT_DIR.DIRECTORY_SEPARATOR.$backup['local']['path'].DIRECTORY_SEPARATOR.$file['file_name'];
                if(file_exists($path)) {
                    $new_md5=md5_file($path);
                    if($file['md5']!=$new_md5) {
                        $need_download=true;
                    }
                }
                else {
                    $need_download=true;
                }
                $information['files'][$file['file_name']]['size']=$wpvivid_pulgin->formatBytes($file['size']);
                if($need_download) {
                    if(empty($backup['remote'])) {
                        $information['files'][$file['file_name']]['status']='file_not_found';
                    }
                    else{
                        $task = WPvivid_taskmanager::get_download_task_v2($file['file_name']);
                        if ($task === false) {
                            $information['files'][$file['file_name']]['status']='need_download';
                        }
                        else {
                            if($task['status'] === 'running'){
                                $information['files'][$file['file_name']]['status'] = 'running';
                                $information['files'][$file['file_name']]['progress_text']=$task['progress_text'];
                            }
                            elseif($task['status'] === 'timeout'){
                                $information['files'][$file['file_name']]['status']='timeout';
                                $information['files'][$file['file_name']]['progress_text']=$task['progress_text'];
                            }
                            elseif($task['status'] === 'completed'){
                                $information['files'][$file['file_name']]['status']='completed';
                                WPvivid_taskmanager::delete_download_task_v2($file['file_name']);
                            }
                            elseif($task['status'] === 'error'){
                                $information['files'][$file['file_name']]['status']='error';
                                $information['files'][$file['file_name']]['error'] = $task['error'];
                                WPvivid_taskmanager::delete_download_task_v2($file['file_name']);
                            }
                        }
                    }
                }
                else{
                    if(WPvivid_taskmanager::get_download_task_v2($file['file_name']))
                        WPvivid_taskmanager::delete_download_task_v2($file['file_name']);
                    $information['files'][$file['file_name']]['download_path']=$path;
                    $information['files'][$file['file_name']]['status']='completed';
                }
            }
            WPvivid_taskmanager::update_download_cache($backup_id,$information);
        }
        return $information;
    }

    public function init_download_page()
    {
        global $wpvivid_pulgin;
        $wpvivid_pulgin->ajax_check_security();
        if(!isset($_POST['backup_id'])||empty($_POST['backup_id'])||!is_string($_POST['backup_id'])) {
            $information['error']='Invalid parameter param:'.$_POST['backup_id'];
            return $information;
        }
        else {
            $backup_id=sanitize_key($_POST['backup_id']);
            return $this->init_download($backup_id);
        }
    }

    public function prepare_download_backup()
    {
        global $wpvivid_pulgin;
        $wpvivid_pulgin->ajax_check_security();
        if(!isset($_POST['backup_id'])||empty($_POST['backup_id'])||!is_string($_POST['backup_id']))
        {
            $information['error']='Invalid parameter param:'.$_POST['backup_id'];
            return $information;
        }
        if(!isset($_POST['file_name'])||empty($_POST['file_name'])||!is_string($_POST['file_name']))
        {
            $information['error']='Invalid parameter param:'.$_POST['file_name'];
            return $information;
        }
        $download_info=array();
        $download_info['backup_id']=sanitize_key($_POST['backup_id']);
        $download_info['file_name']=sanitize_file_name($_POST['file_name']);

        set_time_limit(600);
        if (session_id())
            session_write_close();
        try
        {
            $downloader=new WPvivid_downloader();
            $downloader->ready_download($download_info);
        }
        catch (Exception $e)
        {
            $message = 'A exception ('.get_class($e).') occurred '.$e->getMessage().' (Code: '.$e->getCode().', line '.$e->getLine().' in '.$e->getFile().')';
            error_log($message);
            return array('error'=>$message);
        }
        catch (Error $e)
        {
            $message = 'A error ('.get_class($e).') has occurred: '.$e->getMessage().' (Code: '.$e->getCode().', line '.$e->getLine().' in '.$e->getFile().')';
            error_log($message);
            return array('error'=>$message);
        }

        $information['result']='success';
        return $information;
    }

    public function get_download_task()
    {
        global $wpvivid_pulgin;
        $wpvivid_pulgin->ajax_check_security();
        if(!isset($_POST['backup_id'])||empty($_POST['backup_id'])||!is_string($_POST['backup_id'])) {
            $information['error']='Invalid parameter param:'.$_POST['backup_id'];
            return $information;
        }
        else {
            $backup_id = $_POST['backup_id'];
            $backup = WPvivid_Backuplist::get_backuplist_by_key($backup_id);
            if ($backup === false) {
                $ret['result'] = MAINWP_WPVIVID_FAILED;
                $ret['error'] = 'backup id not found';
                return $ret;
            }
            $backup_item = new WPvivid_Backup_Item($backup);
            $ret = $backup_item->update_download_page($backup_id);
            return $ret;
        }
    }

    public function download_backup()
    {
        global $wpvivid_pulgin;
        $wpvivid_pulgin->ajax_check_security();
        if(!isset($_POST['backup_id'])||empty($_POST['backup_id'])||!is_string($_POST['backup_id']))
        {
            $information['error']='Invalid parameter param: backup_id';
            return $information;
        }
        if(!isset($_POST['file_name'])||empty($_POST['file_name'])||!is_string($_POST['file_name']))
        {
            $information['error']='Invalid parameter param: file_name';
            return $information;
        }
        $backup_id=sanitize_key($_POST['backup_id']);
        $file_name=sanitize_file_name($_POST['file_name']);
        $cache=WPvivid_taskmanager::get_download_cache($backup_id);
        if($cache===false)
        {
            $this->init_download($backup_id);
            $cache=WPvivid_taskmanager::get_download_cache($backup_id);
        }
        $path=false;
        if(array_key_exists($file_name,$cache['files']))
        {
            if($cache['files'][$file_name]['status']=='completed')
            {
                $path=$cache['files'][$file_name]['download_path'];
            }
        }
        if($path!==false)
        {
            if (file_exists($path))
            {
                $information['path'] = $path;
                $information['size'] = filesize($path);
            }
        }
        return $information;
    }

    public function set_general_setting()
    {
        global $wpvivid_pulgin;
        $wpvivid_pulgin->ajax_check_security();
        $information=array();
        try
        {
            if(isset($_POST['setting'])&&!empty($_POST['setting']))
            {
                $json_setting = $_POST['setting'];
                $json_setting = stripslashes($json_setting);
                $setting = json_decode($json_setting, true);
                if (is_null($setting))
                {
                    $information['error']='bad parameter';
                    return $information;
                }
                WPvivid_Setting::update_setting($setting);
            }

            $information['result']='success';
        }
        catch (Exception $error)
        {
            $message = 'An exception has occurred. class: '.get_class($error).';msg: '.$error->getMessage().';code: '.$error->getCode().';line: '.$error->getLine().';in_file: '.$error->getFile().';';
            error_log($message);
            return array('error'=>$message);
        }

        return $information;
    }

    public function set_schedule()
    {
        global $wpvivid_pulgin;
        $wpvivid_pulgin->ajax_check_security();
        $information=array();
        try
        {
            if(isset($_POST['schedule'])&&!empty($_POST['schedule']))
            {
                $json = $_POST['schedule'];
                $json = stripslashes($json);
                $schedule = json_decode($json, true);
                if (is_null($schedule))
                {
                    $information['error']='bad parameter';
                    return $information;
                }
                $ret=WPvivid_Schedule::set_schedule_ex($schedule);
                if($ret['result']!='success')
                {
                    $information['error']=$ret['error'];
                    return $information;
                }
            }
            $information['result']='success';
        }
        catch (Exception $error)
        {
            $message = 'An exception has occurred. class: '.get_class($error).';msg: '.$error->getMessage().';code: '.$error->getCode().';line: '.$error->getLine().';in_file: '.$error->getFile().';';
            error_log($message);
            return array('error'=>$message);
        }

        return $information;
    }

    public function set_remote()
    {
        global $wpvivid_pulgin;
        $wpvivid_pulgin->ajax_check_security();
        $information=array();
        try
        {
            if(isset($_POST['remote'])&&!empty($_POST['remote']))
            {
                $json = $_POST['remote'];
                $json = stripslashes($json);
                $remote = json_decode($json, true);
                if (is_null($remote))
                {
                    $information['error']='bad parameter';
                    return $information;
                }

                WPvivid_Setting::update_option('wpvivid_upload_setting',$remote['upload']);

                $history=WPvivid_Setting::get_option('wpvivid_user_history');
                $history['remote_selected']=$remote['history']['remote_selected'];
                WPvivid_Setting::update_option('wpvivid_user_history',$history);
            }
            $information['result']='success';
        }
        catch (Exception $error)
        {
            $message = 'An exception has occurred. class: '.get_class($error).';msg: '.$error->getMessage().';code: '.$error->getCode().';line: '.$error->getLine().';in_file: '.$error->getFile().';';
            error_log($message);
            return array('error'=>$message);
        }

        return $information;
    }
}