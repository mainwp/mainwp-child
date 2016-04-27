<?php

class MainWP_Child_Wordfence {
	public static $instance = null;
	private static $wfLog = false;
	public $is_wordfence_installed = false;
	public $plugin_translate = 'mainwp-child';

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
		'scansEnabled_database',
		'scansEnabled_heartbleed',
		'scansEnabled_highSense',
		'scansEnabled_malware',
		'scansEnabled_oldVersions',
		'scansEnabled_passwds',
		'scansEnabled_plugins',
		'scansEnabled_posts',
		'scansEnabled_scanImages',
		'scansEnabled_themes',
		'scheduledScansEnabled',
		'securityLevel',
		//'scheduleScan' // NOTE: filtered, not save
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
		'disableCodeExecutionUploads',
		//'isPaid',
		"advancedCommentScanning",
		"checkSpamIP",
		"spamvertizeCheck",
		'scansEnabled_public',
		'email_summary_enabled',
		'email_summary_dashboard_widget_enabled',
		'ssl_verify',
		'email_summary_interval',
		'email_summary_excluded_directories',
		'allowed404s',
	);


	static function Instance() {
		if ( null === MainWP_Child_Wordfence::$instance ) {
			MainWP_Child_Wordfence::$instance = new MainWP_Child_Wordfence();
		}

		return MainWP_Child_Wordfence::$instance;
	}


	public function __construct() {
		add_action( 'mainwp_child_deactivation', array( $this, 'deactivation' ) );

		require_once( ABSPATH . 'wp-admin/includes/plugin.php' );

		if ( is_plugin_active( 'wordfence/wordfence.php' ) && file_exists( plugin_dir_path( __FILE__ ) . '../../wordfence/wordfence.php' ) ) {
			require_once( plugin_dir_path( __FILE__ ) . '../../wordfence/wordfence.php' );
			$this->is_wordfence_installed = true;
		}

		if ( $this->is_wordfence_installed ) {
			add_action( 'wp_ajax_mainwp_wordfence_download_htaccess', array( $this, 'downloadHtaccess' ) );
		}

	}

	public function deactivation() {
		if ( $sched = wp_next_scheduled( 'mainwp_child_wordfence_cron_scan' ) ) {
			wp_unschedule_event( $sched, 'mainwp_child_wordfence_cron_scan' );
		}
	}


	public function action() {
		$information = array();
		if ( ! $this->is_wordfence_installed ) {
			MainWP_Helper::write( array( 'error' => __( 'Please install Wordfence plugin on child website', $this->plugin_translate ) ) );
			return;
		}

		if ( ! class_exists( 'wordfence' ) || ! class_exists( 'wfScanEngine' ) ) {
			$information['error'] = 'NO_WORDFENCE';
			MainWP_Helper::write( $information );
		}
		if ( isset( $_POST['mwp_action'] ) ) {
			MainWP_Helper::update_option('mainwp_wordfence_ext_enabled', "Y", 'yes');
			switch ( $_POST['mwp_action'] ) {
				case 'start_scan':
					$information = $this->start_scan();
					break;
				case 'set_showhide':
					$information = $this->set_showhide();
					break;
				case 'get_log':
					$information = $this->get_log();
					break;
				case 'update_log':
					$information = $this->update_log();
					break;
				case 'get_summary':
					$information = $this->get_summary();
					break;
				case 'load_issues':
					$information = $this->load_issues();
					break;
				case 'update_all_issues':
					$information = $this->update_all_issues();
					break;
				case 'update_issues_status':
					$information = $this->update_issues_status();
					break;
				case 'delete_issues':
					$information = $this->delete_issues();
					break;
				case 'bulk_operation':
					$information = $this->bulk_operation();
					break;
				case 'delete_file':
					$information = $this->delete_file();
					break;
				case 'restore_file':
					$information = $this->restore_file();
					break;
				case 'save_setting':
					$information = $this->save_setting();
					break;
				case 'ticker':
					$information = $this->ticker();
					break;
				case 'reverse_lookup':
					$information = $this->reverse_lookup();
					break;
				case 'block_ip':
					$information = $this->block_ip();
					break;
				case 'unblock_ip':
					$information = $this->unblock_ip();
					break;
				case 'load_static_panel':
					$information = $this->load_static_panel();
					break;
				case 'downgrade_license':
					$information = $this->downgrade_license();
					break;
				case "import_settings":
					$information = $this->import_settings();
					break;
				case "export_settings":
					$information = $this->export_settings();
					break;
				case "save_cache_config":
					$information = $this->saveCacheConfig();
					break;
				case "check_falcon_htaccess":
					$information = $this->checkFalconHtaccess();
					break;
				case "save_cache_options":
					$information = $this->saveCacheOptions();
					break;
				case "clear_page_cache":
					$information = $this->clearPageCache();
					break;
				case "get_cache_stats":
					$information = $this->getCacheStats();
					break;
				case "add_cache_exclusion":
					$information = $this->addCacheExclusion();
					break;
				case "load_cache_exclusions":
					$information = $this->loadCacheExclusions();
					break;
				case "remove_cache_exclusion":
					$information = $this->removeCacheExclusion();
					break;
			}
		}
		MainWP_Helper::write( $information );
	}

	private function start_scan() {
		$information = array();
		if ( ! class_exists( 'wordfence' ) || ! class_exists( 'wfScanEngine' ) ) {
			$information['error'] = 'NO_WORDFENCE';

			return $information;
		}
		if ( wfUtils::isScanRunning() ) {
			$information['error'] = 'SCAN_RUNNING';

			return $information;
		}
		$err = wfScanEngine::startScan();
		if ( $err ) {
			$information['error'] = htmlentities( $err );
		} else {
			$information['result'] = 'SUCCESS';
		}

		return $information;
	}

	function set_showhide() {
		$hide = isset( $_POST['showhide'] ) && ( $_POST['showhide'] === 'hide' ) ? 'hide' : '';
		MainWP_Helper::update_option( 'mainwp_wordfence_hide_plugin', $hide );
		$information['result'] = 'SUCCESS';

		return $information;
	}

	public function wordfence_init() {
		if ( get_option( 'mainwp_wordfence_ext_enabled' ) !== 'Y' ) {
			return;
		}

		if ( get_option( 'mainwp_wordfence_hide_plugin' ) === 'hide' ) {
			add_filter( 'all_plugins', array( $this, 'all_plugins' ) );
			add_action( 'admin_menu', array( $this, 'remove_menu' ) );
			add_action( 'admin_init', array( $this, 'admin_init' ) );
		}
		$this->init_cron();

	}

	public function admin_init() {
		remove_meta_box( 'wordfence_activity_report_widget', 'dashboard', 'normal' );
	}

	public function init_cron() {
		$sched = wp_next_scheduled( 'mainwp_child_wordfence_cron_scan' );
		$sch   = get_option( 'mainwp_child_wordfence_cron_time' );
		if ( 'twicedaily' === $sch ||
		     'daily' === $sch ||
		     'weekly' === $sch ||
		     'monthly' === $sch
		) {
			add_action( 'mainwp_child_wordfence_cron_scan', array( $this, 'wfc_cron_scan' ) );
			if ( false === $sched ) {
				$sched = wp_schedule_event( time(), $sch, 'mainwp_child_wordfence_cron_scan' );
			}
		} else {
			if ( false !== $sched ) {
				wp_unschedule_event( $sched, 'mainwp_child_wordfence_cron_scan' );
			}
		}
	}

	public function wfc_cron_scan() {
		$this->start_scan();
	}

	public function all_plugins( $plugins ) {
		foreach ( $plugins as $key => $value ) {
			$plugin_slug = basename( $key, '.php' );
			if ( 'wordfence' === $plugin_slug ) {
				unset( $plugins[ $key ] );
			}
		}

		return $plugins;
	}

	public function remove_menu() {
		remove_menu_page( 'Wordfence' );
	}

	public function get_log() {
		$information = array();
		$wfLog       = self::getLog();
		if ( $wfLog ) {
			$information['events']  = $wfLog->getStatusEvents( 0 );
			$information['summary'] = $wfLog->getSummaryEvents();
		}
		$information['debugOn']    = wfConfig::get( 'debugOn', false );
		$information['timeOffset'] = 3600 * get_option( 'gmt_offset' );

		return $information;
	}

	private static function getLog() {
		if ( ! self::$wfLog ) {
			$wfLog       = new wfLog( wfConfig::get( 'apiKey' ), wfUtils::getWPVersion() );
			self::$wfLog = $wfLog;
		}

		return self::$wfLog;
	}

	public function update_log() {
		return wordfence::ajax_activityLogUpdate_callback();
	}

	public function load_issues() {
		$i   = new wfIssues();
		$iss = $i->getIssues();

		//error_log("wp-ajax: " . wp_create_nonce('wp-ajax'));
		return array(
			'issuesLists'       => $iss,
			'summary'           => $i->getSummaryItems(),
			'lastScanCompleted' => wfConfig::get( 'lastScanCompleted' ),
			'apiKey'            => wfConfig::get( 'apiKey' ),
			'isPaid' => wfConfig::get('isPaid'),
			'lastscan_timestamp' => $this->get_lastscan()
		);
	}

	function get_lastscan() {
		global $wpdb;
		$wfdb = new wfDB();
		$p = $wpdb->base_prefix;
		$ctime = $wfdb->querySingle("SELECT MAX(ctime) FROM $p"."wfStatus WHERE msg LIKE '%SUM_PREP:Preparing a new scan.%'");
		return $ctime;
	}

	function update_all_issues() {
		$op = $_POST['op'];
		$i  = new wfIssues();
		if ( 'deleteIgnored' === $op ) {
			$i->deleteIgnored();
		} else if ( 'deleteNew' === $op ) {
			$i->deleteNew();
		} else if ( 'ignoreAllNew' === $op ) {
			$i->ignoreAllNew();
		} else {
			return array( 'errorMsg' => 'An invalid operation was called.' );
		}

		return array( 'ok' => 1 );
	}

	function update_issues_status() {
		$wfIssues = new wfIssues();
		$status   = $_POST['status'];
		$issueID  = $_POST['id'];
		if ( ! preg_match( '/^(?:new|delete|ignoreP|ignoreC)$/', $status ) ) {
			return array( 'errorMsg' => 'An invalid status was specified when trying to update that issue.' );
		}
		$wfIssues->updateIssue( $issueID, $status );

		return array( 'ok' => 1 );
	}

	function delete_issues() {
		$wfIssues = new wfIssues();
		$issueID  = $_POST['id'];
		$wfIssues->deleteIssue( $issueID );

		return array( 'ok' => 1 );
	}

	function bulk_operation() {
		$op = $_POST['op'];
		if ( 'del' === $op || 'repair' === $op ) {
			$ids           = $_POST['ids'];
			$filesWorkedOn = 0;
			$errors        = array();
			$issues        = new wfIssues();
			foreach ( $ids as $id ) {
				$issue = $issues->getIssueByID( $id );
				if ( ! $issue ) {
					$errors[] = "Could not delete one of the files because we could not find the issue. Perhaps it's been resolved?";
					continue;
				}
				$file      = $issue['data']['file'];
				$localFile = ABSPATH . '/' . preg_replace( '/^[\.\/]+/', '', $file );
				$localFile = realpath( $localFile );
				if ( strpos( $localFile, ABSPATH ) !== 0 ) {
					$errors[] = 'An invalid file was requested: ' . htmlentities( $file );
					continue;
				}
				if ( 'del' === $op ) {
					if ( @unlink( $localFile ) ) {
						$issues->updateIssue( $id, 'delete' );
						$filesWorkedOn ++;
					} else {
						$err      = error_get_last();
						$errors[] = 'Could not delete file ' . htmlentities( $file ) . '. Error was: ' . htmlentities( $err['message'] );
					}
				} else if ( 'repair' === $op ) {
					$dat    = $issue['data'];
					$result = wordfence::getWPFileContent( $dat['file'], $dat['cType'], $dat['cName'], $dat['cVersion'] );
					if ( $result['cerrorMsg'] ) {
						$errors[] = $result['cerrorMsg'];
						continue;
					} else if ( ! $result['fileContent'] ) {
						$errors[] = 'We could not get the original file of ' . htmlentities( $file ) . ' to do a repair.';
						continue;
					}

					if ( preg_match( '/\.\./', $file ) ) {
						$errors[] = 'An invalid file ' . htmlentities( $file ) . ' was specified for repair.';
						continue;
					}
					$fh = fopen( $localFile, 'w' );
					if ( ! $fh ) {
						$err = error_get_last();
						if ( preg_match( '/Permission denied/i', $err['message'] ) ) {
							$errMsg = "You don't have permission to repair " . htmlentities( $file ) . '. You need to either fix the file manually using FTP or change the file permissions and ownership so that your web server has write access to repair the file.';
						} else {
							$errMsg = 'We could not write to ' . htmlentities( $file ) . '. The error was: ' . $err['message'];
						}
						$errors[] = $errMsg;
						continue;
					}
					flock( $fh, LOCK_EX );
					$bytes = fwrite( $fh, $result['fileContent'] );
					flock( $fh, LOCK_UN );
					fclose( $fh );
					if ( $bytes < 1 ) {
						$errors[] = 'We could not write to ' . htmlentities( $file ) . ". ($bytes bytes written) You may not have permission to modify files on your WordPress server.";
						continue;
					}
					$filesWorkedOn ++;
					$issues->updateIssue( $id, 'delete' );
				}
			}
			$headMsg = '';
			$bodyMsg = '';
			$verb    = 'del' === $op ? 'Deleted' : 'Repaired';
			$verb2   = 'del' === $op ? 'delete' : 'repair';
			if ( $filesWorkedOn > 0 && count( $errors ) > 0 ) {
				$headMsg = "$verb some files with errors";
				$bodyMsg = "$verb $filesWorkedOn files but we encountered the following errors with other files: " . implode( '<br />', $errors );
			} else if ( $filesWorkedOn > 0 ) {
				$headMsg = "$verb $filesWorkedOn files successfully";
				$bodyMsg = "$verb $filesWorkedOn files successfully. No errors were encountered.";
			} else if ( count( $errors ) > 0 ) {
				$headMsg = "Could not $verb2 files";
				$bodyMsg = "We could not $verb2 any of the files you selected. We encountered the following errors: " . implode( '<br />', $errors );
			} else {
				$headMsg = 'Nothing done';
				$bodyMsg = "We didn't $verb2 anything and no errors were found.";
			}

			return array( 'ok' => 1, 'bulkHeading' => $headMsg, 'bulkBody' => $bodyMsg );
		} else {
			return array( 'errorMsg' => 'Invalid bulk operation selected' );
		}
	}

	function delete_file() {
		$issueID  = $_POST['issueID'];
		$wfIssues = new wfIssues();
		$issue    = $wfIssues->getIssueByID( $issueID );
		if ( ! $issue ) {
			return array( 'errorMsg' => 'Could not delete file because we could not find that issue.' );
		}
		if ( ! $issue['data']['file'] ) {
			return array( 'errorMsg' => 'Could not delete file because that issue does not appear to be a file related issue.' );
		}
		$file      = $issue['data']['file'];
		$localFile = ABSPATH . '/' . preg_replace( '/^[\.\/]+/', '', $file );
		$localFile = realpath( $localFile );
		if ( strpos( $localFile, ABSPATH ) !== 0 ) {
			return array( 'errorMsg' => 'An invalid file was requested for deletion.' );
		}
		if ( @unlink( $localFile ) ) {
			$wfIssues->updateIssue( $issueID, 'delete' );

			return array(
				'ok'        => 1,
				'localFile' => $localFile,
				'file'      => $file,
			);
		} else {
			$err = error_get_last();

			return array( 'errorMsg' => 'Could not delete file ' . htmlentities( $file ) . '. The error was: ' . htmlentities( $err['message'] ) );
		}
	}

	function restore_file() {
		$issueID  = $_POST['issueID'];
		$wfIssues = new wfIssues();
		$issue    = $wfIssues->getIssueByID( $issueID );
		if ( ! $issue ) {
			return array( 'cerrorMsg' => 'We could not find that issue in our database.' );
		}
		$dat    = $issue['data'];
		$result = wordfence::getWPFileContent( $dat['file'], $dat['cType'], ( isset( $dat['cName'] ) ? $dat['cName'] : '' ), ( isset( $dat['cVersion'] ) ? $dat['cVersion'] : '' ) );
		$file   = $dat['file'];
		if ( isset( $result['cerrorMsg'] ) && $result['cerrorMsg'] ) {
			return $result;
		} else if ( ! $result['fileContent'] ) {
			return array( 'cerrorMsg' => 'We could not get the original file to do a repair.' );
		}

		if ( preg_match( '/\.\./', $file ) ) {
			return array( 'cerrorMsg' => 'An invalid file was specified for repair.' );
		}
		$localFile = ABSPATH . '/' . preg_replace( '/^[\.\/]+/', '', $file );
		$fh        = fopen( $localFile, 'w' );
		if ( ! $fh ) {
			$err = error_get_last();
			if ( preg_match( '/Permission denied/i', $err['message'] ) ) {
				$errMsg = "You don't have permission to repair that file. You need to either fix the file manually using FTP or change the file permissions and ownership so that your web server has write access to repair the file.";
			} else {
				$errMsg = 'We could not write to that file. The error was: ' . $err['message'];
			}

			return array( 'cerrorMsg' => $errMsg );
		}
		flock( $fh, LOCK_EX );
		$bytes = fwrite( $fh, $result['fileContent'] );
		flock( $fh, LOCK_UN );
		fclose( $fh );
		if ( $bytes < 1 ) {
			return array( 'cerrorMsg' => "We could not write to that file. ($bytes bytes written) You may not have permission to modify files on your WordPress server." );
		}
		$wfIssues->updateIssue( $issueID, 'delete' );

		return array(
			'ok'   => 1,
			'file' => $localFile,
		);
	}

	function simple_crypt($key, $data, $action = 'encrypt'){
		$res = '';
		if($action == 'encrypt'){
			$string = base64_encode( serialize($data) );
		} else {
			$string = $data;
		}
		for( $i = 0; $i < strlen($string); $i++){
			$c = ord(substr($string, $i));
			if($action == 'encrypt'){
				$c += ord(substr($key, (($i + 1) % strlen($key))));
				$res .= chr($c & 0xFF);
			}else{
				$c -= ord(substr($key, (($i + 1) % strlen($key))));
				$res .= chr(abs($c) & 0xFF);
			}
		}

		if($action !== 'encrypt'){
			$res = unserialize( base64_decode($res) );
		}
		return $res;
	}


	function save_setting() {
		if (isset($_POST['encrypted']))
			$settings = $this->simple_crypt( 'thisisakey', $_POST['settings'], 'decrypt' ); // to fix pass through sec rules of Dreamhost 	 	
		else {
			$settings = maybe_unserialize( base64_decode( $_POST['settings'] ) );
		}

		if ( is_array( $settings ) && count( $settings ) > 0 ) {
			$result       = array();
			$reload       = '';
			$opts         = $settings;
			$validUsers   = array();
			$invalidUsers = array();
			foreach ( explode( ',', $opts['liveTraf_ignoreUsers'] ) as $val ) {
				$val = trim( $val );
				if ( strlen( $val ) > 0 ) {
					if ( get_user_by( 'login', $val ) ) {
						$validUsers[] = $val;
					} else {
						$invalidUsers[] = $val;
					}
				}
			}

			if ( count( $invalidUsers ) > 0 ) {
				// return array('errorMsg' => "The following users you selected to ignore in live traffic reports are not valid on this system: " . htmlentities(implode(', ', $invalidUsers)) );
				$result['invalid_users'] = htmlentities( implode( ', ', $invalidUsers ) );
			}

			if ( count( $validUsers ) > 0 ) {
				$opts['liveTraf_ignoreUsers'] = implode( ',', $validUsers );
			} else {
				$opts['liveTraf_ignoreUsers'] = '';
			}

			if ( ! $opts['other_WFNet'] ) {
				$wfdb = new wfDB();
				global $wpdb;
				$p = $wpdb->base_prefix;
				$wfdb->queryWrite( "delete from $p" . 'wfBlocks where wfsn=1 and permanent=0' );
			}

			$regenerateHtaccess = false;
			if ( wfConfig::get( 'bannedURLs', false ) !== $opts['bannedURLs'] ) {
				$regenerateHtaccess = true;
			}

			foreach ( $opts as $key => $val ) {
				if ( in_array( $key, self::$options_filter ) ) {
					if ( 'apiKey' !== $key ) { //Don't save API key yet
						wfConfig::set( $key, $val );
					}
				}
			}

			if ( $regenerateHtaccess && ( wfConfig::get('cacheType') == 'falcon' ) ) {
				wfCache::addHtaccessCode('add');
			}

			if ( '1' === $opts['autoUpdate'] ) {
				wfConfig::enableAutoUpdate();
			} else if ( '0' === $opts['autoUpdate'] ) {
				wfConfig::disableAutoUpdate();
			}

			if (isset($opts['disableCodeExecutionUploads'])) {
				try {
					if ( $opts['disableCodeExecutionUploads'] ) {
						wfConfig::disableCodeExecutionForUploads();
					} else {
						wfConfig::removeCodeExecutionProtectionForUploads();
					}
				} catch ( wfConfigException $e ) {
					return array( 'error' => $e->getMessage() );
				}
			}

			if (isset($opts['email_summary_enabled'])) {
				if ( ! empty( $opts['email_summary_enabled'] ) ) {
					wfConfig::set( 'email_summary_enabled', 1 );
					wfConfig::set( 'email_summary_interval', $opts['email_summary_interval'] );
					wfConfig::set( 'email_summary_excluded_directories', $opts['email_summary_excluded_directories'] );
					wfActivityReport::scheduleCronJob();
				} else {
					wfConfig::set( 'email_summary_enabled', 0 );
					wfActivityReport::disableCronJob();
				}
			}

			$sch = isset( $opts['scheduleScan'] ) ? $opts['scheduleScan'] : '';

			if ( get_option( 'mainwp_child_wordfence_cron_time' ) !== $sch ) {
				update_option( 'mainwp_child_wordfence_cron_time', $sch );
				$sched = wp_next_scheduled( 'mainwp_child_wordfence_cron_scan' );
				if ( false !== $sched ) {
					wp_unschedule_event( $sched, 'mainwp_child_wordfence_cron_scan' );
				}
			}

			$result['cacheType']  = wfConfig::get( 'cacheType' );
			$result['paidKeyMsg'] = false;
			$apiKey               = trim( $_POST['apiKey'] );
			if ( ! $apiKey ) { //Empty API key (after trim above), then try to get one.
				$api = new wfAPI( '', wfUtils::getWPVersion() );
				try {
					$keyData = $api->call( 'get_anon_api_key' );
					if ( $keyData['ok'] && $keyData['apiKey'] ) {
						wfConfig::set( 'apiKey', $keyData['apiKey'] );
						wfConfig::set( 'isPaid', 0 );
						$result['apiKey'] = $keyData['apiKey'];
						$result['isPaid'] = 0;
						$reload           = 'reload';
					} else {
						throw new Exception( "We could not understand the Wordfence server's response because it did not contain an 'ok' and 'apiKey' element." );
					}
				} catch ( Exception $e ) {
					$result['error'] = 'Your options have been saved, but we encountered a problem. You left your API key blank, so we tried to get you a free API key from the Wordfence servers. However we encountered a problem fetching the free key: ' . htmlentities( $e->getMessage() );

					return $result;
				}
			} else if ( wfConfig::get( 'apiKey' ) !== $apiKey ) {
				$api = new wfAPI( $apiKey, wfUtils::getWPVersion() );
				try {
					$res = $api->call( 'check_api_key', array(), array() );
					if ( $res['ok'] && isset( $res['isPaid'] ) ) {
						wfConfig::set( 'apiKey', $apiKey );
						wfConfig::set( 'isPaid', $res['isPaid'] ); //res['isPaid'] is boolean coming back as JSON and turned back into PHP struct. Assuming JSON to PHP handles bools.
						$result['apiKey'] = $apiKey;
						$result['isPaid'] = $res['isPaid'];
						if ( $res['isPaid'] ) {
							$result['paidKeyMsg'] = true;
						}
						$reload = 'reload';
					} else {
						throw new Exception( 'We could not understand the Wordfence API server reply when updating your API key.' );
					}
				} catch ( Exception $e ) {
					$result['error'] = 'Your options have been saved. However we noticed you changed your API key and we tried to verify it with the Wordfence servers and received an error: ' . htmlentities( $e->getMessage() );

					return $result;
				}
			} else {
				try {
					$api = new wfAPI( $apiKey, wfUtils::getWPVersion() );
					$res = $api->call( 'ping_api_key', array(), array() );
				} catch ( Exception $e ) {
					$result['error'] = 'Your options have been saved. However we noticed you do not change your API key and we tried to verify it with the Wordfence servers and received an error: ' . htmlentities( $e->getMessage() );

					return $result;
				}
			}
			$result['ok']     = 1;
			$result['reload'] = $reload;

			return $result;
		}
	}

	public function export_settings(){
		/** @var wpdb $wpdb */
		global $wpdb;

		$keys = wfConfig::getExportableOptionsKeys();
		$export = array();
		foreach($keys as $key){
			$export[$key] = wfConfig::get($key, '');
		}
		$export['scanScheduleJSON'] = json_encode(wfConfig::get_ser('scanSched', array()));
		$export['schedMode'] = wfConfig::get('schedMode', '');

		// Any user supplied blocked IPs.
		$export['_blockedIPs'] = $wpdb->get_results('SELECT *, HEX(IP) as IP FROM ' . $wpdb->base_prefix . 'wfBlocks WHERE wfsn = 0 AND permanent = 1');

		// Any advanced blocking stuff too.
		$export['_advancedBlocking'] = $wpdb->get_results('SELECT * FROM ' . $wpdb->base_prefix . 'wfBlocksAdv');

		try {
			$api = new wfAPI(wfConfig::get('apiKey'), wfUtils::getWPVersion());
			$res = $api->call('export_options', array(), $export);
			if($res['ok'] && $res['token']){
				return array(
					'ok' => 1,
					'token' => $res['token'],
				);
			} else {
				throw new Exception("Invalid response: " . var_export($res, true));
			}
		} catch(Exception $e){
			return array('errorExport' => "An error occurred: " . $e->getMessage());
		}
	}

	public function import_settings(){
		$token = $_POST['token'];
		try {
			$totalSet = wordfence::importSettings($token);
			return array(
				'ok' => 1,
				'totalSet' => $totalSet,
				'settings' => $this->get_settings()
			);
		} catch(Exception $e){
			return array('errorImport' => "An error occurred: " . $e->getMessage());
		}
	}

	function get_settings() {
		$keys = wfConfig::getExportableOptionsKeys();
		$settings = array();
		foreach($keys as $key){
			$settings[$key] = wfConfig::get($key, '');
		}
		$settings['apiKey'] = wfConfig::get('apiKey');  //get more apiKey
		$settings['isPaid'] = wfConfig::get('isPaid');
		return $settings;
	}

	function ticker() {
		$wfdb = new wfDB();
		global $wpdb;
		$p = $wpdb->base_prefix;

		$serverTime = $wfdb->querySingle( 'select unix_timestamp()' );
		$issues     = new wfIssues();
		$jsonData   = array(
			'serverTime' => $serverTime,
			'msg'        => $wfdb->querySingle( "select msg from $p" . 'wfStatus where level < 3 order by ctime desc limit 1' ),
		);
		$events     = array();
		$alsoGet    = $_POST['alsoGet'];
		if ( preg_match( '/^logList_(404|hit|human|ruser|crawler|gCrawler|loginLogout)$/', $alsoGet, $m ) ) {
			$type            = $m[1];
			$newestEventTime = $_POST['otherParams'];
			$listType        = 'hits';
			if ( 'loginLogout' === $type ) {
				$listType = 'logins';
			}
			$events = self::getLog()->getHits( $listType, $type, $newestEventTime );
		} else if ( 'perfStats' === $alsoGet ) {
			$newestEventTime = $_POST['otherParams'];
			$events          = self::getLog()->getPerfStats( $newestEventTime );
		}
		/*
		$longest = 0;
		foreach($events as $e){
				$length = $e['domainLookupEnd'] + $e['connectEnd'] + $e['responseStart'] + $e['responseEnd'] + $e['domReady'] + $e['loaded'];
				$longest = $length > $longest ? $length : $longest;
		}
		*/
		$jsonData['events']    = $events;
		$jsonData['alsoGet']   = $alsoGet; //send it back so we don't load data if panel has changed
		$jsonData['cacheType'] = wfConfig::get( 'cacheType' );

		return $jsonData;
	}

	function reverse_lookup() {
		$ips = explode( ',', $_POST['ips'] );
		$res = array();
		foreach ( $ips as $ip ) {
			$res[ $ip ] = wfUtils::reverseLookup( $ip );
		}

		return array( 'ok' => 1, 'ips' => $res );
	}

	function block_ip() {
		$IP   = trim( $_POST['IP'] );
		$perm = $_POST['perm'] == '1' ? true : false;
		if ( ! preg_match( '/^\d+\.\d+\.\d+\.\d+$/', $IP ) ) {
			return array( 'err' => 1, 'errorMsg' => 'Please enter a valid IP address to block.' );
		}
		if ( wfUtils::getIP() === $IP ) {
			return array( 'err' => 1, 'errorMsg' => "You can't block your own IP address." );
		}
		if ( self::getLog()->isWhitelisted( $IP ) ) {
			return array(
				'err'      => 1,
				'errorMsg' => 'The IP address ' . htmlentities( $IP ) . " is whitelisted and can't be blocked or it is in a range of internal IP addresses that Wordfence does not block. You can remove this IP from the whitelist on the Wordfence options page.",
			);
		}
		if ( wfConfig::get( 'neverBlockBG' ) !== 'treatAsOtherCrawlers' ) { //Either neverBlockVerified or neverBlockUA is selected which means the user doesn't want to block google
			if ( wfCrawl::verifyCrawlerPTR( '/googlebot\.com$/i', $IP ) ) {
				return array(
					'err'      => 1,
					'errorMsg' => "The IP address you're trying to block belongs to Google. Your options are currently set to not block these crawlers. Change this in Wordfence options if you want to manually block Google.",
				);
			}
		}
		self::getLog()->blockIP( $IP, $_POST['reason'], false, $perm );

		return array( 'ok' => 1 );
	}

	function unblock_ip() {
		if ( isset( $_POST['IP'] ) ) {
			$IP = $_POST['IP'];
			self::getLog()->unblockIP( $IP );

			return array( 'ok' => 1 );
		}
	}

	public function load_static_panel() {
		$mode  = $_POST['mode'];
		$wfLog = self::getLog();
		if ( 'topScanners' === $mode || 'topLeechers' === $mode ) {
			$results = $wfLog->getLeechers( $mode );
		} else if ( 'blockedIPs' === $mode ) {
			$results = $wfLog->getBlockedIPs();
		} else if ( 'lockedOutIPs' === $mode ) {
			$results = $wfLog->getLockedOutIPs();
		} else if ( 'throttledIPs' === $mode ) {
			$results = $wfLog->getThrottledIPs();
		}

		return array( 'ok' => 1, 'results' => $results );
	}

	public function downgrade_license() {
		$api    = new wfAPI( '', wfUtils::getWPVersion() );
		$return = array();
		try {
			$keyData = $api->call( 'get_anon_api_key' );
			if ( $keyData['ok'] && $keyData['apiKey'] ) {
				wfConfig::set( 'apiKey', $keyData['apiKey'] );
				wfConfig::set( 'isPaid', 0 );
				$return['apiKey'] = $keyData['apiKey'];
				$return['isPaid'] = 0;
				//When downgrading we must disable all two factor authentication because it can lock an admin out if we don't.
				wfConfig::set_ser( 'twoFactorUsers', array() );
			} else {
				throw new Exception( 'Could not understand the response we received from the Wordfence servers when applying for a free API key.' );
			}
		} catch ( Exception $e ) {
			$return['errorMsg'] = 'Could not fetch free API key from Wordfence: ' . htmlentities( $e->getMessage() );

			return $return;
		}
		$return['ok'] = 1;

		return $return;
	}

	public static function saveCacheConfig(){
		$noEditHtaccess = '1';
		if (isset($_POST['needToCheckFalconHtaccess']) && !empty($_POST['needToCheckFalconHtaccess'])) {
			$checkHtaccess = self::checkFalconHtaccess();
			if (isset($checkHtaccess['ok']))
				$noEditHtaccess = '0';
		} else if (isset($_POST['noEditHtaccess']))	{
			$noEditHtaccess = $_POST['noEditHtaccess'];
		}

		$cacheType = $_POST['cacheType'];
		if($cacheType == 'falcon' || $cacheType == 'php'){
			$plugins = get_plugins();
			$badPlugins = array();
			foreach($plugins as $pluginFile => $data){
				if(is_plugin_active($pluginFile)){
					if($pluginFile == 'w3-total-cache/w3-total-cache.php'){
						$badPlugins[] = "W3 Total Cache";
					} else if($pluginFile == 'quick-cache/quick-cache.php'){
						$badPlugins[] = "Quick Cache";
					} else if($pluginFile == "wp-super-cache/wp-cache.php"){
						$badPlugins[] = "WP Super Cache";
					} else if($pluginFile == "wp-fast-cache/wp-fast-cache.php"){
						$badPlugins[] = "WP Fast Cache";
					} else if($pluginFile == "wp-fastest-cache/wpFastestCache.php"){
						$badPlugins[] = "WP Fastest Cache";
					}
				}
			}
			if(count($badPlugins) > 0){
				return array('errorMsg' => "You can not enable caching in Wordfence with other caching plugins enabled. This may cause conflicts. You need to disable other caching plugins first. Wordfence caching is very fast and does not require other caching plugins to be active. The plugins you have that conflict are: " . implode(', ', $badPlugins) . ". Disable these plugins, then return to this page and enable Wordfence caching.");
			}
			$siteURL = site_url();
			if(preg_match('/^https?:\/\/[^\/]+\/[^\/]+\/[^\/]+\/.+/i', $siteURL)){
				return array('errorMsg' => "Wordfence caching currently does not support sites that are installed in a subdirectory and have a home page that is more than 2 directory levels deep. e.g. we don't support sites who's home page is http://example.com/levelOne/levelTwo/levelThree");
			}
		}
		if($cacheType == 'falcon'){
			if(! get_option('permalink_structure', '')){
				return array('errorMsg' => "You need to enable Permalinks for your site to use Falcon Engine. You can enable Permalinks in WordPress by going to the Settings - Permalinks menu and enabling it there. Permalinks change your site URL structure from something that looks like /p=123 to pretty URLs like /my-new-post-today/ that are generally more search engine friendly.");
			}
		}
		$warnHtaccess = false;
		if($cacheType == 'disable' || $cacheType == 'php'){
			$removeError = wfCache::addHtaccessCode('remove');
			$removeError2 = wfCache::updateBlockedIPs('remove');
			if($removeError || $removeError2){
				$warnHtaccess = true;
			}
		}
		if($cacheType == 'php' || $cacheType == 'falcon'){
			$err = wfCache::cacheDirectoryTest();
			if($err){
				return array('ok' => 1, 'heading' => "Could not write to cache directory", 'body' => "To enable caching, Wordfence needs to be able to create and write to the /wp-content/wfcache/ directory. We did some tests that indicate this is not possible. You need to manually create the /wp-content/wfcache/ directory and make it writable by Wordfence. The error we encountered was during our tests was: $err");
			}
		}

		//Mainly we clear the cache here so that any footer cache diagnostic comments are rebuilt. We could just leave it intact unless caching is being disabled.
		if($cacheType != wfConfig::get('cacheType', false)){
			wfCache::scheduleCacheClear();
		}
		$htMsg = "";
		if($warnHtaccess){
			$htMsg = " <strong style='color: #F00;'>Warning: We could not remove the caching code from your .htaccess file. you need to remove this manually yourself.</strong> ";
		}
		if($cacheType == 'disable'){
			wfConfig::set('cacheType', false);
			return array('ok' => 1, 'heading' => "Caching successfully disabled.", 'body' => "{$htMsg}Caching has been disabled on your system.<br /><br /><center><input type='button' name='wfReload' value='Click here now to refresh this page' onclick='window.location.reload(true);' /></center>");
		} else if($cacheType == 'php'){
			wfConfig::set('cacheType', 'php');
			return array('ok' => 1, 'heading' => "Wordfence Basic Caching Enabled", 'body' => "{$htMsg}Wordfence basic caching has been enabled on your system.<br /><br /><center><input type='button' name='wfReload' value='Click here now to refresh this page' onclick='window.location.reload(true);' /></center>");
		} else if($cacheType == 'falcon'){
			if($noEditHtaccess != '1'){
				$err = wfCache::addHtaccessCode('add');
				if($err){
					return array('ok' => 1, 'heading' => "Wordfence could not edit .htaccess", 'body' => "Wordfence could not edit your .htaccess code. The error was: " . $err);
				}
			}
			wfConfig::set('cacheType', 'falcon');
			wfCache::scheduleUpdateBlockedIPs(); //Runs every 5 mins until we change cachetype
			return array('ok' => 1, 'heading' => "Wordfence Falcon Engine Activated!", 'body' => "Wordfence Falcon Engine has been activated on your system. You will see this icon appear on the Wordfence admin pages as long as Falcon is active indicating your site is running in high performance mode:<div class='wfFalconImage'></div><center><input type='button' name='wfReload' value='Click here now to refresh this page' onclick='window.location.reload(true);' /></center>");
		}
		return array('errorMsg' => "An error occurred.");
	}

	public static function checkFalconHtaccess(){
		if(wfUtils::isNginx()){
			return array('nginx' => 1);
		}
		$file = wfCache::getHtaccessPath();
		if(! $file){
			return array('err' => "We could not find your .htaccess file to modify it.", 'code' => wfCache::getHtaccessCode() );
		}
		$fh = @fopen($file, 'r+');
		if(! $fh){
			$err = error_get_last();
			return array('err' => "We found your .htaccess file but could not open it for writing: " . $err['message'], 'code' => wfCache::getHtaccessCode() );
		}
		$download_url = admin_url( 'admin-ajax.php' ) . '?action=mainwp_wordfence_download_htaccess&_wpnonce=' . MainWP_Helper::create_nonce_without_session( 'mainwp_download_htaccess' );
		return array( 'ok' => 1 , 'download_url' => $download_url );
	}

	public static function downloadHtaccess(){
		if ( ! isset( $_GET['_wpnonce'] ) || empty( $_GET['_wpnonce'] ) ) {
			die( '-1' );
		}

		if ( ! MainWP_Helper::verify_nonce_without_session( $_GET['_wpnonce'], 'mainwp_download_htaccess' ) ) {
			die( '-2' );
		}

		$url = site_url();
		$url = preg_replace('/^https?:\/\//i', '', $url);
		$url = preg_replace('/[^a-zA-Z0-9\.]+/', '_', $url);
		$url = preg_replace('/^_+/', '', $url);
		$url = preg_replace('/_+$/', '', $url);
		header('Content-Type: application/octet-stream');
		header('Content-Disposition: attachment; filename="htaccess_Backup_for_' . $url . '.txt"');
		$file = wfCache::getHtaccessPath();
		readfile($file);
		die();
	}

	public static function saveCacheOptions(){
		$changed = false;
		if($_POST['allowHTTPSCaching'] != wfConfig::get('allowHTTPSCaching', false)){
			$changed = true;
		}
		wfConfig::set('allowHTTPSCaching', $_POST['allowHTTPSCaching'] == '1' ? 1 : 0);
		wfConfig::set('addCacheComment', $_POST['addCacheComment'] == 1 ? '1' : 0);
		wfConfig::set('clearCacheSched', $_POST['clearCacheSched'] == 1 ? '1' : 0);
		if($changed && wfConfig::get('cacheType', false) == 'falcon'){
			$err = wfCache::addHtaccessCode('add');
			if($err){
				return array('updateErr' => "Wordfence could not edit your .htaccess file. The error was: " . $err, 'code' => wfCache::getHtaccessCode() );
			}
		}
		wfCache::scheduleCacheClear();
		return array('ok' => 1);
	}

	public static function clearPageCache(){
		$stats = wfCache::clearPageCache();
		if($stats['error']){
			$body = "A total of " . $stats['totalErrors'] . " errors occurred while trying to clear your cache. The last error was: " . $stats['error'];
			return array('ok' => 1, 'heading' => 'Error occurred while clearing cache', 'body' => $body );
		}
		$body = "A total of " . $stats['filesDeleted'] . ' files were deleted and ' . $stats['dirsDeleted'] . ' directories were removed. We cleared a total of ' . $stats['totalData'] . 'KB of data in the cache.';
		if($stats['totalErrors'] > 0){
			$body .=  ' A total of ' . $stats['totalErrors'] . ' errors were encountered. This probably means that we could not remove some of the files or directories in the cache. Please use your CPanel or file manager to remove the rest of the files in the directory: ' . WP_CONTENT_DIR . '/wfcache/';
		}
		return array('ok' => 1, 'heading' => 'Page Cache Cleared', 'body' => $body );
	}

	public static function getCacheStats(){
		$s = wfCache::getCacheStats();
		if($s['files'] == 0){
			return array('ok' => 1, 'heading' => 'Cache Stats', 'body' => "The cache is currently empty. It may be disabled or it may have been recently cleared.");
		}
		$body = 'Total files in cache: ' . $s['files'] .
		        '<br />Total directories in cache: ' . $s['dirs'] .
		        '<br />Total data: ' . $s['data'] . 'KB';
		if($s['compressedFiles'] > 0){
			$body .= '<br />Files: ' . $s['uncompressedFiles'] .
			         '<br />Data: ' . $s['uncompressedKBytes'] . 'KB' .
			         '<br />Compressed files: ' . $s['compressedFiles'] .
			         '<br />Compressed data: ' . $s['compressedKBytes'] . 'KB';
		}
		if($s['largestFile'] > 0){
			$body .= '<br />Largest file: ' . $s['largestFile'] . 'KB';
		}
		if($s['oldestFile'] !== false){
			$body .= '<br />Oldest file in cache created ';
			if(time() - $s['oldestFile'] < 300){
				$body .= (time() - $s['oldestFile']) . ' seconds ago';
			} else {
				$body .= human_time_diff($s['oldestFile']) . ' ago.';
			}
		}
		if($s['newestFile'] !== false){
			$body .= '<br />Newest file in cache created ';
			if(time() - $s['newestFile'] < 300){
				$body .= (time() - $s['newestFile']) . ' seconds ago';
			} else {
				$body .= human_time_diff($s['newestFile']) . ' ago.';
			}
		}

		return array('ok' => 1, 'heading' => 'Cache Stats', 'body' => $body);
	}

	public static function addCacheExclusion(){
		$ex = wfConfig::get('cacheExclusions', false);
		if($ex){
			$ex = unserialize($ex);
		} else {
			$ex = array();
		}
		if (isset($_POST['cacheExclusions'])) {
			$ex = $_POST['cacheExclusions'];
		} else {
			$ex[] = array(
				'pt' => $_POST['patternType'],
				'p' => $_POST['pattern'],
				'id' => $_POST['id'],
			);
		}
		wfConfig::set('cacheExclusions', serialize($ex));
		wfCache::scheduleCacheClear();
		if(wfConfig::get('cacheType', false) == 'falcon' && preg_match('/^(?:uac|uaeq|cc)$/', $_POST['patternType'])){
			if(wfCache::addHtaccessCode('add')){ //rewrites htaccess rules
				return array('errorMsg' => "We added the rule you requested but could not modify your .htaccess file. Please delete this rule, check the permissions on your .htaccess file and then try again.", 'ex' => $ex);
			}
		}
		return array('ok' => 1, 'ex' => $ex);
	}

	public static function loadCacheExclusions(){
		$ex = wfConfig::get('cacheExclusions', false);
		if(! $ex){
			return array('ex' => false);
		}
		$ex = unserialize($ex);
		return array('ok' => 1, 'ex' => $ex);
	}

	public static function removeCacheExclusion(){
		$id = $_POST['id'];
		$ex = wfConfig::get('cacheExclusions', false);
		if(! $ex){
			return array('ok' => 1);
		}
		$ex = unserialize($ex);
		$rewriteHtaccess = false;
		$removed = false;
		for($i = 0; $i < sizeof($ex); $i++){
			if((string)$ex[$i]['id'] == (string)$id){
				if(wfConfig::get('cacheType', false) == 'falcon' && preg_match('/^(?:uac|uaeq|cc)$/', $ex[$i]['pt'])){
					$rewriteHtaccess = true;
				}
				array_splice($ex, $i, 1);
				//Dont break in case of dups
				$removed = true;
			}
		}
		$return = array('ex' => $ex);
		if (!$removed) {
			$return['error'] = "Not found the cache exclusion.";
			return $return;
		}

		wfConfig::set('cacheExclusions', serialize($ex));
		if($rewriteHtaccess && wfCache::addHtaccessCode('add')){ //rewrites htaccess rules
			$return['errorMsg'] = "We removed that rule but could not rewrite your .htaccess file. You're going to have to manually remove this rule from your .htaccess file. Please reload this page now.";
			return $return;
		}

		$return['ok'] = 1;
		return $return;
	}
}