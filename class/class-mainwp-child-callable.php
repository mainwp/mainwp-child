<?php

namespace MainWP\Child;

// phpcs:disable WordPress.WP.AlternativeFunctions -- root namespace to use external code.

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
		'cancel_scheduled_post' => 'cancel_scheduled_post',
		'serverInformation'     => 'server_information',
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
		$callable  = false;
		$callable_no_auth = false;
		$call_func = false;

		// check to execute mainwp child's callable functions.
		if ( isset( $_POST['function'] ) ) {			
			$call_func     = $_POST['function'];
			$callable = $this->is_callable_function( $call_func ); // check callable func.			
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
		mainwp_child_helper()->write( $ids );
	}

	public function cancel_scheduled_post() {
		global $wpdb;
		$postId      = $_POST['post_id'];
		$cancel_all  = $_POST['cancel_all'];
		$result      = false;
		$information = array();
		if ( $postId > 0 ) {
			if ( 'yes' === get_post_meta( $postId, '_is_auto_generate_content', true ) ) {
				$post = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT * FROM $wpdb->posts WHERE ID = %d AND post_status = 'future'",
						$postId
					)
				);
				if ( $post ) {
					$result = wp_trash_post( $postId );
				} else {
					$result = true;
				}
			}
			if ( ! $result ) {
				$information['status'] = 'SUCCESS';
			}
		} elseif ( $cancel_all ) {
			$post_type = $_POST['post_type'];
			$posts     = $wpdb->get_results( $wpdb->prepare( "SELECT p.ID FROM $wpdb->posts p JOIN $wpdb->postmeta pm ON p.ID=pm.post_id WHERE p.post_status='future' AND p.post_type = %s AND  pm.meta_key = '_is_auto_generate_content' AND pm.meta_value = 'yes' ", $post_type ) );
			$count     = 0;
			if ( is_array( $posts ) ) {
				foreach ( $posts as $post ) {
					if ( $post ) {
						if ( false !== wp_trash_post( $post->ID ) ) {
							$count ++;

						}
					}
				}
			} else {
				$posts = array();
			}

			$information['status'] = 'SUCCESS';
			$information['count']  = $count;
		}

		mainwp_child_helper()->write( $information );
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

	public function server_information() {
		ob_start();
		MainWP_Child_Server_Information::render();
		$output['information'] = ob_get_contents();
		ob_end_clean();
		ob_start();
		MainWP_Child_Server_Information::render_cron();
		$output['cron'] = ob_get_contents();
		ob_end_clean();
		ob_start();
		MainWP_Child_Server_Information::render_error_page();
		$output['error'] = ob_get_contents();
		ob_end_clean();
		ob_start();
		MainWP_Child_Server_Information::render_wp_config();
		$output['wpconfig'] = ob_get_contents();
		ob_end_clean();
		ob_start();
		MainWP_Child_Server_Information::renderhtaccess();
		$output['htaccess'] = ob_get_contents();
		ob_end_clean();

		mainwp_child_helper()->write( $output );
	}

	public function maintenance_site() {
		global $wpdb;
		$information = array();
		if ( isset( $_POST['action'] ) ) {
			if ( 'save_settings' === $_POST['action'] ) {

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
				mainwp_child_helper()->write( $information );

				return;
			} elseif ( 'clear_settings' === $_POST['action'] ) {
				delete_option( 'mainwp_maintenance_opt_alert_404' );
				delete_option( 'mainwp_maintenance_opt_alert_404_email' );
				$information['result'] = 'SUCCESS';
				mainwp_child_helper()->write( $information );
			}
			mainwp_child_helper()->write( $information );
		}

		$maint_options = $_POST['options'];
		$max_revisions = isset( $_POST['revisions'] ) ? intval( $_POST['revisions'] ) : 0;

		if ( ! is_array( $maint_options ) ) {
			$information['status'] = 'FAIL';
			$maint_options         = array();
		}

		$performed_what = array();

		if ( in_array( 'revisions', $maint_options ) ) {
			if ( empty( $max_revisions ) ) {
				$sql_clean = "DELETE FROM $wpdb->posts WHERE post_type = 'revision'";
				$wpdb->query( $sql_clean ); // phpcs:ignore -- safe sql.
				// to fix issue of meta_value short length.
				$performed_what[] = 'revisions'; // 'Posts revisions deleted'.
			} else {
				$results          = MainWP_Helper::get_revisions( $max_revisions );
				$count_deleted    = MainWP_Helper::delete_revisions( $results, $max_revisions );
				$performed_what[] = 'revisions_max'; // 'Posts revisions deleted'.
			}
		}

		if ( in_array( 'autodraft', $maint_options ) ) {
			$sql_clean = "DELETE FROM $wpdb->posts WHERE post_status = 'auto-draft'";
			$wpdb->query( $sql_clean ); // phpcs:ignore -- safe sql.
			$performed_what[] = 'autodraft'; // 'Auto draft posts deleted'.
		}

		if ( in_array( 'trashpost', $maint_options ) ) {
			$sql_clean = "DELETE FROM $wpdb->posts WHERE post_status = 'trash'";
			$wpdb->query( $sql_clean ); // phpcs:ignore -- safe sql.
			$performed_what[] = 'trashpost'; // 'Trash posts deleted'.
		}

		if ( in_array( 'spam', $maint_options ) ) {
			$sql_clean = "DELETE FROM $wpdb->comments WHERE comment_approved = 'spam'";
			$wpdb->query( $sql_clean ); // phpcs:ignore -- safe sql.
			$performed_what[] = 'spam'; // 'Spam comments deleted'.
		}

		if ( in_array( 'pending', $maint_options ) ) {
			$sql_clean = "DELETE FROM $wpdb->comments WHERE comment_approved = '0'";
			$wpdb->query( $sql_clean ); // phpcs:ignore -- safe sql.
			$performed_what[] = 'pending'; // 'Pending comments deleted'.
		}

		if ( in_array( 'trashcomment', $maint_options ) ) {
			$sql_clean = "DELETE FROM $wpdb->comments WHERE comment_approved = 'trash'";
			$wpdb->query( $sql_clean ); // phpcs:ignore -- safe sql.
			$performed_what[] = 'trashcomment'; // 'Trash comments deleted'.
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
		if ( ! isset( $information['status'] ) ) {
			$information['status'] = 'SUCCESS';
		}

		if ( ! empty( $performed_what ) && has_action( 'mainwp_reports_maintenance' ) ) {
			$details  = implode( ',', $performed_what );
			$log_time = time();
			$message  = 'Maintenance Performed';
			$result   = 'Maintenance Performed';
			do_action( 'mainwp_reports_maintenance', $message, $log_time, $details, $result, $max_revisions );
		}

		mainwp_child_helper()->write( $information );
	}

	public function maintenance_optimize() {
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

		mainwp_child_helper()->write( $information );
	}

	public function backup_poll() {
		$fileNameUID = ( isset( $_POST['fileNameUID'] ) ? $_POST['fileNameUID'] : '' );
		$fileName    = ( isset( $_POST['fileName'] ) ? $_POST['fileName'] : '' );

		if ( 'full' === $_POST['type'] ) {
			if ( '' !== $fileName ) {
				$backupFile = $fileName;
			} else {
				$backupFile = 'backup-' . $fileNameUID . '-';
			}

			$dirs        = MainWP_Helper::get_mainwp_dir( 'backup' );
			$backupdir   = $dirs[0];
			$result      = glob( $backupdir . $backupFile . '*' );
			$archiveFile = false;
			foreach ( $result as $file ) {
				if ( MainWP_Helper::is_archive( $file, $backupFile, '(.*)' ) ) {
					$archiveFile = $file;
					break;
				}
			}
			if ( false === $archiveFile ) {
				mainwp_child_helper()->write( array() );
			}

			mainwp_child_helper()->write( array( 'size' => filesize( $archiveFile ) ) );
		} else {
			$backupFile = 'dbBackup-' . $fileNameUID . '-*.sql';

			$dirs      = MainWP_Helper::get_mainwp_dir( 'backup' );
			$backupdir = $dirs[0];
			$result    = glob( $backupdir . $backupFile . '*' );
			if ( 0 === count( $result ) ) {
				mainwp_child_helper()->write( array() );
			}

			$size = 0;
			foreach ( $result as $f ) {
				$size += filesize( $f );
			}
			mainwp_child_helper()->write( array( 'size' => $size ) );
			exit();
		}
	}

	public function backup_checkpid() {
		$pid = $_POST['pid'];

		$dirs      = MainWP_Helper::get_mainwp_dir( 'backup' );
		$backupdir = $dirs[0];

		$information = array();

		/** @var $wp_filesystem WP_Filesystem_Base */
		global $wp_filesystem;

		MainWP_Helper::get_wp_filesystem();

		$pidFile  = trailingslashit( $backupdir ) . 'backup-' . $pid . '.pid';
		$doneFile = trailingslashit( $backupdir ) . 'backup-' . $pid . '.done';
		if ( $wp_filesystem->is_file( $pidFile ) ) {
			$time = $wp_filesystem->mtime( $pidFile );

			$minutes = date( 'i', time() ); // phpcs:ignore -- local time.
			$seconds = date( 's', time() ); // phpcs:ignore -- local time.

			$file_minutes = date( 'i', $time ); // phpcs:ignore -- local time.
			$file_seconds = date( 's', $time ); // phpcs:ignore -- local time.

			$minuteDiff = $minutes - $file_minutes;
			if ( 59 === $minuteDiff ) {
				$minuteDiff = 1;
			}
			$secondsdiff = ( $minuteDiff * 60 ) + $seconds - $file_seconds;

			$file                = $wp_filesystem->get_contents( $pidFile );
			$information['file'] = basename( $file );
			if ( $secondsdiff < 80 ) {
				$information['status'] = 'busy';
			} else {
				$information['status'] = 'stalled';
			}
		} elseif ( $wp_filesystem->is_file( $doneFile ) ) {
			$file                  = $wp_filesystem->get_contents( $doneFile );
			$information['status'] = 'done';
			$information['file']   = basename( $file );
			$information['size']   = filesize( $file );
		} else {
			$information['status'] = 'invalid';
		}

		mainwp_child_helper()->write( $information );
	}

	public function backup( $pWrite = true ) {

		$timeout = 20 * 60 * 60;		
		MainWP_Helper::set_limit( $timeout );
		
		MainWP_Helper::end_session();

		// Cleanup pid files!
		$dirs      = MainWP_Helper::get_mainwp_dir( 'backup' );
		$backupdir = trailingslashit( $dirs[0] );

		/** @var $wp_filesystem WP_Filesystem_Base */
		global $wp_filesystem;

		MainWP_Helper::get_wp_filesystem();

		$files = glob( $backupdir . '*' );
		foreach ( $files as $file ) {
			if ( MainWP_Helper::ends_with( $file, '/index.php' ) | MainWP_Helper::ends_with( $file, '/.htaccess' ) ) {
				continue;
			}

			if ( ( time() - filemtime( $file ) ) > ( 60 * 60 * 3 ) ) {
				unlink( $file );
			}
		}

		$fileName = ( isset( $_POST['fileUID'] ) ? $_POST['fileUID'] : '' );
		if ( 'full' === $_POST['type'] ) {
			$excludes   = ( isset( $_POST['exclude'] ) ? explode( ',', $_POST['exclude'] ) : array() );
			$excludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/uploads/mainwp';
			$uploadDir  = MainWP_Helper::get_mainwp_dir();
			$uploadDir  = $uploadDir[0];
			$excludes[] = str_replace( ABSPATH, '', $uploadDir );
			$excludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/object-cache.php';

			if ( function_exists( 'posix_uname' ) ) {
				$uname = posix_uname();
				if ( is_array( $uname ) && isset( $uname['nodename'] ) ) {
					if ( stristr( $uname['nodename'], 'hostgator' ) ) {
						if ( ! isset( $_POST['file_descriptors'] ) || '0' == $_POST['file_descriptors'] || $_POST['file_descriptors'] > 1000 ) {
							$_POST['file_descriptors'] = 1000;
						}
						$_POST['file_descriptors_auto'] = 0;
						$_POST['loadFilesBeforeZip']    = false;
					}
				}
			}

			$file_descriptors      = ( isset( $_POST['file_descriptors'] ) ? $_POST['file_descriptors'] : 0 );
			$file_descriptors_auto = ( isset( $_POST['file_descriptors_auto'] ) ? $_POST['file_descriptors_auto'] : 0 );
			if ( 1 === (int) $file_descriptors_auto ) {
				if ( function_exists( 'posix_getrlimit' ) ) {
					$result = posix_getrlimit();
					if ( isset( $result['soft openfiles'] ) ) {
						$file_descriptors = $result['soft openfiles'];
					}
				}
			}

			$loadFilesBeforeZip = ( isset( $_POST['loadFilesBeforeZip'] ) ? $_POST['loadFilesBeforeZip'] : true );

			$newExcludes = array();
			foreach ( $excludes as $exclude ) {
				$newExcludes[] = rtrim( $exclude, '/' );
			}

			$excludebackup = ( isset( $_POST['excludebackup'] ) && '1' == $_POST['excludebackup'] );
			$excludecache  = ( isset( $_POST['excludecache'] ) && '1' == $_POST['excludecache'] );
			$excludezip    = ( isset( $_POST['excludezip'] ) && '1' == $_POST['excludezip'] );
			$excludenonwp  = ( isset( $_POST['excludenonwp'] ) && '1' == $_POST['excludenonwp'] );

			if ( $excludebackup ) {
				$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/uploads/backupbuddy_backups';
				$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/uploads/backupbuddy_temp';
				$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/uploads/pb_backupbuddy';
				$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/managewp';
				$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/infinitewp';
				$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/backups';
				$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/backups';
				$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/uploads/backwpup*';
				$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/plugins/wp-complete-backup/storage';
				$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/backups';
				$newExcludes[] = '/administrator/backups';
			}

			if ( $excludecache ) {
				$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/w3tc-cache';
				$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/w3tc';
				$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/cache/config';
				$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/cache/minify';
				$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/cache/page_enhanced';
				$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/cache/tmp';
				$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/cache/supercache';
				$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/cache/quick-cache';
				$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/hyper-cache/cache';
				$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/cache/all';
				$newExcludes[] = str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/cache/wp-rocket';
			}

			$file = false;
			if ( isset( $_POST['f'] ) ) {
				$file = $_POST['f'];
			} elseif ( isset( $_POST['file'] ) ) {
				$file = $_POST['file'];
			}

			$ext = 'zip';
			if ( isset( $_POST['ext'] ) ) {
				$ext = $_POST['ext'];
			}

			$pid = false;
			if ( isset( $_POST['pid'] ) ) {
				$pid = $_POST['pid'];
			}

			$append = ( isset( $_POST['append'] ) && ( '1' == $_POST['append'] ) );

			$res = MainWP_Backup::get()->create_full_backup( $newExcludes, $fileName, true, true, $file_descriptors, $file, $excludezip, $excludenonwp, $loadFilesBeforeZip, $ext, $pid, $append );
			if ( ! $res ) {
				$information['full'] = false;
			} else {
				$information['full'] = $res['file'];
				$information['size'] = $res['filesize'];
			}
			$information['db'] = false;
		} elseif ( 'db' == $_POST['type'] ) {
			$ext = 'zip';
			if ( isset( $_POST['ext'] ) ) {
				$ext = $_POST['ext'];
			}

			$res = $this->backup_db( $fileName, $ext );
			if ( ! $res ) {
				$information['db'] = false;
			} else {
				$information['db']   = $res['file'];
				$information['size'] = $res['filesize'];
			}
			$information['full'] = false;
		} else {
			$information['full'] = false;
			$information['db']   = false;
		}

		if ( $pWrite ) {
			mainwp_child_helper()->write( $information );
		}

		return $information;
	}

	protected function backup_db( $fileName = '', $ext = 'zip' ) {
		$dirs      = MainWP_Helper::get_mainwp_dir( 'backup' );
		$dir       = $dirs[0];
		$timestamp = time();

		if ( '' !== $fileName ) {
			$fileName .= '-';
		}

		$filepath_prefix = $dir . 'dbBackup-' . $fileName . $timestamp;

		$dh = opendir( $dir );

		if ( $dh ) {
			while ( ( $file = readdir( $dh ) ) !== false ) {
				if ( '.' !== $file && '..' !== $file && ( preg_match( '/dbBackup-(.*).sql(\.zip|\.tar|\.tar\.gz|\.tar\.bz2|\.tmp)?$/', $file ) ) ) {
					unlink( $dir . $file );
				}
			}
			closedir( $dh );
		}

		$result = MainWP_Backup::get()->create_backup_db( $filepath_prefix, $ext );

		MainWP_Helper::update_option( 'mainwp_child_last_db_backup_size', filesize( $result['filepath'] ) );

		return ( ! $result ) ? false : array(
			'timestamp' => $timestamp,
			'file'      => basename( $result['filepath'] ),
			'filesize'  => filesize( $result['filepath'] ),
		);
	}

	public function get_site_icon() {
		$information = array();
		$url         = $this->get_favicon( true );
		if ( ! empty( $url ) ) {
			$information['faviIconUrl'] = $url;
		}
		mainwp_child_helper()->write( $information );
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

		mainwp_child_helper()->write( $information );
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
		mainwp_child_helper()->write( $information );
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

		mainwp_child_helper()->write( $information );
	}

	public function settings_tools() {
		if ( isset( $_POST['action'] ) ) {
			switch ( $_POST['action'] ) {
				case 'force_destroy_sessions':
					if ( 0 === get_current_user_id() ) {
						mainwp_child_helper()->write( array( 'error' => __( 'Cannot get user_id', 'mainwp-child' ) ) );
					}

					wp_destroy_all_sessions();

					$sessions = wp_get_all_sessions();

					if ( empty( $sessions ) ) {
						mainwp_child_helper()->write( array( 'success' => 1 ) );
					} else {
						mainwp_child_helper()->write( array( 'error' => __( 'Cannot destroy sessions', 'mainwp-child' ) ) );
					}
					break;

				default:
					mainwp_child_helper()->write( array( 'error' => __( 'Invalid action', 'mainwp-child' ) ) );
			}
		} else {
			mainwp_child_helper()->write( array( 'error' => __( 'Missing action', 'mainwp-child' ) ) );
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
		mainwp_child_helper()->write( $information );
	}


	public function uploader_action() {
		$file_url    = base64_decode( $_POST['url'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for begin reasons.
		$path        = $_POST['path'];
		$filename    = $_POST['filename'];
		$information = array();

		if ( empty( $file_url ) || empty( $path ) ) {
			mainwp_child_helper()->write( $information );

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
				mainwp_child_helper()->write( $information );

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
		mainwp_child_helper()->write( $information );
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
			mainwp_child_helper()->write( array( 'error' => $error ) );
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

		mainwp_child_helper()->write( array( 'result' => 'ok' ) );
	}


	public function update_child_values() {
		$uniId = isset( $_POST['uniqueId'] ) ? $_POST['uniqueId'] : '';
		MainWP_Helper::update_option( 'mainwp_child_uniqueId', $uniId );
		mainwp_child_helper()->write( array( 'result' => 'ok' ) );
	}



	public function keyword_links_action() {
		MainWP_Keyword_Links::instance()->action();
	}

	public function branding_child_plugin() {
		MainWP_Child_Branding::instance()->action();
	}

	public function code_snippet() {
		$action      = $_POST['action'];
		$information = array( 'status' => 'FAIL' );
		if ( 'run_snippet' === $action || 'save_snippet' === $action ) {
			if ( ! isset( $_POST['code'] ) ) {
				mainwp_child_helper()->write( $information );
			}
		}
		$code = stripslashes( $_POST['code'] );
		if ( 'run_snippet' === $action ) {
			$information = MainWP_Helper::execute_snippet( $code );
		} elseif ( 'save_snippet' === $action ) {
			$type     = $_POST['type'];
			$slug     = $_POST['slug'];
			$snippets = get_option( 'mainwp_ext_code_snippets' );

			if ( ! is_array( $snippets ) ) {
				$snippets = array();
			}

			if ( 'C' === $type ) { // save into wp-config file.
				if ( false !== $this->snippet_update_wp_config( 'save', $slug, $code ) ) {
					$information['status'] = 'SUCCESS';
				}
			} else {
				$snippets[ $slug ] = $code;
				if ( MainWP_Helper::update_option( 'mainwp_ext_code_snippets', $snippets ) ) {
					$information['status'] = 'SUCCESS';
				}
			}
			MainWP_Helper::update_option( 'mainwp_ext_snippets_enabled', true, 'yes' );
		} elseif ( 'delete_snippet' === $action ) {
			$type     = $_POST['type'];
			$slug     = $_POST['slug'];
			$snippets = get_option( 'mainwp_ext_code_snippets' );

			if ( ! is_array( $snippets ) ) {
				$snippets = array();
			}
			if ( 'C' === $type ) { // delete in wp-config file.
				if ( false !== $this->snippet_update_wp_config( 'delete', $slug ) ) {
					$information['status'] = 'SUCCESS';
				}
			} else {
				if ( isset( $snippets[ $slug ] ) ) {
					unset( $snippets[ $slug ] );
					if ( MainWP_Helper::update_option( 'mainwp_ext_code_snippets', $snippets ) ) {
						$information['status'] = 'SUCCESS';
					}
				} else {
					$information['status'] = 'SUCCESS';
				}
			}
		}
		mainwp_child_helper()->write( $information );
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
		mainwp_child_helper()->write( array( 'result' => 'success' ) );
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
		mainwp_child_helper()->write( $information );
	}

}
