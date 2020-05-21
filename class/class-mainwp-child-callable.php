<?php

namespace MainWP\Child;

// phpcs:disable WordPress.WP.AlternativeFunctions --  to use external code, third party credit.

class MainWP_Child_Callable {

	protected static $instance = null;

	private $callableFunctions = array(
		'stats'                 => 'get_site_stats',
		'upgrade'               => 'upgrade_wp',
		'newpost'               => 'new_post',
		'deactivate'            => 'deactivate',
		'newuser'               => 'new_user',
		'newadminpassword'      => 'new_admin_password',
		'installplugintheme'    => 'install_plugin_theme',
		'upgradeplugintheme'    => 'upgrade_plugin_theme',
		'upgradetranslation'    => 'upgrade_translation',
		'backup'                => 'backup',
		'backup_checkpid'       => 'backup_checkpid',
		'cloneinfo'             => 'cloneinfo',
		'security'              => 'get_security_stats',
		'securityFix'           => 'do_security_fix',
		'securityUnFix'         => 'do_security_un_fix',
		'post_action'           => 'post_action',
		'get_all_posts'         => 'get_all_posts',
		'comment_action'        => 'comment_action',
		'comment_bulk_action'   => 'comment_bulk_action',
		'get_all_comments'      => 'get_all_comments',
		'get_all_themes'        => 'get_all_themes',
		'theme_action'          => 'theme_action',
		'get_all_plugins'       => 'get_all_plugins',
		'plugin_action'         => 'plugin_action',
		'get_all_pages'         => 'get_all_pages',
		'get_all_users'         => 'get_all_users',
		'user_action'           => 'user_action',
		'search_users'          => 'search_users',
		'insert_comment'        => 'insert_comment',
		'maintenance_site'      => 'maintenance_site',
		'keyword_links_action'  => 'keyword_links_action',
		'branding_child_plugin' => 'branding_child_plugin',
		'code_snippet'          => 'code_snippet',
		'uploader_action'       => 'uploader_action',
		'wordpress_seo'         => 'wordpress_seo',
		'client_report'         => 'client_report',
		'createBackupPoll'      => 'backup_poll',
		'page_speed'            => 'page_speed',
		'woo_com_status'        => 'woo_com_status',
		'links_checker'         => 'links_checker',
		'wordfence'             => 'wordfence',
		'delete_backup'         => 'delete_backup',
		'update_values'         => 'update_child_values',
		'ithemes'               => 'ithemes',
		'updraftplus'           => 'updraftplus',
		'backup_wp'             => 'backup_wp',
		'backwpup'              => 'backwpup',
		'wp_rocket'             => 'wp_rocket',
		'settings_tools'        => 'settings_tools',
		'skeleton_key'          => 'skeleton_key',
		'custom_post_type'      => 'custom_post_type',
		'backup_buddy'          => 'backup_buddy',
		'get_site_icon'         => 'get_site_icon',
		'vulner_checker'        => 'vulner_checker',
		'wp_staging'            => 'wp_staging',
		'disconnect'            => 'disconnect',
		'time_capsule'          => 'time_capsule',
		'extra_excution'        => 'extra_execution', // deprecated!
		'extra_execution'       => 'extra_execution',
		'wpvivid_backuprestore' => 'wpvivid_backuprestore',
	);

	private $callableFunctionsNoAuth = array(
		'stats' => 'get_site_stats_no_auth',
	);

	/**
	 * Method get_class_name()
	 *
	 * Get Class Name.
	 *
	 * @return object
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	public function __construct() {
	}

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init_call_functions( $auth = false ) {
		$callable         = false;
		$callable_no_auth = false;
		$call_func        = false;

		// check to execute mainwp child's callable functions.
		if ( isset( $_POST['function'] ) ) {
			$call_func        = $_POST['function'];
			$callable         = $this->is_callable_function( $call_func ); // check callable func.
			$callable_no_auth = $this->is_callable_function_no_auth( $call_func ); // check callable no auth func.
		}

		// Call the function required.
		if ( $auth && isset( $_POST['function'] ) && $callable ) {
			define( 'DOING_CRON', true );
			MainWP_Utility::handle_fatal_error();
			MainWP_Utility::fix_for_custom_themes();
			$this->call_function( $call_func );
		} elseif ( isset( $_POST['function'] ) && $callable_no_auth ) {
			define( 'DOING_CRON', true );
			MainWP_Utility::fix_for_custom_themes();
			$this->call_function_no_auth( $call_func );
		} elseif ( isset( $_POST['function'] ) && isset( $_POST['mainwpsignature'] ) && ! $callable && ! $callable_no_auth ) {
			MainWP_Helper::error( __( 'Required version has not been detected. Please, make sure that you are using the latest version of the MainWP Child plugin on your site.', 'mainwp-child' ) );
		}
	}

	public function is_callable_function( $func ) {
		if ( isset( $this->callableFunctions[ $func ] ) ) {
			return true;
		}
		return false;
	}

	public function is_callable_function_no_auth( $func ) {
		if ( isset( $this->callableFunctionsNoAuth[ $func ] ) ) {
			return true;
		}
		return false;
	}

	public function call_function( $func ) {
		if ( $this->is_callable_function( $func ) ) {
			call_user_func( array( $this, $this->callableFunctions[ $func ] ) );
		}
	}

	public function call_function_no_auth( $func ) {
		if ( $this->is_callable_function_no_auth( $func ) ) {
			call_user_func( array( $this, $this->callableFunctionsNoAuth[ $func ] ) );
		}
	}

	public function get_site_stats() {
		MainWP_Child_Stats::get_instance()->get_site_stats();
	}

	public function get_site_stats_no_auth() {
		MainWP_Child_Stats::get_instance()->get_site_stats_no_auth();
	}

	/**
	 * Functions to support core functionality
	 */
	public function install_plugin_theme() {
		MainWP_Child_Install::get_instance()->install_plugin_theme();
	}

	public function upgrade_wp() {
		MainWP_Child_Updates::get_instance()->upgrade_wp();
	}

	public function upgrade_translation() {
		MainWP_Child_Updates::get_instance()->upgrade_translation();
	}

	public function upgrade_plugin_theme() {
		MainWP_Child_Updates::get_instance()->upgrade_plugin_theme();
	}


	public function insert_comment() {
		$postId   = $_POST['id'];
		$comments = maybe_unserialize( base64_decode( $_POST['comments'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for begin reasons.
		$ids      = array();
		foreach ( $comments as $comment ) {
			$ids[] = wp_insert_comment(
				array(
					'comment_post_ID' => $postId,
					'comment_author'  => $comment['author'],
					'comment_content' => $comment['content'],
					'comment_date'    => $comment['date'],
				)
			);
		}
		MainWP_Helper::write( $ids );
	}

	public function theme_action() {
		MainWP_Child_Install::get_instance()->theme_action();
	}

	public function plugin_action() {
		MainWP_Child_Install::get_instance()->plugin_action();
	}

	public function get_all_plugins() {
		MainWP_Child_Stats::get_instance()->get_all_plugins();
	}

	public function get_all_themes() {
		MainWP_Child_Stats::get_instance()->get_all_themes();
	}

	public function get_all_users() {
		MainWP_Child_Users::get_instance()->get_all_users();
	}

	public function user_action() {
		MainWP_Child_Users::get_instance()->user_action();
	}

	public function search_users() {
		MainWP_Child_Users::get_instance()->search_users();
	}

	public function get_all_posts() {
		MainWP_Child_Posts::get_instance()->get_all_posts();
	}

	public function get_all_pages() {
		MainWP_Child_Posts::get_instance()->get_all_pages();
	}

	public function comment_action() {
		MainWP_Child_Posts::get_instance()->comment_action();
	}

	public function get_all_comments() {
		MainWP_Child_Posts::get_instance()->get_all_comments();
	}

	public function comment_bulk_action() {
		MainWP_Child_Posts::get_instance()->comment_bulk_action();
	}

	public function maintenance_site() {

		if ( isset( $_POST['action'] ) ) {
			$this->maintenance_action( $_POST['action'] ); // exit.
		}

		$maint_options = $_POST['options'];
		if ( ! is_array( $maint_options ) ) {
			MainWP_Helper::write( array( 'status' => 'FAIL' ) ); // exit.
		}

		$max_revisions = isset( $_POST['revisions'] ) ? intval( $_POST['revisions'] ) : 0;
		$information   = $this->maintenance_db( $maint_options, $max_revisions );
		MainWP_Helper::write( $information );
	}

	private function maintenance_db( $maint_options, $max_revisions ) {
		global $wpdb;

		$performed_what = array();

		if ( in_array( 'revisions', $maint_options ) ) {
			if ( empty( $max_revisions ) ) {
				$sql_clean = "DELETE FROM $wpdb->posts WHERE post_type = 'revision'";
				$wpdb->query( $sql_clean ); // phpcs:ignore -- safe sql.
				// to fix issue of meta_value short length.
				$performed_what[] = 'revisions'; // 'Posts revisions deleted'.
			} else {
				$results = $this->maintenance_get_revisions( $max_revisions );
				$this->maintenance_delete_revisions( $results, $max_revisions );
				$performed_what[] = 'revisions_max'; // 'Posts revisions deleted'.
			}
		}

		$maint_sqls = array(
			'autodraft'    => "DELETE FROM $wpdb->posts WHERE post_status = 'auto-draft'",
			'trashpost'    => "DELETE FROM $wpdb->posts WHERE post_status = 'trash'",
			'spam'         => "DELETE FROM $wpdb->comments WHERE comment_approved = 'spam'",
			'pending'      => "DELETE FROM $wpdb->comments WHERE comment_approved = '0'",
			'trashcomment' => "DELETE FROM $wpdb->comments WHERE comment_approved = 'trash'",
		);

		foreach ( $maint_sqls as $act => $sql_clean ) {
			if ( in_array( $act, $maint_options ) ) {
				$wpdb->query( $sql_clean ); // phpcs:ignore -- safe sql.
				$performed_what[] = $act; // 'Auto draft posts deleted'.
			}
		}

		if ( in_array( 'tags', $maint_options ) ) {
			$post_tags = get_terms( 'post_tag', array( 'hide_empty' => false ) );
			if ( is_array( $post_tags ) ) {
				foreach ( $post_tags as $tag ) {
					if ( 0 === $tag->count ) {
						wp_delete_term( $tag->term_id, 'post_tag' );
					}
				}
			}
			$performed_what[] = 'tags'; // 'Tags with 0 posts associated deleted'.
		}

		if ( in_array( 'categories', $maint_options ) ) {
			$post_cats = get_terms( 'category', array( 'hide_empty' => false ) );
			if ( is_array( $post_cats ) ) {
				foreach ( $post_cats as $cat ) {
					if ( 0 === $cat->count ) {
						wp_delete_term( $cat->term_id, 'category' );
					}
				}
			}
			$performed_what[] = 'categories'; // 'Categories with 0 posts associated deleted'.
		}

		if ( in_array( 'optimize', $maint_options ) ) {
			$this->maintenance_optimize();
			$performed_what[] = 'optimize'; // 'Database optimized'.
		}

		if ( ! empty( $performed_what ) && has_action( 'mainwp_reports_maintenance' ) ) {
			$details  = implode( ',', $performed_what );
			$log_time = time();
			$message  = 'Maintenance Performed';
			$result   = 'Maintenance Performed';
			do_action( 'mainwp_reports_maintenance', $message, $log_time, $details, $result, $max_revisions );
		}
		return array( 'status' => 'SUCCESS' );
	}

	protected function maintenance_get_revisions( $max_revisions ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( " SELECT	`post_parent`, COUNT(*) cnt FROM $wpdb->posts WHERE `post_type` = 'revision' GROUP BY `post_parent` HAVING COUNT(*) > %d ", $max_revisions ) );
	}

	private function maintenance_delete_revisions( $results, $max_revisions ) {
		global $wpdb;

		if ( ! is_array( $results ) || 0 === count( $results ) ) {
			return;
		}
		$count_deleted  = 0;
		$results_length = count( $results );
		for ( $i = 0; $i < $results_length; $i ++ ) {
			$number_to_delete = $results[ $i ]->cnt - $max_revisions;
			$count_deleted   += $number_to_delete;
			$results_posts    = $wpdb->get_results( $wpdb->prepare( "SELECT `ID`, `post_modified` FROM  $wpdb->posts WHERE `post_parent`= %d AND `post_type`='revision' ORDER BY `post_modified` ASC", $results[ $i ]->post_parent ) );
			$delete_ids       = array();
			if ( is_array( $results_posts ) && count( $results_posts ) > 0 ) {
				for ( $j = 0; $j < $number_to_delete; $j ++ ) {
					$delete_ids[] = $results_posts[ $j ]->ID;
				}
			}

			if ( count( $delete_ids ) > 0 ) {
				$sql_delete = " DELETE FROM $wpdb->posts WHERE `ID` IN (" . implode( ',', $delete_ids ) . ")"; // phpcs:ignore -- safe
				$wpdb->get_results( $sql_delete ); // phpcs:ignore -- safe
			}
		}

		return $count_deleted;
	}

	private function maintenance_optimize() {
		global $wpdb, $table_prefix;
		$sql    = 'SHOW TABLE STATUS FROM `' . DB_NAME . '`';
		$result = MainWP_Child_DB::to_query( $sql, $wpdb->dbh );
		if ( MainWP_Child_DB::num_rows( $result ) && MainWP_Child_DB::is_result( $result ) ) {
			while ( $row = MainWP_Child_DB::fetch_array( $result ) ) {
				if ( strpos( $row['Name'], $table_prefix ) !== false ) {
					$sql = 'OPTIMIZE TABLE ' . $row['Name'];
					MainWP_Child_DB::to_query( $sql, $wpdb->dbh );
				}
			}
		}
	}

	private function maintenance_action( $action ) {
		$information = array();
		if ( 'save_settings' === $action ) {
			if ( isset( $_POST['enable_alert'] ) && '1' === $_POST['enable_alert'] ) {
				MainWP_Helper::update_option( 'mainwp_maintenance_opt_alert_404', 1, 'yes' );
			} else {
				delete_option( 'mainwp_maintenance_opt_alert_404' );
			}

			if ( isset( $_POST['email'] ) && ! empty( $_POST['email'] ) ) {
				MainWP_Helper::update_option( 'mainwp_maintenance_opt_alert_404_email', $_POST['email'], 'yes' );
			} else {
				delete_option( 'mainwp_maintenance_opt_alert_404_email' );
			}
			$information['result'] = 'SUCCESS';
			MainWP_Helper::write( $information );

			return;
		} elseif ( 'clear_settings' === $action ) {
			delete_option( 'mainwp_maintenance_opt_alert_404' );
			delete_option( 'mainwp_maintenance_opt_alert_404_email' );
			$information['result'] = 'SUCCESS';
			MainWP_Helper::write( $information );
		}

		MainWP_Helper::write( $information );
	}

	public function new_post() {
		MainWP_Child_Posts::get_instance()->new_post();
	}

	public function post_action() {
		MainWP_Child_Posts::get_instance()->post_action();
	}

	public function new_admin_password() {
		MainWP_Child_Users::get_instance()->new_admin_password();
	}

	public function new_user() {
		MainWP_Child_Users::get_instance()->new_user();
	}

	public function cloneinfo() {
		global $table_prefix;
		$information['dbCharset']    = DB_CHARSET;
		$information['dbCollate']    = DB_COLLATE;
		$information['table_prefix'] = $table_prefix;
		$information['site_url']     = get_option( 'site_url' );
		$information['home']         = get_option( 'home' );

		MainWP_Helper::write( $information );
	}

	public function backup_poll() {
		MainWP_Backup::get()->backup_poll();
	}

	public function backup_checkpid() {
		MainWP_Backup::get()->backup_checkpid();
	}

	public function backup( $pWrite = true ) {
		return MainWP_Backup::get()->backup( $pWrite );
	}

	protected function backup_full( $fileName ) {
		return MainWP_Backup::get()->backup_full( $fileName );
	}

	protected function backup_db( $fileName = '', $ext = 'zip' ) {
		return MainWP_Backup::get()->backup_db( $fileName, $ext );
	}

	public function get_site_icon() {
		$information = array();
		$url         = $this->get_favicon( true );
		if ( ! empty( $url ) ) {
			$information['faviIconUrl'] = $url;
		}
		MainWP_Helper::write( $information );
	}

	public function get_favicon( $parse_page = false ) {

		$favi_url = '';
		$favi     = '';
		$site_url = get_option( 'siteurl' );
		if ( substr( $site_url, - 1 ) != '/' ) {
			$site_url .= '/';
		}

		if ( function_exists( 'get_site_icon_url' ) && has_site_icon() ) {
			$favi     = get_site_icon_url();
			$favi_url = $favi;
		}

		if ( empty( $favi ) ) {
			if ( file_exists( ABSPATH . 'favicon.ico' ) ) {
				$favi = 'favicon.ico';
			} elseif ( file_exists( ABSPATH . 'favicon.png' ) ) {
				$favi = 'favicon.png';
			}

			if ( ! empty( $favi ) ) {
				$favi_url = $site_url . $favi;
			}
		}

		if ( $parse_page ) {
			// try to parse page.
			if ( empty( $favi_url ) ) {
				$favi_url = $this->get_favicon_try_to_find( $site_url );
			}

			if ( ! empty( $favi_url ) ) {
				return $favi_url;
			} else {
				return false;
			}
		} else {
			return $favi_url;
		}
	}

	private function get_favicon_try_to_find( $site_url ) {
		$request = wp_remote_get( $site_url, array( 'timeout' => 50 ) );
		$favi    = '';
		if ( is_array( $request ) && isset( $request['body'] ) ) {
			$preg_str1 = '/(<link\s+(?:[^\>]*)(?:rel="shortcut\s+icon"\s*)(?:[^>]*)?href="([^"]+)"(?:[^>]*)?>)/is';
			$preg_str2 = '/(<link\s+(?:[^\>]*)(?:rel="(?:shortcut\s+)?icon"\s*)(?:[^>]*)?href="([^"]+)"(?:[^>]*)?>)/is';

			if ( preg_match( $preg_str1, $request['body'], $matches ) ) {
				$favi = $matches[2];
			} elseif ( preg_match( $preg_str2, $request['body'], $matches ) ) {
				$favi = $matches[2];
			}
		}
		$favi_url = '';
		if ( ! empty( $favi ) ) {
			if ( false === strpos( $favi, 'http' ) ) {
				if ( 0 === strpos( $favi, '//' ) ) {
					if ( 0 === strpos( $site_url, 'https' ) ) {
						$favi_url = 'https:' . $favi;
					} else {
						$favi_url = 'http:' . $favi;
					}
				} else {
					$favi_url = $site_url . $favi;
				}
			} else {
				$favi_url = $favi;
			}
		}
		return $favi_url;
	}

	public function get_security_stats() {
		$information = array();

		$information['listing']             = ( ! MainWP_Security::prevent_listing_ok() ? 'N' : 'Y' );
		$information['wp_version']          = ( ! MainWP_Security::remove_wp_version_ok() ? 'N' : 'Y' );
		$information['rsd']                 = ( ! MainWP_Security::remove_rsd_ok() ? 'N' : 'Y' );
		$information['wlw']                 = ( ! MainWP_Security::remove_wlw_ok() ? 'N' : 'Y' );
		$information['db_reporting']        = ( ! MainWP_Security::remove_database_reporting_ok() ? 'N' : 'Y' );
		$information['php_reporting']       = ( ! MainWP_Security::remove_php_reporting_ok() ? 'N' : 'Y' );
		$information['versions']            = ( ! MainWP_Security::remove_scripts_version_ok() || ! MainWP_Security::remove_styles_version_ok() || ! MainWP_Security::remove_generator_version_ok() ? 'N' : 'Y' );
		$information['registered_versions'] = ( MainWP_Security::remove_registered_versions_ok() ? 'Y' : 'N' );
		$information['admin']               = ( MainWP_Security::admin_user_ok() ? 'Y' : 'N' );
		$information['readme']              = ( MainWP_Security::remove_readme_ok() ? 'Y' : 'N' );

		MainWP_Helper::write( $information );
	}


	public function do_security_fix() {
		$sync = false;
		if ( 'all' === $_POST['feature'] ) {
			$sync = true;
		}

		$information = array();
		$security    = get_option( 'mainwp_security' );
		if ( ! is_array( $security ) ) {
			$security = array();
		}

		if ( 'all' === $_POST['feature'] || 'listing' === $_POST['feature'] ) {
			MainWP_Security::prevent_listing();
			$information['listing'] = ( ! MainWP_Security::prevent_listing_ok() ? 'N' : 'Y' );
		}

		if ( 'all' === $_POST['feature'] || 'wp_version' === $_POST['feature'] ) {
			$security['wp_version'] = true;
			MainWP_Security::remove_wp_version( true );
			$information['wp_version'] = ( ! MainWP_Security::remove_wp_version_ok() ? 'N' : 'Y' );
		}

		if ( 'all' === $_POST['feature'] || 'rsd' === $_POST['feature'] ) {
			$security['rsd'] = true;
			MainWP_Security::remove_rsd( true );
			$information['rsd'] = ( ! MainWP_Security::remove_rsd_ok() ? 'N' : 'Y' );
		}

		if ( 'all' === $_POST['feature'] || 'wlw' === $_POST['feature'] ) {
			$security['wlw'] = true;
			MainWP_Security::remove_wlw( true );
			$information['wlw'] = ( ! MainWP_Security::remove_wlw_ok() ? 'N' : 'Y' );
		}

		if ( 'all' === $_POST['feature'] || 'db_reporting' === $_POST['feature'] ) {
			MainWP_Security::remove_database_reporting();
			$information['db_reporting'] = ( ! MainWP_Security::remove_database_reporting_ok() ? 'N' : 'Y' );
		}

		if ( 'all' === $_POST['feature'] || 'php_reporting' === $_POST['feature'] ) {
			$security['php_reporting'] = true;
			MainWP_Security::remove_php_reporting( true );
			$information['php_reporting'] = ( ! MainWP_Security::remove_php_reporting_ok() ? 'N' : 'Y' );
		}

		if ( 'all' === $_POST['feature'] || 'versions' === $_POST['feature'] ) {
			$security['scripts_version']   = true;
			$security['styles_version']    = true;
			$security['generator_version'] = true;
			MainWP_Security::remove_generator_version( true );
			$information['versions'] = 'Y';
		}

		if ( 'all' === $_POST['feature'] || 'registered_versions' === $_POST['feature'] ) {
			$security['registered_versions']    = true;
			$information['registered_versions'] = 'Y';
		}

		if ( 'all' === $_POST['feature'] || 'admin' === $_POST['feature'] ) {
			$information['admin'] = ( ! MainWP_Security::admin_user_ok() ? 'N' : 'Y' );
		}

		if ( 'all' === $_POST['feature'] || 'readme' === $_POST['feature'] ) {
			$security['readme'] = true;
			MainWP_Security::remove_readme( true );
			$information['readme'] = ( MainWP_Security::remove_readme_ok() ? 'Y' : 'N' );
		}

		MainWP_Helper::update_option( 'mainwp_security', $security, 'yes' );

		if ( $sync ) {
			$information['sync'] = MainWP_Child_Stats::get_instance()->get_site_stats( array(), false );
		}
		MainWP_Helper::write( $information );
	}

	public function do_security_un_fix() {
		$information = array();

		$sync = false;
		if ( 'all' === $_POST['feature'] ) {
			$sync = true;
		}

		$security = get_option( 'mainwp_security' );

		if ( 'all' === $_POST['feature'] || 'wp_version' === $_POST['feature'] ) {
			$security['wp_version']    = false;
			$information['wp_version'] = 'N';
		}

		if ( 'all' === $_POST['feature'] || 'rsd' === $_POST['feature'] ) {
			$security['rsd']    = false;
			$information['rsd'] = 'N';
		}

		if ( 'all' === $_POST['feature'] || 'wlw' === $_POST['feature'] ) {
			$security['wlw']    = false;
			$information['wlw'] = 'N';
		}

		if ( 'all' === $_POST['feature'] || 'php_reporting' === $_POST['feature'] ) {
			$security['php_reporting']    = false;
			$information['php_reporting'] = 'N';
		}

		if ( 'all' === $_POST['feature'] || 'versions' === $_POST['feature'] ) {
			$security['scripts_version']   = false;
			$security['styles_version']    = false;
			$security['generator_version'] = false;
			$information['versions']       = 'N';
		}

		if ( 'all' === $_POST['feature'] || 'registered_versions' === $_POST['feature'] ) {
			$security['registered_versions']    = false;
			$information['registered_versions'] = 'N';
		}
		if ( 'all' === $_POST['feature'] || 'readme' === $_POST['feature'] ) {
			$security['readme']    = false;
			$information['readme'] = MainWP_Security::remove_readme_ok();
		}

		MainWP_Helper::update_option( 'mainwp_security', $security, 'yes' );

		if ( $sync ) {
			$information['sync'] = MainWP_Child_Stats::get_instance()->get_site_stats( array(), false );
		}

		MainWP_Helper::write( $information );
	}

	public function settings_tools() {
		if ( isset( $_POST['action'] ) ) {
			switch ( $_POST['action'] ) {
				case 'force_destroy_sessions':
					if ( 0 === get_current_user_id() ) {
						MainWP_Helper::write( array( 'error' => __( 'Cannot get user_id', 'mainwp-child' ) ) );
					}

					wp_destroy_all_sessions();

					$sessions = wp_get_all_sessions();

					if ( empty( $sessions ) ) {
						MainWP_Helper::write( array( 'success' => 1 ) );
					} else {
						MainWP_Helper::write( array( 'error' => __( 'Cannot destroy sessions', 'mainwp-child' ) ) );
					}
					break;

				default:
					MainWP_Helper::write( array( 'error' => __( 'Invalid action', 'mainwp-child' ) ) );
			}
		} else {
			MainWP_Helper::write( array( 'error' => __( 'Missing action', 'mainwp-child' ) ) );
		}
	}

	public function skeleton_key() {
		MainWP_Child_Skeleton_Key::instance()->action();
	}

	public function custom_post_type() {
		MainWP_Custom_Post_Type::instance()->action();
	}

	public function backup_buddy() {
		\MainWP_Child_Back_Up_Buddy::instance()->action();
	}

	public function vulner_checker() {
		MainWP_Child_Vulnerability_Checker::instance()->action();
	}

	public function time_capsule() {
		\MainWP_Child_Timecapsule::instance()->action();
	}

	public function wp_staging() {
		\MainWP_Child_Staging::instance()->action();
	}

	public function extra_execution() {
		$post        = $_POST;
		$information = array();
		$information = apply_filters( 'mainwp_child_extra_execution', $information, $post );
		MainWP_Helper::write( $information );
	}


	public function uploader_action() {
		$file_url    = base64_decode( $_POST['url'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for begin reasons.
		$path        = $_POST['path'];
		$filename    = $_POST['filename'];
		$information = array();

		if ( empty( $file_url ) || empty( $path ) ) {
			MainWP_Helper::write( $information );

			return;
		}

		if ( strpos( $path, 'wp-content' ) === 0 ) {
			$path = basename( WP_CONTENT_DIR ) . substr( $path, 10 );
		} elseif ( strpos( $path, 'wp-includes' ) === 0 ) {
			$path = WPINC . substr( $path, 11 );
		}

		if ( '/' === $path ) {
			$dir = ABSPATH;
		} else {
			$path = str_replace( ' ', '-', $path );
			$path = str_replace( '.', '-', $path );
			$dir  = ABSPATH . $path;
		}

		if ( ! file_exists( $dir ) ) {
			if ( false === mkdir( $dir, 0777, true ) ) {
				$information['error'] = 'ERRORCREATEDIR';
				MainWP_Helper::write( $information );

				return;
			}
		}

		try {
			$upload = $this->uploader_upload_file( $file_url, $dir, $filename );
			if ( null !== $upload ) {
				$information['success'] = true;
			}
		} catch ( \Exception $e ) {
			$information['error'] = $e->getMessage();
		}
		MainWP_Helper::write( $information );
	}


	public function uploader_upload_file( $file_url, $path, $file_name ) {
		// to fix uploader extension rename htaccess file issue.
		if ( '.htaccess' != $file_name && '.htpasswd' != $file_name ) {
			$file_name = sanitize_file_name( $file_name );
		}

		$full_file_name = $path . DIRECTORY_SEPARATOR . $file_name;

		$response = wp_remote_get(
			$file_url,
			array(
				'timeout'  => 10 * 60 * 60,
				'stream'   => true,
				'filename' => $full_file_name,
			)
		);

		if ( is_wp_error( $response ) ) {
			unlink( $full_file_name );
			throw new \Exception( 'Error: ' . $response->get_error_message() );
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			unlink( $full_file_name );
			throw new \Exception( 'Error 404: ' . trim( wp_remote_retrieve_response_message( $response ) ) );
		}
		if ( '.phpfile.txt' === substr( $file_name, - 12 ) ) {
			$new_file_name = substr( $file_name, 0, - 12 ) . '.php';
			$new_file_name = $path . DIRECTORY_SEPARATOR . $new_file_name;
			$moved         = rename( $full_file_name, $new_file_name );
			if ( $moved ) {
				return array( 'path' => $new_file_name );
			} else {
				unlink( $full_file_name );
				throw new \Exception( 'Error: Copy file.' );
			}
		}

		return array( 'path' => $full_file_name );
	}

	public function wordpress_seo() {
		\MainWP_WordPress_SEO::instance()->action();
	}

	public function client_report() {
		MainWP_Client_Report::instance()->action();
	}

	public function page_speed() {
		\MainWP_Child_Pagespeed::instance()->action();
	}

	public function woo_com_status() {
		\MainWP_Child_WooCommerce_Status::instance()->action();
	}

	public function links_checker() {
		\MainWP_Child_Links_Checker::instance()->action();
	}

	public function wordfence() {
		\MainWP_Child_Wordfence::instance()->action();
	}

	public function ithemes() {
		\MainWP_Child_IThemes_Security::instance()->action();
	}


	public function updraftplus() {
		\MainWP_Child_Updraft_Plus_Backups::instance()->action();
	}

	public function wpvivid_backuprestore() {
		\MainWP_Child_WPvivid_BackupRestore::instance()->action();
	}

	public function backup_wp() {
		if ( ! version_compare( phpversion(), '5.3', '>=' ) ) {
			$error = sprintf( __( 'PHP Version %s is unsupported.', 'mainwp-child' ), phpversion() );
			MainWP_Helper::write( array( 'error' => $error ) );
		}
		\MainWP_Child_Back_Up_WordPress::instance()->action();
	}

	public function wp_rocket() {
		\MainWP_Child_WP_Rocket::instance()->action();
	}

	public function backwpup() {
		\MainWP_Child_Back_WP_Up::instance()->action();
	}


	public function delete_backup() {
		$dirs      = MainWP_Helper::get_mainwp_dir( 'backup' );
		$backupdir = $dirs[0];

		$file = $_REQUEST['del'];

		if ( file_exists( $backupdir . $file ) ) {
			unlink( $backupdir . $file );
		}

		MainWP_Helper::write( array( 'result' => 'ok' ) );
	}


	public function update_child_values() {
		$uniId = isset( $_POST['uniqueId'] ) ? $_POST['uniqueId'] : '';
		MainWP_Helper::update_option( 'mainwp_child_uniqueId', $uniId );
		MainWP_Helper::write( array( 'result' => 'ok' ) );
	}



	public function keyword_links_action() {
		MainWP_Keyword_Links::instance()->action();
	}

	public function branding_child_plugin() {
		MainWP_Child_Branding::instance()->action();
	}

	public function code_snippet() {

		$action = $_POST['action'];
		$type   = isset( $_POST['type'] ) ? $_POST['type'] : '';
		$slug   = isset( $_POST['slug'] ) ? $_POST['slug'] : '';

		$snippets = get_option( 'mainwp_ext_code_snippets' );

		if ( ! is_array( $snippets ) ) {
			$snippets = array();
		}

		if ( 'run_snippet' === $action || 'save_snippet' === $action ) {
			if ( ! isset( $_POST['code'] ) ) {
				MainWP_Helper::write( array( 'status' => 'FAIL' ) );
			}
		}

		$code = isset( $_POST['code'] ) ? stripslashes( $_POST['code'] ) : '';

		$information = array();
		if ( 'run_snippet' === $action ) {
			$information = MainWP_Utility::execute_snippet( $code );
		} elseif ( 'save_snippet' === $action ) {
			$information = $this->snippet_save_snippet( $slug, $type, $code, $snippets );
		} elseif ( 'delete_snippet' === $action ) {
			$information = $this->snippet_delete_snippet( $slug, $type, $snippets );
		}

		if ( empty( $information ) ) {
			$information = array( 'status' => 'FAIL' );
		}

		MainWP_Helper::write( $information );
	}

	private function snippet_save_snippet( $slug, $type, $code, $snippets ) {
		$return = array();
		if ( 'C' === $type ) { // save into wp-config file.
			if ( false !== $this->snippet_update_wp_config( 'save', $slug, $code ) ) {
				$return['status'] = 'SUCCESS';
			}
		} else {
			$snippets[ $slug ] = $code;
			if ( MainWP_Helper::update_option( 'mainwp_ext_code_snippets', $snippets ) ) {
				$return['status'] = 'SUCCESS';
			}
		}
		MainWP_Helper::update_option( 'mainwp_ext_snippets_enabled', true, 'yes' );
		return $return;
	}

	private function snippet_delete_snippet( $slug, $type, $snippets ) {
		$return = array();
		if ( 'C' === $type ) { // delete in wp-config file.
			if ( false !== $this->snippet_update_wp_config( 'delete', $slug ) ) {
				$return['status'] = 'SUCCESS';
			}
		} else {
			if ( isset( $snippets[ $slug ] ) ) {
				unset( $snippets[ $slug ] );
				if ( MainWP_Helper::update_option( 'mainwp_ext_code_snippets', $snippets ) ) {
					$return['status'] = 'SUCCESS';
				}
			} else {
				$return['status'] = 'SUCCESS';
			}
		}
		return $return;
	}

	public function snippet_update_wp_config( $action, $slug, $code = '' ) {

		$config_file = '';
		if ( file_exists( ABSPATH . 'wp-config.php' ) ) {
			// The config file resides in ABSPATH.
			$config_file = ABSPATH . 'wp-config.php';
		} elseif ( file_exists( dirname( ABSPATH ) . '/wp-config.php' ) && ! file_exists( dirname( ABSPATH ) . '/wp-settings.php' ) ) {
			// The config file resides one level above ABSPATH but is not part of another install.
			$config_file = dirname( ABSPATH ) . '/wp-config.php';
		}

		if ( ! empty( $config_file ) ) {
			$wpConfig = file_get_contents( $config_file );

			if ( 'delete' === $action ) {
				$wpConfig = preg_replace( '/' . PHP_EOL . '{1,2}\/\*\*\*snippet_' . $slug . '\*\*\*\/(.*)\/\*\*\*end_' . $slug . '\*\*\*\/' . PHP_EOL . '/is', '', $wpConfig );
			} elseif ( 'save' === $action ) {
				$wpConfig = preg_replace( '/(\$table_prefix *= *[\'"][^\'|^"]*[\'"] *;)/is', '${1}' . PHP_EOL . PHP_EOL . '/***snippet_' . $slug . '***/' . PHP_EOL . $code . PHP_EOL . '/***end_' . $slug . '***/' . PHP_EOL, $wpConfig );
			}
			file_put_contents( $config_file, $wpConfig );

			return true;
		}
		return false;
	}

	public function disconnect() {
		global $mainWPChild;
		$mainWPChild->deactivation( false );
		MainWP_Helper::write( array( 'result' => 'success' ) );
	}


	// Deactivating child plugin.
	public function deactivate() {
		global $mainWPChild;
		include_once ABSPATH . 'wp-admin/includes/plugin.php';
		deactivate_plugins( $mainWPChild->plugin_slug, true );
		$information = array();
		if ( is_plugin_active( $mainWPChild->plugin_slug ) ) {
			MainWP_Helper::error( 'Plugin still active' );
		}
		$information['deactivated'] = true;
		MainWP_Helper::write( $information );
	}

}
