<?php

class MainWPChildWordfence
{   
    public static $instance = null;   
    private static $wfLog = false;
    
    public static $options_filter = array(
            'alertEmails',
            'alertOn_adminLogin',
            'alertOn_block',
            'alertOn_critical',
            'alertOn_loginLockout',
            'alertOn_lostPasswdForm',
            'alertOn_nonAdminLogin',
            'alertOn_update',
            'alertOn_warnings',
            'alert_maxHourly',
            'autoUpdate',
            'firewallEnabled',
            'howGetIPs',
            'liveTrafficEnabled',
            'loginSec_blockAdminReg',
            'loginSec_countFailMins',
            'loginSec_disableAuthorScan',
            'loginSec_lockInvalidUsers',
            'loginSec_lockoutMins',
            'loginSec_maskLoginErrors',
            'loginSec_maxFailures',
            'loginSec_maxForgotPasswd',
            'loginSec_strongPasswds',
            'loginSec_userBlacklist',
            'loginSecurityEnabled',
            'other_scanOutside',
            'scan_exclude',
            'scansEnabled_comments',
            'scansEnabled_core',
            'scansEnabled_diskSpace',
            'scansEnabled_dns',
            'scansEnabled_fileContents',
            'scansEnabled_heartbleed',
            'scansEnabled_highSense',
            'scansEnabled_malware',
            'scansEnabled_oldVersions',
            'scansEnabled_options',
            'scansEnabled_passwds',
            'scansEnabled_plugins',
            'scansEnabled_posts',
            'scansEnabled_scanImages',
            'scansEnabled_themes',
            'scheduledScansEnabled',
            'securityLevel',
            //'scheduleScan' // filtered this
            'blockFakeBots',
            'neverBlockBG',
            'maxGlobalRequests',
            'maxGlobalRequests_action',     
            'maxRequestsCrawlers',
            'maxRequestsCrawlers_action',
            'max404Crawlers',
            'max404Crawlers_action',
            'maxRequestsHumans',
            'maxRequestsHumans_action',
            'max404Humans',
            'max404Humans_action',
            'maxScanHits',
            'maxScanHits_action',
            'blockedTime'       
        );

     
    static function Instance() {
        if (MainWPChildWordfence::$instance == null) {
            MainWPChildWordfence::$instance = new MainWPChildWordfence();
        }
        return MainWPChildWordfence::$instance;
    }    
    
    
    public function __construct() {
        add_action('mainwp_child_deactivation', array($this, 'deactivation'));
    }
    
    public function deactivation()
    {
        if ($sched = wp_next_scheduled('mainwp_child_wordfence_cron_scan')) {
            wp_unschedule_event($sched, 'mainwp_child_wordfence_cron_scan');
        }
    }
    
    
    public function action() {   
        $information = array();
        if (!class_exists('wordfence') || !class_exists('wfScanEngine')) {
            $information['error'] = 'NO_WORDFENCE';
            MainWPHelper::write($information);
        }   
        if (isset($_POST['mwp_action'])) {
            switch ($_POST['mwp_action']) {
                case "start_scan":
                    $information = $this->start_scan();
                break;                 
                case "set_showhide":
                    $information = $this->set_showhide();
                break;
                case "get_log":
                    $information = $this->get_log();
                break;
                case "update_log":
                    $information = $this->update_log();
                break;
                case "get_summary":
                    $information = $this->get_summary();
                break;
                case "load_issues":
                    $information = $this->load_issues();
                break;
                case "update_all_issues":
                    $information = $this->update_all_issues();
                break;    
                case "update_issues_status":
                    $information = $this->update_issues_status();
                break; 
                case "delete_issues":
                    $information = $this->delete_issues();
                break; 
                case "bulk_operation":
                    $information = $this->bulk_operation();
                break; 
                case "delete_file":
                    $information = $this->delete_file();
                break;
                case "restore_file":
                    $information = $this->restore_file();
                break;
                case "save_setting":
                    $information = $this->save_setting();
                break;
            }        
        }
        MainWPHelper::write($information);
    }
    
    private function start_scan() {  
        $information = array();
        if(wfUtils::isScanRunning()){
            $information['error'] = "SCAN_RUNNING";
            return $information;
        }                        
        $err = wfScanEngine::startScan();
        if($err){
                $information['error'] = htmlentities($err);
        } else {
                $information['result'] = 'SUCCESS';
        }
        return $information;
    }    
    
    function set_showhide() {
        MainWPHelper::update_option('mainwp_wordfence_ext_enabled', "Y");        
        $hide = isset($_POST['showhide']) && ($_POST['showhide'] === "hide") ? 'hide' : "";
        MainWPHelper::update_option('mainwp_wordfence_hide_plugin', $hide);        
        $information['result'] = 'SUCCESS';
        return $information;
    }
    public function wordfence_init()
    {  
        if (get_option('mainwp_wordfence_ext_enabled') !== "Y")
            return;
        
        if (get_option('mainwp_wordfence_hide_plugin') === "hide")
        {
            add_filter('all_plugins', array($this, 'all_plugins'));   
            add_action( 'admin_menu', array($this, 'remove_menu'));
        }
        $this->init_cron();
        
    }
    
    public function init_cron() {                       
        $sched = wp_next_scheduled('mainwp_child_wordfence_cron_scan');             
        $sch = get_option("mainwp_child_wordfence_cron_time");
        if ($sch == "twicedaily" ||
            $sch == "daily" ||
            $sch == "weekly" ||
            $sch == "monthly") {
            add_action('mainwp_child_wordfence_cron_scan', array($this, 'wfc_cron_scan'));        
            if ($sched == false)
            {   
                $sched = wp_schedule_event(time(), $sch, 'mainwp_child_wordfence_cron_scan');
            }  
        } else {
            if ($sched != false) {
                wp_unschedule_event($sched, 'mainwp_child_wordfence_cron_scan');
            }
        }
    }  
    
    public function wfc_cron_scan() {        
        $this->start_scan();
    }
    
    public function all_plugins($plugins) {
        foreach ($plugins as $key => $value)
        {
            $plugin_slug = basename($key, '.php');
            if ($plugin_slug == 'wordfence')
                unset($plugins[$key]);
        }
        return $plugins;       
    }
    
    public function remove_menu() {
        remove_menu_page('Wordfence');  
    }  
    
    public function get_log(){        
        $information = array('events' => self::getLog()->getStatusEvents(0));  
        $information['summary'] = self::getLog()->getSummaryEvents();
        $information['debugOn'] = wfConfig::get('debugOn', false);
        $information['updateInt'] = wfConfig::get('actUpdateInterval', 2);    
        $information['timeOffset'] = 3600 * get_option('gmt_offset');
        return $information;
    } 
    
    private static function getLog(){
        if(! self::$wfLog){
            $wfLog = new wfLog(wfConfig::get('apiKey'), wfUtils::getWPVersion());
            self::$wfLog = $wfLog;
        }
        return self::$wfLog;
    }
        
    public function update_log() {                
        return wordfence::ajax_activityLogUpdate_callback(); 
    }   
   
    public function load_issues() {
        $i = new wfIssues();
        $iss = $i->getIssues();
        return array(
                'issuesLists' => $iss,
                'summary' => $i->getSummaryItems(),
                'lastScanCompleted' => wfConfig::get('lastScanCompleted')
                );
    }
    function update_all_issues() {        
        $op = $_POST['op'];
        $i = new wfIssues();
        if($op == 'deleteIgnored'){
                $i->deleteIgnored();	
        } else if($op == 'deleteNew'){
                $i->deleteNew();
        } else if($op == 'ignoreAllNew'){
                $i->ignoreAllNew();
        } else {
                return array('errorMsg' => "An invalid operation was called.");
        }
        return array('ok' => 1);	
    }
    
    function update_issues_status() {        
        $wfIssues = new wfIssues();
        $status = $_POST['status'];
        $issueID = $_POST['id'];
        if(! preg_match('/^(?:new|delete|ignoreP|ignoreC)$/', $status)){
                return array('errorMsg' => "An invalid status was specified when trying to update that issue.");
        }
        $wfIssues->updateIssue($issueID, $status);
        return array('ok' => 1);
    }
    
    function delete_issues(){
            $wfIssues = new wfIssues();
            $issueID = $_POST['id'];
            $wfIssues->deleteIssue($issueID);
            return array('ok' => 1);
    }
    function bulk_operation(){
            $op = $_POST['op'];
            if($op == 'del' || $op == 'repair'){
                    $ids = $_POST['ids'];
                    $filesWorkedOn = 0;
                    $errors = array();
                    $issues = new wfIssues();
                    foreach($ids as $id){
                            $issue = $issues->getIssueByID($id);
                            if(! $issue){
                                    $errors[] = "Could not delete one of the files because we could not find the issue. Perhaps it's been resolved?";
                                    continue;
                            }
                            $file = $issue['data']['file'];
                            $localFile = ABSPATH . '/' . preg_replace('/^[\.\/]+/', '', $file);
                            $localFile = realpath($localFile);
                            if(strpos($localFile, ABSPATH) !== 0){
                                    $errors[] = "An invalid file was requested: " . htmlentities($file);
                                    continue;
                            }
                            if($op == 'del'){
                                    if(@unlink($localFile)){
                                            $issues->updateIssue($id, 'delete');
                                            $filesWorkedOn++;
                                    } else {
                                            $err = error_get_last();
                                            $errors[] = "Could not delete file " . htmlentities($file) . ". Error was: " . htmlentities($err['message']);
                                    }
                            } else if($op == 'repair'){
                                    $dat = $issue['data'];	
                                    $result = self::getWPFileContent($dat['file'], $dat['cType'], $dat['cName'], $dat['cVersion']);
                                    if($result['cerrorMsg']){
                                            $errors[] = $result['cerrorMsg'];
                                            continue;
                                    } else if(! $result['fileContent']){
                                            $errors[] = "We could not get the original file of " . htmlentities($file) . " to do a repair.";
                                            continue;
                                    }

                                    if(preg_match('/\.\./', $file)){
                                            $errors[] = "An invalid file " . htmlentities($file) . " was specified for repair.";
                                            continue;
                                    }
                                    $fh = fopen($localFile, 'w');
                                    if(! $fh){
                                            $err = error_get_last();
                                            if(preg_match('/Permission denied/i', $err['message'])){
                                                    $errMsg = "You don't have permission to repair " . htmlentities($file) . ". You need to either fix the file manually using FTP or change the file permissions and ownership so that your web server has write access to repair the file.";
                                            } else {
                                                    $errMsg = "We could not write to " . htmlentities($file) . ". The error was: " . $err['message'];
                                            }
                                            $errors[] = $errMsg;
                                            continue;
                                    }
                                    flock($fh, LOCK_EX);
                                    $bytes = fwrite($fh, $result['fileContent']);
                                    flock($fh, LOCK_UN);
                                    fclose($fh);
                                    if($bytes < 1){
                                            $errors[] = "We could not write to " . htmlentities($file) . ". ($bytes bytes written) You may not have permission to modify files on your WordPress server.";
                                            continue;
                                    }
                                    $filesWorkedOn++;
                                    $issues->updateIssue($id, 'delete');
                            }
                    }
                    $headMsg = "";
                    $bodyMsg = "";
                    $verb = $op == 'del' ? 'Deleted' : 'Repaired';
                    $verb2 = $op == 'del' ? 'delete' : 'repair';
                    if($filesWorkedOn > 0 && sizeof($errors) > 0){
                            $headMsg = "$verb some files with errors";
                            $bodyMsg = "$verb $filesWorkedOn files but we encountered the following errors with other files: " . implode('<br />', $errors);
                    } else if($filesWorkedOn > 0){
                            $headMsg = "$verb $filesWorkedOn files successfully";
                            $bodyMsg = "$verb $filesWorkedOn files successfully. No errors were encountered.";
                    } else if(sizeof($errors) > 0){
                            $headMsg = "Could not $verb2 files";
                            $bodyMsg = "We could not $verb2 any of the files you selected. We encountered the following errors: " . implode('<br />', $errors);
                    } else {
                            $headMsg = "Nothing done";
                            $bodyMsg = "We didn't $verb2 anything and no errors were found.";
                    }

                    return array('ok' => 1, 'bulkHeading' => $headMsg, 'bulkBody' => $bodyMsg);
            } else {
                    return array('errorMsg' => "Invalid bulk operation selected");
            }
    }   
    
    function delete_file(){
		$issueID = $_POST['issueID'];
		$wfIssues = new wfIssues();
		$issue = $wfIssues->getIssueByID($issueID);
		if(! $issue){
			return array('errorMsg' => "Could not delete file because we could not find that issue.");
		}
		if(! $issue['data']['file']){
			return array('errorMsg' => "Could not delete file because that issue does not appear to be a file related issue.");
		}
		$file = $issue['data']['file'];
		$localFile = ABSPATH . '/' . preg_replace('/^[\.\/]+/', '', $file);
		$localFile = realpath($localFile);
		if(strpos($localFile, ABSPATH) !== 0){
			return array('errorMsg' => "An invalid file was requested for deletion.");
		}
		if(@unlink($localFile)){
			$wfIssues->updateIssue($issueID, 'delete');
			return array(
				'ok' => 1,
				'localFile' => $localFile,
				'file' => $file
				);
		} else {
			$err = error_get_last();
			return array('errorMsg' => "Could not delete file " . htmlentities($file) . ". The error was: " . htmlentities($err['message']));
		}
	}
        
	function restore_file(){
		$issueID = $_POST['issueID'];
		$wfIssues = new wfIssues();
		$issue = $wfIssues->getIssueByID($issueID);
		if(! $issue){
			return array('cerrorMsg' => "We could not find that issue in our database.");
		}
		$dat = $issue['data'];	
		$result = self::getWPFileContent($dat['file'], $dat['cType'], (isset($dat['cName']) ? $dat['cName'] : ''), (isset($dat['cVersion']) ? $dat['cVersion'] : ''));
		$file = $dat['file'];
		if(isset($result['cerrorMsg']) && $result['cerrorMsg']){
			return $result;
		} else if(! $result['fileContent']){
			return array('cerrorMsg' => "We could not get the original file to do a repair.");
		}
		
		if(preg_match('/\.\./', $file)){
			return array('cerrorMsg' => "An invalid file was specified for repair.");
		}
		$localFile = ABSPATH . '/' . preg_replace('/^[\.\/]+/', '', $file);
		$fh = fopen($localFile, 'w');
		if(! $fh){
			$err = error_get_last();
			if(preg_match('/Permission denied/i', $err['message'])){
				$errMsg = "You don't have permission to repair that file. You need to either fix the file manually using FTP or change the file permissions and ownership so that your web server has write access to repair the file.";
			} else {
				$errMsg = "We could not write to that file. The error was: " . $err['message'];
			}
			return array('cerrorMsg' => $errMsg);
		}
		flock($fh, LOCK_EX);
		$bytes = fwrite($fh, $result['fileContent']);
		flock($fh, LOCK_UN);
		fclose($fh);
		if($bytes < 1){
			return array('cerrorMsg' => "We could not write to that file. ($bytes bytes written) You may not have permission to modify files on your WordPress server.");
		}
		$wfIssues->updateIssue($issueID, 'delete');
		return array(
			'ok' => 1,
			'file' => $localFile
			);
	}
        
        function save_setting() {
            $settings = unserialize(base64_decode($_POST['settings']));
            if (is_array($settings) && count($settings) > 0) {
                $opts = $settings;		
		foreach($opts as $key => $val){
                    if (in_array($key, self::$options_filter)) {
                        if($key != 'apiKey'){ //Don't save API key yet
                            wfConfig::set($key, $val);
                        }
                    }
		}
		
		if($opts['autoUpdate'] == '1'){
			wfConfig::enableAutoUpdate();
		} else if($opts['autoUpdate'] == '0'){
			wfConfig::disableAutoUpdate();
		}
                
                $sch = isset($opts['scheduleScan']) ? $opts['scheduleScan'] : "";     
                if ($sch != get_option('mainwp_child_wordfence_cron_time')) {
                    update_option('mainwp_child_wordfence_cron_time', $sch);
                    $sched = wp_next_scheduled('mainwp_child_wordfence_cron_scan');
                    if ($sched != false) {
                        wp_unschedule_event($sched, 'mainwp_child_wordfence_cron_scan');
                    }
                }
                
		return array('result' => 'SUCCESS');
            }
        }
        
}

