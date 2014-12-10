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
            'blockedTime',
            'liveTraf_ignorePublishers',
            'liveTraf_ignoreUsers',
            'liveTraf_ignoreIPs',
            'liveTraf_ignoreUA',
        
            'whitelisted',
            'bannedURLs',
            'other_hideWPVersion',
            'other_noAnonMemberComments',
            'other_scanComments',
            'other_pwStrengthOnUpdate',
            'other_WFNet',
            'maxMem',
            'maxExecutionTime',
            'actUpdateInterval',
            'debugOn',
            'deleteTablesOnDeact',
            'disableCookies',
            'startScansRemotely',
            'disableConfigCaching',
            'addCacheComment',
            'isPaid',        
            "advancedCommentScanning", 
            "checkSpamIP", 
            "spamvertizeCheck", 
            'scansEnabled_public'
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
                case "ticker":
                    $information = $this->ticker();
                break;
                case "reverse_lookup":
                    $information = $this->reverse_lookup();
                break;
                case "update_live_traffic":
                    $information = $this->update_live_traffic();
                break;
                case "block_ip":
                    $information = $this->block_ip();
                break;
                case "unblock_ip":
                    $information = $this->unblock_ip();
                break;          
                case "load_static_panel":
                    $information = $this->load_static_panel();
                break;   
                case "downgrade_license":
                    $information = $this->downgrade_license();
                break;            
            }        
        }
        MainWPHelper::write($information);
    }
    
    private function start_scan() {  
        $information = array();
        if (!class_exists('wordfence') || !class_exists('wfScanEngine')) {
            $information['error'] = 'NO_WORDFENCE';
             return $information;            
        }
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
        $information = array();
        $wfLog = self::getLog();
        if ($wfLog) {
            $information['events'] = $wfLog->getStatusEvents(0);
            $information['summary'] = $wfLog->getSummaryEvents();
        }        
        $information['debugOn'] = wfConfig::get('debugOn', false);         
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
        //error_log("wp-ajax: " . wp_create_nonce('wp-ajax'));
        return array(
                'issuesLists' => $iss,
                'summary' => $i->getSummaryItems(),
                'lastScanCompleted' => wfConfig::get('lastScanCompleted'),
                'apiKey' => wfConfig::get('apiKey'),
                'isPaid' => wfConfig::get('isPaid')
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
		$result = wordfence::getWPFileContent($dat['file'], $dat['cType'], (isset($dat['cName']) ? $dat['cName'] : ''), (isset($dat['cVersion']) ? $dat['cVersion'] : ''));
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
            MainWPHelper::update_option('mainwp_wordfence_ext_enabled', "Y");   
            $settings = unserialize(base64_decode($_POST['settings']));
            if (is_array($settings) && count($settings) > 0) {
                $result = array();
                $reload = '';
                $opts = $settings;		
                $validUsers = array();
                $invalidUsers = array();
                foreach(explode(',', $opts['liveTraf_ignoreUsers']) as $val){
                        $val = trim($val);
                        if(strlen($val) > 0){
                                if(get_user_by('login', $val)){
                                        $validUsers[] = $val;
                                } else {
                                        $invalidUsers[] = $val;
                                }
                        }
                }  
                
                if(sizeof($invalidUsers) > 0){
                       // return array('errorMsg' => "The following users you selected to ignore in live traffic reports are not valid on this system: " . htmlentities(implode(', ', $invalidUsers)) );
                    $result['invalid_users'] = htmlentities(implode(', ', $invalidUsers)); 
                }
                
                if(sizeof($validUsers) > 0){
                        $opts['liveTraf_ignoreUsers'] = implode(',', $validUsers);
                } else {
                        $opts['liveTraf_ignoreUsers'] = '';
                }

                if(! $opts['other_WFNet']){	
			$wfdb = new wfDB();
			global $wpdb;
			$p = $wpdb->base_prefix;
			$wfdb->queryWrite("delete from $p"."wfBlocks where wfsn=1 and permanent=0");
		}
                
                $regenerateHtaccess = false;
		if(wfConfig::get('bannedURLs', false) != $opts['bannedURLs']){
			$regenerateHtaccess = true;
		}
                
                foreach($opts as $key => $val){
                    if (in_array($key, self::$options_filter)) {
                        if($key != 'apiKey'){ //Don't save API key yet
                            wfConfig::set($key, $val);
                        }
                    }
		}
                
                if($regenerateHtaccess){
			wfCache::addHtaccessCode('add');
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
                
                $result['cacheType'] = wfConfig::get('cacheType');                  
                $result['paidKeyMsg'] = false; 
                $apiKey = trim($_POST['apiKey']);
                if(! $apiKey){ //Empty API key (after trim above), then try to get one.
			$api = new wfAPI('', wfUtils::getWPVersion());
			try {
				$keyData = $api->call('get_anon_api_key');
				if($keyData['ok'] && $keyData['apiKey']){
					wfConfig::set('apiKey', $keyData['apiKey']);
					wfConfig::set('isPaid', 0);
                                        $result['apiKey'] = $keyData['apiKey'];
                                        $result['isPaid'] = 0;
                                        $reload = 'reload';
				} else {
					throw new Exception("We could not understand the Wordfence server's response because it did not contain an 'ok' and 'apiKey' element.");
				}
			} catch(Exception $e){
				$result['error'] = "Your options have been saved, but we encountered a problem. You left your API key blank, so we tried to get you a free API key from the Wordfence servers. However we encountered a problem fetching the free key: " . htmlentities($e->getMessage()) ;
                                return $result;
			}
		} else if($apiKey != wfConfig::get('apiKey')){
			$api = new wfAPI($apiKey, wfUtils::getWPVersion());
			try {
				$res = $api->call('check_api_key', array(), array());
				if($res['ok'] && isset($res['isPaid'])){
					wfConfig::set('apiKey', $apiKey);					
					wfConfig::set('isPaid', $res['isPaid']); //res['isPaid'] is boolean coming back as JSON and turned back into PHP struct. Assuming JSON to PHP handles bools.
                                        $result['apiKey'] = $apiKey;
                                        $result['isPaid'] = $res['isPaid'];
					if($res['isPaid']){
                                            $result['paidKeyMsg'] = true;
					}
                                        $reload = 'reload';
				} else {
					throw new Exception("We could not understand the Wordfence API server reply when updating your API key.");
				}
			} catch (Exception $e){
                                $result['error'] = "Your options have been saved. However we noticed you changed your API key and we tried to verify it with the Wordfence servers and received an error: " . htmlentities($e->getMessage());
				return $result;
			}
		} else {
                    try {
			$api = new wfAPI($apiKey, wfUtils::getWPVersion());
			$res = $api->call('ping_api_key', array(), array());
                    } catch (Exception $e){
                        $result['error'] = "Your options have been saved. However we noticed you do not change your API key and we tried to verify it with the Wordfence servers and received an error: " . htmlentities($e->getMessage());
                        return $result;
                    }
		}
                $result['ok'] = 1;
                $result['reload'] = $reload;                        
		return $result;
            }
        }
        
        function update_live_traffic() {
//            if (isset($_POST['liveTrafficEnabled'])) {
//                wfConfig::set('liveTrafficEnabled', $_POST['liveTrafficEnabled']);
//                return array(
//                    'ok' => 1                    
//                );
//            }
        }
        
        function ticker() {
            $wfdb = new wfDB();
            global $wpdb;
            $p = $wpdb->base_prefix;

            $serverTime = $wfdb->querySingle("select unix_timestamp()");
            $issues = new wfIssues();
            $jsonData = array(
                    'serverTime' => $serverTime,
                    'msg' => $wfdb->querySingle("select msg from $p"."wfStatus where level < 3 order by ctime desc limit 1")
                    );
            $events = array();
            $alsoGet = $_POST['alsoGet'];
            if(preg_match('/^logList_(404|hit|human|ruser|crawler|gCrawler|loginLogout)$/', $alsoGet, $m)){
                    $type = $m[1];
                    $newestEventTime = $_POST['otherParams'];
                    $listType = 'hits';
                    if($type == 'loginLogout'){
                            $listType = 'logins';
                    }
                    $events = self::getLog()->getHits($listType, $type, $newestEventTime);
            } else if($alsoGet == 'perfStats'){
                    $newestEventTime = $_POST['otherParams'];
                    $events = self::getLog()->getPerfStats($newestEventTime);
            }
            /*
            $longest = 0;
            foreach($events as $e){
                    $length = $e['domainLookupEnd'] + $e['connectEnd'] + $e['responseStart'] + $e['responseEnd'] + $e['domReady'] + $e['loaded'];
                    $longest = $length > $longest ? $length : $longest;
            }
            */
            $jsonData['events'] = $events;
            $jsonData['alsoGet'] = $alsoGet; //send it back so we don't load data if panel has changed
            $jsonData['cacheType'] = wfConfig::get('cacheType');                
            return $jsonData;
        }

        function reverse_lookup() {
            $ips = explode(',', $_POST['ips']);
            $res = array();
            foreach($ips as $ip){
                    $res[$ip] = wfUtils::reverseLookup($ip);
            }
            return array('ok' => 1, 'ips' => $res);
        }
        
        function block_ip() {
            $IP = trim($_POST['IP']);
            $perm = $_POST['perm'] == '1' ? true : false;
            if(! preg_match('/^\d+\.\d+\.\d+\.\d+$/', $IP)){
                    return array('err' => 1, 'errorMsg' => "Please enter a valid IP address to block.");
            }
            if($IP == wfUtils::getIP()){
                    return array('err' => 1, 'errorMsg' => "You can't block your own IP address.");
            }
            if(self::getLog()->isWhitelisted($IP)){
                    return array('err' => 1, 'errorMsg' => "The IP address " . htmlentities($IP) . " is whitelisted and can't be blocked or it is in a range of internal IP addresses that Wordfence does not block. You can remove this IP from the whitelist on the Wordfence options page.");
            }
            if(wfConfig::get('neverBlockBG') != 'treatAsOtherCrawlers'){ //Either neverBlockVerified or neverBlockUA is selected which means the user doesn't want to block google 
                    if(wfCrawl::verifyCrawlerPTR('/googlebot\.com$/i', $IP)){
                            return array('err' => 1, 'errorMsg' => "The IP address you're trying to block belongs to Google. Your options are currently set to not block these crawlers. Change this in Wordfence options if you want to manually block Google.");
                    }
            }
            self::getLog()->blockIP($IP, $_POST['reason'], false, $perm);
            return array('ok' => 1);
        }
        
        function unblock_ip() {          
            if (isset($_POST['IP'])) {
                  $IP = $_POST['IP'];
                self::getLog()->unblockIP($IP);
                return array('ok' => 1);
            }
        }         
        
        public function load_static_panel(){
		$mode = $_POST['mode'];
		$wfLog = self::getLog();
		if($mode == 'topScanners' || $mode == 'topLeechers'){
			$results = $wfLog->getLeechers($mode);
		} else if($mode == 'blockedIPs'){
			$results = $wfLog->getBlockedIPs();
		} else if($mode == 'lockedOutIPs'){
			$results = $wfLog->getLockedOutIPs();
		} else if($mode == 'throttledIPs'){
			$results = $wfLog->getThrottledIPs();
		}
		return array('ok' => 1, 'results' => $results);
	}
        
        public function downgrade_license(){
		$api = new wfAPI('', wfUtils::getWPVersion());
                $return = array();
		try {
                    $keyData = $api->call('get_anon_api_key');
                    if($keyData['ok'] && $keyData['apiKey']){
                            wfConfig::set('apiKey', $keyData['apiKey']);
                            wfConfig::set('isPaid', 0);
                            $return['apiKey'] = $keyData['apiKey'];
                            $return['isPaid'] = 0;
                            //When downgrading we must disable all two factor authentication because it can lock an admin out if we don't. 
                            wfConfig::set_ser('twoFactorUsers', array());
                    } else {
                            throw new Exception("Could not understand the response we received from the Wordfence servers when applying for a free API key.");
                    }
		} catch(Exception $e){
                    $return['errorMsg'] = "Could not fetch free API key from Wordfence: " . htmlentities($e->getMessage());
                    return $return;
		}
                $return['ok'] = 1;
		return $return;
	}
        
}

